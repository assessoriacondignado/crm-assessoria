<?php
session_start();

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!file_exists($caminho_conexao) || !file_exists($caminho_header)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> Arquivos base não encontrados.");
}
include $caminho_conexao;
include $caminho_header;
?>

<div class="container-fluid px-4 conteudo-principal">

    <div class="row mb-3">
        <div class="col-12">
            <h2 class="text-primary fw-bold"><i class="fas fa-robot me-2"></i> Módulo W-API</h2>
            <p class="text-muted">Gerencie suas instâncias de conexão, fluxos de chatbot e histórico de atendimento.</p>
        </div>
    </div>

    <style>
      .hidden { display: none !important; }
      .nav-tabs .nav-link { color: #555; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-weight: 500;}
      .nav-tabs .nav-link.active { color: #0d6efd; border-bottom: 3px solid #0d6efd; background: none; font-weight: bold; }
      .instance-card { border: 1px solid #e0e0e0; border-radius: 12px; margin-bottom: 20px; background:white; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
      .card-header-custom { padding: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f0f0f0; }
      .block-card { border-left: 5px solid #0d6efd; background: #fff; margin-bottom: 15px; padding: 15px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
      .bg-cadastro { background: #e3f2fd; color: #0d47a1; border-left-color: #0d47a1 !important; }
      .bg-menu { background: #e8f5e9; color: #1b5e20; border-left-color: #1b5e20 !important; }
      .bg-smart { background: #fff3e0; color: #e65100; border-left-color: #e65100 !important; }
      .block-id-badge { font-family: monospace; background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; }
      .status-badge { font-size: 0.7rem; padding: 5px 10px; border-radius: 20px; text-transform: uppercase; font-weight: 800; }
      .st-open { background-color: #d1e7dd; color: #0f5132; } 
      .st-close { background-color: #f8d7da; color: #842029; } 
      .schedule-row { display: flex; align-items: center; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
      .day-inputs { flex: 1; display: flex; gap: 5px; align-items: center; opacity: 0.4; pointer-events: none; }
      .day-inputs.active { opacity: 1; pointer-events: auto; }
      .table-logs { font-size: 0.85rem; }
      .btn-edit-contact { cursor: pointer; color: #6c757d; transition: 0.2s; } .btn-edit-contact:hover { color: #0d6efd; }
      .card-template { border: 1px solid #eee; padding: 15px; margin-bottom: 10px; border-radius: 8px; background: #fff; cursor: pointer; height: 100%; transition: 0.2s; }
      .card-template:hover { background: #f8f9fa; transform: translateY(-2px); border-color: #0d6efd;}
    </style>

    <ul class="nav nav-tabs mb-4 bg-white rounded shadow-sm px-2 pt-2 border-bottom">
      <li class="nav-item"><button class="nav-link py-3" id="tab-send-btn" onclick="switchTab('send')"><i class="fas fa-paper-plane me-2"></i>Disparador</button></li>
      <li class="nav-item"><button class="nav-link py-3" id="tab-status-btn" onclick="switchTab('status')"><i class="fas fa-network-wired me-2"></i>Instâncias</button></li>
      <li class="nav-item"><button class="nav-link py-3" id="tab-tpl-btn" onclick="switchTab('tpl')"><i class="fas fa-comment-dots me-2"></i>Modelos</button></li>
      <li class="nav-item"><button class="nav-link py-3" id="tab-logs-btn" onclick="switchTab('logs')"><i class="fas fa-list-ul me-2"></i>Logs & Clientes</button></li>
    </ul>

    <div class="tab-content">
      <div id="view-send" class="content-view hidden">
          <div class="row justify-content-center">
              <div class="col-md-8 mb-3">
                  <div class="card border-dark shadow-lg rounded-3">
                      <div class="card-header bg-dark text-white fw-bold py-3"><i class="fas fa-paper-plane me-2 text-info"></i> Enviar Mensagem</div>
                      <div class="card-body bg-light">
                          <form id="formSend">
                              <div class="mb-3">
                                  <label class="small fw-bold">1. Instância de Disparo</label>
                                  <select class="form-select border-dark" id="selInstance" required><option value="">Carregando...</option></select>
                              </div>
                              <div class="mb-3 bg-white p-3 rounded border border-dark shadow-sm">
                                  <label class="small fw-bold d-block mb-2 text-primary">2. Destinatário:</label>
                                  <div class="btn-group w-100 mb-3 border border-dark rounded">
                                      <input type="radio" class="btn-check" name="destType" id="radManual" value="manual" checked onchange="toggleDest()">
                                      <label class="btn btn-outline-dark fw-bold" for="radManual">Digitar Número</label>
                                      
                                      <input type="radio" class="btn-check" name="destType" id="radClient" value="client" onchange="toggleDest()">
                                      <label class="btn btn-outline-dark fw-bold" for="radClient">Puxar do Cadastro</label>
                                  </div>
                                  <div id="divManual">
                                      <input type="text" class="form-control border-dark" id="phone" placeholder="55 + DDD + Numero">
                                      <div class="form-check mt-2">
                                          <input class="form-check-input border-dark" type="checkbox" id="chkGroup">
                                          <label class="form-check-label small fw-bold text-muted" for="chkGroup">É um Grupo (@g.us)</label>
                                      </div>
                                  </div>
                                  <div id="divClient" class="hidden">
                                      <select id="selClient" class="form-select border-dark"><option value="">Carregando clientes...</option></select>
                                  </div>
                              </div>
                              
                              <div class="mb-3 bg-white p-3 rounded border border-dark shadow-sm">
                                  <label class="small fw-bold d-block mb-2 text-primary">3. Tipo de Disparo:</label>
                                  <div class="btn-group w-100 mb-3 border border-dark rounded">
                                      <input type="radio" class="btn-check" name="msgType" id="radText" value="text" checked onchange="toggleMsgType()">
                                      <label class="btn btn-outline-dark fw-bold" for="radText"><i class="fas fa-font me-1"></i> Apenas Texto</label>
                                      
                                      <input type="radio" class="btn-check" name="msgType" id="radDoc" value="doc" onchange="toggleMsgType()">
                                      <label class="btn btn-outline-dark fw-bold" for="radDoc"><i class="fas fa-file-alt me-1"></i> Enviar Arquivo</label>
                                  </div>

                                  <div id="divText">
                                      <label class="small fw-bold">Conteúdo da Mensagem</label>
                                      <select class="form-select mb-2 border-dark" id="selTemplate" onchange="applyTemplate()"><option value="">-- Puxar de um Modelo Salvo --</option></select>
                                      <textarea class="form-control border-dark" id="msgText" rows="5" placeholder="Escreva a sua mensagem aqui..."></textarea>
                                  </div>

                                  <div id="divDoc" class="hidden">
                                      <div class="row g-2 mb-2">
                                          <div class="col-md-8">
                                              <label class="small fw-bold">URL do Arquivo (Link direto)</label>
                                              <input type="text" class="form-control border-dark" id="docUrl" placeholder="https://site.com/arquivo.pdf">
                                          </div>
                                          <div class="col-md-4">
                                              <label class="small fw-bold">Extensão</label>
                                              <input type="text" class="form-control border-dark" id="docExt" placeholder="pdf, png, mp4...">
                                          </div>
                                      </div>
                                      <div class="row g-2 mb-2">
                                          <div class="col-md-12">
                                              <label class="small fw-bold">Nome do Arquivo (Opcional)</label>
                                              <input type="text" class="form-control border-dark" id="docFileName" placeholder="Ex: Boleto_Mensal">
                                          </div>
                                      </div>
                                      <label class="small fw-bold">Legenda (Opcional)</label>
                                      <textarea class="form-control border-dark" id="docCaption" rows="2" placeholder="Mensagem que acompanha o arquivo..."></textarea>
                                  </div>
                              </div>

                              <button type="submit" class="btn btn-primary w-100 fw-bold py-2 border-dark" id="btnSend"><i class="fas fa-paper-plane me-2"></i> Executar Disparo</button>
                          </form>
                      </div>
                  </div>
              </div>
          </div>
      </div>
      
      <div id="view-status" class="content-view hidden">
          <div class="d-flex justify-content-between align-items-center mb-4">
              <h5 class="text-dark fw-bold m-0"><i class="fas fa-server me-2"></i> Gerenciamento de Conexões</h5>
              <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="openInstanceModal()"><i class="fas fa-plus me-1"></i> Nova Instância</button>
          </div>
          <div id="loading" class="text-center py-5"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div></div>
          <div id="instancesContainer" class="row hidden g-3"></div>
      </div>
      
      <div id="view-tpl" class="content-view hidden">
          <div class="d-flex justify-content-between align-items-center mb-4">
              <h5 class="text-dark fw-bold m-0"><i class="fas fa-comment-medical me-2"></i> Mensagens Padrão (Templates)</h5>
              <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="openTplModal()"><i class="fas fa-plus me-1"></i> Criar Novo</button>
          </div>
          <div id="tplContainer" class="row g-3"></div>
      </div>
      
      <div id="view-logs" class="content-view hidden">
          <div class="card border-dark shadow-lg rounded-3">
              <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                  <div style="flex:1">
                      <div class="input-group">
                          <span class="input-group-text bg-white border-dark"><i class="fas fa-search text-primary"></i></span>
                          <input type="text" id="filterLogs" class="form-control border-dark border-start-0" placeholder="Buscar no histórico por nome ou telefone..." onkeyup="filterLogTable()">
                      </div>
                  </div>
                  <button class="btn btn-warning fw-bold border-dark ms-3" onclick="loadAutoLogs()"><i class="fas fa-sync-alt me-1"></i> Atualizar</button>
              </div>
              <div class="table-responsive bg-white">
                  <table class="table table-hover table-striped mb-0 table-logs align-middle text-center">
                      <thead class="table-dark text-white">
                          <tr><th>Data/Hora</th><th>Grupo/Chat</th><th>Telefone</th><th class="text-start">Nome / Cliente</th><th>CPF</th><th class="text-start">Mensagem / Status</th><th>Ação</th></tr>
                      </thead>
                      <tbody id="tableLogs">
                          <tr><td colspan="7" class="text-center p-4 fw-bold">Buscando dados no servidor...</td></tr>
                      </tbody>
                  </table>
              </div>
          </div>
      </div>
    </div>

    <div class="modal fade" id="modalQueue">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-dark">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-list-ol me-2"></i>Fila de Mensagens na W-API</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white border border-dark rounded shadow-sm">
                        <span class="fw-bold fs-5">Presas na fila: <span id="queueCount" class="text-danger fw-bolder">0</span></span>
                        <button class="btn btn-danger fw-bold border-dark shadow-sm" onclick="clearQueue()"><i class="fas fa-trash-alt me-2"></i> Apagar Toda a Fila</button>
                    </div>
                    <div class="table-responsive border border-dark rounded bg-white">
                        <table class="table table-sm table-hover table-striped text-center mb-0 align-middle">
                            <thead class="table-dark">
                                <tr><th>Telefone Destino</th><th class="text-start">Conteúdo da Mensagem</th><th>Status W-API</th><th>Ação</th></tr>
                            </thead>
                            <tbody id="queueTableBody">
                                <tr><td colspan="4" class="p-4 fw-bold text-muted">Carregando informações da nuvem...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditClient">
        <div class="modal-dialog">
            <div class="modal-content border-dark">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fas fa-link me-2"></i>Vincular Histórico ao Cliente</h5>
                    <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light">
                    <form id="formEditClient">
                        <div class="mb-3">
                            <label class="small fw-bold">Telefone do WhatsApp (Chave do Histórico)</label>
                            <input id="editPhone" class="form-control border-dark bg-secondary text-white fw-bold text-center fs-5" readonly>
                        </div>
                        <div class="mb-3 border border-dark rounded p-3 bg-white shadow-sm">
                            <label class="small fw-bold text-primary mb-2"><i class="fas fa-search me-1"></i> 1. Buscar Cadastro Existente</label>
                            <div class="input-group">
                                <input type="text" id="searchClientTerm" class="form-control border-dark" placeholder="Digite Nome, CPF ou Telefone...">
                                <button type="button" class="btn btn-dark border-dark fw-bold" onclick="searchClientsToLink()"><i class="fas fa-search"></i></button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="small fw-bold text-success mb-2"><i class="fas fa-check-circle me-1"></i> 2. Selecione o Cliente Encontrado</label>
                            <select id="selLinkedClient" class="form-select border-dark fw-bold" required>
                                <option value="">-- Faça a busca primeiro --</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold border-dark shadow-sm" id="btnSaveClient"><i class="fas fa-save me-2"></i> Salvar Vínculo no Histórico</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="modalNew"><div class="modal-dialog"><div class="modal-content border-dark"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-server me-2"></i>Dados da Instância</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="formNew"><input type="hidden" id="iRow"><div class="mb-3"><label class="small fw-bold">Nome de Exibição</label><input id="iName" class="form-control border-dark" required></div><div class="row mb-3"><div class="col"><label class="small fw-bold">Instance ID (W-API)</label><input id="iID" class="form-control border-dark" required></div><div class="col"><label class="small fw-bold">Tipo de Conexão</label><input id="iType" class="form-control border-dark"></div></div><div class="mb-3"><label class="small fw-bold">Token de Segurança</label><input id="iToken" class="form-control border-dark" required></div><div class="row mb-3"><div class="col"><label class="small fw-bold">Telefone</label><input id="iPhone" class="form-control border-dark"></div><div class="col"><label class="small fw-bold">Vencimento da Fatura</label><input type="date" id="iDate" class="form-control border-dark"></div></div><button type="submit" class="btn btn-primary w-100 fw-bold border-dark" id="btnSaveInstance">Salvar Registro</button></form></div></div></div></div>
    
    <div class="modal fade" id="modalConn"><div class="modal-dialog modal-sm"><div class="modal-content border-dark"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-qrcode me-2"></i>Conectar WhatsApp</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body text-center bg-light p-4"><div id="qrLoad" class="spinner-border text-primary mb-3"></div><img id="qrImg" class="img-fluid border border-2 border-dark rounded p-2 hidden shadow-sm" style="max-width:250px"><p class="text-muted small mt-3 fw-bold">Abra o WhatsApp no celular e escaneie este código para vincular.</p></div></div></div></div>
    
    <div class="modal fade" id="modalTpl"><div class="modal-dialog"><div class="modal-content border-dark"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-comment-dots me-2"></i>Criar/Editar Modelo</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="formTpl"><input type="hidden" id="tRow"><div class="mb-3"><label class="small fw-bold">Título do Modelo</label><input id="tName" class="form-control border-dark" required></div><div class="mb-3"><label class="small fw-bold">Objetivo (Opcional)</label><input id="tObj" class="form-control border-dark"></div><div class="mb-3"><label class="small fw-bold">Conteúdo da Mensagem</label><textarea id="tContent" class="form-control border-dark" rows="6" required></textarea></div><button type="submit" class="btn btn-primary w-100 fw-bold border-dark">Gravar Modelo</button></form></div></div></div></div>

    <div class="modal fade" id="modalConfigInst"><div class="modal-dialog modal-lg"><div class="modal-content border-dark">
      <div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-cogs me-2"></i>Configuração Inteligente</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <div class="modal-body bg-light">
          <form id="formConfigInst">
             <input type="hidden" id="cfgInstId">
             <div class="card p-3 mb-4 border-dark shadow-sm">
                <div class="form-check form-switch mb-3 border-bottom border-dark pb-2">
                    <input class="form-check-input" type="checkbox" id="cfgActive" style="transform: scale(1.5); margin-right: 15px;">
                    <label class="form-check-label fw-bold text-success mt-1" for="cfgActive">Ativar Robô de Atendimento</label>
                </div>
                <h6 class="text-primary border-bottom pb-1 small fw-bold"><i class="fas fa-clock me-1"></i> HORÁRIO DE ATENDIMENTO</h6>
                <div id="scheduleContainer" class="mb-3"></div>
                <div class="row g-3">
                    <div class="col-md-6"><label class="small fw-bold">Msg de Loja Fechada (Fora do horário)</label><textarea id="cfgMsg" class="form-control form-control-sm border-dark" rows="3"></textarea></div>
                    <div class="col-md-6"><label class="small fw-bold">Grupo para Encaminhamento (Aviso humano)</label><input id="cfgGroup" class="form-control form-control-sm border-dark"></div>
                </div>
             </div>
             <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white border border-dark rounded shadow-sm">
                 <div>
                     <h6 class="fw-bold m-0 text-primary"><i class="fas fa-project-diagram me-2"></i>Construtor de Fluxo</h6>
                     <div class="form-check form-switch mt-2">
                         <input class="form-check-input" type="checkbox" id="botActive">
                         <label class="form-check-label small fw-bold text-muted" for="botActive">Habilitar leitura de Blocos</label>
                     </div>
                 </div>
                 <div class="input-group input-group-sm w-50">
                     <select class="form-select border-dark fw-bold" id="selNewBlockType">
                         <option value="verificacao">1. Verificação 24h</option>
                         <option value="menu">2. Menu Interativo</option>
                         <option value="smart">3. Resposta Inteligente</option>
                     </select>
                     <button type="button" class="btn btn-success fw-bold border-dark" onclick="addNewBlockUI()"><i class="fas fa-plus"></i> Inserir</button>
                 </div>
             </div>
             <div class="alert alert-warning border-dark shadow-sm py-2 small fw-bold mb-4">
                 <i class="fas fa-lightbulb me-2"></i> Variáveis disponíveis para o texto: <span class="badge bg-dark mx-1">{nome}</span> <span class="badge bg-dark mx-1">{primeiro_nome}</span> <span class="badge bg-dark mx-1">{cpf_final}</span>
             </div>
             <div id="blocksContainer"></div>
             <div class="text-end mt-4 pt-3 border-top border-dark">
                 <button type="submit" class="btn btn-primary btn-lg px-5 fw-bold border-dark shadow-sm" id="btnSaveCfg"><i class="fas fa-save me-2"></i>Salvar Fluxo</button>
             </div>
          </form>
      </div>
    </div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    let instances=[], templates=[], allLogs=[];
    let mNew, mConn, mTpl, mCfg, mEditClient, mQueue;
    let currentQueueInstId = '';
    const DAYS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

    window.onload = function() { 
        mNew = new bootstrap.Modal(document.getElementById('modalNew'));
        mConn = new bootstrap.Modal(document.getElementById('modalConn'));
        mTpl = new bootstrap.Modal(document.getElementById('modalTpl'));
        mCfg = new bootstrap.Modal(document.getElementById('modalConfigInst'));
        mEditClient = new bootstrap.Modal(document.getElementById('modalEditClient'));
        mQueue = new bootstrap.Modal(document.getElementById('modalQueue'));

        loadInstances(); 
        loadTemplates(); 
        loadClients(); 
        loadAutoLogs(); 
        switchTab('status'); 
    };

    async function apiCall(action, payload = null) {
        try {
            const options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload ? JSON.stringify(payload) : null
            };
            const response = await fetch('ajax_wapi.php?action=' + action, options);
            if (!response.ok) throw new Error("Erro de Rede: " + response.status);
            return await response.json();
        } catch (error) {
            console.error("Erro na API:", error);
            crmToast("Aviso: Falha de comunicação com o servidor MySQL.", "info", 5000);
            return { success: false, message: error.toString() };
        }
    }

    function switchTab(id) {
        document.querySelectorAll('.content-view').forEach(e=>e.classList.add('hidden'));
        document.querySelectorAll('.nav-link').forEach(e=>e.classList.remove('active'));
        document.getElementById('view-'+id).classList.remove('hidden');
        const btn=document.getElementById('tab-'+id+'-btn'); if(btn) btn.classList.add('active');
        if(id==='logs') loadAutoLogs();
    }

    function loadInstances() { document.getElementById('loading').classList.remove('hidden'); apiCall('getInstancesList').then(d => { instances = d || []; renderControl(instances); renderSelect(instances); document.getElementById('loading').classList.add('hidden'); }); }
    function loadTemplates() { apiCall('getTemplates').then(d => { templates = d || []; renderTemplates(templates); renderTplSelect(templates); }); }
    function loadClients() { apiCall('getClientsData').then(d => { renderOptions('selClient', d || []); }); }
    function loadAutoLogs() { apiCall('getAutoLogs').then(logs => { allLogs = logs || []; renderLogsTable(allLogs); }); }

    function renderLogsTable(logs) {
        const tb = document.getElementById('tableLogs'); tb.innerHTML = ''; 
        if(!logs.length) { tb.innerHTML='<tr><td colspan="7" class="text-center text-muted fw-bold p-4">Nenhum histórico encontrado.</td></tr>'; return; }
        logs.forEach(l => { 
            tb.innerHTML += `<tr class="border-bottom border-dark">
                <td class="text-nowrap">${l.date}</td>
                <td><span class="badge bg-light text-dark border border-dark">${l.grupo || 'MSG INDIVIDUAL'}</span></td>
                <td class="fw-bold">${l.phone}</td>
                <td class="text-start fw-bold text-primary">${l.name || 'Sem nome'}</td>
                <td><span class="badge bg-secondary border border-dark">${l.cpf || 'Não Identificado'}</span></td>
                <td class="text-start text-truncate" style="max-width:250px">
                    <div class="small fw-bold text-success mb-1">${l.status}</div>
                    ${l.msg}
                </td>
                <td><button class="btn btn-sm btn-outline-dark fw-bold shadow-sm" onclick="editContact('${l.phone}')" title="Editar Vínculo"><i class="fas fa-link"></i> Vincular</button></td>
            </tr>`; 
        });
    }

    function filterLogTable() { const term = document.getElementById('filterLogs').value.toLowerCase(); const filtered = allLogs.filter(l => (l.name||'').toLowerCase().includes(term) || l.phone.includes(term)); renderLogsTable(filtered); }
    
    function editContact(phone) { 
        document.getElementById('editPhone').value = phone; 
        document.getElementById('searchClientTerm').value = '';
        document.getElementById('selLinkedClient').innerHTML = '<option value="">-- Faça a busca primeiro --</option>';
        mEditClient.show(); 
    }
    
    function searchClientsToLink() {
        const term = document.getElementById('searchClientTerm').value;
        const sel = document.getElementById('selLinkedClient');
        if (term.length < 3) { crmToast("Digite pelo menos 3 letras ou números para buscar.", "warning", 5000); return; }
        
        sel.innerHTML = '<option value="">Buscando no banco...</option>';
        apiCall('searchClient', { term: term }).then(results => {
            sel.innerHTML = '<option value="">-- Selecione o Cliente na Lista --</option>';
            if (results && results.length > 0) {
                results.forEach(c => { sel.innerHTML += `<option value="${c.CPF}|${c.NOME}">${c.NOME} (CPF: ${c.CPF})</option>`; });
            } else { sel.innerHTML = '<option value="">Nenhum cliente encontrado.</option>'; }
        });
    }

    document.getElementById('formEditClient').addEventListener('submit', e => {
        e.preventDefault(); 
        const selectedVal = document.getElementById('selLinkedClient').value;
        if (!selectedVal) { crmToast("Por favor, pesquise e selecione um cliente na lista.", "info", 5000); return; }

        const btn = document.getElementById('btnSaveClient'); btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...'; btn.disabled = true;
        const parts = selectedVal.split('|');
        const form = { phone: document.getElementById('editPhone').value, newCpf: parts[0], newName: parts[1] };
        
        apiCall('updateContactInfo', form).then(r => { 
            btn.innerHTML = '<i class="fas fa-save me-2"></i> Salvar Vínculo no Histórico'; btn.disabled = false; 
            crmToast(r.message || "Ação concluída.", "info"); 
            if(r.success) { mEditClient.hide(); loadAutoLogs(); }
        });
    });

    function renderControl(data) {
        const c = document.getElementById('instancesContainer'); c.innerHTML = ''; c.classList.remove('hidden');
        data.forEach((inst, idx) => {
            let stClass = (inst.status.toLowerCase().includes('open')) ? 'st-open' : 'st-close';
            c.innerHTML += `<div class="col-md-4">
                <div class="instance-card border-dark shadow-sm">
                    <div class="card-header-custom bg-dark text-white rounded-top">
                        <div class="fw-bold text-truncate"><i class="fab fa-whatsapp text-success me-2 fs-5"></i>${inst.nome}</div>
                        <span class="status-badge border border-white ${stClass}">${inst.status}</span>
                    </div>
                    <div class="p-3 bg-light rounded-bottom">
                        <div class="d-flex justify-content-between">
                            <button class="btn btn-dark border-dark btn-sm fw-bold shadow-sm" title="Verificar Status" onclick="cmd('status',${idx})"><i class="fas fa-sync-alt"></i></button>
                            <button class="btn btn-secondary border-dark btn-sm fw-bold shadow-sm" title="Reiniciar Instância" onclick="cmd('restart',${idx})"><i class="fas fa-redo-alt"></i></button>
                            <button class="btn btn-success border-dark btn-sm fw-bold shadow-sm" title="Gerar QR Code" onclick="conn(${idx})"><i class="fas fa-qrcode"></i></button>
                            <button class="btn btn-info border-dark btn-sm fw-bold shadow-sm text-dark" title="Fila de Mensagens" onclick="viewQueue(${idx})"><i class="fas fa-list-ol"></i> Fila</button>
                            <button class="btn btn-warning border-dark btn-sm fw-bold shadow-sm text-dark" title="Fluxo e Robô" onclick="openConfig('${inst.instanceId}', '${inst.nome}')"><i class="fas fa-cogs"></i></button>
                            <button class="btn btn-danger border-dark btn-sm fw-bold shadow-sm" title="Desconectar / Sair" onclick="cmd('logout',${idx})"><i class="fas fa-power-off"></i></button>
                        </div>
                    </div>
                </div>
            </div>`;
        });
    }
    
    function renderTemplates(data) { const c = document.getElementById('tplContainer'); c.innerHTML = ''; data.forEach((t, idx) => { c.innerHTML += `<div class="col-md-4"><div class="card-template border-dark shadow-sm" onclick="openTplModal(${idx})"><div class="fw-bold text-primary border-bottom border-dark pb-2 mb-2"><i class="fas fa-comment-alt me-2"></i>${t.nome}</div><div class="small text-muted">${t.conteudo.substring(0,80)}...</div></div></div>`; }); }
    function renderSelect(d) { const s=document.getElementById('selInstance'); s.innerHTML='<option value="">Escolha a conexão de disparo...</option>'; d.forEach((x,i)=>s.innerHTML+=`<option value="${i}">${x.nome} (Status: ${x.status})</option>`); }
    function renderTplSelect(d) { const s=document.getElementById('selTemplate'); s.innerHTML='<option value="">-- Puxar de um Modelo Salvo --</option>'; d.forEach((x,i)=>s.innerHTML+=`<option value="${i}">${x.nome}</option>`); }
    function renderOptions(id, l) { const s=document.getElementById(id); s.innerHTML='<option value="">Selecione na base de clientes...</option>'; l.forEach(x=>s.innerHTML+=`<option value="${x.phone}">${x.name} (${x.phone})</option>`); }

    function openConfig(instId, instName) {
        document.getElementById('cfgInstId').value = instId; document.getElementById('blocksContainer').innerHTML = ""; 
        const container = document.getElementById('scheduleContainer'); container.innerHTML = '';
        DAYS.forEach((dayName, idx) => { container.innerHTML += `<div class="schedule-row"><div class="day-label fw-bold" style="width: 80px;">${dayName}</div><div class="form-check form-switch me-3"><input class="form-check-input border-dark" type="checkbox" id="sc_active_${idx}" onchange="toggleDay(${idx})"></div><div class="day-inputs" id="sc_inputs_${idx}"><input type="time" class="form-control form-control-sm border-dark fw-bold" id="sc_start_${idx}" value="08:00"><span class="small mx-2 fw-bold">até</span><input type="time" class="form-control form-control-sm border-dark fw-bold" id="sc_end_${idx}" value="18:00"></div></div>`; });
        mCfg.show();
        
        apiCall('getInstanceConfig', { instanceId: instId }).then(config => {
            if(config && config.instanceId === instId) {
                document.getElementById('cfgActive').checked = config.ativo; document.getElementById('cfgMsg').value = config.mensagem; document.getElementById('cfgGroup').value = config.grupoAviso;
                if(config.schedule) { for(let i=0; i<7; i++) { let d = config.schedule[i]; if(d) { document.getElementById(`sc_active_${i}`).checked = d.active; document.getElementById(`sc_start_${i}`).value = d.start; document.getElementById(`sc_end_${i}`).value = d.end; toggleDay(i); } } }
                if (config.chatbot) { document.getElementById('botActive').checked = config.chatbot.ativo; if (config.chatbot.blocos) config.chatbot.blocos.forEach(b => renderBlock(b)); }
            }
        });
    }

    function toggleDay(idx) { const isActive = document.getElementById(`sc_active_${idx}`).checked; const inputs = document.getElementById(`sc_inputs_${idx}`); if(isActive) inputs.classList.add('active'); else inputs.classList.remove('active'); }
    function addNewBlockUI() { const type = document.getElementById('selNewBlockType').value; renderBlock({ tipo: type }); }
    
    function renderBlock(block) {
        if (!block.id) block.id = Math.random().toString(36).substr(2, 5).toUpperCase(); const id = block.id; const container = document.getElementById('blocksContainer'); let color='bg-light', title='', inputs='';
        if (block.tipo === 'verificacao') { color='bg-cadastro'; title='<i class="fas fa-search me-1"></i> Verificação 24h & Histórico'; inputs=`<div class="row"><div class="col-md-6 mb-2 border-end border-dark"><label class="small fw-bold text-success mb-1">Contato RECENTE (< 24h)</label><input type="text" class="form-control form-control-sm block-next-step border-dark" placeholder="Cole o ID do Bloco Destino" value="${block.nextStep||''}"><small class="text-muted">Ação: Pular para outro bloco</small></div><div class="col-md-6 mb-2"><label class="small fw-bold text-danger mb-1">Contato ANTIGO (> 24h) ou NOVO</label><div class="mb-2"><textarea class="form-control form-control-sm block-msg-known border-dark" rows="2" placeholder="Msg se já existir no banco...">${block.msgKnown||''}</textarea></div><div><textarea class="form-control form-control-sm block-msg-new border-dark" rows="2" placeholder="Msg se for cliente 100% novo...">${block.msgNew||''}</textarea></div><small class="text-muted">Ação: Enviar mensagem</small></div></div>`; } 
        else if (block.tipo === 'menu') { color='bg-menu'; title='<i class="fas fa-bars me-1"></i> Menu Interativo'; inputs=`<div class="mb-2"><label class="small fw-bold text-dark">Texto de Apresentação do Menu:</label><textarea class="form-control form-control-sm block-msg-menu border-dark" rows="3" placeholder="Ex: Digite 1 para falar com financeiro...">${block.msgMenu||''}</textarea></div><label class="small fw-bold mt-2 text-dark">Opções Disponíveis (Gatilho exato):</label><div id="opts_container_${id}"></div><button type="button" class="btn btn-sm btn-dark fw-bold mt-1 mb-3 shadow-sm border-dark" onclick="addOptionRow('${id}')"><i class="fas fa-plus"></i> Nova Opção</button><div class="mb-2"><label class="small fw-bold text-danger">Mensagem de Erro (Digitou errado):</label><input type="text" class="form-control form-control-sm block-msg-error border-dark" placeholder="Opção inválida, tente de novo." value="${block.msgError||''}"></div>`; setTimeout(() => { if(block.options) block.options.forEach(o => addOptionRow(id, o.opt, o.resp)); }, 100); } 
        else if (block.tipo === 'smart') { color='bg-smart'; title='<i class="fas fa-brain me-1"></i> Resposta Inteligente'; inputs=`<div class="mb-2"><label class="small fw-bold text-dark">Palavras-chave (Gatilhos separados por vírgula):</label><input type="text" class="form-control form-control-sm block-trigger border-dark" placeholder="Ex: valor, preço, quanto custa" value="${block.gatilhos||''}"></div><div class="mb-2"><label class="small fw-bold text-dark">Ação: Enviar Resposta</label><textarea class="form-control form-control-sm block-msg-smart border-dark" rows="3">${block.msgSmart||''}</textarea></div>`; }
        const html = `<div class="block-card border-dark ${color}" id="div_${id}" data-id="${id}" data-type="${block.tipo}"><div class="block-header d-flex justify-content-between align-items-center mb-3 border-bottom border-dark pb-2"><div><span class="badge bg-white text-dark border border-dark px-3 py-2 fw-bold fs-6 shadow-sm">${title}</span><span class="block-id-badge text-white bg-dark user-select-all shadow-sm ms-2" title="ID Único">ID: ${id}</span></div><button type="button" class="btn btn-danger btn-sm fw-bold border-dark shadow-sm" onclick="document.getElementById('div_${id}').remove()"><i class="fas fa-trash"></i> Remover</button></div>${inputs}</div>`; container.insertAdjacentHTML('beforeend', html);
    }

    function addOptionRow(blockId, optVal='', respVal='') { const c = document.getElementById(`opts_container_${blockId}`); const rowId = Date.now() + Math.random(); c.insertAdjacentHTML('beforeend', `<div class="input-group input-group-sm mb-2 option-row shadow-sm" id="opt_${rowId}"><span class="input-group-text bg-dark text-white border-dark fw-bold">SE:</span><input type="text" class="form-control opt-key border-dark text-center fw-bold" placeholder="1" value="${optVal}" style="max-width:80px"><span class="input-group-text bg-dark text-white border-dark fw-bold">RESP:</span><input type="text" class="form-control opt-resp border-dark" placeholder="Escreva a resposta para esta opção..." value="${respVal}"><button class="btn btn-danger border-dark fw-bold px-3" type="button" title="Apagar opção" onclick="document.getElementById('opt_${rowId}').remove()"><i class="fas fa-times"></i></button></div>`); }

    document.getElementById('formConfigInst').addEventListener('submit', e => {
        e.preventDefault(); const btn = document.getElementById('btnSaveCfg'); btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Salvando no MySQL...'; btn.disabled = true;
        let schedule = {}; for(let i=0; i<7; i++) { schedule[i] = { active: document.getElementById(`sc_active_${i}`).checked, start: document.getElementById(`sc_start_${i}`).value, end: document.getElementById(`sc_end_${i}`).value }; }
        let blocos = []; document.querySelectorAll('.block-card').forEach(el => { const type = el.getAttribute('data-type'); const id = el.getAttribute('data-id'); let b = { id: id, tipo: type }; if(type==='verificacao') { b.nextStep = el.querySelector('.block-next-step').value; b.msgKnown = el.querySelector('.block-msg-known').value; b.msgNew = el.querySelector('.block-msg-new').value; } else if (type==='menu') { b.msgMenu = el.querySelector('.block-msg-menu').value; b.msgError = el.querySelector('.block-msg-error').value; b.options = []; el.querySelectorAll('.option-row').forEach(row => { b.options.push({ opt: row.querySelector('.opt-key').value, resp: row.querySelector('.opt-resp').value }); }); } else { b.gatilhos = el.querySelector('.block-trigger').value; b.msgSmart = el.querySelector('.block-msg-smart').value; } blocos.push(b); });
        const form = { instanceId: document.getElementById('cfgInstId').value, ativo: document.getElementById('cfgActive').checked, mensagem: document.getElementById('cfgMsg').value, grupoAviso: document.getElementById('cfgGroup').value, schedule: schedule, chatbot: { ativo: document.getElementById('botActive').checked, blocos: blocos } };
        apiCall('saveInstanceConfigApi', form).then(r => { btn.innerHTML = '<i class="fas fa-save me-2"></i>Salvar Fluxo'; btn.disabled = false; crmToast(r.message || "Fluxo gravado com sucesso.", "success"); if(r.success) mCfg.hide(); });
    });

    function toggleDest() { const type = document.querySelector('input[name="destType"]:checked').value; document.getElementById('divManual').classList.toggle('hidden', type !== 'manual'); document.getElementById('divClient').classList.toggle('hidden', type !== 'client'); }
    function applyTemplate() { const idx=document.getElementById('selTemplate').value; if(idx!==""){ document.getElementById('msgText').value=templates[idx].conteudo; } }
    
    // NOVIDADE: Alternar entre envio de texto e envio de documento
    function toggleMsgType() { 
        const type = document.querySelector('input[name="msgType"]:checked').value; 
        document.getElementById('divText').classList.toggle('hidden', type !== 'text'); 
        document.getElementById('divDoc').classList.toggle('hidden', type !== 'doc'); 
    }

    // NOVIDADE: FormSubmit adaptado para Texto e Documento
    document.getElementById('formSend')?.addEventListener('submit', e => { 
        e.preventDefault(); 
        const idx = document.getElementById('selInstance').value; 
        if(idx === "") { crmToast("Erro: Selecione uma instância primeiro.", "warning", 5000); return; } 
        const inst = instances[idx]; 
        let target = "", isGroup = false; 
        const destType = document.querySelector('input[name="destType"]:checked').value; 
        
        if (destType === 'manual') { 
            target = document.getElementById('phone').value; 
            isGroup = document.getElementById('chkGroup').checked; 
        } else if (destType === 'client') { 
            target = document.getElementById('selClient').value; 
        }
        
        if(!target) { crmToast("Erro: Selecione ou digite o destinatário.", "warning", 5000); return; } 
        
        const msgType = document.querySelector('input[name="msgType"]:checked').value;
        const btn = document.getElementById('btnSend'); 
        btn.disabled = true; 
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Enviando na nuvem...'; 

        if (msgType === 'text') {
            const tx = document.getElementById('msgText').value; 
            if(!tx) { crmToast("Digite a mensagem.", "warning", 5000); btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane me-2"></i> Executar Disparo'; return; }
            
            apiCall('sendWapiMessage', { target: target, message: tx, instanceId: inst.instanceId, isGroup: isGroup }).then(r => { 
                btn.disabled = false; 
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Executar Disparo'; 
                crmToast(r.message, "info"); 
                if(r.success) { document.getElementById('msgText').value=''; }
            }); 
        } else {
            const docUrl = document.getElementById('docUrl').value;
            const docExt = document.getElementById('docExt').value;
            
            if(!docUrl || !docExt) { 
                crmToast("A URL do arquivo e a Extensão são obrigatórias.", "warning", 5000); 
                btn.disabled=false; 
                btn.innerHTML='<i class="fas fa-paper-plane me-2"></i> Executar Disparo'; 
                return; 
            }
            
            apiCall('sendWapiDocument', { 
                target: target, 
                document: docUrl,
                extension: docExt.replace('.', ''),
                fileName: document.getElementById('docFileName').value,
                caption: document.getElementById('docCaption').value,
                instanceId: inst.instanceId, 
                isGroup: isGroup 
            }).then(r => { 
                btn.disabled = false; 
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Executar Disparo'; 
                crmToast(r.message, "info"); 
                if(r.success) { 
                    document.getElementById('docUrl').value=''; 
                    document.getElementById('docExt').value=''; 
                    document.getElementById('docFileName').value='';
                    document.getElementById('docCaption').value='';
                }
            }); 
        }
    });
    
    function openTplModal(idx=null){ document.getElementById('formTpl').reset(); if(idx!==null){ const t=templates[idx]; document.getElementById('tRow').value=t.id; document.getElementById('tName').value=t.nome; document.getElementById('tObj').value=t.objetivo; document.getElementById('tContent').value=t.conteudo; }else{ document.getElementById('tRow').value=""; } mTpl.show(); }
    
    document.getElementById('formTpl').addEventListener('submit',e=>{ e.preventDefault(); const f={ id:document.getElementById('tRow').value, nome:document.getElementById('tName').value, objetivo:document.getElementById('tObj').value, conteudo:document.getElementById('tContent').value }; const fu = f.id ? 'editTemplate' : 'saveTemplate'; apiCall(fu, f).then(r => { if(r.success){ mTpl.hide(); loadTemplates(); } else crmToast(r.message, "info"); }); });

    function openInstanceModal(idx=null){ document.getElementById('formNew').reset(); if(idx!==null){ const i=instances[idx]; document.getElementById('iRow').value=i.id; document.getElementById('iName').value=i.nome; document.getElementById('iID').value=i.instanceId; document.getElementById('iType').value=i.tipo; document.getElementById('iToken').value=i.token; document.getElementById('iPhone').value=i.telefone; document.getElementById('iDate').value=i.vencimento; document.getElementById('btnSaveInstance').innerText="Atualizar Registro"; } else { document.getElementById('iRow').value=""; document.getElementById('btnSaveInstance').innerText="Salvar Nova Instância"; } mNew.show(); }

    document.getElementById('formNew').addEventListener('submit',e=>{ e.preventDefault(); const b=document.getElementById('btnSaveInstance'); b.disabled=true; const f={ id:document.getElementById('iRow').value, nome:document.getElementById('iName').value, instanceId:document.getElementById('iID').value, tipo:document.getElementById('iType').value, token:document.getElementById('iToken').value, telefone:document.getElementById('iPhone').value, vencimento:document.getElementById('iDate').value }; const fu = f.id ? 'editInstanceInSheet' : 'addInstanceToSheet'; apiCall(fu, f).then(r => { b.disabled=false; if(r.success){ mNew.hide(); loadInstances(); } else crmToast(r.message || "Erro ao salvar.", "error", 6000); }); });

    function cmd(a,i){ 
        const x=instances[i]; 
        if(a==='logout'&&!confirm('Certeza que deseja desconectar o WhatsApp desta instância?')) return; 
        if(a==='restart'&&!confirm('Deseja reiniciar a instância? O WhatsApp perderá a conexão por alguns segundos e será restabelecido.')) return; 
        
        apiCall('executeCommand', { action: a, instanceId: x.instanceId }).then(r => { 
            if(r.success){ 
                crmToast("✅ " + (r.data||"Comando processado"), "success"); 
                if(a==='status' || a==='restart') loadInstances(); 
            } else crmToast("❌ " + (r.message||r.error), "error", 6000); 
        }); 
    }

    function conn(i){ const x=instances[i]; mConn.show(); document.getElementById('qrImg').classList.add('hidden'); document.getElementById('qrLoad').classList.remove('hidden'); apiCall('executeCommand', { action: 'qrcode', instanceId: x.instanceId }).then(r => { document.getElementById('qrLoad').classList.add('hidden'); if(r.success && r.type==='qrcode'){ document.getElementById('qrImg').src=r.data.includes('base64')?r.data:'data:image/png;base64,'+r.data; document.getElementById('qrImg').classList.remove('hidden'); } else crmToast("❌ Falha QR: " + (r.message||r.error||"Instância conectada?"), "error", 6000); }); }

    // ===============================================
    // LÓGICA DO MODAL DE FILA
    // ===============================================
    function viewQueue(idx) {
        const inst = instances[idx];
        currentQueueInstId = inst.instanceId;
        document.getElementById('queueTableBody').innerHTML = '<tr><td colspan="4" class="p-4 fw-bold text-muted"><div class="spinner-border text-primary spinner-border-sm me-2"></div>Buscando fila na W-API...</td></tr>';
        document.getElementById('queueCount').innerText = '...';
        mQueue.show();
        
        apiCall('viewQueue', { instanceId: inst.instanceId }).then(r => {
            const tb = document.getElementById('queueTableBody');
            tb.innerHTML = '';
            
            if (r.success && r.data && r.data.error === false) {
                let list = r.data.messages || [];
                document.getElementById('queueCount').innerText = list.length;
                
                if(list.length === 0) {
                    tb.innerHTML = '<tr><td colspan="4" class="text-success fw-bold p-4"><i class="fas fa-check-circle me-2"></i>A fila está vazia e limpa!</td></tr>';
                } else {
                    list.forEach(m => {
                        let phone = m.phone || 'Desconhecido';
                        let text = m.message || 'Conteúdo não identificado';
                        if(typeof text === 'object') text = "Arquivo/Mídia";
                        let status = 'Pendente ⏳';
                        
                        // ID da mensagem na API para deletar
                        let msgId = m.id || m.messageId || '';
                        let btnDel = msgId ? `<button class="btn btn-sm btn-danger border-dark shadow-sm" onclick="deleteMessageFromQueue('${msgId}')" title="Apagar Mensagem"><i class="fas fa-trash"></i></button>` : '<span class="text-muted small">Sem ID</span>';
                        
                        tb.innerHTML += `<tr class="border-bottom border-dark">
                            <td class="fw-bold">${phone}</td>
                            <td class="text-start text-truncate" style="max-width: 250px;" title="${text}">${text}</td>
                            <td><span class="badge bg-warning text-dark border border-dark">${status}</span></td>
                            <td>${btnDel}</td>
                        </tr>`;
                    });
                }
            } else {
                document.getElementById('queueCount').innerText = 'Erro';
                tb.innerHTML = `<tr><td colspan="4" class="text-danger fw-bold p-4">Falha ao ler fila: ${r.message || r.error || 'Erro desconhecido da W-API'}</td></tr>`;
            }
        });
    }

    function clearQueue() {
        if(!confirm('🚨 ATENÇÃO!\nTem certeza que deseja APAGAR definitivamente TODAS as mensagens que estão presas na fila?')) return;
        
        const btn = event.currentTarget;
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Apagando...';
        btn.disabled = true;
        
        apiCall('clearQueue', { instanceId: currentQueueInstId }).then(r => {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
            crmToast(r.message || "Comando enviado.", "info");
            if(r.success) {
                mQueue.hide();
            }
        });
    }

    function deleteMessageFromQueue(msgId) {
        if(!msgId) { crmToast("ID da mensagem não encontrado.", "warning", 5000); return; }
        if(!confirm('Tem certeza que deseja apagar ESTA mensagem específica da fila?')) return;
        
        apiCall('deleteQueueMessage', { instanceId: currentQueueInstId, messageId: msgId }).then(r => {
            crmToast(r.message || "Comando enviado.", "info");
            if(r.success) {
                // Procura a instância pelo ID pra recarregar a fila visualmente
                const idx = instances.findIndex(i => i.instanceId === currentQueueInstId);
                if(idx !== -1) viewQueue(idx);
            }
        });
    }
</script>

</div> <?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>