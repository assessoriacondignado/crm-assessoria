<?php
// =====================================================================
// 1. SUA CREDENCIAL DO GPT MAKER (Só precisa da Chave agora)
// =====================================================================
$GPT_API_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJncHRtYWtlciIsImlkIjoiM0YwODNDRTVBRTU5QzE0QUI4MjNBNjY1QTU2QkRERjEiLCJ0ZW5hbnQiOiIzRjA4M0NFNUFFNTlDMTRBQjgyM0E2NjVBNTZCRERGMSIsInV1aWQiOiJlMWRmNmQwNi00ZDcyLTQ3MWQtYTBjYy1mOWEzOGE1ZDZmNzMifQ.tNCfnBORdsP-GXU__dOPLgrQik0-7ZQXUGV48A8IRm4";

$resultado_html = ""; 
$lista_canais_html = "";

// =====================================================================
// AÇÃO 1: BUSCAR E LISTAR OS CANAIS AUTOMATICAMENTE
// =====================================================================
if ($GPT_API_KEY !== "COLE_SUA_CHAVE_AQUI" && !empty($GPT_API_KEY)) {
    $ch_canais = curl_init("https://api.gptmaker.ai/v2/channels");
    curl_setopt($ch_canais, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_canais, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$GPT_API_KEY}",
        "Content-Type: application/json"
    ]);
    $resp_canais = curl_exec($ch_canais);
    curl_close($ch_canais);
    
    $json_canais = json_decode($resp_canais, true);
    
    // Tenta montar uma lista bonita se a API retornar os dados
    if ($json_canais) {
        $array_canais = isset($json_canais['data']) ? $json_canais['data'] : $json_canais;
        if (is_array($array_canais)) {
            foreach ($array_canais as $canal) {
                $nome = $canal['name'] ?? 'Canal sem nome';
                $id = $canal['id'] ?? 'ID_NAO_ENCONTRADO';
                $lista_canais_html .= "<li style='margin-bottom: 5px;'>📡 <b>{$nome}</b> <br> Channel ID: <code style='background: #e0e0e0; padding: 2px 6px; border-radius: 4px; font-size: 14px; user-select: all;'>{$id}</code></li>";
            }
        } else {
            $lista_canais_html = "<li>Não foi possível listar os canais. Retorno bruto: <pre>".htmlspecialchars($resp_canais)."</pre></li>";
        }
    } else {
        $lista_canais_html = "<li style='color:red;'>Erro ao ler canais ou chave de API inválida.</li>";
    }
} else {
    $lista_canais_html = "<li>⚠️ <b>Atenção:</b> Você precisa colar sua Chave de API no arquivo PHP primeiro!</li>";
}

// =====================================================================
// AÇÃO 2: EFETUAR O DISPARO (Se o formulário for enviado)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['telefone']) && !empty($_POST['channel_id'])) {
    
    $telefone_digitado = preg_replace('/\D/', '', $_POST['telefone']);
    if (substr($telefone_digitado, 0, 2) !== '55') {
        $telefone_digitado = '55' . $telefone_digitado;
    }

    $channel_id_digitado = trim($_POST['channel_id']);
    
    $url_disparo = "https://api.gptmaker.ai/v2/channel/{$channel_id_digitado}/start-conversation";
    $mensagem_ia = "Fala chefe! 🚀 Teste de disparo ativo concluído com sucesso pela nossa Central de Testes V8!";

    $payloadGpt = json_encode([
        "phone" => $telefone_digitado,
        "message" => $mensagem_ia
    ]);

    $chGpt = curl_init($url_disparo);
    curl_setopt($chGpt, CURLOPT_POST, true);
    curl_setopt($chGpt, CURLOPT_POSTFIELDS, $payloadGpt);
    curl_setopt($chGpt, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chGpt, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer {$GPT_API_KEY}",
        "Content-Type: application/json"
    ]);

    $resposta_gpt = curl_exec($chGpt);
    $http_status_gpt = curl_getinfo($chGpt, CURLINFO_HTTP_CODE);
    $erro_curl = curl_error($chGpt);
    curl_close($chGpt);

    if ($erro_curl) {
        $resultado_html = "<div class='alert error'>❌ Erro de conexão cURL: {$erro_curl}</div>";
    } else {
        $json_resp = json_decode($resposta_gpt, true);
        $resposta_formatada = $json_resp ? print_r($json_resp, true) : htmlspecialchars($resposta_gpt);

        if ($http_status_gpt == 200 || $http_status_gpt == 201 || $http_status_gpt == 204) {
            $resultado_html = "<div class='alert success'>✅ <b>Sucesso!</b> Disparo enviado para {$telefone_digitado}. Olhe o WhatsApp!</div>";
        } else {
            $resultado_html = "<div class='alert error'>❌ <b>Falha no disparo.</b> Status HTTP: {$http_status_gpt}</div>";
        }
        $resultado_html .= "<b>Retorno da API do GPT Maker:</b><br><pre>{$resposta_formatada}</pre>";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central de Disparo - IA V8</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f7f6; display: flex; justify-content: center; align-items: flex-start; height: 100vh; margin: 0; padding-top: 40px; }
        .container { background-color: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); width: 100%; max-width: 600px; }
        h2, h3 { color: #333; text-align: center; margin-top: 0; }
        label { font-weight: bold; color: #555; display: block; margin-bottom: 8px; margin-top: 15px; }
        input[type="text"] { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 5px; box-sizing: border-box; font-size: 16px; margin-bottom: 5px; }
        button { width: 100%; background-color: #007bff; color: white; padding: 12px; border: none; border-radius: 5px; font-size: 16px; cursor: pointer; font-weight: bold; transition: background-color 0.3s; margin-top: 20px;}
        button:hover { background-color: #0056b3; }
        .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
        .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .box-canais { background-color: #e9ecef; border: 1px solid #ced4da; padding: 15px; border-radius: 5px; margin-bottom: 20px;}
        .box-canais ul { list-style-type: none; padding-left: 0; margin: 0; }
        pre { background-color: #eee; padding: 10px; border-radius: 5px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>

<div class="container">
    <h2>🚀 Central de Disparo Ativo</h2>
    
    <div class="box-canais">
        <h3 style="font-size: 16px; margin-bottom: 10px;">📋 Seus Canais Encontrados (Copie o ID)</h3>
        <ul>
            <?= $lista_canais_html ?>
        </ul>
    </div>

    <?= $resultado_html ?>

    <form method="POST" action="">
        <label for="channel_id">Cole o Channel ID aqui:</label>
        <input type="text" id="channel_id" name="channel_id" placeholder="Ex: 5f4e3d2c1b..." required value="<?= isset($_POST['channel_id']) ? htmlspecialchars($_POST['channel_id']) : '' ?>">

        <label for="telefone">Número de Telefone (com DDD):</label>
        <input type="text" id="telefone" name="telefone" placeholder="Ex: 82999025155" required value="<?= isset($_POST['telefone']) ? htmlspecialchars($_POST['telefone']) : '' ?>">
        
        <button type="submit">Enviar Mensagem de Teste</button>
    </form>
</div>

</body>
</html>