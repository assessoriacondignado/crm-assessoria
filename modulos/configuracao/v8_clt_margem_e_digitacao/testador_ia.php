<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Simulador de API (Teste M2M - IA)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; padding: 20px; }
        .card { box-shadow: 0 4px 8px rgba(0,0,0,0.1); border-top: 4px solid #0d6efd; }
        .json-response { background-color: #212529; color: #20c997; padding: 15px; border-radius: 5px; font-family: monospace; white-space: pre-wrap; min-height: 100px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card p-4 mb-4">
        <h4 class="mb-4">🤖 Simulador de API (Teste M2M - IA)</h4>
        
        <div class="mb-3">
            <label class="form-label fw-bold">1. Cole o Token Gerado no seu CRM (Bearer):</label>
            <input type="text" id="token" class="form-control text-primary fw-bold" placeholder="Ex: V8IA_f2b902e7a..." value="">
        </div>

        <div class="mb-3">
            <label class="form-label fw-bold">2. Escolha a Ação do Funil:</label>
            <select id="acao" class="form-select border-primary" onchange="ajustarCampos()">
                <option value="consulta_completa">⚡ 0. consulta_completa (Fluxo Completo até Simulação)</option>
                <option value="simular_proposta">🧮 1. simular_proposta (Refazer Simulação Personalizada)</option>
                <option value="enviar_proposta">✍️ 2. enviar_proposta (Gerar Link de Assinatura)</option>
                <option value="ver_status_proposta">🔎 3. ver_status_proposta (Consultar andamento da V8)</option>
                <option value="resolver_pendencia_pix">🏦 4. resolver_pendencia_pix (Corrigir conta bancária)</option>
                <option value="cancelar_proposta">❌ 5. cancelar_proposta (Alertar Equipe)</option>
            </select>
        </div>

        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label fw-bold">CPF do Cliente:</label>
                <input type="text" id="cpf" class="form-control text-primary fw-bold" placeholder="Somente números">
            </div>
            <div class="col-md-4" id="div_telefone">
                <label class="form-label fw-bold">Telefone Cliente (Com DDD):</label>
                <input type="text" id="telefone" class="form-control" placeholder="Ex: 11999999999">
            </div>
            <div class="col-md-4" id="div_pix" style="display: none;">
                <label class="form-label fw-bold text-success">Chave PIX (CPF):</label>
                <input type="text" id="pix" class="form-control border-success" placeholder="Chave do cliente">
            </div>
        </div>

        <div class="row mb-3" id="div_personalizada" style="display: none;">
            <div class="col-md-4">
                <label class="form-label fw-bold text-warning">Prazo (Meses):</label>
                <input type="number" id="prazo" class="form-control" placeholder="Ex: 84">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold text-warning">Valor da Parcela (R$):</label>
                <input type="number" step="0.01" id="valor_parcela" class="form-control" placeholder="Ex: 150.00">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold text-warning">Valor Líquido (R$):</label>
                <input type="number" step="0.01" id="valor_liquido" class="form-control" placeholder="Ex: 5000.00">
            </div>
        </div>

        <div id="alerta_espera" class="alert alert-warning" style="display: block;">
            <strong>⏱️ Atenção:</strong> A "consulta_completa" pode demorar de 1 a 4 minutos aguardando a Dataprev. Não atualize a página!
        </div>

        <button class="btn btn-success w-100 fw-bold fs-5" onclick="dispararAPI()" id="btn_disparar">
            <span id="btn_text">🚀 Disparar Requisição (Como IA)</span>
        </button>
    </div>

    <h5 class="fw-bold">Resposta da API (JSON):</h5>
    <div class="json-response" id="resposta_json">Aguardando disparo...</div>
</div>

<script>
    function ajustarCampos() {
        const acao = document.getElementById('acao').value;
        const divTelefone = document.getElementById('div_telefone');
        const divPix = document.getElementById('div_pix');
        const divPersonalizada = document.getElementById('div_personalizada');
        const alertaEspera = document.getElementById('alerta_espera');

        // Esconde tudo primeiro
        divTelefone.style.display = 'none';
        divPix.style.display = 'none';
        divPersonalizada.style.display = 'none';
        alertaEspera.style.display = 'none';

        // Mostra dependendo da ação
        if(acao === 'consulta_completa') {
            divTelefone.style.display = 'block';
            alertaEspera.style.display = 'block';
        } else if(acao === 'simular_proposta') {
            divPersonalizada.style.display = 'flex';
        } else if(acao === 'enviar_proposta') {
            divTelefone.style.display = 'block';
            divPix.style.display = 'block';
            divPersonalizada.style.display = 'flex';
        } else if(acao === 'resolver_pendencia_pix') {
            divPix.style.display = 'block';
        }
    }

    async function dispararAPI() {
        const token = document.getElementById('token').value.trim();
        const acao = document.getElementById('acao').value;
        const cpf = document.getElementById('cpf').value.trim();
        const telefone = document.getElementById('telefone').value.trim();
        const pix = document.getElementById('pix').value.trim();
        const prazo = document.getElementById('prazo').value.trim();
        const valor_parcela = document.getElementById('valor_parcela').value.trim();
        const valor_liquido = document.getElementById('valor_liquido').value.trim();

        if (!token || !cpf) {
            alert('Token e CPF são obrigatórios!'); return;
        }

        const payload = { acao: acao, cpf: cpf };
        if (telefone) payload.telefone = telefone;
        if (pix) payload.pix = pix;
        if (prazo) payload.prazo = parseInt(prazo);
        if (valor_parcela) payload.valor_parcela = parseFloat(valor_parcela);
        if (valor_liquido) payload.valor_liquido = parseFloat(valor_liquido);

        const btn = document.getElementById('btn_disparar');
        const btnText = document.getElementById('btn_text');
        const respostaBox = document.getElementById('resposta_json');

        btn.disabled = true;
        btn.classList.replace('btn-success', 'btn-secondary');
        btnText.innerHTML = '⏳ Processando... Aguarde...';
        respostaBox.innerHTML = 'Conectando com o servidor...';

        try {
            // ATENÇÃO: Verifique se o caminho da sua API está correto aqui!
            const response = await fetch('api_ia_v8.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer ' + token
                },
                body: JSON.stringify(payload)
            });

            const data = await response.json();
            respostaBox.innerHTML = JSON.stringify(data, null, 4);

        } catch (error) {
            respostaBox.innerHTML = "ERRO DE CONEXÃO: " + error;
        } finally {
            btn.disabled = false;
            btn.classList.replace('btn-secondary', 'btn-success');
            btnText.innerHTML = '🚀 Disparar Requisição (Como IA)';
        }
    }

    // Inicia a tela com os campos corretos
    ajustarCampos();
</script>
</body>
</html>