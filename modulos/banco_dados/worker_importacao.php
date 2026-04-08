<?php
// TRAVA DE SEGURANÇA
if (php_sapi_name() !== 'cli') { die("Acesso restrito à linha de comando."); }

ignore_user_abort(true); 
set_time_limit(0);       
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');
error_reporting(0);

$raiz_sistema = realpath(dirname(__FILE__) . '/../../'); 
include $raiz_sistema . '/conexao.php';

$taskId = $argv[1] ?? null;
if (!$taskId) die("Sem Task ID\n");

register_shutdown_function(function() use ($pdo, $taskId) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        $msgFatal = "Erro Fatal: " . $error['message'] . " na linha " . $error['line'];
        if (isset($pdo)) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS='ERRO_CRITICO', ARQUIVO_LOG_ERRO=? WHERE ID=?")
                ->execute([$msgFatal, $taskId]);
        }
    }
});

try {
    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS = 'PROCESSANDO' WHERE ID = ?")->execute([$taskId]);

    $stmt = $pdo->prepare("SELECT * FROM CONTROLE_IMPORTACAO_ASSINCRONA WHERE ID = ?");
    $stmt->execute([$taskId]);
    $tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

    $arquivo = $tarefa['ARQUIVO'];
    $tabela = $tarefa['TABELA_DESTINO'];
    $nome_importacao = $tarefa['NOME_IMPORTACAO'];
    $nome_usuario = $tarefa['NOME_USUARIO']; 

    $caminho_arquivo = $raiz_sistema . '/modulos/banco_dados/Aquivo_importacao/' . $arquivo;
    $caminho_mapa = $caminho_arquivo . '_map.json';

    $arquivos_csv_para_processar = [];
    if (is_dir($caminho_arquivo)) {
        $arquivos_csv_para_processar = glob($caminho_arquivo . '/*.csv');
    } else {
        $arquivos_csv_para_processar[] = $caminho_arquivo;
    }

    if (empty($arquivos_csv_para_processar)) { throw new Exception("Nenhum arquivo CSV encontrado."); }
    if (!file_exists($caminho_mapa)) { throw new Exception("Mapeamento não encontrado."); }

    $mapeamento = json_decode(file_get_contents($caminho_mapa), true);
    if (!is_array($mapeamento)) { throw new Exception("JSON inválido."); }

    $pasta_erros = $raiz_sistema . '/modulos/banco_dados/Arquivo_erros_importacao/';
    if (!is_dir($pasta_erros)) { @mkdir($pasta_erros, 0777, true); }

    $data_hoje = date('d-m-Y_H-i-s');
    $nome_txt = "importacao_erros_{$data_hoje}.txt";
    $caminho_txt = $pasta_erros . $nome_txt;
    
    // Abre em modo 'a' (append) para não apagar os erros anteriores se for uma retomada de pausa
    $txt_handle = @fopen($caminho_txt, 'a');
    
    // Só escreve o cabeçalho se o arquivo estiver vazio (primeira rodada)
    if (filesize($caminho_txt) == 0) {
        fwrite($txt_handle, "========================================\nRELATÓRIO DE ERROS - INSS E GERAL\nLote: $nome_importacao\n========================================\n\n");
    }

    $total_linhas = 0;
    foreach ($arquivos_csv_para_processar as $arq) {
        $handle = @fopen($arq, "r");
        while(fgetcsv($handle, 10000, ";") !== FALSE) { $total_linhas++; }
        fclose($handle);
        $total_linhas = max(0, $total_linhas - 1);
    }
    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET QTD_TOTAL = ? WHERE ID = ?")->execute([$total_linhas, $taskId]);

    // QUERIES FIXAS
    $stmtCheckHist = $pdo->prepare("SELECT id FROM BANCO_DE_DADOS_HISTORICO_IMPORTACAO WHERE cpf = ? AND nome_importacao = ?");
    $stmtInsertHist = $pdo->prepare("INSERT INTO BANCO_DE_DADOS_HISTORICO_IMPORTACAO (cpf, nome_importacao) VALUES (?, ?)");
    $stmtCheckCPF = $pdo->prepare("SELECT cpf FROM dados_cadastrais WHERE cpf = ?");
    $stmtInsertFakeCPF = $pdo->prepare("INSERT INTO dados_cadastrais (cpf) VALUES (?)");
    $stmtCheckTel = $pdo->prepare("SELECT id FROM telefones WHERE cpf = ? AND telefone_cel = ?");
    $stmtInsertTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)");
    $stmtCheckEnd = $pdo->prepare("SELECT id FROM enderecos WHERE cpf = ? AND logradouro = ? AND numero = ?");
    $stmtInsertEnd = $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, numero, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtCheckEmail = $pdo->prepare("SELECT id FROM emails WHERE cpf = ? AND email = ?");
    $stmtInsertEmail = $pdo->prepare("INSERT INTO emails (cpf, email) VALUES (?, ?)");
    $stmtCheckConvenio = $pdo->prepare("SELECT ID FROM BANCO_DADOS_CONVENIO WHERE CPF = ? AND CONVENIO = ? AND (MATRICULA = ? OR (MATRICULA IS NULL AND ? IS NULL))");
    $stmtInsertConvenio = $pdo->prepare("INSERT INTO BANCO_DADOS_CONVENIO (CPF, CONVENIO, MATRICULA) VALUES (?, ?, ?)");
    $stmtInsertCampanhaCli = $pdo->prepare("INSERT IGNORE INTO BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA (CPF_CLIENTE, ID_CAMPANHA) VALUES (?, ?)");

    // CARREGA DADOS ANTIGOS (RETOMADA INTELIGENTE)
    $novos = $tarefa['QTD_NOVOS'] ?? 0; 
    $atualizados = $tarefa['QTD_ATUALIZADOS'] ?? 0; 
    $erros = $tarefa['QTD_ERROS'] ?? 0; 
    $processados = $tarefa['QTD_PROCESSADA'] ?? 0;
    
    $linhas_a_pular = $processados; 
    $contador_leitura = 0; 

    // REDUZIDO PARA 500: Salva no banco em lotes menores
    $update_interval = ($total_linhas < 100) ? 10 : 500;
    $matriculas_limpas = []; 

    $pdo->beginTransaction();

    foreach ($arquivos_csv_para_processar as $arquivo_atual) {
        $handle = fopen($arquivo_atual, "r");
        $cabecalhosCSV = fgetcsv($handle, 10000, ";");

        while (($linha = fgetcsv($handle, 10000, ";")) !== FALSE) {
            $contador_leitura++;
            
            // FAST FORWARD: Pula as linhas já processadas
            if ($contador_leitura <= $linhas_a_pular) { continue; }
            
            $processados++;
            
            $cpf_raw = isset($linha[$mapeamento['cpf']]) ? $linha[$mapeamento['cpf']] : '';
            $cpf = preg_replace('/[^0-9]/', '', $cpf_raw);
            if (strlen($cpf) > 0 && strlen($cpf) < 11) $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);
            
            if (strlen($cpf) !== 11) { 
                $erros++; 
                fwrite($txt_handle, "LINHA $processados | MOTIVO: CPF Inválido ($cpf_raw).\n");
                continue; 
            }

            try {
                if (!empty($nome_importacao)) {
                    $stmtCheckHist->execute([$cpf, $nome_importacao]);
                    if (!$stmtCheckHist->fetch()) { $stmtInsertHist->execute([$cpf, $nome_importacao]); }
                }

                if ($tabela == 'banco_de_Dados_inss_dados_cadastrais' || $tabela == 'banco_de_Dados_inss_contratos') {
                    $stmtCheckCPF->execute([$cpf]);
                    if(!$stmtCheckCPF->fetch()) { $stmtInsertFakeCPF->execute([$cpf]); }
                    
                    $mat_idx = $mapeamento['matricula_nb'] ?? null;
                    $matricula = $mat_idx !== null ? trim($linha[$mat_idx] ?? '') : '';
                    
                    if (empty($matricula)) {
                         $erros++;
                         fwrite($txt_handle, "LINHA $processados | CPF: $cpf | MOTIVO: Matrícula ausente/não mapeada.\n");
                         continue;
                    }
                    
                    if ($tabela == 'banco_de_Dados_inss_contratos') {
                         $contrato_idx = $mapeamento['contrato'] ?? null;
                         $contrato = $contrato_idx !== null ? trim($linha[$contrato_idx] ?? '') : '';
                         
                         if (empty($contrato)) {
                             $erros++;
                             fwrite($txt_handle, "LINHA $processados | CPF: $cpf | MOTIVO: Nº de Contrato ausente/não mapeado.\n");
                             continue;
                         }
                         
                         $apagar = $mapeamento['param_apagar_contratos'] ?? 'NAO';
                         if ($apagar === 'SIM') {
                             $chave_cache = $cpf . '_' . $matricula;
                             if (!isset($matriculas_limpas[$chave_cache])) {
                                 $pdo->prepare("DELETE FROM banco_de_Dados_inss_contratos WHERE cpf = ? AND matricula_nb = ?")->execute([$cpf, $matricula]);
                                 $matriculas_limpas[$chave_cache] = true;
                             }
                         }
                    }
                    
                    $campos_banco = []; $valores_banco = []; $campos_update = [];
                    foreach ($mapeamento as $colBanco => $idxCsv) {
                        if (in_array($colBanco, ['importacao', 'id_campanha_vinculo', 'param_apagar_contratos'])) continue;
                        
                        if ($colBanco == 'cpf') { $valor = $cpf; } 
                        else {
                            $valor = isset($linha[$idxCsv]) ? trim($linha[$idxCsv]) : '';
                            $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8, ISO-8859-1');
                            if (strtolower($valor) === 'limpar_dados' || $valor === '') $valor = null;
                            if ($valor !== null && strpos($colBanco, 'valor') !== false || strpos($colBanco, 'margem') !== false || strpos($colBanco, 'taxa') !== false || strpos($colBanco, 'saldo') !== false) {
                                $valor = str_replace(['R$', ' ', '.'], '', $valor);
                                $valor = str_replace(',', '.', $valor);
                            }
                        }
                        $campos_banco[] = "`$colBanco`"; $valores_banco[] = $valor; $campos_update[] = "`$colBanco` = VALUES(`$colBanco`)";
                    }
                    
                    if(count($campos_banco) > 0) {
                        $sqlINSS = "INSERT INTO `$tabela` (" . implode(",", $campos_banco) . ") VALUES (" . implode(",", array_fill(0, count($valores_banco), "?")) . ") ON DUPLICATE KEY UPDATE " . implode(",", $campos_update);
                        $pdo->prepare($sqlINSS)->execute($valores_banco);
                        $atualizados++; 
                    }
                }

                elseif ($tabela === 'IMPORTACAO_COMPLETA_CADASTRO') {
                    $stmtCheckCPF->execute([$cpf]);
                    $existe = $stmtCheckCPF->fetch();

                    $nome = mb_convert_encoding($linha[$mapeamento['nome']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $sexo_raw = strtoupper(trim($linha[$mapeamento['sexo']] ?? ''));
                    $sexo = null;
                    if($sexo_raw === 'F' || $sexo_raw === 'FEMININO') $sexo = 'FEMININO';
                    elseif($sexo_raw === 'M' || $sexo_raw === 'MASCULINO') $sexo = 'MASCULINO';
                    elseif($sexo_raw !== '' && $sexo_raw !== 'LIMPAR_DADOS') $sexo = 'OUTROS';

                    $nasc_raw = $linha[$mapeamento['nascimento']] ?? '';
                    $nasc = null;
                    if(!empty($nasc_raw)) {
                        $p = explode('/', $nasc_raw);
                        if(count($p) == 3) $nasc = $p[2].'-'.$p[1].'-'.$p[0];
                    }

                    $mae = mb_convert_encoding($linha[$mapeamento['nome_mae']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $pai = mb_convert_encoding($linha[$mapeamento['nome_pai']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $rg = mb_convert_encoding($linha[$mapeamento['rg']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $cnh = mb_convert_encoding($linha[$mapeamento['cnh']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $cart = mb_convert_encoding($linha[$mapeamento['carteira_profissional']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $agrup = mb_convert_encoding($linha[$mapeamento['agrupamento']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');

                    $sqlCom = "INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, nome_pai, rg, cnh, carteira_profissional, agrupamento) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE 
                               nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), 
                               nome_pai=VALUES(nome_pai), rg=VALUES(rg), cnh=VALUES(cnh), carteira_profissional=VALUES(carteira_profissional), agrupamento=VALUES(agrupamento)";
                    $pdo->prepare($sqlCom)->execute([$cpf, $nome ?: null, $sexo, $nasc, $mae ?: null, $pai ?: null, $rg ?: null, $cnh ?: null, $cart ?: null, $agrup ?: null]);

                    for ($i=1; $i<=10; $i++) {
                        if (isset($mapeamento['tel_'.$i])) {
                            $tel_limpo = preg_replace('/[^0-9]/', '', $linha[$mapeamento['tel_'.$i]] ?? '');
                            if (strlen($tel_limpo) == 13 && strpos($tel_limpo, '55') === 0) { $tel_limpo = substr($tel_limpo, 2); }
                            if (strlen($tel_limpo) === 11) {
                                $stmtCheckTel->execute([$cpf, $tel_limpo]);
                                if (!$stmtCheckTel->fetch()) { $stmtInsertTel->execute([$cpf, $tel_limpo]); }
                            }
                        }
                    }

                    $cep = preg_replace('/[^0-9]/', '', $linha[$mapeamento['end_cep']] ?? '');
                    $logradouro = mb_convert_encoding($linha[$mapeamento['end_logradouro']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $numero = mb_convert_encoding($linha[$mapeamento['end_numero']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $bairro = mb_convert_encoding($linha[$mapeamento['end_bairro']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $cidade = mb_convert_encoding($linha[$mapeamento['end_cidade']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $uf = substr(strtoupper(preg_replace('/[^a-zA-Z]/', '', mb_convert_encoding($linha[$mapeamento['end_uf']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1'))), 0, 2);

                    if (!empty($logradouro) || !empty($cep)) {
                        $stmtCheckEnd->execute([$cpf, $logradouro, $numero]);
                        if (!$stmtCheckEnd->fetch()) { $stmtInsertEnd->execute([$cpf, $logradouro, $numero, $bairro, $cidade, $uf, $cep]); }
                    }

                    for ($i=1; $i<=5; $i++) {
                        if (isset($mapeamento['email_'.$i])) {
                            $email_bruto = strtolower(trim($linha[$mapeamento['email_'.$i]] ?? ''));
                            if (!empty($email_bruto) && strpos($email_bruto, '@') !== false) {
                                $stmtCheckEmail->execute([$cpf, $email_bruto]);
                                if (!$stmtCheckEmail->fetch()) { $stmtInsertEmail->execute([$cpf, $email_bruto]); }
                            }
                        }
                    }

                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($mapeamento['convenio_'.$i])) {
                            $conv_nome = strtoupper(trim(mb_convert_encoding($linha[$mapeamento['convenio_'.$i]] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1')));
                            if (!empty($conv_nome)) {
                                $mat_num = isset($mapeamento['matricula_'.$i]) ? strtoupper(trim($linha[$mapeamento['matricula_'.$i]] ?? '')) : '';
                                $mat_bd = ($mat_num === '') ? null : $mat_num; 
                                $stmtCheckConvenio->execute([$cpf, $conv_nome, $mat_bd, $mat_bd]);
                                if (!$stmtCheckConvenio->fetch()) {
                                    $stmtInsertConvenio->execute([$cpf, $conv_nome, $mat_bd]);
                                }
                            }
                        }
                    }

                    if ($existe) $atualizados++; else $novos++;
                }
                
                elseif ($tabela == 'dados_cadastrais') {
                    $stmtCheckCPF->execute([$cpf]);
                    $existe = $stmtCheckCPF->fetch();
                    $campos_banco = []; $valores_banco = []; $campos_update = [];

                    foreach ($mapeamento as $colBanco => $idxCsv) {
                        if (in_array($colBanco, ['importacao', 'id_campanha_vinculo', 'param_apagar_contratos'])) continue; 
                        if ($colBanco == 'cpf') { $valor = $cpf; } 
                        else {
                            $valor = isset($linha[$idxCsv]) ? trim($linha[$idxCsv]) : '';
                            $valor = mb_convert_encoding($valor, 'UTF-8', 'UTF-8, ISO-8859-1');
                            if ($colBanco == 'sexo') {
                                $valor_upper = strtoupper($valor);
                                if ($valor_upper === 'LIMPAR_DADOS' || $valor_upper === '') $valor = null;
                                elseif ($valor_upper === 'F' || $valor_upper === 'FEMININO') $valor = 'FEMININO';
                                elseif ($valor_upper === 'M' || $valor_upper === 'MASCULINO') $valor = 'MASCULINO';
                                else $valor = 'OUTROS';
                            } else {
                                if (strtolower($valor) === 'limpar_dados' || $valor === '') $valor = null;
                                if ($colBanco == 'nascimento' && !empty($valor)) {
                                    $p = explode('/', $valor);
                                    if (count($p) == 3) $valor = $p[2] . '-' . $p[1] . '-' . $p[0];
                                }
                            }
                        }
                        $campos_banco[] = $colBanco; $valores_banco[] = $valor; $campos_update[] = "$colBanco = VALUES($colBanco)";
                    }

                    if(count($campos_banco) > 0) {
                        $sql = "INSERT INTO dados_cadastrais (" . implode(",", $campos_banco) . ") VALUES (" . implode(",", array_fill(0, count($valores_banco), "?")) . ") ON DUPLICATE KEY UPDATE " . implode(",", $campos_update);
                        $pdo->prepare($sql)->execute($valores_banco);
                    } else {
                        $stmtInsertFakeCPF->execute([$cpf]);
                    }
                    if ($existe) $atualizados++; else $novos++;
                }
                
                elseif ($tabela == 'telefones') {
                    $telefone_inserido = false; 
                    $stmtCheckCPF->execute([$cpf]);
                    if(!$stmtCheckCPF->fetch()) { $stmtInsertFakeCPF->execute([$cpf]); }
                    
                    for ($i = 1; $i <= 10; $i++) {
                        if (isset($mapeamento['telefone_cel_' . $i])) {
                            $tel_limpo = preg_replace('/[^0-9]/', '', $linha[$mapeamento['telefone_cel_' . $i]] ?? '');
                            if (strlen($tel_limpo) == 13 && strpos($tel_limpo, '55') === 0) { $tel_limpo = substr($tel_limpo, 2); }
                            if (strlen($tel_limpo) == 11) {
                                $stmtCheckTel->execute([$cpf, $tel_limpo]);
                                if (!$stmtCheckTel->fetch()) {
                                    $stmtInsertTel->execute([$cpf, $tel_limpo]);
                                    $telefone_inserido = true;
                                }
                            }
                        }
                    }
                    if ($telefone_inserido) { $novos++; } else { $atualizados++; }
                }

                elseif ($tabela == 'emails') {
                    $email_inserido = false; 
                    $stmtCheckCPF->execute([$cpf]);
                    if(!$stmtCheckCPF->fetch()) { $stmtInsertFakeCPF->execute([$cpf]); }
                    
                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($mapeamento['email_' . $i])) {
                            $email_limpo = strtolower(trim($linha[$mapeamento['email_' . $i]] ?? ''));
                            if (!empty($email_limpo) && strpos($email_limpo, '@') !== false) {
                                $stmtCheckEmail->execute([$cpf, $email_limpo]);
                                if (!$stmtCheckEmail->fetch()) {
                                    $stmtInsertEmail->execute([$cpf, $email_limpo]);
                                    $email_inserido = true;
                                }
                            }
                        }
                    }
                    if ($email_inserido) { $novos++; } else { $atualizados++; }
                }

                elseif ($tabela == 'enderecos') {
                    $stmtCheckCPF->execute([$cpf]);
                    if(!$stmtCheckCPF->fetch()) { $stmtInsertFakeCPF->execute([$cpf]); }
                    
                    $logradouro = mb_convert_encoding($linha[$mapeamento['logradouro']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $numero = mb_convert_encoding($linha[$mapeamento['numero']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $bairro = mb_convert_encoding($linha[$mapeamento['bairro']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $cidade = mb_convert_encoding($linha[$mapeamento['cidade']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1');
                    $uf = substr(strtoupper(preg_replace('/[^a-zA-Z]/', '', mb_convert_encoding($linha[$mapeamento['uf']] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1'))), 0, 2);
                    $cep = preg_replace('/[^0-9]/', '', $linha[$mapeamento['cep']] ?? ''); 
                    
                    if (!empty($logradouro) || !empty($cep)) {
                        $stmtCheckEnd->execute([$cpf, $logradouro, $numero]);
                        if (!$stmtCheckEnd->fetch()) {
                            $stmtInsertEnd->execute([$cpf, $logradouro, $numero, $bairro, $cidade, $uf, $cep]);
                            $novos++;
                        } else { $atualizados++; }
                    }
                }
                
                elseif ($tabela == 'convenios') {
                    $conv_inserido = false;
                    $stmtCheckCPF->execute([$cpf]);
                    if(!$stmtCheckCPF->fetch()) { $stmtInsertFakeCPF->execute([$cpf]); }
                    
                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($mapeamento['convenio_'.$i])) {
                            $conv_nome = strtoupper(trim(mb_convert_encoding($linha[$mapeamento['convenio_'.$i]] ?? '', 'UTF-8', 'UTF-8, ISO-8859-1')));
                            if (!empty($conv_nome)) {
                                $mat_num = isset($mapeamento['matricula_'.$i]) ? strtoupper(trim($linha[$mapeamento['matricula_'.$i]] ?? '')) : '';
                                $mat_bd = ($mat_num === '') ? null : $mat_num;

                                $stmtCheckConvenio->execute([$cpf, $conv_nome, $mat_bd, $mat_bd]);
                                if (!$stmtCheckConvenio->fetch()) {
                                    $stmtInsertConvenio->execute([$cpf, $conv_nome, $mat_bd]);
                                    $conv_inserido = true;
                                }
                            }
                        }
                    }
                    if ($conv_inserido) { $novos++; } else { $atualizados++; }
                }

                if (!empty($mapeamento['id_campanha_vinculo'])) {
                    $stmtInsertCampanhaCli->execute([$cpf, $mapeamento['id_campanha_vinculo']]);
                }

            } catch (Exception $e) {
                $erros++;
                fwrite($txt_handle, "FALHA SQL | CPF: $cpf | ERRO: " . $e->getMessage() . "\n");
            }

            if ($processados % $update_interval == 0) {
                $pdo->commit();
                $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET QTD_PROCESSADA=?, QTD_NOVOS=?, QTD_ATUALIZADOS=?, QTD_ERROS=? WHERE ID=?")
                    ->execute([$processados, $novos, $atualizados, $erros, $taskId]);
                
                // VERIFICA SE O USUÁRIO APERTOU PAUSE OU EXCLUIR
                $stmtCheckStatus = $pdo->prepare("SELECT STATUS FROM CONTROLE_IMPORTACAO_ASSINCRONA WHERE ID = ?");
                $stmtCheckStatus->execute([$taskId]);
                $statusAgora = $stmtCheckStatus->fetchColumn();
                
                if ($statusAgora === 'PAUSADA' || $statusAgora === 'CANCELADA') {
                    exit; // Morre educadamente e libera memória.
                }

                // RESPIRA SERVIDOR! 0.5 Segundos
                usleep(500000); 

                $pdo->beginTransaction();
            }
        }
        fclose($handle); 
    }

    $pdo->commit();
    fclose($txt_handle);

    $arquivo_log_erro = null;
    if ($erros > 0) { $arquivo_log_erro = $nome_txt; } else { @unlink($caminho_txt); }

    $stmtLog = $pdo->prepare("INSERT INTO base_de_dados_registro_importacao (nome_usuario, nome_importacao, qtd_novos, qtd_atualizados, qtd_erros) VALUES (?, ?, ?, ?, ?)");
    $stmtLog->execute([$nome_usuario, $nome_importacao, $novos, $atualizados, $erros]);

    $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS='CONCLUIDA', QTD_PROCESSADA=?, QTD_NOVOS=?, QTD_ATUALIZADOS=?, QTD_ERROS=?, ARQUIVO_LOG_ERRO=?, DATA_FIM=NOW() WHERE ID=?")
        ->execute([$processados, $novos, $atualizados, $erros, $arquivo_log_erro, $taskId]);

    // Apaga apenas o mapa velho, mas MANTÉM a planilha no servidor para reutilização!
    @unlink($caminho_mapa);

} catch (\Throwable $e) {
    $msgErroDetalhada = $e->getMessage() . " | Linha: " . $e->getLine();
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    if (isset($pdo)) {
        $pdo->prepare("UPDATE CONTROLE_IMPORTACAO_ASSINCRONA SET STATUS='ERRO_CRITICO', ARQUIVO_LOG_ERRO=? WHERE ID=?")
            ->execute([$msgErroDetalhada, $taskId]);
    }
}
exit;
?>