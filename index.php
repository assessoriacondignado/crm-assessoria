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

// 3. Carrega permissões e dados V8 para os widgets do Hub
$temAcessoV8 = false;
$v8_chaves   = [];
$v8_historico = [];

try {
    $caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

    // Verifica se o usuário tem acesso ao módulo V8
    // verificaPermissao retorna TRUE quando o grupo NÃO está bloqueado (tem acesso livre)
    $temAcessoV8 = function_exists('verificaPermissao')
        ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_DIGITAÇÃO', 'FUNCAO')
        : $is_master;

    if ($temAcessoV8) {
        // Hierarquia (espelho de v8_api.ajax.php)
        $v8_restricao_meu_usuario   = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE_CUSTO_MEU_USUARIO', 'FUNCAO') : false;
        $v8_restricao_minha_fila    = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_FILA_MEU_REGITRO', 'FUNCAO') : false;
        $v8_restricao_hierarquia    = function_exists('verificaPermissao') ? !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_HIERARQUIA', 'FUNCAO') : true;

        $v8_empresa_logado = null;
        if ($v8_restricao_hierarquia && !$v8_restricao_minha_fila) {
            $stmtEmpV8 = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtEmpV8->execute([$cpf_logado]);
            $v8_empresa_logado = $stmtEmpV8->fetchColumn() ?: null;
        }

        // --- Widget 1: Chaves V8 com contagens ---
        $sqlChaves = $v8_restricao_meu_usuario
            ? "SELECT ca.ID, ca.CLIENTE_NOME, ca.STATUS, ca.SALDO,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID AND DATE(rc.DATA_FILA) = CURDATE()) as CONSEN_HOJE,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID) as CONSEN_TOTAL,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID AND rc.STATUS_V8 REGEXP 'MARGIN|MARGEM|OK|LIBERADA|AVAILABLE|APPROVED|PRE_APPROVED') as MARGEM_OK,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTRO_SIMULACAO rs LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA rc2 ON rs.ID_FILA = rc2.ID WHERE rc2.CHAVE_ID = ca.ID) as SIMUL_TOTAL
               FROM INTEGRACAO_V8_CHAVE_ACESSO ca WHERE ca.CPF_USUARIO = ? ORDER BY ca.STATUS DESC, ca.CLIENTE_NOME ASC"
            : "SELECT ca.ID, ca.CLIENTE_NOME, ca.STATUS, ca.SALDO,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID AND DATE(rc.DATA_FILA) = CURDATE()) as CONSEN_HOJE,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID) as CONSEN_TOTAL,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTROCONSULTA rc WHERE rc.CHAVE_ID = ca.ID AND rc.STATUS_V8 REGEXP 'MARGIN|MARGEM|OK|LIBERADA|AVAILABLE|APPROVED|PRE_APPROVED') as MARGEM_OK,
                      (SELECT COUNT(*) FROM INTEGRACAO_V8_REGISTRO_SIMULACAO rs LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA rc2 ON rs.ID_FILA = rc2.ID WHERE rc2.CHAVE_ID = ca.ID) as SIMUL_TOTAL
               FROM INTEGRACAO_V8_CHAVE_ACESSO ca ORDER BY ca.STATUS DESC, ca.CLIENTE_NOME ASC";

        $stmtChaves = $v8_restricao_meu_usuario
            ? $pdo->prepare($sqlChaves)
            : $pdo->query($sqlChaves);
        if ($v8_restricao_meu_usuario) { $stmtChaves->execute([$cpf_logado]); }
        $v8_chaves = $stmtChaves->fetchAll(PDO::FETCH_ASSOC);

        // --- Widget 2: Histórico das últimas 10 consultas ---
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
                           DATE_FORMAT(c.DATA_FILA, '%d/%m/%Y %H:%i') as DATA_BR,
                           ch.CLIENTE_NOME, u.NOME as NOME_USUARIO, c.FONTE_CONSULT_ID
                    FROM INTEGRACAO_V8_REGISTROCONSULTA c
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
    $sqlCamp = "
        SELECT c.ID, c.NOME_CAMPANHA, 
               (SELECT COUNT(ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID) as TOTAL_CLIENTES,
               (SELECT COUNT(cl.ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cl WHERE cl.ID_CAMPANHA = c.ID AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = cl.CPF_CLIENTE AND r.DATA_REGISTRO >= cl.DATA_INCLUSAO)) as RESTANTES,
               (SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID ORDER BY ID ASC LIMIT 1) as PRIMEIRO_CPF
        FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
        WHERE c.STATUS = 'ATIVO'
    ";
    $paramsCamp = [];

    // Se NÃO for Master/Admin, mostra apenas campanhas Globais (NULL) ou as atribuídas diretamente ao CPF dele
    if (!$is_master) {
        $sqlCamp .= " AND (c.CPF_USUARIO IS NULL OR c.CPF_USUARIO = ?)";
        $paramsCamp[] = $cpf_logado;
    }

    $sqlCamp .= " ORDER BY c.NOME_CAMPANHA ASC";
    
    $stmtCamp = $pdo->prepare($sqlCamp);
    $stmtCamp->execute($paramsCamp);
    $campanhas_ativas = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

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

    .card-v8 {
        background-color: #2471a3;
        color: #ffffff;
        border: 2px solid #ffffff;
        outline: 2px solid #2471a3;
        border-radius: 4px;
        padding: 10px 12px;
        min-height: 90px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        position: relative;
        overflow: hidden;
        transition: background-color 0.2s;
        text-decoration: none;
    }
    .card-v8:hover { background-color: #1a5276; outline-color: #1a5276; color: #fff; }
    .card-v8.inativo { background-color: #95a5a6; outline-color: #95a5a6; cursor: default; }
    .card-v8.inativo:hover { background-color: #85929e; outline-color: #85929e; }

    .card-v8 .icon-v8-bg {
        font-size: 42px;
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.15;
        color: #fff;
    }
    .card-v8 .v8-nome {
        font-weight: 800;
        font-size: 0.72rem;
        text-transform: uppercase;
        line-height: 1.2;
        margin-bottom: 6px;
    }
    .card-v8 .v8-badge-status {
        font-size: 0.6rem;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 10px;
        background: rgba(255,255,255,0.25);
        display: inline-block;
        margin-bottom: 6px;
    }
    .card-v8 .v8-stats {
        font-size: 0.62rem;
        background: rgba(0,0,0,0.2);
        padding: 3px 7px;
        border-radius: 3px;
        font-weight: 600;
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    .card-v8 .v8-stats span { white-space: nowrap; }

    /* Tabela histórico */
    .tbl-v8-hist { font-size: 0.78rem; }
    .tbl-v8-hist th { background-color: #2471a3; color: #fff; font-weight: 600; white-space: nowrap; }
    .tbl-v8-hist td { vertical-align: middle; }
    .badge-v8-ok   { background-color: #27ae60; color: #fff; }
    .badge-v8-err  { background-color: #c0392b; color: #fff; }
    .badge-v8-pend { background-color: #f39c12; color: #fff; }
    .badge-v8-ia   { background-color: #8e44ad; color: #fff; }
</style>

<div class="d-flex justify-content-between align-items-center border-bottom border-dark pb-2 mb-4">
    <div>
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-home text-primary me-2"></i> Hub Principal do CRM</h2>
        <p class="text-muted m-0">Bem-vindo. Selecione um módulo no menu superior para começar.</p>
    </div>
</div>

<?php if (isset($erro_db)): ?>
    <div class="alert alert-danger fw-bold border-dark shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> <?= $erro_db ?></div>
<?php endif; ?>

<div class="mb-5">
    <div class="barra-titulo-campanha shadow-sm" data-bs-toggle="collapse" data-bs-target="#collapseCampanhas" aria-expanded="true">
        <span><i class="far fa-newspaper me-2"></i> Campanhas em Andamento</span>
        <i class="fas fa-bell"></i>
    </div>
    
    <div class="collapse show" id="collapseCampanhas">
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
                                        <span class="stats">
                                            Total: <?= number_format($camp['TOTAL_CLIENTES'], 0, ',', '.') ?> | Restante: <?= number_format($camp['RESTANTES'], 0, ',', '.') ?>
                                        </span>
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
</div>

<?php if ($temAcessoV8): ?>

<!-- ====== WIDGET 1: V8 CLT - ROBÔ DE CONSULTA ====== -->
<div class="mb-4">
    <div class="barra-titulo-v8 shadow-sm" data-bs-toggle="collapse" data-bs-target="#collapseV8Chaves" aria-expanded="false">
        <span><i class="fas fa-robot me-2"></i> V8 CLT - ROBÔ DE CONSULTA</span>
        <i class="fas fa-chevron-down" id="iconV8Chaves"></i>
    </div>

    <div class="collapse" id="collapseV8Chaves">
        <div class="box-v8 shadow-sm">
            <div class="row g-3">
                <?php if (empty($v8_chaves)): ?>
                    <div class="col-12 text-center py-3">
                        <span class="text-muted fw-bold fst-italic">Nenhuma chave V8 configurada.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($v8_chaves as $chave): ?>
                        <?php
                            $ativo = strtoupper($chave['STATUS'] ?? '') === 'ATIVO';
                            $linkV8 = '/modulos/configuracao/v8_clt_margem_e_digitacao/index.api.ia.v8.php';
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                            <?php if ($ativo): ?>
                                <a href="<?= $linkV8 ?>" class="card-v8 d-block">
                            <?php else: ?>
                                <div class="card-v8 inativo">
                            <?php endif; ?>

                                <i class="fas fa-microchip icon-v8-bg"></i>
                                <div>
                                    <div class="v8-nome"><?= htmlspecialchars($chave['CLIENTE_NOME']) ?></div>
                                    <span class="v8-badge-status"><?= $ativo ? '● ATIVO' : '○ INATIVO' ?></span>
                                </div>
                                <div class="v8-stats">
                                    <span title="Consentimentos hoje / total">
                                        <i class="fas fa-handshake me-1"></i>Consen: <?= (int)$chave['CONSEN_HOJE'] ?>/<?= (int)$chave['CONSEN_TOTAL'] ?>
                                    </span>
                                    <span title="Consultas com margem aprovada">
                                        <i class="fas fa-check-circle me-1"></i>Margem: <?= (int)$chave['MARGEM_OK'] ?>
                                    </span>
                                    <span title="Simulações realizadas">
                                        <i class="fas fa-calculator me-1"></i>Simul: <?= (int)$chave['SIMUL_TOTAL'] ?>
                                    </span>
                                </div>

                            <?php if ($ativo): ?>
                                </a>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ====== WIDGET 2: V8 CLT - HISTÓRICO CONSULTA ====== -->
<div class="mb-5">
    <div class="barra-titulo-v8 shadow-sm" data-bs-toggle="collapse" data-bs-target="#collapseV8Hist" aria-expanded="false">
        <span><i class="fas fa-history me-2"></i> V8 CLT - HISTÓRICO CONSULTA</span>
        <i class="fas fa-chevron-down" id="iconV8Hist"></i>
    </div>

    <div class="collapse" id="collapseV8Hist">
        <div class="box-v8 shadow-sm">
            <?php if (empty($v8_historico)): ?>
                <div class="text-center py-3">
                    <span class="text-muted fw-bold fst-italic">Nenhuma consulta registrada.</span>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0 tbl-v8-hist">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>CPF</th>
                                <th>Nome</th>
                                <th>Chave / Robô</th>
                                <th>Usuário</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($v8_historico as $h): ?>
                                <?php
                                    $st = strtoupper($h['STATUS_V8'] ?? '');
                                    $isIA = (strtoupper($h['FONTE_CONSULT_ID'] ?? '') === 'IA BOT');
                                    if ($isIA) {
                                        $badgeCls = 'badge-v8-ia'; $badgeIcon = 'fa-robot';
                                    } elseif (preg_match('/ERROR|ERRO|REJECT|DENIED|CANCEL|FAILED|TIMEOUT/', $st)) {
                                        $badgeCls = 'badge-v8-err'; $badgeIcon = 'fa-times-circle';
                                    } elseif (preg_match('/MARGIN|MARGEM|OK|SIMULAT|PRONTA|AVAILABLE|APPROVED|PRE_APPROVED|LIBERADA/', $st)) {
                                        $badgeCls = 'badge-v8-ok'; $badgeIcon = 'fa-check-circle';
                                    } else {
                                        $badgeCls = 'badge-v8-pend'; $badgeIcon = 'fa-clock';
                                    }
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= htmlspecialchars($h['DATA_BR']) ?></td>
                                    <td class="text-nowrap"><?= htmlspecialchars($h['CPF_CONSULTADO']) ?></td>
                                    <td><?= htmlspecialchars($h['NOME_COMPLETO'] ?? '--') ?></td>
                                    <td class="text-nowrap"><?= htmlspecialchars($h['CLIENTE_NOME'] ?? '--') ?></td>
                                    <td><?= htmlspecialchars($h['NOME_USUARIO'] ?? '--') ?></td>
                                    <td>
                                        <span class="badge <?= $badgeCls ?> d-inline-flex align-items-center gap-1" style="font-size:0.65rem;">
                                            <i class="fas <?= $badgeIcon ?>"></i>
                                            <?= htmlspecialchars(mb_substr($h['STATUS_V8'] ?? 'AGUARDANDO', 0, 30, 'UTF-8')) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <div class="text-end mt-2">
                <a href="/modulos/configuracao/v8_clt_margem_e_digitacao/index.api.ia.v8.php" class="btn btn-sm btn-outline-primary">
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
        var icon = document.querySelector('[data-bs-target="#' + el.id + '"] .fa-chevron-down, [data-bs-target="#' + el.id + '"] .fa-chevron-up');
        if (icon) { icon.classList.replace('fa-chevron-down', 'fa-chevron-up'); }
    });
    el.addEventListener('hide.bs.collapse', function() {
        var icon = document.querySelector('[data-bs-target="#' + el.id + '"] .fa-chevron-up, [data-bs-target="#' + el.id + '"] .fa-chevron-down');
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