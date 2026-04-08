<?php
// Inicia a sessão para poder destruí-la
session_start();

// Limpa todas as variáveis da sessão
$_SESSION = array();

// Se é preciso destruir a sessão completamente, apaga também o cookie de sessão.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destrói a sessão no servidor
session_destroy();

// Redireciona o usuário de volta para a tela de login
header("Location: login.php");
exit;
?>