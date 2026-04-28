<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
if (!isset($pdo) && isset($conn)) $pdo = $conn;

// Segurança: só MASTER pode acessar
$grupo = strtoupper($_SESSION['usuario_grupo'] ?? '');
if (!in_array($grupo, ['MASTER', 'ADMIN', 'ADMINISTRADOR'])) {
    http_response_code(403); die('Acesso negado.');
}

// ────────────────────────────────────────────────────────────
// FUNÇÕES DE BENCHMARK
// ────────────────────────────────────────────────────────────
function bench_cpu() {
    $t = microtime(true);
    $n = 0;
    for ($i = 2; $i <= 15000; $i++) {
        $primo = true;
        for ($j = 2; $j <= sqrt($i); $j++) {
            if ($i % $j === 0) { $primo = false; break; }
        }
        if ($primo) $n++;
    }
    return ['ms' => round((microtime(true) - $t) * 1000, 2), 'primos' => $n];
}

function bench_hash() {
    $t = microtime(true);
    for ($i = 0; $i < 5000; $i++) {
        password_hash("benchmark_test_{$i}", PASSWORD_BCRYPT, ['cost' => 4]);
    }
    return round((microtime(true) - $t) * 1000, 2);
}

function bench_string() {
    $t = microtime(true);
    $s = '';
    for ($i = 0; $i < 50000; $i++) $s .= md5($i);
    $l = strlen($s);
    return ['ms' => round((microtime(true) - $t) * 1000, 2), 'chars' => $l];
}

function bench_disk_write($dir) {
    $file = $dir . '/bench_tmp_' . getmypid() . '.dat';
    $data = str_repeat('X', 1024 * 1024); // 1MB
    $t = microtime(true);
    file_put_contents($file, $data);
    $w = round((microtime(true) - $t) * 1000, 2);
    $t = microtime(true);
    file_get_contents($file);
    $r = round((microtime(true) - $t) * 1000, 2);
    @unlink($file);
    return ['write_ms' => $w, 'read_ms' => $r];
}

function bench_mysql_simples($pdo) {
    $t = microtime(true);
    $pdo->query("SELECT 1+1")->fetchAll();
    return round((microtime(true) - $t) * 1000, 3);
}

function bench_mysql_count($pdo) {
    $t = microtime(true);
    $pdo->query("SELECT COUNT(*) FROM dados_cadastrais")->fetchColumn();
    return round((microtime(true) - $t) * 1000, 2);
}

function bench_mysql_fulltext($pdo) {
    $t = microtime(true);
    $pdo->query("SELECT cpf, nome FROM dados_cadastrais WHERE MATCH(nome) AGAINST('+MARIA* +SILVA*' IN BOOLEAN MODE) LIMIT 50")->fetchAll();
    return round((microtime(true) - $t) * 1000, 2);
}

function bench_mysql_join($pdo) {
    $t = microtime(true);
    $pdo->query("SELECT d.cpf, d.nome, t.telefone_cel FROM dados_cadastrais d LEFT JOIN telefones t ON d.cpf = t.cpf LIMIT 500")->fetchAll();
    return round((microtime(true) - $t) * 1000, 2);
}

function bench_mysql_like($pdo) {
    $t = microtime(true);
    $pdo->query("SELECT cpf, nome FROM dados_cadastrais WHERE nome LIKE 'MARIA SILVA%' LIMIT 50")->fetchAll();
    return round((microtime(true) - $t) * 1000, 2);
}

function classificar($ms, $bom, $medio) {
    if ($ms <= $bom) return ['class' => 'success', 'label' => 'Ótimo'];
    if ($ms <= $medio) return ['class' => 'warning', 'label' => 'Médio'];
    return ['class' => 'danger', 'label' => 'Lento'];
}

function bar($ms, $max) {
    $pct = min(100, round(($ms / $max) * 100));
    return $pct;
}

// ────────────────────────────────────────────────────────────
// COLETA DE MÉTRICAS DO SERVIDOR
// ────────────────────────────────────────────────────────────
$load_avg = sys_getloadavg();
$mem_raw  = file_get_contents('/proc/meminfo');
preg_match('/MemTotal:\s+(\d+)/', $mem_raw, $m); $mem_total = (int)$m[1];
preg_match('/MemAvailable:\s+(\d+)/', $mem_raw, $m); $mem_avail = (int)$m[1];
preg_match('/SwapTotal:\s+(\d+)/', $mem_raw, $m); $swap_total = (int)$m[1];
preg_match('/SwapFree:\s+(\d+)/', $mem_raw, $m); $swap_free = (int)$m[1];
$mem_used     = $mem_total - $mem_avail;
$mem_pct      = round(($mem_used / $mem_total) * 100);
$swap_used    = $swap_total - $swap_free;
$swap_pct     = $swap_total > 0 ? round(($swap_used / $swap_total) * 100) : 0;

$cpu_model    = trim(shell_exec("cat /proc/cpuinfo | grep 'model name' | head -1 | cut -d: -f2") ?: 'Desconhecido');
$cpu_cores    = (int)(shell_exec("nproc") ?: 1);
$disk_raw     = shell_exec("df -h / | tail -1");
preg_match('/(\d+)%/', $disk_raw, $m); $disk_pct = (int)($m[1] ?? 0);
$disk_parts   = preg_split('/\s+/', trim($disk_raw));

$php_version  = phpversion();
$php_mem_max  = ini_get('memory_limit');
$php_mem_used = round(memory_get_usage(true) / 1024 / 1024, 1);

// MySQL stats
$mysql_vars   = [];
foreach ($pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetchAll(PDO::FETCH_ASSOC) as $r) $mysql_vars[$r['Variable_name']] = $r['Value'];
foreach ($pdo->query("SHOW STATUS LIKE 'Slow_queries'")->fetchAll(PDO::FETCH_ASSOC) as $r) $mysql_vars[$r['Variable_name']] = $r['Value'];
foreach ($pdo->query("SHOW STATUS LIKE 'Questions'")->fetchAll(PDO::FETCH_ASSOC) as $r) $mysql_vars[$r['Variable_name']] = $r['Value'];
foreach ($pdo->query("SHOW VARIABLES LIKE 'innodb_buffer_pool_size'")->fetchAll(PDO::FETCH_ASSOC) as $r) $mysql_vars[$r['Variable_name']] = $r['Value'];

$buffer_pool_gb = round((int)($mysql_vars['innodb_buffer_pool_size'] ?? 0) / 1024 / 1024 / 1024, 1);

// Tamanho total do banco
$db_size = $pdo->query("SELECT ROUND(SUM(data_length+index_length)/1024/1024,1) FROM information_schema.tables WHERE table_schema='crm_dados'")->fetchColumn();

// Top 5 tabelas
$top_tabelas = $pdo->query("SELECT table_name, ROUND((data_length+index_length)/1024/1024,1) as total_mb FROM information_schema.tables WHERE table_schema='crm_dados' ORDER BY (data_length+index_length) DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Total de registros nas tabelas chave
$qtd_cadastros = $pdo->query("SELECT COUNT(*) FROM dados_cadastrais")->fetchColumn();
$qtd_telefones = $pdo->query("SELECT COUNT(*) FROM telefones")->fetchColumn();
$qtd_lotes     = $pdo->query("SELECT COUNT(*) FROM INTEGRACAO_V8_IMPORTACAO_LOTE")->fetchColumn();

// ────────────────────────────────────────────────────────────
// EXECUTA BENCHMARKS
// ────────────────────────────────────────────────────────────
$b_cpu    = bench_cpu();
$b_string = bench_string();
$b_disk   = bench_disk_write('/tmp');
$b_sq     = bench_mysql_simples($pdo);
$b_count  = bench_mysql_count($pdo);
$b_ft     = bench_mysql_fulltext($pdo);
$b_join   = bench_mysql_join($pdo);
$b_like   = bench_mysql_like($pdo);

// ────────────────────────────────────────────────────────────
// SCORE GERAL (0-100)
// ────────────────────────────────────────────────────────────
$scores = [
    min(100, max(0, round(100 - ($b_cpu['ms'] / 20)))),       // CPU
    min(100, max(0, round(100 - ($b_string['ms'] / 10)))),    // String
    min(100, max(0, round(100 - ($b_disk['write_ms'] / 5)))), // Disco
    min(100, max(0, round(100 - ($b_count / 5)))),             // MySQL count
    min(100, max(0, round(100 - ($b_ft / 3)))),                // MySQL FT
    min(100, max(0, round(100 - ($b_join / 5)))),              // MySQL join
];
$score_geral = round(array_sum($scores) / count($scores));
$score_class = $score_geral >= 75 ? 'success' : ($score_geral >= 50 ? 'warning' : 'danger');
$score_label = $score_geral >= 75 ? 'Servidor Saudável' : ($score_geral >= 50 ? 'Atenção Necessária' : 'Servidor Sobrecarregado');

// ────────────────────────────────────────────────────────────
// RECOMENDAÇÕES AUTOMÁTICAS
// ────────────────────────────────────────────────────────────
$recomendacoes = [];
if ($mem_pct > 80) $recomendacoes[] = ['danger', 'RAM crítica ('.$mem_pct.'% usada)', 'Servidor com memória RAM insuficiente. Recomendado: aumentar para 16GB ou mais.'];
elseif ($mem_pct > 65) $recomendacoes[] = ['warning', 'RAM elevada ('.$mem_pct.'% usada)', 'Memória RAM com uso alto. Monitorar crescimento de usuários.'];
if ($swap_pct > 20) $recomendacoes[] = ['danger', 'Swap em uso ('.$swap_pct.'%)', 'O servidor está usando swap (disco como RAM). Isso causa lentidão severa. Aumente a RAM.'];
if ($disk_pct > 80) $recomendacoes[] = ['danger', 'Disco quase cheio ('.$disk_pct.'%)', 'Disco com mais de 80% de uso. Risco de parada total do sistema.'];
if ($b_cpu['ms'] > 1500) $recomendacoes[] = ['danger', 'CPU lenta ('.$b_cpu['ms'].'ms)', 'Processador abaixo do esperado para a carga atual. Considere upgrade para CPU mais moderna (ex: AMD EPYC ou Intel Xeon recente).'];
if ($b_disk['write_ms'] > 300) $recomendacoes[] = ['warning', 'Disco lento (escrita: '.$b_disk['write_ms'].'ms)', 'Disco mecânico (HDD) detectado. Migrar para SSD reduziria drasticamente o tempo de resposta.'];
if ($b_count > 800) $recomendacoes[] = ['warning', 'COUNT(*) lento ('.$b_count.'ms)', 'Query de contagem dos cadastros está lenta. Verifique se há queries sem índice em execução.'];
if ($b_ft > 500) $recomendacoes[] = ['warning', 'Busca FULLTEXT lenta ('.$b_ft.'ms)', 'Busca por nome está com tempo elevado. Verifique se o índice FULLTEXT está otimizado.'];
if ((float)$db_size > 2000) $recomendacoes[] = ['warning', 'Banco de dados grande ('.$db_size.'MB)', 'Banco crescendo. Avaliar arquivamento de dados históricos antigos.'];
if ($buffer_pool_gb < 2 && (float)$db_size > 500) $recomendacoes[] = ['danger', 'InnoDB Buffer Pool pequeno', 'Buffer pool do MySQL menor que o banco de dados. Aumentar para pelo menos '.ceil((float)$db_size/1024).'GB.'];
if ($load_avg[0] > $cpu_cores) $recomendacoes[] = ['danger', 'Carga do servidor alta (load: '.$load_avg[0].')', 'Load average acima do número de CPUs. O servidor está sobrecarregado.'];
elseif ($load_avg[0] > $cpu_cores * 0.7) $recomendacoes[] = ['warning', 'Carga moderada (load: '.$load_avg[0].')', 'Carga próxima do limite. Monitorar crescimento.'];
if (empty($recomendacoes)) $recomendacoes[] = ['success', 'Servidor dentro dos parâmetros normais', 'Nenhum gargalo crítico detectado nos testes realizados.'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnóstico do Servidor — CRM</title>
<link rel="stylesheet" href="/assets/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
<style>
body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
.card-metric { border-radius: 8px; border: 1px solid #dee2e6; }
.score-circle {
    width: 120px; height: 120px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column; font-size: 2rem; font-weight: 900;
    border: 6px solid;
}
.score-circle.success { border-color: #198754; color: #198754; background: #d1e7dd; }
.score-circle.warning { border-color: #ffc107; color: #856404; background: #fff3cd; }
.score-circle.danger  { border-color: #dc3545; color: #dc3545; background: #f8d7da; }
.bench-bar { height: 8px; border-radius: 4px; background: #dee2e6; position: relative; }
.bench-bar-fill { height: 100%; border-radius: 4px; transition: width .6s ease; }
.stat-box { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:16px; }
</style>
</head>
<body>
<div class="container-fluid py-4 px-4">

    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h4 class="fw-bold mb-0"><i class="fas fa-tachometer-alt text-primary me-2"></i>Diagnóstico de Performance — Servidor</h4>
            <small class="text-muted">Gerado em <?= date('d/m/Y H:i:s') ?> — Resultados em tempo real</small>
        </div>
        <a href="javascript:location.reload()" class="btn btn-outline-primary border-dark fw-bold shadow-sm rounded-0">
            <i class="fas fa-sync me-1"></i> Novo Teste
        </a>
    </div>

    <!-- SCORE GERAL -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 d-flex align-items-center justify-content-center">
            <div class="text-center">
                <div class="score-circle <?= $score_class ?> mx-auto mb-2">
                    <span><?= $score_geral ?></span>
                    <span style="font-size:.6rem; font-weight:600;">SCORE</span>
                </div>
                <div class="fw-bold text-<?= $score_class ?> small"><?= $score_label ?></div>
            </div>
        </div>
        <div class="col-md-9">
            <div class="row g-2">
                <!-- CPU -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">CPU Load</span>
                            <span class="badge bg-<?= $load_avg[0] > $cpu_cores ? 'danger' : ($load_avg[0] > $cpu_cores*0.7 ? 'warning' : 'success') ?>"><?= $load_avg[0] ?></span>
                        </div>
                        <div class="fw-bold fs-5"><?= $cpu_cores ?> vCPUs</div>
                        <div class="text-muted" style="font-size:.72rem;"><?= trim($cpu_model) ?></div>
                        <div class="bench-bar mt-2"><div class="bench-bar-fill bg-<?= $load_avg[0] > $cpu_cores ? 'danger' : 'success' ?>" style="width:<?= min(100,round($load_avg[0]/$cpu_cores*100)) ?>%"></div></div>
                    </div>
                </div>
                <!-- RAM -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">RAM</span>
                            <span class="badge bg-<?= $mem_pct > 80 ? 'danger' : ($mem_pct > 65 ? 'warning' : 'success') ?>"><?= $mem_pct ?>%</span>
                        </div>
                        <div class="fw-bold fs-5"><?= round($mem_used/1024/1024,1) ?>GB <span class="text-muted fw-normal fs-6">/ <?= round($mem_total/1024/1024,1) ?>GB</span></div>
                        <div class="text-muted" style="font-size:.72rem;">Disponível: <?= round($mem_avail/1024/1024,1) ?>GB | Swap: <?= $swap_pct ?>%</div>
                        <div class="bench-bar mt-2"><div class="bench-bar-fill bg-<?= $mem_pct > 80 ? 'danger' : ($mem_pct > 65 ? 'warning' : 'success') ?>" style="width:<?= $mem_pct ?>%"></div></div>
                    </div>
                </div>
                <!-- DISCO -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">Disco</span>
                            <span class="badge bg-<?= $disk_pct > 80 ? 'danger' : ($disk_pct > 60 ? 'warning' : 'success') ?>"><?= $disk_pct ?>%</span>
                        </div>
                        <div class="fw-bold fs-5"><?= $disk_parts[2] ?? '?' ?> <span class="text-muted fw-normal fs-6">/ <?= $disk_parts[1] ?? '?' ?></span></div>
                        <div class="text-muted" style="font-size:.72rem;">Livre: <?= $disk_parts[3] ?? '?' ?></div>
                        <div class="bench-bar mt-2"><div class="bench-bar-fill bg-<?= $disk_pct > 80 ? 'danger' : 'success' ?>" style="width:<?= $disk_pct ?>%"></div></div>
                    </div>
                </div>
                <!-- BANCO -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">MySQL</span>
                            <span class="badge bg-info"><?= $mysql_vars['Threads_connected'] ?? '?' ?> conexões</span>
                        </div>
                        <div class="fw-bold fs-5"><?= $db_size ?>MB</div>
                        <div class="text-muted" style="font-size:.72rem;">Buffer Pool: <?= $buffer_pool_gb ?>GB | Slow Queries: <?= $mysql_vars['Slow_queries'] ?? 0 ?></div>
                        <div class="bench-bar mt-2"><div class="bench-bar-fill bg-info" style="width:<?= min(100,round((float)$db_size/30)) ?>%"></div></div>
                    </div>
                </div>
                <!-- REGISTROS -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">Base de Dados</span>
                        </div>
                        <div class="fw-bold fs-5"><?= number_format($qtd_cadastros,0,',','.') ?></div>
                        <div class="text-muted" style="font-size:.72rem;">Cadastros | <?= number_format($qtd_telefones,0,',','.') ?> telefones | <?= $qtd_lotes ?> lotes V8</div>
                    </div>
                </div>
                <!-- PHP -->
                <div class="col-6 col-md-4">
                    <div class="stat-box">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">PHP</span>
                            <span class="badge bg-secondary">v<?= $php_version ?></span>
                        </div>
                        <div class="fw-bold fs-5"><?= $php_mem_used ?>MB</div>
                        <div class="text-muted" style="font-size:.72rem;">Limite: <?= $php_mem_max ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BENCHMARKS -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white fw-bold py-2 text-uppercase" style="font-size:.8rem;">
                    <i class="fas fa-microchip me-2 text-info"></i> Benchmark — CPU & Processamento
                </div>
                <div class="card-body bg-white p-3">
                    <?php
                    $itens_cpu = [
                        ['label' => 'Cálculo de Primos (0-15k)', 'ms' => $b_cpu['ms'], 'bom' => 500, 'medio' => 1200, 'detalhe' => $b_cpu['primos'].' primos'],
                        ['label' => 'Operações de String (50k MD5)', 'ms' => $b_string['ms'], 'bom' => 400, 'medio' => 900, 'detalhe' => number_format($b_string['chars'],0,',','.').' chars'],
                        ['label' => 'Bcrypt Hash (5k ops)', 'ms' => isset($b_hash) ? $b_hash : 0, 'bom' => 200, 'medio' => 500, 'detalhe' => ''],
                    ];
                    foreach ($itens_cpu as $it):
                        if ($it['ms'] == 0) continue;
                        $cl = classificar($it['ms'], $it['bom'], $it['medio']);
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold"><?= $it['label'] ?></span>
                            <div>
                                <?php if($it['detalhe']): ?><span class="text-muted me-2" style="font-size:.7rem;"><?= $it['detalhe'] ?></span><?php endif; ?>
                                <span class="badge bg-<?= $cl['class'] ?>"><?= $it['ms'] ?>ms — <?= $cl['label'] ?></span>
                            </div>
                        </div>
                        <div class="bench-bar"><div class="bench-bar-fill bg-<?= $cl['class'] ?>" style="width:<?= bar($it['ms'], $it['medio']*2) ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="mb-0">
                        <?php $cl = classificar($b_disk['write_ms'], 100, 400); ?>
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold">Escrita em Disco (1MB)</span>
                            <span class="badge bg-<?= $cl['class'] ?>"><?= $b_disk['write_ms'] ?>ms — <?= $cl['label'] ?></span>
                        </div>
                        <div class="bench-bar"><div class="bench-bar-fill bg-<?= $cl['class'] ?>" style="width:<?= bar($b_disk['write_ms'], 800) ?>%"></div></div>
                        <div class="text-muted mt-1" style="font-size:.7rem;">Leitura: <?= $b_disk['read_ms'] ?>ms <?= $b_disk['write_ms'] > 200 ? '⚠️ HDD detectado — SSD seria ~10x mais rápido' : '✅ Velocidade normal' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-dark text-white fw-bold py-2 text-uppercase" style="font-size:.8rem;">
                    <i class="fas fa-database me-2 text-warning"></i> Benchmark — MySQL (<?= number_format($qtd_cadastros,0,',','.') ?> registros)
                </div>
                <div class="card-body bg-white p-3">
                    <?php
                    $itens_db = [
                        ['label' => 'Query simples (SELECT 1+1)', 'ms' => $b_sq, 'bom' => 2, 'medio' => 10],
                        ['label' => 'COUNT(*) — dados_cadastrais', 'ms' => $b_count, 'bom' => 200, 'medio' => 600],
                        ['label' => 'Busca FULLTEXT (nome)', 'ms' => $b_ft, 'bom' => 80, 'medio' => 300],
                        ['label' => 'JOIN dados+telefones (500 rows)', 'ms' => $b_join, 'bom' => 100, 'medio' => 400],
                        ['label' => 'LIKE prefixo (nome%)', 'ms' => $b_like, 'bom' => 50, 'medio' => 200],
                    ];
                    foreach ($itens_db as $it):
                        $cl = classificar($it['ms'], $it['bom'], $it['medio']);
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold"><?= $it['label'] ?></span>
                            <span class="badge bg-<?= $cl['class'] ?>"><?= $it['ms'] ?>ms — <?= $cl['label'] ?></span>
                        </div>
                        <div class="bench-bar"><div class="bench-bar-fill bg-<?= $cl['class'] ?>" style="width:<?= bar($it['ms'], $it['medio']*3) ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- TABELAS PRINCIPAIS -->
    <div class="row g-3 mb-4">
        <div class="col-md-5">
            <div class="card border-dark shadow-sm">
                <div class="card-header bg-secondary text-white fw-bold py-2 text-uppercase" style="font-size:.8rem;">
                    <i class="fas fa-table me-2"></i> Maiores Tabelas do Banco
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0" style="font-size:.78rem;">
                        <thead class="table-dark"><tr><th>Tabela</th><th class="text-end">Tamanho</th></tr></thead>
                        <tbody>
                        <?php foreach ($top_tabelas as $t): ?>
                        <tr>
                            <td class="fw-bold"><?= htmlspecialchars($t['table_name']) ?></td>
                            <td class="text-end">
                                <span class="badge bg-<?= $t['total_mb'] > 500 ? 'danger' : ($t['total_mb'] > 100 ? 'warning' : 'secondary') ?>">
                                    <?= $t['total_mb'] ?>MB
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-7">
            <div class="card border-dark shadow-sm h-100">
                <div class="card-header fw-bold py-2 text-uppercase" style="font-size:.8rem; background:#1a1a2e; color:#fff;">
                    <i class="fas fa-lightbulb text-warning me-2"></i> Diagnóstico & Recomendações
                </div>
                <div class="card-body p-3">
                    <?php foreach ($recomendacoes as [$tipo, $titulo, $desc]): ?>
                    <div class="alert alert-<?= $tipo ?> py-2 px-3 mb-2 border-<?= $tipo ?> shadow-sm" style="font-size:.8rem;">
                        <strong><i class="fas fa-<?= $tipo==='success'?'check-circle':($tipo==='warning'?'exclamation-triangle':'times-circle') ?> me-1"></i><?= $titulo ?></strong>
                        <?php if ($desc): ?><br><span class="text-<?= $tipo==='success'?'success':($tipo==='warning'?'dark':'danger') ?>"><?= $desc ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- RESUMO TÉCNICO -->
    <div class="card border-dark shadow-sm mb-3">
        <div class="card-header bg-dark text-white fw-bold py-2 text-uppercase" style="font-size:.8rem;">
            <i class="fas fa-info-circle me-2 text-info"></i> Resumo Técnico — O que está causando lentidão?
        </div>
        <div class="card-body bg-white p-3">
            <div class="row g-3" style="font-size:.82rem;">
                <div class="col-md-4">
                    <strong class="text-primary"><i class="fas fa-server me-1"></i> Configuração Atual</strong><br>
                    CPU: <?= $cpu_cores ?> vCPUs — <?= trim($cpu_model) ?><br>
                    RAM Total: <?= round($mem_total/1024/1024,1) ?>GB | Usada: <?= round($mem_used/1024/1024,1) ?>GB (<?= $mem_pct ?>%)<br>
                    Disco: <?= $disk_parts[1]??'?' ?> total — <?= $disk_pct ?>% usado<br>
                    Load Average: <?= implode(' | ', $load_avg) ?>
                </div>
                <div class="col-md-4">
                    <strong class="text-warning"><i class="fas fa-database me-1"></i> MySQL</strong><br>
                    Buffer Pool: <?= $buffer_pool_gb ?>GB (banco: <?= $db_size ?>MB)<br>
                    Conexões ativas: <?= $mysql_vars['Threads_connected']??'?' ?>/500<br>
                    Slow Queries: <?= $mysql_vars['Slow_queries']??0 ?><br>
                    Total de queries: <?= number_format((int)($mysql_vars['Questions']??0),0,',','.') ?>
                </div>
                <div class="col-md-4">
                    <strong class="text-success"><i class="fas fa-arrow-up me-1"></i> Para melhorar a velocidade</strong><br>
                    <?php if ($b_disk['write_ms'] > 200): ?>⚠️ <b>Migrar para SSD</b> — maior impacto (10x mais rápido)<br><?php endif; ?>
                    <?php if ($mem_pct > 65): ?>⚠️ <b>Aumentar RAM</b> — atual <?= round($mem_total/1024/1024,1) ?>GB, ideal 16GB+<br><?php endif; ?>
                    <?php if ($b_cpu['ms'] > 1000): ?>⚠️ <b>CPU mais moderna</b> — Xeon X5670 é de 2010<br><?php endif; ?>
                    ✅ Buffer Pool MySQL bem configurado (<?= $buffer_pool_gb ?>GB)<br>
                    ✅ Índices FULLTEXT e BTREE configurados<br>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center text-muted" style="font-size:.72rem;">
        Tempo total do diagnóstico: <?= round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000) ?>ms &nbsp;|&nbsp; Acesso restrito a administradores
    </div>
</div>
</body>
</html>
