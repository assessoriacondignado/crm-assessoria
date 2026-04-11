<?php require_once '../../includes/header.php'; ?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 text-dark fw-bold"><i class="fas fa-address-card text-primary me-2"></i>Cadastro Cliente Consignado</h3>
        <button class="btn btn-primary fw-bold shadow-sm" onclick="abrirModalCliente()"><i class="fas fa-plus me-1"></i> Novo Cliente</button>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark text-white">
                        <tr>
                            <th>CPF</th>
                            <th>Nome Completo</th>
                            <th>Celular</th>
                            <th>Localidade</th>
                            <th>Última Atualização</th>
                            <th class="text-center">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody_clientes">
                        <tr><td colspan="6" class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCadCliente" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-0 rounded-3">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-user-edit me-2"></i> Formulário do Cliente</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <form id="formClienteConsignado">
                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-user text-primary"></i> Dados Pessoais</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3"><label class="small fw-bold">CPF *</label><input type="text" id="cad_cpf" class="form-control border-secondary fw-bold" required maxlength="14"></div>
                        <div class="col-md-5"><label class="small fw-bold">Nome Completo *</label><input type="text" id="cad_nome" class="form-control border-secondary" required></div>
                        <div class="col-md-2"><label class="small fw-bold">Nascimento</label><input type="date" id="cad_nascimento" class="form-control border-secondary"></div>
                        <div class="col-md-2"><label class="small fw-bold">Sexo</label><select id="cad_sexo" class="form-select border-secondary"><option value="">Selecione</option><option value="MASCULINO">Masculino</option><option value="FEMININO">Feminino</option></select></div>
                        <div class="col-md-6"><label class="small fw-bold">Nome da Mãe</label><input type="text" id="cad_mae" class="form-control border-secondary"></div>
                        <div class="col-md-6"><label class="small fw-bold">Nome do Pai</label><input type="text" id="cad_pai" class="form-control border-secondary"></div>
                        <div class="col-md-4"><label class="small fw-bold">RG</label><input type="text" id="cad_rg" class="form-control border-secondary"></div>
                        <div class="col-md-3"><label class="small fw-bold">Data Exp. RG</label><input type="date" id="cad_exp_rg" class="form-control border-secondary"></div>
                    </div>

                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-phone text-success"></i> Contatos</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-3"><label class="small fw-bold">Celular</label><input type="text" id="cad_celular" class="form-control border-secondary" placeholder="(00) 00000-0000"></div>
                        <div class="col-md-3"><label class="small fw-bold">ID WhatsApp</label><input type="text" id="cad_whatsapp" class="form-control border-secondary"></div>
                        <div class="col-md-6"><label class="small fw-bold">E-mail</label><input type="email" id="cad_email" class="form-control border-secondary"></div>
                    </div>

                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-map-marker-alt text-danger"></i> Endereço</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6"><label class="small fw-bold">Rua / Logradouro</label><input type="text" id="cad_rua" class="form-control border-secondary"></div>
                        <div class="col-md-2"><label class="small fw-bold">Número</label><input type="text" id="cad_numero" class="form-control border-secondary"></div>
                        <div class="col-md-4"><label class="small fw-bold">Complemento</label><input type="text" id="cad_complemento" class="form-control border-secondary"></div>
                        <div class="col-md-5"><label class="small fw-bold">Bairro</label><input type="text" id="cad_bairro" class="form-control border-secondary"></div>
                        <div class="col-md-5"><label class="small fw-bold">Cidade</label><input type="text" id="cad_cidade" class="form-control border-secondary"></div>
                        <div class="col-md-2"><label class="small fw-bold">UF</label><input type="text" id="cad_uf" class="form-control border-secondary" maxlength="2"></div>
                    </div>

                    <h6 class="fw-bold text-secondary border-bottom pb-2 mb-3"><i class="fas fa-money-check-alt text-warning"></i> Dados de Pagamento</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4"><label class="small fw-bold">Tipo de PIX</label><select id="cad_tipo_pix" class="form-select border-secondary"><option value="">Selecione</option><option value="CPF">CPF</option><option value="CELULAR">Celular</option><option value="EMAIL">E-mail</option><option value="ALEATORIA">Chave Aleatória</option></select></div>
                        <div class="col-md-8"><label class="small fw-bold">Chave PIX</label><input type="text" id="cad_chave_pix" class="form-control border-secondary"></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary fw-bold shadow-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary fw-bold shadow-sm px-4" onclick="salvarCliente()"><i class="fas fa-save me-1"></i> Salvar Cliente</button>
            </div>
        </div>
    </div>
</div>

<script>
    let modalCadastro;

    document.addEventListener("DOMContentLoaded", () => {
        modalCadastro = new bootstrap.Modal(document.getElementById('modalCadCliente'));
        carregarTabelaClientes();
    });

    async function reqApi(acao, dados = {}) {
        const fd = new FormData(); fd.append('acao', acao);
        for(let k in dados) fd.append(k, dados[k]);
        try { const r = await fetch('ajax_cadastro_consignado.php', { method: 'POST', body: fd }); return await r.json(); } 
        catch(e) { return { success: false, msg: e }; }
    }

    async function carregarTabelaClientes() {
        const tb = document.getElementById('tbody_clientes');
        const res = await reqApi('listar_clientes');
        if(res.success) {
            tb.innerHTML = '';
            if(res.data.length === 0) return tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 fw-bold">Nenhum cliente cadastrado.</td></tr>';
            res.data.forEach(c => {
                tb.innerHTML += `
                    <tr>
                        <td class="fw-bold">${c.cpf}</td>
                        <td>${c.nome_completo}</td>
                        <td>${c.telefone_celular || '--'}</td>
                        <td>${c.cidade || '--'} / ${c.uf || '--'}</td>
                        <td class="small text-muted">${c.atualizado_br}</td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-warning shadow-sm fw-bold me-1" onclick="editarCliente('${c.cpf}')"><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-danger shadow-sm fw-bold" onclick="excluirCliente('${c.cpf}')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>`;
            });
        }
    }

    function abrirModalCliente() { document.getElementById('formClienteConsignado').reset(); document.getElementById('cad_cpf').readOnly = false; modalCadastro.show(); }

    async function editarCliente(cpf) {
        const res = await reqApi('buscar_cliente', { cpf: cpf });
        if(res.success) {
            const d = res.data;
            document.getElementById('cad_cpf').value = d.cpf;
            document.getElementById('cad_cpf').readOnly = true; // Bloqueia edição do CPF
            document.getElementById('cad_nome').value = d.nome_completo;
            document.getElementById('cad_nascimento').value = d.nascimento;
            document.getElementById('cad_sexo').value = d.sexo;
            document.getElementById('cad_mae').value = d.nome_mae;
            document.getElementById('cad_pai').value = d.nome_pai;
            document.getElementById('cad_celular').value = d.telefone_celular;
            document.getElementById('cad_whatsapp').value = d.id_whatsapp;
            document.getElementById('cad_email').value = d.email;
            document.getElementById('cad_rua').value = d.rua;
            document.getElementById('cad_numero').value = d.numero;
            document.getElementById('cad_complemento').value = d.complemento;
            document.getElementById('cad_bairro').value = d.bairro;
            document.getElementById('cad_cidade').value = d.cidade;
            document.getElementById('cad_uf').value = d.uf;
            document.getElementById('cad_rg').value = d.rg;
            document.getElementById('cad_exp_rg').value = d.data_exp_rg;
            document.getElementById('cad_tipo_pix').value = d.tipo_pix;
            document.getElementById('cad_chave_pix').value = d.chave_pix;
            modalCadastro.show();
        } else { crmToast(res.msg, res.success === false ? "error" : "info"); }
    }

    async function salvarCliente() {
        const payload = {
            cpf: document.getElementById('cad_cpf').value, nome_completo: document.getElementById('cad_nome').value,
            nascimento: document.getElementById('cad_nascimento').value, sexo: document.getElementById('cad_sexo').value,
            nome_mae: document.getElementById('cad_mae').value, nome_pai: document.getElementById('cad_pai').value,
            telefone_celular: document.getElementById('cad_celular').value, id_whatsapp: document.getElementById('cad_whatsapp').value,
            email: document.getElementById('cad_email').value, rua: document.getElementById('cad_rua').value,
            numero: document.getElementById('cad_numero').value, complemento: document.getElementById('cad_complemento').value,
            bairro: document.getElementById('cad_bairro').value, cidade: document.getElementById('cad_cidade').value,
            uf: document.getElementById('cad_uf').value, rg: document.getElementById('cad_rg').value,
            data_exp_rg: document.getElementById('cad_exp_rg').value, tipo_pix: document.getElementById('cad_tipo_pix').value,
            chave_pix: document.getElementById('cad_chave_pix').value
        };
        if(!payload.cpf || !payload.nome_completo) return crmToast("CPF e Nome Completo são obrigatórios.", "info", 5000);

        const res = await reqApi('salvar_cliente', payload);
        if(res.success) { crmToast("Sucesso!", "success"); modalCadastro.hide(); carregarTabelaClientes(); } 
        else { crmToast("❌ " + res.msg, "error", 6000); }
    }

    async function excluirCliente(cpf) {
        if(!confirm("Deseja realmente excluir este cliente? Isso apagará os dados dele.")) return;
        const res = await reqApi('excluir_cliente', { cpf: cpf });
        if(res.success) carregarTabelaClientes(); else crmToast("❌ " + res.msg, "error", 6000);
    }
</script>

<?php $caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; if(file_exists($caminho_footer)) include $caminho_footer; ?>