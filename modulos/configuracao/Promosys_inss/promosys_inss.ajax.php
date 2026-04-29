<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

ini_set('memory_limit', '-1');
ini_set('max_execution_time', 0);

include '../../../conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$acao = $_POST['acao'] ?? ($_GET['acao'] ?? '');

$pdo->exec("CREATE TABLE IF NOT EXISTS `promosys_config_api` (
  `ID` int NOT NULL DEFAULT '1',
  `TOKEN_MANUAL` varchar(255) DEFAULT '', 
  `TOKEN_LOTE` varchar(255) DEFAULT '',   
  `TOKEN_CSV` varchar(255) DEFAULT '',
  `TEMPO_CACHE` int DEFAULT '30',
  `WAPI_INSTANCE` varchar(100) DEFAULT '',
  `WAPI_TOKEN` varchar(255) DEFAULT '',
  `TOKEN_ROBO` varchar(255) DEFAULT '',
  `CMD_MENU` varchar(50) DEFAULT '#MENU_INSS',
  `CMD_CONSULTA` varchar(50) DEFAULT '#INSS:',
  `CMD_COMPLETO` varchar(50) DEFAULT '#INSS/COMPLETO:',
  `CMD_SALDO` varchar(50) DEFAULT '#INSS/SALDO',
  `CMD_EXTRATO` varchar(50) DEFAULT '#INSS/EXTRATO',
  `CMD_LISTA` varchar(50) DEFAULT '#INSS/CONSULTAS',
  `CMD_SUPORTE` varchar(50) DEFAULT '#SUPORTE',
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

try {
    $pdo->exec("ALTER TABLE promosys_config_api ADD COLUMN TEMPO_CACHE INT DEFAULT 30 AFTER TOKEN_CSV");
} catch (Exception $e) {}

$pdo->exec("INSERT IGNORE INTO promosys_config_api (ID) VALUES (1)");

// ========================================================================
// Resolução de empresa e permissões de hierarquia (HIST INSS)
// ========================================================================
$cpf_logado_pm       = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$id_usuario_logado_pm = (int)($_SESSION['usuario_id'] ?? 0);
$id_empresa_logado_pm = null;
try {
    if (!empty($cpf_logado_pm)) {
        $sepm = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $sepm->execute([$cpf_logado_pm]);
        $rowpm = $sepm->fetch(PDO::FETCH_ASSOC);
        if ($rowpm) $id_empresa_logado_pm = (int)$rowpm['id_empresa'];
    }
} catch (Exception $e) {}
$_caminho_perm_pm = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($_caminho_perm_pm)) include_once $_caminho_perm_pm;
$perm_meu_reg_inss = function_exists('verificaPermissao')       ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_HIST_INSS_MEU_REGISTRO', 'FUNCAO') : true;
$perm_hier_inss    = function_exists('verificaPermissaoEstrita') ? verificaPermissaoEstrita($pdo, 'SUBMENU_OP_INTEGRACAO_HIST_INSS_HIERARQUIA')       : true;
// ========================================================================

// ========================================================================
function obterTokenPromosys($pdo) {
    $stmt = $pdo->query("SELECT TOKEN_MANUAL as usuario, TOKEN_LOTE as senha FROM promosys_config_api WHERE ID = 1");
    $credenciais = $stmt->fetch(PDO::FETCH_ASSOC);

    if(empty($credenciais['usuario']) || empty($credenciais['senha'])) {
        return ['success' => false, 'msg' => 'Usuário ou Senha da API não configurados.'];
    }

    $url = "https://jcf.promosysweb.com/services/token.php";
    $postData = http_build_query(['usuario' => $credenciais['usuario'], 'senha' => $credenciais['senha']]);
    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch); $erro = curl_error($ch); curl_close($ch);

    if ($erro) { return ['success' => false, 'msg' => 'Erro de conexão com a API: ' . $erro]; }
    $data = json_decode($response, true);
    if (isset($data['Code']) && $data['Code'] === "000" && !empty($data['Token'])) { return ['success' => true, 'token' => $data['Token']]; } 
    else { return ['success' => false, 'msg' => 'Falha na autenticação da API: ' . ($data['Msg'] ?? 'Erro desconhecido')]; }
}

// ========================================================================
if ($acao === 'listar_clientes') {
    $busca              = trim($_POST['busca'] ?? '');
    $busca_usuario_id   = (int)($_POST['busca_usuario_id'] ?? 0);
    $busca_usuario_cpf  = preg_replace('/\D/', '', $_POST['busca_usuario_cpf'] ?? '');
    $sql = "SELECT cc.CPF, cc.NOME, cc.NOME_EMPRESA, cc.CUSTO_CONSULTA, cc.SALDO, cc.GRUPO_WHATS,
                   COALESCE(cu.NOME, cc.NOME) as NOME_USUARIO,
                   cu.ID as USUARIO_ID
            FROM CLIENTE_CADASTRO cc
            LEFT JOIN CLIENTE_USUARIO cu ON cu.CPF = cc.CPF";
    $params = [];
    $busca_direta = false;
    if ($busca_usuario_id > 0) {
        $sql .= " WHERE cu.ID = ?";
        $params = [$busca_usuario_id];
        $busca_direta = true;
    } elseif (!empty($busca_usuario_cpf)) {
        $sql .= " WHERE cc.CPF = ?";
        $params = [$busca_usuario_cpf];
        $busca_direta = true;
    } elseif (!empty($busca)) {
        $sql .= " WHERE cc.NOME LIKE ? OR cc.CPF LIKE ? OR cc.NOME_EMPRESA LIKE ? OR cu.NOME LIKE ?";
        $like = '%' . $busca . '%';
        $params = [$like, $like, $like, $like];
    }
    $sql .= " ORDER BY cc.NOME ASC" . ($busca_direta ? "" : " LIMIT 30");
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($acao === 'salvar_dados_cliente') { $cpf = $_POST['cpf']; $custo = str_replace(',', '.', $_POST['custo']); $grupo = trim($_POST['grupo']); $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CUSTO_CONSULTA = ?, GRUPO_WHATS = ? WHERE CPF = ?")->execute([$custo, $grupo, $cpf]); echo json_encode(['success' => true, 'msg' => 'Cliente atualizado com sucesso!']); exit; }
if ($acao === 'movimentar_saldo') {
    $cpf = $_POST['cpf']; $tipo = $_POST['tipo']; $valor = (float)$_POST['valor']; $motivo = trim($_POST['motivo']);
    if ($valor <= 0) { echo json_encode(['success' => false, 'msg' => 'Valor inválido.']); exit; }
    $pdo->beginTransaction();
    $stmtCli = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ? FOR UPDATE"); $stmtCli->execute([$cpf]); $saldo_anterior = (float)$stmtCli->fetchColumn();
    $saldo_atual = ($tipo === 'CREDITO') ? ($saldo_anterior + $valor) : ($saldo_anterior - $valor);
    $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atual, $cpf]);
    $pdo->prepare("INSERT INTO PROMOSYS_INSS_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, ?, ?, ?, ?, ?)")->execute([$cpf, $tipo, $motivo, $valor, $saldo_anterior, $saldo_atual]);
    $pdo->commit();
    echo json_encode(['success' => true, 'msg' => 'Saldo movimentado com sucesso!']); exit;
}
if ($acao === 'carregar_extrato') { $stmt = $pdo->prepare("SELECT DATE_FORMAT(DATA_HORA, '%d/%m/%Y %H:%i') as DATA_FORMATADA, TIPO, VALOR, SALDO_ATUAL, MOTIVO FROM PROMOSYS_INSS_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? ORDER BY DATA_HORA DESC LIMIT 50"); $stmt->execute([$_POST['cpf']]); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit; }

// ========================================================================
// CONSULTA MANUAL E SPLIT DE JSON
// ========================================================================
if ($acao === 'consulta_cpf_manual') {
    $cpf_alvo = preg_replace('/\D/', '', $_POST['cpf']);
    $cpf_cobrar = preg_replace('/\D/', '', $_POST['cpf_cobrar']);
    $forcar_api = isset($_POST['forcar_api']) && $_POST['forcar_api'] == '1';

    if (empty($cpf_alvo) || empty($cpf_cobrar)) { echo json_encode(['success' => false, 'msg' => 'CPF alvo ou de cobrança inválidos.']); exit; }

    $tempo_cache = (int)$pdo->query("SELECT TEMPO_CACHE FROM promosys_config_api WHERE ID = 1")->fetchColumn();
    
    if (!$forcar_api && $tempo_cache > 0) {
        $stmtCache = $pdo->prepare("SELECT nome, data_atualizacao FROM banco_de_Dados_inss_dados_cadastrais WHERE cpf = ? ORDER BY data_atualizacao DESC LIMIT 1");
        $stmtCache->execute([$cpf_alvo]);
        $cacheData = $stmtCache->fetch(PDO::FETCH_ASSOC);

        if ($cacheData && !empty($cacheData['data_atualizacao'])) {
            $dias_diff = (strtotime(date('Y-m-d H:i:s')) - strtotime($cacheData['data_atualizacao'])) / (60 * 60 * 24);
            if ($dias_diff <= $tempo_cache) {
                $nomeCache = $pdo->query("SELECT nome FROM dados_cadastrais WHERE cpf = '$cpf_alvo'")->fetchColumn() ?: 'Nome não localizado';
                echo json_encode([
                    'success' => true,
                    'origem' => 'CACHE LOCAL (' . round($dias_diff) . ' dias)',
                    'dados' => ['nome' => $nomeCache],
                    'json_bruto' => ['Msg' => 'Os dados foram carregados diretamente do seu Banco de Dados, pois uma consulta recente já foi feita para este CPF. Nenhuma tarifa foi cobrada no saldo do cliente.'],
                    'cobranca' => null 
                ]);
                exit;
            }
        }
    }

    $stmtCli = $pdo->prepare("SELECT NOME, SALDO, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = ?");
    $stmtCli->execute([$cpf_cobrar]);
    $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);
    if (!$cliente) { echo json_encode(['success' => false, 'msg' => 'Cliente de cobrança não encontrado.']); exit; }
    
    $custo = (float)$cliente['CUSTO_CONSULTA'];
    $saldo_atual = (float)$cliente['SALDO'];
    
    if ($saldo_atual < $custo) {
        echo json_encode(['success' => false, 'msg' => "Saldo insuficiente. Custo: R$ {$custo} | Saldo: R$ {$saldo_atual}"]); exit;
    }

    $resToken = obterTokenPromosys($pdo);
    if (!$resToken['success']) { echo json_encode($resToken); exit; }
    $token = $resToken['token'];

    $url = "https://jcf.promosysweb.com/services/consultaCpfOffline.php";
    $postData = http_build_query(['token' => $token, 'cpf' => $cpf_alvo]);

    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 40);
    $response = curl_exec($ch); $erro = curl_error($ch); curl_close($ch);

    if ($erro) { echo json_encode(['success' => false, 'msg' => 'CURL_ERROR: ' . $erro]); exit; }

    $json_bruto = json_decode($response, true);

    $dir_logs = '/var/www/html/logs_consulta/logs_promosys/';
    if (!is_dir($dir_logs)) { @mkdir($dir_logs, 0777, true); }
    $nome_arquivo_txt = $dir_logs . "log_cpf_" . $cpf_alvo . "_" . time() . "_manual.txt";
    @file_put_contents($nome_arquivo_txt, json_encode($json_bruto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    if (isset($json_bruto['Code']) && $json_bruto['Code'] === "000" && isset($json_bruto['Consulta'])) {
        
        $consultas_array = [];
        if (is_array($json_bruto['Consulta'])) {
            if (isset($json_bruto['Consulta']['BENEFICIO']) || isset($json_bruto['Consulta']['NOME'])) {
                $consultas_array = [$json_bruto['Consulta']]; 
            } else {
                $consultas_array = $json_bruto['Consulta']; 
            }
        }

        $sucesso_inss = false;
        $nome_global = 'Nome Nao Informado';

        $pdo->beginTransaction();

        foreach ($consultas_array as $consulta) {
            $beneficio = $consulta['BENEFICIO'] ?? [];
            $bancario = $consulta['DADOS_BANCARIOS'] ?? [];
            $contratos = $consulta['CONTRATO'] ?? [];
            $nome_global = $consulta['NOME'] ?? $nome_global;
            $matricula_nb = $beneficio['nb'] ?? null;

            if (!empty($matricula_nb)) {
                $sucesso_inss = true;
                
                $desc_contrib = is_array($beneficio['DescontoContribuicao'] ?? null) ? ($beneficio['DescontoContribuicao']['Descricao'] ?? '') : '';
                $val_contrib = is_array($beneficio['DescontoContribuicao'] ?? null) ? (float)($beneficio['DescontoContribuicao']['Valor'] ?? 0) : 0;
                
                $rep_cpf = is_array($consulta['REPRESENTANTE'] ?? null) ? ($consulta['REPRESENTANTE']['CPF'] ?? '') : '';
                $rep_nome = is_array($consulta['REPRESENTANTE'] ?? null) ? ($consulta['REPRESENTANTE']['NOME'] ?? '') : '';

                $banco_completo = is_array($bancario) ? ($bancario['BANCO_COMPLETO'] ?? '') : '';
                $agencia_completo = is_array($bancario) ? ($bancario['AGENCIA_COMPLETO'] ?? '') : '';
                $conta_pagto = is_array($bancario) ? ($bancario['CONTA_PAGTO'] ?? '') : '';
                $dib = is_array($bancario) ? ($bancario['DIB_FORMATADO'] ?? '') : '';
                
                $forma_pagto = is_array($bancario) ? ($bancario['NOME_TIPO_PAGTO'] ?? '') : '';
                if (empty($forma_pagto)) { $forma_pagto = is_array($beneficio) ? ($beneficio['FormaPagamento'] ?? '') : ''; }

                $contratos_atualizados_ate = $consulta['ContratosAtualizadosAte'] ?? null;

                $sql = "INSERT INTO banco_de_Dados_inss_dados_cadastrais (
                    cpf, matricula_nb, especie_beneficio, esp_consignavel, situacao_beneficio, bloqueio_emprestimo, dib,
                    representante_legal, pensao_alimenticia, margem_calculada, margem_cartao, margem_cartao_ben,
                    valor_rcc, valor_rmc, margem_cartao_loas, valor_base_calculo, valor_consignado, valor_liberado_total,
                    contribuicao_nome, contribuicao_valor, representante_cpf, representante_nome, banco_pagamento,
                    agencia_pagamento, conta_pagamento, forma_pagamento, contratos_atualizados_ate
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    especie_beneficio=VALUES(especie_beneficio), esp_consignavel=VALUES(esp_consignavel),
                    situacao_beneficio=VALUES(situacao_beneficio), bloqueio_emprestimo=VALUES(bloqueio_emprestimo),
                    dib=VALUES(dib), representante_legal=VALUES(representante_legal), pensao_alimenticia=VALUES(pensao_alimenticia),
                    margem_calculada=VALUES(margem_calculada), margem_cartao=VALUES(margem_cartao), margem_cartao_ben=VALUES(margem_cartao_ben),
                    valor_rcc=VALUES(valor_rcc), valor_rmc=VALUES(valor_rmc), margem_cartao_loas=VALUES(margem_cartao_loas),
                    valor_base_calculo=VALUES(valor_base_calculo), valor_consignado=VALUES(valor_consignado), valor_liberado_total=VALUES(valor_liberado_total),
                    contribuicao_nome=VALUES(contribuicao_nome), contribuicao_valor=VALUES(contribuicao_valor),
                    representante_cpf=VALUES(representante_cpf), representante_nome=VALUES(representante_nome),
                    banco_pagamento=VALUES(banco_pagamento), agencia_pagamento=VALUES(agencia_pagamento),
                    conta_pagamento=VALUES(conta_pagamento), forma_pagamento=VALUES(forma_pagamento),
                    contratos_atualizados_ate=VALUES(contratos_atualizados_ate)";

                $pdo->prepare($sql)->execute([
                    $cpf_alvo, $matricula_nb,
                    ($consulta['ESP'] ?? ''), ($consulta['ESP_Consignavel'] ?? ''), ($beneficio['situacao'] ?? ''),
                    ($beneficio['bloqemp'] ?? ''), $dib, ($beneficio['possuirepresentantelegal'] ?? ''),
                    ($beneficio['pa'] ?? ''), (float)($beneficio['MargemCalculada'] ?? 0),
                    (float)($beneficio['margemdispcartao'] ?? 0), (float)($beneficio['margemdispcartaoBen'] ?? 0),
                    (float)($beneficio['ValorRCC'] ?? 0), (float)($beneficio['ValorRMC'] ?? 0), (float)($beneficio['MargemCartaoLoas'] ?? 0),
                    (float)($beneficio['vlbasecalc'] ?? 0), (float)($beneficio['ValorConsignado'] ?? 0), (float)($beneficio['ValorLiberadoTotal'] ?? 0),
                    $desc_contrib, $val_contrib,
                    $rep_cpf, $rep_nome,
                    $banco_completo, $agencia_completo,
                    $conta_pagto, $forma_pagto,
                    $contratos_atualizados_ate
                ]);

                if (is_array($contratos) && count($contratos) > 0) {
                    // ✨ ATUALIZAÇÃO DA QUERY E VARIÁVEIS DOS CONTRATOS ✨
                    $sqlC = "INSERT INTO banco_de_Dados_inss_contratos (
                        cpf, matricula_nb, contrato, tipo_emprestimo, banco, valor_emprestimo, valor_parcela, 
                        prazo, parcelas_pagas, inicio_desconto, data_averbacao, situacao, taxa_juros, 
                        taxa_msg, saldo_quitacao, valor_liberado_refin
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        tipo_emprestimo=VALUES(tipo_emprestimo), banco=VALUES(banco), valor_emprestimo=VALUES(valor_emprestimo), 
                        valor_parcela=VALUES(valor_parcela), prazo=VALUES(prazo), parcelas_pagas=VALUES(parcelas_pagas), 
                        inicio_desconto=VALUES(inicio_desconto), data_averbacao=VALUES(data_averbacao), situacao=VALUES(situacao), 
                        taxa_juros=VALUES(taxa_juros), taxa_msg=VALUES(taxa_msg), saldo_quitacao=VALUES(saldo_quitacao), 
                        valor_liberado_refin=VALUES(valor_liberado_refin)";
                    
                    $stmtC = $pdo->prepare($sqlC);
                    foreach ($contratos as $ctr) {
                        if (!empty($ctr['Contrato'])) {
                            $banco_nome_completo = ($ctr['Banco'] ?? '') . ' - ' . ($ctr['Banco_Nome'] ?? '');
                            $dt_averbacao = (!empty($ctr['dt_averbacao'])) ? $ctr['dt_averbacao'] : null;
                            
                            // Lógica 1: Tipo de Empréstimo
                            $tipo_emp_raw = $ctr['Tipo_Emprestimo'] ?? '';
                            $tipo_emp = $tipo_emp_raw;
                            if ($tipo_emp_raw == '98') $tipo_emp = 'EMP';
                            elseif ($tipo_emp_raw == '44') $tipo_emp = 'RMC';
                            elseif ($tipo_emp_raw == '66') $tipo_emp = 'RCC';

                            // Lógica 2: Inicio Desconto (01/MM/YYYY)
                            $inicio_desc = $ctr['InicioDesconto'] ?? '';
                            if (strlen($inicio_desc) == 6 && is_numeric($inicio_desc)) {
                                $ano = substr($inicio_desc, 0, 4);
                                $mes = substr($inicio_desc, 4, 2);
                                $inicio_desc = "01/{$mes}/{$ano}";
                            }

                            // Lógica 3: Taxa MSG
                            $taxa_msg = $ctr['TaxaMSG'] ?? '';
                            if (strpos($taxa_msg, 'Taxa de juros média fixada') !== false || strpos($taxa_msg, 'não é possível calcularmos') !== false) {
                                $taxa_msg = 'Taxa de juros calculada';
                            }

                            $stmtC->execute([
                                $cpf_alvo, $matricula_nb, $ctr['Contrato'], $tipo_emp, $banco_nome_completo, 
                                (float)($ctr['Vl_Emprestimo'] ?? 0), (float)($ctr['Vl_Parcela'] ?? 0), 
                                (int)($ctr['Prazo'] ?? 0), (int)($ctr['ParcPagas'] ?? 0), $inicio_desc, 
                                $dt_averbacao, ($ctr['Situacao'] ?? ''), (float)($ctr['TaxaJuros'] ?? 0), 
                                $taxa_msg, (float)($ctr['QUITACAOATUAL'] ?? 0), (float)($ctr['ValorLiberado'] ?? 0)
                            ]);
                        }
                    }
                }
                
                $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome) VALUES (?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome)")->execute([$cpf_alvo, $nome_global]);
                
                if (!empty($consulta['ENDERECO']) || !empty($consulta['CEP'])) {
                    try {
                        $ufs_validas = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                        $uf_raw = strtoupper(substr($consulta['UF'] ?? '', 0, 2));
                        $uf_insert = in_array($uf_raw, $ufs_validas) ? $uf_raw : null;
                        $pdo->prepare("DELETE FROM enderecos WHERE cpf = ?")->execute([$cpf_alvo]);
                        $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?)")->execute([$cpf_alvo, substr($consulta['ENDERECO'] ?? '', 0, 65000), substr($consulta['BAIRRO'] ?? '', 0, 100), substr($consulta['CIDADE'] ?? '', 0, 100), $uf_insert, substr(preg_replace('/\D/', '', $consulta['CEP'] ?? ''), 0, 8)]);
                    } catch (\Throwable $eEnd) { /* UF inválida ou constraint: ignora endereço */ }
                }

                $telefones = [];
                for ($i = 1; $i <= 10; $i++) {
                    if (!empty($consulta["TEL_$i"])) $telefones[] = preg_replace('/\D/', '', $consulta["TEL_$i"]);
                    if (!empty($consulta["WHATSAPP_$i"])) $telefones[] = preg_replace('/\D/', '', $consulta["WHATSAPP_{$i}_FORMATADO"] ?? $consulta["WHATSAPP_$i"]);
                }
                $telefones = array_unique(array_filter($telefones));
                $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)");
                foreach ($telefones as $tel) {
                    if (strlen($tel) == 13 && strpos($tel, '55') === 0) { $tel = substr($tel, 2); }
                    if (strlen($tel) == 11) { $stmtTel->execute([$cpf_alvo, $tel]); }
                }
            }
        }

        if ($sucesso_inss) {
            $pdo->prepare("INSERT INTO promosys_inss_historico_consultas (cpf, nome_cliente, origem_consulta, custo_consulta) VALUES (?, ?, 'API_MANUAL', ?)")->execute([$cpf_alvo, $nome_global, $custo]);

            if ($custo > 0) {
                $saldo_final = $saldo_atual - $custo;
                $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_final, $cpf_cobrar]);
                $motivo = "Consulta Individual INSS (Painel) - CPF {$cpf_alvo}";
                $pdo->prepare("INSERT INTO PROMOSYS_INSS_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, 'DEBITO', ?, ?, ?, ?)")->execute([$cpf_cobrar, $motivo, $custo, $saldo_atual, $saldo_final]);
            } else { $saldo_final = $saldo_atual; }

            $pdo->commit();
            echo json_encode(['success' => true, 'origem' => 'API Promosys', 'dados' => ['nome' => $nome_global], 'json_bruto' => $json_bruto, 'cobranca' => ['custo' => number_format($custo, 2, ',', '.'), 'saldo_atual' => number_format($saldo_final, 2, ',', '.')]]);
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'msg' => 'Consulta realizada, mas o cliente não possui Benefício/Matrícula ativa no INSS.', 'json_bruto' => $json_bruto]); 
        }

    } else {
        $erroMsg = isset($json_bruto['Msg']) && !empty($json_bruto['Msg']) ? $json_bruto['Msg'] : 'Erro desconhecido na API ou CPF inválido';
        echo json_encode(['success' => false, 'msg' => $erroMsg, 'json_bruto' => $json_bruto]);
    }
    exit;
}

// ========================================================================
if ($acao === 'carregar_tokens_abas') { echo json_encode(['success' => true, 'data' => $pdo->query("SELECT TOKEN_MANUAL, TOKEN_LOTE, TOKEN_CSV, TEMPO_CACHE FROM promosys_config_api WHERE ID = 1")->fetch(PDO::FETCH_ASSOC)]); exit; }
if ($acao === 'salvar_credenciais') { 
    $usuario = $_POST['usuario']; 
    $senha = $_POST['senha']; 
    $tempo_cache = (int)$_POST['tempo_cache'];
    $pdo->prepare("UPDATE promosys_config_api SET TOKEN_MANUAL = ?, TOKEN_LOTE = ?, TEMPO_CACHE = ? WHERE ID = 1")->execute([$usuario, $senha, $tempo_cache]); 
    echo json_encode(['success' => true, 'msg' => 'Credenciais e Tempo de Cache salvos com sucesso!']); 
    exit; 
}

// ========================================================================
if ($acao === 'upload_csv_lote') {
    $agrupamento = trim($_POST['agrupamento']); $cpf_cobrar = preg_replace('/\D/', '', $_POST['cpf_cobrar']); $arquivo = $_FILES['arquivo_csv'];
    if ($arquivo['error'] !== UPLOAD_ERR_OK || pathinfo($arquivo['name'], PATHINFO_EXTENSION) !== 'csv') { echo json_encode(['success' => false, 'msg' => 'Erro no envio do CSV.']); exit; }
    $caminho_salvar = '../../../modulos/configuracao/Promosys_inss/uploads/';
    if (!is_dir($caminho_salvar)) mkdir($caminho_salvar, 0777, true);
    $destino = $caminho_salvar . time() . '_' . uniqid() . '.csv';

    if (move_uploaded_file($arquivo['tmp_name'], $destino)) {
        $custo_unitario = $pdo->query("SELECT CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = '$cpf_cobrar'")->fetchColumn();
        $pdo->beginTransaction();
        $stmtLote = $pdo->prepare("INSERT INTO promosys_inss_importacao_lote (nome_processamento, usuario_id, nome_usuario, cpf_cobranca, custo_unitario, status_fila, data_importacao, id_empresa) VALUES (?, ?, ?, ?, ?, 'PENDENTE', NOW(), ?)");
        $stmtLote->execute([$agrupamento, $_SESSION['usuario_id'] ?? 1, $_SESSION['usuario_nome'] ?? 'Sistema', $cpf_cobrar, (float)$custo_unitario, $id_empresa_logado_pm]);
        $lote_id = $pdo->lastInsertId();
        $handle = fopen($destino, "r"); fgetcsv($handle, 1000, ";"); $qtd_total = 0;
        $stmtItem = $pdo->prepare("INSERT INTO promosys_inss_importacao_itens (lote_id, cpf, status_item) VALUES (?, ?, 'NA FILA')");
        while (($linha = fgetcsv($handle, 1000, ";")) !== FALSE) { $cpf_linha = preg_replace('/\D/', '', $linha[0]); if (strlen($cpf_linha) == 11) { $stmtItem->execute([$lote_id, $cpf_linha]); $qtd_total++; } }
        fclose($handle);
        $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_total = ? WHERE id = ?")->execute([$qtd_total, $lote_id]);
        $pdo->commit();
        exec("php worker_promosys_lote.php > /dev/null 2>&1 &");
        echo json_encode(['success' => true]);
    } else { echo json_encode(['success' => false, 'msg' => 'Falha ao salvar arquivo no servidor.']); }
    exit;
}

if ($acao === 'lote_por_lista_cpfs') {
    $agrupamento = trim($_POST['agrupamento'] ?? '');
    $cpf_cobrar  = preg_replace('/\D/', '', $_POST['cpf_cobrar'] ?? '');
    $lista_raw   = trim($_POST['lista_cpfs'] ?? '');

    if (empty($agrupamento)) { echo json_encode(['success' => false, 'msg' => 'Informe o agrupamento.']); exit; }
    if (empty($cpf_cobrar))  { echo json_encode(['success' => false, 'msg' => 'Selecione o cliente para cobrança.']); exit; }
    if (empty($lista_raw))   { echo json_encode(['success' => false, 'msg' => 'A lista de CPFs está vazia.']); exit; }

    $linhas = explode("\n", str_replace("\r", "", $lista_raw));
    $cpfs_validos = [];
    foreach ($linhas as $linha) {
        $cpf = preg_replace('/\D/', '', trim($linha));
        if (strlen($cpf) === 11) $cpfs_validos[] = $cpf;
    }
    if (empty($cpfs_validos)) { echo json_encode(['success' => false, 'msg' => 'Nenhum CPF válido encontrado na lista.']); exit; }
    if (count($cpfs_validos) > 2000) { echo json_encode(['success' => false, 'msg' => 'Limite máximo de 2.000 CPFs por lote.']); exit; }

    $custo_unitario = $pdo->query("SELECT CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = '$cpf_cobrar'")->fetchColumn();
    $pdo->beginTransaction();
    $stmtLote = $pdo->prepare("INSERT INTO promosys_inss_importacao_lote (nome_processamento, usuario_id, nome_usuario, cpf_cobranca, custo_unitario, status_fila, data_importacao, id_empresa) VALUES (?, ?, ?, ?, ?, 'PENDENTE', NOW(), ?)");
    $stmtLote->execute([$agrupamento, $_SESSION['usuario_id'] ?? 1, $_SESSION['usuario_nome'] ?? 'Sistema', $cpf_cobrar, (float)$custo_unitario, $id_empresa_logado_pm]);
    $lote_id = $pdo->lastInsertId();
    $stmtItem = $pdo->prepare("INSERT INTO promosys_inss_importacao_itens (lote_id, cpf, status_item) VALUES (?, ?, 'NA FILA')");
    foreach ($cpfs_validos as $cpf) { $stmtItem->execute([$lote_id, $cpf]); }
    $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_total = ? WHERE id = ?")->execute([count($cpfs_validos), $lote_id]);
    $pdo->commit();
    exec("php worker_promosys_lote.php > /dev/null 2>&1 &");
    echo json_encode(['success' => true, 'msg' => count($cpfs_validos) . ' CPFs adicionados à fila.']);
    exit;
}

if ($acao === 'listar_lotes_csv') {
    $sql_pm = "SELECT l.*, c.NOME as NOME_CLIENTE, DATE_FORMAT(l.data_importacao, '%d/%m/%Y %H:%i') as DATA_BR FROM promosys_inss_importacao_lote l LEFT JOIN CLIENTE_CADASTRO c ON l.cpf_cobranca = c.CPF";
    $params_pm = [];
    if (!$perm_meu_reg_inss && !empty($cpf_logado_pm)) {
        $sql_pm .= " WHERE (l.cpf_cobranca = ? OR l.usuario_id = ?)";
        $params_pm = [$cpf_logado_pm, $id_usuario_logado_pm];
    } elseif (!$perm_hier_inss && $id_empresa_logado_pm) {
        $sql_pm .= " WHERE l.id_empresa = ?";
        $params_pm = [$id_empresa_logado_pm];
    }
    $sql_pm .= " ORDER BY l.id DESC LIMIT 50";
    $stmt = $pdo->prepare($sql_pm);
    $stmt->execute($params_pm);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}
if ($acao === 'pausar_retomar_lote') { $id = $_POST['id_lote']; $acao_lote = $_POST['acao_lote']; $novo_status = ($acao_lote === 'PAUSAR') ? 'PAUSADO' : 'PENDENTE'; $pdo->prepare("UPDATE promosys_inss_importacao_lote SET status_fila = ? WHERE id = ?")->execute([$novo_status, $id]); if ($novo_status === 'PENDENTE') exec("php worker_promosys_lote.php > /dev/null 2>&1 &"); echo json_encode(['success' => true]); exit; }
if ($acao === 'excluir_lote_csv') { $pdo->prepare("DELETE FROM promosys_inss_importacao_lote WHERE id = ?")->execute([$_POST['id']]); echo json_encode(['success' => true, 'msg' => 'Lote excluído do histórico com sucesso.']); exit; }
if ($acao === 'forcar_processamento_lote') { exec("php worker_promosys_lote.php > /dev/null 2>&1 &"); echo json_encode(['success' => true, 'msg' => 'Comando enviado ao servidor.']); exit; }

if ($acao === 'exportar_csv_agrupamento' && isset($_GET['id_lote'])) {
    $id_lote = (int)$_GET['id_lote'];
    if (!$perm_meu_reg_inss && !empty($cpf_logado_pm)) {
        $stmtAcl = $pdo->prepare("SELECT cpf_cobranca, usuario_id FROM promosys_inss_importacao_lote WHERE id = ?");
        $stmtAcl->execute([$id_lote]);
        $rowAcl = $stmtAcl->fetch(PDO::FETCH_ASSOC);
        if (!$rowAcl || ($rowAcl['cpf_cobranca'] !== $cpf_logado_pm && (int)$rowAcl['usuario_id'] !== $id_usuario_logado_pm)) { exit('Acesso negado.'); }
    } elseif (!$perm_hier_inss && $id_empresa_logado_pm) {
        $stmtAcl = $pdo->prepare("SELECT id_empresa FROM promosys_inss_importacao_lote WHERE id = ?");
        $stmtAcl->execute([$id_lote]);
        if ((int)$stmtAcl->fetchColumn() !== $id_empresa_logado_pm) { exit('Acesso negado.'); }
    }
    $stmtAgrup = $pdo->prepare("SELECT nome_processamento FROM promosys_inss_importacao_lote WHERE id = ?"); $stmtAgrup->execute([$id_lote]); $agrupamento = $stmtAgrup->fetchColumn();
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="Exportacao_HIST_INSS_' . $agrupamento . '.csv"');
    $out = fopen('php://output', 'w'); fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['CPF', 'NOME', 'BENEFICIO', 'ESPECIE', 'MARGEM', 'VALOR_BASE', 'BANCO_PAGTO', 'CONTRATO', 'BANCO_EMP', 'VALOR_EMP', 'PARCELA', 'PRAZO', 'SALDO_QUITACAO'], ';');

    $sql = "SELECT i.cpf, d.nome, b.matricula_nb, b.especie_beneficio, b.margem_calculada, b.valor_base_calculo, b.banco_pagamento, c.contrato, c.banco as banco_emprestimo, c.valor_emprestimo, c.valor_parcela, c.prazo, c.saldo_quitacao FROM promosys_inss_importacao_itens i LEFT JOIN dados_cadastrais d ON i.cpf = d.cpf LEFT JOIN banco_de_Dados_inss_dados_cadastrais b ON i.cpf = b.cpf LEFT JOIN banco_de_Dados_inss_contratos c ON b.matricula_nb = c.matricula_nb AND b.cpf = c.cpf WHERE i.lote_id = ? AND i.status_item = 'OK'";
    $stmtExp = $pdo->prepare($sql); $stmtExp->execute([$id_lote]);
    while ($r = $stmtExp->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, [$r['cpf'], $r['nome'], $r['matricula_nb'], $r['especie_beneficio'], number_format($r['margem_calculada'] ?? 0, 2, ',', ''), number_format($r['valor_base_calculo'] ?? 0, 2, ',', ''), $r['banco_pagamento'], $r['contrato'], $r['banco_emprestimo'], number_format($r['valor_emprestimo'] ?? 0, 2, ',', ''), number_format($r['valor_parcela'] ?? 0, 2, ',', ''), $r['prazo'], number_format($r['saldo_quitacao'] ?? 0, 2, ',', '')], ';'); }
    fclose($out); exit;
}

if ($acao === 'salvar_token_aba') { $coluna = "TOKEN_" . strtoupper($_POST['mod']); $pdo->prepare("UPDATE promosys_config_api SET {$coluna} = ? WHERE ID = 1")->execute([$_POST['token']]); echo json_encode(['success' => true, 'msg' => 'Token Salvo!']); exit; }
if ($acao === 'carregar_config_robo') { echo json_encode(['success' => true, 'data' => $pdo->query("SELECT * FROM promosys_config_api WHERE ID = 1")->fetch(PDO::FETCH_ASSOC)]); exit; }
if ($acao === 'salvar_config_robo') { $pdo->prepare("UPDATE promosys_config_api SET CMD_MENU=?, CMD_CONSULTA=?, CMD_COMPLETO=?, CMD_SALDO=?, CMD_EXTRATO=?, CMD_LISTA=?, CMD_SUPORTE=?, WAPI_INSTANCE=?, WAPI_TOKEN=?, TOKEN_ROBO=? WHERE ID=1")->execute([$_POST['menu'], $_POST['consulta'], $_POST['completo'], $_POST['saldo'], $_POST['extrato'], $_POST['lista'], $_POST['suporte'], $_POST['wapi_instance'], $_POST['wapi_token'], $_POST['token_robo']]); echo json_encode(['success' => true, 'msg' => 'Configurações salvas!']); exit; }
?>