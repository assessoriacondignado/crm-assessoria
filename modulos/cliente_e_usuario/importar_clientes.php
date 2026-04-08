<?php
session_start();
header('Content-Type: application/json');
ini_set('display_errors', 0); // Desativar exibição de erros na tela para não quebrar o JSON

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) {
    echo json_encode(['success' => false, 'msg' => 'Erro interno: Arquivo de conexão não encontrado.']);
    exit;
}
include $caminho_conexao;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['arquivo_csv'])) {
    
    $arquivo = $_FILES['arquivo_csv'];

    // 1. Verifica se houve erro no upload
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'msg' => 'Erro interno ao fazer o upload do arquivo.']);
        exit;
    }

    // 2. Verifica a extensão (tem que ser .csv)
    $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if ($extensao != 'csv') {
        echo json_encode(['success' => false, 'msg' => 'Formato inválido. O arquivo precisa ser .CSV.']);
        exit;
    }

    // 3. Tenta abrir o arquivo para leitura
    $handle = fopen($arquivo['tmp_name'], "r");
    if ($handle === FALSE) {
        echo json_encode(['success' => false, 'msg' => 'O sistema não conseguiu abrir o arquivo enviado.']);
        exit;
    }

    $inseridos = 0;
    $ignorados = 0;

    // 4. Detecta automaticamente se o CSV usa vírgula ou ponto-e-vírgula
    $delimitador = ',';
    $primeiraLinha = fgets($handle);
    if (strpos($primeiraLinha, ';') !== false) {
        $delimitador = ';';
    }
    
    // Volta o ponteiro para o início para o fgetcsv pular a primeira linha (cabeçalho)
    rewind($handle);
    fgetcsv($handle, 1000, $delimitador);

    $pdo->beginTransaction();

    try {
        // 5. Prepara os moldes do banco FORA do loop (Mais rápido e seguro)
        $stmtCheck = $pdo->prepare("SELECT CPF FROM CLIENTE_CADASTRO WHERE CPF = :cpf LIMIT 1");
        $stmtInsertCad = $pdo->prepare("INSERT INTO CLIENTE_CADASTRO (CPF, NOME, CELULAR, SITUACAO) VALUES (:cpf, :nome, :celular, 'ATIVO')");
        $stmtInsertUsu = $pdo->prepare("INSERT INTO CLIENTE_USUARIO (CPF, NOME, CELULAR, EMAIL, Tentativas, Situação) VALUES (:cpf, :nome, :celular, :email, 0, 'ativo')");

        // 6. Loop lendo linha por linha do arquivo Excel/CSV
        while (($dados = fgetcsv($handle, 1000, $delimitador)) !== FALSE) {
            
            // Previne erro se a linha estiver totalmente em branco
            if (empty(array_filter($dados))) continue;

            $nomeRaw = $dados[0] ?? '';
            $cpfRaw = $dados[1] ?? '';
            $celularRaw = $dados[2] ?? '';
            $emailRaw = $dados[3] ?? '';

            // Limpeza bruta (Apenas números para CPF e Celular)
            $cpf = preg_replace('/[^0-9]/', '', $cpfRaw);
            $celular = preg_replace('/[^0-9]/', '', $celularRaw);
            
            // Limpeza de texto e garantia de formatação (evita erro de acentuação)
            $nome = trim(mb_convert_encoding($nomeRaw, 'UTF-8', 'auto'));
            $email = trim(mb_convert_encoding($emailRaw, 'UTF-8', 'auto'));

            // Se a linha não tiver um CPF ou Nome válido, pula
            if (empty($cpf) || empty($nome)) {
                $ignorados++;
                continue; 
            }

            // =================================================================
            // A MÁGICA ACONTECE AQUI: PROTEÇÃO CONTRA ATUALIZAÇÃO E DUPLICIDADE
            // =================================================================
            $stmtCheck->execute(['cpf' => $cpf]);
            if ($stmtCheck->fetchColumn()) {
                // Se achou o CPF no banco, ele NÃO faz update. Apenas conta como ignorado e pula pra próxima linha.
                $ignorados++; 
            } else {
                // É um cliente 100% novo! Insere nas duas tabelas.
                $stmtInsertCad->execute(['cpf' => $cpf, 'nome' => $nome, 'celular' => $celular]);
                $stmtInsertUsu->execute(['cpf' => $cpf, 'nome' => $nome, 'celular' => $celular, 'email' => $email]);
                $inseridos++;
            }
        }

        $pdo->commit();
        fclose($handle);

        // Retorna a mensagem de sucesso com o resumo do que aconteceu
        echo json_encode([
            'success' => true, 
            'inseridos' => $inseridos, 
            'ignorados' => $ignorados,
            'msg' => "Importação Concluída!\n\n✅ {$inseridos} Novos clientes cadastrados.\n⏩ {$ignorados} Clientes ignorados (Já existiam ou linha inválida)."
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        fclose($handle);
        echo json_encode(['success' => false, 'msg' => 'Erro Fatal no Banco de Dados: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'msg' => 'Nenhuma requisição de arquivo foi recebida.']);
}
?>