<?php
session_start();
if (!isset($_SESSION['usuario_cpf'])) { http_response_code(403); exit; }

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;
$pdo->exec("SET NAMES utf8mb4");

$acao = $_POST['acao_lote'] ?? $_GET['acao_lote'] ?? '';

// ── Monta WHERE base igual à busca da tela ────────────────────────────────────
function buildWhere($pdo, $busca, $situacao, &$params) {
    $where = "WHERE 1=1";
    $params = [];
    if ($situacao && $situacao !== 'TODOS') {
        $where .= " AND c.SITUACAO = :sit";
        $params[':sit'] = $situacao;
    }
    if (!empty($busca)) {
        $num = preg_replace('/\D/', '', $busca);
        $where .= " AND (c.NOME LIKE :t OR c.BANCO_NOME LIKE :t";
        $params[':t'] = "%$busca%";
        if ($num) {
            $where .= " OR c.CPF LIKE :cpf OR c.CELULAR LIKE :cel";
            $params[':cpf'] = "$num%";
            $params[':cel'] = "$num%";
        }
        $where .= ")";
    }
    return $where;
}

// ── ATIVAR / INATIVAR ────────────────────────────────────────────────────────
if (in_array($acao, ['ativar', 'inativar'])) {
    header('Content-Type: application/json');
    $nova_sit = $acao === 'ativar' ? 'ATIVO' : 'INATIVO';
    $cpfs_post = $_POST['cpfs'] ?? [];

    if (!empty($cpfs_post)) {
        // Apenas os selecionados
        $placeholders = implode(',', array_fill(0, count($cpfs_post), '?'));
        $stmt = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SITUACAO = ? WHERE CPF IN ($placeholders)");
        $stmt->execute(array_merge([$nova_sit], $cpfs_post));
        echo json_encode(['ok' => true, 'total' => $stmt->rowCount()]);
    } else {
        // Todos os da busca atual
        $busca   = trim($_POST['busca'] ?? '');
        $situacao = $_POST['situacao'] ?? 'ATIVO';
        $params = [];
        $where = buildWhere($pdo, $busca, $situacao, $params);
        $stmt = $pdo->prepare("UPDATE CLIENTE_CADASTRO c SET c.SITUACAO = :nova $where");
        $stmt->execute(array_merge([':nova' => $nova_sit], $params));
        echo json_encode(['ok' => true, 'total' => $stmt->rowCount()]);
    }
    exit;
}

// ── EXPORTAR CSV ────────────────────────────────────────────────────────────
if ($acao === 'exportar') {
    $busca    = trim($_GET['busca'] ?? '');
    $situacao = $_GET['situacao'] ?? 'ATIVO';
    $cpfs_get = array_filter(explode(',', $_GET['cpfs'] ?? ''));

    if (!empty($cpfs_get)) {
        $placeholders = implode(',', array_fill(0, count($cpfs_get), '?'));
        $stmt = $pdo->prepare("SELECT c.ID, c.CPF, c.NOME, u.EMAIL, c.CELULAR, c.CNPJ, c.BANCO_NOME, c.CHAVE_PIX, c.SITUACAO
            FROM CLIENTE_CADASTRO c LEFT JOIN CLIENTE_USUARIO u ON c.CPF = u.CPF
            WHERE c.CPF IN ($placeholders) ORDER BY c.NOME ASC");
        $stmt->execute($cpfs_get);
    } else {
        $params = [];
        $where = buildWhere($pdo, $busca, $situacao, $params);
        $stmt = $pdo->prepare("SELECT c.ID, c.CPF, c.NOME, u.EMAIL, c.CELULAR, c.CNPJ, c.BANCO_NOME, c.CHAVE_PIX, c.SITUACAO
            FROM CLIENTE_CADASTRO c LEFT JOIN CLIENTE_USUARIO u ON c.CPF = u.CPF
            $where ORDER BY c.NOME ASC");
        $stmt->execute($params);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = 'clientes_' . date('Y-m-d_H-i') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel
    fputcsv($out, ['ID', 'CPF', 'Nome', 'E-mail', 'Celular', 'CNPJ', 'Banco', 'Chave PIX', 'Situação'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['ID'], $r['CPF'], $r['NOME'], $r['EMAIL'] ?? '',
            $r['CELULAR'], $r['CNPJ'] ?? '', $r['BANCO_NOME'] ?? '',
            $r['CHAVE_PIX'] ?? '', $r['SITUACAO'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'msg' => 'Ação inválida.']);
