<?php
// Evita erro caso o arquivo seja incluído mais de uma vez na mesma tela
if (!function_exists('verificaPermissao')) {
    
    function verificaPermissao($pdo, $chave, $tipo = 'FUNCAO') {
        try {
            if (!$pdo) return true; 

            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
            
            // Pega o grupo salvo na sessão. Se não tiver, assume que é ADMIN temporariamente
            $grupo_usuario = $_SESSION['usuario_grupo'] ?? 'ADMIN'; 

            // ✨ SE FOR ADMIN, ADMINISTRADOR OU MASTER, IGNORA AS REGRAS E LIBERA TUDO ✨
            if (in_array(strtoupper($grupo_usuario), ['MASTER', 'ADMIN', 'ADMINISTRADOR'])) {
                return true;
            }

            // Usa cache de permissões carregado em header.php (evita 1 SELECT por chamada)
            if (isset($_SESSION['perm_cache'])) {
                if (array_key_exists($chave, $_SESSION['perm_cache'])) {
                    $grupo_usuarios = $_SESSION['perm_cache'][$chave];
                } else {
                    // Chave adicionada após cache ser carregado: consulta DB e adiciona ao cache
                    $stmt = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = ?");
                    $stmt->execute([$chave]);
                    $reg = $stmt->fetch(PDO::FETCH_ASSOC);
                    $grupo_usuarios = $reg ? ($reg['GRUPO_USUARIOS'] ?? '') : '';
                    $_SESSION['perm_cache'][$chave] = $grupo_usuarios;
                }
            } else {
                $stmt = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = ?");
                $stmt->execute([$chave]);
                $reg = $stmt->fetch(PDO::FETCH_ASSOC);
                $grupo_usuarios = $reg ? ($reg['GRUPO_USUARIOS'] ?? '') : '';
            }

            // Se tem grupos bloqueados nela
            if (!empty($grupo_usuarios)) {
                // Transforma a string "VENDEDORES, SUPERVISORES" em um array
                $grupos_bloqueados = array_map('trim', explode(',', strtoupper($grupo_usuarios)));

                // Se o grupo do usuário estiver na lista de bloqueados, retorna false (Acesso Negado)
                if (in_array(strtoupper($grupo_usuario), $grupos_bloqueados)) {
                    return false;
                }
            }

            return true; // Se a chave não existir ou o grupo não estiver bloqueado, libera o acesso

        } catch (\Throwable $e) {
            // BLINDAGEM: Em caso de erro no banco, libera o acesso para não travar o sistema
            return true; 
        }
    }
}

// Versão estrita: não bypassa MASTER/ADMIN — aplica a todos os grupos igualmente
if (!function_exists('verificaPermissaoEstrita')) {
    function verificaPermissaoEstrita($pdo, $chave) {
        try {
            if (!$pdo) return false;
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) session_start();
            $grupo_usuario = strtoupper($_SESSION['usuario_grupo'] ?? '');

            if (isset($_SESSION['perm_cache']) && array_key_exists($chave, $_SESSION['perm_cache'])) {
                $grupo_usuarios = $_SESSION['perm_cache'][$chave];
            } else {
                $stmt = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = ?");
                $stmt->execute([$chave]);
                $reg = $stmt->fetch(PDO::FETCH_ASSOC);
                $grupo_usuarios = $reg ? ($reg['GRUPO_USUARIOS'] ?? '') : '';
                $_SESSION['perm_cache'][$chave] = $grupo_usuarios;
            }

            if (!empty($grupo_usuarios)) {
                $bloqueados = array_map('trim', explode(',', strtoupper($grupo_usuarios)));
                if (in_array($grupo_usuario, $bloqueados)) return false;
            }
            return true;
        } catch (\Throwable $e) { return true; }
    }
}
?>