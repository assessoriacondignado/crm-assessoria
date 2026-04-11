<?php
// Arquivo: index.api.ia.v8.php
// Interface limpa e modularizada para gestão da API de IA
@session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; }
$pdo->exec("SET NAMES utf8mb4");

// =========================================================================
// BLOQUEIO DE PERMISSÃO (usa o mesmo sistema central do projeto)
// =========================================================================
include_once $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
$acessoLiberado = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA', 'FUNCAO');

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
        <!-- TOKENS DE ACESSO — RETRÁTIL, RECOLHIDO POR PADRÃO -->
        <div class="col-md-12 mb-4">
            <div class="d-flex justify-content-between align-items-center p-2 rounded border border-dark bg-dark text-white" style="cursor:pointer;" onclick="toggleTokensIA()">
                <h6 class="text-white fw-bold mb-0"><i class="fas fa-key text-warning me-2"></i> Tokens de Acesso (Credenciais IA)</h6>
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-warning btn-sm fw-bold shadow-sm text-dark" onclick="event.stopPropagation(); abrirModalNovoTokenIA()">
                        <i class="fas fa-plus me-1"></i> Gerar Novo Token
                    </button>
                    <i class="fas fa-chevron-down text-white" id="icon_tokens_ia"></i>
                </div>
            </div>

            <div id="painel_tokens_ia" style="display:none;">
                <div class="table-responsive border border-dark rounded shadow-sm bg-white" style="min-height: 80px; padding-bottom: 20px;">
                    <table class="table table-hover align-middle mb-0 text-center text-nowrap" style="font-size: 13px;">
                        <thead class="table-dark">
                            <tr>
                                <th>Nome do Robô / Automação</th>
                                <th class="text-danger">PARÂMETROS</th>
                                <th>Chave V8 Associada</th>
                                <th>Dono do Saldo (CPF)</th>
                                <th>Token de Autenticação (Bearer)</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody id="tbody_tokens_ia">
                            <tr><td colspan="7" class="py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando tokens...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- PAINEL DE NOTIFICAÇÕES -->
        <div class="col-md-12 mb-4" id="painel_notificacoes_ia" style="display:none;">
            <div class="card border-warning shadow-sm">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center fw-bold py-2">
                    <span><i class="fas fa-bell me-2"></i> Notificações da IA <span class="badge bg-dark ms-1" id="badge_notif_ia">0</span></span>
                    <button class="btn btn-sm btn-dark fw-bold" onclick="marcarTodasLidasIA()"><i class="fas fa-check-double me-1"></i> Marcar todas como lidas</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 350px; overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0 text-center" style="font-size:12px;">
                            <thead class="table-secondary sticky-top">
                                <tr>
                                    <th>Data/Hora</th>
                                    <th>Robô</th>
                                    <th>Tipo</th>
                                    <th>Nome Cliente</th>
                                    <th>CPF</th>
                                    <th>Valor</th>
                                    <th>Detalhes</th>
                                </tr>
                            </thead>
                            <tbody id="tbody_notificacoes_ia">
                                <tr><td colspan="7" class="text-muted py-3">Sem notificações.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- RESULTADOS DE SOLICITAÇÕES IA — com permissão SUBMENU_OP_INTEGRACAO_V8_IA_JSOM -->
        <?php
        $acessoMonitorIA = verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA_JSOM', 'FUNCAO');
        if ($acessoMonitorIA):
        ?>
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="text-dark fw-bold mb-0"><i class="fas fa-clipboard-list text-primary me-2"></i> Resultados de Solicitações IA</h6>
                <button class="btn btn-outline-dark bg-white btn-sm fw-bold shadow-sm" onclick="carregarSessoesIA()">
                    <i class="fas fa-sync-alt me-1"></i> Atualizar Painel
                </button>
            </div>

            <div class="table-responsive border border-dark rounded shadow-sm bg-white" style="max-height: 520px; overflow-y: auto; padding-bottom: 20px;">
                <table class="table table-hover align-middle mb-0 text-center text-nowrap table-sm" style="font-size: 12px;">
                    <thead class="table-dark border-dark sticky-top">
                        <tr>
                            <th>Data / Status</th>
                            <th class="text-start">Robô</th>
                            <th class="text-start">Cliente / CPF / Tel</th>
                            <th>Resultado</th>
                            <th>JSON</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_sessoes_ia">
                        <tr><td colspan="5" class="py-4 text-muted">Aguardando resultados...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
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

        function toggleTokensIA() {
            const painel = document.getElementById('painel_tokens_ia');
            const icon   = document.getElementById('icon_tokens_ia');
            if (!painel) return;
            const aberto = painel.style.display !== 'none';
            painel.style.display = aberto ? 'none' : '';
            icon.className = aberto ? 'fas fa-chevron-down text-white' : 'fas fa-chevron-up text-white';
            if (!aberto) carregarTokensIA(); // carrega ao abrir
        }

        document.addEventListener("DOMContentLoaded", () => {
            if(document.getElementById('modalTokenIA')) modalTokenIAObj = new bootstrap.Modal(document.getElementById('modalTokenIA'));
            if(document.getElementById('modalEditarTokenIA')) modalEditarTokenIAObj = new bootstrap.Modal(document.getElementById('modalEditarTokenIA'));

            const tabIA = document.querySelector('[data-bs-target="#tab-atendimento-ia"]');
            if(tabIA) {
                tabIA.addEventListener('shown.bs.tab', function (e) {
                    // Tokens recolhidos — carrega só o monitor
                    carregarSessoesIA();
                    carregarNotificacoesIA();
                    if(!intervalMonitorIA) intervalMonitorIA = setInterval(() => { carregarSessoesIA(); carregarNotificacoesIA(); }, 15000);
                });
                tabIA.addEventListener('hidden.bs.tab', function (e) {
                    if(intervalMonitorIA) { clearInterval(intervalMonitorIA); intervalMonitorIA = null; }
                });
            } else {
                carregarSessoesIA();
                carregarNotificacoesIA();
                setInterval(() => { carregarSessoesIA(); carregarNotificacoesIA(); }, 15000);
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
                if (res.data.length === 0) { tb.innerHTML = '<tr><td colspan="7" class="py-4 text-muted fw-bold">Nenhum token gerado ainda.</td></tr>'; return; }
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

                    // Toggles de notificação inline
                    const chkSim = parseInt(t.NOTIF_SIMULACAO) === 1;
                    const chkPro = parseInt(t.NOTIF_PROPOSTA) === 1;
                    let colParametros = `
                        <div class="d-flex flex-column gap-1 align-items-start" style="min-width:155px;">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="ns_${t.ID}" ${chkSim ? 'checked' : ''}
                                    onchange="salvarNotificacaoIA(${t.ID})"
                                    title="Notificar quando IA tiver simulação pronta">
                                <label class="form-check-label small fw-bold text-primary" for="ns_${t.ID}">
                                    <i class="fas fa-chart-bar me-1"></i>Aviso Simulação
                                </label>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="np_${t.ID}" ${chkPro ? 'checked' : ''}
                                    onchange="salvarNotificacaoIA(${t.ID})"
                                    title="Notificar quando IA enviar proposta">
                                <label class="form-check-label small fw-bold text-success" for="np_${t.ID}">
                                    <i class="fas fa-file-contract me-1"></i>Aviso Proposta
                                </label>
                            </div>
                        </div>`;

                    tb.innerHTML += `
                        <tr class="border-bottom border-dark">
                            <td class="fw-bold text-dark">${t.NOME_ROBO}</td>
                            <td class="text-start px-2">${colParametros}</td>
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

        async function salvarNotificacaoIA(id) {
            const notifSim = document.getElementById('ns_' + id)?.checked ? 1 : 0;
            const notifPro = document.getElementById('np_' + id)?.checked ? 1 : 0;
            await v8Req(ARQUIVO_AJAX_IA, 'salvar_notificacoes', { id, notif_simulacao: notifSim, notif_proposta: notifPro }, false);
        }

        async function carregarNotificacoesIA() {
            const res = await v8Req(ARQUIVO_AJAX_IA, 'listar_notificacoes', {}, false);
            if (!res.success) return;
            const painel = document.getElementById('painel_notificacoes_ia');
            const badge = document.getElementById('badge_notif_ia');
            const tb = document.getElementById('tbody_notificacoes_ia');
            if (!painel || !badge || !tb) return;

            badge.textContent = res.total;
            painel.style.display = res.total > 0 ? '' : 'none';
            if (res.total === 0) { tb.innerHTML = '<tr><td colspan="7" class="text-muted py-3">Sem notificações.</td></tr>'; return; }

            tb.innerHTML = '';
            res.data.forEach(n => {
                const isSim = n.TIPO === 'SIMULACAO';
                const badge_tipo = isSim
                    ? '<span class="badge bg-primary">Simulação</span>'
                    : '<span class="badge bg-success">Proposta</span>';
                const detalhe = isSim
                    ? `Vlr: <b class="text-success">R$ ${parseFloat(n.VALOR||0).toFixed(2).replace('.',',')}</b><br><small>${n.PRAZO||''}x de R$ ${parseFloat(n.PARCELA||0).toFixed(2).replace('.',',')}</small>`
                    : `Vlr: <b class="text-success">R$ ${parseFloat(n.VALOR||0).toFixed(2).replace('.',',')}</b><br><small class="text-muted">#${n.NUMERO_PROPOSTA||''}</small>`;
                tb.innerHTML += `<tr>
                    <td class="small text-muted">${n.DATA_BR}</td>
                    <td class="fw-bold">${n.NOME_ROBO||'—'}</td>
                    <td>${badge_tipo}</td>
                    <td class="text-start">${n.NOME_CLIENTE||'—'}</td>
                    <td class="text-danger fw-bold">${n.CPF_CLIENTE||''}</td>
                    <td>${detalhe}</td>
                    <td></td>
                </tr>`;
            });
        }

        async function marcarTodasLidasIA() {
            await v8Req(ARQUIVO_AJAX_IA, 'marcar_lidas', {}, false);
            document.getElementById('painel_notificacoes_ia').style.display = 'none';
            document.getElementById('badge_notif_ia').textContent = '0';
            document.getElementById('tbody_notificacoes_ia').innerHTML = '<tr><td colspan="7" class="text-muted py-3">Sem notificações.</td></tr>';
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
        // RESULTADOS DE SOLICITAÇÕES IA
        // ==========================================
        async function carregarSessoesIA() {
            const tb = document.getElementById('tbody_sessoes_ia');
            if (!tb) return;

            tb.innerHTML = '<tr><td colspan="5" class="py-3 text-muted text-center"><i class="fas fa-spinner fa-spin me-1"></i> Carregando...</td></tr>';

            const res = await v8Req(ARQUIVO_AJAX_IA, 'listar_sessoes', {}, false);
            if (!res.success) return;

            if (res.data.length === 0) {
                tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold text-center">Nenhum resultado registrado.</td></tr>';
                return;
            }

            tb.innerHTML = '';
            res.data.forEach(x => {
                // STATUS badge
                const st = (x.STATUS_SESSAO || '').toUpperCase();
                let corSt = 'secondary';
                if (st === 'SIMULACAO_PRONTA' || st === 'CONCLUIDO') corSt = 'success';
                else if (st.includes('ERRO') || st.includes('TIMEOUT')) corSt = 'danger';
                else if (st.includes('AGUARDANDO') || st.includes('BUSCANDO')) corSt = 'warning text-dark';

                const colData = `<span class="small fw-bold text-dark">${x.DATA_INICIO_BR}</span><br>
                    <span class="badge bg-${corSt} shadow-sm" style="font-size:9px;">${x.STATUS_SESSAO}</span><br>
                    <small class="text-muted" style="font-size:9px;">Atualizado: ${x.ULTIMA_ACAO_BR}</small>`;

                const colRobo = `<span class="fw-bold text-dark"><i class="fas fa-robot text-warning me-1"></i>${x.NOME_ROBO || 'IA Base'}</span><br>
                    <small class="text-muted" style="font-size:10px;">Dono: ${x.CPF_DONO || '--'}</small>`;

                const cpf      = x.CPF_CONSULTADO || x.CPF_SESSAO || '--';
                const nome     = x.NOME_COMPLETO || '—';
                const tel      = x.TELEFONE_CLIENTE || '--';
                const colCli   = `<span class="fw-bold text-dark">${cpf}</span><br>
                    <small class="text-muted" style="font-size:10px;">${nome}</small><br>
                    <small class="text-primary fw-bold" style="font-size:10px;"><i class="fas fa-phone-alt"></i> ${tel}</small>`;

                // Resultado resumido
                let resultado = '<span class="badge bg-light text-muted border">Sem resultado</span>';
                if (x.SIMULATION_ID && parseFloat(x.VALOR_LIBERADO) > 0) {
                    resultado = `<span class="badge bg-success mb-1">SIMULADO</span><br>
                        <b class="text-success">R$ ${parseFloat(x.VALOR_LIBERADO).toFixed(2).replace('.',',')}</b><br>
                        <small class="text-dark" style="font-size:10px;">${x.PRAZO_SIMULACAO || 24}x de R$ ${parseFloat(x.VALOR_PARCELA||0).toFixed(2).replace('.',',')}</small>`;
                } else if (x.NUMERO_PROPOSTA) {
                    const pSt = (x.STATUS_PROPOSTA_REAL_TIME || 'PROPOSTA').toUpperCase();
                    resultado = `<span class="badge bg-primary mb-1">PROPOSTA</span><br>
                        <small class="fw-bold text-primary" style="font-size:10px;">${x.NUMERO_PROPOSTA}</small><br>
                        <small class="text-muted" style="font-size:9px;">${pSt}</small>`;
                } else if (x.STATUS_V8 && x.STATUS_V8.includes('AGUARDANDO')) {
                    resultado = '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Aguardando V8</span>';
                } else if (st.includes('ERRO') || (x.STATUS_V8 && x.STATUS_V8.includes('ERRO'))) {
                    let erroTxt = x.MENSAGEM_ERRO || x.STATUS_V8 || 'Erro API';
                    try { const j = JSON.parse(erroTxt); erroTxt = j.detail || j.message || erroTxt; } catch(e){}
                    resultado = `<span class="badge bg-danger mb-1">ERRO</span><br><small class="text-danger" style="font-size:9px;">${String(erroTxt).substring(0,60)}</small>`;
                }

                // Link para baixar o log JSON
                const cpfLimpo = cpf.replace(/\D/g, '');
                const dataLog  = (x.DATA_INICIO_BR || '').replace(/(\d{2})\/(\d{2})\/(\d{4}) .*/, '$1-$2-$3');
                const linkJson = `ajax_api_ia_v8.php?acao=download_log_json&cpf=${cpfLimpo}&data=${dataLog}&sessao_id=${x.SESSAO_ID}`;
                const colJson  = `<a href="${linkJson}" target="_blank" class="btn btn-sm btn-outline-dark fw-bold shadow-sm" title="Baixar log JSON desta sessão" style="font-size:11px;">
                    <i class="fas fa-download me-1"></i> JSON
                </a>`;

                tb.innerHTML += `
                    <tr class="border-bottom border-secondary bg-white">
                        <td class="align-middle text-center">${colData}</td>
                        <td class="align-middle text-start px-2">${colRobo}</td>
                        <td class="align-middle text-start px-2">${colCli}</td>
                        <td class="align-middle text-center">${resultado}</td>
                        <td class="align-middle text-center">${colJson}</td>
                    </tr>`;
            });
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