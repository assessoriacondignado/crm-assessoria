<?php
include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

// Define que o arquivo será salvo na MESMA pasta deste script (configuracao)
$caminho_arquivo = __DIR__ . '/estrutura_do_banco.txt';

$gerado_com_sucesso = false;
$erro_permissao = false;
$conteudo_tela = "";

// Se o usuário clicou no botão para gerar o relatório
if (isset($_POST['gerar_relatorio'])) {
    
    $conteudo = "====================================================\n";
    $conteudo .= "   RELATÓRIO DE ESTRUTURA DO BANCO DE DADOS\n";
    $conteudo .= "   Sistema: MEU CRM | Banco: crm_dados\n";
    $conteudo .= "   Gerado em: " . date('d/m/Y H:i:s') . "\n";
    $conteudo .= "====================================================\n\n";

    try {
        $stmt = $pdo->query("SHOW TABLES");
        $tabelas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tabelas as $tabela) {
            $conteudo .= "TABELA: " . strtoupper($tabela) . "\n";
            $conteudo .= str_repeat("-", 50) . "\n";

            $stmtCol = $pdo->query("SHOW COLUMNS FROM $tabela");
            $colunas = $stmtCol->fetchAll(PDO::FETCH_ASSOC);

            $conteudo .= "[COLUNAS]\n";
            foreach ($colunas as $col) {
                $chave = !empty($col['Key']) ? " [Chave: {$col['Key']}]" : "";
                $nulo = ($col['Null'] == 'YES') ? "Pode ser Vazio" : "OBRIGATÓRIO";
                $conteudo .= "  • {$col['Field']} | Tipo: {$col['Type']} | {$nulo}{$chave}\n";
            }
            $conteudo .= "\n";

            $stmtCreate = $pdo->query("SHOW CREATE TABLE $tabela");
            $create = $stmtCreate->fetch(PDO::FETCH_ASSOC);
            $conteudo .= "[CÓDIGO SQL DE CRIAÇÃO]\n";
            $conteudo .= $create['Create Table'] . ";\n\n";
            $conteudo .= str_repeat("=", 50) . "\n\n";
        }

        // Tenta criar/sobrescrever o arquivo .txt na pasta configuracao
        if (@file_put_contents($caminho_arquivo, $conteudo) !== false) {
            $gerado_com_sucesso = true;
            $conteudo_tela = $conteudo; // Guarda o texto para mostrar na tela
        } else {
            $erro_permissao = true;
        }

    } catch (PDOException $e) {
        $erro = $e->getMessage();
    }
}
?>

<div class="row justify-content-center mt-5 mb-5">
    <div class="col-md-10 text-center">
        <h2 class="text-primary">⚙️ Configurações do Banco de Dados</h2>
        <p class="text-muted mb-4">Área técnica para manutenção e documentação do sistema.</p>

        <div class="card shadow-sm border-secondary">
            <div class="card-body py-4">
                <h4 class="card-title mb-3">📄 Dicionário de Dados</h4>
                <p class="card-text mb-4">
                    Ao clicar no botão, o sistema atualizará o arquivo <b>estrutura_do_banco.txt</b> dentro do módulo de configuração e exibirá o resultado abaixo.
                </p>

                <form method="POST">
                    <button type="submit" name="gerar_relatorio" class="btn btn-dark btn-lg">
                        <i class="bi bi-file-earmark-text"></i> Executar Leitura do Banco
                    </button>
                </form>

                <?php if ($erro_permissao): ?>
                    <div class="alert alert-danger mt-4 text-start shadow-sm">
                        ❌ <b>Erro de Permissão (Linux)</b><br>
                        Para resolver, abra o terminal do VS Code e rode este comando:<br>
                        <code>chmod 777 /var/www/html/modulos/configuracao</code>
                    </div>
                <?php endif; ?>

                <?php if (isset($erro)): ?>
                    <div class="alert alert-danger mt-4">Erro no Banco: <?= $erro ?></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($gerado_com_sucesso): ?>
            <div class="alert alert-success mt-4 fs-5 shadow-sm">
                ✅ <b>Arquivo atualizado com sucesso!</b>
            </div>
            
            <div class="card mt-4 shadow-sm text-start">
                <div class="card-header bg-dark text-white fw-bold">
                    Visualização do Relatório Gerado
                </div>
                <div class="card-body bg-light">
                    <pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 14px;"><?= htmlspecialchars($conteudo_tela) ?></pre>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>