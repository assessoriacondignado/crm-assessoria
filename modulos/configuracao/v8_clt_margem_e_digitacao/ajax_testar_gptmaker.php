<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['usuario_cpf'])) { echo json_encode(['ok'=>false,'log'=>'Nao autenticado.']); exit; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;
$pdo->exec("SET NAMES utf8mb4");

$config_id = (int)($_POST['config_id'] ?? 0);
$telefone  = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
$mensagem  = trim($_POST['mensagem'] ?? '[TESTE] Diagnostico do sistema.');

if (!$telefone) { echo json_encode(['ok'=>false,'log'=>'Informe o telefone.']); exit; }

$stG = $pdo->prepare("SELECT API_TOKEN, AGENT_ID, NOME_USUARIO FROM INTEGRACAO_GPTMAKE_CONFIG WHERE ID = ? LIMIT 1");
$stG->execute([$config_id]);
$gpt = $stG->fetch(PDO::FETCH_ASSOC);
if (!$gpt) { echo json_encode(['ok'=>false,'log'=>'Config nao encontrada.']); exit; }

$log = [];
$log[] = "=== DIAGNOSTICO DE ENVIO GPTMAKER ===";
$log[] = "Telefone recebido    : " . $telefone . " (" . strlen($telefone) . " dig)";

$phone = $telefone;

// Unico passo: adiciona 55 se nao tiver (nao altera 9 digito)
if (strlen($phone) < 12) {
    $antes = $phone;
    $phone = '55' . $phone;
    $log[] = "Passo 1 (add 55)     : " . $antes . " -> " . $phone . " (" . strlen($phone) . " dig) [APLICADO]";
} else {
    $log[] = "Passo 1 (add 55)     : nao aplicado - ja tem " . strlen($phone) . " dig com prefixo 55";
}

$log[] = "Numero FINAL enviado : " . $phone;
$log[] = "Agent ID             : " . $gpt['AGENT_ID'];
$log[] = "Usuario              : " . $gpt['NOME_USUARIO'];
$log[] = "---";
$log[] = "Mensagem             : " . $mensagem;
$log[] = "---";

// Envio real para GPTMaker
$payload = json_encode(['phone' => $phone, 'message' => $mensagem], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$log[] = "Body (JSON enviado)  :";
$log[] = $payload;
$log[] = "---";
$payload = json_encode(['phone' => $phone, 'message' => $mensagem], JSON_UNESCAPED_UNICODE);
$ch = curl_init("https://api.gptmaker.ai/v2/agent/{$gpt['AGENT_ID']}/conversation");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $gpt['API_TOKEN'],
        'Content-Type: application/json'
    ]
]);
$res  = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$log[] = "URL                  : https://api.gptmaker.ai/v2/agent/" . $gpt['AGENT_ID'] . "/conversation";
$log[] = "Method               : POST";
$log[] = "Header Authorization : Bearer " . substr($gpt['API_TOKEN'], 0, 20) . "...";
$log[] = "Header Content-Type  : application/json";
$log[] = "---";
$log[] = "HTTP Status          : " . $http;
$log[] = "Resposta GPTMaker    :";
$res_pretty = json_encode(json_decode($res, true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$log[] = ($res_pretty && $res_pretty !== 'null') ? $res_pretty : $res;
$ok = ($http >= 200 && $http < 300);
$log[] = $ok ? "** ENVIADO COM SUCESSO **" : "** FALHA NO ENVIO **";

echo json_encode(['ok' => $ok, 'log' => implode("\n", $log)]);
