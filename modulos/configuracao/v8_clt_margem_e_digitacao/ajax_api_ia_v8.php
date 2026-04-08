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

    $acao = $_POST['acao'] ?? '';
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
    $restricao_hierarquia  = !verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_IA_HIERARQUIA', 'FUNCAO');

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

        default: throw new Exception("Ação não reconhecida pelo painel da IA.");
    }
} catch (Exception $e) { ob_end_clean(); echo json_encode(['success' => false, 'msg' => $e->getMessage()]); exit; }
?>