<?php require_once '../../../includes/header.php'; ?>

<style>
  :root { --primary-color: #6f42c1; }
  body { background-color: #f4f6f9; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .table td { vertical-align: middle; font-size: 0.85rem; }
  
  .accordion-button:not(.collapsed) { background-color: #e2d9f3; color: var(--primary-color); font-weight: bold; }
  .accordion-button { font-weight: bold; color: #495057; }
  .accordion-item { border: 1px solid #dee2e6; margin-bottom: 10px; border-radius: 8px !important; overflow: hidden; }
  
  .val-comissao { font-weight: bold; color: #198754; }
  .checkbox-lg { transform: scale(1.3); cursor: pointer; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-secondary fw-bold m-0"><i class="fas fa-hand-holding-usd me-2" style="color: var(--primary-color);"></i>Baixa de Comissões</h3>
    </div>

    <div class="card-custom p-4 mb-4 bg-light border-dark">
        <h6 class="fw-bold mb-3"><i class="fas fa-filter me-2"></i>Filtros de Pesquisa (Data do Pagamento do Pedido)</h6>
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><label class="small fw-bold">Data Início:</label><input type="date" id="fDataIni" class="form-control border-dark"></div>
            <div class="col-md-3"><label class="small fw-bold">Data Fim:</label><input type="date" id="fDataFim" class="form-control border-dark"></div>
            <div class="col-md-4">
                <label class="small fw-bold">Vendedor / Afiliado:</label>
                <div class="position-relative">
                    <input type="hidden" id="fVend" value="">
                    <input type="text" id="fVendBusca" class="form-control border-dark fw-bold" placeholder="Buscar por qualquer parte do nome..." autocomplete="off" onkeyup="buscarVendedorFiltro(this.value)" oninput="if(!this.value){document.getElementById('fVend').value='';document.getElementById('fVendNome').innerText='Todos';}">
                    <ul id="listaVendFiltro" class="list-unstyled bg-white position-absolute w-100 border border-dark rounded-bottom shadow-lg" style="display:none; z-index:9999; max-height:200px; overflow-y:auto; margin:0; padding:0;"></ul>
                </div>
                <small class="text-muted">Filtrado: <span id="fVendNome" class="fw-bold text-primary">Todos</span></small>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100 fw-bold border-dark shadow-sm" onclick="carregarTodasEtapas()"><i class="fas fa-sync-alt me-1"></i> Atualizar</button></div>
        </div>
    </div>

    <div class="accordion" id="accComissoes">
        
        <div class="accordion-item shadow-sm border-dark">
            <h2 class="accordion-header" id="head1">
                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#col1"><i class="fas fa-exclamation-circle text-danger me-2"></i> ETAPA 1: SEM CONFERÊNCIA <span class="badge bg-danger ms-2" id="bdgSemConf">0</span></button>
            </h2>
            <div id="col1" class="accordion-collapse collapse show" data-bs-parent="#accComissoes">
                <div class="accordion-body bg-white p-3">
                    <div class="table-responsive border border-dark rounded" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-dark text-white border-dark sticky-top">
                                <tr>
                                    <th class="text-center" style="width: 50px;"><input type="checkbox" class="checkbox-lg" id="chkAll1" onclick="toggleAll('chkSemConf', this.checked)"></th>
                                    <th>Data Pgto. Pedido</th><th>Pedido</th><th>Vendedor</th><th>Produto</th><th>Valor Venda</th><th>Comissão (R$)</th>
                                </tr>
                            </thead>
                            <tbody id="tbSemConf"></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3 border-top border-dark pt-3">
                        <button class="btn btn-warning fw-bold border-dark shadow-sm" onclick="avancarEtapa('chkSemConf', 'CONFERIDO')"><i class="fas fa-check-double me-1"></i> Marcar Selecionados como CONFERIDO</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item shadow-sm border-dark">
            <h2 class="accordion-header" id="head2">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#col2"><i class="fas fa-check-double text-warning me-2"></i> ETAPA 2: CONFERIDO (Aguardando Pagamento) <span class="badge bg-warning text-dark ms-2" id="bdgConf">0</span></button>
            </h2>
            <div id="col2" class="accordion-collapse collapse" data-bs-parent="#accComissoes">
                <div class="accordion-body bg-white p-3">
                    <div class="table-responsive border border-dark rounded" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-dark text-white border-dark sticky-top">
                                <tr>
                                    <th class="text-center" style="width: 50px;"><input type="checkbox" class="checkbox-lg" id="chkAll2" onclick="toggleAll('chkConf', this.checked)"></th>
                                    <th>Data Pgto. Pedido</th><th>Pedido</th><th>Vendedor</th><th>Data Conferência</th><th>Comissão (R$)</th>
                                </tr>
                            </thead>
                            <tbody id="tbConf"></tbody>
                        </table>
                    </div>
                    <div class="text-end mt-3 border-top border-dark pt-3">
                        <button class="btn btn-danger fw-bold border-dark shadow-sm me-2" onclick="avancarEtapa('chkConf', 'ESTORNADO')"><i class="fas fa-ban me-1"></i> Estornar/Cancelar Selecionados</button>
                        <button class="btn btn-success fw-bold border-dark shadow-sm" onclick="avancarEtapa('chkConf', 'PAGO')"><i class="fas fa-money-bill-wave me-1"></i> Efetuar PAGAMENTO dos Selecionados</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="accordion-item shadow-sm border-dark">
            <h2 class="accordion-header" id="head3">
                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#col3"><i class="fas fa-money-bill-wave text-success me-2"></i> ETAPA 3: COMISSÕES PAGAS E RELATÓRIOS <span class="badge bg-success ms-2" id="bdgPago">0</span></button>
            </h2>
            <div id="col3" class="accordion-collapse collapse" data-bs-parent="#accComissoes">
                <div class="accordion-body bg-white p-3">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted small fw-bold"><i class="fas fa-info-circle"></i> Comissões já liquidadas não possuem ações, apenas visualização e exportação.</span>
                        <button class="btn btn-dark btn-sm fw-bold border-dark shadow-sm" onclick="window.print()"><i class="fas fa-file-pdf me-1"></i> Exportar Relatório (PDF)</button>
                    </div>
                    <div class="table-responsive border border-dark rounded" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-dark text-white border-dark sticky-top">
                                <tr><th>Data Pgto. Pedido</th><th>Pedido</th><th>Vendedor</th><th>Data Conferência</th><th>Data Pagamento</th><th>Comissão Paga (R$)</th></tr>
                            </thead>
                            <tbody id="tbPago"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", () => {
      const data = new Date();
      document.getElementById('fDataIni').value = new Date(data.getFullYear(), data.getMonth(), 1).toISOString().split('T')[0];
      document.getElementById('fDataFim').value = new Date(data.getFullYear(), data.getMonth() + 1, 0).toISOString().split('T')[0];
      carregarTodasEtapas();
      // Fecha dropdown ao clicar fora
      document.addEventListener('click', e => { if(!e.target.closest('#fVendBusca') && !e.target.closest('#listaVendFiltro')) document.getElementById('listaVendFiltro').style.display = 'none'; });
  });

  async function callApi(acao, dados = {}) {
      try { const fd = new FormData(); fd.append('acao', acao); for (const k in dados) fd.append(k, dados[k]);
          const res = await fetch('comissao.ajax.php', { method: 'POST', body: fd }); return await res.json();
      } catch(e) { return { success: false, msg: "Erro de conexão." }; }
  }

  let timerVendFiltro;
  async function buscarVendedorFiltro(termo) {
      clearTimeout(timerVendFiltro);
      const ul = document.getElementById('listaVendFiltro');
      if (!termo || termo.length < 1) { ul.style.display = 'none'; return; }
      timerVendFiltro = setTimeout(async () => {
          const r = await callApi('buscar_vendedores_filtro', { termo: termo });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = `<li style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #eee; background:#f8f9fa;" onclick="selecionarVendedorFiltro('', 'Todos')"><em>-- Todos os Vendedores --</em></li>`;
              r.data.forEach(v => {
                  ul.innerHTML += `<li style="padding:8px 12px; cursor:pointer; border-bottom:1px solid #eee;" onmouseover="this.style.background='#f4511e'; this.style.color='white'" onmouseout="this.style.background=''; this.style.color=''" onclick="selecionarVendedorFiltro(${v.ID}, '${v.NOME.replace(/'/g, "\\'")}')">${v.NOME}</li>`;
              });
              ul.style.display = 'block';
          } else {
              ul.innerHTML = `<li style="padding:8px 12px; color:#999;">Nenhum vendedor encontrado.</li>`;
              ul.style.display = 'block';
          }
      }, 300);
  }

  function selecionarVendedorFiltro(id, nome) {
      document.getElementById('fVend').value = id;
      document.getElementById('fVendBusca').value = id ? nome : '';
      document.getElementById('fVendNome').innerText = id ? nome : 'Todos';
      document.getElementById('listaVendFiltro').style.display = 'none';
      carregarTodasEtapas();
  }

  function carregarTodasEtapas() {
      carregarTabela('SEM CONFERENCIA', 'tbSemConf', 'bdgSemConf', 'chkSemConf');
      carregarTabela('CONFERIDO', 'tbConf', 'bdgConf', 'chkConf');
      carregarTabela('PAGO', 'tbPago', 'bdgPago', null);
  }

  async function carregarTabela(status, idTabela, idBadge, classChk) {
      const tb = document.getElementById(idTabela);
      tb.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-primary"><i class="fas fa-spinner fa-spin"></i> Lendo comissões...</td></tr>`;
      
      const filtros = { 
          status: status, 
          data_inicio: document.getElementById('fDataIni').value, 
          data_fim: document.getElementById('fDataFim').value, 
          vendedor_id: document.getElementById('fVend').value 
      };
      
      const r = await callApi('listar_comissoes', filtros);
      
      if(r.success) {
          document.getElementById(idBadge).innerText = r.data.length;
          tb.innerHTML = '';
          if(r.data.length === 0) { tb.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted fw-bold">Nenhum registro encontrado neste período.</td></tr>`; return; }
          
          r.data.forEach(x => {
              let chk = classChk ? `<td class="text-center"><input type="checkbox" class="checkbox-lg ${classChk}" value="${x.ID}"></td>` : '';
              let vVenda = parseFloat(x.VALOR_BASE_VENDA).toFixed(2).replace('.', ',');
              let vCom = parseFloat(x.VALOR_COMISSAO).toFixed(2).replace('.', ',');
              
              if(status === 'SEM CONFERENCIA') {
                  tb.innerHTML += `<tr class="border-bottom border-dark">${chk}<td>${x.DATA_BASE_BR}</td><td><b>#${x.PEDIDO_CODIGO}</b> <br><small class="text-muted">${x.CLIENTE_NOME}</small></td><td class="fw-bold text-dark">${x.VENDEDOR_NOME}</td><td>${x.PRODUTO_NOME}</td><td>R$ ${vVenda}</td><td class="val-comissao">R$ ${vCom}</td></tr>`;
              } else if(status === 'CONFERIDO') {
                  tb.innerHTML += `<tr class="border-bottom border-dark">${chk}<td>${x.DATA_BASE_BR}</td><td><b>#${x.PEDIDO_CODIGO}</b></td><td class="fw-bold text-dark">${x.VENDEDOR_NOME}</td><td>${x.DATA_CONF_BR}</td><td class="val-comissao">R$ ${vCom}</td></tr>`;
              } else if(status === 'PAGO') {
                  tb.innerHTML += `<tr class="border-bottom border-dark"><td>${x.DATA_BASE_BR}</td><td><b>#${x.PEDIDO_CODIGO}</b></td><td class="fw-bold text-dark">${x.VENDEDOR_NOME}</td><td>${x.DATA_CONF_BR}</td><td class="fw-bold text-success">${x.DATA_PAG_BR}</td><td class="val-comissao">R$ ${vCom}</td></tr>`;
              }
          });
      }
  }

  function toggleAll(className, isChecked) {
      document.querySelectorAll(`.${className}`).forEach(cb => cb.checked = isChecked);
  }

  async function avancarEtapa(className, novaEtapa) {
      const checkboxes = document.querySelectorAll(`.${className}:checked`);
      if(checkboxes.length === 0) { crmToast("Selecione pelo menos uma comissão na caixinha!", "info", 5000); return; }
      
      let msg = novaEtapa === 'ESTORNADO' ? "ATENÇÃO: Deseja ESTORNAR as comissões selecionadas?" : `Mover ${checkboxes.length} comissões para o status ${novaEtapa}?`;
      if(!confirm(msg)) return;
      
      let ids = []; checkboxes.forEach(cb => ids.push(cb.value));
      
      const r = await callApi('avancar_etapa', { nova_etapa: novaEtapa, ids: JSON.stringify(ids) });
      if(r.success) {
          if(document.getElementById('chkAll1')) document.getElementById('chkAll1').checked = false;
          if(document.getElementById('chkAll2')) document.getElementById('chkAll2').checked = false;
          carregarTodasEtapas();
      } else { crmToast(r.msg, r.success === false ? "error" : "info"); }
  }
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>