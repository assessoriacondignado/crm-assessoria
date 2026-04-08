<?php
// ATIVAR ERROS TEMPORARIAMENTE PARA DEBUG
ini_set('display_errors', 0);
error_reporting(0);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

// Bloqueio de Segurança
if (!podeAcessarMenu($pdo, 'SUBMENU_ANOTACOES_GERAIS')) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>Acesso Negado. Você não tem permissão para visualizar esta página.</div></div></body></html>";
    exit;
}

// Lógica de exclusão
if (isset($_GET['delete_anotacao'])) {
    $stmt = $pdo->prepare("DELETE FROM CONFIG_ANOTACOES_GERAIS WHERE ID = ?");
    $stmt->execute([$_GET['delete_anotacao']]);
    echo "<script>window.location.href='index.php?msg=sucesso';</script>";
    exit;
}

if (isset($_GET['delete_acesso'])) {
    $stmt = $pdo->prepare("DELETE FROM CONFIG_ACESSOS WHERE ID = ?");
    $stmt->execute([$_GET['delete_acesso']]);
    echo "<script>window.location.href='index.php?msg=sucesso';</script>";
    exit;
}

// Consultas Seguras
$anotacoes = [];
try {
    $stmtAnot = $pdo->query("SELECT * FROM CONFIG_ANOTACOES_GERAIS ORDER BY DATA_ATUALIZACAO DESC");
    if ($stmtAnot) {
        $anotacoes = $stmtAnot->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    echo "<div class='alert alert-danger m-4'><strong>Erro Banco de Dados (Anotações):</strong> " . $e->getMessage() . "</div>";
}

$acessos = [];
try {
    $stmtAcesso = $pdo->query("SELECT * FROM CONFIG_ACESSOS ORDER BY DATA_ATUALIZACAO DESC");
    if ($stmtAcesso) {
        $acessos = $stmtAcesso->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    echo "<div class='alert alert-danger m-4'><strong>Erro Banco de Dados (Acessos):</strong> " . $e->getMessage() . "</div>";
}
?>

<style>
    /* Ajustes para o CKEditor ficar com altura ideal */
    .ck-editor__editable_inline {
        min-height: 250px;
    }
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0 text-secondary"><i class="fas fa-book"></i> Central de Anotações e Acessos</h4>
    </div>

    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active fw-bold text-dark" id="anotacoes-tab" data-bs-toggle="tab" data-bs-target="#anotacoes" type="button" role="tab" aria-controls="anotacoes" aria-selected="true">
                <i class="fas fa-sticky-note text-warning"></i> Anotações Gerais
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link fw-bold text-dark" id="acessos-tab" data-bs-toggle="tab" data-bs-target="#acessos" type="button" role="tab" aria-controls="acessos" aria-selected="false">
                <i class="fas fa-key text-primary"></i> Senhas e Acessos
            </button>
        </li>
    </ul>

    <div class="tab-content border border-top-0 p-4 bg-white rounded-bottom shadow-sm" id="myTabContent">
        
        <div class="tab-pane fade show active" id="anotacoes" role="tabpanel" aria-labelledby="anotacoes-tab">
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
                        <tr>
                            <th class="text-start">Assunto / Título</th>
                            <th>Última Atualização</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="listaAnotacoes" class="bg-white">
                        <?php if(!empty($anotacoes)): foreach($anotacoes as $anot): ?>
                        <tr class="border-bottom border-dark">
                            <td class="fw-bold text-dark text-start"><?= htmlspecialchars($anot['ASSUNTO']) ?></td>
                            <td class="text-muted small"><?= date('d/m/Y H:i', strtotime($anot['DATA_ATUALIZACAO'])) ?></td>
                            <td>
                                <div id="anot_html_<?= $anot['ID'] ?>" class="d-none"><?= $anot['ANOTACAO'] ?></div>
                                
                                <button class="btn btn-sm btn-dark fw-bold border-dark shadow-sm btn-view-anot" data-id="<?= $anot['ID'] ?>" data-assunto="<?= htmlspecialchars($anot['ASSUNTO']) ?>"><i class="fas fa-eye"></i> Ler</button>
                                <a href="?delete_anotacao=<?= $anot['ID'] ?>" class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="return confirm('Deseja excluir esta anotação?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted fw-bold">Nenhuma anotação encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-pane fade" id="acessos" role="tabpanel" aria-labelledby="acessos-tab">
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
                        <tr>
                            <th>Tipo</th>
                            <th class="text-start">Origem (Sistema/Banco)</th>
                            <th>Usuário</th>
                            <th>Senha</th>
                            <th>Atualização</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody id="listaAcessos" class="bg-white">
                        <?php if(!empty($acessos)): foreach($acessos as $acesso): ?>
                        <tr class="border-bottom border-dark">
                            <td><span class="badge bg-<?= $acesso['TIPO'] == 'BANCOS' ? 'success' : 'info text-dark' ?> border border-dark"><?= $acesso['TIPO'] ?></span></td>
                            <td class="fw-bold text-dark text-start"><?= htmlspecialchars($acesso['ORIGEM']) ?></td>
                            <td class="fw-medium text-primary"><?= htmlspecialchars($acesso['USUARIO']) ?></td>
                            <td>
                                <input type="password" value="<?= htmlspecialchars($acesso['SENHA']) ?>" class="form-control form-control-sm border-0 bg-transparent p-0 text-center fw-bold" readonly style="width:120px; display:inline-block;">
                                <button class="btn btn-sm text-secondary toggle-senha border-0" title="Mostrar/Ocultar Senha"><i class="fas fa-eye"></i></button>
                            </td>
                            <td class="text-muted small"><?= date('d/m/Y', strtotime($acesso['DATA_ATUALIZACAO'])) ?></td>
                            <td>
                                <div id="acesso_uso_<?= $acesso['ID'] ?>" class="d-none"><?= nl2br(htmlspecialchars($acesso['USO'])) ?></div>
                                <div id="acesso_obs_<?= $acesso['ID'] ?>" class="d-none"><?= nl2br(htmlspecialchars($acesso['OBSERVACAO'])) ?></div>

                                <button class="btn btn-sm btn-dark fw-bold border-dark shadow-sm btn-view-acesso" data-id="<?= $acesso['ID'] ?>" data-origem="<?= htmlspecialchars($acesso['ORIGEM']) ?>"><i class="fas fa-info-circle"></i> Info</button>
                                <a href="?delete_acesso=<?= $acesso['ID'] ?>" class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="return confirm('Deseja excluir este acesso?')"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted fw-bold">Nenhum acesso cadastrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<div class="modal fade" id="modalAnotacao" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark">
            <form action="ajax_anotacao.php" method="POST">
                <input type="hidden" name="acao" value="salvar_anotacao">
                <div class="modal-header bg-dark text-white border-bottom border-dark">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-warning me-2"></i> Nova Anotação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <div class="mb-3">
                        <label class="fw-bold text-dark mb-1">Assunto / Título</label>
                        <input type="text" name="assunto" class="form-control border-dark" placeholder="Ex: Regras de Digitação Banco BMG" required>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold text-dark mb-1">Anotação (Use os botões para formatar)</label>
                        <textarea name="anotacao" id="editor_anotacao" class="form-control"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top border-secondary">
                    <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold border-dark shadow-sm"><i class="fas fa-save me-1"></i> Salvar Anotação</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAcesso" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content shadow-lg border-dark">
            <form action="ajax_anotacao.php" method="POST">
                <input type="hidden" name="acao" value="salvar_acesso">
                <div class="modal-header bg-dark text-white border-bottom border-dark">
                    <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-key text-info me-2"></i> Novo Acesso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body bg-light p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="fw-bold text-dark mb-1">Tipo</label>
                            <select name="tipo" class="form-select border-dark fw-bold">
                                <option value="SISTEMAS">Sistemas (Portais, etc)</option>
                                <option value="BANCOS">Bancos (Promotoras)</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="fw-bold text-dark mb-1">Origem (Nome do Banco ou Sistema)</label>
                            <input type="text" name="origem" class="form-control border-dark" maxlength="100" placeholder="Ex: Banco Itaú" required>
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-dark mb-1">Usuário / Login</label>
                            <input type="text" name="usuario" class="form-control border-dark" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="fw-bold text-dark mb-1">Senha</label>
                            <input type="text" name="senha" class="form-control border-dark" maxlength="100">
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold text-dark mb-1">Uso (Qual a finalidade deste acesso?)</label>
                            <textarea name="uso" class="form-control border-secondary" rows="2"></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="fw-bold text-dark mb-1">Observações Gerais</label>
                            <textarea name="observacao" class="form-control border-secondary" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-top border-secondary">
                    <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary fw-bold border-dark shadow-sm"><i class="fas fa-save me-1"></i> Salvar Acesso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modalVerAnotacao" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-book-open text-warning me-2"></i> Lendo: <span id="lerAnotacaoAssunto" class="text-info"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-white text-dark" id="lerAnotacaoConteudo" style="min-height: 300px; font-size: 1.05rem; line-height: 1.6;">
            </div>
            <div class="modal-footer bg-light border-top border-secondary">
                <button type="button" class="btn btn-secondary fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Fechar Leitura</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalVerAcesso" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content shadow-lg border-dark">
            <div class="modal-header bg-dark text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-info-circle text-info me-2"></i> Detalhes: <span id="lerAcessoOrigem" class="text-warning"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="mb-3 p-3 bg-white border border-secondary rounded shadow-sm">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Finalidade / Uso</h6>
                    <div id="lerAcessoUso" class="text-dark"></div>
                </div>
                <div class="p-3 bg-white border border-secondary rounded shadow-sm">
                    <h6 class="fw-bold text-primary border-bottom pb-2">Observações</h6>
                    <div id="lerAcessoObs" class="text-dark"></div>
                </div>
            </div>
            <div class="modal-footer bg-light border-top border-secondary">
                <button type="button" class="btn btn-secondary fw-bold border-dark shadow-sm" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>

<script>
    // Inicializar o Editor de Texto na Modal
    ClassicEditor
        .create(document.querySelector('#editor_anotacao'), {
            toolbar: [ 'heading', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList', 'blockQuote', '|', 'undo', 'redo' ]
        })
        .catch(error => {
            console.error(error);
        });

    // Filtro Simples via JS - Anotações
    document.getElementById('filtroAnotacoes').addEventListener('keyup', function() {
        let text = this.value.toLowerCase();
        let rows = document.querySelectorAll('#listaAnotacoes tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(text) ? '' : 'none';
        });
    });

    // Filtro Simples via JS - Acessos
    document.getElementById('filtroAcessos').addEventListener('keyup', function() {
        let text = this.value.toLowerCase();
        let rows = document.querySelectorAll('#listaAcessos tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(text) ? '' : 'none';
        });
    });

    // Revelar Senha nas linhas da tabela
    document.querySelectorAll('.toggle-senha').forEach(btn => {
        btn.addEventListener('click', function() {
            let input = this.previousElementSibling;
            let icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                icon.classList.add('text-danger');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                icon.classList.remove('text-danger');
            }
        });
    });

    // Lógica para Abrir e Ler a Anotação
    document.querySelectorAll('.btn-view-anot').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const assunto = this.getAttribute('data-assunto');
            const conteudoHTML = document.getElementById('anot_html_' + id).innerHTML;

            document.getElementById('lerAnotacaoAssunto').innerText = assunto;
            document.getElementById('lerAnotacaoConteudo').innerHTML = conteudoHTML;

            new bootstrap.Modal(document.getElementById('modalVerAnotacao')).show();
        });
    });

    // Lógica para Abrir e Ler Detalhes do Acesso
    document.querySelectorAll('.btn-view-acesso').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const origem = this.getAttribute('data-origem');
            const uso = document.getElementById('acesso_uso_' + id).innerHTML;
            const obs = document.getElementById('acesso_obs_' + id).innerHTML;

            document.getElementById('lerAcessoOrigem').innerText = origem;
            document.getElementById('lerAcessoUso').innerHTML = uso ? uso : '<em class="text-muted">Nenhum detalhe de uso informado.</em>';
            document.getElementById('lerAcessoObs').innerHTML = obs ? obs : '<em class="text-muted">Nenhuma observação informada.</em>';

            new bootstrap.Modal(document.getElementById('modalVerAcesso')).show();
        });
    });
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; 
if(file_exists($caminho_footer)) include $caminho_footer; 
?>