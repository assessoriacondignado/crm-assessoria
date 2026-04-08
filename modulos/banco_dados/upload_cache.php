<?php
// Tira os limites de tempo e memória do PHP para este script
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

// Suprime avisos que podem quebrar o JSON de retorno no frontend
error_reporting(0); 
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['arquivo_csv'])) {
    
    $erro_upload = $_FILES['arquivo_csv']['error'];
    
    // Verifica se o Servidor barrou o arquivo
    if ($erro_upload !== UPLOAD_ERR_OK) {
        $msg = "Erro interno no upload (Código $erro_upload). ";
        if ($erro_upload == UPLOAD_ERR_INI_SIZE || $erro_upload == UPLOAD_ERR_FORM_SIZE) {
            $msg = "O arquivo ultrapassa o limite de tamanho configurado no servidor (php.ini).";
        }
        echo json_encode(['sucesso' => false, 'erro' => $msg]);
        exit;
    }

    $tmpName = $_FILES['arquivo_csv']['tmp_name'];
    $name = $_FILES['arquivo_csv']['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $pasta_destino = $_SERVER['DOCUMENT_ROOT'] . '/modulos/banco_dados/Aquivo_importacao/';
    if (!is_dir($pasta_destino)) { mkdir($pasta_destino, 0777, true); }

    $cabecalhos = [];
    $amostra = [];
    $identificador_retorno = ''; 

    // ==============================================================
    // 🗜️ FLUXO 1: ARQUIVO ZIP (DESCOMPACTAÇÃO)
    // ==============================================================
    if ($ext === 'zip') {
        $zip = new ZipArchive;
        if ($zip->open($tmpName) === TRUE) {
            // Cria uma pasta única para esse lote de planilhas
            $nome_pasta_lote = 'lote_zip_' . uniqid();
            $caminho_pasta_lote = $pasta_destino . $nome_pasta_lote . '/';
            mkdir($caminho_pasta_lote, 0777, true);

            // Extrai tudo
            $zip->extractTo($caminho_pasta_lote);
            $zip->close();

            // Pega o primeiro arquivo CSV que encontrar lá dentro para gerar a Amostra
            $arquivos_extraidos = glob($caminho_pasta_lote . '*.csv');
            
            if (empty($arquivos_extraidos)) {
                // Se não tinha CSV no ZIP, apaga a pasta e dá erro
                rmdir($caminho_pasta_lote);
                echo json_encode(['sucesso' => false, 'erro' => 'O arquivo ZIP não continha nenhuma planilha .csv válida.']);
                exit;
            }

            // Lê o primeiro arquivo extraído
            $arquivo_amostra = $arquivos_extraidos[0];
            
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

            // Devolve o NOME DA PASTA para o Javascript
            $identificador_retorno = $nome_pasta_lote;

        } else {
            echo json_encode(['sucesso' => false, 'erro' => 'Falha ao descompactar o arquivo ZIP.']);
            exit;
        }
    } 
    // ==============================================================
    // 📄 FLUXO 2: ARQUIVO CSV ÚNICO (COMPORTAMENTO ANTIGO MANTIDO)
    // ==============================================================
    else {
        $novoNome = uniqid() . '.csv';
        $destino = $pasta_destino . $novoNome;

        if (move_uploaded_file($tmpName, $destino)) {
            if (($handle = fopen($destino, "r")) !== FALSE) {
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
            $identificador_retorno = $novoNome;
        } else {
            echo json_encode(['sucesso' => false, 'erro' => 'Falha ao mover o arquivo CSV. Verifique as permissões da pasta.']);
            exit;
        }
    }

    // ==============================================================
    // RETORNO UNIVERSAL PARA A TELA (ETAPA 2) - COM BLINDAGEM JSON
    // ==============================================================
    $dados_retorno = [
        'sucesso' => true, 
        'arquivo_cache' => $identificador_retorno,
        'cabecalhos' => $cabecalhos,
        'amostra' => $amostra
    ];

    // O comando JSON_INVALID_UTF8_SUBSTITUTE força o PHP a ignorar acentos quebrados 
    // e retornar o JSON válido de qualquer jeito, trocando o erro por um símbolo de interrogação ().
    $json_final = json_encode($dados_retorno, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    // Se mesmo com a blindagem máxima o json_encode falhar
    if ($json_final === false) {
        $erro_json = json_last_error_msg();
        echo json_encode([
            'sucesso' => false, 
            'erro' => 'Erro extremo ao processar a amostra do CSV (Erro: ' . $erro_json . '). DICA: Abra a planilha no Excel, clique em "Salvar Como" e escolha o formato "CSV UTF-8 (Delimitado por vírgulas)".'
        ]);
        exit;
    }

    echo $json_final;
    exit;

} else {
    echo json_encode(['sucesso' => false, 'erro' => 'Nenhum arquivo foi recebido pelo PHP.']);
    exit;
}
?>