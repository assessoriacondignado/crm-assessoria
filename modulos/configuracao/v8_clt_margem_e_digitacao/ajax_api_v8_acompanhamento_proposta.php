<?php
ob_start(); 
ini_set('display_errors', 0); 
error_reporting(0); 
session_start(); 
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4");
    
    $caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

    $acao = $_POST['acao'] ?? '';
    $usuario_logado_id = $_SESSION['usuario_id'] ?? 1;

    // ✨ CPF DO USUÁRIO LOGADO PARA TRAVA DE SEGURANÇA ✨
    $usuario_logado_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

    // Hierarquia por empresa nas propostas (verificaPermissaoEstrita = bloqueia MASTER também)
    $restricao_hierarquia_proposta = function_exists('verificaPermissaoEstrita') ? !verificaPermissaoEstrita($pdo, 'SUBMENU_OP_INTEGRACAO_V8_HIERARQUIA') : !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_HIERARQUIA', 'FUNCAO');
    $restricao_meu_registro_proposta = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_PROPOSTA_MEU_REGISTRO', 'FUNCAO') : false;
    $id_empresa_logado_proposta = null;
    if ($restricao_hierarquia_proposta || $restricao_meu_registro_proposta) {
        $stmtEmpP = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmpP->execute([$usuario_logado_cpf]);
        $id_empresa_logado_proposta = $stmtEmpP->fetchColumn() ?: null;
    }

    function v8_api_request_with_lock($url, $method, $payload, $headers, $chave_id) {
        $lock_dir = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8';
        if (!is_dir($lock_dir)) { @mkdir($lock_dir, 0777, true); }
        $lock_file = $lock_dir . '/v8_api_chave_' . $chave_id . '.lock';
        $fp = @fopen($lock_file, "w+");
        if ($fp && flock($fp, LOCK_EX)) {
            $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($payload) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload); }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); $res = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            usleep(500000); flock($fp, LOCK_UN); fclose($fp);
            return ['response' => $res, 'http_code' => $http_code];
        } else {
            if ($fp) fclose($fp); throw new Exception("A fila desta credencial está ocupada no momento. Tente novamente em instantes.");
        }
    }

    function v8LogProposta($numero_proposta, $regra, $req_url, $req_body, $res_body, $http_code) {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8_propostas'; 
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        $file = $dir . '/PROPOSTA-' . preg_replace('/[^a-zA-Z0-9]/', '', $numero_proposta) . '.txt'; 
        $dataHora = date('d/m/Y H:i:s');
        $req_print = (is_array($req_body) || is_object($req_body)) ? json_encode($req_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $req_body;
        $res_print = (is_array($res_body) || is_object($res_body)) ? json_encode($res_body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $res_body;
        $log = "\n=== AÇÃO: {$regra} ===\nDATA: {$dataHora}\nURL: {$req_url}\nHTTP: {$http_code}\nREQ:\n{$req_print}\nRES:\n{$res_print}\n========================\n";
        @file_put_contents($file, $log, FILE_APPEND);
    }

    function pegarChaveIdPorCpf($cpf, $pdo) {
        $stmtFila = $pdo->prepare("SELECT CHAVE_ID FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE CPF_CONSULTADO = ? ORDER BY ID DESC LIMIT 1");
        $stmtFila->execute([$cpf]);
        $fila = $stmtFila->fetch(PDO::FETCH_ASSOC);
        if(!$fila) throw new Exception("Credencial não encontrada para este cliente.");
        return $fila['CHAVE_ID'];
    }

    function gerarTokenV8($chave_id, $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); $stmt->execute([$chave_id]); $chave = $stmt->fetch(PDO::FETCH_ASSOC);
        $payload = http_build_query([ 'grant_type' => 'password', 'username' => $chave['USERNAME_API'], 'password' => $chave['PASSWORD_API'], 'audience' => $chave['AUDIENCE'], 'client_id' => $chave['CLIENT_ID'], 'scope' => 'offline_access' ]);
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $api_call = v8_api_request_with_lock("https://auth.v8sistema.com/oauth/token", 'POST', $payload, $headers, $chave_id);
        $json = json_decode($api_call['response'], true);
        if (isset($json['access_token'])) return $json['access_token']; 
        throw new Exception("Falha ao autenticar na V8.");
    }

    switch ($acao) {
        
        case 'listar_propostas':
            $filtro_cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $filtro_numero = trim($_POST['numero'] ?? '');
            $filtro_status = trim($_POST['status'] ?? '');
            $data_ini = trim($_POST['data_ini'] ?? '');
            $data_fim = trim($_POST['data_fim'] ?? '');
            
            $where = " WHERE 1=1 ";
            $params = [];

            if ($restricao_meu_registro_proposta && !empty($usuario_logado_cpf)) {
                // Somente propostas do próprio usuário
                $where .= " AND p.CPF_USUARIO = ? ";
                $params[] = $usuario_logado_cpf;
            } elseif ($restricao_hierarquia_proposta && $id_empresa_logado_proposta) {
                // Somente propostas da empresa do usuário (bloqueia MASTER também)
                $where .= " AND p.EMPRESA_ID = ? ";
                $params[] = $id_empresa_logado_proposta;
            }

            if (!empty($data_ini)) { $where .= " AND DATE(p.DATA_DIGITACAO) >= ? "; $params[] = $data_ini; }
            if (!empty($data_fim)) { $where .= " AND DATE(p.DATA_DIGITACAO) <= ? "; $params[] = $data_fim; }
            if (!empty($filtro_cpf)) { $where .= " AND p.CPF_CLIENTE LIKE ? "; $params[] = "%$filtro_cpf%"; }
            if (!empty($filtro_numero)) { $where .= " AND p.NUMERO_PROPOSTA LIKE ? "; $params[] = "%$filtro_numero%"; }
            if (!empty($filtro_status)) { 
                if ($filtro_status === 'PAGO') { $where .= " AND (p.STATUS_PROPOSTA_V8 LIKE '%PAGO%' OR p.STATUS_PROPOSTA_V8 LIKE '%APROV%') "; } 
                else { $where .= " AND p.STATUS_PROPOSTA_V8 LIKE ? "; $params[] = "%$filtro_status%"; }
            }
            
            $sql = "SELECT p.*, DATE_FORMAT(p.DATA_DIGITACAO, '%d/%m/%Y %H:%i') as DATA_DIGITACAO_BR, DATE_FORMAT(p.DATA_STATUS, '%d/%m/%Y %H:%i') as DATA_STATUS_BR FROM INTEGRACAO_V8_REGISTRO_PROPOSTA p $where ORDER BY p.ID DESC LIMIT 200";
            
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;

        case 'cancelar_proposta':
            $id_proposta_db = (int)$_POST['id_db']; $motivo = trim($_POST['motivo']); if(empty($motivo)) throw new Exception("Informe um motivo.");
            $stmtP = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_REGISTRO_PROPOSTA WHERE ID = ?"); $stmtP->execute([$id_proposta_db]); $prop = $stmtP->fetch(PDO::FETCH_ASSOC);
            $numero_proposta_v8 = $prop['NUMERO_PROPOSTA']; $chave_id = pegarChaveIdPorCpf($prop['CPF_CLIENTE'], $pdo); $token = gerarTokenV8($chave_id, $pdo);
            $stmtAverb = $pdo->prepare("SELECT AVERBADORA FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ? LIMIT 1"); $stmtAverb->execute([$chave_id]); $averbadora_cancel = strtoupper(trim($stmtAverb->fetchColumn() ?: 'QI')) ?: 'QI';
            $payload = [ 'cancel_reason' => 'invalid_data:other', 'cancel_description' => mb_substr($motivo, 0, 200, 'UTF-8'), 'provider' => $averbadora_cancel ];
            $url = "https://bff.v8sistema.com/private-consignment/operation/{$numero_proposta_v8}/cancel";
            $headers = ["Authorization: Bearer $token", "Content-Type: application/json"];
            $api_call = v8_api_request_with_lock($url, 'POST', $payload, $headers, $chave_id); $res_raw = $api_call['response']; $http_code = $api_call['http_code']; $res = json_decode($res_raw, true);
            if ($http_code >= 400) { throw new Exception("V8 recusou: " . ($res['detail'] ?? $res_raw)); }
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTRO_PROPOSTA SET STATUS_PROPOSTA_V8 = 'CANCELADA', DATA_CANCELAMENTO = NOW(), DATA_STATUS = NOW() WHERE ID = ?")->execute([$id_proposta_db]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Cancelada com sucesso!']); exit;

        case 'resolver_pendencia_pix':
            $id_proposta_db = (int)$_POST['id_db']; $pix = trim($_POST['pix']); $tipo_pix_front = strtoupper(trim($_POST['tipo_pix']));
            $stmtP = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_REGISTRO_PROPOSTA WHERE ID = ?"); $stmtP->execute([$id_proposta_db]); $prop = $stmtP->fetch(PDO::FETCH_ASSOC);
            $numero_proposta_v8 = $prop['NUMERO_PROPOSTA']; $chave_id = pegarChaveIdPorCpf($prop['CPF_CLIENTE'], $pdo); $token = gerarTokenV8($chave_id, $pdo);
            $tipo_pix_api = 'cpf'; if ($tipo_pix_front === 'PHONE') $tipo_pix_api = 'phone'; if ($tipo_pix_front === 'EMAIL') $tipo_pix_api = 'email'; if ($tipo_pix_front === 'RANDOM') $tipo_pix_api = 'chave aleatória'; 
            $payload = [ 'bank' => [ 'transfer_method' => 'pix', 'pix_key' => $pix, 'pix_key_type' => $tipo_pix_api ] ];
            $url = "https://bff.v8sistema.com/private-consignment/operation/{$numero_proposta_v8}/pendency/payment-data";
            $headers = ["Authorization: Bearer $token", "Content-Type: application/json"];
            $api_call = v8_api_request_with_lock($url, 'PATCH', $payload, $headers, $chave_id); $res_raw = $api_call['response']; $http_code = $api_call['http_code']; $res = json_decode($res_raw, true);
            if ($http_code >= 400) { throw new Exception("V8 recusou a correção: " . ($res['detail'] ?? $res_raw)); }
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Pendência resolvida!']); exit;

        case 'atualizar_status':
            $id_proposta_db = (int)$_POST['id_db'];
            $stmtP = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_REGISTRO_PROPOSTA WHERE ID = ?"); $stmtP->execute([$id_proposta_db]); $prop = $stmtP->fetch(PDO::FETCH_ASSOC);
            $numero_proposta_v8 = $prop['NUMERO_PROPOSTA']; $chave_id = pegarChaveIdPorCpf($prop['CPF_CLIENTE'], $pdo); $token = gerarTokenV8($chave_id, $pdo);
            $url = "https://bff.v8sistema.com/private-consignment/operation/{$numero_proposta_v8}";
            $headers = ["Authorization: Bearer $token"];
            $api_call = v8_api_request_with_lock($url, 'GET', null, $headers, $chave_id); $res_raw = $api_call['response']; $http_code = $api_call['http_code']; $res = json_decode($res_raw, true);
            if ($http_code == 200 && isset($res['status'])) {
                $novo_status = strtoupper($res['status']);
                if ($novo_status !== $prop['STATUS_PROPOSTA_V8']) {
                    $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTRO_PROPOSTA SET STATUS_PROPOSTA_V8 = ?, DATA_STATUS = NOW() WHERE ID = ?")->execute([$novo_status, $id_proposta_db]);
                    ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Status atualizado: ' . $novo_status]); exit;
                } else { ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'O status continua o mesmo: ' . $novo_status]); exit; }
            } else { throw new Exception("Falha ao consultar V8."); }

        default: throw new Exception("Ação desconhecida.");
    }
} catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>