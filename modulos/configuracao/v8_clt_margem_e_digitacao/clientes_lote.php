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

                    <!-- SELETOR DE LOTE MULTI-SELECT -->
                    <div class="mb-2">
                        <label class="filtro-label">Lote</label>
                        <div style="position:relative;" id="wrap_lote_picker">
                            <!-- Tags dos selecionados + input de busca -->
                            <div id="lote_display" onclick="abrirLotePicker()"
                                 style="min-height:32px; background:#fff; border:1px solid #dc3545; border-radius:4px;
                                        padding:3px 6px; cursor:text; display:flex; flex-wrap:wrap; gap:3px; align-items:center;">
                                <span id="lote_placeholder" style="color:#aaa; font-size:.78rem;">— Todos os Lotes —</span>
                                <input type="text" id="f_lote_busca" placeholder="Buscar..."
                                       oninput="filtrarLotesPicker(this.value)"
                                       onclick="event.stopPropagation(); abrirLotePicker()"
                                       autocomplete="off"
                                       style="border:none; outline:none; font-size:.78rem; flex:1; min-width:60px; background:transparent;">
                            </div>
                            <!-- Dropdown -->
                            <div id="lote_picker_dropdown"
                                 style="display:none; position:absolute; top:100%; left:0; width:100%; background:#fff;
                                        border:1px solid #343a40; border-top:none; z-index:9999;
                                        max-height:220px; overflow-y:auto; border-radius:0 0 4px 4px;
                                        box-shadow:0 4px 12px rgba(0,0,0,.2);">
                            </div>
                        </div>
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

            <!-- Banner seleção global -->
            <div id="banner_global" style="display:none; background:#2d2d4e; color:#fff; padding:7px 12px; font-size:.8rem; border-radius:4px; margin-bottom:4px;" class="d-flex align-items-center gap-2 flex-wrap">
                <i class="fas fa-info-circle text-warning"></i>
                <span id="banner_global_txt"></span>
                <button class="btn btn-sm btn-warning fw-bold rounded-0 text-dark ms-2" onclick="ativarGlobal()">
                    <i class="fas fa-globe me-1"></i> Selecionar todos do filtro
                </button>
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

<!-- Componente padrão incluir em campanha -->
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/componente_incluir_campanha.php'; ?>

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

// =========================================================================
// SELETOR DE LOTE MULTI-SELECT
// =========================================================================
let _lotesSelecionados = []; // [{id, nome, status}]

async function carregarLotes() {
    const fd = new FormData(); fd.append('acao', 'listar_lotes_hierarquia');
    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
    if (!r || !r.success) return;
    _lotesCache = r.lotes || [];
    renderLotePicker('');

    if (ID_LOTE_INICIAL > 0) {
        const lote = _lotesCache.find(l => parseInt(l.ID) === ID_LOTE_INICIAL);
        if (lote) {
            toggleLote(lote.ID, lote.NOME_IMPORTACAO, lote.STATUS_LOTE, lote.NOME_USUARIO || '', false);
            filtrar();
        }
    }
}

function abrirLotePicker() {
    renderLotePicker(document.getElementById('f_lote_busca').value);
    document.getElementById('lote_picker_dropdown').style.display = 'block';
}

function filtrarLotesPicker(texto) {
    renderLotePicker(texto);
    document.getElementById('lote_picker_dropdown').style.display = 'block';
}

function renderLotePicker(texto) {
    const dd  = document.getElementById('lote_picker_dropdown');
    const t   = (texto || '').toLowerCase().trim();
    const sel = new Set(_lotesSelecionados.map(l => l.id));

    const filtrados = t
        ? _lotesCache.filter(l => l.NOME_IMPORTACAO.toLowerCase().includes(t) || String(l.ID).includes(t))
        : _lotesCache;

    const ativo = filtrados.filter(l => l.STATUS_LOTE === 'ATIVO');
    const inativo = filtrados.filter(l => l.STATUS_LOTE !== 'ATIVO');

    // Item "Todos"
    const checkTodos = !sel.size;
    let html = `<div onmousedown="selecionarTodos()"
                     style="padding:6px 10px; font-weight:700; font-size:.75rem; cursor:pointer;
                            background:${checkTodos?'#fff0f0':'#f8f9fa'}; border-bottom:2px solid #dee2e6;
                            display:flex; align-items:center; gap:6px;">
                    <span style="width:14px; text-align:center; color:#dc3545;">${checkTodos ? '✓' : ''}</span>
                    — Todos os Lotes —
                </div>`;

    const renderGrupo = (lista, titulo) => {
        if (!lista.length) return;
        html += `<div style="padding:3px 8px; font-size:.65rem; font-weight:700; color:#888; background:#f8f8f8;
                              text-transform:uppercase; letter-spacing:.5px; border-bottom:1px solid #eee;">
                    ${titulo}
                 </div>`;
        lista.forEach(l => {
            const isSel = sel.has(String(l.ID));
            const isAtivo = l.STATUS_LOTE === 'ATIVO';
            const dot = isAtivo ? '🟢' : '🔴';
            const dest = t
                ? escHtml(l.NOME_IMPORTACAO).replace(new RegExp(`(${escHtml(t)})`, 'gi'), '<b style="color:#dc3545;">$1</b>')
                : escHtml(l.NOME_IMPORTACAO);
            html += `<div onmousedown="toggleLote('${l.ID}','${escAttr(l.NOME_IMPORTACAO)}','${l.STATUS_LOTE}','${escAttr(l.NOME_USUARIO||'')}',true)"
                         style="padding:5px 10px; font-size:.75rem; cursor:pointer; border-bottom:1px solid #f5f5f5;
                                background:${isSel?'#fff0f0':'#fff'}; display:flex; align-items:flex-start; gap:6px;"
                         onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background='${isSel?'#fff0f0':'#fff'}'">
                         <span style="width:14px; text-align:center; flex-shrink:0; color:#dc3545;">${isSel ? '✓' : ''}</span>
                         <div>
                             <span style="color:#999; font-size:.65rem;">#${l.ID}</span> ${dot} ${dest}
                             ${l.NOME_USUARIO ? `<br><span style="color:#aaa; font-size:.65rem;">👤 ${escHtml(l.NOME_USUARIO)}</span>` : ''}
                         </div>
                     </div>`;
        });
    };

    renderGrupo(ativo, '🟢 Ativos');
    renderGrupo(inativo, '🔴 Inativos');
    if (!filtrados.length) {
        html += `<div style="padding:8px 10px; color:#aaa; font-size:.75rem; font-style:italic;">Nenhum lote encontrado.</div>`;
    }
    dd.innerHTML = html;
}

function toggleLote(id, nome, status, usuario, fecharApos) {
    const idStr = String(id);
    const idx = _lotesSelecionados.findIndex(l => l.id === idStr);
    if (idx >= 0) {
        _lotesSelecionados.splice(idx, 1);
    } else {
        _lotesSelecionados.push({ id: idStr, nome, status, usuario });
    }
    renderTagsLote();
    renderLotePicker(document.getElementById('f_lote_busca').value);
    if (fecharApos && _lotesSelecionados.length === 1) {
        // mantém aberto para facilitar seleção múltipla
    }
    atualizarTituloLote();
}

function selecionarTodos() {
    _lotesSelecionados = [];
    renderTagsLote();
    renderLotePicker('');
    atualizarTituloLote();
}

function renderTagsLote() {
    const display = document.getElementById('lote_display');
    // Remove tags antigas
    display.querySelectorAll('.tag-lote').forEach(el => el.remove());
    const placeholder = document.getElementById('lote_placeholder');
    if (!_lotesSelecionados.length) {
        placeholder.style.display = '';
    } else {
        placeholder.style.display = 'none';
        _lotesSelecionados.forEach(l => {
            const tag = document.createElement('span');
            tag.className = 'tag-lote';
            const isAtivo = l.status === 'ATIVO';
            tag.style.cssText = `display:inline-flex; align-items:center; gap:3px; background:${isAtivo?'#dc3545':'#6c757d'};
                color:#fff; font-size:.67rem; padding:1px 5px; border-radius:3px; flex-shrink:0;`;
            tag.innerHTML = `#${l.id} ${escHtml(l.nome.substring(0,20))}${l.nome.length>20?'…':''}
                <span onmousedown="event.stopPropagation();removerLote('${l.id}')"
                      style="cursor:pointer; opacity:.8; margin-left:2px; font-size:.85rem;">×</span>`;
            display.insertBefore(tag, document.getElementById('f_lote_busca'));
        });
    }
}

function removerLote(id) {
    _lotesSelecionados = _lotesSelecionados.filter(l => l.id !== String(id));
    renderTagsLote();
    renderLotePicker(document.getElementById('f_lote_busca').value);
    atualizarTituloLote();
}

function atualizarTituloLote() {
    const badge  = document.getElementById('badge_lote_titulo');
    const titulo = document.getElementById('nome_lote_titulo');
    if (!_lotesSelecionados.length) {
        badge.style.display = 'none';
        titulo.textContent = '— Todos os Lotes —';
    } else if (_lotesSelecionados.length === 1) {
        badge.textContent  = '#' + _lotesSelecionados[0].id;
        badge.style.display = '';
        titulo.textContent = _lotesSelecionados[0].nome;
    } else {
        badge.style.display = 'none';
        titulo.textContent = `${_lotesSelecionados.length} lotes selecionados`;
    }
    // Atualiza _loteAtual para compatibilidade
    _loteAtual = _lotesSelecionados.length === 1 ? parseInt(_lotesSelecionados[0].id) : 0;
    _nomeLote  = _lotesSelecionados.length === 1 ? _lotesSelecionados[0].nome : '';
}

// Fecha dropdown ao clicar fora
document.addEventListener('click', e => {
    if (!document.getElementById('wrap_lote_picker')?.contains(e.target)) {
        document.getElementById('lote_picker_dropdown').style.display = 'none';
        document.getElementById('f_lote_busca').value = '';
        renderLotePicker('');
    }
});

function onLoteChange() {}

// =========================================================================
// FILTROS
// =========================================================================
function lerFiltros() {
    return {
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
function lerIdsLote() {
    return _lotesSelecionados.map(l => l.id); // array vazio = Todos
}

async function buscar(offset, reset) {
    const filtros = lerFiltros();
    const fd = new FormData();
    fd.append('acao', 'listar_clientes_avancado');
    fd.append('offset', offset);
    // Envia IDs dos lotes selecionados (vazio = todos)
    lerIdsLote().forEach(id => fd.append('ids_lote[]', id));
    Object.entries(filtros).forEach(([k,v]) => { if (k !== 'id_lote') fd.append(k, v); });

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
                <b>#${c.LOTE_ID || _loteAtual}</b><br>
                <span style="font-size:.62rem;" title="${escHtml(c.NOME_LOTE||_nomeLote)}">${escHtml((c.NOME_LOTE||_nomeLote).substring(0,20))}${(c.NOME_LOTE||_nomeLote).length>20?'…':''}</span>
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
let _globalAtivo = false;

function toggleTodos(el) {
    document.querySelectorAll('.ck-ln').forEach(c => c.checked = el.checked);
    _globalAtivo = false;
    atualizarBarraAcoes();
    // Mostra banner de seleção global se marcou todos e há mais que a página
    const banner = document.getElementById('banner_global');
    const todos = document.querySelectorAll('.ck-ln').length;
    if (el.checked && _total > todos) {
        document.getElementById('banner_global_txt').textContent =
            `${todos} clientes desta página selecionados.`;
        const btn = banner.querySelector('button');
        if (btn) btn.textContent = '';
        if (btn) btn.innerHTML = `<i class="fas fa-globe me-1"></i> Selecionar todos os ${_total.toLocaleString('pt-BR')} do filtro`;
        banner.style.display = 'flex';
    } else {
        banner.style.display = 'none';
    }
}
function ativarGlobal() {
    _globalAtivo = true;
    const banner = document.getElementById('banner_global');
    banner.innerHTML = `<i class="fas fa-globe text-warning me-2"></i><strong>Todos os ${_total.toLocaleString('pt-BR')} clientes do filtro foram selecionados.</strong>
        <button class="btn btn-sm btn-outline-light rounded-0 ms-3" onclick="cancelarGlobalLote()"><i class="fas fa-times me-1"></i>Cancelar</button>`;
    banner.style.display = 'flex';
    atualizarBarraAcoes();
}
function cancelarGlobalLote() {
    _globalAtivo = false;
    document.getElementById('banner_global').style.display = 'none';
    atualizarBarraAcoes();
}
function desmarcarTodos() {
    document.getElementById('check_all').checked = false;
    document.querySelectorAll('.ck-ln').forEach(c => c.checked = false);
    _globalAtivo = false;
    document.getElementById('banner_global').style.display = 'none';
    atualizarBarraAcoes();
}
function cpfsSelecionados() {
    return [...document.querySelectorAll('.ck-ln:checked')].map(c => c.dataset.cpf);
}
function atualizarBarraAcoes() {
    const qtd  = _globalAtivo ? _total : cpfsSelecionados().length;
    const barra = document.getElementById('barra_acoes');
    barra.style.display = qtd > 0 ? 'flex' : 'none';
    document.getElementById('qtd_sel_txt').textContent = qtd.toLocaleString('pt-BR') + (_globalAtivo ? ' — TODOS DO FILTRO' : '');
}

// =========================================================================
// INCLUIR EM CAMPANHA — componente padrão do sistema
// =========================================================================
function abrirDropdownCampanha() {
    const sel = _globalAtivo
        ? [] // global: componente sabe que são todos
        : cpfsSelecionados();

    // Se global, precisamos buscar todos os CPFs do filtro via AJAX
    if (_globalAtivo) {
        _buscarTodosCpfsFiltro().then(cpfs => {
            const resumo = `<i class="fas fa-globe text-warning me-1"></i>Seleção global: <strong>${_total.toLocaleString('pt-BR')}</strong> cliente(s) do filtro serão incluídos.`;
            if (typeof sistemaIncluirCampanha === 'function') sistemaIncluirCampanha(cpfs, resumo);
        });
        return;
    }

    const resumo = sel.length > 0
        ? `<i class="fas fa-check-circle text-success me-1"></i><strong>${sel.length}</strong> cliente(s) selecionado(s) serão incluídos.`
        : `<i class="fas fa-info-circle text-warning me-1"></i>Nenhum selecionado.`;
    if (typeof sistemaIncluirCampanha === 'function') sistemaIncluirCampanha(sel, resumo);
}

async function _buscarTodosCpfsFiltro() {
    const fd = new FormData();
    fd.append('acao', 'listar_clientes');
    fd.append('so_cpfs', '1');
    fd.append('sem_limite', '1');
    _lotesSelecionados.forEach(l => fd.append('lotes[]', l.id));
    const f = _coletarFiltros();
    Object.entries(f).forEach(([k,v]) => fd.append(k, v));
    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
    return r && r.cpfs ? r.cpfs : cpfsSelecionados();
}

// =========================================================================
// AUDITORIA
// =========================================================================
function iniciarAuditoria() {
    const cpfs = cpfsSelecionados();
    if (!cpfs.length) { crmToast('Selecione ao menos um cliente.', 'warning'); return; }
    document.getElementById('qtd_aud_sel').textContent = cpfs.length;
    document.getElementById('barra_campanha').style.display = 'none';
    document.getElementById('barra_auditoria').style.display = 'flex';
}
function cancelarAuditoria() { document.getElementById('barra_auditoria')?.style && (document.getElementById('barra_auditoria').style.display = 'none'); }

async function confirmarAuditoria() {
    const cpfs = cpfsSelecionados();
    if (!cpfs.length) return;
    document.getElementById('barra_auditoria')?.style && (document.getElementById('barra_auditoria').style.display = 'none');

    const fd = new FormData();
    fd.append('acao', 'incluir_em_auditoria');
    fd.append('id_lote', _loteAtual);
    fd.append('cpfs', JSON.stringify(cpfs));

    const r = await fetch('ajax_api_v8_lote_csv.php', {method:'POST', body:fd}).then(r=>r.json()).catch(()=>null);
    if (r && r.success) {
        crmToast((r.msg || 'Auditoria realizada.') + ' Os registros foram removidos deste lote.', 'success');
        filtrar();
    } else {
        crmToast(r?.msg || 'Erro ao executar auditoria.', 'error');
    }
}

function cancelarCampanha() { /* substituído pelo componente sic-modal */ }

// =========================================================================
// EXPORTAR
// =========================================================================
function exportarFiltro() {
    const ids = lerIdsLote();
    if (!ids.length) { crmToast('Selecione ao menos um lote para exportar.', 'warning'); return; }
    const f = lerFiltros();
    const p = new URLSearchParams({ acao: 'exportar_clientes_filtrado' });
    ids.forEach(id => p.append('ids_lote[]', id));
    Object.entries(f).forEach(([k,v]) => { if(v!=='' && v!='0') p.append(k, v); });
    window.open('ajax_api_v8_lote_csv.php?' + p.toString(), '_blank');
}

// =========================================================================
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) {
    if (!s) return '';
    return String(s).replace(/'/g,"\\'").replace(/"/g,'&quot;');
}
(function(){
    const ICONS = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    window.crmToast = function(msg, tipo) {
        tipo = tipo || 'success';
        const wrap = document.getElementById('crm-toast-wrap');
        if (!wrap) return;
        const t = document.createElement('div');
        t.className = 'crm-toast crm-t-' + tipo;
        t.innerHTML = '<i class="fas ' + (ICONS[tipo]||'fa-bell') + ' crm-toast-icon"></i>'
                    + '<span>' + msg + '</span>'
                    + '<button class="crm-toast-close" onclick="this.parentNode.remove()">&times;</button>';
        wrap.appendChild(t);
        requestAnimationFrame(function(){ requestAnimationFrame(function(){ t.classList.add('in'); }); });
    };
})();
</script>
<style>
#crm-toast-wrap { position:fixed; top:16px; right:16px; z-index:999999; display:flex; flex-direction:column; gap:7px; pointer-events:none; }
.crm-toast { display:flex; align-items:flex-start; gap:10px; padding:11px 15px; border-radius:7px; font-size:13px; font-weight:600; color:#fff; box-shadow:0 4px 18px rgba(0,0,0,.28); max-width:380px; pointer-events:auto; opacity:0; transform:translateX(70px); transition:opacity .28s, transform .28s; line-height:1.4; }
.crm-toast.in { opacity:1; transform:translateX(0); }
.crm-toast-icon { font-size:16px; flex-shrink:0; margin-top:1px; }
.crm-toast-close { margin-left:auto; flex-shrink:0; background:none; border:none; color:rgba(255,255,255,.75); font-size:15px; cursor:pointer; padding:0 0 0 6px; line-height:1; }
.crm-toast-close:hover { color:#fff; }
.crm-t-success { background:#198754; }
.crm-t-error   { background:#dc3545; }
.crm-t-warning { background:#e67e22; }
.crm-t-info    { background:#0d6efd; }
</style>
<div id="crm-toast-wrap"></div>
