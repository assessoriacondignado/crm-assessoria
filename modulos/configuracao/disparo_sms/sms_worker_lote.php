<?php
// Worker de disparo em lote — chamado via cURL em background
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(300);
ignore_user_abort(true);

require_once '../../../conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$lote_id = (int)($_GET['lote_id'] ?? 0);
if (!$lote_id) exit;

// Busca o lote
$stmtL = $pdo->prepare("SELECT * FROM DISPARO_SMS_LOTE WHERE ID=? AND STATUS IN ('PENDENTE','PROCESSANDO') LIMIT 1");
$stmtL->execute([$lote_id]);
$lote = $stmtL->fetch(PDO::FETCH_ASSOC);
if (!$lote) exit;

// Busca configuração da API
$stmtC = $pdo->prepare("SELECT * FROM DISPARO_SMS_CONFIG WHERE ID=? AND ATIVO=1 LIMIT 1");
$stmtC->execute([$lote['CONFIG_ID']]);
$cfg = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$cfg) {
    $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET STATUS='ERRO' WHERE ID=?")->execute([$lote_id]);
    exit;
}

// Marca como processando
$pdo->prepare("UPDATE DISPARO_SMS_LOTE SET STATUS='PROCESSANDO', DATA_INICIO=COALESCE(DATA_INICIO,NOW()) WHERE ID=?")
    ->execute([$lote_id]);

$tempo_ini = time();
$max_seg   = 260; // re-dispara o worker antes de 4min30

// Busca itens pendentes
$stmtI = $pdo->prepare("SELECT * FROM DISPARO_SMS_LOTE_ITENS WHERE LOTE_ID=? AND STATUS='NA_FILA' ORDER BY ID ASC");
$stmtI->execute([$lote_id]);
$itens = $stmtI->fetchAll(PDO::FETCH_ASSOC);

foreach ($itens as $item) {
    // Verifica pausa
    $stmtSt = $pdo->prepare("SELECT STATUS FROM DISPARO_SMS_LOTE WHERE ID=? LIMIT 1");
    $stmtSt->execute([$lote_id]);
    if ($stmtSt->fetchColumn() === 'PAUSADO') break;

    // Limite de tempo — re-dispara worker e sai
    if ((time() - $tempo_ini) > $max_seg) {
        $url = 'http://127.0.0.1/modulos/configuracao/disparo_sms/sms_worker_lote.php?lote_id='.$lote_id;
        $ch  = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_TIMEOUT=>2,CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true]);
        curl_exec($ch); curl_close($ch);
        exit;
    }

    $tel = preg_replace('/\D/','',$item['DESTINATARIO_TEL']);
    if (strlen($tel) < 8) {
        $pdo->prepare("UPDATE DISPARO_SMS_LOTE_ITENS SET STATUS='FALHA',OBSERVACAO='Telefone inválido' WHERE ID=?")->execute([$item['ID']]);
        $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET QTD_FALHA=QTD_FALHA+1 WHERE ID=?")->execute([$lote_id]);
        continue;
    }

    $pid = uniqid('lote_',true);
    $payload = [
        'numero'      => $item['DESTINATARIO_TEL'],
        'servico'     => $lote['SERVICO'] ?: $cfg['SERVICO'],
        'mensagem'    => $item['MENSAGEM_FINAL'],
        'parceiro_id' => $pid,
        'codificacao' => $lote['CODIFICACAO'] ?: '0',
    ];

    $ch = curl_init('https://apihttp.disparopro.com.br:8433/mt');
    curl_setopt_array($ch,[
        CURLOPT_POST           => 1,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer '.$cfg['TOKEN_API'],
            'Content-Type: application/json',
        ],
    ]);
    $res  = curl_exec($ch);
    curl_close($ch);
    $resp = json_decode($res, true) ?? [];

    $ok  = isset($resp['status']) && (int)$resp['status'] === 200;
    $st  = $ok ? 'ENVIADO' : 'FALHA';
    $obs = $ok ? null : (is_array($resp['detail'] ?? null) ? json_encode($resp['detail']) : ($resp['detail'] ?? substr($res,0,200)));

    $pdo->prepare("UPDATE DISPARO_SMS_LOTE_ITENS SET STATUS=?,OBSERVACAO=?,PARCEIRO_ID=?,DATA_ENVIO=NOW() WHERE ID=?")
        ->execute([$st,$obs,$pid,$item['ID']]);

    if ($ok) {
        $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET QTD_ENVIADO=QTD_ENVIADO+1 WHERE ID=?")->execute([$lote_id]);
        // Registra no chat
        $pdo->prepare("INSERT INTO DISPARO_SMS_CHAT (id_empresa,TELEFONE,NOME_CONTATO,TIPO,MENSAGEM,PARCEIRO_ID,STATUS_ENTREGA) VALUES (?,?,?,?,?,?,?)")
            ->execute([$lote['id_empresa'],$item['DESTINATARIO_TEL'],$item['DESTINATARIO_NOME'],'ENVIADO',$item['MENSAGEM_FINAL'],$pid,'ENVIADO']);
        // Registra no histórico geral
        $pdo->prepare("INSERT INTO DISPARO_SMS_DISPAROS
            (id_empresa,id_usuario,CPF_USUARIO,NOME_USUARIO,DESTINATARIO_NOME,DESTINATARIO_TEL,MENSAGEM,SERVICO,CODIFICACAO,PARCEIRO_ID,STATUS,DATA_ENVIO,CONFIG_ID,LOTE_ID)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?)")
            ->execute([$lote['id_empresa'],$lote['id_usuario'],$lote['CPF_USUARIO'],$lote['NOME_USUARIO'],
                       $item['DESTINATARIO_NOME'],$item['DESTINATARIO_TEL'],$item['MENSAGEM_FINAL'],
                       $lote['SERVICO'],$lote['CODIFICACAO'],$pid,'ENVIADO',$lote['CONFIG_ID'],$lote_id]);
    } else {
        $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET QTD_FALHA=QTD_FALHA+1 WHERE ID=?")->execute([$lote_id]);
    }

    usleep(250000); // 250ms entre envios
}

// Verifica se concluiu tudo
$stmtP = $pdo->prepare("SELECT COUNT(*) FROM DISPARO_SMS_LOTE_ITENS WHERE LOTE_ID=? AND STATUS='NA_FILA'");
$stmtP->execute([$lote_id]);
if ((int)$stmtP->fetchColumn() === 0) {
    $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET STATUS='CONCLUIDO',DATA_FIM=NOW() WHERE ID=?")->execute([$lote_id]);
}
