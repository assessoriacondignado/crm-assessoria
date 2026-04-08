<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);

// Ajuste o caminho da conexão conforme a estrutura da sua pasta
require_once '../../../conexao.php';

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($acao) {
            
            // =======================================================
            // 1. SALVAR NOVA ANOTAÇÃO GERAL
            // =======================================================
            case 'salvar_anotacao':
                $assunto = trim($_POST['assunto'] ?? '');
                $anotacao = trim($_POST['anotacao'] ?? '');

                if (!empty($assunto) && !empty($anotacao)) {
                    $stmt = $pdo->prepare("INSERT INTO CONFIG_ANOTACOES_GERAIS (ASSUNTO, ANOTACAO, DATA_ATUALIZACAO) VALUES (?, ?, NOW())");
                    $stmt->execute([$assunto, $anotacao]);
                    header("Location: index.php?msg=sucesso");
                    exit;
                } else {
                    die("Erro: Preencha todos os campos obrigatórios (Assunto e Anotação).");
                }
                break;

            // =======================================================
            // 2. SALVAR NOVO ACESSO (SENHAS E SISTEMAS)
            // =======================================================
            case 'salvar_acesso':
                $tipo = trim($_POST['tipo'] ?? 'SISTEMAS');
                $origem = trim($_POST['origem'] ?? '');
                $usuario = trim($_POST['usuario'] ?? '');
                $senha = trim($_POST['senha'] ?? '');
                $uso = trim($_POST['uso'] ?? '');
                $observacao = trim($_POST['observacao'] ?? '');

                if (!empty($origem)) {
                    $stmt = $pdo->prepare("INSERT INTO CONFIG_ACESSOS (TIPO, ORIGEM, USUARIO, SENHA, USO, OBSERVACAO, DATA_ATUALIZACAO) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$tipo, $origem, $usuario, $senha, $uso, $observacao]);
                    header("Location: index.php?msg=sucesso");
                    exit;
                } else {
                    die("Erro: O campo Origem (Nome do Banco/Sistema) é obrigatório.");
                }
                break;

            default:
                die("Ação não reconhecida pelo sistema.");
        }
    } catch (Exception $e) {
        die("Erro no banco de dados: " . $e->getMessage());
    }
}

// Se tentar acessar o arquivo direto pela URL, volta pro index
header("Location: index.php");
exit;
?>