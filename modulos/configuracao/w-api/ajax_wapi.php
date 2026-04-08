<?php
session_start();
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) {
    echo json_encode(['success' => false, 'message' => 'Erro interno: Conexão não encontrada.']);
    exit;
}
include $caminho_conexao;

$action = isset($_GET['action']) ? $_GET['action'] : '';
$data = json_decode(file_get_contents('php://input'), true);

$API_BASE_URL = 'https://api.w-api.app/v1';

// Função atualizada com os novos parâmetros obrigatórios da W-API
function chamarWapi($endpoint, $instanceId, $method = 'GET', $payload = null) {
    global $pdo, $API_BASE_URL;
    $stmt = $pdo->prepare("SELECT TOKEN FROM WAPI_INSTANCIAS WHERE INSTANCE_ID = :id");
    $stmt->execute(['id' => $instanceId]);
    $token = $stmt->fetchColumn();
    if (!$token) return ['success' => false, 'message' => 'Instância não cadastrada ou sem token.'];

    $url = $API_BASE_URL . $endpoint;
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $token];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST' && $payload) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        // A API nova retorna a imagem no parâmetro "qrcode"
        $isQrCode = isset($decoded['qrcode']);
        return [
            'success' => true, 
            'data' => $isQrCode ? $decoded['qrcode'] : $decoded, 
            'type' => $isQrCode ? 'qrcode' : 'json'
        ];
    } else {
        return ['success' => false, 'message' => $response, 'error' => $curlError, 'code' => $httpCode];
    }
}

try {
    switch ($action) {
        case 'getInstancesList':
            $stmt = $pdo->query("SELECT ID as id, NOME as nome, TELEFONE as telefone, VENCIMENTO as vencimento, INSTANCE_ID as instanceId, STATUS as status, TIPO as tipo, TOKEN as token FROM WAPI_INSTANCIAS ORDER BY NOME ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'getTemplates':
            $stmt = $pdo->query("SELECT ID as id, NOME_MODELO as nome, OBJETIVO as objetivo, CONTEUDO as conteudo FROM WAPI_TEMPLATES ORDER BY NOME_MODELO ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'getClientsData':
            $stmt = $pdo->query("SELECT NOME as name, CELULAR as phone, CPF as cpf FROM CLIENTE_CADASTRO WHERE CELULAR IS NOT NULL AND CELULAR != '' ORDER BY NOME ASC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'getAutoLogs':
            $stmt = $pdo->query("SELECT DATE_FORMAT(DATA_HORA, '%d/%m %H:%i') as date, INSTANCE_ID as inst, GRUPO_ID as grupo, TELEFONE as phone, NOME as name, MENSAGEM as msg, STATUS as status, CPF as cpf FROM WAPI_LOGS ORDER BY DATA_HORA DESC LIMIT 50");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'addInstanceToSheet':
            $stmt = $pdo->prepare("INSERT INTO WAPI_INSTANCIAS (NOME, INSTANCE_ID, TIPO, TOKEN, TELEFONE, VENCIMENTO) VALUES (:nome, :instanceId, :tipo, :token, :telefone, :vencimento)");
            $stmt->execute(['nome' => $data['nome'], 'instanceId' => $data['instanceId'], 'tipo' => $data['tipo'], 'token' => $data['token'], 'telefone' => $data['telefone'], 'vencimento' => $data['vencimento']]);
            echo json_encode(['success' => true]);
            break;

        case 'editInstanceInSheet':
            $stmt = $pdo->prepare("UPDATE WAPI_INSTANCIAS SET NOME=:nome, INSTANCE_ID=:instanceId, TIPO=:tipo, TOKEN=:token, TELEFONE=:telefone, VENCIMENTO=:vencimento WHERE ID=:id");
            $stmt->execute(['nome' => $data['nome'], 'instanceId' => $data['instanceId'], 'tipo' => $data['tipo'], 'token' => $data['token'], 'telefone' => $data['telefone'], 'vencimento' => $data['vencimento'], 'id' => $data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'saveTemplate':
            $stmt = $pdo->prepare("INSERT INTO WAPI_TEMPLATES (NOME_MODELO, OBJETIVO, CONTEUDO) VALUES (:nome, :objetivo, :conteudo)");
            $stmt->execute(['nome' => $data['nome'], 'objetivo' => $data['objetivo'], 'conteudo' => $data['conteudo']]);
            echo json_encode(['success' => true]);
            break;

        case 'editTemplate':
            $stmt = $pdo->prepare("UPDATE WAPI_TEMPLATES SET NOME_MODELO=:nome, OBJETIVO=:objetivo, CONTEUDO=:conteudo WHERE ID=:id");
            $stmt->execute(['nome' => $data['nome'], 'objetivo' => $data['objetivo'], 'conteudo' => $data['conteudo'], 'id' => $data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'getInstanceConfig':
            $stmt = $pdo->prepare("SELECT * FROM WAPI_CONFIG WHERE INSTANCE_ID = :id");
            $stmt->execute(['id' => $data['instanceId']]);
            $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cfg) {
                echo json_encode([
                    'instanceId' => $cfg['INSTANCE_ID'],
                    'ativo' => (bool)$cfg['ATIVO_GLOBAL'],
                    'mensagem' => $cfg['MENSAGEM_PADRAO'],
                    'grupoAviso' => $cfg['GRUPO_AVISO'],
                    'schedule' => json_decode($cfg['SCHEDULE_JSON'], true),
                    'chatbot' => json_decode($cfg['CHATBOT_JSON'], true)
                ]);
            } else {
                echo json_encode(null);
            }
            break;

        case 'saveInstanceConfigApi':
            $stmt = $pdo->prepare("INSERT INTO WAPI_CONFIG (INSTANCE_ID, ATIVO_GLOBAL, SCHEDULE_JSON, MENSAGEM_PADRAO, GRUPO_AVISO, CHATBOT_JSON) 
                                   VALUES (:id, :ativo, :schedule, :msg, :grupo, :chatbot)
                                   ON DUPLICATE KEY UPDATE 
                                   ATIVO_GLOBAL=:ativo, SCHEDULE_JSON=:schedule, MENSAGEM_PADRAO=:msg, GRUPO_AVISO=:grupo, CHATBOT_JSON=:chatbot");
            $stmt->execute([
                'id' => $data['instanceId'],
                'ativo' => $data['ativo'] ? 1 : 0,
                'schedule' => json_encode($data['schedule']),
                'msg' => $data['mensagem'],
                'grupo' => $data['grupoAviso'],
                'chatbot' => json_encode($data['chatbot'])
            ]);
            echo json_encode(['success' => true, 'message' => 'Configurações salvas no MySQL!']);
            break;

        case 'executeCommand':
            $cmd = $data['action'];
            $inst = $data['instanceId'];
            if ($cmd === 'qrcode') {
                $res = chamarWapi("/instance/qr-code?instanceId={$inst}", $inst, 'GET');
            } elseif ($cmd === 'logout') {
                $res = chamarWapi("/instance/disconnect?instanceId={$inst}", $inst, 'GET');
            } elseif ($cmd === 'restart') {
                $res = chamarWapi("/instance/restart?instanceId={$inst}", $inst, 'PUT');
            } elseif ($cmd === 'status') {
                $res = chamarWapi("/instance/connection-state?instanceId={$inst}", $inst, 'GET');
                if ($res['success'] && isset($res['data']['state'])) {
                    $up = $pdo->prepare("UPDATE WAPI_INSTANCIAS SET STATUS = :st WHERE INSTANCE_ID = :id");
                    $up->execute(['st' => $res['data']['state'], 'id' => $inst]);
                }
            }
            echo json_encode($res);
            break;

        case 'sendWapiMessage':
            $alvo = $data['target'];
            $msg = $data['message'];
            $instId = $data['instanceId'];
            $isGroup = $data['isGroup'];
            
            $finalNumber = $isGroup ? $alvo : preg_replace('/[^0-9]/', '', $alvo);
            if (!$isGroup && !str_starts_with($finalNumber, '55')) $finalNumber = '55' . $finalNumber;
            if ($isGroup && !str_contains($finalNumber, '@g.us')) $finalNumber .= '@g.us';

            $payload = ['phone' => $finalNumber, 'message' => $msg, 'delayMessage' => 2];
            $res = chamarWapi("/message/send-text?instanceId={$instId}", $instId, 'POST', $payload);
            
            if ($res['success']) {
                $dadosRetorno = $res['data'] ?? [];
                $idMensagem = $dadosRetorno['messageId'] ?? 'ID não gerado';
                $res['message'] = "✅ SUCESSO!\nW-API confirmou o envio.\nID: " . $idMensagem;
            } else {
                $codigoHttp = $res['code'] ?? '000';
                $res['message'] = "❌ ERRO W-API (Código: {$codigoHttp})\n" . ($res['message'] ?? 'Falha na conexão.');
            }
            echo json_encode($res);
            break;

        case 'sendWapiDocument':
            $alvo = $data['target'];
            $documentUrl = $data['document'];
            $extension = $data['extension'];
            $fileName = $data['fileName'] ?? '';
            $caption = $data['caption'] ?? '';
            $instId = $data['instanceId'];
            $isGroup = $data['isGroup'];
            
            $finalNumber = $isGroup ? $alvo : preg_replace('/[^0-9]/', '', $alvo);
            if (!$isGroup && !str_starts_with($finalNumber, '55')) $finalNumber = '55' . $finalNumber;
            if ($isGroup && !str_contains($finalNumber, '@g.us')) $finalNumber .= '@g.us';

            $payload = [
                'phone' => $finalNumber,
                'document' => $documentUrl,
                'extension' => $extension,
                'delayMessage' => 2
            ];
            
            if (!empty($fileName)) $payload['fileName'] = $fileName;
            if (!empty($caption)) $payload['caption'] = $caption;

            $res = chamarWapi("/message/send-document?instanceId={$instId}", $instId, 'POST', $payload);
            
            if ($res['success']) {
                $dadosRetorno = $res['data'] ?? [];
                $idMensagem = $dadosRetorno['messageId'] ?? 'ID não gerado';
                $res['message'] = "✅ SUCESSO!\nW-API confirmou o envio do arquivo.\nID: " . $idMensagem;
            } else {
                $codigoHttp = $res['code'] ?? '000';
                $res['message'] = "❌ ERRO W-API (Código: {$codigoHttp})\n" . ($res['message'] ?? 'Falha na conexão.');
            }
            echo json_encode($res);
            break;

        case 'searchClient':
            $term = isset($data['term']) ? preg_replace('/[^a-zA-Z0-9 ]/', '', $data['term']) : '';
            $stmt = $pdo->prepare("SELECT CPF, NOME, CELULAR FROM CLIENTE_CADASTRO WHERE NOME LIKE :term OR CPF LIKE :term OR CELULAR LIKE :term ORDER BY NOME ASC LIMIT 15");
            $stmt->execute(['term' => "%$term%"]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'updateContactInfo':
            $logPhoneRaw = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
            $cpfRaw = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
            $nomeRaw = $data['name'] ?? '';

            if (empty($cpfRaw) && !empty($nomeRaw)) {
                preg_match('/\d{11}/', preg_replace('/[^0-9]/', '', $nomeRaw), $matches);
                if (isset($matches[0])) { $cpfRaw = $matches[0]; }
            }

            $nomeLimpo = trim(preg_replace('/\(CPF:.*?\)/i', '', $nomeRaw));

            if (empty($logPhoneRaw) || empty($cpfRaw)) {
                echo json_encode(['success' => false, 'message' => 'Faltam dados. Telefone ou CPF inválidos. (Tel: ' . $logPhoneRaw . ' / CPF: ' . $cpfRaw . ')']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $stmtCli = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CELULAR = :tel WHERE CPF = :cpf");
                $stmtCli->execute(['tel' => substr($logPhoneRaw, 0, 20), 'cpf' => $cpfRaw]); 
                
                $stmtLog = $pdo->prepare("UPDATE WAPI_LOGS SET NOME = :nome, CPF = :cpf WHERE TELEFONE LIKE :tel");
                $stmtLog->execute(['nome' => substr($nomeLimpo, 0, 100), 'cpf' => $cpfRaw, 'tel' => "%$logPhoneRaw%"]);

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Vínculo realizado com sucesso e Cliente atualizado!']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Erro de Banco de Dados: ' . $e->getMessage()]);
            }
            break;

        case 'viewQueue':
            $instId = $data['instanceId'];
            $res = chamarWapi("/quere/quere?instanceId={$instId}&perPage=50&page=1", $instId, 'GET');
            echo json_encode($res);
            break;

        case 'clearQueue':
            $instId = $data['instanceId'];
            $res = chamarWapi("/quere/delete-quere?instanceId={$instId}", $instId, 'DELETE');
            if ($res['success']) {
                $res['message'] = "✅ Fila apagada com sucesso no servidor da W-API!";
            } else {
                $res['message'] = "Erro ao limpar fila: " . ($res['error'] ?? 'Desconhecido');
            }
            echo json_encode($res);
            break;

        case 'deleteQueueMessage':
            $instId = $data['instanceId'];
            $msgId = $data['messageId'];
            $res = chamarWapi("/quere/delete-msg-quere?instanceId={$instId}&messageId={$msgId}", $instId, 'DELETE');
            if ($res['success']) {
                $res['message'] = "✅ Mensagem removida da fila!";
            } else {
                $res['message'] = "Erro ao remover mensagem: " . ($res['error'] ?? 'Desconhecido');
            }
            echo json_encode($res);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Ação desconhecida.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Falha no servidor: ' . $e->getMessage()]);
}
?>