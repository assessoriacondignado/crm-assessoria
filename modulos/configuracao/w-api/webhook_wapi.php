<?php
// ==========================================
// 1. SISTEMA DE RASTREAMENTO E PREPARAÇÃO
// ==========================================
$logFile = __DIR__ . '/debug_webhook.txt';
$jsonData = file_get_contents('php://input');

if (empty($jsonData)) { echo "<h2 style='color:green;'>Webhook W-API Online (Modo Grupo e Regra 24h)!</h2>"; exit; }

file_put_contents($logFile, "======================\nDATA: " . date('d/m/Y H:i:s') . "\nJSON RECEBIDO:\n" . $jsonData . "\n", FILE_APPEND);

$rawData = json_decode($jsonData, true);
if (!$rawData) { http_response_code(400); exit('Invalid JSON'); }

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) { http_response_code(500); exit('No DB'); }
include $caminho_conexao;

// ==========================================
// 2. EXTRAÇÃO DE DADOS E VALIDAÇÃO DE GRUPO
// ==========================================
try {
    $instanceId = $rawData['instanceId'] ?? 'Desconhecido';
    $isGroup = (bool)($rawData['isGroup'] ?? false);
    $fromMe = (bool)($rawData['fromMe'] ?? false);
    $statusMsg = $fromMe ? 'Enviado' : 'Recebido';

    $chatId = $rawData['chat']['id'] ?? '';
    $senderObj = $rawData['sender'] ?? ($rawData['remetente'] ?? null);

    $senderPhone = ''; $pushName = 'Desconhecido';
    if ($senderObj) {
        $senderPhone = $senderObj['id'] ?? '';
        $pushName = $senderObj['pushName'] ?? ($senderObj['verifiedBizName'] ?? 'Desconhecido');
    }

    if (!$isGroup && strpos($chatId, '@g.us') === false) {
        $senderPhoneLimpo = preg_replace('/[^0-9]/', '', explode('@', $chatId)[0]);
        $content = extractContent($rawData);
        if(!empty($content)) {
            $stmtLog = $pdo->prepare("INSERT INTO WAPI_LOGS (INSTANCE_ID, GRUPO_ID, TELEFONE, NOME, MENSAGEM, STATUS) VALUES (?, ?, ?, ?, ?, ?)");
            $stmtLog->execute([$instanceId, 'MSG INDIVIDUAL', $senderPhoneLimpo, $pushName, $content, $statusMsg]);
        }
        http_response_code(200); exit('Ignored - Private Message');
    }

    $groupId = explode('@', $chatId)[0];
    if (empty($senderPhone)) { $senderPhone = $chatId; }
    $senderPhone = preg_replace('/[^0-9]/', '', explode('@', $senderPhone)[0]);

    $content = extractContent($rawData);
    if (empty($content)) { http_response_code(200); exit('Ignored - Empty Content'); }

    // =========================================================================
    // 3. BUSCA O CLIENTE DONO DESTE GRUPO NO CRM
    // =========================================================================
    $cpf_dono = ''; $saldo_cliente = 0; $custo_cliente = 0.50; $is_cliente = false;
    try {
        $stmtCli = $pdo->prepare("SELECT NOME, CPF, SALDO, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE GRUPO_WHATS = :grupo LIMIT 1");
        $stmtCli->execute(['grupo' => $groupId]);
        $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
        
        if ($cliente) {
            $is_cliente = true;
            $cpf_dono = $cliente['CPF'];
            $saldo_cliente = floatval($cliente['SALDO']);
            $custo_cliente = floatval($cliente['CUSTO_CONSULTA']);
        }
    } catch (Exception $e) { }

    $stmtLog = $pdo->prepare("INSERT INTO WAPI_LOGS (INSTANCE_ID, GRUPO_ID, TELEFONE, NOME, MENSAGEM, STATUS, CPF) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtLog->execute([$instanceId, $groupId, $senderPhone, $pushName, $content, $statusMsg, $cpf_dono]);

    // =========================================================================
    // 4. O CÉREBRO DO ROBÔ (FATOR CONFERI WAPI)
    // =========================================================================
    
    $stmtCfg = $pdo->query("SELECT * FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1");
    $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC);
    
    if (!$fromMe && $is_cliente) {
        
        $msgUpper = strtoupper(trim($content));
        
        $cmd_menu     = strtoupper(trim($cfg['CMD_MENU'] ?? '#MENU'));
        $cmd_saldo    = strtoupper(trim($cfg['CMD_SALDO'] ?? '#SALDO'));
        $cmd_consulta = strtoupper(trim($cfg['CMD_CONSULTA'] ?? '#CPF:'));
        $cmd_completo = strtoupper(trim($cfg['CMD_COMPLETO'] ?? '#CPF/COMPLETO:'));
        $cmd_suporte  = strtoupper(trim($cfg['CMD_SUPORTE'] ?? '#SUPORTE'));
        
        $resposta_bot = null;

        if (strpos($msgUpper, $cmd_menu) === 0) {
            $resposta_bot = "🤖 *MENU DE CONSULTAS*\n\n🔹 *Simples:* {$cmd_consulta}00000000000\n🔸 *Completa:* {$cmd_completo}00000000000\n💰 *Saldo:* {$cmd_saldo}\n🆘 *Suporte:* {$cmd_suporte}";
        }
        elseif (strpos($msgUpper, $cmd_saldo) === 0) {
            $resposta_bot = "💰 *SALDO DESTE GRUPO:* R$ " . number_format($saldo_cliente, 2, ',', '.');
            if ($saldo_cliente <= $custo_cliente) { $resposta_bot .= "\n\n⚠️ Atenção: O saldo está muito baixo. Adicione créditos para continuar consultando."; }
        }
        elseif (strpos($msgUpper, $cmd_suporte) === 0) {
            $resposta_bot = "📞 *SUPORTE SOLICITADO*\n\nNossa equipe foi notificada.\nAguarde, entraremos em contato.";
        }
        elseif (strpos($msgUpper, $cmd_consulta) === 0 || strpos($msgUpper, $cmd_completo) === 0) {
            
            $modo = (strpos($msgUpper, $cmd_completo) === 0) ? 'COMPLETO' : 'SIMPLES';
            $prefixo = ($modo === 'COMPLETO') ? $cmd_completo : $cmd_consulta;
            $cpf_busca = preg_replace('/\D/', '', substr($msgUpper, strlen($prefixo)));
            
            if (strlen($cpf_busca) >= 10) {
                // =======================================================
                // REQUISIÇÃO PARA O AJAX
                // =======================================================
                $is_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                $protocolo = $is_https ? "https" : "http";
                
                $dir_wapi = dirname($_SERVER['SCRIPT_NAME']);
                $dir_parent = dirname($dir_wapi);
                if ($dir_parent === '\\' || $dir_parent === '/') { $dir_parent = ''; }
                $url_ajax = $protocolo . "://" . $_SERVER['HTTP_HOST'] . $dir_parent . "/fator_conferi/fator_conferi.ajax.php";
                
                // A MÁGICA ACONTECE AQUI: Passamos o CPF do dono e o ID do Grupo para o AJAX resolver o lado financeiro
                $post_data = [
                    'acao' => 'consulta_cpf_manual', 
                    'cpf' => $cpf_busca, 
                    'forcar_api' => '0', 
                    'fonte' => 'WHATSAPP_GRUPO',
                    'cpf_cobrar' => $cpf_dono,
                    'grupo_whats' => $groupId
                ];
                
                $ch = curl_init(); 
                curl_setopt($ch, CURLOPT_URL, $url_ajax); 
                curl_setopt($ch, CURLOPT_POST, 1); 
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data)); 
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);
                
                $resposta_json = curl_exec($ch); 
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                $res_api = json_decode($resposta_json, true);
                // =======================================================

                if ($res_api && isset($res_api['success']) && $res_api['success']) {
                    
                    $dados_api = $res_api['json_bruto'] ?? [];
                    $cad = $dados_api['CADASTRAIS'] ?? [];
                    $ends = $dados_api['ENDERECOS']['ENDERECO'] ?? null;
                    if($ends && !isset($ends[0])) { $ends = [$ends]; }
                    $end_principal = $ends[0] ?? [];
                    $tels = $dados_api['TELEFONES_MOVEL']['TELEFONE'] ?? null;
                    if($tels && !isset($tels[0])) { $tels = [$tels]; }

                    $resposta_bot = ($modo === 'COMPLETO') ? "✅ *CONSULTA COMPLETA*\n" : "✅ *CONSULTA SIMPLES*\n";
                    $resposta_bot .= "🆔 *CPF:* $cpf_busca\n👤 *Nome:* " . ($cad['NOME'] ?? 'Não informado') . "\n";
                    
                    if ($modo === 'COMPLETO') {
                        $resposta_bot .= "🎂 *Nasc:* " . ($cad['NASCTO'] ?? '--') . " (" . ($cad['IDADE'] ?? '?') . " anos)\n";
                        $resposta_bot .= "👵 *Mãe:* " . ($cad['NOME_MAE'] ?? '--') . "\n";
                        $resposta_bot .= "💀 *Óbito:* " . (($cad['CONSTA_OBITO'] ?? 'N') == 'S' ? 'SIM' : 'NÃO') . "\n";
                        if(!empty($cad['RG']) || !empty($cad['NIT'])) { $resposta_bot .= "\n📂 *DOCUMENTOS*\n"; if(!empty($cad['RG'])) { $resposta_bot .= "📝 RG: " . $cad['RG'] . "\n"; } if(!empty($cad['NIT'])) { $resposta_bot .= "🔢 NIT/PIS: " . $cad['NIT'] . "\n"; } }
                        $resposta_bot .= "\n💼 *PERFIL*\n🏭 Profissão: " . ($cad['PROFISSAO'] ?? '--') . "\n💵 Renda Est.: R$ " . ($cad['SALARIO'] ?? '--') . "\n";
                    }
                    
                    if (!empty($end_principal['LOGRADOURO']) || !empty($end_principal['CIDADE'])) {
                        $resposta_bot .= "\n📍 *ENDEREÇO*\n🏠 " . ($end_principal['LOGRADOURO'] ?? '--') . ", " . ($end_principal['NUMERO'] ?? '--') . "\n🏙️ " . ($end_principal['BAIRRO'] ?? '--') . " - " . ($end_principal['CIDADE'] ?? '--') . "/" . ($end_principal['ESTADO'] ?? '--') . "\n";
                        if ($modo === 'COMPLETO' && !empty($end_principal['CEP'])) { $resposta_bot .= "📮 CEP: " . $end_principal['CEP'] . "\n"; }
                    }

                    $resposta_bot .= "\n📞 *CONTATOS*\n";
                    if (!empty($tels)) {
                        $cont_fones = 0;
                        foreach($tels as $t) {
                            if ($cont_fones >= 6) break;
                            $num = $t['NUMERO'] ?? '';
                            if (strlen(preg_replace('/\D/', '', $num)) > 5) {
                                $zap = (($t['TEM_ZAP'] ?? 'N') == 'S') ? "(W) " : "";
                                $resposta_bot .= "📱 $zap$num\n";
                                $cont_fones++;
                            }
                        }
                    } else { $resposta_bot .= "(Nenhum telefone recente)\n"; }

                    // LÊ A DECISÃO FINANCEIRA TOMADA PELO AJAX
                    if (isset($res_api['cobranca']) && $res_api['cobranca']) {
                        if ($res_api['cobranca']['is_repetida']) {
                            $resposta_bot .= "\n♻️ *Consulta Repetida (24h):* Grátis";
                        } else {
                            $resposta_bot .= "\n📉 Custo: R$ " . number_format($res_api['cobranca']['custo'], 2, ',', '.') . " | 💰 Saldo: R$ " . number_format($res_api['cobranca']['saldo_atual'], 2, ',', '.');
                        }
                    }

                } else { 
                    $msg_erro = $res_api['msg'] ?? "Erro Servidor: HTTP $http_code | URL: $url_ajax";
                    if($curl_error) $msg_erro .= " | Detalhe: $curl_error";
                    $resposta_bot = "⚠️ Falha na Consulta: " . $msg_erro; 
                }
            } else { $resposta_bot = "⚠️ Formato inválido. Digite 11 números após o comando."; }
        }

        // =========================================================================
        // 5. DISPARO DA RESPOSTA VIA W-API DE VOLTA PARA O GRUPO
        // =========================================================================
        if ($resposta_bot) {
            $wapi_instance_banco = $cfg['WAPI_INSTANCE'] ?? '';
            $instance_final = !empty($wapi_instance_banco) ? $wapi_instance_banco : $instanceId;
            
            $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId={$instance_final}";
            $payload = json_encode([ "phone" => $chatId, "message" => $resposta_bot ]);

            $ch_w = curl_init();
            curl_setopt($ch_w, CURLOPT_URL, $url_wapi);
            curl_setopt($ch_w, CURLOPT_POST, 1);
            curl_setopt($ch_w, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch_w, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_w, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . ($cfg['WAPI_TOKEN'] ?? '')
            ]);
            $resultado_wapi = curl_exec($ch_w);
            curl_close($ch_w);

            $stmtLog->execute([$instance_final, $groupId, 'ROBÔ', 'Resposta Automática', $resposta_bot, 'Enviado para Grupo', $cpf_dono]);
        }
    }

    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    file_put_contents($logFile, "ERRO GERAL: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    exit('Error');
}

function extractContent($rawData) {
    $msgContent = $rawData['msgContent'] ?? [];
    if (isset($msgContent['conversation'])) { return $msgContent['conversation']; } 
    elseif (isset($msgContent['extendedTextMessage']['text'])) { return $msgContent['extendedTextMessage']['text']; } 
    return "";
}
?>