<?php
ob_start();
ini_set('display_errors', 0);
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
header('Content-Type: application/json');

$acao = isset($_REQUEST['acao']) ? $_REQUEST['acao'] : '';

if ($acao == 'listar') {
    $stmt = $pdo->query("SELECT * FROM BANCO_DE_PLANILHA_REGISTRO ORDER BY id DESC");
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($dados as &$d) { 
        $d['data_importacao_br'] = date('d/m/Y', strtotime($d['data_importacao'])); 
    }
    ob_clean();
    echo json_encode($dados); 
    exit;
} 

elseif ($acao == 'editar') {
    $id = $_POST['id'] ?? '';
    $nome = $_POST['nome'] ?? '';
    $convenio = $_POST['convenio'] ?? '';
    $status = $_POST['status'] ?? 'Ativo';
    $desc = $_POST['desc'] ?? '';
    $tipo_lista = $_POST['tipo_lista'] ?? 'Cadastral';
    $valor_lista = str_replace(',', '.', $_POST['valor_lista'] ?? '0');
    
    // PEGANDO OS CAMPOS DE QUALIDADE COM SEGURANÇA
    $qual_tel = strtoupper($_POST['qual_tel'] ?? 'REGULAR');
    $qual_fin = strtoupper($_POST['qual_fin'] ?? 'REGULAR');

    try {
        $stmt = $pdo->prepare("UPDATE BANCO_DE_PLANILHA_REGISTRO SET nome_planilha=?, convenio=?, status_arquivo=?, descricao_planilha=?, tipo_lista=?, valor_lista=?, qualidade_telefones=?, qualidade_finalidade=? WHERE id=?");
        
        if($stmt->execute([$nome, $convenio, $status, $desc, $tipo_lista, $valor_lista, $qual_tel, $qual_fin, $id])) {
            ob_clean();
            echo json_encode(['sucesso' => true]);
        } else {
            ob_clean();
            echo json_encode(['sucesso' => false, 'erro' => 'Erro ao atualizar o banco de dados.']);
        }
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
    exit;
}

elseif ($acao == 'excluir') {
    $id = $_POST['id'];
    $stmtGet = $pdo->prepare("SELECT arquivo_caminho FROM BANCO_DE_PLANILHA_REGISTRO WHERE id=?");
    $stmtGet->execute([$id]);
    $arquivo = $stmtGet->fetchColumn();
    
    if($arquivo && file_exists($arquivo)) unlink($arquivo); 

    $stmt = $pdo->prepare("DELETE FROM BANCO_DE_PLANILHA_REGISTRO WHERE id=?");
    if($stmt->execute([$id])) { ob_clean(); echo json_encode(['sucesso' => true]); }
    else { ob_clean(); echo json_encode(['sucesso' => false, 'erro' => 'Erro ao excluir.']); }
    exit;
}

elseif ($acao == 'buscar_planilhas_filtro') {
    $rules = json_decode($_POST['rules'], true);

    function formatarDataBusca($dataBruta) {
        $partes = explode('/', trim($dataBruta));
        if (count($partes) == 3) { return $partes[2] . '-' . $partes[1] . '-' . $partes[0]; }
        return trim($dataBruta); 
    }

    $filtro_sql = "";
    $params = [];
    $contador_params = 1;

    foreach ($rules as $regra) {
        $campo_db = $regra['campo']; 
        $operador = $regra['operador']; 
        $valor_bruto = trim($regra['valor']);
        
        if ($operador == 'vazio') { $filtro_sql .= " AND ($campo_db IS NULL OR $campo_db = '' OR $campo_db = 0) "; continue; }

        $valores_array = explode(';', $valor_bruto);
        $condicoes_or = [];

        foreach ($valores_array as $val) {
            $val = trim($val); if ($val === '') continue;
            $param_nome = 'p' . $contador_params++; 

            if ($campo_db == 'data_importacao') {
                if ($operador == 'entre') {
                    $p_data = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                    if (count($p_data) == 2) { $val_in = formatarDataBusca($p_data[0]); $val_fim = formatarDataBusca($p_data[1]); }
                } else { $val = formatarDataBusca($val); }
            }

            if ($operador == 'contem') { $condicoes_or[] = "$campo_db LIKE :$param_nome"; $params[$param_nome] = "%$val%"; }
            elseif ($operador == 'nao_contem') { $condicoes_or[] = "$campo_db NOT LIKE :$param_nome"; $params[$param_nome] = "%$val%"; }
            elseif ($operador == 'comeca') { $condicoes_or[] = "$campo_db LIKE :$param_nome"; $params[$param_nome] = "$val%"; }
            elseif ($operador == 'igual') { $condicoes_or[] = "$campo_db = :$param_nome"; $params[$param_nome] = $val; }
            elseif ($operador == 'maior') { $condicoes_or[] = "$campo_db > :$param_nome"; $params[$param_nome] = $val; }
            elseif ($operador == 'menor') { $condicoes_or[] = "$campo_db < :$param_nome"; $params[$param_nome] = $val; }
            elseif ($operador == 'entre') {
                if ($campo_db == 'data_importacao' && isset($val_in) && isset($val_fim)) {
                    $p2 = 'p' . $contador_params++; $condicoes_or[] = "$campo_db BETWEEN :$param_nome AND :$p2";
                    $params[$param_nome] = $val_in; $params[$p2] = $val_fim;
                } else {
                    $p_entre = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                    if(count($p_entre) == 2) {
                        $p2 = 'p' . $contador_params++; $condicoes_or[] = "$campo_db BETWEEN :$param_nome AND :$p2";
                        $params[$param_nome] = trim($p_entre[0]); $params[$p2] = trim($p_entre[1]);
                    }
                }
            }
        }

        if (!empty($condicoes_or)) {
            if ($operador == 'nao_contem') { $filtro_sql .= " AND (" . implode(" AND ", $condicoes_or) . ") "; } 
            else { $filtro_sql .= " AND (" . implode(" OR ", $condicoes_or) . ") "; }
        }
    }

    try {
        $query = "SELECT * FROM BANCO_DE_PLANILHA_REGISTRO WHERE 1=1 AND (status_arquivo = 'Ativo' OR status_arquivo IS NULL) " . $filtro_sql . " ORDER BY id DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($resultados as &$d) { $d['data_importacao_br'] = date('d/m/Y', strtotime($d['data_importacao'])); }
        
        ob_clean();
        echo json_encode(['sucesso' => true, 'planilhas' => $resultados]);
    } catch (Exception $e) {
        ob_clean();
        echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
    }
}

elseif ($acao == 'preview_planilha_bruta') {
    $id = $_POST['id'];
    $stmt = $pdo->prepare("SELECT arquivo_caminho FROM BANCO_DE_PLANILHA_REGISTRO WHERE id=?");
    $stmt->execute([$id]);
    $arquivo = $stmt->fetchColumn();

    if(!$arquivo || !file_exists($arquivo)) { ob_clean(); echo json_encode(['sucesso' => false, 'erro' => 'O arquivo físico desta planilha não foi encontrado.']); exit; }

    $cabecalhos = []; $linhas_amostra = [];
    $handle = @fopen($arquivo, "r");
    if ($handle !== FALSE) {
        $cab_raw = fgetcsv($handle, 10000, ";");
        if($cab_raw) {
            $cab_raw[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $cab_raw[0]); 
            foreach($cab_raw as $c) $cabecalhos[] = mb_convert_encoding($c, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
        }
        for ($i = 0; $i < 50; $i++) {
            $linha = fgetcsv($handle, 10000, ";");
            if ($linha !== FALSE) {
                $linha_limpa = []; foreach ($linha as $col) $linha_limpa[] = mb_convert_encoding($col, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                $linhas_amostra[] = $linha_limpa;
            } else break;
        }
        fclose($handle);
    }
    ob_clean();
    echo json_encode(['sucesso' => true, 'cabecalhos' => $cabecalhos, 'linhas' => $linhas_amostra]);
}
?>