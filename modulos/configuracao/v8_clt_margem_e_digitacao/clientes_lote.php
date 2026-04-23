<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$caminho_conexao    = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header     = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';

if (!file_exists($caminho_conexao)) die("Erro Crítico: conexão não encontrada.");
include $caminho_conexao;
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;

if (!isset($_SESSION['usuario_cpf'])) { header("Location: /login.php"); exit; }

$id_lote_get = max(0, intval($_GET['id_lote'] ?? 0));

// Info básica do lote para exibir no título
$infoLotePage = null;
if ($id_lote_get) {
    $st = $pdo->prepare("SELECT l.ID, l.NOME_IMPORTACAO, u.NOME as NOME_USUARIO FROM INTEGRACAO_V8_IMPORTACAO_LOTE l LEFT JOIN CLIENTE_USUARIO u ON u.CPF = l.CPF_USUARIO WHERE l.ID = ? LIMIT 1");
    $st->execute([$id_lote_get]);
    $infoLotePage = $st->fetch(PDO::FETCH_ASSOC);
}

include $caminho_header;
?>
<style>
#filtro_lateral { width:280px; min-width:240px; flex-shrink:0; position:sticky; top:68px; height:calc(100vh - 80px); overflow-y:auto; }
#area_tabela { flex:1 1 auto; min-width:0; }
.filtro-label { font-size:.75rem; font-weight:700; color:#343a40; margin-bottom:2px; }
.badge-v8 { font-size:.68rem; padding:2px 6px; border-radius:3px; }
#tabela_clientes thead th { font-size:.72rem; white-space:nowrap; }
#tabela_clientes tbody td { font-size:.75rem; vertical-align:middle; }
.resumo-barra { font-size:.78rem; }
</style>

<div class="container-fluid mt-2 mb-5 px-3">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
        <div>
            <h5 class="fw-bold text-dark mb-0">
                <i class="fas fa-users me-2" style="color:#6f42c1;"></i>
                Clientes do Lote
                <?php if ($infoLotePage): ?>
                    <span class="badge bg-secondary ms-1">#<?= $infoLotePage['ID'] ?></span>
                    <span class="text-muted fw-normal fs-6 ms-2"><?= htmlspecialchars($infoLotePage['NOME_IMPORTACAO']) ?></span>
                <?php endif; ?>
            </h5>
            <?php if ($infoLotePage && !empty($infoLotePage['NOME_USUARIO'])): ?>
            <small class="text-muted"><i class="fas fa-user me-1"></i> Responsável: <?= htmlspecialchars($infoLotePage['NOME_USUARIO']) ?></small>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-sm btn-success border-dark fw-bold" onclick="exportarFiltro()">
                <i class="fas fa-file-csv me-1"></i> Exportar CSV
            </button>
            <a href="index.api.v8.lote.vsc.php" class="btn btn-sm btn-outline-secondary border-dark fw-bold">
                <i class="fas fa-arrow-left me-1"></i> Voltar
            </a>
        </div>
    </div>

    <div class="d-flex gap-3 align-items-start">

        <!-- ===== FILTROS ===== -->
        <div id="filtro_lateral">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white py-2 fw-bold small text-uppercase d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-filter me-1 text-warning"></i> Filtros Avançados</span>
                    <button class="btn btn-sm btn-outline-light py-0 px-2 border-0" onclick="limparFiltros()" title="Limpar filtros">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="card-body p-2" style="font-size:.8rem;">

                    <div class="mb-2">
                        <label class="filtro-label">CPF / Nome</label>
                        <input type="text" id="f_q" class="form-control form-control-sm border-dark" placeholder="Digite CPF ou nome...">
                    </div>

                    <div class="mb-2">
                        <label class="filtro-label">Status Margem</label>
                        <select id="f_status_margem" class="form-select form-select-sm border-dark">
                            <option value="">— Todos —</option>
                            <option value="OK">✅ OK</option>
                            <option value="NA FILA">⏳ Na Fila</option>
                            <option value="AGUARDANDO DATAPREV">📡 Aguardando Dataprev</option>
                            <option value="AGUARDANDO MARGEM">🔍 Aguardando Margem</option>
                            <option value="AGUARDANDO SIMULACAO">⚙️ Aguardando Simulação</option>
                            <option value="ERRO MARGEM">❌ Erro Margem</option>
                            <option value="ERRO SIMULACAO">❌ Erro Simulação</option>
                            <option value="ERRO CONSULTA">❌ Erro Consulta</option>
                            <option value="RECUPERAR V8">🔄 Recuperar V8</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="filtro-label">Status Consentimento</label>
                        <select id="f_status_cons" class="form-select form-select-sm border-dark">
                            <option value="">— Todos —</option>
                            <option value="NAO ENVIADO">🔴 Não Enviado</option>
                            <option value="ENVIADO">🟡 Enviado</option>
                            <option value="APROVADO">🟢 Aprovado</option>
                            <option value="REJEITADO">❌ Rejeitado</option>
                            <option value="CANCELADO">⛔ Cancelado</option>
                        </select>
                    </div>

                    <div class="mb-2 border-top pt-2">
                        <label class="filtro-label">Data Consentimento</label>
                        <div class="row g-1">
                            <div class="col-6">
                                <label class="filtro-label fw-normal text-muted">De</label>
                                <input type="date" id="f_cons_de" class="form-control form-control-sm border-dark">
                            </div>
                            <div class="col-6">
                                <label class="filtro-label fw-normal text-muted">Até</label>
                                <input type="date" id="f_cons_ate" class="form-control form-control-sm border-dark">
                            </div>
                        </div>
                    </div>

                    <div class="mb-2 border-top pt-2">
                        <label class="filtro-label">Data Consulta Margem</label>
                        <div class="row g-1">
                            <div class="col-6">
                                <label class="filtro-label fw-normal text-muted">De</label>
                                <input type="date" id="f_sim_de" class="form-control form-control-sm border-dark">
                            </div>
                            <div class="col-6">
                                <label class="filtro-label fw-normal text-muted">Até</label>
                                <input type="date" id="f_sim_ate" class="form-control form-control-sm border-dark">
                            </div>
                        </div>
                    </div>

                    <div class="mb-2 border-top pt-2">
                        <label class="filtro-label">Valor Margem (R$)</label>
                        <div class="row g-1">
                            <div class="col-6">
                                <input type="number" id="f_margem_min" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Mín">
                            </div>
                            <div class="col-6">
                                <input type="number" id="f_margem_max" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Máx">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3 border-top pt-2">
                        <label class="filtro-label">Valor Liberado (R$)</label>
                        <div class="row g-1">
                            <div class="col-6">
                                <input type="number" id="f_lib_min" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Mín">
                            </div>
                            <div class="col-6">
                                <input type="number" id="f_lib_max" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Máx">
                            </div>
                        </div>
                    </div>

                    <div class="d-grid gap-1">
                        <button class="btn btn-danger btn-sm fw-bold border-dark" onclick="filtrar()">
                            <i class="fas fa-search me-1"></i> Filtrar
                        </button>
                        <button class="btn btn-outline-secondary btn-sm border-dark" onclick="limparFiltros()">
                            <i class="fas fa-times me-1"></i> Limpar
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <!-- ===== TABELA ===== -->
        <div id="area_tabela">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold small text-uppercase">
                        <i class="fas fa-list me-1 text-warning"></i> Lista de Clientes
                        <span class="badge bg-warning text-dark ms-2 border border-dark" id="badge_total">0</span>
                    </span>
                    <span id="resumo_barra" class="resumo-barra text-muted"></span>
                </div>
                <div style="overflow-x:auto; max-height:calc(100vh - 200px); overflow-y:auto;">
                    <table class="table table-hover align-middle mb-0" id="tabela_clientes">
                        <thead class="table-dark" style="position:sticky;top:0;z-index:1;">
                            <tr>
                                <th style="width:28px;"><input type="checkbox" id="check_all" onchange="toggleTodos(this)"></th>
                                <th>Lote</th>
                                <th>CPF</th>
                                <th>Nome</th>
                                <th>St. Consentimento</th>
                                <th>Data Consentimento</th>
                                <th>St. Margem</th>
                                <th>Margem</th>
                                <th>Valor Liberado</th>
                                <th>Data Consulta</th>
                                <th>Observação</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="tbody_clientes">
                            <tr><td colspan="12" class="text-center text-muted py-4 fst-italic">
                                <i class="fas fa-filter me-2"></i>Clique em "Filtrar" para carregar os clientes.
                            </td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-light border-dark d-flex justify-content-between align-items-center py-2">
                    <span class="text-muted small" id="info_exibindo"></span>
                    <button class="btn btn-sm btn-dark border-secondary fw-bold" id="btn_mais" style="display:none;" onclick="verMais()">
                        <i class="fas fa-plus-circle me-1"></i> VER MAIS 100
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const ID_LOTE = <?= $id_lote_get ?>;
let _offset   = 0;
let _total    = 0;
let _nomeLote = <?= json_encode($infoLotePage['NOME_IMPORTACAO'] ?? '') ?>;

const CORES_STATUS = {
    'OK':                  'success',
    'NA FILA':             'secondary',
    'AGUARDANDO DATAPREV': 'primary',
    'AGUARDANDO MARGEM':   'info',
    'AGUARDANDO SIMULACAO':'info',
    'ERRO MARGEM':         'danger',
    'ERRO SIMULACAO':      'danger',
    'ERRO CONSULTA':       'danger',
    'RECUPERAR V8':        'warning',
};
const CORES_CONS = {
    'NAO ENVIADO': 'secondary',
    'ENVIADO':     'warning',
    'APROVADO':    'success',
    'REJEITADO':   'danger',
    'CANCELADO':   'dark',
};

function lerFiltros() {
    return {
        id_lote      : ID_LOTE,
        q            : document.getElementById('f_q').value.trim(),
        status_margem: document.getElementById('f_status_margem').value,
        status_cons  : document.getElementById('f_status_cons').value,
        cons_de      : document.getElementById('f_cons_de').value,
        cons_ate     : document.getElementById('f_cons_ate').value,
        sim_de       : document.getElementById('f_sim_de').value,
        sim_ate      : document.getElementById('f_sim_ate').value,
        margem_min   : document.getElementById('f_margem_min').value,
        margem_max   : document.getElementById('f_margem_max').value,
        lib_min      : document.getElementById('f_lib_min').value,
        lib_max      : document.getElementById('f_lib_max').value,
    };
}

function filtrar() {
    _offset = 0;
    document.getElementById('tbody_clientes').innerHTML =
        '<tr><td colspan="12" class="text-center py-4"><div class="spinner-border spinner-border-sm text-danger me-2"></div> Carregando...</td></tr>';
    buscar(0, true);
}

function verMais() { buscar(_offset, false); }

function limparFiltros() {
    ['f_q','f_status_margem','f_status_cons','f_cons_de','f_cons_ate',
     'f_sim_de','f_sim_ate','f_margem_min','f_margem_max','f_lib_min','f_lib_max']
    .forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
    document.getElementById('tbody_clientes').innerHTML =
        '<tr><td colspan="12" class="text-center text-muted py-4 fst-italic"><i class="fas fa-filter me-2"></i>Clique em "Filtrar" para carregar os clientes.</td></tr>';
    document.getElementById('badge_total').textContent = '0';
    document.getElementById('info_exibindo').textContent = '';
    document.getElementById('btn_mais').style.display = 'none';
    document.getElementById('resumo_barra').textContent = '';
    _offset = 0; _total = 0;
}

function buscar(offset, reset) {
    const filtros = lerFiltros();
    const fd = new FormData();
    fd.append('acao', 'listar_clientes_avancado');
    fd.append('offset', offset);
    Object.entries(filtros).forEach(([k,v]) => fd.append(k, v));

    fetch('ajax_api_v8_lote_csv.php', { method:'POST', body:fd })
        .then(r => r.json())
        .then(r => {
            if (!r.success) {
                document.getElementById('tbody_clientes').innerHTML =
                    `<tr><td colspan="12" class="text-center text-danger py-3">${r.msg||'Erro ao carregar'}</td></tr>`;
                return;
            }
            renderTabela(r.clientes, reset);
            _total  = r.total;
            _offset = offset + r.clientes.length;
            document.getElementById('badge_total').textContent     = r.total;
            document.getElementById('info_exibindo').textContent   = `Exibindo ${_offset} de ${r.total} registro(s)`;
            document.getElementById('resumo_barra').textContent    = r.total > 0 ? `${r.total} registro(s)` : '';
            document.getElementById('btn_mais').style.display      = r.tem_mais ? 'inline-block' : 'none';
            // Atualiza nome do lote se disponível
            if (r.info_lote && r.info_lote.NOME_IMPORTACAO) _nomeLote = r.info_lote.NOME_IMPORTACAO;
        })
        .catch(() => {
            document.getElementById('tbody_clientes').innerHTML =
                '<tr><td colspan="12" class="text-center text-danger py-3">Falha de comunicação.</td></tr>';
        });
}

function renderTabela(lista, reset) {
    const tbody = document.getElementById('tbody_clientes');
    if (reset) tbody.innerHTML = '';
    if (!lista || lista.length === 0) {
        if (reset) tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4 fst-italic">Nenhum registro encontrado.</td></tr>';
        return;
    }
    lista.forEach(c => {
        const corM   = CORES_STATUS[c.STATUS_V8] || 'secondary';
        const corC   = CORES_CONS[c.STATUS_WHATSAPP] || 'secondary';
        const margem = c.VALOR_MARGEM  ? 'R$ ' + parseFloat(c.VALOR_MARGEM).toFixed(2)  : '—';
        const lib    = c.VALOR_LIQUIDO ? 'R$ ' + parseFloat(c.VALOR_LIQUIDO).toFixed(2) : '—';
        const obs    = c.OBSERVACAO ? (c.OBSERVACAO.length > 45 ? c.OBSERVACAO.substring(0,45)+'…' : c.OBSERVACAO) : '—';
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="checkbox" class="check-linha form-check-input" value="${c.CPF}"></td>
            <td class="text-muted fw-bold" style="white-space:nowrap;">
                <small>#${ID_LOTE}</small><br>
                <small class="text-muted fw-normal" style="font-size:.65rem;">${escHtml(_nomeLote).substring(0,20)}</small>
            </td>
            <td class="fw-bold" style="white-space:nowrap;">${c.CPF_FORMATADO}</td>
            <td style="white-space:nowrap;">${escHtml(c.NOME || '—')}</td>
            <td><span class="badge badge-v8 bg-${corC}">${c.STATUS_WHATSAPP || 'NAO ENVIADO'}</span></td>
            <td style="white-space:nowrap; color:#666;">${c.DATA_CONS_DISPLAY || '—'}</td>
            <td><span class="badge badge-v8 bg-${corM}">${c.STATUS_V8}</span></td>
            <td class="text-success fw-bold" style="white-space:nowrap;">${margem}</td>
            <td class="text-primary fw-bold" style="white-space:nowrap;">${lib}</td>
            <td style="white-space:nowrap; color:#666;">${c.DATA_SIM_DISPLAY || '—'}</td>
            <td style="font-size:.7rem; color:#555; max-width:160px;" title="${escHtml(c.OBSERVACAO)}">${escHtml(obs)}</td>
            <td>
                <a href="/modulos/banco_dados/consulta.php?busca=${c.CPF}&cpf_selecionado=${c.CPF}&acao=visualizar"
                   target="_blank" class="btn btn-sm fw-bold py-0 px-1"
                   style="font-size:10px; border:1px solid #6f42c1; color:#6f42c1; border-radius:3px;">Ver</a>
            </td>`;
        tbody.appendChild(tr);
    });
}

function toggleTodos(el) {
    document.querySelectorAll('.check-linha').forEach(c => c.checked = el.checked);
}

function exportarFiltro() {
    const f = lerFiltros();
    const p = new URLSearchParams({ acao: 'exportar_clientes_filtrado' });
    Object.entries(f).forEach(([k,v]) => { if(v !== '') p.append(k, v); });
    window.open('ajax_api_v8_lote_csv.php?' + p.toString(), '_blank');
}

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Auto-carrega ao abrir
document.addEventListener('DOMContentLoaded', () => {
    if (ID_LOTE > 0) filtrar();
});
</script>
