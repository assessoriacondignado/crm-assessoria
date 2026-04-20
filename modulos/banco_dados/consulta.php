<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// FORÇA A EXIBIÇÃO DE ERROS PARA DEBUG
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php'; 

// Identificação do Usuário para o Log de Auditoria
$cpf_logado = $_SESSION['usuario_cpf'] ?? '00000000000';
$nome_logado = $_SESSION['usuario_nome'] ?? 'SISTEMA';

// ==========================================
// 🚀 AÇÃO: CADASTRAR NOVO CLIENTE (MANUAL)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_rapida']) && $_POST['acao_rapida'] === 'cadastrar_novo') {
    $cpf_novo = preg_replace('/[^0-9]/', '', $_POST['cpf_novo']);
    $nome_novo = trim(strtoupper($_POST['nome_novo']));
    $telefone_novo = preg_replace('/[^0-9]/', '', $_POST['telefone_novo']);
    
    if (!empty($cpf_novo) && strlen($cpf_novo) === 11) {
        try {
            $pdo->prepare("INSERT IGNORE INTO dados_cadastrais (cpf, nome) VALUES (?, ?)")->execute([$cpf_novo, $nome_novo]);
            
            if (!empty($telefone_novo)) {
                $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)")->execute([$cpf_novo, $telefone_novo]);
            }
            
            header("Location: ?busca={$cpf_novo}&cpf_selecionado={$cpf_novo}&acao=visualizar");
            exit;
        } catch (Exception $e) {}
    }
}

// ==========================================
// 🛡️ MOTOR DE PERMISSÕES
// ==========================================
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

$perm_historico_geral = verificaPermissao($pdo, 'MENU_BANCO_DADOS_HISTORICO_CONSULTA', 'FUNCAO');
$perm_lotes = verificaPermissao($pdo, 'MENU_BANCO_DADOS_LOTES_DE_IMPORTAÇÃO', 'TELA');
$perm_agrup = verificaPermissao($pdo, 'MENU_BANCO_DADOS_AGRUPAMENTOS', 'TELA');
$perm_btn_v8 = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_BOTÃO_V8_CONSIGNADO', 'FUNCAO');
$perm_btn_fator = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_BOTAO_FATOR_CONFERI', 'FUNCAO');
$perm_btn_hist_inss = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_BOTAO_HIST_INSS', 'FUNCAO');
$perm_filtro_av = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FILTRO AVANÇADO', 'FUNCAO');
$perm_filtro_sis = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FILTRO_AVANÇADO_SISTEMA', 'FUNCAO');
$perm_historico_rodape = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_HISTORICO_RODAPE', 'FUNCAO');
$perm_editar = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_ATUALIZAR_CADASTRO', 'FUNCAO');
$perm_cadastrar = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_CADASTRAR_CLIENTE', 'FUNCAO');
$perm_excluir = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_EXCLUIR_CADASTRO', 'FUNCAO');
$perm_ver_agrup = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_CAMPO_AGRUPAMENTO', 'FUNCAO');
$perm_ver_lote = verificaPermissao($pdo, 'MENU_BANCO_DADOS_FICHA_CAMPO_LOTE', 'FUNCAO');

$perm_campanha_tela = verificaPermissao($pdo, 'MENU_CAMPANHA_TELA_CADASTRO', 'FUNCAO');
$perm_campanha_registros = verificaPermissao($pdo, 'MENU_CAMPANHA_MEU_REGISTRO', 'FUNCAO');

// ✨ NOVAS REGRAS APLICADAS ✨
$perm_acao_campanha = verificaPermissao($pdo, 'MENU_BANCO_DADOS_ACAO_CAMPANHA', 'FUNCAO');
$perm_exportacao = verificaPermissao($pdo, 'MENU_BANCO_DADOS_EXPORTAÇÃO', 'FUNCAO');
$perm_hist_geral_hierarquia = verificaPermissao($pdo, 'MENU_BANCO_DADOS_HISTORICO_GERAL_REGISTRO', 'FUNCAO');
$perm_incluir_camp = verificaPermissao($pdo, 'MENU_BANCO_DADOS_INCLUIR_CAMPANHA', 'FUNCAO');
$perm_hist_integ = verificaPermissao($pdo, 'MENU_BANCO_DADOS_HISTORIO_INTEGRACAO_HIERARQUIA', 'FUNCAO');
$perm_hist_consulta = verificaPermissao($pdo, 'MENU_BANCO_DADOS_HISTORIO_CONSULTA_HIERARQUIA', 'FUNCAO');
$perm_reg_campanha = verificaPermissao($pdo, 'MENU_BANCO_DADOS_REGISTRO_CAMPANHA_HIERARQUIA', 'FUNCAO');

$is_master = in_array(strtoupper($_SESSION['usuario_grupo'] ?? ''), ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// ✨ REGRA DE OURO: Captura IDs numéricos da hierarquia do usuário logado ✨
$id_usuario_logado_num = null;
$id_empresa_logado_num = null;
if ($cpf_logado && $cpf_logado !== '00000000000') {
    try {
        $stmtUsuIDs = $pdo->prepare("SELECT ID, id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtUsuIDs->execute([$cpf_logado]);
        $dados_ids = $stmtUsuIDs->fetch(PDO::FETCH_ASSOC);
        if($dados_ids){
            $id_usuario_logado_num = $dados_ids['ID'];
            $id_empresa_logado_num = $dados_ids['id_empresa'];
        }
    } catch (\Throwable $e) {}
}

if (isset($_POST['acao']) && $_POST['acao'] == 'autocomplete_busca') {
    header('Content-Type: application/json');
    $termo = trim($_POST['termo'] ?? '');
    $prefixo = substr($termo, 0, 2);
    $valor = trim(substr($termo, 2));
    $resultados = [];

    if (strlen($valor) >= 3) {
        if ($prefixo === 'n:') {
            $stmt = $pdo->prepare("SELECT cpf, nome FROM dados_cadastrais WHERE nome LIKE ? LIMIT 10");
            $stmt->execute(["%" . $valor . "%"]);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($prefixo === 'c:') {
            $valor_limpo = preg_replace('/\D/', '', $valor);
            $stmt = $pdo->prepare("SELECT cpf, nome FROM dados_cadastrais WHERE cpf LIKE ? LIMIT 10");
            $stmt->execute(["%" . $valor_limpo . "%"]);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($prefixo === 'f:') {
            $valor_limpo = preg_replace('/\D/', '', $valor);
            $stmt = $pdo->prepare("SELECT d.cpf, d.nome, t.telefone_cel FROM dados_cadastrais d INNER JOIN telefones t ON d.cpf = t.cpf WHERE t.telefone_cel LIKE ? GROUP BY d.cpf LIMIT 10");
            $stmt->execute(["%" . $valor_limpo . "%"]);
            $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    echo json_encode(['success' => true, 'data' => $resultados]);
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'salvar_log_rodape') {
    header('Content-Type: application/json');
    $cpf_alvo = preg_replace('/\D/', '', $_POST['cpf_cliente'] ?? '');
    $texto = trim($_POST['texto_registro'] ?? '');
    $margem_recebida = isset($_POST['margem']) ? floatval($_POST['margem']) : 0;
    
    if ($margem_recebida > 0) {
        $coeficiente_24x = 0.048; 
        $liberado_24x = $margem_recebida / $coeficiente_24x;
        $texto .= " | Margem Disponível: R$ " . number_format($margem_recebida, 2, ',', '.') . " | Previsão Liberação (24x): R$ " . number_format($liberado_24x, 2, ',', '.');
    }
    
    if(!empty($cpf_alvo) && !empty($texto)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO, id_usuario, id_empresa) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([str_pad($cpf_alvo, 11, '0', STR_PAD_LEFT), $nome_logado, $texto, $id_usuario_logado_num, $id_empresa_logado_num]);
        } catch (\Throwable $e) {
            $stmt = $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO) VALUES (?, ?, ?)");
            $stmt->execute([str_pad($cpf_alvo, 11, '0', STR_PAD_LEFT), $nome_logado, $texto]);
        }
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'msg' => 'Dados incompletos para log.']);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'listar_historico') {
    header('Content-Type: application/json');
    $dIni = $_POST['data_ini'] ?? date('Y-m-01');
    $dFim = $_POST['data_fim'] ?? date('Y-m-t');
    
    if ($perm_hist_consulta) {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(h.DATA_HORA, '%d/%m/%Y %H:%i') as DATA_BR, h.MODULO_CONSULTA, h.CPF_CLIENTE, d.nome as NOME_CLIENTE FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON h.CPF_CLIENTE = d.cpf WHERE DATE(h.DATA_HORA) BETWEEN ? AND ? ORDER BY h.ID DESC LIMIT 500");
        $stmt->execute([$dIni, $dFim]);
    } else {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(h.DATA_HORA, '%d/%m/%Y %H:%i') as DATA_BR, h.MODULO_CONSULTA, h.CPF_CLIENTE, d.nome as NOME_CLIENTE FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON h.CPF_CLIENTE = d.cpf WHERE h.id_usuario IN (SELECT ID FROM CLIENTE_USUARIO WHERE id_empresa = ?) AND DATE(h.DATA_HORA) BETWEEN ? AND ? ORDER BY h.ID DESC LIMIT 500");
        $stmt->execute([$id_empresa_logado_num, $dIni, $dFim]);
    }
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'exportar_historico') {
    $dIni = $_GET['data_ini'] ?? date('Y-m-01');
    $dFim = $_GET['data_fim'] ?? date('Y-m-t');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Historico_Consultas_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w'); fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['DATA HORA', 'MODULO DE PESQUISA', 'CPF CONSULTADO', 'NOME DO CLIENTE'], ';');
    
    if ($perm_hist_consulta) {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(h.DATA_HORA, '%d/%m/%Y %H:%i') as DATA_BR, h.MODULO_CONSULTA, h.CPF_CLIENTE, d.nome as NOME_CLIENTE FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON h.CPF_CLIENTE = d.cpf WHERE DATE(h.DATA_HORA) BETWEEN ? AND ? ORDER BY h.ID DESC");
        $stmt->execute([$dIni, $dFim]);
    } else {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(h.DATA_HORA, '%d/%m/%Y %H:%i') as DATA_BR, h.MODULO_CONSULTA, h.CPF_CLIENTE, d.nome as NOME_CLIENTE FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON h.CPF_CLIENTE = d.cpf WHERE h.id_usuario IN (SELECT ID FROM CLIENTE_USUARIO WHERE id_empresa = ?) AND DATE(h.DATA_HORA) BETWEEN ? AND ? ORDER BY h.ID DESC");
        $stmt->execute([$id_empresa_logado_num, $dIni, $dFim]);
    }
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($out, [$r['DATA_BR'], $r['MODULO_CONSULTA'], $r['CPF_CLIENTE'], $r['NOME_CLIENTE']], ';'); }
    fclose($out); exit;
}

$termo_busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$cpf_selecionado = isset($_GET['cpf_selecionado']) ? preg_replace('/[^0-9]/', '', $_GET['cpf_selecionado']) : '';
$is_busca_avancada = isset($_GET['busca_avancada']) && $_GET['busca_avancada'] == '1';

$filtros_campo = isset($_GET['campo']) ? $_GET['campo'] : [];
$filtros_operador = isset($_GET['operador']) ? $_GET['operador'] : [];
$filtros_valor = isset($_GET['valor']) ? $_GET['valor'] : [];

$pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limites_por_pagina = 25; 
$offset = ($pagina_atual - 1) * $limites_por_pagina; 
$tem_proxima_pagina = false;

$query_base_export = "";
$params_export = [];

$resultados_busca = [];
$cliente = null;
$historico_campanha = [];
$historico_geral_cliente = [];
$is_modo_campanha = false;
$campanha_atual = null;
$status_campanha = [];
$proximo_cpf_campanha = null;
$telefones = [];
$enderecos = [];
$emails = [];
$convenios = [];
$logs_rodape = []; 
$idade_calculada = 'N/A';
$lista_importacoes_cliente = '';

$inss_beneficios = [];
$inss_contratos = [];

function mascaraCPF($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
}

function formatarDataBusca($dataBruta) {
    $partes = explode('/', trim($dataBruta));
    if (count($partes) == 3) { return $partes[2] . '-' . $partes[1] . '-' . $partes[0]; }
    return trim($dataBruta); 
}

function calcularIdadeExport($nascimento_banco, $idade_banco) {
    if (!empty($nascimento_banco) && $nascimento_banco != '0000-00-00') {
        try {
            $nasc_obj = new DateTime($nascimento_banco);
            $hoje_obj = new DateTime('today');
            $diff = $nasc_obj->diff($hoje_obj);
            $anos = $diff->y; $meses = $diff->m; $dias = $diff->d;
            $partes = [];
            if ($anos > 0) $partes[] = $anos . ($anos == 1 ? " ano" : " anos");
            if ($meses > 0) $partes[] = $meses . ($meses == 1 ? " mês" : " meses");
            if ($dias > 0) $partes[] = $dias . ($dias == 1 ? " dia" : " dias");
            if (count($partes) == 3) return "{$partes[0]}, {$partes[1]} e {$partes[2]}";
            if (count($partes) == 2) return "{$partes[0]} e {$partes[1]}";
            if (count($partes) == 1) return "{$partes[0]}";
            return "0 dias";
        } catch (Exception $e) {}
    }
    return $idade_banco;
}

function mapearColunaBanco($campo) {
    $mapa = [
        'nome' => 'd.nome', 'cpf' => 'd.cpf', 'sexo' => 'd.sexo', 'nascimento' => 'd.nascimento',
        'idade' => 'TIMESTAMPDIFF(YEAR, d.nascimento, CURDATE())', 'nome_mae' => 'd.nome_mae',
        'nome_pai' => 'd.nome_pai', 'rg' => 'd.rg', 'cnh' => 'd.cnh',
        'carteira_profissional' => 'd.carteira_profissional', 'agrupamento' => 'd.agrupamento',
        'importacao' => 'hi.nome_importacao', 'telefone_cel' => 't.telefone_cel',
        'ddd' => 'LEFT(t.telefone_cel, 2)', 'email' => 'em.email', 'cidade' => 'e.cidade',
        'uf' => 'e.uf', 'bairro' => 'e.bairro', 'cep' => 'e.cep', 'matricula' => 'c.MATRICULA', 'convenio' => 'c.CONVENIO',
        'inss_matricula' => 'inss_ctr.matricula_nb', 'inss_contrato' => 'inss_ctr.contrato', 'inss_banco' => 'inss_ctr.banco',
        'inss_tipo_emprestimo' => 'inss_ctr.tipo_emprestimo', 'inss_situacao' => 'inss_ctr.situacao',
        'inss_especie_ben' => 'inss_ben.especie_beneficio', 'inss_situacao_ben' => 'inss_ben.situacao_beneficio',
        'inss_banco_pag' => 'inss_ben.banco_pagamento', 'inss_margem' => 'inss_ben.margem_calculada'
    ];
    return isset($mapa[$campo]) ? $mapa[$campo] : 'd.nome';
}

$lotes_importacao = [];
$lista_agrupamentos = [];
$lista_campanhas_ativas = [];
$campanhas_para_inclusao = [];

try {
    if ($perm_lotes) {
        $stmtLote = $pdo->query("SELECT id, nome_importacao, data_importacao, qtd_novos, qtd_atualizados, qtd_erros FROM base_de_dados_registro_importacao ORDER BY id DESC LIMIT 100");
        $lotes_importacao = $stmtLote->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($perm_agrup) {
        $stmtAgr = $pdo->query("SELECT agrupamento, COUNT(cpf) as qtd FROM dados_cadastrais WHERE agrupamento IS NOT NULL AND trim(agrupamento) != '' GROUP BY agrupamento ORDER BY agrupamento ASC LIMIT 100");
        $lista_agrupamentos = $stmtAgr->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $sqlCampA = "SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO'";
    $paramsCampA = [];
    if (!$is_master) {
        $sqlCampA .= " AND (id_empresa = ? OR CNPJ_EMPRESA IS NULL OR CNPJ_EMPRESA = '')";
        $paramsCampA[] = $id_empresa_logado_num;
    }
    $sqlCampA .= " ORDER BY NOME_CAMPANHA ASC";
    
    $stmtCampA = $pdo->prepare($sqlCampA);
    $stmtCampA->execute($paramsCampA);
    $lista_campanhas_ativas = $stmtCampA->fetchAll(PDO::FETCH_ASSOC);

    if ($perm_campanha_tela) {
        $sqlCampInc = "SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO'";
        $paramsCampInc = [];
        if (!$is_master) {
            $sqlCampInc .= " AND (id_empresa = ? OR CNPJ_EMPRESA IS NULL OR CNPJ_EMPRESA = '')";
            $paramsCampInc[] = $id_empresa_logado_num;
        }
        $sqlCampInc .= " ORDER BY NOME_CAMPANHA ASC";
        $stmtCampInc = $pdo->prepare($sqlCampInc);
        $stmtCampInc->execute($paramsCampInc);
        $campanhas_para_inclusao = $stmtCampInc->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Status para "NOVO REGISTRO" na ficha do cliente (TIPO_CONTATO = 'FICHA_REGISTRO' ou legado)
$status_gerais_avulsos = [];
try {
    $cnpj_emp_logado_fich = null;
    if ($id_empresa_logado_num) {
        $s = $pdo->prepare("SELECT CNPJ FROM CLIENTE_EMPRESAS WHERE ID = ? LIMIT 1");
        $s->execute([$id_empresa_logado_num]);
        $cnpj_emp_logado_fich = $s->fetchColumn() ?: null;
    }
    // Mostra status FICHA_REGISTRO e legados (tipo != CAMPANHA) filtrados por empresa
    $sqlAv = "SELECT ID, NOME_STATUS, MARCACAO FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO
              WHERE COALESCE(TIPO_CONTATO,'') != 'CAMPANHA'";
    $paramsAv = [];
    if (!$is_master) {
        $sqlAv .= " AND (CNPJ_EMPRESA IS NULL OR CNPJ_EMPRESA = '' OR FIND_IN_SET(?, CNPJ_EMPRESA))";
        $paramsAv[] = $cnpj_emp_logado_fich;
    }
    $sqlAv .= " ORDER BY NOME_STATUS ASC";
    $stmtAv = $pdo->prepare($sqlAv);
    $stmtAv->execute($paramsAv);
    $status_gerais_avulsos = $stmtAv->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $status_gerais_avulsos = []; }

if (!$is_busca_avancada && !empty($termo_busca) && empty($cpf_selecionado)) {
    $prefixo = substr($termo_busca, 0, 2);
    $is_prefixo = in_array($prefixo, ['n:', 'c:', 'f:']);
    $valor_busca = $is_prefixo ? trim(substr($termo_busca, 2)) : $termo_busca;

    $query_sql = ""; $params = [];

    if ($prefixo === 'n:' && !empty($valor_busca)) {
        $query_sql = "SELECT cpf, nome, 'Busca Rápida' as origem FROM dados_cadastrais WHERE nome LIKE :termo LIMIT :limit OFFSET :offset";
        $params[':termo'] = $valor_busca . "%"; 
        $query_base_export = " FROM dados_cadastrais d WHERE d.nome LIKE :termo "; $params_export = ['termo' => $valor_busca . "%"];
    } elseif ($prefixo === 'f:' && !empty($valor_busca)) {
        $valor_limpo = preg_replace('/\D/', '', $valor_busca);
        if (!empty($valor_limpo)) {
            $query_sql = "SELECT d.cpf, d.nome, 'Busca Rápida' as origem FROM dados_cadastrais d INNER JOIN telefones t ON d.cpf = t.cpf WHERE t.telefone_cel LIKE :termo GROUP BY d.cpf LIMIT :limit OFFSET :offset";
            $params[':termo'] = $valor_limpo . "%"; 
            $query_base_export = " FROM dados_cadastrais d INNER JOIN telefones t ON d.cpf = t.cpf WHERE t.telefone_cel LIKE :termo "; $params_export = ['termo' => $valor_limpo . "%"];
        }
    } else {
        $termo_limpo = preg_replace('/\D/', '', $valor_busca);
        if (!empty($termo_limpo)) {
            $cpf_formatado = str_pad($termo_limpo, 11, '0', STR_PAD_LEFT);
            $query_sql = "SELECT cpf, nome, 'Busca Rápida' as origem FROM dados_cadastrais WHERE cpf = :cpf LIMIT :limit OFFSET :offset";
            $params[':cpf'] = $cpf_formatado;
            $query_base_export = " FROM dados_cadastrais d WHERE d.cpf = :cpf "; $params_export = ['cpf' => $cpf_formatado];
        }
    }

    if (!empty($query_sql)) {
        try {
            $stmt = $pdo->prepare($query_sql);
            foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
            $stmt->bindValue(':limit', $limites_por_pagina + 1, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $resultados_busca = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($resultados_busca) > $limites_por_pagina) {
                $tem_proxima_pagina = true;
                array_pop($resultados_busca);
            }
        } catch (Exception $e) { $erro_sql = $e->getMessage(); }
    }
}

if ($is_busca_avancada && empty($cpf_selecionado)) {
    $filtro_sql = ""; $params = []; $contador_params = 1;
    
    $precisa_telefone = false; $precisa_endereco = false; $precisa_email = false;
    $precisa_importacao = false; $precisa_convenio = false; 
    $precisa_inss_ben = false; $precisa_inss_ctr = false;

    for ($i = 0; $i < count($filtros_campo); $i++) {
        $campo_html = $filtros_campo[$i]; $operador = $filtros_operador[$i]; $valor_bruto = trim($filtros_valor[$i]);
        if ($valor_bruto === '' && $operador != 'vazio') continue;

        // IMPORTACAO: subquery IN — evita JOIN com 3.5M+ linhas; usa FULLTEXT para "contem"
        if ($campo_html === 'importacao') {
            $valores_imp = explode(';', $valor_bruto);
            $sub_conds = [];
            foreach ($valores_imp as $val_imp) {
                $val_imp = trim($val_imp); if ($val_imp === '') continue;
                $pn = 'p' . $contador_params++;
                if ($operador == 'igual')      { $sub_conds[] = "nome_importacao = :$pn";            $params[$pn] = $val_imp; }
                elseif ($operador == 'comeca') { $sub_conds[] = "nome_importacao LIKE :$pn";          $params[$pn] = "$val_imp%"; }
                elseif ($operador == 'nao_contem') { $sub_conds[] = "nome_importacao NOT LIKE :$pn";  $params[$pn] = "%$val_imp%"; }
                elseif ($operador == 'vazio')  { $sub_conds[] = "(nome_importacao IS NULL OR nome_importacao = '')"; }
                else { // contem — FULLTEXT BOOLEAN MODE (usa índice, muito mais rápido que LIKE '%x%')
                    $palavras_ft = array_filter(preg_split('/\s+/', trim($val_imp)));
                    $ft_term_imp = count($palavras_ft) ? ('+' . implode('* +', $palavras_ft) . '*') : $val_imp;
                    $sub_conds[] = "MATCH(nome_importacao) AGAINST(:$pn IN BOOLEAN MODE)";
                    $params[$pn] = $ft_term_imp;
                }
            }
            if (!empty($sub_conds)) {
                $glue = ($operador == 'nao_contem') ? ' AND ' : ' OR ';
                $filtro_sql .= " AND d.cpf IN (SELECT cpf FROM BANCO_DE_DADOS_HISTORICO_IMPORTACAO WHERE " . implode($glue, $sub_conds) . ") ";
            }
            continue;
        }

        $coluna_db = mapearColunaBanco($campo_html);

        if (strpos($coluna_db, 't.') !== false || $coluna_db == 'LEFT(t.telefone_cel, 2)') $precisa_telefone = true;
        if (strpos($coluna_db, 'e.') !== false) $precisa_endereco = true;
        if (strpos($coluna_db, 'em.') !== false) $precisa_email = true;
        if (strpos($coluna_db, 'c.') !== false) $precisa_convenio = true;
        if (strpos($coluna_db, 'inss_ben.') !== false) $precisa_inss_ben = true;
        if (strpos($coluna_db, 'inss_ctr.') !== false) $precisa_inss_ctr = true;

        if ($operador == 'vazio') { $filtro_sql .= " AND ($coluna_db IS NULL OR trim($coluna_db) = '') "; continue; }

        $valores_array = explode(';', $valor_bruto); $condicoes_or = [];
        foreach ($valores_array as $val) {
            $val = trim($val); if ($val === '') continue;
            if ($campo_html == 'cpf') { $val = preg_replace('/[^0-9]/', '', $val); if (!empty($val)) $val = str_pad($val, 11, '0', STR_PAD_LEFT); }
            $param_nome = 'p' . $contador_params++; 
            if ($campo_html == 'nascimento') {
                if ($operador == 'entre') {
                    $partes_data = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                    if (count($partes_data) == 2) { $val_inicio = formatarDataBusca($partes_data[0]); $val_fim = formatarDataBusca($partes_data[1]); }
                } else { $val = formatarDataBusca($val); }
            }

            if ($operador == 'contem') {
                if ($coluna_db === 'd.nome') {
                    // FULLTEXT BOOLEAN MODE — evita full table scan de LIKE '%texto%' em 1.3M+ registros
                    $palavras = array_filter(preg_split('/\s+/', trim($val)));
                    $ft_term  = count($palavras) ? ('+' . implode('* +', $palavras) . '*') : $val;
                    $condicoes_or[] = "MATCH(d.nome) AGAINST(:$param_nome IN BOOLEAN MODE)";
                    $params[$param_nome] = $ft_term;
                } else {
                    $val_smart = preg_replace('/[\s\-]+/', '%', $val);
                    $condicoes_or[] = "$coluna_db LIKE :$param_nome";
                    $params[$param_nome] = "%$val_smart%";
                }
            } elseif ($operador == 'nao_contem') { $val_smart = preg_replace('/[\s\-]+/', '%', $val); $condicoes_or[] = "$coluna_db NOT LIKE :$param_nome"; $params[$param_nome] = "%$val_smart%"; }
            elseif ($operador == 'comeca') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params[$param_nome] = "$val%"; } 
            elseif ($operador == 'igual') { $condicoes_or[] = "TRIM($coluna_db) = :$param_nome"; $params[$param_nome] = $val; } 
            elseif ($operador == 'maior') { $condicoes_or[] = "$coluna_db > :$param_nome"; $params[$param_nome] = $val; } 
            elseif ($operador == 'menor') { $condicoes_or[] = "$coluna_db < :$param_nome"; $params[$param_nome] = $val; } 
            elseif ($operador == 'entre') {
                if ($campo_html == 'nascimento' && isset($val_inicio) && isset($val_fim)) {
                    $p_nome2 = 'p' . $contador_params++; $condicoes_or[] = "$coluna_db BETWEEN :$param_nome AND :$p_nome2"; $params[$param_nome] = $val_inicio; $params[$p_nome2] = $val_fim;
                } else {
                    $partes_entre = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                    if(count($partes_entre) == 2) { $p_nome2 = 'p' . $contador_params++; $condicoes_or[] = "$coluna_db BETWEEN :$param_nome AND :$p_nome2"; $params[$param_nome] = trim($partes_entre[0]); $params[$p_nome2] = trim($partes_entre[1]); } 
                    else { $condicoes_or[] = "TRIM($coluna_db) = :$param_nome"; $params[$param_nome] = $val; }
                }
            }
        }
        if (!empty($condicoes_or)) { if ($operador == 'nao_contem') { $filtro_sql .= " AND (" . implode(" AND ", $condicoes_or) . ") "; } else { $filtro_sql .= " AND (" . implode(" OR ", $condicoes_or) . ") "; } }
    }

    try {
        $is_exato = isset($_GET['filtro_exato']) && $_GET['filtro_exato'] == '1';
        $join_type = $is_exato ? 'INNER JOIN' : 'LEFT JOIN';

        $from_joins = " FROM dados_cadastrais d ";
        if ($precisa_telefone) $from_joins .= " $join_type telefones t ON d.cpf = t.cpf ";
        if ($precisa_endereco) $from_joins .= " $join_type enderecos e ON d.cpf = e.cpf ";
        if ($precisa_email) $from_joins .= " $join_type emails em ON d.cpf = em.cpf ";
        if ($precisa_importacao) $from_joins .= " $join_type BANCO_DE_DADOS_HISTORICO_IMPORTACAO hi ON d.cpf = hi.cpf ";
        if ($precisa_convenio) $from_joins .= " $join_type BANCO_DADOS_CONVENIO c ON d.cpf = c.CPF ";
        if ($precisa_inss_ben) $from_joins .= " $join_type banco_de_Dados_inss_dados_cadastrais inss_ben ON d.cpf = inss_ben.cpf ";
        if ($precisa_inss_ctr) $from_joins .= " $join_type banco_de_Dados_inss_contratos inss_ctr ON d.cpf = inss_ctr.cpf ";

        $from_joins .= " WHERE 1=1 " . $filtro_sql;
                        
        $limite_busca = $limites_por_pagina + 1;
        $query_avancada = "SELECT d.cpf, d.nome, 'Avançada' as origem " . $from_joins . " GROUP BY d.cpf, d.nome LIMIT " . (int)$limite_busca . " OFFSET " . (int)$offset;
        
        $stmt_av = $pdo->prepare($query_avancada); $stmt_av->execute($params); 
        $resultados_busca = $stmt_av->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($resultados_busca) > $limites_por_pagina) {
            $tem_proxima_pagina = true;
            array_pop($resultados_busca);
        }
        
        $query_base_export = $from_joins; $params_export = $params; $termo_busca = "Busca Avançada Ativada"; 
    } catch (Exception $e) { $erro_sql = $e->getMessage(); }
}

if (isset($_GET['acao_lote_campanha']) && $_GET['acao_lote_campanha'] == '1' && !empty($query_base_export)) {
    ini_set('max_execution_time', 0);
    $id_camp = (int)$_GET['id_campanha_lote'];
    $tipo_acao = $_GET['tipo_acao_lote']; 
    
    $afetados = 0;

    if ($tipo_acao == 'incluir') {
        $sql_in = "INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) SELECT d.cpf, :id_camp " . $query_base_export . " GROUP BY d.cpf";
        $stmtIn = $pdo->prepare($sql_in);
        $p = $params_export; $p['id_camp'] = $id_camp;
        $stmtIn->execute($p);
        $afetados = $stmtIn->rowCount();
    } elseif ($tipo_acao == 'excluir') {
        $sql_cpfs = "SELECT d.cpf " . $query_base_export . " GROUP BY d.cpf";
        $stmt_cpfs = $pdo->prepare($sql_cpfs);
        $stmt_cpfs->execute($params_export);
        $cpfs = $stmt_cpfs->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($cpfs)) {
            $chunks = array_chunk($cpfs, 1000);
            foreach ($chunks as $chunk) {
                $inQuery = implode(',', array_fill(0, count($chunk), '?'));
                $stmtDel = $pdo->prepare("DELETE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ? AND CPF_CLIENTE IN ($inQuery)");
                $paramsDel = array_merge([$id_camp], $chunk);
                $stmtDel->execute($paramsDel);
                $afetados += $stmtDel->rowCount();
            }
        }
    }
    
    $params_redirect = $_GET;
    unset($params_redirect['acao_lote_campanha'], $params_redirect['id_campanha_lote'], $params_redirect['tipo_acao_lote']);
    $params_redirect['msg_lote'] = $tipo_acao;
    $params_redirect['qtd_lote'] = $afetados;
    
    header("Location: ?" . http_build_query($params_redirect));
    exit;
}

if (isset($_GET['exportar']) && $_GET['exportar'] == '1' && !empty($query_base_export)) {
    ini_set('max_execution_time', 0); ini_set('memory_limit', '-1');
    $modelo_escolhido = isset($_GET['modelo_exportacao']) ? $_GET['modelo_exportacao'] : 'dados_cadastrais';
    $nome_arquivo = "exportacao_busca_" . $modelo_escolhido . "_" . date('Y-m-d_His') . ".csv";
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    $output = fopen('php://output', 'w'); fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));

    // Injeta LEFT JOIN de enderecos (derived table com alias _end) antes do WHERE,
    // evitando 6 subqueries correlacionadas e conflito com alias 'e' da busca avançada.
    $join_end = " LEFT JOIN (SELECT cpf, MIN(cep) cep, MIN(logradouro) logradouro, MIN(numero) numero, MIN(bairro) bairro, MIN(cidade) cidade, MIN(uf) uf FROM enderecos GROUP BY cpf) _end ON _end.cpf = d.cpf ";
    $query_base_com_end = preg_replace('/\bWHERE\b/i', $join_end . ' WHERE ', $query_base_export, 1);

    $sql_export = "SELECT d.cpf, d.nome, d.sexo, d.nascimento, d.idade, d.nome_mae, d.nome_pai, d.rg, d.cnh, d.carteira_profissional, d.agrupamento, _end.cep, _end.logradouro, _end.numero, _end.bairro, _end.cidade, _end.uf, (SELECT GROUP_CONCAT(telefone_cel SEPARATOR ',') FROM telefones t2 WHERE t2.cpf = d.cpf) as telefones_agrupados, (SELECT GROUP_CONCAT(email SEPARATOR ',') FROM emails em2 WHERE em2.cpf = d.cpf) as emails_agrupados " . $query_base_com_end . " GROUP BY d.cpf";
    $stmt_export = $pdo->prepare($sql_export); $stmt_export->execute($params_export);

    if ($modelo_escolhido == 'dados_cadastrais') {
        $cabecalhos = ['CPF', 'NOME COMPLETO', 'SEXO', 'NASCIMENTO', 'IDADE', 'NOME DA MAE', 'NOME DO PAI', 'RG', 'CNH', 'CARTEIRA PROFISSIONAL', 'AGRUPAMENTO', 'CEP', 'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF'];
        for ($i = 1; $i <= 15; $i++) { $cabecalhos[] = "TELEFONE " . $i; } for ($i = 1; $i <= 5; $i++) { $cabecalhos[] = "EMAIL " . $i; }
        fputcsv($output, $cabecalhos, ';');
        while ($row = $stmt_export->fetch(PDO::FETCH_ASSOC)) {
            $linha_csv = [ mascaraCPF($row['cpf']), $row['nome'], $row['sexo'], !empty($row['nascimento']) ? date('d/m/Y', strtotime($row['nascimento'])) : '', calcularIdadeExport($row['nascimento'], $row['idade']), $row['nome_mae'], $row['nome_pai'], $row['rg'], $row['cnh'], $row['carteira_profissional'], $row['agrupamento'], $row['cep'], $row['logradouro'], $row['numero'], $row['bairro'], $row['cidade'], $row['uf'] ];
            $tels = !empty($row['telefones_agrupados']) ? explode(',', $row['telefones_agrupados']) : []; for ($i = 0; $i < 15; $i++) { $linha_csv[] = isset($tels[$i]) ? trim($tels[$i]) : ''; }
            $em = !empty($row['emails_agrupados']) ? explode(',', $row['emails_agrupados']) : []; for ($i = 0; $i < 5; $i++) { $linha_csv[] = isset($em[$i]) ? trim($em[$i]) : ''; }
            fputcsv($output, $linha_csv, ';');
        }
    } 
    elseif ($modelo_escolhido == 'telefones_foco') {
        fputcsv($output, ['TELEFONE CELULAR', 'CPF', 'NOME COMPLETO', 'NASCIMENTO', 'IDADE'], ';');
        while ($row = $stmt_export->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['telefones_agrupados'])) continue;
            $tels = explode(',', $row['telefones_agrupados']);
            foreach ($tels as $telefone) { if(!empty(trim($telefone))) fputcsv($output, [ trim($telefone), mascaraCPF($row['cpf']), $row['nome'], !empty($row['nascimento']) ? date('d/m/Y', strtotime($row['nascimento'])) : '', calcularIdadeExport($row['nascimento'], $row['idade']) ], ';'); }
        }
    }
    fclose($output); exit; 
}
?>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

// ✨ BLINDAGEM MÁXIMA PARA A ABERTURA DA FICHA E EDIÇÃO ✨
if (!empty($cpf_selecionado) && in_array($acao, ['visualizar', 'editar'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM dados_cadastrais WHERE cpf = :cpf");
        $stmt->execute(['cpf' => $cpf_selecionado]);
        $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente) {
            $modulo_log = ($acao == 'editar') ? 'WEB EDICAO' : 'WEB FICHA COMPLETA';
            
            try {
                $id_cliente_num = null;
                $stmtIdCli = $pdo->prepare("SELECT ID FROM CLIENTE_CADASTRO WHERE CPF = ? LIMIT 1");
                $stmtIdCli->execute([$cpf_selecionado]);
                $id_cliente_num = $stmtIdCli->fetchColumn() ?: null;

                $pdo->prepare("INSERT INTO BANCO_DE_DADOS_REGISTRO_CONSULTA 
                    (CPF_USUARIO, NOME_USUARIO, CPF_CLIENTE, MODULO_CONSULTA, id_usuario, id_cliente) 
                    VALUES (?, ?, ?, ?, ?, ?)")->execute([$cpf_logado, $nome_logado, $cpf_selecionado, $modulo_log, $id_usuario_logado_num, $id_cliente_num]);
            } catch (\Throwable $e) {}

            $idade_calculada = calcularIdadeExport($cliente['nascimento'] ?? '', 'N/A');

            // Busca dados complementares — colunas específicas para reduzir transferência MySQL→PHP
            $stmtTel = $pdo->prepare("SELECT telefone_cel FROM telefones WHERE cpf = :cpf ORDER BY id ASC");
            $stmtTel->execute(['cpf' => $cpf_selecionado]);
            $telefones = $stmtTel->fetchAll(PDO::FETCH_ASSOC);

            $stmtEnd = $pdo->prepare("SELECT cep, logradouro, numero, bairro, cidade, uf FROM enderecos WHERE cpf = :cpf ORDER BY id ASC");
            $stmtEnd->execute(['cpf' => $cpf_selecionado]);
            $enderecos = $stmtEnd->fetchAll(PDO::FETCH_ASSOC);

            $stmtEmail = $pdo->prepare("SELECT email FROM emails WHERE cpf = :cpf ORDER BY id ASC");
            $stmtEmail->execute(['cpf' => $cpf_selecionado]);
            $emails = $stmtEmail->fetchAll(PDO::FETCH_ASSOC);

            $stmtConv = $pdo->prepare("SELECT CONVENIO, MATRICULA FROM BANCO_DADOS_CONVENIO WHERE CPF = :cpf ORDER BY ID ASC");
            $stmtConv->execute(['cpf' => $cpf_selecionado]);
            $convenios = $stmtConv->fetchAll(PDO::FETCH_ASSOC);

            $stmtHist = $pdo->prepare("SELECT nome_importacao FROM BANCO_DE_DADOS_HISTORICO_IMPORTACAO WHERE cpf = :cpf");
            $stmtHist->execute(['cpf' => $cpf_selecionado]);
            $historicos = $stmtHist->fetchAll(PDO::FETCH_COLUMN);
            $lista_importacoes_cliente = count($historicos) > 0 ? implode("; ", $historicos) : 'Cadastro Manual';
            
            // ✨ CORREÇÃO DOS LOGS DO RODAPÉ (ESCONDE LOGS DE API) ✨
            $sqlLogs = "SELECT DATA_REGISTRO, NOME_USUARIO, TEXTO_REGISTRO FROM dados_cadastrais_log_rodape WHERE CPF_CLIENTE = ?";
            // Adicionado filtro para NÃO mostrar logs automáticos de API
            $sqlLogs .= " AND TEXTO_REGISTRO NOT LIKE 'Consulta de Beneficio%' AND TEXTO_REGISTRO NOT LIKE '%executada via%'";
            
            $paramsLogs = [$cpf_selecionado];
            
            try {
                if (!$perm_hist_integ) {
                    $sqlLogs .= " AND id_empresa = ?";
                    $paramsLogs[] = $id_empresa_logado_num;
                }
                if (!$perm_historico_rodape) { 
                    $sqlLogs .= " AND NOME_USUARIO = ?"; 
                    $paramsLogs[] = $nome_logado; 
                }
                $sqlLogs .= " ORDER BY ID DESC LIMIT 50";
                $stmtLogs = $pdo->prepare($sqlLogs);
                $stmtLogs->execute($paramsLogs);
                $logs_rodape = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { $logs_rodape = []; }

            $is_modo_campanha = false;
            $campanha_atual = null;
            $status_campanha = [];
            $historico_campanha = [];
            $proximo_cpf_campanha = null;
            $historico_geral_cliente = [];

            try {
                $sqlHistGeral = "
                    SELECT r.*, s.NOME_STATUS, c.NOME_CAMPANHA 
                    FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r 
                    JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON r.ID_STATUS_CONTATO = s.ID 
                    LEFT JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c ON s.ID_CAMPANHA = c.ID
                    WHERE r.CPF_CLIENTE = ?
                ";
                $paramsHistGeral = [$cpf_selecionado];
                if (!$perm_hist_geral_hierarquia) {
                    $sqlHistGeral .= " AND r.id_empresa = ?";
                    $paramsHistGeral[] = $id_empresa_logado_num;
                }
                if (!$perm_campanha_registros) {
                    $sqlHistGeral .= " AND r.CPF_USUARIO = ?";
                    $paramsHistGeral[] = $cpf_logado;
                }
                $sqlHistGeral .= " ORDER BY r.DATA_REGISTRO DESC";
                $stmtHistGeral = $pdo->prepare($sqlHistGeral);
                $stmtHistGeral->execute($paramsHistGeral);
                $historico_geral_cliente = $stmtHistGeral->fetchAll(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) { $historico_geral_cliente = []; }

            if (isset($_GET['id_campanha']) && !empty($_GET['id_campanha']) && $perm_campanha_tela) {
                $id_camp = (int)$_GET['id_campanha'];

                $stmtCamp = $pdo->prepare("SELECT * FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE ID = ? AND STATUS = 'ATIVO'");
                $stmtCamp->execute([$id_camp]);
                $campanha_atual = $stmtCamp->fetch(PDO::FETCH_ASSOC);

                if ($campanha_atual) {
                    $is_modo_campanha = true;

                    if (!empty($campanha_atual['IDS_STATUS_CONTATOS'])) {
                        $ids_in_safe = implode(',', array_map('intval', explode(',', $campanha_atual['IDS_STATUS_CONTATOS'])));
                        if (!empty($ids_in_safe)) {
                            $stmtStat = $pdo->query("SELECT ID, NOME_STATUS, MARCACAO FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO WHERE ID IN ($ids_in_safe) ORDER BY NOME_STATUS ASC");
                            $status_campanha = $stmtStat->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }

                    try {
                        $sqlHistCamp = "
                            SELECT r.*, s.NOME_STATUS 
                            FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r 
                            JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON r.ID_STATUS_CONTATO = s.ID 
                            WHERE r.CPF_CLIENTE = ? AND (s.ID_CAMPANHA = ? OR s.ID_CAMPANHA IS NULL)
                        ";
                        $paramsHist = [$cpf_selecionado, $id_camp];

                        if (!$perm_reg_campanha) {
                            $sqlHistCamp .= " AND r.id_empresa = ?";
                            $paramsHist[] = $id_empresa_logado_num;
                        }
                        if (!$perm_campanha_registros) {
                            $sqlHistCamp .= " AND r.CPF_USUARIO = ?";
                            $paramsHist[] = $cpf_logado;
                        }

                        $sqlHistCamp .= " ORDER BY r.DATA_REGISTRO DESC";
                        $stmtHistCamp = $pdo->prepare($sqlHistCamp);
                        $stmtHistCamp->execute($paramsHist);
                        $historico_campanha = $stmtHistCamp->fetchAll(PDO::FETCH_ASSOC);
                    } catch (\Throwable $e) { $historico_campanha = []; }

                    $sqlProx = "SELECT c.CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c WHERE c.ID_CAMPANHA = :id_camp AND c.CPF_CLIENTE != :cpf_atual";
                    if ($campanha_atual['PARAMETRO_INICIO_ALEATORIO'] == 'SIM') {
                        $sqlProx .= " ORDER BY RAND() LIMIT 1";
                    } else {
                        $sqlProx .= " AND c.ID > (SELECT ID FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE CPF_CLIENTE = :cpf_atual AND ID_CAMPANHA = :id_camp LIMIT 1) ORDER BY c.ID ASC LIMIT 1";
                    }
                    
                    $stmtProx = $pdo->prepare($sqlProx);
                    $stmtProx->execute(['id_camp' => $id_camp, 'cpf_atual' => $cpf_selecionado]);
                    $proximo_cpf_campanha = $stmtProx->fetchColumn();
                    
                    if (!$proximo_cpf_campanha && $campanha_atual['PARAMETRO_INICIO_ALEATORIO'] == 'NAO') {
                        $stmtProxLoop = $pdo->prepare("SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ? AND CPF_CLIENTE != ? ORDER BY ID ASC LIMIT 1");
                        $stmtProxLoop->execute([$id_camp, $cpf_selecionado]);
                        $proximo_cpf_campanha = $stmtProxLoop->fetchColumn();
                    }
                }
            }
            
            $stmtInssBen = $pdo->prepare("SELECT * FROM banco_de_Dados_inss_dados_cadastrais WHERE cpf = :cpf");
            $stmtInssBen->execute(['cpf' => $cpf_selecionado]);
            $inss_beneficios = $stmtInssBen->fetchAll(PDO::FETCH_ASSOC);

            $sqlCtr = "SELECT *, 
                       CASE WHEN tipo_emprestimo IN ('76', '66', 'RCC') THEN 'RCC'
                            WHEN tipo_emprestimo IN ('44', 'RMC') THEN 'RMC'
                            WHEN tipo_emprestimo IN ('98', 'EMP') THEN 'EMP'
                            ELSE tipo_emprestimo END as tipo_corrigido
                       FROM banco_de_Dados_inss_contratos 
                       WHERE cpf = :cpf 
                       ORDER BY matricula_nb, 
                       CASE WHEN tipo_emprestimo IN ('RMC', 'RCC', '44', '66', '76') THEN 2 ELSE 1 END, 
                       banco";

            $stmtInssCtr = $pdo->prepare($sqlCtr);
            $stmtInssCtr->execute(['cpf' => $cpf_selecionado]);
            $inss_contratos_raw = $stmtInssCtr->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($inss_contratos_raw as $ctr) {
                $inss_contratos[$ctr['matricula_nb']][] = $ctr;
            }
        }
    } catch (\Throwable $e) {
        $erro_fatal_abertura = $e->getMessage();
    }
}
?>
<div class="container-fluid mt-3 mb-3">
    
    <?php if(isset($erro_fatal_abertura)): ?>
        <div class="alert alert-danger shadow-lg border-dark fw-bold text-center p-4">
            <h4><i class="fas fa-bug text-danger"></i> Ocorreu um Erro no Banco de Dados</h4>
            <p>O sistema abortou a abertura da ficha do cliente para evitar corrompimento de dados. Detalhe técnico:</p>
            <code class="text-dark bg-white p-2 border rounded d-block"><?= htmlspecialchars($erro_fatal_abertura ?? '') ?></code>
            <a href="consulta.php" class="btn btn-dark mt-3">Voltar para a Busca</a>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['msg_lote'])): ?>
        <div class="row justify-content-center">
            <div class="col-md-10">
                <div class="alert alert-success border-dark shadow-sm mt-2 fw-bold text-center rounded-0">
                    <i class="fas fa-check-circle fs-5 me-2"></i> Ação em lote concluída com sucesso! <b><?= (int)$_GET['qtd_lote'] ?></b> clientes foram <?= $_GET['msg_lote'] == 'incluir' ? 'INCLUÍDOS na' : 'REMOVIDOS da' ?> campanha.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row align-items-center">
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-start gap-2">
            
            <form action="" method="GET" class="d-flex shadow-sm m-0" style="width: 35%; min-width: 300px; position: relative;" id="formBuscaRapida">
                <?php if(isset($is_modo_campanha) && $is_modo_campanha): ?>
                    <input type="hidden" name="id_campanha" value="<?= htmlspecialchars($_GET['id_campanha'] ?? '') ?>">
                <?php endif; ?>
                <input type="text" name="busca" id="inputBuscaRapida" class="form-control border-primary rounded-0" placeholder="Busca inteligente: n:nome, c:cpf ou f:telefone..." value="<?= $is_busca_avancada ? '' : htmlspecialchars($termo_busca ?? '') ?>" autocomplete="off" autofocus>
                <button type="submit" class="btn btn-primary px-3 ms-1 fw-bold rounded-0"><i class="fas fa-search"></i></button>
                
                <ul id="listaAutocomplete" class="list-group position-absolute w-100 shadow-lg rounded-0 border-dark" style="top: 100%; z-index: 1050; display: none; max-height: 300px; overflow-y: auto;">
                </ul>
            </form>
            
            <div class="d-flex gap-2">
                <?php 
                $mostrar_integracoes = false;
                if (!empty($cpf_selecionado)) { $mostrar_integracoes = true; }
                if (!empty($termo_busca) && empty($cpf_selecionado) && empty($resultados_busca)) { $mostrar_integracoes = true; }
                
                if ($mostrar_integracoes): 
                ?>
                <div style="position: relative;">
                    <button id="btnIntegracoes" class="btn btn-sm btn-primary fw-bold border-dark shadow-sm rounded-0" type="button"><i class="fas fa-plug me-1"></i> Integrações <i class="fas fa-chevron-down ms-1"></i></button>
                    <ul id="menuIntegracoes" class="list-unstyled shadow-lg border border-dark bg-white py-2 rounded-0" style="display: none; position: absolute; right: 0; top: 110%; z-index: 9999; min-width: 250px; margin: 0;">
                        <li><a class="text-decoration-none fw-bold <?= !$perm_btn_v8 ? 'text-muted disabled pe-none' : 'text-dark' ?> px-3 py-2 d-block text-start table-hover bg-white hover-bg-light" href="#" <?= $perm_btn_v8 ? 'onclick="abrirModalIntegracao(\'V8_CLT\'); return false;"' : 'onclick="return false;"' ?>><i class="fas fa-handshake <?= !$perm_btn_v8 ? 'text-muted' : 'text-info' ?> me-2"></i> Integração V8 CLT <?= !$perm_btn_v8 ? '<i class="fas fa-lock float-end mt-1"></i>' : '' ?></a></li>
                        <li><hr class="border-dark m-0"></li>
                        <li><a class="text-decoration-none fw-bold <?= !$perm_btn_fator ? 'text-muted disabled pe-none' : 'text-dark' ?> px-3 py-2 d-block text-start table-hover bg-white hover-bg-light" href="#" <?= $perm_btn_fator ? 'onclick="abrirModalIntegracao(\'FATOR_CONFERI\'); return false;"' : 'onclick="return false;"' ?>><i class="fas fa-search-dollar <?= !$perm_btn_fator ? 'text-muted' : 'text-success' ?> me-2"></i> Atualização Cadastral <?= !$perm_btn_fator ? '<i class="fas fa-lock float-end mt-1"></i>' : '' ?></a></li>
                        <li><hr class="border-dark m-0"></li>
                        <li><a class="text-decoration-none fw-bold <?= !$perm_btn_hist_inss ? 'text-muted disabled pe-none' : 'text-dark' ?> px-3 py-2 d-block text-start table-hover bg-white hover-bg-light" href="#" <?= $perm_btn_hist_inss ? 'onclick="abrirModalIntegracao(\'HIST_INSS\'); return false;"' : 'onclick="return false;"' ?>><i class="fas fa-university <?= !$perm_btn_hist_inss ? 'text-muted' : 'text-danger' ?> me-2"></i> Consulta INSS (HIST) <?= !$perm_btn_hist_inss ? '<i class="fas fa-lock float-end mt-1"></i>' : '' ?></a></li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <button class="btn btn-sm btn-outline-primary fw-bold shadow-sm bg-light rounded-0" type="button" data-bs-toggle="modal" data-bs-target="#modalHistoricoConsulta"><i class="fas fa-history me-1"></i> Meu Histórico</button>
                <button class="btn btn-sm btn-outline-primary fw-bold bg-light shadow-sm rounded-0" type="button" data-bs-toggle="modal" data-bs-target="#modalDadosOrigem"><i class="fas fa-database me-1"></i> Dados Origem</button>
                
                <?php if ($perm_filtro_av): ?>
                <button class="btn btn-sm btn-outline-primary fw-bold shadow-sm rounded-0" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="false"><i class="fas fa-sliders-h me-1"></i> Filtro Aprimorado</button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($perm_filtro_av): ?>
        <div class="col-12 mt-2">
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/pesquisa_avancada.php'; ?>
        </div>
        <?php endif; ?>
    </div>

<?php if (!empty($termo_busca) && empty($cpf_selecionado) && !isset($erro_fatal_abertura)): ?>
    <div class="row justify-content-center mt-3">
        <div class="col-12">
            <?php if (isset($erro_sql)): ?>
                <div class="alert alert-danger border-dark shadow-sm rounded-0"><b>Erro na estrutura da busca:</b> <?= htmlspecialchars($erro_sql ?? '') ?></div>
            <?php endif; ?>

            <?php if (!empty($resultados_busca)): ?>
                <div class="card border-dark shadow-sm mb-5 rounded-0">
                    <div class="card-header bg-dark text-white border-bottom border-dark py-2 d-flex justify-content-between align-items-center rounded-0">
                        <h6 class="mb-0 fw-bold text-uppercase"><i class="fas fa-list text-info me-2"></i> Resultados da Busca (Pág <?= $pagina_atual ?>)</h6>
                        
                        <div style="position: relative;" class="d-flex gap-2">
                            
                            <?php if(!empty($lista_campanhas_ativas) && $perm_acao_campanha): ?>
                                <button id="btnAcaoLoteCamp" class="btn btn-sm btn-warning fw-bold shadow-sm border-dark rounded-0 text-dark" type="button" data-bs-toggle="modal" data-bs-target="#modalLoteCampanha"><i class="fas fa-bolt me-1"></i> Ações na Campanha</button>
                            <?php endif; ?>

                            <?php 
                                $params_url = $_GET; unset($params_url['pagina']); $params_url['exportar'] = 1; 
                                $url_base_export = '?' . http_build_query($params_url);
                            ?>
                            
                            <?php if ($perm_exportacao): ?>
                            <button id="btnExportDropdownList" class="btn btn-sm btn-success fw-bold shadow-sm border-dark rounded-0" type="button"><i class="fas fa-file-csv me-1"></i> Exportar <i class="fas fa-chevron-down ms-1"></i></button>
                            <ul id="menuExportDropdownList" class="list-unstyled shadow-lg border border-dark bg-white py-2 rounded-0" style="display: none; position: absolute; right: 0; top: 110%; z-index: 9999; min-width: 280px; margin: 0;">
                                <li><a class="text-decoration-none fw-bold text-dark px-3 py-2 d-block text-start table-hover bg-white hover-bg-light" href="<?= $url_base_export ?>&modelo_exportacao=dados_cadastrais" target="_blank" onclick="document.getElementById('menuExportDropdownList').style.display='none';"><i class="fas fa-id-card text-info me-2"></i> Completo (Dados Cadastrais)</a></li>
                                <li><hr class="border-dark m-0"></li>
                                <li><a class="text-decoration-none fw-bold text-danger px-3 py-2 d-block text-start table-hover bg-white hover-bg-light" href="<?= $url_base_export ?>&modelo_exportacao=telefones_foco" target="_blank" onclick="document.getElementById('menuExportDropdownList').style.display='none';"><i class="fas fa-phone-alt text-success me-2"></i> Foco em Telefones (Disparos)</a></li>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive bg-white rounded-0">
                        <table class="table table-hover table-sm align-middle mb-0 border-dark text-center">
                            <thead class="table-dark text-white text-uppercase" style="font-size: 0.80rem;"><tr><th>CPF</th><th class="text-start">Nome</th><th>Encontrado via</th><th>Ações</th></tr></thead>
                            <tbody>
                                <?php foreach ($resultados_busca as $res): ?>
                                    <tr class="border-bottom border-dark">
                                        <td class="fw-bold text-secondary bg-light" style="width: 15%;"><?= mascaraCPF($res['cpf']) ?></td>
                                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($res['nome'] ?? '') ?></td>
                                        <td><span class="badge bg-secondary text-light border border-dark rounded-0"><?= htmlspecialchars($res['origem'] ?? '') ?></span></td>
                                        <td>
                                            <?php 
                                            $url_params = $_GET;
                                            $url_params['cpf_selecionado'] = trim($res['cpf']);
                                            
                                            // Link de Visualizar
                                            $url_params['acao'] = 'visualizar';
                                            $link_ficha = '?' . http_build_query($url_params);
                                            
                                            // Link de Editar
                                            $url_params['acao'] = 'editar';
                                            $link_editar = '?' . http_build_query($url_params);
                                            ?>
                                            <a href="<?= $link_ficha ?>" class="btn btn-sm btn-primary border-dark me-1 py-0 rounded-0"><i class="fas fa-eye"></i> Ficha</a>
                                            <?php if ($perm_editar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                                            <a href="<?= $link_editar ?>" class="btn btn-sm btn-warning border-dark text-dark py-0 rounded-0"><i class="fas fa-edit"></i> Editar</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="card-footer bg-light border-top border-dark pt-2 pb-2 rounded-0">
                        <?php 
                            $params_url = $_GET; unset($params_url['pagina']); unset($params_url['exportar']); unset($params_url['modelo_exportacao']);
                            $str_params = http_build_query($params_url);
                            $url_base = '?' . $str_params . '&pagina=';
                        ?>
                        <nav aria-label="Navegação">
                            <ul class="pagination pagination-sm justify-content-center mb-1">
                                <li class="page-item <?= ($pagina_atual <= 1) ? 'disabled' : '' ?>">
                                    <a class="page-link border-dark text-dark rounded-0 fw-bold" href="<?= $url_base . ($pagina_atual - 1) ?>"><i class="fas fa-chevron-left me-1"></i> Anterior</a>
                                </li>
                                <li class="page-item active">
                                    <span class="page-link border-dark bg-dark text-white rounded-0">Página <?= $pagina_atual ?></span>
                                </li>
                                <li class="page-item <?= (!$tem_proxima_pagina) ? 'disabled' : '' ?>">
                                    <a class="page-link border-dark text-dark rounded-0 fw-bold" href="<?= $url_base . ($pagina_atual + 1) ?>">Próximo <i class="fas fa-chevron-right ms-1"></i></a>
                                </li>
                            </ul>
                        </nav>
                    </div>

                </div>
            <?php else: ?>
                <?php
                    $cpf_limpo_sugerido = is_numeric($termo_busca) ? preg_replace('/[^0-9]/', '', $termo_busca) : '';
                    $nome_sugerido = !$is_busca_avancada && !is_numeric($termo_busca) ? htmlspecialchars($termo_busca ?? '') : '';
                    $cpf_e_valido_para_inss = (strlen($cpf_limpo_sugerido) === 11);
                    $cpf_logado_sessao = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
                ?>

                <?php if ($cpf_e_valido_para_inss): ?>
                <!-- Estado: verificando HIST INSS (exibido enquanto JS processa) -->
                <div id="bloco-verificando-inss" class="alert alert-secondary text-center p-3 shadow-sm border-dark rounded-0">
                    <span class="spinner-border spinner-border-sm text-dark me-2" role="status"></span>
                    <span class="fw-bold text-dark">Verificando no histórico INSS offline... aguarde.</span>
                </div>

                <!-- Estado: encontrou no INSS (oculto até JS confirmar) -->
                <div id="bloco-inss-encontrado" class="alert alert-info text-center p-3 shadow-sm border-dark rounded-0" style="display:none;">
                    <p class="fs-6 fw-bold mb-1 text-dark"><i class="fas fa-database text-info me-2"></i> CPF não cadastrado localmente. Encontramos dados no <strong>Histórico INSS Offline</strong> — confira e cadastre.</p>
                    <?php if ($perm_cadastrar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                        <button class="btn btn-info btn-sm mt-1 shadow-sm fw-bold border-dark rounded-0 text-white" onclick="document.getElementById('formNovoCadastro').style.display='block'; this.style.display='none';"><i class="fas fa-user-plus me-1"></i> Ver e Confirmar Cadastro</button>
                    <?php endif; ?>
                </div>

                <!-- Estado: não encontrado em nenhuma fonte (oculto até JS confirmar) -->
                <div id="bloco-nao-encontrado" class="alert alert-warning text-center p-3 shadow-sm border-dark rounded-0" style="display:none;">
                    <p class="fs-6 fw-bold mb-0 text-dark"><i class="fas fa-exclamation-triangle text-warning me-2"></i> Não encontramos nenhum cliente com esses filtros.</p>
                    <?php if ($perm_cadastrar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                        <button class="btn btn-dark btn-sm mt-2 shadow-sm fw-bold border-dark rounded-0" onclick="document.getElementById('formNovoCadastro').style.display='block'; this.style.display='none';"><i class="fas fa-plus text-info me-1"></i> Cadastrar Novo</button>
                    <?php else: ?>
                        <p class="text-danger small fw-bold mt-2"><i class="fas fa-ban"></i> Cadastro manual desabilitado ou indisponível no Modo Campanha.</p>
                    <?php endif; ?>
                </div>

                <?php else: ?>
                <!-- CPF inválido ou busca por nome — exibe direto -->
                <div class="alert alert-warning text-center p-3 shadow-sm border-dark rounded-0">
                    <p class="fs-6 fw-bold mb-0 text-dark"><i class="fas fa-exclamation-triangle text-warning me-2"></i> Não encontramos nenhum cliente com esses filtros.</p>
                    <?php if ($perm_cadastrar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                        <button class="btn btn-dark btn-sm mt-2 shadow-sm fw-bold border-dark rounded-0" onclick="document.getElementById('formNovoCadastro').style.display='block'; this.style.display='none';"><i class="fas fa-plus text-info me-1"></i> Cadastrar Novo</button>
                    <?php else: ?>
                        <p class="text-danger small fw-bold mt-2"><i class="fas fa-ban"></i> Cadastro manual desabilitado ou indisponível no Modo Campanha.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($perm_cadastrar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                <div id="formNovoCadastro" style="display: none;" class="mt-4 text-start">
                    <form action="" method="POST" class="card border-dark shadow-lg rounded-0 overflow-hidden">
                        <input type="hidden" name="acao_rapida" value="cadastrar_novo">
                        <div class="card-header bg-dark text-white border-bottom border-dark py-3 rounded-0">
                            <h5 class="mb-0 fw-bold text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-user-plus text-info me-2"></i> Ficha de Novo Cadastro Rápido</h5>
                        </div>
                        <div class="card-body bg-light">
                            <div id="aviso-dados-inss" class="alert alert-info py-2 mb-3 border-dark rounded-0 small fw-bold" style="display:none;"><i class="fas fa-info-circle me-1"></i> Dados pré-preenchidos do histórico INSS. Confira antes de salvar.</div>
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">CPF <span class="text-danger">*</span></label>
                                    <input type="text" name="cpf_novo" id="campo-cpf-novo" class="form-control border-dark rounded-0" value="<?= $cpf_limpo_sugerido ?>" required maxlength="14">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Nome Completo <span class="text-danger">*</span></label>
                                    <input type="text" name="nome_novo" id="campo-nome-novo" class="form-control border-dark rounded-0 text-uppercase" value="<?= $nome_sugerido ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Telefone Principal</label>
                                    <input type="text" name="telefone_novo" class="form-control border-dark rounded-0" placeholder="DDD + Número">
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-top border-dark d-flex justify-content-between py-3 rounded-0">
                            <a href="consulta.php" class="btn btn-outline-dark fw-bold rounded-0">Cancelar</a>
                            <button type="submit" class="btn btn-success fw-bold border-dark text-dark rounded-0"><i class="fas fa-save me-2"></i> Salvar e Abrir Ficha</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if ($cpf_e_valido_para_inss): ?>
                <script>
                (function() {
                    const cpfAlvo  = '<?= $cpf_limpo_sugerido ?>';
                    const cpfCobrar = '<?= $cpf_logado_sessao ?>';

                    if (!cpfAlvo || !cpfCobrar) {
                        _exibirNaoEncontrado(); return;
                    }

                    const fd = new FormData();
                    fd.append('acao', 'consulta_cpf_manual');
                    fd.append('cpf', cpfAlvo);
                    fd.append('cpf_cobrar', cpfCobrar);
                    fd.append('fonte', 'AUTO_BUSCA');

                    fetch('/modulos/configuracao/Promosys_inss/promosys_inss.ajax.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(r => {
                            document.getElementById('bloco-verificando-inss').style.display = 'none';
                            if (r.success && r.dados && r.dados.nome && r.dados.nome !== 'Nome Nao Informado') {
                                // Preenche o campo nome com o dado do INSS
                                const campoNome = document.getElementById('campo-nome-novo');
                                if (campoNome) campoNome.value = r.dados.nome.toUpperCase();
                                const aviso = document.getElementById('aviso-dados-inss');
                                if (aviso) aviso.style.display = 'block';
                                document.getElementById('bloco-inss-encontrado').style.display = 'block';
                            } else {
                                _exibirNaoEncontrado();
                            }
                        })
                        .catch(() => _exibirNaoEncontrado());

                    function _exibirNaoEncontrado() {
                        const v = document.getElementById('bloco-verificando-inss');
                        if (v) v.style.display = 'none';
                        const n = document.getElementById('bloco-nao-encontrado');
                        if (n) n.style.display = 'block';
                    }
                })();
                </script>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php if (!empty($cpf_selecionado) && $acao == 'visualizar' && isset($cliente) && $cliente): ?>
    <div class="row justify-content-center mb-4 mt-2">
        <div class="col-12">
            
            <?php if (isset($is_modo_campanha) && $is_modo_campanha): ?>
            <div class="d-flex align-items-stretch mb-3 border border-dark shadow-sm bg-danger" style="height: 40px; overflow: hidden;">
                <div class="bg-danger text-white fw-bold d-flex align-items-center px-3 text-uppercase border-end border-dark" style="font-size: 0.85rem;"><i class="fas fa-headset me-2"></i> Campanha: <?= htmlspecialchars($campanha_atual['NOME_CAMPANHA'] ?? '') ?></div>
                <button class="btn btn-light rounded-0 fw-bold d-flex align-items-center px-3 border-end border-dark text-danger" style="font-size: 0.80rem;" data-bs-toggle="modal" data-bs-target="#modalStatusCampanha"><i class="fas fa-address-book me-2"></i> REGISTRAR CONTATO</button>
                <?php if($proximo_cpf_campanha): ?>
                    <a href="?id_campanha=<?= $id_camp ?>&busca=<?= $proximo_cpf_campanha ?>&cpf_selecionado=<?= $proximo_cpf_campanha ?>&acao=visualizar" class="btn btn-success rounded-0 fw-bold d-flex align-items-center px-3 border-end border-dark text-white text-decoration-none" style="font-size: 0.80rem;"><i class="fas fa-forward me-2"></i> Próximo Cliente</a>
                <?php else: ?>
                    <button class="btn btn-secondary rounded-0 fw-bold d-flex align-items-center px-3 border-end border-dark text-white" style="font-size: 0.80rem;" disabled><i class="fas fa-check-double me-2"></i> Fim da Fila</button>
                <?php endif; ?>
                <button class="btn btn-light rounded-0 fw-bold d-flex align-items-center px-3 border-end border-dark text-dark" style="font-size: 0.80rem;" data-bs-toggle="modal" data-bs-target="#modalVerRegistros"><i class="fas fa-history text-warning me-2"></i> REGISTROS</button>
                <div class="bg-danger flex-grow-1"></div>
                <a href="/modulos/campanhas/index.php" class="btn btn-dark rounded-0 fw-bold d-flex align-items-center px-4 border-start border-secondary text-white text-decoration-none" style="font-size: 0.80rem;"><i class="fas fa-sign-out-alt me-2"></i> Sair</a>
            </div>
            <?php endif; ?>

            <div class="row g-2 border-bottom border-primary pb-2 mb-2 align-items-end">
                <div class="col-md-7 d-flex align-items-center flex-wrap gap-2">
                    <h4 class="text-primary fw-bold text-uppercase m-0 me-3"><i class="fas fa-user-circle text-primary me-2"></i> <?= htmlspecialchars($cliente['nome'] ?? '') ?></h4>
                    <?php if (!(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                        <button class="btn btn-sm btn-outline-primary fw-bold rounded-0 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrosGerais"><i class="fas fa-list me-1"></i> REGISTROS</button>
                        <?php if(!empty($campanhas_para_inclusao) && $perm_incluir_camp): ?>
                            <button class="btn btn-sm btn-outline-primary fw-bold rounded-0 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalIncluirCampanha"><i class="fas fa-bullhorn me-1"></i> CAMPANHA</button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-primary fw-bold rounded-0 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalSelecionarStatusGeral"><i class="fas fa-plus me-1"></i> NOVO REGISTRO</button>
                    <?php endif; ?>
                </div>
                <div class="col-md-5 text-end">
                    <?php $urlRetorno = $is_busca_avancada ? '?' . preg_replace('/&?cpf_selecionado=[^&]*/', '', preg_replace('/&?acao=[^&]*/', '', $_SERVER['QUERY_STRING'])) : '?busca=' . urlencode($termo_busca); ?>
                    <a href="<?= $urlRetorno ?>" class="btn btn-sm btn-primary shadow-sm me-1 fw-bold rounded-0"><i class="fas fa-arrow-left"></i> Voltar</a>
                    
                    <?php if ($perm_editar && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                    <a href="?<?= $_SERVER['QUERY_STRING'] ?>&acao=editar" class="btn btn-sm btn-primary border-primary shadow-sm fw-bold rounded-0"><i class="fas fa-edit"></i> Editar</a>
                    <?php endif; ?>

                    <?php if ($perm_excluir && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
                    <form action="/modulos/cliente_e_usuario/acoes_cliente.php" method="POST" class="d-inline" onsubmit="return confirm('Confirma a exclusão DEFINITIVA deste cliente e todo seu histórico?');">
                        <input type="hidden" name="acao_crud" value="excluir">
                        <input type="hidden" name="cpf" value="<?= $cliente['cpf'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary shadow-sm fw-bold rounded-0 ms-1"><i class="fas fa-trash"></i> Excluir</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="row g-2">
                <div class="col-md-6">
                    <div class="card mb-2 border-dark rounded-0">
                        <div class="card-header bg-dark text-white py-1 px-2 border-bottom border-dark rounded-0"><span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fas fa-id-card text-info me-2"></i> Dados Principais</span></div>
                        <div class="table-responsive bg-white">
                            <table class="table table-bordered table-sm align-middle mb-0 border-dark" style="font-size: 0.75rem;">
                                <tbody>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">CPF</th><td class="text-start text-dark fw-bold border-dark" style="padding: 2px 8px;" id="ficha_cpf"><?= mascaraCPF($cliente['cpf'] ?? '') ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Nascimento</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;" id="ficha_nasc"><?= !empty($cliente['nascimento']) ? date('d/m/Y', strtotime($cliente['nascimento'])) : '-' ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Idade</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;"><?= $idade_calculada ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Sexo</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;" id="ficha_sexo"><?= htmlspecialchars($cliente['sexo'] ?? '-') ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Mãe</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;"><?= htmlspecialchars($cliente['nome_mae'] ?? '-') ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Pai</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;"><?= htmlspecialchars($cliente['nome_pai'] ?? '-') ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">RG</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;"><?= htmlspecialchars($cliente['rg'] ?? '-') ?></td></tr>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">CNH</th><td class="text-start text-dark border-dark" style="padding: 2px 8px;"><?= htmlspecialchars($cliente['cnh'] ?? '-') ?></td></tr>
                                    
                                    <?php if ($perm_ver_agrup): ?>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Agrupamento</th><td class="text-start border-dark" style="padding: 2px 8px;"><span class="badge bg-dark text-light rounded-1 py-1" style="font-size: 0.70rem;"><?= htmlspecialchars($cliente['agrupamento'] ?? 'Nenhum') ?></span></td></tr>
                                    <?php endif; ?>
                                    
                                    <?php if ($perm_ver_lote): ?>
                                    <tr><th class="bg-light text-dark text-end fw-bold text-uppercase border-dark" style="width: 1%; white-space: nowrap; padding: 2px 8px;">Importado via</th><td class="text-start border-dark" style="padding: 2px 8px;"><span class="badge bg-info text-dark border border-dark rounded-1 py-1" style="white-space: normal; font-size: 0.70rem;"><?= htmlspecialchars($lista_importacoes_cliente ?? 'Cadastro Manual') ?></span></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <div class="card h-100 border-dark rounded-0">
                                <div class="card-header bg-dark text-white py-1 px-2 border-bottom border-dark rounded-0"><span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fab fa-whatsapp text-success me-2"></i> Telefones</span></div>
                                <table class="table table-bordered table-sm mb-0 border-dark" style="font-size: 0.75rem;"><tbody><?php if(empty($telefones)): ?><tr><td class="text-muted px-2 py-1">Nenhum telefone.</td></tr><?php else: ?><?php foreach($telefones as $tel): ?><tr><td class="px-2 py-1 border-dark d-flex align-items-center justify-content-between"><span><i class="fab fa-whatsapp text-success me-2"></i><span class="text-dark fw-bold"><?= htmlspecialchars($tel['telefone_cel'] ?? '') ?></span></span><a href="tel:<?= htmlspecialchars($tel['telefone_cel'] ?? '') ?>" class="btn btn-sm btn-success py-0 px-2 ms-2" title="Ligar para este número" style="font-size:0.7rem;"><i class="fas fa-phone-alt me-1"></i> Ligar</a></td></tr><?php endforeach; ?><?php endif; ?></tbody></table>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card h-100 border-dark rounded-0">
                                <div class="card-header bg-dark text-white py-1 px-2 border-bottom border-dark rounded-0"><span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fas fa-envelope text-warning me-2"></i> E-mails</span></div>
                                <table class="table table-bordered table-sm mb-0 border-dark" style="font-size: 0.75rem;"><tbody><?php if(empty($emails)): ?><tr><td class="text-muted px-2 py-1">Nenhum e-mail.</td></tr><?php else: ?><?php foreach($emails as $email): ?><tr><td class="px-2 py-1 border-dark"><i class="fas fa-envelope text-secondary me-2"></i><span class="text-primary text-decoration-underline"><?= htmlspecialchars($email['email'] ?? '') ?></span></td></tr><?php endforeach; ?><?php endif; ?></tbody></table>
                            </div>
                        </div>
                    </div>

                    <div class="card border-dark rounded-0">
                        <div class="card-header bg-dark text-white py-1 px-2 border-bottom border-dark rounded-0"><span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fas fa-map-marker-alt text-danger me-2"></i> Endereços</span></div>
                        <div class="card-body p-0 bg-white">
                            <div class="row g-0">
                                <?php if(empty($enderecos)): ?>
                                    <div class="col-12 p-2"><span class="text-muted fst-italic" style="font-size: 0.75rem;">Nenhum endereço registrado.</span></div>
                                <?php else: ?>
                                    <?php foreach($enderecos as $end): ?>
                                        <div class="col-6 border border-dark p-2 text-start d-flex align-items-center" style="margin: -1px 0 0 -1px;">
                                            <i class="fas fa-map text-muted me-3 fs-5"></i>
                                            <div style="line-height: 1.1; font-size: 0.70rem;">
                                                <span class="fw-bold text-dark d-block text-uppercase mb-1"><?= htmlspecialchars($end['logradouro'] ?? '') ?>, <?= htmlspecialchars($end['numero'] ?? '') ?></span>
                                                <span class="text-secondary d-block text-uppercase"><?= htmlspecialchars($end['bairro'] ?? '') ?> | <?= htmlspecialchars($end['cidade'] ?? '') ?> - <?= htmlspecialchars($end['uf'] ?? '') ?></span>
                                                <span class="text-dark fw-bold d-block mt-1">CEP: <?= htmlspecialchars($end['cep'] ?? '') ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card border-danger rounded-0 mb-3">
                        <div class="card-header bg-danger text-white py-1 px-2 border-bottom border-danger rounded-0"><span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fas fa-id-badge me-2"></i> Convênios (Ficha Cadastral)</span></div>
                        <div class="card-body p-2 bg-white text-start">
                            <?php if(empty($convenios)): ?>
                                <span class="text-muted fst-italic" style="font-size: 0.75rem;">Nenhum convênio registrado.</span>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach($convenios as $conv): ?>
                                        <li class="border border-danger p-2 mb-2 bg-white rounded-0 d-inline-block me-2" style="font-size: 0.75rem; line-height: 1.2;">
                                            <div class="text-danger fw-bold text-uppercase mb-1" style="font-size: 0.65rem;">CONVÊNIO</div>
                                            <div class="text-dark mb-2 text-uppercase"><?= htmlspecialchars($conv['CONVENIO'] ?? '') ?></div>
                                            <div class="text-danger fw-bold text-uppercase mb-1" style="font-size: 0.65rem;">MATRÍCULA</div>
                                            <div class="text-dark text-uppercase"><?= htmlspecialchars($conv['MATRICULA'] ?? '') ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($inss_beneficios)): ?>
                        <div class="card h-100 border-danger rounded-0 mb-2">
                            <div class="card-header bg-danger text-white py-1 px-2 border-bottom border-danger rounded-0">
                                <span class="fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fas fa-university me-2"></i> INSS: Benefícios e Contratos</span>
                            </div>
                            <div class="card-body p-2 bg-white text-start" style="max-height: 500px; overflow-y: auto;">
                                <?php foreach($inss_beneficios as $ben): 
                                    $mat = $ben['matricula_nb'] ?? '';
                                    $ctrs = $inss_contratos[$mat] ?? [];
                                ?>
                                <div class="border border-danger p-2 mb-3 bg-white rounded-0 shadow-sm">
                                    
                                    <div class="row mb-2">
                                        <div class="col-md-3 border-end border-light">
                                            <div class="text-danger fw-bold text-uppercase mb-0" style="font-size: 0.65rem;">MATRÍCULA / ST</div>
                                            <div class="text-dark fw-bold text-uppercase" style="font-size: 0.90rem;"><?= htmlspecialchars($mat) ?></div>
                                            <div class="mt-1">
                                                <span class="badge bg-<?= strtolower($ben['situacao_beneficio'] ?? '') == 'ativo' ? 'success' : 'danger' ?> border border-dark rounded-0"><?= strtoupper(htmlspecialchars($ben['situacao_beneficio'] ?? 'N/A')) ?></span>
                                                <?php if(($ben['bloqueio_emprestimo'] ?? '') == 'Sim'): ?>
                                                    <span class="badge bg-danger border border-dark rounded-0" title="Bloqueado para Empréstimo"><i class="fas fa-lock"></i> BLOQ</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-1 text-uppercase" style="font-size: 0.65rem;">
                                                <b>DIB:</b> <?= htmlspecialchars($ben['dib'] ?? '---') ?>
                                            </div>
                                        </div>

                                        <div class="col-md-5 border-end border-light">
                                            <div class="text-danger fw-bold text-uppercase mb-0" style="font-size: 0.65rem;">DADOS DO BENEFÍCIO</div>
                                            <div class="text-dark text-uppercase" style="font-size: 0.70rem; line-height: 1.3;">
                                                <div class="mb-1 text-truncate" title="<?= htmlspecialchars($ben['especie_beneficio'] ?? '') ?>"><b>Espécie:</b> <?= htmlspecialchars($ben['especie_beneficio'] ?? '') ?> (Consig: <?= htmlspecialchars($ben['esp_consignavel'] ?? '-') ?>)</div>
                                                <div><b>Rep. Legal:</b> <?= htmlspecialchars($ben['representante_legal'] ?? '-') ?> <?= !empty($ben['representante_nome']) ? ' - ' . htmlspecialchars($ben['representante_nome']) : '' ?></div>
                                                <div><b>Pensão Alim.:</b> <?= htmlspecialchars($ben['pensao_alimenticia'] ?? '-') ?></div>
                                                <?php if(!empty($ben['contribuicao_nome'])): ?>
                                                <div class="text-danger mt-1 text-truncate" title="<?= htmlspecialchars($ben['contribuicao_nome'] ?? '') ?>"><b>Desconto:</b> <?= htmlspecialchars($ben['contribuicao_nome'] ?? '') ?> (R$ <?= number_format((float)($ben['contribuicao_valor'] ?? 0), 2, ',', '.') ?>)</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="text-danger fw-bold text-uppercase mb-0" style="font-size: 0.65rem;">FINANCEIRO / BANCO</div>
                                            <div class="text-dark text-uppercase" style="font-size: 0.70rem; line-height: 1.3;">
                                                <div class="d-flex justify-content-between border-bottom border-light pb-1 mb-1">
                                                    <span><b>Base:</b> R$ <?= number_format((float)($ben['valor_base_calculo'] ?? 0), 2, ',', '.') ?></span> 
                                                    <span><b>Livre:</b> <span class="text-success fw-bold fs-6">R$ <?= number_format((float)($ben['margem_calculada'] ?? 0), 2, ',', '.') ?></span></span>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span><b>RMC:</b> R$ <?= number_format((float)(($ben['valor_rmc'] ?? 0) > 0 ? $ben['valor_rmc'] : ($ben['margem_cartao'] ?? 0)), 2, ',', '.') ?></span> 
                                                    <span><b>RCC:</b> R$ <?= number_format((float)(($ben['valor_rcc'] ?? 0) > 0 ? $ben['valor_rcc'] : ($ben['margem_cartao_ben'] ?? 0)), 2, ',', '.') ?></span>
                                                </div>
                                                <div class="mt-1 text-truncate" title="<?= htmlspecialchars($ben['banco_pagamento'] ?? '') ?> / <?= htmlspecialchars($ben['agencia_pagamento'] ?? '') ?> / <?= htmlspecialchars($ben['conta_pagamento'] ?? '') ?>">
                                                    <b>BC:</b> <?= htmlspecialchars($ben['banco_pagamento'] ?? '') ?> <?= htmlspecialchars($ben['agencia_pagamento'] ?? '') ?> <b>C:</b> <?= htmlspecialchars($ben['conta_pagamento'] ?? '') ?>
                                                </div>
                                                <div><b>Recebe via:</b> <?= htmlspecialchars($ben['forma_pagamento'] ?? 'NÃO INFORMADO') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered mb-0 text-center align-middle" style="font-size: 0.70rem;">
                                            <thead class="bg-light text-dark">
                                                <tr>
                                                    <th>Tipo</th>
                                                    <th class="text-start">Banco</th>
                                                    <th>Parcela</th>
                                                    <th>Início</th>
                                                    <th>Resta</th>
                                                    <th class="text-start">Informações - Contrato</th>
                                                    <th>Taxa</th>
                                                    <th>Valor</th>
                                                    <th>Saldo</th>
                                                    <th>St</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if(empty($ctrs)): ?>
                                                <tr><td colspan="10" class="text-center text-muted">Nenhum contrato ativo/encontrado.</td></tr>
                                                <?php else: ?>
                                                    <?php foreach($ctrs as $c): 
                                                        $resta = ($c['prazo'] ?? 0) - ($c['parcelas_pagas'] ?? 0);
                                                        $resta = $resta < 0 ? 0 : $resta;
                                                        
                                                        $tipo_exibicao = $c['tipo_corrigido'] ?? '-';
                                                        
                                                        $badge_tipo = 'bg-secondary text-white';
                                                        if ($tipo_exibicao == 'EMP') {
                                                            $badge_tipo = 'bg-primary text-white';
                                                        } elseif ($tipo_exibicao == 'RMC' || $tipo_exibicao == 'RCC') {
                                                            $badge_tipo = 'bg-transparent text-dark fw-bold border border-dark'; 
                                                        }
                                                    ?>
                                                    <tr>
                                                        <td><span class="badge <?= $badge_tipo ?> rounded-0"><?= htmlspecialchars($tipo_exibicao) ?></span></td>
                                                        <td class="text-start fw-bold text-nowrap"><i class="fas fa-university text-secondary"></i> <?= htmlspecialchars(substr($c['banco'] ?? '', 0, 15)) ?></td>
                                                        <td class="text-success fw-bold text-nowrap">R$ <?= number_format($c['valor_parcela'] ?? 0, 2, ',', '.') ?></td>
                                                        <td><?= htmlspecialchars($c['inicio_desconto'] ?? '') ?></td>
                                                        <td><?= $resta ?>/<?= $c['prazo'] ?? 0 ?></td>
                                                        <td class="text-start text-muted"><?= htmlspecialchars($c['contrato'] ?? '') ?></td>
                                                        
                                                        <td class="text-success fw-bold">
                                                            <?= number_format($c['taxa_juros'] ?? 0, 2, ',', '.') ?>%
                                                            <?php if(!empty($c['taxa_msg'])): ?>
                                                                <i class="fas fa-exclamation-circle text-danger ms-1" title="<?= htmlspecialchars($c['taxa_msg'] ?? '') ?>" style="cursor: help;"></i>
                                                            <?php endif; ?>
                                                        </td>
                                                        
                                                        <td class="fw-bold text-nowrap">R$ <?= number_format($c['valor_emprestimo'] ?? 0, 2, ',', '.') ?></td>
                                                        <td class="text-danger fw-bold text-nowrap">R$ <?= number_format($c['saldo_quitacao'] ?? 0, 2, ',', '.') ?></td>
                                                        <td>
                                                            <?= strtolower($c['situacao'] ?? '') == 'ativo' ? '<i class="fas fa-check text-success" title="Ativo"></i>' : '<i class="fas fa-times text-danger" title="'.($c['situacao'] ?? '').'"></i>' ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
            
            <div class="row mt-3 mb-5">
                <div class="col-12">
                    <div class="card border-dark shadow-sm rounded-0">
                        <div class="card-header bg-dark text-white fw-bold py-2 border-bottom border-dark text-uppercase rounded-0" style="letter-spacing: 1px; font-size: 0.85rem;"><i class="fas fa-terminal text-success me-2"></i> Histórico de Interações (Integrações)</div>
                        <div class="table-responsive bg-light" style="max-height: 250px; overflow-y: auto;">
                            <table class="table table-hover table-sm align-middle mb-0 border-dark text-center" style="font-size: 0.75rem;">
                                <thead class="table-secondary text-dark text-uppercase sticky-top border-bottom border-dark"><tr><th style="width: 15%;">Data / Hora</th><th style="width: 15%;">Usuário</th><th class="text-start">Resultado da Operação</th></tr></thead>
                                <tbody id="tbody_logs_rodape">
                                    <?php if(empty($logs_rodape)): ?>
                                        <tr><td colspan="3" class="text-muted fst-italic py-3">Nenhuma integração executada para este cliente.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($logs_rodape as $log): ?>
                                            <tr class="border-bottom border-dark">
                                                <td class="fw-bold text-secondary"><?= date('d/m/Y H:i', strtotime($log['DATA_REGISTRO'])) ?></td>
                                                <td><span class="badge bg-dark border border-secondary rounded-0"><?= htmlspecialchars($log['NOME_USUARIO'] ?? '') ?></span></td>
                                                <td class="text-start text-dark fw-bold"><?= htmlspecialchars($log['TEXTO_REGISTRO'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div> </div>
<?php endif; ?>

<?php if (!empty($cpf_selecionado) && $acao == 'editar' && isset($cliente) && $cliente && !(isset($is_modo_campanha) && $is_modo_campanha)): ?>
    <div class="row justify-content-center">
        <div class="col-md-10">
            <form action="/modulos/cliente_e_usuario/atualizar_cadastro.php" method="POST" class="card border-dark shadow-lg rounded-0 overflow-hidden mb-4">
                <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase rounded-0" style="letter-spacing: 1px;">
                    <i class="fas fa-edit text-warning me-2"></i> Modo de Edição de Cliente
                </div>
                <div class="card-body bg-light">
                    
                    <ul class="nav nav-tabs mb-4 border-dark rounded-0" role="tablist">
                      <li class="nav-item"><a class="nav-link active text-dark fw-bold border-dark rounded-0" data-bs-toggle="tab" href="#edit-dados">1. Dados</a></li>
                      <li class="nav-item"><a class="nav-link text-dark border-dark rounded-0" data-bs-toggle="tab" href="#edit-tel">2. Telefones</a></li>
                      <li class="nav-item"><a class="nav-link text-dark border-dark rounded-0" data-bs-toggle="tab" href="#edit-end">3. Endereço</a></li>
                      <li class="nav-item"><a class="nav-link text-dark border-dark rounded-0" data-bs-toggle="tab" href="#edit-email">4. E-mails</a></li>
                      <li class="nav-item"><a class="nav-link text-danger fw-bold border-dark rounded-0" data-bs-toggle="tab" href="#edit-convenio">5. Convênios</a></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="edit-dados">
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label fw-bold">CPF</label><input type="text" class="form-control border-dark bg-secondary text-white rounded-0" value="<?= mascaraCPF($cpf_selecionado) ?>" readonly><input type="hidden" name="cpf" value="<?= $cpf_selecionado ?>"></div>
                                <div class="col-md-6"><label class="form-label fw-bold">Nome Completo</label><input type="text" name="nome" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['nome'] ?? '') ?>"></div>
                                <div class="col-md-3"><label class="form-label fw-bold">Sexo</label><select name="sexo" class="form-select border-dark rounded-0"><option value="" <?= empty($cliente['sexo']) ? 'selected' : '' ?>>Não Informado</option><option value="MASCULINO" <?= ($cliente['sexo'] ?? '') == 'MASCULINO' ? 'selected' : '' ?>>Masculino</option><option value="FEMININO" <?= ($cliente['sexo'] ?? '') == 'FEMININO' ? 'selected' : '' ?>>Feminino</option><option value="OUTROS" <?= ($cliente['sexo'] ?? '') == 'OUTROS' ? 'selected' : '' ?>>Outros</option></select></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Nascimento</label><input type="date" name="nascimento" class="form-control border-dark rounded-0" value="<?= $cliente['nascimento'] ?? '' ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Nome da Mãe</label><input type="text" name="nome_mae" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['nome_mae'] ?? '') ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Nome do Pai</label><input type="text" name="nome_pai" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['nome_pai'] ?? '') ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">RG</label><input type="text" name="rg" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['rg'] ?? '') ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">CNH</label><input type="text" name="cnh" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['cnh'] ?? '') ?>"></div>
                                <div class="col-md-4"><label class="form-label fw-bold">Carteira Profissional</label><input type="text" name="carteira_profissional" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($cliente['carteira_profissional'] ?? '') ?>"></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="edit-tel">
                            <div class="row g-3">
                                <?php for($i=0; $i<5; $i++): ?>
                                <?php $tel_atual = isset($telefones[$i]) ? $telefones[$i]['telefone_cel'] : ''; ?>
                                <div class="col-md-4"><label class="form-label fw-bold">Telefone <?= $i+1 ?></label><input type="text" name="telefones[]" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($tel_atual ?? '') ?>" maxlength="11"></div>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <?php $end_atual = isset($enderecos[0]) ? $enderecos[0] : []; ?>
                        <div class="tab-pane fade" id="edit-end">
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label fw-bold">CEP</label><input type="text" name="cep" class="form-control border-dark rounded-0" value="<?= isset($end_atual['cep']) ? htmlspecialchars($end_atual['cep']) : '' ?>" maxlength="8"></div>
                                <div class="col-md-7"><label class="form-label fw-bold">Logradouro (Rua/Av)</label><input type="text" name="logradouro" class="form-control border-dark rounded-0" value="<?= isset($end_atual['logradouro']) ? htmlspecialchars($end_atual['logradouro']) : '' ?>"></div>
                                <div class="col-md-2"><label class="form-label fw-bold">Número</label><input type="text" name="numero" class="form-control border-dark rounded-0" value="<?= isset($end_atual['numero']) ? htmlspecialchars($end_atual['numero']) : '' ?>"></div>
                                <div class="col-md-5"><label class="form-label fw-bold">Bairro</label><input type="text" name="bairro" class="form-control border-dark rounded-0" value="<?= isset($end_atual['bairro']) ? htmlspecialchars($end_atual['bairro']) : '' ?>"></div>
                                <div class="col-md-5"><label class="form-label fw-bold">Cidade</label><input type="text" name="cidade" class="form-control border-dark rounded-0" value="<?= isset($end_atual['cidade']) ? htmlspecialchars($end_atual['cidade']) : '' ?>"></div>
                                <div class="col-md-2"><label class="form-label fw-bold">UF</label><input type="text" name="uf" class="form-control border-dark rounded-0" value="<?= isset($end_atual['uf']) ? htmlspecialchars($end_atual['uf']) : '' ?>" maxlength="2"></div>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="edit-email">
                            <div class="row g-3">
                                <?php for($i=0; $i<5; $i++): ?>
                                <?php $email_atual = isset($emails[$i]) ? $emails[$i]['email'] : ''; ?>
                                <div class="col-md-6"><label class="form-label fw-bold">E-mail <?= $i+1 ?></label><input type="email" name="emails[]" class="form-control border-dark rounded-0" value="<?= htmlspecialchars($email_atual ?? '') ?>"></div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="edit-convenio">
                            <div class="alert alert-danger py-2 small border-dark fw-bold rounded-0"><i class="fas fa-info-circle"></i> Deixe a Matrícula e o Convênio em branco para excluir/ignorar.</div>
                            <div class="row g-3">
                                <?php for($i=0; $i<5; $i++): ?>
                                <?php $conv_atual = isset($convenios[$i]) ? $convenios[$i]['CONVENIO'] : ''; ?>
                                <?php $mat_atual = isset($convenios[$i]) ? $convenios[$i]['MATRICULA'] : ''; ?>
                                <div class="col-md-6"><label class="form-label fw-bold text-danger">Convênio <?= $i+1 ?></label><input type="text" name="convenios_nome[]" class="form-control border-danger text-uppercase rounded-0" value="<?= htmlspecialchars($conv_atual ?? '') ?>" placeholder="Ex: INSS, FGTS, SIAPE"></div>
                                <div class="col-md-6"><label class="form-label fw-bold text-danger">Matrícula <?= $i+1 ?></label><input type="text" name="convenios_matricula[]" class="form-control border-danger text-uppercase rounded-0" value="<?= htmlspecialchars($mat_atual ?? '') ?>" placeholder="Ex: 00000000"></div>
                                <?php endfor; ?>
                            </div>
                        </div>

                    </div>
                </div>
                <div class="card-footer bg-white border-top border-dark d-flex justify-content-between py-3 rounded-0">
                    <?php $urlRetorno = $is_busca_avancada ? '?' . preg_replace('/&?cpf_selecionado=[^&]*/', '', preg_replace('/&?acao=[^&]*/', '', $_SERVER['QUERY_STRING'])) : '?busca=' . urlencode($termo_busca); ?>
                    <a href="<?= $urlRetorno ?>" class="btn btn-outline-dark fw-bold rounded-0">Cancelar e Voltar</a>
                    <button type="submit" class="btn btn-warning border-dark btn-lg fw-bold text-dark rounded-0"><i class="fas fa-save me-2"></i> Atualizar Cadastro</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="modalSelecionarStatusGeral" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-primary text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-tags me-2"></i> Registrar Contato</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="d-grid gap-2">
                    <?php if(empty($status_gerais_avulsos)): ?>
                        <div class="small text-danger fw-bold w-100 text-center py-3">Nenhum status configurado para sua empresa.</div>
                    <?php else: ?>
                        <?php foreach($status_gerais_avulsos as $st): ?>
                            <button class="btn btn-outline-dark fw-bold rounded-0 text-start" onclick="abrirModalRegistroGeral(<?= $st['ID'] ?>, '<?= addslashes(htmlspecialchars($st['NOME_STATUS'])) ?>', '<?= $st['MARCACAO'] ?>')" data-bs-dismiss="modal">
                                <i class="fas <?= $st['MARCACAO'] == 'COM RETORNO' ? 'fa-calendar-plus text-danger' : 'fa-check text-success' ?> me-1"></i> <?= htmlspecialchars($st['NOME_STATUS']) ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalHistoricoConsulta" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark rounded-0 overflow-hidden">
            <div class="modal-header bg-warning text-dark border-bottom border-dark rounded-0">
                <h5 class="modal-title fw-bold text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-history text-dark me-2"></i> Meu Histórico de Consultas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="row g-2 align-items-end mb-4">
                    <div class="col-md-4">
                        <label class="small fw-bold">Data Início:</label>
                        <input type="date" id="hist_data_ini" class="form-control border-dark rounded-0" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="small fw-bold">Data Fim:</label>
                        <input type="date" id="hist_data_fim" class="form-control border-dark rounded-0" value="<?= date('Y-m-t') ?>">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-dark w-50 fw-bold border-dark shadow-sm rounded-0" onclick="carregarHistorico()"><i class="fas fa-filter me-1"></i> Filtrar</button>
                        <button class="btn btn-success w-50 fw-bold border-dark shadow-sm rounded-0" onclick="exportarHistorico()"><i class="fas fa-file-csv me-1"></i> CSV</button>
                    </div>
                </div>
                <div class="table-responsive shadow-sm border-dark rounded-0 overflow-hidden" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 border-dark text-center fs-6">
                        <thead class="table-dark text-white text-uppercase sticky-top" style="font-size: 0.85rem; z-index: 1;">
                            <tr><th>Data / Hora</th><th>Módulo / Pesquisa</th><th class="text-start">Nome do Cliente</th><th>CPF Alvo</th></tr>
                        </thead>
                        <tbody id="tbodyHistorico">
                            <tr><td colspan="4" class="text-muted py-4 bg-white fst-italic">Clique em Filtrar para carregar seu histórico.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDadosOrigem" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-dark rounded-0">
            <div class="modal-header bg-dark text-white border-bottom border-dark rounded-0">
                <h5 class="modal-title fw-bold text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-database text-info me-2"></i> Relação de Dados de Origem</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 bg-light">
                <ul class="nav nav-tabs rounded-0 pt-2 px-2 border-dark" role="tablist">
                    <?php if($perm_lotes): ?>
                    <li class="nav-item"><a class="nav-link active text-dark fw-bold border-dark rounded-0" data-bs-toggle="tab" href="#aba-modal-importacoes">Lotes de Importação</a></li>
                    <?php endif; ?>
                    <?php if($perm_agrup): ?>
                    <li class="nav-item"><a class="nav-link text-dark border-dark rounded-0" data-bs-toggle="tab" href="#aba-modal-agrupamentos">Agrupamentos</a></li>
                    <?php endif; ?>
                </ul>
                <div class="tab-content p-4">
                    <?php if($perm_lotes): ?>
                    <div class="tab-pane fade show active" id="aba-modal-importacoes">
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="text-dark small mb-0 fw-bold">
                                <i class="fas fa-info-circle text-primary me-1"></i> Copie o nome do Lote abaixo para utilizar no Filtro Aprimorado.
                            </p>
                            <div class="input-group input-group-sm" style="max-width: 250px;">
                                <span class="input-group-text bg-dark text-white border-dark"><i class="fas fa-search"></i></span>
                                <input type="text" id="filtroLotes" class="form-control border-dark fw-bold" placeholder="Filtrar lote ou data..." onkeyup="filtrarTabelaLotes()">
                            </div>
                        </div>
                        
                        <div class="table-responsive shadow-sm border-dark rounded-0" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                            <table class="table table-hover align-middle mb-0 border-dark text-center" style="font-size: 0.75rem;">
                                <thead class="table-dark text-white text-uppercase sticky-top" style="font-size: 0.75rem; z-index: 1;">
                                    <tr>
                                        <th style="width: 5%;">ID</th>
                                        <th class="text-start">Nome (Lote)</th>
                                        <th>Data / Hora</th>
                                        <th>Inseridos</th>
                                        <th>Atualizados</th>
                                        <th>Erros</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($lotes_importacao)): ?>
                                        <tr><td colspan="6" class="text-muted py-4 bg-white fst-italic">Nenhum lote.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($lotes_importacao as $lote): ?>
                                            <tr class="border-bottom border-dark bg-white">
                                                <td><span class="badge bg-secondary border border-dark rounded-0"><?= $lote['id'] ?></span></td>
                                                <td class="text-start fw-bold text-primary" style="user-select: all; cursor: pointer;"><?= htmlspecialchars($lote['nome_importacao']) ?></td>
                                                <td class="text-dark fw-bold"><?= date('d/m/Y H:i', strtotime($lote['data_importacao'])) ?></td>
                                                <td><span class="badge bg-dark text-white border border-secondary rounded-0"><?= $lote['qtd_novos'] ?></span></td>
                                                <td><span class="badge bg-warning text-dark border border-secondary rounded-0"><?= $lote['qtd_atualizados'] ?></span></td>
                                                <td><span class="badge bg-danger text-white border border-secondary rounded-0"><?= $lote['qtd_erros'] ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($perm_agrup): ?>
                    <div class="tab-pane fade <?= !$perm_lotes ? 'show active' : '' ?>" id="aba-modal-agrupamentos">
                        <div class="table-responsive shadow-sm border-dark rounded-0" style="max-height: 400px; overflow-y: auto; overflow-x: hidden;">
                            <table class="table table-hover align-middle mb-0 border-dark text-center" style="font-size: 0.85rem;">
                                <thead class="table-dark text-white text-uppercase sticky-top" style="font-size: 0.85rem; z-index: 1;">
                                    <tr>
                                        <th class="text-start ps-3">Agrupamento</th>
                                        <th>Qtd Clientes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(empty($lista_agrupamentos)): ?>
                                        <tr><td colspan="2" class="text-muted py-4 bg-white fst-italic">Nenhum agrupamento cadastrado.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($lista_agrupamentos as $agr): ?>
                                            <tr class="border-bottom border-dark bg-white">
                                                <td class="text-start ps-3 fw-bold text-primary" style="user-select: all; cursor: pointer;"><?= htmlspecialchars($agr['agrupamento']) ?></td>
                                                <td><span class="badge bg-dark text-white border border-secondary px-3 rounded-0"><?= $agr['qtd'] ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0"><button type="button" class="btn btn-outline-dark fw-bold rounded-0" data-bs-dismiss="modal">Fechar Janela</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalLoteCampanha" tabindex="-1">
    <div class="modal-dialog">
        <form method="GET" action="" class="modal-content border-dark shadow-lg">
            
            <?php foreach($_GET as $k => $v): ?>
                <?php if(is_array($v)): ?>
                    <?php foreach($v as $val): ?>
                        <input type="hidden" name="<?= $k ?>[]" value="<?= htmlspecialchars($val) ?>">
                    <?php endforeach; ?>
                <?php elseif($k != 'msg_lote' && $k != 'qtd_lote'): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            
            <input type="hidden" name="acao_lote_campanha" value="1">
            
            <div class="modal-header bg-warning text-dark border-dark rounded-0">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-bolt me-2"></i> Ações em Lote (Campanha)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-info border-dark small fw-bold shadow-sm rounded-0">
                    <i class="fas fa-info-circle"></i> A ação escolhida será aplicada a <b>TODOS os clientes encontrados neste filtro (sem limite de página)</b>.
                </div>
                <div class="mb-3 mt-3">
                    <label class="fw-bold small text-dark mb-1">Escolha a Campanha Alvo:</label>
                    <select name="id_campanha_lote" class="form-select border-dark shadow-sm rounded-0" required>
                        <option value="">-- Selecione --</option>
                        <?php foreach($lista_campanhas_ativas as $ca): ?>
                            <option value="<?= $ca['ID'] ?>"><?= htmlspecialchars($ca['NOME_CAMPANHA']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="fw-bold small text-dark mb-1">O que deseja fazer?</label>
                    <select name="tipo_acao_lote" class="form-select border-dark shadow-sm rounded-0" required>
                        <option value="incluir" class="text-success fw-bold">➕ INCLUIR todos os clientes nesta campanha</option>
                        <option value="excluir" class="text-danger fw-bold">➖ EXCLUIR todos os clientes desta campanha</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0">
                <button type="button" class="btn btn-outline-dark fw-bold rounded-0" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-dark fw-bold shadow-sm rounded-0" onclick="return confirm('ATENÇÃO: Você tem certeza que deseja executar essa ação em todos os clientes encontrados ao mesmo tempo?')"><i class="fas fa-play me-2"></i> Executar Agora</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($is_modo_campanha) && $is_modo_campanha): ?>
<div class="modal fade" id="modalStatusCampanha" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-danger text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-tags me-2"></i> Escolher Status</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <p class="small fw-bold text-dark mb-2 text-center text-uppercase">O que aconteceu na ligação?</p>
                <div class="d-grid gap-2">
                    <?php if(empty($status_campanha)): ?>
                        <div class="small text-danger fw-bold w-100 text-center py-3">Nenhum status vinculado a esta campanha.</div>
                    <?php else: ?>
                        <?php foreach($status_campanha as $st): ?>
                            <button class="btn btn-outline-dark fw-bold rounded-0 text-start" onclick="abrirModalRegistroCampanha(<?= $st['ID'] ?>, '<?= addslashes(htmlspecialchars($st['NOME_STATUS'])) ?>', '<?= $st['MARCACAO'] ?>')" data-bs-dismiss="modal">
                                <i class="fas <?= $st['MARCACAO'] == 'COM RETORNO' ? 'fa-calendar-plus text-danger' : 'fa-check text-success' ?> me-1"></i> <?= htmlspecialchars($st['NOME_STATUS']) ?>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalRegistroCampanha" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="/modulos/campanhas/acoes_campanha.php" class="modal-content border-dark shadow-lg rounded-0">
            <input type="hidden" name="acao" value="salvar_registro_contato">
            <input type="hidden" name="id_status" id="reg_id_status">
            <input type="hidden" name="cpf_cliente" value="<?= $cpf_selecionado ?>">
            <input type="hidden" name="id_campanha" id="reg_id_campanha">
            
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-info me-2"></i> Gravar Atendimento</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                
                <div class="mb-3">
                    <label class="small fw-bold text-muted">Status Selecionado:</label>
                    <div id="lbl_status_nome" class="form-control border-dark fw-bold bg-white text-primary rounded-0" readonly></div>
                </div>

                <div class="mb-3">
                    <label class="small fw-bold text-dark">Telefone Discado (Para Qualificação) <span class="text-muted fw-normal">(opcional)</span></label>
                    <select name="telefone_discado" class="form-select border-dark rounded-0 fw-bold text-success">
                        <option value="">— Sem telefone / Não informar —</option>
                        <?php foreach($telefones as $tel): ?>
                            <option value="<?= htmlspecialchars($tel['telefone_cel']) ?>"><?= htmlspecialchars($tel['telefone_cel']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="small fw-bold text-dark">Informação / Observação do Contato</label>
                    <textarea name="texto_registro" class="form-control border-dark rounded-0" rows="3" placeholder="Digite os detalhes da conversa..." required></textarea>
                </div>

                <div class="mb-2" id="div_data_agendamento" style="display: none;">
                    <label class="small fw-bold text-danger"><i class="fas fa-calendar-alt"></i> Agendar Retorno (Obrigatório)</label>
                    <input type="datetime-local" name="data_agendamento" id="input_data_agendamento" class="form-control border-danger fw-bold rounded-0">
                </div>

            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-outline-dark fw-bold rounded-0" data-bs-dismiss="modal"><i class="fas fa-arrow-left"></i> Voltar</button>
                <button type="submit" class="btn btn-sm btn-success fw-bold text-dark border-dark rounded-0"><i class="fas fa-save me-1"></i> Salvar Registro</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalVerRegistros" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-history text-warning me-2"></i> Registros nesta Campanha</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3">

                <!-- FILTROS -->
                <div class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Data Início</label>
                        <input type="date" id="filtroRegDataIni" class="form-control form-control-sm border-dark rounded-0" onchange="filtrarRegistrosCampanha()">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Data Fim</label>
                        <input type="date" id="filtroRegDataFim" class="form-control form-control-sm border-dark rounded-0" onchange="filtrarRegistrosCampanha()">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Status</label>
                        <select id="filtroRegStatus" class="form-select form-select-sm border-dark rounded-0" onchange="filtrarRegistrosCampanha()">
                            <option value="">— Todos —</option>
                            <?php foreach(array_unique(array_column($historico_campanha, 'NOME_STATUS')) as $ns): ?>
                                <option value="<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($ns) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Usuário</label>
                        <select id="filtroRegUsuario" class="form-select form-select-sm border-dark rounded-0" onchange="filtrarRegistrosCampanha()">
                            <option value="">— Todos —</option>
                            <?php foreach(array_unique(array_column($historico_campanha, 'NOME_USUARIO')) as $nu): ?>
                                <option value="<?= htmlspecialchars($nu) ?>"><?= htmlspecialchars($nu) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- CONTADOR -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-bold" id="contadorRegCampanha"></span>
                    <button class="btn btn-sm btn-outline-secondary rounded-0 fw-bold" onclick="filtrarRegistrosCampanha(true)"><i class="fas fa-times me-1"></i> Limpar Filtros</button>
                </div>

                <!-- TABELA COM SCROLL -->
                <div style="max-height: 520px; overflow-y: auto; border: 1px solid #343a40;">
                    <table class="table table-bordered table-hover table-sm mb-0 align-middle" style="font-size:0.8rem;">
                        <thead class="table-dark sticky-top" style="top:0; z-index:2;">
                            <tr>
                                <th style="width:130px; white-space:nowrap;">Data / Hora</th>
                                <th style="width:160px;">Status</th>
                                <th style="width:160px;">Usuário</th>
                                <th>Observação</th>
                                <th style="width:130px; white-space:nowrap;">Agendamento</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyRegCampanha">
                            <?php if(empty($historico_campanha)): ?>
                                <tr><td colspan="5" class="text-center text-muted fw-bold py-4">Nenhum registro salvo para este cliente nesta campanha ainda.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- BOTÃO MAIS -->
                <div class="text-center mt-2" id="divBtnMaisReg" style="display:none;">
                    <button class="btn btn-sm btn-outline-dark fw-bold rounded-0 px-4" onclick="carregarMaisRegistros()">
                        <i class="fas fa-chevron-down me-1"></i> Carregar mais 20 <span class="badge bg-secondary ms-1" id="badgeRestantesReg"></span>
                    </button>
                </div>

            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-dark fw-bold rounded-0" data-bs-dismiss="modal">Fechar Janela</button>
            </div>
        </div>
    </div>
</div>

<script>
const _regCampanhaData = <?= json_encode(array_map(function($hc) {
    return [
        'data'        => date('Y-m-d', strtotime($hc['DATA_REGISTRO'])),
        'data_br'     => date('d/m/Y H:i', strtotime($hc['DATA_REGISTRO'])),
        'status'      => $hc['NOME_STATUS'] ?? '',
        'usuario'     => $hc['NOME_USUARIO'] ?? '',
        'registro'    => $hc['REGISTRO'] ?? '',
        'agendamento' => !empty($hc['DATA_AGENDAMENTO']) ? date('d/m/Y H:i', strtotime($hc['DATA_AGENDAMENTO'])) : ''
    ];
}, $historico_campanha), JSON_UNESCAPED_UNICODE) ?>;

let _regFiltrados = [];
let _regOffset = 0;
const _REG_PAGE = 20;

function filtrarRegistrosCampanha(limpar) {
    if (limpar) {
        document.getElementById('filtroRegDataIni').value = '';
        document.getElementById('filtroRegDataFim').value = '';
        document.getElementById('filtroRegStatus').value = '';
        document.getElementById('filtroRegUsuario').value = '';
    }
    const dIni   = document.getElementById('filtroRegDataIni').value;
    const dFim   = document.getElementById('filtroRegDataFim').value;
    const status = document.getElementById('filtroRegStatus').value.toLowerCase();
    const usuario= document.getElementById('filtroRegUsuario').value.toLowerCase();

    _regFiltrados = _regCampanhaData.filter(r => {
        if (dIni   && r.data < dIni) return false;
        if (dFim   && r.data > dFim) return false;
        if (status && r.status.toLowerCase() !== status) return false;
        if (usuario&& r.usuario.toLowerCase() !== usuario) return false;
        return true;
    });

    _regOffset = 0;
    document.getElementById('tbodyRegCampanha').innerHTML = '';
    _renderizarRegistros();
}

function _renderizarRegistros() {
    const tbody = document.getElementById('tbodyRegCampanha');
    const lote = _regFiltrados.slice(_regOffset, _regOffset + _REG_PAGE);
    const restantes = _regFiltrados.length - _regOffset - lote.length;

    if (_regFiltrados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted fw-bold py-4">Nenhum registro encontrado com os filtros aplicados.</td></tr>';
        document.getElementById('divBtnMaisReg').style.display = 'none';
        document.getElementById('contadorRegCampanha').textContent = '0 registros';
        return;
    }

    lote.forEach(r => {
        const agend = r.agendamento
            ? `<span class="badge bg-danger rounded-0"><i class="fas fa-calendar-alt me-1"></i>${r.agendamento}</span>`
            : '<span class="text-muted">—</span>';
        tbody.innerHTML += `<tr>
            <td class="text-nowrap fw-bold text-muted">${r.data_br}</td>
            <td><span class="badge bg-primary rounded-0 text-uppercase" style="font-size:0.72rem;">${r.status}</span></td>
            <td class="text-dark" style="font-size:0.75rem;">${r.usuario}</td>
            <td style="white-space:pre-wrap; word-break:break-word;">${r.registro.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</td>
            <td class="text-nowrap">${agend}</td>
        </tr>`;
    });

    _regOffset += lote.length;

    document.getElementById('contadorRegCampanha').textContent =
        _regOffset + ' de ' + _regFiltrados.length + ' registro(s)';

    const btnMais = document.getElementById('divBtnMaisReg');
    if (restantes > 0) {
        document.getElementById('badgeRestantesReg').textContent = restantes + ' restantes';
        btnMais.style.display = '';
    } else {
        btnMais.style.display = 'none';
    }
}

function carregarMaisRegistros() {
    _renderizarRegistros();
}

// Inicializa quando o modal abre
document.getElementById('modalVerRegistros').addEventListener('show.bs.modal', function() {
    document.getElementById('filtroRegDataIni').value = '';
    document.getElementById('filtroRegDataFim').value = '';
    document.getElementById('filtroRegStatus').value = '';
    document.getElementById('filtroRegUsuario').value = '';
    _regFiltrados = [..._regCampanhaData];
    _regOffset = 0;
    document.getElementById('tbodyRegCampanha').innerHTML = '';
    _renderizarRegistros();
});
</script>

<?php if (!(isset($is_modo_campanha) && $is_modo_campanha) && !empty($cpf_selecionado)): ?>
<div class="modal fade" id="modalRegistrosGerais" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-history text-warning me-2"></i> Histórico Geral de Registros</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3">

                <!-- FILTROS -->
                <div class="row g-2 mb-3">
                    <div class="col-md-2">
                        <label class="small fw-bold text-dark">Data Início</label>
                        <input type="date" id="filtroGeralDataIni" class="form-control form-control-sm border-dark rounded-0" onchange="filtrarRegistrosGerais()">
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-dark">Data Fim</label>
                        <input type="date" id="filtroGeralDataFim" class="form-control form-control-sm border-dark rounded-0" onchange="filtrarRegistrosGerais()">
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Status</label>
                        <select id="filtroGeralStatus" class="form-select form-select-sm border-dark rounded-0" onchange="filtrarRegistrosGerais()">
                            <option value="">— Todos —</option>
                            <?php foreach(array_unique(array_column($historico_geral_cliente, 'NOME_STATUS')) as $ns): if(!$ns) continue; ?>
                                <option value="<?= htmlspecialchars($ns) ?>"><?= htmlspecialchars($ns) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="small fw-bold text-dark">Usuário</label>
                        <select id="filtroGeralUsuario" class="form-select form-select-sm border-dark rounded-0" onchange="filtrarRegistrosGerais()">
                            <option value="">— Todos —</option>
                            <?php foreach(array_unique(array_column($historico_geral_cliente, 'NOME_USUARIO')) as $nu): if(!$nu) continue; ?>
                                <option value="<?= htmlspecialchars($nu) ?>"><?= htmlspecialchars($nu) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="small fw-bold text-dark">Campanha</label>
                        <select id="filtroGeralCampanha" class="form-select form-select-sm border-dark rounded-0" onchange="filtrarRegistrosGerais()">
                            <option value="">— Todas —</option>
                            <?php foreach(array_unique(array_column($historico_geral_cliente, 'NOME_CAMPANHA')) as $nc): if(!$nc) continue; ?>
                                <option value="<?= htmlspecialchars($nc) ?>"><?= htmlspecialchars($nc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- CONTADOR -->
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="small text-muted fw-bold" id="contadorRegGeral"></span>
                    <button class="btn btn-sm btn-outline-secondary rounded-0 fw-bold" onclick="filtrarRegistrosGerais(true)"><i class="fas fa-times me-1"></i> Limpar Filtros</button>
                </div>

                <!-- TABELA COM SCROLL -->
                <div style="max-height: 480px; overflow-y: auto; border: 1px solid #343a40;">
                    <table class="table table-bordered table-hover table-sm mb-0 align-middle" style="font-size:0.8rem;">
                        <thead class="table-dark sticky-top" style="top:0; z-index:2;">
                            <tr>
                                <th style="width:120px; white-space:nowrap;">Data / Hora</th>
                                <th style="width:140px;">Status</th>
                                <th style="width:130px;">Campanha</th>
                                <th style="width:140px;">Usuário</th>
                                <th>Observação</th>
                                <th style="width:120px; white-space:nowrap;">Agendamento</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyRegGeral">
                        </tbody>
                    </table>
                </div>

                <!-- BOTÃO MAIS -->
                <div class="text-center mt-2" id="divBtnMaisRegGeral" style="display:none;">
                    <button class="btn btn-sm btn-outline-dark fw-bold rounded-0 px-4" onclick="carregarMaisRegistrosGerais()">
                        <i class="fas fa-chevron-down me-1"></i> Carregar mais 20 <span class="badge bg-secondary ms-1" id="badgeRestantesRegGeral"></span>
                    </button>
                </div>

            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-dark fw-bold rounded-0" data-bs-dismiss="modal">Fechar Janela</button>
            </div>
        </div>
    </div>
</div>

<script>
const _regGeralData = <?= json_encode(array_map(function($hc) {
    return [
        'data'      => date('Y-m-d', strtotime($hc['DATA_REGISTRO'])),
        'data_br'   => date('d/m/Y H:i', strtotime($hc['DATA_REGISTRO'])),
        'status'    => $hc['NOME_STATUS'] ?? '',
        'campanha'  => $hc['NOME_CAMPANHA'] ?? '',
        'usuario'   => $hc['NOME_USUARIO'] ?? '',
        'registro'  => $hc['REGISTRO'] ?? '',
        'agendamento' => !empty($hc['DATA_AGENDAMENTO']) ? date('d/m/Y H:i', strtotime($hc['DATA_AGENDAMENTO'])) : ''
    ];
}, $historico_geral_cliente), JSON_UNESCAPED_UNICODE) ?>;

let _regGeralFiltrados = [];
let _regGeralOffset = 0;
const _REG_GERAL_PAGE = 20;

function filtrarRegistrosGerais(limpar) {
    if (limpar) {
        document.getElementById('filtroGeralDataIni').value = '';
        document.getElementById('filtroGeralDataFim').value = '';
        document.getElementById('filtroGeralStatus').value = '';
        document.getElementById('filtroGeralUsuario').value = '';
        document.getElementById('filtroGeralCampanha').value = '';
    }
    const dIni    = document.getElementById('filtroGeralDataIni').value;
    const dFim    = document.getElementById('filtroGeralDataFim').value;
    const status  = document.getElementById('filtroGeralStatus').value.toLowerCase();
    const usuario = document.getElementById('filtroGeralUsuario').value.toLowerCase();
    const camp    = document.getElementById('filtroGeralCampanha').value.toLowerCase();

    _regGeralFiltrados = _regGeralData.filter(r => {
        if (dIni   && r.data < dIni) return false;
        if (dFim   && r.data > dFim) return false;
        if (status && r.status.toLowerCase() !== status) return false;
        if (usuario && r.usuario.toLowerCase() !== usuario) return false;
        if (camp   && r.campanha.toLowerCase() !== camp) return false;
        return true;
    });

    _regGeralOffset = 0;
    document.getElementById('tbodyRegGeral').innerHTML = '';
    _renderizarRegistrosGerais();
}

function _renderizarRegistrosGerais() {
    const tbody = document.getElementById('tbodyRegGeral');
    const lote = _regGeralFiltrados.slice(_regGeralOffset, _regGeralOffset + _REG_GERAL_PAGE);
    const restantes = _regGeralFiltrados.length - _regGeralOffset - lote.length;

    if (_regGeralFiltrados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted fw-bold py-4">Nenhum registro encontrado com os filtros aplicados.</td></tr>';
        document.getElementById('divBtnMaisRegGeral').style.display = 'none';
        document.getElementById('contadorRegGeral').textContent = '0 registros';
        return;
    }

    lote.forEach(r => {
        const agend = r.agendamento
            ? `<span class="badge bg-danger rounded-0"><i class="fas fa-calendar-alt me-1"></i>${r.agendamento}</span>`
            : '<span class="text-muted">—</span>';
        const camp = r.campanha
            ? `<span class="badge bg-secondary rounded-0" style="font-size:0.70rem;">${r.campanha.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>`
            : '<span class="text-muted">—</span>';
        tbody.innerHTML += `<tr>
            <td class="text-nowrap fw-bold text-muted">${r.data_br}</td>
            <td><span class="badge bg-primary rounded-0 text-uppercase" style="font-size:0.72rem;">${r.status.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span></td>
            <td>${camp}</td>
            <td class="text-dark" style="font-size:0.75rem;">${r.usuario.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</td>
            <td style="white-space:pre-wrap; word-break:break-word;">${r.registro.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</td>
            <td class="text-nowrap">${agend}</td>
        </tr>`;
    });

    _regGeralOffset += lote.length;
    document.getElementById('contadorRegGeral').textContent = _regGeralOffset + ' de ' + _regGeralFiltrados.length + ' registro(s)';

    const btnMais = document.getElementById('divBtnMaisRegGeral');
    if (restantes > 0) {
        document.getElementById('badgeRestantesRegGeral').textContent = restantes + ' restantes';
        btnMais.style.display = '';
    } else {
        btnMais.style.display = 'none';
    }
}

function carregarMaisRegistrosGerais() { _renderizarRegistrosGerais(); }

document.getElementById('modalRegistrosGerais').addEventListener('show.bs.modal', function() {
    document.getElementById('filtroGeralDataIni').value = '';
    document.getElementById('filtroGeralDataFim').value = '';
    document.getElementById('filtroGeralStatus').value = '';
    document.getElementById('filtroGeralUsuario').value = '';
    document.getElementById('filtroGeralCampanha').value = '';
    _regGeralFiltrados = [..._regGeralData];
    _regGeralOffset = 0;
    document.getElementById('tbodyRegGeral').innerHTML = '';
    _renderizarRegistrosGerais();
});
</script>

<?php if(!empty($campanhas_para_inclusao) && $perm_incluir_camp): ?>
<div class="modal fade" id="modalIncluirCampanha" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" action="/modulos/campanhas/acoes_campanha.php" class="modal-content border-dark shadow-lg rounded-0">
            <input type="hidden" name="acao" value="incluir_cliente_campanha">
            <input type="hidden" name="cpf_cliente" value="<?= $cpf_selecionado ?>">
            
            <div class="modal-header bg-danger text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase"><i class="fas fa-bullhorn me-2"></i> Incluir na Campanha</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light text-center">
                <label class="small fw-bold text-dark mb-2 text-uppercase">Selecione a Campanha:</label>
                <select name="id_campanha" class="form-select border-dark rounded-0 fw-bold" required>
                    <option value="">-- Selecione --</option>
                    <?php foreach($campanhas_para_inclusao as $cInc): ?>
                        <option value="<?= $cInc['ID'] ?>"><?= htmlspecialchars($cInc['NOME_CAMPANHA']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-3" style="font-size: 0.70rem;">O cliente será inserido e a tela mudará para o Modo de Atendimento dessa campanha.</small>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="submit" class="btn btn-sm btn-success fw-bold text-dark border-dark rounded-0 w-100"><i class="fas fa-play me-1"></i> Incluir e Iniciar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/modal_integracoes.php'; ?>

<script>
document.getElementById('modalHistoricoConsulta')?.addEventListener('shown.bs.modal', function () {
    carregarHistorico();
});

document.addEventListener('DOMContentLoaded', function() {
    const inputBusca = document.getElementById('inputBuscaRapida');
    const listaAuto = document.getElementById('listaAutocomplete');
    let timeoutBusca = null;

    if(inputBusca) {
        inputBusca.addEventListener('input', function() {
            clearTimeout(timeoutBusca);
            const termo = this.value.trim();
            const prefixo = termo.substring(0, 2);

            if (['n:', 'c:', 'f:'].includes(prefixo) && termo.length > 4) {
                timeoutBusca = setTimeout(() => {
                    let fd = new FormData();
                    fd.append('acao', 'autocomplete_busca');
                    fd.append('termo', termo);

                    fetch('consulta.php', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(r => {
                        listaAuto.innerHTML = '';
                        if(r.success && r.data.length > 0) {
                            r.data.forEach(item => {
                                let li = document.createElement('li');
                                li.className = 'list-group-item list-group-item-action border-dark text-start p-2';
                                li.style.cursor = 'pointer';
                                let extra = item.telefone_cel ? `<br><small class="text-success fw-bold"><i class="fas fa-phone"></i> ${item.telefone_cel}</small>` : '';
                                let paramCamp = '<?= (isset($is_modo_campanha) && $is_modo_campanha) ? "&id_campanha=".$id_camp : "" ?>';
                                li.innerHTML = `<span class="fw-bold text-primary">${item.cpf}</span> - <span class="fw-bold text-dark">${item.nome}</span> ${extra}`;
                                li.onclick = () => {
                                    window.location.href = `consulta.php?busca=${item.cpf}&cpf_selecionado=${item.cpf}&acao=visualizar${paramCamp}`;
                                };
                                listaAuto.appendChild(li);
                            });
                            listaAuto.style.display = 'block';
                        } else {
                            listaAuto.innerHTML = '<li class="list-group-item text-muted text-center p-2 fw-bold">Nenhum resultado rápido encontrado.</li>';
                            listaAuto.style.display = 'block';
                        }
                    });
                }, 400); 
            } else {
                listaAuto.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (!inputBusca.contains(e.target) && !listaAuto.contains(e.target)) {
                listaAuto.style.display = 'none';
            }
        });
    }
});

function carregarHistorico() {
    const tb = document.getElementById('tbodyHistorico');
    if(!tb) return;
    tb.innerHTML = '<tr><td colspan="4" class="text-center py-3 text-primary"><i class="fas fa-spinner fa-spin"></i> Buscando histórico no cofre...</td></tr>';
    
    let fd = new FormData();
    fd.append('acao', 'listar_historico');
    fd.append('data_ini', document.getElementById('hist_data_ini').value);
    fd.append('data_fim', document.getElementById('hist_data_fim').value);
    
    fetch('consulta.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(r => {
        tb.innerHTML = '';
        if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted fw-bold">Nenhum registro neste período.</td></tr>'; return; }
        
        r.data.forEach(h => {
            let nome_cliente = h.NOME_CLIENTE ? h.NOME_CLIENTE : '<span class="text-muted fst-italic">Cliente não localizado</span>';
            tb.innerHTML += `<tr class="border-bottom border-dark bg-white">
                <td class="fw-bold">${h.DATA_BR}</td>
                <td><span class="badge bg-dark text-white border border-secondary rounded-0">${h.MODULO_CONSULTA}</span></td>
                <td class="fw-bold text-dark text-start text-uppercase" style="font-size: 0.85rem;">${nome_cliente}</td>
                <td><a href="consulta.php?busca=${h.CPF_CLIENTE}&cpf_selecionado=${h.CPF_CLIENTE}&acao=visualizar" class="btn btn-sm btn-outline-primary fw-bold py-0 rounded-0"><i class="fas fa-link"></i> ${h.CPF_CLIENTE}</a></td>
            </tr>`;
        });
    });
}

function exportarHistorico() {
    let dIni = document.getElementById('hist_data_ini').value;
    let dFim = document.getElementById('hist_data_fim').value;
    window.location.href = `consulta.php?acao=exportar_historico&data_ini=${dIni}&data_fim=${dFim}`;
}

let modalRegistroCamp;
// Função usada tanto no Modo Campanha quanto no Registro Avulso (Geral)
function abrirModalRegistroCampanha(id_status, nome_status, marcacao) {
    document.getElementById('reg_id_status').value = id_status;
    document.getElementById('lbl_status_nome').innerText = nome_status;
    
    // Verifica se estamos em uma campanha e injeta o ID, senão deixa vazio
    document.getElementById('reg_id_campanha').value = '<?= $id_camp ?? '' ?>';
    
    const divAgendamento = document.getElementById('div_data_agendamento');
    const inputAgendamento = document.getElementById('input_data_agendamento');
    
    if(marcacao === 'COM RETORNO') {
        divAgendamento.style.display = 'block';
        inputAgendamento.setAttribute('required', 'required');
    } else {
        divAgendamento.style.display = 'none';
        inputAgendamento.removeAttribute('required');
    }
    
    if(!modalRegistroCamp) modalRegistroCamp = new bootstrap.Modal(document.getElementById('modalRegistroCampanha'));
    modalRegistroCamp.show();
}

function abrirModalRegistroGeral(id_status, nome_status, marcacao) {
    document.getElementById('reg_id_status').value = id_status;
    document.getElementById('lbl_status_nome').innerText = nome_status;
    
    // Como é registro avulso, não tem ID de campanha
    document.getElementById('reg_id_campanha').value = '';
    
    const divAgendamento = document.getElementById('div_data_agendamento');
    const inputAgendamento = document.getElementById('input_data_agendamento');
    
    if(marcacao === 'COM RETORNO') {
        divAgendamento.style.display = 'block';
        inputAgendamento.setAttribute('required', 'required');
    } else {
        divAgendamento.style.display = 'none';
        inputAgendamento.removeAttribute('required');
    }
    
    if(!modalRegistroCamp) modalRegistroCamp = new bootstrap.Modal(document.getElementById('modalRegistroCampanha'));
    modalRegistroCamp.show();
}

function filtrarTabelaLotes() {
    let input = document.getElementById("filtroLotes").value.toLowerCase();
    let linhas = document.querySelectorAll("#aba-modal-importacoes tbody tr");

    linhas.forEach(linha => {
        if (linha.cells.length < 3) return; 
        
        let nomeLote = linha.cells[1].textContent.toLowerCase();
        let dataLote = linha.cells[2].textContent.toLowerCase();
        
        if (nomeLote.includes(input) || dataLote.includes(input)) {
            linha.style.display = "";
        } else {
            linha.style.display = "none";
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    var dropBtnInteg = document.getElementById('btnIntegracoes');
    var dropMenuInteg = document.getElementById('menuIntegracoes');
    if(dropBtnInteg && dropMenuInteg) {
        dropBtnInteg.addEventListener('click', function(e) { e.preventDefault(); e.stopPropagation(); dropMenuInteg.style.display = (dropMenuInteg.style.display === 'block') ? 'none' : 'block'; });
        document.addEventListener('click', function(e) { if(!dropBtnInteg.contains(e.target) && !dropMenuInteg.contains(e.target)) dropMenuInteg.style.display = 'none'; });
    }

    var dropBtnExport = document.getElementById('btnExportDropdownList');
    var dropMenuExport = document.getElementById('menuExportDropdownList');
    if(dropBtnExport && dropMenuExport) {
        dropBtnExport.addEventListener('click', function(e) { 
            e.preventDefault(); 
            e.stopPropagation(); 
            dropMenuExport.style.display = (dropMenuExport.style.display === 'block') ? 'none' : 'block'; 
        });
        document.addEventListener('click', function(e) { 
            if(!dropBtnExport.contains(e.target) && !dropMenuExport.contains(e.target)) {
                dropMenuExport.style.display = 'none'; 
            }
        });
    }
});

function verificarVazio(selectOperador) {
    const inputValor = selectOperador.closest('.linha-filtro').querySelector('.input-valor');
    if (selectOperador.value === 'vazio') {
        inputValor.value = ''; inputValor.setAttribute('readonly', 'readonly'); inputValor.setAttribute('placeholder', 'Operador não exige valor');
    } else {
        inputValor.removeAttribute('readonly'); inputValor.setAttribute('placeholder', 'Valor do filtro...');
    }
}

function adicionarFiltro() {
    const area = document.getElementById('areaFiltros');
    const primeiraLinha = area.querySelector('.linha-filtro');
    const novaLinha = primeiraLinha.cloneNode(true);
    novaLinha.querySelector('input').value = '';
    novaLinha.querySelector('input').removeAttribute('readonly');
    novaLinha.querySelector('input').setAttribute('placeholder', 'Valor do filtro...');
    area.appendChild(novaLinha);
}

document.getElementById('areaFiltros')?.addEventListener('click', function(e) {
    if (e.target.closest('.remover-linha')) {
        const area = document.getElementById('areaFiltros');
        if (area.querySelectorAll('.linha-filtro').length > 1) {
            e.target.closest('.linha-filtro').remove();
        } else { crmToast("Você precisa ter pelo menos uma regra de filtro!", "warning", 5000); }
    }
});
</script>
</div>
<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>