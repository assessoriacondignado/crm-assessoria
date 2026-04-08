<?php
// Evita erro caso o arquivo seja incluído mais de uma vez na mesma tela
if (!function_exists('verificaPermissao')) {
    
    function verificaPermissao($pdo, $chave, $tipo = 'FUNCAO') {
        try {
            if (!$pdo) return true; 

            if (session_status() === PHP_SESSION_NONE) { 
                session_start(); 
            }
            
            // Pega o grupo salvo na sessão. Se não tiver, assume que é ADMIN temporariamente
            $grupo_usuario = $_SESSION['usuario_grupo'] ?? 'ADMIN'; 

            // ✨ SE FOR ADMIN, ADMINISTRADOR OU MASTER, IGNORA AS REGRAS E LIBERA TUDO ✨
            if (in_array(strtoupper($grupo_usuario), ['MASTER', 'ADMIN', 'ADMINISTRADOR'])) {
                return true;
            }

            // Busca a regra pela CHAVE (Única)
            $stmt = $pdo->prepare("SELECT GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = ?");
            $stmt->execute([$chave]);
            $reg = $stmt->fetch(PDO::FETCH_ASSOC);

            // Se achou a regra e tem grupos bloqueados nela
            if ($reg && !empty($reg['GRUPO_USUARIOS'])) {
                // Transforma a string "VENDEDORES, SUPERVISORES" em um array
                $grupos_bloqueados = array_map('trim', explode(',', strtoupper($reg['GRUPO_USUARIOS'])));
                
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
?>