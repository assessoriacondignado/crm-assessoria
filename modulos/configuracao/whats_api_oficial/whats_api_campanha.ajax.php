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
    $id_empresa = $_SESSION['empresa_id'] ?? null;

    if (empty($cpf_logado)) {
        echo json_encode(['success' => false, 'msg' => 'Sessão expirada.']);
        exit;
    }

    $caminho_perm = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_perm)) { require_once $caminho_perm; }
    if (!function_exists('VerificaBloqueio')) { function VerificaBloqueio($chave) { return false; } }

    switch ($acao) {
        
        case 'importar_campanha':
            $nome_campanha = trim($_POST['nome_campanha'] ?? '');
            $phone_id = trim($_POST['phone_id_remetente'] ?? '');
            $template_name = trim($_POST['template_name'] ?? '');
            $tipo_importacao = trim($_POST['tipo_importacao'] ?? 'tela');

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
                        NOME_CAMPANHA, NOME_USUARIO, DATA_IMPORTACAO,
                        COUNT(ID) as TOTAL,
                        SUM(CASE WHEN STATUS_DISPARO = 'ENVIADO' THEN 1 ELSE 0 END) as ENVIADOS,
                        SUM(CASE WHEN STATUS_DISPARO = 'FALHA' THEN 1 ELSE 0 END) as FALHAS,
                        MAX(DATA_STATUS) as ULTIMO_STATUS,
                        STATUS_IMPORTACAO
                    FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE
                    WHERE $filtro
                    GROUP BY NOME_CAMPANHA, NOME_USUARIO, DATA_IMPORTACAO, STATUS_IMPORTACAO
                    ORDER BY MAX(DATA_IMPORTACAO) DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        case 'processar_lote':
            $nome_campanha = $_POST['nome_campanha'];
            
            $stmt = $pdo->prepare("SELECT * FROM WHATSAPP_OFICIAL_CAMPANHA_LOTE WHERE NOME_CAMPANHA = ? AND STATUS_DISPARO = 'PENDENTE' LIMIT 10");
            $stmt->execute([$nome_campanha]);
            $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($pendentes) === 0) {
                echo json_encode(['success' => true, 'msg' => 'Campanha Finalizada', 'concluido' => true]); exit;
            }

            $stmtT = $pdo->prepare("SELECT PERMANENT_TOKEN FROM WHATSAPP_OFICIAL_CONTAS WHERE CPF_USUARIO = ?");
            $stmtT->execute([$cpf_logado]);
            $token = $stmtT->fetchColumn();

            foreach ($pendentes as $p) {
                $partes_tpl = explode('|', $p['TEMPLATE_NAME']);
                $t_name = $partes_tpl[0];
                $t_lang = $partes_tpl[1] ?? 'pt_BR';

                $parametros = [
                    ["type" => "text", "text" => $p['NOME'] ?? 'Cliente']
                ];

                $payload = [
                    "messaging_product" => "whatsapp",
                    "to" => $p['NUMERO'],
                    "type" => "template",
                    "template" => [
                        "name" => $t_name,
                        "language" => ["code" => $t_lang],
                        "components" => [
                            [
                                "type" => "body",
                                "parameters" => $parametros
                            ]
                        ]
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
            }

            echo json_encode(['success' => true, 'concluido' => false]);
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