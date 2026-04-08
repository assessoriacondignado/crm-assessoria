<?php
// ==========================================
// 1. MOTOR DE SEGURANÇA E SESSÃO
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// AJAX: REQUISIÇÃO DO MODAL DE SUPORTE (LOGADO)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_suporte_logado'])) {
    header('Content-Type: application/json');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
    if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    
    $nome = trim($_POST['nome']); 
    $telefone = trim($_POST['telefone']);
    $telefone_limpo = preg_replace('/[^0-9a-zA-Z-]/', '', $telefone);
    
    if (strlen($telefone_limpo) < 10 && !preg_match('/[a-zA-Z]/', $telefone_limpo)) {
         echo json_encode(['success' => false, 'message' => 'Número/ID inválido.']); 
         exit;
    }
    
    $stmt = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1");
    $inst = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($inst) {
        $msg = "🆘 *SOLICITAÇÃO DE SUPORTE (USUÁRIO LOGADO)*\n\n👤 *Nome:* $nome\n📱 *Contato:* $telefone\n⏰ *Data:* " . date('d/m/Y H:i:s');
        $url = "https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID'];
        // Mantive o ID do seu grupo idêntico ao do login
        $payload = json_encode(['phone' => '120363406245292046@g.us', 'message' => $msg, 'delayMessage' => 1]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $inst['TOKEN']]);
        curl_exec($ch);
        curl_close($ch);
    }
    echo json_encode(['success' => true, 'message' => 'Solicitação enviada. Nossa equipe entrará em contato!']); 
    exit;
}

// ✨ BLINDAGEM: Garante a conexão com o Banco ANTES de verificar as permissões ✨
require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

// ✨ MOTOR DE PERMISSÕES: Tenta carregar o arquivo silenciosamente ✨
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) {
    include_once $caminho_permissoes;
}

// ✨ HELPER PARA O MENU ✨
// Facilita a checagem sem poluir o HTML com vários try/catch
function podeAcessarMenu($pdo, $chave_menu) {
    if (function_exists('verificaPermissao') && isset($pdo)) {
        try {
            return verificaPermissao($pdo, $chave_menu, 'TELA');
        } catch (\Throwable $e) {}
    }
    return true; // Padrão: libera se houver falha no motor
}

// Verifica se a pessoa passou pela tela de login
if (!isset($_SESSION['usuario_cpf'])) {
    header("Location: /login.php");
    exit;
}

// ==========================================
// CHAVE DE PERMISSÃO: VER USUÁRIOS ONLINE
// ==========================================
$pode_ver_online = podeAcessarMenu($pdo, 'MENU_INICIO_VER_ONLINE');

// ==========================================
// NOVO: RASTREIO ONLINE E LOGOUT FORÇADO
// ==========================================
if (isset($_SESSION['usuario_cpf']) && isset($pdo)) {
    $stmtCheck = $pdo->prepare("SELECT FORCAR_LOGOUT FROM CLIENTE_USUARIO WHERE CPF = ?");
    $stmtCheck->execute([$_SESSION['usuario_cpf']]);
    $deve_sair = $stmtCheck->fetchColumn();

    if ($deve_sair == 1) {
        $pdo->prepare("UPDATE CLIENTE_USUARIO SET FORCAR_LOGOUT = 0, ULTIMO_ACESSO = NULL WHERE CPF = ?")->execute([$_SESSION['usuario_cpf']]);
        session_unset();
        session_destroy();
        header("Location: /login.php?aviso=deslogado_pelo_admin");
        exit;
    }
    
    // Atualiza o "pulso" de atividade no Banco de Dados
    $pdo->prepare("UPDATE CLIENTE_USUARIO SET ULTIMO_ACESSO = NOW() WHERE CPF = ?")->execute([$_SESSION['usuario_cpf']]);
}

// ==========================================
// 2. VERIFICAÇÃO DE INATIVIDADE (2 HORAS)
// ==========================================
$limite_inatividade = 7200; 

if (isset($_SESSION['ultimo_acesso'])) {
    $tempo_parado = time() - $_SESSION['ultimo_acesso'];
    if ($tempo_parado > $limite_inatividade) {
        session_unset();
        session_destroy();
        header("Location: /login.php?aviso=expirado");
        exit;
    }
}
$_SESSION['ultimo_acesso'] = time();

// ==========================================
// 3. MOTOR DE DATA DE EXPIRAÇÃO 
// ==========================================
$stmtExp = $pdo->prepare("SELECT Situação, CELULAR, DATA_EXPIRAR FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
$stmtExp->execute([$_SESSION['usuario_cpf']]);
$userExp = $stmtExp->fetch(PDO::FETCH_ASSOC);

if ($userExp && !empty($userExp['DATA_EXPIRAR'])) {
    $data_exp = strtotime($userExp['DATA_EXPIRAR']);
    $hoje = strtotime(date('Y-m-d'));
    
    // Se a data de hoje for maior ou igual a data de expirar, e ele ainda estiver Ativo
    if ($hoje >= $data_exp && strtolower($userExp['Situação']) == 'ativo') {
        
        // 3.1. Desativa o usuário no banco
        $pdo->prepare("UPDATE CLIENTE_USUARIO SET Situação = 'vencido' WHERE CPF = ?")->execute([$_SESSION['usuario_cpf']]);
        
        // 3.2. Dispara a mensagem via W-API
        $stmtInst = $pdo->query("SELECT INSTANCE_ID, TOKEN FROM WAPI_INSTANCIAS ORDER BY ID ASC LIMIT 1");
        $inst = $stmtInst->fetch(PDO::FETCH_ASSOC);
        
        if ($inst && !empty($userExp['CELULAR'])) {
            $cel_whats = '55' . preg_replace('/\D/', '', $userExp['CELULAR']);
            $msg = "⚠️ *Aviso do Sistema*\n\nSeu acesso ao portal Assessoria Consignado atingiu a data limite de expiração e foi desativado.\n\nContate o administrador para renovar seu acesso.";
            
            $url = "https://api.w-api.app/v1/message/send-text?instanceId=" . $inst['INSTANCE_ID'];
            $payload = json_encode(['phone' => $cel_whats, 'message' => $msg]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $inst['TOKEN']]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout curto para não travar a tela
            curl_exec($ch);
            curl_close($ch);
        }
        
        // 3.3. Destrói a sessão e chuta pra tela de login
        session_unset();
        session_destroy();
        header("Location: /login.php?aviso=expirado");
        exit;
    }
}

// Pega o primeiro nome do usuário logado para exibir no menu
$nome_completo = isset($_SESSION['usuario_nome']) ? $_SESSION['usuario_nome'] : 'Usuário';
$primeiro_nome = explode(' ', trim($nome_completo))[0];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CRM - Assessoria Consignado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* VISUAL UNIFORME E MINIMALISTA */
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Roboto, sans-serif; }
        
        .navbar-custom { background-color: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-bottom: 1px solid #eaeaea; padding: 0.5rem 2rem; position: relative; z-index: 9999; }
        .navbar-custom .nav-link { color: #444444; font-weight: 500; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px; padding: 10px 15px; transition: color 0.2s; }
        .navbar-custom .nav-link:hover, .navbar-custom .nav-link:focus { color: #0d6efd; }
        .navbar-custom .nav-link i { margin-right: 6px; font-size: 14px; color: #666; }
        
        .dropdown-menu { border: 1px solid #eaeaea; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-radius: 6px; padding: 8px 0; margin-top: 5px; z-index: 10000; }
        .dropdown-item { color: #555; font-size: 14px; padding: 8px 20px; transition: all 0.2s; }
        .dropdown-item i { width: 20px; color: #777; margin-right: 8px; text-align: center; }
        .dropdown-item:hover { background-color: #f4f6f9; color: #0d6efd; }
        .dropdown-item:hover i { color: #0d6efd; }
        
        .user-profile { display: flex; align-items: center; text-align: right; line-height: 1.2; }
        .user-name { font-weight: 600; color: #333; font-size: 14px; margin-bottom: 0; }
        
        .user-status { font-size: 11px; color: #198754; font-weight: bold; transition: color 0.2s;}
        .user-status.clickable:hover { color: #0d6efd; cursor: pointer; text-decoration: underline; }
        
        .user-avatar { font-size: 26px; color: #666; margin-left: 10px; cursor: pointer; transition: 0.2s;}
        .user-avatar:hover { color: #0d6efd; }
        
        .conteudo-principal { margin: 20px auto; padding: 30px; background: #fff; border-radius: 8px; box-shadow: 0 0 15px rgba(0,0,0,0.03); min-height: calc(100vh - 110px); }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-custom">
  <div class="container-fluid">
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#menuPrincipal">
      <span class="navbar-toggler-icon"></span>
    </button>
    
    <div class="collapse navbar-collapse" id="menuPrincipal">
      
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        
        <?php if(podeAcessarMenu($pdo, 'MENU_USUARIO')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-users-cog"></i> Usuário
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_USUARIO')): ?>
            <li><a class="dropdown-item" href="/modulos/cliente_e_usuario/cadastro_usuario.php"><i class="fas fa-user-shield"></i> Cadastro Usuário</a></li>
            <?php endif; ?>
            <?php if(podeAcessarMenu($pdo, 'USUARIO_FINANCEIRO')): ?>
            <li><a class="dropdown-item" href="/modulos/cliente_e_usuario/cadastro_cliente_financeiro.php"><i class="fas fa-wallet"></i> Painel Financeiro</a></li>
            <?php endif; ?>
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_GESTAO_PERMISSOES')): ?>
            <li><a class="dropdown-item" href="/modulos/cliente_e_usuario/permissoes.php"><i class="fas fa-key"></i> Gestão de Permissões</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_CLIENTE')): ?>
            <li><a class="dropdown-item" href="/modulos/cliente_e_usuario/cadastro_cliente.php"><i class="fas fa-user"></i> Cadastro Cliente</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_EMPRESA')): ?>
            <li><a class="dropdown-item" href="/modulos/cliente_e_usuario/cadastro_empresa.php"><i class="fas fa-building"></i> Cadastro Empresa</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_CONSIGNADO')): ?>
            <li><a class="dropdown-item" href="/modulos/cadastro_cliente_consignado/index.php"><i class="fas fa-address-card"></i> Cadastro Consignado</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_CAMPANHA')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle text-danger" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-bullhorn text-danger"></i> Campanhas
          </a>
          <ul class="dropdown-menu shadow-sm border-danger border-top-0 border-end-0 border-bottom-0 border-3">
            <li><a class="dropdown-item" href="/modulos/campanhas/index.php"><i class="fas fa-list text-danger"></i> Relação de Campanhas</a></li>
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_STATUS')): ?>
            <li><a class="dropdown-item" href="/modulos/campanhas/status.php"><i class="fas fa-tags text-primary"></i> Status e Qualificações</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="/modulos/campanhas/agenda.php"><i class="fas fa-calendar-alt text-success"></i> Minha Agenda</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_BANCO_DADOS')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-database"></i> Banco de Dados
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_CONSULTA')): ?>
            <li><a class="dropdown-item" href="/modulos/banco_dados/consulta.php"><i class="fas fa-search"></i> Consulta</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_IMPORTACAO')): ?>
            <li><a class="dropdown-item" href="/modulos/banco_dados/importacao.php"><i class="fas fa-file-import"></i> Importação</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_EXPORTACAO')): ?>
            <li><a class="dropdown-item" href="/modulos/banco_dados/exportacao.php"><i class="fas fa-file-export"></i> Exportação</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_BANCO_PLANILHAS')): ?>
        <li class="nav-item">
          <a class="nav-link" href="/modulos/banco_planilhas/index.php"><i class="fas fa-server"></i> Banco de Planilhas</a>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_OPERACIONAL')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-briefcase"></i> Operacional
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_META_ADS')): ?>
            <li><a class="dropdown-item" href="/modulos/meta_ads/index.php"><i class="fab fa-meta"></i> META ADS</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_HIST_INSS')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/Promosys_inss/index.php"><i class="fas fa-university"></i> HIST INSS</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_TAREFAS')): ?>
            <li><a class="dropdown-item" href="/modulos/operacional/tarefas/index.php"><i class="fas fa-tasks"></i> Controle de Tarefas</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_FATOR_CONFERI')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/fator_conferi/index.php"><i class="fas fa-search-dollar"></i> Fator Conferi</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_INTEGRACAO_V8')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/v8_clt_margem_e_digitacao/index.php"><i class="fas fa-handshake"></i> Integração V8 Digital</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_WHATS_API')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/whats_api_oficial/index.php"><i class="fab fa-whatsapp"></i> WHATS API OFICIAL</a></li>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_WAPI')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/w-api/index.php"><i class="fas fa-robot"></i> W-API (Múltiplas Conexões)</a></li>
            <?php endif; ?>
            
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_COMERCIAL')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-store"></i> Comercial
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_PRODUTOS')): ?>
            <li><a class="dropdown-item" href="/modulos/comercial/produtos/index.php"><i class="fas fa-box-open"></i> Catálogo de Produtos</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_PEDIDOS')): ?>
            <li><a class="dropdown-item" href="/modulos/comercial/pedidos/index.php"><i class="fas fa-shopping-cart"></i> Pedidos e Renovações</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_ENTREGAS')): ?>
            <li><a class="dropdown-item" href="/modulos/comercial/entregas/index.php"><i class="fas fa-truck"></i> Logística e Entregas</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_COMISSOES')): ?>
            <li><a class="dropdown-item" href="/modulos/comercial/comissao/index.php"><i class="fas fa-hand-holding-usd"></i> Gestão de Comissões</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_FINANCEIRO')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-wallet"></i> Financeiro
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_FIN_PAINEL')): ?>
            <li><a class="dropdown-item" href="/modulos/financeiro/index.php"><i class="fas fa-chart-pie"></i> Painel Financeiro</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if(podeAcessarMenu($pdo, 'MENU_CONFIGURACAO')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
            <i class="fas fa-cog"></i> Configuração
          </a>
          <ul class="dropdown-menu shadow-sm">
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CONF_GERAL')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/index.php"><i class="fas fa-sliders-h"></i> Configuração Geral</a></li>
            <?php endif; ?>
            
            <?php if(podeAcessarMenu($pdo, 'SUBMENU_CONF_MYSQL')): ?>
            <li><a class="dropdown-item" href="/modulos/configuracao/resumo_mysql.php"><i class="fas fa-table"></i> Resumo MySQL</a></li>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'SUBMENU_ANOTACOES_GERAIS')): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/modulos/configuracao/anotacoes/index.php"><i class="fas fa-sticky-note"></i> Anotações Gerais</a></li>
            <?php endif; ?>

          </ul>
        </li>
        <?php endif; ?>

      </ul>

      <ul class="navbar-nav ms-auto mb-2 mb-lg-0 d-flex align-items-center">
          
        <li class="nav-item me-2">
            <a href="/index.php" class="btn btn-outline-dark btn-sm fw-bold border-2">
                <i class="fas fa-home me-1"></i> Início
            </a>
        </li>
        
        <li class="nav-item me-3">
            <button class="btn btn-success btn-sm fw-bold shadow-sm border-0" style="background-color: #25D366;" onclick="abrirModalSuporteLogado()">
                <i class="fab fa-whatsapp me-1"></i> Falar com Suporte
            </button>
        </li>
        
      </ul>
      
      <div class="user-profile border-start ps-3 ms-1">
        <div>
            <p class="user-name">Olá, <?= htmlspecialchars($primeiro_nome) ?></p>
            
            <?php if($pode_ver_online): ?>
                <span class="user-status clickable" onclick="carregarUsuariosOnline(true)" title="Ver usuários online">
                    <i class="fas fa-circle text-success" style="font-size: 8px;"></i> <span id="topo_contador_online">0</span> Online
                </span>
            <?php else: ?>
                <span class="user-status"><i class="fas fa-circle text-success" style="font-size: 8px;"></i> Online</span>
            <?php endif; ?>
            
        </div>
        <div class="dropdown">
            <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration: none;">
                <i class="fas fa-user-circle user-avatar text-dark"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                <li><a class="dropdown-item text-danger fw-bold" href="/logout.php"><i class="fas fa-sign-out-alt text-danger"></i> Sair do Sistema</a></li>
            </ul>
        </div>
      </div>

    </div>
  </div>
</nav>

<div class="modal fade" id="modalSuporteLogado" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background-color: #25D366; color: white;">
                <h6 class="modal-title fw-bold">📱 Solicitar Suporte</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-start">
                <div id="suporteFormularioLogado">
                    <label class="small fw-bold mb-1">Seu Nome:</label>
                    <input type="text" id="supNomeLogado" class="form-control mb-2" value="<?= htmlspecialchars($nome_completo) ?>">
                    <label class="small fw-bold mb-1">Celular (DDD + 9 dígitos):</label>
                    <input type="text" id="supTelefoneLogado" class="form-control mb-3" placeholder="Ex: 11999998888">
                    <button type="button" class="btn btn-success w-100 fw-bold" onclick="enviarSuporteLogado()" id="btnSuporteSubmitLogado">SOLICITAR ATENDIMENTO</button>
                </div>
                <div id="suporteSucessoLogado" class="d-none text-center py-3">
                    <h4 class="text-success mb-2">✅ Notificado!</h4>
                    <p class="small text-muted mb-0">Nossa equipe foi acionada e entrará em contato com você em breve.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    let mSuporteLogado;
    
    function abrirModalSuporteLogado() {
        document.getElementById('suporteFormularioLogado').classList.remove('d-none');
        document.getElementById('suporteSucessoLogado').classList.add('d-none');
        document.getElementById('supTelefoneLogado').value = '';
        
        if (!mSuporteLogado) {
            mSuporteLogado = new bootstrap.Modal(document.getElementById('modalSuporteLogado'));
        }
        mSuporteLogado.show();
    }

    function enviarSuporteLogado() {
        const nome = document.getElementById('supNomeLogado').value; 
        const tel = document.getElementById('supTelefoneLogado').value;
        if(!nome || !tel) return alert('Preencha os dois campos obrigatórios.');
        
        const btn = document.getElementById('btnSuporteSubmitLogado'); 
        btn.innerHTML = 'Notificando equipe...'; btn.disabled = true;
        
        const fd = new FormData(); 
        fd.append('ajax_suporte_logado', '1'); 
        fd.append('nome', nome); 
        fd.append('telefone', tel);
        
        fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json()).then(res => {
            btn.innerHTML = 'SOLICITAR ATENDIMENTO'; btn.disabled = false;
            if(res.success) { 
                document.getElementById('suporteFormularioLogado').classList.add('d-none'); 
                document.getElementById('suporteSucessoLogado').classList.remove('d-none');
            } else { 
                alert(res.message); 
            }
        }).catch(() => { 
            btn.innerHTML = 'SOLICITAR ATENDIMENTO'; btn.disabled = false; 
            alert('Erro ao contatar o suporte.'); 
        });
    }
</script>

<?php if($pode_ver_online): ?>
<div style="position: fixed; bottom: 25px; right: 25px; z-index: 9999;">
    <button class="btn btn-dark fw-bold shadow-lg border-light rounded-pill px-4 py-2" onclick="carregarUsuariosOnline(true)" title="Gerenciar Usuários Online">
        <i class="fas fa-users text-success me-2"></i> <span id="rodape_contador_online">0</span> Online
    </button>
</div>

<div class="modal fade" id="modalUsuariosOnline" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-dark text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-signal text-success me-2"></i> Monitor de Atividades Online</h5>
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
        
        // ATENÇÃO: Caminho absoluto para funcionar em qualquer página do CRM
        fetch('/modulos/cliente_e_usuario/ajax_usuarios_online.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                // Atualiza os contadores na barra superior e no botão flutuante
                document.getElementById('topo_contador_online').innerText = data.usuarios.length;
                document.getElementById('rodape_contador_online').innerText = data.usuarios.length;
                
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
                                <td><span class="badge bg-secondary border border-dark">${u.GRUPO_USUARIOS}</span></td>
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
        
        fetch('/modulos/cliente_e_usuario/ajax_usuarios_online.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.sucesso) {
                alert(`${nome} será deslogado no próximo clique dele no sistema!`);
                carregarUsuariosOnline(); 
            }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        carregarUsuariosOnline();
        setInterval(() => carregarUsuariosOnline(), 30000); // Atualiza a cada 30s
    });
</script>
<?php endif; // Fim Bloco de Visualização Online ?>

<div class="container-fluid px-4 conteudo-principal">