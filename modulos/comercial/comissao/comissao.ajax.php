<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!file_exists($caminho_conexao)) { echo json_encode(['success' => false, 'msg' => "ERRO: conexao.php não encontrado."]); exit; }
    require_once $caminho_conexao; 
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? '';

    switch ($acao) {
        case 'listar_comissoes':
            $status = $_POST['status'] ?? ''; // SEM CONFERENCIA, CONFERIDO, PAGO
            $data_inicio = !empty($_POST['data_inicio']) ? $_POST['data_inicio'] : '2000-01-01';
            $data_fim = !empty($_POST['data_fim']) ? $_POST['data_fim'] : '2099-12-31';
            $vendedor_id = !empty($_POST['vendedor_id']) ? (int)$_POST['vendedor_id'] : 0;

            $sql = "SELECT c.*, p.CODIGO as PEDIDO_CODIGO, p.CLIENTE_NOME, p.PRODUTO_NOME, v.NOME as VENDEDOR_NOME 
                    FROM COMERCIAL_COMISSOES c 
                    INNER JOIN COMERCIAL_PEDIDOS p ON c.PEDIDO_ID = p.ID 
                    INNER JOIN FINANCEIRO_VENDEDORES v ON c.VENDEDOR_ID = v.ID 
                    WHERE c.STATUS_COMISSAO = ? AND c.DATA_BASE BETWEEN ? AND ?";
            $params = [$status, $data_inicio, $data_fim];

            if ($vendedor_id > 0) {
                $sql .= " AND c.VENDEDOR_ID = ?";
                $params[] = $vendedor_id;
            }
            $sql .= " ORDER BY c.DATA_BASE ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Formatar datas para o front-end
            foreach($dados as &$d) {
                $d['DATA_BASE_BR'] = date('d/m/Y', strtotime($d['DATA_BASE']));
                $d['DATA_CONF_BR'] = $d['DATA_CONFERENCIA'] ? date('d/m/Y H:i', strtotime($d['DATA_CONFERENCIA'])) : '--';
                $d['DATA_PAG_BR'] = $d['DATA_PAGAMENTO'] ? date('d/m/Y H:i', strtotime($d['DATA_PAGAMENTO'])) : '--';
            }
            echo json_encode(['success' => true, 'data' => $dados]);
            break;

        case 'avancar_etapa':
            $ids = json_decode($_POST['ids'] ?? '[]');
            $nova_etapa = $_POST['nova_etapa']; // CONFERIDO ou PAGO
            if (empty($ids)) { echo json_encode(['success' => false, 'msg' => 'Nenhuma comissão selecionada.']); break; }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $dataAtual = date('Y-m-d H:i:s');
            
            if ($nova_etapa === 'CONFERIDO') {
                $sql = "UPDATE COMERCIAL_COMISSOES SET STATUS_COMISSAO = 'CONFERIDO', DATA_CONFERENCIA = ? WHERE ID IN ($placeholders)";
                $params = array_merge([$dataAtual], $ids);
            } else if ($nova_etapa === 'PAGO') {
                $sql = "UPDATE COMERCIAL_COMISSOES SET STATUS_COMISSAO = 'PAGO', DATA_PAGAMENTO = ? WHERE ID IN ($placeholders)";
                $params = array_merge([$dataAtual], $ids);
            } else if ($nova_etapa === 'ESTORNADO') {
                $sql = "UPDATE COMERCIAL_COMISSOES SET STATUS_COMISSAO = 'ESTORNADO' WHERE ID IN ($placeholders)";
                $params = $ids;
            }

            $pdo->prepare($sql)->execute($params);
            echo json_encode(['success' => true, 'msg' => 'Comissões atualizadas com sucesso!']);
            break;

        case 'listar_vendedores_filtro':
            $stmt = $pdo->query("SELECT ID, NOME FROM FINANCEIRO_VENDEDORES WHERE STATUS = 'ATIVO' ORDER BY NOME ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        default: echo json_encode(['success' => false, 'msg' => 'Ação não especificada.']); break;
    }
} catch (Exception $e) { echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); }
?>