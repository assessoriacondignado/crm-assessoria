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

// Últimas sessões
$stSess = $pdo->prepare("SELECT f.* FROM INTEGRACAO_V8_IA_FOLLOWUP f ORDER BY f.DATA_CRIACAO DESC LIMIT 20");
$stSess->execute();
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

<div class="container-fluid py-3">
<?php if ($msg): ?>
  <div class="alert alert-<?= $msg_tipo ?> py-2 mb-3"><?= $msg ?></div>
<?php endif; ?>
<?php if (isset($_GET['executado'])): ?>
  <div class="alert alert-success py-2 mb-3">Worker executado manualmente.</div>
<?php endif; ?>

<div class="row g-3">

  <!-- ── Formulário ─────────────────────────────────────── -->
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

    <!-- Worker status -->
    <div class="card border-dark shadow-sm mt-3 gpt-card">
      <div class="card-header bg-secondary text-white fw-bold small">
        <i class="fas fa-clock me-2"></i>Worker Follow-up (Cron: a cada 2 min)
      </div>
      <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge bg-warning text-dark px-2 py-1">⏳ Pendente: <?= $statsMap['PENDENTE'] ?? 0 ?></span>
          <span class="badge bg-success px-2 py-1">✅ Processado: <?= $statsMap['PROCESSADO'] ?? 0 ?></span>
          <span class="badge bg-danger px-2 py-1">❌ Erro: <?= $statsMap['ERRO'] ?? 0 ?></span>
          <span class="badge bg-secondary px-2 py-1">💤 Expirado: <?= $statsMap['EXPIRADO'] ?? 0 ?></span>
        </div>
        <a href="?forcar_worker=1" class="btn btn-sm btn-outline-dark border-dark">
          <i class="fas fa-play me-1"></i>Executar Agora
        </a>
      </div>
    </div>

    <!-- Log -->
    <div class="card border-dark shadow-sm mt-3 gpt-card">
      <div class="card-header bg-dark text-white fw-bold small">
        <i class="fas fa-terminal me-1"></i>Log do Worker (hoje)
      </div>
      <div class="card-body p-0">
        <pre style="background:#1e1e1e;color:#d4d4d4;font-size:11px;max-height:180px;overflow-y:auto;margin:0;padding:10px;"><?php
          $logFile = __DIR__ . '/logs_ia/followup_' . date('Y-m-d') . '.log';
          echo file_exists($logFile)
            ? htmlspecialchars(implode('', array_slice(file($logFile), -30)))
            : "Nenhum log ainda hoje.";
        ?></pre>
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

    <!-- Últimas sessões -->
    <div class="card border-dark shadow-sm gpt-card">
      <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="fas fa-history me-2"></i>Últimas Sessões de Follow-up</span>
        <a href="?" class="btn btn-sm btn-outline-light py-0">🔄</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:12px;">
          <thead class="table-dark">
            <tr><th>CPF</th><th>Telefone</th><th>Status</th><th>Tentativas</th><th>Criado</th><th>Observação</th></tr>
          </thead>
          <tbody>
          <?php if (empty($ultimasSessoes)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3 fst-italic">Nenhuma sessão registrada.</td></tr>
          <?php else: ?>
            <?php foreach ($ultimasSessoes as $s):
              $badge = match($s['STATUS']) {
                'PROCESSADO' => 'bg-success', 'ERRO' => 'bg-danger',
                'EXPIRADO'   => 'bg-secondary', default => 'bg-warning text-dark'
              };
            ?>
            <tr>
              <td class="font-monospace"><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.***.***-$4', str_pad($s['CPF_CLIENTE'],11,'0',STR_PAD_LEFT)) ?></td>
              <td><?= htmlspecialchars($s['TELEFONE'] ?? '—') ?></td>
              <td><span class="badge <?= $badge ?>"><?= $s['STATUS'] ?></span></td>
              <td class="text-center"><?= $s['TENTATIVAS'] ?>/10</td>
              <td><?= $s['DATA_CRIACAO'] ? date('d/m H:i', strtotime($s['DATA_CRIACAO'])) : '—' ?></td>
              <td class="text-truncate" style="max-width:200px;" title="<?= htmlspecialchars($s['OBSERVACAO']??'') ?>">
                <?= htmlspecialchars($s['OBSERVACAO'] ?? '—') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
</div>

<?php if (isset($_GET['forcar_worker'])):
    $ch = curl_init('https://crm.assessoriaconsignado.com/modulos/configuracao/v8_clt_margem_e_digitacao/worker_gptmake_followup.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); curl_close($ch);
    header("Location: config_gptmake.php?executado=1"); exit;
endif; ?>
