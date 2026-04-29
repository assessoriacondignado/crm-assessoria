<?php
session_start();

// Habilita exibição de erros temporariamente para evitar a "Tela Preta (Erro 500)"
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

// ==========================================
// AJAX: GET permissões de um grupo
// ==========================================
if (isset($_GET['acao_ajax']) && $_GET['acao_ajax'] === 'get_permissoes_grupo') {
    header('Content-Type: application/json');
    $nome_grupo = strtoupper(trim($_GET['nome_grupo'] ?? ''));
    $stmtAll = $pdo->query("SELECT ID, NOME_PERMISSAO, CHAVE, TIPO, GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO ORDER BY TIPO ASC, NOME_PERMISSAO ASC");
    $result = [];
    foreach ($stmtAll->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $bloqueados = !empty($p['GRUPO_USUARIOS']) ? array_map('trim', explode(',', strtoupper($p['GRUPO_USUARIOS']))) : [];
        $result[] = ['ID' => $p['ID'], 'NOME_PERMISSAO' => $p['NOME_PERMISSAO'], 'CHAVE' => $p['CHAVE'], 'TIPO' => $p['TIPO'], 'BLOQUEADO' => in_array($nome_grupo, $bloqueados)];
    }
    echo json_encode(['ok' => true, 'permissoes' => $result]); exit;
}

// ==========================================
// AJAX: Salvar permissões de um grupo
// ==========================================
if (isset($_POST['acao_grupo']) && $_POST['acao_grupo'] === 'salvar_permissoes_grupo') {
    header('Content-Type: application/json');
    $nome_grupo = strtoupper(trim($_POST['nome_grupo'] ?? ''));
    $ids_bloquear = array_map('intval', (array)($_POST['ids_bloqueados'] ?? []));
    $stmtAll = $pdo->query("SELECT ID, GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO");
    foreach ($stmtAll->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $grupos = !empty($p['GRUPO_USUARIOS']) ? array_map('trim', explode(',', $p['GRUPO_USUARIOS'])) : [];
        $tinha = in_array($nome_grupo, array_map('strtoupper', $grupos));
        $deve  = in_array((int)$p['ID'], $ids_bloquear);
        if ($tinha && !$deve) {
            $grupos = array_values(array_filter($grupos, fn($g) => strtoupper(trim($g)) !== $nome_grupo));
            $pdo->prepare("UPDATE CLIENTE_USUARIO_PERMISSAO SET GRUPO_USUARIOS = ? WHERE ID = ?")->execute([implode(',', $grupos), $p['ID']]);
        } elseif (!$tinha && $deve) {
            $grupos[] = $nome_grupo;
            $pdo->prepare("UPDATE CLIENTE_USUARIO_PERMISSAO SET GRUPO_USUARIOS = ? WHERE ID = ?")->execute([implode(',', $grupos), $p['ID']]);
        }
    }
    echo json_encode(['ok' => true]); exit;
}

// ==========================================
// AÇÕES DE GRUPO
// ==========================================
if (isset($_POST['acao_grupo'])) {
    $nome_grupo = strtoupper(trim($_POST['nome_grupo']));
    $id_grupo = $_POST['id_grupo'] ?? '';

    try {
        if ($_POST['acao_grupo'] == 'duplicar') {
            $id_orig = intval($_POST['id_grupo'] ?? 0);
            $stmtN = $pdo->prepare("SELECT NOME_GRUPO FROM SISTEMA_GRUPOS_USUARIO WHERE ID = ?");
            $stmtN->execute([$id_orig]);
            $nome_orig = $stmtN->fetchColumn();
            if ($nome_orig) {
                $nome_novo = $nome_orig . ' - COPIA';
                $pdo->prepare("INSERT INTO SISTEMA_GRUPOS_USUARIO (NOME_GRUPO) VALUES (?)")->execute([$nome_novo]);
                // Copia as permissões: onde o grupo original está bloqueado, bloqueia o novo também
                $stmtP = $pdo->query("SELECT ID, GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO");
                foreach ($stmtP->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    if (!empty($p['GRUPO_USUARIOS'])) {
                        $gs = array_map('trim', explode(',', $p['GRUPO_USUARIOS']));
                        if (in_array($nome_orig, array_map('strtoupper', $gs)) && !in_array($nome_novo, array_map('strtoupper', $gs))) {
                            $gs[] = $nome_novo;
                            $pdo->prepare("UPDATE CLIENTE_USUARIO_PERMISSAO SET GRUPO_USUARIOS = ? WHERE ID = ?")->execute([implode(',', $gs), $p['ID']]);
                        }
                    }
                }
                $msg_sucesso = "Grupo '{$nome_novo}' criado com as mesmas permissões!";
            }
        } elseif ($_POST['acao_grupo'] == 'salvar') {
            if(empty($id_grupo)) {
                $pdo->prepare("INSERT INTO SISTEMA_GRUPOS_USUARIO (NOME_GRUPO) VALUES (?)")->execute([$nome_grupo]);
            } else {
                // Pega o nome antigo antes de alterar
                $stmtVelho = $pdo->prepare("SELECT NOME_GRUPO FROM SISTEMA_GRUPOS_USUARIO WHERE ID = ?");
                $stmtVelho->execute([$id_grupo]);
                $nome_velho = $stmtVelho->fetchColumn();

                // 1. Atualiza o nome do grupo na tabela de grupos
                $pdo->prepare("UPDATE SISTEMA_GRUPOS_USUARIO SET NOME_GRUPO = ? WHERE ID = ?")->execute([$nome_grupo, $id_grupo]);
                
                // 2. Atualiza o grupo nos usuários vinculados
                $pdo->prepare("UPDATE CLIENTE_USUARIO SET GRUPO_USUARIOS = ? WHERE GRUPO_USUARIOS = ?")->execute([$nome_grupo, $nome_velho]);

                // 3. Atualiza o grupo dentro da string separada por vírgulas na tabela de regras
                $stmtPerms = $pdo->query("SELECT ID, GRUPO_USUARIOS FROM CLIENTE_USUARIO_PERMISSAO");
                $perms = $stmtPerms->fetchAll(PDO::FETCH_ASSOC);
                foreach ($perms as $p) {
                    if (!empty($p['GRUPO_USUARIOS'])) {
                        // Transforma "GRUPO1, GRUPO2" em um array
                        $grupos_array = array_map('trim', explode(',', $p['GRUPO_USUARIOS']));
                        
                        // Procura se o grupo antigo está nesse array
                        $key = array_search($nome_velho, $grupos_array);
                        if ($key !== false) {
                            // Substitui pelo novo nome e remonta a string
                            $grupos_array[$key] = $nome_grupo;
                            $nova_string = implode(',', $grupos_array);
                            
                            $pdo->prepare("UPDATE CLIENTE_USUARIO_PERMISSAO SET GRUPO_USUARIOS = ? WHERE ID = ?")->execute([$nova_string, $p['ID']]);
                        }
                    }
                }
            }
            $msg_sucesso = "Grupo salvo com sucesso!";
        }
    } catch (Exception $e) {
        $msg_erro = "Erro ao salvar grupo: " . $e->getMessage();
    }
}

if (isset($_GET['mudar_status_grupo'])) {
    $id = $_GET['mudar_status_grupo'];
    $novo_status = $_GET['st'] == 'ATIVO' ? 'INATIVO' : 'ATIVO';
    $pdo->prepare("UPDATE SISTEMA_GRUPOS_USUARIO SET STATUS = ? WHERE ID = ?")->execute([$novo_status, $id]);
    header("Location: permissoes.php"); exit;
}

if (isset($_GET['excluir_grupo'])) {
    $pdo->prepare("DELETE FROM SISTEMA_GRUPOS_USUARIO WHERE ID = ?")->execute([$_GET['excluir_grupo']]);
    header("Location: permissoes.php"); exit;
}

// ==========================================
// AÇÕES DE PERMISSÃO/CHAVE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'salvar_regra') {
    $nome_permissao = trim($_POST['nome_permissao']);
    $chave = strtoupper(trim($_POST['chave'])); 
    $tipo = $_POST['tipo'];
    $local = $_POST['local_aplicacao'] ?? '';
    $desc = $_POST['descricao'] ?? '';
    
    // Pega os grupos marcados no checkbox e junta com vírgula (Ex: VENDEDORES,SUPERVISORES)
    $grupos_marcados = isset($_POST['grupos_bloqueados']) ? implode(',', $_POST['grupos_bloqueados']) : '';

    try {
        $stmtCheck = $pdo->prepare("SELECT ID FROM CLIENTE_USUARIO_PERMISSAO WHERE CHAVE = ? LIMIT 1");
        $stmtCheck->execute([$chave]);
        $existe = $stmtCheck->fetchColumn();

        if ($existe) {
            $sql = "UPDATE CLIENTE_USUARIO_PERMISSAO SET NOME_PERMISSAO=?, TIPO=?, LOCAL_APLICACAO=?, DESCRICAO=?, GRUPO_USUARIOS=?, VALOR_REGRA='BLOQUEADO' WHERE CHAVE=?";
            $pdo->prepare($sql)->execute([$nome_permissao, $tipo, $local, $desc, $grupos_marcados, $chave]);
        } else {
            $sql = "INSERT INTO CLIENTE_USUARIO_PERMISSAO (NOME_PERMISSAO, CHAVE, TIPO, LOCAL_APLICACAO, DESCRICAO, GRUPO_USUARIOS, VALOR_REGRA) VALUES (?, ?, ?, ?, ?, ?, 'BLOQUEADO')";
            $pdo->prepare($sql)->execute([$nome_permissao, $chave, $tipo, $local, $desc, $grupos_marcados]);
        }
        $msg_sucesso = "Regra de permissão salva com sucesso!";
    } catch (Exception $e) {
        $msg_erro = "Erro de Banco de Dados ao salvar regra: " . $e->getMessage();
    }
}

if (isset($_GET['excluir_regra'])) {
    $pdo->prepare("DELETE FROM CLIENTE_USUARIO_PERMISSAO WHERE ID = ?")->execute([$_GET['excluir_regra']]);
    header("Location: permissoes.php"); exit;
}

// ==========================================
// BUSCAS NO BANCO PARA A TELA
// ==========================================
$stmtGrupos = $pdo->query("SELECT * FROM SISTEMA_GRUPOS_USUARIO ORDER BY NOME_GRUPO ASC");
$grupos = $stmtGrupos->fetchAll(PDO::FETCH_ASSOC);

$nomes_grupos_ativos = [];
foreach($grupos as $g) {
    if($g['STATUS'] === 'ATIVO') $nomes_grupos_ativos[] = $g['NOME_GRUPO'];
}

$stmtPermissoes = $pdo->query("SELECT * FROM CLIENTE_USUARIO_PERMISSAO ORDER BY NOME_PERMISSAO ASC");
$todas_regras_banco = $stmtPermissoes->fetchAll(PDO::FETCH_ASSOC);

$regras = [];
$chaves_vistas = [];
foreach($todas_regras_banco as $r) {
    if(!in_array($r['CHAVE'], $chaves_vistas)) {
        $regras[] = $r;
        $chaves_vistas[] = $r['CHAVE'];
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>

<div class="container-fluid mt-4 mb-5">
    <div class="row mb-4">
        <div class="col-12 d-flex justify-content-between align-items-center border-bottom border-secondary pb-2">
            <h2 class="text-primary fw-bold m-0"><i class="fas fa-user-shield me-2"></i> Gestão de Acessos</h2>
        </div>
    </div>

    <?php if(isset($msg_sucesso)): ?><div class="alert alert-success fw-bold shadow-sm"><i class="fas fa-check-circle"></i> <?= $msg_sucesso ?></div><?php endif; ?>
    <?php if(isset($msg_erro)): ?><div class="alert alert-danger fw-bold shadow-sm"><i class="fas fa-exclamation-triangle"></i> <?= $msg_erro ?></div><?php endif; ?>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-users me-2 text-info"></i> Grupos de Usuário</h5>
                    <button class="btn btn-sm btn-info fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#modalGrupo"><i class="fas fa-plus"></i> Novo</button>
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if(empty($grupos)): ?>
                            <li class="list-group-item text-muted text-center py-3">Nenhum grupo cadastrado.</li>
                        <?php endif; ?>
                        
                        <?php foreach($grupos as $g): ?>
                            <?php $isAtivo = $g['STATUS'] === 'ATIVO'; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="fw-bold text-dark"><?= htmlspecialchars($g['NOME_GRUPO']) ?></span>
                                    <span class="badge <?= $isAtivo ? 'bg-success' : 'bg-danger' ?> ms-1" style="font-size: 0.65rem;"><?= $g['STATUS'] ?></span>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-secondary dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-bars"></i> Mais
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end border-dark shadow">
                                        <li>
                                            <a class="dropdown-item fw-bold" href="#" onclick="abrirGerenciarGrupo(<?= $g['ID'] ?>, '<?= htmlspecialchars($g['NOME_GRUPO']) ?>')">
                                                <i class="fas fa-edit text-primary" style="width: 20px;"></i> Editar
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item fw-bold" href="#" onclick="abrirModalEdicaoGrupo(<?= $g['ID'] ?>, '<?= htmlspecialchars($g['NOME_GRUPO']) ?>')">
                                                <i class="fas fa-pen text-secondary" style="width: 20px;"></i> Renomear
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item fw-bold text-info" href="#" onclick="duplicarGrupo(<?= $g['ID'] ?>, '<?= htmlspecialchars($g['NOME_GRUPO']) ?>')">
                                                <i class="fas fa-copy" style="width: 20px;"></i> Duplicar
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item fw-bold" href="?mudar_status_grupo=<?= $g['ID'] ?>&st=<?= $g['STATUS'] ?>">
                                                <i class="fas <?= $isAtivo ? 'fa-ban text-warning' : 'fa-check text-success' ?>" style="width: 20px;"></i> <?= $isAtivo ? 'Desativar' : 'Ativar' ?>
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item fw-bold text-danger" href="#" onclick="confirmarExclusaoGrupo(<?= $g['ID'] ?>)">
                                                <i class="fas fa-trash-alt" style="width: 20px;"></i> Excluir
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-key me-2 text-warning"></i> Chaves de Permissão / Bloqueio</h5>
                    <button class="btn btn-sm btn-warning fw-bold text-dark shadow-sm" onclick="abrirModalNovaRegra()"><i class="fas fa-plus"></i> Nova Regra</button>
                </div>
                
                <div class="bg-light p-2 border-bottom border-dark">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-dark"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="filtroPermissoes" class="form-control border-dark" placeholder="Buscar por chave, nome da regra ou grupo..." onkeyup="filtrarTabela()">
                    </div>
                </div>

                <div class="card-body p-0 table-responsive" style="min-height: 65vh;">
                    <table class="table table-hover align-middle mb-0 text-center bg-white" id="tabelaPermissoes">
                        <thead class="table-light border-dark text-uppercase small">
                            <tr>
                                <th class="text-start ps-3">Regra / Chave</th>
                                <th>Tipo</th>
                                <th>Grupos Bloqueados</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($regras as $p): ?>
                                <?php 
                                    $str_grupos = $p['GRUPO_USUARIOS'] ?? '';
                                    $bloqueados = empty($str_grupos) ? [] : array_filter(array_map('trim', explode(',', $str_grupos)));
                                    
                                    // Prepara o texto do Tooltip de Instruções
                                    $tooltip_text = "";
                                    if(!empty($p['DESCRICAO'])) {
                                        $tooltip_text .= "<b>📝 Descrição / Notas:</b><br>" . nl2br(htmlspecialchars($p['DESCRICAO']));
                                    }
                                    if(!empty($p['LOCAL_APLICACAO'])) {
                                        if(!empty($tooltip_text)) $tooltip_text .= "<br><br>";
                                        $tooltip_text .= "<b>🔗 Local de Aplicação:</b><br>" . htmlspecialchars($p['LOCAL_APLICACAO']);
                                    }
                                ?>
                                <tr class="border-bottom border-dark">
                                    <td class="text-start ps-3">
                                        <span class="fw-bold text-dark d-block">
                                            <?= htmlspecialchars($p['NOME_PERMISSAO']) ?>
                                            
                                            <?php if(!empty($tooltip_text)): ?>
                                                <i class="fas fa-info-circle text-info ms-1" data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="top" title="<?= str_replace('"', '&quot;', $tooltip_text) ?>" style="cursor: help;"></i>
                                            <?php endif; ?>
                                        </span>
                                        <code class="text-primary fw-bold bg-light px-1 border rounded"><?= htmlspecialchars($p['CHAVE']) ?></code>
                                    </td>
                                    <td>
                                        <?= $p['TIPO'] === 'FUNCAO' ? '<span class="text-danger fw-bold small"><i class="fas fa-ban"></i> Função</span>' : '<span class="text-info fw-bold small"><i class="fas fa-desktop"></i> Tela</span>' ?>
                                    </td>
                                    <td>
                                        <?php if(empty($bloqueados)): ?>
                                            <span class="badge bg-success shadow-sm">Nenhum (Livre para todos)</span>
                                        <?php else: ?>
                                            <?php foreach($bloqueados as $b): ?>
                                                <span class="badge bg-danger shadow-sm mb-1"><?= htmlspecialchars($b) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-dark dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-bars"></i> Mais
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end border-dark shadow">
                                                
                                                <?php if(!empty($tooltip_text)): ?>
                                                    <li>
                                                        <span class="dropdown-item fw-bold text-info" style="cursor: help;" data-bs-toggle="tooltip" data-bs-html="true" data-bs-placement="left" title="<?= str_replace('"', '&quot;', $tooltip_text) ?>">
                                                            <i class="fas fa-info-circle" style="width: 20px;"></i> Instruções
                                                        </span>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>

                                                <li>
                                                    <a class="dropdown-item fw-bold" href="#" onclick='editarRegra(<?= json_encode($p) ?>)'>
                                                        <i class="fas fa-edit text-primary" style="width: 20px;"></i> Editar
                                                    </a>
                                                </li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <a class="dropdown-item fw-bold text-danger" href="#" onclick="confirmarExclusaoRegra(<?= $p['ID'] ?>)">
                                                        <i class="fas fa-trash-alt" style="width: 20px;"></i> Excluir
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if(empty($regras)): ?>
                                <tr id="linhaVazia"><td colspan="4" class="py-5 text-muted fw-bold">Nenhuma regra cadastrada no sistema.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: GERENCIAR GRUPO (PERMISSÕES) -->
<div class="modal fade" id="modalGerenciarGrupo" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-dark text-white border-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-shield-alt text-info me-2"></i> Permissões do Grupo: <span id="ggNomeLabel" class="text-info"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-0">
                <div class="p-3 border-bottom border-dark bg-white d-flex gap-2 align-items-center">
                    <input type="text" id="ggFiltro" class="form-control border-dark rounded-0" placeholder="Filtrar permissões..." oninput="ggFiltrar()">
                    <select id="ggFiltroTipo" class="form-select border-dark rounded-0" style="max-width:160px;" onchange="ggFiltrar()">
                        <option value="">Todos os tipos</option>
                        <option value="TELA">Tela / Menu</option>
                        <option value="FUNCAO">Função / Botão</option>
                    </select>
                    <div class="form-check form-switch ms-2 mb-0 text-nowrap">
                        <input class="form-check-input" type="checkbox" id="ggApenasAtivos" onchange="ggFiltrar()">
                        <label class="form-check-label fw-bold small" for="ggApenasAtivos">Só bloqueados</label>
                    </div>
                </div>
                <div id="ggLoading" class="text-center py-5 text-primary"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
                <div id="ggLista" style="display:none;">
                    <table class="table table-hover table-sm mb-0 border-dark text-start" style="font-size:0.85rem;">
                        <thead class="table-dark text-white text-uppercase sticky-top" style="z-index:1;">
                            <tr>
                                <th class="ps-3">Permissão</th>
                                <th>Chave</th>
                                <th class="text-center" style="width:80px;">Tipo</th>
                                <th class="text-center" style="width:120px;">Bloqueado?</th>
                            </tr>
                        </thead>
                        <tbody id="ggTbody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer border-dark bg-white d-flex justify-content-between">
                <small id="ggContador" class="text-muted"></small>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-success fw-bold border-dark" onclick="ggSalvar()"><i class="fas fa-save me-1"></i> Salvar Alterações</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGrupo" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-dark shadow-lg">
            <input type="hidden" name="acao_grupo" value="salvar">
            <input type="hidden" name="id_grupo" id="id_grupo_form">
            <div class="modal-header bg-dark text-white border-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-users-cog text-info me-2"></i> Adicionar / Editar Grupo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="form-group">
                    <label class="fw-bold small">Nome do Grupo</label>
                    <input type="text" name="nome_grupo" id="nome_grupo_form" class="form-control border-dark text-uppercase fw-bold" placeholder="Ex: VENDEDORES" required>
                </div>
            </div>
            <div class="modal-footer border-dark bg-white">
                <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold border-dark"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalNovaPermissao" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-dark shadow-lg">
            <input type="hidden" name="acao" value="salvar_regra">
            <div class="modal-header bg-dark text-white border-dark">
                <h5 class="modal-title fw-bold"><i class="fas fa-key text-warning me-2"></i> Configurar Regra de Acesso</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="fw-bold small">Nome da Permissão (Para identificar)</label>
                        <input type="text" name="nome_permissao" id="form_nome_permissao" class="form-control border-dark" placeholder="Ex: Acesso Menu Financeiro" required>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold small text-primary">Chave no Código PHP (CHAVE)</label>
                        <input type="text" name="chave" id="form_chave" class="form-control border-primary text-uppercase fw-bold" placeholder="Ex: MENU_FINANCEIRO" required>
                    </div>
                    
                    <div class="col-md-12">
                        <label class="fw-bold small">Tipo</label>
                        <select name="tipo" id="form_tipo" class="form-select border-dark" required>
                            <option value="TELA">Bloqueio de Tela / Menu</option>
                            <option value="FUNCAO">Bloqueio de Função / Botão</option>
                        </select>
                    </div>

                    <div class="col-md-12 mt-4">
                        <div class="card border-danger shadow-sm">
                            <div class="card-header bg-danger text-white py-2">
                                <h6 class="mb-0 fw-bold"><i class="fas fa-ban me-2"></i> Selecione os Grupos que serão BLOQUEADOS:</h6>
                            </div>
                            <div class="card-body bg-white row">
                                <?php if(empty($nomes_grupos_ativos)): ?>
                                    <div class="col-12 text-muted">Nenhum grupo ativo disponível.</div>
                                <?php endif; ?>
                                
                                <?php foreach($nomes_grupos_ativos as $ng): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input border-danger checkbox-grupo" type="checkbox" name="grupos_bloqueados[]" value="<?= htmlspecialchars($ng) ?>" id="chk_<?= md5($ng) ?>">
                                            <label class="form-check-label fw-bold text-dark" for="chk_<?= md5($ng) ?>"><?= htmlspecialchars($ng) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="card-footer bg-light py-1 text-muted small">
                                * Os grupos que não estiverem marcados terão acesso <b>LIVRE</b>. Os grupos Master/Admin nunca são bloqueados.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-12 mt-4">
                        <label class="fw-bold small"><i class="fas fa-code me-1"></i> Local de Aplicação (Caminho ou Referência)</label>
                        <textarea name="local_aplicacao" id="form_local" class="form-control border-dark font-monospace shadow-sm" rows="2" style="resize: vertical; background-color: #fff9c4;" placeholder="Ex: /modulos/cliente/index.php ou Botão Financeiro..."></textarea>
                    </div>
                    <div class="col-md-12 mt-3">
                        <label class="fw-bold small"><i class="fas fa-sticky-note me-1"></i> Descrição / Notas sobre esta regra</label>
                        <textarea name="descricao" id="form_desc" class="form-control border-dark font-monospace shadow-sm" rows="3" style="resize: vertical; background-color: #fff9c4;" placeholder="Escreva observações aqui..."></textarea>
                    </div>

                </div>
            </div>
            <div class="modal-footer border-dark bg-white">
                <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold text-dark border-dark"><i class="fas fa-save"></i> Gravar Regra</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- ATIVADOR DE TOOLTIPS DO BOOTSTRAP ---
document.addEventListener("DOMContentLoaded", function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// --- FUNÇÕES DE DUPLA CONFIRMAÇÃO PARA EXCLUSÃO ---
function confirmarExclusaoGrupo(id) {
    if(confirm("ATENÇÃO: Deseja realmente excluir este grupo?")) {
        if(confirm("CONFIRMAÇÃO FINAL: A exclusão de um grupo não pode ser desfeita e pode afetar usuários vinculados. Deseja continuar?")) {
            window.location.href = "?excluir_grupo=" + id;
        }
    }
}

function confirmarExclusaoRegra(id) {
    if(confirm("ATENÇÃO: Deseja realmente excluir esta regra do sistema?")) {
        if(confirm("CONFIRMAÇÃO FINAL: Excluir esta regra liberará o acesso livre para todos os usuários. Continuar?")) {
            window.location.href = "?excluir_regra=" + id;
        }
    }
}
// --------------------------------------------------

// --- MOTOR DE PESQUISA (FILTRO) ---
function filtrarTabela() {
    let input = document.getElementById("filtroPermissoes").value.toLowerCase();
    let linhas = document.querySelectorAll("#tabelaPermissoes tbody tr");

    linhas.forEach(linha => {
        if(linha.id === 'linhaVazia') return; 
        let textoLinha = linha.textContent.toLowerCase();
        
        if (textoLinha.includes(input)) {
            linha.style.display = "";
        } else {
            linha.style.display = "none";
        }
    });
}
// ----------------------------------

function abrirModalEdicaoGrupo(id, nome) {
    document.getElementById('id_grupo_form').value = id;
    document.getElementById('nome_grupo_form').value = nome;
    new bootstrap.Modal(document.getElementById('modalGrupo')).show();
}

function abrirModalNovaRegra() {
    document.getElementById('form_nome_permissao').value = '';
    document.getElementById('form_chave').value = '';
    document.getElementById('form_chave').removeAttribute('readonly'); 
    document.getElementById('form_local').value = '';
    document.getElementById('form_desc').value = '';
    
    document.querySelectorAll('.checkbox-grupo').forEach(chk => chk.checked = false);
    new bootstrap.Modal(document.getElementById('modalNovaPermissao')).show();
}

function editarRegra(regra) {
    document.getElementById('form_nome_permissao').value = regra.NOME_PERMISSAO;
    document.getElementById('form_chave').value = regra.CHAVE;
    document.getElementById('form_chave').setAttribute('readonly', 'true'); 
    document.getElementById('form_tipo').value = regra.TIPO;
    
    document.getElementById('form_local').value = regra.LOCAL_APLICACAO || '';
    document.getElementById('form_desc').value = regra.DESCRICAO || '';
    
    document.querySelectorAll('.checkbox-grupo').forEach(chk => chk.checked = false);
    
    if(regra.GRUPO_USUARIOS) {
        let bloqueados = regra.GRUPO_USUARIOS.split(',').map(item => item.trim());
        document.querySelectorAll('.checkbox-grupo').forEach(chk => {
            if(bloqueados.includes(chk.value)) {
                chk.checked = true;
            }
        });
    }
    
    new bootstrap.Modal(document.getElementById('modalNovaPermissao')).show();
}

document.getElementById('modalGrupo').addEventListener('hidden.bs.modal', function () {
    document.getElementById('id_grupo_form').value = '';
    document.getElementById('nome_grupo_form').value = '';
});

// ──────────────────────────────────────────────────────────────────────────
// GERENCIAR GRUPO (PERMISSÕES)
// ──────────────────────────────────────────────────────────────────────────
let _ggNomeGrupo = '';
let _ggPerms     = [];

function abrirGerenciarGrupo(id, nome) {
    _ggNomeGrupo = nome;
    document.getElementById('ggNomeLabel').textContent = nome;
    document.getElementById('ggFiltro').value = '';
    document.getElementById('ggFiltroTipo').value = '';
    document.getElementById('ggApenasAtivos').checked = false;
    document.getElementById('ggLoading').style.display = '';
    document.getElementById('ggLista').style.display = 'none';
    new bootstrap.Modal(document.getElementById('modalGerenciarGrupo')).show();

    fetch(`permissoes.php?acao_ajax=get_permissoes_grupo&nome_grupo=${encodeURIComponent(nome)}`)
    .then(r => r.json())
    .then(data => {
        _ggPerms = data.permissoes || [];
        ggRenderizar(_ggPerms);
        document.getElementById('ggLoading').style.display = 'none';
        document.getElementById('ggLista').style.display = '';
        ggAtualizarContador();
    });
}

function ggRenderizar(perms) {
    const tb = document.getElementById('ggTbody');
    tb.innerHTML = '';
    perms.forEach(p => {
        const tipoBadge = p.TIPO === 'TELA'
            ? '<span class="badge bg-primary rounded-0">Tela</span>'
            : '<span class="badge bg-warning text-dark rounded-0">Função</span>';
        const checked = p.BLOQUEADO ? 'checked' : '';
        const rowCls  = p.BLOQUEADO ? 'table-danger' : '';
        tb.innerHTML += `<tr class="${rowCls}" id="gg_row_${p.ID}" data-tipo="${p.TIPO}" data-bloqueado="${p.BLOQUEADO ? '1' : '0'}">
            <td class="ps-3 fw-bold text-dark">${p.NOME_PERMISSAO}</td>
            <td class="text-muted font-monospace small">${p.CHAVE}</td>
            <td class="text-center">${tipoBadge}</td>
            <td class="text-center">
                <div class="form-check form-switch d-flex justify-content-center mb-0">
                    <input class="form-check-input border-danger gg-toggle" type="checkbox" id="gg_chk_${p.ID}" data-id="${p.ID}" ${checked} onchange="ggToggle(this)">
                </div>
            </td>
        </tr>`;
    });
}

function ggToggle(chk) {
    const id  = parseInt(chk.dataset.id);
    const row = document.getElementById(`gg_row_${id}`);
    const perm = _ggPerms.find(p => p.ID == id);
    if (perm) perm.BLOQUEADO = chk.checked;
    row.className = chk.checked ? 'table-danger' : '';
    row.dataset.bloqueado = chk.checked ? '1' : '0';
    ggAtualizarContador();
}

function ggFiltrar() {
    const texto   = document.getElementById('ggFiltro').value.toLowerCase();
    const tipo    = document.getElementById('ggFiltroTipo').value;
    const soBloc  = document.getElementById('ggApenasAtivos').checked;
    document.querySelectorAll('#ggTbody tr').forEach(tr => {
        const matchTxt  = tr.textContent.toLowerCase().includes(texto);
        const matchTipo = !tipo || tr.dataset.tipo === tipo;
        const matchBloc = !soBloc || tr.dataset.bloqueado === '1';
        tr.style.display = (matchTxt && matchTipo && matchBloc) ? '' : 'none';
    });
}

function ggAtualizarContador() {
    const total   = _ggPerms.length;
    const bloqued = _ggPerms.filter(p => p.BLOQUEADO).length;
    document.getElementById('ggContador').textContent = `${bloqued} bloqueadas de ${total} permissões`;
}

function ggSalvar() {
    const ids = _ggPerms.filter(p => p.BLOQUEADO).map(p => p.ID);
    const fd  = new FormData();
    fd.append('acao_grupo', 'salvar_permissoes_grupo');
    fd.append('nome_grupo', _ggNomeGrupo);
    ids.forEach(id => fd.append('ids_bloqueados[]', id));

    fetch('permissoes.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalGerenciarGrupo')).hide();
            location.reload();
        }
    });
}

// ──────────────────────────────────────────────────────────────────────────
// DUPLICAR GRUPO
// ──────────────────────────────────────────────────────────────────────────
function duplicarGrupo(id, nome) {
    if (!confirm(`Duplicar o grupo "${nome}"?\n\nSera criado "${nome} - COPIA" com as mesmas permissões.`)) return;
    const fd = new FormData();
    fd.append('acao_grupo', 'duplicar');
    fd.append('id_grupo', id);
    fetch('permissoes.php', { method: 'POST', body: fd })
    .then(() => location.reload());
}
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>