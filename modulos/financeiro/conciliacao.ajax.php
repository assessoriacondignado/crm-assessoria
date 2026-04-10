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

    switch ($acao) {
        
        // ==========================================
        // 1. IMPORTAÇÃO DE EXTRATO DO PAGBANK (.CSV)
        // ==========================================
        case 'importar_extrato_pagbank':
            if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] != UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'msg' => 'Nenhum arquivo válido foi enviado.']); exit;
            }

            $tmpName = $_FILES['arquivo_csv']['tmp_name'];

            // Detecta encoding do arquivo (PagBank exporta em Windows-1252 ou UTF-8)
            $conteudoRaw = file_get_contents($tmpName);
            $encodingDetectado = mb_detect_encoding($conteudoRaw, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
            if ($encodingDetectado && $encodingDetectado !== 'UTF-8') {
                $conteudoRaw = mb_convert_encoding($conteudoRaw, 'UTF-8', $encodingDetectado);
            }
            // Remove BOM UTF-8 se presente
            $conteudoRaw = ltrim($conteudoRaw, "\xEF\xBB\xBF");

            $handle = fopen('data://text/plain,' . rawurlencode($conteudoRaw), 'r');
            if ($handle === FALSE) {
                echo json_encode(['success' => false, 'msg' => 'Não foi possível ler o arquivo CSV.']); exit;
            }

            // Formato real do CSV PagBank:
            // CODIGO DA TRANSACAO ; DATA ; TIPO ; DESCRICAO ; VALOR
            // Pular apenas a 1ª linha (cabeçalho)
            fgetcsv($handle, 10000, ";");

            $qtdImportados = 0;
            $qtdIgnorados  = 0;
            $qtdEntradas   = 0;

            while (($row = fgetcsv($handle, 10000, ";")) !== FALSE) {
                if (count($row) < 5) continue;

                $codigo_transacao = trim($row[0]);
                $dataStr          = trim($row[1]);
                $tipo             = mb_strtoupper(trim($row[2]), 'UTF-8');
                $descricao        = mb_strtoupper(trim($row[3]), 'UTF-8');
                $valorStr         = trim($row[4]);

                if (empty($codigo_transacao) || empty($dataStr)) continue;

                // Converter valor: "34,00" ou "-7,99" → float
                $valorNum = (float) str_replace(',', '.', str_replace('.', '', $valorStr));

                // Apenas SAÍDAS (valores negativos)
                if ($valorNum >= 0) { $qtdEntradas++; continue; }

                $valorPositivo = abs($valorNum);

                // Converter "25/03/2026" → "2026-03-25"
                $partes = explode('/', $dataStr);
                if (count($partes) !== 3) continue;
                $dataBanco = $partes[2] . '-' . $partes[1] . '-' . $partes[0];

                // Deduplica pelo CODIGO DA TRANSACAO
                $check = $pdo->prepare("SELECT ID FROM FINANCEIRO_CONTAS_PAGAS WHERE CODIGO_BANCO = ? LIMIT 1");
                $check->execute([$codigo_transacao]);

                if ($check->rowCount() == 0) {
                    $obs = $descricao . ' (' . $tipo . ')';
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_PAGAS (CODIGO_BANCO, OBSERVACAO, VALOR_PAGO, DATA_VENCIMENTO, DATA_PAGAMENTO, STATUS_PAGAMENTO, DATA_CONCILIACAO) VALUES (?, ?, ?, ?, ?, 'PAGO', NULL)")
                        ->execute([$codigo_transacao, $obs, $valorPositivo, $dataBanco, $dataBanco]);
                    $qtdImportados++;
                } else {
                    $qtdIgnorados++;
                }
            }
            fclose($handle);
            echo json_encode(['success' => true, 'msg' => "Importação concluída! ✓ Saídas importadas: {$qtdImportados} | ⚠ Já existiam: {$qtdIgnorados} | ↩ Entradas ignoradas: {$qtdEntradas}."]);
            break;


        // ==========================================
        // 2. LISTAR DESPESAS PENDENTES DE CONCILIAÇÃO
        // ==========================================
        case 'listar_despesas_pendentes':
            // Puxa da tabela PAGAS onde DATA_CONCILIACAO é NULL
            $sql = "SELECT ID, CODIGO_BANCO, OBSERVACAO, VALOR_PAGO, DATA_PAGAMENTO 
                    FROM FINANCEIRO_CONTAS_PAGAS 
                    WHERE DATA_CONCILIACAO IS NULL 
                    ORDER BY DATA_PAGAMENTO DESC";
            $stmt = $pdo->query($sql);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach($dados as &$d) {
                $d['DATA_PAG_BR'] = !empty($d['DATA_PAGAMENTO']) ? date('d/m/Y', strtotime($d['DATA_PAGAMENTO'])) : '--';
            }
            
            echo json_encode(['success' => true, 'data' => $dados]);
            break;


        // ==========================================
        // 3. LISTAR RECEITAS PENDENTES DE CONCILIAÇÃO
        // ==========================================
        case 'listar_receitas_pendentes':
            // Puxa da tabela RECEBIDAS onde DATA_CONCILIACAO é NULL
            $sql = "SELECT r.ID, r.PEDIDO_ID, r.VALOR_RECEBIDO, r.DATA_RECEBIMENTO, r.TIPO_ENTIDADE, r.ENTIDADE_ID,
                           CASE 
                               WHEN r.TIPO_ENTIDADE = 'VENDEDOR' THEN (SELECT NOME FROM FINANCEIRO_VENDEDORES WHERE ID = r.ENTIDADE_ID)
                               WHEN r.TIPO_ENTIDADE = 'ENTIDADE' THEN (SELECT NOME FROM FINANCEIRO_ENTIDADES WHERE ID = r.ENTIDADE_ID)
                               ELSE 'Cliente / Favorecido Não Vinculado'
                           END as NOME_ENTIDADE
                    FROM FINANCEIRO_CONTAS_RECEBIDAS r 
                    WHERE r.DATA_CONCILIACAO IS NULL 
                    ORDER BY r.DATA_RECEBIMENTO DESC";
            $stmt = $pdo->query($sql);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach($dados as &$d) {
                $d['DATA_PAG_BR'] = !empty($d['DATA_RECEBIMENTO']) ? date('d/m/Y', strtotime($d['DATA_RECEBIMENTO'])) : '--';
            }

            echo json_encode(['success' => true, 'data' => $dados]);
            break;


        // ==========================================
        // 4. EXCLUIR PENDÊNCIA (Cancelar)
        // ==========================================
        case 'excluir_pendencia':
            $tipo_fluxo = $_POST['tipo_fluxo']; // DESPESA ou RECEITA
            $id = (int)$_POST['id_origem'];

            if ($tipo_fluxo === 'DESPESA') {
                $pdo->prepare("DELETE FROM FINANCEIRO_CONTAS_PAGAS WHERE ID = ?")->execute([$id]);
            } else {
                $pdo->prepare("DELETE FROM FINANCEIRO_CONTAS_RECEBIDAS WHERE ID = ?")->execute([$id]);
            }
            echo json_encode(['success' => true]);
            break;


        // ==========================================
        // 5. PROCESSAR A CONCILIAÇÃO FINAL
        // ==========================================
        case 'conciliar_registro':
            $tipo_fluxo = $_POST['tipo_fluxo']; // DESPESA ou RECEITA
            $id_tabela = (int)$_POST['id_origem']; 
            $caminho_categoria = $_POST['descricao'];
            $conta_id = (int)$_POST['conta_id'];
            $status = $_POST['categoria']; 
            $favorecido_raw = !empty($_POST['favorecido_id']) ? $_POST['favorecido_id'] : null;
            $obs_complementar = trim($_POST['obs'] ?? '');
            $data_conciliacao = $_POST['data_conciliacao']; 

            if(empty($data_conciliacao)) {
                echo json_encode(['success' => false, 'msg' => 'A Data de Conciliação é obrigatória.']); exit;
            }

            // 1. Achar o ID da Categoria pelo Caminho
            $stmtCat = $pdo->prepare("SELECT ID FROM FINANCEIRO_HIERARQUIA_CONTAS WHERE CAMINHO_HIERARQUIA = ?");
            $stmtCat->execute([$caminho_categoria]);
            $categoria_id = $stmtCat->fetchColumn();

            if (!$categoria_id) {
                echo json_encode(['success' => false, 'msg' => 'Categoria do Plano de Contas inválida.']); exit;
            }

            // 2. Processar o Favorecido (Opcional, pois pode já estar vinculado ou não ter)
            $tipo_fav = null;
            $id_fav = null;
            
            if ($favorecido_raw) {
                $partes = explode('_', $favorecido_raw, 2);
                if (count($partes) == 2) {
                    $tipo_fav = $partes[0];
                    $id_ou_cpf = $partes[1];
                    
                    if ($tipo_fav === 'CLIENTENOVO') {
                        $stmtCli = $pdo->prepare("SELECT NOME FROM CLIENTE_CADASTRO WHERE CPF = ?");
                        $stmtCli->execute([$id_ou_cpf]);
                        $nome_cliente = $stmtCli->fetchColumn();
                        
                        if ($nome_cliente) {
                            $pdo->prepare("INSERT INTO FINANCEIRO_ENTIDADES (DOCUMENTO, NOME, TIPO_VINCULO) VALUES (?, ?, 'CLIENTE')")->execute([$id_ou_cpf, $nome_cliente]);
                            $id_fav = (int)$pdo->lastInsertId();
                            $tipo_fav = 'ENTIDADE';
                        }
                    } else {
                        $id_fav = (int)$id_ou_cpf;
                    }
                }
            }

            // 3. Fazer o UPDATE na tabela correspondente
            if ($tipo_fluxo === 'DESPESA') {
                $sqlUpdate = "UPDATE FINANCEIRO_CONTAS_PAGAS SET 
                              CONTA_ID = ?, 
                              CATEGORIA_ID = ?, 
                              STATUS_PAGAMENTO = ?, 
                              DATA_CONCILIACAO = ?";
                $params = [$conta_id, $categoria_id, $status, $data_conciliacao];

                if (!empty($tipo_fav) && !empty($id_fav)) {
                    $sqlUpdate .= ", TIPO_FAVORECIDO = ?, FAVORECIDO_ID = ?";
                    array_push($params, $tipo_fav, $id_fav);
                }

                if (!empty($obs_complementar)) {
                    $sqlUpdate .= ", OBSERVACAO = CONCAT(OBSERVACAO, ' | ', ?)";
                    $params[] = $obs_complementar;
                }

                $sqlUpdate .= " WHERE ID = ?";
                $params[] = $id_tabela;

                $pdo->prepare($sqlUpdate)->execute($params);

            } else { // RECEITA
                $sqlUpdate = "UPDATE FINANCEIRO_CONTAS_RECEBIDAS SET 
                              CONTA_ID = ?, 
                              CATEGORIA_ID = ?, 
                              STATUS_RECEBIMENTO = ?, 
                              DATA_CONCILIACAO = ?";
                $params = [$conta_id, $categoria_id, $status, $data_conciliacao];

                if (!empty($tipo_fav) && !empty($id_fav)) {
                    $sqlUpdate .= ", TIPO_ENTIDADE = ?, ENTIDADE_ID = ?";
                    array_push($params, $tipo_fav, $id_fav);
                }

                if (!empty($obs_complementar)) {
                    $sqlUpdate .= ", OBSERVACAO = CONCAT(OBSERVACAO, ' | ', ?)";
                    $params[] = $obs_complementar;
                }

                $sqlUpdate .= " WHERE ID = ?";
                $params[] = $id_tabela;

                $pdo->prepare($sqlUpdate)->execute($params);
            }

            echo json_encode(['success' => true, 'msg' => 'Registro conciliado com sucesso!']);
            break;

        default: 
            echo json_encode(['success' => false, 'msg' => 'Ação não especificada.']); 
            break;
    }

} catch (Exception $e) { 
    echo json_encode(['success' => false, 'msg' => "Erro no Backend: " . $e->getMessage()]); 
}
?>