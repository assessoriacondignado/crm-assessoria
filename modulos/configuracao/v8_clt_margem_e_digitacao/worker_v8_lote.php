<?php
ignore_user_abort(true);
set_time_limit(0);
ini_set('display_errors', 0);
error_reporting(0);

// Aceita chamadas apenas do próprio servidor (processo interno)
$ip_chamador = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip_chamador, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''])) {
    http_response_code(403);
    exit;
}

require_once '../../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

// =========================================================================
// SISTEMA DE LOGS E LIMPEZA (7 DIAS)
// =========================================================================
function limparLogsAntigos($diretorio, $segundos = 7200) {
    if (!is_dir($diretorio)) { @mkdir($diretorio, 0777, true); return; }
    $arquivos = glob($diretorio . '/*.txt');
    $tempo_limite = time() - $segundos;
    foreach ($arquivos as $arquivo) {
        if (is_file($arquivo) && filemtime($arquivo) < $tempo_limite) {
            @unlink($arquivo);
        }
    }
}

function gravarLogIntegracao($pasta_destino, $cpf, $fase, $url, $req, $res, $http_code) {
    $dir = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8/' . $pasta_destino;
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    
    $data_nome_arquivo = date('d-m-Y_H\h');
    $file = $dir . '/' . $cpf . '_' . $data_nome_arquivo . '.txt';
    
    $req_print = (is_array($req) || is_object($req)) ? json_encode($req, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $req;
    $res_print = (is_array($res) || is_object($res)) ? json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $res;
    
    $log = "\n[ " . date('d/m/Y H:i:s') . " ] === {$fase} ===\n";
    $log .= "URL: {$url}\nHTTP STATUS: {$http_code}\n";
    $log .= ">>> PAYLOAD (ENVIO):\n{$req_print}\n";
    $log .= "<<< RESPOSTA (RETORNO):\n{$res_print}\n";
    $log .= str_repeat("-", 60) . "\n";
    
    @file_put_contents($file, $log, FILE_APPEND);
}

limparLogsAntigos($_SERVER['DOCUMENT_ROOT'] . '/logs_v8/logs_consulta_lote', 7200); // 2 horas
limparLogsAntigos($_SERVER['DOCUMENT_ROOT'] . '/logs_v8/logs_automacao', 7200); // 2 horas
// =========================================================================

// Garante colunas novas sem quebrar instalações existentes
try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_REGISTROCONSULTA_LOTE ADD COLUMN DATA_CONSENTIMENTO DATETIME NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IMPORTACAO_LOTE ADD COLUMN AUTO_REPROCESS_CHECKPOINT INT NOT NULL DEFAULT 0"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IMPORTACAO_LOTE ADD COLUMN HORA_INATIVACAO_INICIO TIME NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IMPORTACAO_LOTE ADD COLUMN HORA_INATIVACAO_FIM TIME NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IMPORTACAO_LOTE ADD COLUMN HORA_FIM_DIARIO TIME NULL"); } catch(Exception $e){}

$user_cpf = preg_replace('/\D/', '', $_GET['user_cpf'] ?? '');
if (empty($user_cpf)) { exit; }

$lock_file = sys_get_temp_dir() . '/v8_lote_csv_' . $user_cpf . '.lock';
$fp = @fopen($lock_file, "w+");
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { exit; }

function acordarWorkerNovamente() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $url_worker); 
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
    curl_exec($ch); 
    curl_close($ch);
}

function gerarTokenV8Lote($chave_id, $pdo) {
    $stmt = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); 
    $stmt->execute([$chave_id]); $chave = $stmt->fetch(PDO::FETCH_ASSOC);
    $payload = http_build_query(['grant_type'=>'password', 'username'=>$chave['USERNAME_API'], 'password'=>$chave['PASSWORD_API'], 'audience'=>$chave['AUDIENCE'], 'client_id'=>$chave['CLIENT_ID'], 'scope'=>'offline_access']);
    $ch = curl_init("https://auth.v8sistema.com/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $res = curl_exec($ch); curl_close($ch);
    $json = json_decode($res, true);
    return $json['access_token'] ?? null;
}

// Retorna true se o horário atual está dentro da janela de inativação do lote.
// Suporta janelas que cruzam meia-noite (ex: 22:00 a 06:00).
function v8EmHorarioInativacao($lote) {
    $inicio = $lote['HORA_INATIVACAO_INICIO'] ?? '';
    $fim    = $lote['HORA_INATIVACAO_FIM']    ?? '';
    if (empty($inicio) || empty($fim)) return false;
    $agora = (int)date('Hi'); // ex: 2230
    $i     = (int)str_replace(':', '', substr($inicio, 0, 5));
    $f     = (int)str_replace(':', '', substr($fim,    0, 5));
    if ($i < $f) { return ($agora >= $i && $agora < $f); }   // ex: 08:00–18:00
    else         { return ($agora >= $i || $agora < $f); }   // cruza meia-noite
}

function extrairValorSeguro($arr, $keys) {
    if(!is_array($arr)) return null; 
    foreach($keys as $k) { if(isset($arr[$k]) && is_numeric($arr[$k])) return (float)$arr[$k]; } 
    foreach($arr as $v) { if(is_array($v)) { $res = extrairValorSeguro($v, $keys); if($res !== null) return $res; } } 
    return null; 
}

/**
 * Busca um consentimento existente na V8 percorrendo TODAS as páginas da listagem.
 * Retorna o consult_id encontrado ou null.
 *
 * @param string $cpf_busca       CPF (11 dígitos, sem formatação)
 * @param array  $headers         Headers de autenticação (Bearer token)
 * @param bool   $ignorar_rejeito Se true, pula itens com status REJECTED/DENIED/CANCELED/ERROR
 * @param string $cpf_log         CPF para identificação nos logs
 */
function buscarConsentimentoV8ComPaginacao($cpf_busca, $headers, $ignorar_rejeito = true, $cpf_log = 'sistema') {
    $pagina_atual = 1;
    $max_paginas  = 20; // Segurança: limita a 2.000 registros (20 × 100)

    while ($pagina_atual <= $max_paginas) {
        $url = "https://bff.v8sistema.com/private-consignment/consult?limit=100&page={$pagina_atual}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode($res, true);
        gravarLogIntegracao('logs_consulta_lote', $cpf_log,
            "BUSCA PAGINADA V8 (pag {$pagina_atual})",
            $url, "GET", $json, $http);

        if ($http !== 200 || empty($json)) break;

        $lista = $json['data'] ?? $json['items'] ?? [];

        foreach ($lista as $item) {
            $doc_api     = preg_replace('/\D/', '', $item['documentNumber'] ?? $item['borrowerDocumentNumber'] ?? $item['cpf'] ?? '');
            $status_item = strtoupper($item['status'] ?? '');

            if ($doc_api !== $cpf_busca) continue;

            if ($ignorar_rejeito && in_array($status_item, ['REJECTED', 'DENIED', 'CANCELED', 'ERROR'])) continue;

            return $item['id'] ?? null;
        }

        // Verifica se há próxima página
        $has_next = $json['pages']['hasNext'] ?? false;
        if (!$has_next) break;

        $pagina_atual++;
    }

    return null;
}

$start_time = time();
$target_runtime = 280;
$tokens_cache = [];
$ciclo_count = 0;

while(true) {
    if(time() - $start_time > $target_runtime) {
        flock($fp, LOCK_UN); fclose($fp); acordarWorkerNovamente(); exit;
    }

    $ciclo_count++;
    $work_found = false;

    $stmtLote = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE STATUS_FILA IN ('PENDENTE', 'PROCESSANDO') AND CPF_USUARIO = ? ORDER BY ID ASC LIMIT 1");
    $stmtLote->execute([$user_cpf]);
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if (!$lote) { flock($fp, LOCK_UN); fclose($fp); exit; }

    $id_lote = $lote['ID'];
    $chave_id = $lote['CHAVE_ID'];
    // Tabela de CPFs: própria (lotes novos) ou central (lotes antigos)
    $tbl = !empty($lote['TABELA_DADOS']) ? $lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

    // =========================================================================
    // TRAVA DE HORÁRIO DE INATIVAÇÃO
    // =========================================================================
    if (v8EmHorarioInativacao($lote)) {
        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PAUSADO' WHERE ID = ?")->execute([$id_lote]);
        gravarLogIntegracao('logs_consulta_lote', 'sistema', 'INATIVACAO HORARIO', 'n/a',
            "inicio={$lote['HORA_INATIVACAO_INICIO']} fim={$lote['HORA_INATIVACAO_FIM']}",
            'Lote pausado automaticamente pelo horário de inativação.', 0);
        flock($fp, LOCK_UN); fclose($fp); exit;
    }
    // =========================================================================

    // =========================================================================
    // TRAVA DE HORÁRIO FIM DIÁRIO
    // =========================================================================
    if (!empty($lote['HORA_FIM_DIARIO']) && $lote['AGENDAMENTO_TIPO'] === 'DIARIO') {
        $agoraHi = (int)date('Hi');
        $fimHi   = (int)str_replace(':', '', substr($lote['HORA_FIM_DIARIO'], 0, 5));
        if ($agoraHi >= $fimHi) {
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'AGUARDANDO_DIARIO' WHERE ID = ?")->execute([$id_lote]);
            gravarLogIntegracao('logs_consulta_lote', 'sistema', 'FIM HORARIO DIARIO', 'n/a',
                "fim={$lote['HORA_FIM_DIARIO']}",
                'Lote pausado automaticamente ao atingir o horário fim do dia.', 0);
            flock($fp, LOCK_UN); fclose($fp); exit;
        }
    }
    // =========================================================================

    // Verifica se o lote foi pausado externalmente entre ciclos (ex: usuário clicou Pausar)
    $stmtCheckPausa = $pdo->prepare("SELECT STATUS_FILA FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
    $stmtCheckPausa->execute([$id_lote]);
    $status_atual_db = $stmtCheckPausa->fetchColumn();
    if ($status_atual_db === 'PAUSADO') { flock($fp, LOCK_UN); fclose($fp); exit; }

    $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PROCESSANDO' WHERE ID = ?")->execute([$id_lote]);

    // =========================================================================
    // IMPLEMENTAÇÃO DO LIMITE DIÁRIO BASEADO NO EXTRATO (SUCESSOS COBRADOS)
    // =========================================================================
    // Lê o limite diário configurado no próprio lote (0 = sem limite)
    $limite_diario_sucessos = (int)($lote['LIMITE_DIARIO'] ?? 0);

    $stmtSucessos = $pdo->prepare("SELECT COUNT(ID) FROM INTEGRACAO_V8_EXTRATO_CLIENTE WHERE CHAVE_ID = ? AND DATE(DATA_LANCAMENTO) = CURDATE() AND TIPO_MOVIMENTO = 'DEBITO'");
    $stmtSucessos->execute([$chave_id]);
    $qtd_sucessos_hoje = (int) $stmtSucessos->fetchColumn();
    // Se limite = 0, nunca atinge (sem limite). Caso contrário, compara com o configurado.
    $atingiu_limite_diario = ($limite_diario_sucessos > 0 && $qtd_sucessos_hoje >= $limite_diario_sucessos);
    // =========================================================================

    $stmtKeySet = $pdo->prepare("SELECT TABELA_PADRAO, PRAZO_PADRAO, AVERBADORA, INTERVALO_CONSENTIMENTO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
    $stmtKeySet->execute([$chave_id]);
    $keySet = $stmtKeySet->fetch(PDO::FETCH_ASSOC);
    $tabela_padrao = !empty($keySet['TABELA_PADRAO']) ? $keySet['TABELA_PADRAO'] : 'CLT Acelera';
    $prazo_padrao = !empty($keySet['PRAZO_PADRAO']) ? (int)$keySet['PRAZO_PADRAO'] : 24;
    $averbadora_lote = strtoupper(trim($keySet['AVERBADORA'] ?? 'QI')) ?: 'QI';
    $intervalo_consentimento = max(0, (int)($keySet['INTERVALO_CONSENTIMENTO'] ?? 0));

    $token = null;
    if(isset($tokens_cache[$chave_id]) && (time() - $tokens_cache[$chave_id]['time'] < 3000)) { 
        $token = $tokens_cache[$chave_id]['token']; 
    } else { 
        $token = gerarTokenV8Lote($chave_id, $pdo); 
        if($token) { $tokens_cache[$chave_id] = ['token'=>$token, 'time'=>time()]; } 
    }

    if (!$token) {
        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'ERRO CREDENCIAL' WHERE ID = ?")->execute([$id_lote]);
        v8EnviarAvisoStatusLote($id_lote, 'ERRO CREDENCIAL', $lote, $pdo);
        continue;
    }

    $headers = ["Authorization: Bearer $token", "Content-Type: application/json"];


    // =========================================================================
    // PRIORIDADE 1: AGUARDANDO SIMULACAO — já tem margem, só falta simular
    // =========================================================================
    $stmtF3 = $pdo->prepare("SELECT * FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO SIMULACAO' ORDER BY ID ASC LIMIT 1");
    $stmtF3->execute([$id_lote]);
    if ($cpfFase3 = $stmtF3->fetch(PDO::FETCH_ASSOC)) {
        $work_found = true;
        v8SimularLote($cpfFase3, $cpfFase3['CONFIG_ID'], $cpfFase3['CONSULT_ID'], (float)$cpfFase3['VALOR_MARGEM'], $id_lote, $prazo_padrao, $headers, $lote, $pdo);
        sleep(2); continue;
    }

    // =========================================================================
    // PRIORIDADE 2: AGUARDANDO MARGEM — verifica todos os pendentes de uma vez
    // Uma chamada por item, sem polling. Pendente → AGUARDANDO DATAPREV direto.
    // =========================================================================
    $stmtF2 = $pdo->prepare("SELECT * FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO MARGEM' ORDER BY ID ASC LIMIT 100");
    $stmtF2->execute([$id_lote]);
    $listaF2 = $stmtF2->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($listaF2)) {
        $work_found = true;
        foreach ($listaF2 as $cpfFase2) {
            $consult_id = $cpfFase2['CONSULT_ID'];

            $chC = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id}"); curl_setopt($chC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chC, CURLOPT_HTTPHEADER, $headers);
            $resC = curl_exec($chC); $httpC = curl_getinfo($chC, CURLINFO_HTTP_CODE); curl_close($chC);
            $jsonC = json_decode($resC, true);

            gravarLogIntegracao('logs_consulta_lote', $cpfFase2['CPF'], 'FASE 2: BUSCAR MARGEM DATAPREV', "https://bff.v8sistema.com/private-consignment/consult/{$consult_id}", "GET Request", $jsonC, $httpC);

            $status_api = strtoupper($jsonC['status'] ?? '');

            if ($httpC == 200 && in_array($status_api, ['SUCCESS', 'COMPLETED', 'PRE_APPROVED', 'APPROVED'])) {
                $margem = extrairValorSeguro($jsonC, ['availableMargin', 'margin', 'maxAmount', 'marginBaseValue', 'availableMarginValue']) ?? 0;

                $chCfg = curl_init("https://bff.v8sistema.com/private-consignment/simulation/configs?consult_id={$consult_id}"); curl_setopt($chCfg, CURLOPT_RETURNTRANSFER, true); curl_setopt($chCfg, CURLOPT_HTTPHEADER, $headers);
                $resCfg = curl_exec($chCfg); curl_close($chCfg); $jsonCfg = json_decode($resCfg, true);

                $config_id = null; $lista_configs = $jsonCfg['configs'] ?? [];
                if (is_array($lista_configs) && count($lista_configs) > 0) {
                    $tabela_desejada = trim(strtolower($tabela_padrao)); $quer_seguro = (strpos($tabela_desejada, 'seguro') !== false);
                    foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if ($nomeSlug === $tabela_desejada) { $config_id = $cfg['id']; break; } }
                    if (!$config_id) { foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if (strpos($nomeSlug, $tabela_desejada) !== false) { $tem_seguro = (strpos($nomeSlug, 'seguro') !== false); if (!$quer_seguro && $tem_seguro) continue; $config_id = $cfg['id']; break; } } }
                    if (!$config_id) { foreach ($lista_configs as $cfg) { $nomeSlug = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); $tem_seguro = (strpos($nomeSlug, 'seguro') !== false); if (!$quer_seguro && $tem_seguro) continue; $config_id = $cfg['id']; break; } }
                    if (!$config_id) { $config_id = $lista_configs[0]['id']; }
                }
                if (!$config_id) $config_id = $consult_id;

                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'AGUARDANDO SIMULACAO', VALOR_MARGEM = ?, CONFIG_ID = ?, OBSERVACAO = 'Margem lida — simulando...' WHERE ID = ?")->execute([(float)$margem, $config_id, $cpfFase2['ID']]);
                $cpfFase2['VALOR_MARGEM'] = $margem; $cpfFase2['CONFIG_ID'] = $config_id;
                v8AtualizarFatorConferi($cpfFase2, $lote, $pdo);
                v8SimularLote($cpfFase2, $config_id, $consult_id, (float)$margem, $id_lote, $prazo_padrao, $headers, $lote, $pdo);

            } elseif (!empty($status_api) && !in_array($status_api, ['PROCESSING', 'PENDING', 'WAITING', 'WAITING_CONSULT', 'ANALYZING', 'IN_PROGRESS', 'PENDING_CONSULTATION', 'CONSENT_APPROVED', 'WAITING_CREDIT_ANALYSIS'])) {
                $msgErro = $jsonC['detail'] ?? $jsonC['description'] ?? $jsonC['status_description'] ?? 'Rejeitado pela Dataprev';
                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'ERRO MARGEM', OBSERVACAO = ? WHERE ID = ?")->execute([$msgErro, $cpfFase2['ID']]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_ERRO = QTD_ERRO + 1 WHERE ID = ?")->execute([$id_lote]);

            } else {
                // Dataprev ainda processando — aguarda próximo ciclo
                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'AGUARDANDO DATAPREV', OBSERVACAO = 'Dataprev não retornou. Aguardando reprocessamento manual.' WHERE ID = ?")->execute([$cpfFase2['ID']]);
            }
        }
        continue;
    }

    // =========================================================================
    // PRIORIDADE 3: AGUARDANDO DATAPREV — re-consulta todos automaticamente
    // Verificado a cada ciclo. Usuário também pode reprocessar manualmente.
    // =========================================================================
    $stmtDP = $pdo->prepare("SELECT * FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO DATAPREV' ORDER BY ID ASC LIMIT 100");
    $stmtDP->execute([$id_lote]);
    $listaDP = $stmtDP->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($listaDP)) {
        $work_found = true;
        $dataprev_avancou = false;
        foreach ($listaDP as $cpfDP) {
            $consult_id_dp = $cpfDP['CONSULT_ID'];
            if (empty($consult_id_dp)) {
                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'NA FILA', OBSERVACAO = 'Sem CONSULT_ID — novo consentimento será criado.' WHERE ID = ?")->execute([$cpfDP['ID']]);
                $dataprev_avancou = true;
                continue;
            }

            $chDP = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id_dp}");
            curl_setopt($chDP, CURLOPT_RETURNTRANSFER, true); curl_setopt($chDP, CURLOPT_HTTPHEADER, $headers);
            $resDP = curl_exec($chDP); $httpDP = curl_getinfo($chDP, CURLINFO_HTTP_CODE); curl_close($chDP);
            $jsonDP = json_decode($resDP, true);

            gravarLogIntegracao('logs_consulta_lote', $cpfDP['CPF'], 'AGUARDANDO DATAPREV - RECHECK', "GET /consult/{$consult_id_dp}", "GET", $jsonDP, $httpDP);

            $status_dp = strtoupper($jsonDP['status'] ?? '');

            if ($httpDP == 200 && in_array($status_dp, ['SUCCESS', 'COMPLETED', 'PRE_APPROVED', 'APPROVED'])) {
                $margem_dp = extrairValorSeguro($jsonDP, ['availableMargin', 'margin', 'maxAmount', 'marginBaseValue', 'availableMarginValue']) ?? 0;

                $chCfgDP = curl_init("https://bff.v8sistema.com/private-consignment/simulation/configs?consult_id={$consult_id_dp}");
                curl_setopt($chCfgDP, CURLOPT_RETURNTRANSFER, true); curl_setopt($chCfgDP, CURLOPT_HTTPHEADER, $headers);
                $resCfgDP = curl_exec($chCfgDP); curl_close($chCfgDP); $jsonCfgDP = json_decode($resCfgDP, true);

                $config_id_dp = null; $lista_cfg_dp = $jsonCfgDP['configs'] ?? [];
                if (!empty($lista_cfg_dp)) {
                    $tabela_slug = trim(strtolower($tabela_padrao)); $quer_seg = strpos($tabela_slug, 'seguro') !== false;
                    foreach ($lista_cfg_dp as $cfg) { $ns = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if ($ns === $tabela_slug) { $config_id_dp = $cfg['id']; break; } }
                    if (!$config_id_dp) foreach ($lista_cfg_dp as $cfg) { $ns = trim(strtolower($cfg['slug'] ?? $cfg['name'] ?? '')); if (strpos($ns, $tabela_slug) !== false) { if (!$quer_seg && strpos($ns, 'seguro') !== false) continue; $config_id_dp = $cfg['id']; break; } }
                    if (!$config_id_dp) $config_id_dp = $lista_cfg_dp[0]['id'];
                }
                if (!$config_id_dp) $config_id_dp = $consult_id_dp;

                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'AGUARDANDO SIMULACAO', VALOR_MARGEM = ?, CONFIG_ID = ?, OBSERVACAO = 'Margem recuperada no recheck automático.' WHERE ID = ?")->execute([(float)$margem_dp, $config_id_dp, $cpfDP['ID']]);
                $cpfDP['VALOR_MARGEM'] = $margem_dp; $cpfDP['CONFIG_ID'] = $config_id_dp;
                v8AtualizarFatorConferi($cpfDP, $lote, $pdo);
                $dataprev_avancou = true;

            } elseif (!empty($status_dp) && in_array($status_dp, ['REJECTED', 'DENIED', 'CANCELED', 'ERROR'])) {
                $msgErroDP = $jsonDP['detail'] ?? $jsonDP['description'] ?? $jsonDP['status_description'] ?? "Rejeitado pela Dataprev (status: {$status_dp})";
                $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'ERRO MARGEM', OBSERVACAO = ? WHERE ID = ?")->execute([$msgErroDP, $cpfDP['ID']]);
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_ERRO = QTD_ERRO + 1 WHERE ID = ?")->execute([$id_lote]);
                $dataprev_avancou = true;
            }
            // Ainda pendente → mantém AGUARDANDO DATAPREV, não bloqueia o fluxo
        }
        // Só reinicia o ciclo se algum item avançou. Se todos ainda pendentes,
        // cai na NA FILA para continuar enviando novos consentimentos.
        if ($dataprev_avancou) continue;
    }

    // =========================================================================
    // PRIORIDADE 5: RECUPERAR V8 — busca consentimento existente na V8
    // =========================================================================
    $stmtF15 = $pdo->prepare("SELECT * FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 = 'RECUPERAR V8' ORDER BY ID ASC LIMIT 1");
    $stmtF15->execute([$id_lote]);
    if ($cpfFase15 = $stmtF15->fetch(PDO::FETCH_ASSOC)) {
        $work_found = true;

        // Busca paginada — percorre TODAS as páginas até encontrar o CPF ou esgotar a listagem
        $consult_id = buscarConsentimentoV8ComPaginacao($cpfFase15['CPF'], $headers, true, $cpfFase15['CPF']);

        if ($consult_id) {
            $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'AGUARDANDO MARGEM', CONSULT_ID = ?, OBSERVACAO = 'Consentimento recuperado na V8. Lendo margem...' WHERE ID = ?")->execute([$consult_id, $cpfFase15['ID']]);
        } else {
            // Consentimento não encontrado na V8 (expirado ou rejeitado) — cria novo consentimento
            $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'NA FILA', CONSULT_ID = NULL, OBSERVACAO = 'Sem consentimento válido na V8 — novo consentimento será criado.' WHERE ID = ?")->execute([$cpfFase15['ID']]);
        }
        sleep(2);
        continue;
    }

    // =========================================================================
    // PRIORIDADE 6 (ÚLTIMA): NA FILA — envia lote de 20 consentimentos de uma vez
    // Sem polling por item. Após enviar o lote, aguarda 30s e retorna ao ciclo
    // para que a PRIORIDADE 2 verifique os resultados da Dataprev.
    // =========================================================================
    $stmtF1 = $pdo->prepare("SELECT * FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 = 'NA FILA' ORDER BY ID ASC LIMIT 20");
    $stmtF1->execute([$id_lote]);
    $listaF1 = $stmtF1->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($listaF1)) {
        if (!$atingiu_limite_diario) {
            $work_found = true;
            foreach ($listaF1 as $cpfFase1) {
                $telefone = '11900000000';
                $stmtT = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? LIMIT 1"); $stmtT->execute([$cpfFase1['CPF']]);
                if ($t = $stmtT->fetchColumn()) { $telefone = $t; $pdo->prepare("UPDATE {$tbl} SET TELEFONES_LOCAL = ? WHERE ID = ?")->execute([$telefone, $cpfFase1['ID']]); }

                if ($intervalo_consentimento > 0) { sleep($intervalo_consentimento); }

                $payload_cons = json_encode([ 'borrowerDocumentNumber' => $cpfFase1['CPF'], 'gender' => $cpfFase1['SEXO'] ?: 'female', 'birthDate' => $cpfFase1['NASCIMENTO'], 'signerName' => $cpfFase1['NOME'], 'signerEmail' => 'cliente@gmail.com', 'signerPhone' => ['countryCode' => '55', 'areaCode' => substr($telefone, 0, 2), 'phoneNumber' => substr($telefone, 2)], 'provider' => $averbadora_lote ]);

                $ch = curl_init("https://bff.v8sistema.com/private-consignment/consult");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $payload_cons); curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
                $json = json_decode($res, true);

                gravarLogIntegracao('logs_consulta_lote', $cpfFase1['CPF'], 'FASE 1: CRIAR CONSENTIMENTO', 'https://bff.v8sistema.com/private-consignment/consult', json_decode($payload_cons, true), $json, $http);

                $consult_id = $json['id'] ?? null;

                if ($consult_id || ($http >= 400 && strpos($res, 'consult_already_exists') !== false)) {
                    if (!$consult_id) {
                        $consult_id = buscarConsentimentoV8ComPaginacao($cpfFase1['CPF'], $headers, false, $cpfFase1['CPF']);
                    }
                    if ($consult_id) {
                        $chA = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$consult_id}/authorize"); curl_setopt($chA, CURLOPT_RETURNTRANSFER, true); curl_setopt($chA, CURLOPT_POST, true); curl_setopt($chA, CURLOPT_POSTFIELDS, json_encode([])); curl_setopt($chA, CURLOPT_HTTPHEADER, $headers);
                        $resA = curl_exec($chA); $httpA = curl_getinfo($chA, CURLINFO_HTTP_CODE); curl_close($chA);

                        gravarLogIntegracao('logs_consulta_lote', $cpfFase1['CPF'], 'FASE 1.1: AUTORIZAR', "https://bff.v8sistema.com/private-consignment/consult/{$consult_id}/authorize", [], json_decode($resA, true), $httpA);

                        $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'AGUARDANDO MARGEM', CONSULT_ID = ?, DATA_CONSENTIMENTO = NOW(), OBSERVACAO = 'Consentimento enviado. Aguardando retorno da Dataprev...' WHERE ID = ?")->execute([$consult_id, $cpfFase1['ID']]);
                        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET PROCESSADOS_HOJE = PROCESSADOS_HOJE + 1 WHERE ID = ?")->execute([$id_lote]);

                        // Cobrança V8
                        $stmtCusto = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA, CUSTO_V8 FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
                        $stmtCusto->execute([$chave_id]);
                        if ($chaveInfo = $stmtCusto->fetch(PDO::FETCH_ASSOC)) {
                            $custo = (float)$chaveInfo['CUSTO_CONSULTA'];
                            if ($custo > 0) {
                                $saldo_anterior = (float)$chaveInfo['SALDO'];
                                $saldo_atual = $saldo_anterior - $custo;
                                $pdo->prepare("UPDATE INTEGRACAO_V8_CHAVE_ACESSO SET SALDO = ? WHERE ID = ?")->execute([$saldo_atual, $chave_id]);
                                $pdo->prepare("INSERT INTO INTEGRACAO_V8_EXTRATO_CLIENTE (CHAVE_ID, TIPO_MOVIMENTO, TIPO_CUSTO, VALOR, CUSTO_V8, SALDO_ANTERIOR, SALDO_ATUAL, DATA_LANCAMENTO) VALUES (?, 'DEBITO', ?, ?, ?, ?, ?, NOW())")->execute([$chave_id, "LOTE V8 - CPF {$cpfFase1['CPF']}", $custo, (float)$chaveInfo['CUSTO_V8'], $saldo_anterior, $saldo_atual]);
                            }
                        }
                    } else {
                        $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'ERRO CONSULTA', OBSERVACAO = 'Bloqueado. ID não retornado pela V8.' WHERE ID = ?")->execute([$cpfFase1['ID']]);
                        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_ERRO = QTD_ERRO + 1 WHERE ID = ?")->execute([$id_lote]);
                    }
                } else {
                    $msgErro = $json['detail'] ?? $json['message'] ?? mb_substr($res, 0, 200);
                    $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'ERRO CONSULTA', OBSERVACAO = ? WHERE ID = ?")->execute([$msgErro, $cpfFase1['ID']]);
                    $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_ERRO = QTD_ERRO + 1 WHERE ID = ?")->execute([$id_lote]);
                }
            }
            continue;
        } // FIM TRAVA LIMITE DIÁRIO
    }

    if (!$work_found) {

        // Verifica se ainda tem clientes ativos na fila (excluindo DATAPREV — não bloqueia conclusão)
        $stmtRestante = $pdo->prepare("SELECT ID FROM {$tbl} WHERE LOTE_ID = ? AND STATUS_V8 IN ('NA FILA','AGUARDANDO MARGEM','AGUARDANDO SIMULACAO','RECUPERAR V8') LIMIT 1");
        $stmtRestante->execute([$id_lote]);
        $tem_pendente_ativo = $stmtRestante->fetchColumn();

        if ($tem_pendente_ativo && $atingiu_limite_diario) {
            // Limite diário atingido — para lote DIÁRIO vai aguardar o próximo ciclo do dia;
            // para lote único fica PENDENTE. Em ambos os casos o worker encerra para não fazer spinning.
            $status_limite = ($lote['AGENDAMENTO_TIPO'] === 'DIARIO') ? 'AGUARDANDO_DIARIO' : 'PENDENTE';
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = ? WHERE ID = ?")->execute([$status_limite, $id_lote]);
            flock($fp, LOCK_UN); fclose($fp); exit;
        } else if (!$tem_pendente_ativo) {
            // Lista encerrou (DATAPREV restante não bloqueia a conclusão)
            if ($lote['AGENDAMENTO_TIPO'] === 'DIARIO') {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'AGUARDANDO_DIARIO', DATA_FINALIZACAO = NOW() WHERE ID = ?")->execute([$id_lote]);
                v8EnviarAvisoStatusLote($id_lote, 'AGUARDANDO_DIARIO', $lote, $pdo);
            } else {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'CONCLUIDO', DATA_FINALIZACAO = NOW() WHERE ID = ?")->execute([$id_lote]);
                v8EnviarAvisoStatusLote($id_lote, 'CONCLUIDO', $lote, $pdo);
            }
        }
    } else {
        // work_found=true mas pode ser só DATAPREV rodando — verifica se toda fila ativa acabou
        // AGUARDANDO DATAPREV não bloqueia a conclusão do lote
        $stmtPendentes = $pdo->prepare("
            SELECT COUNT(*) FROM {$tbl}
            WHERE LOTE_ID = ?
              AND STATUS_V8 IN ('NA FILA','AGUARDANDO MARGEM','AGUARDANDO SIMULACAO','RECUPERAR V8')
        ");
        $stmtPendentes->execute([$id_lote]);
        $qtd_pendentes = (int)$stmtPendentes->fetchColumn();

        if ($qtd_pendentes === 0) {
            if ($lote['AGENDAMENTO_TIPO'] === 'DIARIO') {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'AGUARDANDO_DIARIO', DATA_FINALIZACAO = NOW() WHERE ID = ?")->execute([$id_lote]);
                v8EnviarAvisoStatusLote($id_lote, 'AGUARDANDO_DIARIO', $lote, $pdo);
            } else {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'CONCLUIDO', DATA_FINALIZACAO = NOW() WHERE ID = ?")->execute([$id_lote]);
                v8EnviarAvisoStatusLote($id_lote, 'CONCLUIDO', $lote, $pdo);
            }
        }
    }
}

// ===============================================
// FUNÇÃO: SIMULAÇÃO PADRÃO + FATOR CONFERI
// Chamada inline após margem resolvida (FASE 1 e FASE 2)
// e também usada pela FASE 3 como fallback.
// ===============================================
function v8SimularLote($cpfRow, $config_id_sim, $consult_id_sim, $margem_sim, $id_lote, $prazo_padrao, $headers, $lote, $pdo) {
    $tbl = !empty($lote['TABELA_DADOS']) ? $lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
    $url_sim = "https://bff.v8sistema.com/private-consignment/simulation";
    $payload_sim_array = [ 'consult_id' => $consult_id_sim, 'config_id' => $config_id_sim, 'number_of_installments' => $prazo_padrao, 'installment_face_value' => $margem_sim ];

    $tentativas = 0; $max_tentativas = 5; $sim_obj = null; $sucesso_sim = false; $erro_fatal = ''; $observacao_final = 'Simulação e Margem extraídas com sucesso!';

    while ($tentativas < $max_tentativas) {
        $payload_sim = json_encode($payload_sim_array);
        $chS = curl_init($url_sim); curl_setopt($chS, CURLOPT_RETURNTRANSFER, true); curl_setopt($chS, CURLOPT_POST, true); curl_setopt($chS, CURLOPT_POSTFIELDS, $payload_sim); curl_setopt($chS, CURLOPT_HTTPHEADER, $headers);
        $resS = curl_exec($chS); $httpS = curl_getinfo($chS, CURLINFO_HTTP_CODE); curl_close($chS);
        $jsonS = json_decode($resS, true);

        gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], "SIMULACAO (TENTATIVA " . ($tentativas+1) . ")", $url_sim, json_decode($payload_sim, true), $jsonS, $httpS);

        if ($httpS >= 200 && $httpS < 300 && !empty($jsonS)) { $sim_obj = isset($jsonS[0]) ? $jsonS[0] : $jsonS; $sucesso_sim = true; break; }

        $erroMsg = strtolower($jsonS['detail'] ?? $jsonS['message'] ?? mb_substr($resS, 0, 200));
        if (strpos($erroMsg, '50000') !== false) {
            $observacao_final = 'O valor solicitado não pode ser maior que 50000.';
            unset($payload_sim_array['installment_face_value']); $payload_sim_array['disbursed_amount'] = 50000; $tentativas++; continue;
        } elseif (strpos($erroMsg, 'desembolso') !== false) {
            $erro_fatal = 'A simulação não passou nos critérios de elegibilidade por valor mínimo de desembolso.'; break;
        } elseif (strpos($erroMsg, 'parcelas') !== false && (strpos($erroMsg, 'maior que 12') !== false || strpos($erroMsg, 'número de parcelas') !== false || strpos($erroMsg, 'numero de parcelas') !== false)) {
            if (($payload_sim_array['number_of_installments'] ?? 0) > 12) {
                $payload_sim_array['number_of_installments'] = 12;
                $observacao_final = 'Prazo ajustado para 12 meses (máximo permitido pela API).';
                $tentativas++; continue;
            }
            $erro_fatal = $jsonS['detail'] ?? $jsonS['message'] ?? mb_substr($resS, 0, 200); break;
        } elseif (strpos($erroMsg, 'margem dispon') !== false || strpos($erroMsg, 'maior que a margem') !== false) {
            $observacao_final = 'O valor da parcela não pode ser maior que a margem disponível do funcionário (parcela enquadrada em lup 10%)';
            if (isset($payload_sim_array['installment_face_value'])) { $payload_sim_array['installment_face_value'] = round($payload_sim_array['installment_face_value'] * 0.90, 2); }
            $tentativas++; continue;
        } else {
            $erro_fatal = $jsonS['detail'] ?? $jsonS['message'] ?? mb_substr($resS, 0, 200); break;
        }
    }

    if ($sucesso_sim) {
        $valor_liberado = $sim_obj['disbursement_amount'] ?? $sim_obj['disbursed_amount'] ?? 0;
        $sim_id = $sim_obj['id_simulation'] ?? $sim_obj['id'] ?? null;

        $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'OK', VALOR_LIQUIDO = ?, PRAZO = ?, SIMULATION_ID = ?, OBSERVACAO = ?, DATA_SIMULACAO = NOW() WHERE ID = ?")->execute([(float)$valor_liberado, $prazo_padrao, $sim_id, $observacao_final, $cpfRow['ID']]);
        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_SUCESSO = QTD_SUCESSO + 1 WHERE ID = ?")->execute([$id_lote]);

        // ---------------------------------------------------------------
        // Registra no rodapé da ficha do cliente com dados do usuário dono
        // do lote, garantindo hierarquia e permissão corretas.
        // ---------------------------------------------------------------
        try {
            $stmtUsrLog = $pdo->prepare("SELECT ID, NOME, id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtUsrLog->execute([$lote['CPF_USUARIO']]);
            $dadosUsrLog = $stmtUsrLog->fetch(PDO::FETCH_ASSOC);

            if ($dadosUsrLog) {
                $coef_24   = 0.048;
                $prev_24x  = $margem_sim > 0 ? round($margem_sim / $coef_24, 2) : 0;
                $texto_log = "Consulta V8 Consignado (Lote)"
                    . " | Margem: R$ " . number_format((float)$margem_sim, 2, ',', '.')
                    . " | Valor Liberado: R$ " . number_format((float)$valor_liberado, 2, ',', '.')
                    . " | Prazo: {$prazo_padrao}x"
                    . ($prev_24x > 0 ? " | Previsão Liberação (24x): R$ " . number_format($prev_24x, 2, ',', '.') : '');

                $cpf_log = str_pad(preg_replace('/\D/', '', $cpfRow['CPF']), 11, '0', STR_PAD_LEFT);

                try {
                    $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO, id_usuario, id_empresa) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$cpf_log, $dadosUsrLog['NOME'], $texto_log, $dadosUsrLog['ID'], $dadosUsrLog['id_empresa']]);
                } catch (\Throwable $e2) {
                    // Fallback sem id_usuario/id_empresa (instalações mais antigas)
                    $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO) VALUES (?, ?, ?)")
                        ->execute([$cpf_log, $dadosUsrLog['NOME'], $texto_log]);
                }
            }
        } catch (\Throwable $eLog) {
            gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'LOG RODAPÉ - ERRO', 'n/a', '', $eLog->getMessage(), 0);
        }
        // ---------------------------------------------------------------

        // Última etapa: Aprovação no W-API (se flag ativo)
        v8EnviarAprovacaoWapi($cpfRow, (float)$valor_liberado, $prazo_padrao, $margem_sim, $lote, $pdo);
    } else {
        $pdo->prepare("UPDATE {$tbl} SET STATUS_V8 = 'ERRO SIMULACAO', OBSERVACAO = ?, DATA_SIMULACAO = NOW() WHERE ID = ?")->execute([$erro_fatal, $cpfRow['ID']]);
        $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_PROCESSADA = QTD_PROCESSADA + 1, QTD_ERRO = QTD_ERRO + 1 WHERE ID = ?")->execute([$id_lote]);
    }
}

// ===============================================
// FATOR CONFERI — atualiza cadastro ao localizar margem
// Chamada nas FASES 1 e 2 imediatamente após confirmação da margem,
// independente do resultado da simulação.
// ===============================================
function v8AtualizarFatorConferi($cpfRow, $lote, $pdo) {
    if (($lote['ATUALIZAR_TELEFONE'] ?? 0) != 1) return;
    $cpf_dono_lote = $lote['CPF_USUARIO'];
    $cpf_busca = ltrim($cpfRow['CPF'], '0');
    try {
        $stmtFc = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = ?");
        $stmtFc->execute([$cpf_dono_lote]); $cliFc = $stmtFc->fetch(PDO::FETCH_ASSOC);
        if (!$cliFc) { gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - SKIP', 'n/a', "cpf_dono={$cpf_dono_lote}", 'Usuario nao encontrado em CLIENTE_CADASTRO', 0); return; }
        $custo_fc = (float)($cliFc['CUSTO_CONSULTA'] ?? 0); $saldo_fc = (float)($cliFc['SALDO'] ?? 0);
        if ($saldo_fc < $custo_fc) { gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - SKIP', 'n/a', "saldo={$saldo_fc} custo={$custo_fc}", 'Saldo FC insuficiente', 0); return; }
        $stmtCfgFC = $pdo->query("SELECT TOKEN_LOTE FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); $tkFC = trim($stmtCfgFC->fetchColumn() ?: '');
        if (empty($tkFC)) { gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - SKIP', 'n/a', '', 'TOKEN_LOTE nao configurado em WAPI_CONFIG_BOT_FATOR', 0); return; }
        $dir_fc_json = $_SERVER['DOCUMENT_ROOT'] . '/modulos/configuracao/fator_conferi/Json_confatorconferi/';
        $arquivos_cache = glob($dir_fc_json . $cpf_busca . "_*.json");
        $usou_cache_fc = false;
        if (!empty($arquivos_cache)) { $json_salvo = @file_get_contents($arquivos_cache[0]); $array_fc = json_decode($json_salvo, true); if (isset($array_fc['CADASTRAIS']['NOME']) && !empty($array_fc['CADASTRAIS']['NOME'])) { $usou_cache_fc = true; inserirDadosOficiais_V8($cpf_busca, $array_fc, $pdo); gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - CACHE', 'n/a', '', 'Dados atualizados via cache local', 200); } }
        if (!$usou_cache_fc) {
            $urlFC = "https://fator.confere.link/api/?acao=CONS_CPF&TK=" . $tkFC . "&DADO=" . $cpf_busca;
            $chFC = curl_init($urlFC); curl_setopt($chFC, CURLOPT_RETURNTRANSFER, true); curl_setopt($chFC, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($chFC, CURLOPT_TIMEOUT, 30);
            $resFC = curl_exec($chFC); $httpFC = curl_getinfo($chFC, CURLINFO_HTTP_CODE); $curlErrFC = curl_error($chFC); curl_close($chFC);
            gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - API', $urlFC, "DADO={$cpf_busca}", !empty($curlErrFC) ? $curlErrFC : mb_substr($resFC, 0, 500), $httpFC);
            if ($httpFC >= 200 && $httpFC < 300) {
                $xmlString = mb_convert_encoding($resFC, 'UTF-8', 'ISO-8859-1'); if(strpos($xmlString, '<') !== false) { $xmlString = substr($xmlString, strpos($xmlString, '<')); }
                libxml_use_internal_errors(true); $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);
                if ($xmlObject && isset($xmlObject->CADASTRAIS->NOME)) {
                    $array_fc = xmlToArray_V8($xmlObject); inserirDadosOficiais_V8($cpf_busca, $array_fc, $pdo);
                    $json_final_fc = json_encode($array_fc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $cad_fc = $array_fc['CADASTRAIS'] ?? []; $nome_cliente_fc = trim((string)($cad_fc['NOME'] ?? ''));
                    $nome_limpo_fc = strtolower(preg_replace('/[^A-Za-z0-9]/', '', str_replace(' ', '', $nome_cliente_fc)));
                    if (!is_dir($dir_fc_json)) { @mkdir($dir_fc_json, 0777, true); }
                    @file_put_contents($dir_fc_json . "{$cpf_busca}_{$nome_limpo_fc}.json", $json_final_fc);
                    if ($custo_fc > 0) { $novo_saldo_fc = $saldo_fc - $custo_fc; $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$novo_saldo_fc, $cpf_dono_lote]); $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL, DATA_HORA) VALUES (?, 'DEBITO', ?, ?, ?, ?, NOW())")->execute([$cpf_dono_lote, "LOTE V8 - ENRIQUECIMENTO CPF {$cpfRow['CPF']} (API)", $custo_fc, $saldo_fc, $novo_saldo_fc]); }
                } else { gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - ERRO XML', $urlFC, '', 'XML invalido ou sem CADASTRAIS->NOME', $httpFC); }
            }
        }
    } catch (Exception $e) { gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'FATOR CONFERI - EXCEPTION', 'n/a', '', $e->getMessage(), 0); }
}

// ===============================================
// APROVAÇÃO NO W-API — envia dados do cliente ao grupo do lote quando simulação OK
// Chamada ao final de v8SimularLote() somente quando sucesso_sim = true
// e o flag ENVIAR_WHATSAPP estiver ativo no lote.
// ===============================================
function v8EnviarAprovacaoWapi($cpfRow, $valor_liberado, $prazo_padrao, $margem_sim, $lote, $pdo) {
    if (($lote['ENVIAR_WHATSAPP'] ?? 0) != 1) return;
    if ((float)$margem_sim <= 1.00) return; // Só envia se margem > R$ 1,00

    $cpf_dono_lote = $lote['CPF_USUARIO'];

    try {
        // 1. Busca GRUPO_WHATS do dono do lote (mesmo padrão do envio de relatório)
        $stmtCli = $pdo->prepare("SELECT GRUPO_WHATS FROM CLIENTE_CADASTRO WHERE CPF = ?");
        $stmtCli->execute([$cpf_dono_lote]);
        $grupo_cliente = $stmtCli->fetchColumn();

        if (empty($grupo_cliente)) {
            gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'APROVACAO WAPI - SKIP', 'n/a', '', 'GRUPO_WHATS nao configurado para o dono do lote', 0);
            return;
        }

        // 2. Busca instância W-API ativa
        $stmtWapi = $pdo->query("SELECT i.INSTANCE_ID, i.TOKEN FROM WAPI_CONFIG c JOIN WAPI_INSTANCIAS i ON c.INSTANCE_ID = i.INSTANCE_ID WHERE c.ATIVO_GLOBAL = 1 LIMIT 1");
        $wapi = $stmtWapi->fetch(PDO::FETCH_ASSOC);

        if (!$wapi || empty($wapi['INSTANCE_ID']) || empty($wapi['TOKEN'])) {
            gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'APROVACAO WAPI - SKIP', 'n/a', '', 'W-API nao esta ativo', 0);
            return;
        }

        // 3. Busca telefones do cliente
        $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 5");
        $stmtTel->execute([$cpfRow['CPF']]);
        $telefones = $stmtTel->fetchAll(PDO::FETCH_COLUMN);
        $telefones_fmt = !empty($telefones) ? implode(' | ', $telefones) : 'Nao informado';

        // 4. Formata dados e monta mensagem
        $cpf_raw = $cpfRow['CPF'];
        $cpf_fmt = strlen($cpf_raw) === 11
            ? substr($cpf_raw,0,3).'.'.substr($cpf_raw,3,3).'.'.substr($cpf_raw,6,3).'-'.substr($cpf_raw,9,2)
            : $cpf_raw;
        $nome       = $cpfRow['NOME'] ?? 'Nao informado';
        $margem_fmt = 'R$ ' . number_format((float)$margem_sim,  2, ',', '.');
        $valor_fmt  = 'R$ ' . number_format((float)$valor_liberado, 2, ',', '.');
        $nomeLote   = $lote['NOME_IMPORTACAO'] ?? '';
        $dataHora   = date('d/m/Y H:i');

        $texto = "✅ *Aprovação V8 — Cliente Qualificado!*\n\n"
               . "*Lote:* {$nomeLote}\n"
               . "*Data/Hora:* {$dataHora}\n\n"
               . "👤 *NOME:* {$nome}\n"
               . "📄 *CPF:* {$cpf_fmt}\n"
               . "💰 *MARGEM:* {$margem_fmt}\n"
               . "🏦 *SIMULAÇÃO:* {$valor_fmt}\n"
               . "📅 *PRAZO:* {$prazo_padrao}x\n"
               . "📱 *TELEFONES:* {$telefones_fmt}";

        // 5. Envia ao grupo
        $phone = preg_replace('/[^0-9\-@a-zA-Z.]/', '', $grupo_cliente);
        if (strpos($phone, '@g.us') === false) $phone .= '@g.us';

        $payload = json_encode(['phone' => $phone, 'message' => $texto, 'delayMessage' => 2]);
        $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapi['INSTANCE_ID'];

        $chW = curl_init($url_wapi);
        curl_setopt($chW, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chW, CURLOPT_POST, true);
        curl_setopt($chW, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($chW, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $wapi['TOKEN'],
            "Content-Type: application/json"
        ]);
        $resW = curl_exec($chW);
        $httpW = curl_getinfo($chW, CURLINFO_HTTP_CODE);
        curl_close($chW);

        gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'APROVACAO WAPI', $url_wapi, json_decode($payload, true), $resW, $httpW);

    } catch (Exception $e) {
        gravarLogIntegracao('logs_consulta_lote', $cpfRow['CPF'], 'APROVACAO WAPI - EXCEPTION', 'n/a', '', $e->getMessage(), 0);
    }
}

// ===============================================
// FUNÇÃO: AVISO DE STATUS DO LOTE VIA WAPI
// Enviada quando o lote muda para CONCLUIDO ou ERRO*.
// Só dispara se AVISO_STATUS_WAPI = 1 no lote.
// ===============================================
function v8EnviarAvisoStatusLote($id_lote, $novo_status, $lote, $pdo) {
    if (($lote['AVISO_STATUS_WAPI'] ?? 0) != 1) return;

    $cpf_dono_lote = $lote['CPF_USUARIO'];
    $nomeLote      = $lote['NOME_IMPORTACAO'] ?? "Lote #{$id_lote}";

    try {
        $stmtCli = $pdo->prepare("SELECT GRUPO_WHATS FROM CLIENTE_CADASTRO WHERE CPF = ?");
        $stmtCli->execute([$cpf_dono_lote]);
        $grupo_cliente = $stmtCli->fetchColumn();

        if (empty($grupo_cliente)) return;

        $stmtWapi = $pdo->query("SELECT i.INSTANCE_ID, i.TOKEN FROM WAPI_CONFIG c JOIN WAPI_INSTANCIAS i ON c.INSTANCE_ID = i.INSTANCE_ID WHERE c.ATIVO_GLOBAL = 1 LIMIT 1");
        $wapi = $stmtWapi->fetch(PDO::FETCH_ASSOC);

        if (!$wapi || empty($wapi['INSTANCE_ID']) || empty($wapi['TOKEN'])) return;

        // Estatísticas do lote para incluir na mensagem
        $tbl_stats = !empty($lote['TABELA_DADOS']) ? $lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
        $stmtStats = $pdo->prepare("
            SELECT
                COUNT(*) as total,
                SUM(STATUS_V8 = 'OK') as ok,
                SUM(STATUS_V8 LIKE 'ERRO%') as erros,
                SUM(STATUS_V8 = 'AGUARDANDO DATAPREV') as dataprev
            FROM {$tbl_stats} WHERE LOTE_ID = ?
        ");
        $stmtStats->execute([$id_lote]);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        $icone   = (strpos($novo_status, 'ERRO') !== false || strpos($novo_status, 'CREDENCIAL') !== false) ? '❌' : '✅';
        $dataHora = date('d/m/Y H:i');

        $linhaStats = '';
        if ($stats && (int)$stats['total'] > 0) {
            $linhaStats = "\n"
                . "📊 *Resumo do Lote:*\n"
                . "   ✅ OK: " . (int)$stats['ok'] . "\n"
                . "   ⏳ Aguard. Dataprev: " . (int)$stats['dataprev'] . "\n"
                . "   ❌ Erros: " . (int)$stats['erros'] . "\n"
                . "   📋 Total: " . (int)$stats['total'];
        }

        $statusLabel = $novo_status === 'AGUARDANDO_DIARIO' ? 'LISTA DO DIA CONCLUÍDA' : $novo_status;

        $texto = "{$icone} *Atualização de Status — Lote V8*\n\n"
               . "*Status:* {$statusLabel}\n"
               . "*Lote ID:* {$id_lote}\n"
               . "*Nome do Lote:* {$nomeLote}\n"
               . "*Data/Hora:* {$dataHora}"
               . $linhaStats . "\n\n"
               . "_CRM Assessoria Consignado_";

        $phone = preg_replace('/[^0-9\-@a-zA-Z.]/', '', $grupo_cliente);
        if (strpos($phone, '@g.us') === false) $phone .= '@g.us';

        $payload = json_encode(['phone' => $phone, 'message' => $texto, 'delayMessage' => 2]);
        $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapi['INSTANCE_ID'];

        $chW = curl_init($url_wapi);
        curl_setopt($chW, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chW, CURLOPT_POST, true);
        curl_setopt($chW, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($chW, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $wapi['TOKEN'],
            "Content-Type: application/json"
        ]);
        $resW = curl_exec($chW);
        $httpW = curl_getinfo($chW, CURLINFO_HTTP_CODE);
        curl_close($chW);

        gravarLogIntegracao('logs_consulta_lote', $cpf_dono_lote, "AVISO STATUS WAPI ({$novo_status})", $url_wapi, json_decode($payload, true), $resW, $httpW);

    } catch (Exception $e) {
        gravarLogIntegracao('logs_consulta_lote', $cpf_dono_lote, 'AVISO STATUS WAPI - EXCEPTION', 'n/a', '', $e->getMessage(), 0);
    }
}

// ===============================================
// FUNÇÕES AUXILIARES PARA SALVAR FATOR CONFERI
// ===============================================
function xmlToArray_V8($xmlObject) { $out = array(); $arrayObj = (array) $xmlObject; if (empty($arrayObj)) return ''; foreach ( $arrayObj as $index => $node ) { $out[$index] = ( is_object ( $node ) || is_array ( $node ) ) ? xmlToArray_V8 ( $node ) : $node; } return $out; }
function getXmlString_V8($node) { if (!isset($node) || is_array($node) || is_object($node)) return ''; return trim((string)$node); }
function formataDataBd_V8($dataStr) { if(empty(trim($dataStr))) return null; $p = explode('/', $dataStr); return (count($p) == 3) ? $p[2].'-'.$p[1].'-'.$p[0] : null; }
function converterEstadoUF_V8($estado) { if(is_array($estado)) return 'SP'; $estado = strtoupper(trim(preg_replace('/[^a-zA-Z\s]/', '', $estado))); if (strlen($estado) == 2) return $estado; $mapa = ['ACRE'=>'AC','ALAGOAS'=>'AL','AMAPA'=>'AP','AMAZONAS'=>'AM','BAHIA'=>'BA','CEARA'=>'CE','DISTRITO FEDERAL'=>'DF','ESPIRITO SANTO'=>'ES','GOIAS'=>'GO','MARANHAO'=>'MA','MATO GROSSO'=>'MT','MATO GROSSO DO SUL'=>'MS','MINAS GERAIS'=>'MG','PARA'=>'PA','PARAIBA'=>'PB','PARANA'=>'PR','PERNAMBUCO'=>'PE','PIAUI'=>'PI','RIO DE JANEIRO'=>'RJ','RIO GRANDE DO NORTE'=>'RN','RIO GRANDE DO SUL'=>'RS','RONDONIA'=>'RO','RORAIMA'=>'RR','SANTA CATARINA'=>'SC','SAO PAULO'=>'SP','SERGIPE'=>'SE','TOCANTINS'=>'TO']; return $mapa[$estado] ?? 'SP'; }

function inserirDadosOficiais_V8($cpf, $array_completo, $pdo) {
    $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : [];
    $nome = getXmlString_V8($cad['NOME'] ?? ''); 
    $nascimento = formataDataBd_V8(getXmlString_V8($cad['NASCTO'] ?? '')); 
    $mae = substr(getXmlString_V8($cad['NOME_MAE'] ?? ''), 0, 150); 
    $sexo = substr(getXmlString_V8($cad['SEXO'] ?? ''), 0, 20); 
    $rg = substr(getXmlString_V8($cad['RG'] ?? ''), 0, 20); 
    $profissao = substr(getXmlString_V8($cad['PROFISSAO'] ?? ''), 0, 50);
    
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg, carteira_profissional) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), rg=VALUES(rg), carteira_profissional=VALUES(carteira_profissional)")->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT), $nome, $sexo, $nascimento, $mae, $rg, $profissao]); 
    
    $pdo->prepare("DELETE FROM enderecos WHERE cpf = ?")->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT)]); 
    if(isset($array_completo['ENDERECOS']['ENDERECO'])) { 
        $enderecos = $array_completo['ENDERECOS']['ENDERECO']; 
        if(isset($enderecos['LOGRADOURO']) || isset($enderecos['CIDADE'])) { $enderecos = [$enderecos]; } 
        $stmtEnd = $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, numero, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?, ?)"); 
        foreach($enderecos as $end) { 
            if(is_array($end)) { 
                $stmtEnd->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT), substr(getXmlString_V8($end['LOGRADOURO'] ?? ''), 0, 65000), substr(getXmlString_V8($end['NUMERO'] ?? ''), 0, 20), substr(getXmlString_V8($end['BAIRRO'] ?? ''), 0, 100), substr(getXmlString_V8($end['CIDADE'] ?? ''), 0, 100), converterEstadoUF_V8(getXmlString_V8($end['ESTADO'] ?? '')), substr(preg_replace('/\D/', '', getXmlString_V8($end['CEP'] ?? '')), 0, 8)]); 
            } 
        } 
    }
    
    $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)"); 
    if(isset($array_completo['TELEFONES_MOVEL']['TELEFONE'])) { 
        $telefones = $array_completo['TELEFONES_MOVEL']['TELEFONE']; 
        if(isset($telefones['NUMERO'])) { $telefones = [$telefones]; } 
        foreach($telefones as $tel) { 
            if(is_array($tel)) { 
                $numLimpo = preg_replace('/\D/', '', getXmlString_V8($tel['NUMERO'] ?? '')); 
                if(strlen($numLimpo) == 13 && strpos($numLimpo, '55') === 0) { $numLimpo = substr($numLimpo, 2); } 
                if(strlen($numLimpo) == 11) { $stmtTel->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT), $numLimpo]); } 
            } 
        } 
    }

    if(isset($array_completo['EMAILS']['EMAIL'])) {
        $lista_emails = $array_completo['EMAILS']['EMAIL'];
        if(is_string($lista_emails)) { $lista_emails = [$lista_emails]; }
        $stmtVerificaEmail = $pdo->prepare("SELECT id FROM emails WHERE cpf = ? AND email = ?");
        $stmtInsereEmail = $pdo->prepare("INSERT INTO emails (cpf, email) VALUES (?, ?)");
        foreach($lista_emails as $em) {
            $email_str = is_array($em) ? getXmlString_V8($em['EMAIL'] ?? '') : getXmlString_V8($em);
            $email_str = strtolower(trim($email_str));
            if(!empty($email_str) && strpos($email_str, '@') !== false) {
                $stmtVerificaEmail->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT), $email_str]);
                if($stmtVerificaEmail->rowCount() == 0) {
                    $stmtInsereEmail->execute([str_pad($cpf, 11, '0', STR_PAD_LEFT), substr($email_str, 0, 150)]);
                }
            }
        }
    }
    $pdo->commit();
}
?>