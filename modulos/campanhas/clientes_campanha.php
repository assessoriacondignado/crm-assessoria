<?php
session_start();
$caminho_conexao    = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header     = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';

if (!file_exists($caminho_conexao)) die("Erro Crítico: conexão não encontrada.");
include $caminho_conexao;
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;
if (!isset($_SESSION['usuario_cpf'])) { header("Location: /login.php"); exit; }

$id_campanha = (int)($_GET['id_campanha'] ?? 0);
if (!$id_campanha) { die("Campanha inválida."); }

$stmtCamp = $pdo->prepare("SELECT c.*, e.NOME_CADASTRO as NOME_EMPRESA
    FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
    LEFT JOIN CLIENTE_EMPRESAS e ON c.CNPJ_EMPRESA COLLATE utf8mb4_unicode_ci = e.CNPJ COLLATE utf8mb4_unicode_ci
    WHERE c.ID = ?");
$stmtCamp->execute([$id_campanha]);
$campanha = $stmtCamp->fetch(PDO::FETCH_ASSOC);
if (!$campanha) { die("Campanha não encontrada."); }

$somente_restantes  = !empty($_GET['restantes']);
$somente_contatados = !empty($_GET['contatados']);
$q       = trim($_GET['q'] ?? '');
$pagina  = max(1, (int)($_GET['p'] ?? 1));
$por_pag = 100;
$offset  = ($pagina - 1) * $por_pag;

$where  = "WHERE c.ID_CAMPANHA = ?";
$params = [$id_campanha];

if ($q !== '') {
    $qL = preg_replace('/\D/', '', $q);
    if ($qL !== '') {
        $where .= " AND c.CPF_CLIENTE LIKE ?";
        $params[] = "%{$qL}%";
    } else {
        $where .= " AND (SELECT nome FROM dados_cadastrais WHERE cpf = c.CPF_CLIENTE LIMIT 1) LIKE ?";
        $params[] = "%{$q}%";
    }
}

$existe_contato = "EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO)";
if ($somente_restantes)  { $where .= " AND NOT {$existe_contato}"; }
if ($somente_contatados) { $where .= " AND {$existe_contato}"; }

$stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c {$where}");
$stmtCnt->execute($params);
$total = (int)$stmtCnt->fetchColumn();
$total_pags = max(1, ceil($total / $por_pag));

$sql = "SELECT c.CPF_CLIENTE, c.DATA_INCLUSAO, c.PRIORIDADE,
    (SELECT dc.nome FROM dados_cadastrais dc WHERE dc.cpf = c.CPF_CLIENTE LIMIT 1) AS NOME,
    (SELECT t.telefone_cel FROM telefones t WHERE t.cpf = c.CPF_CLIENTE ORDER BY t.id ASC LIMIT 1) AS TELEFONE,
    (SELECT MAX(r.DATA_REGISTRO) FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
     WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO) AS ULTIMO_CONTATO,
    (SELECT s.NOME_STATUS FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
     JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON s.ID = r.ID_STATUS_CONTATO
     WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO
     ORDER BY r.DATA_REGISTRO DESC LIMIT 1) AS ULTIMO_STATUS
FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c
{$where}
ORDER BY c.DATA_INCLUSAO DESC
LIMIT {$por_pag} OFFSET {$offset}";
$stmtCli = $pdo->prepare($sql);
$stmtCli->execute($params);
$clientes = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

// Totais para os badges
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = ?");
$stmtTotal->execute([$id_campanha]);
$badge_total = (int)$stmtTotal->fetchColumn();

$stmtRest = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA c WHERE ID_CAMPANHA = ? AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = c.CPF_CLIENTE AND r.DATA_REGISTRO >= c.DATA_INCLUSAO)");
$stmtRest->execute([$id_campanha]);
$badge_restantes = (int)$stmtRest->fetchColumn();

// URL base para paginação
function urlPag($pagina, $extra = []) {
    $p = array_merge($_GET, ['p' => $pagina], $extra);
    return '?' . http_build_query($p);
}

include $caminho_header;
?>
<style>
.cc-header { background:#1a1a2e; color:#fff; border-radius:8px 8px 0 0; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; }
.cc-badge-btn { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:13px; font-weight:700; cursor:pointer; border:2px solid transparent; text-decoration:none; transition:.15s; }
.cc-badge-btn.todos  { background:#343a40; color:#fff; border-color:#6c757d; }
.cc-badge-btn.todos.ativo { border-color:#fff; }
.cc-badge-btn.rest   { background:#dc354520; color:#dc3545; border-color:#dc3545; }
.cc-badge-btn.rest.ativo { background:#dc3545; color:#fff; }
.cc-badge-btn.cont   { background:#19875420; color:#198754; border-color:#198754; }
.cc-badge-btn.cont.ativo { background:#198754; color:#fff; }
.cc-table th { background:#212529; color:#fff; font-size:12px; text-transform:uppercase; white-space:nowrap; padding:8px 10px; }
.cc-table td { font-size:13px; padding:7px 10px; vertical-align:middle; }
.cc-table tr:hover td { background:#f8f9fa; }
.cc-cpf  { font-family:monospace; font-size:12px; }
.cc-nome { font-weight:600; }
.cc-tel  { color:#0d6efd; font-size:12px; }
.cc-data { font-size:11px; color:#6c757d; white-space:nowrap; }
.cc-status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
.cc-sem-contato { color:#adb5bd; font-style:italic; font-size:11px; }
.cc-ver-btn { padding:2px 10px; font-size:12px; }
.cc-filtro-ativo { font-size:11px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:4px 10px; color:#856404; }
</style>

<div class="container-fluid py-3">

    <!-- Cabeçalho da campanha -->
    <div class="cc-header mb-0">
        <div>
            <i class="fas fa-bullhorn me-2 text-warning"></i>
            <strong style="font-size:16px;"><?= htmlspecialchars($campanha['NOME_CAMPANHA']) ?></strong>
            <?php if ($campanha['NOME_EMPRESA']): ?>
                <span class="badge bg-primary ms-2" style="font-size:11px;"><?= htmlspecialchars($campanha['NOME_EMPRESA']) ?></span>
            <?php endif; ?>
            <span class="badge bg-<?= $campanha['STATUS'] === 'ATIVO' ? 'success' : 'danger' ?> ms-2" style="font-size:10px;"><?= $campanha['STATUS'] ?></span>
        </div>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <a href="?id_campanha=<?= $id_campanha ?>&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn todos <?= (!$somente_restantes && !$somente_contatados) ? 'ativo' : '' ?>">
                <i class="fas fa-users"></i> Total <strong><?= number_format($badge_total, 0, ',', '.') ?></strong>
            </a>
            <a href="?id_campanha=<?= $id_campanha ?>&restantes=1&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn rest <?= $somente_restantes ? 'ativo' : '' ?>">
                <i class="fas fa-hourglass-half"></i> Restantes <strong><?= number_format($badge_restantes, 0, ',', '.') ?></strong>
            </a>
            <a href="?id_campanha=<?= $id_campanha ?>&contatados=1&<?= http_build_query(array_diff_key($_GET, ['p'=>1,'restantes'=>1,'contatados'=>1])) ?>"
               class="cc-badge-btn cont <?= $somente_contatados ? 'ativo' : '' ?>">
                <i class="fas fa-check-double"></i> Contatados <strong><?= number_format($badge_total - $badge_restantes, 0, ',', '.') ?></strong>
            </a>
            <a href="/modulos/campanhas/index.php" class="btn btn-sm btn-outline-light">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <!-- Barra de busca -->
    <div class="bg-light border border-top-0 rounded-bottom mb-3 p-3">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <input type="hidden" name="id_campanha" value="<?= $id_campanha ?>">
            <?php if ($somente_restantes): ?><input type="hidden" name="restantes" value="1"><?php endif; ?>
            <?php if ($somente_contatados): ?><input type="hidden" name="contatados" value="1"><?php endif; ?>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control form-control-sm" style="max-width:260px;" placeholder="CPF ou nome...">
            <button type="submit" class="btn btn-sm btn-dark"><i class="fas fa-search"></i> Buscar</button>
            <?php if ($q): ?>
                <a href="?id_campanha=<?= $id_campanha ?><?= $somente_restantes ? '&restantes=1' : '' ?><?= $somente_contatados ? '&contatados=1' : '' ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times"></i> Limpar
                </a>
                <span class="cc-filtro-ativo"><i class="fas fa-filter me-1"></i>Filtro: "<?= htmlspecialchars($q) ?>"</span>
            <?php endif; ?>
            <span class="ms-auto text-muted small">
                <?= number_format($total, 0, ',', '.') ?> resultado<?= $total != 1 ? 's' : '' ?>
                <?php if ($total > $por_pag): ?> — página <?= $pagina ?> de <?= $total_pags ?><?php endif; ?>
            </span>
        </form>
    </div>

    <!-- Tabela -->
    <div class="table-responsive shadow-sm rounded border border-secondary">
        <table class="table table-bordered table-hover mb-0 cc-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>CPF</th>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Inclusão na Campanha</th>
                    <th>Último Contato</th>
                    <th>Último Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($clientes)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4 fst-italic">Nenhum cliente encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($clientes as $i => $cli):
                    $num = $offset + $i + 1;
                    $cpf_fmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', str_pad($cli['CPF_CLIENTE'], 11, '0', STR_PAD_LEFT));
                    $tel_fmt = $cli['TELEFONE'] ? preg_replace('/(\d{2})(\d{4,5})(\d{4})/', '($1) $2-$3', $cli['TELEFONE']) : '';
                    $inc_fmt = $cli['DATA_INCLUSAO'] ? date('d/m/Y H:i', strtotime($cli['DATA_INCLUSAO'])) : '—';
                    $ult_fmt = $cli['ULTIMO_CONTATO'] ? date('d/m/Y H:i', strtotime($cli['ULTIMO_CONTATO'])) : null;
                    $url_ver = "/modulos/banco_dados/consulta.php?id_campanha={$id_campanha}&cpf_selecionado={$cli['CPF_CLIENTE']}&acao=visualizar";
                ?>
                <tr>
                    <td class="text-muted small"><?= $num ?></td>
                    <td class="cc-cpf"><?= $cpf_fmt ?></td>
                    <td class="cc-nome"><?= htmlspecialchars($cli['NOME'] ?? '—') ?></td>
                    <td class="cc-tel"><?= $tel_fmt ?: '<span class="text-muted">—</span>' ?></td>
                    <td class="cc-data"><?= $inc_fmt ?></td>
                    <td class="cc-data">
                        <?php if ($ult_fmt): ?>
                            <span class="text-success fw-bold"><?= $ult_fmt ?></span>
                        <?php else: ?>
                            <span class="cc-sem-contato"><i class="fas fa-clock me-1"></i>Não contatado</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($cli['ULTIMO_STATUS']): ?>
                            <span class="cc-status-badge bg-info text-dark"><?= htmlspecialchars($cli['ULTIMO_STATUS']) ?></span>
                        <?php else: ?>
                            <span class="cc-sem-contato">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= $url_ver ?>" target="_blank" class="btn btn-sm btn-outline-primary cc-ver-btn">
                            <i class="fas fa-eye"></i> Ver
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($total_pags > 1): ?>
    <nav class="mt-3 d-flex justify-content-center">
        <ul class="pagination pagination-sm mb-0">
            <?php if ($pagina > 1): ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag(1)) ?>"><i class="fas fa-angle-double-left"></i></a></li>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($pagina - 1)) ?>"><i class="fas fa-angle-left"></i></a></li>
            <?php endif; ?>
            <?php
            $ini = max(1, $pagina - 2);
            $fim = min($total_pags, $pagina + 2);
            for ($pp = $ini; $pp <= $fim; $pp++): ?>
                <li class="page-item <?= $pp === $pagina ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(urlPag($pp)) ?>"><?= $pp ?></a>
                </li>
            <?php endfor; ?>
            <?php if ($pagina < $total_pags): ?>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($pagina + 1)) ?>"><i class="fas fa-angle-right"></i></a></li>
                <li class="page-item"><a class="page-link" href="<?= htmlspecialchars(urlPag($total_pags)) ?>"><i class="fas fa-angle-double-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <p class="text-center text-muted small mt-1"><?= number_format($total, 0, ',', '.') ?> clientes — 100 por página</p>
    <?php endif; ?>

</div>
