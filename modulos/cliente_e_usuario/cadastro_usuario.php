<?php
session_start();

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

// ==========================================
// 1. CHECAGEM DE PERMISSÕES DO GRUPO
// ==========================================
// ✨ REGRA APLICADA: Se for bloqueado, não vê os outros, só vê a si mesmo
$pode_ver_todos = verificaPermissao($pdo, 'FUNCAO_CADASTRO_USUARIO_MEU_CPF', 'FUNCAO');

// ✨ REGRA APLICADA: Se for bloqueado, não pode editar nem excluir a ficha
$pode_editar_excluir = verificaPermissao($pdo, 'FUNCAO_CADASTRO_USUARIO_EDITAR_EXCLUIR', 'FUNCAO');
$pode_editar_geral = $pode_editar_excluir;
$pode_excluir_geral = $pode_editar_excluir;

$termo_busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$acao = isset($_GET['acao']) ? $_GET['acao'] : '';
$cpf_selecionado = isset($_GET['cpf_selecionado']) ? trim($_GET['cpf_selecionado']) : '';
$is_busca_avancada = isset($_GET['busca_avancada']) && $_GET['busca_avancada'] == '1';

if (!$pode_ver_todos) {
    $cpf_selecionado = $_SESSION['usuario_cpf'];
    $acao = 'visualizar';
}

$filtros_campo = isset($_GET['campo']) ? $_GET['campo'] : [];
$filtros_operador = isset($_GET['operador']) ? $_GET['operador'] : [];
$filtros_valor = isset($_GET['valor']) ? $_GET['valor'] : [];

$pagina_atual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$limites_por_pagina = 20; 
$offset = ($pagina_atual - 1) * $limites_por_pagina; 

$resultados_busca = [];
$usuario_ficha = null;
$erro_banco = null; 
$grupos_disponiveis = [];

function mascaraCPF($cpf) {
    $cpf = str_pad(preg_replace('/[^0-9]/', '', $cpf), 11, '0', STR_PAD_LEFT);
    return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cpf);
}

function mapearColunaUsuario($campo) {
    $mapa = [
        'cpf' => 'CPF', 'nome' => 'NOME', 'usuario' => 'USUARIO', 
        'celular' => 'CELULAR', 'email' => 'EMAIL', 'grupo_whats' => 'GRUPO_WHATS', 
        'grupo_usuarios' => 'GRUPO_USUARIOS', 'situacao' => 'Situação'
    ];
    return isset($mapa[$campo]) ? $mapa[$campo] : 'NOME';
}

try {
    $stmtGrupos = $pdo->query("SELECT NOME_GRUPO FROM SISTEMA_GRUPOS_USUARIO WHERE STATUS = 'ATIVO' ORDER BY NOME_GRUPO ASC");
    $grupos_disponiveis = $stmtGrupos->fetchAll(PDO::FETCH_COLUMN);

    $stmtEmpresas = $pdo->query("SELECT ID, NOME_CADASTRO FROM CLIENTE_EMPRESAS ORDER BY NOME_CADASTRO ASC");
    $lista_empresas_usuarios = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

    if ($pode_ver_todos) {
        $filtro_sql = ""; $params = []; $contador_params = 1;

        if (!$is_busca_avancada && !empty($termo_busca) && empty($cpf_selecionado)) {
            $termo_limpo_num = preg_replace('/[^0-9]/', '', $termo_busca);
            $filtro_sql .= " AND (NOME LIKE :termo OR USUARIO LIKE :termo OR EMAIL LIKE :termo ";
            $params[':termo'] = "$termo_busca%"; 
            
            if (!empty($termo_limpo_num)) {
                $filtro_sql .= " OR CPF LIKE :cpf OR CELULAR LIKE :celular ";
                $params[':cpf'] = "$termo_limpo_num%";
                $params[':celular'] = "$termo_limpo_num%";
            }
            $filtro_sql .= ") ";
        } elseif ($is_busca_avancada && empty($cpf_selecionado)) {
            for ($i = 0; $i < count($filtros_campo); $i++) {
                $campo_html = $filtros_campo[$i]; $operador = $filtros_operador[$i]; $valor_bruto = trim($filtros_valor[$i]);
                if ($valor_bruto === '' && $operador != 'vazio') continue; 

                $coluna_db = mapearColunaUsuario($campo_html);
                if ($operador == 'vazio') { $filtro_sql .= " AND ($coluna_db IS NULL OR trim($coluna_db) = '') "; continue; }

                $valores_array = explode(';', $valor_bruto); $condicoes_or = [];
                foreach ($valores_array as $val) {
                    $val = trim($val); if ($val === '') continue;
                    if (in_array($campo_html, ['cpf', 'celular'])) { $val = preg_replace('/[^0-9]/', '', $val); }
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
        if ((!empty($termo_busca) || $is_busca_avancada) && empty($cpf_selecionado)) {
            $sql_base = " FROM CLIENTE_USUARIO WHERE 1=1 " . $filtro_sql;
            
            $limite_busca = $limites_por_pagina + 1;
            $sql_dados = "SELECT * " . $sql_base . " LIMIT " . (int)$limite_busca . " OFFSET " . (int)$offset;
            $stmt = $pdo->prepare($sql_dados);
            $stmt->execute($params);
            $resultados_busca = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($resultados_busca) > $limites_por_pagina) {
                $tem_proxima_pagina = true;
                array_pop($resultados_busca);
            }
        }
    }

    if (!empty($cpf_selecionado) && in_array($acao, ['visualizar', 'editar'])) {
        $cpf_cru = urldecode($cpf_selecionado);
        $cpf_so_numeros = preg_replace('/[^0-9]/', '', $cpf_selecionado);
        $cpf_com_11_digitos = str_pad($cpf_so_numeros, 11, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("SELECT * FROM CLIENTE_USUARIO WHERE CPF = :cpf1 OR CPF = :cpf2 OR CPF = :cpf3 LIMIT 1");
        $stmt->execute([
            'cpf1' => $cpf_cru,
            'cpf2' => $cpf_so_numeros,
            'cpf3' => $cpf_com_11_digitos
        ]);
        
        $usuario_ficha = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $erro_banco = "<b>Erro no MySQL:</b> " . $e->getMessage();
}

if (!file_exists($caminho_header)) {
    die("<h1 style='color:red;'>ERRO CRÍTICO:</h1> O arquivo de cabeçalho não foi encontrado no caminho: <b>$caminho_header</b>");
}
include $caminho_header;
?>

<div class="row mb-4">
    <div class="col-12 text-center">
        <h2 class="text-primary fw-bold"><i class="fas fa-user-shield me-2"></i> Gestão de Usuários</h2>
        <p class="text-muted">Gerencie os acessos, senhas e configurações dos usuários da plataforma.</p>
    </div>
</div>

<?php if ($erro_banco): ?>
    <div class="row justify-content-center mb-4">
        <div class="col-md-8">
            <div class="alert alert-danger shadow-lg border-dark fw-bold text-center">
                <i class="fas fa-database me-2"></i> <?= $erro_banco ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($pode_ver_todos): ?>
<div class="row justify-content-center mb-2">
    <div class="col-md-8">
        <form action="" method="GET" class="d-flex shadow-sm mb-2">
            <input type="text" name="busca" class="form-control form-control-lg border-primary" placeholder="Pesquisa rápida (Nome, CPF, Celular)..." value="<?= $is_busca_avancada ? '' : htmlspecialchars($termo_busca) ?>" <?= $is_busca_avancada ? 'disabled' : 'autofocus' ?>>
            <button type="submit" class="btn btn-primary btn-lg px-4 ms-2 fw-bold" <?= $is_busca_avancada ? 'disabled' : '' ?>><i class="fas fa-search"></i></button>
        </form>
        
        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-sm btn-outline-secondary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#painelBuscaAvancada" aria-expanded="<?= $is_busca_avancada ? 'true' : 'false' ?>">
                <i class="fas fa-sliders-h"></i> Filtro Aprimorado
            </button>
        </div>

        <div class="collapse mt-3 <?= $is_busca_avancada ? 'show' : '' ?>" id="painelBuscaAvancada">
            <div class="card border-dark shadow-lg rounded-3 overflow-hidden">
                <div class="card-header bg-dark text-white fw-bold py-3 border-bottom border-dark text-uppercase">
                    <i class="fas fa-filter text-info me-2"></i> Montador de Filtros (Usuários)
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
                                        <option value="nome" <?= $c_sel=='nome'?'selected':'' ?>>Nome</option>
                                        <option value="cpf" <?= $c_sel=='cpf'?'selected':'' ?>>CPF</option>
                                        <option value="usuario" <?= $c_sel=='usuario'?'selected':'' ?>>Usuário (Login)</option>
                                        <option value="celular" <?= $c_sel=='celular'?'selected':'' ?>>Celular</option>
                                        <option value="email" <?= $c_sel=='email'?'selected':'' ?>>E-mail</option>
                                        <option value="grupo_whats" <?= $c_sel=='grupo_whats'?'selected':'' ?>>Grupo Whats</option>
                                        <option value="grupo_usuarios" <?= $c_sel=='grupo_usuarios'?'selected':'' ?>>Grupo Usuários</option>
                                        <option value="situacao" <?= $c_sel=='situacao'?'selected':'' ?>>Situação</option>
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
                                <a href="cadastro_usuario.php" class="btn btn-sm btn-outline-dark shadow-sm bg-white">Limpar Filtros</a>
                                <button type="submit" class="btn btn-sm btn-info text-dark border-dark fw-bold shadow-sm"><i class="fas fa-search"></i> Executar Filtro</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ((!empty($termo_busca) || $is_busca_avancada) && empty($cpf_selecionado) && !$erro_banco): ?>
    <div class="row justify-content-center">
        <div class="col-md-10">
            <?php if (!empty($resultados_busca)): ?>
                <div class="card border-dark shadow-lg rounded-3 mb-5">
                    <div class="card-header bg-dark text-white border-bottom border-dark py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold text-uppercase"><i class="fas fa-list text-info me-2"></i> Resultados da Busca (Pág <?= $pagina_atual ?>)</h5>
                    </div>
                    <div class="table-responsive bg-white">
                        <table class="table table-hover align-middle mb-0 border-dark text-center fs-6">
                            <thead class="table-dark text-white text-uppercase" style="font-size: 0.85rem;">
                                <tr>
                                    <th>CPF</th>
                                    <th class="text-start">Nome</th>
                                    <th>Usuário</th>
                                    <th>Situação</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados_busca as $res): ?>
                                    <tr class="border-bottom border-dark">
                                        <td class="fw-bold text-secondary bg-light"><?= mascaraCPF($res['CPF']) ?></td>
                                        <td class="text-start fw-bold text-dark"><?= htmlspecialchars($res['NOME']) ?></td>
                                        <td><?= htmlspecialchars($res['USUARIO'] ?? '') ?></td>
                                        <td><span class="badge bg-<?= ($res['Situação'] ?? '') == 'ativo' ? 'success' : 'danger' ?> border border-dark"><?= strtoupper($res['Situação'] ?? 'DESCONHECIDO') ?></span></td>
                                        <td>
                                            <?php 
                                            $url_params = $_GET;
                                            $url_params['cpf_selecionado'] = trim($res['CPF']);
                                            $url_params['acao'] = 'visualizar';
                                            $link_editar = '?' . http_build_query($url_params);
                                            ?>
                                            <a href="<?= $link_editar ?>" class="btn btn-sm btn-primary border-dark"><i class="fas fa-eye"></i> Ver / Editar</a>
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
                    <h4 class="alert-heading text-dark fw-bold"><i class="fas fa-exclamation-triangle"></i> Nenhum cadastro encontrado com estes filtros!</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<?php if (!empty($cpf_selecionado) && $acao == 'visualizar' && !$erro_banco): ?>
    <div class="row justify-content-center mb-5">
        <div class="col-md-8">
            <?php if (!$usuario_ficha): ?>
                <div class="alert alert-danger shadow-lg border-dark text-center p-4 rounded-3">
                    <h4 class="fw-bold mb-3"><i class="fas fa-exclamation-triangle"></i> Falha ao Carregar a Ficha</h4>
                    <p class="mb-0">Não foi possível localizar o usuário no banco de dados.</p>
                    <?php if($pode_ver_todos): ?>
                        <a href="cadastro_usuario.php" class="btn btn-dark fw-bold mt-3"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <?php endif; ?>
                </div>
            <?php else: 
                $is_meu_perfil = ($usuario_ficha['CPF'] === $_SESSION['usuario_cpf']);
                $is_master = in_array(strtoupper($_SESSION['usuario_grupo'] ?? ''), ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
                
                if ($is_meu_perfil) {
                    $pode_editar = $is_master; 
                    $pode_excluir = false; 
                } else {
                    $pode_editar = $pode_editar_geral;
                    $pode_excluir = $pode_excluir_geral;
                }
                
                $readonly_attr = (!$pode_editar) ? 'disabled readonly' : '';
            ?>
                <div class="card border-dark shadow-lg rounded-3">
                    <div class="card-header bg-dark text-white py-3 border-bottom border-dark d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0 text-uppercase"><i class="fas fa-id-card text-info me-2"></i> Ficha do Usuário</h5>
                        
                        <div>
                            <a href="cadastro_cliente_financeiro.php?cpf=<?= htmlspecialchars($usuario_ficha['CPF']) ?>" class="btn btn-sm btn-success fw-bold shadow-sm me-2">
                                <i class="fas fa-wallet"></i> Financeiro
                            </a>

                            <?php if($pode_ver_todos): ?>
                                <?php $urlRetorno = $is_busca_avancada ? '?' . preg_replace('/&?cpf_selecionado=[^&]*/', '', preg_replace('/&?acao=[^&]*/', '', $_SERVER['QUERY_STRING'])) : '?busca=' . urlencode($termo_busca); ?>
                                <a href="<?= $urlRetorno ?>" class="btn btn-sm btn-outline-light"><i class="fas fa-arrow-left"></i> Voltar</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body bg-light">
                        <form id="formFichaUsuario" action="acoes_usuario.php" method="POST">
                            <input type="hidden" name="acao_crud" id="acaoCrud" value="editar">
                            
                            <?php if($is_meu_perfil && !$is_master): ?>
                                <div class="alert alert-info border-dark py-2 small fw-bold">
                                    <i class="fas fa-info-circle"></i> Por questões de segurança, você não pode editar os seus próprios dados ou excluir sua conta.
                                </div>
                            <?php endif; ?>
                            
                            <?php if(!$pode_editar && !$is_meu_perfil): ?>
                                <div class="alert alert-warning border-dark py-2 small fw-bold">
                                    <i class="fas fa-lock"></i> Visualização Protegida: Seu usuário não tem permissão para editar ou excluir registros.
                                </div>
                            <?php endif; ?>

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="fw-bold">CPF</label>
                                    <input type="hidden" name="cpf" value="<?= htmlspecialchars($usuario_ficha['CPF']) ?>">
                                    <input type="text" class="form-control border-dark bg-secondary text-white fw-bold" value="<?= mascaraCPF($usuario_ficha['CPF']) ?>" readonly disabled>
                                </div>
                                <div class="col-md-8">
                                    <label class="fw-bold mb-0">Nome Completo</label>
                                    <input type="text" class="form-control border-dark mt-1" name="nome" value="<?= htmlspecialchars($usuario_ficha['NOME']) ?>" <?= $readonly_attr ?>>
                                    
                                    <small class="text-muted fw-bold d-block mt-1">
                                        <i class="fas fa-shield-alt text-info"></i> Grupo de Permissão: <?= htmlspecialchars($usuario_ficha['GRUPO_USUARIOS'] ?? 'Não definido') ?>
                                    </small>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="fw-bold">Usuário (Login)</label>
                                    <input type="text" class="form-control border-dark text-primary fw-bold" name="usuario" value="<?= htmlspecialchars($usuario_ficha['USUARIO'] ?? '') ?>" <?= $readonly_attr ?>>
                                </div>
                                
                                <div class="col-md-4">
                                    <label class="fw-bold text-danger d-block">Segurança / Senha</label>
                                    <button type="button" class="btn btn-<?= $is_meu_perfil ? 'success' : 'danger' ?> fw-bold w-100 border-dark shadow-sm" id="btnLinkReset" onclick="enviarLinkReset('<?= htmlspecialchars($usuario_ficha['CPF']) ?>', <?= $is_meu_perfil ? 'true' : 'false' ?>)" <?= (!$is_meu_perfil && $readonly_attr) ? 'disabled' : '' ?>>
                                        <i class="<?= $is_meu_perfil ? 'fab fa-whatsapp' : 'fas fa-link' ?> me-1"></i> <?= $is_meu_perfil ? 'Redefinir Minha Senha' : 'Gerar Link de Reset' ?>
                                    </button>
                                </div>

                                <div class="col-md-4">
                                    <label class="fw-bold">Celular</label>
                                    <input type="text" class="form-control border-dark" name="celular" value="<?= htmlspecialchars($usuario_ficha['CELULAR'] ?? '') ?>" maxlength="11" <?= $readonly_attr ?>>
                                </div>
                                
                                <div class="col-md-12 d-none" id="divLinkResetResult">
                                    <div class="alert alert-warning border-dark shadow-sm p-3 mb-0">
                                        <label class="fw-bold text-dark small mb-1"><i class="fas fa-exclamation-circle me-1"></i> Link de Redefinição Gerado (Cópia Manual):</label>
                                        <div class="input-group">
                                            <input type="text" class="form-control border-dark fw-bold text-primary" id="inputLinkGerado" readonly>
                                            <button class="btn btn-dark border-dark fw-bold" type="button" onclick="copiarLink()"><i class="fas fa-copy"></i> Copiar</button>
                                        </div>
                                        <small class="text-muted mt-2 d-block">O link é válido por 1 hora. Envie-o manualmente caso o usuário não receba a mensagem automática no WhatsApp.</small>
                                    </div>
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="fw-bold text-danger"><i class="fas fa-calendar-times me-1"></i> Data Expirar</label>
                                    <input type="date" class="form-control border-danger fw-bold" name="data_expirar" value="<?= htmlspecialchars($usuario_ficha['DATA_EXPIRAR'] ?? '') ?>" <?= $readonly_attr ?>>
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="fw-bold text-primary">Grupo Usuários (Permissões)</label>
                                    <?php if($pode_editar): ?>
                                    <select name="grupo_usuarios" class="form-select border-primary fw-bold text-uppercase">
                                        <option value="">-- Sem Grupo --</option>
                                        <?php foreach($grupos_disponiveis as $grupo): ?>
                                            <option value="<?= htmlspecialchars($grupo) ?>" <?= ($usuario_ficha['GRUPO_USUARIOS'] ?? '') == $grupo ? 'selected' : '' ?>><?= htmlspecialchars($grupo) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control border-dark bg-secondary text-white fw-bold text-uppercase" value="<?= htmlspecialchars($usuario_ficha['GRUPO_USUARIOS'] ?? '-- Sem Grupo --') ?>" disabled>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="fw-bold">Situação</label>
                                    <?php if($pode_editar): ?>
                                    <select name="situacao" class="form-select border-dark">
                                        <option value="ativo" <?= ($usuario_ficha['Situação'] ?? '') == 'ativo' ? 'selected' : '' ?>>Ativo</option>
                                        <option value="inativo" <?= ($usuario_ficha['Situação'] ?? '') == 'inativo' ? 'selected' : '' ?>>Inativo</option>
                                        <option value="vencido" <?= ($usuario_ficha['Situação'] ?? '') == 'vencido' ? 'selected' : '' ?>>Vencido</option>
                                        <option value="suspenso" <?= ($usuario_ficha['Situação'] ?? '') == 'suspenso' ? 'selected' : '' ?>>Suspenso</option>
                                    </select>
                                    <?php else: ?>
                                        <input type="text" class="form-control border-dark bg-secondary text-white fw-bold text-uppercase" value="<?= htmlspecialchars($usuario_ficha['Situação'] ?? '') ?>" disabled>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-8 mt-3">
                                    <label class="fw-bold"><i class="fas fa-building me-1"></i> Empresa / Vínculo</label>
                                    <?php if($pode_editar): ?>
                                    <select name="id_empresa" class="form-select border-dark">
                                        <option value="">-- Sem Vínculo --</option>
                                        <?php foreach(($lista_empresas_usuarios ?? []) as $emp): ?>
                                            <option value="<?= $emp['ID'] ?>" <?= ($usuario_ficha['id_empresa'] ?? '') == $emp['ID'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['NOME_CADASTRO']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php else: ?>
                                        <?php
                                            $nome_emp_atual = 'Sem Vínculo';
                                            foreach(($lista_empresas_usuarios ?? []) as $emp) {
                                                if ($emp['ID'] == ($usuario_ficha['id_empresa'] ?? '')) { $nome_emp_atual = $emp['NOME_CADASTRO']; break; }
                                            }
                                        ?>
                                        <input type="text" class="form-control border-dark bg-secondary text-white fw-bold" value="<?= htmlspecialchars($nome_emp_atual) ?>" disabled>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($pode_editar || $pode_excluir): ?>
                                <div class="mt-4 pt-3 border-top border-dark d-flex justify-content-between">
                                    <?php if($pode_excluir): ?>
                                        <button type="button" class="btn btn-danger fw-bold border-dark shadow-sm" onclick="confirmarAcao('excluir', 'Você tem CERTEZA ABSOLUTA que deseja EXCLUIR este usuário? Esta ação não pode ser desfeita.')">
                                            <i class="fas fa-trash"></i> Excluir Cadastro
                                        </button>
                                    <?php else: ?> <div></div> <?php endif; ?>
                                    
                                    <?php if($pode_editar): ?>
                                        <button type="button" class="btn btn-warning fw-bold border-dark shadow-sm text-dark" onclick="confirmarAcao('editar', 'Confirma a gravação das edições feitas neste cadastro?')">
                                            <i class="fas fa-save"></i> Salvar Edições
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php 
$eh_admin = in_array(strtoupper($_SESSION['usuario_grupo'] ?? ''), ['MASTER', 'ADMIN', 'ADMINISTRADOR']);
if ($eh_admin): 
?>
<div class="fixed-bottom bg-white border-top border-dark p-3 shadow-lg d-flex justify-content-between align-items-center" style="z-index: 1030;">
    <span class="text-muted fw-bold"><i class="fas fa-cog"></i> Gestão de Usuários</span>
    <button class="btn btn-primary fw-bold px-4 border-dark shadow-sm" onclick="carregarUsuariosOnline(true)">
        <i class="fas fa-users me-2"></i> Usuários Online (<span id="contador_online">0</span>)
    </button>
</div>

<div class="modal fade" id="modalUsuariosOnline" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-primary text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-signal me-2"></i> Monitor de Atividades Online</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center border-dark">
                        <thead class="table-dark text-white" style="font-size: 0.85rem;">
                            <tr>
                                <th class="text-start px-4">Nome do Usuário</th>
                                <th>Empresa / Vínculo</th>
                                <th>Grupo</th>
                                <th>Contato</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="lista_usuarios_online">
                            </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function carregarUsuariosOnline(abrirModal = false) {
        let fd = new FormData();
        fd.append('acao', 'listar_online');
        
        fetch('ajax_usuarios_online.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                document.getElementById('contador_online').innerText = data.usuarios.length;
                let tb = document.getElementById('lista_usuarios_online');
                tb.innerHTML = '';
                
                if(data.usuarios.length === 0) {
                    tb.innerHTML = '<tr><td colspan="5" class="py-4 fw-bold text-muted border-dark">Ninguém online no momento.</td></tr>';
                } else {
                    data.usuarios.forEach(u => {
                        tb.innerHTML += `
                            <tr class="border-bottom border-dark">
                                <td class="text-start px-4 fw-bold text-dark">
                                    <i class="fas fa-circle text-success me-2" style="font-size: 10px;"></i> ${u.NOME}
                                    <small class="d-block text-muted fw-normal">${u.CPF}</small>
                                </td>
                                <td class="fw-bold text-primary">${u.EMPRESA || 'Sem Vínculo'}</td>
                                <td><span class="badge bg-dark border border-secondary">${u.GRUPO_USUARIOS}</span></td>
                                <td class="fw-bold">${u.CELULAR || '--'}</td>
                                <td>
                                    <button class="btn btn-sm btn-danger fw-bold border-dark shadow-sm" onclick="derrubarUsuario('${u.CPF}', '${u.NOME}')">
                                        <i class="fas fa-sign-out-alt me-1"></i> Deslogar
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                }
                
                if(abrirModal) {
                    new bootstrap.Modal(document.getElementById('modalUsuariosOnline')).show();
                }
            }
        });
    }

    function derrubarUsuario(cpf, nome) {
        if(!confirm(`Atenção: Tem certeza que deseja forçar a desconexão de ${nome}?`)) return;
        
        let fd = new FormData();
        fd.append('acao', 'forcar_logout');
        fd.append('cpf_alvo', cpf);
        
        fetch('ajax_usuarios_online.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                crmToast(nome + " será deslogado no próximo clique.", "warning", 5000);
                carregarUsuariosOnline(); 
            }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        carregarUsuariosOnline();
        setInterval(() => carregarUsuariosOnline(), 30000); // Atualiza a cada 30s
    });
</script>
<?php endif; // Fim Bloco Admin ?>

<script>
// Scripts originais de pesquisa
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

function confirmarAcao(acao, mensagem) {
    if (confirm(mensagem)) {
        document.getElementById('acaoCrud').value = acao;
        document.getElementById('formFichaUsuario').submit();
    }
}

function enviarLinkReset(cpfDestino, isSelf) {
    const btn = document.getElementById('btnLinkReset');
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando...';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('ajax_action', 'gerar_enviar_reset');
    fd.append('cpf_alvo', cpfDestino);

    fetch('acoes_usuario.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        
        if(res.success) {
            if(!isSelf) {
                document.getElementById('divLinkResetResult').classList.remove('d-none');
                document.getElementById('inputLinkGerado').value = res.link;
                if(res.wapi) {
                    crmToast("✅ Link gerado E enviado para o WhatsApp do usuário com sucesso!", "warning", 5000);
                } else {
                    crmToast("✅ Link gerado! " + res.info + " Copie e envie manualmente.", "success");
                }
            } else {
                crmToast("✅ Link de redefinição enviado para o seu WhatsApp com sucesso! Verifique seu celular.", "warning", 5000);
            }
        } else {
            crmToast("❌ " + res.message, "error", 6000);
        }
    }).catch(() => {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
        crmToast("Falha na comunicação com o servidor.", "warning", 5000);
    });
}

function copiarLink() {
    var copyText = document.getElementById("inputLinkGerado");
    copyText.select();
    copyText.setSelectionRange(0, 99999); 
    navigator.clipboard.writeText(copyText.value);
    crmToast("Copiado com sucesso!", "info", 5000);
}
</script>

</div> 
<?php 
// IMPORTANTE: Adicionado estilo inline para evitar o rodapé de sobrepor o footer padrão
?>
<div style="height: 80px;"></div>
<?php
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) {
    include $caminho_footer; 
}
?>