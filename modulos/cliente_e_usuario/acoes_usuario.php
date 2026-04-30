<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) { die("Erro Crítico: Arquivo de conexão não encontrado."); }
include $caminho_conexao;

// ========================================================================
// REQUISIÇÃO AJAX: GERAR LINK DE RESET DE SENHA E ENVIAR WHATSAPP
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] == 'gerar_enviar_reset') {
    header('Content-Type: application/json');
    
    // Pega o CPF de quem vai receber o reset (pode ser o próprio usuário logado ou outro que o admin selecionou)
    $cpf_alvo = preg_replace('/[^0-9]/', '', $_POST['cpf_alvo'] ?? '');
    
    if (empty($cpf_alvo)) {
        echo json_encode(['success' => false, 'message' => 'CPF não identificado.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT CELULAR, NOME, USUARIO FROM CLIENTE_USUARIO WHERE CPF = ?");
    $stmt->execute([$cpf_alvo]);
    $row_alvo = $stmt->fetch(PDO::FETCH_ASSOC);
    $cel = $row_alvo['CELULAR'] ?? '';
    $partes_nome_reset = explode(' ', trim($row_alvo['NOME'] ?? ''));
    $nome_mask_reset = count($partes_nome_reset) > 1
        ? strtolower($partes_nome_reset[0]) . '*****' . strtolower(end($partes_nome_reset))
        : mb_substr($row_alvo['NOME'] ?? '', 0, 2) . str_repeat('*', max(3, mb_strlen($row_alvo['NOME'] ?? '') - 4)) . mb_substr($row_alvo['NOME'] ?? '', -2);
    $login_reset = $row_alvo['USUARIO'] ?? '';
    
    // Gera o token de 32 bytes e salva no banco com expiração de 1h
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE CLIENTE_USUARIO SET RESET_TOKEN = ?, RESET_EXPIRA = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE CPF = ?")
        ->execute([$token, $cpf_alvo]);

    // Monta o link final
    $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $link = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/login.php?token=" . $token;

    $wapi_success = false;
    $msg_extra = "";

    // Se a ficha tem celular, tenta disparar o whats
    if (!empty($cel)) {
        $celular = '55' . preg_replace('/\D/', '', $cel);
        $msg = "🔒 *Portal Assessoria Consignado*\n\n👤 *{$nome_mask_reset}* | Login: {$login_reset}\n\nVocê solicitou a alteração da sua senha.\nClique no link abaixo para criar uma nova (Válido por 1 hora):\n\n$link";

        $stmtInst = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1");
        $inst = $stmtInst->fetch(PDO::FETCH_ASSOC);

        if ($inst) {
            $url = "https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID'];
            $payload = json_encode(['phone' => $celular, 'message' => $msg]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url); 
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $inst['TOKEN']]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout pra não travar a tela
            curl_exec($ch); 
            curl_close($ch);
            
            $wapi_success = true;
        } else {
            $msg_extra = "(O sistema não possui conexões do WhatsApp ativas)";
        }
    } else {
        $msg_extra = "(O usuário alvo não possui celular cadastrado na ficha)";
    }
    
    $msg_whats = "🔒 *Portal Assessoria Consignado*\n\n👤 *{$nome_mask_reset}* | Login: {$login_reset}\n\nVocê solicitou a alteração da sua senha.\nClique no link abaixo para criar uma nova (Válido por 1 hora):\n\n{$link}";
    echo json_encode(['success' => true, 'link' => $link, 'wapi' => $wapi_success, 'info' => $msg_extra, 'msg_whats' => $msg_whats]);
    exit;
}

// ========================================================================
// CRIAÇÃO E ATUALIZAÇÃO (CRUD)
// ========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_crud'])) {
    
    $acao = $_POST['acao_crud'];
    $cpf_recebido = $_POST['cpf'];
    
    $is_master = in_array(strtoupper($_SESSION['usuario_grupo'] ?? ''), ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

    // BLOQUEIO DE SEGURANÇA: Ninguém pode excluir a si mesmo
    if ($acao == 'excluir' && $cpf_recebido === $_SESSION['usuario_cpf']) {
        die("<h3>Acesso Negado:</h3> Você não pode excluir a sua própria conta.");
    }
    
    // BLOQUEIO DE SEGURANÇA: Se não for master, não pode editar a si mesmo
    if ($acao == 'editar' && $cpf_recebido === $_SESSION['usuario_cpf'] && !$is_master) {
        die("<h3>Acesso Negado:</h3> Você não tem permissão para alterar seus próprios dados de acesso.");
    }

    $cpf_cru = $cpf_recebido;
    $cpf_so_numeros = preg_replace('/[^0-9]/', '', $cpf_recebido);
    $cpf_com_11_digitos = str_pad($cpf_so_numeros, 11, '0', STR_PAD_LEFT);

    try {
        if ($acao == 'editar') {
            $nome = trim($_POST['nome'] ?? '');
            $usuario = trim($_POST['usuario'] ?? '');
            
            // REMOVEMOS DEFINITIVAMENTE A LEITURA DO POST DE SENHA! A atualização agora é SÓ via link.
            
            $celular = preg_replace('/[^0-9]/', '', $_POST['celular'] ?? '');
            $grupo_whats = trim($_POST['grupo_whats'] ?? '');
            $grupo_usuarios = trim($_POST['grupo_usuarios'] ?? '');
            $situacao = trim($_POST['situacao'] ?? '');
            $data_expirar = !empty($_POST['data_expirar']) ? $_POST['data_expirar'] : null;
            $id_empresa = !empty($_POST['id_empresa']) ? intval($_POST['id_empresa']) : null;

            $sql = "UPDATE CLIENTE_USUARIO SET NOME=:nome, USUARIO=:usuario, CELULAR=:celular, GRUPO_WHATS=:grupo_whats, GRUPO_USUARIOS=:grupo_usuarios, Situação=:situacao, DATA_EXPIRAR=:data_expirar, id_empresa=:id_empresa WHERE CPF=:cpf1 OR CPF=:cpf2 OR CPF=:cpf3";
            $params = ['nome'=>$nome, 'usuario'=>$usuario, 'celular'=>$celular, 'grupo_whats'=>$grupo_whats, 'grupo_usuarios'=>$grupo_usuarios, 'situacao'=>$situacao, 'data_expirar'=>$data_expirar, 'id_empresa'=>$id_empresa, 'cpf1'=>$cpf_cru, 'cpf2'=>$cpf_so_numeros, 'cpf3'=>$cpf_com_11_digitos];
            
            $pdo->prepare($sql)->execute($params);

        } elseif ($acao == 'excluir') {
            $stmt = $pdo->prepare("DELETE FROM CLIENTE_USUARIO WHERE CPF = :cpf1 OR CPF = :cpf2 OR CPF = :cpf3");
            $stmt->execute(['cpf1'=>$cpf_cru, 'cpf2'=>$cpf_so_numeros, 'cpf3'=>$cpf_com_11_digitos]);
        }

        header("Location: cadastro_usuario.php");
        exit;

    } catch (PDOException $e) {
        die("<div style='color:red'>Erro no Banco de Dados: " . htmlspecialchars($e->getMessage()) . "</div>");
    }
} else {
    header("Location: cadastro_usuario.php");
    exit;
}
?>