<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    $caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!file_exists($caminho_conexao)) { 
        echo json_encode(['success' => false, 'msg' => "ERRO: conexao.php não encontrado."]); 
        exit; 
    }
    require_once $caminho_conexao; 

    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? '';
    $usuarioLogado = isset($_SESSION['usuario_nome']) ? explode(' ', trim($_SESSION['usuario_nome']))[0] : 'Sistema';

    function registrarHistorico($pdo, $itemId, $tipoAcao, $obs, $usuario) {
        $stmt = $pdo->prepare("INSERT INTO COMERCIAL_HISTORICO (MODULO, ITEM_ID, TIPO_ACAO, OBSERVACAO, USUARIO) VALUES ('ENTREGA', ?, ?, ?, ?)");
        $stmt->execute([$itemId, $tipoAcao, $obs, $usuario]);
    }

    switch ($acao) {
        
        case 'buscar_clientes':
            $termo = "%" . trim($_POST['termo'] ?? '') . "%";
            $stmt = $pdo->prepare("SELECT CPF, NOME, CELULAR FROM CLIENTE_CADASTRO WHERE NOME LIKE ? OR CPF LIKE ? LIMIT 15");
            $stmt->execute([$termo, $termo]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'buscar_produtos':
            $stmt = $pdo->query("SELECT ID, NOME FROM CATALOGO_ITENS WHERE STATUS_ITEM = 'Ativo' ORDER BY NOME ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'listar_entregas':
            $stmt = $pdo->query("SELECT * FROM COMERCIAL_ENTREGAS ORDER BY ID DESC");
            $entregas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($entregas as &$e) {
                $e['DATA_PREVISTA_BR'] = $e['DATA_PREVISTA'] ? date('d/m/Y', strtotime($e['DATA_PREVISTA'])) : '--';
                $e['DATA_PREV_REN_BR'] = $e['DATA_PREVISTA_RENOVACAO'] ? date('d/m/Y', strtotime($e['DATA_PREVISTA_RENOVACAO'])) : null;
                $e['DATA_EFET_REN_BR'] = $e['DATA_EFETIVA_RENOVACAO'] ? date('d/m/Y', strtotime($e['DATA_EFETIVA_RENOVACAO'])) : null;
            }
            echo json_encode(['success' => true, 'data' => $entregas]);
            break;

        case 'salvar_entrega':
            $cliente = trim($_POST['cliente']);
            $produto = trim($_POST['produto']);
            $dataPrevista = !empty($_POST['dataPrevista']) ? $_POST['dataPrevista'] : null;
            $obs = trim($_POST['obs']);

            $stmtCount = $pdo->query("SELECT COUNT(ID) FROM COMERCIAL_ENTREGAS");
            $codigo = 'LISTA-' . str_pad($stmtCount->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("INSERT INTO COMERCIAL_ENTREGAS (CODIGO, CLIENTE_NOME, PRODUTO_NOME, OBSERVACAO, DATA_PREVISTA) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$codigo, $cliente, $produto, $obs, $dataPrevista]);
            $itemId = $pdo->lastInsertId();

            registrarHistorico($pdo, $itemId, 'CRIACAO', 'Solicitação de entrega/lista gerada manualmente.', $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => "Solicitação {$codigo} gerada!"]);
            break;

        // ==========================================================
        // NOVAS FUNÇÕES ADICIONADAS PARA LER/EDITAR PEDIDOS VIA ENTREGA
        // ==========================================================
        case 'get_dados_pedido':
            $entrega_id = (int)$_POST['id'];
            
            // 1. Descobre qual é o PEDIDO_ID atrelado a essa entrega
            $stmtEnt = $pdo->prepare("SELECT PEDIDO_ID FROM COMERCIAL_ENTREGAS WHERE ID = ?");
            $stmtEnt->execute([$entrega_id]);
            $entrega = $stmtEnt->fetch(PDO::FETCH_ASSOC);

            if (!$entrega || empty($entrega['PEDIDO_ID'])) {
                echo json_encode(['success' => false, 'msg' => 'Esta entrega foi gerada de forma avulsa e não possui dados financeiros de Pedido atrelados a ela.']);
                exit;
            }

            // 2. Busca os dados reais do Pedido
            $stmtPed = $pdo->prepare("SELECT * FROM COMERCIAL_PEDIDOS WHERE ID = ?");
            $stmtPed->execute([$entrega['PEDIDO_ID']]);
            $pedido = $stmtPed->fetch(PDO::FETCH_ASSOC);

            if ($pedido) {
                echo json_encode(['success' => true, 'data' => $pedido]);
            } else {
                echo json_encode(['success' => false, 'msg' => 'O pedido original não foi encontrado no banco de dados.']);
            }
            break;

        case 'editar_pedido_via_entrega':
            $pedido_id = (int)$_POST['pedido_id'];
            $entrega_id = (int)$_POST['entrega_id'];
            $cliente = trim($_POST['cliente']);
            $produto = trim($_POST['produto']);
            $unitario = (float)$_POST['unitario'];
            $qtd = (int)$_POST['qtd'];
            $acrescimo = (float)$_POST['acrescimo'];
            $variacao = (float)$_POST['variacao'];
            $desconto = (float)$_POST['desconto'];
            $cupom_nome = trim($_POST['cupom_nome']);
            $cupom_val = (float)$_POST['cupom_val'];
            $fidelidade = (float)$_POST['fidelidade'];
            $iva = (float)$_POST['iva'];
            $total = (float)$_POST['total'];
            $prevRenovacao = !empty($_POST['data_prevista']) ? $_POST['data_prevista'] : null;
            $dataRenovacao = !empty($_POST['data_efetiva']) ? $_POST['data_efetiva'] : null;
            $obs = trim($_POST['obs']);

            // Atualiza os valores na tabela de Pedidos
            $sql = "UPDATE COMERCIAL_PEDIDOS SET 
                    CLIENTE_NOME=?, PRODUTO_NOME=?, VALOR_UNITARIO=?, QUANTIDADE=?, ACRESCIMO=?, VARIACAO=?, DESCONTO=?, 
                    CUPOM=?, VALOR_CUPOM=?, FIDELIDADE=?, IVA=?, TOTAL=?, OBSERVACAO=?, DATA_PREVISTA_RENOVACAO=?, DATA_EFETIVA_RENOVACAO=? 
                    WHERE ID=?";
            $pdo->prepare($sql)->execute([$cliente, $produto, $unitario, $qtd, $acrescimo, $variacao, $desconto, $cupom_nome, $cupom_val, $fidelidade, $iva, $total, $obs, $prevRenovacao, $dataRenovacao, $pedido_id]);
            
            // Sincroniza o básico na tabela de Entregas também para não ficar diferente
            $pdo->prepare("UPDATE COMERCIAL_ENTREGAS SET CLIENTE_NOME=?, PRODUTO_NOME=?, DATA_PREVISTA_RENOVACAO=?, DATA_EFETIVA_RENOVACAO=? WHERE ID=?")
                ->execute([$cliente, $produto, $prevRenovacao, $dataRenovacao, $entrega_id]);

            registrarHistorico($pdo, $entrega_id, 'EDIÇÃO', 'Dados financeiros do pedido foram atualizados através da tela de Entregas.', $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Dados do Pedido atualizados com sucesso!']);
            break;
        // ==========================================================

        case 'get_entrega':
            $stmt = $pdo->prepare("SELECT * FROM COMERCIAL_ENTREGAS WHERE ID = ?");
            $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;

        case 'editar_entrega':
            $id = (int)$_POST['id'];
            $prevRenovacao = !empty($_POST['data_prevista']) ? $_POST['data_prevista'] : null;
            $dataRenovacao = !empty($_POST['data_efetiva']) ? $_POST['data_efetiva'] : null;
            
            $sql = "UPDATE COMERCIAL_ENTREGAS SET DATA_PREVISTA_RENOVACAO = ?, DATA_EFETIVA_RENOVACAO = ? WHERE ID = ?";
            $pdo->prepare($sql)->execute([$prevRenovacao, $dataRenovacao, $id]);
            
            registrarHistorico($pdo, $id, 'RENOVAÇÃO', 'Datas de renovação atualizadas.', $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Entrega atualizada com sucesso!']);
            break;

        case 'excluir_entrega':
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM COMERCIAL_ENTREGAS WHERE ID = ?")->execute([$id]);
            echo json_encode(['success' => true, 'msg' => 'Entrega excluída com sucesso!']);
            break;

        case 'mudar_status':
            $id = (int)$_POST['id'];
            $status = $_POST['status'];
            $obs = $_POST['obs'];
            $pdo->prepare("UPDATE COMERCIAL_ENTREGAS SET STATUS_ENTREGA = ? WHERE ID = ?")->execute([$status, $id]);
            registrarHistorico($pdo, $id, "STATUS: {$status}", $obs, $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Status atualizado com sucesso!']);
            break;

        case 'registro_externo':
            $id = (int)$_POST['id'];
            $obs = trim($_POST['obs']);
            registrarHistorico($pdo, $id, 'EXTERNO / WHATSAPP', $obs, $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Aviso registrado!']);
            break;

        case 'carregar_historico':
            $itemId = (int)$_POST['item_id'];
            $modulo = 'ENTREGA'; 
            $stmt = $pdo->prepare("SELECT * FROM COMERCIAL_HISTORICO WHERE MODULO = ? AND ITEM_ID = ? ORDER BY ID DESC");
            $stmt->execute([$modulo, $itemId]);
            $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($hist as &$h) {
                $data_banco = isset($h['DATA_REGISTRO']) ? $h['DATA_REGISTRO'] : date('Y-m-d H:i:s');
                $h['DATA_BR'] = date('d/m/Y H:i', strtotime($data_banco));
            }
            echo json_encode(['success' => true, 'data' => $hist]);
            break;

        default: 
            echo json_encode(['success' => false, 'msg' => 'Ação não especificada.']); 
            break;
    }
} catch (Exception $e) { 
    echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); 
}
?>