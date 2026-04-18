<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['usuario_cpf'])) {
    echo json_encode(['success' => false, 'msg' => 'Sessão expirada']);
    exit;
}

// Auto-cria tabela GUIA_CRM
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS GUIA_CRM (
        ID INT AUTO_INCREMENT PRIMARY KEY,
        TITULO VARCHAR(200) NOT NULL,
        COMENTARIO TEXT,
        NOME_CONTEUDO VARCHAR(255),
        TIPO_CONTEUDO ENUM('VIDEO','IMAGEM','TEXTO') DEFAULT 'TEXTO',
        LOCAL_EXIBICAO VARCHAR(200),
        STATUS ENUM('ATIVO','INATIVO') DEFAULT 'ATIVO',
        DATA_CRIACAO DATETIME DEFAULT NOW(),
        DATA_ATUALIZACAO DATETIME DEFAULT NOW() ON UPDATE NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$acao      = $_POST['acao'] ?? $_GET['acao'] ?? '';
$grupo     = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_admin  = in_array($grupo, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

$upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/modulos/configuracao/anotacoes/guia_sistema_conteudo/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
$upload_url = '/modulos/configuracao/anotacoes/guia_sistema_conteudo/';

switch ($acao) {

    // ── LISTAR (gerenciamento, somente admin) ─────────────────────────────
    case 'listar':
        if (!$is_admin) { echo json_encode(['success' => false, 'msg' => 'Sem permissão']); exit; }
        $rows = $pdo->query("SELECT ID, TITULO, COMENTARIO, TIPO_CONTEUDO, NOME_CONTEUDO, STATUS, LOCAL_EXIBICAO,
                             DATE_FORMAT(DATA_CRIACAO,'%d/%m/%Y %H:%i') AS DATA_BR
                             FROM GUIA_CRM ORDER BY DATA_CRIACAO DESC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── LISTAR WIDGET (ativos, para todos os usuários logados) ────────────
    case 'listar_widget':
        $rows = $pdo->query("SELECT ID, TITULO, COMENTARIO, TIPO_CONTEUDO, NOME_CONTEUDO, LOCAL_EXIBICAO
                             FROM GUIA_CRM WHERE STATUS='ATIVO' ORDER BY DATA_CRIACAO DESC")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    // ── SALVAR (criar ou editar) ──────────────────────────────────────────
    case 'salvar':
        if (!$is_admin) { echo json_encode(['success' => false, 'msg' => 'Sem permissão']); exit; }

        $id             = (int)($_POST['id'] ?? 0);
        $titulo         = trim($_POST['titulo'] ?? '');
        $comentario     = trim($_POST['comentario'] ?? '');
        $tipo_conteudo  = $_POST['tipo_conteudo'] ?? 'TEXTO';
        $local_exibicao = trim($_POST['local_exibicao'] ?? '');
        $conteudo_texto = $_POST['conteudo_texto'] ?? '';

        if (!$titulo) { echo json_encode(['success' => false, 'msg' => 'Título obrigatório']); exit; }
        if (!in_array($tipo_conteudo, ['VIDEO', 'IMAGEM', 'TEXTO'])) $tipo_conteudo = 'TEXTO';

        $nome_arquivo_atual = null;
        if ($id) {
            $stm = $pdo->prepare("SELECT NOME_CONTEUDO FROM GUIA_CRM WHERE ID=? LIMIT 1");
            $stm->execute([$id]);
            $nome_arquivo_atual = $stm->fetchColumn() ?: null;
        }

        $nome_arquivo = $nome_arquivo_atual; // mantém o atual por padrão

        if ($tipo_conteudo === 'TEXTO') {
            // Salva conteúdo HTML como arquivo
            if ($nome_arquivo_atual && pathinfo($nome_arquivo_atual, PATHINFO_EXTENSION) === 'html') {
                // Sobrescreve arquivo existente
                file_put_contents($upload_dir . $nome_arquivo_atual, $conteudo_texto);
                $nome_arquivo = $nome_arquivo_atual;
            } else {
                // Novo arquivo
                if ($nome_arquivo_atual && file_exists($upload_dir . $nome_arquivo_atual)) {
                    @unlink($upload_dir . $nome_arquivo_atual);
                }
                $nome_arquivo = uniqid('guia_') . '.html';
                file_put_contents($upload_dir . $nome_arquivo, $conteudo_texto);
            }
        } elseif (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === 0) {
            // Upload de arquivo (VIDEO ou IMAGEM)
            $exts_ok = [
                'VIDEO' => ['mp4'],
                'IMAGEM' => ['png', 'jpg', 'jpeg', 'bmp'],
            ];
            $orig_name = $_FILES['arquivo']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if (!isset($exts_ok[$tipo_conteudo]) || !in_array($ext, $exts_ok[$tipo_conteudo])) {
                echo json_encode(['success' => false, 'msg' => 'Tipo de arquivo não permitido para este tipo de conteúdo.']);
                exit;
            }

            // Remove arquivo antigo
            if ($nome_arquivo_atual && file_exists($upload_dir . $nome_arquivo_atual)) {
                @unlink($upload_dir . $nome_arquivo_atual);
            }

            $nome_arquivo = uniqid('guia_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $orig_name);
            if (!move_uploaded_file($_FILES['arquivo']['tmp_name'], $upload_dir . $nome_arquivo)) {
                echo json_encode(['success' => false, 'msg' => 'Falha ao salvar o arquivo. Verifique permissões da pasta.']);
                exit;
            }
        }
        // Se for VIDEO/IMAGEM e não vier arquivo novo, mantém $nome_arquivo = $nome_arquivo_atual

        if ($id) {
            $pdo->prepare("UPDATE GUIA_CRM SET TITULO=?, COMENTARIO=?, TIPO_CONTEUDO=?, NOME_CONTEUDO=?, LOCAL_EXIBICAO=?, DATA_ATUALIZACAO=NOW() WHERE ID=?")
                ->execute([$titulo, $comentario, $tipo_conteudo, $nome_arquivo, $local_exibicao, $id]);
        } else {
            $pdo->prepare("INSERT INTO GUIA_CRM (TITULO, COMENTARIO, TIPO_CONTEUDO, NOME_CONTEUDO, LOCAL_EXIBICAO, STATUS) VALUES (?,?,?,?,?,'ATIVO')")
                ->execute([$titulo, $comentario, $tipo_conteudo, $nome_arquivo, $local_exibicao]);
            $id = (int)$pdo->lastInsertId();
        }

        echo json_encode(['success' => true, 'id' => $id]);
        break;

    // ── TOGGLE STATUS ─────────────────────────────────────────────────────
    case 'toggle_status':
        if (!$is_admin) { echo json_encode(['success' => false, 'msg' => 'Sem permissão']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT STATUS FROM GUIA_CRM WHERE ID=? LIMIT 1");
        $stmt->execute([$id]);
        $atual = $stmt->fetchColumn();
        $novo  = $atual === 'ATIVO' ? 'INATIVO' : 'ATIVO';
        $pdo->prepare("UPDATE GUIA_CRM SET STATUS=?, DATA_ATUALIZACAO=NOW() WHERE ID=?")->execute([$novo, $id]);
        echo json_encode(['success' => true, 'novo_status' => $novo]);
        break;

    // ── EXCLUIR ────────────────────────────────────────────────────────────
    case 'excluir':
        if (!$is_admin) { echo json_encode(['success' => false, 'msg' => 'Sem permissão']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT NOME_CONTEUDO FROM GUIA_CRM WHERE ID=? LIMIT 1");
        $stmt->execute([$id]);
        $arq = $stmt->fetchColumn();
        if ($arq && file_exists($upload_dir . $arq)) @unlink($upload_dir . $arq);
        $pdo->prepare("DELETE FROM GUIA_CRM WHERE ID=?")->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    // ── GET (conteúdo completo para exibir no viewer) ─────────────────────
    case 'get':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM GUIA_CRM WHERE ID=? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'msg' => 'Não encontrado']); exit; }

        $conteudo_html = '';
        $arq = $row['NOME_CONTEUDO'];
        if ($row['TIPO_CONTEUDO'] === 'TEXTO') {
            if ($arq && file_exists($upload_dir . $arq)) {
                $conteudo_html = file_get_contents($upload_dir . $arq);
            }
        } elseif ($row['TIPO_CONTEUDO'] === 'VIDEO' && $arq) {
            $url = $upload_url . rawurlencode($arq);
            $conteudo_html = '<video controls style="width:100%;border-radius:8px;background:#000;" src="' . htmlspecialchars($url) . '"></video>';
        } elseif ($row['TIPO_CONTEUDO'] === 'IMAGEM' && $arq) {
            $url = $upload_url . rawurlencode($arq);
            $conteudo_html = '<img src="' . htmlspecialchars($url) . '" style="max-width:100%;border-radius:8px;display:block;margin:0 auto;" alt="' . htmlspecialchars($row['TITULO']) . '">';
        }

        // Para edição, retorna o conteúdo bruto do arquivo TEXTO
        $conteudo_raw = '';
        if ($row['TIPO_CONTEUDO'] === 'TEXTO' && $arq && file_exists($upload_dir . $arq)) {
            $conteudo_raw = file_get_contents($upload_dir . $arq);
        }

        $row['CONTEUDO_HTML'] = $conteudo_html;
        $row['CONTEUDO_RAW']  = $conteudo_raw;
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    default:
        echo json_encode(['success' => false, 'msg' => 'Ação desconhecida']);
}
