<?php
// Oculta erros na tela e força o retorno JSON
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Puxa a sua conexão com o banco de dados (mesmo padrão do seu V8)
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2. Recebe os dados enviados pelo AJAX
    $json = file_get_contents('php://input');
    $dados = json_decode($json, true);

    // 3. Verifica se o CPF chegou
    if (!isset($dados['cpf']) || empty($dados['cpf'])) {
        ob_end_clean();
        echo json_encode(['status' => 'erro', 'mensagem' => 'CPF é obrigatório.']);
        exit;
    }

    // Limpa o CPF e ajusta as variáveis com os nomes certos
    $cpf = preg_replace('/[^0-9]/', '', $dados['cpf']); 
    $nome_completo = $dados['nome'] ?? '';
    $telefone_celular = $dados['telefone'] ?? '';

    // 4. Verifica se o cliente já existe (usando a sua tabela correta)
    $stmt = $pdo->prepare("SELECT cpf FROM cadastro_cliente_consignado_dados_cadastrais WHERE cpf = :cpf");
    $stmt->execute(['cpf' => $cpf]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cliente) {
        // Cliente EXISTE: Faz UPDATE
        $sql = "UPDATE cadastro_cliente_consignado_dados_cadastrais 
                SET nome_completo = :nome, 
                    telefone_celular = :telefone, 
                    ultima_atualizacao = NOW() 
                WHERE cpf = :cpf";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'nome' => $nome_completo,
            'telefone' => $telefone_celular,
            'cpf' => $cpf
        ]);
        
        ob_end_clean();
        echo json_encode(['status' => 'sucesso', 'acao' => 'atualizado']);

    } else {
        // Cliente NÃO EXISTE: Faz INSERT
        $sql = "INSERT INTO cadastro_cliente_consignado_dados_cadastrais 
                (cpf, nome_completo, telefone_celular, data_cadastro, ultima_atualizacao) 
                VALUES (:cpf, :nome, :telefone, NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'cpf' => $cpf,
            'nome' => $nome_completo,
            'telefone' => $telefone_celular
        ]);
        
        ob_end_clean();
        echo json_encode(['status' => 'sucesso', 'acao' => 'cadastrado']);
    }

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'erro', 'mensagem' => 'Erro no banco de dados: ' . $e->getMessage()]);
}
?>