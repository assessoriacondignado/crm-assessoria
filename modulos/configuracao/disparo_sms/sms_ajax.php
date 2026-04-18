<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

if (!isset($_SESSION['usuario_cpf'])) { echo json_encode(['success'=>false,'msg'=>'Sessão expirada.']); exit; }
if (!function_exists('verificaPermissao') || !verificaPermissao($pdo, 'SUBMENU_SMS', 'TELA')) {
    echo json_encode(['success'=>false,'msg'=>'Sem permissão.']); exit;
}

$cpf_logado   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$nome_logado  = $_SESSION['usuario_nome'] ?? '';
$grupo_logado = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master    = in_array($grupo_logado, ['MASTER','ADMIN','ADMINISTRADOR']);
$is_supervisor = ($grupo_logado === 'SUPERVISOR');

$id_usuario_logado = null;
$id_empresa_logado = null;
try {
    $stmtU = $pdo->prepare("SELECT ID, id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtU->execute([$cpf_logado]);
    $rowU = $stmtU->fetch(PDO::FETCH_ASSOC);
    $id_usuario_logado = (int)($rowU['ID'] ?? 0);
    $id_empresa_logado = (int)($rowU['id_empresa'] ?? 0);
} catch (Exception $e) {}

// ─── Criação automática das tabelas ─────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_CONFIG` (
    `ID`           int NOT NULL AUTO_INCREMENT,
    `id_empresa`   int DEFAULT NULL,
    `NOME_CONFIG`  varchar(100) NOT NULL,
    `TOKEN_API`    varchar(255) NOT NULL,
    `SERVICO`      varchar(50) DEFAULT 'short',
    `ATIVO`        tinyint(1) DEFAULT 1,
    `DATA_CRIACAO` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`), KEY `idx_emp` (`id_empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_DISPAROS` (
    `ID`                int NOT NULL AUTO_INCREMENT,
    `id_empresa`        int DEFAULT NULL,
    `id_usuario`        int DEFAULT NULL,
    `CPF_USUARIO`       varchar(14) DEFAULT NULL,
    `NOME_USUARIO`      varchar(150) DEFAULT NULL,
    `DESTINATARIO_NOME` varchar(150) DEFAULT NULL,
    `DESTINATARIO_TEL`  varchar(20) NOT NULL,
    `MENSAGEM`          text DEFAULT NULL,
    `SERVICO`           varchar(50) DEFAULT 'short',
    `CODIFICACAO`       varchar(5) DEFAULT '0',
    `PARCEIRO_ID`       varchar(150) DEFAULT NULL,
    `STATUS`            varchar(50) DEFAULT 'AGUARDANDO',
    `DATA_AGENDAMENTO`  datetime DEFAULT NULL,
    `DATA_ENVIO`        datetime DEFAULT NULL,
    `RESPOSTA_API`      text DEFAULT NULL,
    `LOTE_ID`           int DEFAULT NULL,
    `CONFIG_ID`         int DEFAULT NULL,
    `DATA_CRIACAO`      datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    KEY `idx_emp` (`id_empresa`), KEY `idx_usr` (`id_usuario`),
    KEY `idx_lote` (`LOTE_ID`),   KEY `idx_status` (`STATUS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_LOTE` (
    `ID`                int NOT NULL AUTO_INCREMENT,
    `id_empresa`        int DEFAULT NULL,
    `id_usuario`        int DEFAULT NULL,
    `CPF_USUARIO`       varchar(14) DEFAULT NULL,
    `NOME_USUARIO`      varchar(150) DEFAULT NULL,
    `NOME_LOTE`         varchar(200) DEFAULT NULL,
    `MENSAGEM_TEMPLATE` text DEFAULT NULL,
    `QTD_TOTAL`         int DEFAULT 0,
    `QTD_ENVIADO`       int DEFAULT 0,
    `QTD_FALHA`         int DEFAULT 0,
    `STATUS`            enum('PENDENTE','PROCESSANDO','CONCLUIDO','PAUSADO','ERRO') DEFAULT 'PENDENTE',
    `DATA_AGENDAMENTO`  datetime DEFAULT NULL,
    `DATA_INICIO`       datetime DEFAULT NULL,
    `DATA_FIM`          datetime DEFAULT NULL,
    `CONFIG_ID`         int DEFAULT NULL,
    `SERVICO`           varchar(50) DEFAULT 'short',
    `CODIFICACAO`       varchar(5) DEFAULT '0',
    `DATA_CRIACAO`      datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    KEY `idx_emp` (`id_empresa`), KEY `idx_usr` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_LOTE_ITENS` (
    `ID`                int NOT NULL AUTO_INCREMENT,
    `LOTE_ID`           int NOT NULL,
    `DESTINATARIO_NOME` varchar(150) DEFAULT NULL,
    `DESTINATARIO_TEL`  varchar(20) NOT NULL,
    `TAG1`              varchar(200) DEFAULT NULL,
    `TAG2`              varchar(200) DEFAULT NULL,
    `TAG3`              varchar(200) DEFAULT NULL,
    `MENSAGEM_FINAL`    text DEFAULT NULL,
    `PARCEIRO_ID`       varchar(150) DEFAULT NULL,
    `STATUS`            varchar(50) DEFAULT 'NA_FILA',
    `OBSERVACAO`        text DEFAULT NULL,
    `DATA_ENVIO`        datetime DEFAULT NULL,
    PRIMARY KEY (`ID`), KEY `idx_lote` (`LOTE_ID`), KEY `idx_status` (`STATUS`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_CHAT` (
    `ID`             int NOT NULL AUTO_INCREMENT,
    `id_empresa`     int DEFAULT NULL,
    `TELEFONE`       varchar(20) NOT NULL,
    `NOME_CONTATO`   varchar(150) DEFAULT NULL,
    `TIPO`           enum('ENVIADO','RECEBIDO') NOT NULL,
    `MENSAGEM`       text DEFAULT NULL,
    `PARCEIRO_ID`    varchar(150) DEFAULT NULL,
    `STATUS_ENTREGA` varchar(50) DEFAULT NULL,
    `DATA_HORA`      datetime DEFAULT CURRENT_TIMESTAMP,
    `LIDO`           tinyint(1) DEFAULT 0,
    PRIMARY KEY (`ID`),
    KEY `idx_emp` (`id_empresa`), KEY `idx_tel` (`TELEFONE`), KEY `idx_lido` (`LIDO`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Helpers ────────────────────────────────────────────────────────────────
function smsApiCall(string $method, string $endpoint, string $token, array $data = []): array {
    $url = 'https://apihttp.disparopro.com.br:8433/' . ltrim($endpoint, '/');
    $ch  = curl_init();
    if ($method === 'GET' && $data) $url .= '?' . http_build_query($data);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.$token, 'Content-Type: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['_erro' => 'cURL: '.$err];
    return json_decode($res, true) ?? ['_erro' => 'Resposta inválida', '_raw' => substr($res,0,200)];
}

function smsGetConfig(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM DISPARO_SMS_CONFIG WHERE ID=? AND ATIVO=1 LIMIT 1");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function smsAplicarTags(string $tpl, string $nome, string $t1='', string $t2='', string $t3=''): string {
    $p = explode(' ', trim($nome));
    $prim = $p[0] ?? $nome;
    $disc = count($p) >= 2 ? $prim.'***'.$p[count($p)-1] : $prim;
    return str_replace(
        ['{PRIMEIRO_NOME}','{NOME_COMPLETO}','{NOME_DISCRETO}','{TAG1}','{TAG2}','{TAG3}'],
        [$prim, $nome, $disc, $t1, $t2, $t3], $tpl
    );
}

function smsFmtTel(string $n): string {
    $n = preg_replace('/\D/','',$n);
    if (strlen($n)===13) $n = substr($n,2);
    if (strlen($n)===11) return '('.substr($n,0,2).') '.substr($n,2,5).'-'.substr($n,7);
    if (strlen($n)===10) return '('.substr($n,0,2).') '.substr($n,2,4).'-'.substr($n,6);
    return $n;
}

function smsWhere(bool $master, bool $super, ?int $empresa, ?int $usuario): string {
    if ($master) return '1=1';
    if ($super && $empresa) return "id_empresa=$empresa";
    return "id_usuario=$usuario";
}

function smsTelApi(string $t): string {
    $t = preg_replace('/\D/','',$t);
    return (strlen($t) <= 11 && substr($t,0,2)!=='55') ? '55'.$t : $t;
}

// ─── Roteamento ─────────────────────────────────────────────────────────────
$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

try { switch ($acao) {

// ════════════════════════════════════════════
// BUSCAR CLIENTE
// ════════════════════════════════════════════
case 'buscar_cliente':
    $t = trim($_POST['termo'] ?? '');
    if (strlen($t) < 2) throw new Exception("Mínimo 2 caracteres.");
    $tl = preg_replace('/\D/','',$t);
    $rows = [];
    if (strlen($tl) >= 3) {
        $s = $pdo->prepare("SELECT dc.CPF, dc.NOME,
            (SELECT t2.telefone_cel FROM telefones t2 WHERE t2.cpf=dc.CPF ORDER BY t2.id ASC LIMIT 1) AS TELEFONE
            FROM dados_cadastrais dc WHERE dc.CPF LIKE ? LIMIT 20");
        $s->execute(['%'.$tl.'%']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $s = $pdo->prepare("SELECT dc.CPF, dc.NOME, tel.telefone_cel AS TELEFONE
                FROM telefones tel JOIN dados_cadastrais dc ON dc.CPF=tel.cpf
                WHERE tel.telefone_cel LIKE ? LIMIT 20");
            $s->execute(['%'.$tl.'%']);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $s = $pdo->prepare("SELECT dc.CPF, dc.NOME,
            (SELECT t2.telefone_cel FROM telefones t2 WHERE t2.cpf=dc.CPF ORDER BY t2.id ASC LIMIT 1) AS TELEFONE
            FROM dados_cadastrais dc WHERE dc.NOME LIKE ? ORDER BY dc.NOME LIMIT 20");
        $s->execute(['%'.$t.'%']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as &$r) {
        $r['CPF_FMT'] = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/','$1.$2.$3-$4',str_pad($r['CPF'],11,'0',STR_PAD_LEFT));
        $r['TEL_FMT'] = smsFmtTel($r['TELEFONE'] ?? '');
    }
    echo json_encode(['success'=>true,'clientes'=>$rows]); exit;

// ════════════════════════════════════════════
// LISTAR CONFIGS
// ════════════════════════════════════════════
case 'listar_configs':
    if ($is_master) {
        $s = $pdo->query("SELECT * FROM DISPARO_SMS_CONFIG ORDER BY ID ASC");
    } else {
        $s = $pdo->prepare("SELECT * FROM DISPARO_SMS_CONFIG WHERE id_empresa=? ORDER BY ID ASC");
        $s->execute([$id_empresa_logado]);
    }
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $tok = $r['TOKEN_API'] ?? '';
        $r['TOKEN_MASK'] = strlen($tok)>8 ? substr($tok,0,4).'••••'.substr($tok,-4) : '••••';
    }
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

// ════════════════════════════════════════════
// SALVAR CONFIG
// ════════════════════════════════════════════
case 'salvar_config':
    if (!verificaPermissao($pdo,'SUBMENU_CARTEIRA','TELA')) throw new Exception("Sem permissão para gerenciar configurações.");
    $id      = (int)($_POST['id'] ?? 0);
    $nome    = trim($_POST['nome'] ?? '');
    $token   = trim($_POST['token'] ?? '');
    $servico = trim($_POST['servico'] ?? 'short');
    $emp     = $is_master ? (int)($_POST['id_empresa'] ?? $id_empresa_logado) : $id_empresa_logado;
    if (!$nome || !$token) throw new Exception("Nome e token obrigatórios.");
    if ($id) {
        $pdo->prepare("UPDATE DISPARO_SMS_CONFIG SET NOME_CONFIG=?,TOKEN_API=?,SERVICO=?,id_empresa=? WHERE ID=?")
            ->execute([$nome,$token,$servico,$emp,$id]);
    } else {
        $pdo->prepare("INSERT INTO DISPARO_SMS_CONFIG (NOME_CONFIG,TOKEN_API,SERVICO,id_empresa) VALUES (?,?,?,?)")
            ->execute([$nome,$token,$servico,$emp]);
    }
    echo json_encode(['success'=>true,'msg'=>'Configuração salva com sucesso!']); exit;

case 'excluir_config':
    if (!verificaPermissao($pdo,'SUBMENU_CARTEIRA','TELA')) throw new Exception("Sem permissão.");
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE DISPARO_SMS_CONFIG SET ATIVO=0 WHERE ID=?")->execute([$id]);
    echo json_encode(['success'=>true,'msg'=>'Configuração removida.']); exit;

// ════════════════════════════════════════════
// CONSULTAR SALDO
// ════════════════════════════════════════════
case 'consultar_saldo':
    $cfg_id = (int)($_POST['config_id'] ?? 0);
    $cfg = smsGetConfig($pdo,$cfg_id);
    if (!$cfg) throw new Exception("Configuração não encontrada.");
    $resp = smsApiCall('GET','balance',$cfg['TOKEN_API']);
    echo json_encode(['success'=>true,'data'=>$resp]); exit;

// ════════════════════════════════════════════
// ENVIAR MANUAL
// ════════════════════════════════════════════
case 'enviar_manual':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_DISPARO_MANUAL','TELA')) throw new Exception("Sem permissão.");
    $cfg_id   = (int)($_POST['config_id'] ?? 0);
    $nome_d   = trim($_POST['nome_dest'] ?? '');
    $tel      = preg_replace('/\D/','',$_POST['telefone'] ?? '');
    $msg      = trim($_POST['mensagem'] ?? '');
    $cod      = ($_POST['codificacao'] ?? '0')==='8'?'8':'0';
    $agenda   = trim($_POST['agendamento'] ?? '');
    if (!$tel || strlen($tel)<8)  throw new Exception("Telefone inválido.");
    if (!$msg) throw new Exception("Mensagem obrigatória.");
    $cfg = smsGetConfig($pdo,$cfg_id);
    if (!$cfg) throw new Exception("Selecione uma configuração de API.");

    $tel_api     = smsTelApi($tel);
    $parceiro_id = uniqid('sms_',true);
    $status_ini  = $agenda ? 'AGENDADO' : 'AGUARDANDO';

    $pdo->prepare("INSERT INTO DISPARO_SMS_DISPAROS
        (id_empresa,id_usuario,CPF_USUARIO,NOME_USUARIO,DESTINATARIO_NOME,DESTINATARIO_TEL,MENSAGEM,SERVICO,CODIFICACAO,PARCEIRO_ID,STATUS,DATA_AGENDAMENTO,CONFIG_ID)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id_empresa_logado,$id_usuario_logado,$cpf_logado,$nome_logado,
                   $nome_d,$tel_api,$msg,$cfg['SERVICO'],$cod,$parceiro_id,$status_ini,$agenda?:null,$cfg_id]);
    $did = (int)$pdo->lastInsertId();

    if (!$agenda) {
        $resp = smsApiCall('POST','mt',$cfg['TOKEN_API'],[
            'numero'=>$tel_api,'servico'=>$cfg['SERVICO'],
            'mensagem'=>$msg,'parceiro_id'=>$parceiro_id,'codificacao'=>$cod,
        ]);
        $ok = isset($resp['status']) && (int)$resp['status']===200;
        $st = $ok ? 'ENVIADO' : 'FALHA';
        $pdo->prepare("UPDATE DISPARO_SMS_DISPAROS SET STATUS=?,DATA_ENVIO=NOW(),RESPOSTA_API=? WHERE ID=?")
            ->execute([$st,json_encode($resp),$did]);
        // Registra no chat
        $pdo->prepare("INSERT INTO DISPARO_SMS_CHAT (id_empresa,TELEFONE,NOME_CONTATO,TIPO,MENSAGEM,PARCEIRO_ID,STATUS_ENTREGA) VALUES (?,?,?,?,?,?,?)")
            ->execute([$id_empresa_logado,$tel_api,$nome_d,'ENVIADO',$msg,$parceiro_id,$st]);
        echo json_encode(['success'=>$ok,'msg'=> $ok ? 'SMS enviado com sucesso!' : 'Falha no envio. Verifique o token e o número.','api'=>$resp]); exit;
    }
    echo json_encode(['success'=>true,'msg'=>'SMS agendado para '.date('d/m/Y H:i',strtotime($agenda)).'.']); exit;

// ════════════════════════════════════════════
// CRIAR LOTE
// ════════════════════════════════════════════
case 'criar_lote':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_DISPARO_LOTE','TELA')) throw new Exception("Sem permissão.");
    $cfg_id  = (int)($_POST['config_id'] ?? 0);
    $nome_l  = trim($_POST['nome_lote'] ?? '');
    $msg     = trim($_POST['mensagem'] ?? '');
    $cod     = ($_POST['codificacao'] ?? '0')==='8'?'8':'0';
    $agenda  = trim($_POST['agendamento'] ?? '');
    $itens   = json_decode($_POST['itens'] ?? '[]', true);
    if (!$nome_l) throw new Exception("Nome do lote obrigatório.");
    if (!$msg)    throw new Exception("Mensagem obrigatória.");
    if (empty($itens)) throw new Exception("Nenhum destinatário válido.");
    $cfg = smsGetConfig($pdo,$cfg_id);
    if (!$cfg) throw new Exception("Selecione uma configuração de API.");

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO DISPARO_SMS_LOTE
        (id_empresa,id_usuario,CPF_USUARIO,NOME_USUARIO,NOME_LOTE,MENSAGEM_TEMPLATE,QTD_TOTAL,STATUS,DATA_AGENDAMENTO,CONFIG_ID,SERVICO,CODIFICACAO)
        VALUES (?,?,?,?,?,?,?,'PENDENTE',?,?,?,?)")
        ->execute([$id_empresa_logado,$id_usuario_logado,$cpf_logado,$nome_logado,
                   $nome_l,$msg,count($itens),$agenda?:null,$cfg_id,$cfg['SERVICO'],$cod]);
    $lote_id = (int)$pdo->lastInsertId();

    $stI = $pdo->prepare("INSERT INTO DISPARO_SMS_LOTE_ITENS (LOTE_ID,DESTINATARIO_NOME,DESTINATARIO_TEL,TAG1,TAG2,TAG3,MENSAGEM_FINAL) VALUES (?,?,?,?,?,?,?)");
    foreach ($itens as $it) {
        $t = preg_replace('/\D/','',$it['telefone'] ?? '');
        if (strlen($t)<8) continue;
        $msg_f = smsAplicarTags($msg, $it['nome']??'', $it['tag1']??'', $it['tag2']??'', $it['tag3']??'');
        $stI->execute([$lote_id,$it['nome']??'',smsTelApi($t),$it['tag1']??'',$it['tag2']??'',$it['tag3']??'',$msg_f]);
    }
    $pdo->commit();

    if (!$agenda) {
        $wurl = 'http://127.0.0.1/modulos/configuracao/disparo_sms/sms_worker_lote.php?lote_id='.$lote_id;
        $ch = curl_init($wurl);
        curl_setopt_array($ch,[CURLOPT_TIMEOUT=>2,CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true]);
        curl_exec($ch); curl_close($ch);
    }
    echo json_encode(['success'=>true,'msg'=>'Lote criado com '.count($itens).' destinatários!','lote_id'=>$lote_id]); exit;

// ════════════════════════════════════════════
// LISTAR LOTES
// ════════════════════════════════════════════
case 'listar_lotes':
    $w = smsWhere($is_master,$is_supervisor,$id_empresa_logado,$id_usuario_logado);
    $s = $pdo->query("SELECT * FROM DISPARO_SMS_LOTE WHERE $w ORDER BY DATA_CRIACAO DESC LIMIT 300");
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['DATA_BR'] = date('d/m/Y H:i',strtotime($r['DATA_CRIACAO']));
        $r['PCT']     = $r['QTD_TOTAL']>0 ? round($r['QTD_ENVIADO']/$r['QTD_TOTAL']*100) : 0;
    }
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

// ════════════════════════════════════════════
// LISTAR ITENS DE UM LOTE
// ════════════════════════════════════════════
case 'listar_itens_lote':
    $lid = (int)($_POST['lote_id'] ?? 0);
    if (!$lid) throw new Exception("ID inválido.");
    $s = $pdo->prepare("SELECT * FROM DISPARO_SMS_LOTE_ITENS WHERE LOTE_ID=? ORDER BY ID ASC");
    $s->execute([$lid]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['DATA_BR']  = $r['DATA_ENVIO'] ? date('d/m/Y H:i',strtotime($r['DATA_ENVIO'])) : '—';
        $r['TEL_FMT']  = smsFmtTel($r['DESTINATARIO_TEL']);
    }
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

case 'pausar_lote':
    $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET STATUS='PAUSADO' WHERE ID=?")->execute([(int)$_POST['lote_id']]);
    echo json_encode(['success'=>true,'msg'=>'Lote pausado.']); exit;

case 'retomar_lote':
    $lid = (int)($_POST['lote_id'] ?? 0);
    $pdo->prepare("UPDATE DISPARO_SMS_LOTE SET STATUS='PENDENTE' WHERE ID=?")->execute([$lid]);
    $wurl = 'http://127.0.0.1/modulos/configuracao/disparo_sms/sms_worker_lote.php?lote_id='.$lid;
    $ch = curl_init($wurl);
    curl_setopt_array($ch,[CURLOPT_TIMEOUT=>2,CURLOPT_RETURNTRANSFER=>true,CURLOPT_NOBODY=>true]);
    curl_exec($ch); curl_close($ch);
    echo json_encode(['success'=>true,'msg'=>'Lote retomado.']); exit;

// ════════════════════════════════════════════
// LISTAR DISPAROS (relatório)
// ════════════════════════════════════════════
case 'listar_disparos':
    $w = smsWhere($is_master,$is_supervisor,$id_empresa_logado,$id_usuario_logado);
    $st_f = trim($_POST['status'] ?? '');
    $di   = trim($_POST['data_ini'] ?? '');
    $df   = trim($_POST['data_fim'] ?? '');
    $extra = ''; $params = [];
    if ($st_f) { $extra .= " AND STATUS=?";          $params[] = $st_f; }
    if ($di)   { $extra .= " AND DATE(DATA_CRIACAO)>=?"; $params[] = date('Y-m-d',strtotime(str_replace('/','-',$di))); }
    if ($df)   { $extra .= " AND DATE(DATA_CRIACAO)<=?"; $params[] = date('Y-m-d',strtotime(str_replace('/','-',$df))); }
    $s = $pdo->prepare("SELECT * FROM DISPARO_SMS_DISPAROS WHERE $w $extra ORDER BY DATA_CRIACAO DESC LIMIT 500");
    $s->execute($params);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['DATA_BR']  = date('d/m/Y H:i',strtotime($r['DATA_CRIACAO']));
        $r['TEL_FMT']  = smsFmtTel($r['DESTINATARIO_TEL']);
    }
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

// ════════════════════════════════════════════
// CHAT — listar conversas
// ════════════════════════════════════════════
case 'listar_conversas':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_CHAT','TELA')) throw new Exception("Sem permissão.");
    $we = $is_master ? '1=1' : "id_empresa=$id_empresa_logado";
    $s = $pdo->query("
        SELECT c.TELEFONE, c.NOME_CONTATO, c.id_empresa,
            MAX(c.DATA_HORA) AS ULTIMA_HORA,
            SUM(CASE WHEN c.LIDO=0 AND c.TIPO='RECEBIDO' THEN 1 ELSE 0 END) AS NAO_LIDOS,
            (SELECT c2.MENSAGEM FROM DISPARO_SMS_CHAT c2
             WHERE c2.TELEFONE=c.TELEFONE AND c2.id_empresa=c.id_empresa
             ORDER BY c2.DATA_HORA DESC LIMIT 1) AS ULTIMA_MENSAGEM
        FROM DISPARO_SMS_CHAT c WHERE $we
        GROUP BY c.TELEFONE, c.NOME_CONTATO, c.id_empresa
        ORDER BY ULTIMA_HORA DESC LIMIT 200");
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['DATA_BR']  = date('d/m/Y H:i',strtotime($r['ULTIMA_HORA']));
        $r['TEL_FMT']  = smsFmtTel($r['TELEFONE']);
    }
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

case 'listar_mensagens':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_CHAT','TELA')) throw new Exception("Sem permissão.");
    $tel = preg_replace('/[^0-9]/','', $_POST['telefone'] ?? '');
    if (!$tel) throw new Exception("Telefone inválido.");
    $we = $is_master ? '1=1' : "id_empresa=$id_empresa_logado";
    $s  = $pdo->prepare("SELECT * FROM DISPARO_SMS_CHAT WHERE TELEFONE LIKE ? AND $we ORDER BY DATA_HORA ASC LIMIT 300");
    $s->execute(['%'.$tel.'%']);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['DATA_BR'] = date('d/m/Y H:i',strtotime($r['DATA_HORA']));
    $pdo->prepare("UPDATE DISPARO_SMS_CHAT SET LIDO=1 WHERE TELEFONE LIKE ? AND TIPO='RECEBIDO'")->execute(['%'.$tel.'%']);
    echo json_encode(['success'=>true,'data'=>$rows]); exit;

case 'responder_chat':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_CHAT','TELA')) throw new Exception("Sem permissão.");
    $cfg_id = (int)($_POST['config_id'] ?? 0);
    $tel    = preg_replace('/\D/','',$_POST['telefone'] ?? '');
    $msg    = trim($_POST['mensagem'] ?? '');
    $nome_c = trim($_POST['nome_contato'] ?? '');
    if (!$tel || !$msg) throw new Exception("Telefone e mensagem obrigatórios.");
    $cfg = smsGetConfig($pdo,$cfg_id);
    if (!$cfg) throw new Exception("Configuração não encontrada.");
    $tel_api = smsTelApi($tel);
    $pid = uniqid('chat_',true);
    $resp = smsApiCall('POST','mt',$cfg['TOKEN_API'],[
        'numero'=>$tel_api,'servico'=>$cfg['SERVICO'],'mensagem'=>$msg,'parceiro_id'=>$pid,'codificacao'=>'0',
    ]);
    $ok = isset($resp['status']) && (int)$resp['status']===200;
    $pdo->prepare("INSERT INTO DISPARO_SMS_CHAT (id_empresa,TELEFONE,NOME_CONTATO,TIPO,MENSAGEM,PARCEIRO_ID,STATUS_ENTREGA) VALUES (?,?,?,?,?,?,?)")
        ->execute([$id_empresa_logado,$tel_api,$nome_c,'ENVIADO',$msg,$pid,$ok?'ENVIADO':'FALHA']);
    echo json_encode(['success'=>$ok,'msg'=> $ok?'Enviado!':'Falha no envio.']); exit;

case 'sincronizar_respostas':
    if (!verificaPermissao($pdo,'SUBMENU_SMS_CHAT','TELA')) throw new Exception("Sem permissão.");
    $cfg_id = (int)($_POST['config_id'] ?? 0);
    $cfg = smsGetConfig($pdo,$cfg_id);
    if (!$cfg) throw new Exception("Configuração não encontrada.");
    $resp = smsApiCall('GET','mo',$cfg['TOKEN_API'],['data'=>date('d/m/Y')]);
    $novos = 0;
    $lista = $resp['messages'] ?? $resp['data'] ?? (isset($resp['origem'])?[$resp]:[]);
    foreach ((array)$lista as $item) {
        $tel  = preg_replace('/\D/','',$item['origem'] ?? '');
        $msg  = $item['resposta'] ?? $item['mensagem'] ?? '';
        $dt   = $item['data_recebimento'] ?? date('Y-m-d H:i:s');
        if (!$tel || !$msg) continue;
        $ex = $pdo->prepare("SELECT COUNT(*) FROM DISPARO_SMS_CHAT WHERE TELEFONE=? AND MENSAGEM=? AND DATA_HORA=? AND TIPO='RECEBIDO'");
        $ex->execute([$tel,$msg,$dt]);
        if ($ex->fetchColumn()) continue;
        $pdo->prepare("INSERT INTO DISPARO_SMS_CHAT (id_empresa,TELEFONE,TIPO,MENSAGEM,DATA_HORA,LIDO) VALUES (?,?,?,?,?,0)")
            ->execute([$id_empresa_logado,$tel,'RECEBIDO',$msg,$dt]);
        $novos++;
    }
    echo json_encode(['success'=>true,'novos'=>$novos,'msg'=>"$novos nova(s) mensagem(ns)."]); exit;

// ════════════════════════════════════════════
// STATS (painel)
// ════════════════════════════════════════════
case 'stats':
    $w = smsWhere($is_master,$is_supervisor,$id_empresa_logado,$id_usuario_logado);
    $hoje = date('Y-m-d');
    $s = $pdo->query("SELECT
        SUM(CASE WHEN DATE(DATA_CRIACAO)='$hoje' THEN 1 ELSE 0 END) AS hoje,
        SUM(CASE WHEN STATUS='ENVIADO' AND DATE(DATA_CRIACAO)='$hoje' THEN 1 ELSE 0 END) AS enviado_hoje,
        SUM(CASE WHEN STATUS='ENTREGUE' THEN 1 ELSE 0 END) AS entregues,
        SUM(CASE WHEN STATUS='FALHA' THEN 1 ELSE 0 END) AS falhas,
        COUNT(*) AS total
        FROM DISPARO_SMS_DISPAROS WHERE $w");
    $stats = $s->fetch(PDO::FETCH_ASSOC);
    $s2 = $pdo->query("SELECT COUNT(*) FROM DISPARO_SMS_CHAT WHERE $w AND TIPO='RECEBIDO' AND LIDO=0");
    $stats['nao_lidos'] = (int)$s2->fetchColumn();
    echo json_encode(['success'=>true,'data'=>$stats]); exit;

default:
    echo json_encode(['success'=>false,'msg'=>'Ação não reconhecida.']); exit;

}} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'msg'=>$e->getMessage()]); exit;
}
