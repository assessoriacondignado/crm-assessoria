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

        case 'carregar_usuario':
            $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            if (empty($cpf)) throw new Exception("CPF inválido.");
            $st = $pdo->prepare("SELECT u.*, u.Situação as situacao FROM CLIENTE_USUARIO u WHERE u.CPF = ? LIMIT 1");
            $st->execute([$cpf]);
            $usr = $st->fetch(PDO::FETCH_ASSOC);
            $grupos = $pdo->query("SELECT NOME_GRUPO FROM SISTEMA_GRUPOS_USUARIO WHERE STATUS = 'ATIVO' ORDER BY NOME_GRUPO ASC")->fetchAll(PDO::FETCH_COLUMN);
            $empresas = $pdo->query("SELECT ID, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'usuario' => $usr ?: null, 'grupos' => $grupos, 'empresas' => $empresas]);
            break;

        case 'salvar_usuario':
            $cpf        = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
            $usuario    = trim($_POST['usuario'] ?? '');
            $senha      = trim($_POST['senha'] ?? '');
            $grupo      = trim($_POST['grupo_usuarios'] ?? '');
            $situacao   = trim($_POST['situacao'] ?? 'ativo');
            $id_empresa = intval($_POST['id_empresa'] ?? 0) ?: null;
            $data_exp   = trim($_POST['data_expirar'] ?? '') ?: null;
            if (empty($cpf)) throw new Exception("CPF inválido.");

            // Verifica se já existe
            $existe = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $existe->execute([$cpf]);
            $id_usr = $existe->fetchColumn();

            if ($id_usr) {
                // Atualiza
                $sql_u = "UPDATE CLIENTE_USUARIO SET GRUPO_USUARIOS=?, Situação=?, id_empresa=?, DATA_EXPIRAR=?";
                $params_u = [$grupo, $situacao, $id_empresa, $data_exp];
                if (!empty($usuario)) { $sql_u .= ", USUARIO=?"; $params_u[] = $usuario; }
                if (!empty($senha))   { $sql_u .= ", SENHA=?";   $params_u[] = md5($senha); }
                $sql_u .= " WHERE CPF=?"; $params_u[] = $cpf;
                $pdo->prepare($sql_u)->execute($params_u);
                echo json_encode(['success' => true, 'msg' => 'Usuário atualizado com sucesso!']);
            } else {
                // Cria novo
                if (empty($usuario)) throw new Exception("Login é obrigatório para criar usuário.");
                if (empty($senha))   throw new Exception("Senha é obrigatória para criar usuário.");
                $nome = $pdo->prepare("SELECT nome FROM dados_cadastrais WHERE cpf = ? LIMIT 1");
                $nome->execute([$cpf]);
                $nome_val = $nome->fetchColumn() ?: $cpf;
                $pdo->prepare("INSERT INTO CLIENTE_USUARIO (CPF, NOME, USUARIO, SENHA, GRUPO_USUARIOS, Situação, id_empresa, DATA_EXPIRAR) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$cpf, $nome_val, $usuario, md5($senha), $grupo, $situacao, $id_empresa, $data_exp]);
                echo json_encode(['success' => true, 'msg' => 'Usuário criado com sucesso!', 'criado' => true]);
            }
            break;

        case 'nova_empresa':
            $cnpj  = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
            $nome  = strtoupper(trim($_POST['nome_cadastro'] ?? ''));
            $cel   = preg_replace('/\D/', '', $_POST['celular'] ?? '');
            if (empty($cnpj) || empty($nome)) throw new Exception("CNPJ e Nome são obrigatórios.");
            $chk = $pdo->prepare("SELECT ID FROM CLIENTE_EMPRESAS WHERE CNPJ = ?");
            $chk->execute([$cnpj]);
            if ($chk->fetchColumn()) throw new Exception("Este CNPJ já está cadastrado.");
            $pdo->prepare("INSERT INTO CLIENTE_EMPRESAS (CNPJ, NOME_CADASTRO, CELULAR) VALUES (?, ?, ?)")
                ->execute([$cnpj, $nome, $cel ?: null]);
            echo json_encode(['success' => true, 'cnpj' => $cnpj, 'nome' => $nome, 'msg' => 'Empresa cadastrada com sucesso!']);
            break;

        case 'carregar_empresa':
            $cnpj = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
            if (empty($cnpj)) throw new Exception("CNPJ inválido.");
            $st = $pdo->prepare("SELECT CNPJ, NOME_CADASTRO, CELULAR FROM CLIENTE_EMPRESAS WHERE CNPJ = ?");
            $st->execute([$cnpj]);
            $emp = $st->fetch(PDO::FETCH_ASSOC);
            if (!$emp) throw new Exception("Empresa não encontrada.");
            echo json_encode(['success' => true, 'empresa' => $emp]);
            break;

        case 'editar_empresa':
            $cnpj  = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
            $nome  = strtoupper(trim($_POST['nome_cadastro'] ?? ''));
            $cel   = preg_replace('/\D/', '', $_POST['celular'] ?? '');
            if (empty($cnpj) || empty($nome)) throw new Exception("CNPJ e Nome são obrigatórios.");
            $pdo->prepare("UPDATE CLIENTE_EMPRESAS SET NOME_CADASTRO = ?, CELULAR = ? WHERE CNPJ = ?")
                ->execute([$nome, $cel ?: null, $cnpj]);
            echo json_encode(['success' => true, 'cnpj' => $cnpj, 'nome' => $nome, 'msg' => 'Empresa atualizada com sucesso!']);
            break;

        default:
            throw new Exception("Ação não reconhecida.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
