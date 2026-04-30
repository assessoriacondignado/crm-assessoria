<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

if (empty($_SESSION['usuario_cpf'])) { echo json_encode(['success' => false]); exit; }

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

$cpf = $_SESSION['usuario_cpf'];
$limites = [];

try {
    // ── HIST INSS (PROMOSYS) ─────────────────────────────────
    $st = $pdo->prepare("
        SELECT cc.SALDO, cc.CUSTO_CONSULTA
        FROM CLIENTE_CADASTRO cc
        WHERE cc.CPF = ?
        LIMIT 1
    ");
    $st->execute([$cpf]);
    $inss = $st->fetch(PDO::FETCH_ASSOC);
    if ($inss && $inss['CUSTO_CONSULTA'] > 0) {
        $qtd = (int) floor((float)$inss['SALDO'] / (float)$inss['CUSTO_CONSULTA']);
        $limites[] = ['nome' => 'HIST INSS', 'qtd' => $qtd, 'unidade' => 'consultas'];
    }

    // ── FATOR CONFERI ────────────────────────────────────────
    $st2 = $pdo->prepare("
        SELECT e.SALDO_ATUAL, cc.CUSTO_CONSULTA
        FROM fatorconferi_CLIENTE_FINANCEIRO_EXTRATO e
        JOIN CLIENTE_CADASTRO cc ON cc.CPF = e.CPF_CLIENTE
        WHERE e.CPF_CLIENTE = ?
        ORDER BY e.ID DESC LIMIT 1
    ");
    $st2->execute([$cpf]);
    $fc = $st2->fetch(PDO::FETCH_ASSOC);
    if ($fc && $fc['CUSTO_CONSULTA'] > 0) {
        $qtd2 = (int) floor((float)$fc['SALDO_ATUAL'] / (float)$fc['CUSTO_CONSULTA']);
        $limites[] = ['nome' => 'Fator Conferi', 'qtd' => $qtd2, 'unidade' => 'consultas'];
    }

    // ── ROBÔ V8 CLT ──────────────────────────────────────────
    $st3 = $pdo->prepare("
        SELECT SALDO, CUSTO_CONSULTA
        FROM INTEGRACAO_V8_CHAVE_ACESSO
        WHERE CPF_USUARIO = ? AND STATUS = 'ATIVO'
        ORDER BY ID ASC LIMIT 1
    ");
    $st3->execute([$cpf]);
    $v8 = $st3->fetch(PDO::FETCH_ASSOC);
    if ($v8 && $v8['CUSTO_CONSULTA'] > 0) {
        $qtd3 = (int) floor((float)$v8['SALDO'] / (float)$v8['CUSTO_CONSULTA']);
        $limites[] = ['nome' => 'Robô V8 CLT', 'qtd' => $qtd3, 'unidade' => 'consultas'];
    }

} catch (Exception $e) {}

echo json_encode(['success' => true, 'limites' => $limites]);
