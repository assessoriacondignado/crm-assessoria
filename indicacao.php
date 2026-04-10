<?php
ini_set('display_errors', 0);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
require_once $caminho_conexao;
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$token = trim($_GET['ref'] ?? '');
$vendedor = null;
$erro = '';
$sucesso = '';

if (empty($token)) {
    $erro = 'Link de indicação inválido ou ausente.';
} else {
    $stmt = $pdo->prepare("SELECT ID, NOME FROM FINANCEIRO_VENDEDORES WHERE LINK_TOKEN = ? AND STATUS = 'ATIVO' LIMIT 1");
    $stmt->execute([$token]);
    $vendedor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vendedor) {
        $erro = 'Link de indicação não reconhecido ou expirado.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $vendedor) {
    $cpf_raw   = preg_replace('/\D/', '', trim($_POST['cpf'] ?? ''));
    $nome      = strtoupper(trim($_POST['nome'] ?? ''));
    $celular   = preg_replace('/\D/', '', trim($_POST['celular'] ?? ''));
    $nome_emp  = strtoupper(trim($_POST['nome_empresa'] ?? ''));

    if (strlen($cpf_raw) !== 11) {
        $erro = 'CPF inválido. Informe os 11 dígitos.';
    } elseif (empty($nome)) {
        $erro = 'Informe seu nome completo.';
    } elseif (empty($celular)) {
        $erro = 'Informe seu celular.';
    } else {
        // verifica se CPF já existe
        $chk = $pdo->prepare("SELECT CPF, VENDEDOR_REF_ID FROM CLIENTE_CADASTRO WHERE CPF = ?");
        $chk->execute([$cpf_raw]);
        $existente = $chk->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            // já cadastrado — apenas vincula ao vendedor se ainda não tiver vínculo
            if (empty($existente['VENDEDOR_REF_ID'])) {
                $pdo->prepare("UPDATE CLIENTE_CADASTRO SET VENDEDOR_REF_ID = ? WHERE CPF = ?")
                    ->execute([$vendedor['ID'], $cpf_raw]);
                $sucesso = "Bem-vindo de volta! Sua conta foi vinculada ao(à) consultor(a) <strong>" . htmlspecialchars($vendedor['NOME']) . "</strong>.";
            } else {
                $sucesso = "Você já possui cadastro em nosso sistema. Entre em contato com seu consultor.";
            }
        } else {
            // novo cadastro
            $pdo->prepare("INSERT INTO CLIENTE_CADASTRO (CPF, NOME, CELULAR, NOME_EMPRESA, VENDEDOR_REF_ID, SITUACAO) VALUES (?, ?, ?, ?, ?, 'ATIVO')")
                ->execute([$cpf_raw, $nome, $celular, $nome_emp, $vendedor['ID']]);
            $sucesso = "Cadastro realizado com sucesso! Você foi indicado(a) por <strong>" . htmlspecialchars($vendedor['NOME']) . "</strong>.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro por Indicação</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card-indicacao { border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); max-width: 480px; width: 100%; }
        .header-indicacao { background: linear-gradient(135deg, #f4511e, #e53935); border-radius: 16px 16px 0 0; padding: 32px 24px 24px; text-align: center; }
        .vendedor-badge { background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); border-radius: 50px; display: inline-block; padding: 6px 20px; font-size: 0.85rem; margin-bottom: 8px; }
        input.form-control { border-radius: 8px; }
        .btn-cadastrar { background: linear-gradient(135deg, #f4511e, #e53935); border: none; border-radius: 8px; font-weight: 700; font-size: 1rem; padding: 12px; letter-spacing: 0.5px; }
        .btn-cadastrar:hover { opacity: 0.9; }
    </style>
</head>
<body>
<div class="card-indicacao bg-white">
    <div class="header-indicacao text-white">
        <div class="vendedor-badge"><i class="fas fa-user-tie me-1"></i> Indicação por Consultor</div>
        <?php if ($vendedor): ?>
        <h3 class="fw-bold mb-1 mt-2"><?= htmlspecialchars($vendedor['NOME']) ?></h3>
        <p class="mb-0 opacity-75">convidou você para fazer parte da nossa base.</p>
        <?php else: ?>
        <h3 class="fw-bold mb-1 mt-2">Cadastro por Indicação</h3>
        <?php endif; ?>
    </div>

    <div class="p-4">
        <?php if ($erro): ?>
        <div class="alert alert-danger border-danger fw-bold shadow-sm">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
        <div class="alert alert-success border-success fw-bold shadow-sm text-center">
            <i class="fas fa-check-circle me-2 fs-4"></i><br>
            <?= $sucesso ?>
        </div>
        <p class="text-center text-muted small mt-3">Você pode fechar esta página.</p>
        <?php elseif ($vendedor): ?>
        <p class="text-muted small mb-4 text-center">Preencha seus dados para se cadastrar. É rápido!</p>

        <form method="POST" id="fCadastro">
            <div class="mb-3">
                <label class="fw-bold small">CPF <span class="text-danger">*</span></label>
                <input type="text" name="cpf" id="inpCpf" class="form-control border-dark fw-bold" placeholder="000.000.000-00" maxlength="14" required autocomplete="off">
            </div>
            <div class="mb-3">
                <label class="fw-bold small">Nome Completo <span class="text-danger">*</span></label>
                <input type="text" name="nome" class="form-control border-dark text-uppercase fw-bold" placeholder="Seu nome completo" required>
            </div>
            <div class="mb-3">
                <label class="fw-bold small">Celular / WhatsApp <span class="text-danger">*</span></label>
                <input type="text" name="celular" id="inpCel" class="form-control border-dark fw-bold" placeholder="(00) 00000-0000" maxlength="15" required>
            </div>
            <div class="mb-4">
                <label class="fw-bold small">Empresa (opcional)</label>
                <input type="text" name="nome_empresa" class="form-control border-dark text-uppercase" placeholder="Nome da empresa, se houver">
            </div>
            <button type="submit" class="btn btn-cadastrar text-white w-100">
                <i class="fas fa-user-plus me-2"></i> Cadastrar Agora
            </button>
        </form>
        <?php else: ?>
        <p class="text-center text-muted">Verifique o link recebido e tente novamente.</p>
        <?php endif; ?>
    </div>
</div>

<script>
// Máscara CPF
document.getElementById('inpCpf')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0,11);
    if(v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2}).*/, '$1.$2.$3-$4');
    else if(v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{1,3}).*/, '$1.$2.$3');
    else if(v.length > 3) v = v.replace(/^(\d{3})(\d{1,3}).*/, '$1.$2');
    this.value = v;
});
// Máscara Celular
document.getElementById('inpCel')?.addEventListener('input', function() {
    let v = this.value.replace(/\D/g,'').substring(0,11);
    if(v.length > 6) v = v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
    else if(v.length > 2) v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
    this.value = v;
});
</script>
</body>
</html>
