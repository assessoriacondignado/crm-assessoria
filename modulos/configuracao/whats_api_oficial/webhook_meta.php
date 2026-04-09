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
        // Verifica se o token pertence a algum BM cadastrado (hierarquia nova)
        $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_BM WHERE WEBHOOK_VERIFY_TOKEN = ?");
        $stmt->execute([$token]);

        if ($stmt->rowCount() === 0) {
            // Fallback: verifica também nas contas legadas (sem BM)
            $stmt = $pdo->prepare("SELECT ID FROM WHATSAPP_OFICIAL_CONTAS WHERE WEBHOOK_VERIFY_TOKEN = ? AND bm_id IS NULL");
            $stmt->execute([$token]);
        }

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
    http_response_code(200); // Meta exige retorno 200 imediato

    $input     = file_get_contents('php://input');
    $dadosMeta = json_decode($input, true);

    if ($dadosMeta && isset($dadosMeta['object']) && $dadosMeta['object'] === 'whatsapp_business_account') {
        foreach ($dadosMeta['entry'] as $entry) {
            foreach ($entry['changes'] as $change) {
                $value           = $change['value'];
                $phone_number_id = $value['metadata']['phone_number_id'] ?? null;

                // ------------------------------------------------------------------
                // A. STATUS DE ENVIO (delivered, read, failed...)
                // ------------------------------------------------------------------
                if (isset($value['statuses'])) {
                    foreach ($value['statuses'] as $status_item) {
                        $message_id  = $status_item['id'];
                        $status_nome = $status_item['status'];
                        $erro_detalhe = null;

                        if ($status_nome === 'failed' && isset($status_item['errors'])) {
                            $erro_titulo  = $status_item['errors'][0]['title'] ?? 'Erro';
                            $erro_desc    = $status_item['errors'][0]['error_data']['details'] ?? '';
                            $erro_detalhe = trim("Meta API: {$erro_titulo} - {$erro_desc}");
                        }

                        // Histórico legado
                        if ($erro_detalhe) {
                            $pdo->prepare("UPDATE WHATSAPP_OFICIAL_HISTORICO SET STATUS_ENVIO = ?, ERRO_DETALHE = ? WHERE MESSAGE_ID = ?")
                                ->execute([$status_nome, $erro_detalhe, $message_id]);
                        } else {
                            $pdo->prepare("UPDATE WHATSAPP_OFICIAL_HISTORICO SET STATUS_ENVIO = ? WHERE MESSAGE_ID = ?")
                                ->execute([$status_nome, $message_id]);
                        }

                        // Chat — atualiza status da mensagem enviada
                        $pdo->prepare("UPDATE WHATSAPP_CHAT_MENSAGENS SET STATUS_ENVIO = ? WHERE MESSAGE_ID = ?")
                            ->execute([$status_nome, $message_id]);

                        // Se lida → reabre janela (Meta considera janela aberta quando cliente interage)
                        if ($status_nome === 'read') {
                            $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_JANELA='ABERTA', DATA_JANELA_EXPIRA = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE PHONE_NUMBER_ID = ?")
                                ->execute([$phone_number_id]);
                        }
                    }
                }

                // ------------------------------------------------------------------
                // B. MENSAGEM RECEBIDA
                // ------------------------------------------------------------------
                if (isset($value['messages'])) {
                    $nome_cliente = $value['contacts'][0]['profile']['name'] ?? '';

                    foreach ($value['messages'] as $msg_item) {
                        $message_id       = $msg_item['id'];
                        $telefone_cliente = $msg_item['from'];
                        $tipo             = $msg_item['type'];
                        $conteudo         = '';
                        $arquivo_path     = null;
                        $prefixo          = !empty($nome_cliente) ? "👤 [{$nome_cliente}]: " : "";

                        switch ($tipo) {
                            case 'text':     $conteudo = $prefixo . ($msg_item['text']['body'] ?? ''); break;
                            case 'button':   $conteudo = $prefixo . "[Botão]: " . ($msg_item['button']['text'] ?? ''); break;
                            case 'interactive':
                                if (isset($msg_item['interactive']['button_reply']))
                                    $conteudo = $prefixo . "[Opção]: " . ($msg_item['interactive']['button_reply']['title'] ?? '');
                                elseif (isset($msg_item['interactive']['list_reply']))
                                    $conteudo = $prefixo . "[Lista]: " . ($msg_item['interactive']['list_reply']['title'] ?? '');
                                break;
                            case 'image':    $conteudo = $prefixo . "[Imagem]"; break;
                            case 'video':    $conteudo = $prefixo . "[Vídeo]"; break;
                            case 'sticker':  $conteudo = $prefixo . "[Figurinha]"; break;
                            case 'document': $conteudo = $prefixo . "[Documento: " . ($msg_item['document']['filename'] ?? '') . "]"; break;
                            case 'audio':    $conteudo = $prefixo . "[Áudio]"; break;
                            default:         $conteudo = $prefixo . "[Mensagem do tipo '{$tipo}']"; break;
                        }

                        // Salva histórico legado
                        $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_HISTORICO (MESSAGE_ID, TELEFONE_CLIENTE, PHONE_NUMBER_ID, DIRECAO, TIPO_MENSAGEM, CONTEUDO, STATUS_ENVIO) VALUES (?, ?, ?, 'RECEBIDA', ?, ?, 'received')")
                            ->execute([$message_id, $telefone_cliente, $phone_number_id, $tipo, $conteudo]);

                        // ----------------------------------------------------------
                        // CHAT: cria/atualiza conversa e salva mensagem
                        // ----------------------------------------------------------
                        if ($phone_number_id) {
                            // Busca ou cria conversa
                            $stmtCv = $pdo->prepare("SELECT ID FROM WHATSAPP_CHAT_CONVERSAS WHERE PHONE_NUMBER_ID = ? AND TELEFONE_CLIENTE = ?");
                            $stmtCv->execute([$phone_number_id, $telefone_cliente]);
                            $conversa_id = $stmtCv->fetchColumn();

                            if (!$conversa_id) {
                                $pdo->prepare("INSERT INTO WHATSAPP_CHAT_CONVERSAS (PHONE_NUMBER_ID, TELEFONE_CLIENTE, NOME_CLIENTE, STATUS_JANELA, DATA_JANELA_EXPIRA, STATUS_ATENDIMENTO) VALUES (?,?,?,'ABERTA', DATE_ADD(NOW(), INTERVAL 24 HOUR),'AGUARDANDO')")
                                    ->execute([$phone_number_id, $telefone_cliente, $nome_cliente]);
                                $conversa_id = $pdo->lastInsertId();
                            } else {
                                // Atualiza nome do cliente se veio na notificação
                                if (!empty($nome_cliente)) {
                                    $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET NOME_CLIENTE=? WHERE ID=? AND (NOME_CLIENTE IS NULL OR NOME_CLIENTE='')")
                                        ->execute([$nome_cliente, $conversa_id]);
                                }
                                // Reabre a janela 24h
                                $pdo->prepare("UPDATE WHATSAPP_CHAT_CONVERSAS SET STATUS_JANELA='ABERTA', DATA_JANELA_EXPIRA=DATE_ADD(NOW(), INTERVAL 24 HOUR), DATA_ULTIMA_MSG=NOW(), STATUS_ATENDIMENTO = IF(STATUS_ATENDIMENTO='FINALIZADO','AGUARDANDO',STATUS_ATENDIMENTO) WHERE ID=?")
                                    ->execute([$conversa_id]);
                            }

                            // Baixa mídia se houver
                            $media_id = $msg_item[$tipo]['id'] ?? null;
                            if ($media_id && in_array($tipo, ['image','video','audio','document','sticker'])) {
                                $arquivo_path = baixarMidiaWebhook($media_id, $tipo, $pdo);
                            }

                            // Salva mensagem no chat
                            $pdo->prepare("INSERT IGNORE INTO WHATSAPP_CHAT_MENSAGENS (CONVERSA_ID, MESSAGE_ID, DIRECAO, TIPO, CONTEUDO, ARQUIVO_PATH, STATUS_ENVIO) VALUES (?,?,'RECEBIDA',?,?,?,'received')")
                                ->execute([$conversa_id, $message_id, $tipo, $conteudo, $arquivo_path]);
                        }
                    }
                }
            }
        }
    }
    exit;
}

// =========================================================================
// HELPER: Baixa mídia da Meta e salva localmente
// =========================================================================
function baixarMidiaWebhook($media_id, $tipo, $pdo) {
    // Busca token de qualquer BM cadastrado (basta um para baixar)
    $stmt = $pdo->query("SELECT PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_BM LIMIT 1");
    $token = $stmt->fetchColumn();
    if (!$token) {
        // Fallback legado
        $stmt2 = $pdo->query("SELECT PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS LIMIT 1");
        $token = $stmt2->fetchColumn();
    }
    if (!$token) return null;

    // 1. Obtém URL da mídia
    $ch = curl_init("https://graph.facebook.com/v19.0/{$media_id}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    $res  = curl_exec($ch); curl_close($ch);
    $json = json_decode($res, true);
    $url  = $json['url'] ?? null;
    $mime = $json['mime_type'] ?? 'application/octet-stream';

    if (!$url) return null;

    // 2. Mapeia extensão
    $ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','video/mp4'=>'mp4','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/opus'=>'opus','application/pdf'=>'pdf'];
    $ext = $ext_map[$mime] ?? 'bin';
    $nome_arquivo = date('Ymd_His') . '_' . $media_id . '.' . $ext;
    $dir = __DIR__ . '/arquivos_recebidos_whats/';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    // 3. Baixa o arquivo
    $ch2 = curl_init($url);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    $binario = curl_exec($ch2); curl_close($ch2);

    if ($binario) {
        file_put_contents($dir . $nome_arquivo, $binario);
        return 'arquivos_recebidos_whats/' . $nome_arquivo;
    }
    return null;
}
?>