<?php
/**
 * PagBank Connect — Callback OAuth 2.0
 *
 * Esta URL DEVE ser cadastrada no portal de desenvolvedores do PagBank:
 *   developers.pagbank.com.br → Sua Aplicação → URIs de Redirecionamento
 *
 * URL exata: https://crm.assessoriaconsignado.com/modulos/financeiro/pagbank_oauth_callback.php
 */
ini_set('display_errors', 0);
session_start();

define('PAGBANK_TOKEN_URL', 'https://connect.pagbank.com.br/oauth2/token');

function fecharComErro($msg) {
    $safe = addslashes(htmlspecialchars($msg, ENT_QUOTES));
    echo "<script>crmToast("Erro PagBank OAuth: {$safe}", "warning", 5000); window.close();</script>";
    exit;
}

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $code  = $_GET['code']  ?? '';
    $error = $_GET['error'] ?? '';
    $state = $_GET['state'] ?? '';

    // Proteção CSRF via state
    $expected = $_SESSION['pagbank_oauth_state'] ?? '';
    if (empty($expected) || $state !== $expected) {
        fecharComErro('State inválido. Tente reiniciar a autorização.');
    }
    unset($_SESSION['pagbank_oauth_state']);

    if ($error) {
        fecharComErro('Autorização negada: ' . ($_GET['error_description'] ?? $error));
    }
    if (empty($code)) {
        fecharComErro('Código de autorização não recebido.');
    }

    // Lê credenciais do banco
    $stmt = $pdo->query("SELECT CHAVE, VALOR FROM FINANCEIRO_CONFIG WHERE CHAVE IN ('PAGBANK_CLIENT_ID','PAGBANK_CLIENT_SECRET')");
    $cfg = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $cfg[$row['CHAVE']] = $row['VALOR']; }

    $client_id     = $cfg['PAGBANK_CLIENT_ID'] ?? '';
    $client_secret = $cfg['PAGBANK_CLIENT_SECRET'] ?? '';
    if (empty($client_id) || empty($client_secret)) {
        fecharComErro('Client ID ou Client Secret não configurados no sistema.');
    }

    $redirect_uri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST']
        . '/modulos/financeiro/pagbank_oauth_callback.php';

    // Troca o código pelo access_token
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => PAGBANK_TOKEN_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirect_uri,
            'client_id'     => $client_id,
            'client_secret' => $client_secret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 15,
    ]);
    $response = curl_exec($curl);
    $curlErr  = curl_error($curl);
    curl_close($curl);

    if ($curlErr) { fecharComErro('Falha de conexão: ' . $curlErr); }

    $data = json_decode($response, true);

    if (empty($data['access_token'])) {
        $msg = $data['error_description'] ?? ($data['message'] ?? $response);
        fecharComErro('PagBank recusou: ' . $msg);
    }

    // Salva tokens no banco
    $expires_at = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));
    $upsert = $pdo->prepare("INSERT INTO FINANCEIRO_CONFIG (CHAVE, VALOR) VALUES (?, ?) ON DUPLICATE KEY UPDATE VALOR = ?, ATUALIZADO_EM = NOW()");
    $upsert->execute(['PAGBANK_ACCESS_TOKEN',  $data['access_token'],        $data['access_token']]);
    $upsert->execute(['PAGBANK_REFRESH_TOKEN', $data['refresh_token'] ?? '', $data['refresh_token'] ?? '']);
    $upsert->execute(['PAGBANK_TOKEN_EXPIRES', $expires_at,                  $expires_at]);

    echo '<script>
        if (window.opener && typeof window.opener.pagbankOAuthSuccess === "function") {
            window.opener.pagbankOAuthSuccess();
        }
        document.write("<div style=\"font-family:sans-serif;text-align:center;padding:40px;color:#0f5132;background:#d1e7dd;border:1px solid #badbcc;border-radius:8px;margin:40px auto;max-width:400px;\"><h3>✓ Conectado com sucesso!</h3><p>Esta janela fechará automaticamente...</p></div>");
        setTimeout(() => window.close(), 2000);
    </script>';

} catch (Exception $e) {
    fecharComErro('Erro interno: ' . $e->getMessage());
}
?>
