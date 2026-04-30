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

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) {
    include_once $caminho_permissoes;
}

// Variáveis de fluxo e busca
$termo_busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$cpf_selecionado = isset($_GET['cpf_selecionado']) ? trim($_GET['cpf_selecionado']) : '';
// Filtro de situação: padrão ATIVO ao entrar na página sem busca
$filtro_situacao = $_GET['situacao'] ?? 'ATIVO';
$is_busca_avancada = isset($_GET['busca_avancada']) && $_GET['busca_avancada'] == '1';

// CONSULTOR não pode criar novos clientes
$pode_ver_todos = verificaPermissao($pdo, 'FUNCAO_CADASTRO_CLIENTE_MEU_CPF', 'FUNCAO');

// ✨ REGRA APLICADA: Se for bloqueado, ele não poderá excluir ou editar a ficha
$pode_editar_excluir = verificaPermissao($pdo, 'FUNCAO_CADASTRO_CLIENTE_EDITAR_EXCLUIR', 'FUNCAO');
$bloquear_pedido_tarefas = !verificaPermissao($pdo, 'FUNCAO_CADASTRO_CLIENTE_PEDIDO_PRODUTOS', 'FUNCAO');
$pode_ver_financeiro = verificaPermissao($pdo, 'SUBMENU_CADASTRO_USUARIO_FINANCEIRO_VER_MEU_CLIENTE', 'FUNCAO');

// Hierarquia por empresa: SUPERVISORES e CONSULTOR só veem clientes da sua empresa
$grupo_sessao = strtoupper($_SESSION['usuario_grupo'] ?? '');
$eh_hierarquia = !in_array($grupo_sessao, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
$id_empresa_logado = null;
if ($eh_hierarquia) {
    try {
        $cpf_logado_limpo = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
        $stmtEmpL = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
        $stmtEmpL->execute([$cpf_logado_limpo]);
        $id_empresa_logado = $stmtEmpL->fetchColumn() ?: null;
    } catch (\Throwable $e) { $id_empresa_logado = null; }
}

// Arrays da Busca Avançada
$filtros_campo = isset($_GET['campo']) ? $_GET['campo'] : [];
$filtros_operador = isset($_GET['operador']) ? $_GET['operador'] : [];
$filtros_valor = isset($_GET['valor']) ? $_GET['valor'] : [];

// Paginação (Limite de 20 por página conforme regra)
$pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limites_por_pagina = 20; 
$offset = ($pagina_atual - 1) * $limites_por_pagina; 

$resultados_busca = [];
$cliente_ficha = null;
$dados_acesso = null; 
$mensagem_alerta = '';
$erro_banco = null; 

function mascaraCPF($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
}

function mapearColunaCliente($campo) {
    $mapa = [
        // ── Dados do Cliente (CLIENTE_CADASTRO) ──
        'cpf'            => 'c.CPF',
        'nome'           => 'c.NOME',
        'celular'        => 'c.CELULAR',
        'cnpj'           => 'c.CNPJ',
        'banco_nome'     => 'c.BANCO_NOME',
        'chave_pix'      => 'c.CHAVE_PIX',
        'grupo_whats'    => 'c.GRUPO_WHATS',
        'grupo_assessor' => 'c.GRUPO_ASSESSOR',
        'situacao'       => 'c.SITUACAO',
        // ── Dados do Usuário (CLIENTE_USUARIO) ──
        'u_nome'         => 'u.NOME',
        'u_usuario'      => 'u.USUARIO',
        'u_email'        => 'u.EMAIL',
        'u_grupo'        => 'u.GRUPO_USUARIOS',
        'u_situacao'     => 'u.Situação',
        'u_ultimo_acesso'=> 'u.ULTIMO_ACESSO',
    ];
    return isset($mapa[$campo]) ? $mapa[$campo] : 'c.NOME';
}

// SISTEMA DE PARA-QUEDAS PARA O BANCO DE DADOS
try {
    // REGRA DE NEGÓCIO: CRIAR CLIENTE E USUÁRIO SIMULTANEAMENTE
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_crud']) && $_POST['acao_crud'] == 'novo' && $pode_ver_todos) {
        $cpf_novo = str_pad(preg_replace('/[^0-9]/', '', $_POST['cpf']), 11, '0', STR_PAD_LEFT);
        $nome = $_POST['nome'];
        $celular = preg_replace('/[^0-9]/', '', $_POST['celular']);
        $email = $_POST['email'] ?? '';
        
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM CLIENTE_CADASTRO WHERE CPF = :cpf");
        $stmtCheck->execute(['cpf' => $cpf_novo]);
        if ($stmtCheck->fetchColumn() > 0) {
            $mensagem_alerta = "<div class='alert alert-danger fw-bold'>ERRO: Este CPF já está cadastrado no sistema!</div>";
        } else {
            $pdo->beginTransaction();
            $stmt1 = $pdo->prepare("INSERT INTO CLIENTE_CADASTRO (CPF, NOME, CELULAR, SITUACAO, CUSTO_CONSULTA, SALDO) VALUES (:cpf, :nome, :celular, 'ATIVO', 0.01, 50.00)");
            $stmt1->execute(['cpf' => $cpf_novo, 'nome' => $nome, 'celular' => $celular]);

            // Registra o crédito inicial de R$ 50,00 no extrato financeiro
            $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, 'CREDITO', 'Saldo inicial do cadastro', 50.00, 0.00, 50.00)")
                ->execute([$cpf_novo]);

            $stmt2 = $pdo->prepare("INSERT INTO CLIENTE_USUARIO (CPF, NOME, CELULAR, EMAIL, Tentativas, Situação) VALUES (:cpf, :nome, :celular, :email, 0, 'ativo')");
            $stmt2->execute(['cpf' => $cpf_novo, 'nome' => $nome, 'celular' => $celular, 'email' => $email]);
            $pdo->commit();
            $mensagem_alerta = "<div class='alert alert-success fw-bold'>Cadastro criado com sucesso! O perfil de Usuário também foi gerado.</div>";
        }
    }

    // ==========================================
    // 1. LÓGICA DE BUSCA SIMPLES E AVANÇADA OTIMIZADA
    // ==========================================
    $tem_proxima_pagina = false;
    
    {
        $filtro_sql = ""; $params = []; $contador_params = 1;

        if (!$is_busca_avancada && !empty($termo_busca) && empty($cpf_selecionado)) {
            $termo_limpo_num = preg_replace('/[^0-9]/', '', $termo_busca);

            // Inclui u.NOME (nome do usuário) e u.EMAIL na busca simples
            $filtro_sql .= " AND (c.NOME LIKE :termo OR c.BANCO_NOME LIKE :termo OR c.NOME_EMPRESA LIKE :termo OR e.NOME_CADASTRO LIKE :termo OR u.NOME LIKE :termo OR u.EMAIL LIKE :termo ";
            $params[':termo'] = "%$termo_busca%";

            if (!empty($termo_limpo_num)) {
                $filtro_sql .= " OR c.CPF LIKE :cpf OR c.CELULAR LIKE :celular OR c.CNPJ LIKE :cnpj ";
                $params[':cpf']    = "$termo_limpo_num%";
                $params[':celular'] = "$termo_limpo_num%";
                $params[':cnpj']   = "$termo_limpo_num%";
            }
            $filtro_sql .= ") ";
        } elseif ($is_busca_avancada && empty($cpf_selecionado)) {
            for ($i = 0; $i < count($filtros_campo); $i++) {
                $campo_html = $filtros_campo[$i]; $operador = $filtros_operador[$i]; $valor_bruto = trim($filtros_valor[$i]);
                if ($valor_bruto === '' && $operador != 'vazio') continue; 

                $coluna_db = mapearColunaCliente($campo_html);
                if ($operador == 'vazio') { $filtro_sql .= " AND ($coluna_db IS NULL OR trim($coluna_db) = '') "; continue; }

                $valores_array = explode(';', $valor_bruto); $condicoes_or = [];
                foreach ($valores_array as $val) {
                    $val = trim($val); if ($val === '') continue;
                    if ($campo_html == 'cpf') { $val = str_pad(preg_replace('/[^0-9]/', '', $val), 11, '0', STR_PAD_LEFT); } 
                    elseif (in_array($campo_html, ['celular', 'cnpj'])) { $val = preg_replace('/[^0-9]/', '', $val); }

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

        // Mostra resultados: ao entrar na página (sem busca) exibe ATIVO por padrão
        $executar_busca = (!empty($termo_busca) || $is_busca_avancada || empty($cpf_selecionado));
        if ($executar_busca && empty($cpf_selecionado)) {
            // Filtro de situação
            if ($filtro_situacao && $filtro_situacao !== 'TODOS') {
                $filtro_sql .= " AND c.SITUACAO = :situacao_filtro ";
                $params[':situacao_filtro'] = $filtro_situacao;
            }

            // Hierarquia por empresa: filtra lista para SUPERVISORES e CONSULTOR
            if ($eh_hierarquia) {
                if ($id_empresa_logado) {
                    $filtro_sql .= " AND u.id_empresa = :id_empresa_logado";
                    $params[':id_empresa_logado'] = $id_empresa_logado;
                } else {
                    $filtro_sql .= " AND 1=0";
                }
            }

            $sql_base = " FROM CLIENTE_CADASTRO c LEFT JOIN CLIENTE_USUARIO u ON c.CPF = u.CPF LEFT JOIN CLIENTE_EMPRESAS e ON c.CNPJ = e.CNPJ WHERE 1=1 " . $filtro_sql;
            $limite_busca = $limites_por_pagina + 1;
            $sql_dados = "SELECT c.*, u.EMAIL, u.NOME as NOME_USUARIO, u.USUARIO as LOGIN_USUARIO, u.GRUPO_USUARIOS, e.NOME_CADASTRO as NOME_EMPRESA_VINCULADA " . $sql_base . " ORDER BY c.NOME ASC LIMIT " . (int)$limite_busca . " OFFSET " . (int)$offset;
            $stmt = $pdo->prepare($sql_dados);
            $stmt->execute($params);
            $resultados_busca = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($resultados_busca) > $limites_por_pagina) {
                $tem_proxima_pagina = true;
                array_pop($resultados_busca);
            }
        }
    }

    // ==========================================
    // 3. CARREGAR FICHA E DADOS DE ACESSO
    // ==========================================
    if (!empty($cpf_selecionado) && in_array($acao, ['visualizar', 'editar'])) {
        
        // ✨ LÓGICA DE BUSCA TRIPLA BLINDADA ✨
        $cpf_cru = urldecode($cpf_selecionado);
        $cpf_so_numeros = preg_replace('/[^0-9]/', '', $cpf_selecionado);
        $cpf_com_11_digitos = str_pad($cpf_so_numeros, 11, '0', STR_PAD_LEFT);
        
        // Modificado para trazer o E-mail junto na ficha
        $stmt = $pdo->prepare("SELECT c.*, u.EMAIL FROM CLIENTE_CADASTRO c LEFT JOIN CLIENTE_USUARIO u ON c.CPF = u.CPF WHERE c.CPF = :cpf1 OR c.CPF = :cpf2 OR c.CPF = :cpf3 LIMIT 1");
        $stmt->execute([
            'cpf1' => $cpf_cru,
            'cpf2' => $cpf_so_numeros,
            'cpf3' => $cpf_com_11_digitos
        ]);
        
        $cliente_ficha = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $stmtEmp = $pdo->query("SELECT CNPJ, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC");
        $lista_empresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

        if ($cliente_ficha) {
            $stmtLogin = $pdo->prepare("SELECT USUARIO, SENHA, Situação FROM CLIENTE_USUARIO WHERE CPF = :cpf");
            $stmtLogin->execute(['cpf' => $cliente_ficha['CPF']]);
            $dados_acesso = $stmtLogin->fetch(PDO::FETCH_ASSOC);
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

// Atributo global para as caixas de formulário se o cara estiver bloqueado
$readonly_attr = (!$pode_editar_excluir) ? 'disabled readonly' : '';
?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-success fw-bold"><i class="fas fa-user me-2"></i> Cadastro de Clientes</h2>
        <p class="text-muted">Busque clientes, aplique filtros ou crie um novo registro.</p>
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

<?php if ($pode_ver_todos || $eh_hierarquia): ?>
<div class="row justify-content-center mb-2">
    <div class="col-md-8">
        <form action="" method="GET" class="d-flex shadow-sm mb-2 flex-wrap gap-2" id="formBuscaPrincipal">
            <input type="text" name="busca" class="form-control form-control-lg border-success flex-grow-1" placeholder="Pesquisar por Nome, CPF, Celular, Empresa, CNPJ..." value="<?= $is_busca_avancada ? '' : htmlspecialchars($termo_busca) ?>" <?= $is_busca_avancada ? 'disabled' : 'autofocus' ?> style="min-width:200px;">
            <select name="situacao" class="form-select form-select-lg border-success fw-bold" style="max-width:150px;">
                <option value="ATIVO"   <?= $filtro_situacao === 'ATIVO'   ? 'selected' : '' ?>>Ativos</option>
                <option value="INATIVO" <?= $filtro_situacao === 'INATIVO' ? 'selected' : '' ?>>Inativos</option>
                <option value="TODOS"   <?= $filtro_situacao === 'TODOS'   ? 'selected' : '' ?>>Todos</option>
            </select>
            <button type="submit" class="btn btn-success btn-lg px-4 fw-bold text-dark border-dark" <?= $is_busca_avancada ? 'disabled' : '' ?>><i class="fas fa-search"></i> Buscar</button>
            <?php if ($pode_ver_todos): ?>
            <button type="button" class="btn btn-dark btn-lg px-4 fw-bold shadow-sm border-dark" onclick="abrirCadastro()"><i class="fas fa-plus text-info"></i> Novo</button>
            <button type="button" class="btn btn-primary btn-lg px-4 fw-bold shadow-sm border-dark" data-bs-toggle="modal" data-bs-target="#modalImportacao"><i class="fas fa-file-import"></i> Importar</button>
            <?php endif; ?>
        </form>

        <div class="d-flex justify-content-end gap-2">
            <div class="dropdown">
                <button class="btn btn-sm btn-warning fw-bold border-dark dropdown-toggle" type="button" id="btnAcaoLote" data-bs-toggle="dropdown">
                    <i class="fas fa-tasks me-1"></i> Ação em Lote
                </button>
                <ul class="dropdown-menu border-dark shadow">
                    <li><a class="dropdown-item fw-bold text-success" href="#" onclick="acaoLote('ativar')"><i class="fas fa-check-circle me-2"></i>Ativar Selecionados</a></li>
                    <li><a class="dropdown-item fw-bold text-danger" href="#" onclick="acaoLote('inativar')"><i class="fas fa-ban me-2"></i>Inativar Selecionados</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item fw-bold text-primary" href="#" onclick="exportarClientes()"><i class="fas fa-file-excel me-2"></i>Exportar Lista (CSV)</a></li>
                </ul>
            </div>
            <button class="btn btn-sm btn-outline-secondary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="<?= $is_busca_avancada ? 'true' : 'false' ?>">
                <i class="fas fa-sliders-h"></i> Filtro Aprimorado
            </button>
        </div>

        <div class="collapse mt-3 <?= $is_busca_avancada ? 'show' : '' ?>" id="painelBuscaAvancada">
            <div class="card border-dark shadow-lg rounded-3 overflow-hidden">
                <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase">
                    <i class="fas fa-filter text-success me-2"></i> Montador de Filtros (Clientes)
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
                                        <optgroup label="── Dados do Cliente ──">
                                            <option value="nome"           <?= $c_sel=='nome'?'selected':'' ?>>Nome</option>
                                            <option value="cpf"            <?= $c_sel=='cpf'?'selected':'' ?>>CPF</option>
                                            <option value="celular"        <?= $c_sel=='celular'?'selected':'' ?>>Celular</option>
                                            <option value="cnpj"           <?= $c_sel=='cnpj'?'selected':'' ?>>CNPJ Vinculado</option>
                                            <option value="banco_nome"     <?= $c_sel=='banco_nome'?'selected':'' ?>>Banco</option>
                                            <option value="chave_pix"      <?= $c_sel=='chave_pix'?'selected':'' ?>>Chave PIX</option>
                                            <option value="grupo_whats"    <?= $c_sel=='grupo_whats'?'selected':'' ?>>Grupo Whats</option>
                                            <option value="grupo_assessor" <?= $c_sel=='grupo_assessor'?'selected':'' ?>>Grupo Assessor</option>
                                            <option value="situacao"       <?= $c_sel=='situacao'?'selected':'' ?>>Situação</option>
                                        </optgroup>
                                        <optgroup label="── Dados do Usuário ──">
                                            <option value="u_nome"          <?= $c_sel=='u_nome'?'selected':'' ?>>Nome do Usuário</option>
                                            <option value="u_usuario"       <?= $c_sel=='u_usuario'?'selected':'' ?>>Login</option>
                                            <option value="u_email"         <?= $c_sel=='u_email'?'selected':'' ?>>E-mail</option>
                                            <option value="u_grupo"         <?= $c_sel=='u_grupo'?'selected':'' ?>>Grupo / Perfil</option>
                                            <option value="u_situacao"      <?= $c_sel=='u_situacao'?'selected':'' ?>>Status da Conta</option>
                                            <option value="u_ultimo_acesso" <?= $c_sel=='u_ultimo_acesso'?'selected':'' ?>>Último Acesso</option>
                                        </optgroup>
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
                                <a href="cadastro_cliente.php" class="btn btn-sm btn-outline-dark shadow-sm bg-white">Limpar Filtros</a>
                                <button type="submit" class="btn btn-sm btn-info text-dark border-dark fw-bold shadow-sm"><i class="fas fa-search"></i> Executar Filtro</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalImportacao" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-primary text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-file-csv me-2"></i>Importar Clientes</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-info border-dark small fw-bold shadow-sm">
                    <i class="fas fa-info-circle"></i> O arquivo deve ser um <b>.CSV</b> (separado por vírgula ou ponto e vírgula).<br>
                    <hr class="my-2 border-dark">
                    <b>A ORDEM DAS COLUNAS DEVE SER:</b><br>
                    1. Nome Completo<br>
                    2. CPF<br>
                    3. Celular<br>
                    4. E-mail (Opcional)<br>
                    <i>Obs: A primeira linha será ignorada (Pois é o cabeçalho).</i>
                </div>
                <form id="formImportacao" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="fw-bold small text-dark mb-1">Selecione o arquivo CSV do seu computador:</label>
                        <input type="file" name="arquivo_csv" id="arquivo_csv" class="form-control border-dark border-2 bg-white" accept=".csv" required>
                    </div>
                    <div id="resultadoImportacao" class="mt-3"></div>
            </div>
            <div class="modal-footer bg-white border-top border-dark">
                <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold border-dark shadow-sm text-dark" id="btnImportarLoad"><i class="fas fa-upload me-2"></i> Iniciar Importação</button>
            </div>
            </form>
        </div>
    </div>
</div>

<div class="row justify-content-center mb-4" id="painelNovoCadastro" style="display: none;">
    <div class="col-md-8">
        <form action="" method="POST" class="card border-dark shadow-lg rounded-3">
            <input type="hidden" name="acao_crud" value="novo">
            <div class="card-header bg-dark text-white border-bottom border-dark py-3 d-flex justify-content-between">
                <h5 class="mb-0 fw-bold text-uppercase"><i class="fas fa-user-plus text-info me-2"></i> Adicionar Novo Cliente</h5>
                <button type="button" class="btn-close btn-close-white" onclick="fecharCadastro()"></button>
            </div>
            <div class="card-body bg-light row g-3">
                <div class="col-md-4">
                    <label class="fw-bold">CPF <span class="text-danger">*</span></label>
                    <input type="text" name="cpf" class="form-control border-dark" required maxlength="14" placeholder="Apenas números">
                </div>
                <div class="col-md-8">
                    <label class="fw-bold">Nome Completo <span class="text-danger">*</span></label>
                    <input type="text" name="nome" class="form-control border-dark" required>
                </div>
                <div class="col-md-6">
                    <label class="fw-bold">Celular <span class="text-danger">*</span></label>
                    <input type="text" name="celular" class="form-control border-dark" required maxlength="11" placeholder="Ex: 11999999999">
                </div>
                <div class="col-md-6">
                    <label class="fw-bold">E-mail (Para o Usuário)</label>
                    <input type="email" name="email" class="form-control border-dark">
                </div>
            </div>
            <div class="card-footer bg-white border-top border-dark text-end">
                <button type="submit" class="btn btn-success fw-bold border-dark text-dark"><i class="fas fa-save me-2"></i> Gravar e Gerar Usuário</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (empty($cpf_selecionado) && !$erro_banco && ($pode_ver_todos || $eh_hierarquia)): ?>
    <div class="row justify-content-center" id="painelResultados">
        <div class="col-md-10">
            <?php if (!empty($resultados_busca)): ?>
                <div class="card border-dark shadow-lg rounded-3 mb-5">
                    <div class="card-header bg-dark text-white border-bottom border-dark py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-uppercase"><i class="fas fa-list text-info me-2"></i> Resultados da Busca (Pág <?= $pagina_atual ?>)</h5>
                    </div>
                    <div class="table-responsive bg-white">
                        <table class="table table-hover align-middle mb-0 border-dark text-center fs-6" id="tabelaClientes">
                            <thead class="table-dark text-white text-uppercase" style="font-size: 0.85rem;">
                                <tr>
                                    <th style="width:40px;"><input type="checkbox" id="selectAll" title="Selecionar todos" onclick="toggleSelectAll(this)" class="form-check-input border-light"></th>
                                    <th style="width: 60px;">ID</th>
                                    <th>CPF</th>
                                    <th class="text-start">Nome do Cliente</th>
                                    <th>E-mail</th>
                                    <th>Usuário</th>
                                    <th>Empresa</th>
                                    <th>Celular</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados_busca as $res):
                                    $sit = $res['SITUACAO'] ?? 'ATIVO';
                                    $sit_badge = $sit === 'ATIVO' ? 'bg-success' : 'bg-secondary';
                                ?>
                                    <tr class="border-bottom border-dark <?= $sit === 'INATIVO' ? 'table-secondary opacity-75' : '' ?>">
                                        <td><input type="checkbox" class="form-check-input cb-cliente border-dark" value="<?= htmlspecialchars($res['CPF']) ?>"></td>
                                        <td class="fw-bold text-danger">#<?= htmlspecialchars($res['ID'] ?? '') ?></td>
                                        <td class="fw-bold text-secondary bg-light"><?= mascaraCPF($res['CPF']) ?></td>
                                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($res['NOME']) ?></td>
                                        <td><?= htmlspecialchars($res['EMAIL'] ?? '--') ?></td>
                                        <td class="text-secondary"><?= htmlspecialchars($res['LOGIN_USUARIO'] ?? '--') ?></td>
                                        <td class="text-secondary"><?= htmlspecialchars($res['NOME_EMPRESA_VINCULADA'] ?? '--') ?></td>
                                        <td><?= htmlspecialchars($res['CELULAR']) ?></td>
                                        <td><span class="badge <?= $sit_badge ?>"><?= $sit ?></span></td>
                                        <td>
                                            <?php
                                            $url_params = $_GET;
                                            $url_params['cpf_selecionado'] = trim($res['CPF']);
                                            $url_params['acao'] = 'visualizar';
                                            $link_editar = '?' . http_build_query($url_params);
                                            ?>
                                            <a href="<?= $link_editar ?>" class="btn btn-sm btn-success border-dark text-dark fw-bold"><i class="fas fa-eye"></i> Ficha</a>
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
                    <h4 class="alert-heading text-dark fw-bold"><i class="fas fa-search text-warning"></i> Cliente não encontrado.</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($cpf_selecionado) && $acao == 'visualizar' && !$erro_banco): ?>
    <div class="row justify-content-center mb-5">
        <div class="col-md-10">
            <?php if (!$cliente_ficha): ?>
                <div class="alert alert-danger shadow-lg border-dark text-center p-4 rounded-3">
                    <h4 class="fw-bold mb-3"><i class="fas fa-exclamation-triangle"></i> Falha ao Carregar a Ficha</h4>
                    <p class="mb-0">Não foi possível localizar o cliente com o CPF informado no banco de dados.</p>
                    
                    <?php if($pode_ver_todos || $eh_hierarquia): ?>
                        <a href="cadastro_cliente.php" class="btn btn-dark fw-bold mt-3"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
            <div class="card border-dark shadow-lg rounded-3">
                <div class="card-header bg-dark text-white py-3 border-bottom border-dark d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-uppercase"><i class="fas fa-address-book text-success me-2"></i> Ficha Cadastral (ID: <?= htmlspecialchars($cliente_ficha['ID']) ?>)</h5>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <?php if (!$bloquear_pedido_tarefas): ?>
                        <a href="/modulos/comercial/pedidos/index.php?pre_cliente=<?= urlencode($cliente_ficha['NOME']) ?>&pre_tel=<?= urlencode($cliente_ficha['CELULAR'] ?? '') ?>" class="btn btn-sm btn-warning fw-bold text-dark">
                            <i class="fas fa-shopping-cart me-1"></i> Pedido
                        </a>
                        <button class="btn btn-sm btn-info fw-bold text-dark" onclick="abrirModalTarefas('<?= htmlspecialchars($cliente_ficha['CPF']) ?>','<?= htmlspecialchars($cliente_ficha['NOME']) ?>')">
                            <i class="fas fa-tasks me-1"></i> Tarefas
                        </button>
                        <?php endif; ?>
                        <?php if ($pode_ver_financeiro): ?>
                        <a href="cadastro_cliente_financeiro.php?cpf=<?= urlencode($cliente_ficha['CPF']) ?>" class="btn btn-sm btn-success fw-bold">
                            <i class="fas fa-wallet me-1"></i> Financeiro
                        </a>
                        <?php endif; ?>
                        <?php if($pode_ver_todos || $eh_hierarquia): ?>
                            <?php $urlRetorno = $is_busca_avancada ? '?' . preg_replace('/&?cpf_selecionado=[^&]*/', '', preg_replace('/&?acao=[^&]*/', '', $_SERVER['QUERY_STRING'])) : '?busca=' . urlencode($termo_busca); ?>
                            <a href="<?= $urlRetorno ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left"></i> Voltar</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body bg-light">
                    
                    <?php if(!$pode_editar_excluir): ?>
                        <div class="alert alert-warning border-dark py-2 small fw-bold mb-3">
                            <i class="fas fa-lock"></i> Visualização Protegida: Seu usuário não tem permissão para editar ou excluir dados desta ficha.
                        </div>
                    <?php endif; ?>

                    <form id="formFichaCliente" action="acoes_cliente.php" method="POST">
                        <input type="hidden" name="acao_crud" id="acaoCrudCliente" value="editar">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="fw-bold">CPF</label>
                                <input type="hidden" name="cpf" value="<?= htmlspecialchars($cliente_ficha['CPF']) ?>">
                                <input type="text" class="form-control border-dark bg-secondary text-white" value="<?= mascaraCPF($cliente_ficha['CPF']) ?>" readonly disabled>
                            </div>
                            <div class="col-md-5">
                                <label class="fw-bold">Nome Completo</label>
                                <input type="text" class="form-control border-dark" name="nome" value="<?= htmlspecialchars($cliente_ficha['NOME']) ?>" required <?= $readonly_attr ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">Celular</label>
                                <input type="text" class="form-control border-dark" name="celular" value="<?= htmlspecialchars($cliente_ficha['CELULAR']) ?>" required maxlength="11" <?= $readonly_attr ?>>
                            </div>
                            
                            <div class="col-md-12">
                                <label class="fw-bold">E-mail</label>
                                <input type="email" class="form-control border-dark" name="email" value="<?= htmlspecialchars($cliente_ficha['EMAIL'] ?? '') ?>" <?= $readonly_attr ?>>
                            </div>

                            <?php
                            // Carrega dados completos do usuário para o bloco unificado
                            $dados_usuario_completo = [];
                            if (!empty($cliente_ficha['CPF'])) {
                                $stmtU = $pdo->prepare("SELECT USUARIO, SENHA, GRUPO_USUARIOS, Situação, DATA_EXPIRAR, ULTIMO_ACESSO, FORCAR_LOGOUT FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
                                $stmtU->execute([$cliente_ficha['CPF']]);
                                $dados_usuario_completo = $stmtU->fetch(PDO::FETCH_ASSOC) ?: [];
                            }
                            $pode_ver_senha = verificaPermissao($pdo, 'CADASTRO_CLEINTE_SENHA_VER', 'FUNCAO');
                            $senha_val = $dados_usuario_completo['SENHA'] ?? '';
                            ?>

                            <!-- EMPRESA VINCULADA — retrátil, começa recolhida -->
                            <div class="col-md-12 mt-4">
                                <div class="border border-dark rounded bg-white shadow-sm overflow-hidden">
                                    <button type="button" class="btn btn-light w-100 text-start fw-bold py-2 px-3 border-0 d-flex justify-content-between align-items-center"
                                        data-bs-toggle="collapse" data-bs-target="#collapseEmpresa" style="border-left:5px solid #ffc107 !important;">
                                        <span><i class="fas fa-building me-2 text-warning"></i> Empresa Vinculada
                                            <?php if(empty($cliente_ficha['CNPJ'])): ?>
                                                <span class="badge bg-danger ms-2 small">Sem vínculo</span>
                                            <?php else: ?>
                                                <span class="badge bg-success ms-2 small"><?= htmlspecialchars($lista_empresas[array_search($cliente_ficha['CNPJ'], array_column($lista_empresas,'CNPJ'))] ['NOME_CADASTRO'] ?? $cliente_ficha['CNPJ']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <i class="fas fa-chevron-down small"></i>
                                    </button>
                                    <div class="collapse" id="collapseEmpresa">
                                        <div class="p-3 border-top border-dark">
                                            <?php if(empty($cliente_ficha['CNPJ'])): ?>
                                                <p class="text-danger small fw-bold mb-2"><i class="fas fa-exclamation-circle"></i> Este cliente ainda não possui empresa atrelada.</p>
                                            <?php endif; ?>
                                            <?php
                                            $emp_atual_nome = '';
                                            if (!empty($cliente_ficha['CNPJ']) && isset($lista_empresas)) {
                                                foreach($lista_empresas as $emp) {
                                                    if ($emp['CNPJ'] === $cliente_ficha['CNPJ']) { $emp_atual_nome = $emp['NOME_CADASTRO'] . ' (CNPJ: ' . $emp['CNPJ'] . ')'; break; }
                                                }
                                            }
                                            ?>
                                            <!-- Busca de empresa com pesquisa ao digitar -->
                                            <input type="hidden" name="cnpj_vinculado" id="cnpjVinculadoHidden" value="<?= htmlspecialchars($cliente_ficha['CNPJ'] ?? '') ?>">
                                            <div class="position-relative" id="empresaBuscaWrapper">
                                                <input type="text" id="empresaBuscaInput" class="form-control border-dark"
                                                    placeholder="Digite para pesquisar empresa..."
                                                    value="<?= htmlspecialchars($emp_atual_nome) ?>"
                                                    <?= $readonly_attr ?> autocomplete="off"
                                                    oninput="filtrarEmpresas(this.value)">
                                                <div id="empresaSugestoes" class="list-group position-absolute w-100 shadow-lg border border-dark" style="z-index:9999;max-height:220px;overflow-y:auto;display:none;top:100%;"></div>
                                            </div>
                                            <div class="mt-2 d-flex gap-2">
                                                <button type="button" class="btn btn-sm btn-outline-danger rounded-0" onclick="limparEmpresa()"><i class="fas fa-times me-1"></i>Remover vínculo</button>
                                                <button type="button" class="btn btn-sm btn-outline-primary rounded-0" onclick="editarEmpresaAtual()"><i class="fas fa-edit me-1"></i>Editar Empresa</button>
                                                <button type="button" class="btn btn-sm btn-outline-success rounded-0" onclick="abrirModalEmpresa('nova')"><i class="fas fa-plus me-1"></i>Nova Empresa</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- CREDENCIAIS DE ACESSO — retrátil, começa recolhida -->
                            <div class="col-md-12 mt-3">
                                <div class="border border-dark rounded overflow-hidden" style="background-color:#e3f2fd;">
                                    <button type="button" class="btn w-100 text-start fw-bold py-2 px-3 border-0 d-flex justify-content-between align-items-center"
                                        data-bs-toggle="collapse" data-bs-target="#collapseCredenciais" style="background-color:#e3f2fd; border-left:5px solid #0d6efd !important;">
                                        <span><i class="fas fa-key me-2 text-primary"></i> Credenciais de Acesso ao Sistema
                                            <?php $sit_us = $dados_usuario_completo['Situação'] ?? 'NÃO REGISTRADO'; ?>
                                            <span class="badge <?= $sit_us === 'ativo' ? 'bg-success' : 'bg-secondary' ?> ms-2 small"><?= strtoupper($sit_us) ?></span>
                                        </span>
                                        <i class="fas fa-chevron-down small text-primary"></i>
                                    </button>
                                    <div class="collapse" id="collapseCredenciais">
                                        <div class="p-3 border-top border-primary">
                                            <div class="row g-2">
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Login / Usuário:</label>
                                                    <input type="text" class="form-control border-primary bg-white fw-bold" value="<?= !empty($dados_usuario_completo['USUARIO']) ? htmlspecialchars($dados_usuario_completo['USUARIO']) : 'Ainda não criado' ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Senha atual:</label>
                                                    <div class="input-group">
                                                        <input type="password" id="campoSenhaAtual" class="form-control border-primary bg-white"
                                                            value="<?= $pode_ver_senha ? htmlspecialchars($senha_val) : '***' ?>"
                                                            <?= $pode_ver_senha ? 'data-permitido="1"' : '' ?> readonly>
                                                        <?php if ($pode_ver_senha): ?>
                                                        <button type="button" class="btn btn-outline-primary" onclick="toggleVerSenha()" title="Ver/ocultar senha"><i class="fas fa-eye" id="iconeSenha"></i></button>
                                                        <?php else: ?>
                                                        <span class="input-group-text bg-secondary text-white border-secondary" title="Sem permissão para ver a senha"><i class="fas fa-lock"></i></span>
                                                        <?php endif; ?>
                                                        <?php if ($pode_editar_excluir): ?>
                                                        <button type="button" class="btn btn-outline-warning" onclick="gerarNovaSenha('<?= htmlspecialchars($cliente_ficha['CPF']) ?>')" title="Gerar nova senha"><i class="fas fa-sync-alt"></i></button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Status da Conta:</label>
                                                    <?php $cor_status = ($sit_us == 'ativo') ? 'success' : (($sit_us == 'NÃO REGISTRADO') ? 'secondary' : 'danger'); ?>
                                                    <div class="form-control border-primary bg-white text-<?= $cor_status ?> fw-bold text-uppercase">
                                                        <i class="fas fa-circle me-1 small"></i> <?= $sit_us ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Grupo / Perfil:</label>
                                                    <input type="text" class="form-control border-primary bg-white" value="<?= htmlspecialchars($dados_usuario_completo['GRUPO_USUARIOS'] ?? '--') ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Expira em:</label>
                                                    <input type="text" class="form-control border-primary bg-white" value="<?= !empty($dados_usuario_completo['DATA_EXPIRAR']) ? date('d/m/Y', strtotime($dados_usuario_completo['DATA_EXPIRAR'])) : '--' ?>" readonly>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="fw-bold small text-dark">Último acesso:</label>
                                                    <input type="text" class="form-control border-primary bg-white" value="<?= !empty($dados_usuario_completo['ULTIMO_ACESSO']) ? date('d/m/Y H:i', strtotime($dados_usuario_completo['ULTIMO_ACESSO'])) : '--' ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                                <button type="button" class="btn btn-sm btn-outline-info" onclick="enviarResetSenha('<?= htmlspecialchars($cliente_ficha['CPF']) ?>')">
                                                    <i class="fas fa-paper-plane me-1"></i>Enviar Link de Reset
                                                </button>
                                                <a href="/modulos/cliente_e_usuario/cadastro_usuario.php?busca=<?= $cliente_ficha['CPF'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="fas fa-external-link-alt me-1"></i>Gerenciar Usuário
                                                </a>
                                            </div>
                                            <div id="resetSenhaResultado" class="mt-2"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4 mt-4">
                                <label class="fw-bold">Banco Nome</label>
                                <input type="text" class="form-control border-dark" name="banco_nome" value="<?= htmlspecialchars($cliente_ficha['BANCO_NOME'] ?? '') ?>" <?= $readonly_attr ?>>
                            </div>
                            <div class="col-md-4 mt-4">
                                <label class="fw-bold">Chave PIX</label>
                                <input type="text" class="form-control border-dark" name="chave_pix" value="<?= htmlspecialchars($cliente_ficha['CHAVE_PIX'] ?? '') ?>" <?= $readonly_attr ?>>
                            </div>
                            <div class="col-md-4 mt-4">
                                <label class="fw-bold">Grupo Whats</label>
                                <input type="text" class="form-control border-dark" name="grupo_whats" value="<?= htmlspecialchars($cliente_ficha['GRUPO_WHATS'] ?? '') ?>" <?= $readonly_attr ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold">Grupo Assessor</label>
                                <input type="text" class="form-control border-dark" name="grupo_assessor" value="<?= htmlspecialchars($cliente_ficha['GRUPO_ASSESSOR'] ?? '') ?>" <?= $readonly_attr ?>>
                            </div>
                            <div class="col-md-6">
                                <label class="fw-bold">Situação</label>
                                <select name="situacao" class="form-select border-dark" <?= $readonly_attr ?>>
                                    <option value="ATIVO" <?= ($cliente_ficha['SITUACAO'] ?? '') == 'ATIVO' ? 'selected' : '' ?>>Ativo</option>
                                    <option value="INATIVO" <?= ($cliente_ficha['SITUACAO'] ?? '') == 'INATIVO' ? 'selected' : '' ?>>Inativo</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($pode_editar_excluir): ?>
                            <div class="mt-4 pt-3 border-top border-dark d-flex justify-content-between">
                                <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" onclick="confirmarAcaoCliente('excluir', 'Confirma a EXCLUSÃO deste cliente?')">
                                    <i class="fas fa-trash"></i> Excluir Registro
                                </button>
                                <button type="button" class="btn btn-warning fw-bold border-dark shadow-sm text-dark px-4" onclick="confirmarAcaoCliente('editar', 'Deseja salvar as alterações nesta ficha?')">
                                    <i class="fas fa-save"></i> Atualizar Dados
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            <?php endif; ?>
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

function confirmarAcaoCliente(acao, mensagem) {
    if (confirm(mensagem)) {
        document.getElementById('acaoCrudCliente').value = acao;
        document.getElementById('formFichaCliente').submit();
    }
}

function abrirCadastro() {
    document.getElementById('painelNovoCadastro').style.display = 'flex';
    const painelRes = document.getElementById('painelResultados');
    if (painelRes) {
        painelRes.style.setProperty('display', 'none', 'important');
    }
}

function fecharCadastro() {
    document.getElementById('painelNovoCadastro').style.display = 'none';
    const painelRes = document.getElementById('painelResultados');
    if (painelRes) {
        painelRes.style.setProperty('display', 'flex', 'important');
    }
}

// SCRIPTS DA IMPORTAÇÃO DE ARQUIVO
const formImportacao = document.getElementById('formImportacao');
if (formImportacao) {
    formImportacao.addEventListener('submit', async function(e) {
        e.preventDefault();
        const form = e.target;
        const btn = document.getElementById('btnImportarLoad');
        const resDiv = document.getElementById('resultadoImportacao');
        const formData = new FormData(form);

        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Lendo e Salvando...';
        resDiv.innerHTML = '';

        try {
            const response = await fetch('importar_clientes.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                resDiv.innerHTML = `<div class="alert alert-success border-dark fw-bold shadow-sm"><i class="fas fa-check-circle fs-5 me-2"></i> Importação Concluída! <br><br> ✅ Inseridos (Novos): <b>${result.inseridos}</b> <br> ⚠️ Ignorados (Já Existiam): <b>${result.ignorados}</b></div>`;
                setTimeout(() => { location.reload(); }, 3000);
            } else {
                resDiv.innerHTML = `<div class="alert alert-danger border-dark fw-bold"><i class="fas fa-exclamation-triangle"></i> Erro: ${result.msg}</div>`;
            }
        } catch (error) {
            resDiv.innerHTML = `<div class="alert alert-danger border-dark fw-bold"><i class="fas fa-wifi"></i> Erro de rede ao comunicar com o servidor.</div>`;
        }

        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-upload me-2"></i> Iniciar Importação';
    });
}

// ============================================================
// MODAL PEDIDO
// ============================================================
async function abrirModalPedido(nomeCliente, telefone) {
    document.getElementById('ped_cliente').value = nomeCliente;
    document.getElementById('ped_telefone').value = telefone;
    document.getElementById('ped_produto').innerHTML = '<option value="">Carregando...</option>';
    document.getElementById('ped_variacao').innerHTML = '<option value="">Selecione um produto primeiro</option>';
    document.getElementById('ped_unitario').value = '';
    document.getElementById('ped_qtd').value = 1;
    document.getElementById('ped_obs').value = '';
    document.getElementById('ped_msg').innerHTML = '';

    const fd = new FormData(); fd.append('acao','buscar_produtos');
    const r = await fetch('/modulos/comercial/pedidos/pedidos.ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    let opts = '<option value="">-- Selecione o produto --</option>';
    (d.data||[]).forEach(p => opts += `<option value="${p.NOME}">${p.NOME}</option>`);
    document.getElementById('ped_produto').innerHTML = opts;

    new bootstrap.Modal(document.getElementById('modalNovoPedido')).show();
}

async function pedCarregarVariacoes() {
    const prod = document.getElementById('ped_produto').value;
    document.getElementById('ped_variacao').innerHTML = '<option value="">Carregando...</option>';
    document.getElementById('ped_unitario').value = '';
    if (!prod) { document.getElementById('ped_variacao').innerHTML = '<option value="">Selecione um produto primeiro</option>'; return; }
    const fd = new FormData(); fd.append('acao','buscar_variacoes'); fd.append('produto_nome', prod);
    const r = await fetch('/modulos/comercial/pedidos/pedidos.ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    let opts = '<option value="">Único</option>';
    (d.data||[]).forEach(v => opts += `<option value="${v.NOME_VARIACAO}" data-valor="${v.VALOR_VENDA}">${v.NOME_VARIACAO} — R$ ${parseFloat(v.VALOR_VENDA).toFixed(2).replace('.',',')}</option>`);
    document.getElementById('ped_variacao').innerHTML = opts;
}

function pedSetValor() {
    const sel = document.getElementById('ped_variacao');
    const opt = sel.options[sel.selectedIndex];
    const val = opt?.dataset?.valor;
    if (val) document.getElementById('ped_unitario').value = parseFloat(val).toFixed(2);
}

async function salvarPedidoCliente() {
    const btn = document.getElementById('btnSalvarPedido');
    const msg = document.getElementById('ped_msg');
    const cliente   = document.getElementById('ped_cliente').value.trim();
    const telefone  = document.getElementById('ped_telefone').value.trim();
    const produto   = document.getElementById('ped_produto').value.trim();
    const variacao  = document.getElementById('ped_variacao').value.trim();
    const unitario  = parseFloat(document.getElementById('ped_unitario').value) || 0;
    const qtd       = parseInt(document.getElementById('ped_qtd').value) || 1;
    const obs       = document.getElementById('ped_obs').value.trim();

    if (!produto || unitario <= 0) { msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">Selecione o produto e informe o valor.</div>'; return; }

    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('acao','salvar_pedido'); fd.append('cliente', cliente); fd.append('telefone', telefone);
    fd.append('produto_base', produto); fd.append('produto_variacao', variacao||'');
    fd.append('unitario', unitario); fd.append('qtd', qtd);
    fd.append('acrescimo',0); fd.append('variacao',0); fd.append('desconto',0);
    fd.append('cupom_nome',''); fd.append('cupom_val',0); fd.append('fidelidade',0); fd.append('iva',0);
    fd.append('total', (unitario * qtd).toFixed(2)); fd.append('obs', obs);
    const r = await fetch('/modulos/comercial/pedidos/pedidos.ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) {
        msg.innerHTML = `<div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle me-1"></i> ${d.msg}</div>`;
        setTimeout(() => bootstrap.Modal.getInstance(document.getElementById('modalNovoPedido')).hide(), 1800);
    } else {
        msg.innerHTML = `<div class="alert alert-danger py-2 mb-0">${d.msg||'Erro ao salvar.'}</div>`;
    }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-save me-1"></i> Salvar Pedido';
}

// ============================================================
// MODAL TAREFAS
// ============================================================
let _tarefasCpf = '', _tarefasNome = '';

async function abrirModalTarefas(cpf, nome) {
    _tarefasCpf = cpf; _tarefasNome = nome;
    document.getElementById('tar_titulo').value = '';
    document.getElementById('tar_descricao').value = '';
    document.getElementById('tar_vencimento').value = '';
    document.getElementById('tar_msg').innerHTML = '';
    await carregarTarefasCliente();
    new bootstrap.Modal(document.getElementById('modalTarefasCliente')).show();
}

async function carregarTarefasCliente() {
    const tb = document.getElementById('tbodyTarefas');
    tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i></td></tr>';
    const fd = new FormData(); fd.append('acao','listar_tarefas'); fd.append('cpf', _tarefasCpf);
    const r = await fetch('cliente_ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    if (!d.success || !d.data.length) { tb.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Nenhuma tarefa cadastrada.</td></tr>'; return; }
    tb.innerHTML = d.data.map(t => {
        const stCls = t.STATUS_TAREFA === 'Concluída' ? 'bg-success' : t.STATUS_TAREFA === 'Em andamento' ? 'bg-warning text-dark' : 'bg-secondary';
        const venc  = t.DATA_VENCIMENTO ? new Date(t.DATA_VENCIMENTO).toLocaleDateString('pt-BR') : '—';
        return `<tr>
            <td class="fw-bold small">${t.TITULO_TAREFA}</td>
            <td class="small text-muted">${t.DESCRICAO||'—'}</td>
            <td class="small">${venc}</td>
            <td><span class="badge ${stCls}">${t.STATUS_TAREFA||'Pendente'}</span></td>
            <td>
                <select class="form-select form-select-sm border-dark" onchange="atualizarStatusTarefa(${t.ID}, this.value)" style="min-width:130px">
                    <option ${t.STATUS_TAREFA==='Pendente'?'selected':''}>Pendente</option>
                    <option ${t.STATUS_TAREFA==='Em andamento'?'selected':''}>Em andamento</option>
                    <option ${t.STATUS_TAREFA==='Concluída'?'selected':''}>Concluída</option>
                </select>
            </td>
        </tr>`;
    }).join('');
}

async function salvarTarefaCliente() {
    const btn = document.getElementById('btnSalvarTarefa');
    const msg = document.getElementById('tar_msg');
    const titulo    = document.getElementById('tar_titulo').value.trim();
    const descricao = document.getElementById('tar_descricao').value.trim();
    const vencimento= document.getElementById('tar_vencimento').value;
    if (!titulo) { msg.innerHTML = '<div class="alert alert-danger py-2 mb-0">Informe o título da tarefa.</div>'; return; }
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('acao','salvar_tarefa'); fd.append('cpf', _tarefasCpf); fd.append('nome', _tarefasNome);
    fd.append('titulo', titulo); fd.append('descricao', descricao); fd.append('vencimento', vencimento);
    const r = await fetch('cliente_ajax.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) { msg.innerHTML = '<div class="alert alert-success py-2 mb-0"><i class="fas fa-check-circle me-1"></i> Tarefa salva!</div>'; document.getElementById('tar_titulo').value=''; document.getElementById('tar_descricao').value=''; document.getElementById('tar_vencimento').value=''; await carregarTarefasCliente(); }
    else { msg.innerHTML = `<div class="alert alert-danger py-2 mb-0">${d.msg||'Erro.'}</div>`; }
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus me-1"></i> Adicionar';
}

async function atualizarStatusTarefa(id, status) {
    const fd = new FormData(); fd.append('acao','atualizar_status'); fd.append('id', id); fd.append('status', status);
    await fetch('cliente_ajax.php', {method:'POST', body:fd});
    await carregarTarefasCliente();
}

// ── Empresa busca ao digitar ─────────────────────────────────
const _empresas = <?= json_encode(isset($lista_empresas) ? $lista_empresas : [], JSON_UNESCAPED_UNICODE) ?>;
function filtrarEmpresas(q) {
    const box = document.getElementById('empresaSugestoes');
    if (!box) return;
    const t = q.toLowerCase().trim();
    if (!t) { box.style.display='none'; return; }
    const matches = _empresas.filter(e =>
        e.NOME_CADASTRO.toLowerCase().includes(t) || e.CNPJ.includes(t)
    ).slice(0, 20);
    if (!matches.length) { box.innerHTML='<a class="list-group-item text-muted">Nenhuma empresa encontrada</a>'; box.style.display='block'; return; }
    box.innerHTML = '<a class="list-group-item list-group-item-action text-muted small" onclick="selecionarEmpresa(\'\',\'-- Sem vínculo --\')">-- Sem vínculo empresarial --</a>'
        + matches.map(e => `<a class="list-group-item list-group-item-action" onclick="selecionarEmpresa('${e.CNPJ}','${e.NOME_CADASTRO.replace(/'/g,"\\'")} (CNPJ: ${e.CNPJ})')">`
            + `<strong>${e.NOME_CADASTRO}</strong> <small class="text-muted">${e.CNPJ}</small></a>`).join('');
    box.style.display = 'block';
}
function selecionarEmpresa(cnpj, label) {
    const inp = document.getElementById('empresaBuscaInput');
    const hid = document.getElementById('cnpjVinculadoHidden');
    const box = document.getElementById('empresaSugestoes');
    if (inp) inp.value = label;
    if (hid) hid.value = cnpj;
    if (box) box.style.display = 'none';
}
function limparEmpresa() { selecionarEmpresa('', ''); }
document.addEventListener('click', e => {
    const w = document.getElementById('empresaBuscaWrapper');
    if (w && !w.contains(e.target)) { const b = document.getElementById('empresaSugestoes'); if(b) b.style.display='none'; }
});

// ── Senha: ver/ocultar ────────────────────────────────────────
function toggleVerSenha() {
    const inp = document.getElementById('campoSenhaAtual');
    const ico = document.getElementById('iconeSenha');
    if (!inp || !inp.dataset.permitido) return;
    if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fas fa-eye-slash'; }
    else { inp.type = 'password'; ico.className = 'fas fa-eye'; }
}

// ── Gerar nova senha ──────────────────────────────────────────
async function gerarNovaSenha(cpf) {
    if (!confirm('Gerar uma nova senha aleatória para este usuário?')) return;
    const fd = new FormData();
    fd.append('acao', 'gerar_senha'); fd.append('cpf', cpf);
    const r = await fetch('ajax_cliente_usuario.php', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) {
        const inp = document.getElementById('campoSenhaAtual');
        if (inp) { inp.value = j.senha; inp.type = 'text'; }
        const ico = document.getElementById('iconeSenha'); if(ico) ico.className='fas fa-eye-slash';
        alert('Nova senha gerada: ' + j.senha);
    } else alert('Erro: ' + j.msg);
}

// ── Enviar reset de senha ─────────────────────────────────────
async function enviarResetSenha(cpf) {
    const fd = new FormData();
    fd.append('ajax_action','gerar_enviar_reset'); fd.append('cpf_alvo', cpf);
    const r = await fetch('/modulos/cliente_e_usuario/acoes_usuario.php', {method:'POST', body:fd});
    const j = await r.json();
    const div = document.getElementById('resetSenhaResultado');
    if (j.success) { div.innerHTML='<div class="alert alert-success py-1 small mt-1">✅ Link enviado! <a href="'+j.link+'" target="_blank">Abrir link</a></div>'; }
    else { div.innerHTML='<div class="alert alert-danger py-1 small mt-1">❌ Erro ao gerar link.</div>'; }
}

// ── Seleção em lote ──────────────────────────────────────────
function toggleSelectAll(cb) {
    document.querySelectorAll('.cb-cliente').forEach(c => c.checked = cb.checked);
}
function getCpfsSelecionados() {
    return [...document.querySelectorAll('.cb-cliente:checked')].map(c => c.value);
}

async function acaoLote(tipo) {
    const cpfs = getCpfsSelecionados();
    const label = tipo === 'ativar' ? 'ATIVAR' : 'INATIVAR';
    const msg = cpfs.length > 0
        ? `${label} ${cpfs.length} cliente(s) selecionado(s)?`
        : `${label} TODOS os clientes listados na busca atual?`;
    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('acao_lote', tipo);
    cpfs.forEach(c => fd.append('cpfs[]', c));
    // passa filtros atuais para aplicar em todos se nenhum selecionado
    const params = new URLSearchParams(window.location.search);
    fd.append('busca', params.get('busca') || '');
    fd.append('situacao', params.get('situacao') || 'ATIVO');

    const r = await fetch('ajax_clientes_lote.php', {method:'POST', body:fd});
    const j = await r.json();
    if (j.ok) { alert(`${j.total} cliente(s) ${tipo === 'ativar' ? 'ativados' : 'inativados'}.`); location.reload(); }
    else alert('Erro: ' + j.msg);
}

function exportarClientes() {
    const cpfs = getCpfsSelecionados();
    const params = new URLSearchParams(window.location.search);
    let url = 'ajax_clientes_lote.php?acao_lote=exportar';
    url += '&busca=' + encodeURIComponent(params.get('busca') || '');
    url += '&situacao=' + encodeURIComponent(params.get('situacao') || 'ATIVO');
    if (cpfs.length > 0) url += '&cpfs=' + cpfs.join(',');
    window.location.href = url;
}
</script>

<!-- ===== MODAL NOVO PEDIDO ===== -->
<div class="modal fade" id="modalNovoPedido" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-dark">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title fw-bold"><i class="fas fa-shopping-cart me-2 text-warning"></i> Novo Pedido</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7"><label class="fw-bold small">Cliente</label><input type="text" id="ped_cliente" class="form-control border-dark bg-light" readonly></div>
          <div class="col-md-5"><label class="fw-bold small">Telefone</label><input type="text" id="ped_telefone" class="form-control border-dark"></div>
          <div class="col-md-6">
            <label class="fw-bold small">Produto</label>
            <select id="ped_produto" class="form-select border-dark" onchange="pedCarregarVariacoes()"><option value="">-- Selecione --</option></select>
          </div>
          <div class="col-md-6">
            <label class="fw-bold small">Variação</label>
            <select id="ped_variacao" class="form-select border-dark" onchange="pedSetValor()"><option value="">Único</option></select>
          </div>
          <div class="col-md-4"><label class="fw-bold small">Valor Unitário (R$)</label><input type="number" id="ped_unitario" class="form-control border-dark" min="0" step="0.01" placeholder="0,00"></div>
          <div class="col-md-2"><label class="fw-bold small">Qtd.</label><input type="number" id="ped_qtd" class="form-control border-dark" value="1" min="1"></div>
          <div class="col-md-6"><label class="fw-bold small">Observação</label><input type="text" id="ped_obs" class="form-control border-dark" placeholder="Opcional"></div>
        </div>
        <div id="ped_msg" class="mt-3"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" id="btnSalvarPedido" class="btn btn-warning fw-bold text-dark" onclick="salvarPedidoCliente()"><i class="fas fa-save me-1"></i> Salvar Pedido</button>
      </div>
    </div>
  </div>
</div>

<!-- ===== MODAL TAREFAS ===== -->
<div class="modal fade" id="modalTarefasCliente" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content border-dark">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title fw-bold"><i class="fas fa-tasks me-2 text-info"></i> Tarefas do Cliente</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Form nova tarefa -->
        <div class="border border-dark rounded p-3 bg-light mb-4">
          <h6 class="fw-bold mb-3 text-dark"><i class="fas fa-plus-circle me-1 text-success"></i> Nova Tarefa</h6>
          <div class="row g-2">
            <div class="col-md-5"><label class="fw-bold small">Título *</label><input type="text" id="tar_titulo" class="form-control border-dark" placeholder="Ex: Ligar para cliente, Enviar proposta..."></div>
            <div class="col-md-5"><label class="fw-bold small">Descrição</label><input type="text" id="tar_descricao" class="form-control border-dark" placeholder="Detalhes (opcional)"></div>
            <div class="col-md-2"><label class="fw-bold small">Vencimento</label><input type="date" id="tar_vencimento" class="form-control border-dark"></div>
          </div>
          <div id="tar_msg" class="mt-2"></div>
          <button type="button" id="btnSalvarTarefa" class="btn btn-success fw-bold mt-3" onclick="salvarTarefaCliente()"><i class="fas fa-plus me-1"></i> Adicionar</button>
        </div>
        <!-- Lista de tarefas -->
        <div class="table-responsive">
          <table class="table table-bordered table-hover align-middle small">
            <thead class="table-dark"><tr><th>Título</th><th>Descrição</th><th>Vencimento</th><th>Status</th><th style="width:160px">Alterar Status</th></tr></thead>
            <tbody id="tbodyTarefas"><tr><td colspan="5" class="text-center text-muted py-3">Carregando...</td></tr></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
      </div>
    </div>
  </div>
</div>

</div> <?php
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>

<!-- Modal Empresa (Nova / Editar) -->
<div class="modal fade" id="modalEmpresa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase" id="modalEmpresaTitulo">
                    <i class="fas fa-building me-2"></i> Nova Empresa
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" id="modalEmpresaAcao" value="nova_empresa">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="fw-bold small text-dark">CNPJ <span class="text-danger">*</span></label>
                        <input type="text" id="modalEmpresaCnpj" class="form-control border-dark rounded-0" placeholder="Apenas números" maxlength="18">
                    </div>
                    <div class="col-md-7">
                        <label class="fw-bold small text-dark">Nome / Razão Social <span class="text-danger">*</span></label>
                        <input type="text" id="modalEmpresaNome" class="form-control border-dark rounded-0 text-uppercase">
                    </div>
                    <div class="col-md-12">
                        <label class="fw-bold small text-dark">Celular</label>
                        <input type="text" id="modalEmpresaCelular" class="form-control border-dark rounded-0" placeholder="Ex: 11999999999" maxlength="11">
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-secondary rounded-0" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm btn-success fw-bold rounded-0" onclick="salvarEmpresaModal()">
                    <i class="fas fa-save me-1"></i> Salvar Empresa
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function editarEmpresaAtual() {
    const cnpj = document.getElementById('cnpjVinculadoHidden').value.replace(/\D/g,'');
    if (!cnpj) return crmToast('Nenhuma empresa vinculada. Use "Nova Empresa" para criar.', 'warning');
    abrirModalEmpresa('editar', cnpj);
}

function abrirModalEmpresa(modo, cnpj) {
    document.getElementById('modalEmpresaCnpj').value    = '';
    document.getElementById('modalEmpresaNome').value    = '';
    document.getElementById('modalEmpresaCelular').value = '';

    if (modo === 'nova') {
        document.getElementById('modalEmpresaTitulo').innerHTML = '<i class="fas fa-building me-2"></i> Nova Empresa';
        document.getElementById('modalEmpresaAcao').value = 'nova_empresa';
        document.getElementById('modalEmpresaCnpj').removeAttribute('readonly');
        new bootstrap.Modal(document.getElementById('modalEmpresa')).show();
    } else {
        document.getElementById('modalEmpresaTitulo').innerHTML = '<i class="fas fa-edit me-2"></i> Editar Empresa';
        document.getElementById('modalEmpresaAcao').value = 'editar_empresa';
        document.getElementById('modalEmpresaCnpj').setAttribute('readonly', true);
        // Carrega dados da empresa
        const fd = new FormData();
        fd.append('acao', 'carregar_empresa');
        fd.append('cnpj', cnpj);
        fetch('cliente_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) return crmToast(res.msg, 'error');
                document.getElementById('modalEmpresaCnpj').value    = res.empresa.CNPJ;
                document.getElementById('modalEmpresaNome').value    = res.empresa.NOME_CADASTRO;
                document.getElementById('modalEmpresaCelular').value = res.empresa.CELULAR || '';
                new bootstrap.Modal(document.getElementById('modalEmpresa')).show();
            });
    }
}

function salvarEmpresaModal() {
    const acao = document.getElementById('modalEmpresaAcao').value;
    const cnpj = document.getElementById('modalEmpresaCnpj').value.replace(/\D/g,'');
    const nome = document.getElementById('modalEmpresaNome').value.trim();
    const cel  = document.getElementById('modalEmpresaCelular').value.trim();

    if (!cnpj || !nome) return crmToast('CNPJ e Nome são obrigatórios.', 'warning');

    const fd = new FormData();
    fd.append('acao', acao);
    fd.append('cnpj', cnpj);
    fd.append('nome_cadastro', nome);
    fd.append('celular', cel);

    fetch('cliente_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (!res.success) return crmToast(res.msg, 'error');
            bootstrap.Modal.getInstance(document.getElementById('modalEmpresa')).hide();
            crmToast(res.msg, 'success');
            // Se for nova empresa, já vincula automaticamente ao cliente
            if (acao === 'nova_empresa') {
                document.getElementById('cnpjVinculadoHidden').value = res.cnpj;
                document.getElementById('empresaBuscaInput').value   = res.nome + ' (CNPJ: ' + res.cnpj + ')';
            }
        });
}
</script>
<?php
?>