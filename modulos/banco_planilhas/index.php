<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>

<style>
  .nav-tabs .nav-link { color: #495057; font-weight: 600; border: none; border-bottom: 3px solid transparent; }
  .nav-tabs .nav-link:hover { color: #000; background: #f8f9fa; }
  .nav-tabs .nav-link.active { color: #000; border-bottom: 3px solid #212529; background: transparent; font-weight: bold; }
  .hidden { display: none !important; }
  
  /* ESTILIZAÇÃO DO EDITOR DE TEXTO PARA COMBINAR COM O TEMA */
  .ql-toolbar.ql-snow { border-color: #212529 !important; border-top-left-radius: 5px; border-top-right-radius: 5px; background: #f8f9fa; }
  .ql-container.ql-snow { border-color: #212529 !important; border-bottom-left-radius: 5px; border-bottom-right-radius: 5px; font-family: inherit; font-size: 14px; }
  .ql-editor { min-height: 120px; }
</style>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-dark fw-bold text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-server text-info me-2"></i> Banco de Planilhas (Data Lake)</h2>
        <p class="text-muted fw-bold">Gerencie, localize e baixe arquivos brutos do seu acervo.</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-12">
        <div class="card border-dark shadow-lg rounded-3 mb-5 overflow-hidden">
            <div class="card-header bg-dark p-0 border-bottom border-dark">
                <ul class="nav nav-tabs m-0 bg-light" id="moduleTabs">
                  <li class="nav-item"><button class="nav-link active px-4 py-3 text-uppercase" onclick="switchTab('list')"><i class="fas fa-list me-2"></i> Acervo de Planilhas</button></li>
                  <li class="nav-item"><button class="nav-link px-4 py-3 text-uppercase" onclick="switchTab('import')"><i class="fas fa-upload me-2"></i> Importar Nova Planilha</button></li>
                </ul>
            </div>
            <div class="card-body p-4 bg-light">

              <div id="view-list" class="content-view">
                 <div class="row mb-3">
                    <div class="col-md-5">
                       <input type="text" id="searchFile" class="form-control border-dark shadow-sm fw-bold" placeholder="Pesquisa rápida de Planilha/Convênio..." onkeyup="filterList()">
                    </div>
                    <div class="col-md-7 text-end d-flex justify-content-end gap-2">
                       <button class="btn btn-outline-secondary fw-bold shadow-sm bg-white border-dark text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="false">
                           <i class="fas fa-sliders-h text-info"></i> Filtro Aprimorado de Planilhas
                       </button>
                       <button class="btn btn-dark fw-bold shadow-sm border-dark" onclick="recarregarAcervoCompleto()"><i class="fas fa-sync-alt me-1"></i> Atualizar Acervo</button>
                    </div>
                 </div>

                 <div class="collapse mb-4" id="painelBuscaAvancada">
                    <div class="card border-dark shadow-lg rounded-3 overflow-hidden">
                        <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase" style="letter-spacing: 1px;">
                            <i class="fas fa-filter text-info me-2"></i> Localizador Avançado de Planilhas
                        </div>
                        <div class="card-body bg-light">
                            <div class="alert alert-secondary py-2 small mb-3 border-dark">
                                <i class="fas fa-info-circle"></i> <b>Dica:</b> Use o filtro abaixo para encontrar planilhas específicas no seu acervo.
                            </div>
                            <div id="areaFiltrosExp"></div>
                            <div class="d-flex justify-content-between mt-3 border-top border-dark pt-3">
                                <button type="button" class="btn btn-sm btn-dark fw-bold shadow-sm" onclick="adicionarFiltroExp()"><i class="fas fa-plus"></i> Adicionar Regra</button>
                                <div>
                                    <button type="button" class="btn btn-sm btn-outline-dark shadow-sm bg-white" onclick="limparFiltroGeral()">Limpar Filtros</button>
                                    <button type="button" class="btn btn-sm btn-info text-dark border-dark fw-bold shadow-sm" id="btnExecutarFiltro" onclick="filtrarCatologoPlanilhas()"><i class="fas fa-search"></i> Filtrar Catálogo</button>
                                </div>
                            </div>
                        </div>
                    </div>
                 </div>
                 
                 <div id="loaderList" class="text-center py-5 hidden">
                    <div class="spinner-border text-dark" role="status"></div>
                    <p class="mt-2 fw-bold">Carregando acervo...</p>
                 </div>
                 
                 <div id="containerList" class="table-responsive bg-white shadow-sm border-dark rounded-3">
                    <table class="table table-hover align-middle mb-0 border-dark text-center fs-6">
                        <thead class="table-dark text-white text-uppercase" style="font-size: 0.85rem;">
                            <tr>
                                <th class="text-start ps-4">Nome / Convênio</th>
                                <th>Qtd. Linhas</th>
                                <th>Importação / Tipo</th>
                                <th>Qualidade</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tbody_arquivos"></tbody>
                    </table>
                 </div>
              </div>

              <div id="view-import" class="content-view hidden">
                 <div class="row justify-content-center">
                    <div class="col-md-10">
                       <div id="import-step-1">
                           <form id="formUp" enctype="multipart/form-data">
                              <div class="row g-3 mb-4">
                                 <div class="col-md-6"><label class="form-label fw-bold text-dark">Nome da Planilha *</label><input type="text" id="i_nome" name="nome_planilha" class="form-control border-dark shadow-sm" required></div>
                                 <div class="col-md-3"><label class="form-label fw-bold text-dark">Convênio</label><input type="text" id="i_convenio" name="convenio" class="form-control border-dark shadow-sm"></div>
                                 
                                 <div class="col-md-3"><label class="form-label fw-bold text-dark">Valor (R$)</label><input type="number" step="0.01" id="i_valor_lista" name="valor_lista" class="form-control border-dark shadow-sm" placeholder="Ex: 150.00"></div>

                                 <div class="col-md-3"><label class="form-label fw-bold text-dark">Data Importação</label><input type="date" id="i_data" name="data_importacao" class="form-control border-dark shadow-sm" required value="<?= date('Y-m-d') ?>"></div>
                                 <div class="col-md-3">
                                    <label class="form-label fw-bold text-dark">Tipo de Lista</label>
                                    <select id="i_tipo_lista" name="tipo_lista" class="form-select border-dark shadow-sm">
                                        <option value="Cadastral">Cadastral</option><option value="Contratos">Contratos</option><option value="Profissional">Profissional</option>
                                    </select>
                                 </div>
                                 <div class="col-md-3"><label class="form-label fw-bold text-dark">Qualidade (Tel)</label><select id="i_qual_tel" name="qualidade_telefones" class="form-select border-dark shadow-sm"><option value="OTIMA">Ótima</option><option value="BOA">Boa</option><option value="REGULAR" selected>Regular</option><option value="RUIM">Ruim</option></select></div>
                                 <div class="col-md-3"><label class="form-label fw-bold text-dark">Qualidade (Fin)</label><select id="i_qual_fin" name="qualidade_finalidade" class="form-select border-dark shadow-sm"><option value="OTIMA">Ótima</option><option value="BOA">Boa</option><option value="REGULAR" selected>Regular</option><option value="RUIM">Ruim</option></select></div>
                                 
                                 <div class="col-md-12">
                                    <label class="form-label fw-bold text-dark">Descrição / Observações da Lista</label>
                                    <div class="shadow-sm">
                                        <div id="editor_importacao" style="background: #fff;"></div>
                                    </div>
                                    <input type="hidden" id="i_desc" name="descricao_planilha">
                                 </div>

                                 <div class="col-md-12 mt-4"><label class="form-label fw-bold text-dark">Arquivo CSV (Limite: 3GB)</label><input type="file" id="i_arquivo" name="arquivo_csv" class="form-control form-control-lg border-dark shadow-sm" accept=".csv" required></div>
                              </div>
                              <div class="text-end border-top border-dark pt-3"><button type="button" id="btn_preview" class="btn btn-dark btn-lg shadow-sm fw-bold border-dark" onclick="enviarParaPreview()">Ler Arquivo <i class="fas fa-arrow-right ms-1"></i></button></div>
                           </form>
                       </div>
                       <div id="import-step-2" class="hidden">
                           <h5 class="fw-bold text-dark text-uppercase border-bottom border-dark pb-2 mb-3"><i class="fas fa-eye text-info me-2"></i> Conferência de Cabeçalhos</h5>
                           <input type="hidden" id="cache_filename"><input type="hidden" id="cache_headers">
                           <div class="table-responsive bg-white shadow-sm border-dark rounded-3 overflow-hidden mb-4" style="max-height: 300px;"><table class="table table-sm table-hover align-middle mb-0 border-dark text-center"><thead class="table-dark text-white text-uppercase sticky-top" id="preview_head" style="font-size: 0.8rem; z-index: 1;"></thead><tbody id="preview_body" class="border-dark text-dark"></tbody></table></div>
                           <div class="d-flex justify-content-between border-top border-dark pt-3"><button type="button" class="btn btn-outline-dark fw-bold bg-white" onclick="cancelarImportacao()"><i class="fas fa-times me-1"></i> Cancelar</button><button type="button" id="btn_salvar_bd" class="btn btn-success btn-lg shadow-sm fw-bold border-dark text-dark" onclick="salvarNoBanco()"><i class="fas fa-save me-1"></i> Confirmar e Salvar Planilha</button></div>
                       </div>
                    </div>
                 </div>
              </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAmostra" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-dark text-white border-bottom border-dark">
        <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-table text-info me-2"></i> Visualizando: <span id="titulo_amostra" class="text-warning"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light p-0">
          <div class="table-responsive bg-white" style="max-height: 500px;">
              <table class="table table-hover align-middle mb-0 border-dark text-center" style="font-size: 0.85rem; white-space: nowrap;">
                  <thead class="table-dark text-white text-uppercase sticky-top" id="tblPreviewHead" style="z-index: 1;"></thead>
                  <tbody id="tblPreviewBody" class="border-dark text-dark"></tbody>
              </table>
          </div>
      </div>
      <div class="modal-footer bg-light border-top border-dark justify-content-between">
          <span class="small fw-bold text-muted">Amostra de 50 linhas diretamente do arquivo original.</span>
          <a href="#" id="btn_exportar_modal" class="btn btn-success btn-lg border-dark fw-bold shadow-sm text-dark" target="_blank"><i class="fas fa-download me-1"></i> EXPORTAR PLANILHA COMPLETA</a>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalInfo" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-primary text-white border-bottom border-dark">
        <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-info-circle text-light me-2"></i> Informações da Planilha</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
          <table class="table table-bordered border-dark table-sm bg-white shadow-sm mb-0">
              <tbody>
                  <tr><th class="bg-light" style="width: 30%;">Nome da Planilha</th><td id="info_nome" class="fw-bold text-primary"></td></tr>
                  <tr><th class="bg-light">Convênio</th><td id="info_convenio"></td></tr>
                  <tr><th class="bg-light">Tipo de Lista</th><td id="info_tipo"></td></tr>
                  <tr><th class="bg-light">Valor (R$)</th><td id="info_valor" class="text-success fw-bold"></td></tr>
                  <tr><th class="bg-light">Quantidade de Linhas</th><td id="info_qtd"></td></tr>
                  <tr><th class="bg-light">Qualidade (Telefones)</th><td id="info_qual_tel"></td></tr>
                  <tr><th class="bg-light">Qualidade (Finalidade)</th><td id="info_qual_fin"></td></tr>
                  <tr><th class="bg-light">Data de Importação</th><td id="info_data"></td></tr>
                  <tr><th class="bg-light">Status do Arquivo</th><td id="info_status"></td></tr>
              </tbody>
          </table>
          <h6 class="fw-bold mt-4 border-bottom border-dark pb-2"><i class="fas fa-align-left text-secondary me-2"></i> Descrição / Observações</h6>
          <div id="info_desc" class="p-3 bg-white border border-dark rounded shadow-sm" style="min-height: 100px;"></div>
      </div>
      <div class="modal-footer bg-white border-top border-dark">
          <button type="button" class="btn btn-dark fw-bold" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalFicha" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-warning text-dark border-bottom border-dark">
        <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-dark me-2"></i> Editar Registro da Planilha</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
          <input type="hidden" id="edit_id">
          
          <div class="row g-3">
             <div class="col-md-6">
                <label class="fw-bold small text-dark">Nome</label>
                <input type="text" id="edit_nome" class="form-control border-dark shadow-sm fw-bold">
             </div>
             <div class="col-md-3">
                <label class="fw-bold small text-dark">Status</label>
                <select id="edit_status" class="form-select border-dark shadow-sm fw-bold">
                    <option value="Ativo">Ativo</option><option value="Inativo">Inativo (Ocultar)</option>
                </select>
             </div>
             <div class="col-md-3">
                <label class="fw-bold small text-dark">Convênio</label>
                <input type="text" id="edit_convenio" class="form-control border-dark shadow-sm">
             </div>

             <div class="col-md-3">
                <label class="fw-bold small text-dark">Tipo de Lista</label>
                <select id="edit_tipo_lista" class="form-select border-dark shadow-sm">
                    <option value="Cadastral">Cadastral</option><option value="Contratos">Contratos</option><option value="Profissional">Profissional</option>
                </select>
             </div>
             <div class="col-md-3">
                <label class="fw-bold small text-dark">Valor (R$)</label>
                <input type="number" step="0.01" id="edit_valor_lista" class="form-control border-dark shadow-sm">
             </div>
             <div class="col-md-3">
                <label class="fw-bold small text-dark">Qualidade (Tel)</label>
                <select id="edit_qual_tel" class="form-select border-dark shadow-sm">
                    <option value="OTIMA">Ótima</option><option value="BOA">Boa</option><option value="REGULAR">Regular</option><option value="RUIM">Ruim</option>
                </select>
             </div>
             <div class="col-md-3">
                <label class="fw-bold small text-dark">Qualidade (Fin)</label>
                <select id="edit_qual_fin" class="form-select border-dark shadow-sm">
                    <option value="OTIMA">Ótima</option><option value="BOA">Boa</option><option value="REGULAR">Regular</option><option value="RUIM">Ruim</option>
                </select>
             </div>

             <div class="col-md-6">
                <label class="fw-bold small text-dark">Qtd. Linhas Lidas</label>
                <input type="text" id="edit_qtd" class="form-control border-dark shadow-sm bg-secondary text-white" readonly>
             </div>
             <div class="col-md-6">
                <label class="fw-bold small text-dark">Importado em</label>
                <input type="text" id="edit_data" class="form-control border-dark shadow-sm bg-secondary text-white" readonly>
             </div>

             <div class="col-12">
                <label class="fw-bold small text-dark">Descrição / Observações</label>
                <div class="shadow-sm">
                    <div id="editor_ficha" style="background: #fff;"></div>
                </div>
             </div>
          </div>
          
      </div>
      <div class="modal-footer bg-white border-top border-dark justify-content-between">
          <button class="btn btn-outline-danger fw-bold" onclick="excluirPlanilha()"><i class="fas fa-trash me-1"></i> Excluir Arquivo</button>
          <button class="btn btn-warning border-dark fw-bold text-dark" onclick="salvarEdicao()"><i class="fas fa-save me-1"></i> Salvar Alterações</button>
      </div>
    </div>
  </div>
</div>

<script>
let globalFiles = [];
let quillImport, quillFicha;

const opcoesFiltroPlanilha = `
    <option value="nome_planilha">Nome da Planilha</option>
    <option value="convenio">Convênio</option>
    <option value="tipo_lista">Tipo de Lista</option>
    <option value="valor_lista">Valor (R$)</option>
    <option value="data_importacao">Data de Importação (DD/MM/YYYY)</option>
    <option value="quantidade_cpf">Quantidade de Linhas/CPFs</option>
    <option value="qualidade_telefones">Qualidade (Telefones)</option>
    <option value="qualidade_finalidade">Qualidade (Finalidade)</option>
`;

window.onload = function() {
    var barraFerramentas = [
        ['bold', 'italic', 'underline', 'strike'], 
        [{ 'list': 'bullet' }, { 'list': 'ordered' }],
        ['clean']
    ];

    quillImport = new Quill('#editor_importacao', {
        theme: 'snow', placeholder: 'Escreva as observações aqui...', modules: { toolbar: barraFerramentas }
    });

    quillFicha = new Quill('#editor_ficha', {
        theme: 'snow', modules: { toolbar: barraFerramentas }
    });

    adicionarFiltroExp();
    loadFiles();
};

function switchTab(id) {
    document.querySelectorAll('.content-view').forEach(e => e.classList.add('hidden'));
    document.querySelectorAll('#moduleTabs .nav-link').forEach(e => e.classList.remove('active'));
    document.getElementById('view-' + id).classList.remove('hidden');
    if(id === 'list') { document.querySelectorAll('#moduleTabs .nav-link')[0].classList.add('active'); }
    if(id === 'import') document.querySelectorAll('#moduleTabs .nav-link')[1].classList.add('active');
}

function recarregarAcervoCompleto() {
    document.getElementById('searchFile').value = '';
    limparFiltroGeral();
}

function loadFiles() {
    document.getElementById('containerList').classList.add('hidden');
    document.getElementById('loaderList').classList.remove('hidden');
    fetch('ajax_acervo.php?acao=listar').then(res => res.json()).then(data => {
        globalFiles = data; filterList();
        document.getElementById('loaderList').classList.add('hidden');
        document.getElementById('containerList').classList.remove('hidden');
    });
}

function filterList() {
    const termo = document.getElementById('searchFile').value.toLowerCase();
    const tbody = document.getElementById('tbody_arquivos');
    tbody.innerHTML = '';
    let count = 0;

    globalFiles.forEach(f => {
        let statusReal = f.status_arquivo ? f.status_arquivo : 'Ativo';
        let tipo = f.tipo_lista ? f.tipo_lista.toUpperCase() : 'CADASTRAL';
        let valor = f.valor_lista ? parseFloat(f.valor_lista).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : '0,00';
        
        if(f.nome_planilha.toLowerCase().includes(termo) || (f.convenio && f.convenio.toLowerCase().includes(termo))) {
            let tr = document.createElement('tr');
            tr.className = "border-bottom border-dark bg-white";
            if(statusReal === 'Inativo') tr.classList.add('table-danger');
            
            tr.innerHTML = `
                <td class="text-start ps-4 py-3"><span class="fw-bold text-dark d-block fs-6">${f.nome_planilha}</span><span class="text-muted small fw-bold">${f.convenio || 'Sem Convênio'}</span></td>
                <td class="text-secondary fw-bold">${parseInt(f.quantidade_cpf).toLocaleString('pt-BR')}</td>
                <td>
                    <span class="badge bg-secondary mb-1 border border-dark">${tipo}</span><br>
                    <span class="small fw-bold text-success">R$ ${valor}</span>
                </td>
                <td>
                    <span class="badge border border-dark text-dark" style="background:#eee;">Tel: ${f.qualidade_telefones}</span><br>
                    <span class="badge border border-dark text-dark mt-1" style="background:#eee;">Fin: ${f.qualidade_finalidade}</span>
                </td>
                <td>
                    <button class="btn btn-sm btn-dark text-white fw-bold shadow-sm border-dark me-1" onclick="verAmostraPlanilha(${f.id}, '${f.nome_planilha.replace(/'/g, "\\'")}')"><i class="fas fa-eye"></i> VER (50)</button>
                    <a href="ajax_exportar_bruto.php?id=${f.id}" class="btn btn-sm btn-success fw-bold shadow-sm border-dark text-dark me-1" target="_blank"><i class="fas fa-download"></i> EXPORTAR</a>
                    
                    <button class="btn btn-sm btn-primary fw-bold shadow-sm border-dark me-1" onclick="abrirInfo(${f.id})"><i class="fas fa-info-circle"></i> INFO</button> 
                    <button class="btn btn-sm btn-warning fw-bold shadow-sm border-dark text-dark" onclick="abrirFicha(${f.id})"><i class="fas fa-edit"></i> EDITAR</button> 
                </td>
            `;
            tbody.appendChild(tr); count++;
        }
    });
    if(count === 0) tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-muted fw-bold fst-italic bg-light">Nenhuma planilha atende aos critérios do filtro atual.</td></tr>';
}

function verificarVazioExp(selectOperador) {
    const inputValor = selectOperador.closest('.linha-filtro-exp').querySelector('.input-valor-exp');
    if (selectOperador.value === 'vazio') { inputValor.value = ''; inputValor.setAttribute('readonly', 'readonly'); inputValor.setAttribute('placeholder', 'Não exige valor'); } else { inputValor.removeAttribute('readonly'); inputValor.setAttribute('placeholder', 'Valor do filtro...'); }
}

function adicionarFiltroExp() {
    const area = document.getElementById('areaFiltrosExp');
    const novaLinha = document.createElement('div');
    novaLinha.className = 'row g-2 mb-2 linha-filtro-exp align-items-center';
    novaLinha.innerHTML = `
        <div class="col-md-3"><select class="form-select border-dark shadow-sm select-campo-exp fw-bold">${opcoesFiltroPlanilha}</select></div>
        <div class="col-md-3">
            <select class="form-select border-dark shadow-sm select-operador-exp" onchange="verificarVazioExp(this)">
                <option value="contem">Contém</option><option value="nao_contem">Não contém</option>
                <option value="comeca">Começa com</option><option value="igual">Exatamente igual a</option>
                <option value="maior">Maior que (>)</option><option value="menor">Menor que (<)</option>
                <option value="entre">Entre (Ex: 1 a 10)</option><option value="vazio">É Vazio</option>
            </select>
        </div>
        <div class="col-md-5"><input type="text" class="form-control border-dark shadow-sm input-valor-exp" placeholder="Valor do filtro..."></div>
        <div class="col-md-1 text-center"><button type="button" class="btn btn-outline-danger border-dark btn-sm remover-linha-exp"><i class="fas fa-times"></i></button></div>
    `;
    area.appendChild(novaLinha);
}

document.getElementById('areaFiltrosExp').addEventListener('click', function(e) {
    if (e.target.closest('.remover-linha-exp')) {
        const area = document.getElementById('areaFiltrosExp');
        if (area.querySelectorAll('.linha-filtro-exp').length > 1) e.target.closest('.linha-filtro-exp').remove();
    }
});

function getRegrasAtuais() {
    let rules = [];
    document.querySelectorAll('.linha-filtro-exp').forEach(row => {
        let campo = row.querySelector('.select-campo-exp').value; let op = row.querySelector('.select-operador-exp').value; let val = row.querySelector('.input-valor-exp').value;
        if(val.trim() !== '' || op === 'vazio') rules.push({ campo: campo, operador: op, valor: val });
    });
    return rules;
}

function limparFiltroGeral() { document.getElementById('areaFiltrosExp').innerHTML = ''; adicionarFiltroExp(); loadFiles(); }

function filtrarCatologoPlanilhas() {
    const rules = getRegrasAtuais();
    if(rules.length === 0) { crmToast("Crie pelo menos uma regra para filtrar o catálogo!", "warning", 5000); return; }
    const btn = document.getElementById('btnExecutarFiltro'); btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Filtrando Catálogo...'; btn.disabled = true;
    const formData = new FormData(); formData.append('acao', 'buscar_planilhas_filtro'); formData.append('rules', JSON.stringify(rules));
    fetch('ajax_acervo.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
        btn.innerHTML = '<i class="fas fa-search me-1"></i> Filtrar Catálogo'; btn.disabled = false;
        if(data.sucesso) { globalFiles = data.planilhas; new bootstrap.Collapse(document.getElementById('painelBuscaAvancada'), {toggle: false}).hide(); filterList(); } else crmToast("❌ " + data.erro, "error", 6000);
    });
}

function verAmostraPlanilha(idPlanilha, nomePlanilha) {
    document.getElementById('titulo_amostra').innerText = nomePlanilha;
    document.getElementById('tblPreviewHead').innerHTML = ''; document.getElementById('tblPreviewBody').innerHTML = '<tr><td class="p-4 fw-bold"><i class="fas fa-spinner fa-spin me-2"></i> Lendo arquivo gigante, aguarde...</td></tr>';
    document.getElementById('btn_exportar_modal').href = `ajax_exportar_bruto.php?id=${idPlanilha}`;
    new bootstrap.Modal(document.getElementById('modalAmostra')).show();
    const formData = new FormData(); formData.append('acao', 'preview_planilha_bruta'); formData.append('id', idPlanilha);
    fetch('ajax_acervo.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => {
        if(data.sucesso) {
            let h = '<tr>'; data.cabecalhos.forEach(c => h += `<th>${c}</th>`); h += '</tr>'; document.getElementById('tblPreviewHead').innerHTML = h;
            let b = ''; data.linhas.forEach(linha => { b += '<tr class="bg-white border-bottom border-dark">'; linha.forEach(celula => b += `<td>${celula}</td>`); b += '</tr>'; });
            document.getElementById('tblPreviewBody').innerHTML = b;
        } else document.getElementById('tblPreviewBody').innerHTML = `<tr><td class="text-danger p-4 fw-bold">${data.erro}</td></tr>`;
    });
}

function abrirInfo(id) {
    let f = globalFiles.find(x => x.id == id);
    if(f) {
        document.getElementById('info_nome').innerText = f.nome_planilha;
        document.getElementById('info_convenio').innerText = f.convenio || '-';
        document.getElementById('info_tipo').innerText = f.tipo_lista || '-';
        document.getElementById('info_valor').innerText = f.valor_lista ? 'R$ ' + parseFloat(f.valor_lista).toLocaleString('pt-BR', {minimumFractionDigits: 2}) : 'R$ 0,00';
        document.getElementById('info_qtd').innerText = parseInt(f.quantidade_cpf).toLocaleString('pt-BR');
        document.getElementById('info_qual_tel').innerText = f.qualidade_telefones || '-';
        document.getElementById('info_qual_fin').innerText = f.qualidade_finalidade || '-';
        document.getElementById('info_data').innerText = f.data_importacao_br;
        
        let statusBadge = f.status_arquivo === 'Inativo' ? '<span class="badge bg-danger">Inativo</span>' : '<span class="badge bg-success">Ativo</span>';
        document.getElementById('info_status').innerHTML = statusBadge;
        
        document.getElementById('info_desc').innerHTML = f.descricao_planilha || '<span class="text-muted fst-italic">Sem descrição cadastrada.</span>';
        
        new bootstrap.Modal(document.getElementById('modalInfo')).show();
    }
}

function abrirFicha(id) {
    let f = globalFiles.find(x => x.id == id);
    if(f) {
        document.getElementById('edit_id').value = f.id; 
        document.getElementById('edit_nome').value = f.nome_planilha;
        document.getElementById('edit_convenio').value = f.convenio; 
        document.getElementById('edit_status').value = f.status_arquivo ? f.status_arquivo : 'Ativo';
        
        document.getElementById('edit_tipo_lista').value = f.tipo_lista ? f.tipo_lista : 'Cadastral';
        document.getElementById('edit_valor_lista').value = f.valor_lista ? f.valor_lista : '';
        
        // FORÇANDO MAIÚSCULO PARA GARANTIR COMPATIBILIDADE COM O <SELECT>
        document.getElementById('edit_qual_tel').value = f.qualidade_telefones ? f.qualidade_telefones.toUpperCase() : 'REGULAR';
        document.getElementById('edit_qual_fin').value = f.qualidade_finalidade ? f.qualidade_finalidade.toUpperCase() : 'REGULAR';

        document.getElementById('edit_qtd').value = parseInt(f.quantidade_cpf).toLocaleString('pt-BR');
        document.getElementById('edit_data').value = f.data_importacao_br; 
        
        quillFicha.root.innerHTML = f.descricao_planilha || '';
        
        new bootstrap.Modal(document.getElementById('modalFicha')).show();
    }
}

function salvarEdicao() {
    const formData = new FormData(); 
    formData.append('acao', 'editar'); 
    formData.append('id', document.getElementById('edit_id').value);
    formData.append('nome', document.getElementById('edit_nome').value); 
    formData.append('convenio', document.getElementById('edit_convenio').value);
    formData.append('status', document.getElementById('edit_status').value); 
    formData.append('tipo_lista', document.getElementById('edit_tipo_lista').value);
    formData.append('valor_lista', document.getElementById('edit_valor_lista').value);
    
    // ENVIANDO AS QUALIDADES EDITADAS PARA O BACKEND
    formData.append('qual_tel', document.getElementById('edit_qual_tel').value);
    formData.append('qual_fin', document.getElementById('edit_qual_fin').value);
    
    formData.append('desc', quillFicha.root.innerHTML);
    
    fetch('ajax_acervo.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => { 
        if(data.sucesso) { bootstrap.Modal.getInstance(document.getElementById('modalFicha')).hide(); loadFiles(); } else crmToast("❌ " + data.erro, "error", 6000); 
    });
}

function excluirPlanilha() {
    if(confirm('Tem certeza absoluta? Isso deletará o arquivo gigante do servidor.')) {
        const formData = new FormData(); formData.append('acao', 'excluir'); formData.append('id', document.getElementById('edit_id').value);
        fetch('ajax_acervo.php', { method: 'POST', body: formData }).then(res => res.json()).then(data => { if(data.sucesso) { bootstrap.Modal.getInstance(document.getElementById('modalFicha')).hide(); loadFiles(); } else crmToast("❌ " + data.erro, "error", 6000); });
    }
}

function enviarParaPreview() {
    let fileInput = document.getElementById('i_arquivo'); if(fileInput.files.length === 0) { crmToast("Selecione um arquivo!", "warning", 5000); return; }
    let btn = document.getElementById('btn_preview'); btn.innerHTML = '<i class="fas fa-spinner fa-spin text-info me-2"></i> Lendo arquivo...'; btn.disabled = true;
    let formData = new FormData(); formData.append('arquivo_csv', fileInput.files[0]);
    fetch('upload_cache_planilhas.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
        btn.innerHTML = 'Ler Arquivo <i class="fas fa-arrow-right ms-1"></i>'; btn.disabled = false;
        if(data.sucesso) {
            document.getElementById('cache_filename').value = data.arquivo_cache; document.getElementById('cache_headers').value = JSON.stringify(data.cabecalhos);
            let trH = '<tr>'; data.cabecalhos.forEach(c => trH += `<th>${c}</th>`); trH += '</tr>'; document.getElementById('preview_head').innerHTML = trH;
            let bH = ''; data.amostra.forEach(l => { bH += '<tr class="border-bottom border-dark bg-white">'; l.forEach(c => bH += `<td>${c}</td>`); bH += '</tr>'; });
            document.getElementById('preview_body').innerHTML = bH; document.getElementById('import-step-1').classList.add('hidden'); document.getElementById('import-step-2').classList.remove('hidden');
        } else crmToast("❌ " + data.erro, "error", 6000);
    });
}

function cancelarImportacao() { document.getElementById('import-step-2').classList.add('hidden'); document.getElementById('import-step-1').classList.remove('hidden'); document.getElementById('i_arquivo').value = ''; }

function salvarNoBanco() {
    let btn = document.getElementById('btn_salvar_bd'); btn.innerHTML = '<i class="fas fa-spinner fa-spin text-dark me-2"></i> Processando BD...'; btn.disabled = true;
    document.getElementById('i_desc').value = quillImport.root.innerHTML;
    let formData = new FormData(document.getElementById('formUp')); formData.append('arquivo_cache', document.getElementById('cache_filename').value); formData.append('cabecalhos', document.getElementById('cache_headers').value);
    fetch('salvar_planilha_bd.php', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
        if(data.sucesso) { crmToast("Planilha importada com sucesso!", "warning", 5000); document.getElementById('formUp').reset(); quillImport.setContents([]); cancelarImportacao(); switchTab('list'); } 
        else { crmToast("❌ " + data.erro, "error", 6000); btn.innerHTML = '<i class="fas fa-save me-1"></i> Confirmar e Salvar Planilha'; btn.disabled = false; }
    });
}
</script>

</div> <?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>