<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
$pdo->exec("SET NAMES utf8mb4");

// Migração automática: adapta OPERACIONAL_TAREFAS para tarefas de clientes
try { $pdo->exec("ALTER TABLE OPERACIONAL_TAREFAS MODIFY COLUMN ENTREGA_ID INT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE OPERACIONAL_TAREFAS ADD COLUMN CPF_CLIENTE VARCHAR(11) NULL AFTER ENTREGA_ID"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE OPERACIONAL_TAREFAS ADD INDEX idx_tarefa_cpf (CPF_CLIENTE)"); } catch(Exception $e){}

$acao = $_POST['acao'] ?? '';

try {
    switch ($acao) {

        case 'listar_tarefas':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            if (empty($cpf)) throw new Exception("CPF inválido.");
            $stmt = $pdo->prepare("SELECT * FROM OPERACIONAL_TAREFAS WHERE CPF_CLIENTE = ? ORDER BY ID DESC LIMIT 100");
            $stmt->execute([$cpf]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        case 'salvar_tarefa':
            $cpf       = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $nome      = mb_strtoupper(trim($_POST['nome'] ?? ''), 'UTF-8');
            $titulo    = trim($_POST['titulo'] ?? '');
            $descricao = trim($_POST['descricao'] ?? '');
            $vencimento= !empty($_POST['vencimento']) ? $_POST['vencimento'] : null;

            if (empty($cpf) || empty($titulo)) throw new Exception("Dados incompletos.");

            $pdo->prepare("INSERT INTO OPERACIONAL_TAREFAS (CPF_CLIENTE, CLIENTE_NOME, TITULO_TAREFA, DESCRICAO, STATUS_TAREFA, DATA_VENCIMENTO) VALUES (?, ?, ?, ?, 'Pendente', ?)")
                ->execute([$cpf, $nome, $titulo, $descricao, $vencimento]);

            echo json_encode(['success' => true]);
            break;

        case 'atualizar_status':
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            $validos = ['Pendente', 'Em andamento', 'Concluída'];
            if ($id <= 0 || !in_array($status, $validos)) throw new Exception("Dados inválidos.");
            $pdo->prepare("UPDATE OPERACIONAL_TAREFAS SET STATUS_TAREFA = ? WHERE ID = ?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception("Ação não reconhecida.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
