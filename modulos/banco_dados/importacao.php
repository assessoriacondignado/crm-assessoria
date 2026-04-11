<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>
<?php
try {
    $stmtCamp = $pdo->query("SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE STATUS = 'ATIVO' ORDER BY NOME_CAMPANHA ASC");
    $lista_campanhas_import = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lista_campanhas_import = [];
}
?>
<style>
    .select-mapped {
        background-color: #d1e7dd !important; 
        border: 2px solid #0f5132 !important; 
        color: #0f5132 !important; 
        box-shadow: 0 0 5px rgba(25, 135, 84, 0.5);
    }
    .accordion-button:not(.collapsed) {
        background-color: #212529 !important;
        color: white !important;
        box-shadow: none;
    }
</style>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-primary fw-bold"><i class="fas fa-rocket me-2"></i> Gerenciador de Importação Massiva</h2>
        <p class="text-muted">Importe milhares de dados sem travar a sua tela. O sistema roda em background, com pausa inteligente e reaproveitamento de planilhas.</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-11">
        <div class="card border-dark shadow-lg rounded-3 mb-5 overflow-hidden">
            <div class="card-body p-4 p-md-5 bg-light">
                
                <div id="etapa1" class="etapa-importacao">
                    <h4 class="mb-4 text-dark fw-bold text-uppercase border-bottom border-dark pb-2">Etapa 1: Escolha o Arquivo</h4>
                    
                    <div class="d-flex justify-content-end mb-3">
                        <button class="btn btn-outline-info fw-bold shadow-sm" onclick="mudarEtapa('etapa3'); carregarFilaDashboard();"><i class="fas fa-list"></i> Ver Fila de Importações</button>
                    </div>

                    <form id="formUpload" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label fw-bold text-dark">Nome da Importação (Ex: Lote_Agosto_2026)</label>
                            <input type="text" name="nome_importacao" id="nome_importacao" class="form-control form-control-lg border-dark shadow-sm" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-dark">Arquivo CSV ou ZIP</label>
                            <input type="file" name="arquivo_csv" id="arquivo_csv" class="form-control form-control-lg border-dark shadow-sm" accept=".csv, .zip" required>
                        </div>
                        
                        <div id="container_progresso_upload" class="mb-3 text-start" style="display: none;">
                            <label class="form-label fw-bold text-primary small mb-1" id="texto_progresso_upload">Enviando arquivo para o servidor: 0%</label>
                            <div class="progress shadow-sm border border-dark bg-white" style="height: 25px; border-radius: 8px;">
                                <div id="barra_progresso_upload" class="progress-bar progress-bar-striped progress-bar-animated bg-primary text-white fw-bold" style="width: 0%; font-size: 14px;">0%</div>
                            </div>
                        </div>

                        <div class="text-end">
                            <button type="button" id="btn_upload" class="btn btn-dark btn-lg shadow-sm fw-bold border-dark" onclick="enviarParaCache()">Próximo Passo <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </form>
                </div>

                <div id="etapa2" class="etapa-importacao" style="display: none;">
                    <h4 class="mb-3 text-dark fw-bold text-uppercase border-bottom border-dark pb-2">Etapa 2: Mapeamento de Colunas</h4>
                    
                    <div class="alert alert-info fs-6 border-dark text-dark shadow-sm">
                        <i class="fas fa-info-circle text-primary me-2"></i> Revise a amostra bruta. Selecione o modelo de destino e ligue os campos correspondentes.
                    </div>
                    
                    <input type="hidden" id="arquivo_cache_nome" value="">
                    
                    <details class="mb-4 bg-white p-3 rounded-3 border border-dark shadow-sm">
                        <summary class="fw-bold text-dark text-uppercase" style="cursor: pointer;"><i class="fas fa-table text-info me-2"></i> Ver amostra bruta do arquivo recebido</summary>
                        <div class="table-responsive mt-3 shadow-sm border-dark rounded-3 overflow-hidden" style="max-height: 250px; overflow-y: auto;">
                            <table class="table table-sm table-hover align-middle mb-0 border-dark text-center bg-white" id="tabela_amostra">
                                <thead class="table-dark text-white text-uppercase sticky-top" id="amostra_head" style="font-size: 0.85rem; z-index: 1;"></thead>
                                <tbody id="amostra_body" class="border-dark text-dark"></tbody>
                            </table>
                        </div>
                    </details>

                    <div class="mb-4">
                        <label class="form-label fw-bold fs-5 text-dark text-uppercase">Para qual Tabela esses dados irão?</label>
                        <select id="modelo_tabela" class="form-select form-select-lg border-dark shadow-sm" onchange="carregarCamposModelo()">
                            <option value="">-- Selecione o Modelo --</option>
                            <option value="IMPORTACAO_COMPLETA_CADASTRO" class="fw-bold text-primary">Importação Completa (CRM)</option>
                            <option value="IMPORTACAO_HISTORICO_STATUS" class="fw-bold text-success">Migração de Status / Histórico (CRM Antigo)</option>
                            <option value="dados_cadastrais">Somente Dados Cadastrais</option>
                            <option value="telefones">Somente Telefones</option>
                            <option value="enderecos">Somente Endereços</option>
                            <option value="emails">Somente E-mails</option>
                            <option value="convenios">Somente Convênios Básicos</option>
                            <option value="banco_de_Dados_inss_dados_cadastrais" class="fw-bold text-danger">INSS - Dados de Benefício (DIB/Situação/Bancários)</option>
                            <option value="banco_de_Dados_inss_contratos" class="fw-bold text-danger">INSS - Contratos e Empréstimos</option>
                        </select>
                    </div>
                    
                    <div class="row g-3 mb-4" id="area_parametros" style="display: none;">
                        
                        <div class="col-md-4" id="container_parametro_campanha">
                            <div class="p-3 border border-danger rounded-3 bg-white h-100 shadow-sm text-center">
                                <label class="form-label fw-bold text-danger text-uppercase mb-2"><i class="fas fa-bullhorn"></i> Parâmetro: Campanha</label>
                                <select id="id_campanha_alvo" class="form-select border-danger shadow-sm">
                                    <option value="">-- Nenhuma Campanha --</option>
                                    <?php foreach($lista_campanhas_import as $c): ?>
                                        <option value="<?= $c['ID'] ?>"><?= htmlspecialchars($c['NOME_CAMPANHA']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted fw-bold d-block mt-2" style="font-size: 0.70rem;">Clientes vinculados automaticamente a esta campanha.</small>
                            </div>
                        </div>

                        <div class="col-md-4" id="container_parametro_limpar_contratos" style="display: none;">
                            <div class="p-3 border border-warning rounded-3 bg-white h-100 shadow-sm text-center">
                                <label class="form-label fw-bold text-warning text-uppercase mb-2"><i class="fas fa-eraser"></i> Limpar Contratos Antigos?</label>
                                <select id="apagar_contratos_alvo" class="form-select border-warning shadow-sm">
                                    <option value="NAO" class="fw-bold">NÃO (Apenas atualizar/adicionar)</option>
                                    <option value="SIM" class="fw-bold text-danger">SIM (Apagar antigos desta matrícula)</option>
                                </select>
                                <small class="text-muted fw-bold d-block mt-2" style="font-size: 0.70rem;">Apaga todos os contratos antigos daquela matrícula e insere apenas os da planilha.</small>
                            </div>
                        </div>
                        
                    </div>

                    <div id="area_mapeamento" class="mb-4" style="display: none;"></div>

                    <div class="d-flex justify-content-between mt-4 pt-3 border-top border-dark">
                        <button type="button" class="btn btn-outline-dark btn-lg shadow-sm fw-bold bg-white" onclick="mudarEtapa('etapa1')"><i class="fas fa-arrow-left me-1"></i> Voltar e Trocar</button>
                        
                        <div>
                            <button type="button" class="btn btn-warning btn-lg shadow-sm fw-bold border-dark text-dark me-2" onclick="testarImportacao()" id="btn_testar_importacao" disabled><i class="fas fa-vial me-1"></i> Testar 5 Linhas</button>
                            <button type="button" class="btn btn-success btn-lg shadow-sm fw-bold border-dark text-dark" onclick="iniciarImportacaoBackground()" id="btn_iniciar_importacao" disabled>Colocar na Fila <i class="fas fa-rocket ms-1"></i></button>
                        </div>
                    </div>
                </div>

                <div id="etapa3" class="etapa-importacao" style="display: none;">
                    <h4 class="mb-4 text-dark fw-bold text-uppercase border-bottom border-dark pb-2">
                        <i class="fas fa-tasks text-info me-2"></i> Dashboard: Fila de Importações
                    </h4>
                    
                    <div class="d-flex justify-content-between mb-3">
                        <button type="button" class="btn btn-dark fw-bold shadow-sm border-dark" onclick="mudarEtapa('etapa1'); document.getElementById('formUpload').reset();"><i class="fas fa-plus me-1"></i> Subir Novo Arquivo</button>
                        <button type="button" class="btn btn-outline-dark fw-bold shadow-sm bg-white" onclick="carregarFilaDashboard()"><i class="fas fa-sync-alt me-1"></i> Atualizar Fila</button>
                    </div>

                    <div class="table-responsive bg-white shadow-sm border border-dark rounded-3">
                        <table class="table table-hover align-middle mb-0 text-center">
                            <thead class="table-dark text-white text-uppercase" style="font-size: 0.80rem;">
                                <tr>
                                    <th style="width: 5%;">ID</th>
                                    <th class="text-start" style="width: 25%;">Lote / Nome</th>
                                    <th style="width: 35%;">Progresso</th>
                                    <th style="width: 15%;">Status</th>
                                    <th style="width: 20%;">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tbody_fila_importacoes">
                                <tr><td colspan="5" class="text-center py-4 text-muted fw-bold">Carregando fila...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTesteImportacao" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-vial me-2"></i> Resultado do Teste (5 Linhas)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body bg-light">
                <p class="text-muted fw-bold">As 5 primeiras linhas da sua planilha foram importadas. Verifique se os dados caíram corretamente clicando na ficha.</p>
                <div class="table-responsive bg-white shadow-sm border border-dark rounded-3">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="table-dark text-white" style="font-size: 0.85rem;">
                            <tr>
                                <th>#</th>
                                <th>CPF</th>
                                <th>Status do Teste</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="tbody_resultados_teste">
                            </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<div class="offcanvas offcanvas-end shadow-lg border-start border-dark" tabindex="-1" id="offcanvasFichaCliente" style="width: 50%; min-width: 400px;">
    <div class="offcanvas-header bg-dark text-white">
        <h5 class="offcanvas-title fw-bold"><i class="fas fa-user-circle me-2"></i> Ficha Rápida do Cliente</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body p-0 bg-light">
        <iframe id="iframeFichaCliente" src="" width="100%" height="100%" style="border: none;"></iframe>
    </div>
</div>

<script>
    let cabecalhosCSV = [];
    let amostraCSV = [];
    let watcherInterval = null; 

    const colunasDoBanco = {
        'IMPORTACAO_COMPLETA_CADASTRO': [
            { id: 'cpf', nome: 'CPF * (Obrigatório)', grupo: 'Dados Cadastrais' },
            { id: 'nome', nome: 'Nome Completo', grupo: 'Dados Cadastrais' }, 
            { id: 'sexo', nome: 'Sexo', grupo: 'Dados Cadastrais' },
            { id: 'nascimento', nome: 'Nascimento (DD/MM/YYYY)', grupo: 'Dados Cadastrais' },
            { id: 'nome_mae', nome: 'Nome da Mãe', grupo: 'Dados Cadastrais' },
            { id: 'nome_pai', nome: 'Nome do Pai', grupo: 'Dados Cadastrais' },
            { id: 'rg', nome: 'RG', grupo: 'Dados Cadastrais' },
            { id: 'cnh', nome: 'CNH', grupo: 'Dados Cadastrais' },
            { id: 'carteira_profissional', nome: 'Cart. Profissional', grupo: 'Dados Cadastrais' },
            { id: 'agrupamento', nome: 'Agrupamento (Texto Livre)', grupo: 'Dados Cadastrais' },
            { id: 'tel_1', nome: '[+] Telefone Celular 1', grupo: 'Telefones' },
            { id: 'tel_2', nome: '[+] Telefone Celular 2', grupo: 'Telefones' },
            { id: 'tel_3', nome: '[+] Telefone Celular 3', grupo: 'Telefones' },
            { id: 'tel_4', nome: '[+] Telefone Celular 4', grupo: 'Telefones' },
            { id: 'tel_5', nome: '[+] Telefone Celular 5', grupo: 'Telefones' },
            { id: 'tel_6', nome: '[+] Telefone Celular 6', grupo: 'Telefones' },
            { id: 'tel_7', nome: '[+] Telefone Celular 7', grupo: 'Telefones' },
            { id: 'tel_8', nome: '[+] Telefone Celular 8', grupo: 'Telefones' },
            { id: 'tel_9', nome: '[+] Telefone Celular 9', grupo: 'Telefones' },
            { id: 'tel_10', nome: '[+] Telefone Celular 10', grupo: 'Telefones' },
            { id: 'end_cep', nome: '[-] CEP', grupo: 'Endereço' },
            { id: 'end_logradouro', nome: '[-] Logradouro', grupo: 'Endereço' },
            { id: 'end_numero', nome: '[-] Número Resid.', grupo: 'Endereço' },
            { id: 'end_bairro', nome: '[-] Bairro', grupo: 'Endereço' },
            { id: 'end_cidade', nome: '[-] Cidade', grupo: 'Endereço' },
            { id: 'end_uf', nome: '[-] Estado (UF)', grupo: 'Endereço' },
            { id: 'email_1', nome: '[@] E-mail 1', grupo: 'E-mails' },
            { id: 'email_2', nome: '[@] E-mail 2', grupo: 'E-mails' },
            { id: 'email_3', nome: '[@] E-mail 3', grupo: 'E-mails' },
            { id: 'email_4', nome: '[@] E-mail 4', grupo: 'E-mails' },
            { id: 'convenio_1', nome: '[*] Convênio 1', grupo: 'Convênios e Matrículas' },
            { id: 'matricula_1', nome: '[*] Matrícula 1', grupo: 'Convênios e Matrículas' },
            { id: 'convenio_2', nome: '[*] Convênio 2', grupo: 'Convênios e Matrículas' },
            { id: 'matricula_2', nome: '[*] Matrícula 2', grupo: 'Convênios e Matrículas' },
            { id: 'convenio_3', nome: '[*] Convênio 3', grupo: 'Convênios e Matrículas' },
            { id: 'matricula_3', nome: '[*] Matrícula 3', grupo: 'Convênios e Matrículas' }
        ],
        'IMPORTACAO_HISTORICO_STATUS': [
            { id: 'cpf', nome: 'CPF do Cliente * (Obrigatório)', grupo: 'Chaves de Vínculo' },
            { id: 'status_nome', nome: 'Status da Campanha *', grupo: 'Dados do Atendimento' },
            { id: 'data_registro', nome: 'Data do Registro', grupo: 'Dados do Atendimento' },
            { id: 'usuario_atendimento', nome: 'Usuário/Operador', grupo: 'Dados do Atendimento' },
            { id: 'telefone_usado', nome: 'Telefone Principal', grupo: 'Dados do Atendimento' }
        ],
        'banco_de_Dados_inss_dados_cadastrais': [
            { id: 'cpf', nome: 'CPF * (Obrigatório)', grupo: 'Chaves de Vínculo' },
            { id: 'matricula_nb', nome: 'Matrícula (NB) *', grupo: 'Chaves de Vínculo' },
            { id: 'especie_beneficio', nome: 'Espécie do Benefício', grupo: 'Dados do Benefício' },
            { id: 'esp_consignavel', nome: 'É Consignável?', grupo: 'Dados do Benefício' },
            { id: 'situacao_beneficio', nome: 'Situação (Ativo/Inativo)', grupo: 'Dados do Benefício' },
            { id: 'bloqueio_emprestimo', nome: 'Bloqueio Empréstimo', grupo: 'Dados do Benefício' },
            { id: 'dib', nome: 'DIB (Data de Início)', grupo: 'Dados do Benefício' },
            { id: 'representante_legal', nome: 'Representante Legal', grupo: 'Dados do Benefício' },
            { id: 'pensao_alimenticia', nome: 'Pensão Alimentícia', grupo: 'Dados do Benefício' },
            { id: 'margem_calculada', nome: 'Margem Livre', grupo: 'Financeiro' },
            { id: 'margem_cartao', nome: 'Margem RMC', grupo: 'Financeiro' },
            { id: 'margem_cartao_ben', nome: 'Margem RCC', grupo: 'Financeiro' },
            { id: 'valor_base_calculo', nome: 'Salário Base', grupo: 'Financeiro' },
            { id: 'banco_pagamento', nome: 'Banco de Pagamento', grupo: 'Bancário' },
            { id: 'agencia_pagamento', nome: 'Agência', grupo: 'Bancário' },
            { id: 'conta_pagamento', nome: 'Conta', grupo: 'Bancário' },
            { id: 'forma_pagamento', nome: 'Forma de Pagamento', grupo: 'Bancário' }
        ],
        'banco_de_Dados_inss_contratos': [
            { id: 'cpf', nome: 'CPF * (Obrigatório)', grupo: 'Chaves de Vínculo' },
            { id: 'matricula_nb', nome: 'Matrícula (NB) *', grupo: 'Chaves de Vínculo' },
            { id: 'contrato', nome: 'Nº do Contrato *', grupo: 'Chaves de Vínculo' },
            { id: 'banco', nome: 'Banco Empréstimo', grupo: 'Dados do Contrato' },
            { id: 'tipo_emprestimo', nome: 'Tipo (76, 66, RMC)', grupo: 'Dados do Contrato' },
            { id: 'valor_emprestimo', nome: 'Valor Financiado', grupo: 'Valores' },
            { id: 'valor_parcela', nome: 'Valor Parcela', grupo: 'Valores' },
            { id: 'prazo', nome: 'Prazo Total', grupo: 'Prazos' },
            { id: 'parcelas_pagas', nome: 'Parcelas Pagas', grupo: 'Prazos' },
            { id: 'inicio_desconto', nome: 'Início do Desconto', grupo: 'Prazos' },
            { id: 'situacao', primary: false, nome: 'Situação (Ativo)', grupo: 'Taxas e Saldos' },
            { id: 'taxa_juros', nome: 'Taxa de Juros (%)', grupo: 'Taxas e Saldos' },
            { id: 'saldo_quitacao', nome: 'Saldo Devedor', grupo: 'Taxas e Saldos' }
        ],
        'dados_cadastrais': [
            { id: 'cpf', nome: 'CPF * (Obrigatório)', grupo: 'Dados Cadastrais' },
            { id: 'nome', nome: 'Nome Completo', grupo: 'Dados Cadastrais' }, 
            { id: 'sexo', nome: 'Sexo', grupo: 'Dados Cadastrais' },
            { id: 'nascimento', nome: 'Nascimento', grupo: 'Dados Cadastrais' },
            { id: 'nome_mae', nome: 'Nome da Mãe', grupo: 'Dados Cadastrais' },
            { id: 'nome_pai', nome: 'Nome do Pai', grupo: 'Dados Cadastrais' },
            { id: 'agrupamento', nome: 'Agrupamento', grupo: 'Dados Cadastrais' }
        ],
        'telefones': [
            { id: 'cpf', nome: 'CPF do Titular *', grupo: 'Telefones' },
            { id: 'telefone_cel_1', nome: 'Telefone 1', grupo: 'Telefones' },
            { id: 'telefone_cel_2', nome: 'Telefone 2', grupo: 'Telefones' },
            { id: 'telefone_cel_3', nome: 'Telefone 3', grupo: 'Telefones' }
        ],
        'enderecos': [
            { id: 'cpf', nome: 'CPF *', grupo: 'Endereço' },
            { id: 'cep', nome: 'CEP', grupo: 'Endereço' },
            { id: 'logradouro', nome: 'Logradouro (Rua)', grupo: 'Endereço' },
            { id: 'numero', nome: 'Número', grupo: 'Endereço' },
            { id: 'bairro', nome: 'Bairro', grupo: 'Endereço' },
            { id: 'cidade', nome: 'Cidade', grupo: 'Endereço' },
            { id: 'uf', nome: 'UF (Estado)', grupo: 'Endereço' }
        ]
    };

    document.addEventListener("DOMContentLoaded", function() {
        mudarEtapa('etapa3'); 
        carregarFilaDashboard();
        watcherInterval = setInterval(carregarFilaDashboard, 3000);
    });

    function mudarEtapa(etapa_id) {
        document.querySelectorAll('.etapa-importacao').forEach(el => el.style.display = 'none');
        document.getElementById(etapa_id).style.display = 'block';
    }

    function enviarParaCache() {
        let nome = document.getElementById('nome_importacao').value;
        let fileInput = document.getElementById('arquivo_csv');
        let btn = document.getElementById('btn_upload');

        if(!nome || fileInput.files.length === 0) return crmToast("Preencha o nome e selecione o Arquivo!", "warning", 5000);

        let formData = new FormData();
        formData.append('arquivo_csv', fileInput.files[0]);

        btn.innerHTML = '<i class="fas fa-spinner fa-spin text-info me-2"></i> Aguarde...';
        btn.disabled = true;

        document.getElementById('container_progresso_upload').style.display = 'block';
        let barra = document.getElementById('barra_progresso_upload');
        let texto = document.getElementById('texto_progresso_upload');
        barra.style.width = '0%';
        barra.innerText = '0%';
        barra.classList.remove('bg-success');
        barra.classList.add('bg-primary');
        texto.innerText = 'Enviando arquivo para o servidor: 0%';

        let xhr = new XMLHttpRequest();
        xhr.open('POST', 'upload_cache.php', true);

        xhr.upload.onprogress = function(e) {
            if (e.lengthComputable) {
                let percentual = Math.floor((e.loaded / e.total) * 100);
                barra.style.width = percentual + '%';
                barra.innerText = percentual + '%';
                
                if (percentual === 100) {
                    texto.innerText = 'Upload concluído! Servidor extraindo a amostra...';
                    barra.classList.remove('bg-primary');
                    barra.classList.add('bg-success');
                } else {
                    texto.innerText = 'Enviando arquivo para o servidor: ' + percentual + '%';
                }
            }
        };

        xhr.onload = function() {
            btn.innerHTML = 'Próximo Passo <i class="fas fa-arrow-right ms-1"></i>';
            btn.disabled = false;
            
            if (xhr.status === 200) {
                try {
                    let data = JSON.parse(xhr.responseText);
                    if(data.sucesso) {
                        document.getElementById('container_progresso_upload').style.display = 'none';
                        document.getElementById('arquivo_cache_nome').value = data.arquivo_cache;
                        cabecalhosCSV = data.cabecalhos; 
                        amostraCSV = data.amostra; 
                        
                        let trHead = '<tr>';
                        data.cabecalhos.forEach(cab => { trHead += `<th>${cab}</th>`; });
                        trHead += '</tr>';
                        document.getElementById('amostra_head').innerHTML = trHead;

                        let htmlBody = '';
                        data.amostra.forEach(linha => {
                            htmlBody += '<tr class="border-bottom border-dark">';
                            linha.forEach(col => { htmlBody += `<td class="px-2 py-2">${col}</td>`; });
                            htmlBody += '</tr>';
                        });
                        document.getElementById('amostra_body').innerHTML = htmlBody;

                        mudarEtapa('etapa2');
                    } else { crmToast("❌ " + data.erro, "error", 6000); }
                } catch (e) {
                    crmToast("❌ Erro servidor: resposta inválida.", "error", 7000);
                }
            } else {
                crmToast("❌ Erro HTTP " + xhr.status + ": servidor bloqueou.", "error", 7000);
            }
        };

        xhr.onerror = function() {
            btn.innerHTML = 'Próximo Passo <i class="fas fa-arrow-right ms-1"></i>';
            btn.disabled = false;
            document.getElementById('container_progresso_upload').style.display = 'none';
            crmToast("ERRO DE REDE: Falha na conexão com o servidor durante o upload.", "warning", 5000);
        };

        xhr.send(formData);
    }

    function carregarCamposModelo() {
        let modelo = document.getElementById('modelo_tabela').value;
        let area = document.getElementById('area_mapeamento');
        let paramArea = document.getElementById('area_parametros');
        let paramLixeira = document.getElementById('container_parametro_limpar_contratos');
        let btnImportar = document.getElementById('btn_iniciar_importacao');
        let btnTestar = document.getElementById('btn_testar_importacao');
        
        if(!modelo) {
            area.style.display = 'none';
            paramArea.style.display = 'none';
            btnImportar.disabled = true;
            btnTestar.disabled = true;
            return;
        }

        paramArea.style.display = 'flex';
        if (modelo === 'banco_de_Dados_inss_contratos') {
            paramLixeira.style.display = 'block';
        } else {
            paramLixeira.style.display = 'none';
        }

        let colunas = colunasDoBanco[modelo] || [];
        
        let agrupados = {};
        colunas.forEach(col => {
            if(!agrupados[col.grupo]) agrupados[col.grupo] = [];
            agrupados[col.grupo].push(col);
        });

        let html = '<h5 class="mb-4 text-dark fw-bold text-uppercase"><i class="fas fa-link text-primary me-2"></i> Mapeamento de Colunas</h5>';
        html += '<div class="accordion shadow-sm" id="accordionMapeamento">';

        let indexGrupo = 0;
        for (let grupo in agrupados) {
            let cols = agrupados[grupo];
            let isFirst = (indexGrupo === 0) ? 'show' : ''; 
            let isCollapsed = (indexGrupo === 0) ? '' : 'collapsed';

            html += `
            <div class="accordion-item border-dark mb-2 rounded overflow-hidden">
                <h2 class="accordion-header">
                    <button class="accordion-button ${isCollapsed} bg-dark text-white fw-bold text-uppercase" type="button" data-bs-toggle="collapse" data-bs-target="#collapse${indexGrupo}">
                        <i class="fas fa-folder-open me-2 text-info"></i> ${grupo}
                    </button>
                </h2>
                <div id="collapse${indexGrupo}" class="accordion-collapse collapse ${isFirst}" data-bs-parent="#accordionMapeamento">
                    <div class="accordion-body bg-light">
                        <div class="row g-3">`;

            cols.forEach(bancoCol => {
                html += `
                    <div class="col-md-4">
                        <label class="form-label fw-bold text-dark small"><i class="fas fa-database text-info me-1"></i> Banco: ${bancoCol.nome}</label>
                        <select class="form-select border-dark shadow-sm sel-mapeamento fw-bold" data-colunabanco="${bancoCol.id}" onchange="marcarCorSelect(this)">
                            <option value="">-- Não Vincular (Ignorar) --</option>`;
                
                cabecalhosCSV.forEach((csvCol, indiceCSV) => {
                    let nomeLimpoBanco = bancoCol.id.toLowerCase().replace(/_/g, ' ');
                    let nomeLimpoCSV = csvCol.toLowerCase().trim();
                    let selecionado = (nomeLimpoBanco === nomeLimpoCSV || csvCol.toLowerCase().includes(nomeLimpoBanco.split(' ')[0])) ? 'selected' : '';
                    html += `<option value="${indiceCSV}" ${selecionado}>Planilha: ${csvCol}</option>`;
                });

                html += `</select></div>`;
            });

            html += `       </div>
                    </div>
                </div>
            </div>`;
            indexGrupo++;
        }

        html += '</div>'; 
        area.innerHTML = html;
        area.style.display = 'block';
        btnImportar.disabled = false;
        btnTestar.disabled = false;

        document.querySelectorAll('.sel-mapeamento').forEach(select => { marcarCorSelect(select); });
    }

    function marcarCorSelect(selectElement) {
        if(selectElement.value !== "") {
            selectElement.classList.add('select-mapped');
            selectElement.classList.remove('border-dark');
        } else {
            selectElement.classList.remove('select-mapped');
            selectElement.classList.add('border-dark');
        }
    }

    function testarImportacao() {
        let modelo = document.getElementById('modelo_tabela').value;
        let arquivoCache = document.getElementById('arquivo_cache_nome').value;
        let idCampanhaVinculo = document.getElementById('id_campanha_alvo').value;
        let apagarContratos = document.getElementById('apagar_contratos_alvo') ? document.getElementById('apagar_contratos_alvo').value : 'NAO';

        let mapeamento = {};
        document.querySelectorAll('.sel-mapeamento').forEach(select => {
            if(select.value !== "") mapeamento[select.getAttribute('data-colunabanco')] = parseInt(select.value);
        });

        if(!mapeamento.hasOwnProperty('cpf')) return crmToast("Aviso: É obrigatório vincular a coluna 'CPF'!", "info", 5000);

        let btn = document.getElementById('btn_testar_importacao');
        let iconAntigo = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
        btn.disabled = true;

        let formData = new FormData();
        formData.append('acao', 'testar_importacao');
        formData.append('arquivo_cache', arquivoCache);
        formData.append('modelo_tabela', modelo);
        formData.append('id_campanha', idCampanhaVinculo); 
        formData.append('apagar_contratos', apagarContratos); 
        formData.append('mapeamento', JSON.stringify(mapeamento));

        fetch('processar_importacao_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = iconAntigo;
            btn.disabled = false;
            
            if(data.sucesso) {
                let tb = document.getElementById('tbody_resultados_teste');
                tb.innerHTML = '';
                
                data.resultados.forEach((res, index) => {
                    let badge = res.status === 'Sucesso' ? 'bg-success' : 'bg-danger';
                    tb.innerHTML += `
                        <tr>
                            <td class="fw-bold">${index + 1}</td>
                            <td class="fw-bold">${res.cpf}</td>
                            <td><span class="badge ${badge} border border-dark text-white">${res.status}</span></td>
                            <td>
                                <button class="btn btn-sm btn-dark fw-bold border-dark shadow-sm" onclick="abrirFichaCliente('${res.cpf}')">
                                    <i class="fas fa-external-link-alt"></i> Ver Ficha
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                let modal = new bootstrap.Modal(document.getElementById('modalTesteImportacao'));
                modal.show();
            } else {
                crmToast("❌ Erro no teste: " + data.erro, "error", 6000);
            }
        }).catch(e => {
            btn.innerHTML = iconAntigo;
            btn.disabled = false;
            crmToast("Erro de conexão ao realizar o teste.", "info", 5000);
        });
    }

    function abrirFichaCliente(cpf) {
        // Agora sim, com o link oficial do seu sistema!
        let urlFicha = '/modulos/banco_dados/consulta.php?busca=' + cpf; 
        
        document.getElementById('iframeFichaCliente').src = urlFicha;
        
        let offcanvas = new bootstrap.Offcanvas(document.getElementById('offcanvasFichaCliente'));
        offcanvas.show();
    }

    function iniciarImportacaoBackground() {
        let modelo = document.getElementById('modelo_tabela').value;
        let arquivoCache = document.getElementById('arquivo_cache_nome').value;
        let nomeImportacao = document.getElementById('nome_importacao').value;
        let idCampanhaVinculo = document.getElementById('id_campanha_alvo').value;
        let apagarContratos = document.getElementById('apagar_contratos_alvo') ? document.getElementById('apagar_contratos_alvo').value : 'NAO';

        if(!modelo) return crmToast("Selecione o Modelo de Tabela!", "info", 5000);
        
        let mapeamento = {};
        document.querySelectorAll('.sel-mapeamento').forEach(select => {
            if(select.value !== "") mapeamento[select.getAttribute('data-colunabanco')] = parseInt(select.value);
        });

        if(!mapeamento.hasOwnProperty('cpf')) return crmToast("Aviso: É obrigatório vincular a coluna 'CPF'!", "info", 5000);
        if(modelo.includes('inss') && !mapeamento.hasOwnProperty('matricula_nb')) return crmToast("Aviso: É obrigatório vincular a coluna 'MATRÍCULA' para dados do INSS!", "info", 5000);

        let formData = new FormData();
        formData.append('arquivo_cache', arquivoCache);
        formData.append('modelo_tabela', modelo);
        formData.append('nome_importacao', nomeImportacao);
        formData.append('id_campanha', idCampanhaVinculo); 
        formData.append('apagar_contratos', apagarContratos); 
        formData.append('mapeamento', JSON.stringify(mapeamento));
        formData.append('acao', 'iniciar_tarefa'); 

        fetch('processar_importacao_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                mudarEtapa('etapa3'); 
                carregarFilaDashboard();
            } else {
                crmToast("❌ Erro ao iniciar: " + data.erro, "error", 6000);
            }
        });
    }

    function carregarFilaDashboard() {
        let formData = new FormData();
        formData.append('acao', 'listar_tarefas');

        fetch('processar_importacao_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                let tb = document.getElementById('tbody_fila_importacoes');
                tb.innerHTML = '';
                
                if(data.tarefas.length === 0) {
                    tb.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted fw-bold">Nenhuma importação no histórico.</td></tr>';
                    return;
                }

                data.tarefas.forEach(t => {
                    let pct = 0;
                    let total = parseInt(t.QTD_TOTAL);
                    let processada = parseInt(t.QTD_PROCESSADA);
                    if(total > 0) { pct = Math.floor((processada / total) * 100); }
                    
                    let bgStatus = 'bg-secondary';
                    let icone = '';
                    let colorBar = 'bg-dark';
                    let animado = '';
                    
                    if(t.STATUS === 'PENDENTE') { bgStatus = 'bg-warning text-dark'; icone = 'fas fa-clock'; colorBar = 'bg-warning'; }
                    else if(t.STATUS === 'PROCESSANDO') { bgStatus = 'bg-primary'; icone = 'fas fa-cogs fa-spin'; colorBar = 'bg-primary'; animado = 'progress-bar-animated'; }
                    else if(t.STATUS === 'PAUSADA') { bgStatus = 'bg-warning text-dark'; icone = 'fas fa-pause-circle'; colorBar = 'bg-secondary'; }
                    else if(t.STATUS === 'CONCLUIDA') { bgStatus = 'bg-success'; icone = 'fas fa-check-circle'; colorBar = 'bg-success'; }
                    else if(t.STATUS === 'CANCELADA') { bgStatus = 'bg-danger'; icone = 'fas fa-times-circle'; colorBar = 'bg-danger'; }
                    else if(t.STATUS === 'ERRO_CRITICO') { bgStatus = 'bg-danger'; icone = 'fas fa-exclamation-triangle'; colorBar = 'bg-danger'; }

                    let acoes = '';
                    if(t.STATUS === 'PROCESSANDO' || t.STATUS === 'PENDENTE') {
                        acoes += `<button class="btn btn-sm btn-warning text-dark border-dark fw-bold me-1" onclick="pausarTarefa(${t.ID})" title="Pausar"><i class="fas fa-pause"></i></button>`;
                    }
                    if(t.STATUS === 'PAUSADA') {
                        acoes += `<button class="btn btn-sm btn-success border-dark fw-bold me-1" onclick="retomarTarefa(${t.ID})" title="Retomar (Fast Forward)"><i class="fas fa-play"></i></button>`;
                    }
                    if(t.STATUS !== 'CONCLUIDA' && t.STATUS !== 'CANCELADA') {
                        acoes += `<button class="btn btn-sm btn-danger border-dark fw-bold me-1" onclick="excluirTarefa(${t.ID})" title="Cancelar / Excluir"><i class="fas fa-trash"></i></button>`;
                    }
                    
                    // BOTÃO DE REUTILIZAR
                    if(t.STATUS === 'CONCLUIDA' || t.STATUS === 'CANCELADA' || t.STATUS === 'ERRO_CRITICO') {
                        acoes += `<button class="btn btn-sm btn-info text-dark border-dark fw-bold me-1" onclick="reutilizarArquivo('${t.ARQUIVO}', '${t.NOME_IMPORTACAO}')" title="Reutilizar Planilha"><i class="fas fa-redo"></i></button>`;
                    }
                    
                    if(t.STATUS === 'CONCLUIDA' && t.ARQUIVO_LOG_ERRO) {
                        acoes += `<a href="/modulos/banco_dados/Arquivo_erros_importacao/${t.ARQUIVO_LOG_ERRO}" class="btn btn-sm btn-outline-danger fw-bold border-dark" download title="Baixar Log de Erros"><i class="fas fa-file-alt"></i></a>`;
                    }

                    let linhaHtml = `
                    <tr class="border-bottom border-dark bg-white">
                        <td class="fw-bold">${t.ID}</td>
                        <td class="text-start">
                            <span class="fw-bold text-dark d-block text-truncate" style="max-width: 250px;">${t.NOME_IMPORTACAO}</span>
                            <small class="text-muted"><i class="fas fa-database"></i> ${t.TABELA_DESTINO}</small>
                        </td>
                        <td>
                            <div class="progress shadow-sm border border-dark bg-light" style="height: 20px; border-radius: 5px;">
                                <div class="progress-bar progress-bar-striped ${colorBar} ${animado} text-white fw-bold" style="width: ${pct}%; font-size: 12px;">${pct}%</div>
                            </div>
                            <div class="d-flex justify-content-between mt-1" style="font-size: 0.70rem;">
                                <span class="fw-bold text-dark">${processada} / ${total}</span>
                                <span class="text-danger fw-bold"><i class="fas fa-times"></i> ${t.QTD_ERROS} erros</span>
                            </div>
                        </td>
                        <td><span class="badge ${bgStatus} border border-dark rounded-0 px-2 py-1 shadow-sm"><i class="${icone} me-1"></i> ${t.STATUS}</span></td>
                        <td>${acoes}</td>
                    </tr>
                    `;
                    tb.innerHTML += linhaHtml;
                });
            }
        });
    }

    function pausarTarefa(id) {
        let fd = new FormData(); fd.append('acao', 'pausar_tarefa'); fd.append('task_id', id);
        fetch('processar_importacao_ajax.php', { method: 'POST', body: fd }).then(r => carregarFilaDashboard());
    }

    function retomarTarefa(id) {
        let fd = new FormData(); fd.append('acao', 'retomar_tarefa'); fd.append('task_id', id);
        fetch('processar_importacao_ajax.php', { method: 'POST', body: fd }).then(r => carregarFilaDashboard());
    }

    function excluirTarefa(id) {
        if(!confirm('Tem certeza que deseja cancelar esta importação permanentemente?')) return;
        let fd = new FormData(); fd.append('acao', 'excluir_tarefa'); fd.append('task_id', id);
        fetch('processar_importacao_ajax.php', { method: 'POST', body: fd }).then(r => carregarFilaDashboard());
    }

    function reutilizarArquivo(arquivo_cache, nome_importacao) {
        let btn = event.currentTarget;
        let iconAntigo = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        btn.disabled = true;

        let fd = new FormData(); 
        fd.append('acao', 'reutilizar_arquivo'); 
        fd.append('arquivo_cache', arquivo_cache);

        fetch('processar_importacao_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.innerHTML = iconAntigo;
            btn.disabled = false;
            
            if(data.sucesso) {
                document.getElementById('nome_importacao').value = nome_importacao + " (REAPROVEITADO)";
                document.getElementById('arquivo_cache_nome').value = data.arquivo_cache;
                
                cabecalhosCSV = data.cabecalhos; 
                amostraCSV = data.amostra; 
                
                let trHead = '<tr>';
                data.cabecalhos.forEach(cab => { trHead += `<th>${cab}</th>`; });
                trHead += '</tr>';
                document.getElementById('amostra_head').innerHTML = trHead;

                let htmlBody = '';
                data.amostra.forEach(linha => {
                    htmlBody += '<tr class="border-bottom border-dark">';
                    linha.forEach(col => { htmlBody += `<td class="px-2 py-2">${col}</td>`; });
                    htmlBody += '</tr>';
                });
                document.getElementById('amostra_body').innerHTML = htmlBody;

                mudarEtapa('etapa2');
            } else {
                crmToast("❌ " + data.erro, "error", 6000);
            }
        })
        .catch(e => {
            btn.innerHTML = iconAntigo;
            btn.disabled = false;
            crmToast("Erro de conexão ao tentar reutilizar o arquivo.", "warning", 5000);
        });
    }
</script>

</div> <?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>