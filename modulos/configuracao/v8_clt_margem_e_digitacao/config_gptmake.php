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

$msg = ''; $msg_tipo = '';

// SALVAR CONFIG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_config') {
    $api_token = trim($_POST['api_token'] ?? '');
    $agent_id  = trim($_POST['agent_id'] ?? '');
    if ($api_token && $agent_id) {
        $existe = $pdo->query("SELECT COUNT(*) FROM INTEGRACAO_GPTMAKE_CONFIG")->fetchColumn();
        if ($existe) {
            $pdo->prepare("UPDATE INTEGRACAO_GPTMAKE_CONFIG SET API_TOKEN=?, AGENT_ID=?, STATUS='ATIVO'")->execute([$api_token, $agent_id]);
        } else {
            $pdo->prepare("INSERT INTO INTEGRACAO_GPTMAKE_CONFIG (API_TOKEN, AGENT_ID) VALUES (?,?)")->execute([$api_token, $agent_id]);
        }
        $msg = 'Configuração salva com sucesso!'; $msg_tipo = 'success';
    } else {
        $msg = 'Preencha o Token e o Agent ID.'; $msg_tipo = 'danger';
    }
}

// TESTAR CONEXÃO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'testar') {
    $api_token = trim($_POST['api_token'] ?? '');
    $agent_id  = trim($_POST['agent_id'] ?? '');
    $ch = curl_init("https://api.gptmaker.ai/v2/agent/{$agent_id}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
        CURLOPT_HTTPHEADER=>["Authorization: Bearer {$api_token}"]]);
    $res = curl_exec($ch); $http = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $json = json_decode($res, true);
    if ($http === 200 && isset($json['name'])) {
        $msg = "✅ Conexão OK! Agente encontrado: <strong>" . htmlspecialchars($json['name']) . "</strong>";
        $msg_tipo = 'success';
    } else {
        $msg = "❌ Falha na conexão (HTTP {$http}). Verifique o Token e Agent ID.";
        $msg_tipo = 'danger';
    }
}

// Carrega config atual
$config = $pdo->query("SELECT * FROM INTEGRACAO_GPTMAKE_CONFIG LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];

// Estatísticas followup
$stats = $pdo->query("SELECT STATUS, COUNT(*) as total FROM INTEGRACAO_V8_IA_FOLLOWUP GROUP BY STATUS")->fetchAll(PDO::FETCH_ASSOC);
$statsMap = array_column($stats, 'total', 'STATUS');

// Últimas sessões
$ultimasSessoes = $pdo->query("SELECT * FROM INTEGRACAO_V8_IA_FOLLOWUP ORDER BY DATA_CRIACAO DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

include $caminho_header;
?>
<div class="container-fluid py-3">
<div class="row g-3">

  <!-- Config Card -->
  <div class="col-md-5">
    <div class="card border-dark shadow-sm">
      <div class="card-header bg-dark text-white fw-bold">
        <i class="fas fa-robot me-2 text-success"></i>GPTMaker — Configuração da API
      </div>
      <div class="card-body">
        <?php if ($msg): ?>
          <div class="alert alert-<?= $msg_tipo ?> py-2 small"><?= $msg ?></div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="acao" value="salvar_config">
          <div class="mb-3">
            <label class="form-label fw-bold small">API Token (Bearer)</label>
            <input type="text" name="api_token" class="form-control border-dark font-monospace"
              value="<?= htmlspecialchars($config['API_TOKEN'] ?? '') ?>"
              placeholder="eyJ..." required>
            <div class="form-text">Obtido em: app.gptmaker.ai → Desenvolvedores</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold small">Agent ID</label>
            <input type="text" name="agent_id" class="form-control border-dark font-monospace"
              value="<?= htmlspecialchars($config['AGENT_ID'] ?? '') ?>"
              placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required>
            <div class="form-text">ID do agente ATENDENTE CLT no GPTMaker</div>
          </div>
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success fw-bold border-dark">
              <i class="fas fa-save me-1"></i>Salvar
            </button>
            <button type="submit" form="form_teste" class="btn btn-outline-primary border-dark">
              <i class="fas fa-plug me-1"></i>Testar Conexão
            </button>
          </div>
        </form>
        <form id="form_teste" method="POST">
          <input type="hidden" name="acao" value="testar">
          <input type="hidden" name="api_token" id="test_token" value="<?= htmlspecialchars($config['API_TOKEN'] ?? '') ?>">
          <input type="hidden" name="agent_id"  id="test_agent" value="<?= htmlspecialchars($config['AGENT_ID'] ?? '') ?>">
        </form>
      </div>
    </div>

    <!-- Status Worker -->
    <div class="card border-dark shadow-sm mt-3">
      <div class="card-header bg-secondary text-white fw-bold small">
        <i class="fas fa-clock me-2"></i>Worker Follow-up (Cron: a cada 2 min)
      </div>
      <div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 mb-2">
          <span class="badge bg-warning text-dark fs-6 px-3">
            ⏳ Pendente: <?= $statsMap['PENDENTE'] ?? 0 ?>
          </span>
          <span class="badge bg-success fs-6 px-3">
            ✅ Processado: <?= $statsMap['PROCESSADO'] ?? 0 ?>
          </span>
          <span class="badge bg-danger fs-6 px-3">
            ❌ Erro: <?= $statsMap['ERRO'] ?? 0 ?>
          </span>
          <span class="badge bg-secondary fs-6 px-3">
            💤 Expirado: <?= $statsMap['EXPIRADO'] ?? 0 ?>
          </span>
        </div>
        <a href="?forcar_worker=1" class="btn btn-sm btn-outline-dark border-dark">
          <i class="fas fa-play me-1"></i>Executar Worker Agora
        </a>
      </div>
    </div>

    <!-- Como configurar no GPTMaker -->
    <div class="card border-info shadow-sm mt-3">
      <div class="card-header bg-info text-white fw-bold small">
        <i class="fas fa-info-circle me-1"></i>Como encontrar o Agent ID
      </div>
      <div class="card-body small text-muted py-2">
        <ol class="mb-0 ps-3">
          <li>Acesse <strong>app.gptmaker.ai</strong></li>
          <li>Clique no agente <strong>ATENDENTE CLT</strong></li>
          <li>Vá em <strong>Configurações</strong></li>
          <li>Copie o <strong>ID do Agente</strong> (UUID)</li>
          <li>Para o Token: <strong>Desenvolvedores → Gerar Token</strong></li>
        </ol>
      </div>
    </div>
  </div>

  <!-- Tabela de sessões -->
  <div class="col-md-7">
    <div class="card border-dark shadow-sm">
      <div class="card-header bg-dark text-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="fas fa-list me-2"></i>Últimas Sessões de Follow-up</span>
        <a href="?" class="btn btn-sm btn-outline-light">🔄 Atualizar</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:12px;">
          <thead class="table-dark">
            <tr>
              <th>CPF</th><th>Telefone</th><th>Status</th>
              <th>Tentativas</th><th>Criado</th><th>Observação</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($ultimasSessoes)): ?>
            <tr><td colspan="6" class="text-center text-muted py-3 fst-italic">Nenhuma sessão registrada.</td></tr>
          <?php else: ?>
            <?php foreach ($ultimasSessoes as $s):
              $badge = match($s['STATUS']) {
                'PROCESSADO' => 'bg-success',
                'ERRO'       => 'bg-danger',
                'EXPIRADO'   => 'bg-secondary',
                default      => 'bg-warning text-dark'
              };
            ?>
            <tr>
              <td class="font-monospace"><?= preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.***.***-$4', str_pad($s['CPF_CLIENTE'],11,'0',STR_PAD_LEFT)) ?></td>
              <td><?= htmlspecialchars($s['TELEFONE'] ?? '—') ?></td>
              <td><span class="badge <?= $badge ?>"><?= $s['STATUS'] ?></span></td>
              <td class="text-center"><?= $s['TENTATIVAS'] ?>/10</td>
              <td><?= $s['DATA_CRIACAO'] ? date('d/m H:i', strtotime($s['DATA_CRIACAO'])) : '—' ?></td>
              <td class="text-truncate" style="max-width:180px;" title="<?= htmlspecialchars($s['OBSERVACAO'] ?? '') ?>">
                <?= htmlspecialchars($s['OBSERVACAO'] ?? '—') ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Log do worker -->
    <div class="card border-dark shadow-sm mt-3">
      <div class="card-header bg-dark text-white fw-bold small">
        <i class="fas fa-terminal me-1"></i>Log do Worker (hoje)
      </div>
      <div class="card-body p-0">
        <pre style="background:#1e1e1e;color:#d4d4d4;font-size:11px;max-height:200px;overflow-y:auto;margin:0;padding:10px;"><?php
          $logFile = __DIR__ . '/logs_ia/followup_' . date('Y-m-d') . '.log';
          if (file_exists($logFile)) {
              $lines = file($logFile);
              echo htmlspecialchars(implode('', array_slice($lines, -30)));
          } else {
              echo "Nenhum log ainda hoje.";
          }
        ?></pre>
      </div>
    </div>
  </div>

</div>
</div>

<?php
// Executa worker manualmente
if (isset($_GET['forcar_worker'])) {
    $url = 'https://crm.assessoriaconsignado.com/modulos/configuracao/v8_clt_margem_e_digitacao/worker_gptmake_followup.php';
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); curl_close($ch);
    header("Location: config_gptmake.php?executado=1"); exit;
}
?>
<script>
// Sincroniza campos do form de teste com os do form principal
document.querySelector('[name="api_token"]')?.addEventListener('input', function(){ document.getElementById('test_token').value = this.value; });
document.querySelector('[name="agent_id"]')?.addEventListener('input',  function(){ document.getElementById('test_agent').value = this.value; });
</script>
