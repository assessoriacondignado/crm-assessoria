<?php
// Arquivo: api_ia_v8.php
// CÉREBRO DA INTEGRAÇÃO IA <> V8 E FATOR CONFERI
// Módulos: Consulta Completa, Simulação Personalizada, Digitação, Status, PIX e Cancelamento
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(800); 
ignore_user_abort(true); 
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') { http_response_code(200); exit; }

// =========================================================================
// SISTEMA DE LOGS IA E FUNÇÕES AUXILIARES
// =========================================================================
function registrarLogIA($cpf, $acao, $dados) {
    $dir = __DIR__ . '/logs_ia';
    if (!is_dir($dir)) { mkdir($dir, 0777, true); }
    $cpfLimpo = empty($cpf) ? 'SEM_CPF' : preg_replace('/\D/', '', $cpf);
    $dataStr = date('d-m-Y');
    $horaStr = date('H:i:s');
    $nomeArquivo = $dir . "/log_cpf_{$cpfLimpo}_{$dataStr}_IA.txt";
    
    $msg = is_array($dados) || is_object($dados) ? json_encode($dados, JSON_UNESCAPED_UNICODE) : $dados;
    $linha = "[{$dataStr} {$horaStr}] [{$acao}] => {$msg}\n";
    file_put_contents($nomeArquivo, $linha, FILE_APPEND);
}

function enviarResposta($cpf, $acaoLog, $resposta) {
    http_response_code(200); 
    $json = json_encode($resposta, JSON_UNESCAPED_UNICODE);
    registrarLogIA($cpf, "RESPOSTA_ENVIADA - {$acaoLog}", $json);
    ob_end_clean();
    echo $json;
    exit;
}

function normalizarStringIA($str) {
    $str = mb_strtolower(trim($str), 'UTF-8');
    $map = array('á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c');
    return strtr($str, $map);
}

function enviarNotificacaoWhatsApp($pdo, $cpf_dono, $mensagem) {
    try {
        $stmtEmp = $pdo->prepare("SELECT u.id_empresa, e.GRUPO_WHATS FROM CLIENTE_USUARIO u LEFT JOIN CLIENTE_EMPRESAS e ON e.ID = u.id_empresa WHERE u.CPF = ? LIMIT 1");
        $stmtEmp->execute([$cpf_dono]);
        $rowEmp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        $id_empresa = $rowEmp['id_empresa'] ?? null;
        $grupo_whats = preg_replace('/\D/', '', $rowEmp['GRUPO_WHATS'] ?? '');

        if (empty($grupo_whats) || empty($id_empresa)) return;

        $stmtNum = $pdo->prepare("
            SELECT n.PHONE_NUMBER_ID, bm.PERMANENT_TOKEN
            FROM WHATSAPP_OFICIAL_NUMEROS n
            INNER JOIN WHATSAPP_OFICIAL_CONTAS c ON c.ID = n.CONTA_ID
            INNER JOIN WHATSAPP_OFICIAL_BM bm ON c.bm_id = bm.ID
            WHERE bm.id_empresa = ?
            LIMIT 1
        ");
        $stmtNum->execute([$id_empresa]);
        $rowNum = $stmtNum->fetch(PDO::FETCH_ASSOC);

        if (empty($rowNum['PHONE_NUMBER_ID']) || empty($rowNum['PERMANENT_TOKEN'])) return;

        $phone_id = $rowNum['PHONE_NUMBER_ID'];
        $token    = $rowNum['PERMANENT_TOKEN'];

        $payload = [
            "messaging_product" => "whatsapp",
            "to"                => $grupo_whats,
            "type"              => "text",
            "text"              => ["body" => $mensagem]
        ];

        $ch = curl_init("https://graph.facebook.com/v19.0/{$phone_id}/messages");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) { }
}

function extrairErroV8($jsonC) {
    if (isset($jsonC['reasons']) && is_array($jsonC['reasons']) && count($jsonC['reasons']) > 0) {
        $motivos = [];
        foreach ($jsonC['reasons'] as $r) { if (isset($r['description'])) $motivos[] = $r['description']; }
        if (count($motivos) > 0) return implode(" | ", $motivos);
    }
    if (isset($jsonC['description']) && !empty($jsonC['description'])) { return $jsonC['description']; }
    if (isset($jsonC['status_description']) && !empty($jsonC['status_description'])) { return $jsonC['status_description']; }
    if (isset($jsonC['detail']) && !empty($jsonC['detail'])) { return $jsonC['detail']; }
    return "Rejeitado pela Dataprev/V8 (Motivo não especificado pela API)";
}

// =========================================================================
// RECEBE A REQUISIÇÃO E VALIDA O JSON
// =========================================================================
$inputRaw = file_get_contents("php://input");
$req = json_decode($inputRaw, true);

if ($req === null && json_last_error() !== JSON_ERROR_NONE) {
    enviarResposta('SEM_CPF', 'FALHA_JSON', [
        'success' => false, 
        'error' => 'JSON_INVALIDO: O formato de envio do Webhook quebrou.'
    ]);
}

$acao = $req['acao'] ?? '';
$cpf = preg_replace('/\D/', '', $req['cpf'] ?? '');

registrarLogIA($cpf, "REQUISICAO_RECEBIDA - {$acao}", $inputRaw);

if (empty($acao) || strlen($cpf) !== 11) {
    enviarResposta($cpf, 'FALHA_VALIDACAO', ['success' => false, 'error' => 'CPF FORA DO PADRÃO OU AÇÃO VAZIA (Deve conter 11 dígitos)']);
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->exec("SET NAMES utf8mb4");

$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (empty($authHeader) || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    enviarResposta($cpf, 'FALHA_AUTENTICACAO', ['success' => false, 'error' => 'Acesso Negado: Token Bearer não fornecido no Header.']);
}

$tokenIA = $matches[1];

$stmtCred = $pdo->prepare("SELECT i.*, c.SALDO as SALDO_CLIENTE, c.CUSTO_CONSULTA as CUSTO_CLIENTE, v.CUSTO_V8, v.ID as CHAVE_REAL_V8, v.USERNAME_API, v.PASSWORD_API, v.CLIENT_ID, v.AUDIENCE, v.TABELA_PADRAO, v.PRAZO_PADRAO, u.ID as ID_USUARIO_DONO 
                           FROM INTEGRACAO_V8_IA_CREDENCIAIS i 
                           JOIN CLIENTE_CADASTRO c ON i.CPF_DONO = c.CPF
                           JOIN INTEGRACAO_V8_CHAVE_ACESSO v ON i.CHAVE_V8_ID = v.ID
                           LEFT JOIN CLIENTE_USUARIO u ON c.CPF = u.CPF
                           WHERE i.TOKEN_IA = ? AND i.STATUS = 'ATIVO'");
$stmtCred->execute([$tokenIA]);
$credencialIA = $stmtCred->fetch(PDO::FETCH_ASSOC);

if (!$credencialIA) {
    enviarResposta($cpf, 'FALHA_TOKEN', ['success' => false, 'error' => 'Acesso Negado: Token IA inválido ou inativo.']);
}

function gerarTokenV8_Local($cred) {
    $payload = http_build_query(['grant_type'=>'password', 'username'=>$cred['USERNAME_API'], 'password'=>$cred['PASSWORD_API'], 'audience'=>$cred['AUDIENCE'], 'client_id'=>$cred['CLIENT_ID'], 'scope'=>'offline_access']);
    $ch = curl_init("https://auth.v8sistema.com/oauth/token"); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $res = curl_exec($ch); curl_close($ch); $json = json_decode($res, true);
    if(isset($json['access_token'])) return $json['access_token'];
    throw new Exception("Falha ao autenticar na API V8 Oficial.");
}

function buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone = '11900000000') {
    $stmt = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_IA_SESSAO WHERE CPF_CLIENTE = ? AND DATE(DATA_INICIO) = CURDATE() ORDER BY ID DESC LIMIT 1");
    $stmt->execute([$cpf]);
    $id = $stmt->fetchColumn();
    if ($id) return $id;
    $pdo->prepare("INSERT INTO INTEGRACAO_V8_IA_SESSAO (TOKEN_IA_USADO, TELEFONE_CLIENTE, CPF_CLIENTE, STATUS_SESSAO) VALUES (?, ?, ?, 'RETOMANDO_FUNIL')")->execute([$tokenIA, $telefone, $cpf]);
    return $pdo->lastInsertId();
}

function buscarOuAtualizarCadastro($cpf, $pdo, $credencialIA) {
    $stmt = $pdo->prepare("SELECT nome, nascimento, sexo, nome_mae, rg FROM dados_cadastrais WHERE cpf = ?"); $stmt->execute([$cpf]); $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cliente) return $cliente;

    $stmtFc = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = ?"); $stmtFc->execute([$credencialIA['CPF_DONO']]); $cliFc = $stmtFc->fetch(PDO::FETCH_ASSOC);
    $custo_fc = (float)($cliFc['CUSTO_CONSULTA'] ?? 0); $saldo_fc = (float)($cliFc['SALDO'] ?? 0);
    if ($saldo_fc < $custo_fc && $custo_fc > 0) throw new Exception('Saldo insuficiente para buscar na Fator Conferi.');

    $stmtCfgFC = $pdo->query("SELECT TOKEN_FATOR FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); $tkFC = trim($stmtCfgFC->fetchColumn());
    if (empty($tkFC)) throw new Exception('Token Fator Conferi ausente.');

    $chFC = curl_init("https://fator.confere.link/api/?acao=CONS_CPF&TK=" . $tkFC . "&DADO=" . $cpf); 
    curl_setopt($chFC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chFC, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($chFC, CURLOPT_TIMEOUT, 20);
    $resFC = curl_exec($chFC); $httpFC = curl_getinfo($chFC, CURLINFO_HTTP_CODE); curl_close($chFC);
    
    if ($httpFC >= 200 && $httpFC < 300 && strpos($resFC, '<CADASTRAIS>') !== false) {
        $xmlString = mb_convert_encoding($resFC, 'UTF-8', 'ISO-8859-1'); 
        if(strpos($xmlString, '<') !== false) { $xmlString = substr($xmlString, strpos($xmlString, '<')); } 
        libxml_use_internal_errors(true); $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
        
        if ($xmlObject && isset($xmlObject->CADASTRAIS->NOME)) {
            $nome = trim((string)$xmlObject->CADASTRAIS->NOME); $nasc_str = trim((string)$xmlObject->CADASTRAIS->NASCTO);
            $sexo = trim((string)$xmlObject->CADASTRAIS->SEXO); $mae = trim((string)$xmlObject->CADASTRAIS->NOME_MAE);
            $rg = trim((string)$xmlObject->CADASTRAIS->RG);
            $nascimento = null; if(strpos($nasc_str, '/') !== false) { $p = explode('/', $nasc_str); if(count($p)==3) $nascimento = "{$p[2]}-{$p[1]}-{$p[0]}"; }
            $sexo_fmt = (strtoupper(substr($sexo, 0, 1)) === 'M') ? 'M' : 'F';
            $pdo->prepare("INSERT IGNORE INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg) VALUES (?, ?, ?, ?, ?, ?)")->execute([$cpf, $nome, $sexo_fmt, $nascimento, $mae, $rg]);
            
            if ($custo_fc > 0) {
                $novo_saldo = $saldo_fc - $custo_fc;
                $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$novo_saldo, $credencialIA['CPF_DONO']]);
                $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL, DATA_HORA) VALUES (?, 'DEBITO', ?, ?, ?, ?, NOW())")->execute([$credencialIA['CPF_DONO'], "ATENDIMENTO IA - CPF {$cpf}", $custo_fc, $saldo_fc, $novo_saldo]);
            }
            return ['cpf'=>$cpf, 'nome'=>$nome, 'sexo'=>$sexo_fmt, 'nascimento'=>$nascimento, 'nome_mae'=>$mae, 'rg'=>$rg];
        }
    }
    throw new Exception("Cliente não localizado na base nacional.");
}

function processarConsentimentoDireto($cpf, $telefone, $cliente, $pdo, $credencialIA) {
    $custo = (float)$credencialIA['CUSTO_CLIENTE'];
    $saldo = (float)$credencialIA['SALDO_CLIENTE'];
    if ($saldo < $custo && $custo > 0) throw new Exception("Saldo insuficiente na conta do CRM para V8.");

    $tokenV8 = gerarTokenV8_Local($credencialIA);
    $sexo_api = ($cliente['sexo'] === 'M') ? 'male' : 'female';
    $payloadV8 = json_encode(['borrowerDocumentNumber' => $cpf, 'gender' => $sexo_api, 'birthDate' => $cliente['nascimento'] ?: '1980-01-01', 'signerName' => $cliente['nome'], 'signerEmail' => 'cliente@gmail.com', 'signerPhone' => ['countryCode' => '55', 'areaCode' => substr($telefone, 0, 2), 'phoneNumber' => substr($telefone, 2)], 'provider' => 'QI']);
    
    $ch = curl_init("https://bff.v8sistema.com/private-consignment/consult");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadV8); curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $json = json_decode($res, true);

    $consult_id = $json['id'] ?? null;
    if (!$consult_id) {
        $erroDetalhado = extrairErroV8($json);
        throw new Exception("Erro V8 (Consentimento): " . $erroDetalhado);
    }

    $chA = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id}/authorize"); curl_setopt($chA, CURLOPT_RETURNTRANSFER, true); curl_setopt($chA, CURLOPT_POST, true); curl_setopt($chA, CURLOPT_POSTFIELDS, json_encode([])); curl_setopt($chA, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]);
    curl_exec($chA); curl_close($chA);

    if ($custo > 0) {
        $saldo_atualizado = $saldo - $custo;
        $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atualizado, $credencialIA['CPF_DONO']]);
        $pdo->prepare("INSERT INTO INTEGRACAO_V8_EXTRATO_CLIENTE (CHAVE_ID, TIPO_MOVIMENTO, TIPO_CUSTO, VALOR, CUSTO_V8, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, 'DEBITO', ?, ?, ?, ?, ?)")->execute([$credencialIA['CHAVE_REAL_V8'], "CONSENTIMENTO IA - CPF {$cpf}", $custo, (float)$credencialIA['CUSTO_V8'], $saldo, $saldo_atualizado]);
    }

    $stmtEmpIA = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtEmpIA->execute([$credencialIA['CPF_DONO']]);
    $empresa_id_ia = $stmtEmpIA->fetchColumn() ?: null;
    $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTROCONSULTA (CONSULT_ID, CPF_CONSULTADO, CHAVE_ID, USUARIO_ID, CPF_USUARIO, STATUS_V8, NOME_COMPLETO, DATA_NASCIMENTO, TELEFONE, FONTE_CONSULT_ID, EMPRESA_ID) VALUES (?, ?, ?, ?, ?, 'AGUARDANDO MARGEM', ?, ?, ?, 'IA BOT', ?)")->execute([$consult_id, $cpf, $credencialIA['CHAVE_REAL_V8'], $credencialIA['ID_USUARIO_DONO'], $credencialIA['CPF_DONO'], $cliente['nome'], $cliente['nascimento'], $telefone, $empresa_id_ia]);

    return $consult_id;
}

function identificarTabelaUsuario($consult_id, $credencialIA, $tokenV8) {
    $tabela_padrao = trim($credencialIA['TABELA_PADRAO'] ?: 'CLT Acelera');
    $chCfg = curl_init("https://bff.v8sistema.com/private-consignment/simulation/configs?consult_id={$consult_id}"); 
    curl_setopt($chCfg, CURLOPT_RETURNTRANSFER, true); curl_setopt($chCfg, CURLOPT_HTTPGET, true); curl_setopt($chCfg, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]);
    $resCfg = curl_exec($chCfg); curl_close($chCfg); $jsonCfg = json_decode($resCfg, true);
    
    // CORREÇÃO 1: Trata o array de configs da mesma forma que o script manual
    $config_id = null; $lista_configs = $jsonCfg['configs'] ?? (isset($jsonCfg[0]['id']) ? $jsonCfg : []); $nome_tb_final = $tabela_padrao;
    
    if (is_array($lista_configs) && count($lista_configs) > 0) { 
        $busca = normalizarStringIA($tabela_padrao);
        foreach ($lista_configs as $cfg) { 
            $nomeAPI = normalizarStringIA($cfg['name'] ?? $cfg['slug'] ?? '');
            if ($nomeAPI === $busca) { $config_id = $cfg['id']; $nome_tb_final = $cfg['name'] ?? $cfg['slug']; break; } 
        } 
        if(!$config_id) {
            foreach ($lista_configs as $cfg) { 
                $nomeAPI = normalizarStringIA($cfg['name'] ?? $cfg['slug'] ?? '');
                if (strpos($nomeAPI, $busca) !== false || strpos($busca, $nomeAPI) !== false) { $config_id = $cfg['id']; $nome_tb_final = $cfg['name'] ?? $cfg['slug']; break; } 
            } 
        }
        if(!$config_id) { $config_id = $lista_configs[0]['id']; $nome_tb_final = $lista_configs[0]['name'] ?? $lista_configs[0]['slug'] ?? 'Tabela Padrão'; }
    }
    if(!$config_id) $config_id = $consult_id;
    return ['config_id' => $config_id, 'nome_tb_final' => $nome_tb_final];
}

function processarSimulacaoPadrao($consult_id, $cpf, $margem, $pdo, $credencialIA, $tokenV8) {
    $prazo_padrao = (int)($credencialIA['PRAZO_PADRAO'] ?: 84);
    $tab = identificarTabelaUsuario($consult_id, $credencialIA, $tokenV8);

    $payload_sim = [ 'consult_id' => $consult_id, 'config_id' => $tab['config_id'], 'number_of_installments' => $prazo_padrao ];
    if ($margem > 0) $payload_sim['installment_face_value'] = $margem;

    $chS = curl_init("https://bff.v8sistema.com/private-consignment/simulation"); curl_setopt($chS, CURLOPT_RETURNTRANSFER, true); curl_setopt($chS, CURLOPT_POST, true); curl_setopt($chS, CURLOPT_POSTFIELDS, json_encode($payload_sim)); curl_setopt($chS, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]);
    $resS = curl_exec($chS); $httpS = curl_getinfo($chS, CURLINFO_HTTP_CODE); curl_close($chS); $jsonS = json_decode($resS, true);

    // CORREÇÃO 2: Identifica os valores retornados ou define valores zerados em caso de erro, SALVANDO A MARGEM de qualquer forma.
    $sim_obj = isset($jsonS[0]) ? $jsonS[0] : $jsonS;
    $sim_id = $sim_obj['id_simulation'] ?? $sim_obj['id'] ?? null;
    $valor_lib = $sim_obj['disbursement_amount'] ?? $sim_obj['disbursed_amount'] ?? 0;
    $valor_parc = $sim_obj['installment_value'] ?? $sim_obj['installment_face_value'] ?? $margem;
    $status_simulacao = 'SIMULACAO OK';

    if ($httpS >= 400 || empty($jsonS)) {
        $sim_id = 'ERRO-TABELA';
        $status_simulacao = 'ERRO V8';
        $valor_lib = 0;
    }

    $stmtGetID = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1"); $stmtGetID->execute([$consult_id]);
    $id_fila = $stmtGetID->fetchColumn();

    if ($id_fila) {
        $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, MARGEM_DISPONIVEL, TIPO_SIMULACAO, SIMULATION_ID, STATUS_SIMULATION_ID, DATA_SIMULATION_ID, STATUS_CONFIG_ID, VALOR_LIBERADO, VALOR_PARCELA, PRAZO_SIMULACAO) VALUES (?, ?, ?, ?, ?, 'PADRÃO IA', ?, ?, NOW(), 'MARGEM OK', ?, ?, ?)")->execute([$id_fila, $cpf, $tab['config_id'], $tab['nome_tb_final'], $margem, $sim_id, $status_simulacao, (float)$valor_lib, (float)$valor_parc, $prazo_padrao]);
        $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'OK-SIMULACAO', ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$id_fila]);
    }
    
    // AGORA SIM, se teve erro na V8, avisamos a IA interrompendo
    if ($httpS >= 400 || empty($jsonS)) {
        $erroDetalhado = extrairErroV8($jsonS);
        throw new Exception("Erro Simulação: " . $erroDetalhado);
    }

    if (!empty($credencialIA['NOTIF_SIMULACAO'])) {
        $stmtNome = $pdo->prepare("SELECT nome FROM dados_cadastrais WHERE cpf = ? LIMIT 1");
        $stmtNome->execute([$cpf]);
        $nomeCliente = $stmtNome->fetchColumn() ?: $cpf;
        $pdo->prepare("INSERT INTO INTEGRACAO_V8_IA_NOTIFICACOES (CREDENCIAL_ID, CPF_DONO, TIPO, NOME_CLIENTE, CPF_CLIENTE, VALOR, PRAZO, PARCELA, NOME_ROBO) VALUES (?, ?, 'SIMULACAO', ?, ?, ?, ?, ?, ?)")
            ->execute([$credencialIA['ID'], $credencialIA['CPF_DONO'], $nomeCliente, $cpf, (float)$valor_lib, (int)$prazo_padrao, (float)$valor_parc, $credencialIA['NOME_ROBO']]);
        $msgSimulacao = "🤖 *{$credencialIA['NOME_ROBO']}* — Simulação Aprovada\n👤 Cliente: {$nomeCliente} | CPF: " . substr($cpf,0,3) . ".***.***-" . substr($cpf,-2) . "\n💰 Liberado: R$ " . number_format((float)$valor_lib, 2, ',', '.') . "\n📅 Prazo: {$prazo_padrao}x | Parcela: R$ " . number_format((float)$valor_parc, 2, ',', '.') . "\n🕐 " . date('d/m/Y H:i');
        enviarNotificacaoWhatsApp($pdo, $credencialIA['CPF_DONO'], $msgSimulacao);
    }

    return ['simulation_id' => $sim_id, 'tabela' => $tab['nome_tb_final'], 'valor_liberado' => (float)$valor_lib, 'valor_parcela' => (float)$valor_parc, 'prazo' => $prazo_padrao];
}
// =========================================================================
// ROTEAMENTO DAS AÇÕES PRINCIPAIS
// =========================================================================
$sessao_id = 0;
$consult_id = '';
$ultimo_consult_id = ''; // Declarando globalmente para o catch block

try {
    switch ($acao) {
        
        case 'consulta_completa':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            $tokenV8  = gerarTokenV8_Local($credencialIA);

            $ST_OK   = ['SUCCESS','COMPLETED','WAITING_CREDIT_ANALYSIS','APPROVED','PRE_APPROVED','SIMULATED','READY','AVAILABLE','MARGIN_AVAILABLE','AUTHORIZED','DONE','FINISHED'];
            $ST_ERRO = ['ERROR','REJECTED','DENIED','CANCELED','EXPIRED','FAILED'];

            $stmtAguard = $pdo->prepare("
                SELECT rc.CONSULT_ID, s.ID as SESSAO_ID, s.DATA_INICIO
                FROM INTEGRACAO_V8_REGISTROCONSULTA rc
                JOIN INTEGRACAO_V8_IA_SESSAO s ON s.CONSULT_ID = rc.CONSULT_ID
                WHERE rc.CPF_CONSULTADO = ? AND rc.STATUS_V8 = 'AGUARDANDO MARGEM' AND s.STATUS_SESSAO = 'AGUARDANDO_DATAPREV'
                ORDER BY s.DATA_INICIO DESC LIMIT 1
            ");
            $stmtAguard->execute([$cpf]);
            $consultaAguardando = $stmtAguard->fetch(PDO::FETCH_ASSOC);

            if ($consultaAguardando) {
                $ultimo_consult_id = $consultaAguardando['CONSULT_ID'];
                $sessao_id         = (int)$consultaAguardando['SESSAO_ID'];
                $elapsed           = time() - strtotime($consultaAguardando['DATA_INICIO']);

                if ($elapsed >= 14400) {
                    $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'TIMEOUT_DATAPREV' WHERE ID = ?")->execute([$sessao_id]);
                    $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'TIMEOUT_DATAPREV', ULTIMA_ATUALIZACAO = NOW() WHERE CONSULT_ID = ?")->execute([$ultimo_consult_id]);
                } else {
                    $chC = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$ultimo_consult_id}"); curl_setopt($chC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chC, CURLOPT_HTTPGET, true); curl_setopt($chC, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]); $resC = curl_exec($chC); curl_close($chC); $jsonC = json_decode($resC, true);
                    $st = strtoupper($jsonC['status'] ?? '');

                    if (in_array($st, $ST_OK)) {
                        $margem = $jsonC['availableMargin'] ?? $jsonC['marginBaseValue'] ?? $jsonC['availableMarginValue'] ?? $jsonC['maxAmount'] ?? 0;
                        $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'MARGEM_LIBERADA' WHERE ID = ?")->execute([$sessao_id]);
                        $simDados = processarSimulacaoPadrao($ultimo_consult_id, $cpf, (float)$margem, $pdo, $credencialIA, $tokenV8);
                        $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'SIMULACAO_PRONTA' WHERE ID = ?")->execute([$sessao_id]);
                        enviarResposta($cpf, $acao, ['success' => true, 'status' => 'CONCLUIDO', 'margem_disponivel' => (float)$margem, 'simulacao_padrao' => $simDados]);
                    } elseif (in_array($st, $ST_ERRO)) {
                        $erroMsg_V8 = extrairErroV8($jsonC);
                        $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'ERRO_MARGEM' WHERE ID = ?")->execute([$sessao_id]);
                        $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO-MARGEM', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE CONSULT_ID = ?")->execute([$erroMsg_V8, $ultimo_consult_id]);
                        enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => $erroMsg_V8]);
                    } else {
                        enviarResposta($cpf, $acao, ['success' => false, 'status'  => 'AGUARDANDO_DATAPREV', 'msg' => "Dataprev ainda processando."]);
                    }
                }
            }

            $pdo->prepare("INSERT INTO INTEGRACAO_V8_IA_SESSAO (TOKEN_IA_USADO, TELEFONE_CLIENTE, CPF_CLIENTE, STATUS_SESSAO) VALUES (?, ?, ?, 'BUSCANDO_V8')")->execute([$tokenIA, $telefone, $cpf]);
            $sessao_id = $pdo->lastInsertId();

            $cliente           = buscarOuAtualizarCadastro($cpf, $pdo, $credencialIA);
            $ultimo_consult_id = processarConsentimentoDireto($cpf, $telefone, $cliente, $pdo, $credencialIA);

            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET CONSULT_ID = ?, STATUS_SESSAO = 'AGUARDANDO_DATAPREV' WHERE ID = ?")->execute([$ultimo_consult_id, $sessao_id]);

            $conseguiu_margem = false; $margem = 0;
            for ($i = 0; $i < 3; $i++) {
                sleep(5); 
                $chC = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$ultimo_consult_id}"); curl_setopt($chC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chC, CURLOPT_HTTPGET, true); curl_setopt($chC, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]); $resC = curl_exec($chC); curl_close($chC); $jsonC = json_decode($resC, true);
                $st = strtoupper($jsonC['status'] ?? '');
                if (in_array($st, $ST_OK)) { $margem = $jsonC['availableMargin'] ?? $jsonC['marginBaseValue'] ?? $jsonC['availableMarginValue'] ?? $jsonC['maxAmount'] ?? 0; $conseguiu_margem = true; break; }
                if (in_array($st, $ST_ERRO)) {
                    $erroMsg_V8 = extrairErroV8($jsonC);
                    $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'ERRO_MARGEM' WHERE ID = ?")->execute([$sessao_id]);
                    $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO-MARGEM', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE CONSULT_ID = ?")->execute([$erroMsg_V8, $ultimo_consult_id]);
                    enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => $erroMsg_V8]);
                }
            }

            if ($conseguiu_margem) {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'MARGEM_LIBERADA' WHERE ID = ?")->execute([$sessao_id]);
                $simDados = processarSimulacaoPadrao($ultimo_consult_id, $cpf, (float)$margem, $pdo, $credencialIA, $tokenV8);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'SIMULACAO_PRONTA' WHERE ID = ?")->execute([$sessao_id]);
                enviarResposta($cpf, $acao, ['success' => true, 'status' => 'CONCLUIDO', 'margem_disponivel' => (float)$margem, 'simulacao_padrao' => $simDados]);
            } else {
                enviarResposta($cpf, $acao, ['success' => false, 'status'  => 'AGUARDANDO_DATAPREV', 'msg' => 'Processando na fila.']);
            }
            break;

        case 'simular_proposta':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            // ✅ Atrelar a sessão correta no CRM
            $sessao_id = buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'RECALCULANDO_PROPOSTA' WHERE ID = ?")->execute([$sessao_id]);

            $prazo_str = (string)($req['prazo'] ?? ''); if (strpos($prazo_str, '{{') !== false) $prazo_str = ''; $prazo = (int)preg_replace('/\D/', '', $prazo_str); if ($prazo <= 0) $prazo = (int)($credencialIA['PRAZO_PADRAO'] ?: 84);
            $v_parc_str = str_replace(',', '.', (string)($req['valor_parcela'] ?? '')); if (strpos($v_parc_str, '{{') !== false) $v_parc_str = '0'; $valor_parcela = (float)preg_replace('/[^0-9.]/', '', $v_parc_str);
            $v_liq_str = str_replace(',', '.', (string)($req['valor_liquido'] ?? '')); if (strpos($v_liq_str, '{{') !== false) $v_liq_str = '0'; $valor_liquido = (float)preg_replace('/[^0-9.]/', '', $v_liq_str);

            $stmt = $pdo->prepare("SELECT CONSULT_ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CPF_CONSULTADO = ? ORDER BY ID DESC LIMIT 1"); $stmt->execute([$cpf]); $consult_id = $stmt->fetchColumn();
            if(!$consult_id) throw new Exception("Nenhuma consulta de consentimento prévia encontrada para este CPF.");

            $tokenV8 = gerarTokenV8_Local($credencialIA);
            $tab = identificarTabelaUsuario($consult_id, $credencialIA, $tokenV8);

            $payload_sim = [ 'consult_id' => $consult_id, 'config_id' => $tab['config_id'], 'number_of_installments' => $prazo ];
            if ($valor_parcela > 0) { $payload_sim['installment_face_value'] = $valor_parcela; } elseif ($valor_liquido > 0) { $payload_sim['disbursed_amount'] = $valor_liquido; } else {
                $stmtM = $pdo->prepare("SELECT MARGEM_DISPONIVEL FROM INTEGRACAO_V8_REGISTRO_SIMULACAO WHERE ID_FILA = (SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1) ORDER BY ID DESC LIMIT 1"); $stmtM->execute([$consult_id]); $margem_total = (float)$stmtM->fetchColumn();
                if($margem_total > 0) $payload_sim['installment_face_value'] = $margem_total;
            }

            // CORREÇÃO 3: Trava de segurança para impedir simulação sem valor e erro feio da Dataprev
            if (empty($payload_sim['installment_face_value']) && empty($payload_sim['disbursed_amount'])) {
                throw new Exception("Nenhum valor de parcela (margem) ou valor líquido foi informado ou localizado no banco para realizar a simulação.");
            }

            $chS = curl_init("https://bff.v8sistema.com/private-consignment/simulation"); curl_setopt($chS, CURLOPT_RETURNTRANSFER, true); curl_setopt($chS, CURLOPT_POST, true); curl_setopt($chS, CURLOPT_POSTFIELDS, json_encode($payload_sim)); curl_setopt($chS, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]); $resS = curl_exec($chS); $httpS = curl_getinfo($chS, CURLINFO_HTTP_CODE); curl_close($chS); $jsonS = json_decode($resS, true);
            
            if ($httpS >= 400 || empty($jsonS)) { $erroDetalhado = extrairErroV8($jsonS); throw new Exception("Erro Simulação: " . $erroDetalhado); }
            $sim_obj = isset($jsonS[0]) ? $jsonS[0] : $jsonS;

            $stmtGetID = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1"); $stmtGetID->execute([$consult_id]); $id_fila = $stmtGetID->fetchColumn();
            if ($id_fila) {
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, TIPO_SIMULACAO, SIMULATION_ID, STATUS_SIMULATION_ID, DATA_SIMULATION_ID, STATUS_CONFIG_ID, VALOR_LIBERADO, VALOR_PARCELA, PRAZO_SIMULACAO) VALUES (?, ?, ?, ?, 'PERSONALIZADA IA', ?, 'SIMULACAO OK', NOW(), 'MARGEM OK', ?, ?, ?)")->execute([$id_fila, $cpf, $tab['config_id'], $tab['nome_tb_final'], ($sim_obj['id_simulation']??$sim_obj['id']), (float)($sim_obj['disbursement_amount']??$sim_obj['disbursed_amount']??0), (float)($sim_obj['installment_value']??$sim_obj['installment_face_value']??0), $prazo]);
            }
            
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'SIMULACAO_PRONTA' WHERE ID = ?")->execute([$sessao_id]);
            enviarResposta($cpf, $acao, ['success' => true, 'valor_liberado' => (float)($sim_obj['disbursement_amount']??$sim_obj['disbursed_amount']??0), 'valor_parcela' => (float)($sim_obj['installment_value']??$sim_obj['installment_face_value']??0), 'prazo' => $prazo ]);
            break;

        case 'enviar_proposta':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            $sessao_id = buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone);
            $chave_pix = trim($req['pix'] ?? '');
            
            $prazo_str = (string)($req['prazo'] ?? ''); if (strpos($prazo_str, '{{') !== false) $prazo_str = ''; $prazo = (int)preg_replace('/\D/', '', $prazo_str); if ($prazo <= 0) $prazo = (int)($credencialIA['PRAZO_PADRAO'] ?: 84);
            $v_parc_str = str_replace(',', '.', (string)($req['valor_parcela'] ?? '')); if (strpos($v_parc_str, '{{') !== false) $v_parc_str = '0'; $valor_parcela = (float)preg_replace('/[^0-9.]/', '', $v_parc_str);
            $v_liq_str = str_replace(',', '.', (string)($req['valor_liquido'] ?? '')); if (strpos($v_liq_str, '{{') !== false) $v_liq_str = '0'; $valor_liquido = (float)preg_replace('/[^0-9.]/', '', $v_liq_str);

            if (empty($chave_pix)) throw new Exception("A chave PIX é obrigatória para emissão.");
            
            $stmt = $pdo->prepare("SELECT CONSULT_ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CPF_CONSULTADO = ? ORDER BY ID DESC LIMIT 1"); $stmt->execute([$cpf]); $consult_id = $stmt->fetchColumn();
            if(!$consult_id) throw new Exception("Nenhuma consulta de consentimento prévia encontrada.");

            $tokenV8 = gerarTokenV8_Local($credencialIA);
            $tab = identificarTabelaUsuario($consult_id, $credencialIA, $tokenV8);

            $payload_sim = [ 'consult_id' => $consult_id, 'config_id' => $tab['config_id'], 'number_of_installments' => $prazo ];
            if ($valor_parcela > 0) $payload_sim['installment_face_value'] = $valor_parcela; elseif ($valor_liquido > 0) $payload_sim['disbursed_amount'] = $valor_liquido;
            else {
                $stmtM = $pdo->prepare("SELECT MARGEM_DISPONIVEL FROM INTEGRACAO_V8_REGISTRO_SIMULACAO WHERE ID_FILA = (SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1) ORDER BY ID DESC LIMIT 1"); $stmtM->execute([$consult_id]); $margem_total = (float)$stmtM->fetchColumn();
                if($margem_total > 0) $payload_sim['installment_face_value'] = $margem_total;
            }

            // CORREÇÃO 3: Trava de segurança no envio da proposta também
            if (empty($payload_sim['installment_face_value']) && empty($payload_sim['disbursed_amount'])) {
                throw new Exception("Nenhum valor de parcela (margem) ou valor líquido foi informado ou localizado no banco para formalizar a proposta.");
            }

            $chS = curl_init("https://bff.v8sistema.com/private-consignment/simulation"); curl_setopt($chS, CURLOPT_RETURNTRANSFER, true); curl_setopt($chS, CURLOPT_POST, true); curl_setopt($chS, CURLOPT_POSTFIELDS, json_encode($payload_sim)); curl_setopt($chS, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]); $resS = curl_exec($chS); curl_close($chS); $jsonS = json_decode($resS, true);
            $sim_obj = isset($jsonS[0]) ? $jsonS[0] : $jsonS;
            $sim_id = $sim_obj['id_simulation'] ?? $sim_obj['id'] ?? null;
            if(!$sim_id) throw new Exception("Falha ao preparar simulação para envio.");

            $cliente = buscarOuAtualizarCadastro($cpf, $pdo, $credencialIA);
            $nasc = !empty($cliente['nascimento']) ? $cliente['nascimento'] : '1980-01-01'; $mae = !empty($cliente['nome_mae']) ? mb_strtoupper($cliente['nome_mae'], 'UTF-8') : 'NAO INFORMADO'; $rg = !empty($cliente['rg']) ? preg_replace('/[^a-zA-Z0-9]/', '', $cliente['rg']) : '000000000'; if (empty($rg)) $rg = '000000000'; $sexo_api = (strtoupper($cliente['sexo']) === 'M') ? 'male' : 'female';

            // DETECTOR AUTOMÁTICO DE TIPO DE CHAVE PIX
            $tipo_pix = 'random_key'; // Padrão
            if (filter_var($chave_pix, FILTER_VALIDATE_EMAIL)) { 
                $tipo_pix = 'email'; 
            } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $chave_pix)) { 
                $tipo_pix = 'random_key'; 
            } else {
                $num_pix = preg_replace('/\D/', '', $chave_pix);
                if ($num_pix === $cpf) { 
                    $tipo_pix = 'cpf'; 
                    $chave_pix = $num_pix;
                } elseif (strlen($num_pix) === 10 || strlen($num_pix) === 11) { 
                    $tipo_pix = 'phone'; 
                    $chave_pix = $num_pix; // Limpa os parênteses e traços para o banco
                }
            }

            // ENDEREÇO FIXO E CHAVE PIX DINÂMICA
            $payloadProp = [
                'simulation_id' => $sim_id,
                'borrower' => [
                    'name' => $cliente['nome'], 'email' => 'cliente@gmail.com', 
                    'phone' => [ 'country_code' => '55', 'area_code' => substr($telefone, 0, 2), 'number' => substr($telefone, 2) ], 
                    'political_exposition' => false, 'birth_date' => $nasc, 'mother_name' => $mae, 'nationality' => 'BR', 'document_issuer' => 'SSP',
                    'gender' => $sexo_api, 'person_type' => 'natural', 'marital_status' => 'single', 'individual_document_number' => $cpf,
                    'document_identification_date' => '2015-01-01', 'document_identification_type' => 'rg', 'document_identification_number' => $rg,
                    'address' => [ 
                        'city' => 'Florianópolis', 
                        'state' => 'SC', 
                        'number' => '900', 
                        'street' => 'Servidão Unidos', 
                        'complement' => 'casa', 
                        'postal_code' => '88049335', 
                        'neighborhood' => 'Tapera' 
                    ],
                    'bank' => [ 'transfer_method' => 'pix', 'pix_key' => $chave_pix, 'pix_key_type' => $tipo_pix ]
                ]
            ];

            $chP = curl_init("https://bff.v8sistema.com/private-consignment/operation"); curl_setopt($chP, CURLOPT_RETURNTRANSFER, true); curl_setopt($chP, CURLOPT_POST, true); curl_setopt($chP, CURLOPT_POSTFIELDS, json_encode($payloadProp)); curl_setopt($chP, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]); $resP = curl_exec($chP); $httpP = curl_getinfo($chP, CURLINFO_HTTP_CODE); curl_close($chP); $jsonP = json_decode($resP, true);

            if ($httpP >= 400 || empty($jsonP)) { $erroDetalhado = extrairErroV8($jsonP); throw new Exception("Erro V8 (Proposta): " . $erroDetalhado); }
            $proposal_id = $jsonP['id'] ?? $jsonP['proposal_id'] ?? 'PROPOSTA_IA'; $url_formalizacao = $jsonP['formalization_url'] ?? '';

            $stmtGetID = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CONSULT_ID = ? LIMIT 1"); $stmtGetID->execute([$consult_id]); $id_fila = $stmtGetID->fetchColumn();
            if($id_fila) {
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = ?, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute(['PROPOSTA: ' . $proposal_id, $id_fila]);
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_PROPOSTA (CPF_USUARIO, CPF_CLIENTE, NOME_CLIENTE, NUMERO_PROPOSTA, PRAZO, PARCELA, VALOR_LIBERADO, STATUS_PROPOSTA_V8, LINK_PROPOSTA, DATA_STATUS, DATA_DIGITACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")->execute([$credencialIA['CPF_DONO'], $cpf, $cliente['nome'], $proposal_id, $prazo, (float)($sim_obj['installment_value']??0), (float)($sim_obj['disbursement_amount']??0), 'AGUARDANDO', $url_formalizacao]);
            }
            if (!empty($credencialIA['NOTIF_PROPOSTA'])) {
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_IA_NOTIFICACOES (CREDENCIAL_ID, CPF_DONO, TIPO, NOME_CLIENTE, CPF_CLIENTE, VALOR, PRAZO, PARCELA, NUMERO_PROPOSTA, NOME_ROBO) VALUES (?, ?, 'PROPOSTA', ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$credencialIA['ID'], $credencialIA['CPF_DONO'], $cliente['nome'], $cpf, (float)($sim_obj['disbursement_amount']??0), $prazo, (float)($sim_obj['installment_value']??0), $proposal_id, $credencialIA['NOME_ROBO']]);
                $msgProposta = "🤖 *{$credencialIA['NOME_ROBO']}* — Proposta Digitada\n👤 Cliente: ".($cliente['nome'] ?? $cpf)." | CPF: " . substr($cpf,0,3) . ".***.***-" . substr($cpf,-2) . "\n📋 Proposta: {$proposal_id}\n💰 Liberado: R$ " . number_format((float)($sim_obj['disbursement_amount']??0), 2, ',', '.') . "\n📅 Prazo: {$prazo}x | Parcela: R$ " . number_format((float)($sim_obj['installment_value']??0), 2, ',', '.') . "\n🕐 " . date('d/m/Y H:i');
                enviarNotificacaoWhatsApp($pdo, $credencialIA['CPF_DONO'], $msgProposta);
            }
            
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'CONCLUIDO' WHERE ID = ?")->execute([$sessao_id]);
            enviarResposta($cpf, $acao, ['success' => true, 'proposal_id' => $proposal_id, 'link_assinatura' => $url_formalizacao, 'msg' => 'Proposta gerada!' ]);
            break;

        case 'ver_status_proposta':
            $stmt = $pdo->prepare("SELECT NUMERO_PROPOSTA, ID FROM INTEGRACAO_V8_REGISTRO_PROPOSTA WHERE CPF_CLIENTE = ? ORDER BY ID DESC LIMIT 1"); $stmt->execute([$cpf]); $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proposta || empty($proposta['NUMERO_PROPOSTA'])) { enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => 'Nenhuma proposta encontrada.']); }
            
            $proposal_id = $proposta['NUMERO_PROPOSTA']; $tokenV8 = gerarTokenV8_Local($credencialIA);
            $chP = curl_init("https://bff.v8sistema.com/private-consignment/operation/{$proposal_id}"); curl_setopt($chP, CURLOPT_RETURNTRANSFER, true); curl_setopt($chP, CURLOPT_HTTPGET, true); curl_setopt($chP, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]); $resP = curl_exec($chP); $httpP = curl_getinfo($chP, CURLINFO_HTTP_CODE); curl_close($chP); $jsonP = json_decode($resP, true);
            if ($httpP >= 400 || empty($jsonP)) { enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => 'Falha V8: ' . extrairErroV8($jsonP)]); }

            $status_proposta = strtoupper($jsonP['status'] ?? 'DESCONHECIDO'); $motivo_pendencia = $jsonP['pendency_reason'] ?? $jsonP['status_description'] ?? '';
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTRO_PROPOSTA SET STATUS_PROPOSTA_V8 = ?, DATA_STATUS = NOW() WHERE ID = ?")->execute([$status_proposta, $proposta['ID']]);
            enviarResposta($cpf, $acao, ['success' => true, 'status_proposta' => $status_proposta, 'motivo_pendencia' => $motivo_pendencia]);
            break;

        case 'resolver_pendencia_pix':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            $sessao_id = buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone);
            $novo_pix = trim($req['pix'] ?? '');
            if (empty($novo_pix)) { enviarResposta($cpf, $acao, ['success' => false, 'error' => 'Chave PIX obrigatória.']); }

            $stmt = $pdo->prepare("SELECT NUMERO_PROPOSTA, ID FROM INTEGRACAO_V8_REGISTRO_PROPOSTA WHERE CPF_CLIENTE = ? ORDER BY ID DESC LIMIT 1"); $stmt->execute([$cpf]); $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proposta || empty($proposta['NUMERO_PROPOSTA'])) { enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => 'Nenhuma proposta.']); }
            
            $proposal_id = $proposta['NUMERO_PROPOSTA']; $tokenV8 = gerarTokenV8_Local($credencialIA);
            $payloadBank = [ 'bank' => [ 'transfer_method' => 'pix', 'pix_key' => $novo_pix, 'pix_key_type' => 'cpf' ] ];
            $chB = curl_init("https://bff.v8sistema.com/private-consignment/operation/{$proposal_id}"); curl_setopt($chB, CURLOPT_RETURNTRANSFER, true); curl_setopt($chB, CURLOPT_CUSTOMREQUEST, 'PATCH'); curl_setopt($chB, CURLOPT_POSTFIELDS, json_encode($payloadBank)); curl_setopt($chB, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8", "Content-Type: application/json"]); $resB = curl_exec($chB); $httpB = curl_getinfo($chB, CURLINFO_HTTP_CODE); curl_close($chB); $jsonB = json_decode($resB, true);
            if ($httpB >= 400) { enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => 'Erro PIX V8: ' . extrairErroV8($jsonB)]); }

            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTRO_PROPOSTA SET STATUS_PROPOSTA_V8 = 'PIX ATUALIZADO', DATA_STATUS = NOW() WHERE ID = ?")->execute([$proposta['ID']]);
            enviarResposta($cpf, $acao, ['success' => true, 'status' => 'CONCLUIDO', 'msg' => 'PIX atualizado!']);
            break;

        case 'cancelar_proposta':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            $sessao_id = buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone);
            $stmt = $pdo->prepare("SELECT p.NUMERO_PROPOSTA, p.ID, c.nome FROM INTEGRACAO_V8_REGISTRO_PROPOSTA p LEFT JOIN dados_cadastrais c ON p.CPF_CLIENTE = c.cpf WHERE p.CPF_CLIENTE = ? ORDER BY p.ID DESC LIMIT 1"); $stmt->execute([$cpf]); $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$proposta) { enviarResposta($cpf, $acao, ['success' => false, 'status' => 'ERRO', 'error' => 'Nenhuma proposta para cancelar.']); }

            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTRO_PROPOSTA SET STATUS_PROPOSTA_V8 = 'CANCELAMENTO SOLICITADO (IA)', DATA_STATUS = NOW() WHERE ID = ?")->execute([$proposta['ID']]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'CONCLUIDO' WHERE ID = ?")->execute([$sessao_id]);
            enviarResposta($cpf, $acao, ['success' => true, 'status' => 'CONCLUIDO', 'msg' => 'Cancelamento registrado.']);
            break;

        case 'consultar_cadastro':
            $cliente = buscarOuAtualizarCadastro($cpf, $pdo, $credencialIA);
            enviarResposta($cpf, $acao, ['success' => true, 'dados' => ['nome' => $cliente['nome'], 'nascimento' => $cliente['nascimento'], 'sexo' => $cliente['sexo']] ]);
            break;

        case 'verificar_margem':
            $telefone = preg_replace('/\D/', '', $req['telefone'] ?? '11900000000');
            $sessao_id = buscarSessaoIA($cpf, $tokenIA, $pdo, $telefone);
            
            $stmt = $pdo->prepare("SELECT CONSULT_ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CPF_CONSULTADO = ? ORDER BY ID DESC LIMIT 1");
            $stmt->execute([$cpf]);
            $consult_id = $stmt->fetchColumn();
            if(!$consult_id) throw new Exception("Nenhuma consulta encontrada.");

            $tokenV8 = gerarTokenV8_Local($credencialIA);
            
            $chC = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id}"); curl_setopt($chC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chC, CURLOPT_HTTPGET, true); curl_setopt($chC, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]);
            $resC = curl_exec($chC); curl_close($chC); $jsonC = json_decode($resC, true);
            $status_api = strtoupper($jsonC['status'] ?? '');
            
            if (in_array($status_api, ['PROCESSING', 'PENDING', 'WAITING', 'WAITING_CONSULT', 'ANALYZING', 'IN_PROGRESS', 'PENDING_CONSULTATION', 'CONSENT_APPROVED', 'CREATED', 'QUEUED', 'STARTED'])) {
                enviarResposta($cpf, $acao, ['success' => true, 'status' => 'pendente', 'msg' => 'Dataprev ainda está processando.']);
            }
            if (!in_array($status_api, ['SUCCESS', 'COMPLETED', 'WAITING_CREDIT_ANALYSIS', 'APPROVED', 'PRE_APPROVED', 'SIMULATED', 'READY', 'AVAILABLE', 'MARGIN_AVAILABLE', 'AUTHORIZED', 'DONE', 'FINISHED'])) {
                $erroMsg = extrairErroV8($jsonC);
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO-MARGEM', MENSAGEM_ERRO = ? WHERE CONSULT_ID = ?")->execute([$erroMsg, $consult_id]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'ERRO_MARGEM' WHERE ID = ?")->execute([$sessao_id]);
                enviarResposta($cpf, $acao, ['success' => false, 'status' => 'erro', 'error' => $erroMsg]);
            }

            $margem = $jsonC['availableMargin'] ?? $jsonC['marginBaseValue'] ?? $jsonC['availableMarginValue'] ?? $jsonC['maxAmount'] ?? 0;
            $simDados = processarSimulacaoPadrao($consult_id, $cpf, (float)$margem, $pdo, $credencialIA, $tokenV8);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET CONSULT_ID = ?, STATUS_SESSAO = 'SIMULACAO_PRONTA' WHERE ID = ?")->execute([$consult_id, $sessao_id]);

            enviarResposta($cpf, $acao, ['success' => true, 'status' => 'concluido', 'margem_disponivel' => (float)$margem, 'simulacao_padrao' => $simDados]);
            break;

        default:
            enviarResposta($cpf, 'FALHA_ACAO', ['success' => false, 'error' => "Ação não reconhecida."]);
            break;
    }
} catch (Exception $e) {
    $msgErro = $e->getMessage();
    
    // ✅ TRAVA DE SEGURANÇA: Garante que a sessão e a consulta recebam o status correto no CRM
    if (isset($sessao_id) && $sessao_id > 0) {
        $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'ERRO_API', ULTIMA_ACAO = NOW() WHERE ID = ?")->execute([$sessao_id]);
    }
    
    $cid = !empty($consult_id) ? $consult_id : (!empty($ultimo_consult_id) ? $ultimo_consult_id : null);
    if (!empty($cid)) {
        $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO_API', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE CONSULT_ID = ?")->execute([$msgErro, $cid]);
    }
    
    enviarResposta($cpf, 'ERRO_CRITICO', ['success' => false, 'status' => 'ERRO', 'error' => $msgErro]);
}
?>