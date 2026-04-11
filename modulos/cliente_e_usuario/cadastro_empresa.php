<?php
session_start();

// RASTREADOR DE ERROS ATIVADO PARA O PHP
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';

if (!file_exists($caminho_conexao)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> O arquivo de conexão não foi encontrado no caminho: <b>$caminho_conexao</b>");
}
include $caminho_conexao;
try { $pdo->exec("ALTER TABLE CLIENTE_EMPRESAS MODIFY COLUMN NOME_CADASTRO VARCHAR(150)"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE CLIENTE_CADASTRO MODIFY COLUMN NOME_EMPRESA VARCHAR(150)"); } catch(Exception $e){}

$termo_busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$cnpj_selecionado = isset($_GET['cnpj_selecionado']) ? $_GET['cnpj_selecionado'] : '';
$is_busca_avancada = isset($_GET['busca_avancada']) && $_GET['busca_avancada'] == '1';

$filtros_campo = isset($_GET['campo']) ? $_GET['campo'] : [];
$filtros_operador = isset($_GET['operador']) ? $_GET['operador'] : [];
$filtros_valor = isset($_GET['valor']) ? $_GET['valor'] : [];

$pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limites_por_pagina = 20; 
$offset = ($pagina_atual - 1) * $limites_por_pagina; 

$resultados_busca = [];
$empresa_ficha = null;
$cpfs_vinculados_ficha = []; 
$erro_banco = null; 
$mensagem_alerta = ''; 

function mascaraCNPJ($cnpj) {
    $cnpj = str_pad(preg_replace('/[^0-9]/', '', $cnpj), 14, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj);
}
function mascaraCPF($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
}

function mapearColunaEmpresa($campo) {
    $mapa = [
        'cnpj' => 'CNPJ',
        'cpf_cliente' => '(SELECT GROUP_CONCAT(CPF) FROM CLIENTE_CADASTRO WHERE CNPJ = CLIENTE_EMPRESAS.CNPJ)',
        'nome_cadastro' => 'NOME_CADASTRO',
        'celular' => 'CELULAR',
        'grupo_whats' => 'GRUPO_WHATS',
        'grupo_empresa' => 'GRUPO_EMPRESA'
    ];
    return isset($mapa[$campo]) ? $mapa[$campo] : 'NOME_CADASTRO';
}

try {
    $stmtCli = $pdo->query("SELECT CPF, NOME FROM CLIENTE_CADASTRO ORDER BY NOME ASC");
    $lista_clientes = $stmtCli->fetchAll(PDO::FETCH_ASSOC);

    // INSERIR NOVA EMPRESA NO BANCO
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_crud']) && $_POST['acao_crud'] == 'novo') {
        $cnpj_novo = preg_replace('/[^0-9]/', '', $_POST['cnpj']);
        $nome_cadastro = $_POST['nome_cadastro'];
        $celular = preg_replace('/[^0-9]/', '', $_POST['celular']);
        $cpfs_selecionados = isset($_POST['cpfs_vinculados']) ? $_POST['cpfs_vinculados'] : [];
        
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_EMPRESAS WHERE CNPJ = :cnpj");
        $stmtCheck->execute(['cnpj' => $cnpj_novo]);
        if ($stmtCheck->fetchColumn() > 0) {
            $mensagem_alerta = "<div class='alert alert-danger fw-bold'>ERRO: Este CNPJ já está cadastrado no sistema!</div>";
        } else {
            $pdo->beginTransaction();
            $stmtInsert = $pdo->prepare("INSERT INTO CLIENTE_EMPRESAS (CNPJ, NOME_CADASTRO, CPF_CLIENTE_CADASTRO, CELULAR) VALUES (:cnpj, :nome, NULL, :celular)");
            $stmtInsert->execute(['cnpj' => $cnpj_novo, 'nome' => $nome_cadastro, 'celular' => $celular]);
            
            if (!empty($cpfs_selecionados)) {
                $stmtSet = $pdo->prepare("UPDATE CLIENTE_CADASTRO SET CNPJ = :cnpj WHERE CPF = :cpf");
                foreach($cpfs_selecionados as $cpf_v) {
                    $stmtSet->execute(['cnpj' => $cnpj_novo, 'cpf' => $cpf_v]);
                }
            }
            $pdo->commit();
            $mensagem_alerta = "<div class='alert alert-success fw-bold'>Empresa cadastrada e vinculada aos clientes com sucesso!</div>";
        }
    }

    // ==========================================
    // LÓGICA DE BUSCA SIMPLES E AVANÇADA OTIMIZADA
    // ==========================================
    $filtro_sql = ""; $params = []; $contador_params = 1;

    if (!$is_busca_avancada && !empty($termo_busca) && empty($cnpj_selecionado)) {
        $termo_limpo_num = preg_replace('/[^0-9]/', '', $termo_busca);
        $filtro_sql .= " AND (NOME_CADASTRO LIKE :termo ";
        $params[':termo'] = "$termo_busca%"; // Sem % inicial
        
        if (!empty($termo_limpo_num)) {
            $filtro_sql .= " OR CNPJ LIKE :cnpj OR (SELECT GROUP_CONCAT(CPF) FROM CLIENTE_CADASTRO WHERE CNPJ = CLIENTE_EMPRESAS.CNPJ) LIKE :cnpj ";
            $params[':cnpj'] = "%$termo_limpo_num%";
        }
        $filtro_sql .= ")";
    } elseif ($is_busca_avancada && empty($cnpj_selecionado)) {
        for ($i = 0; $i < count($filtros_campo); $i++) {
            $campo_html = $filtros_campo[$i]; $operador = $filtros_operador[$i]; $valor_bruto = trim($filtros_valor[$i]);
            if ($valor_bruto === '' && $operador != 'vazio') continue; 

            $coluna_db = mapearColunaEmpresa($campo_html);
            if ($operador == 'vazio') { $filtro_sql .= " AND ($coluna_db IS NULL OR trim($coluna_db) = '') "; continue; }

            $valores_array = explode(';', $valor_bruto); $condicoes_or = [];
            foreach ($valores_array as $val) {
                $val = trim($val); if ($val === '') continue;
                if (in_array($campo_html, ['cnpj', 'cpf_cliente', 'celular'])) { $val = preg_replace('/[^0-9]/', '', $val); }
                $param_nome = 'p' . $contador_params++; 

                if ($operador == 'contem') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params[$param_nome] = "%$val%"; } 
                elseif ($operador == 'nao_contem') { $condicoes_or[] = "$coluna_db NOT LIKE :$param_nome"; $params[$param_nome] = "%$val%"; } 
                elseif ($operador == 'comeca') { $condicoes_or[] = "$coluna_db LIKE :$param_nome"; $params[$param_nome] = "$val%"; } 
                elseif ($operador == 'igual') { $condicoes_or[] = "TRIM($coluna_db) = :$param_nome"; $params[$param_nome] = $val; }
            }
            if (!empty($condicoes_or)) { $filtro_sql .= ($operador == 'nao_contem') ? " AND (" . implode(" AND ", $condicoes_or) . ") " : " AND (" . implode(" OR ", $condicoes_or) . ") "; }
        }
        $termo_busca = "Busca Avançada Ativada"; 
    }

    $tem_proxima_pagina = false;
    if ((!empty($termo_busca) || $is_busca_avancada) && empty($cnpj_selecionado)) {
        $sql_base = " FROM CLIENTE_EMPRESAS WHERE 1=1 " . $filtro_sql;
        
        $limite_busca = $limites_por_pagina + 1;
        $sql_dados = "SELECT CLIENTE_EMPRESAS.*, (SELECT COUNT(*) FROM CLIENTE_CADASTRO c WHERE c.CNPJ = CLIENTE_EMPRESAS.CNPJ) as total_clientes " . $sql_base . " LIMIT " . (int)$limite_busca . " OFFSET " . (int)$offset;
        $stmt = $pdo->prepare($sql_dados);
        $stmt->execute($params);
        $resultados_busca = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($resultados_busca) > $limites_por_pagina) {
            $tem_proxima_pagina = true;
            array_pop($resultados_busca);
        }
    }

    // ==========================================
    // CARREGAR FICHA PARA EXIBIÇÃO/EDIÇÃO
    // ==========================================
    if (!empty($cnpj_selecionado) && in_array($acao, ['visualizar', 'editar'])) {
        $cnpj_limpo = preg_replace('/[^0-9]/', '', $cnpj_selecionado);
        $stmt = $pdo->prepare("SELECT * FROM CLIENTE_EMPRESAS WHERE CNPJ = :cnpj");
        $stmt->execute(['cnpj' => $cnpj_limpo]);
        $empresa_ficha = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($empresa_ficha) {
            $stmtVinc = $pdo->prepare("SELECT CPF FROM CLIENTE_CADASTRO WHERE CNPJ = :cnpj");
            $stmtVinc->execute(['cnpj' => $cnpj_limpo]);
            $cpfs_vinculados_ficha = $stmtVinc->fetchAll(PDO::FETCH_COLUMN);
        }
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage();
}

if (!file_exists($caminho_header)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> O arquivo de cabeçalho não foi encontrado.");
}
include $caminho_header;
?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-warning fw-bold text-dark"><i class="fas fa-building me-2"></i> Cadastro de Empresas</h2>
        <p class="text-muted">Consulte registros empresariais ou crie uma nova empresa no sistema.</p>
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

<div class="row justify-content-center mb-2">
    <div class="col-md-8">
        <form action="" method="GET" class="d-flex shadow-sm mb-2">
            <input type="text" name="busca" class="form-control form-control-lg border-warning" placeholder="Pesquisar por CNPJ ou Nome da Empresa..." value="<?= $is_busca_avancada ? '' : htmlspecialchars($termo_busca) ?>" <?= $is_busca_avancada ? 'disabled' : 'autofocus' ?>>
            <button type="submit" class="btn btn-warning btn-lg px-4 ms-2 fw-bold text-dark border-dark" <?= $is_busca_avancada ? 'disabled' : '' ?>><i class="fas fa-search"></i> Pesquisar</button>
            <button type="button" class="btn btn-dark btn-lg px-4 ms-2 fw-bold shadow-sm" onclick="document.getElementById('painelNovoCadastro').style.display='block'"><i class="fas fa-plus text-info"></i> Novo</button>
        </form>

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-outline-secondary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="<?= $is_busca_avancada ? 'true' : 'false' ?>">
                <i class="fas fa-sliders-h"></i> Filtro Aprimorado
            </button>
        </div>

        <div class="collapse mt-3 <?= $is_busca_avancada ? 'show' : '' ?>" id="painelBuscaAvancada">
            <div class="card border-dark shadow-lg rounded-3 overflow-hidden">
                <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase">
                    <i class="fas fa-filter text-warning me-2"></i> Montador de Filtros (Empresas)
                </div>
                <div class="card-body bg-light">
                    <form action="" method="GET" id="formBuscaAvancada">
                        <input type="hidden" name="busca_avancada" value="1">
                        
                        <div class="alert alert-secondary py-2 small mb-3 border-dark">
                            <i class="fas fa-info-circle"></i> <b>Dica:</b> Use ponto e vírgula <b>(;)</b> para buscar vários valores ao mesmo tempo.
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
                                        <option value="nome_cadastro" <?= $c_sel=='nome_cadastro'?'selected':'' ?>>Nome da Empresa</option>
                                        <option value="cnpj" <?= $c_sel=='cnpj'?'selected':'' ?>>CNPJ</option>
                                        <option value="cpf_cliente" <?= $c_sel=='cpf_cliente'?'selected':'' ?>>CPF de Algum Titular</option>
                                        <option value="celular" <?= $c_sel=='celular'?'selected':'' ?>>Celular</option>
                                        <option value="grupo_whats" <?= $c_sel=='grupo_whats'?'selected':'' ?>>Grupo Whats</option>
                                        <option value="grupo_empresa" <?= $c_sel=='grupo_empresa'?'selected':'' ?>>Grupo Empresa</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select name="operador[]" class="form-select border-dark shadow-sm" onchange="verificarVazio(this)">
                                        <option value="contem" <?= $o_sel=='contem'?'selected':'' ?>>Contém</option>
                                        <option value="nao_contem" <?= $o_sel=='nao_contem'?'selected':'' ?>>Não contém</option>
                                        <option value="comeca" <?= $o_sel=='comeca'?'selected':'' ?>>Começa com</option>
                                        <option value="igual" <?= $o_sel=='igual'?'selected':'' ?>>Exatamente igual a</option>
                                        <option value="vazio" <?= $o_sel=='vazio'?'selected':'' ?>>É Vazio</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" name="valor[]" class="form-control border-dark shadow-sm input-valor" placeholder="Valor do filtro..." value="<?= htmlspecialchars($v_sel) ?>" <?= $o_sel=='vazio'?'readonly':'' ?>>
                                </div>
                                <div class="col-md-1 text-center">
                                    <button type="button" class="btn btn-outline-danger border-dark btn-sm remover-linha" title="Remover Regra"><i class="fas fa-times"></i></button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>

                        <div class="d-flex justify-content-between mt-3 border-top border-dark pt-3">
                            <button type="button" class="btn btn-sm btn-dark fw-bold shadow-sm" onclick="adicionarFiltro()">
                                <i class="fas fa-plus"></i> Adicionar Regra
                            </button>
                            <div>
                                <a href="cadastro_empresa.php" class="btn btn-sm btn-outline-dark shadow-sm bg-white">Limpar Filtros</a>
                                <button type="submit" class="btn btn-sm btn-info text-dark border-dark fw-bold shadow-sm"><i class="fas fa-search"></i> Executar Filtro</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row justify-content-center mb-4" id="painelNovoCadastro" style="display: none;">
    <div class="col-md-8">
        <form action="" method="POST" class="card border-dark shadow-lg rounded-3">
            <input type="hidden" name="acao_crud" value="novo">
            <div class="card-header bg-dark text-white border-bottom border-dark py-3 d-flex justify-content-between">
                <h5 class="mb-0 fw-bold text-uppercase"><i class="fas fa-building text-warning me-2"></i> Registrar Nova Empresa</h5>
                <button type="button" class="btn-close btn-close-white" onclick="document.getElementById('painelNovoCadastro').style.display='none'"></button>
            </div>
            <div class="card-body bg-light row g-3">
                <div class="col-md-5">
                    <label class="fw-bold">CNPJ <span class="text-danger">*</span></label>
                    <input type="text" name="cnpj" class="form-control border-dark" required maxlength="18" placeholder="Apenas números">
                </div>
                <div class="col-md-7">
                    <label class="fw-bold">Nome da Empresa / Razão Social <span class="text-danger">*</span></label>
                    <input type="text" name="nome_cadastro" class="form-control border-dark" required>
                </div>
                
                <div class="col-md-12 mt-3 mb-2">
                    <div class="p-3 border border-dark rounded bg-white shadow-sm border-start border-4 border-success">
                        <h6 class="fw-bold text-success mb-1"><i class="fas fa-users"></i> Vincular Pessoas Físicas (Opcional)</h6>
                        <small class="text-muted d-block mb-2 fw-bold">Segure a tecla CTRL para selecionar ou desmarcar clientes nesta lista.</small>
                        <select name="cpfs_vinculados[]" class="form-select border-dark border-2" multiple size="4">
                            <?php if(isset($lista_clientes)): ?>
                                <?php foreach($lista_clientes as $cli): ?>
                                    <option value="<?= $cli['CPF'] ?>">
                                        <?= htmlspecialchars($cli['NOME']) ?> (CPF: <?= mascaraCPF($cli['CPF']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="fw-bold">Celular da Empresa</label>
                    <input type="text" name="celular" class="form-control border-dark" maxlength="11" placeholder="Ex: 11999999999">
                </div>
            </div>
            <div class="card-footer bg-white border-top border-dark text-end">
                <button type="submit" class="btn btn-warning fw-bold border-dark text-dark"><i class="fas fa-save me-2"></i> Gravar Empresa no Sistema</button>
            </div>
        </form>
    </div>
</div>

<?php if ((!empty($termo_busca) || $is_busca_avancada) && empty($cnpj_selecionado) && !$erro_banco): ?>
    <div class="row justify-content-center">
        <div class="col-md-10">
            <?php if (!empty($resultados_busca)): ?>
                <div class="card border-dark shadow-lg rounded-3 mb-5">
                    <div class="card-header bg-dark text-white border-bottom border-dark py-3">
                        <h5 class="mb-0 fw-bold text-uppercase"><i class="fas fa-list text-info me-2"></i> Resultados da Busca (Pág <?= $pagina_atual ?>)</h5>
                    </div>
                    <div class="table-responsive bg-white">
                        <table class="table table-hover align-middle mb-0 border-dark text-center fs-6">
                            <thead class="table-dark text-white text-uppercase" style="font-size: 0.85rem;">
                                <tr>
                                    <th>CNPJ</th>
                                    <th class="text-start">Nome da Empresa</th>
                                    <th>Vinculados</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados_busca as $res): ?>
                                    <tr class="border-bottom border-dark">
                                        <td class="fw-bold text-secondary bg-light"><?= mascaraCNPJ($res['CNPJ']) ?></td>
                                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($res['NOME_CADASTRO']) ?></td>
                                        <td><span class="badge bg-success border border-dark"><?= $res['total_clientes'] ?> Cliente(s)</span></td>
                                        <td>
                                            <?php $queryString = $_SERVER['QUERY_STRING']; ?>
                                            <a href="?<?= $queryString ?>&cnpj_selecionado=<?= $res['CNPJ'] ?>&acao=visualizar" class="btn btn-sm btn-warning border-dark text-dark fw-bold"><i class="fas fa-edit"></i> Acessar Ficha</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($resultados_busca)): ?>
                        <div class="card-footer bg-light border-top border-dark pt-3 pb-3">
                            <?php 
                                $params_url = $_GET; unset($params_url['pagina']); 
                                $str_params = http_build_query($params_url);
                                $url_base = '?' . $str_params . '&pagina=';
                            ?>
                            <nav aria-label="Navegação">
                                <ul class="pagination justify-content-center mb-0">
                                    <li class="page-item <?= ($pagina_atual <= 1) ? 'disabled' : '' ?>">
                                        <a class="page-link border-dark text-dark fw-bold" href="<?= $url_base . ($pagina_atual - 1) ?>"><i class="fas fa-chevron-left me-1"></i> Anterior</a>
                                    </li>
                                    <li class="page-item active">
                                        <span class="page-link border-dark bg-dark text-white">Página <?= $pagina_atual ?></span>
                                    </li>
                                    <li class="page-item <?= (!$tem_proxima_pagina) ? 'disabled' : '' ?>">
                                        <a class="page-link border-dark text-dark fw-bold" href="<?= $url_base . ($pagina_atual + 1) ?>">Próximo <i class="fas fa-chevron-right ms-1"></i></a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning text-center p-4 shadow-lg border-dark rounded-3">
                    <h4 class="alert-heading text-dark fw-bold"><i class="fas fa-building text-warning"></i> Nenhuma empresa encontrada com estes filtros.</h4>
                    <button class="btn btn-dark fw-bold mt-3 border-dark shadow-sm" onclick="document.getElementById('painelNovoCadastro').style.display='block'">Cadastrar a Primeira Empresa</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($cnpj_selecionado) && $acao == 'visualizar' && $empresa_ficha && !$erro_banco): ?>
    <div class="row justify-content-center mb-5">
        <div class="col-md-8">
            <div class="card border-dark shadow-lg rounded-3">
                <div class="card-header bg-dark text-white py-3 border-bottom border-dark d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-uppercase"><i class="fas fa-city text-warning me-2"></i> Ficha da Empresa</h5>
                    <?php $urlRetorno = $is_busca_avancada ? '?' . preg_replace('/&?cnpj_selecionado=[^&]*/', '', preg_replace('/&?acao=[^&]*/', '', $_SERVER['QUERY_STRING'])) : '?busca=' . urlencode($termo_busca); ?>
                    <a href="<?= $urlRetorno ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left"></i> Voltar</a>
                </div>
                <div class="card-body bg-light">
                    <form id="formFichaEmpresa" action="acoes_empresa.php" method="POST">
                        <input type="hidden" name="acao_crud" id="acaoCrudEmpresa" value="editar">
                        
                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="fw-bold">CNPJ</label>
                                <input type="text" class="form-control border-dark bg-secondary text-white fw-bold" name="cnpj" value="<?= mascaraCNPJ($empresa_ficha['CNPJ']) ?>" readonly>
                            </div>
                            <div class="col-md-7">
                                <label class="fw-bold">Nome / Razão Social</label>
                                <input type="text" class="form-control border-dark" name="nome_cadastro" value="<?= htmlspecialchars($empresa_ficha['NOME_CADASTRO']) ?>" maxlength="150" required>
                            </div>
                            
                            <div class="col-md-12 mt-4 mb-2">
                                <div class="p-3 border border-dark rounded bg-white shadow-sm border-start border-4 border-success">
                                    <h6 class="fw-bold text-success mb-1"><i class="fas fa-users"></i> Pessoas Físicas Vinculadas</h6>
                                    <small class="text-muted d-block mb-2 fw-bold">Segure CTRL para selecionar ou desmarcar clientes nesta lista.</small>
                                    <select name="cpfs_vinculados[]" class="form-select border-dark border-2" multiple size="5">
                                        <?php if(isset($lista_clientes)): ?>
                                            <?php foreach($lista_clientes as $cli): ?>
                                                <option value="<?= $cli['CPF'] ?>" <?= in_array($cli['CPF'], $cpfs_vinculados_ficha) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($cli['NOME']) ?> (CPF: <?= mascaraCPF($cli['CPF']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="fw-bold">Celular</label>
                                <input type="text" class="form-control border-dark" name="celular" value="<?= htmlspecialchars($empresa_ficha['CELULAR']) ?>" maxlength="11">
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">Grupo Whats</label>
                                <input type="text" class="form-control border-dark" name="grupo_whats" value="<?= htmlspecialchars($empresa_ficha['GRUPO_WHATS'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">Grupo Empresa</label>
                                <input type="text" class="form-control border-dark" name="grupo_empresa" value="<?= htmlspecialchars($empresa_ficha['GRUPO_EMPRESA'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mt-4 pt-3 border-top border-dark d-flex justify-content-between">
                            <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" onclick="confirmarAcaoEmpresa('excluir', 'Tem certeza que deseja APAGAR os dados desta empresa?')">
                                <i class="fas fa-trash"></i> Excluir
                            </button>
                            <button type="button" class="btn btn-warning fw-bold border-dark shadow-sm text-dark px-4" onclick="confirmarAcaoEmpresa('editar', 'Confirma as alterações realizadas nos dados?')">
                                <i class="fas fa-save"></i> Atualizar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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

document.getElementById('areaFiltros').addEventListener('click', function(e) {
    if (e.target.closest('.remover-linha')) {
        const area = document.getElementById('areaFiltros');
        if (area.querySelectorAll('.linha-filtro').length > 1) {
            e.target.closest('.linha-filtro').remove();
        } else {
            crmToast("Você precisa ter pelo menos uma regra de filtro!", "warning", 5000);
        }
    }
});

function confirmarAcaoEmpresa(acao, mensagem) {
    if (confirm(mensagem)) {
        document.getElementById('acaoCrudEmpresa').value = acao;
        document.getElementById('formFichaEmpresa').submit();
    }
}
</script>

</div> <?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>