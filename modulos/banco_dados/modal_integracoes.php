<?php
// ✨ Lógica para capturar o CPF mesmo se for um cliente novo (não cadastrado) ✨
$cpf_para_integracao = $cpf_selecionado ?? '';
if (empty($cpf_para_integracao) && isset($termo_busca)) {
    $cpf_limpo = preg_replace('/\D/', '', $termo_busca);
    if (strlen($cpf_limpo) == 11) {
        $cpf_para_integracao = $cpf_limpo;
    }
}
?>
<div class="modal fade" id="modalIntegracaoGeral" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-bottom border-dark rounded-0 py-2">
                <h5 class="modal-title fw-bold text-uppercase" style="letter-spacing: 1px;">
                    <i class="fas fa-cogs text-info me-2"></i> <span id="titulo_modal_integracao">Integração</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" id="btn_fechar_modal_top"></button>
            </div>
            <div class="modal-body bg-light p-4 text-center">

                <input type="hidden" id="integ_tipo_alvo">
                <input type="hidden" id="integ_cpf_alvo" value="<?= htmlspecialchars($cpf_para_integracao) ?>">
                <input type="hidden" id="integ_nome_alvo" value="<?= htmlspecialchars($cliente['nome'] ?? '') ?>">
                <input type="hidden" id="integ_nasc_alvo" value="<?= htmlspecialchars($cliente['nascimento'] ?? '') ?>">
                <input type="hidden" id="integ_sexo_alvo" value="<?= htmlspecialchars($cliente['sexo'] ?? '') ?>">
                <?php $telefone_principal = isset($telefones[0]) ? $telefones[0]['telefone_cel'] : ''; ?>
                <input type="hidden" id="integ_tel_alvo" value="<?= htmlspecialchars($telefone_principal) ?>">

                <input type="hidden" id="integ_user_cpf_logado" value="<?= $_SESSION['usuario_cpf'] ?? '' ?>">
                <input type="hidden" id="integ_user_id_logado" value="<?= $_SESSION['usuario_id'] ?? '' ?>">
                <input type="hidden" id="integ_user_grupo_logado" value="<?= $_SESSION['usuario_grupo'] ?? '' ?>">

                <div id="box_selecao_chave">
                    <div class="alert alert-info py-2 small fw-bold border-dark rounded-0 mb-3 shadow-sm text-center">
                        <i class="fas fa-info-circle text-primary me-1"></i> Selecione a chave que será cobrada por esta operação.
                    </div>
                    <select id="integ_chave_alvo" class="form-select border-dark shadow-sm rounded-0 fw-bold mb-3 text-center" style="font-size: 1rem;">
                        <option value="">-- Carregando chaves... --</option>
                    </select>
                    <button id="btn_executar_integ" class="btn btn-success w-100 fw-bold border-dark shadow-sm rounded-0 py-2 fs-5" onclick="executarIntegracaoUnica()">
                        <i class="fas fa-play me-2"></i> Executar Consulta Agora
                    </button>
                </div>

                <div id="box_processamento" style="display: none;">
                    <div id="loading_spinner" class="mb-3 mt-2">
                        <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div>
                    </div>
                    <h5 id="texto_status_integracao" class="fw-bold text-dark mb-2">Iniciando consulta...</h5>
                    <p id="subtexto_status" class="text-muted small mb-0">Cobrando do seu saldo. Por favor, não feche a janela.</p>
                    <button id="btn_fechar_erro" class="btn btn-dark fw-bold mt-4 px-4 rounded-0 shadow-sm" style="display: none;" data-bs-dismiss="modal">Entendi, Fechar</button>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
    let chavesV8Cache = [];
    let chavesFatorCache = [];
    let chavesHistInssCache = [];

    // =====================================================
    // FILA DE CONSULTAS V8 EM BACKGROUND
    // =====================================================
    window._v8FilaConsultas = window._v8FilaConsultas || [];

    function atualizarBadgeFila() {
        const pendentes = window._v8FilaConsultas.filter(f => f.status === 'aguardando').length;
        let badge = document.getElementById('badge-fila-v8');
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'badge-fila-v8';
            badge.style.cssText = 'position:fixed; bottom:70px; right:25px; z-index:99997;';
            document.body.appendChild(badge);
        }
        if (pendentes > 0) {
            const plural = pendentes > 1 ? 's' : '';
            badge.innerHTML = `
                <div class="shadow-lg rounded-pill px-3 py-2 d-flex align-items-center gap-2 fw-bold border border-dark"
                     style="background:#fff3cd; color:#856404; font-size:0.82rem; cursor:default; white-space:nowrap;">
                    <i class="fas fa-sync-alt fa-spin text-warning"></i>
                    ${pendentes} consulta${plural} V8 em andamento
                </div>`;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    }

    function pollarEmBackground(idFila, cpfAlvo, nomeAlvo, tentativas) {
        if (tentativas > 18) {
            window._v8FilaConsultas = window._v8FilaConsultas.filter(f => f.idFila !== idFila);
            atualizarBadgeFila();
            crmToast('⚠️ Consulta V8 de ' + nomeAlvo + ': Dataprev demorou muito. Verifique o módulo V8 ou o rodapé do cliente.', 'warning');
            return;
        }

        let pFd = new FormData();
        pFd.append('acao', 'checar_margem_e_simular');
        pFd.append('id_fila', idFila);

        fetch('/modulos/configuracao/v8_clt_margem_e_digitacao/v8_api.ajax.php', { method: 'POST', body: pFd })
            .then(pr => pr.json())
            .then(pr => {
                if (pr.status === 'concluido') {
                    window._v8FilaConsultas = window._v8FilaConsultas.filter(f => f.idFila !== idFila);
                    atualizarBadgeFila();
                    gravarLogRodape(cpfAlvo, 'Consulta e Simulação V8 Concluída.', pr.margem);
                    crmToast('✅ Consulta V8 de ' + nomeAlvo + ' concluída! Verifique o rodapé do cliente.', 'success');
                } else if (pr.status === 'erro') {
                    window._v8FilaConsultas = window._v8FilaConsultas.filter(f => f.idFila !== idFila);
                    atualizarBadgeFila();
                    crmToast('❌ Erro na consulta V8 de ' + nomeAlvo + ': ' + (pr.msg || 'Erro desconhecido'), 'error');
                } else {
                    setTimeout(() => pollarEmBackground(idFila, cpfAlvo, nomeAlvo, tentativas + 1), 10000);
                }
            })
            .catch(() => {
                setTimeout(() => pollarEmBackground(idFila, cpfAlvo, nomeAlvo, tentativas + 1), 10000);
            });
    }

    // =====================================================
    // ABERTURA DO MODAL
    // =====================================================
    function abrirModalIntegracao(tipo) {
        document.getElementById('integ_tipo_alvo').value = tipo;
        const titulo = document.getElementById('titulo_modal_integracao');
        const selChaves = document.getElementById('integ_chave_alvo');

        // Reseta as telas
        document.getElementById('box_selecao_chave').style.display = 'block';
        document.getElementById('box_processamento').style.display = 'none';
        document.getElementById('btn_fechar_modal_top').style.display = 'block';
        document.getElementById('btn_fechar_erro').style.display = 'none';
        document.getElementById('loading_spinner').style.display = 'block';
        document.getElementById('subtexto_status').style.display = 'block';
        document.getElementById('texto_status_integracao').className = 'fw-bold text-dark mb-2';

        selChaves.innerHTML = '<option value="">-- Carregando chaves... --</option>';

        if (tipo === 'V8_CLT') {
            titulo.innerHTML = 'INTEGRAÇÃO V8 CONSIGNADO';
            if (chavesV8Cache.length > 0) { popularSelectChaves(selChaves, chavesV8Cache); } else { buscarChavesV8(selChaves); }
        } else if (tipo === 'FATOR_CONFERI') {
            titulo.innerHTML = 'ATUALIZAÇÃO CADASTRAL';
            document.getElementById('box_selecao_chave').style.display = 'none';
            document.getElementById('btn_fechar_modal_top').style.display = 'none';
            document.getElementById('box_processamento').style.display = 'block';
            document.getElementById('texto_status_integracao').innerHTML = 'Atualizando cadastro no Fator Conferi...';
            document.getElementById('btn_fechar_erro').style.display = 'none';
        } else if (tipo === 'HIST_INSS') {
            titulo.innerHTML = 'CONSULTA BENEFÍCIO (HIST INSS)';
            if (chavesHistInssCache.length > 0) { popularSelectChaves(selChaves, chavesHistInssCache); } else { buscarChavesHistInss(selChaves); }
        }

        var myModal = new bootstrap.Modal(document.getElementById('modalIntegracaoGeral'));
        myModal.show();

        if (tipo === 'FATOR_CONFERI') {
            setTimeout(() => executarFatorConferiAutomatico(), 300);
        }
    }

    function popularSelectChaves(select, dados) {
        const cpfLogado = document.getElementById('integ_user_cpf_logado').value.replace(/\D/g, '');
        const idLogado = document.getElementById('integ_user_id_logado').value;
        const grupoLogado = document.getElementById('integ_user_grupo_logado').value.toUpperCase();
        const isAdmin = ['MASTER', 'ADMIN', 'ADMINISTRADOR'].includes(grupoLogado);

        let opt = '<option value="">-- Selecione a Chave/Dono --</option>';
        let achouChaveDoUsuario = false;

        dados.forEach(d => {
            let isDono = false;
            if (d.USUARIO_ID && d.USUARIO_ID == idLogado) isDono = true;
            if (d.id === cpfLogado) isDono = true;
            if (!isAdmin && !isDono) return;

            let isSelected = isDono ? 'selected' : '';
            if (isSelected) achouChaveDoUsuario = true;
            opt += `<option value="${d.id}" ${isSelected}>${d.nome} (Saldo: R$ ${d.saldo})</option>`;
        });

        select.innerHTML = opt;

        if (!isAdmin) {
            select.disabled = true;
            select.classList.add('bg-secondary', 'text-white');
            if (!achouChaveDoUsuario) {
                select.innerHTML = '<option value="">-- Sua conta não possui carteira financeira ativa --</option>';
                document.getElementById('btn_executar_integ').disabled = true;
            }
        } else {
            select.disabled = false;
            select.classList.remove('bg-secondary', 'text-white');
            document.getElementById('btn_executar_integ').disabled = false;
        }
    }

    function buscarChavesV8(select) {
        let fd = new FormData(); fd.append('acao', 'listar_chaves_acesso');
        fetch('/modulos/configuracao/v8_clt_margem_e_digitacao/v8_api.ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                if (r.success && r.data) {
                    chavesV8Cache = r.data.map(c => ({ id: c.ID, nome: c.CLIENTE_NOME, saldo: c.SALDO, USUARIO_ID: c.USUARIO_ID }));
                    popularSelectChaves(select, chavesV8Cache);
                } else { select.innerHTML = '<option value="">Erro ao carregar chaves</option>'; }
            }).catch(() => { select.innerHTML = '<option value="">Falha de comunicação.</option>'; });
    }

    function buscarChavesFator(select) {
        let fd = new FormData(); fd.append('acao', 'listar_chaves_acesso');
        fetch('/modulos/configuracao/fator_conferi/fator_conferi.ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                if (r.success && r.data) {
                    chavesFatorCache = r.data.map(c => ({ id: c.ID, nome: c.CLIENTE_NOME, saldo: c.SALDO, USUARIO_ID: c.USUARIO_ID }));
                    popularSelectChaves(select, chavesFatorCache);
                } else { select.innerHTML = '<option value="">Erro ao carregar chaves</option>'; }
            }).catch(() => { select.innerHTML = '<option value="">Falha de comunicação.</option>'; });
    }

    function buscarChavesHistInss(select) {
        let fd = new FormData(); fd.append('acao', 'listar_clientes');
        fetch('/modulos/configuracao/Promosys_inss/promosys_inss.ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                if (r.success && r.data) {
                    chavesHistInssCache = r.data.map(c => ({ id: c.CPF, nome: c.NOME, saldo: c.SALDO }));
                    popularSelectChaves(select, chavesHistInssCache);
                } else { select.innerHTML = '<option value="">Erro ao carregar clientes</option>'; }
            }).catch(() => { select.innerHTML = '<option value="">Falha de comunicação.</option>'; });
    }

    function executarFatorConferiAutomatico() {
        let cpfAlvo = document.getElementById('integ_cpf_alvo').value.replace(/\D/g, '');
        if (cpfAlvo.length !== 11) {
            const inputFormCPF = document.querySelector('input[name="cpf"]');
            if (inputFormCPF) cpfAlvo = inputFormCPF.value.replace(/\D/g, '');
        }
        if (cpfAlvo.length !== 11) return exibirErroIntegracao("CPF inválido. Digite um CPF com 11 dígitos na busca.");

        const cpfCobrar = document.getElementById('integ_user_cpf_logado').value.replace(/\D/g, '');
        if (!cpfCobrar) return exibirErroIntegracao("Sessão inválida. Faça login novamente.");

        let fd = new FormData();
        fd.append('acao', 'consulta_cpf_manual');
        fd.append('cpf', cpfAlvo);
        fd.append('cpf_cobrar', cpfCobrar);
        fd.append('fonte', 'PAINEL_WEB');

        fetch('/modulos/configuracao/fator_conferi/fator_conferi.ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                document.getElementById('loading_spinner').style.display = 'none';
                document.getElementById('subtexto_status').style.display = 'none';
                document.getElementById('btn_fechar_modal_top').style.display = 'block';
                const textoStatus = document.getElementById('texto_status_integracao');
                if (r.success) {
                    textoStatus.innerHTML = '<i class="fas fa-check-circle text-success mb-2" style="font-size: 2rem;"></i><br>Atualizado com Sucesso!';
                    textoStatus.className = 'fw-bold text-success mb-2';
                    gravarLogRodape(cpfAlvo, "Atualização Cadastral via Fator Conferi.");
                } else {
                    exibirErroIntegracao(r.msg || "Erro ao executar atualização cadastral.");
                }
            })
            .catch(() => exibirErroIntegracao("Falha de comunicação com o Fator Conferi."));
    }

    function exibirErroIntegracao(mensagem) {
        document.getElementById('loading_spinner').style.display = 'none';
        document.getElementById('subtexto_status').style.display = 'none';
        const textoStatus = document.getElementById('texto_status_integracao');
        textoStatus.innerHTML = '<i class="fas fa-exclamation-triangle text-danger mb-2" style="font-size: 2rem;"></i><br>' + mensagem;
        textoStatus.className = 'fw-bold text-danger mb-2';
        document.getElementById('btn_fechar_erro').style.display = 'inline-block';
        document.getElementById('btn_fechar_modal_top').style.display = 'block';
    }

    function gravarLogRodape(cpf, texto, margem = 0) {
        let fd = new FormData();
        fd.append('acao', 'salvar_log_rodape');
        fd.append('cpf_cliente', cpf);
        fd.append('texto_registro', texto);
        if (margem > 0) { fd.append('margem', margem); }
        fetch('consulta.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(r => {
                if (r.success) {
                    setTimeout(() => {
                        if (!window.location.href.includes('cpf_selecionado')) {
                            window.location.href = `consulta.php?busca=${cpf}&cpf_selecionado=${cpf}&acao=visualizar`;
                        } else {
                            location.reload();
                        }
                    }, 1000);
                }
            });
    }

    // =====================================================
    // EXECUÇÃO DA INTEGRAÇÃO
    // =====================================================
    function executarIntegracaoUnica() {
        const tipo     = document.getElementById('integ_tipo_alvo').value;
        const chaveId  = document.getElementById('integ_chave_alvo').value;
        const textoStatus = document.getElementById('texto_status_integracao');

        let cpfAlvo = document.getElementById('integ_cpf_alvo').value.replace(/\D/g, '');
        if (cpfAlvo.length !== 11) {
            const inputFormCPF = document.querySelector('input[name="cpf"]');
            if (inputFormCPF && inputFormCPF.value) cpfAlvo = inputFormCPF.value.replace(/\D/g, '');
        }

        if (!chaveId) return crmToast("Selecione a chave/usuário que será cobrado no campo acima.", "info", 5000);
        if (cpfAlvo.length !== 11) return crmToast("Erro: CPF inválido. Digite um CPF com 11 números na busca.", "error", 6000);

        const nome     = document.getElementById('integ_nome_alvo').value;
        const nasc     = document.getElementById('integ_nasc_alvo').value;
        const genero   = document.getElementById('integ_sexo_alvo').value.toUpperCase().startsWith('M') ? 'male' : 'female';
        const telefone = document.getElementById('integ_tel_alvo').value.replace(/\D/g, '');
        const nomeExib = nome || ('CPF ' + cpfAlvo);

        // Alterna para o modo Processamento
        document.getElementById('box_selecao_chave').style.display = 'none';
        document.getElementById('btn_fechar_modal_top').style.display = 'none';
        document.getElementById('box_processamento').style.display = 'block';

        if (tipo === 'V8_CLT') {
            if (!nome || !nasc) {
                return exibirErroIntegracao("O Nome e o Nascimento precisam estar preenchidos no cadastro para a V8.");
            }

            textoStatus.innerHTML = 'Enviando dados para a V8...';
            document.getElementById('subtexto_status').innerHTML = 'Cobrando do seu saldo. Aguarde...';
            document.getElementById('subtexto_status').style.display = 'block';

            let fd = new FormData();
            fd.append('acao', 'solicitar_consulta_cpf');
            fd.append('cpf', cpfAlvo);
            fd.append('nascimento', nasc);
            fd.append('genero', genero);
            fd.append('nome', nome);
            fd.append('telefone', telefone);
            fd.append('chave_id', chaveId);

            fetch('/modulos/configuracao/v8_clt_margem_e_digitacao/v8_api.ajax.php', { method: 'POST', body: fd })
                .then(async r => JSON.parse(await r.text()))
                .then(r => {
                    if (r.success) {
                        // Adiciona na fila global
                        window._v8FilaConsultas.push({ idFila: r.id_fila, cpf: cpfAlvo, nome: nomeExib, status: 'aguardando' });
                        atualizarBadgeFila();

                        // Mostra confirmação com countdown de 5 segundos
                        document.getElementById('loading_spinner').style.display = 'none';
                        textoStatus.innerHTML = '<i class="fas fa-check-circle text-success mb-2" style="font-size:2.5rem;"></i><br>Consulta enviada!';
                        textoStatus.className = 'fw-bold text-success mb-2';
                        document.getElementById('subtexto_status').innerHTML =
                            'A Dataprev está processando em segundo plano.<br>' +
                            'Você será avisado aqui quando concluir.<br>' +
                            '<small class="text-muted mt-1 d-block">Ou verifique o rodapé do cliente. Fechando em <strong id="countdown-integ">5</strong>s...</small>';
                        document.getElementById('subtexto_status').style.display = 'block';
                        document.getElementById('btn_fechar_modal_top').style.display = 'block';

                        // Countdown e fechamento automático
                        let seg = 5;
                        const cd = setInterval(() => {
                            seg--;
                            const el = document.getElementById('countdown-integ');
                            if (el) el.textContent = seg;
                            if (seg <= 0) {
                                clearInterval(cd);
                                const m = bootstrap.Modal.getInstance(document.getElementById('modalIntegracaoGeral'));
                                if (m) m.hide();
                                // Toast informativo (permanece até o usuário fechar)
                                crmToast('🔄 Consulta V8 de ' + nomeExib + ' em andamento. Aguarde o aviso de conclusão ou verifique o rodapé do cliente.', 'info');
                            }
                        }, 1000);

                        // Inicia polling em background após 5 segundos
                        setTimeout(() => pollarEmBackground(r.id_fila, cpfAlvo, nomeExib, 1), 5000);

                    } else {
                        exibirErroIntegracao(r.msg);
                    }
                })
                .catch(() => exibirErroIntegracao("Erro de comunicação com a V8."));

        } else if (tipo === 'FATOR_CONFERI') {
            textoStatus.innerHTML = 'Atualizando cadastro no Fator Conferi...';
            let fd = new FormData();
            fd.append('acao', 'consulta_cpf_manual');
            fd.append('cpf', cpfAlvo);
            fd.append('cpf_cobrar', chaveId);
            fd.append('fonte', 'PAINEL_WEB');
            fetch('/modulos/configuracao/fator_conferi/fator_conferi.ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (r.success) {
                        document.getElementById('loading_spinner').style.display = 'none';
                        document.getElementById('subtexto_status').style.display = 'none';
                        textoStatus.innerHTML = '<i class="fas fa-check-circle text-success mb-2" style="font-size: 2rem;"></i><br>Atualizado com Sucesso!';
                        textoStatus.className = 'fw-bold text-success mb-2';
                        gravarLogRodape(cpfAlvo, "Atualização Cadastral via Fator Conferi.");
                    } else { exibirErroIntegracao(r.msg); }
                })
                .catch(() => exibirErroIntegracao("Falha de comunicação com o Fator Conferi."));

        } else if (tipo === 'HIST_INSS') {
            textoStatus.innerHTML = 'Consultando benefício no HIST INSS...';
            let fd = new FormData();
            fd.append('acao', 'consulta_cpf_manual');
            fd.append('cpf', cpfAlvo);
            fd.append('cpf_cobrar', chaveId);
            fetch('/modulos/configuracao/Promosys_inss/promosys_inss.ajax.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(r => {
                    if (r.success) {
                        document.getElementById('loading_spinner').style.display = 'none';
                        document.getElementById('subtexto_status').style.display = 'none';
                        textoStatus.innerHTML = '<i class="fas fa-check-circle text-success mb-2" style="font-size: 2rem;"></i><br>Processado com Sucesso!';
                        textoStatus.className = 'fw-bold text-success mb-2';
                        gravarLogRodape(cpfAlvo, "Consulta de Benefício executada via HIST INSS.");
                    } else { exibirErroIntegracao(r.msg); }
                })
                .catch(() => exibirErroIntegracao("Falha de comunicação com o módulo HIST INSS."));
        }
    }
</script>
