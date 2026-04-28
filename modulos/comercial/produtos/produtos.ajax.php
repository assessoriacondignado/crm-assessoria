<?php
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../../../conexao.php'; // Verifique se chega na raiz do seu painel
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$acao = $_POST['acao'] ?? '';

try {
    switch ($acao) {
        case 'listar':
            $tipo = $_POST['tipo'];
            $stmt = $pdo->prepare("SELECT * FROM CATALOGO_ITENS WHERE TIPO = ? AND STATUS_ITEM = 'Ativo' ORDER BY ID DESC");
            $stmt->execute([$tipo]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'salvar':
            $tipo = $_POST['tipo'];
            $nome = mb_strtoupper(trim($_POST['nome']), 'UTF-8');
            $desc = trim($_POST['desc']);
            $tipo_venda = in_array($_POST['tipo_venda'] ?? '', ['VENDA','RENOVAÇÃO']) ? $_POST['tipo_venda'] : 'VENDA';
            $prefixo = ($tipo === 'PROD') ? 'PRODUTO-' : 'SERVICO-';

            $stmtLast = $pdo->prepare("SELECT COUNT(ID) FROM CATALOGO_ITENS WHERE TIPO = ?");
            $stmtLast->execute([$tipo]);
            $count = $stmtLast->fetchColumn() + 1;
            $codigo = $prefixo . str_pad($count, 3, '0', STR_PAD_LEFT);

            $dataCriacao = date('d/m/Y H:i');
            $historico = "[{$dataCriacao}] Criado.";

            $stmt = $pdo->prepare("INSERT INTO CATALOGO_ITENS (TIPO, CODIGO, NOME, TIPO_VENDA, DESCRICAO, STATUS_ITEM, HISTORICO_OBS) VALUES (?, ?, ?, ?, ?, 'Ativo', ?)");
            $stmt->execute([$tipo, $codigo, $nome, $tipo_venda, $desc, $historico]);

            echo json_encode(['success' => true, 'msg' => "Cadastrado: {$codigo}"]);
            break;

        case 'editar':
            $id = $_POST['id'];
            $nome = mb_strtoupper(trim($_POST['nome']), 'UTF-8');
            $desc = trim($_POST['desc']);
            $tipo_venda = in_array($_POST['tipo_venda'] ?? '', ['VENDA','RENOVAÇÃO']) ? $_POST['tipo_venda'] : 'VENDA';

            $stmt = $pdo->prepare("UPDATE CATALOGO_ITENS SET NOME = ?, DESCRICAO = ?, TIPO_VENDA = ? WHERE ID = ?");
            $stmt->execute([$nome, $desc, $tipo_venda, $id]);
            
            echo json_encode(['success' => true, 'msg' => 'Atualizado com sucesso!']);
            break;

        case 'mudar_status':
            $id = $_POST['id'];
            $status = $_POST['status'];
            $obs = trim($_POST['obs']);
            $dataAtual = date('d/m/Y H:i');

            $stmtHist = $pdo->prepare("SELECT HISTORICO_OBS FROM CATALOGO_ITENS WHERE ID = ?");
            $stmtHist->execute([$id]);
            $historicoAntigo = $stmtHist->fetchColumn();
            $novoHistorico = "[{$dataAtual} - {$status}]: {$obs}\n" . $historicoAntigo;

            $stmt = $pdo->prepare("UPDATE CATALOGO_ITENS SET STATUS_ITEM = ?, HISTORICO_OBS = ? WHERE ID = ?");
            $stmt->execute([$status, $novoHistorico, $id]);
            
            echo json_encode(['success' => true, 'msg' => 'Status alterado!']);
            break;

        // ==========================================
        // MÓDULO: EXPLORADOR DE ARQUIVOS
        // ==========================================
        case 'listar_arquivos':
            $itemId = $_POST['item_id'];
            $stmt = $pdo->prepare("SELECT *, DATE_FORMAT(DATA_UPLOAD, '%d/%m/%Y %H:%i') as DATA_BR FROM CATALOGO_ARQUIVOS WHERE ITEM_ID = ? ORDER BY ID DESC");
            $stmt->execute([$itemId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'upload_imagem_desc':
            $itemId = (int)($_POST['item_id'] ?? 0);
            if (!isset($_FILES['imagem']) || $_FILES['imagem']['error'] != 0) {
                throw new Exception("Selecione uma imagem válida.");
            }
            $ext = strtolower(pathinfo($_FILES['imagem']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                throw new Exception("Somente imagens (jpg, png, gif, webp).");
            }
            $dir = __DIR__ . '/arquivos/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $nomeUnico = md5(time().rand()) . '.' . $ext;
            if (move_uploaded_file($_FILES['imagem']['tmp_name'], $dir . $nomeUnico)) {
                if ($itemId > 0) {
                    $pdo->prepare("INSERT INTO CATALOGO_ARQUIVOS (ITEM_ID, NOME_ARQUIVO, CAMINHO_ARQUIVO) VALUES (?,?,?)")
                        ->execute([$itemId, $_FILES['imagem']['name'], 'arquivos/' . $nomeUnico]);
                }
                $url = '/modulos/comercial/produtos/arquivos/' . $nomeUnico;
                echo json_encode(['success' => true, 'url' => $url]);
            } else { throw new Exception("Falha ao salvar imagem."); }
            break;

        case 'upload_arquivo':
            $itemId = $_POST['item_id'];
            if(!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] != 0) { throw new Exception("Selecione um arquivo válido."); }
            
            $dir = __DIR__ . '/arquivos/';
            if (!is_dir($dir)) mkdir($dir, 0777, true); 
            
            $nomeOriginal = $_FILES['arquivo']['name'];
            $extensao = pathinfo($nomeOriginal, PATHINFO_EXTENSION);
            $nomeUnico = md5(time().rand()) . '.' . $extensao; 
            
            $caminhoDestino = $dir . $nomeUnico;
            $caminhoRelativo = 'arquivos/' . $nomeUnico; 

            if (move_uploaded_file($_FILES['arquivo']['tmp_name'], $caminhoDestino)) {
                $stmt = $pdo->prepare("INSERT INTO CATALOGO_ARQUIVOS (ITEM_ID, NOME_ARQUIVO, CAMINHO_ARQUIVO) VALUES (?, ?, ?)");
                $stmt->execute([$itemId, $nomeOriginal, $caminhoRelativo]);
                echo json_encode(['success' => true, 'msg' => 'Arquivo salvo no servidor!']);
            } else { throw new Exception("Falha de permissão no servidor ao salvar."); }
            break;

        case 'excluir_arquivo':
            $id = $_POST['id'];
            $stmt = $pdo->prepare("SELECT CAMINHO_ARQUIVO FROM CATALOGO_ARQUIVOS WHERE ID = ?");
            $stmt->execute([$id]);
            $caminho = $stmt->fetchColumn();
            
            if ($caminho) {
                $caminhoCompleto = __DIR__ . '/' . $caminho;
                if (file_exists($caminhoCompleto)) unlink($caminhoCompleto); 
                $pdo->prepare("DELETE FROM CATALOGO_ARQUIVOS WHERE ID = ?")->execute([$id]); 
            }
            echo json_encode(['success' => true, 'msg' => 'Arquivo deletado!']);
            break;

        // ==========================================
        // NOVO MÓDULO: VARIAÇÕES E COMISSÕES
        // ==========================================
        case 'listar_variacoes':
            $itemId = $_POST['item_id'];
            $stmt = $pdo->prepare("SELECT * FROM CATALOGO_VARIACOES WHERE ITEM_ID = ? ORDER BY ID DESC");
            $stmt->execute([$itemId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'salvar_variacao':
            $itemId = $_POST['item_id'];
            $nomeVar = mb_strtoupper(trim($_POST['nome_variacao']), 'UTF-8');
            $preco = (float) $_POST['valor_venda'];
            $tipoCom = $_POST['tipo_comissao'];
            $valCom = (float) $_POST['valor_comissao'];

            $stmt = $pdo->prepare("INSERT INTO CATALOGO_VARIACOES (ITEM_ID, NOME_VARIACAO, VALOR_VENDA, TIPO_COMISSAO, VALOR_COMISSAO) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$itemId, $nomeVar, $preco, $tipoCom, $valCom]);
            
            echo json_encode(['success' => true, 'msg' => 'Variação adicionada!']);
            break;

        case 'mudar_status_variacao':
            $id = $_POST['id'];
            $status = $_POST['status'];
            $stmt = $pdo->prepare("UPDATE CATALOGO_VARIACOES SET STATUS_VARIACAO = ? WHERE ID = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true]);
            break;

        case 'excluir_variacao':
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM CATALOGO_VARIACOES WHERE ID = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não encontrada.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
?>