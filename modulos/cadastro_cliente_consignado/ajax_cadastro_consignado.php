<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; }
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? '';

    switch ($acao) {
        case 'listar_clientes':
            $stmt = $pdo->query("SELECT cpf, nome_completo, telefone_celular, cidade, uf, DATE_FORMAT(ultima_atualizacao, '%d/%m/%Y %H:%i') as atualizado_br FROM cadastro_cliente_consignado_dados_cadastrais ORDER BY nome_completo ASC");
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;

        case 'buscar_cliente':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $stmt = $pdo->prepare("SELECT * FROM cadastro_cliente_consignado_dados_cadastrais WHERE cpf = ?");
            $stmt->execute([$cpf]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cliente) { ob_end_clean(); echo json_encode(['success' => true, 'data' => $cliente]); exit; }
            else { ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Cliente não encontrado.']); exit; }

        case 'salvar_cliente':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            if(empty($cpf) || strlen($cpf) !== 11) throw new Exception("CPF inválido.");

            $nascimento = !empty($_POST['nascimento']) ? $_POST['nascimento'] : null;
            $idade = null;
            if ($nascimento) {
                $hoje = new DateTime(); $nasc = new DateTime($nascimento);
                $idade = $hoje->diff($nasc)->y;
            }

            $dados = [
                mb_strtoupper(trim($_POST['nome_completo']), 'UTF-8'), $nascimento, $idade,
                mb_strtoupper(trim($_POST['nome_mae']), 'UTF-8'), mb_strtoupper(trim($_POST['nome_pai']), 'UTF-8'),
                $_POST['sexo'], preg_replace('/\D/', '', $_POST['telefone_celular']),
                strtolower(trim($_POST['email'])), $_POST['id_whatsapp'],
                mb_strtoupper(trim($_POST['rua']), 'UTF-8'), $_POST['numero'],
                mb_strtoupper(trim($_POST['complemento']), 'UTF-8'), mb_strtoupper(trim($_POST['bairro']), 'UTF-8'),
                mb_strtoupper(trim($_POST['cidade']), 'UTF-8'), strtoupper($_POST['uf']),
                $_POST['rg'], (!empty($_POST['data_exp_rg']) ? $_POST['data_exp_rg'] : null),
                $_POST['tipo_pix'], $_POST['chave_pix'], $cpf
            ];

            // Verifica se existe para fazer INSERT ou UPDATE
            $stmtCheck = $pdo->prepare("SELECT cpf FROM cadastro_cliente_consignado_dados_cadastrais WHERE cpf = ?");
            $stmtCheck->execute([$cpf]);
            
            if ($stmtCheck->rowCount() > 0) {
                $sql = "UPDATE cadastro_cliente_consignado_dados_cadastrais SET nome_completo=?, nascimento=?, idade=?, nome_mae=?, nome_pai=?, sexo=?, telefone_celular=?, email=?, id_whatsapp=?, rua=?, numero=?, complemento=?, bairro=?, cidade=?, uf=?, rg=?, data_exp_rg=?, tipo_pix=?, chave_pix=? WHERE cpf=?";
            } else {
                $sql = "INSERT INTO cadastro_cliente_consignado_dados_cadastrais (nome_completo, nascimento, idade, nome_mae, nome_pai, sexo, telefone_celular, email, id_whatsapp, rua, numero, complemento, bairro, cidade, uf, rg, data_exp_rg, tipo_pix, chave_pix, cpf) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            }
            
            $pdo->prepare($sql)->execute($dados);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Cliente salvo com sucesso!']); exit;

        case 'excluir_cliente':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $pdo->prepare("DELETE FROM cadastro_cliente_consignado_dados_cadastrais WHERE cpf = ?")->execute([$cpf]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Cliente excluído.']); exit;

        default: throw new Exception("Ação desconhecida.");
    }
} catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>