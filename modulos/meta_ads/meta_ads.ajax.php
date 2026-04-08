<?php
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; } 

$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$acao = $_POST['acao'] ?? '';

try {
    $stmtCfg = $pdo->query("SELECT * FROM METAADS_CONFIG WHERE ID = 1");
    $config = $stmtCfg->fetch(PDO::FETCH_ASSOC);
    $metaToken = $config['META_ACCESS_TOKEN'] ?? '';

    switch ($acao) {
        case 'carregar_config': echo json_encode(['success' => true, 'data' => $config]); break;
        case 'salvar_config': $token = $_POST['meta_token'] ?? ''; $pdo->prepare("UPDATE METAADS_CONFIG SET META_ACCESS_TOKEN = ? WHERE ID = 1")->execute([$token]); echo json_encode(['success' => true, 'msg' => 'Configurações salvas com sucesso!']); break;
        case 'listar_clientes': $stmt = $pdo->query("SELECT CPF, NOME, GRUPO_WHATS FROM CLIENTE_CADASTRO ORDER BY NOME ASC"); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_vinculo': $cpf = preg_replace('/\D/', '', $_POST['cpf_cliente']); $ad_id = trim($_POST['ad_id']); $ad_acc_id = str_replace('act_', '', trim($_POST['ad_account_id'] ?? '')); $pdo->prepare("INSERT INTO METAADS_VINCULOS (CPF_CLIENTE, AD_ID, AD_ACCOUNT_ID, STATUS_VINCULO) VALUES (?, ?, ?, 'ATIVO')")->execute([$cpf, $ad_id, $ad_acc_id]); echo json_encode(['success' => true, 'msg' => 'Anúncio salvo! Sincronize os dados em seguida.']); break;
        case 'mudar_status': $pdo->prepare("UPDATE METAADS_VINCULOS SET STATUS_VINCULO = ? WHERE ID = ?")->execute([$_POST['status'], $_POST['id']]); echo json_encode(['success' => true]); break;
        case 'excluir_vinculo': $pdo->prepare("DELETE FROM METAADS_VINCULOS WHERE ID = ?")->execute([$_POST['id']]); echo json_encode(['success' => true, 'msg' => 'Anúncio removido do sistema!']); break;
        case 'editar_vinculo': $cpf = preg_replace('/\D/', '', $_POST['cpf_cliente']); $ad_acc_id = str_replace('act_', '', trim($_POST['ad_account_id'] ?? '')); $pdo->prepare("UPDATE METAADS_VINCULOS SET CPF_CLIENTE = ?, AD_ID = ?, AD_ACCOUNT_ID = ? WHERE ID = ?")->execute([$cpf, trim($_POST['ad_id']), $ad_acc_id, $_POST['id']]); echo json_encode(['success' => true, 'msg' => 'Anúncio editado! Sincronize para atualizar os nomes.']); break;
        case 'salvar_descricao': $pdo->prepare("UPDATE METAADS_VINCULOS SET DESCRICAO = ? WHERE ID = ?")->execute([$_POST['descricao'], $_POST['id']]); echo json_encode(['success' => true, 'msg' => 'Descrição atualizada com sucesso!']); break;

        case 'listar_templates': $stmt = $pdo->query("SELECT *, DATE_FORMAT(DATA_DA_ATUALIZACAO, '%d/%m/%Y %H:%i') as DATA_BR FROM METAADS_MENSAGEM_PADRAO ORDER BY ID DESC"); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_template': $id = $_POST['id'] ?? ''; if(empty($id)) { $pdo->prepare("INSERT INTO METAADS_MENSAGEM_PADRAO (NOME_DA_MENSAGEM, LOCAL_VINCULO, FUNCAO, CONTEUDO_DA_MENSAGEM) VALUES (?, ?, ?, ?)")->execute([$_POST['nome'], $_POST['local'], $_POST['funcao'], $_POST['conteudo']]); } else { $pdo->prepare("UPDATE METAADS_MENSAGEM_PADRAO SET NOME_DA_MENSAGEM=?, LOCAL_VINCULO=?, FUNCAO=?, CONTEUDO_DA_MENSAGEM=? WHERE ID=?")->execute([$_POST['nome'], $_POST['local'], $_POST['funcao'], $_POST['conteudo'], $id]); } echo json_encode(['success' => true, 'msg' => 'Modelo de mensagem salvo com sucesso!']); break;
        case 'excluir_template': $pdo->prepare("DELETE FROM METAADS_MENSAGEM_PADRAO WHERE ID = ?")->execute([$_POST['id']]); echo json_encode(['success' => true, 'msg' => 'Modelo de mensagem excluído!']); break;

        case 'listar_agendamentos': $stmt = $pdo->query("SELECT a.*, t.NOME_DA_MENSAGEM as NOME_TEMPLATE FROM METAADS_AGENDAMENTOS a LEFT JOIN METAADS_MENSAGEM_PADRAO t ON a.TEMPLATE_ID = t.ID ORDER BY a.ID ASC"); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;
        case 'salvar_agendamento': $id = $_POST['id'] ?? ''; $tplId = $_POST['template_id']; $horarios = str_replace(' ', '', $_POST['horarios']); $dias = $_POST['dias']; if(empty($id)) { $pdo->prepare("INSERT INTO METAADS_AGENDAMENTOS (TEMPLATE_ID, DIAS_SEMANA, HORARIOS, STATUS_AGENDAMENTO) VALUES (?, ?, ?, 'ATIVO')")->execute([$tplId, $dias, $horarios]); } else { $pdo->prepare("UPDATE METAADS_AGENDAMENTOS SET TEMPLATE_ID=?, DIAS_SEMANA=?, HORARIOS=? WHERE ID=?")->execute([$tplId, $dias, $horarios, $id]); } echo json_encode(['success' => true, 'msg' => 'Agendamento salvo com sucesso! O Robô já sabe o que fazer.']); break;
        case 'mudar_status_agendamento': $pdo->prepare("UPDATE METAADS_AGENDAMENTOS SET STATUS_AGENDAMENTO = ? WHERE ID = ?")->execute([$_POST['status'], $_POST['id']]); echo json_encode(['success' => true]); break;
        case 'excluir_agendamento': $pdo->prepare("DELETE FROM METAADS_AGENDAMENTOS WHERE ID = ?")->execute([$_POST['id']]); echo json_encode(['success' => true]); break;

        case 'carregar_registros_diarios': $vId = $_POST['vinculo_id']; $stmt = $pdo->prepare("SELECT *, MENSAGEM_ENVIADA AS MSG_ENVIADA, DATE_FORMAT(DATA_REGISTRO, '%d/%m/%Y %H:%i') as DATA_REG_BR FROM METAADS_REGISTRO_ATIVIDADES WHERE VINCULO_ID = ? ORDER BY DATA_REGISTRO DESC"); $stmt->execute([$vId]); echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); break;

        case 'salvar_registro_diario':
            $vId = $_POST['vinculo_id']; $st = $_POST['status']; $obs = $_POST['observacao'] ?? ''; $whats = $_POST['enviar_whats'] ?? 0; $template_id = $_POST['template_id'] ?? ''; $destino = $_POST['destino'] ?? 'grupo';
            $saldo_disponivel_tela = $_POST['saldo_disponivel'] ?? 'R$ 0,00';
            
            $pdo->prepare("INSERT INTO METAADS_REGISTRO_ATIVIDADES (VINCULO_ID, STATUS_DIARIO, OBSERVACAO, MENSAGEM_ENVIADA) VALUES (?, ?, ?, ?)")->execute([$vId, $st, $obs, $whats]);
            
            if ($whats == 1 && !empty($template_id)) {
                $stmtTpl = $pdo->prepare("SELECT CONTEUDO_DA_MENSAGEM FROM METAADS_MENSAGEM_PADRAO WHERE ID = ?"); $stmtTpl->execute([$template_id]); $tpl = $stmtTpl->fetchColumn();
                if ($tpl) {
                    $stmtV = $pdo->prepare("SELECT m.*, c.NOME as NOME_CLIENTE, c.GRUPO_WHATS, c.CELULAR FROM METAADS_VINCULOS m LEFT JOIN CLIENTE_CADASTRO c ON m.CPF_CLIENTE = c.CPF WHERE m.ID = ?"); $stmtV->execute([$vId]); $vInfo = $stmtV->fetch(PDO::FETCH_ASSOC);
                    if ($vInfo) {
                        $numero_alvo = ($destino === 'privado') ? $vInfo['CELULAR'] : $vInfo['GRUPO_WHATS'];
                        if (!empty($numero_alvo) && $numero_alvo != 'Não Vinculado') {
                            $msgFinal = str_replace(
                                ['{NOME_CLIENTE}', '{NOME_ANUNCIO}', '{CONTA_ANUNCIO}', '{STATUS_DIARIO}', '{OBSERVACAO_DIA}', '{SALDO_DISPONIVEL}'],
                                [$vInfo['NOME_CLIENTE'], $vInfo['NOME_ANUNCIO'], $vInfo['NOME_CONTA_ANUNCIO'], $st, $obs, $saldo_disponivel_tela],
                                $tpl
                            );

                            $stmtWapi = $pdo->query("SELECT WAPI_INSTANCE, WAPI_TOKEN FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); $wapiCfg = $stmtWapi->fetch(PDO::FETCH_ASSOC);
                            if(!empty($wapiCfg['WAPI_INSTANCE']) && !empty($wapiCfg['WAPI_TOKEN'])) {
                                $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapiCfg['WAPI_INSTANCE'];
                                $id_final_wapi = preg_replace('/[^0-9]/', '', $numero_alvo); if ($destino === 'grupo') { $id_final_wapi .= '@g.us'; } else { if(!str_starts_with($id_final_wapi, '55')) { $id_final_wapi = '55' . $id_final_wapi; } }
                                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_wapi); curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([ "phone" => $id_final_wapi, "message" => $msgFinal ])); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $wapiCfg['WAPI_TOKEN']]); curl_exec($ch); curl_close($ch);
                            }
                        }
                    }
                }
            }
            echo json_encode(['success' => true, 'msg' => 'Registro do dia lançado com sucesso!']);
            break;

        case 'sincronizar_linha':
            if (empty($metaToken)) throw new Exception("Token da Meta não configurado.");
            $id = $_POST['id']; $stmt = $pdo->prepare("SELECT ID, AD_ID, AD_ACCOUNT_ID FROM METAADS_VINCULOS WHERE ID = ?"); $stmt->execute([$id]); $v = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($v) {
                $urlAd = "https://graph.facebook.com/v19.0/{$v['AD_ID']}?fields=name,account_id&access_token={$metaToken}";
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $urlAd); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $resAd = json_decode(curl_exec($ch), true); curl_close($ch);
                $acc_id_to_use = $resAd['account_id'] ?? $v['AD_ACCOUNT_ID'];
                if (!empty($acc_id_to_use)) {
                    $urlAcc = "https://graph.facebook.com/v19.0/act_{$acc_id_to_use}?fields=name&access_token={$metaToken}"; $ch2 = curl_init(); curl_setopt($ch2, CURLOPT_URL, $urlAcc); curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false); $resAcc = json_decode(curl_exec($ch2), true); curl_close($ch2);
                    $nomeAnuncio = $resAd['name'] ?? 'Desconhecido';
                    $pdo->prepare("UPDATE METAADS_VINCULOS SET NOME_ANUNCIO = ?, AD_ACCOUNT_ID = ?, NOME_CONTA_ANUNCIO = ? WHERE ID = ?")->execute([$nomeAnuncio, $acc_id_to_use, ($resAcc['name'] ?? 'Desconhecido'), $id]); echo json_encode(['success' => true, 'msg' => "Linha sincronizada com sucesso!"]);
                } else { echo json_encode(['success' => false, 'msg' => "Erro na Meta. Adicione o ID da Conta manualmente."]); }
            }
            break;

        case 'enviar_aviso_wapi':
            $grupo_id = trim($_POST['grupo_whats'] ?? ''); if(empty($grupo_id) || $grupo_id == 'Não Vinculado') { throw new Exception("Cliente não possui Grupo."); }
            
            // 🟢 MODIFICAÇÃO AQUI: Agora aceita o ID do Template via POST (Para o Disparo Global)
            $template_id = $_POST['template_id'] ?? '';
            
            if (!empty($template_id)) {
                $stmtTpl = $pdo->prepare("SELECT CONTEUDO_DA_MENSAGEM FROM METAADS_MENSAGEM_PADRAO WHERE ID = ?");
                $stmtTpl->execute([$template_id]);
                $tpl = $stmtTpl->fetchColumn();
            } else {
                $stmtTpl = $pdo->query("SELECT CONTEUDO_DA_MENSAGEM FROM METAADS_MENSAGEM_PADRAO WHERE LOCAL_VINCULO = 'relatorio_grupo' ORDER BY ID DESC LIMIT 1"); 
                $tpl = $stmtTpl->fetchColumn();
            }

            if ($tpl) {
                $custoFormatado = 'R$ ' . number_format($_POST['custo_conversa'] ?? 0, 2, ',', '.'); $gastoFormatado = 'R$ ' . number_format($_POST['valor_usado'] ?? 0, 2, ',', '.');
                $msg = str_replace(
                    ['{NOME_CLIENTE}', '{NOME_ANUNCIO}', '{CONTA_ANUNCIO}', '{STATUS_CONTA}', '{STATUS_WHATS}', '{DATA_REFERENCIA}', '{ALCANCE}', '{IMPRESSOES}', '{FREQUENCIA}', '{CLIQUES}', '{CTR}', '{CONVERSAS}', '{CUSTO_CONVERSA}', '{GASTO_PERIODO}', '{SALDO_DISPONIVEL}'],
                    [$_POST['nome_cliente'], $_POST['ad_nome'], $_POST['conta_nome'], $_POST['conta_status'], $_POST['whats_status'], $_POST['data_referencia'], $_POST['alcance'], $_POST['impressoes'], $_POST['frequencia'], $_POST['cliques'], $_POST['ctr'], $_POST['conversas'], $custoFormatado, $gastoFormatado, $_POST['saldo_disponivel']],
                    $tpl
                );
            } else {
                $dataRef = $_POST['data_referencia'] ?? 'Últimas 24h';
                $msg = "📊 *Relatório de Desempenho Meta Ads*\n📅 *Data Referência:* " . $dataRef . "\n\n";
                $msg .= "👤 *Cliente:* " . $_POST['nome_cliente'] . "\n📢 *Anúncio:* " . $_POST['ad_nome'] . "\n🏢 *Conta:* " . $_POST['conta_nome'] . "\n";
                $msg .= "🚨 *Status da Conta:* " . ($_POST['conta_status'] ?? '⚪ Desconhecido') . "\n";
                $msg .= "📱 *Status do WhatsApp:* " . ($_POST['whats_status'] ?? '⚪ Desconhecido') . "\n";
                $msg .= "💸 *Saldo Disponível:* " . ($_POST['saldo_disponivel'] ?? 'R$ 0,00') . "\n";
                $msg .= "----------------------------\n";
                $msg .= "👀 *Pessoas Alcançadas:* " . $_POST['alcance'] . "\n";
                $msg .= "💸 *Gasto no Período:* R$ " . number_format($_POST['valor_usado'], 2, ',', '.') . "\n";
            }

            $stmtWapi = $pdo->query("SELECT WAPI_INSTANCE, WAPI_TOKEN FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1"); $wapiCfg = $stmtWapi->fetch(PDO::FETCH_ASSOC);
            if(empty($wapiCfg['WAPI_INSTANCE']) || empty($wapiCfg['WAPI_TOKEN'])) { throw new Exception("Credenciais da W-API não configuradas."); }

            $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapiCfg['WAPI_INSTANCE']; $grupo_id = preg_replace('/[^0-9]/', '', $grupo_id) . '@g.us';
            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_wapi); curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([ "phone" => $grupo_id, "message" => $msg ])); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $wapiCfg['WAPI_TOKEN']]); curl_exec($ch); curl_close($ch);
            echo json_encode(['success' => true, 'msg' => "Relatório enviado com sucesso para o WhatsApp!"]);
            break;

        case 'sincronizar_dados_meta':
            if (empty($metaToken)) throw new Exception("Token da Meta não configurado.");
            $stmt = $pdo->query("SELECT ID, AD_ID, AD_ACCOUNT_ID FROM METAADS_VINCULOS"); $vinculos = $stmt->fetchAll(PDO::FETCH_ASSOC); $atualizados = 0;
            foreach ($vinculos as $v) {
                $urlAd = "https://graph.facebook.com/v19.0/{$v['AD_ID']}?fields=name,account_id&access_token={$metaToken}"; $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $urlAd); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); $resAd = json_decode(curl_exec($ch), true); curl_close($ch);
                $acc_id_to_use = $resAd['account_id'] ?? $v['AD_ACCOUNT_ID'];
                if (!empty($acc_id_to_use)) {
                    $urlAcc = "https://graph.facebook.com/v19.0/act_{$acc_id_to_use}?fields=name&access_token={$metaToken}"; $ch2 = curl_init(); curl_setopt($ch2, CURLOPT_URL, $urlAcc); curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false); $resAcc = json_decode(curl_exec($ch2), true); curl_close($ch2);
                    $nomeAnuncio = $resAd['name'] ?? 'Desconhecido';
                    $pdo->prepare("UPDATE METAADS_VINCULOS SET NOME_ANUNCIO = ?, AD_ACCOUNT_ID = ?, NOME_CONTA_ANUNCIO = ? WHERE ID = ?")->execute([$nomeAnuncio, $acc_id_to_use, ($resAcc['name'] ?? 'Desconhecido'), $v['ID']]); $atualizados++;
                }
            }
            echo json_encode(['success' => true, 'msg' => "Sincronização global concluída ($atualizados)!"]); break;

        case 'carregar_relatorio':
            $filtro = $_POST['filtro_data'] ?? 'hoje'; $status_filtro = $_POST['filtro_status'] ?? 'ATIVO'; 

            $sqlVinculos = "SELECT m.*, c.NOME as NOME_CLIENTE, c.GRUPO_WHATS FROM METAADS_VINCULOS m LEFT JOIN CLIENTE_CADASTRO c ON m.CPF_CLIENTE = c.CPF WHERE 1=1";
            if ($status_filtro !== 'TODOS') { $sqlVinculos .= " AND m.STATUS_VINCULO = '{$status_filtro}'"; }
            $vinculos = $pdo->query($sqlVinculos)->fetchAll(PDO::FETCH_ASSOC); $relatorio_final = [];
            
            $ch = curl_init(); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            foreach ($vinculos as $v) {
                $metricas = [ 'veiculacao' => 'Desconhecido', 'gasto_total' => 0.00, 'alcance' => 0, 'impressoes' => 0, 'frequencia' => 0, 'valor_usado' => 0.00, 'conversas' => 0, 'custo_conversa' => 0.00, 'ctr' => '0.00%', 'cliques' => 0, 'conta_status_badge' => '', 'whats_meta_badge' => '', 'conta_status_texto' => '⚪ Desconhecido', 'whats_meta_texto' => '⚪ Desconhecido', 'saldo_disponivel' => 'R$ 0,00', 'saldo_badge' => '' ];

                if (!empty($metaToken) && !empty($v['AD_ID']) && $v['STATUS_VINCULO'] == 'ATIVO') {
                    
                    // 1. STATUS E SALDO DISPONÍVEL
                    curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/{$v['AD_ID']}?fields=effective_status,account_id&access_token={$metaToken}");
                    $res_status = json_decode(curl_exec($ch), true);
                    $st_raw = $res_status['effective_status'] ?? 'UNKNOWN';
                    $acc_id = $res_status['account_id'] ?? $v['AD_ACCOUNT_ID'] ?? '';
                    
                    if (!empty($acc_id)) {
                        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/act_{$acc_id}?fields=account_status,balance,spend_cap,amount_spent&access_token={$metaToken}");
                        $res_acc = json_decode(curl_exec($ch), true);
                        
                        $acc_status = $res_acc['account_status'] ?? 1; 
                        $mapa_acc = [ 1 => '<span class="badge bg-success shadow-sm mt-1" style="font-size:0.65rem;">🟢 Conta Ativa</span>', 2 => '<span class="badge bg-danger shadow-sm mt-1" style="font-size:0.65rem;">🔴 Desabilitada (Bloqueio)</span>', 3 => '<span class="badge bg-warning text-dark shadow-sm mt-1" style="font-size:0.65rem;">💳 Erro de Pagamento</span>', 101 => '<span class="badge bg-dark shadow-sm mt-1" style="font-size:0.65rem;">⚫ Conta Fechada</span>', 201 => '<span class="badge bg-success shadow-sm mt-1" style="font-size:0.65rem;">🟢 Ativa</span>' ];
                        $mapa_acc_texto = [ 1 => '🟢 Conta Ativa', 2 => '🔴 Desabilitada (Bloqueio)', 3 => '💳 Erro de Pagamento', 101 => '⚫ Conta Fechada', 201 => '🟢 Ativa' ];
                        $metricas['conta_status_badge'] = $mapa_acc[$acc_status] ?? '<span class="badge bg-secondary shadow-sm mt-1" style="font-size:0.65rem;">🟡 Em Análise</span>';
                        $metricas['conta_status_texto'] = $mapa_acc_texto[$acc_status] ?? '🟡 Em Análise';
                        if ($acc_status == 3 && $st_raw === 'ACTIVE') { $st_raw = 'PENDING_BILLING_INFO'; } elseif ($acc_status == 2 && $st_raw === 'ACTIVE') { $st_raw = 'ACCOUNT_DISABLED'; } 
                        
                        $balance = $res_acc['balance'] ?? 0;
                        $spend_cap = $res_acc['spend_cap'] ?? 0;
                        $amount_spent = $res_acc['amount_spent'] ?? 0;

                        if ($balance != 0) {
                            $saldo_centavos = abs($balance);
                        } else {
                            $saldo_centavos = max(0, $spend_cap - $amount_spent);
                        }
                        
                        $saldo_real = $saldo_centavos / 100;
                        $metricas['saldo_disponivel'] = 'R$ ' . number_format($saldo_real, 2, ',', '.');
                        
                        $cor_saldo = ($saldo_real > 10) ? 'success' : (($saldo_real > 0) ? 'warning text-dark' : 'danger');
                        $title_debug = "Bal: {$balance} | Cap: {$spend_cap} | Spent: {$amount_spent}"; 
                        
                        $metricas['saldo_badge'] = '<span class="badge bg-'.$cor_saldo.' shadow-sm mt-1 ms-1" style="font-size:0.65rem;" title="Saldo Disponível ('.$title_debug.')"><i class="fas fa-wallet"></i> ' . $metricas['saldo_disponivel'] . '</span>';
                    }
                    
                    $mapa_status = [ 'ACTIVE' => '🟢 Ativo', 'PAUSED' => '⚪ Pausado', 'PENDING_REVIEW' => '🟡 Em análise', 'DISAPPROVED' => '🔴 Rejeitado', 'PREAPPROVED' => '🔵 Pré-aprovado', 'CAMPAIGN_PAUSED' => '⚪ Campanha pausada', 'ADSET_PAUSED' => '⚪ Conjunto pausado', 'IN_PROCESS' => '🔄 Em processamento', 'WITH_ISSUES' => '🟠 Com problemas', 'DELETED' => '⚫ Excluído', 'ARCHIVED' => '🗄️ Arquivado', 'PENDING_BILLING_INFO' => '💳 Erro no pagamento', 'ACCOUNT_DISABLED' => '🚫 Conta Desativada' ];
                    $metricas['veiculacao'] = $mapa_status[$st_raw] ?? 'ℹ️ ' . $st_raw;

                    // 2. WHATSAPP BUSINESS
                    curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/{$v['AD_ID']}?fields=adcreatives{object_story_spec}&access_token={$metaToken}");
                    $res_creative = json_decode(curl_exec($ch), true);
                    $page_id = $res_creative['adcreatives']['data'][0]['object_story_spec']['page_id'] ?? null;

                    if ($page_id) {
                        curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/{$page_id}?fields=whatsapp_number,whatsapp_business_account{account_review_status}&access_token={$metaToken}");
                        $res_page = json_decode(curl_exec($ch), true);
                        $wa_numero = $res_page['whatsapp_number'] ?? '';
                        
                        if (!empty($wa_numero)) {
                            $waba_status_raw = $res_page['whatsapp_business_account']['account_review_status'] ?? 'UNKNOWN';
                            $mapa_waba = [ 'APPROVED' => '<span class="badge bg-success shadow-sm mt-1" style="font-size:0.65rem;"><i class="fab fa-whatsapp"></i> ' . $wa_numero . ' (Ativo)</span>', 'REJECTED' => '<span class="badge bg-danger shadow-sm mt-1" style="font-size:0.65rem;"><i class="fab fa-whatsapp"></i> ' . $wa_numero . ' (Banido)</span>', 'PENDING'  => '<span class="badge bg-warning text-dark shadow-sm mt-1" style="font-size:0.65rem;"><i class="fab fa-whatsapp"></i> ' . $wa_numero . ' (Análise)</span>', 'UNKNOWN'  => '<span class="badge bg-secondary shadow-sm mt-1" style="font-size:0.65rem;"><i class="fab fa-whatsapp"></i> ' . $wa_numero . ' (Oculto)</span>' ];
                            $mapa_waba_texto = [ 'APPROVED' => '🟢 ' . $wa_numero . ' (Ativo)', 'REJECTED' => '🔴 ' . $wa_numero . ' (Banido)', 'PENDING'  => '🟡 ' . $wa_numero . ' (Análise)', 'UNKNOWN'  => '⚪ ' . $wa_numero . ' (Oculto)' ];

                            $metricas['whats_meta_badge'] = $mapa_waba[$waba_status_raw] ?? $mapa_waba['UNKNOWN'];
                            $metricas['whats_meta_texto'] = $mapa_waba_texto[$waba_status_raw] ?? $mapa_waba_texto['UNKNOWN'];
                        } else {
                            $metricas['whats_meta_badge'] = '<span class="badge bg-dark shadow-sm mt-1" style="font-size:0.65rem;"><i class="fab fa-whatsapp"></i> Sem Whats no Anúncio / Oculto</span>';
                            $metricas['whats_meta_texto'] = '⚪ Sem Whats no Anúncio / Oculto';
                        }
                    }

                    // 3. INSIGHTS E GASTOS
                    curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/{$v['AD_ID']}/insights?fields=spend&date_preset=maximum&access_token={$metaToken}");
                    $res_total = json_decode(curl_exec($ch), true);
                    if (isset($res_total['data'][0])) { $metricas['gasto_total'] = $res_total['data'][0]['spend'] ?? 0.00; }

                    $date_param = "&date_preset=today";
                    if ($filtro === 'mes') $date_param = "&date_preset=this_month";
                    elseif ($filtro === 'maximum') $date_param = "&date_preset=maximum"; 
                    elseif (strpos($filtro, '|') !== false) {
                        $datas = explode('|', $filtro);
                        $date_param = "&time_range={'since':'{$datas[0]}','until':'{$datas[1]}'}";
                    }

                    curl_setopt($ch, CURLOPT_URL, "https://graph.facebook.com/v19.0/{$v['AD_ID']}/insights?fields=reach,impressions,frequency,spend,actions,clicks,ctr{$date_param}&access_token={$metaToken}");
                    $meta_data = json_decode(curl_exec($ch), true);

                    if (isset($meta_data['error'])) {
                        $metricas['alcance'] = 'ERRO API'; 
                        $metricas['impressoes'] = $meta_data['error']['message'] ?? 'Erro desconhecido na Meta';
                    } elseif (isset($meta_data['data'][0])) {
                        $d = $meta_data['data'][0];
                        $metricas['alcance'] = $d['reach'] ?? 0; $metricas['impressoes'] = $d['impressions'] ?? 0;
                        $metricas['frequencia'] = round($d['frequency'] ?? 0, 2); $metricas['valor_usado'] = $d['spend'] ?? 0.00;
                        $metricas['ctr'] = round($d['ctr'] ?? 0, 2) . '%'; $metricas['cliques'] = $d['clicks'] ?? 0;
                        $conversas = 0;
                        if (isset($d['actions'])) { foreach ($d['actions'] as $action) { if (strpos($action['action_type'], 'messaging_conversation') !== false || strpos($action['action_type'], 'lead') !== false) { $conversas += $action['value']; } } }
                        $metricas['conversas'] = $conversas;
                        if ($conversas > 0) $metricas['custo_conversa'] = $metricas['valor_usado'] / $conversas;
                    }
                }
                $v['metricas'] = $metricas;
                $relatorio_final[] = $v;
            }
            curl_close($ch);
            
            echo json_encode(['success' => true, 'data' => $relatorio_final]);
            break;

        default:
            echo json_encode(['success' => false, 'msg' => 'Ação não encontrada.']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
}
?>