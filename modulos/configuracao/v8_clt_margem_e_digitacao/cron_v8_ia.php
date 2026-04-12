<?php
// Arquivo: cron_v8_ia.php
// Script para rodar via Cron Job a cada 30 minutos
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; }
$pdo->exec("SET NAMES utf8mb4");

// ⚠️ CONFIGURAÇÕES DO GPT MAKER PARA O DISPARO ATIVO
// Substitua pelos seus dados reais do GPT Maker
$GPT_API_KEY = "SUA_CHAVE_DE_API_GPTMAKER_AQUI"; 
$GPT_CHANNEL_ID = "SEU_CHANNEL_ID_DO_WHATSAPP_AQUI"; 
$GPT_URL_DISPARO = "https://api.gptmaker.ai/v2/channels/{$GPT_CHANNEL_ID}/messages";

// Funções de apoio (você deve incluir/copiar as funções 'gerarTokenV8_Local' e 'processarSimulacaoPadrao' do seu api_ia_v8.php aqui ou dar require)
require_once 'api_ia_v8.php'; // Se as funções estiverem acessíveis

$ST_OK   = ['SUCCESS','COMPLETED','WAITING_CREDIT_ANALYSIS','APPROVED','PRE_APPROVED','SIMULATED','READY','AVAILABLE','MARGIN_AVAILABLE','AUTHORIZED','DONE','FINISHED'];
$ST_ERRO = ['ERROR','REJECTED','DENIED','CANCELED','EXPIRED','FAILED'];

// Busca todas as sessões que estão aguardando
$stmt = $pdo->prepare("
    SELECT s.ID as SESSAO_ID, s.CPF_CLIENTE, s.TELEFONE_CLIENTE, s.CONSULT_ID, s.DATA_INICIO, 
           cred.USERNAME_API, cred.PASSWORD_API, cred.CLIENT_ID, cred.AUDIENCE, cred.TABELA_PADRAO, cred.PRAZO_PADRAO, cred.ID as CRED_ID, cred.CPF_DONO
    FROM INTEGRACAO_V8_IA_SESSAO s
    JOIN INTEGRACAO_V8_IA_CREDENCIAIS cred ON s.TOKEN_IA_USADO = cred.TOKEN_IA
    WHERE s.STATUS_SESSAO = 'AGUARDANDO_DATAPREV'
");
$stmt->execute();
$sessoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($sessoes as $sessao) {
    $elapsed = time() - strtotime($sessao['DATA_INICIO']);

    // Se passou de 4 horas (14400 segundos), cancela silenciosamente
    if ($elapsed >= 14400) {
        $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'TIMEOUT_DATAPREV' WHERE ID = ?")->execute([$sessao['SESSAO_ID']]);
        $pdo->prepare("UPDATE INTEGRACAO_V8_REGISTROCONSULTA SET STATUS_V8 = 'TIMEOUT_DATAPREV', ULTIMA_ATUALIZACAO = NOW() WHERE CONSULT_ID = ?")->execute([$sessao['CONSULT_ID']]);
        continue; // Vai para o próximo cliente da fila
    }

    // Consulta V8
    try {
        $tokenV8 = gerarTokenV8_Local($sessao); // Requer a credencial
        $chC = curl_init("https://bff.v8sistema.com/private-consignment/consult/{$sessao['CONSULT_ID']}");
        curl_setopt($chC, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chC, CURLOPT_HTTPGET, true);
        curl_setopt($chC, CURLOPT_HTTPHEADER, ["Authorization: Bearer $tokenV8"]);
        $resC = curl_exec($chC); curl_close($chC);
        $jsonC = json_decode($resC, true);
        $st = strtoupper($jsonC['status'] ?? '');

        if (in_array($st, $ST_OK)) {
            // APROVOU! Processa a simulação
            $margem = $jsonC['availableMargin'] ?? $jsonC['marginBaseValue'] ?? 0;
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'MARGEM_LIBERADA' WHERE ID = ?")->execute([$sessao['SESSAO_ID']]);
            
            $simDados = processarSimulacaoPadrao($sessao['CONSULT_ID'], $sessao['CPF_CLIENTE'], (float)$margem, $pdo, $sessao, $tokenV8);
            
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'SIMULACAO_PRONTA' WHERE ID = ?")->execute([$sessao['SESSAO_ID']]);

            // ==========================================
            // DISPARO ATIVO - GPT MAKER (Mensagem pro Cliente)
            // ==========================================
            $mensagem_ia = "Boas notícias! 🎉 Acabou de chegar a sua simulação da Dataprev. \n\nConseguimos liberar o valor de *R$ " . number_format($simDados['valor_liberado'], 2, ',', '.') . "* em *" . $simDados['prazo'] . " parcelas de R$ " . number_format($simDados['valor_parcela'], 2, ',', '.') . "*. \n\nEsse valor fica bom para você?";
            
            $payloadGpt = json_encode([
                "telefone" => "55" . $sessao['TELEFONE_CLIENTE'],
                "mensagem" => $mensagem_ia
            ]);

            $chGpt = curl_init($GPT_URL_DISPARO);
            curl_setopt($chGpt, CURLOPT_POST, true);
            curl_setopt($chGpt, CURLOPT_POSTFIELDS, $payloadGpt);
            curl_setopt($chGpt, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chGpt, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $GPT_API_KEY",
                "Content-Type: application/json"
            ]);
            curl_exec($chGpt);
            curl_close($chGpt);

        } elseif (in_array($st, $ST_ERRO)) {
            // Reprovou na Dataprev - Encerra a sessão em silêncio ou pode disparar mensagem de erro também.
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_SESSAO SET STATUS_SESSAO = 'ERRO_MARGEM' WHERE ID = ?")->execute([$sessao['SESSAO_ID']]);
        }

    } catch (Exception $e) {
        // Falha de comunicação, tenta na próxima rodada do cron
    }
}
?>