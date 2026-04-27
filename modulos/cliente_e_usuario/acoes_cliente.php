<?php
session_start();

// Habilita a exibição de erros temporariamente para debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) {
    die("Erro Crítico: Arquivo de conexão não encontrado.");
}
include $caminho_conexao;

// Verifica se a requisição veio via POST e se tem ação definida
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_crud'])) {
    
    $acao = $_POST['acao_crud'];
    
    // ✨ LÓGICA DE BLINDAGEM TRIPLA DO CPF ✨
    $cpf_recebido = $_POST['cpf'];
    $cpf_cru = $cpf_recebido; // Exatamente como veio da ficha
    $cpf_so_numeros = preg_replace('/[^0-9]/', '', $cpf_recebido); // Apenas números
    $cpf_com_11_digitos = str_pad($cpf_so_numeros, 11, '0', STR_PAD_LEFT); // Força os 11 dígitos

    try {
        if ($acao == 'editar') {
            // Recebe os dados do formulário
            $nome = trim($_POST['nome'] ?? '');
            $celular = preg_replace('/[^0-9]/', '', $_POST['celular'] ?? '');
            $cnpj_vinculado = !empty($_POST['cnpj_vinculado']) ? $_POST['cnpj_vinculado'] : null;
            $banco_nome = trim($_POST['banco_nome'] ?? '');
            $chave_pix = trim($_POST['chave_pix'] ?? '');
            $grupo_whats = trim($_POST['grupo_whats'] ?? '');
            $grupo_assessor = trim($_POST['grupo_assessor'] ?? '');
            $situacao = trim($_POST['situacao'] ?? '');

            // Opcional: Busca o Nome da Empresa caso tenha selecionado um CNPJ, para manter a coluna NOME_EMPRESA atualizada
            $nome_empresa = null;
            if ($cnpj_vinculado) {
                $stmtEmp = $pdo->prepare("SELECT NOME_CADASTRO FROM CLIENTE_EMPRESAS WHERE CNPJ = :cnpj");
                $stmtEmp->execute(['cnpj' => $cnpj_vinculado]);
                $nome_empresa = $stmtEmp->fetchColumn();
            }

            // Prepara a query de atualização (UPDATE) com a blindagem
            $stmt = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET 
                NOME = :nome, 
                CELULAR = :celular, 
                CNPJ = :cnpj, 
                NOME_EMPRESA = :nome_empresa,
                BANCO_NOME = :banco_nome, 
                CHAVE_PIX = :chave_pix, 
                GRUPO_WHATS = :grupo_whats, 
                GRUPO_ASSESSOR = :grupo_assessor, 
                SITUACAO = :situacao 
                WHERE CPF = :cpf1 OR CPF = :cpf2 OR CPF = :cpf3");
            
            // Executa com segurança
            $stmt->execute([
                'nome' => $nome,
                'celular' => $celular,
                'cnpj' => $cnpj_vinculado,
                'nome_empresa' => $nome_empresa,
                'banco_nome' => $banco_nome,
                'chave_pix' => $chave_pix,
                'grupo_whats' => $grupo_whats,
                'grupo_assessor' => $grupo_assessor,
                'situacao' => $situacao,
                'cpf1' => $cpf_cru,
                'cpf2' => $cpf_so_numeros,
                'cpf3' => $cpf_com_11_digitos
            ]);

            // Busca o ID da empresa para sincronizar com CLIENTE_USUARIO.id_empresa
            $id_empresa_sync = null;
            if ($cnpj_vinculado) {
                $stmtEmpId = $pdo->prepare("SELECT ID FROM CLIENTE_EMPRESAS WHERE CNPJ = ? LIMIT 1");
                $stmtEmpId->execute([$cnpj_vinculado]);
                $id_empresa_sync = $stmtEmpId->fetchColumn() ?: null;
            }

            // Sincroniza nome, celular E empresa em CLIENTE_USUARIO
            $stmtSync = $pdo->prepare("UPDATE CLIENTE_USUARIO SET NOME = :nome, CELULAR = :celular, id_empresa = :id_empresa WHERE CPF = :cpf1 OR CPF = :cpf2 OR CPF = :cpf3");
            $stmtSync->execute([
                'nome'       => $nome,
                'celular'    => $celular,
                'id_empresa' => $id_empresa_sync,
                'cpf1'       => $cpf_cru,
                'cpf2'       => $cpf_so_numeros,
                'cpf3'       => $cpf_com_11_digitos
            ]);

        } elseif ($acao == 'excluir') {
            // Prepara a query de exclusão (DELETE) com blindagem
            // Nota: Como configuramos ON DELETE CASCADE no banco, apagar o cliente vai apagar o usuário automaticamente.
            $stmt = $pdo->prepare("DELETE FROM CLIENTE_CADASTRO WHERE CPF = :cpf1 OR CPF = :cpf2 OR CPF = :cpf3");
            $stmt->execute([
                'cpf1' => $cpf_cru,
                'cpf2' => $cpf_so_numeros,
                'cpf3' => $cpf_com_11_digitos
            ]);
        }

        // Redireciona de volta
        header("Location: cadastro_cliente.php");
        exit;

    } catch (PDOException $e) {
        die("
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; border: 1px solid #dc3545; border-radius: 8px; background-color: #f8d7da; color: #721c24;'>
            <h2 style='margin-top: 0;'><i class='fas fa-exclamation-triangle'></i> Erro ao salvar Cliente</h2>
            <p>Não foi possível processar a sua solicitação no banco de dados.</p>
            <hr style='border-color: #f5c6cb;'>
            <p><b>Detalhes do MySQL:</b> " . htmlspecialchars($e->getMessage()) . "</p>
            <br>
            <a href='cadastro_cliente.php?cpf_selecionado=".urlencode($cpf_cru)."&acao=visualizar' style='display: inline-block; padding: 10px 15px; background-color: #343a40; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold;'>Voltar para a Ficha</a>
        </div>");
    }
} else {
    header("Location: cadastro_cliente.php");
    exit;
}
?>