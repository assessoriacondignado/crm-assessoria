<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

// Inclui o motor de permissões
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

$acao = $_POST['acao'] ?? '';

// Trava de Segurança: Verifica a permissão MENU_INICIO_VER_ONLINE
$pode_ver_online = false;
if (function_exists('verificaPermissao') && isset($pdo)) {
    try {
        $pode_ver_online = verificaPermissao($pdo, 'MENU_INICIO_VER_ONLINE', 'TELA');
    } catch (\Throwable $e) {}
}

if (!$pode_ver_online) {
    echo json_encode(['sucesso' => false, 'erro' => 'Acesso negado pelas permissões do sistema.']);
    exit;
}

if ($acao === 'listar_online') {
    // Busca utilizadores ativos nos últimos 10 minutos
    $sql = "
        SELECT u.CPF, u.NOME, u.GRUPO_USUARIOS, u.CELULAR, e.NOME_CADASTRO as EMPRESA
        FROM CLIENTE_USUARIO u
        LEFT JOIN CLIENTE_EMPRESAS e ON u.id_empresa = e.ID
        WHERE u.ULTIMO_ACESSO >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ORDER BY u.NOME ASC
    ";
    
    $stmt = $pdo->query($sql);
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['sucesso' => true, 'usuarios' => $usuarios]);
    exit;
}

if ($acao === 'forcar_logout') {
    $cpf_alvo = $_POST['cpf_alvo'] ?? '';
    if (!empty($cpf_alvo)) {
        $stmt = $pdo->prepare("UPDATE CLIENTE_USUARIO SET FORCAR_LOGOUT = 1 WHERE CPF = ?");
        $stmt->execute([$cpf_alvo]);
        echo json_encode(['sucesso' => true]);
    } else {
        echo json_encode(['sucesso' => false]);
    }
    exit;
}

if ($acao === 'forcar_logout_todos') {
    $cpf_logado = $_SESSION['usuario_cpf'] ?? '';
    // Marca todos como forçar logout, exceto o próprio usuário logado
    $stmt = $pdo->prepare("UPDATE CLIENTE_USUARIO SET FORCAR_LOGOUT = 1 WHERE Situação = 'online' AND CPF != ?");
    $stmt->execute([$cpf_logado]);
    echo json_encode(['sucesso' => true, 'afetados' => $stmt->rowCount()]);
    exit;
}
?>