<?php 
require_once '../../../includes/header.php'; 
require_once '../../../conexao.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

$perm_lote = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE', 'TELA');
$perm_chaves = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE_CUSTO', 'TELA'); 
$perm_extrato = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_EXTRATO', 'TELA');
$perm_digitar = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_DIGITAÇÃO', 'FUNCAO') ? 'true' : 'false';
$perm_fator = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_FATOR_CONFERI', 'FUNCAO') ? 'true' : 'false';

// REGRAS DE BLOQUEIO GERAL
$restricao_chave = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE', 'FUNCAO');
$restricao_custo_cliente = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CUSTO_CLIENTE', 'FUNCAO');
$restricao_custo_api = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CUSTO_API', 'FUNCAO');
$restricao_meu_usuario = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CHAVE_CUSTO_MEU_USUARIO', 'FUNCAO');

// REGRAS DE LOTE QUE SERÃO EXPORTADAS
$restricao_lote_editar = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_EDITAR', 'FUNCAO');
$restricao_lote_excluir = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_EXCLUIR', 'FUNCAO');

// REGRA DE BLOQUEIO DA ABA IA
$restricao_ia = false;
$grp_ia = '';
$id_sessao_ia = (int)($_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? 0);

if ($id_sessao_ia > 0) {
    $stmtDbGrp = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO WHERE ID = ? LIMIT 1");
    $stmtDbGrp->execute([$id_sessao_ia]);
    $grp_ia = $stmtDbGrp->fetchColumn();
}
if (empty($grp_ia)) { $grp_ia = $_SESSION['GRUPO_USUARIOS'] ?? $_SESSION['grupo_usuarios'] ?? $_SESSION['grupo'] ?? ''; }

if (!empty($grp_ia)) {
    $stmtPermIA = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_INTEGRACAO_V8_IA' AND UPPER(TRIM(GRUPO_USUARIOS)) = UPPER(TRIM(?))");
    $stmtPermIA->execute([$grp_ia]);
    if ($stmtPermIA->fetchColumn() > 0) { $restricao_ia = true; }
}

$perfil_ia = (int)($_SESSION['perfil'] ?? 0);
if ($perfil_ia === 1 || strtoupper(trim($grp_ia)) === 'MASTER' || strtoupper(trim($grp_ia)) === 'ADMIN') { $restricao_ia = false; }
?>

<style>
  .v8-loader-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.8); z-index: 9999; backdrop-filter: blur(2px); }
  .v8-loader-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; }
  .caixa-cobranca { border: 1px dashed #198754; background: #f8fff9; padding: 12px; border-radius: 6px; margin-bottom: 15px; }
  .table-fila th { background-color: #343a40; color: white; font-size: 0.75rem; text-transform: uppercase; text-align: center; vertical-align: middle; }
  .table-fila td { text-align: center; vertical-align: middle; }
  .filtro-avancado-box { background: #f8f9fa; border: 1px solid #ced4da; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: inset 0 1px 3px rgba(0,0,0,.05); }
</style>

<div id="v8-loader" class="v8-loader-overlay"><div class="v8-loader-content"><div class="spinner-border text-danger" style="width: 3rem; height: 3rem;" role="status"></div><h5 id="v8-loader-msg" class="mt-3 text-dark fw-bold">Aguarde...</h5></div></div>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-12 col-lg-11">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="text-dark fw-bold m-0"><i class="fas fa-handshake text-danger me-2"></i>API V8 DIGITAL - FLUXO AUTOMÁTICO</h3>
                
                <div class="border border-danger p-2 rounded bg-white shadow-sm text-start" style="font-size: 13px; min-width: 280px;">
                    <div class="text-danger fw-bold mb-1">R$ <span id="lbl_saldo_topo_v8">0,00</span> saldo integração v8</div>
                    <div class="text-danger fw-bold">R$ <span id="lbl_saldo_topo_cad">0,00</span> Saldo integração dados cad.</div>
                </div>
            </div>

            <div class="card shadow-sm border-0"><div class="card-body">
                
                <ul class="nav nav-pills mb-4 pb-2 border-bottom" id="pills-tab">
                    <li class="nav-item"><button class="nav-link active fw-bold" data-bs-toggle="pill" data-bs-target="#tab-consulta">Nova Consulta / Fila</button></li>
                    <li class="nav-item <?= !$perm_lote ? 'd-none' : '' ?>"><button class="nav-link text-success fw-bold" data-bs-toggle="pill" data-bs-target="#tab-lote-csv"><i class="fas fa-file-csv me-1"></i> Consulta em Lote (CSV)</button></li>
                    <li class="nav-item"><button class="nav-link text-muted fw-bold" data-bs-toggle="pill" data-bs-target="#tab-acompanhamento" onclick="v8CarregarPropostas()">📋 Acompanhamento de Propostas</button></li>
                    <li class="nav-item <?= !$perm_chaves ? 'd-none' : '' ?>"><button class="nav-link text-muted fw-bold" data-bs-toggle="pill" data-bs-target="#tab-clientes" onclick="v8CarregarClientes()">Chaves e Custos</button></li>
                    <li class="nav-item <?= !$perm_extrato ? 'd-none' : '' ?>"><button class="nav-link text-muted fw-bold" data-bs-toggle="pill" data-bs-target="#tab-extrato" onclick="v8PopularSelectExtrato()"><i class="fas fa-file-invoice-dollar me-1"></i> Extrato</button></li>
                    
                    <li class="nav-item <?= $restricao_ia ? 'd-none' : '' ?>"><button class="nav-link text-danger fw-bold" data-bs-toggle="pill" data-bs-target="#tab-atendimento-ia"><i class="fas fa-robot me-1"></i> ATENDIMENTO IA</button></li>
                </ul>
                
                <div class="tab-content">
                    
                    <div class="tab-pane fade show active" id="tab-consulta">
                        <div class="caixa-cobranca mb-4">
                            <label class="form-label fw-bold small text-success">Sua Chave/Credencial Disponível (Dono):</label>
                            <select id="v8_cobrar_manual" class="form-select form-select-sm v8-dropdown-clientes fw-bold" onchange="v8AtualizarSaldosTopo()"></select>
                        </div>
                        
                        <div class="bg-light p-3 border rounded shadow-sm mb-4">
                            <h6 class="fw-bold text-danger border-bottom pb-2 mb-3"><i class="fas fa-search me-2"></i>PASSO 1: Localizar Cadastro do Cliente</h6>
                            <div class="row mb-3"><div class="col-md-12 position-relative"><label class="form-label fw-bold text-dark mb-1">Pesquisar Cliente (CPF, Nome ou Duplo com vírgula):</label><div class="input-group input-group-sm"><span class="input-group-text bg-white border-dark"><i class="fas fa-search text-muted"></i></span><input type="text" id="v8_busca_cliente" class="form-control border-dark border-start-0" placeholder="Ex: MARIA DA SILVA, 000.000.000-00" autocomplete="off" onkeyup="v8PesquisarClienteBanco(this.value)"></div><div id="v8_resultado_busca" class="list-group position-absolute w-100 shadow-lg border border-dark d-none" style="z-index: 1000; top: 60px; max-height: 250px; overflow-y: auto;"></div></div></div>
                            <div id="v8_alerta_cadastro" class="alert alert-info d-none small fw-bold py-2 mb-3"></div>
                            <div class="row g-2 mb-3"><div class="col-md-3"><label class="form-label fw-bold text-dark mb-1">CPF:</label><input type="text" id="v8_input_cpf" class="form-control form-control-sm border-secondary bg-light" readonly></div><div class="col-md-3"><label class="form-label fw-bold text-dark mb-1">Nascimento:</label><input type="date" id="v8_input_nascimento" class="form-control form-control-sm border-secondary bg-light" readonly></div><div class="col-md-6"><label class="form-label fw-bold text-dark mb-1">Nome Completo:</label><input type="text" id="v8_input_nome" class="form-control form-control-sm border-secondary bg-light fw-bold" readonly></div></div>
                            <div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label fw-bold text-dark mb-1">Gênero:</label><select id="v8_input_genero" class="form-select form-select-sm border-dark"><option value="female">Feminino</option><option value="male">Masculino</option></select></div><div class="col-md-4"><label class="form-label fw-bold text-dark mb-1">Telefone (Opcional):</label><input type="text" id="v8_input_telefone" class="form-control form-control-sm border-dark" maxlength="11" placeholder="Somente números"></div><input type="hidden" id="v8_input_email" value="cliente@gmail.com"><div class="col-md-5 d-flex gap-2"><button class="btn btn-secondary btn-sm w-25 fw-bold shadow-sm" onclick="v8LimparFormulario()"><i class="fas fa-eraser"></i></button><button id="btn_gerar_consent" class="btn btn-danger btn-sm w-75 fw-bold shadow-sm" onclick="v8ExecutarPasso1E2()"><i class="fas fa-play me-1"></i> Gerar Consentimento</button></div></div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-5 mb-2">
                            <h5 class="mb-0 text-secondary fw-bold"><i class="fas fa-list-alt me-2"></i> Fila e Status das Operações</h5>
                            <div class="d-flex gap-2">
                                <button class="btn btn-outline-dark btn-sm fw-bold shadow-sm bg-white" onclick="v8ExportarFilaManual()"><i class="fas fa-file-export text-success me-1"></i> EXPORTAR</button>
                                <button class="btn btn-dark btn-sm fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMontadorFila"><i class="fas fa-filter me-1"></i> Filtro Avançado</button>
                            </div>
                        </div>
                        <div class="collapse mb-3" id="collapseMontadorFila"><div class="filtro-avancado-box"><h6 class="text-dark fw-bold border-bottom pb-2 mb-3"><i class="fas fa-search me-2 text-primary"></i>Buscar Operações</h6><div class="row g-2 align-items-end"><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Data Inicial</label><input type="date" id="filtro_fila_data_ini" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Data Final</label><input type="date" id="filtro_fila_data_fim" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">CPF</label><input type="text" id="filtro_fila_cpf" class="form-control border-secondary" placeholder="Somente números"></div><div class="col-md-3"><label class="small fw-bold text-dark mb-1">Nome do Cliente</label><input type="text" id="filtro_fila_nome" class="form-control border-secondary" placeholder="Parte do nome"></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-outline-secondary btn-sm w-100 fw-bold shadow-sm" onclick="v8LimparFiltrosFila()">Limpar</button><button class="btn btn-primary btn-sm w-100 fw-bold shadow-sm border-dark" onclick="v8CarregarFila()"><i class="fas fa-search"></i> Executar</button></div></div></div></div>
                        <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 600px; overflow-y: auto;">
                            <table class="table table-hover table-bordered table-sm align-middle mb-0 table-fila">
                                <thead><tr><th>Data Fila</th><th>CPF</th><th class="border-start border-end border-danger">Autorização ID Consulta</th><th class="border-end border-danger">ID Config (Margem)</th><th class="border-end border-danger">ID Simulação</th><th class="border-end border-danger">ID Proposta</th><th>Ações</th></tr></thead>
                                <tbody id="v8_fila_body" class="bg-white"><tr><td colspan="7" class="py-4 text-muted">Carregando fila...</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-lote-csv">
                        <?php 
                        $caminho_lote = $_SERVER['DOCUMENT_ROOT'] . '/modulos/configuracao/v8_clt_margem_e_digitacao/index.api.v8.lote.vsc.php';
                        if (file_exists($caminho_lote)) {
                            include $caminho_lote;
                        } else {
                            echo '<div class="alert alert-warning fw-bold"><i class="fas fa-exclamation-triangle"></i> Módulo de Lote (CSV) não encontrado. Crie o arquivo index.api.v8.lote.vsc.php na mesma pasta.</div>';
                        }
                        ?>
                    </div>

                    <div class="tab-pane fade" id="tab-acompanhamento">
                        <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0 text-primary fw-bold"><i class="fas fa-tasks me-2"></i> Gestão e Acompanhamento de Propostas V8</h5><button class="btn btn-dark btn-sm fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMontadorProp"><i class="fas fa-filter me-1"></i> Filtro Avançado</button></div>
                        <div class="collapse show mb-4" id="collapseMontadorProp"><div class="filtro-avancado-box border-primary"><div class="row g-2 align-items-end"><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Data Inicial</label><input type="date" id="filtro_prop_data_ini" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Data Final</label><input type="date" id="filtro_prop_data_fim" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">CPF Cliente</label><input type="text" id="filtro_prop_cpf" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Nº Proposta</label><input type="text" id="filtro_prop_numero" class="form-control border-secondary"></div><div class="col-md-2"><label class="small fw-bold text-dark mb-1">Status V8</label><select id="filtro_prop_status" class="form-select border-secondary fw-bold"><option value="">Todos os Status</option><option value="AGUARDANDO">⏳ Aguardando / Em Análise</option><option value="PENDENCIA">⚠️ Com Pendência</option><option value="CANCELADA">❌ Canceladas</option><option value="PAGO">✅ Pagas / Aprovadas</option></select></div><div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-secondary btn-sm w-100 fw-bold shadow-sm" onclick="v8CarregarPropostas()">Limpar</button><button class="btn btn-primary btn-sm w-100 fw-bold shadow-sm border-dark" onclick="v8CarregarPropostas()"><i class="fas fa-search"></i> Buscar</button></div></div></div></div>
                        <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 600px; overflow-y: auto;">
                            <table class="table table-hover table-bordered table-sm align-middle mb-0 table-fila"><thead class="table-dark"><tr><th>Data Digitação</th><th>Cliente</th><th>Valores</th><th class="border-start border-end border-warning">Proposta e Status Real</th><th>Ações Rápidas</th></tr></thead><tbody id="v8_tbody_propostas" class="bg-white"><tr><td colspan="5" class="py-4 text-muted text-center">Carregando propostas...</td></tr></tbody></table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-clientes">
                        <div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0 text-secondary fw-bold"><i class="fas fa-key text-warning me-2"></i>Gestão de Chaves V8 e Saldo</h5><button class="btn btn-dark fw-bold border-dark shadow-sm" onclick="abrirModalNovaChave()"><i class="fas fa-plus me-1"></i> Nova Chave V8</button></div>
                        <div class="table-responsive border border-dark rounded shadow-sm">
                            <table class="table table-hover align-middle mb-0 bg-white text-center">
                                <thead class="table-dark text-white border-dark" id="v8_thead_clientes">
                                    </thead>
                                <tbody id="v8_tabela_clientes"><tr><td colspan="6" class="text-center py-4 text-muted">Carregando chaves...</td></tr></tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-extrato">
                        <div class="d-flex justify-content-between align-items-center mb-4"><h5 class="mb-0 text-secondary fw-bold"><i class="fas fa-file-invoice-dollar text-success me-2"></i>Extrato de Movimentações</h5><div id="boxAcoesExtrato" class="d-none"><button class="btn btn-primary fw-bold border-dark shadow-sm me-2" onclick="v8AbrirModalAjusteSaldo()"><i class="fas fa-coins me-1"></i> Ajustar Saldo / Crédito</button><button class="btn btn-warning text-dark fw-bold border-dark shadow-sm me-2" onclick="v8ExportarExcelV8()"><i class="fas fa-file-excel me-1"></i> Exportar Custo V8</button><button class="btn btn-success fw-bold border-dark shadow-sm" onclick="v8ExportarExcelCliente()"><i class="fas fa-file-excel me-1"></i> Exportar Extrato Cliente</button></div></div>
                        <div class="card bg-light border-dark shadow-sm mb-3"><div class="card-body py-3"><div class="row align-items-end"><div class="col-md-6"><label class="fw-bold small text-dark mb-1">Selecione a Chave para visualizar o extrato:</label><select id="sel_extrato_chave" class="form-select border-dark fw-bold v8-dropdown-clientes" onchange="v8CarregarExtrato()"></select></div></div></div></div>
                        <div id="info_extrato_selecionado" class="alert alert-secondary d-none py-2 px-3 border-secondary mb-3 shadow-sm"><div class="row text-center small text-dark"><div class="col-md-4"><strong>Dono (Usuário):</strong> <span id="lbl_extrato_usuario" class="text-primary fw-bold"></span></div><div class="col-md-4 border-start border-secondary"><strong>Cliente:</strong> <span id="lbl_extrato_cliente" class="text-primary fw-bold"></span></div><div class="col-md-4 border-start border-secondary"><strong>CPF Associado:</strong> <span id="lbl_extrato_cpf" class="text-primary fw-bold"></span></div></div></div>
                        <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 500px; overflow-y: auto;"><table class="table table-hover table-bordered table-sm align-middle mb-0"><thead class="table-dark text-center sticky-top"><tr><th>Data/Hora</th><th>Motivo / Referência</th><th>Tipo (Movimento)</th><th class="text-end">Valor Debit. Cliente</th><th class="text-end">Saldo Anterior</th><th class="text-end text-warning">Saldo Atual</th></tr></thead><tbody id="v8_tbody_extrato" class="bg-white"><tr><td colspan="6" class="text-center py-4 text-muted">Selecione uma chave acima.</td></tr></tbody></table></div>
                    </div>

                    <div class="tab-pane fade" id="tab-atendimento-ia">
                        <?php 
                        $caminho_ia = $_SERVER['DOCUMENT_ROOT'] . '/modulos/configuracao/v8_clt_margem_e_digitacao/index.api.ia.v8.php';
                        if (file_exists($caminho_ia)) {
                            include $caminho_ia;
                        } else {
                            echo '<div class="alert alert-warning fw-bold"><i class="fas fa-exclamation-triangle"></i> Módulo de IA não encontrado. Crie o arquivo index.api.ia.v8.php na mesma pasta.</div>';
                        }
                        ?>
                    </div>

                </div>
            </div></div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalChaveV8" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content shadow-lg border-dark rounded-3 overflow-hidden"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-key text-warning me-2"></i> Cadastrar Credencial V8</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
<div class="modal-body bg-light p-4">
    <form id="formChaveV8">
        <input type="hidden" id="chv_id">
        <div class="row g-3 mb-3">
            <div class="col-md-12 <?php if($restricao_meu_usuario) echo 'd-none'; ?>">
                <label class="small fw-bold text-dark">Usuário do Sistema (Dono da Chave):</label>
                <select id="chv_usuario_id" class="form-select border-dark" required></select>
                <small class="text-muted"><i class="fas fa-info-circle"></i> O sistema vinculará o Cliente (Nome e ID) automaticamente com base neste usuário.</small>
            </div>
        </div>
        <div class="row g-3 mb-3 d-none">
            <div class="col-md-6"><label class="small fw-bold text-dark">Client ID:</label><input type="text" id="chv_client_id" class="form-control border-dark"></div>
            <div class="col-md-6"><label class="small fw-bold text-dark">Audience:</label><input type="text" id="chv_audience" class="form-control border-dark"></div>
        </div>
        <div class="row g-3 mb-3 <?php if($restricao_chave) echo 'd-none'; ?>">
            <div class="col-md-6"><label class="small fw-bold text-dark">Username API:</label><input type="email" id="chv_username" class="form-control border-dark"></div>
            <div class="col-md-6"><label class="small fw-bold text-dark">Password API:</label><input type="text" id="chv_password" class="form-control border-dark"></div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6 <?php if($restricao_custo_cliente) echo 'd-none'; ?>"><label class="small fw-bold text-dark text-primary">Custo p/ Cliente (R$):</label><input type="number" id="chv_custo_consulta" class="form-control border-primary" step="0.01" value="0.00"></div>
            <div class="col-md-6 <?php if($restricao_custo_api) echo 'd-none'; ?>"><label class="small fw-bold text-dark text-warning">Seu Custo API (R$):</label><input type="number" id="chv_custo_v8" class="form-control border-warning" step="0.01" value="0.00"></div>
            <div class="col-md-6"><label class="small fw-bold text-info">Tabela Padrão (Ex: CLT Acelera):</label><input type="text" id="chv_tabela_padrao" class="form-control border-info" value="CLT Acelera" required></div>
            <div class="col-md-6"><label class="small fw-bold text-info">Prazo Padrão (Ex: 24, 36, 48):</label><input type="number" id="chv_prazo_padrao" class="form-control border-info" value="24" required></div>
        </div>
        <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm fs-5"><i class="fas fa-save me-2"></i> Salvar</button>
    </form>
</div></div></div></div>

<div class="modal fade" id="modalSimulacaoV8" tabindex="-1"><div class="modal-dialog modal-xl"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-file-signature text-warning me-2"></i> Digitação</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><div class="card mb-3 border-dark shadow-sm"><div class="card-header bg-secondary text-white fw-bold d-flex justify-content-between align-items-center py-2"><span><i class="fas fa-user-check"></i> BLOCO 1: Dados Cadastrais</span><button type="button" class="btn btn-sm btn-light fw-bold text-dark shadow-sm" onclick="v8AtualizarDadosDigitacaoFC()"><i class="fas fa-sync text-primary"></i> Atualização Cadastral</button></div><div class="card-body"><div class="row g-2 mb-3"><div class="col-md-2"><label class="small fw-bold">CPF</label><input type="text" id="v8_dig_cpf" class="form-control bg-light" readonly></div><div class="col-md-4"><label class="small fw-bold">Nome</label><input type="text" id="v8_dig_nome" class="form-control"></div><div class="col-md-4"><label class="small fw-bold">E-mail</label><input type="email" id="v8_dig_email" class="form-control"></div><div class="col-md-2"><label class="small fw-bold">Nascimento</label><input type="date" id="v8_dig_nascimento" class="form-control"></div></div><div class="row g-2 mb-3"><div class="col-md-4"><label class="small fw-bold">Mãe</label><input type="text" id="v8_dig_mae" class="form-control"></div><div class="col-md-3"><label class="small fw-bold">RG</label><input type="text" id="v8_dig_rg" class="form-control"></div><div class="col-md-2"><label class="small fw-bold">Sexo</label><select id="v8_dig_sexo" class="form-select"><option value="F">Feminino</option><option value="M">Masculino</option></select></div><div class="col-md-3 d-flex align-items-end gap-2"><div class="flex-grow-1"><label class="small fw-bold text-primary">Telefones</label><select id="v8_dig_telefone_sel" class="form-select border-primary fw-bold" onchange="document.getElementById('v8_dig_telefone').value = this.value;"></select><input type="text" id="v8_dig_telefone_input" class="form-control border-primary fw-bold d-none" placeholder="(00) 00000-0000" maxlength="15" oninput="v8MascaraTelefone(this)"><input type="hidden" id="v8_dig_telefone"></div><button type="button" class="btn btn-outline-primary shadow-sm" style="margin-bottom: 2px;" onclick="v8LimparTelefoneManual()" id="btn_novo_telefone" title="Novo Telefone"><i class="fas fa-plus"></i> Novo</button></div></div><hr class="my-3"><div class="row g-2"><div class="col-md-12 mb-2 d-flex align-items-end gap-2"><div class="flex-grow-1"><label class="small fw-bold text-primary">Endereços (Selecione ou digite um novo)</label><select id="v8_dig_seletor_endereco" class="form-select border-primary" onchange="v8PreencherEnderecoSelecionado()"></select></div><button type="button" class="btn btn-outline-primary shadow-sm" style="margin-bottom: 2px;" onclick="v8LimparEnderecoManual()"><i class="fas fa-plus"></i> Novo</button></div><div class="col-md-2"><label class="small fw-bold">CEP</label><input type="text" id="v8_dig_cep" class="form-control"></div><div class="col-md-4"><label class="small fw-bold">Logradouro</label><input type="text" id="v8_dig_logradouro" class="form-control"></div><div class="col-md-2"><label class="small fw-bold">Número</label><input type="text" id="v8_dig_numero" class="form-control"></div><div class="col-md-4"><label class="small fw-bold">Bairro</label><input type="text" id="v8_dig_bairro" class="form-control"></div><div class="col-md-3 mt-2"><label class="small fw-bold">Cidade</label><input type="text" id="v8_dig_cidade" class="form-control"></div><div class="col-md-2 mt-2"><label class="small fw-bold">UF</label><input type="text" id="v8_dig_uf" class="form-control" maxlength="2"></div></div></div></div><div class="card mb-3 border-dark shadow-sm"><div class="card-header bg-secondary text-white fw-bold"><i class="fas fa-calculator"></i> BLOCO 2: Simulações</div><div class="card-body"><div class="row mb-3 bg-white p-3 border rounded shadow-sm"><div class="col-md-3"><label class="fw-bold text-dark">Prazo</label><select id="v8_sim_prazo" class="form-select border-primary fw-bold"></select></div><div class="col-md-4"><label class="fw-bold text-dark">Tipo de Busca</label><select id="v8_sim_tipo_busca" class="form-select border-primary fw-bold"><option value="PARCELA">Por Parcela (R$)</option><option value="TOTAL">Por Total (R$)</option></select></div><div class="col-md-3"><label class="fw-bold text-dark">Valor (R$)</label><input type="number" step="0.01" id="v8_sim_valor" class="form-control border-primary"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-warning w-100 fw-bold border-dark" onclick="v8FazerSimulacaoPersonalizada()"><i class="fas fa-sync"></i> Simular</button></div></div><div class="table-responsive border border-dark rounded"><table class="table table-bordered table-hover text-center mb-0" style="font-size: 13px;"><thead class="table-dark"><tr><th>ESCOLHER</th><th>Tipo</th><th>Prazo</th><th>Parcela</th><th>VALOR LIBERADO</th><th>Data</th></tr></thead><tbody id="v8_bloco2_historico"></tbody></table></div></div></div><div class="card border-dark shadow-sm mb-3"><div class="card-header bg-secondary text-white fw-bold"><i class="fas fa-money-check-alt"></i> BLOCO 3: PIX</div><div class="card-body bg-white"><div class="row"><div class="col-md-6 mb-2"><label class="fw-bold text-primary">Chave PIX</label><input type="text" id="v8_dig_pix" class="form-control border-primary"></div><div class="col-md-6 mb-2"><label class="fw-bold text-primary">Tipo</label><select id="v8_dig_tipo_pix" class="form-select border-primary"><option value="CPF">CPF</option><option value="PHONE">Celular</option><option value="EMAIL">E-mail</option><option value="RANDOM">Aleatória</option></select></div></div></div></div><div class="card border-dark shadow-sm"><div class="card-header bg-secondary text-white fw-bold py-2"><i class="fas fa-comment-dots"></i> BLOCO 4: Observação</div><div class="card-body bg-white py-2"><textarea id="v8_dig_observacao" class="form-control border-secondary" rows="2"></textarea></div></div></div><div class="modal-footer bg-light border-top border-secondary"><input type="hidden" id="v8_id_fila_modal"><button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-success fw-bold px-5 shadow-sm border-dark fs-6" onclick="v8EnviarProposta()"><i class="fas fa-paper-plane"></i> ENVIAR PROPOSTA</button></div></div></div></div>

<div class="modal fade" id="modalPendenciaPix" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-exclamation-triangle me-2"></i> Resolver Pendência PIX</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><input type="hidden" id="v8_pend_id_db"><div class="mb-3"><label class="fw-bold text-dark mb-1">Nova Chave PIX</label><input type="text" id="v8_pend_pix" class="form-control border-dark"></div><div class="mb-4"><label class="fw-bold text-dark mb-1">Tipo da Chave</label><select id="v8_pend_tipo_pix" class="form-select border-dark"><option value="CPF">CPF</option><option value="PHONE">Celular</option><option value="EMAIL">E-mail</option><option value="RANDOM">Aleatória</option></select></div><button type="button" class="btn btn-success w-100 fw-bold border-dark shadow-sm fs-5" onclick="v8ResolverPendenciaPix()"><i class="fas fa-paper-plane me-2"></i> Salvar</button></div></div></div></div>

<div class="modal fade" id="modalAjusteSaldo" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-coins me-2"></i> Ajuste de Saldo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light p-4"><input type="hidden" id="ajuste_chave_id"><div class="mb-3"><label class="fw-bold text-dark mb-1">Tipo</label><select id="ajuste_tipo" class="form-select border-dark fw-bold"><option value="CREDITO" class="text-success">Adicionar (Crédito)</option><option value="DEBITO" class="text-danger">Remover (Débito)</option></select></div><div class="mb-3"><label class="fw-bold text-dark mb-1">Valor (R$)</label><input type="number" id="ajuste_valor" class="form-control border-dark fw-bold fs-5 text-primary" step="0.01"></div><div class="mb-4"><label class="fw-bold text-dark mb-1">Motivo</label><input type="text" id="ajuste_obs" class="form-control border-dark"></div><button type="button" class="btn btn-success w-100 fw-bold border-dark shadow-sm fs-5" onclick="v8SalvarAjusteSaldo()"><i class="fas fa-save me-2"></i> Confirmar</button></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const restricaoChave = <?= $restricao_chave ? 'true' : 'false' ?>;
    const restricaoCustoCliente = <?= $restricao_custo_cliente ? 'true' : 'false' ?>;
    const restricaoCustoApi = <?= $restricao_custo_api ? 'true' : 'false' ?>;
    const restricaoMeuUsuario = <?= $restricao_meu_usuario ? 'true' : 'false' ?>;
    
    const cpfLogado = '<?= preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '') ?>';
    const perm_digitar = <?= $perm_digitar ?>;
    const perm_fator = <?= $perm_fator ?>;

    let v8Modais = {}; let windowListaChavesCache = []; let windowPollingData = {}; 
    let windowDadosFilaAtual = null; let windowDadosExtratoAtual = []; 
    let v8TimerBusca; let listaEnderecosFC = [];

    document.addEventListener("DOMContentLoaded", () => {
        v8Modais = { 
            simulacao: new bootstrap.Modal(document.getElementById('modalSimulacaoV8')), 
            chave: new bootstrap.Modal(document.getElementById('modalChaveV8')),
            pendencia: new bootstrap.Modal(document.getElementById('modalPendenciaPix')),
            ajusteSaldo: new bootstrap.Modal(document.getElementById('modalAjusteSaldo'))
        };
        v8CarregarCadastros(); v8CarregarDadosIniciais(); v8CarregarFila();
        v8AtualizarSaldosTopo();
    });

    function v8Loading(show, msg = "Aguarde...") { document.getElementById('v8-loader').style.display = show ? 'block' : 'none'; if(show) document.getElementById('v8-loader-msg').innerText = msg; }
    
    async function v8Req(arquivo, acao, dados = {}, showLoader = true, msgLoader = "") { 
        if(showLoader) v8Loading(true, msgLoader); 
        try { 
            const fd = new FormData(); fd.append('acao', acao); 
            for(let k in dados) fd.append(k, dados[k]); 
            const r = await fetch(arquivo, { method: 'POST', body: fd }); 
            const textResponse = await r.text(); 
            try { const j = JSON.parse(textResponse); if(showLoader) v8Loading(false); return j; } catch(e) { console.error("O PHP não retornou um JSON válido.", textResponse); if(showLoader) v8Loading(false); return { success: false, msg: "Erro no processamento do PHP." }; }
        } catch(e) { if(showLoader) v8Loading(false); return { success: false, msg: "Falha de comunicação." }; } 
    }

    async function v8AtualizarSaldosTopo(valorOrigem = null) {
        let chaveId = 0;
        if(valorOrigem !== null && valorOrigem !== '') {
            chaveId = valorOrigem; 
        } else {
            let selectManual = document.getElementById('v8_cobrar_manual');
            if(selectManual && selectManual.value) { chaveId = selectManual.value; }
        }
        const res = await v8Req('v8_api.ajax.php', 'buscar_saldos_gerais', { chave_id: chaveId }, false);
        if(res.success) {
            document.getElementById('lbl_saldo_topo_v8').innerText = res.saldo_v8;
            document.getElementById('lbl_saldo_topo_cad').innerText = res.saldo_cad;
        }
    }

    async function v8CarregarCadastros() { const res = await v8Req('v8_api.ajax.php', 'listar_cadastros_base', {}, false); const selU = document.getElementById('chv_usuario_id'); if(res.success) { if(res.data.usuarios.length > 0) { let optU = '<option value="">-- Selecione o Usuário --</option>'; res.data.usuarios.forEach(u => { optU += `<option value="${u.id}">${u.nome}</option>`; }); selU.innerHTML = optU; } else { selU.innerHTML = '<option value="1">Usuário Padrão</option>'; } } }
    async function v8CarregarClientes() { const tb = document.getElementById("v8_tabela_clientes"); tb.innerHTML = '<tr><td colspan="6" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></td></tr>'; const thead = document.getElementById("v8_thead_clientes"); let htmlHeader = `<tr><th>Cliente / Credencial</th><th>Usuário ID (Dono)</th>`; if (!restricaoCustoCliente) htmlHeader += `<th>Custo p/ Cliente</th>`; if (!restricaoCustoApi) htmlHeader += `<th>Seu Custo API</th>`; htmlHeader += `<th>Saldo Atual</th><th class="text-center">Ações</th></tr>`; thead.innerHTML = htmlHeader; const r = await v8Req('v8_api.ajax.php', 'listar_chaves_acesso', {}, false); if (r.success) { tb.innerHTML = ''; if (r.data.length === 0) return tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 fw-bold">Nenhuma chave encontrada.</td></tr>'; r.data.forEach(c => { let tr = `<tr class="border-bottom border-dark"><td class="fw-bold text-primary">${c.CLIENTE_NOME}</td><td>${c.NOME_USUARIO}</td>`; if (!restricaoCustoCliente) tr += `<td>R$ ${parseFloat(c.CUSTO_CONSULTA).toFixed(2).replace('.',',')}</td>`; if (!restricaoCustoApi) tr += `<td class="text-warning fw-bold">R$ ${parseFloat(c.CUSTO_V8).toFixed(2).replace('.',',')}</td>`; tr += `<td class="fw-bold text-success fs-6">R$ ${parseFloat(c.SALDO).toFixed(2).replace('.',',')}</td><td class="text-center"><button class="btn btn-sm btn-dark border-dark" onclick="abrirModalEditarChave(${c.ID})"><i class="fas fa-edit"></i> Editar</button></td></tr>`; tb.innerHTML += tr; }); } else { tb.innerHTML = `<tr><td colspan="6" class="text-center py-4 text-danger fw-bold">Erro: ${r.msg}</td></tr>`; } }
    async function v8CarregarDadosIniciais() { const r = await v8Req('v8_api.ajax.php', 'listar_chaves_acesso', {}, false); if(r.success) { windowListaChavesCache = r.data; let o = '<option value="">-- Selecione a Chave/Cliente --</option>'; r.data.forEach(c => { o += `<option value="${c.ID}">${c.CLIENTE_NOME} (Saldo: R$ ${c.SALDO})</option>`; }); document.querySelectorAll('.v8-dropdown-clientes').forEach(s => s.innerHTML = o); } }
    function v8PopularSelectExtrato() { const sel = document.getElementById("sel_extrato_chave"); if(sel.options.length > 1) return; let o = '<option value="">-- Selecione a Chave --</option>'; windowListaChavesCache.forEach(c => { o += `<option value="${c.ID}">${c.CLIENTE_NOME} (Saldo: R$ ${c.SALDO})</option>`; }); sel.innerHTML = o; }
    async function v8CarregarExtrato() { const chaveId = document.getElementById("sel_extrato_chave").value; const tb = document.getElementById("v8_tbody_extrato"); const infoBar = document.getElementById('info_extrato_selecionado'); const boxAcoes = document.getElementById('boxAcoesExtrato'); if (!chaveId) { tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted">Selecione uma chave acima.</td></tr>'; windowDadosExtratoAtual = []; infoBar.classList.add('d-none'); boxAcoes.classList.add('d-none'); return; } boxAcoes.classList.remove('d-none'); const chaveObj = windowListaChavesCache.find(c => c.ID == chaveId); if(chaveObj) { document.getElementById('lbl_extrato_usuario').innerText = chaveObj.NOME_USUARIO || 'N/A'; document.getElementById('lbl_extrato_cliente').innerText = chaveObj.CLIENTE_NOME || 'N/A'; document.getElementById('lbl_extrato_cpf').innerText = chaveObj.CPF_USUARIO || 'N/A'; infoBar.classList.remove('d-none'); } tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin"></i></td></tr>'; const res = await v8Req('v8_api.ajax.php', 'listar_extrato_cliente', { chave_id: chaveId }, false); if(res.success) { windowDadosExtratoAtual = res.data; tb.innerHTML = ""; if(res.data.length === 0) { tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 fw-bold">Nenhuma movimentação registrada.</td></tr>'; return; } res.data.forEach(r => { let corValor = (parseFloat(r.VALOR) < 0 || r.TIPO_MOVIMENTO === 'RETIRADA' || r.TIPO_MOVIMENTO === 'DEBITO') ? 'text-danger' : 'text-success'; let sinalValor = (parseFloat(r.VALOR) < 0 || r.TIPO_MOVIMENTO === 'RETIRADA' || r.TIPO_MOVIMENTO === 'DEBITO') ? '-' : '+'; tb.innerHTML += `<tr><td class="text-center small">${r.DATA_BR}</td><td class="small fw-bold">${r.TIPO_CUSTO}</td><td class="text-center"><span class="badge ${corValor === 'text-danger' ? 'bg-danger' : 'bg-success'}">${r.TIPO_MOVIMENTO}</span></td><td class="text-end fw-bold ${corValor}">${sinalValor} R$ ${Math.abs(parseFloat(r.VALOR)).toFixed(2).replace('.', ',')}</td><td class="text-end text-muted small">R$ ${parseFloat(r.SALDO_ANTERIOR).toFixed(2).replace('.', ',')}</td><td class="text-end fw-bold text-dark">R$ ${parseFloat(r.SALDO_ATUAL).toFixed(2).replace('.', ',')}</td></tr>`; }); } }
    function v8ExportarExcelCliente() { const selectBox = document.getElementById("sel_extrato_chave"); const chaveNome = selectBox.options[selectBox.selectedIndex]?.text.replace(/[^a-zA-Z0-9 ]/g, '').trim() || 'Extrato'; if (windowDadosExtratoAtual.length === 0) return alert("Carregue um extrato primeiro!"); let trs = ''; windowDadosExtratoAtual.forEach(r => { trs += `<tr><td>${r.DATA_BR}</td><td>${r.TIPO_CUSTO}</td><td>${r.TIPO_MOVIMENTO}</td><td>R$ ${parseFloat(r.VALOR).toFixed(2).replace('.', ',')}</td><td>R$ ${parseFloat(r.SALDO_ANTERIOR).toFixed(2).replace('.', ',')}</td><td>R$ ${parseFloat(r.SALDO_ATUAL).toFixed(2).replace('.', ',')}</td></tr>`; }); let htmlBase = `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1"><thead><tr><th>Data/Hora</th><th>Motivo</th><th>Tipo</th><th>Valor Cobrado</th><th>Saldo Anterior</th><th>Saldo Atual</th></tr></thead><tbody>${trs}</tbody></table></body></html>`; let blob = new Blob([htmlBase], { type: 'application/vnd.ms-excel' }); let link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = `Extrato_Cliente_${chaveNome}.xls`; link.click(); }
    function v8ExportarExcelV8() { const selectBox = document.getElementById("sel_extrato_chave"); const chaveNome = selectBox.options[selectBox.selectedIndex]?.text.replace(/[^a-zA-Z0-9 ]/g, '').trim() || 'Extrato'; if (windowDadosExtratoAtual.length === 0) return alert("Carregue um extrato primeiro!"); let trs = ''; windowDadosExtratoAtual.forEach(r => { trs += `<tr><td>${r.DATA_BR}</td><td>${r.TIPO_CUSTO}</td><td>${r.TIPO_MOVIMENTO}</td><td>R$ ${parseFloat(r.CUSTO_V8).toFixed(2).replace('.', ',')}</td></tr>`; }); let htmlBase = `<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body><table border="1"><thead><tr><th>Data/Hora</th><th>Motivo</th><th>Movimento</th><th>Custo API (R$)</th></tr></thead><tbody>${trs}</tbody></table></body></html>`; let blob = new Blob([htmlBase], { type: 'application/vnd.ms-excel' }); let link = document.createElement("a"); link.href = URL.createObjectURL(blob); link.download = `CustoAPI_V8_${chaveNome}.xls`; link.click(); }
    function v8AbrirModalAjusteSaldo() { const chaveId = document.getElementById("sel_extrato_chave").value; if(!chaveId) return alert("Selecione a Chave primeiro."); document.getElementById('ajuste_chave_id').value = chaveId; document.getElementById('ajuste_valor').value = ''; document.getElementById('ajuste_obs').value = ''; v8Modais.ajusteSaldo.show(); }
    async function v8SalvarAjusteSaldo() { const chaveId = document.getElementById('ajuste_chave_id').value; const tipo = document.getElementById('ajuste_tipo').value; const valor = document.getElementById('ajuste_valor').value; const obs = document.getElementById('ajuste_obs').value; if(!valor || valor <= 0) return alert("Digite um valor válido."); if(!obs) return alert("Digite o motivo."); const res = await v8Req('v8_api.ajax.php', 'ajustar_saldo_manual', { chave_id: chaveId, tipo: tipo, valor: valor, obs: obs }, true, "Ajustando..."); if(res.success) { alert("✅ " + res.msg); v8Modais.ajusteSaldo.hide(); await v8CarregarDadosIniciais(); v8CarregarExtrato(); v8AtualizarSaldosTopo(); } else { alert("❌ Erro: " + res.msg); } }
    function abrirModalNovaChave() { document.getElementById('formChaveV8').reset(); document.getElementById('chv_id').value = ''; if(restricaoMeuUsuario) { document.getElementById('chv_usuario_id').value = cpfLogado; } v8Modais.chave.show(); }
    function abrirModalEditarChave(id) { const chave = windowListaChavesCache.find(c => c.ID == id); if(!chave) return; document.getElementById('chv_id').value = chave.ID; document.getElementById('chv_usuario_id').value = chave.CPF_USUARIO || chave.USUARIO_ID; document.getElementById('chv_client_id').value = chave.CLIENT_ID; document.getElementById('chv_audience').value = chave.AUDIENCE; document.getElementById('chv_username').value = chave.USERNAME_API; document.getElementById('chv_password').value = chave.PASSWORD_API; document.getElementById('chv_custo_consulta').value = chave.CUSTO_CONSULTA; document.getElementById('chv_custo_v8').value = chave.CUSTO_V8; document.getElementById('chv_tabela_padrao').value = chave.TABELA_PADRAO || 'CLT Acelera'; document.getElementById('chv_prazo_padrao').value = chave.PRAZO_PADRAO || '24'; v8Modais.chave.show(); }
    document.getElementById('formChaveV8').addEventListener('submit', async e => { e.preventDefault(); const payload = { id: document.getElementById('chv_id').value, usuario_id: document.getElementById('chv_usuario_id').value, client_id: document.getElementById('chv_client_id').value, audience: document.getElementById('chv_audience').value, username_api: document.getElementById('chv_username').value, password_api: document.getElementById('chv_password').value, custo_consulta: document.getElementById('chv_custo_consulta').value, custo_v8: document.getElementById('chv_custo_v8').value, tabela_padrao: document.getElementById('chv_tabela_padrao').value, prazo_padrao: document.getElementById('chv_prazo_padrao').value }; const res = await v8Req('v8_api.ajax.php', 'salvar_chave_v8', payload); if(res.success) { v8Modais.chave.hide(); v8CarregarClientes(); v8CarregarDadosIniciais(); alert("Chave salva com sucesso!"); } else { alert("Erro: " + res.msg); } });
    
    function v8PesquisarClienteBanco(termo) { 
        clearTimeout(v8TimerBusca); 
        const caixa = document.getElementById('v8_resultado_busca'); 
        if (termo.length < 3) { caixa.classList.add('d-none'); return; } 
        v8TimerBusca = setTimeout(async () => { 
            caixa.classList.remove('d-none'); 
            caixa.innerHTML = '<div class="list-group-item text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Buscando...</div>'; 
            const res = await v8Req('v8_api.ajax.php', 'buscar_cliente_banco', { termo: termo }, false); 
            if (res.success) { 
                if (res.dados.length === 0) { 
                    caixa.innerHTML = `<div class="list-group-item bg-light text-center"><p class="text-danger fw-bold mb-2"><i class="fas fa-exclamation-triangle"></i> Cliente não encontrado.</p>` + (perm_fator ? `<button class="btn btn-sm btn-primary fw-bold px-4 border-dark shadow-sm" onclick="v8AtualizarCpfFatorConferi('${termo}')"><i class="fas fa-sync"></i> Atualização Cadastral</button>` : `<button class="btn btn-sm btn-secondary fw-bold px-4 border-dark shadow-sm" disabled><i class="fas fa-lock"></i> Bloqueada</button>`) + `</div>`; 
                } else { 
                    let html = ''; 
                    res.dados.forEach(c => { html += `<button type="button" class="list-group-item list-group-item-action py-2" onclick="v8SelecionarCliente('${c.cpf}', '${c.nome}', '${c.nascimento}', '${c.sexo}')"><div class="d-flex w-100 justify-content-between"><h6 class="mb-1 fw-bold text-dark">${c.nome}</h6><small class="text-muted fw-bold">CPF: ${c.cpf}</small></div><small class="text-primary">Nasc: ${c.nascimento ? c.nascimento.split('-').reverse().join('/') : '--'}</small></button>`; }); 
                    caixa.innerHTML = html; 
                } 
            } 
        }, 600); 
    }
    
    function v8SelecionarCliente(cpf, nome, nascimento, sexo) { 
        document.getElementById('v8_input_cpf').value = cpf; 
        document.getElementById('v8_input_nome').value = nome; 
        document.getElementById('v8_input_nascimento').value = nascimento || ''; 
        let genero = 'female'; if(sexo && (sexo.toUpperCase() === 'M' || sexo.toUpperCase() === 'MASCULINO')) genero = 'male'; 
        document.getElementById('v8_input_genero').value = genero; 
        document.getElementById('v8_resultado_busca').classList.add('d-none'); 
        document.getElementById('v8_busca_cliente').value = ''; 
        const box = document.getElementById('v8_alerta_cadastro'); 
        box.classList.remove('d-none'); 
        if (!nome || nome.trim() === '' || !nascimento || nascimento === '0000-00-00') { 
            box.className = 'alert alert-warning small fw-bold py-2 mb-3 shadow-sm border-warning'; 
            box.innerHTML = `<div class="d-flex justify-content-between align-items-center"><span><i class="fas fa-exclamation-triangle text-danger me-1"></i> Falta Nome ou Nascimento.</span>` + (perm_fator ? `<button class="btn btn-sm btn-primary border-dark shadow-sm px-3" onclick="v8AtualizarCpfFatorConferi('${cpf}')"><i class="fas fa-sync"></i> Atualização Cadastral</button>` : `<button class="btn btn-sm btn-secondary border-dark shadow-sm px-3" disabled><i class="fas fa-lock"></i> Bloqueado</button>`) + `</div>`; 
        } else { 
            box.className = 'alert alert-success small fw-bold py-2 mb-3 shadow-sm border-success'; 
            box.innerHTML = `<div class="d-flex justify-content-between align-items-center"><span><i class="fas fa-check-circle text-success me-1"></i> Cliente selecionado.</span>` + (perm_fator ? `<button class="btn btn-sm btn-light border-dark shadow-sm px-3" onclick="v8AtualizarCpfFatorConferi('${cpf}')"><i class="fas fa-sync text-primary"></i> Forçar Atualização</button>` : `<button class="btn btn-sm btn-secondary border-dark shadow-sm px-3" disabled><i class="fas fa-lock"></i> Bloqueado</button>`) + `</div>`; 
        } 
    }
    
    async function v8AtualizarCpfFatorConferi(termoDigitado) { 
        const chaveId = document.getElementById('v8_cobrar_manual').value; 
        if(!chaveId) return alert("Selecione a Chave no topo da tela para faturar a atualização."); 
        
        let chaveObj = windowListaChavesCache.find(c => c.ID == chaveId);
        let cpfDonoFC = chaveObj ? (chaveObj.CPF_USUARIO || chaveObj.USUARIO_ID) : '';
        if(!cpfDonoFC) return alert("Erro: Chave V8 sem um usuário/CPF dono atrelado para faturar a consulta Fator Conferi.");

        let cpf = termoDigitado.replace(/\D/g, ''); 
        if (cpf.length !== 11) return alert("Digite o CPF com 11 dígitos na pesquisa."); 
        
        v8Loading(true, "Consultando Cadastral..."); 
        try { 
            const fd = new FormData(); 
            fd.append('acao', 'consulta_cpf_manual'); 
            fd.append('cpf', cpf); 
            fd.append('cpf_cobrar', cpfDonoFC);
            fd.append('fonte', 'V8_CONSENTIMENTO'); 
            
            const urlFatorConferi = '/modulos/configuracao/fator_conferi/fator_conferi.ajax.php'; 
            const r = await fetch(urlFatorConferi, { method: 'POST', body: fd }); 
            const res = await r.json(); 
            v8Loading(false); 
            
            if (res.success) { 
                alert("✅ Dados atualizados!"); 
                let cad = res.json_bruto.CADASTRAIS || {}; 
                let nome = cad.NOME || (res.dados ? res.dados.nome : ''); 
                let nascimento = cad.NASCTO || ''; 
                let sexo = cad.SEXO || ''; 
                if(nascimento && nascimento.includes('/')) { let p = nascimento.split('/'); nascimento = `${p[2]}-${p[1]}-${p[0]}`; } 
                let genero = 'female'; if(sexo && sexo.toUpperCase().startsWith('M')) genero = 'male'; 
                
                document.getElementById('v8_input_cpf').value = cpf; 
                document.getElementById('v8_input_nome').value = nome; 
                document.getElementById('v8_input_nascimento').value = nascimento; 
                document.getElementById('v8_input_genero').value = genero; 
                document.getElementById('v8_resultado_busca').classList.add('d-none'); 
                
                const box = document.getElementById('v8_alerta_cadastro'); 
                box.classList.remove('d-none'); 
                box.className = 'alert alert-success small fw-bold py-2 mb-3 shadow-sm border-success'; 
                box.innerHTML = `<div class="d-flex justify-content-between align-items-center"><span><i class="fas fa-check-circle text-success me-1"></i> Cliente atualizado via Integração.</span></div>`; 
                v8AtualizarSaldosTopo();
            } else { 
                alert("⚠️ Erro na atualização: " + res.msg); 
            } 
        } catch(e) { v8Loading(false); alert("Erro de comunicação."); } 
    }

    function v8LimparFormulario() { document.getElementById('v8_input_cpf').value = ''; document.getElementById('v8_input_nascimento').value = ''; document.getElementById('v8_input_nome').value = ''; document.getElementById('v8_input_telefone').value = ''; document.getElementById('v8_alerta_cadastro').classList.add('d-none'); document.getElementById('v8_busca_cliente').focus(); }
    function v8LimparFiltrosFila() { document.getElementById('filtro_fila_data_ini').value = ''; document.getElementById('filtro_fila_data_fim').value = ''; document.getElementById('filtro_fila_cpf').value = ''; document.getElementById('filtro_fila_nome').value = ''; v8CarregarFila(); }

    function v8ExportarFilaManual() {
        let data_ini = document.getElementById('filtro_fila_data_ini') ? document.getElementById('filtro_fila_data_ini').value : '';
        let data_fim = document.getElementById('filtro_fila_data_fim') ? document.getElementById('filtro_fila_data_fim').value : '';
        let cpf = document.getElementById('filtro_fila_cpf') ? document.getElementById('filtro_fila_cpf').value.replace(/\D/g, '') : '';
        let nome = document.getElementById('filtro_fila_nome') ? document.getElementById('filtro_fila_nome').value : '';
        let url = `v8_api.ajax.php?acao=exportar_fila_consultas&data_ini=${data_ini}&data_fim=${data_fim}&cpf=${cpf}&nome=${nome}`;
        window.open(url, '_blank');
    }

    async function v8CarregarFila() { 
        const tb = document.getElementById('v8_fila_body'); 
        tb.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando fila...</td></tr>'; 
        const payload = { data_ini: document.getElementById('filtro_fila_data_ini') ? document.getElementById('filtro_fila_data_ini').value : '', data_fim: document.getElementById('filtro_fila_data_fim') ? document.getElementById('filtro_fila_data_fim').value : '', cpf: document.getElementById('filtro_fila_cpf') ? document.getElementById('filtro_fila_cpf').value.replace(/\D/g, '') : '', nome: document.getElementById('filtro_fila_nome') ? document.getElementById('filtro_fila_nome').value : '' }; 
        const r = await v8Req('v8_api.ajax.php', 'listar_fila_consultas', payload, false); 
        if(r.success) { 
            windowDadosFilaAtual = r.data; tb.innerHTML = ''; 
            if(r.data.length === 0) return tb.innerHTML = '<tr><td colspan="7" class="text-muted py-4 text-center fw-bold">Nenhum resultado encontrado na fila.</td></tr>'; 
            
            r.data.forEach(x => { 
                let colAuth = `<span class="badge bg-light text-muted border">Vazio</span>`; let colConf = `<span class="badge bg-light text-muted border">Vazio</span>`; let colSim  = `<span class="badge bg-light text-muted border">Vazio</span>`; let colProp = `<span class="badge bg-light text-muted border">Vazio</span>`; let btnAcoes = ''; let msgErroLimpa = "Erro na V8"; 
                if(x.MENSAGEM_ERRO) { try { let jsonErro = JSON.parse(x.MENSAGEM_ERRO); msgErroLimpa = jsonErro.detail || jsonErro.message || jsonErro.title || "Erro API"; } catch(e) { msgErroLimpa = String(x.MENSAGEM_ERRO).replace(/"/g, "'"); } } 
                let fontAuth = x.FONTE_CONSULT_ID ? x.FONTE_CONSULT_ID : "NOVO CONSENTIMENTO"; let badgeFonteAuth = `<span class="badge bg-dark rounded-pill mb-1" style="font-size:8.5px;">FONTE: ${fontAuth}</span><br>`; 
                let consultIdVisual = x.CONSULT_ID ? String(x.CONSULT_ID).substring(0,8) : 'N/A';
                
                if (x.STATUS_V8 === 'ERRO-AUT') { colAuth = `${badgeFonteAuth}<span class="badge bg-danger mb-1" title="${msgErroLimpa}" data-bs-toggle="tooltip">ERRO-AUT</span><br><small class="text-muted" style="font-size:10px;">ID: ${consultIdVisual}<br>${x.DATA_RETORNO_BR || ''}</small>`; btnAcoes = `<button class="btn btn-sm btn-danger fw-bold shadow-sm w-100" onclick="v8ReenviarAut(${x.ID})"><i class="fas fa-paper-plane"></i> Reenviar AUT</button>`; } else if (x.STATUS_V8 === 'ERRO ID CONSENTIMENTO') { colAuth = `<span class="badge bg-danger mb-1" title="${msgErroLimpa}" data-bs-toggle="tooltip">ERRO API</span><br><small class="text-muted" style="font-size:10px;">${x.DATA_RETORNO_BR || ''}</small>`; } else if (x.CONSULT_ID && x.STATUS_V8 !== 'AGUARDANDO ID CONSENTIMENTO') { colAuth = `${badgeFonteAuth}<span class="badge bg-success mb-1">OK-CONSENTIMENTO</span><br><small class="text-muted" style="font-size:10px;">ID: ${consultIdVisual}<br>${x.DATA_RETORNO_BR || ''}</small>`; if((x.STATUS_V8 === 'OK-CONSENTIMENTO' || x.STATUS_V8 === 'CONSENTIMENTO AUTOMÁTICO OK') && x.VALOR_MARGEM === null && x.STATUS_CONFIG_ID !== 'ERRO CONSIG_ID' && x.STATUS_CONFIG_ID !== 'ERRO CACHE V8') { btnAcoes = `<button class="btn btn-sm btn-info border-dark fw-bold shadow-sm w-100 text-dark" disabled><i class="fas fa-cogs fa-spin"></i> Margem...</button>`; if(!windowPollingData[x.ID]) { setTimeout(() => { v8BotaoNovaMargem(x.ID, false); }, 1000); } } } else { colAuth = `<span class="badge bg-info text-dark mb-1">Aguardando...</span><br><small class="text-muted" style="font-size:10px;">${x.DATA_RETORNO_BR || ''}</small>`; } 
                
                let fontMargem = x.FONTE_CONSIG_ID ? x.FONTE_CONSIG_ID : "V8"; let badgeFonteMargem = `<span class="badge bg-secondary rounded-pill mb-1" style="font-size:8.5px;">FONTE: ${fontMargem}</span><br>`; 
                if (x.STATUS_V8 === 'ERRO-MARGEM' || x.STATUS_V8 === 'ERRO LEITURA MARGEM' || x.STATUS_CONFIG_ID === 'ERRO CONSIG_ID' || x.STATUS_CONFIG_ID === 'ERRO CACHE V8') { let detalheErroConf = x.OBS_CONFIG_ID || msgErroLimpa; colConf = `${badgeFonteMargem}<span class="badge bg-danger mb-1">REJEITADO</span><br><small class="text-danger fw-bold d-block" style="font-size:9px;">${detalheErroConf}</small>`; btnAcoes = `<button class="btn btn-sm btn-warning border-dark fw-bold shadow-sm w-100" onclick="v8BotaoNovaMargem(${x.ID}, true)"><i class="fas fa-sync"></i> Forçar Margem</button>`; } else if (x.STATUS_V8 === 'AGUARDANDO MARGEM' || x.STATUS_V8 === 'AGUARDANDO V8 MARGEM E PRAZOS') { 
                    colConf = `${badgeFonteMargem}<span class="badge bg-warning text-dark mb-1"><i class="fas fa-spinner fa-spin"></i> Lendo Margem...</span>`; 
                    btnAcoes = `<button class="btn btn-sm btn-info border-dark shadow-sm w-100 mb-1" disabled><i class="fas fa-cogs fa-spin"></i> Processando...</button><button class="btn btn-sm btn-danger border-dark shadow-sm w-100" onclick="v8PararFluxo(${x.ID})">Parar Timer</button>`;
                    if(!windowPollingData[x.ID]) { v8IniciarPollingMargem(x.ID); }
                } else if (x.VALOR_MARGEM !== null) { 
                    let prazosFormatados = "24x"; try { if(x.PRAZOS) { let arrPrazos = JSON.parse(x.PRAZOS); prazosFormatados = Array.isArray(arrPrazos) ? arrPrazos.join(', ')+"x" : x.PRAZOS; } } catch(e) {} 
                    colConf = `${badgeFonteMargem}<span class="badge bg-success mb-1">MARGEM OK</span><br><span class="text-success fw-bold fs-6">R$ ${x.VALOR_MARGEM}</span><br><small class="text-dark d-block" style="font-size:10px;">Prazos: ${prazosFormatados}</small>`; 
                    
                    if (!x.SIMULATION_ID && x.STATUS_V8 !== 'ERRO-SIMULACAO') { 
                        btnAcoes = `<button class="btn btn-sm btn-primary border-dark shadow-sm fw-bold w-100 mb-1" onclick="v8ExecutarSimulacao('PADRÃO', ${x.ID})"><i class="fas fa-calculator"></i> Simulação Padrão</button><button class="btn btn-sm btn-warning border-dark shadow-sm fw-bold w-100 mb-1" onclick="v8BotaoNovaMargem(${x.ID}, true)"><i class="fas fa-sync"></i> Margem DATAPREV</button>`; 
                    } else if (x.SIMULATION_ID || x.VALOR_LIBERADO == '0.00') { 
                        let obsSimText = x.OBS_SIMULATION_ID && x.OBS_SIMULATION_ID !== 'Cálculo concluído' ? `<br><small class="text-danger fw-bold mt-1 d-block" style="font-size:9px; line-height: 1.1; white-space: normal;">${x.OBS_SIMULATION_ID}</small>` : '';
                        colSim = `<span class="badge bg-success mb-1">SIMULADO</span><br><b class="text-success fs-6">R$ ${x.VALOR_LIBERADO || '0.00'}</b><br><small class="text-dark" style="font-size:10px;">Parcela: R$ ${x.VALOR_PARCELA || '0.00'} <br>Prazo: ${x.PRAZO_SIMULACAO || 24}x</small>${obsSimText}`; 
                        btnAcoes = perm_digitar ? `<button class="btn btn-sm btn-dark border-secondary shadow-sm fw-bold w-100 mb-1" onclick="v8AbrirModalDigitar(${x.ID})"><i class="fas fa-edit"></i> Personalizar e Digitar</button>` : `<button class="btn btn-sm btn-dark border-secondary shadow-sm fw-bold w-100 mb-1" disabled><i class="fas fa-lock"></i> Digitação Bloqueada</button>`; 
                    } 
                } 
                
                if (x.STATUS_PROPOSTA_REAL_TIME || (x.STATUS_V8 && x.STATUS_V8.includes('PROPOSTA:'))) { let propId = x.NUMERO_PROPOSTA || x.STATUS_V8.replace('PROPOSTA:', '').trim(); let propStatus = x.STATUS_PROPOSTA_REAL_TIME ? x.STATUS_PROPOSTA_REAL_TIME.toUpperCase() : 'AGUARDANDO'; let corBadge = 'warning text-dark'; if(propStatus.includes('CAN') || propStatus.includes('ERR')) corBadge = 'danger'; if(propStatus.includes('APROV') || propStatus.includes('PAG')) corBadge = 'success'; let btnFormalizacao = (x.LINK_PROPOSTA && x.LINK_PROPOSTA !== 'null' && x.LINK_PROPOSTA !== '') ? `<button class="btn btn-sm btn-outline-primary mt-1 shadow-sm w-100 fw-bold" style="font-size:10px;" onclick="v8VerLinkAssinatura('${x.LINK_PROPOSTA}')"><i class="fas fa-link"></i> Assinatura</button>` : ''; colProp = `<span class="badge bg-${corBadge} border border-dark mb-1">${propStatus}</span><br><a href="javascript:void(0)" onclick="v8AbrirAcompanhamento('${propId}')" class="text-primary fw-bold" style="font-size:12px;">${propId}</a>${btnFormalizacao}`; btnAcoes = `<button class="btn btn-sm btn-secondary border-dark shadow-sm fw-bold w-100 mb-1"><i class="fas fa-check"></i> Emitida</button>`; } else if (x.STATUS_V8 === 'ERRO PROPOSTA') { colProp = `<span class="badge bg-danger mb-1">ERRO V8</span><br><small class="text-danger fw-bold d-block" style="font-size:9px;">${msgErroLimpa}</small>`; btnAcoes = perm_digitar ? `<button class="btn btn-sm btn-dark border-secondary shadow-sm fw-bold w-100 mb-1" onclick="v8AbrirModalDigitar(${x.ID})"><i class="fas fa-edit"></i> Corrigir Erro</button>` : `<button class="btn btn-sm btn-dark" disabled>Bloqueado</button>`; } 
                
                tb.innerHTML += `<tr class="border-bottom border-dark"><td class="small fw-bold text-muted">${x.DATA_FILA_BR || ''}</td><td class="text-start"><span class="fw-bold fs-6 text-dark">${x.CPF_CONSULTADO || ''}</span><br><span class="text-muted small" style="font-size:10px;">${x.NOME_COMPLETO || 'NÃO INFORMADO'}</span></td><td class="border-start border-end border-danger bg-light p-2 text-center align-middle">${colAuth}</td><td class="border-end border-danger bg-light p-2 text-center align-middle">${colConf}</td><td class="border-end border-danger bg-light p-2 text-center align-middle">${colSim}</td><td class="border-end border-danger bg-light p-2 text-center align-middle">${colProp}</td><td class="p-2 text-center align-middle">${btnAcoes}</td></tr>`; 
            }); 
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]')); tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl); }); 
        } 
    }

    async function v8ExecutarPasso1E2() { 
        let cpf = document.getElementById('v8_input_cpf').value.replace(/\D/g, ''); 
        const chaveId = document.getElementById('v8_cobrar_manual').value; 
        const nome = document.getElementById('v8_input_nome').value; 
        const nascimento = document.getElementById('v8_input_nascimento').value;
        const genero = document.getElementById('v8_input_genero').value;
        const telefone = document.getElementById('v8_input_telefone').value; 
        
        if(!cpf || !nome || !nascimento) return alert("Preencha CPF, Nome e Nascimento."); 
        if(!chaveId) return alert("Selecione a Chave no topo."); 
        
        let btnGerar = document.getElementById('btn_gerar_consent');
        let textoOriginal = btnGerar.innerHTML;
        btnGerar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Iniciando...';
        btnGerar.disabled = true;

        const res = await v8Req('v8_api.ajax.php', 'solicitar_consulta_cpf', { cpf: cpf, nascimento: nascimento, genero: genero, nome: nome, telefone: telefone, chave_id: chaveId }, false); 
        
        btnGerar.innerHTML = textoOriginal;
        btnGerar.disabled = false;

        if(!res.success) { v8CarregarFila(); return alert("Erro: " + res.msg); } 
        
        v8LimparFormulario();
        v8CarregarFila(); 
        v8AtualizarSaldosTopo();
        v8IniciarPollingMargem(res.id_fila); 
    }

    async function v8IniciarPollingMargem(idFila) { 
        if(!windowPollingData) windowPollingData = {}; 
        if(windowPollingData[idFila]) return; 
        windowPollingData[idFila] = { attempts: 0, timer: null }; 
        v8CarregarFila(); 
        const tick = async () => { 
            windowPollingData[idFila].attempts++; 
            if(windowPollingData[idFila].attempts > 12) { v8PararFluxo(idFila, 'TEMPO ESGOTADO'); return alert("A Dataprev demorou muito na fila " + idFila + ". O sistema parou de escutar. Tente forçar a margem depois."); } 
            const resPolling = await v8Req('v8_api.ajax.php', 'checar_margem_e_simular', { id_fila: idFila }, false); 
            if(resPolling.status === 'concluido') { clearTimeout(windowPollingData[idFila].timer); delete windowPollingData[idFila]; v8CarregarFila(); return; } 
            else if(resPolling.status === 'erro') { clearTimeout(windowPollingData[idFila].timer); delete windowPollingData[idFila]; v8CarregarFila(); return alert("❌ Erro Dataprev: " + resPolling.msg); } 
            else if (resPolling.status === 'pendente') { if (windowPollingData[idFila]) { windowPollingData[idFila].timer = setTimeout(tick, 10000); } } 
        }; 
        windowPollingData[idFila].timer = setTimeout(tick, 5000); 
    }

    async function v8BotaoNovaMargem(idFila, forcar_dataprev = false) { v8IniciarPollingMargem(idFila); }
    async function v8ReenviarAut(idFila) { const res = await v8Req('v8_api.ajax.php', 'reenviar_autorizacao_automatica', { id_fila: idFila }, true, "Reenviando..."); v8CarregarFila(); if(res.success) alert("Autorizado!"); else alert("Erro: " + res.msg); }
    async function v8PararFluxo(idFila, motivo = 'PARADO PELO USUÁRIO') { if(motivo === 'PARADO PELO USUÁRIO' && !confirm("Deseja parar?")) return; if(windowPollingData && windowPollingData[idFila]) { clearTimeout(windowPollingData[idFila].timer); delete windowPollingData[idFila]; } await v8Req('v8_api.ajax.php', 'parar_fluxo', { id_fila: idFila, motivo: motivo }, true, "Parando..."); v8CarregarFila(); }
    
    function v8AbrirAcompanhamento(numProposta) { document.querySelector('[data-bs-target="#tab-acompanhamento"]').click(); document.getElementById('filtro_prop_numero').value = numProposta; document.getElementById('filtro_prop_cpf').value = ''; document.getElementById('filtro_prop_status').value = ''; v8CarregarPropostas(); }
    async function v8CarregarPropostas() { const tb = document.getElementById('v8_tbody_propostas'); tb.innerHTML = '<tr><td colspan="5" class="py-4 text-center"><i class="fas fa-spinner fa-spin"></i></td></tr>'; const payload = { data_ini: document.getElementById('filtro_prop_data_ini') ? document.getElementById('filtro_prop_data_ini').value : '', data_fim: document.getElementById('filtro_prop_data_fim') ? document.getElementById('filtro_prop_data_fim').value : '', cpf: document.getElementById('filtro_prop_cpf').value.replace(/\D/g, ''), numero: document.getElementById('filtro_prop_numero').value, status: document.getElementById('filtro_prop_status').value }; const res = await v8Req('ajax_api_v8_acompanhamento_proposta.php', 'listar_propostas', payload, false); if (res.success) { tb.innerHTML = ''; if(res.data.length === 0) return tb.innerHTML = '<tr><td colspan="5" class="text-muted py-4 text-center fw-bold">Nenhuma proposta.</td></tr>'; res.data.forEach(p => { let statusUpper = p.STATUS_PROPOSTA_V8 ? p.STATUS_PROPOSTA_V8.toUpperCase() : 'AGUARDANDO'; let btnColor = "secondary"; if (statusUpper.includes('PEND')) btnColor = "warning text-dark"; if (statusUpper.includes('CAN') || statusUpper.includes('ERR')) btnColor = "danger"; if (statusUpper.includes('APROV') || statusUpper.includes('PAG')) btnColor = "success"; let linkHtml = p.LINK_PROPOSTA && p.LINK_PROPOSTA !== '' && p.LINK_PROPOSTA !== 'null' ? `<br><button class="btn btn-sm btn-outline-primary mt-2 shadow-sm" onclick="v8VerLinkAssinatura('${p.LINK_PROPOSTA}')"><i class="fas fa-link"></i> Copiar Assinatura</button>` : ''; tb.innerHTML += `<tr class="border-bottom border-dark"><td class="text-center small text-muted">${p.DATA_DIGITACAO_BR || ''}</td><td><span class="fw-bold">${p.CPF_CLIENTE || ''}</span><br><small>${p.NOME_CLIENTE || ''}</small></td><td class="text-center"><b class="text-success">R$ ${p.VALOR_LIBERADO || '0.00'}</b><br><small class="text-muted">${p.PRAZO || '0'}x de R$ ${p.PARCELA || '0.00'}</small></td><td class="text-center bg-light p-2"><span class="fw-bold">${p.NUMERO_PROPOSTA || ''}</span><br><span class="badge bg-${btnColor} mt-1">${statusUpper}</span>${linkHtml}</td><td class="p-2"><button class="btn btn-sm btn-info w-100 mb-1" onclick="v8SincronizarStatus(${p.ID})"><i class="fas fa-sync"></i> Status</button><button class="btn btn-sm btn-warning w-100 mb-1" onclick="v8AbrirModalPendencia(${p.ID}, '${p.NUMERO_PROPOSTA}')"><i class="fas fa-exclamation-circle"></i> Pendência</button><button class="btn btn-sm btn-danger w-100" onclick="v8CancelarProposta(${p.ID}, '${p.NUMERO_PROPOSTA}')"><i class="fas fa-times-circle"></i> Cancelar</button></td></tr>`; }); } }
    async function v8SincronizarStatus(idDb) { const res = await v8Req('ajax_api_v8_acompanhamento_proposta.php', 'atualizar_status', { id_db: idDb }, true, "Consultando API..."); if(res.success) { alert(res.msg); v8CarregarPropostas(); } else { alert("Erro: " + res.msg); } }
    async function v8CancelarProposta(idDb, numProposta) { let c1 = confirm(`Deseja CANCELAR a proposta ${numProposta}?`); if(!c1) return; let c2 = prompt(`Digite o MOTIVO DO CANCELAMENTO:`); if(c2 === null || c2.trim() === '') return; const res = await v8Req('ajax_api_v8_acompanhamento_proposta.php', 'cancelar_proposta', { id_db: idDb, motivo: c2 }, true, "Cancelando..."); if(res.success) { alert("✅ " + res.msg); v8CarregarPropostas(); } else { alert("❌ Erro: " + res.msg); } }
    function v8AbrirModalPendencia(idDb, numProposta) { document.getElementById('v8_pend_id_db').value = idDb; document.getElementById('v8_pend_num_prop').value = numProposta; document.getElementById('v8_pend_pix').value = ''; v8Modais.pendencia.show(); }
    async function v8ResolverPendenciaPix() { let idDb = document.getElementById('v8_pend_id_db').value; let pix = document.getElementById('v8_pend_pix').value; let tipo = document.getElementById('v8_pend_tipo_pix').value; if(!pix) return alert("Digite a nova chave PIX."); const res = await v8Req('ajax_api_v8_acompanhamento_proposta.php', 'resolver_pendencia_pix', { id_db: idDb, pix: pix, tipo_pix: tipo }, true, "Enviando..."); if(res.success) { alert("✅ " + res.msg); v8Modais.pendencia.hide(); v8CarregarPropostas(); } else { alert("❌ Erro: " + res.msg); } }
    function v8VerLinkAssinatura(link) { if (!link || link === 'null' || link === '') return alert("Link não disponível."); Swal.fire({ title: '<strong>Link de Assinatura V8</strong>', html: `<input type="text" id="v8_swal_link" class="form-control text-center fw-bold text-primary" value="${link}" readonly>`, showCancelButton: true, confirmButtonText: 'Copiar Link', cancelButtonText: 'Abrir Link' }).then((result) => { if (result.isConfirmed) { document.getElementById("v8_swal_link").select(); document.execCommand("copy"); Swal.fire('Copiado!', '', 'success'); } else if (result.dismiss === Swal.DismissReason.cancel) { window.open(link, '_blank'); } }); }
    function v8MascaraTelefone(i) { let v = i.value.replace(/\D/g,''); document.getElementById('v8_dig_telefone').value = v; if(v.length > 11) v = v.substring(0,11); if(v.length > 2) v = `(${v.substring(0,2)}) ${v.substring(2)}`; if(v.length > 10) v = `${v.substring(0,10)}-${v.substring(10)}`; i.value = v; }
    function v8LimparTelefoneManual() { document.getElementById('v8_dig_telefone_sel').classList.add('d-none'); document.getElementById('v8_dig_telefone_input').classList.remove('d-none'); document.getElementById('v8_dig_telefone_input').value = ''; document.getElementById('v8_dig_telefone').value = ''; document.getElementById('v8_dig_telefone_input').focus(); document.getElementById('btn_novo_telefone').classList.add('d-none'); }
    function v8LimparEnderecoManual() { document.getElementById('v8_dig_seletor_endereco').value = ''; document.getElementById('v8_dig_cep').value = ''; document.getElementById('v8_dig_logradouro').value = ''; document.getElementById('v8_dig_numero').value = ''; document.getElementById('v8_dig_bairro').value = ''; document.getElementById('v8_dig_cidade').value = ''; document.getElementById('v8_dig_uf').value = ''; document.getElementById('v8_dig_cep').focus(); }
    
    async function v8AbrirModalDigitar(idFila) { 
        try { 
            const fila = windowDadosFilaAtual.find(f => f.ID == idFila); 
            if(!fila) return alert("Erro: Dados não encontrados."); 
            document.getElementById('v8_id_fila_modal').value = idFila; 
            document.getElementById('v8_dig_cpf').value = fila.CPF_CONSULTADO; 
            document.getElementById('v8_dig_nome').value = fila.NOME_COMPLETO || ''; 
            document.getElementById('v8_dig_email').value = fila.EMAIL || 'cliente@gmail.com'; 
            document.getElementById('v8_dig_pix').value = fila.CPF_CONSULTADO; 
            document.getElementById('v8_dig_observacao').value = ''; 
            document.getElementById('v8_dig_nascimento').value = ''; 
            document.getElementById('v8_dig_mae').value = ''; 
            document.getElementById('v8_dig_rg').value = ''; 
            v8Loading(true, "Buscando dados..."); 
            const resDb = await v8Req('ajax_api_v8_digitacao.php', 'buscar_dados_cliente_db', { cpf: fila.CPF_CONSULTADO }, false); 
            v8Loading(false); 
            if(resDb.success && resDb.dados) { 
                let d = resDb.dados; 
                if(d.cadastrais && d.cadastrais.cpf) { 
                    if(d.cadastrais.nascimento) document.getElementById('v8_dig_nascimento').value = d.cadastrais.nascimento; 
                    if(d.cadastrais.nome_mae) document.getElementById('v8_dig_mae').value = d.cadastrais.nome_mae; 
                    if(d.cadastrais.rg) document.getElementById('v8_dig_rg').value = d.cadastrais.rg; 
                    if(d.cadastrais.sexo) document.getElementById('v8_dig_sexo').value = d.cadastrais.sexo.toUpperCase().startsWith('M') ? 'M' : 'F'; 
                } 
                if(d.emails && d.emails.email) document.getElementById('v8_dig_email').value = d.emails.email; 
                document.getElementById('v8_dig_telefone_sel').classList.remove('d-none'); 
                document.getElementById('v8_dig_telefone_input').classList.add('d-none'); 
                document.getElementById('btn_novo_telefone').classList.remove('d-none'); 
                let selectTel = document.getElementById('v8_dig_telefone_sel'); 
                selectTel.innerHTML = ''; 
                if(d.telefones && d.telefones.length > 0) { 
                    d.telefones.forEach(t => { 
                        let n = t.telefone_cel.replace(/\D/g, ''); 
                        if(n.length >= 10) { 
                            let fmt = n.length === 11 ? `(${n.substring(0,2)}) ${n.substring(2,7)}-${n.substring(7,11)}` : `(${n.substring(0,2)}) ${n.substring(2,6)}-${n.substring(6,10)}`; 
                            selectTel.innerHTML += `<option value="${n}">${fmt}</option>`; 
                        } 
                    }); 
                } else { 
                    selectTel.innerHTML = '<option value="">Sem telefone</option>'; 
                } 
                document.getElementById('v8_dig_telefone').value = selectTel.options.length > 0 ? selectTel.options[0].value : ''; 
                let selectEnd = document.getElementById('v8_dig_seletor_endereco'); 
                selectEnd.innerHTML = ''; 
                listaEnderecosFC = d.enderecos || []; 
                if(listaEnderecosFC.length > 0) { 
                    listaEnderecosFC.forEach((end, index) => { 
                        listaEnderecosFC[index] = { LOGRADOURO: end.logradouro, NUMERO: end.numero, BAIRRO: end.bairro, CIDADE: end.cidade, ESTADO: end.uf, CEP: end.cep }; 
                        selectEnd.innerHTML += `<option value="${index}">${end.logradouro}, ${end.numero||'0'} - ${end.cidade}</option>`; 
                    }); 
                    v8PreencherEnderecoSelecionado(); 
                } else { 
                    selectEnd.innerHTML = '<option value="">Nenhum endereço...</option>'; 
                    document.getElementById('v8_dig_cep').value = ''; 
                    document.getElementById('v8_dig_logradouro').value = ''; 
                } 
            } 
            let prazos = []; 
            try { 
                prazos = JSON.parse(fila.PRAZOS); 
                if(!Array.isArray(prazos)) prazos = [24, 36, 48, 60, 72, 84]; 
            } catch(e) { 
                prazos = [24, 36, 48, 60, 72, 84]; 
            } 
            let options = ''; 
            prazos.forEach(p => options += `<option value="${p}">${p} Meses</option>`); 
            document.getElementById('v8_sim_prazo').innerHTML = options; 
            document.getElementById('v8_sim_valor').value = fila.VALOR_MARGEM; 
            await v8CarregarHistoricoSimulacoes(idFila); 
            v8Modais.simulacao.show(); 
        } catch (e) { alert("Erro: " + e.message); } 
    }
    
    async function v8AtualizarDadosDigitacaoFC() { 
        let cpf = document.getElementById('v8_dig_cpf').value.replace(/\D/g, ''); 
        const chaveId = document.getElementById('v8_cobrar_manual').value; 
        if(!chaveId) return alert("Selecione a Chave no topo da tela."); 

        let chaveObj = windowListaChavesCache.find(c => c.ID == chaveId);
        let cpfDonoFC = chaveObj ? (chaveObj.CPF_USUARIO || chaveObj.USUARIO_ID) : '';
        if(!cpfDonoFC) return alert("Erro: Chave V8 sem um usuário atrelado.");

        v8Loading(true, "Consultando API..."); 
        try { 
            const fd = new FormData(); 
            fd.append('acao', 'consulta_cpf_manual'); 
            fd.append('cpf', cpf); 
            fd.append('cpf_cobrar', cpfDonoFC); 
            fd.append('fonte', 'V8_DIGITACAO'); 
            
            const r = await fetch('/modulos/configuracao/fator_conferi/fator_conferi.ajax.php', { method: 'POST', body: fd }); 
            const res = await r.json(); 
            v8Loading(false); 
            
            if (res.success) { 
                alert("✅ Dados atualizados!"); 
                let cad = res.json_bruto.CADASTRAIS || {}; 
                document.getElementById('v8_dig_nome').value = cad.NOME || ''; 
                let nasc = cad.NASCTO || ''; 
                if(nasc.includes('/')) { let p = nasc.split('/'); document.getElementById('v8_dig_nascimento').value = `${p[2]}-${p[1]}-${p[0]}`; } 
                document.getElementById('v8_dig_mae').value = cad.NOME_MAE || ''; 
                document.getElementById('v8_dig_rg').value = cad.RG || ''; 
                document.getElementById('v8_dig_sexo').value = (cad.SEXO && cad.SEXO.toUpperCase().startsWith('M')) ? 'M' : 'F'; 
                
                let selectEnd = document.getElementById('v8_dig_seletor_endereco'); selectEnd.innerHTML = ''; 
                listaEnderecosFC = res.json_bruto.ENDERECOS?.ENDERECO || []; 
                if (!Array.isArray(listaEnderecosFC)) listaEnderecosFC = [listaEnderecosFC]; 
                
                if(listaEnderecosFC.length > 0 && listaEnderecosFC[0].CEP) { 
                    listaEnderecosFC.forEach((end, i) => { selectEnd.innerHTML += `<option value="${i}">${end.LOGRADOURO}, ${end.NUMERO||'0'} - ${end.CIDADE}</option>`; }); 
                    v8PreencherEnderecoSelecionado(); 
                } 
                v8AtualizarSaldosTopo();
            } else { 
                alert("⚠️ Erro: " + res.msg); 
            } 
        } catch(e) { v8Loading(false); alert("Erro de comunicação."); } 
    }

    function v8PreencherEnderecoSelecionado() { let index = document.getElementById('v8_dig_seletor_endereco').value; if(index === "") return; let end = listaEnderecosFC[index]; document.getElementById('v8_dig_cep').value = end.CEP ? end.CEP.replace(/\D/g, '') : ''; document.getElementById('v8_dig_logradouro').value = end.LOGRADOURO || ''; document.getElementById('v8_dig_numero').value = end.NUMERO && end.NUMERO !== 'S/N' ? end.NUMERO : '0'; document.getElementById('v8_dig_bairro').value = end.BAIRRO || ''; document.getElementById('v8_dig_cidade').value = end.CIDADE || ''; document.getElementById('v8_dig_uf').value = end.ESTADO || ''; }
    async function v8ExecutarSimulacao(tipo, idFila) { v8Loading(true, "Simulando..."); let payload = { id_fila: idFila, tipo: tipo }; if (tipo === 'PERSONALIZADA') { payload.prazo = document.getElementById('v8_sim_prazo').value; payload.valor = document.getElementById('v8_sim_valor').value; payload.tipo_busca = document.getElementById('v8_sim_tipo_busca').value; if (!payload.valor || payload.valor <= 0) { v8Loading(false); return alert("Digite um valor válido."); } } const res = await v8Req('ajax_api_v8_digitacao.php', 'passo4_simular', payload, false); v8Loading(false); if (res.success) { if (tipo === 'PADRÃO') v8CarregarFila(); else v8CarregarHistoricoSimulacoes(idFila); } else alert("❌ Erro: " + res.msg); }
    function v8FazerSimulacaoPersonalizada() { let idFila = document.getElementById('v8_id_fila_modal').value; v8ExecutarSimulacao('PERSONALIZADA', idFila); }
    async function v8CarregarHistoricoSimulacoes(idFila) { const tb = document.getElementById('v8_bloco2_historico'); tb.innerHTML = '<tr><td colspan="6" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></td></tr>'; const res = await v8Req('ajax_api_v8_digitacao.php', 'listar_simulacoes_banco', { id_fila: idFila }, false); if (res.success) { tb.innerHTML = ''; if (res.data.length === 0) return tb.innerHTML = '<tr><td colspan="6" class="text-center py-3">Nenhuma simulação.</td></tr>'; res.data.forEach(s => { tb.innerHTML += `<tr><td><input type="radio" name="radio_simulacao" value="${s.ID}" class="form-check-input" style="width: 20px; height: 20px; cursor: pointer;"></td><td class="fw-bold">${s.TIPO_SIMULACAO}</td><td>${s.PRAZO_SIMULACAO}x</td><td class="text-danger fw-bold">R$ ${parseFloat(s.VALOR_PARCELA).toFixed(2).replace('.',',')}</td><td class="text-success fw-bold">R$ ${parseFloat(s.VALOR_LIBERADO).toFixed(2).replace('.',',')}</td><td class="small text-muted">${s.DATA_BR}</td></tr>`; }); } }
    async function v8EnviarProposta() { let idFila = document.getElementById('v8_id_fila_modal').value; let radios = document.getElementsByName('radio_simulacao'); let idSimBanco = null; for (let i = 0; i < radios.length; i++) { if (radios[i].checked) { idSimBanco = radios[i].value; break; } } if (!idSimBanco) return alert("Selecione uma simulação no Bloco 2 clicando na bolinha (ESCOLHER)."); let payload = { id_fila: idFila, id_sim_banco: idSimBanco, nome: document.getElementById('v8_dig_nome').value, email: document.getElementById('v8_dig_email').value, nascimento: document.getElementById('v8_dig_nascimento').value, nome_mae: document.getElementById('v8_dig_mae').value, rg: document.getElementById('v8_dig_rg').value, sexo: document.getElementById('v8_dig_sexo').value, telefone: document.getElementById('v8_dig_telefone').value, cep: document.getElementById('v8_dig_cep').value, logradouro: document.getElementById('v8_dig_logradouro').value, numero: document.getElementById('v8_dig_numero').value, bairro: document.getElementById('v8_dig_bairro').value, cidade: document.getElementById('v8_dig_cidade').value, uf: document.getElementById('v8_dig_uf').value, pix: document.getElementById('v8_dig_pix').value, tipo_pix: document.getElementById('v8_dig_tipo_pix').value, obs: document.getElementById('v8_dig_observacao').value }; if(!payload.pix) return alert("A chave PIX no Bloco 3 é obrigatória."); const res = await v8Req('ajax_api_v8_digitacao.php', 'passo5_enviar_proposta', payload, true, "Enviando..."); if (res.success) { alert("✅ Proposta gerada."); v8VerLinkAssinatura(res.url); v8Modais.simulacao.hide(); v8CarregarFila(); v8CarregarPropostas(); v8AtualizarSaldosTopo(); } else alert("❌ Erro: " + res.msg); }

</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; 
if(file_exists($caminho_footer)) include $caminho_footer; 
?>