<?php
// ========================================================================
// WORKER BACKGROUND - HIST INSS (PROMOSYS) PROCESSAMENTO EM LOTE
// ========================================================================
ignore_user_abort(true);
set_time_limit(0);
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

define('DIR_LOGS', '/var/www/html/logs_consulta/logs_promosys/');
if (!is_dir(DIR_LOGS)) { @mkdir(DIR_LOGS, 0777, true); }

$lock_csv = sys_get_temp_dir() . '/promosys_processando_csv.lock';
$fp = @fopen($lock_csv, "w+");
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { exit("Worker HIST INSS já está rodando."); } 

function acordarWorkerPromosys() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
}

function obterTokenPromosysWorker($pdo) {
    $stmt = $pdo->query("SELECT TOKEN_MANUAL as usuario, TOKEN_LOTE as senha FROM promosys_config_api WHERE ID = 1");
    $cred = $stmt->fetch(PDO::FETCH_ASSOC);
    if(empty($cred['usuario']) || empty($cred['senha'])) return null;

    $url = "https://jcf.promosysweb.com/services/token.php";
    $postData = http_build_query(['usuario' => $cred['usuario'], 'senha' => $cred['senha']]);
    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $postData); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch); curl_close($ch);
    
    $data = json_decode($response, true);
    return (isset($data['Code']) && $data['Code'] === "000" && !empty($data['Token'])) ? $data['Token'] : null;
}

$start_time = time();
$target_runtime = 280; 

$token_api = obterTokenPromosysWorker($pdo);

while (true) {
    if(time() - $start_time > $target_runtime) { 
        flock($fp, LOCK_UN); fclose($fp); acordarWorkerPromosys(); exit; 
    }

    $stmtLote = $pdo->query("SELECT * FROM promosys_inss_importacao_lote WHERE status_fila IN ('PENDENTE', 'PROCESSANDO') ORDER BY id ASC LIMIT 1");
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if (!$lote) { flock($fp, LOCK_UN); fclose($fp); exit; }

    $id_lote = $lote['id'];
    $cpf_cobrar = $lote['cpf_cobranca'];
    $custo_unitario = (float)$lote['custo_unitario'];
    $agrupamento = $lote['nome_processamento'];

    if (!$token_api) { 
        $pdo->prepare("UPDATE promosys_inss_importacao_lote SET status_fila = 'ERRO CREDENCIAL' WHERE id = ?")->execute([$id_lote]);
        flock($fp, LOCK_UN); fclose($fp); exit("Credenciais API (Usuário/Senha) inválidas."); 
    }

    $pdo->prepare("UPDATE promosys_inss_importacao_lote SET status_fila = 'PROCESSANDO' WHERE id = ?")->execute([$id_lote]);

    $stmtItem = $pdo->prepare("SELECT * FROM promosys_inss_importacao_itens WHERE lote_id = ? AND status_item = 'NA FILA' ORDER BY id ASC LIMIT 1");
    $stmtItem->execute([$id_lote]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        $cpf = $item['cpf'];
        $id_item = $item['id'];
        
        $is_repetida = false;
        if (!empty($cpf_cobrar)) {
            $stmt24h = $pdo->prepare("SELECT COUNT(*) FROM PROMOSYS_INSS_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? AND MOTIVO LIKE ? AND DATA_HORA >= (NOW() - INTERVAL 24 HOUR)");
            $stmt24h->execute([$cpf_cobrar, "%INSS % CPF $cpf%"]);
            if ($stmt24h->fetchColumn() > 0) { $is_repetida = true; }
        }

        $url_consulta = "https://jcf.promosysweb.com/services/consultaCpfOffline.php";
        $postData = http_build_query(['token' => $token_api, 'cpf' => ltrim($cpf, '0')]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url_consulta);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $json_resposta = curl_exec($ch);
        $erro_curl = curl_error($ch);
        curl_close($ch);

        if ($erro_curl) {
            $pdo->prepare("UPDATE promosys_inss_importacao_itens SET status_item = 'ERRO', observacao = ? WHERE id = ?")->execute(['Falha CURL: ' . $erro_curl, $id_item]);
            $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_nao_atualizado = qtd_nao_atualizado + 1 WHERE id = ?")->execute([$id_lote]);
            usleep(500000); continue;
        }

        $dados_api = json_decode($json_resposta, true);

        $nome_arquivo_txt = DIR_LOGS . "log_cpf_{$cpf}_" . time() . "_lote.txt";
        @file_put_contents($nome_arquivo_txt, json_encode($dados_api, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if ($dados_api && isset($dados_api['Code']) && $dados_api['Code'] === "000" && isset($dados_api['Consulta'])) {
            
            $consultas_array = [];
            if (is_array($dados_api['Consulta'])) {
                if (isset($dados_api['Consulta']['BENEFICIO']) || isset($dados_api['Consulta']['NOME'])) {
                    $consultas_array = [$dados_api['Consulta']]; 
                } else {
                    $consultas_array = $dados_api['Consulta']; 
                }
            }

            $sucesso_inss = false;
            $nome_global = 'Nome Nao Informado';

            foreach ($consultas_array as $consulta) {
                $beneficio = $consulta['BENEFICIO'] ?? [];
                $bancario = $consulta['DADOS_BANCARIOS'] ?? [];
                $contratos = $consulta['CONTRATO'] ?? [];
                $nome_global = $consulta['NOME'] ?? $nome_global;
                $matricula_nb = $beneficio['nb'] ?? null;

                if (!empty($matricula_nb)) {
                    $sucesso_inss = true;
                    inserirDadosBeneficioWorker($pdo, $cpf, $matricula_nb, $consulta, $beneficio, $bancario);
                    if (is_array($contratos) && count($contratos) > 0) { inserirContratosWorker($pdo, $cpf, $matricula_nb, $contratos); }
                    atualizarDadosCadastraisWorker($pdo, $cpf, $nome_global, $consulta, $agrupamento);
                }
            }

            if ($sucesso_inss) {
                $pdo->prepare("INSERT INTO promosys_inss_historico_consultas (cpf, nome_cliente, origem_consulta, custo_consulta) VALUES (?, ?, 'API_LOTE_CSV', ?)")->execute([$cpf, $nome_global, $custo_unitario]);
                $pdo->prepare("UPDATE promosys_inss_importacao_itens SET status_item = 'OK', observacao = 'Enriquecido com sucesso' WHERE id = ?")->execute([$id_item]);
                $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_atualizado = qtd_atualizado + 1 WHERE id = ?")->execute([$id_lote]);

                if (!$is_repetida && !empty($cpf_cobrar) && $custo_unitario > 0) {
                    $pdo->beginTransaction();
                    $stmtCli = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ? FOR UPDATE");
                    $stmtCli->execute([$cpf_cobrar]);
                    if ($cli = $stmtCli->fetch(PDO::FETCH_ASSOC)) {
                        $saldo_anterior = (float)$cli['SALDO'];
                        $saldo_atual = $saldo_anterior - $custo_unitario;
                        $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atual, $cpf_cobrar]);
                        
                        $motivo = "Lote HIST INSS ({$agrupamento}) - CPF {$cpf}";
                        $pdo->prepare("INSERT INTO PROMOSYS_INSS_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, 'DEBITO', ?, ?, ?, ?)")->execute([$cpf_cobrar, $motivo, $custo_unitario, $saldo_anterior, $saldo_atual]);
                    }
                    $pdo->commit();
                }
                
            } else {
                $pdo->prepare("UPDATE promosys_inss_importacao_itens SET status_item = 'ERRO', observacao = 'Cliente não possui benefício ativo' WHERE id = ?")->execute([$id_item]);
                $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_nao_atualizado = qtd_nao_atualizado + 1 WHERE id = ?")->execute([$id_lote]);
            }

        } else {
            $erroMsg = isset($dados_api['Msg']) && !empty($dados_api['Msg']) ? $dados_api['Msg'] : 'Erro desconhecido na API ou Cliente não localizado';
            $pdo->prepare("UPDATE promosys_inss_importacao_itens SET status_item = 'ERRO', observacao = ? WHERE id = ?")->execute([mb_substr($erroMsg, 0, 250), $id_item]);
            $pdo->prepare("UPDATE promosys_inss_importacao_lote SET qtd_nao_atualizado = qtd_nao_atualizado + 1 WHERE id = ?")->execute([$id_lote]);
        }
        
        usleep(400000); 
        continue; 
    }

    $pdo->prepare("UPDATE promosys_inss_importacao_lote SET status_fila = 'CONCLUIDO' WHERE id = ?")->execute([$id_lote]);
}

function inserirDadosBeneficioWorker($pdo, $cpf, $matricula_nb, $consulta, $beneficio, $bancario) {
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

    $sql = "INSERT INTO banco_de_Dados_inss_dados_cadastrais (
        cpf, matricula_nb, especie_beneficio, esp_consignavel, situacao_beneficio, bloqueio_emprestimo, dib, 
        representante_legal, pensao_alimenticia, margem_calculada, margem_cartao, margem_cartao_ben, 
        valor_rcc, valor_rmc, margem_cartao_loas, valor_base_calculo, valor_consignado, valor_liberado_total, 
        contribuicao_nome, contribuicao_valor, representante_cpf, representante_nome, banco_pagamento, 
        agencia_pagamento, conta_pagamento, forma_pagamento
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
        conta_pagamento=VALUES(conta_pagamento), forma_pagamento=VALUES(forma_pagamento)";

    $pdo->prepare($sql)->execute([
        $cpf, $matricula_nb, 
        ($consulta['ESP'] ?? ''), ($consulta['ESP_Consignavel'] ?? ''), ($beneficio['situacao'] ?? ''), 
        ($beneficio['bloqemp'] ?? ''), $dib, ($beneficio['possuirepresentantelegal'] ?? ''), 
        ($beneficio['pa'] ?? ''), (float)($beneficio['MargemCalculada'] ?? 0), 
        (float)($beneficio['margemdispcartao'] ?? 0), (float)($beneficio['margemdispcartaoBen'] ?? 0), 
        (float)($beneficio['ValorRCC'] ?? 0), (float)($beneficio['ValorRMC'] ?? 0), (float)($beneficio['MargemCartaoLoas'] ?? 0), 
        (float)($beneficio['vlbasecalc'] ?? 0), (float)($beneficio['ValorConsignado'] ?? 0), (float)($beneficio['ValorLiberadoTotal'] ?? 0), 
        $desc_contrib, $val_contrib, 
        $rep_cpf, $rep_nome, 
        $banco_completo, $agencia_completo, 
        $conta_pagto, $forma_pagto
    ]);
}

function inserirContratosWorker($pdo, $cpf, $matricula_nb, $contratos) {
    // ✨ INSERIDA A LÓGICA DAS 3 NOVAS VARIÁVEIS AQUI ✨
    $sql = "INSERT INTO banco_de_Dados_inss_contratos (
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

    $stmt = $pdo->prepare($sql);
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

            // Lógica 2: Inicio Desconto (YYYYMM -> 01/MM/YYYY)
            $inicio_desc = $ctr['InicioDesconto'] ?? '';
            if (strlen($inicio_desc) == 6 && is_numeric($inicio_desc)) {
                $ano = substr($inicio_desc, 0, 4);
                $mes = substr($inicio_desc, 4, 2);
                $inicio_desc = "01/{$mes}/{$ano}";
            }

            // Lógica 3: Taxa MSG formatada
            $taxa_msg = $ctr['TaxaMSG'] ?? '';
            if (strpos($taxa_msg, 'Taxa de juros média fixada') !== false || strpos($taxa_msg, 'não é possível calcularmos') !== false) {
                $taxa_msg = 'Taxa de juros calculada';
            }

            $stmt->execute([
                $cpf, $matricula_nb, $ctr['Contrato'], $tipo_emp, $banco_nome_completo, 
                (float)($ctr['Vl_Emprestimo'] ?? 0), (float)($ctr['Vl_Parcela'] ?? 0), 
                (int)($ctr['Prazo'] ?? 0), (int)($ctr['ParcPagas'] ?? 0), $inicio_desc, 
                $dt_averbacao, ($ctr['Situacao'] ?? ''), (float)($ctr['TaxaJuros'] ?? 0), 
                $taxa_msg, (float)($ctr['QUITACAOATUAL'] ?? 0), (float)($ctr['ValorLiberado'] ?? 0)
            ]);
        }
    }
}

function atualizarDadosCadastraisWorker($pdo, $cpf, $nome, $consulta, $agrupamento) {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, agrupamento) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), agrupamento=VALUES(agrupamento)")->execute([$cpf, $nome, $agrupamento]);
    
    if (!empty($consulta['ENDERECO']) || !empty($consulta['CEP'])) {
        $pdo->prepare("DELETE FROM enderecos WHERE cpf = ?")->execute([$cpf]); 
        $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?)")->execute([
            $cpf, substr($consulta['ENDERECO'] ?? '', 0, 65000), substr($consulta['BAIRRO'] ?? '', 0, 100), 
            substr($consulta['CIDADE'] ?? '', 0, 100), substr($consulta['UF'] ?? '', 0, 2), substr(preg_replace('/\D/', '', $consulta['CEP'] ?? ''), 0, 8)
        ]);
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
        if (strlen($tel) == 11) { $stmtTel->execute([$cpf, $tel]); }
    }
    $pdo->commit();
}
?>