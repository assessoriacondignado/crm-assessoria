<?php
// Worker: monitora sessões AGUARDANDO_DATAPREV e envia resultado via GPTMaker API
set_time_limit(120);
ignore_user_abort(true);
date_default_timezone_set('America/Sao_Paulo');

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: dirname(__DIR__, 3);
require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$log_dir = __DIR__ . '/logs_ia';
if (!is_dir($log_dir)) mkdir($log_dir, 0777, true);

function logFU($msg) {
    global $log_dir;
    file_put_contents($log_dir . '/followup_' . date('Y-m-d') . '.log',
        '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Config GPTMaker carregada por sessão (por CPF_DONO da credencial IA)

// Busca sessões pendentes com pelo menos 60s de espera, máx 10 tentativas
$stmtFU = $pdo->prepare("
    SELECT * FROM INTEGRACAO_V8_IA_FOLLOWUP
    WHERE STATUS = 'PENDENTE'
      AND TENTATIVAS < 10
      AND (DATA_ULTIMA_TENTATIVA IS NULL OR DATA_ULTIMA_TENTATIVA <= DATE_SUB(NOW(), INTERVAL 90 SECOND))
    ORDER BY DATA_CRIACAO ASC
    LIMIT 20
");
$stmtFU->execute();
$pendentes = $stmtFU->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendentes)) { logFU("Nenhuma sessão pendente."); exit; }

logFU("Processando " . count($pendentes) . " sessões pendentes.");

// Funções auxiliares
function gerarTokenV8FU($cred) {
    $payload = http_build_query([
        'grant_type' => 'password', 'username' => $cred['USERNAME_API'],
        'password' => $cred['PASSWORD_API'], 'audience' => $cred['AUDIENCE'],
        'client_id' => $cred['CLIENT_ID'], 'scope' => 'offline_access'
    ]);
    $ch = curl_init("https://auth.v8sistema.com/oauth/token");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $res = curl_exec($ch); curl_close($ch);
    $json = json_decode($res, true);
    if (isset($json['access_token'])) return $json['access_token'];
    throw new Exception("Falha token V8: " . ($json['error_description'] ?? $res));
}

function normalizarFU($str) {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e',
            'í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'];
    return strtr($str, $map);
}

function enviarGPTMaker($gpt_agent, $gpt_token, $telefone, $mensagem) {
    $phone = preg_replace('/\D/', '', $telefone);
    if (strlen($phone) < 10) return false;
    // Garante prefixo 55 (Brasil): se < 12 dígitos, ainda não tem o código do país
    if (strlen($phone) < 12) {
        $phone = '55' . $phone;
    }

    $payload = json_encode([
        'phone'   => $phone,
        'message' => $mensagem
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://api.gptmaker.ai/v2/agent/{$gpt_agent}/conversation");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $gpt_token,
            'Content-Type: application/json'
        ]
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http' => $http, 'body' => $res];
}

$ST_OK   = ['SUCCESS','COMPLETED','APPROVED','PRE_APPROVED',
            'SIMULATED','READY','AVAILABLE','MARGIN_AVAILABLE','AUTHORIZED','DONE','FINISHED'];
$ST_WAIT = ['WAITING_CREDIT_ANALYSIS','PROCESSING','PENDING','WAITING','WAITING_CONSULT',
            'ANALYZING','IN_PROGRESS','PENDING_CONSULTATION','CONSENT_APPROVED','CREATED','QUEUED','STARTED'];
$ST_ERRO = ['ERROR','REJECTED','DENIED','CANCELED','EXPIRED','FAILED'];

foreach ($pendentes as $fu) {
    $cpf        = $fu['CPF_CLIENTE'];
    // Usa telefone WhatsApp (com 55) se disponível, senão telefone limpo
    $telefone   = !empty($fu['TELEFONE_WHATSAPP']) ? $fu['TELEFONE_WHATSAPP'] : ($fu['TELEFONE'] ?: '11900000000');
    $consult_id = $fu['CONSULT_ID'];
    $token_ia   = $fu['TOKEN_IA'];
    $agent_id_salvo = $fu['AGENT_ID'] ?? null;
    $tentativas = (int)$fu['TENTATIVAS'] + 1;

    logFU("CPF {$cpf} | Tentativa {$tentativas} | CONSULT {$consult_id} | Agent: " . ($agent_id_salvo ?: 'lookup'));

    // Atualiza tentativa
    $pdo->prepare("UPDATE INTEGRACAO_V8_IA_FOLLOWUP SET TENTATIVAS=?, DATA_ULTIMA_TENTATIVA=NOW() WHERE ID=?")
        ->execute([$tentativas, $fu['ID']]);

    try {
        // Credenciais V8 via token IA
        $stmtC = $pdo->prepare("SELECT i.*, v.USERNAME_API, v.PASSWORD_API, v.CLIENT_ID, v.AUDIENCE,
                                        v.TABELA_PADRAO, v.PRAZO_PADRAO, v.ID as CHAVE_REAL_V8
                                 FROM INTEGRACAO_V8_IA_CREDENCIAIS i
                                 JOIN INTEGRACAO_V8_CHAVE_ACESSO v ON i.CHAVE_V8_ID = v.ID
                                 WHERE i.TOKEN_IA = CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci AND i.STATUS = 'ATIVO' LIMIT 1");
        $stmtC->execute([$token_ia]);
        $cred = $stmtC->fetch(PDO::FETCH_ASSOC);
        if (!$cred) throw new Exception("Credencial não encontrada para token.");

        // Config GPTMaker: prioriza AGENT_ID salvo na fila, senão busca por CPF_DONO
        if ($agent_id_salvo) {
            $stmtGPT = $pdo->prepare("SELECT API_TOKEN, AGENT_ID FROM INTEGRACAO_GPTMAKE_CONFIG WHERE AGENT_ID = ? AND STATUS = 'ATIVO' LIMIT 1");
            $stmtGPT->execute([$agent_id_salvo]);
            $gptCfg = $stmtGPT->fetch(PDO::FETCH_ASSOC);
        }
        if (empty($gptCfg)) {
            $stmtGPT = $pdo->prepare("SELECT API_TOKEN, AGENT_ID FROM INTEGRACAO_GPTMAKE_CONFIG WHERE CPF_USUARIO = ? AND STATUS = 'ATIVO' ORDER BY ID DESC LIMIT 1");
            $stmtGPT->execute([$cred['CPF_DONO']]);
            $gptCfg = $stmtGPT->fetch(PDO::FETCH_ASSOC);
        }
        if (!$gptCfg) throw new Exception("Config GPTMaker não encontrada para agente/CPF dono {$cred['CPF_DONO']}.");
        $gpt_token = $gptCfg['API_TOKEN'];
        $gpt_agent = $gptCfg['AGENT_ID'];

        logFU("CPF {$cpf} | GPTMaker Agent: {$gpt_agent} | Tel: {$telefone}");

        $tokenV8 = gerarTokenV8FU($cred);

        // Consulta status na V8
        $ch = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPGET=>true, CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>["Authorization: Bearer {$tokenV8}"]]);
        $resV8 = curl_exec($ch); curl_close($ch);
        $jsonV8 = json_decode($resV8, true);
        $status_v8 = strtoupper($jsonV8['status'] ?? '');

        logFU("CPF {$cpf} | Status V8: {$status_v8}");

        if (in_array($status_v8, $ST_OK)) {
            // Margem liberada — processa simulação
            $margem = (float)($jsonV8['availableMargin'] ?? $jsonV8['marginBaseValue'] ?? $jsonV8['availableMarginValue'] ?? $jsonV8['maxAmount'] ?? 0);

            // Identifica tabela
            $tabela_padrao = trim($cred['TABELA_PADRAO'] ?: 'CLT Acelera');
            $chCfg = curl_init("https://bff.v8sistema.com/private-consignment/simulation/configs?consult_id={$consult_id}");
            curl_setopt_array($chCfg, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPGET=>true, CURLOPT_TIMEOUT=>15,
                CURLOPT_HTTPHEADER=>["Authorization: Bearer {$tokenV8}"]]);
            $resCfg = curl_exec($chCfg); curl_close($chCfg);
            $jsonCfg = json_decode($resCfg, true);

            $config_id = null; $lista = $jsonCfg['configs'] ?? (isset($jsonCfg[0]['id']) ? $jsonCfg : []);
            $busca = normalizarFU($tabela_padrao);
            if (is_array($lista) && count($lista) > 0) {
                foreach ($lista as $cfg) {
                    if (normalizarFU($cfg['name'] ?? $cfg['slug'] ?? '') === $busca) {
                        $config_id = $cfg['id']; break;
                    }
                }
                if (!$config_id) $config_id = $lista[0]['id'];
            }
            if (!$config_id) $config_id = $consult_id;

            $prazo = (int)($cred['PRAZO_PADRAO'] ?: 84);
            $payload_sim = ['consult_id' => $consult_id, 'config_id' => $config_id, 'number_of_installments' => $prazo];
            if ($margem > 0) $payload_sim['installment_face_value'] = $margem;

            $chS = curl_init("https://bff.v8sistema.com/private-consignment/simulation");
            curl_setopt_array($chS, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>20,
                CURLOPT_POSTFIELDS=>json_encode($payload_sim),
                CURLOPT_HTTPHEADER=>["Authorization: Bearer {$tokenV8}", "Content-Type: application/json"]]);
            $resS = curl_exec($chS); $httpS = curl_getinfo($chS, CURLINFO_HTTP_CODE); curl_close($chS);
            $jsonS = json_decode($resS, true);

            if ($httpS >= 400 || empty($jsonS)) throw new Exception("Erro simulação V8 HTTP {$httpS}");

            $sim = isset($jsonS[0]) ? $jsonS[0] : $jsonS;
            $vl  = (float)($sim['disbursement_amount'] ?? $sim['disbursed_amount'] ?? 0);
            $vp  = (float)($sim['installment_value'] ?? $sim['installment_face_value'] ?? 0);

            // Salva simulação no CRM
            $stmtFila = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1");
            $stmtFila->execute([$consult_id]); $id_fila = $stmtFila->fetchColumn();
            if ($id_fila) {
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, MARGEM_DISPONIVEL, TIPO_SIMULACAO, SIMULATION_ID, STATUS_SIMULATION_ID, DATA_SIMULATION_ID, STATUS_CONFIG_ID, VALOR_LIBERADO, VALOR_PARCELA, PRAZO_SIMULACAO) VALUES (?,?,?,?,?,'PADRÃO IA (FOLLOWUP)',?,?,NOW(),'MARGEM OK',?,?,?)")
                    ->execute([$id_fila, $cpf, $config_id, $tabela_padrao, $margem,
                               $sim['id_simulation'] ?? $sim['id'] ?? 'FU', 'SIMULACAO OK', $vl, $vp, $prazo]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8='OK-SIMULACAO', ULTIMA_ATUALIZACAO=NOW() WHERE ID=?")
                    ->execute([$id_fila]);
            }

            // Monta mensagem para o cliente via GPTMaker
            $vl_fmt = 'R$ ' . number_format($vl, 2, ',', '.');
            $vp_fmt = 'R$ ' . number_format($vp, 2, ',', '.');
            $msg_cliente = "Simulação aprovada! 🎉 Conseguimos liberar o valor de {$vl_fmt} em {$prazo} parcelas de {$vp_fmt}. Esse valor serve para você?";

            $gptRes = enviarGPTMaker($gpt_agent, $gpt_token, $telefone, $msg_cliente);
            logFU("CPF {$cpf} | GPTMaker HTTP: " . ($gptRes['http'] ?? 'ERR') . " | " . substr($gptRes['body'] ?? '', 0, 100));

            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_FOLLOWUP SET STATUS='PROCESSADO', OBSERVACAO=? WHERE ID=?")
                ->execute(["Simulação enviada: {$vl_fmt} / {$prazo}x {$vp_fmt}", $fu['ID']]);

            // Atualiza status da sessão IA de AGUARDANDO_DATAPREV para SIMULACAO_PRONTA
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO='SIMULACAO_PRONTA', ULTIMA_ACAO=NOW()
                           WHERE CPF_CLIENTE=? AND CONSULT_ID=? AND STATUS_SESSAO='AGUARDANDO_DATAPREV'")
                ->execute([$cpf, $consult_id]);

            // Atualiza registro de consulta
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8='SIMULADO', ULTIMA_ATUALIZACAO=NOW()
                           WHERE CONSULT_ID=? AND STATUS_V8='AGUARDANDO MARGEM'")
                ->execute([$consult_id]);

            logFU("CPF {$cpf} | CONCLUIDO — simulação enviada ao cliente.");

        } elseif (in_array($status_v8, $ST_ERRO)) {
            $motivo = $jsonV8['description'] ?? $jsonV8['status_description'] ?? $status_v8;
            $msg_erro = "Infelizmente, o sistema não conseguiu processar sua consulta agora. Motivo: {$motivo}. Gostaria de tentar novamente?";
            enviarGPTMaker($gpt_agent, $gpt_token, $telefone, $msg_erro);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_FOLLOWUP SET STATUS='ERRO', OBSERVACAO=? WHERE ID=?")->execute([$motivo, $fu['ID']]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO='ERRO_MARGEM', ULTIMA_ACAO=NOW() WHERE CPF_CLIENTE=? AND CONSULT_ID=?")->execute([$cpf, $consult_id]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8='ERRO-MARGEM', MENSAGEM_ERRO=?, ULTIMA_ATUALIZACAO=NOW() WHERE CONSULT_ID=?")->execute([$motivo, $consult_id]);
            logFU("CPF {$cpf} | ERRO V8: {$motivo}");

        } elseif (in_array($status_v8, $ST_WAIT)) {
            // V8 ainda processando — aguarda próxima tentativa
            if ($tentativas >= 10) {
                $msg_exp = "Poxa, o sistema do banco está demorando mais que o normal. Pode me mandar uma mensagem em uns 5 minutinhos que verifico novamente pra você!";
                enviarGPTMaker($gpt_agent, $gpt_token, $telefone, $msg_exp);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IA_FOLLOWUP SET STATUS='EXPIRADO', OBSERVACAO='Máximo de tentativas atingido (V8: {$status_v8})' WHERE ID=?")->execute([$fu['ID']]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO='TIMEOUT_DATAPREV', ULTIMA_ACAO=NOW() WHERE CPF_CLIENTE=? AND CONSULT_ID=?")->execute([$cpf, $consult_id]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8='TIMEOUT_DATAPREV', ULTIMA_ATUALIZACAO=NOW() WHERE CONSULT_ID=?")->execute([$consult_id]);
                logFU("CPF {$cpf} | EXPIRADO após 10 tentativas. Status V8: {$status_v8}");
            } else {
                logFU("CPF {$cpf} | V8 ainda processando ({$status_v8}). Próxima tentativa em ~90s.");
            }
        } else {
            // Status desconhecido — trata como ainda processando
            logFU("CPF {$cpf} | Status V8 desconhecido: {$status_v8}. Aguardando.");
        }

    } catch (Exception $e) {
        logFU("CPF {$cpf} | EXCEPTION: " . $e->getMessage());
        if ($tentativas >= 10) {
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_FOLLOWUP SET STATUS='ERRO', OBSERVACAO=? WHERE ID=?")
                ->execute([$e->getMessage(), $fu['ID']]);
        }
    }
}

logFU("Worker finalizado.");
