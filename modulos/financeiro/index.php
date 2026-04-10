<?php require_once '../../includes/header.php'; ?>

<style>
  :root { --primary-color: #f4511e; }
  body { background-color: #f4f6f9; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .table td { vertical-align: middle; font-size: 0.85rem; }
  .text-entrada { color: #0f5132; font-weight: bold; } .text-saida { color: #842029; font-weight: bold; }
  .text-transferencia { color: #0d6efd; font-weight: bold; }
  .bg-recebido { background-color: #d1e7dd; color: #0f5132; } .bg-receber { background-color: #cff4fc; color: #055160; }
  .bg-pago { background-color: #e2e3e5; color: #383d41; } .bg-pagar { background-color: #f8d7da; color: #842029; }
  .status-badge { font-size: 0.75rem; padding: 4px 8px; border-radius: 12px; font-weight: bold; text-transform: uppercase; cursor: pointer; border: 1px solid rgba(0,0,0,0.1); }
  .nav-tabs .nav-link { color: #495057; font-weight: bold; border: 1px solid transparent; }
  .nav-tabs .nav-link.active { color: var(--primary-color); border-bottom: 3px solid var(--primary-color); }
  .autocomplete-lista { display: none; position: absolute; z-index: 99999; width: 100%; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #343a40; border-top: none; }
  .autocomplete-lista li { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #eee; }
  .autocomplete-lista li:hover { background-color: #f4511e; color: white; }
  .nav-pills .nav-link { color: #343a40; font-weight: bold; border: 1px solid #343a40; margin-right: 10px; }
  .nav-pills .nav-link.active { background-color: #343a40; color: white !important; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-secondary fw-bold m-0"><i class="fas fa-wallet me-2"></i>Módulo Financeiro</h3>
    </div>

    <ul class="nav nav-tabs mb-4" role="tablist">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#caixa" type="button"><i class="fas fa-exchange-alt me-1"></i> Fluxo de Caixa</button></li>
      <li class="nav-item"><button class="nav-link text-primary fw-bold" data-bs-toggle="tab" data-bs-target="#conciliacao" type="button" onclick="carregarDespesas()"><i class="fas fa-check-double me-1"></i> Conciliação Bancária</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#planoContas" type="button" onclick="carregarListaPlanoContas()"><i class="fas fa-sitemap me-1"></i> Plano de Contas</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#contasBancarias" type="button" onclick="carregarContasBancarias()"><i class="fas fa-university me-1"></i> Contas Bancárias</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#entidades" type="button" onclick="carregarEntidades()"><i class="fas fa-building me-1"></i> Entidades</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#vendedores" type="button" onclick="carregarVendedores()"><i class="fas fa-user-tie me-1"></i> Vendedores</button></li>
    </ul>

    <div class="tab-content card-custom p-4">
      
      <div class="tab-pane fade show active" id="caixa">
         <div class="row g-2 align-items-end bg-light p-3 rounded border border-dark mb-4">
            <div class="col-md-5"><label class="small fw-bold">Buscar:</label><input type="text" id="buscaCaixa" class="form-control border-dark" placeholder="Pesquisar..." onkeyup="filtrarCaixa()"></div>
            <div class="col-md-4"><label class="small fw-bold">Status:</label><select id="filtroStatusCaixa" class="form-select border-dark" onchange="filtrarCaixa()"><option value="">Todos</option><option value="RECEBIDO">Recebidas (+)</option><option value="A RECEBER">A Receber (+)</option><option value="PAGO">Pagas (-)</option><option value="A PAGAR">A Pagar (-)</option></select></div>
            <div class="col-md-3 text-end"><button class="btn btn-success fw-bold border-dark w-100" onclick="abrirNovoLancamento()"><i class="fas fa-plus me-1"></i> Novo Lançamento</button></div>
         </div>
         <div class="table-responsive border border-dark rounded" style="min-height: 300px;">
            <table class="table table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Conta (Hierarquia)</th><th>Favorecido / Recebedor</th><th>Banco</th><th>Observação</th><th>Tipo</th><th>Valor (R$)</th><th>Venc.</th><th>Status</th><th class="text-center">Ações</th></tr></thead><tbody id="tbody-caixa"></tbody></table>
         </div>
      </div>

      <div class="tab-pane fade" id="conciliacao">
         <ul class="nav nav-pills mb-4" role="tablist">
            <li class="nav-item"><button class="nav-link active shadow-sm" data-bs-toggle="tab" data-bs-target="#tabDespesas" type="button" onclick="carregarDespesas()"><i class="fas fa-arrow-down text-danger me-1"></i> Despesas (Extrato PagBank)</button></li>
            <li class="nav-item"><button class="nav-link shadow-sm" data-bs-toggle="tab" data-bs-target="#tabReceitas" type="button" onclick="carregarReceitas()"><i class="fas fa-arrow-up text-success me-1"></i> Receitas (Pedidos Base)</button></li>
         </ul>

         <div class="tab-content">
            <div class="tab-pane fade show active" id="tabDespesas">
               <div class="row g-2 align-items-end bg-light p-3 rounded border border-dark mb-4">
                  <div class="col-md-9">
                      <h6 class="fw-bold text-dark m-0"><i class="fas fa-file-csv text-success me-2"></i> Conciliação de Despesas</h6>
                      <small class="text-muted">As saídas serão importadas para esta fila aguardando a sua conciliação.</small>
                  </div>
                  <div class="col-md-3 text-end">
                      <button class="btn btn-info fw-bold border-dark w-100 shadow-sm mb-2 text-dark" type="button" onclick="abrirModalExtratoPagBank()"><i class="fas fa-list-alt me-1"></i> Ver Extrato Completo</button>
                      <div class="d-flex gap-1 mb-2">
                          <input type="date" id="sincDataInicio" class="form-control form-control-sm border-dark" title="Data Início">
                          <input type="date" id="sincDataFim" class="form-control form-control-sm border-dark" title="Data Fim">
                      </div>
                      <button class="btn btn-primary fw-bold border-dark w-100 shadow-sm mb-2" id="btnSincApi" onclick="sincronizarPagBank()"><i class="fas fa-sync-alt me-1"></i> Sincronizar por Período</button>
                      <button class="btn btn-outline-secondary btn-sm fw-bold border-dark w-100 shadow-sm" onclick="document.getElementById('fileExtrato').click()"><i class="fas fa-file-csv me-1"></i> Subir CSV Manual</button>
                      <input type="file" id="fileExtrato" accept=".csv" style="display:none;" onchange="importarExtrato(this)">
                  </div>
               </div>
               <div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 250px;">
                  <table class="table table-hover mb-0 align-middle text-center">
                      <thead class="table-dark text-white border-dark"><tr><th>Data Origem</th><th class="text-start">Descrição Original (Extrato)</th><th>Cód Transação</th><th>Valor a Conciliar</th><th class="text-center" style="width: 120px;"><i class="fas fa-cog"></i> Ações</th></tr></thead>
                      <tbody id="tbody-despesas"><tr><td colspan="5" class="py-4">Aguardando...</td></tr></tbody>
                  </table>
               </div>
            </div>

            <div class="tab-pane fade" id="tabReceitas">
               <div class="alert alert-info fw-bold border-dark shadow-sm py-2 mb-4">
                   <i class="fas fa-info-circle me-2"></i> Abaixo estão listados os Pedidos lançados pelo Comercial que ainda não foram conciliados no Financeiro.
               </div>
               <div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 250px;">
                  <table class="table table-hover mb-0 align-middle text-center">
                      <thead class="table-dark text-white border-dark"><tr><th>Nº Pedido</th><th class="text-start">Cliente / Favorecido</th><th>Data do Pagamento (Baixa)</th><th>Valor Recebido</th><th class="text-center" style="width: 120px;"><i class="fas fa-cog"></i> Ações</th></tr></thead>
                      <tbody id="tbody-receitas"><tr><td colspan="5" class="py-4">Aguardando...</td></tr></tbody>
                  </table>
               </div>
            </div>
         </div>
      </div>

      <div class="tab-pane fade" id="planoContas"><div class="d-flex justify-content-between mb-3"><h5 class="fw-bold text-dark m-0 align-self-center"><i class="fas fa-sitemap text-primary me-2"></i>Árvore de Contas Cadastradas</h5><button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="abrirModalPlanoConta()"><i class="fas fa-plus me-1"></i> Criar Nova Conta</button></div><div class="table-responsive border border-dark rounded shadow-sm"><table class="table table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Tipo</th><th>Caminho Completo da Conta</th><th>Status</th><th class="text-center">Ações</th></tr></thead><tbody id="tbody-plano-contas"><tr><td colspan="4" class="text-center py-3">Carregando...</td></tr></tbody></table></div></div>
      <div class="tab-pane fade" id="contasBancarias">
        <div class="d-flex justify-content-between mb-3">
          <h5 class="fw-bold text-dark m-0 align-self-center"><i class="fas fa-university text-info me-2"></i>Contas de Movimentação (Bancos)</h5>
          <button class="btn btn-info text-dark fw-bold border-dark shadow-sm" onclick="abrirModalContaBancaria()"><i class="fas fa-plus me-1"></i> Adicionar Banco</button>
        </div>
        <div class="table-responsive border border-dark rounded shadow-sm mb-4">
          <table class="table table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Nome da Conta (Apelido)</th><th>Dados Bancários (Ag/CC/Pix)</th><th class="text-center">Ações</th></tr></thead><tbody id="tbody-contas-bancarias"><tr><td colspan="3" class="text-center py-3">Carregando...</td></tr></tbody></table>
        </div>

        <!-- CONFIGURAÇÃO PAGBANK -->
        <div class="card border-dark shadow-sm">
          <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span class="fw-bold"><i class="fas fa-key me-2 text-warning"></i> Configuração PagBank — Token de Acesso à API</span>
            <span id="pagbank_atualizado" class="small text-muted"></span>
          </div>
          <div class="card-body bg-light">
            <p class="small text-muted mb-3">Gere seu token em <b>PagBank → Extrato → API → Tokens de acesso</b> e cole abaixo. O sistema usará este token para sincronizar o extrato automaticamente.</p>
            <div class="input-group">
              <input type="text" id="inputPagbankToken" class="form-control border-dark fw-bold font-monospace" placeholder="Cole aqui o token do PagBank...">
              <button class="btn btn-warning fw-bold text-dark border-dark" onclick="salvarTokenPagBank()"><i class="fas fa-save me-1"></i> Salvar Token</button>
            </div>
            <div id="pagbank_msg" class="mt-2"></div>
          </div>
        </div>
      </div>
      <div class="tab-pane fade" id="entidades"><div class="d-flex justify-content-between mb-3"><h5 class="fw-bold text-dark m-0 align-self-center">Gerenciamento de Entidades</h5><button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="abrirModalVinculo('ENTIDADE')"><i class="fas fa-link me-1"></i> Vincular Nova Entidade</button></div><div class="table-responsive border border-dark rounded"><table class="table table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Nome / Razão Social</th><th>Documento</th><th>Tipo Base</th><th>Status</th><th class="text-center">Ações</th></tr></thead><tbody id="tbody-entidades"></tbody></table></div></div>
      <div class="tab-pane fade" id="vendedores"><div class="d-flex justify-content-between mb-3"><h5 class="fw-bold text-dark m-0 align-self-center">Gerenciamento de Vendedores</h5><button class="btn btn-warning text-dark fw-bold border-dark shadow-sm" onclick="abrirModalVinculo('VENDEDOR')"><i class="fas fa-link me-1"></i> Vincular Novo Vendedor</button></div><div class="table-responsive border border-dark rounded"><table class="table table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Nome / Razão Social</th><th>Documento</th><th>Tipo Base</th><th class="text-center">Indicados</th><th>Link de Indicação</th><th>Status</th><th class="text-center">Ações</th></tr></thead><tbody id="tbody-vendedores"></tbody></table></div></div>
    </div>
</div>

<div class="modal fade" id="mVisLancamento"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-eye me-2"></i>Detalhes do Lançamento</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
    <table class="table table-bordered border-dark mb-0">
        <tr><th class="bg-light" style="width: 35%;">Hierarquia:</th><td id="visLDesc" class="fw-bold text-primary"></td></tr>
        <tr><th class="bg-light">Favorecido:</th><td id="visLFav" class="fw-bold"></td></tr>
        <tr><th class="bg-light">Banco:</th><td id="visLBanco" class="fw-bold text-dark"></td></tr>
        <tr><th class="bg-light">Tipo:</th><td id="visLTipo" class="fw-bold"></td></tr>
        <tr><th class="bg-light">Valor:</th><td id="visLValor" class="fw-bold fs-5 text-success"></td></tr>
        <tr><th class="bg-light">Vencimento:</th><td id="visLVenc"></td></tr>
        <tr><th class="bg-light">Data Pago:</th><td id="visLPag"></td></tr>
        <tr><th class="bg-light">Status:</th><td id="visLStatus" class="fw-bold"></td></tr>
        <tr><th class="bg-light">Observação:</th><td id="visLObs"></td></tr>
    </table>
</div><div class="modal-footer bg-light border-top border-dark justify-content-center"><button class="btn btn-dark fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Fechar</button></div></div></div></div>

<div class="modal fade" id="mNovoLancamento"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-success text-white border-dark"><h5 class="modal-title fw-bold text-uppercase" id="titModalLancamento"><i class="fas fa-file-invoice-dollar me-2"></i>Registrar Lançamento</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light">
   <form id="fNovoLancamento">
      <input type="hidden" id="lLancamentoId">
      <div class="row mb-3">
          <div class="col-6"><label class="small fw-bold">Movimento:</label>
              <select id="lTipo" class="form-select border-dark fw-bold" onchange="ajustarTipoLancamento();">
                  <option value="">-- Selecione --</option>
                  <option value="ENTRADA">Entrada / Receita (+)</option>
                  <option value="SAIDA">Saída / Despesa (-)</option>
                  <option value="TRANSFERENCIA" class="text-primary fw-bold">Transferência entre Contas</option>
              </select>
          </div>
          <div class="col-6" id="boxStatus"><label class="small fw-bold">Status do Pgto:</label>
              <select id="lCat" class="form-select border-dark bg-white"><option value="">-- Escolha Movimento --</option></select>
          </div>
      </div>
      
      <div class="p-3 border border-dark rounded mb-3 bg-white shadow-sm" id="boxOrigem" style="display:none;">
          <h6 class="fw-bold text-dark mb-3 border-bottom pb-2" id="titleOrigem"><i class="fas fa-arrow-circle-up text-danger"></i> Dados da Conta</h6>
          <div class="mb-3">
              <label class="small fw-bold text-primary"><i class="fas fa-university me-1"></i> Banco / Conta de Movimentação:</label>
              <select id="lContaBanco" class="form-select border-dark fw-bold border-primary shadow-sm select-bancos" required></select>
          </div>
          <div id="container_cascata_origem" class="row g-2 mb-2"></div>
      </div>

      <div class="p-3 border border-primary rounded mb-3 shadow-sm" style="background-color: #eef7ff; display: none;" id="boxDestino">
          <h6 class="fw-bold text-primary mb-3 border-bottom border-primary pb-2"><i class="fas fa-arrow-circle-down"></i> Dados da Entrada (Destino do Dinheiro)</h6>
          <div class="mb-3">
              <label class="small fw-bold text-primary"><i class="fas fa-university me-1"></i> Banco / Conta de Destino:</label>
              <select id="lContaDestino" class="form-select border-primary fw-bold shadow-sm select-bancos"></select>
          </div>
          <div id="container_cascata_destino" class="row g-2 mb-2"></div>
      </div>

      <div class="mb-3 position-relative" id="boxFavorecido">
          <label class="small fw-bold text-dark"><i class="fas fa-user-tag text-info me-1"></i> Favorecido / Cliente pagador (Busca Dinâmica)</label>
          <div class="row g-2 mb-1">
              <div class="col-md-4">
                  <select id="lTipoFavorecidoBusca" class="form-select border-dark fw-bold text-primary" onchange="limparBuscaFavLancamento()">
                      <option value="ENTIDADE">Entidade Cadastrada</option>
                      <option value="VENDEDOR">Vendedor Parceiro</option>
                      <option value="CLIENTE">Cliente (Base CRM)</option>
                  </select>
              </div>
              <div class="col-md-8 position-relative">
                  <input type="hidden" id="lFavorecidoId">
                  <input type="text" id="lFavorecidoText" class="form-control border-dark fw-bold text-uppercase" placeholder="Digite para buscar..." autocomplete="off" onkeyup="buscarFavorecidoLancamento(this.value)">
                  <ul id="listaFavorecidosLan" class="list-unstyled autocomplete-lista shadow-lg rounded-bottom" style="width: 100%;"></ul>
              </div>
          </div>
      </div>

      <div class="mb-3"><label class="small fw-bold">Detalhes / Observação Livre:</label><input type="text" id="lObs" class="form-control border-dark text-uppercase" placeholder="Ex: Pagamento referente ao Mês de Agosto"></div>
      
      <div class="row mb-3">
          <div class="col-md-6"><label class="small fw-bold text-secondary">Cód Transação / Banco:</label><input type="text" id="lCodBanco" class="form-control border-dark" placeholder="Opcional"></div>
          <div class="col-md-6"><label class="small fw-bold text-primary">Data de Conciliação:</label><input type="date" id="lDataConciliacao" class="form-control border-dark" required></div>
      </div>

      <div class="row mb-4">
          <div class="col-4"><label class="small fw-bold">Valor (R$):</label><input type="number" step="0.01" id="lValor" class="form-control border-dark fw-bold text-primary" required></div>
          <div class="col-4"><label class="small fw-bold">Vencimento:</label><input type="date" id="lVenc" class="form-control border-dark" required></div>
          <div class="col-4"><label class="small fw-bold">Data Pago/Recebido:</label><input type="date" id="lPag" class="form-control border-dark"></div>
      </div>
      <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm fs-5"><i class="fas fa-save me-1"></i> Salvar Lançamento</button>
   </form>
</div></div></div></div>

<div class="modal fade" id="mConciliar"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg">
    <div class="modal-header bg-dark text-white border-dark">
        <h5 class="modal-title fw-bold text-uppercase" id="titModalConc">Processar Conciliação</h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body bg-light p-4">
        <div class="alert alert-warning py-2 mb-4 fw-bold border-dark text-center shadow-sm" id="lblInfoOrigem"></div>
        <form id="fConciliar">
            <input type="hidden" id="cTipoFluxo"> 
            <input type="hidden" id="cIdOrigem">
            
            <div class="row mb-3">
                <div class="col-md-4">
                    <label class="small fw-bold text-primary"><i class="fas fa-university me-1"></i> Conta de Destino:</label>
                    <select id="cContaBanco" class="form-select border-dark fw-bold border-primary shadow-sm select-bancos" required></select>
                </div>
                <div class="col-md-4">
                    <label class="small fw-bold text-dark">Status:</label>
                    <select id="cCategoria" class="form-select border-dark fw-bold" required></select>
                </div>
                <div class="col-md-4">
                    <label class="small fw-bold text-danger">Data de Conciliação:</label>
                    <input type="date" id="cDataConciliacao" class="form-control border-dark fw-bold text-dark" required>
                </div>
            </div>

            <div class="p-3 border border-dark rounded mb-3 bg-white shadow-sm">
                <h6 class="fw-bold text-dark mb-3 border-bottom pb-2"><i class="fas fa-sitemap text-primary"></i> Classificação no Plano de Contas</h6>
                <div id="container_cascata_conciliacao" class="row g-2 mb-2"></div>
            </div>

            <div class="mb-3 position-relative">
                <label class="small fw-bold text-dark"><i class="fas fa-user-tag text-info me-1"></i> Favorecido / Cliente (Busca Dinâmica)</label>
                <div class="row g-2 mb-1">
                    <div class="col-md-4">
                        <select id="cTipoBuscaFav" class="form-select border-dark fw-bold text-primary" onchange="limparBuscaFavConciliacao()">
                            <option value="ENTIDADE">Entidade Cadastrada</option>
                            <option value="VENDEDOR">Vendedor Parceiro</option>
                            <option value="CLIENTE">Cliente (Base CRM)</option>
                        </select>
                    </div>
                    <div class="col-md-8 position-relative">
                        <input type="hidden" id="cFavorecidoId">
                        <input type="text" id="cFavorecidoText" class="form-control border-dark fw-bold text-uppercase" placeholder="Digite para buscar..." autocomplete="off" onkeyup="buscarFavorecidoConciliacao(this.value)">
                        <ul id="listaFavorecidosConc" class="list-unstyled autocomplete-lista shadow-lg rounded-bottom" style="width: 100%;"></ul>
                    </div>
                </div>
            </div>

            <div class="mb-4">
                <label class="small fw-bold text-dark">Observação Complementar:</label>
                <input type="text" id="cObs" class="form-control border-dark text-uppercase">
            </div>
            <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm fs-5"><i class="fas fa-check-double me-2"></i> Confirmar Conciliação</button>
        </form>
    </div>
</div></div></div>

<div class="modal fade" id="mVisPlanoConta"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-eye me-2"></i>Detalhes do Plano de Conta</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><table class="table table-bordered border-dark mb-0"><tr><th class="bg-light" style="width: 35%;">Tipo:</th><td id="visPcTipo" class="fw-bold"></td></tr><tr><th class="bg-light">Caminho:</th><td id="visPcCaminho" class="fw-bold text-primary"></td></tr><tr><th class="bg-light">Status:</th><td id="visPcStatus"></td></tr></table></div><div class="modal-footer bg-light border-top border-dark justify-content-center" id="visPcFooter"></div></div></div></div>
<div class="modal fade" id="mContaBancaria"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-info text-dark border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-university me-2"></i>Conta de Movimentação</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="fContaBancaria"><input type="hidden" id="cbId"><div class="mb-3"><label class="small fw-bold">Apelido da Conta:</label><input type="text" id="cbNome" class="form-control border-dark text-uppercase fw-bold" required></div><div class="mb-4"><label class="small fw-bold">Dados Bancários / PIX:</label><textarea id="cbDados" class="form-control border-dark" rows="3" required></textarea></div><button type="submit" class="btn btn-info text-dark w-100 fw-bold border-dark shadow-sm">Salvar</button></form></div></div></div></div>
<div class="modal fade" id="mPlanoConta"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-sitemap me-2"></i>Conta Hierárquica</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="fPlanoConta"><input type="hidden" id="pcId"><div class="mb-3"><label class="small fw-bold">Tipo da Conta:</label><select id="pcTipo" class="form-select border-dark fw-bold" required onchange="carregarPaisSelect()"><option value="">-- Selecione --</option><option value="ENTRADA">ENTRADA (Receitas)</option><option value="SAIDA">SAÍDA (Despesas)</option></select></div><div class="mb-3"><label class="small fw-bold">Selecione a Conta PAI:</label><select id="pcParent" class="form-select border-dark"><option value="">-- É UMA CONTA PRINCIPAL RAIZ --</option></select></div><div class="mb-4"><label class="small fw-bold text-primary">Nome Específico:</label><input type="text" id="pcNome" class="form-control border-dark text-uppercase fw-bold" required></div><button type="submit" class="btn btn-primary w-100 fw-bold border-dark shadow-sm">Gravar no Plano</button></form></div></div></div></div>
<div class="modal fade" id="mVinculo"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase" id="titVinculo">Vincular Cadastro</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="fVinculo"><input type="hidden" id="vDestino"><input type="hidden" id="vId"><div class="mb-3 position-relative"><label class="fw-bold small text-primary"><i class="fas fa-search"></i> Buscar Nome, CPF ou CNPJ:</label><input type="text" id="vBusca" class="form-control border-dark fw-bold text-uppercase" autocomplete="off" onkeyup="buscarBase(this.value)" required><ul id="listaBase" class="list-unstyled autocomplete-lista shadow-lg rounded-bottom"></ul></div><div class="row mb-4"><div class="col-md-8"><label class="fw-bold small">Nome Selecionado:</label><input type="text" id="vNome" class="form-control border-dark bg-white" readonly required></div><div class="col-md-4"><label class="fw-bold small">Tipo / Base:</label><input type="text" id="vTipo" class="form-control border-dark bg-white fw-bold text-secondary" readonly></div><input type="hidden" id="vDoc"></div><button type="submit" class="btn btn-primary w-100 fw-bold border-dark shadow-sm"><i class="fas fa-save me-1"></i> Salvar Vínculo</button></form></div></div></div></div>
<div class="modal fade" id="mComissaoVendedor"><div class="modal-dialog modal-xl"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-money-check-alt me-2"></i>Tabelas e Comissões Liberadas</h5><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><h5 class="fw-bold text-dark border-bottom border-dark pb-2 mb-3" id="nomeVendedorTabela">Vendedor: </h5><form id="fComissaoVend" class="row g-2 align-items-end mb-4 bg-white p-3 border border-dark rounded shadow-sm"><input type="hidden" id="cVendId"><div class="col-md-9"><label class="small fw-bold text-primary">Selecione o Produto e Variação que este vendedor pode vender:</label><select id="cVariacaoId" class="form-select border-dark fw-bold" required></select></div><div class="col-md-3"><button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm"><i class="fas fa-check-circle me-1"></i> Liberar Venda</button></div></form><div class="table-responsive border border-dark rounded shadow-sm"><table class="table table-sm table-hover mb-0 align-middle"><thead class="table-dark text-white border-dark"><tr><th>Produto Base</th><th>Nome da Variação (Preço)</th><th>Comissão (Regra)</th><th class="text-center">Opção do Vendedor</th><th class="text-center">Ações</th></tr></thead><tbody id="tbodyComVend"><tr><td colspan="5" class="text-center py-4 text-muted">Carregando tabelas...</td></tr></tbody></table></div><div class="alert alert-info border-info mt-3 py-2 small fw-bold"><i class="fas fa-info-circle me-2"></i> <b>COMISSÃO</b>: valor pago ao vendedor após o pedido. &nbsp;|&nbsp; <b>DESCONTO</b>: desconto automático aplicado no pedido, no valor da comissão.</div></div></div></div></div>

<div class="modal fade" id="mIndicados" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-primary text-white border-dark">
        <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-users me-2"></i>Clientes Indicados — <span id="nomeVendedorIndicados"></span></h5>
        <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 align-middle">
            <thead class="table-dark text-white border-dark sticky-top">
              <tr><th>Nome</th><th>CPF</th><th>Celular</th><th>Empresa</th><th>Situação</th><th>Cadastro</th></tr>
            </thead>
            <tbody id="tbodyIndicados"><tr><td colspan="6" class="text-center py-4 text-muted">Carregando...</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer bg-light border-top border-dark"><button class="btn btn-dark fw-bold border-dark" data-bs-dismiss="modal">Fechar</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="mExtratoPagBank" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-info text-dark border-bottom border-dark">
        <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-list-alt me-2"></i>Consulta de Extrato PagBank</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
        <div class="row g-2 mb-3 align-items-end">
           <div class="col-md-4">
              <label class="small fw-bold">Data Início:</label>
              <input type="date" id="extDataIni" class="form-control border-dark" value="<?php echo date('Y-m-01'); ?>">
           </div>
           <div class="col-md-4">
              <label class="small fw-bold">Data Fim:</label>
              <input type="date" id="extDataFim" class="form-control border-dark" value="<?php echo date('Y-m-t'); ?>">
           </div>
           <div class="col-md-4">
              <button class="btn btn-dark w-100 fw-bold border-dark shadow-sm" onclick="buscarExtratoPagBankAPI()"><i class="fas fa-search me-1"></i> Buscar Extrato</button>
           </div>
        </div>
        <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 400px; overflow-y: auto;">
           <table class="table table-sm table-hover mb-0 align-middle text-center" style="font-size: 0.85rem;">
               <thead class="table-dark text-white border-dark sticky-top">
                   <tr>
                       <th>Data / Hora</th>
                       <th class="text-start">Descrição</th>
                       <th>ID Transação</th>
                       <th>Tipo</th>
                       <th>Valor (R$)</th>
                   </tr>
               </thead>
               <tbody id="tbody-extrato-pagbank">
                   <tr><td colspan="5" class="py-4 text-muted fw-bold">Escolha as datas e clique em Buscar.</td></tr>
               </tbody>
           </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  let modais = {}; let delayTimer; window.listaContasCache = []; window.caixaList = [];

  document.addEventListener("DOMContentLoaded", () => {
      modais = {
          lancamento: new bootstrap.Modal(document.getElementById('mNovoLancamento')),
          visLancamento: new bootstrap.Modal(document.getElementById('mVisLancamento')),
          conciliar: new bootstrap.Modal(document.getElementById('mConciliar')),
          vinculo: new bootstrap.Modal(document.getElementById('mVinculo')),
          comissaoVend: new bootstrap.Modal(document.getElementById('mComissaoVendedor')),
          plano: new bootstrap.Modal(document.getElementById('mPlanoConta')),
          banco: new bootstrap.Modal(document.getElementById('mContaBancaria')),
          visPlano: new bootstrap.Modal(document.getElementById('mVisPlanoConta')),
          extratoPagbank: new bootstrap.Modal(document.getElementById('mExtratoPagBank')),
          indicados: new bootstrap.Modal(document.getElementById('mIndicados'))
      };
      carregarCaixa();
  });

  async function callFinanceiro(acao, dados = {}) {
      try { const fd = new FormData(); fd.append('acao', acao); for (const k in dados) fd.append(k, dados[k]);
          const res = await fetch('financeiro.ajax.php', { method: 'POST', body: fd }); return await res.json();
      } catch(e) { return { success: false, msg: "Erro Backend Financeiro" }; }
  }

  async function callConciliacao(acao, dados = {}) {
      try { const fd = new FormData(); fd.append('acao', acao); for(let k in dados) fd.append(k, dados[k]);
          const res = await fetch('conciliacao.ajax.php', { method: 'POST', body: fd }); return await res.json();
      } catch(e) { return { success: false, msg: "Erro Backend Conciliação" }; }
  }

  // ===============================================
  // ABA: CONCILIAÇÃO BANCÁRIA
  // ===============================================
  async function importarExtrato(input) {
      if(!input.files[0]) return;
      const fd = new FormData(); fd.append('acao', 'importar_extrato_pagbank'); fd.append('arquivo_csv', input.files[0]);
      const res = await fetch('conciliacao.ajax.php', { method: 'POST', body: fd });
      const j = await res.json(); alert(j.msg); input.value = ""; carregarDespesas();
  }

  async function carregarDespesas() {
      const tb = document.getElementById('tbody-despesas'); tb.innerHTML = '<tr><td colspan="5" class="py-4 text-center"><i class="fas fa-spinner fa-spin"></i> Buscando...</td></tr>';
      const r = await callConciliacao('listar_despesas_pendentes');
      if(r.success) {
          tb.innerHTML = ''; if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold text-center">Nenhuma despesa pendente de conciliação.</td></tr>'; return; }
          r.data.forEach(x => {
              tb.innerHTML += `<tr class="border-bottom border-dark">
                  <td class="fw-bold text-dark">${x.DATA_PAG_BR || x.DATA_VENC_BR}</td>
                  <td class="text-start text-secondary" style="font-size:12px;">${x.OBSERVACAO || x.DESCRICAO}</td>
                  <td><code class="text-dark bg-light border border-secondary px-2 py-1 rounded">${x.CODIGO_BANCO || 'N/A'}</code></td>
                  <td class="text-danger fw-bold">R$ ${parseFloat(x.VALOR_PAGO).toFixed(2).replace('.',',')}</td>
                  <td class="text-center">
                      <div class="dropdown">
                          <button class="btn btn-sm btn-dark shadow-sm dropdown-toggle fw-bold border-dark" type="button" data-bs-toggle="dropdown"><i class="fas fa-pencil-alt"></i></button>
                          <ul class="dropdown-menu shadow-lg border-dark dropdown-menu-end">
                              <li><a class="dropdown-item fw-bold text-success" href="#" onclick="abrirConciliacao('DESPESA', ${x.ID}, '${x.OBSERVACAO || x.DESCRICAO}', ${x.VALOR_PAGO}); return false;"><i class="fas fa-check-double me-2"></i>Conciliar Despesa</a></li>
                              <li><hr class="dropdown-divider border-dark"></li>
                              <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="if(confirm('Excluir permanentemente este lançamento da fila?')) callConciliacao('excluir_pendencia',{tipo_fluxo:'DESPESA', id_origem:${x.ID}}).then(carregarDespesas); return false;"><i class="fas fa-trash me-2"></i>Excluir Item</a></li>
                          </ul>
                      </div>
                  </td>
              </tr>`;
          });
      }
  }

  async function carregarReceitas() {
      const tb = document.getElementById('tbody-receitas'); tb.innerHTML = '<tr><td colspan="5" class="py-4 text-center"><i class="fas fa-spinner fa-spin"></i> Buscando...</td></tr>';
      const r = await callConciliacao('listar_receitas_pendentes');
      if(r.success) {
          tb.innerHTML = ''; if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold text-center">Nenhum pedido pendente de conciliação.</td></tr>'; return; }
          r.data.forEach(x => {
              let nomeEnt = x.NOME_ENTIDADE || 'Não Informado';
              tb.innerHTML += `<tr class="border-bottom border-dark">
                  <td class="fw-bold fs-6">#${x.PEDIDO_ID}</td>
                  <td class="text-start fw-bold text-primary">${nomeEnt}</td>
                  <td class="fw-bold">${x.DATA_PAG_BR || '--'}</td>
                  <td class="text-success fw-bold fs-6">R$ ${parseFloat(x.VALOR_RECEBIDO).toFixed(2).replace('.',',')}</td>
                  <td class="text-center">
                      <div class="dropdown">
                          <button class="btn btn-sm btn-dark shadow-sm dropdown-toggle fw-bold border-dark" type="button" data-bs-toggle="dropdown"><i class="fas fa-pencil-alt"></i></button>
                          <ul class="dropdown-menu shadow-lg border-dark dropdown-menu-end">
                              <li><a class="dropdown-item fw-bold text-success" href="#" onclick="abrirConciliacao('RECEITA', ${x.ID}, 'PEDIDO #${x.PEDIDO_ID} - ${nomeEnt}', ${x.VALOR_RECEBIDO}); return false;"><i class="fas fa-check-double me-2"></i>Conciliar Entrada</a></li>
                              <li><hr class="dropdown-divider border-dark"></li>
                              <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="if(confirm('Excluir permanentemente este lançamento da fila?')) callConciliacao('excluir_pendencia',{tipo_fluxo:'RECEITA', id_origem:${x.ID}}).then(carregarReceitas); return false;"><i class="fas fa-trash me-2"></i>Excluir Item</a></li>
                          </ul>
                      </div>
                  </td>
              </tr>`;
          });
      }
  }

  function abrirConciliacao(tipo, id_origem, descricao_original, valor) {
      document.getElementById('fConciliar').reset(); document.getElementById('cTipoFluxo').value = tipo; document.getElementById('cIdOrigem').value = id_origem; document.getElementById('cFavorecidoId').value = '';
      let lblInfo = document.getElementById('lblInfoOrigem'); let selStatus = document.getElementById('cCategoria');
      document.getElementById('cDataConciliacao').value = new Date().toISOString().split('T')[0];

      if (tipo === 'DESPESA') {
          lblInfo.className = 'alert alert-danger py-2 mb-4 fw-bold border-dark text-center shadow-sm';
          lblInfo.innerHTML = `<i class="fas fa-arrow-down"></i> Conciliando Saída | Valor: <b class="fs-5">R$ ${parseFloat(valor).toFixed(2).replace('.',',')}</b>`;
          selStatus.innerHTML = '<option value="PAGO">Já Pago (Baixado)</option><option value="A PAGAR">A Pagar (Agendado)</option>';
          iniciarCascata('SAIDA', '_conciliacao');
      } else {
          lblInfo.className = 'alert alert-success py-2 mb-4 fw-bold border-dark text-center shadow-sm';
          lblInfo.innerHTML = `<i class="fas fa-arrow-up"></i> Conciliando Receita de Pedido | Valor: <b class="fs-5">R$ ${parseFloat(valor).toFixed(2).replace('.',',')}</b>`;
          selStatus.innerHTML = '<option value="RECEBIDO">Já Recebido (Baixado)</option><option value="A RECEBER">A Receber (Fiado/Boleto)</option>';
          iniciarCascata('ENTRADA', '_conciliacao');
      }
      carregarBancosNoSelect(); modais.conciliar.show();
  }

  document.getElementById('fConciliar').addEventListener('submit', async e => {
      e.preventDefault();
      const selectsCascata = document.querySelectorAll('.select-conta-cascata_conciliacao'); let caminhoSelecionado = '';
      for (let i = selectsCascata.length - 1; i >= 0; i--) { if (selectsCascata[i].value !== "") { caminhoSelecionado = selectsCascata[i].options[selectsCascata[i].selectedIndex].getAttribute('data-caminho'); break; } }
      if(caminhoSelecionado === '') { alert("Classifique este item em alguma categoria do Plano de Contas!"); return; }

      const f = {
          tipo_fluxo: document.getElementById('cTipoFluxo').value, 
          id_origem: document.getElementById('cIdOrigem').value, 
          descricao: caminhoSelecionado,
          conta_id: document.getElementById('cContaBanco').value, 
          categoria: document.getElementById('cCategoria').value,
          favorecido_id: document.getElementById('cFavorecidoId').value, 
          obs: document.getElementById('cObs').value,
          data_conciliacao: document.getElementById('cDataConciliacao').value
      };

      const r = await callConciliacao('conciliar_registro', f);
      if(r.success) { modais.conciliar.hide(); if (f.tipo_fluxo === 'DESPESA') carregarDespesas(); else carregarReceitas(); carregarCaixa(); } else { alert(r.msg); }
  });

  // ===============================================
  // MOTORES COMPARTILHADOS
  // ===============================================
  async function carregarBancosNoSelect() {
      const selects = document.querySelectorAll('.select-bancos');
      selects.forEach(sel => sel.innerHTML = '<option value="">-- Lendo Bancos... --</option>');
      const r = await callFinanceiro('listar_contas_bancarias');
      if(r.success) { selects.forEach(sel => { sel.innerHTML = '<option value="">-- Selecione o Banco --</option>'; r.data.forEach(x => { sel.innerHTML += `<option value="${x.ID}">${x.NOME_CONTA}</option>`; }); }); }
  }

  async function iniciarCascata(tipo, prefixo_container) {
      const container = document.getElementById('container_cascata' + prefixo_container); container.style.display = 'flex';
      container.innerHTML = '<div class="col-12 text-center text-primary fw-bold py-2"><i class="fas fa-spinner fa-spin"></i> Lendo estrutura de pastas...</div>';
      await carregarNivelCascata(tipo, null, 1, prefixo_container);
  }

  async function carregarNivelCascata(tipo, parentId, nivel, prefixo_container) {
      const container = document.getElementById('container_cascata' + prefixo_container); const caixas = container.querySelectorAll('.box-cascata');
      caixas.forEach(bx => { if (parseInt(bx.dataset.nivel) >= nivel) bx.remove(); }); if(nivel === 1) container.innerHTML = '';
      const r = await callFinanceiro('buscar_hierarquia', { tipo: tipo, parent_id: parentId || '' });
      if(r.success && r.data.length > 0) {
          let badgeLvl = nivel === 1 ? 'bg-dark' : 'bg-secondary'; let label = nivel === 1 ? 'Categoria Principal' : 'Subcategoria Nível ' + nivel;
          let html = `<div class="col-12 box-cascata mt-2" data-nivel="${nivel}"><label class="small fw-bold text-dark"><span class="badge ${badgeLvl} me-1">${nivel}</span> ${label}:</label><select class="form-select border-dark select-conta-cascata${prefixo_container} fw-bold text-primary shadow-sm" data-nivel="${nivel}" onchange="mudouSelectCascata(this, '${tipo}', '${prefixo_container}')" required><option value="">-- Expandir Opções --</option>`;
          r.data.forEach(c => { html += `<option value="${c.ID}" data-caminho="${c.CAMINHO_HIERARQUIA}">${c.NOME_CONTA}</option>`; });
          html += `</select></div>`; container.insertAdjacentHTML('beforeend', html);
      }
  }

  function mudouSelectCascata(selectDom, tipo, prefixo_container) {
      const valorSel = selectDom.value; const nivelSel = parseInt(selectDom.dataset.nivel);
      if(valorSel !== "") { carregarNivelCascata(tipo, valorSel, nivelSel + 1, prefixo_container); } else { carregarNivelCascata(tipo, null, nivelSel + 1, prefixo_container); }
  }

  let timerFavLan;
  function limparBuscaFavLancamento() { document.getElementById('lFavorecidoText').value = ''; document.getElementById('lFavorecidoId').value = ''; document.getElementById('listaFavorecidosLan').style.display = 'none'; document.getElementById('lFavorecidoText').focus(); }
  async function buscarFavorecidoLancamento(termo) {
      clearTimeout(timerFavLan); let ul = document.getElementById('listaFavorecidosLan'); let tipoBusca = document.getElementById('lTipoFavorecidoBusca').value; 
      if (termo.length < 3) { ul.style.display = 'none'; return; }
      timerFavLan = setTimeout(async () => {
          const r = await callFinanceiro('buscar_favorecidos_dinamico', { termo: termo, tipo_busca: tipoBusca });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = `<li><strong>-- Não se aplica / Limpar --</strong></li>`; ul.firstChild.onclick = () => limparBuscaFavLancamento();
              r.data.forEach(v => {
                  let badge = v.TIPO === 'CLIENTENOVO' ? '<span class="badge bg-primary">CLIENTE</span>' : (v.TIPO === 'VENDEDOR' ? '<span class="badge bg-warning text-dark">VENDEDOR</span>' : '<span class="badge bg-info text-dark">ENTIDADE</span>');
                  let li = document.createElement('li'); li.innerHTML = `<strong>${v.NOME}</strong> ${badge}<br><small class="text-muted">Doc: ${v.DOC}</small>`;
                  li.onclick = () => { document.getElementById('lFavorecidoText').value = v.NOME; document.getElementById('lFavorecidoId').value = `${v.TIPO}_${v.ID}`; ul.style.display = 'none'; }; ul.appendChild(li);
              }); ul.style.display = 'block';
          } else { ul.style.display = 'none'; }
      }, 400);
  }

  let timerFavConc;
  function limparBuscaFavConciliacao() { document.getElementById('cFavorecidoText').value = ''; document.getElementById('cFavorecidoId').value = ''; document.getElementById('listaFavorecidosConc').style.display = 'none'; document.getElementById('cFavorecidoText').focus(); }
  async function buscarFavorecidoConciliacao(termo) {
      clearTimeout(timerFavConc); let ul = document.getElementById('listaFavorecidosConc'); let tipoBusca = document.getElementById('cTipoBuscaFav').value; 
      if (termo.length < 3) { ul.style.display = 'none'; return; }
      timerFavConc = setTimeout(async () => {
          const r = await callFinanceiro('buscar_favorecidos_dinamico', { termo: termo, tipo_busca: tipoBusca });
          if (r.success && r.data.length > 0) {
              ul.innerHTML = `<li><strong>-- Não se aplica / Limpar --</strong></li>`; ul.firstChild.onclick = () => limparBuscaFavConciliacao();
              r.data.forEach(v => {
                  let badge = v.TIPO === 'CLIENTENOVO' ? '<span class="badge bg-primary">CLIENTE</span>' : (v.TIPO === 'VENDEDOR' ? '<span class="badge bg-warning text-dark">VENDEDOR</span>' : '<span class="badge bg-info text-dark">ENTIDADE</span>');
                  let li = document.createElement('li'); li.innerHTML = `<strong>${v.NOME}</strong> ${badge}<br><small class="text-muted">Doc: ${v.DOC}</small>`;
                  li.onclick = () => { document.getElementById('cFavorecidoText').value = v.NOME; document.getElementById('cFavorecidoId').value = `${v.TIPO}_${v.ID}`; ul.style.display = 'none'; }; ul.appendChild(li);
              }); ul.style.display = 'block';
          } else { ul.style.display = 'none'; }
      }, 400);
  }
  document.addEventListener('click', (e) => { 
      if(e.target.id !== 'lFavorecidoText') { let f = document.getElementById('listaFavorecidosLan'); if(f) f.style.display = 'none'; }
      if(e.target.id !== 'cFavorecidoText') { let f = document.getElementById('listaFavorecidosConc'); if(f) f.style.display = 'none'; }
  });

  // ===============================================
  // ABA: CAIXA GERAL (AGORA UNE DUAS TABELAS)
  // ===============================================
  async function carregarCaixa() { const r = await callFinanceiro('listar_lancamentos'); if(r.success) { window.caixaList = r.data; filtrarCaixa(); } }
  function filtrarCaixa() {
     const t = document.getElementById('buscaCaixa').value.toLowerCase(); const st = document.getElementById('filtroStatusCaixa').value; const tb = document.getElementById('tbody-caixa'); tb.innerHTML = '';
     window.caixaList.filter(x => x.DESCRICAO.toLowerCase().includes(t) && (!st || x.CATEGORIA === st)).forEach(x => {
        let cls = x.CATEGORIA === 'RECEBIDO' ? 'bg-recebido border-success' : (x.CATEGORIA === 'PAGO' ? 'bg-pago border-dark' : (x.CATEGORIA === 'A RECEBER' ? 'bg-receber border-info' : 'bg-pagar border-danger'));
        let bancoInfo = x.NOME_CONTA ? `<i class="fas fa-university text-info me-1"></i>${x.NOME_CONTA}` : '<i class="text-muted small">Não Informada</i>';
        let favorecidoInfo = x.NOME_FAVORECIDO ? `<span class="badge bg-secondary border border-dark"><i class="fas fa-user text-warning me-1"></i>${x.NOME_FAVORECIDO}</span>` : '<i class="text-muted small">-</i>';

        let opcoesStatus = '';
        if(x.TIPO_MOVIMENTO === 'ENTRADA') {
            opcoesStatus = `<li><a class="dropdown-item fw-bold text-success" href="#" onclick="mudarStatusLancamento(${x.ID}, 'RECEBIDO', 'ENTRADA'); return false;"><i class="fas fa-check me-2"></i>Marcar como RECEBIDO</a></li><li><a class="dropdown-item fw-bold text-info" href="#" onclick="mudarStatusLancamento(${x.ID}, 'A RECEBER', 'ENTRADA'); return false;"><i class="fas fa-clock me-2"></i>Voltar para A RECEBER</a></li>`;
        } else if (x.TIPO_MOVIMENTO === 'SAIDA') {
            opcoesStatus = `<li><a class="dropdown-item fw-bold text-danger" href="#" onclick="mudarStatusLancamento(${x.ID}, 'PAGO', 'SAIDA'); return false;"><i class="fas fa-check me-2"></i>Marcar como PAGO</a></li><li><a class="dropdown-item fw-bold text-warning" href="#" onclick="mudarStatusLancamento(${x.ID}, 'A PAGAR', 'SAIDA'); return false;"><i class="fas fa-clock me-2"></i>Voltar para A PAGAR</a></li>`;
        }

        let btnEditar = `<button class="btn btn-sm btn-warning fw-bold border-dark shadow-sm" title="Editar" onclick="abrirEditarLancamento(${x.ID}, '${x.TIPO_MOVIMENTO}')"><i class="fas fa-edit"></i></button>`;
        
        tb.innerHTML += `<tr class="border-bottom border-dark">
            <td class="fw-bold text-primary">${x.DESCRICAO}</td><td>${favorecidoInfo}</td><td class="fw-bold text-dark">${bancoInfo}</td>
            <td class="small">${x.OBSERVACAO ? x.OBSERVACAO : '-'}</td><td class="${x.TIPO_MOVIMENTO=='ENTRADA'?'text-entrada':'text-saida'}">${x.TIPO_MOVIMENTO}</td>
            <td class="fw-bold fs-6">R$ ${parseFloat(x.VALOR).toFixed(2).replace('.', ',')}</td><td>${x.DATA_VENC_BR}</td>
            <td><div class="dropdown"><button class="btn btn-sm dropdown-toggle status-badge ${cls} shadow-sm" data-bs-toggle="dropdown">${x.CATEGORIA}</button><ul class="dropdown-menu shadow-sm border-dark">${opcoesStatus}</ul></div></td>
            <td class="text-center" style="width: 120px;"><div class="d-flex gap-1 justify-content-center"><button class="btn btn-sm btn-primary fw-bold border-dark shadow-sm" onclick="abrirVisLancamento(${x.ID}, '${x.TIPO_MOVIMENTO}')"><i class="fas fa-eye"></i></button>${btnEditar}<button class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="if(confirm('Excluir permanentemente?')) callFinanceiro('excluir_lancamento',{id:${x.ID}, tipo_movimento:'${x.TIPO_MOVIMENTO}'}).then(carregarCaixa)"><i class="fas fa-trash"></i></button></div></td>
        </tr>`;
     });
  }

  async function mudarStatusLancamento(id, novoStatus, tipoMovimento) { const r = await callFinanceiro('mudar_status_lancamento', { id: id, categoria: novoStatus, tipo_movimento: tipoMovimento }); if(r.success) carregarCaixa(); else alert(r.msg); }
  
  function abrirVisLancamento(id, tipoMovimento) {
      const item = window.caixaList.find(i => i.ID == id && i.TIPO_MOVIMENTO == tipoMovimento); if(!item) return;
      document.getElementById('visLDesc').innerText = item.DESCRICAO; document.getElementById('visLFav').innerText = item.NOME_FAVORECIDO || 'Não se aplica'; document.getElementById('visLBanco').innerText = item.NOME_CONTA || 'Não informada';
      document.getElementById('visLObs').innerText = item.OBSERVACAO || 'Sem detalhes'; document.getElementById('visLTipo').innerText = item.TIPO_MOVIMENTO; document.getElementById('visLValor').innerText = 'R$ ' + parseFloat(item.VALOR).toFixed(2).replace('.', ',');
      document.getElementById('visLVenc').innerText = item.DATA_VENC_BR; document.getElementById('visLPag').innerText = item.DATA_PAG_BR;
      let cls = item.CATEGORIA === 'RECEBIDO' ? 'bg-success text-white' : (item.CATEGORIA === 'PAGO' ? 'bg-secondary text-white' : (item.CATEGORIA === 'A RECEBER' ? 'bg-info text-dark' : 'bg-danger text-white'));
      document.getElementById('visLStatus').innerHTML = `<span class="badge ${cls} fs-6 border border-dark">${item.CATEGORIA}</span>`; modais.visLancamento.show();
  }

  function abrirNovoLancamento() { 
      document.getElementById('fNovoLancamento').reset(); document.getElementById('lLancamentoId').value = ''; document.getElementById('titModalLancamento').innerHTML = '<i class="fas fa-file-invoice-dollar me-2"></i>Registrar Lançamento';
      document.getElementById('boxOrigem').style.display = 'none'; document.getElementById('boxDestino').style.display = 'none'; document.getElementById('lTipo').removeAttribute('disabled');
      document.getElementById('lDataConciliacao').value = new Date().toISOString().split('T')[0];
      document.querySelectorAll('.alerta-edit-cascade').forEach(e => e.remove()); carregarBancosNoSelect(); modais.lancamento.show(); 
  }

  async function abrirEditarLancamento(id, tipoMovimento) {
      const item = window.caixaList.find(i => i.ID == id && i.TIPO_MOVIMENTO == tipoMovimento); if(!item) return;
      document.getElementById('fNovoLancamento').reset(); document.getElementById('lLancamentoId').value = item.ID; document.getElementById('titModalLancamento').innerHTML = '<i class="fas fa-edit text-warning me-2"></i>Editar Lançamento';
      document.querySelectorAll('.alerta-edit-cascade').forEach(e => e.remove()); await carregarBancosNoSelect();
      document.getElementById('lTipo').value = item.TIPO_MOVIMENTO; document.getElementById('lTipo').setAttribute('disabled', 'disabled'); ajustarTipoLancamento();
      document.getElementById('lCat').value = item.CATEGORIA; document.getElementById('lContaBanco').value = item.CONTA_ID;
      document.getElementById('lFavorecidoId').value = item.TIPO_FAVORECIDO ? `${item.TIPO_FAVORECIDO}_${item.FAVORECIDO_ID}` : ''; document.getElementById('lFavorecidoText').value = item.NOME_FAVORECIDO || '';
      document.getElementById('lTipoFavorecidoBusca').value = item.TIPO_FAVORECIDO === 'VENDEDOR' ? 'VENDEDOR' : (item.TIPO_FAVORECIDO === 'ENTIDADE' ? 'ENTIDADE' : 'CLIENTE');
      document.getElementById('lObs').value = item.OBSERVACAO || ''; document.getElementById('lValor').value = item.VALOR; document.getElementById('lVenc').value = item.DATA_VENCIMENTO; document.getElementById('lPag').value = item.DATA_PAGAMENTO || '';
      document.getElementById('lDataConciliacao').value = item.DATA_CONCILIACAO || '';
      document.getElementById('lCodBanco').value = item.CODIGO_BANCO || '';
      
      let cascataContainer = document.getElementById('container_cascata_origem');
      cascataContainer.innerHTML = `<div class="col-12 alert alert-info py-2 mb-2 small fw-bold border-dark shadow-sm alerta-edit-cascade"><i class="fas fa-sitemap me-2 text-primary"></i> <b>Hierarquia atual:</b><br><span class="text-dark">${item.DESCRICAO}</span><br><a href="#" onclick="iniciarCascata('${item.TIPO_MOVIMENTO}', '_origem'); return false;" class="text-danger mt-1 d-block"><i class="fas fa-edit"></i> Clique para alterar</a><input type="hidden" id="lDescricaoOriginal" value="${item.DESCRICAO}"></div>`;
      modais.lancamento.show();
  }

  function ajustarTipoLancamento() { 
      const t = document.getElementById('lTipo').value; const boxStatus = document.getElementById('boxStatus'); const boxOrigem = document.getElementById('boxOrigem'); const boxDestino = document.getElementById('boxDestino'); const titOrigem = document.getElementById('titleOrigem');
      if(!t) { boxOrigem.style.display = 'none'; boxDestino.style.display = 'none'; boxStatus.style.display = 'block'; document.getElementById('lCat').innerHTML = '<option value="">-- Escolha Movimento --</option>'; return; }
      if (t === 'TRANSFERENCIA') {
          boxStatus.style.display = 'none'; boxOrigem.style.display = 'block'; boxDestino.style.display = 'block'; titOrigem.innerHTML = '<i class="fas fa-arrow-circle-up text-danger"></i> Dados da Saída (Origem)';
          document.getElementById('lContaDestino').setAttribute('required', 'required'); iniciarCascata('SAIDA', '_origem'); iniciarCascata('ENTRADA', '_destino');
      } else {
          boxStatus.style.display = 'block'; boxOrigem.style.display = 'block'; boxDestino.style.display = 'none';
          titOrigem.innerHTML = t === 'ENTRADA' ? '<i class="fas fa-arrow-circle-down text-success"></i> Conta que vai Receber' : '<i class="fas fa-arrow-circle-up text-danger"></i> Conta de Saída';
          document.getElementById('lCat').innerHTML = t === 'ENTRADA' ? '<option value="A RECEBER">A Receber (+)</option><option value="RECEBIDO">Já Recebido (Pago)</option>' : '<option value="A PAGAR">A Pagar (-)</option><option value="PAGO">Já Pago</option>';
          document.getElementById('lContaDestino').removeAttribute('required'); iniciarCascata(t, '_origem');
      }
  }

  document.getElementById('fNovoLancamento').addEventListener('submit', async e => { 
      e.preventDefault(); const idLancamento = document.getElementById('lLancamentoId').value; const tipoMov = document.getElementById('lTipo').value;
      const selectsOrigem = document.querySelectorAll('.select-conta-cascata_origem'); let contaSelecionadaOrigem = '';
      if (selectsOrigem.length > 0) { for (let i = selectsOrigem.length - 1; i >= 0; i--) { if (selectsOrigem[i].value !== "") { contaSelecionadaOrigem = selectsOrigem[i].options[selectsOrigem[i].selectedIndex].getAttribute('data-caminho'); break; } } } else { const inputDescOrig = document.getElementById('lDescricaoOriginal'); if (inputDescOrig) contaSelecionadaOrigem = inputDescOrig.value; }
      if(contaSelecionadaOrigem === '') { alert("Selecione a Hierarquia da Origem!"); return; }

      let contaSelecionadaDestino = '';
      if (tipoMov === 'TRANSFERENCIA' && !idLancamento) {
          const selectsDestino = document.querySelectorAll('.select-conta-cascata_destino');
          for (let i = selectsDestino.length - 1; i >= 0; i--) { if (selectsDestino[i].value !== "") { contaSelecionadaDestino = selectsDestino[i].options[selectsDestino[i].selectedIndex].getAttribute('data-caminho'); break; } }
          if(contaSelecionadaDestino === '') { alert("Selecione a Hierarquia do Destino!"); return; }
          if(document.getElementById('lContaBanco').value === document.getElementById('lContaDestino').value) { alert("Contas iguais!"); return; }
      }

      const f = { 
          id: idLancamento, 
          tipo_movimento: tipoMov, 
          categoria: tipoMov === 'TRANSFERENCIA' ? 'PAGO' : document.getElementById('lCat').value, 
          descricao: contaSelecionadaOrigem, 
          descricao_destino: contaSelecionadaDestino, 
          conta_id: document.getElementById('lContaBanco').value, 
          conta_destino_id: (tipoMov === 'TRANSFERENCIA' && !idLancamento) ? document.getElementById('lContaDestino').value : '',
          favorecido: tipoMov === 'TRANSFERENCIA' ? '' : document.getElementById('lFavorecidoId').value, 
          valor: document.getElementById('lValor').value, 
          vencimento: document.getElementById('lVenc').value, 
          pagamento: document.getElementById('lPag').value, 
          obs: document.getElementById('lObs').value,
          codigo_banco: document.getElementById('lCodBanco').value,
          data_conciliacao: document.getElementById('lDataConciliacao').value
      }; 
      const r = await callFinanceiro('salvar_lancamento', f); if(r.success){ modais.lancamento.hide(); carregarCaixa(); } else { alert(r.msg); }
  });

  // ===============================================
  // BOTÃO DE SINCRONIZAÇÃO PAGBANK (API)
  // ===============================================
  // Preenche datas padrão: último mês até hoje
  (function() {
      const hoje = new Date();
      const fim = hoje.toISOString().slice(0, 10);
      const ini = new Date(hoje.getFullYear(), hoje.getMonth(), 1).toISOString().slice(0, 10);
      document.addEventListener('DOMContentLoaded', function() {
          const di = document.getElementById('sincDataInicio');
          const df = document.getElementById('sincDataFim');
          if (di && !di.value) di.value = ini;
          if (df && !df.value) df.value = fim;
      });
  })();

  async function sincronizarPagBank() {
      const btn = document.getElementById('btnSincApi');
      const dataInicio = document.getElementById('sincDataInicio').value;
      const dataFim    = document.getElementById('sincDataFim').value;

      if (!dataInicio || !dataFim) { alert('Selecione o período de início e fim antes de sincronizar.'); return; }
      if (dataInicio > dataFim) { alert('A data de início não pode ser maior que a data fim.'); return; }

      const originalHtml = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Conectando ao Banco...';
      btn.disabled = true;

      try {
          const fd = new FormData();
          fd.append('acao', 'sincronizar_extrato');
          fd.append('data_inicio', dataInicio);
          fd.append('data_fim', dataFim);

          const res = await fetch('api_pagbank.php', { method: 'POST', body: fd });
          const j = await res.json();

          alert(j.msg);

          if (j.success) {
              carregarDespesas();
          }
      } catch (e) {
          alert("Ocorreu um erro de conexão com o seu arquivo api_pagbank.php");
      } finally {
          btn.innerHTML = originalHtml;
          btn.disabled = false;
      }
  }

  // ===============================================
  // MODAL E BUSCA DO EXTRATO PAGBANK
  // ===============================================
  function abrirModalExtratoPagBank() {
      if(!modais.extratoPagbank) {
          modais.extratoPagbank = new bootstrap.Modal(document.getElementById('mExtratoPagBank'));
      }
      modais.extratoPagbank.show();
  }

  async function buscarExtratoPagBankAPI() {
      const tb = document.getElementById('tbody-extrato-pagbank');
      tb.innerHTML = '<tr><td colspan="5" class="py-4 text-primary fw-bold"><i class="fas fa-spinner fa-spin me-2"></i>Buscando diretamente no PagBank...</td></tr>';
      
      const fd = new FormData();
      fd.append('acao', 'ver_extrato');
      fd.append('data_inicio', document.getElementById('extDataIni').value);
      fd.append('data_fim', document.getElementById('extDataFim').value);

      try {
          const res = await fetch('api_pagbank.php', { method: 'POST', body: fd });
          const j = await res.json();

          if(!j.success) {
              tb.innerHTML = `<tr><td colspan="5" class="py-4 text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> ${j.msg}</td></tr>`;
              return;
          }

          if(!j.data || j.data.length === 0) {
              tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold">Nenhuma movimentação neste período.</td></tr>';
              return;
          }

          tb.innerHTML = '';
          j.data.forEach(item => {
              let valor = parseFloat(item.amount);
              let cor = valor >= 0 ? 'text-success' : 'text-danger';
              let tipo = valor >= 0 ? '<span class="badge bg-success border border-dark"><i class="fas fa-arrow-up"></i> ENTRADA</span>' : '<span class="badge bg-danger border border-dark"><i class="fas fa-arrow-down"></i> SAÍDA</span>';
              
              let dataObj = new Date(item.created_at);
              let dataFmt = dataObj.toLocaleDateString('pt-BR') + ' ' + dataObj.toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
              
              tb.innerHTML += `<tr class="border-bottom border-dark bg-white">
                  <td class="fw-bold">${dataFmt}</td>
                  <td class="text-start">${item.description || 'Transação Genérica'}</td>
                  <td style="font-size:0.75rem;"><code class="text-dark bg-light px-1 border border-secondary rounded">${item.id}</code></td>
                  <td>${tipo}</td>
                  <td class="fw-bold ${cor} fs-6">R$ ${Math.abs(valor).toFixed(2).replace('.', ',')}</td>
              </tr>`;
          });
      } catch(e) {
          tb.innerHTML = '<tr><td colspan="5" class="py-4 text-danger fw-bold">Erro de conexão com a API.</td></tr>';
      }
  }

  // ABA DEMAIS E VÍNCULOS
  async function carregarContasBancarias() {
    const tb = document.getElementById('tbody-contas-bancarias');
    tb.innerHTML = '<tr><td colspan="3" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Lendo contas bancárias...</td></tr>';
    const r = await callFinanceiro('listar_contas_bancarias');
    if(r.success) {
      tb.innerHTML = '';
      if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted fw-bold">Nenhum banco cadastrado.</td></tr>'; }
      else r.data.forEach(x => { tb.innerHTML += `<tr class="border-bottom border-dark"><td class="fw-bold text-info text-dark"><i class="fas fa-university me-2"></i> ${x.NOME_CONTA}</td><td class="small text-muted" style="white-space: pre-wrap;">${x.DADOS_BANCARIOS}</td><td class="text-center"><button class="btn btn-sm btn-danger border-dark shadow-sm" onclick="if(confirm('Excluir essa conta?')) callFinanceiro('excluir_conta_bancaria',{id:${x.ID}}).then(carregarContasBancarias)"><i class="fas fa-trash"></i></button></td></tr>`; });
    }
    // Carrega token salvo
    const cfg = await callFinanceiro('ler_config_financeiro', { chave: 'PAGBANK_TOKEN' });
    if (cfg.valor) {
      document.getElementById('inputPagbankToken').value = cfg.valor;
      const dt = cfg.atualizado_em ? new Date(cfg.atualizado_em).toLocaleString('pt-BR') : '';
      document.getElementById('pagbank_atualizado').textContent = dt ? 'Salvo em: ' + dt : '';
    }
  }
  function abrirModalContaBancaria() { document.getElementById('fContaBancaria').reset(); document.getElementById('cbId').value = ""; modais.banco.show(); }
  document.getElementById('fContaBancaria').addEventListener('submit', async e => { e.preventDefault(); const f = { id: document.getElementById('cbId').value, nome: document.getElementById('cbNome').value, dados: document.getElementById('cbDados').value }; const r = await callFinanceiro('salvar_conta_bancaria', f); if(r.success) { modais.banco.hide(); carregarContasBancarias(); } else { alert(r.msg); } });

  async function salvarTokenPagBank() {
    const token = document.getElementById('inputPagbankToken').value.trim();
    const msg   = document.getElementById('pagbank_msg');
    if (!token) { msg.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">Cole o token antes de salvar.</div>'; return; }
    const r = await callFinanceiro('salvar_config_financeiro', { chave: 'PAGBANK_TOKEN', valor: token });
    if (r.success) {
      msg.innerHTML = '<div class="alert alert-success py-2 mb-0 small"><i class="fas fa-check-circle me-1"></i> Token salvo! A sincronização já usará o novo token.</div>';
      document.getElementById('pagbank_atualizado').textContent = 'Salvo em: ' + new Date().toLocaleString('pt-BR');
      setTimeout(() => msg.innerHTML = '', 4000);
    } else {
      msg.innerHTML = `<div class="alert alert-danger py-2 mb-0 small">${r.msg}</div>`;
    }
  }
  async function carregarListaPlanoContas() { const tb = document.getElementById('tbody-plano-contas'); tb.innerHTML = '<tr><td colspan="4" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Lendo plano de contas...</td></tr>'; const r = await callFinanceiro('listar_plano_contas'); if(r.success) { window.listaContasCache = r.data; tb.innerHTML = ''; if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="4" class="text-center py-4 text-muted fw-bold">A árvore está vazia. Crie as contas!</td></tr>'; return; } r.data.forEach(x => { let cls = x.TIPO === 'ENTRADA' ? 'text-success' : 'text-danger'; let statusBadge = x.STATUS === 'ATIVO' ? '<span class="badge bg-success">ATIVO</span>' : '<span class="badge bg-secondary">INATIVO</span>'; tb.innerHTML += `<tr class="border-bottom border-dark"><td class="fw-bold ${cls}">${x.TIPO}</td><td class="fw-bold text-dark"><i class="fas fa-level-up-alt text-muted me-2" style="transform: rotate(90deg);"></i> ${x.CAMINHO_HIERARQUIA}</td><td>${statusBadge}</td><td class="text-center"><button class="btn btn-sm btn-primary border-dark shadow-sm fw-bold" onclick="abrirVisPlanoConta(${x.ID})"><i class="fas fa-eye me-1"></i> Visualizar</button></td></tr>`; }); } }
  function abrirVisPlanoConta(id) { const pc = window.listaContasCache.find(x => x.ID == id); if(!pc) return; document.getElementById('visPcTipo').innerText = pc.TIPO; document.getElementById('visPcCaminho').innerText = pc.CAMINHO_HIERARQUIA; document.getElementById('visPcStatus').innerHTML = pc.STATUS === 'ATIVO' ? '<span class="badge bg-success fs-6">ATIVO</span>' : '<span class="badge bg-secondary fs-6">INATIVO</span>'; let btnStatus = pc.STATUS === 'ATIVO' ? `<button class="btn btn-secondary border-dark fw-bold me-2 shadow-sm" onclick="mudarStatusPlanoConta(${pc.ID}, 'INATIVO')"><i class="fas fa-ban me-1"></i> Inativar</button>` : `<button class="btn btn-success border-dark fw-bold me-2 shadow-sm" onclick="mudarStatusPlanoConta(${pc.ID}, 'ATIVO')"><i class="fas fa-check me-1"></i> Ativar</button>`; document.getElementById('visPcFooter').innerHTML = `${btnStatus} <button class="btn btn-warning border-dark fw-bold text-dark me-2 shadow-sm" onclick="editarPlanoConta(${pc.ID})"><i class="fas fa-edit me-1"></i> Editar</button> <button class="btn btn-danger border-dark fw-bold shadow-sm" onclick="excluirPlanoConta(${pc.ID})"><i class="fas fa-trash me-1"></i> Excluir</button>`; modais.visPlano.show(); }
  async function mudarStatusPlanoConta(id, novoStatus) { if(!confirm(`Alterar status para ${novoStatus}?`)) return; const r = await callFinanceiro('mudar_status_plano_conta', { id: id, status: novoStatus }); if(r.success) { await carregarListaPlanoContas(); abrirVisPlanoConta(id); } }
  async function excluirPlanoConta(id) { if(!confirm('Excluir?')) return; const r = await callFinanceiro('excluir_plano_conta', { id: id }); if(r.success) { modais.visPlano.hide(); carregarListaPlanoContas(); } else { alert(r.msg); } }
  function editarPlanoConta(id) { const pc = window.listaContasCache.find(x => x.ID == id); modais.visPlano.hide(); document.getElementById('fPlanoConta').reset(); document.getElementById('pcId').value = pc.ID; document.getElementById('pcTipo').value = pc.TIPO; carregarPaisSelect(); document.getElementById('pcParent').value = pc.PARENT_ID || ''; document.getElementById('pcNome').value = pc.NOME_CONTA; modais.plano.show(); }
  function abrirModalPlanoConta() { document.getElementById('fPlanoConta').reset(); document.getElementById('pcId').value = ""; carregarPaisSelect(); modais.plano.show(); }
  function carregarPaisSelect() { const tipoSel = document.getElementById('pcTipo').value; const sel = document.getElementById('pcParent'); sel.innerHTML = '<option value="">-- É UMA CONTA PRINCIPAL RAIZ --</option>'; if(tipoSel !== "" && window.listaContasCache.length > 0) { window.listaContasCache.forEach(x => { if(x.TIPO === tipoSel) { sel.innerHTML += `<option value="${x.ID}">${x.CAMINHO_HIERARQUIA}</option>`; } }); } }
  document.getElementById('fPlanoConta').addEventListener('submit', async e => { e.preventDefault(); const f = { id: document.getElementById('pcId').value, tipo: document.getElementById('pcTipo').value, parent_id: document.getElementById('pcParent').value, nome: document.getElementById('pcNome').value }; const r = await callFinanceiro('salvar_plano_conta', f); if(r.success) { modais.plano.hide(); carregarListaPlanoContas(); } else { alert(r.msg); } });
  async function buscarBase(termo) { clearTimeout(delayTimer); let ul = document.getElementById('listaBase'); if(termo.length < 3) { ul.style.display = 'none'; return; } delayTimer = setTimeout(async () => { const r = await callFinanceiro('buscar_cadastro_base', { termo: termo }); if(r.success && r.data.length > 0) { ul.innerHTML = ''; r.data.forEach(c => { let li = document.createElement('li'); li.innerHTML = `<strong>${c.NOME}</strong> <br><small class="text-muted">${c.TIPO} | Doc: ${c.DOC}</small>`; li.onclick = () => { document.getElementById('vBusca').value = c.NOME; document.getElementById('vNome').value = c.NOME; document.getElementById('vDoc').value = c.DOC; document.getElementById('vTipo').value = c.TIPO; ul.style.display = 'none'; }; ul.appendChild(li); }); ul.style.display = 'block'; } else { ul.style.display = 'none'; } }, 400); }
  document.addEventListener('click', e => { if(e.target.id !== 'vBusca' && document.getElementById('listaBase')) document.getElementById('listaBase').style.display = 'none'; });
  function abrirModalVinculo(tipo, id=0, nome='', doc='', tipoBase='') { document.getElementById('fVinculo').reset(); document.getElementById('vDestino').value = tipo; document.getElementById('vId').value = id; document.getElementById('titVinculo').innerText = id > 0 ? `Editar Vínculo (${tipo})` : `Vincular Novo ${tipo}`; if(id > 0) { document.getElementById('vBusca').value = nome; document.getElementById('vNome').value = nome; document.getElementById('vDoc').value = doc; document.getElementById('vTipo').value = tipoBase; } modais.vinculo.show(); }
  document.getElementById('fVinculo').addEventListener('submit', async e => { e.preventDefault(); const dest = document.getElementById('vDestino').value; const f = { id: document.getElementById('vId').value, nome: document.getElementById('vNome').value, documento: document.getElementById('vDoc').value, tipo: document.getElementById('vTipo').value }; const r = await callFinanceiro(dest === 'ENTIDADE' ? 'salvar_entidade' : 'salvar_vendedor', f); if(r.success) { modais.vinculo.hide(); dest === 'ENTIDADE' ? carregarEntidades() : carregarVendedores(); } else alert(r.msg); });
  async function carregarEntidades() { const r = await callFinanceiro('listar_entidades'); const tb = document.getElementById('tbody-entidades'); tb.innerHTML = ''; if(r.success) r.data.forEach(x => { tb.innerHTML += `<tr class="border-bottom border-dark"><td class="fw-bold text-primary">${x.NOME}</td><td>${x.DOCUMENTO}</td><td><span class="badge bg-secondary">${x.TIPO_VINCULO}</span></td><td><span class="badge bg-success">Ativo</span></td><td class="text-center"><button class="btn btn-sm btn-dark fw-bold shadow-sm me-1" onclick="abrirModalVinculo('ENTIDADE', ${x.ID}, '${x.NOME}', '${x.DOCUMENTO}', '${x.TIPO_VINCULO}')"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="if(confirm('Desvincular?')) callFinanceiro('excluir_entidade',{id:${x.ID}}).then(carregarEntidades)"><i class="fas fa-unlink"></i></button></td></tr>`; }); }
  async function carregarVendedores() {
    const r = await callFinanceiro('listar_vendedores');
    const tb = document.getElementById('tbody-vendedores');
    tb.innerHTML = '';
    if(r.success) r.data.forEach(x => {
      let linkHtml = x.LINK_INDICACAO
        ? `<div class="d-flex align-items-center gap-1"><input type="text" value="${x.LINK_INDICACAO}" readonly class="form-control form-control-sm border-dark fw-bold" style="font-size:0.75rem; max-width:260px;" id="lnk_${x.ID}"><button class="btn btn-sm btn-outline-dark border-dark shadow-sm" title="Copiar link" onclick="copiarLink('lnk_${x.ID}')"><i class="fas fa-copy"></i></button></div>`
        : `<button class="btn btn-sm btn-info text-dark fw-bold border-dark shadow-sm" onclick="gerarLinkVendedor(${x.ID})"><i class="fas fa-link me-1"></i> Gerar Link</button>`;
      tb.innerHTML += `<tr class="border-bottom border-dark">
        <td class="fw-bold text-dark">${x.NOME}</td>
        <td>${x.DOCUMENTO_VENDEDOR}</td>
        <td><span class="badge bg-secondary">${x.TIPO_VINCULO}</span></td>
        <td class="text-center"><span class="badge bg-primary fs-6" style="cursor:pointer;" title="Ver clientes indicados" onclick="verIndicados(${x.ID}, '${x.NOME.replace(/'/g,"\\'")}')">${x.TOTAL_INDICADOS ?? 0}</span></td>
        <td>${linkHtml}</td>
        <td><span class="badge bg-success">Ativo</span></td>
        <td class="text-center">
          <button class="btn btn-sm btn-success fw-bold border-dark shadow-sm me-1" onclick="abrirComissoesVendedor(${x.ID}, '${x.NOME}')" title="Tabelas de Comissão Liberadas"><i class="fas fa-money-check-alt"></i> Liberação</button>
          <button class="btn btn-sm btn-dark fw-bold shadow-sm me-1" onclick="abrirModalVinculo('VENDEDOR', ${x.ID}, '${x.NOME}', '${x.DOCUMENTO_VENDEDOR}', '${x.TIPO_VINCULO}')"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-danger fw-bold shadow-sm" onclick="if(confirm('Desvincular?')) callFinanceiro('excluir_vendedor',{id:${x.ID}}).then(carregarVendedores)"><i class="fas fa-unlink"></i></button>
        </td></tr>`;
    });
  }
  async function gerarLinkVendedor(id) {
    if(!confirm('Gerar link único de indicação para este vendedor?')) return;
    const r = await callFinanceiro('gerar_link_vendedor', { id: id });
    if(r.success) { carregarVendedores(); } else { alert(r.msg); }
  }
  async function verIndicados(vendedorId, nomeVendedor) {
    document.getElementById('nomeVendedorIndicados').innerText = nomeVendedor;
    document.getElementById('tbodyIndicados').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-primary"><i class="fas fa-spinner fa-spin"></i> Buscando...</td></tr>';
    modais.indicados.show();
    const r = await callFinanceiro('listar_indicados_vendedor', { vendedor_id: vendedorId });
    const tb = document.getElementById('tbodyIndicados');
    if (r.success) {
      if (!r.data.length) { tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Nenhum cliente indicado ainda.</td></tr>'; return; }
      tb.innerHTML = '';
      r.data.forEach(c => {
        let sit = c.SITUACAO === 'ATIVO' ? '<span class="badge bg-success">ATIVO</span>' : `<span class="badge bg-secondary">${c.SITUACAO || 'N/A'}</span>`;
        tb.innerHTML += `<tr class="border-bottom border-dark">
          <td class="fw-bold text-primary">${c.NOME || '-'}</td>
          <td><code>${c.CPF}</code></td>
          <td>${c.CELULAR || '-'}</td>
          <td>${c.NOME_EMPRESA || '-'}</td>
          <td>${sit}</td>
          <td class="small text-muted">${c.DATA_CADASTRO_BR || '-'}</td>
        </tr>`;
      });
    } else { tb.innerHTML = `<tr><td colspan="6" class="text-center text-danger py-4">${r.msg}</td></tr>`; }
  }

  function copiarLink(inputId) {
    const el = document.getElementById(inputId); el.select(); el.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(el.value).then(() => {
      const btn = el.nextElementSibling; const orig = btn.innerHTML;
      btn.innerHTML = '<i class="fas fa-check text-success"></i>'; btn.classList.add('btn-success'); btn.classList.remove('btn-outline-dark');
      setTimeout(() => { btn.innerHTML = orig; btn.classList.remove('btn-success'); btn.classList.add('btn-outline-dark'); }, 1800);
    });
  }
  async function carregarSelectCatalogoComissoes() { const r = await callFinanceiro('buscar_variacoes_catalogo'); if(r.success) { let ops = '<option value="">-- Selecione o Produto / Variação --</option>'; r.data.forEach(p => { let valor_venda = parseFloat(p.VALOR_VENDA).toFixed(2).replace('.', ','); let regra_comissao = p.TIPO_COMISSAO === 'PERCENTUAL' ? p.VALOR_COMISSAO+'%' : 'R$ '+p.VALOR_COMISSAO; ops += `<option value="${p.ID}">📦 ${p.PRODUTO_NOME} ➡ [${p.NOME_VARIACAO}] - Venda: R$ ${valor_venda} | Comissão: ${regra_comissao}</option>`; }); document.getElementById('cVariacaoId').innerHTML = ops; } }
  function abrirComissoesVendedor(idVendedor, nomeVendedor) { document.getElementById('cVendId').value = idVendedor; document.getElementById('nomeVendedorTabela').innerHTML = `<i class="fas fa-user-tie me-2"></i>${nomeVendedor}`; carregarSelectCatalogoComissoes(); carregarTabelasLiberadas(); modais.comissaoVend.show(); }
  async function carregarTabelasLiberadas() {
    const tb = document.getElementById('tbodyComVend');
    tb.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-primary"><i class="fas fa-spinner fa-spin"></i> Lendo tabelas...</td></tr>';
    const r = await callFinanceiro('listar_comissoes_vendedor', { vendedor_id: document.getElementById('cVendId').value });
    tb.innerHTML = '';
    if(r.success) {
      if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted fw-bold">Nenhuma tabela liberada.</td></tr>'; return; }
      r.data.forEach(x => {
        let opcao = x.OPCAO_COMISSAO || 'COMISSAO';
        let btnCom = opcao === 'COMISSAO'
          ? `<button class="btn btn-sm btn-success fw-bold border-dark shadow-sm" disabled title="Ativo"><i class="fas fa-hand-holding-usd me-1"></i> Comissão</button>`
          : `<button class="btn btn-sm btn-outline-success border-dark" onclick="toggleOpcaoComissao(${x.VINCULO_ID}, 'COMISSAO')" title="Clique para alterar para Comissão"><i class="fas fa-hand-holding-usd me-1"></i> Comissão</button>`;
        let btnDes = opcao === 'DESCONTO'
          ? `<button class="btn btn-sm btn-warning fw-bold border-dark shadow-sm text-dark" disabled title="Ativo"><i class="fas fa-tag me-1"></i> Desconto</button>`
          : `<button class="btn btn-sm btn-outline-warning border-dark text-dark" onclick="toggleOpcaoComissao(${x.VINCULO_ID}, 'DESCONTO')" title="Clique para alterar para Desconto"><i class="fas fa-tag me-1"></i> Desconto</button>`;
        tb.innerHTML += `<tr class="border-bottom border-dark">
          <td class="fw-bold text-primary">${x.PRODUTO_NOME}</td>
          <td class="fw-bold">${x.NOME_VARIACAO} <span class="badge bg-secondary">R$ ${parseFloat(x.VALOR_VENDA).toFixed(2).replace('.', ',')}</span></td>
          <td class="fw-bold text-success">${x.TIPO_COMISSAO === 'PERCENTUAL' ? x.VALOR_COMISSAO+' %' : 'R$ '+x.VALOR_COMISSAO}</td>
          <td class="text-center"><div class="btn-group btn-group-sm">${btnCom}${btnDes}</div></td>
          <td class="text-center"><button class="btn btn-sm btn-danger border-dark" onclick="if(confirm('Remover permissão?')) callFinanceiro('excluir_comissao_vendedor',{id:${x.VINCULO_ID}}).then(carregarTabelasLiberadas)"><i class="fas fa-trash"></i></button></td>
        </tr>`;
      });
    }
  }
  async function toggleOpcaoComissao(vinculoId, novaOpcao) {
    const r = await callFinanceiro('atualizar_opcao_comissao', { id: vinculoId, opcao: novaOpcao });
    if(r.success) carregarTabelasLiberadas(); else alert(r.msg);
  }
  document.getElementById('fComissaoVend').addEventListener('submit', async e => { e.preventDefault(); const f = { vendedor_id: document.getElementById('cVendId').value, variacao_id: document.getElementById('cVariacaoId').value }; const r = await callFinanceiro('salvar_comissao_vendedor', f); if(r.success) { carregarTabelasLiberadas(); } else { alert(r.msg); } });
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; } else { include '../../includes/footer.php'; }
?>