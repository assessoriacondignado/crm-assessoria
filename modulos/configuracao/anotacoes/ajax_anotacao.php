<?php
session_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../conexao.php';

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

$cpf_logado   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$nome_logado  = $_SESSION['usuario_nome'] ?? '';
$grupo_logado = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master    = in_array($grupo_logado, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($acao) {

            // =======================================================
            // 1. SALVAR NOVA ANOTAÇÃO GERAL
            // =======================================================
            case 'salvar_anotacao':
                $assunto  = trim($_POST['assunto'] ?? '');
                $anotacao = trim($_POST['anotacao'] ?? '');

                if (!empty($assunto) && !empty($anotacao)) {
                    $stmt = $pdo->prepare("INSERT INTO CONFIG_ANOTACOES_GERAIS (ASSUNTO, ANOTACAO, DATA_ATUALIZACAO) VALUES (?, ?, NOW())");
                    $stmt->execute([$assunto, $anotacao]);
                    header("Location: index.php?msg=sucesso");
                    exit;
                } else {
                    die("Erro: Preencha todos os campos obrigatórios (Assunto e Anotação).");
                }
                break;

            // =======================================================
            // 1b. EDITAR ANOTAÇÃO GERAL
            // =======================================================
            case 'editar_anotacao':
                header('Content-Type: application/json');
                $id       = intval($_POST['id'] ?? 0);
                $assunto  = trim($_POST['assunto'] ?? '');
                $anotacao = trim($_POST['anotacao'] ?? '');

                if ($id <= 0 || empty($assunto) || empty($anotacao)) {
                    echo json_encode(['success' => false, 'msg' => 'Dados incompletos.']);
                    exit;
                }
                $stmt = $pdo->prepare("UPDATE CONFIG_ANOTACOES_GERAIS SET ASSUNTO = ?, ANOTACAO = ?, DATA_ATUALIZACAO = NOW() WHERE ID = ?");
                $stmt->execute([$assunto, $anotacao, $id]);
                echo json_encode([
                    'success' => true,
                    'data_br' => date('d/m/Y H:i'),
                ]);
                exit;

            // =======================================================
            // 1c. EXCLUIR ANOTAÇÃO GERAL
            // =======================================================
            case 'excluir_anotacao':
                header('Content-Type: application/json');
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'msg' => 'ID inválido.']);
                    exit;
                }
                $pdo->prepare("DELETE FROM CONFIG_ANOTACOES_GERAIS WHERE ID = ?")->execute([$id]);
                echo json_encode(['success' => true]);
                exit;

            // =======================================================
            // 2. SALVAR NOVO ACESSO (SENHAS E SISTEMAS)
            // =======================================================
            case 'salvar_acesso':
                $tipo       = trim($_POST['tipo'] ?? 'SISTEMAS');
                $origem     = trim($_POST['origem'] ?? '');
                $usuario    = trim($_POST['usuario'] ?? '');
                $senha      = trim($_POST['senha'] ?? '');
                $uso        = trim($_POST['uso'] ?? '');
                $observacao = trim($_POST['observacao'] ?? '');

                if (!empty($origem)) {
                    $stmt = $pdo->prepare("INSERT INTO CONFIG_ACESSOS (TIPO, ORIGEM, USUARIO, SENHA, USO, OBSERVACAO, DATA_ATUALIZACAO) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                    $stmt->execute([$tipo, $origem, $usuario, $senha, $uso, $observacao]);
                    header("Location: index.php?msg=sucesso");
                    exit;
                } else {
                    die("Erro: O campo Origem (Nome do Banco/Sistema) é obrigatório.");
                }
                break;

            // =======================================================
            // 2b. EXCLUIR ACESSO
            // =======================================================
            case 'excluir_acesso':
                header('Content-Type: application/json');
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'msg' => 'ID inválido.']);
                    exit;
                }
                $pdo->prepare("DELETE FROM CONFIG_ACESSOS WHERE ID = ?")->execute([$id]);
                echo json_encode(['success' => true]);
                exit;

            // =======================================================
            // 3. SALVAR NOVO AVISO INTERNO (somente MASTER)
            // =======================================================
            case 'salvar_aviso':
                header('Content-Type: application/json');

                if (!$is_master) {
                    echo json_encode(['success' => false, 'msg' => 'Sem permissão.']);
                    exit;
                }

                $assunto     = trim($_POST['assunto'] ?? '');
                $conteudo    = trim($_POST['conteudo'] ?? '');
                $dest_tipo   = trim($_POST['dest_tipo'] ?? 'TODOS');
                $dest_raw    = trim($_POST['dest_valores'] ?? '[]');
                $dest_valores = json_decode($dest_raw, true) ?: [];

                if (empty($assunto) || empty($conteudo)) {
                    echo json_encode(['success' => false, 'msg' => 'Assunto e conteúdo são obrigatórios.']);
                    exit;
                }

                if (!in_array($dest_tipo, ['TODOS', 'EMPRESA', 'GRUPO', 'USUARIO'])) {
                    echo json_encode(['success' => false, 'msg' => 'Tipo de destinatário inválido.']);
                    exit;
                }

                if ($dest_tipo !== 'TODOS' && empty($dest_valores)) {
                    echo json_encode(['success' => false, 'msg' => 'Selecione ao menos um destinatário.']);
                    exit;
                }

                $pdo->beginTransaction();

                $stmtAviso = $pdo->prepare("
                    INSERT INTO AVISOS_INTERNOS (ASSUNTO, CONTEUDO, TIPO, CPF_CRIADOR, NOME_CRIADOR, DATA_CRIACAO)
                    VALUES (?, ?, 'MASTER', ?, ?, NOW())
                ");
                $stmtAviso->execute([$assunto, $conteudo, $cpf_logado, $nome_logado]);
                $aviso_id = $pdo->lastInsertId();

                if ($dest_tipo === 'TODOS') {
                    $pdo->prepare("INSERT INTO AVISOS_INTERNOS_DESTINATARIOS (AVISO_ID, TIPO_DEST, VALOR) VALUES (?, 'TODOS', 'TODOS')")
                        ->execute([$aviso_id]);
                } else {
                    $stmtDest = $pdo->prepare("INSERT INTO AVISOS_INTERNOS_DESTINATARIOS (AVISO_ID, TIPO_DEST, VALOR) VALUES (?, ?, ?)");
                    foreach ($dest_valores as $val) {
                        $val = trim($val);
                        if ($val !== '') {
                            $stmtDest->execute([$aviso_id, $dest_tipo, $val]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'id' => $aviso_id]);
                exit;

            // =======================================================
            // 4. BUSCAR CONTEÚDO DE UM AVISO
            // =======================================================
            case 'get_aviso':
                header('Content-Type: application/json');

                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'msg' => 'ID inválido.']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT ID, ASSUNTO, CONTEUDO, TIPO, NOME_CRIADOR, DATA_CRIACAO FROM AVISOS_INTERNOS WHERE ID = ?");
                $stmt->execute([$id]);
                $av = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$av) {
                    echo json_encode(['success' => false, 'msg' => 'Aviso não encontrado.']);
                    exit;
                }

                echo json_encode([
                    'success'      => true,
                    'id'           => $av['ID'],
                    'assunto'      => $av['ASSUNTO'],
                    'conteudo'     => $av['CONTEUDO'],
                    'tipo'         => $av['TIPO'],
                    'nome_criador' => $av['NOME_CRIADOR'],
                    'data_criacao' => date('d/m/Y H:i', strtotime($av['DATA_CRIACAO'])),
                ]);
                exit;

            // =======================================================
            // 5. MARCAR AVISO COMO LIDO
            // =======================================================
            case 'marcar_lido':
                header('Content-Type: application/json');

                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0 || empty($cpf_logado)) {
                    echo json_encode(['success' => false, 'msg' => 'Parâmetros inválidos.']);
                    exit;
                }

                $pdo->prepare("
                    INSERT IGNORE INTO AVISOS_INTERNOS_LEITURA (AVISO_ID, CPF_USUARIO, DATA_LEITURA)
                    VALUES (?, ?, NOW())
                ")->execute([$id, $cpf_logado]);

                // Invalida cache de avisos do header
                unset($_SESSION['avisos_header_' . $cpf_logado], $_SESSION['avisos_header_' . $cpf_logado . '_ts']);

                echo json_encode(['success' => true]);
                exit;

            // =======================================================
            // 6. MARCAR TODOS OS AVISOS COMO LIDOS
            // =======================================================
            case 'marcar_todos_lidos':
                header('Content-Type: application/json');

                if (empty($cpf_logado)) {
                    echo json_encode(['success' => false, 'msg' => 'Usuário não identificado.']);
                    exit;
                }

                // Busca id_empresa do usuário para filtro EMPRESA
                $id_empresa_usuario = null;
                try {
                    $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
                    $stmtEmp->execute([$cpf_logado]);
                    $id_empresa_usuario = $stmtEmp->fetchColumn();
                } catch (Exception $e) {}

                // Busca todos os avisos destinados ao usuário que ainda não leu
                $stmtTodos = $pdo->prepare("
                    SELECT a.ID FROM AVISOS_INTERNOS a
                    WHERE EXISTS (
                        SELECT 1 FROM AVISOS_INTERNOS_DESTINATARIOS d
                        WHERE d.AVISO_ID = a.ID AND (
                            d.TIPO_DEST = 'TODOS'
                            OR (d.TIPO_DEST = 'GRUPO'   AND d.VALOR = ?)
                            OR (d.TIPO_DEST = 'USUARIO' AND d.VALOR = ?)
                            OR (d.TIPO_DEST = 'EMPRESA' AND d.VALOR = ?)
                        )
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM AVISOS_INTERNOS_LEITURA l
                        WHERE l.AVISO_ID = a.ID AND l.CPF_USUARIO = ?
                    )
                ");
                $stmtTodos->execute([$grupo_logado, $cpf_logado, (string)$id_empresa_usuario, $cpf_logado]);
                $ids_pendentes = $stmtTodos->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($ids_pendentes)) {
                    $stmtIns = $pdo->prepare("INSERT IGNORE INTO AVISOS_INTERNOS_LEITURA (AVISO_ID, CPF_USUARIO, DATA_LEITURA) VALUES (?, ?, NOW())");
                    foreach ($ids_pendentes as $aid) {
                        $stmtIns->execute([$aid, $cpf_logado]);
                    }
                }

                // Invalida cache de avisos do header
                unset($_SESSION['avisos_header_' . $cpf_logado], $_SESSION['avisos_header_' . $cpf_logado . '_ts']);

                echo json_encode(['success' => true, 'marcados' => count($ids_pendentes)]);
                exit;

            // =======================================================
            // 7. EXCLUIR AVISO (somente MASTER)
            // =======================================================
            case 'excluir_aviso':
                header('Content-Type: application/json');

                if (!$is_master) {
                    echo json_encode(['success' => false, 'msg' => 'Sem permissão.']);
                    exit;
                }

                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'msg' => 'ID inválido.']);
                    exit;
                }

                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM AVISOS_INTERNOS_LEITURA    WHERE AVISO_ID = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM AVISOS_INTERNOS_DESTINATARIOS WHERE AVISO_ID = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM AVISOS_INTERNOS             WHERE ID = ?")->execute([$id]);
                $pdo->commit();

                echo json_encode(['success' => true]);
                exit;

            // =======================================================
            // 8. LISTAR AVISOS ENVIADOS (MASTER) — paginado
            // =======================================================
            case 'listar_avisos_master':
                header('Content-Type: application/json');

                if (!$is_master) {
                    echo json_encode(['success' => false, 'msg' => 'Sem permissão.']);
                    exit;
                }

                $offset = max(0, intval($_POST['offset'] ?? 0));

                $stmt = $pdo->prepare("
                    SELECT a.ID, a.ASSUNTO, a.TIPO, a.NOME_CRIADOR, a.DATA_CRIACAO,
                           (SELECT COUNT(*) FROM AVISOS_INTERNOS_LEITURA WHERE AVISO_ID = a.ID) as TOTAL_LIDOS,
                           (SELECT COUNT(*) FROM AVISOS_INTERNOS_DESTINATARIOS WHERE AVISO_ID = a.ID) as TOTAL_DEST
                    FROM AVISOS_INTERNOS a
                    ORDER BY a.DATA_CRIACAO DESC
                    LIMIT 20 OFFSET ?
                ");
                $stmt->execute([$offset]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as &$row) {
                    $row['DATA_CRIACAO_BR'] = date('d/m/Y H:i', strtotime($row['DATA_CRIACAO']));
                    $row['TOTAL_LIDOS'] = (int)$row['TOTAL_LIDOS'];
                    $row['TOTAL_DEST']  = (int)$row['TOTAL_DEST'];
                    $row['ID']          = (int)$row['ID'];
                }

                echo json_encode(['success' => true, 'data' => $rows]);
                exit;

            default:
                die("Ação não reconhecida pelo sistema.");
        }
    } catch (Exception $e) {
        if (in_array($acao, ['salvar_aviso','get_aviso','marcar_lido','marcar_todos_lidos','excluir_aviso','listar_avisos_master'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'msg' => 'Erro interno: ' . $e->getMessage()]);
            exit;
        }
        die("Erro no banco de dados: " . $e->getMessage());
    }
}

// Se tentar acessar o arquivo direto pela URL, volta pro index
header("Location: index.php");
exit;
?>
