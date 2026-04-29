<?php
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

$task_id = (int)($_GET['task_id'] ?? 0);
$tipo    = strtoupper(trim($_GET['tipo'] ?? 'TODOS'));
$pagina  = max(1, (int)($_GET['pagina'] ?? 1));
$por_pag = 100;
$offset  = ($pagina - 1) * $por_pag;

if ($task_id <= 0) { die('Task inválida.'); }

$stmtTask = $pdo->prepare("SELECT NOME_IMPORTACAO, QTD_NOVOS, QTD_ATUALIZADOS, QTD_ERROS, QTD_TOTAL, STATUS, DATA_INICIO, DATA_FIM FROM CONTROLE_IMPORTACAO_ASSINCRONA WHERE ID = ?");
$stmtTask->execute([$task_id]);
$tarefa = $stmtTask->fetch(PDO::FETCH_ASSOC);
if (!$tarefa) { die('Importação não encontrada.'); }

$tipos_validos = ['NOVO', 'ATUALIZADO', 'ERRO', 'TODOS'];
if (!in_array($tipo, $tipos_validos)) $tipo = 'TODOS';

$where_tipo = ($tipo !== 'TODOS') ? "AND h.tipo = ?" : '';
$params_count = [$task_id]; if ($tipo !== 'TODOS') $params_count[] = $tipo;
$params_list  = [$task_id]; if ($tipo !== 'TODOS') $params_list[] = $tipo;
$params_list  = array_merge($params_list, [$por_pag, $offset]);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM BANCO_DE_DADOS_HISTORICO_IMPORTACAO h WHERE h.task_id = ? $where_tipo");
$stmtCount->execute($params_count);
$total_registros = (int)$stmtCount->fetchColumn();
$total_paginas = max(1, ceil($total_registros / $por_pag));

$stmtLista = $pdo->prepare("
    SELECT h.cpf, h.tipo, d.nome,
           (SELECT telefone_cel FROM telefones WHERE cpf = h.cpf LIMIT 1) as telefone
    FROM BANCO_DE_DADOS_HISTORICO_IMPORTACAO h
    LEFT JOIN dados_cadastrais d ON d.cpf = h.cpf
    WHERE h.task_id = ? $where_tipo
    ORDER BY h.id DESC
    LIMIT ? OFFSET ?
");
$stmtLista->execute($params_list);
$registros = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

$labels_tipo = ['NOVO' => ['bg-success', 'Novo'], 'ATUALIZADO' => ['bg-info text-dark', 'Atualizado'], 'ERRO' => ['bg-danger', 'Erro']];

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
?>
<div class="container-fluid mt-3 mb-5">

    <div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
        <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-file-import text-primary me-2"></i><?= htmlspecialchars($tarefa['NOME_IMPORTACAO']) ?></h5>
        <span class="badge bg-dark border border-secondary">ID #<?= $task_id ?></span>
        <span class="badge bg-secondary"><?= $tarefa['STATUS'] ?></span>
    </div>

    <div class="d-flex gap-2 mb-3 flex-wrap">
        <?php
        $tabs = ['TODOS' => ['secondary', 'Todos', $tarefa['QTD_NOVOS'] + $tarefa['QTD_ATUALIZADOS'] + $tarefa['QTD_ERROS']],
                 'NOVO'       => ['success', 'Novos',       $tarefa['QTD_NOVOS']],
                 'ATUALIZADO' => ['info',    'Atualizados',  $tarefa['QTD_ATUALIZADOS']],
                 'ERRO'       => ['danger',  'Erros',        $tarefa['QTD_ERROS']]];
        foreach ($tabs as $t => [$cor, $label, $qtd]):
            $ativo = ($tipo === $t) ? 'active fw-bold' : 'fw-bold opacity-75';
        ?>
            <a href="?task_id=<?= $task_id ?>&tipo=<?= $t ?>" class="btn btn-<?= $cor ?> btn-sm <?= $ativo ?> border border-dark shadow-sm">
                <?= $label ?> <span class="badge bg-dark ms-1"><?= number_format($qtd) ?></span>
            </a>
        <?php endforeach; ?>
        <button class="btn btn-outline-dark btn-sm fw-bold ms-auto shadow-sm" onclick="window.close()"><i class="fas fa-times me-1"></i> Fechar</button>
    </div>

    <div class="card border-dark shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
            <span class="fw-bold small"><i class="fas fa-list me-1"></i> <?= number_format($total_registros) ?> registros encontrados</span>
            <span class="small text-muted">Página <?= $pagina ?> / <?= $total_paginas ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-center" style="font-size: 0.85rem;">
                <thead class="table-light text-uppercase border-dark small">
                    <tr>
                        <th class="text-start ps-3">CPF</th>
                        <th class="text-start">Nome</th>
                        <th>Telefone</th>
                        <th>Tipo</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="5" class="py-5 text-muted fw-bold">Nenhum registro encontrado.</td></tr>
                <?php else: foreach ($registros as $r):
                    [$bg_tipo, $label_tipo] = $labels_tipo[$r['tipo']] ?? ['bg-secondary', $r['tipo']];
                ?>
                    <tr class="border-bottom">
                        <td class="text-start ps-3 fw-bold font-monospace"><?= htmlspecialchars($r['cpf']) ?></td>
                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($r['nome'] ?? '—') ?></td>
                        <td class="text-muted"><?= htmlspecialchars($r['telefone'] ?? '—') ?></td>
                        <td><span class="badge <?= $bg_tipo ?> border border-dark"><?= $label_tipo ?></span></td>
                        <td>
                            <a href="/modulos/banco_dados/consulta.php?busca=<?= urlencode($r['cpf']) ?>" target="_blank" class="btn btn-sm btn-dark fw-bold shadow-sm">
                                <i class="fas fa-search"></i> Ver Ficha
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_paginas > 1): ?>
        <div class="card-footer bg-light border-dark d-flex justify-content-center gap-1 flex-wrap py-2">
            <?php for ($p = max(1, $pagina - 4); $p <= min($total_paginas, $pagina + 4); $p++): ?>
                <a href="?task_id=<?= $task_id ?>&tipo=<?= $tipo ?>&pagina=<?= $p ?>"
                   class="btn btn-sm <?= $p === $pagina ? 'btn-dark' : 'btn-outline-dark' ?> fw-bold"><?= $p ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
