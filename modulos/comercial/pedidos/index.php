<?php require_once '../../../includes/header.php'; ?>

<style>
  :root { --primary-color: #f4511e; }
  body { background-color: #f4f6f9; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .table td { vertical-align: middle; font-size: 0.85rem; }
  
  .bg-Aguardando { background-color: #fff3cd; color: #664d03; }
  .bg-Pago { background-color: #d1e7dd; color: #0f5132; }
  .bg-Cancelado { background-color: #f8d7da; color: #842029; }
  .bg-Solicitado { background-color: #cff4fc; color: #055160; }
  .status-badge { font-size: 0.75rem; padding: 4px 8px; border-radius: 12px; font-weight: bold; text-transform: uppercase; border: 1px solid rgba(0,0,0,0.1); }

  .timeline-item { padding-left: 20px; border-left: 2px solid #ddd; margin-bottom: 15px; position: relative; }
  .timeline-item::before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #6c757d; }
  .timeline-date { font-size: 0.75rem; color: #999; font-weight: bold; }

  /* Lista de Autocomplete para Clientes e Vendedores */
  .lista-suspensa { display: none; max-height: 180px; overflow-y: auto; z-index: 1050; position: absolute; width: 100%; border: 1px solid #343a40; }
  .lista-suspensa li { cursor: pointer; padding: 8px 12px; border-bottom: 1px solid #ddd; }
  .lista-suspensa li:hover { background-color: #f4511e; color: white; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-secondary fw-bold m-0"><i class="fas fa-shopping-cart me-2"></i>Controle de Pedidos e Renovações</h3>
    </div>

    <div class="card-custom p-4">
       <div class="row g-2 align-items-end bg-light p-3 rounded border border-dark mb-4">
          <div class="col-md-4">
             <label class="small fw-bold">Buscar Cliente/Produto:</label>
             <input type="text" id="search" class="form-control border-dark" placeholder="Filtrar..." onkeyup="filter()">
          </div>
          <div class="col-md-3">
             <label class="small fw-bold">Filtro de Status:</label>
             <select id="fStatus" class="form-select border-dark" onchange="filter()">
                <option value="">Todos</option>
                <option value="Solicitado">Solicitado</option>
                <option value="Aguardando Pagamento">Aguardando Pagamento</option>
                <option value="Pago">Pago</option>
                <option value="Cancelado">Cancelado</option>
             </select>
          </div>
          <div class="col-md-5 text-end">
             <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="openNew()"><i class="fas fa-plus me-1"></i> Novo Pedido</button>
          </div>
       </div>

       <div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 400px;">
          <table class="table table-hover mb-0 align-middle text-center">
             <thead class="table-dark text-white border-dark">
                <tr>
                   <th class="text-start">Código</th>
                   <th class="text-start">Cliente</th>
                   <th class="text-start">Produto & Plano</th>
                   <th>Total (R$)</th>
                   <th>Renovação</th>
                   <th>Criado</th>
                   <th>Pago</th>
                   <th>Cancelado</th>
                   <th>Status</th>
                   <th style="width: 110px;">Ações</th>
                </tr>
             </thead>
             <tbody id="tbody"><tr><td colspan="10" class="text-center py-4 text-primary fw-bold"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr></tbody>
          </table>
       </div>
    </div>
</div>

<div class="modal fade" id="mNew"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-plus-square me-2"></i>Novo Pedido</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fNew">
      <div class="row mb-3 position-relative">
         <div class="col-md-8">
            <label class="fw-bold small text-primary"><i class="fas fa-search"></i> Buscar Cliente (Nome ou CPF):</label>
            <input type="text" id="nCli" class="form-control border-dark fw-bold text-uppercase" placeholder="Digite para buscar..." autocomplete="off" onkeyup="buscarClienteAJAX(this.value, 'n')" required>
            <ul id="listaClientes_n" class="list-unstyled bg-white lista-suspensa shadow-lg rounded-bottom"></ul>
         </div>
         <div class="col-md-4">
            <label class="fw-bold small">Telefone:</label>
            <input type="text" id="nTel" class="form-control border-dark">
         </div>
      </div>
      
      <div class="row mb-3 position-relative bg-white p-2 border border-dark rounded shadow-sm">
         <div class="col-md-4">
            <label class="fw-bold small text-primary"><i class="fas fa-box"></i> Produto Base:</label>
            <select id="nProd" class="form-select border-dark fw-bold prod-lista" onchange="buscarVariacoesAjax(this.value, 'n')" required><option value="">Carregando produtos...</option></select>
         </div>
         <div class="col-md-4">
            <label class="fw-bold small text-info"><i class="fas fa-tags"></i> Plano / Variação:</label>
            <select id="nVariacao" class="form-select border-info fw-bold" onchange="setarValorUnitario('n')" required>
                <option value="">Escolha o Produto antes...</option>
            </select>
         </div>
         <div class="col-md-4 position-relative">
            <label class="fw-bold small text-primary"><i class="fas fa-user-tie"></i> Vendedor:</label>
            <input type="hidden" id="nVendId">
            <input type="text" id="nVend" class="form-control border-dark fw-bold text-uppercase" placeholder="Buscar Vendedor..." autocomplete="off" onkeyup="buscarVendedorAJAX(this.value, 'n')">
            <ul id="listaVend_n" class="list-unstyled bg-white lista-suspensa shadow-lg rounded-bottom"></ul>
         </div>
      </div>

      <div class="row mb-3">
         <div class="col-md-6"><label class="fw-bold small">Valor Unitário (R$):</label><input type="number" step="0.01" id="nUnit" class="form-control border-dark calc-new" required value="0"></div>
         <div class="col-md-6"><label class="fw-bold small">Quantidade:</label><input type="number" id="nQtd" class="form-control border-dark calc-new" required value="1"></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-success">Acréscimo (R$):</label><input type="number" step="0.01" id="nAcres" class="form-control border-dark calc-new" value="0"></div>
         <div class="col-md-4"><label class="fw-bold small text-info">Variação (R$):</label><input type="number" step="0.01" id="nVar" class="form-control border-dark calc-new" value="0"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Desconto (R$):</label><input type="number" step="0.01" id="nDesc" class="form-control border-dark calc-new" value="0"></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-secondary">Cupom Nome:</label><input type="text" id="nCupomNome" class="form-control border-dark text-uppercase"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Valor Cupom (R$):</label><input type="number" step="0.01" id="nCupomVal" class="form-control border-dark calc-new" value="0"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Fidelidade (R$):</label><input type="number" step="0.01" id="nFidel" class="form-control border-dark calc-new" value="0"></div>
      </div>
      <div class="row mb-4 align-items-end p-3 bg-white border border-dark rounded shadow-sm">
         <div class="col-md-4"><label class="fw-bold small text-warning"><i class="fas fa-percent"></i> IVA (%):</label><input type="number" step="0.01" id="nIva" class="form-control border-dark calc-new" value="0"></div>
         <div class="col-md-8"><label class="fw-bold text-dark h6 mb-1">TOTAL (R$):</label><input type="text" id="nTotal" class="form-control form-control-lg border-dark bg-dark text-white fw-bold" readonly value="0.00"></div>
      </div>
      <div class="mb-4">
          <label class="fw-bold small">Observações:</label>
          <textarea id="nObs" class="form-control border-dark" rows="2" placeholder="Insira detalhes..."></textarea>
      </div>

      <div class="mb-3 form-check form-switch p-3 border border-success rounded bg-white shadow-sm d-flex align-items-center">
          <input class="form-check-input ms-0 me-3 mt-0 border-dark" type="checkbox" id="nGerarEntrega" checked style="width: 40px; height: 20px; cursor: pointer;">
          <label class="form-check-label fw-bold text-success m-0" for="nGerarEntrega" style="cursor: pointer;"><i class="fas fa-truck-fast me-1"></i> Enviar automaticamente para a Logística (Gerar Entrega)</label>
      </div>

      <div class="text-end border-top border-dark pt-3">
        <button type="button" class="btn btn-outline-dark fw-bold me-2" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary fw-bold border-dark shadow-sm">Gerar Pedido</button>
      </div>
   </form>
</div></div></div></div>

<div class="modal fade" id="mViewPedido"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit me-2"></i>Detalhes do Pedido</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fViewPedido">
      <input type="hidden" id="vpPedId">
      
      <div class="row mb-4 p-3 border border-secondary rounded bg-white shadow-sm">
          <h6 class="fw-bold text-secondary text-uppercase mb-3"><i class="fas fa-calendar-day me-2"></i>Evolução de Datas do Pedido</h6>
          <div class="col-md-4">
              <label class="fw-bold small text-muted">Criado em (Pendente):</label>
              <input type="date" id="vpDataPedido" class="form-control border-dark">
          </div>
          <div class="col-md-4">
              <label class="fw-bold small text-success">Pago em:</label>
              <input type="date" id="vpDataPagamento" class="form-control border-dark">
          </div>
          <div class="col-md-4">
              <label class="fw-bold small text-danger">Cancelado em:</label>
              <input type="date" id="vpDataCancelamento" class="form-control border-dark">
          </div>
      </div>

      <div class="row mb-3 position-relative">
         <div class="col-md-12">
             <label class="fw-bold small text-primary"><i class="fas fa-search"></i> Buscar Cliente (Nome ou CPF):</label>
             <input type="text" id="vpCli" class="form-control border-dark fw-bold text-uppercase" placeholder="Digite para buscar..." autocomplete="off" onkeyup="buscarClienteAJAX(this.value, 'vp')" required>
             <ul id="listaClientes_vp" class="list-unstyled bg-white lista-suspensa shadow-lg rounded-bottom"></ul>
         </div>
      </div>
      
      <div class="row mb-3 position-relative bg-white p-2 border border-dark rounded shadow-sm">
         <div class="col-md-4">
            <label class="fw-bold small text-primary"><i class="fas fa-box"></i> Produto Base:</label>
            <select id="vpProd" class="form-select border-dark fw-bold prod-lista" onchange="buscarVariacoesAjax(this.value, 'vp')" required></select>
         </div>
         <div class="col-md-4">
            <label class="fw-bold small text-info"><i class="fas fa-tags"></i> Plano / Variação:</label>
            <select id="vpVariacao" class="form-select border-info fw-bold" onchange="setarValorUnitario('vp')" required>
                <option value="">Escolha o Produto antes...</option>
            </select>
         </div>
         <div class="col-md-4 position-relative">
            <label class="fw-bold small text-primary"><i class="fas fa-user-tie"></i> Vendedor:</label>
            <input type="hidden" id="vpVendId">
            <input type="text" id="vpVend" class="form-control border-dark fw-bold text-uppercase" placeholder="Buscar Vendedor..." autocomplete="off" onkeyup="buscarVendedorAJAX(this.value, 'vp')">
            <ul id="listaVend_vp" class="list-unstyled bg-white lista-suspensa shadow-lg rounded-bottom"></ul>
         </div>
      </div>

      <div class="row mb-3">
         <div class="col-md-6"><label class="fw-bold small">Valor Unitário (R$):</label><input type="number" step="0.01" id="vpUnit" class="form-control border-dark calc-edit" required></div>
         <div class="col-md-6"><label class="fw-bold small">Quantidade:</label><input type="number" id="vpQtd" class="form-control border-dark calc-edit" required></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-success">Acréscimo (R$):</label><input type="number" step="0.01" id="vpAcres" class="form-control border-dark calc-edit"></div>
         <div class="col-md-4"><label class="fw-bold small text-info">Variação (R$):</label><input type="number" step="0.01" id="vpVar" class="form-control border-dark calc-edit"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Desconto (R$):</label><input type="number" step="0.01" id="vpDesc" class="form-control border-dark calc-edit"></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-secondary">Cupom:</label><input type="text" id="vpCupomNome" class="form-control border-dark text-uppercase"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Valor Cupom (R$):</label><input type="number" step="0.01" id="vpCupomVal" class="form-control border-dark calc-edit"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Fidelidade (R$):</label><input type="number" step="0.01" id="vpFidel" class="form-control border-dark calc-edit"></div>
      </div>
      <div class="row mb-4 align-items-end p-3 bg-white border border-dark rounded shadow-sm">
         <div class="col-md-4"><label class="fw-bold small text-warning"><i class="fas fa-percent"></i> IVA (%):</label><input type="number" step="0.01" id="vpIva" class="form-control border-dark calc-edit"></div>
         <div class="col-md-8"><label class="fw-bold text-dark h6 mb-1">TOTAL (R$):</label><input type="text" id="vpTotal" class="form-control form-control-lg border-dark bg-dark text-white fw-bold" readonly></div>
      </div>
      
      <div class="row mb-4 p-3 border border-warning rounded bg-white shadow-sm" id="boxRenovacao">
          <h6 class="fw-bold text-warning text-uppercase"><i class="fas fa-sync-alt me-2"></i>Controle de Renovação</h6>
          <div class="col-md-4">
              <label class="fw-bold small">Status da Renovação:</label>
              <select id="vpStatusRen" class="form-select border-dark fw-bold">
                  <option value="A Configurar">A Configurar</option>
                  <option value="Pendente">Pendente</option>
                  <option value="Pago">Pago</option>
                  <option value="Renovado">Renovado</option>
                  <option value="Cancelado">Cancelado</option>
              </select>
          </div>
          <div class="col-md-4"><label class="fw-bold small">Data Prevista:</label><input type="date" id="vpDataPrev" class="form-control border-dark"></div>
          <div class="col-md-4"><label class="fw-bold small text-success">Data Efetiva:</label><input type="date" id="vpDataEfetiva" class="form-control border-dark"></div>
      </div>
      
      <div class="mb-4"><label class="fw-bold small">Observações Financeiras:</label><textarea id="vpObs" class="form-control border-dark" rows="2"></textarea></div>
      <button type="submit" class="btn btn-dark w-100 fw-bold shadow-sm border-dark">Salvar Alterações</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mSt"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-flag me-2"></i>Status do Pedido</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fSt">
      <input type="hidden" id="sRow">
      <div class="mb-3"><label class="fw-bold small">Novo Status:</label>
         <select id="sVal" class="form-select border-dark fw-bold">
            <option value="Solicitado">Solicitado</option>
            <option value="Aguardando Pagamento">Aguardando Pagamento</option>
            <option value="Pago">Pago</option>
            <option value="Cancelado">Cancelado</option>
         </select>
      </div>
      <div class="mb-4"><label class="fw-bold small">Observação p/ Histórico:</label><textarea id="sObs" class="form-control border-dark" rows="3" required></textarea></div>
      <button type="submit" class="btn btn-warning w-100 fw-bold border-dark text-dark shadow-sm">Atualizar Status</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mExt"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-success text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fab fa-whatsapp me-2"></i>Contato Cliente</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fExt">
      <input type="hidden" id="extRow">
      <div class="alert alert-success border-dark py-2 small fw-bold"><i class="fas fa-info-circle"></i> O texto será salvo no Histórico do Pedido.</div>
      <div class="mb-3"><label class="small fw-bold">Mensagem Enviada:</label><textarea id="extMsg" class="form-control border-dark" rows="4" required></textarea></div>
      <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm text-dark"><i class="fab fa-whatsapp me-1"></i> Registrar Contato</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mHist"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-secondary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-history me-2"></i>Histórico</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><div id="timeline" style="max-height:400px; overflow-y:auto; padding: 10px;"></div></div></div></div></div>

<script>
  let list = [];
  let modais = {};

  document.addEventListener("DOMContentLoaded", () => {
      modais = { 
          new: new bootstrap.Modal(document.getElementById('mNew')), 
          viewPed: new bootstrap.Modal(document.getElementById('mViewPedido')), 
          st: new bootstrap.Modal(document.getElementById('mSt')),
          ext: new bootstrap.Modal(document.getElementById('mExt')),
          hist: new bootstrap.Modal(document.getElementById('mHist'))
      };
      
      document.querySelectorAll('.calc-new').forEach(el => el.addEventListener('input', () => calcTotal('n')));
      document.querySelectorAll('.calc-edit').forEach(el => el.addEventListener('input', () => calcTotal('vp')));
      
      load(); carregarListaProdutos();

      // Pré-preenchimento via URL (vindo da ficha do cliente)
      const urlP = new URLSearchParams(window.location.search);
      if (urlP.has('pre_cliente')) {
          document.getElementById('nCli').value = urlP.get('pre_cliente');
          document.getElementById('nTel').value = urlP.get('pre_tel') || '';
          modais.new.show();
      }
  });

  async function callApi(acao, dados = {}) {
      try {
          const fd = new FormData(); fd.append('acao', acao);
          for (const key in dados) { fd.append(key, dados[key]); }
          const res = await fetch('pedidos.ajax.php', { method: 'POST', body: fd });
          if (!res.ok) throw new Error("Erro HTTP");
          return await res.json();
      } catch(e) { return { success: false, msg: "Falha de conexão com backend." }; }
  }

  async function carregarListaProdutos() {
      const r = await callApi('buscar_produtos');
      if (r.success) {
          let ops = '<option value="">-- Selecione o Produto --</option>';
          r.data.forEach(p => { ops += `<option value="${p.NOME}">${p.NOME}</option>`; });
          document.querySelectorAll('.prod-lista').forEach(sel => sel.innerHTML = ops);
      }
  }

  async function buscarVariacoesAjax(produtoNome, prefix) {
      const selVar = document.getElementById(prefix + 'Variacao');
      selVar.innerHTML = '<option value="">Buscando variações...</option>';
      
      if(!produtoNome) {
          selVar.innerHTML = '<option value="">Escolha o Produto antes...</option>';
          return;
      }

      const r = await callApi('buscar_variacoes', { produto_nome: produtoNome });
      if (r.success && r.data.length > 0) {
          let ops = '<option value="" data-valor="0">-- Selecione o Plano --</option>';
          r.data.forEach(v => {
              ops += `<option value="${v.NOME_VARIACAO}" data-valor="${v.VALOR_VENDA}">${v.NOME_VARIACAO} - R$ ${v.VALOR_VENDA}</option>`;
          });
          selVar.innerHTML = ops;
      } else {
          selVar.innerHTML = '<option value="Único" data-valor="0">Sem Variação Específica</option>';
          document.getElementById(prefix + 'Unit').value = '0.00';
          calcTotal(prefix);
      }
  }

  function setarValorUnitario(prefix) {
      const sel = document.getElementById(prefix + 'Variacao');
      const opt = sel.options[sel.selectedIndex];
      if(opt) {
          const valor = opt.getAttribute('data-valor');
          document.getElementById(prefix + 'Unit').value = parseFloat(valor).toFixed(2);
          // Limpa desconto de indicação anterior ao mudar variação
          let descField = document.getElementById(prefix + 'Desc');
          if(descField) { descField.value = '0'; }
          let infoEl = document.getElementById('infoDescIndicacao_' + prefix);
          if(infoEl) infoEl.innerHTML = '';
          calcTotal(prefix);
          // Verifica desconto de indicação para nova variação
          verificarDescontoIndicacao(prefix);
      }
  }

  let delayTimer;
  async function buscarClienteAJAX(termo, prefix = 'n') {
      clearTimeout(delayTimer);
      let ul = document.getElementById('listaClientes_' + prefix);
      if (termo.length < 3) { ul.style.display = 'none'; return; }

      delayTimer = setTimeout(async () => {
          const r = await callApi('buscar_clientes', { termo: termo });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = '';
              r.data.forEach(c => {
                  let li = document.createElement('li');
                  let badgeVend = c.VENDEDOR_NOME ? ` <span class="badge bg-warning text-dark border border-dark" style="font-size:0.7rem;"><i class="fas fa-user-tie me-1"></i>${c.VENDEDOR_NOME}</span>` : '';
                  li.innerHTML = `<strong>${c.NOME}</strong>${badgeVend}<br><small class="text-muted">CPF: ${c.CPF}</small>`;
                  li.onclick = () => {
                      document.getElementById(prefix + 'Cli').value = c.NOME;
                      if(prefix === 'n') {
                          let telField = document.getElementById('nTel');
                          if(telField) telField.value = c.CELULAR || '';
                      }
                      // Auto-preenche vendedor se cliente foi indicado e campo vendedor está vazio
                      let vendField = document.getElementById(prefix + 'Vend');
                      let vendIdField = document.getElementById(prefix + 'VendId');
                      if(c.VENDEDOR_REF_ID && c.VENDEDOR_NOME && vendField && (!vendField.value || vendField.value.trim() === '')) {
                          vendField.value = c.VENDEDOR_NOME;
                          vendIdField.value = c.VENDEDOR_REF_ID;
                          // Mostra badge de indicação
                          let badgeEl = document.getElementById('badgeVendorRef_' + prefix);
                          if(!badgeEl) {
                              badgeEl = document.createElement('div');
                              badgeEl.id = 'badgeVendorRef_' + prefix;
                              vendField.parentElement.appendChild(badgeEl);
                          }
                          badgeEl.innerHTML = `<span class="badge bg-warning text-dark border border-dark mt-1"><i class="fas fa-link me-1"></i> Indicado por: ${c.VENDEDOR_NOME}</span>`;
                          // Verifica desconto de indicação se variação já estiver selecionada
                          verificarDescontoIndicacao(prefix);
                      }
                      ul.style.display = 'none';
                  };
                  ul.appendChild(li);
              });
              ul.style.display = 'block';
          } else { ul.style.display = 'none'; }
      }, 400);
  }

  async function verificarDescontoIndicacao(prefix) {
      const vendId = document.getElementById(prefix + 'VendId')?.value;
      const variacaoNome = document.getElementById(prefix + 'Variacao')?.value;
      const total = parseFloat(document.getElementById(prefix + 'Total')?.value) || 0;
      if (!vendId || !variacaoNome || !total) return;

      const r = await callApi('buscar_desconto_indicacao', { vendedor_id: vendId, variacao_nome: variacaoNome, total: total });
      if (r && r.desconto > 0) {
          let descField = document.getElementById(prefix + 'Desc');
          if (descField && (parseFloat(descField.value) || 0) === 0) {
              descField.value = r.desconto.toFixed(2);
              calcTotal(prefix);
              let infoEl = document.getElementById('infoDescIndicacao_' + prefix);
              if (!infoEl) {
                  infoEl = document.createElement('div');
                  infoEl.id = 'infoDescIndicacao_' + prefix;
                  descField.parentElement.appendChild(infoEl);
              }
              infoEl.innerHTML = `<small class="text-warning fw-bold"><i class="fas fa-tag me-1"></i> Desconto de indicação: R$ ${r.desconto.toFixed(2).replace('.', ',')}</small>`;
          }
      }
  }

  let timerVend;
  async function buscarVendedorAJAX(termo, prefix) {
      clearTimeout(timerVend); let ul = document.getElementById('listaVend_' + prefix);
      if (termo.length < 3) { ul.style.display = 'none'; return; }
      timerVend = setTimeout(async () => {
          const r = await callApi('buscar_vendedores', { termo: termo });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = '';
              r.data.forEach(v => {
                  let li = document.createElement('li');
                  li.innerHTML = `<strong>${v.NOME}</strong> <br><small>Doc: ${v.DOCUMENTO_VENDEDOR}</small>`;
                  li.onclick = () => {
                      document.getElementById(prefix + 'Vend').value = v.NOME;
                      document.getElementById(prefix + 'VendId').value = v.ID;
                      ul.style.display = 'none';
                  };
                  ul.appendChild(li);
              });
              ul.style.display = 'block';
          } else { ul.style.display = 'none'; }
      }, 400);
  }

  document.addEventListener('click', (e) => { 
      if(!e.target.closest('#nCli')) { let u1 = document.getElementById('listaClientes_n'); if(u1) u1.style.display = 'none'; }
      if(!e.target.closest('#vpCli')) { let u2 = document.getElementById('listaClientes_vp'); if(u2) u2.style.display = 'none'; }
      if(!e.target.closest('#nVend')) { let ul1 = document.getElementById('listaVend_n'); if(ul1) ul1.style.display = 'none'; }
      if(!e.target.closest('#vpVend')) { let ul2 = document.getElementById('listaVend_vp'); if(ul2) ul2.style.display = 'none'; }
  });

  function calcTotal(prefix) {
      let v = (id) => parseFloat(document.getElementById(prefix + id).value) || 0;
      let qtd = parseInt(document.getElementById(prefix + 'Qtd').value) || 1;
      let subtotal = (v('Unit') * qtd) + v('Acres') + v('Var');
      let descontos = v('Desc') + v('CupomVal') + v('Fidel');
      let base = subtotal - descontos;
      document.getElementById(prefix + 'Total').value = (base + (base * (v('Iva') / 100))).toFixed(2);
  }

  async function load() {
     document.getElementById('tbody').innerHTML = '<tr><td colspan="10" class="text-center py-4 text-primary fw-bold"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
     const r = await callApi('listar_pedidos');
     if (r && r.success) { list = r.data; filter(); } 
     else { document.getElementById('tbody').innerHTML = '<tr><td colspan="10" class="text-center text-danger py-4 fw-bold">Erro: ' + r.msg + '</td></tr>'; }
  }

  function getBadgeClass(status) {
      const s = (status||'').toLowerCase();
      if(s.includes('cancelado')) return 'bg-Cancelado';
      if(s.includes('pago')) return 'bg-Pago';
      if(s.includes('aguardando')) return 'bg-Aguardando';
      return 'bg-Solicitado';
  }

  function render(data) {
     const tb = document.getElementById('tbody'); tb.innerHTML = '';
     if(!data.length) { tb.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-4 fw-bold">Nenhum pedido encontrado.</td></tr>'; return; }
     
     data.forEach(x => {
        const stCls = getBadgeClass(x.STATUS_PEDIDO);
        let renovacaoHtml = `<span class="badge bg-secondary border border-dark">Sem Renovação</span>`;
        if(x.DATA_EFET_REN_BR) renovacaoHtml = `<span class="badge bg-success border border-dark text-white"><i class="fas fa-check-circle"></i> Renovado: ${x.DATA_EFET_REN_BR}</span>`;
        else if(x.DATA_PREV_REN_BR) renovacaoHtml = `<span class="badge bg-warning border border-dark text-dark"><i class="fas fa-clock"></i> Previsto: ${x.DATA_PREV_REN_BR}</span>`;

        let dtPedido = x.DATA_PEDIDO_BR ? `<span class="small text-muted fw-bold">${x.DATA_PEDIDO_BR}</span>` : '-';
        let dtPago = x.DATA_PAGO_BR ? `<span class="small text-success fw-bold">${x.DATA_PAGO_BR}</span>` : '-';
        let dtCanc = x.DATA_CANC_BR ? `<span class="small text-danger fw-bold">${x.DATA_CANC_BR}</span>` : '-';

        tb.innerHTML += `
        <tr class="border-bottom border-dark">
           <td class="text-start"><code class="text-dark fw-bold px-2 py-1 bg-light border border-secondary rounded">${x.CODIGO}</code></td>
           <td class="fw-bold text-primary text-start">${x.CLIENTE_NOME || 'N/A'}</td>
           <td class="text-truncate text-start fw-bold" style="max-width: 180px;">${x.PRODUTO_NOME || 'N/A'}</td>
           <td class="fw-bold text-success">R$ ${x.TOTAL ? parseFloat(x.TOTAL).toFixed(2).replace('.', ',') : '0,00'}</td>
           <td>${renovacaoHtml}</td>
           <td>${dtPedido}</td>
           <td>${dtPago}</td>
           <td>${dtCanc}</td>
           <td><span class="badge-status ${stCls}">${x.STATUS_PEDIDO || 'Solicitado'}</span></td>
           <td class="text-center">
              <div class="dropdown">
                <button class="btn btn-sm btn-dark dropdown-toggle fw-bold shadow-sm" type="button" data-bs-toggle="dropdown">Opções</button>
                <ul class="dropdown-menu shadow-lg border-dark dropdown-menu-end" style="z-index: 99999;">
                  <li><a class="dropdown-item fw-bold text-primary" href="#" onclick="viewPedido(${x.ID})"><i class="fas fa-edit me-2"></i> Editar / Detalhes</a></li>
                  <li><a class="dropdown-item fw-bold text-warning" href="#" onclick="openRenovacao(${x.ID})"><i class="fas fa-sync-alt me-2"></i> Renovar Pedido</a></li>
                  <li><a class="dropdown-item fw-bold text-info" href="#" onclick="gerarEntrega(${x.ID})"><i class="fas fa-truck me-2"></i> Gerar Ficha de Entrega</a></li>
                  <li><a class="dropdown-item fw-bold text-success" href="#" onclick="openWapi(${x.ID})"><i class="fab fa-whatsapp me-2"></i> Msg Cliente</a></li>
                  <li><a class="dropdown-item fw-bold text-warning" href="#" onclick="openSt(${x.ID}, '${x.STATUS_PEDIDO}')"><i class="fas fa-flag text-dark me-2"></i> Mudar Status</a></li>
                  <li><a class="dropdown-item fw-bold text-secondary" href="#" onclick="viewHist(${x.ID})"><i class="fas fa-history me-2"></i> Histórico</a></li>
                  <li><hr class="dropdown-divider border-dark"></li>
                  <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="deleteOrder(${x.ID})"><i class="fas fa-trash me-2"></i> Excluir Pedido</a></li>
                </ul>
              </div>
           </td>
        </tr>`;
     });
  }

  function filter() {
     const t = document.getElementById('search').value.toLowerCase(); const st = document.getElementById('fStatus').value.toLowerCase();
     render(list.filter(x => {
         const cli = (x.CLIENTE_NOME || '').toLowerCase();
         const prod = (x.PRODUTO_NOME || '').toLowerCase();
         const cod = (x.CODIGO || '').toLowerCase();
         const status = (x.STATUS_PEDIDO || '').toLowerCase();
         return (cli.includes(t) || prod.includes(t) || cod.includes(t)) && (!st || status.includes(st));
     }));
  }

  function openNew() {
      document.getElementById('fNew').reset();
      document.getElementById('nVendId').value = '';
      let badge = document.getElementById('badgeVendorRef_n'); if(badge) badge.innerHTML = '';
      let infoDesc = document.getElementById('infoDescIndicacao_n'); if(infoDesc) infoDesc.innerHTML = '';
      modais.new.show();
  }

  document.getElementById('fNew').addEventListener('submit', async e => {
     e.preventDefault();
     const f = { 
         cliente: document.getElementById('nCli').value, 
         telefone: document.getElementById('nTel').value, 
         produto_base: document.getElementById('nProd').value, 
         produto_variacao: document.getElementById('nVariacao').value, 
         vendedor_id: document.getElementById('nVendId').value, 
         unitario: document.getElementById('nUnit').value, 
         qtd: document.getElementById('nQtd').value, 
         acrescimo: document.getElementById('nAcres').value, 
         variacao: document.getElementById('nVar').value, 
         desconto: document.getElementById('nDesc').value, 
         cupom_nome: document.getElementById('nCupomNome').value, 
         cupom_val: document.getElementById('nCupomVal').value, 
         fidelidade: document.getElementById('nFidel').value, 
         iva: document.getElementById('nIva').value, 
         total: document.getElementById('nTotal').value, 
         obs: document.getElementById('nObs').value,
         gerar_entrega: document.getElementById('nGerarEntrega').checked ? '1' : '0'
     };
     const r = await callApi('salvar_pedido', f);
     if(r.success){ modais.new.hide(); load(); alert(r.msg); } else alert(r.msg);
  });

  async function viewPedido(id) {
      const r = await callApi('get_pedido', {id: id});
      if(r.success) {
          const d = r.data;
          document.getElementById('vpPedId').value = d.ID;
          
          document.getElementById('vpDataPedido').value = d.DATA_PEDIDO ? d.DATA_PEDIDO.split(' ')[0] : '';
          document.getElementById('vpDataPagamento').value = d.DATA_PAGAMENTO ? d.DATA_PAGAMENTO.split(' ')[0] : '';
          document.getElementById('vpDataCancelamento').value = d.DATA_CANCELAMENTO ? d.DATA_CANCELAMENTO.split(' ')[0] : '';

          document.getElementById('vpCli').value = d.CLIENTE_NOME || ''; 
          document.getElementById('vpVend').value = d.VENDEDOR_NOME || ''; document.getElementById('vpVendId').value = d.VENDEDOR_ID || '';
          
          let baseName = d.PRODUTO_NOME || '';
          let varName = '';
          const selProd = document.getElementById('vpProd');
          
          for (let opt of selProd.options) {
              if (opt.value && baseName.startsWith(opt.value)) {
                  baseName = opt.value;
                  if (d.PRODUTO_NOME.length > baseName.length) {
                      varName = d.PRODUTO_NOME.replace(baseName + ' - ', '');
                  }
                  break;
              }
          }
          
          selProd.value = baseName;
          await buscarVariacoesAjax(baseName, 'vp');
          if(varName) { document.getElementById('vpVariacao').value = varName; }
          
          document.getElementById('vpUnit').value = d.VALOR_UNITARIO || '0.00'; document.getElementById('vpQtd').value = d.QUANTIDADE || '1';
          document.getElementById('vpAcres').value = d.ACRESCIMO || '0.00'; document.getElementById('vpVar').value = d.VARIACAO || '0.00';
          document.getElementById('vpDesc').value = d.DESCONTO || '0.00'; document.getElementById('vpCupomNome').value = d.CUPOM || '';
          document.getElementById('vpCupomVal').value = d.VALOR_CUPOM || '0.00'; document.getElementById('vpFidel').value = d.FIDELIDADE || '0.00';
          document.getElementById('vpIva').value = d.IVA || '0.00'; document.getElementById('vpTotal').value = d.TOTAL || d.VALOR || '0.00';
          
          document.getElementById('vpStatusRen').value = d.STATUS_RENOVACAO || 'A Configurar';
          document.getElementById('vpDataPrev').value = d.DATA_PREVISTA_RENOVACAO || ''; 
          document.getElementById('vpDataEfetiva').value = d.DATA_EFETIVA_RENOVACAO ? d.DATA_EFETIVA_RENOVACAO.split(' ')[0] : '';
          
          document.getElementById('vpObs').value = d.OBSERVACAO || '';
          modais.viewPed.show();
      } else { alert(r.msg); }
  }

  async function openRenovacao(id) {
      await viewPedido(id);
      setTimeout(() => {
          document.getElementById('boxRenovacao').scrollIntoView({ behavior: 'smooth' });
          document.getElementById('vpStatusRen').focus();
      }, 500);
  }
  
  document.getElementById('fViewPedido').addEventListener('submit', async e => {
      e.preventDefault();
      const f = { 
          id: document.getElementById('vpPedId').value, 
          data_pedido: document.getElementById('vpDataPedido').value,
          data_pagamento: document.getElementById('vpDataPagamento').value,
          data_cancelamento: document.getElementById('vpDataCancelamento').value,
          cliente: document.getElementById('vpCli').value, 
          
          produto_base: document.getElementById('vpProd').value, 
          produto_variacao: document.getElementById('vpVariacao').value,
          
          vendedor_id: document.getElementById('vpVendId').value, 
          unitario: document.getElementById('vpUnit').value, qtd: document.getElementById('vpQtd').value, acrescimo: document.getElementById('vpAcres').value, 
          variacao: document.getElementById('vpVar').value, desconto: document.getElementById('vpDesc').value, cupom_nome: document.getElementById('vpCupomNome').value, 
          cupom_val: document.getElementById('vpCupomVal').value, fidelidade: document.getElementById('vpFidel').value, iva: document.getElementById('vpIva').value, 
          total: document.getElementById('vpTotal').value, status_renovacao: document.getElementById('vpStatusRen').value, data_prevista: document.getElementById('vpDataPrev').value, 
          data_efetiva: document.getElementById('vpDataEfetiva').value, obs: document.getElementById('vpObs').value 
      };
      const r = await callApi('editar_pedido_completo', f);
      if(r.success) { modais.viewPed.hide(); load(); alert(r.msg); } else alert(r.msg);
  });

  function openSt(id, st) { document.getElementById('sRow').value=id; document.getElementById('sVal').value=st; document.getElementById('sObs').value=''; modais.st.show(); }
  document.getElementById('fSt').addEventListener('submit', async e => {
     e.preventDefault(); const r = await callApi('mudar_status', { id: document.getElementById('sRow').value, status: document.getElementById('sVal').value, obs: document.getElementById('sObs').value });
     if(r.success){ modais.st.hide(); load(); }
  });

  function openWapi(id) { document.getElementById('extRow').value=id; document.getElementById('extMsg').value=''; modais.ext.show(); }
  document.getElementById('fExt').addEventListener('submit', async e => {
     e.preventDefault(); const r = await callApi('registro_externo', { id: document.getElementById('extRow').value, obs: document.getElementById('extMsg').value });
     if(r.success){ modais.ext.hide(); alert('Registrado no histórico!'); }
  });

  async function gerarEntrega(id) {
     if(confirm("Deseja enviar este pedido para o setor de Logística/Entregas?")) {
         let obs = prompt("Deseja adicionar alguma observação para a logística? (Opcional)");
         const r = await callApi('gerar_entrega', { pedido_id: id, obs: obs });
         if(r.success) { alert(r.msg); load(); } else { alert(r.msg); }
     }
  }

  async function deleteOrder(id) { 
      if(confirm("Tem certeza que deseja apagar este pedido? Isso não pode ser desfeito.")) { 
          const r = await callApi('excluir_pedido', {id: id}); 
          if(r.success) load(); 
      } 
  }

  async function viewHist(id) {
     const tm = document.getElementById('timeline'); tm.innerHTML = 'Carregando...'; modais.hist.show();
     const r = await callApi('carregar_historico', {item_id: id, modulo: 'PEDIDO'});
     if(r.success) {
         if(!r.data.length) { tm.innerHTML = 'Sem histórico registrado.'; return; }
         let html = ''; r.data.forEach(x => { html += `<div class="timeline-item"><div class="timeline-date">${x.DATA_BR} <strong>${x.TIPO_ACAO}</strong></div><div class="small mt-1 text-dark fw-bold">${x.OBSERVACAO}</div><div class="small mt-1 text-muted fst-italic">Por: ${x.USUARIO}</div></div>`; });
         tm.innerHTML = html;
     }
  }
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>