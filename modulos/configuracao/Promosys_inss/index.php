<?php
session_start();
$path_header = '../../../includes/header.php';
if(file_exists($path_header)) { include_once $path_header; }
?>

<style>
    .nav-tabs .nav-link { cursor: pointer; font-weight: bold; color: #495057; }
    .nav-tabs .nav-link.active { color: #0d6efd; border-color: #dee2e6 #dee2e6 #fff; }
    .card-body { padding: 20px; }
    .json-viewer { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; max-height: 400px; overflow-y: auto; font-family: monospace; }
    .progress { height: 25px; border-radius: 5px; box-shadow: inset 0 1px 2px rgba(0,0,0,.1); }
    .progress-bar { font-size: 14px; font-weight: bold; line-height: 25px; transition: width 0.4s ease; }
    .caixa-upload { border: 2px dashed #0d6efd; background: #f8f9fa; padding: 25px; border-radius: 10px; text-align: center; }
</style>

<div class="container-fluid mt-4">
    <input type="hidden" id="sessao_cpf_logado" value="<?= $_SESSION['usuario_cpf'] ?? '' ?>">
    <input type="hidden" id="sessao_grupo_logado" value="<?= $_SESSION['usuario_grupo'] ?? '' ?>">

    <h2><i class="fa fa-university text-primary"></i> Módulo HIST INSS</h2>
    <hr>

    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="consulta-tab" data-bs-toggle="tab" data-bs-target="#consulta" type="button" role="tab"><i class="fas fa-search"></i> Consulta Manual</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link text-success" id="lote-tab" data-bs-toggle="tab" data-bs-target="#lote" type="button" role="tab" onclick="pmCarregarLotes()"><i class="fas fa-file-csv"></i> Consulta em Lote (CSV)</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="clientes-tab" data-bs-toggle="tab" data-bs-target="#clientes" type="button" role="tab"><i class="fas fa-users"></i> Clientes & Saldo</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="tokens-tab" data-bs-toggle="tab" data-bs-target="#tokens" type="button" role="tab"><i class="fas fa-key"></i> Credenciais API</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="robo-tab" data-bs-toggle="tab" data-bs-target="#robo" type="button" role="tab"><i class="fas fa-robot"></i> Configuração Robô</button></li>
    </ul>

    <div class="tab-content shadow-sm" id="myTabContent" style="background: #fff; padding: 25px; border: 1px solid #dee2e6; border-top: none; border-radius: 0 0 8px 8px;">
        
        <div class="tab-pane fade show active" id="consulta" role="tabpanel">
            <h4 class="mb-4 text-dark fw-bold">Consulta Individual de CPF (HIST INSS)</h4>
            <div class="row g-3 align-items-end p-3 bg-light border rounded">
                <div class="col-md-3"><label class="fw-bold small text-muted">CPF a consultar:</label><input type="text" id="cpf_consulta" class="form-control border-dark" placeholder="000.000.000-00"></div>
                <div class="col-md-4"><label class="fw-bold small text-muted">Cobrar de qual cliente?</label><select id="cliente_cobrar" class="form-select border-dark border-danger"><option value="" disabled selected>-- Selecione o Cliente (Obrigatório) --</option></select></div>
                <div class="col-md-3"><div class="form-check mt-2 border p-2 rounded bg-white border-dark"><input class="form-check-input" type="checkbox" id="forcar_api" value="1"><label class="form-check-label fw-bold text-dark small" for="forcar_api">Ignorar Cache (Forçar API)</label></div></div>
                <div class="col-md-2"><button class="btn btn-primary w-100 fw-bold shadow-sm border-dark" id="btn_consultar"><i class="fa fa-search me-1"></i> Consultar</button></div>
            </div>
            <div id="resultado_consulta" class="mt-4" style="display:none;"><div class="alert alert-info fw-bold shadow-sm" id="msg_retorno_consulta"></div><h6 class="fw-bold mt-4">Retorno Bruto (JSON):</h6><pre class="json-viewer shadow-sm" id="json_retorno_consulta"></pre></div>
        </div>

        <div class="tab-pane fade" id="lote" role="tabpanel">
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="caixa-upload shadow-sm border-primary">
                        <h5 class="fw-bold text-primary mb-2"><i class="fas fa-cloud-upload-alt me-2"></i>Importação de Lote para Consulta INSS (HIST INSS)</h5>
                        <p class="text-muted small mb-3">Limite máximo de <strong>2.000 CPFs por lote</strong>. As importações entram em fila única de processamento.</p>

                        <!-- Toggle CSV / Lista -->
                        <div class="d-flex gap-2 mb-3">
                            <button type="button" id="btn_modo_csv" class="btn btn-sm btn-primary fw-bold border-dark" onclick="pmModoImport('csv')"><i class="fas fa-file-csv me-1"></i> Arquivo CSV</button>
                            <button type="button" id="btn_modo_lista" class="btn btn-sm btn-outline-primary fw-bold border-dark" onclick="pmModoImport('lista')"><i class="fas fa-list me-1"></i> Digitar CPFs</button>
                        </div>

                        <!-- Formulário CSV -->
                        <form id="form_upload_lote" enctype="multipart/form-data" class="row justify-content-center g-3">
                            <div class="col-md-3 text-start">
                                <label class="fw-bold small text-dark">Identificação / Agrupamento:</label>
                                <input type="text" name="agrupamento" id="lote_agrupamento" class="form-control border-primary" placeholder="Ex: LOTE_INSS_MAIO" required>
                            </div>
                            <div class="col-md-3 text-start">
                                <label class="fw-bold small text-dark text-danger">Cobrar Lote de qual cliente?</label>
                                <select id="lote_cliente_cobrar" name="cpf_cobrar" class="form-select border-danger" required><option value="" disabled selected>-- Carregando... --</option></select>
                            </div>
                            <div class="col-md-3 text-start">
                                <label class="fw-bold small text-dark">Arquivo CSV:</label>
                                <input type="file" name="arquivo_csv" id="lote_arquivo" class="form-control border-primary" accept=".csv" required>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm" id="btn_enviar_lote"><i class="fas fa-play me-1"></i> Iniciar Importação</button>
                            </div>
                        </form>

                        <!-- Formulário Lista CPFs -->
                        <form id="form_lista_cpfs" class="row justify-content-center g-3" style="display:none!important;">
                            <div class="col-md-4 text-start">
                                <label class="fw-bold small text-dark">Identificação / Agrupamento:</label>
                                <input type="text" id="lista_agrupamento" class="form-control border-primary" placeholder="Ex: LOTE_INSS_MAIO" required>
                            </div>
                            <div class="col-md-4 text-start">
                                <label class="fw-bold small text-dark text-danger">Cobrar de qual cliente?</label>
                                <select id="lista_cliente_cobrar" class="form-select border-danger" required><option value="" disabled selected>-- Carregando... --</option></select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-success w-100 fw-bold shadow-sm" id="btn_enviar_lista" onclick="pmEnviarLista()"><i class="fas fa-play me-1"></i> Iniciar Verificação</button>
                            </div>
                            <div class="col-md-12 text-start">
                                <label class="fw-bold small text-dark">Lista de CPFs <span class="text-muted fw-normal">(um por linha, máx. 2.000)</span>:</label>
                                <textarea id="lista_cpfs_inss" class="form-control border-primary font-monospace" rows="7" placeholder="06504802440&#10;12547457444&#10;065.048.024-40"></textarea>
                                <div id="lista_cpfs_contador" class="text-muted small mt-1">0 CPFs informados</div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-end mb-3">
                <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2"></i> Gerenciador de Lotes e Exportação</h5>
                <div class="d-flex gap-2">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-dark text-white"><i class="fas fa-search"></i></span>
                        <input type="text" id="pesquisa_lote" class="form-control border-dark" placeholder="Pesquisar agrupamento..." onkeyup="pmFiltrarTabelaLotes()">
                    </div>
                    <button class="btn btn-warning btn-sm text-dark fw-bold shadow-sm border-dark text-nowrap" onclick="pmForcarProcessamento()"><i class="fas fa-bolt"></i> Destravar Fila</button>
                    <button class="btn btn-dark btn-sm fw-bold shadow-sm text-nowrap" onclick="pmCarregarLotes()"><i class="fas fa-sync"></i> Atualizar Tabela</button>
                </div>
            </div>
            
            <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover table-bordered align-middle mb-0 text-center" style="font-size: 14px;" id="tabela_gerenciador_lotes">
                    <thead class="table-dark sticky-top">
                        <tr><th>Data Importação</th><th>Agrupamento / Cliente</th><th>Status Fila</th><th>Progresso (Sucesso / Erro)</th><th>Ações Exportação</th></tr>
                    </thead>
                    <tbody id="tbody_lotes" class="bg-white">
                        <tr><td colspan="5" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando lotes...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="clientes" role="tabpanel">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="text-dark fw-bold mb-0">Gestão de Clientes Financeiro</h4>
                <div class="input-group shadow-sm" style="width:340px;">
                    <span class="input-group-text bg-dark text-white border-dark"><i class="fas fa-search"></i></span>
                    <input type="text" id="pesquisa_cliente_inss" class="form-control border-dark" placeholder="Pesquisar por nome, CPF ou empresa..." onkeyup="pmBuscarClientes(this.value)">
                </div>
            </div>
            <div class="table-responsive shadow-sm border rounded">
                <table class="table table-striped table-hover align-middle mb-0" style="font-size:.85rem;">
                    <thead class="table-dark">
                        <tr><th>Nome</th><th>Empresa</th><th>Usuário</th><th>CPF</th><th>Custo (R$)</th><th>Saldo Atual (R$)</th><th>Grupo WhatsApp</th><th>Ações</th></tr>
                    </thead>
                    <tbody id="tabela_clientes" class="bg-white"></tbody>
                </table>
            </div>
            <div id="clientes_rodape" class="text-muted small mt-2"></div>
        </div>
        
        <div class="tab-pane fade" id="tokens" role="tabpanel">
            <h4 class="mb-4 text-dark fw-bold">Credenciais de Acesso (API Externa) e Regras</h4>
            <div class="alert alert-info fw-bold border-dark shadow-sm"><i class="fas fa-info-circle me-2"></i> O sistema externo gera um token dinâmico. Configure abaixo seu acesso e o tempo de Cache desejado (para não cobrar consultas repetidas no mesmo período).</div>
            <div class="row mt-3 g-3 justify-content-center">
                <div class="col-md-4">
                    <div class="card shadow-sm border-dark">
                        <div class="card-body bg-light">
                            <label class="fw-bold mb-2">Usuário API:</label>
                            <input type="text" id="tk_usuario" class="form-control border-dark mb-3 fw-bold">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-dark">
                        <div class="card-body bg-light">
                            <label class="fw-bold mb-2">Senha API:</label>
                            <input type="password" id="tk_senha" class="form-control border-dark mb-3 fw-bold">
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card shadow-sm border-dark border-2 border-primary">
                        <div class="card-body bg-light">
                            <label class="fw-bold mb-2 text-primary">Tempo de Cache (Dias):</label>
                            <input type="number" id="tk_cache" class="form-control border-primary mb-3 fw-bold text-center" placeholder="Ex: 30">
                        </div>
                    </div>
                </div>
                <div class="col-md-12 text-end">
                    <button class="btn btn-success fw-bold px-5 shadow-sm border-dark btn-salvar-credenciais"><i class="fas fa-save me-1"></i> Salvar Configurações</button>
                </div>
            </div>
        </div>
        
        <div class="tab-pane fade" id="robo" role="tabpanel"><h4 class="mb-4 text-dark fw-bold">Configuração do Robô (WhatsApp API)</h4><form id="form_robo" class="bg-light p-4 border rounded shadow-sm"><div class="row g-3 mb-4"><div class="col-md-3"><label class="fw-bold small">Comando Menu:</label><input type="text" id="cmd_menu" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Consulta:</label><input type="text" id="cmd_consulta" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Completo:</label><input type="text" id="cmd_completo" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Saldo:</label><input type="text" id="cmd_saldo" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Extrato:</label><input type="text" id="cmd_extrato" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Lista:</label><input type="text" id="cmd_lista" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Suporte:</label><input type="text" id="cmd_suporte" class="form-control border-secondary"></div></div><h6 class="fw-bold text-primary border-bottom pb-2 mb-3">Conexões API Externas</h6><div class="row g-3"><div class="col-md-4"><label class="fw-bold small">WAPI Instance:</label><input type="text" id="wapi_instance" class="form-control border-primary"></div><div class="col-md-4"><label class="fw-bold small">WAPI Token:</label><input type="text" id="wapi_token" class="form-control border-primary"></div><div class="col-md-4"><label class="fw-bold small">Token HIST INSS (Bot):</label><input type="text" id="token_robo" class="form-control border-primary"></div></div><div class="mt-4 text-end"><button type="submit" class="btn btn-success fw-bold px-5 shadow-sm border-dark"><i class="fas fa-save me-1"></i> Salvar Configurações</button></div></form></div>
    </div>
</div>

<div class="modal fade" id="modalEditCliente" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-sm"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold">Editar Cliente</h5><button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="edit_cli_cpf"><div class="form-group mb-3"><label class="fw-bold small">Custo por Consulta (R$):</label><input type="number" step="0.01" id="edit_cli_custo" class="form-control border-dark"></div><div class="form-group"><label class="fw-bold small">Grupo WhatsApp (ID):</label><input type="text" id="edit_cli_grupo" class="form-control border-dark"></div></div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary fechar-modal" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary fw-bold px-4" id="btn_salvar_cli">Salvar Alterações</button></div></div></div></div>
<div class="modal fade" id="modalSaldo" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-sm"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold"><i class="fas fa-coins me-2"></i> Movimentar Saldo</h5><button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="saldo_cli_cpf"><div class="form-group mb-3"><label class="fw-bold small">Tipo de Operação:</label><select id="saldo_tipo" class="form-select border-dark fw-bold"><option value="CREDITO" class="text-success">🟢 Adicionar Saldo (Crédito)</option><option value="DEBITO" class="text-danger">🔴 Remover Saldo (Débito)</option></select></div><div class="form-group mb-3"><label class="fw-bold small">Valor (R$):</label><input type="number" step="0.01" id="saldo_valor" class="form-control border-dark text-primary fw-bold fs-5"></div><div class="form-group"><label class="fw-bold small">Motivo / Observação:</label><input type="text" id="saldo_motivo" class="form-control border-dark" value="Recarga Manual"></div></div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary fechar-modal" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-success fw-bold px-4 shadow-sm" id="btn_salvar_saldo"><i class="fas fa-check"></i> Confirmar</button></div></div></div></div>
<div class="modal fade" id="modalExtrato" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-sm"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-list me-2"></i> Extrato do Cliente</h5><button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button></div><div class="modal-body" style="max-height: 500px; overflow-y: auto; padding:0;"><table class="table table-hover table-sm text-center mb-0 align-middle"><thead class="table-light sticky-top shadow-sm"><tr><th>Data</th><th>Tipo</th><th>Valor (R$)</th><th>Saldo Atual (R$)</th><th>Motivo</th></tr></thead><tbody id="tbody_extrato"></tbody></table></div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary fw-bold px-4 fechar-modal" data-bs-dismiss="modal">Fechar Extrato</button></div></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
$(document).ready(function() {
    const ajax_url = 'promosys_inss.ajax.php';
    let pmIntervaloLote = null;

    let _pmBuscaTimer = null;
    window.pmBuscarClientes = function(val) {
        clearTimeout(_pmBuscaTimer);
        _pmBuscaTimer = setTimeout(() => carregarClientes(val), 350);
    };

    function carregarClientes(busca = '') {
        $.post(ajax_url, { acao: 'listar_clientes', busca: busca }, function(res) {
            if(res.success) {
                let html = ''; 
                let options = '<option value="" disabled selected>-- Selecione o Cliente (Obrigatório) --</option>'; 
                let optionsLote = '<option value="" disabled selected>-- Selecione o Cliente (Obrigatório) --</option>';
                
                // ✨ REGRA: TRAVAR O SELECT PARA O USUÁRIO LOGADO ✨
                let cpfLogado = $('#sessao_cpf_logado').val();
                let grupoLogado = $('#sessao_grupo_logado').val().toUpperCase();
                let isAdmin = ['MASTER', 'ADMIN', 'ADMINISTRADOR'].includes(grupoLogado);
                
                res.data.forEach(c => {
                    if (!isAdmin && c.CPF !== cpfLogado) return; // Filtra se não for admin
                    
                    let isSelected = (c.CPF === cpfLogado) ? 'selected' : '';
                    
                    html += `<tr>
                        <td class="fw-bold">${c.NOME}</td>
                        <td class="text-muted small">${c.NOME_EMPRESA || '-'}</td>
                        <td class="text-muted small">${c.NOME_USUARIO || '-'}</td>
                        <td><code class="text-dark">${c.CPF}</code></td>
                        <td>R$ ${parseFloat(c.CUSTO_CONSULTA).toFixed(2)}</td>
                        <td><b class="${parseFloat(c.SALDO)<0?'text-danger':'text-success'}">R$ ${parseFloat(c.SALDO).toFixed(2)}</b></td>
                        <td class="small">${c.GRUPO_WHATS || '-'}</td>
                        <td class="text-nowrap">
                            <button class="btn btn-sm btn-outline-dark fw-bold btn-edit-cli me-1" data-cpf="${c.CPF}" data-custo="${c.CUSTO_CONSULTA}" data-grupo="${c.GRUPO_WHATS}"><i class="fas fa-edit"></i> Editar</button>
                            <button class="btn btn-sm btn-primary fw-bold shadow-sm btn-saldo-cli me-1" data-cpf="${c.CPF}"><i class="fas fa-coins"></i> Saldo</button>
                            <button class="btn btn-sm btn-warning text-dark fw-bold shadow-sm btn-extrato-cli" data-cpf="${c.CPF}"><i class="fas fa-file-alt"></i> Extrato</button>
                        </td>
                    </tr>`;
                    
                    options += `<option value="${c.CPF}" ${isSelected}>${c.NOME} (Saldo: R$ ${parseFloat(c.SALDO).toFixed(2)})</option>`;
                    optionsLote += `<option value="${c.CPF}" ${isSelected}>${c.NOME} (Custo: R$ ${parseFloat(c.CUSTO_CONSULTA).toFixed(2)} | Saldo: R$ ${parseFloat(c.SALDO).toFixed(2)})</option>`;
                });
                
                $('#tabela_clientes').html(html || '<tr><td colspan="8" class="py-4 text-center text-muted">Nenhum cliente encontrado.</td></tr>');
                const total = res.data.length;
                $('#clientes_rodape').text(total === 30 ? 'Exibindo os primeiros 30 registros. Use a busca para filtrar.' : `${total} cliente(s) encontrado(s).`);
                $('#cliente_cobrar').html(options);
                $('#lote_cliente_cobrar').html(optionsLote);

                // Bloqueia a caixa caso não seja um administrador
                if (!isAdmin) {
                    $('#cliente_cobrar').prop('disabled', true).addClass('bg-secondary text-white');
                    $('#lote_cliente_cobrar').prop('disabled', true).addClass('bg-secondary text-white');
                }
            }
        }, 'json');
    }
    carregarClientes();

    $('#btn_consultar').click(function() { 
        let cpf = $('#cpf_consulta').val(); 
        let cpf_cobrar = $('#cliente_cobrar').val(); 
        let forcar = $('#forcar_api').is(':checked') ? 1 : 0; 
        
        if(!cpf) { crmToast("Digite o CPF!", "info", 5000); return; } 
        if(!cpf_cobrar) { crmToast("É obrigatório selecionar de qual cliente será cobrada a consulta!", "info", 5000); return; } 

        $(this).html('<i class="fa fa-spinner fa-spin"></i> Consultando...').prop('disabled', true); 
        $('#resultado_consulta').hide(); 

        $.post(ajax_url, { acao: 'consulta_cpf_manual', cpf: cpf, cpf_cobrar: cpf_cobrar, forcar_api: forcar }, function(res) { 
            $('#btn_consultar').html('<i class="fa fa-search me-1"></i> Consultar').prop('disabled', false); 
            if(res.success) { 
                let msg = `<b>Sucesso!</b> Origem: ${res.origem} | Nome: ${res.dados.nome}`; 
                if(res.cobranca) { msg += `<br>Cobrança: R$ ${res.cobranca.custo} | Saldo Atual do Cliente: R$ ${res.cobranca.saldo_atual}`; } 
                $('#msg_retorno_consulta').removeClass('alert-danger').addClass('alert-success').html(msg); 
                $('#json_retorno_consulta').text(JSON.stringify(res.json_bruto, null, 4)); 
                $('#resultado_consulta').fadeIn(); 
                carregarClientes(); 
            } else { 
                $('#msg_retorno_consulta').removeClass('alert-success').addClass('alert-danger').html(`<b>Erro:</b> ${res.msg}`); 
                $('#json_retorno_consulta').text(''); 
                $('#resultado_consulta').fadeIn(); 
            } 
        }, 'json').fail(function() { 
            crmToast("❌ Erro de comunicação.", "error", 6000); 
            $('#btn_consultar').html('<i class="fa fa-search me-1"></i> Consultar').prop('disabled', false); 
        }); 
    });

    $(document).on('click', '.btn-edit-cli', function() { $('#edit_cli_cpf').val($(this).data('cpf')); $('#edit_cli_custo').val($(this).data('custo')); $('#edit_cli_grupo').val($(this).data('grupo')); $('#modalEditCliente').modal('show'); }); $('#btn_salvar_cli').click(function() { $.post(ajax_url, { acao: 'salvar_dados_cliente', cpf: $('#edit_cli_cpf').val(), custo: $('#edit_cli_custo').val(), grupo: $('#edit_cli_grupo').val() }, function(res) { if(res.success) { $('#modalEditCliente').modal('hide'); carregarClientes(); } else { crmToast(res.msg, res.success === false ? "error" : "info"); } }, 'json'); }); $(document).on('click', '.btn-saldo-cli', function() { $('#saldo_cli_cpf').val($(this).data('cpf')); $('#saldo_valor').val(''); $('#modalSaldo').modal('show'); }); $('#btn_salvar_saldo').click(function() { $.post(ajax_url, { acao: 'movimentar_saldo', cpf: $('#saldo_cli_cpf').val(), tipo: $('#saldo_tipo').val(), valor: $('#saldo_valor').val(), motivo: $('#saldo_motivo').val() }, function(res) { if(res.success) { crmToast(res.msg, res.success === false ? "error" : "info"); $('#modalSaldo').modal('hide'); carregarClientes(); } else { crmToast(res.msg, res.success === false ? "error" : "info"); } }, 'json'); }); $(document).on('click', '.btn-extrato-cli', function() { let cpf = $(this).data('cpf'); $('#tbody_extrato').html('<tr><td colspan="5" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando...</td></tr>'); $('#modalExtrato').modal('show'); $.post(ajax_url, { acao: 'carregar_extrato', cpf: cpf }, function(res) { if(res.success) { let html = ''; res.data.forEach(e => { let badge = e.TIPO === 'CREDITO' ? '<span class="badge bg-success">CREDITO</span>' : '<span class="badge bg-danger">DEBITO</span>'; let corValor = e.TIPO === 'CREDITO' ? 'text-success' : 'text-danger'; let sinal = e.TIPO === 'CREDITO' ? '+' : '-'; html += `<tr class="border-bottom"><td class="small text-muted fw-bold">${e.DATA_FORMATADA}</td><td>${badge}</td><td class="${corValor} fw-bold">${sinal} R$ ${parseFloat(e.VALOR).toFixed(2)}</td><td class="fw-bold text-dark">R$ ${parseFloat(e.SALDO_ATUAL).toFixed(2)}</td><td class="small text-start">${e.MOTIVO}</td></tr>`; }); if(html === '') html = '<tr><td colspan="5" class="py-4 fw-bold">Nenhuma movimentação registrada.</td></tr>'; $('#tbody_extrato').html(html); } }, 'json'); }); $('.fechar-modal').click(function() { $(this).closest('.modal').modal('hide'); });

    window.pmCarregarLotes = function() {
        $('#tbody_lotes').html('<tr><td colspan="5" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Buscando histórico de lotes...</td></tr>');
        $.post(ajax_url, { acao: 'listar_lotes_csv' }, function(res) {
            if(res.success) {
                let html = ''; let temAlgumRodando = false;
                if(res.data.length === 0) { html = '<tr><td colspan="5" class="py-4 text-muted fw-bold">Nenhum lote importado ainda.</td></tr>'; } else {
                    res.data.forEach(l => {
                        let btnExport = `<button class="btn btn-sm btn-primary fw-bold shadow-sm w-100 mb-1" onclick="window.open('${ajax_url}?acao=exportar_csv_agrupamento&id_lote=${l.ID}', '_blank')"><i class="fas fa-file-excel"></i> Exportar Dados</button>`; 
                        let btnDelete = `<button class="btn btn-sm btn-outline-danger shadow-sm w-100" onclick="pmExcluirLote(${l.ID}, '${l.NOME_PROCESSAMENTO}')"><i class="fas fa-trash-alt"></i> Apagar Lote e Histórico</button>`; 
                        let btnPausar = '';
                        let badgeStatus = `<span class="badge bg-secondary border border-dark">PENDENTE</span>`; 
                        let barClass = "bg-secondary"; let rowClass = ""; 
                        
                        let progressoNum = parseInt(l.QTD_TOTAL) > 0 ? Math.round(((parseInt(l.QTD_ATUALIZADO) + parseInt(l.QTD_NAO_ATUALIZADO)) / parseInt(l.QTD_TOTAL)) * 100) : 0;
                        let processadosReal = parseInt(l.QTD_ATUALIZADO) + parseInt(l.QTD_NAO_ATUALIZADO);

                        if (l.STATUS_FILA === 'PROCESSANDO' || l.STATUS_FILA === 'PENDENTE') { 
                            if(l.STATUS_FILA === 'PROCESSANDO') {
                                temAlgumRodando = true; 
                                badgeStatus = `<span class="badge bg-warning text-dark border border-dark shadow-sm"><i class="fas fa-cog fa-spin"></i> PROCESSANDO</span>`; 
                                barClass = "bg-warning progress-bar-striped progress-bar-animated text-dark"; rowClass = "table-warning"; 
                            } else {
                                badgeStatus = `<span class="badge bg-secondary border border-dark shadow-sm">PENDENTE</span>`;
                            }
                            btnExport = `<button class="btn btn-sm btn-secondary fw-bold shadow-sm w-100 mb-1" disabled><i class="fas fa-ban"></i> Exportar (Aguarde...)</button>`; 
                            btnPausar = `<button class="btn btn-sm btn-warning text-dark fw-bold shadow-sm w-100 mb-1" onclick="pmPausarRetomarLote(${l.ID}, 'PAUSAR')"><i class="fas fa-pause"></i> Pausar Lote</button>`;
                        
                        } else if (l.STATUS_FILA === 'PAUSADO') {
                            badgeStatus = `<span class="badge bg-danger border border-dark shadow-sm"><i class="fas fa-pause"></i> PAUSADO</span>`;
                            btnExport = `<button class="btn btn-sm btn-primary fw-bold shadow-sm w-100 mb-1" onclick="window.open('${ajax_url}?acao=exportar_csv_agrupamento&id_lote=${l.ID}', '_blank')"><i class="fas fa-file-excel"></i> Exportar Parcial</button>`;
                            btnPausar = `<button class="btn btn-sm btn-success fw-bold shadow-sm w-100 mb-1" onclick="pmPausarRetomarLote(${l.ID}, 'RETOMAR')"><i class="fas fa-play"></i> Retomar Lote</button>`;

                        } else if (l.STATUS_FILA === 'CONCLUIDO') { 
                            badgeStatus = `<span class="badge bg-success border border-dark shadow-sm"><i class="fas fa-check-double"></i> CONCLUÍDO</span>`; 
                            barClass = "bg-success"; progressoNum = 100; 
                        }

                        let progressHtml = `
                            <div class="d-flex justify-content-between small fw-bold mb-1"><span>${processadosReal} de ${l.QTD_TOTAL}</span><span>${progressoNum}%</span></div>
                            <div class="progress shadow-sm" style="height: 15px;">
                                <div class="progress-bar ${barClass} fw-bold" role="progressbar" style="width: ${progressoNum}%;" aria-valuenow="${progressoNum}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        `;
                        let colQtd = `<span class="text-success fw-bold">${l.QTD_ATUALIZADO} OK</span> <br> <span class="text-danger fw-bold">${l.QTD_NAO_ATUALIZADO} Erros</span>`;

                        html += `<tr class="${rowClass} border-bottom border-secondary">
                                    <td class="fw-bold text-muted small">${l.DATA_BR}</td>
                                    <td class="text-start"><b class="text-dark fs-6">${l.NOME_PROCESSAMENTO}</b><br><small class="text-primary">Cliente: ${l.NOME_CLIENTE || 'Sistema'}</small></td>
                                    <td>${badgeStatus}</td>
                                    <td class="align-middle px-3" style="width: 25%;">
                                        ${progressHtml}
                                        <div class="mt-2" style="font-size: 11px;">${colQtd}</div>
                                    </td>
                                    <td class="p-2">${btnPausar}${btnExport}${btnDelete}</td>
                                </tr>`;
                    });
                }
                $('#tbody_lotes').html(html);
                if(temAlgumRodando) { if(!pmIntervaloLote) pmIntervaloLote = setInterval(pmCarregarLotes, 5000); } else { if(pmIntervaloLote) { clearInterval(pmIntervaloLote); pmIntervaloLote = null; carregarClientes(); } }
            } else { $('#tbody_lotes').html(`<tr><td colspan="5" class="py-4 text-danger fw-bold">Erro: ${res.msg}</td></tr>`); }
        }, 'json');
    }

    window.pmFiltrarTabelaLotes = function() { var input = document.getElementById("pesquisa_lote"); var filter = input.value.toUpperCase(); var table = document.getElementById("tabela_gerenciador_lotes"); var tr = table.getElementsByTagName("tr"); for (var i = 1; i < tr.length; i++) { var tdCode = tr[i].getElementsByTagName("td")[1]; if (tdCode) { var txtValue = tdCode.textContent || tdCode.innerText; if (txtValue.toUpperCase().indexOf(filter) > -1) { tr[i].style.display = ""; } else { tr[i].style.display = "none"; } } } }

    $('#form_upload_lote').submit(function(e) {
        e.preventDefault();
        let agrup = $('#lote_agrupamento').val();
        let clienteCobrar = $('#lote_cliente_cobrar').val();
        let file = $('#lote_arquivo')[0].files[0];
        
        if(!agrup || !file) return crmToast("Preencha o Agrupamento e selecione o CSV.", "info", 5000);
        if(!clienteCobrar) return crmToast("Selecione qual cliente pagará pelos cadastros validados deste lote.", "info", 5000);
        if(file.type !== 'text/csv' && !file.name.endsWith('.csv')) return crmToast("O arquivo deve ser obrigatoriamente .csv!", "info", 5000);

        $('#btn_enviar_lote').html('<i class="fas fa-spinner fa-spin"></i> Enviando...').prop('disabled', true);
        
        let formData = new FormData();
        formData.append('acao', 'upload_csv_lote');
        formData.append('agrupamento', agrup);
        formData.append('cpf_cobrar', clienteCobrar);
        formData.append('arquivo_csv', file);

        $.ajax({
            url: ajax_url, type: 'POST', data: formData, contentType: false, processData: false, dataType: 'json',
            success: function(res) {
                $('#btn_enviar_lote').html('<i class="fas fa-play me-1"></i> Iniciar Importação').prop('disabled', false);
                if(res.success) {
                    $('#form_upload_lote')[0].reset();
                    crmToast("✅ Lote enviado com sucesso! O processamento já entrou na fila e acontecerá em segundo plano. Só haverá cobrança quando o lote finalizar.", "success");
                    pmCarregarLotes(); 
                } else { crmToast("❌ " + res.msg, "error", 6000); }
            },
            error: function(err) { $('#btn_enviar_lote').html('<i class="fas fa-play me-1"></i> Iniciar Importação').prop('disabled', false); crmToast("❌ Erro de comunicação.", "error", 6000); }
        });
    });

    // Toggle CSV / Lista CPFs
    window.pmModoImport = function(modo) {
        if (modo === 'csv') {
            $('#form_upload_lote').css('display', '');
            $('#form_lista_cpfs').css('display', 'none !important').hide();
            $('#btn_modo_csv').removeClass('btn-outline-primary').addClass('btn-primary');
            $('#btn_modo_lista').removeClass('btn-primary').addClass('btn-outline-primary');
        } else {
            $('#form_upload_lote').hide();
            $('#form_lista_cpfs').css('display', '').show();
            $('#btn_modo_lista').removeClass('btn-outline-primary').addClass('btn-primary');
            $('#btn_modo_csv').removeClass('btn-primary').addClass('btn-outline-primary');
            // Copia clientes para o select da lista
            $('#lista_cliente_cobrar').html($('#lote_cliente_cobrar').html());
        }
    };

    // Contador de CPFs digitados
    $(document).on('input', '#lista_cpfs_inss', function() {
        const linhas = $(this).val().split('\n').filter(l => preg_replace_js(l.trim()).length === 11);
        $('#lista_cpfs_contador').text(linhas.length + ' CPFs válidos informados');
    });
    function preg_replace_js(s) { return s.replace(/\D/g,''); }

    // Enviar lista de CPFs
    window.pmEnviarLista = function() {
        const agrup = $('#lista_agrupamento').val().trim();
        const cobrar = $('#lista_cliente_cobrar').val();
        const lista  = $('#lista_cpfs_inss').val().trim();
        if (!agrup) return crmToast("Informe o Agrupamento.", "info", 4000);
        if (!cobrar) return crmToast("Selecione o cliente para cobrança.", "info", 4000);
        if (!lista)  return crmToast("Informe ao menos um CPF.", "info", 4000);
        $('#btn_enviar_lista').html('<i class="fas fa-spinner fa-spin"></i> Enviando...').prop('disabled', true);
        $.post(ajax_url, { acao: 'lote_por_lista_cpfs', agrupamento: agrup, cpf_cobrar: cobrar, lista_cpfs: lista }, function(res) {
            $('#btn_enviar_lista').html('<i class="fas fa-play me-1"></i> Iniciar Verificação').prop('disabled', false);
            if (res.success) {
                $('#lista_cpfs_inss').val(''); $('#lista_agrupamento').val(''); $('#lista_cpfs_contador').text('0 CPFs informados');
                crmToast("✅ Lote enviado! Processamento em fila.", "success");
                pmCarregarLotes();
            } else { crmToast("❌ " + res.msg, "error", 6000); }
        }, 'json').fail(() => { $('#btn_enviar_lista').html('<i class="fas fa-play me-1"></i> Iniciar Verificação').prop('disabled', false); crmToast("❌ Erro de comunicação.", "error", 6000); });
    };

    window.pmExcluirLote = function(id_lote, agrupamento) { 
        if(!confirm(`Atenção: Isso excluirá o registro do lote [${agrupamento}].\nOs clientes validados NÃO serão apagados do sistema principal.\n\nConfirma a exclusão do Histórico?`)) return; 
        $.post(ajax_url, { acao: 'excluir_lote_csv', id: id_lote }, function(res) { if(res.success) { crmToast("✅ " + res.msg, "success"); pmCarregarLotes(); } else { crmToast("❌ " + res.msg, "error", 6000); } }, 'json'); 
    }

    window.pmForcarProcessamento = function() {
        $.post(ajax_url, { acao: 'forcar_processamento_lote' }, function(res) { if(res.success) { crmToast("✅ " + res.msg, "success"); pmCarregarLotes(); } else { crmToast("❌ " + res.msg, "error", 6000); } }, 'json');
    }

    window.pmPausarRetomarLote = function(idLote, acaoLote) {
        $.post(ajax_url, { acao: 'pausar_retomar_lote', id_lote: idLote, acao_lote: acaoLote }, function(res) { if(res.success) { pmCarregarLotes(); } else { crmToast("❌ " + res.msg, "error", 6000); } }, 'json');
    }

    function carregarTokens() { 
        $.post(ajax_url, { acao: 'carregar_tokens_abas' }, function(res) { 
            if(res.success && res.data) { 
                $('#tk_usuario').val(res.data.TOKEN_MANUAL); 
                $('#tk_senha').val(res.data.TOKEN_LOTE); 
                $('#tk_cache').val(res.data.TEMPO_CACHE);
            } 
        }, 'json'); 
    } 
    carregarTokens(); 
    
    $('.btn-salvar-credenciais').click(function() { 
        let usr = $('#tk_usuario').val(); 
        let pwd = $('#tk_senha').val();
        let cache = $('#tk_cache').val();
        $.post(ajax_url, { acao: 'salvar_credenciais', usuario: usr, senha: pwd, tempo_cache: cache }, function(res) { 
            crmToast(res.msg, res.success === false ? "error" : "info"); 
        }, 'json'); 
    });

    function carregarConfigRobo() { $.post(ajax_url, { acao: 'carregar_config_robo' }, function(res) { if(res.success && res.data) { $('#cmd_menu').val(res.data.CMD_MENU); $('#cmd_consulta').val(res.data.CMD_CONSULTA); $('#cmd_completo').val(res.data.CMD_COMPLETO); $('#cmd_saldo').val(res.data.CMD_SALDO); $('#cmd_extrato').val(res.data.CMD_EXTRATO); $('#cmd_lista').val(res.data.CMD_LISTA); $('#cmd_suporte').val(res.data.CMD_SUPORTE); $('#wapi_instance').val(res.data.WAPI_INSTANCE); $('#wapi_token').val(res.data.WAPI_TOKEN); $('#token_robo').val(res.data.TOKEN_ROBO); } }, 'json'); } carregarConfigRobo(); $('#form_robo').submit(function(e) { e.preventDefault(); let dados = { acao: 'salvar_config_robo', menu: $('#cmd_menu').val(), consulta: $('#cmd_consulta').val(), completo: $('#cmd_completo').val(), saldo: $('#cmd_saldo').val(), extrato: $('#cmd_extrato').val(), lista: $('#cmd_lista').val(), suporte: $('#cmd_suporte').val(), wapi_instance: $('#wapi_instance').val(), wapi_token: $('#wapi_token').val(), token_robo: $('#token_robo').val() }; $.post(ajax_url, dados, function(res) { crmToast(res.msg, res.success === false ? "error" : "info"); }, 'json'); });
});
</script>

<?php $path_footer = '../../../includes/footer.php'; if(file_exists($path_footer)) { include_once $path_footer; } ?>