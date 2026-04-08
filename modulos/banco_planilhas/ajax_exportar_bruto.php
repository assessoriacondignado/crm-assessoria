<?php
ini_set('max_execution_time', 0);
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
$stmt = $pdo->prepare("SELECT arquivo_caminho, nome_planilha FROM BANCO_DE_PLANILHA_REGISTRO WHERE id=?");
$stmt->execute([$_GET['id']]);
$f = $stmt->fetch();
if($f && file_exists($f['arquivo_caminho'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="BRUTO_'.$f['nome_planilha'].'.csv"');
    readfile($f['arquivo_caminho']);
}
?>