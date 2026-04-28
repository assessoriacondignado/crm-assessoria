<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
session_start();

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($acao !== 'exportar_resultado_lote' && $acao !== 'exportar_planilha_importada' && $acao !== 'exportar_tudo_lotes' && $acao !== 'exportar_clientes_filtrado') {
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

            // PASSO 1: Inserir o cabeçalho do lote (fora de transação — DDL logo em seguida causaria commit implícito)
            $stmtLote = $pdo->prepare("INSERT INTO INTEGRACAO_V8_IMPORTACAO_LOTE
                (NOME_IMPORTACAO, USUARIO_ID, CPF_USUARIO, CHAVE_ID, ARQUIVO_CAMINHO, QTD_TOTAL, AGENDAMENTO_TIPO, ATUALIZAR_TELEFONE, ENVIAR_WHATSAPP, SOMENTE_SIMULAR, ENVIAR_ARQUIVO_WHATSAPP, STATUS_FILA)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtLote->execute([$agrupamento, $dono_lote_id, $dono_lote_cpf, $chave_id, $_FILES['arquivo_csv']['name'], $total, $agendamento_tipo, $atualizar_telefone, $enviar_whats, $somente_simular, $enviar_arquivo_whatsapp, 'PAUSADO']);
            $id_lote = $pdo->lastInsertId();

            // PASSO 2: CREATE TABLE provoca commit implícito no MySQL — executar antes de beginTransaction
            $tabela_lote = 'V8_LOTE_' . $id_lote;
            $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tabela_lote}` LIKE INTEGRACAO_V8_REGISTROCONSULTA_LOTE");
            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET TABELA_DADOS = ? WHERE ID = ?")->execute([$tabela_lote, $id_lote]);

            // PASSO 3: Agora sim, transação só para os INSERTs de CPFs
            $pdo->beginTransaction();

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

                // TABELA_DADOS já vem no SELECT l.* da query principal — sem query extra
                $tblsUnicas = [];
                foreach ($lotes as $r) {
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

                // IDs de lotes PROCESSANDO que podem estar presos (sem itens pendentes)
                $ids_processando = [];
                foreach ($lotes as $l) {
                    if (($l['STATUS_FILA'] ?? '') === 'PROCESSANDO') $ids_processando[] = $l['ID'];
                }

                foreach ($lotes as &$l) {
                    $lid = $l['ID'];
                    $hoje = $hojeByLote[$lid] ?? ['c_hoje'=>0, 's_hoje'=>0];
                    $funil = isset($statsByLote[$lid]) ? $statsByLote[$lid] : ['c_ok'=>0, 'c_err'=>0, 'm_ok'=>0, 'm_err'=>0, 's_ok'=>0, 's_err'=>0, 'dataprev'=>0, 'na_fila'=>0];
                    $funil['c_hoje'] = $hoje['c_hoje'];
                    $funil['s_hoje'] = $hoje['s_hoje'];
                    $funil['m_hoje'] = $hoje['s_hoje'];
                    $l['funil'] = $funil;

                    // Auto-concluir lote PROCESSANDO sem nenhum item pendente (dataprev=0, na_fila=0)
                    if (($l['STATUS_FILA'] ?? '') === 'PROCESSANDO'
                        && $funil['dataprev'] === 0
                        && $funil['na_fila']   === 0
                    ) {
                        $tblLote = !empty($l['TABELA_DADOS']) ? $l['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';
                        $stmtChk = $pdo->prepare("
                            SELECT COUNT(*) FROM `{$tblLote}`
                            WHERE LOTE_ID = ?
                              AND STATUS_V8 IN ('NA FILA','AGUARDANDO MARGEM','AGUARDANDO SIMULACAO','AGUARDANDO DATAPREV','RECUPERAR V8')
                        ");
                        $stmtChk->execute([$lid]);
                        if ((int)$stmtChk->fetchColumn() === 0) {
                            $novoStatus = ($l['AGENDAMENTO_TIPO'] === 'DIARIO') ? 'AGUARDANDO_DIARIO' : 'CONCLUIDO';
                            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET STATUS_FILA = ?, DATA_FINALIZACAO = NOW() WHERE ID = ? AND STATUS_FILA = 'PROCESSANDO'")
                                ->execute([$novoStatus, $lid]);
                            $l['STATUS_FILA'] = $novoStatus;
                        }
                    }
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
            $hora_envio_csv_raw = trim($_POST['hora_envio_csv'] ?? '');
            $hora_envio_csv = preg_match('/^\d{2}:\d{2}$/', $hora_envio_csv_raw) ? $hora_envio_csv_raw : null;
            $aviso_status_wapi = (int)($_POST['aviso_status_wapi'] ?? 0);
            $id_campanha_auto = (int)($_POST['id_campanha_auto'] ?? 0) ?: null;
            $valor_margem_min = (float)($_POST['valor_margem_min_campanha'] ?? 0);

            // Verifica se a campanha existe e pertence à hierarquia do usuário
            if ($id_campanha_auto) {
                $stCampCheck = $pdo->prepare("SELECT ID FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE ID = ? AND STATUS = 'ATIVO'");
                $stCampCheck->execute([$id_campanha_auto]);
                if (!$stCampCheck->fetchColumn()) $id_campanha_auto = null;
            }

            $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET
                NOME_IMPORTACAO = ?, AGENDAMENTO_TIPO = 'DIARIO',
                HORA_INICIO_DIARIO = ?, HORA_FIM_DIARIO = ?, DIAS_MES_DIARIO = ?,
                LIMITE_DIARIO = ?, SOMENTE_SIMULAR = ?, ATUALIZAR_TELEFONE = ?, ENVIAR_WHATSAPP = ?,
                ENVIAR_ARQUIVO_WHATSAPP = ?, HORA_ENVIO_CSV = ?, AVISO_STATUS_WAPI = ?,
                ID_CAMPANHA_AUTO = ?, VALOR_MARGEM_MIN_CAMPANHA = ?
                WHERE ID = ?")->execute([
                $agrupamento,
                $hora_inicio_diario, $hora_fim_diario, $dias_mes_diario,
                $limite_diario, $somente_simular, $atualizar_telefone, $enviar_whats,
                $enviar_arquivo_whatsapp, $hora_envio_csv, $aviso_status_wapi,
                $id_campanha_auto, $valor_margem_min,
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

            // Cabeçalho igual ao modelo exportar_clientes_filtrado
            $cabecalho_rel = [
                'LOTE ID', 'NOME LOTE', 'RESPONSÁVEL',
                'CPF', 'NOME', 'STATUS CONSENTIMENTO', 'DATA CONSENTIMENTO',
                'STATUS MARGEM', 'MARGEM', 'VALOR LIBERADO', 'DATA CONSULTA', 'OBSERVAÇÃO',
                'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP',
                'TEL_1', 'TEL_2', 'TEL_3', 'TEL_4', 'TEL_5',
                'TEL_6', 'TEL_7', 'TEL_8', 'TEL_9', 'TEL_10',
                'EMAIL_1', 'EMAIL_2', 'EMAIL_3'
            ];
            fputcsv($fp, $cabecalho_rel, ";");

            $stmtNomeResp = $pdo->prepare("SELECT u.NOME FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO WHERE l.ID = ? LIMIT 1");
            $stmtNomeResp->execute([$id_lote]);
            $nomeResp = $stmtNomeResp->fetchColumn() ?: '';

            // Filtro: apenas clientes com VALOR_MARGEM > 1.00
            $stmtCpfs = $pdo->prepare("SELECT c.* FROM `{$tabela_wapp}` c WHERE c.LOTE_ID = ? AND c.VALOR_MARGEM > 1.00 ORDER BY c.DATA_SIMULACAO DESC");
            $stmtCpfs->execute([$id_lote]);

            $stmtEnd   = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id ASC LIMIT 1");
            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id ASC LIMIT 3");
            $stmtTel   = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id ASC LIMIT 10");

            while ($row = $stmtCpfs->fetch(PDO::FETCH_ASSOC)) {
                $stmtEnd->execute([$row['CPF']]); $end = $stmtEnd->fetch(PDO::FETCH_ASSOC) ?: [];
                $stmtEmail->execute([$row['CPF']]); $emails = $stmtEmail->fetchAll(PDO::FETCH_COLUMN);
                $stmtTel->execute([$row['CPF']]); $tels = $stmtTel->fetchAll(PDO::FETCH_COLUMN);

                $linha = [
                    $id_lote,
                    $nomeLote,
                    $nomeResp,
                    $row['CPF'],
                    $row['NOME'] ?? '',
                    $row['STATUS_WHATSAPP'] ?? '',
                    $row['DATA_CONSENTIMENTO'] ? date('d/m/Y H:i', strtotime($row['DATA_CONSENTIMENTO'])) : '',
                    $row['STATUS_V8'] ?? '',
                    $row['VALOR_MARGEM'] ? number_format($row['VALOR_MARGEM'], 2, ',', '.') : '',
                    $row['VALOR_LIQUIDO'] ? number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : '',
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '',
                    $row['OBSERVACAO'] ?? '',
                    $end['logradouro'] ?? '', $end['numero'] ?? '', $end['bairro'] ?? '',
                    $end['cidade'] ?? '', $end['uf'] ?? '', $end['cep'] ?? '',
                ];
                for ($i = 0; $i < 10; $i++) { $linha[] = $tels[$i] ?? ''; }
                for ($i = 0; $i < 3;  $i++) { $linha[] = $emails[$i] ?? ''; }

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

        // ==================================================================
        // LISTAR LOTES RESPEITANDO HIERARQUIA (para seletor da nova tela)
        // ==================================================================
        case 'listar_lotes_hierarquia':
            $grp_lh  = strtoupper($_SESSION['usuario_grupo'] ?? '');
            $is_m_lh = in_array($grp_lh, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
            $is_c_lh = in_array($grp_lh, ['CONSULTOR', 'CONSULTORES']);
            $cpf_lh  = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

            $id_emp_lh = null;
            if (!$is_m_lh) {
                $se = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
                $se->execute([$cpf_lh]);
                $id_emp_lh = (int)($se->fetchColumn() ?: 0);
            }

            $sqlLH = "SELECT l.ID, l.NOME_IMPORTACAO, l.STATUS_FILA, l.STATUS_LOTE,
                             u.NOME as NOME_USUARIO, l.DATA_IMPORTACAO
                      FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                      LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO
                      WHERE 1=1";
            $pLH = [];
            if ($is_c_lh) {
                // CONSULTOR: só os próprios lotes
                $sqlLH .= " AND l.CPF_USUARIO = ?"; $pLH[] = $cpf_lh;
            } elseif (!$is_m_lh && $id_emp_lh) {
                // SUPERVISOR: lotes da empresa (por id_empresa OU por CPF_USUARIO de usuários da mesma empresa)
                $sqlLH .= " AND (l.id_empresa = ? OR l.CPF_USUARIO IN (SELECT CPF FROM CLIENTE_USUARIO WHERE id_empresa = ?))";
                $pLH[] = $id_emp_lh; $pLH[] = $id_emp_lh;
            }
            $sqlLH .= " ORDER BY l.STATUS_LOTE ASC, l.ID DESC LIMIT 300";
            $stLH = $pdo->prepare($sqlLH);
            $stLH->execute($pLH);
            $lotesLH = $stLH->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean(); echo json_encode(['success' => true, 'lotes' => $lotesLH]); exit;

        case 'listar_campanhas_disponiveis':
            // MASTER/ADMIN vê todas; demais filtram pela empresa
            $camp_grupo  = strtoupper($_SESSION['usuario_grupo'] ?? '');
            $camp_master = in_array($camp_grupo, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
            $camp_consul = in_array($camp_grupo, ['CONSULTOR', 'CONSULTORES']);
            if ($camp_master) {
                $stmt = $pdo->prepare("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' ORDER BY NOME_CAMPANHA ASC");
                $stmt->execute([]);
            } else {
                $camp_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
                $s_emp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
                $s_emp->execute([$camp_cpf]);
                $camp_empresa = (int)($s_emp->fetchColumn() ?: 0);
                if ($camp_empresa > 0) {
                    if ($camp_consul) {
                        // CONSULTOR: apenas campanhas vinculadas ao seu usuário
                        $stmt = $pdo->prepare("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' AND (id_empresa = ? OR id_empresa IS NULL) AND (id_usuario = ? OR FIND_IN_SET(?, CPF_USUARIO)) ORDER BY NOME_CAMPANHA ASC");
                        $id_u_camp = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1"); $id_u_camp->execute([$camp_cpf]); $id_u_camp_num = (int)($id_u_camp->fetchColumn() ?: 0);
                        $stmt->execute([$camp_empresa, $id_u_camp_num, $camp_cpf]);
                    } else {
                        // SUPERVISOR: todas as campanhas da empresa (id_empresa OU criadas por usuários da empresa)
                        $stmt = $pdo->prepare("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' AND (id_empresa = ? OR id_empresa IS NULL OR id_usuario IN (SELECT ID FROM CLIENTE_USUARIO WHERE id_empresa = ?)) ORDER BY NOME_CAMPANHA ASC");
                        $stmt->execute([$camp_empresa, $camp_empresa]);
                    }
                } else {
                    ob_end_clean(); echo json_encode(['success' => true, 'campanhas' => []]); exit;
                }
            }
            ob_end_clean(); echo json_encode(['success' => true, 'campanhas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;

        case 'incluir_em_campanha':
            $id_campanha = (int)$_POST['id_campanha'];
            $cpfs = json_decode($_POST['cpfs'] ?? '[]', true);
            $id_lote_camp = (int)($_POST['id_lote'] ?? 0);
            if (!$id_campanha || empty($cpfs)) throw new Exception("Campanha ou CPFs inválidos.");

            // Garante que cada CPF existe em dados_cadastrais (exigido pela FK)
            // Busca nome no lote para preencher caso não exista
            $tabela_camp = v8_tabela_lote($pdo, $id_lote_camp);
            $stmtGarantir = $pdo->prepare("INSERT IGNORE INTO dados_cadastrais (cpf, nome) VALUES (?, ?)");
            $stmtNome = $pdo->prepare("SELECT NOME FROM {$tabela_camp} WHERE CPF = ? LIMIT 1");

            $stmt = $pdo->prepare("INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) VALUES (?, ?)");
            $inseridos = 0;
            foreach ($cpfs as $cpf) {
                $cpf = preg_replace('/\D/', '', $cpf);
                if (strlen($cpf) < 11) continue;
                // Garante cadastro mínimo antes de vincular à campanha
                $stmtNome->execute([$cpf]);
                $nome_lote = $stmtNome->fetchColumn() ?: 'IMPORTADO VIA LOTE V8';
                $stmtGarantir->execute([$cpf, $nome_lote]);
                $stmt->execute([$cpf, $id_campanha]);
                $inseridos += $stmt->rowCount();
            }
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "$inseridos CPF(s) incluído(s) na campanha.", 'inseridos' => $inseridos]); exit;

        // ============================================================
        // INCLUIR EM AUDITORIA
        // ============================================================
        case 'incluir_em_auditoria':
            // Verificar permissão específica de auditoria
            $caminho_perm_aud = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
            if (file_exists($caminho_perm_aud)) { include_once $caminho_perm_aud; }
            if (!verificaPermissao($pdo, 'v8_AUDITORIA_INCLUSAO_CPF', 'FUNCAO')) {
                throw new Exception("Sem permissão para incluir em auditoria.");
            }

            $id_lote_aud   = (int)$_POST['id_lote'];
            $cpfs_aud      = json_decode($_POST['cpfs'] ?? '[]', true);
            if (!$id_lote_aud || empty($cpfs_aud)) throw new Exception("Lote ou CPFs inválidos.");

            v8_verificar_dono_lote($pdo, $id_lote_aud, $usuario_logado_cpf);

            // Dados do lote original e do auditor
            $stmtLoteAud = $pdo->prepare("SELECT * FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID = ? LIMIT 1");
            $stmtLoteAud->execute([$id_lote_aud]);
            $loteAud = $stmtLoteAud->fetch(PDO::FETCH_ASSOC);
            if (!$loteAud) throw new Exception("Lote não encontrado.");

            $tabela_aud = v8_tabela_lote($pdo, $id_lote_aud);

            // Empresa do auditor
            $stmtEmpAud = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtEmpAud->execute([$usuario_logado_cpf]);
            $id_empresa_aud = $stmtEmpAud->fetchColumn() ?: null;

            // Chave do auditor (a primeira disponível)
            $chave_aud_id = null;
            try {
                $stmtChaveAud = $pdo->prepare("SELECT ID FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE CPF_USUARIO = ? LIMIT 1");
                $stmtChaveAud->execute([$usuario_logado_cpf]);
                $chave_aud_id = $stmtChaveAud->fetchColumn() ?: null;
            } catch (Exception $e) {}

            // Garante que as tabelas de auditoria existem
            $pdo->exec("CREATE TABLE IF NOT EXISTS `V8_LOTE_AUDITORIA` (
                `ID` int NOT NULL AUTO_INCREMENT,
                `NOME_AUDITORIA` varchar(200) NOT NULL,
                `LOTE_ORIGEM_ID` int DEFAULT NULL,
                `LOTE_ORIGEM_NOME` varchar(200) DEFAULT NULL,
                `TABELA_ORIGEM` varchar(100) DEFAULT NULL,
                `USUARIO_AUDITOR_CPF` varchar(14) DEFAULT NULL,
                `USUARIO_AUDITOR_NOME` varchar(150) DEFAULT NULL,
                `CHAVE_AUDITORIA_ID` int DEFAULT NULL,
                `id_empresa` int DEFAULT NULL,
                `QTD_CPF` int DEFAULT 0,
                `STATUS_AUDITORIA` enum('ATIVO','ARQUIVADO') DEFAULT 'ATIVO',
                `DATA_CRIACAO` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS `V8_LOTE_AUDITORIA_DADOS` (
                `ID` int NOT NULL AUTO_INCREMENT,
                `AUDITORIA_ID` int NOT NULL,
                `CPF` varchar(14) NOT NULL,
                `NOME` varchar(150) DEFAULT NULL,
                `NASCIMENTO` date DEFAULT NULL,
                `SEXO` varchar(20) DEFAULT NULL,
                `VALOR_MARGEM` decimal(10,2) DEFAULT NULL,
                `PRAZO` int DEFAULT NULL,
                `VALOR_LIQUIDO` decimal(10,2) DEFAULT NULL,
                `STATUS_V8` varchar(50) DEFAULT NULL,
                `OBSERVACAO` text DEFAULT NULL,
                `CONSULT_ID` varchar(150) DEFAULT NULL,
                `CONFIG_ID` varchar(150) DEFAULT NULL,
                `SIMULATION_ID` varchar(150) DEFAULT NULL,
                `DATA_SIMULACAO` datetime DEFAULT NULL,
                `DATA_TRANSFERENCIA` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID`),
                KEY `idx_auditoria_id` (`AUDITORIA_ID`),
                KEY `idx_cpf` (`CPF`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->beginTransaction();

            // Nome do auditor
            $stmtNomeAud = $pdo->prepare("SELECT NOME FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtNomeAud->execute([$usuario_logado_cpf]);
            $nome_auditor = $stmtNomeAud->fetchColumn() ?: $usuario_logado_cpf;

            // Cria registro de auditoria
            $nome_auditoria = 'Auditoria — ' . ($loteAud['NOME_IMPORTACAO'] ?? 'Lote #'.$id_lote_aud) . ' — ' . date('d/m/Y H:i');
            $stmtInsAud = $pdo->prepare("INSERT INTO V8_LOTE_AUDITORIA (NOME_AUDITORIA, LOTE_ORIGEM_ID, LOTE_ORIGEM_NOME, TABELA_ORIGEM, USUARIO_AUDITOR_CPF, USUARIO_AUDITOR_NOME, CHAVE_AUDITORIA_ID, id_empresa, QTD_CPF) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmtInsAud->execute([$nome_auditoria, $id_lote_aud, $loteAud['NOME_IMPORTACAO'], $tabela_aud, $usuario_logado_cpf, $nome_auditor, $chave_aud_id, $id_empresa_aud, count($cpfs_aud)]);
            $id_auditoria_novo = $pdo->lastInsertId();

            // Copia registros para V8_LOTE_AUDITORIA_DADOS e marca como AUDITADO na tabela original
            $stmtBuscarCpf = $pdo->prepare("SELECT * FROM `{$tabela_aud}` WHERE CPF = ? AND LOTE_ID = ? LIMIT 1");
            $stmtInsDados  = $pdo->prepare("INSERT INTO V8_LOTE_AUDITORIA_DADOS (AUDITORIA_ID, CPF, NOME, NASCIMENTO, SEXO, VALOR_MARGEM, PRAZO, VALOR_LIQUIDO, STATUS_V8, OBSERVACAO, CONSULT_ID, CONFIG_ID, SIMULATION_ID, DATA_SIMULACAO) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmtBloquear  = $pdo->prepare("UPDATE `{$tabela_aud}` SET STATUS_V8 = 'AUDITADO', OBSERVACAO = 'Transferido para auditoria #{$id_auditoria_novo}' WHERE CPF = ? AND LOTE_ID = ?");

            $transferidos = 0;
            foreach ($cpfs_aud as $cpf_raw) {
                $cpf = preg_replace('/\D/', '', $cpf_raw);
                if (strlen($cpf) < 11) continue;
                $stmtBuscarCpf->execute([$cpf, $id_lote_aud]);
                $row = $stmtBuscarCpf->fetch(PDO::FETCH_ASSOC);
                if (!$row) continue;

                $stmtInsDados->execute([
                    $id_auditoria_novo,
                    $cpf,
                    $row['NOME']        ?? null,
                    $row['NASCIMENTO']  ?? null,
                    $row['SEXO']        ?? null,
                    $row['VALOR_MARGEM'] ?? null,
                    $row['PRAZO']       ?? null,
                    $row['VALOR_LIQUIDO'] ?? null,
                    $row['STATUS_V8']   ?? null,
                    $row['OBSERVACAO']  ?? null,
                    $row['CONSULT_ID']  ?? null,
                    $row['CONFIG_ID']   ?? null,
                    $row['SIMULATION_ID'] ?? null,
                    $row['DATA_SIMULACAO'] ?? null,
                ]);

                // Bloqueia na tabela original
                $stmtBloquear->execute([$cpf, $id_lote_aud]);

                // Remove log do rodapé do cliente
                try {
                    $cpf_padded = str_pad($cpf, 11, '0', STR_PAD_LEFT);
                    $pdo->prepare("DELETE FROM dados_cadastrais_log_rodape WHERE CPF_CLIENTE = ? AND TEXTO_REGISTRO LIKE '%V8%'")->execute([$cpf_padded]);
                } catch (Exception $e) {}

                $transferidos++;
            }

            $pdo->commit();
            ob_end_clean(); echo json_encode([
                'success'      => true,
                'msg'          => "{$transferidos} CPF(s) transferido(s) para a Auditoria #{$id_auditoria_novo}.",
                'transferidos' => $transferidos,
                'id_auditoria' => $id_auditoria_novo
            ]); exit;

        case 'listar_clientes_lote':
            $id_lote = (int)$_POST['id_lote'];
            v8_verificar_dono_lote($pdo, $id_lote, $usuario_logado_cpf);
            $tabela_lc = v8_tabela_lote($pdo, $id_lote);
            $stmt = $pdo->prepare("
                SELECT t.CPF, t.NOME, t.STATUS_V8,
                       COALESCE(NULLIF(TRIM(t.OBSERVACAO),''), '') AS OBSERVACAO,
                       t.VALOR_MARGEM, t.VALOR_LIQUIDO,
                       t.DATA_SIMULACAO, t.DATA_CONSENTIMENTO, t.STATUS_WHATSAPP
                FROM `{$tabela_lc}` t
                WHERE t.LOTE_ID = ?
                  AND (t.STATUS_V8 IS NULL OR t.STATUS_V8 != 'AUDITADO')
                ORDER BY t.DATA_SIMULACAO DESC, t.ID ASC
            ");
            $stmt->execute([$id_lote]);
            $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($clientes as &$c) {
                $c['CPF_FORMATADO']       = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($c['CPF'], 11, '0', STR_PAD_LEFT));
                $c['DATA_SIM_DISPLAY']    = $c['DATA_SIMULACAO']    ? date('d/m/Y H\hi', strtotime($c['DATA_SIMULACAO'])) : '';
                $c['DATA_SIM_DATE']       = $c['DATA_SIMULACAO']    ? date('Y-m-d', strtotime($c['DATA_SIMULACAO'])) : '';
                $c['DATA_CONS_DISPLAY']   = $c['DATA_CONSENTIMENTO'] ? date('d/m/Y H\hi', strtotime($c['DATA_CONSENTIMENTO'])) : '';
            }
            ob_end_clean(); echo json_encode(['success' => true, 'clientes' => $clientes]); exit;

        // ==================================================================
        // NOVA AÇÃO: listagem avançada com filtros server-side + paginação
        // ==================================================================
        case 'listar_clientes_avancado':
            // Aceita array de IDs ou id_lote único (0 = todos)
            $ids_lote_raw = $_POST['ids_lote'] ?? null;
            if ($ids_lote_raw !== null) {
                $ids_lote_sel = array_values(array_filter(array_map('intval', is_array($ids_lote_raw) ? $ids_lote_raw : explode(',', $ids_lote_raw))));
            } else {
                $id_lote_single = (int)($_POST['id_lote'] ?? 0);
                $ids_lote_sel = $id_lote_single > 0 ? [$id_lote_single] : [];
            }
            $id_lote   = count($ids_lote_sel) === 1 ? $ids_lote_sel[0] : 0;
            $offset    = max(0, (int)($_POST['offset'] ?? 0));
            $limite    = 100;

            // ---- Monta cláusulas de filtro comuns (sem o filtro de lote) ----
            $params_filtro = [];
            $where_filtro  = "(t.STATUS_V8 IS NULL OR t.STATUS_V8 != 'AUDITADO')";
            $q = trim($_POST['q'] ?? '');
            if ($q !== '') {
                $qLimpo = preg_replace('/\D/', '', $q);
                if ($qLimpo !== '') { $where_filtro .= " AND t.CPF LIKE ?"; $params_filtro[] = "%{$qLimpo}%"; }
                else { $where_filtro .= " AND t.NOME LIKE ?"; $params_filtro[] = "%{$q}%"; }
            }
            if (!empty($_POST['status_margem'])) { $where_filtro .= " AND t.STATUS_V8 = ?";                  $params_filtro[] = $_POST['status_margem']; }
            if (!empty($_POST['status_cons']))   { $where_filtro .= " AND t.STATUS_WHATSAPP = ?";             $params_filtro[] = $_POST['status_cons']; }
            if (!empty($_POST['cons_de']))        { $where_filtro .= " AND DATE(t.DATA_CONSENTIMENTO) >= ?";  $params_filtro[] = $_POST['cons_de']; }
            if (!empty($_POST['cons_ate']))       { $where_filtro .= " AND DATE(t.DATA_CONSENTIMENTO) <= ?";  $params_filtro[] = $_POST['cons_ate']; }
            if (!empty($_POST['sim_de']))         { $where_filtro .= " AND DATE(t.DATA_SIMULACAO) >= ?";      $params_filtro[] = $_POST['sim_de']; }
            if (!empty($_POST['sim_ate']))        { $where_filtro .= " AND DATE(t.DATA_SIMULACAO) <= ?";      $params_filtro[] = $_POST['sim_ate']; }
            if (isset($_POST['margem_min']) && $_POST['margem_min'] !== '') { $where_filtro .= " AND t.VALOR_MARGEM >= ?";  $params_filtro[] = (float)$_POST['margem_min']; }
            if (isset($_POST['margem_max']) && $_POST['margem_max'] !== '') { $where_filtro .= " AND t.VALOR_MARGEM <= ?";  $params_filtro[] = (float)$_POST['margem_max']; }
            if (isset($_POST['lib_min'])    && $_POST['lib_min']    !== '') { $where_filtro .= " AND t.VALOR_LIQUIDO >= ?"; $params_filtro[] = (float)$_POST['lib_min']; }
            if (isset($_POST['lib_max'])    && $_POST['lib_max']    !== '') { $where_filtro .= " AND t.VALOR_LIQUIDO <= ?"; $params_filtro[] = (float)$_POST['lib_max']; }

            // ---- Decide se é lote(s) específico(s) ou TODOS ----
            $infoLote = null;
            if (!empty($ids_lote_sel)) {
                // Um ou mais lotes selecionados — UNION ALL se mais de um
                foreach ($ids_lote_sel as $lid) { v8_verificar_dono_lote($pdo, $lid, $usuario_logado_cpf); }

                if (count($ids_lote_sel) === 1) {
                    $id_lote = $ids_lote_sel[0];
                    $tbl_av  = v8_tabela_lote($pdo, $id_lote);

                    $stLote = $pdo->prepare("SELECT l.ID, l.NOME_IMPORTACAO, u.NOME as NOME_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO WHERE l.ID = ? LIMIT 1");
                    $stLote->execute([$id_lote]);
                    $infoLote = $stLote->fetch(PDO::FETCH_ASSOC);

                    $params_av = array_merge([$id_lote], $params_filtro);
                    $where_av  = "WHERE t.LOTE_ID = ? AND {$where_filtro}";
                    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM `{$tbl_av}` t {$where_av}");
                    $stCnt->execute($params_av);
                    $total = (int)$stCnt->fetchColumn();
                    $stAv  = $pdo->prepare("SELECT t.ID, t.LOTE_ID, t.CPF, t.NOME, t.STATUS_V8,
                           COALESCE(NULLIF(TRIM(t.OBSERVACAO),''),'') AS OBSERVACAO,
                           t.VALOR_MARGEM, t.VALOR_LIQUIDO, t.DATA_SIMULACAO, t.DATA_CONSENTIMENTO, t.STATUS_WHATSAPP
                        FROM `{$tbl_av}` t {$where_av} ORDER BY t.DATA_SIMULACAO DESC, t.ID ASC LIMIT {$limite} OFFSET {$offset}");
                    $stAv->execute($params_av);

                } else {
                    // Múltiplos lotes selecionados — UNION ALL das tabelas
                    $lotesAcessiveis = [];
                    foreach ($ids_lote_sel as $lid) {
                        $tbl = v8_tabela_lote($pdo, $lid);
                        $lotesAcessiveis[] = ['ID' => $lid, 'TABELA' => $tbl];
                    }
                    goto build_union;
                }

            } else {
                // TODOS os lotes acessíveis — monta UNION ALL
                $grp_td = strtoupper($_SESSION['usuario_grupo'] ?? '');
                $is_m_td = in_array($grp_td, ['MASTER','ADMIN','ADMINISTRADOR']);
                $is_c_td = in_array($grp_td, ['CONSULTOR','CONSULTORES']);
                $cpf_td  = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

                $id_emp_td = null;
                if (!$is_m_td) {
                    $se = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
                    $se->execute([$cpf_td]);
                    $id_emp_td = (int)($se->fetchColumn() ?: 0);
                }

                $sqlLotes = "SELECT l.ID, COALESCE(l.TABELA_DADOS,'INTEGRACAO_V8_REGISTROCONSULTA_LOTE') as TABELA
                             FROM INTEGRACAO_V8_IMPORTACAO_LOTE l WHERE 1=1";
                $pLotes = [];
                if ($is_c_td) {
                    $sqlLotes .= " AND l.CPF_USUARIO = ?"; $pLotes[] = $cpf_td;
                } elseif (!$is_m_td && $id_emp_td) {
                    $sqlLotes .= " AND (l.id_empresa = ? OR l.CPF_USUARIO IN (SELECT CPF FROM CLIENTE_USUARIO WHERE id_empresa = ?))";
                    $pLotes[] = $id_emp_td; $pLotes[] = $id_emp_td;
                }
                $stL = $pdo->prepare($sqlLotes); $stL->execute($pLotes);
                $lotesAcessiveis = $stL->fetchAll(PDO::FETCH_ASSOC);

                if (empty($lotesAcessiveis)) {
                    ob_end_clean(); echo json_encode(['success'=>true,'clientes'=>[],'total'=>0,'tem_mais'=>false,'info_lote'=>null]); exit;
                }

                build_union:
                // Deduplica tabelas agrupando lotes que usam a mesma tabela física
                $tabelasUnicas = [];
                foreach ($lotesAcessiveis as $lAc) {
                    $tbl = $lAc['TABELA'] ?? v8_tabela_lote($pdo, (int)$lAc['ID']);
                    if (!isset($tabelasUnicas[$tbl])) $tabelasUnicas[$tbl] = [];
                    $tabelasUnicas[$tbl][] = (int)$lAc['ID'];
                }

                $unionParts = []; $params_union = [];
                foreach ($tabelasUnicas as $tbl => $ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $unionParts[] = "SELECT t.ID, t.LOTE_ID, t.CPF, t.NOME, t.STATUS_V8,
                        COALESCE(NULLIF(TRIM(t.OBSERVACAO),''),'') AS OBSERVACAO,
                        t.VALOR_MARGEM, t.VALOR_LIQUIDO, t.DATA_SIMULACAO, t.DATA_CONSENTIMENTO, t.STATUS_WHATSAPP
                        FROM `{$tbl}` t WHERE t.LOTE_ID IN ({$ph}) AND {$where_filtro}";
                    $params_union = array_merge($params_union, $ids, $params_filtro);
                }
                $sqlUnion = "SELECT * FROM (" . implode(" UNION ALL ", $unionParts) . ") u";
                $stCnt2 = $pdo->prepare("SELECT COUNT(*) FROM ({$sqlUnion}) cnt");
                $stCnt2->execute($params_union);
                $total = (int)$stCnt2->fetchColumn();
                $stAv  = $pdo->prepare("{$sqlUnion} ORDER BY u.DATA_SIMULACAO DESC, u.ID ASC LIMIT {$limite} OFFSET {$offset}");
                $stAv->execute($params_union);
            }

            $rows = $stAv->fetchAll(PDO::FETCH_ASSOC);

            // Resolve NOME_LOTE para cada linha em uma única query
            $lote_ids_unicos = array_values(array_unique(array_filter(array_column($rows, 'LOTE_ID'))));
            $mapa_nomes_lote = [];
            if (!empty($lote_ids_unicos)) {
                $ph_ln = implode(',', array_fill(0, count($lote_ids_unicos), '?'));
                $stNL  = $pdo->prepare("SELECT ID, NOME_IMPORTACAO FROM INTEGRACAO_V8_IMPORTACAO_LOTE WHERE ID IN ($ph_ln)");
                $stNL->execute($lote_ids_unicos);
                $mapa_nomes_lote = array_column($stNL->fetchAll(PDO::FETCH_ASSOC), 'NOME_IMPORTACAO', 'ID');
            }

            foreach ($rows as &$r) {
                $r['CPF_FORMATADO']     = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($r['CPF'], 11, '0', STR_PAD_LEFT));
                $r['DATA_SIM_DISPLAY']  = $r['DATA_SIMULACAO']    ? date('d/m/Y H\hi', strtotime($r['DATA_SIMULACAO']))    : '';
                $r['DATA_CONS_DISPLAY'] = $r['DATA_CONSENTIMENTO'] ? date('d/m/Y H\hi', strtotime($r['DATA_CONSENTIMENTO'])) : '';
                $r['NOME_LOTE']         = $mapa_nomes_lote[$r['LOTE_ID']] ?? '';
            }
            ob_end_clean(); echo json_encode([
                'success'   => true,
                'clientes'  => $rows,
                'total'     => $total,
                'tem_mais'  => ($offset + $limite) < $total,
                'info_lote' => $infoLote,
            ]); exit;

        // ==================================================================
        // NOVA AÇÃO: exportar clientes filtrados (suporta múltiplos lotes)
        // ==================================================================
        case 'exportar_clientes_filtrado':
            // Aceita ids_lote[] (array) ou id_lote único
            $ids_lote_raw_g = $_GET['ids_lote'] ?? null;
            if ($ids_lote_raw_g !== null) {
                $ids_lote_exp = array_values(array_filter(array_map('intval', is_array($ids_lote_raw_g) ? $ids_lote_raw_g : [$ids_lote_raw_g])));
            } else {
                $id_s = (int)($_GET['id_lote'] ?? 0);
                $ids_lote_exp = $id_s > 0 ? [$id_s] : [];
            }
            if (empty($ids_lote_exp)) { http_response_code(400); echo 'Nenhum lote selecionado'; exit; }
            foreach ($ids_lote_exp as $lid_v) { v8_verificar_dono_lote($pdo, $lid_v, $usuario_logado_cpf); }

            // Mapa de info dos lotes
            $ph_exp = implode(',', array_fill(0, count($ids_lote_exp), '?'));
            $stLotesExp = $pdo->prepare("SELECT l.ID, l.NOME_IMPORTACAO, u.NOME as NOME_USUARIO
                FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO
                WHERE l.ID IN ({$ph_exp})");
            $stLotesExp->execute($ids_lote_exp);
            $lotesExpMap = [];
            while ($li = $stLotesExp->fetch(PDO::FETCH_ASSOC)) { $lotesExpMap[(int)$li['ID']] = $li; }

            // Filtros comuns
            $params_ef = [];
            $where_ef  = "(t.STATUS_V8 IS NULL OR t.STATUS_V8 != 'AUDITADO')";
            $q = trim($_GET['q'] ?? '');
            if ($q !== '') {
                $qL = preg_replace('/\D/', '', $q);
                if ($qL !== '') { $where_ef .= " AND t.CPF LIKE ?"; $params_ef[] = "%{$qL}%"; }
                else { $where_ef .= " AND t.NOME LIKE ?"; $params_ef[] = "%{$q}%"; }
            }
            if (!empty($_GET['status_margem'])) { $where_ef .= " AND t.STATUS_V8 = ?";                 $params_ef[] = $_GET['status_margem']; }
            if (!empty($_GET['status_cons']))   { $where_ef .= " AND t.STATUS_WHATSAPP = ?";            $params_ef[] = $_GET['status_cons']; }
            if (!empty($_GET['cons_de']))        { $where_ef .= " AND DATE(t.DATA_CONSENTIMENTO) >= ?"; $params_ef[] = $_GET['cons_de']; }
            if (!empty($_GET['cons_ate']))       { $where_ef .= " AND DATE(t.DATA_CONSENTIMENTO) <= ?"; $params_ef[] = $_GET['cons_ate']; }
            if (!empty($_GET['sim_de']))         { $where_ef .= " AND DATE(t.DATA_SIMULACAO) >= ?";     $params_ef[] = $_GET['sim_de']; }
            if (!empty($_GET['sim_ate']))        { $where_ef .= " AND DATE(t.DATA_SIMULACAO) <= ?";     $params_ef[] = $_GET['sim_ate']; }
            if (isset($_GET['margem_min']) && $_GET['margem_min'] !== '') { $where_ef .= " AND t.VALOR_MARGEM >= ?";  $params_ef[] = (float)$_GET['margem_min']; }
            if (isset($_GET['margem_max']) && $_GET['margem_max'] !== '') { $where_ef .= " AND t.VALOR_MARGEM <= ?";  $params_ef[] = (float)$_GET['margem_max']; }
            if (isset($_GET['lib_min'])    && $_GET['lib_min']    !== '') { $where_ef .= " AND t.VALOR_LIQUIDO >= ?"; $params_ef[] = (float)$_GET['lib_min']; }
            if (isset($_GET['lib_max'])    && $_GET['lib_max']    !== '') { $where_ef .= " AND t.VALOR_LIQUIDO <= ?"; $params_ef[] = (float)$_GET['lib_max']; }

            // UNION ALL agrupando lotes pela mesma tabela física
            $tblsExp = [];
            foreach ($ids_lote_exp as $lid_e) {
                $tbl_e = v8_tabela_lote($pdo, $lid_e);
                if (!isset($tblsExp[$tbl_e])) $tblsExp[$tbl_e] = [];
                $tblsExp[$tbl_e][] = $lid_e;
            }
            $unionExp = []; $params_eu = [];
            foreach ($tblsExp as $tbl_e => $ids_e) {
                $ph_e = implode(',', array_fill(0, count($ids_e), '?'));
                $unionExp[] = "SELECT t.LOTE_ID, t.CPF, t.NOME, t.STATUS_V8, t.OBSERVACAO,
                               t.VALOR_MARGEM, t.VALOR_LIQUIDO, t.DATA_SIMULACAO, t.DATA_CONSENTIMENTO, t.STATUS_WHATSAPP
                               FROM `{$tbl_e}` t WHERE t.LOTE_ID IN ({$ph_e}) AND {$where_ef}";
                $params_eu = array_merge($params_eu, $ids_e, $params_ef);
            }
            $sqlExp = "SELECT * FROM (" . implode(" UNION ALL ", $unionExp) . ") u ORDER BY DATA_SIMULACAO DESC";
            $stExp = $pdo->prepare($sqlExp);
            $stExp->execute($params_eu);

            $nArq = count($ids_lote_exp) === 1
                ? 'clientes_lote_' . $ids_lote_exp[0] . '_' . date('Ymd_His') . '.csv'
                : 'clientes_lotes_export_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $nArq . '"');
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'LOTE ID', 'NOME LOTE', 'RESPONSÁVEL',
                'CPF', 'NOME', 'STATUS CONSENTIMENTO', 'DATA CONSENTIMENTO',
                'STATUS MARGEM', 'MARGEM', 'VALOR LIBERADO', 'DATA CONSULTA', 'OBSERVAÇÃO',
                'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP',
                'TEL_1', 'TEL_2', 'TEL_3', 'TEL_4', 'TEL_5',
                'TEL_6', 'TEL_7', 'TEL_8', 'TEL_9', 'TEL_10',
                'EMAIL_1', 'EMAIL_2', 'EMAIL_3'
            ], ';');
            $stEEnd   = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id ASC LIMIT 1");
            $stEEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id ASC LIMIT 3");
            $stETel   = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id ASC LIMIT 10");
            while ($row = $stExp->fetch(PDO::FETCH_ASSOC)) {
                $lid_row  = (int)$row['LOTE_ID'];
                $lInfo    = $lotesExpMap[$lid_row] ?? ['NOME_IMPORTACAO' => "Lote #{$lid_row}", 'NOME_USUARIO' => ''];
                $stEEnd->execute([$row['CPF']]);   $eEnd   = $stEEnd->fetch(PDO::FETCH_ASSOC) ?: [];
                $stEEmail->execute([$row['CPF']]); $eEmails= $stEEmail->fetchAll(PDO::FETCH_COLUMN);
                $stETel->execute([$row['CPF']]);   $eTels  = $stETel->fetchAll(PDO::FETCH_COLUMN);
                $linha = [
                    $lid_row,
                    $lInfo['NOME_IMPORTACAO'] ?? '',
                    $lInfo['NOME_USUARIO'] ?? '',
                    $row['CPF'],
                    $row['NOME'] ?? '',
                    $row['STATUS_WHATSAPP'] ?? '',
                    $row['DATA_CONSENTIMENTO'] ? date('d/m/Y H:i', strtotime($row['DATA_CONSENTIMENTO'])) : '',
                    $row['STATUS_V8'] ?? '',
                    $row['VALOR_MARGEM'] ? number_format($row['VALOR_MARGEM'], 2, ',', '.') : '',
                    $row['VALOR_LIQUIDO'] ? number_format($row['VALOR_LIQUIDO'], 2, ',', '.') : '',
                    $row['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($row['DATA_SIMULACAO'])) : '',
                    $row['OBSERVACAO'] ?? '',
                    $eEnd['logradouro'] ?? '', $eEnd['numero'] ?? '', $eEnd['bairro'] ?? '',
                    $eEnd['cidade'] ?? '', $eEnd['uf'] ?? '', $eEnd['cep'] ?? '',
                ];
                for ($i = 0; $i < 10; $i++) { $linha[] = $eTels[$i] ?? ''; }
                for ($i = 0; $i < 3;  $i++) { $linha[] = $eEmails[$i] ?? ''; }
                fputcsv($out, $linha, ';');
            }
            fclose($out); exit;

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

            // 5. Envio automático do CSV no horário configurado (HORA_ENVIO_CSV)
            $stmtCSV = $pdo->query("
                SELECT l.ID, l.NOME_IMPORTACAO, l.CPF_USUARIO, l.HORA_ENVIO_CSV, l.TABELA_DADOS
                FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                WHERE l.ENVIAR_ARQUIVO_WHATSAPP = 1
                  AND l.HORA_ENVIO_CSV IS NOT NULL
                  AND l.HORA_ENVIO_CSV = '{$hora_atual}'
                  AND (l.DATA_ULTIMO_ENVIO_CSV IS NULL OR l.DATA_ULTIMO_ENVIO_CSV < CURDATE())
                  AND (l.STATUS_LOTE = 'ATIVO' OR l.STATUS_LOTE IS NULL)
            ");
            while ($lCSV = $stmtCSV->fetch(PDO::FETCH_ASSOC)) {
                try {
                    $id_lote_csv = $lCSV['ID'];
                    $nomeLoteCSV = $lCSV['NOME_IMPORTACAO'] ?: 'LOTE';
                    $cpf_dono_csv = $lCSV['CPF_USUARIO'];
                    $tabela_csv   = !empty($lCSV['TABELA_DADOS']) ? $lCSV['TABELA_DADOS'] : 'INTEGRACAO_V8_REGISTROCONSULTA_LOTE';

                    $pasta_csv = $_SERVER['DOCUMENT_ROOT'] . '/logs_v8/relatorio_v8';
                    if (!is_dir($pasta_csv)) @mkdir($pasta_csv, 0777, true);

                    $nome_arq = "Relatorio_" . preg_replace('/[^a-zA-Z0-9]/', '_', $nomeLoteCSV) . "_" . time() . ".csv";
                    $caminho_csv = $pasta_csv . '/' . $nome_arq;
                    $protocol_csv = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                    $urlArqCSV = $protocol_csv . "://" . $_SERVER['HTTP_HOST'] . "/logs_v8/relatorio_v8/" . $nome_arq;

                    $fp_csv = @fopen($caminho_csv, 'w');
                    if (!$fp_csv) { $disparados++; continue; }
                    fputs($fp_csv, "\xEF\xBB\xBF");

                    $stmtRespCSV = $pdo->prepare("SELECT u.NOME FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO WHERE l.ID = ? LIMIT 1");
                    $stmtRespCSV->execute([$id_lote_csv]);
                    $nomeRespCSV = $stmtRespCSV->fetchColumn() ?: '';

                    $cab = [
                        'LOTE ID', 'NOME LOTE', 'RESPONSÁVEL',
                        'CPF', 'NOME', 'STATUS CONSENTIMENTO', 'DATA CONSENTIMENTO',
                        'STATUS MARGEM', 'MARGEM', 'VALOR LIBERADO', 'DATA CONSULTA', 'OBSERVAÇÃO',
                        'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'CEP',
                        'TEL_1', 'TEL_2', 'TEL_3', 'TEL_4', 'TEL_5',
                        'TEL_6', 'TEL_7', 'TEL_8', 'TEL_9', 'TEL_10',
                        'EMAIL_1', 'EMAIL_2', 'EMAIL_3'
                    ];
                    fputcsv($fp_csv, $cab, ";");

                    $stmtR   = $pdo->prepare("SELECT * FROM `{$tabela_csv}` WHERE LOTE_ID = ? AND VALOR_MARGEM > 1.00 ORDER BY DATA_SIMULACAO DESC");
                    $stmtREnd  = $pdo->prepare("SELECT logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf = ? ORDER BY id ASC LIMIT 1");
                    $stmtREmail= $pdo->prepare("SELECT email FROM emails WHERE cpf = ? ORDER BY id ASC LIMIT 3");
                    $stmtRTel  = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = ? ORDER BY id ASC LIMIT 10");
                    $stmtR->execute([$id_lote_csv]);
                    while ($rr = $stmtR->fetch(PDO::FETCH_ASSOC)) {
                        $stmtREnd->execute([$rr['CPF']]); $rEnd = $stmtREnd->fetch(PDO::FETCH_ASSOC) ?: [];
                        $stmtREmail->execute([$rr['CPF']]); $rEmails = $stmtREmail->fetchAll(PDO::FETCH_COLUMN);
                        $stmtRTel->execute([$rr['CPF']]); $rTels = $stmtRTel->fetchAll(PDO::FETCH_COLUMN);
                        $lnCSV = [
                            $id_lote_csv, $nomeLoteCSV, $nomeRespCSV,
                            $rr['CPF'], $rr['NOME'] ?? '',
                            $rr['STATUS_WHATSAPP'] ?? '',
                            $rr['DATA_CONSENTIMENTO'] ? date('d/m/Y H:i', strtotime($rr['DATA_CONSENTIMENTO'])) : '',
                            $rr['STATUS_V8'] ?? '',
                            $rr['VALOR_MARGEM'] ? number_format($rr['VALOR_MARGEM'],2,',','.') : '',
                            $rr['VALOR_LIQUIDO'] ? number_format($rr['VALOR_LIQUIDO'],2,',','.') : '',
                            $rr['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($rr['DATA_SIMULACAO'])) : '',
                            $rr['OBSERVACAO'] ?? '',
                            $rEnd['logradouro']??'', $rEnd['numero']??'', $rEnd['bairro']??'',
                            $rEnd['cidade']??'', $rEnd['uf']??'', $rEnd['cep']??'',
                        ];
                        for ($ii=0; $ii<10; $ii++) { $lnCSV[] = $rTels[$ii] ?? ''; }
                        for ($ii=0; $ii<3;  $ii++) { $lnCSV[] = $rEmails[$ii] ?? ''; }
                        fputcsv($fp_csv, $lnCSV, ";");
                    }
                    fclose($fp_csv);

                    $stmtGrupo = $pdo->prepare("SELECT GRUPO_WHATS FROM CLIENTE_CADASTRO WHERE CPF = ?");
                    $stmtGrupo->execute([$cpf_dono_csv]);
                    $grupo_csv = $stmtGrupo->fetchColumn();

                    $stmtWapiCSV = $pdo->query("SELECT i.INSTANCE_ID, i.TOKEN FROM WAPI_CONFIG c JOIN WAPI_INSTANCIAS i ON c.INSTANCE_ID = i.INSTANCE_ID WHERE c.ATIVO_GLOBAL = 1 LIMIT 1");
                    $wapiCSV = $stmtWapiCSV->fetch(PDO::FETCH_ASSOC);

                    if (!empty($grupo_csv) && !empty($wapiCSV['INSTANCE_ID'])) {
                        $phone_csv = preg_replace('/[^0-9\-@a-zA-Z.]/', '', $grupo_csv);
                        if (strpos($phone_csv, '@g.us') === false) $phone_csv .= '@g.us';
                        $txt_csv = "📊 *Relatório Automático Disponível*\n\n"
                                 . "*LOTE:* {$nomeLoteCSV}\n"
                                 . "*DATA/HORA:* " . date('d/m/Y H:i') . "\n\n"
                                 . "🔗 *Clique para baixar:*\n{$urlArqCSV}\n\n"
                                 . "_Link válido por 10 dias. Filtro: margem > R\$1,00._\n\nAssessoria Consignado";
                        $chC = curl_init("https://api.w-api.app/v1/message/send-text?instanceId=" . $wapiCSV['INSTANCE_ID']);
                        curl_setopt_array($chC, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['phone'=>$phone_csv,'message'=>$txt_csv,'delayMessage'=>2]), CURLOPT_HTTPHEADER=>["Authorization: Bearer ".$wapiCSV['TOKEN'],"Content-Type: application/json"]]);
                        curl_exec($chC); curl_close($chC);
                    }
                    $pdo->prepare("UPDATE INTEGRACAO_V8_IMPORTACAO_LOTE SET DATA_ULTIMO_ENVIO_CSV = CURDATE() WHERE ID = ?")->execute([$id_lote_csv]);
                    $disparados++;
                } catch (Exception $e) {}
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