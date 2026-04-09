<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$nome_logado = $_SESSION['usuario_nome'] ?? 'Usuário';
$cpf_logado = $_SESSION['usuario_cpf'] ?? '00000000000';

// Motor de permissões
$caminho_perm = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_perm)) { require_once $caminho_perm; }
if (!function_exists('VerificaBloqueio')) { function VerificaBloqueio($chave) { return false; } }

// Permissões por aba — chave SUBMENU_OP_WHATOFICIAL_*
$perm_credenciais = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_CREDENCIAIS_DA_META');
$perm_modelos     = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_MODELOS');
$perm_manual      = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_DISPARO_MANUAL');
$perm_campanha    = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_CAMPANHA_LOTE');
$perm_historico   = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_HISTORICO');
$perm_chat        = !VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_CHAT');

// Determina qual aba abre como padrão (a primeira disponível)
$tab_ativa = '';
if ($perm_credenciais)  $tab_ativa = $tab_ativa ?: 'tab-config';
if ($perm_modelos)      $tab_ativa = $tab_ativa ?: 'tab-templates';
if ($perm_manual)       $tab_ativa = $tab_ativa ?: 'tab-teste';
if ($perm_campanha)     $tab_ativa = $tab_ativa ?: 'tab-campanha';
if ($perm_historico)    $tab_ativa = $tab_ativa ?: 'tab-historico';
if ($perm_chat)         $tab_ativa = $tab_ativa ?: 'tab-chat';
?>

<style>
  :root { --wa-color: #25D366; --wa-dark: #128C7E; }
  .card-custom { border: 1px solid #e0e0e0; border-radius: 8px; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
  .nav-tabs .nav-link { color: #495057; font-weight: bold; }
  .nav-tabs .nav-link.active { color: var(--wa-dark); border-bottom: 3px solid var(--wa-color); }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
       <h3 class="text-dark fw-bold m-0"><i class="fab fa-whatsapp text-success me-2"></i> WhatsApp Cloud API (Oficial)</h3>
    </div>

    <div class="card card-custom p-4 border-dark shadow-sm">
        
        <ul class="nav nav-tabs mb-4 border-dark" role="tablist">
            <?php if($perm_credenciais): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-config' ? 'active' : '' ?> border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-config" type="button" onclick="carregarConfig()"><i class="fas fa-cogs me-1 text-secondary"></i> Credenciais da Meta</button></li>
            <?php endif; ?>

            <?php if($perm_modelos): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-templates' ? 'active' : '' ?> border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-templates" type="button" onclick="carregarTemplatesMeta()"><i class="fas fa-file-code me-1 text-info"></i> Modelos</button></li>
            <?php endif; ?>

            <?php if($perm_manual): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-teste' ? 'active' : '' ?> border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-teste" type="button" onclick="carregarTemplatesMeta()"><i class="fas fa-paper-plane me-1 text-primary"></i> Disparo Manual</button></li>
            <?php endif; ?>

            <?php if($perm_campanha): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-campanha' ? 'active' : '' ?> border-dark text-dark fw-bold text-success" data-bs-toggle="tab" data-bs-target="#tab-campanha" type="button" onclick="carregarTemplatesMeta(); carregarCampanhas();"><i class="fas fa-users me-1"></i> Campanha / Lote</button></li>
            <?php endif; ?>

            <?php if($perm_historico): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-historico' ? 'active' : '' ?> border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-historico" type="button" onclick="carregarHistorico()"><i class="fas fa-history me-1 text-warning"></i> Histórico</button></li>
            <?php endif; ?>

            <?php if($perm_chat): ?>
            <li class="nav-item"><button class="nav-link <?= $tab_ativa==='tab-chat' ? 'active' : '' ?> border-dark text-dark fw-bold" data-bs-toggle="tab" data-bs-target="#tab-chat" type="button" onclick="iniciarChat()"><i class="fab fa-whatsapp text-success me-1"></i> Chat</button></li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            
            <div class="tab-pane fade <?= $tab_ativa==='tab-config' ? 'show active' : '' ?>" id="tab-config">

                <!-- HEADER: botão novo BM + webhook global -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark m-0"><i class="fas fa-sitemap text-warning me-2"></i> Gerenciadores de Negócios (BM) da Empresa</h5>
                    <button class="btn btn-dark fw-bold border-dark shadow-sm" onclick="abrirModalBM()"><i class="fas fa-plus me-1"></i> Novo BM</button>
                </div>

                <!-- ALERTA WEBHOOK GLOBAL -->
                <div class="alert alert-success border-dark shadow-sm small mb-3 py-2">
                    <i class="fas fa-link me-1"></i> <strong>Webhook URL:</strong>
                    <code id="cfg_webhook_url_global"><?= 'https://' . $_SERVER['HTTP_HOST'] . '/modulos/configuracao/whats_api_oficial/webhook_meta.php' ?></code>
                    <button class="btn btn-sm btn-outline-dark ms-2 py-0" onclick="copiarTexto('cfg_webhook_url_global')"><i class="fas fa-copy"></i></button>
                    <span class="text-muted ms-2">— Cole este URL em todos os seus Apps/BMs na Meta.</span>
                </div>

                <!-- LISTA DE BMs (accordion) -->
                <div id="lista-bms">
                    <div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando...</div>
                </div>

                <!-- MODAL NOVO/EDITAR BM -->
                <div class="modal fade" id="modalBM" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content border-dark">
                            <div class="modal-header bg-dark text-white border-dark">
                                <h5 class="modal-title fw-bold"><i class="fas fa-building me-2"></i> <span id="modal-bm-titulo">Novo Gerenciador de Negócios</span></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="bm_db_id" value="0">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small fw-bold">Apelido (Nome amigável):</label>
                                        <input type="text" id="bm_nome" class="form-control border-dark" placeholder="Ex: BM Principal, BM Filial 1...">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold">Business Manager ID (Meta):</label>
                                        <input type="text" id="bm_id_meta" class="form-control border-dark" placeholder="123456789012345">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold">App ID (Meta for Developers):</label>
                                        <input type="text" id="bm_app_id" class="form-control border-dark" placeholder="App ID do seu aplicativo">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold">App Secret:</label>
                                        <input type="password" id="bm_app_secret" class="form-control border-dark" placeholder="Deixe em branco para não alterar">
                                    </div>
                                    <div class="col-12">
                                        <label class="small fw-bold text-success">Token de Acesso Permanente:</label>
                                        <textarea id="bm_token" class="form-control border-success fw-bold" rows="3" placeholder="EAAxxxxxxxx..."></textarea>
                                        <small class="text-muted">Gerado no Meta for Developers → Seu App → Ferramentas → Token de Acesso do Sistema.</small>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer border-dark">
                                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button class="btn btn-dark fw-bold" onclick="salvarBM()"><i class="fas fa-save me-1"></i> Salvar BM</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODAL NOVO/EDITAR WABA -->
                <div class="modal fade" id="modalWABA" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content border-primary">
                            <div class="modal-header bg-primary text-white border-primary">
                                <h5 class="modal-title fw-bold"><i class="fab fa-whatsapp me-2"></i> Conta WhatsApp (WABA)</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="waba_bm_db_id" value="0">
                                <input type="hidden" id="waba_conta_id" value="0">
                                <div class="mb-3">
                                    <label class="small fw-bold text-primary">WhatsApp Business Account ID (WABA ID):</label>
                                    <input type="text" id="waba_id_input" class="form-control border-primary fw-bold" placeholder="Ex: 102345678901234">
                                    <small class="text-muted">Encontre em: Meta Business Suite → Configurações → Contas do WhatsApp.</small>
                                </div>
                            </div>
                            <div class="modal-footer border-primary">
                                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button class="btn btn-primary fw-bold" onclick="salvarWABA()"><i class="fas fa-save me-1"></i> Salvar WABA</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODAL NOVO PHONE ID -->
                <div class="modal fade" id="modalPhone" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content border-success">
                            <div class="modal-header bg-success text-white border-success">
                                <h5 class="modal-title fw-bold"><i class="fas fa-phone me-2"></i> Adicionar Número (Phone ID)</h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" id="phone_conta_id" value="0">
                                <div class="mb-3">
                                    <label class="small fw-bold">Phone Number ID (Meta):</label>
                                    <input type="text" id="phone_id_input" class="form-control border-dark" placeholder="Ex: 102345678901234">
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold">Apelido do Número:</label>
                                    <input type="text" id="phone_nome_input" class="form-control border-dark" placeholder="Ex: Atendimento, Vendas, Filial SP...">
                                </div>
                            </div>
                            <div class="modal-footer border-success">
                                <button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button class="btn btn-success fw-bold" onclick="salvarPhone()"><i class="fas fa-plus me-1"></i> Adicionar</button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="tab-pane fade" id="tab-templates">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="alert alert-info border-dark shadow-sm fw-bold mb-0 w-100 me-3">
                        <i class="fas fa-info-circle me-2"></i> Visualização em tempo real conectada à Meta. O CRM não cria templates, faça isso no seu Gerenciador de Negócios.
                    </div>
                    <button class="btn btn-dark fw-bold border-dark shadow-sm text-nowrap" onclick="carregarTemplatesMeta(true)">
                        <i class="fas fa-sync-alt me-2"></i> Atualizar Lista
                    </button>
                </div>
                <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                        <thead class="table-dark text-white border-dark sticky-top">
                            <tr>
                                <th>Nome do Template</th>
                                <th>Categoria</th>
                                <th>Idioma</th>
                                <th>Status na Meta</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-templates">
                            <tr><td colspan="4" class="py-4 text-muted fw-bold">Clique para carregar...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-teste">
                <div class="row justify-content-center">
                    <div class="col-md-8 bg-light p-4 border border-dark rounded shadow-sm">
                        <h5 class="fw-bold text-dark border-bottom border-dark pb-2 mb-4"><i class="fas fa-paper-plane text-primary me-2"></i> Disparar Mensagem Avulsa</h5>
                        <form id="formTeste">
                            <div class="mb-3">
                                <label class="small fw-bold text-primary">Número Oficial (Remetente):</label>
                                <select id="tst_remetente" class="form-select border-primary fw-bold text-dark" required>
                                    <option value="">Carregando números...</option>
                                </select>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold">Destino (Com DDI e DDD):</label>
                                    <input type="text" id="tst_telefone" class="form-control border-dark" placeholder="Ex: 5562999999999" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Tipo de Mensagem:</label>
                                    <select id="tst_tipo" class="form-select border-dark fw-bold" onchange="mudarTipoTeste()">
                                        <option value="text">Texto Livre (Janela 24h)</option>
                                        <option value="template">Template Aprovado</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-4" id="box_texto_livre">
                                <label class="small fw-bold">Conteúdo:</label>
                                <textarea id="tst_conteudo" class="form-control border-dark" rows="3"></textarea>
                            </div>

                            <div class="mb-3 d-none" id="box_template">
                                <label class="small fw-bold text-success">Template:</label>
                                <select id="tst_template_name" class="form-select border-dark fw-bold">
                                    <option value="">Carregando da Meta...</option>
                                </select>
                            </div>

                            <div class="mb-3 d-none bg-white p-3 border border-info rounded" id="box_imagem">
                                <label class="small fw-bold text-info"><i class="fas fa-image"></i> URL Imagem (Opcional):</label>
                                <input type="url" id="tst_url_imagem" class="form-control border-info">
                            </div>

                            <div class="mb-4 d-none bg-white p-3 border border-warning rounded" id="box_variaveis">
                                <label class="small fw-bold text-warning"><i class="fas fa-edit"></i> Variáveis (Opcional):</label>
                                <input type="text" id="tst_variaveis" class="form-control border-warning" placeholder="Ex: João, 1500.00">
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold border-dark shadow-sm" id="btnTestar"><i class="fas fa-paper-plane me-2"></i> Enviar</button>
                            <div id="tst_resultado" class="mt-3 text-center fw-bold"></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-campanha">
                <div class="row">
                    <div class="col-md-4">
                        <div class="bg-light p-3 border border-dark rounded shadow-sm">
                            <h5 class="fw-bold text-success border-bottom border-success pb-2"><i class="fas fa-upload"></i> Importar Lista</h5>
                            
                            <form id="formCampanha" enctype="multipart/form-data">
                                <div class="mb-2">
                                    <label class="small fw-bold">Nome da Campanha:</label>
                                    <input type="text" id="camp_nome" name="nome_campanha" class="form-control form-control-sm border-dark" required>
                                </div>
                                <div class="mb-2">
                                    <label class="small fw-bold">Remetente:</label>
                                    <select id="camp_remetente" name="phone_id_remetente" class="form-select form-control-sm border-dark" required></select>
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold">Template Aprovado:</label>
                                    <select id="camp_template" name="template_name" class="form-select form-control-sm border-dark" required></select>
                                </div>

                                <div class="nav nav-pills mb-2 justify-content-center" role="tablist">
                                    <button class="nav-link active btn-sm border py-1 px-3 me-2" data-bs-toggle="pill" data-bs-target="#tipo_tela" type="button" onclick="document.getElementById('tipo_imp').value='tela'">Colar na Tela</button>
                                    <button class="nav-link btn-sm border py-1 px-3" data-bs-toggle="pill" data-bs-target="#tipo_csv" type="button" onclick="document.getElementById('tipo_imp').value='csv'">Enviar CSV</button>
                                </div>
                                <input type="hidden" id="tipo_imp" name="tipo_importacao" value="tela">

                                <div class="tab-content">
                                    <div class="tab-pane fade show active" id="tipo_tela">
                                        <textarea id="camp_numeros_tela" name="numeros_tela" class="form-control border-dark" rows="5" placeholder="551199999999"></textarea>
                                    </div>
                                    <div class="tab-pane fade" id="tipo_csv">
                                        <input type="file" id="camp_csv" name="arquivo_csv" class="form-control border-dark" accept=".csv">
                                        <small class="text-muted d-block mt-1" style="font-size:11px;">NUMERO; NOME; CPF; MARGEM; PARCELA; PRAZO; VALOR</small>
                                    </div>
                                </div>

                                <button type="submit" id="btnSalvarCampanha" class="btn btn-success w-100 fw-bold mt-3 shadow-sm"><i class="fas fa-save"></i> Criar Campanha</button>
                            </form>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="bg-white p-3 border border-dark rounded shadow-sm h-100">
                            <h5 class="fw-bold text-primary border-bottom border-primary pb-2"><i class="fas fa-tasks"></i> Acompanhamento de Disparos</h5>
                            <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                                <table class="table table-hover align-middle text-center" style="font-size: 0.85rem;">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Campanha</th>
                                            <th>Usuário</th>
                                            <th>Status (Env/Falha/Total)</th>
                                            <th>Progresso</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbody-campanhas">
                                        <tr><td colspan="5" class="py-3 text-muted">Carregando...</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade <?= $tab_ativa==='tab-historico' ? 'show active' : '' ?>" id="tab-historico">
                <div class="table-responsive border border-dark rounded shadow-sm" style="max-height: 500px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                        <thead class="table-dark text-white border-dark sticky-top">
                            <tr>
                                <th>Data/Hora</th>
                                <th>Direção</th>
                                <th>Remetente</th>
                                <th>Destinatário</th>
                                <th>Tipo</th>
                                <th class="text-start">Conteúdo</th>
                                <th>Status/Retorno</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-historico">
                            <tr><td colspan="7" class="py-4 text-muted fw-bold">Aguardando...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================================================ -->
            <!-- ABA CHAT                                                      -->
            <!-- ============================================================ -->
            <div class="tab-pane fade <?= $tab_ativa==='tab-chat' ? 'show active' : '' ?>" id="tab-chat">
                <style>
                    #chat-lista-conversas { height: 560px; overflow-y: auto; border-right: 2px solid #dee2e6; }
                    #chat-lista-conversas .conv-item { cursor: pointer; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; transition: background 0.15s; }
                    #chat-lista-conversas .conv-item:hover, #chat-lista-conversas .conv-item.ativo { background: #e8f5e9; }
                    #chat-mensagens-box { height: 420px; overflow-y: auto; background: #f7f7f7; padding: 12px; border-radius: 8px; }
                    .msg-enviada { text-align: right; }
                    .msg-enviada .balao { background: #dcf8c6; border-radius: 12px 0 12px 12px; display: inline-block; padding: 8px 12px; max-width: 75%; text-align: left; word-break: break-word; }
                    .msg-recebida .balao { background: #fff; border: 1px solid #e0e0e0; border-radius: 0 12px 12px 12px; display: inline-block; padding: 8px 12px; max-width: 75%; word-break: break-word; }
                    .msg-hora { font-size: 0.7rem; color: #999; margin-top: 2px; }
                    .janela-badge-aberta { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
                    .janela-badge-fechada { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
                </style>

                <div class="row g-0">
                    <!-- PAINEL ESQUERDO: lista de conversas -->
                    <div class="col-md-4 col-lg-3" id="chat-lista-conversas">
                        <div class="p-2 bg-dark text-white d-flex gap-2 align-items-center">
                            <select id="chat-filtro-numero" class="form-select form-select-sm border-dark text-dark" style="max-width:140px" onchange="carregarConversas()">
                                <option value="">Todos os números</option>
                            </select>
                            <select id="chat-filtro-status" class="form-select form-select-sm border-dark text-dark" onchange="carregarConversas()">
                                <option value="TODOS">Todos</option>
                                <option value="AGUARDANDO">Aguardando</option>
                                <option value="EM_ATENDIMENTO">Em Atendimento</option>
                                <option value="FINALIZADO">Finalizado</option>
                            </select>
                            <button class="btn btn-sm btn-success text-nowrap fw-bold" onclick="abrirModalNovaConversa()" title="Nova conversa"><i class="fas fa-plus"></i></button>
                        </div>
                        <div id="chat-lista-itens">
                            <div class="text-center py-4 text-muted small">Carregando...</div>
                        </div>
                    </div>

                    <!-- PAINEL DIREITO: conversa aberta -->
                    <div class="col-md-8 col-lg-9 d-flex flex-column" style="height:560px;">
                        <!-- Header da conversa -->
                        <div id="chat-header" class="p-2 bg-light border-bottom d-flex justify-content-between align-items-center" style="min-height:52px;">
                            <span class="text-muted fst-italic small">Selecione uma conversa</span>
                        </div>

                        <!-- Mensagens -->
                        <div id="chat-mensagens-box" class="flex-grow-1">
                            <div class="text-center text-muted small fst-italic py-4">Nenhuma conversa selecionada.</div>
                        </div>

                        <!-- Barra de input -->
                        <div id="chat-input-area" class="p-2 border-top bg-white" style="display:none;">
                            <!-- Banner janela -->
                            <div id="chat-banner-janela" class="mb-2 px-3 py-1 rounded small text-center fw-bold" style="display:none;"></div>

                            <!-- Assinatura + Modelos CRM -->
                            <div class="d-flex gap-2 mb-2 align-items-center">
                                <div class="input-group input-group-sm" style="max-width:220px">
                                    <span class="input-group-text bg-secondary text-white border-dark"><i class="fas fa-signature"></i></span>
                                    <input type="text" id="chat-assinatura" class="form-control border-dark" placeholder="Assinatura (opcional)" onchange="salvarAssinatura()">
                                </div>
                                <select id="chat-sel-modelo" class="form-select form-select-sm border-dark" onchange="aplicarModeloCRM()" style="max-width:180px">
                                    <option value="">💬 Modelos CRM</option>
                                </select>
                                <button class="btn btn-sm btn-outline-secondary" onclick="abrirModalModelos()" title="Gerenciar modelos"><i class="fas fa-cog"></i></button>
                            </div>

                            <!-- Tipo de mensagem + Input -->
                            <div id="chat-area-texto">
                                <div class="input-group">
                                    <select id="chat-tipo-msg" class="form-select form-select-sm border-dark fw-bold" style="max-width:160px" onchange="mudarTipoChat()">
                                        <option value="text">✍️ Texto Livre</option>
                                        <option value="template">📋 Template Meta</option>
                                        <option value="image">🖼️ Imagem</option>
                                        <option value="document">📄 Documento</option>
                                        <option value="audio">🎵 Áudio</option>
                                    </select>
                                    <textarea id="chat-texto" class="form-control border-dark" rows="2" placeholder="Digite sua mensagem..." style="resize:none;"></textarea>
                                    <button class="btn btn-success fw-bold px-3" onclick="enviarMensagemChat()" id="btn-enviar-chat"><i class="fas fa-paper-plane"></i></button>
                                </div>
                            </div>

                            <!-- Area template Meta -->
                            <div id="chat-area-template" style="display:none;">
                                <div class="d-flex gap-2">
                                    <select id="chat-tpl-nome" class="form-select form-select-sm border-success fw-bold flex-grow-1">
                                        <option value="">Selecione o Template...</option>
                                    </select>
                                    <input type="text" id="chat-tpl-vars" class="form-control form-control-sm border-dark" placeholder="Variáveis: João, 1500.00" style="max-width:220px">
                                    <button class="btn btn-success fw-bold" onclick="enviarMensagemChat()"><i class="fas fa-paper-plane"></i></button>
                                </div>
                            </div>

                            <!-- Area arquivo -->
                            <div id="chat-area-arquivo" style="display:none;">
                                <div class="d-flex gap-2 align-items-center">
                                    <input type="file" id="chat-arquivo" class="form-control form-control-sm border-dark">
                                    <input type="text" id="chat-arquivo-caption" class="form-control form-control-sm border-dark" placeholder="Legenda (opcional)">
                                    <button class="btn btn-success fw-bold text-nowrap" onclick="enviarMensagemChat()"><i class="fas fa-paper-plane me-1"></i> Enviar</button>
                                </div>
                            </div>
                        </div>

                        <!-- Finalizar conversa -->
                        <div id="chat-footer-btns" class="px-2 pb-2 bg-white" style="display:none;">
                            <button class="btn btn-sm btn-outline-danger fw-bold" onclick="finalizarConversa()"><i class="fas fa-check-circle me-1"></i> Finalizar Conversa</button>
                        </div>
                    </div>
                </div>

                <!-- MODAL NOVA CONVERSA -->
                <div class="modal fade" id="modalNovaConversa" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content border-success">
                            <div class="modal-header bg-success text-white"><h5 class="modal-title fw-bold"><i class="fab fa-whatsapp me-2"></i> Nova Conversa</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="small fw-bold">Número Remetente (nosso):</label>
                                    <select id="nc-phone-id" class="form-select border-dark"></select>
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold">WhatsApp do Cliente (com DDI+DDD):</label>
                                    <input type="text" id="nc-telefone" class="form-control border-dark" placeholder="5562999999999">
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold">Nome do Cliente (opcional):</label>
                                    <input type="text" id="nc-nome" class="form-control border-dark">
                                </div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success fw-bold" onclick="criarNovaConversa()"><i class="fas fa-plus me-1"></i> Iniciar</button></div>
                        </div>
                    </div>
                </div>

                <!-- MODAL MODELOS CRM -->
                <div class="modal fade" id="modalModelos" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content border-dark">
                            <div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold"><i class="fas fa-comment-dots me-2"></i> Modelos de Mensagem (CRM)</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                            <div class="modal-body">
                                <div class="row g-3 mb-3">
                                    <div class="col-md-4">
                                        <input type="text" id="modelo-nome" class="form-control border-dark" placeholder="Nome do modelo">
                                    </div>
                                    <div class="col-md-6">
                                        <textarea id="modelo-conteudo" class="form-control border-dark" rows="2" placeholder="Conteúdo (use {nome}, {valor} como variáveis)"></textarea>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <input type="hidden" id="modelo-id" value="0">
                                        <button class="btn btn-dark fw-bold w-100" onclick="salvarModelo()"><i class="fas fa-save"></i> Salvar</button>
                                    </div>
                                </div>
                                <div id="lista-modelos-crm">
                                    <div class="text-center text-muted py-3">Carregando...</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>

<script>
    let templatesCarregados = false;

    async function waReq(acao, dados = {}) {
        const fd = new FormData(); fd.append('acao', acao);
        for(let k in dados) fd.append(k, dados[k]);
        try {
            const res = await fetch('whats_api.ajax.php', { method: 'POST', body: fd });
            return await res.json();
        } catch(e) { return { success: false, msg: 'Erro de conexão.' }; }
    }

    async function reqCampanha(acao, formOuDados) {
        let fd;
        if (formOuDados instanceof HTMLFormElement) {
            fd = new FormData(formOuDados);
            fd.append('acao', acao);
        } else {
            fd = new FormData();
            fd.append('acao', acao);
            for(let k in formOuDados) fd.append(k, formOuDados[k]);
        }
        try {
            const res = await fetch('whats_api_campanha.ajax.php', { method: 'POST', body: fd });
            return await res.json();
        } catch(e) { return { success: false, msg: 'Erro na campanha.' }; }
    }

    function copiarTexto(id) { let c = document.getElementById(id); c.select(); document.execCommand("copy"); alert("Copiado!"); }

    function mudarTipoTeste() {
        const tipo = document.getElementById('tst_tipo').value;
        if(tipo === 'text') { 
            document.getElementById('box_texto_livre').classList.remove('d-none'); 
            document.getElementById('box_template').classList.add('d-none'); 
            document.getElementById('box_variaveis').classList.add('d-none');
            document.getElementById('box_imagem').classList.add('d-none');
        } else { 
            document.getElementById('box_texto_livre').classList.add('d-none'); 
            document.getElementById('box_template').classList.remove('d-none'); 
            document.getElementById('box_variaveis').classList.remove('d-none');
            document.getElementById('box_imagem').classList.remove('d-none');
        }
    }

    // =========================================================================
    // HIERARQUIA BM → WABA → PHONE
    // =========================================================================

    async function carregarConfig() {
        const r = await waReq('listar_bms');
        if (!r.success) return;
        renderListaBMs(r.bms);
        preencherSelectsRemetente(r.bms);
    }

    function renderListaBMs(bms) {
        const div = document.getElementById('lista-bms');
        if (!div) return;
        if (!bms || bms.length === 0) {
            div.innerHTML = '<div class="alert alert-warning border-dark"><i class="fas fa-exclamation-triangle me-2"></i> Nenhum Gerenciador de Negócios cadastrado. Clique em <strong>Novo BM</strong> para começar.</div>';
            return;
        }
        let html = '<div class="accordion border border-dark rounded shadow-sm" id="accordionBMs">';
        bms.forEach((bm, idx) => {
            html += `
            <div class="accordion-item border-dark">
                <h2 class="accordion-header">
                    <button class="accordion-button fw-bold ${idx > 0 ? 'collapsed' : ''}" type="button" data-bs-toggle="collapse" data-bs-target="#bm-${bm.ID}">
                        <i class="fas fa-building text-warning me-2"></i>
                        ${escHtml(bm.NOME_BM)}
                        <span class="badge bg-dark ms-2">${bm.QTD_WABAS} WABA(s)</span>
                        <small class="text-muted ms-3 fw-normal">BM ID: ${escHtml(bm.BM_ID)}</small>
                    </button>
                </h2>
                <div id="bm-${bm.ID}" class="accordion-collapse collapse ${idx === 0 ? 'show' : ''}">
                    <div class="accordion-body bg-light">
                        <div class="d-flex justify-content-end gap-2 mb-3">
                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="abrirModalWABA(${bm.ID})"><i class="fas fa-plus me-1"></i> Adicionar WABA</button>
                            <button class="btn btn-sm btn-outline-secondary fw-bold" onclick="editarBM(${bm.ID},'${escHtml(bm.NOME_BM)}','${escHtml(bm.BM_ID)}','${escHtml(bm.APP_ID)}')"><i class="fas fa-edit me-1"></i> Editar BM</button>
                            <button class="btn btn-sm btn-outline-danger fw-bold" onclick="excluirBM(${bm.ID}, '${escHtml(bm.NOME_BM)}')"><i class="fas fa-trash me-1"></i> Excluir BM</button>
                        </div>`;

            if (!bm.wabas || bm.wabas.length === 0) {
                html += '<div class="text-muted small fst-italic mb-2">Nenhuma conta WABA vinculada.</div>';
            } else {
                bm.wabas.forEach(waba => {
                    html += `
                    <div class="card mb-2 border-primary shadow-sm">
                        <div class="card-header bg-white border-primary d-flex justify-content-between align-items-center py-2">
                            <span class="fw-bold text-primary"><i class="fab fa-whatsapp me-1"></i> WABA: <code>${escHtml(waba.WABA_ID)}</code></span>
                            <div class="d-flex gap-1 align-items-center">
                                <span class="badge bg-secondary">${waba.QTD_PHONES} número(s)</span>
                                <button class="btn btn-sm btn-outline-success py-0 px-2" onclick="abrirModalPhone(${waba.ID})"><i class="fas fa-plus"></i> Phone ID</button>
                                <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="excluirWABA(${waba.ID})"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>`;

                    if (waba.numeros && waba.numeros.length > 0) {
                        html += '<div class="card-body p-2"><div class="table-responsive"><table class="table table-sm table-hover text-center mb-0" style="font-size:0.82rem"><thead class="table-dark"><tr><th>Apelido</th><th>Phone ID</th><th></th></tr></thead><tbody>';
                        waba.numeros.forEach(n => {
                            html += `<tr><td class="fw-bold text-start">${escHtml(n.NOME_NUMERO)}</td><td><code>${escHtml(n.PHONE_NUMBER_ID)}</code></td><td><button class="btn btn-sm btn-danger py-0 px-2" onclick="excluirNumero(${n.ID})"><i class="fas fa-trash"></i></button></td></tr>`;
                        });
                        html += '</tbody></table></div></div>';
                    }
                    html += '</div>';
                });
            }
            html += '</div></div></div>';
        });
        html += '</div>';
        div.innerHTML = html;
    }

    function preencherSelectsRemetente(bms) {
        const sels = ['tst_remetente', 'camp_remetente'].map(id => document.getElementById(id)).filter(Boolean);
        sels.forEach(sel => sel.innerHTML = '<option value="">-- Selecione o Remetente --</option>');
        if (!bms) return;
        bms.forEach(bm => {
            if (!bm.wabas) return;
            bm.wabas.forEach(waba => {
                if (!waba.numeros || waba.numeros.length === 0) return;
                const grp = document.createElement('optgroup');
                grp.label = `${bm.NOME_BM} › WABA ${waba.WABA_ID}`;
                waba.numeros.forEach(n => {
                    const opt = new Option(`${n.NOME_NUMERO} (${n.PHONE_NUMBER_ID})`, n.PHONE_NUMBER_ID);
                    grp.appendChild(opt);
                });
                sels.forEach(sel => sel.appendChild(grp.cloneNode(true)));
            });
        });
    }

    function escHtml(str) { return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // --- Modal BM ---
    function abrirModalBM() {
        document.getElementById('bm_db_id').value = '0';
        document.getElementById('modal-bm-titulo').textContent = 'Novo Gerenciador de Negócios';
        ['bm_nome','bm_id_meta','bm_app_id','bm_app_secret','bm_token'].forEach(id => { const el = document.getElementById(id); if(el) el.value=''; });
        new bootstrap.Modal(document.getElementById('modalBM')).show();
    }
    function editarBM(id, nome, bm_id_meta, app_id) {
        document.getElementById('bm_db_id').value = id;
        document.getElementById('modal-bm-titulo').textContent = 'Editar BM — ' + nome;
        document.getElementById('bm_nome').value = nome;
        document.getElementById('bm_id_meta').value = bm_id_meta;
        document.getElementById('bm_app_id').value = app_id;
        document.getElementById('bm_app_secret').value = '';
        document.getElementById('bm_token').value = '';
        new bootstrap.Modal(document.getElementById('modalBM')).show();
    }
    async function salvarBM() {
        const r = await waReq('salvar_bm', {
            bm_db_id: document.getElementById('bm_db_id').value,
            nome_bm: document.getElementById('bm_nome').value,
            bm_id_meta: document.getElementById('bm_id_meta').value,
            app_id: document.getElementById('bm_app_id').value,
            app_secret: document.getElementById('bm_app_secret').value,
            token: document.getElementById('bm_token').value
        });
        alert(r.msg);
        if (r.success) { bootstrap.Modal.getInstance(document.getElementById('modalBM'))?.hide(); carregarConfig(); }
    }
    async function excluirBM(id, nome) {
        if (!confirm(`Excluir o BM "${nome}" e TODAS as WABAs e Números vinculados?\n\nEsta ação é irreversível.`)) return;
        const r = await waReq('excluir_bm', { bm_db_id: id });
        alert(r.msg); carregarConfig();
    }

    // --- Modal WABA ---
    function abrirModalWABA(bm_id) {
        document.getElementById('waba_bm_db_id').value = bm_id;
        document.getElementById('waba_conta_id').value = '0';
        document.getElementById('waba_id_input').value = '';
        new bootstrap.Modal(document.getElementById('modalWABA')).show();
    }
    async function salvarWABA() {
        const r = await waReq('salvar_waba', {
            bm_db_id: document.getElementById('waba_bm_db_id').value,
            conta_id: document.getElementById('waba_conta_id').value,
            waba_id: document.getElementById('waba_id_input').value
        });
        alert(r.msg);
        if (r.success) { bootstrap.Modal.getInstance(document.getElementById('modalWABA'))?.hide(); carregarConfig(); }
    }
    async function excluirWABA(conta_id) {
        if (!confirm('Excluir esta conta WABA e todos os números vinculados?')) return;
        const r = await waReq('excluir_waba', { conta_id });
        alert(r.msg); carregarConfig();
    }

    // --- Modal Phone ---
    function abrirModalPhone(conta_id) {
        document.getElementById('phone_conta_id').value = conta_id;
        document.getElementById('phone_id_input').value = '';
        document.getElementById('phone_nome_input').value = '';
        new bootstrap.Modal(document.getElementById('modalPhone')).show();
    }
    async function salvarPhone() {
        const r = await waReq('adicionar_numero', {
            conta_id: document.getElementById('phone_conta_id').value,
            phone_id: document.getElementById('phone_id_input').value,
            nome_numero: document.getElementById('phone_nome_input').value
        });
        alert(r.msg);
        if (r.success) { bootstrap.Modal.getInstance(document.getElementById('modalPhone'))?.hide(); carregarConfig(); }
    }
    async function excluirNumero(id) {
        if(!confirm('Excluir este número?')) return;
        await waReq('excluir_numero', {id_numero: id}); carregarConfig();
    }

    async function carregarTemplatesMeta(forcarBusca = false) {
        if (forcarBusca) { templatesCarregados = false; }
        if (templatesCarregados) return;

        const tb = document.getElementById('tbody-templates');
        const selTpl = document.getElementById('tst_template_name');
        if(!tb || !selTpl) return;

        tb.innerHTML = '<tr><td colspan="4" class="py-4"><i class="fas fa-spinner fa-spin text-primary"></i> Buscando na Meta...</td></tr>';
        selTpl.innerHTML = '<option value="">Buscando na Meta...</option>';

        // Passa o phone selecionado para derivar BM/token correto
        const phoneId = document.getElementById('tst_remetente')?.value || '';
        const r = await waReq('listar_templates_meta', phoneId ? { phone_id: phoneId } : {});
        
        if (r.success) {
            tb.innerHTML = '';
            selTpl.innerHTML = '<option value="">-- Selecione um Template --</option>';
            
            if (r.data.length === 0) { tb.innerHTML = '<tr><td colspan="4" class="py-4">Nenhum modelo cadastrado.</td></tr>'; return; }

            r.data.forEach(t => {
                let badge = t.status === 'APPROVED' ? '<span class="badge bg-success"><i class="fas fa-check"></i> Aprovado</span>' : 
                            (t.status === 'REJECTED' ? '<span class="badge bg-danger"><i class="fas fa-times"></i> Rejeitado</span>' : `<span class="badge bg-warning text-dark">${t.status}</span>`);

                tb.innerHTML += `<tr><td class="fw-bold">${t.name}</td><td>${t.category}</td><td><span class="badge bg-secondary">${t.language}</span></td><td>${badge}</td></tr>`;
                if (t.status === 'APPROVED') { selTpl.innerHTML += `<option value="${t.name}|${t.language}">${t.name} (${t.language})</option>`; }
            });
            templatesCarregados = true;
        } else {
            tb.innerHTML = `<tr><td colspan="4" class="py-4 text-danger">${r.msg}</td></tr>`;
            selTpl.innerHTML = '<option value="">Erro ao carregar</option>';
        }
    }

    document.getElementById('formTeste')?.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('btnTestar'); const resDiv = document.getElementById('tst_resultado');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando...';
        
        const f = {
            phone_id_remetente: document.getElementById('tst_remetente').value,
            telefone: document.getElementById('tst_telefone').value,
            tipo: document.getElementById('tst_tipo').value,
            conteudo: document.getElementById('tst_conteudo').value,
            template_name: document.getElementById('tst_template_name').value,
            url_imagem: document.getElementById('tst_url_imagem').value,
            variaveis: document.getElementById('tst_variaveis').value
        };

        const r = await waReq('enviar_teste', f);
        resDiv.innerHTML = r.success ? `<span class="text-success"><i class="fas fa-check-circle"></i> ${r.msg}</span>` : `<span class="text-danger"><i class="fas fa-times-circle"></i> ${r.msg}</span>`;
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i> Enviar Mensagem';
    });

    async function carregarHistorico() {
        const tb = document.getElementById('tbody-historico');
        if(!tb) return;
        tb.innerHTML = '<tr><td colspan="7" class="py-4"><i class="fas fa-spinner fa-spin text-primary"></i> Carregando...</td></tr>';
        const r = await waReq('carregar_historico');
        if(r.success) {
            tb.innerHTML = '';
            if(r.data.length === 0) return tb.innerHTML = '<tr><td colspan="7" class="py-4 text-muted fw-bold">Nenhuma mensagem.</td></tr>';
            
            r.data.forEach(m => {
                let iconeDir = m.DIRECAO === 'ENVIADA' ? '<i class="fas fa-arrow-up text-primary"></i>' : '<i class="fas fa-arrow-down text-success"></i>';
                let st = m.STATUS_ENVIO;
                let statusBadge = (st === 'enviada' || st === 'sent') ? '<span class="badge bg-secondary">Enviado</span>' : 
                                  (st === 'delivered' ? '<span class="badge bg-dark">Entregue</span>' : 
                                  (st === 'read' ? '<span class="badge bg-info text-dark">Lido</span>' : 
                                  (st === 'failed' ? '<span class="badge bg-danger">Falha</span>' : `<span class="badge bg-success">${st}</span>`)));
                
                let remetenteLabel = m.NOME_NUMERO ? `${m.NOME_NUMERO}<br><small class="text-muted">${m.PHONE_NUMBER_ID}</small>` : m.PHONE_NUMBER_ID;

                tb.innerHTML += `<tr><td class="fw-bold">${m.DATA_BR}</td><td>${iconeDir} ${m.DIRECAO}</td><td style="font-size:0.75rem;">${remetenteLabel}</td><td class="fw-bold text-primary">${m.TELEFONE_CLIENTE}</td><td><span class="badge bg-light text-dark border">${m.TIPO_MENSAGEM}</span></td><td class="text-start">${m.CONTEUDO || m.ERRO_DETALHE || '-'}</td><td>${statusBadge}</td></tr>`;
            });
        }
    }

    // CAMPANHAS LOGIC
    function carregarDropdownsCampanha() {
        document.getElementById('camp_remetente').innerHTML = document.getElementById('tst_remetente').innerHTML;
        document.getElementById('camp_template').innerHTML = document.getElementById('tst_template_name').innerHTML;
    }

    document.getElementById('formCampanha')?.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('btnSalvarCampanha');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
        
        const r = await reqCampanha('importar_campanha', document.getElementById('formCampanha'));
        alert(r.msg);
        if (r.success) { document.getElementById('formCampanha').reset(); carregarCampanhas(); }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Criar Campanha';
    });

    async function carregarCampanhas() {
        carregarDropdownsCampanha();
        const tb = document.getElementById('tbody-campanhas');
        if(!tb) return;

        const r = await reqCampanha('listar_campanhas', {});
        
        if(r.success) {
            tb.innerHTML = '';
            if(r.data.length === 0) return tb.innerHTML = '<tr><td colspan="5" class="py-3">Nenhuma campanha registrada.</td></tr>';
            
            r.data.forEach(c => {
                let proc = parseInt(c.ENVIADOS) + parseInt(c.FALHAS);
                let percentual = Math.round((proc / c.TOTAL) * 100) || 0;
                let cor = percentual === 100 ? 'bg-success' : 'bg-primary';

                let btnAcao = '';
                if (percentual < 100) {
                    if (campanhaEmAndamento === c.NOME_CAMPANHA && !disparoPausado) {
                        btnAcao += `<button class="btn btn-sm btn-warning fw-bold text-dark me-1" onclick="pausarDisparo()"><i class="fas fa-pause"></i> Pausar</button>`;
                    } else {
                        btnAcao += `<button class="btn btn-sm btn-primary fw-bold me-1" onclick="iniciarDisparo('${c.NOME_CAMPANHA}')"><i class="fas fa-play"></i> Iniciar</button>`;
                    }
                } else {
                    btnAcao += `<button class="btn btn-sm btn-outline-success fw-bold disabled me-1"><i class="fas fa-check"></i></button>`;
                }
                
                btnAcao += `<button class="btn btn-sm btn-danger fw-bold me-1" onclick="excluirCampanha('${c.NOME_CAMPANHA}')"><i class="fas fa-trash"></i></button>`;
                btnAcao += `<a href="whats_api_campanha.ajax.php?acao=exportar_excel&nome_campanha=${c.NOME_CAMPANHA}" class="btn btn-sm btn-success fw-bold" target="_blank"><i class="fas fa-file-excel"></i></a>`;

                tb.innerHTML += `<tr><td class="fw-bold">${c.NOME_CAMPANHA}<br><small class="text-muted fw-normal">${c.DATA_IMPORTACAO}</small></td><td>${c.NOME_USUARIO}</td><td class="fw-bold"><span class="text-success">${c.ENVIADOS}</span> / <span class="text-danger">${c.FALHAS}</span> / ${c.TOTAL}</td><td><div class="progress border border-dark" style="height: 20px;"><div class="progress-bar ${cor} fw-bold" style="width: ${percentual}%;">${percentual}%</div></div></td><td><div class="d-flex justify-content-center">${btnAcao}</div></td></tr>`;
            });
        }
    }

    let disparoPausado = false; let campanhaEmAndamento = null;

    async function iniciarDisparo(nome_campanha) {
        if(!confirm(`Iniciar disparo: ${nome_campanha}?`)) return;
        disparoPausado = false; campanhaEmAndamento = nome_campanha;
        carregarCampanhas(); processarLoteMeta(nome_campanha);
    }

    function pausarDisparo() {
        disparoPausado = true; campanhaEmAndamento = null;
        alert("Pausado."); carregarCampanhas(); 
    }

    async function processarLoteMeta(nome_campanha) {
        if(disparoPausado) return;
        const r = await reqCampanha('processar_lote', { nome_campanha: nome_campanha });
        carregarCampanhas(); 
        if (r.success && r.concluido === false) {
            setTimeout(() => { processarLoteMeta(nome_campanha); }, 1000);
        } else if (r.concluido === true) {
            campanhaEmAndamento = null; alert(`Campanha finalizada!`); carregarCampanhas();
        } else {
            campanhaEmAndamento = null; alert('Erro no lote.');
        }
    }

    async function excluirCampanha(nome_campanha) {
        if(!confirm(`Excluir campanha: ${nome_campanha}?`)) return;
        const r = await reqCampanha('excluir_campanha', { nome_campanha: nome_campanha });
        alert(r.msg); if(r.success) carregarCampanhas();
    }

    window.onload = () => { if(document.getElementById('formConfig')) carregarConfig(); };

    // =========================================================================
    // CHAT
    // =========================================================================
    let chatConversaAtual = null;
    let chatUltimoMsgId   = 0;
    let chatPollingTimer  = null;
    let chatTemplatesMeta = [];

    async function chatReq(acao, dados = {}) {
        const fd = new FormData(); fd.append('acao', acao);
        for (let k in dados) fd.append(k, dados[k]);
        try {
            const res = await fetch('chat.ajax.php', { method: 'POST', body: fd });
            return await res.json();
        } catch(e) { return { success: false, msg: 'Erro de conexão.' }; }
    }

    async function iniciarChat() {
        const r = await chatReq('listar_numeros_chat');
        if (r.success) {
            const sels = ['chat-filtro-numero', 'nc-phone-id'].map(id => document.getElementById(id)).filter(Boolean);
            sels.forEach(sel => {
                const isNC = sel.id === 'nc-phone-id';
                sel.innerHTML = isNC ? '' : '<option value="">Todos os números</option>';
                r.numeros.forEach(n => {
                    const label = (n.NOME_BM ? n.NOME_BM + ' › ' : '') + n.NOME_NUMERO;
                    sel.innerHTML += `<option value="${n.PHONE_NUMBER_ID}">${label}</option>`;
                });
            });
        }
        carregarConversas();
        carregarModelosCRM();
    }

    async function carregarConversas() {
        const status  = document.getElementById('chat-filtro-status')?.value || 'TODOS';
        const phoneId = document.getElementById('chat-filtro-numero')?.value || '';
        const r = await chatReq('listar_conversas', { status, phone_number_id: phoneId });
        const div = document.getElementById('chat-lista-itens');
        if (!div) return;

        if (!r.success || !r.conversas.length) {
            div.innerHTML = '<div class="text-center text-muted small py-4">Nenhuma conversa.</div>';
            return;
        }

        div.innerHTML = r.conversas.map(cv => {
            const janelaBadge = cv.STATUS_JANELA === 'ABERTA'
                ? '<span class="badge janela-badge-aberta" style="font-size:0.65rem">●  Janela Aberta</span>'
                : '<span class="badge janela-badge-fechada" style="font-size:0.65rem">● Janela Fechada</span>';
            const statusBadge = cv.STATUS_ATENDIMENTO === 'AGUARDANDO'
                ? '<span class="badge bg-warning text-dark" style="font-size:0.65rem">Aguardando</span>'
                : (cv.STATUS_ATENDIMENTO === 'FINALIZADO' ? '<span class="badge bg-secondary" style="font-size:0.65rem">Finalizado</span>' : '');
            const ativo = chatConversaAtual?.ID == cv.ID ? 'ativo' : '';
            return `<div class="conv-item ${ativo}" onclick="selecionarConversa(${cv.ID})">
                <div class="d-flex justify-content-between align-items-start">
                    <strong class="small text-truncate" style="max-width:120px">${escHtml(cv.NOME_CLIENTE || cv.TELEFONE_CLIENTE)}</strong>
                    <span class="text-muted" style="font-size:0.7rem">${cv.ULTIMA_MSG_BR || ''}</span>
                </div>
                <div class="text-muted small text-truncate" style="font-size:0.75rem">${escHtml(cv.ULTIMO_TRECHO || '...')}</div>
                <div class="d-flex gap-1 mt-1">${janelaBadge}${statusBadge}</div>
            </div>`;
        }).join('');
    }

    async function selecionarConversa(id) {
        clearInterval(chatPollingTimer);
        const r = await chatReq('abrir_conversa', { conversa_id: id });
        if (!r.success) return alert(r.msg);

        chatConversaAtual = r.conversa;
        chatUltimoMsgId   = 0;

        // Destaca item na lista
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('ativo'));
        document.querySelectorAll('.conv-item').forEach(el => {
            if (el.onclick?.toString().includes(`(${id})`)) el.classList.add('ativo');
        });

        atualizarHeaderChat(r.conversa, r.janela_aberta);
        renderMensagens(r.mensagens);
        chatUltimoMsgId = r.mensagens.length ? Math.max(...r.mensagens.map(m => m.ID)) : 0;

        // Assinatura
        document.getElementById('chat-assinatura').value = r.conversa.ASSINATURA || '';

        // Templates Meta
        await carregarTemplatesMetaChat(r.conversa.PHONE_NUMBER_ID);

        // Exibe input area
        document.getElementById('chat-input-area').style.display = '';
        document.getElementById('chat-footer-btns').style.display = r.conversa.STATUS_ATENDIMENTO !== 'FINALIZADO' ? '' : 'none';
        atualizarBannerJanela(r.janela_aberta, r.conversa.DATA_JANELA_EXPIRA);
        mudarTipoChat();

        // Inicia polling
        chatPollingTimer = setInterval(() => pollingMensagens(), 5000);
    }

    function atualizarHeaderChat(cv, janela_aberta) {
        const header = document.getElementById('chat-header');
        const janelaBadge = janela_aberta
            ? '<span class="badge janela-badge-aberta ms-2">Janela 24h Aberta</span>'
            : '<span class="badge janela-badge-fechada ms-2">Janela 24h Fechada</span>';
        const statusMap = { AGUARDANDO: 'warning', EM_ATENDIMENTO: 'success', FINALIZADO: 'secondary' };
        const cor = statusMap[cv.STATUS_ATENDIMENTO] || 'secondary';
        header.innerHTML = `
            <div>
                <strong class="text-dark">${escHtml(cv.NOME_CLIENTE || 'Cliente')} </strong>
                <code class="small text-muted">${escHtml(cv.TELEFONE_CLIENTE)}</code>
                ${janelaBadge}
                <span class="badge bg-${cor} ms-1 small">${cv.STATUS_ATENDIMENTO}</span>
            </div>
            <div class="small text-muted">Atendente: ${escHtml(cv.NOME_ATENDENTE || '—')}</div>`;
    }

    function renderMensagens(msgs) {
        const box = document.getElementById('chat-mensagens-box');
        if (!msgs.length) { box.innerHTML = '<div class="text-center text-muted small fst-italic py-4">Nenhuma mensagem ainda.</div>'; return; }
        box.innerHTML = msgs.map(m => renderBalao(m)).join('');
        box.scrollTop = box.scrollHeight;
    }

    function renderBalao(m) {
        const cls     = m.DIRECAO === 'ENVIADA' ? 'msg-enviada' : 'msg-recebida';
        const hora    = m.DATA_HORA ? m.DATA_HORA.substr(11, 5) : '';
        const status  = m.DIRECAO === 'ENVIADA' ? renderStatusIcon(m.STATUS_ENVIO) : '';
        let conteudo  = escHtml(m.CONTEUDO || '');

        if (m.ARQUIVO_PATH) {
            const ext = m.ARQUIVO_PATH.split('.').pop().toLowerCase();
            const url = '/modulos/configuracao/whats_api_oficial/' + m.ARQUIVO_PATH;
            if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
                conteudo += `<br><img src="${url}" style="max-width:200px;border-radius:8px;margin-top:4px" onclick="window.open('${url}')">`;
            } else if (['mp3','ogg','opus'].includes(ext)) {
                conteudo += `<br><audio controls src="${url}" style="max-width:200px"></audio>`;
            } else {
                conteudo += `<br><a href="${url}" target="_blank" class="btn btn-sm btn-outline-dark mt-1"><i class="fas fa-file"></i> Abrir arquivo</a>`;
            }
        }

        return `<div class="${cls} mb-2">
            <div class="balao">${conteudo}</div>
            <div class="msg-hora">${hora} ${status}</div>
        </div>`;
    }

    function renderStatusIcon(status) {
        if (status === 'read')       return '<i class="fas fa-check-double text-primary"></i>';
        if (status === 'delivered')  return '<i class="fas fa-check-double text-muted"></i>';
        if (status === 'sent')       return '<i class="fas fa-check text-muted"></i>';
        if (status === 'failed')     return '<i class="fas fa-times text-danger"></i>';
        return '';
    }

    function atualizarBannerJanela(aberta, expira) {
        const banner = document.getElementById('chat-banner-janela');
        if (!banner) return;
        if (aberta && expira) {
            const restante = Math.max(0, Math.floor((new Date(expira) - new Date()) / 60000));
            banner.className = 'mb-2 px-3 py-1 rounded small text-center fw-bold janela-badge-aberta';
            banner.textContent = `✅ Janela aberta — ${restante} min restantes. Envio de texto livre liberado.`;
            banner.style.display = '';
        } else {
            banner.className = 'mb-2 px-3 py-1 rounded small text-center fw-bold janela-badge-fechada';
            banner.textContent = '🔒 Janela 24h fechada. Use um Template Meta aprovado para reabrir a conversa.';
            banner.style.display = '';
        }
    }

    function mudarTipoChat() {
        const tipo = document.getElementById('chat-tipo-msg')?.value || 'text';
        const janela = chatConversaAtual ? chatConversaAtual.STATUS_JANELA === 'ABERTA' : false;

        document.getElementById('chat-area-texto').style.display    = (tipo === 'text') ? '' : 'none';
        document.getElementById('chat-area-template').style.display = (tipo === 'template') ? '' : 'none';
        document.getElementById('chat-area-arquivo').style.display  = ['image','document','audio','video'].includes(tipo) ? '' : 'none';

        // Desabilita texto se janela fechada
        const txtArea = document.getElementById('chat-texto');
        if (txtArea) {
            txtArea.disabled = (tipo === 'text' && !janela);
            txtArea.placeholder = (tipo === 'text' && !janela) ? '🔒 Janela fechada. Selecione "Template Meta" para enviar.' : 'Digite sua mensagem...';
        }
    }

    async function carregarTemplatesMetaChat(phoneId) {
        if (!phoneId) return;
        const r = await waReq('listar_templates_meta', { phone_id: phoneId });
        const sel = document.getElementById('chat-tpl-nome');
        if (!sel) return;
        sel.innerHTML = '<option value="">Selecione o Template...</option>';
        chatTemplatesMeta = [];
        if (r.success) {
            r.data.filter(t => t.status === 'APPROVED').forEach(t => {
                chatTemplatesMeta.push(t);
                sel.innerHTML += `<option value="${t.name}|${t.language}">${t.name} (${t.language})</option>`;
            });
        }
    }

    async function pollingMensagens() {
        if (!chatConversaAtual) return;
        const r = await chatReq('polling_mensagens', { conversa_id: chatConversaAtual.ID, ultimo_id: chatUltimoMsgId });
        if (!r.success) return;

        if (r.mensagens.length) {
            const box = document.getElementById('chat-mensagens-box');
            r.mensagens.forEach(m => { box.innerHTML += renderBalao(m); chatUltimoMsgId = Math.max(chatUltimoMsgId, m.ID); });
            box.scrollTop = box.scrollHeight;
        }

        // Atualiza banner da janela
        chatConversaAtual.STATUS_JANELA    = r.janela_aberta ? 'ABERTA' : 'FECHADA';
        chatConversaAtual.DATA_JANELA_EXPIRA = r.data_expira;
        atualizarBannerJanela(r.janela_aberta, r.data_expira);
        mudarTipoChat();
    }

    async function enviarMensagemChat() {
        if (!chatConversaAtual) return;
        const btn  = document.getElementById('btn-enviar-chat');
        const tipo = document.getElementById('chat-tipo-msg').value;

        const fd = new FormData();
        fd.append('acao', 'enviar_mensagem');
        fd.append('conversa_id', chatConversaAtual.ID);
        fd.append('tipo', tipo);

        if (tipo === 'text') {
            const txt = document.getElementById('chat-texto').value.trim();
            if (!txt) return;
            fd.append('conteudo', txt);
        } else if (tipo === 'template') {
            const tpl = document.getElementById('chat-tpl-nome').value;
            if (!tpl) return alert('Selecione um template.');
            fd.append('template_name', tpl);
            fd.append('variaveis', document.getElementById('chat-tpl-vars').value);
        } else {
            const arquivo = document.getElementById('chat-arquivo').files[0];
            if (!arquivo) return alert('Selecione um arquivo.');
            fd.append('arquivo', arquivo);
            fd.append('caption', document.getElementById('chat-arquivo-caption').value);
        }

        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }

        try {
            const res  = await fetch('chat.ajax.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                document.getElementById('chat-texto').value = '';
                document.getElementById('chat-arquivo').value = '';
                await pollingMensagens();
                carregarConversas();
            } else {
                alert('Erro: ' + data.msg);
            }
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane"></i>'; }
        }
    }

    async function finalizarConversa() {
        if (!chatConversaAtual) return;
        if (!confirm('Finalizar esta conversa?')) return;
        const r = await chatReq('finalizar_conversa', { conversa_id: chatConversaAtual.ID });
        if (r.success) { carregarConversas(); chatConversaAtual = null; document.getElementById('chat-input-area').style.display = 'none'; document.getElementById('chat-footer-btns').style.display = 'none'; document.getElementById('chat-header').innerHTML = '<span class="text-muted fst-italic small">Selecione uma conversa</span>'; }
    }

    async function salvarAssinatura() {
        if (!chatConversaAtual) return;
        await chatReq('salvar_assinatura', { conversa_id: chatConversaAtual.ID, assinatura: document.getElementById('chat-assinatura').value });
    }

    // Modelos CRM
    async function carregarModelosCRM() {
        const r = await chatReq('listar_modelos');
        const sel = document.getElementById('chat-sel-modelo');
        if (sel) {
            sel.innerHTML = '<option value="">💬 Modelos CRM</option>';
            (r.modelos || []).forEach(m => sel.innerHTML += `<option value="${escHtml(m.CONTEUDO)}">${escHtml(m.NOME_MODELO)}</option>`);
        }
        const lista = document.getElementById('lista-modelos-crm');
        if (!lista) return;
        lista.innerHTML = !r.modelos?.length ? '<div class="text-muted text-center py-2">Nenhum modelo cadastrado.</div>'
            : '<table class="table table-sm table-hover mb-0"><thead class="table-dark"><tr><th>Nome</th><th>Conteúdo</th><th></th></tr></thead><tbody>'
                + r.modelos.map(m => `<tr><td class="fw-bold">${escHtml(m.NOME_MODELO)}</td><td class="text-truncate" style="max-width:250px">${escHtml(m.CONTEUDO)}</td><td><button class="btn btn-sm btn-outline-secondary py-0 px-1 me-1" onclick="editarModelo(${m.ID},'${escHtml(m.NOME_MODELO)}',\`${m.CONTEUDO.replace(/`/g,"'")}\`)"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-danger py-0 px-1" onclick="excluirModelo(${m.ID})"><i class="fas fa-trash"></i></button></td></tr>`).join('')
                + '</tbody></table>';
    }

    function aplicarModeloCRM() {
        const sel = document.getElementById('chat-sel-modelo');
        if (sel?.value) { document.getElementById('chat-texto').value = sel.value; sel.value = ''; }
    }

    function abrirModalModelos() { carregarModelosCRM(); new bootstrap.Modal(document.getElementById('modalModelos')).show(); }

    function editarModelo(id, nome, conteudo) {
        document.getElementById('modelo-id').value      = id;
        document.getElementById('modelo-nome').value    = nome;
        document.getElementById('modelo-conteudo').value = conteudo;
    }

    async function salvarModelo() {
        const r = await chatReq('salvar_modelo', { modelo_id: document.getElementById('modelo-id').value, nome_modelo: document.getElementById('modelo-nome').value, conteudo: document.getElementById('modelo-conteudo').value });
        if (r.success) { document.getElementById('modelo-id').value = '0'; document.getElementById('modelo-nome').value = ''; document.getElementById('modelo-conteudo').value = ''; carregarModelosCRM(); }
        else alert(r.msg);
    }

    async function excluirModelo(id) {
        if (!confirm('Excluir este modelo?')) return;
        await chatReq('excluir_modelo', { modelo_id: id }); carregarModelosCRM();
    }

    // Modal nova conversa
    function abrirModalNovaConversa() { new bootstrap.Modal(document.getElementById('modalNovaConversa')).show(); }

    async function criarNovaConversa() {
        const r = await chatReq('nova_conversa', { phone_number_id: document.getElementById('nc-phone-id').value, telefone_cliente: document.getElementById('nc-telefone').value, nome_cliente: document.getElementById('nc-nome').value });
        if (r.success) { bootstrap.Modal.getInstance(document.getElementById('modalNovaConversa'))?.hide(); carregarConversas(); selecionarConversa(r.conversa_id); }
        else alert(r.msg);
    }
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; } 
?>