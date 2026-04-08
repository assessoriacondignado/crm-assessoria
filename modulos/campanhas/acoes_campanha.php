<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!file_exists($caminho_conexao)) { 
    die("Erro Crítico: Arquivo de conexão não encontrado."); 
}
include $caminho_conexao;

// =========================================================================
// 1. AÇÃO: SALVAR REGISTRO DE CONTATO (GERAL OU CAMPANHA)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_registro_contato') {
    
    $cpf_usuario = $_SESSION['usuario_cpf'] ?? null;
    $nome_usuario = $_SESSION['usuario_nome'] ?? 'SISTEMA';
    
    $id_status = $_POST['id_status'];
    $cpf_cliente = $_POST['cpf_cliente'];
    $id_campanha = !empty($_POST['id_campanha']) ? $_POST['id_campanha'] : null;
    $texto_registro = trim($_POST['texto_registro']);
    $data_agendamento = !empty($_POST['data_agendamento']) ? $_POST['data_agendamento'] : null;
    $telefone_discado = preg_replace('/[^0-9]/', '', $_POST['telefone_discado'] ?? '');

    try {
        $pdo->beginTransaction();

        $cnpj_empresa = null;
        $stmtEmp = $pdo->prepare("SELECT CNPJ FROM CLIENTE_EMPRESAS WHERE CPF_CLIENTE_CADASTRO = ? LIMIT 1");
        $stmtEmp->execute([$cpf_usuario]);
        $cnpj_empresa = $stmtEmp->fetchColumn();

        if (!$cnpj_empresa) {
            $stmtCnpj = $pdo->prepare("SELECT CNPJ FROM CLIENTE_CADASTRO WHERE CPF = ? LIMIT 1");
            $stmtCnpj->execute([$cpf_usuario]);
            $cnpj_empresa = $stmtCnpj->fetchColumn();
        }

        if (empty($cnpj_empresa)) { $cnpj_empresa = null; }

        $id_empresa_num = null;
        if ($cnpj_empresa) {
            $stmtIdEmp = $pdo->prepare("SELECT ID FROM CLIENTE_EMPRESAS WHERE CNPJ = ? LIMIT 1");
            $stmtIdEmp->execute([$cnpj_empresa]);
            $id_empresa_num = $stmtIdEmp->fetchColumn() ?: null;
        }

        $id_usuario_num = null;
        if ($cpf_usuario) {
            $stmtIdUsu = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtIdUsu->execute([$cpf_usuario]);
            $id_usuario_num = $stmtIdUsu->fetchColumn() ?: null;
        }

        $stmtIns = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO (CPF_CLIENTE, CNPJ_EMPRESA, CPF_USUARIO, NOME_USUARIO, ID_STATUS_CONTATO, REGISTRO, DATA_AGENDAMENTO, id_empresa, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$cpf_cliente, $cnpj_empresa, $cpf_usuario, $nome_usuario, $id_status, $texto_registro, $data_agendamento, $id_empresa_num, $id_usuario_num]);

        $stmtStatus = $pdo->prepare("SELECT ID_QUALIFICACAO, NOME_STATUS, MARCACAO FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO WHERE ID = ?");
        $stmtStatus->execute([$id_status]);
        $status_data = $stmtStatus->fetch(PDO::FETCH_ASSOC);
        
        if ($status_data && !empty($status_data['ID_QUALIFICACAO']) && !empty($telefone_discado)) {
            $stmtQual = $pdo->prepare("UPDATE telefones SET ID_QUALIFICACAO = ? WHERE cpf = ? AND telefone_cel = ?");
            $stmtQual->execute([$status_data['ID_QUALIFICACAO'], $cpf_cliente, $telefone_discado]);
        }

        $texto_log = ($id_campanha ? "Modo Campanha: " : "Registro Avulso: ") . "Status [" . $status_data['NOME_STATUS'] . "] | Tel: " . $telefone_discado . " | Obs: " . $texto_registro;
        
        $stmtLog = $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO, id_usuario, id_empresa) VALUES (?, ?, ?, ?, ?)");
        $stmtLog->execute([$cpf_cliente, $nome_usuario, $texto_log, $id_usuario_num, $id_empresa_num]);

        $proximo_cpf = null;
        
        // SÓ BUSCA O PRÓXIMO CLIENTE SE ESTIVER DENTRO DE UMA CAMPANHA
        if ($id_campanha && $status_data['MARCACAO'] === 'FINALIZAR ATENDIMENTO') {
            $stmtCamp = $pdo->prepare("SELECT PARAMETRO_INICIO_ALEATORIO FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE ID = ?");
            $stmtCamp->execute([$id_campanha]);
            $aleatorio = $stmtCamp->fetchColumn();

            $sqlProx = "SELECT c.CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c WHERE c.ID_CAMPANHA = ? AND c.CPF_CLIENTE != ?";
            $paramsProx = [$id_campanha, $cpf_cliente];

            if ($aleatorio == 'SIM') {
                $sqlProx .= " ORDER BY RAND() LIMIT 1";
            } else {
                $sqlProx .= " AND c.ID > (SELECT ID FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ? LIMIT 1) ORDER BY c.ID ASC LIMIT 1";
                $paramsProx[] = $cpf_cliente;
                $paramsProx[] = $id_campanha;
            }
            
            $stmtProx = $pdo->prepare($sqlProx);
            $stmtProx->execute($paramsProx);
            $proximo_cpf = $stmtProx->fetchColumn();

            if (!$proximo_cpf && $aleatorio == 'NAO') {
                $stmtProxLoop = $pdo->prepare("SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ? AND CPF_CLIENTE != ? ORDER BY ID ASC LIMIT 1");
                $stmtProxLoop->execute([$id_campanha, $cpf_cliente]);
                $proximo_cpf = $stmtProxLoop->fetchColumn();
            }
        }

        $pdo->commit();

        if ($proximo_cpf && $id_campanha) {
            header("Location: /modulos/banco_dados/consulta.php?id_campanha={$id_campanha}&busca={$proximo_cpf}&cpf_selecionado={$proximo_cpf}&acao=visualizar");
        } else {
            $url_camp = $id_campanha ? "&id_campanha={$id_campanha}" : "";
            header("Location: /modulos/banco_dados/consulta.php?busca={$cpf_cliente}&cpf_selecionado={$cpf_cliente}&acao=visualizar" . $url_camp);
        }
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        die("<div style='padding:20px; color:red; font-family:sans-serif;'><b>Erro ao salvar registro:</b> " . $e->getMessage() . "</div>");
    }
} 

elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'incluir_cliente_campanha') {
    $cpf_cliente = preg_replace('/[^0-9]/', '', $_POST['cpf_cliente']);
    $id_campanha = $_POST['id_campanha'];
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) VALUES (?, ?)");
        $stmt->execute([$cpf_cliente, $id_campanha]);
        header("Location: /modulos/banco_dados/consulta.php?id_campanha={$id_campanha}&busca={$cpf_cliente}&cpf_selecionado={$cpf_cliente}&acao=visualizar");
        exit;
    } catch (Exception $e) {
        die("Erro ao incluir cliente na campanha: " . $e->getMessage());
    }
} else { header("Location: /"); exit; }
?>