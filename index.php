<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Puxa a conexão com o banco para buscar as campanhas
$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (file_exists($caminho_conexao)) { 
    include_once $caminho_conexao; 
} else { 
    include_once 'conexao.php'; // Fallback
}

// 2. Identificação do Usuário Logado e Permissões Base
$cpf_logado = $_SESSION['usuario_cpf'] ?? '';
$grupo_usuario = strtoupper($_SESSION['usuario_grupo'] ?? 'ADMIN');
$is_master = in_array($grupo_usuario, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// Carrega permissões base antes de tudo
$caminho_permissoes_early = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes_early)) { include_once $caminho_permissoes_early; }

// Permissão de acesso ao módulo Campanhas
$temAcessoCampanhas = function_exists('verificaPermissao')
    ? verificaPermissao($pdo, 'MENU_CAMPANHAS', 'MENU')
    : $is_master;

// Hierarquia de campanhas — MASTER vê tudo, demais filtram por empresa
$is_master_camp = function_exists('verificaPermissao')
    ? verificaPermissao($pdo, 'SUBMENU_CAMPANHAS_HIERARQUIA', 'FUNCAO')
    : $is_master;

// Empresa do usuário logado (usada em múltiplos filtros)
$id_empresa_logado = null;
if (!$is_master_camp || !$is_master) {
    try {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$cpf_logado]);
        $id_empresa_logado = $stmtEmp->fetchColumn() ?: null;
    } catch (Exception $e) {}
}

// 3. Carrega permissões e dados V8 para os widgets do Hub
$temAcessoV8 = false;
$v8_chaves   = [];
$v8_historico = [];

try {
    // verificaPermissao já foi carregado acima

    // Verifica se o usuário tem acesso ao módulo V8
    $temAcessoV8 = function_exists('verificaPermissao')
        ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_DIGITAÇÃO', 'FUNCAO')
        : $is_master;

    if ($temAcessoV8) {
        $grupo_v8      = strtoupper($grupo_usuario);
        $is_master_v8  = in_array($grupo_v8, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
        $is_super_v8   = in_array($grupo_v8, ['SUPERVISORES', 'SUPERVISOR']);
        $v8_restricao_minha_fila = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_FILA_MEU_REGITRO', 'FUNCAO') : false;

        // Empresa do usuário logado (para SUPERVISOR)
        $v8_empresa_logado = $id_empresa_logado;
        if (!$v8_empresa_logado && $is_super_v8) {
            $stmtES = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtES->execute([$cpf_logado]);
            $v8_empresa_logado = $stmtES->fetchColumn() ?: null;
        }

        // --- Widget 1: Lotes Ativos ---
        // Hierarquia baseada no DONO DA CHAVE: Lote → CHAVE_ID → CPF_USUARIO → id_empresa
        $sqlLotes = "SELECT l.ID, l.NOME_IMPORTACAO, l.STATUS_FILA, l.STATUS_LOTE,
                            l.QTD_TOTAL, l.QTD_PROCESSADA, l.PROCESSADOS_HOJE, l.LIMITE_DIARIO,
                            l.HORA_INICIO_DIARIO, l.HORA_FIM_DIARIO,
                            DATE_FORMAT(l.DATA_IMPORTACAO, '%d/%m/%Y %H:%i') as DATA_IMPORTACAO_BR,
                            ca.CLIENTE_NOME as CHAVE_NOME, ca.USERNAME_API, ca.TABELA_PADRAO, ca.PRAZO_PADRAO,
                            ca.CPF_USUARIO as CHAVE_CPF_DONO,
                            cu_dono.NOME as NOME_USUARIO, cu_dono.id_empresa as EMPRESA_DONO
                     FROM INTEGRACAO_V8_IMPORTACAO_LOTE l
                     LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ca ON ca.ID = l.CHAVE_ID
                     LEFT JOIN CLIENTE_USUARIO cu_dono ON cu_dono.CPF = ca.CPF_USUARIO
                     WHERE l.STATUS_LOTE = 'ATIVO'";
        $paramsLotes = [];
        if ($is_master_v8) {
            // MASTER: todos os lotes ativos, independente do dono da chave
        } elseif ($is_super_v8 && $v8_empresa_logado) {
            // SUPERVISOR: lotes cuja chave pertence a usuário da mesma empresa
            $sqlLotes .= " AND cu_dono.id_empresa = ?";
            $paramsLotes[] = $v8_empresa_logado;
        } else {
            // CONSULTOR: apenas lotes cuja chave pertence ao próprio usuário
            $sqlLotes .= " AND ca.CPF_USUARIO = ?";
            $paramsLotes[] = $cpf_logado;
        }
        $sqlLotes .= " ORDER BY l.ID DESC";

        $stmtLotes = $pdo->prepare($sqlLotes);
        $stmtLotes->execute($paramsLotes);
        $v8_lotes = $stmtLotes->fetchAll(PDO::FETCH_ASSOC);

        // Calcula funil por lote (a partir da tabela V8_LOTE_{ID})
        foreach ($v8_lotes as &$lote) {
            $tbl = 'V8_LOTE_' . $lote['ID'];
            $funil = ['na_fila'=>0,'c_ok'=>0,'c_err'=>0,'m_ok'=>0,'m_err'=>0,'s_ok'=>0,'s_err'=>0,'dataprev'=>0,'c_hoje'=>0,'s_hoje'=>0];
            try {
                $stmtF = $pdo->query("SELECT STATUS_V8, COUNT(*) as qtd FROM `{$tbl}` GROUP BY STATUS_V8");
                foreach ($stmtF->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $st = strtoupper($row['STATUS_V8']); $q = (int)$row['qtd'];
                    if ($st === 'NA FILA') { $funil['na_fila'] += $q; }
                    elseif (strpos($st,'ERRO CONSULTA')!==false || strpos($st,'ERRO SALDO')!==false) { $funil['c_err'] += $q; }
                    elseif (strpos($st,'AGUARDANDO MARGEM')!==false || strpos($st,'RECUPERAR V8')!==false) { $funil['c_ok'] += $q; }
                    elseif ($st==='AGUARDANDO DATAPREV') { $funil['c_ok'] += $q; $funil['dataprev'] += $q; }
                    elseif (strpos($st,'ERRO MARGEM')!==false) { $funil['c_ok'] += $q; $funil['m_err'] += $q; }
                    elseif (strpos($st,'AGUARDANDO SIMULACAO')!==false) { $funil['c_ok'] += $q; $funil['m_ok'] += $q; }
                    elseif (strpos($st,'ERRO SIMULACAO')!==false) { $funil['c_ok'] += $q; $funil['m_ok'] += $q; $funil['s_err'] += $q; }
                    elseif ($st==='OK') { $funil['c_ok'] += $q; $funil['m_ok'] += $q; $funil['s_ok'] += $q; }
                }
                $stmtH = $pdo->query("SELECT
                    SUM(CASE WHEN DATA_CONSENTIMENTO >= CURDATE() THEN 1 ELSE 0 END) as c_hoje,
                    SUM(CASE WHEN DATA_SIMULACAO >= CURDATE() AND STATUS_V8 = 'OK' THEN 1 ELSE 0 END) as s_hoje
                    FROM `{$tbl}`");
                $hj = $stmtH->fetch(PDO::FETCH_ASSOC);
                $funil['c_hoje'] = (int)($hj['c_hoje'] ?? 0);
                $funil['s_hoje'] = (int)($hj['s_hoje'] ?? 0);
                $funil['m_hoje'] = $funil['s_hoje'];
            } catch (Exception $e) {}
            $lote['funil'] = $funil;
        }
        unset($lote);

        // --- Widget 2: Histórico das últimas 10 consultas ---
        // Hierarquia: via CHAVE → dono da chave → empresa do dono
        $whereHist = " WHERE 1=1 ";
        $paramsHist = [];
        if ($is_master_v8) {
            // MASTER: tudo
        } elseif ($is_super_v8 && $v8_empresa_logado) {
            // SUPERVISOR: consultas cuja chave pertence a usuário da mesma empresa
            $whereHist .= " AND cu_chave.id_empresa = ? ";
            $paramsHist[] = $v8_empresa_logado;
        } else {
            // CONSULTOR: apenas consultas cuja chave pertence ao próprio usuário
            $whereHist .= " AND ch.CPF_USUARIO = ? ";
            $paramsHist[] = $cpf_logado;
        }

        $sqlHist = "SELECT c.ID, c.CPF_CONSULTADO, c.NOME_COMPLETO, c.STATUS_V8,
                           c.CONSULT_ID, c.FONTE_CONSULT_ID, c.MENSAGEM_ERRO,
                           DATE_FORMAT(c.DATA_FILA, '%d/%m/%Y %H:%i') as DATA_FILA_BR,
                           DATE_FORMAT(c.ULTIMA_ATUALIZACAO, '%d/%m/%Y %H:%i') as DATA_RETORNO_BR,
                           s.CONFIG_ID, s.FONTE_CONSIG_ID, s.MARGEM_DISPONIVEL as VALOR_MARGEM,
                           s.PRAZOS_DISPONIVEIS as PRAZOS, s.SIMULATION_ID,
                           s.STATUS_CONFIG_ID, s.OBS_SIMULATION_ID,
                           s.VALOR_LIBERADO, s.VALOR_PARCELA, s.PRAZO_SIMULACAO,
                           DATE_FORMAT(s.DATA_CONFIG_ID, '%d/%m/%Y %H:%i') as DATA_CONFIG_BR,
                           p.NUMERO_PROPOSTA, p.STATUS_PROPOSTA_V8 as STATUS_PROPOSTA_REAL,
                           ch.CLIENTE_NOME, ch.TABELA_PADRAO, ch.PRAZO_PADRAO, ch.USERNAME_API,
                           u.NOME as NOME_USUARIO
                    FROM INTEGRACAO_V8_REGISTROCONSULTA c
                    LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO s
                        ON s.ID = (SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1)
                    LEFT JOIN INTEGRACAO_V8_REGISTRO_PROPOSTA p
                        ON c.STATUS_V8 LIKE CONCAT('%', p.NUMERO_PROPOSTA, '%')
                    LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO ch ON c.CHAVE_ID = ch.ID
                    LEFT JOIN CLIENTE_USUARIO cu_chave ON cu_chave.CPF COLLATE utf8mb4_unicode_ci = ch.CPF_USUARIO COLLATE utf8mb4_unicode_ci
                    LEFT JOIN CLIENTE_USUARIO u ON c.CPF_USUARIO COLLATE utf8mb4_unicode_ci = u.CPF COLLATE utf8mb4_unicode_ci
                    $whereHist
                    ORDER BY c.DATA_FILA DESC LIMIT 10";

        $stmtHist = $pdo->prepare($sqlHist);
        $stmtHist->execute($paramsHist);
        $v8_historico = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Silencia erros nos widgets V8 (tabelas podem não existir)
}

// 4. Busca as Campanhas Ativas (Calculando Total e Restantes)
$campanhas_ativas = [];
try {
    if ($temAcessoCampanhas) {
        $sqlCamp = "
            SELECT c.ID, c.NOME_CAMPANHA,
                   (SELECT COUNT(ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID) as TOTAL_CLIENTES,
                   (SELECT COUNT(cl.ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cl WHERE cl.ID_CAMPANHA = c.ID AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = cl.CPF_CLIENTE AND r.DATA_REGISTRO >= cl.DATA_INCLUSAO)) as RESTANTES,
                   (SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID ORDER BY ID ASC LIMIT 1) as PRIMEIRO_CPF
            FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
            WHERE c.STATUS = 'ATIVO'
        ";
        $paramsCamp = [];

        if ($is_master_camp) {
            // MASTER: vê todas as campanhas ativas
        } elseif ($id_empresa_logado) {
            // Filtra por empresa numérica (novo padrão) OU por CNPJ_EMPRESA (legado, sem id_empresa)
            // OU campanhas totalmente sem empresa E sem CNPJ (globais reais)
            $sqlCamp .= " AND (
                c.id_empresa = ?
                OR c.CPF_USUARIO = ?
                OR (c.id_empresa IS NULL AND c.CNPJ_EMPRESA IS NULL)
                OR (c.id_empresa IS NULL AND EXISTS(
                    SELECT 1 FROM CLIENTE_EMPRESAS ce
                    WHERE ce.CNPJ COLLATE utf8mb4_unicode_ci = c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci
                      AND ce.ID = ?
                ))
            )";
            $paramsCamp[] = $id_empresa_logado;
            $paramsCamp[] = $cpf_logado;
            $paramsCamp[] = $id_empresa_logado;
        } else {
            // Sem empresa definida: só campanhas sem empresa nenhuma ou atribuídas ao CPF
            $sqlCamp .= " AND (c.id_empresa IS NULL AND c.CNPJ_EMPRESA IS NULL OR c.CPF_USUARIO = ?)";
            $paramsCamp[] = $cpf_logado;
        }

        $sqlCamp .= " ORDER BY c.NOME_CAMPANHA ASC";
        $stmtCamp = $pdo->prepare($sqlCamp);
        $stmtCamp->execute($paramsCamp);
        $campanhas_ativas = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $erro_db = "Erro ao buscar campanhas: " . $e->getMessage();
}

// 5. Avisos Internos para o painel do hub
$avisos_hub      = [];
$avisos_hub_nao_lidos = 0;
try {
    $cpf_logado_clean = preg_replace('/\D/', '', $cpf_logado);
    $grupo_hub        = strtoupper($_SESSION['usuario_grupo'] ?? '');

    $id_empresa_hub = null;
    $stmtEmpHub = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtEmpHub->execute([$cpf_logado_clean]);
    $id_empresa_hub = $stmtEmpHub->fetchColumn() ?: null;

    $stmtAv = $pdo->prepare("
        SELECT a.ID, a.ASSUNTO, a.CONTEUDO, a.TIPO, a.NOME_CRIADOR, a.DATA_CRIACAO,
               (SELECT ID FROM AVISOS_INTERNOS_LEITURA WHERE AVISO_ID = a.ID AND CPF_USUARIO = ?) as LIDO_ID
        FROM AVISOS_INTERNOS a
        WHERE EXISTS (
            SELECT 1 FROM AVISOS_INTERNOS_DESTINATARIOS d
            WHERE d.AVISO_ID = a.ID AND (
                d.TIPO_DEST = 'TODOS'
                OR (d.TIPO_DEST = 'GRUPO'   AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'USUARIO' AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'EMPRESA' AND d.VALOR = ?)
            )
        )
        ORDER BY a.DATA_CRIACAO DESC
        LIMIT 50
    ");
    $stmtAv->execute([$cpf_logado_clean, $grupo_hub, $cpf_logado_clean, (string)$id_empresa_hub]);
    $avisos_hub = $stmtAv->fetchAll(PDO::FETCH_ASSOC);
    $avisos_hub_nao_lidos = count(array_filter($avisos_hub, fn($a) => !$a['LIDO_ID']));
} catch (Exception $e) {}

// 6. Puxa o menu superior de forma segura
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
if (file_exists($caminho_header)) { 
    include $caminho_header; 
} else { 
    include 'includes/header.php'; // Fallback
}
?>

<style>
    /* ESTILIZAÇÃO BASEADA NO PRINT ENVIADO */
    .barra-titulo-campanha {
        background-color: #e4e2d7;
        border: 1px solid #a3a196;
        color: #333;
        font-weight: 600;
        padding: 5px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
        cursor: pointer;
        border-radius: 3px;
        user-select: none;
    }
    
    .barra-titulo-campanha:hover {
        background-color: #d8d6cc;
    }

    .box-campanhas {
        border: 1px solid #4cae4c;
        border-radius: 4px;
        padding: 15px;
        background-color: #ffffff;
        margin-top: 5px;
    }

    .btn-camp-hub {
        background-color: #4cae4c; /* Verde Estilo Imagem */
        color: #ffffff;
        border: 2px solid #ffffff;
        outline: 2px solid #4cae4c;
        border-radius: 0; /* Quadrado */
        display: flex;
        align-items: center;
        text-decoration: none;
        min-height: 80px;
        position: relative;
        padding: 10px;
        transition: background-color 0.2s;
        overflow: hidden;
    }

    .btn-camp-hub:hover {
        background-color: #449d44;
        color: #ffffff;
        outline-color: #449d44;
    }

    /* Ícone de fundo marca d'água */
    .btn-camp-hub .icon-bg {
        font-size: 38px;
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.2;
        color: #000;
    }

    .btn-camp-hub .content {
        margin-left: 45px; /* Espaço para o ícone */
        width: 100%;
        text-align: center;
    }

    .btn-camp-hub .nome {
        font-weight: 800;
        font-size: 0.75rem;
        text-transform: uppercase;
        display: block;
        margin-bottom: 5px;
        line-height: 1.2;
    }

    .btn-camp-hub .stats {
        font-size: 0.65rem;
        background: rgba(0,0,0,0.25);
        padding: 2px 6px;
        border-radius: 3px;
        font-weight: 600;
        display: inline-block;
    }

    /* Botão Cinza para Campanhas Vazias */
    .btn-camp-vazia {
        background-color: #888888;
        outline-color: #888888;
        cursor: not-allowed;
    }
    .btn-camp-vazia:hover {
        background-color: #777777;
        outline-color: #777777;
    }

    /* ===== V8 Hub Widgets ===== */
    .barra-titulo-v8 {
        background-color: #d4e6f1;
        border: 1px solid #5dade2;
        color: #1a5276;
        font-weight: 600;
        padding: 5px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
        cursor: pointer;
        border-radius: 3px;
        user-select: none;
    }
    .barra-titulo-v8:hover { background-color: #c3daf0; }

    .box-v8 {
        border: 1px solid #5dade2;
        border-radius: 4px;
        padding: 15px;
        background-color: #ffffff;
        margin-top: 5px;
    }

    /* Card V8 — estilo igual ao do módulo */
    .card-v8-hub {
        border: 1px solid #dee2e6;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,.1);
        background: #fff;
        font-size: 0.78rem;
    }
    .card-v8-hub .v8h-header {
        background-color: #1a2332;
        color: #fff;
        font-weight: 800;
        font-size: 0.72rem;
        text-transform: uppercase;
        padding: 7px 10px;
        line-height: 1.3;
    }
    .card-v8-hub .v8h-body {
        padding: 8px 10px;
    }
    .card-v8-hub .v8h-status-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .v8h-dot-ativo  { color: #27ae60; font-weight: 700; }
    .v8h-dot-inativo{ color: #95a5a6; font-weight: 700; }
    .btn-v8h-acoes {
        font-size: 0.65rem;
        padding: 2px 8px;
        font-weight: 700;
        background: #343a40;
        color: #fff;
        border: none;
        border-radius: 4px;
        text-decoration: none;
        white-space: nowrap;
    }
    .btn-v8h-acoes:hover { background: #495057; color:#fff; }
    .btn-v8h-acoes.disabled { background:#adb5bd; cursor:default; pointer-events:none; }
    .card-v8-hub .v8h-metric-row {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
        font-size: 0.72rem;
    }
    .card-v8-hub .v8h-metric-row .v8h-icon {
        width: 18px;
        text-align: center;
        color: #6c757d;
    }
    .card-v8-hub .v8h-metric-row .v8h-label {
        flex: 1;
        color: #333;
        font-weight: 600;
    }
    .card-v8-hub .v8h-metric-row .v8h-val {
        font-weight: 700;
        color: #1a2332;
    }
    .badge-hj {
        font-size: 0.6rem;
        background: #0dcaf0;
        color: #000;
        border-radius: 10px;
        padding: 1px 6px;
        font-weight: 700;
        white-space: nowrap;
    }
    .card-v8-hub .v8h-total-row {
        margin-top: 6px;
        border-top: 1px solid #e9ecef;
        padding-top: 5px;
        font-size: 0.72rem;
        color: #555;
        font-weight: 600;
    }

    /* Botões hub toggle — estado ativo */
    .btn-hub-toggle.active {
        background-color: #343a40;
        color: #fff;
        border-color: #343a40;
    }
    .btn-hub-toggle.active i { color: #fff !important; }

    /* Tabela histórico — espelho do módulo */
    .tbl-v8-hist { font-size: 0.73rem; }
    .tbl-v8-hist th {
        background-color: #343a40;
        color: #fff;
        font-size: 0.68rem;
        text-transform: uppercase;
        text-align: center;
        vertical-align: middle;
        white-space: nowrap;
    }
    .tbl-v8-hist td { text-align: center; vertical-align: middle; }
</style>

<?php if (isset($erro_db)): ?>
    <div class="alert alert-danger fw-bold border-dark shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> <?= $erro_db ?></div>
<?php endif; ?>

<!-- BARRA DE BOTÕES DO HUB -->
<div class="d-flex flex-wrap gap-2 mb-4">
    <?php if ($temAcessoCampanhas): ?>
    <button class="btn btn-outline-dark fw-bold shadow-sm btn-hub-toggle" onclick="hubToggle('painelCampanhas', this)">
        <i class="far fa-newspaper me-2 text-warning"></i> Campanhas em Andamento
    </button>
    <?php endif; ?>
    <?php if ($temAcessoV8): ?>
    <button class="btn btn-outline-dark fw-bold shadow-sm btn-hub-toggle" onclick="hubToggle('painelV8Chaves', this)">
        <i class="fas fa-layer-group me-2 text-primary"></i> V8 CLT — Lotes Ativos
    </button>
    <button class="btn btn-outline-dark fw-bold shadow-sm btn-hub-toggle" onclick="hubToggle('painelV8Hist', this)">
        <i class="fas fa-history me-2 text-info"></i> V8 CLT — Histórico Consulta
    </button>
    <?php endif; ?>

    <!-- BOTÃO AVISOS -->
    <button class="btn btn-outline-dark fw-bold shadow-sm btn-hub-toggle position-relative" onclick="hubToggle('painelAvisos', this)">
        <i class="fas fa-bell me-2 text-danger"></i> Avisos
        <?php if ($avisos_hub_nao_lidos > 0): ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.55rem; min-width:18px;" id="badge-hub-avisos"><?= $avisos_hub_nao_lidos ?></span>
        <?php else: ?>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none" style="font-size:0.55rem; min-width:18px;" id="badge-hub-avisos"></span>
        <?php endif; ?>
    </button>
</div>

<?php if ($temAcessoCampanhas): ?>
<div id="painelCampanhas" class="hub-painel mb-4" style="display:none;">
    <div class="box-campanhas shadow-sm">
        <div class="row g-3">
            <?php if (empty($campanhas_ativas)): ?>
                <div class="col-12 text-center py-3">
                    <span class="text-muted fw-bold fst-italic">Nenhuma campanha ativa no momento.</span>
                </div>
            <?php else: ?>
                <?php foreach ($campanhas_ativas as $camp): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                        <?php if ($camp['TOTAL_CLIENTES'] > 0 && !empty($camp['PRIMEIRO_CPF'])): ?>
                            <a href="/modulos/banco_dados/consulta.php?id_campanha=<?= $camp['ID'] ?>&busca=<?= $camp['PRIMEIRO_CPF'] ?>&cpf_selecionado=<?= $camp['PRIMEIRO_CPF'] ?>&acao=visualizar" class="btn-camp-hub">
                                <i class="fas fa-headset icon-bg"></i>
                                <div class="content">
                                    <span class="nome"><?= htmlspecialchars($camp['NOME_CAMPANHA']) ?></span>
                                    <span class="stats">Total: <?= number_format($camp['TOTAL_CLIENTES'], 0, ',', '.') ?> | Restante: <?= number_format($camp['RESTANTES'], 0, ',', '.') ?></span>
                                </div>
                            </a>
                        <?php else: ?>
                            <div class="btn-camp-hub btn-camp-vazia" title="Nenhum cliente inserido nesta campanha.">
                                <i class="fas fa-headset icon-bg"></i>
                                <div class="content">
                                    <span class="nome"><?= htmlspecialchars($camp['NOME_CAMPANHA']) ?></span>
                                    <span class="stats text-warning">Vazia (Sem Clientes)</span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; // temAcessoCampanhas ?>

<?php if ($temAcessoV8): ?>

<!-- ====== WIDGET 1: V8 CLT - LOTES ATIVOS ====== -->
<div id="painelV8Chaves" class="hub-painel mb-4" style="display:none;">
    <div class="box-v8 shadow-sm">
        <div class="row g-3">
            <?php if (empty($v8_lotes)): ?>
                <div class="col-12 text-center py-3">
                    <span class="text-muted fw-bold fst-italic">Nenhum lote ativo no momento.</span>
                </div>
            <?php else: ?>
                <?php foreach ($v8_lotes as $lote):
                    $funil   = $lote['funil'];
                    $total   = (int)$lote['QTD_TOTAL'];
                    $proc    = (int)$lote['QTD_PROCESSADA'];
                    $pct     = $total > 0 ? round($proc / $total * 100) : 0;
                    $sfila   = strtoupper($lote['STATUS_FILA'] ?? '');
                    // Badge de status
                    if ($sfila === 'PROCESSANDO')         { $badgeCls = 'bg-success'; $badgeTxt = 'RODANDO'; }
                    elseif ($sfila === 'PAUSADO')          { $badgeCls = 'bg-warning text-dark'; $badgeTxt = 'PAUSADO'; }
                    elseif (str_contains($sfila,'AGUARD')) { $badgeCls = 'bg-info text-dark'; $badgeTxt = 'AGUARDANDO'; }
                    elseif ($sfila === 'CONCLUIDO')        { $badgeCls = 'bg-secondary'; $badgeTxt = 'CONCLUÍDO'; }
                    else                                   { $badgeCls = 'bg-dark'; $badgeTxt = $sfila ?: 'PENDENTE'; }
                    // URL do lote
                    $urlLote = "/modulos/configuracao/v8_clt_margem_e_digitacao/index.php?tab=lote";
                ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                        <div class="card-v8-hub">
                            <div class="v8h-header" style="font-size:0.68rem; line-height:1.3;">
                                <?= htmlspecialchars($lote['NOME_IMPORTACAO']) ?>
                                <div style="font-size:0.6rem; opacity:0.75; margin-top:2px;">
                                    <?= htmlspecialchars($lote['CHAVE_NOME'] ?? '--') ?>
                                </div>
                            </div>
                            <div class="v8h-body">
                                <!-- Status + botão -->
                                <div class="v8h-status-row">
                                    <span class="badge <?= $badgeCls ?>" style="font-size:0.6rem;"><?= $badgeTxt ?> · <?= $pct ?>%</span>
                                    <a href="<?= $urlLote ?>" class="btn-v8h-acoes"><i class="fas fa-external-link-alt me-1"></i>Acesse Aqui</a>
                                </div>
                                <!-- Barra de progresso -->
                                <div class="progress mb-2" style="height:5px;">
                                    <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                                </div>
                                <!-- Na fila -->
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon" style="color:#dc3545;"><i class="fas fa-list-ol"></i></span>
                                    <span class="v8h-label">Na Fila:</span>
                                    <span class="v8h-val text-danger"><?= number_format($funil['na_fila'], 0, ',', '.') ?></span>
                                </div>
                                <?php if ($funil['dataprev'] > 0): ?>
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon" style="color:#6f42c1;"><i class="fas fa-university"></i></span>
                                    <span class="v8h-label">Dataprev:</span>
                                    <span class="v8h-val" style="color:#6f42c1;"><?= $funil['dataprev'] ?></span>
                                </div>
                                <?php endif; ?>
                                <!-- Consen -->
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-credit-card"></i></span>
                                    <span class="v8h-label">Consen.:</span>
                                    <span class="v8h-val"><?= $funil['c_ok'] ?></span>
                                    <?php if ($funil['c_err']): ?><span class="badge bg-danger" style="font-size:0.55rem;"><?= $funil['c_err'] ?> err</span><?php endif; ?>
                                    <span class="badge-hj"><?= $funil['c_hoje'] ?> hj</span>
                                </div>
                                <!-- Margem -->
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-search"></i></span>
                                    <span class="v8h-label">Margem:</span>
                                    <span class="v8h-val"><?= $funil['m_ok'] ?></span>
                                    <?php if ($funil['m_err']): ?><span class="badge bg-danger" style="font-size:0.55rem;"><?= $funil['m_err'] ?> err</span><?php endif; ?>
                                    <span class="badge-hj"><?= $funil['m_hoje'] ?> hj</span>
                                </div>
                                <!-- Simul -->
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-file-alt"></i></span>
                                    <span class="v8h-label">Simul.:</span>
                                    <span class="v8h-val"><?= $funil['s_ok'] ?></span>
                                    <?php if ($funil['s_err']): ?><span class="badge bg-danger" style="font-size:0.55rem;"><?= $funil['s_err'] ?> err</span><?php endif; ?>
                                    <span class="badge-hj"><?= $funil['s_hoje'] ?> hj</span>
                                </div>
                                <!-- Total -->
                                <div class="v8h-total-row">
                                    <i class="fas fa-users me-1 text-muted"></i>
                                    Total: <?= number_format($total, 0, ',', '.') ?>
                                    &nbsp;·&nbsp; Limit./dia: <?= number_format((int)$lote['LIMITE_DIARIO'], 0, ',', '.') ?>
                                    <?php if ($lote['HORA_INICIO_DIARIO']): ?>
                                    &nbsp;·&nbsp; <?= $lote['HORA_INICIO_DIARIO'] ?>–<?= substr($lote['HORA_FIM_DIARIO'],0,5) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ====== WIDGET 2: V8 CLT - HISTÓRICO CONSULTA ====== -->
<div id="painelV8Hist" class="hub-painel mb-5" style="display:none;">
        <div class="box-v8 shadow-sm">
            <?php if (empty($v8_historico)): ?>
                <div class="text-center py-3">
                    <span class="text-muted fw-bold fst-italic">Nenhuma consulta registrada.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive border border-dark rounded shadow-sm" style="max-height:520px; overflow-y:auto;">
                    <table class="table table-hover table-bordered table-sm align-middle mb-0 tbl-v8-hist">
                        <thead>
                            <tr>
                                <th>ID / Data</th>
                                <th>Cliente e Origem</th>
                                <th class="border-start border-end border-danger">Autorização ID Consulta</th>
                                <th class="border-end border-danger">ID Config (Margem)</th>
                                <th class="border-end border-danger">ID Simulação</th>
                                <th class="border-end border-danger">ID Proposta</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                        <?php foreach ($v8_historico as $h):
                            // ---- Coluna Autorização ----
                            $fontAuth   = !empty($h['FONTE_CONSULT_ID']) ? $h['FONTE_CONSULT_ID'] : 'NOVO CONSENTIMENTO';
                            $consultVis = !empty($h['CONSULT_ID']) ? mb_substr($h['CONSULT_ID'], 0, 8) : 'N/A';
                            $st = strtoupper($h['STATUS_V8'] ?? '');
                            if (strpos($st,'ERRO') !== false || strpos($st,'ERROR') !== false) {
                                $badgeAuth = '<span class="badge bg-danger mb-1">' . htmlspecialchars($h['STATUS_V8']) . '</span>';
                            } elseif (!empty($h['CONSULT_ID'])) {
                                $badgeAuth = '<span class="badge bg-success mb-1">OK-CONSENTIMENTO</span>';
                            } else {
                                $badgeAuth = '<span class="badge bg-secondary mb-1">' . htmlspecialchars($h['STATUS_V8'] ?? 'AGUARDANDO') . '</span>';
                            }

                            // ---- Coluna Margem ----
                            $fontMargem = !empty($h['FONTE_CONSIG_ID']) ? $h['FONTE_CONSIG_ID'] : 'V8';
                            if (!empty($h['VALOR_MARGEM'])) {
                                $prazosF = '24x';
                                if (!empty($h['PRAZOS'])) {
                                    $arr = json_decode($h['PRAZOS'], true);
                                    $prazosF = is_array($arr) ? implode(', ', $arr) . 'x' : $h['PRAZOS'];
                                }
                                $colMargem = '<span class="badge bg-secondary rounded-pill mb-1" style="font-size:8px;">FONTE: ' . htmlspecialchars($fontMargem) . '</span><br>'
                                           . '<span class="badge bg-success mb-1">MARGEM OK</span><br>'
                                           . '<span class="text-success fw-bold">R$ ' . htmlspecialchars($h['VALOR_MARGEM']) . '</span><br>'
                                           . '<small class="text-dark d-block" style="font-size:10px;">Prazos: ' . htmlspecialchars($prazosF) . '</small>';
                            } else {
                                $colMargem = '<span class="badge bg-light text-muted border">Vazio</span>';
                            }

                            // ---- Coluna Simulação ----
                            if (!empty($h['SIMULATION_ID'])) {
                                $colSimul = '<span class="badge bg-success mb-1">SIMULADO</span><br>'
                                          . '<b class="text-success fs-6">R$ ' . htmlspecialchars($h['VALOR_LIBERADO'] ?? '0.00') . '</b><br>'
                                          . '<small class="text-dark" style="font-size:10px;">Parcela: R$ ' . htmlspecialchars($h['VALOR_PARCELA'] ?? '0.00')
                                          . '<br>Prazo: ' . htmlspecialchars($h['PRAZO_SIMULACAO'] ?? 24) . 'x</small>';
                            } else {
                                $colSimul = '<span class="badge bg-light text-muted border">Vazio</span>';
                            }

                            // ---- Coluna Proposta ----
                            $colProp = !empty($h['NUMERO_PROPOSTA'])
                                ? '<span class="badge bg-primary">' . htmlspecialchars($h['NUMERO_PROPOSTA']) . '</span>'
                                : '<span class="badge bg-light text-muted border">Vazio</span>';
                        ?>
                            <tr>
                                <td class="text-nowrap" style="min-width:100px;">
                                    <b class="text-primary">#<?= (int)$h['ID'] ?></b><br>
                                    <small class="text-muted"><?= htmlspecialchars($h['DATA_FILA_BR']) ?></small>
                                </td>
                                <td style="min-width:160px; text-align:left;">
                                    <b><?= htmlspecialchars($h['CPF_CONSULTADO']) ?></b><br>
                                    <span class="text-dark"><?= htmlspecialchars($h['NOME_COMPLETO'] ?? '--') ?></span><br>
                                    <small class="text-muted">Usuário: <?= htmlspecialchars($h['NOME_USUARIO'] ?? '--') ?></small><br>
                                    <small class="text-muted">API: <?= htmlspecialchars($h['USERNAME_API'] ?? '--') ?></small><br>
                                    <small class="text-muted">Tabela: <?= htmlspecialchars($h['TABELA_PADRAO'] ?? '--') ?> &nbsp;·&nbsp; Prazo: <?= (int)($h['PRAZO_PADRAO'] ?? 24) ?>x</small>
                                </td>
                                <td style="min-width:130px;">
                                    <span class="badge bg-dark rounded-pill mb-1" style="font-size:8px;">FONTE: <?= htmlspecialchars($fontAuth) ?></span><br>
                                    <?= $badgeAuth ?>
                                    <?php if (!empty($h['CONSULT_ID'])): ?>
                                        <br><small class="text-muted">ID: <?= htmlspecialchars($consultVis) ?></small>
                                        <?php if (!empty($h['DATA_RETORNO_BR'])): ?><br><small class="text-muted"><?= htmlspecialchars($h['DATA_RETORNO_BR']) ?></small><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td style="min-width:130px;"><?= $colMargem ?></td>
                                <td style="min-width:130px;"><?= $colSimul ?></td>
                                <td style="min-width:100px;"><?= $colProp ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="text-end mt-2">
                <a href="/modulos/configuracao/v8_clt_margem_e_digitacao/index.php" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-external-link-alt me-1"></i> Ver módulo completo V8
                </a>
            </div>
        </div>
    </div>
</div>

<!-- PAINEL AVISOS INTERNOS -->
<div id="painelAvisos" class="hub-painel mb-4" style="display:none;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="fw-bold text-dark mb-0">
            <i class="fas fa-bell text-danger me-2"></i> Meus Avisos
            <?php if ($avisos_hub_nao_lidos > 0): ?>
            <span class="badge bg-danger ms-1" id="badge-avisos-painel-count"><?= $avisos_hub_nao_lidos ?> não lido<?= $avisos_hub_nao_lidos > 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </h6>
        <?php if ($avisos_hub_nao_lidos > 0): ?>
        <button class="btn btn-sm btn-outline-success fw-bold border-dark" onclick="hubMarcarTodosLidos()">
            <i class="fas fa-check-double me-1"></i> Marcar todos como lido
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($avisos_hub)): ?>
    <div class="text-center py-5 text-muted">
        <i class="fas fa-bell-slash fa-2x mb-3 d-block opacity-25"></i>
        <span class="fw-bold">Nenhum aviso para você no momento.</span>
    </div>
    <?php else: ?>
    <div class="row g-3" id="lista-avisos-hub">
        <?php foreach ($avisos_hub as $av):
            $lido = !empty($av['LIDO_ID']);
        ?>
        <div class="col-xl-4 col-lg-6 col-12" id="hub-aviso-card-<?= $av['ID'] ?>">
            <div class="border rounded shadow-sm p-3 h-100 position-relative" style="border-left: 4px solid <?= $lido ? '#198754' : '#dc3545' ?> !important; background: <?= $lido ? '#f8fff9' : '#fff8f8' ?>; opacity: <?= $lido ? '0.75' : '1' ?>;">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="d-flex align-items-center gap-2">
                        <?php if (!$lido): ?>
                        <span class="badge bg-danger" style="font-size:0.55rem;">Não lido</span>
                        <?php endif; ?>
                        <span class="badge text-white" style="font-size:0.55rem; background: <?= $av['TIPO']==='AUTOMATICO'?'#6f42c1':'#0d6efd' ?>;">
                            <?= $av['TIPO']==='AUTOMATICO' ? 'AUTO' : 'MASTER' ?>
                        </span>
                        <small class="text-muted" style="font-size:0.68rem;"><?= date('d/m/Y H:i', strtotime($av['DATA_CRIACAO'])) ?></small>
                    </div>
                </div>
                <div class="fw-bold text-dark mb-1" style="font-size:0.88rem;"><?= htmlspecialchars($av['ASSUNTO']) ?></div>
                <?php if (!empty($av['NOME_CRIADOR'])): ?>
                <small class="text-muted d-block mb-2" style="font-size:0.7rem;">por <?= htmlspecialchars($av['NOME_CRIADOR']) ?></small>
                <?php endif; ?>
                <div class="text-muted small mb-3" style="overflow:hidden; max-height:36px; font-size:0.78rem; line-height:1.4;">
                    <?= strip_tags($av['CONTEUDO']) ?>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-outline-dark fw-bold flex-fill" style="font-size:0.72rem;" onclick="hubVerAviso(<?= $av['ID'] ?>, '<?= addslashes(htmlspecialchars($av['ASSUNTO'])) ?>', <?= $lido ? 'true' : 'false' ?>)">
                        <i class="fas fa-eye me-1"></i> Ler
                    </button>
                    <?php if (!$lido): ?>
                    <button class="btn btn-sm btn-outline-success fw-bold" style="font-size:0.72rem;" onclick="hubMarcarLido(<?= $av['ID'] ?>)">
                        <i class="fas fa-check me-1"></i> Lido
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="text-end mt-3">
        <a href="/modulos/configuracao/anotacoes/index.php#avisos" class="btn btn-sm btn-outline-secondary fw-bold">
            <i class="fas fa-external-link-alt me-1"></i> Ver módulo completo de Avisos
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Ler Aviso (hub) -->
<div class="modal fade" id="modalHubAviso" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-bell text-danger me-2"></i> <span id="hubAvisoAssunto"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white" id="hubAvisoConteudo" style="min-height:180px; font-size:0.95rem; line-height:1.7;"></div>
            <div class="modal-footer bg-light">
                <button type="button" id="btnHubMarcarLidoModal" class="btn btn-success fw-bold border-dark d-none" onclick="hubMarcarLidoModal()">
                    <i class="fas fa-check me-1"></i> Marcar como Lido
                </button>
                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
function hubToggle(painelId, btn) {
    var painel = document.getElementById(painelId);
    var aberto = painel.style.display !== 'none';
    document.querySelectorAll('.hub-painel').forEach(function(p) { p.style.display = 'none'; });
    document.querySelectorAll('.btn-hub-toggle').forEach(function(b) { b.classList.remove('active'); });
    if (!aberto) {
        painel.style.display = '';
        btn.classList.add('active');
    }
}

// ===== AVISOS INTERNOS — HUB =====
let _hubAvisoAtualId = null;

function hubVerAviso(id, assunto, jaLido) {
    _hubAvisoAtualId = id;
    document.getElementById('hubAvisoAssunto').textContent = assunto;
    document.getElementById('hubAvisoConteudo').innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin text-muted"></i></div>';
    const btnLido = document.getElementById('btnHubMarcarLidoModal');
    btnLido.classList.toggle('d-none', jaLido);
    new bootstrap.Modal(document.getElementById('modalHubAviso')).show();
    const fd = new FormData();
    fd.append('acao', 'get_aviso');
    fd.append('id', id);
    fetch('/modulos/configuracao/anotacoes/ajax_anotacao.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(j => {
            if (j.success) document.getElementById('hubAvisoConteudo').innerHTML = j.conteudo;
        });
}

function hubMarcarLidoModal() {
    if (_hubAvisoAtualId) {
        hubMarcarLido(_hubAvisoAtualId);
        bootstrap.Modal.getInstance(document.getElementById('modalHubAviso'))?.hide();
    }
}

async function hubMarcarLido(id) {
    const fd = new FormData();
    fd.append('acao', 'marcar_lido');
    fd.append('id', id);
    await fetch('/modulos/configuracao/anotacoes/ajax_anotacao.php', { method: 'POST', body: fd });
    // Atualiza o card visualmente
    const card = document.getElementById('hub-aviso-card-' + id);
    if (card) {
        const inner = card.querySelector('.border');
        if (inner) {
            inner.style.borderLeftColor = '#198754';
            inner.style.background = '#f8fff9';
            inner.style.opacity = '0.75';
        }
        const badgeNaoLido = card.querySelector('.badge.bg-danger');
        if (badgeNaoLido) badgeNaoLido.remove();
        const btnLido = card.querySelector('.btn-outline-success');
        if (btnLido) btnLido.remove();
    }
    _hubAtualizarBadges(-1);
    // Sincroniza sino do header
    const sinoH = document.getElementById('badge-avisos-h');
    if (sinoH) {
        const n = parseInt(sinoH.textContent || '1') - 1;
        n > 0 ? (sinoH.textContent = n, sinoH.classList.remove('d-none')) : sinoH.classList.add('d-none');
    }
}

async function hubMarcarTodosLidos() {
    const fd = new FormData();
    fd.append('acao', 'marcar_todos_lidos');
    await fetch('/modulos/configuracao/anotacoes/ajax_anotacao.php', { method: 'POST', body: fd });
    location.reload();
}

function _hubAtualizarBadges(delta) {
    const badgeHub    = document.getElementById('badge-hub-avisos');
    const badgeCount  = document.getElementById('badge-avisos-painel-count');
    if (badgeHub) {
        let n = (parseInt(badgeHub.textContent) || 0) + delta;
        n = Math.max(0, n);
        if (n > 0) { badgeHub.textContent = n; badgeHub.classList.remove('d-none'); }
        else { badgeHub.classList.add('d-none'); }
    }
    if (badgeCount) {
        let n = (parseInt(badgeCount.textContent) || 0) + delta;
        n = Math.max(0, n);
        badgeCount.textContent = n > 0 ? n + (n === 1 ? ' não lido' : ' não lidos') : '';
        if (n === 0) badgeCount.remove();
    }
}
</script>

<?php endif; ?>

</div> <?php // Fecha a div container-fluid do header ?>
<?php
// 5. Puxa o rodapé de forma segura
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { 
    include $caminho_footer; 
} else {
    include 'includes/footer.php'; // Fallback
}
?>