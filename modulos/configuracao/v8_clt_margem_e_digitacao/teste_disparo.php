<?php
// =====================================================================
// 1. SUAS CREDENCIAIS DO GPT MAKER (Preencha aqui)
// =====================================================================
$GPT_API_KEY = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJncHRtYWtlciIsImlkIjoiM0YwODNDRTVBRTU5QzE0QUI4MjNBNjY1QTU2QkRERjEiLCJ0ZW5hbnQiOiIzRjA4M0NFNUFFNTlDMTRBQjgyM0E2NjVBNTZCRERGMSIsInV1aWQiOiJlMWRmNmQwNi00ZDcyLTQ3MWQtYTBjYy1mOWEzOGE1ZDZmNzMifQ.tNCfnBORdsP-GXU__dOPLgrQik0-7ZQXUGV48A8IRm4"; 
$GPT_CHANNEL_ID = "EAANUEeg7qHwBRGqpDWv8DsTUjckqERARcvv0S3wZAtANc0YnWyun956cOV4uiZCZCReMqMNx8FH4r4r8kZCpJjWBeApWLZB2ZBwtskQp5Uh2FHZCs7hsR8KZBytfyx6UAVJW6w7cEYijj0jimuSogMTs68VWZBO2hyyiaxD8rNYKHMfsp2rZCZA7ERGaJUMozZCBBJZAqSxaLU58VQ4eA9CuqkxkkWdps6x5yTpnsqzZA6E3eCaDLodfwnCztZAsAqbGrvHZCxZCNzypOF5Hot8LDH7kkHOpZAl6WEM3J0Ixpj"; 

// Endpoint V2 do GPT Maker (O mesmo que você usou no seu Cron)
$url_disparo = "https://api.gptmaker.ai/v2/channels/{$GPT_CHANNEL_ID}/messages";

$resultado_html = ""; // Variável para guardar a resposta na tela

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['telefone'])) {
    
    // Limpa tudo que não for número
    $telefone_digitado = preg_replace('/\D/', '', $_POST['telefone']);
    
    // Se o número não começar com 55, adiciona automaticamente
    if (substr($telefone_digitado, 0, 2) !== '55') {
        $telefone_digitado = '55' . $telefone_digitado;
    }

    $mensagem_ia = "Fala chefe! 🚀 Teste de disparo ativo via Formulário Web concluído com sucesso. O sistema está voando!";

    $payloadGpt = json_encode([
        "telefone" => $telefone_digitado,
        "mensagem" => $mensagem_ia
    ]);

    // Executa o cURL para a API do GPT Maker
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

    // Monta o resultado para exibir na tela
    if ($erro_curl) {
        $resultado_html = "<div class='alert error'>❌ Erro de conexão cURL: {$erro_curl}</div>";
    } else {
        $json_resp = json_decode($resposta_gpt, true);
        $resposta_formatada = $json_resp ? print_r($json_resp, true) : htmlspecialchars($resposta_gpt);

        if ($http_status_gpt == 200 || $http_status_gpt == 201) {
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
    <title>Teste de Disparo - IA V8</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f7f6;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: #fff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 500px;
        }
        h2 {
            color: #333;
            text-align: center;
            margin-top: 0;
        }
        label {
            font-weight: bold;
            color: #555;
            display: block;
            margin-bottom: 8px;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 16px;
            margin-bottom: 20px;
        }
        button {
            width: 100%;
            background-color: #007bff;
            color: white;
            padding: 12px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        button:hover {
            background-color: #0056b3;
        }
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        pre {
            background-color: #eee;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            overflow-x: auto;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>🚀 Teste de Disparo Ativo</h2>
    
    <?= $resultado_html ?>

    <form method="POST" action="">
        <label for="telefone">Número de Telefone (com DDD):</label>
        <input type="text" id="telefone" name="telefone" placeholder="Ex: 82999025155" required>
        
        <button type="submit">Enviar Mensagem de Teste</button>
    </form>
</div>

</body>
</html>