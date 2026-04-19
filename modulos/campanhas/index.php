<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!file_exists($caminho_conexao)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> Arquivo de conexão não encontrado.");
}
include $caminho_conexao;

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

if (!verificaPermissao($pdo, 'MENU_CAMPANHA', 'TELA')) {
    include $caminho_header;
    die("<div class='container mt-5'><div class='alert alert-danger text-center shadow-lg border-dark p-4 rounded-3'><h4 class='fw-bold mb-3'><i class='fas fa-ban'></i> Acesso Negado</h4><p class='mb-0'>Seu grupo de usuário não tem permissão para acessar o Menu de Campanhas.</p></div></div>");
}

$perm_visao_global = verificaPermissao($pdo, 'MENU_CAMPANHA_MEU_REGISTRO', 'FUNCAO');

// NOVAS PERMISSÕES DE CAMPANHA
$perm_camp_hierarquia = verificaPermissao($pdo, 'MENU_CAMPANHA_HIERARQUIA', 'TELA');
$perm_camp_conf_hierarquia = verificaPermissao($pdo, 'MENU_CAMPANHA_CONFIGURAR_HIERARQUIA', 'TELA');
$perm_camp_meu_cad = verificaPermissao($pdo, 'MENU_CAMPANHA_CONFIGURAR_MEU_CADASTRO', 'TELA');
$perm_camp_editar = verificaPermissao($pdo, 'MENU_CAMPANHA_CONFIGURAR_EDITAR', 'FUNCAO');
$perm_camp_excluir = verificaPermissao($pdo, 'MENU_CAMPANHA_CONFIGURAR_EXCLUIR', 'FUNCAO');

$cpf_logado = $_SESSION['usuario_cpf'];
$grupo_camp = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master_camp_gestao = in_array($grupo_camp, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// =========================================================================
// Captura IDs Hierárquicos (Regra de Ouro com Pára-quedas Automático)
// =========================================================================
$id_usuario_logado_num = null;
$id_empresa_logado_num = null;
$cnpj_minha_empresa = null;
$nome_minha_empresa = 'Sem Empresa Vinculada';

// 1. Tenta buscar pelo novo padrão (Dual Write)
$stmtUsuIDs = $pdo->prepare("SELECT ID as id_usr, id_empresa as id_emp FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
$stmtUsuIDs->execute([$cpf_logado]);
$dados_ids = $stmtUsuIDs->fetch(PDO::FETCH_ASSOC);

if ($dados_ids) {
    $id_usuario_logado_num = $dados_ids['id_usr'];
    $id_empresa_logado_num = $dados_ids['id_emp'];
}

// 2. PÁRA-QUEDAS: Se o id_empresa numérico estiver vazio (Usuário Antigo)
if (empty($id_empresa_logado_num)) {
    $stmtCnpjLegado = $pdo->prepare("SELECT CNPJ FROM CLIENTE_CADASTRO WHERE CPF = ? LIMIT 1");
    $stmtCnpjLegado->execute([$cpf_logado]);
    $cnpj_legado = $stmtCnpjLegado->fetchColumn();

    if ($cnpj_legado) {
        $stmtEmpLegado = $pdo->prepare("SELECT ID, CNPJ, NOME_CADASTRO FROM CLIENTE_EMPRESAS WHERE CNPJ = ? LIMIT 1");
        $stmtEmpLegado->execute([$cnpj_legado]);
        $emp_legada = $stmtEmpLegado->fetch(PDO::FETCH_ASSOC);
        
        if ($emp_legada) {
            $id_empresa_logado_num = $emp_legada['ID'];
            $cnpj_minha_empresa = $emp_legada['CNPJ'];
            $nome_minha_empresa = $emp_legada['NOME_CADASTRO'];
            
            // Auto-Povoamento: Atualiza o banco de dados do usuário silenciosamente
            $pdo->prepare("UPDATE CLIENTE_USUARIO SET id_empresa = ? WHERE CPF = ?")->execute([$id_empresa_logado_num, $cpf_logado]);
        }
    }
} else {
    // Se ele já tinha o id_empresa, só busca os nomes no banco para exibir na tela
    $stmtNomeEmp = $pdo->prepare("SELECT CNPJ, NOME_CADASTRO FROM CLIENTE_EMPRESAS WHERE ID = ? LIMIT 1");
    $stmtNomeEmp->execute([$id_empresa_logado_num]);
    $dados_emp = $stmtNomeEmp->fetch(PDO::FETCH_ASSOC);
    if ($dados_emp) {
        $cnpj_minha_empresa = $dados_emp['CNPJ'];
        $nome_minha_empresa = $dados_emp['NOME_CADASTRO'];
    }
}

$erro_banco = null;
$mensagem_alerta = '';

// =========================================================================
// AÇÕES CRUD DE CAMPANHAS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_crud'])) {
    $acao = $_POST['acao_crud'];
    
    try {
        $pdo->beginTransaction();

        if ($acao === 'salvar_campanha') {
            $id = $_POST['id_campanha'] ?? '';
            $nome = trim($_POST['nome_campanha']);
            $data_inicio = $_POST['data_inicio'];
            $data_fim = $_POST['data_fim'];
            $status = $_POST['status'];
            $inicio_aleatorio = $_POST['parametro_inicio_aleatorio'];
            
            $cnpj_empresa_salvar = $perm_visao_global ? (!empty($_POST['cnpj_empresa']) ? $_POST['cnpj_empresa'] : null) : $cnpj_minha_empresa;
            
            // Descobre o ID numérico da empresa selecionada para o Dual Write
            $id_empresa_num = null;
            if ($cnpj_empresa_salvar) {
                $stmtIdEmp = $pdo->prepare("SELECT ID FROM CLIENTE_EMPRESAS WHERE CNPJ = ? LIMIT 1");
                $stmtIdEmp->execute([$cnpj_empresa_salvar]);
                $id_empresa_num = $stmtIdEmp->fetchColumn() ?: null;
            }

            $ids_status = isset($_POST['ids_status']) ? implode(',', $_POST['ids_status']) : null;
            $cpfs_usuarios = isset($_POST['cpfs_usuarios']) ? implode(',', $_POST['cpfs_usuarios']) : null;

            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_CAMPANHA_CAMPANHAS 
                    (CNPJ_EMPRESA, NOME_CAMPANHA, DATA_INICIO, DATA_FIM, IDS_STATUS_CONTATOS, CPF_USUARIO, STATUS, PARAMETRO_INICIO_ALEATORIO, id_empresa, id_usuario) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cnpj_empresa_salvar, $nome, $data_inicio, $data_fim, $ids_status, $cpfs_usuarios, $status, $inicio_aleatorio, $id_empresa_num, $id_usuario_logado_num]);
                $mensagem_alerta = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle'></i> Campanha criada com sucesso!</div>";
            } else {
                if($perm_camp_editar) {
                    $stmt = $pdo->prepare("UPDATE BANCO_DE_DADOS_CAMPANHA_CAMPANHAS 
                        SET CNPJ_EMPRESA=?, NOME_CAMPANHA=?, DATA_INICIO=?, DATA_FIM=?, IDS_STATUS_CONTATOS=?, CPF_USUARIO=?, STATUS=?, PARAMETRO_INICIO_ALEATORIO=?, id_empresa=? 
                        WHERE ID=?");
                    $stmt->execute([$cnpj_empresa_salvar, $nome, $data_inicio, $data_fim, $ids_status, $cpfs_usuarios, $status, $inicio_aleatorio, $id_empresa_num, $id]);
                    $mensagem_alerta = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle'></i> Campanha atualizada com sucesso!</div>";
                } else {
                    $mensagem_alerta = "<div class='alert alert-danger fw-bold shadow-sm'><i class='fas fa-ban'></i> Acesso Negado: Você não tem permissão para editar.</div>";
                }
            }
        } elseif ($acao === 'excluir_campanha' && $perm_camp_excluir) {
            $id = $_POST['id_campanha'];
            $stmt = $pdo->prepare("DELETE FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS WHERE ID = ?");
            $stmt->execute([$id]);
            $mensagem_alerta = "<div class='alert alert-warning fw-bold shadow-sm'><i class='fas fa-trash'></i> Campanha excluída permanentemente!</div>";
        } elseif ($acao === 'toggle_status' && $perm_camp_excluir) {
            $id = $_POST['id_campanha'];
            $novo_status = $_POST['status_atual'] === 'ATIVO' ? 'INATIVO' : 'ATIVO';
            $stmt = $pdo->prepare("UPDATE BANCO_DE_DADOS_CAMPANHA_CAMPANHAS SET STATUS = ? WHERE ID = ?");
            $stmt->execute([$novo_status, $id]);
            $mensagem_alerta = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle'></i> Status alterado para $novo_status!</div>";
        } elseif ($acao === 'limpar_disponiveis') {
            $id = $_POST['id_campanha'];
            $sqlDelete = "DELETE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA 
                          WHERE ID_CAMPANHA = ? 
                          AND CPF_CLIENTE NOT IN (
                              SELECT r.CPF_CLIENTE FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
                              JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON r.ID_STATUS_CONTATO = s.ID
                              WHERE (s.ID_CAMPANHA = ? OR s.ID_CAMPANHA IS NULL) AND r.DATA_AGENDAMENTO >= CURDATE()
                          )";
            $stmt = $pdo->prepare($sqlDelete);
            $stmt->execute([$id, $id]);
            $afetados = $stmt->rowCount();
            $mensagem_alerta = "<div class='alert alert-warning fw-bold shadow-sm'><i class='fas fa-broom'></i> $afetados clientes disponíveis removidos da campanha! Apenas retornos agendados foram mantidos.</div>";
        }

        $pdo->commit();
        if(!empty($acao)) {
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($mensagem_alerta));
            exit;
        }

    } catch (PDOException $e) {
        $pdo->rollBack();
        $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage();
    }
}

if (isset($_GET['msg'])) {
    $mensagem_alerta = urldecode($_GET['msg']);
}

// =========================================================================
// BUSCA E FILTRAGEM DE CAMPANHAS PARA A TELA
// =========================================================================
try {
    $sqlCampanhas = "
        SELECT c.*, 
               (SELECT GROUP_CONCAT(u.NOME SEPARATOR ' | ') FROM CLIENTE_USUARIO u WHERE FIND_IN_SET(u.CPF, c.CPF_USUARIO)) as NOME_USUARIO,
               e.NOME_CADASTRO as NOME_EMPRESA,
               (SELECT COUNT(ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID) as TOTAL_CLIENTES,
               (SELECT COUNT(cl.ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cl WHERE cl.ID_CAMPANHA = c.ID AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = cl.CPF_CLIENTE AND r.DATA_REGISTRO >= cl.DATA_INCLUSAO)) as RESTANTES
        FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
        LEFT JOIN CLIENTE_EMPRESAS e ON c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci = e.CNPJ COLLATE utf8mb4_unicode_ci
        WHERE 1=1
    ";
    
    $paramsCampanhas = [];
    
    // Filtros de Permissão da Hierarquia — apenas MASTER/ADMIN vê todas as empresas
    if (!$is_master_camp_gestao) {
        $sqlCampanhas .= " AND (
            c.id_empresa = ?
            OR (c.id_empresa IS NULL AND c.CNPJ_EMPRESA IS NULL)
            OR (c.id_empresa IS NULL AND EXISTS(
                SELECT 1 FROM CLIENTE_EMPRESAS ce
                WHERE ce.CNPJ COLLATE utf8mb4_unicode_ci = c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci
                  AND ce.ID = ?
            ))
        )";
        $paramsCampanhas[] = $id_empresa_logado_num;
        $paramsCampanhas[] = $id_empresa_logado_num;
    }
    
    // Filtros de Visão (Meu Cadastro) — CONSULTOR vê apenas as campanhas vinculadas ao seu usuário
    $is_consultor_camp = in_array($grupo_camp, ['CONSULTOR', 'CONSULTORES']);
    if ($is_consultor_camp) {
        $sqlCampanhas .= " AND (c.id_usuario = ? OR FIND_IN_SET(?, c.CPF_USUARIO))";
        $paramsCampanhas[] = $id_usuario_logado_num;
        $paramsCampanhas[] = $cpf_logado;
    }

    $sqlCampanhas .= " ORDER BY c.ID DESC";
    
    $stmtCamp = $pdo->prepare($sqlCampanhas);
    $stmtCamp->execute($paramsCampanhas);
    $lista_campanhas = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

    $sqlUsuJS = "
        SELECT u.CPF, u.NOME, e.CNPJ as CNPJ_VINCULO, e.NOME_CADASTRO as NOME_EMP 
        FROM CLIENTE_USUARIO u 
        LEFT JOIN CLIENTE_CADASTRO c ON u.CPF = c.CPF 
        LEFT JOIN CLIENTE_EMPRESAS e ON (c.CNPJ COLLATE utf8mb4_unicode_ci = e.CNPJ COLLATE utf8mb4_unicode_ci OR u.CPF COLLATE utf8mb4_unicode_ci = e.CPF_CLIENTE_CADASTRO COLLATE utf8mb4_unicode_ci)
        WHERE u.Situação = 'ativo' 
        ORDER BY u.NOME ASC
    ";
    $stmtUsuJS = $pdo->query($sqlUsuJS);
    $lista_usuarios_js = $stmtUsuJS->fetchAll(PDO::FETCH_ASSOC);

    if ($perm_visao_global) {
        $stmtTodasEmp = $pdo->query("SELECT CNPJ, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC");
        $lista_todas_empresas = $stmtTodasEmp->fetchAll(PDO::FETCH_ASSOC);
    }

    $stmtStat = $pdo->query("SELECT ID, NOME_STATUS FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO ORDER BY NOME_STATUS ASC");
    $lista_status_opcoes = $stmtStat->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage();
}

include $caminho_header;
?>

<div class="container-fluid mt-3 mb-5">
    <div class="row mb-4">
        <div class="col-12 text-center">
            <h2 class="text-danger fw-bold"><i class="fas fa-bullhorn me-2"></i> Gestão de Campanhas</h2>
            <p class="text-muted">Crie campanhas de contato, defina datas e relacione responsáveis.</p>
        </div>
    </div>

    <?= $mensagem_alerta ?>

    <?php if ($erro_banco): ?>
        <div class="row justify-content-center mb-4">
            <div class="col-md-8">
                <div class="alert alert-danger shadow-lg border-dark fw-bold">
                    <i class="fas fa-database me-2"></i> <?= $erro_banco ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-12">
            <div class="card border-dark shadow-lg rounded-3 mb-5">
                <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 text-uppercase"><i class="fas fa-list text-danger me-2"></i> Relação de Campanhas</h5>
                    <div>
                        <button class="btn btn-sm btn-danger text-white fw-bold shadow-sm border-dark" onclick="abrirModalCampanha()"><i class="fas fa-plus text-white"></i> Nova Campanha</button>
                    </div>
                </div>
                
                <div class="card-body p-0 table-responsive bg-white" style="overflow: visible; min-height: 320px;">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="table-light border-dark small text-uppercase">
                            <tr>
                                <th class="text-start ps-4">Campanha</th>
                                <th class="text-start">Empresa Vinculada</th>
                                <th>Período (Início - Fim)</th>
                                <th>Usuário(s) Vinculado(s)</th>
                                <th>Total / Restantes</th>
                                <th>Início Aleatório</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lista_campanhas)): ?>
                                <tr><td colspan="8" class="py-5 text-muted fw-bold fst-italic">Nenhuma campanha registrada ou acessível.</td></tr>
                            <?php else: ?>
                                <?php foreach ($lista_campanhas as $c): ?>
                                    <tr class="border-bottom border-dark">
                                        <td class="text-start ps-4 fw-bold text-dark fs-6"><?= htmlspecialchars($c['NOME_CAMPANHA']) ?></td>
                                        
                                        <td class="text-start">
                                            <?= !empty($c['NOME_EMPRESA']) ? '<span class="text-primary fw-bold"><i class="fas fa-building"></i> ' . htmlspecialchars($c['NOME_EMPRESA']) . '</span>' : '<span class="text-muted fst-italic">Global</span>' ?>
                                        </td>

                                        <td class="text-secondary fw-bold">
                                            <?= date('d/m/Y', strtotime($c['DATA_INICIO'])) ?> a <?= date('d/m/Y', strtotime($c['DATA_FIM'])) ?>
                                        </td>
                                        <td>
                                            <?= !empty($c['NOME_USUARIO']) ? '<span class="badge bg-primary text-wrap text-start lh-base py-1" style="max-width: 250px;"><i class="fas fa-users"></i> ' . htmlspecialchars($c['NOME_USUARIO']) . '</span>' : '<span class="badge bg-secondary border border-dark text-light">Ninguém / Global</span>' ?>
                                        </td>
                                        
                                        <td>
                                            <span class="badge bg-dark text-white border border-secondary shadow-sm px-2 py-1 fs-6" title="Total de Clientes na Lista"><?= $c['TOTAL_CLIENTES'] ?></span>
                                            <span class="mx-1 text-danger fw-bold fs-5">/</span>
                                            <span class="text-danger fw-bold fs-6" title="Leads Restantes (Ainda não contatados)"><?= $c['RESTANTES'] ?></span>
                                            
                                            <?php if ($c['RESTANTES'] > 0 && $perm_camp_editar): ?>
                                            <form method="POST" class="d-inline ms-2" onsubmit="return confirmarLimpeza();">
                                                <input type="hidden" name="acao_crud" value="limpar_disponiveis">
                                                <input type="hidden" name="id_campanha" value="<?= $c['ID'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1 border-0" title="Retirar Clientes Disponíveis (Manter apenas os que têm retorno)"><i class="fas fa-user-minus"></i></button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <td>
                                            <?= $c['PARAMETRO_INICIO_ALEATORIO'] == 'SIM' ? '<span class="text-success fw-bold"><i class="fas fa-random"></i> Sim</span>' : '<span class="text-muted fw-bold"><i class="fas fa-sort-numeric-down"></i> Não</span>' ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $c['STATUS'] == 'ATIVO' ? 'bg-success' : 'bg-danger' ?> border border-dark rounded-1 p-2">
                                                <?= $c['STATUS'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-dark dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown">Mais</button>
                                                <ul class="dropdown-menu shadow-sm border-dark">
                                                    
                                                    <?php if($perm_camp_editar): ?>
                                                    <li><a class="dropdown-item fw-bold text-primary" href="#" onclick='editarCampanha(<?= json_encode($c) ?>)'><i class="fas fa-edit me-2"></i> Editar</a></li>
                                                    <?php endif; ?>

                                                    <?php if($perm_camp_excluir): ?>
                                                    <li>
                                                        <form method="POST">
                                                            <input type="hidden" name="acao_crud" value="toggle_status">
                                                            <input type="hidden" name="id_campanha" value="<?= $c['ID'] ?>">
                                                            <input type="hidden" name="status_atual" value="<?= $c['STATUS'] ?>">
                                                            <button class="dropdown-item fw-bold text-warning" type="submit"><i class="fas fa-power-off me-2"></i> <?= $c['STATUS'] == 'ATIVO' ? 'Desativar' : 'Ativar' ?></button>
                                                        </form>
                                                    </li>
                                                    <li>
                                                        <form method="POST" onsubmit="return confirm('ATENÇÃO: Excluir esta campanha apagará TODOS os clientes vinculados a ela na relação (Mas NÃO apaga do sistema). Deseja continuar?');">
                                                            <input type="hidden" name="acao_crud" value="excluir_campanha">
                                                            <input type="hidden" name="id_campanha" value="<?= $c['ID'] ?>">
                                                            <button class="dropdown-item fw-bold text-danger" type="submit"><i class="fas fa-trash-alt me-2"></i> Excluir</button>
                                                        </form>
                                                    </li>
                                                    <?php endif; ?>

                                                    <?php if(!$perm_camp_editar && !$perm_camp_excluir): ?>
                                                        <li><span class="dropdown-item text-muted fst-italic small">Nenhuma ação permitida</span></li>
                                                    <?php endif; ?>
                                                </ul>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalCampanha" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" class="modal-content border-dark shadow-lg rounded-0">
            <input type="hidden" name="acao_crud" value="salvar_campanha">
            <input type="hidden" name="id_campanha" id="form_id_campanha">
            
            <div class="modal-header bg-dark text-white border-dark rounded-0">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-bullhorn text-danger me-2"></i> Configurar Campanha</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <div class="modal-body bg-light p-4">
                
                <div class="row g-3 mb-4">
                    <div class="col-md-12">
                        <label class="fw-bold small mb-1">Nome da Campanha <span class="text-danger">*</span></label>
                        <input type="text" name="nome_campanha" id="form_nome" class="form-control border-dark text-uppercase fw-bold fs-5 rounded-0" required>
                    </div>
                    
                    <div class="col-md-12">
                        <label class="fw-bold small mb-1">Empresa Proprietária da Campanha <span class="text-danger">*</span></label>
                        <?php if (!$perm_visao_global): ?>
                            <div class="form-control border-dark bg-secondary text-white fw-bold rounded-0"><i class="fas fa-building me-2"></i> <?= htmlspecialchars($nome_minha_empresa) ?></div>
                        <?php else: ?>
                            <select name="cnpj_empresa" id="form_cnpj_empresa" class="form-select border-dark fw-bold text-primary rounded-0">
                                <option value="">-- CAMPANHA GLOBAL (Acessível a todos) --</option>
                                <?php foreach($lista_todas_empresas as $emp): ?>
                                    <option value="<?= $emp['CNPJ'] ?>"><?= htmlspecialchars($emp['NOME_CADASTRO']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label class="fw-bold small mb-1">Data de Início <span class="text-danger">*</span></label>
                        <input type="date" name="data_inicio" id="form_inicio" class="form-control border-dark fw-bold rounded-0" required>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small mb-1">Data de Fim <span class="text-danger">*</span></label>
                        <input type="date" name="data_fim" id="form_fim" class="form-control border-dark fw-bold rounded-0" required>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6 d-flex flex-column gap-3">
                        <div class="card border-primary shadow-sm rounded-0">
                            <div class="card-header bg-primary text-white py-2 rounded-0">
                                <h6 class="mb-0 fw-bold text-uppercase" style="font-size: 0.85rem;"><i class="fas fa-users me-2"></i> Operadores Permitidos</h6>
                            </div>
                            <div class="card-body bg-white p-2">
                                <label class="fw-bold small mb-2 text-primary d-block">Vincular Operadores:</label>
                                <div id="form_cpf_usuario" class="border border-primary rounded-0 overflow-auto" style="max-height:150px;"></div>
                            </div>
                        </div>

                        <div class="card border-dark shadow-sm rounded-0 flex-grow-1">
                            <div class="card-body bg-white p-3">
                                <label class="fw-bold small mb-1 text-info">Início Aleatório de Fila?</label>
                                <select name="parametro_inicio_aleatorio" id="form_aleatorio" class="form-select border-info fw-bold mb-3 rounded-0">
                                    <option value="NAO">NÃO (Segue a Ordem Padrão/Filtro)</option>
                                    <option value="SIM">SIM (Mistura os clientes ao iniciar a fila)</option>
                                </select>

                                <label class="fw-bold small mb-1 text-dark">Situação da Campanha</label>
                                <select name="status" id="form_status" class="form-select border-dark fw-bold text-dark rounded-0">
                                    <option value="ATIVO" class="text-success">ATIVO</option>
                                    <option value="INATIVO" class="text-danger">INATIVO</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="card border-warning shadow-sm h-100 rounded-0">
                            <div class="card-header bg-warning text-dark py-2 rounded-0">
                                <h6 class="mb-0 fw-bold text-uppercase" style="font-size: 0.85rem;"><i class="fas fa-tags me-2"></i> Status Permitidos na Campanha</h6>
                            </div>
                            <div class="card-body bg-white p-2 d-flex flex-column">
                                <small class="text-muted d-block mb-2 fw-bold"><i class="fas fa-info-circle text-primary me-1"></i> Selecione quais botões de status aparecerão para o operador na hora do atendimento.</small>
                                <div id="form_ids_status" class="border border-warning border-2 rounded-0 overflow-auto flex-grow-1" style="min-height:250px;">
                                    <?php foreach($lista_status_opcoes as $st): ?>
                                    <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom border-light fw-bold small w-100 mb-0" style="cursor:pointer;" onmouseover="this.style.background='#fffbe6'" onmouseout="this.style.background=''">
                                        <input type="checkbox" name="ids_status[]" value="<?= $st['ID'] ?>" class="form-check-input mt-0 flex-shrink-0" style="width:18px;height:18px;">
                                        <?= htmlspecialchars($st['NOME_STATUS']) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer border-dark bg-white rounded-0">
                <button type="button" class="btn btn-outline-dark fw-bold rounded-0" data-bs-dismiss="modal">Cancelar e Voltar</button>
                <button type="submit" class="btn btn-danger border-dark fw-bold shadow-sm rounded-0"><i class="fas fa-save me-1"></i> Gravar Campanha</button>
            </div>
        </form>
    </div>
</div>

<script>
let modalCampanha;

const todosUsuarios = <?= json_encode($lista_usuarios_js) ?>;
const isMaster = <?= $perm_visao_global ? 'true' : 'false' ?>;
const minhaEmpresaCnpj = '<?= $cnpj_minha_empresa ?>';

function filtrarUsuariosPorEmpresa(cnpj, selecionados = []) {
    const box = document.getElementById('form_cpf_usuario');
    box.innerHTML = '';
    todosUsuarios.forEach(u => {
        if (!cnpj || u.CNPJ_VINCULO === cnpj) {
            let nomeEmp = u.NOME_EMP ? ` (${u.NOME_EMP})` : '';
            let checked = selecionados.includes(u.CPF) ? 'checked' : '';
            let label = document.createElement('label');
            label.className = 'd-flex align-items-center gap-2 px-3 py-2 border-bottom border-light fw-bold small w-100 mb-0';
            label.style.cursor = 'pointer';
            label.onmouseover = function(){ this.style.background='#e8f0fe'; };
            label.onmouseout  = function(){ this.style.background=''; };
            label.innerHTML = `<input type="checkbox" name="cpfs_usuarios[]" value="${u.CPF}" class="form-check-input mt-0 flex-shrink-0" style="width:18px;height:18px;" ${checked}> ${u.NOME}${nomeEmp}`;
            box.appendChild(label);
        }
    });
}

function confirmarLimpeza() {
    if(confirm('ATENÇÃO: Você está prestes a remover TODOS os clientes desta campanha que NÃO possuem um retorno agendado.\n\nEssa ação é recomendada para limpar a fila após finalizar os leads novos. Continuar?')) {
        return confirm('Tem certeza absoluta? Esta ação não pode ser desfeita!');
    }
    return false;
}

document.addEventListener("DOMContentLoaded", function() {
    modalCampanha = new bootstrap.Modal(document.getElementById('modalCampanha'));
    let elEmpresa = document.getElementById('form_cnpj_empresa');
    if (elEmpresa) { elEmpresa.addEventListener('change', function() { filtrarUsuariosPorEmpresa(this.value); }); }
});

function abrirModalCampanha() {
    document.getElementById('form_id_campanha').value = '';
    document.getElementById('form_nome').value = '';
    document.getElementById('form_inicio').value = '';
    document.getElementById('form_fim').value = '';
    document.getElementById('form_aleatorio').value = 'NAO';
    document.getElementById('form_status').value = 'ATIVO';

    let elEmpresa = document.getElementById('form_cnpj_empresa');
    if (elEmpresa) elEmpresa.value = '';
    if(isMaster) { filtrarUsuariosPorEmpresa('', []); } else { filtrarUsuariosPorEmpresa(minhaEmpresaCnpj, []); }

    // Desmarca todos os status
    document.querySelectorAll('#form_ids_status input[type=checkbox]').forEach(cb => cb.checked = false);
    modalCampanha.show();
}

function editarCampanha(dados) {
    document.getElementById('form_id_campanha').value = dados.ID;
    document.getElementById('form_nome').value = dados.NOME_CAMPANHA;
    document.getElementById('form_inicio').value = dados.DATA_INICIO;
    document.getElementById('form_fim').value = dados.DATA_FIM;
    document.getElementById('form_aleatorio').value = dados.PARAMETRO_INICIO_ALEATORIO;
    document.getElementById('form_status').value = dados.STATUS;

    let elEmpresa = document.getElementById('form_cnpj_empresa');
    if (elEmpresa) elEmpresa.value = dados.CNPJ_EMPRESA || '';

    // Operadores: reconstrói checkboxes já com os selecionados marcados
    let cpfsArray = dados.CPF_USUARIO ? dados.CPF_USUARIO.split(',').map(s => s.trim()) : [];
    if(isMaster) { filtrarUsuariosPorEmpresa(dados.CNPJ_EMPRESA || '', cpfsArray); } else { filtrarUsuariosPorEmpresa(minhaEmpresaCnpj, cpfsArray); }

    // Status: marca os que estavam salvos
    let statusArray = dados.IDS_STATUS_CONTATOS ? dados.IDS_STATUS_CONTATOS.split(',').map(s => s.trim()) : [];
    document.querySelectorAll('#form_ids_status input[type=checkbox]').forEach(cb => {
        cb.checked = statusArray.includes(cb.value);
    });

    modalCampanha.show();
}
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>