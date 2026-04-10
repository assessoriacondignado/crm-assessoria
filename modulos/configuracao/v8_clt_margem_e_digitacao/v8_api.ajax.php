<?php
ini_set('display_errors', 0);
session_start();

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($acao !== 'exportar_fila_consultas') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    // =========================================================================
    // CONEXÃO E CONFIGURAÇÃO INICIAL
    // =========================================================================
    $caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!file_exists($caminho_conexao)) { echo json_encode(['success' => false, 'msg' => "ERRO: conexao.php não encontrado."]); exit; }
    require_once $caminho_conexao; 

    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $usuario_logado_id = $_SESSION['usuario_id'] ?? 1;
    $usuario_logado_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

    // =========================================================================
    // SISTEMA DE LOGS E LIMPEZA (ÚLTIMOS 20 LOGS) - CONSULTA MANUAL
    // =========================================================================
    function limparLogsManuais($diretorio, $limite = 20) {
        if (!is_dir($diretorio)) { @mkdir($diretorio, 0777, true); return; }
        $arquivos = glob($diretorio . '/*.txt');
        
        if (count($arquivos) > $limite) {
            usort($arquivos, function($a, $b) { return filemtime($a) - filemtime($b); });
            $arquivos_para_deletar = array_slice($arquivos, 0, count($arquivos) - $limite);
            foreach ($arquivos_para_deletar as $arquivo) { @unlink($arquivo); }
        }
    }

    function gravarLogManual($cpf, $fase, $url, $req, $res, $http_code) {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8/logs_consulta_manual';
        if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
        
        $data_nome_arquivo = date('d-m-Y_H\h'); 
        $file = $dir . '/log_cpf_' . $cpf . '_' . $data_nome_arquivo . '.txt';
        
        $req_print = (is_array($req) || is_object($req)) ? json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $req;
        $res_print = (is_array($res) || is_object($res)) ? json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $res;
        
        $log = "\n[ " . date('d/m/Y H:i:s') . " ] === {$fase} ===\n";
        $log .= "URL: {$url}\nHTTP STATUS: {$http_code}\n";
        $log .= ">>> PAYLOAD (ENVIO):\n{$req_print}\n";
        $log .= "<<< RESPOSTA (RETORNO):\n{$res_print}\n";
        $log .= str_repeat("-", 60) . "\n";
        
        @file_put_contents($file, $log, FILE_APPEND);
        limparLogsManuais($dir, 20);
    }

    // =========================================================================
    // MOTOR DE PERMISSÃO OFICIAL DO SISTEMA
    // =========================================================================
    $caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

    $restricao_meu_usuario = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE_CUSTO_MEU_USUARIO', 'FUNCAO') : false;
    $restricao_minha_fila = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_FILA_MEU_REGITRO', 'FUNCAO') : false;
    $restricao_chave = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE', 'FUNCAO') : false;
    $restricao_custo_cliente = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CUSTO_CLIENTE', 'FUNCAO') : false;
    $restricao_custo_api = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CUSTO_API', 'FUNCAO') : false;
    // Hierarquia por empresa: se o usuário NÃO tem a permissão, vê somente registros da sua empresa
    $restricao_hierarquia_fila = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_HIERARQUIA', 'FUNCAO') : true;
    $id_empresa_logado = null;
    if ($restricao_hierarquia_fila && !$restricao_minha_fila) {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$usuario_logado_cpf]);
        $id_empresa_logado = $stmtEmp->fetchColumn() ?: null;
    }

    $URL_AUTENTICACAO = "https://auth.v8sistema.com/oauth/token"; 
    $URL_CONSULTA = "https://bff.v8sistema.com/private-consignment/consult"; 
    $URL_CONFIGS = "https://bff.v8sistema.com/private-consignment/simulation/configs"; 
    $URL_SIMULACAO_MARGEM = "https://bff.v8sistema.com/private-consignment/simulation"; 

    function gerarTokenV8($chave_id, $pdo, $url_auth) {
        $stmt = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
        $stmt->execute([$chave_id]);
        $chave = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$chave) throw new Exception("Credenciais V8 não encontradas no banco de dados.");
        $post_fields = http_build_query([ 'grant_type' => 'password', 'username' => $chave['USERNAME_API'], 'password' => $chave['PASSWORD_API'], 'audience' => $chave['AUDIENCE'], 'client_id' => $chave['CLIENT_ID'], 'scope' => 'offline_access' ]);
        $ch = curl_init($url_auth); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        $response = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $json = json_decode($response, true);
        if ($http_code >= 200 && $http_code < 300 && isset($json['access_token'])) { return $json['access_token']; } else { throw new Exception("Falha ao autenticar na V8."); }
    }

    switch ($acao) {
        
        case 'buscar_saldos_gerais':
            $chave_id = (int)($_POST['chave_id'] ?? 0);
            $saldo_v8 = 0.00;
            $saldo_cad = 0.00;

            if ($chave_id > 0) {
                $stmtChave = $pdo->prepare("SELECT CPF_USUARIO, SALDO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
                $stmtChave->execute([$chave_id]);
                $chaveData = $stmtChave->fetch(PDO::FETCH_ASSOC);
                
                if ($chaveData) {
                    $saldo_v8 = (float)$chaveData['SALDO'];
                    $cpf_dono_chave = $chaveData['CPF_USUARIO'];
                    
                    if (!empty($cpf_dono_chave)) {
                        $stmtCad = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ?");
                        $stmtCad->execute([$cpf_dono_chave]);
                        $saldo_cad = (float)$stmtCad->fetchColumn();
                    }
                }
            } else {
                $stmtV8 = $pdo->prepare("SELECT SUM(SALDO) FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE CPF_USUARIO = ?");
                $stmtV8->execute([$usuario_logado_cpf]);
                $saldo_v8 = (float)$stmtV8->fetchColumn();

                $stmtCad = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ?");
                $stmtCad->execute([$usuario_logado_cpf]);
                $saldo_cad = (float)$stmtCad->fetchColumn();
            }

            echo json_encode([ 'success' => true, 'saldo_v8' => number_format($saldo_v8, 2, ',', '.'), 'saldo_cad' => number_format($saldo_cad, 2, ',', '.') ]);
            break;

        case 'buscar_cliente_banco':
            $busca = trim($_POST['termo'] ?? '');
            if (strlen($busca) < 3) { echo json_encode(['success' => true, 'dados' => []]); exit; }
            $busca_limpa = preg_replace('/[^a-zA-Z0-9]/', '', $busca);
            $is_cpf_exato = (is_numeric($busca_limpa) && strlen($busca_limpa) == 11);

            if ($is_cpf_exato) {
                $stmt = $pdo->prepare("SELECT * FROM dados_cadastrais WHERE cpf = ?"); $stmt->execute([$busca_limpa]); $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cliente) {
                    $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? LIMIT 1"); $stmtTel->execute([$busca_limpa]);
                    $cliente['telefone'] = $stmtTel->fetchColumn() ?: '';
                    echo json_encode(['success' => true, 'dados' => [$cliente]]);
                } else { echo json_encode(['success' => true, 'dados' => []]); }
            } else {
                $termos = explode(',', $busca); $sql = "SELECT cpf, nome, nascimento, sexo FROM dados_cadastrais WHERE 1=1 "; $params = [];
                foreach ($termos as $t) { $t = trim($t); if (!empty($t)) { $t_limpo = preg_replace('/[^a-zA-Z0-9]/', '', $t); $sql .= " AND (nome LIKE ? OR cpf LIKE ? OR cpf LIKE ?) "; $params[] = "%$t%"; $params[] = "%$t%"; $params[] = "%$t_limpo%"; } }
                $sql .= " LIMIT 15"; $stmt = $pdo->prepare($sql); $stmt->execute($params);
                echo json_encode(['success' => true, 'dados' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            } break;

        case 'listar_cadastros_base':
            $res = ['usuarios' => []];
            try { 
                if ($restricao_meu_usuario) {
                    $stmtU = $pdo->prepare("SELECT CPF as id, NOME as nome FROM CLIENTE_USUARIO WHERE CPF = ? ORDER BY NOME ASC"); $stmtU->execute([$usuario_logado_cpf]);
                } else {
                    $stmtU = $pdo->query("SELECT CPF as id, NOME as nome FROM CLIENTE_USUARIO ORDER BY NOME ASC"); 
                }
                $res['usuarios'] = $stmtU->fetchAll(PDO::FETCH_ASSOC); 
            } catch (Exception $e) {}
            echo json_encode(['success' => true, 'data' => $res]); break;

        case 'listar_chaves_acesso':
            if ($restricao_meu_usuario) { 
                $stmt = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE CPF_USUARIO = ? ORDER BY CLIENTE_NOME ASC"); $stmt->execute([$usuario_logado_cpf]); 
            } else { 
                $stmt = $pdo->query("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO ORDER BY CLIENTE_NOME ASC"); 
            }
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;

        case 'salvar_chave_v8':
            $id = (int)($_POST['id'] ?? 0); $tabela_padrao = trim($_POST['tabela_padrao'] ?? 'CLT Acelera'); $prazo_padrao = (int)($_POST['prazo_padrao'] ?? 24);
            $intervalo_consentimento = max(0, (int)($_POST['intervalo_consentimento'] ?? 0));
            try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_CHAVE_ACESSO ADD COLUMN INTERVALO_CONSENTIMENTO INT NOT NULL DEFAULT 0"); } catch(Exception $e){}
            $client_id = trim($_POST['client_id'] ?? ''); $audience = trim($_POST['audience'] ?? '');

            if(empty($client_id) || empty($audience)) {
                $stmtDef = $pdo->query("SELECT CLIENT_ID, AUDIENCE FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE CLIENT_ID IS NOT NULL AND CLIENT_ID != '' LIMIT 1");
                $defaultCreds = $stmtDef->fetch(PDO::FETCH_ASSOC);
                if($defaultCreds) { $client_id = $defaultCreds['CLIENT_ID']; $audience = $defaultCreds['AUDIENCE']; } else { $client_id = 'DHWogdaYmEI8n5bwwxPDzulMlSK7dwln'; $audience = 'https://bff.v8sistema.com'; }
            }

            $username_api = trim($_POST['username_api'] ?? ''); $password_api = trim($_POST['password_api'] ?? '');
            $custo_consulta = (float)str_replace(',', '.', $_POST['custo_consulta'] ?? 0); $custo_v8 = (float)str_replace(',', '.', $_POST['custo_v8'] ?? 0); 
            $cpf_dono = preg_replace('/\D/', '', $_POST['usuario_id'] ?? '');
            if ($restricao_meu_usuario) { $cpf_dono = $usuario_logado_cpf; }

            $nome_dono = 'Usuário Padrão'; $cliente_nome = 'CLIENTE NÃO IDENTIFICADO';
            if(!empty($cpf_dono)) {
                $stmtD = $pdo->prepare("SELECT u.NOME as NOME_US, c.NOME as NOME_CLI FROM CLIENTE_USUARIO u LEFT JOIN CLIENTE_CADASTRO c ON u.CPF = c.CPF WHERE u.CPF = ?"); 
                $stmtD->execute([$cpf_dono]); $dadosDono = $stmtD->fetch(PDO::FETCH_ASSOC);
                if($dadosDono) { $nome_dono = $dadosDono['NOME_US'] ?: 'Usuário Padrão'; $cliente_nome = mb_strtoupper($dadosDono['NOME_CLI'] ?: $nome_dono, 'UTF-8'); }
            }

            if ($id > 0) {
                $stmtExist = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); $stmtExist->execute([$id]); $chaveExist = $stmtExist->fetch(PDO::FETCH_ASSOC);
                if ($chaveExist) {
                    if ($restricao_chave) { $client_id = $chaveExist['CLIENT_ID']; $audience = $chaveExist['AUDIENCE']; $username_api = $chaveExist['USERNAME_API']; $password_api = $chaveExist['PASSWORD_API']; }
                    if ($restricao_custo_cliente) { $custo_consulta = (float)$chaveExist['CUSTO_CONSULTA']; }
                    if ($restricao_custo_api) { $custo_v8 = (float)$chaveExist['CUSTO_V8']; }
                }
                $pdo->prepare("UPDATE INTEGRACAO_V8_CHAVE_ACESSO SET CLIENTE_NOME=?, CLIENT_ID=?, AUDIENCE=?, USERNAME_API=?, PASSWORD_API=?, CUSTO_CONSULTA=?, CUSTO_V8=?, TABELA_PADRAO=?, PRAZO_PADRAO=?, INTERVALO_CONSENTIMENTO=?, CPF_USUARIO=?, NOME_USUARIO=? WHERE ID=?")->execute([$cliente_nome, $client_id, $audience, $username_api, $password_api, $custo_consulta, $custo_v8, $tabela_padrao, $prazo_padrao, $intervalo_consentimento, $cpf_dono, $nome_dono, $id]);
            } else {
                if ($restricao_chave) { $client_id = ''; $audience = ''; $username_api = ''; $password_api = ''; }
                if ($restricao_custo_cliente) { $custo_consulta = 0; }
                if ($restricao_custo_api) { $custo_v8 = 0; }
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_CHAVE_ACESSO (CLIENTE_NOME, CLIENT_ID, AUDIENCE, USERNAME_API, PASSWORD_API, CUSTO_CONSULTA, CUSTO_V8, CPF_USUARIO, NOME_USUARIO, TABELA_PADRAO, PRAZO_PADRAO, INTERVALO_CONSENTIMENTO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$cliente_nome, $client_id, $audience, $username_api, $password_api, $custo_consulta, $custo_v8, $cpf_dono, $nome_dono, $tabela_padrao, $prazo_padrao, $intervalo_consentimento]);
            }
            echo json_encode(['success' => true, 'msg' => 'Chave salva com sucesso!']); break;

        case 'listar_extrato_cliente':
            $chave_id = (int)$_POST['chave_id']; 
            if ($restricao_meu_usuario) {
                $stmtCheck = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ? AND CPF_USUARIO = ?"); $stmtCheck->execute([$chave_id, $usuario_logado_cpf]);
                if (!$stmtCheck->fetch()) { echo json_encode(['success' => true, 'data' => []]); exit; }
            }
            $stmt = $pdo->prepare("SELECT ID, TIPO_MOVIMENTO, TIPO_CUSTO, VALOR, CUSTO_V8, SALDO_ANTERIOR, SALDO_ATUAL, DATE_FORMAT(DATA_LANCAMENTO, '%d/%m/%Y %H:%i:%s') as DATA_BR FROM INTEGRACAO_V8_EXTRATO_CLIENTE WHERE CHAVE_ID = ? ORDER BY ID DESC LIMIT 500"); $stmt->execute([$chave_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;

        case 'ajustar_saldo_manual':
            $chave_id = (int)$_POST['chave_id']; $tipo = $_POST['tipo'] ?? 'CREDITO'; $valor = abs((float)$_POST['valor']); $obs = trim($_POST['obs']);
            if ($valor <= 0) throw new Exception("Valor inválido."); $pdo->beginTransaction(); $stmtCli = $pdo->prepare("SELECT SALDO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ? FOR UPDATE"); $stmtCli->execute([$chave_id]); $chave = $stmtCli->fetch(PDO::FETCH_ASSOC); if (!$chave) throw new Exception("Chave não encontrada.");
            $saldo_anterior = (float)$chave['SALDO']; $saldo_atual = ($tipo === 'CREDITO') ? ($saldo_anterior + $valor) : ($saldo_anterior - $valor);
            $pdo->prepare("UPDATE INTEGRACAO_V8_CHAVE_ACESSO SET SALDO = ? WHERE ID = ?")->execute([$saldo_atual, $chave_id]); $pdo->prepare("INSERT INTO INTEGRACAO_V8_EXTRATO_CLIENTE (CHAVE_ID, TIPO_MOVIMENTO, TIPO_CUSTO, VALOR, CUSTO_V8, SALDO_ANTERIOR, SALDO_ATUAL, DATA_LANCAMENTO) VALUES (?, ?, ?, ?, 0.00, ?, ?, NOW())")->execute([$chave_id, $tipo, "Ajuste Manual: " . $obs, $valor, $saldo_anterior, $saldo_atual]); $pdo->commit();
            echo json_encode(['success' => true, 'msg' => 'Saldo ajustado!']); break;

        case 'listar_fila_consultas':
            $filtros_raw = $_POST['filtros'] ?? '[]';
            $filtros = json_decode($filtros_raw, true) ?: [];
            $offset = max(0, intval($_POST['offset'] ?? 0));
            $limit  = 100;

            $campos_map = [
                'ID'               => 'c.ID',
                'DATA_FILA'        => 'DATE(c.DATA_FILA)',
                'CPF_CONSULTADO'   => 'c.CPF_CONSULTADO',
                'NOME_COMPLETO'    => 'c.NOME_COMPLETO',
                'STATUS_V8'        => 'c.STATUS_V8',
                'FONTE_CONSULT_ID' => 'c.FONTE_CONSULT_ID',
                'NOME_USUARIO'     => 'u.NOME',
                'CLIENTE_NOME'     => 'ch.CLIENTE_NOME',
                'CONSULT_ID'       => 'c.CONSULT_ID',
                'NUMERO_PROPOSTA'  => 'p.NUMERO_PROPOSTA',
                'CPF_USUARIO'      => 'c.CPF_USUARIO',
            ];

            $where = " WHERE c.FONTE_CONSULT_ID != 'IA BOT' "; $params = [];
            if ($restricao_minha_fila) { $where .= " AND c.CPF_USUARIO = ? "; $params[] = $usuario_logado_cpf; }
            elseif ($restricao_hierarquia_fila && $id_empresa_logado) { $where .= " AND c.EMPRESA_ID = ? "; $params[] = $id_empresa_logado; }

            foreach ($filtros as $f) {
                $campo_key = $f['campo'] ?? ''; $operador = $f['operador'] ?? 'CONTEM'; $valor = trim($f['valor'] ?? '');
                if (empty($campo_key) || !isset($campos_map[$campo_key]) || $valor === '') continue;
                $col = $campos_map[$campo_key];
                switch ($operador) {
                    case 'IGUAL':   $where .= " AND $col = ? ";        $params[] = $valor;         break;
                    case 'COMECA':  $where .= " AND $col LIKE ? ";     $params[] = "$valor%";      break;
                    case 'TERMINA': $where .= " AND $col LIKE ? ";     $params[] = "%$valor";      break;
                    case 'MAIOR':   $where .= " AND $col > ? ";        $params[] = $valor;         break;
                    case 'MENOR':   $where .= " AND $col < ? ";        $params[] = $valor;         break;
                    default:        $where .= " AND $col LIKE ? ";     $params[] = "%$valor%";     break;
                }
            }

            $joins = "FROM INTEGRACAO_V8_REGISTROCONSULTA c
                LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO s ON s.ID = (SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1)
                LEFT JOIN INTEGRACAO_V8_REGISTRO_PROPOSTA p ON c.STATUS_V8 LIKE CONCAT('%', p.NUMERO_PROPOSTA, '%')
                LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ch ON c.CHAVE_ID = ch.ID
                LEFT JOIN CLIENTE_USUARIO u ON c.CPF_USUARIO COLLATE utf8mb4_unicode_ci = u.CPF COLLATE utf8mb4_unicode_ci";

            $stmt_total = $pdo->prepare("SELECT COUNT(*) $joins $where");
            $stmt_total->execute($params);
            $total = (int)$stmt_total->fetchColumn();

            $sql = "SELECT c.*, DATE_FORMAT(c.DATA_FILA, '%d/%m/%Y %H:%i') as DATA_FILA_BR, DATE_FORMAT(c.ULTIMA_ATUALIZACAO, '%d/%m/%Y %H:%i') as DATA_RETORNO_BR, s.CONFIG_ID, s.NOME_TABELA, s.MARGEM_DISPONIVEL as VALOR_MARGEM, s.PRAZOS_DISPONIVEIS as PRAZOS, s.SIMULATION_ID, s.STATUS_CONFIG_ID, s.OBS_CONFIG_ID, s.OBS_SIMULATION_ID, s.FONTE_CONSIG_ID, s.VALOR_LIBERADO, s.VALOR_PARCELA, s.PRAZO_SIMULACAO, DATE_FORMAT(s.DATA_CONFIG_ID, '%d/%m/%Y %H:%i') as DATA_CONFIG_BR, p.NUMERO_PROPOSTA, p.STATUS_PROPOSTA_V8 as STATUS_PROPOSTA_REAL_TIME, p.LINK_PROPOSTA, ch.USERNAME_API, ch.TABELA_PADRAO, ch.PRAZO_PADRAO, ch.CLIENTE_NOME, u.NOME as NOME_USUARIO $joins $where ORDER BY c.ID DESC LIMIT $limit OFFSET $offset";
            $stmt = $pdo->prepare($sql); $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $data, 'total' => $total, 'has_more' => ($offset + count($data)) < $total, 'offset' => $offset]);
            break;

        case 'exportar_fila_consultas':
            $filtros_raw = $_GET['filtros'] ?? '[]';
            $filtros = json_decode($filtros_raw, true) ?: [];

            $campos_map_exp = [
                'ID' => 'c.ID', 'DATA_FILA' => 'DATE(c.DATA_FILA)', 'CPF_CONSULTADO' => 'c.CPF_CONSULTADO',
                'NOME_COMPLETO' => 'c.NOME_COMPLETO', 'STATUS_V8' => 'c.STATUS_V8',
                'FONTE_CONSULT_ID' => 'c.FONTE_CONSULT_ID', 'NOME_USUARIO' => 'u.NOME',
                'CLIENTE_NOME' => 'ch.CLIENTE_NOME', 'CONSULT_ID' => 'c.CONSULT_ID',
                'NUMERO_PROPOSTA' => 'p.NUMERO_PROPOSTA', 'CPF_USUARIO' => 'c.CPF_USUARIO',
            ];

            $where = " WHERE c.FONTE_CONSULT_ID != 'IA BOT' ";
            $params = [];

            if ($restricao_minha_fila) { $where .= " AND c.CPF_USUARIO = ? "; $params[] = $usuario_logado_cpf; }
            elseif ($restricao_hierarquia_fila && $id_empresa_logado) { $where .= " AND c.EMPRESA_ID = ? "; $params[] = $id_empresa_logado; }
            foreach ($filtros as $f) {
                $campo_key = $f['campo'] ?? ''; $operador = $f['operador'] ?? 'CONTEM'; $valor = trim($f['valor'] ?? '');
                if (empty($campo_key) || !isset($campos_map_exp[$campo_key]) || $valor === '') continue;
                $col = $campos_map_exp[$campo_key];
                switch ($operador) {
                    case 'IGUAL':   $where .= " AND $col = ? ";    $params[] = $valor;    break;
                    case 'COMECA':  $where .= " AND $col LIKE ? "; $params[] = "$valor%"; break;
                    case 'TERMINA': $where .= " AND $col LIKE ? "; $params[] = "%$valor"; break;
                    case 'MAIOR':   $where .= " AND $col > ? ";    $params[] = $valor;    break;
                    case 'MENOR':   $where .= " AND $col < ? ";    $params[] = $valor;    break;
                    default:        $where .= " AND $col LIKE ? "; $params[] = "%$valor%"; break;
                }
            }
            
            $sql = "SELECT c.*, DATE_FORMAT(c.DATA_FILA, '%d/%m/%Y %H:%i') as DATA_FILA_BR, 
                    s.MARGEM_DISPONIVEL as VALOR_MARGEM, s.VALOR_LIBERADO, s.PRAZO_SIMULACAO as PRAZO, s.DATA_SIMULATION_ID, s.NOME_TABELA
                    FROM INTEGRACAO_V8_REGISTROCONSULTA c 
                    LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO s ON s.ID = ( SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1 ) 
                    $where ORDER BY c.ID DESC"; 
            
            $stmt = $pdo->prepare($sql); 
            $stmt->execute($params);

            ob_end_clean(); 
            header('Content-Type: text/csv; charset=utf-8'); 
            header('Content-Disposition: attachment; filename="Exportacao_V8_Manual_' . date('dmY_Hi') . '.csv"');
            
            $output = fopen('php://output', 'w'); 
            fputs($output, "\xEF\xBB\xBF"); 
            
            $cabecalho = [
                'NOME PLANILHA', 'DATA E HORA SIMULACAO', 'CPF', 'NOME', 'NASCIMENTO', 'SEXO', 'STATUS_V8', 'OBSERVACAO', 'TABELA SIMULADA', 'MARGEM', 'PRAZO', 'VALOR_LIBERADO', 'STATUS_WHATSAPP',
                'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP',
                'EMAIL 1', 'EMAIL 2', 'EMAIL 3',
                'DDD'
            ];
            for($i = 1; $i <= 10; $i++) { $cabecalho[] = "CELULAR $i"; }
            fputcsv($output, $cabecalho, ";");

            $stmtEnd = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");
            $stmtCad = $pdo->prepare("SELECT sexo FROM dados_cadastrais WHERE cpf = ? LIMIT 1");

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
                $stmtCad->execute([$row['CPF_CONSULTADO']]);
                $sexoDB = $stmtCad->fetchColumn();
                $sexoFinal = ($sexoDB && strtoupper(substr($sexoDB,0,1)) == 'M') ? 'MASCULINO' : 'FEMININO';

                $obs = $row['MENSAGEM_ERRO'] ?? '';
                if(empty($obs)) {
                    if($row['STATUS_V8'] == 'OK-SIMULACAO') $obs = 'Simulação concluída';
                    else $obs = $row['STATUS_V8'];
                }

                $linha = [ 
                    "CONSULTA MANUAL",
                    $row['DATA_SIMULATION_ID'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULATION_ID'])) : '-', 
                    $row['CPF_CONSULTADO'] . " ", 
                    $row['NOME_COMPLETO'], 
                    $row['DATA_NASCIMENTO'] ? date('d/m/Y', strtotime($row['DATA_NASCIMENTO'])) : '', 
                    $sexoFinal, 
                    $row['STATUS_V8'], 
                    $obs, 
                    $row['NOME_TABELA'] ?? '-',
                    $row['VALOR_MARGEM'] ? 'R$ ' . number_format($row['VALOR_MARGEM'], 2, ',', '.') : '-', 
                    $row['PRAZO'] ? $row['PRAZO'] . 'x' : '-', 
                    $row['VALOR_LIBERADO'] ? 'R$ ' . number_format($row['VALOR_LIBERADO'], 2, ',', '.') : '-',
                    'NAO ENVIADO'
                ];
                
                $stmtEnd->execute([$row['CPF_CONSULTADO']]);
                $end = $stmtEnd->fetch(PDO::FETCH_ASSOC);
                if ($end) {
                    $linha[] = $end['logradouro']; $linha[] = $end['numero']; $linha[] = $end['bairro'];
                    $linha[] = $end['cidade']; $linha[] = $end['uf']; $linha[] = $end['cep'] ? $end['cep'] . " " : ''; 
                } else { $linha = array_merge($linha, ['', '', '', '', '', '']); }

                $stmtEmail->execute([$row['CPF_CONSULTADO']]);
                $emails = $stmtEmail->fetchAll(PDO::FETCH_COLUMN);
                for($i = 0; $i < 3; $i++) { $linha[] = isset($emails[$i]) ? $emails[$i] : ''; }
                
                $stmtTel->execute([$row['CPF_CONSULTADO']]);
                $telefones = $stmtTel->fetchAll(PDO::FETCH_COLUMN);
                $ddd = (isset($telefones[0]) && strlen($telefones[0]) >= 10) ? substr($telefones[0], 0, 2) : '';
                $linha[] = $ddd;

                for($i = 0; $i < 10; $i++) { $tel = isset($telefones[$i]) ? $telefones[$i] : ''; $linha[] = $tel ? $tel . " " : ''; }

                fputcsv($output, $linha, ";"); 
            }
            fclose($output); exit;

        case 'solicitar_consulta_cpf':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? ''); $nascimento = trim($_POST['nascimento'] ?? ''); $genero = trim($_POST['genero'] ?? 'male'); $nome = mb_strtoupper(trim($_POST['nome'] ?? ''), 'UTF-8'); $email = trim($_POST['email'] ?? ''); $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? ''); $chave_id = !empty($_POST['chave_id']) ? (int)$_POST['chave_id'] : null;
            if (strlen($cpf) !== 11) { throw new Exception("CPF Inválido."); } if (!$chave_id) { throw new Exception("Selecione uma Chave."); }

            $stmtCli = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA, CUSTO_V8 FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); $stmtCli->execute([$chave_id]); $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
            $custo = floatval($cli['CUSTO_CONSULTA']); $saldo = floatval($cli['SALDO']);
            if ($saldo < $custo && $custo > 0) { throw new Exception("Saldo insuficiente."); }

            $area_code = substr($telefone, 0, 2) ?: '11'; $number = substr($telefone, 2) ?: '900000000';
            $token = gerarTokenV8($chave_id, $pdo, $URL_AUTENTICACAO);

            $ch1 = curl_init($URL_CONSULTA);
            $payload1 = ['borrowerDocumentNumber' => $cpf, 'gender' => $genero, 'birthDate' => $nascimento, 'signerName' => $nome, 'signerEmail' => empty($email) ? 'cliente@gmail.com' : $email, 'signerPhone' => ['countryCode' => '55', 'areaCode' => $area_code, 'phoneNumber' => $number], 'provider' => 'QI']; 
            curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch1, CURLOPT_POST, true); curl_setopt($ch1, CURLOPT_POSTFIELDS, json_encode($payload1)); curl_setopt($ch1, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
            $res1 = curl_exec($ch1); $http1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE); curl_close($ch1);

            $json1 = json_decode($res1, true); $consult_id = $json1['id'] ?? $json1['consult_id'] ?? null; $fonte_usada = 'NOVA CRIADA';

            gravarLogManual($cpf, 'FASE 1: CRIAR CONSENTIMENTO (MANUAL)', $URL_CONSULTA, $payload1, $json1, $http1);

            if ($http1 >= 400 && strpos($res1, 'consult_already_exists') !== false) {
                $ch_lista_fallback = curl_init($URL_CONSULTA . "?limit=50&page=1"); curl_setopt($ch_lista_fallback, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_lista_fallback, CURLOPT_HTTPGET, true); curl_setopt($ch_lista_fallback, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                $res_lista_fallback = curl_exec($ch_lista_fallback); $http_lista_fallback = curl_getinfo($ch_lista_fallback, CURLINFO_HTTP_CODE); curl_close($ch_lista_fallback); 
                $json_lista_fallback = json_decode($res_lista_fallback, true); 
                
                gravarLogManual($cpf, 'FASE 1: FALLBACK BUSCA CPF', $URL_CONSULTA . "?limit=50&page=1", "GET", $json_lista_fallback, $http_lista_fallback);

                $arr_items_fallback = $json_lista_fallback['data'] ?? $json_lista_fallback['items'] ?? $json_lista_fallback['content'] ?? [];
                
                if (is_array($arr_items_fallback) && count($arr_items_fallback) > 0) { 
                    foreach ($arr_items_fallback as $item) { 
                        $status_fallback = strtoupper($item['status'] ?? ''); $doc_fallback = preg_replace('/\D/', '', $item['documentNumber'] ?? $item['borrowerDocumentNumber'] ?? $item['cpf'] ?? '');
                        if ($doc_fallback === $cpf && !in_array($status_fallback, ['REJECTED', 'DENIED', 'CANCELED', 'ERROR'])) { $consult_id = $item['id'] ?? null; $fonte_usada = 'REAPROVEITADA'; break; } 
                    } 
                }
                if (!$consult_id) { throw new Exception("Consulta presa na V8, mas o ID não foi localizado."); }
            } elseif ($http1 < 200 || $http1 >= 300 || !$consult_id) { throw new Exception("ERRO V8 (HTTP {$http1}): " . $res1); }

            $url_auth = $URL_CONSULTA . '/' . $consult_id . '/authorize';
            $ch_auth = curl_init($url_auth); curl_setopt($ch_auth, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_auth, CURLOPT_POST, true); curl_setopt($ch_auth, CURLOPT_POSTFIELDS, json_encode([])); curl_setopt($ch_auth, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
            $res_auth = curl_exec($ch_auth); $http_auth = curl_getinfo($ch_auth, CURLINFO_HTTP_CODE); curl_close($ch_auth);

            gravarLogManual($cpf, 'FASE 1.1: AUTORIZAR (MANUAL)', $url_auth, [], json_decode($res_auth, true) ?? $res_auth, $http_auth);

            if ($http_auth >= 400 && $http_auth != 409) { 
                if (strpos($res_auth, 'consult_already_approved') === false) { throw new Exception("ERRO AO AUTORIZAR (HTTP {$http_auth}): " . $res_auth); }
            }

            $stmtEmpNew = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtEmpNew->execute([$usuario_logado_cpf]);
            $empresa_id_novo_reg = $stmtEmpNew->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTROCONSULTA (CONSULT_ID, CPF_CONSULTADO, CHAVE_ID, USUARIO_ID, CPF_USUARIO, STATUS_V8, NOME_COMPLETO, DATA_NASCIMENTO, EMAIL, TELEFONE, FONTE_CONSULT_ID, EMPRESA_ID) VALUES (?, ?, ?, ?, ?, 'AGUARDANDO MARGEM', ?, ?, ?, ?, ?, ?)")->execute([$consult_id, $cpf, $chave_id, $usuario_logado_id, $usuario_logado_cpf, $nome, $nascimento, $email, $telefone, $fonte_usada, $empresa_id_novo_reg]);
            
            // =====================================================================
            // EFETUA A COBRANÇA (DESCONTO DO SALDO) NO MOMENTO DO CONSENTIMENTO
            // =====================================================================
            if ($custo > 0) {
                $saldo_atualizado = $saldo - $custo;
                // 1. Atualiza o saldo final da Chave
                $pdo->prepare("UPDATE INTEGRACAO_V8_CHAVE_ACESSO SET SALDO = ? WHERE ID = ?")->execute([$saldo_atualizado, $chave_id]);
                
                // 2. Grava a movimentação no Extrato
                $motivo = "CONSENTIMENTO MANUAL - CPF {$cpf}";
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_EXTRATO_CLIENTE (CHAVE_ID, TIPO_MOVIMENTO, TIPO_CUSTO, VALOR, CUSTO_V8, SALDO_ANTERIOR, SALDO_ATUAL, DATA_LANCAMENTO) VALUES (?, 'DEBITO', ?, ?, ?, ?, ?, NOW())")->execute([$chave_id, $motivo, $custo, (float)$cli['CUSTO_V8'], $saldo, $saldo_atualizado]);
            }
            // =====================================================================

            echo json_encode(['success' => true, 'msg' => 'Consentimento criado e autorizado!', 'id_fila' => $pdo->lastInsertId(), 'cpf' => $cpf ]); break;

        case 'checar_margem_e_simular':
            $id_fila = (int)$_POST['id_fila'];
            $stmt = $pdo->prepare("SELECT CONSULT_ID, CHAVE_ID, CPF_CONSULTADO FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE ID = ?"); $stmt->execute([$id_fila]); $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fila || empty($fila['CONSULT_ID'])) throw new Exception("Consulta inválida ou sem ID de consentimento.");

            $stmtChv = $pdo->prepare("SELECT TABELA_PADRAO, PRAZO_PADRAO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); $stmtChv->execute([$fila['CHAVE_ID']]); $dadosChave = $stmtChv->fetch(PDO::FETCH_ASSOC);
            $nome_tabela_padrao = !empty($dadosChave['TABELA_PADRAO']) ? $dadosChave['TABELA_PADRAO'] : 'CLT Acelera'; $prazo_padrao_simulacao = !empty($dadosChave['PRAZO_PADRAO']) ? (int)$dadosChave['PRAZO_PADRAO'] : 24;

            $token = gerarTokenV8($fila['CHAVE_ID'], $pdo, $URL_AUTENTICACAO); $consult_id = $fila['CONSULT_ID']; $cpf_cliente = $fila['CPF_CONSULTADO'];

            $url_lista = $URL_CONSULTA . "?borrowerDocumentNumber=" . $cpf_cliente . "&limit=10&page=1";
            $ch_lista = curl_init($url_lista); curl_setopt($ch_lista, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_lista, CURLOPT_HTTPGET, true); curl_setopt($ch_lista, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            $res_lista = curl_exec($ch_lista); $http_lista = curl_getinfo($ch_lista, CURLINFO_HTTP_CODE); curl_close($ch_lista);
            $json_lista = json_decode($res_lista, true);
            
            gravarLogManual($cpf_cliente, 'FASE 2: BUSCAR STATUS (MANUAL)', $url_lista, "GET", $json_lista, $http_lista);

            $arr_items = $json_lista['data'] ?? $json_lista['items'] ?? $json_lista['content'] ?? (isset($json_lista[0]) ? $json_lista : []);
            $consulta_atual = null; if(is_array($arr_items)) { foreach ($arr_items as $item) { if (isset($item['id']) && $item['id'] === $consult_id) { $consulta_atual = $item; break; } } }

            if (!$consulta_atual) {
                $ch_status = curl_init($URL_CONSULTA . '/' . $consult_id); curl_setopt($ch_status, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_status, CURLOPT_HTTPGET, true); curl_setopt($ch_status, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
                $res_status = curl_exec($ch_status); $http_status = curl_getinfo($ch_status, CURLINFO_HTTP_CODE); curl_close($ch_status); $consulta_atual = json_decode($res_status, true);
                gravarLogManual($cpf_cliente, 'FASE 2: BUSCAR STATUS DIRETO (MANUAL)', $URL_CONSULTA . '/' . $consult_id, "GET", $consulta_atual, $http_status);
            }

            $status_api = strtoupper($consulta_atual['status'] ?? '');

            if (in_array($status_api, ['PROCESSING', 'PENDING', 'WAITING', 'WAITING_CONSULT', 'ANALYZING', 'IN_PROGRESS', 'PENDING_CONSULTATION', 'CONSENT_APPROVED'])) {
                echo json_encode(['success' => true, 'status' => 'pendente', 'msg' => 'Dataprev processando...']); exit;
            }

            if (!in_array($status_api, ['SUCCESS', 'COMPLETED', 'WAITING_CREDIT_ANALYSIS', 'APPROVED', 'PRE_APPROVED'])) {
                $erroMsg = $consulta_atual['detail'] ?? $consulta_atual['status_description'] ?? 'Rejeitado pela Dataprev';
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO-MARGEM', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$erroMsg, $id_fila]);
                echo json_encode(['success' => false, 'status' => 'erro', 'msg' => $erroMsg]); exit;
            }

            $margem = $consulta_atual['availableMargin'] ?? $consulta_atual['marginBaseValue'] ?? $consulta_atual['availableMarginValue'] ?? $consulta_atual['maxAmount'] ?? 0;

            $url_configs = $URL_CONFIGS . "?consult_id=" . urlencode($consult_id);
            $ch_cfg = curl_init($url_configs); curl_setopt($ch_cfg, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_cfg, CURLOPT_HTTPGET, true); curl_setopt($ch_cfg, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            $res_cfg = curl_exec($ch_cfg); $http_cfg = curl_getinfo($ch_cfg, CURLINFO_HTTP_CODE); curl_close($ch_cfg); $json_cfg = json_decode($res_cfg, true);
            
            gravarLogManual($cpf_cliente, 'FASE 2.1: BUSCAR TABELAS (MANUAL)', $url_configs, "GET", $json_cfg, $http_cfg);

            $config_id = null; $prazos = [24, 36, 48, 60, 72, 84]; $lista_configs = $json_cfg['configs'] ?? (isset($json_cfg[0]['id']) ? $json_cfg : []); $nome_tabela_salvar = $nome_tabela_padrao;

            if (is_array($lista_configs) && count($lista_configs) > 0) { 
                $tabela_desejada = trim(strtolower($nome_tabela_padrao)); $quer_seguro = (strpos($tabela_desejada, 'seguro') !== false);
                foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if ($nomeSlug === $tabela_desejada) { $config_id = $cfg['id']; $prazos = $cfg['number_of_installments'] ?? $prazos; $nome_tabela_salvar = $cfg['name'] ?? $cfg['slug']; break; } } 
                if (!$config_id) { foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if (strpos($nomeSlug, $tabela_desejada) !== false) { $tem_seguro = (strpos($nomeSlug, 'seguro') !== false); if (!$quer_seguro && $tem_seguro) continue; $config_id = $cfg['id']; $prazos = $cfg['number_of_installments'] ?? $prazos; $nome_tabela_salvar = $cfg['name'] ?? $cfg['slug']; break; } } }
                if (!$config_id) { foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); $tem_seguro = (strpos($nomeSlug, 'seguro') !== false); if (!$quer_seguro && $tem_seguro) continue; $config_id = $cfg['id']; $prazos = $cfg['number_of_installments'] ?? $prazos; $nome_tabela_salvar = $cfg['name'] ?? $cfg['slug']; break; } } 
                if (!$config_id) { $config_id = $lista_configs[0]['id']; $prazos = $lista_configs[0]['number_of_installments'] ?? $prazos; $nome_tabela_salvar = $lista_configs[0]['name'] ?? $lista_configs[0]['slug']; }
            }

            if (!$config_id) { $config_id = $consult_id; }

            $ch_sim = curl_init($URL_SIMULACAO_MARGEM); $margem_float = (float)$margem;
            $payload_sim = [ 'consult_id' => $consult_id, 'config_id' => $config_id, 'number_of_installments' => $prazo_padrao_simulacao ];
            if ($margem_float > 0) { $payload_sim['installment_face_value'] = $margem_float; }
            
            curl_setopt($ch_sim, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_sim, CURLOPT_POST, true); curl_setopt($ch_sim, CURLOPT_POSTFIELDS, json_encode($payload_sim)); curl_setopt($ch_sim, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
            $res_sim = curl_exec($ch_sim); $http_sim = curl_getinfo($ch_sim, CURLINFO_HTTP_CODE); curl_close($ch_sim);
            $json_sim = json_decode($res_sim, true);

            gravarLogManual($cpf_cliente, 'FASE 3: SIMULACAO PADRAO (MANUAL)', $URL_SIMULACAO_MARGEM, $payload_sim, $json_sim, $http_sim);

            $sim_obj = isset($json_sim[0]) ? $json_sim[0] : $json_sim;
            $sim_id = $sim_obj['id_simulation'] ?? $sim_obj['id'] ?? null;
            $valor_liberado = $sim_obj['disbursement_amount'] ?? $sim_obj['disbursed_amount'] ?? $sim_obj['operation_amount'] ?? 0;
            $valor_parcela_real = $sim_obj['installment_value'] ?? $sim_obj['installment_face_value'] ?? $margem_float;

            if (!$sim_id || $http_sim >= 400) { $sim_id = 'ERRO-TABELA'; }

            $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, MARGEM_DISPONIVEL, PRAZOS_DISPONIVEIS, TIPO_SIMULACAO, SIMULATION_ID, STATUS_SIMULATION_ID, DATA_SIMULATION_ID, STATUS_CONFIG_ID, VALOR_LIBERADO, VALOR_PARCELA, PRAZO_SIMULACAO) VALUES (?, ?, ?, ?, ?, ?, 'PADRÃO', ?, 'SIMULACAO OK', NOW(), 'MARGEM OK', ?, ?, ?)")->execute([$id_fila, $cpf_cliente, $config_id, $nome_tabela_salvar, $margem_float, json_encode($prazos), $sim_id, (float)$valor_liberado, (float)$valor_parcela_real, $prazo_padrao_simulacao]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'OK-SIMULACAO', ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$id_fila]);

            echo json_encode(['success' => true, 'status' => 'concluido', 'margem' => $margem, 'msg' => 'Sucesso!']); break;

        case 'reenviar_autorizacao_automatica':
            $id_fila = (int)($_POST['id_fila'] ?? 0);
            $stmtCli = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE ID = ?"); $stmtCli->execute([$id_fila]); $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
            $chave_id = $cliente['CHAVE_ID']; $token = gerarTokenV8($chave_id, $pdo, $URL_AUTENTICACAO); $consult_id = $cliente['CONSULT_ID'];
            if(!$consult_id) throw new Exception("Não existe ID.");
            $url_auto = $URL_CONSULTA . '/' . $consult_id . '/authorize';
            $ch_auto = curl_init($url_auto); curl_setopt($ch_auto, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch_auto, CURLOPT_POST, true); curl_setopt($ch_auto, CURLOPT_POSTFIELDS, json_encode([])); curl_setopt($ch_auto, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
            $res_auto = curl_exec($ch_auto); $http_auto = curl_getinfo($ch_auto, CURLINFO_HTTP_CODE); curl_close($ch_auto);
            
            gravarLogManual($cliente['CPF_CONSULTADO'], 'REENVIO AUTORIZACAO (MANUAL)', $url_auto, [], json_decode($res_auto, true) ?? $res_auto, $http_auto);

            if ($http_auto >= 400 && $http_auto != 409) {
                if (strpos($res_auto, 'consult_already_approved') !== false) { $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'AGUARDANDO MARGEM', ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$id_fila]); echo json_encode(['success' => true, 'msg' => 'Avançando!']); exit; }
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO-AUT', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$res_auto, $id_fila]); echo json_encode(['success' => false, 'status' => 'ERRO-AUT', 'msg' => "Falha."]); exit;
            }
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'AGUARDANDO MARGEM', ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$id_fila]);
            echo json_encode(['success' => true]); break;

        case 'apagar_consulta_manual':
            $id_fila = (int)$_POST['id_fila'];
            $pdo->prepare("DELETE FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE ID = ?")->execute([$id_fila]);
            echo json_encode(['success' => true, 'msg' => 'Consulta removida da fila.']); break;

        case 'reiniciar_consulta_fila':
            $id_fila = (int)$_POST['id_fila'];
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'SOLICITADO', DATA_FILA = NOW(), VALOR_MARGEM = NULL, DATA_RETORNO_V8 = NULL, CONSULT_ID = NULL WHERE ID = ?")->execute([$id_fila]);
            echo json_encode(['success' => true, 'msg' => 'Reiniciada.']); break;

        case 'parar_fluxo':
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = ?, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([($_POST['motivo'] ?? 'PARADO PELO USUARIO'), (int)$_POST['id_fila']]);
            echo json_encode(['success' => true]); break;

        default: echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']); break;
    }
} catch (Exception $e) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } echo json_encode(['success' => false, 'msg' => $e->getMessage()]); }
?>