<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_cpf'])) {
    echo json_encode(['success' => false]); exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;

$termo = trim($_POST['termo'] ?? '');
if (strlen($termo) < 3) { echo json_encode(['success' => false]); exit; }

try {
    $results = [];

    // Detecta formato de telefone: começa com ( ex: (82)999025155 ou (82) 9 9902-5155
    $is_telefone = preg_match('/^\s*\(/', $termo);

    $termo_limpo = preg_replace('/\D/', '', $termo);

    if ($is_telefone && strlen($termo_limpo) >= 8) {
        // Busca direto por telefone — sem tentar CPF
        $stmt = $pdo->prepare("SELECT d.cpf, d.nome, t.telefone_cel as tel FROM dados_cadastrais d INNER JOIN telefones t ON d.cpf = t.cpf WHERE t.telefone_cel LIKE ? GROUP BY d.cpf LIMIT 8");
        $stmt->execute([$termo_limpo . '%']);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($termo_limpo !== '' && strlen($termo_limpo) >= 8) {
        // Busca por CPF primeiro
        $cpf_pad = str_pad($termo_limpo, 11, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT d.cpf, d.nome, (SELECT telefone_cel FROM telefones WHERE cpf = d.cpf LIMIT 1) as tel FROM dados_cadastrais d WHERE d.cpf = ? LIMIT 1");
        $stmt->execute([$cpf_pad]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Se não achou CPF, tenta telefone
        if (empty($results)) {
            $stmt = $pdo->prepare("SELECT d.cpf, d.nome, t.telefone_cel as tel FROM dados_cadastrais d INNER JOIN telefones t ON d.cpf = t.cpf WHERE t.telefone_cel LIKE ? GROUP BY d.cpf LIMIT 8");
            $stmt->execute([$termo_limpo . '%']);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

    } elseif ($termo_limpo === '') {
        // Busca por nome (FULLTEXT)
        $palavras = array_filter(preg_split('/\s+/', trim($termo)));
        $ft = count($palavras) ? ('+' . implode('* +', $palavras) . '*') : $termo . '*';
        $stmt = $pdo->prepare("SELECT d.cpf, d.nome, (SELECT telefone_cel FROM telefones WHERE cpf = d.cpf LIMIT 1) as tel FROM dados_cadastrais d WHERE MATCH(d.nome) AGAINST(? IN BOOLEAN MODE) LIMIT 8");
        $stmt->execute([$ft]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($results as &$r) {
        $r['cpf_fmt'] = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($r['cpf'], 11, '0', STR_PAD_LEFT));
        // Formata telefone exibido
        if (!empty($r['tel']) && strlen($r['tel']) >= 10) {
            $t = $r['tel'];
            $r['tel'] = '(' . substr($t,0,2) . ') ' . (strlen($t)==11 ? substr($t,2,5).'-'.substr($t,7) : substr($t,2,4).'-'.substr($t,6));
        }
    }

    echo json_encode(['success' => true, 'data' => $results]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
?>
