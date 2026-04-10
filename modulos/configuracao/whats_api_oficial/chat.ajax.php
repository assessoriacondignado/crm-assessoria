<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $cpf_logado   = $_SESSION['usuario_cpf'] ?? '';
    $nome_logado  = $_SESSION['usuario_nome'] ?? '';
    $acao         = $_POST['acao'] ?? $_GET['acao'] ?? '';

    if (empty($cpf_logado)) {
        echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']); exit;
    }

    if (!isset($_SESSION['empresa_id'])) {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$cpf_logado]);
        $_SESSION['empresa_id'] = $stmtEmp->fetchColumn() ?: 1;
    }
    $id_empresa = $_SESSION['empresa_id'];

    // Helper: busca token pelo Phone ID (BM → fallback legado)
    function getTokenChat($phone_id, $pdo) {
        $stmt = $pdo->prepare("SELECT bm.PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
            INNER JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
            WHERE n.PHONE_NUMBER_ID = ?");
        $stmt->execute([$phone_id]);
        $t = $stmt->fetchColumn();
        if ($t) return $t;
        $stmt2 = $pdo->prepare("SELECT c.PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
            WHERE n.PHONE_NUMBER_ID = ?");
        $stmt2->execute([$phone_id]);
        return $stmt2->fetchColumn();
    }

    // Helper: chama API Meta
    function metaPost($url, $payload, $token) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $res = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => json_decode($res, true), 'http' => $http, 'raw' => $res];
    }

    // Helper: verifica se a janela de 24h está aberta
    function janelaAberta($conversa) {
        if ($conversa['STATUS_JANELA'] !== 'ABERTA') return false;
        if (empty($conversa['DATA_JANELA_EXPIRA'])) return false;
        return strtotime($conversa['DATA_JANELA_EXPIRA']) > time();
    }

    switch ($acao) {

        // =====================================================================
        // CONVERSAS
        // =====================================================================

        case 'listar_conversas':
            $status_filtro = $_POST['status'] ?? 'TODOS';
            $phone_filtro  = $_POST['phone_number_id'] ?? '';

            $sql = "SELECT cv.*,
                        DATE_FORMAT(cv.DATA_ULTIMA_MSG, '%d/%m %H:%i') as ULTIMA_MSG_BR,
                        (SELECT CONTEUDO FROM WHATSAPP_CHAT_MENSAGENS WHERE CONVERSA_ID = cv.ID ORDER BY ID DESC LIMIT 1) as ULTIMO_TRECHO,
                        (SELECT COUNT(*) FROM WHATSAPP_CHAT_MENSAGENS WHERE CONVERSA_ID = cv.ID AND DIRECAO = 'RECEBIDA' AND STATUS_ENVIO = 'received') as MSGS_NOVAS
                    FROM WHATSAPP_CHAT_CONVERSAS cv
                    WHERE cv.PHONE_NUMBER_ID IN (
                        SELECT n.PHONE_NUMBER_ID FROM WHATSAPP_OFICIAL_NUMEROS n
                        INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
                        WHERE c.id_empresa = ? OR c.CPF_USUARIO = ?
                    )";
            $params = [$id_empresa, $cpf_logado];

            if ($status_filtro !== 'TODOS') {
                $sql .= " AND cv.STATUS_ATENDIMENTO = ?";
                $params[] = $status_filtro;
            }
            if (!empty($phone_filtro)) {
                $sql .= " AND cv.PHONE_NUMBER_ID = ?";
                $params[] = $phone_filtro;
            }

            // Recalcula janela expirada em tempo real
            $sql .= " ORDER BY cv.DATA_ULTIMA_MSG DESC, cv.DATA_CRIACAO DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $conversas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Atualiza janelas expiradas
            foreach ($conversas as &$cv) {
                if ($cv['STATUS_JANELA'] === 'ABERTA' && !empty($cv['DATA_JANELA_EXPIRA']) && strtotime($cv['DATA_JANELA_EXPIRA']) <= time()) {
                    $cv['STATUS_JANELA'] = 'FECHADA';
                    $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_JANELA='FECHADA' WHERE ID=?")->execute([$cv['ID']]);
                }
                // Trunca último trecho
                if (!empty($cv['ULTIMO_TRECHO'])) {
                    $cv['ULTIMO_TRECHO'] = mb_substr(strip_tags($cv['ULTIMO_TRECHO']), 0, 60) . '...';
                }
            }

            echo json_encode(['success' => true, 'conversas' => $conversas]);
            break;

        case 'abrir_conversa':
            $conversa_id = (int)$_POST['conversa_id'];
            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_CHAT_CONVERSAS WHERE ID = ?");
            $stmt->execute([$conversa_id]);
            $conversa = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$conversa) { echo json_encode(['success' => false, 'msg' => 'Conversa não encontrada.']); exit; }

            // Marca como em atendimento se estava aguardando
            if ($conversa['STATUS_ATENDIMENTO'] === 'AGUARDANDO') {
                $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_ATENDIMENTO='EM_ATENDIMENTO', CPF_ATENDENTE=?, NOME_ATENDENTE=? WHERE ID=?")
                    ->execute([$cpf_logado, $nome_logado, $conversa_id]);
                $conversa['STATUS_ATENDIMENTO'] = 'EM_ATENDIMENTO';
                $conversa['CPF_ATENDENTE'] = $cpf_logado;
                $conversa['NOME_ATENDENTE'] = $nome_logado;
            }

            // Recalcula janela
            if ($conversa['STATUS_JANELA'] === 'ABERTA' && !empty($conversa['DATA_JANELA_EXPIRA']) && strtotime($conversa['DATA_JANELA_EXPIRA']) <= time()) {
                $conversa['STATUS_JANELA'] = 'FECHADA';
                $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_JANELA='FECHADA' WHERE ID=?")->execute([$conversa_id]);
            }

            // Carrega mensagens
            $stmtM = $pdo->prepare("SELECT * FROM WHATSAPP_CHAT_MENSAGENS WHERE CONVERSA_ID = ? ORDER BY DATA_HORA ASC, ID ASC");
            $stmtM->execute([$conversa_id]);
            $mensagens = $stmtM->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'conversa' => $conversa, 'mensagens' => $mensagens, 'janela_aberta' => janelaAberta($conversa)]);
            break;

        case 'nova_conversa':
            // Inicia conversa com um número de cliente
            $phone_number_id  = trim($_POST['phone_number_id'] ?? '');
            $telefone_cliente = preg_replace('/\D/', '', $_POST['telefone_cliente'] ?? '');
            $nome_cliente     = trim($_POST['nome_cliente'] ?? '');

            if (empty($phone_number_id) || empty($telefone_cliente)) {
                echo json_encode(['success' => false, 'msg' => 'Phone ID e telefone do cliente são obrigatórios.']); exit;
            }

            // Verifica se já existe
            $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_CHAT_CONVERSAS WHERE PHONE_NUMBER_ID = ? AND TELEFONE_CLIENTE = ?");
            $stmt->execute([$phone_number_id, $telefone_cliente]);
            $existente = $stmt->fetchColumn();

            if ($existente) {
                echo json_encode(['success' => true, 'conversa_id' => $existente, 'msg' => 'Conversa já existe.']);
            } else {
                $pdo->prepare("INSERT INTO WHATSAPP_CHAT_CONVERSAS (PHONE_NUMBER_ID, TELEFONE_CLIENTE, NOME_CLIENTE, STATUS_ATENDIMENTO, CPF_ATENDENTE, NOME_ATENDENTE) VALUES (?,?,?,'EM_ATENDIMENTO',?,?)")
                    ->execute([$phone_number_id, $telefone_cliente, $nome_cliente, $cpf_logado, $nome_logado]);
                echo json_encode(['success' => true, 'conversa_id' => $pdo->lastInsertId(), 'msg' => 'Conversa iniciada.']);
            }
            break;

        case 'finalizar_conversa':
            $conversa_id = (int)$_POST['conversa_id'];
            $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_ATENDIMENTO='FINALIZADO' WHERE ID=?")->execute([$conversa_id]);
            echo json_encode(['success' => true, 'msg' => 'Conversa finalizada.']);
            break;

        case 'salvar_assinatura':
            $conversa_id = (int)$_POST['conversa_id'];
            $assinatura  = trim($_POST['assinatura'] ?? '');
            $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET ASSINATURA=? WHERE ID=?")->execute([$assinatura, $conversa_id]);
            echo json_encode(['success' => true]);
            break;

        // =====================================================================
        // ENVIO DE MENSAGENS
        // =====================================================================

        case 'enviar_mensagem':
            $conversa_id = (int)$_POST['conversa_id'];
            $tipo        = $_POST['tipo'] ?? 'text'; // text | template | image | document
            $conteudo    = trim($_POST['conteudo'] ?? '');

            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_CHAT_CONVERSAS WHERE ID = ?");
            $stmt->execute([$conversa_id]);
            $conversa = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$conversa) { echo json_encode(['success' => false, 'msg' => 'Conversa não encontrada.']); exit; }

            $phone_number_id  = $conversa['PHONE_NUMBER_ID'];
            $telefone_cliente = $conversa['TELEFONE_CLIENTE'];
            $token = getTokenChat($phone_number_id, $pdo);

            if (empty($token)) { echo json_encode(['success' => false, 'msg' => 'Token não encontrado para este número.']); exit; }

            // Verifica janela para texto livre
            if ($tipo === 'text' && !janelaAberta($conversa)) {
                echo json_encode(['success' => false, 'msg' => 'Janela de 24h fechada. Use um template aprovado para reabrir a conversa.']); exit;
            }

            // Adiciona assinatura ao texto se definida
            $texto_enviar = $conteudo;
            if ($tipo === 'text' && !empty($conversa['ASSINATURA'])) {
                $texto_enviar .= "\n\n— " . $conversa['ASSINATURA'];
            }

            // Monta payload Meta
            $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $telefone_cliente, 'type' => $tipo];

            $arquivo_path = null;

            if ($tipo === 'text') {
                $payload['text'] = ['preview_url' => false, 'body' => $texto_enviar];

            } elseif ($tipo === 'template') {
                $template_raw = $_POST['template_name'] ?? '';
                $partes       = explode('|', $template_raw);
                $tpl_nome     = $partes[0];
                $tpl_lang     = $partes[1] ?? 'pt_BR';
                $variaveis    = $_POST['variaveis'] ?? '';
                $url_imagem   = trim($_POST['url_imagem'] ?? '');

                $components = [];
                if (!empty($url_imagem)) {
                    $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $url_imagem]]]];
                }
                if (!empty($variaveis)) {
                    $vars = array_map('trim', explode(',', $variaveis));
                    $params_body = array_map(fn($v) => ['type' => 'text', 'text' => $v], $vars);
                    $components[] = ['type' => 'body', 'parameters' => $params_body];
                }

                $payload['template'] = ['name' => $tpl_nome, 'language' => ['code' => $tpl_lang]];
                if (!empty($components)) { $payload['template']['components'] = $components; }
                $conteudo = "Template: {$tpl_nome}";

            } elseif (in_array($tipo, ['image', 'document', 'audio', 'video'])) {
                if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'msg' => 'Erro no upload do arquivo.']); exit;
                }
                $ext = strtolower(pathinfo($_FILES['arquivo']['name'], PATHINFO_EXTENSION));
                $nome_arquivo = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
                $dir_envio = __DIR__ . '/arquivos_enviados_whats/';
                if (!is_dir($dir_envio)) { @mkdir($dir_envio, 0775, true); }
                move_uploaded_file($_FILES['arquivo']['tmp_name'], $dir_envio . $nome_arquivo);
                $arquivo_path = 'arquivos_enviados_whats/' . $nome_arquivo;

                $url_publica = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']
                    . '/modulos/configuracao/whats_api_oficial/' . $arquivo_path;

                $caption = trim($_POST['caption'] ?? '');
                $payload[$tipo] = ['link' => $url_publica];
                if (!empty($caption)) { $payload[$tipo]['caption'] = $caption; }
                $conteudo = "[" . strtoupper($tipo) . "] " . ($caption ?: basename($nome_arquivo));
            }

            // Envia para a Meta
            $resp = metaPost("https://graph.facebook.com/v19.0/{$phone_number_id}/messages", $payload, $token);

            if ($resp['http'] === 200 && isset($resp['body']['messages'][0]['id'])) {
                $message_id = $resp['body']['messages'][0]['id'];

                // Salva mensagem
                $pdo->prepare("INSERT INTO WHATSAPP_CHAT_MENSAGENS (CONVERSA_ID, MESSAGE_ID, DIRECAO, TIPO, CONTEUDO, ARQUIVO_PATH, STATUS_ENVIO) VALUES (?,?,?,'ENVIADA',?,?,?,'enviada')")
                    ->execute([$conversa_id, $message_id, $tipo, $conteudo, $arquivo_path]);

                // Atualiza conversa
                $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET DATA_ULTIMA_MSG=NOW(), STATUS_ATENDIMENTO='EM_ATENDIMENTO' WHERE ID=?")->execute([$conversa_id]);

                echo json_encode(['success' => true, 'msg' => 'Mensagem enviada!', 'message_id' => $message_id]);
            } else {
                $erro = $resp['body']['error']['message'] ?? 'Erro desconhecido na Meta';
                echo json_encode(['success' => false, 'msg' => 'Meta: ' . $erro]);
            }
            break;

        case 'polling_mensagens':
            // Retorna mensagens novas após um determinado ID
            $conversa_id   = (int)$_POST['conversa_id'];
            $ultimo_id     = (int)($_POST['ultimo_id'] ?? 0);

            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_CHAT_MENSAGENS WHERE CONVERSA_ID = ? AND ID > ? ORDER BY DATA_HORA ASC, ID ASC");
            $stmt->execute([$conversa_id, $ultimo_id]);
            $novas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Atualiza janela em tempo real
            $stmtCv = $pdo->prepare("SELECT STATUS_JANELA, DATA_JANELA_EXPIRA FROM WHATSAPP_CHAT_CONVERSAS WHERE ID = ?");
            $stmtCv->execute([$conversa_id]);
            $cv = $stmtCv->fetch(PDO::FETCH_ASSOC);

            $janela_aberta = ($cv['STATUS_JANELA'] === 'ABERTA' && !empty($cv['DATA_JANELA_EXPIRA']) && strtotime($cv['DATA_JANELA_EXPIRA']) > time());

            echo json_encode(['success' => true, 'mensagens' => $novas, 'janela_aberta' => $janela_aberta, 'data_expira' => $cv['DATA_JANELA_EXPIRA'] ?? null]);
            break;

        // =====================================================================
        // MODELOS INTERNOS DO CRM
        // =====================================================================

        case 'listar_modelos':
            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_CHAT_MODELOS WHERE id_empresa = ? OR CPF_CRIADOR = ? ORDER BY NOME_MODELO ASC");
            $stmt->execute([$id_empresa, $cpf_logado]);
            echo json_encode(['success' => true, 'modelos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'salvar_modelo':
            $modelo_id  = (int)($_POST['modelo_id'] ?? 0);
            $nome       = trim($_POST['nome_modelo'] ?? '');
            $conteudo   = trim($_POST['conteudo'] ?? '');

            if (empty($nome) || empty($conteudo)) {
                echo json_encode(['success' => false, 'msg' => 'Nome e conteúdo são obrigatórios.']); exit;
            }

            if ($modelo_id > 0) {
                $pdo->prepare("UPDATE WHATSAPP_CHAT_MODELOS SET NOME_MODELO=?, CONTEUDO=? WHERE ID=? AND (id_empresa=? OR CPF_CRIADOR=?)")
                    ->execute([$nome, $conteudo, $modelo_id, $id_empresa, $cpf_logado]);
            } else {
                $pdo->prepare("INSERT INTO WHATSAPP_CHAT_MODELOS (NOME_MODELO, CONTEUDO, id_empresa, CPF_CRIADOR) VALUES (?,?,?,?)")
                    ->execute([$nome, $conteudo, $id_empresa, $cpf_logado]);
            }
            echo json_encode(['success' => true, 'msg' => 'Modelo salvo!']);
            break;

        case 'excluir_modelo':
            $modelo_id = (int)$_POST['modelo_id'];
            $pdo->prepare("DELETE FROM WHATSAPP_CHAT_MODELOS WHERE ID=? AND (id_empresa=? OR CPF_CRIADOR=?)")
                ->execute([$modelo_id, $id_empresa, $cpf_logado]);
            echo json_encode(['success' => true, 'msg' => 'Modelo excluído.']);
            break;

        // =====================================================================
        // NÚMEROS DA EMPRESA (para selector do chat)
        // =====================================================================

        case 'listar_numeros_chat':
            $stmt = $pdo->prepare("SELECT n.PHONE_NUMBER_ID, n.NOME_NUMERO, bm.NOME_BM
                FROM WHATSAPP_OFICIAL_NUMEROS n
                INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
                LEFT JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
                WHERE c.id_empresa = ? OR c.CPF_USUARIO = ?
                ORDER BY n.NOME_NUMERO ASC");
            $stmt->execute([$id_empresa, $cpf_logado]);
            echo json_encode(['success' => true, 'numeros' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => 'Erro: ' . $e->getMessage()]);
}
?>
