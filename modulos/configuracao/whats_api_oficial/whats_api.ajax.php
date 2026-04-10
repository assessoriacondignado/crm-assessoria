<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? '';
    $cpf_logado = $_SESSION['usuario_cpf'] ?? '';

    if (empty($cpf_logado)) {
        echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']);
        exit;
    }

    $caminho_perm = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_perm)) { require_once $caminho_perm; }
    if (!function_exists('VerificaBloqueio')) { function VerificaBloqueio($chave) { return false; } }

    // Busca empresa_id da sessão; se ausente, busca do banco e salva na sessão
    if (!isset($_SESSION['empresa_id'])) {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$cpf_logado]);
        $empRow = $stmtEmp->fetchColumn();
        $_SESSION['empresa_id'] = $empRow ?: 1; // fallback para empresa 1
    }
    $id_empresa = $_SESSION['empresa_id'];

    // Helper: busca token pelo Phone ID (BM hierarchy → fallback legado)
    function getTokenPorPhoneId($phone_id, $pdo) {
        // Tenta via BM
        $stmt = $pdo->prepare("SELECT bm.PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
            INNER JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
            WHERE n.PHONE_NUMBER_ID = ?");
        $stmt->execute([$phone_id]);
        $token = $stmt->fetchColumn();
        if ($token) return $token;
        // Fallback legado: token direto na conta WABA
        $stmt2 = $pdo->prepare("SELECT c.PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
            WHERE n.PHONE_NUMBER_ID = ?");
        $stmt2->execute([$phone_id]);
        return $stmt2->fetchColumn();
    }

    // Helper: busca WABA_ID pelo Phone ID
    function getWabaIdPorPhoneId($phone_id, $pdo) {
        $stmt = $pdo->prepare("SELECT c.WABA_ID FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
            WHERE n.PHONE_NUMBER_ID = ?");
        $stmt->execute([$phone_id]);
        return $stmt->fetchColumn();
    }

    switch ($acao) {

        // =====================================================================
        // GERENCIADOR DE NEGÓCIOS (BM)
        // =====================================================================

        case 'listar_bms':
            // Lista todos os BMs da empresa do usuário logado
            $stmt = $pdo->prepare("SELECT b.*, COUNT(c.ID) as QTD_WABAS,
                COALESCE(e.NOME_CADASTRO, '') as NOME_EMPRESA
                FROM WHATSAPP_OFICIAL_BM b
                LEFT JOIN WHATSAPP_OFICIAL_CONTAS c ON c.bm_id = b.ID
                LEFT JOIN CLIENTE_EMPRESAS e ON e.ID = b.id_empresa
                WHERE b.id_empresa = ?
                GROUP BY b.ID ORDER BY b.NOME_BM ASC");
            $stmt->execute([$id_empresa]);
            $bms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Máscara: primeiros 10 + *** + últimos 5 chars do token
            foreach ($bms as &$bm) {
                $tok = $bm['PERMANENT_TOKEN'] ?? '';
                $bm['TOKEN_MASKED'] = strlen($tok) > 15
                    ? substr($tok, 0, 10) . '•••••••••••••••' . substr($tok, -5)
                    : str_repeat('•', strlen($tok));
                unset($bm['PERMANENT_TOKEN']); // nunca envia o token completo ao front
            }
            unset($bm);

            // Para cada BM, carrega suas WABAs com os phones
            foreach ($bms as &$bm) {
                $stmtC = $pdo->prepare("SELECT c.*, COUNT(n.ID) as QTD_PHONES FROM WHATSAPP_OFICIAL_CONTAS c
                    LEFT JOIN WHATSAPP_OFICIAL_NUMEROS n ON n.CONTA_ID = c.ID
                    WHERE c.bm_id = ? GROUP BY c.ID ORDER BY c.WABA_ID ASC");
                $stmtC->execute([$bm['ID']]);
                $wabas = $stmtC->fetchAll(PDO::FETCH_ASSOC);

                foreach ($wabas as &$waba) {
                    $stmtN = $pdo->prepare("SELECT * FROM WHATSAPP_OFICIAL_NUMEROS WHERE CONTA_ID = ? ORDER BY NOME_NUMERO ASC");
                    $stmtN->execute([$waba['ID']]);
                    $waba['numeros'] = $stmtN->fetchAll(PDO::FETCH_ASSOC);
                }
                $bm['wabas'] = $wabas;
            }
            echo json_encode(['success' => true, 'bms' => $bms,
                'webhook_url' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/modulos/configuracao/whats_api_oficial/webhook_meta.php']);
            break;

        case 'salvar_bm':
            $bm_db_id   = (int)($_POST['bm_db_id'] ?? 0);
            $nome_bm    = trim($_POST['nome_bm'] ?? '');
            $bm_id_meta = trim($_POST['bm_id_meta'] ?? '');
            $app_id     = trim($_POST['app_id'] ?? '');
            $app_secret = trim($_POST['app_secret'] ?? '');
            $token      = trim($_POST['token'] ?? '');

            if (empty($nome_bm) || empty($bm_id_meta) || empty($app_id) || empty($token)) {
                echo json_encode(['success' => false, 'msg' => 'Preencha Nome, BM ID, App ID e Token.']); exit;
            }
            if (empty($id_empresa)) {
                echo json_encode(['success' => false, 'msg' => 'Usuário sem empresa vinculada.']); exit;
            }

            if ($bm_db_id > 0) {
                // Atualiza — somente se pertencer à mesma empresa
                $pdo->prepare("UPDATE WHATSAPP_OFICIAL_BM SET NOME_BM=?, BM_ID=?, APP_ID=?, APP_SECRET=?, PERMANENT_TOKEN=? WHERE ID=? AND id_empresa=?")
                    ->execute([$nome_bm, $bm_id_meta, $app_id, $app_secret, $token, $bm_db_id, $id_empresa]);
                echo json_encode(['success' => true, 'msg' => 'BM atualizado com sucesso!']);
            } else {
                $verify = 'crm_' . bin2hex(random_bytes(8));
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_BM (id_empresa, NOME_BM, BM_ID, APP_ID, APP_SECRET, PERMANENT_TOKEN, WEBHOOK_VERIFY_TOKEN, CPF_CRIADOR) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$id_empresa, $nome_bm, $bm_id_meta, $app_id, $app_secret, $token, $verify, $cpf_logado]);
                echo json_encode(['success' => true, 'msg' => 'Gerenciador de Negócios cadastrado!']);
            }
            break;

        case 'excluir_bm':
            $bm_db_id = (int)$_POST['bm_db_id'];
            // Só exclui se pertencer à empresa do usuário
            $pdo->prepare("DELETE FROM WHATSAPP_OFICIAL_BM WHERE ID = ? AND id_empresa = ?")
                ->execute([$bm_db_id, $id_empresa]);
            echo json_encode(['success' => true, 'msg' => 'BM e todas as contas vinculadas foram removidos.']);
            break;

        // =====================================================================
        // CONTAS WABA (vinculadas ao BM)
        // =====================================================================

        case 'salvar_waba':
            $bm_db_id = (int)($_POST['bm_db_id'] ?? 0);
            $waba_id  = trim($_POST['waba_id'] ?? '');
            $conta_id = (int)($_POST['conta_id'] ?? 0);

            if (empty($bm_db_id) || empty($waba_id)) {
                echo json_encode(['success' => false, 'msg' => 'BM e WABA ID são obrigatórios.']); exit;
            }
            // Confirma que o BM pertence à empresa
            $stmtBm = $pdo->prepare("SELECT ID, WEBHOOK_VERIFY_TOKEN FROM WHATSAPP_OFICIAL_BM WHERE ID = ? AND id_empresa = ?");
            $stmtBm->execute([$bm_db_id, $id_empresa]);
            $bm_row = $stmtBm->fetch(PDO::FETCH_ASSOC);
            if (!$bm_row) { echo json_encode(['success' => false, 'msg' => 'BM não encontrado.']); exit; }

            if ($conta_id > 0) {
                $pdo->prepare("UPDATE WHATSAPP_OFICIAL_CONTAS SET WABA_ID=?, bm_id=? WHERE ID=?")
                    ->execute([$waba_id, $bm_db_id, $conta_id]);
                echo json_encode(['success' => true, 'msg' => 'WABA atualizada!']);
            } else {
                try {
                    $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_CONTAS (CPF_USUARIO, WABA_ID, PERMANENT_TOKEN, WEBHOOK_VERIFY_TOKEN, id_empresa, bm_id) VALUES (?,?,?,?,?,?)")
                        ->execute([$cpf_logado, $waba_id, '', $bm_row['WEBHOOK_VERIFY_TOKEN'], $id_empresa, $bm_db_id]);
                    echo json_encode(['success' => true, 'msg' => 'Conta WABA adicionada!']);
                } catch (PDOException $e) {
                    echo json_encode(['success' => false, 'msg' => 'Esta WABA já está cadastrada neste BM.']);
                }
            }
            break;

        case 'excluir_waba':
            $conta_id = (int)$_POST['conta_id'];
            // Verifica que a conta pertence à empresa antes de excluir
            $pdo->prepare("DELETE c FROM WHATSAPP_OFICIAL_CONTAS c
                INNER JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
                WHERE c.ID = ? AND bm.id_empresa = ?")
                ->execute([$conta_id, $id_empresa]);
            echo json_encode(['success' => true, 'msg' => 'WABA e seus números foram removidos.']);
            break;

        // =====================================================================
        // NÚMEROS DE TELEFONE (Phone IDs)
        // =====================================================================

        case 'adicionar_numero':
            $nome_numero = trim($_POST['nome_numero'] ?? '');
            $phone_id    = trim($_POST['phone_id'] ?? '');
            $conta_id    = (int)($_POST['conta_id'] ?? 0);

            if (empty($phone_id) || empty($nome_numero)) {
                echo json_encode(['success' => false, 'msg' => 'Phone ID e apelido são obrigatórios.']); exit;
            }

            // Se conta_id não informado, tenta encontrar a conta legada por CPF
            if (!$conta_id) {
                $stmtC = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ? LIMIT 1");
                $stmtC->execute([$cpf_logado]);
                $conta_id = (int)$stmtC->fetchColumn();
            }
            if (!$conta_id) { echo json_encode(['success' => false, 'msg' => 'Selecione a conta WABA primeiro.']); exit; }

            try {
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_NUMEROS (CONTA_ID, PHONE_NUMBER_ID, NOME_NUMERO) VALUES (?, ?, ?)")
                    ->execute([$conta_id, $phone_id, $nome_numero]);
                echo json_encode(['success' => true, 'msg' => 'Número adicionado!']);
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'msg' => 'Este Phone ID já está cadastrado.']);
            }
            break;

        case 'excluir_numero':
            $id_numero = (int)$_POST['id_numero'];
            $pdo->prepare("DELETE FROM WHATSAPP_OFICIAL_NUMEROS WHERE ID = ?")->execute([$id_numero]);
            echo json_encode(['success' => true, 'msg' => 'Número removido.']);
            break;

        // =====================================================================
        // TEMPLATES E DISPARO (usam token do BM via Phone ID)
        // =====================================================================

        case 'listar_numeros_empresa':
            // Carrega todos os Phone IDs disponíveis para a empresa do usuário
            $stmt = $pdo->prepare("SELECT n.ID, n.PHONE_NUMBER_ID, n.NOME_NUMERO, c.WABA_ID, bm.NOME_BM
                FROM WHATSAPP_OFICIAL_NUMEROS n
                INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
                LEFT JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
                WHERE c.id_empresa = ? OR c.CPF_USUARIO = ?
                ORDER BY bm.NOME_BM ASC, n.NOME_NUMERO ASC");
            $stmt->execute([$id_empresa, $cpf_logado]);
            echo json_encode(['success' => true, 'numeros' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'listar_templates_meta':
            $phone_id_param = trim($_POST['phone_id'] ?? '');

            if (!empty($phone_id_param)) {
                // Novo fluxo: token via BM do phone selecionado
                $token_tpl = getTokenPorPhoneId($phone_id_param, $pdo);
                $waba_id_tpl = getWabaIdPorPhoneId($phone_id_param, $pdo);
            } else {
                // Fallback legado
                $stmt = $pdo->prepare("SELECT WABA_ID, PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
                $stmt->execute([$cpf_logado]);
                $conta = $stmt->fetch(PDO::FETCH_ASSOC);
                $token_tpl   = $conta['PERMANENT_TOKEN'] ?? null;
                $waba_id_tpl = $conta['WABA_ID'] ?? null;
            }

            if (empty($token_tpl) || empty($waba_id_tpl)) {
                echo json_encode(['success' => false, 'msg' => 'Credenciais não encontradas para este número.']); exit;
            }

            $url = "https://graph.facebook.com/v19.0/{$waba_id_tpl}/message_templates?limit=200";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_tpl]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_json = json_decode($response, true);
            if ($http_code == 200 && isset($res_json['data'])) {
                echo json_encode(['success' => true, 'data' => $res_json['data']]);
            } else {
                echo json_encode(['success' => false, 'msg' => 'Erro da Meta: ' . ($res_json['error']['message'] ?? 'Desconhecido')]);
            }
            break;

        case 'enviar_teste':
            $phone_id_remetente = $_POST['phone_id_remetente'];
            $telefone_destino = preg_replace('/\D/', '', $_POST['telefone']);
            $tipo = $_POST['tipo'];

            $token = getTokenPorPhoneId($phone_id_remetente, $pdo);

            if(empty($token) || empty($phone_id_remetente)) {
                echo json_encode(['success' => false, 'msg' => 'Credenciais ou Número Remetente não configurados.']); exit;
            }

            $payload = [ "messaging_product" => "whatsapp", "recipient_type" => "individual", "to" => $telefone_destino, "type" => $tipo ];

            if ($tipo === 'text') {
                $payload["text"] = ["preview_url" => false, "body" => trim($_POST['conteudo'])];
                $conteudo_salvo = trim($_POST['conteudo']);
            } else {
                $template_raw = trim($_POST['template_name']);
                $partes = explode('|', $template_raw);
                $template_name = $partes[0];
                $template_lang = $partes[1] ?? 'pt_BR';

                $payload["template"] = [ "name" => $template_name, "language" => ["code" => $template_lang] ];
                $components = [];

                if (!empty($_POST['url_imagem'])) {
                    $components[] = [ "type" => "header", "parameters" => [ ["type" => "image", "image" => [ "link" => trim($_POST['url_imagem']) ] ] ] ];
                }
                if (!empty($_POST['variaveis'])) {
                    $vars = explode(',', $_POST['variaveis']);
                    $parameters_body = [];
                    foreach ($vars as $v) { $parameters_body[] = [ "type" => "text", "text" => trim($v) ]; }
                    $components[] = [ "type" => "body", "parameters" => $parameters_body ];
                }
                if (count($components) > 0) { $payload["template"]["components"] = $components; }
                $conteudo_salvo = "Template: " . $template_name;
            }

            $ch = curl_init("https://graph.facebook.com/v19.0/{$phone_id_remetente}/messages");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_json = json_decode($response, true);
            if ($http_code == 200 && isset($res_json['messages'][0]['id'])) {
                $message_id = $res_json['messages'][0]['id'];
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_HISTORICO (MESSAGE_ID, TELEFONE_CLIENTE, PHONE_NUMBER_ID, DIRECAO, TIPO_MENSAGEM, CONTEUDO, STATUS_ENVIO) VALUES (?, ?, ?, 'ENVIADA', ?, ?, 'enviada')")
                    ->execute([$message_id, $telefone_destino, $phone_id_remetente, $tipo, $conteudo_salvo]);
                echo json_encode(['success' => true, 'msg' => 'Mensagem aceita pela Meta!']);
            } else {
                $erro_desc = $res_json['error']['message'] ?? 'Erro desconhecido na Meta';
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_HISTORICO (TELEFONE_CLIENTE, PHONE_NUMBER_ID, DIRECAO, TIPO_MENSAGEM, CONTEUDO, STATUS_ENVIO, ERRO_DETALHE) VALUES (?, ?, 'ENVIADA', ?, ?, 'failed', ?)")
                    ->execute([$telefone_destino, $phone_id_remetente, $tipo, $conteudo_salvo, $erro_desc]);
                echo json_encode(['success' => false, 'msg' => 'Erro da Meta: ' . $erro_desc]);
            }
            break;

        case 'carregar_historico':
            $bloqueado_meu     = VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_MEU_CADASTRO');
            $bloqueado_empresa = VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_CAMPANHA_HIERARQUIA');
            
            $sql = "SELECT h.*, DATE_FORMAT(h.DATA_HORA, '%d/%m/%Y %H:%i:%s') as DATA_BR, n.NOME_NUMERO 
                    FROM WHATSAPP_OFICIAL_HISTORICO h 
                    INNER JOIN WHATSAPP_OFICIAL_NUMEROS n ON h.PHONE_NUMBER_ID = n.PHONE_NUMBER_ID
                    INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON n.CONTA_ID = c.ID
                    WHERE 1=1 ";
            
            $params = [];

            if ($bloqueado_meu) {
                $sql .= " AND c.CPF_USUARIO = ? ";
                $params[] = $cpf_logado;
            } elseif ($bloqueado_empresa && isset($_SESSION['empresa_id'])) {
                $sql .= " AND c.id_empresa = ? ";
                $params[] = $_SESSION['empresa_id'];
            }

            $sql .= " ORDER BY h.ID DESC LIMIT 150";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']);
            break;
    }
} catch (Exception $e) { 
    echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); 
}
?>