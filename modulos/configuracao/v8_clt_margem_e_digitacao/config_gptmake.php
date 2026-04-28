<?php
session_start();
$caminho_conexao    = $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$caminho_header     = $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (!file_exists($caminho_conexao)) die("Erro: conexão não encontrada.");
include $caminho_conexao;
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;
if (!isset($_SESSION['usuario_cpf'])) { header("Location: /login.php"); exit; }
if (!isset($pdo) && isset($conn)) $pdo = $conn;
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// ── Identidade do usuário logado ───────────────────────────────────────────
$cpf_logado   = preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '');
$grupo_logado = strtoupper($_SESSION['usuario_grupo'] ?? '');
$is_master    = in_array($grupo_logado, ['MASTER','ADMIN','ADMINISTRADOR']);
$is_super     = in_array($grupo_logado, ['SUPERVISOR','SUPERVISORES']);
$is_consul    = in_array($grupo_logado, ['CONSULTOR','CONSULTORES']);

$stU = $pdo->prepare("SELECT id_empresa, NOME FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
$stU->execute([$cpf_logado]);
$rowU = $stU->fetch(PDO::FETCH_ASSOC) ?: [];
$id_empresa_logado = (int)($rowU['id_empresa'] ?? 0);

// Permissão: quando GPTMAKE_API_MEU_CADASTRO está bloqueado → força visão própria
$perm_meu_cadastro = verificaPermissao($pdo, 'GPTMAKE_API_MEU_CADASTRO', 'FUNCAO');
$forcar_proprio    = (!$is_master && !$perm_meu_cadastro); // true = ver só o próprio

// ── Cláusula WHERE de hierarquia ───────────────────────────────────────────
function hierarquiaWhere($is_master, $is_super, $forcar_proprio, $cpf_logado, $id_empresa_logado) {
    if ($is_master && !$forcar_proprio) return ['1=1', []];
    if ($is_super  && !$forcar_proprio) return ['id_empresa = ?', [$id_empresa_logado]];
    return ['CPF_USUARIO = ?', [$cpf_logado]];
}
[$where_hier, $params_hier] = hierarquiaWhere($is_master, $is_super, $forcar_proprio, $cpf_logado, $id_empresa_logado);

$msg = ''; $msg_tipo = '';

// ── AÇÕES POST ────────────────────────────────────────────────────────────
$acao_post = $_POST['acao'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // SALVAR / EDITAR
    if ($acao_post === 'salvar') {
        $id_edit   = (int)($_POST['id_edit'] ?? 0);
        $api_token = trim($_POST['api_token'] ?? '');
        $agent_id  = trim($_POST['agent_id']  ?? '');
        $cpf_alvo  = $is_master ? preg_replace('/\D/', '', $_POST['cpf_usuario'] ?? $cpf_logado) : $cpf_logado;

        if (!$api_token || !$agent_id) {
            $msg = 'Preencha o Token e o Agent ID.'; $msg_tipo = 'danger';
        } else {
            // Busca dados do usuário alvo
            $stAlvo = $pdo->prepare("SELECT id_empresa, NOME FROM CLIENTE_USUARIO WHERE CPF = ? LIMIT 1");
            $stAlvo->execute([$cpf_alvo]);
            $rowAlvo = $stAlvo->fetch(PDO::FETCH_ASSOC) ?: [];
            $emp_alvo  = (int)($rowAlvo['id_empresa'] ?? 0);
            $nome_alvo = $rowAlvo['NOME'] ?? '';

            if ($id_edit) {
                $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_CONFIG SET API_TOKEN=?, AGENT_ID=?, STATUS='ATIVO' WHERE ID=?")
                    ->execute([$api_token, $agent_id, $id_edit]);
                $msg = 'Configuração atualizada com sucesso!'; $msg_tipo = 'success';
            } else {
                $pdo->prepare("INSERT INTO INTEGRACAO_GPTMAKE_CONFIG (CPF_USUARIO, id_empresa, NOME_USUARIO, API_TOKEN, AGENT_ID) VALUES (?,?,?,?,?)")
                    ->execute([$cpf_alvo, $emp_alvo, $nome_alvo, $api_token, $agent_id]);
                $msg = 'Configuração adicionada com sucesso!'; $msg_tipo = 'success';
            }
        }
    }

    // ATIVAR / INATIVAR
    if ($acao_post === 'toggle') {
        $id_t  = (int)($_POST['id'] ?? 0);
        $novo  = ($_POST['status_atual'] ?? '') === 'ATIVO' ? 'INATIVO' : 'ATIVO';
        $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_CONFIG SET STATUS=? WHERE ID=?")->execute([$novo, $id_t]);
        $msg = "Configuração " . ($novo === 'ATIVO' ? 'ativada' : 'desativada') . "."; $msg_tipo = 'success';
    }

    // EXCLUIR
    if ($acao_post === 'excluir' && $is_master) {
        $pdo->prepare("DELETE FROM INTEGRACAO_GPTMAKE_CONFIG WHERE ID=?")->execute([(int)$_POST['id']]);
        $msg = 'Configuração removida.'; $msg_tipo = 'warning';
    }

    // TESTAR
    if ($acao_post === 'testar') {
        $api_token = trim($_POST['api_token'] ?? '');
        $agent_id  = trim($_POST['agent_id']  ?? '');
        $ch = curl_init("https://api.gptmaker.ai/v2/agent/{$agent_id}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>["Authorization: Bearer {$api_token}"]]);
        $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $json = json_decode($res, true);
        if ($http === 200 && isset($json['name'])) {
            $msg = "✅ Conexão OK! Agente: <strong>" . htmlspecialchars($json['name']) . "</strong>";
            $msg_tipo = 'success';
        } else {
            $msg = "❌ Falha HTTP {$http}. Verifique Token e Agent ID."; $msg_tipo = 'danger';
        }
    }
}

// ── Carrega configs conforme hierarquia ───────────────────────────────────
$stConf = $pdo->prepare("SELECT g.*, u.NOME as NOME_USUARIO_ATUAL
    FROM INTEGRACAO_GPTMAKE_CONFIG g
    LEFT JOIN CLIENTE_USUARIO u ON u.CPF COLLATE utf8mb4_unicode_ci = g.CPF_USUARIO
    WHERE {$where_hier} ORDER BY g.ID DESC");
$stConf->execute($params_hier);
$configs = $stConf->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editando = null;
if (!empty($_GET['editar'])) {
    $stE = $pdo->prepare("SELECT * FROM INTEGRACAO_GPTMAKE_CONFIG WHERE ID=? AND {$where_hier}");
    $stE->execute(array_merge([(int)$_GET['editar']], $params_hier));
    $editando = $stE->fetch(PDO::FETCH_ASSOC);
}

// Estatísticas followup
$stStats = $pdo->prepare("SELECT f.STATUS, COUNT(*) as total FROM INTEGRACAO_V8_IA_FOLLOWUP f
    JOIN INTEGRACAO_V8_IA_CREDENCIAIS c ON c.TOKEN_IA = f.TOKEN_IA
    JOIN INTEGRACAO_GPTMAKE_CONFIG g ON g.CPF_USUARIO = c.CPF_DONO
    WHERE g.{$where_hier} GROUP BY f.STATUS");
// Simplifica: stats globais para master
$stats = $pdo->query("SELECT STATUS, COUNT(*) as total FROM INTEGRACAO_V8_IA_FOLLOWUP GROUP BY STATUS")->fetchAll(PDO::FETCH_ASSOC);
$statsMap = array_column($stats, 'total', 'STATUS');

// ── Tabela de treinamentos ────────────────────────────────────────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS INTEGRACAO_GPTMAKE_TREINAMENTOS (
    ID INT AUTO_INCREMENT PRIMARY KEY,
    AGENT_ID VARCHAR(100) NOT NULL,
    CPF_USUARIO VARCHAR(11),
    id_empresa INT,
    TITULO VARCHAR(150) NOT NULL,
    CONTEUDO TEXT NOT NULL,
    TIPO ENUM('COMPORTAMENTO','TREINAMENTO') NOT NULL DEFAULT 'COMPORTAMENTO',
    GPTMAKE_TRAINING_ID VARCHAR(200),
    STATUS ENUM('LOCAL','SINCRONIZADO','ERRO') DEFAULT 'LOCAL',
    MSG_ERRO TEXT,
    DATA_CRIACAO DATETIME DEFAULT NOW(),
    DATA_SYNC DATETIME
)"); } catch(Exception $e){}
// Migração: adiciona coluna TIPO se a tabela já existia (registros antigos eram todos comportamento)
try { $pdo->exec("ALTER TABLE INTEGRACAO_GPTMAKE_TREINAMENTOS ADD COLUMN TIPO ENUM('COMPORTAMENTO','TREINAMENTO') NOT NULL DEFAULT 'COMPORTAMENTO' AFTER CONTEUDO"); } catch(Exception $e){}

// Ação: salvar bloco de treinamento/comportamento
if ($acao_post === 'salvar_treino') {
    header('Content-Type: application/json');
    $id_treino  = (int)($_POST['id_treino'] ?? 0);
    $titulo     = trim($_POST['titulo'] ?? '');
    $conteudo   = trim($_POST['conteudo'] ?? '');
    $agent_id_t = trim($_POST['agent_id_treino'] ?? '');
    $tipo       = in_array($_POST['tipo'] ?? '', ['COMPORTAMENTO','TREINAMENTO']) ? $_POST['tipo'] : 'COMPORTAMENTO';
    if (!$titulo || !$conteudo || !$agent_id_t) { echo json_encode(['ok'=>false,'msg'=>'Campos obrigatórios.']); exit; }
    if ($id_treino) {
        $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_TREINAMENTOS SET TITULO=?,CONTEUDO=?,TIPO=?,STATUS='LOCAL',GPTMAKE_TRAINING_ID=NULL,DATA_SYNC=NULL WHERE ID=?")->execute([$titulo,$conteudo,$tipo,$id_treino]);
    } else {
        $pdo->prepare("INSERT INTO INTEGRACAO_GPTMAKE_TREINAMENTOS (AGENT_ID,CPF_USUARIO,id_empresa,TITULO,CONTEUDO,TIPO) VALUES (?,?,?,?,?,?)")->execute([$agent_id_t,$cpf_logado,$id_empresa_logado,$titulo,$conteudo,$tipo]);
    }
    echo json_encode(['ok'=>true]); exit;
}

// Ação: excluir bloco de treinamento
if ($acao_post === 'excluir_treino') {
    header('Content-Type: application/json');
    $id_treino = (int)($_POST['id_treino'] ?? 0);
    $pdo->prepare("DELETE FROM INTEGRACAO_GPTMAKE_TREINAMENTOS WHERE ID=?")->execute([$id_treino]);
    echo json_encode(['ok'=>true]); exit;
}

// Ação: sincronizar com GPTMaker
if ($acao_post === 'sincronizar_treino') {
    ignore_user_abort(true);
    set_time_limit(120);
    session_write_close(); // libera o lock de sessão antes do curl
    header('Content-Type: application/json');
    $id_treino = (int)($_POST['id_treino'] ?? 0);
    try {
        $stT = $pdo->prepare("SELECT t.*, c.API_TOKEN FROM INTEGRACAO_GPTMAKE_TREINAMENTOS t JOIN INTEGRACAO_GPTMAKE_CONFIG c ON c.AGENT_ID=t.AGENT_ID WHERE t.ID=? LIMIT 1");
        $stT->execute([$id_treino]);
        $treino = $stT->fetch(PDO::FETCH_ASSOC);
        if (!$treino) { echo json_encode(['ok'=>false,'msg'=>'Bloco não encontrado.']); exit; }
        if (empty($treino['API_TOKEN'])) { echo json_encode(['ok'=>false,'msg'=>'Token não configurado para este agente.']); exit; }

        $tipo_bloco = $treino['TIPO'] ?? 'TREINAMENTO';

        if ($tipo_bloco === 'COMPORTAMENTO') {
            // ── COMPORTAMENTO: atualiza o campo behavior do agente via PATCH ──────
            $body = json_encode(['behavior' => $treino['CONTEUDO']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ch = curl_init("https://api.gptmaker.ai/v2/agent/{$treino['AGENT_ID']}");
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PATCH',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$treino['API_TOKEN']}",
                    "Content-Type: application/json; charset=utf-8",
                    "Content-Length: " . strlen($body)
                ],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $res  = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            if ($curl_err) { echo json_encode(['ok'=>false,'msg'=>"Erro cURL: {$curl_err}"]); exit; }

            if ($http >= 200 && $http < 300) {
                $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_TREINAMENTOS SET STATUS='SINCRONIZADO',GPTMAKE_TRAINING_ID='comportamento',DATA_SYNC=NOW(),MSG_ERRO=NULL WHERE ID=?")->execute([$id_treino]);
                echo json_encode(['ok'=>true, 'msg'=>'Comportamento sincronizado com sucesso!']); exit;
            } else {
                $json = json_decode($res, true);
                $erro = $json['message'] ?? $json['error'] ?? "HTTP {$http} — " . substr($res, 0, 200);
                $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_TREINAMENTOS SET STATUS='ERRO',MSG_ERRO=? WHERE ID=?")->execute([$erro, $id_treino]);
                echo json_encode(['ok'=>false, 'msg'=>"Erro ao sincronizar comportamento: {$erro}"]); exit;
            }
        } else {
            // ── TREINAMENTO: envia conteúdo à base de conhecimento via POST ───────
            $body = json_encode(['type'=>'TEXT','text'=>$treino['CONTEUDO']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ch = curl_init("https://api.gptmaker.ai/v2/agent/{$treino['AGENT_ID']}/trainings");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$treino['API_TOKEN']}",
                    "Content-Type: application/json; charset=utf-8",
                    "Content-Length: " . strlen($body)
                ],
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            ]);
            $res  = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err = curl_error($ch);
            curl_close($ch);

            if ($curl_err) { echo json_encode(['ok'=>false,'msg'=>"Erro cURL: {$curl_err}"]); exit; }

            $json = json_decode($res, true);
            // GPTMaker retorna {"id":"...","tenant":"..."} em sucesso para trainings
            $training_id = $json['id'] ?? $json['trainingId'] ?? null;
            if ($http === 200 && !empty($training_id)) {
                $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_TREINAMENTOS SET STATUS='SINCRONIZADO',GPTMAKE_TRAINING_ID=?,DATA_SYNC=NOW(),MSG_ERRO=NULL WHERE ID=?")->execute([$training_id, $id_treino]);
                echo json_encode(['ok'=>true, 'msg'=>'Treinamento sincronizado com sucesso!']); exit;
            } else {
                $erro = $json['message'] ?? $json['error'] ?? "HTTP {$http} — " . substr($res, 0, 200);
                $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_TREINAMENTOS SET STATUS='ERRO',MSG_ERRO=? WHERE ID=?")->execute([$erro, $id_treino]);
                echo json_encode(['ok'=>false, 'msg'=>"Erro ao sincronizar treinamento: {$erro}"]); exit;
            }
        }
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'msg'=>'Exceção: ' . $e->getMessage()]); exit;
    }
}

// Carrega treinamentos
$stTreinos = $pdo->prepare("SELECT * FROM INTEGRACAO_GPTMAKE_TREINAMENTOS WHERE {$where_hier} ORDER BY TIPO ASC, DATA_CRIACAO ASC");
try { $stTreinos->execute($params_hier); $lista_treinos = $stTreinos->fetchAll(PDO::FETCH_ASSOC); }
catch(Exception $e) { $lista_treinos = []; }

// Filtros sessões
$filtro_data_ini = $_GET['data_ini'] ?? '';
$filtro_data_fim = $_GET['data_fim'] ?? '';
$filtro_usuario  = trim($_GET['usuario'] ?? '');
$filtro_cpf      = preg_replace('/\D/', '', $_GET['cpf'] ?? '');
$filtro_telefone = preg_replace('/\D/', '', $_GET['telefone'] ?? '');

$where_sess  = ['1=1'];
$params_sess = [];
if ($filtro_data_ini) { $where_sess[] = 'DATE(f.DATA_CRIACAO) >= ?'; $params_sess[] = $filtro_data_ini; }
if ($filtro_data_fim) { $where_sess[] = 'DATE(f.DATA_CRIACAO) <= ?'; $params_sess[] = $filtro_data_fim; }
if ($filtro_usuario)  { $where_sess[] = '(COALESCE(u.NOME, g.NOME_USUARIO) LIKE ?)'; $params_sess[] = "%{$filtro_usuario}%"; }
if ($filtro_cpf)      { $where_sess[] = 'f.CPF_CLIENTE LIKE ?'; $params_sess[] = "%{$filtro_cpf}%"; }
if ($filtro_telefone) { $where_sess[] = '(f.TELEFONE LIKE ? OR COALESCE(f.TELEFONE_WHATSAPP,\'\') LIKE ?)'; $params_sess[] = "%{$filtro_telefone}%"; $params_sess[] = "%{$filtro_telefone}%"; }

$where_sess_str = implode(' AND ', $where_sess);

// Últimas sessões
$stSess = $pdo->prepare("
    SELECT f.*,
           g.ID             AS CONFIG_ID,
           g.AGENT_ID       AS GPTMAKE_AGENT_ID,
           COALESCE(u.NOME, g.NOME_USUARIO) AS DONO_NOME
    FROM INTEGRACAO_V8_IA_FOLLOWUP f
    LEFT JOIN INTEGRACAO_GPTMAKE_CONFIG g ON g.AGENT_ID COLLATE utf8mb4_unicode_ci = f.AGENT_ID
    LEFT JOIN CLIENTE_USUARIO u ON u.CPF COLLATE utf8mb4_unicode_ci = g.CPF_USUARIO
    WHERE {$where_sess_str}
    ORDER BY f.DATA_CRIACAO DESC
    LIMIT 100
");
$stSess->execute($params_sess);
$ultimasSessoes = $stSess->fetchAll(PDO::FETCH_ASSOC);

// Lista usuários para select (MASTER)
$usuarios = [];
if ($is_master) {
    $usuarios = $pdo->query("SELECT CPF, NOME FROM CLIENTE_USUARIO ORDER BY NOME ASC")->fetchAll(PDO::FETCH_ASSOC);
}

include $caminho_header;
?>
<style>
.gpt-card  { border-radius:8px; }
.token-blur { filter:blur(4px); transition:.2s; cursor:pointer; font-family:monospace; }
.token-blur:hover { filter:none; }
.badge-ativo   { background:#198754; }
.badge-inativo { background:#6c757d; }
</style>
<script>
async function testarEnvioGPT() {
    const tel  = document.getElementById('test_telefone').value.trim();
    const msg  = document.getElementById('test_mensagem').value.trim();
    const cfgId = document.getElementById('test_config_id').value;
    if (!tel) { alert('Informe o telefone.'); return; }

    const log = document.getElementById('test_log');
    const box = document.getElementById('test_resultado');
    log.textContent = 'Executando...';
    box.style.display = 'block';

    const fd = new FormData();
    fd.append('acao', 'testar_envio_gpt');
    fd.append('telefone', tel);
    fd.append('mensagem', msg);
    fd.append('config_id', cfgId);

    const r = await fetch('ajax_testar_gptmaker.php', { method: 'POST', body: fd });
    const j = await r.json();
    log.textContent = j.log;
    log.style.color  = j.ok ? '#4ec94e' : '#ff6b6b';
}
</script>

<div class="container-fluid py-3">
<?php if ($msg): ?>
  <div class="alert alert-<?= $msg_tipo ?> py-2 mb-3"><?= $msg ?></div>
<?php endif; ?>
<?php if (isset($_GET['executado'])): ?>
  <div class="alert alert-success py-2 mb-3">Worker executado manualmente.</div>
<?php endif; ?>

<!-- ══ MENU TABS ══════════════════════════════════════════════════════════ -->
<ul class="nav nav-tabs border-dark fw-bold mb-3" id="gptTabs" role="tablist">
  <li class="nav-item">
    <button class="nav-link active text-dark" data-bs-toggle="tab" data-bs-target="#tabConfig">
      <i class="fas fa-cog me-1 text-success"></i> Configuração
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link text-dark" data-bs-toggle="tab" data-bs-target="#tabTreinamentos">
      <i class="fas fa-brain me-1 text-primary"></i> Treinamentos
      <span class="badge bg-primary ms-1"><?= count($lista_treinos) ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link text-dark" data-bs-toggle="tab" data-bs-target="#tabFollowup">
      <i class="fas fa-history me-1 text-warning"></i> Follow-up
      <span class="badge bg-secondary ms-1"><?= count($ultimasSessoes) ?></span>
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link text-dark" data-bs-toggle="tab" data-bs-target="#tabWorker">
      <i class="fas fa-terminal me-1 text-secondary"></i> Worker / Log
    </button>
  </li>
</ul>

<div class="tab-content">

<!-- ══ TAB 1: CONFIGURAÇÃO ═══════════════════════════════════════════════ -->
<div class="tab-pane fade show active" id="tabConfig">
<div class="row g-3">
  <div class="col-md-4">
    <div class="card border-dark shadow-sm gpt-card">
      <div class="card-header bg-dark text-white fw-bold">
        <i class="fas fa-brain me-2 text-success"></i>
        <?= $editando ? 'Editar Configuração' : 'Nova Configuração GPTMaker' ?>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="acao"    value="salvar">
          <input type="hidden" name="id_edit" value="<?= $editando['ID'] ?? 0 ?>">

          <?php if ($is_master): ?>
          <div class="mb-3">
            <label class="form-label fw-bold small">Usuário (Dono da Config)</label>
            <select name="cpf_usuario" class="form-select border-dark" <?= $editando ? 'disabled' : '' ?>>
              <?php foreach ($usuarios as $u): ?>
                <option value="<?= $u['CPF'] ?>" <?= ($editando['CPF_USUARIO'] ?? $cpf_logado) === $u['CPF'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($u['NOME']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php else: ?>
          <div class="mb-2 p-2 bg-light rounded border small text-muted">
            <i class="fas fa-user me-1"></i> Configuração vinculada ao seu usuário
          </div>
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label fw-bold small">API Token (Bearer)</label>
            <input type="text" name="api_token" class="form-control border-dark font-monospace"
              value="<?= htmlspecialchars($editando['API_TOKEN'] ?? '') ?>"
              placeholder="eyJ..." required style="font-size:11px;">
            <div class="form-text">app.gptmaker.ai → Desenvolvedores → Gerar Token</div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-bold small">Agent ID</label>
            <input type="text" name="agent_id" class="form-control border-dark font-monospace"
              value="<?= htmlspecialchars($editando['AGENT_ID'] ?? '') ?>"
              placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required style="font-size:11px;">
            <div class="form-text">Dados do Agente → Configurações → ID do Agente</div>
          </div>

          <div class="d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-success fw-bold border-dark">
              <i class="fas fa-save me-1"></i><?= $editando ? 'Atualizar' : 'Salvar' ?>
            </button>
            <button type="submit" name="acao" value="testar" class="btn btn-outline-primary border-dark">
              <i class="fas fa-plug me-1"></i>Testar
            </button>
            <?php if ($editando): ?>
            <a href="config_gptmake.php" class="btn btn-outline-secondary border-dark">Cancelar</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Testar Envio GPTMaker -->
    <div class="card border-dark shadow-sm mt-3 gpt-card">
      <div class="card-header bg-dark text-white fw-bold small">
        <i class="fas fa-paper-plane me-2 text-warning"></i>Testar Envio de Mensagem
      </div>
      <div class="card-body py-2">
        <div class="mb-2">
          <label class="form-label fw-bold small mb-1">Telefone (como está no banco)</label>
          <input type="text" id="test_telefone" class="form-control form-control-sm border-dark font-monospace"
            placeholder="Ex: 8299025155 ou 558299025155" style="font-size:11px;">
          <div class="form-text">O código mostrará como o número é processado antes de enviar.</div>
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold small mb-1">Mensagem de Teste</label>
          <input type="text" id="test_mensagem" class="form-control form-control-sm border-dark"
            value="[TESTE] Mensagem de diagnóstico do sistema." style="font-size:11px;">
        </div>
        <div class="mb-2">
          <label class="form-label fw-bold small mb-1">Configuração GPTMaker</label>
          <select id="test_config_id" class="form-select form-select-sm border-dark" style="font-size:11px;">
            <?php foreach ($configs as $cfg): ?>
            <option value="<?= $cfg['ID'] ?>"><?= htmlspecialchars($cfg['NOME_USUARIO_ATUAL'] ?? $cfg['NOME_USUARIO']) ?> — <?= substr($cfg['AGENT_ID'],0,18) ?>...</option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" class="btn btn-sm btn-warning border-dark fw-bold" onclick="testarEnvioGPT()">
          <i class="fas fa-vial me-1"></i>Executar Teste
        </button>
        <div id="test_resultado" class="mt-2" style="display:none;">
          <pre id="test_log" style="background:#1e1e1e;color:#d4d4d4;font-size:11px;border-radius:4px;padding:10px;max-height:200px;overflow-y:auto;margin:0;"></pre>
        </div>
      </div>
    </div>

  </div>

  <!-- ── Tabela de configs + sessões ───────────────────── -->
  <div class="col-md-8">

    <!-- Configs cadastradas -->
    <div class="card border-dark shadow-sm gpt-card mb-3">
      <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>Configurações Cadastradas</span>
        <span class="badge bg-secondary"><?= count($configs) ?> registro(s)</span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:12px;">
          <thead class="table-dark">
            <tr>
              <th>Usuário</th>
              <th>Agent ID</th>
              <th>Token</th>
              <th>Status</th>
              <th class="text-center">Ações</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($configs)): ?>
            <tr><td colspan="5" class="text-center text-muted py-3 fst-italic">Nenhuma configuração cadastrada.</td></tr>
          <?php else: ?>
            <?php foreach ($configs as $cfg): ?>
            <tr>
              <td>
                <div class="fw-bold"><?= htmlspecialchars($cfg['NOME_USUARIO_ATUAL'] ?? $cfg['NOME_USUARIO'] ?? '—') ?></div>
                <div class="text-muted" style="font-size:10px;"><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.***.***-$4', str_pad($cfg['CPF_USUARIO']??'',11,'0',STR_PAD_LEFT)) ?></div>
              </td>
              <td class="font-monospace" style="font-size:10px;"><?= substr(htmlspecialchars($cfg['AGENT_ID']),0,18) ?>...</td>
              <td>
                <span class="token-blur" title="Clique para ver">
                  <?= substr(htmlspecialchars($cfg['API_TOKEN']),0,20) ?>...
                </span>
              </td>
              <td>
                <span class="badge <?= $cfg['STATUS']==='ATIVO' ? 'badge-ativo' : 'badge-inativo' ?>">
                  <?= $cfg['STATUS'] ?>
                </span>
              </td>
              <td class="text-center">
                <div class="d-flex gap-1 justify-content-center">
                  <a href="?editar=<?= $cfg['ID'] ?>" class="btn btn-xs btn-outline-primary py-0 px-2" title="Editar">
                    <i class="fas fa-edit"></i>
                  </a>
                  <form method="POST" class="d-inline">
                    <input type="hidden" name="acao"         value="toggle">
                    <input type="hidden" name="id"           value="<?= $cfg['ID'] ?>">
                    <input type="hidden" name="status_atual" value="<?= $cfg['STATUS'] ?>">
                    <button class="btn btn-xs <?= $cfg['STATUS']==='ATIVO' ? 'btn-outline-warning' : 'btn-outline-success' ?> py-0 px-2"
                      title="<?= $cfg['STATUS']==='ATIVO' ? 'Desativar' : 'Ativar' ?>">
                      <i class="fas <?= $cfg['STATUS']==='ATIVO' ? 'fa-pause' : 'fa-play' ?>"></i>
                    </button>
                  </form>
                  <?php if ($is_master): ?>
                  <form method="POST" class="d-inline" onsubmit="return confirm('Remover esta configuração?')">
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="id"   value="<?= $cfg['ID'] ?>">
                    <button class="btn btn-xs btn-outline-danger py-0 px-2" title="Excluir">
                      <i class="fas fa-trash"></i>
                    </button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /col-md-8 -->
</div><!-- /row -->
</div><!-- /tabConfig -->

<!-- ══ TAB 2: TREINAMENTOS ════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabTreinamentos">

  <!-- Legenda de tipos -->
  <div class="d-flex gap-3 mb-3 align-items-center">
    <span class="badge bg-purple text-white border border-dark px-3 py-2" style="background:#6f42c1!important;font-size:.8rem;">
      🎭 COMPORTAMENTO — tom de voz, regras, fluxo da conversa (limite: 3.000 chars)
    </span>
    <span class="badge bg-success border border-dark px-3 py-2" style="font-size:.8rem;">
      📚 TREINAMENTO — dados e informações do produto
    </span>
    <button class="btn btn-sm btn-dark fw-bold border-dark ms-auto" onclick="abrirModalTreino()">
      <i class="fas fa-plus me-1"></i>Novo Bloco
    </button>
  </div>

  <div class="card border-dark shadow-sm gpt-card">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:12px;">
        <thead class="table-dark">
          <tr>
            <th style="width:110px;">Tipo</th>
            <th style="width:200px;">Título</th>
            <th>Conteúdo (prévia)</th>
            <th class="text-center" style="width:70px;">Chars</th>
            <th class="text-center" style="width:110px;">Status</th>
            <th>Última Sync</th>
            <th class="text-center" style="width:130px;">Ações</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($lista_treinos)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4 fst-italic">
            Nenhum bloco cadastrado. Clique em <b>Novo Bloco</b> para começar.
          </td></tr>
        <?php else:
          $tipo_atual = '';
          foreach ($lista_treinos as $tr):
            $tipo_bloco = $tr['TIPO'] ?? 'TREINAMENTO';
            $limite     = $tipo_bloco === 'COMPORTAMENTO' ? 3000 : 1028;
            $chars      = mb_strlen($tr['CONTEUDO']);
            $chars_cls  = $chars > $limite ? 'text-danger fw-bold' : ($chars > $limite * 0.87 ? 'text-warning fw-bold' : 'text-success fw-bold');
            $badge_status = match($tr['STATUS']) {
              'SINCRONIZADO' => '<span class="badge bg-success">✅ Sincronizado</span>',
              'ERRO'         => '<span class="badge bg-danger">❌ Erro</span>',
              default        => '<span class="badge bg-secondary">⏳ Local</span>'
            };
            // Separador de seção ao mudar de tipo
            if ($tipo_bloco !== $tipo_atual):
                $tipo_atual = $tipo_bloco;
                $sep_cor    = $tipo_bloco === 'COMPORTAMENTO' ? '#6f42c1' : '#198754';
                $sep_icon   = $tipo_bloco === 'COMPORTAMENTO' ? '🎭' : '📚';
                $sep_label  = $tipo_bloco === 'COMPORTAMENTO' ? 'Comportamento (instruções do agente)' : 'Treinamento (base de conhecimento)';
        ?>
            <tr>
              <td colspan="7" class="fw-bold text-white py-1 px-2" style="background:<?= $sep_cor ?>;font-size:11px;">
                <?= $sep_icon ?> <?= $sep_label ?>
              </td>
            </tr>
        <?php endif; ?>
          <tr id="row_treino_<?= $tr['ID'] ?>">
            <td>
              <?php if ($tipo_bloco === 'COMPORTAMENTO'): ?>
                <span class="badge border" style="background:#6f42c1;color:#fff;font-size:.7rem;">🎭 Comport.</span>
              <?php else: ?>
                <span class="badge bg-success" style="font-size:.7rem;">📚 Treino</span>
              <?php endif; ?>
            </td>
            <td class="fw-bold text-dark"><?= htmlspecialchars($tr['TITULO']) ?></td>
            <td class="text-muted text-truncate" style="max-width:280px;" title="<?= htmlspecialchars($tr['CONTEUDO']) ?>">
              <?= htmlspecialchars(mb_substr($tr['CONTEUDO'], 0, 80)) ?>...
            </td>
            <td class="text-center <?= $chars_cls ?>"><?= $chars ?>/<?= $limite ?></td>
            <td class="text-center"><?= $badge_status ?>
              <?php if ($tr['STATUS']==='ERRO' && $tr['MSG_ERRO']): ?>
              <div class="text-danger" style="font-size:10px;" title="<?= htmlspecialchars($tr['MSG_ERRO']) ?>">
                <?= htmlspecialchars(mb_substr($tr['MSG_ERRO'],0,30)) ?>
              </div>
              <?php endif; ?>
            </td>
            <td><?= $tr['DATA_SYNC'] ? date('d/m/Y H:i', strtotime($tr['DATA_SYNC'])) : '—' ?></td>
            <td class="text-center">
              <div class="d-flex gap-1 justify-content-center">
                <button class="btn btn-xs btn-outline-primary py-0 px-2" title="Editar"
                  onclick='editarTreino(<?= json_encode($tr) ?>)'>
                  <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-xs py-0 px-2 <?= $tipo_bloco==='COMPORTAMENTO' ? 'btn-outline-purple' : 'btn-outline-success' ?>"
                  style="<?= $tipo_bloco==='COMPORTAMENTO' ? 'color:#6f42c1;border-color:#6f42c1;' : '' ?>"
                  title="Sincronizar com GPTMaker"
                  onclick="sincronizarTreino(<?= $tr['ID'] ?>, '<?= addslashes($tr['TITULO']) ?>', '<?= $tipo_bloco ?>')">
                  <i class="fas fa-cloud-upload-alt"></i>
                </button>
                <button class="btn btn-xs btn-outline-danger py-0 px-2" title="Excluir"
                  onclick="excluirTreino(<?= $tr['ID'] ?>, '<?= addslashes($tr['TITULO']) ?>')">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Diferença Comportamento x Treinamento -->
  <div class="row g-2 mt-2">
    <div class="col-md-6">
      <div class="alert border py-2 small mb-0" style="border-color:#6f42c1!important;background:#f8f4ff;">
        <b style="color:#6f42c1;">🎭 Comportamento</b> — instruções de <em>como</em> o agente deve agir:<br>
        tom de voz, regras, fluxo da conversa, o que fazer/não fazer.<br>
        <small class="text-muted">Limite: 3.000 chars. Sincronizar sobrescreve o comportamento atual do agente.</small>
      </div>
    </div>
    <div class="col-md-6">
      <div class="alert alert-success border-success py-2 small mb-0">
        <b>📚 Treinamento</b> — <em>informações</em> sobre o produto para o agente consultar:<br>
        taxas, prazos, documentos, convênios, perguntas frequentes.<br>
        <small class="text-muted">Cada bloco é adicionado à base de conhecimento do agente.</small>
      </div>
    </div>
  </div>
</div><!-- /tabTreinamentos -->

<!-- ══ TAB 3: FOLLOW-UP ══════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabFollowup">
  <div class="card border-dark shadow-sm gpt-card">
    <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
      <span><i class="fas fa-history me-2 text-warning"></i>Sessões de Follow-up <span class="badge bg-secondary ms-1"><?= count($ultimasSessoes) ?></span></span>
      <a href="?<?= http_build_query(array_filter(['data_ini'=>$filtro_data_ini,'data_fim'=>$filtro_data_fim,'usuario'=>$filtro_usuario,'cpf'=>$filtro_cpf,'telefone'=>$filtro_telefone])) ?>" class="btn btn-sm btn-outline-light py-0">🔄</a>
    </div>
    <div class="p-2 border-bottom bg-light">
      <form method="GET" class="row g-1 align-items-end" style="font-size:12px;">
        <input type="hidden" name="tab" value="followup">
        <div class="col-auto">
          <label class="form-label mb-0 fw-bold" style="font-size:11px;">De</label>
          <input type="date" name="data_ini" class="form-control form-control-sm border-dark" style="font-size:11px;width:130px;" value="<?= htmlspecialchars($filtro_data_ini) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0 fw-bold" style="font-size:11px;">Até</label>
          <input type="date" name="data_fim" class="form-control form-control-sm border-dark" style="font-size:11px;width:130px;" value="<?= htmlspecialchars($filtro_data_fim) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0 fw-bold" style="font-size:11px;">Usuário</label>
          <input type="text" name="usuario" class="form-control form-control-sm border-dark" style="font-size:11px;width:140px;" placeholder="Nome..." value="<?= htmlspecialchars($filtro_usuario) ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0 fw-bold" style="font-size:11px;">CPF Cliente</label>
          <input type="text" name="cpf" class="form-control form-control-sm border-dark" style="font-size:11px;width:120px;" placeholder="Somente dígitos" value="<?= htmlspecialchars($_GET['cpf'] ?? '') ?>">
        </div>
        <div class="col-auto">
          <label class="form-label mb-0 fw-bold" style="font-size:11px;">Telefone</label>
          <input type="text" name="telefone" class="form-control form-control-sm border-dark" style="font-size:11px;width:120px;" placeholder="Somente dígitos" value="<?= htmlspecialchars($_GET['telefone'] ?? '') ?>">
        </div>
        <div class="col-auto d-flex gap-1">
          <button type="submit" class="btn btn-sm btn-dark py-0 px-2" style="font-size:11px;"><i class="fas fa-search me-1"></i>Filtrar</button>
          <a href="?tab=followup" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px;">✕</a>
        </div>
      </form>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0" style="font-size:12px;">
        <thead class="table-dark">
          <tr><th>Usuário / Agent ID</th><th>CPF Cliente</th><th>Telefone</th><th>Status</th><th>Tentativas</th><th>Criado</th><th>Observação</th></tr>
        </thead>
        <tbody>
        <?php if (empty($ultimasSessoes)): ?>
          <tr><td colspan="7" class="text-center text-muted py-3 fst-italic">Nenhuma sessão encontrada.</td></tr>
        <?php else: ?>
          <?php foreach ($ultimasSessoes as $s):
            $badge = match($s['STATUS']) { 'PROCESSADO'=>'bg-success','ERRO'=>'bg-danger','EXPIRADO'=>'bg-secondary',default=>'bg-warning text-dark' };
            $agentId=$s['GPTMAKE_AGENT_ID']??$s['AGENT_ID']??''; $donoNome=$s['DONO_NOME']??'—';
          ?>
          <tr>
            <td><div class="fw-bold"><?= htmlspecialchars($donoNome) ?></div>
              <?php if($agentId): ?><div class="font-monospace text-muted" style="font-size:10px;"><?= substr(htmlspecialchars($agentId),0,18) ?>...</div><?php endif; ?>
            </td>
            <td class="font-monospace"><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.***.***-$4', str_pad($s['CPF_CLIENTE'],11,'0',STR_PAD_LEFT)) ?></td>
            <td><?= htmlspecialchars($s['TELEFONE']??'—') ?></td>
            <td><span class="badge <?= $badge ?>"><?= $s['STATUS'] ?></span></td>
            <td class="text-center"><?= $s['TENTATIVAS'] ?>/10</td>
            <td><?= $s['DATA_CRIACAO'] ? date('d/m H:i', strtotime($s['DATA_CRIACAO'])) : '—' ?></td>
            <td class="text-truncate" style="max-width:200px;" title="<?= htmlspecialchars($s['OBSERVACAO']??'') ?>"><?= htmlspecialchars($s['OBSERVACAO']??'—') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div><!-- /tabFollowup -->

<!-- ══ TAB 4: WORKER / LOG ═══════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tabWorker">
  <div class="row g-3">
    <div class="col-md-4">
      <div class="card border-dark shadow-sm gpt-card">
        <div class="card-header bg-secondary text-white fw-bold small">
          <i class="fas fa-clock me-2"></i>Worker Follow-up (Cron: a cada 2 min)
        </div>
        <div class="card-body py-3">
          <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge bg-warning text-dark px-2 py-1">⏳ Pendente: <?= $statsMap['PENDENTE']??0 ?></span>
            <span class="badge bg-success px-2 py-1">✅ Processado: <?= $statsMap['PROCESSADO']??0 ?></span>
            <span class="badge bg-danger px-2 py-1">❌ Erro: <?= $statsMap['ERRO']??0 ?></span>
            <span class="badge bg-secondary px-2 py-1">💤 Expirado: <?= $statsMap['EXPIRADO']??0 ?></span>
          </div>
          <a href="?forcar_worker=1" class="btn btn-sm btn-outline-dark border-dark">
            <i class="fas fa-play me-1"></i>Executar Agora
          </a>
        </div>
      </div>
    </div>
    <div class="col-md-8">
      <div class="card border-dark shadow-sm gpt-card">
        <div class="card-header bg-dark text-white fw-bold small">
          <i class="fas fa-terminal me-1"></i>Log do Worker (hoje)
        </div>
        <div class="card-body p-0">
          <pre style="background:#1e1e1e;color:#d4d4d4;font-size:11px;min-height:200px;max-height:500px;overflow-y:auto;margin:0;padding:10px;"><?php
            $logFile = __DIR__ . '/logs_ia/followup_' . date('Y-m-d') . '.log';
            echo file_exists($logFile) ? htmlspecialchars(implode('', array_slice(file($logFile), -50))) : "Nenhum log ainda hoje.";
          ?></pre>
        </div>
      </div>
    </div>
  </div>
</div><!-- /tabWorker -->

</div><!-- /tab-content -->
</div><!-- /container-fluid -->

<!-- ══ MODAL: NOVO / EDITAR BLOCO DE TREINAMENTO ══════════════════════════ -->
<div class="modal fade" id="modalTreino" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header border-dark text-white" id="modalTreinoHeader" style="background:#343a40;">
        <h5 class="modal-title fw-bold"><span id="modalTreinoIcon">🎭</span> <span id="modalTreinoTitulo">Novo Bloco</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
        <input type="hidden" id="treino_id">
        <div class="mb-3">
          <label class="fw-bold small">Tipo do Bloco</label>
          <select id="treino_tipo" class="form-select border-dark fw-bold" style="font-size:12px;" onchange="onTipoChange()">
            <option value="COMPORTAMENTO">🎭 Comportamento — tom de voz, regras, fluxo da conversa</option>
            <option value="TREINAMENTO">📚 Treinamento — dados e informações do produto</option>
          </select>
          <div id="dica_tipo" class="form-text mt-1" style="font-size:11px;"></div>
        </div>
        <div class="mb-3">
          <label class="fw-bold small">Agente (Agent ID)</label>
          <select id="treino_agent" class="form-select border-dark" style="font-size:12px;">
            <?php foreach($configs as $cfg): ?>
            <option value="<?= htmlspecialchars($cfg['AGENT_ID']) ?>">
              <?= htmlspecialchars($cfg['NOME_USUARIO_ATUAL']??$cfg['NOME_USUARIO']) ?> — <?= substr($cfg['AGENT_ID'],0,22) ?>...
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="fw-bold small">Título do Bloco</label>
          <input type="text" id="treino_titulo" class="form-control border-dark" placeholder="Ex: Regras de Atendimento" maxlength="150">
        </div>
        <div class="mb-1">
          <label class="fw-bold small d-flex justify-content-between">
            Conteúdo
            <span id="treino_chars" class="text-success fw-bold">0/3000</span>
          </label>
          <textarea id="treino_conteudo" class="form-control border-dark font-monospace" rows="10"
            placeholder="Escreva o conteúdo aqui..." style="font-size:12px;"
            oninput="atualizarCharsCounter()"></textarea>
          <div class="form-text" id="treino_limite_hint">Comportamento: máximo 3.000 caracteres.</div>
        </div>
      </div>
      <div class="modal-footer bg-white border-dark d-flex justify-content-between">
        <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-success fw-bold border-dark px-4" onclick="salvarTreino()">
          <i class="fas fa-save me-1"></i>Salvar Bloco
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Tab persistência ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const tabParam = new URLSearchParams(location.search).get('tab');
    if (tabParam) {
        const el = document.querySelector(`[data-bs-target="#tab${tabParam.charAt(0).toUpperCase()+tabParam.slice(1)}"]`);
        if (el) new bootstrap.Tab(el).show();
    }
});

// ── Treinamentos / Comportamento ──────────────────────────────────────────
function getLimiteTipo() {
    const tipo = document.getElementById('treino_tipo')?.value || 'TREINAMENTO';
    return tipo === 'COMPORTAMENTO' ? 3000 : 1028;
}

function onTipoChange() {
    const tipo = document.getElementById('treino_tipo').value;
    const isCp = tipo === 'COMPORTAMENTO';
    document.getElementById('modalTreinoIcon').textContent = isCp ? '🎭' : '📚';
    document.getElementById('modalTreinoHeader').style.background = isCp ? '#4a2d7d' : '#343a40';
    document.getElementById('dica_tipo').textContent = isCp
        ? 'Comportamento: instruções de como agir, tom de voz, fluxo. Limite: 3.000 chars. Sincronizar sobrescreve o comportamento atual do agente.'
        : 'Treinamento: informações/dados sobre o produto (taxas, prazos, documentos). Cada bloco é adicionado à base de conhecimento.';
    document.getElementById('treino_limite_hint').textContent = isCp
        ? 'Comportamento: máximo 3.000 caracteres.'
        : 'Treinamento: máximo 1.028 caracteres por bloco.';
    atualizarCharsCounter();
}

function abrirModalTreino(tipoDefault) {
    document.getElementById('treino_id').value = '';
    document.getElementById('treino_titulo').value = '';
    document.getElementById('treino_conteudo').value = '';
    document.getElementById('treino_tipo').value = tipoDefault || 'COMPORTAMENTO';
    document.getElementById('modalTreinoTitulo').textContent = 'Novo Bloco';
    onTipoChange();
    new bootstrap.Modal(document.getElementById('modalTreino')).show();
}

function editarTreino(t) {
    document.getElementById('treino_id').value = t.ID;
    document.getElementById('treino_titulo').value = t.TITULO;
    document.getElementById('treino_conteudo').value = t.CONTEUDO;
    document.getElementById('treino_agent').value = t.AGENT_ID;
    document.getElementById('treino_tipo').value = t.TIPO || 'COMPORTAMENTO';
    document.getElementById('modalTreinoTitulo').textContent = 'Editar Bloco';
    onTipoChange();
    new bootstrap.Modal(document.getElementById('modalTreino')).show();
}

function atualizarCharsCounter() {
    const limite = getLimiteTipo();
    const len    = document.getElementById('treino_conteudo').value.length;
    const el     = document.getElementById('treino_chars');
    el.textContent = len + '/' + limite;
    el.className   = len > limite ? 'text-danger fw-bold' : len > (limite * 0.87) ? 'text-warning fw-bold' : 'text-success fw-bold';
}

function salvarTreino() {
    const titulo   = document.getElementById('treino_titulo').value.trim();
    const conteudo = document.getElementById('treino_conteudo').value.trim();
    const agent    = document.getElementById('treino_agent').value;
    const tipo     = document.getElementById('treino_tipo').value;
    const id       = document.getElementById('treino_id').value;
    if (!titulo || !conteudo) { alert('Preencha título e conteúdo.'); return; }
    const limite = getLimiteTipo();
    if (conteudo.length > limite) {
        if (!confirm(`O conteúdo tem ${conteudo.length} chars (limite ${limite}). Deseja salvar mesmo assim?`)) return;
    }
    const fd = new FormData();
    fd.append('acao','salvar_treino'); fd.append('titulo',titulo);
    fd.append('conteudo',conteudo); fd.append('agent_id_treino',agent);
    fd.append('id_treino',id); fd.append('tipo',tipo);
    fetch('config_gptmake.php',{method:'POST',body:fd}).then(r=>r.json()).then(j=>{
        if(j.ok){ bootstrap.Modal.getInstance(document.getElementById('modalTreino')).hide(); location.reload(); }
        else alert(j.msg);
    });
}

async function sincronizarTreino(id, titulo, tipo) {
    const tipoLabel = tipo === 'COMPORTAMENTO' ? '🎭 comportamento' : '📚 treinamento';
    if (!confirm(`Sincronizar ${tipoLabel} "${titulo}" com o GPTMaker?\n\n${tipo==='COMPORTAMENTO'?'⚠️ Isso sobrescreve o comportamento atual do agente.':''}`)) return;
    const btn = event.target.closest('button');
    const btnOriginal = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    btn.disabled = true;

    try {
        const fd = new FormData();
        fd.append('acao', 'sincronizar_treino');
        fd.append('id_treino', id);

        const r = await fetch('config_gptmake.php', { method: 'POST', body: fd });
        const text = await r.text(); // lê como texto primeiro para diagnóstico

        let j;
        try {
            j = JSON.parse(text);
        } catch(parseErr) {
            alert('Erro: resposta inválida do servidor.\n\n' + text.substring(0, 300));
            btn.innerHTML = btnOriginal;
            btn.disabled = false;
            return;
        }

        if (j.ok) {
            alert('✅ ' + (j.msg || 'Sincronizado com sucesso!'));
            location.reload();
        } else {
            alert('❌ ' + (j.msg || 'Erro ao sincronizar.'));
            btn.innerHTML = btnOriginal;
            btn.disabled = false;
        }
    } catch(err) {
        alert('❌ Falha de comunicação: ' + err.message);
        btn.innerHTML = btnOriginal;
        btn.disabled = false;
    }
}

function excluirTreino(id, titulo) {
    if (!confirm(`Excluir o bloco "${titulo}"?`)) return;
    const fd = new FormData();
    fd.append('acao','excluir_treino'); fd.append('id_treino',id);
    fetch('config_gptmake.php',{method:'POST',body:fd}).then(()=>location.reload());
}
</script>

<?php if (isset($_GET['forcar_worker'])):
    $ch = curl_init('https://crm.assessoriaconsignado.com/modulos/configuracao/v8_clt_margem_e_digitacao/worker_gptmake_followup.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); curl_close($ch);
    header("Location: config_gptmake.php?executado=1"); exit;
endif;

$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) include $caminho_footer;
?>
