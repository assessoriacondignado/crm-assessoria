<?php
/**
 * cron_lembretes.php
 * Enviar lembretes automáticos de renovação via WhatsApp
 * Agendar: 0 8 * * * php /var/www/html/modulos/comercial/pedidos/cron_lembretes.php
 */
ini_set('display_errors', 0);
$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!$caminho_conexao || !file_exists($caminho_conexao)) {
    $caminho_conexao = dirname(__DIR__, 3) . '/conexao.php';
}
require_once $caminho_conexao;
if (!isset($pdo) && isset($conn)) $pdo = $conn;

$hoje = date('Y-m-d');
$processados = 0; $erros = 0;

try {
    // Busca lembretes do dia que ainda estão pendentes
    $stL = $pdo->query("
        SELECT l.*, p.CODIGO, p.CLIENTE_NOME, p.PRODUTO_NOME, p.TOTAL, p.CLIENTE_TELEFONE,
               DATE_FORMAT(p.DATA_PREVISTA_RENOVACAO,'%d/%m/%Y') as DATA_PREV_BR
        FROM COMERCIAL_RENOVACAO_LEMBRETES l
        JOIN COMERCIAL_PEDIDOS p ON p.ID = l.PEDIDO_ID
        WHERE l.DATA_ENVIO = '{$hoje}' AND l.STATUS = 'PENDENTE'
    ");
    $lembretes = $stL->fetchAll(PDO::FETCH_ASSOC);

    $inst = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    foreach ($lembretes as $l) {
        $tel = preg_replace('/\D/', '', $l['TELEFONE'] ?: $l['CLIENTE_TELEFONE']);
        if (!$tel) { continue; }

        $dias = (int)$l['DIAS_ANTES'];
        if ($dias === 0) { $prazo = '*HOJE* é o dia do vencimento!'; }
        else { $prazo = "Faltam *{$dias} dia(s)* para o vencimento."; }

        $total = number_format((float)$l['TOTAL'], 2, ',', '.');
        $msg = "⏰ *Lembrete de Renovação*\n\n";
        $msg .= "👤 Cliente: *{$l['CLIENTE_NOME']}*\n";
        $msg .= "📋 Pedido: *{$l['CODIGO']}*\n";
        $msg .= "📦 Produto: {$l['PRODUTO_NOME']}\n";
        $msg .= "💰 Valor: R$ {$total}\n";
        $msg .= "📅 Renovação prevista: {$l['DATA_PREV_BR']}\n\n";
        $msg .= $prazo;

        $ok = false;
        if ($inst) {
            $celular = strlen($tel) <= 11 ? '55' . $tel : $tel;
            $ch = curl_init("https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['phone' => $celular, 'message' => $msg]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $inst['TOKEN']],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            $ok = ($http >= 200 && $http < 300);
        }

        $novoStatus = $ok ? 'ENVIADO' : 'ERRO';
        $pdo->prepare("UPDATE COMERCIAL_RENOVACAO_LEMBRETES SET STATUS=? WHERE ID=?")->execute([$novoStatus, $l['ID']]);
        $ok ? $processados++ : $erros++;
    }
} catch (Exception $e) {
    file_put_contents('/tmp/cron_lembretes_erro.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n", FILE_APPEND);
}

echo date('Y-m-d H:i:s') . " — Lembretes: {$processados} enviados, {$erros} erros.\n";
