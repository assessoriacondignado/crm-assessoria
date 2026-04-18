<?php
ignore_user_abort(true);
set_time_limit(0);
ini_set('display_errors', 0);

// Só aceita chamadas do próprio servidor
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1', $_SERVER['SERVER_ADDR'] ?? ''])) {
    http_response_code(403); exit;
}

require_once '../../../conexao.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

// Garante estrutura do banco
try { $pdo->exec("ALTER TABLE telefones ADD COLUMN WHATSAPP_VALIDADO TINYINT NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE telefones ADD COLUMN DATA_VALIDACAO_WHATS DATETIME NULL DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS WAPI_VALIDACAO_FILA (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    CPF VARCHAR(11) NOT NULL,
    NOME VARCHAR(150),
    TELEFONE VARCHAR(20) NOT NULL,
    INSTANCE_ID VARCHAR(100) NULL,
    STATUS ENUM('PENDENTE','PROCESSANDO','CONCLUIDO','ERRO') NOT NULL DEFAULT 'PENDENTE',
    TEM_WHATSAPP TINYINT NULL,
    SOLICITADO_POR VARCHAR(100),
    DATA_SOLICITACAO DATETIME DEFAULT NOW(),
    DATA_PROCESSAMENTO DATETIME NULL,
    INDEX idx_status (STATUS),
    INDEX idx_cpf (CPF)
)"); } catch(Exception $e){}
try { $pdo->exec("CREATE TABLE IF NOT EXISTS WAPI_VALIDACAO_CONTADOR (
    INSTANCE_ID VARCHAR(100) NOT NULL,
    DATA DATE NOT NULL,
    QUANTIDADE INT NOT NULL DEFAULT 0,
    PRIMARY KEY (INSTANCE_ID, DATA)
)"); } catch(Exception $e){}

// Lock — impede execução paralela
$lock_file = sys_get_temp_dir() . '/wapi_validacao.lock';
$fp = @fopen($lock_file, 'w+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) exit;

$API_BASE_URL  = 'https://api.w-api.app/v1';
$LIMITE_DIARIO = 100; // consultas por instância por dia
$MAX_RUNTIME   = 270; // reinicia a cada ~4,5 min para não travar o PHP
$start_time    = time();

function getProximaInstancia($pdo, $limite) {
    $stmt = $pdo->prepare("
        SELECT i.INSTANCE_ID, i.TOKEN,
               COALESCE((SELECT QUANTIDADE FROM WAPI_VALIDACAO_CONTADOR
                         WHERE INSTANCE_ID = i.INSTANCE_ID AND DATA = CURDATE()), 0) AS usado_hoje
        FROM WAPI_INSTANCIAS i
        HAVING usado_hoje < :limite
        ORDER BY usado_hoje ASC
        LIMIT 1
    ");
    $stmt->execute(['limite' => $limite]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function acordarWorker() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $url = $protocol . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
         . '/modulos/configuracao/w-api/worker_validacao_wapi.php';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    @curl_exec($ch);
    curl_close($ch);
}

while (true) {
    // Reinicia worker se tempo limite atingido e ainda tem fila
    if (time() - $start_time > $MAX_RUNTIME) {
        $stmt = $pdo->query("SELECT COUNT(*) FROM WAPI_VALIDACAO_FILA WHERE STATUS = 'PENDENTE'");
        if ((int)$stmt->fetchColumn() > 0) acordarWorker();
        break;
    }

    // Busca instância com cota disponível
    $instancia = getProximaInstancia($pdo, $LIMITE_DIARIO);
    if (!$instancia) break; // Todas as instâncias atingiram o limite diário

    // Pega próximo item pendente
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM WAPI_VALIDACAO_FILA WHERE STATUS = 'PENDENTE' ORDER BY ID ASC LIMIT 1");
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        $pdo->commit();
        break; // Fila vazia
    }

    // Marca como processando
    $pdo->prepare("UPDATE WAPI_VALIDACAO_FILA SET STATUS = 'PROCESSANDO', INSTANCE_ID = ? WHERE ID = ?")
        ->execute([$instancia['INSTANCE_ID'], $item['ID']]);
    $pdo->commit();

    // Formata número
    $numero = preg_replace('/\D/', '', $item['TELEFONE']);
    if (!str_starts_with($numero, '55')) $numero = '55' . $numero;

    // Consulta W-API
    $url = $API_BASE_URL . "/misc/phone-exists?phone={$numero}&instanceId=" . $instancia['INSTANCE_ID'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $instancia['TOKEN'],
        'Content-Type: application/json'
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json     = json_decode($res, true);
    $tem_whats = 0;
    $status_item = 'CONCLUIDO';

    if ($http >= 200 && $http < 300) {
        $tem_whats = ($json['exists'] ?? $json['exist'] ?? $json['registered'] ?? false) ? 1 : 0;
    } else {
        $status_item = 'ERRO';
    }

    // Salva resultado na fila
    $pdo->prepare("UPDATE WAPI_VALIDACAO_FILA SET STATUS = ?, TEM_WHATSAPP = ?, INSTANCE_ID = ?, DATA_PROCESSAMENTO = NOW() WHERE ID = ?")
        ->execute([$status_item, $tem_whats, $instancia['INSTANCE_ID'], $item['ID']]);

    // Atualiza tabela telefones
    if ($status_item === 'CONCLUIDO') {
        $pdo->prepare("UPDATE telefones SET WHATSAPP_VALIDADO = ?, DATA_VALIDACAO_WHATS = NOW() WHERE telefone_cel = ? AND cpf = ?")
            ->execute([$tem_whats, $item['TELEFONE'], $item['CPF']]);
    }

    // Incrementa contador diário da instância
    $pdo->prepare("INSERT INTO WAPI_VALIDACAO_CONTADOR (INSTANCE_ID, DATA, QUANTIDADE) VALUES (?, CURDATE(), 1)
                   ON DUPLICATE KEY UPDATE QUANTIDADE = QUANTIDADE + 1")
        ->execute([$instancia['INSTANCE_ID']]);

    // Intervalo aleatório entre 3 e 8 segundos
    sleep(rand(3, 8));
}

// Reseta itens que ficaram em PROCESSANDO por falha do worker anterior
$pdo->exec("UPDATE WAPI_VALIDACAO_FILA SET STATUS = 'PENDENTE', INSTANCE_ID = NULL WHERE STATUS = 'PROCESSANDO'");

flock($fp, LOCK_UN);
fclose($fp);
?>
