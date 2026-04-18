<?php
session_start();
$path_header = '../../../includes/header.php';
if (file_exists($path_header)) { include_once $path_header; }

require_once '../../../conexao.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) { include_once $caminho_permissoes; }

if (!function_exists('verificaPermissao') || !verificaPermissao($pdo, 'SUBMENU_SMS', 'TELA')) {
    echo '<div class="alert alert-danger m-4"><i class="fas fa-lock me-2"></i>Sem permissão de acesso ao módulo de SMS.</div>';
    if (file_exists('../../../includes/footer.php')) include_once '../../../includes/footer.php';
    exit;
}

$perm_manual   = verificaPermissao($pdo, 'SUBMENU_SMS_DISPARO_MANUAL', 'TELA');
$perm_lote     = verificaPermissao($pdo, 'SUBMENU_SMS_DISPARO_LOTE',   'TELA');
$perm_chat     = verificaPermissao($pdo, 'SUBMENU_SMS_CHAT',            'TELA');
$perm_carteira = verificaPermissao($pdo, 'SUBMENU_CARTEIRA',            'TELA');

$cpf_logado   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$grupo_logado = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master    = in_array($grupo_logado, ['MASTER','ADMIN','ADMINISTRADOR']);

$id_empresa_logado = null;
try {
    $stmtE = $pdo->prepare("SELECT id_empresa FROM CLIENTE_USUARIO WHERE CPF=? LIMIT 1");
    $stmtE->execute([$cpf_logado]);
    $id_empresa_logado = $stmtE->fetchColumn() ?: null;
} catch (Exception $e) {}

// Garante tabelas
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `DISPARO_SMS_CONFIG` (
        `ID` int NOT NULL AUTO_INCREMENT, `id_empresa` int DEFAULT NULL,
        `NOME_CONFIG` varchar(100) NOT NULL, `TOKEN_API` varchar(255) NOT NULL,
        `SERVICO` varchar(50) DEFAULT 'short', `ATIVO` tinyint(1) DEFAULT 1,
        `DATA_CRIACAO` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`ID`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Carrega configs disponíveis
$configs = [];
try {
    if ($is_master) {
        $stmt = $pdo->query("SELECT * FROM DISPARO_SMS_CONFIG WHERE ATIVO=1 ORDER BY ID ASC");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM DISPARO_SMS_CONFIG WHERE ATIVO=1 AND id_empresa=? ORDER BY ID ASC");
        $stmt->execute([$id_empresa_logado]);
    }
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<style>
/* ── Layout geral ───────────────────────────────── */
.sms-card { background:#fff; border-radius:10px; box-shadow:0 1px 6px rgba(0,0,0,.07); }
.sms-title { font-size:22px; font-weight:700; color:#b02a37; }

/* ── Tabs ───────────────────────────────────────── */
.sms-nav .nav-link { font-weight:600; font-size:13px; color:#555; padding:10px 18px; border:none; border-bottom:3px solid transparent; border-radius:0; }
.sms-nav .nav-link.active { color:#b02a37; border-bottom-color:#b02a37; background:none; }
.sms-nav .nav-link:hover { color:#b02a37; }
.sms-nav { border-bottom:2px solid #e9ecef; }

/* ── Painel stats ───────────────────────────────── */
.stat-card { border-radius:10px; padding:18px 20px; color:#fff; display:flex; align-items:center; gap:14px; }
.stat-card .stat-icon { font-size:28px; opacity:.85; }
.stat-card .stat-val  { font-size:28px; font-weight:700; line-height:1; }
.stat-card .stat-lbl  { font-size:12px; opacity:.85; }

/* ── Form helpers ───────────────────────────────── */
.tag-btn { font-size:11px; padding:3px 8px; border-radius:4px; cursor:pointer; border:1px solid #adb5bd; background:#f8f9fa; transition:.15s; }
.tag-btn:hover { background:#b02a37; color:#fff; border-color:#b02a37; }
.preview-sms { background:#e8f5e9; border-radius:12px 12px 12px 0; padding:12px 16px; font-size:13px; max-width:300px; margin-top:10px; line-height:1.6; white-space:pre-wrap; }
.char-count { font-size:11px; color:#888; }

/* ── Busca cliente ──────────────────────────────── */
#sms-busca-resultado { max-height:220px; overflow-y:auto; border:1px solid #dee2e6; border-radius:6px; }
.cliente-item { padding:8px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #f0f0f0; transition:.1s; }
.cliente-item:hover { background:#fff3f3; }

/* ── Lote upload ────────────────────────────────── */
.caixa-upload { border:2px dashed #b02a37; background:#fff5f5; padding:28px; border-radius:10px; text-align:center; cursor:pointer; transition:.2s; }
.caixa-upload:hover { background:#ffe8e8; }
.lote-preview-wrap { max-height:260px; overflow-y:auto; }

/* ── Relatório ──────────────────────────────────── */
.badge-status-ENVIADO    { background:#198754; }
.badge-status-ENTREGUE   { background:#0d6efd; }
.badge-status-FALHA      { background:#dc3545; }
.badge-status-AGUARDANDO { background:#6c757d; }
.badge-status-AGENDADO   { background:#fd7e14; }
.badge-status-SEM_SALDO  { background:#6f42c1; }

/* ── Chat ───────────────────────────────────────── */
.chat-wrap       { display:flex; height:calc(100vh - 200px); min-height:480px; border:1px solid #e0e0e0; border-radius:10px; overflow:hidden; }
.chat-sidebar    { width:280px; min-width:280px; border-right:1px solid #e0e0e0; display:flex; flex-direction:column; background:#fff; }
.chat-search     { padding:10px 12px; border-bottom:1px solid #eee; }
.chat-list       { flex:1; overflow-y:auto; }
.chat-conv-item  { padding:10px 14px; cursor:pointer; border-bottom:1px solid #f5f5f5; transition:.15s; }
.chat-conv-item:hover, .chat-conv-item.ativo { background:#fff3f3; }
.chat-conv-nome  { font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.chat-conv-msg   { font-size:11.5px; color:#888; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.chat-conv-hora  { font-size:10px; color:#aaa; flex-shrink:0; }
.badge-nao-lido  { background:#b02a37; border-radius:10px; padding:1px 6px; font-size:10px; color:#fff; }
.chat-main       { flex:1; display:flex; flex-direction:column; background:#f8f9fa; }
.chat-header     { padding:12px 16px; background:#fff; border-bottom:1px solid #e0e0e0; display:flex; align-items:center; gap:10px; }
.chat-msgs       { flex:1; overflow-y:auto; padding:16px; display:flex; flex-direction:column; gap:10px; }
.chat-bubble     { max-width:70%; padding:8px 12px; border-radius:12px; font-size:13px; line-height:1.5; }
.chat-bubble.env { background:#b02a37; color:#fff; align-self:flex-end; border-radius:12px 12px 0 12px; }
.chat-bubble.rec { background:#fff; color:#333; align-self:flex-start; border-radius:12px 12px 12px 0; box-shadow:0 1px 2px rgba(0,0,0,.08); }
.chat-bubble .hora { font-size:10px; opacity:.65; display:block; margin-top:2px; text-align:right; }
.chat-input-area { padding:12px; background:#fff; border-top:1px solid #e0e0e0; display:flex; gap:8px; }
.chat-input-area textarea { flex:1; resize:none; border:1px solid #dee2e6; border-radius:8px; padding:8px 12px; font-size:13px; }
.chat-empty { flex:1; display:flex; align-items:center; justify-content:center; color:#aaa; font-size:14px; }

/* ── Carteira ───────────────────────────────────── */
.saldo-box { background:linear-gradient(135deg,#b02a37,#7b1d25); color:#fff; border-radius:12px; padding:24px 28px; }
</style>

<div class="container-fluid mt-3">

    <!-- Cabeçalho -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <div>
            <div class="sms-title"><i class="fas fa-sms me-2"></i>Disparo SMS</div>
            <small class="text-muted">Disparo Pro — API SMS</small>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <?php if ($configs): ?>
            <select id="sms-config-global" class="form-select form-select-sm" style="max-width:200px;">
                <?php foreach ($configs as $c): ?>
                <option value="<?= $c['ID'] ?>"><?= htmlspecialchars($c['NOME_CONFIG']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i>Nenhuma API configurada</span>
            <?php endif; ?>
            <?php if ($perm_carteira): ?>
            <button class="btn btn-sm btn-outline-secondary" onclick="smsConsultarSaldo()"><i class="fas fa-wallet me-1"></i>Saldo</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Abas -->
    <ul class="nav sms-nav mb-0" id="smsTabs">
        <li class="nav-item"><button class="nav-link active" data-sms-tab="painel"><i class="fas fa-chart-bar me-1"></i>Painel</button></li>
        <?php if ($perm_manual): ?>
        <li class="nav-item"><button class="nav-link" data-sms-tab="manual"><i class="fas fa-paper-plane me-1"></i>Disparo Manual</button></li>
        <?php endif; ?>
        <?php if ($perm_lote): ?>
        <li class="nav-item"><button class="nav-link" data-sms-tab="lote"><i class="fas fa-layer-group me-1"></i>Disparo em Lote</button></li>
        <?php endif; ?>
        <li class="nav-item"><button class="nav-link" data-sms-tab="relatorio"><i class="fas fa-list-alt me-1"></i>Relatório</button></li>
        <?php if ($perm_chat): ?>
        <li class="nav-item"><button class="nav-link" data-sms-tab="chat"><i class="fas fa-comments me-1"></i>Chat <span id="sms-badge-chat" class="badge-nao-lido d-none">0</span></button></li>
        <?php endif; ?>
        <?php if ($perm_carteira): ?>
        <li class="nav-item"><button class="nav-link" data-sms-tab="carteira"><i class="fas fa-cog me-1"></i>Carteira & Config</button></li>
        <?php endif; ?>
    </ul>

    <div class="sms-card p-3 p-md-4" style="border-radius:0 0 10px 10px; border-top:none;">

    <!-- ════════════════════════════════════════════
         TAB: PAINEL
    ════════════════════════════════════════════ -->
    <div id="sms-tab-painel" class="sms-tab-content">
        <div class="row g-3 mb-4" id="sms-stats-cards">
            <div class="col-6 col-md-3"><div class="stat-card" style="background:linear-gradient(135deg,#b02a37,#7b1d25)"><i class="fas fa-paper-plane stat-icon"></i><div><div class="stat-val" id="st-hoje">—</div><div class="stat-lbl">Disparos hoje</div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card" style="background:linear-gradient(135deg,#198754,#0f5132)"><i class="fas fa-check-double stat-icon"></i><div><div class="stat-val" id="st-entregues">—</div><div class="stat-lbl">Entregues total</div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card" style="background:linear-gradient(135deg,#dc3545,#a71d2a)"><i class="fas fa-times-circle stat-icon"></i><div><div class="stat-val" id="st-falhas">—</div><div class="stat-lbl">Falhas total</div></div></div></div>
            <div class="col-6 col-md-3"><div class="stat-card" style="background:linear-gradient(135deg,#6f42c1,#4a2b8e)"><i class="fas fa-comment-dots stat-icon"></i><div><div class="stat-val" id="st-chat">—</div><div class="stat-lbl">Não lidas</div></div></div></div>
        </div>
        <div class="text-center py-3" id="sms-painel-loading"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i></div>
        <div id="sms-painel-lotes-wrap" style="display:none;">
            <h6 class="fw-bold mb-2"><i class="fas fa-layer-group me-2 text-danger"></i>Últimos Lotes</h6>
            <div id="sms-painel-lotes"></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════
         TAB: DISPARO MANUAL
    ════════════════════════════════════════════ -->
    <?php if ($perm_manual): ?>
    <div id="sms-tab-manual" class="sms-tab-content" style="display:none;">
        <div class="row g-4">
            <div class="col-lg-7">

                <!-- Modo seleção -->
                <div class="mb-3 d-flex gap-2">
                    <button class="btn btn-sm btn-danger fw-bold active" id="btn-modo-busca" onclick="smsModo('busca')"><i class="fas fa-search me-1"></i>Pesquisar Cliente</button>
                    <button class="btn btn-sm btn-outline-secondary fw-bold" id="btn-modo-manual" onclick="smsModo('manual')"><i class="fas fa-keyboard me-1"></i>Informar Manualmente</button>
                </div>

                <!-- Busca cliente -->
                <div id="bloco-busca">
                    <div class="input-group mb-2">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="sms-busca-input" class="form-control" placeholder="Nome, CPF ou telefone..." oninput="smsBuscarCliente(this.value)">
                    </div>
                    <div id="sms-busca-resultado" class="d-none mb-3"></div>
                </div>

                <!-- Campo manual -->
                <div id="bloco-manual" style="display:none;">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Nome do Destinatário</label>
                            <input type="text" id="sms-nome-manual" class="form-control" placeholder="Ex: João Silva" oninput="smsAtualizarPreview()">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Telefone (com DDD)</label>
                            <input type="text" id="sms-tel-manual" class="form-control" placeholder="Ex: 11999998888" maxlength="15">
                        </div>
                    </div>
                </div>

                <!-- Destinatário selecionado -->
                <div id="sms-dest-selecionado" class="alert alert-danger py-2 d-none mb-3" style="background:#fff3f3; border-color:#b02a37; color:#b02a37;">
                    <i class="fas fa-user me-2"></i>
                    <strong id="sms-dest-nome">—</strong> &nbsp;|&nbsp; <span id="sms-dest-tel">—</span>
                    <button class="btn btn-sm btn-outline-danger ms-2 py-0 px-1" onclick="smsLimparDest()" style="font-size:11px;">Trocar</button>
                </div>

                <!-- Composer -->
                <label class="form-label small fw-bold">Mensagem</label>
                <div class="d-flex gap-1 flex-wrap mb-2">
                    <span class="tag-btn" onclick="smsInserirTag('{PRIMEIRO_NOME}')">{PRIMEIRO_NOME}</span>
                    <span class="tag-btn" onclick="smsInserirTag('{NOME_COMPLETO}')">{NOME_COMPLETO}</span>
                    <span class="tag-btn" onclick="smsInserirTag('{NOME_DISCRETO}')">{NOME_DISCRETO}</span>
                </div>
                <textarea id="sms-msg-manual" class="form-control mb-1" rows="5" placeholder="Digite sua mensagem..." oninput="smsAtualizarPreview(); smsContarChars(this, 'sms-chars-manual')"></textarea>
                <div class="char-count mb-3" id="sms-chars-manual">0 / 160 chars (1 SMS)</div>

                <!-- Codificação -->
                <div class="d-flex gap-3 mb-3">
                    <div>
                        <label class="form-label small fw-bold">Codificação</label><br>
                        <div class="btn-group btn-group-sm">
                            <input type="radio" class="btn-check" name="sms-cod-manual" id="cod-m-7" value="0" checked>
                            <label class="btn btn-outline-secondary" for="cod-m-7">SEM ACENTUAÇÃO (7-BIT)</label>
                            <input type="radio" class="btn-check" name="sms-cod-manual" id="cod-m-16" value="8">
                            <label class="btn btn-outline-secondary" for="cod-m-16">COM ACENTUAÇÃO (16-BIT)</label>
                        </div>
                    </div>
                </div>

                <!-- Agendamento -->
                <div class="mb-3">
                    <label class="form-label small fw-bold">Agendamento <small class="text-muted">(opcional — deixe vazio para envio imediato)</small></label>
                    <input type="datetime-local" id="sms-agenda-manual" class="form-control" style="max-width:260px;">
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-danger fw-bold px-4" onclick="smsEnviarManual()"><i class="fas fa-paper-plane me-2"></i>Enviar Agora</button>
                    <button class="btn btn-outline-danger fw-bold" onclick="smsAgendarManual()"><i class="fas fa-clock me-2"></i>Agendar</button>
                </div>

            </div>
            <!-- Preview -->
            <div class="col-lg-5 d-flex flex-column align-items-center">
                <div style="background:#e0e0e0; border-radius:36px; padding:18px 12px; width:240px; box-shadow:0 8px 24px rgba(0,0,0,.12);">
                    <div style="background:#f5f5f5; border-radius:24px; padding:16px 10px; min-height:340px;">
                        <div class="text-center mb-3" style="font-size:11px; color:#aaa;">Pré-visualização</div>
                        <div class="preview-sms" id="sms-preview-box" style="display:none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════
         TAB: DISPARO EM LOTE
    ════════════════════════════════════════════ -->
    <?php if ($perm_lote): ?>
    <div id="sms-tab-lote" class="sms-tab-content" style="display:none;">
        <div class="row g-4">
            <div class="col-lg-6">
                <label class="form-label small fw-bold">Nome do Lote</label>
                <input type="text" id="sms-nome-lote" class="form-control mb-3" placeholder="Ex: Campanha Consignado Abril/2026">

                <!-- Upload CSV -->
                <label class="form-label small fw-bold">Lista de Destinatários <small class="text-muted">(CSV separado por ; ou ,)</small></label>
                <div class="caixa-upload mb-1" id="lote-drop-area" onclick="document.getElementById('sms-lote-file').click()" ondragover="event.preventDefault()" ondrop="smsLoteDrop(event)">
                    <i class="fas fa-file-csv fa-2x text-danger mb-2 d-block"></i>
                    <strong>Clique ou arraste o arquivo CSV/XLSX</strong><br>
                    <small class="text-muted">Colunas: <code>nome ; telefone ; tag1 ; tag2 ; tag3</code></small>
                </div>
                <input type="file" id="sms-lote-file" accept=".csv,.txt" class="d-none" onchange="smsLerCsv(this)">
                <small class="text-muted d-block mb-3">A primeira linha é o cabeçalho e será ignorada.</small>

                <!-- Preview tabela -->
                <div id="sms-lote-preview-wrap" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small fw-bold text-success" id="sms-lote-qtd"></span>
                        <button class="btn btn-sm btn-outline-danger py-0" onclick="smsLoteLimpar()"><i class="fas fa-trash me-1"></i>Limpar</button>
                    </div>
                    <div class="lote-preview-wrap">
                        <table class="table table-sm table-bordered mb-0" style="font-size:11.5px;">
                            <thead class="table-dark"><tr><th>#</th><th>Nome</th><th>Telefone</th><th>Tag1</th><th>Tag2</th><th>Tag3</th></tr></thead>
                            <tbody id="sms-lote-tbody"></tbody>
                        </table>
                    </div>
                    <div class="mt-2 d-flex flex-wrap gap-1" id="sms-tags-detectadas"></div>
                </div>
            </div>

            <div class="col-lg-6">
                <label class="form-label small fw-bold">Mensagem</label>
                <div class="d-flex gap-1 flex-wrap mb-2">
                    <span class="tag-btn" onclick="smsInserirTagLote('{PRIMEIRO_NOME}')">{PRIMEIRO_NOME}</span>
                    <span class="tag-btn" onclick="smsInserirTagLote('{NOME_COMPLETO}')">{NOME_COMPLETO}</span>
                    <span class="tag-btn" onclick="smsInserirTagLote('{NOME_DISCRETO}')">{NOME_DISCRETO}</span>
                    <span class="tag-btn" onclick="smsInserirTagLote('{TAG1}')">{TAG1}</span>
                    <span class="tag-btn" onclick="smsInserirTagLote('{TAG2}')">{TAG2}</span>
                    <span class="tag-btn" onclick="smsInserirTagLote('{TAG3}')">{TAG3}</span>
                </div>
                <textarea id="sms-msg-lote" class="form-control mb-1" rows="6" placeholder="Olá {PRIMEIRO_NOME}, temos uma oferta especial para você!" oninput="smsContarChars(this,'sms-chars-lote')"></textarea>
                <div class="char-count mb-3" id="sms-chars-lote">0 / 160 chars (1 SMS)</div>

                <div class="d-flex gap-3 mb-3">
                    <div>
                        <label class="form-label small fw-bold">Codificação</label><br>
                        <div class="btn-group btn-group-sm">
                            <input type="radio" class="btn-check" name="sms-cod-lote" id="cod-l-7" value="0" checked>
                            <label class="btn btn-outline-secondary" for="cod-l-7">7-BIT</label>
                            <input type="radio" class="btn-check" name="sms-cod-lote" id="cod-l-16" value="8">
                            <label class="btn btn-outline-secondary" for="cod-l-16">16-BIT</label>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Agendamento <small class="text-muted">(opcional)</small></label>
                    <input type="datetime-local" id="sms-agenda-lote" class="form-control" style="max-width:260px;">
                </div>

                <button class="btn btn-danger fw-bold px-4" onclick="smsCriarLote()">
                    <i class="fas fa-rocket me-2"></i>Disparar Lote
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════
         TAB: RELATÓRIO
    ════════════════════════════════════════════ -->
    <div id="sms-tab-relatorio" class="sms-tab-content" style="display:none;">
        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <div>
                <label class="form-label small fw-bold mb-1">Status</label>
                <select id="rel-status" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <option>ENVIADO</option><option>ENTREGUE</option>
                    <option>FALHA</option><option>AGENDADO</option><option>AGUARDANDO</option>
                </select>
            </div>
            <div>
                <label class="form-label small fw-bold mb-1">De</label>
                <input type="date" id="rel-data-ini" class="form-control form-control-sm">
            </div>
            <div>
                <label class="form-label small fw-bold mb-1">Até</label>
                <input type="date" id="rel-data-fim" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <button class="btn btn-danger btn-sm fw-bold" onclick="smsCarregarRelatorio()"><i class="fas fa-filter me-1"></i>Filtrar</button>
            <span class="ms-auto small text-muted" id="rel-contador"></span>
        </div>
        <div id="sms-rel-wrap">
            <div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════
         TAB: CHAT
    ════════════════════════════════════════════ -->
    <?php if ($perm_chat): ?>
    <div id="sms-tab-chat" class="sms-tab-content" style="display:none;">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-bold small text-muted">Conversas</span>
            <button class="btn btn-sm btn-outline-danger fw-bold" onclick="smsSincronizarRespostas()"><i class="fas fa-sync-alt me-1"></i>Sincronizar Respostas</button>
        </div>
        <div class="chat-wrap">
            <!-- Sidebar -->
            <div class="chat-sidebar">
                <div class="chat-search">
                    <input type="text" class="form-control form-control-sm" placeholder="Buscar..." id="chat-busca" oninput="smsFiltrarConversas(this.value)">
                </div>
                <div class="chat-list" id="chat-lista"></div>
            </div>
            <!-- Área principal -->
            <div class="chat-main">
                <div id="chat-header-vazio" class="chat-empty">
                    <div class="text-center"><i class="fas fa-comments fa-3x mb-3 opacity-25"></i><br>Selecione uma conversa</div>
                </div>
                <div id="chat-conv-wrap" style="display:none; flex:1; flex-direction:column; display:none;">
                    <div class="chat-header" id="chat-header-info"></div>
                    <div class="chat-msgs" id="chat-msgs"></div>
                    <div class="chat-input-area">
                        <textarea id="chat-reply-msg" rows="2" placeholder="Digite uma mensagem..." onkeydown="if(event.ctrlKey&&event.key==='Enter')smsResponderChat()"></textarea>
                        <button class="btn btn-danger fw-bold" onclick="smsResponderChat()"><i class="fas fa-paper-plane"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ════════════════════════════════════════════
         TAB: CARTEIRA & CONFIG
    ════════════════════════════════════════════ -->
    <?php if ($perm_carteira): ?>
    <div id="sms-tab-carteira" class="sms-tab-content" style="display:none;">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="saldo-box mb-3">
                    <div class="small opacity-75 mb-1">Saldo disponível</div>
                    <div style="font-size:32px; font-weight:700;" id="cart-saldo-val">—</div>
                    <button class="btn btn-sm btn-light mt-2 fw-bold" onclick="smsConsultarSaldo(true)"><i class="fas fa-sync-alt me-1"></i>Atualizar</button>
                </div>
            </div>
            <div class="col-md-8">
                <!-- Form configuração -->
                <div class="border rounded p-3 mb-3">
                    <h6 class="fw-bold mb-3"><i class="fas fa-key me-2 text-danger"></i>Adicionar / Editar Configuração</h6>
                    <input type="hidden" id="cfg-edit-id" value="0">
                    <div class="row g-2">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">Nome da Configuração</label>
                            <input type="text" id="cfg-nome" class="form-control form-control-sm" placeholder="Ex: Conta Principal">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label small fw-bold">Token API (Bearer)</label>
                            <input type="text" id="cfg-token" class="form-control form-control-sm" placeholder="Cole o token aqui">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Serviço</label>
                            <select id="cfg-servico" class="form-select form-select-sm">
                                <option value="short">SHORT CODE</option>
                                <option value="long">LONG CODE</option>
                            </select>
                        </div>
                        <?php if ($is_master): ?>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">id_empresa</label>
                            <input type="number" id="cfg-empresa" class="form-control form-control-sm" placeholder="ID da empresa">
                        </div>
                        <?php endif; ?>
                        <div class="col-12 mt-1">
                            <button class="btn btn-danger btn-sm fw-bold" onclick="smsSalvarConfig()"><i class="fas fa-save me-1"></i>Salvar</button>
                            <button class="btn btn-outline-secondary btn-sm ms-2" onclick="smsLimparFormConfig()">Limpar</button>
                        </div>
                    </div>
                </div>
                <!-- Lista configs -->
                <div id="sms-cfg-lista"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- /sms-card -->
</div>

<!-- Modal detalhe lote -->
<div class="modal fade" id="smsModalLote" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:#b02a37; color:#fff;">
                <h6 class="modal-title fw-bold mb-0" id="smsModalLoteTitulo"><i class="fas fa-layer-group me-2"></i>Detalhe do Lote</h6>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="smsModalLoteBody">
                <div class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></div>
            </div>
        </div>
    </div>
</div>

<script>
// ─── Estado ──────────────────────────────────────────────────────────────────
let smsDestAtual   = { nome:'', tel:'' };
let smsLoteItens   = [];
let chatTelAtual   = '';
let chatNomeAtual  = '';
let chatConversas  = [];
let smsModalLoteObj = null;

// ─── Inicialização ───────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    // Tabs
    document.querySelectorAll('[data-sms-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-sms-tab]').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.sms-tab-content').forEach(t => t.style.display='none');
            btn.classList.add('active');
            const tab = document.getElementById('sms-tab-'+btn.dataset.smsTab);
            if (tab) tab.style.display='';
            if (btn.dataset.smsTab==='painel')   smsPainelCarregar();
            if (btn.dataset.smsTab==='relatorio') smsCarregarRelatorio();
            if (btn.dataset.smsTab==='chat')      smsChatCarregar();
            if (btn.dataset.smsTab==='carteira')  { smsCarregarConfigs(); smsConsultarSaldo(true); }
        });
    });

    if (document.getElementById('smsModalLote'))
        smsModalLoteObj = new bootstrap.Modal(document.getElementById('smsModalLote'));

    smsPainelCarregar();
});

// ─── Requisição centralizada ─────────────────────────────────────────────────
async function smsReq(acao, dados = {}) {
    const fd = new FormData();
    fd.append('acao', acao);
    fd.append('config_id', document.getElementById('sms-config-global')?.value || 0);
    for (const [k,v] of Object.entries(dados)) fd.append(k, v);
    const r = await fetch('sms_ajax.php', { method:'POST', body:fd });
    return r.json();
}

function smsEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ─── Painel ──────────────────────────────────────────────────────────────────
async function smsPainelCarregar() {
    document.getElementById('sms-painel-loading').style.display = '';
    const [r1, r2] = await Promise.all([smsReq('stats'), smsReq('listar_lotes')]);
    document.getElementById('sms-painel-loading').style.display = 'none';
    if (r1.success) {
        const d = r1.data;
        document.getElementById('st-hoje').textContent     = d.hoje || 0;
        document.getElementById('st-entregues').textContent = d.entregues || 0;
        document.getElementById('st-falhas').textContent   = d.falhas || 0;
        document.getElementById('st-chat').textContent     = d.nao_lidos || 0;
        const badge = document.getElementById('sms-badge-chat');
        if (d.nao_lidos > 0) { badge.textContent=d.nao_lidos; badge.classList.remove('d-none'); }
        else badge.classList.add('d-none');
    }
    if (r2.success && r2.data.length) {
        const wrap = document.getElementById('sms-painel-lotes-wrap');
        wrap.style.display = '';
        const ativos = r2.data.slice(0,5);
        let h = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;"><thead class="table-dark"><tr><th>Lote</th><th class="text-center">Status</th><th>Progresso</th><th class="text-center">Total</th><th class="text-center">Ações</th></tr></thead><tbody>';
        ativos.forEach(l => {
            const st = smsStatusBadge(l.STATUS);
            h += `<tr>
                <td><strong>${smsEsc(l.NOME_LOTE)}</strong><br><small class="text-muted">${l.DATA_BR} — ${smsEsc(l.NOME_USUARIO||'')}</small></td>
                <td class="text-center">${st}</td>
                <td style="min-width:120px;"><div class="progress" style="height:18px;"><div class="progress-bar bg-danger fw-bold" style="width:${l.PCT}%">${l.PCT}%</div></div></td>
                <td class="text-center"><span class="badge bg-secondary">${l.QTD_TOTAL}</span></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-dark py-0 px-2" onclick="smsVerLote(${l.ID},'${smsEsc(l.NOME_LOTE).replace(/'/g,"\\'")}')"><i class="fas fa-eye"></i></button>
                    ${l.STATUS==='PROCESSANDO'?`<button class="btn btn-sm btn-warning py-0 px-2 ms-1" onclick="smsPausarLote(${l.ID})"><i class="fas fa-pause"></i></button>`:''}
                    ${l.STATUS==='PAUSADO'?`<button class="btn btn-sm btn-success py-0 px-2 ms-1" onclick="smsRetomarLote(${l.ID})"><i class="fas fa-play"></i></button>`:''}
                </td>
            </tr>`;
        });
        h += '</tbody></table></div>';
        document.getElementById('sms-painel-lotes').innerHTML = h;
    }
}

// ─── Modo manual/busca ───────────────────────────────────────────────────────
function smsModo(modo) {
    document.getElementById('bloco-busca').style.display  = modo==='busca' ? '' : 'none';
    document.getElementById('bloco-manual').style.display = modo==='manual' ? '' : 'none';
    document.getElementById('btn-modo-busca').classList.toggle('active', modo==='busca');
    document.getElementById('btn-modo-busca').classList.toggle('btn-danger', modo==='busca');
    document.getElementById('btn-modo-busca').classList.toggle('btn-outline-secondary', modo!=='busca');
    document.getElementById('btn-modo-manual').classList.toggle('active', modo==='manual');
    document.getElementById('btn-modo-manual').classList.toggle('btn-danger', modo==='manual');
    document.getElementById('btn-modo-manual').classList.toggle('btn-outline-secondary', modo!=='manual');
}

let smsBuscaTimer;
function smsBuscarCliente(v) {
    clearTimeout(smsBuscaTimer);
    const res = document.getElementById('sms-busca-resultado');
    if (v.length < 2) { res.classList.add('d-none'); return; }
    smsBuscaTimer = setTimeout(async () => {
        const r = await smsReq('buscar_cliente', {termo:v});
        if (!r.success || !r.clientes.length) { res.innerHTML='<div class="p-3 text-muted small">Nenhum resultado.</div>'; res.classList.remove('d-none'); return; }
        res.classList.remove('d-none');
        res.innerHTML = r.clientes.map(c =>
            `<div class="cliente-item" onclick="smsSelecionarCliente('${smsEsc(c.NOME).replace(/'/g,"\\'")}','${smsEsc(c.TELEFONE||'').replace(/'/g,"\\'")}')">
                <strong>${smsEsc(c.NOME)}</strong> <span class="text-muted ms-2">${c.CPF_FMT}</span>
                <span class="float-end text-muted small">${c.TEL_FMT||'Sem tel'}</span>
            </div>`
        ).join('');
    }, 350);
}

function smsSelecionarCliente(nome, tel) {
    smsDestAtual = {nome, tel};
    document.getElementById('sms-dest-nome').textContent = nome;
    document.getElementById('sms-dest-tel').textContent  = tel;
    document.getElementById('sms-dest-selecionado').classList.remove('d-none');
    document.getElementById('sms-busca-resultado').classList.add('d-none');
    document.getElementById('sms-busca-input').value = '';
    smsAtualizarPreview();
}

function smsLimparDest() {
    smsDestAtual = {nome:'',tel:''};
    document.getElementById('sms-dest-selecionado').classList.add('d-none');
    document.getElementById('sms-preview-box').style.display = 'none';
}

// ─── Preview ─────────────────────────────────────────────────────────────────
function smsAtualizarPreview() {
    const box = document.getElementById('sms-preview-box');
    const msg = document.getElementById('sms-msg-manual').value;
    const nome = smsDestAtual.nome || document.getElementById('sms-nome-manual')?.value || '';
    if (!msg) { box.style.display='none'; return; }
    const p = nome.split(' ');
    const prim = p[0]||nome, disc = p.length>=2?prim+'***'+p[p.length-1]:prim;
    const final = msg.replace(/{PRIMEIRO_NOME}/g,prim).replace(/{NOME_COMPLETO}/g,nome).replace(/{NOME_DISCRETO}/g,disc);
    box.textContent = final;
    box.style.display = '';
}

function smsInserirTag(tag) {
    const el = document.getElementById('sms-msg-manual');
    const s = el.selectionStart, e = el.selectionEnd;
    el.value = el.value.slice(0,s)+tag+el.value.slice(e);
    el.selectionStart = el.selectionEnd = s+tag.length;
    el.focus();
    smsAtualizarPreview();
    smsContarChars(el, 'sms-chars-manual');
}

function smsInserirTagLote(tag) {
    const el = document.getElementById('sms-msg-lote');
    const s = el.selectionStart, e = el.selectionEnd;
    el.value = el.value.slice(0,s)+tag+el.value.slice(e);
    el.selectionStart = el.selectionEnd = s+tag.length;
    el.focus();
    smsContarChars(el,'sms-chars-lote');
}

function smsContarChars(el, id) {
    const c = el.value.length;
    const limit = document.querySelector(`[name="${el.id.includes('manual')?'sms-cod-manual':'sms-cod-lote'"]:checked`)?.value === '8' ? 70 : 160;
    const sms_count = Math.ceil(c / limit) || 1;
    document.getElementById(id).textContent = `${c} / ${limit} chars (${sms_count} SMS)`;
}

// ─── Enviar manual ───────────────────────────────────────────────────────────
async function smsEnviarManual(agendado = false) {
    const modo_busca = document.getElementById('bloco-busca').style.display !== 'none';
    const nome = modo_busca ? smsDestAtual.nome : document.getElementById('sms-nome-manual').value.trim();
    const tel  = modo_busca ? smsDestAtual.tel  : document.getElementById('sms-tel-manual').value.trim();
    const msg  = document.getElementById('sms-msg-manual').value.trim();
    const cod  = document.querySelector('[name="sms-cod-manual"]:checked').value;
    const agenda = agendado ? document.getElementById('sms-agenda-manual').value : '';

    if (!tel) return crmToast('Informe o telefone do destinatário.','warning');
    if (!msg) return crmToast('Digite a mensagem.','warning');
    if (agendado && !agenda) return crmToast('Selecione a data/hora do agendamento.','warning');

    const r = await smsReq('enviar_manual',{nome_dest:nome, telefone:tel, mensagem:msg, codificacao:cod, agendamento:agenda});
    crmToast(r.msg, r.success ? 'success' : 'error');
    if (r.success && !agendado) {
        document.getElementById('sms-msg-manual').value = '';
        document.getElementById('sms-preview-box').style.display = 'none';
    }
}

function smsAgendarManual() { smsEnviarManual(true); }

// ─── Lote — CSV ──────────────────────────────────────────────────────────────
function smsLoteDrop(e) {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (file) { const inp = document.getElementById('sms-lote-file'); const dt = new DataTransfer(); dt.items.add(file); inp.files = dt.files; smsLerCsv(inp); }
}

function smsLerCsv(inp) {
    const file = inp.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const text = e.target.result;
        const lines = text.split(/\r?\n/).filter(l => l.trim());
        smsLoteItens = [];
        for (let i = 1; i < lines.length; i++) { // skip header
            const cols = lines[i].split(/[;,]/);
            if (cols.length < 2) continue;
            const tel = cols[1]?.trim().replace(/\D/g,'');
            if (!tel || tel.length < 8) continue;
            smsLoteItens.push({
                nome: cols[0]?.trim() || '',
                telefone: tel,
                tag1: cols[2]?.trim() || '',
                tag2: cols[3]?.trim() || '',
                tag3: cols[4]?.trim() || '',
            });
        }
        smsRenderizarPreviewLote();
    };
    reader.readAsText(file, 'UTF-8');
}

function smsRenderizarPreviewLote() {
    const wrap = document.getElementById('sms-lote-preview-wrap');
    if (!smsLoteItens.length) { wrap.style.display='none'; return; }
    wrap.style.display = '';
    document.getElementById('sms-lote-qtd').textContent = `${smsLoteItens.length} destinatário(s) carregado(s)`;
    const tbody = document.getElementById('sms-lote-tbody');
    tbody.innerHTML = smsLoteItens.slice(0,20).map((it,i) =>
        `<tr><td>${i+1}</td><td>${smsEsc(it.nome)}</td><td>${it.telefone}</td><td>${smsEsc(it.tag1)}</td><td>${smsEsc(it.tag2)}</td><td>${smsEsc(it.tag3)}</td></tr>`
    ).join('') + (smsLoteItens.length>20 ? `<tr><td colspan="6" class="text-center text-muted small">... e mais ${smsLoteItens.length-20} registros</td></tr>` : '');
    // Tags detectadas
    const tags = new Set();
    smsLoteItens.forEach(it => { if(it.tag1) tags.add('{TAG1}'); if(it.tag2) tags.add('{TAG2}'); if(it.tag3) tags.add('{TAG3}'); });
    document.getElementById('sms-tags-detectadas').innerHTML = [...tags].map(t=>
        `<span class="badge bg-secondary me-1">${t} detectada</span>`).join('');
}

function smsLoteLimpar() {
    smsLoteItens = [];
    document.getElementById('sms-lote-file').value = '';
    document.getElementById('sms-lote-preview-wrap').style.display='none';
}

async function smsCriarLote() {
    const nome = document.getElementById('sms-nome-lote').value.trim();
    const msg  = document.getElementById('sms-msg-lote').value.trim();
    const cod  = document.querySelector('[name="sms-cod-lote"]:checked').value;
    const ag   = document.getElementById('sms-agenda-lote').value;
    if (!nome) return crmToast('Informe o nome do lote.','warning');
    if (!msg)  return crmToast('Escreva a mensagem.','warning');
    if (!smsLoteItens.length) return crmToast('Nenhum destinatário carregado.','warning');
    const r = await smsReq('criar_lote',{nome_lote:nome, mensagem:msg, codificacao:cod, agendamento:ag, itens:JSON.stringify(smsLoteItens)});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) { smsLoteLimpar(); document.getElementById('sms-nome-lote').value=''; document.getElementById('sms-msg-lote').value=''; }
}

// ─── Relatório ───────────────────────────────────────────────────────────────
async function smsCarregarRelatorio() {
    const wrap = document.getElementById('sms-rel-wrap');
    wrap.innerHTML = '<div class="text-center py-5 text-muted"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    const r = await smsReq('listar_disparos',{
        status:   document.getElementById('rel-status').value,
        data_ini: document.getElementById('rel-data-ini').value,
        data_fim: document.getElementById('rel-data-fim').value,
    });
    if (!r.success) { wrap.innerHTML=`<div class="alert alert-danger">${r.msg}</div>`; return; }
    document.getElementById('rel-contador').textContent = `${r.data.length} registro(s)`;
    if (!r.data.length) { wrap.innerHTML='<div class="text-center py-5 text-muted">Nenhum disparo encontrado.</div>'; return; }
    let h = `<div class="table-responsive"><table class="table table-hover table-sm align-middle mb-0" style="font-size:12.5px;">
        <thead class="table-dark"><tr><th>#</th><th>Destinatário</th><th>Telefone</th><th>Mensagem</th><th class="text-center">Status</th><th class="text-center">Data</th><th>Operador</th></tr></thead><tbody>`;
    r.data.forEach(d => {
        const msg = d.MENSAGEM||'';
        h += `<tr>
            <td><small class="text-muted">${d.ID}</small></td>
            <td>${smsEsc(d.DESTINATARIO_NOME||'—')}</td>
            <td style="white-space:nowrap;">${smsEsc(d.TEL_FMT||d.DESTINATARIO_TEL)}</td>
            <td><small>${smsEsc(msg.length>60?msg.substring(0,60)+'…':msg)}</small></td>
            <td class="text-center"><span class="badge badge-status-${d.STATUS}" style="font-size:10px;">${d.STATUS}</span></td>
            <td class="text-center" style="white-space:nowrap;"><small>${d.DATA_BR}</small></td>
            <td><small class="text-muted">${smsEsc(d.NOME_USUARIO||'—')}</small></td>
        </tr>`;
    });
    h += '</tbody></table></div>';
    wrap.innerHTML = h;
}

function smsStatusBadge(st) {
    const cores = {CONCLUIDO:'success',PROCESSANDO:'warning text-dark',PENDENTE:'secondary',PAUSADO:'info text-dark',ERRO:'danger'};
    return `<span class="badge bg-${cores[st]||'secondary'}">${st}</span>`;
}

// ─── Chat ────────────────────────────────────────────────────────────────────
async function smsChatCarregar() {
    document.getElementById('chat-lista').innerHTML = '<div class="text-center py-4 text-muted small"><i class="fas fa-spinner fa-spin"></i></div>';
    const r = await smsReq('listar_conversas');
    if (!r.success) { document.getElementById('chat-lista').innerHTML=`<div class="p-3 text-danger small">${r.msg}</div>`; return; }
    chatConversas = r.data || [];
    smsFiltrarConversas('');
}

function smsFiltrarConversas(q) {
    const lista = chatConversas.filter(c => !q || (c.NOME_CONTATO||'').toLowerCase().includes(q.toLowerCase()) || c.TEL_FMT.includes(q));
    const el = document.getElementById('chat-lista');
    if (!lista.length) { el.innerHTML='<div class="text-center py-5 text-muted small">Nenhuma conversa</div>'; return; }
    el.innerHTML = lista.map(c =>
        `<div class="chat-conv-item ${c.TELEFONE===chatTelAtual?'ativo':''}" onclick="smsAbrirConversa('${c.TELEFONE.replace(/'/g,"\\'")}','${smsEsc(c.NOME_CONTATO||'').replace(/'/g,"\\'")}')">
            <div class="d-flex justify-content-between align-items-center">
                <div class="chat-conv-nome">${smsEsc(c.NOME_CONTATO||c.TEL_FMT)}</div>
                <div class="d-flex gap-1 align-items-center">
                    ${c.NAO_LIDOS>0?`<span class="badge-nao-lido">${c.NAO_LIDOS}</span>`:''}
                    <span class="chat-conv-hora">${c.DATA_BR}</span>
                </div>
            </div>
            <div class="chat-conv-msg">${smsEsc(c.ULTIMA_MENSAGEM||'')}</div>
        </div>`
    ).join('');
}

async function smsAbrirConversa(tel, nome) {
    chatTelAtual  = tel;
    chatNomeAtual = nome;
    smsFiltrarConversas(document.getElementById('chat-busca').value);
    document.getElementById('chat-header-vazio').style.display = 'none';
    const wrap = document.getElementById('chat-conv-wrap');
    wrap.style.display = 'flex';
    document.getElementById('chat-header-info').innerHTML =
        `<i class="fas fa-user-circle fa-2x text-muted me-2"></i>
        <div><div class="fw-bold">${smsEsc(nome||tel)}</div><small class="text-muted">${smsEsc(tel)}</small></div>`;
    document.getElementById('chat-msgs').innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i></div>';
    const r = await smsReq('listar_mensagens', {telefone: tel.replace(/\D/g,'')});
    if (!r.success) { document.getElementById('chat-msgs').innerHTML=`<div class="alert alert-danger">${r.msg}</div>`; return; }
    const msgs = document.getElementById('chat-msgs');
    msgs.innerHTML = r.data.map(m =>
        `<div style="display:flex; flex-direction:column; align-items:${m.TIPO==='ENVIADO'?'flex-end':'flex-start'};">
            <div class="chat-bubble ${m.TIPO==='ENVIADO'?'env':'rec'}">
                ${smsEsc(m.MENSAGEM)}
                <span class="hora">${m.DATA_BR}</span>
            </div>
        </div>`
    ).join('');
    msgs.scrollTop = msgs.scrollHeight;
}

async function smsResponderChat() {
    const msg = document.getElementById('chat-reply-msg').value.trim();
    if (!msg || !chatTelAtual) return;
    document.getElementById('chat-reply-msg').value = '';
    const r = await smsReq('responder_chat', {telefone: chatTelAtual.replace(/\D/g,''), mensagem:msg, nome_contato:chatNomeAtual});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) smsAbrirConversa(chatTelAtual, chatNomeAtual);
}

async function smsSincronizarRespostas() {
    const r = await smsReq('sincronizar_respostas');
    crmToast(r.msg || 'Sincronizado.', r.success?'info':'error');
    if (r.success) smsChatCarregar();
}

// ─── Lotes (pausar/retomar/ver) ───────────────────────────────────────────────
async function smsPausarLote(id) {
    const r = await smsReq('pausar_lote',{lote_id:id});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) smsPainelCarregar();
}

async function smsRetomarLote(id) {
    const r = await smsReq('retomar_lote',{lote_id:id});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) smsPainelCarregar();
}

async function smsVerLote(id, nome) {
    document.getElementById('smsModalLoteTitulo').innerHTML = `<i class="fas fa-layer-group me-2"></i>${smsEsc(nome)}`;
    document.getElementById('smsModalLoteBody').innerHTML   = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    smsModalLoteObj.show();
    const r = await smsReq('listar_itens_lote',{lote_id:id});
    if (!r.success) { document.getElementById('smsModalLoteBody').innerHTML=`<div class="alert alert-danger m-3">${r.msg}</div>`; return; }
    let h = `<table class="table table-sm table-hover mb-0" style="font-size:12px;">
        <thead class="table-dark"><tr><th>#</th><th>Nome</th><th>Telefone</th><th>Mensagem</th><th class="text-center">Status</th><th class="text-center">Envio</th></tr></thead><tbody>`;
    r.data.forEach((it,i) => {
        const cor = {ENVIADO:'success',FALHA:'danger',NA_FILA:'secondary'}[it.STATUS]||'secondary';
        h += `<tr><td>${i+1}</td><td>${smsEsc(it.DESTINATARIO_NOME||'—')}</td><td style="white-space:nowrap;">${it.TEL_FMT}</td>
            <td><small>${smsEsc((it.MENSAGEM_FINAL||'').substring(0,60)+(it.MENSAGEM_FINAL?.length>60?'…':''))}</small></td>
            <td class="text-center"><span class="badge bg-${cor} text-white" style="font-size:10px;">${it.STATUS}</span></td>
            <td class="text-center"><small>${it.DATA_BR}</small></td></tr>`;
    });
    h += '</tbody></table>';
    document.getElementById('smsModalLoteBody').innerHTML = h;
}

// ─── Carteira & Config ────────────────────────────────────────────────────────
async function smsConsultarSaldo(atualizar_tela = false) {
    const cfg_id = document.getElementById('sms-config-global')?.value;
    if (!cfg_id) return crmToast('Selecione uma configuração primeiro.','warning');
    const r = await smsReq('consultar_saldo');
    const saldo = r?.data?.detail?.saldo || r?.data?.saldo || '—';
    if (atualizar_tela && document.getElementById('cart-saldo-val')) {
        document.getElementById('cart-saldo-val').textContent = saldo !== '—' ? 'R$ '+saldo : '—';
    }
    crmToast(`Saldo: R$ ${saldo}`, 'info');
}

async function smsCarregarConfigs() {
    const r = await smsReq('listar_configs');
    const el = document.getElementById('sms-cfg-lista');
    if (!r.success || !r.data.length) { el.innerHTML='<div class="text-muted small">Nenhuma configuração cadastrada.</div>'; return; }
    let h = '<div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;"><thead class="table-dark"><tr><th>Nome</th><th>Token</th><th>Serviço</th><th class="text-center">Ações</th></tr></thead><tbody>';
    r.data.forEach(c => {
        h += `<tr>
            <td><strong>${smsEsc(c.NOME_CONFIG)}</strong></td>
            <td><code>${smsEsc(c.TOKEN_MASK)}</code></td>
            <td>${smsEsc(c.SERVICO)}</td>
            <td class="text-center">
                <button class="btn btn-sm btn-outline-secondary py-0 px-2 me-1" onclick="smsEditarConfig(${c.ID},'${smsEsc(c.NOME_CONFIG).replace(/'/g,"\\'")}','','${c.SERVICO}',${c.id_empresa||0})"><i class="fas fa-edit"></i></button>
                <button class="btn btn-sm btn-outline-danger py-0 px-2" onclick="smsExcluirConfig(${c.ID})"><i class="fas fa-trash"></i></button>
            </td>
        </tr>`;
    });
    h += '</tbody></table></div>';
    el.innerHTML = h;
}

async function smsSalvarConfig() {
    const id      = parseInt(document.getElementById('cfg-edit-id').value)||0;
    const nome    = document.getElementById('cfg-nome').value.trim();
    const token   = document.getElementById('cfg-token').value.trim();
    const servico = document.getElementById('cfg-servico').value;
    const emp     = document.getElementById('cfg-empresa')?.value || '';
    const r = await smsReq('salvar_config',{id, nome, token, servico, id_empresa:emp});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) { smsLimparFormConfig(); smsCarregarConfigs(); }
}

async function smsExcluirConfig(id) {
    if (!confirm('Remover esta configuração?')) return;
    const r = await smsReq('excluir_config',{id});
    crmToast(r.msg, r.success?'success':'error');
    if (r.success) smsCarregarConfigs();
}

function smsEditarConfig(id, nome, token, servico, empresa) {
    document.getElementById('cfg-edit-id').value  = id;
    document.getElementById('cfg-nome').value     = nome;
    document.getElementById('cfg-token').value    = token;
    document.getElementById('cfg-servico').value  = servico;
    if (document.getElementById('cfg-empresa')) document.getElementById('cfg-empresa').value = empresa||'';
    window.scrollTo({top:0,behavior:'smooth'});
}

function smsLimparFormConfig() {
    ['cfg-edit-id','cfg-nome','cfg-token'].forEach(id => { const el=document.getElementById(id); if(el) el.value = id==='cfg-edit-id'?'0':''; });
    document.getElementById('cfg-servico').value = 'short';
}
</script>

<?php
if (file_exists('../../../includes/footer.php')) include_once '../../../includes/footer.php';
?>
