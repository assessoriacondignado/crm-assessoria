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
        // ==========================================
        // FLUXO DE CAIXA (LÊ AS DUAS TABELAS E UNE)
        // ==========================================
        case 'listar_lancamentos':
            $sql = "SELECT 
                        p.ID, 'SAIDA' AS TIPO_MOVIMENTO, p.STATUS_PAGAMENTO AS CATEGORIA, 
                        COALESCE(h.CAMINHO_HIERARQUIA, 'Sem Categoria de Plano de Contas') AS DESCRICAO, 
                        p.VALOR_PAGO AS VALOR, p.DATA_VENCIMENTO, p.DATA_PAGAMENTO, 
                        p.OBSERVACAO, c.NOME_CONTA,
                        CASE 
                            WHEN p.TIPO_FAVORECIDO = 'VENDEDOR' THEN (SELECT NOME FROM FINANCEIRO_VENDEDORES WHERE ID = p.FAVORECIDO_ID)
                            WHEN p.TIPO_FAVORECIDO = 'ENTIDADE' THEN (SELECT NOME FROM FINANCEIRO_ENTIDADES WHERE ID = p.FAVORECIDO_ID)
                            ELSE NULL 
                        END as NOME_FAVORECIDO,
                        p.CONTA_ID
                    FROM FINANCEIRO_CONTAS_PAGAS p
                    LEFT JOIN FINANCEIRO_CONTA_MOVIMENTACAO c ON p.CONTA_ID = c.ID
                    LEFT JOIN FINANCEIRO_HIERARQUIA_CONTAS h ON p.CATEGORIA_ID = h.ID

                    UNION ALL

                    SELECT 
                        r.ID, 'ENTRADA' AS TIPO_MOVIMENTO, r.STATUS_RECEBIMENTO AS CATEGORIA, 
                        COALESCE(h.CAMINHO_HIERARQUIA, 'Sem Categoria de Plano de Contas') AS DESCRICAO, 
                        r.VALOR_RECEBIDO AS VALOR, r.DATA_VENCIMENTO, r.DATA_RECEBIMENTO AS DATA_PAGAMENTO, 
                        r.OBSERVACAO, c.NOME_CONTA,
                        CASE 
                            WHEN r.TIPO_ENTIDADE = 'VENDEDOR' THEN (SELECT NOME FROM FINANCEIRO_VENDEDORES WHERE ID = r.ENTIDADE_ID)
                            WHEN r.TIPO_ENTIDADE = 'ENTIDADE' THEN (SELECT NOME FROM FINANCEIRO_ENTIDADES WHERE ID = r.ENTIDADE_ID)
                            ELSE NULL 
                        END as NOME_FAVORECIDO,
                        r.CONTA_ID
                    FROM FINANCEIRO_CONTAS_RECEBIDAS r
                    LEFT JOIN FINANCEIRO_CONTA_MOVIMENTACAO c ON r.CONTA_ID = c.ID
                    LEFT JOIN FINANCEIRO_HIERARQUIA_CONTAS h ON r.CATEGORIA_ID = h.ID
                    
                    ORDER BY DATA_VENCIMENTO ASC";
            
            $stmt = $pdo->query($sql);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($dados as &$d) {
                $d['DATA_VENC_BR'] = $d['DATA_VENCIMENTO'] ? date('d/m/Y', strtotime($d['DATA_VENCIMENTO'])) : '--';
                $d['DATA_PAG_BR'] = $d['DATA_PAGAMENTO'] ? date('d/m/Y', strtotime($d['DATA_PAGAMENTO'])) : '--';
            }
            echo json_encode(['success' => true, 'data' => $dados]);
            break;

        case 'salvar_lancamento':
            $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0; 
            $tipo = $_POST['tipo_movimento']; 
            $categoria = $_POST['categoria']; 
            $descricao_caminho = trim($_POST['descricao']); 
            $valor = (float)$_POST['valor']; 
            $vencimento = $_POST['vencimento']; 
            $pagamento = !empty($_POST['pagamento']) ? $_POST['pagamento'] : null; 
            $obs = trim($_POST['obs']); 
            $conta_id = !empty($_POST['conta_id']) ? (int)$_POST['conta_id'] : null;
            $data_concil = !empty($_POST['data_conciliacao']) ? $_POST['data_conciliacao'] : date('Y-m-d'); // Registro manual já nasce conciliado

            // Busca o ID da Categoria Baseado no Caminho
            $stmtCat = $pdo->prepare("SELECT ID FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE CAMINHO_HIERARQUIA = ?");
            $stmtCat->execute([$descricao_caminho]);
            $categoria_id = $stmtCat->fetchColumn() ?: null;

            $favorecido_raw = !empty($_POST['favorecido']) ? $_POST['favorecido'] : null;
            $tipo_favorecido = null; 
            $favorecido_id = null;
            
            if ($favorecido_raw) {
                $partes = explode('_', $favorecido_raw, 2); 
                if (count($partes) == 2) { 
                    $tipo_favorecido = $partes[0]; $id_ou_cpf = $partes[1]; 
                    if ($tipo_favorecido === 'CLIENTENOVO') {
                        $stmtCli = $pdo->prepare("SELECT NOME FROM CLIENTE_CADASTRO WHERE CPF = ?"); $stmtCli->execute([$id_ou_cpf]); $nome_cliente = $stmtCli->fetchColumn();
                        if ($nome_cliente) {
                            $pdo->prepare("INSERT INTO FINANCEIRO_ENTIDADES (DOCUMENTO, NOME, TIPO_VINCULO) VALUES (?, ?, 'CLIENTE')")->execute([$id_ou_cpf, $nome_cliente]);
                            $favorecido_id = (int)$pdo->lastInsertId(); $tipo_favorecido = 'ENTIDADE';
                        }
                    } else { $favorecido_id = (int)$id_ou_cpf; }
                }
            }

            if ($id > 0) { // EDICAO
                if($tipo === 'ENTRADA') {
                    $pdo->prepare("UPDATE FINANCEIRO_CONTAS_RECEBIDAS SET STATUS_RECEBIMENTO = ?, CATEGORIA_ID = ?, VALOR_RECEBIDO = ?, DATA_VENCIMENTO = ?, DATA_RECEBIMENTO = ?, OBSERVACAO = ?, CONTA_ID = ?, TIPO_ENTIDADE = ?, ENTIDADE_ID = ?, DATA_CONCILIACAO = ? WHERE ID = ?")
                        ->execute([$categoria, $categoria_id, $valor, $vencimento, $pagamento, $obs, $conta_id, $tipo_favorecido, $favorecido_id, $data_concil, $id]);
                } else {
                    $pdo->prepare("UPDATE FINANCEIRO_CONTAS_PAGAS SET STATUS_PAGAMENTO = ?, CATEGORIA_ID = ?, VALOR_PAGO = ?, DATA_VENCIMENTO = ?, DATA_PAGAMENTO = ?, OBSERVACAO = ?, CONTA_ID = ?, TIPO_FAVORECIDO = ?, FAVORECIDO_ID = ?, DATA_CONCILIACAO = ? WHERE ID = ?")
                        ->execute([$categoria, $categoria_id, $valor, $vencimento, $pagamento, $obs, $conta_id, $tipo_favorecido, $favorecido_id, $data_concil, $id]);
                }
                echo json_encode(['success' => true, 'msg' => 'Atualizado!']);
            } 
            else { // NOVO 
                if ($tipo === 'TRANSFERENCIA') {
                    $conta_destino_id = !empty($_POST['conta_destino_id']) ? (int)$_POST['conta_destino_id'] : null;
                    $desc_dest_caminho = trim($_POST['descricao_destino']); 
                    $data_pag = $pagamento ? $pagamento : $vencimento;

                    $stmtCatDest = $pdo->prepare("SELECT ID FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE CAMINHO_HIERARQUIA = ?");
                    $stmtCatDest->execute([$desc_dest_caminho]);
                    $categoria_dest_id = $stmtCatDest->fetchColumn() ?: null;

                    $obs_saida = "Transf. enviada | " . $obs;
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_PAGAS (CONTA_ID, CATEGORIA_ID, VALOR_PAGO, DATA_VENCIMENTO, DATA_PAGAMENTO, STATUS_PAGAMENTO, DATA_CONCILIACAO, OBSERVACAO) VALUES (?, ?, ?, ?, ?, 'PAGO', ?, ?)")
                        ->execute([$conta_id, $categoria_id, $valor, $vencimento, $data_pag, $data_concil, $obs_saida]);

                    $obs_entrada = "Transf. recebida | " . $obs;
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_RECEBIDAS (CONTA_ID, CATEGORIA_ID, VALOR_RECEBIDO, DATA_VENCIMENTO, DATA_RECEBIMENTO, STATUS_RECEBIMENTO, DATA_CONCILIACAO, OBSERVACAO) VALUES (?, ?, ?, ?, ?, 'RECEBIDO', ?, ?)")
                        ->execute([$conta_destino_id, $categoria_dest_id, $valor, $vencimento, $data_pag, $data_concil, $obs_entrada]);

                    echo json_encode(['success' => true, 'msg' => 'Transferência registrada!']);
                } elseif($tipo === 'ENTRADA') {
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_RECEBIDAS (STATUS_RECEBIMENTO, CATEGORIA_ID, VALOR_RECEBIDO, DATA_VENCIMENTO, DATA_RECEBIMENTO, DATA_CONCILIACAO, OBSERVACAO, CONTA_ID, TIPO_ENTIDADE, ENTIDADE_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$categoria, $categoria_id, $valor, $vencimento, $pagamento, $data_concil, $obs, $conta_id, $tipo_favorecido, $favorecido_id]);
                    echo json_encode(['success' => true, 'msg' => 'Lançamento de Receita salvo!']);
                } else {
                    $codigo_banco = !empty($_POST['codigo_banco']) ? $_POST['codigo_banco'] : null;
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_PAGAS (STATUS_PAGAMENTO, CATEGORIA_ID, VALOR_PAGO, DATA_VENCIMENTO, DATA_PAGAMENTO, DATA_CONCILIACAO, OBSERVACAO, CONTA_ID, TIPO_FAVORECIDO, FAVORECIDO_ID, CODIGO_BANCO) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$categoria, $categoria_id, $valor, $vencimento, $pagamento, $data_concil, $obs, $conta_id, $tipo_favorecido, $favorecido_id, $codigo_banco]);
                    echo json_encode(['success' => true, 'msg' => 'Lançamento de Despesa salvo!']);
                }
            }
            break;

        case 'excluir_lancamento':
            $id = (int)$_POST['id'];
            $tipo = $_POST['tipo_movimento'];
            if($tipo === 'ENTRADA') { $pdo->prepare("DELETE FROM FINANCEIRO_CONTAS_RECEBIDAS WHERE ID = ?")->execute([$id]); } 
            else { $pdo->prepare("DELETE FROM FINANCEIRO_CONTAS_PAGAS WHERE ID = ?")->execute([$id]); }
            echo json_encode(['success' => true]); 
            break;

        case 'mudar_status_lancamento':
            $id = (int)$_POST['id']; $nova_categoria = $_POST['categoria']; $tipo = $_POST['tipo_movimento'];
            $data_pag = ($nova_categoria == 'PAGO' || $nova_categoria == 'RECEBIDO') ? date('Y-m-d') : null;
            if($tipo === 'ENTRADA') { $pdo->prepare("UPDATE FINANCEIRO_CONTAS_RECEBIDAS SET STATUS_RECEBIMENTO = ?, DATA_RECEBIMENTO = ? WHERE ID = ?")->execute([$nova_categoria, $data_pag, $id]); }
            else { $pdo->prepare("UPDATE FINANCEIRO_CONTAS_PAGAS SET STATUS_PAGAMENTO = ?, DATA_PAGAMENTO = ? WHERE ID = ?")->execute([$nova_categoria, $data_pag, $id]); }
            echo json_encode(['success' => true]); 
            break;

        // ==========================================
        // OUTRAS CONFIGURAÇÕES (Não precisaram mudar)
        // ==========================================
        case 'buscar_favorecidos_dinamico':
            $termo = "%" . trim($_POST['termo'] ?? '') . "%"; $termo_limpo = preg_replace('/[^0-9]/', '', $_POST['termo'] ?? '');
            $stmtV = $pdo->prepare("SELECT ID, NOME, DOCUMENTO_VENDEDOR as DOC, 'VENDEDOR' as TIPO FROM FINANCEIRO_VENDEDORES WHERE NOME LIKE ? OR DOCUMENTO_VENDEDOR LIKE ? LIMIT 5"); $stmtV->execute([$termo, "%$termo_limpo%"]); $vendedores = $stmtV->fetchAll(PDO::FETCH_ASSOC);
            $stmtE = $pdo->prepare("SELECT ID, NOME, DOCUMENTO as DOC, 'ENTIDADE' as TIPO FROM FINANCEIRO_ENTIDADES WHERE NOME LIKE ? OR DOCUMENTO LIKE ? LIMIT 5"); $stmtE->execute([$termo, "%$termo_limpo%"]); $entidades = $stmtE->fetchAll(PDO::FETCH_ASSOC);
            $clientes = []; if (strlen(trim($_POST['termo'] ?? '')) >= 3 || strlen($termo_limpo) >= 3) { $stmtC = $pdo->prepare("SELECT CPF as ID, NOME, CPF as DOC, 'CLIENTENOVO' as TIPO FROM CLIENTE_CADASTRO WHERE (NOME LIKE ? OR CPF LIKE ?) AND CPF NOT IN (SELECT DOCUMENTO FROM FINANCEIRO_ENTIDADES) LIMIT 10"); $stmtC->execute([$termo, "%$termo_limpo%"]); $clientes = $stmtC->fetchAll(PDO::FETCH_ASSOC); }
            echo json_encode(['success' => true, 'data' => array_merge($vendedores, $entidades, $clientes)]); break;
        case 'listar_contas_bancarias':
            echo json_encode(['success' => true, 'data' => $pdo->query("SELECT * FROM FINANCEIRO_CONTA_MOVIMENTACAO ORDER BY NOME_CONTA ASC")->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_conta_bancaria':
            $nome = mb_strtoupper(trim($_POST['nome']), 'UTF-8'); $dados = trim($_POST['dados']); $id = (int)($_POST['id'] ?? 0);
            if($id > 0) { $pdo->prepare("UPDATE FINANCEIRO_CONTA_MOVIMENTACAO SET NOME_CONTA=?, DADOS_BANCARIOS=? WHERE ID=?")->execute([$nome, $dados, $id]); } else { $pdo->prepare("INSERT INTO FINANCEIRO_CONTA_MOVIMENTACAO (NOME_CONTA, DADOS_BANCARIOS) VALUES (?, ?)")->execute([$nome, $dados]); }
            echo json_encode(['success' => true, 'msg' => 'Conta salva!']); break;
        case 'excluir_conta_bancaria':
            $pdo->prepare("DELETE FROM FINANCEIRO_CONTA_MOVIMENTACAO WHERE ID=?")->execute([(int)$_POST['id']]); echo json_encode(['success' => true]); break;
        case 'listar_plano_contas':
            $stmt = $pdo->query("SELECT * FROM FINANCEIRO_HIERARQUIA_CONTAS ORDER BY TIPO ASC, CAMINHO_HIERARQUIA ASC"); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_plano_conta':
            $tipo = $_POST['tipo']; $nome = mb_strtoupper(trim($_POST['nome']), 'UTF-8'); $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null; $id = (int)($_POST['id'] ?? 0); $caminho = $nome;
            if ($parent_id) { $stmt = $pdo->prepare("SELECT CAMINHO_HIERARQUIA FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE ID = ?"); $stmt->execute([$parent_id]); if ($parent_caminho = $stmt->fetchColumn()) { $caminho = $parent_caminho . ' > ' . $nome; } }
            if ($id > 0) { $pdo->prepare("UPDATE FINANCEIRO_HIERARQUIA_CONTAS SET TIPO=?, NOME_CONTA=?, PARENT_ID=?, CAMINHO_HIERARQUIA=? WHERE ID=?")->execute([$tipo, $nome, $parent_id, $caminho, $id]); } else { $pdo->prepare("INSERT INTO FINANCEIRO_HIERARQUIA_CONTAS (TIPO, NOME_CONTA, PARENT_ID, CAMINHO_HIERARQUIA) VALUES (?, ?, ?, ?)")->execute([$tipo, $nome, $parent_id, $caminho]); }
            echo json_encode(['success' => true, 'msg' => 'Plano de Conta salvo!']); break;
        case 'excluir_plano_conta':
            $pdo->prepare("DELETE FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE ID = ?")->execute([(int)$_POST['id']]); echo json_encode(['success' => true]); break;
        case 'mudar_status_plano_conta':
            $pdo->prepare("UPDATE FINANCEIRO_HIERARQUIA_CONTAS SET STATUS = ? WHERE ID = ?")->execute([$_POST['status'], (int)$_POST['id']]); echo json_encode(['success' => true]); break;
        case 'buscar_hierarquia':
            $tipo = $_POST['tipo']; $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
            if ($parent_id === null) { $stmt = $pdo->prepare("SELECT ID, NOME_CONTA, CAMINHO_HIERARQUIA FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE TIPO = ? AND STATUS = 'ATIVO' AND PARENT_ID IS NULL ORDER BY NOME_CONTA ASC"); $stmt->execute([$tipo]); } else { $stmt = $pdo->prepare("SELECT ID, NOME_CONTA, CAMINHO_HIERARQUIA FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE TIPO = ? AND STATUS = 'ATIVO' AND PARENT_ID = ? ORDER BY NOME_CONTA ASC"); $stmt->execute([$tipo, $parent_id]); }
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'buscar_cadastro_base':
            $termo = "%" . trim($_POST['termo'] ?? '') . "%"; $stmtC = $pdo->prepare("SELECT CPF as DOC, NOME, 'CLIENTE' as TIPO FROM CLIENTE_CADASTRO WHERE NOME LIKE ? OR CPF LIKE ? LIMIT 10"); $stmtC->execute([$termo, $termo]); $clientes = $stmtC->fetchAll(PDO::FETCH_ASSOC); $stmtE = $pdo->prepare("SELECT CNPJ as DOC, NOME_CADASTRO as NOME, 'EMPRESA' as TIPO FROM CLIENTE_EMPRESAS WHERE NOME_CADASTRO LIKE ? OR CNPJ LIKE ? LIMIT 10"); $stmtE->execute([$termo, $termo]); $empresas = $stmtE->fetchAll(PDO::FETCH_ASSOC); echo json_encode(['success' => true, 'data' => array_merge($clientes, $empresas)]); break;
        case 'listar_entidades':
            echo json_encode(['success' => true, 'data' => $pdo->query("SELECT * FROM FINANCEIRO_ENTIDADES ORDER BY NOME ASC")->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_entidade':
            $doc = trim($_POST['documento']); $nome = trim($_POST['nome']); $tipo = $_POST['tipo']; $id = (int)($_POST['id'] ?? 0);
            try { if($id > 0) { $pdo->prepare("UPDATE FINANCEIRO_ENTIDADES SET DOCUMENTO=?, NOME=?, TIPO_VINCULO=? WHERE ID=?")->execute([$doc, $nome, $tipo, $id]); echo json_encode(['success' => true, 'msg' => 'Atualizado!']); } else { $pdo->prepare("INSERT INTO FINANCEIRO_ENTIDADES (DOCUMENTO, NOME, TIPO_VINCULO) VALUES (?, ?, ?)")->execute([$doc, $nome, $tipo]); echo json_encode(['success' => true, 'msg' => 'Vinculado!']); } } catch (PDOException $e) { if($e->getCode() == 23000) echo json_encode(['success' => false, 'msg' => 'Documento já vinculado!']); else throw $e; } break;
        case 'excluir_entidade':
            $pdo->prepare("DELETE FROM FINANCEIRO_ENTIDADES WHERE ID = ?")->execute([(int)$_POST['id']]); echo json_encode(['success' => true]); break;
        case 'listar_vendedores':
            echo json_encode(['success' => true, 'data' => $pdo->query("SELECT * FROM FINANCEIRO_VENDEDORES ORDER BY NOME ASC")->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_vendedor':
            $doc = trim($_POST['documento']); $nome = trim($_POST['nome']); $tipo = $_POST['tipo']; $id = (int)($_POST['id'] ?? 0);
            try { if($id > 0) { $pdo->prepare("UPDATE FINANCEIRO_VENDEDORES SET DOCUMENTO_VENDEDOR=?, NOME=?, TIPO_VINCULO=? WHERE ID=?")->execute([$doc, $nome, $tipo, $id]); echo json_encode(['success' => true, 'msg' => 'Atualizado!']); } else { $pdo->prepare("INSERT INTO FINANCEIRO_VENDEDORES (DOCUMENTO_VENDEDOR, NOME, TIPO_VINCULO) VALUES (?, ?, ?)")->execute([$doc, $nome, $tipo]); echo json_encode(['success' => true, 'msg' => 'Vinculado!']); } } catch (PDOException $e) { if($e->getCode() == 23000) echo json_encode(['success' => false, 'msg' => 'Documento já vinculado!']); else throw $e; } break;
        case 'excluir_vendedor':
            $pdo->prepare("DELETE FROM FINANCEIRO_VENDEDORES WHERE ID = ?")->execute([(int)$_POST['id']]); echo json_encode(['success' => true]); break;
        case 'buscar_variacoes_catalogo':
            $sql = "SELECT v.ID, v.NOME_VARIACAO, v.VALOR_VENDA, v.TIPO_COMISSAO, v.VALOR_COMISSAO, p.NOME as PRODUTO_NOME FROM CATALOGO_VARIACOES v INNER JOIN CATALOGO_ITENS p ON v.ITEM_ID = p.ID WHERE v.STATUS_VARIACAO = 'Ativo' AND p.STATUS_ITEM = 'Ativo' ORDER BY p.NOME ASC, v.NOME_VARIACAO ASC"; echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'listar_comissoes_vendedor':
            $stmt = $pdo->prepare("SELECT fv.ID as VINCULO_ID, v.NOME_VARIACAO, v.VALOR_VENDA, v.TIPO_COMISSAO, v.VALOR_COMISSAO, p.NOME as PRODUTO_NOME FROM FINANCEIRO_VENDEDOR_VARIACOES fv INNER JOIN CATALOGO_VARIACOES v ON fv.VARIACAO_ID = v.ID INNER JOIN CATALOGO_ITENS p ON v.ITEM_ID = p.ID WHERE fv.VENDEDOR_ID = ? ORDER BY p.NOME ASC"); $stmt->execute([(int)$_POST['vendedor_id']]); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_comissao_vendedor':
            $id_vendedor = (int)$_POST['vendedor_id']; $id_variacao = (int)$_POST['variacao_id']; $check = $pdo->prepare("SELECT ID FROM FINANCEIRO_VENDEDOR_VARIACOES WHERE VENDEDOR_ID = ? AND VARIACAO_ID = ?"); $check->execute([$id_vendedor, $id_variacao]); if($check->rowCount() > 0) { echo json_encode(['success' => false, 'msg' => 'Este produto já está liberado!']); break; } $pdo->prepare("INSERT INTO FINANCEIRO_VENDEDOR_VARIACOES (VENDEDOR_ID, VARIACAO_ID) VALUES (?, ?)")->execute([$id_vendedor, $id_variacao]); echo json_encode(['success' => true, 'msg' => 'Tabela liberada!']); break;
        case 'excluir_comissao_vendedor':
            $pdo->prepare("DELETE FROM FINANCEIRO_VENDEDOR_VARIACOES WHERE ID = ?")->execute([(int)$_POST['id']]); echo json_encode(['success' => true]); break;
        default: echo json_encode(['success' => false, 'msg' => 'Ação não especificada.']); break;
    }
} catch (Exception $e) { echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); }
?>