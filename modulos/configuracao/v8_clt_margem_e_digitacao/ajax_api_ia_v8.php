<?php
// Arquivo: ajax_api_ia_v8.php
// Controlador exclusivo para a interface (aba) de gestão da Inteligência Artificial no CRM
ob_start();
ini_set('display_errors', 0);
error_reporting(0);
session_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    $pdo->exec("SET NAMES utf8mb4");
    try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IA_CREDENCIAIS ADD COLUMN NOTIF_SIMULACAO TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}
    try { $pdo->exec("ALTER TABLE INTEGRACAO_V8_IA_CREDENCIAIS ADD COLUMN NOTIF_PROPOSTA  TINYINT(1) NOT NULL DEFAULT 1"); } catch(Exception $e){}

    $acao = $_POST['acao'] ?? $_GET['acao'] ?? '';
    $usuario_logado_cpf = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
    
    // Trava de segurança básica do CRM
    $caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
    if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

    $tem_permissao = function_exists('verificaPermissao') ? verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_NOVA_CONSULTA_DIGITAÇÃO', 'FUNCAO') : true;
    if(!$tem_permissao) {
        throw new Exception("Seu usuário não tem permissão para gerenciar a API da IA.");
    }

    // Regras de hierarquia de visibilidade
    $restricao_meu_regist  = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA_MEU_REGISTRO', 'FUNCAO');
    $restricao_hierarquia  = function_exists('verificaPermissaoEstrita') ? !verificaPermissaoEstrita($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA_HIERARQUIA') : !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA_HIERARQUIA', 'FUNCAO');

    // Busca id_empresa do usuário logado (só quando necessário)
    $id_empresa_logado = null;
    if ($restricao_hierarquia && !$restricao_meu_regist) {
        $stmtEmp = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmp->execute([$usuario_logado_cpf]);
        $id_empresa_logado = $stmtEmp->fetchColumn();
    }

    switch ($acao) {
        
        case 'listar_tokens':
            $sql = "SELECT c.*, v.CLIENTE_NOME as NOME_CHAVE_V8
                    FROM INTEGRACAO_V8_IA_CREDENCIAIS c
                    LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO v ON c.CHAVE_V8_ID = v.ID";
            $params = [];

            if ($restricao_meu_regist) {
                $sql .= " WHERE c.CPF_DONO = ?";
                $params[] = $usuario_logado_cpf;
            } elseif ($restricao_hierarquia && $id_empresa_logado) {
                $sql .= " WHERE c.CPF_DONO IN (SELECT CPF FROM CLIENTE_USUARIO WHERE id_empresa = ?)";
                $params[] = $id_empresa_logado;
            }

            $sql .= " ORDER BY c.ID DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_end_clean(); echo json_encode(['success' => true, 'data' => $dados]); exit;

        case 'salvar_token':
            $nome_robo = mb_strtoupper(trim($_POST['nome_robo'] ?? ''), 'UTF-8');
            $chave_v8_id = (int)($_POST['chave_v8_id'] ?? 0);

            if (empty($nome_robo) || $chave_v8_id <= 0) throw new Exception("Dados incompletos.");

            // Descobre o CPF do dono automático lendo a Chave selecionada
            $stmtChave = $pdo->prepare("SELECT ID, CPF_USUARIO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); 
            $stmtChave->execute([$chave_v8_id]);
            $chaveV8 = $stmtChave->fetch(PDO::FETCH_ASSOC);
            
            if (!$chaveV8) throw new Exception("A chave V8 selecionada não é válida.");

            $cpf_dono = !empty($chaveV8['CPF_USUARIO']) ? $chaveV8['CPF_USUARIO'] : $usuario_logado_cpf;

            $stmtCpf = $pdo->prepare("SELECT CPF FROM CLIENTE_CADASTRO WHERE CPF = ?"); 
            $stmtCpf->execute([$cpf_dono]);
            if (!$stmtCpf->fetch()) throw new Exception("O CPF do dono desta Chave não foi encontrado na base de clientes.");

            $token_ia = 'V8IA_' . bin2hex(random_bytes(20));

            $sqlInsert = "INSERT INTO INTEGRACAO_V8_IA_CREDENCIAIS (CPF_DONO, CHAVE_V8_ID, NOME_ROBO, TOKEN_IA, STATUS) VALUES (?, ?, ?, ?, 'ATIVO')";
            $pdo->prepare($sqlInsert)->execute([$cpf_dono, $chave_v8_id, $nome_robo, $token_ia]);

            ob_end_clean(); echo json_encode(['success' => true, 'msg' => "Token gerado com sucesso!"]); exit;

        case 'editar_token':
            $id = (int)($_POST['id'] ?? 0);
            $nome_robo = mb_strtoupper(trim($_POST['nome_robo'] ?? ''), 'UTF-8');
            $chave_v8_id = (int)($_POST['chave_v8_id'] ?? 0);
            
            if ($id <= 0 || empty($nome_robo) || $chave_v8_id <= 0) throw new Exception("Dados incompletos.");
            
            // Descobre o CPF do dono automático caso ele tenha trocado a Chave V8
            $stmtChave = $pdo->prepare("SELECT ID, CPF_USUARIO FROM INTEGRACAO_V8_CHAVE_ACESSO WHERE ID = ?"); 
            $stmtChave->execute([$chave_v8_id]);
            $chaveV8 = $stmtChave->fetch(PDO::FETCH_ASSOC);
            
            if (!$chaveV8) throw new Exception("A chave V8 selecionada não é válida.");

            $cpf_dono = !empty($chaveV8['CPF_USUARIO']) ? $chaveV8['CPF_USUARIO'] : $usuario_logado_cpf;

            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_CREDENCIAIS SET NOME_ROBO = ?, CHAVE_V8_ID = ?, CPF_DONO = ? WHERE ID = ?")->execute([$nome_robo, $chave_v8_id, $cpf_dono, $id]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Dados do Robô atualizados com sucesso!']); exit;

        case 'revogar_token':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido.");
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_CREDENCIAIS SET STATUS = 'INATIVO' WHERE ID = ?")->execute([$id]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Token revogado com sucesso.']); exit;

        case 'reativar_token':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido.");
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_CREDENCIAIS SET STATUS = 'ATIVO' WHERE ID = ?")->execute([$id]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Token reativado com sucesso!']); exit;

        case 'gerar_novo_token_existente':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido.");
            
            $novo_token_ia = 'V8IA_' . bin2hex(random_bytes(20));
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_CREDENCIAIS SET TOKEN_IA = ? WHERE ID = ?")->execute([$novo_token_ia, $id]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Novo Bearer gerado! O antigo parou de funcionar.']); exit;

        case 'excluir_token':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception("ID inválido.");
            $pdo->prepare("DELETE FROM INTEGRACAO_V8_IA_CREDENCIAIS WHERE ID = ?")->execute([$id]);
            ob_end_clean(); echo json_encode(['success' => true, 'msg' => 'Token excluído definitivamente.']); exit;

        case 'salvar_notificacoes':
            $id = (int)($_POST['id'] ?? 0);
            $notif_sim = (int)($_POST['notif_simulacao'] ?? 0) ? 1 : 0;
            $notif_pro = (int)($_POST['notif_proposta'] ?? 0) ? 1 : 0;
            if ($id <= 0) throw new Exception("ID inválido.");
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_CREDENCIAIS SET NOTIF_SIMULACAO = ?, NOTIF_PROPOSTA = ? WHERE ID = ?")->execute([$notif_sim, $notif_pro, $id]);
            ob_end_clean(); echo json_encode(['success' => true]); exit;

        case 'listar_notificacoes':
            $stmt = $pdo->prepare("SELECT n.*, DATE_FORMAT(n.DATA_CRIACAO, '%d/%m/%Y %H:%i') as DATA_BR
                FROM INTEGRACAO_V8_IA_NOTIFICACOES n
                WHERE n.CPF_DONO = ? AND n.LIDA = 0
                ORDER BY n.DATA_CRIACAO DESC LIMIT 50");
            $stmt->execute([$usuario_logado_cpf]);
            $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmtTot = $pdo->prepare("SELECT COUNT(*) FROM INTEGRACAO_V8_IA_NOTIFICACOES WHERE CPF_DONO = ? AND LIDA = 0");
            $stmtTot->execute([$usuario_logado_cpf]);
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $notifs, 'total' => (int)$stmtTot->fetchColumn()]); exit;

        case 'marcar_lidas':
            $pdo->prepare("UPDATE INTEGRACAO_V8_IA_NOTIFICACOES SET LIDA = 1 WHERE CPF_DONO = ?")->execute([$usuario_logado_cpf]);
            ob_end_clean(); echo json_encode(['success' => true]); exit;

        case 'listar_sessoes':
            $where = "";
            $params = [];

            if ($restricao_meu_regist) {
                $where = " AND cred.CPF_DONO = ?";
                $params[] = $usuario_logado_cpf;
            } elseif ($restricao_hierarquia && $id_empresa_logado) {
                $where = " AND cred.CPF_DONO IN (SELECT CPF FROM CLIENTE_USUARIO WHERE id_empresa = ?)";
                $params[] = $id_empresa_logado;
            }

            $sql = "SELECT s.ID as SESSAO_ID, s.STATUS_SESSAO, s.TELEFONE_CLIENTE, s.CPF_CLIENTE AS CPF_SESSAO,
                    DATE_FORMAT(s.DATA_INICIO, '%d/%m/%Y %H:%i') as DATA_INICIO_BR,
                    DATE_FORMAT(s.ULTIMA_ACAO, '%H:%i:%s') as ULTIMA_ACAO_BR,
                    c.CPF_CONSULTADO, c.NOME_COMPLETO, c.STATUS_V8, c.MENSAGEM_ERRO, c.FONTE_CONSULT_ID, c.CONSULT_ID,
                    DATE_FORMAT(c.ULTIMA_ATUALIZACAO, '%d/%m/%Y %H:%i') as DATA_RETORNO_BR,
                    sim.MARGEM_DISPONIVEL as VALOR_MARGEM, sim.PRAZOS_DISPONIVEIS as PRAZOS, sim.SIMULATION_ID,
                    sim.STATUS_CONFIG_ID, sim.OBS_CONFIG_ID, sim.OBS_SIMULATION_ID, sim.FONTE_CONSIG_ID,
                    sim.VALOR_LIBERADO, sim.VALOR_PARCELA, sim.PRAZO_SIMULACAO,
                    p.NUMERO_PROPOSTA, p.STATUS_PROPOSTA_V8 as STATUS_PROPOSTA_REAL_TIME, p.LINK_PROPOSTA,
                    cred.NOME_ROBO, cred.CPF_DONO, v8k.CLIENTE_NOME as NOME_CHAVE_V8
                    FROM INTEGRACAO_V8_IA_SESSAO s
                    LEFT JOIN INTEGRACAO_V8_IA_CREDENCIAIS cred ON s.TOKEN_IA_USADO = cred.TOKEN_IA
                    LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO v8k ON cred.CHAVE_V8_ID = v8k.ID
                    LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA c ON s.CONSULT_ID = c.CONSULT_ID AND c.CONSULT_ID IS NOT NULL
                    LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO sim ON sim.ID = (SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1)
                    LEFT JOIN INTEGRACAO_V8_REGISTRO_PROPOSTA p ON c.STATUS_V8 LIKE CONCAT('%', p.NUMERO_PROPOSTA, '%')
                    WHERE 1=1 {$where}
                    ORDER BY s.ULTIMA_ACAO DESC LIMIT 100";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ob_end_clean(); echo json_encode(['success' => true, 'data' => $dados]); exit;

        // -------------------------------------------------------
        // FORÇAR REPROCESSAMENTO DE SESSÃO AGUARDANDO_DATAPREV
        // -------------------------------------------------------
        case 'forcar_sessao':
            $sessao_id_fc = (int)($_POST['sessao_id'] ?? 0);
            $consult_id_fc = trim($_POST['consult_id'] ?? '');

            if ($sessao_id_fc <= 0 || empty($consult_id_fc)) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Parâmetros inválidos.']); exit;
            }

            // Carrega a sessão e credencial
            $stmtSessao = $pdo->prepare("
                SELECT s.*, c.STATUS_V8 as STATUS_CONSULT, c.ID as CONSULT_DB_ID,
                       cred.TOKEN_IA, cred.CPF_DONO, cred.CHAVE_V8_ID,
                       v8k.USERNAME_API, v8k.PASSWORD_API, v8k.CLIENT_ID, v8k.AUDIENCE, v8k.TABELA_PADRAO, v8k.PRAZO_PADRAO
                FROM INTEGRACAO_V8_IA_SESSAO s
                LEFT JOIN INTEGRACAO_V8_IA_CREDENCIAIS cred ON s.TOKEN_IA_USADO = cred.TOKEN_IA
                LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO v8k ON cred.CHAVE_V8_ID = v8k.ID
                LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA c ON c.CONSULT_ID = ?
                WHERE s.ID = ? LIMIT 1
            ");
            $stmtSessao->execute([$consult_id_fc, $sessao_id_fc]);
            $sessao_fc = $stmtSessao->fetch(PDO::FETCH_ASSOC);

            if (!$sessao_fc || $sessao_fc['STATUS_SESSAO'] !== 'AGUARDANDO_DATAPREV') {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Sessão não está aguardando ou não encontrada.']); exit;
            }

            // Força o worker para esta sessão inserindo/resetando na fila de follow-up
            $pdo->prepare("INSERT INTO INTEGRACAO_V8_IA_FOLLOWUP (CPF_CLIENTE, TELEFONE, CONSULT_ID, TOKEN_IA, AGENT_ID)
                           VALUES (?, ?, ?, ?, (SELECT AGENT_ID FROM INTEGRACAO_GPTMAKE_CONFIG WHERE CPF_USUARIO = ? AND STATUS = 'ATIVO' ORDER BY ID DESC LIMIT 1))
                           ON DUPLICATE KEY UPDATE STATUS='PENDENTE', TENTATIVAS=0, DATA_ULTIMA_TENTATIVA=NULL")
                ->execute([$sessao_fc['CPF_CLIENTE'], $sessao_fc['TELEFONE_CLIENTE'], $consult_id_fc, $sessao_fc['TOKEN_IA'], $sessao_fc['CPF_DONO']]);

            // Executa o worker diretamente (síncrono para resposta imediata)
            $worker_path = __DIR__ . '/worker_gptmake_followup.php';
            if (file_exists($worker_path)) {
                // Executa em background para não travar a requisição
                shell_exec("php " . escapeshellarg($worker_path) . " > /dev/null 2>&1 &");
            }

            ob_end_clean();
            echo json_encode(['success' => true, 'msg' => 'Worker acionado! O status será atualizado em instantes. Clique em "Atualizar Painel" após alguns segundos.']);
            exit;

        // -------------------------------------------------------
        // DOWNLOAD DO LOG JSON DA SESSÃO
        // -------------------------------------------------------
        case 'download_log_json':
            $cpf_log   = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
            $sessao_id_log = (int)($_GET['sessao_id'] ?? 0);

            if (empty($cpf_log) || $sessao_id_log <= 0) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Parâmetros inválidos.']); exit;
            }

            // Busca dados completos da sessão
            $stmtLog = $pdo->prepare("
                SELECT s.*, c.CPF_CONSULTADO, c.NOME_COMPLETO, c.STATUS_V8, c.MENSAGEM_ERRO, c.CONSULT_ID, c.FONTE_CONSULT_ID,
                    sim.MARGEM_DISPONIVEL, sim.VALOR_LIBERADO, sim.VALOR_PARCELA, sim.PRAZO_SIMULACAO, sim.SIMULATION_ID, sim.NOME_TABELA,
                    p.NUMERO_PROPOSTA, p.STATUS_PROPOSTA_V8, p.LINK_PROPOSTA,
                    cred.NOME_ROBO, cred.CPF_DONO, v8k.CLIENTE_NOME as NOME_CHAVE_V8
                FROM INTEGRACAO_V8_IA_SESSAO s
                LEFT JOIN INTEGRACAO_V8_IA_CREDENCIAIS cred ON s.TOKEN_IA_USADO = cred.TOKEN_IA
                LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO v8k ON cred.CHAVE_V8_ID = v8k.ID
                LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA c ON s.CONSULT_ID = c.CONSULT_ID
                LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO sim ON sim.ID = (SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1)
                LEFT JOIN INTEGRACAO_V8_REGISTRO_PROPOSTA p ON c.STATUS_V8 LIKE CONCAT('%', p.NUMERO_PROPOSTA, '%')
                WHERE s.ID = ? AND (s.CPF_CLIENTE = ? OR c.CPF_CONSULTADO = ?)
                LIMIT 1
            ");
            $stmtLog->execute([$sessao_id_log, $cpf_log, $cpf_log]);
            $dadosLog = $stmtLog->fetch(PDO::FETCH_ASSOC);

            if (!$dadosLog) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Sessão não encontrada.']); exit;
            }

            // Também busca o log em arquivo se existir
            $dirLogs = __DIR__ . '/../../../logs_v8/logs_automacao/';
            $logArquivo = null;
            $padroes = glob($dirLogs . "log_cpf_{$cpf_log}_*.txt");
            if (!empty($padroes)) {
                usort($padroes, fn($a,$b) => filemtime($b) - filemtime($a));
                $logArquivo = file_get_contents($padroes[0]);
            }

            $payload = [
                'sessao_id'    => $sessao_id_log,
                'gerado_em'    => date('d/m/Y H:i:s'),
                'dados_sessao' => $dadosLog,
                'log_arquivo'  => $logArquivo ? explode("\n", $logArquivo) : null
            ];

            $nomeArq = "log_ia_cpf{$cpf_log}_sessao{$sessao_id_log}.json";
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $nomeArq . '"');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit;

        // -------------------------------------------------------
        // VER LOG JSON NO POPUP (sem download)
        // -------------------------------------------------------
        case 'ver_log_json':
            $cpf_log   = preg_replace('/\D/', '', $_GET['cpf'] ?? $_POST['cpf'] ?? '');
            $sessao_id_log = (int)($_GET['sessao_id'] ?? $_POST['sessao_id'] ?? 0);

            if (empty($cpf_log) || $sessao_id_log <= 0) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Parâmetros inválidos.']); exit;
            }

            $stmtLog2 = $pdo->prepare("
                SELECT s.*, c.CPF_CONSULTADO, c.NOME_COMPLETO, c.STATUS_V8, c.MENSAGEM_ERRO, c.CONSULT_ID, c.FONTE_CONSULT_ID,
                    sim.MARGEM_DISPONIVEL, sim.VALOR_LIBERADO, sim.VALOR_PARCELA, sim.PRAZO_SIMULACAO, sim.SIMULATION_ID, sim.NOME_TABELA,
                    p.NUMERO_PROPOSTA, p.STATUS_PROPOSTA_V8, p.LINK_PROPOSTA,
                    cred.NOME_ROBO, cred.CPF_DONO, v8k.CLIENTE_NOME as NOME_CHAVE_V8
                FROM INTEGRACAO_V8_IA_SESSAO s
                LEFT JOIN INTEGRACAO_V8_IA_CREDENCIAIS cred ON s.TOKEN_IA_USADO = cred.TOKEN_IA
                LEFT JOIN INTEGRACAO_V8_CHAVE_ACESSO v8k ON cred.CHAVE_V8_ID = v8k.ID
                LEFT JOIN INTEGRACAO_V8_REGISTROCONSULTA c ON s.CONSULT_ID = c.CONSULT_ID
                LEFT JOIN INTEGRACAO_V8_REGISTRO_SIMULACAO sim ON sim.ID = (SELECT ID FROM INTEGRACAO_V8_REGISTRO_SIMULACAO s2 WHERE s2.ID_FILA = c.ID ORDER BY s2.ID DESC LIMIT 1)
                LEFT JOIN INTEGRACAO_V8_REGISTRO_PROPOSTA p ON c.STATUS_V8 LIKE CONCAT('%', p.NUMERO_PROPOSTA, '%')
                WHERE s.ID = ? AND (s.CPF_CLIENTE = ? OR c.CPF_CONSULTADO = ?)
                LIMIT 1
            ");
            $stmtLog2->execute([$sessao_id_log, $cpf_log, $cpf_log]);
            $dadosLog2 = $stmtLog2->fetch(PDO::FETCH_ASSOC);

            if (!$dadosLog2) {
                ob_end_clean(); echo json_encode(['success' => false, 'msg' => 'Sessão não encontrada.']); exit;
            }

            $dirLogs2   = __DIR__ . '/../../../logs_v8/logs_automacao/';
            $logArq2    = null;
            $padroes2   = glob($dirLogs2 . "log_cpf_{$cpf_log}_*.txt");
            if (!empty($padroes2)) {
                usort($padroes2, fn($a,$b) => filemtime($b) - filemtime($a));
                $logArq2 = file_get_contents($padroes2[0]);
            }

            $payload2 = [
                'sessao_id'    => $sessao_id_log,
                'gerado_em'    => date('d/m/Y H:i:s'),
                'dados_sessao' => $dadosLog2,
                'log_arquivo'  => $logArq2 ? explode("\n", $logArq2) : null
            ];

            ob_end_clean();
            echo json_encode(['success' => true, 'json_content' => $payload2, 'filename' => "log_ia_cpf{$cpf_log}_sessao{$sessao_id_log}.json"]);
            exit;

        default: throw new Exception("Ação não reconhecida pelo painel da IA.");
    }
} catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>