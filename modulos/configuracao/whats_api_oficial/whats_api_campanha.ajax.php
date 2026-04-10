<?php
ini_set('display_errors', 0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    $acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
    $cpf_logado = $_SESSION['usuario_cpf'] ?? '';
    $id_usuario = $_SESSION['usuario_id'] ?? null;
    $nome_usuario = $_SESSION['usuario_nome'] ?? '';

    if (empty($cpf_logado)) {
        echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']);
        exit;
    }

    if (!isset($_SESSION['empresa_id'])) {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$cpf_logado]);
        $_SESSION['empresa_id'] = $stmtEmp->fetchColumn() ?: 1;
    }
    $id_empresa = $_SESSION['empresa_id'];

    $caminho_perm = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_perm)) { require_once $caminho_perm; }
    if (!function_exists('VerificaBloqueio')) { function VerificaBloqueio($chave) { return false; } }

    switch ($acao) {
        
        case 'importar_campanha':
            $nome_campanha = trim($_POST['nome_campanha'] ?? '');
            $phone_id = trim($_POST['phone_id_remetente'] ?? '');
            $template_name = trim($_POST['template_name'] ?? '');
            $tipo_importacao = trim($_POST['tipo_importacao'] ?? 'tela');
            $intervalo_segundos  = max(1, (int)($_POST['intervalo_segundos'] ?? 5));
            $pausa_apos_qtde     = max(0, (int)($_POST['pausa_apos_qtde'] ?? 0));
            $pausa_duracao_seg   = max(1, (int)($_POST['pausa_duracao_segundos'] ?? 60));

            if (empty($nome_campanha) || empty($phone_id) || empty($template_name)) {
                echo json_encode(['success' => false, 'msg' => 'Preencha todos os campos.']); exit;
            }

            $dir_upload = '/var/www/html/modulos/configuracao/whats_api_oficial/arquivo_importacao/';
            if (!is_dir($dir_upload)) { @mkdir($dir_upload, 0775, true); }

            $linhas = [];
            $nome_arquivo = $nome_campanha . '_' . $cpf_logado . '_' . date('Ymd_His') . ($tipo_importacao === 'csv' ? '.csv' : '.txt');
            $caminho_completo = $dir_upload . $nome_arquivo;

            if ($tipo_importacao === 'tela') {
                $numeros = explode("\n", $_POST['numeros_tela']);
                file_put_contents($caminho_completo, $_POST['numeros_tela']);
                foreach ($numeros as $num) {
                    $n = preg_replace('/\D/', '', $num);
                    if (!empty($n)) $linhas[] = ['numero' => $n];
                }
            } else {
                if (!isset($_FILES['arquivo_csv']) || $_FILES['arquivo_csv']['error'] !== UPLOAD_ERR_OK) {
                    echo json_encode(['success' => false, 'msg' => 'Erro no upload do arquivo CSV.']); exit;
                }
                move_uploaded_file($_FILES['arquivo_csv']['tmp_name'], $caminho_completo);
                $file = fopen($caminho_completo, 'r');
                $header = fgetcsv($file, 1000, ";");
                while (($data = fgetcsv($file, 1000, ";")) !== FALSE) {
                    $cpf_original = preg_replace('/\D/', '', $data[2] ?? '');
                    $cpf_mascarado = strlen($cpf_original) >= 2 ? str_repeat('#', strlen($cpf_original) - 2) . substr($cpf_original, -2) : '';

                    $linhas[] = [
                        'numero' => preg_replace('/\D/', '', $data[0] ?? ''),
                        'nome' => $data[1] ?? null,
                        'cpf' => $cpf_mascarado,
                        'margem' => str_replace(',', '.', $data[3] ?? '0.00'),
                        'parcela' => str_replace(',', '.', $data[4] ?? '0.00'),
                        'prazo' => (int)($data[5] ?? 0),
                        'liquido' => str_replace(',', '.', $data[6] ?? '0.00')
                    ];
                }
                fclose($file);
            }

            $sucesso = 0; $erros = 0;
            $stmt = $pdo->prepare("INSERT IGNORE INTO WHATSAPP_OFICIAL_CAMPANHA_LOTE 
                (ID_USUARIO, CPF_USUARIO, NOME_USUARIO, ID_EMPRESA, NOME_CAMPANHA, PHONE_NUMBER_ID, TEMPLATE_NAME, NUMERO, NOME, CPF, MARGEM, PARCELA, PRAZO, VALOR_LIQUIDO) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($linhas as $l) {
                if (empty($l['numero'])) continue;
                $exec = $stmt->execute([
                    $id_usuario, $cpf_logado, $nome_usuario, $id_empresa, 
                    $nome_campanha, $phone_id, $template_name, 
                    $l['numero'], $l['nome'] ?? null, $l['cpf'] ?? null, 
                    $l['margem'] ?? 0, $l['parcela'] ?? 0, $l['prazo'] ?? 0, $l['liquido'] ?? 0
                ]);
                if ($exec && $stmt->rowCount() > 0) $sucesso++; else $erros++;
            }

            // Salva configurações de disparo desta campanha
            $pdo->prepare("INSERT INTO WHATSAPP_OFICIAL_CAMPANHA_META (NOME_CAMPANHA, INTERVALO_SEGUNDOS, PAUSA_APOS_QTDE, PAUSA_DURACAO_SEGUNDOS)
                VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE INTERVALO_SEGUNDOS=VALUES(INTERVALO_SEGUNDOS), PAUSA_APOS_QTDE=VALUES(PAUSA_APOS_QTDE), PAUSA_DURACAO_SEGUNDOS=VALUES(PAUSA_DURACAO_SEGUNDOS)")
                ->execute([$nome_campanha, $intervalo_segundos, $pausa_apos_qtde, $pausa_duracao_seg]);

            echo json_encode(['success' => true, 'msg' => "Importação Concluída. $sucesso números inseridos (Ignorados: $erros)."]);
            break;

        case 'listar_campanhas':
            $bloqueado_meu     = VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_MEU_CADASTRO');
            $bloqueado_empresa = VerificaBloqueio('SUBMENU_OP_WHATOFICIAL_CAMPANHA_HIERARQUIA');

            $filtro = "1=1";
            $params = [];

            if ($bloqueado_meu) {
                $filtro .= " AND CPF_USUARIO = ?";
                $params[] = $cpf_logado;
            } elseif ($bloqueado_empresa && !empty($id_empresa)) {
                $filtro .= " AND ID_EMPRESA = ?";
                $params[] = $id_empresa;
            }
            // MASTER/ADMIN: filtro = "1=1", vê tudo

            $sql = "SELECT
                        l.NOME_CAMPANHA, l.NOME_USUARIO, l.DATA_IMPORTACAO,
                        COUNT(l.ID) as TOTAL,
                        SUM(CASE WHEN l.STATUS_DISPARO = 'ENVIADO' THEN 1 ELSE 0 END) as ENVIADOS,
                        SUM(CASE WHEN l.STATUS_DISPARO = 'FALHA' THEN 1 ELSE 0 END) as FALHAS,
                        MAX(l.DATA_STATUS) as ULTIMO_STATUS,
                        l.STATUS_IMPORTACAO,
                        COALESCE(m.INTERVALO_SEGUNDOS, 5) as INTERVALO_SEGUNDOS,
                        COALESCE(m.PAUSA_APOS_QTDE, 0) as PAUSA_APOS_QTDE,
                        COALESCE(m.PAUSA_DURACAO_SEGUNDOS, 60) as PAUSA_DURACAO_SEGUNDOS
                    FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE l
                    LEFT JOIN WHATSAPP_OFICIAL_CAMPANHA_META m ON m.NOME_CAMPANHA = l.NOME_CAMPANHA
                    WHERE $filtro
                    GROUP BY l.NOME_CAMPANHA, l.NOME_USUARIO, l.DATA_IMPORTACAO, l.STATUS_IMPORTACAO, m.INTERVALO_SEGUNDOS, m.PAUSA_APOS_QTDE, m.PAUSA_DURACAO_SEGUNDOS
                    ORDER BY MAX(l.DATA_IMPORTACAO) DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'processar_lote':
            $nome_campanha = $_POST['nome_campanha'];

            // Busca 1 pendente por vez (timing controlado pelo frontend)
            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE WHERE NOME_CAMPANHA = ? AND STATUS_DISPARO = 'PENDENTE' LIMIT 1");
            $stmt->execute([$nome_campanha]);
            $p = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$p) {
                echo json_encode(['success' => true, 'msg' => 'Campanha Finalizada', 'concluido' => true]); exit;
            }

            // Busca token pelo BM associado ao Phone ID do registro
            $stmtToken = $pdo->prepare("
                SELECT bm.PERMANENT_TOKEN
                FROM WHATSAPP_OFICIAL_BM bm
                JOIN WHATSAPP_OFICIAL_WABA waba ON waba.BM_ID = bm.ID
                JOIN WHATSAPP_OFICIAL_NUMEROS num ON num.WABA_ID = waba.ID
                WHERE num.PHONE_NUMBER_ID = ?
                LIMIT 1
            ");
            $stmtToken->execute([$p['PHONE_NUMBER_ID']]);
            $token = $stmtToken->fetchColumn();

            // Fallback: token antigo
            if (!$token) {
                $stmtT = $pdo->prepare("SELECT PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
                $stmtT->execute([$cpf_logado]);
                $token = $stmtT->fetchColumn();
            }

            $partes_tpl = explode('|', $p['TEMPLATE_NAME']);
            $t_name = $partes_tpl[0];
            $t_lang = $partes_tpl[1] ?? 'pt_BR';

            // Monta parâmetros do template com variáveis disponíveis
            $parametros = [];
            if (!empty($p['NOME']))    $parametros[] = ["type" => "text", "text" => $p['NOME']];
            if (!empty($p['MARGEM']))  $parametros[] = ["type" => "text", "text" => 'R$ ' . number_format((float)$p['MARGEM'], 2, ',', '.')];
            if (!empty($p['PARCELA'])) $parametros[] = ["type" => "text", "text" => 'R$ ' . number_format((float)$p['PARCELA'], 2, ',', '.')];
            if (!empty($p['PRAZO']))   $parametros[] = ["type" => "text", "text" => $p['PRAZO'] . 'x'];
            if (empty($parametros))    $parametros[] = ["type" => "text", "text" => "Cliente"];

            $payload = [
                "messaging_product" => "whatsapp",
                "to" => $p['NUMERO'],
                "type" => "template",
                "template" => [
                    "name" => $t_name,
                    "language" => ["code" => $t_lang],
                    "components" => [["type" => "body", "parameters" => $parametros]]
                ]
            ];

            $ch = curl_init("https://graph.facebook.com/v19.0/{$p['PHONE_NUMBER_ID']}/messages");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
            $res = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_json = json_decode($res, true);
            $status_disparo = ($http_code == 200 && isset($res_json['messages'])) ? 'ENVIADO' : 'FALHA';

            $pdo->prepare("UPDATE WHATSAPP_OFICIAL_CAMPANHA_LOTE SET STATUS_DISPARO = ?, DATA_STATUS = NOW() WHERE ID = ?")
                ->execute([$status_disparo, $p['ID']]);

            // Contagem restante
            $stmtRest = $pdo->prepare("SELECT COUNT(*) FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE WHERE NOME_CAMPANHA = ? AND STATUS_DISPARO = 'PENDENTE'");
            $stmtRest->execute([$nome_campanha]);
            $restantes = (int)$stmtRest->fetchColumn();

            echo json_encode(['success' => true, 'concluido' => false, 'status' => $status_disparo, 'restantes' => $restantes]);
            break;

        case 'excluir_campanha':
            $nome_campanha = $_POST['nome_campanha'];
            $pdo->prepare("DELETE FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE WHERE NOME_CAMPANHA = ?")->execute([$nome_campanha]);
            echo json_encode(['success' => true, 'msg' => 'Campanha excluída com sucesso!']);
            break;

        case 'exportar_excel':
            $nome_campanha = $_GET['nome_campanha'] ?? '';
            header("Content-Type: text/csv; charset=utf-8");
            header("Content-Disposition: attachment; filename=campanha_{$nome_campanha}.csv");
            $output = fopen("php://output", "w");
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM para acentuação no Excel
            fputcsv($output, ['Nome Campanha', 'Numero', 'Nome Cliente', 'CPF', 'Status Disparo', 'Data Status'], ';');
            
            $stmt = $pdo->prepare("SELECT NOME_CAMPANHA, NUMERO, NOME, CPF, STATUS_DISPARO, DATA_STATUS FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE WHERE NOME_CAMPANHA = ?");
            $stmt->execute([$nome_campanha]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { fputcsv($output, $row, ';'); }
            fclose($output);
            exit;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida no módulo de campanha.']);
            break;
    }
} catch (Exception $e) { 
    echo json_encode(['success' => false, 'msg' => "Erro: " . $e->getMessage()]); 
}
?>