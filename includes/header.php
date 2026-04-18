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
$pode_ver_online = podeAcessarMenu($pdo, 'USUARIO_ONLINE');

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

// ==========================================
// AVISOS INTERNOS — BADGE E PAINEL DO SINO
// ==========================================
$cpf_logado_h   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$grupo_logado_h = strtoupper($_SESSION['usuario_grupo'] ?? '');
$avisos_header  = [];
$nao_lidos_h    = 0;
try {
    $id_empresa_h = null;
    $stmtE = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
    $stmtE->execute([$cpf_logado_h]);
    $id_empresa_h = $stmtE->fetchColumn();

    $stmtH = $pdo->prepare("
        SELECT a.ID, a.ASSUNTO, a.TIPO, a.NOME_CRIADOR, a.DATA_CRIACAO
        FROM AVISOS_INTERNOS a
        WHERE EXISTS (
            SELECT 1 FROM AVISOS_INTERNOS_DESTINATARIOS d
            WHERE d.AVISO_ID = a.ID AND (
                d.TIPO_DEST = 'TODOS'
                OR (d.TIPO_DEST = 'GRUPO'   AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'USUARIO' AND d.VALOR = ?)
                OR (d.TIPO_DEST = 'EMPRESA' AND d.VALOR = ?)
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM AVISOS_INTERNOS_LEITURA
            WHERE AVISO_ID = a.ID AND CPF_USUARIO = ?
        )
        ORDER BY a.DATA_CRIACAO DESC
        LIMIT 30
    ");
    $stmtH->execute([$grupo_logado_h, $cpf_logado_h, (string)$id_empresa_h, $cpf_logado_h]);
    $avisos_header = $stmtH->fetchAll(PDO::FETCH_ASSOC);
    $nao_lidos_h   = count($avisos_header);
} catch(Exception $e) {}
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
        /* ============================================================
           BASE
        ============================================================ */
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Roboto, sans-serif; padding-top: 54px; }

        /* ============================================================
           TOPBAR SLIM
        ============================================================ */
        .topbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
            height: 54px;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            display: flex; align-items: center;
            padding: 0 18px; gap: 10px;
        }
        .topbar-toggle {
            background: none; border: none; cursor: pointer;
            color: #444; font-size: 20px; padding: 4px 8px;
            border-radius: 6px; transition: background .15s, color .15s;
            display: flex; align-items: center;
        }
        .topbar-toggle:hover { background: #f0f0f0; color: #b02a37; }
        .topbar-brand {
            font-weight: 700; font-size: 15px; letter-spacing: 1px;
            color: #b02a37; white-space: nowrap;
        }
        .topbar-right {
            margin-left: auto; display: flex; align-items: center; gap: 8px;
        }
        .user-profile { display: flex; align-items: center; text-align: right; line-height: 1.2; }
        .user-name { font-weight: 600; color: #333; font-size: 14px; margin-bottom: 0; }
        .user-status { font-size: 11px; color: #198754; font-weight: bold; transition: color 0.2s; }
        .user-status.clickable:hover { color: #0d6efd; cursor: pointer; text-decoration: underline; }
        .user-avatar { font-size: 26px; color: #666; margin-left: 10px; cursor: pointer; transition: 0.2s; }
        .user-avatar:hover { color: #0d6efd; }

        /* ============================================================
           SIDEBAR OVERLAY + PAINEL
        ============================================================ */
        .sidebar-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.48);
            z-index: 99997;
            opacity: 0; pointer-events: none;
            transition: opacity .25s;
        }
        .sidebar-overlay.aberto { opacity: 1; pointer-events: all; }

        .sidebar-panel {
            position: fixed; top: 0; left: 0; bottom: 0;
            width: 285px;
            background: #16213e;
            z-index: 99998;
            display: flex; flex-direction: column;
            transform: translateX(-100%);
            transition: transform .25s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
        }
        .sidebar-overlay.aberto .sidebar-panel { transform: translateX(0); }

        /* Cabeçalho da sidebar */
        .sidebar-head {
            background: #b02a37;
            padding: 14px 16px 14px 18px;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .sidebar-head-title {
            color: #fff; font-weight: 700; font-size: 14px; letter-spacing: 1.5px;
            display: flex; align-items: center; gap: 9px;
        }
        .sidebar-close {
            background: none; border: none; color: rgba(255,255,255,.75);
            font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px;
            transition: color .15s;
        }
        .sidebar-close:hover { color: #fff; }

        /* Área de scroll dos itens */
        .sidebar-body {
            flex: 1; overflow-y: auto; padding: 6px 0 16px;
        }
        .sidebar-body::-webkit-scrollbar { width: 4px; }
        .sidebar-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 4px; }

        /* Seção com título */
        .sidebar-section-title {
            font-size: 10px; font-weight: 700; letter-spacing: 1.5px;
            color: rgba(255,255,255,.3); text-transform: uppercase;
            padding: 14px 18px 5px;
        }

        /* Item com submenu */
        .sidebar-item {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 10px 18px;
            background: none; border: none;
            color: #c8d0e0; font-size: 13px; font-weight: 500;
            text-transform: uppercase; letter-spacing: .5px;
            cursor: pointer; text-align: left;
            transition: background .15s, color .15s;
            position: relative;
        }
        .sidebar-item:hover { background: rgba(255,255,255,.07); color: #fff; }
        .sidebar-item.ativo { background: rgba(176,42,55,.18); color: #ff8a8a; }
        .sidebar-item i.menu-icon { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }
        .sidebar-chevron {
            margin-left: auto; font-size: 10px; color: rgba(255,255,255,.35);
            transition: transform .2s; flex-shrink: 0;
        }
        .sidebar-item.aberto .sidebar-chevron { transform: rotate(180deg); }
        .sidebar-item.campanha { color: #ff8a8a; }
        .sidebar-item.campanha i { color: #ff8a8a; }

        /* Submenu colapsável */
        .sidebar-sub {
            overflow: hidden; max-height: 0;
            transition: max-height .3s ease;
        }
        .sidebar-sub.aberto { max-height: 600px; }
        .sidebar-subitem {
            display: flex; align-items: center; gap: 9px;
            padding: 8px 18px 8px 46px;
            color: #8da0bb; font-size: 12.5px;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .sidebar-subitem:hover { background: rgba(255,255,255,.05); color: #fff; }
        .sidebar-subitem i { width: 16px; text-align: center; font-size: 12px; flex-shrink: 0; }

        /* Link simples (sem submenu) */
        .sidebar-link {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 18px;
            color: #c8d0e0; font-size: 13px; font-weight: 500;
            text-transform: uppercase; letter-spacing: .5px;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .sidebar-link:hover { background: rgba(255,255,255,.07); color: #fff; }
        .sidebar-link i { width: 18px; text-align: center; font-size: 14px; flex-shrink: 0; }

        /* Divisor */
        .sidebar-divider { border-top: 1px solid rgba(255,255,255,.06); margin: 6px 0; }

        /* ============================================================
           CONTEÚDO PRINCIPAL
        ============================================================ */
        .conteudo-principal {
            margin: 20px auto; padding: 30px;
            background: #fff; border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.03);
            min-height: calc(100vh - 94px);
        }

        /* Painel de avisos internos */
        .aviso-item-header:hover { background: #f8f9ff !important; }
        #avisos-panel-header { animation: slideDownPanel .18s ease; }
        @keyframes slideDownPanel { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

        /* Dropdown do topbar */
        .topbar .dropdown-menu {
            border: 1px solid #eaeaea; box-shadow: 0 4px 12px rgba(0,0,0,0.10);
            border-radius: 6px; padding: 8px 0; z-index: 100000;
        }
        .topbar .dropdown-item { color: #555; font-size: 14px; padding: 8px 20px; }
        .topbar .dropdown-item i { width: 20px; color: #777; margin-right: 8px; }
        .topbar .dropdown-item:hover { background: #f4f6f9; color: #0d6efd; }
    </style>
</head>
<body>

<!-- ================================================================
     TOPBAR SLIM
================================================================ -->
<div class="topbar">
    <!-- Botão hamburguer -->
    <button class="topbar-toggle" onclick="sidebarAbrir()" title="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Brand -->
    <span class="topbar-brand">CRM</span>

    <!-- Controles à direita -->
    <div class="topbar-right">

        <!-- Início -->
        <a href="/index.php" class="btn btn-outline-dark btn-sm fw-bold border-2">
            <i class="fas fa-home me-1"></i> Início
        </a>

        <!-- Sino de avisos -->
        <div class="position-relative" id="li-sino-avisos">
            <button class="btn btn-outline-dark btn-sm fw-bold border-2 position-relative" onclick="toggleAvisosPanel(event)" id="btnSino" title="Avisos Internos">
                <i class="fas fa-bell"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $nao_lidos_h === 0 ? 'd-none' : '' ?>" id="badge-avisos-h" style="font-size:0.55rem; min-width:18px;"><?= $nao_lidos_h ?: '' ?></span>
            </button>
            <!-- Painel de avisos -->
            <div id="avisos-panel-header" style="display:none; position:absolute; top:calc(100% + 8px); right:0; width:370px; background:#fff; border:1px solid #dee2e6; border-radius:8px; box-shadow:0 8px 28px rgba(0,0,0,0.18); z-index:99999; overflow:hidden;">
                <div class="d-flex justify-content-between align-items-center px-3 py-2" style="background:#dc3545; border-radius:8px 8px 0 0;">
                    <span class="fw-bold text-white small"><i class="fas fa-bell me-1"></i> Avisos Internos</span>
                    <button class="btn btn-sm text-white border-0 p-0 lh-1" onclick="toggleAvisosPanel(event)" style="background:none; font-size:1.2rem; opacity:.8;">&times;</button>
                </div>
                <div id="lista-avisos-header" style="max-height:420px; overflow-y:auto;">
                    <?php if(empty($avisos_header)): ?>
                    <div class="text-center py-5 text-muted small fw-bold" id="aviso-h-vazio">
                        <i class="fas fa-bell-slash d-block mb-2 opacity-25" style="font-size:2rem;"></i>
                        Nenhum aviso pendente.
                    </div>
                    <?php else: foreach($avisos_header as $avh): ?>
                    <div class="aviso-item-header px-3 py-2 border-bottom" id="aviso-h-<?= $avh['ID'] ?>" style="background:#fff; transition:background .15s;">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge text-white" style="font-size:0.55rem; background:<?= $avh['TIPO']==='AUTOMATICO'?'#6f42c1':'#0d6efd' ?>; flex-shrink:0;">
                                        <?= $avh['TIPO']==='AUTOMATICO' ? 'AUTO' : 'MASTER' ?>
                                    </span>
                                    <small class="text-muted text-nowrap" style="font-size:0.68rem;"><?= date('d/m/Y H:i', strtotime($avh['DATA_CRIACAO'])) ?></small>
                                </div>
                                <div class="fw-bold text-dark text-truncate" style="font-size:0.82rem;"><?= htmlspecialchars($avh['ASSUNTO']) ?></div>
                                <?php if(!empty($avh['NOME_CRIADOR'])): ?>
                                <small class="text-muted" style="font-size:0.68rem;">por <?= htmlspecialchars($avh['NOME_CRIADOR']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-column gap-1 flex-shrink-0">
                                <button class="btn btn-success btn-sm fw-bold py-0 px-2" style="font-size:0.68rem;" onclick="marcarLidoHeader(<?= $avh['ID'] ?>)" title="Marcar como lido">
                                    <i class="fas fa-check me-1"></i>Lido
                                </button>
                                <button class="btn btn-outline-secondary btn-sm fw-bold py-0 px-2 lh-1" style="font-size:0.75rem;" onclick="dispensarAvisoHeader(<?= $avh['ID'] ?>)" title="Fechar (sem marcar como lido)">&times;</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="text-center py-2 border-top" style="background:#f8f9fa;">
                    <a href="/modulos/configuracao/anotacoes/index.php#avisos" class="small fw-bold text-primary text-decoration-none">
                        <i class="fas fa-external-link-alt me-1"></i> Ver todos os avisos
                    </a>
                </div>
            </div>
        </div>

        <!-- Suporte -->
        <button class="btn btn-success btn-sm fw-bold shadow-sm border-0" style="background-color: #25D366;" onclick="abrirModalSuporteLogado()">
            <i class="fab fa-whatsapp me-1"></i> <span class="d-none d-md-inline">Falar com Suporte</span>
        </button>

        <!-- Usuário -->
        <div class="user-profile border-start ps-3 ms-1">
            <div>
                <p class="user-name mb-0">Olá, <?= htmlspecialchars($primeiro_nome) ?></p>
                <?php if($pode_ver_online): ?>
                    <span class="user-status clickable" onclick="carregarUsuariosOnline(true)" title="Ver usuários online">
                        <i class="fas fa-circle text-success" style="font-size:8px;"></i> <span id="topo_contador_online">0</span> Online
                    </span>
                <?php else: ?>
                    <span class="user-status"><i class="fas fa-circle text-success" style="font-size:8px;"></i> Online</span>
                <?php endif; ?>
            </div>
            <div class="dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="text-decoration:none;">
                    <i class="fas fa-user-circle user-avatar text-dark"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><a class="dropdown-item text-danger fw-bold" href="/logout.php"><i class="fas fa-sign-out-alt text-danger"></i> Sair do Sistema</a></li>
                </ul>
            </div>
        </div>

    </div>
</div>

<!-- ================================================================
     SIDEBAR OVERLAY + PAINEL
================================================================ -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="sidebarFecharOverlay(event)">
    <div class="sidebar-panel" id="sidebarPanel">

        <!-- Cabeçalho -->
        <div class="sidebar-head">
            <div class="sidebar-head-title">
                <i class="fas fa-th-large"></i> MENU PRINCIPAL
            </div>
            <button class="sidebar-close" onclick="sidebarFechar()" title="Fechar">&times;</button>
        </div>

        <!-- Itens -->
        <div class="sidebar-body">

            <?php if(podeAcessarMenu($pdo, 'MENU_USUARIO')): ?>
            <div class="sidebar-section-title">Gestão</div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-users-cog menu-icon"></i> Usuário
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_USUARIO')): ?>
                <a class="sidebar-subitem" href="/modulos/cliente_e_usuario/cadastro_usuario.php"><i class="fas fa-user-shield"></i> Cadastro Usuário</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'USUARIO_FINANCEIRO')): ?>
                <a class="sidebar-subitem" href="/modulos/cliente_e_usuario/cadastro_cliente_financeiro.php"><i class="fas fa-wallet"></i> Painel Financeiro</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_GESTAO_PERMISSOES')): ?>
                <a class="sidebar-subitem" href="/modulos/cliente_e_usuario/permissoes.php"><i class="fas fa-key"></i> Gestão de Permissões</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_CLIENTE')): ?>
                <a class="sidebar-subitem" href="/modulos/cliente_e_usuario/cadastro_cliente.php"><i class="fas fa-user"></i> Cadastro Cliente</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_EMPRESA')): ?>
                <a class="sidebar-subitem" href="/modulos/cliente_e_usuario/cadastro_empresa.php"><i class="fas fa-building"></i> Cadastro Empresa</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CADASTRO_CONSIGNADO')): ?>
                <a class="sidebar-subitem" href="/modulos/cadastro_cliente_consignado/index.php"><i class="fas fa-address-card"></i> Cadastro Consignado</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_CAMPANHA')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item campanha" onclick="sidebarToggleSub(this)">
                <i class="fas fa-bullhorn menu-icon"></i> Campanhas
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <a class="sidebar-subitem" href="/modulos/campanhas/index.php"><i class="fas fa-list"></i> Relação de Campanhas</a>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_STATUS')): ?>
                <a class="sidebar-subitem" href="/modulos/campanhas/status.php"><i class="fas fa-tags"></i> Status e Qualificações</a>
                <?php endif; ?>
                <a class="sidebar-subitem" href="/modulos/campanhas/agenda.php"><i class="fas fa-calendar-alt"></i> Minha Agenda</a>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_BANCO_DADOS')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-database menu-icon"></i> Banco de Dados
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_CONSULTA')): ?>
                <a class="sidebar-subitem" href="/modulos/banco_dados/consulta.php"><i class="fas fa-search"></i> Consulta</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_IMPORTACAO')): ?>
                <a class="sidebar-subitem" href="/modulos/banco_dados/importacao.php"><i class="fas fa-file-import"></i> Importação</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_BD_EXPORTACAO')): ?>
                <a class="sidebar-subitem" href="/modulos/banco_dados/exportacao.php"><i class="fas fa-file-export"></i> Exportação</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_BANCO_PLANILHAS')): ?>
            <div class="sidebar-divider"></div>
            <a class="sidebar-link" href="/modulos/banco_planilhas/index.php">
                <i class="fas fa-server menu-icon"></i> Banco de Planilhas
            </a>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_OPERACIONAL')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-briefcase menu-icon"></i> Operacional
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_META_ADS')): ?>
                <a class="sidebar-subitem" href="/modulos/meta_ads/index.php"><i class="fab fa-meta"></i> META ADS</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_HIST_INSS')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/Promosys_inss/index.php"><i class="fas fa-university"></i> HIST INSS</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_TAREFAS')): ?>
                <a class="sidebar-subitem" href="/modulos/operacional/tarefas/index.php"><i class="fas fa-tasks"></i> Controle de Tarefas</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_FATOR_CONFERI')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/fator_conferi/index.php"><i class="fas fa-search-dollar"></i> Fator Conferi</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_INTEGRACAO_V8')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/v8_clt_margem_e_digitacao/index.php"><i class="fas fa-handshake"></i> Integração V8 Digital</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_WHATS_API')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/whats_api_oficial/index.php"><i class="fab fa-whatsapp"></i> WHATS API OFICIAL</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_OP_WAPI')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/w-api/index.php"><i class="fas fa-robot"></i> W-API (Múltiplas Conexões)</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_SMS')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/disparo_sms/index.php"><i class="fas fa-sms"></i> Disparo SMS</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_COMERCIAL')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-store menu-icon"></i> Comercial
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_PRODUTOS')): ?>
                <a class="sidebar-subitem" href="/modulos/comercial/produtos/index.php"><i class="fas fa-box-open"></i> Catálogo de Produtos</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_PEDIDOS')): ?>
                <a class="sidebar-subitem" href="/modulos/comercial/pedidos/index.php"><i class="fas fa-shopping-cart"></i> Pedidos e Renovações</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_ENTREGAS')): ?>
                <a class="sidebar-subitem" href="/modulos/comercial/entregas/index.php"><i class="fas fa-truck"></i> Logística e Entregas</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_COM_COMISSOES')): ?>
                <a class="sidebar-subitem" href="/modulos/comercial/comissao/index.php"><i class="fas fa-hand-holding-usd"></i> Gestão de Comissões</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_FINANCEIRO')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-wallet menu-icon"></i> Financeiro
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_FIN_PAINEL')): ?>
                <a class="sidebar-subitem" href="/modulos/financeiro/index.php"><i class="fas fa-chart-pie"></i> Painel Financeiro</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if(podeAcessarMenu($pdo, 'MENU_CONFIGURACAO')): ?>
            <div class="sidebar-divider"></div>
            <button class="sidebar-item" onclick="sidebarToggleSub(this)">
                <i class="fas fa-cog menu-icon"></i> Configuração
                <i class="fas fa-chevron-down sidebar-chevron"></i>
            </button>
            <div class="sidebar-sub">
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CONF_GERAL')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/index.php"><i class="fas fa-sliders-h"></i> Configuração Geral</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_CONF_MYSQL')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/resumo_mysql.php"><i class="fas fa-table"></i> Resumo MySQL</a>
                <?php endif; ?>
                <?php if(podeAcessarMenu($pdo, 'SUBMENU_ANOTACOES_GERAIS')): ?>
                <a class="sidebar-subitem" href="/modulos/configuracao/anotacoes/index.php"><i class="fas fa-sticky-note"></i> Anotações Gerais</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Sair -->
            <div class="sidebar-divider"></div>
            <a class="sidebar-link" href="/logout.php" style="color:#ff8a8a;">
                <i class="fas fa-sign-out-alt menu-icon"></i> Sair do Sistema
            </a>

        </div>
    </div>
</div>

<script>
function sidebarAbrir() {
    document.getElementById('sidebarOverlay').classList.add('aberto');
    document.body.style.overflow = 'hidden';
}
function sidebarFechar() {
    document.getElementById('sidebarOverlay').classList.remove('aberto');
    document.body.style.overflow = '';
}
function sidebarFecharOverlay(e) {
    if (e.target === document.getElementById('sidebarOverlay')) sidebarFechar();
}
function sidebarToggleSub(btn) {
    const sub = btn.nextElementSibling;
    const aberto = sub.classList.contains('aberto');
    // fecha todos
    document.querySelectorAll('.sidebar-sub.aberto').forEach(s => s.classList.remove('aberto'));
    document.querySelectorAll('.sidebar-item.aberto').forEach(b => b.classList.remove('aberto'));
    // abre o clicado (se não estava aberto)
    if (!aberto) {
        sub.classList.add('aberto');
        btn.classList.add('aberto');
    }
}
// Fecha sidebar com ESC
document.addEventListener('keydown', e => { if (e.key === 'Escape') sidebarFechar(); });
</script>

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

    // ========== PAINEL DE AVISOS INTERNOS ==========
    function toggleAvisosPanel(e) {
        if (e) e.stopPropagation();
        const panel = document.getElementById('avisos-panel-header');
        panel.style.display = panel.style.display === 'none' ? '' : 'none';
    }

    // Fecha o painel ao clicar fora dele
    document.addEventListener('click', function(e) {
        const panel = document.getElementById('avisos-panel-header');
        const li    = document.getElementById('li-sino-avisos');
        if (panel && panel.style.display !== 'none' && li && !li.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    async function marcarLidoHeader(id) {
        const fd = new FormData();
        fd.append('acao', 'marcar_lido');
        fd.append('id', id);
        try {
            await fetch('/modulos/configuracao/anotacoes/ajax_anotacao.php', { method: 'POST', body: fd });
        } catch(err) {}
        _removerAvisoHeader(id);
    }

    function dispensarAvisoHeader(id) {
        _removerAvisoHeader(id);
    }

    function _removerAvisoHeader(id) {
        const el = document.getElementById('aviso-h-' + id);
        if (el) el.remove();
        const restantes = document.querySelectorAll('.aviso-item-header').length;
        const badge = document.getElementById('badge-avisos-h');
        if (badge) {
            if (restantes > 0) {
                badge.textContent = restantes;
                badge.classList.remove('d-none');
            } else {
                badge.classList.add('d-none');
                const lista = document.getElementById('lista-avisos-header');
                if (lista) lista.innerHTML = '<div class="text-center py-5 text-muted small fw-bold"><i class="fas fa-bell-slash d-block mb-2 opacity-25" style="font-size:2rem;"></i>Nenhum aviso pendente.</div>';
            }
        }
    }
    // ========== FIM AVISOS INTERNOS ==========

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