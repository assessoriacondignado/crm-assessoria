<?php
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

if (isset($_GET['nome_planilha'])) {
    
    $nome_planilha = $_GET['nome_planilha'];
    $busca_rapida = trim($_GET['busca_rapida'] ?? '');
    $rules = isset($_GET['rules']) ? json_decode(urldecode($_GET['rules']), true) : [];

    function formatarDataBusca($dataBruta) {
        $partes = explode('/', trim($dataBruta));
        if (count($partes) == 3) return $partes[2] . '-' . $partes[1] . '-' . $partes[0];
        return trim($dataBruta); 
    }

    function mapearColunaBanco($campo) {
        $mapa = [
            'nome' => 'd.nome', 'cpf' => 'd.cpf', 'sexo' => 'd.sexo', 'nascimento' => 'd.nascimento',
            'idade' => 'TIMESTAMPDIFF(YEAR, d.nascimento, CURDATE())', 'agrupamento' => 'd.agrupamento',
            'telefone_cel' => 't.telefone_cel', 'ddd' => 'LEFT(t.telefone_cel, 2)', 'email' => 'em.email',
            'cidade' => 'e.cidade', 'uf' => 'e.uf', 'bairro' => 'e.bairro', 'cep' => 'e.cep'
        ];
        return isset($mapa[$campo]) ? $mapa[$campo] : 'd.nome';
    }

    $filtro_sql = " AND hi.nome_importacao = :nome_planilha ";
    $params = ['nome_planilha' => $nome_planilha];
    $contador_params = 1;

    // Filtro Rápido
    if (!empty($busca_rapida)) {
        $termo_limpo = preg_replace('/[^0-9]/', '', $busca_rapida);
        if (empty($termo_limpo)) {
            $filtro_sql .= " AND d.nome LIKE :br_nome "; $params['br_nome'] = "%{$busca_rapida}%";
        } else {
            $cpf_format = str_pad($termo_limpo, 11, '0', STR_PAD_LEFT);
            $filtro_sql .= " AND (d.cpf = :br_cpf OR t.telefone_cel LIKE :br_tel) ";
            $params['br_cpf'] = $cpf_format; $params['br_tel'] = "%{$termo_limpo}%";
        }
    }

    // Filtro Avançado
    if(is_array($rules)) {
        foreach ($rules as $regra) {
            $campo_html = $regra['campo']; $operador = $regra['operador']; $valor_bruto = trim($regra['valor']);
            $coluna_db = mapearColunaBanco($campo_html);
            
            if ($operador == 'vazio') { $filtro_sql .= " AND ($coluna_db IS NULL OR $coluna_db = '') "; continue; }

            $valores_array = explode(';', $valor_bruto);
            $condicoes_or = [];

            foreach ($valores_array as $val) {
                $val = trim($val); if ($val === '') continue;
                if ($campo_html == 'cpf') {
                    $val = preg_replace('/[^0-9]/', '', $val);
                    if (!empty($val)) $val = str_pad($val, 11, '0', STR_PAD_LEFT);
                }

                $param_nome = 'p' . $contador_params++; 

                if ($campo_html == 'nascimento') {
                    if ($operador == 'entre') {
                        $p_data = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                        if (count($p_data) == 2) { $val_in = formatarDataBusca($p_data[0]); $val_fim = formatarDataBusca($p_data[1]); }
                    } else { $val = formatarDataBusca($val); }
                }

                if ($operador == 'contem') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params[$param_nome] = "%$val%"; }
                elseif ($operador == 'nao_contem') { $condicoes_or[] = "$coluna_db NOT LIKE :$param_nome"; $params[$param_nome] = "%$val%"; }
                elseif ($operador == 'comeca') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params[$param_nome] = "$val%"; }
                elseif ($operador == 'igual') { $condicoes_or[] = "$coluna_db = :$param_nome"; $params[$param_nome] = $val; }
                elseif ($operador == 'maior') { $condicoes_or[] = "$coluna_db > :$param_nome"; $params[$param_nome] = $val; }
                elseif ($operador == 'menor') { $condicoes_or[] = "$coluna_db < :$param_nome"; $params[$param_nome] = $val; }
                elseif ($operador == 'entre') {
                    if ($campo_html == 'nascimento' && isset($val_in) && isset($val_fim)) {
                        $p2 = 'p' . $contador_params++; $condicoes_or[] = "$coluna_db BETWEEN :$param_nome AND :$p2";
                        $params[$param_nome] = $val_in; $params[$p2] = $val_fim;
                    } else {
                        $p_entre = explode(' a ', str_replace(' e ', ' a ', strtolower($val)));
                        if(count($p_entre) == 2) {
                            $p2 = 'p' . $contador_params++; $condicoes_or[] = "$coluna_db BETWEEN :$param_nome AND :$p2";
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
    }

    $nome_limpo = preg_replace('/[^A-Za-z0-9_]/', '', $nome_planilha);
    $nome_arquivo = "Extracao_" . $nome_limpo . "_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    
    $output = fopen('php://output', 'w');
    fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF))); // Formatação para o Excel reconhecer os acentos

    // Cabeçalhos (Idêntico ao módulo Consulta Universal)
    $cabecalhos = ['CPF', 'NOME COMPLETO', 'SEXO', 'NASCIMENTO', 'IDADE', 'NOME DA MAE', 'NOME DO PAI', 'RG', 'CNH', 'CARTEIRA PROFISSIONAL', 'AGRUPAMENTO', 'CEP', 'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF'];
    for ($i = 1; $i <= 15; $i++) { $cabecalhos[] = "TELEFONE " . $i; }
    for ($i = 1; $i <= 5; $i++) { $cabecalhos[] = "EMAIL " . $i; }
    fputcsv($output, $cabecalhos, ';');

    // QUERY DO BANCO DE DADOS
    $sql_export = "SELECT d.cpf, d.nome, d.sexo, d.nascimento, TIMESTAMPDIFF(YEAR, d.nascimento, CURDATE()) as idade, d.nome_mae, d.nome_pai, d.rg, d.cnh, d.carteira_profissional, d.agrupamento,
                    (SELECT cep FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as cep,
                    (SELECT logradouro FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as logradouro,
                    (SELECT numero FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as numero,
                    (SELECT bairro FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as bairro,
                    (SELECT cidade FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as cidade,
                    (SELECT uf FROM enderecos e2 WHERE e2.cpf = d.cpf LIMIT 1) as uf,
                    (SELECT GROUP_CONCAT(telefone_cel SEPARATOR ',') FROM telefones t2 WHERE t2.cpf = d.cpf) as telefones_agrupados,
                    (SELECT GROUP_CONCAT(email SEPARATOR ',') FROM emails em2 WHERE em2.cpf = d.cpf) as emails_agrupados
                   FROM dados_cadastrais d
                   INNER JOIN BANCO_DE_DADOS_HISTORICO_IMPORTACAO hi ON d.cpf = hi.cpf
                   LEFT JOIN telefones t ON d.cpf = t.cpf
                   LEFT JOIN enderecos e ON d.cpf = e.cpf
                   LEFT JOIN emails em ON d.cpf = em.cpf
                   WHERE 1=1 " . $filtro_sql . " GROUP BY d.cpf";

    $stmt_export = $pdo->prepare($sql_export);
    $stmt_export->execute($params);

    while ($row = $stmt_export->fetch(PDO::FETCH_ASSOC)) {
        // Formata CPF
        $cpf_export = preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", str_pad($row['cpf'], 11, '0', STR_PAD_LEFT));
        
        $linha_csv = [
            $cpf_export, $row['nome'], $row['sexo'],
            !empty($row['nascimento']) && $row['nascimento'] != '0000-00-00' ? date('d/m/Y', strtotime($row['nascimento'])) : '',
            $row['idade'] ? $row['idade'] : '-', 
            $row['nome_mae'], $row['nome_pai'], $row['rg'], $row['cnh'],
            $row['carteira_profissional'], $row['agrupamento'], $row['cep'],
            $row['logradouro'], $row['numero'], $row['bairro'], $row['cidade'], $row['uf']
        ];

        // Telefones (Puxa os 15)
        $tels = !empty($row['telefones_agrupados']) ? explode(',', $row['telefones_agrupados']) : [];
        for ($i = 0; $i < 15; $i++) { $linha_csv[] = isset($tels[$i]) ? trim($tels[$i]) : ''; }

        // E-mails (Puxa os 5)
        $em = !empty($row['emails_agrupados']) ? explode(',', $row['emails_agrupados']) : [];
        for ($i = 0; $i < 5; $i++) { $linha_csv[] = isset($em[$i]) ? trim($em[$i]) : ''; }

        fputcsv($output, $linha_csv, ';');
    }

    fclose($output);
    exit;
}
?>