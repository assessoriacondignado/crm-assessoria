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

$perm_auditoria = verificaPermissao($pdo, 'v8_AUDITORIA_INCLUSAO_CPF', 'FUNCAO');

// Lote pré-selecionado via GET
$id_lote_get = max(0, intval($_GET['id_lote'] ?? 0));

include $caminho_header;
?>
<style>
#filtro_lateral { width:270px; min-width:250px; flex-shrink:0; position:sticky; top:68px;
    max-height:calc(100vh - 80px); overflow-y:auto; }
#area_tabela { flex:1 1 auto; min-width:0; }
.filtro-label  { font-size:.74rem; font-weight:700; color:#343a40; margin-bottom:2px; display:block; }
.badge-v8      { font-size:.67rem; padding:2px 5px; border-radius:3px; }
#tabela_clientes thead th { font-size:.71rem; white-space:nowrap; padding:6px 8px; }
#tabela_clientes tbody td { font-size:.74rem; vertical-align:middle; padding:4px 8px; }
/* Barra de ação flutuante */
#barra_acoes { display:none; position:sticky; top:0; z-index:50;
    background:#2d1b69; color:#fff; padding:6px 12px; border-radius:0 0 6px 6px;
    align-items:center; gap:8px; flex-wrap:wrap; font-size:.8rem; }
</style>

<div class="container-fluid mt-2 mb-5 px-3">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
        <h5 class="fw-bold text-dark mb-0">
            <i class="fas fa-users me-2" style="color:#6f42c1;"></i>
            Clientes do Lote
            <span class="badge bg-secondary ms-1" id="badge_lote_titulo" style="display:none;"></span>
            <span class="text-muted fw-normal fs-6 ms-1" id="nome_lote_titulo"></span>
        </h5>
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
                    <span><i class="fas fa-filter me-1 text-warning"></i> Filtros</span>
                    <button class="btn btn-sm btn-outline-light py-0 px-2 border-0" onclick="limparFiltros()" title="Limpar">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="card-body p-2" style="font-size:.8rem;">

                    <!-- SELETOR DE LOTE -->
                    <div class="mb-2">
                        <label class="filtro-label">Lote <span class="text-danger">*</span></label>
                        <select id="f_lote" class="form-select form-select-sm border-danger fw-bold" onchange="onLoteChange()">
                            <option value="">— Selecione o Lote —</option>
                        </select>
                        <div class="text-muted mt-1" id="info_responsavel" style="font-size:.68rem;"></div>
                    </div>

                    <div class="mb-2 border-top pt-2">
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
                            <div class="col-6"><label class="filtro-label fw-normal text-muted">De</label>
                                <input type="date" id="f_cons_de" class="form-control form-control-sm border-dark"></div>
                            <div class="col-6"><label class="filtro-label fw-normal text-muted">Até</label>
                                <input type="date" id="f_cons_ate" class="form-control form-control-sm border-dark"></div>
                        </div>
                    </div>

                    <div class="mb-2 border-top pt-2">
                        <label class="filtro-label">Data Consulta Margem</label>
                        <div class="row g-1">
                            <div class="col-6"><label class="filtro-label fw-normal text-muted">De</label>
                                <input type="date" id="f_sim_de" class="form-control form-control-sm border-dark"></div>
                            <div class="col-6"><label class="filtro-label fw-normal text-muted">Até</label>
                                <input type="date" id="f_sim_ate" class="form-control form-control-sm border-dark"></div>
                        </div>
                    </div>

                    <div class="mb-2 border-top pt-2">
                        <label class="filtro-label">Valor Margem (R$)</label>
                        <div class="row g-1">
                            <div class="col-6"><input type="number" id="f_margem_min" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Mín"></div>
                            <div class="col-6"><input type="number" id="f_margem_max" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Máx"></div>
                        </div>
                    </div>

                    <div class="mb-3 border-top pt-2">
                        <label class="filtro-label">Valor Liberado (R$)</label>
                        <div class="row g-1">
                            <div class="col-6"><input type="number" id="f_lib_min" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Mín"></div>
                            <div class="col-6"><input type="number" id="f_lib_max" step="0.01" min="0" class="form-control form-control-sm border-dark" placeholder="Máx"></div>
                        </div>
                    </div>

                    <div class="d-grid gap-1">
                        <button class="btn btn-danger btn-sm fw-bold border-dark" onclick="filtrar()">
                            <i class="fas fa-search me-1"></i> Filtrar
                        </button>
                        <button class="btn btn-outline-secondary btn-sm border-dark" onclick="limparFiltros()">
                            <i class="fas fa-times me-1"></i> Limpar Filtros
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <!-- ===== TABELA ===== -->
        <div id="area_tabela">

            <!-- Barra de ações (aparece quando há seleção) -->
            <div id="barra_acoes">
                <i class="fas fa-check-square text-warning"></i>
                <strong id="qtd_sel_txt">0</strong> selecionado(s)
                <button class="btn btn-sm btn-light fw-bold border-dark ms-2" onclick="abrirDropdownCampanha()" style="font-size:.75rem; padding:2px 10px;">
                    <i class="fas fa-bullhorn me-1" style="color:#6f42c1;"></i> Incluir em Campanha
                </button>
                <?php if ($perm_auditoria): ?>
                <button class="btn btn-sm fw-bold ms-1" onclick="iniciarAuditoria()" style="background:#b02a37; color:#fff; font-size:.75rem; padding:2px 10px; border:none;">
                    <i class="fas fa-shield-alt me-1"></i> Auditoria
                </button>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-light ms-auto border-0" onclick="desmarcarTodos()" title="Desmarcar todos" style="font-size:.75rem;">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <!-- Barra confirmação campanha -->
            <div id="barra_campanha" style="display:none; background:#f0ebff; border:1px solid #c0a8f0; border-radius:4px; padding:6px 12px; margin-bottom:4px; font-size:.8rem;" class="d-flex align-items-center gap-2 flex-wrap">
                <i class="fas fa-bullhorn" style="color:#6f42c1;"></i>
                <span>Campanha: <strong id="nome_camp_sel"></strong></span>
                <span class="text-muted">— <span id="qtd_camp_sel"></span> cliente(s) serão incluídos</span>
                <button class="btn btn-sm fw-bold ms-auto" style="background:#6f42c1; color:#fff; border:none; font-size:.72rem; padding:2px 10px;" onclick="confirmarCampanha()">
                    <i class="fas fa-check me-1"></i> Confirmar
                </button>
                <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem; padding:2px 8px;" onclick="cancelarCampanha()">Cancelar</button>
            </div>

            <!-- Barra confirmação auditoria -->
            <?php if ($perm_auditoria): ?>
            <div id="barra_auditoria" style="display:none; background:#fff0f0; border:1px solid #f0a0a0; border-radius:4px; padding:6px 12px; margin-bottom:4px; font-size:.8rem;" class="d-flex align-items-center gap-2">
                <i class="fas fa-shield-alt" style="color:#b02a37;"></i>
                <span class="fw-bold" style="color:#b02a37;">AUDITORIA</span>
                <span class="text-muted">— <span id="qtd_aud_sel"></span> CPF(s) serão transferidos e bloqueados.</span>
                <button class="btn btn-sm fw-bold ms-auto" style="background:#b02a37; color:#fff; border:none; font-size:.72rem; padding:2px 10px;" onclick="confirmarAuditoria()">
                    <i class="fas fa-check me-1"></i> Confirmar Auditoria
                </button>
                <button class="btn btn-sm btn-outline-secondary" style="font-size:.72rem; padding:2px 8px;" onclick="cancelarAuditoria()">Cancelar</button>
            </div>
            <?php endif; ?>

            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                    <span class="fw-bold small text-uppercase">
                        <i class="fas fa-list me-1 text-warning"></i> Lista de Clientes
                        <span class="badge bg-warning text-dark ms-2 border border-dark" id="badge_total">0</span>
                    </span>
                    <span class="text-muted small" id="info_exibindo"></span>
                </div>
                <div style="overflow-x:auto; max-height:calc(100vh - 220px); overflow-y:auto;">
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
                                <i class="fas fa-hand-point-left me-2"></i>Selecione um lote e clique em "Filtrar".
                            </td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-light border-dark d-flex justify-content-between align-items-center py-2">
                    <span class="text-muted small" id="info_rodape"></span>
                    <button class="btn btn-sm btn-dark border-secondary fw-bold" id="btn_mais" style="display:none;" onclick="verMais()">
                        <i class="fas fa-plus-circle me-1"></i> VER MAIS 100
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Escolher Campanha -->
<div class="modal fade" id="modalCampanha" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-dark">
            <div class="modal-header bg-dark text-white border-dark py-2">
                <h6 class="modal-title fw-bold"><i class="fas fa-bullhorn me-2" style="color:#c0a0ff;"></i> Incluir em Campanha</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-2"><strong id="qtd_modal_camp"></strong> cliente(s) selecionado(s)</p>
                <label class="fw-bold small mb-1">Selecione a campanha:</label>
                <select id="sel_campanha" class="form-select border-dark">
                    <option value="">— Carregando... —</option>
                </select>
                <div id="msg_camp_modal" class="mt-2"></div>
            </div>
            <div class="modal-footer border-dark">
                <button class="btn btn-outline-secondary border-dark btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-sm fw-bold" style="background:#6f42c1; color:#fff;" onclick="executarIncluirCampanha()">
                    <i class="fas fa-check me-1"></i> Incluir
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// =========================================================================
const ID_LOTE_INICIAL = <?= $id_lote_get ?>;
let _lotesCache   = [];
let _loteAtual    = 0;
let _nomeLote     = '';
let _offset       = 0;
let _total        = 0;
let _campIdSel    = null;
let _campNomeSel  = '';
let _campanhasCache = null;

const CORES_M = { 'OK':'success','NA FILA':'secondary','AGUARDANDO DATAPREV':'primary',
    'AGUARDANDO MARGEM':'info','AGUARDANDO SIMULACAO':'info','ERRO MARGEM':'danger',
    'ERRO SIMULACAO':'danger','ERRO CONSULTA':'danger','RECUPERAR V8':'warning' };
const CORES_C = { 'NAO ENVIADO':'secondary','ENVIADO':'warning','APROVADO':'success',
    'REJEITADO':'danger','CANCELADO':'dark' };

// =========================================================================
// INICIALIZAÇÃO
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {
    carregarLotes();
    // Filtrar ao pressionar Enter nos campos de texto
    document.getElementById('f_q').addEventListener('keydown', e => { if(e.key==='Enter') filtrar(); });
});

async function carregarLotes() {
    const fd = new FormData(); fd.append('acao', 'listar_lotes_hierarquia');
    const r = await fetch('ajax_api_v8_lote_csv.php', { method:'POST', body:fd }).then(r => r.json()).catch(()=>null);
    if (!r || !r.success) return;
    _lotesCache = r.lotes || [];

    const sel = document.getElementById('f_lote');
    sel.innerHTML = '<option value="">— Selecione o Lote —</option>';
    _lotesCache.forEach(l => {
        const o = document.createElement('option');
        o.value = l.ID;
        o.textContent = `#${l.ID} — ${l.NOME_IMPORTACAO}`;
        o.dataset.nome = l.NOME_IMPORTACAO;
        o.dataset.usuario = l.NOME_USUARIO || '';
        if (parseInt(l.ID) === ID_LOTE_INICIAL) o.selected = true;
        sel.appendChild(o);
    });

    if (ID_LOTE_INICIAL > 0) {
        onLoteChange();
        filtrar();
    }
}

function onLoteChange() {
    const sel = document.getElementById('f_lote');
    const opt = sel.options[sel.selectedIndex];
    _loteAtual = parseInt(sel.value) || 0;
    _nomeLote  = opt ? (opt.dataset.nome || '') : '';
    const resp = opt ? (opt.dataset.usuario || '') : '';

    const badge = document.getElementById('badge_lote_titulo');
    const titulo = document.getElementById('nome_lote_titulo');
    const info   = document.getElementById('info_responsavel');
    if (_loteAtual) {
        badge.textContent = '#' + _loteAtual;
        badge.style.display = '';
        titulo.textContent = _nomeLote;
        info.textContent   = resp ? '👤 Responsável: ' + resp : '';
    } else {
        badge.style.display = 'none';
        titulo.textContent = '';
        info.textContent   = '';
    }
}

// =========================================================================
// FILTROS
// =========================================================================
function lerFiltros() {
    return {
        id_lote      : _loteAtual,
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
    if (!_loteAtual) { alert('Selecione um lote para filtrar.'); return; }
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
        '<tr><td colspan="12" class="text-center text-muted py-4 fst-italic"><i class="fas fa-hand-point-left me-2"></i>Selecione um lote e clique em "Filtrar".</td></tr>';
    document.getElementById('badge_total').textContent = '0';
    document.getElementById('info_exibindo').textContent = '';
    document.getElementById('info_rodape').textContent = '';
    document.getElementById('btn_mais').style.display = 'none';
    _offset = 0; _total = 0;
    atualizarBarraAcoes();
}

// =========================================================================
// BUSCAR
// =========================================================================
async function buscar(offset, reset) {
    const filtros = lerFiltros();
    const fd = new FormData();
    fd.append('acao', 'listar_clientes_avancado');
    fd.append('offset', offset);
    Object.entries(filtros).forEach(([k,v]) => fd.append(k, v));

    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd})
        .then(r => r.json())
        .catch(() => null);

    if (!r || !r.success) {
        document.getElementById('tbody_clientes').innerHTML =
            `<tr><td colspan="12" class="text-center text-danger py-3">${r?.msg||'Erro ao carregar'}</td></tr>`;
        return;
    }
    renderTabela(r.clientes, reset);
    _total  = r.total;
    _offset = offset + r.clientes.length;
    document.getElementById('badge_total').textContent  = r.total;
    document.getElementById('info_exibindo').textContent = `${r.total} registro(s)`;
    document.getElementById('info_rodape').textContent   = `Exibindo ${_offset} de ${r.total}`;
    document.getElementById('btn_mais').style.display    = r.tem_mais ? 'inline-block' : 'none';
}

// =========================================================================
// RENDERIZAR TABELA
// =========================================================================
function renderTabela(lista, reset) {
    const tbody = document.getElementById('tbody_clientes');
    if (reset) tbody.innerHTML = '';
    if (!lista || !lista.length) {
        if (reset) tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4 fst-italic">Nenhum registro encontrado.</td></tr>';
        return;
    }
    lista.forEach(c => {
        const corM   = CORES_M[c.STATUS_V8] || 'secondary';
        const corC   = CORES_C[c.STATUS_WHATSAPP] || 'secondary';
        const margem = c.VALOR_MARGEM  ? 'R$ ' + parseFloat(c.VALOR_MARGEM).toFixed(2)  : '—';
        const lib    = c.VALOR_LIQUIDO ? 'R$ ' + parseFloat(c.VALOR_LIQUIDO).toFixed(2) : '—';
        const obs    = c.OBSERVACAO
            ? `<span title="${escHtml(c.OBSERVACAO)}">${escHtml(c.OBSERVACAO.substring(0,40))}${c.OBSERVACAO.length>40?'…':''}</span>`
            : '—';
        const tr = document.createElement('tr');
        tr.style.cursor = 'pointer';
        tr.onclick = () => { const cb = tr.querySelector('.ck-ln'); cb.checked = !cb.checked; atualizarBarraAcoes(); };
        tr.innerHTML = `
            <td onclick="event.stopPropagation()">
                <input type="checkbox" class="ck-ln form-check-input" data-cpf="${c.CPF}" onchange="atualizarBarraAcoes()">
            </td>
            <td class="text-muted" style="white-space:nowrap; font-size:.7rem;">
                <b>#${_loteAtual}</b><br><span style="font-size:.62rem;">${escHtml(_nomeLote.substring(0,18))}${_nomeLote.length>18?'…':''}</span>
            </td>
            <td class="fw-bold" style="white-space:nowrap;">${c.CPF_FORMATADO}</td>
            <td style="white-space:nowrap;">${escHtml(c.NOME||'—')}</td>
            <td><span class="badge badge-v8 bg-${corC}">${c.STATUS_WHATSAPP||'NAO ENVIADO'}</span></td>
            <td style="white-space:nowrap; color:#666; font-size:.7rem;">${c.DATA_CONS_DISPLAY||'—'}</td>
            <td><span class="badge badge-v8 bg-${corM}">${c.STATUS_V8}</span></td>
            <td class="text-success fw-bold" style="white-space:nowrap;">${margem}</td>
            <td class="text-primary fw-bold" style="white-space:nowrap;">${lib}</td>
            <td style="white-space:nowrap; color:#666; font-size:.7rem;">${c.DATA_SIM_DISPLAY||'—'}</td>
            <td style="font-size:.68rem; color:#555; max-width:150px;">${obs}</td>
            <td onclick="event.stopPropagation()">
                <a href="/modulos/banco_dados/consulta.php?busca=${c.CPF}&cpf_selecionado=${c.CPF}&acao=visualizar"
                   target="_blank" class="btn btn-sm py-0 px-1 fw-bold"
                   style="font-size:.68rem; border:1px solid #6f42c1; color:#6f42c1; border-radius:3px;">Ver</a>
            </td>`;
        tbody.appendChild(tr);
    });
}

// =========================================================================
// SELEÇÃO
// =========================================================================
function toggleTodos(el) {
    document.querySelectorAll('.ck-ln').forEach(c => c.checked = el.checked);
    atualizarBarraAcoes();
}
function desmarcarTodos() {
    document.getElementById('check_all').checked = false;
    document.querySelectorAll('.ck-ln').forEach(c => c.checked = false);
    atualizarBarraAcoes();
}
function cpfsSelecionados() {
    return [...document.querySelectorAll('.ck-ln:checked')].map(c => c.dataset.cpf);
}
function atualizarBarraAcoes() {
    const qtd  = cpfsSelecionados().length;
    const barra = document.getElementById('barra_acoes');
    barra.style.display = qtd > 0 ? 'flex' : 'none';
    document.getElementById('qtd_sel_txt').textContent = qtd;
}

// =========================================================================
// INCLUIR EM CAMPANHA
// =========================================================================
async function abrirDropdownCampanha() {
    const qtd = cpfsSelecionados().length;
    if (!qtd) { alert('Selecione ao menos um cliente.'); return; }
    document.getElementById('qtd_modal_camp').textContent = qtd;
    document.getElementById('msg_camp_modal').innerHTML = '';

    const sel = document.getElementById('sel_campanha');
    sel.innerHTML = '<option value="">Carregando...</option>';

    if (!_campanhasCache) {
        const fd = new FormData(); fd.append('acao', 'listar_campanhas_disponiveis');
        const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
        _campanhasCache = (r && r.success) ? r.campanhas : [];
    }
    sel.innerHTML = '<option value="">— Selecione —</option>';
    _campanhasCache.forEach(c => sel.innerHTML += `<option value="${c.ID}">${escHtml(c.NOME_CAMPANHA)}</option>`);

    new bootstrap.Modal(document.getElementById('modalCampanha')).show();
}

async function executarIncluirCampanha() {
    const id_camp = document.getElementById('sel_campanha').value;
    const cpfs    = cpfsSelecionados();
    const msg     = document.getElementById('msg_camp_modal');
    if (!id_camp) { msg.innerHTML='<div class="alert alert-warning py-1 small">Selecione uma campanha.</div>'; return; }
    if (!cpfs.length) { msg.innerHTML='<div class="alert alert-warning py-1 small">Nenhum cliente selecionado.</div>'; return; }

    const fd = new FormData();
    fd.append('acao', 'incluir_em_campanha');
    fd.append('id_campanha', id_camp);
    fd.append('cpfs', JSON.stringify(cpfs));
    fd.append('id_lote', _loteAtual);

    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
    const cls = (r && r.success) ? 'success' : 'danger';
    msg.innerHTML = `<div class="alert alert-${cls} py-1 small">${r?.msg||'Erro'}</div>`;
    if (r && r.success) {
        setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('modalCampanha'))?.hide(), 1500);
    }
}

// =========================================================================
// AUDITORIA
// =========================================================================
function iniciarAuditoria() {
    const cpfs = cpfsSelecionados();
    if (!cpfs.length) { alert('Selecione ao menos um cliente.'); return; }
    document.getElementById('qtd_aud_sel').textContent = cpfs.length;
    document.getElementById('barra_campanha').style.display = 'none';
    document.getElementById('barra_auditoria').style.display = 'flex';
}
function cancelarAuditoria() { document.getElementById('barra_auditoria').style.display = 'none'; }

async function confirmarAuditoria() {
    const cpfs = cpfsSelecionados();
    if (!cpfs.length) return;
    document.getElementById('barra_auditoria').style.display = 'none';

    const fd = new FormData();
    fd.append('acao', 'incluir_em_auditoria');
    fd.append('id_lote', _loteAtual);
    fd.append('cpfs', JSON.stringify(cpfs));

    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
    if (r && r.success) {
        alert(`✅ ${r.msg||'Auditoria realizada.'} Os registros foram removidos deste lote.`);
        filtrar(); // recarrega sem os auditados
    } else {
        alert('❌ ' + (r?.msg || 'Erro ao executar auditoria.'));
    }
}

// (funções de campanha via barra — não usadas nesta versão, modal é suficiente)
function cancelarCampanha() { document.getElementById('barra_campanha').style.display = 'none'; }
function confirmarCampanha() {}

// =========================================================================
// EXPORTAR
// =========================================================================
function exportarFiltro() {
    if (!_loteAtual) { alert('Selecione um lote primeiro.'); return; }
    const f = lerFiltros();
    const p = new URLSearchParams({ acao: 'exportar_clientes_filtrado' });
    Object.entries(f).forEach(([k,v]) => { if(v!=='' && v!==0) p.append(k, v); });
    window.open('ajax_api_v8_lote_csv.php?' + p.toString(), '_blank');
}

// =========================================================================
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
