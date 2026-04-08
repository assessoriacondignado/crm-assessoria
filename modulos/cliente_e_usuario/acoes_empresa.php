<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) {
    die("Erro Crítico: Arquivo de conexão não encontrado.");
}
include $caminho_conexao;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_crud'])) {
    
    $acao = $_POST['acao_crud'];
    $cnpj = preg_replace('/[^0-9]/', '', $_POST['cnpj']);

    try {
        if ($acao == 'editar') {
            $nome_cadastro = $_POST['nome_cadastro'];
            $celular = preg_replace('/[^0-9]/', '', $_POST['celular']);
            $grupo_whats = $_POST['grupo_whats'];
            $grupo_empresa = $_POST['grupo_empresa'];
            
            // O segredo para os MÚLTIPLOS VÍNCULOS
            $cpfs_vinculados = isset($_POST['cpfs_vinculados']) ? $_POST['cpfs_vinculados'] : [];

            $pdo->beginTransaction();

            // 1. Atualiza os dados de texto da empresa
            $stmt = $pdo->prepare("UPDATE CLIENTE_EMPRESAS SET 
                NOME_CADASTRO = :nome_cadastro, 
                CELULAR = :celular, 
                GRUPO_WHATS = :grupo_whats, 
                GRUPO_EMPRESA = :grupo_empresa 
                WHERE CNPJ = :cnpj");
            $stmt->execute([
                'nome_cadastro' => $nome_cadastro,
                'celular' => $celular,
                'grupo_whats' => $grupo_whats,
                'grupo_empresa' => $grupo_empresa,
                'cnpj' => $cnpj
            ]);

            // 2. Limpa os vínculos antigos dessa empresa (desvincula os clientes antigos)
            $stmtLimpar = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CNPJ = NULL WHERE CNPJ = :cnpj");
            $stmtLimpar->execute(['cnpj' => $cnpj]);

            // 3. Vincula os novos clientes que você selecionou na lista múltipla
            if (!empty($cpfs_vinculados)) {
                $stmtSet = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CNPJ = :cnpj WHERE CPF = :cpf");
                foreach($cpfs_vinculados as $cpf_v) {
                    $stmtSet->execute(['cnpj' => $cnpj, 'cpf' => $cpf_v]);
                }
            }

            $pdo->commit();

        } elseif ($acao == 'excluir') {
            $pdo->beginTransaction();
            // Desvincula os clientes para não dar erro
            $stmtLimpar = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CNPJ = NULL WHERE CNPJ = :cnpj");
            $stmtLimpar->execute(['cnpj' => $cnpj]);

            // Apaga a empresa
            $stmt = $pdo->prepare("DELETE FROM CLIENTE_EMPRESAS WHERE CNPJ = :cnpj");
            $stmt->execute(['cnpj' => $cnpj]);
            
            $pdo->commit();
        }

        header("Location: cadastro_empresa.php");
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        die("
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #dc3545; border-radius: 8px; background-color: #f8d7da; color: #721c24;'>
            <h2 style='margin-top: 0;'><i class='fas fa-exclamation-triangle'></i> Erro ao salvar Empresa</h2>
            <p>Não foi possível processar a sua solicitação no banco de dados.</p>
            <hr style='border-color: #f5c6cb;'>
            <p><b>Detalhes do MySQL:</b> " . $e->getMessage() . "</p>
            <br>
            <a href='cadastro_empresa.php?cnpj_selecionado=".$cnpj."&acao=visualizar' style='display: inline-block; padding: 10px 15px; background-color: #343a40; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold;'>Voltar para a Ficha</a>
        </div>");
    }
} else {
    header("Location: cadastro_empresa.php");
    exit;
}
?>