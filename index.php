<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Puxa a conexão com o banco para buscar as campanhas
$caminho_conexao = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (file_exists($caminho_conexao)) { 
    include_once $caminho_conexao; 
} else { 
    include_once 'conexao.php'; // Fallback
}

// 2. Identificação do Usuário Logado e Permissões Base
$cpf_logado = $_SESSION['usuario_cpf'] ?? '';
$grupo_usuario = strtoupper($_SESSION['usuario_grupo'] ?? 'ADMIN');
$is_master = in_array($grupo_usuario, ['MASTER', 'ADMIN', 'ADMINISTRADOR']);

// 3. Busca as Campanhas Ativas (Calculando Total e Restantes)
$campanhas_ativas = [];
try {
    $sqlCamp = "
        SELECT c.ID, c.NOME_CAMPANHA, 
               (SELECT COUNT(ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID) as TOTAL_CLIENTES,
               (SELECT COUNT(cl.ID) FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA cl WHERE cl.ID_CAMPANHA = c.ID AND NOT EXISTS (SELECT 1 FROM BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO r WHERE r.CPF_CLIENTE = cl.CPF_CLIENTE AND r.DATA_REGISTRO >= cl.DATA_INCLUSAO)) as RESTANTES,
               (SELECT CPF_CLIENTE FROM BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA WHERE ID_CAMPANHA = c.ID ORDER BY ID ASC LIMIT 1) as PRIMEIRO_CPF
        FROM BANCO_DE_DADOS_CAMPANHA_CAMPANHAS c
        WHERE c.STATUS = 'ATIVO'
    ";
    $paramsCamp = [];

    // Se NÃO for Master/Admin, mostra apenas campanhas Globais (NULL) ou as atribuídas diretamente ao CPF dele
    if (!$is_master) {
        $sqlCamp .= " AND (c.CPF_USUARIO IS NULL OR c.CPF_USUARIO = ?)";
        $paramsCamp[] = $cpf_logado;
    }

    $sqlCamp .= " ORDER BY c.NOME_CAMPANHA ASC";
    
    $stmtCamp = $pdo->prepare($sqlCamp);
    $stmtCamp->execute($paramsCamp);
    $campanhas_ativas = $stmtCamp->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $erro_db = "Erro ao buscar campanhas: " . $e->getMessage();
}

// 4. Puxa o menu superior de forma segura
$caminho_header = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
if (file_exists($caminho_header)) { 
    include $caminho_header; 
} else { 
    include 'includes/header.php'; // Fallback
}
?>

<style>
    /* ESTILIZAÇÃO BASEADA NO PRINT ENVIADO */
    .barra-titulo-campanha {
        background-color: #e4e2d7;
        border: 1px solid #a3a196;
        color: #333;
        font-weight: 600;
        padding: 5px 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
        cursor: pointer;
        border-radius: 3px;
        user-select: none;
    }
    
    .barra-titulo-campanha:hover {
        background-color: #d8d6cc;
    }

    .box-campanhas {
        border: 1px solid #4cae4c;
        border-radius: 4px;
        padding: 15px;
        background-color: #ffffff;
        margin-top: 5px;
    }

    .btn-camp-hub {
        background-color: #4cae4c; /* Verde Estilo Imagem */
        color: #ffffff;
        border: 2px solid #ffffff;
        outline: 2px solid #4cae4c;
        border-radius: 0; /* Quadrado */
        display: flex;
        align-items: center;
        text-decoration: none;
        min-height: 80px;
        position: relative;
        padding: 10px;
        transition: background-color 0.2s;
        overflow: hidden;
    }

    .btn-camp-hub:hover {
        background-color: #449d44;
        color: #ffffff;
        outline-color: #449d44;
    }

    /* Ícone de fundo marca d'água */
    .btn-camp-hub .icon-bg {
        font-size: 38px;
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.2;
        color: #000;
    }

    .btn-camp-hub .content {
        margin-left: 45px; /* Espaço para o ícone */
        width: 100%;
        text-align: center;
    }

    .btn-camp-hub .nome {
        font-weight: 800;
        font-size: 0.75rem;
        text-transform: uppercase;
        display: block;
        margin-bottom: 5px;
        line-height: 1.2;
    }

    .btn-camp-hub .stats {
        font-size: 0.65rem;
        background: rgba(0,0,0,0.25);
        padding: 2px 6px;
        border-radius: 3px;
        font-weight: 600;
        display: inline-block;
    }

    /* Botão Cinza para Campanhas Vazias */
    .btn-camp-vazia {
        background-color: #888888;
        outline-color: #888888;
        cursor: not-allowed;
    }
    .btn-camp-vazia:hover {
        background-color: #777777;
        outline-color: #777777;
    }
</style>

<div class="d-flex justify-content-between align-items-center border-bottom border-dark pb-2 mb-4">
    <div>
        <h2 class="text-dark fw-bold m-0"><i class="fas fa-home text-primary me-2"></i> Hub Principal do CRM</h2>
        <p class="text-muted m-0">Bem-vindo. Selecione um módulo no menu superior para começar.</p>
    </div>
</div>

<?php if (isset($erro_db)): ?>
    <div class="alert alert-danger fw-bold border-dark shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i> <?= $erro_db ?></div>
<?php endif; ?>

<div class="mb-5">
    <div class="barra-titulo-campanha shadow-sm" data-bs-toggle="collapse" data-bs-target="#collapseCampanhas" aria-expanded="true">
        <span><i class="far fa-newspaper me-2"></i> Campanhas em Andamento</span>
        <i class="fas fa-bell"></i>
    </div>
    
    <div class="collapse show" id="collapseCampanhas">
        <div class="box-campanhas shadow-sm">
            <div class="row g-3">
                <?php if (empty($campanhas_ativas)): ?>
                    <div class="col-12 text-center py-3">
                        <span class="text-muted fw-bold fst-italic">Nenhuma campanha ativa no momento.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($campanhas_ativas as $camp): ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 col-sm-12">
                            <?php if ($camp['TOTAL_CLIENTES'] > 0 && !empty($camp['PRIMEIRO_CPF'])): ?>
                                <a href="/modulos/banco_dados/consulta.php?id_campanha=<?= $camp['ID'] ?>&busca=<?= $camp['PRIMEIRO_CPF'] ?>&cpf_selecionado=<?= $camp['PRIMEIRO_CPF'] ?>&acao=visualizar" class="btn-camp-hub">
                                    <i class="fas fa-headset icon-bg"></i>
                                    <div class="content">
                                        <span class="nome"><?= htmlspecialchars($camp['NOME_CAMPANHA']) ?></span>
                                        <span class="stats">
                                            Total: <?= number_format($camp['TOTAL_CLIENTES'], 0, ',', '.') ?> | Restante: <?= number_format($camp['RESTANTES'], 0, ',', '.') ?>
                                        </span>
                                    </div>
                                </a>
                            <?php else: ?>
                                <div class="btn-camp-hub btn-camp-vazia" title="Nenhum cliente inserido nesta campanha.">
                                    <i class="fas fa-headset icon-bg"></i>
                                    <div class="content">
                                        <span class="nome"><?= htmlspecialchars($camp['NOME_CAMPANHA']) ?></span>
                                        <span class="stats text-warning">Vazia (Sem Clientes)</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div> <?php // Fecha a div container-fluid do header ?>
<?php
// 5. Puxa o rodapé de forma segura
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { 
    include $caminho_footer; 
} else {
    include 'includes/footer.php'; // Fallback
}
?>