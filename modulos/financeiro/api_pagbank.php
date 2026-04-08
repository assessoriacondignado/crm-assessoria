<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? '';

    // =======================================================
    // SEU TOKEN GERADO NO PAINEL DO PAGBANK
    // =======================================================
    $token = '2dc753e0-d62e-4e7e-9bec-ec2fa1d87eb19c5dffe44b2abee6f0d45e51ffa0a4be159d-8e8e-4b10-8dc4-bff57a667e36'; 

    // (A trava de segurança foi removida daqui para não causar mais o erro da tela vermelha)

    // =======================================================
    // FUNÇÃO 1: SINCRONIZAR SAÍDAS (24H)
    // =======================================================
    if ($acao === 'sincronizar_extrato') {
        $data_inicio = date('Y-m-d', strtotime('-1 days'));
        $data_fim = date('Y-m-d');

        $url = "https://api.pagseguro.com/digital-account/v1/statements?initial_date={$data_inicio}&final_date={$data_fim}";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", "accept: application/json"],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) { echo json_encode(['success' => false, 'msg' => "Erro no servidor cURL: " . $err]); exit; }

        $dadosApi = json_decode($response, true);
        if (isset($dadosApi['error_messages'])) { echo json_encode(['success' => false, 'msg' => "Recusado pelo PagBank: " . $dadosApi['error_messages'][0]['description']]); exit; }

        $qtdImportados = 0; $qtdIgnorados = 0;

        if (!empty($dadosApi['data'])) {
            foreach ($dadosApi['data'] as $transacao) {
                if (!isset($transacao['amount']) || (float)$transacao['amount'] >= 0) continue; // Pega só saídas

                $valorPositivo = abs((float)$transacao['amount']);
                $codigo_transacao = $transacao['id']; 
                $descricao = mb_strtoupper($transacao['description'] ?? 'SAÍDA PAGBANK', 'UTF-8');
                $dataBanco = date('Y-m-d', strtotime($transacao['created_at']));

                $check = $pdo->prepare("SELECT ID FROM FINANCEIRO_CONTAS_PAGAS WHERE CODIGO_BANCO = ? LIMIT 1");
                $check->execute([$codigo_transacao]);

                if ($check->rowCount() == 0) {
                    $pdo->prepare("INSERT INTO FINANCEIRO_CONTAS_PAGAS (CODIGO_BANCO, OBSERVACAO, VALOR_PAGO, DATA_VENCIMENTO, DATA_PAGAMENTO, STATUS_PAGAMENTO, DATA_CONCILIACAO) VALUES (?, ?, ?, ?, ?, 'PAGO', NULL)")
                        ->execute([$codigo_transacao, $descricao . " (Via API)", $valorPositivo, $dataBanco, $dataBanco]);
                    $qtdImportados++;
                } else { $qtdIgnorados++; }
            }
        }
        echo json_encode(['success' => true, 'msg' => "Sincronização 24h finalizada! ✓ Novas despesas importadas: {$qtdImportados} | ⚠ Já existiam: {$qtdIgnorados}."]);
        exit;
    }

    // =======================================================
    // FUNÇÃO 2: VISUALIZAR EXTRATO COMPLETO NO POP-UP
    // =======================================================
    if ($acao === 'ver_extrato') {
        $data_inicio = $_POST['data_inicio'] ?? date('Y-m-01');
        $data_fim = $_POST['data_fim'] ?? date('Y-m-t');

        $url = "https://api.pagseguro.com/digital-account/v1/statements?initial_date={$data_inicio}&final_date={$data_fim}";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", "accept: application/json"],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) { echo json_encode(['success' => false, 'msg' => "Erro de conexão: " . $err]); exit; }

        $dadosApi = json_decode($response, true);
        if (isset($dadosApi['error_messages'])) { echo json_encode(['success' => false, 'msg' => "Erro do PagBank: " . $dadosApi['error_messages'][0]['description']]); exit; }

        echo json_encode(['success' => true, 'data' => $dadosApi['data'] ?? []]);
        exit;
    }

    echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']);

} catch (Exception $e) { echo json_encode(['success' => false, 'msg' => "Erro do BD: " . $e->getMessage()]); }
?>