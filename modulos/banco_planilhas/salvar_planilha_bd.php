<?php
ob_start(); 
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método inválido.");

    $arq_nome_temporario = $_POST['arquivo_cache'] ?? '';
    if (empty($arq_nome_temporario)) throw new Exception("O nome do arquivo em cache não foi recebido.");

    $caminho_antigo = __DIR__ . '/arquivos_brutos/' . $arq_nome_temporario;
    if (!file_exists($caminho_antigo)) throw new Exception("O arquivo temporário não foi encontrado no HD: " . $caminho_antigo);

    $nome_db = trim($_POST['nome_planilha'] ?? 'Planilha_Sem_Nome');
    
    // Mágica do Nome
    $nome_limpo = str_replace(' ', '_', $nome_db);
    $nome_limpo = preg_replace('/[áàãâä]/ui', 'a', $nome_limpo);
    $nome_limpo = preg_replace('/[éèêë]/ui', 'e', $nome_limpo);
    $nome_limpo = preg_replace('/[íìîï]/ui', 'i', $nome_limpo);
    $nome_limpo = preg_replace('/[óòõôö]/ui', 'o', $nome_limpo);
    $nome_limpo = preg_replace('/[úùûü]/ui', 'u', $nome_limpo);
    $nome_limpo = preg_replace('/[ç]/ui', 'c', $nome_limpo);
    $nome_limpo = preg_replace('/[^a-zA-Z0-9_]/', '', $nome_limpo);
    $nome_limpo = strtoupper(preg_replace('/_+/', '_', trim($nome_limpo, '_')));
    
    if(empty($nome_limpo)) $nome_limpo = "PLANILHA_IMPORTADA";

    $novo_nome_arquivo = $nome_limpo . '_' . date('d_m_Y_Hi') . '.csv';
    $caminho_novo = __DIR__ . '/arquivos_brutos/' . $novo_nome_arquivo;

    $caminho_final = $caminho_antigo;
    if (@rename($caminho_antigo, $caminho_novo)) {
        $caminho_final = $caminho_novo;
    } else if (@copy($caminho_antigo, $caminho_novo)) {
        @unlink($caminho_antigo);
        $caminho_final = $caminho_novo;
    } else {
        throw new Exception("Falta de permissão no Linux para renomear. Rode 'chmod -R 777' na pasta arquivos_brutos.");
    }

    // Contagem
    $linhas_totais = 0;
    $handle = @fopen($caminho_final, "r");
    if ($handle) {
        fgetcsv($handle, 10000, ";"); 
        while (fgetcsv($handle, 10000, ";") !== FALSE) $linhas_totais++;
        fclose($handle);
    } else {
        throw new Exception("Não foi possível ler o arquivo recém-renomeado para contar as linhas.");
    }

    $hoje = date('Y-m-d');
    $data_form = !empty($_POST['data_importacao']) ? $_POST['data_importacao'] : $hoje;
    
    $convenio = $_POST['convenio'] ?? '';
    $desc = $_POST['descricao_planilha'] ?? '';
    $qual_tel = $_POST['qualidade_telefones'] ?? '';
    $qual_fin = $_POST['qualidade_finalidade'] ?? '';
    $cabs = $_POST['cabecalhos'] ?? '';
    
    // NOVOS CAMPOS
    $tipo_lista = $_POST['tipo_lista'] ?? 'cadastral';
    $valor_lista = !empty($_POST['valor_lista']) ? str_replace(',', '.', $_POST['valor_lista']) : 0.00;

    $stmt = $pdo->prepare("INSERT INTO BANCO_DE_PLANILHA_REGISTRO (nome_planilha, convenio, data_importacao, data_atualizacao, quantidade_cpf, descricao_planilha, qualidade_telefones, qualidade_finalidade, arquivo_caminho, cabecalhos_json, status_arquivo, tipo_lista, valor_lista) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Ativo', ?, ?)");
    
    $stmt->execute([
        $nome_db, $convenio, $data_form, $hoje, $linhas_totais, $desc, $qual_tel, $qual_fin, $caminho_final, $cabs, $tipo_lista, $valor_lista
    ]);

    ob_clean();
    echo json_encode(['sucesso' => true]);

} catch (\Throwable $e) {
    ob_clean(); 
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
?>