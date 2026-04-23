<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao  = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header   = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
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
#box_modelos .card-modelo {
    cursor: pointer; transition: transform .15s, box-shadow .15s; border: 2px solid #dee2e6;
}
#box_modelos .card-modelo:hover { transform: translateY(-4px); box-shadow: 0 8px 24px rgba(0,0,0,.18); border-color: #dc3545; }
#box_modelos .card-modelo.selecionado { border-color: #dc3545; background: #fff5f5; }
#painel_esquerdo { position: sticky; top: 70px; height: calc(100vh - 90px); display: flex; flex-direction: column; }
#box_filtros_card { flex: 0 0 auto; }
#box_grafico_card { flex: 1 1 auto; min-height: 0; overflow: hidden; }
#box_grafico_card .card-body { height: 100%; display: flex; flex-direction: column; justify-content: center; align-items: center; }
#grafico_canvas_wrap { position: relative; width: 100%; flex: 1 1 auto; min-height: 0; }
#tabela_wrap { max-height: calc(100vh - 200px); overflow-y: auto; }
.badge-status { font-size: .72rem; padding: 3px 7px; }
select[multiple] { height: auto; min-height: 38px; max-height: 90px; }
#resumo_lista { font-size: .82rem; }
.linha-cliente td { vertical-align: middle; font-size: .82rem; }
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
                    <p class="text-muted small mb-0">Visualize os registros de contato agrupados por status, campanha e período. Gráfico de pizza.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card card-modelo shadow-sm rounded-3 p-3 text-center" onclick="selecionarModelo('AGENDAMENTOS_FUTURO')">
                    <div class="mb-3"><i class="fas fa-calendar-check fa-3x text-primary"></i></div>
                    <h5 class="fw-bold text-dark">AGENDAMENTOS FUTUROS</h5>
                    <p class="text-muted small mb-0">Visualize os clientes com retorno agendado. Gráfico de barras por data.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- ===================== LAYOUT DO RELATÓRIO ===================== -->
    <div id="box_relatorio" style="display:none;">

        <!-- Cabeçalho do relatório -->
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

            <!-- ===== PAINEL ESQUERDO (filtros + gráfico) ===== -->
            <div class="col-md-3 col-lg-3">
                <div id="painel_esquerdo">

                    <!-- FILTROS -->
                    <div class="card border-dark shadow-sm rounded-2 mb-2" id="box_filtros_card">
                        <div class="card-header bg-dark text-white py-2 fw-bold small text-uppercase">
                            <i class="fas fa-filter me-1 text-warning"></i> Filtro
                        </div>
                        <div class="card-body p-2">

                            <input type="hidden" id="tipo_relatorio_ativo">

                            <?php if ($is_master): ?>
                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-0">Empresa</label>
                                <select id="f_empresa" class="form-select form-select-sm border-dark">
                                    <option value="">— Todas —</option>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-0">Campanha <span class="text-muted fw-normal">(Ctrl+clique)</span></label>
                                <select id="f_campanha" class="form-select form-select-sm border-dark" multiple>
                                    <option value="">— Todas —</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-0">Status <span class="text-muted fw-normal">(Ctrl+clique)</span></label>
                                <select id="f_status" class="form-select form-select-sm border-dark" multiple>
                                    <option value="">— Todos —</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-0">Usuário <span class="text-muted fw-normal">(Ctrl+clique)</span></label>
                                <select id="f_usuario" class="form-select form-select-sm border-dark" multiple>
                                    <option value="">— Todos —</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label fw-bold small mb-0">Período</label>
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

            <!-- ===== PAINEL DIREITO (lista) ===== -->
            <div class="col-md-9 col-lg-9">
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
                                <tr>
                                    <th style="width:30px;"><input type="checkbox" id="check_all" onchange="toggleTodos(this)"></th>
                                    <th>ID</th>
                                    <th>Nome / CPF</th>
                                    <th>Data Registro</th>
                                    <th>Data Agendamento</th>
                                    <th>Campanha</th>
                                    <th>Usuário</th>
                                    <th>Status</th>
                                    <th style="max-width:200px;">Anotação</th>
                                    <th></th>
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

<!-- ===== MODAL: Incluir em Campanha ===== -->
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// =========================================================================
// Estado global
// =========================================================================
let _tipoAtivo     = '';
let _offsetAtual   = 0;
let _totalRegistros= 0;
let _graficoInst   = null;
let _campDestino   = [];
let _filtroCacheGET= '';

const CORES_PIE = [
    '#dc3545','#0d6efd','#198754','#ffc107','#0dcaf0','#6f42c1',
    '#fd7e14','#20c997','#6c757d','#e83e8c','#17a2b8','#343a40'
];

// =========================================================================
// Seleção de modelo
// =========================================================================
function selecionarModelo(tipo) {
    _tipoAtivo = tipo;
    document.getElementById('tipo_relatorio_ativo').value = tipo;
    const titulos = {
        'STATUS_CAMPANHA'    : '📊 Status de Campanha',
        'AGENDAMENTOS_FUTURO': '📅 Agendamentos Futuros',
    };
    document.getElementById('titulo_relatorio').textContent = titulos[tipo] || tipo;
    document.getElementById('box_modelos').style.display  = 'none';
    document.getElementById('box_relatorio').style.display = 'block';
    carregarFiltros();
}

function voltarModelos() {
    document.getElementById('box_relatorio').style.display = 'none';
    document.getElementById('box_modelos').style.display   = 'block';
    _tipoAtivo = '';
    _offsetAtual = 0;
}

// =========================================================================
// Carregar opções dos filtros (select options)
// =========================================================================
function carregarFiltros() {
    const fd = new FormData();
    fd.append('acao', 'listar_filtros');
    fetch('relatorio_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            if (!r.success) return;
            popularSelect('f_campanha', r.campanhas, 'ID', 'NOME_CAMPANHA', '— Todas —');
            popularSelect('f_status',   r.status,    'ID', 'NOME_STATUS',   '— Todos —');
            popularSelect('f_usuario',  r.usuarios,  'ID', 'NOME',          '— Todos —');
            if (r.is_master) {
                popularSelect('f_empresa', r.empresas, 'ID', 'NOME_CADASTRO', '— Todas —');
            }
            _campDestino = r.campanhas_destino || [];
            const sel = document.getElementById('select_camp_destino');
            sel.innerHTML = '<option value="">— Selecione —</option>';
            _campDestino.forEach(c => {
                const o = document.createElement('option');
                o.value = c.ID; o.textContent = c.NOME_CAMPANHA;
                sel.appendChild(o);
            });
        });
}

function popularSelect(id, dados, valKey, lblKey, textoTodos) {
    const sel = document.getElementById(id);
    if (!sel) return;
    sel.innerHTML = `<option value="">${textoTodos}</option>`;
    (dados || []).forEach(d => {
        const o = document.createElement('option');
        o.value = d[valKey]; o.textContent = d[lblKey];
        sel.appendChild(o);
    });
}

// =========================================================================
// Filtrar
// =========================================================================
function toggleDataPersonalizada() {
    const v = document.getElementById('f_periodo').value;
    document.getElementById('box_data_pers').style.display = (v === 'personalizado') ? 'block' : 'none';
}

function lerFiltros() {
    const getMulti = (id) => {
        const sel = document.getElementById(id);
        if (!sel) return [];
        return Array.from(sel.selectedOptions).map(o => o.value).filter(v => v !== '');
    };
    return {
        tipo     : document.getElementById('tipo_relatorio_ativo').value,
        empresa  : document.getElementById('f_empresa') ? document.getElementById('f_empresa').value : '',
        campanha : getMulti('f_campanha'),
        status   : getMulti('f_status'),
        usuario  : getMulti('f_usuario'),
        periodo  : document.getElementById('f_periodo').value,
        data_ini : document.getElementById('f_data_ini') ? document.getElementById('f_data_ini').value : '',
        data_fim : document.getElementById('f_data_fim') ? document.getElementById('f_data_fim').value : '',
    };
}

function filtrar() {
    _offsetAtual = 0;
    document.getElementById('tbody_relatorio').innerHTML =
        '<tr><td colspan="10" class="text-center py-4"><div class="spinner-border spinner-border-sm text-danger me-2"></div> Carregando...</td></tr>';
    buscarDados(0, true);
}

function limparFiltro() {
    ['f_empresa','f_campanha','f_status','f_usuario'].forEach(id => {
        const el = document.getElementById(id); if (el) { el.value = ''; Array.from(el.options).forEach(o => o.selected = false); }
    });
    document.getElementById('f_periodo').value = 'todos';
    if (document.getElementById('f_data_ini')) document.getElementById('f_data_ini').value = '';
    if (document.getElementById('f_data_fim')) document.getElementById('f_data_fim').value = '';
    document.getElementById('box_data_pers').style.display = 'none';
    document.getElementById('tbody_relatorio').innerHTML =
        '<tr><td colspan="10" class="text-center text-muted py-4 fst-italic"><i class="fas fa-filter me-2"></i>Aplique um filtro para visualizar os registros.</td></tr>';
    document.getElementById('badge_total').textContent = '0';
    document.getElementById('resumo_lista').textContent = '';
    document.getElementById('btn_ver_mais').style.display = 'none';
    document.getElementById('box_grafico_inner').innerHTML = '<p class="text-muted small fst-italic mt-3">Aplique um filtro para ver o gráfico.</p>';
    if (_graficoInst) { _graficoInst.destroy(); _graficoInst = null; }
    atualizarBotoesSel();
}

// =========================================================================
// Buscar dados via AJAX
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
    // Salvar params para exportar
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
            document.getElementById('badge_total').textContent = r.total;
            document.getElementById('resumo_lista').textContent =
                `Exibindo ${_offsetAtual} de ${r.total} registro(s)`;
            document.getElementById('btn_ver_mais').style.display = r.tem_mais ? 'inline-block' : 'none';
            if (resetLista) renderGrafico(r.grafico, r.tipo_grafico);
            const btns = document.querySelectorAll('#btn_exp_tudo, #btn_exp_sel');
            btns.forEach(b => { if (b) b.disabled = r.total === 0; });
        })
        .catch(() => {
            document.getElementById('tbody_relatorio').innerHTML =
                '<tr><td colspan="10" class="text-center text-danger py-4">Falha de comunicação com o servidor.</td></tr>';
        });
}

function verMais() {
    buscarDados(_offsetAtual, false);
}

// =========================================================================
// Renderizar lista
// =========================================================================
function renderLista(lista, reset) {
    const tbody = document.getElementById('tbody_relatorio');
    if (reset) tbody.innerHTML = '';

    if (!lista || lista.length === 0) {
        if (reset) tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4 fst-italic">Nenhum registro encontrado para os filtros selecionados.</td></tr>';
        return;
    }

    lista.forEach(r => {
        const agend = r.DATA_AGENDAMENTO_FMT
            ? `<span class="badge bg-info text-dark border border-dark">${r.DATA_AGENDAMENTO_FMT}</span>`
            : '<span class="text-muted">—</span>';
        const anotacao = r.ANOTACAO
            ? `<span title="${escHtml(r.ANOTACAO)}">${escHtml(r.ANOTACAO.substring(0, 50))}${r.ANOTACAO.length > 50 ? '…' : ''}</span>`
            : '<span class="text-muted">—</span>';
        const cpfFmt = r.CPF_CLIENTE.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        const camps  = r.CAMPANHAS
            ? `<small class="text-primary fw-bold">${escHtml(r.CAMPANHAS)}</small>`
            : '<span class="text-muted">—</span>';

        const tr = document.createElement('tr');
        tr.className = 'linha-cliente';
        tr.dataset.cpf = r.CPF_CLIENTE;
        tr.innerHTML = `
            <td><input type="checkbox" class="check-linha" value="${escHtml(r.CPF_CLIENTE)}" onchange="atualizarBotoesSel()"></td>
            <td class="text-muted">${r.ID}</td>
            <td><span class="fw-bold text-dark">${escHtml(r.NOME_CLIENTE)}</span><br><small class="text-muted">${cpfFmt}</small></td>
            <td class="text-nowrap text-muted">${r.DATA_REGISTRO_FMT || '—'}</td>
            <td class="text-nowrap">${agend}</td>
            <td>${camps}</td>
            <td class="text-nowrap"><small>${escHtml(r.NOME_USUARIO)}</small></td>
            <td><span class="badge badge-status bg-secondary border border-dark">${escHtml(r.NOME_STATUS)}</span></td>
            <td>${anotacao}</td>
            <td>
                <a href="/modulos/banco_dados/consulta.php?busca=${r.CPF_CLIENTE}&cpf_selecionado=${r.CPF_CLIENTE}&acao=visualizar"
                   target="_blank" class="btn btn-xs btn-sm btn-outline-primary border-dark py-0 px-1" title="Ver Cliente">
                    <i class="fas fa-external-link-alt"></i>
                </a>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

// =========================================================================
// Gráfico
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
    wrap.id = 'grafico_canvas_wrap';
    wrap.style.cssText = 'width:100%;max-height:200px;position:relative;';
    const canvas = document.createElement('canvas');
    canvas.id = 'grafico_canvas';
    wrap.appendChild(canvas);
    box.appendChild(wrap);

    // Resumo textual
    const resumoDiv = document.createElement('div');
    resumoDiv.id = 'resumo_grafico';
    resumoDiv.style.cssText = 'margin-top:8px;width:100%;overflow-y:auto;max-height:120px;';
    dados.forEach((d, i) => {
        const pct = totais.reduce((a,b)=>a+b,0) > 0 ? Math.round(d.TOTAL / totais.reduce((a,b)=>a+b,0) * 100) : 0;
        resumoDiv.innerHTML += `<div class="d-flex justify-content-between align-items-center border-bottom py-1">
            <span style="display:inline-block;width:10px;height:10px;background:${cores[i]};border-radius:2px;margin-right:5px;flex-shrink:0;"></span>
            <span class="text-start flex-grow-1 small text-truncate" style="max-width:130px;">${escHtml(d.LABEL)}</span>
            <span class="fw-bold small ms-1">${d.TOTAL} <small class="text-muted">(${pct}%)</small></span>
        </div>`;
    });
    box.appendChild(resumoDiv);

    _graficoInst = new Chart(canvas, {
        type: tipo,
        data: {
            labels: labels,
            datasets: [{
                data: totais,
                backgroundColor: tipo === 'pie' ? cores : '#0d6efd',
                borderColor: '#fff',
                borderWidth: tipo === 'pie' ? 2 : 0,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: tipo === 'bar' ? {
                y: { beginAtZero: true, ticks: { precision: 0 } }
            } : {},
        }
    });
}

// =========================================================================
// Selecionar todos / botões dependentes
// =========================================================================
function toggleTodos(el) {
    document.querySelectorAll('.check-linha').forEach(c => c.checked = el.checked);
    atualizarBotoesSel();
}

function atualizarBotoesSel() {
    const qtd = document.querySelectorAll('.check-linha:checked').length;
    const btnInc = document.getElementById('btn_incluir_camp');
    const btnSel = document.getElementById('btn_exp_sel');
    if (btnInc) btnInc.disabled = qtd === 0;
    if (btnSel) btnSel.disabled = qtd === 0;
}

function cpfsSelecionados() {
    return Array.from(document.querySelectorAll('.check-linha:checked')).map(c => c.value);
}

// =========================================================================
// Incluir em campanha
// =========================================================================
function abrirModalIncluir() {
    const qtd = cpfsSelecionados().length;
    document.getElementById('qtd_selecionados_modal').textContent = qtd;
    document.getElementById('msg_incluir').innerHTML = '';
    new bootstrap.Modal(document.getElementById('modalIncluirCamp')).show();
}

function executarInclusao() {
    const id_camp = document.getElementById('select_camp_destino').value;
    const cpfs    = cpfsSelecionados();
    if (!id_camp) { document.getElementById('msg_incluir').innerHTML = '<div class="alert alert-warning py-1 small">Selecione uma campanha.</div>'; return; }
    if (!cpfs.length) { document.getElementById('msg_incluir').innerHTML = '<div class="alert alert-warning py-1 small">Nenhum cliente selecionado.</div>'; return; }

    const fd = new FormData();
    fd.append('acao', 'incluir_em_campanha');
    fd.append('id_campanha_dest', id_camp);
    cpfs.forEach(c => fd.append('cpfs[]', c));

    fetch('relatorio_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(r => {
            const cls = r.success ? 'success' : 'danger';
            document.getElementById('msg_incluir').innerHTML = `<div class="alert alert-${cls} py-1 small">${r.msg}</div>`;
            if (r.success) setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('modalIncluirCamp')).hide(), 1500);
        });
}

// =========================================================================
// Exportar CSV
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
// Util
// =========================================================================
function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
