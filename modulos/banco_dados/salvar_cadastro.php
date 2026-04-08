<?php
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Limpa o CPF garantindo que venha apenas números
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']); 
    $nome = trim($_POST['nome']);
    
    // Tratamento dos campos
    $sexo = !empty($_POST['sexo']) ? trim($_POST['sexo']) : null;
    $nascimento = !empty($_POST['nascimento']) ? $_POST['nascimento'] : null;
    $idade = !empty($_POST['idade']) ? $_POST['idade'] : null;
    $nome_mae = !empty($_POST['nome_mae']) ? trim($_POST['nome_mae']) : null;
    $nome_pai = !empty($_POST['nome_pai']) ? trim($_POST['nome_pai']) : null;
    $rg = !empty($_POST['rg']) ? trim($_POST['rg']) : null;
    $cnh = !empty($_POST['cnh']) ? trim($_POST['cnh']) : null;
    $carteira_profissional = !empty($_POST['carteira_profissional']) ? trim($_POST['carteira_profissional']) : null;
    
    $pdo->beginTransaction();

    try {
        // 1. Salva na Tabela Principal
        $stmt = $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, idade, nome_mae, nome_pai, rg, cnh, carteira_profissional) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cpf, $nome, $sexo, $nascimento, $idade, $nome_mae, $nome_pai, $rg, $cnh, $carteira_profissional]);

        // 2. Salva os Telefones
        if (isset($_POST['telefones']) && is_array($_POST['telefones'])) {
            $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)");
            foreach ($_POST['telefones'] as $telefone) {
                $telefone_limpo = preg_replace('/[^0-9]/', '', $telefone);
                if (!empty($telefone_limpo)) {
                    $stmtTel->execute([$cpf, $telefone_limpo]);
                }
            }
        }

        // 3. Salva o Endereço
        if (!empty($_POST['cidade'])) {
            $stmtEnd = $pdo->prepare("INSERT INTO enderecos (cpf, cep, logradouro, numero, bairro, cidade, uf) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtEnd->execute([$cpf, $_POST['cep'], $_POST['logradouro'], $_POST['numero'], $_POST['bairro'], $_POST['cidade'], strtoupper($_POST['uf'])]);
        }

        // 4. Salva os E-mails
        if (isset($_POST['emails']) && is_array($_POST['emails'])) {
            $stmtEmail = $pdo->prepare("INSERT IGNORE INTO emails (cpf, email) VALUES (?, ?)");
            foreach ($_POST['emails'] as $email) {
                $email_limpo = trim($email);
                if (!empty($email_limpo)) {
                    $stmtEmail->execute([$cpf, $email_limpo]);
                }
            }
        }

        // 5. NOVO: Salva os Convênios e Matrículas (Até 5 vínculos)
        if (isset($_POST['convenios_nome']) && is_array($_POST['convenios_nome']) && isset($_POST['convenios_matricula'])) {
            // INSERT IGNORE previne que o sistema trave se tentarem salvar a mesma matrícula para o mesmo convênio 2x
            $stmtConv = $pdo->prepare("INSERT IGNORE INTO BANCO_DADOS_CONVENIO (CPF, CONVENIO, MATRICULA) VALUES (?, ?, ?)");
            
            for ($i = 0; $i < count($_POST['convenios_nome']); $i++) {
                $conv_nome = trim($_POST['convenios_nome'][$i]);
                $conv_mat = trim($_POST['convenios_matricula'][$i]);
                
                // Só salva no banco se o usuário preencheu TANTO o convênio QUANTO a matrícula daquela linha
                if (!empty($conv_nome) && !empty($conv_mat)) {
                    $stmtConv->execute([$cpf, strtoupper($conv_nome), strtoupper($conv_mat)]);
                }
            }
        }

        $pdo->commit();

        // Redireciona já abrindo a ficha completa visual do cliente que acabou de ser criado
        header("Location: consulta.php?busca=" . $cpf . "&cpf_selecionado=" . $cpf . "&acao=visualizar");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("<div style='background: #ffdddd; color: red; padding: 20px; font-family: sans-serif; border-radius: 8px;'><h3>Erro ao salvar cadastro:</h3> " . $e->getMessage() . "<br><br><a href='consulta.php' style='padding: 10px 20px; background: #333; color: #fff; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px;'>Voltar para a Consulta</a></div>");
    }
}
?>