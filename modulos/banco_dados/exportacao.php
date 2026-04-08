<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php'; ?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-primary fw-bold"><i class="fas fa-file-export me-2"></i> Exportação de Dados</h2>
        <p class="text-muted">Extraia os dados do sistema em formato CSV padronizado.</p>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-dark shadow-lg rounded-3 mb-5 overflow-hidden">
            <div class="card-header bg-dark text-white py-3 border-bottom border-dark text-center">
                <h5 class="fw-bold mb-0 text-uppercase" style="letter-spacing: 1px;"><i class="fas fa-filter text-info me-2"></i> Selecione o Modelo de Exportação</h5>
            </div>
            <div class="card-body p-5 bg-light text-center">
                
                <form action="processar_exportacao.php" method="POST" target="_blank" id="formExportacao">
                    <div class="mb-4">
                        <select name="modelo_exportacao" id="modelo_exportacao" class="form-select form-select-lg border-dark shadow-sm mx-auto fw-bold text-dark" style="max-width: 500px;" required>
                            <option value="">-- Escolha um modelo --</option>
                            <option value="dados_cadastrais">Dados Cadastrais (Completo, 1 Linha por Cliente)</option>
                            <option value="telefones_foco">Lista de Telefones (1 Linha por Celular, repete CPF)</option>
                            <option value="emails_foco">Lista de E-mails (1 Linha por E-mail, repete CPF)</option>
                        </select>
                    </div>

                    <div id="info_dados_cadastrais" class="alert alert-secondary border-dark text-start mx-auto mb-4 shadow-sm info-box" style="max-width: 500px; display: none;">
                        <strong class="text-dark"><i class="fas fa-info-circle text-primary me-1"></i> O que vem neste arquivo?</strong>
                        <ul class="mb-0 mt-2 small text-dark fw-bold">
                            <li>Dados principais do cliente (Nome, CPF, Sexo, RG, etc)</li>
                            <li>1 Endereço completo por cliente</li>
                            <li>Até 15 colunas de Telefones na mesma linha</li>
                            <li>Até 5 colunas de E-mails na mesma linha</li>
                        </ul>
                    </div>

                    <div id="info_telefones_foco" class="alert alert-success border-dark text-start mx-auto mb-4 shadow-sm info-box" style="max-width: 500px; display: none;">
                        <strong class="text-dark"><i class="fas fa-phone-alt text-success me-1"></i> Foco em Celulares (Disparos)</strong>
                        <ul class="mb-0 mt-2 small text-dark fw-bold">
                            <li>A primeira coluna será o <b>Telefone</b>.</li>
                            <li>Colunas seguintes: CPF, Nome, Nascimento e Idade.</li>
                            <li>Se a pessoa tiver 3 telefones, ela aparecerá em 3 linhas diferentes.</li>
                        </ul>
                    </div>

                    <div id="info_emails_foco" class="alert alert-warning border-dark text-start mx-auto mb-4 shadow-sm info-box" style="max-width: 500px; display: none;">
                        <strong class="text-dark"><i class="fas fa-envelope text-warning me-1"></i> Foco em E-mails (Disparos)</strong>
                        <ul class="mb-0 mt-2 small text-dark fw-bold">
                            <li>A primeira coluna será o <b>E-mail</b>.</li>
                            <li>Colunas seguintes: CPF, Nome, Nascimento e Idade.</li>
                            <li>Se a pessoa tiver 2 e-mails, ela aparecerá em 2 linhas diferentes.</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-dark btn-lg fw-bold px-5 shadow-sm border-dark" id="btn_exportar" disabled>
                        <i class="fas fa-download text-info me-2"></i> Baixar Arquivo CSV
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('modelo_exportacao').addEventListener('change', function() {
        const btnExportar = document.getElementById('btn_exportar');
        
        // Esconde todos os painéis de informação primeiro
        document.querySelectorAll('.info-box').forEach(el => el.style.display = 'none');

        // Mostra o painel correto com base na escolha
        if (this.value !== '') {
            document.getElementById('info_' + this.value).style.display = 'block';
            btnExportar.disabled = false;
        } else {
            btnExportar.disabled = true;
        }
    });

    document.getElementById('formExportacao').addEventListener('submit', function() {
        const btn = document.getElementById('btn_exportar');
        const textOriginal = btn.innerHTML;
        
        btn.innerHTML = '<i class="fas fa-spinner fa-spin text-info me-2"></i> Gerando Arquivo...';
        
        setTimeout(() => {
            btn.innerHTML = textOriginal;
        }, 3000);
    });
</script>

</div> <?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>