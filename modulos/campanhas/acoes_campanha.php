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

// Colunas ID_RESERVA_USUARIO e DATA_RESERVA já existem na tabela.

// =========================================================================
// HEARTBEAT — renova a reserva do cliente (chamado pelo JS a cada 60s)
// =========================================================================
if (isset($_POST['acao']) && $_POST['acao'] === 'heartbeat_campanha') {
    header('Content-Type: application/json');
    $cpf_cli  = preg_replace('/[^0-9]/', '', $_POST['cpf_cliente'] ?? '');
    $id_camp  = (int)($_POST['id_campanha'] ?? 0);
    $cpf_usr  = $_SESSION['usuario_cpf'] ?? null;
    if ($cpf_cli && $id_camp && $cpf_usr) {
        try {
            $stmtHBU = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtHBU->execute([$cpf_usr]);
            $id_hb = $stmtHBU->fetchColumn();
            if ($id_hb) {
                $pdo->prepare("UPDATE BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA SET DATA_RESERVA = NOW() WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ? AND ID_RESERVA_USUARIO = ?")
                    ->execute([$cpf_cli, $id_camp, $id_hb]);
            }
        } catch(Exception $e){}
    }
    echo json_encode(['ok' => true]); exit;
}

// =========================================================================
// LIBERAR — devolve cliente à fila (chamado via sendBeacon ao sair)
// =========================================================================
if (isset($_POST['acao']) && $_POST['acao'] === 'liberar_cliente_campanha') {
    header('Content-Type: application/json');
    $cpf_cli  = preg_replace('/[^0-9]/', '', $_POST['cpf_cliente'] ?? '');
    $id_camp  = (int)($_POST['id_campanha'] ?? 0);
    $cpf_usr  = $_SESSION['usuario_cpf'] ?? null;
    if ($cpf_cli && $id_camp && $cpf_usr) {
        try {
            $stmtLBU = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stmtLBU->execute([$cpf_usr]);
            $id_lb = $stmtLBU->fetchColumn();
            if ($id_lb) {
                $pdo->prepare("UPDATE BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA SET ID_RESERVA_USUARIO = NULL, DATA_RESERVA = NULL WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ? AND ID_RESERVA_USUARIO = ?")
                    ->execute([$cpf_cli, $id_camp, $id_lb]);
            }
        } catch(Exception $e){}
    }
    echo json_encode(['ok' => true]); exit;
}

// =========================================================================
// 1. AÇÃO: SALVAR REGISTRO DE CONTATO (GERAL OU CAMPANHA)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_registro_contato') {
    
    $cpf_usuario = $_SESSION['usuario_cpf'] ?? null;
    $nome_usuario = $_SESSION['usuario_nome'] ?? 'SISTEMA';
    
    $retornar_json = !empty($_POST['formato']) && $_POST['formato'] === 'json';

    $id_status = $_POST['id_status'];
    $cpf_cliente = $_POST['cpf_cliente'];
    $id_campanha = !empty($_POST['id_campanha']) ? $_POST['id_campanha'] : null;
    $texto_registro = trim($_POST['texto_registro']);
    $data_agendamento = !empty($_POST['data_agendamento']) ? $_POST['data_agendamento'] : null;
    $telefone_discado = preg_replace('/[^0-9]/', '', $_POST['telefone_discado'] ?? '');

    // Novo formato: JSON de qualificações por telefone
    $telefones_qual = json_decode($_POST['telefones_qual'] ?? '[]', true) ?: [];
    $telefones_selecionados = json_decode($_POST['telefones_selecionados'] ?? '[]', true) ?: [];
    // Retrocompatibilidade: se enviou o antigo telefone_discado, converte
    if ($telefone_discado && empty($telefones_qual) && empty($telefones_selecionados)) {
        $telefones_selecionados = [$telefone_discado];
    }

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

        // Monta resumo de qualificações dos telefones para salvar junto ao registro
        $tel_qual_resumo = [];
        if (!empty($telefones_qual)) {
            // Busca nomes das qualificações
            $stmtQnomes = $pdo->prepare("SELECT ID, NOME_QUALIFICACAO FROM BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE");
            $stmtQnomes->execute();
            $mapQual = [];
            foreach ($stmtQnomes->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $mapQual[$q['ID']] = $q['NOME_QUALIFICACAO'];
            }
            foreach ($telefones_qual as $tq) {
                $tel_num = preg_replace('/[^0-9]/', '', $tq['telefone'] ?? '');
                $id_qual = !empty($tq['id_qualificacao']) ? (int)$tq['id_qualificacao'] : null;
                if ($tel_num) {
                    $tel_qual_resumo[] = [
                        'tel'  => $tel_num,
                        'qual' => $id_qual ? ($mapQual[$id_qual] ?? '') : ''
                    ];
                }
            }
        }
        $tel_qual_json = !empty($tel_qual_resumo) ? json_encode($tel_qual_resumo, JSON_UNESCAPED_UNICODE) : null;

        $stmtIns = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO (CPF_CLIENTE, CNPJ_EMPRESA, CPF_USUARIO, NOME_USUARIO, ID_STATUS_CONTATO, REGISTRO, TELEFONES_QUALIFICACAO, DATA_AGENDAMENTO, id_empresa, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtIns->execute([$cpf_cliente, $cnpj_empresa, $cpf_usuario, $nome_usuario, $id_status, $texto_registro, $tel_qual_json, $data_agendamento, $id_empresa_num, $id_usuario_num]);

        $stmtStatus = $pdo->prepare("SELECT ID_QUALIFICACAO, NOME_STATUS, MARCACAO FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO WHERE ID = ?");
        $stmtStatus->execute([$id_status]);
        $status_data = $stmtStatus->fetch(PDO::FETCH_ASSOC);

        $stmtQual = $pdo->prepare("UPDATE telefones SET ID_QUALIFICACAO = ? WHERE cpf = ? AND telefone_cel = ?");

        // Aplica a qualificação escolhida pelo usuário por telefone
        foreach ($telefones_qual as $tq) {
            $tel_num = preg_replace('/[^0-9]/', '', $tq['telefone'] ?? '');
            $id_qual = !empty($tq['id_qualificacao']) ? (int)$tq['id_qualificacao'] : null;
            if ($tel_num) {
                $stmtQual->execute([$id_qual, $cpf_cliente, $tel_num]);
            }
        }

        $tels_log = implode(', ', array_filter(array_map(function($tq){ return $tq['telefone'] ?? ''; }, $telefones_qual)));
        if (empty($tels_log)) $tels_log = implode(', ', $telefones_selecionados) ?: $telefone_discado;
        $texto_log = ($id_campanha ? "Modo Campanha: " : "Registro Avulso: ") . "Status [" . $status_data['NOME_STATUS'] . "] | Tel: " . $tels_log . " | Obs: " . $texto_registro;
        
        $stmtLog = $pdo->prepare("INSERT INTO dados_cadastrais_log_rodape (CPF_CLIENTE, NOME_USUARIO, TEXTO_REGISTRO, id_usuario, id_empresa) VALUES (?, ?, ?, ?, ?)");
        $stmtLog->execute([$cpf_cliente, $nome_usuario, $texto_log, $id_usuario_num, $id_empresa_num]);

        $marcacao = $status_data['MARCACAO'] ?? '';

        // ── Libera reserva do cliente atual ───────────────────────────────────
        if ($id_campanha && $id_usuario_num) {
            try {
                $pdo->prepare("UPDATE BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA SET ID_RESERVA_USUARIO = NULL, DATA_RESERVA = NULL WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ? AND ID_RESERVA_USUARIO = ?")
                    ->execute([$cpf_cliente, $id_campanha, $id_usuario_num]);
            } catch(Exception $e){}
        }

        // ── Regras por marcação ───────────────────────────────────────────────
        // SEM RETORNO       → remove cliente da campanha + avança para próximo
        // FINALIZAR ATENDIMENTO → remove cliente da campanha + avança para próximo
        // COM RETORNO       → mantém cliente na campanha + avança para próximo
        // Em todos os casos dentro de uma campanha: sempre avança para o próximo cliente

        $proximo_cpf  = null;
        $id_pos_atual = null;

        if ($id_campanha) {
            // Salva posição atual ANTES de deletar (necessário para query sequencial)
            $stmtPos = $pdo->prepare("SELECT ID FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ? LIMIT 1");
            $stmtPos->execute([$cpf_cliente, $id_campanha]);
            $id_pos_atual = (int)($stmtPos->fetchColumn() ?: 0);

            // Busca configuração da campanha
            $stmtCamp = $pdo->prepare("SELECT PARAMETRO_INICIO_ALEATORIO FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE ID = ?");
            $stmtCamp->execute([$id_campanha]);
            $aleatorio = $stmtCamp->fetchColumn();

            // SEM RETORNO e FINALIZAR ATENDIMENTO: remove o cliente da campanha
            if (in_array($marcacao, ['SEM RETORNO', 'FINALIZAR ATENDIMENTO'])) {
                try {
                    $pdo->prepare("DELETE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE CPF_CLIENTE = ? AND ID_CAMPANHA = ?")
                        ->execute([$cpf_cliente, $id_campanha]);
                } catch(Exception $e){}
            }

            // Busca o próximo cliente para TODOS os tipos de marcação em campanha
            $cond_reserva = " AND (c.ID_RESERVA_USUARIO IS NULL OR c.DATA_RESERVA < NOW() - INTERVAL 5 MINUTE OR c.ID_RESERVA_USUARIO = ?)";
            $sqlProx = "SELECT c.CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c WHERE c.ID_CAMPANHA = ? AND c.CPF_CLIENTE != ?{$cond_reserva}";
            $paramsProx = [$id_campanha, $cpf_cliente, $id_usuario_num];

            if ($aleatorio == 'SIM') {
                $sqlProx .= " ORDER BY RAND() LIMIT 1";
            } else {
                // Usa ID salvo antes da deleção (evita subquery em registro já removido)
                $sqlProx .= " AND c.ID > ? ORDER BY c.ID ASC LIMIT 1";
                $paramsProx[] = $id_pos_atual;
            }

            $stmtProx = $pdo->prepare($sqlProx);
            $stmtProx->execute($paramsProx);
            $proximo_cpf = $stmtProx->fetchColumn() ?: null;

            // Se não encontrou à frente, reinicia do começo (volta para o primeiro)
            if (!$proximo_cpf && $aleatorio == 'NAO') {
                $stmtLoop = $pdo->prepare("SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ? AND CPF_CLIENTE != ? AND (ID_RESERVA_USUARIO IS NULL OR DATA_RESERVA < NOW() - INTERVAL 5 MINUTE OR ID_RESERVA_USUARIO = ?) ORDER BY ID ASC LIMIT 1");
                $stmtLoop->execute([$id_campanha, $cpf_cliente, $id_usuario_num]);
                $proximo_cpf = $stmtLoop->fetchColumn() ?: null;
            }
        }

        $pdo->commit();

        if ($retornar_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'proximo_cpf' => $proximo_cpf ?: null, 'marcacao' => $marcacao]);
            exit;
        }

        // ── Redirecionamento por marcação ─────────────────────────────────────
        if ($marcacao === 'FINALIZAR ATENDIMENTO') {
            // Sai do modo campanha — redireciona sem id_campanha
            header("Location: /modulos/banco_dados/consulta.php?busca={$cpf_cliente}&cpf_selecionado={$cpf_cliente}&acao=visualizar");
        } elseif ($proximo_cpf && $id_campanha) {
            // Avança para o próximo cliente na campanha
            header("Location: /modulos/banco_dados/consulta.php?id_campanha={$id_campanha}&busca={$proximo_cpf}&cpf_selecionado={$proximo_cpf}&acao=visualizar");
        } else {
            // Sem próximo: volta para a busca da campanha
            header("Location: /modulos/banco_dados/consulta.php?id_campanha={$id_campanha}");
        }
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        if ($retornar_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
            exit;
        }
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