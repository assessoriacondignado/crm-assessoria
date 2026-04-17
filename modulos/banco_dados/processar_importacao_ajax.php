<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

$acao = $_POST['acao'] ?? '';

// ==============================================================
// 1. INICIAR A TAREFA EM BACKGROUND
// ==============================================================
if ($acao === 'iniciar_tarefa') {
    $arquivo = $_POST['arquivo_cache'];
    $tabela = $_POST['modelo_tabela'];
    $nome_importacao = trim($_POST['nome_importacao']);
    
    // INJETA OS PARÂMETROS NO JSON DO MAPEAMENTO
    $mapeamento_array = json_decode($_POST['mapeamento'], true);
    $mapeamento_array['id_campanha_vinculo'] = $_POST['id_campanha'] ?? '';
    $mapeamento_array['param_apagar_contratos'] = $_POST['apagar_contratos'] ?? 'NAO';
    $mapeamento = json_encode($mapeamento_array); 
    
    $nome_usuario = isset($_SESSION['usuario_nome']) ? trim($_SESSION['usuario_nome']) : 'Administrador (Deslogado)';

    $stmt = $pdo->prepare("INSERT INTO CONTROLE_IMPORTACAO_ASSINCRONA (NOME_IMPORTACAO, ARQUIVO, TABELA_DESTINO, NOME_USUARIO, STATUS) VALUES (?, ?, ?, ?, 'PENDENTE')");
    $stmt->execute([$nome_importacao, $arquivo, $tabela, $nome_usuario]);
    $taskId = $pdo->lastInsertId();

    $caminho_mapa = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/Aquivo_importacao/' . $arquivo . '_map.json';
    file_put_contents($caminho_mapa, $mapeamento);

    $caminho_worker = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/worker_importacao.php';
    $comando = "php " . escapeshellarg($caminho_worker) . " " . escapeshellarg($taskId) . " > /dev/null 2>&1 &";
    exec($comando);

    echo json_encode(['sucesso' => true, 'task_id' => $taskId]);
    exit;
}

// ==============================================================
// 2. PAUSAR TAREFA
// ==============================================================
if ($acao === 'pausar_tarefa') {
    $taskId = $_POST['task_id'];
    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS = 'PAUSADA' WHERE ID = ?")->execute([$taskId]);
    echo json_encode(['sucesso' => true]);
    exit;
}

// ==============================================================
// 3. RETOMAR TAREFA
// ==============================================================
if ($acao === 'retomar_tarefa') {
    $taskId = $_POST['task_id'];
    // Volta o status para PENDENTE e religa o motor
    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS = 'PENDENTE' WHERE ID = ?")->execute([$taskId]);
    
    $caminho_worker = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/worker_importacao.php';
    $comando = "php " . escapeshellarg($caminho_worker) . " " . escapeshellarg($taskId) . " > /dev/null 2>&1 &";
    exec($comando);
    
    echo json_encode(['sucesso' => true]);
    exit;
}

// ==============================================================
// 4. CANCELAR / EXCLUIR TAREFA
// ==============================================================
if ($acao === 'excluir_tarefa') {
    $taskId = $_POST['task_id'];
    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS = 'CANCELADA' WHERE ID = ?")->execute([$taskId]);
    echo json_encode(['sucesso' => true]);
    exit;
}

// ==============================================================
// 5. LISTAR FILA (DASHBOARD)
// ==============================================================
if ($acao === 'listar_tarefas') {
    $stmt = $pdo->query("SELECT * FROM CONTROLE_IMPORTACAO_ASSINCRONA ORDER BY ID DESC LIMIT 15");
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['sucesso' => true, 'tarefas' => $tarefas]);
    exit;
}

// ==============================================================
// 6. REUTILIZAR ARQUIVO EXISTENTE
// ==============================================================
if ($acao === 'reutilizar_arquivo') {
    $arquivo = $_POST['arquivo_cache'];
    $pasta_destino = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/Aquivo_importacao/';
    $caminho_arquivo = $pasta_destino . $arquivo;

    $arquivos_csv = [];
    if (is_dir($caminho_arquivo)) {
        $arquivos_csv = glob($caminho_arquivo . '/*.csv');
    } else {
        $arquivos_csv[] = $caminho_arquivo;
    }

    if (empty($arquivos_csv) || !file_exists($arquivos_csv[0])) {
        echo json_encode(['sucesso' => false, 'erro' => 'O arquivo original não foi encontrado no servidor. Talvez já tenha sido apagado.']);
        exit;
    }

    $arquivo_amostra = $arquivos_csv[0];
    $cabecalhos = [];
    $amostra = [];

    if (($handle = fopen($arquivo_amostra, "r")) !== FALSE) {
        $cabecalhos_lidos = fgetcsv($handle, 10000, ";");
        if ($cabecalhos_lidos) {
            if(count($cabecalhos_lidos) == 1 && strpos($cabecalhos_lidos[0], ',') !== false) { $cabecalhos_lidos = explode(',', $cabecalhos_lidos[0]); }
            $cabecalhos_lidos[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $cabecalhos_lidos[0]);
            $cabecalhos = $cabecalhos_lidos;

            for ($i = 0; $i < 5; $i++) {
                $linha = fgetcsv($handle, 10000, ";");
                if ($linha) {
                    if(count($linha) == 1 && count($cabecalhos_lidos) > 1 && strpos($linha[0], ',') !== false) { $linha = explode(',', $linha[0]); }
                    $linha_limpa = [];
                    foreach ($linha as $col) { $linha_limpa[] = mb_convert_encoding($col, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252'); }
                    $amostra[] = $linha_limpa;
                } else { break; }
            }
        }
        fclose($handle);
    }

    echo json_encode([
        'sucesso' => true,
        'arquivo_cache' => $arquivo,
        'cabecalhos' => $cabecalhos,
        'amostra' => $amostra
    ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

// ==============================================================
// 7. TESTAR IMPORTAÇÃO (5 LINHAS)
// ==============================================================
if ($acao === 'testar_importacao') {
    $arquivo = $_POST['arquivo_cache'];
    $tabela = $_POST['modelo_tabela'];
    $pasta_destino = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/Aquivo_importacao/';
    $caminho_arquivo = $pasta_destino . $arquivo;

    $arquivos_csv = is_dir($caminho_arquivo) ? glob($caminho_arquivo . '/*.csv') : [$caminho_arquivo];
    if (empty($arquivos_csv) || !file_exists($arquivos_csv[0])) {
        echo json_encode(['sucesso' => false, 'erro' => 'Arquivo não encontrado.']); exit;
    }

    $mapeamento_array = json_decode($_POST['mapeamento'], true);
    $idx_cpf = $mapeamento_array['cpf'] ?? null;
    
    if ($idx_cpf === null) {
        echo json_encode(['sucesso' => false, 'erro' => 'Coluna CPF não mapeada.']); exit;
    }

    $resultados = [];
    $linhas_lidas = 0;

    if (($handle = fopen($arquivos_csv[0], "r")) !== FALSE) {
        $cabecalhos_lidos = fgetcsv($handle, 10000, ";"); 
        
        while (($linha = fgetcsv($handle, 10000, ";")) !== FALSE && $linhas_lidas < 10) {
            if(count($linha) == 1 && count($cabecalhos_lidos) > 1 && strpos($linha[0], ',') !== false) { $linha = explode(',', $linha[0]); }
            
            $cpf_raw = isset($linha[$idx_cpf]) ? $linha[$idx_cpf] : '';
            $cpf = preg_replace('/[^0-9]/', '', $cpf_raw);
            if (strlen($cpf) > 0 && strlen($cpf) < 11) $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
            
            if (strlen($cpf) === 11) {
                $resultados[] = [
                    'cpf' => $cpf,
                    'status' => 'Sucesso'
                ];
                $linhas_lidas++;
            }
        }
        fclose($handle);
    }

    echo json_encode(['sucesso' => true, 'resultados' => $resultados]);
    exit;
}

echo json_encode(['sucesso' => false, 'erro' => 'Ação inválida.']);
?>