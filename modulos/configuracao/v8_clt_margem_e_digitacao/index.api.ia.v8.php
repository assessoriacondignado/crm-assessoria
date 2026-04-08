<?php
// Arquivo: index.api.ia.v8.php
// Interface limpa e modularizada para gestão da API de IA
@session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->exec("SET NAMES utf8mb4");

// =========================================================================
// BLOQUEIO DE PERMISSÃO (SEGURANÇA DO GRUPO)
// =========================================================================
$acessoLiberado = true; 
$grupo_logado = '';
$id_sessao_ia = (int)($_SESSION['usuario_id'] ?? $_SESSION['id_usuario'] ?? 0);

if ($id_sessao_ia > 0) {
    $stmtDbGrp = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO WHERE ID = ? LIMIT 1");
    $stmtDbGrp->execute([$id_sessao_ia]);
    $grp_ia_banco = $stmtDbGrp->fetchColumn();
    if ($grp_ia_banco) { $grupo_logado = $grp_ia_banco; }
}

if (empty($grupo_logado)) { $grupo_logado = $_SESSION['GRUPO_USUARIOS'] ?? $_SESSION['grupo_usuarios'] ?? $_SESSION['grupo'] ?? ''; }

if (!empty($grupo_logado)) {
    $stmtPerm = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = 'SUBMENU_OP_INTEGRACAO_V8_IA' AND UPPER(TRIM(GRUPO_USUARIOS)) = UPPER(TRIM(?))");
    $stmtPerm->execute([$grupo_logado]);
    if ($stmtPerm->fetchColumn() > 0) { $acessoLiberado = false; }
}

$perfil_ia = (int)($_SESSION['perfil'] ?? 0);
if ($perfil_ia === 1 || strtoupper(trim($grupo_logado)) === 'MASTER' || strtoupper(trim($grupo_logado)) === 'ADMIN') {
    $acessoLiberado = true;
}

if (!$acessoLiberado):
?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8 mt-4">
                <div class="alert alert-danger text-center shadow-sm border-danger p-5 rounded">
                    <i class="fas fa-lock text-danger mb-3" style="font-size: 4rem;"></i>
                    <h4 class="fw-bold">Acesso Restrito</h4>
                    <p class="mb-0 fs-6">Seu grupo de usuário (<b><?= htmlspecialchars($grupo_logado) ?></b>) não possui autorização para acessar o painel de <b>Atendimento IA</b>.</p>
                </div>
            </div>
        </div>
    </div>
<?php
else:
?>

    <div class="row mb-4">
        <div class="col-md-12">
            <div class="alert alert-danger shadow-sm border-danger">
                <h5 class="fw-bold mb-1"><i class="fas fa-robot"></i> Gestão de API para Inteligência Artificial (M2M)</h5>
                <p class="mb-0 small">Gere tokens de acesso para suas ferramentas de automação (Voiceflow, Typebot, n8n, etc.). Todo o custo de consulta gerado pela IA será debitado do saldo do usuário vinculado ao Token.</p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-dark fw-bold mb-0"><i class="fas fa-key text-warning me-2"></i> Tokens de Acesso (Credenciais IA)</h6>
                <button class="btn btn-dark btn-sm fw-bold shadow-sm" onclick="abrirModalNovoTokenIA()">
                    <i class="fas fa-plus me-1"></i> Gerar Novo Token
                </button>
            </div>
            
            <div class="table-responsive border border-dark rounded shadow-sm bg-white" style="min-height: 250px; padding-bottom: 20px;">
                <table class="table table-hover align-middle mb-0 text-center text-nowrap" style="font-size: 13px;">
                    <thead class="table-dark">
                        <tr>
                            <th>Nome do Robô / Automação</th>
                            <th>Chave V8 Associada</th>
                            <th>Dono do Saldo (CPF)</th>
                            <th>Token de Autenticação (Bearer)</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_tokens_ia">
                        <tr><td colspan="6" class="py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando tokens...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-dark fw-bold mb-0"><i class="fas fa-history text-primary me-2"></i> Monitor de Atendimentos da IA (Ao Vivo)</h6>
                <button class="btn btn-outline-dark bg-white btn-sm fw-bold shadow-sm" onclick="carregarSessoesIA()">
                    <i class="fas fa-sync-alt me-1"></i> Atualizar Painel
                </button>
            </div>
            
            <div class="table-responsive border border-dark rounded shadow-sm bg-white" style="max-height: 500px; overflow-y: auto; padding-bottom: 80px;">
                <table class="table table-hover align-middle mb-0 text-center text-nowrap table-sm" style="font-size: 12px;">
                    <thead class="table-secondary border-dark sticky-top">
                        <tr>
                            <th>Data / Status IA</th>
                            <th class="text-start">Robô / Chave</th>
                            <th class="text-start">Cliente / Telefone</th>
                            <th class="border-start border-end border-danger">Autorização ID Consulta</th>
                            <th class="border-end border-danger">ID Config (Margem)</th>
                            <th class="border-end border-danger">ID Simulação</th>
                            <th class="border-end border-danger">ID Proposta</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sessoes_ia">
                        <tr><td colspan="7" class="py-4 text-muted">Aguardando atendimentos...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalTokenIA" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-dark shadow-lg">
                <div class="modal-header bg-dark text-white border-bottom border-dark">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-robot text-warning me-2"></i> Nova Credencial para IA</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <form id="formTokenIA" class="row g-3">
                        <div class="col-md-12">
                            <label class="fw-bold small text-dark mb-1">Nome de Identificação do Robô:</label>
                            <input type="text" id="ia_nome_robo" class="form-control border-dark fw-bold" placeholder="Ex: Typebot WhatsApp Vendas" required>
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold small text-dark mb-1 text-success">Usar qual Chave V8 para consulta?</label>
                            <select id="ia_chave_v8" class="form-select border-success fw-bold v8-dropdown-clientes" required></select>
                            <small class="text-muted"><i class="fas fa-info-circle"></i> O custo do robô será descontado automaticamente da conta do usuário dono desta chave.</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer bg-light border-top border-secondary">
                    <button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold px-5 shadow-sm border-dark" onclick="salvarNovoTokenIA()"><i class="fas fa-save me-2"></i> Gerar Token</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarTokenIA" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-dark shadow-lg">
                <div class="modal-header bg-primary text-white border-bottom border-dark">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-warning me-2"></i> Editar Credencial IA</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <form class="row g-3">
                        <input type="hidden" id="edit_ia_id">
                        <div class="col-md-12">
                            <label class="fw-bold small text-dark mb-1">Nome de Identificação do Robô:</label>
                            <input type="text" id="edit_ia_nome_robo" class="form-control border-dark fw-bold" required>
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold small text-dark mb-1 text-success">Mudar Chave V8:</label>
                            <select id="edit_ia_chave_v8" class="form-select border-success fw-bold v8-dropdown-clientes" required></select>
                            <small class="text-muted"><i class="fas fa-info-circle"></i> Se você alterar a chave, as próximas cobranças vão para o dono da nova chave.</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer bg-light border-top border-secondary">
                    <button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold px-5 shadow-sm border-dark" onclick="salvarEdicaoTokenIA()"><i class="fas fa-save me-2"></i> Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let modalTokenIAObj;
        let modalEditarTokenIAObj;
        const ARQUIVO_AJAX_IA = 'ajax_api_ia_v8.php';
        let intervalMonitorIA = null;

        document.addEventListener("DOMContentLoaded", () => {
            if(document.getElementById('modalTokenIA')) modalTokenIAObj = new bootstrap.Modal(document.getElementById('modalTokenIA'));
            if(document.getElementById('modalEditarTokenIA')) modalEditarTokenIAObj = new bootstrap.Modal(document.getElementById('modalEditarTokenIA'));
            
            const tabIA = document.querySelector('[data-bs-target="#tab-atendimento-ia"]');
            if(tabIA) {
                tabIA.addEventListener('shown.bs.tab', function (e) {
                    carregarTokensIA();
                    carregarSessoesIA();
                    if(!intervalMonitorIA) intervalMonitorIA = setInterval(carregarSessoesIA, 10000); 
                });
                tabIA.addEventListener('hidden.bs.tab', function (e) {
                    if(intervalMonitorIA) { clearInterval(intervalMonitorIA); intervalMonitorIA = null; }
                });
            } else {
                carregarTokensIA();
                carregarSessoesIA();
            }
        });

        function abrirModalNovoTokenIA() {
            if(modalTokenIAObj) {
                document.getElementById('formTokenIA').reset();
                modalTokenIAObj.show();
            }
        }

        function abrirModalEditarTokenIA(id, nome, chave_id) {
            document.getElementById('edit_ia_id').value = id;
            document.getElementById('edit_ia_nome_robo').value = nome;
            document.getElementById('edit_ia_chave_v8').value = chave_id;
            modalEditarTokenIAObj.show();
        }

        async function salvarNovoTokenIA() {
            const nome_robo = document.getElementById('ia_nome_robo').value;
            const chave_v8_id = document.getElementById('ia_chave_v8').value;

            if(!nome_robo || !chave_v8_id) return alert("Preencha todos os campos corretamente.");

            const res = await v8Req(ARQUIVO_AJAX_IA, 'salvar_token', { nome_robo: nome_robo, chave_v8_id: chave_v8_id }, true, "Gerando Token Seguro...");
            if (res.success) { alert("✅ " + res.msg); modalTokenIAObj.hide(); carregarTokensIA(); } else { alert("❌ Erro: " + res.msg); }
        }

        async function salvarEdicaoTokenIA() {
            const payload = {
                id: document.getElementById('edit_ia_id').value,
                nome_robo: document.getElementById('edit_ia_nome_robo').value,
                chave_v8_id: document.getElementById('edit_ia_chave_v8').value
            };
            if(!payload.nome_robo || !payload.chave_v8_id) return alert("Preencha os campos obrigatórios.");
            
            const res = await v8Req(ARQUIVO_AJAX_IA, 'editar_token', payload, true, "Salvando...");
            if(res.success) { alert("✅ " + res.msg); modalEditarTokenIAObj.hide(); carregarTokensIA(); } else { alert("❌ Erro: " + res.msg); }
        }

        async function carregarTokensIA() {
            const tb = document.getElementById('tbody_tokens_ia');
            if(!tb) return;
            const res = await v8Req(ARQUIVO_AJAX_IA, 'listar_tokens', {}, false);
            if (res.success) {
                tb.innerHTML = '';
                if (res.data.length === 0) { tb.innerHTML = '<tr><td colspan="6" class="py-4 text-muted fw-bold">Nenhum token gerado ainda.</td></tr>'; return; }
                res.data.forEach(t => {
                    let badgeStatus = t.STATUS === 'ATIVO' ? '<span class="badge bg-success">ATIVO</span>' : '<span class="badge bg-danger">INATIVO</span>';
                    
                    let actReativarOuRevogar = t.STATUS === 'ATIVO' 
                        ? `<li><a class="dropdown-item fw-bold text-warning" href="#" onclick="revogarTokenIA(${t.ID})"><i class="fas fa-ban me-2"></i> Revogar (Inativar)</a></li>`
                        : `<li><a class="dropdown-item fw-bold text-success" href="#" onclick="reativarTokenIA(${t.ID})"><i class="fas fa-check-circle me-2"></i> Reativar Robô</a></li>`;

                    let btnAcoes = `
                    <div class="dropdown">
                        <button class="btn btn-sm btn-dark dropdown-toggle fw-bold shadow-sm" type="button" data-bs-toggle="dropdown" data-bs-boundary="window">
                            <i class="fas fa-cogs"></i> Opções
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-dark" style="font-size:12px; z-index: 1050;">
                            <li><a class="dropdown-item fw-bold text-primary" href="#" onclick="abrirModalEditarTokenIA(${t.ID}, '${t.NOME_ROBO}', ${t.CHAVE_V8_ID})"><i class="fas fa-edit me-2"></i> Editar Dados</a></li>
                            <li><a class="dropdown-item fw-bold text-secondary" href="#" onclick="gerarNovoTokenIAExistente(${t.ID})"><i class="fas fa-sync-alt me-2"></i> Gerar Novo Bearer V8IA</a></li>
                            <li><hr class="dropdown-divider"></li>
                            ${actReativarOuRevogar}
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="excluirTokenIA(${t.ID})"><i class="fas fa-trash-alt me-2"></i> Excluir (Deletar)</a></li>
                        </ul>
                    </div>`;
                    
                    tb.innerHTML += `
                        <tr class="border-bottom border-dark">
                            <td class="fw-bold text-dark">${t.NOME_ROBO}</td>
                            <td class="text-success fw-bold">${t.NOME_CHAVE_V8}</td>
                            <td class="text-danger fw-bold">${t.CPF_DONO}</td>
                            <td>
                                <div class="input-group input-group-sm w-75 mx-auto">
                                    <input type="text" class="form-control border-dark text-center fw-bold text-primary" value="${t.TOKEN_IA}" readonly id="tk_${t.ID}">
                                    <button class="btn btn-dark border-dark" type="button" onclick="copiarTokenIA('tk_${t.ID}')" title="Copiar Token"><i class="fas fa-copy"></i></button>
                                </div>
                            </td>
                            <td>${badgeStatus}</td>
                            <td>${btnAcoes}</td>
                        </tr>
                    `;
                });
            }
        }

        async function revogarTokenIA(id) {
            if(!confirm("Tem certeza que deseja REVOGAR este Token? O robô perderá acesso!")) return;
            const res = await v8Req(ARQUIVO_AJAX_IA, 'revogar_token', { id: id }, true, "Revogando...");
            if(res.success) carregarTokensIA(); else alert("Erro: " + res.msg);
        }

        async function reativarTokenIA(id) {
            if(!confirm("Deseja REATIVAR este robô? O token voltará a dar acesso.")) return;
            const res = await v8Req(ARQUIVO_AJAX_IA, 'reativar_token', { id: id }, true, "Reativando...");
            if(res.success) carregarTokensIA(); else alert("Erro: " + res.msg);
        }

        async function excluirTokenIA(id) {
            if(!confirm("⚠️ CUIDADO: Tem certeza que deseja EXCLUIR DEFINITIVAMENTE este Token?\n\nEsta ação não pode ser desfeita e o robô perderá o acesso instantaneamente!")) return;
            const res = await v8Req(ARQUIVO_AJAX_IA, 'excluir_token', { id: id }, true, "Excluindo...");
            if(res.success) carregarTokensIA(); else alert("Erro: " + res.msg);
        }

        async function gerarNovoTokenIAExistente(id) {
            if(!confirm("⚠️ ATENÇÃO EXTREMA: Deseja gerar um NOVO BEARER para este robô?\n\nO token atual vai parar de funcionar imediatamente e você terá que colar o novo na sua automação.")) return;
            const res = await v8Req(ARQUIVO_AJAX_IA, 'gerar_novo_token_existente', { id: id }, true, "Gerando novo Bearer...");
            if(res.success) { alert("✅ " + res.msg); carregarTokensIA(); } else { alert("Erro: " + res.msg); }
        }

        function copiarTokenIA(idInput) {
            var copyText = document.getElementById(idInput);
            copyText.select(); copyText.setSelectionRange(0, 99999); 
            navigator.clipboard.writeText(copyText.value);
            alert("Token copiado para a área de transferência!");
        }

        // ==========================================
        // SESSÃO DO MONITOR
        // ==========================================
        async function carregarSessoesIA() {
            const tb = document.getElementById('tbody_sessoes_ia');
            if(!tb) return;
            
            if(tb.innerHTML.indexOf('Aguardando') !== -1) tb.innerHTML = '<tr><td colspan="7" class="py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando...</td></tr>';
            
            const res = await v8Req(ARQUIVO_AJAX_IA, 'listar_sessoes', {}, false);
            if (res.success) {
                tb.innerHTML = '';
                if (res.data.length === 0) {
                    tb.innerHTML = '<tr><td colspan="7" class="py-4 text-muted fw-bold">Nenhum atendimento registrado hoje.</td></tr>';
                    return;
                }
                res.data.forEach(x => {
                    let statusSessaoFmt = `<span class="badge bg-dark text-white shadow-sm border border-secondary" style="font-size:9px;">${x.STATUS_SESSAO}</span>`;
                    let colData = `<span class="small fw-bold text-muted">${x.DATA_INICIO_BR}</span><br>${statusSessaoFmt}<br><small class="text-muted" style="font-size:10px;"><i class="fas fa-history text-secondary"></i> Modificado: ${x.ULTIMA_ACAO_BR}</small>`;
                    
                    let colRobo = `<span class="fw-bold text-dark"><i class="fas fa-robot text-warning"></i> ${x.NOME_ROBO || 'IA Base'}</span><br><small class="text-muted" style="font-size:10px;">Chave: ${x.NOME_CHAVE_V8 || '--'}<br>Dono CPF: ${x.CPF_DONO || '--'}</small>`;
                    
                    let cpfCliente = x.CPF_CONSULTADO || x.CPF_SESSAO || '--';
                    let nomeCliente = x.NOME_COMPLETO || 'NÃO INFORMADO';
                    let telCliente = x.TELEFONE_CLIENTE || '--';
                    let colCliente = `<span class="fw-bold fs-6 text-dark">${cpfCliente}</span><br><span class="text-muted small" style="font-size:10px;">${nomeCliente}</span><br><span class="text-primary small fw-bold" style="font-size:10px;"><i class="fas fa-phone-alt"></i> ${telCliente}</span>`;

                    let colAuth = `<span class="badge bg-light text-muted border">Vazio</span>`; 
                    let colConf = `<span class="badge bg-light text-muted border">Vazio</span>`; 
                    let colSim  = `<span class="badge bg-light text-muted border">Vazio</span>`; 
                    let colProp = `<span class="badge bg-light text-muted border">Vazio</span>`;
                    
                    let msgErroLimpa = "Erro na V8"; 
                    if(x.MENSAGEM_ERRO) { 
                        try { let jsonErro = JSON.parse(x.MENSAGEM_ERRO); msgErroLimpa = jsonErro.detail || jsonErro.message || jsonErro.title || "Erro API"; } 
                        catch(e) { msgErroLimpa = String(x.MENSAGEM_ERRO).replace(/"/g, "'"); } 
                    } 

                    let fontAuth = x.FONTE_CONSULT_ID ? x.FONTE_CONSULT_ID : "IA BOT"; 
                    let badgeFonteAuth = `<span class="badge bg-dark rounded-pill mb-1" style="font-size:8px;">FONTE: ${fontAuth}</span><br>`; 
                    let consultIdVisual = x.CONSULT_ID ? String(x.CONSULT_ID).substring(0,8) : 'N/A';
                    
                    if (x.STATUS_V8 === 'ERRO-AUT') { 
                        colAuth = `${badgeFonteAuth}<span class="badge bg-danger mb-1" title="${msgErroLimpa}">ERRO-AUT</span><br><small class="text-muted" style="font-size:10px;">ID: ${consultIdVisual}<br>${x.DATA_RETORNO_BR || ''}</small>`; 
                    } else if (x.STATUS_V8 === 'ERRO ID CONSENTIMENTO') { 
                        colAuth = `<span class="badge bg-danger mb-1" title="${msgErroLimpa}">ERRO API</span><br><small class="text-muted" style="font-size:10px;">${x.DATA_RETORNO_BR || ''}</small>`; 
                    } else if (x.CONSULT_ID) { 
                        colAuth = `${badgeFonteAuth}<span class="badge bg-success mb-1">OK-CONSENTIMENTO</span><br><small class="text-muted" style="font-size:10px;">ID: ${consultIdVisual}<br>${x.DATA_RETORNO_BR || ''}</small>`; 
                    } else if (x.STATUS_V8) { 
                        colAuth = `<span class="badge bg-info text-dark mb-1">Aguardando...</span><br><small class="text-muted" style="font-size:10px;">${x.DATA_RETORNO_BR || ''}</small>`; 
                    }
                    
                    let fontMargem = x.FONTE_CONSIG_ID ? x.FONTE_CONSIG_ID : "V8"; 
                    let badgeFonteMargem = `<span class="badge bg-secondary rounded-pill mb-1" style="font-size:8px;">FONTE: ${fontMargem}</span><br>`; 
                    
                    if (x.STATUS_V8 === 'ERRO-MARGEM' || x.STATUS_V8 === 'ERRO LEITURA MARGEM' || x.STATUS_CONFIG_ID === 'ERRO CONSIG_ID' || x.STATUS_CONFIG_ID === 'ERRO CACHE V8') { 
                        let detalheErroConf = x.OBS_CONFIG_ID || msgErroLimpa; 
                        colConf = `${badgeFonteMargem}<span class="badge bg-danger mb-1">REJEITADO</span><br><small class="text-danger fw-bold d-block" style="font-size:9px;">${detalheErroConf}</small>`; 
                    } else if (x.STATUS_V8 === 'AGUARDANDO MARGEM' || x.STATUS_V8 === 'AGUARDANDO V8 MARGEM E PRAZOS') { 
                        colConf = `${badgeFonteMargem}<span class="badge bg-warning text-dark mb-1"><i class="fas fa-spinner fa-spin"></i> Lendo Margem...</span>`; 
                    } else if (x.VALOR_MARGEM !== null) { 
                        let prazosFormatados = "24x"; 
                        try { if(x.PRAZOS) { let arrPrazos = JSON.parse(x.PRAZOS); prazosFormatados = Array.isArray(arrPrazos) ? arrPrazos.join(', ')+"x" : x.PRAZOS; } } catch(e) {} 
                        colConf = `${badgeFonteMargem}<span class="badge bg-success mb-1">MARGEM OK</span><br><span class="text-success fw-bold fs-6">R$ ${x.VALOR_MARGEM}</span><br><small class="text-dark d-block" style="font-size:10px;">Prazos: ${prazosFormatados}</small>`; 
                        
                        if (x.SIMULATION_ID || x.VALOR_LIBERADO == '0.00') { 
                            let obsSimText = x.OBS_SIMULATION_ID && x.OBS_SIMULATION_ID !== 'Cálculo concluído' ? `<br><small class="text-danger fw-bold mt-1 d-block" style="font-size:9px; line-height: 1.1; white-space: normal;">${x.OBS_SIMULATION_ID}</small>` : '';
                            colSim = `<span class="badge bg-success mb-1">SIMULADO</span><br><b class="text-success fs-6">R$ ${x.VALOR_LIBERADO || '0.00'}</b><br><small class="text-dark" style="font-size:10px;">Parcela: R$ ${x.VALOR_PARCELA || '0.00'} <br>Prazo: ${x.PRAZO_SIMULACAO || 24}x</small>${obsSimText}`; 
                        } 
                    }
                    
                    if (x.STATUS_PROPOSTA_REAL_TIME || (x.STATUS_V8 && x.STATUS_V8.includes('PROPOSTA:')) || x.NUMERO_PROPOSTA) { 
                        let propId = x.NUMERO_PROPOSTA || (x.STATUS_V8 && x.STATUS_V8.includes('PROPOSTA:') ? x.STATUS_V8.replace('PROPOSTA:', '').trim() : ''); 
                        let propStatus = x.STATUS_PROPOSTA_V8 || x.STATUS_PROPOSTA_REAL_TIME || 'AGUARDANDO'; 
                        propStatus = propStatus.toUpperCase();

                        let corBadge = 'warning text-dark'; 
                        if(propStatus.includes('CAN') || propStatus.includes('ERR') || propStatus.includes('REJEITAD')) corBadge = 'danger'; 
                        if(propStatus.includes('APROV') || propStatus.includes('PAG') || propStatus.includes('INTEGRAD')) corBadge = 'success'; 
                        if(propStatus.includes('PIX') || propStatus.includes('PENDEN')) corBadge = 'info text-dark'; 
                        
                        let btnFormalizacao = (x.LINK_PROPOSTA && x.LINK_PROPOSTA !== 'null' && x.LINK_PROPOSTA !== '' && !propStatus.includes('CAN')) 
                            ? `<a href="${x.LINK_PROPOSTA}" target="_blank" class="btn btn-sm btn-outline-primary mt-1 shadow-sm w-100 fw-bold" style="font-size:10px;"><i class="fas fa-link"></i> Assinatura</a>` 
                            : ''; 
                        
                        let btnMais = `
                        <div class="dropdown mt-1">
                            <button class="btn btn-sm btn-dark dropdown-toggle w-100 fw-bold shadow-sm" style="font-size:9px;" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-boundary="window">
                                <i class="fas fa-plus"></i> MAIS
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-dark" style="font-size:11px; z-index: 1050;">
                                <li><a class="dropdown-item fw-bold text-primary" href="#" onclick="acaoPropostaPainel('atualizar_status_proposta', '${propId}', '${cpfCliente}')"><i class="fas fa-sync-alt"></i> Atualizar Status</a></li>
                                <li><a class="dropdown-item fw-bold text-warning" href="#" onclick="acaoPropostaPainel('resolver_pendencia_pix', '${propId}', '${cpfCliente}')"><i class="fas fa-university"></i> Resolver Pendência</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="acaoPropostaPainel('cancelar_proposta_v8', '${propId}', '${cpfCliente}')"><i class="fas fa-times-circle"></i> Cancelar V8</a></li>
                            </ul>
                        </div>`;

                        colProp = `<span class="badge bg-${corBadge} border border-dark mb-1 shadow-sm" style="font-size:10px;">${propStatus}</span><br><span class="text-primary fw-bold" style="font-size:12px;">${propId}</span><br>${btnFormalizacao}${btnMais}`; 
                    } else if (x.STATUS_V8 === 'ERRO PROPOSTA') { 
                        colProp = `<span class="badge bg-danger mb-1">ERRO V8</span><br><small class="text-danger fw-bold d-block" style="font-size:9px;">${msgErroLimpa}</small>`; 
                    }

                    tb.innerHTML += `
                        <tr class="border-bottom border-dark bg-white">
                            <td class="text-center align-middle">${colData}</td>
                            <td class="text-start align-middle">${colRobo}</td>
                            <td class="text-start align-middle">${colCliente}</td>
                            <td class="border-start border-end border-danger bg-light p-2 text-center align-middle">${colAuth}</td>
                            <td class="border-end border-danger bg-light p-2 text-center align-middle">${colConf}</td>
                            <td class="border-end border-danger bg-light p-2 text-center align-middle">${colSim}</td>
                            <td class="border-end border-danger bg-light p-2 text-center align-middle" style="min-width: 140px; overflow: visible;">${colProp}</td>
                        </tr>
                    `;
                });
            }
        }

        async function acaoPropostaPainel(acaoBackend, id_proposta, cpf) {
            let payload = { proposta: id_proposta, cpf: cpf };
            if (acaoBackend === 'resolver_pendencia_pix') {
                let novaChave = prompt(`Digite a nova chave PIX para o cliente (Proposta: ${id_proposta}):`);
                if (!novaChave) return;
                payload.pix = novaChave;
            } else if (acaoBackend === 'cancelar_proposta_v8') {
                if(!confirm(`ALERTA: Você está prestes a ENVIAR O CANCELAMENTO da proposta ${id_proposta} para a V8. Deseja continuar?`)) return;
            }
            const res = await v8Req(ARQUIVO_AJAX_IA, acaoBackend, payload, true, "Processando com a V8...");
            if(res.success) { alert("✅ Sucesso!"); carregarSessoesIA(); } else { alert("❌ Erro: " + (res.msg || res.error || "Falha na comunicação")); }
        }
    </script>
<?php endif; ?>