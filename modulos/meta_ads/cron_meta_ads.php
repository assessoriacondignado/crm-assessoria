<?php
ini_set('display_errors', 1); error_reporting(E_ALL);

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../../conexao.php';
if(!isset($pdo) && isset($conn)) { $pdo = $conn; }

function limparCacheLocal() {
    $arquivos = glob(__DIR__ . '/cache_relatorio_*.json');
    if ($arquivos) { foreach ($arquivos as $arq) { @unlink($arq); } }
}

try {
    $stmtCfg = $pdo->query("SELECT META_ACCESS_TOKEN FROM METAADS_CONFIG WHERE ID = 1");
    $config = $stmtCfg->fetch(PDO::FETCH_ASSOC);
    if (!$config || empty($config['META_ACCESS_TOKEN'])) die("Token da Meta nao configurado.\n");

    $stmtWapi = $pdo->query("SELECT WAPI_INSTANCE, WAPI_TOKEN FROM WAPI_CONFIG_BOT_FATOR WHERE ID = 1");
    $wapiCfg = $stmtWapi->fetch(PDO::FETCH_ASSOC);
    if(empty($wapiCfg['WAPI_INSTANCE']) || empty($wapiCfg['WAPI_TOKEN'])) die("Credenciais W-API vazias.\n");

    $dia_semana_atual = date('w'); 
    $horario_atual = date('H:i');

    $stmtAgendamentos = $pdo->query("
        SELECT a.*, t.CONTEUDO_DA_MENSAGEM 
        FROM METAADS_AGENDAMENTOS a 
        INNER JOIN METAADS_MENSAGEM_PADRAO t ON a.TEMPLATE_ID = t.ID 
        WHERE a.STATUS_AGENDAMENTO = 'ATIVO'
    ");
    $agendamentos = $stmtAgendamentos->fetchAll(PDO::FETCH_ASSOC);
    if (count($agendamentos) === 0) die("Nenhum agendamento ativo encontrado no banco.\n");

    $agendamentos_para_rodar = [];
    foreach ($agendamentos as $agen) {
        $dias_permitidos = array_map('trim', explode(',', $agen['DIAS_SEMANA']));
        $horarios_permitidos = array_map('trim', explode(',', $agen['HORARIOS']));

        if (in_array($dia_semana_atual, $dias_permitidos) && in_array($horario_atual, $horarios_permitidos)) {
            $agendamentos_para_rodar[] = $agen;
        }
    }

    if (count($agendamentos_para_rodar) === 0) die("Nenhum agendamento programado para hoje ($dia_semana_atual) as $horario_atual.\n");

    $stmtVinculos = $pdo->query("SELECT m.*, c.NOME as NOME_CLIENTE, c.GRUPO_WHATS FROM METAADS_VINCULOS m LEFT JOIN CLIENTE_CADASTRO c ON m.CPF_CLIENTE = c.CPF WHERE m.STATUS_VINCULO = 'ATIVO'");
    $vinculos = $stmtVinculos->fetchAll(PDO::FETCH_ASSOC);
    if (count($vinculos) === 0) die("Nenhum anuncio ativo para gerar relatorio.\n");

    limparCacheLocal();
    $dataRef = date('d/m/Y') . ' (Hoje)';
    
    foreach ($agendamentos_para_rodar as $tarefa) {
        echo "-> INICIANDO TAREFA ID: {$tarefa['ID']} | Template: {$tarefa['TEMPLATE_ID']} | Hora: {$horario_atual}\n";
        $template = $tarefa['CONTEUDO_DA_MENSAGEM'];

        foreach ($vinculos as $v) {
            $grupo_id = $v['GRUPO_WHATS'];
            if(empty($grupo_id) || $grupo_id == 'Não Vinculado') continue;

            $alcance = 0; $impressoes = 0; $frequencia = 0; $valor_usado = 0.00;
            $conversas = 0; $custo_conversa = 0.00; $ctr = '0.00%'; $cliques = 0;
            $status_conta_texto = "⚪ Desconhecido";
            $status_whats_texto = "⚪ Sem Whats no Anúncio / Oculto";
            $saldo_disponivel_texto = "R$ 0,00";

            if (!empty($v['AD_ID'])) {
                if (!empty($v['AD_ACCOUNT_ID'])) {
                    // CÁLCULO DE SALDO DISPONÍVEL PARA CONTA PRÉ-PAGA
                    $urlAccStatus = "https://graph.facebook.com/v19.0/act_{$v['AD_ACCOUNT_ID']}?fields=account_status,spend_cap,amount_spent&access_token=" . $config['META_ACCESS_TOKEN'];
                    $chStatus = curl_init(); curl_setopt($chStatus, CURLOPT_URL, $urlAccStatus); curl_setopt($chStatus, CURLOPT_RETURNTRANSFER, true); curl_setopt($chStatus, CURLOPT_SSL_VERIFYPEER, false);
                    $resAccStatus = json_decode(curl_exec($chStatus), true); curl_close($chStatus);
                    
                    $acc_status_code = $resAccStatus['account_status'] ?? 1;
                    $mapa_acc_whats = [ 1 => '🟢 Conta Ativa', 2 => '🔴 Desabilitada (Bloqueio)', 3 => '💳 Erro de Pagamento', 101 => '⚫ Conta Fechada', 201 => '🟢 Ativa' ];
                    $status_conta_texto = $mapa_acc_whats[$acc_status_code] ?? '🟡 Em Análise';
                    
                    $spend_cap = $resAccStatus['spend_cap'] ?? 0;
                    $amount_spent = $resAccStatus['amount_spent'] ?? 0;
                    $saldo_centavos = max(0, $spend_cap - $amount_spent);
                    $saldo_disponivel_texto = 'R$ ' . number_format($saldo_centavos / 100, 2, ',', '.');
                }

                $urlCreative = "https://graph.facebook.com/v19.0/{$v['AD_ID']}?fields=adcreatives{object_story_spec}&access_token=" . $config['META_ACCESS_TOKEN'];
                $chCreat = curl_init(); curl_setopt($chCreat, CURLOPT_URL, $urlCreative); curl_setopt($chCreat, CURLOPT_RETURNTRANSFER, true); curl_setopt($chCreat, CURLOPT_SSL_VERIFYPEER, false);
                $res_creative = json_decode(curl_exec($chCreat), true); curl_close($chCreat);
                $page_id = $res_creative['adcreatives']['data'][0]['object_story_spec']['page_id'] ?? null;

                if ($page_id) {
                    $urlPage = "https://graph.facebook.com/v19.0/{$page_id}?fields=whatsapp_number,whatsapp_business_account{account_review_status}&access_token=" . $config['META_ACCESS_TOKEN'];
                    $chPage = curl_init(); curl_setopt($chPage, CURLOPT_URL, $urlPage); curl_setopt($chPage, CURLOPT_RETURNTRANSFER, true); curl_setopt($chPage, CURLOPT_SSL_VERIFYPEER, false);
                    $res_page = json_decode(curl_exec($chPage), true); curl_close($chPage);
                    $wa_numero = $res_page['whatsapp_number'] ?? '';
                    if (!empty($wa_numero)) {
                        $waba_status_raw = $res_page['whatsapp_business_account']['account_review_status'] ?? 'UNKNOWN';
                        $mapa_waba_whats = [ 'APPROVED' => '🟢 ' . $wa_numero . ' (Ativo)', 'REJECTED' => '🔴 ' . $wa_numero . ' (Banido)', 'PENDING'  => '🟡 ' . $wa_numero . ' (Análise)', 'UNKNOWN'  => '⚪ ' . $wa_numero . ' (Oculto)' ];
                        $status_whats_texto = $mapa_waba_whats[$waba_status_raw] ?? $mapa_waba_whats['UNKNOWN'];
                    }
                }

                $url = "https://graph.facebook.com/v19.0/{$v['AD_ID']}/insights?date_preset=today&fields=reach,impressions,frequency,spend,actions,clicks,ctr&access_token=" . $config['META_ACCESS_TOKEN'];
                $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $meta_data = json_decode(curl_exec($ch), true); curl_close($ch);

                if (isset($meta_data['data'][0])) {
                    $d = $meta_data['data'][0];
                    $alcance = $d['reach'] ?? 0; $impressoes = $d['impressions'] ?? 0; $frequencia = round($d['frequency'] ?? 0, 2);
                    $valor_usado = $d['spend'] ?? 0.00; $ctr = round($d['ctr'] ?? 0, 2) . '%'; $cliques = $d['clicks'] ?? 0;
                    if (isset($d['actions'])) { foreach ($d['actions'] as $action) { if (strpos($action['action_type'], 'messaging_conversation') !== false || strpos($action['action_type'], 'lead') !== false) { $conversas += $action['value']; } } }
                    if ($conversas > 0) $custo_conversa = $valor_usado / $conversas;
                }
            }

            $custoFormatado = 'R$ ' . number_format($custo_conversa, 2, ',', '.');
            $gastoFormatado = 'R$ ' . number_format($valor_usado, 2, ',', '.');
            
            $msgFinal = str_replace(
                ['{NOME_CLIENTE}', '{NOME_ANUNCIO}', '{CONTA_ANUNCIO}', '{STATUS_CONTA}', '{STATUS_WHATS}', '{DATA_REFERENCIA}', '{ALCANCE}', '{IMPRESSOES}', '{FREQUENCIA}', '{CLIQUES}', '{CTR}', '{CONVERSAS}', '{CUSTO_CONVERSA}', '{GASTO_PERIODO}', '{SALDO_DISPONIVEL}'],
                [$v['NOME_CLIENTE'], $v['NOME_ANUNCIO'], $v['NOME_CONTA_ANUNCIO'], $status_conta_texto, $status_whats_texto, $dataRef, $alcance, $impressoes, $frequencia, $cliques, $ctr, $conversas, $custoFormatado, $gastoFormatado, $saldo_disponivel_texto],
                $template
            );

            $url_wapi = "https://api.w-api.app/v1/message/send-text?instanceId=" . $wapiCfg['WAPI_INSTANCE'];
            $id_final = preg_replace('/[^0-9]/', '', $grupo_id) . '@g.us';

            $ch = curl_init(); curl_setopt($ch, CURLOPT_URL, $url_wapi); curl_setopt($ch, CURLOPT_POST, 1); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([ "phone" => $id_final, "message" => $msgFinal ])); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $wapiCfg['WAPI_TOKEN']]);
            $resposta_wapi = curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            
            if ($http_code != 200 && $http_code != 201) { echo "   [X] Erro ao enviar para {$v['NOME_CLIENTE']}: {$resposta_wapi}\n"; } else { echo "   [OK] Sucesso para {$v['NOME_CLIENTE']}!\n"; }
            
            sleep(10); 
        }
    }
    echo "=== FIM DO PROCESSAMENTO DO CRON ===\n";

} catch (Exception $e) { echo "ERRO CRITICO: " . $e->getMessage() . "\n"; }
?>