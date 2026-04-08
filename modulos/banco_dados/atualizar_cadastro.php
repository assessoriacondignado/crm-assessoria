<?php
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Limpa o CPF para garantir
    $cpf = preg_replace('/[^0-9]/', '', $_POST['cpf']);
    $nome = trim($_POST['nome']);
    
    // Tratamento dos campos novos e opcionais
    $sexo = !empty($_POST['sexo']) ? trim($_POST['sexo']) : null;
    $nascimento = !empty($_POST['nascimento']) ? $_POST['nascimento'] : null;
    $nome_mae = !empty($_POST['nome_mae']) ? trim($_POST['nome_mae']) : null;
    $nome_pai = !empty($_POST['nome_pai']) ? trim($_POST['nome_pai']) : null;
    $rg = !empty($_POST['rg']) ? trim($_POST['rg']) : null;
    $cnh = !empty($_POST['cnh']) ? trim($_POST['cnh']) : null;
    $carteira_profissional = !empty($_POST['carteira_profissional']) ? trim($_POST['carteira_profissional']) : null;
    
    $pdo->beginTransaction();

    try {
        // 1. ATUALIZA a Tabela Principal (UPDATE em vez de INSERT)
        $stmt = $pdo->prepare("UPDATE dados_cadastrais SET nome=?, sexo=?, nascimento=?, nome_mae=?, nome_pai=?, rg=?, cnh=?, carteira_profissional=? WHERE cpf=?");
        $stmt->execute([$nome, $sexo, $nascimento, $nome_mae, $nome_pai, $rg, $cnh, $carteira_profissional, $cpf]);

        // 2. ATUALIZA Telefones (Apaga os antigos e insere os novos)
        $pdo->prepare("DELETE FROM telefones WHERE cpf=?")->execute([$cpf]);
        if (isset($_POST['telefones']) && is_array($_POST['telefones'])) {
            $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)");
            foreach ($_POST['telefones'] as $telefone) {
                $telefone_limpo = preg_replace('/[^0-9]/', '', $telefone);
                if (!empty($telefone_limpo)) {
                    $stmtTel->execute([$cpf, $telefone_limpo]);
                }
            }
        }

        // 3. ATUALIZA Endereços (Apaga o antigo e insere o novo)
        $pdo->prepare("DELETE FROM enderecos WHERE cpf=?")->execute([$cpf]);
        if (!empty($_POST['cidade'])) {
            $stmtEnd = $pdo->prepare("INSERT INTO enderecos (cpf, cep, logradouro, numero, bairro, cidade, uf) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtEnd->execute([$cpf, $_POST['cep'], $_POST['logradouro'], $_POST['numero'], $_POST['bairro'], $_POST['cidade'], strtoupper($_POST['uf'])]);
        }

        // 4. ATUALIZA E-mails
        $pdo->prepare("DELETE FROM emails WHERE cpf=?")->execute([$cpf]);
        if (isset($_POST['emails']) && is_array($_POST['emails'])) {
            $stmtEmail = $pdo->prepare("INSERT IGNORE INTO emails (cpf, email) VALUES (?, ?)");
            foreach ($_POST['emails'] as $email) {
                $email_limpo = trim($email);
                if (!empty($email_limpo)) {
                    $stmtEmail->execute([$cpf, $email_limpo]);
                }
            }
        }

        // 5. ATUALIZA Convênios (Apaga os antigos e insere os novos)
        $pdo->prepare("DELETE FROM BANCO_DADOS_CONVENIO WHERE CPF=?")->execute([$cpf]);
        if (isset($_POST['convenios_nome']) && is_array($_POST['convenios_nome']) && isset($_POST['convenios_matricula'])) {
            $stmtConv = $pdo->prepare("INSERT IGNORE INTO BANCO_DADOS_CONVENIO (CPF, CONVENIO, MATRICULA) VALUES (?, ?, ?)");
            for ($i = 0; $i < count($_POST['convenios_nome']); $i++) {
                $conv_nome = trim($_POST['convenios_nome'][$i]);
                $conv_mat = trim($_POST['convenios_matricula'][$i]);
                
                if (!empty($conv_nome) && !empty($conv_mat)) {
                    $stmtConv->execute([$cpf, strtoupper($conv_nome), strtoupper($conv_mat)]);
                }
            }
        }

        $pdo->commit();

        // Redireciona de volta para visualizar as alterações na ficha do cliente!
        header("Location: consulta.php?busca=" . $cpf . "&cpf_selecionado=" . $cpf . "&acao=visualizar");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("<div style='background: #ffdddd; color: red; padding: 20px; font-family: sans-serif; border-radius: 8px;'><h3>Erro ao atualizar:</h3> " . $e->getMessage() . "<br><br><a href='consulta.php' style='padding: 10px 20px; background: #333; color: #fff; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px;'>Voltar para Consulta</a></div>");
    }
}
?>