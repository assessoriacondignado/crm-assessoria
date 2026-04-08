<?php
ignore_user_abort(true);
set_time_limit(0);
ini_set('display_errors', 0);
error_reporting(0);

require_once '../../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4");

define('URL_FATOR', 'https://fator.confere.link/api/');
define('DIR_JSON', __DIR__ . '/Json_confatorconferi/');

if (!is_dir(DIR_JSON)) { @mkdir(DIR_JSON, 0777, true); }

$lock_csv = sys_get_temp_dir() . '/fc_processando_csv_v2.lock';
$fp = @fopen($lock_csv, "w+");
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) { exit("Worker Fator Conferi já está rodando."); } 

function acordarWorkerFator() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $url_worker = $protocol . "://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_worker); curl_setopt($ch, CURLOPT_TIMEOUT, 1); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_exec($ch); curl_close($ch);
}

$start_time = time();
$target_runtime = 280;

while (true) {
    if(time() - $start_time > $target_runtime) { 
        flock($fp, LOCK_UN); fclose($fp); acordarWorkerFator(); exit; 
    }

    $stmtLote = $pdo->query("SELECT * FROM fatorconferi_banco_de_dados_retorno_importacao WHERE STATUS_FILA IN ('PENDENTE', 'PROCESSANDO') ORDER BY ID ASC LIMIT 1");
    $lote = $stmtLote->fetch(PDO::FETCH_ASSOC);

    if (!$lote) { flock($fp, LOCK_UN); fclose($fp); exit; }

    $id_lote = $lote['ID'];
    $cpf_cobrar = $lote['CPF_COBRANCA'];
    $custo_unitario = (float)$lote['CUSTO_UNITARIO'];
    $agrupamento = $lote['NOME_PROCESSAMENTO'];

    $token_csv = '';
    try {
        $stmtCfg = $pdo->query("SELECT TOKEN_CSV FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1");
        $token_csv = trim($stmtCfg->fetchColumn());
    } catch (Exception $e) {}

    if (empty($token_csv)) { 
        $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'ERRO CREDENCIAL' WHERE ID = ?")->execute([$id_lote]);
        flock($fp, LOCK_UN); fclose($fp); exit("Token CSV Vazio."); 
    }

    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'PROCESSANDO' WHERE ID = ?")->execute([$id_lote]);

    $stmtItem = $pdo->prepare("SELECT * FROM fatorconferi_banco_de_dados_retorno_importacao_itens WHERE LOTE_ID = ? AND STATUS_ITEM = 'NA FILA' ORDER BY ID ASC LIMIT 1");
    $stmtItem->execute([$id_lote]);
    $item = $stmtItem->fetch(PDO::FETCH_ASSOC);

    if ($item) {
        $cpf = $item['CPF'];
        $id_item = $item['ID'];
        
        $is_repetida = false;
        if (!empty($cpf_cobrar)) {
            $stmt24h = $pdo->prepare("SELECT COUNT(*) FROM fatorconferi_CLIENTE_FINANCEIRO_EXTRATO WHERE CPF_CLIENTE = ? AND MOTIVO LIKE ? AND DATA_HORA >= (NOW() - INTERVAL 24 HOUR)");
            $stmt24h->execute([$cpf_cobrar, "%CPF $cpf%"]);
            if ($stmt24h->fetchColumn() > 0) { $is_repetida = true; }
        }

        $usou_cache = false;
        $arquivos_cache = glob(DIR_JSON . $cpf . "_*.json");
        
        if (!empty($arquivos_cache)) {
            $json_salvo = @file_get_contents($arquivos_cache[0]);
            $array_completo = json_decode($json_salvo, true);
            $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : [];
            
            if (!empty($cad['NOME'])) {
                $usou_cache = true;
                $nome = getXmlString_Worker($cad['NOME']);
                
                $custo_real = ($is_repetida || empty($cpf_cobrar)) ? 0.00 : $custo_unitario;
                
                inserirDadosOficiais_Worker($cpf, $array_completo, $pdo, $agrupamento);
                registrarHistorico_Worker($pdo, $cpf, $nome, 'CACHE_LOTE_CSV', $custo_real, 'IMPORTACAO_MASSA');

                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao_itens SET STATUS_ITEM = 'OK', OBSERVACAO = 'Enriquecido com sucesso (Cache)' WHERE ID = ?")->execute([$id_item]);
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET QTD_ATUALIZADO = QTD_ATUALIZADO + 1 WHERE ID = ?")->execute([$id_lote]);

                if (!$is_repetida && !empty($cpf_cobrar) && $custo_unitario > 0) {
                    $pdo->beginTransaction();
                    $stmtCli = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ? FOR UPDATE");
                    $stmtCli->execute([$cpf_cobrar]);
                    if ($cli = $stmtCli->fetch(PDO::FETCH_ASSOC)) {
                        $saldo_anterior = (float)$cli['SALDO'];
                        $saldo_atual = $saldo_anterior - $custo_unitario;
                        $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atual, $cpf_cobrar]);
                        
                        $motivo = "Lote Fator Conferi ({$agrupamento}) - CPF {$cpf} (Cache)";
                        $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, GRUPO_WHATS, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, NULL, 'DEBITO', ?, ?, ?, ?)")->execute([$cpf_cobrar, $motivo, $custo_unitario, $saldo_anterior, $saldo_atual]);
                    }
                    $pdo->commit();
                }
            }
        }

        if (!$usou_cache) {
            $url = URL_FATOR . "?acao=CONS_CPF&TK=" . $token_csv . "&DADO=" . ltrim($cpf, '0');
            $xmlOriginalCRU = fetchAPI_Worker($url); 
            
            if ($xmlOriginalCRU && strpos($xmlOriginalCRU, 'CURL_ERROR:') === false) {
                $xmlString = mb_convert_encoding($xmlOriginalCRU, 'UTF-8', 'ISO-8859-1'); 
                if(strpos($xmlString, '<') !== false) { $xmlString = substr($xmlString, strpos($xmlString, '<')); } 
                libxml_use_internal_errors(true);
                $xmlObject = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA);

                if ($xmlObject && isset($xmlObject->CADASTRAIS->NOME)) {
                    $array_completo = xmlToArray_Worker($xmlObject);
                    $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : []; 
                    $nome = getXmlString_Worker($cad['NOME'] ?? '');

                    $json_final = json_encode($array_completo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    $nome_arquivo_json = DIR_JSON . "{$cpf}_" . strtolower(preg_replace('/[^A-Za-z0-9]/', '', str_replace(' ', '', $nome))) . ".json";
                    @file_put_contents($nome_arquivo_json, $json_final);

                    $custo_real = ($is_repetida || empty($cpf_cobrar)) ? 0.00 : $custo_unitario;

                    inserirDadosOficiais_Worker($cpf, $array_completo, $pdo, $agrupamento);
                    registrarHistorico_Worker($pdo, $cpf, $nome, 'API_LOTE_CSV', $custo_real, 'IMPORTACAO_MASSA');

                    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao_itens SET STATUS_ITEM = 'OK', OBSERVACAO = 'Enriquecido com sucesso' WHERE ID = ?")->execute([$id_item]);
                    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET QTD_ATUALIZADO = QTD_ATUALIZADO + 1 WHERE ID = ?")->execute([$id_lote]);

                    if (!$is_repetida && !empty($cpf_cobrar) && $custo_unitario > 0) {
                        $pdo->beginTransaction();
                        $stmtCli = $pdo->prepare("SELECT SALDO FROM CLIENTE_CADASTRO WHERE CPF = ? FOR UPDATE");
                        $stmtCli->execute([$cpf_cobrar]);
                        if ($cli = $stmtCli->fetch(PDO::FETCH_ASSOC)) {
                            $saldo_anterior = (float)$cli['SALDO'];
                            $saldo_atual = $saldo_anterior - $custo_unitario;
                            $pdo->prepare("UPDATE CLIENTE_CADASTRO SET SALDO = ? WHERE CPF = ?")->execute([$saldo_atual, $cpf_cobrar]);
                            
                            $motivo = "Lote Fator Conferi ({$agrupamento}) - CPF {$cpf}";
                            $pdo->prepare("INSERT INTO fatorconferi_CLIENTE_FINANCEIRO_EXTRATO (CPF_CLIENTE, GRUPO_WHATS, TIPO, MOTIVO, VALOR, SALDO_ANTERIOR, SALDO_ATUAL) VALUES (?, NULL, 'DEBITO', ?, ?, ?, ?)")->execute([$cpf_cobrar, $motivo, $custo_unitario, $saldo_anterior, $saldo_atual]);
                        }
                        $pdo->commit();
                    }
                } else {
                    $jsonDecode = json_decode($xmlOriginalCRU, true);
                    if (is_array($jsonDecode) && isset($jsonDecode['msg'])) {
                        $erroMsg = "API JSON: " . $jsonDecode['msg'];
                    } elseif (isset($xmlObject->MSG)) {
                        $erroMsg = "API XML: " . (string)$xmlObject->MSG;
                    } else {
                        $erroMsg = mb_substr(trim(strip_tags($xmlOriginalCRU)), 0, 150);
                        if(empty($erroMsg)) { $erroMsg = "API retornou vazio ou em branco."; }
                    }

                    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao_itens SET STATUS_ITEM = 'ERRO', OBSERVACAO = ? WHERE ID = ?")->execute([$erroMsg, $id_item]);
                    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET QTD_NAO_ATUALIZADO = QTD_NAO_ATUALIZADO + 1 WHERE ID = ?")->execute([$id_lote]);
                }
            } else {
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao_itens SET STATUS_ITEM = 'ERRO', OBSERVACAO = 'Falha de conexão (CURL_ERROR)' WHERE ID = ?")->execute([$id_item]);
                $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET QTD_NAO_ATUALIZADO = QTD_NAO_ATUALIZADO + 1 WHERE ID = ?")->execute([$id_lote]);
            }
        }
        
        usleep(500000); 
        continue; 
    }

    $pdo->prepare("UPDATE fatorconferi_banco_de_dados_retorno_importacao SET STATUS_FILA = 'CONCLUIDO' WHERE ID = ?")->execute([$id_lote]);
}

// ===============================================
// FUNÇÕES AUXILIARES 
// ===============================================
function inserirDadosOficiais_Worker($cpf, $array_completo, $pdo, $agrupamento = null) {
    $cad = (isset($array_completo['CADASTRAIS']) && is_array($array_completo['CADASTRAIS'])) ? $array_completo['CADASTRAIS'] : [];
    $nome = getXmlString_Worker($cad['NOME'] ?? ''); 
    $nascimento = formataDataBd_Worker(getXmlString_Worker($cad['NASCTO'] ?? '')); 
    $mae = substr(getXmlString_Worker($cad['NOME_MAE'] ?? ''), 0, 150); 
    $sexo = substr(getXmlString_Worker($cad['SEXO'] ?? ''), 0, 20); 
    $rg = substr(getXmlString_Worker($cad['RG'] ?? ''), 0, 20); 
    $profissao = substr(getXmlString_Worker($cad['PROFISSAO'] ?? ''), 0, 50);
    
    $pdo->beginTransaction();
    
    if ($agrupamento) { 
        $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg, carteira_profissional, agrupamento) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), rg=VALUES(rg), carteira_profissional=VALUES(carteira_profissional), agrupamento=VALUES(agrupamento)")->execute([$cpf, $nome, $sexo, $nascimento, $mae, $rg, $profissao, $agrupamento]); 
    } else { 
        $pdo->prepare("INSERT INTO dados_cadastrais (cpf, nome, sexo, nascimento, nome_mae, rg, carteira_profissional) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nome=VALUES(nome), sexo=VALUES(sexo), nascimento=VALUES(nascimento), nome_mae=VALUES(nome_mae), rg=VALUES(rg), carteira_profissional=VALUES(carteira_profissional)")->execute([$cpf, $nome, $sexo, $nascimento, $mae, $rg, $profissao]); 
    }
    
    $pdo->prepare("DELETE FROM enderecos WHERE cpf = ?")->execute([$cpf]); 
    if(isset($array_completo['ENDERECOS']['ENDERECO'])) { 
        $enderecos = $array_completo['ENDERECOS']['ENDERECO']; 
        if(isset($enderecos['LOGRADOURO']) || isset($enderecos['CIDADE'])) { $enderecos = [$enderecos]; } 
        $stmtEnd = $pdo->prepare("INSERT INTO enderecos (cpf, logradouro, numero, bairro, cidade, uf, cep) VALUES (?, ?, ?, ?, ?, ?, ?)"); 
        foreach($enderecos as $end) { 
            if(is_array($end)) { 
                $stmtEnd->execute([$cpf, substr(getXmlString_Worker($end['LOGRADOURO'] ?? ''), 0, 65000), substr(getXmlString_Worker($end['NUMERO'] ?? ''), 0, 20), substr(getXmlString_Worker($end['BAIRRO'] ?? ''), 0, 100), substr(getXmlString_Worker($end['CIDADE'] ?? ''), 0, 100), converterEstadoUF_Worker(getXmlString_Worker($end['ESTADO'] ?? '')), substr(preg_replace('/\D/', '', getXmlString_Worker($end['CEP'] ?? '')), 0, 8)]); 
            } 
        } 
    }
    
    $stmtTel = $pdo->prepare("INSERT IGNORE INTO telefones (cpf, telefone_cel) VALUES (?, ?)"); 
    if(isset($array_completo['TELEFONES_MOVEL']['TELEFONE'])) { 
        $telefones = $array_completo['TELEFONES_MOVEL']['TELEFONE']; 
        if(isset($telefones['NUMERO'])) { $telefones = [$telefones]; } 
        foreach($telefones as $tel) { 
            if(is_array($tel)) { 
                $numLimpo = preg_replace('/\D/', '', getXmlString_Worker($tel['NUMERO'] ?? '')); 
                if(strlen($numLimpo) == 13 && strpos($numLimpo, '55') === 0) { $numLimpo = substr($numLimpo, 2); } 
                if(strlen($numLimpo) == 11) { $stmtTel->execute([$cpf, $numLimpo]); } 
            } 
        } 
    }

    if(isset($array_completo['EMAILS']['EMAIL'])) {
        $lista_emails = $array_completo['EMAILS']['EMAIL'];
        if(is_string($lista_emails)) { $lista_emails = [$lista_emails]; }
        
        $stmtVerificaEmail = $pdo->prepare("SELECT id FROM emails WHERE cpf = ? AND email = ?");
        $stmtInsereEmail = $pdo->prepare("INSERT INTO emails (cpf, email) VALUES (?, ?)");
        
        foreach($lista_emails as $em) {
            $email_str = is_array($em) ? getXmlString_Worker($em['EMAIL'] ?? '') : getXmlString_Worker($em);
            $email_str = strtolower(trim($email_str));
            
            if(!empty($email_str) && strpos($email_str, '@') !== false) {
                $stmtVerificaEmail->execute([$cpf, $email_str]);
                if($stmtVerificaEmail->rowCount() == 0) {
                    $stmtInsereEmail->execute([$cpf, substr($email_str, 0, 150)]);
                }
            }
        }
    }

    $pdo->commit();
}
// CORREÇÃO AQUI: Recebe o parâmetro $custo e insere corretamente no banco
function registrarHistorico_Worker($pdo, $cpf, $nome, $origem, $custo, $fonte) { try { $pdo->prepare("INSERT INTO fatorconferi_banco_de_dados_retorno_historico (cpf, nome_cliente, data_consulta, origem_consulta, custo_consulta, fonte_consulta) VALUES (?, ?, NOW(), ?, ?, ?)")->execute([$cpf, $nome, $origem, $custo, $fonte]); } catch (PDOException $e) {} }
function fetchAPI_Worker($url) { $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_TIMEOUT, 30); $response = curl_exec($ch); $err = curl_error($ch); curl_close($ch); return $err ? "CURL_ERROR: " . $err : $response; }
function xmlToArray_Worker($xmlObject) { $out = array(); $arrayObj = (array) $xmlObject; if (empty($arrayObj)) return ''; foreach ( $arrayObj as $index => $node ) { $out[$index] = ( is_object ( $node ) || is_array ( $node ) ) ? xmlToArray_Worker ( $node ) : $node; } return $out; }
function getXmlString_Worker($node) { if (!isset($node) || is_array($node) || is_object($node)) return ''; return trim((string)$node); }
function formataDataBd_Worker($dataStr) { if(empty(trim($dataStr))) return null; $p = explode('/', $dataStr); return (count($p) == 3) ? $p[2].'-'.$p[1].'-'.$p[0] : null; }
function converterEstadoUF_Worker($estado) { if(is_array($estado)) return 'SP'; $estado = strtoupper(trim(preg_replace('/[^a-zA-Z\s]/', '', $estado))); if (strlen($estado) == 2) return $estado; $mapa = ['ACRE'=>'AC','ALAGOAS'=>'AL','AMAPA'=>'AP','AMAZONAS'=>'AM','BAHIA'=>'BA','CEARA'=>'CE','DISTRITO FEDERAL'=>'DF','ESPIRITO SANTO'=>'ES','GOIAS'=>'GO','MARANHAO'=>'MA','MATO GROSSO'=>'MT','MATO GROSSO DO SUL'=>'MS','MINAS GERAIS'=>'MG','PARA'=>'PA','PARAIBA'=>'PB','PARANA'=>'PR','PERNAMBUCO'=>'PE','PIAUI'=>'PI','RIO DE JANEIRO'=>'RJ','RIO GRANDE DO NORTE'=>'RN','RIO GRANDE DO SUL'=>'RS','RONDONIA'=>'RO','RORAIMA'=>'RR','SANTA CATARINA'=>'SC','SAO PAULO'=>'SP','SERGIPE'=>'SE','TOCANTINS'=>'TO']; return $mapa[$estado] ?? 'SP'; }
?>