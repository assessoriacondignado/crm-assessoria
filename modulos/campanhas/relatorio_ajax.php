<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;

if (!isset($_SESSION['usuario_cpf'])) {
    echo json_encode(['success' => false, 'msg' => 'Sessão inválida.']); exit;
}

$perm_consulta   = verificaPermissao($pdo, 'CAMPANHA_RELATORIO_CONSULTA',       'FUNCAO');
$perm_exportar   = verificaPermissao($pdo, 'CAMPANHA_RELATORIO_EXPORTAR',        'FUNCAO');
$perm_meus_reg   = verificaPermissao($pdo, 'CAMPANHA_RELATORIO_MEUS_REGISTROS',  'FUNCAO');

if (!$perm_consulta) {
    echo json_encode(['success' => false, 'msg' => 'Sem permissão para acessar relatórios.']); exit;
}

$acao       = $_POST['acao'] ?? ($_GET['acao'] ?? '');
$cpf_logado = $_SESSION['usuario_cpf'] ?? '';
$grupo      = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master  = in_array($grupo, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
$is_consultor = in_array($grupo, ['CONSULTOR', 'CONSULTORES']);

// Se o grupo estiver bloqueado em CAMPANHA_RELATORIO_MEUS_REGISTROS,
// o usuário só enxerga seus próprios registros (independente do grupo)
$somente_meus = !$perm_meus_reg && !$is_master;

// Captura IDs hierárquicos
$id_usuario_num   = null;
$id_empresa_num   = null;
if ($cpf_logado) {
    $st = $pdo->prepare("SELECT ID, id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $st->execute([$cpf_logado]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if ($d) { $id_usuario_num = $d['ID']; $id_empresa_num = $d['id_empresa']; }
}

// =========================================================================
// HELPER: Monta WHERE hierárquico para REGISTRO_CONTATO
// =========================================================================
function filtroHierarquia($is_master, $is_consultor, $id_empresa_num, $id_usuario_num, &$params, $somente_meus = false) {
    $sql = '';
    if (!$is_master) {
        $sql .= " AND r.id_empresa = ?";
        $params[] = $id_empresa_num;
        // Restringe ao próprio usuário se for CONSULTOR ou se CAMPANHA_RELATORIO_MEUS_REGISTROS estiver bloqueado
        if ($is_consultor || $somente_meus) {
            $sql .= " AND r.id_usuario = ?";
            $params[] = $id_usuario_num;
        }
    }
    return $sql;
}

// =========================================================================
// HELPER: Monta WHERE de filtros do usuário
// =========================================================================
function filtrosUsuario($post, &$params, $is_master, $id_empresa_num) {
    $sql = '';

    // Empresa (só master pode filtrar por empresa específica)
    if ($is_master && !empty($post['empresa'])) {
        $sql .= " AND r.id_empresa = ?";
        $params[] = intval($post['empresa']);
    }

    // Campanha: filtra CPFs que estão na campanha
    if (!empty($post['campanha'])) {
        $camps = array_filter(array_map('intval', (array)$post['campanha']));
        if (!empty($camps)) {
            $placeholders = implode(',', array_fill(0, count($camps), '?'));
            $sql .= " AND r.CPF_CLIENTE IN (SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA IN ($placeholders))";
            $params = array_merge($params, $camps);
        }
    }

    // Status
    if (!empty($post['status'])) {
        $stats = array_filter(array_map('intval', (array)$post['status']));
        if (!empty($stats)) {
            $placeholders = implode(',', array_fill(0, count($stats), '?'));
            $sql .= " AND r.ID_STATUS_CONTATO IN ($placeholders)";
            $params = array_merge($params, $stats);
        }
    }

    // Usuário
    if (!empty($post['usuario'])) {
        $usus = array_filter(array_map('intval', (array)$post['usuario']));
        if (!empty($usus)) {
            $placeholders = implode(',', array_fill(0, count($usus), '?'));
            $sql .= " AND r.id_usuario IN ($placeholders)";
            $params = array_merge($params, $usus);
        }
    }

    // Período
    $periodo   = $post['periodo'] ?? 'todos';
    $data_ini  = $post['data_ini'] ?? '';
    $data_fim  = $post['data_fim'] ?? '';
    switch ($periodo) {
        case 'hoje':
            $sql .= " AND DATE(r.DATA_REGISTRO) = CURDATE()";
            break;
        case 'ontem':
            $sql .= " AND DATE(r.DATA_REGISTRO) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'mes':
            $sql .= " AND YEAR(r.DATA_REGISTRO) = YEAR(CURDATE()) AND MONTH(r.DATA_REGISTRO) = MONTH(CURDATE())";
            break;
        case 'personalizado':
            if ($data_ini) { $sql .= " AND DATE(r.DATA_REGISTRO) >= ?"; $params[] = $data_ini; }
            if ($data_fim) { $sql .= " AND DATE(r.DATA_REGISTRO) <= ?"; $params[] = $data_fim; }
            break;
    }

    // Tipo de relatório: AGENDAMENTOS_FUTURO filtra apenas com agendamento futuro
    if (($post['tipo'] ?? '') === 'AGENDAMENTOS_FUTURO') {
        $sql .= " AND r.DATA_AGENDAMENTO IS NOT NULL AND r.DATA_AGENDAMENTO >= NOW()";
    }

    return $sql;
}

// =========================================================================
// AÇÃO: Listar opções dos filtros
// =========================================================================
if ($acao === 'listar_filtros') {
    try {
        $empresas = [];
        if ($is_master) {
            $st = $pdo->query("SELECT ID, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC");
            $empresas = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // Campanhas com filtro de hierarquia
        $sqlCamp = "SELECT ID, NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE 1=1";
        $paramsCamp = [];
        if (!$is_master && $id_empresa_num) {
            $sqlCamp .= " AND (id_empresa = ? OR id_empresa IS NULL)";
            $paramsCamp[] = $id_empresa_num;
        }
        if ($is_consultor) {
            $sqlCamp .= " AND (id_usuario = ? OR FIND_IN_SET(?, CPF_USUARIO))";
            $paramsCamp[] = $id_usuario_num;
            $paramsCamp[] = $cpf_logado;
        }
        $sqlCamp .= " ORDER BY NOME_CAMPANHA ASC";
        $stCamp = $pdo->prepare($sqlCamp);
        $stCamp->execute($paramsCamp);
        $campanhas = $stCamp->fetchAll(PDO::FETCH_ASSOC);

        // Status campanha
        $stStat = $pdo->query("SELECT ID, NOME_STATUS FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO WHERE COALESCE(TIPO_CONTATO,'') != 'FICHA_REGISTRO' ORDER BY NOME_STATUS ASC");
        $status = $stStat->fetchAll(PDO::FETCH_ASSOC);

        // Usuários com filtro de hierarquia
        $sqlUsu = "SELECT u.ID, u.NOME FROM CLIENTE_USUARIO u WHERE u.Situação = 'ativo'";
        $paramsUsu = [];
        if (!$is_master && $id_empresa_num) {
            $sqlUsu .= " AND u.id_empresa = ?";
            $paramsUsu[] = $id_empresa_num;
        }
        $sqlUsu .= " ORDER BY u.NOME ASC";
        $stUsu = $pdo->prepare($sqlUsu);
        $stUsu->execute($paramsUsu);
        $usuarios = $stUsu->fetchAll(PDO::FETCH_ASSOC);

        // Campanhas disponíveis para "incluir em campanha" (destino)
        $stCampDest = $pdo->prepare($sqlCamp);
        $stCampDest->execute($paramsCamp);
        $campanhas_destino = $stCampDest->fetchAll(PDO::FETCH_ASSOC);

        // Módulos de consulta (para HISTORICO_CONSULTAS)
        $stMod = $pdo->query("SELECT DISTINCT MODULO_CONSULTA FROM BANCO_DE_DADOS_REGISTRO_CONSULTA WHERE MODULO_CONSULTA IS NOT NULL ORDER BY MODULO_CONSULTA ASC");
        $modulos = $stMod->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'success'           => true,
            'empresas'          => $empresas,
            'campanhas'         => $campanhas,
            'status'            => $status,
            'usuarios'          => $usuarios,
            'campanhas_destino' => $campanhas_destino,
            'modulos'           => $modulos,
            'is_master'         => $is_master,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// AÇÃO: Buscar dados — HISTÓRICO DE CONSULTAS (modelo separado)
// =========================================================================
if ($acao === 'buscar_dados' && ($_POST['tipo'] ?? '') === 'HISTORICO_CONSULTAS') {
    try {
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $limite = 100;
        $agrupamento = $_POST['agrupamento'] ?? 'modulo';

        // Hierarquia
        $params_hc = []; $where_hc = " WHERE 1=1";
        if (!$is_master) {
            if ($is_consultor || $somente_meus) {
                $where_hc .= " AND h.id_usuario = ?"; $params_hc[] = $id_usuario_num;
            } else {
                $where_hc .= " AND h.id_usuario IN (SELECT ID FROM CLIENTE_USUARIO WHERE id_empresa = ?)";
                $params_hc[] = $id_empresa_num;
            }
        }

        // Filtros
        $periodo  = $_POST['periodo'] ?? 'todos';
        $data_ini = $_POST['data_ini'] ?? '';
        $data_fim = $_POST['data_fim'] ?? '';
        switch ($periodo) {
            case 'hoje':   $where_hc .= " AND DATE(h.DATA_HORA) = CURDATE()"; break;
            case 'ontem':  $where_hc .= " AND DATE(h.DATA_HORA) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)"; break;
            case 'mes':    $where_hc .= " AND YEAR(h.DATA_HORA) = YEAR(CURDATE()) AND MONTH(h.DATA_HORA) = MONTH(CURDATE())"; break;
            case 'personalizado':
                if ($data_ini) { $where_hc .= " AND DATE(h.DATA_HORA) >= ?"; $params_hc[] = $data_ini; }
                if ($data_fim) { $where_hc .= " AND DATE(h.DATA_HORA) <= ?"; $params_hc[] = $data_fim; }
                break;
        }
        $usuario_sel = $_POST['usuario'] ?? []; $usuario_sel = array_filter(array_map('intval', (array)$usuario_sel));
        if (!empty($usuario_sel)) { $ph = implode(',', array_fill(0, count($usuario_sel), '?')); $where_hc .= " AND h.id_usuario IN ($ph)"; $params_hc = array_merge($params_hc, $usuario_sel); }
        $modulo_sel = trim($_POST['modulo'] ?? '');
        if ($modulo_sel !== '') { $where_hc .= " AND h.MODULO_CONSULTA = ?"; $params_hc[] = $modulo_sel; }
        $q_hc = trim($_POST['q'] ?? '');
        if ($q_hc !== '') { $qL = preg_replace('/\D/', '', $q_hc); if ($qL) { $where_hc .= " AND h.CPF_CLIENTE LIKE ?"; $params_hc[] = "%{$qL}%"; } else { $where_hc .= " AND (d.nome LIKE ? OR h.NOME_USUARIO LIKE ?)"; $params_hc[] = "%{$q_hc}%"; $params_hc[] = "%{$q_hc}%"; } }

        // Total
        $stCnt = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON d.cpf = h.CPF_CLIENTE {$where_hc}");
        $stCnt->execute($params_hc); $total = (int)$stCnt->fetchColumn();

        // Lista
        $params_l = $params_hc;
        $stL = $pdo->prepare("SELECT DATE_FORMAT(h.DATA_HORA,'%d/%m/%Y %H:%i') as DATA_BR, h.MODULO_CONSULTA, h.NOME_USUARIO, h.CPF_CLIENTE, COALESCE(d.nome, h.CPF_CLIENTE) as NOME_CLIENTE FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON d.cpf = h.CPF_CLIENTE {$where_hc} ORDER BY h.DATA_HORA DESC LIMIT {$limite} OFFSET {$offset}");
        $stL->execute($params_l); $lista = $stL->fetchAll(PDO::FETCH_ASSOC);

        // Gráfico
        $params_g = $params_hc;
        switch ($agrupamento) {
            case 'usuario':
                $sqlG = "SELECT COALESCE(h.NOME_USUARIO,'Sem Usuário') AS LABEL, COUNT(*) AS TOTAL FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON d.cpf = h.CPF_CLIENTE {$where_hc} GROUP BY h.NOME_USUARIO ORDER BY TOTAL DESC";
                $tipo_g = 'pie';
                break;
            case 'campanha': // "por dia" para histórico
                $sqlG = "SELECT DATE_FORMAT(h.DATA_HORA,'%d/%m') AS LABEL, COUNT(*) AS TOTAL FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON d.cpf = h.CPF_CLIENTE {$where_hc} GROUP BY DATE(h.DATA_HORA) ORDER BY DATE(h.DATA_HORA) ASC LIMIT 30";
                $tipo_g = 'bar';
                break;
            default: // modulo
                $sqlG = "SELECT COALESCE(h.MODULO_CONSULTA,'Sem Módulo') AS LABEL, COUNT(*) AS TOTAL FROM BANCO_DE_DADOS_REGISTRO_CONSULTA h LEFT JOIN dados_cadastrais d ON d.cpf = h.CPF_CLIENTE {$where_hc} GROUP BY h.MODULO_CONSULTA ORDER BY TOTAL DESC";
                $tipo_g = 'pie';
        }
        $stG = $pdo->prepare($sqlG); $stG->execute($params_g);
        $grafico_rows = $stG->fetchAll(PDO::FETCH_ASSOC);

        ob_end_clean(); echo json_encode(['success'=>true,'lista'=>$lista,'total'=>$total,'offset_atual'=>$offset,'tem_mais'=>($offset+$limite)<$total,'grafico'=>$grafico_rows,'tipo_grafico'=>$tipo_g]);
    } catch (Exception $e) { ob_end_clean(); echo json_encode(['success'=>false,'msg'=>$e->getMessage()]); }
    exit;
}

// =========================================================================
// AÇÃO: Buscar dados do relatório (lista + gráfico)
// =========================================================================
if ($acao === 'buscar_dados') {
    try {
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $limite = 100;

        $params_list   = [];
        $where_hierarq = filtroHierarquia($is_master, $is_consultor, $id_empresa_num, $id_usuario_num, $params_list, $somente_meus);
        $where_filtros = filtrosUsuario($_POST, $params_list, $is_master, $id_empresa_num);

        $where_base = " WHERE 1=1" . $where_hierarq . $where_filtros;

        // ORDER BY
        $tipo_rel = $_POST['tipo'] ?? 'STATUS_CAMPANHA';
        $order_by = $tipo_rel === 'AGENDAMENTOS_FUTURO'
            ? " ORDER BY r.DATA_AGENDAMENTO ASC"
            : " ORDER BY r.DATA_REGISTRO DESC";

        // Total (para mostrar contador)
        $params_count = $params_list;
        $sqlCount = "SELECT COUNT(*) FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r" . $where_base;
        $total = (int)$pdo->prepare($sqlCount)->execute($params_count) ? $pdo->prepare($sqlCount) : 0;
        $stCount = $pdo->prepare($sqlCount);
        $stCount->execute($params_count);
        $total = (int)$stCount->fetchColumn();

        // Lista
        $sqlList = "
            SELECT
                r.ID,
                r.CPF_CLIENTE,
                COALESCE(dc.nome, r.CPF_CLIENTE) AS NOME_CLIENTE,
                DATE_FORMAT(r.DATA_REGISTRO, '%d/%m/%Y %H:%i') AS DATA_REGISTRO_FMT,
                DATE_FORMAT(r.DATA_AGENDAMENTO, '%d/%m/%Y %H:%i') AS DATA_AGENDAMENTO_FMT,
                r.NOME_USUARIO,
                COALESCE(s.NOME_STATUS, '—') AS NOME_STATUS,
                r.REGISTRO AS ANOTACAO,
                (SELECT GROUP_CONCAT(DISTINCT ca.NOME_CAMPANHA ORDER BY ca.ID SEPARATOR ', ')
                 FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cc
                 JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS ca ON ca.ID = cc.ID_CAMPANHA
                 WHERE cc.CPF_CLIENTE = r.CPF_CLIENTE) AS CAMPANHAS
            FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
            LEFT JOIN dados_cadastrais dc ON dc.cpf = r.CPF_CLIENTE
            LEFT JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON s.ID = r.ID_STATUS_CONTATO
        " . $where_base . $order_by . " LIMIT {$limite} OFFSET {$offset}";

        $stList = $pdo->prepare($sqlList);
        $stList->execute($params_list);
        $lista = $stList->fetchAll(PDO::FETCH_ASSOC);

        // Gráfico
        $params_graf = [];
        $where_hierarq_g = filtroHierarquia($is_master, $is_consultor, $id_empresa_num, $id_usuario_num, $params_graf, $somente_meus);
        $where_filtros_g = filtrosUsuario($_POST, $params_graf, $is_master, $id_empresa_num);
        $where_graf = " WHERE 1=1" . $where_hierarq_g . $where_filtros_g;

        $agrupamento = $_POST['agrupamento'] ?? 'status';
        $grafico = [];
        if ($tipo_rel === 'AGENDAMENTOS_FUTURO') {
            $sqlGraf = "
                SELECT DATE_FORMAT(r.DATA_AGENDAMENTO, '%d/%m') AS LABEL, COUNT(*) AS TOTAL
                FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
                {$where_graf}
                AND r.DATA_AGENDAMENTO IS NOT NULL AND r.DATA_AGENDAMENTO >= CURDATE()
                GROUP BY DATE_FORMAT(r.DATA_AGENDAMENTO, '%Y-%m-%d')
                ORDER BY r.DATA_AGENDAMENTO ASC
                LIMIT 30
            ";
            $tipo_grafico = 'bar';
        } elseif ($agrupamento === 'campanha') {
            $sqlGraf = "
                SELECT COALESCE(ca.NOME_CAMPANHA, 'Sem Campanha') AS LABEL, COUNT(DISTINCT r.ID) AS TOTAL
                FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
                LEFT JOIN BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cc ON cc.CPF_CLIENTE = r.CPF_CLIENTE
                LEFT JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS ca ON ca.ID = cc.ID_CAMPANHA
                {$where_graf}
                GROUP BY ca.ID, ca.NOME_CAMPANHA
                ORDER BY TOTAL DESC
            ";
            $tipo_grafico = 'pie';
        } elseif ($agrupamento === 'usuario') {
            $sqlGraf = "
                SELECT COALESCE(r.NOME_USUARIO, 'Sem Usuário') AS LABEL, COUNT(*) AS TOTAL
                FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
                {$where_graf}
                GROUP BY r.NOME_USUARIO
                ORDER BY TOTAL DESC
            ";
            $tipo_grafico = 'pie';
        } else {
            // status (padrão)
            $sqlGraf = "
                SELECT COALESCE(s.NOME_STATUS, 'Sem Status') AS LABEL, COUNT(*) AS TOTAL
                FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
                LEFT JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON s.ID = r.ID_STATUS_CONTATO
                {$where_graf}
                GROUP BY r.ID_STATUS_CONTATO
                ORDER BY TOTAL DESC
            ";
            $tipo_grafico = 'pie';
        }
        $stGraf = $pdo->prepare($sqlGraf);
        $stGraf->execute($params_graf);
        $grafico_rows = $stGraf->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'lista'        => $lista,
            'total'        => $total,
            'offset_atual' => $offset,
            'tem_mais'     => ($offset + $limite) < $total,
            'grafico'      => $grafico_rows,
            'tipo_grafico' => $tipo_grafico,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// AÇÃO: Incluir CPFs em campanha
// =========================================================================
if ($acao === 'incluir_em_campanha') {
    try {
        $id_campanha_dest = intval($_POST['id_campanha_dest'] ?? 0);
        $cpfs_raw = $_POST['cpfs'] ?? [];
        if (!is_array($cpfs_raw)) $cpfs_raw = explode(',', $cpfs_raw);
        $cpfs = array_filter(array_map(function($c){ return preg_replace('/\D/', '', trim($c)); }, $cpfs_raw));

        if (!$id_campanha_dest || empty($cpfs)) {
            echo json_encode(['success' => false, 'msg' => 'Selecione ao menos um cliente e uma campanha.']); exit;
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) VALUES (?, ?)");
        $incluidos = 0;
        foreach ($cpfs as $cpf) {
            $stmt->execute([$cpf, $id_campanha_dest]);
            $incluidos += $stmt->rowCount();
        }
        echo json_encode(['success' => true, 'msg' => "{$incluidos} cliente(s) incluído(s) na campanha."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// =========================================================================
// AÇÃO: Exportar CSV (GET request)
// =========================================================================
if ($acao === 'exportar_csv' && !$perm_exportar) {
    echo json_encode(['success' => false, 'msg' => 'Sem permissão para exportar.']); exit;
}
if ($acao === 'exportar_csv') {
    $post = $_GET; // export uses GET params
    $params_exp  = [];
    $where_hierarq_e = filtroHierarquia($is_master, $is_consultor, $id_empresa_num, $id_usuario_num, $params_exp, $somente_meus);
    $where_filtros_e = filtrosUsuario($post, $params_exp, $is_master, $id_empresa_num);
    $where_exp = " WHERE 1=1" . $where_hierarq_e . $where_filtros_e;

    $tipo_rel = $post['tipo'] ?? 'STATUS_CAMPANHA';
    $order_exp = $tipo_rel === 'AGENDAMENTOS_FUTURO' ? " ORDER BY r.DATA_AGENDAMENTO ASC" : " ORDER BY r.DATA_REGISTRO DESC";

    // CPFs selecionados (exportar apenas selecionados)
    $cpfs_sel_raw = $post['cpfs_selecionados'] ?? '';
    $cpfs_sel = array_filter(array_map('trim', explode(',', $cpfs_sel_raw)));

    if (!empty($cpfs_sel)) {
        $placeholders = implode(',', array_fill(0, count($cpfs_sel), '?'));
        $where_exp .= " AND r.CPF_CLIENTE IN ($placeholders)";
        $params_exp = array_merge($params_exp, $cpfs_sel);
    }

    $sqlExp = "
        SELECT
            r.ID,
            r.CPF_CLIENTE,
            COALESCE(dc.nome, '') AS NOME_CLIENTE,
            DATE_FORMAT(r.DATA_REGISTRO, '%d/%m/%Y %H:%i') AS DATA_REGISTRO,
            DATE_FORMAT(r.DATA_AGENDAMENTO, '%d/%m/%Y %H:%i') AS DATA_AGENDAMENTO,
            r.NOME_USUARIO,
            COALESCE(s.NOME_STATUS, '') AS STATUS,
            (SELECT GROUP_CONCAT(DISTINCT ca.NOME_CAMPANHA ORDER BY ca.ID SEPARATOR ' | ')
             FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cc
             JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS ca ON ca.ID = cc.ID_CAMPANHA
             WHERE cc.CPF_CLIENTE = r.CPF_CLIENTE) AS CAMPANHAS,
            r.REGISTRO AS ANOTACAO
        FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
        LEFT JOIN dados_cadastrais dc ON dc.cpf = r.CPF_CLIENTE
        LEFT JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON s.ID = r.ID_STATUS_CONTATO
    " . $where_exp . $order_exp;

    $stExp = $pdo->prepare($sqlExp);
    $stExp->execute($params_exp);

    $nome_arquivo = 'relatorio_campanha_' . date('Ymd_His') . '.csv';
    // Override Content-Type for CSV download
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, ['ID', 'CPF', 'NOME', 'DATA REGISTRO', 'DATA AGENDAMENTO', 'USUÁRIO', 'STATUS', 'CAMPANHAS', 'ANOTAÇÃO'], ';');
    while ($row = $stExp->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, [
            $row['ID'], $row['CPF_CLIENTE'], $row['NOME_CLIENTE'],
            $row['DATA_REGISTRO'], $row['DATA_AGENDAMENTO'],
            $row['NOME_USUARIO'], $row['STATUS'], $row['CAMPANHAS'], $row['ANOTACAO']
        ], ';');
    }
    fclose($out);
    exit;
}

echo json_encode(['success' => false, 'msg' => 'Ação desconhecida.']);
