<?php
session_start();
$path_header = '../../../includes/header.php';
if(file_exists($path_header)) { include_once $path_header; }

require_once '../../../conexao.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

$perm_saldo_editar = function_exists('verificaPermissao') ? (verificaPermissao($pdo, 'SUBMENU_OP_FATOR_CONFERI_SALDO_EDITAR', 'FUNCAO') ? 'true' : 'false') : 'true';

// =========================================================================
// NOVA LÓGICA DE BLOQUEIO VISUAL (Consulta direta ao Banco de Dados)
// =========================================================================
$grupo_usuario_tela = strtoupper($_SESSION['usuario_grupo'] ?? '');
$cpf_logado_tela = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');

if (empty($grupo_usuario_tela) && !empty($cpf_logado_tela)) {
    try {
        $stmtG = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO WHERE CPF = ?");
        $stmtG->execute([$cpf_logado_tela]);
        $grupo_usuario_tela = strtoupper(trim((string)$stmtG->fetchColumn()));
    } catch (Exception $e) {}
}

$perm_token = true;
$perm_robo  = true;

if (!empty($grupo_usuario_tela) && !in_array($grupo_usuario_tela, ['MASTER', 'ADMIN', 'ADMINISTRADOR'])) {
    try {
        $stmtTk = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_TOKEN'");
        $stmtTk->execute();
        $regTk = $stmtTk->fetch(PDO::FETCH_ASSOC);
        if ($regTk && !empty($regTk['GRUPO_USUARIOS'])) {
            $grupos_bloqueados_tk = array_map('trim', explode(',', strtoupper($regTk['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario_tela, $grupos_bloqueados_tk)) { $perm_token = false; }
        }

        $stmtRb = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_FATOR_CONFERI_ROBO'");
        $stmtRb->execute();
        $regRb = $stmtRb->fetch(PDO::FETCH_ASSOC);
        if ($regRb && !empty($regRb['GRUPO_USUARIOS'])) {
            $grupos_bloqueados_rb = array_map('trim', explode(',', strtoupper($regRb['GRUPO_USUARIOS'])));
            if (in_array($grupo_usuario_tela, $grupos_bloqueados_rb)) { $perm_robo = false; }
        }
    } catch (Exception $e) {}
}
// =========================================================================
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
    <h2><i class="fa fa-cogs text-primary"></i> Módulo Atualização Cadastral</h2>
    <hr>

    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation"><button class="nav-link active" id="consulta-tab" data-bs-toggle="tab" data-bs-target="#consulta" type="button" role="tab"><i class="fas fa-search"></i> Consulta Manual</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link text-success" id="lote-tab" data-bs-toggle="tab" data-bs-target="#lote" type="button" role="tab" onclick="fcCarregarLotes()"><i class="fas fa-list-ol"></i> Consulta em Lote</button></li>
        <li class="nav-item" role="presentation"><button class="nav-link" id="clientes-tab" data-bs-toggle="tab" data-bs-target="#clientes" type="button" role="tab"><i class="fas fa-users"></i> Clientes & Saldo</button></li>
        
        <?php if ($perm_token): ?>
        <li class="nav-item" role="presentation"><button class="nav-link" id="tokens-tab" data-bs-toggle="tab" data-bs-target="#tokens" type="button" role="tab"><i class="fas fa-key"></i> Tokens API</button></li>
        <?php endif; ?>
        
        <?php if ($perm_robo): ?>
        <li class="nav-item" role="presentation"><button class="nav-link" id="robo-tab" data-bs-toggle="tab" data-bs-target="#robo" type="button" role="tab"><i class="fas fa-robot"></i> Configuração Robô</button></li>
        <?php endif; ?>
    </ul>

    <div class="tab-content shadow-sm" id="myTabContent" style="background: #fff; padding: 25px; border: 1px solid #dee2e6; border-top: none; border-radius: 0 0 8px 8px;">
        
        <div class="tab-pane fade show active" id="consulta" role="tabpanel">
            <h4 class="mb-4 text-dark fw-bold">Consulta Individual de CPF</h4>
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
                        <h5 class="fw-bold text-primary mb-3"><i class="fas fa-paste me-2"></i>Consulta Massiva Atualização Cadastral</h5>
                        <p class="text-muted small mb-4">Cole a lista de CPFs abaixo. O sistema limpa formatações automaticamente.<br><strong class="text-danger">Atenção: Limite máximo de 2.000 CPFs por lote.</strong></p>
                        
                        <form id="form_upload_lote" class="row justify-content-center g-3">
                            <div class="col-md-6 text-start">
                                <label class="fw-bold small text-dark">Identificação / Agrupamento:</label>
                                <input type="text" name="agrupamento" id="lote_agrupamento" class="form-control border-primary" placeholder="Ex: LOTE_INSS_MAIO" required>
                            </div>
                            <div class="col-md-6 text-start">
                                <label class="fw-bold small text-dark text-danger">Cobrar Lote de qual cliente?</label>
                                <select id="lote_cliente_cobrar" name="cpf_cobrar" class="form-select border-danger" required><option value="" disabled selected>-- Carregando... --</option></select>
                            </div>
                            <div class="col-md-12 text-start">
                                <label class="fw-bold small text-dark">Lista de CPFs (Um por linha):</label>
                                <textarea id="lista_cpfs" class="form-control border-primary" rows="6" placeholder="06504802440&#10;12547457444&#10;065.048.024-40" required></textarea>
                            </div>
                            <div class="col-md-4 mt-3">
                                <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm py-2" id="btn_enviar_lote"><i class="fas fa-play me-1"></i> Iniciar Verificação</button>
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
                        <input type="text" id="pesquisa_lote" class="form-control border-dark" placeholder="Pesquisar agrupamento..." onkeyup="fcFiltrarTabelaLotes()">
                    </div>
                    <button class="btn btn-warning btn-sm text-dark fw-bold shadow-sm border-dark text-nowrap" onclick="fcForcarProcessamento()"><i class="fas fa-bolt"></i> Destravar Fila</button>
                    <button class="btn btn-dark btn-sm fw-bold shadow-sm text-nowrap" onclick="fcCarregarLotes()"><i class="fas fa-sync"></i> Atualizar Tabela</button>
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="text-dark fw-bold mb-0">Gestão de Clientes Financeiro</h4>
                <div class="input-group shadow-sm" style="width: 350px;">
                    <span class="input-group-text bg-dark text-white border-dark"><i class="fas fa-search"></i></span>
                    <input type="text" id="pesquisa_cliente" class="form-control border-dark" placeholder="Pesquisar por nome do cliente..." onkeyup="fcFiltrarTabelaClientes()">
                </div>
            </div>
            <div class="table-responsive shadow-sm border rounded" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-striped table-hover align-middle mb-0 text-center" id="tabela_clientes_completa">
                    <thead class="table-dark sticky-top">
                        <tr>
                            <th class="text-start">Nome</th>
                            <th>CPF</th>
                            <th>Custo (R$)</th>
                            <th>Saldo Atual (R$)</th>
                            <th>Grupo WhatsApp</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tabela_clientes" class="bg-white text-start">
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($perm_token): ?>
        <div class="tab-pane fade" id="tokens" role="tabpanel"><h4 class="mb-4 text-dark fw-bold">Gerenciamento de Tokens Atualização Cadastral</h4><div class="row mt-3 g-3"><div class="col-md-4"><div class="card shadow-sm border-dark"><div class="card-body text-center bg-light"><label class="fw-bold mb-2">Token Manual (Painel):</label><input type="text" id="tk_manual" class="form-control border-dark mb-3 text-center fw-bold"><button class="btn btn-success btn-sm w-100 fw-bold btn-salvar-token" data-mod="manual"><i class="fas fa-save me-1"></i> Salvar Manual</button></div></div></div><div class="col-md-4"><div class="card shadow-sm border-dark"><div class="card-body text-center bg-light"><label class="fw-bold mb-2">Token Lote (API Automática):</label><input type="text" id="tk_lote" class="form-control border-dark mb-3 text-center fw-bold"><button class="btn btn-success btn-sm w-100 fw-bold btn-salvar-token" data-mod="lote"><i class="fas fa-save me-1"></i> Salvar Lote</button></div></div></div><div class="col-md-4"><div class="card shadow-sm border-primary"><div class="card-body text-center bg-light border border-primary rounded"><label class="fw-bold mb-2 text-primary">Token CSV (Fila Em Massa):</label><input type="text" id="tk_csv" class="form-control border-primary mb-3 text-center fw-bold"><button class="btn btn-primary btn-sm w-100 fw-bold btn-salvar-token" data-mod="csv"><i class="fas fa-save me-1"></i> Salvar Token CSV</button></div></div></div></div></div>
        <?php endif; ?>

        <?php if ($perm_robo): ?>
        <div class="tab-pane fade" id="robo" role="tabpanel"><h4 class="mb-4 text-dark fw-bold">Configuração do Robô (WhatsApp API)</h4><form id="form_robo" class="bg-light p-4 border rounded shadow-sm"><div class="row g-3 mb-4"><div class="col-md-3"><label class="fw-bold small">Comando Menu:</label><input type="text" id="cmd_menu" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Consulta:</label><input type="text" id="cmd_consulta" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Completo:</label><input type="text" id="cmd_completo" class="form-control border-secondary"></div><div class="col-md-3"><label class="fw-bold small">Comando Saldo:</label><input type="text" id="cmd_saldo" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Extrato:</label><input type="text" id="cmd_extrato" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Lista:</label><input type="text" id="cmd_lista" class="form-control border-secondary"></div><div class="col-md-4"><label class="fw-bold small">Comando Suporte:</label><input type="text" id="cmd_suporte" class="form-control border-secondary"></div></div><h6 class="fw-bold text-primary border-bottom pb-2 mb-3">Conexões API Externas</h6><div class="row g-3"><div class="col-md-4"><label class="fw-bold small">WAPI Instance:</label><input type="text" id="wapi_instance" class="form-control border-primary"></div><div class="col-md-4"><label class="fw-bold small">WAPI Token:</label><input type="text" id="wapi_token" class="form-control border-primary"></div><div class="col-md-4"><label class="fw-bold small">Token Fator (Bot):</label><input type="text" id="token_robo" class="form-control border-primary"></div></div><div class="mt-4 text-end"><button type="submit" class="btn btn-success fw-bold px-5 shadow-sm border-dark"><i class="fas fa-save me-1"></i> Salvar Configurações</button></div></form></div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="modalEditCliente" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-sm"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold">Editar Cliente</h5><button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="edit_cli_cpf"><div class="form-group mb-3"><label class="fw-bold small">Custo por Consulta (R$):</label><input type="number" step="0.01" id="edit_cli_custo" class="form-control border-dark"></div><div class="form-group"><label class="fw-bold small">Grupo WhatsApp (ID):</label><input type="text" id="edit_cli_grupo" class="form-control border-dark"></div></div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary fechar-modal" data-bs-dismiss="modal">Fechar</button><button type="button" class="btn btn-primary fw-bold px-4" id="btn_salvar_cli">Salvar Alterações</button></div></div></div></div>
<div class="modal fade" id="modalSaldo" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-sm"><div class="modal-header bg-primary text-white"><h5 class="modal-title fw-bold"><i class="fas fa-coins me-2"></i> Movimentar Saldo</h5><button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="saldo_cli_cpf"><div class="form-group mb-3"><label class="fw-bold small">Tipo de Operação:</label><select id="saldo_tipo" class="form-select border-dark fw-bold"><option value="CREDITO" class="text-success">🟢 Adicionar Saldo (Crédito)</option><option value="DEBITO" class="text-danger">🔴 Remover Saldo (Débito)</option></select></div><div class="form-group mb-3"><label class="fw-bold small">Valor (R$):</label><input type="number" step="0.01" id="saldo_valor" class="form-control border-dark text-primary fw-bold fs-5"></div><div class="form-group"><label class="fw-bold small">Motivo / Observação:</label><input type="text" id="saldo_motivo" class="form-control border-dark" value="Recarga Manual"></div></div><div class="modal-footer bg-light"><button type="button" class="btn btn-secondary fechar-modal" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-success fw-bold px-4 shadow-sm" id="btn_salvar_saldo"><i class="fas fa-check"></i> Confirmar</button></div></div></div></div>
<div class="modal fade" id="modalExtrato" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content border-dark shadow-sm">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-list me-2"></i> Extrato — Atualização Cadastral</h5>
                <button type="button" class="btn-close btn-close-white fechar-modal" data-bs-dismiss="modal"></button>
            </div>
            <div class="p-3 bg-white border-bottom">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="small fw-bold text-muted">Período:</label>
                        <select id="extrato_periodo" class="form-select form-select-sm border-dark fw-bold" onchange="toggleExtratoPersonalizado(this.value)">
                            <option value="HOJE" selected>Hoje</option>
                            <option value="MES">Mês Atual</option>
                            <option value="TODO">Todo o Período</option>
                            <option value="CUSTOM">Personalizado</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-none div-extrato-datas">
                        <label class="small fw-bold text-muted">Início:</label>
                        <input type="date" id="extrato_data_inicio" class="form-control form-control-sm border-dark">
                    </div>
                    <div class="col-md-3 d-none div-extrato-datas">
                        <label class="small fw-bold text-muted">Fim:</label>
                        <input type="date" id="extrato_data_fim" class="form-control form-control-sm border-dark">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-dark btn-sm w-100 fw-bold" onclick="aplicarFiltroExtrato()"><i class="fas fa-filter"></i> Filtrar</button>
                    </div>
                </div>
            </div>
            <div class="modal-body p-0" style="max-height: 450px; overflow-y: auto;">
                <table class="table table-hover table-sm text-center mb-0 align-middle">
                    <thead class="table-light sticky-top shadow-sm">
                        <tr><th>Data</th><th>Tipo</th><th>Valor (R$)</th><th>Saldo Atual (R$)</th><th>Motivo</th></tr>
                    </thead>
                    <tbody id="tbody_extrato"></tbody>
                </table>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary fw-bold px-4 fechar-modal" data-bs-dismiss="modal">Fechar Extrato</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

<script>
const canEditSaldo = <?= $perm_saldo_editar ?>;
const cpfLogado = '<?= $cpf_logado_tela ?>';

$(document).ready(function() {
    const ajax_url = 'fator_conferi.ajax.php';
    let fcIntervaloLote = null;

    function carregarClientes() {
        $.post(ajax_url, { acao: 'listar_clientes' }, function(res) {
            if(res.success) {
                let html = '';
                let options = '';
                let optionsLote = '';

                res.data.forEach(c => {
                    let btnAcoes = '';
                    if (canEditSaldo) {
                        btnAcoes += `<button class="btn btn-sm btn-outline-dark fw-bold btn-edit-cli me-1" data-cpf="${c.CPF}" data-custo="${c.CUSTO_CONSULTA}" data-grupo="${c.GRUPO_WHATS}"><i class="fas fa-edit"></i> Editar</button>`;
                        btnAcoes += `<button class="btn btn-sm btn-primary fw-bold shadow-sm btn-saldo-cli me-1" data-cpf="${c.CPF}"><i class="fas fa-coins"></i> Saldo</button>`;
                    }
                    btnAcoes += `<button class="btn btn-sm btn-warning text-dark fw-bold shadow-sm btn-extrato-cli" data-cpf="${c.CPF}"><i class="fas fa-file-alt"></i> Extrato</button>`;
                    html += `<tr><td>${c.NOME}</td><td class="text-center">${c.CPF}</td><td class="text-center">R$ ${parseFloat(c.CUSTO_CONSULTA).toFixed(2)}</td><td class="text-center"><b class="${parseFloat(c.SALDO)<0?'text-danger':'text-success'}">R$ ${parseFloat(c.SALDO).toFixed(2)}</b></td><td class="text-center">${c.GRUPO_WHATS || '-'}</td><td class="text-center">${btnAcoes}</td></tr>`;
                    options += `<option value="${c.CPF}">${c.NOME} (Saldo: R$ ${parseFloat(c.SALDO).toFixed(2)})</option>`;
                    optionsLote += `<option value="${c.CPF}">${c.NOME} (Custo: R$ ${parseFloat(c.CUSTO_CONSULTA).toFixed(2)} | Saldo: R$ ${parseFloat(c.SALDO).toFixed(2)})</option>`;
                });
                $('#tabela_clientes').html(html);
                $('#cliente_cobrar').html(options);
                $('#lote_cliente_cobrar').html(optionsLote);
                // Auto-seleciona o usuário logado
                if (cpfLogado) {
                    $('#cliente_cobrar').val(cpfLogado);
                    $('#lote_cliente_cobrar').val(cpfLogado);
                }
            }
        }, 'json');
    }
    carregarClientes();

    $('#btn_consultar').click(function() { 
        let cpf = $('#cpf_consulta').val(); let cpf_cobrar = $('#cliente_cobrar').val(); let forcar = $('#forcar_api').is(':checked') ? 1 : 0; 
        if(!cpf) { alert("Digite o CPF!"); return; } if(!cpf_cobrar) { alert("É obrigatório selecionar de qual cliente será cobrada a consulta!"); return; } 
        $(this).html('<i class="fa fa-spinner fa-spin"></i> Consultando...').prop('disabled', true); $('#resultado_consulta').hide(); 
        $.post(ajax_url, { acao: 'consulta_cpf_manual', cpf: cpf, cpf_cobrar: cpf_cobrar, forcar_api: forcar }, function(res) { 
            $('#btn_consultar').html('<i class="fa fa-search me-1"></i> Consultar').prop('disabled', false); 
            if(res.success) { 
                let msg = `<b>Sucesso!</b> Origem: ${res.origem} | Nome: ${res.dados.nome}`; 
                if(res.cobranca) { msg += `<br>Cobrança: R$ ${res.cobranca.custo} | Saldo Atual do Cliente: R$ ${res.cobranca.saldo_atual}`; } 
                $('#msg_retorno_consulta').removeClass('alert-danger').addClass('alert-success').html(msg); $('#json_retorno_consulta').text(JSON.stringify(res.json_bruto, null, 4)); $('#resultado_consulta').fadeIn(); carregarClientes(); 
            } else { $('#msg_retorno_consulta').removeClass('alert-success').addClass('alert-danger').html(`<b>Erro:</b> ${res.msg}`); $('#json_retorno_consulta').text(''); $('#resultado_consulta').fadeIn(); } 
        }, 'json').fail(function() { alert("Erro de comunicação."); $('#btn_consultar').html('<i class="fa fa-search me-1"></i> Consultar').prop('disabled', false); }); 
    });
    
    $(document).on('click', '.btn-edit-cli', function() { $('#edit_cli_cpf').val($(this).data('cpf')); $('#edit_cli_custo').val($(this).data('custo')); $('#edit_cli_grupo').val($(this).data('grupo')); $('#modalEditCliente').modal('show'); }); 
    $('#btn_salvar_cli').click(function() { $.post(ajax_url, { acao: 'salvar_dados_cliente', cpf: $('#edit_cli_cpf').val(), custo: $('#edit_cli_custo').val(), grupo: $('#edit_cli_grupo').val() }, function(res) { if(res.success) { $('#modalEditCliente').modal('hide'); carregarClientes(); } else { alert(res.msg); } }, 'json'); }); 
    $(document).on('click', '.btn-saldo-cli', function() { $('#saldo_cli_cpf').val($(this).data('cpf')); $('#saldo_valor').val(''); $('#modalSaldo').modal('show'); }); 
    $('#btn_salvar_saldo').click(function() { $.post(ajax_url, { acao: 'movimentar_saldo', cpf: $('#saldo_cli_cpf').val(), tipo: $('#saldo_tipo').val(), valor: $('#saldo_valor').val(), motivo: $('#saldo_motivo').val() }, function(res) { if(res.success) { alert(res.msg); $('#modalSaldo').modal('hide'); carregarClientes(); } else { alert(res.msg); } }, 'json'); }); 
    let cpfExtratoAtual = '';

    function carregarExtrato(cpf, dataInicio, dataFim) {
        $('#tbody_extrato').html('<tr><td colspan="5" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando...</td></tr>');
        $.post(ajax_url, { acao: 'carregar_extrato', cpf: cpf, data_inicio: dataInicio, data_fim: dataFim }, function(res) {
            if (res.success) {
                let html = '';
                res.data.forEach(e => {
                    let badge = e.TIPO === 'CREDITO' ? '<span class="badge bg-success">CRÉDITO</span>' : '<span class="badge bg-danger">DÉBITO</span>';
                    let corValor = e.TIPO === 'CREDITO' ? 'text-success' : 'text-danger';
                    let sinal = e.TIPO === 'CREDITO' ? '+' : '-';
                    html += `<tr class="border-bottom"><td class="small text-muted fw-bold">${e.DATA_FORMATADA}</td><td>${badge}</td><td class="${corValor} fw-bold">${sinal} R$ ${parseFloat(e.VALOR).toFixed(2)}</td><td class="fw-bold text-dark">R$ ${parseFloat(e.SALDO_ATUAL).toFixed(2)}</td><td class="small text-start">${e.MOTIVO}</td></tr>`;
                });
                if (html === '') html = '<tr><td colspan="5" class="py-4 fw-bold text-muted">Nenhuma movimentação neste período.</td></tr>';
                $('#tbody_extrato').html(html);
            }
        }, 'json');
    }

    function getExtratoDatas() {
        const periodo = $('#extrato_periodo').val();
        const hoje = new Date().toISOString().split('T')[0];
        if (periodo === 'HOJE')  return { inicio: hoje, fim: hoje };
        if (periodo === 'MES')  { const d = new Date(); return { inicio: `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-01`, fim: hoje }; }
        if (periodo === 'TODO') return { inicio: '2000-01-01', fim: '2099-12-31' };
        return { inicio: $('#extrato_data_inicio').val() || hoje, fim: $('#extrato_data_fim').val() || hoje };
    }

    window.toggleExtratoPersonalizado = function(val) {
        if (val === 'CUSTOM') { $('.div-extrato-datas').removeClass('d-none'); }
        else { $('.div-extrato-datas').addClass('d-none'); }
    };

    window.aplicarFiltroExtrato = function() {
        if (!cpfExtratoAtual) return;
        const d = getExtratoDatas();
        carregarExtrato(cpfExtratoAtual, d.inicio, d.fim);
    };

    $(document).on('click', '.btn-extrato-cli', function() {
        cpfExtratoAtual = $(this).data('cpf');
        $('#extrato_periodo').val('HOJE');
        $('.div-extrato-datas').addClass('d-none');
        const d = getExtratoDatas();
        $('#modalExtrato').modal('show');
        carregarExtrato(cpfExtratoAtual, d.inicio, d.fim);
    }); 
    $('.fechar-modal').click(function() { $(this).closest('.modal').modal('hide'); });

    window.fcCarregarLotes = function(isAuto = false) {
        if (!isAuto) {
            $('#tbody_lotes').html('<tr><td colspan="5" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Buscando histórico de lotes...</td></tr>');
        }
        $.post(ajax_url, { acao: 'listar_lotes_csv' }, function(res) {
            if(res.success) {
                let html = ''; let temAlgumRodando = false;
                if(res.data.length === 0) { html = '<tr><td colspan="5" class="py-4 text-muted fw-bold">Nenhum lote importado ainda.</td></tr>'; } else {
                    res.data.forEach(l => {
                        let btnExport = `<button class="btn btn-sm btn-primary fw-bold shadow-sm w-100 mb-1" onclick="window.open('${ajax_url}?acao=exportar_csv_agrupamento&id_lote=${l.ID}', '_blank')"><i class="fas fa-file-excel"></i> Exportar Dados</button>`; 
                        let btnDelete = `<button class="btn btn-sm btn-outline-danger shadow-sm w-100" onclick="fcExcluirLote(${l.ID}, '${l.NOME_PROCESSAMENTO}')"><i class="fas fa-trash-alt"></i> Apagar Lote e Histórico</button>`; 
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
                            btnPausar = `<button class="btn btn-sm btn-warning text-dark fw-bold shadow-sm w-100 mb-1" onclick="fcPausarRetomarLote(${l.ID}, 'PAUSAR')"><i class="fas fa-pause"></i> Pausar Lote</button>`;
                        
                        } else if (l.STATUS_FILA === 'PAUSADO') {
                            badgeStatus = `<span class="badge bg-danger border border-dark shadow-sm"><i class="fas fa-pause"></i> PAUSADO</span>`;
                            btnExport = `<button class="btn btn-sm btn-primary fw-bold shadow-sm w-100 mb-1" onclick="window.open('${ajax_url}?acao=exportar_csv_agrupamento&id_lote=${l.ID}', '_blank')"><i class="fas fa-file-excel"></i> Exportar Parcial</button>`;
                            btnPausar = `<button class="btn btn-sm btn-success fw-bold shadow-sm w-100 mb-1" onclick="fcPausarRetomarLote(${l.ID}, 'RETOMAR')"><i class="fas fa-play"></i> Retomar Lote</button>`;

                        } else if (l.STATUS_FILA === 'CONCLUIDO') { 
                            badgeStatus = `<span class="badge bg-success border border-dark shadow-sm"><i class="fas fa-check-double"></i> CONCLUÍDO</span>`; 
                            barClass = "bg-success"; progressoNum = 100; 
                            
                            if (parseInt(l.QTD_NAO_ATUALIZADO) > 0) {
                                btnPausar = `<button class="btn btn-sm btn-info text-dark fw-bold shadow-sm w-100 mb-1" onclick="fcReprocessarErros(${l.ID})"><i class="fas fa-redo"></i> Reprocessar Erros</button>`;
                            }
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
                if(temAlgumRodando) { 
                    if(!fcIntervaloLote) fcIntervaloLote = setInterval(function(){ fcCarregarLotes(true); }, 5000); 
                } else { 
                    if(fcIntervaloLote) { clearInterval(fcIntervaloLote); fcIntervaloLote = null; carregarClientes(); } 
                }
            } else { $('#tbody_lotes').html(`<tr><td colspan="5" class="py-4 text-danger fw-bold">Erro: ${res.msg}</td></tr>`); }
        }, 'json');
    }

    window.fcFiltrarTabelaClientes = function() { var input = document.getElementById("pesquisa_cliente"); var filter = input.value.toUpperCase(); var tbody = document.getElementById("tabela_clientes"); var tr = tbody.getElementsByTagName("tr"); for (var i = 0; i < tr.length; i++) { var tdNome = tr[i].getElementsByTagName("td")[0]; if (tdNome) { var txtValue = tdNome.textContent || tdNome.innerText; if (txtValue.toUpperCase().indexOf(filter) > -1) { tr[i].style.display = ""; } else { tr[i].style.display = "none"; } } } }
    window.fcFiltrarTabelaLotes = function() { var input = document.getElementById("pesquisa_lote"); var filter = input.value.toUpperCase(); var table = document.getElementById("tabela_gerenciador_lotes"); var tr = table.getElementsByTagName("tr"); for (var i = 1; i < tr.length; i++) { var tdCode = tr[i].getElementsByTagName("td")[1]; if (tdCode) { var txtValue = tdCode.textContent || tdCode.innerText; if (txtValue.toUpperCase().indexOf(filter) > -1) { tr[i].style.display = ""; } else { tr[i].style.display = "none"; } } } }

    $('#form_upload_lote').submit(function(e) {
        e.preventDefault();
        let agrup = $('#lote_agrupamento').val();
        let clienteCobrar = $('#lote_cliente_cobrar').val();
        let listaCpfs = $('#lista_cpfs').val();
        
        if(!agrup || !listaCpfs.trim()) return alert("Preencha o Agrupamento e cole a lista de CPFs.");
        if(!clienteCobrar) return alert("Selecione qual cliente pagará pelos cadastros validados deste lote.");

        $('#btn_enviar_lote').html('<i class="fas fa-spinner fa-spin"></i> Processando...').prop('disabled', true);
        
        $.post(ajax_url, { 
            acao: 'processar_lote_cpfs', 
            agrupamento: agrup, 
            cpf_cobrar: clienteCobrar, 
            lista_cpfs: listaCpfs 
        }, function(res) {
            $('#btn_enviar_lote').html('<i class="fas fa-play me-1"></i> Iniciar Verificação').prop('disabled', false);
            if(res.success) {
                $('#form_upload_lote')[0].reset();
                alert("✅ " + res.msg);
                fcCarregarLotes(); 
            } else { alert("❌ Erro ao enviar lote: " + res.msg); }
        }, 'json').fail(function() {
            $('#btn_enviar_lote').html('<i class="fas fa-play me-1"></i> Iniciar Verificação').prop('disabled', false); 
            alert("Erro de comunicação. Verifique a aba Network (F12).");
        });
    });

    window.fcExcluirLote = function(id_lote, agrupamento) { 
        if(!confirm(`Atenção: Isso excluirá o registro do lote [${agrupamento}].\nOs clientes validados NÃO serão apagados do sistema principal.\n\nConfirma a exclusão do Histórico?`)) return; 
        $.post(ajax_url, { acao: 'excluir_lote_csv', id: id_lote }, function(res) { if(res.success) { alert("✅ " + res.msg); fcCarregarLotes(); } else { alert("❌ Erro: " + res.msg); } }, 'json'); 
    }

    window.fcForcarProcessamento = function() {
        $.post(ajax_url, { acao: 'forcar_processamento_lote' }, function(res) { if(res.success) { alert("✅ " + res.msg); fcCarregarLotes(); } else { alert("❌ Erro: " + res.msg); } }, 'json');
    }

    window.fcPausarRetomarLote = function(idLote, acaoLote) {
        $.post(ajax_url, { acao: 'pausar_retomar_lote', id_lote: idLote, acao_lote: acaoLote }, function(res) { if(res.success) { fcCarregarLotes(); } else { alert("❌ Erro: " + res.msg); } }, 'json');
    }

    window.fcReprocessarErros = function(idLote) {
        if(!confirm('Deseja reenviar os CPFs que deram erro para a fila de processamento?')) return;
        $.post(ajax_url, { acao: 'reprocessar_erros_lote', id_lote: idLote }, function(res) {
            if(res.success) { alert("✅ " + res.msg); fcCarregarLotes(); } 
            else { alert("❌ Erro: " + res.msg); }
        }, 'json');
    }

    <?php if ($perm_token): ?>
    function carregarTokens() { $.post(ajax_url, { acao: 'carregar_tokens_abas' }, function(res) { if(res.success && res.data) { $('#tk_manual').val(res.data.TOKEN_MANUAL); $('#tk_lote').val(res.data.TOKEN_LOTE); $('#tk_csv').val(res.data.TOKEN_CSV); } }, 'json'); } carregarTokens(); $('.btn-salvar-token').click(function() { let mod = $(this).data('mod'); let tk = $('#tk_' + mod).val(); $.post(ajax_url, { acao: 'salvar_token_aba', mod: mod, token: tk }, function(res) { alert(res.msg); }, 'json'); });
    <?php endif; ?>

    <?php if ($perm_robo): ?>
    function carregarConfigRobo() { $.post(ajax_url, { acao: 'carregar_config_robo' }, function(res) { if(res.success && res.data) { $('#cmd_menu').val(res.data.CMD_MENU); $('#cmd_consulta').val(res.data.CMD_CONSULTA); $('#cmd_completo').val(res.data.CMD_COMPLETO); $('#cmd_saldo').val(res.data.CMD_SALDO); $('#cmd_extrato').val(res.data.CMD_EXTRATO); $('#cmd_lista').val(res.data.CMD_LISTA); $('#cmd_suporte').val(res.data.CMD_SUPORTE); $('#wapi_instance').val(res.data.WAPI_INSTANCE); $('#wapi_token').val(res.data.WAPI_TOKEN); $('#token_robo').val(res.data.TOKEN_FATOR); } }, 'json'); } carregarConfigRobo(); $('#form_robo').submit(function(e) { e.preventDefault(); let dados = { acao: 'salvar_config_robo', menu: $('#cmd_menu').val(), consulta: $('#cmd_consulta').val(), completo: $('#cmd_completo').val(), saldo: $('#cmd_saldo').val(), extrato: $('#cmd_extrato').val(), lista: $('#cmd_lista').val(), suporte: $('#cmd_suporte').val(), wapi_instance: $('#wapi_instance').val(), wapi_token: $('#wapi_token').val(), token_robo: $('#token_robo').val() }; $.post(ajax_url, dados, function(res) { alert(res.msg); }, 'json'); });
    <?php endif; ?>
});
</script>

<?php $path_footer = '../../../includes/footer.php'; if(file_exists($path_footer)) { include_once $path_footer; } ?>