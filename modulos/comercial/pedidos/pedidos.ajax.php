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
        $stmt = $pdo->prepare("INSERT INTO COMERCIAL_HISTORICO (MODULO, ITEM_ID, TIPO_ACAO, OBSERVACAO, USUARIO) VALUES ('PEDIDO', ?, ?, ?, ?)");
        $stmt->execute([$itemId, $tipoAcao, $obs, $usuario]);
    }

    function calcularEGerarComissao($pdo, $pedidoId, $vendedorId, $produtoBaseNome, $variacaoNome, $valorTotalVenda) {
        if (empty($vendedorId) || $valorTotalVenda <= 0) return;
        try {
            $stmtProd = $pdo->prepare("SELECT ID FROM CATALOGO_ITENS WHERE NOME = ? LIMIT 1");
            $stmtProd->execute([$produtoBaseNome]);
            $produtoId = $stmtProd->fetchColumn();
            if (!$produtoId) return;

            $stmtRegra = $pdo->prepare("SELECT TIPO_COMISSAO, VALOR_COMISSAO FROM FINANCEIRO_ENTIDADE_PRODUTOS WHERE ENTIDADE_ID = ? AND PRODUTO_ID = ? LIMIT 1");
            $stmtRegra->execute([$vendedorId, $produtoId]);
            $regra = $stmtRegra->fetch(PDO::FETCH_ASSOC);

            if (!$regra && !empty($variacaoNome)) {
                $stmtRegraGeral = $pdo->prepare("SELECT TIPO_COMISSAO, VALOR_COMISSAO FROM CATALOGO_VARIACOES WHERE ITEM_ID = ? AND NOME_VARIACAO = ? LIMIT 1");
                $stmtRegraGeral->execute([$produtoId, $variacaoNome]);
                $regra = $stmtRegraGeral->fetch(PDO::FETCH_ASSOC);
            }

            if ($regra && $regra['VALOR_COMISSAO'] > 0) {
                $valorComissao = 0;
                if (strtoupper($regra['TIPO_COMISSAO']) === 'PERCENTUAL') {
                    $valorComissao = $valorTotalVenda * ($regra['VALOR_COMISSAO'] / 100);
                } else {
                    $valorComissao = $regra['VALOR_COMISSAO']; 
                }

                $sqlComissao = "INSERT INTO COMERCIAL_COMISSOES (PEDIDO_ID, VENDEDOR_ID, DATA_BASE, VALOR_BASE_VENDA, VALOR_COMISSAO, STATUS_COMISSAO) 
                                VALUES (?, ?, CURDATE(), ?, ?, 'SEM CONFERENCIA')";
                $pdo->prepare($sqlComissao)->execute([$pedidoId, $vendedorId, $valorTotalVenda, $valorComissao]);
                registrarHistorico($pdo, $pedidoId, 'COMISSÃO GERADA', "Comissão de R$ " . number_format($valorComissao, 2, ',', '.') . " calculada via sistema.", 'Robô Financeiro');
            }
        } catch (Exception $e) { }
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
        case 'buscar_variacoes':
            $produto_nome = trim($_POST['produto_nome'] ?? '');
            $stmt = $pdo->prepare("SELECT v.ID, v.NOME_VARIACAO, v.VALOR_VENDA FROM CATALOGO_VARIACOES v INNER JOIN CATALOGO_ITENS i ON v.ITEM_ID = i.ID WHERE TRIM(i.NOME) = ? AND (UPPER(v.STATUS_VARIACAO) = 'ATIVO' OR v.STATUS_VARIACAO IS NULL) ORDER BY v.VALOR_VENDA ASC");
            $stmt->execute([$produto_nome]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'buscar_vendedores':
            $termo = "%" . trim($_POST['termo'] ?? '') . "%";
            $stmt = $pdo->prepare("SELECT ID, NOME, DOCUMENTO_VENDEDOR FROM FINANCEIRO_VENDEDORES WHERE (NOME LIKE ? OR DOCUMENTO_VENDEDOR LIKE ?) AND STATUS = 'ATIVO' LIMIT 10");
            $stmt->execute([$termo, $termo]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'listar_pedidos':
            $stmt = $pdo->query("SELECT * FROM COMERCIAL_PEDIDOS ORDER BY ID DESC");
            $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($pedidos as &$p) {
                $p['DATA_PEDIDO_BR'] = $p['DATA_PEDIDO'] ? date('d/m/Y', strtotime($p['DATA_PEDIDO'])) : null;
                $p['DATA_PAGO_BR'] = $p['DATA_PAGAMENTO'] ? date('d/m/Y', strtotime($p['DATA_PAGAMENTO'])) : null;
                $p['DATA_CANC_BR'] = $p['DATA_CANCELAMENTO'] ? date('d/m/Y', strtotime($p['DATA_CANCELAMENTO'])) : null;
                $p['DATA_PREV_REN_BR'] = $p['DATA_PREVISTA_RENOVACAO'] ? date('d/m/Y', strtotime($p['DATA_PREVISTA_RENOVACAO'])) : null;
                $p['DATA_EFET_REN_BR'] = $p['DATA_EFETIVA_RENOVACAO'] ? date('d/m/Y', strtotime($p['DATA_EFETIVA_RENOVACAO'])) : null;
            }
            echo json_encode(['success' => true, 'data' => $pedidos]);
            break;
        case 'get_pedido':
            $sql = "SELECT p.*, (SELECT v.NOME FROM COMERCIAL_COMISSOES c JOIN FINANCEIRO_VENDEDORES v ON c.VENDEDOR_ID = v.ID WHERE c.PEDIDO_ID = p.ID LIMIT 1) as VENDEDOR_NOME, (SELECT c.VENDEDOR_ID FROM COMERCIAL_COMISSOES c WHERE c.PEDIDO_ID = p.ID LIMIT 1) as VENDEDOR_ID FROM COMERCIAL_PEDIDOS p WHERE p.ID = ?";
            $stmt = $pdo->prepare($sql); $stmt->execute([(int)$_POST['id']]);
            echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;
        case 'salvar_pedido':
            $cliente = trim($_POST['cliente']);
            $telefone = trim($_POST['telefone']);
            $produto_base = trim($_POST['produto_base'] ?? '');
            $produto_variacao = trim($_POST['produto_variacao'] ?? '');
            $produto_final = !empty($produto_variacao) && $produto_variacao !== 'Único' ? $produto_base . ' - ' . $produto_variacao : $produto_base;
            $vendedor_id = !empty($_POST['vendedor_id']) ? (int)$_POST['vendedor_id'] : null;
            $unitario = (float)$_POST['unitario']; $qtd = (int)$_POST['qtd']; $acrescimo = (float)$_POST['acrescimo'];
            $variacao = (float)$_POST['variacao']; $desconto = (float)$_POST['desconto']; $cupom_nome = trim($_POST['cupom_nome']);
            $cupom_val = (float)$_POST['cupom_val']; $fidelidade = (float)$_POST['fidelidade']; $iva = (float)$_POST['iva'];
            $total = (float)$_POST['total']; $obs = trim($_POST['obs']);
            $gerar_entrega = isset($_POST['gerar_entrega']) && $_POST['gerar_entrega'] === '1';

            $stmtCount = $pdo->query("SELECT COUNT(ID) FROM COMERCIAL_PEDIDOS");
            $codigo = 'PED-' . str_pad($stmtCount->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

            $sql = "INSERT INTO COMERCIAL_PEDIDOS (CODIGO, CLIENTE_NOME, CLIENTE_TELEFONE, PRODUTO_NOME, VALOR_UNITARIO, QUANTIDADE, ACRESCIMO, VARIACAO, DESCONTO, CUPOM, VALOR_CUPOM, FIDELIDADE, IVA, TOTAL, OBSERVACAO, STATUS_PEDIDO, DATA_PEDIDO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aguardando Pagamento', NOW())";
            $pdo->prepare($sql)->execute([$codigo, $cliente, $telefone, $produto_final, $unitario, $qtd, $acrescimo, $variacao, $desconto, $cupom_nome, $cupom_val, $fidelidade, $iva, $total, $obs]);
            $itemId = $pdo->lastInsertId();

            registrarHistorico($pdo, $itemId, 'CRIACAO', "Pedido gerado no valor de R$ ".number_format($total, 2, ',', '.'), $usuarioLogado);
            if ($vendedor_id) { calcularEGerarComissao($pdo, $itemId, $vendedor_id, $produto_base, $produto_variacao, $total); }
            if ($gerar_entrega) {
                $stmtCountEnt = $pdo->query("SELECT COUNT(ID) FROM COMERCIAL_ENTREGAS");
                $codigo_entrega = 'LISTA-' . str_pad($stmtCountEnt->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO COMERCIAL_ENTREGAS (PEDIDO_ID, CODIGO, CLIENTE_NOME, PRODUTO_NOME, OBSERVACAO, DATA_PREVISTA) VALUES (?, ?, ?, ?, ?, NULL)")->execute([$itemId, $codigo_entrega, $cliente, $produto_final, "Gerado automaticamente junto com o Pedido."]);
                registrarHistorico($pdo, $itemId, 'ENTREGA GERADA', "A entrega {$codigo_entrega} foi criada automaticamente para o setor de Logística.", $usuarioLogado);
            }
            echo json_encode(['success' => true, 'msg' => "Pedido {$codigo} gerado com sucesso!"]);
            break;
        case 'editar_pedido_completo':
            $id = (int)$_POST['id']; $cliente = trim($_POST['cliente']);
            $produto_base = trim($_POST['produto_base'] ?? ''); $produto_variacao = trim($_POST['produto_variacao'] ?? '');
            $produto_final = !empty($produto_variacao) && $produto_variacao !== 'Único' ? $produto_base . ' - ' . $produto_variacao : $produto_base;
            $vendedor_id = !empty($_POST['vendedor_id']) ? (int)$_POST['vendedor_id'] : null;
            $unitario = (float)$_POST['unitario']; $qtd = (int)$_POST['qtd']; $acrescimo = (float)$_POST['acrescimo'];
            $variacao = (float)$_POST['variacao']; $desconto = (float)$_POST['desconto']; $cupom_nome = trim($_POST['cupom_nome']);
            $cupom_val = (float)$_POST['cupom_val']; $fidelidade = (float)$_POST['fidelidade']; $iva = (float)$_POST['iva'];
            $total = (float)$_POST['total']; $obs = trim($_POST['obs']);
            
            $data_pedido = !empty($_POST['data_pedido']) ? $_POST['data_pedido'] : null;
            $data_pagamento = !empty($_POST['data_pagamento']) ? $_POST['data_pagamento'] : null;
            $data_cancelamento = !empty($_POST['data_cancelamento']) ? $_POST['data_cancelamento'] : null;
            $statusRenovacao = !empty($_POST['status_renovacao']) ? trim($_POST['status_renovacao']) : 'A Configurar';
            $prevRenovacao = !empty($_POST['data_prevista']) ? $_POST['data_prevista'] : null;
            $dataRenovacao = !empty($_POST['data_efetiva']) ? $_POST['data_efetiva'] : null;
            
            $sql = "UPDATE COMERCIAL_PEDIDOS SET CLIENTE_NOME=?, PRODUTO_NOME=?, VALOR_UNITARIO=?, QUANTIDADE=?, ACRESCIMO=?, VARIACAO=?, DESCONTO=?, CUPOM=?, VALOR_CUPOM=?, FIDELIDADE=?, IVA=?, TOTAL=?, OBSERVACAO=?, STATUS_RENOVACAO=?, DATA_PREVISTA_RENOVACAO=?, DATA_EFETIVA_RENOVACAO=?, DATA_PEDIDO=?, DATA_PAGAMENTO=?, DATA_CANCELAMENTO=? WHERE ID=?";
            $pdo->prepare($sql)->execute([$cliente, $produto_final, $unitario, $qtd, $acrescimo, $variacao, $desconto, $cupom_nome, $cupom_val, $fidelidade, $iva, $total, $obs, $statusRenovacao, $prevRenovacao, $dataRenovacao, $data_pedido, $data_pagamento, $data_cancelamento, $id]);
            
            registrarHistorico($pdo, $id, 'EDIÇÃO', 'Dados completos do pedido atualizados.', $usuarioLogado);
            $pdo->prepare("DELETE FROM COMERCIAL_COMISSOES WHERE PEDIDO_ID = ? AND STATUS_COMISSAO != 'PAGO'")->execute([$id]);
            if ($vendedor_id) { calcularEGerarComissao($pdo, $id, $vendedor_id, $produto_base, $produto_variacao, $total); }
            echo json_encode(['success' => true, 'msg' => 'Pedido atualizado!']);
            break;
        case 'gerar_entrega':
            $pedido_id = (int)$_POST['pedido_id']; $data_prev = !empty($_POST['data_prev']) ? $_POST['data_prev'] : null; $obs_entrega = trim($_POST['obs']);
            $stmt = $pdo->prepare("SELECT CLIENTE_NOME, PRODUTO_NOME FROM COMERCIAL_PEDIDOS WHERE ID = ?"); $stmt->execute([$pedido_id]); $ped = $stmt->fetch();
            if(!$ped) { echo json_encode(['success' => false, 'msg' => 'Pedido não localizado.']); exit; }
            $stmtCount = $pdo->query("SELECT COUNT(ID) FROM COMERCIAL_ENTREGAS");
            $codigo_entrega = 'LISTA-' . str_pad($stmtCount->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO COMERCIAL_ENTREGAS (PEDIDO_ID, CODIGO, CLIENTE_NOME, PRODUTO_NOME, OBSERVACAO, DATA_PREVISTA) VALUES (?, ?, ?, ?, ?, ?)")->execute([$pedido_id, $codigo_entrega, $ped['CLIENTE_NOME'], $ped['PRODUTO_NOME'], "Gerado via Pedido. " . $obs_entrega, $data_prev]);
            registrarHistorico($pdo, $pedido_id, 'ENTREGA GERADA', "A entrega {$codigo_entrega} foi criada para o setor de Logística.", $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => "Ficha de Entrega {$codigo_entrega} enviada para a Logística!"]);
            break;
        case 'excluir_pedido':
            $id = (int)$_POST['id']; $pdo->prepare("DELETE FROM COMERCIAL_PEDIDOS WHERE ID = ?")->execute([$id]);
            echo json_encode(['success' => true, 'msg' => 'Pedido excluído com sucesso!']);
            break;
        case 'mudar_status':
            $id = (int)$_POST['id']; $status = $_POST['status']; $obs = $_POST['obs'];
            $sql = "UPDATE COMERCIAL_PEDIDOS SET STATUS_PEDIDO = ?"; $params = [$status];
            if ($status === 'Pago') { $sql .= ", DATA_PAGAMENTO = NOW()"; } 
            elseif ($status === 'Cancelado') { $sql .= ", DATA_CANCELAMENTO = NOW()"; }
            $sql .= " WHERE ID = ?"; $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            
            // INTEGRAÇÃO FINANCEIRO: Joga pra Fila de Receitas
            if ($status === 'Pago') {
                $stmtPed = $pdo->prepare("SELECT TOTAL, CLIENTE_NOME FROM COMERCIAL_PEDIDOS WHERE ID = ?");
                $stmtPed->execute([$id]);
                $ped = $stmtPed->fetch(PDO::FETCH_ASSOC);
                if ($ped) {
                    $stmtEnt = $pdo->prepare("SELECT ID FROM FINANCEIRO_ENTIDADES WHERE NOME = ? LIMIT 1");
                    $stmtEnt->execute([$ped['CLIENTE_NOME']]);
                    $ent = $stmtEnt->fetch(PDO::FETCH_ASSOC);
                    $entidade_id = $ent ? $ent['ID'] : null;
                    $tipo_entidade = $ent ? 'ENTIDADE' : null;

                    $sqlRec = "INSERT INTO FINANCEIRO_CONTAS_RECEBIDAS (PEDIDO_ID, TIPO_ENTIDADE, ENTIDADE_ID, VALOR_RECEBIDO, DATA_RECEBIMENTO, STATUS_RECEBIMENTO, OBSERVACAO) VALUES (?, ?, ?, ?, CURDATE(), 'RECEBIDO', ?)";
                    $pdo->prepare($sqlRec)->execute([$id, $tipo_entidade, $entidade_id, $ped['TOTAL'], "Automático: Baixa via Módulo de Pedidos"]);
                }
            }
            registrarHistorico($pdo, $id, "STATUS: {$status}", $obs, $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Status atualizado!']);
            break;
        case 'registro_externo':
            $id = (int)$_POST['id']; $obs = trim($_POST['obs']); registrarHistorico($pdo, $id, 'WHATSAPP ENVIADO', $obs, $usuarioLogado);
            echo json_encode(['success' => true, 'msg' => 'Aviso ao cliente registrado!']); break;
        case 'carregar_historico':
            $itemId = (int)$_POST['item_id']; $modulo = $_POST['modulo']; 
            $stmt = $pdo->prepare("SELECT * FROM COMERCIAL_HISTORICO WHERE MODULO = ? AND ITEM_ID = ? ORDER BY ID DESC"); $stmt->execute([$modulo, $itemId]);
            $hist = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($hist as &$h) { $h['DATA_BR'] = date('d/m/Y H:i', strtotime(isset($h['DATA_REGISTRO']) ? $h['DATA_REGISTRO'] : date('Y-m-d H:i:s'))); }
            echo json_encode(['success' => true, 'data' => $hist]); break;
        default: echo json_encode(['success' => false, 'msg' => 'Ação não especificada.']); break;
    }
} catch (Exception $e) { echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); }
?>