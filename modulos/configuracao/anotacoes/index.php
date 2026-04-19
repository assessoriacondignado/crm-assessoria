<?php
ini_set('display_errors', 0);
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!podeAcessarMenu($pdo, 'SUBMENU_ANOTACOES_GERAIS')) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Acesso Negado.</div></div></body></html>";
    exit;
}

if (isset($_GET['delete_anotacao'])) {
    $pdo->prepare("DELETE FROM CONFIG_ANOTACOES_GERAIS WHERE ID = ?")->execute([$_GET['delete_anotacao']]);
    echo "<script>window.location.href='index.php?msg=sucesso';</script>"; exit;
}
if (isset($_GET['delete_acesso'])) {
    $pdo->prepare("DELETE FROM CONFIG_ACESSOS WHERE ID = ?")->execute([$_GET['delete_acesso']]);
    echo "<script>window.location.href='index.php?msg=sucesso';</script>"; exit;
}

$cpf_logado   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$nome_logado  = $_SESSION['usuario_nome'] ?? '';
$grupo_logado = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master    = in_array($grupo_logado, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// --- Anotações ---
$anotacoes = [];
try { $anotacoes = $pdo->query("SELECT * FROM CONFIG_ANOTACOES_GERAIS ORDER BY DATA_ATUALIZACAO DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

// --- Acessos ---
$acessos = [];
try { $acessos = $pdo->query("SELECT * FROM CONFIG_ACESSOS ORDER BY DATA_ATUALIZACAO DESC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){}

// --- Empresas e Usuários (para seleção de destinatários) ---
$empresas_lista = [];
$usuarios_lista = [];
try {
    $empresas_lista = $pdo->query("SELECT ID, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO")->fetchAll(PDO::FETCH_ASSOC);
    $usuarios_lista = $pdo->query("SELECT CPF, NOME, GRUPO_USUARIOS FROM CLIENTE_USUARIO ORDER BY NOME")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

// --- Avisos para o usuário logado (meus avisos não lidos + lidos recentes) ---
$meus_avisos = [];
try {
    $stmtMeus = $pdo->prepare("
        SELECT a.ID, a.ASSUNTO, a.CONTEUDO, a.TIPO, a.NOME_CRIADOR, a.DATA_CRIACAO,
               (SELECT ID FROM AVISOS_INTERNOS_LEITURA WHERE AVISO_ID = a.ID AND CPF_USUARIO = ?) as LIDO_ID
        FROM AVISOS_INTERNOS a
        WHERE EXISTS (
            SELECT 1 FROM AVISOS_INTERNOS_DESTINATARIOS d
            WHERE d.AVISO_ID = a.ID AND (
                d.TIPO_DEST = 'TODOS'
                OR (d.TIPO_DEST = 'GRUPO'   AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'USUARIO' AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'EMPRESA' AND d.VALOR = (SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1))
            )
        )
        ORDER BY a.DATA_CRIACAO DESC
        LIMIT 50
    ");
    $stmtMeus->execute([$cpf_logado, $grupo_logado, $cpf_logado, $cpf_logado]);
    $meus_avisos = $stmtMeus->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

$nao_lidos = count(array_filter($meus_avisos, fn($a) => !$a['LIDO_ID']));

// --- Avisos enviados (somente MASTER vê) ---
$avisos_enviados = [];
if ($is_master) {
    try {
        $avisos_enviados = $pdo->query("
            SELECT a.ID, a.ASSUNTO, a.TIPO, a.NOME_CRIADOR, a.DATA_CRIACAO,
                   (SELECT COUNT(*) FROM AVISOS_INTERNOS_LEITURA WHERE AVISO_ID = a.ID) as TOTAL_LIDOS,
                   (SELECT COUNT(*) FROM AVISOS_INTERNOS_DESTINATARIOS WHERE AVISO_ID = a.ID) as TOTAL_DEST
            FROM AVISOS_INTERNOS a
            ORDER BY a.DATA_CRIACAO DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e){}
}
?>

<style>
.ck-editor__editable_inline { min-height: 200px; }
.guia-idx-item:hover { background: #e8f0fe !important; border-left-color: #6ea8fe !important; }
.guia-idx-item { user-select: none; }
.aviso-card { border-left: 4px solid #0d6efd; background: #f8f9ff; border-radius: 6px; padding: 14px 16px; margin-bottom: 10px; position: relative; }
.aviso-card.nao-lido { border-left-color: #dc3545; background: #fff8f8; }
.aviso-card.lido { border-left-color: #198754; opacity: 0.75; }
.aviso-card .aviso-tipo-badge { font-size: 0.6rem; text-transform: uppercase; font-weight: 700; padding: 2px 7px; border-radius: 10px; }
.aviso-form-panel { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
.dest-check-group { display: flex; flex-wrap: wrap; gap: 6px; }
.dest-check-group label { cursor: pointer; border: 1px solid #dee2e6; border-radius: 5px; padding: 4px 10px; font-size: 0.8rem; font-weight: 600; background: #fff; }
.dest-check-group input:checked + span { color: #0d6efd; }
#tbl-avisos-enviados th { background: #343a40; color: #fff; font-size: 0.7rem; text-transform: uppercase; }
#tbl-avisos-enviados td { font-size: 0.8rem; vertical-align: middle; }
.badge-tipo-auto { background: #6f42c1; }
.badge-tipo-master { background: #0d6efd; }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0 text-secondary"><i class="fas fa-book"></i> Central de Anotações e Acessos</h4>
        <?php if ($nao_lidos > 0): ?>
        <span class="badge bg-danger fs-6 shadow-sm">
            <i class="fas fa-bell me-1"></i> <?= $nao_lidos ?> aviso<?= $nao_lidos > 1 ? 's' : '' ?> não lido<?= $nao_lidos > 1 ? 's' : '' ?>
        </span>
        <?php endif; ?>
    </div>

    <ul class="nav nav-tabs" id="myTab">
        <li class="nav-item">
            <button class="nav-link active fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#anotacoes">
                <i class="fas fa-sticky-note text-warning"></i> Anotações Gerais
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold text-dark" data-bs-toggle="tab" data-bs-target="#acessos">
                <i class="fas fa-key text-primary"></i> Senhas e Acessos
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold text-dark position-relative" data-bs-toggle="tab" data-bs-target="#avisos" id="tab-avisos-btn">
                <i class="fas fa-bell text-danger"></i> Avisos Internos
                <?php if ($nao_lidos > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;"><?= $nao_lidos ?></span>
                <?php endif; ?>
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link fw-bold text-dark" <?php if($is_master): ?>data-bs-toggle="tab" data-bs-target="#guia-sistema" onclick="guiaCarregarLista()"<?php else: ?>onclick="guiaAbrirViewer()"<?php endif; ?>>
                <span style="font-size:1.1em;">👨‍🏫</span> Guia Sistema
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-4 bg-white rounded-bottom shadow-sm">

        <!-- ===== ABA ANOTAÇÕES ===== -->
        <div class="tab-pane fade show active" id="anotacoes">
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" id="filtroAnotacoes" class="form-control border-secondary" placeholder="Pesquisar anotações...">
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-primary fw-bold border-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAnotacao">
                        <i class="fas fa-plus"></i> Nova Anotação
                    </button>
                </div>
            </div>
            <div class="table-responsive border border-dark rounded shadow-sm">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-dark">
                        <tr><th class="text-start">Assunto / Título</th><th>Última Atualização</th><th>Ações</th></tr>
                    </thead>
                    <tbody id="listaAnotacoes" class="bg-white">
                        <?php if(!empty($anotacoes)): foreach($anotacoes as $anot): ?>
                        <tr class="border-bottom border-dark" id="row-anot-<?= $anot['ID'] ?>">
                            <td class="fw-bold text-dark text-start"><?= htmlspecialchars($anot['ASSUNTO']) ?></td>
                            <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($anot['DATA_ATUALIZACAO'])) ?></td>
                            <td>
                                <div id="anot_html_<?= $anot['ID'] ?>" class="d-none"><?= $anot['ANOTACAO'] ?></div>
                                <button class="btn btn-sm btn-dark fw-bold border-dark shadow-sm btn-view-anot" data-id="<?= $anot['ID'] ?>" data-assunto="<?= htmlspecialchars($anot['ASSUNTO']) ?>"><i class="fas fa-eye"></i> Ler</button>
                                <button class="btn btn-sm btn-warning fw-bold border-dark shadow-sm" onclick="abrirEditarAnotacao(<?= $anot['ID'] ?>, '<?= addslashes(htmlspecialchars($anot['ASSUNTO'])) ?>')"><i class="fas fa-edit"></i> Editar</button>
                                <button class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="excluirAnotacao(<?= $anot['ID'] ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted fw-bold">Nenhuma anotação encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== ABA ACESSOS ===== -->
        <div class="tab-pane fade" id="acessos">
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" id="filtroAcessos" class="form-control border-secondary" placeholder="Pesquisar sistemas, bancos, usuários...">
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-primary fw-bold border-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAcesso">
                        <i class="fas fa-plus"></i> Novo Acesso
                    </button>
                </div>
            </div>
            <div class="table-responsive border border-dark rounded shadow-sm">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-dark">
                        <tr><th>Tipo</th><th class="text-start">Origem (Sistema/Banco)</th><th>Usuário</th><th>Senha</th><th>Atualização</th><th>Ações</th></tr>
                    </thead>
                    <tbody id="listaAcessos" class="bg-white">
                        <?php if(!empty($acessos)): foreach($acessos as $acesso): ?>
                        <tr class="border-bottom border-dark" id="row-acesso-<?= $acesso['ID'] ?>">
                            <td><span class="badge bg-<?= $acesso['TIPO'] == 'BANCOS' ? 'success' : 'info text-dark' ?> border border-dark"><?= $acesso['TIPO'] ?></span></td>
                            <td class="fw-bold text-dark text-start"><?= htmlspecialchars($acesso['ORIGEM']) ?></td>
                            <td class="fw-medium text-primary"><?= htmlspecialchars($acesso['USUARIO']) ?></td>
                            <td>
                                <input type="password" value="<?= htmlspecialchars($acesso['SENHA']) ?>" class="form-control form-control-sm border-0 bg-transparent p-0 text-center fw-bold" readonly style="width:120px; display:inline-block;">
                                <button class="btn btn-sm text-secondary toggle-senha border-0"><i class="fas fa-eye"></i></button>
                            </td>
                            <td class="text-muted small"><?= date('d/m/Y', strtotime($acesso['DATA_ATUALIZACAO'])) ?></td>
                            <td>
                                <div id="acesso_uso_<?= $acesso['ID'] ?>" class="d-none"><?= nl2br(htmlspecialchars($acesso['USO'])) ?></div>
                                <div id="acesso_obs_<?= $acesso['ID'] ?>" class="d-none"><?= nl2br(htmlspecialchars($acesso['OBSERVACAO'])) ?></div>
                                <button class="btn btn-sm btn-dark fw-bold border-dark shadow-sm btn-view-acesso" data-id="<?= $acesso['ID'] ?>" data-origem="<?= htmlspecialchars($acesso['ORIGEM']) ?>"><i class="fas fa-info-circle"></i> Info</button>
                                <button class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="excluirAcesso(<?= $acesso['ID'] ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Nenhum acesso cadastrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== ABA AVISOS INTERNOS ===== -->
        <div class="tab-pane fade" id="avisos">

            <?php if ($is_master): ?>
            <!-- FORMULÁRIO DE NOVO AVISO (somente MASTER) -->
            <div class="mb-3 text-end">
                <button class="btn btn-danger fw-bold shadow-sm" id="btnNovoAviso" onclick="avisoToggleForm()">
                    <i class="fas fa-plus me-1"></i> Novo Aviso
                </button>
            </div>

            <div id="avisoFormPanel" class="aviso-form-panel" style="display:none;">
                <h6 class="fw-bold text-dark mb-3"><i class="fas fa-bullhorn text-danger me-2"></i> Criar Aviso Interno</h6>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="fw-bold small text-dark mb-1">Assunto <span class="text-danger">*</span></label>
                        <input type="text" id="aviso_assunto" class="form-control border-dark" placeholder="Ex: Atualização de sistema em 20/04" maxlength="200">
                    </div>
                    <div class="col-12">
                        <label class="fw-bold small text-dark mb-1">Mensagem <span class="text-danger">*</span></label>
                        <div class="border border-secondary rounded" style="background:#fff;">
                            <!-- Barra de formatação simples -->
                            <div class="d-flex gap-1 flex-wrap p-2 border-bottom border-secondary" style="background:#f8f9fa;">
                                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="avisoFmt('bold')" title="Negrito"><b>B</b></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary fst-italic" onclick="avisoFmt('italic')" title="Itálico"><i>I</i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="avisoFmt('underline')" title="Sublinhado"><u>U</u></button>
                                <span class="border-start border-secondary mx-1"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="avisoFmt('insertUnorderedList')" title="Lista com marcadores"><i class="fas fa-list-ul"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="avisoFmt('insertOrderedList')" title="Lista numerada"><i class="fas fa-list-ol"></i></button>
                                <span class="border-start border-secondary mx-1"></span>
                                <select class="form-select form-select-sm border-secondary" style="width:auto;" onchange="avisoFmtBlock(this.value); this.value='';">
                                    <option value="">Parágrafo</option>
                                    <option value="h3">Título</option>
                                    <option value="h5">Subtítulo</option>
                                    <option value="p">Normal</option>
                                </select>
                                <span class="border-start border-secondary mx-1"></span>
                                <select class="form-select form-select-sm border-secondary" style="width:auto;" onchange="avisoFmtCor(this.value); this.value='';">
                                    <option value="">Cor do texto</option>
                                    <option value="#dc3545">Vermelho</option>
                                    <option value="#198754">Verde</option>
                                    <option value="#0d6efd">Azul</option>
                                    <option value="#e67e22">Laranja</option>
                                    <option value="#333333">Preto</option>
                                </select>
                            </div>
                            <div id="aviso_editor"
                                 contenteditable="true"
                                 style="min-height:160px; max-height:400px; overflow-y:auto; padding:12px 14px; outline:none; font-size:0.92rem; resize:vertical;"
                                 placeholder="Digite o conteúdo do aviso aqui..."></div>
                        </div>
                    </div>

                    <!-- DESTINATÁRIOS -->
                    <div class="col-12">
                        <label class="fw-bold small text-dark mb-2">Destinatários <span class="text-danger">*</span></label>
                        <div class="p-3 bg-white border border-secondary rounded">
                            <!-- Tipo de destino -->
                            <div class="mb-3">
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="dest_tipo" id="dest_todos" value="TODOS" checked onchange="avisoToggleDest()">
                                    <label class="form-check-label fw-bold" for="dest_todos"><i class="fas fa-globe me-1 text-success"></i> Todos os usuários</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="dest_tipo" id="dest_empresa" value="EMPRESA" onchange="avisoToggleDest()">
                                    <label class="form-check-label fw-bold" for="dest_empresa"><i class="fas fa-building me-1 text-primary"></i> Por Empresa</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="dest_tipo" id="dest_grupo" value="GRUPO" onchange="avisoToggleDest()">
                                    <label class="form-check-label fw-bold" for="dest_grupo"><i class="fas fa-users me-1 text-warning"></i> Por Grupo</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="dest_tipo" id="dest_usuario" value="USUARIO" onchange="avisoToggleDest()">
                                    <label class="form-check-label fw-bold" for="dest_usuario"><i class="fas fa-user me-1 text-info"></i> Usuários Específicos</label>
                                </div>
                            </div>

                            <!-- Empresa -->
                            <div id="panel_dest_empresa" class="d-none">
                                <label class="small fw-bold text-muted mb-1">Selecione a(s) empresa(s):</label>
                                <select id="sel_empresas" class="form-select border-dark" multiple size="5">
                                    <?php foreach ($empresas_lista as $emp): ?>
                                    <option value="<?= $emp['ID'] ?>"><?= htmlspecialchars($emp['NOME_CADASTRO']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Segure Ctrl para selecionar mais de uma</small>
                            </div>

                            <!-- Grupo -->
                            <div id="panel_dest_grupo" class="d-none">
                                <label class="small fw-bold text-muted mb-1">Selecione o(s) grupo(s):</label>
                                <div class="dest-check-group">
                                    <label><input type="checkbox" class="form-check-input me-1 chk-grupo" value="MASTER"> <span>MASTER</span></label>
                                    <label><input type="checkbox" class="form-check-input me-1 chk-grupo" value="SUPERVISORES"> <span>SUPERVISORES</span></label>
                                    <label><input type="checkbox" class="form-check-input me-1 chk-grupo" value="CONSULTOR"> <span>CONSULTOR</span></label>
                                </div>
                            </div>

                            <!-- Usuário -->
                            <div id="panel_dest_usuario" class="d-none">
                                <label class="small fw-bold text-muted mb-1">Buscar e selecionar usuários:</label>
                                <input type="text" id="busca_usuarios" class="form-control form-control-sm border-secondary mb-2" placeholder="Filtrar usuários..." oninput="filtrarUsuariosAviso()">
                                <select id="sel_usuarios" class="form-select border-dark" multiple size="6">
                                    <?php foreach ($usuarios_lista as $usr): ?>
                                    <option value="<?= htmlspecialchars($usr['CPF']) ?>" data-nome="<?= strtolower(htmlspecialchars($usr['NOME'])) ?>">
                                        <?= htmlspecialchars($usr['NOME']) ?> (<?= htmlspecialchars($usr['GRUPO_USUARIOS'] ?? 'SEM GRUPO') ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Segure Ctrl para selecionar mais de um</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <button type="button" class="btn btn-secondary fw-bold border-dark" onclick="avisoToggleForm()">Cancelar</button>
                        <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" id="btnSalvarAviso" onclick="salvarAviso()">
                            <i class="fas fa-paper-plane me-1"></i> Enviar Aviso
                        </button>
                    </div>
                </div>
            </div>

            <!-- LISTA DE AVISOS ENVIADOS (MASTER) -->
            <div class="mb-2 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2 text-muted"></i> Avisos Enviados</h6>
            </div>
            <div class="table-responsive border border-dark rounded shadow-sm mb-4">
                <table class="table table-hover table-bordered table-sm align-middle mb-0" id="tbl-avisos-enviados">
                    <thead>
                        <tr>
                            <th>Tipo</th>
                            <th class="text-start">Assunto</th>
                            <th>Enviado por</th>
                            <th>Data Envio</th>
                            <th>Leituras</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-avisos-enviados" class="bg-white">
                        <?php if (empty($avisos_enviados)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Nenhum aviso enviado ainda.</td></tr>
                        <?php else: foreach ($avisos_enviados as $av): ?>
                        <tr>
                            <td class="text-center">
                                <span class="badge <?= $av['TIPO'] === 'AUTOMATICO' ? 'badge-tipo-auto' : 'badge-tipo-master' ?> text-white" style="font-size:0.6rem;">
                                    <?= $av['TIPO'] === 'AUTOMATICO' ? 'AUTO' : 'MASTER' ?>
                                </span>
                            </td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($av['ASSUNTO']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($av['NOME_CRIADOR'] ?? '--') ?></td>
                            <td class="text-muted small text-nowrap"><?= date('d/m/Y H:i', strtotime($av['DATA_CRIACAO'])) ?></td>
                            <td class="text-center">
                                <span class="badge bg-success"><?= $av['TOTAL_LIDOS'] ?></span> lido(s)
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-dark border-dark fw-bold" onclick="verAvisoMaster(<?= $av['ID'] ?>)"><i class="fas fa-eye"></i></button>
                                <button class="btn btn-sm btn-danger border-dark fw-bold" onclick="excluirAviso(<?= $av['ID'] ?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="text-center" id="btn-mais-avisos-env">
                <?php if (count($avisos_enviados) >= 20): ?>
                <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="carregarMaisAvisosEnviados()">
                    <i class="fas fa-chevron-down me-1"></i> Ver mais 20
                </button>
                <?php endif; ?>
            </div>

            <hr class="my-4">
            <?php endif; // is_master ?>

            <!-- MEUS AVISOS (todos os usuários veem) -->
            <div class="mb-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold text-dark mb-0">
                    <i class="fas fa-inbox me-2 text-danger"></i> Meus Avisos
                    <?php if ($nao_lidos > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $nao_lidos ?> não lido<?= $nao_lidos > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </h6>
                <?php if ($nao_lidos > 0): ?>
                <button class="btn btn-sm btn-outline-success fw-bold border-dark" onclick="marcarTodosLidos()">
                    <i class="fas fa-check-double me-1"></i> Marcar todos como lido
                </button>
                <?php endif; ?>
            </div>

            <div id="lista-meus-avisos">
                <?php if (empty($meus_avisos)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-bell-slash fa-2x mb-3 d-block opacity-25"></i>
                    <span class="fw-bold">Nenhum aviso para você no momento.</span>
                </div>
                <?php else: ?>
                <?php $count_av = 0; foreach ($meus_avisos as $av):
                    $is_lido = !empty($av['LIDO_ID']);
                    $count_av++;
                    $hidden = $count_av > 20 ? 'style="display:none;" data-extra="1"' : '';
                ?>
                <div class="aviso-card <?= $is_lido ? 'lido' : 'nao-lido' ?>" id="aviso-card-<?= $av['ID'] ?>" <?= $hidden ?>>
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <?php if (!$is_lido): ?>
                                <span class="badge bg-danger aviso-tipo-badge">Não lido</span>
                                <?php endif; ?>
                                <span class="badge text-white aviso-tipo-badge <?= $av['TIPO'] === 'AUTOMATICO' ? 'badge-tipo-auto' : 'badge-tipo-master' ?>">
                                    <?= $av['TIPO'] === 'AUTOMATICO' ? 'Automático' : 'Aviso Master' ?>
                                </span>
                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($av['DATA_CRIACAO'])) ?></small>
                                <?php if (!empty($av['NOME_CRIADOR'])): ?>
                                <small class="text-muted">· por <?= htmlspecialchars($av['NOME_CRIADOR']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($av['ASSUNTO']) ?></div>
                            <div class="aviso-resumo text-muted small" style="overflow:hidden; max-height:40px;" id="aviso-resumo-<?= $av['ID'] ?>">
                                <?= strip_tags($av['CONTEUDO']) ?>
                            </div>
                        </div>
                        <div class="d-flex flex-column gap-1 ms-3">
                            <button class="btn btn-sm btn-outline-dark border-dark fw-bold" onclick="verAviso(<?= $av['ID'] ?>, '<?= addslashes(htmlspecialchars($av['ASSUNTO'])) ?>', <?= $is_lido ? 'true' : 'false' ?>)">
                                <i class="fas fa-eye"></i> Ler
                            </button>
                            <?php if (!$is_lido): ?>
                            <button class="btn btn-sm btn-outline-success border-dark fw-bold" onclick="marcarLido(<?= $av['ID'] ?>)">
                                <i class="fas fa-check"></i> Lido
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if ($count_av > 20): ?>
                <div class="text-center mt-3" id="btn-mais-meus-avisos">
                    <button class="btn btn-outline-secondary btn-sm fw-bold" onclick="verMaisAvisos()">
                        <i class="fas fa-chevron-down me-1"></i> Ver mais <?= min(20, $count_av - 20) ?>
                    </button>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </div><!-- fim aba avisos -->

        <!-- ===== ABA GUIA SISTEMA ===== -->
        <?php if ($is_master): ?>
        <div class="tab-pane fade" id="guia-sistema">
            <div class="row mb-3 align-items-center">
                <div class="col">
                    <h6 class="fw-bold text-dark mb-0"><span style="font-size:1.2em;">👨‍🏫</span> Conteúdos de Treinamento</h6>
                    <small class="text-muted">Crie guias de vídeo, imagem ou texto para auxiliar os usuários do sistema.</small>
                </div>
                <div class="col-auto">
                    <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="guiaAbrirModal(0)">
                        <i class="fas fa-plus me-1"></i> Novo Conteúdo
                    </button>
                </div>
            </div>

            <!-- Filtro -->
            <div class="mb-3">
                <input type="text" id="guia_filtro_admin" class="form-control border-secondary" placeholder="🔍 Filtrar por título ou índice..." oninput="guiaFiltrarTabela(this.value)">
            </div>
            <div class="table-responsive border border-dark rounded shadow-sm">
                <table class="table table-hover align-middle mb-0" id="tbl-guia">
                    <thead class="table-dark">
                        <tr>
                            <th class="text-center ps-3" style="width:90px;">Índice</th>
                            <th class="text-start">Título</th>
                            <th class="text-center" style="width:110px;">Tipo</th>
                            <th class="text-center" style="width:100px;">Status</th>
                            <th class="text-center" style="width:130px;">Data</th>
                            <th class="text-center" style="width:160px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-guia" class="bg-white">
                        <tr id="guia-loading-row">
                            <td colspan="6" class="text-center py-4 text-muted fw-bold">
                                <i class="fas fa-spinner fa-spin me-2"></i> Carregando...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- fim tab-content -->
</div>

<!-- MODAL LER ANOTAÇÃO -->
<div class="modal fade" id="modalVerAnotacao" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-book-open text-warning me-2"></i> Lendo: <span id="lerAnotacaoAssunto" class="text-info"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white text-dark" id="lerAnotacaoConteudo" style="min-height:300px; font-size:1.05rem; line-height:1.6;"></div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL LER AVISO -->
<div class="modal fade" id="modalVerAviso" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-bell text-danger me-2"></i> <span id="modalAvisoAssunto"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white" id="modalAvisoConteudo" style="min-height:200px; font-size:0.95rem; line-height:1.7;"></div>
            <div class="modal-footer bg-light">
                <button type="button" id="btnModalMarcarLido" class="btn btn-success fw-bold border-dark d-none" onclick="marcarLidoModal()">
                    <i class="fas fa-check me-1"></i> Marcar como Lido
                </button>
                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL NOVA ANOTAÇÃO -->
<div class="modal fade" id="modalAnotacao" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark">
            <form action="ajax_anotacao.php" method="POST">
                <input type="hidden" name="acao" value="salvar_anotacao">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-warning me-2"></i> Nova Anotação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <div class="mb-3">
                        <label class="fw-bold text-dark mb-1">Assunto / Título</label>
                        <input type="text" name="assunto" class="form-control border-dark" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold text-dark mb-1">Anotação</label>
                        <textarea name="anotacao" id="editor_anotacao" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-danger fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-save me-1"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDITAR ANOTAÇÃO -->
<div class="modal fade" id="modalEditarAnotacao" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit me-2"></i> Editar Anotação</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <input type="hidden" id="edit_anot_id">
                <div class="mb-3">
                    <label class="fw-bold text-dark mb-1">Assunto / Título</label>
                    <input type="text" id="edit_anot_assunto" class="form-control border-dark" required>
                </div>
                <div class="mb-3">
                    <label class="fw-bold text-dark mb-1">Anotação</label>
                    <textarea id="editor_anotacao_edit" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-danger fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning fw-bold border-dark" id="btnSalvarEdicao" onclick="salvarEdicaoAnotacao()">
                    <i class="fas fa-save me-1"></i> Salvar Alterações
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL NOVO ACESSO -->
<div class="modal fade" id="modalAcesso" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-dark">
            <form action="ajax_anotacao.php" method="POST">
                <input type="hidden" name="acao" value="salvar_acesso">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-key text-info me-2"></i> Novo Acesso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="fw-bold text-dark mb-1">Tipo</label>
                            <select name="tipo" class="form-select border-dark fw-bold">
                                <option value="SISTEMAS">Sistemas</option>
                                <option value="BANCOS">Bancos</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="fw-bold text-dark mb-1">Origem</label>
                            <input type="text" name="origem" class="form-control border-dark" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-dark mb-1">Usuário / Login</label>
                            <input type="text" name="usuario" class="form-control border-dark">
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-dark mb-1">Senha</label>
                            <input type="text" name="senha" class="form-control border-dark">
                        </div>
                        <div class="col-12">
                            <label class="fw-bold text-dark mb-1">Finalidade</label>
                            <textarea name="uso" class="form-control border-secondary" rows="2"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="fw-bold text-dark mb-1">Observações</label>
                            <textarea name="observacao" class="form-control border-secondary" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-danger fw-bold" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="fas fa-save me-1"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL VER ACESSO -->
<div class="modal fade" id="modalVerAcesso" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-info-circle text-info me-2"></i> <span id="lerAcessoOrigem" class="text-warning"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="mb-3 p-3 bg-white border rounded shadow-sm">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Finalidade / Uso</h6>
                    <div id="lerAcessoUso" class="text-dark"></div>
                </div>
                <div class="p-3 bg-white border rounded shadow-sm">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Observações</h6>
                    <div id="lerAcessoObs" class="text-dark"></div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<script>
// CKEditor para nova anotação
let ckNova, ckEdit;
ClassicEditor.create(document.querySelector('#editor_anotacao'), {
    toolbar: ['heading','|','bold','italic','link','bulletedList','numberedList','blockQuote','|','undo','redo']
}).then(editor => { ckNova = editor; }).catch(e => console.error(e));

// CKEditor para edição
ClassicEditor.create(document.querySelector('#editor_anotacao_edit'), {
    toolbar: ['heading','|','bold','italic','link','bulletedList','numberedList','blockQuote','|','undo','redo']
}).then(editor => { ckEdit = editor; }).catch(e => console.error(e));

// Abre modal de edição e carrega o conteúdo atual
function abrirEditarAnotacao(id, assunto) {
    document.getElementById('edit_anot_id').value = id;
    document.getElementById('edit_anot_assunto').value = assunto;
    // Carrega o conteúdo do HTML já no DOM (sem ajax extra)
    const htmlDiv = document.getElementById('anot_html_' + id);
    if (ckEdit) ckEdit.setData(htmlDiv ? htmlDiv.innerHTML : '');
    new bootstrap.Modal(document.getElementById('modalEditarAnotacao')).show();
}

async function salvarEdicaoAnotacao() {
    const id      = document.getElementById('edit_anot_id').value;
    const assunto = document.getElementById('edit_anot_assunto').value.trim();
    const conteudo = ckEdit ? ckEdit.getData() : '';

    if (!assunto || !conteudo) { alert('Preencha o assunto e a anotação.'); return; }

    const btn = document.getElementById('btnSalvarEdicao');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Salvando...';

    const fd = new FormData();
    fd.append('acao', 'editar_anotacao');
    fd.append('id', id);
    fd.append('assunto', assunto);
    fd.append('anotacao', conteudo);

    try {
        const r = await fetch('ajax_anotacao.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            // Atualiza o DOM sem recarregar a página
            const row = document.getElementById('row-anot-' + id);
            if (row) {
                row.querySelector('td:first-child').textContent = assunto;
                row.querySelector('td:nth-child(2)').textContent = j.data_br;
                document.getElementById('anot_html_' + id).innerHTML = conteudo;
                const btnView = row.querySelector('.btn-view-anot');
                if (btnView) btnView.setAttribute('data-assunto', assunto);
            }
            bootstrap.Modal.getInstance(document.getElementById('modalEditarAnotacao'))?.hide();
        } else {
            alert('Erro: ' + (j.msg || 'Falha ao salvar.'));
        }
    } catch(e) { alert('Falha de comunicação.'); }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-save me-1"></i> Salvar Alterações';
}

async function excluirAnotacao(id) {
    if (!confirm('Excluir esta anotação? Esta ação não pode ser desfeita.')) return;
    const fd = new FormData();
    fd.append('acao', 'excluir_anotacao');
    fd.append('id', id);
    try {
        const r = await fetch('ajax_anotacao.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            const row = document.getElementById('row-anot-' + id);
            if (row) row.remove();
            const tbody = document.getElementById('listaAnotacoes');
            if (tbody && !tbody.querySelector('tr[id^="row-anot-"]')) {
                tbody.innerHTML = '<tr><td colspan="3" class="text-center py-4 text-muted fw-bold">Nenhuma anotação encontrada.</td></tr>';
            }
        } else { alert('Erro: ' + (j.msg || 'Falha ao excluir.')); }
    } catch(e) { alert('Falha de comunicação.'); }
}

async function excluirAcesso(id) {
    if (!confirm('Excluir este acesso? Esta ação não pode ser desfeita.')) return;
    const fd = new FormData();
    fd.append('acao', 'excluir_acesso');
    fd.append('id', id);
    try {
        const r = await fetch('ajax_anotacao.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            const row = document.getElementById('row-acesso-' + id);
            if (row) row.remove();
            const tbody = document.getElementById('listaAcessos');
            if (tbody && !tbody.querySelector('tr[id^="row-acesso-"]')) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Nenhum acesso cadastrado.</td></tr>';
            }
        } else { alert('Erro: ' + (j.msg || 'Falha ao excluir.')); }
    } catch(e) { alert('Falha de comunicação.'); }
}

// Filtros
document.getElementById('filtroAnotacoes').addEventListener('keyup', function() {
    let t = this.value.toLowerCase();
    document.querySelectorAll('#listaAnotacoes tr').forEach(r => r.style.display = r.innerText.toLowerCase().includes(t) ? '' : 'none');
});
document.getElementById('filtroAcessos').addEventListener('keyup', function() {
    let t = this.value.toLowerCase();
    document.querySelectorAll('#listaAcessos tr').forEach(r => r.style.display = r.innerText.toLowerCase().includes(t) ? '' : 'none');
});

// Toggle senha
document.querySelectorAll('.toggle-senha').forEach(btn => {
    btn.addEventListener('click', function() {
        let inp = this.previousElementSibling, icon = this.querySelector('i');
        inp.type = inp.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('fa-eye'); icon.classList.toggle('fa-eye-slash');
        icon.classList.toggle('text-danger', inp.type === 'text');
    });
});

// Abrir anotação
document.querySelectorAll('.btn-view-anot').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('lerAnotacaoAssunto').innerText = this.getAttribute('data-assunto');
        document.getElementById('lerAnotacaoConteudo').innerHTML = document.getElementById('anot_html_' + this.getAttribute('data-id')).innerHTML;
        new bootstrap.Modal(document.getElementById('modalVerAnotacao')).show();
    });
});

// Abrir acesso
document.querySelectorAll('.btn-view-acesso').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.getAttribute('data-id');
        document.getElementById('lerAcessoOrigem').innerText = this.getAttribute('data-origem');
        document.getElementById('lerAcessoUso').innerHTML = document.getElementById('acesso_uso_' + id).innerHTML || '<em class="text-muted">Nenhuma informação.</em>';
        document.getElementById('lerAcessoObs').innerHTML = document.getElementById('acesso_obs_' + id).innerHTML || '<em class="text-muted">Nenhuma informação.</em>';
        new bootstrap.Modal(document.getElementById('modalVerAcesso')).show();
    });
});

// ==============================
// AVISOS INTERNOS
// ==============================
let _avisoAtualId = null;

function avisoToggleForm() {
    const p = document.getElementById('avisoFormPanel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
    if (p.style.display !== 'none') document.getElementById('aviso_assunto').focus();
}

function avisoToggleDest() {
    const val = document.querySelector('input[name="dest_tipo"]:checked')?.value;
    document.getElementById('panel_dest_empresa').classList.toggle('d-none', val !== 'EMPRESA');
    document.getElementById('panel_dest_grupo').classList.toggle('d-none', val !== 'GRUPO');
    document.getElementById('panel_dest_usuario').classList.toggle('d-none', val !== 'USUARIO');
}

function avisoFmt(cmd) {
    document.getElementById('aviso_editor').focus();
    document.execCommand(cmd, false, null);
}
function avisoFmtBlock(tag) {
    if (!tag) return;
    document.getElementById('aviso_editor').focus();
    document.execCommand('formatBlock', false, tag);
}
function avisoFmtCor(cor) {
    if (!cor) return;
    document.getElementById('aviso_editor').focus();
    document.execCommand('foreColor', false, cor);
}

function filtrarUsuariosAviso() {
    const q = document.getElementById('busca_usuarios').value.toLowerCase();
    document.querySelectorAll('#sel_usuarios option').forEach(opt => {
        opt.style.display = opt.getAttribute('data-nome').includes(q) ? '' : 'none';
    });
}

async function salvarAviso() {
    const assunto = document.getElementById('aviso_assunto').value.trim();
    const conteudo = document.getElementById('aviso_editor').innerHTML.trim();
    if (!assunto || !conteudo || conteudo === '<br>') { alert('Preencha o assunto e a mensagem.'); return; }

    const destTipo = document.querySelector('input[name="dest_tipo"]:checked')?.value || 'TODOS';
    let destValores = [];

    if (destTipo === 'EMPRESA') {
        destValores = Array.from(document.getElementById('sel_empresas').selectedOptions).map(o => o.value);
        if (!destValores.length) { alert('Selecione ao menos uma empresa.'); return; }
    } else if (destTipo === 'GRUPO') {
        destValores = Array.from(document.querySelectorAll('.chk-grupo:checked')).map(c => c.value);
        if (!destValores.length) { alert('Selecione ao menos um grupo.'); return; }
    } else if (destTipo === 'USUARIO') {
        destValores = Array.from(document.getElementById('sel_usuarios').selectedOptions).map(o => o.value);
        if (!destValores.length) { alert('Selecione ao menos um usuário.'); return; }
    }

    const btn = document.getElementById('btnSalvarAviso');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando...';

    const fd = new FormData();
    fd.append('acao', 'salvar_aviso');
    fd.append('assunto', assunto);
    fd.append('conteudo', conteudo);
    fd.append('dest_tipo', destTipo);
    fd.append('dest_valores', JSON.stringify(destValores));

    try {
        const r = await fetch('ajax_anotacao.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.success) {
            alert('Aviso enviado com sucesso!');
            location.reload();
        } else {
            alert('Erro: ' + j.msg);
        }
    } catch(e) { alert('Falha de comunicação.'); }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Enviar Aviso';
}

function verAviso(id, assunto, jaLido) {
    _avisoAtualId = id;
    document.getElementById('modalAvisoAssunto').textContent = assunto;
    // Busca conteúdo
    fetch('ajax_anotacao.php', { method:'POST', body: (() => { const f=new FormData(); f.append('acao','get_aviso'); f.append('id',id); return f; })() })
        .then(r => r.json()).then(j => {
            if (j.success) {
                document.getElementById('modalAvisoConteudo').innerHTML = j.conteudo;
                const btnLido = document.getElementById('btnModalMarcarLido');
                btnLido.classList.toggle('d-none', jaLido);
                new bootstrap.Modal(document.getElementById('modalVerAviso')).show();
            }
        });
}

function marcarLidoModal() {
    if (_avisoAtualId) {
        marcarLido(_avisoAtualId);
        bootstrap.Modal.getInstance(document.getElementById('modalVerAviso'))?.hide();
    }
}

async function marcarLido(id) {
    const fd = new FormData(); fd.append('acao', 'marcar_lido'); fd.append('id', id);
    const r = await fetch('ajax_anotacao.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.success) {
        const card = document.getElementById('aviso-card-' + id);
        if (card) {
            card.classList.remove('nao-lido'); card.classList.add('lido');
            const btnLido = card.querySelector('.btn-outline-success');
            if (btnLido) btnLido.remove();
            const badgeNaoLido = card.querySelector('.badge.bg-danger');
            if (badgeNaoLido) badgeNaoLido.remove();
        }
        // Atualiza contador
        const cont = document.querySelector('.badge.bg-danger.fs-6');
        if (cont) {
            const num = parseInt(cont.textContent) - 1;
            num > 0 ? cont.textContent = num + (num===1?' aviso não lido':' avisos não lidos') : cont.remove();
        }
    }
}

async function marcarTodosLidos() {
    const fd = new FormData(); fd.append('acao', 'marcar_todos_lidos');
    const r = await fetch('ajax_anotacao.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.success) location.reload();
}

function verMaisAvisos() {
    let count = 0;
    document.querySelectorAll('[data-extra="1"]').forEach(el => {
        if (count < 20) { el.style.display = ''; el.removeAttribute('data-extra'); count++; }
    });
    const remaining = document.querySelectorAll('[data-extra="1"]').length;
    const btn = document.getElementById('btn-mais-meus-avisos');
    if (btn && remaining === 0) btn.remove();
    else if (btn) btn.querySelector('button').textContent = 'Ver mais ' + Math.min(20, remaining);
}

<?php if ($is_master): ?>
function verAvisoMaster(id) {
    fetch('ajax_anotacao.php', { method:'POST', body: (() => { const f=new FormData(); f.append('acao','get_aviso'); f.append('id',id); return f; })() })
        .then(r => r.json()).then(j => {
            if (j.success) {
                document.getElementById('modalAvisoAssunto').textContent = j.assunto;
                document.getElementById('modalAvisoConteudo').innerHTML = j.conteudo;
                document.getElementById('btnModalMarcarLido').classList.add('d-none');
                new bootstrap.Modal(document.getElementById('modalVerAviso')).show();
            }
        });
}

async function excluirAviso(id) {
    if (!confirm('Excluir este aviso? Todos os registros de leitura também serão removidos.')) return;
    const fd = new FormData(); fd.append('acao', 'excluir_aviso'); fd.append('id', id);
    const r = await fetch('ajax_anotacao.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.success) location.reload();
    else alert('Erro: ' + j.msg);
}

let _offsetAvisosEnv = 20;
async function carregarMaisAvisosEnviados() {
    const fd = new FormData(); fd.append('acao','listar_avisos_master'); fd.append('offset', _offsetAvisosEnv);
    const r = await fetch('ajax_anotacao.php', { method:'POST', body: fd });
    const j = await r.json();
    if (j.success && j.data.length) {
        const tb = document.getElementById('tbody-avisos-enviados');
        j.data.forEach(av => {
            tb.innerHTML += `<tr>
                <td class="text-center"><span class="badge text-white" style="font-size:0.6rem; background:${av.TIPO==='AUTOMATICO'?'#6f42c1':'#0d6efd'}">${av.TIPO==='AUTOMATICO'?'AUTO':'MASTER'}</span></td>
                <td class="fw-bold text-dark">${av.ASSUNTO}</td>
                <td class="text-muted small">${av.NOME_CRIADOR||'--'}</td>
                <td class="text-muted small text-nowrap">${av.DATA_CRIACAO_BR}</td>
                <td class="text-center"><span class="badge bg-success">${av.TOTAL_LIDOS}</span> lido(s)</td>
                <td class="text-center">
                    <button class="btn btn-sm btn-dark border-dark fw-bold" onclick="verAvisoMaster(${av.ID})"><i class="fas fa-eye"></i></button>
                    <button class="btn btn-sm btn-danger border-dark fw-bold" onclick="excluirAviso(${av.ID})"><i class="fas fa-trash"></i></button>
                </td></tr>`;
        });
        _offsetAvisosEnv += j.data.length;
        if (j.data.length < 20) document.getElementById('btn-mais-avisos-env').remove();
    } else {
        document.getElementById('btn-mais-avisos-env').remove();
    }
}
<?php endif; ?>

// Abre aba avisos se URL tiver #avisos
if (window.location.hash === '#avisos') {
    document.getElementById('tab-avisos-btn')?.click();
}

// =============================================================
// GUIA SISTEMA
// =============================================================
<?php if ($is_master): ?>
let _guiaListaCarregada = false;

let _guiaDados = [];

function guiaCarregarLista(forcar) {
    if (_guiaListaCarregada && !forcar) return;
    _guiaListaCarregada = true;
    const fd = new FormData(); fd.append('acao','listar');
    fetch('guia_ajax.php', {method:'POST', body:fd})
        .then(r => r.json()).then(j => {
            if (!j.success) { document.getElementById('tbody-guia').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger fw-bold">Erro ao carregar dados.</td></tr>'; return; }
            _guiaDados = j.data || [];
            guiaRenderizarTabela(_guiaDados);
        }).catch(() => {
            document.getElementById('tbody-guia').innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger fw-bold">Erro ao carregar dados.</td></tr>';
        });
}

function guiaRenderizarTabela(dados) {
    const tb = document.getElementById('tbody-guia');
    if (!dados.length) {
        tb.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-muted fw-bold"><i class="fas fa-graduation-cap fa-2x d-block mb-2 opacity-25"></i>Nenhum conteúdo cadastrado ainda.</td></tr>';
        return;
    }
    const tipoIco = {VIDEO:'<span class="badge bg-danger">🎬 Vídeo</span>', IMAGEM:'<span class="badge bg-info text-dark">🖼️ Imagem</span>', TEXTO:'<span class="badge bg-secondary">📝 Texto</span>'};
    tb.innerHTML = dados.map(g => `
        <tr id="guia-row-${g.ID}" data-titulo="${escHtml(g.TITULO)}" data-indice="${escHtml(g.INDICE||'')}">
            <td class="text-center ps-2">
                <span class="badge bg-dark fw-bold" style="font-size:0.75rem;letter-spacing:0.5px;">${escHtml(g.INDICE||'—')}</span>
            </td>
            <td class="ps-2">
                <div class="fw-bold text-dark">${escHtml(g.TITULO)}</div>
                ${g.COMENTARIO ? '<div class="text-muted small mt-1">' + escHtml(g.COMENTARIO) + '</div>' : ''}
            </td>
            <td class="text-center">${tipoIco[g.TIPO_CONTEUDO] || g.TIPO_CONTEUDO}</td>
            <td class="text-center">
                <button class="btn btn-sm fw-bold border-dark ${g.STATUS==='ATIVO'?'btn-success':'btn-outline-secondary'}"
                        onclick="guiaToggleStatus(${g.ID}, this)" style="font-size:0.72rem;padding:2px 10px;">
                    ${g.STATUS==='ATIVO'?'✔ ATIVO':'✖ INATIVO'}
                </button>
            </td>
            <td class="text-center text-muted small">${g.DATA_BR}</td>
            <td class="text-center">
                <button class="btn btn-sm btn-dark fw-bold border-dark me-1" onclick="guiaAbrirViewer()" title="Ver no índice">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-warning fw-bold border-dark me-1" onclick="guiaAbrirModal(${g.ID})" title="Editar">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-danger fw-bold border-dark" onclick="guiaExcluir(${g.ID})" title="Excluir">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>`).join('');
}

function guiaFiltrarTabela(q) {
    const termo = q.toLowerCase();
    const filtrado = _guiaDados.filter(g =>
        (g.TITULO||'').toLowerCase().includes(termo) ||
        (g.INDICE||'').toLowerCase().includes(termo) ||
        (g.COMENTARIO||'').toLowerCase().includes(termo)
    );
    guiaRenderizarTabela(filtrado);
}

async function guiaToggleStatus(id, btn) {
    const fd = new FormData(); fd.append('acao','toggle_status'); fd.append('id',id);
    const r = await fetch('guia_ajax.php', {method:'POST', body:fd});
    const j = await r.json();
    if (j.success) {
        const novo = j.novo_status;
        btn.textContent = novo === 'ATIVO' ? '✔ ATIVO' : '✖ INATIVO';
        btn.className = 'btn btn-sm fw-bold border-dark ' + (novo === 'ATIVO' ? 'btn-success' : 'btn-outline-secondary');
        btn.style.fontSize = '0.72rem'; btn.style.padding = '2px 10px';
    } else { alert(j.msg); }
}

async function guiaExcluir(id) {
    if (!confirm('Tem certeza que deseja excluir este conteúdo?')) return;
    if (!confirm('Esta ação é irreversível. Confirmar exclusão?')) return;
    const fd = new FormData(); fd.append('acao','excluir'); fd.append('id',id);
    const r = await fetch('guia_ajax.php', {method:'POST', body:fd});
    const j = await r.json();
    if (j.success) {
        document.getElementById('guia-row-' + id)?.remove();
        crmToast('Conteúdo excluído com sucesso.', 'success', 3500);
    } else { alert(j.msg); }
}

// ── Modal criar/editar ────────────────────────────────────────
let _guiaEditId = 0;

function guiaAbrirModal(id) {
    _guiaEditId = id;
    const isEdit = id > 0;

    document.getElementById('guiaModalTitulo').textContent = isEdit ? 'Editar Conteúdo' : 'Novo Conteúdo de Treinamento';
    document.getElementById('guia_indice').value = '';
    document.getElementById('guia_titulo').value = '';
    document.getElementById('guia_comentario').value = '';
    document.getElementById('guia_local').value = '';
    document.getElementById('guia_tipo').value = 'TEXTO';
    document.getElementById('guia_arquivo').value = '';
    document.getElementById('guia_editor').innerHTML = '';
    document.getElementById('guia_html_code').value = '';
    guiaTipoChange();

    if (isEdit) {
        const fd = new FormData(); fd.append('acao','get'); fd.append('id',id);
        fetch('guia_ajax.php', {method:'POST', body:fd})
            .then(r => r.json()).then(j => {
                if (!j.success) { alert(j.msg); return; }
                const d = j.data;
                document.getElementById('guia_indice').value = d.INDICE || '';
                document.getElementById('guia_titulo').value = d.TITULO || '';
                document.getElementById('guia_comentario').value = d.COMENTARIO || '';
                document.getElementById('guia_local').value = d.LOCAL_EXIBICAO || '';
                document.getElementById('guia_tipo').value = d.TIPO_CONTEUDO || 'TEXTO';
                guiaTipoChange();
                if (d.TIPO_CONTEUDO === 'TEXTO') {
                    document.getElementById('guia_editor').innerHTML = d.CONTEUDO_RAW || '';
                } else if (d.TIPO_CONTEUDO === 'HTML') {
                    document.getElementById('guia_html_code').value = d.CONTEUDO_RAW || '';
                }
                const arqInfo = document.getElementById('guia_arquivo_atual');
                if (arqInfo) arqInfo.textContent = d.NOME_CONTEUDO ? 'Arquivo atual: ' + d.NOME_CONTEUDO + ' (deixe em branco para manter)' : '';
            });
    }

    new bootstrap.Modal(document.getElementById('modalGuiaForm')).show();
}

function guiaTipoChange() {
    const tipo = document.getElementById('guia_tipo').value;
    document.getElementById('guia_panel_texto').style.display   = tipo === 'TEXTO'  ? '' : 'none';
    document.getElementById('guia_panel_html').style.display    = tipo === 'HTML'   ? '' : 'none';
    document.getElementById('guia_panel_arquivo').style.display = (tipo === 'VIDEO' || tipo === 'IMAGEM') ? '' : 'none';
    const dica = document.getElementById('guia_arquivo_dica');
    if (dica) dica.textContent = tipo === 'VIDEO' ? 'Formatos aceitos: mp4' : 'Formatos aceitos: png, jpg, jpeg, bmp';
}

async function guiaSalvar() {
    const titulo = document.getElementById('guia_titulo').value.trim();
    const tipo   = document.getElementById('guia_tipo').value;
    if (!titulo) { alert('Informe o título.'); document.getElementById('guia_titulo').focus(); return; }

    const fd = new FormData();
    fd.append('acao','salvar');
    fd.append('id', _guiaEditId);
    fd.append('indice', document.getElementById('guia_indice').value.trim());
    fd.append('titulo', titulo);
    fd.append('comentario', document.getElementById('guia_comentario').value.trim());
    fd.append('tipo_conteudo', tipo);
    fd.append('local_exibicao', document.getElementById('guia_local').value.trim());

    if (tipo === 'TEXTO') {
        fd.append('conteudo_texto', document.getElementById('guia_editor').innerHTML);
    } else if (tipo === 'HTML') {
        fd.append('conteudo_texto', document.getElementById('guia_html_code').value);
    } else {
        const arq = document.getElementById('guia_arquivo');
        if (arq.files[0]) fd.append('arquivo', arq.files[0]);
    }

    const btn = document.getElementById('btnGuiaSalvar');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Salvando...';

    try {
        const r = await fetch('guia_ajax.php', {method:'POST', body:fd});
        const j = await r.json();
        if (j.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalGuiaForm'))?.hide();
            _guiaListaCarregada = false;
            guiaCarregarLista(true);
            crmToast('Conteúdo salvo com sucesso!', 'success', 3500);
        } else { alert('Erro: ' + j.msg); }
    } catch(e) { alert('Falha de comunicação.'); }

    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i> Salvar';
}

// Formatação do editor de texto
function guiaFmt(cmd) {
    document.getElementById('guia_editor').focus();
    document.execCommand(cmd, false, null);
}
function guiaFmtCor(cor) {
    if (!cor) return;
    document.getElementById('guia_editor').focus();
    document.execCommand('foreColor', false, cor);
}
<?php endif; ?>

// =============================================================
// GUIA SISTEMA — VIEWER (disponível para todos os usuários)
// =============================================================
function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let _guiaViewerDados = [];

function guiaAbrirViewer(focarId) {
    const fd = new FormData(); fd.append('acao','listar_widget');
    fetch('guia_ajax.php', {method:'POST', body:fd})
        .then(r => r.json()).then(j => {
            _guiaViewerDados = j.data || [];
            guiaViewerRenderizar(_guiaViewerDados);
            new bootstrap.Modal(document.getElementById('modalGuiaVer')).show();
            if (focarId) setTimeout(() => guiaViewerAbrirItem(focarId), 300);
        });
}

// Constrói árvore hierárquica a partir da lista plana ordenada por índice
function guiaBuildTree(dados) {
    const byId = {}, byIdx = {};
    dados.forEach(g => {
        byId[g.ID] = {...g, children: []};
        if (g.INDICE) byIdx[g.INDICE] = byId[g.ID];
    });
    const roots = [];
    dados.forEach(g => {
        const idx = g.INDICE || '';
        if (!idx || !idx.includes('.')) { roots.push(byId[g.ID]); return; }
        const parts = idx.split('.'); parts.pop();
        const parent = byIdx[parts.join('.')];
        parent ? parent.children.push(byId[g.ID]) : roots.push(byId[g.ID]);
    });
    return roots;
}

// Renderiza um nó e seus filhos recursivamente
function guiaRenderNode(node, depth) {
    const hasKids = node.children.length > 0;
    const pad = 12 + depth * 18;
    const cid = 'gc-' + node.ID;
    const badge = node.INDICE
        ? `<span class="badge bg-secondary me-1" style="font-size:0.6rem;min-width:28px;">${escHtml(node.INDICE)}</span>`
        : '';
    let h = `<div>`;
    h += `<div class="guia-idx-item d-flex align-items-center gap-1" id="guia-idx-${node.ID}"
               style="padding:7px 10px 7px ${pad}px;border-left:3px solid transparent;border-bottom:1px solid #eee;font-size:0.82rem;">`;
    if (hasKids) {
        h += `<button class="btn p-0 text-secondary" onclick="guiaToggleKids(event,'${cid}',this)"
                       style="width:18px;height:18px;line-height:1;border:1px solid #ccc;border-radius:3px;background:#fff;flex-shrink:0;font-weight:bold;font-size:0.75rem;">+</button>`;
    } else {
        h += `<span style="width:18px;flex-shrink:0;display:inline-block;"></span>`;
    }
    h += `<span class="fw-semibold flex-grow-1" style="cursor:pointer;" onclick="guiaViewerAbrirItem(${node.ID})">${badge}${escHtml(node.TITULO)}</span>`;
    h += `</div>`;
    if (hasKids) {
        h += `<div id="${cid}" style="display:none;">`;
        node.children.forEach(c => { h += guiaRenderNode(c, depth + 1); });
        h += `</div>`;
    }
    h += `</div>`;
    return h;
}

function guiaToggleKids(e, cid, btn) {
    e.stopPropagation();
    const el = document.getElementById(cid);
    if (!el) return;
    const open = el.style.display !== 'none';
    el.style.display = open ? 'none' : '';
    btn.textContent = open ? '+' : '−';
}

function guiaViewerRenderizar(dados) {
    const cont = document.getElementById('guia-viewer-index');
    if (!dados.length) {
        cont.innerHTML = '<div class="text-muted text-center py-4 small">Nenhum conteúdo disponível.</div>';
        return;
    }
    cont.innerHTML = guiaBuildTree(dados).map(r => guiaRenderNode(r, 0)).join('');
    if (dados.length) guiaViewerAbrirItem(dados[0].ID);
}

function guiaViewerAbrirItem(id) {
    document.querySelectorAll('.guia-idx-item').forEach(el => {
        el.style.borderLeftColor = 'transparent';
        el.style.background = '';
    });
    const ativo = document.getElementById('guia-idx-' + id);
    if (ativo) { ativo.style.borderLeftColor = '#0d6efd'; ativo.style.background = '#f0f4ff'; }

    const painel = document.getElementById('guia-viewer-conteudo');
    painel.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const fd = new FormData(); fd.append('acao','get'); fd.append('id',id);
    fetch('guia_ajax.php', {method:'POST', body:fd})
        .then(r => r.json()).then(j => {
            if (!j.success) { painel.innerHTML = '<p class="text-danger p-4">Erro ao carregar conteúdo.</p>'; return; }
            const d = j.data;
            const badge = d.INDICE ? `<span class="badge bg-dark me-2">${escHtml(d.INDICE)}</span>` : '';
            painel.innerHTML = `
                <div class="border-bottom pb-3 mb-3">
                    <h5 class="fw-bold text-dark mb-1">${badge}${escHtml(d.TITULO)}</h5>
                    ${d.COMENTARIO ? '<div class="text-muted small">' + escHtml(d.COMENTARIO) + '</div>' : ''}
                </div>
                <div style="font-size:0.93rem; line-height:1.7;">${d.CONTEUDO_HTML || '<p class="text-muted text-center py-4">Nenhum conteúdo disponível.</p>'}</div>`;
        });
}

// Filtro: mostra lista plana com todos os resultantes; limpar filtro volta à árvore
function guiaViewerFiltrar(q) {
    if (!q.trim()) { guiaViewerRenderizar(_guiaViewerDados); return; }
    const termo = q.toLowerCase();
    const filtrado = _guiaViewerDados.filter(g =>
        (g.TITULO||'').toLowerCase().includes(termo) ||
        (g.INDICE||'').toLowerCase().includes(termo)
    );
    const cont = document.getElementById('guia-viewer-index');
    if (!filtrado.length) {
        cont.innerHTML = '<div class="text-muted text-center py-4 small">Nenhum resultado encontrado.</div>';
        return;
    }
    cont.innerHTML = filtrado.map(g => {
        const prof = (g.INDICE||'').split('.').length - 1;
        const pad = 12 + prof * 18;
        const badge = g.INDICE ? `<span class="badge bg-secondary me-1" style="font-size:0.6rem;min-width:28px;">${escHtml(g.INDICE)}</span>` : '';
        return `<div class="guia-idx-item d-flex align-items-center gap-1" id="guia-idx-${g.ID}"
                     style="padding:7px 10px 7px ${pad}px;border-left:3px solid transparent;border-bottom:1px solid #eee;font-size:0.82rem;cursor:pointer;"
                     onclick="guiaViewerAbrirItem(${g.ID})">
                    <span style="width:18px;flex-shrink:0;display:inline-block;"></span>
                    <span class="fw-semibold">${badge}${escHtml(g.TITULO)}</span>
                </div>`;
    }).join('');
}
</script>

<!-- ===== MODAL GUIA SISTEMA — VIEWER COM ÍNDICE ===== -->
<div class="modal fade" id="modalGuiaVer" tabindex="-1">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white py-2">
                <h5 class="modal-title fw-bold mb-0"><span style="font-size:1.1em;">👨‍🏫</span> Guia Sistema</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex" style="min-height:500px; overflow:hidden;">
                <!-- PAINEL ESQUERDO: ÍNDICE -->
                <div style="width:300px; min-width:260px; border-right:2px solid #dee2e6; display:flex; flex-direction:column; background:#f8f9fa;">
                    <div class="p-2 border-bottom" style="background:#1a2332;">
                        <input type="text" class="form-control form-control-sm border-0 text-white"
                               style="background:rgba(255,255,255,.12); font-size:0.8rem;"
                               placeholder="🔍 Filtrar por título..."
                               oninput="guiaViewerFiltrar(this.value)">
                    </div>
                    <div id="guia-viewer-index" style="flex:1; overflow-y:auto;"></div>
                </div>
                <!-- PAINEL DIREITO: CONTEÚDO -->
                <div id="guia-viewer-conteudo" style="flex:1; overflow-y:auto; padding:24px 28px;">
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-hand-point-left fa-2x d-block mb-2 opacity-50"></i>
                        Selecione um item no índice ao lado.
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL CRIAR/EDITAR GUIA ===== -->
<?php if ($is_master): ?>
<div class="modal fade" id="modalGuiaForm" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold" id="guiaModalTitulo">Novo Conteúdo de Treinamento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="fw-bold text-dark mb-1">Número do Índice <span class="text-muted fw-normal">(hierarquia)</span></label>
                        <input type="text" id="guia_indice" class="form-control border-dark fw-bold text-center" placeholder="Ex: 2.1" maxlength="20" style="letter-spacing:1px;">
                        <small class="text-muted">1 / 2 / 2.1 / 2.1.1 / 3</small>
                    </div>
                    <div class="col-md-9">
                        <label class="fw-bold text-dark mb-1">Título <span class="text-danger">*</span></label>
                        <input type="text" id="guia_titulo" class="form-control border-dark" placeholder="Ex: Como usar o módulo de relatórios" maxlength="200">
                    </div>
                    <div class="col-12">
                        <label class="fw-bold text-dark mb-1">Comentário / Descrição</label>
                        <input type="text" id="guia_comentario" class="form-control border-secondary" placeholder="Breve descrição do conteúdo" maxlength="300">
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-dark mb-1">Tipo de Conteúdo</label>
                        <select id="guia_tipo" class="form-select border-dark fw-bold" onchange="guiaTipoChange()">
                            <option value="TEXTO">📝 Bloco de Notas (texto)</option>
                            <option value="HTML">🖥️ Código HTML (apresentação)</option>
                            <option value="VIDEO">🎬 Vídeo Curto (mp4)</option>
                            <option value="IMAGEM">🖼️ Imagem / Apresentação</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="fw-bold text-dark mb-1">Local de Exibição <span class="text-muted fw-normal">(opcional)</span></label>
                        <input type="text" id="guia_local" class="form-control border-secondary" placeholder="Ex: dashboard, relatórios, v8...">
                    </div>

                    <!-- Painel TEXTO -->
                    <div class="col-12" id="guia_panel_texto">
                        <label class="fw-bold text-dark mb-1">Conteúdo</label>
                        <div class="border border-secondary rounded">
                            <div class="d-flex gap-1 flex-wrap p-2 border-bottom border-secondary" style="background:#f8f9fa;">
                                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" onclick="guiaFmt('bold')"><b>B</b></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary fst-italic" onclick="guiaFmt('italic')"><i>I</i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="guiaFmt('underline')"><u>U</u></button>
                                <span class="border-start border-secondary mx-1"></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="guiaFmt('insertUnorderedList')"><i class="fas fa-list-ul"></i></button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="guiaFmt('insertOrderedList')"><i class="fas fa-list-ol"></i></button>
                                <span class="border-start border-secondary mx-1"></span>
                                <select class="form-select form-select-sm border-secondary" style="width:auto;" onchange="guiaFmt('formatBlock'); this.value='';">
                                    <option value="">Parágrafo</option>
                                    <option value="h3">Título</option>
                                    <option value="h5">Subtítulo</option>
                                    <option value="p">Normal</option>
                                </select>
                                <select class="form-select form-select-sm border-secondary" style="width:auto;" onchange="guiaFmtCor(this.value); this.value='';">
                                    <option value="">Cor</option>
                                    <option value="#dc3545">Vermelho</option>
                                    <option value="#198754">Verde</option>
                                    <option value="#0d6efd">Azul</option>
                                    <option value="#e67e22">Laranja</option>
                                    <option value="#333333">Preto</option>
                                </select>
                            </div>
                            <div id="guia_editor" contenteditable="true"
                                 style="min-height:200px;max-height:400px;overflow-y:auto;padding:12px 14px;outline:none;font-size:0.92rem;"
                                 placeholder="Digite o conteúdo do guia aqui..."></div>
                        </div>
                    </div>

                    <!-- Painel HTML -->
                    <div class="col-12" id="guia_panel_html" style="display:none;">
                        <label class="fw-bold text-dark mb-1">Código HTML <span class="text-muted fw-normal small">(ex: embed do Canva, Google Slides...)</span></label>
                        <textarea id="guia_html_code" class="form-control font-monospace border-dark"
                                  rows="10" placeholder="Cole aqui o código HTML da sua apresentação..."
                                  style="font-size:0.78rem; resize:vertical;"></textarea>
                        <small class="text-muted">O conteúdo será exibido em um iframe. Cole o código completo gerado pela ferramenta de design.</small>
                    </div>

                    <!-- Painel ARQUIVO (VIDEO ou IMAGEM) -->
                    <div class="col-12" id="guia_panel_arquivo" style="display:none;">
                        <label class="fw-bold text-dark mb-1">Arquivo</label>
                        <input type="file" id="guia_arquivo" class="form-control border-dark">
                        <small id="guia_arquivo_dica" class="text-muted">Formatos aceitos: mp4</small>
                        <div id="guia_arquivo_atual" class="text-info small mt-1"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-danger fw-bold border-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary fw-bold border-dark" id="btnGuiaSalvar" onclick="guiaSalvar()">
                    <i class="fas fa-save me-1"></i> Salvar
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if(file_exists($caminho_footer)) include $caminho_footer;
?>
