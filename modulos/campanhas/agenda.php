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

// ====================================================================================
// REGRAS DE PERMISSÃO
// ====================================================================================
if (!verificaPermissao($pdo, 'MENU_MENU', 'TELA')) {
    include $caminho_header;
    die("<div class='container mt-5'><div class='alert alert-danger text-center shadow-lg border-dark p-4 rounded-3'><h4 class='fw-bold mb-3'><i class='fas fa-ban'></i> Acesso Negado</h4><p class='mb-0'>Seu grupo de usuário não tem permissão para acessar a Agenda de Campanhas.</p></div></div>");
}

$perm_agenda_hierarquia = verificaPermissao($pdo, 'MENU_MENU_MENU_HIERARQUIA', 'TELA');
$perm_agenda_meu = verificaPermissao($pdo, 'MENU_MENU_MEU_CPF', 'TELA');

// ====================================================================================
// FUNÇÃO MÁSCARA CPF E MAPEAMENTO DE COLUNAS
// ====================================================================================
function mascaraCPF($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
}

function mapearColunaAgenda($campo) {
    $mapa = [
        'cpf' => 'r.CPF_CLIENTE',
        'nome' => 'd.nome',
        'campanha' => 'c.NOME_CAMPANHA',
        'status' => 's.NOME_STATUS',
        'registro' => 'r.REGISTRO'
    ];
    return isset($mapa[$campo]) ? $mapa[$campo] : 'd.nome';
}

$erro_banco = null;

// ====================================================================================
// IDENTIFICA O USUÁRIO E SUAS REGRAS (DUAL WRITE)
// ====================================================================================
$cpf_logado = $_SESSION['usuario_cpf'] ?? '';
$id_usuario_logado_num = null;
$id_empresa_logado_num = null;

if ($cpf_logado) {
    $stmtUsuIDs = $pdo->prepare("SELECT ID, id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtUsuIDs->execute([$cpf_logado]);
    $dados_ids = $stmtUsuIDs->fetch(PDO::FETCH_ASSOC);
    if($dados_ids){
        $id_usuario_logado_num = $dados_ids['ID'];
        $id_empresa_logado_num = $dados_ids['id_empresa'];
    }
}

// ====================================================================================
// FILTRO AVANÇADO
// ====================================================================================
$is_busca_avancada = isset($_GET['busca_avancada']) && $_GET['busca_avancada'] == '1';
$filtros_campo = isset($_GET['campo']) ? $_GET['campo'] : [];
$filtros_operador = isset($_GET['operador']) ? $_GET['operador'] : [];
$filtros_valor = isset($_GET['valor']) ? $_GET['valor'] : [];

$filtro_avancado_sql = "";
$params_avancado = [];
$contador_params = 1;

if ($is_busca_avancada) {
    for ($i = 0; $i < count($filtros_campo); $i++) {
        $campo_html = $filtros_campo[$i]; $operador = $filtros_operador[$i]; $valor_bruto = trim($filtros_valor[$i]);
        if ($valor_bruto === '' && $operador != 'vazio') continue; 

        $coluna_db = mapearColunaAgenda($campo_html);
        if ($operador == 'vazio') { $filtro_avancado_sql .= " AND ($coluna_db IS NULL OR trim($coluna_db) = '') "; continue; }

        $valores_array = explode(';', $valor_bruto); $condicoes_or = [];
        foreach ($valores_array as $val) {
            $val = trim($val); if ($val === '') continue;
            if ($campo_html == 'cpf') { $val = str_pad(preg_replace('/[^0-9]/', '', $val), 11, '0', STR_PAD_LEFT); } 

            $param_nome = 'pa' . $contador_params++; 
            if ($operador == 'contem') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params_avancado[$param_nome] = "%$val%"; } 
            elseif ($operador == 'nao_contem') { $condicoes_or[] = "$coluna_db NOT LIKE :$param_nome"; $params_avancado[$param_nome] = "%$val%"; } 
            elseif ($operador == 'comeca') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params_avancado[$param_nome] = "$val%"; } 
            elseif ($operador == 'igual') { $condicoes_or[] = "TRIM($coluna_db) = :$param_nome"; $params_avancado[$param_nome] = $val; }
        }
        if (!empty($condicoes_or)) { $filtro_avancado_sql .= ($operador == 'nao_contem') ? " AND (" . implode(" AND ", $condicoes_or) . ") " : " AND (" . implode(" OR ", $condicoes_or) . ") "; }
    }
}

// ====================================================================================
// CONSULTAS DA AGENDA
// ====================================================================================
$agendamentos_hoje = [];
$agendamentos_atrasados = [];
$agendamentos_futuros = [];

try {
    $hoje_inicio = date('Y-m-d 00:00:00');
    $hoje_fim = date('Y-m-d 23:59:59');

    // Query Base de Busca 
    $sqlBase = "
        SELECT r.ID, r.CPF_CLIENTE, r.DATA_AGENDAMENTO, r.REGISTRO, r.NOME_USUARIO,
               s.NOME_STATUS,
               c.NOME_CAMPANHA, c.ID as ID_CAMPANHA,
               d.nome as NOME_CLIENTE,
               (SELECT telefone_cel FROM telefones WHERE cpf = r.CPF_CLIENTE ORDER BY ID ASC LIMIT 1) as TELEFONE
        FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r
        JOIN BANCO_DE_DADOS_CAMPANHA_STATUS_CONTATO s ON r.ID_STATUS_CONTATO = s.ID
        LEFT JOIN BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c ON s.ID_CAMPANHA = c.ID
        LEFT JOIN dados_cadastrais d ON r.CPF_CLIENTE = d.cpf
        WHERE r.DATA_AGENDAMENTO IS NOT NULL
    ";

    $paramsBase = [];

    // Filtros de Permissão da Hierarquia
    if (!$perm_agenda_hierarquia) {
        $sqlBase .= " AND r.id_empresa = ?";
        $paramsBase[] = $id_empresa_logado_num;
    }
    if (!$perm_agenda_meu) {
        $sqlBase .= " AND r.id_usuario = ?";
        $paramsBase[] = $id_usuario_logado_num;
    }

    // Aplica o filtro avançado
    if ($is_busca_avancada && !empty($filtro_avancado_sql)) {
        $sqlBase .= $filtro_avancado_sql;
        $paramsBase = array_merge($paramsBase, $params_avancado);
    }

    // 1. Busca Agendamentos de Hoje
    $sqlHoje = $sqlBase . " AND r.DATA_AGENDAMENTO BETWEEN ? AND ? ORDER BY r.DATA_AGENDAMENTO ASC";
    $paramsHoje = array_merge($paramsBase, [$hoje_inicio, $hoje_fim]);
    $stmtHoje = $pdo->prepare($sqlHoje);
    $stmtHoje->execute($paramsHoje);
    $agendamentos_hoje = $stmtHoje->fetchAll(PDO::FETCH_ASSOC);

    // 2. Busca Agendamentos Atrasados
    $sqlAtrasados = $sqlBase . " AND r.DATA_AGENDAMENTO < ? 
        AND NOT EXISTS (
            SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r2 
            WHERE r2.CPF_CLIENTE = r.CPF_CLIENTE AND r2.DATA_REGISTRO > r.DATA_REGISTRO
        )
        ORDER BY r.DATA_AGENDAMENTO ASC";
    $paramsAtrasados = array_merge($paramsBase, [$hoje_inicio]);
    $stmtAtrasados = $pdo->prepare($sqlAtrasados);
    $stmtAtrasados->execute($paramsAtrasados);
    $agendamentos_atrasados = $stmtAtrasados->fetchAll(PDO::FETCH_ASSOC);

    // 3. Busca Agendamentos Futuros
    $sqlFuturos = $sqlBase . " AND r.DATA_AGENDAMENTO > ? ORDER BY r.DATA_AGENDAMENTO ASC LIMIT 100";
    $paramsFuturos = array_merge($paramsBase, [$hoje_fim]);
    $stmtFuturos = $pdo->prepare($sqlFuturos);
    $stmtFuturos->execute($paramsFuturos);
    $agendamentos_futuros = $stmtFuturos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $erro_banco = "<b>Erro ao carregar agenda:</b> " . $e->getMessage();
}

include $caminho_header;
?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-success fw-bold"><i class="fas fa-calendar-alt me-2"></i> Minha Agenda</h2>
        <p class="text-muted">Acompanhe seus retornos agendados e compromissos de campanhas.</p>
    </div>
</div>

<?php if ($erro_banco): ?>
    <div class="alert alert-danger shadow-sm border-dark fw-bold text-center"><i class="fas fa-exclamation-triangle"></i> <?= $erro_banco ?></div>
<?php endif; ?>

<div class="row justify-content-center mb-4">
    <div class="col-md-12 text-end">
        <button class="btn btn-sm btn-outline-success fw-bold border-dark shadow-sm bg-white" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="<?= $is_busca_avancada ? 'true' : 'false' ?>">
            <i class="fas fa-filter"></i> Filtro da Agenda
        </button>
    </div>
</div>

<div class="row justify-content-center">
    <div class="col-12">
        
        <div class="collapse mb-4 <?= $is_busca_avancada ? 'show' : '' ?>" id="painelBuscaAvancada">
            <div class="card border-dark shadow-sm rounded-3 overflow-hidden">
                <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase">
                    <i class="fas fa-search text-success me-2"></i> Montador de Filtros (Agenda)
                </div>
                <div class="card-body bg-light">
                    <form action="" method="GET" id="formBuscaAvancada">
                        <input type="hidden" name="busca_avancada" value="1">
                        
                        <div class="alert alert-secondary py-2 small mb-3 border-dark">
                            <i class="fas fa-info-circle"></i> <b>Dica:</b> Use ponto e vírgula <b>(;)</b> para buscar vários valores.
                        </div>

                        <div id="areaFiltros">
                            <?php 
                            $qtd_linhas = max(1, count($filtros_campo));
                            for ($i = 0; $i < $qtd_linhas; $i++): 
                                $c_sel = $filtros_campo[$i] ?? '';
                                $o_sel = $filtros_operador[$i] ?? 'contem';
                                $v_sel = $filtros_valor[$i] ?? '';
                            ?>
                            <div class="row g-2 mb-2 linha-filtro align-items-center">
                                <div class="col-md-3">
                                    <select name="campo[]" class="form-select border-dark shadow-sm">
                                        <option value="nome" <?= $c_sel=='nome'?'selected':'' ?>>Nome do Cliente</option>
                                        <option value="cpf" <?= $c_sel=='cpf'?'selected':'' ?>>CPF</option>
                                        <option value="campanha" <?= $c_sel=='campanha'?'selected':'' ?>>Campanha</option>
                                        <option value="status" <?= $c_sel=='status'?'selected':'' ?>>Status do Retorno</option>
                                        <option value="registro" <?= $c_sel=='registro'?'selected':'' ?>>Anotação / Observação</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="operador[]" class="form-select border-dark shadow-sm" onchange="verificarVazio(this)">
                                        <option value="contem" <?= $o_sel=='contem'?'selected':'' ?>>Contém</option>
                                        <option value="nao_contem" <?= $o_sel=='nao_contem'?'selected':'' ?>>Não contém</option>
                                        <option value="comeca" <?= $o_sel=='comeca'?'selected':'' ?>>Começa com</option>
                                        <option value="igual" <?= $o_sel=='igual'?'selected':'' ?>>Exatamente igual a</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" name="valor[]" class="form-control border-dark shadow-sm input-valor" placeholder="Valor do filtro..." value="<?= htmlspecialchars($v_sel) ?>">
                                </div>
                                <div class="col-md-1 text-center">
                                    <button type="button" class="btn btn-outline-danger border-dark btn-sm remover-linha"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>

                        <div class="d-flex justify-content-between mt-3 border-top border-dark pt-3">
                            <button type="button" class="btn btn-sm btn-dark fw-bold shadow-sm" onclick="adicionarFiltro()">
                                <i class="fas fa-plus"></i> Adicionar Regra
                            </button>
                            <div>
                                <a href="agenda.php" class="btn btn-sm btn-outline-dark shadow-sm bg-white">Limpar Filtros</a>
                                <button type="submit" class="btn btn-sm btn-success text-dark border-dark fw-bold shadow-sm"><i class="fas fa-filter"></i> Aplicar Filtros na Agenda</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card border-dark shadow-sm rounded-0">
            <div class="card-header bg-primary text-white rounded-0 py-2 border-bottom border-dark">
                <ul class="nav nav-tabs card-header-tabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active fw-bold text-dark rounded-0 border-dark" data-bs-toggle="tab" href="#aba-hoje">
                            <i class="fas fa-calendar-day text-primary me-1"></i> Para Hoje 
                            <span class="badge bg-dark ms-1"><?= count($agendamentos_hoje) ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-bold text-danger rounded-0 border-dark" data-bs-toggle="tab" href="#aba-atrasados">
                            <i class="fas fa-exclamation-circle me-1"></i> Atrasados 
                            <span class="badge bg-danger ms-1"><?= count($agendamentos_atrasados) ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-bold text-dark rounded-0 border-dark bg-light" data-bs-toggle="tab" href="#aba-futuros">
                            <i class="fas fa-calendar-week text-success me-1"></i> Próximos Dias 
                            <span class="badge bg-success ms-1"><?= count($agendamentos_futuros) ?></span>
                        </a>
                    </li>
                </ul>
            </div>
            
            <div class="card-body bg-light p-3">
                <div class="tab-content">
                    
                    <div class="tab-pane fade show active" id="aba-hoje">
                        <?php if (empty($agendamentos_hoje)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-laugh-beam text-muted fa-3x mb-3"></i>
                                <h5 class="text-secondary fw-bold">Nenhum retorno para exibir aqui.</h5>
                                <p class="text-muted small">Aproveite para prospectar novos clientes!</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover border-dark align-middle text-center bg-white shadow-sm mb-0" style="font-size: 0.80rem;">
                                    <thead class="table-light border-dark text-uppercase">
                                        <tr>
                                            <th>Data do Retorno</th>
                                            <th class="text-start">Cliente</th>
                                            <th>CPF</th>
                                            <th>Telefone</th>
                                            <th>Campanha</th>
                                            <th>Status</th>
                                            <th class="text-start" style="width: 25%;">Observação</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agendamentos_hoje as $a): ?>
                                            <tr class="border-bottom border-dark">
                                                <td class="fw-bold text-primary"><i class="fas fa-clock text-dark"></i> <?= date('H:i', strtotime($a['DATA_AGENDAMENTO'])) ?></td>
                                                <td class="text-start fw-bold"><?= htmlspecialchars($a['NOME_CLIENTE'] ?? 'Cliente Desconhecido') ?></td>
                                                <td class="fw-bold text-secondary"><?= mascaraCPF($a['CPF_CLIENTE']) ?></td>
                                                <td><span class="badge bg-dark text-white rounded-0"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($a['TELEFONE'] ?? 'Sem telefone') ?></span></td>
                                                <td><div class="fw-bold text-danger"><?= htmlspecialchars($a['NOME_CAMPANHA'] ?? 'Global') ?></div></td>
                                                <td><span class="badge bg-secondary rounded-0"><?= htmlspecialchars($a['NOME_STATUS']) ?></span></td>
                                                <td class="text-start text-muted fst-italic" style="font-size: 0.75rem;"><?= nl2br(htmlspecialchars($a['REGISTRO'])) ?></td>
                                                <td>
                                                    <a href="/modulos/banco_dados/consulta.php?id_campanha=<?= $a['ID_CAMPANHA'] ?>&busca=<?= $a['CPF_CLIENTE'] ?>&cpf_selecionado=<?= $a['CPF_CLIENTE'] ?>&acao=visualizar" class="btn btn-sm btn-primary border-dark fw-bold shadow-sm rounded-0"><i class="fas fa-phone-alt"></i> Atender</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="aba-atrasados">
                        <?php if (empty($agendamentos_atrasados)): ?>
                            <div class="alert alert-success text-center fw-bold shadow-sm border-dark rounded-0"><i class="fas fa-check-circle fs-5 me-2"></i> Sem retornos em atraso para este filtro.</div>
                        <?php else: ?>
                            <div class="alert alert-danger border-dark small fw-bold shadow-sm rounded-0"><i class="fas fa-info-circle"></i> Estes são retornos que passaram da data e o cliente ainda não recebeu um novo registro.</div>
                            <div class="table-responsive">
                                <table class="table table-hover border-dark align-middle text-center bg-white shadow-sm mb-0" style="font-size: 0.80rem;">
                                    <thead class="table-danger border-dark text-uppercase">
                                        <tr>
                                            <th>Data do Retorno</th>
                                            <th class="text-start">Cliente</th>
                                            <th>CPF</th>
                                            <th>Telefone</th>
                                            <th>Campanha</th>
                                            <th>Status</th>
                                            <th class="text-start" style="width: 25%;">Observação</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agendamentos_atrasados as $a): ?>
                                            <tr class="border-bottom border-dark">
                                                <td class="fw-bold text-danger"><i class="fas fa-calendar-times text-dark"></i> <?= date('d/m/Y H:i', strtotime($a['DATA_AGENDAMENTO'])) ?></td>
                                                <td class="text-start fw-bold"><?= htmlspecialchars($a['NOME_CLIENTE'] ?? 'Cliente Desconhecido') ?></td>
                                                <td class="fw-bold text-secondary"><?= mascaraCPF($a['CPF_CLIENTE']) ?></td>
                                                <td><span class="badge bg-dark text-white rounded-0"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($a['TELEFONE'] ?? 'Sem telefone') ?></span></td>
                                                <td><div class="fw-bold text-dark"><?= htmlspecialchars($a['NOME_CAMPANHA'] ?? 'Global') ?></div></td>
                                                <td><span class="badge bg-secondary rounded-0"><?= htmlspecialchars($a['NOME_STATUS']) ?></span></td>
                                                <td class="text-start text-muted fst-italic" style="font-size: 0.75rem;"><?= nl2br(htmlspecialchars($a['REGISTRO'])) ?></td>
                                                <td>
                                                    <a href="/modulos/banco_dados/consulta.php?id_campanha=<?= $a['ID_CAMPANHA'] ?>&busca=<?= $a['CPF_CLIENTE'] ?>&cpf_selecionado=<?= $a['CPF_CLIENTE'] ?>&acao=visualizar" class="btn btn-sm btn-danger border-dark fw-bold shadow-sm rounded-0"><i class="fas fa-exclamation-triangle"></i> Recuperar</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="tab-pane fade" id="aba-futuros">
                        <?php if (empty($agendamentos_futuros)): ?>
                            <div class="text-center py-4 text-muted fw-bold">Sem agendamentos para este filtro.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover border-dark align-middle text-center bg-white shadow-sm mb-0" style="font-size: 0.80rem;">
                                    <thead class="table-secondary border-dark text-uppercase">
                                        <tr>
                                            <th>Data do Retorno</th>
                                            <th class="text-start">Cliente</th>
                                            <th>CPF</th>
                                            <th>Telefone</th>
                                            <th>Campanha</th>
                                            <th>Status</th>
                                            <th class="text-start" style="width: 25%;">Observação</th>
                                            <th>Ação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($agendamentos_futuros as $a): ?>
                                            <tr class="border-bottom border-dark">
                                                <td class="fw-bold text-success"><i class="fas fa-calendar-check text-dark"></i> <?= date('d/m/Y H:i', strtotime($a['DATA_AGENDAMENTO'])) ?></td>
                                                <td class="text-start fw-bold"><?= htmlspecialchars($a['NOME_CLIENTE'] ?? 'Cliente Desconhecido') ?></td>
                                                <td class="fw-bold text-secondary"><?= mascaraCPF($a['CPF_CLIENTE']) ?></td>
                                                <td><span class="badge bg-dark text-white rounded-0"><i class="fab fa-whatsapp text-success"></i> <?= htmlspecialchars($a['TELEFONE'] ?? 'Sem telefone') ?></span></td>
                                                <td><div class="fw-bold text-dark"><?= htmlspecialchars($a['NOME_CAMPANHA'] ?? 'Global') ?></div></td>
                                                <td><span class="badge bg-secondary rounded-0"><?= htmlspecialchars($a['NOME_STATUS']) ?></span></td>
                                                <td class="text-start text-muted fst-italic" style="font-size: 0.75rem;"><?= nl2br(htmlspecialchars($a['REGISTRO'])) ?></td>
                                                <td>
                                                    <a href="/modulos/banco_dados/consulta.php?id_campanha=<?= $a['ID_CAMPANHA'] ?>&busca=<?= $a['CPF_CLIENTE'] ?>&cpf_selecionado=<?= $a['CPF_CLIENTE'] ?>&acao=visualizar" class="btn btn-sm btn-outline-dark border-dark fw-bold shadow-sm rounded-0"><i class="fas fa-eye"></i> Ver Ficha</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
function verificarVazio(selectOperador) {
    const inputValor = selectOperador.closest('.linha-filtro').querySelector('.input-valor');
    if (selectOperador.value === 'vazio') {
        inputValor.value = '';
        inputValor.setAttribute('readonly', 'readonly');
        inputValor.setAttribute('placeholder', 'Operador não exige valor');
    } else {
        inputValor.removeAttribute('readonly');
        inputValor.setAttribute('placeholder', 'Valor do filtro...');
    }
}

function adicionarFiltro() {
    const area = document.getElementById('areaFiltros');
    const primeiraLinha = area.querySelector('.linha-filtro');
    const novaLinha = primeiraLinha.cloneNode(true);
    
    novaLinha.querySelector('input').value = '';
    novaLinha.querySelector('input').removeAttribute('readonly');
    novaLinha.querySelector('input').setAttribute('placeholder', 'Valor do filtro...');
    
    area.appendChild(novaLinha);
}

document.getElementById('areaFiltros')?.addEventListener('click', function(e) {
    if (e.target.closest('.remover-linha')) {
        const area = document.getElementById('areaFiltros');
        if (area.querySelectorAll('.linha-filtro').length > 1) {
            e.target.closest('.linha-filtro').remove();
        } else {
            crmToast("Você precisa ter pelo menos uma regra de filtro!", "warning", 5000);
        }
    }
});
</script>

<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>