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
        // Hierarquia (espelho de v8_api.ajax.php)
        $v8_restricao_meu_usuario = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE_CUSTO_MEU_USUARIO', 'FUNCAO') : false;
        $v8_restricao_minha_fila  = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_FILA_MEU_REGITRO', 'FUNCAO') : false;
        $v8_restricao_hierarquia  = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_HIERARQUIA', 'FUNCAO') : true;

        // Empresa do usuário para filtros V8 (reutiliza $id_empresa_logado já buscado)
        $v8_empresa_logado = $id_empresa_logado;

        // --- Widget 1: Chaves V8 com contagens (hoje e total) ---
        $subConsenHoje  = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID AND DATE(rc.DATA_FILA) = CURDATE())";
        $subConsenTotal = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID)";
        $subMargemHoje  = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO rs ON rs.ID_FILA = rc.ID WHERE rc.CHAVE_ID = ca.ID AND rs.MARGEM_DISPONIVEL IS NOT NULL AND DATE(rc.DATA_FILA) = CURDATE())";
        $subMargemTotal = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO rs ON rs.ID_FILA = rc.ID WHERE rc.CHAVE_ID = ca.ID AND rs.MARGEM_DISPONIVEL IS NOT NULL)";
        $subSimulHoje   = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO rs ON rs.ID_FILA = rc.ID WHERE rc.CHAVE_ID = ca.ID AND rs.SIMULATION_ID IS NOT NULL AND DATE(rc.DATA_FILA) = CURDATE())";
        $subSimulTotal  = "(SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO rs ON rs.ID_FILA = rc.ID WHERE rc.CHAVE_ID = ca.ID AND rs.SIMULATION_ID IS NOT NULL)";

        $sqlChavesSel = "SELECT ca.ID, ca.CLIENTE_NOME, ca.STATUS, ca.SALDO,
                      $subConsenHoje  as CONSEN_HOJE,
                      $subConsenTotal as CONSEN_TOTAL,
                      $subMargemHoje  as MARGEM_HOJE,
                      $subMargemTotal as MARGEM_TOTAL,
                      $subSimulHoje   as SIMUL_HOJE,
                      $subSimulTotal  as SIMUL_TOTAL
               FROM INTEGRACAO_V8_CHAVE_ACESSO ca";

        // Filtro hierárquico nas chaves: meu usuário > minha empresa > todos
        $paramsChaves = [];
        if ($v8_restricao_meu_usuario) {
            $sqlChaves = "$sqlChavesSel WHERE ca.STATUS = 'ATIVO' AND ca.CPF_USUARIO = ? ORDER BY ca.CLIENTE_NOME ASC";
            $paramsChaves = [$cpf_logado];
        } elseif ($v8_restricao_hierarquia && $v8_empresa_logado) {
            $sqlChaves = "$sqlChavesSel WHERE ca.STATUS = 'ATIVO' AND ca.id_empresa = ? ORDER BY ca.CLIENTE_NOME ASC";
            $paramsChaves = [$v8_empresa_logado];
        } else {
            $sqlChaves = "$sqlChavesSel WHERE ca.STATUS = 'ATIVO' ORDER BY ca.CLIENTE_NOME ASC";
        }

        $stmtChaves = $pdo->prepare($sqlChaves);
        $stmtChaves->execute($paramsChaves);
        $v8_chaves = $stmtChaves->fetchAll(PDO::FETCH_ASSOC);

        // --- Widget 2: Histórico das últimas 10 consultas (mesmos campos da fila do módulo) ---
        $whereHist = " WHERE 1=1 ";
        $paramsHist = [];
        if ($v8_restricao_minha_fila) {
            $whereHist .= " AND c.CPF_USUARIO = ? ";
            $paramsHist[] = $cpf_logado;
        } elseif ($v8_restricao_hierarquia && $v8_empresa_logado) {
            $whereHist .= " AND c.EMPRESA_ID = ? ";
            $paramsHist[] = $v8_empresa_logado;
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
            // Hierarquia empresa: vê campanhas da própria empresa ou globais (sem empresa)
            $sqlCamp .= " AND (c.id_empresa IS NULL OR c.id_empresa = ? OR c.CPF_USUARIO = ?)";
            $paramsCamp[] = $id_empresa_logado;
            $paramsCamp[] = $cpf_logado;
        } else {
            // Sem empresa definida: só campanhas globais ou atribuídas ao CPF
            $sqlCamp .= " AND (c.CPF_USUARIO IS NULL OR c.CPF_USUARIO = ?)";
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

// 5. Puxa o menu superior de forma segura
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
    <button class="btn btn-outline-dark fw-bold shadow-sm" onclick="hubToggle('painelCampanhas', this)">
        <i class="far fa-newspaper me-2 text-warning"></i> Campanhas em Andamento
    </button>
    <?php endif; ?>
    <?php if ($temAcessoV8): ?>
    <button class="btn btn-outline-dark fw-bold shadow-sm" onclick="hubToggle('painelV8Chaves', this)">
        <i class="fas fa-robot me-2 text-primary"></i> V8 CLT — Robô de Consulta
    </button>
    <button class="btn btn-outline-dark fw-bold shadow-sm" onclick="hubToggle('painelV8Hist', this)">
        <i class="fas fa-history me-2 text-info"></i> V8 CLT — Histórico Consulta
    </button>
    <?php endif; ?>
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

<!-- ====== WIDGET 1: V8 CLT - ROBÔ DE CONSULTA ====== -->
<div id="painelV8Chaves" class="hub-painel mb-4" style="display:none;">
    <div class="box-v8 shadow-sm">
        <div class="row g-3">
            <?php if (empty($v8_chaves)): ?>
                <div class="col-12 text-center py-3">
                    <span class="text-muted fw-bold fst-italic">Nenhuma chave V8 configurada.</span>
                </div>
            <?php else: ?>
                <?php foreach ($v8_chaves as $chave): ?>
                    <?php $ativo = strtoupper($chave['STATUS'] ?? '') === 'ATIVO'; ?>
                    <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                        <div class="card-v8-hub">
                            <div class="v8h-header"><?= htmlspecialchars($chave['CLIENTE_NOME']) ?></div>
                            <div class="v8h-body">
                                <div class="v8h-status-row">
                                    <?php if ($ativo): ?>
                                        <span class="v8h-dot-ativo"><i class="fas fa-circle" style="font-size:0.55rem;"></i> ATIVO</span>
                                        <a href="/modulos/configuracao/v8_clt_margem_e_digitacao/index.php" class="btn-v8h-acoes"><i class="fas fa-bars me-1"></i>Ações</a>
                                    <?php else: ?>
                                        <span class="v8h-dot-inativo"><i class="far fa-circle" style="font-size:0.55rem;"></i> INATIVO</span>
                                        <span class="btn-v8h-acoes disabled"><i class="fas fa-bars me-1"></i>Ações</span>
                                    <?php endif; ?>
                                </div>
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-credit-card"></i></span>
                                    <span class="v8h-label">Consen.:</span>
                                    <span class="v8h-val"><?= (int)$chave['CONSEN_HOJE'] ?></span>
                                    <span class="badge-hj"><?= (int)$chave['CONSEN_HOJE'] ?> hj</span>
                                </div>
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-search"></i></span>
                                    <span class="v8h-label">Margem:</span>
                                    <span class="v8h-val"><?= (int)$chave['MARGEM_HOJE'] ?></span>
                                    <span class="badge-hj"><?= (int)$chave['MARGEM_HOJE'] ?> hj</span>
                                </div>
                                <div class="v8h-metric-row">
                                    <span class="v8h-icon"><i class="fas fa-file-alt"></i></span>
                                    <span class="v8h-label">Simul.:</span>
                                    <span class="v8h-val"><?= (int)$chave['SIMUL_HOJE'] ?></span>
                                    <span class="badge-hj"><?= (int)$chave['SIMUL_HOJE'] ?> hj</span>
                                </div>
                                <div class="v8h-total-row">
                                    <i class="fas fa-users me-1 text-muted"></i> Total histórico: <?= number_format((int)$chave['CONSEN_TOTAL'], 0, ',', '.') ?>
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

<script>
// Chevron toggle para as seções V8
document.querySelectorAll('#collapseV8Chaves, #collapseV8Hist').forEach(function(el) {
    el.addEventListener('show.bs.collapse', function() {
        var icon = document.querySelector('[data-bs-target="#' + el.id + '"] i.fas');
        if (icon) { icon.classList.replace('fa-chevron-down', 'fa-chevron-up'); }
    });
    el.addEventListener('hide.bs.collapse', function() {
        var icon = document.querySelector('[data-bs-target="#' + el.id + '"] i.fas');
        if (icon) { icon.classList.replace('fa-chevron-up', 'fa-chevron-down'); }
    });
});
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