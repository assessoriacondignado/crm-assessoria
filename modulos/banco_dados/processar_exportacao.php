<?php
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['modelo_exportacao'])) {
    
    $modelo = $_POST['modelo_exportacao'];

    // =================================================================
    // MODELO 1: DADOS CADASTRAIS GERAL (1 Linha por Cliente)
    // =================================================================
    if ($modelo == 'dados_cadastrais') {
        
        $nome_arquivo = "exportacao_cadastros_" . date('Y-m-d_His') . ".csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
        
        $output = fopen('php://output', 'w');
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        $cabecalhos = [
            'CPF', 'NOME COMPLETO', 'SEXO', 'NASCIMENTO', 'IDADE', 'NOME DA MAE', 'NOME DO PAI', 
            'RG', 'CNH', 'CARTEIRA PROFISSIONAL', 'AGRUPAMENTO',
            'CEP', 'LOGRADOURO', 'NUMERO', 'BAIRRO', 'CIDADE', 'UF'
        ];
        for ($i = 1; $i <= 15; $i++) { $cabecalhos[] = "TELEFONE " . $i; }
        for ($i = 1; $i <= 5; $i++) { $cabecalhos[] = "EMAIL " . $i; }
        
        fputcsv($output, $cabecalhos, ';');

        $sql = "SELECT d.cpf, d.nome, d.sexo, d.nascimento, d.idade, d.nome_mae, d.nome_pai, d.rg, d.cnh, d.carteira_profissional, d.agrupamento,
                (SELECT cep FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as cep,
                (SELECT logradouro FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as logradouro,
                (SELECT numero FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as numero,
                (SELECT bairro FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as bairro,
                (SELECT cidade FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as cidade,
                (SELECT uf FROM enderecos e WHERE e.cpf = d.cpf LIMIT 1) as uf,
                (SELECT GROUP_CONCAT(telefone_cel SEPARATOR ',') FROM telefones t WHERE t.cpf = d.cpf) as telefones_agrupados,
                (SELECT GROUP_CONCAT(email SEPARATOR ',') FROM emails em WHERE em.cpf = d.cpf) as emails_agrupados
            FROM dados_cadastrais d";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idade_export = calcularIdade($row['nascimento'], $row['idade']);
            $linha_csv = [
                mascara_exportacao($row['cpf']), $row['nome'], $row['sexo'], 
                !empty($row['nascimento']) ? date('d/m/Y', strtotime($row['nascimento'])) : '',
                $idade_export, $row['nome_mae'], $row['nome_pai'], $row['rg'], $row['cnh'],
                $row['carteira_profissional'], $row['agrupamento'], $row['cep'], $row['logradouro'],
                $row['numero'], $row['bairro'], $row['cidade'], $row['uf']
            ];
            
            $tels = !empty($row['telefones_agrupados']) ? explode(',', $row['telefones_agrupados']) : [];
            for ($i = 0; $i < 15; $i++) { $linha_csv[] = isset($tels[$i]) ? trim($tels[$i]) : ''; }
            $emails = !empty($row['emails_agrupados']) ? explode(',', $row['emails_agrupados']) : [];
            for ($i = 0; $i < 5; $i++) { $linha_csv[] = isset($emails[$i]) ? trim($emails[$i]) : ''; }

            fputcsv($output, $linha_csv, ';');
        }
        fclose($output);
        exit;
    } 
    // =================================================================
    // MODELO 2: FOCO EM TELEFONES (1 Linha por Telefone)
    // =================================================================
    elseif ($modelo == 'telefones_foco') {
        
        $nome_arquivo = "exportacao_telefones_" . date('Y-m-d_His') . ".csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
        
        $output = fopen('php://output', 'w');
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        $cabecalhos = ['TELEFONE CELULAR', 'CPF', 'NOME COMPLETO', 'NASCIMENTO', 'IDADE'];
        fputcsv($output, $cabecalhos, ';');

        // INNER JOIN garante que só puxa pessoas que POSSUEM telefone registrado
        // Se a pessoa tiver 3 telefones, o banco gera 3 linhas automaticamente
        $sql = "SELECT t.telefone_cel, d.cpf, d.nome, d.nascimento, d.idade 
                FROM telefones t 
                INNER JOIN dados_cadastrais d ON t.cpf = d.cpf";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idade_export = calcularIdade($row['nascimento'], $row['idade']);
            
            $linha_csv = [
                $row['telefone_cel'],
                mascara_exportacao($row['cpf']),
                $row['nome'],
                !empty($row['nascimento']) ? date('d/m/Y', strtotime($row['nascimento'])) : '',
                $idade_export
            ];
            fputcsv($output, $linha_csv, ';');
        }
        fclose($output);
        exit;
    }
    // =================================================================
    // MODELO 3: FOCO EM E-MAILS (1 Linha por E-mail)
    // =================================================================
    elseif ($modelo == 'emails_foco') {
        
        $nome_arquivo = "exportacao_emails_" . date('Y-m-d_His') . ".csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
        
        $output = fopen('php://output', 'w');
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        $cabecalhos = ['ENDERECO DE E-MAIL', 'CPF', 'NOME COMPLETO', 'NASCIMENTO', 'IDADE'];
        fputcsv($output, $cabecalhos, ';');

        // INNER JOIN garante que só puxa pessoas que POSSUEM e-mail registrado
        $sql = "SELECT e.email, d.cpf, d.nome, d.nascimento, d.idade 
                FROM emails e 
                INNER JOIN dados_cadastrais d ON e.cpf = d.cpf";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $idade_export = calcularIdade($row['nascimento'], $row['idade']);
            
            $linha_csv = [
                $row['email'],
                mascara_exportacao($row['cpf']),
                $row['nome'],
                !empty($row['nascimento']) ? date('d/m/Y', strtotime($row['nascimento'])) : '',
                $idade_export
            ];
            fputcsv($output, $linha_csv, ';');
        }
        fclose($output);
        exit;
    }

} else {
    echo "Acesso Inválido.";
}

// =================================================================
// FUNÇÕES AUXILIARES DE EXPORTAÇÃO
// =================================================================

function mascara_exportacao($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    if(strlen($cpf) == 11) {
        return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
    }
    return $cpf;
}

function calcularIdade($nascimento_banco, $idade_banco) {
    $idade_export = $idade_banco;
    if (!empty($nascimento_banco) && $nascimento_banco != '0000-00-00') {
        try {
            $nasc_obj = new DateTime($nascimento_banco);
            $hoje_obj = new DateTime('today');
            $idade_export = $nasc_obj->diff($hoje_obj)->y;
        } catch (Exception $e) {}
    }
    return $idade_export;
}
?>