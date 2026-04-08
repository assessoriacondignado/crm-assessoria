<?php require_once '../../../includes/header.php'; ?>

<style>
  :root { --primary-color: #f4511e; }
  .nav-tabs { border-bottom: 2px solid #eee; margin-bottom: 20px; }
  .nav-tabs .nav-link { color: #666; font-weight: 600; border: none; border-bottom: 3px solid transparent; padding: 10px 20px; cursor: pointer; }
  .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); background: transparent; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .table td { vertical-align: middle; white-space: pre-wrap; word-wrap: break-word; font-size: 0.85rem;}
  .bg-Ativo { background: #d1e7dd; color: #0f5132; }
  .bg-Suspenso { background: #fff3cd; color: #664d03; }
  .bg-Cancelado { background: #f8d7da; color: #842029; }
  .badge-status { font-size: 0.75rem; padding: 4px 8px; border-radius: 12px; font-weight: bold; text-transform: uppercase; }
  .historico-box { font-size: 0.75rem; color: #6c757d; max-height: 60px; overflow-y: auto; background: #f8f9fa; padding: 5px; border-radius: 4px; border: 1px solid #dee2e6;}
  .file-drop-area { border: 2px dashed #0d6efd; border-radius: 8px; padding: 20px; text-align: center; background: #f8f9fa; cursor: pointer; transition: 0.3s; }
  .file-drop-area:hover { background: #e9ecef; border-color: #0a58ca; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-primary fw-bold m-0"><i class="fas fa-box-open me-2"></i>Gestão de Produtos e Serviços</h3>
    </div>

    <div class="card-custom p-4">
       <ul class="nav nav-tabs mb-4" id="typeTabs">
         <li class="nav-item"><a class="nav-link active" onclick="loadType('PROD')">📦 Produtos</a></li>
         <li class="nav-item"><a class="nav-link" onclick="loadType('SERV')">🤝 Serviços</a></li>
       </ul>
       
       <div class="d-flex justify-content-between mb-4">
          <input id="search" class="form-control form-control-sm w-50 border-dark" placeholder="Pesquisar por nome ou código..." onkeyup="filter()">
          <button class="btn btn-primary btn-sm fw-bold border-dark shadow-sm" onclick="openNew()"><i class="fas fa-plus me-1"></i> Novo Item</button>
       </div>
       
       <div class="table-responsive border border-dark rounded shadow-sm">
          <table class="table table-hover mb-0 align-middle">
             <thead class="table-dark text-white border-dark">
                <tr>
                   <th style="width: 10%;">Código</th>
                   <th style="width: 25%;">Nome</th>
                   <th class="text-center" style="width: 15%;">Descrição</th>
                   <th style="width: 20%;">Histórico</th>
                   <th style="width: 10%;">Status</th>
                   <th class="text-end" style="width: 20%;">Ações</th>
                </tr>
             </thead>
             <tbody id="tbody"><tr><td colspan="6" class="text-center py-4 fw-bold text-primary"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr></tbody>
          </table>
       </div>
    </div>
</div>

<div class="modal fade" id="mForm"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title text-uppercase fw-bold" id="mTitle">Item</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fItem">
      <input type="hidden" id="eId">
      <input type="hidden" id="eType">
      <div class="mb-3"><label class="fw-bold small text-dark">Nome do Item:</label><input class="form-control border-dark text-uppercase" id="iNome" required placeholder="Ex: ASSESSORIA COMPLETA"></div>
      <div class="mb-4"><label class="fw-bold small text-dark">Descrição Completa:</label><textarea class="form-control border-dark" id="iDesc" rows="6" required></textarea></div>
      <div class="text-end border-top border-dark pt-3"><button type="button" class="btn btn-outline-dark fw-bold me-2" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary fw-bold border-dark shadow-sm">Salvar Registro</button></div>
   </form>
</div></div></div></div>

<div class="modal fade" id="mSt"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title text-uppercase fw-bold"><i class="fas fa-flag me-2"></i>Alterar Status</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fSt">
      <input type="hidden" id="sId">
      <div class="mb-3"><label class="fw-bold small text-dark">Novo Status:</label>
         <select class="form-select border-dark fw-bold" id="sVal">
            <option value="Ativo">🟢 Ativo</option><option value="Suspenso">🟡 Suspenso</option><option value="Cancelado">🔴 Cancelado</option>
         </select>
      </div>
      <div class="mb-4"><label class="fw-bold small text-dark">Observação (Obrigatória):</label><textarea class="form-control border-dark" id="sObs" rows="3" required placeholder="Motivo da alteração..."></textarea></div>
      <div class="text-end border-top border-dark pt-3"><button type="button" class="btn btn-outline-dark fw-bold me-2" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning text-dark fw-bold border-dark shadow-sm">Gravar Status</button></div>
   </form>
</div></div></div></div>

<div class="modal fade" id="mArquivos"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-info text-dark border-bottom border-dark"><h5 class="modal-title text-uppercase fw-bold" id="titleArquivos"><i class="fas fa-folder-open me-2"></i>Arquivos do Cliente</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <input type="hidden" id="arqItemId">
   <div class="file-drop-area mb-4" onclick="document.getElementById('fileInput').click()"><i class="fas fa-cloud-upload-alt fa-3x text-primary mb-2"></i><h5 class="text-dark fw-bold">Clique aqui para enviar um Arquivo</h5><p class="small text-muted mb-0">PDF, Imagens, Documentos...</p><input type="file" id="fileInput" class="d-none" onchange="uploadArquivo(this)"></div>
   <h6 class="fw-bold text-dark border-bottom border-dark pb-2 mb-3"><i class="fas fa-list me-1"></i> Lista de Arquivos</h6>
   <div class="table-responsive bg-white border border-dark rounded shadow-sm" style="max-height: 250px; overflow-y: auto;">
       <table class="table table-sm table-hover align-middle mb-0"><thead class="table-dark sticky-top" style="font-size: 0.8rem;"><tr><th>Nome do Documento</th><th>Data Envio</th><th class="text-center">Ações</th></tr></thead><tbody id="tbodyArquivos"><tr><td colspan="3" class="text-center py-3 text-muted">Carregando...</td></tr></tbody></table>
   </div>
</div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Fechar Explorador</button></div></div></div></div>

<div class="modal fade" id="mDesc"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-secondary text-white border-bottom border-dark"><h5 class="modal-title text-uppercase fw-bold"><i class="fas fa-align-left me-2"></i>Detalhes do Item</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><div id="descConteudo" class="p-3 bg-white border border-dark rounded shadow-sm text-dark" style="white-space: pre-wrap; font-size: 0.95rem; min-height: 150px;"></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-dark fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Fechar</button></div></div></div></div>

<div class="modal fade" id="mVariacao"><div class="modal-dialog modal-xl"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-success text-white border-bottom border-dark"><h5 class="modal-title text-uppercase fw-bold"><i class="fas fa-tags me-2"></i>Variações de Preço e Comissão</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <h5 class="fw-bold text-dark border-bottom border-dark pb-2 mb-3" id="nomeProdutoVariacao">Produto: </h5>
   
   <form id="fVariacao" class="row g-2 align-items-end mb-4 bg-white p-3 border border-dark rounded shadow-sm">
      <input type="hidden" id="vProdId">
      <div class="col-md-3"><label class="small fw-bold">Nome da Variação (Ex: Mensal, Anual):</label><input type="text" id="vNomeVar" class="form-control border-dark text-uppercase" required></div>
      <div class="col-md-2"><label class="small fw-bold text-success">Preço Venda (R$):</label><input type="number" step="0.01" id="vPreco" class="form-control border-dark fw-bold" required></div>
      <div class="col-md-3"><label class="small fw-bold text-danger">Tipo de Comissão Base:</label><select id="vTipoCom" class="form-select border-dark"><option value="PERCENTUAL">% Percentual sobre a Venda</option><option value="VALOR_FIXO">R$ Valor Fixo</option></select></div>
      <div class="col-md-2"><label class="small fw-bold text-danger">Valor Comissão:</label><input type="number" step="0.01" id="vValCom" class="form-control border-dark" required></div>
      <div class="col-md-2"><button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm"><i class="fas fa-plus me-1"></i> Adicionar</button></div>
   </form>

   <div class="table-responsive border border-dark rounded shadow-sm">
       <table class="table table-sm table-hover mb-0 align-middle">
           <thead class="table-dark text-white border-dark">
               <tr><th>Variação</th><th>Preço de Venda</th><th>Comissão Base</th><th>Status</th><th class="text-center">Ações</th></tr>
           </thead>
           <tbody id="tbodyVariacoes"><tr><td colspan="5" class="text-center py-4 text-muted">Carregando variações...</td></tr></tbody>
       </table>
   </div>
</div></div></div></div>

<script>
  let list = [], currentType = 'PROD';
  let modalForm, modalSt, modalArquivos, modalDescricao, modalVariacao;

  document.addEventListener("DOMContentLoaded", function() {
      modalForm = new bootstrap.Modal(document.getElementById('mForm'));
      modalSt = new bootstrap.Modal(document.getElementById('mSt'));
      modalArquivos = new bootstrap.Modal(document.getElementById('mArquivos'));
      modalDescricao = new bootstrap.Modal(document.getElementById('mDesc'));
      modalVariacao = new bootstrap.Modal(document.getElementById('mVariacao')); // Novo
      loadType('PROD');
  });

  async function callApi(acao, dados = {}, isFile = false) {
      let reqBody; if (isFile) { reqBody = dados; } else { reqBody = new FormData(); reqBody.append('acao', acao); for (const key in dados) { reqBody.append(key, dados[key]); } }
      const res = await fetch('produtos.ajax.php', { method: 'POST', body: reqBody }); return await res.json();
  }

  async function loadType(type) {
      currentType = type; document.querySelectorAll('.nav-link').forEach(b => b.classList.remove('active'));
      const btnIndex = type === 'PROD' ? 0 : 1; document.querySelectorAll('.nav-link')[btnIndex].classList.add('active');
      document.getElementById('tbody').innerHTML = '<tr><td colspan="6" class="text-center py-4 fw-bold text-primary"><i class="fas fa-spinner fa-spin"></i> Conectando...</td></tr>';
      const r = await callApi('listar', { tipo: type }); if(r.success) { list = r.data; filter(); }
  }

  function filter() { const t = document.getElementById('search').value.toLowerCase(); const res = list.filter(x => x.NOME.toLowerCase().includes(t) || x.CODIGO.toLowerCase().includes(t)); render(res); }

  function render(data) {
      const tb = document.getElementById('tbody'); tb.innerHTML = '';
      if(!data.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Vazio</td></tr>'; return; }
      
      data.forEach(x => {
         const stCls = `bg-${x.STATUS_ITEM}`; const inativo = x.STATUS_ITEM !== 'Ativo' ? 'opacity-75' : '';
         tb.innerHTML += `
         <tr class="border-bottom border-dark ${inativo}">
            <td><code class="text-dark fw-bold px-2 py-1 bg-light border border-secondary rounded">${x.CODIGO}</code></td>
            <td class="fw-bold text-primary">${x.NOME}</td>
            <td class="text-center"><button class="btn btn-sm btn-outline-secondary border-dark shadow-sm" onclick="verDescricao(${x.ID})" title="Ler Descrição Completa"><i class="fas fa-align-left me-1"></i> Ver Descrição</button></td>
            <td><div class="historico-box">${x.HISTORICO_OBS ? x.HISTORICO_OBS.replace(/\n/g, '<br>') : '-'}</div></td>
            <td><span class="badge-status ${stCls} border border-dark shadow-sm">${x.STATUS_ITEM}</span></td>
            <td class="text-end text-nowrap">
               <button class="btn btn-sm btn-success text-white fw-bold border-dark shadow-sm me-1" onclick="abrirVariacoes(${x.ID}, '${x.NOME}')" title="Preços e Comissões"><i class="fas fa-tags"></i> Tabela Preços</button>
               
               <button class="btn btn-sm btn-info text-dark fw-bold border-dark shadow-sm me-1" onclick="abrirArquivos(${x.ID}, '${x.NOME}')" title="Explorador"><i class="fas fa-folder-open"></i></button>
               <button class="btn btn-sm btn-outline-dark border-dark shadow-sm me-1" onclick="edit(${x.ID})" title="Editar"><i class="fas fa-edit"></i></button>
               <button class="btn btn-sm btn-outline-danger border-dark shadow-sm" onclick="st(${x.ID}, '${x.STATUS_ITEM}')" title="Status"><i class="fas fa-flag"></i></button>
            </td>
         </tr>`;
      });
  }

  function verDescricao(id) { const item = list.find(i => i.ID == id); if(item) { document.getElementById('descConteudo').innerHTML = item.DESCRICAO ? item.DESCRICAO : '<i class="text-muted">Nenhuma descrição.</i>'; modalDescricao.show(); } }
  function openNew() { document.getElementById('fItem').reset(); document.getElementById('eId').value = ""; document.getElementById('eType').value = currentType; document.getElementById('mTitle').innerHTML = "<i class='fas fa-plus-circle text-primary me-2'></i> Novo Item"; modalForm.show(); }
  function edit(id) { const x = list.find(i => i.ID == id); document.getElementById('eId').value = id; document.getElementById('eType').value = currentType; document.getElementById('iNome').value = x.NOME; document.getElementById('iDesc').value = x.DESCRICAO; document.getElementById('mTitle').innerHTML = "<i class='fas fa-edit text-primary me-2'></i> Editar Item"; modalForm.show(); }
  
  document.getElementById('fItem').addEventListener('submit', async e => { e.preventDefault(); const t = document.getElementById('eType').value || currentType; const id = document.getElementById('eId').value; const dados = { tipo: t, nome: document.getElementById('iNome').value, desc: document.getElementById('iDesc').value }; const acao = id ? 'editar' : 'salvar'; if (id) dados.id = id; const btn = e.target.querySelector('button[type="submit"]'); const btnOriginal = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; const r = await callApi(acao, dados); btn.disabled = false; btn.innerHTML = btnOriginal; if(r.success){ modalForm.hide(); loadType(currentType); } else { alert(r.msg); } });
  
  function st(id, statusAtual) { document.getElementById('sId').value = id; document.getElementById('sVal').value = statusAtual === 'Ativo' ? 'Suspenso' : 'Ativo'; document.getElementById('sObs').value = ''; modalSt.show(); }
  document.getElementById('fSt').addEventListener('submit', async e => { e.preventDefault(); const dados = { id: document.getElementById('sId').value, status: document.getElementById('sVal').value, obs: document.getElementById('sObs').value }; const btn = e.target.querySelector('button[type="submit"]'); const btnOriginal = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gravando...'; const r = await callApi('mudar_status', dados); btn.disabled = false; btn.innerHTML = btnOriginal; if(r.success) { modalSt.hide(); loadType(currentType); } else { alert(r.msg); } });

  // ARQUIVOS (MANTIDOS)
  async function abrirArquivos(id, nome) { document.getElementById('arqItemId').value = id; document.getElementById('titleArquivos').innerHTML = `<i class="fas fa-folder-open me-2"></i>Pasta: ${nome}`; modalArquivos.show(); carregarArquivos(); }
  async function carregarArquivos() { const id = document.getElementById('arqItemId').value; const tb = document.getElementById('tbodyArquivos'); tb.innerHTML = '<tr><td colspan="3" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Lendo...</td></tr>'; const r = await callApi('listar_arquivos', { item_id: id }); if(r.success) { tb.innerHTML = ''; if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted">Pasta vazia.</td></tr>'; return; } r.data.forEach(a => { tb.innerHTML += `<tr class="border-bottom"><td class="fw-bold"><i class="fas fa-file-alt text-secondary me-2"></i>${a.NOME_ARQUIVO}</td><td class="small text-muted">${a.DATA_BR}</td><td class="text-center"><a href="${a.CAMINHO_ARQUIVO}" download="${a.NOME_ARQUIVO}" class="btn btn-sm btn-success border-dark me-1"><i class="fas fa-download"></i></a><button class="btn btn-sm btn-danger border-dark" onclick="excluirArquivo(${a.ID})"><i class="fas fa-trash"></i></button></td></tr>`; }); } }
  async function uploadArquivo(input) { if(input.files.length === 0) return; const id = document.getElementById('arqItemId').value; const formData = new FormData(); formData.append('acao', 'upload_arquivo'); formData.append('item_id', id); formData.append('arquivo', input.files[0]); document.getElementById('tbodyArquivos').innerHTML = '<tr><td colspan="3" class="text-center py-3 text-warning"><i class="fas fa-spinner fa-spin"></i> Upload...</td></tr>'; const r = await callApi('', formData, true); input.value = ""; if(r.success) { carregarArquivos(); } else { alert(r.msg); carregarArquivos(); } }
  async function excluirArquivo(id) { if(!confirm("Deletar arquivo?")) return; document.getElementById('tbodyArquivos').innerHTML = '<tr><td colspan="3" class="text-center py-3 text-danger"><i class="fas fa-spinner fa-spin"></i> Excluindo...</td></tr>'; const r = await callApi('excluir_arquivo', { id: id }); if(r.success) { carregarArquivos(); } else { alert(r.msg); } }

  // ==========================================
  // NOVAS FUNÇÕES: VARIAÇÕES E COMISSÕES
  // ==========================================
  function abrirVariacoes(idProduto, nomeProduto) {
      document.getElementById('vProdId').value = idProduto;
      document.getElementById('nomeProdutoVariacao').innerHTML = `<i class="fas fa-box me-2"></i>${nomeProduto}`;
      modalVariacao.show();
      carregarVariacoes();
  }

  async function carregarVariacoes() {
      const id = document.getElementById('vProdId').value;
      const tb = document.getElementById('tbodyVariacoes');
      tb.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-primary"><i class="fas fa-spinner fa-spin"></i> Buscando Variações...</td></tr>';
      
      const r = await callApi('listar_variacoes', { item_id: id });
      tb.innerHTML = '';
      
      if(r.success) {
          if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted fw-bold">Nenhum preço configurado para este produto.</td></tr>'; return; }
          r.data.forEach(v => {
              const statusCls = v.STATUS_VARIACAO === 'Ativo' ? 'bg-success' : 'bg-danger';
              const sBtn = v.STATUS_VARIACAO === 'Ativo' ? `<button class="btn btn-sm btn-outline-danger" title="Inativar Variação" onclick="stVariacao(${v.ID}, 'Inativo')"><i class="fas fa-ban"></i></button>` : `<button class="btn btn-sm btn-outline-success" title="Ativar Variação" onclick="stVariacao(${v.ID}, 'Ativo')"><i class="fas fa-check"></i></button>`;
              
              tb.innerHTML += `
              <tr class="border-bottom border-dark">
                  <td class="fw-bold text-uppercase">${v.NOME_VARIACAO}</td>
                  <td class="fw-bold text-success">R$ ${parseFloat(v.VALOR_VENDA).toFixed(2).replace('.', ',')}</td>
                  <td class="fw-bold text-danger">${v.TIPO_COMISSAO === 'PERCENTUAL' ? v.VALOR_COMISSAO+' %' : 'R$ '+v.VALOR_COMISSAO}</td>
                  <td><span class="badge ${statusCls} border border-dark">${v.STATUS_VARIACAO}</span></td>
                  <td class="text-center text-nowrap">
                      ${sBtn}
                      <button class="btn btn-sm btn-dark ms-1" title="Excluir Permanentemente" onclick="excluirVariacao(${v.ID})"><i class="fas fa-trash"></i></button>
                  </td>
              </tr>`;
          });
      }
  }

  document.getElementById('fVariacao').addEventListener('submit', async e => {
      e.preventDefault();
      const dados = {
          item_id: document.getElementById('vProdId').value,
          nome_variacao: document.getElementById('vNomeVar').value,
          valor_venda: document.getElementById('vPreco').value,
          tipo_comissao: document.getElementById('vTipoCom').value,
          valor_comissao: document.getElementById('vValCom').value
      };
      
      const r = await callApi('salvar_variacao', dados);
      if(r.success) {
          document.getElementById('vNomeVar').value = '';
          document.getElementById('vPreco').value = '';
          document.getElementById('vValCom').value = '';
          carregarVariacoes();
      } else { alert(r.msg); }
  });

  async function stVariacao(id, novoStatus) {
      await callApi('mudar_status_variacao', { id: id, status: novoStatus });
      carregarVariacoes();
  }

  async function excluirVariacao(id) {
      if(!confirm("Tem certeza? Se algum cliente já comprou esse produto por esse preço, o histórico não será perdido, mas o preço sumirá da tela de vendas.")) return;
      await callApi('excluir_variacao', { id: id });
      carregarVariacoes();
  }
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>