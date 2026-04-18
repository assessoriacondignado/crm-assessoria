<!-- =====================================================================
     ABA AUDITORIA V8  —  index.api.v8.auditoria.php
     Requer permissão v8_AUDITORIA_SUBMENU
===================================================================== -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold text-danger m-0"><i class="fas fa-shield-alt me-2"></i>AUDITORIA V8 — Lotes Transferidos</h5>
    <button class="btn btn-sm btn-outline-danger fw-bold" onclick="audCarregarLista()">
        <i class="fas fa-sync-alt me-1"></i> Atualizar
    </button>
</div>

<div id="aud-lista-wrap">
    <div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i><p class="mt-2">Carregando auditorias...</p></div>
</div>

<!-- Modal Detalhes Auditoria -->
<div class="modal fade" id="audModalDetalhes" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header py-2 px-3" style="background:#b02a37; color:#fff; border-bottom:none;">
                <h6 class="modal-title fw-bold mb-0" id="audModalTitulo"><i class="fas fa-shield-alt me-2"></i>Auditoria</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Filtros -->
                <div class="p-2 bg-light border-bottom d-flex gap-2 align-items-center flex-wrap" style="font-size:12px;">
                    <input type="text" id="audDetalhesFiltro" class="form-control form-control-sm" style="max-width:200px;"
                           placeholder="Filtrar CPF ou nome..." oninput="audFiltrarDetalhes()">
                    <select id="audDetalhesStatus" class="form-select form-select-sm" style="max-width:175px;" onchange="audFiltrarDetalhes()">
                        <option value="">Todos os Status</option>
                        <option value="OK">✅ OK</option>
                        <option value="ERRO SIMULACAO">🔢 Erro Simulação</option>
                        <option value="ERRO MARGEM">⚠️ Erro Margem</option>
                        <option value="NA FILA">⏳ Na Fila</option>
                    </select>
                    <span class="ms-auto text-muted fw-bold" id="audDetalhesContador" style="font-size:11px;"></span>
                    <button class="btn btn-sm fw-bold" style="background:#b02a37; color:#fff; border:none; font-size:11px; padding:4px 10px;"
                            onclick="audExportarCSV()">
                        <i class="fas fa-file-csv me-1"></i> Exportar CSV
                    </button>
                </div>
                <div id="audDetalhesTabela" style="max-height:65vh; overflow-y:auto;">
                    <div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let audTodasAuditorias = [];
let audDetalhesCpfs    = [];
let audAuditoriaAtual  = null;
let audModalDetalhesObj = null;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('audModalDetalhes'))
        audModalDetalhesObj = new bootstrap.Modal(document.getElementById('audModalDetalhes'));

    const tabAud = document.querySelector('[data-bs-target="#tab-auditoria-v8"]');
    if (tabAud) tabAud.addEventListener('shown.bs.tab', () => audCarregarLista());
});

// ---- Requisição centralizada ------------------------------------------
async function audReq(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    for (const [k, v] of Object.entries(dados)) fd.append(k, v);
    const r = await fetch('ajax_api_v8_auditoria.php', { method: 'POST', body: fd });
    return r.json();
}

// ---- Carregar lista de auditorias -------------------------------------
async function audCarregarLista() {
    document.getElementById('aud-lista-wrap').innerHTML =
        '<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const r = await audReq('listar_auditorias');
    if (!r.success) {
        document.getElementById('aud-lista-wrap').innerHTML =
            `<div class="alert alert-danger">${r.msg}</div>`;
        return;
    }
    audTodasAuditorias = r.data || [];
    audRenderizarLista();
}

function audRenderizarLista() {
    const wrap = document.getElementById('aud-lista-wrap');
    if (!audTodasAuditorias.length) {
        wrap.innerHTML = `<div class="alert alert-secondary text-center py-4">
            <i class="fas fa-shield-alt fa-2x text-muted mb-2 d-block"></i>
            <strong>Nenhuma auditoria registrada ainda.</strong><br>
            <small class="text-muted">Use o botão <strong>Auditoria</strong> na lista de clientes de um lote para transferir registros.</small>
        </div>`;
        return;
    }

    let html = `<div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0" style="font-size:13px;">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Nome da Auditoria</th>
                <th>Lote de Origem</th>
                <th>Auditor</th>
                <th class="text-center">CPFs</th>
                <th class="text-center">Status</th>
                <th class="text-center">Data</th>
                <th class="text-center">Ações</th>
            </tr>
        </thead>
        <tbody>`;

    audTodasAuditorias.forEach(a => {
        const badgeStatus = a.STATUS_AUDITORIA === 'ATIVO'
            ? '<span class="badge" style="background:#b02a37;">ATIVO</span>'
            : '<span class="badge bg-secondary">ARQUIVADO</span>';
        html += `<tr>
            <td><strong>#${a.ID}</strong></td>
            <td><span class="fw-bold">${audEsc(a.NOME_AUDITORIA)}</span><br>
                <small class="text-muted">${a.TABELA_ORIGEM ? 'Origem: ' + audEsc(a.TABELA_ORIGEM) : ''}</small></td>
            <td><small>${audEsc(a.LOTE_ORIGEM_NOME || '—')}</small></td>
            <td><small>${audEsc(a.USUARIO_AUDITOR_NOME || '—')}</small></td>
            <td class="text-center"><span class="badge bg-dark">${a.QTD_REAL}</span></td>
            <td class="text-center">${badgeStatus}</td>
            <td class="text-center" style="white-space:nowrap;">${a.DATA_BR}</td>
            <td class="text-center">
                <button class="btn btn-sm fw-bold" style="background:#b02a37; color:#fff; font-size:11px; padding:3px 10px; border:none;"
                        onclick="audAbrirDetalhes(${a.ID}, '${audEsc(a.NOME_AUDITORIA).replace(/'/g,"\\'")}')">
                    <i class="fas fa-eye me-1"></i> Ver CPFs
                </button>
            </td>
        </tr>`;
    });

    html += `</tbody></table></div>`;
    wrap.innerHTML = html;
}

// ---- Abrir modal de detalhes -----------------------------------------
async function audAbrirDetalhes(id, nome) {
    audAuditoriaAtual = id;
    document.getElementById('audModalTitulo').innerHTML =
        `<i class="fas fa-shield-alt me-2"></i>Auditoria #${id} — ${audEsc(nome)}`;
    document.getElementById('audDetalhesTabela').innerHTML =
        '<div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>';
    document.getElementById('audDetalhesFiltro').value = '';
    document.getElementById('audDetalhesStatus').value = '';
    audModalDetalhesObj.show();

    const r = await audReq('listar_cpfs_auditoria', { id_auditoria: id });
    if (!r.success) {
        document.getElementById('audDetalhesTabela').innerHTML =
            `<div class="alert alert-danger m-3">${r.msg}</div>`;
        return;
    }
    audDetalhesCpfs = r.cpfs || [];
    audFiltrarDetalhes();
}

function audFiltrarDetalhes() {
    const termo   = (document.getElementById('audDetalhesFiltro').value || '').toLowerCase();
    const status  = (document.getElementById('audDetalhesStatus').value || '').toLowerCase();
    const filtrados = audDetalhesCpfs.filter(c => {
        const matchTxt = !termo  || c.NOME?.toLowerCase().includes(termo) || c.CPF_FORMATADO?.includes(termo);
        const matchSts = !status || (c.STATUS_V8 || '').toLowerCase().includes(status);
        return matchTxt && matchSts;
    });
    document.getElementById('audDetalhesContador').textContent = `${filtrados.length} registro(s)`;
    audRenderizarDetalhes(filtrados);
}

function audRenderizarDetalhes(lista) {
    if (!lista.length) {
        document.getElementById('audDetalhesTabela').innerHTML =
            '<div class="text-center py-4 text-muted">Nenhum registro encontrado.</div>';
        return;
    }

    let html = `<table class="table table-hover table-sm align-middle mb-0" style="font-size:12px;">
        <thead class="table-dark">
            <tr>
                <th>CPF</th><th>Nome</th><th class="text-center">Status</th>
                <th class="text-end">Margem</th><th class="text-end">Simulação</th>
                <th class="text-center">Data Sim.</th><th>Observação</th>
            </tr>
        </thead><tbody>`;

    lista.forEach(c => {
        const statusHtml = c.STATUS_V8 === 'OK'
            ? '<span class="badge bg-success">OK</span>'
            : `<span class="badge" style="background:#6c757d; font-size:10px;">${audEsc(c.STATUS_V8||'—')}</span>`;
        html += `<tr>
            <td style="white-space:nowrap;">${c.CPF_FORMATADO}</td>
            <td>${audEsc(c.NOME||'—')}</td>
            <td class="text-center">${statusHtml}</td>
            <td class="text-end fw-bold text-success">${c.MARGEM_FMT}</td>
            <td class="text-end fw-bold text-primary">${c.LIQUIDO_FMT}</td>
            <td class="text-center" style="white-space:nowrap;">${c.DATA_SIM_DISPLAY}</td>
            <td><small class="text-muted">${audEsc(c.OBSERVACAO||'')}</small></td>
        </tr>`;
    });

    html += '</tbody></table>';
    document.getElementById('audDetalhesTabela').innerHTML = html;
}

// ---- Exportar CSV da auditoria atual ---------------------------------
function audExportarCSV() {
    if (!audAuditoriaAtual) return;
    const fd = new FormData();
    fd.append('acao', 'exportar_csv');
    fd.append('id_auditoria', audAuditoriaAtual);
    fetch('ajax_api_v8_auditoria.php', { method: 'POST', body: fd })
        .then(r => r.blob())
        .then(blob => {
            const url = URL.createObjectURL(blob);
            const a   = document.createElement('a');
            a.href    = url;
            a.download = `auditoria_${audAuditoriaAtual}_${new Date().toISOString().slice(0,10)}.csv`;
            a.click(); URL.revokeObjectURL(url);
        });
}

function audEsc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
