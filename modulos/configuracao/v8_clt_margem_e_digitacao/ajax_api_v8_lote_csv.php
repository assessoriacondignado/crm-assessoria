<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
session_start();

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($acao !== 'exportar_resultado_lote' && $acao !== 'exportar_planilha_importada' && $acao !== 'exportar_tudo_lotes') {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4");

    $caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

    $usuario_logado_id = (int)($_SESSION['usuario_id'] ?? 1);
    $usuario_logado_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

    switch ($acao) {
        
        case 'upload_csv_lote':
            $agrupamento = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_ \-]/', '_', $_POST['agrupamento'] ?? 'LOTE_V8')));
            $chave_id = (int)($_POST['chave_id'] ?? 0);
            
            $agendamento_tipo = $_POST['agendamento_tipo'] ?? 'IMEDIATO';
            $data_hora_agendada = !empty($_POST['data_hora_agendada']) ? $_POST['data_hora_agendada'] : null;
            $dia_mes_agendado = !empty($_POST['dia_mes_agendado']) ? (int)$_POST['dia_mes_agendado'] : null;
            $hora_inicio_diario = ($agendamento_tipo === 'DIARIO' && !empty($_POST['hora_inicio_diario'])) ? trim($_POST['hora_inicio_diario']) : null;
            $dias_mes_diario = ($agendamento_tipo === 'DIARIO' && !empty($_POST['dias_mes_diario'])) ? trim($_POST['dias_mes_diario']) : null;
            $limite_diario = (int)($_POST['limite_diario'] ?? 0);
            $atualizar_telefone = (isset($_POST['atualizar_telefone']) && $_POST['atualizar_telefone'] == '1') ? 1 : 0;
            $enviar_whats = (isset($_POST['enviar_whats']) && $_POST['enviar_whats'] == '1') ? 1 : 0;
            $somente_simular = (isset($_POST['somente_simular']) && $_POST['somente_simular'] == '1') ? 1 : 0;
            $enviar_arquivo_whatsapp = (isset($_POST['enviar_arquivo_whatsapp']) && $_POST['enviar_arquivo_whatsapp'] == '1') ? 1 : 0;
            $hora_inativacao_inicio = !empty(trim($_POST['hora_inativacao_inicio'] ?? '')) ? trim($_POST['hora_inativacao_inicio']) : null;
            $hora_inativacao_fim    = !empty(trim($_POST['hora_inativacao_fim']    ?? '')) ? trim($_POST['hora_inativacao_fim'])    : null;
            
            if ($chave_id <= 0) throw new Exception("Selecione uma Credencial/Chave para cobrar o lote.");
            if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] != UPLOAD_ERR_OK) throw new Exception("Nenhum arquivo CSV recebido.");
            if (strtolower(pathinfo($_FILES['arquivo_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') throw new Exception("O arquivo precisa ser obrigatoriamente .csv.");

            $stmtCli = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA, CPF_USUARIO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
            $stmtCli->execute([$chave_id]);
            $chave = $stmtCli->fetch(PDO::FETCH_ASSOC);
            if (!$chave) throw new Exception("Chave selecionada não encontrada no banco.");

            $dono_lote_cpf = !empty($chave['CPF_USUARIO']) ? $chave['CPF_USUARIO'] : $usuario_logado_cpf;
            $dono_lote_id = $usuario_logado_id; 

            $handle = @fopen($_FILES['arquivo_csv']['tmp_name'], "r");
            $header = fgetcsv($handle, 1000, ";");
            if (count($header) == 1 && strpos($header[0], ',') !== false) { rewind($handle); $header = fgetcsv($handle, 1000, ","); }
            
            $idx_cpf = -1; $idx_nasc = -1; $idx_sexo = -1; $idx_nome = -1;
            foreach ($header as $k => $v) {
                $v = strtolower(trim($v));
                if (strpos($v, 'cpf') !== false) $idx_cpf = $k;
                if (strpos($v, 'nascimento') !== false || strpos($v, 'nasc') !== false) $idx_nasc = $k;
                if (strpos($v, 'sexo') !== false || strpos($v, 'genero') !== false) $idx_sexo = $k;
                if (strpos($v, 'nome') !== false) $idx_nome = $k;
            }

            if ($idx_cpf === -1) { throw new Exception("CSV Inválido! A planilha precisa ter no mínimo o cabeçalho 'CPF'."); }

            $linhas_validas = [];
            $linhas_descartadas = 0;
            while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                try {
                    if (count($data) == 1 && strpos($data[0], ',') !== false) { $data = explode(',', $data[0]); }
                    $cpf = isset($data[$idx_cpf]) ? str_pad(preg_replace('/\D/', '', $data[$idx_cpf]), 11, '0', STR_PAD_LEFT) : '';
                    if (strlen($cpf) !== 11) { $linhas_descartadas++; continue; }
                    $nasc = ($idx_nasc !== -1 && isset($data[$idx_nasc])) ? trim($data[$idx_nasc]) : '';
                    if (strpos($nasc, '/') !== false) { $p = explode('/', $nasc); if (count($p) == 3) $nasc = "{$p[2]}-{$p[1]}-{$p[0]}"; }
                    // Valida formato YYYY-MM-DD; descarta datas inválidas sem interromper
                    if (!empty($nasc)) {
                        $dt = DateTime::createFromFormat('Y-m-d', $nasc);
                        $nasc = ($dt && $dt->format('Y-m-d') === $nasc) ? $nasc : null;
                    } else { $nasc = null; }
                    $sexo = ($idx_sexo !== -1 && isset($data[$idx_sexo])) ? strtoupper(trim($data[$idx_sexo])) : '';
                    $genero = (strpos($sexo, 'M') === 0) ? 'male' : 'female';
                    $nome = ($idx_nome !== -1 && isset($data[$idx_nome])) ? mb_strtoupper(trim($data[$idx_nome]), 'UTF-8') : 'NÃO INFORMADO';
                    $linhas_validas[] = ['cpf' => $cpf, 'nascimento' => $nasc, 'sexo' => $genero, 'nome' => $nome];
                } catch (Exception $e) { $linhas_descartadas++; continue; }
            }
            fclose($handle);

            $total = count($linhas_validas);
            if ($total == 0) throw new Exception("Nenhum CPF válido localizado no arquivo.");
            if ($total > 100000) throw new Exception("Atenção: O limite máximo é de 100.000 CPFs por importação.");

            $custo_lote = $total * (float)$chave['CUSTO_CONSULTA'];
            if ((float)$chave['SALDO'] < $custo_lote && $custo_lote > 0) { throw new Exception("Saldo Insuficiente! Lote custará R$ " . number_format($custo_lote, 2, ',', '.') . ". Saldo atual: R$ " . number_format((float)$chave['SALDO'], 2, ',', '.')); }

            $pdo->beginTransaction();
            
            // DIARIO: validar que tem hora configurada
            if ($agendamento_tipo === 'DIARIO' && empty($hora_inicio_diario)) {
                throw new Exception("Para agendamento Diário, informe o horário de início.");
            }
            // DIARIO: status inicial = aguardando, não dispara worker imediatamente
            $status_fila_inicial = ($agendamento_tipo === 'DIARIO') ? 'AGUARDANDO_DIARIO' : 'PENDENTE';

            $stmtLote = $pdo->prepare("INSERT INTO INTEGRACAO_V8_IMPORTACAO_LOTE
                (NOME_IMPORTACAO, USUARIO_ID, CPF_USUARIO, CHAVE_ID, ARQUIVO_CAMINHO, QTD_TOTAL, AGENDAMENTO_TIPO, DATA_HORA_AGENDADA, DIA_MES_AGENDADO, HORA_INICIO_DIARIO, DIAS_MES_DIARIO, LIMITE_DIARIO, ATUALIZAR_TELEFONE, ENVIAR_WHATSAPP, SOMENTE_SIMULAR, ENVIAR_ARQUIVO_WHATSAPP, HORA_INATIVACAO_INICIO, HORA_INATIVACAO_FIM, STATUS_FILA)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtLote->execute([$agrupamento, $dono_lote_id, $dono_lote_cpf, $chave_id, $_FILES['arquivo_csv']['name'], $total, $agendamento_tipo, $data_hora_agendada, $dia_mes_agendado, $hora_inicio_diario, $dias_mes_diario, $limite_diario, $atualizar_telefone, $enviar_whats, $somente_simular, $enviar_arquivo_whatsapp, $hora_inativacao_inicio, $hora_inativacao_fim, $status_fila_inicial]);
            $id_lote = $pdo->lastInsertId();

            $stmtCpf = $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTROCONSULTA_LOTE (LOTE_ID, CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, VALOR_MARGEM, CONSULT_ID, CONFIG_ID, OBSERVACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmtBuscaLote = null; $stmtBuscaManual = null;
            if ($somente_simular == 1) {
                $stmtBuscaLote = $pdo->prepare("SELECT CONSULT_ID, CONFIG_ID, VALOR_MARGEM FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE CPF = ? AND VALOR_MARGEM IS NOT NULL AND VALOR_MARGEM > 0 AND CONSULT_ID IS NOT NULL ORDER BY ID DESC LIMIT 1");
                $stmtBuscaManual = $pdo->prepare("SELECT r.CONSULT_ID, s.CONFIG_ID, s.MARGEM_DISPONIVEL as VALOR_MARGEM FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s JOIN INTEGRACAO_V8_REGISTROCONSULTA r ON r.ID = s.ID_FILA WHERE s.CPF = ? AND s.MARGEM_DISPONIVEL > 0 AND r.CONSULT_ID IS NOT NULL ORDER BY s.ID DESC LIMIT 1");
            }

            $inseridos = 0; $erros_insert = 0;
            foreach ($linhas_validas as $l) {
                try {
                    $status_v8 = 'NA FILA'; $v_margem = null; $c_id = null; $cfg_id = null; $obs = null;
                    if ($somente_simular == 1) {
                        $achou = false;
                        $stmtBuscaLote->execute([$l['cpf']]); $margLote = $stmtBuscaLote->fetch(PDO::FETCH_ASSOC);
                        if ($margLote) { $c_id = $margLote['CONSULT_ID']; $cfg_id = $margLote['CONFIG_ID']; $v_margem = $margLote['VALOR_MARGEM']; $achou = true; }
                        else {
                            $stmtBuscaManual->execute([$l['cpf']]); $margManual = $stmtBuscaManual->fetch(PDO::FETCH_ASSOC);
                            if ($margManual) { $c_id = $margManual['CONSULT_ID']; $cfg_id = $margManual['CONFIG_ID']; $v_margem = $margManual['VALOR_MARGEM']; $achou = true; }
                        }
                        if ($achou) { $status_v8 = 'AGUARDANDO SIMULACAO'; $obs = 'Pulo direto para simulação (Margem prévia localizada localmente).'; }
                        else { $status_v8 = 'RECUPERAR V8'; $obs = 'Buscando consentimento e margem direto na API da V8...'; }
                    }
                    $stmtCpf->execute([$id_lote, $l['cpf'], $l['nome'], $l['nascimento'], $l['sexo'], $status_v8, $v_margem, $c_id, $cfg_id, $obs]);
                    $inseridos++;
                } catch (Exception $e) { $erros_insert++; continue; }
            }

            // Atualiza QTD_TOTAL com o que realmente foi inserido
            if ($inseridos !== $total) {
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_TOTAL = ? WHERE ID = ?")->execute([$inseridos, $id_lote]);
                $total = $inseridos;
            }

            $pdo->commit();

            $aviso_descarte = ($linhas_descartadas + $erros_insert) > 0
                ? " ({$linhas_descartadas} linhas com erro de formato descartadas.)"
                : '';

            if ($agendamento_tipo !== 'DIARIO') {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $dono_lote_cpf;
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                $msg_retorno = "Lote de $total CPFs enviado com sucesso! Processamento iniciado.{$aviso_descarte}";
            } else {
                $msg_retorno = "Lote de $total CPFs criado! Será iniciado automaticamente às {$hora_inicio_diario} nos dias configurados.";
            }

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => $msg_retorno, 'cpf_dono' => $dono_lote_cpf]); exit;

        // ==================================================================================================
        // NOVA AÇÃO DE APPEND: INCLUIR MAIS CLIENTE
        // ==================================================================================================
        case 'append_csv_lote':
            $id_lote = (int)$_POST['append_lote_id'];
            if ($id_lote <= 0) throw new Exception("Selecione um Lote Destino válido.");
            
            if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] != UPLOAD_ERR_OK) throw new Exception("Nenhum arquivo CSV recebido.");
            if (strtolower(pathinfo($_FILES['arquivo_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') throw new Exception("O arquivo precisa ser obrigatoriamente .csv.");

            $stmtLote = $pdo->prepare("SELECT CHAVE_ID, CPF_USUARIO, SOMENTE_SIMULAR FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $loteAtual = $stmtLote->fetch(PDO::FETCH_ASSOC);
            if (!$loteAtual) throw new Exception("Lote não encontrado.");

            $chave_id = $loteAtual['CHAVE_ID'];
            $somente_simular = $loteAtual['SOMENTE_SIMULAR'];
            $dono_lote_cpf = $loteAtual['CPF_USUARIO'];

            $stmtCli = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
            $stmtCli->execute([$chave_id]);
            $chave = $stmtCli->fetch(PDO::FETCH_ASSOC);

            // Carrega CPFs que JÁ ESTÃO no lote
            $stmtExistentes = $pdo->prepare("SELECT CPF FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE LOTE_ID = ?");
            $stmtExistentes->execute([$id_lote]);
            $cpfsLoteExistentes = $stmtExistentes->fetchAll(PDO::FETCH_COLUMN);
            $hashExistentes = array_flip($cpfsLoteExistentes);

            $handle = @fopen($_FILES['arquivo_csv']['tmp_name'], "r");
            $header = fgetcsv($handle, 1000, ";");
            if (count($header) == 1 && strpos($header[0], ',') !== false) { rewind($handle); $header = fgetcsv($handle, 1000, ","); }
            
            $idx_cpf = -1; $idx_nasc = -1; $idx_sexo = -1; $idx_nome = -1;
            foreach ($header as $k => $v) {
                $v = strtolower(trim($v));
                if (strpos($v, 'cpf') !== false) $idx_cpf = $k;
                if (strpos($v, 'nascimento') !== false || strpos($v, 'nasc') !== false) $idx_nasc = $k;
                if (strpos($v, 'sexo') !== false || strpos($v, 'genero') !== false) $idx_sexo = $k;
                if (strpos($v, 'nome') !== false) $idx_nome = $k;
            }

            if ($idx_cpf === -1) { throw new Exception("CSV Inválido! Cabeçalho 'CPF' não encontrado."); }

            $linhas_validas_novas = [];
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if(count($data) == 1 && strpos($data[0], ',') !== false) { $data = explode(',', $data[0]); }
                $cpf = isset($data[$idx_cpf]) ? str_pad(preg_replace('/\D/', '', $data[$idx_cpf]), 11, '0', STR_PAD_LEFT) : '';
                
                if (strlen($cpf) == 11) {
                    // SE O CPF JÁ ESTÁ NO LOTE, ELE PULA (Evita duplicar)
                    if (isset($hashExistentes[$cpf])) continue;

                    try {
                        $nasc = ($idx_nasc !== -1 && isset($data[$idx_nasc])) ? trim($data[$idx_nasc]) : '';
                        if (strpos($nasc, '/') !== false) { $p = explode('/', $nasc); if (count($p) == 3) $nasc = "{$p[2]}-{$p[1]}-{$p[0]}"; }
                        if (!empty($nasc)) { $dt = DateTime::createFromFormat('Y-m-d', $nasc); $nasc = ($dt && $dt->format('Y-m-d') === $nasc) ? $nasc : null; } else { $nasc = null; }
                        $sexo = ($idx_sexo !== -1 && isset($data[$idx_sexo])) ? strtoupper(trim($data[$idx_sexo])) : '';
                        $genero = (strpos($sexo, 'M') === 0) ? 'male' : 'female';
                        $nome = ($idx_nome !== -1 && isset($data[$idx_nome])) ? mb_strtoupper(trim($data[$idx_nome]), 'UTF-8') : 'NÃO INFORMADO';
                        $linhas_validas_novas[] = ['cpf' => $cpf, 'nascimento' => $nasc, 'sexo' => $genero, 'nome' => $nome];
                    } catch (Exception $e) { continue; }
                    $hashExistentes[$cpf] = true; 
                }
            }
            fclose($handle);

            $total_novos = count($linhas_validas_novas);
            if ($total_novos == 0) throw new Exception("Nenhum CPF novo encontrado. Todos os CPFs da planilha já existem neste lote ou a planilha é inválida.");

            $custo_lote = $total_novos * (float)$chave['CUSTO_CONSULTA'];
            if ((float)$chave['SALDO'] < $custo_lote && $custo_lote > 0) { throw new Exception("Saldo Insuficiente para os novos CPFs! Custo: R$ " . number_format($custo_lote, 2, ',', '.') . "."); }

            $pdo->beginTransaction();
            
            // Atualiza o total e reativa o lote
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_TOTAL = QTD_TOTAL + ?, STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$total_novos, $id_lote]);
            $stmtCpf = $pdo->prepare("INSERT INTO INTEGRACAO_V8_REGISTROCONSULTA_LOTE (LOTE_ID, CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, VALOR_MARGEM, CONSULT_ID, CONFIG_ID, OBSERVACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmtBuscaLote = null; $stmtBuscaManual = null;
            if ($somente_simular == 1) {
                $stmtBuscaLote = $pdo->prepare("SELECT CONSULT_ID, CONFIG_ID, VALOR_MARGEM FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE CPF = ? AND VALOR_MARGEM IS NOT NULL AND VALOR_MARGEM > 0 AND CONSULT_ID IS NOT NULL ORDER BY ID DESC LIMIT 1");
                $stmtBuscaManual = $pdo->prepare("SELECT r.CONSULT_ID, s.CONFIG_ID, s.MARGEM_DISPONIVEL as VALOR_MARGEM FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s JOIN INTEGRACAO_V8_REGISTROCONSULTA r ON r.ID = s.ID_FILA WHERE s.CPF = ? AND s.MARGEM_DISPONIVEL > 0 AND r.CONSULT_ID IS NOT NULL ORDER BY s.ID DESC LIMIT 1");
            }

            $inseridos_novos = 0; $erros_insert_novos = 0;
            foreach ($linhas_validas_novas as $l) {
                try {
                    $status_v8 = 'NA FILA'; $v_margem = null; $c_id = null; $cfg_id = null; $obs = null;
                    if ($somente_simular == 1) {
                        $achou = false;
                        $stmtBuscaLote->execute([$l['cpf']]); $margLote = $stmtBuscaLote->fetch(PDO::FETCH_ASSOC);
                        if ($margLote) { $c_id = $margLote['CONSULT_ID']; $cfg_id = $margLote['CONFIG_ID']; $v_margem = $margLote['VALOR_MARGEM']; $achou = true; }
                        else {
                            $stmtBuscaManual->execute([$l['cpf']]); $margManual = $stmtBuscaManual->fetch(PDO::FETCH_ASSOC);
                            if ($margManual) { $c_id = $margManual['CONSULT_ID']; $cfg_id = $margManual['CONFIG_ID']; $v_margem = $margManual['VALOR_MARGEM']; $achou = true; }
                        }
                        if ($achou) { $status_v8 = 'AGUARDANDO SIMULACAO'; $obs = 'Pulo direto para simulação (Margem prévia localizada).'; }
                        else { $status_v8 = 'RECUPERAR V8'; $obs = 'Buscando consentimento e margem direto na API...'; }
                    }
                    $stmtCpf->execute([$id_lote, $l['cpf'], $l['nome'], $l['nascimento'], $l['sexo'], $status_v8, $v_margem, $c_id, $cfg_id, $obs]);
                    $inseridos_novos++;
                } catch (Exception $e) { $erros_insert_novos++; continue; }
            }

            // Ajusta QTD_TOTAL com o que realmente foi inserido
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET QTD_TOTAL = QTD_TOTAL + ?, STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$inseridos_novos, $id_lote]);

            $pdo->commit();

            $aviso_append = ($erros_insert_novos > 0) ? " ({$erros_insert_novos} linhas com erro de formato descartadas.)" : '';

            // Desperta o robô
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $dono_lote_cpf;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "{$inseridos_novos} CPFs inéditos foram incluídos ao lote com sucesso!{$aviso_append}", 'cpf_dono' => $dono_lote_cpf]); exit;

        case 'listar_lotes':
            $perm_meu_registro = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO') : true;
            
            // Lógica do Filtro Avançado
            $regras = isset($_POST['regras']) ? json_decode($_POST['regras'], true) : [];
            $where = " WHERE 1=1 ";
            $params = [];
            $temFiltroStatusLote = false;

            if (is_array($regras) && count($regras) > 0) {
                foreach ($regras as $r) {
                    $campo = $r['campo'];
                    $operador = $r['operador'];
                    $valor = $r['valor'];

                    $allowed_campos = [
                        'l.ID', 'l.NOME_IMPORTACAO', 'c.CLIENTE_NOME', 'l.STATUS_FILA', 'l.STATUS_LOTE',
                        'l.QTD_TOTAL', 'l.QTD_PROCESSADA', 'l.QTD_SUCESSO', 'l.QTD_ERRO',
                        'l.PROCESSADOS_HOJE', 'l.LIMITE_DIARIO',
                        'l.DATA_IMPORTACAO', 'l.DATA_FINALIZACAO', 'l.DATA_HORA_AGENDADA', 'l.ULTIMO_PROCESSAMENTO',
                        'l.AGENDAMENTO_TIPO', 'l.CPF_USUARIO'
                    ];
                    if(!in_array($campo, $allowed_campos)) continue;

                    if ($campo === 'l.STATUS_LOTE') $temFiltroStatusLote = true;

                    if ($operador == 'vazio') {
                        $where .= " AND ($campo IS NULL OR $campo = '') ";
                    } elseif ($operador == 'maior_que') {
                        $where .= " AND $campo > ? "; $params[] = $valor;
                    } elseif ($operador == 'menor_que') {
                        $where .= " AND $campo < ? "; $params[] = $valor;
                    } elseif ($operador == 'contem') {
                        $where .= " AND $campo LIKE ? "; $params[] = "%$valor%";
                    } elseif ($operador == 'nao_contem') {
                        $where .= " AND $campo NOT LIKE ? "; $params[] = "%$valor%";
                    } elseif ($operador == 'comeca_com') {
                        $where .= " AND $campo LIKE ? "; $params[] = "$valor%";
                    } elseif ($operador == 'igual') {
                        $where .= " AND $campo = ? "; $params[] = $valor;
                    }
                }
            }

            // Padrão: Se não filtrou por Status Lote, lista apenas os Ativos
            if (!$temFiltroStatusLote) {
                $where .= " AND (l.STATUS_LOTE = 'ATIVO' OR l.STATUS_LOTE IS NULL) ";
            }

            if (!$perm_meu_registro) {
                $where .= " AND l.CPF_USUARIO = ? ";
                $params[] = $usuario_logado_cpf;
            }

            // Verifica se o usuário tem chaves (apenas se tiver restrição de visualizar tudo)
            if (!$perm_meu_registro) {
                $stmtChaves = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE USUARIO_ID = ? OR CPF_USUARIO = ?");
                $stmtChaves->execute([$usuario_logado_id, $usuario_logado_cpf]);
                if (!$stmtChaves->fetchColumn()) {
                    ob_end_clean(); echo json_encode(['success' => true, 'data' => []]); exit; 
                }
            }

            // JOIN COM CLIENTE_USUARIO PARA TRAZER O NOME REAL (COM CORREÇÃO DE COLLATION)
            $sql = "SELECT l.*, c.CLIENTE_NOME, c.USERNAME_API, c.TABELA_PADRAO, c.PRAZO_PADRAO, DATE_FORMAT(l.DATA_IMPORTACAO, '%d/%m/%Y %H:%i') as DATA_BR,
                           u.NOME as NOME_USUARIO 
                    FROM INTEGRACAO_V8_IMPORTACAO_LOTE l 
                    LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO c ON l.CHAVE_ID = c.ID 
                    LEFT JOIN CLIENTE_USUARIO u ON l.CPF_USUARIO COLLATE utf8mb4_unicode_ci = u.CPF COLLATE utf8mb4_unicode_ci
                    $where ORDER BY l.ID DESC LIMIT 50";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $lotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $lote_ids = array_column($lotes, 'ID');
            if (!empty($lote_ids)) {
                $inQuery = implode(',', array_fill(0, count($lote_ids), '?'));

                // Contagens totais por status
                $stmtStats = $pdo->prepare("SELECT LOTE_ID, STATUS_V8, COUNT(*) as qtd FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE LOTE_ID IN ($inQuery) GROUP BY LOTE_ID, STATUS_V8");
                $stmtStats->execute($lote_ids);
                $rawStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

                // Contagens do dia (desde 00h) — consentimentos e simulações
                $stmtHoje = $pdo->prepare("SELECT LOTE_ID,
                    SUM(CASE WHEN DATA_CONSENTIMENTO >= CURDATE() THEN 1 ELSE 0 END) as c_hoje,
                    SUM(CASE WHEN DATA_SIMULACAO >= CURDATE() AND STATUS_V8 = 'OK' THEN 1 ELSE 0 END) as s_hoje
                    FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE LOTE_ID IN ($inQuery) GROUP BY LOTE_ID");
                $stmtHoje->execute($lote_ids);
                $hojeByLote = [];
                foreach ($stmtHoje->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $hojeByLote[$r['LOTE_ID']] = ['c_hoje' => (int)$r['c_hoje'], 's_hoje' => (int)$r['s_hoje']];
                }

                $statsByLote = [];
                foreach($rawStats as $row) {
                    $lid = $row['LOTE_ID'];
                    $st = strtoupper($row['STATUS_V8']);
                    $q = (int)$row['qtd'];

                    if(!isset($statsByLote[$lid])) {
                        $statsByLote[$lid] = ['c_ok'=>0, 'c_err'=>0, 'm_ok'=>0, 'm_err'=>0, 's_ok'=>0, 's_err'=>0, 'dataprev'=>0];
                    }

                    if (strpos($st, 'ERRO CONSULTA') !== false || strpos($st, 'ERRO SALDO') !== false) {
                        $statsByLote[$lid]['c_err'] += $q;
                    } elseif (strpos($st, 'AGUARDANDO MARGEM') !== false || strpos($st, 'RECUPERAR V8') !== false) {
                        $statsByLote[$lid]['c_ok'] += $q;
                    } elseif ($st === 'AGUARDANDO DATAPREV') {
                        $statsByLote[$lid]['c_ok'] += $q;
                        $statsByLote[$lid]['dataprev'] += $q;
                    } elseif (strpos($st, 'ERRO MARGEM') !== false) {
                        $statsByLote[$lid]['c_ok'] += $q;
                        $statsByLote[$lid]['m_err'] += $q;
                    } elseif (strpos($st, 'AGUARDANDO SIMULACAO') !== false) {
                        $statsByLote[$lid]['c_ok'] += $q;
                        $statsByLote[$lid]['m_ok'] += $q;
                    } elseif (strpos($st, 'ERRO SIMULACAO') !== false) {
                        $statsByLote[$lid]['c_ok'] += $q;
                        $statsByLote[$lid]['m_ok'] += $q;
                        $statsByLote[$lid]['s_err'] += $q;
                    } elseif ($st === 'OK') {
                        $statsByLote[$lid]['c_ok'] += $q;
                        $statsByLote[$lid]['m_ok'] += $q;
                        $statsByLote[$lid]['s_ok'] += $q;
                    }
                }

                foreach ($lotes as &$l) {
                    $lid = $l['ID'];
                    $hoje = $hojeByLote[$lid] ?? ['c_hoje'=>0, 's_hoje'=>0];
                    $funil = isset($statsByLote[$lid]) ? $statsByLote[$lid] : ['c_ok'=>0, 'c_err'=>0, 'm_ok'=>0, 'm_err'=>0, 's_ok'=>0, 's_err'=>0, 'dataprev'=>0];
                    $funil['c_hoje'] = $hoje['c_hoje'];
                    $funil['s_hoje'] = $hoje['s_hoje'];
                    // m_hoje: aprovações de margem hoje ≈ simulações OK + erros de simulação hoje (mesma rodada)
                    $funil['m_hoje'] = $hoje['s_hoje']; // aproximação conservadora (simulação implica margem OK)
                    $l['funil'] = $funil;
                }
            }

            ob_end_clean(); echo json_encode(['success' => true, 'data' => $lotes]); exit;

        // ==================================================================================================
        // NOVA AÇÃO: EXPORTAR TODOS OS LOTES FILTRADOS (COM CHECAGEM NATIVA DE PERMISSÃO)
        // ==================================================================================================
        case 'exportar_tudo_lotes':
            $pode_exportar_tudo = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_EXPORTAR_TUDO', 'FUNCAO') : false;
            
            if (!$pode_exportar_tudo) {
                die("Acesso negado. Seu grupo de usuário não possui permissão para realizar esta exportação em massa.");
            }

            $perm_meu_registro = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO') : true;
            
            $regras = isset($_GET['regras']) ? json_decode($_GET['regras'], true) : [];
            $where = " WHERE 1=1 ";
            $params = [];
            $temFiltroStatusLote = false;

            if (is_array($regras) && count($regras) > 0) {
                foreach ($regras as $r) {
                    $campo = $r['campo'];
                    $operador = $r['operador'];
                    $valor = $r['valor'];

                    $allowed_campos = [
                        'l.ID', 'l.NOME_IMPORTACAO', 'c.CLIENTE_NOME', 'l.STATUS_FILA', 'l.STATUS_LOTE',
                        'l.QTD_TOTAL', 'l.QTD_PROCESSADA', 'l.QTD_SUCESSO', 'l.QTD_ERRO',
                        'l.PROCESSADOS_HOJE', 'l.LIMITE_DIARIO',
                        'l.DATA_IMPORTACAO', 'l.DATA_FINALIZACAO', 'l.DATA_HORA_AGENDADA', 'l.ULTIMO_PROCESSAMENTO',
                        'l.AGENDAMENTO_TIPO', 'l.CPF_USUARIO'
                    ];
                    if(!in_array($campo, $allowed_campos)) continue;

                    if ($campo === 'l.STATUS_LOTE') $temFiltroStatusLote = true;

                    if ($operador == 'vazio') {
                        $where .= " AND ($campo IS NULL OR $campo = '') ";
                    } elseif ($operador == 'maior_que') {
                        $where .= " AND $campo > ? "; $params[] = $valor;
                    } elseif ($operador == 'menor_que') {
                        $where .= " AND $campo < ? "; $params[] = $valor;
                    } elseif ($operador == 'contem') {
                        $where .= " AND $campo LIKE ? "; $params[] = "%$valor%";
                    } elseif ($operador == 'nao_contem') {
                        $where .= " AND $campo NOT LIKE ? "; $params[] = "%$valor%";
                    } elseif ($operador == 'comeca_com') {
                        $where .= " AND $campo LIKE ? "; $params[] = "$valor%";
                    } elseif ($operador == 'igual') {
                        $where .= " AND $campo = ? "; $params[] = $valor;
                    }
                }
            }

            if (!$temFiltroStatusLote) {
                $where .= " AND (l.STATUS_LOTE = 'ATIVO' OR l.STATUS_LOTE IS NULL) ";
            }

            if (!$perm_meu_registro) {
                $where .= " AND l.CPF_USUARIO = ? ";
                $params[] = $usuario_logado_cpf;
            }

            $sqlLotes = "SELECT l.ID FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO c ON l.CHAVE_ID = c.ID $where";
            $stmtLotes = $pdo->prepare($sqlLotes);
            $stmtLotes->execute($params);
            $lote_ids = $stmtLotes->fetchAll(PDO::FETCH_COLUMN);

            if (empty($lote_ids)) {
                die("Nenhum lote localizado com os filtros atuais.");
            }

            ob_end_clean(); 
            header('Content-Type: text/csv; charset=utf-8'); 
            $somente_simulados = isset($_GET['somente_simulados']) && $_GET['somente_simulados'] == '1';
            $sufixo_arquivo = $somente_simulados ? 'ComValorLiquido' : 'Tudo';
            header('Content-Disposition: attachment; filename="Exportacao_V8_' . $sufixo_arquivo . '_' . date('dmY_Hi') . '.csv"');

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

            $inQuery = implode(',', array_fill(0, count($lote_ids), '?'));
            $filtro_simulados = $somente_simulados ? " AND c.VALOR_LIQUIDO IS NOT NULL AND c.VALOR_LIQUIDO > 0 " : "";
            $sqlCpfs = "
                SELECT c.*, l.NOME_IMPORTACAO, ca.TABELA_PADRAO
                FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE c
                JOIN INTEGRACAO_V8_IMPORTACAO_LOTE l ON c.LOTE_ID = l.ID
                LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON l.CHAVE_ID = ca.ID
                WHERE c.LOTE_ID IN ($inQuery) $filtro_simulados
            ";
            $stmtCpfs = $pdo->prepare($sqlCpfs);
            $stmtCpfs->execute($lote_ids);
            
            $stmtEnd = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) { 
                $linha = [ 
                    $row['NOME_IMPORTACAO'], 
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '-', 
                    $row['CPF'] . " ", 
                    $row['NOME'], 
                    $row['NASCIMENTO'] ? date('d/m/Y', strtotime($row['NASCIMENTO'])) : '', 
                    $row['SEXO'] == 'male' ? 'MASCULINO' : 'FEMININO', 
                    $row['STATUS_V8'], 
                    $row['OBSERVACAO'], 
                    $row['TABELA_PADRAO'] ?? '-',
                    $row['VALOR_MARGEM'] ? 'R$ ' . number_format($row['VALOR_MARGEM'], 2, ',', '.') : '-', 
                    $row['PRAZO'] ? $row['PRAZO'] . 'x' : '-', 
                    $row['VALOR_LIQUIDO'] ? 'R$ ' . number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : '-',
                    $row['STATUS_WHATSAPP'] ?? 'NAO ENVIADO'
                ];
                
                $stmtEnd->execute([$row['CPF']]);
                $end = $stmtEnd->fetch(PDO::FETCH_ASSOC);
                if ($end) {
                    $linha[] = $end['logradouro']; $linha[] = $end['numero']; $linha[] = $end['bairro'];
                    $linha[] = $end['cidade']; $linha[] = $end['uf']; $linha[] = $end['cep'] ? $end['cep'] . " " : ''; 
                } else {
                    $linha = array_merge($linha, ['', '', '', '', '', '']); 
                }

                $stmtEmail->execute([$row['CPF']]);
                $emails = $stmtEmail->fetchAll(PDO::FETCH_COLUMN);
                for($i = 0; $i < 3; $i++) { $linha[] = isset($emails[$i]) ? $emails[$i] : ''; }
                
                $stmtTel->execute([$row['CPF']]);
                $telefones = $stmtTel->fetchAll(PDO::FETCH_COLUMN);
                $ddd = (isset($telefones[0]) && strlen($telefones[0]) >= 10) ? substr($telefones[0], 0, 2) : '';
                $linha[] = $ddd;

                for($i = 0; $i < 10; $i++) {
                    $tel = isset($telefones[$i]) ? $telefones[$i] : '';
                    $linha[] = $tel ? $tel . " " : ''; 
                }

                fputcsv($output, $linha, ";"); 
            }
            fclose($output); 
            exit;

        case 'exportar_resultado_lote':
            $id_lote = (int)($_GET['id_lote'] ?? $_POST['id_lote'] ?? 0);
            if (!$id_lote) die('ID do lote não informado.');

            $perm_meu_registro = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO') : true;

            $stmtLote = $pdo->prepare("SELECT l.*, ca.TABELA_PADRAO FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON l.CHAVE_ID = ca.ID WHERE l.ID = ?");
            $stmtLote->execute([$id_lote]);
            $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

            if (!$lote) die('Lote não encontrado.');
            if (!$perm_meu_registro && $lote['CPF_USUARIO'] !== $usuario_logado_cpf) die('Acesso negado a este lote.');

            ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="Lote_' . $id_lote . '_' . date('dmY_Hi') . '.csv"');

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

            $cabecalho = [
                'ID LOTE', 'DATA E HORA SIMULACAO', 'CPF', 'NOME', 'NASCIMENTO', 'SEXO', 'STATUS_V8', 'OBSERVACAO', 'TABELA SIMULADA', 'MARGEM', 'PRAZO', 'VALOR_LIBERADO', 'STATUS_WHATSAPP',
                'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP',
                'EMAIL 1', 'EMAIL 2', 'EMAIL 3',
                'DDD'
            ];
            for ($i = 1; $i <= 10; $i++) { $cabecalho[] = "CELULAR $i"; }
            fputcsv($output, $cabecalho, ";");

            $sqlCpfs = "SELECT c.*, ? as NOME_IMPORTACAO, ? as TABELA_PADRAO_LOTE FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE c WHERE c.LOTE_ID = ? AND c.VALOR_LIQUIDO IS NOT NULL AND c.VALOR_LIQUIDO > 0";
            $stmtCpfs = $pdo->prepare($sqlCpfs);
            $stmtCpfs->execute([$lote['NOME_IMPORTACAO'], $lote['TABELA_PADRAO'] ?? '', $id_lote]);

            $stmtEnd   = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel   = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) {
                $linha = [
                    $row['LOTE_ID'],
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '-',
                    $row['CPF'] . " ",
                    $row['NOME'],
                    $row['NASCIMENTO'] ? date('d/m/Y', strtotime($row['NASCIMENTO'])) : '',
                    ($row['SEXO'] ?? '') == 'male' ? 'MASCULINO' : (($row['SEXO'] ?? '') == 'female' ? 'FEMININO' : ''),
                    $row['STATUS_V8'],
                    $row['OBSERVACAO'],
                    $row['TABELA_PADRAO_LOTE'] ?: '-',
                    $row['VALOR_MARGEM'] ? 'R$ ' . number_format($row['VALOR_MARGEM'], 2, ',', '.') : '-',
                    $row['PRAZO'] ? $row['PRAZO'] . 'x' : '-',
                    $row['VALOR_LIQUIDO'] ? 'R$ ' . number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : '-',
                    $row['STATUS_WHATSAPP'] ?? 'NAO ENVIADO'
                ];

                $stmtEnd->execute([$row['CPF']]);
                $end = $stmtEnd->fetch(PDO::FETCH_ASSOC);
                if ($end) {
                    $linha[] = $end['logradouro']; $linha[] = $end['numero']; $linha[] = $end['bairro'];
                    $linha[] = $end['cidade']; $linha[] = $end['uf']; $linha[] = $end['cep'] ? $end['cep'] . " " : '';
                } else {
                    $linha = array_merge($linha, ['', '', '', '', '', '']);
                }

                $stmtEmail->execute([$row['CPF']]);
                $emails = $stmtEmail->fetchAll(PDO::FETCH_COLUMN);
                for ($i = 0; $i < 3; $i++) { $linha[] = $emails[$i] ?? ''; }

                $stmtTel->execute([$row['CPF']]);
                $telefones = $stmtTel->fetchAll(PDO::FETCH_COLUMN);
                $ddd = (isset($telefones[0]) && strlen($telefones[0]) >= 10) ? substr($telefones[0], 0, 2) : '';
                $linha[] = $ddd;
                for ($i = 0; $i < 10; $i++) {
                    $tel = $telefones[$i] ?? '';
                    $linha[] = $tel ? $tel . " " : '';
                }

                fputcsv($output, $linha, ";");
            }
            fclose($output);
            exit;

        case 'exportar_planilha_importada':
            $id_lote = (int)($_GET['id_lote'] ?? $_POST['id_lote'] ?? 0);
            if (!$id_lote) die('ID do lote não informado.');

            $perm_meu_registro = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO') : true;

            $stmtLote = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

            if (!$lote) die('Lote não encontrado.');
            if (!$perm_meu_registro && $lote['CPF_USUARIO'] !== $usuario_logado_cpf) die('Acesso negado a este lote.');

            ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="Planilha_Importada_Lote_' . $id_lote . '_' . date('dmY_Hi') . '.csv"');

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

            fputcsv($output, ['CPF', 'NOME', 'NASCIMENTO', 'SEXO', 'STATUS_V8', 'OBSERVACAO', 'VALOR_MARGEM', 'PRAZO', 'VALOR_LIQUIDO'], ";");

            $stmtCpfs = $pdo->prepare("SELECT CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, OBSERVACAO, VALOR_MARGEM, PRAZO, VALOR_LIQUIDO FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE WHERE LOTE_ID = ? ORDER BY ID ASC");
            $stmtCpfs->execute([$id_lote]);

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['CPF'] . " ",
                    $row['NOME'],
                    $row['NASCIMENTO'] ? date('d/m/Y', strtotime($row['NASCIMENTO'])) : '',
                    ($row['SEXO'] ?? '') == 'male' ? 'MASCULINO' : (($row['SEXO'] ?? '') == 'female' ? 'FEMININO' : ''),
                    $row['STATUS_V8'],
                    $row['OBSERVACAO'],
                    $row['VALOR_MARGEM'] ? number_format($row['VALOR_MARGEM'], 2, ',', '.') : '',
                    $row['PRAZO'] ?? '',
                    $row['VALOR_LIQUIDO'] ? number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : ''
                ], ";");
            }
            fclose($output);
            exit;

        case 'alternar_status_lote':
            $id_lote = (int)$_POST['id_lote'];
            $novo_status = $_POST['novo_status'] === 'ATIVO' ? 'ATIVO' : 'INATIVO';
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_LOTE = ? WHERE ID = ?")->execute([$novo_status, $id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "Status alterado para $novo_status"]); exit;

        case 'salvar_edicao_lote':
            $id_lote = (int)$_POST['id_lote'];
            $agrupamento = strtoupper(trim($_POST['agrupamento']));
            $agendamento_tipo = $_POST['agendamento_tipo'];
            $data_hora_agendada = !empty($_POST['data_hora_agendada']) ? $_POST['data_hora_agendada'] : null;
            $dia_mes_agendado = !empty($_POST['dia_mes_agendado']) ? (int)$_POST['dia_mes_agendado'] : null;
            $hora_inicio_diario = ($agendamento_tipo === 'DIARIO' && !empty($_POST['hora_inicio_diario'])) ? trim($_POST['hora_inicio_diario']) : null;
            $dias_mes_diario = ($agendamento_tipo === 'DIARIO' && !empty($_POST['dias_mes_diario'])) ? trim($_POST['dias_mes_diario']) : null;
            $limite_diario = (int)$_POST['limite_diario'];
            $somente_simular = (int)$_POST['somente_simular'];
            $atualizar_telefone = (int)$_POST['atualizar_telefone'];
            $enviar_whats = (int)$_POST['enviar_whats'];
            $enviar_arquivo_whatsapp = (int)$_POST['enviar_arquivo_whatsapp'];
            $hora_inativacao_inicio = !empty(trim($_POST['hora_inativacao_inicio'] ?? '')) ? trim($_POST['hora_inativacao_inicio']) : null;
            $hora_inativacao_fim    = !empty(trim($_POST['hora_inativacao_fim']    ?? '')) ? trim($_POST['hora_inativacao_fim'])    : null;

            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET
                NOME_IMPORTACAO = ?, AGENDAMENTO_TIPO = ?, DATA_HORA_AGENDADA = ?, DIA_MES_AGENDADO = ?,
                HORA_INICIO_DIARIO = ?, DIAS_MES_DIARIO = ?,
                LIMITE_DIARIO = ?, SOMENTE_SIMULAR = ?, ATUALIZAR_TELEFONE = ?, ENVIAR_WHATSAPP = ?, ENVIAR_ARQUIVO_WHATSAPP = ?,
                HORA_INATIVACAO_INICIO = ?, HORA_INATIVACAO_FIM = ?
                WHERE ID = ?")->execute([
                $agrupamento, $agendamento_tipo, $data_hora_agendada, $dia_mes_agendado,
                $hora_inicio_diario, $dias_mes_diario,
                $limite_diario, $somente_simular, $atualizar_telefone, $enviar_whats, $enviar_arquivo_whatsapp,
                $hora_inativacao_inicio, $hora_inativacao_fim, $id_lote
            ]);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Configurações do Lote atualizadas com sucesso!']); exit;

        case 'enviar_relatorio_whatsapp':
            $id_lote = (int)$_POST['id_lote'];
            
            $stmtLote = $pdo->prepare("SELECT NOME_IMPORTACAO, CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]); 
            $dados_lote = $stmtLote->fetch(PDO::FETCH_ASSOC);
            
            if (!$dados_lote) { throw new Exception("Lote não encontrado no banco de dados."); }
            
            $nomeLote = $dados_lote['NOME_IMPORTACAO'] ?: 'LOTE';
            $cpf_dono_lote = $dados_lote['CPF_USUARIO'];

            if (empty($cpf_dono_lote)) { throw new Exception("Lote não possui um usuário dono vinculado."); }

            $pasta_relatorios = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8/relatorio_v8';
            
            if (!is_dir($pasta_relatorios)) {
                if (!@mkdir($pasta_relatorios, 0777, true)) {
                    throw new Exception("BLOQUEIO DO SERVIDOR: O PHP não tem permissão para criar a pasta 'relatorio_v8'. Crie a pasta manualmente no caminho: {$pasta_relatorios} e dê permissão 777.");
                }
            }

            $arquivos = glob($pasta_relatorios . '/*.csv');
            if ($arquivos) {
                $tempo_limite = time() - (10 * 24 * 60 * 60); 
                foreach ($arquivos as $arquivo) {
                    if (is_file($arquivo) && filemtime($arquivo) < $tempo_limite) {
                        @unlink($arquivo);
                    }
                }
            }
            
            $arquivos_log = glob($pasta_relatorios . '/*.txt');
            if ($arquivos_log) {
                $tempo_limite = time() - (10 * 24 * 60 * 60);
                foreach ($arquivos_log as $arq_log) {
                    if (is_file($arq_log) && filemtime($arq_log) < $tempo_limite) {
                        @unlink($arq_log);
                    }
                }
            }

            $nome_arquivo = "Relatorio_" . preg_replace('/[^a-zA-Z0-9]/', '_', $nomeLote) . "_" . time() . ".csv";
            $caminho_fisico = $pasta_relatorios . '/' . $nome_arquivo;
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $urlArquivo = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/logs_v8/relatorio_v8/" . $nome_arquivo;

            $fp = @fopen($caminho_fisico, 'w');
            if (!$fp) {
                throw new Exception("FALTA DE PERMISSÃO: A pasta existe, mas o servidor impediu a gravação do arquivo. Aplique permissão CHMOD 777 na pasta: {$pasta_relatorios}");
            }

            fputs($fp, "\xEF\xBB\xBF"); 
            
            $cabecalho = ['NOME PLANILHA', 'DATA E HORA SIMULACAO', 'CPF', 'NOME', 'NASCIMENTO', 'SEXO', 'STATUS_V8', 'OBSERVACAO', 'TABELA SIMULADA', 'MARGEM', 'PRAZO', 'VALOR_LIBERADO', 'STATUS_WHATSAPP', 'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP', 'EMAIL 1', 'EMAIL 2', 'EMAIL 3', 'DDD'];
            for($i = 1; $i <= 10; $i++) { $cabecalho[] = "CELULAR $i"; }
            fputcsv($fp, $cabecalho, ";");
            
            // Somente CPFs com VALOR_LIQUIDO preenchido e maior que zero
            $stmtCpfs = $pdo->prepare("SELECT c.*, l.NOME_IMPORTACAO, ca.TABELA_PADRAO FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE c JOIN INTEGRACAO_V8_IMPORTACAO_LOTE l ON c.LOTE_ID = l.ID LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON l.CHAVE_ID = ca.ID WHERE c.LOTE_ID = ? AND c.VALOR_LIQUIDO IS NOT NULL AND c.VALOR_LIQUIDO > 0");
            $stmtCpfs->execute([$id_lote]);
            
            $stmtEnd = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) { 
                $linha = [ $row['NOME_IMPORTACAO'], $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '-', $row['CPF'] . " ", $row['NOME'], $row['NASCIMENTO'] ? date('d/m/Y', strtotime($row['NASCIMENTO'])) : '', $row['SEXO'] == 'male' ? 'MASCULINO' : 'FEMININO', $row['STATUS_V8'], $row['OBSERVACAO'], $row['TABELA_PADRAO'] ?? '-', $row['VALOR_MARGEM'] ? 'R$ ' . number_format($row['VALOR_MARGEM'], 2, ',', '.') : '-', $row['PRAZO'] ? $row['PRAZO'] . 'x' : '-', $row['VALOR_LIQUIDO'] ? 'R$ ' . number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : '-', $row['STATUS_WHATSAPP'] ?? 'NAO ENVIADO' ];
                
                $stmtEnd->execute([$row['CPF']]); $end = $stmtEnd->fetch(PDO::FETCH_ASSOC);
                if ($end) { $linha[] = $end['logradouro']; $linha[] = $end['numero']; $linha[] = $end['bairro']; $linha[] = $end['cidade']; $linha[] = $end['uf']; $linha[] = $end['cep'] ? $end['cep'] . " " : ''; } else { $linha = array_merge($linha, ['', '', '', '', '', '']); }

                $stmtEmail->execute([$row['CPF']]); $emails = $stmtEmail->fetchAll(PDO::FETCH_COLUMN);
                for($i = 0; $i < 3; $i++) { $linha[] = isset($emails[$i]) ? $emails[$i] : ''; }
                
                $stmtTel->execute([$row['CPF']]); $telefones = $stmtTel->fetchAll(PDO::FETCH_COLUMN);
                $ddd = (isset($telefones[0]) && strlen($telefones[0]) >= 10) ? substr($telefones[0], 0, 2) : ''; $linha[] = $ddd;
                for($i = 0; $i < 10; $i++) { $tel = isset($telefones[$i]) ? $telefones[$i] : ''; $linha[] = $tel ? $tel . " " : ''; }

                fputcsv($fp, $linha, ";"); 
            }
            fclose($fp);
            
            $stmtCli = $pdo->prepare("SELECT GRUPO_WHATS FROM CLIENTE_CADASTRO WHERE CPF = ?");
            $stmtCli->execute([$cpf_dono_lote]);
            $grupo_cliente = $stmtCli->fetchColumn();

            if (empty($grupo_cliente)) {
                throw new Exception("O cliente dono deste lote não possui um 'Grupo Whats' configurado.");
            }

            $stmtWapi = $pdo->query("SELECT i.INSTANCE_ID, i.TOKEN FROM WAPI_CONFIG c JOIN WAPI_INSTANCIAS i ON c.INSTANCE_ID = i.INSTANCE_ID WHERE c.ATIVO_GLOBAL = 1 LIMIT 1");
            $wapi = $stmtWapi->fetch(PDO::FETCH_ASSOC);
            
            if(!$wapi || empty($wapi['INSTANCE_ID']) || empty($wapi['TOKEN'])) {
                throw new Exception("O Módulo W-API não está ativo para fazer o disparo das mensagens.");
            }
            
            $phone = preg_replace('/[^0-9\-@a-zA-Z.]/', '', $grupo_cliente);
            if (strpos($phone, '@g.us') === false) {
                $phone .= '@g.us'; 
            }

            $dataHoraAtual = date('d/m/Y H:i');
            $texto_whatsapp = "📊 *Aviso de Relatório Disponível*\n\n"
                            . "*PRODUTO:* Lista do Robô CLT V8 da Assessoria Consignado\n"
                            . "*NOME DA LISTA:* {$nomeLote}\n"
                            . "*DATA E HORA DE ENVIADO:* {$dataHoraAtual}\n\n"
                            . "*INSTRUÇÕES:*\n"
                            . "• O link fica disponível por até 10 dias\n"
                            . "• Cada planilha corresponde à campanha ativa, podendo conter 1 ou mais dias de consulta\n"
                            . "• A lista possui todas as consultas feitas na campanha\n"
                            . "• Ao clicar no link abaixo você será redirecionado para baixar o arquivo em CSV\n"
                            . "• Acesse nosso site para saber mais\n\n"
                            . "🔗 *Clique aqui para baixar:*\n"
                            . "{$urlArquivo}\n\n"
                            . "Assessoria Consignado\n"
                            . "https://crm.assessoriaconsignado.com/";
            
            $payload_wapi = json_encode([
                "phone" => $phone,
                "message" => $texto_whatsapp,
                "delayMessage" => 2
            ]);
            
            $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapi['INSTANCE_ID'];
            
            $chW = curl_init($url_wapi);
            curl_setopt($chW, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chW, CURLOPT_POST, true);
            curl_setopt($chW, CURLOPT_POSTFIELDS, $payload_wapi);
            curl_setopt($chW, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $wapi['TOKEN'],
                "Content-Type: application/json"
            ]);
            $resW = curl_exec($chW);
            $httpW = curl_getinfo($chW, CURLINFO_HTTP_CODE);
            curl_close($chW);
            
            $log_nome_arquivo = "Log_WAPI_" . preg_replace('/[^a-zA-Z0-9]/', '_', $nomeLote) . "_" . time() . ".txt";
            $caminho_log = $pasta_relatorios . '/' . $log_nome_arquivo;
            
            $log_conteudo = "=== REQUISIÇÃO W-API ===\n";
            $log_conteudo .= "DATA: " . date('d/m/Y H:i:s') . "\n";
            $log_conteudo .= "URL: " . $url_wapi . "\n";
            $log_conteudo .= "PAYLOAD ENVIADO:\n" . json_encode(json_decode($payload_wapi), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
            $log_conteudo .= "=== RESPOSTA W-API ===\n";
            $log_conteudo .= "HTTP STATUS: " . $httpW . "\n";
            $log_conteudo .= "RETORNO:\n" . $resW . "\n";
            
            @file_put_contents($caminho_log, $log_conteudo);

            if($httpW >= 200 && $httpW < 300) {
                ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Relatório gerado e Link enviado para o grupo do Cliente com sucesso!']); exit;
            } else {
                $erro_wapi = json_decode($resW, true);
                $detalhe_erro = isset($erro_wapi['message']) ? $erro_wapi['message'] : (isset($erro_wapi['error']) ? $erro_wapi['error'] : $resW);
                throw new Exception("Erro W-API (HTTP {$httpW}): " . $detalhe_erro);
            }

        case 'excluir_lote':
            $id_lote = (int)$_POST['id_lote'];
            $pdo->prepare("DELETE FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?")->execute([$id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Lote e histórico apagados com sucesso.']); exit;

        case 'reprocessar_consentimento':
            // Re-verifica apenas CPFs com AGUARDANDO DATAPREV → volta para AGUARDANDO MARGEM (FASE 2 relê o status da V8)
            $id_lote = (int)$_POST['id_lote'];
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA_LOTE SET STATUS_V8 = 'AGUARDANDO MARGEM', OBSERVACAO = 'Reverificando: aguardando retorno da Dataprev...' WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO DATAPREV'")->execute([$id_lote]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);
            $stmtDono = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtDono->execute([$id_lote]); $cpf_dono = $stmtDono->fetchColumn();
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'CPFs aguardando Dataprev enviados para reverificação.', 'cpf_dono' => $cpf_dono]); exit;

        case 'reprocessar_erros':
            // Reprocessa apenas CPFs com ERRO gerado nas últimas 24h
            $id_lote = (int)$_POST['id_lote'];
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA_LOTE
                SET STATUS_V8 = 'RECUPERAR V8', OBSERVACAO = 'Reprocessando erro (últimas 24h): recuperando margem/simulação...'
                WHERE LOTE_ID = ?
                  AND STATUS_V8 IN ('ERRO CONSULTA', 'ERRO MARGEM', 'ERRO SIMULACAO')
                  AND COALESCE(DATA_SIMULACAO, DATA_CONSENTIMENTO) >= NOW() - INTERVAL 24 HOUR")->execute([$id_lote]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);
            $stmtDono = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtDono->execute([$id_lote]); $cpf_dono = $stmtDono->fetchColumn();
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'CPFs com erro enviados para reprocessamento.', 'cpf_dono' => $cpf_dono]); exit;

        case 'reprocessar_simulacao':
            $id_lote = (int)$_POST['id_lote'];
            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA_LOTE SET STATUS_V8 = 'RECUPERAR V8', OBSERVACAO = 'Recuperando Margem/Simulação...' WHERE LOTE_ID = ? AND STATUS_V8 NOT IN ('OK', 'AGUARDANDO MARGEM', 'AGUARDANDO SIMULACAO')")->execute([$id_lote]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);

            $stmtDono = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtDono->execute([$id_lote]); $cpf_dono = $stmtDono->fetchColumn();

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Simulações reprocessadas com sucesso.', 'cpf_dono' => $cpf_dono]); exit;

        case 'reprocessar_todos':
            $id_lote = (int)$_POST['id_lote'];
            
            $stmtLote = $pdo->prepare("SELECT SOMENTE_SIMULAR, CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $loteDados = $stmtLote->fetch(PDO::FETCH_ASSOC);
            $is_simular = $loteDados['SOMENTE_SIMULAR'];
            $cpf_dono = $loteDados['CPF_USUARIO'];
            
            $novo_status = ($is_simular == 1) ? 'RECUPERAR V8' : 'NA FILA';

            $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA_LOTE SET STATUS_V8 = ?, VALOR_MARGEM = NULL, CONSULT_ID = NULL, CONFIG_ID = NULL, VALOR_LIQUIDO = NULL, SIMULATION_ID = NULL, OBSERVACAO = 'Reprocessando do zero...' WHERE LOTE_ID = ?")->execute([$novo_status, $id_lote]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE', QTD_PROCESSADA = 0, QTD_SUCESSO = 0, QTD_ERRO = 0 WHERE ID = ?")->execute([$id_lote]);
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Todos os registros foram zerados para reprocessamento.', 'cpf_dono' => $cpf_dono]); exit;

        case 'pausar_retomar_lote':
            $id_lote = (int)$_POST['id_lote'];
            $acaoLote = $_POST['acao_lote'] === 'PAUSAR' ? 'PAUSADO' : 'PENDENTE';
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = ? WHERE ID = ?")->execute([$acaoLote, $id_lote]);
            
            $stmtDono = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtDono->execute([$id_lote]); $cpf_dono = $stmtDono->fetchColumn();

            if ($acaoLote === 'PENDENTE') {
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            }
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Ação processada com sucesso.', 'cpf_dono' => $cpf_dono]); exit;
            
        // =====================================================================
        // VERIFICAR AGENDAMENTOS DIÁRIOS (chamado pelo frontend a cada minuto)
        // =====================================================================
        case 'verificar_agendamentos_diarios':
            $hora_atual = date('H:i');
            $dia_atual = (int)date('j'); // 1-31
            $data_hoje = date('Y-m-d');

            // Garante coluna nova sem quebrar instalações existentes
            try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IMPORTACAO_LOTE ADD COLUMN AUTO_REPROCESS_CHECKPOINT INT NOT NULL DEFAULT 0"); } catch(Exception $e){}

            // 1. Resetar contadores do dia para lotes diários que ainda não foram resetados hoje
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE
                SET PROCESSADOS_HOJE = 0, AUTO_REPROCESS_CHECKPOINT = 0, ULTIMO_PROCESSAMENTO = NULL
                WHERE AGENDAMENTO_TIPO = 'DIARIO'
                  AND (ULTIMO_PROCESSAMENTO IS NULL OR ULTIMO_PROCESSAMENTO < ?)
                  AND STATUS_FILA NOT IN ('PENDENTE', 'PROCESSANDO')")->execute([$data_hoje]);

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $disparados = 0;

            // 2. Disparar lotes AGUARDANDO_DIARIO cujo horário já passou hoje e ainda não rodaram
            $stmtD = $pdo->query("SELECT * FROM INTEGRACAO_V8_IMPORTACAO_LOTE
                WHERE AGENDAMENTO_TIPO = 'DIARIO'
                  AND STATUS_FILA = 'AGUARDANDO_DIARIO'
                  AND STATUS_LOTE = 'ATIVO'
                  AND HORA_INICIO_DIARIO IS NOT NULL
                  AND (ULTIMO_PROCESSAMENTO IS NULL OR ULTIMO_PROCESSAMENTO < '{$data_hoje}')");

            while ($loteDiario = $stmtD->fetch(PDO::FETCH_ASSOC)) {
                $hora_lote = substr($loteDiario['HORA_INICIO_DIARIO'], 0, 5);

                // Dispara se o horário configurado já chegou (aceita janela perdida do mesmo dia)
                if ($hora_lote > $hora_atual) continue;

                // Verifica se o dia bate
                $dias_config = trim($loteDiario['DIAS_MES_DIARIO'] ?? 'TODOS');
                if (!empty($dias_config) && $dias_config !== 'TODOS') {
                    $dias_arr = array_map('intval', explode(',', $dias_config));
                    if (!in_array($dia_atual, $dias_arr)) continue;
                }

                // Dispara: muda para PENDENTE, zera contadores e marca o dia
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE
                    SET STATUS_FILA = 'PENDENTE', PROCESSADOS_HOJE = 0, AUTO_REPROCESS_CHECKPOINT = 0, ULTIMO_PROCESSAMENTO = ?
                    WHERE ID = ?")->execute([$data_hoje, $loteDiario['ID']]);

                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $loteDiario['CPF_USUARIO'];
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                $disparados++;
            }

            // 3. Reprocessamento automático: a cada 250 consentimentos enviados no dia, re-verifica DATAPREV
            $stmtAR = $pdo->query("SELECT l.ID, l.CPF_USUARIO, l.PROCESSADOS_HOJE, l.AUTO_REPROCESS_CHECKPOINT
                FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                WHERE l.AGENDAMENTO_TIPO = 'DIARIO'
                  AND l.STATUS_LOTE = 'ATIVO'
                  AND l.STATUS_FILA IN ('PENDENTE','PROCESSANDO','AGUARDANDO_DIARIO')
                  AND l.PROCESSADOS_HOJE >= l.AUTO_REPROCESS_CHECKPOINT + 250
                  AND EXISTS (
                      SELECT 1 FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE r
                      WHERE r.LOTE_ID = l.ID AND r.STATUS_V8 = 'AGUARDANDO DATAPREV'
                  )");

            while ($lAR = $stmtAR->fetch(PDO::FETCH_ASSOC)) {
                // Avança o checkpoint para evitar re-trigger antes de mais 250
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET AUTO_REPROCESS_CHECKPOINT = PROCESSADOS_HOJE WHERE ID = ?")->execute([$lAR['ID']]);
                // Manda AGUARDANDO_DATAPREV → AGUARDANDO MARGEM (FASE 2 relê o status na V8)
                $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA_LOTE SET STATUS_V8 = 'AGUARDANDO MARGEM', OBSERVACAO = 'Reprocessamento automático (a cada 250 consentimentos do dia)' WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO DATAPREV'")->execute([$lAR['ID']]);
                // Se estava aguardando horário, acorda para rodar FASE 2
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ? AND STATUS_FILA = 'AGUARDANDO_DIARIO'")->execute([$lAR['ID']]);
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $lAR['CPF_USUARIO'];
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                $disparados++;
            }

            ob_end_clean(); echo json_encode(['success' => true, 'disparados' => $disparados, 'hora' => $hora_atual]); exit;

        case 'forcar_processamento_lote':
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $stmtDonos = $pdo->query("SELECT DISTINCT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE STATUS_FILA IN ('PENDENTE', 'PROCESSANDO')");
            while($cpf_dono = $stmtDonos->fetchColumn()) {
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            }
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Worker despertado para todos os lotes ativos!']); exit;

        case 'buscar_anotacao_lote':
            $id_lote = (int)$_POST['id_lote'];
            $stmt = $pdo->prepare("SELECT ANOTACOES FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmt->execute([$id_lote]);
            $anotacoes = $stmt->fetchColumn();
            ob_end_clean(); echo json_encode(['success' => true, 'anotacoes' => $anotacoes]); exit;

        case 'salvar_anotacao_lote':
            $id_lote = (int)$_POST['id_lote'];
            $anotacao = $_POST['anotacao'];
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET ANOTACOES = ? WHERE ID = ?")->execute([$anotacao, $id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Anotações atualizadas com sucesso!']); exit;

        default: throw new Exception("Ação desconhecida no Lote V8.");
    }
} catch (Exception $e) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>