<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!file_exists($caminho_conexao)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> Arquivo de conexão não encontrado.");
}
include $caminho_conexao; 

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

// ====================================================================================
// REGRAS DE PERMISSÃO E TRAVA DA TELA
// ====================================================================================
$pode_ver_outros       = verificaPermissao($pdo, 'SUBMENU_CADASTRO_USUARIO_FINANCEIRO_VER_MEU_CLIENTE', 'FUNCAO');
$pode_inserir_carteira = verificaPermissao($pdo, 'FUNCAO_MENU_FINANCEIRO_LANCAMENTO', 'FUNCAO');
$pode_ver_planilha     = verificaPermissao($pdo, 'PLANILHA CLIENTE_CADASTRO_FINANCEIRO_HISTORICO_CARTEIRA', 'FUNCAO');
$pode_ver_comissoes    = verificaPermissao($pdo, 'SUBMENU_FIN_PAINEL', 'TELA');

$cpf_alvo = $_GET['cpf'] ?? '';
$cpf_alvo = preg_replace('/[^0-9]/', '', $cpf_alvo);

if (!$pode_ver_outros || empty($cpf_alvo)) {
    $cpf_alvo = $_SESSION['usuario_cpf'];
}
$cpf_alvo = preg_replace('/[^0-9]/', '', $cpf_alvo);

$stmtUsr = $pdo->prepare("SELECT u.NOME, u.CPF, c.SALDO AS SALDO_FATOR FROM CLIENTE_USUARIO u LEFT JOIN CLIENTE_CADASTRO c ON u.CPF = c.CPF WHERE u.CPF = ? LIMIT 1");
$stmtUsr->execute([$cpf_alvo]);
$cliente_dados = $stmtUsr->fetch(PDO::FETCH_ASSOC);

if (!$cliente_dados) {
    die("<div style='padding:50px; text-align:center;'><h3>Usuário não encontrado.</h3><a href='cadastro_usuario.php'>Voltar</a></div>");
}

$nome_cliente = $cliente_dados['NOME'];
$cpf_formatado = substr($cpf_alvo, 0, 3) . '.' . substr($cpf_alvo, 3, 3) . '.' . substr($cpf_alvo, 6, 3) . '-' . substr($cpf_alvo, 9, 2);

// ====================================================================================
// AÇÃO: INSERIR LANÇAMENTO NA CARTEIRA PRINCIPAL (Protegida pela Regra)
// ====================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'inserir_carteira') {
    if ($pode_inserir_carteira) {
        $tipo = $_POST['tipo'];
        $valor = str_replace(',', '.', str_replace('.', '', $_POST['valor'])); 
        $motivo = trim($_POST['motivo']);

        $stmtIns = $pdo->prepare("INSERT INTO CLIENTE_CADASTRO_FINANCEIRO_HISTORICO_CARTEIRA (CPF, NOME, TIPO, VALOR, MOTIVO) VALUES (?, ?, ?, ?, ?)");
        $stmtIns->execute([$cpf_alvo, $nome_cliente, $tipo, $valor, $motivo]);

        header("Location: ?cpf=" . $cpf_alvo . "&extrato=CARTEIRA");
        exit;
    } else {
        die("<script>alert('ACESSO NEGADO: Você não tem permissão para realizar lançamentos.'); history.back();</script>");
    }
}

// ====================================================================================
// PROCESSAMENTO DOS SALDOS
// ====================================================================================
$stmtCart = $pdo->prepare("SELECT SUM(CASE WHEN TIPO = 'CREDITO' THEN VALOR ELSE 0 END) - SUM(CASE WHEN TIPO = 'DEBITO' THEN VALOR ELSE 0 END) AS SALDO_CARTEIRA FROM CLIENTE_CADASTRO_FINANCEIRO_HISTORICO_CARTEIRA WHERE CPF = ?");
$stmtCart->execute([$cpf_alvo]);
$saldo_carteira = $stmtCart->fetchColumn() ?: 0.00;

$saldo_fator = $cliente_dados['SALDO_FATOR'] ?: 0.00;

$stmtV8 = $pdo->prepare("SELECT SALDO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE CPF_USUARIO = ? AND STATUS = 'ATIVO' LIMIT 1");
$stmtV8->execute([$cpf_alvo]);
$saldo_v8 = $stmtV8->fetchColumn() ?: 0.00;

// ====================================================================================
// PEDIDOS E ENTREGAS
// ====================================================================================
$stmtPed = $pdo->prepare("SELECT CODIGO, DATA_PEDIDO, STATUS_PEDIDO, VALOR FROM COMERCIAL_PEDIDOS WHERE CLIENTE_NOME = ? ORDER BY DATA_PEDIDO DESC LIMIT 50");
$stmtPed->execute([$nome_cliente]);
$pedidos = $stmtPed->fetchAll(PDO::FETCH_ASSOC);

$stmtEnt = $pdo->prepare("SELECT CODIGO, DATA_CRIACAO, STATUS_ENTREGA, PRODUTO_NOME FROM COMERCIAL_ENTREGAS WHERE CLIENTE_NOME = ? ORDER BY DATA_CRIACAO DESC LIMIT 50");
$stmtEnt->execute([$nome_cliente]);
$entregas = $stmtEnt->fetchAll(PDO::FETCH_ASSOC);

// ====================================================================================
// LÓGICA DO EXTRATO (POP-UP) E CORREÇÃO DO V8
// ====================================================================================
$ver_extrato = $_GET['extrato'] ?? null;
$extrato_linhas = [];
$erro_extrato = null;

$periodo = $_GET['periodo'] ?? 'DIA_ATUAL';
$data_inicio = date('Y-m-d 00:00:00');
$data_fim = date('Y-m-d 23:59:59');

if ($ver_extrato) {
    try {
        if ($periodo == 'PERSONALIZADO') {
            $data_inicio = ($_GET['data_inicio'] ?? date('Y-m-d')) . " 00:00:00";
            $data_fim = ($_GET['data_fim'] ?? date('Y-m-d')) . " 23:59:59";
        } elseif ($periodo == 'TODO_PERIODO') {
            $data_inicio = '2000-01-01 00:00:00';
            $data_fim = '2099-12-31 23:59:59';
        } elseif ($periodo == 'MES_ATUAL') {
            $data_inicio = date('Y-m-01 00:00:00');
            $data_fim = date('Y-m-t 23:59:59');
        } elseif ($periodo == 'DIA_ATUAL') {
            $data_inicio = date('Y-m-d 00:00:00');
            $data_fim = date('Y-m-d 23:59:59');
        }

        if ($ver_extrato == 'CARTEIRA') {
            $stmtExt = $pdo->prepare("SELECT DATA as data_mov, TIPO as tipo, VALOR as valor, MOTIVO as motivo, NULL as saldo_momento FROM CLIENTE_CADASTRO_FINANCEIRO_HISTORICO_CARTEIRA WHERE CPF = ? AND DATA BETWEEN ? AND ? ORDER BY DATA DESC");
            $stmtExt->execute([$cpf_alvo, $data_inicio, $data_fim]);
            $extrato_linhas = $stmtExt->fetchAll(PDO::FETCH_ASSOC);
            $titulo_extrato = "Extrato - Carteira Principal";
            
        } elseif ($ver_extrato == 'FATOR_CONFERI') {
            $stmtExt = $pdo->prepare("SELECT DATA_HORA as data_mov, TIPO as tipo, VALOR as valor, MOTIVO as motivo, SALDO_ATUAL as saldo_momento FROM fatorconferi_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? AND DATA_HORA BETWEEN ? AND ? ORDER BY DATA_HORA DESC");
            $stmtExt->execute([$cpf_alvo, $data_inicio, $data_fim]);
            $extrato_linhas = $stmtExt->fetchAll(PDO::FETCH_ASSOC);
            $titulo_extrato = "Extrato - Fator Conferi";
            
        } elseif ($ver_extrato == 'V8_MARGEM') {
            // ✨ Armadilha de erro (Try/Catch) adicionada aqui ✨
            $stmtExt = $pdo->prepare("
                SELECT 
                    e.DATA_LANCAMENTO as data_mov, 
                    e.TIPO_MOVIMENTO as tipo, 
                    e.VALOR as valor, 
                    e.TIPO_CUSTO as motivo, 
                    e.SALDO_ATUAL as saldo_momento 
                FROM INTEGRACAO_V8_EXTRATO_CLIENTE e 
                JOIN INTEGRACAO_V8_CHAVE_ACESSO c ON e.CHAVE_ID = c.ID 
                WHERE c.CPF_USUARIO = ? 
                AND e.DATA_LANCAMENTO BETWEEN ? AND ? 
                ORDER BY e.DATA_LANCAMENTO DESC
            ");
            $stmtExt->execute([$cpf_alvo, $data_inicio, $data_fim]);
            $extrato_linhas = $stmtExt->fetchAll(PDO::FETCH_ASSOC);
            $titulo_extrato = "Extrato - Integração V8 Margem";
        }
    } catch (PDOException $e) {
        $erro_extrato = $e->getMessage();
    }
}

// ====================================================================================
// COMISSÕES DE REVENDA
// ====================================================================================
$comissoes = [];
$erro_comissoes = null;
try {
    $stmtCom = $pdo->prepare("
        SELECT cc.ID, cc.PEDIDO_ID, cc.DATA_BASE, cc.VALOR_BASE_VENDA, cc.VALOR_COMISSAO,
               cc.STATUS_COMISSAO, cc.DATA_CONFERENCIA, cc.DATA_PAGAMENTO,
               cp.CODIGO as NUM_PEDIDO, cp.PRODUTO_NOME
        FROM COMERCIAL_COMISSOES cc
        JOIN FINANCEIRO_VENDEDORES fv ON cc.VENDEDOR_ID = fv.ID
        LEFT JOIN COMERCIAL_PEDIDOS cp ON cc.PEDIDO_ID = cp.ID
        WHERE fv.DOCUMENTO_VENDEDOR = ?
        ORDER BY cc.DATA_BASE DESC
        LIMIT 200
    ");
    $stmtCom->execute([$cpf_alvo]);
    $comissoes = $stmtCom->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $erro_comissoes = $e->getMessage();
}

$total_comissao_pago = 0;
$total_comissao_conferido = 0;
$total_comissao_pendente = 0;
foreach ($comissoes as $com) {
    $st = strtoupper(trim($com['STATUS_COMISSAO'] ?? ''));
    if ($st === 'PAGO') {
        $total_comissao_pago += (float)$com['VALOR_COMISSAO'];
    } elseif ($st === 'CONFERIDO') {
        $total_comissao_conferido += (float)$com['VALOR_COMISSAO'];
    } elseif ($st === 'SEM CONFERENCIA') {
        $total_comissao_pendente += (float)$com['VALOR_COMISSAO'];
    }
}

// ====================================================================================
// GESTÃO DE INDICAÇÃO
// ====================================================================================
$vendedor_ind = null;
$link_indicacao = null;
$clientes_indicados = [];
$tabela_comissao_ind = [];
try {
    $stmtVend = $pdo->prepare("SELECT ID, NOME, LINK_TOKEN FROM FINANCEIRO_VENDEDORES WHERE DOCUMENTO_VENDEDOR = ? LIMIT 1");
    $stmtVend->execute([$cpf_alvo]);
    $vendedor_ind = $stmtVend->fetch(PDO::FETCH_ASSOC);

    if ($vendedor_ind) {
        if ($vendedor_ind['LINK_TOKEN']) {
            $base_url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
            $link_indicacao = $base_url . '/indicacao.php?ref=' . $vendedor_ind['LINK_TOKEN'];
        }

        $stmtInd = $pdo->prepare("SELECT CPF, NOME, CELULAR, NOME_EMPRESA, SITUACAO FROM CLIENTE_CADASTRO WHERE VENDEDOR_REF_ID = ? ORDER BY NOME ASC");
        $stmtInd->execute([$vendedor_ind['ID']]);
        $clientes_indicados = $stmtInd->fetchAll(PDO::FETCH_ASSOC);

        $stmtTab = $pdo->prepare("SELECT fv.OPCAO_COMISSAO, cv.NOME_VARIACAO, cv.VALOR_VENDA, cv.TIPO_COMISSAO, cv.VALOR_COMISSAO, ci.NOME as PRODUTO_NOME FROM FINANCEIRO_VENDEDOR_VARIACOES fv INNER JOIN CATALOGO_VARIACOES cv ON fv.VARIACAO_ID = cv.ID INNER JOIN CATALOGO_ITENS ci ON cv.ITEM_ID = ci.ID WHERE fv.VENDEDOR_ID = ? ORDER BY ci.NOME ASC");
        $stmtTab->execute([$vendedor_ind['ID']]);
        $tabela_comissao_ind = $stmtTab->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) { /* silencioso */ }

include $caminho_header;
?>

<div class="row mb-4">
    <div class="col-12 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="text-primary fw-bold m-0"><i class="fas fa-wallet me-2"></i> Painel Financeiro do Cliente</h2>
            <p class="text-muted m-0">Acompanhe saldos, pedidos e entregas.</p>
        </div>
        <a href="cadastro_usuario.php" class="btn btn-outline-dark fw-bold"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
</div>

<div class="alert alert-info border-primary shadow-sm mb-4">
    <h5 class="fw-bold text-dark m-0"><i class="fas fa-user-circle me-2"></i> <?= htmlspecialchars($nome_cliente) ?></h5>
    <span class="badge bg-primary fs-6 mt-1"><?= $cpf_formatado ?></span>
</div>

<ul class="nav nav-tabs fw-bold mb-4" id="financeiroTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="saldo-tab" data-bs-toggle="tab" data-bs-target="#saldo" type="button" role="tab"><i class="fas fa-coins me-1"></i> Resumo de Saldos</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-dark" id="pedidos-tab" data-bs-toggle="tab" data-bs-target="#pedidos" type="button" role="tab"><i class="fas fa-shopping-cart me-1"></i> Meus Pedidos</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-dark" id="entregas-tab" data-bs-toggle="tab" data-bs-target="#entregas" type="button" role="tab"><i class="fas fa-truck me-1"></i> Entregas</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-dark" id="revenda-tab" data-bs-toggle="tab" data-bs-target="#revenda" type="button" role="tab"><i class="fas fa-percentage me-1"></i> Revenda / Comissões</button>
    </li>
    <?php if ($vendedor_ind): ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link text-danger fw-bold" id="indicacao-tab" data-bs-toggle="tab" data-bs-target="#indicacao" type="button" role="tab"><i class="fas fa-share-alt me-1"></i> Gestão de Indicação</button>
    </li>
    <?php endif; ?>
</ul>

<div class="tab-content" id="financeiroTabsContent">
    
    <div class="tab-pane fade show active" id="saldo" role="tabpanel">
        <div class="row g-4">
            
            <div class="col-md-4">
                <div class="card text-center border-success shadow-sm h-100">
                    <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center py-2">
                        <span><i class="fas fa-wallet me-1"></i> Carteira Principal</span>
                        <a href="?cpf=<?= $cpf_alvo ?>&extrato=CARTEIRA" class="btn btn-sm bg-white text-success fw-bold py-0 px-3 border-success shadow-sm">Extrato</a>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center align-items-center">
                        <h2 class="text-success fw-bold mt-2 mb-1">R$ <?= number_format($saldo_carteira, 2, ',', '.') ?></h2>
                        <p class="small text-muted mb-3">Saldo financeiro livre da conta.</p>
                        
                        <?php if ($pode_inserir_carteira): ?>
                            <button class="btn btn-outline-success btn-sm fw-bold px-4" data-bs-toggle="modal" data-bs-target="#modalInserirLcto">
                                + Lançamento
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card text-center border-primary shadow-sm h-100">
                    <div class="card-header bg-primary text-white fw-bold d-flex justify-content-between align-items-center py-2">
                        <span><i class="fas fa-search-dollar me-1"></i> Fator Conferi</span>
                        <a href="?cpf=<?= $cpf_alvo ?>&extrato=FATOR_CONFERI" class="btn btn-sm bg-white text-primary fw-bold py-0 px-3 border-primary shadow-sm">Extrato</a>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center align-items-center">
                        <h2 class="text-primary fw-bold mt-2 mb-1">R$ <?= number_format($saldo_fator, 2, ',', '.') ?></h2>
                        <p class="small text-muted mb-0">Créditos de Atualização Cadastral.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card text-center border-warning shadow-sm h-100">
                    <div class="card-header bg-warning text-dark fw-bold d-flex justify-content-between align-items-center py-2">
                        <span><i class="fas fa-handshake me-1"></i> Integração V8 Margem</span>
                        <a href="?cpf=<?= $cpf_alvo ?>&extrato=V8_MARGEM" class="btn btn-sm bg-white text-warning fw-bold py-0 px-3 border-warning shadow-sm">Extrato</a>
                    </div>
                    <div class="card-body d-flex flex-column justify-content-center align-items-center">
                        <h2 class="text-warning fw-bold mt-2 mb-1" style="text-shadow: 0 0 1px #000;">R$ <?= number_format($saldo_v8, 2, ',', '.') ?></h2>
                        <p class="small text-muted mb-0">Créditos de Digitação / Margem.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="tab-pane fade" id="pedidos" role="tabpanel">
        <div class="card border-dark shadow-sm">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-shopping-cart"></i> Resumo de Pedidos (Últimos 50)</div>
            <div class="table-responsive bg-white">
                <table class="table table-hover text-center mb-0 align-middle">
                    <thead class="table-light border-dark">
                        <tr><th>Nº do Pedido</th><th>Data</th><th>Valor</th><th>Último Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pedidos)): ?>
                            <tr><td colspan="4" class="py-4 fw-bold text-muted">Nenhum pedido encontrado.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pedidos as $p): ?>
                                <tr>
                                    <td class="fw-bold text-primary">#<?= htmlspecialchars($p['CODIGO']) ?></td>
                                    <td><?= !empty($p['DATA_PEDIDO']) ? date('d/m/Y', strtotime($p['DATA_PEDIDO'])) : '--' ?></td>
                                    <td class="fw-bold">R$ <?= number_format((float)($p['VALOR']??0), 2, ',', '.') ?></td>
                                    <td><span class="badge bg-secondary border border-dark"><?= strtoupper(htmlspecialchars($p['STATUS_PEDIDO'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="entregas" role="tabpanel">
        <div class="card border-dark shadow-sm">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-truck"></i> Resumo de Entregas (Últimas 50)</div>
            <div class="table-responsive bg-white">
                <table class="table table-hover text-center mb-0 align-middle">
                    <thead class="table-light border-dark">
                        <tr><th>Cód. Entrega</th><th>Produto Referência</th><th>Data Inclusão</th><th>Último Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($entregas)): ?>
                            <tr><td colspan="4" class="py-4 fw-bold text-muted">Nenhuma entrega encontrada.</td></tr>
                        <?php else: ?>
                            <?php foreach ($entregas as $e): ?>
                                <tr>
                                    <td class="fw-bold text-info">#<?= htmlspecialchars($e['CODIGO']) ?></td>
                                    <td class="text-start"><?= htmlspecialchars($e['PRODUTO_NOME'] ?? '--') ?></td>
                                    <td><?= !empty($e['DATA_CRIACAO']) ? date('d/m/Y', strtotime($e['DATA_CRIACAO'])) : '--' ?></td>
                                    <td><span class="badge bg-dark border border-white"><?= strtoupper(htmlspecialchars($e['STATUS_ENTREGA'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="revenda" role="tabpanel">

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card text-center border-success shadow-sm h-100">
                    <div class="card-header bg-success text-white fw-bold py-2"><i class="fas fa-check-circle me-1"></i> Total Pago</div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h3 class="text-success fw-bold">R$ <?= number_format($total_comissao_pago, 2, ',', '.') ?></h3>
                        <p class="small text-muted mb-0">Comissões já pagas</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info shadow-sm h-100">
                    <div class="card-header bg-info text-white fw-bold py-2"><i class="fas fa-hourglass-half me-1"></i> Conferido / Aguard. Pgto.</div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h3 class="text-info fw-bold">R$ <?= number_format($total_comissao_conferido, 2, ',', '.') ?></h3>
                        <p class="small text-muted mb-0">Confirmado, a pagar</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning shadow-sm h-100">
                    <div class="card-header bg-warning text-dark fw-bold py-2"><i class="fas fa-clock me-1"></i> Sem Conferência</div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h3 class="text-warning fw-bold" style="text-shadow: 0 0 1px #000;">R$ <?= number_format($total_comissao_pendente, 2, ',', '.') ?></h3>
                        <p class="small text-muted mb-0">Aguardando conferência</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-primary shadow-sm h-100">
                    <div class="card-header bg-primary text-white fw-bold py-2"><i class="fas fa-list me-1"></i> Total de Registros</div>
                    <div class="card-body d-flex flex-column justify-content-center">
                        <h3 class="text-primary fw-bold"><?= count($comissoes) ?></h3>
                        <p class="small text-muted mb-0">Lançamentos encontrados</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-dark shadow-sm">
            <div class="card-header bg-dark text-white fw-bold"><i class="fas fa-percentage me-1"></i> Relatório de Comissões de Revenda (Últimos 200)</div>
            <?php if ($erro_comissoes): ?>
                <div class="alert alert-danger m-3 border-dark fw-bold">
                    <i class="fas fa-exclamation-triangle me-2"></i> ERRO: <?= htmlspecialchars($erro_comissoes) ?>
                </div>
            <?php else: ?>
            <div class="table-responsive bg-white">
                <table class="table table-hover table-striped text-center mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>Data Base</th>
                            <th>Pedido</th>
                            <th class="text-start">Produto</th>
                            <th>Valor Venda (R$)</th>
                            <th>Comissão (R$)</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($comissoes)): ?>
                            <tr><td colspan="5" class="py-5 fw-bold text-muted fs-5">Nenhuma comissão registrada para este revendedor.</td></tr>
                        <?php else: ?>
                            <?php foreach ($comissoes as $com):
                                $status_com = trim($com['STATUS_COMISSAO'] ?? '');
                                $status_up  = strtoupper($status_com);
                                $badge_class = match($status_up) {
                                    'PAGO'             => 'bg-success',
                                    'CONFERIDO'        => 'bg-info',
                                    'SEM CONFERENCIA'  => 'bg-warning text-dark',
                                    'ESTORNADO'        => 'bg-danger',
                                    default            => 'bg-secondary'
                                };
                            ?>
                                <tr>
                                    <td class="text-nowrap"><?= !empty($com['DATA_BASE']) ? date('d/m/Y', strtotime($com['DATA_BASE'])) : '--' ?></td>
                                    <td class="fw-bold text-primary"><?= !empty($com['NUM_PEDIDO']) ? '#' . htmlspecialchars($com['NUM_PEDIDO']) : '#' . ($com['PEDIDO_ID'] ?? '--') ?></td>
                                    <td class="text-start text-break"><?= htmlspecialchars($com['PRODUTO_NOME'] ?? '--') ?></td>
                                    <td class="fw-bold">R$ <?= number_format((float)($com['VALOR_BASE_VENDA'] ?? 0), 2, ',', '.') ?></td>
                                    <td class="fw-bold text-success fs-6">R$ <?= number_format((float)($com['VALOR_COMISSAO'] ?? 0), 2, ',', '.') ?></td>
                                    <td><span class="badge <?= $badge_class ?> shadow-sm px-3"><?= htmlspecialchars($status_com) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($vendedor_ind): ?>
    <div class="tab-pane fade" id="indicacao" role="tabpanel">

        <!-- LINK DE INDICAÇÃO -->
        <div class="card border-dark shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-bold py-2"><i class="fas fa-link me-2"></i> Seu Link de Indicação</div>
            <div class="card-body bg-light">
                <?php if ($link_indicacao): ?>
                <p class="small text-muted mb-2">Compartilhe este link com seus clientes. Quando eles se cadastrarem por ele, serão automaticamente vinculados a você.</p>
                <div class="input-group">
                    <input type="text" id="linkIndicacaoInput" class="form-control border-dark fw-bold" value="<?= htmlspecialchars($link_indicacao) ?>" readonly>
                    <button class="btn btn-primary fw-bold border-dark" onclick="copiarLinkInd()" title="Copiar link">
                        <i class="fas fa-copy me-1"></i> Copiar
                    </button>
                </div>
                <small id="msgCopiado" class="text-success fw-bold mt-1" style="display:none;"><i class="fas fa-check me-1"></i> Link copiado!</small>
                <?php else: ?>
                <div class="alert alert-warning border-dark fw-bold py-2 mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i> Seu link de indicação ainda não foi gerado. Solicite ao administrador do sistema.
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- INDICADORES: RESUMO -->
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card text-center border-primary shadow-sm h-100">
                    <div class="card-header bg-primary text-white fw-bold py-2"><i class="fas fa-users me-1"></i> Total de Indicados</div>
                    <div class="card-body">
                        <h2 class="text-primary fw-bold"><?= count($clientes_indicados) ?></h2>
                        <p class="small text-muted mb-0">Clientes que se cadastraram pelo seu link</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center border-success shadow-sm h-100">
                    <div class="card-header bg-success text-white fw-bold py-2"><i class="fas fa-box-open me-1"></i> Produtos Liberados</div>
                    <div class="card-body">
                        <h2 class="text-success fw-bold"><?= count($tabela_comissao_ind) ?></h2>
                        <p class="small text-muted mb-0">Variações com comissão configurada</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-center border-warning shadow-sm h-100">
                    <div class="card-header bg-warning text-dark fw-bold py-2"><i class="fas fa-hand-holding-usd me-1"></i> Comissões a Receber</div>
                    <div class="card-body">
                        <h2 class="text-warning fw-bold">R$ <?= number_format($total_comissao_pendente + $total_comissao_conferido, 2, ',', '.') ?></h2>
                        <p class="small text-muted mb-0">Pendente + Conferido</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- CLIENTES INDICADOS -->
        <div class="card border-dark shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="fas fa-user-friends me-2"></i> Clientes Vinculados a Você
                <span class="badge bg-primary ms-2"><?= count($clientes_indicados) ?></span>
            </div>
            <?php if (empty($clientes_indicados)): ?>
            <div class="card-body text-center text-muted py-5 fw-bold">
                <i class="fas fa-user-plus fa-2x mb-3 d-block text-secondary"></i>
                Nenhum cliente indicado ainda. Compartilhe seu link!
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-dark">
                        <tr><th>#</th><th>Nome</th><th>CPF</th><th>Celular</th><th>Empresa</th><th class="text-center">Situação</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clientes_indicados as $i => $cli): ?>
                        <tr>
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($cli['NOME'] ?? '-') ?></td>
                            <td><code><?= substr($cli['CPF'],0,3).'.'.substr($cli['CPF'],3,3).'.'.substr($cli['CPF'],6,3).'-'.substr($cli['CPF'],9,2) ?></code></td>
                            <td><?= htmlspecialchars($cli['CELULAR'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($cli['NOME_EMPRESA'] ?? '-') ?></td>
                            <td class="text-center">
                                <?php $sit = strtoupper($cli['SITUACAO'] ?? ''); ?>
                                <span class="badge <?= $sit === 'ATIVO' ? 'bg-success' : 'bg-secondary' ?>"><?= htmlspecialchars($cli['SITUACAO'] ?? 'N/A') ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- TABELA DE COMISSÃO -->
        <div class="card border-dark shadow-sm mb-4">
            <div class="card-header bg-dark text-white fw-bold py-2"><i class="fas fa-money-check-alt me-2"></i> Sua Tabela de Comissões por Produto</div>
            <?php if (empty($tabela_comissao_ind)): ?>
            <div class="card-body text-center text-muted py-5 fw-bold">
                <i class="fas fa-inbox fa-2x mb-3 d-block text-secondary"></i>
                Nenhum produto liberado ainda. Consulte o administrador.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle text-center">
                    <thead class="table-dark">
                        <tr><th class="text-start">Produto</th><th class="text-start">Variação / Plano</th><th>Valor de Venda</th><th>Sua Comissão</th><th>Modalidade</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tabela_comissao_ind as $tab):
                            $regra = $tab['TIPO_COMISSAO'] === 'PERCENTUAL'
                                ? $tab['VALOR_COMISSAO'] . '%'
                                : 'R$ ' . number_format((float)$tab['VALOR_COMISSAO'], 2, ',', '.');
                            $opcao = $tab['OPCAO_COMISSAO'] ?? 'COMISSAO';
                        ?>
                        <tr>
                            <td class="fw-bold text-start text-primary"><?= htmlspecialchars($tab['PRODUTO_NOME']) ?></td>
                            <td class="text-start"><?= htmlspecialchars($tab['NOME_VARIACAO']) ?> <span class="badge bg-secondary">R$ <?= number_format((float)$tab['VALOR_VENDA'],2,',','.') ?></span></td>
                            <td class="fw-bold">R$ <?= number_format((float)$tab['VALOR_VENDA'],2,',','.') ?></td>
                            <td class="fw-bold text-success fs-6"><?= $regra ?></td>
                            <td>
                                <?php if ($opcao === 'DESCONTO'): ?>
                                <span class="badge bg-warning text-dark border border-dark"><i class="fas fa-tag me-1"></i> Desconto no Pedido</span>
                                <?php else: ?>
                                <span class="badge bg-success border border-dark"><i class="fas fa-hand-holding-usd me-1"></i> Comissão Direta</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-light border-dark small text-muted fw-bold">
                <i class="fas fa-info-circle me-1 text-primary"></i>
                <b>Comissão Direta:</b> valor pago a você após o pedido ser confirmado. &nbsp;|&nbsp;
                <b>Desconto no Pedido:</b> o valor da sua comissão é aplicado como desconto para o cliente no momento da venda.
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

</div>

<?php if ($ver_extrato): ?>
<div class="modal fade" id="modalExtrato" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-dark">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-file-invoice-dollar me-2"></i> <?= $titulo_extrato ?></h5>
                <button type="button" class="btn-close btn-close-white" aria-label="Close" onclick="window.location.href='?cpf=<?= $cpf_alvo ?>'"></button>
            </div>
            <div class="modal-body bg-light p-0">
                <div class="bg-white p-3 border-bottom border-dark shadow-sm">
                    <form method="GET" action="" class="row g-2 align-items-end">
                        <input type="hidden" name="cpf" value="<?= $cpf_alvo ?>">
                        <input type="hidden" name="extrato" value="<?= $ver_extrato ?>">
                        
                        <div class="col-md-3">
                            <label class="small fw-bold text-muted">Período:</label>
                            <select name="periodo" class="form-select border-dark fw-bold" onchange="toggleDatas(this.value)">
                                <option value="DIA_ATUAL" <?= $periodo=='DIA_ATUAL'?'selected':'' ?>>Hoje</option>
                                <option value="MES_ATUAL" <?= $periodo=='MES_ATUAL'?'selected':'' ?>>Mês Atual</option>
                                <option value="TODO_PERIODO" <?= $periodo=='TODO_PERIODO'?'selected':'' ?>>Todo o Período</option>
                                <option value="PERSONALIZADO" <?= $periodo=='PERSONALIZADO'?'selected':'' ?>>Personalizado</option>
                            </select>
                        </div>
                        <div class="col-md-3 div-datas <?= $periodo=='PERSONALIZADO'?'':'d-none' ?>">
                            <label class="small fw-bold text-muted">Início:</label>
                            <input type="date" name="data_inicio" class="form-control border-dark" value="<?= substr($data_inicio, 0, 10) ?>">
                        </div>
                        <div class="col-md-3 div-datas <?= $periodo=='PERSONALIZADO'?'':'d-none' ?>">
                            <label class="small fw-bold text-muted">Fim:</label>
                            <input type="date" name="data_fim" class="form-control border-dark" value="<?= substr($data_fim, 0, 10) ?>">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-dark w-100 fw-bold"><i class="fas fa-filter"></i> Aplicar Filtro</button>
                        </div>
                    </form>
                </div>

                <?php if ($erro_extrato): ?>
                    <div class="alert alert-danger m-4 shadow-sm border-dark fw-bold">
                        <i class="fas fa-exclamation-triangle me-2"></i> ERRO NO BANCO DE DADOS: <?= htmlspecialchars($erro_extrato) ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped text-center mb-0 align-middle">
                            <thead class="table-dark text-white" style="position: sticky; top: 0; z-index: 1;">
                                <tr>
                                    <th>Data/Hora</th>
                                    <th>Tipo</th>
                                    <th class="text-start">Motivo / Descrição</th>
                                    <th>Valor (R$)</th>
                                    <?php if($ver_extrato != 'CARTEIRA'): ?><th>Saldo na Hora</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($extrato_linhas)): ?>
                                    <tr><td colspan="5" class="py-5 fw-bold text-muted fs-5">Nenhuma movimentação neste período.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($extrato_linhas as $linha): ?>
                                        <tr>
                                            <td class="text-nowrap"><?= date('d/m/Y H:i', strtotime($linha['data_mov'])) ?></td>
                                            <td>
                                                <?php if(strtoupper($linha['tipo']) == 'CREDITO' || strtoupper($linha['tipo']) == 'ENTRADA'): ?>
                                                    <span class="badge bg-success shadow-sm px-3"><i class="fas fa-arrow-up"></i> Crédito</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger shadow-sm px-3"><i class="fas fa-arrow-down"></i> Débito</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-start text-break"><?= htmlspecialchars($linha['motivo']) ?></td>
                                            <td class="fw-bold fs-6 <?= (strtoupper($linha['tipo']) == 'CREDITO' || strtoupper($linha['tipo']) == 'ENTRADA') ? 'text-success' : 'text-danger' ?>">
                                                R$ <?= number_format((float)$linha['valor'], 2, ',', '.') ?>
                                            </td>
                                            <?php if($ver_extrato != 'CARTEIRA'): ?>
                                                <td class="fw-bold text-secondary">R$ <?= number_format((float)($linha['saldo_momento']??0), 2, ',', '.') ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer bg-light justify-content-between border-top border-dark">
                <small class="text-muted fw-bold"><i class="fas fa-lock"></i> Visualização Protegida.</small>
                <button type="button" class="btn btn-secondary fw-bold" onclick="window.location.href='?cpf=<?= $cpf_alvo ?>'">Fechar Extrato</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($pode_inserir_carteira): ?>
<div class="modal fade" id="modalInserirLcto" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="" class="modal-content border-success shadow-lg">
            <input type="hidden" name="acao" value="inserir_carteira">
            
            <div class="modal-header bg-success text-white border-success">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> Nova Movimentação - Carteira</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-warning py-2 small border-warning text-dark fw-bold mb-3">
                    <i class="fas fa-info-circle me-1"></i> Este lançamento afetará diretamente o Saldo da Carteira.
                </div>

                <div class="mb-3">
                    <label class="fw-bold small mb-1">Tipo de Lançamento</label>
                    <select name="tipo" class="form-select border-dark fw-bold" required>
                        <option value="CREDITO" class="text-success">ENTRADA (Crédito)</option>
                        <option value="DEBITO" class="text-danger">SAÍDA (Débito)</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="fw-bold small mb-1">Valor (R$)</label>
                    <input type="text" name="valor" class="form-control border-dark fw-bold text-end fs-5" placeholder="0,00" required onkeyup="mascaraMoeda(this, event)">
                </div>

                <div class="mb-3">
                    <label class="fw-bold small mb-1">Motivo / Descrição Histórico</label>
                    <textarea name="motivo" class="form-control border-dark" rows="2" placeholder="Ex: Bônus de fidelidade, Pagamento de fatura..." required></textarea>
                </div>
            </div>
            <div class="modal-footer border-success bg-white">
                <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-save"></i> Gravar Lançamento</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function toggleDatas(valor) {
    const divs = document.querySelectorAll('.div-datas');
    if (valor === 'PERSONALIZADO') { divs.forEach(d => d.classList.remove('d-none')); } 
    else { divs.forEach(d => d.classList.add('d-none')); }
}

function mascaraMoeda(i, e) {
    var v = i.value.replace(/\D/g,'');
    v = (v/100).toFixed(2) + '';
    v = v.replace(".", ",");
    v = v.replace(/(\d)(?=(\d{3})+(?!\d))/g, "$1.");
    i.value = v;
}

document.addEventListener("DOMContentLoaded", function() {
    <?php if ($ver_extrato): ?>
        if (typeof bootstrap !== 'undefined') {
            var myModalEl = document.getElementById('modalExtrato');
            var modal = new bootstrap.Modal(myModalEl, {
                keyboard: false,
                backdrop: 'static'
            });
            // Oculta a URL suja do extrato ao fechar o modal
            myModalEl.addEventListener('hidden.bs.modal', function () {
                window.location.href='?cpf=<?= $cpf_alvo ?>';
            });
            modal.show();
        }
    <?php endif; ?>
});
</script>

</div>

<script>
function copiarLinkInd() {
    const el = document.getElementById('linkIndicacaoInput');
    if (!el) return;
    el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(el.value).then(() => {
        const msg = document.getElementById('msgCopiado');
        if (msg) { msg.style.display = 'inline'; setTimeout(() => msg.style.display = 'none', 2500); }
    });
}
</script>

<?php
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) {
    include $caminho_footer;
}
?>