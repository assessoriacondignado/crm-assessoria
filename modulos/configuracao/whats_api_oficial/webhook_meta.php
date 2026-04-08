<?php
ini_set('display_errors', 0);
date_default_timezone_set('America/Sao_Paulo');

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// =========================================================================
// 1. VERIFICAÇÃO DO WEBHOOK (GET)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $token = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    if ($mode === 'subscribe') {
        // Verifica se o token recebido pertence a ALGUMA das contas cadastradas
        $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_CONTAS WHERE WEBHOOK_VERIFY_TOKEN = ?");
        $stmt->execute([$token]);
        
        if ($stmt->rowCount() > 0) {
            http_response_code(200); echo $challenge; exit;
        } else {
            http_response_code(403); echo "Acesso Negado."; exit;
        }
    }
}

// =========================================================================
// 2. RECEBIMENTO DE EVENTOS E MENSAGENS (POST)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(200); // Meta exige retorno 200 Imediato
    
    $input = file_get_contents('php://input');
    $dadosMeta = json_decode($input, true);

    if ($dadosMeta && isset($dadosMeta['object']) && $dadosMeta['object'] === 'whatsapp_business_account') {
        foreach ($dadosMeta['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                $value = $change['value'];
                
                // Captura para qual Phone ID essa notificação foi mandada
                $phone_number_id = $value['metadata']['phone_number_id'] ?? null;

                // A. STATUS DE LEITURA E ERROS
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status_item) {
                        $message_id = $status_item['id'];
                        $status_nome = $status_item['status'];
                        $erro_detalhe = null;

                        // Se o status for de falha, captura o título e detalhes do erro
                        if ($status_nome === 'failed' && isset($status_item['errors'])) {
                            $erro_titulo = $status_item['errors'][0]['title'] ?? 'Erro';
                            $erro_desc = $status_item['errors'][0]['error_data']['details'] ?? '';
                            $erro_detalhe = trim("Meta API: {$erro_titulo} - {$erro_desc}");
                        }

                        if ($erro_detalhe) {
                            $pdo->prepare("UPDATE WHATSAPP_OFICIAL_HISTORICO SET STATUS_ENVIO = ?, ERRO_DETALHE = ? WHERE MESSAGE_ID = ?")
                                ->execute([$status_nome, $erro_detalhe, $message_id]);
                        } else {
                            $pdo->prepare("UPDATE WHATSAPP_OFICIAL_HISTORICO SET STATUS_ENVIO = ? WHERE MESSAGE_ID = ?")
                                ->execute([$status_nome, $message_id]);
                        }
                    }
                }

                // B. MENSAGEM RECEBIDA
                if (isset($value['messages'])) {
                    // Captura o nome de perfil do cliente no WhatsApp
                    $nome_cliente = $value['contacts'][0]['profile']['name'] ?? '';
                    
                    foreach ($value['messages'] as $msg_item) {
                        $message_id = $msg_item['id'];
                        $telefone_cliente = $msg_item['from'];
                        $tipo = $msg_item['type'];
                        $conteudo = '';

                        // Adiciona o nome do perfil do cliente no início da mensagem recebida para identificação
                        $prefixo = !empty($nome_cliente) ? "👤 [{$nome_cliente}]: " : "";

                        switch ($tipo) {
                            case 'text': $conteudo = $prefixo . ($msg_item['text']['body'] ?? ''); break;
                            case 'button': $conteudo = $prefixo . "[Botão]: " . ($msg_item['button']['text'] ?? ''); break;
                            case 'interactive':
                                if(isset($msg_item['interactive']['button_reply'])) $conteudo = $prefixo . "[Opção]: " . ($msg_item['interactive']['button_reply']['title'] ?? '');
                                elseif (isset($msg_item['interactive']['list_reply'])) $conteudo = $prefixo . "[Lista]: " . ($msg_item['interactive']['list_reply']['title'] ?? '');
                                break;
                            case 'image': $conteudo = $prefixo . "[Imagem]"; break;
                            case 'video': $conteudo = $prefixo . "[Vídeo]"; break;
                            case 'sticker': $conteudo = $prefixo . "[Figurinha]"; break;
                            case 'document': $conteudo = $prefixo . "[Documento]"; break;
                            case 'audio': $conteudo = $prefixo . "[Áudio]"; break;
                            default: $conteudo = $prefixo . "[Mensagem do tipo '{$tipo}']"; break;
                        }

                        $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_HISTORICO (MESSAGE_ID, TELEFONE_CLIENTE, PHONE_NUMBER_ID, DIRECAO, TIPO_MENSAGEM, CONTEUDO, STATUS_ENVIO) VALUES (?, ?, ?, 'RECEBIDA', ?, ?, 'received')")
                            ->execute([$message_id, $telefone_cliente, $phone_number_id, $tipo, $conteudo]);
                    }
                }
            }
        }
    }
    exit;
}
?>