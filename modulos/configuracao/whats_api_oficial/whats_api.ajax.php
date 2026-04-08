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

    if (!function_exists('VerificaBloqueio')) {
        function VerificaBloqueio($chave) { return false; }
    }

    switch ($acao) {
        
        case 'carregar_config':
            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmt->execute([$cpf_logado]);
            $conta = $stmt->fetch(PDO::FETCH_ASSOC);

            $numeros = [];
            if ($conta) {
                $stmtN = $pdo->prepare("SELECT * FROM WHATSAPP_OFICIAL_NUMEROS WHERE CONTA_ID = ? ORDER BY NOME_NUMERO ASC");
                $stmtN->execute([$conta['ID']]);
                $numeros = $stmtN->fetchAll(PDO::FETCH_ASSOC);
            }
            echo json_encode(['success' => true, 'conta' => $conta, 'numeros' => $numeros]);
            break;

        case 'salvar_config':
            $waba_id = trim($_POST['waba_id'] ?? '');
            $token = trim($_POST['token'] ?? '');

            $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmt->execute([$cpf_logado]);
            $conta = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conta) {
                $pdo->prepare("UPDATE WHATSAPP_OFICIAL_CONTAS SET WABA_ID = ?, PERMANENT_TOKEN = ? WHERE ID = ?")
                    ->execute([$waba_id, $token, $conta['ID']]);
            } else {
                $verify_token = 'crm_' . bin2hex(random_bytes(8)); 
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_CONTAS (CPF_USUARIO, WABA_ID, PERMANENT_TOKEN, WEBHOOK_VERIFY_TOKEN) VALUES (?, ?, ?, ?)")
                    ->execute([$cpf_logado, $waba_id, $token, $verify_token]);
            }
            echo json_encode(['success' => true, 'msg' => 'Conta Meta salva com sucesso!']);
            break;

        case 'adicionar_numero':
            $nome_numero = trim($_POST['nome_numero'] ?? '');
            $phone_id = trim($_POST['phone_id'] ?? '');

            $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmt->execute([$cpf_logado]);
            $conta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conta) { echo json_encode(['success' => false, 'msg' => 'Salve a Credencial primeiro!']); exit; }

            try {
                $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_NUMEROS (CONTA_ID, PHONE_NUMBER_ID, NOME_NUMERO) VALUES (?, ?, ?)")
                    ->execute([$conta['ID'], $phone_id, $nome_numero]);
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

        case 'listar_templates_meta':
            $stmt = $pdo->prepare("SELECT WABA_ID, PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmt->execute([$cpf_logado]);
            $conta = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conta || empty($conta['WABA_ID']) || empty($conta['PERMANENT_TOKEN'])) {
                echo json_encode(['success' => false, 'msg' => 'Credenciais incompletas.']); exit;
            }

            $url = "https://graph.facebook.com/v19.0/{$conta['WABA_ID']}/message_templates?limit=200";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $conta['PERMANENT_TOKEN']]);
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
            
            $stmt = $pdo->prepare("SELECT PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmt->execute([$cpf_logado]);
            $token = $stmt->fetchColumn();

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
            $bloqueado_meu = VerificaBloqueio('SUBMENU_OP_WHATS_API_DISPARO_MEU_REGISTRO'); 
            $bloqueado_empresa = VerificaBloqueio('SUBMENU_OP_WHATS_API_DISPARO_EMPRESA'); 
            
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