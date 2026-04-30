<?php
/**
 * Componente reutilizável: Modal Incluir em Campanha
 *
 * Como usar:
 *   include $_SERVER['DOCUMENT_ROOT'] . '/includes/componente_incluir_campanha.php';
 *
 * No JS chame:
 *   sistemaIncluirCampanha(cpfs)           → array de CPFs específicos
 *   sistemaIncluirCampanha([])             → inclui os CPFs do array vazio (0 = página atual)
 *
 * Depende de: Bootstrap 5, FontAwesome, crmToast (footer.php)
 */

// Busca campanhas com hierarquia
if (!isset($pdo)) include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($_SESSION['usuario_cpf'])) $_SESSION['usuario_cpf'] = '';

$_sic_grupo    = strtoupper($_SESSION['usuario_grupo'] ?? '');
$_sic_master   = in_array($_sic_grupo, ['MASTER','ADMIN','ADMINISTRADOR']);
$_sic_id_emp   = null;
if (!$_sic_master) {
    $_se = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $_se->execute([$_SESSION['usuario_cpf']]);
    $_sic_id_emp = $_se->fetchColumn() ?: null;
}
$_sic_sql = "SELECT c.ID, c.NOME_CAMPANHA, c.STATUS,
    COALESCE(e.NOME_CADASTRO,'') AS NOME_EMPRESA
    FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
    LEFT JOIN CLIENTE_EMPRESAS e ON e.CNPJ COLLATE utf8mb4_unicode_ci = c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci
    WHERE 1=1";
$_sic_params = [];
if (!$_sic_master && $_sic_id_emp) {
    $_sic_sql .= " AND (c.id_empresa = ? OR c.id_empresa IS NULL)";
    $_sic_params[] = $_sic_id_emp;
}
$_sic_sql .= " ORDER BY c.STATUS DESC, c.NOME_CAMPANHA ASC";
$_sic_st = $pdo->prepare($_sic_sql);
$_sic_st->execute($_sic_params);
$_sic_campanhas = $_sic_st->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ===== MODAL INCLUIR EM CAMPANHA (componente do sistema) ===== -->
<div class="modal fade" id="sic-modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase">
                    <i class="fas fa-bullhorn me-2"></i> Incluir em Campanha
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-3">
                <p class="small mb-3 border-start border-warning border-3 ps-2 bg-white p-2 rounded-0" id="sic-resumo"></p>
                <input type="text" id="sic-busca" class="form-control border-dark rounded-0 mb-2"
                    placeholder="Filtrar por nome da campanha ou empresa..."
                    oninput="sicFiltrar(this.value)">
                <div id="sic-lista" style="max-height:320px; overflow-y:auto; border:1px solid #343a40; background:#fff;"></div>
                <div id="sic-selecionada" class="mt-2 d-none">
                    <span class="badge bg-success rounded-0 py-2 px-3" style="font-size:12px;">
                        <i class="fas fa-check me-1"></i> <span id="sic-selecionada-nome"></span>
                    </span>
                </div>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-secondary rounded-0" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-success fw-bold rounded-0" id="sic-btn-ok" disabled onclick="sicConfirmar()">
                    <i class="fas fa-plus me-1"></i> Incluir na Campanha Selecionada
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.sic-item { display:flex; align-items:center; justify-content:space-between; padding:9px 14px; border-bottom:1px solid #e9ecef; cursor:pointer; border-left:4px solid transparent; transition:.1s; }
.sic-item:hover { background:#f0f4ff; }
.sic-item.ativo { background:#e8f4ff; border-left-color:#0d6efd; }
</style>

<script>
(function() {
    const _sicCamps = <?= json_encode($_sic_campanhas) ?>;
    let _sicIdSel   = null;
    let _sicCpfs    = [];   // array de CPFs passado pelo chamador

    // Renderiza lista
    function sicRender(camps) {
        const lista = document.getElementById('sic-lista');
        if (!camps.length) { lista.innerHTML = '<div class="text-muted text-center py-3 small">Nenhuma campanha encontrada.</div>'; return; }
        lista.innerHTML = camps.map((c, i) => {
            const badge = c.STATUS === 'ATIVO'
                ? '<span class="badge bg-success" style="font-size:10px;">ATIVO</span>'
                : `<span class="badge bg-secondary" style="font-size:10px;">${c.STATUS}</span>`;
            const emp = c.NOME_EMPRESA ? `<small class="text-muted ms-2"><i class="fas fa-building me-1"></i>${c.NOME_EMPRESA}</small>` : '';
            return `<div class="sic-item" data-idx="${i}" onclick="sicSelecionar(${i})">
                <span><strong>${c.NOME_CAMPANHA}</strong>${emp}</span>
                <span>${badge}</span>
            </div>`;
        }).join('');
    }

    // Filtro de busca
    window.sicFiltrar = function(q) {
        const t = q.toLowerCase().trim();
        document.querySelectorAll('#sic-lista .sic-item').forEach(el => {
            const idx = parseInt(el.dataset.idx);
            const c   = _sicCamps[idx];
            if (!c) return;
            const txt = (c.NOME_CAMPANHA + ' ' + c.NOME_EMPRESA).toLowerCase();
            el.style.display = (!t || txt.includes(t)) ? 'flex' : 'none';
        });
    };

    // Seleção de campanha
    window.sicSelecionar = function(idx) {
        const c = _sicCamps[idx];
        if (!c) return;
        _sicIdSel = c.ID;
        document.querySelectorAll('#sic-lista .sic-item').forEach(el => el.classList.remove('ativo'));
        document.querySelector(`#sic-lista [data-idx="${idx}"]`)?.classList.add('ativo');
        document.getElementById('sic-selecionada-nome').textContent = c.NOME_CAMPANHA + (c.NOME_EMPRESA ? ' — ' + c.NOME_EMPRESA : '');
        document.getElementById('sic-selecionada').classList.remove('d-none');
        document.getElementById('sic-btn-ok').disabled = false;
    };

    // Abre modal — cpfs: array de CPFs, resumoTxt: texto do resumo
    window.sistemaIncluirCampanha = function(cpfs, resumoTxt) {
        _sicCpfs    = cpfs || [];
        _sicIdSel   = null;
        document.getElementById('sic-selecionada').classList.add('d-none');
        document.getElementById('sic-btn-ok').disabled = true;
        document.getElementById('sic-busca').value = '';
        document.getElementById('sic-resumo').innerHTML = resumoTxt || `<i class="fas fa-info-circle text-warning me-1"></i>${_sicCpfs.length > 0 ? '<strong>' + _sicCpfs.length + '</strong> cliente(s) selecionado(s) serão incluídos.' : 'Todos os clientes visíveis serão incluídos.'}`;
        sicRender(_sicCamps);
        new bootstrap.Modal(document.getElementById('sic-modal')).show();
    };

    // Confirmar inclusão
    window.sicConfirmar = async function() {
        if (!_sicIdSel) return;
        const fd = new FormData();
        fd.append('acao', 'incluir_em_campanha');
        fd.append('id_campanha_dest', _sicIdSel);
        _sicCpfs.forEach(cpf => fd.append('cpfs[]', cpf));
        const res = await fetch('/modulos/campanhas/relatorio_ajax.php', {method:'POST', body:fd}).then(r=>r.json());
        bootstrap.Modal.getInstance(document.getElementById('sic-modal'))?.hide();
        if (typeof crmToast === 'function')
            crmToast(res.success ? (res.msg || 'Incluídos com sucesso!') : (res.msg || 'Erro.'), res.success ? 'success' : 'error');
        else alert(res.success ? (res.msg || 'Incluídos com sucesso!') : (res.msg || 'Erro.'));
    };
})();
</script>
