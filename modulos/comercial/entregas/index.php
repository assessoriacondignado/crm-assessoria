<?php require_once '../../../includes/header.php'; ?>

<style>
  :root { --primary-color: #f4511e; }
  body { background-color: #f4f6f9; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .table td { vertical-align: middle; font-size: 0.85rem; }
  
  .bg-Incluído { background-color: #cff4fc; color: #055160; }
  .bg-Processando { background-color: #fff3cd; color: #664d03; }
  .bg-Entregue { background-color: #d1e7dd; color: #0f5132; }
  .bg-Cancelado { background-color: #f8d7da; color: #842029; }
  .bg-Pendente { background-color: #e2e3e5; color: #383d41; }
  .status-badge { font-size: 0.75rem; padding: 4px 8px; border-radius: 12px; font-weight: bold; text-transform: uppercase; border: 1px solid rgba(0,0,0,0.1); }
  
  .timeline-item { padding-left: 20px; border-left: 2px solid #ddd; margin-bottom: 15px; position: relative; }
  .timeline-item::before { content: ''; position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #6c757d; }
  .timeline-date { font-size: 0.75rem; color: #999; font-weight: bold; }

  /* Lista de Autocomplete para Clientes */
  .lista-suspensa { display: none; max-height: 180px; overflow-y: auto; z-index: 1050; position: absolute; width: 100%; border: 1px solid #343a40; }
  .lista-suspensa li { cursor: pointer; padding: 8px 12px; border-bottom: 1px solid #ddd; }
  .lista-suspensa li:hover { background-color: #f4511e; color: white; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-secondary fw-bold m-0"><i class="fas fa-list-check me-2"></i>Controle de Logística e Entregas</h3>
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
                <option value="Incluído">Incluído</option>
                <option value="Processando lista">Processando lista</option>
                <option value="Entregue">Entregue</option>
                <option value="Pendente">Pendente</option>
             </select>
          </div>
          <div class="col-md-5 text-end">
             <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="openNew()"><i class="fas fa-plus me-1"></i> Nova Solicitação Avulsa</button>
          </div>
       </div>

       <div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 400px;">
          <table class="table table-hover mb-0 align-middle">
             <thead class="table-dark text-white border-dark">
                <tr>
                   <th>Código</th>
                   <th>Cliente</th>
                   <th>Produto / Lista</th>
                   <th>Previsão Entrega</th>
                   <th>Renovação</th>
                   <th>Status</th>
                   <th class="text-center" style="width: 120px;">Ações</th>
                </tr>
             </thead>
             <tbody id="tbody"><tr><td colspan="7" class="text-center py-4 text-primary fw-bold"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr></tbody>
          </table>
       </div>
    </div>
</div>

<div class="modal fade" id="mNew"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-plus-square me-2"></i>Nova Solicitação Avulsa</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fNew">
      <div class="row mb-3 position-relative">
         <div class="col-md-12">
            <label class="fw-bold small text-primary"><i class="fas fa-search"></i> Buscar Cliente (Nome ou CPF):</label>
            <input type="text" id="nCli" class="form-control border-dark fw-bold text-uppercase" placeholder="Digite para buscar..." autocomplete="off" onkeyup="buscarClienteAJAX(this.value)" required>
            <ul id="listaClientes" class="list-unstyled bg-white lista-suspensa shadow-lg rounded-bottom"></ul>
         </div>
      </div>
      <div class="row mb-3">
         <div class="col-md-12">
            <label class="fw-bold small text-primary"><i class="fas fa-box"></i> Produto / Catálogo:</label>
            <select id="nProd" class="form-select border-dark fw-bold" required><option value="">Carregando produtos...</option></select>
         </div>
      </div>
      <div class="row mb-3">
         <div class="col-md-6">
             <label class="fw-bold small">Data Prevista de Entrega:</label>
             <input type="date" id="nDate" class="form-control border-dark">
         </div>
         <div class="col-md-6">
             <label class="fw-bold small">Status Inicial:</label>
             <select id="nStatus" class="form-select border-dark fw-bold">
                <option value="Incluído">Incluído (Aguardando Fila)</option>
                <option value="Processando lista">Processando lista</option>
                <option value="Pendente">Pendente</option>
                <option value="Entregue">Entregue</option>
             </select>
         </div>
      </div>
      <div class="mb-4">
          <label class="fw-bold small">Observações p/ Histórico:</label>
          <textarea id="nObs" class="form-control border-dark" rows="3" placeholder="Insira detalhes... Será gravado no Histórico."></textarea>
      </div>
      <div class="text-end border-top border-dark pt-3">
        <button type="button" class="btn btn-outline-dark fw-bold me-2" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary fw-bold border-dark shadow-sm">Salvar Ficha Avulsa</button>
      </div>
   </form>
</div></div></div></div>

<div class="modal fade" id="mViewPedido"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-file-invoice-dollar me-2"></i>Dados do Pedido / Renovação</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fViewPedido">
      <input type="hidden" id="vpPedId"><input type="hidden" id="vpEntId">
      <div class="row mb-3">
         <div class="col-md-6"><label class="fw-bold small">Cliente:</label><input type="text" id="vpCli" class="form-control border-dark fw-bold" required></div>
         <div class="col-md-6"><label class="fw-bold small">Produto/Plano:</label><select id="vpProd" class="form-select border-dark fw-bold prod-lista" required></select></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-6"><label class="fw-bold small">Valor Unitário (R$):</label><input type="number" step="0.01" id="vpUnit" class="form-control border-dark vp-calc" required></div>
         <div class="col-md-6"><label class="fw-bold small">Quantidade:</label><input type="number" id="vpQtd" class="form-control border-dark vp-calc" required></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-success">Acréscimo (R$):</label><input type="number" step="0.01" id="vpAcres" class="form-control border-dark vp-calc"></div>
         <div class="col-md-4"><label class="fw-bold small text-info">Variação (R$):</label><input type="number" step="0.01" id="vpVar" class="form-control border-dark vp-calc"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Desconto (R$):</label><input type="number" step="0.01" id="vpDesc" class="form-control border-dark vp-calc"></div>
      </div>
      <div class="row mb-3">
         <div class="col-md-4"><label class="fw-bold small text-secondary">Cupom:</label><input type="text" id="vpCupomNome" class="form-control border-dark text-uppercase"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Valor Cupom (R$):</label><input type="number" step="0.01" id="vpCupomVal" class="form-control border-dark vp-calc"></div>
         <div class="col-md-4"><label class="fw-bold small text-danger">Fidelidade (R$):</label><input type="number" step="0.01" id="vpFidel" class="form-control border-dark vp-calc"></div>
      </div>
      <div class="row mb-4 align-items-end p-3 bg-white border border-dark rounded shadow-sm">
         <div class="col-md-4"><label class="fw-bold small text-warning"><i class="fas fa-percent"></i> IVA (%):</label><input type="number" step="0.01" id="vpIva" class="form-control border-dark vp-calc"></div>
         <div class="col-md-8"><label class="fw-bold text-dark h6 mb-1">TOTAL (R$):</label><input type="text" id="vpTotal" class="form-control form-control-lg border-dark bg-dark text-white fw-bold" readonly></div>
      </div>
      <div class="row mb-4 p-3 border border-warning rounded bg-white shadow-sm">
          <h6 class="fw-bold text-warning text-uppercase"><i class="fas fa-sync-alt me-2"></i>Controle de Renovação</h6>
          <div class="col-md-6"><label class="fw-bold small">Prevista p/ Renovação:</label><input type="date" id="vpDataPrev" class="form-control border-dark"></div>
          <div class="col-md-6"><label class="fw-bold small text-success">Data Efetiva da Renovação:</label><input type="date" id="vpDataEfetiva" class="form-control border-dark"></div>
      </div>
      <div class="mb-4"><label class="fw-bold small">Observações Financeiras:</label><textarea id="vpObs" class="form-control border-dark" rows="2"></textarea></div>
      <button type="submit" class="btn btn-dark w-100 fw-bold shadow-sm border-dark">Salvar Alterações</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mSt"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-flag me-2"></i>Status da Entrega</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fSt">
      <input type="hidden" id="sRow">
      <div class="mb-3"><label class="fw-bold small">Novo Status:</label>
         <select id="sVal" class="form-select border-dark fw-bold">
            <option value="Incluído">Incluído (Aguardando Fila)</option>
            <option value="Processando lista">Processando lista (Em execução)</option>
            <option value="Pendente">Pendente (Falta Info/Pagamento)</option>
            <option value="Entregue">Entregue (Concluído)</option>
            <option value="Cancelado">Cancelado</option>
         </select>
      </div>
      <div class="mb-4"><label class="fw-bold small">Observação p/ Histórico:</label><textarea id="sObs" class="form-control border-dark" rows="3" required></textarea></div>
      <button type="submit" class="btn btn-warning w-100 fw-bold border-dark text-dark shadow-sm">Salvar Status</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mExt"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-success text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fab fa-whatsapp me-2"></i>Contato com Cliente</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fExt">
      <input type="hidden" id="extRow">
      <div class="alert alert-success border-dark py-2 small fw-bold"><i class="fas fa-info-circle"></i> O texto será salvo na Tabela de Histórico.</div>
      <div class="mb-3"><label class="small fw-bold">Mensagem:</label><textarea id="extMsg" class="form-control border-dark" rows="4" required placeholder="Olá, sua lista está pronta..."></textarea></div>
      <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm text-dark"><i class="fab fa-whatsapp me-1"></i> Enviar</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mHist"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-secondary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-history me-2"></i>Histórico da Ficha</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><div id="timeline" style="max-height:400px; overflow-y:auto; padding: 10px;"></div></div></div></div></div>

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
      document.querySelectorAll('.vp-calc').forEach(el => el.addEventListener('input', calcularTotalVp));
      load(); carregarListaProdutos();
  });

  async function callApi(acao, dados = {}) {
      try {
          const fd = new FormData(); fd.append('acao', acao);
          for (const key in dados) { fd.append(key, dados[key]); }
          const res = await fetch('entregas.ajax.php', { method: 'POST', body: fd });
          if (!res.ok) throw new Error("Erro HTTP");
          return await res.json();
      } catch(e) { return { success: false, msg: "Falha de conexão com backend." }; }
  }

  // CARREGA PRODUTOS NO SELECT
  async function carregarListaProdutos() {
      const r = await callApi('buscar_produtos');
      if (r.success) {
          let ops = '<option value="">-- Selecione o Produto --</option>';
          r.data.forEach(p => { ops += `<option value="${p.NOME}">${p.NOME}</option>`; });
          document.getElementById('nProd').innerHTML = ops;
          document.querySelectorAll('.prod-lista').forEach(sel => sel.innerHTML = ops);
      }
  }

  // BUSCADOR INTELIGENTE PARA CLIENTES
  let delayTimer;
  async function buscarClienteAJAX(termo) {
      clearTimeout(delayTimer); let ul = document.getElementById('listaClientes');
      if (termo.length < 3) { ul.style.display = 'none'; return; }
      delayTimer = setTimeout(async () => {
          const r = await callApi('buscar_clientes', { termo: termo });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = '';
              r.data.forEach(c => {
                  let li = document.createElement('li');
                  li.innerHTML = `<strong>${c.NOME}</strong> <br><small>CPF: ${c.CPF}</small>`;
                  li.onclick = () => { document.getElementById('nCli').value = c.NOME; ul.style.display = 'none'; };
                  ul.appendChild(li);
              });
              ul.style.display = 'block';
          } else { ul.style.display = 'none'; }
      }, 400);
  }
  document.addEventListener('click', (e) => { if(e.target.id !== 'nCli') document.getElementById('listaClientes').style.display = 'none'; });

  function calcularTotalVp() {
      let v = (id) => parseFloat(document.getElementById('vp' + id).value) || 0;
      let qtd = parseInt(document.getElementById('vpQtd').value) || 1;
      let subtotal = (v('Unit') * qtd) + v('Acres') + v('Var');
      let descontos = v('Desc') + v('CupomVal') + v('Fidel');
      let base = subtotal - descontos;
      document.getElementById('vpTotal').value = (base + (base * (v('Iva') / 100))).toFixed(2);
  }

  async function load() {
     document.getElementById('tbody').innerHTML = '<tr><td colspan="7" class="text-center py-4 text-primary fw-bold"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
     const r = await callApi('listar_entregas');
     if (r && r.success) { list = r.data; filter(); } 
  }

  function getBadgeClass(status) {
      const s = (status||'').toLowerCase();
      if(s.includes('cancelado')) return 'bg-Cancelado';
      if(s.includes('entregue')) return 'bg-Entregue';
      if(s.includes('processando')) return 'bg-Processando';
      if(s.includes('pendente')) return 'bg-Pendente';
      return 'bg-Incluído';
  }

  function render(data) {
     const tb = document.getElementById('tbody'); tb.innerHTML = '';
     if(!data.length) { tb.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4 fw-bold">Nenhuma entrega na fila.</td></tr>'; return; }
     
     data.forEach(x => {
        const stCls = getBadgeClass(x.STATUS_ENTREGA);
        let renovacaoHtml = `<span class="badge bg-secondary border border-dark">Sem Renovação</span>`;
        if(x.DATA_EFET_REN_BR) renovacaoHtml = `<span class="badge bg-success border border-dark text-white"><i class="fas fa-check-circle"></i> Renovado: ${x.DATA_EFET_REN_BR}</span>`;
        else if(x.DATA_PREV_REN_BR) renovacaoHtml = `<span class="badge bg-warning border border-dark text-dark"><i class="fas fa-clock"></i> Previsto: ${x.DATA_PREV_REN_BR}</span>`;

        tb.innerHTML += `
        <tr class="border-bottom border-dark">
           <td><code class="text-dark fw-bold px-2 py-1 bg-light border border-secondary rounded">${x.CODIGO}</code></td>
           <td class="fw-bold text-primary">${x.CLIENTE_NOME}</td>
           <td class="text-truncate" style="max-width: 180px;">${x.PRODUTO_NOME}<br><small class="text-muted fst-italic">${x.OBSERVACAO}</small></td>
           <td class="fw-bold">${x.DATA_PREVISTA_BR}</td>
           <td>${renovacaoHtml}</td>
           <td><span class="badge-status ${stCls}">${x.STATUS_ENTREGA}</span></td>
           <td class="text-center">
              <div class="dropdown">
                <button class="btn btn-sm btn-dark dropdown-toggle fw-bold shadow-sm" type="button" data-bs-toggle="dropdown">Ações</button>
                <ul class="dropdown-menu shadow-lg border-dark dropdown-menu-end" style="z-index: 99999;">
                  <li><a class="dropdown-item fw-bold text-primary" href="#" onclick="viewPedido(${x.ID})"><i class="fas fa-file-invoice-dollar me-2"></i> Visualizar Pedido</a></li>
                  <li><a class="dropdown-item fw-bold text-success" href="#" onclick="openWapi(${x.ID})"><i class="fab fa-whatsapp me-2"></i> Msg Cliente</a></li>
                  <li><a class="dropdown-item fw-bold text-secondary" href="#" onclick="viewHist(${x.ID})"><i class="fas fa-history me-2"></i> Tabela de Histórico</a></li>
                  <li><a class="dropdown-item fw-bold text-warning" href="#" onclick="openSt(${x.ID}, '${x.STATUS_ENTREGA}')"><i class="fas fa-flag text-dark me-2"></i> Status Entrega</a></li>
                  <li><hr class="dropdown-divider border-dark"></li>
                  <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="deleteOrder(${x.ID})"><i class="fas fa-trash me-2"></i> Excluir Entrega</a></li>
                </ul>
              </div>
           </td>
        </tr>`;
     });
  }

  function filter() {
     const t = document.getElementById('search').value.toLowerCase(); const st = document.getElementById('fStatus').value.toLowerCase();
     render(list.filter(x => (x.CLIENTE_NOME.toLowerCase().includes(t) || x.PRODUTO_NOME.toLowerCase().includes(t) || x.CODIGO.toLowerCase().includes(t)) && (!st || x.STATUS_ENTREGA.toLowerCase().includes(st)) ));
  }

  function openNew() { document.getElementById('fNew').reset(); modais.new.show(); }
  document.getElementById('fNew').addEventListener('submit', async e => {
     e.preventDefault();
     const f = { cliente: document.getElementById('nCli').value, produto: document.getElementById('nProd').value, dataPrevista: document.getElementById('nDate').value, status: document.getElementById('nStatus').value, obs: document.getElementById('nObs').value };
     const r = await callApi('salvar_entrega', f);
     if(r.success){ modais.new.hide(); load(); } else alert(r.msg);
  });

  async function viewPedido(entrega_id) {
      const r = await callApi('get_dados_pedido', {id: entrega_id});
      if(r.success) {
          const d = r.data;
          document.getElementById('vpEntId').value = entrega_id; document.getElementById('vpPedId').value = d.ID;
          document.getElementById('vpCli').value = d.CLIENTE_NOME || ''; document.getElementById('vpProd').value = d.PRODUTO_NOME || '';
          document.getElementById('vpUnit').value = d.VALOR_UNITARIO || '0.00'; document.getElementById('vpQtd').value = d.QUANTIDADE || '1';
          document.getElementById('vpAcres').value = d.ACRESCIMO || '0.00'; document.getElementById('vpVar').value = d.VARIACAO || '0.00';
          document.getElementById('vpDesc').value = d.DESCONTO || '0.00'; document.getElementById('vpCupomNome').value = d.CUPOM || '';
          document.getElementById('vpCupomVal').value = d.VALOR_CUPOM || '0.00'; document.getElementById('vpFidel').value = d.FIDELIDADE || '0.00';
          document.getElementById('vpIva').value = d.IVA || '0.00'; document.getElementById('vpTotal').value = d.TOTAL || d.VALOR || '0.00';
          document.getElementById('vpDataPrev').value = d.DATA_PREVISTA_RENOVACAO || ''; document.getElementById('vpDataEfetiva').value = d.DATA_EFETIVA_RENOVACAO || '';
          document.getElementById('vpObs').value = d.OBSERVACAO || '';
          modais.viewPed.show();
      } else { alert(r.msg); }
  }
  
  document.getElementById('fViewPedido').addEventListener('submit', async e => {
      e.preventDefault();
      const f = { entrega_id: document.getElementById('vpEntId').value, pedido_id: document.getElementById('vpPedId').value, cliente: document.getElementById('vpCli').value, produto: document.getElementById('vpProd').value, unitario: document.getElementById('vpUnit').value, qtd: document.getElementById('vpQtd').value, acrescimo: document.getElementById('vpAcres').value, variacao: document.getElementById('vpVar').value, desconto: document.getElementById('vpDesc').value, cupom_nome: document.getElementById('vpCupomNome').value, cupom_val: document.getElementById('vpCupomVal').value, fidelidade: document.getElementById('vpFidel').value, iva: document.getElementById('vpIva').value, total: document.getElementById('vpTotal').value, data_prevista: document.getElementById('vpDataPrev').value, data_efetiva: document.getElementById('vpDataEfetiva').value, obs: document.getElementById('vpObs').value };
      const r = await callApi('editar_pedido_via_entrega', f);
      if(r.success) { modais.viewPed.hide(); load(); alert(r.msg); }
  });

  function openSt(id, st) { document.getElementById('sRow').value=id; document.getElementById('sVal').value=st; document.getElementById('sObs').value=''; modais.st.show(); }
  document.getElementById('fSt').addEventListener('submit', async e => {
     e.preventDefault(); const r = await callApi('mudar_status', { id: document.getElementById('sRow').value, status: document.getElementById('sVal').value, obs: document.getElementById('sObs').value });
     if(r.success){ modais.st.hide(); load(); }
  });

  function openWapi(id) { document.getElementById('extRow').value=id; document.getElementById('extMsg').value=''; modais.ext.show(); }
  document.getElementById('fExt').addEventListener('submit', async e => {
     e.preventDefault(); const r = await callApi('registro_externo', { id: document.getElementById('extRow').value, obs: document.getElementById('extMsg').value });
     if(r.success){ modais.ext.hide(); alert('Registrado!'); }
  });

  async function deleteOrder(id) { if(confirm("Deseja apagar esta Ficha de Entrega permanentemente?")) { const r = await callApi('excluir_entrega', {id: id}); if(r.success) load(); } }

  async function viewHist(id) {
     const tm = document.getElementById('timeline'); tm.innerHTML = 'Carregando...'; modais.hist.show();
     const r = await callApi('carregar_historico', {item_id: id});
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