<?php
ob_start(); 
ini_set('display_errors', 0); 
error_reporting(0); 
session_start(); 
header('Content-Type: application/json; charset=utf-8');

try {
    $caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!file_exists($caminho_conexao)) { throw new Exception("Arquivo de conexão não encontrado."); }
    require_once $caminho_conexao;
    
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4");
    
    $acao = $_POST['acao'] ?? '';
    
    // PEGANDO O CPF DO USUÁRIO LOGADO
    $usuario_logado_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

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
            if ($fp) fclose($fp); throw new Exception("A fila desta credencial está ocupada.");
        }
    }

    function gerarTokenV8($chave_id, $pdo) {
        $stmt = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); $stmt->execute([$chave_id]); $chave = $stmt->fetch(PDO::FETCH_ASSOC);
        $payload = http_build_query([ 'grant_type' => 'password', 'username' => $chave['USERNAME_API'], 'password' => $chave['PASSWORD_API'], 'audience' => $chave['AUDIENCE'], 'client_id' => $chave['CLIENT_ID'], 'scope' => 'offline_access' ]);
        $api_call = v8_api_request_with_lock("https://auth.v8sistema.com/oauth/token", 'POST', $payload, ['Content-Type: application/x-www-form-urlencoded'], $chave_id);
        $json = json_decode($api_call['response'], true);
        if (isset($json['access_token'])) return $json['access_token']; 
        throw new Exception("Falha ao autenticar na V8.");
    }

    switch ($acao) {
        
        case 'buscar_dados_cliente_db':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? ''); if (empty($cpf)) { ob_end_clean(); echo json_encode(['success' => false]); exit; }
            $res = ['cadastrais' => [], 'enderecos' => [], 'telefones' => [], 'emails' => []];
            try {
                $stmt1 = $pdo->prepare("SELECT * FROM dados_cadastrais WHERE cpf = ?"); $stmt1->execute([$cpf]); $res['cadastrais'] = $stmt1->fetch(PDO::FETCH_ASSOC) ?: [];
                $stmt2 = $pdo->prepare("SELECT * FROM enderecos WHERE cpf = ?"); $stmt2->execute([$cpf]); $res['enderecos'] = $stmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $stmt3 = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ?"); $stmt3->execute([$cpf]); $res['telefones'] = $stmt3->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $stmt4 = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 1"); $stmt4->execute([$cpf]); $res['emails'] = $stmt4->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}
            ob_end_clean(); echo json_encode(['success' => true, 'dados' => $res]); exit;

        case 'passo4_simular': 
            $id_fila = (int)$_POST['id_fila']; $tipo = $_POST['tipo']; $prazo_req = (int)($_POST['prazo'] ?? 0); $valor_req = (float)($_POST['valor'] ?? 0); $tipo_busca = $_POST['tipo_busca'] ?? 'PARCELA';

            $stmtConfig = $pdo->prepare("SELECT CONFIG_ID, NOME_TABELA, DATA_CONFIG_ID, MARGEM_DISPONIVEL, PRAZOS_DISPONIVEIS FROM INTEGRACAO_V8_REGISTRO_SIMULACAO WHERE ID_FILA = ? AND CONFIG_ID IS NOT NULL ORDER BY ID ASC LIMIT 1"); $stmtConfig->execute([$id_fila]); $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
            if(!$config) throw new Exception("Configurações não carregadas. Atualize a margem.");

            $stmt = $pdo->prepare("SELECT CHAVE_ID, CONSULT_ID, CPF_CONSULTADO FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE ID = ?"); $stmt->execute([$id_fila]); $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            $chave_id = $fila['CHAVE_ID']; $token = gerarTokenV8($chave_id, $pdo);

            $prazo = 24; $valor_parcela_base = (float)$config['MARGEM_DISPONIVEL'];
            if ($tipo === 'PERSONALIZADA') { $prazo = $prazo_req; } else { $arrPrazos = json_decode($config['PRAZOS_DISPONIVEIS'], true); if (is_array($arrPrazos) && !in_array(24, $arrPrazos)) { $prazo = max($arrPrazos); } }

            $payload = [ 'consult_id' => $fila['CONSULT_ID'], 'config_id' => $config['CONFIG_ID'], 'number_of_installments' => $prazo ];
            if ($tipo === 'PERSONALIZADA') { if ($tipo_busca === 'TOTAL') { $payload['disbursed_amount'] = $valor_req; } else { $payload['installment_face_value'] = $valor_req; } } else { if ($valor_parcela_base > 0) { $payload['installment_face_value'] = $valor_parcela_base; } }

            $url_sim = "https://bff.v8sistema.com/private-consignment/simulation";
            
            // =========================================================================
            // LOOP INTELIGENTE DE RETENTATIVA DA SIMULAÇÃO
            // =========================================================================
            $tentativas = 0;
            $max_tentativas = 5;
            $sim_res = null;
            $http_sim = 0;
            $observacao_simulacao = 'Cálculo concluído';

            while ($tentativas < $max_tentativas) {
                $api_sim = v8_api_request_with_lock($url_sim, 'POST', $payload, ["Authorization: Bearer $token", "Content-Type: application/json"], $chave_id);
                $sim_res = json_decode($api_sim['response'], true); 
                $http_sim = $api_sim['http_code'];

                if ($http_sim < 400 && !empty($sim_res)) {
                    break; // Sucesso na simulação!
                }

                $erroMsg = strtolower($sim_res['detail'] ?? $sim_res['message'] ?? "Erro ao simular.");

                if (strpos($erroMsg, '50000') !== false) {
                    $observacao_simulacao = 'O valor solicitado não pode ser maior que 50000.';
                    unset($payload['installment_face_value']);
                    $payload['disbursed_amount'] = 50000;
                    $tentativas++;
                    continue;
                } elseif (strpos($erroMsg, 'desembolso') !== false) {
                    $observacao_simulacao = 'A simulação não passou nos critérios de elegibilidade por valor mínimo de desembolso.';
                    $sim_res = [['disbursement_amount' => 0, 'installment_value' => 0, 'id_simulation' => 'SIM_ZERADA']];
                    $http_sim = 200; // Forçamos sucesso para salvar zerado na tela
                    break;
                } elseif (strpos($erroMsg, 'margem dispon') !== false || strpos($erroMsg, 'maior que a margem') !== false) {
                    $observacao_simulacao = 'O valor da parcela não pode ser maior que a margem (parcela enquadrada em lup 10%) - Ajustado R.O';
                    if (isset($payload['installment_face_value'])) {
                        $payload['installment_face_value'] = round($payload['installment_face_value'] * 0.90, 2);
                    }
                    $tentativas++;
                    continue;
                } else {
                    break; // Erro desconhecido, sai do loop e processa como erro normal
                }
            }
            // =========================================================================

            if ($http_sim >= 400 || empty($sim_res)) {
                $erroMsgFinal = $sim_res['detail'] ?? $sim_res['message'] ?? "Erro ao simular.";
                $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, DATA_CONFIG_ID, MARGEM_DISPONIVEL, PRAZOS_DISPONIVEIS, TIPO_SIMULACAO, PRAZO_SIMULACAO, STATUS_SIMULATION_ID, OBS_SIMULATION_ID, DATA_SIMULATION_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ERRO SIMULACAO', ?, NOW())")->execute([$id_fila, $fila['CPF_CONSULTADO'], $config['CONFIG_ID'], $config['NOME_TABELA'], $config['DATA_CONFIG_ID'], $config['MARGEM_DISPONIVEL'], $config['PRAZOS_DISPONIVEIS'], $tipo, $prazo, $erroMsgFinal]);
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => $erroMsgFinal]); exit;
            }

            $sim_obj = isset($sim_res[0]) ? $sim_res[0] : $sim_res;
            $sim_id = $sim_obj['id_simulation'] ?? $sim_obj['id'] ?? null;
            $valor_liberado = $sim_obj['disbursement_amount'] ?? $sim_obj['disbursed_amount'] ?? $sim_obj['operation_amount'] ?? 0;
            $valor_parcela_real = $sim_obj['installment_value'] ?? $sim_obj['installment_face_value'] ?? ($tipo_busca === 'PARCELA' ? $valor_req : 0);
            $margem_final = $valor_parcela_base > 0 ? $valor_parcela_base : $valor_parcela_real;

            $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_SIMULACAO (ID_FILA, CPF, CONFIG_ID, NOME_TABELA, DATA_CONFIG_ID, MARGEM_DISPONIVEL, PRAZOS_DISPONIVEIS, TIPO_SIMULACAO, SIMULATION_ID, STATUS_SIMULATION_ID, OBS_SIMULATION_ID, VALOR_LIBERADO, VALOR_PARCELA, PRAZO_SIMULACAO, DATA_SIMULATION_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'SIMULACAO OK', ?, ?, ?, ?, NOW())")->execute([$id_fila, $fila['CPF_CONSULTADO'], $config['CONFIG_ID'], $config['NOME_TABELA'], $config['DATA_CONFIG_ID'], (float)$margem_final, $config['PRAZOS_DISPONIVEIS'], $tipo, $sim_id, $observacao_simulacao, (float)$valor_liberado, (float)$valor_parcela_real, $prazo]);
            if ($tipo === 'PADRÃO') { $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'OK-SIMULACAO' WHERE ID = ?")->execute([$id_fila]); }
            
            ob_end_clean(); echo json_encode(['success' => true, 'margem' => (float)$margem_final, 'prazo' => $prazo, 'tabela' => $config['NOME_TABELA'], 'valor_liberado' => (float)$valor_liberado]); exit;

        case 'listar_simulacoes_banco':
            $id_fila = (int)$_POST['id_fila'];
            $stmt = $pdo->prepare("SELECT ID, TIPO_SIMULACAO, PRAZO_SIMULACAO, VALOR_PARCELA, VALOR_LIBERADO, DATE_FORMAT(DATA_SIMULATION_ID, '%d/%m %H:%i') as DATA_BR FROM INTEGRACAO_V8_REGISTRO_SIMULACAO WHERE ID_FILA = ? AND SIMULATION_ID IS NOT NULL AND STATUS_SIMULATION_ID = 'SIMULACAO OK' ORDER BY ID DESC"); $stmt->execute([$id_fila]);
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;

        case 'passo5_enviar_proposta':
            $id_fila = (int)$_POST['id_fila']; $id_sim_banco = (int)$_POST['id_sim_banco']; $pix = trim($_POST['pix']); $tipo_pix_front = strtoupper(trim($_POST['tipo_pix'])); $obs = trim($_POST['obs'] ?? '');
            if (!$id_sim_banco || empty($pix)) { throw new Exception("Simulação não selecionada ou PIX em branco."); }

            $stmtFila = $pdo->prepare("SELECT CHAVE_ID, NOME_COMPLETO, CPF_CONSULTADO, DATA_NASCIMENTO FROM INTEGRACAO_V8_REGISTROCONSULTA WHERE ID = ?"); $stmtFila->execute([$id_fila]); $fila = $stmtFila->fetch(PDO::FETCH_ASSOC);
            $stmtSim = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_REGISTRO_SIMULACAO WHERE ID = ? AND ID_FILA = ?"); $stmtSim->execute([$id_sim_banco, $id_fila]); $simulacao = $stmtSim->fetch(PDO::FETCH_ASSOC);
            if (!$simulacao || empty($simulacao['SIMULATION_ID'])) { throw new Exception("ID de Simulação V8 não encontrado."); }

            $chave_id = $fila['CHAVE_ID']; $token = gerarTokenV8($chave_id, $pdo);
            $telLimpo = preg_replace('/\D/', '', $_POST['telefone'] ?? '11900000000'); if (strlen($telLimpo) < 10) { $telLimpo = '11900000000'; }
            $area_code = substr($telLimpo, 0, 2); $number = substr($telLimpo, 2); 
            $gender = (strtoupper($_POST['sexo'] ?? 'F') === 'M') ? 'male' : 'female';
            $rgLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $_POST['rg'] ?? ''); if(empty($rgLimpo)) $rgLimpo = '000000';

            $tipo_pix_api = 'cpf'; if ($tipo_pix_front === 'PHONE') $tipo_pix_api = 'phone'; if ($tipo_pix_front === 'EMAIL') $tipo_pix_api = 'email'; if ($tipo_pix_front === 'RANDOM') $tipo_pix_api = 'chave aleatória'; 

            $cpf_puro = preg_replace('/\D/', '', $fila['CPF_CONSULTADO']);
            $nome_digitado = mb_strtoupper($_POST['nome'] ?? $fila['NOME_COMPLETO'], 'UTF-8');
            $nasc_digitado = $_POST['nascimento'] ?? $fila['DATA_NASCIMENTO'];
            $mae_digitado = mb_strtoupper($_POST['nome_mae'] ?? '', 'UTF-8');
            $sexo_digitado = (strtoupper($_POST['sexo'] ?? 'F') === 'M') ? 'MASCULINO' : 'FEMININO';

            $sqlCad = "INSERT INTO dados_cadastrais (cpf, nome, nascimento, sexo, nome_mae, rg) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome = VALUES(nome), nascimento = VALUES(nascimento), sexo = VALUES(sexo), nome_mae = VALUES(nome_mae), rg = VALUES(rg)";
            $pdo->prepare($sqlCad)->execute([$cpf_puro, $nome_digitado, $nasc_digitado, $sexo_digitado, $mae_digitado, $rgLimpo]);

            if (strlen($telLimpo) >= 10) { $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)")->execute([$cpf_puro, $telLimpo]); }
            $email_digitado = strtolower(trim($_POST['email'] ?? ''));
            if (!empty($email_digitado) && $email_digitado !== 'cliente@gmail.com') { $stmtEmail = $pdo->prepare("SELECT id FROM emails WHERE cpf = ? AND email = ?"); $stmtEmail->execute([$cpf_puro, $email_digitado]); if (!$stmtEmail->fetch()) { $pdo->prepare("INSERT INTO emails (cpf, email) VALUES (?, ?)")->execute([$cpf_puro, $email_digitado]); } }
            $cep_digitado = preg_replace('/\D/', '', $_POST['cep'] ?? '');
            if (!empty($cep_digitado)) { $stmtEnd = $pdo->prepare("SELECT id FROM enderecos WHERE cpf = ? AND cep = ? AND numero = ?"); $stmtEnd->execute([$cpf_puro, $cep_digitado, $_POST['numero'] ?? '']); if (!$stmtEnd->fetch()) { $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, numero, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$cpf_puro, $_POST['logradouro'] ?? '', $_POST['numero'] ?? 'S/N', $_POST['bairro'] ?? '', $_POST['cidade'] ?? '', strtoupper($_POST['uf'] ?? ''), $cep_digitado]); } }

            $payload = [
                'simulation_id' => $simulacao['SIMULATION_ID'],
                'borrower' => [
                    'name' => $_POST['nome'] ?? $fila['NOME_COMPLETO'], 'email' => $_POST['email'] ?: 'cliente@gmail.com', 'phone' => [ 'country_code' => '55', 'area_code' => $area_code, 'number' => $number ], 'political_exposition' => false,
                    'address' => [ 'city' => $_POST['cidade'] ?? '', 'state' => strtoupper($_POST['uf'] ?? ''), 'number' => $_POST['numero'] ?? '0', 'street' => $_POST['logradouro'] ?? '', 'complement' => '', 'postal_code' => preg_replace('/\D/', '', $_POST['cep'] ?? ''), 'neighborhood' => $_POST['bairro'] ?? '' ],
                    'birth_date' => $_POST['nascimento'] ?? $fila['DATA_NASCIMENTO'], 'mother_name' => mb_strtoupper($_POST['nome_mae'] ?? 'NÃO INFORMADO', 'UTF-8'), 'nationality' => 'BR', 'document_issuer' => 'SSP', 'gender' => $gender, 'person_type' => 'natural', 'marital_status' => 'single', 'individual_document_number' => preg_replace('/\D/', '', $fila['CPF_CONSULTADO']), 'document_identification_date' => '2015-01-01', 'document_identification_type' => 'rg', 'document_identification_number' => $rgLimpo,
                    'bank' => [ 'transfer_method' => 'pix', 'pix_key' => $pix, 'pix_key_type' => $tipo_pix_api ]
                ]
            ];

            $url_prop = "https://bff.v8sistema.com/private-consignment/operation";
            $api_prop = v8_api_request_with_lock($url_prop, 'POST', $payload, ["Authorization: Bearer $token", "Content-Type: application/json"], $chave_id);
            $prop_res = json_decode($api_prop['response'], true); $http_prop = $api_prop['http_code'];

            if ($http_prop >= 400 || empty($prop_res)) {
                $erroMsg = $prop_res['detail'] ?? "Erro na V8.";
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'ERRO PROPOSTA', MENSAGEM_ERRO = ?, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute([$erroMsg, $id_fila]);
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => $erroMsg]); exit;
            }

            $proposal_id = $prop_res['id'] ?? $prop_res['proposal_id'] ?? 'PROPOSTA_' . time();
            $status_proposta_inicial = $prop_res['status'] ?? 'AGUARDANDO';
            $url_formalizacao = $prop_res['formalization_url'] ?? '';

            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = ?, MENSAGEM_ERRO = NULL, ULTIMA_ATUALIZACAO = NOW() WHERE ID = ?")->execute(['PROPOSTA: ' . $proposal_id, $id_fila]);
            
            $sqlInsertProposta = "INSERT INTO INTEGRACAO_V8_REGISTRO_PROPOSTA (CPF_USUARIO, CPF_CLIENTE, NOME_CLIENTE, CONSIG_ID, SIMULATION_CONSIG, NUMERO_PROPOSTA, PRAZO, PARCELA, VALOR_LIBERADO, OBSERVACAO_GERAL, STATUS_PROPOSTA_V8, LINK_PROPOSTA, DATA_STATUS, DATA_DIGITACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $pdo->prepare($sqlInsertProposta)->execute([$usuario_logado_cpf, $fila['CPF_CONSULTADO'], $_POST['nome'] ?? $fila['NOME_COMPLETO'], $simulacao['CONFIG_ID'], $simulacao['SIMULATION_ID'], $proposal_id, $simulacao['PRAZO_SIMULACAO'], $simulacao['VALOR_PARCELA'], $simulacao['VALOR_LIBERADO'], $obs, $status_proposta_inicial, $url_formalizacao]);
            $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTRO_PROPOSTA_HISTORICO (NUMERO_PROPOSTA, STATUS, DATA_STATUS, OBSERVACAO) VALUES (?, ?, NOW(), ?)")->execute([$proposal_id, $status_proposta_inicial, "Proposta criada."]);

            ob_end_clean(); echo json_encode(['success' => true, 'proposal_id' => $proposal_id, 'url' => $url_formalizacao]); exit;

        default: throw new Exception("Ação desconhecida.");
    }
} catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>