<?php
ini_set('display_errors', 0);
ob_start();
session_start();

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (file_exists($caminho_conexao)) {
    include $caminho_conexao;
} else {
    die("Erro: Arquivo de conexão não encontrado.");
}

// =======================================================
// FUNÇÃO AUXILIAR: DISPARO W-API INTERNO DO LOGIN
// =======================================================
function dispararWhatsLogin($pdo, $telefone, $mensagem) {
    $stmt = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1");
    $inst = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$inst) return ['success' => false, 'message' => 'Nenhuma instância W-API configurada no banco.'];

    $telefone_formatado = preg_replace('/[^0-9a-zA-Z-]/', '', $telefone);
    if ($telefone === '120363406245292046' || strpos($telefone, '@g.us') !== false) {
        $telefone_formatado = str_replace('@g.us', '', $telefone) . '@g.us';
    } else {
        if (!str_starts_with($telefone_formatado, '55')) {
            $telefone_formatado = '55' . $telefone_formatado;
        }
    }

    $url = "https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID'];
    $payload = json_encode(['phone' => $telefone_formatado, 'message' => $mensagem, 'delayMessage' => 1]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $inst['TOKEN']
    ]);
    
    $res = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($res, true);
}

// Validador Matemático de CPF (Segurança contra dados falsos)
function validarCpfMatematico($cpf) {
    $cpf = preg_replace('/[^0-9]/is', '', $cpf);
    if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) $d += $cpf[$c] * (($t + 1) - $c);
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

// =======================================================
// REQUISIÇÕES AJAX (MODAIS)
// =======================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_action'])) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['ajax_action'];

    // --- VERIFICAÇÃO DE DUPLICIDADE (chamado em tempo real pelo frontend) ---
    if ($action == 'verificar_campo') {
        $campo = $_POST['campo'] ?? '';
        $valor = trim($_POST['valor'] ?? '');
        $existe = false; $detalhe = '';

        if ($campo === 'cpf') {
            $v = str_pad(preg_replace('/\D/','',$valor), 11, '0', STR_PAD_LEFT);
            $s = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_CADASTRO WHERE CPF = ?");
            $s->execute([$v]);
            $existe = $s->fetchColumn() > 0;
            $detalhe = 'CPF já possui cadastro.';
        } elseif ($campo === 'celular') {
            $v = preg_replace('/\D/','',$valor);
            $s = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_USUARIO WHERE CELULAR = ?");
            $s->execute([$v]);
            $existe = $s->fetchColumn() > 0;
            $detalhe = 'Celular já possui cadastro.';
        } elseif ($campo === 'email') {
            $v = strtolower($valor);
            $s = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_USUARIO WHERE EMAIL = ?");
            $s->execute([$v]);
            $existe = $s->fetchColumn() > 0;
            $detalhe = 'E-mail já possui cadastro.';
        } elseif ($campo === 'cnpj') {
            $v = preg_replace('/\D/','',$valor);
            $s = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_EMPRESAS WHERE CNPJ = ?");
            $s->execute([$v]);
            $existe = $s->fetchColumn() > 0;
            $detalhe = 'CNPJ já possui cadastro.';
        }
        echo json_encode(['existe' => $existe, 'detalhe' => $detalhe]);
        exit;
    }

    // --- FLUXO DE NOVO CADASTRO ---
    if ($action == 'novo_cadastro') {
        // Segurança Anti-Spam: Limita 1 cadastro a cada 2 minutos por sessão
        if (isset($_SESSION['last_cadastro']) && (time() - $_SESSION['last_cadastro']) < 120) {
            echo json_encode(['success' => false, 'message' => 'Você está fazendo isso muito rápido. Aguarde 2 minutos.']);
            exit;
        }

        $cpf_puro = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $nome = mb_strtoupper(trim($_POST['nome'] ?? ''), 'UTF-8');
        $celular_puro = preg_replace('/\D/', '', $_POST['celular'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $tem_empresa = !empty($_POST['tem_empresa']) && $_POST['tem_empresa'] == '1';
        $nome_empresa = mb_strtoupper(trim($_POST['nome_empresa'] ?? ''), 'UTF-8');
        $cnpj_puro = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');

        // Validação de CPF
        if (!validarCpfMatematico($cpf_puro)) {
            echo json_encode(['success' => false, 'message' => 'CPF inválido. Verifique a numeração digitada.']); exit;
        }
        $cpf_padrao = str_pad($cpf_puro, 11, '0', STR_PAD_LEFT);

        // Validação de E-mail
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Formato de E-mail inválido.']); exit;
        }

        // Validação de Celular e DDD
        if (strlen($celular_puro) < 10 || strlen($celular_puro) > 11) {
            echo json_encode(['success' => false, 'message' => 'O celular precisa ter o DDD + 8 ou 9 dígitos.']); exit;
        }
        $ddd = (int)substr($celular_puro, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            echo json_encode(['success' => false, 'message' => 'O DDD do celular é inválido para o Brasil.']); exit;
        }

        // Validações de empresa (quando ativado)
        if ($tem_empresa) {
            if (empty($nome_empresa)) {
                echo json_encode(['success' => false, 'message' => 'Informe o nome da empresa.']); exit;
            }
            if (strlen($cnpj_puro) !== 14) {
                echo json_encode(['success' => false, 'message' => 'CNPJ inválido. Informe os 14 dígitos.']); exit;
            }
            // Validação matemática do CNPJ
            $cnpjArr = array_map('intval', str_split($cnpj_puro));
            if (count(array_unique($cnpjArr)) === 1) {
                echo json_encode(['success' => false, 'message' => 'CNPJ inválido (sequência repetida).']); exit;
            }
            $pesos1 = [5,4,3,2,9,8,7,6,5,4,3,2];
            $pesos2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];
            $soma1 = 0; for($i=0;$i<12;$i++) $soma1 += $cnpjArr[$i]*$pesos1[$i];
            $d1 = $soma1 % 11 < 2 ? 0 : 11 - ($soma1 % 11);
            $soma2 = 0; for($i=0;$i<13;$i++) $soma2 += $cnpjArr[$i]*$pesos2[$i];
            $d2 = $soma2 % 11 < 2 ? 0 : 11 - ($soma2 % 11);
            if ($cnpjArr[12] !== $d1 || $cnpjArr[13] !== $d2) {
                echo json_encode(['success' => false, 'message' => 'CNPJ inválido. Verifique os dígitos informados.']); exit;
            }

            // Verifica se CNPJ já existe
            $stmtCnpj = $pdo->prepare("SELECT NOME_CADASTRO FROM CLIENTE_EMPRESAS WHERE CNPJ = ? LIMIT 1");
            $stmtCnpj->execute([$cnpj_puro]);
            if ($empresaExist = $stmtCnpj->fetch(PDO::FETCH_ASSOC)) {
                // Monta nome-usuário no formato EMPRESA.CLIENTE para o aviso
                $primeiroNomeEmpresa  = strtok($nome_empresa, ' ');
                $primeiroNomeCliente  = strtok($nome, ' ');
                $usuario_aviso = strtoupper($primeiroNomeEmpresa . '.' . $primeiroNomeCliente);
                $msgSuporte = "⚠️ *CNPJ JÁ CADASTRADO — SOLICITAÇÃO DE ACESSO*\n\n"
                    . "👤 *Nome:* $nome\n"
                    . "📱 *Celular:* $celular_puro\n"
                    . "🏢 *Empresa informada:* $nome_empresa\n"
                    . "🔢 *CNPJ:* " . preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $cnpj_puro) . "\n"
                    . "👤 *Usuário sugerido:* $usuario_aviso\n"
                    . "⏰ " . date('d/m/Y H:i:s');
                dispararWhatsLogin($pdo, '120363406245292046', $msgSuporte);
                echo json_encode(['success' => false, 'message' => 'Este CNPJ já possui cadastro no sistema. Nossa equipe de suporte foi notificada e entrará em contato com você em breve.']); exit;
            }
        }

        // Verifica se CPF já existe no Banco
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_CADASTRO WHERE CPF = ?");
        $stmtCheck->execute([$cpf_padrao]);
        if ($stmtCheck->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'Este CPF já possui cadastro! Faça login ou recupere sua senha.']); exit;
        }

        // Empresa é obrigatória para criar usuário
        if (!$tem_empresa) {
            echo json_encode(['success' => false, 'message' => 'Para criar seu usuário de acesso, é obrigatório informar os dados da empresa (CNPJ).']); exit;
        }

        // Monta nome de usuário: PRIMEIRANOMEEMPRESA.PRIMEIRONOME (ex: ASSESSORIA.JOAO)
        $primeiroNomeEmpresa = preg_replace('/[^A-Z0-9]/', '', strtoupper(strtok($nome_empresa, ' ')));
        $primeiroNomeCliente = preg_replace('/[^A-Z0-9]/', '', strtoupper(strtok($nome, ' ')));
        $usuario_login = $primeiroNomeEmpresa . '.' . $primeiroNomeCliente;
        // Garante unicidade adicionando sufixo numérico se necessário (ex: ASSESSORIA.JOAO2)
        $baseUsuario = $usuario_login; $sufixo = 2;
        while (true) {
            $stmtU = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_USUARIO WHERE USUARIO = ?");
            $stmtU->execute([$usuario_login]);
            if ($stmtU->fetchColumn() == 0) break;
            $usuario_login = $baseUsuario . $sufixo++;
        }

        // Monta nome com privacidade: ALEX*******LEAL
        $partesNome = preg_split('/\s+/', $nome);
        if (count($partesNome) >= 2) {
            $nomePrivado = $partesNome[0] . '*******' . end($partesNome);
        } else {
            $nomePrivado = substr($nome, 0, 1) . str_repeat('*', max(3, mb_strlen($nome, 'UTF-8') - 2)) . substr($nome, -1);
        }

        // Monta CPF com privacidade: 065.***.***-90
        $cpfPrivado = substr($cpf_padrao, 0, 3) . '.***.***-' . substr($cpf_padrao, -2);

        try {
            $pdo->beginTransaction();

            // Insere Cadastro do Cliente
            $stmt1 = $pdo->prepare("INSERT INTO CLIENTE_CADASTRO (CPF, NOME, CELULAR, SITUACAO, SALDO, CUSTO_CONSULTA) VALUES (?, ?, ?, 'ATIVO', 0.00, 0.50)");
            $stmt1->execute([$cpf_padrao, $nome, $celular_puro]);

            // Insere Credencial de Usuário como CONSULTOR
            $senha_hash = password_hash($cpf_padrao, PASSWORD_DEFAULT);
            $stmt2 = $pdo->prepare("INSERT INTO CLIENTE_USUARIO (CPF, NOME, USUARIO, SENHA, CELULAR, EMAIL, GRUPO_USUARIOS, Situação, Tentativas) VALUES (?, ?, ?, ?, ?, ?, 'CONSULTOR', 'ativo', 0)");
            $stmt2->execute([$cpf_padrao, $nome, $usuario_login, $senha_hash, $celular_puro, $email]);

            // Insere empresa e vincula ao usuário e cadastro do cliente
            $pdo->prepare("INSERT INTO CLIENTE_EMPRESAS (CNPJ, NOME_CADASTRO, CELULAR) VALUES (?, ?, ?)")
                ->execute([$cnpj_puro, $nome_empresa, $celular_puro]);
            $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CNPJ = ? WHERE CPF = ?")
                ->execute([$cnpj_puro, $cpf_padrao]);

            $pdo->commit();
            $_SESSION['last_cadastro'] = time();

            // Disparo W-API ao próprio usuário com dados mascarados por privacidade
            $msgWhats = "🎉 *Cadastro Realizado com Sucesso!*\n\n"
                . "Olá!\n"
                . "Seu acesso ao portal *Assessoria Consignado* foi criado com sucesso.\n\n"
                . "👤 *Nome:* {$nomePrivado}\n"
                . "🔢 *CPF:* {$cpfPrivado}\n"
                . "🏢 *Empresa:* {$nome_empresa}\n\n"
                . "👤 *Usuário de Acesso:* {$usuario_login}\n\n"
                . "🔒 *Atenção:* Por questões de segurança, não enviamos senhas.\n"
                . "Acesse o site, clique em \"Esqueceu a senha?\" e crie sua senha de acesso.\n\n"
                . "🌐 Acesse: https://" . $_SERVER['HTTP_HOST'];
            dispararWhatsLogin($pdo, $celular_puro, $msgWhats);

            echo json_encode(['success' => true, 'message' => 'Cadastro realizado com sucesso! As instruções de acesso foram enviadas no seu WhatsApp.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Erro interno de banco de dados ao salvar.']);
        }
        exit;
    }

    // --- FLUXO BUSCAR TELEFONE (REDEFINIR SENHA) ---
    if ($action == 'buscar_telefone') {
        $identificador = trim($_POST['identificador']);
        $cpf_limpo = preg_replace('/[^0-9]/', '', $identificador);
        $cpf_busca = 'INVALIDO';
        if (strlen($cpf_limpo) > 0 && strlen($cpf_limpo) <= 11) { $cpf_busca = str_pad($cpf_limpo, 11, '0', STR_PAD_LEFT); }

        $stmt = $pdo->prepare("SELECT CPF, CELULAR, NOME FROM CLIENTE_USUARIO WHERE USUARIO = :usuario OR CPF = :cpf LIMIT 1");
        $stmt->execute(['usuario' => $identificador, 'cpf' => $cpf_busca]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && !empty($user['CELULAR'])) {
            $cel = preg_replace('/\D/', '', $user['CELULAR']);
            $mascara = substr($cel, 0, 3) . str_repeat('*', max(0, strlen($cel) - 5)) . substr($cel, -2);
            $_SESSION['reset_temp_cpf']  = $user['CPF'];
            $_SESSION['reset_temp_cel']  = $cel;
            $_SESSION['reset_temp_nome'] = $user['NOME'] ?? '';         
            echo json_encode(['success' => true, 'mascara' => $mascara]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Usuário não encontrado ou sem celular cadastrado.']);
        }
        exit;
    }

    // --- FLUXO ENVIAR LINK W-API (REDEFINIR SENHA) ---
    if ($action == 'enviar_link') {
        if (!isset($_SESSION['reset_temp_cpf'])) {
            echo json_encode(['success' => false, 'message' => 'Sessão expirada, tente novamente.']);
            exit;
        }

        $cpf     = $_SESSION['reset_temp_cpf'];
        $celular = $_SESSION['reset_temp_cel'];
        $nome    = $_SESSION['reset_temp_nome'] ?? '';
        $token = bin2hex(random_bytes(32));

        $stmt = $pdo->prepare("UPDATE CLIENTE_USUARIO SET RESET_TOKEN = :token, RESET_EXPIRA = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE CPF = :cpf");
        $stmt->execute(['token' => $token, 'cpf' => $cpf]);

        $protocolo = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $link = $protocolo . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . "?token=" . $token;

        $saudacao = $nome ? "Olá, *$nome*! 👋\n\n" : '';
        $msg = "🔒 *Recuperação de Senha*\n\n{$saudacao}Você solicitou a redefinição da sua senha no portal Assessoria Consignado.\n\nClique no link abaixo para criar uma nova senha (válido por 1 hora):\n$link\n\n_Se não foi você, apenas ignore esta mensagem._";
        dispararWhatsLogin($pdo, $celular, $msg);

        unset($_SESSION['reset_temp_cpf'], $_SESSION['reset_temp_cel'], $_SESSION['reset_temp_nome']);
        echo json_encode(['success' => true, 'message' => 'Link enviado para o WhatsApp!']);
        exit;
    }

    // --- FLUXO FALAR COM SUPORTE ---
    if ($action == 'suporte') {
        $nome = trim($_POST['nome']); $telefone = trim($_POST['telefone']);
        $telefone_limpo = preg_replace('/[^0-9a-zA-Z-]/', '', $telefone);
        if (strlen($telefone_limpo) < 10 && !preg_match('/[a-zA-Z]/', $telefone_limpo)) {
             echo json_encode(['success' => false, 'message' => 'Número/ID inválido.']); exit;
        }
        $msg = "🆘 *SOLICITAÇÃO DE SUPORTE (LOGIN)*\n\n👤 *Nome:* $nome\n📱 *Contato/ID:* $telefone\n⏰ *Data:* " . date('d/m/Y H:i:s');
        dispararWhatsLogin($pdo, '120363406245292046', $msg);
        echo json_encode(['success' => true, 'message' => 'Solicitação enviada. Nossa equipe entrará em contato!']); exit;
    }
}

// Bloqueio de sessão ativa
if (isset($_SESSION['usuario_cpf']) && !isset($_GET['token'])) { header("Location: index.php"); exit; }

$erro = ''; $sucesso = '';
if (isset($_GET['aviso']) && $_GET['aviso'] == 'expirado') { $erro = "Sua sessão expirou por inatividade. Faça login novamente."; }

// ==========================================
// TELA DE NOVA SENHA
// ==========================================
$renderizar_nova_senha = false;
$token_get = $_GET['token'] ?? null;

if ($token_get) {
    $stmt = $pdo->prepare("SELECT CPF, NOME, CELULAR FROM CLIENTE_USUARIO WHERE RESET_TOKEN = :token AND RESET_EXPIRA > NOW() LIMIT 1");
    $stmt->execute(['token' => $token_get]);
    $user_reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_reset) {
        $renderizar_nova_senha = true;
        if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['nova_senha_submit'])) {
            $senha1 = $_POST['senha1']; $senha2 = $_POST['senha2'];
            if ($senha1 !== $senha2) { $erro = "As senhas não conferem. Tente novamente."; }
            elseif (strlen($senha1) < 4) { $erro = "A senha deve ter pelo menos 4 caracteres."; }
            else {
                $novo_hash = password_hash($senha1, PASSWORD_DEFAULT);
                $stmtUpdate = $pdo->prepare("UPDATE CLIENTE_USUARIO SET SENHA = :hash, RESET_TOKEN = NULL, RESET_EXPIRA = NULL, Tentativas = 0, Ultima_Tentativa = NULL WHERE CPF = :cpf");
                $stmtUpdate->execute(['hash' => $novo_hash, 'cpf' => $user_reset['CPF']]);
                // Notifica o usuário via WhatsApp
                $nome_reset  = $user_reset['NOME'] ?? '';
                $cel_reset   = preg_replace('/\D/', '', $user_reset['CELULAR'] ?? '');
                if ($cel_reset) {
                    $saud = $nome_reset ? "Olá, *$nome_reset*! 👋\n\n" : '';
                    $msg_conf = "✅ *Senha Atualizada*\n\n{$saud}Sua senha de acesso ao portal Assessoria Consignado foi redefinida com sucesso.\n\nSe não foi você, entre em contato com o suporte imediatamente.";
                    dispararWhatsLogin($pdo, $cel_reset, $msg_conf);
                }
                $sucesso = "Sua senha foi atualizada com sucesso! Você já pode fazer login.";
                $renderizar_nova_senha = false;
            }
        }
    } else {
        $erro = "Link de recuperação inválido ou expirado. Solicite um novo.";
    }
}

// ==========================================
// LÓGICA DE VALIDAÇÃO DE LOGIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_submit']) && !$renderizar_nova_senha) {
    $nome_usuario = trim($_POST['usuario']); 
    $senha = $_POST['senha'];

    $stmt = $pdo->prepare("SELECT * FROM CLIENTE_USUARIO WHERE USUARIO = :usuario LIMIT 1");
    $stmt->execute(['usuario' => $nome_usuario]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $cpf = $user['CPF'];
        $tentativas = (int)$user['Tentativas'];
        $ultima_tentativa = $user['Ultima_Tentativa'];
        $situacao = strtolower($user['Situação']);
        $senha_banco = $user['SENHA'];

        $bloqueado = false;
        if ($tentativas >= 5 && $ultima_tentativa) {
            $tempo_passado = time() - strtotime($ultima_tentativa);
            if ($tempo_passado < 86400) { 
                $bloqueado = true;
                $erro = "Sua conta está bloqueada por excesso de tentativas. Tente novamente em 24 horas ou contate o suporte.";
            } else {
                $stmtReset = $pdo->prepare("UPDATE CLIENTE_USUARIO SET Tentativas = 0, Ultima_Tentativa = NULL WHERE CPF = :cpf");
                $stmtReset->execute(['cpf' => $cpf]);
                $tentativas = 0;
            }
        }

        if (!$bloqueado) {
            if ($situacao != 'ativo') {
                $erro = "Acesso negado. Seu cadastro está: " . strtoupper($situacao) . ".";
            } else {
                $senha_correta = false;
                if (password_verify($senha, $senha_banco)) { $senha_correta = true; } 
                else if ($senha === $senha_banco) {
                    $senha_correta = true;
                    $novo_hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmtUpdateHash = $pdo->prepare("UPDATE CLIENTE_USUARIO SET SENHA = :hash WHERE CPF = :cpf");
                    $stmtUpdateHash->execute(['hash' => $novo_hash, 'cpf' => $cpf]);
                }

                if ($senha_correta) {
                    $stmtReset = $pdo->prepare("UPDATE CLIENTE_USUARIO SET Tentativas = 0, Ultima_Tentativa = NULL WHERE CPF = :cpf");
                    $stmtReset->execute(['cpf' => $cpf]);

                    $_SESSION['usuario_cpf'] = $cpf;
                    $_SESSION['usuario_nome'] = $user['NOME'];
                    $_SESSION['usuario_grupo'] = !empty($user['GRUPO_USUARIOS']) ? strtoupper(trim($user['GRUPO_USUARIOS'])) : 'SEM_GRUPO';
                    $_SESSION['ultimo_acesso'] = time(); 

                    header("Location: index.php");
                    exit;
                } else {
                    $tentativas++;
                    $stmtFail = $pdo->prepare("UPDATE CLIENTE_USUARIO SET Tentativas = :tentativas, Ultima_Tentativa = NOW() WHERE CPF = :cpf");
                    $stmtFail->execute(['tentativas' => $tentativas, 'cpf' => $cpf]);
                    
                    $tentativas_restantes = 5 - $tentativas;
                    if ($tentativas_restantes > 0) { $erro = "Senha incorreta. Você tem mais $tentativas_restantes tentativa(s)."; } 
                    else { $erro = "Sua conta foi bloqueada por 24 horas devido a 5 tentativas falhas."; }
                }
            }
        }
    } else {
        $erro = "Usuário ou senha incorretos."; 
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso - Assessoria Consignado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f4f6f9; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: 'Segoe UI', Roboto, sans-serif; }
        .login-card { width: 100%; max-width: 380px; background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); border-top: 4px solid #ff5722; padding: 40px 30px; text-align: center; }
        .login-title { color: #333; font-weight: 600; font-size: 1.3rem; margin-bottom: 2px; }
        .login-subtitle { color: #777; font-size: 0.85rem; margin-bottom: 25px; }
        .form-control { font-size: 0.95rem; padding: 10px 15px; margin-bottom: 15px; border: 1px solid #ccc; }
        .form-control:focus { border-color: #0d6efd; box-shadow: none; }
        .btn-login { background-color: #0d6efd; border: none; font-weight: 600; padding: 10px; font-size: 0.95rem; letter-spacing: 0.5px; width: 100%; }
        .btn-login:hover { background-color: #0b5ed7; }
        
        .btn-suporte { background-color: #25D366; color: white; border: none; font-weight: 600; padding: 10px; font-size: 0.95rem; width: 100%; margin-top: 15px; transition: 0.2s; }
        .btn-suporte:hover { background-color: #128C7E; color: white;}
        .btn-cadastro { background-color: #6c757d; color: white; border: none; font-weight: 600; padding: 10px; font-size: 0.95rem; width: 100%; margin-top: 10px; transition: 0.2s; }
        .btn-cadastro:hover { background-color: #5a6268; color: white;}

        .forgot-link { display: inline-block; margin-top: 15px; font-size: 0.8rem; color: #666; text-decoration: none; }
        .forgot-link:hover { color: #333; text-decoration: underline; }
    </style>
</head>
<body>

    <div class="login-card">
        <h1 class="login-title">Assessoria Consignado</h1>
        <p class="login-subtitle">Portal Integrado</p>

        <?php if ($erro): ?>
            <div class="alert alert-danger py-2 px-3 small text-start" role="alert"><?= $erro ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert alert-success py-2 px-3 small text-start" role="alert"><?= $sucesso ?></div>
        <?php endif; ?>

        <?php if ($renderizar_nova_senha): ?>
            <p class="small text-muted mb-3"><?= !empty($user_reset['NOME']) ? 'Olá, <strong>' . htmlspecialchars($user_reset['NOME']) . '</strong>!<br>' : '' ?> Defina sua nova senha de acesso.</p>
            <form action="" method="POST">
                <input type="hidden" name="nova_senha_submit" value="1">
                <input type="password" name="senha1" class="form-control" placeholder="Nova Senha" required autofocus>
                <input type="password" name="senha2" class="form-control" placeholder="Confirme a Nova Senha" required>
                <button type="submit" class="btn btn-success btn-login w-100">SALVAR NOVA SENHA</button>
            </form>
        <?php else: ?>
            <form action="login.php" method="POST">
                <input type="hidden" name="login_submit" value="1">
                <input type="text" name="usuario" class="form-control" placeholder="Usuário" required autofocus>
                <input type="password" name="senha" class="form-control" placeholder="Senha" required>
                
                <button type="submit" class="btn btn-primary btn-login">ENTRAR</button>
            </form>

            <a href="#" class="forgot-link" onclick="abrirModalReset()">Esqueceu a senha?</a>
            <hr class="my-3 text-muted">
            <button class="btn btn-suporte" onclick="abrirModalSuporte()"><i class="fab fa-whatsapp"></i> FALAR COM SUPORTE</button>
            
            <button class="btn btn-cadastro" onclick="abrirModalCadastro()"><i class="fas fa-user-plus"></i> CADASTRAR-SE</button>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="modalCadastro" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white">
                    <h6 class="modal-title fw-bold"><i class="fas fa-user-plus text-info me-2"></i> Criar Conta de Acesso</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start bg-light p-4">
                    <p class="small text-muted mb-3 border-bottom pb-2">Preencha os dados abaixo para liberar o seu painel de Consultor.</p>
                    <form id="formNovoCadastro">
                        <label class="small fw-bold mb-1">CPF (Apenas números):</label>
                        <input type="text" id="cadCpf" class="form-control border-dark" oninput="mascaraCpf(this);validarCpfCampo()" onblur="validarCpfCampo()" maxlength="14" placeholder="000.000.000-00" required>
                        <div id="cadCpfFeedback" class="small mb-2 mt-1" style="min-height:18px;"></div>

                        <label class="small fw-bold mb-1">Nome Completo:</label>
                        <input type="text" id="cadNome" class="form-control border-dark mb-3" placeholder="Digite seu nome completo" required>

                        <label class="small fw-bold mb-1">Celular / WhatsApp (Com DDD):</label>
                        <input type="text" id="cadCelular" class="form-control border-dark" oninput="mascaraCelular(this);validarCelularCampo()" onblur="validarCelularCampo()" maxlength="15" placeholder="(00) 00000-0000" required>
                        <div id="cadCelularFeedback" class="small mb-2 mt-1" style="min-height:18px;"></div>

                        <label class="small fw-bold mb-1">E-mail de Contato:</label>
                        <input type="email" id="cadEmail" class="form-control border-dark" oninput="validarEmailCampo()" onblur="validarEmailCampo()" placeholder="seu@email.com" required>
                        <div id="cadEmailFeedback" class="small mb-2 mt-1" style="min-height:18px;"></div>

                        <!-- Aviso empresa obrigatória -->
                        <div id="cadAvisoEmpresa" class="border border-danger rounded text-danger fw-bold text-center py-2 mb-3 small">
                            <i class="fas fa-exclamation-triangle me-1"></i> PARA GERAR USUÁRIO NECESSÁRIO
                        </div>

                        <!-- Toggle empresa -->
                        <div class="form-check form-switch border border-secondary rounded p-2 mb-3 bg-white d-flex align-items-center gap-2">
                            <input class="form-check-input mt-0" type="checkbox" id="cadTemEmpresa" onchange="toggleCadEmpresa(this)">
                            <label class="form-check-label small fw-bold mb-0" for="cadTemEmpresa">
                                <i class="fas fa-building text-secondary me-1"></i> Quero cadastrar minha empresa (CNPJ)
                            </label>
                        </div>

                        <!-- Campos empresa (ocultos por padrão) -->
                        <div id="boxCadEmpresa" class="d-none border border-secondary rounded p-3 mb-3 bg-white">
                            <label class="small fw-bold mb-1 text-dark">Nome da Empresa:</label>
                            <input type="text" id="cadNomeEmpresa" class="form-control border-secondary mb-3" placeholder="Razão social ou nome fantasia" maxlength="150" style="text-transform:uppercase;">

                            <label class="small fw-bold mb-1 text-dark">CNPJ:</label>
                            <input type="text" id="cadCnpj" class="form-control border-secondary" oninput="mascaraCnpj(this)" maxlength="18" placeholder="00.000.000/0000-00">
                            <div id="cadCnpjFeedback" class="small mt-1" style="min-height:18px;"></div>
                        </div>

                        <button type="submit" class="btn btn-success w-100 fw-bold border-dark shadow-sm py-2 fs-5" id="btnSalvarCadastro"><i class="fas fa-check-circle me-1"></i> FINALIZAR CADASTRO</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalReset" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h6 class="modal-title fw-bold">Recuperar Acesso</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div id="resetPasso1">
                        <label class="small fw-bold mb-1">Informe seu Usuário ou CPF:</label>
                        <input type="text" id="resetIdentificador" class="form-control mb-3" placeholder="Digite aqui...">
                        <button type="button" class="btn btn-primary w-100 fw-bold" onclick="buscarTelefone()" id="btnBuscarReset">BUSCAR CADASTRO</button>
                    </div>
                    <div id="resetPasso2" class="d-none">
                        <p class="small text-muted mb-2">Encontramos o seu cadastro! Confirme o número abaixo para receber o link de redefinição no WhatsApp:</p>
                        <div class="alert alert-secondary text-center fw-bold fs-5 py-2" id="resetCelularMascarado"></div>
                        <button type="button" class="btn btn-success w-100 fw-bold" onclick="enviarLinkWhats()" id="btnEnviarLink">ENVIAR LINK 📱</button>
                    </div>
                    <div id="resetSucesso" class="d-none text-center py-3">
                        <h4 class="text-success mb-2">✅ Enviado!</h4>
                        <p class="small text-muted mb-0">Verifique seu WhatsApp para redefinir a senha.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalSuporte" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #25D366; color: white;">
                    <h6 class="modal-title fw-bold">📱 Solicitar Suporte</h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-start">
                    <div id="suporteFormulario">
                        <label class="small fw-bold mb-1">Seu Nome:</label>
                        <input type="text" id="supNome" class="form-control mb-2" placeholder="Como podemos te chamar?">
                        <label class="small fw-bold mb-1">Celular (DDD + 9 dígitos):</label>
                        <input type="text" id="supTelefone" class="form-control mb-3" placeholder="Ex: 11999998888">
                        <button type="button" class="btn btn-success w-100 fw-bold" onclick="enviarSuporte()" id="btnSuporteSubmit">SOLICITAR ATENDIMENTO</button>
                    </div>
                    <div id="suporteSucesso" class="d-none text-center py-3">
                        <h4 class="text-success mb-2">✅ Notificado!</h4>
                        <p class="small text-muted mb-0">Nossa equipe foi acionada e entrará em contato com você em breve.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const mReset = new bootstrap.Modal(document.getElementById('modalReset'));
        const mSuporte = new bootstrap.Modal(document.getElementById('modalSuporte'));
        const mCadastro = new bootstrap.Modal(document.getElementById('modalCadastro'));

        function mascaraCpf(i){
            let v = i.value.replace(/\D/g,'');
            if(v.length > 11) v = v.substring(0,11);
            if(v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/,"$1.$2.$3-$4");
            else if(v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{3})/,"$1.$2.$3");
            else if(v.length > 3) v = v.replace(/(\d{3})(\d{3})/,"$1.$2");
            i.value = v;
        }

        function mascaraCelular(i){
            let v = i.value.replace(/\D/g,'');
            if(v.length > 11) v = v.substring(0,11);
            if(v.length > 2) v = v.replace(/^(\d{2})(\d)/g,"($1) $2");
            if(v.length > 9) v = v.replace(/(\d{5})(\d{4})$/,"$1-$2");
            i.value = v;
        }

        // Estado de duplicidade por campo
        const _cadDupl = { cpf: false, celular: false, email: false, cnpj: false };

        function _atualizarBotaoCadastro() {
            const temDupl = Object.values(_cadDupl).some(v => v);
            document.getElementById('btnSalvarCadastro').disabled = temDupl;
        }

        function _msgJaCadastrado(label) {
            return `<span class="text-danger fw-bold"><i class="fas fa-exclamation-circle"></i> ${label} já possui cadastro — `
                + `<a href="#" onclick="mCadastro.hide();abrirModalReset();return false;" class="fw-bold text-danger">Recuperar senha</a> `
                + `ou <a href="#" onclick="mCadastro.hide();abrirModalSuporte();return false;" class="fw-bold text-danger">Falar com suporte</a></span>`;
        }

        async function verificarDuplicidade(campo, valor, fbId) {
            const fb = document.getElementById(fbId);
            fb.innerHTML = '<span class="text-muted"><i class="fas fa-spinner fa-spin"></i> Verificando...</span>';
            const fd = new FormData();
            fd.append('ajax_action','verificar_campo');
            fd.append('campo', campo);
            fd.append('valor', valor);
            try {
                const res = await fetch('login.php', {method:'POST', body:fd}).then(r=>r.json());
                if(res.existe) {
                    _cadDupl[campo] = true;
                    fb.innerHTML = _msgJaCadastrado(res.detalhe.replace(' já possui cadastro.',''));
                } else {
                    _cadDupl[campo] = false;
                    fb.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> Disponível</span>';
                }
            } catch(e) { _cadDupl[campo] = false; fb.innerHTML = ''; }
            _atualizarBotaoCadastro();
        }

        function validarCpfCampo() {
            const val = document.getElementById('cadCpf').value.replace(/\D/g,'');
            const fb  = document.getElementById('cadCpfFeedback');
            if(!val) { fb.innerHTML=''; _cadDupl.cpf=false; _atualizarBotaoCadastro(); return false; }
            if(val.length < 11) { fb.innerHTML='<span class="text-warning"><i class="fas fa-circle-notch"></i> CPF incompleto</span>'; _cadDupl.cpf=false; _atualizarBotaoCadastro(); return false; }
            if(/^(\d)\1{10}$/.test(val)) { fb.innerHTML='<span class="text-danger"><i class="fas fa-times-circle"></i> CPF inválido</span>'; _cadDupl.cpf=false; _atualizarBotaoCadastro(); return false; }
            let ok = true;
            for(let t=9;t<11;t++){
                let d=0; for(let c=0;c<t;c++) d+=parseInt(val[c])*((t+1)-c);
                d=((10*d)%11)%10; if(parseInt(val[t])!==d){ok=false;break;}
            }
            if(!ok){ fb.innerHTML='<span class="text-danger"><i class="fas fa-times-circle"></i> CPF inválido</span>'; _cadDupl.cpf=false; _atualizarBotaoCadastro(); return false; }
            fb.innerHTML='<span class="text-success"><i class="fas fa-check-circle"></i> CPF válido</span>';
            verificarDuplicidade('cpf', val, 'cadCpfFeedback');
            return true;
        }

        function validarCelularCampo() {
            const val = document.getElementById('cadCelular').value.replace(/\D/g,'');
            const fb  = document.getElementById('cadCelularFeedback');
            if(!val){ fb.innerHTML=''; _cadDupl.celular=false; _atualizarBotaoCadastro(); return false; }
            if(val.length < 10){ fb.innerHTML='<span class="text-warning"><i class="fas fa-circle-notch"></i> Celular incompleto (DDD + número)</span>'; _cadDupl.celular=false; _atualizarBotaoCadastro(); return false; }
            const ddd = parseInt(val.substring(0,2));
            if(ddd < 11 || ddd > 99){ fb.innerHTML='<span class="text-danger"><i class="fas fa-times-circle"></i> DDD inválido</span>'; _cadDupl.celular=false; _atualizarBotaoCadastro(); return false; }
            fb.innerHTML='<span class="text-success"><i class="fas fa-check-circle"></i> Celular válido</span>';
            verificarDuplicidade('celular', val, 'cadCelularFeedback');
            return true;
        }

        function validarEmailCampo() {
            const val = document.getElementById('cadEmail').value.trim();
            const fb  = document.getElementById('cadEmailFeedback');
            if(!val){ fb.innerHTML=''; _cadDupl.email=false; _atualizarBotaoCadastro(); return false; }
            const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);
            if(!ok){ fb.innerHTML='<span class="text-danger"><i class="fas fa-times-circle"></i> Formato de e-mail inválido</span>'; _cadDupl.email=false; _atualizarBotaoCadastro(); return false; }
            fb.innerHTML='<span class="text-success"><i class="fas fa-check-circle"></i> E-mail válido</span>';
            verificarDuplicidade('email', val, 'cadEmailFeedback');
            return true;
        }

        function mascaraCnpj(i) {
            let v = i.value.replace(/\D/g,'');
            if(v.length > 14) v = v.substring(0,14);
            if(v.length > 12) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2}).*/,'$1.$2.$3/$4-$5');
            else if(v.length > 8) v = v.replace(/^(\d{2})(\d{3})(\d{3})(\d{0,4}).*/,'$1.$2.$3/$4');
            else if(v.length > 5) v = v.replace(/^(\d{2})(\d{3})(\d{0,3}).*/,'$1.$2.$3');
            else if(v.length > 2) v = v.replace(/^(\d{2})(\d{0,3}).*/,'$1.$2');
            i.value = v;
            validarCnpjFrontend(v);
        }

        function validarCnpjFrontend(val) {
            const fb = document.getElementById('cadCnpjFeedback');
            const nums = val.replace(/\D/g,'');
            if(nums.length < 14) { fb.textContent = ''; _cadDupl.cnpj=false; _atualizarBotaoCadastro(); return false; }
            if(/^(\d)\1{13}$/.test(nums)) { fb.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> CNPJ inválido</span>'; _cadDupl.cnpj=false; _atualizarBotaoCadastro(); return false; }
            const calc = (n, p) => n.split('').slice(0,p.length).reduce((a,d,i) => a + parseInt(d)*p[i], 0);
            const p1=[5,4,3,2,9,8,7,6,5,4,3,2], p2=[6,5,4,3,2,9,8,7,6,5,4,3,2];
            const r1 = calc(nums,p1) % 11; const d1 = r1 < 2 ? 0 : 11-r1;
            const r2 = calc(nums,p2) % 11; const d2 = r2 < 2 ? 0 : 11-r2;
            const ok = parseInt(nums[12])===d1 && parseInt(nums[13])===d2;
            if(!ok){ fb.innerHTML='<span class="text-danger"><i class="fas fa-times-circle"></i> CNPJ inválido</span>'; _cadDupl.cnpj=false; _atualizarBotaoCadastro(); return false; }
            fb.innerHTML='<span class="text-success"><i class="fas fa-check-circle"></i> CNPJ válido</span>';
            verificarDuplicidade('cnpj', nums, 'cadCnpjFeedback');
            return true;
        }

        function toggleCadEmpresa(chk) {
            const box = document.getElementById('boxCadEmpresa');
            const nomeEmp = document.getElementById('cadNomeEmpresa');
            const cnpj = document.getElementById('cadCnpj');
            const aviso = document.getElementById('cadAvisoEmpresa');
            if(chk.checked) {
                box.classList.remove('d-none');
                nomeEmp.required = true; cnpj.required = true;
                if(aviso) aviso.style.display = 'none';
            } else {
                box.classList.add('d-none');
                nomeEmp.required = false; cnpj.required = false;
                nomeEmp.value = ''; cnpj.value = '';
                document.getElementById('cadCnpjFeedback').textContent = '';
                _cadDupl.cnpj = false; _atualizarBotaoCadastro();
                if(aviso) aviso.style.display = 'block';
            }
        }

        function abrirModalCadastro() {
            document.getElementById('formNovoCadastro').reset();
            document.getElementById('cadTemEmpresa').checked = false;
            toggleCadEmpresa({checked: false});
            // Limpa feedbacks e estado de duplicidade
            ['cadCpfFeedback','cadCelularFeedback','cadEmailFeedback','cadCnpjFeedback'].forEach(id => {
                const el = document.getElementById(id); if(el) el.innerHTML='';
            });
            _cadDupl.cpf=false; _cadDupl.celular=false; _cadDupl.email=false; _cadDupl.cnpj=false;
            _atualizarBotaoCadastro();
            mCadastro.show();
        }

        document.getElementById('formNovoCadastro').addEventListener('submit', function(e) {
            e.preventDefault();
            const btn = document.getElementById('btnSalvarCadastro');
            const temEmpresa = document.getElementById('cadTemEmpresa').checked;

            // Validação completa no frontend antes de enviar
            if(!validarCpfCampo())     { alert('CPF inválido. Verifique o número digitado.'); return; }
            if(!validarCelularCampo()) { alert('Celular inválido. Informe o DDD + número.'); return; }
            if(!validarEmailCampo())   { alert('Formato de e-mail inválido.'); return; }
            if(!temEmpresa) {
                alert('Para criar seu usuário de acesso, é obrigatório informar os dados da empresa (CNPJ).');
                return;
            }
            if(!document.getElementById('cadNomeEmpresa').value.trim()) {
                alert('Informe o nome da empresa.'); return;
            }
            if(!validarCnpjFrontend(document.getElementById('cadCnpj').value)) {
                alert('CNPJ inválido. Verifique o número digitado.'); return;
            }

            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Aguarde...'; btn.disabled = true;

            const fd = new FormData();
            fd.append('ajax_action', 'novo_cadastro');
            fd.append('cpf', document.getElementById('cadCpf').value);
            fd.append('nome', document.getElementById('cadNome').value);
            fd.append('celular', document.getElementById('cadCelular').value);
            fd.append('email', document.getElementById('cadEmail').value);
            fd.append('tem_empresa', temEmpresa ? '1' : '0');
            if(temEmpresa) {
                fd.append('nome_empresa', document.getElementById('cadNomeEmpresa').value);
                fd.append('cnpj', document.getElementById('cadCnpj').value);
            }

            fetch('login.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> FINALIZAR CADASTRO'; btn.disabled = false;
                if(res.success) {
                    alert(res.message);
                    mCadastro.hide();
                    this.reset();
                    toggleCadEmpresa({checked: false});
                } else {
                    alert(res.message);
                }
            }).catch(() => { btn.innerHTML = '<i class="fas fa-check-circle me-1"></i> FINALIZAR CADASTRO'; btn.disabled = false; alert('Erro de comunicação com o servidor.'); });
        });

        // Funções Originais
        function abrirModalReset() {
            document.getElementById('resetPasso1').classList.remove('d-none');
            document.getElementById('resetPasso2').classList.add('d-none');
            document.getElementById('resetSucesso').classList.add('d-none');
            document.getElementById('resetIdentificador').value = '';
            mReset.show();
        }

        function abrirModalSuporte() {
            document.getElementById('suporteFormulario').classList.remove('d-none');
            document.getElementById('suporteSucesso').classList.add('d-none');
            document.getElementById('supNome').value = '';
            document.getElementById('supTelefone').value = '';
            mSuporte.show();
        }

        function buscarTelefone() {
            const id = document.getElementById('resetIdentificador').value;
            if(!id) return alert('Digite o Usuário ou CPF.');
            const btn = document.getElementById('btnBuscarReset');
            btn.innerHTML = 'Buscando...'; btn.disabled = true;
            const fd = new FormData(); fd.append('ajax_action', 'buscar_telefone'); fd.append('identificador', id);
            fetch('login.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                btn.innerHTML = 'BUSCAR CADASTRO'; btn.disabled = false;
                if(res.success) { document.getElementById('resetCelularMascarado').innerText = res.mascara; document.getElementById('resetPasso1').classList.add('d-none'); document.getElementById('resetPasso2').classList.remove('d-none');
                } else { alert(res.message); }
            }).catch(() => { btn.innerHTML = 'BUSCAR CADASTRO'; btn.disabled = false; alert('Erro de conexão.'); });
        }

        function enviarLinkWhats() {
            const btn = document.getElementById('btnEnviarLink'); btn.innerHTML = 'Enviando...'; btn.disabled = true;
            const fd = new FormData(); fd.append('ajax_action', 'enviar_link');
            fetch('login.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                btn.innerHTML = 'ENVIAR LINK 📱'; btn.disabled = false;
                if(res.success) { document.getElementById('resetPasso2').classList.add('d-none'); document.getElementById('resetSucesso').classList.remove('d-none');
                } else { alert(res.message); }
            }).catch(() => { btn.innerHTML = 'ENVIAR LINK 📱'; btn.disabled = false; alert('Erro ao enviar mensagem.'); });
        }

        function enviarSuporte() {
            const nome = document.getElementById('supNome').value; const tel = document.getElementById('supTelefone').value;
            if(!nome || !tel) return alert('Preencha os dois campos obrigatórios.');
            const btn = document.getElementById('btnSuporteSubmit'); btn.innerHTML = 'Notificando equipe...'; btn.disabled = true;
            const fd = new FormData(); fd.append('ajax_action', 'suporte'); fd.append('nome', nome); fd.append('telefone', tel);
            fetch('login.php', { method: 'POST', body: fd })
            .then(r => r.json()).then(res => {
                btn.innerHTML = 'SOLICITAR ATENDIMENTO'; btn.disabled = false;
                if(res.success) { document.getElementById('suporteFormulario').classList.add('d-none'); document.getElementById('suporteSucesso').classList.remove('d-none');
                } else { alert(res.message); }
            }).catch(() => { btn.innerHTML = 'SOLICITAR ATENDIMENTO'; btn.disabled = false; alert('Erro ao contatar o suporte.'); });
        }
    </script>
</body>
</html>