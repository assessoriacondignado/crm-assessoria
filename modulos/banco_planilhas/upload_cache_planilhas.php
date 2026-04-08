<?php
ob_start(); // Escudo para impedir que erros do PHP quebrem a resposta JSON
ini_set('display_errors', 0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método inválido.");
    }

    // Se o PHP receber um POST vazio, significa que o arquivo estourou o limite do post_max_size do php.ini
    if (empty($_FILES) && empty($_POST)) {
        throw new Exception("O arquivo é maior do que o limite permitido pelo servidor (post_max_size). Aumente o limite no seu Painel/PHP.");
    }

    if (!isset($_FILES['arquivo_csv'])) {
        throw new Exception("Nenhum arquivo foi recebido pelo servidor.");
    }

    $fileError = $_FILES['arquivo_csv']['error'];
    if ($fileError !== UPLOAD_ERR_OK) {
        $errosUpload = array(
            UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite upload_max_filesize do php.ini.',
            UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite do formulário HTML.',
            UPLOAD_ERR_PARTIAL => 'O upload do arquivo foi feito parcialmente (conexão caiu).',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever o arquivo no disco do servidor.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload.'
        );
        $msg = isset($errosUpload[$fileError]) ? $errosUpload[$fileError] : "Erro desconhecido: $fileError";
        throw new Exception($msg);
    }

    $tmpName = $_FILES['arquivo_csv']['tmp_name'];
    $novoNome = uniqid() . '_bruto.csv';
    $pasta_destino = __DIR__ . '/arquivos_brutos/';
    
    // Tenta criar a pasta se não existir
    if (!is_dir($pasta_destino)) {
        if(!@mkdir($pasta_destino, 0777, true)) {
            throw new Exception("Não foi possível criar a pasta arquivos_brutos.");
        }
    }
    
    $destino = $pasta_destino . $novoNome;

    // Move do cache temporário do Linux para a nossa pasta
    if (!@move_uploaded_file($tmpName, $destino)) {
        throw new Exception("Erro ao salvar no HD. Verifique as permissões da pasta (chmod 777).");
    }

    // Leitura rápida de 10 linhas para mostrar a Amostra de Cabeçalhos
    $cabecalhos = [];
    $amostra = [];
    
    $handle = @fopen($destino, "r");
    if ($handle !== FALSE) {
        $cabecalhos_lidos = fgetcsv($handle, 10000, ";");
        if ($cabecalhos_lidos) {
            // Limpa o primeiro caractere (às vezes vem sujo com encoding UTF-8 BOM)
            $cabecalhos_lidos[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $cabecalhos_lidos[0]);
            foreach($cabecalhos_lidos as $c) {
                $cabecalhos[] = mb_convert_encoding($c, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            }
            
            for ($i = 0; $i < 10; $i++) {
                $linha = fgetcsv($handle, 10000, ";");
                if ($linha) {
                    $linha_limpa = [];
                    foreach ($linha as $col) {
                        $linha_limpa[] = mb_convert_encoding($col, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                    }
                    $amostra[] = $linha_limpa;
                } else {
                    break;
                }
            }
        }
        fclose($handle);
    } else {
        throw new Exception("Falha ao abrir o arquivo para extrair os cabeçalhos.");
    }
    
    ob_clean(); // Limpa tudo
    echo json_encode(['sucesso' => true, 'arquivo_cache' => $novoNome, 'cabecalhos' => $cabecalhos, 'amostra' => $amostra]);

} catch (\Throwable $e) {
    ob_clean(); // Limpa tudo
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
?>