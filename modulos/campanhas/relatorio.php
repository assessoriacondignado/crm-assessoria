<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao    = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header     = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';

if (!file_exists($caminho_conexao)) die("Erro Crítico: arquivo de conexão não encontrado.");
include $caminho_conexao;
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;

if (!verificaPermissao($pdo, 'CAMPANHA_RELATORIO_MENU', 'TELA')) {
    include $caminho_header;
    die("<div class='container mt-5'><div class='alert alert-danger text-center shadow-lg border-dark p-4 rounded-3'><h4 class='fw-bold'><i class='fas fa-ban'></i> Acesso Negado</h4><p class='mb-0'>Sem permissão para acessar Relatórios de Campanhas.</p></div></div>");
}

$perm_consulta = verificaPermissao($pdo, 'CAMPANHA_RELATORIO_CONSULTA', 'FUNCAO');
$perm_exportar = verificaPermissao($pdo, 'CAMPANHA_RELATORIO_EXPORTAR', 'FUNCAO');
$is_master     = in_array(strtoupper($_SESSION['usuario_grupo'] ?? ''), ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

include $caminho_header;
?>
<style>
/* ---- Modelos ---- */
#box_modelos .card-modelo { cursor:pointer; transition:transform .15s,box-shadow .15s; border:2px solid #dee2e6; }
#box_modelos .card-modelo:hover { transform:translateY(-4px); box-shadow:0 8px 24px rgba(0,0,0,.18); border-color:#dc3545; }

/* ---- Layout ---- */
#painel_esquerdo { position:sticky; top:70px; height:calc(100vh - 90px); display:flex; flex-direction:column; }
#box_filtros_card { flex:0 0 auto; }
#box_grafico_card { flex:1 1 auto; min-height:0; overflow:hidden; }
#box_grafico_card .card-body { height:100%; display:flex; flex-direction:column; justify-content:center; align-items:center; }
#tabela_wrap { max-height:calc(100vh - 200px); overflow-y:auto; }
.badge-status { font-size:.72rem; padding:3px 7px; }
.linha-cliente td { vertical-align:middle; font-size:.82rem; }

/* ---- Multi-select customizado ---- */
.crm-ms-wrap { position:relative; }
.crm-ms-display {
    min-height:34px; cursor:pointer; background:#fff; border:1px solid #343a40 !important;
    border-radius:.25rem; padding:3px 8px; display:flex; flex-wrap:wrap; gap:3px; align-items:center;
}
.crm-ms-display:hover { border-color:#dc3545 !important; }
.crm-ms-placeholder { color:#6c757d; font-size:.8rem; }
.crm-ms-tag {
    display:inline-flex; align-items:center; gap:4px;
    background:#dc3545; color:#fff; font-size:.72rem; padding:1px 6px;
    border-radius:3px; border:1px solid #a71d2a;
}
.crm-ms-tag .crm-ms-del { cursor:pointer; font-size:.9rem; line-height:1; opacity:.85; }
.crm-ms-tag .crm-ms-del:hover { opacity:1; }
.crm-ms-dropdown {
    display:none; position:absolute; left:0; top:100%; width:100%; z-index:9999;
    background:#fff; border:1px solid #343a40; border-radius:.25rem;
    box-shadow:0 6px 20px rgba(0,0,0,.18); max-height:230px; overflow:hidden;
    flex-direction:column;
}
.crm-ms-dropdown.aberto { display:flex !important; }
.crm-ms-search-wrap { padding:5px; border-bottom:1px solid #dee2e6; flex-shrink:0; }
.crm-ms-search { width:100%; border:1px solid #343a40; border-radius:.2rem; padding:4px 8px; font-size:.8rem; outline:none; }
.crm-ms-search:focus { border-color:#dc3545; }
.crm-ms-hint { font-size:.7rem; color:#aaa; padding:2px 6px; display:none; }
.crm-ms-list { overflow-y:auto; flex:1 1 auto; }
.crm-ms-item {
    padding:5px 10px; font-size:.8rem; cursor:pointer;
    border-bottom:1px solid #f0f0f0; display:flex; align-items:center; gap:6px;
}
.crm-ms-item:hover { background:#fff5f5; }
.crm-ms-item.selecionado { background:#fff0f0; font-weight:700; color:#dc3545; }
.crm-ms-item.todos { font-weight:700; color:#343a40; background:#f8f9fa; border-bottom:2px solid #dee2e6; }
.crm-ms-item.todos:hover { background:#e9ecef; }
.crm-ms-vazio { padding:8px 10px; font-size:.78rem; color:#aaa; font-style:italic; }
</style>

<div class="container-fluid mt-3 mb-5 px-4">

    <!-- ===================== SELETOR DE MODELOS ===================== -->
    <div id="box_modelos">
        <div class="row mb-4">
            <div class="col-12 text-center">
                <h4 class="text-danger fw-bold"><i class="fas fa-chart-pie me-2"></i> Relatórios de Campanha</h4>
                <p class="text-muted small">Selecione o modelo de relatório para continuar.</p>
            </div>
        </div>
        <div class="row justify-content-center g-4">
            <div class="col-md-4">
                <div class="card card-modelo shadow-sm rounded-3 p-3 text-center" onclick="selecionarModelo('STATUS_CAMPANHA')">
                    <div class="mb-3"><i class="fas fa-tags fa-3x text-danger"></i></div>
                    <h5 class="fw-bold text-dark">STATUS CAMPANHA</h5>
                    <p class="text-muted small mb-0">Registros de contato agrupados por status. Gráfico de pizza.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card card-modelo shadow-sm rounded-3 p-3 text-center" onclick="selecionarModelo('AGENDAMENTOS_FUTURO')">
                    <div class="mb-3"><i class="fas fa-calendar-check fa-3x text-primary"></i></div>
                    <h5 class="fw-bold text-dark">AGENDAMENTOS FUTUROS</h5>
                    <p class="text-muted small mb-0">Clientes com retorno agendado. Gráfico de barras por data.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-modelo shadow-sm rounded-3 p-3 text-center" onclick="selecionarModelo('HISTORICO_CONSULTAS')">
                    <div class="mb-3"><i class="fas fa-history fa-3x text-warning"></i></div>
                    <h5 class="fw-bold text-dark">HIST&Oacute;RICO DE CONSULTAS</h5>
                    <p class="text-muted small mb-0">Consultas realizadas no sistema por m&oacute;dulo, usu&aacute;rio e per&iacute;odo.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ===================== LAYOUT DO RELATÓRIO ===================== -->
    <div id="box_relatorio" style="display:none;">

        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-sm btn-outline-secondary border-dark fw-bold" onclick="voltarModelos()">
                    <i class="fas fa-arrow-left me-1"></i> Modelos
                </button>
                <h5 class="mb-0 fw-bold text-danger" id="titulo_relatorio"></h5>
            </div>
            <?php if ($perm_exportar): ?>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-warning border-dark fw-bold text-dark" onclick="exportar('tudo')" id="btn_exp_tudo" disabled>
                    <i class="fas fa-file-csv me-1"></i> Exportar Tudo
                </button>
                <button class="btn btn-sm btn-outline-warning border-dark fw-bold text-dark" onclick="exportar('selecionados')" id="btn_exp_sel" disabled>
                    <i class="fas fa-check-square me-1"></i> Exportar Selecionados
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-3">

            <!-- ===== PAINEL ESQUERDO ===== -->
            <div class="col-md-3">
                <div id="painel_esquerdo">

                    <!-- FILTROS -->
                    <div class="card border-dark shadow-sm rounded-2 mb-2" id="box_filtros_card">
                        <div class="card-header bg-dark text-white py-2 fw-bold small text-uppercase">
                            <i class="fas fa-filter me-1 text-warning"></i> Filtro
                        </div>
                        <div class="card-body p-2">

                            <input type="hidden" id="tipo_relatorio_ativo">

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Agrupamento</label>
                                <select id="f_agrupamento" class="form-select form-select-sm border-danger fw-bold">
                                    <option value="status">📊 Por Status</option>
                                    <option value="campanha">📣 Por Campanha</option>
                                    <option value="usuario">👤 Por Usuário</option>
                                </select>
                                <!-- Agrupamento alternativo para HISTORICO_CONSULTAS -->
                                <select id="f_agrupamento_hc" class="form-select form-select-sm border-danger fw-bold" style="display:none;">
                                    <option value="modulo">📋 Por Módulo</option>
                                    <option value="usuario">👤 Por Usuário</option>
                                    <option value="campanha">📅 Por Dia</option>
                                </select>
                            </div>

                            <?php if ($is_master): ?>
                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Empresa</label>
                                <select id="f_empresa" class="form-select form-select-sm border-dark">
                                    <option value="">— Todas —</option>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Campanha</label>
                                <div class="crm-ms-wrap" id="ms_campanha">
                                    <div class="crm-ms-display" onclick="toggleMs('ms_campanha', event)">
                                        <span class="crm-ms-placeholder">— Todas —</span>
                                    </div>
                                    <div class="crm-ms-dropdown">
                                        <div class="crm-ms-search-wrap">
                                            <input class="crm-ms-search" type="text" placeholder="Pesquisar (mín. 3 letras)..."
                                                   oninput="filtrarMs('ms_campanha', this.value)"
                                                   onclick="event.stopPropagation()">
                                            <div class="crm-ms-hint" id="ms_campanha_hint">Digite ao menos 3 letras para buscar</div>
                                        </div>
                                        <div class="crm-ms-list" id="ms_campanha_list"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Status</label>
                                <div class="crm-ms-wrap" id="ms_status">
                                    <div class="crm-ms-display" onclick="toggleMs('ms_status', event)">
                                        <span class="crm-ms-placeholder">— Todos —</span>
                                    </div>
                                    <div class="crm-ms-dropdown">
                                        <div class="crm-ms-search-wrap">
                                            <input class="crm-ms-search" type="text" placeholder="Pesquisar (mín. 3 letras)..."
                                                   oninput="filtrarMs('ms_status', this.value)"
                                                   onclick="event.stopPropagation()">
                                            <div class="crm-ms-hint" id="ms_status_hint">Digite ao menos 3 letras para buscar</div>
                                        </div>
                                        <div class="crm-ms-list" id="ms_status_list"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Filtro Módulo (só HISTORICO_CONSULTAS) -->
                            <div class="mb-2" id="box_filtro_modulo" style="display:none;">
                                <label class="form-label fw-bold small mb-1">Módulo de Consulta</label>
                                <select id="f_modulo" class="form-select form-select-sm border-dark">
                                    <option value="">— Todos —</option>
                                </select>
                            </div>

                            <!-- Filtro CPF/Nome para HISTORICO_CONSULTAS -->
                            <div class="mb-2" id="box_filtro_q_hc" style="display:none;">
                                <label class="form-label fw-bold small mb-1">CPF / Nome do Cliente</label>
                                <input type="text" id="f_q_hc" class="form-control form-control-sm border-dark" placeholder="CPF ou nome...">
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Usuário</label>
                                <div class="crm-ms-wrap" id="ms_usuario">
                                    <div class="crm-ms-display" onclick="toggleMs('ms_usuario', event)">
                                        <span class="crm-ms-placeholder">— Todos —</span>
                                    </div>
                                    <div class="crm-ms-dropdown">
                                        <div class="crm-ms-search-wrap">
                                            <input class="crm-ms-search" type="text" placeholder="Pesquisar (mín. 3 letras)..."
                                                   oninput="filtrarMs('ms_usuario', this.value)"
                                                   onclick="event.stopPropagation()">
                                            <div class="crm-ms-hint" id="ms_usuario_hint">Digite ao menos 3 letras para buscar</div>
                                        </div>
                                        <div class="crm-ms-list" id="ms_usuario_list"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-1">Período</label>
                                <select id="f_periodo" class="form-select form-select-sm border-dark" onchange="toggleDataPersonalizada()">
                                    <option value="todos">— Todos —</option>
                                    <option value="hoje">Hoje</option>
                                    <option value="ontem">Ontem</option>
                                    <option value="mes">Mês Atual</option>
                                    <option value="personalizado">Personalizado</option>
                                </select>
                            </div>

                            <div id="box_data_pers" style="display:none;" class="mb-2">
                                <div class="row g-1">
                                    <div class="col-6">
                                        <label class="form-label fw-bold small mb-0">De</label>
                                        <input type="date" id="f_data_ini" class="form-control form-control-sm border-dark">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-bold small mb-0">Até</label>
                                        <input type="date" id="f_data_fim" class="form-control form-control-sm border-dark">
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-1">
                                <button class="btn btn-danger btn-sm fw-bold border-dark" onclick="filtrar()">
                                    <i class="fas fa-search me-1"></i> Filtrar
                                </button>
                                <button class="btn btn-outline-secondary btn-sm border-dark" onclick="limparFiltro()">
                                    <i class="fas fa-times me-1"></i> Limpar Filtro
                                </button>
                            </div>

                        </div>
                    </div>

                    <!-- GRÁFICO -->
                    <div class="card border-dark shadow-sm rounded-2 flex-grow-1" id="box_grafico_card">
                        <div class="card-header bg-dark text-white py-2 fw-bold small text-uppercase">
                            <i class="fas fa-chart-pie me-1 text-warning"></i> Resumo
                        </div>
                        <div class="card-body p-2 text-center" id="box_grafico_inner">
                            <p class="text-muted small fst-italic mt-3">Aplique um filtro para ver o gráfico.</p>
                        </div>
                    </div>

                </div>
            </div>

            <!-- ===== PAINEL DIREITO ===== -->
            <div class="col-md-9">
                <div class="card border-dark shadow-sm rounded-2">
                    <div class="card-header bg-dark text-white py-2 d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-uppercase">
                            <i class="fas fa-list me-1 text-warning"></i>
                            Lista de Clientes
                            <span class="badge bg-warning text-dark ms-2 border border-dark" id="badge_total">0</span>
                        </span>
                        <button class="btn btn-sm btn-success border-dark fw-bold" id="btn_incluir_camp"
                                onclick="abrirModalIncluir()" disabled>
                            <i class="fas fa-plus me-1"></i> Incluir em Campanha
                        </button>
                    </div>
                    <div id="tabela_wrap">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark text-uppercase" style="position:sticky;top:0;z-index:1;">
                                <!-- Cabeçalho padrão (campanhas) -->
                                <tr id="thead_campanha">
                                    <th style="width:30px;"><input type="checkbox" id="check_all" onchange="toggleTodos(this)"></th>
                                    <th>ID</th><th>Nome / CPF</th><th>Data Registro</th>
                                    <th>Data Agendamento</th><th>Campanha</th><th>Usuário</th>
                                    <th>Status</th><th style="max-width:200px;">Anotação</th><th></th>
                                </tr>
                                <!-- Cabeçalho para HISTORICO_CONSULTAS -->
                                <tr id="thead_historico" style="display:none;">
                                    <th style="width:30px;"><input type="checkbox" onchange="toggleTodos(this)"></th>
                                    <th>Data / Hora</th><th>Módulo</th><th>Usuário</th>
                                    <th>Nome do Cliente</th><th>CPF Alvo</th><th></th>
                                </tr>
                            </thead>
                            <tbody id="tbody_relatorio">
                                <tr><td colspan="10" class="text-center text-muted py-4 fst-italic">
                                    <i class="fas fa-filter me-2"></i>Aplique um filtro para visualizar os registros.
                                </td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-light border-dark d-flex justify-content-between align-items-center py-2">
                        <span id="resumo_lista" class="text-muted small"></span>
                        <button class="btn btn-sm btn-dark border-secondary fw-bold" id="btn_ver_mais"
                                style="display:none;" onclick="verMais()">
                            <i class="fas fa-plus-circle me-1"></i> VER MAIS 100
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Modal: Incluir em Campanha -->
<div class="modal fade" id="modalIncluirCamp" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-dark rounded-2">
            <div class="modal-header bg-dark text-white border-dark">
                <h6 class="modal-title fw-bold"><i class="fas fa-plus-circle text-success me-2"></i> Incluir em Campanha</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small"><span id="qtd_selecionados_modal" class="fw-bold text-dark">0</span> cliente(s) selecionado(s)</p>
                <label class="fw-bold small mb-1">Selecione a campanha de destino:</label>
                <select id="select_camp_destino" class="form-select border-dark">
                    <option value="">— Selecione —</option>
                </select>
                <div id="msg_incluir" class="mt-2"></div>
            </div>
            <div class="modal-footer border-dark">
                <button type="button" class="btn btn-outline-secondary border-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success border-dark fw-bold" onclick="executarInclusao()">
                    <i class="fas fa-check me-1"></i> Incluir
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// =========================================================================
// MULTI-SELECT CUSTOMIZADO
// =========================================================================
const _msData     = {};   // {id: [{value, label}]}
const _msSel      = {};   // {id: [values]}

function initMs(id, dados, valKey, lblKey) {
    _msData[id] = dados.map(d => ({ value: String(d[valKey]), label: d[lblKey] }));
    _msSel[id]  = [];
    renderMsLista(id, _msData[id]);
}

function toggleMs(id, event) {
    event && event.stopPropagation();
    const dd = document.querySelector(`#${id} .crm-ms-dropdown`);
    const aberto = dd.classList.contains('aberto');
    // Fecha todos os outros
    document.querySelectorAll('.crm-ms-dropdown.aberto').forEach(el => {
        if (el !== dd) el.classList.remove('aberto');
    });
    dd.classList.toggle('aberto', !aberto);
    if (!aberto) {
        const inp = dd.querySelector('.crm-ms-search');
        if (inp) { inp.value = ''; inp.focus(); }
        renderMsLista(id, _msData[id]);
    }
}

function filtrarMs(id, texto) {
    const hint = document.getElementById(id + '_hint');
    if (texto.length > 0 && texto.length < 3) {
        if (hint) hint.style.display = 'block';
        renderMsLista(id, []);
        return;
    }
    if (hint) hint.style.display = 'none';
    const dados = _msData[id] || [];
    const filtrado = texto.length >= 3
        ? dados.filter(d => d.label.toLowerCase().includes(texto.toLowerCase()))
        : dados;
    renderMsLista(id, filtrado);
}

function renderMsLista(id, opcoes) {
    const lista = document.getElementById(id + '_list');
    if (!lista) return;
    lista.innerHTML = '';

    // Sempre primeiro: "Todos"
    const divTodos = document.createElement('div');
    divTodos.className = 'crm-ms-item todos';
    divTodos.innerHTML = '<i class="fas fa-check-double" style="font-size:.7rem;opacity:.5;"></i> — Todos —';
    divTodos.onclick = (e) => { e.stopPropagation(); limparMs(id); };
    lista.appendChild(divTodos);

    if (!opcoes || opcoes.length === 0) {
        const vazio = document.createElement('div');
        vazio.className = 'crm-ms-vazio';
        vazio.textContent = 'Nenhum resultado encontrado.';
        lista.appendChild(vazio);
        return;
    }

    opcoes.forEach(op => {
        const selecionado = (_msSel[id] || []).includes(op.value);
        const div = document.createElement('div');
        div.className = 'crm-ms-item' + (selecionado ? ' selecionado' : '');
        div.innerHTML = `<span style="width:14px;text-align:center;font-size:.7rem;">${selecionado ? '✓' : ''}</span> ${escHtml(op.label)}`;
        div.onclick = (e) => { e.stopPropagation(); toggleMsItem(id, op.value, op.label); };
        lista.appendChild(div);
    });
}

function toggleMsItem(id, valor, label) {
    if (!_msSel[id]) _msSel[id] = [];
    const idx = _msSel[id].indexOf(valor);
    if (idx >= 0) { _msSel[id].splice(idx, 1); }
    else          { _msSel[id].push(valor); }
    // Re-renderiza lista mantendo filtro atual
    const inp = document.querySelector(`#${id} .crm-ms-search`);
    const texto = inp ? inp.value : '';
    filtrarMs(id, texto);
    renderMsTags(id);
}

function limparMs(id) {
    _msSel[id] = [];
    renderMsTags(id);
    renderMsLista(id, _msData[id] || []);
    const inp = document.querySelector(`#${id} .crm-ms-search`);
    if (inp) inp.value = '';
    const hint = document.getElementById(id + '_hint');
    if (hint) hint.style.display = 'none';
}

function renderMsTags(id) {
    const display = document.querySelector(`#${id} .crm-ms-display`);
    if (!display) return;
    // Remove tags antigas
    display.querySelectorAll('.crm-ms-tag').forEach(el => el.remove());
    const placeholder = display.querySelector('.crm-ms-placeholder');

    const selecionados = _msSel[id] || [];
    if (selecionados.length === 0) {
        if (placeholder) placeholder.style.display = '';
        return;
    }
    if (placeholder) placeholder.style.display = 'none';
    selecionados.forEach(val => {
        const dado = (_msData[id] || []).find(d => d.value === val);
        if (!dado) return;
        const tag = document.createElement('span');
        tag.className = 'crm-ms-tag';
        tag.innerHTML = `${escHtml(dado.label)} <span class="crm-ms-del" onclick="event.stopPropagation();toggleMsItem('${id}','${escHtml(val)}','')">×</span>`;
        display.appendChild(tag);
    });
}

function getMsValores(id) { return _msSel[id] || []; }

// Fecha dropdowns ao clicar fora
document.addEventListener('click', () => {
    document.querySelectorAll('.crm-ms-dropdown.aberto').forEach(el => el.classList.remove('aberto'));
});

// =========================================================================
// ESTADO GLOBAL
// =========================================================================
let _tipoAtivo      = '';
let _offsetAtual    = 0;
let _totalRegistros = 0;
let _graficoInst    = null;
let _campDestino    = [];
let _filtroCacheGET = null;

const CORES_PIE = ['#dc3545','#0d6efd','#198754','#ffc107','#0dcaf0','#6f42c1','#fd7e14','#20c997','#6c757d','#e83e8c'];

// =========================================================================
// SELEÇÃO DE MODELO
// =========================================================================
function selecionarModelo(tipo) {
    _tipoAtivo = tipo;
    document.getElementById('tipo_relatorio_ativo').value = tipo;
    const titulos = { STATUS_CAMPANHA: '📊 Status de Campanha', AGENDAMENTOS_FUTURO: '📅 Agendamentos Futuros', HISTORICO_CONSULTAS: '🕐 Histórico de Consultas' };
    document.getElementById('titulo_relatorio').textContent = titulos[tipo] || tipo;
    document.getElementById('box_modelos').style.display   = 'none';
    document.getElementById('box_relatorio').style.display = 'block';
    // Mostrar/ocultar filtros e colunas por modelo
    const isHC = tipo === 'HISTORICO_CONSULTAS';
    document.getElementById('box_filtro_modulo').style.display  = isHC ? 'block' : 'none';
    document.getElementById('box_filtro_q_hc').style.display    = isHC ? 'block' : 'none';
    document.getElementById('f_agrupamento').style.display      = isHC ? 'none'  : 'block';
    document.getElementById('f_agrupamento_hc').style.display   = isHC ? 'block' : 'none';
    document.getElementById('thead_campanha').style.display     = isHC ? 'none'  : '';
    document.getElementById('thead_historico').style.display    = isHC ? ''      : 'none';
    carregarFiltros();
}

function voltarModelos() {
    document.getElementById('box_relatorio').style.display = 'none';
    document.getElementById('box_modelos').style.display   = 'block';
    _tipoAtivo = ''; _offsetAtual = 0;
}

// =========================================================================
// CARREGAR OPÇÕES DOS FILTROS
// =========================================================================
function carregarFiltros() {
    const fd = new FormData();
    fd.append('acao', 'listar_filtros');
    fetch('relatorio_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (!r.success) return;
            initMs('ms_campanha', r.campanhas,  'ID', 'NOME_CAMPANHA');
            initMs('ms_status',   r.status,     'ID', 'NOME_STATUS');
            initMs('ms_usuario',  r.usuarios,   'ID', 'NOME');
            // Módulos para HISTORICO_CONSULTAS
            const selMod = document.getElementById('f_modulo');
            if (selMod && r.modulos) {
                selMod.innerHTML = '<option value="">— Todos —</option>';
                (r.modulos || []).forEach(m => selMod.innerHTML += `<option value="${escHtml(m)}">${escHtml(m)}</option>`);
            }
            if (r.is_master) {
                const sel = document.getElementById('f_empresa');
                if (sel) {
                    sel.innerHTML = '<option value="">— Todas —</option>';
                    (r.empresas || []).forEach(e => {
                        sel.innerHTML += `<option value="${e.ID}">${escHtml(e.NOME_CADASTRO)}</option>`;
                    });
                }
            }
            _campDestino = r.campanhas_destino || [];
            const dest = document.getElementById('select_camp_destino');
            dest.innerHTML = '<option value="">— Selecione —</option>';
            _campDestino.forEach(c => dest.innerHTML += `<option value="${c.ID}">${escHtml(c.NOME_CAMPANHA)}</option>`);
        });
}

// =========================================================================
// FILTRAR
// =========================================================================
function toggleDataPersonalizada() {
    document.getElementById('box_data_pers').style.display =
        document.getElementById('f_periodo').value === 'personalizado' ? 'block' : 'none';
}

function lerFiltros() {
    const emp = document.getElementById('f_empresa');
    const tipoAtivo = document.getElementById('tipo_relatorio_ativo').value;
    const isHC = tipoAtivo === 'HISTORICO_CONSULTAS';
    return {
        tipo        : tipoAtivo,
        agrupamento : isHC ? document.getElementById('f_agrupamento_hc').value : document.getElementById('f_agrupamento').value,
        modulo      : isHC ? (document.getElementById('f_modulo')?.value || '') : '',
        q           : isHC ? (document.getElementById('f_q_hc')?.value || '') : '',
        empresa     : emp ? emp.value : '',
        campanha : getMsValores('ms_campanha'),
        status   : getMsValores('ms_status'),
        usuario  : getMsValores('ms_usuario'),
        periodo  : document.getElementById('f_periodo').value,
        data_ini : (document.getElementById('f_data_ini') || {}).value || '',
        data_fim : (document.getElementById('f_data_fim') || {}).value || '',
    };
}

function filtrar() {
    _offsetAtual = 0;
    document.getElementById('tbody_relatorio').innerHTML =
        '<tr><td colspan="10" class="text-center py-4"><div class="spinner-border spinner-border-sm text-danger me-2"></div> Carregando...</td></tr>';
    buscarDados(0, true);
}

function limparFiltro() {
    const emp = document.getElementById('f_empresa');
    if (emp) emp.value = '';
    limparMs('ms_campanha');
    limparMs('ms_status');
    limparMs('ms_usuario');
    document.getElementById('f_periodo').value = 'todos';
    const di = document.getElementById('f_data_ini'); if (di) di.value = '';
    const df = document.getElementById('f_data_fim'); if (df) df.value = '';
    document.getElementById('box_data_pers').style.display = 'none';
    document.getElementById('tbody_relatorio').innerHTML =
        '<tr><td colspan="10" class="text-center text-muted py-4 fst-italic"><i class="fas fa-filter me-2"></i>Aplique um filtro para visualizar os registros.</td></tr>';
    document.getElementById('badge_total').textContent = '0';
    document.getElementById('resumo_lista').textContent = '';
    document.getElementById('btn_ver_mais').style.display = 'none';
    document.getElementById('box_grafico_inner').innerHTML = '<p class="text-muted small fst-italic mt-3">Aplique um filtro para ver o gráfico.</p>';
    if (_graficoInst) { _graficoInst.destroy(); _graficoInst = null; }
    atualizarBotoesSel();
    _filtroCacheGET = null;
    const bt = document.getElementById('btn_exp_tudo'); if (bt) bt.disabled = true;
    const bs = document.getElementById('btn_exp_sel');  if (bs) bs.disabled = true;
}

// =========================================================================
// BUSCAR DADOS VIA AJAX
// =========================================================================
function buscarDados(offset, resetLista) {
    const filtros = lerFiltros();
    const fd = new FormData();
    fd.append('acao', 'buscar_dados');
    fd.append('offset', offset);
    Object.entries(filtros).forEach(([k, v]) => {
        if (Array.isArray(v)) { v.forEach(i => fd.append(k + '[]', i)); }
        else { fd.append(k, v); }
    });

    _filtroCacheGET = new URLSearchParams();
    _filtroCacheGET.append('acao', 'exportar_csv');
    Object.entries(filtros).forEach(([k, v]) => {
        if (Array.isArray(v)) { v.forEach(i => _filtroCacheGET.append(k + '[]', i)); }
        else { _filtroCacheGET.append(k, v); }
    });

    fetch('relatorio_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (!r.success) {
                document.getElementById('tbody_relatorio').innerHTML =
                    `<tr><td colspan="10" class="text-center text-danger py-4">${r.msg}</td></tr>`;
                return;
            }
            renderLista(r.lista, resetLista);
            _totalRegistros = r.total;
            _offsetAtual    = offset + r.lista.length;
            document.getElementById('badge_total').textContent  = r.total;
            document.getElementById('resumo_lista').textContent = `Exibindo ${_offsetAtual} de ${r.total} registro(s)`;
            document.getElementById('btn_ver_mais').style.display = r.tem_mais ? 'inline-block' : 'none';
            if (resetLista) renderGrafico(r.grafico, r.tipo_grafico);
            const bt = document.getElementById('btn_exp_tudo'); if (bt) bt.disabled = r.total === 0;
        })
        .catch(() => {
            document.getElementById('tbody_relatorio').innerHTML =
                '<tr><td colspan="10" class="text-center text-danger py-4">Falha de comunicação com o servidor.</td></tr>';
        });
}

function verMais() { buscarDados(_offsetAtual, false); }

// =========================================================================
// RENDERIZAR LISTA
// =========================================================================
function renderLista(lista, reset) {
    const tbody  = document.getElementById('tbody_relatorio');
    const tipoAt = document.getElementById('tipo_relatorio_ativo').value;
    const isHC   = tipoAt === 'HISTORICO_CONSULTAS';
    const cols   = isHC ? 7 : 10;
    if (reset) tbody.innerHTML = '';
    if (!lista || lista.length === 0) {
        if (reset) tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-4 fst-italic">Nenhum registro encontrado.</td></tr>`;
        return;
    }
    lista.forEach(r => {
        const tr = document.createElement('tr');
        tr.className = 'linha-cliente';
        if (isHC) {
            const cpfFmt = (r.CPF_CLIENTE||'').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            tr.innerHTML = `
                <td><input type="checkbox" class="check-linha" value="${escHtml(r.CPF_CLIENTE||'')}" onchange="atualizarBotoesSel()"></td>
                <td class="text-nowrap text-muted small">${r.DATA_BR||'—'}</td>
                <td><span class="badge bg-secondary border border-dark" style="font-size:.7rem;">${escHtml(r.MODULO_CONSULTA||'—')}</span></td>
                <td class="text-nowrap small">${escHtml(r.NOME_USUARIO||'—')}</td>
                <td class="fw-bold text-dark small">${escHtml(r.NOME_CLIENTE||'—')}</td>
                <td class="text-muted small">${cpfFmt||'—'}</td>
                <td>
                    ${r.CPF_CLIENTE ? `<a href="/modulos/banco_dados/consulta.php?busca=${r.CPF_CLIENTE}&cpf_selecionado=${r.CPF_CLIENTE}&acao=visualizar"
                       target="_blank" class="btn btn-xs btn-sm btn-outline-primary border-dark py-0 px-1" title="Ver Cliente">
                        <i class="fas fa-external-link-alt"></i></a>` : ''}
                </td>`;
        } else {
            const agend = r.DATA_AGENDAMENTO_FMT
                ? `<span class="badge bg-info text-dark border border-dark">${r.DATA_AGENDAMENTO_FMT}</span>`
                : '<span class="text-muted">—</span>';
            const anot = r.ANOTACAO
                ? `<span title="${escHtml(r.ANOTACAO)}">${escHtml(r.ANOTACAO.substring(0,50))}${r.ANOTACAO.length>50?'…':''}</span>`
                : '<span class="text-muted">—</span>';
            const cpfFmt = r.CPF_CLIENTE.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
            const camps  = r.CAMPANHAS ? `<small class="text-primary fw-bold">${escHtml(r.CAMPANHAS)}</small>` : '<span class="text-muted">—</span>';
            tr.innerHTML = `
                <td><input type="checkbox" class="check-linha" value="${escHtml(r.CPF_CLIENTE)}" onchange="atualizarBotoesSel()"></td>
                <td class="text-muted">${r.ID}</td>
                <td><span class="fw-bold text-dark">${escHtml(r.NOME_CLIENTE)}</span><br><small class="text-muted">${cpfFmt}</small></td>
                <td class="text-nowrap text-muted">${r.DATA_REGISTRO_FMT||'—'}</td>
                <td class="text-nowrap">${agend}</td>
                <td>${camps}</td>
                <td class="text-nowrap"><small>${escHtml(r.NOME_USUARIO)}</small></td>
                <td><span class="badge badge-status bg-secondary border border-dark">${escHtml(r.NOME_STATUS)}</span></td>
                <td>${anot}</td>
                <td><a href="/modulos/banco_dados/consulta.php?busca=${r.CPF_CLIENTE}&cpf_selecionado=${r.CPF_CLIENTE}&acao=visualizar"
                       target="_blank" class="btn btn-xs btn-sm btn-outline-primary border-dark py-0 px-1"><i class="fas fa-external-link-alt"></i></a></td>`;
        }
        tbody.appendChild(tr);
    });
}

// =========================================================================
// GRÁFICO
// =========================================================================
function renderGrafico(dados, tipo) {
    const box = document.getElementById('box_grafico_inner');
    box.innerHTML = '';
    if (_graficoInst) { _graficoInst.destroy(); _graficoInst = null; }
    if (!dados || dados.length === 0) {
        box.innerHTML = '<p class="text-muted small fst-italic mt-3">Sem dados para o gráfico.</p>';
        return;
    }
    const labels = dados.map(d => d.LABEL);
    const totais = dados.map(d => parseInt(d.TOTAL));
    const cores  = dados.map((_, i) => CORES_PIE[i % CORES_PIE.length]);

    const wrap = document.createElement('div');
    wrap.style.cssText = 'width:100%;max-height:200px;position:relative;';
    const canvas = document.createElement('canvas');
    wrap.appendChild(canvas);
    box.appendChild(wrap);

    const resumoDiv = document.createElement('div');
    resumoDiv.style.cssText = 'margin-top:8px;width:100%;overflow-y:auto;max-height:120px;';
    const totalGeral = totais.reduce((a,b)=>a+b,0);
    dados.forEach((d, i) => {
        const pct = totalGeral > 0 ? Math.round(d.TOTAL / totalGeral * 100) : 0;
        resumoDiv.innerHTML += `<div class="d-flex align-items-center border-bottom py-1">
            <span style="display:inline-block;width:10px;height:10px;background:${cores[i]};border-radius:2px;margin-right:5px;flex-shrink:0;"></span>
            <span class="text-start flex-grow-1 small text-truncate" style="max-width:130px;" title="${escHtml(d.LABEL)}">${escHtml(d.LABEL)}</span>
            <span class="fw-bold small ms-1">${d.TOTAL} <small class="text-muted">(${pct}%)</small></span>
        </div>`;
    });
    box.appendChild(resumoDiv);

    _graficoInst = new Chart(canvas, {
        type: tipo,
        data: {
            labels,
            datasets: [{ data: totais, backgroundColor: tipo==='pie'?cores:'#0d6efd', borderColor:'#fff', borderWidth: tipo==='pie'?2:0 }]
        },
        options: { responsive:true, maintainAspectRatio:true, plugins:{ legend:{display:false} },
            scales: tipo==='bar' ? { y:{beginAtZero:true, ticks:{precision:0}} } : {} }
    });
}

// =========================================================================
// SELEÇÃO / BOTÕES
// =========================================================================
function toggleTodos(el) {
    document.querySelectorAll('.check-linha').forEach(c => c.checked = el.checked);
    atualizarBotoesSel();
}
function atualizarBotoesSel() {
    const qtd = document.querySelectorAll('.check-linha:checked').length;
    const bi = document.getElementById('btn_incluir_camp'); if (bi) bi.disabled = qtd===0;
    const bs = document.getElementById('btn_exp_sel');      if (bs) bs.disabled = qtd===0;
}
function cpfsSelecionados() {
    return Array.from(document.querySelectorAll('.check-linha:checked')).map(c => c.value);
}

// =========================================================================
// INCLUIR EM CAMPANHA
// =========================================================================
function abrirModalIncluir() {
    document.getElementById('qtd_selecionados_modal').textContent = cpfsSelecionados().length;
    document.getElementById('msg_incluir').innerHTML = '';
    new bootstrap.Modal(document.getElementById('modalIncluirCamp')).show();
}
function executarInclusao() {
    const id_camp = document.getElementById('select_camp_destino').value;
    const cpfs    = cpfsSelecionados();
    if (!id_camp) { document.getElementById('msg_incluir').innerHTML = '<div class="alert alert-warning py-1 small">Selecione uma campanha.</div>'; return; }
    if (!cpfs.length) { document.getElementById('msg_incluir').innerHTML = '<div class="alert alert-warning py-1 small">Nenhum cliente selecionado.</div>'; return; }
    const fd = new FormData();
    fd.append('acao','incluir_em_campanha'); fd.append('id_campanha_dest', id_camp);
    cpfs.forEach(c => fd.append('cpfs[]', c));
    fetch('relatorio_ajax.php',{method:'POST',body:fd}).then(r=>r.json()).then(r=>{
        const cls = r.success?'success':'danger';
        document.getElementById('msg_incluir').innerHTML=`<div class="alert alert-${cls} py-1 small">${r.msg}</div>`;
        if (r.success) setTimeout(()=>bootstrap.Modal.getInstance(document.getElementById('modalIncluirCamp')).hide(),1500);
    });
}

// =========================================================================
// EXPORTAR
// =========================================================================
function exportar(modo) {
    if (!_filtroCacheGET) return;
    const params = new URLSearchParams(_filtroCacheGET.toString());
    if (modo === 'selecionados') {
        const cpfs = cpfsSelecionados();
        if (!cpfs.length) { alert('Selecione ao menos um cliente.'); return; }
        params.append('cpfs_selecionados', cpfs.join(','));
    }
    window.open('relatorio_ajax.php?' + params.toString(), '_blank');
}

// =========================================================================
// UTIL
// =========================================================================
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
