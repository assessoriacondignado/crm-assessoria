<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

if (!verificaPermissao($pdo, 'v8_AUDITORIA_SUBMENU', 'TELA')) {
    echo json_encode(['success' => false, 'msg' => 'Sem permissão de acesso ao módulo de Auditoria.']); exit;
}

// Garante existência das tabelas
$pdo->exec("CREATE TABLE IF NOT EXISTS `V8_LOTE_AUDITORIA` (
    `ID`                    int          NOT NULL AUTO_INCREMENT,
    `NOME_AUDITORIA`        varchar(200) NOT NULL,
    `LOTE_ORIGEM_ID`        int          DEFAULT NULL,
    `LOTE_ORIGEM_NOME`      varchar(200) DEFAULT NULL,
    `TABELA_ORIGEM`         varchar(100) DEFAULT NULL,
    `USUARIO_AUDITOR_CPF`   varchar(14)  DEFAULT NULL,
    `USUARIO_AUDITOR_NOME`  varchar(150) DEFAULT NULL,
    `CHAVE_AUDITORIA_ID`    int          DEFAULT NULL,
    `id_empresa`            int          DEFAULT NULL,
    `QTD_CPF`               int          DEFAULT 0,
    `STATUS_AUDITORIA`      enum('ATIVO','ARQUIVADO') DEFAULT 'ATIVO',
    `DATA_CRIACAO`          datetime     DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    KEY `idx_empresa` (`id_empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS `V8_LOTE_AUDITORIA_DADOS` (
    `ID`                int          NOT NULL AUTO_INCREMENT,
    `AUDITORIA_ID`      int          NOT NULL,
    `CPF`               varchar(14)  NOT NULL,
    `NOME`              varchar(150) DEFAULT NULL,
    `NASCIMENTO`        date         DEFAULT NULL,
    `SEXO`              varchar(20)  DEFAULT NULL,
    `VALOR_MARGEM`      decimal(10,2) DEFAULT NULL,
    `PRAZO`             int          DEFAULT NULL,
    `VALOR_LIQUIDO`     decimal(10,2) DEFAULT NULL,
    `STATUS_V8`         varchar(50)  DEFAULT NULL,
    `OBSERVACAO`        text         DEFAULT NULL,
    `CONSULT_ID`        varchar(150) DEFAULT NULL,
    `CONFIG_ID`         varchar(150) DEFAULT NULL,
    `SIMULATION_ID`     varchar(150) DEFAULT NULL,
    `DATA_SIMULACAO`    datetime     DEFAULT NULL,
    `DATA_TRANSFERENCIA` datetime    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`ID`),
    KEY `idx_auditoria_id` (`AUDITORIA_ID`),
    KEY `idx_cpf` (`CPF`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$acao                = $_POST['acao'] ?? '';
$usuario_logado_cpf  = preg_replace('/\D/', '', $_SESSION['usuario_cpf']   ?? '');
$usuario_logado_nome = $_SESSION['usuario_nome']   ?? '';
$usuario_logado_grupo = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master           = in_array($usuario_logado_grupo, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// Empresa do usuário logado (para hierarquia)
$id_empresa_logado = null;
try {
    $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtEmp->execute([$usuario_logado_cpf]);
    $id_empresa_logado = $stmtEmp->fetchColumn() ?: null;
} catch (Exception $e) {}

try {
    switch ($acao) {

        // ============================================================
        // Listar auditorias (com hierarquia de empresa)
        // ============================================================
        case 'listar_auditorias':
            if ($is_master) {
                $stmt = $pdo->query("
                    SELECT a.*,
                        (SELECT COUNT(*) FROM V8_LOTE_AUDITORIA_DADOS WHERE AUDITORIA_ID = a.ID) as QTD_REAL
                    FROM V8_LOTE_AUDITORIA a
                    ORDER BY a.DATA_CRIACAO DESC LIMIT 300
                ");
            } else {
                $stmt = $pdo->prepare("
                    SELECT a.*,
                        (SELECT COUNT(*) FROM V8_LOTE_AUDITORIA_DADOS WHERE AUDITORIA_ID = a.ID) as QTD_REAL
                    FROM V8_LOTE_AUDITORIA a
                    WHERE a.id_empresa = ?
                    ORDER BY a.DATA_CRIACAO DESC LIMIT 300
                ");
                $stmt->execute([$id_empresa_logado]);
            }
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['DATA_BR']  = date('d/m/Y H:i', strtotime($r['DATA_CRIACAO']));
                $r['ID']       = (int)$r['ID'];
                $r['QTD_REAL'] = (int)$r['QTD_REAL'];
            }
            echo json_encode(['success' => true, 'data' => $rows]); exit;

        // ============================================================
        // Listar CPFs de uma auditoria
        // ============================================================
        case 'listar_cpfs_auditoria':
            $id_auditoria = (int)($_POST['id_auditoria'] ?? 0);
            if (!$id_auditoria) throw new Exception("ID de auditoria inválido.");

            // Verifica acesso à auditoria
            if (!$is_master && $id_empresa_logado) {
                $stmtCheck = $pdo->prepare("SELECT id_empresa FROM V8_LOTE_AUDITORIA WHERE ID = ? LIMIT 1");
                $stmtCheck->execute([$id_auditoria]);
                $emp_aud = $stmtCheck->fetchColumn();
                if ((int)$emp_aud !== (int)$id_empresa_logado) throw new Exception("Acesso negado a esta auditoria.");
            }

            $stmt = $pdo->prepare("SELECT * FROM V8_LOTE_AUDITORIA_DADOS WHERE AUDITORIA_ID = ? ORDER BY NOME ASC");
            $stmt->execute([$id_auditoria]);
            $cpfs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cpfs as &$c) {
                $c['CPF_FORMATADO']  = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($c['CPF'], 11, '0', STR_PAD_LEFT));
                $c['DATA_SIM_DISPLAY'] = $c['DATA_SIMULACAO'] ? date('d/m/Y H\hi', strtotime($c['DATA_SIMULACAO'])) : '—';
                $c['MARGEM_FMT']     = $c['VALOR_MARGEM']  ? 'R$ ' . number_format((float)$c['VALOR_MARGEM'],  2, ',', '.') : '—';
                $c['LIQUIDO_FMT']    = $c['VALOR_LIQUIDO'] ? 'R$ ' . number_format((float)$c['VALOR_LIQUIDO'], 2, ',', '.') : '—';
            }
            echo json_encode(['success' => true, 'cpfs' => $cpfs]); exit;

        // ============================================================
        // Exportar CSV de uma auditoria
        // ============================================================
        case 'exportar_csv':
            $id_auditoria = (int)($_POST['id_auditoria'] ?? 0);
            if (!$id_auditoria) throw new Exception("ID inválido.");

            $stmt = $pdo->prepare("SELECT * FROM V8_LOTE_AUDITORIA_DADOS WHERE AUDITORIA_ID = ? ORDER BY NOME ASC");
            $stmt->execute([$id_auditoria]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Pré-carrega dados cadastrais de todos os CPFs em memória (evita N+1)
            $cpfs_list = array_map(fn($r) => str_pad(preg_replace('/\D/','',$r['CPF']),11,'0',STR_PAD_LEFT), $rows);
            $placeholders = implode(',', array_fill(0, count($cpfs_list), '?'));

            $telefones_map = $emails_map = $enderecos_map = [];

            if ($cpfs_list) {
                // Telefones — pega o primeiro de cada CPF
                $stmtT = $pdo->prepare("SELECT cpf, telefone_cel FROM telefones WHERE cpf IN ($placeholders) ORDER BY id ASC");
                $stmtT->execute($cpfs_list);
                foreach ($stmtT->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($telefones_map[$row['cpf']])) $telefones_map[$row['cpf']] = $row['telefone_cel'];
                }

                // E-mails — pega o primeiro de cada CPF
                $stmtE = $pdo->prepare("SELECT cpf, email FROM emails WHERE cpf IN ($placeholders) ORDER BY id ASC");
                $stmtE->execute($cpfs_list);
                foreach ($stmtE->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($emails_map[$row['cpf']])) $emails_map[$row['cpf']] = $row['email'];
                }

                // Endereços — pega o primeiro de cada CPF
                $stmtEnd = $pdo->prepare("SELECT cpf, logradouro, numero, bairro, cidade, uf, cep FROM enderecos WHERE cpf IN ($placeholders) ORDER BY id ASC");
                $stmtEnd->execute($cpfs_list);
                foreach ($stmtEnd->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!isset($enderecos_map[$row['cpf']])) $enderecos_map[$row['cpf']] = $row;
                }
            }

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="auditoria_' . $id_auditoria . '_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fputs($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'CPF','NOME','STATUS V8','MARGEM','VALOR LIQUIDO','PRAZO','OBSERVACAO','DATA SIMULACAO',
                'TELEFONE','EMAIL',
                'CEP','LOGRADOURO','NUMERO','BAIRRO','CIDADE','UF'
            ], ';');
            foreach ($rows as $r) {
                $cpf_pad = str_pad(preg_replace('/\D/','',$r['CPF']),11,'0',STR_PAD_LEFT);
                $cpf_fmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf_pad);
                $end     = $enderecos_map[$cpf_pad] ?? [];

                // Formata telefone (11 dígitos → (DD) 9XXXX-XXXX)
                $tel_raw = $telefones_map[$cpf_pad] ?? '';
                if (strlen($tel_raw) === 11)
                    $tel_fmt = '(' . substr($tel_raw,0,2) . ') ' . substr($tel_raw,2,5) . '-' . substr($tel_raw,7);
                elseif (strlen($tel_raw) === 10)
                    $tel_fmt = '(' . substr($tel_raw,0,2) . ') ' . substr($tel_raw,2,4) . '-' . substr($tel_raw,6);
                else
                    $tel_fmt = $tel_raw;

                fputcsv($out, [
                    $cpf_fmt,
                    $r['NOME'],
                    $r['STATUS_V8'],
                    $r['VALOR_MARGEM']  ? number_format((float)$r['VALOR_MARGEM'],  2, ',', '.') : '',
                    $r['VALOR_LIQUIDO'] ? number_format((float)$r['VALOR_LIQUIDO'], 2, ',', '.') : '',
                    $r['PRAZO'] ?? '',
                    $r['OBSERVACAO'] ?? '',
                    $r['DATA_SIMULACAO'] ? date('d/m/Y H:i', strtotime($r['DATA_SIMULACAO'])) : '',
                    $tel_fmt,
                    $emails_map[$cpf_pad] ?? '',
                    $end['cep']        ?? '',
                    $end['logradouro'] ?? '',
                    $end['numero']     ?? '',
                    $end['bairro']     ?? '',
                    $end['cidade']     ?? '',
                    $end['uf']         ?? '',
                ], ';');
            }
            fclose($out); exit;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não reconhecida.']); exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit;
}
