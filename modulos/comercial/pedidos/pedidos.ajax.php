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

    // Retorna ['opcao'=>'COMISSAO'|'DESCONTO', 'tipo'=>..., 'valor'=>..., 'calculado'=>0] ou null
    function buscarRegraComissao($pdo, $vendedorId, $variacaoNome) {
        if (!$vendedorId || !$variacaoNome) return null;
        $stmt = $pdo->prepare("SELECT fv.OPCAO_COMISSAO, cv.TIPO_COMISSAO, cv.VALOR_COMISSAO FROM FINANCEIRO_VENDEDOR_VARIACOES fv INNER JOIN CATALOGO_VARIACOES cv ON fv.VARIACAO_ID = cv.ID WHERE fv.VENDEDOR_ID = ? AND cv.NOME_VARIACAO = ? LIMIT 1");
        $stmt->execute([$vendedorId, $variacaoNome]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    function calcularEGerarComissao($pdo, $pedidoId, $vendedorId, $produtoBaseNome, $variacaoNome, $valorTotalVenda) {
        if (empty($vendedorId) || $valorTotalVenda <= 0) return;
        try {
            // Verifica opção do vendedor para esta variação
            $regra = buscarRegraComissao($pdo, $vendedorId, $variacaoNome);

            if (!$regra) {
                // Fallback: busca regra geral via CATALOGO_VARIACOES
                $stmtProd = $pdo->prepare("SELECT ID FROM CATALOGO_ITENS WHERE NOME = ? LIMIT 1");
                $stmtProd->execute([$produtoBaseNome]);
                $produtoId = $stmtProd->fetchColumn();
                if ($produtoId && !empty($variacaoNome)) {
                    $stmtRegraGeral = $pdo->prepare("SELECT TIPO_COMISSAO, VALOR_COMISSAO FROM CATALOGO_VARIACOES WHERE ITEM_ID = ? AND NOME_VARIACAO = ? LIMIT 1");
                    $stmtRegraGeral->execute([$produtoId, $variacaoNome]);
                    $regraGeral = $stmtRegraGeral->fetch(PDO::FETCH_ASSOC);
                    if ($regraGeral) $regra = array_merge(['OPCAO_COMISSAO' => 'COMISSAO'], $regraGeral);
                }
            }

            if ($regra && $regra['VALOR_COMISSAO'] > 0) {
                $valorComissao = strtoupper($regra['TIPO_COMISSAO']) === 'PERCENTUAL'
                    ? $valorTotalVenda * ($regra['VALOR_COMISSAO'] / 100)
                    : (float)$regra['VALOR_COMISSAO'];

                $opcao = $regra['OPCAO_COMISSAO'] ?? 'COMISSAO';

                if ($opcao === 'DESCONTO') {
                    // Desconto já foi aplicado no front e no total do pedido — apenas registra no histórico
                    registrarHistorico($pdo, $pedidoId, 'DESCONTO INDICAÇÃO', "Desconto de R$ " . number_format($valorComissao, 2, ',', '.') . " aplicado (indicação do vendedor ID {$vendedorId}).", 'Robô Financeiro');
                } else {
                    $sqlComissao = "INSERT INTO COMERCIAL_COMISSOES (PEDIDO_ID, VENDEDOR_ID, DATA_BASE, VALOR_BASE_VENDA, VALOR_COMISSAO, STATUS_COMISSAO) VALUES (?, ?, CURDATE(), ?, ?, 'SEM CONFERENCIA')";
                    $pdo->prepare($sqlComissao)->execute([$pedidoId, $vendedorId, $valorTotalVenda, $valorComissao]);
                    registrarHistorico($pdo, $pedidoId, 'COMISSÃO GERADA', "Comissão de R$ " . number_format($valorComissao, 2, ',', '.') . " calculada via sistema.", 'Robô Financeiro');
                }
            }
        } catch (Exception $e) { }
    }

    switch ($acao) {
        case 'buscar_clientes':
            $termo = "%" . trim($_POST['termo'] ?? '') . "%";
            $stmt = $pdo->prepare("SELECT c.CPF, c.NOME, c.CELULAR, c.VENDEDOR_REF_ID, v.NOME as VENDEDOR_NOME FROM CLIENTE_CADASTRO c LEFT JOIN FINANCEIRO_VENDEDORES v ON c.VENDEDOR_REF_ID = v.ID WHERE c.NOME LIKE ? OR c.CPF LIKE ? LIMIT 15");
            $stmt->execute([$termo, $termo]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'buscar_desconto_indicacao':
            // Retorna valor de desconto caso vendedor+variacao tenha OPCAO_COMISSAO=DESCONTO
            $vendedor_id = (int)$_POST['vendedor_id']; $variacao_nome = trim($_POST['variacao_nome'] ?? ''); $total = (float)$_POST['total'];
            if ($vendedor_id <= 0 || empty($variacao_nome) || $total <= 0) { echo json_encode(['desconto' => 0]); break; }
            $stmtV = $pdo->prepare("SELECT fv.OPCAO_COMISSAO, cv.TIPO_COMISSAO, cv.VALOR_COMISSAO FROM FINANCEIRO_VENDEDOR_VARIACOES fv INNER JOIN CATALOGO_VARIACOES cv ON fv.VARIACAO_ID = cv.ID INNER JOIN CATALOGO_ITENS ci ON cv.ITEM_ID = ci.ID WHERE fv.VENDEDOR_ID = ? AND cv.NOME_VARIACAO = ? LIMIT 1");
            $stmtV->execute([$vendedor_id, $variacao_nome]);
            $regra = $stmtV->fetch(PDO::FETCH_ASSOC);
            $desconto = 0;
            if ($regra && $regra['OPCAO_COMISSAO'] === 'DESCONTO' && $regra['VALOR_COMISSAO'] > 0) {
                $desconto = strtoupper($regra['TIPO_COMISSAO']) === 'PERCENTUAL' ? round($total * ($regra['VALOR_COMISSAO'] / 100), 2) : (float)$regra['VALOR_COMISSAO'];
            }
            echo json_encode(['desconto' => $desconto]); break;
        case 'buscar_produtos':
            $stmt = $pdo->query("SELECT ID, NOME FROM CATALOGO_ITENS WHERE STATUS_ITEM = 'Ativo' ORDER BY NOME ASC");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;
        case 'buscar_variacoes':
            $produto_nome = trim($_POST['produto_nome'] ?? '');
            $stmt = $pdo->prepare("SELECT v.ID, v.NOME_VARIACAO, v.VALOR_VENDA, i.TIPO_VENDA FROM CATALOGO_VARIACOES v INNER JOIN CATALOGO_ITENS i ON v.ITEM_ID = i.ID WHERE TRIM(i.NOME) = ? AND (UPPER(v.STATUS_VARIACAO) = 'ATIVO' OR v.STATUS_VARIACAO IS NULL) ORDER BY v.VALOR_VENDA ASC");
            $stmt->execute([$produto_nome]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Busca TIPO_VENDA mesmo sem variações
            $stmtTipo = $pdo->prepare("SELECT TIPO_VENDA FROM CATALOGO_ITENS WHERE TRIM(NOME) = ? LIMIT 1");
            $stmtTipo->execute([$produto_nome]);
            $tipo_venda_produto = $stmtTipo->fetchColumn() ?: 'VENDA';
            echo json_encode(['success' => true, 'data' => $rows, 'tipo_venda' => $tipo_venda_produto]);
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
            $tipo_pedido = in_array($_POST['tipo_pedido'] ?? '', ['COMPRA','RENOVAÇÃO']) ? $_POST['tipo_pedido'] : 'COMPRA';

            $stmtCount = $pdo->query("SELECT COUNT(ID) FROM COMERCIAL_PEDIDOS");
            $codigo = 'PED-' . str_pad($stmtCount->fetchColumn() + 1, 3, '0', STR_PAD_LEFT);

            $sql = "INSERT INTO COMERCIAL_PEDIDOS (CODIGO, CLIENTE_NOME, CLIENTE_TELEFONE, PRODUTO_NOME, VALOR_UNITARIO, QUANTIDADE, ACRESCIMO, VARIACAO, DESCONTO, CUPOM, VALOR_CUPOM, FIDELIDADE, IVA, TOTAL, OBSERVACAO, TIPO_PEDIDO, STATUS_PEDIDO, DATA_PEDIDO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Aguardando Pagamento', NOW())";
            $pdo->prepare($sql)->execute([$codigo, $cliente, $telefone, $produto_final, $unitario, $qtd, $acrescimo, $variacao, $desconto, $cupom_nome, $cupom_val, $fidelidade, $iva, $total, $obs, $tipo_pedido]);
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
            $tipoPedido = in_array($_POST['tipo_pedido'] ?? '', ['COMPRA','RENOVAÇÃO']) ? $_POST['tipo_pedido'] : 'COMPRA';

            $sql = "UPDATE COMERCIAL_PEDIDOS SET CLIENTE_NOME=?, PRODUTO_NOME=?, VALOR_UNITARIO=?, QUANTIDADE=?, ACRESCIMO=?, VARIACAO=?, DESCONTO=?, CUPOM=?, VALOR_CUPOM=?, FIDELIDADE=?, IVA=?, TOTAL=?, OBSERVACAO=?, STATUS_RENOVACAO=?, DATA_PREVISTA_RENOVACAO=?, DATA_EFETIVA_RENOVACAO=?, DATA_PEDIDO=?, DATA_PAGAMENTO=?, DATA_CANCELAMENTO=?, TIPO_PEDIDO=? WHERE ID=?";
            $pdo->prepare($sql)->execute([$cliente, $produto_final, $unitario, $qtd, $acrescimo, $variacao, $desconto, $cupom_nome, $cupom_val, $fidelidade, $iva, $total, $obs, $statusRenovacao, $prevRenovacao, $dataRenovacao, $data_pedido, $data_pagamento, $data_cancelamento, $tipoPedido, $id]);
            
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
        case 'atualizar_renovacao':
            $id         = (int)$_POST['id'];
            $statusRen  = trim($_POST['status_renovacao'] ?? 'A Configurar');
            $dataPrev   = !empty($_POST['data_prevista']) ? $_POST['data_prevista'] : null;
            $obs        = trim($_POST['obs'] ?? '');
            $enviarWpp  = ($_POST['enviar_wpp'] ?? '0') === '1';
            $telefone   = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
            $msgWpp     = trim($_POST['msg_wpp'] ?? '');

            $sql = "UPDATE COMERCIAL_PEDIDOS SET STATUS_RENOVACAO=?, DATA_PREVISTA_RENOVACAO=?";
            $params = [$statusRen, $dataPrev];
            if ($statusRen === 'Renovado') { $sql .= ", DATA_EFETIVA_RENOVACAO=NOW()"; }
            $sql .= " WHERE ID=?"; $params[] = $id;
            $pdo->prepare($sql)->execute($params);
            registrarHistorico($pdo, $id, 'RENOVAÇÃO', "[{$statusRen}] {$obs}", $usuarioLogado);

            // Cria tabela de lembretes se necessário
            try { $pdo->exec("CREATE TABLE IF NOT EXISTS COMERCIAL_RENOVACAO_LEMBRETES (
                ID INT AUTO_INCREMENT PRIMARY KEY,
                PEDIDO_ID INT NOT NULL,
                DIAS_ANTES INT NOT NULL,
                DATA_ENVIO DATE NOT NULL,
                TELEFONE VARCHAR(20),
                STATUS ENUM('PENDENTE','ENVIADO','ERRO') DEFAULT 'PENDENTE',
                DATA_CRIACAO TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_data_envio (DATA_ENVIO, STATUS)
            )"); } catch(Exception $e){}

            // Programa lembretes diários para status ABERTO
            if ($statusRen === 'Aberto' && $dataPrev) {
                $pdo->prepare("DELETE FROM COMERCIAL_RENOVACAO_LEMBRETES WHERE PEDIDO_ID=? AND STATUS='PENDENTE'")->execute([$id]);
                $stmtL = $pdo->prepare("INSERT INTO COMERCIAL_RENOVACAO_LEMBRETES (PEDIDO_ID, DIAS_ANTES, DATA_ENVIO, TELEFONE) VALUES (?,?,?,?)");
                foreach ([5,4,3,2,1,0] as $d) {
                    $dataEnvio = date('Y-m-d', strtotime($dataPrev . " -{$d} days"));
                    if ($dataEnvio >= date('Y-m-d')) { $stmtL->execute([$id, $d, $dataEnvio, $telefone ?: null]); }
                }
            } elseif (in_array($statusRen, ['Renovado','Cancelado','Não Renovado'])) {
                // Cancela lembretes pendentes
                $pdo->prepare("DELETE FROM COMERCIAL_RENOVACAO_LEMBRETES WHERE PEDIDO_ID=? AND STATUS='PENDENTE'")->execute([$id]);
            }

            // Enviar WhatsApp manualmente
            $wapiMsg = '';
            if ($enviarWpp && $telefone && $msgWpp) {
                try {
                    $celular = strlen($telefone) <= 11 ? '55' . $telefone : $telefone;
                    $inst = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                    if ($inst) {
                        $ch = curl_init("https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID']);
                        curl_setopt_array($ch, [
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => json_encode(['phone' => $celular, 'message' => $msgWpp]),
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $inst['TOKEN']],
                            CURLOPT_TIMEOUT => 6,
                            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                        ]);
                        curl_exec($ch); curl_close($ch);
                        $wapiMsg = ' ✅ WhatsApp enviado!';
                    } else { $wapiMsg = ' ⚠️ Nenhuma instância W-API ativa.'; }
                } catch(Exception $e) { $wapiMsg = ' ⚠️ Erro ao enviar WhatsApp.'; }
            }
            echo json_encode(['success' => true, 'msg' => "Renovação atualizada para '{$statusRen}'.{$wapiMsg}"]);
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