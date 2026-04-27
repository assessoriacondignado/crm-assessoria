<?php
session_start();
header('Content-Type: application/json');
if (!isset($_SESSION['usuario_cpf'])) { echo json_encode(['ok'=>false,'msg'=>'Nao autenticado.']); exit; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;
$pdo->exec("SET NAMES utf8mb4");

$acao = $_POST['acao'] ?? '';

if ($acao === 'gerar_senha') {
    $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
    if (strlen($cpf) !== 11) { echo json_encode(['ok'=>false,'msg'=>'CPF inválido.']); exit; }

    // Verifica se usuário existe
    $st = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $st->execute([$cpf]);
    if (!$st->fetchColumn()) { echo json_encode(['ok'=>false,'msg'=>'Usuário não encontrado.']); exit; }

    // Gera senha aleatória: 8 chars com letras e números
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $nova_senha = '';
    for ($i = 0; $i < 8; $i++) {
        $nova_senha .= $chars[random_int(0, strlen($chars) - 1)];
    }

    $pdo->prepare("UPDATE CLIENTE_USUARIO SET SENHA = ? WHERE CPF = ?")
        ->execute([$nova_senha, $cpf]);

    echo json_encode(['ok' => true, 'senha' => $nova_senha]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
