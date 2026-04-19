<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!file_exists($caminho_conexao)) { die("Erro de conexão."); }
include $caminho_conexao;

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

if (!verificaPermissao($pdo, 'SUBMENU_STATUS', 'TELA')) {
    include $caminho_header;
    die("<div class='container mt-5'><div class='alert alert-danger text-center shadow-lg border-dark p-4 rounded-3'><h4 class='fw-bold mb-3'><i class='fas fa-ban'></i> Acesso Negado</h4></div></div>");
}

$erro_banco = null; $mensagem_alerta = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_crud'])) {
    $acao = $_POST['acao_crud'];
    try {
        $pdo->beginTransaction();

        if ($acao === 'salvar_status') {
            $id = $_POST['id_status'] ?? '';
            $nome = trim($_POST['nome_status']);
            $tipo = trim($_POST['tipo_contato']);
            $qualificacao = !empty($_POST['id_qualificacao']) ? $_POST['id_qualificacao'] : null;
            $marcacao = $_POST['marcacao'];
            $campanha = !empty($_POST['id_campanha']) ? $_POST['id_campanha'] : null;

            // TRATAMENTO DA MÚLTIPLA SELEÇÃO DE EMPRESAS
            $cnpjs_post = $_POST['cnpj_empresa'] ?? [];
            if (!is_array($cnpjs_post)) { $cnpjs_post = [$cnpjs_post]; }
            $cnpjs_post = array_filter($cnpjs_post, function($v) { return trim($v) !== ''; });
            $cnpj_final = !empty($cnpjs_post) ? implode(',', $cnpjs_post) : null;

            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO (NOME_STATUS, TIPO_CONTATO, ID_QUALIFICACAO, CNPJ_EMPRESA, ID_CAMPANHA, MARCACAO) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nome, $tipo, $qualificacao, $cnpj_final, $campanha, $marcacao]);
            } else {
                $stmt = $pdo->prepare("UPDATE BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO SET NOME_STATUS=?, TIPO_CONTATO=?, ID_QUALIFICACAO=?, CNPJ_EMPRESA=?, ID_CAMPANHA=?, MARCACAO=? WHERE ID=?");
                $stmt->execute([$nome, $tipo, $qualificacao, $cnpj_final, $campanha, $marcacao, $id]);
            }
        } elseif ($acao === 'excluir_status') {
            $stmt = $pdo->prepare("DELETE FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO WHERE ID = ?");
            $stmt->execute([$_POST['id_status']]);
        } elseif ($acao === 'salvar_qualificacao') {
            $id = $_POST['id_qualificacao'] ?? '';
            $nome = trim($_POST['nome_qualificacao']);
            $tipo = $_POST['tipo_qualificacao'];
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE (NOME_QUALIFICACAO, TIPO) VALUES (?, ?)");
                $stmt->execute([$nome, $tipo]);
            } else {
                $stmt = $pdo->prepare("UPDATE BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE SET NOME_QUALIFICACAO=?, TIPO=? WHERE ID=?");
                $stmt->execute([$nome, $tipo, $id]);
            }
        } elseif ($acao === 'excluir_qualificacao') {
            $stmt = $pdo->prepare("DELETE FROM BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE WHERE ID = ?");
            $stmt->execute([$_POST['id_qualificacao']]);
        }

        $pdo->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?msg=sucesso");
        exit;
    } catch (PDOException $e) { $pdo->rollBack(); $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage(); }
}

if (isset($_GET['msg']) && $_GET['msg'] == 'sucesso') { $mensagem_alerta = "<div class='alert alert-success fw-bold shadow-sm'><i class='fas fa-check-circle'></i> Operação realizada com sucesso!</div>"; }

try {
    $lista_qualificacoes = $pdo->query("SELECT * FROM BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE ORDER BY TIPO ASC, NOME_QUALIFICACAO ASC")->fetchAll(PDO::FETCH_ASSOC);

    $sqlStatus = "SELECT s.*, q.NOME_QUALIFICACAO, c.NOME_CAMPANHA FROM BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s LEFT JOIN BANCO_DE_DADOS_CAMPANHA_QUALIFICACAO_TELEFONE q ON s.ID_QUALIFICACAO = q.ID LEFT JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c ON s.ID_CAMPANHA = c.ID ORDER BY s.NOME_STATUS ASC";
    $lista_status = $pdo->query($sqlStatus)->fetchAll(PDO::FETCH_ASSOC);

    $lista_empresas = $pdo->query("SELECT CNPJ, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // TRAZENDO NOME DA EMPRESA + NOME DA CAMPANHA
    $stmtCamp = $pdo->query("SELECT c.ID, c.NOME_CAMPANHA, c.CNPJ_EMPRESA, e.NOME_CADASTRO as NOME_EMPRESA FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c LEFT JOIN CLIENTE_EMPRESAS e ON c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci = e.CNPJ COLLATE utf8mb4_unicode_ci ORDER BY e.NOME_CADASTRO ASC, c.NOME_CAMPANHA ASC");
    $lista_campanhas = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage(); }

include $caminho_header;
?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-primary fw-bold"><i class="fas fa-tags me-2"></i> Configuração de Status</h2>
        <p class="text-muted">Gerencie os status de contato e as qualificações de telefone para o módulo de Campanhas.</p>
    </div>
</div>
<?= $mensagem_alerta ?>
<?php if ($erro_banco): ?><div class="alert alert-danger shadow-lg border-dark fw-bold"><i class="fas fa-database me-2"></i> <?= $erro_banco ?></div><?php endif; ?>

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card border-dark shadow-sm">
            <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-phone-volume text-warning me-2"></i> Qualificações</h5>
                <button class="btn btn-sm btn-warning text-dark fw-bold border-dark shadow-sm" onclick="abrirModalQualificacao()"><i class="fas fa-plus"></i> Nova</button>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0 text-center bg-white">
                    <thead class="table-light border-dark small text-uppercase"><tr><th class="text-start ps-3">Nome</th><th>Tipo</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($lista_qualificacoes as $q): ?>
                            <tr class="border-bottom border-dark">
                                <td class="text-start ps-3 fw-bold text-dark"><?= htmlspecialchars($q['NOME_QUALIFICACAO']) ?></td>
                                <td><span class="badge <?= $q['TIPO'] == 'PRINCIPAL' ? 'bg-success' : 'bg-secondary' ?> border border-dark rounded-1"><?= $q['TIPO'] ?></span></td>
                                <td>
                                    <button class="btn btn-sm btn-dark" onclick='editarQualificacao(<?= json_encode($q) ?>)'><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?');"><input type="hidden" name="acao_crud" value="excluir_qualificacao"><input type="hidden" name="id_qualificacao" value="<?= $q['ID'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-dark shadow-sm">
            <div class="card-header bg-dark text-white fw-bold py-3 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-clipboard-list text-info me-2"></i> Relação de Status de Contato</h5>
                <button class="btn btn-sm btn-info text-dark fw-bold border-dark shadow-sm" onclick="abrirModalStatus()"><i class="fas fa-plus"></i> Novo Status</button>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0 text-center bg-white">
                    <thead class="table-light border-dark small text-uppercase"><tr><th class="text-start ps-3">Nome do Status</th><th>Marcação</th><th>Visibilidade (Empresas)</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($lista_status as $s): ?>
                            <tr class="border-bottom border-dark">
                                <td class="text-start ps-3 fw-bold text-dark"><?= htmlspecialchars($s['NOME_STATUS']) ?></td>
                                <td>
                                    <?php
                                    $badge_class = 'bg-secondary'; $icon = '<i class="fas fa-ban me-1"></i>';
                                    if ($s['MARCACAO'] == 'COM RETORNO') { $badge_class = 'bg-danger'; $icon = '<i class="fas fa-calendar-alt me-1"></i>'; } 
                                    elseif ($s['MARCACAO'] == 'FINALIZAR ATENDIMENTO') { $badge_class = 'bg-primary'; $icon = '<i class="fas fa-check-double me-1"></i>'; }
                                    ?>
                                    <span class="badge <?= $badge_class ?> border border-dark rounded-1 shadow-sm"><?= $icon ?> <?= $s['MARCACAO'] ?></span>
                                </td>
                                <td class="small text-muted text-start" style="max-width: 200px;">
                                    <?php 
                                    if(empty($s['CNPJ_EMPRESA'])) { echo '<span class="text-success fw-bold">GLOBAL (Todas)</span>'; }
                                    else {
                                        $cnpjs_st = explode(',', $s['CNPJ_EMPRESA']);
                                        $nomes_emps = [];
                                        foreach($cnpjs_st as $c_st) {
                                            foreach($lista_empresas as $e_list) { if($e_list['CNPJ'] == $c_st) { $nomes_emps[] = $e_list['NOME_CADASTRO']; break; } }
                                        }
                                        echo implode('<br>', $nomes_emps);
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-dark" onclick='editarStatus(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Excluir?');"><input type="hidden" name="acao_crud" value="excluir_status"><input type="hidden" name="id_status" value="<?= $s['ID'] ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button></form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalQualificacao" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content border-dark shadow-lg">
            <input type="hidden" name="acao_crud" value="salvar_qualificacao"><input type="hidden" name="id_qualificacao" id="form_id_qual">
            <div class="modal-header bg-dark text-white border-dark"><h5 class="modal-title fw-bold text-uppercase">Qualificação</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body bg-light">
                <div class="mb-3"><label class="fw-bold small mb-1">Nome</label><input type="text" name="nome_qualificacao" id="form_nome_qual" class="form-control border-dark text-uppercase fw-bold" required></div>
                <div class="mb-2"><label class="fw-bold small mb-1">Tipo</label><select name="tipo_qualificacao" id="form_tipo_qual" class="form-select border-dark" required><option value="PRINCIPAL">Principal</option><option value="SECUNDARIO">Secundário</option></select></div>
            </div>
            <div class="modal-footer"><button type="submit" class="btn btn-warning text-dark border-dark fw-bold">Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalStatus" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content border-dark shadow-lg">
            <input type="hidden" name="acao_crud" value="salvar_status">
            <input type="hidden" name="id_status" id="form_id_status">
            <div class="modal-header bg-dark text-white border-dark"><h5 class="modal-title fw-bold text-uppercase">Configurar Status</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body bg-light row g-3">
                <div class="col-md-6"><label class="fw-bold small mb-1">Nome do Status</label><input type="text" name="nome_status" id="form_nome_status" class="form-control border-dark text-uppercase fw-bold" required></div>
                <div class="col-md-6"><label class="fw-bold small mb-1 text-primary">Marcação</label><select name="marcacao" id="form_marcacao_status" class="form-select border-primary fw-bold text-dark" required><option value="SEM RETORNO">Sem Retorno</option><option value="FINALIZAR ATENDIMENTO">Finalizar Atendimento</option><option value="COM RETORNO">Com Retorno</option></select></div>
                <div class="col-md-6 mt-3"><label class="fw-bold small mb-1">Tipo de Contato</label><input type="text" name="tipo_contato" id="form_tipo_status" class="form-control border-dark"></div>
                <div class="col-md-6 mt-3"><label class="fw-bold small mb-1 text-success">Auto-Qualificar Telefone</label><select name="id_qualificacao" id="form_id_qual_status" class="form-select border-success"><option value="">-- Nenhuma --</option><?php foreach($lista_qualificacoes as $q): ?><option value="<?= $q['ID'] ?>"><?= htmlspecialchars($q['NOME_QUALIFICACAO']) ?></option><?php endforeach; ?></select></div>
                
                <div class="col-12 mt-4 pt-3 border-top border-dark"><h6 class="fw-bold text-danger mb-3"><i class="fas fa-lock me-1"></i> Restrições de Visibilidade</h6></div>
                <div class="col-md-6">
                    <label class="fw-bold small mb-1">Restringir a Empresa(s): <small class="text-muted">(Segure CTRL)</small></label>
                    <select name="cnpj_empresa[]" id="form_empresa_status" class="form-select border-dark" multiple style="height: 120px;">
                        <option value="" class="fw-bold text-success border-bottom pb-1">-- GLOBAL (Livre) --</option>
                        <?php foreach($lista_empresas as $e): ?>
                            <option value="<?= $e['CNPJ'] ?>"><?= htmlspecialchars($e['NOME_CADASTRO']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="fw-bold small mb-1">Restringir a uma Campanha:</label>
                    <select name="id_campanha" id="form_campanha_status" class="form-select border-dark">
                        <option value="">-- GLOBAL (Livre) --</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-dark bg-white"><button type="submit" class="btn btn-info text-dark border-dark fw-bold">Gravar Status</button></div>
        </form>
    </div>
</div>

<script>
let modalQual, modalStat;
const todasCampanhasStatus = <?= json_encode($lista_campanhas) ?>; 

document.addEventListener("DOMContentLoaded", function() {
    modalQual = new bootstrap.Modal(document.getElementById('modalQualificacao'));
    modalStat = new bootstrap.Modal(document.getElementById('modalStatus'));
    
    // ✨ Detecta seleção de múltiplas empresas e filtra
    document.getElementById('form_empresa_status').addEventListener('change', function() {
        let selecionados = Array.from(this.selectedOptions).map(opt => opt.value);
        filtrarCampanhasPorEmpresa(selecionados);
    });
});

function filtrarCampanhasPorEmpresa(cnpjs_selecionados) {
    const selectCampanha = document.getElementById('form_campanha_status');
    const valorAtual = selectCampanha.value; 
    
    selectCampanha.innerHTML = '<option value="">-- GLOBAL (Livre) --</option>';
    
    todasCampanhasStatus.forEach(camp => {
        let show = false;
        if (!cnpjs_selecionados || cnpjs_selecionados.length === 0 || cnpjs_selecionados.includes('')) {
            show = true; 
        } else if (camp.CNPJ_EMPRESA === null || camp.CNPJ_EMPRESA === '') {
            show = true; 
        } else if (cnpjs_selecionados.includes(camp.CNPJ_EMPRESA)) {
            show = true;
        }

        if (show) {
            let opt = document.createElement('option');
            opt.value = camp.ID;
            // Mostrando "EMPRESA / CAMPANHA"
            opt.text = (camp.NOME_EMPRESA ? camp.NOME_EMPRESA : 'GLOBAL') + ' / ' + camp.NOME_CAMPANHA;
            selectCampanha.add(opt);
        }
    });
    
    selectCampanha.value = valorAtual;
    if(selectCampanha.selectedIndex === -1) selectCampanha.value = '';
}

function abrirModalQualificacao() {
    document.getElementById('form_id_qual').value = '';
    document.getElementById('form_nome_qual').value = '';
    modalQual.show();
}

function editarQualificacao(dados) {
    document.getElementById('form_id_qual').value = dados.ID;
    document.getElementById('form_nome_qual').value = dados.NOME_QUALIFICACAO;
    document.getElementById('form_tipo_qual').value = dados.TIPO;
    modalQual.show();
}

function abrirModalStatus() {
    document.getElementById('form_id_status').value = '';
    document.getElementById('form_nome_status').value = '';
    document.getElementById('form_marcacao_status').value = 'SEM RETORNO';
    let selEmp = document.getElementById('form_empresa_status');
    for(let i=0; i<selEmp.options.length; i++) selEmp.options[i].selected = false;
    selEmp.options[0].selected = true; // Seleciona GLOBAL por padrão
    filtrarCampanhasPorEmpresa(['']); 
    document.getElementById('form_campanha_status').value = '';
    modalStat.show();
}

function editarStatus(dados) {
    document.getElementById('form_id_status').value = dados.ID;
    document.getElementById('form_nome_status').value = dados.NOME_STATUS;
    document.getElementById('form_marcacao_status').value = dados.MARCACAO;
    document.getElementById('form_tipo_status').value = dados.TIPO_CONTATO || '';
    document.getElementById('form_id_qual_status').value = dados.ID_QUALIFICACAO || '';
    
    let cnpjs = dados.CNPJ_EMPRESA ? dados.CNPJ_EMPRESA.split(',') : [];
    let selEmp = document.getElementById('form_empresa_status');
    for(let i=0; i<selEmp.options.length; i++) {
        if(cnpjs.includes(selEmp.options[i].value)) { selEmp.options[i].selected = true; }
        else { selEmp.options[i].selected = false; }
    }
    
    filtrarCampanhasPorEmpresa(cnpjs);
    document.getElementById('form_campanha_status').value = dados.ID_CAMPANHA || '';
    modalStat.show();
}
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>