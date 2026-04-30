<?php
session_start();
$caminho_conexao    = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header     = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';

if (!file_exists($caminho_conexao)) die("Erro Crítico: conexão não encontrada.");
include $caminho_conexao;
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;
if (!isset($_SESSION['usuario_cpf'])) { header("Location: /login.php"); exit; }

$id_campanha = (int)($_GET['id_campanha'] ?? 0);
if (!$id_campanha) { die("Campanha inválida."); }

$stmtCamp = $pdo->prepare("SELECT c.*, e.NOME_CADASTRO as NOME_EMPRESA
    FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
    LEFT JOIN CLIENTE_EMPRESAS e ON c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci = e.CNPJ COLLATE utf8mb4_unicode_ci
    WHERE c.ID = ?");
$stmtCamp->execute([$id_campanha]);
$campanha = $stmtCamp->fetch(PDO::FETCH_ASSOC);
if (!$campanha) { die("Campanha não encontrada."); }

$somente_restantes  = !empty($_GET['restantes']);
$somente_contatados = !empty($_GET['contatados']);
$q       = trim($_GET['q'] ?? '');
$pagina  = max(1, (int)($_GET['p'] ?? 1));
$por_pag = 100;
$offset  = ($pagina - 1) * $por_pag;

$where  = "WHERE c.ID_CAMPANHA = ?";
$params = [$id_campanha];

if ($q !== '') {
    $qL = preg_replace('/\D/', '', $q);
    if ($qL !== '') {
        $where .= " AND c.CPF_CLIENTE LIKE ?";
        $params[] = "%{$qL}%";
    } else {
        $where .= " AND (SELECT nome FROM dados_cadastrais WHERE cpf = c.CPF_CLIENTE LIMIT 1) LIKE ?";
        $params[] = "%{$q}%";
    }
}

$existe_contato = "EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO)";
if ($somente_restantes)  { $where .= " AND NOT {$existe_contato}"; }
if ($somente_contatados) { $where .= " AND {$existe_contato}"; }

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c {$where}");
$stmtCnt->execute($params);
$total = (int)$stmtCnt->fetchColumn();
$total_pags = max(1, ceil($total / $por_pag));

$sql = "SELECT c.CPF_CLIENTE, c.DATA_INCLUSAO, c.PRIORIDADE,
    (SELECT dc.nome FROM dados_cadastrais dc WHERE dc.cpf = c.CPF_CLIENTE LIMIT 1) AS NOME,
    (SELECT t.telefone_cel FROM telefones t WHERE t.cpf = c.CPF_CLIENTE ORDER BY t.id ASC LIMIT 1) AS TELEFONE,
    (SELECT MAX(r.DATA_REGISTRO) FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
     WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO) AS ULTIMO_CONTATO,
    (SELECT s.NOME_STATUS FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
     JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON s.ID = r.ID_STATUS_CONTATO
     WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO
     ORDER BY r.DATA_REGISTRO DESC LIMIT 1) AS ULTIMO_STATUS
FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c
{$where}
ORDER BY c.DATA_INCLUSAO DESC
LIMIT {$por_pag} OFFSET {$offset}";
$stmtCli = $pdo->prepare($sql);
$stmtCli->execute($params);
$clientes = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

// Totais para os badges
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ?");
$stmtTotal->execute([$id_campanha]);
$badge_total = (int)$stmtTotal->fetchColumn();

$stmtRest = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c WHERE ID_CAMPANHA = ? AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO)");
$stmtRest->execute([$id_campanha]);
$badge_restantes = (int)$stmtRest->fetchColumn();

// URL base para paginação
function urlPag($pagina, $extra = []) {
    $p = array_merge($_GET, ['p' => $pagina], $extra);
    return '?' . http_build_query($p);
}

// Busca campanhas disponíveis para inclusão (respeita hierarquia)
$grupo_sess    = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master_cc  = in_array($grupo_sess, ['MASTER','ADMIN','ADMINISTRADOR']);
$id_emp_cc     = null;
if (!$is_master_cc) {
    $se = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $se->execute([$_SESSION['usuario_cpf']]);
    $id_emp_cc = $se->fetchColumn() ?: null;
}
$sqlCampsInc = "SELECT c.ID, c.NOME_CAMPANHA, c.STATUS, COALESCE(e.NOME_CADASTRO,'') AS NOME_EMPRESA
    FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
    LEFT JOIN CLIENTE_EMPRESAS e ON e.CNPJ COLLATE utf8mb4_unicode_ci = c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci
    WHERE 1=1";
$paramsCampsInc = [];
if (!$is_master_cc && $id_emp_cc) {
    $sqlCampsInc .= " AND (c.id_empresa = ? OR c.id_empresa IS NULL)";
    $paramsCampsInc[] = $id_emp_cc;
}
$sqlCampsInc .= " ORDER BY c.NOME_CAMPANHA ASC";
$stCampsInc = $pdo->prepare($sqlCampsInc);
$stCampsInc->execute($paramsCampsInc);
$campanhas_inclusao = $stCampsInc->fetchAll(PDO::FETCH_ASSOC);

$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
include $caminho_header;
?>
<style>
.cc-header { background:#1a1a2e; color:#fff; border-radius:8px 8px 0 0; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.cc-badge-btn { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700; cursor:pointer; border:2px solid transparent; text-decoration:none; transition:.15s; }
.cc-badge-btn.todos  { background:#343a40; color:#fff; border-color:#6c757d; }
.cc-badge-btn.todos.ativo { border-color:#fff; }
.cc-badge-btn.rest   { background:#dc354520; color:#dc3545; border-color:#dc3545; }
.cc-badge-btn.rest.ativo { background:#dc3545; color:#fff; }
.cc-badge-btn.cont   { background:#19875420; color:#198754; border-color:#198754; }
.cc-badge-btn.cont.ativo { background:#198754; color:#fff; }
.cc-table th { background:#212529; color:#fff; font-size:12px; text-transform:uppercase; white-space:nowrap; padding:8px 10px; }
.cc-table td { font-size:13px; padding:7px 10px; vertical-align:middle; }
.cc-table tr:hover td { background:#f8f9fa; }
.cc-cpf  { font-family:monospace; font-size:12px; }
.cc-nome { font-weight:600; }
.cc-tel  { color:#0d6efd; font-size:12px; }
.cc-data { font-size:11px; color:#6c757d; white-space:nowrap; }
.cc-status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.cc-sem-contato { color:#adb5bd; font-style:italic; font-size:11px; }
.cc-ver-btn { padding:2px 10px; font-size:12px; }
.cc-filtro-ativo { font-size:11px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:4px 10px; color:#856404; }
.cc-check { width:16px; height:16px; cursor:pointer; }
#barraAcao { position:sticky; bottom:0; z-index:100; background:#1a1a2e; color:#fff; padding:10px 16px; border-top:2px solid #e8621a; display:none; align-items:center; gap:12px; flex-wrap:wrap; }
.camp-ativa { background:#e8f4ff !important; border-left:4px solid #0d6efd !important; }
.camp-item { border-left:4px solid transparent; }
</style>

<div class="container-fluid py-3">

    <!-- Cabeçalho da campanha -->
    <div class="cc-header mb-0">
        <div>
            <i class="fas fa-bullhorn me-2 text-warning"></i>
            <strong style="font-size:16px;"><?= htmlspecialchars($campanha['NOME_CAMPANHA']) ?></strong>
            <?php if ($campanha['NOME_EMPRESA']): ?>
                <span class="badge bg-primary ms-2" style="font-size:11px;"><?= htmlspecialchars($campanha['NOME_EMPRESA']) ?></span>
            <?php endif; ?>
            <span class="badge bg-<?= $campanha['STATUS'] === 'ATIVO' ? 'success' : 'danger' ?> ms-2" style="font-size:10px;"><?= $campanha['STATUS'] ?></span>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="?id_campanha=<?= $id_campanha ?>&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn todos <?= (!$somente_restantes && !$somente_contatados) ? 'ativo' : '' ?>">
                <i class="fas fa-users"></i> Total <strong><?= number_format($badge_total, 0, ',', '.') ?></strong>
            </a>
            <a href="?id_campanha=<?= $id_campanha ?>&restantes=1&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn rest <?= $somente_restantes ? 'ativo' : '' ?>">
                <i class="fas fa-hourglass-half"></i> Restantes <strong><?= number_format($badge_restantes, 0, ',', '.') ?></strong>
            </a>
            <a href="?id_campanha=<?= $id_campanha ?>&contatados=1&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn cont <?= $somente_contatados ? 'ativo' : '' ?>">
                <i class="fas fa-check-double"></i> Contatados <strong><?= number_format($badge_total - $badge_restantes, 0, ',', '.') ?></strong>
            </a>
            <a href="/modulos/campanhas/index.php" class="btn btn-sm btn-outline-light">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <!-- Barra de busca -->
    <div class="bg-light border border-top-0 rounded-bottom mb-3 p-3">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="id_campanha" value="<?= $id_campanha ?>">
            <?php if ($somente_restantes): ?><input type="hidden" name="restantes" value="1"><?php endif; ?>
            <?php if ($somente_contatados): ?><input type="hidden" name="contatados" value="1"><?php endif; ?>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" style="max-width:260px;" placeholder="CPF ou nome...">
            <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($q): ?>
                <a href="?id_campanha=<?= $id_campanha ?><?= $somente_restantes ? '&restantes=1' : '' ?><?= $somente_contatados ? '&contatados=1' : '' ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times"></i> Limpar
                </a>
                <span class="cc-filtro-ativo"><i class="fas fa-filter me-1"></i>Filtro: "<?= htmlspecialchars($q) ?>"</span>
            <?php endif; ?>
            <span class="ms-auto text-muted small">
                <?= number_format($total, 0, ',', '.') ?> resultado<?= $total != 1 ? 's' : '' ?>
                <?php if ($total > $por_pag): ?> — página <?= $pagina ?> de <?= $total_pags ?><?php endif; ?>
            </span>
        </form>
    </div>

    <!-- Tabela -->
    <div class="table-responsive shadow-sm rounded border border-secondary">
        <table class="table table-bordered table-hover mb-0 cc-table">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" class="cc-check" id="chkTodos" title="Selecionar todos"></th>
                    <th>#</th>
                    <th>CPF</th>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Inclusão na Campanha</th>
                    <th>Último Contato</th>
                    <th>Último Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($clientes)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4 fst-italic">Nenhum cliente encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($clientes as $i => $cli):
                    $num = $offset + $i + 1;
                    $cpf_fmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($cli['CPF_CLIENTE'], 11, '0', STR_PAD_LEFT));
                    $tel_fmt = $cli['TELEFONE'] ? preg_replace('/(\d{2})(\d{4,5})(\d{4})/', '($1) $2-$3', $cli['TELEFONE']) : '';
                    $inc_fmt = $cli['DATA_INCLUSAO'] ? date('d/m/Y H:i', strtotime($cli['DATA_INCLUSAO'])) : '—';
                    $ult_fmt = $cli['ULTIMO_CONTATO'] ? date('d/m/Y H:i', strtotime($cli['ULTIMO_CONTATO'])) : null;
                    $url_ver = "/modulos/banco_dados/consulta.php?id_campanha={$id_campanha}&cpf_selecionado={$cli['CPF_CLIENTE']}&acao=visualizar";
                ?>
                <tr>
                    <td><input type="checkbox" class="cc-check chk-cli" value="<?= $cli['CPF_CLIENTE'] ?>"></td>
                    <td class="text-muted small"><?= $num ?></td>
                    <td class="cc-cpf"><?= $cpf_fmt ?></td>
                    <td class="cc-nome"><?= htmlspecialchars($cli['NOME'] ?? '—') ?></td>
                    <td class="cc-tel"><?= $tel_fmt ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="cc-data"><?= $inc_fmt ?></td>
                    <td class="cc-data">
                        <?php if ($ult_fmt): ?>
                            <span class="text-success fw-bold"><?= $ult_fmt ?></span>
                        <?php else: ?>
                            <span class="cc-sem-contato"><i class="fas fa-clock me-1"></i>Não contatado</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cli['ULTIMO_STATUS']): ?>
                            <span class="cc-status-badge bg-info text-dark"><?= htmlspecialchars($cli['ULTIMO_STATUS']) ?></span>
                        <?php else: ?>
                            <span class="cc-sem-contato">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= $url_ver ?>" target="_blank" class="btn btn-sm btn-outline-primary cc-ver-btn">
                            <i class="fas fa-eye"></i> Ver
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Banner seleção global -->
    <div id="avisoGlobal" style="display:none; background:#2d2d4e; color:#fff; padding:8px 16px; font-size:13px; align-items:center; gap:10px; flex-wrap:wrap; border-top:1px solid rgba(255,255,255,.1);">
        <i class="fas fa-info-circle text-warning me-2"></i>
        Os <strong>100</strong> clientes desta página foram selecionados.
        <button class="btn btn-sm btn-warning fw-bold rounded-0 text-dark" onclick="marcarGlobal()">
            <i class="fas fa-globe me-1"></i> Selecionar todos os <?= number_format($total, 0, ',', '.') ?> do filtro
        </button>
    </div>

    <!-- Barra de Ação (sticky bottom) -->
    <div id="barraAcao">
        <i class="fas fa-check-square text-warning"></i>
        <span id="barraAcaoTexto" style="font-size:13px; font-weight:700;"></span>
        <button class="btn btn-sm btn-warning fw-bold border-dark text-dark rounded-0" onclick="abrirModalIncluirCampanha()">
            <i class="fas fa-bullhorn me-1"></i> Incluir em Campanha
        </button>
        <button class="btn btn-sm btn-outline-light rounded-0" onclick="desmarcarTodos()">
            <i class="fas fa-times me-1"></i> Desmarcar
        </button>
    </div>

    <!-- Paginação -->
    <?php if ($total_pags > 1): ?>
    <nav class="mt-3 d-flex justify-content-center">
        <ul class="pagination pagination-sm mb-0">
            <?php if ($pagina > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag(1)) ?>"><i class="fas fa-angle-double-left"></i></a></li>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($pagina - 1)) ?>"><i class="fas fa-angle-left"></i></a></li>
            <?php endif; ?>
            <?php
            $ini = max(1, $pagina - 2);
            $fim = min($total_pags, $pagina + 2);
            for ($pp = $ini; $pp <= $fim; $pp++): ?>
                <li class="page-item <?= $pp === $pagina ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(urlPag($pp)) ?>"><?= $pp ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($pagina < $total_pags): ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($pagina + 1)) ?>"><i class="fas fa-angle-right"></i></a></li>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($total_pags)) ?>"><i class="fas fa-angle-double-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <p class="text-center text-muted small mt-1"><?= number_format($total, 0, ',', '.') ?> clientes — 100 por página</p>
    <?php endif; ?>

</div>

<!-- Modal Incluir em Campanha -->
<div class="modal fade" id="modalIncluirCamp" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase">
                    <i class="fas fa-bullhorn me-2"></i> Incluir em Campanha
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3">
                <p class="small mb-3 border-start border-warning border-3 ps-2 bg-white p-2 rounded-0" id="modalIncluirTexto"></p>
                <!-- Busca -->
                <input type="text" id="buscaCampModal" class="form-control border-dark rounded-0 mb-2"
                    placeholder="Filtrar por nome da campanha ou empresa..."
                    oninput="filtrarCampsModal(this.value)">
                <!-- Lista de campanhas -->
                <div id="listaCampsModal" style="max-height:320px; overflow-y:auto; border:1px solid #343a40; border-radius:0; background:#fff;">
                    <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Carregando campanhas...</div>
                </div>
                <div id="campSelecionadaInfo" class="mt-2 d-none">
                    <span class="badge bg-success rounded-0 py-2 px-3" style="font-size:12px;">
                        <i class="fas fa-check me-1"></i> <span id="campSelecionadaNome"></span>
                    </span>
                </div>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-secondary rounded-0" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-success fw-bold rounded-0" id="btnConfirmarInclusao" disabled onclick="confirmarInclusao()">
                    <i class="fas fa-plus me-1"></i> Incluir na Campanha Selecionada
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const _totalFiltro = <?= $total ?>;
const _idCampanha  = <?= $id_campanha ?>;
let _selecaoGlobal = false;

const chkTodos = document.getElementById('chkTodos');
chkTodos?.addEventListener('change', () => {
    document.querySelectorAll('.chk-cli').forEach(c => c.checked = chkTodos.checked);
    _selecaoGlobal = false;
    atualizarBarra();
    // Mostra aviso de seleção global apenas se marcou todos e há mais de 100
    document.getElementById('avisoGlobal').style.display =
        (chkTodos.checked && _totalFiltro > 100) ? 'flex' : 'none';
});
document.querySelectorAll('.chk-cli').forEach(c => c.addEventListener('change', () => {
    _selecaoGlobal = false;
    document.getElementById('avisoGlobal').style.display = 'none';
    atualizarBarra();
}));

function marcarGlobal() {
    _selecaoGlobal = true;
    document.getElementById('avisoGlobal').innerHTML =
        `<i class="fas fa-globe text-warning me-2"></i><strong>Todos os ${_totalFiltro.toLocaleString('pt-BR')} clientes do filtro atual foram selecionados.</strong>
         <button class="btn btn-sm btn-outline-light rounded-0 ms-3" onclick="cancelarGlobal()"><i class="fas fa-times me-1"></i>Cancelar</button>`;
    atualizarBarra();
}
function cancelarGlobal() {
    _selecaoGlobal = false;
    document.getElementById('avisoGlobal').style.display = 'none';
    atualizarBarra();
}

function getSelecionados() {
    return [...document.querySelectorAll('.chk-cli:checked')].map(c => c.value);
}
function desmarcarTodos() {
    document.querySelectorAll('.chk-cli').forEach(c => c.checked = false);
    if (chkTodos) chkTodos.checked = false;
    _selecaoGlobal = false;
    document.getElementById('avisoGlobal').style.display = 'none';
    atualizarBarra();
}
function atualizarBarra() {
    const sel   = getSelecionados();
    const barra = document.getElementById('barraAcao');
    const texto = document.getElementById('barraAcaoTexto');
    const qtd   = _selecaoGlobal ? _totalFiltro : sel.length;
    barra.style.display = (qtd > 0) ? 'flex' : 'none';
    if (qtd > 0) texto.textContent = qtd.toLocaleString('pt-BR') + ' cliente(s) selecionado(s)' + (_selecaoGlobal ? ' — TODOS DO FILTRO' : '');
}

let _campsDest = [];
let _campIdSelecionada = null;

function filtrarCampsModal(q) {
    const t = q.toLowerCase();
    const lista = document.getElementById('listaCampsModal');
    const items = lista.querySelectorAll('.camp-item');
    items.forEach(el => {
        const txt = el.dataset.busca || '';
        el.style.display = txt.includes(t) ? '' : 'none';
    });
}

function selecionarCampModal(id, nome, empresa) {
    _campIdSelecionada = id;
    document.querySelectorAll('.camp-item').forEach(el => el.classList.remove('camp-ativa'));
    document.getElementById('camp-item-' + id)?.classList.add('camp-ativa');
    document.getElementById('campSelecionadaNome').textContent = nome + (empresa ? ' — ' + empresa : '');
    document.getElementById('campSelecionadaInfo').classList.remove('d-none');
    document.getElementById('btnConfirmarInclusao').disabled = false;
}

function renderCampsModal(camps) {
    const lista = document.getElementById('listaCampsModal');
    if (!camps.length) { lista.innerHTML = '<div class="text-muted text-center py-3">Nenhuma campanha encontrada.</div>'; return; }
    lista.innerHTML = camps.map(c => {
        const statusBadge = c.STATUS === 'ATIVO'
            ? '<span class="badge bg-success ms-1" style="font-size:10px;">ATIVO</span>'
            : '<span class="badge bg-secondary ms-1" style="font-size:10px;">' + (c.STATUS || '') + '</span>';
        const emp = c.NOME_EMPRESA ? `<small class="text-muted ms-2"><i class="fas fa-building me-1"></i>${c.NOME_EMPRESA}</small>` : '';
        return `<div class="camp-item d-flex align-items-center justify-content-between px-3 py-2 border-bottom"
                    id="camp-item-${c.ID}"
                    data-busca="${(c.NOME_CAMPANHA + ' ' + (c.NOME_EMPRESA||'')).toLowerCase()}"
                    onclick="selecionarCampModal('${c.ID}', '${c.NOME_CAMPANHA.replace(/'/g,"\\'")}', '${(c.NOME_EMPRESA||'').replace(/'/g,"\\'")}')"
                    style="cursor:pointer; transition:.1s;">
                    <span><strong>${c.NOME_CAMPANHA}</strong>${emp}</span>
                    <span>${statusBadge}</span>
                </div>`;
    }).join('');
}

async function abrirModalIncluirCampanha() {
    const sel = getSelecionados();
    document.getElementById('modalIncluirTexto').innerHTML = _selecaoGlobal
        ? `<i class="fas fa-globe text-warning me-1"></i>Seleção global: <strong>${_totalFiltro.toLocaleString('pt-BR')} clientes</strong> do filtro atual serão incluídos.`
        : sel.length > 0
            ? `<i class="fas fa-check-circle text-success me-1"></i><strong>${sel.length}</strong> cliente(s) selecionado(s) serão incluídos.`
            : `<i class="fas fa-info-circle text-warning me-1"></i>Serão incluídos os <strong>100</strong> da página atual.`;

    _campIdSelecionada = null;
    document.getElementById('campSelecionadaInfo').classList.add('d-none');
    document.getElementById('btnConfirmarInclusao').disabled = true;
    document.getElementById('buscaCampModal').value = '';

    if (_campsDest.length === 0) {
        _campsDest = <?= json_encode($campanhas_inclusao) ?>;
    }
    renderCampsModal(_campsDest);
    new bootstrap.Modal(document.getElementById('modalIncluirCamp')).show();
}

// Hover style nas linhas da lista
document.addEventListener('mouseover', e => {
    const el = e.target.closest('.camp-item');
    if (el && !el.classList.contains('camp-ativa')) el.style.background = '#f0f4ff';
});
document.addEventListener('mouseout', e => {
    const el = e.target.closest('.camp-item');
    if (el && !el.classList.contains('camp-ativa')) el.style.background = '';
});

async function confirmarInclusao() {
    const idDest = _campIdSelecionada;
    if (!idDest) { crmToast('Selecione uma campanha de destino.', 'warning'); return; }

    const fd = new FormData();
    fd.append('id_campanha_dest', idDest);

    let url = '/modulos/campanhas/relatorio_ajax.php';

    if (_selecaoGlobal) {
        // Inclusão global: backend busca todos os CPFs do filtro
        fd.append('acao', 'incluir_global_campanha');
        fd.append('id_campanha_origem', _idCampanha);
        fd.append('q', '<?= addslashes($q) ?>');
        <?php if ($somente_restantes): ?>fd.append('somente_restantes', '1');<?php endif; ?>
        <?php if ($somente_contatados): ?>fd.append('somente_contatados', '1');<?php endif; ?>
    } else {
        fd.append('acao', 'incluir_em_campanha');
        const sel = getSelecionados();
        const cpfs = sel.length > 0 ? sel : [...document.querySelectorAll('.chk-cli')].map(c => c.value);
        cpfs.forEach(cpf => fd.append('cpfs[]', cpf));
    }

    const res = await fetch(url, {method:'POST', body:fd}).then(r=>r.json());
    bootstrap.Modal.getInstance(document.getElementById('modalIncluirCamp'))?.hide();
    if (typeof crmToast === 'function')
        crmToast(res.success ? (res.msg || 'Incluídos com sucesso!') : (res.msg || 'Erro ao incluir.'), res.success ? 'success' : 'error');
    else alert(res.success ? (res.msg || 'Incluídos com sucesso!') : (res.msg || 'Erro ao incluir.'));
    if (res.success) desmarcarTodos();
}
</script>
<?php if (file_exists($caminho_footer)) include $caminho_footer; ?>
