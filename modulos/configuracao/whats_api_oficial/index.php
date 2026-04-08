<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; 
$nome_logado = $_SESSION['usuario_nome'] ?? 'Usuário';
$cpf_logado = $_SESSION['usuario_cpf'] ?? '00000000000';

if (!function_exists('VerificaBloqueio')) {
    function VerificaBloqueio($chave) { return false; } // Substitua pela função real
}

// Oculta abas inteiras se o grupo do usuário estiver bloqueado para essas chaves específicas
$perm_credenciais = !VerificaBloqueio('SUBMENU_OP_WHATS_API_CREDENCIAIS');
$perm_modelos     = !VerificaBloqueio('SUBMENU_OP_WHATS_API_MODELOS');
$perm_manual      = !VerificaBloqueio('SUBMENU_OP_WHATS_API_DISPARO_MANUA'); 
$perm_historico   = !VerificaBloqueio('SUBMENU_OP_WHATS_API_DISPARO_HITORICO_DISP'); 
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
            <li class="nav-item"><button class="nav-link active border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-config" type="button" onclick="carregarConfig()"><i class="fas fa-cogs me-1 text-secondary"></i> Credenciais da Meta</button></li>
            <?php endif; ?>
            
            <?php if($perm_modelos): ?>
            <li class="nav-item"><button class="nav-link border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-templates" type="button" onclick="carregarTemplatesMeta()"><i class="fas fa-file-code me-1 text-info"></i> Modelos</button></li>
            <?php endif; ?>
            
            <?php if($perm_manual): ?>
            <li class="nav-item"><button class="nav-link border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-teste" type="button" onclick="carregarTemplatesMeta()"><i class="fas fa-paper-plane me-1 text-primary"></i> Disparo Manual</button></li>
            <li class="nav-item"><button class="nav-link border-dark text-dark fw-bold text-success" data-bs-toggle="tab" data-bs-target="#tab-campanha" type="button" onclick="carregarTemplatesMeta(); carregarCampanhas();"><i class="fas fa-users me-1"></i> Campanha / Lote</button></li>
            <?php endif; ?>
            
            <?php if($perm_historico): ?>
            <li class="nav-item"><button class="nav-link border-dark text-dark" data-bs-toggle="tab" data-bs-target="#tab-historico" type="button" onclick="carregarHistorico()"><i class="fas fa-history me-1 text-warning"></i> Histórico</button></li>
            <?php endif; ?>
        </ul>

        <div class="tab-content">
            
            <div class="tab-pane fade <?php echo $perm_credenciais ? 'show active' : ''; ?>" id="tab-config">
                <div class="row g-4">
                    <div class="col-md-7">
                        <div class="bg-light p-4 border border-dark rounded shadow-sm mb-4">
                            <h5 class="fw-bold text-dark border-bottom border-dark pb-2 mb-3"><i class="fas fa-key text-warning me-2"></i> Chave da Conta (Meta for Developers)</h5>
                            
                            <div class="mb-3">
                                <label class="small fw-bold">Credencial Vinculada ao Usuário:</label>
                                <input type="text" class="form-control border-dark bg-secondary text-white fw-bold" readonly value="<?php echo htmlspecialchars($nome_logado . ' - CPF: ' . $cpf_logado); ?>">
                            </div>

                            <form id="formConfig">
                                <div class="mb-3">
                                    <label class="small fw-bold">Identificação da Conta do WhatsApp (WABA ID):</label>
                                    <input type="text" id="cfg_waba_id" class="form-control border-dark fw-bold text-primary" required>
                                </div>
                                <div class="mb-3">
                                    <label class="small fw-bold">Token de Acesso Permanente (Permanent Token):</label>
                                    <textarea id="cfg_token" class="form-control border-dark fw-bold text-success" rows="3" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-dark w-100 fw-bold border-dark shadow-sm"><i class="fas fa-save me-2"></i> Salvar Conta</button>
                            </form>
                        </div>

                        <div class="bg-white p-4 border border-primary rounded shadow-sm">
                            <h5 class="fw-bold text-primary border-bottom border-primary pb-2 mb-3"><i class="fas fa-phone-alt me-2"></i> Números Oficiais da Conta</h5>
                            
                            <form id="formAddNumero" class="row g-2 align-items-end mb-4">
                                <div class="col-md-5">
                                    <label class="small fw-bold">Phone Number ID (Meta):</label>
                                    <input type="text" id="add_phone_id" class="form-control border-primary" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="small fw-bold">Apelido do Número:</label>
                                    <input type="text" id="add_phone_nome" class="form-control border-primary" placeholder="Ex: Matriz, Filial..." required>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary fw-bold w-100 border-dark"><i class="fas fa-plus"></i> Adicionar</button>
                                </div>
                            </form>

                            <div class="table-responsive border border-dark rounded">
                                <table class="table table-hover table-sm text-center mb-0 align-middle">
                                    <thead class="table-dark text-white border-dark"><tr><th>Apelido</th><th>Phone ID</th><th>Ação</th></tr></thead>
                                    <tbody id="tbody-numeros"><tr><td colspan="3" class="py-3 text-muted">Carregando...</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-5">
                        <div class="bg-white p-4 border border-success rounded shadow-sm h-100">
                            <h5 class="fw-bold text-success border-bottom border-success pb-2 mb-3"><i class="fas fa-link me-2"></i> Configuração do Webhook</h5>
                            <p class="small text-muted mb-3">Copie os dados abaixo e cole no painel da Meta para que o sistema receba as respostas dos clientes e os status de leitura (Tickets azul).</p>
                            
                            <label class="small fw-bold text-dark">URL de Retorno de Chamada (Callback URL):</label>
                            <div class="input-group mb-3">
                                <input type="text" id="cfg_webhook_url" class="form-control border-dark bg-light text-primary" readonly value="https://<?php echo $_SERVER['HTTP_HOST']; ?>/modulos/configuracao/whats_api_oficial/webhook_meta.php">
                                <button class="btn btn-outline-dark fw-bold" type="button" onclick="copiarTexto('cfg_webhook_url')"><i class="fas fa-copy"></i></button>
                            </div>

                            <label class="small fw-bold text-dark">Token de Verificação Exclusivo (Verify Token):</label>
                            <div class="input-group mb-2">
                                <input type="text" id="cfg_verify_token" class="form-control border-dark fw-bold text-danger bg-light" readonly>
                                <button class="btn btn-outline-dark fw-bold" type="button" onclick="copiarTexto('cfg_verify_token')"><i class="fas fa-copy"></i></button>
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

            <div class="tab-pane fade <?php echo (!$perm_credenciais && $perm_historico) ? 'show active' : ''; ?>" id="tab-historico">
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

    async function carregarConfig() {
        const r = await waReq('carregar_config');
        const tbNum = document.getElementById('tbody-numeros');
        const selRem = document.getElementById('tst_remetente');
        
        if (r.success) {
            if (r.conta) {
                document.getElementById('cfg_waba_id').value = r.conta.WABA_ID || '';
                document.getElementById('cfg_token').value = r.conta.PERMANENT_TOKEN || '';
                document.getElementById('cfg_verify_token').value = r.conta.WEBHOOK_VERIFY_TOKEN || '';
            }
            
            tbNum.innerHTML = ''; selRem.innerHTML = '<option value="">-- Selecione o Remetente --</option>';
            if(r.numeros && r.numeros.length > 0) {
                r.numeros.forEach(n => {
                    tbNum.innerHTML += `<tr><td class="fw-bold">${n.NOME_NUMERO}</td><td><code>${n.PHONE_NUMBER_ID}</code></td><td><button class="btn btn-sm btn-danger" onclick="excluirNumero(${n.ID})"><i class="fas fa-trash"></i></button></td></tr>`;
                    selRem.innerHTML += `<option value="${n.PHONE_NUMBER_ID}">${n.NOME_NUMERO} (${n.PHONE_NUMBER_ID})</option>`;
                });
            } else {
                tbNum.innerHTML = '<tr><td colspan="3" class="py-3 text-muted">Nenhum número adicionado.</td></tr>';
            }
        }
    }

    document.getElementById('formConfig')?.addEventListener('submit', async e => {
        e.preventDefault();
        const r = await waReq('salvar_config', { waba_id: document.getElementById('cfg_waba_id').value, token: document.getElementById('cfg_token').value });
        alert(r.msg); carregarConfig();
    });

    document.getElementById('formAddNumero')?.addEventListener('submit', async e => {
        e.preventDefault();
        const r = await waReq('adicionar_numero', { phone_id: document.getElementById('add_phone_id').value, nome_numero: document.getElementById('add_phone_nome').value });
        if(!r.success) alert(r.msg); 
        document.getElementById('add_phone_id').value = ''; document.getElementById('add_phone_nome').value = '';
        carregarConfig();
    });

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

        const r = await waReq('listar_templates_meta');
        
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
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; } 
?>