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

    // Retorna o nome da tabela de CPFs do lote (própria ou central para lotes antigos)
    function v8_tabela_lote(PDO $pdo, int $id_lote): string {
        $s = $pdo->prepare("SELECT TABELA_DADOS FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
        $s->execute([$id_lote]);
        $tabela = $s->fetchColumn();
        return (!empty($tabela)) ? $tabela : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
    }

    /**
     * Hierarquia do Lote V8:
     * Chave → CPF_USUARIO (dono da chave) → CLIENTE_USUARIO.id_empresa
     * Retorna os IDs de CHAVE_ACESSO acessíveis pela empresa do usuário logado.
     * Retorna null quando não há restrição por empresa (hierarquia não aplicável).
     */
    function v8_chaves_da_empresa(PDO $pdo, int $id_empresa): array {
        $s = $pdo->prepare("
            SELECT ca.ID
            FROM INTEGRACAO_V8_CHAVE_ACESSO ca
            JOIN CLIENTE_USUARIO u
              ON u.CPF COLLATE utf8mb4_unicode_ci = ca.CPF_USUARIO COLLATE utf8mb4_unicode_ci
            WHERE u.id_empresa = ?
        ");
        $s->execute([$id_empresa]);
        return $s->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Verifica se a permissão de hierarquia está ativa para o usuário logado.
     * Retornos:
     *   0   → sem restrição (MASTER ou hierarquia não aplicável)
     *   > 0 → id_empresa: filtrar apenas lotes desta empresa
     *  -1   → hierarquia ativa mas empresa não cadastrada → bloqueia tudo (segurança)
     * Busca id_empresa diretamente do banco (login não grava id_empresa na sessão).
     */
    function v8_id_empresa_hierarquia(PDO $pdo): int {
        if (!function_exists('verificaPermissao')) return 0;
        // perm_meu_registro false = CONSULTOR (vê só próprio, já filtrado por CPF_USUARIO)
        $perm_meu = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO');
        if (!$perm_meu) return 0; // CONSULTOR já tem filtro próprio
        // Verifica hierarquia: false = hierarquia restrita por empresa
        $perm_hier = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_HIERARQUIA', 'FUNCAO');
        if ($perm_hier) return 0; // MASTER/sem restrição — vê tudo
        // Hierarquia ativa: busca id_empresa do banco pelo CPF da sessão
        $cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
        if (empty($cpf)) return -1; // sessão inválida → bloqueia
        $s = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $s->execute([$cpf]);
        $id_empresa = (int)($s->fetchColumn() ?: 0);
        // Se empresa não cadastrada mas hierarquia ativa → -1 (bloqueia tudo por segurança)
        return ($id_empresa > 0) ? $id_empresa : -1;
    }

    // Verifica se o usuário logado é dono do lote (segurança backend)
    function v8_verificar_dono_lote(PDO $pdo, int $id_lote, string $usuario_logado_cpf): void {
        if (function_exists('verificaPermissao') && verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_MEU_REGISTRO', 'FUNCAO')) {
            // Tem acesso a todos — verifica hierarquia por empresa
            $id_empresa = v8_id_empresa_hierarquia($pdo);
            if ($id_empresa === -1) throw new Exception("Acesso negado: empresa não configurada no seu usuário.");
            if ($id_empresa > 0) {
                $s = $pdo->prepare("
                    SELECT COUNT(*) FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                    JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON ca.ID = l.CHAVE_ID
                    JOIN CLIENTE_USUARIO u
                      ON u.CPF COLLATE utf8mb4_unicode_ci = ca.CPF_USUARIO COLLATE utf8mb4_unicode_ci
                    WHERE l.ID = ? AND u.id_empresa = ?
                ");
                $s->execute([$id_lote, $id_empresa]);
                if (!(int)$s->fetchColumn()) throw new Exception("Acesso negado: lote de outra empresa.");
            }
            return;
        }
        $s = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
        $s->execute([$id_lote]);
        $lote = $s->fetch(PDO::FETCH_ASSOC);
        if (!$lote) throw new Exception("Lote não encontrado.");
        if (!empty($lote['CPF_USUARIO']) && $lote['CPF_USUARIO'] !== $usuario_logado_cpf) {
            throw new Exception("Acesso negado: este lote pertence a outro usuário.");
        }
    }

    switch ($acao) {
        
        case 'upload_csv_lote':
            $agrupamento = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_ \-]/', '_', $_POST['agrupamento'] ?? 'LOTE_V8')));
            $chave_id = (int)($_POST['chave_id'] ?? 0);
            // Lote sempre criado como DIÁRIO PAUSADO — configuração feita depois na edição
            $agendamento_tipo    = 'DIARIO';
            $somente_simular     = 0;
            $atualizar_telefone  = 0;
            $enviar_whats        = 0;
            $enviar_arquivo_whatsapp = 0;

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
                    if (strlen($cpf) !== 11 || ltrim($cpf, '0') === '') { $linhas_descartadas++; continue; }
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

            // Lote sempre criado PAUSADO; configurações de horário feitas na edição
            $stmtLote = $pdo->prepare("INSERT INTO INTEGRACAO_V8_IMPORTACAO_LOTE
                (NOME_IMPORTACAO, USUARIO_ID, CPF_USUARIO, CHAVE_ID, ARQUIVO_CAMINHO, QTD_TOTAL, AGENDAMENTO_TIPO, ATUALIZAR_TELEFONE, ENVIAR_WHATSAPP, SOMENTE_SIMULAR, ENVIAR_ARQUIVO_WHATSAPP, STATUS_FILA)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtLote->execute([$agrupamento, $dono_lote_id, $dono_lote_cpf, $chave_id, $_FILES['arquivo_csv']['name'], $total, $agendamento_tipo, $atualizar_telefone, $enviar_whats, $somente_simular, $enviar_arquivo_whatsapp, 'PAUSADO']);
            $id_lote = $pdo->lastInsertId();

            // Criar tabela própria para este lote e registrar em TABELA_DADOS
            $tabela_lote = 'V8_LOTE_' . $id_lote;
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tabela_lote}` LIKE INTEGRACAO_V8_REGISTROCONSULTA_LOTE");
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET TABELA_DADOS = ? WHERE ID = ?")->execute([$tabela_lote, $id_lote]);

            $stmtCpf = $pdo->prepare("INSERT INTO `{$tabela_lote}` (LOTE_ID, CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, VALOR_MARGEM, CONSULT_ID, CONFIG_ID, OBSERVACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
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

            $msg_retorno = "Lote de $total CPFs importado! Configure o horário de funcionamento no botão Editar e depois clique em Ligar Robô.{$aviso_descarte}";
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => $msg_retorno]); exit;

        // ==================================================================================================
        // NOVA AÇÃO DE APPEND: INCLUIR MAIS CLIENTE
        // ==================================================================================================
        case 'append_csv_lote':
            $id_lote = (int)$_POST['append_lote_id'];
            if ($id_lote <= 0) throw new Exception("Selecione um Lote Destino válido.");
            
            if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] != UPLOAD_ERR_OK) throw new Exception("Nenhum arquivo CSV recebido.");
            if (strtolower(pathinfo($_FILES['arquivo_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') throw new Exception("O arquivo precisa ser obrigatoriamente .csv.");

            $stmtLote = $pdo->prepare("SELECT CHAVE_ID, CPF_USUARIO, SOMENTE_SIMULAR, TABELA_DADOS FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $loteAtual = $stmtLote->fetch(PDO::FETCH_ASSOC);
            if (!$loteAtual) throw new Exception("Lote não encontrado.");

            $chave_id = $loteAtual['CHAVE_ID'];
            $somente_simular = $loteAtual['SOMENTE_SIMULAR'];
            $dono_lote_cpf = $loteAtual['CPF_USUARIO'];
            $tabela_append = !empty($loteAtual['TABELA_DADOS']) ? $loteAtual['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

            $stmtCli = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?");
            $stmtCli->execute([$chave_id]);
            $chave = $stmtCli->fetch(PDO::FETCH_ASSOC);

            // Carrega CPFs que JÁ ESTÃO no lote
            $stmtExistentes = $pdo->prepare("SELECT CPF FROM `{$tabela_append}` WHERE LOTE_ID = ?");
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
            $linhas_para_atualizar = [];

            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                if(count($data) == 1 && strpos($data[0], ',') !== false) { $data = explode(',', $data[0]); }
                $cpf = isset($data[$idx_cpf]) ? str_pad(preg_replace('/\D/', '', $data[$idx_cpf]), 11, '0', STR_PAD_LEFT) : '';

                if (strlen($cpf) == 11 && ltrim($cpf, '0') !== '') {
                    // CPF já existe no lote — sempre atualiza dados cadastrais
                    if (isset($hashExistentes[$cpf])) {
                        try {
                            $nasc = ($idx_nasc !== -1 && isset($data[$idx_nasc])) ? trim($data[$idx_nasc]) : '';
                            if (strpos($nasc, '/') !== false) { $p = explode('/', $nasc); if (count($p) == 3) $nasc = "{$p[2]}-{$p[1]}-{$p[0]}"; }
                            if (!empty($nasc)) { $dt = DateTime::createFromFormat('Y-m-d', $nasc); $nasc = ($dt && $dt->format('Y-m-d') === $nasc) ? $nasc : null; } else { $nasc = null; }
                            $sexo = ($idx_sexo !== -1 && isset($data[$idx_sexo])) ? strtoupper(trim($data[$idx_sexo])) : '';
                            $genero = (strpos($sexo, 'M') === 0) ? 'male' : 'female';
                            $nome = ($idx_nome !== -1 && isset($data[$idx_nome])) ? mb_strtoupper(trim($data[$idx_nome]), 'UTF-8') : null;
                            $linhas_para_atualizar[] = ['cpf' => $cpf, 'nascimento' => $nasc, 'sexo' => $genero, 'nome' => $nome];
                        } catch (Exception $e) {}
                        continue;
                    }

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
            if ($total_novos == 0 && empty($linhas_para_atualizar)) {
                throw new Exception("Nenhum CPF válido encontrado na planilha ou a planilha é inválida.");
            }

            $custo_lote = $total_novos * (float)$chave['CUSTO_CONSULTA'];
            if ((float)$chave['SALDO'] < $custo_lote && $custo_lote > 0) { throw new Exception("Saldo Insuficiente para os novos CPFs! Custo: R$ " . number_format($custo_lote, 2, ',', '.') . "."); }

            $pdo->beginTransaction();

            // Reativa o lote (QTD_TOTAL será ajustado após inserts)
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);
            $stmtCpf = $pdo->prepare("INSERT INTO `{$tabela_append}` (LOTE_ID, CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, VALOR_MARGEM, CONSULT_ID, CONFIG_ID, OBSERVACAO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmtBuscaLote = null; $stmtBuscaManual = null;
            if ($somente_simular == 1) {
                $stmtBuscaLote = $pdo->prepare("SELECT CONSULT_ID, CONFIG_ID, VALOR_MARGEM FROM `{$tabela_append}` WHERE CPF = ? AND VALOR_MARGEM IS NOT NULL AND VALOR_MARGEM > 0 AND CONSULT_ID IS NOT NULL ORDER BY ID DESC LIMIT 1");
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

            // Sempre atualiza dados cadastrais dos CPFs já existentes
            $atualizados = 0;
            $recolocados_fila = 0;
            if (!empty($linhas_para_atualizar)) {
                $stmtUpd = $pdo->prepare("UPDATE `{$tabela_append}`
                    SET NASCIMENTO = COALESCE(?, NASCIMENTO),
                        SEXO       = ?,
                        NOME       = COALESCE(NULLIF(?, ''), NOME)
                    WHERE LOTE_ID = ? AND CPF = ?");
                $stmtReset = $pdo->prepare("UPDATE `{$tabela_append}`
                    SET STATUS_V8 = 'NA FILA', OBSERVACAO = 'Dados corrigidos — reprocessando.'
                    WHERE LOTE_ID = ? AND CPF = ?
                      AND STATUS_V8 IN ('ERRO CONSULTA','ERRO MARGEM')");
                foreach ($linhas_para_atualizar as $u) {
                    $stmtUpd->execute([$u['nascimento'], $u['sexo'], $u['nome'], $id_lote, $u['cpf']]);
                    $atualizados++;
                    $stmtReset->execute([$id_lote, $u['cpf']]);
                    $recolocados_fila += $stmtReset->rowCount();
                }
            }

            $pdo->commit();

            $aviso_append = ($erros_insert_novos > 0) ? " ({$erros_insert_novos} linhas com erro de formato descartadas.)" : '';
            if ($atualizados > 0) $aviso_append .= " {$atualizados} CPF(s) com dados atualizados, {$recolocados_fila} recolocado(s) na fila.";

            // Desperta o robô
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $dono_lote_cpf;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            $msg_principal = ($inseridos_novos > 0) ? "{$inseridos_novos} CPFs inéditos incluídos ao lote." : "Nenhum CPF novo adicionado.";
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => $msg_principal . $aviso_append, 'cpf_dono' => $dono_lote_cpf]); exit;

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
                // CONSULTOR: vê apenas os próprios lotes (filtra por CPF)
                $where .= " AND l.CPF_USUARIO = ? ";
                $params[] = $usuario_logado_cpf;

                // Verifica se o usuário tem chaves
                $stmtChaves = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE USUARIO_ID = ? OR CPF_USUARIO = ?");
                $stmtChaves->execute([$usuario_logado_id, $usuario_logado_cpf]);
                if (!$stmtChaves->fetchColumn()) {
                    ob_end_clean(); echo json_encode(['success' => true, 'data' => []]); exit;
                }
            } else {
                // SUPERVISORES+: verifica hierarquia por empresa da chave
                $id_empresa_hier = v8_id_empresa_hierarquia($pdo);
                if ($id_empresa_hier === -1) {
                    // Hierarquia ativa mas empresa não cadastrada → bloqueia tudo
                    ob_end_clean(); echo json_encode(['success' => true, 'data' => []]); exit;
                }
                if ($id_empresa_hier > 0) {
                    $chaves_empresa = v8_chaves_da_empresa($pdo, $id_empresa_hier);
                    if (empty($chaves_empresa)) {
                        ob_end_clean(); echo json_encode(['success' => true, 'data' => []]); exit;
                    }
                    $inChaves = implode(',', array_fill(0, count($chaves_empresa), '?'));
                    $where .= " AND l.CHAVE_ID IN ($inChaves) ";
                    $params = array_merge($params, $chaves_empresa);
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

                // Agrupa lotes por tabela de dados (lotes legados usam tabela central)
                $stmtTbls = $pdo->prepare("SELECT ID, TABELA_DADOS FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID IN ($inQuery)");
                $stmtTbls->execute($lote_ids);
                $tblsUnicas = [];
                foreach ($stmtTbls->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $t = !empty($r['TABELA_DADOS']) ? $r['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
                    $tblsUnicas[$t][] = $r['ID'];
                }

                // Contagens totais por status (uma query por tabela)
                $rawStats = [];
                $hojeByLote = [];
                foreach ($tblsUnicas as $tblNome => $ids) {
                    $inQ2 = implode(',', array_fill(0, count($ids), '?'));
                    $s1 = $pdo->prepare("SELECT LOTE_ID, STATUS_V8, COUNT(*) as qtd FROM `{$tblNome}` WHERE LOTE_ID IN ($inQ2) GROUP BY LOTE_ID, STATUS_V8");
                    $s1->execute($ids);
                    $rawStats = array_merge($rawStats, $s1->fetchAll(PDO::FETCH_ASSOC));

                    $s2 = $pdo->prepare("SELECT LOTE_ID,
                        SUM(CASE WHEN DATA_CONSENTIMENTO >= CURDATE() THEN 1 ELSE 0 END) as c_hoje,
                        SUM(CASE WHEN DATA_SIMULACAO >= CURDATE() AND STATUS_V8 = 'OK' THEN 1 ELSE 0 END) as s_hoje
                        FROM `{$tblNome}` WHERE LOTE_ID IN ($inQ2) GROUP BY LOTE_ID");
                    $s2->execute($ids);
                    foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $hojeByLote[$r['LOTE_ID']] = ['c_hoje' => (int)$r['c_hoje'], 's_hoje' => (int)$r['s_hoje']];
                    }
                }

                $statsByLote = [];
                foreach($rawStats as $row) {
                    $lid = $row['LOTE_ID'];
                    $st = strtoupper($row['STATUS_V8']);
                    $q = (int)$row['qtd'];

                    if(!isset($statsByLote[$lid])) {
                        $statsByLote[$lid] = ['c_ok'=>0, 'c_err'=>0, 'm_ok'=>0, 'm_err'=>0, 's_ok'=>0, 's_err'=>0, 'dataprev'=>0, 'na_fila'=>0];
                    }

                    if ($st === 'NA FILA') {
                        $statsByLote[$lid]['na_fila'] += $q;
                    } elseif (strpos($st, 'ERRO CONSULTA') !== false || strpos($st, 'ERRO SALDO') !== false) {
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
                    $funil = isset($statsByLote[$lid]) ? $statsByLote[$lid] : ['c_ok'=>0, 'c_err'=>0, 'm_ok'=>0, 'm_err'=>0, 's_ok'=>0, 's_err'=>0, 'dataprev'=>0, 'na_fila'=>0];
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
            } else {
                $id_empresa_hier = v8_id_empresa_hierarquia($pdo);
                if ($id_empresa_hier === -1) die("Nenhum lote localizado com os filtros atuais.");
                if ($id_empresa_hier > 0) {
                    $chaves_empresa = v8_chaves_da_empresa($pdo, $id_empresa_hier);
                    if (empty($chaves_empresa)) die("Nenhum lote localizado com os filtros atuais.");
                    $inChaves = implode(',', array_fill(0, count($chaves_empresa), '?'));
                    $where .= " AND l.CHAVE_ID IN ($inChaves) ";
                    $params = array_merge($params, $chaves_empresa);
                }
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
            $sufixo_arquivo = $somente_simulados ? 'ComMargem' : 'Tudo';
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
            $filtro_simulados = $somente_simulados ? " AND c.VALOR_MARGEM IS NOT NULL AND c.VALOR_MARGEM > 1 " : "";

            // Agrupa lotes por tabela (lotes legados usam tabela central)
            $stmtTbls2 = $pdo->prepare("SELECT ID, TABELA_DADOS FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID IN ($inQuery)");
            $stmtTbls2->execute($lote_ids);
            $tblsUnicas2 = [];
            foreach ($stmtTbls2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $t = !empty($r['TABELA_DADOS']) ? $r['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
                $tblsUnicas2[$t][] = $r['ID'];
            }
            $allRows2 = [];
            foreach ($tblsUnicas2 as $tblNome => $ids) {
                $inQ2 = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT c.*, l.NOME_IMPORTACAO, ca.TABELA_PADRAO
                    FROM `{$tblNome}` c
                    JOIN INTEGRACAO_V8_IMPORTACAO_LOTE l ON c.LOTE_ID = l.ID
                    LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON l.CHAVE_ID = ca.ID
                    WHERE c.LOTE_ID IN ($inQ2) $filtro_simulados");
                $s->execute($ids);
                $allRows2 = array_merge($allRows2, $s->fetchAll(PDO::FETCH_ASSOC));
            }

            $stmtEnd = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");

            foreach ($allRows2 as $row) {
                $linha = [ 
                    $row['NOME_IMPORTACAO'], 
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '-', 
                    preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($row['CPF'], 11, '0', STR_PAD_LEFT)),
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
            // Hierarquia por empresa da chave
            $id_empresa_hier = v8_id_empresa_hierarquia($pdo);
            if ($perm_meu_registro && $id_empresa_hier !== 0) {
                if ($id_empresa_hier === -1) die('Acesso negado a este lote (empresa não configurada).');
                $chaves_emp = v8_chaves_da_empresa($pdo, $id_empresa_hier);
                if (!in_array((int)$lote['CHAVE_ID'], array_map('intval', $chaves_emp))) die('Acesso negado a este lote (hierarquia).');
            }

            $tabela_res = !empty($lote['TABELA_DADOS']) ? $lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

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

            $sqlCpfs = "SELECT c.*, ? as NOME_IMPORTACAO, ? as TABELA_PADRAO_LOTE FROM `{$tabela_res}` c WHERE c.LOTE_ID = ? AND c.VALOR_MARGEM IS NOT NULL AND c.VALOR_MARGEM > 1";
            $stmtCpfs = $pdo->prepare($sqlCpfs);
            $stmtCpfs->execute([$lote['NOME_IMPORTACAO'], $lote['TABELA_PADRAO'] ?? '', $id_lote]);

            $stmtEnd   = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id DESC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id DESC LIMIT 3");
            $stmtTel   = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id DESC LIMIT 10");

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) {
                $linha = [
                    $row['LOTE_ID'],
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '-',
                    preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($row['CPF'], 11, '0', STR_PAD_LEFT)),
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
            // Hierarquia por empresa da chave
            $id_empresa_hier = v8_id_empresa_hierarquia($pdo);
            if ($perm_meu_registro && $id_empresa_hier !== 0) {
                if ($id_empresa_hier === -1) die('Acesso negado a este lote (empresa não configurada).');
                $chaves_emp = v8_chaves_da_empresa($pdo, $id_empresa_hier);
                if (!in_array((int)$lote['CHAVE_ID'], array_map('intval', $chaves_emp))) die('Acesso negado a este lote (hierarquia).');
            }

            $tabela_exp = !empty($lote['TABELA_DADOS']) ? $lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

            ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="Planilha_Importada_Lote_' . $id_lote . '_' . date('dmY_Hi') . '.csv"');

            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF");

            fputcsv($output, ['CPF', 'NOME', 'NASCIMENTO', 'SEXO', 'STATUS_V8', 'OBSERVACAO', 'VALOR_MARGEM', 'PRAZO', 'VALOR_LIQUIDO'], ";");

            $stmtCpfs = $pdo->prepare("SELECT CPF, NOME, NASCIMENTO, SEXO, STATUS_V8, OBSERVACAO, VALOR_MARGEM, PRAZO, VALOR_LIQUIDO FROM `{$tabela_exp}` WHERE LOTE_ID = ? ORDER BY ID ASC");
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
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $novo_status = $_POST['novo_status'] === 'ATIVO' ? 'ATIVO' : 'INATIVO';
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_LOTE = ? WHERE ID = ?")->execute([$novo_status, $id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "Status alterado para $novo_status"]); exit;

        case 'salvar_edicao_lote':
            $id_lote = (int)$_POST['id_lote'];
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $agrupamento = strtoupper(trim($_POST['agrupamento']));
            $hora_inicio_diario = !empty(trim($_POST['hora_inicio_diario'] ?? '')) ? trim($_POST['hora_inicio_diario']) : null;
            $hora_fim_diario    = !empty(trim($_POST['hora_fim_diario']    ?? '')) ? trim($_POST['hora_fim_diario'])    : null;
            if (empty($hora_inicio_diario)) throw new Exception("Informe o horário de início.");
            if (empty($hora_fim_diario))    throw new Exception("Informe o horário de fim.");
            $dias_mes_diario = !empty(trim($_POST['dias_mes_diario'] ?? '')) ? trim($_POST['dias_mes_diario']) : 'TODOS';
            $limite_diario = (int)($_POST['limite_diario'] ?? 0);
            $somente_simular = (int)($_POST['somente_simular'] ?? 0);
            $atualizar_telefone = (int)($_POST['atualizar_telefone'] ?? 0);
            $enviar_whats = (int)($_POST['enviar_whats'] ?? 0);
            $enviar_arquivo_whatsapp = (int)($_POST['enviar_arquivo_whatsapp'] ?? 0);

            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET
                NOME_IMPORTACAO = ?, AGENDAMENTO_TIPO = 'DIARIO',
                HORA_INICIO_DIARIO = ?, HORA_FIM_DIARIO = ?, DIAS_MES_DIARIO = ?,
                LIMITE_DIARIO = ?, SOMENTE_SIMULAR = ?, ATUALIZAR_TELEFONE = ?, ENVIAR_WHATSAPP = ?, ENVIAR_ARQUIVO_WHATSAPP = ?
                WHERE ID = ?")->execute([
                $agrupamento,
                $hora_inicio_diario, $hora_fim_diario, $dias_mes_diario,
                $limite_diario, $somente_simular, $atualizar_telefone, $enviar_whats, $enviar_arquivo_whatsapp,
                $id_lote
            ]);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Configurações do Lote atualizadas com sucesso!']); exit;

        case 'enviar_relatorio_whatsapp':
            $id_lote = (int)$_POST['id_lote'];
            
            $stmtLote = $pdo->prepare("SELECT NOME_IMPORTACAO, CPF_USUARIO, TABELA_DADOS FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $dados_lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

            if (!$dados_lote) { throw new Exception("Lote não encontrado no banco de dados."); }

            $nomeLote = $dados_lote['NOME_IMPORTACAO'] ?: 'LOTE';
            $cpf_dono_lote = $dados_lote['CPF_USUARIO'];
            $tabela_wapp = !empty($dados_lote['TABELA_DADOS']) ? $dados_lote['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

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
            $stmtCpfs = $pdo->prepare("SELECT c.*, l.NOME_IMPORTACAO, ca.TABELA_PADRAO FROM `{$tabela_wapp}` c JOIN INTEGRACAO_V8_IMPORTACAO_LOTE l ON c.LOTE_ID = l.ID LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON l.CHAVE_ID = ca.ID WHERE c.LOTE_ID = ? AND c.VALOR_LIQUIDO IS NOT NULL AND c.VALOR_LIQUIDO > 0");
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
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $pdo->prepare("DELETE FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?")->execute([$id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Lote e histórico apagados com sucesso.']); exit;

        case 'listar_campanhas_disponiveis':
            $id_empresa = (int)($_SESSION['id_empresa'] ?? 0);
            $stmt = $id_empresa
                ? $pdo->prepare("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' AND id_empresa = ? ORDER BY NOME_CAMPANHA ASC")
                : $pdo->prepare("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' ORDER BY NOME_CAMPANHA ASC");
            $id_empresa ? $stmt->execute([$id_empresa]) : $stmt->execute([]);
            ob_end_clean(); echo json_encode(['success' => true, 'campanhas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;

        case 'incluir_em_campanha':
            $id_campanha = (int)$_POST['id_campanha'];
            $cpfs = json_decode($_POST['cpfs'] ?? '[]', true);
            if (!$id_campanha || empty($cpfs)) throw new Exception("Campanha ou CPFs inválidos.");
            $stmt = $pdo->prepare("INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) VALUES (?, ?)");
            $inseridos = 0;
            foreach ($cpfs as $cpf) {
                $cpf = preg_replace('/\D/', '', $cpf);
                if (strlen($cpf) >= 11) { $stmt->execute([$cpf, $id_campanha]); $inseridos += $stmt->rowCount(); }
            }
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "$inseridos CPF(s) incluído(s) na campanha.", 'inseridos' => $inseridos]); exit;

        case 'listar_clientes_lote':
            $id_lote = (int)$_POST['id_lote'];
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $tabela_lc = v8_tabela_lote($pdo, $id_lote);
            $stmt = $pdo->prepare("
                SELECT CPF, NOME, STATUS_V8,
                       COALESCE(NULLIF(TRIM(OBSERVACAO),''), '') AS OBSERVACAO,
                       VALOR_MARGEM, VALOR_LIQUIDO,
                       DATA_SIMULACAO
                FROM `{$tabela_lc}`
                WHERE LOTE_ID = ?
                ORDER BY DATA_SIMULACAO DESC, ID ASC
            ");
            $stmt->execute([$id_lote]);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Formata CPF e data
            foreach ($clientes as &$c) {
                $c['CPF_FORMATADO'] = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($c['CPF'], 11, '0', STR_PAD_LEFT));
                $c['DATA_SIM_DISPLAY'] = $c['DATA_SIMULACAO'] ? date('d/m/Y H\hi', strtotime($c['DATA_SIMULACAO'])) : '';
                $c['DATA_SIM_DATE']    = $c['DATA_SIMULACAO'] ? date('Y-m-d', strtotime($c['DATA_SIMULACAO'])) : '';
            }
            ob_end_clean(); echo json_encode(['success' => true, 'clientes' => $clientes]); exit;

        case 'listar_grupos_reprocessamento':
            $id_lote = (int)$_POST['id_lote'];
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $hoje = date('Y-m-d');
            $tabela_lgr = v8_tabela_lote($pdo, $id_lote);
            $stmt = $pdo->prepare("
                SELECT
                    STATUS_V8,
                    COALESCE(NULLIF(TRIM(OBSERVACAO),''), '') AS OBSERVACAO,
                    COUNT(*) AS total,
                    SUM(CASE WHEN DATE(COALESCE(DATA_SIMULACAO, DATA_CONSENTIMENTO)) = :hoje THEN 1 ELSE 0 END) AS hoje
                FROM `{$tabela_lgr}`
                WHERE LOTE_ID = :lote
                  AND STATUS_V8 NOT IN ('OK', 'NA FILA', 'AGUARDANDO MARGEM', 'AGUARDANDO SIMULACAO', 'RECUPERAR V8')
                GROUP BY STATUS_V8, OBSERVACAO
                ORDER BY total DESC
            ");
            $stmt->execute([':lote' => $id_lote, ':hoje' => $hoje]);
            $grupos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean(); echo json_encode(['success' => true, 'grupos' => $grupos]); exit;

        case 'reprocessar_grupo':
            $id_lote   = (int)$_POST['id_lote'];
            $status_v8 = $_POST['status_v8'] ?? '';
            $observacao = $_POST['observacao'] ?? null;

            // Define a ação com base no STATUS_V8
            if ($status_v8 === 'AGUARDANDO DATAPREV') {
                $novo_status = 'AGUARDANDO MARGEM';
                $nova_obs    = 'Reverificando: aguardando retorno da Dataprev...';
            } elseif (in_array($status_v8, ['CANCELADO', 'REJEITADO'])) {
                $novo_status = 'NA FILA';
                $nova_obs    = 'Reprocessado manualmente: novo consentimento será criado.';
            } else {
                // ERRO MARGEM, ERRO SIMULACAO, ERRO CONSULTA, etc.
                $novo_status = 'RECUPERAR V8';
                $nova_obs    = 'Reprocessando erro: recuperando margem/simulação...';
            }

            $tabela_rg = v8_tabela_lote($pdo, $id_lote);
            if ($observacao !== null && $observacao !== '') {
                $pdo->prepare("UPDATE `{$tabela_rg}`
                    SET STATUS_V8 = ?, OBSERVACAO = ?
                    WHERE LOTE_ID = ? AND STATUS_V8 = ? AND TRIM(COALESCE(OBSERVACAO,'')) = ?")
                    ->execute([$novo_status, $nova_obs, $id_lote, $status_v8, trim($observacao)]);
            } else {
                $pdo->prepare("UPDATE `{$tabela_rg}`
                    SET STATUS_V8 = ?, OBSERVACAO = ?
                    WHERE LOTE_ID = ? AND STATUS_V8 = ? AND TRIM(COALESCE(OBSERVACAO,'')) = ''")
                    ->execute([$novo_status, $nova_obs, $id_lote, $status_v8]);
            }

            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);
            $stmtDono = $pdo->prepare("SELECT CPF_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
            $stmtDono->execute([$id_lote]); $cpf_dono = $stmtDono->fetchColumn();
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Grupo enviado para reprocessamento.', 'cpf_dono' => $cpf_dono]); exit;

        case 'reprocessar_consentimento':
            // Re-verifica apenas CPFs com AGUARDANDO DATAPREV → volta para AGUARDANDO MARGEM (FASE 2 relê o status da V8)
            $id_lote = (int)$_POST['id_lote'];
            $tabela_rc = v8_tabela_lote($pdo, $id_lote);
            $pdo->prepare("UPDATE `{$tabela_rc}` SET STATUS_V8 = 'AGUARDANDO MARGEM', OBSERVACAO = 'Reverificando: aguardando retorno da Dataprev...' WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO DATAPREV'")->execute([$id_lote]);
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
            $tabela_re = v8_tabela_lote($pdo, $id_lote);
            $pdo->prepare("UPDATE `{$tabela_re}`
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
            $tabela_rs = v8_tabela_lote($pdo, $id_lote);
            $pdo->prepare("UPDATE `{$tabela_rs}` SET STATUS_V8 = 'RECUPERAR V8', OBSERVACAO = 'Recuperando Margem/Simulação...' WHERE LOTE_ID = ? AND STATUS_V8 NOT IN ('OK', 'AGUARDANDO MARGEM', 'AGUARDANDO SIMULACAO')")->execute([$id_lote]);
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

            $tabela_rt = v8_tabela_lote($pdo, $id_lote);
            $pdo->prepare("UPDATE `{$tabela_rt}` SET STATUS_V8 = ?, VALOR_MARGEM = NULL, CONSULT_ID = NULL, CONFIG_ID = NULL, VALOR_LIQUIDO = NULL, SIMULATION_ID = NULL, OBSERVACAO = 'Reprocessando do zero...' WHERE LOTE_ID = ?")->execute([$novo_status, $id_lote]);
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE', QTD_PROCESSADA = 0, QTD_SUCESSO = 0, QTD_ERRO = 0 WHERE ID = ?")->execute([$id_lote]);
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $cpf_dono;
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Todos os registros foram zerados para reprocessamento.', 'cpf_dono' => $cpf_dono]); exit;

        case 'pausar_retomar_lote':
            $id_lote = (int)$_POST['id_lote'];
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            if ($_POST['acao_lote'] === 'PAUSAR') {
                $acaoLote = 'PAUSADO';
            } else {
                // Validação: horários obrigatórios para DIÁRIO
                $stmtTipo = $pdo->prepare("SELECT AGENDAMENTO_TIPO, HORA_INICIO_DIARIO, HORA_FIM_DIARIO, DIAS_MES_DIARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ?");
                $stmtTipo->execute([$id_lote]);
                $dadosRetomar = $stmtTipo->fetch(PDO::FETCH_ASSOC);
                if (!$dadosRetomar) throw new Exception("Lote não encontrado.");
                if (empty($dadosRetomar['HORA_INICIO_DIARIO']) || empty($dadosRetomar['HORA_FIM_DIARIO'])) {
                    throw new Exception("Configure o horário de início e fim antes de ligar o robô.");
                }

                // Se o horário atual está dentro da janela permitida, inicia imediatamente
                // Caso contrário, aguarda o horário de início agendado
                $horaAtual = date('H:i');
                $hIni = substr($dadosRetomar['HORA_INICIO_DIARIO'], 0, 5);
                $hFim = substr($dadosRetomar['HORA_FIM_DIARIO'], 0, 5);

                if ($horaAtual >= $hIni && $horaAtual < $hFim) {
                    // Dentro da janela: inicia direto
                    $acaoLote = 'PENDENTE';
                } else {
                    // Fora da janela: aguarda o horário de início
                    $acaoLote = 'AGUARDANDO_DIARIO';
                }
            }
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
                  AND (STATUS_LOTE = 'ATIVO' OR STATUS_LOTE IS NULL)
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

            // 3. KEEPALIVE: Re-acorda lotes que pararam no meio do dia mas ainda estão dentro da janela de horário
            //    (ex: worker sofreu timeout, atingiu limite momentaneamente, ou travou por outra razão)
            //    NÃO zera contadores — apenas coloca em PENDENTE e acorda o worker.
            $stmtKA = $pdo->query("SELECT ID, CPF_USUARIO, HORA_INICIO_DIARIO, HORA_FIM_DIARIO, DIAS_MES_DIARIO
                FROM INTEGRACAO_V8_IMPORTACAO_LOTE
                WHERE AGENDAMENTO_TIPO = 'DIARIO'
                  AND STATUS_FILA = 'AGUARDANDO_DIARIO'
                  AND (STATUS_LOTE = 'ATIVO' OR STATUS_LOTE IS NULL)
                  AND HORA_INICIO_DIARIO IS NOT NULL
                  AND ULTIMO_PROCESSAMENTO = '{$data_hoje}'
                  AND EXISTS (
                      SELECT 1 FROM INTEGRACAO_V8_REGISTROCONSULTA_LOTE r
                      WHERE r.LOTE_ID = INTEGRACAO_V8_IMPORTACAO_LOTE.ID
                        AND r.STATUS_V8 = 'NA FILA'
                      LIMIT 1
                  )");

            while ($loteKA = $stmtKA->fetch(PDO::FETCH_ASSOC)) {
                $hIni = substr($loteKA['HORA_INICIO_DIARIO'], 0, 5);
                $hFim = !empty($loteKA['HORA_FIM_DIARIO']) ? substr($loteKA['HORA_FIM_DIARIO'], 0, 5) : '23:59';

                // Só reacorda se o horário atual está dentro da janela operacional
                if ($hora_atual < $hIni || $hora_atual >= $hFim) continue;

                // Verifica se o dia bate
                $dias_ka = trim($loteKA['DIAS_MES_DIARIO'] ?? 'TODOS');
                if (!empty($dias_ka) && $dias_ka !== 'TODOS') {
                    $dias_arr_ka = array_map('intval', explode(',', $dias_ka));
                    if (!in_array($dia_atual, $dias_arr_ka)) continue;
                }

                // Reacorda sem zerar contadores
                $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$loteKA['ID']]);
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_v8_lote.php?user_cpf=' . $loteKA['CPF_USUARIO'];
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                $disparados++;
            }

            // 4. Reprocessamento automático: a cada 250 consentimentos enviados no dia, re-verifica DATAPREV
            $stmtAR = $pdo->query("SELECT l.ID, l.CPF_USUARIO, l.PROCESSADOS_HOJE, l.AUTO_REPROCESS_CHECKPOINT
                FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                WHERE l.AGENDAMENTO_TIPO = 'DIARIO'
                  AND (l.STATUS_LOTE = 'ATIVO' OR l.STATUS_LOTE IS NULL)
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
                $tabela_ar = v8_tabela_lote($pdo, (int)$lAR['ID']);
                $pdo->prepare("UPDATE `{$tabela_ar}` SET STATUS_V8 = 'AGUARDANDO MARGEM', OBSERVACAO = 'Reprocessamento automático (a cada 250 consentimentos do dia)' WHERE LOTE_ID = ? AND STATUS_V8 = 'AGUARDANDO DATAPREV'")->execute([$lAR['ID']]);
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