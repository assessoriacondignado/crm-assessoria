<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
session_start();

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($acao !== 'exportar_csv_agrupamento') { header('Content-Type: application/json; charset=utf-8'); }

require_once '../../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

define('URL_FATOR', 'https://fator.confere.link/api/');
define('DIR_JSON', __DIR__ . '/Json_confatorconferi/');
if (!is_dir(DIR_JSON)) { @mkdir(DIR_JSON, 0777, true); }
define('DIR_LOTE', __DIR__ . '/arquivo_importacao_lote/');
if (!is_dir(DIR_LOTE)) { @mkdir(DIR_LOTE, 0777, true); }

$modulo = $_POST['modulo'] ?? 'cpf'; 
$fonte = $_POST['fonte'] ?? 'PAINEL_WEB'; 
$usuario_logado_id = $_SESSION['usuario_id'] ?? 1;
$nome_usuario = $_SESSION['nome_usuario'] ?? 'Admin';
$cpf_logado = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

// =========================================================================
// MOTOR DE PERMISSÃO E RESTRIÇÃO
// =========================================================================
$grupo_usuario = strtoupper($_SESSION['usuario_grupo'] ?? '');

if (empty($grupo_usuario) && !empty($cpf_logado)) {
    try {
        $stmtG = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO WHERE CPF = ?");
        $stmtG->execute([$cpf_logado]);
        $grupo_usuario = strtoupper(trim((string)$stmtG->fetchColumn()));
    } catch (Exception $e) {}
}

$tem_restricao = false;
$restricao_saldo_editar = false;
$restricao_token = false;
$restricao_robo = false;

if (!empty($grupo_usuario) && !in_array($grupo_usuario, ['MASTER', 'ADMIN', 'ADMINISTRADOR'])) {
    try {
        $stmtPerm = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_MEU_USUARIO'");
        $stmtPerm->execute();
        $reg = $stmtPerm->fetch(PDO::FETCH_ASSOC);
        if ($reg && !empty($reg['GRUPO_USUARIOS'])) {
            $grupos_bloqueados = array_map('trim', explode(',', strtoupper($reg['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario, $grupos_bloqueados)) { $tem_restricao = true; }
        }

        $stmtPermEd = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_SALDO_EDITAR'");
        $stmtPermEd->execute();
        $regEd = $stmtPermEd->fetch(PDO::FETCH_ASSOC);
        if ($regEd && !empty($regEd['GRUPO_USUARIOS'])) {
            $grupos_bloqueados_ed = array_map('trim', explode(',', strtoupper($regEd['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario, $grupos_bloqueados_ed)) { $restricao_saldo_editar = true; }
        }
        
        $stmtPermTk = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_TOKEN'");
        $stmtPermTk->execute();
        $regTk = $stmtPermTk->fetch(PDO::FETCH_ASSOC);
        if ($regTk && !empty($regTk['GRUPO_USUARIOS'])) {
            $grupos_bloqueados_tk = array_map('trim', explode(',', strtoupper($regTk['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario, $grupos_bloqueados_tk)) { $restricao_token = true; }
        }

        $stmtPermRb = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_ROBO'");
        $stmtPermRb->execute();
        $regRb = $stmtPermRb->fetch(PDO::FETCH_ASSOC);
        if ($regRb && !empty($regRb['GRUPO_USUARIOS'])) {
            $grupos_bloqueados_rb = array_map('trim', explode(',', strtoupper($regRb['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario, $grupos_bloqueados_rb)) { $restricao_robo = true; }
        }

    } catch (Exception $e) {}
}
// =========================================================================

$token_fator = '';
try {
    $stmtCfg = $pdo->query("SELECT * FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1");
    $config_geral = $stmtCfg->fetch(PDO::FETCH_ASSOC);
    if ($modulo === 'lote') { $token_fator = $config_geral['TOKEN_LOTE'] ?? ''; } 
    elseif ($modulo === 'csv') { $token_fator = $config_geral['TOKEN_CSV'] ?? ''; } 
    elseif (strpos($fonte, 'WHATSAPP') !== false) { $token_fator = $config_geral['TOKEN_FATOR'] ?? ''; } 
    else { $token_fator = $config_geral['TOKEN_MANUAL'] ?? ''; }
} catch (Exception $e) {}

try {
    switch ($acao) {
        
        case 'listar_lotes_csv':
            if ($tem_restricao && !empty($cpf_logado)) {
                $stmt = $pdo->prepare("SELECT *, DATE_FORMAT(DATA_IMPORTACAO, '%d/%m/%y %H:%i') as DATA_BR, NOME_PROCESSAMENTO as AGRUPAMENTO FROM fatorconferi_banco_de_dados_retorno_importacao WHERE CPF_COBRANCA = ? ORDER BY ID DESC LIMIT 100");
                $stmt->execute([$cpf_logado]);
            } else {
                $stmt = $pdo->query("SELECT *, DATE_FORMAT(DATA_IMPORTACAO, '%d/%m/%y %H:%i') as DATA_BR, NOME_PROCESSAMENTO as AGRUPAMENTO FROM fatorconferi_banco_de_dados_retorno_importacao ORDER BY ID DESC LIMIT 100");
            }
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $dados]); exit;

        case 'excluir_lote_csv':
            $id_lote = (int)$_POST['id'];
            if ($tem_restricao) {
                $stmtVal = $pdo->prepare("SELECT CPF_COBRANCA FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?");
                $stmtVal->execute([$id_lote]);
                if ($stmtVal->fetchColumn() !== $cpf_logado) {
                    ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Você não tem permissão para excluir lotes de terceiros.']); exit;
                }
            }
            $pdo->prepare("DELETE FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?")->execute([$id_lote]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Lote e histórico excluídos com sucesso!']); exit;

        case 'exportar_csv_agrupamento':
            $id_lote = (int)($_GET['id_lote'] ?? 0);
            if ($tem_restricao) {
                $stmtVal = $pdo->prepare("SELECT CPF_COBRANCA FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?");
                $stmtVal->execute([$id_lote]);
                if ($stmtVal->fetchColumn() !== $cpf_logado) { exit('Acesso negado.'); }
            }
            $stmtLote = $pdo->prepare("SELECT NOME_PROCESSAMENTO FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?");
            $stmtLote->execute([$id_lote]);
            $nomeLote = $stmtLote->fetchColumn() ?: 'LOTE';

            ob_end_clean();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="Resultado_FatorConferi_'.$nomeLote.'.csv"');
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF"); 
            fputcsv($output, ['CPF', 'NOME', 'SEXO', 'NASCIMENTO', 'NOME_MAE', 'TELEFONES', 'CEP', 'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF', 'STATUS_CONSULTA', 'OBSERVACAO_ERRO'], ";");
            
            $stmt = $pdo->prepare("SELECT i.CPF, i.STATUS_ITEM, i.OBSERVACAO, c.nome, c.sexo, c.nascimento, c.nome_mae, (SELECT GROUP_CONCAT(telefone_cel SEPARATOR ' / ') FROM telefones t WHERE t.cpf = i.CPF) as telefones, e.cep, e.logradouro, e.numero, e.bairro, e.cidade, e.uf FROM fatorconferi_banco_de_dados_retorno_importacao_itens i LEFT JOIN dados_cadastrais c ON c.cpf = i.CPF LEFT JOIN enderecos e ON e.cpf = i.CPF WHERE i.LOTE_ID = ?");
            $stmt->execute([$id_lote]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { 
                fputcsv($output, [
                    $row['CPF'], $row['nome'], $row['sexo'], $row['nascimento'], $row['nome_mae'], $row['telefones'], 
                    $row['cep'], $row['logradouro'], $row['numero'], $row['bairro'], $row['cidade'], $row['uf'],
                    $row['STATUS_ITEM'], $row['OBSERVACAO']
                ], ";"); 
            }
            fclose($output); exit;

        case 'processar_lote_cpfs':
            $agrupamento = strtoupper(trim(preg_replace('/[^a-zA-Z0-9_ \-]/', '_', $_POST['agrupamento'] ?? 'LOTE_PADRAO')));
            $cpf_cobrar = preg_replace('/\D/', '', $_POST['cpf_cobrar'] ?? '');
            $lista_cpfs_raw = $_POST['lista_cpfs'] ?? '';

            if (empty($cpf_cobrar)) { throw new Exception("É obrigatório selecionar um cliente para a cobrança do lote."); }
            if ($tem_restricao && $cpf_cobrar !== $cpf_logado) { throw new Exception("Segurança: Você só pode processar lotes utilizando o saldo da sua própria conta."); }
            if (empty(trim($lista_cpfs_raw))) { throw new Exception("A lista de CPFs está vazia."); }

            $custo_unitario = 0.0;
            $nome_cliente = "Sistema (Isento)";
            if (!empty($cpf_cobrar)) {
                $stmtCli = $pdo->prepare("SELECT NOME, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = ?");
                $stmtCli->execute([$cpf_cobrar]);
                $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
                if ($cli) { 
                    $custo_unitario = floatval($cli['CUSTO_CONSULTA']); 
                    $nome_cliente = $cli['NOME'];
                }
            }

            $linhas = explode("\n", str_replace("\r", "", $lista_cpfs_raw));
            $cpfs_extraidos = [];
            foreach ($linhas as $linha) {
                $cpf_puro = preg_replace('/\D/', '', $linha);
                if (strlen($cpf_puro) > 0 && strlen($cpf_puro) <= 11) { 
                    $cpfs_extraidos[] = str_pad($cpf_puro, 11, '0', STR_PAD_LEFT); 
                }
            }
            
            $cpfs_extraidos = array_unique($cpfs_extraidos);

            $total_linhas = count($cpfs_extraidos);
            if ($total_linhas == 0) { throw new Exception("Nenhum CPF válido localizado na caixa de texto."); }
            if ($total_linhas > 2000) { throw new Exception("Limite: 2.000 CPFs por lote."); }

            $pdo->beginTransaction();
            $stmtLote = $pdo->prepare("INSERT INTO fatorconferi_banco_de_dados_retorno_importacao (NOME_PROCESSAMENTO, USUARIO_ID, NOME_USUARIO, NOME_CLIENTE, CPF_COBRANCA, CUSTO_UNITARIO, QTD_TOTAL, STATUS_FILA) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDENTE')");
            $stmtLote->execute([$agrupamento, $usuario_logado_id, $nome_usuario, $nome_cliente, $cpf_cobrar, $custo_unitario, $total_linhas]);
            $id_lote = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO fatorconferi_banco_de_dados_retorno_importacao_itens (LOTE_ID, CPF, STATUS_ITEM) VALUES (?, ?, 'NA FILA')");
            foreach ($cpfs_extraidos as $cpf_final) {
                $stmtItem->execute([$id_lote, $cpf_final]);
            }
            $pdo->commit();

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_fator_lote.php';
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Processamento em lote iniciado na fila!']); exit;

        case 'reprocessar_erros_lote':
            $id_lote = (int)$_POST['id_lote'];
            if ($tem_restricao) {
                $stmtVal = $pdo->prepare("SELECT CPF_COBRANCA FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?");
                $stmtVal->execute([$id_lote]);
                if ($stmtVal->fetchColumn() !== $cpf_logado) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso negado.']); exit; }
            }

            $pdo->beginTransaction();
            $stmtErro = $pdo->prepare("SELECT COUNT(*) FROM fatorconferi_banco_de_dados_retorno_importacao_itens WHERE LOTE_ID = ? AND STATUS_ITEM = 'ERRO'");
            $stmtErro->execute([$id_lote]);
            $qtdErros = $stmtErro->fetchColumn();

            if ($qtdErros > 0) {
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao_itens SET STATUS_ITEM = 'NA FILA', OBSERVACAO = NULL WHERE LOTE_ID = ? AND STATUS_ITEM = 'ERRO'")->execute([$id_lote]);
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'PENDENTE', QTD_NAO_ATUALIZADO = 0 WHERE ID = ?")->execute([$id_lote]);
                $pdo->commit();

                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_fator_lote.php';
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                
                ob_end_clean(); echo json_encode(['success' => true, 'msg' => "{$qtdErros} CPFs que deram erro foram recolocados na fila!"]); exit;
            } else {
                $pdo->rollBack();
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => "Não há erros para reprocessar neste lote."]); exit;
            }

        case 'forcar_processamento_lote':
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_fator_lote.php';
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "Gatilho de destravamento disparado com sucesso! O Lote deve continuar em instantes."]); exit;

        case 'pausar_retomar_lote':
            $id_lote = (int)$_POST['id_lote'];
            $acao_lote = trim($_POST['acao_lote']);
            if ($tem_restricao) {
                $stmtVal = $pdo->prepare("SELECT CPF_COBRANCA FROM fatorconferi_banco_de_dados_retorno_importacao WHERE ID = ?");
                $stmtVal->execute([$id_lote]);
                if ($stmtVal->fetchColumn() !== $cpf_logado) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso negado.']); exit; }
            }
            if ($acao_lote === 'PAUSAR') {
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'PAUSADO' WHERE ID = ?")->execute([$id_lote]);
                ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Lote pausado. O robô vai parar no próximo CPF.']); exit;
            } else if ($acao_lote === 'RETOMAR') {
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'PENDENTE' WHERE ID = ?")->execute([$id_lote]);
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/worker_fator_lote.php';
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
                ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Lote retomado! O processamento continuará em instantes.']); exit;
            }
            break;

        case 'consulta_cpf_manual':
            $cpf_limpo = preg_replace('/\D/', '', $_POST['cpf'] ?? ''); $cpf = str_pad($cpf_limpo, 11, '0', STR_PAD_LEFT);
            $forcar_api = ($_POST['forcar_api'] === '1'); $cpf_cobrar = $_POST['cpf_cobrar'] ?? ''; $grupo_whats = $_POST['grupo_whats'] ?? '';
            if ($tem_restricao && $cpf_cobrar !== $cpf_logado) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Segurança: Você só pode realizar consultas utilizando o saldo da sua própria conta.']); exit;
            }
            $lock_file = DIR_JSON . 'fc_lock_' . $modulo . '.lock'; $fp = @fopen($lock_file, "w+");
            if ($fp && flock($fp, LOCK_EX)) { consultaCPF($cpf, $pdo, $forcar_api, $token_fator, $fonte, $cpf_cobrar, $grupo_whats); flock($fp, LOCK_UN); } else { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Servidor ocupado.']); }
            if($fp) { fclose($fp); } break;

        case 'listar_clientes':
            if ($tem_restricao && !empty($cpf_logado)) {
                $stmt = $pdo->prepare("SELECT CPF, NOME, CELULAR, CUSTO_CONSULTA, SALDO, GRUPO_WHATS FROM CLIENTE_CADASTRO WHERE CPF = ? ORDER BY NOME ASC");
                $stmt->execute([$cpf_logado]);
                $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $stmtTodos = $pdo->query("SELECT CPF, NOME, CELULAR, CUSTO_CONSULTA, SALDO, GRUPO_WHATS FROM CLIENTE_CADASTRO ORDER BY NOME ASC");
                $clientes = $stmtTodos->fetchAll(PDO::FETCH_ASSOC);
            }
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $clientes]); break;

        case 'carregar_extrato':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            if ($tem_restricao && $cpf !== $cpf_logado) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso negado.']); exit; }
            $stmt = $pdo->prepare("SELECT TIPO, MOTIVO, VALOR, SALDO_ATUAL, DATE_FORMAT(DATA_HORA, '%d/%m/%Y %H:%i') as DATA_FORMATADA FROM fatorconferi_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? ORDER BY DATA_HORA DESC LIMIT 100");
            $stmt->execute([$cpf]); ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;

        case 'salvar_dados_cliente':
            if ($restricao_saldo_editar) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso Negado: Seu grupo não tem permissão para editar dados.']); exit; }
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? ''); $custo = abs(floatval($_POST['custo'] ?? 0)); $grupo = trim($_POST['grupo'] ?? '');
            $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CUSTO_CONSULTA = ?, GRUPO_WHATS = ? WHERE CPF = ?")->execute([$custo, $grupo, $cpf]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Dados atualizados!']); break;

        case 'movimentar_saldo':
            if ($restricao_saldo_editar) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso Negado: Seu grupo não tem permissão para alterar saldos.']); exit; }
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? ''); $tipo = $_POST['tipo'] ?? 'CREDITO'; $valor = abs(floatval($_POST['valor'] ?? 0)); $motivo = $_POST['motivo'] ?? 'Movimentação Manual';
            if ($valor <= 0) { throw new Exception("O valor deve ser maior que zero."); }
            $pdo->beginTransaction(); $stmt = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ? FOR UPDATE"); $stmt->execute([$cpf]); $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cliente) { throw new Exception("Cliente não encontrado."); }
            $saldo_anterior = floatval($cliente['SALDO']); $saldo_atual = ($tipo === 'CREDITO') ? ($saldo_anterior + $valor) : ($saldo_anterior - $valor);
            $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atual, $cpf]);
            $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, GRUPO_WHATS, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, NULL, ?, ?, ?, ?, ?)")->execute([$cpf, $tipo, $motivo, $valor, $saldo_anterior, $saldo_atual]);
            $pdo->commit(); ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Novo saldo: R$ ' . number_format($saldo_atual, 2, ',', '.')]); break;

        case 'salvar_token_aba':
        case 'carregar_tokens_abas':
            if ($restricao_token) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso Negado: Sem permissão para gerenciar Tokens.']); exit; }
            if ($acao === 'salvar_token_aba') {
                $mod = $_POST['mod'] ?? ''; $tk = $_POST['token'] ?? ''; $coluna = '';
                if($mod === 'manual') $coluna = 'TOKEN_MANUAL'; if($mod === 'lote') $coluna = 'TOKEN_LOTE'; if($mod === 'csv') $coluna = 'TOKEN_CSV';
                if($coluna !== '') { $pdo->prepare("UPDATE WAPI_CONFIG_BOT_FATOR SET {$coluna} = ? WHERE ID = 1")->execute([$tk]); ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Chave salva!']); } else { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Módulo inválido.']); }
            } else {
                $stmt = $pdo->query("SELECT TOKEN_MANUAL, TOKEN_LOTE, TOKEN_CSV FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            }
            break;

        case 'salvar_config_robo':
        case 'carregar_config_robo':
            if ($restricao_robo) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Acesso Negado: Sem permissão para gerenciar o Robô.']); exit; }
            if ($acao === 'salvar_config_robo') {
                $dados = [$_POST['menu'] ?? '', $_POST['consulta'] ?? '', $_POST['completo'] ?? '', $_POST['saldo'] ?? '', $_POST['extrato'] ?? '', $_POST['lista'] ?? '', $_POST['suporte'] ?? '', $_POST['wapi_instance'] ?? '', $_POST['wapi_token'] ?? '', $_POST['token_robo'] ?? ''];
                $pdo->prepare("UPDATE WAPI_CONFIG_BOT_FATOR SET CMD_MENU=?, CMD_CONSULTA=?, CMD_COMPLETO=?, CMD_SALDO=?, CMD_EXTRATO=?, CMD_LISTA=?, CMD_SUPORTE=?, WAPI_INSTANCE=?, WAPI_TOKEN=?, TOKEN_FATOR=? WHERE ID=1")->execute($dados);
                ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Configuração salva!']);
            } else {
                $stmt = $pdo->query("SELECT * FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            }
            break;

        default: ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']); break;
    }
} catch (Exception $e) { if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); } ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }

// ===============================================
// FUNÇÕES AUXILIARES DA CONSULTA MANUAL
// ===============================================
function consultaCPF($cpf, $pdo, $forcar_api, $token, $fonte, $cpf_cobrar = '', $grupo_whats = '') {
    if (empty($token)) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Chave API não configurada.']); return; }
    if (strlen($cpf) != 11) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'CPF Inválido.']); return; }
    if (empty($cpf_cobrar)) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'É obrigatório selecionar um cliente para a cobrança da consulta.']); return; }

    $custo = 0; $saldo = 0; $cobrar = false; $is_repetida = false;
    if (!empty($cpf_cobrar)) {
        $stmtCli = $pdo->prepare("SELECT SALDO, CUSTO_CONSULTA FROM CLIENTE_CADASTRO WHERE CPF = ?"); $stmtCli->execute([$cpf_cobrar]); $cli = $stmtCli->fetch(PDO::FETCH_ASSOC);
        if ($cli) {
            $custo = floatval($cli['CUSTO_CONSULTA']); $saldo = floatval($cli['SALDO']); $cobrar = true;
            $stmt24 = $pdo->prepare("SELECT COUNT(*) FROM fatorconferi_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? AND MOTIVO LIKE ? AND DATA_HORA >= (NOW() - INTERVAL 24 HOUR)");
            $stmt24->execute([$cpf_cobrar, "%CPF $cpf%"]);
            if ($stmt24->fetchColumn() > 0) { $is_repetida = true; } else { if ($saldo < $custo && $custo > 0) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Saldo insuficiente. Recarregue para consultar.']); return; } }
        }
    }

    $aplicarCobrancaERetornar = function($origem, $nome, $dados_array) use ($pdo, $cpf, $fonte, $cobrar, $is_repetida, $cpf_cobrar, $grupo_whats, $saldo, $custo) {
        $info_cobranca = null;
        if ($cobrar) {
            if ($is_repetida) {
                $motivo = "REPETIDA 24H (CPF $cpf)";
                $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, GRUPO_WHATS, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, ?, 'CREDITO', ?, 0.00, ?, ?)")->execute([$cpf_cobrar, $grupo_whats ?: null, $motivo, $saldo, $saldo]);
                $info_cobranca = ['is_repetida' => true, 'custo' => 0.00, 'saldo_atual' => $saldo];
            } else {
                $novo_saldo = $saldo - $custo;
                $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$novo_saldo, $cpf_cobrar]);
                $motivo = "Consulta CPF $cpf ($fonte)";
                $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, GRUPO_WHATS, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, ?, 'DEBITO', ?, ?, ?, ?)")->execute([$cpf_cobrar, $grupo_whats ?: null, $motivo, $custo, $saldo, $novo_saldo]);
                $info_cobranca = ['is_repetida' => false, 'custo' => $custo, 'saldo_atual' => $novo_saldo];
            }
        }
        ob_end_clean(); echo json_encode(['success' => true, 'origem' => $origem, 'dados' => ['nome' => $nome], 'json_bruto' => $dados_array, 'cobranca' => $info_cobranca]);
    };

    if (!$forcar_api) {
        $arquivos_cache = glob(DIR_JSON . $cpf . "_*.json");
        if (!empty($arquivos_cache)) {
            $json_salvo = file_get_contents($arquivos_cache[0]); $dados_array = json_decode($json_salvo, true); $cad = (isset($dados_array['CADASTRAIS']) && is_array($dados_array['CADASTRAIS'])) ? $dados_array['CADASTRAIS'] : []; $nome_cache = getXmlString($cad['NOME'] ?? 'Nome Desconhecido');
            
            $custo_real = ($is_repetida || !$cobrar) ? 0.00 : $custo;
            registrarHistorico($pdo, $cpf, $nome_cache, 'CACHE', $custo_real, $fonte); 
            
            inserirDadosOficiais($cpf, $dados_array, $pdo); $aplicarCobrancaERetornar('CACHE', $nome_cache, $dados_array); return;
        }
    }

    $url = URL_FATOR . "?acao=CONS_CPF&TK=" . $token . "&DADO=" . ltrim($cpf, '0');
    $xmlString = fetchAPI($url);
    if (!$xmlString || strpos($xmlString, 'CURL_ERROR:') === 0) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Falha API Fator.']); return; }

    $xmlString = mb_convert_encoding($xmlString, 'UTF-8', 'ISO-8859-1'); $xmlOriginal = $xmlString;
    if(strpos($xmlString, '<') !== false) { $xmlString = substr($xmlString, strpos($xmlString, '<')); } libxml_use_internal_errors(true);
    $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

    if ($xmlObject && isset($xmlObject->CADASTRAIS->NOME)) {
        $array_completo = xmlToArray($xmlObject); $json_final = json_encode($array_completo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : []; $nome = getXmlString($cad['NOME'] ?? '');
        @file_put_contents(DIR_JSON . "{$cpf}_" . strtolower(preg_replace('/[^A-Za-z0-9]/', '', str_replace(' ', '', $nome))) . ".json", $json_final);
        try { 
            inserirDadosOficiais($cpf, $array_completo, $pdo); 
            
            $custo_real = ($is_repetida || !$cobrar) ? 0.00 : $custo;
            registrarHistorico($pdo, $cpf, $nome, 'API', $custo_real, $fonte); 
            
            $aplicarCobrancaERetornar('API', $nome, $array_completo); 
        } catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Erro DB: ' . $e->getMessage()]); }
    } else { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Retorno API: ' . (substr(trim(strip_tags($xmlOriginal)), 0, 150) ?: 'CPF não localizado.')]); }
}

function inserirDadosOficiais($cpf, $array_completo, $pdo, $agrupamento = null) {
    $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : [];
    $nome = getXmlString($cad['NOME'] ?? ''); 
    $nascimento = formataDataBd(getXmlString($cad['NASCTO'] ?? '')); 
    $mae = substr(getXmlString($cad['NOME_MAE'] ?? ''), 0, 150); 
    $sexo = substr(getXmlString($cad['SEXO'] ?? ''), 0, 20); 
    $rg = substr(getXmlString($cad['RG'] ?? ''), 0, 20); 
    $profissao = substr(getXmlString($cad['PROFISSAO'] ?? ''), 0, 50);
    
    $pdo->beginTransaction();
    
    if ($agrupamento) { 
        $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg, carteira_profissional, agrupamento) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), rg=VALUES(rg), carteira_profissional=VALUES(carteira_profissional), agrupamento=VALUES(agrupamento)")->execute([$cpf, $nome, $sexo, $nascimento, $mae, $rg, $profissao, $agrupamento]); 
    } else { 
        $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg, carteira_profissional) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), rg=VALUES(rg), carteira_profissional=VALUES(carteira_profissional)")->execute([$cpf, $nome, $sexo, $nascimento, $mae, $rg, $profissao]); 
    }
    
    $pdo->prepare("DELETE FROM enderecos WHERE cpf = ?")->execute([$cpf]); 
    if(isset($array_completo['ENDERECOS']['ENDERECO'])) { 
        $enderecos = $array_completo['ENDERECOS']['ENDERECO']; 
        if(isset($enderecos['LOGRADOURO']) || isset($enderecos['CIDADE'])) { $enderecos = [$enderecos]; } 
        $stmtEnd = $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, numero, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?, ?)"); 
        foreach($enderecos as $end) { 
            if(is_array($end)) { 
                $stmtEnd->execute([$cpf, substr(getXmlString($end['LOGRADOURO'] ?? ''), 0, 65000), substr(getXmlString($end['NUMERO'] ?? ''), 0, 20), substr(getXmlString($end['BAIRRO'] ?? ''), 0, 100), substr(getXmlString($end['CIDADE'] ?? ''), 0, 100), converterEstadoUF(getXmlString($end['ESTADO'] ?? '')), substr(preg_replace('/\D/', '', getXmlString($end['CEP'] ?? '')), 0, 8)]); 
            } 
        } 
    }
    
    $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)"); 
    if(isset($array_completo['TELEFONES_MOVEL']['TELEFONE'])) { 
        $telefones = $array_completo['TELEFONES_MOVEL']['TELEFONE']; 
        if(isset($telefones['NUMERO'])) { $telefones = [$telefones]; } 
        foreach($telefones as $tel) { 
            if(is_array($tel)) { 
                $numLimpo = preg_replace('/\D/', '', getXmlString($tel['NUMERO'] ?? '')); 
                if(strlen($numLimpo) == 13 && strpos($numLimpo, '55') === 0) { $numLimpo = substr($numLimpo, 2); } 
                if(strlen($numLimpo) == 11) { $stmtTel->execute([$cpf, $numLimpo]); } 
            } 
        } 
    }

    if(isset($array_completo['EMAILS']['EMAIL'])) {
        $lista_emails = $array_completo['EMAILS']['EMAIL'];
        if(is_string($lista_emails)) { $lista_emails = [$lista_emails]; }
        
        $stmtVerificaEmail = $pdo->prepare("SELECT id FROM emails WHERE cpf = ? AND email = ?");
        $stmtInsereEmail = $pdo->prepare("INSERT INTO emails (cpf, email) VALUES (?, ?)");
        
        foreach($lista_emails as $em) {
            $email_str = is_array($em) ? getXmlString($em['EMAIL'] ?? '') : getXmlString($em);
            $email_str = strtolower(trim($email_str));
            
            if(!empty($email_str) && strpos($email_str, '@') !== false) {
                $stmtVerificaEmail->execute([$cpf, $email_str]);
                if($stmtVerificaEmail->rowCount() == 0) {
                    $stmtInsereEmail->execute([$cpf, substr($email_str, 0, 150)]);
                }
            }
        }
    }

    $pdo->commit();
}
// CORREÇÃO AQUI: Recebe o parâmetro $custo e insere corretamente no banco
function registrarHistorico($pdo, $cpf, $nome, $origem, $custo, $fonte) { try { $pdo->prepare("INSERT INTO fatorconferi_banco_de_dados_retorno_historico (cpf, nome_cliente, data_consulta, origem_consulta, custo_consulta, fonte_consulta) VALUES (?, ?, NOW(), ?, ?, ?)")->execute([$cpf, $nome, $origem, $custo, $fonte]); } catch (PDOException $e) {} }
function fetchAPI($url) { $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 30); $response = curl_exec($ch); $err = curl_error($ch); curl_close($ch); return $err ? "CURL_ERROR: " . $err : $response; }
function xmlToArray($xmlObject) { $out = array(); $arrayObj = (array) $xmlObject; if (empty($arrayObj)) return ''; foreach ( $arrayObj as $index => $node ) { $out[$index] = ( is_object ( $node ) || is_array ( $node ) ) ? xmlToArray ( $node ) : $node; } return $out; }
function getXmlString($node) { if (!isset($node) || is_array($node) || is_object($node)) return ''; return trim((string)$node); }
function formataDataBd($dataStr) { if(empty(trim($dataStr))) return null; $p = explode('/', $dataStr); return (count($p) == 3) ? $p[2].'-'.$p[1].'-'.$p[0] : null; }
function converterEstadoUF($estado) { if(is_array($estado)) return 'SP'; $estado = strtoupper(trim(preg_replace('/[^a-zA-Z\s]/', '', $estado))); if (strlen($estado) == 2) return $estado; $mapa = ['ACRE'=>'AC','ALAGOAS'=>'AL','AMAPA'=>'AP','AMAZONAS'=>'AM','BAHIA'=>'BA','CEARA'=>'CE','DISTRITO FEDERAL'=>'DF','ESPIRITO SANTO'=>'ES','GOIAS'=>'GO','MARANHAO'=>'MA','MATO GROSSO'=>'MT','MATO GROSSO DO SUL'=>'MS','MINAS GERAIS'=>'MG','PARA'=>'PA','PARAIBA'=>'PB','PARANA'=>'PR','PERNAMBUCO'=>'PE','PIAUI'=>'PI','RIO DE JANEIRO'=>'RJ','RIO GRANDE DO NORTE'=>'RN','RIO GRANDE DO SUL'=>'RS','RONDONIA'=>'RO','RORAIMA'=>'RR','SANTA CATARINA'=>'SC','SAO PAULO'=>'SP','SERGIPE'=>'SE','TOCANTINS'=>'TO']; return $mapa[$estado] ?? 'SP'; }
?>