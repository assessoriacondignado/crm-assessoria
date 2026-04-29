<?php
// Arquivo: index.api.v8.lote.vsc.php
// Módulo isolado para gestão de Lotes CSV da V8
@session_start();
?>
<style>
/* === V8 MENU AÇÕES LOTE === */
.v8-acoes-dropdown { min-width: 400px; border-radius: 12px !important; overflow: hidden; border: 1px solid #dee2e6 !important; }
.v8-top-actions { padding: 6px 8px; background: #f8f9fa; border-bottom: 1px solid #dee2e6; }
.v8-top-actions .dropdown-item {
    border-radius: 6px; font-size: 12px; padding: 5px 10px;
    transition: background 0.15s ease, box-shadow 0.15s ease;
}
.v8-top-actions .dropdown-item:hover:not(:disabled) { background: #e2e8f0; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
.v8-secao-hdr {
    display: flex; justify-content: space-between; align-items: center;
    padding: 9px 14px; font-size: 11px; font-weight: 800; letter-spacing: 0.4px;
    cursor: pointer; user-select: none; transition: filter 0.15s;
}
.v8-secao-hdr:hover { filter: brightness(0.91); }
.v8-secao-hdr.v8-azul   { background: #1a73e8; color: #fff; }
.v8-secao-hdr.v8-verde  { background: #198754; color: #fff; }
.v8-secao-hdr.v8-laranja{ background: #e07800; color: #fff; }
.v8-secao-hdr.v8-roxo   { background: #6f42c1; color: #fff; }
.v8-item-laranja:hover:not(:disabled) { background: rgba(224,120,0,.09); box-shadow: inset 3px 0 0 #e07800; color: #7a4000; }
.v8-item-roxo:hover:not(:disabled)    { background: rgba(111,66,193,.09); box-shadow: inset 3px 0 0 #6f42c1; color: #3d1a8a; }
.v8-chevron { transition: transform 0.2s ease; font-size: 10px; }
.v8-chevron.aberto { transform: rotate(180deg); }
.v8-item {
    display: flex; align-items: center; width: 100%; background: none; border: none;
    padding: 9px 14px; text-align: left; cursor: pointer; text-decoration: none;
    color: #212529; border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.15s ease, transform 0.1s ease, box-shadow 0.12s ease;
}
.v8-item:last-child { border-bottom: none; }
.v8-item:hover:not(:disabled) { transform: translateX(3px); }
.v8-item-azul:hover:not(:disabled)  { background: rgba(26,115,232,.09); box-shadow: inset 3px 0 0 #1a73e8; color: #1a4fa8; }
.v8-item-verde:hover:not(:disabled) { background: rgba(25,135,84,.09);  box-shadow: inset 3px 0 0 #198754; color: #145e3c; }
.v8-item-icon { font-size: 17px; margin-right: 10px; min-width: 22px; text-align: center; flex-shrink: 0; }
.v8-item-txt  { display: flex; flex-direction: column; line-height: 1.3; }
.v8-item-txt strong { font-size: 12px; font-weight: 700; }
.v8-item-txt small  { font-size: 10px; color: #6c757d; margin-top: 1px; }
.v8-manut { opacity: .55; cursor: not-allowed !important; }
.v8-manut small { color: #fd7e14 !important; font-weight: 600; }
.v8-secao-bdy { animation: v8Slide .15s ease; }
@keyframes v8Slide { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }
.v8-rodape-acoes { padding: 6px 8px; border-top: 1px solid #f0d0d0; background: #fff8f8; }
.v8-btn-apagar {
    display: flex; align-items: center; width: 100%; background: none; border: none;
    padding: 6px 10px; border-radius: 6px; cursor: pointer; color: #dc3545;
    font-size: 12px; font-weight: 700; transition: background .15s, box-shadow .15s;
}
.v8-btn-apagar:hover { background: rgba(220,53,69,.1); box-shadow: 0 1px 4px rgba(220,53,69,.2); }
/* confirm modal */
#v8ModalConfirm .modal-header { border-bottom: none; border-left: 4px solid #0d6efd; border-radius: 0; padding-bottom: 4px; }
#v8ModalConfirm .modal-footer { border-top: none; padding-top: 4px; }
</style>

<div class="caixa-upload shadow-sm border-success mb-4 text-start" style="border: 2px dashed #198754; background: #f8fff9; padding: 15px 25px; border-radius: 10px;">
    
    <a data-bs-toggle="collapse" href="#collapseImportacao" role="button" aria-expanded="true" aria-controls="collapseImportacao" class="text-decoration-none d-block">
        <h5 class="fw-bold text-success mb-0 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-cloud-upload-alt me-2"></i> Importação de Lote para Enriquecimento V8</span>
            <i class="fas fa-chevron-down text-dark fs-6"></i>
        </h5>
    </a>
    
    <div class="collapse show mt-3" id="collapseImportacao">
        <div class="alert alert-warning small text-start border-warning mb-4 shadow-sm" style="background-color: #fff8e6;">
            <h6 class="fw-bold text-danger mb-2"><i class="fas fa-exclamation-triangle"></i> ATENÇÃO ÀS REGRAS DE USO:</h6>
            <ul class="mb-0 ps-3">
                <li><b>1:</b> Limite de <b>100.000 CPFs</b> por importação.</li>
                <li><b>2:</b> Cada usuário pode rodar <b>1 lote em simultâneo</b>.</li>
                <li><b>3:</b> O usuário pode ter lotes agendados, criando um fluxo de 1 lote por dia.</li>
                <li><b>4:</b> Formatos aceitos em Excel (.csv):
                    <ul class="mb-0 ps-3">
                        <li>
                            <span class="badge bg-secondary text-white me-1" style="cursor:help;" data-bs-toggle="tooltip" data-bs-placement="right"
                                title="Somente para lista fornecida pela Assessoria. O sistema busca os dados cadastrais automaticamente na base interna.">
                                Lista da Assessoria
                            </span>
                            Coluna obrigatória: <b>CPF</b>
                        </li>
                        <li class="mt-1">
                            <span class="badge bg-dark text-white me-1" style="cursor:help;" data-bs-toggle="tooltip" data-bs-placement="right"
                                title="Para lista externa (não vinculada à Assessoria). O banco exige todos os dados das colunas exatos e no formato correto.">
                                Lista Externa
                            </span>
                            Colunas obrigatórias: <b>CPF, NOME, DATA NASCIMENTO, SEXO</b>
                        </li>
                    </ul>
                </li>
                <li class="mt-1"><b>5:</b> Cuidado no uso de opções e automações, os mesmos podem gerar custos ou impedir a consulta.</li>
            </ul>
        </div>
        
        <form id="form_upload_lote_v8" enctype="multipart/form-data">
            <div class="row g-2 align-items-end text-start">
                
                <div class="col-md-2 pb-1">
                    <div class="form-check form-switch border border-dark rounded bg-white shadow-sm d-flex align-items-center justify-content-center p-1 m-0" style="height: 31px;">
                        <input class="form-check-input ms-0 me-2 mt-0" type="checkbox" id="chk_is_append" name="is_append" value="1" onchange="v8ToggleAppendMode()">
                        <label class="form-check-label fw-bold text-dark small text-nowrap" for="chk_is_append" style="font-size: 10px; cursor: pointer;">INCLUIR MAIS CLIENTE</label>
                    </div>
                </div>

                <div id="box_agrupamento" class="col-md-3">
                    <label class="fw-bold small text-dark mb-1">Agrupamento (Lista):</label>
                    <input type="text" name="agrupamento" id="lote_v8_agrupamento" class="form-control form-control-sm border-success" required>
                </div>
                <div id="box_chave" class="col-md-3">
                    <label class="fw-bold small text-dark mb-1">Cobrar Lote de qual Chave?</label>
                    <select id="lote_v8_chave" name="chave_id" class="form-select form-select-sm border-success v8-dropdown-clientes" required onchange="v8AtualizarSaldosTopo(this.value)"></select>
                </div>

                <div id="box_append_select" class="col-md-6 d-none">
                    <label class="fw-bold small text-primary mb-1"><i class="fas fa-search me-1"></i> Selecione o Lote Destino (Para adicionar os CPFs):</label>
                    <select id="sel_append_lote" name="append_lote_id" class="form-select form-select-sm border-primary fw-bold text-dark bg-light"></select>
                </div>

                <div class="col-md-2">
                    <label class="fw-bold small text-dark mb-1">Arquivo CSV:</label>
                    <input type="file" name="arquivo_csv" id="lote_v8_arquivo" class="form-control form-control-sm border-success" accept=".csv" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-success btn-sm w-100 fw-bold shadow-sm" id="btn_enviar_lote_v8"><i class="fas fa-cloud-upload-alt me-1"></i> Importar Lista</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-end mb-3 mt-4">
    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2"></i> Gerenciador de Lotes Exportação V8</h5>
    <div class="d-flex gap-2">
        
        <button class="btn btn-outline-dark btn-sm fw-bold shadow-sm bg-white border-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapseFiltroLote"><i class="fas fa-filter me-1"></i> Filtro Aprimorado</button>
        <button class="btn btn-warning btn-sm text-dark fw-bold shadow-sm border-dark" onclick="v8ForcarProcessamentoLote()"><i class="fas fa-bolt"></i> Destravar Fila</button>
        <button class="btn btn-dark btn-sm fw-bold shadow-sm" onclick="v8CarregarLotesCSV()"><i class="fas fa-sync"></i> Atualizar Tabela</button>
    </div>
</div>

<div class="collapse mb-3" id="collapseFiltroLote">
    <div class="border-dark shadow-sm" style="background-color: #212529; padding: 15px; border-radius: 8px;">
        <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-2 mb-3">
            <h6 class="fw-bold mb-0 text-white"><i class="fas fa-filter text-success me-2"></i> MONTADOR DE FILTROS (LOTES)</h6>
        </div>
        
        <div class="alert alert-light small py-2 mb-3 text-dark fw-bold border-0 shadow-sm" style="background-color: #e9ecef;">
            <i class="fas fa-info-circle text-primary me-1"></i> <b>Dica:</b> O padrão do sistema é listar apenas os lotes ATIVOS, a não ser que você filtre pelo "Status do Robô".
        </div>
        
        <div id="container_regras_lote"></div>
        
        <div class="d-flex justify-content-between align-items-center mt-3 border-top border-secondary pt-3">
            <button class="btn btn-dark border-secondary btn-sm fw-bold shadow-sm" onclick="v8AdicionarRegraLote()"><i class="fas fa-plus"></i> Adicionar Regra</button>
            <div class="d-flex gap-2">
                <button class="btn btn-light btn-sm fw-bold shadow-sm" onclick="v8LimparRegrasLote()">Limpar Filtros</button>
                <button class="btn btn-info btn-sm fw-bold shadow-sm" onclick="v8CarregarLotesCSV()"><i class="fas fa-search"></i> Executar Filtro</button>
            </div>
        </div>
    </div>
</div>

<div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 1050px; max-height: 1200px; overflow-y: auto; overflow-x: auto;">
    <table class="table table-hover table-bordered align-middle mb-0 text-center" style="font-size: 13px;">
        <thead class="table-dark sticky-top">
            <tr>
                <th style="width: 50px;">ID</th>
                <th class="text-start" style="width: 350px;">Lista e Chave Origem</th>
                <th class="text-start" style="width: 250px;">Info do Lote</th>
                <th>Status e Progresso</th>
                <th style="width: 200px;">Funil (OK / Erro)</th>
                <th style="width: 120px;">Ações</th>
            </tr>
        </thead>
        <tbody id="v8_tbody_lotes" class="bg-white">
            <tr><td colspan="6" class="py-4 text-muted"><i class="fas fa-spinner fa-spin me-2"></i> Carregando lotes...</td></tr>
        </tbody>
    </table>
</div>

<div class="modal fade" id="modalEditarLote" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-dark text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-sliders-h text-warning me-2"></i> Ver Parâmetros do Lote V8</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div id="edit_lote_aviso_processando" class="alert alert-warning border-warning border-2 rounded-0 mb-0 py-2 px-3 d-none" style="font-size:0.85rem;">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Lote em processamento.</strong> Para salvar alterações é necessário pausar o lote.
                <button type="button" class="btn btn-sm btn-warning border-dark fw-bold ms-2" onclick="v8PausarParaSalvar()">
                    <i class="fas fa-pause me-1"></i> Pausar e Salvar
                </button>
            </div>
            <div class="modal-body bg-light p-4">
                <form id="formEditarLoteV8" class="row g-3">
                    <input type="hidden" id="edit_lote_id">
                    <div class="col-md-12">
                        <label class="fw-bold small text-dark mb-1">Nome do Agrupamento:</label>
                        <input type="text" id="edit_lote_agrupamento" class="form-control border-secondary fw-bold" required>
                    </div>
                    <div class="col-12 mt-2 mb-1"><hr class="m-0"><small class="text-muted fw-bold mt-1 d-block"><i class="fas fa-clock"></i> Horário de Funcionamento Diário <span class="text-danger">*obrigatório</span></small></div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-dark mb-1">Horário de Início: <span class="text-danger">*</span></label>
                        <input type="time" id="edit_hora_inicio_diario" class="form-control border-warning fw-bold" required>
                        <small class="text-muted" style="font-size:10px;">Robô liga automaticamente neste horário</small>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-danger mb-1">Horário de Fim: <span class="text-danger">*</span></label>
                        <input type="time" id="edit_hora_fim_diario" class="form-control border-danger fw-bold" required>
                        <small class="text-muted" style="font-size:10px;">Robô para automaticamente neste horário</small>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-dark mb-1">Limite Diário (0 = Sem Limite):</label>
                        <input type="number" id="edit_limite_diario" class="form-control border-primary">
                    </div>
                    <div class="col-12">
                        <label class="fw-bold small text-dark mb-1">Dias do Mês: <span class="text-muted fw-normal">(deixe vazio = todos os dias)</span></label>
                        <div class="border border-warning rounded p-2 bg-white shadow-sm">
                            <div class="d-flex flex-wrap gap-1 mb-2" id="v8_dias_picker_edit">
                                <?php for($d=1; $d<=31; $d++): ?>
                                <button type="button" class="btn btn-outline-secondary btn-sm v8-dia-btn-edit fw-bold"
                                    style="width:34px; height:30px; font-size:11px; padding:0;"
                                    data-dia="<?= $d ?>" onclick="v8ToggleDiaEdit(this)"><?= $d ?></button>
                                <?php endfor; ?>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-warning border-dark fw-bold" onclick="v8SelecionarTodosDiasEdit()">Todos os dias</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary border-dark fw-bold" onclick="v8LimparDiasEdit()">Limpar</button>
                            </div>
                            <input type="hidden" id="edit_dias_mes_diario" value="TODOS">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-dark mb-1">Processamento:</label>
                        <div class="form-check border p-2 rounded bg-white border-dark shadow-sm">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_somente_simular" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_somente_simular" title="Somente Simular (Não gera novos consentimentos)">
                                <i class="fas fa-bolt text-warning"></i> Somente Simular
                            </label>
                        </div>
                    </div>
                    <div class="col-12 mt-3 mb-1"><hr class="m-0"><small class="text-primary fw-bold mt-1 d-block"><i class="fas fa-robot"></i> Automação Pós-Aprovação</small></div>
                    <div class="col-md-3">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0 h-100">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_atualizar_telefone" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_atualizar_telefone"
                                title="O CPF será atualizado com dados cadastrais (endereço, telefones e e-mail) após localizar a margem" data-bs-toggle="tooltip" data-bs-placement="top">
                                <i class="fas fa-phone-alt text-success"></i> Telefones via FC
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0 h-100">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_enviar_whats" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_enviar_whats"
                                title="O CRM Assessoria enviará uma msg com (nome, CPF, margem, simulação e telefones) para o grupo cadastrado no usuário, toda vez que for localizada margem" data-bs-toggle="tooltip" data-bs-placement="top">
                                <i class="fab fa-whatsapp text-success"></i> Aprovação no W-API
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0 h-100">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_enviar_arquivo_whatsapp" value="1" onchange="v8ToggleCsvHora(this.checked)">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_enviar_arquivo_whatsapp"
                                title="O CRM Assessoria enviará um link para baixar o relatório completo (filtro: margem > R$1,00) no grupo do WhatsApp cadastrado no usuário. Defina o horário de envio." data-bs-toggle="tooltip" data-bs-placement="top">
                                <i class="fas fa-file-csv text-success"></i> Enviar CSV Fim (W-API)
                            </label>
                            <div id="wrap_hora_csv" style="display:none; margin-top:6px;">
                                <div class="text-muted mb-1" style="font-size:10px;">Horário de envio:</div>
                                <input type="time" id="edit_hora_envio_csv" class="form-control form-control-sm border-info" style="font-size:12px;">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check border p-2 rounded bg-white border-warning shadow-sm mb-0 h-100">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_aviso_status_wapi" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_aviso_status_wapi"
                                title="O CRM Assessoria avisará no grupo do WhatsApp as alterações de status do lote (erros e conclusão)" data-bs-toggle="tooltip" data-bs-placement="top">
                                <i class="fas fa-bell text-warning"></i> Status Lote
                            </label>
                        </div>
                    </div>
                    <div class="col-12 mt-3 mb-1"><hr class="m-0"><small class="text-success fw-bold mt-1 d-block"><i class="fas fa-bullhorn"></i> Campanha Automática — ao localizar margem (OK)</small></div>
                    <div class="col-md-7">
                        <label class="fw-bold small text-dark mb-1">Incluir automaticamente na Campanha:</label>
                        <select id="edit_id_campanha_auto" class="form-select border-dark shadow-sm rounded-0">
                            <option value="">— Nenhuma —</option>
                        </select>
                        <div class="form-text">Apenas campanhas que você tem acesso são listadas.</div>
                    </div>
                    <div class="col-md-5">
                        <label class="fw-bold small text-dark mb-1">Margem Mínima para incluir (R$):</label>
                        <input type="number" id="edit_valor_margem_min_campanha" class="form-control border-dark shadow-sm rounded-0" step="0.01" min="0" placeholder="0,00 = qualquer margem">
                        <div class="form-text">Deixe 0 para incluir com qualquer valor de margem.</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light border-top border-secondary">
                <button type="button" class="btn btn-secondary fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Fechar</button>
                <button type="button" id="btn_salvar_edicao_lote" class="btn btn-success fw-bold px-5 shadow-sm border-dark" onclick="v8SalvarEdicaoLote()"><i class="fas fa-save"></i> Salvar Alterações</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAnotacoesLote" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-info text-dark border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-sticky-note me-2"></i> Bloco de Notas do Lote</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <input type="hidden" id="anotacao_lote_id">
                <label class="fw-bold text-dark mb-2">Observações / Anotações:</label>
                <textarea id="anotacao_lote_texto" class="form-control border-dark shadow-sm" rows="8" style="background-color: #fffbc4; font-family: monospace; font-size: 14px;" placeholder="Digite suas anotações aqui..."></textarea>
            </div>
            <div class="modal-footer bg-light border-top border-secondary justify-content-between">
                <button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" onclick="v8ExcluirAnotacao()"><i class="fas fa-trash"></i> Excluir</button>
                <button type="button" class="btn btn-success fw-bold px-4 shadow-sm border-dark" onclick="v8SalvarAnotacao()"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAppendLote" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-dark shadow-lg">
            <div class="modal-header bg-success text-white border-bottom border-dark">
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-plus-circle me-2"></i> Incluir Mais CPFs</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formAppendLote">
                <div class="modal-body bg-light p-4">
                    <input type="hidden" id="append_lote_id" name="append_lote_id">
                    <div class="mb-3">
                        <label class="fw-bold small text-dark mb-1">Lote Destino:</label>
                        <input type="text" id="append_lote_nome" class="form-control border-dark fw-bold text-muted bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-dark mb-1">Novo Arquivo CSV:</label>
                        <input type="file" name="arquivo_csv" class="form-control border-success" accept=".csv" required>
                    </div>
                    <div class="alert alert-info small border-info mt-2 mb-0 py-2">
                        <i class="fas fa-info-circle me-1"></i> CPFs já existentes no lote terão <strong>Nome, Nascimento e Sexo atualizados</strong> automaticamente com os dados do novo arquivo.
                    </div>
                </div>
                <div class="modal-footer bg-light border-top border-secondary">
                    <button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold px-4 shadow-sm border-dark" id="btn_enviar_append"><i class="fas fa-upload"></i> Importar e Adicionar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Modal Listar Clientes do Lote -->
<div class="modal fade" id="v8ModalClientesLote" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content shadow-lg border-0" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header py-2 px-3" style="background:#6f42c1; color:#fff; border-bottom:none;">
                <h6 class="modal-title fw-bold mb-0" id="v8ClientesLoteTitulo">👥 Clientes do Lote</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-2 bg-light border-bottom d-flex gap-2 align-items-center flex-wrap" style="font-size:12px;">
                    <input type="text" id="v8ClientesFiltro" class="form-control form-control-sm" style="max-width:180px;" placeholder="Filtrar por CPF ou nome..." oninput="v8FiltrarClientesLote()">
                    <select id="v8ClientesStatus" class="form-select form-select-sm" style="max-width:175px;" onchange="v8FiltrarClientesLote()">
                        <option value="">Todos os Status</option>
                        <option value="OK">✅ OK</option>
                        <option value="ERRO MARGEM">⚠️ Erro Margem</option>
                        <option value="AGUARDANDO DATAPREV">📡 Aguardando Dataprev</option>
                        <option value="ERRO SIMULACAO">🔢 Erro Simulação</option>
                        <option value="NA FILA">⏳ Na Fila</option>
                    </select>
                    <span class="text-muted fw-bold" style="font-size:11px; white-space:nowrap;">Data Filtro Margem:</span>
                    <input type="date" id="v8ClientesDataDe" class="form-control form-control-sm" style="max-width:135px;" title="Data de" onchange="v8FiltrarClientesLote()">
                    <span class="text-muted" style="font-size:11px;">até</span>
                    <input type="date" id="v8ClientesDataAte" class="form-control form-control-sm" style="max-width:135px;" title="Data até" onchange="v8FiltrarClientesLote()">

                    <!-- Ações: Campanha + Auditoria -->
                    <div class="d-flex gap-1 align-items-center ms-auto">
                        <span class="text-muted" id="v8ClientesContador" style="white-space:nowrap;"></span>
                        <?php if (!empty($perm_auditoria_inclusao) && $perm_auditoria_inclusao): ?>
                        <button class="btn btn-sm fw-bold" id="v8BtnAuditoria" style="background:#b02a37; color:#fff; border:none; font-size:11px; padding:4px 10px; display:none;"
                                onclick="v8IniciarAuditoria()">
                            <i class="fas fa-shield-alt me-1"></i> Auditoria
                        </button>
                        <?php endif; ?>
                        <div class="dropdown" id="v8CampanhaDropdown" style="display:none;">
                            <button class="btn btn-sm fw-bold dropdown-toggle" type="button"
                                    style="background:#6f42c1; color:#fff; border:none; font-size:11px; padding:4px 10px;"
                                    data-bs-toggle="dropdown" data-bs-auto-close="outside"
                                    onclick="v8CarregarCampanhasDropdown()">
                                <i class="fas fa-plus-circle me-1"></i> Incluir em Campanha
                            </button>
                            <ul class="dropdown-menu shadow" id="v8CampanhaLista" style="min-width:260px; font-size:12px; max-height:260px; overflow-y:auto;">
                                <li><span class="dropdown-item text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando...</span></li>
                            </ul>
                        </div>
                        <button class="btn btn-sm fw-bold" id="v8BtnIncluirCampanha"
                                style="background:#6f42c1; color:#fff; border:none; font-size:11px; padding:4px 10px; display:none;"
                                onclick="v8ConfirmarIncluirCampanha()">
                            <i class="fas fa-plus-circle me-1"></i> Incluir em Campanha
                        </button>
                    </div>
                </div>
                <?php if (!empty($perm_auditoria_inclusao) && $perm_auditoria_inclusao): ?>
                <!-- Barra de confirmação de Auditoria -->
                <div id="v8AuditoriaBar" style="display:none; background:#fff5f5; border-bottom:2px solid #b02a37; padding:6px 12px; font-size:12px;" class="d-flex align-items-center gap-2">
                    <i class="fas fa-shield-alt" style="color:#b02a37;"></i>
                    <span class="fw-bold" style="color:#b02a37;">AUDITORIA</span>
                    <span class="text-muted">— <span id="v8AuditoriaQtd"></span> CPF(s) selecionados serão transferidos e bloqueados no lote atual.</span>
                    <button class="btn btn-sm fw-bold ms-auto" style="background:#b02a37; color:#fff; border:none; font-size:11px; padding:3px 10px;"
                            onclick="v8ExecutarAuditoria()">
                        <i class="fas fa-check me-1"></i> Confirmar Auditoria
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:11px; padding:3px 8px;"
                            onclick="v8CancelarAuditoria()">Cancelar</button>
                </div>
                <?php endif; ?>

                <!-- Barra de campanha selecionada -->
                <div id="v8CampanhaSelecionadaBar" style="display:none; background:#f0ebff; border-bottom:1px solid #d0c0f0; padding:6px 12px; font-size:12px;" class="d-flex align-items-center gap-2">
                    <i class="fas fa-bullhorn" style="color:#6f42c1;"></i>
                    <span>Campanha selecionada: <strong id="v8CampanhaNomeSel"></strong></span>
                    <span class="text-muted">— <span id="v8CampanhaQtdSel"></span> cliente(s) na tela serão incluídos</span>
                    <button class="btn btn-sm fw-bold ms-auto" style="background:#6f42c1; color:#fff; border:none; font-size:11px; padding:3px 10px;"
                            onclick="v8ExecutarIncluirCampanha()">
                        <i class="fas fa-check me-1"></i> Confirmar Inclusão
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" style="font-size:11px; padding:3px 8px;"
                            onclick="v8CancelarCampanha()">Cancelar</button>
                </div>
                <div id="v8ClientesTabela" style="max-height:65vh; overflow-y:auto;">
                    <div class="text-center py-4 text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Confirmação de Ações V8 -->
<div class="modal fade" id="v8ModalConfirm" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content shadow-lg border-0" style="border-radius:14px; overflow:hidden;">
            <div class="modal-header py-2 px-3" id="v8ConfirmHdr">
                <h6 class="modal-title fw-bold mb-0" id="v8ConfirmTitulo"></h6>
            </div>
            <div class="modal-body py-2 px-3">
                <p class="small text-muted mb-0" id="v8ConfirmDescricao"></p>
            </div>
            <div class="modal-footer py-2 px-3 gap-2">
                <button type="button" class="btn btn-sm btn-secondary px-3" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-sm fw-bold px-3" id="v8ConfirmBtnOk">Confirmar</button>
            </div>
        </div>
    </div>
</div>

<script>
    let modalEditarLoteObj, modalAnotacoesLoteObj, modalAppendLoteObj, v8ModalConfirmObj;
    let windowDadosLoteAtual = [];
    let intervaloLoteV8 = null;
    let isMenuAcoesAberto = false; // TRAVA: Previne o piscar da tela enquanto o menu está aberto
    let v8CpfResponsavelCache = '<?= preg_replace('/\D/', '', $_SESSION['usuario_cpf'] ?? '') ?>';

    // Variáveis de permissão transferidas do index.php
    const restricaoLoteEditar = <?= isset($restricao_lote_editar) && $restricao_lote_editar ? 'true' : 'false' ?>;
    const restricaoLoteExcluir = <?= isset($restricao_lote_excluir) && $restricao_lote_excluir ? 'true' : 'false' ?>;

    document.addEventListener("DOMContentLoaded", () => {
        if(document.getElementById('modalEditarLote')) modalEditarLoteObj = new bootstrap.Modal(document.getElementById('modalEditarLote'));
        if(document.getElementById('modalAnotacoesLote')) modalAnotacoesLoteObj = new bootstrap.Modal(document.getElementById('modalAnotacoesLote'));
        if(document.getElementById('modalAppendLote')) modalAppendLoteObj = new bootstrap.Modal(document.getElementById('modalAppendLote'));
        if(document.getElementById('v8ModalConfirm')) v8ModalConfirmObj = new bootstrap.Modal(document.getElementById('v8ModalConfirm'));
        if(document.getElementById('v8ModalClientesLote')) window.v8ModalClientesLoteObj = new bootstrap.Modal(document.getElementById('v8ModalClientesLote'));

        const tabLote = document.querySelector('[data-bs-target="#tab-lote-csv"]');
        if(tabLote) {
            tabLote.addEventListener('shown.bs.tab', function (e) {
                v8CarregarLotesCSV();
                if(typeof windowListaChavesCache !== 'undefined') {
                    let o = '<option value="">-- Selecione a Chave/Cliente --</option>';
                    windowListaChavesCache.forEach(c => { o += `<option value="${c.ID}">${c.CLIENTE_NOME} (Saldo: R$ ${c.SALDO})</option>`; });
                    document.querySelectorAll('.v8-dropdown-clientes').forEach(s => s.innerHTML = o);
                }
                // Inicia verificação de agendamentos diários a cada 60s enquanto a aba está aberta
                if(!window._v8DiarioInterval) {
                    v8VerificarAgendamentosDiarios(); // Roda imediatamente ao abrir a aba
                    window._v8DiarioInterval = setInterval(v8VerificarAgendamentosDiarios, 60000);
                }
            });
            tabLote.addEventListener('hidden.bs.tab', function (e) {
                if(intervaloLoteV8) { clearInterval(intervaloLoteV8); intervaloLoteV8 = null; }
                if(window._v8DiarioInterval) { clearInterval(window._v8DiarioInterval); window._v8DiarioInterval = null; }
            });
        }
        
        // Listener Global para destravar a tabela quando fechar qualquer menu Bootstrap Dropdown
        document.body.addEventListener('hidden.bs.dropdown', function () {
            isMenuAcoesAberto = false;
        });
    });

    function markMenuAsOpen() {
        isMenuAcoesAberto = true;
    }

    function v8AcordarRobo(cpfEspecifico = null) {
        let cpf = cpfEspecifico ? cpfEspecifico : v8CpfResponsavelCache;
        fetch('worker_v8_lote.php?user_cpf=' + cpf, { method: 'GET', mode: 'no-cors', cache: 'no-cache' }).catch(() => {});
    }

    function v8ToggleAppendMode() {
        const isAppend = document.getElementById('chk_is_append').checked;
        if(isAppend) {
            document.getElementById('box_agrupamento').classList.add('d-none');
            document.getElementById('box_chave').classList.add('d-none');
            document.getElementById('box_opcoes_novolote').classList.add('d-none');
            document.getElementById('box_append_select').classList.remove('d-none');

            document.getElementById('lote_v8_agrupamento').required = false;
            document.getElementById('lote_v8_chave').required = false;
            document.getElementById('sel_append_lote').required = true;

            let sel = document.getElementById('sel_append_lote');
            sel.innerHTML = '<option value="">-- Escolha um Lote Destino Ativo --</option>';
            if(windowDadosLoteAtual) {
                windowDadosLoteAtual.forEach(l => {
                    if(l.STATUS_LOTE === 'ATIVO' || !l.STATUS_LOTE) {
                        sel.innerHTML += `<option value="${l.ID}">${l.ID} - ${l.NOME_IMPORTACAO}</option>`;
                    }
                });
            }
        } else {
            document.getElementById('box_agrupamento').classList.remove('d-none');
            document.getElementById('box_chave').classList.remove('d-none');
            document.getElementById('box_opcoes_novolote').classList.remove('d-none');
            document.getElementById('box_append_select').classList.add('d-none');

            document.getElementById('lote_v8_agrupamento').required = true;
            document.getElementById('lote_v8_chave').required = true;
            document.getElementById('sel_append_lote').required = false;
        }
    }

    // Agendamento sempre DIARIO — funções de toggle removidas

    // --- SELETOR DE DIAS (NOVO / FORMULÁRIO) ---
    function v8ToggleDia(btn) {
        btn.classList.toggle('btn-outline-secondary');
        btn.classList.toggle('btn-warning');
        v8AtualizarHiddenDias();
    }
    function v8SelecionarTodosDias() {
        document.querySelectorAll('.v8-dia-btn').forEach(b => {
            b.classList.remove('btn-outline-secondary'); b.classList.add('btn-warning');
        });
        document.getElementById('dias_mes_diario_val').value = 'TODOS';
    }
    function v8LimparDias() {
        document.querySelectorAll('.v8-dia-btn').forEach(b => {
            b.classList.remove('btn-warning'); b.classList.add('btn-outline-secondary');
        });
        document.getElementById('dias_mes_diario_val').value = 'TODOS';
    }
    function v8AtualizarHiddenDias() {
        let selecionados = [];
        document.querySelectorAll('.v8-dia-btn.btn-warning').forEach(b => selecionados.push(b.dataset.dia));
        document.getElementById('dias_mes_diario_val').value = selecionados.length > 0 ? selecionados.join(',') : 'TODOS';
    }

    // --- SELETOR DE DIAS (EDIÇÃO) ---
    function v8ToggleDiaEdit(btn) {
        btn.classList.toggle('btn-outline-secondary');
        btn.classList.toggle('btn-warning');
        v8AtualizarHiddenDiasEdit();
    }
    function v8SelecionarTodosDiasEdit() {
        document.querySelectorAll('.v8-dia-btn-edit').forEach(b => {
            b.classList.remove('btn-outline-secondary'); b.classList.add('btn-warning');
        });
        document.getElementById('edit_dias_mes_diario').value = 'TODOS';
    }
    function v8LimparDiasEdit() {
        document.querySelectorAll('.v8-dia-btn-edit').forEach(b => {
            b.classList.remove('btn-warning'); b.classList.add('btn-outline-secondary');
        });
        document.getElementById('edit_dias_mes_diario').value = 'TODOS';
    }
    function v8AtualizarHiddenDiasEdit() {
        let selecionados = [];
        document.querySelectorAll('.v8-dia-btn-edit.btn-warning').forEach(b => selecionados.push(b.dataset.dia));
        document.getElementById('edit_dias_mes_diario').value = selecionados.length > 0 ? selecionados.join(',') : 'TODOS';
    }
    function v8CarregarDiasEdit(diasStr) {
        v8LimparDiasEdit();
        if (!diasStr || diasStr === 'TODOS') { v8SelecionarTodosDiasEdit(); return; }
        let arr = diasStr.split(',').map(s => s.trim());
        document.querySelectorAll('.v8-dia-btn-edit').forEach(b => {
            if (arr.includes(b.dataset.dia)) {
                b.classList.remove('btn-outline-secondary'); b.classList.add('btn-warning');
            }
        });
        document.getElementById('edit_dias_mes_diario').value = diasStr;
    }

    // --- FUNÇÃO EXPORTAR TUDO (MÁSTER) ---
    function v8ExportarTudoLotes(somente_simulados = false) {
        const msg = somente_simulados
            ? "Deseja exportar somente os CPFs com MARGEM acima de R$ 1,00 dos lotes filtrados?"
            : "Deseja exportar todos os CPFs dos lotes que estão filtrados na tabela atualmente?";
        if(!confirm(msg + "\n\nDependendo da quantidade de CPFs, o download pode levar alguns segundos para começar.")) return;

        let regras = v8ObterRegrasLote();
        let encodedRegras = encodeURIComponent(regras);
        let url = `ajax_api_v8_lote_csv.php?acao=exportar_tudo_lotes&regras=${encodedRegras}&somente_simulados=${somente_simulados ? 1 : 0}`;
        window.open(url, '_blank');
    }

    // --- FUNÇÕES DO FILTRO APRIMORADO ---
    function v8AdicionarRegraLote() {
        const container = document.getElementById('container_regras_lote');
        const row = document.createElement('div');
        row.className = 'row g-2 mb-2 regra-linha align-items-center bg-white p-2 rounded shadow-sm';
        row.innerHTML = `
            <div class="col-md-3">
                <select class="form-select form-select-sm border-dark regra-campo text-dark fw-bold">
                    <optgroup label="Identificação">
                        <option value="l.ID">ID do Lote</option>
                        <option value="l.NOME_IMPORTACAO">Nome do Agrupamento</option>
                        <option value="c.CLIENTE_NOME">Nome do Cliente/Credencial V8</option>
                        <option value="l.CPF_USUARIO">CPF do Usuário</option>
                    </optgroup>
                    <optgroup label="Status">
                        <option value="l.STATUS_FILA">Status Progresso (Ex: CONCLUIDO)</option>
                        <option value="l.STATUS_LOTE">Status do Robô (ATIVO/INATIVO)</option>
                        <option value="l.AGENDAMENTO_TIPO">Tipo de Agendamento</option>
                    </optgroup>
                    <optgroup label="Resultados">
                        <option value="l.QTD_TOTAL">Total de CPFs</option>
                        <option value="l.QTD_PROCESSADA">Qtd. Processada</option>
                        <option value="l.QTD_SUCESSO">Qtd. Sucesso</option>
                        <option value="l.QTD_ERRO">Qtd. Erro</option>
                        <option value="l.PROCESSADOS_HOJE">Processados Hoje</option>
                        <option value="l.LIMITE_DIARIO">Limite Diário</option>
                    </optgroup>
                    <optgroup label="Datas">
                        <option value="l.DATA_IMPORTACAO">Data de Importação</option>
                        <option value="l.DATA_FINALIZACAO">Data de Finalização</option>
                        <option value="l.DATA_HORA_AGENDADA">Data/Hora Agendada</option>
                        <option value="l.ULTIMO_PROCESSAMENTO">Último Processamento</option>
                    </optgroup>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm border-dark regra-operador text-dark fw-bold">
                    <option value="contem">Contém</option>
                    <option value="nao_contem">Não contém</option>
                    <option value="comeca_com">Começa com</option>
                    <option value="igual">Exatamente igual a</option>
                    <option value="maior_que">Maior que</option>
                    <option value="menor_que">Menor que</option>
                    <option value="vazio">É Vazio</option>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" class="form-control form-control-sm border-dark regra-valor text-dark" placeholder="Valor do filtro...">
            </div>
            <div class="col-md-1 text-center">
                <button class="btn btn-sm btn-outline-danger w-100 fw-bold border-dark" onclick="this.closest('.regra-linha').remove()"><i class="fas fa-times"></i></button>
            </div>
        `;
        container.appendChild(row);
    }

    function v8LimparRegrasLote() {
        document.getElementById('container_regras_lote').innerHTML = '';
        v8CarregarLotesCSV();
    }

    function v8ObterRegrasLote() {
        let regras = [];
        document.querySelectorAll('.regra-linha').forEach(row => {
            let campo = row.querySelector('.regra-campo').value;
            let operador = row.querySelector('.regra-operador').value;
            let valor = row.querySelector('.regra-valor').value;
            if(operador === 'vazio' || valor.trim() !== '') {
                regras.push({campo: campo, operador: operador, valor: valor});
            }
        });
        return JSON.stringify(regras);
    }

    // --- CARREGAMENTO DA TABELA PRINCIPAL ---
    async function v8CarregarLotesCSV() { 
        if(isMenuAcoesAberto || document.querySelector('.dropdown-menu.show')) {
            isMenuAcoesAberto = true;
            return;
        }

        const tb = document.getElementById('v8_tbody_lotes'); 
        if(!tb) return;
        
        if(tb.innerHTML.indexOf('Carregando') !== -1) { 
            tb.innerHTML = '<tr><td colspan="6" class="text-center py-4"><i class="fas fa-spinner fa-spin"></i></td></tr>'; 
        }

        const payload = { regras: v8ObterRegrasLote() };

        const r = await v8Req('ajax_api_v8_lote_csv.php', 'listar_lotes', payload, false); 
        
        if (!r || !r.success) {
            tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger fw-bold">Erro ao carregar lotes: ' + (r?.msg || 'sem resposta do servidor') + '</td></tr>';
            console.error('[v8] listar_lotes falhou:', r);
            return;
        }
        if (r.success) {
            windowDadosLoteAtual = r.data;
            let newHtml = ''; let temRodando = false;
            if (r.data.length === 0) { tb.innerHTML = '<tr><td colspan="6" class="py-4 text-center text-muted fw-bold">Nenhum lote localizado com os filtros atuais.</td></tr>'; return; }

            try {
            r.data.forEach(l => {
                let badge = ''; let agendamentoInfo = ''; let btnPausarLi = '';
                
                if(l.AGENDAMENTO_TIPO === 'PROGRAMADO') agendamentoInfo = `<span class="badge bg-warning text-dark ms-1">⏳ ${l.DATA_HORA_AGENDADA || 'Imediato'}</span>`;
                if(l.AGENDAMENTO_TIPO === 'DIA_MES') agendamentoInfo = `<span class="badge bg-warning text-dark ms-1">⏳ Dia ${l.DIA_MES_AGENDADO || 'Atual'}</span>`;
                if(l.AGENDAMENTO_TIPO === 'DIARIO') {
                    let diasLabel = (!l.DIAS_MES_DIARIO || l.DIAS_MES_DIARIO === 'TODOS') ? 'todo dia' : 'dias ' + l.DIAS_MES_DIARIO;
                    let hIniD = (l.HORA_INICIO_DIARIO || '--:--').substring(0, 5);
                    let hFimD = (l.HORA_FIM_DIARIO || '').substring(0, 5);
                    let hFimLabel = hFimD ? ` até ${hFimD}` : '';
                    agendamentoInfo = `<span class="badge bg-warning text-dark ms-1">🔁 ${hIniD}${hFimLabel} (${diasLabel})</span>`;
                }
                if(l.LIMITE_DIARIO > 0) agendamentoInfo += `<span class="badge bg-info text-dark ms-1">Lmt: ${l.LIMITE_DIARIO} (Hj: ${l.PROCESSADOS_HOJE})</span>`;
                let hiIni = (l.HORA_INATIVACAO_INICIO || '').substring(0,5);
                let hiFim = (l.HORA_INATIVACAO_FIM    || '').substring(0,5);
                let emInativacao = false;
                if (hiIni && hiFim) {
                    let now = new Date(); let hNow = now.getHours()*100 + now.getMinutes();
                    let hIni = parseInt(hiIni.replace(':','')); let hFim = parseInt(hiFim.replace(':',''));
                    emInativacao = (hIni < hFim) ? (hNow >= hIni && hNow < hFim) : (hNow >= hIni || hNow < hFim);
                    agendamentoInfo += `<span class="badge ms-1 ${emInativacao ? 'bg-danger' : 'bg-secondary'}" title="Pausa obrigatória: ${hiIni} até ${hiFim}"><i class="fas fa-moon"></i> ${hiIni}–${hiFim}${emInativacao ? ' ⛔' : ''}</span>`;
                }

                let statusAtual = l.STATUS_FILA || l.status_fila || l.Status_Fila;
                if (!statusAtual) statusAtual = 'CRIANDO PROCESSAMENTO';
                statusAtual = String(statusAtual).toUpperCase();

                let idLoteReal = l.ID || l.id;

                if (statusAtual === 'AGUARDANDO_DIARIO') {
                    badge = `<span class="badge bg-info text-dark border border-dark"><i class="fas fa-clock me-1"></i> AGUARDANDO HORÁRIO</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Diário</button></li>`;
                } else if (statusAtual === 'PROCESSANDO') {
                    temRodando = true;
                    badge = `<span class="badge bg-primary shadow-sm"><i class="fas fa-cogs fa-spin"></i> PROCESSANDO</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Lote</button></li>`;
                } else if (statusAtual === 'CRIANDO PROCESSAMENTO') {
                    temRodando = true;
                    badge = `<span class="badge bg-warning text-dark shadow-sm border border-dark"><i class="fas fa-spinner fa-spin me-1"></i> CRIANDO PROCESSAMENTO</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Lote</button></li>`;
                } else if (statusAtual === 'NA FILA') {
                    temRodando = true;
                    badge = `<span class="badge bg-secondary text-white shadow-sm border border-dark"><i class="fas fa-list-ol me-1"></i> NA FILA</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Lote</button></li>`;
                } else if (statusAtual === 'PAUSADO') {
                    badge = `<span class="badge bg-warning text-dark"><i class="fas fa-pause-circle"></i> PAUSADO</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-primary" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR')"><i class="fas fa-play me-2"></i> Retomar Lote</button></li>`;
                } else if (statusAtual === 'PROCESSADO PARCIAL') {
                    badge = `<span class="badge bg-warning text-dark"><i class="fas fa-hand-paper"></i> PROCESSADO PARCIAL</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-primary" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR_LIMITE')"><i class="fas fa-forward me-2"></i> Continuar Hoje</button></li>`;
                } else if (statusAtual === 'CONCLUIDO') {
                    badge = `<span class="badge bg-success shadow-sm"><i class="fas fa-check-circle"></i> CONCLUIDO</span>`;
                } else if (statusAtual === 'ERRO CREDENCIAL') {
                    badge = `<span class="badge bg-danger"><i class="fas fa-key me-1"></i> ERRO CREDENCIAL</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-success" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR')"><i class="fas fa-redo me-2"></i> Religar após Corrigir</button></li>`;
                } else {
                    badge = `<span class="badge bg-danger">${statusAtual}</span>`;
                }

                let qtdTotalAtual = l.QTD_TOTAL || l.qtd_total || 0;
                let qtdProcessadaAtual = l.QTD_PROCESSADA || l.qtd_processada || 0;
                let pNum = qtdTotalAtual > 0 ? Math.round((qtdProcessadaAtual / qtdTotalAtual) * 100) : 0; 
                
                let f = l.funil || {c_ok:0, c_err:0, m_ok:0, m_err:0, s_ok:0, s_err:0, dataprev:0, c_hoje:0, m_hoje:0, s_hoje:0, na_fila:0};
                let c_ok = f.c_ok || 0; let c_err = f.c_err || 0;
                let m_ok = f.m_ok || 0; let m_err = f.m_err || 0;
                let s_ok = f.s_ok || 0; let s_err = f.s_err || 0;
                let dataprev = f.dataprev || 0;
                let na_fila = f.na_fila || 0;
                let c_hoje = f.c_hoje || 0; let m_hoje = f.m_hoje || 0; let s_hoje = f.s_hoje || 0;

                let htmlDataprev = dataprev > 0 ? `<div class="mb-1 text-danger fw-bold" style="font-size:10px;"><i class="fas fa-clock text-secondary" style="width:15px;"></i> Dataprev: ${dataprev}</div>` : '';
                let htmlNaFila = na_fila > 0 ? `<div class="mb-1 text-warning fw-bold" style="font-size:10px;"><i class="fas fa-hourglass-half text-secondary" style="width:15px;"></i> Na Fila: <b>${na_fila}</b></div>` : '';

                let funilHtml = `
                <div class="text-start d-inline-block" style="font-size: 11px; min-width: 155px;">
                    ${htmlDataprev}
                    ${htmlNaFila}
                    <div class="mb-1"><i class="fas fa-id-card text-secondary" style="width:15px;"></i> Consen.: <span class="text-success fw-bold">${c_ok}</span> / <span class="text-danger">${c_err}</span> <span class="badge bg-info text-dark ms-1" title="Hoje (desde 00h)">${c_hoje} hj</span></div>
                    <div class="mb-1"><i class="fas fa-search-dollar text-secondary" style="width:15px;"></i> Margem: <span class="text-success fw-bold">${m_ok}</span> / <span class="text-danger">${m_err}</span> <span class="badge bg-info text-dark ms-1" title="Hoje (desde 00h)">${m_hoje} hj</span></div>
                    <div class="mb-1"><i class="fas fa-calculator text-secondary" style="width:15px;"></i> Simul.: <span class="text-success fw-bold">${s_ok}</span> / <span class="text-danger">${s_err}</span> <span class="badge bg-info text-dark ms-1" title="Hoje (desde 00h)">${s_hoje} hj</span></div>
                    <div class="border-top pt-1 mt-1 text-primary"><i class="fas fa-users text-secondary" style="width:15px;"></i> Total: <b>${qtdTotalAtual}</b></div>
                </div>`;

                let nomeImportacao = l.NOME_IMPORTACAO || l.nome_importacao || 'LOTE';
                let dataBr = l.DATA_BR || l.data_br || '';
                
                let nomeUsuarioV8 = l.NOME_USUARIO || '--';
                let usernameApi = l.USERNAME_API || '--';
                let tabelaPadrao = l.TABELA_PADRAO || '--';
                let prazoPadrao = l.PRAZO_PADRAO || '--';

                let infoListaOrigem = `
                    <div class="text-start" style="font-size: 12px; line-height: 1.4;">
                        <div class="text-truncate mb-1" style="max-width: 300px;"><b>NOME:</b> ${nomeImportacao}</div>
                        <div class="text-truncate mb-1" style="max-width: 300px;"><b>USUÁRIO:</b> <span class="text-muted">${nomeUsuarioV8}</span></div>
                        <div class="text-truncate mb-1" style="max-width: 300px;"><b>Username API:</b> <span class="text-muted">${usernameApi}</span></div>
                        <div class="text-truncate mb-1" style="max-width: 300px;"><b>Tabela Padrão:</b> <span class="text-muted">${tabelaPadrao}</span></div>
                        <div class="text-truncate" style="max-width: 300px;"><b>Prazo Padrão:</b> <span class="text-muted">${prazoPadrao}x</span></div>
                    </div>
                `;

                let automacaoInfo = '';
                let flagTel = l.ATUALIZAR_TELEFONE || l.atualizar_telefone;
                let flagWpp = l.ENVIAR_WHATSAPP || l.enviar_whatsapp;
                let flagSimular = l.SOMENTE_SIMULAR || l.somente_simular;
                let flagFileWpp = l.ENVIAR_ARQUIVO_WHATSAPP || l.enviar_arquivo_whatsapp;
                
                if (flagTel == 1) { automacaoInfo += `<span class="badge bg-success ms-1 mt-1" title="Atualiza Telefones na Fator Conferi" data-bs-toggle="tooltip"><i class="fas fa-phone-alt"></i> FC</span>`; }
                if (flagWpp == 1) { automacaoInfo += `<span class="badge bg-success ms-1 mt-1" title="Avisa no WhatsApp via W-API" data-bs-toggle="tooltip"><i class="fab fa-whatsapp"></i> Aprov. W-API</span>`; }
                if (flagFileWpp == 1) { automacaoInfo += `<span class="badge bg-primary ms-1 mt-1" title="Envia Relatório Final no WhatsApp via W-API" data-bs-toggle="tooltip"><i class="fas fa-file-csv"></i> CSV W-API</span>`; }
                if (flagSimular == 1) { automacaoInfo += `<span class="badge bg-warning text-dark ms-1 mt-1" title="Pula Consentimento/Margem e vai direto para Simulação" data-bs-toggle="tooltip"><i class="fas fa-bolt"></i> Simulação Direta</span>`; }

                let infoLoteGeral = `
                    <div class="text-start" style="font-size: 11px; line-height: 1.6;">
                        <div class="mb-1 text-muted"><i class="far fa-calendar-alt"></i> ${dataBr}</div>
                        <div>${agendamentoInfo}</div>
                        <div>${automacaoInfo}</div>
                    </div>
                `;

                let statusLote = l.STATUS_LOTE || 'ATIVO';
                let corStatusLote = statusLote === 'ATIVO' ? 'text-success' : 'text-danger';
                let labelStatusLote = `<div class="mb-2 fw-bold ${corStatusLote}" style="font-size:11px;"><i class="fas fa-circle" style="font-size: 8px;"></i> ${statusLote}</div>`;

                // === NOVO MENU AÇÕES V8 LOTE ===
                let isDiario = (l.AGENDAMENTO_TIPO === 'DIARIO');
                let novoStatusTroca = statusLote === 'ATIVO' ? 'INATIVO' : 'ATIVO';
                let iconeStatusTroca = statusLote === 'ATIVO' ? '🔴' : '🟢';

                // Extrai o conteúdo do btnPausarLi sem o wrapper <li>
                // Para DIÁRIO: bloqueia Retomar manual (só pode pausar)
                let btnPausarBtnRaw = btnPausarLi ? btnPausarLi.replace(/^<li>/, '').replace(/<\/li>$/, '').trim() : '';
                let btnPausarBtn = btnPausarBtnRaw;
                // DIÁRIO pausado: exibe botão ativo para ligar (vai para AGUARDANDO_DIARIO)
                if (isDiario && statusAtual === 'PAUSADO') {
                    btnPausarBtn = `<button class="dropdown-item fw-bold text-success" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR')"><i class="fas fa-play me-2"></i> Ligar Robô</button>`;
                }
                // DIÁRIO aguardando horário: operador pode pausar ou ligar manualmente
                if (isDiario && statusAtual === 'AGUARDANDO_DIARIO') {
                    btnPausarBtn = `
                        <button class="dropdown-item fw-bold text-success" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR')"><i class="fas fa-play me-2"></i> Ligar Agora</button>
                        <button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Robô</button>`;
                }

                let btnTopEditar = '';
                if (!restricaoLoteEditar) {
                    btnTopEditar = `<button class="dropdown-item fw-bold text-primary" onclick="v8AbrirModalEditarLote(${idLoteReal})"><i class="fas fa-sliders-h me-2"></i> Ver Parâmetros</button>`;
                }

                let btnRodapeApagar = '';
                if (!restricaoLoteExcluir) {
                    btnRodapeApagar = `<button class="v8-btn-apagar" onclick="v8ExcluirLote(${idLoteReal})"><i class="fas fa-trash me-2"></i> 🗑️ Apagar Lote</button>`;
                }

                let menuAcoes = `
                <div class="btn-group dropstart w-100">
                  <button class="btn btn-sm btn-dark dropdown-toggle fw-bold shadow-sm w-100" type="button"
                          data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" onclick="markMenuAsOpen()">
                    <i class="fas fa-bars"></i> Ações
                  </button>
                  <div class="dropdown-menu shadow-lg p-0 v8-acoes-dropdown">

                    <div class="v8-top-actions">
                      ${btnPausarBtn}
                      <button class="dropdown-item fw-bold text-secondary" onclick="v8AlternarStatusLote(${idLoteReal}, '${novoStatusTroca}')">
                        <i class="fas fa-power-off me-2"></i>${iconeStatusTroca} Tornar ${novoStatusTroca}
                      </button>
                      ${btnTopEditar}
                      <button class="dropdown-item fw-bold text-success" onclick="v8AbrirModalAppendLote(${idLoteReal}, '${l.NOME_IMPORTACAO.replace(/'/g,"\\'")}')">
                        <i class="fas fa-plus-circle me-2"></i> Incluir Mais CPFs
                      </button>
                      <button class="dropdown-item fw-bold text-warning" onclick="v8AbrirModalAnotacoes(${idLoteReal})">
                        <i class="fas fa-sticky-note me-2"></i> Anotações do Lote
                      </button>
                    </div>

                    <!-- REPROCESSAR CONSENTIMENTO -->
                    <div class="v8-secao">
                      <div class="v8-secao-hdr v8-azul" onclick="v8ToggleSecaoLote(this); v8CarregarGruposReprocessamento(${idLoteReal}, this, 'consent'); event.stopPropagation();">
                        <span>📡 REPROCESSAR CONSENTIMENTO</span>
                        <i class="fas fa-chevron-down v8-chevron"></i>
                      </div>
                      <div class="v8-secao-bdy" style="display:none;" id="rep-consent-${idLoteReal}">
                        <div class="text-center text-muted py-2" style="font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                      </div>
                    </div>

                    <!-- REPROCESSAR MARGEM -->
                    <div class="v8-secao">
                      <div class="v8-secao-hdr v8-laranja" onclick="v8ToggleSecaoLote(this); v8CarregarGruposReprocessamento(${idLoteReal}, this, 'margem'); event.stopPropagation();">
                        <span>⚠️ REPROCESSAR MARGEM</span>
                        <i class="fas fa-chevron-down v8-chevron"></i>
                      </div>
                      <div class="v8-secao-bdy" style="display:none;" id="rep-margem-${idLoteReal}">
                        <div class="text-center text-muted py-2" style="font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
                      </div>
                      <div style="padding:4px 8px; border-top:1px solid #dee2e6;">
                        ${isDiario
                            ? `<button class="v8-item v8-manut w-100" style="margin:0; padding:7px 10px;" disabled>
                                <span class="v8-item-icon" style="font-size:14px;">🔁</span>
                                <span class="v8-item-txt"><strong style="font-size:11px;">Reprocessar Tudo</strong><small>🔒 Bloqueado para lotes Diários.</small></span>
                               </button>`
                            : `<button class="v8-item v8-item-laranja w-100" style="margin:0; padding:7px 10px;" onclick="v8ReprocessarTodos(${idLoteReal}); event.stopPropagation();">
                                <span class="v8-item-icon" style="font-size:14px;">🔁</span>
                                <span class="v8-item-txt"><strong style="font-size:11px;">Reprocessar Tudo</strong><small>Reinicia todos os CPFs do zero.</small></span>
                               </button>`
                        }
                      </div>
                    </div>

                    <!-- LISTAR CLIENTES -->
                    <div class="v8-secao">
                      <div class="v8-secao-hdr v8-roxo" onclick="v8ToggleSecaoLote(this); event.stopPropagation();">
                        <span>👥 LISTAR CLIENTES</span>
                        <i class="fas fa-chevron-down v8-chevron"></i>
                      </div>
                      <div class="v8-secao-bdy" style="display:none;">
                        <button class="v8-item v8-item-roxo" onclick="v8AbrirClientesLote(${idLoteReal}); event.stopPropagation();">
                          <span class="v8-item-icon">🔍</span>
                          <span class="v8-item-txt"><strong>Ver Clientes do Lote</strong><small>Lista todos os CPFs com status e acesso ao cadastro.</small></span>
                        </button>
                        <button class="v8-item" style="background:#0d6efd10; border:1px solid #0d6efd44;" onclick="v8EnviarLinkRelatorio(${idLoteReal}); event.stopPropagation();">
                          <span class="v8-item-icon">📤</span>
                          <span class="v8-item-txt"><strong style="color:#0d6efd;">ENVIO LINK RELATORIO</strong><small>Gera CSV (margem &gt; R$1,00) e envia link via W-API.</small></span>
                        </button>
                      </div>
                    </div>


                    ${btnRodapeApagar ? `<div class="v8-rodape-acoes">${btnRodapeApagar}</div>` : ''}
                  </div>
                </div>`;

                newHtml += `<tr class="bg-white border-bottom border-secondary" data-lote-id="${idLoteReal}">
                    <td class="align-middle fw-bold text-muted">${idLoteReal}</td>
                    <td class="align-middle">${infoListaOrigem}</td>
                    <td class="align-middle">${infoLoteGeral}</td>
                    <td class="align-middle fw-bold text-nowrap v8-td-status">${badge} <span class="v8-pct ms-2 small text-muted">${pNum}%</span></td>
                    <td class="align-middle fs-6 text-nowrap bg-light border-start border-end border-secondary v8-td-funil">${funilHtml}</td>
                    <td class="p-2 align-middle text-center" style="vertical-align: middle;">${labelStatusLote}${menuAcoes}</td>
                </tr>`;
            });
            } catch(errRender) {
                console.error('[v8] Erro ao renderizar lotes:', errRender);
                tb.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-danger fw-bold">Erro ao exibir lotes. Veja o console (F12).</td></tr>';
                return;
            }
            tb.innerHTML = newHtml;

            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]')); 
            tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl); }); 
            
            if(temRodando) {
                if(!intervaloLoteV8) intervaloLoteV8 = setInterval(v8AtualizarFunilCSV, 8000);
                if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo();
            } else {
                if(intervaloLoteV8) { clearInterval(intervaloLoteV8); intervaloLoteV8 = null; if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo(); }
            } 
        } else {
            tb.innerHTML = `<tr><td colspan="6" class="py-4 text-center text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> Erro: ${r.msg}</td></tr>`;
        }
    }

    function v8ToggleCsvHora(checked) {
        const wrap = document.getElementById('wrap_hora_csv');
        if (wrap) wrap.style.display = checked ? 'block' : 'none';
    }

    async function v8EnviarLinkRelatorio(id) {
        if (!confirm('Deseja gerar o relatório (margem > R$1,00) e enviar o link via W-API para o grupo do cliente?')) return;
        const r = await v8Req('ajax_api_v8_lote_csv.php', 'enviar_relatorio_whatsapp', { id_lote: id });
        if (r && r.success) {
            crmToast(r.msg || 'Relatório gerado e link enviado com sucesso!', 'success');
        } else {
            crmToast('Erro: ' + (r?.msg || 'Falha ao enviar o relatório.'), 'error');
        }
    }

    // Atualiza apenas Funil e % sem recriar a tabela (evita piscar)
    let _v8PctAnterior = {};
    async function v8AtualizarFunilCSV() {
        if (isMenuAcoesAberto || document.querySelector('.dropdown-menu.show')) return;
        const payload = { regras: v8ObterRegrasLote() };
        const r = await v8Req('ajax_api_v8_lote_csv.php', 'listar_lotes', payload, false);
        if (!r || !r.success) return;

        let temRodando = false;
        r.data.forEach(l => {
            let idLoteReal = l.ID || l.id;
            const tr = document.querySelector(`tr[data-lote-id="${idLoteReal}"]`);
            if (!tr) return;

            let statusAtual = String(l.STATUS_FILA || 'PENDENTE').toUpperCase();
            if (statusAtual === 'PROCESSANDO' || statusAtual === 'PENDENTE') temRodando = true;

            // Recalcula funil
            let qtdTotal = l.QTD_TOTAL || 0;
            let qtdProc  = l.QTD_PROCESSADA || 0;
            let pNum = qtdTotal > 0 ? Math.round((qtdProc / qtdTotal) * 100) : 0;

            let f = l.funil || {};
            let c_ok=f.c_ok||0, c_err=f.c_err||0, m_ok=f.m_ok||0, m_err=f.m_err||0,
                s_ok=f.s_ok||0, s_err=f.s_err||0, dataprev=f.dataprev||0, na_fila=f.na_fila||0,
                c_hoje=f.c_hoje||0, m_hoje=f.m_hoje||0, s_hoje=f.s_hoje||0;
            let htmlDataprev = dataprev > 0 ? `<div class="mb-1 text-danger fw-bold" style="font-size:10px;"><i class="fas fa-clock text-secondary" style="width:15px;"></i> Dataprev: ${dataprev}</div>` : '';
            let htmlNaFila   = na_fila   > 0 ? `<div class="mb-1 text-warning fw-bold" style="font-size:10px;"><i class="fas fa-hourglass-half text-secondary" style="width:15px;"></i> Na Fila: <b>${na_fila}</b></div>` : '';
            let funilHtml = `<div class="text-start d-inline-block" style="font-size:11px;min-width:155px;">
                ${htmlDataprev}${htmlNaFila}
                <div class="mb-1"><i class="fas fa-id-card text-secondary" style="width:15px;"></i> Consen.: <span class="text-success fw-bold">${c_ok}</span> / <span class="text-danger">${c_err}</span> <span class="badge bg-info text-dark ms-1">${c_hoje} hj</span></div>
                <div class="mb-1"><i class="fas fa-search-dollar text-secondary" style="width:15px;"></i> Margem: <span class="text-success fw-bold">${m_ok}</span> / <span class="text-danger">${m_err}</span> <span class="badge bg-info text-dark ms-1">${m_hoje} hj</span></div>
                <div class="mb-1"><i class="fas fa-calculator text-secondary" style="width:15px;"></i> Simul.: <span class="text-success fw-bold">${s_ok}</span> / <span class="text-danger">${s_err}</span> <span class="badge bg-info text-dark ms-1">${s_hoje} hj</span></div>
                <div class="border-top pt-1 mt-1 text-primary"><i class="fas fa-users text-secondary" style="width:15px;"></i> Total: <b>${qtdTotal}</b></div>
            </div>`;

            // Atualiza célula Funil sem piscar
            const tdFunil = tr.querySelector('.v8-td-funil');
            if (tdFunil) tdFunil.innerHTML = funilHtml;

            // Badge sempre atualiza (status muda independente do %)
            // % só atualiza quando muda ≥5 pontos percentuais
            const tdStatus = tr.querySelector('.v8-td-status');
            if (tdStatus) {
                let badge = '';
                if (statusAtual === 'AGUARDANDO_DIARIO')       badge = `<span class="badge bg-info text-dark border border-dark"><i class="fas fa-clock me-1"></i> AGUARDANDO HORÁRIO</span>`;
                else if (statusAtual === 'PROCESSANDO')         badge = `<span class="badge bg-primary shadow-sm"><i class="fas fa-cogs fa-spin"></i> PROCESSANDO</span>`;
                else if (statusAtual === 'CRIANDO PROCESSAMENTO') badge = `<span class="badge bg-warning text-dark shadow-sm border border-dark"><i class="fas fa-spinner fa-spin me-1"></i> CRIANDO PROCESSAMENTO</span>`;
                else if (statusAtual === 'NA FILA')             badge = `<span class="badge bg-secondary text-white shadow-sm border border-dark"><i class="fas fa-list-ol me-1"></i> NA FILA</span>`;
                else if (statusAtual === 'PAUSADO')             badge = `<span class="badge bg-warning text-dark"><i class="fas fa-pause-circle"></i> PAUSADO</span>`;
                else if (statusAtual === 'PROCESSADO PARCIAL') badge = `<span class="badge bg-warning text-dark"><i class="fas fa-hand-paper"></i> PROCESSADO PARCIAL</span>`;
                else if (statusAtual === 'CONCLUIDO')    badge = `<span class="badge bg-success shadow-sm"><i class="fas fa-check-circle"></i> CONCLUIDO</span>`;
                else if (statusAtual === 'ERRO CREDENCIAL') badge = `<span class="badge bg-danger"><i class="fas fa-key me-1"></i> ERRO CREDENCIAL</span>`;
                else badge = `<span class="badge bg-danger">${statusAtual}</span>`;

                // Atualiza badge
                const badgeEl = tdStatus.querySelector('.badge');
                if (badgeEl) badgeEl.outerHTML = badge;
                else tdStatus.insertAdjacentHTML('afterbegin', badge + ' ');

                // Atualiza % somente se mudou ≥5 pontos
                let lastPct = (_v8PctAnterior[idLoteReal] !== undefined) ? _v8PctAnterior[idLoteReal] : -99;
                if (Math.abs(pNum - lastPct) >= 5 || lastPct === -99) {
                    _v8PctAnterior[idLoteReal] = pNum;
                    const pctEl = tdStatus.querySelector('.v8-pct');
                    if (pctEl) pctEl.textContent = pNum + '%';
                }
            }
        });

        if (!temRodando && intervaloLoteV8) {
            clearInterval(intervaloLoteV8);
            intervaloLoteV8 = null;
            v8CarregarLotesCSV(); // reload completo ao finalizar
        }
    }

    // --- CONFIRM MODAL HELPER ---
    function v8Confirmar(titulo, descricao, corBtn, corBorda, callback) {
        document.getElementById('v8ConfirmTitulo').innerHTML = titulo;
        document.getElementById('v8ConfirmDescricao').innerHTML = descricao;
        const btnOk = document.getElementById('v8ConfirmBtnOk');
        btnOk.className = 'btn btn-sm fw-bold px-3 ' + corBtn;
        document.getElementById('v8ConfirmHdr').style.borderLeftColor = corBorda;
        btnOk.onclick = () => { v8ModalConfirmObj.hide(); callback(); };
        v8ModalConfirmObj.show();
    }

    // --- TOGGLE ACCORDION DO MENU ---
    function v8ToggleSecaoLote(hdr) {
        const body = hdr.nextElementSibling;
        const chevron = hdr.querySelector('.v8-chevron');
        const aberto = body.style.display !== 'none';
        body.style.display = aberto ? 'none' : 'block';
        chevron.classList.toggle('aberto', !aberto);
    }

    // --- FUNÇÕES DE AÇÃO DO LOTE ---
    async function v8AlternarStatusLote(idLote, novoStatus) {
        const desc = novoStatus === 'INATIVO'
            ? 'O lote ficará invisível e não será processado até ser reativado.'
            : 'O lote voltará a aparecer e poderá ser processado normalmente.';
        const cor = novoStatus === 'INATIVO' ? 'btn-warning' : 'btn-success';
        const borda = novoStatus === 'INATIVO' ? '#ffc107' : '#198754';
        v8Confirmar(`🔌 Tornar ${novoStatus}`, desc, cor, borda, async () => {
            const res = await v8Req('ajax_api_v8_lote_csv.php', 'alternar_status_lote', { id_lote: idLote, novo_status: novoStatus }, true, "Alterando Status...");
            if(res.success) { v8CarregarLotesCSV(); } else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
        });
    }

    let _editLoteStatusAtual = 'PAUSADO';

    async function v8AbrirModalEditarLote(id) {
        let lote = windowDadosLoteAtual.find(l => l.ID == id || l.id == id);
        if (!lote) return v8Toast("Erro: Lote não encontrado na tela.", "error", 5000);

        _editLoteStatusAtual = String(lote.STATUS_FILA || 'PAUSADO').toUpperCase();
        const emProcessamento = !['PAUSADO', 'CONCLUIDO', 'AGUARDANDO_DIARIO', 'AGUARDANDO HORÁRIO'].includes(_editLoteStatusAtual);
        const aviso = document.getElementById('edit_lote_aviso_processando');
        const btnSalvar = document.getElementById('btn_salvar_edicao_lote');
        if (emProcessamento) {
            aviso.classList.remove('d-none');
            btnSalvar.disabled = true;
            btnSalvar.classList.replace('btn-success', 'btn-secondary');
        } else {
            aviso.classList.add('d-none');
            btnSalvar.disabled = false;
            btnSalvar.classList.replace('btn-secondary', 'btn-success');
        }

        document.getElementById('edit_lote_id').value = id;
        document.getElementById('edit_lote_agrupamento').value = lote.NOME_IMPORTACAO || lote.nome_importacao;

        document.getElementById('edit_hora_inicio_diario').value = (lote.HORA_INICIO_DIARIO || lote.hora_inicio_diario || '').substring(0, 5);
        document.getElementById('edit_hora_fim_diario').value = (lote.HORA_FIM_DIARIO || lote.hora_fim_diario || '').substring(0, 5);
        v8CarregarDiasEdit(lote.DIAS_MES_DIARIO || lote.dias_mes_diario || 'TODOS');
        document.getElementById('edit_limite_diario').value = lote.LIMITE_DIARIO || lote.limite_diario || 0;

        document.getElementById('edit_somente_simular').checked = (lote.SOMENTE_SIMULAR == 1 || lote.somente_simular == 1);
        document.getElementById('edit_atualizar_telefone').checked = (lote.ATUALIZAR_TELEFONE == 1 || lote.atualizar_telefone == 1);
        document.getElementById('edit_enviar_whats').checked = (lote.ENVIAR_WHATSAPP == 1 || lote.enviar_whatsapp == 1);
        let csvAtivo = (lote.ENVIAR_ARQUIVO_WHATSAPP == 1 || lote.enviar_arquivo_whatsapp == 1);
        document.getElementById('edit_enviar_arquivo_whatsapp').checked = csvAtivo;
        document.getElementById('edit_hora_envio_csv').value = (lote.HORA_ENVIO_CSV || lote.hora_envio_csv || '').substring(0,5);
        v8ToggleCsvHora(csvAtivo);
        document.getElementById('edit_aviso_status_wapi').checked = (lote.AVISO_STATUS_WAPI == 1 || lote.aviso_status_wapi == 1);

        // Campanha automática
        const idCampSalvo = parseInt(lote.ID_CAMPANHA_AUTO || lote.id_campanha_auto || 0);
        document.getElementById('edit_valor_margem_min_campanha').value = lote.VALOR_MARGEM_MIN_CAMPANHA || lote.valor_margem_min_campanha || '';

        // Carrega campanhas disponíveis e seleciona a salva
        const selCamp = document.getElementById('edit_id_campanha_auto');
        selCamp.innerHTML = '<option value="">— Carregando... —</option>';
        const resCamp = await fetch('ajax_api_v8_lote_csv.php', { method:'POST', body: (() => { const f=new FormData(); f.append('acao','listar_campanhas_disponiveis'); return f; })() }).then(r=>r.json()).catch(()=>null);
        selCamp.innerHTML = '<option value="">— Nenhuma —</option>';
        if (resCamp && resCamp.success) {
            resCamp.campanhas.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.ID; opt.textContent = c.NOME_CAMPANHA;
                if (parseInt(c.ID) === idCampSalvo) opt.selected = true;
                selCamp.appendChild(opt);
            });
        }

        modalEditarLoteObj.show();
    }

    async function v8PausarParaSalvar() {
        const id = document.getElementById('edit_lote_id').value;
        const btn = document.querySelector('#edit_lote_aviso_processando button');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Pausando...';
        btn.disabled = true;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'pausar_retomar_lote', { id_lote: id, acao_lote: 'PAUSAR' }, false);
        if (res && res.success) {
            document.getElementById('edit_lote_aviso_processando').classList.add('d-none');
            const btnSalvar = document.getElementById('btn_salvar_edicao_lote');
            btnSalvar.disabled = false;
            btnSalvar.classList.replace('btn-secondary', 'btn-success');
            v8Toast("Lote pausado. Agora você pode salvar.", "success", 3000);
        } else {
            btn.innerHTML = '<i class="fas fa-pause me-1"></i> Pausar e Salvar';
            btn.disabled = false;
            v8Toast("Erro ao pausar o lote.", "error", 4000);
        }
    }

    async function v8SalvarEdicaoLote() {
        if (!document.getElementById('edit_hora_inicio_diario').value) {
            return v8Toast("Informe o horário de início.", "warning", 5000);
        }
        if (!document.getElementById('edit_hora_fim_diario').value) {
            return v8Toast("Informe o horário de fim.", "warning", 5000);
        }
        let payload = {
            id_lote: document.getElementById('edit_lote_id').value,
            agrupamento: document.getElementById('edit_lote_agrupamento').value,
            agendamento_tipo: 'DIARIO',
            hora_inicio_diario: document.getElementById('edit_hora_inicio_diario').value,
            hora_fim_diario: document.getElementById('edit_hora_fim_diario').value,
            dias_mes_diario: document.getElementById('edit_dias_mes_diario').value,
            limite_diario: document.getElementById('edit_limite_diario').value,
            somente_simular: document.getElementById('edit_somente_simular').checked ? 1 : 0,
            atualizar_telefone: document.getElementById('edit_atualizar_telefone').checked ? 1 : 0,
            enviar_whats: document.getElementById('edit_enviar_whats').checked ? 1 : 0,
            enviar_arquivo_whatsapp: document.getElementById('edit_enviar_arquivo_whatsapp').checked ? 1 : 0,
            hora_envio_csv: document.getElementById('edit_hora_envio_csv').value || '',
            aviso_status_wapi: document.getElementById('edit_aviso_status_wapi').checked ? 1 : 0,
            id_campanha_auto: document.getElementById('edit_id_campanha_auto').value || 0,
            valor_margem_min_campanha: document.getElementById('edit_valor_margem_min_campanha').value || 0
        };

        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_edicao_lote', payload, true, "Salvando Edição...");
        if(res.success) { v8Toast("✅ " + res.msg, "success"); modalEditarLoteObj.hide(); v8CarregarLotesCSV(); }
        else { v8Toast("❌ " + (res.msg||"Erro ao salvar"), "error", 6000); }
    }

    async function v8EnviarRelatorioLoteWhats(id) {
        v8Confirmar('📱 Disparar via Grupo Whats',
            'Gera o arquivo CSV deste lote e envia agora mesmo no grupo WhatsApp da equipe.',
            'btn-success', '#198754',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'enviar_relatorio_whatsapp', { id_lote: id }, true, "Gerando e Enviando...");
                if(res.success) { v8Toast("✅ " + res.msg, "success"); } else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
            });
    }

    // Cache para não recarregar sem necessidade
    const v8GruposCache = {};

    // Cache compartilhado: { idLote: { grupos: [...], ts: timestamp } }
    const v8GruposDataCache = {};

    async function v8CarregarGruposReprocessamento(id, hdrEl, tipo) {
        const bdyId = tipo === 'consent' ? 'rep-consent-' + id : 'rep-margem-' + id;
        const bdy = document.getElementById(bdyId);
        if (!bdy) return;

        // v8ToggleSecaoLote já rodou — display !== 'none' = seção acabou de abrir
        if (bdy.style.display === 'none') return;

        bdy.innerHTML = '<div class="text-center text-muted py-2" style="font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>';

        // Usa cache por 30s para não chamar o servidor toda vez
        const agora = Date.now();
        if (!v8GruposDataCache[id] || (agora - v8GruposDataCache[id].ts) > 30000) {
            const res = await v8Req('ajax_api_v8_lote_csv.php', 'listar_grupos_reprocessamento', { id_lote: id }, false);
            if (!res.success) {
                bdy.innerHTML = '<div class="text-danger text-center py-2" style="font-size:12px;">Erro ao carregar.</div>';
                return;
            }
            v8GruposDataCache[id] = { grupos: res.grupos, ts: agora };
        }

        const todosGrupos = v8GruposDataCache[id].grupos || [];

        // Filtra por tipo
        const grupos = tipo === 'consent'
            ? todosGrupos.filter(g => g.STATUS_V8 === 'AGUARDANDO DATAPREV')
            : todosGrupos.filter(g => g.STATUS_V8 !== 'AGUARDANDO DATAPREV');

        if (grupos.length === 0) {
            bdy.innerHTML = '<div class="text-muted text-center py-2" style="font-size:12px;">Nenhum registro pendente.</div>';
            return;
        }

        const icones = {
            'AGUARDANDO DATAPREV': '📡',
            'CANCELADO':           '🚫',
            'REJEITADO':           '❌',
            'ERRO MARGEM':         '⚠️',
            'ERRO SIMULACAO':      '🔢',
            'ERRO CONSULTA':       '🔍',
        };
        const btnColor = tipo === 'consent' ? 'btn-outline-primary' : 'btn-outline-warning';

        let html = '';
        grupos.forEach(g => {
            const icone = icones[g.STATUS_V8] || '⚠️';
            const obsEnc = encodeURIComponent(g.OBSERVACAO || '');
            const motivoLabel = g.OBSERVACAO || '';
            const tooltipFull = g.STATUS_V8 + (motivoLabel ? ' — ' + motivoLabel : '');
            html += `
              <div style="display:flex; align-items:center; gap:8px; padding:6px 10px; border-bottom:1px solid #eee;">
                <span style="flex:0 0 20px; font-size:14px; text-align:center;">${icone}</span>
                <span style="flex:1; min-width:0; line-height:1.35;" title="${tooltipFull.replace(/"/g,'&quot;')}">
                  <span style="display:block; font-size:11px; font-weight:700; color:#212529; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <span style="color:#888; font-weight:400;">(${g.total}/${g.hoje})&nbsp;</span>${g.STATUS_V8}
                  </span>
                  ${motivoLabel ? `<span style="display:block; font-size:10px; color:#555; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="${motivoLabel.replace(/"/g,'&quot;')}">${motivoLabel}</span>` : ''}
                </span>
                <button class="btn ${btnColor} fw-bold" style="flex:0 0 auto; font-size:10px; padding:2px 9px; white-space:nowrap; border-radius:4px;"
                  onclick="v8ReprocessarGrupo(${id}, '${g.STATUS_V8}', decodeURIComponent('${obsEnc}')); event.stopPropagation();">
                  ▶ Reprocessar
                </button>
              </div>`;
        });
        bdy.innerHTML = html;
    }

    // === LISTAR CLIENTES DO LOTE ===
    let v8ClientesTodos = [];
    let v8ClientesFiltrados = [];
    let v8CampanhaIdSel = null;
    let v8CampanhaNomeSel = '';
    let v8CampanhasCache = null;
    let v8ClientesLoteAtual = null;

    function v8AbrirClientesLote(id) {
        window.open('clientes_lote.php?id_lote=' + id, '_blank');
    }

    function v8FiltrarClientesLote() {
        const status  = document.getElementById('v8ClientesStatus').value;
        const t       = (document.getElementById('v8ClientesFiltro').value || '').toLowerCase();
        const dataDe  = document.getElementById('v8ClientesDataDe').value;   // 'YYYY-MM-DD' ou ''
        const dataAte = document.getElementById('v8ClientesDataAte').value;
        v8ClientesFiltrados = v8ClientesTodos.filter(c => {
            const matchTermo  = !t || c.CPF_FORMATADO.includes(t) || (c.NOME || '').toLowerCase().includes(t);
            const matchStatus = !status || c.STATUS_V8 === status;
            const dataCliente = c.DATA_SIM_DATE || '';
            const matchDe     = !dataDe  || dataCliente >= dataDe;
            const matchAte    = !dataAte || dataCliente <= dataAte;
            return matchTermo && matchStatus && matchDe && matchAte;
        });
        v8RenderizarClientesLote(v8ClientesFiltrados);
        if (v8CampanhaIdSel) {
            document.getElementById('v8CampanhaQtdSel').textContent = v8ClientesFiltrados.length;
        }
    }

    // === CAMPANHA ===
    async function v8CarregarCampanhasDropdown() {
        const ul = document.getElementById('v8CampanhaLista');
        if (v8CampanhasCache) { v8RenderizarCampanhasDropdown(v8CampanhasCache); return; }
        ul.innerHTML = '<li><span class="dropdown-item text-muted"><i class="fas fa-spinner fa-spin"></i> Carregando...</span></li>';
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'listar_campanhas_disponiveis', {}, false);
        if (!res.success || !res.campanhas.length) {
            ul.innerHTML = '<li><span class="dropdown-item text-muted">Nenhuma campanha disponível.</span></li>';
            return;
        }
        v8CampanhasCache = res.campanhas;
        v8RenderizarCampanhasDropdown(res.campanhas);
    }

    function v8RenderizarCampanhasDropdown(campanhas) {
        const ul = document.getElementById('v8CampanhaLista');
        ul.innerHTML = '<li><h6 class="dropdown-header">Selecione a campanha:</h6></li>';
        campanhas.forEach(c => {
            ul.innerHTML += `<li><button class="dropdown-item" onclick="v8SelecionarCampanha(${c.ID}, '${c.NOME_CAMPANHA.replace(/'/g,"\\'")}')">
                <i class="fas fa-bullhorn me-2 text-purple" style="color:#6f42c1;"></i>${c.NOME_CAMPANHA}
            </button></li>`;
        });
    }

    function v8SelecionarCampanha(id, nome) {
        v8CampanhaIdSel = id;
        v8CampanhaNomeSel = nome;
        // Fecha dropdown
        const dropdownEl = document.getElementById('v8CampanhaDropdown').querySelector('[data-bs-toggle="dropdown"]');
        bootstrap.Dropdown.getInstance(dropdownEl)?.hide();
        // Calcula quantos serão incluídos (marcados ou todos filtrados)
        const marcados = document.querySelectorAll('.v8-check-cliente:checked').length;
        const qtd = marcados > 0 ? marcados : (v8ClientesFiltrados.length || v8ClientesTodos.length);
        // Mostra barra de confirmação
        document.getElementById('v8CampanhaNomeSel').textContent = nome;
        document.getElementById('v8CampanhaQtdSel').textContent = qtd;
        document.getElementById('v8CampanhaSelecionadaBar').style.display = '';
    }

    function v8CancelarCampanha() {
        v8CampanhaIdSel = null;
        document.getElementById('v8CampanhaSelecionadaBar').style.display = 'none';
    }

    async function v8ExecutarIncluirCampanha() {
        // Prioridade: marcados individualmente → senão, todos filtrados
        const marcados = [...document.querySelectorAll('.v8-check-cliente:checked')].map(cb => cb.dataset.cpf);
        const lista = marcados.length > 0 ? marcados
            : (v8ClientesFiltrados.length ? v8ClientesFiltrados.map(c => c.CPF) : v8ClientesTodos.map(c => c.CPF));
        if (!lista.length) return v8Toast("Nenhum cliente na tela para incluir.", "warning");
        const cpfs = lista;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'incluir_em_campanha',
            { id_campanha: v8CampanhaIdSel, cpfs: JSON.stringify(cpfs), id_lote: v8ClientesLoteAtual }, true, "Incluindo...");
        if (res.success) {
            v8Toast(`✅ ${res.msg}`, "success");
            document.getElementById('v8CampanhaSelecionadaBar').style.display = 'none';
            v8CampanhaIdSel = null;
        } else {
            v8Toast("❌ " + (res.msg || "Erro"), "error");
        }
    }

    // ====================================================================
    // AUDITORIA V8 — funções do modal de clientes
    // ====================================================================
    function v8IniciarAuditoria() {
        // Segurança extra: barra não existe no DOM para grupos bloqueados
        if (!document.getElementById('v8AuditoriaBar')) return;
        const marcados = [...document.querySelectorAll('.v8-check-cliente:checked')].map(cb => cb.dataset.cpf);
        const lista = marcados.length > 0 ? marcados
            : (v8ClientesFiltrados.length ? v8ClientesFiltrados.map(c => c.CPF) : v8ClientesTodos.map(c => c.CPF));
        if (!lista.length) return v8Toast("Nenhum cliente selecionado para auditoria.", "warning");
        // Oculta barra de campanha se aberta
        document.getElementById('v8CampanhaSelecionadaBar').style.display = 'none';
        // Mostra barra de auditoria
        document.getElementById('v8AuditoriaQtd').textContent = lista.length;
        document.getElementById('v8AuditoriaBar').style.display = 'flex';
    }

    function v8CancelarAuditoria() {
        document.getElementById('v8AuditoriaBar').style.display = 'none';
    }

    async function v8ExecutarAuditoria() {
        const marcados = [...document.querySelectorAll('.v8-check-cliente:checked')].map(cb => cb.dataset.cpf);
        const lista = marcados.length > 0 ? marcados
            : (v8ClientesFiltrados.length ? v8ClientesFiltrados.map(c => c.CPF) : v8ClientesTodos.map(c => c.CPF));
        if (!lista.length) return v8Toast("Nenhum cliente para transferir.", "warning");

        document.getElementById('v8AuditoriaBar').style.display = 'none';

        const res = await v8Req('ajax_api_v8_lote_csv.php', 'incluir_em_auditoria',
            { id_lote: v8ClientesLoteAtual, cpfs: JSON.stringify(lista) }, true, "Transferindo para Auditoria...");

        if (res.success) {
            v8Toast(`🛡️ ${res.msg} Os registros não aparecem mais neste lote.`, "success");
            // Recarrega lista removendo os auditados
            await v8AbrirClientesLote(v8ClientesLoteAtual);
        } else {
            v8Toast("❌ " + (res.msg || "Erro ao executar auditoria."), "error");
        }
    }
    // ====================================================================

    const V8_CLIENTES_PAGE = 100;
    let v8ClientesOffset = 0;
    let v8ClientesListaAtual = [];

    const badgeStatusClientes = {
        'OK':                  'success',
        'ERRO MARGEM':         'danger',
        'ERRO SIMULACAO':      'danger',
        'ERRO CONSULTA':       'danger',
        'AGUARDANDO DATAPREV': 'primary',
        'AGUARDANDO MARGEM':   'info',
        'AGUARDANDO SIMULACAO':'info',
        'NA FILA':             'secondary',
        'RECUPERAR V8':        'warning',
    };

    function v8GerarLinhaCliente(c) {
        const cor       = badgeStatusClientes[c.STATUS_V8] || 'secondary';
        const margem    = c.VALOR_MARGEM  ? 'R$ ' + parseFloat(c.VALOR_MARGEM).toFixed(2)  : '—';
        const simulacao = c.VALOR_LIQUIDO ? 'R$ ' + parseFloat(c.VALOR_LIQUIDO).toFixed(2) : '—';
        const obs       = c.OBSERVACAO || '';
        const obsTitle  = obs ? ` title="${obs.replace(/"/g,'&quot;')}"` : '';
        const obsExibir = obs.length > 50 ? obs.substring(0,50)+'…' : obs;
        const cpfEsc    = c.CPF.replace(/\D/g,'');
        return `<tr onclick="v8ToggleCheckCliente(this)" style="cursor:pointer;">
          <td style="width:32px; text-align:center;" onclick="event.stopPropagation(); v8ToggleCheckCliente(this.closest('tr'))">
            <input type="checkbox" class="v8-check-cliente form-check-input" data-cpf="${cpfEsc}" style="cursor:pointer;">
          </td>
          <td class="fw-bold" style="white-space:nowrap;">${c.CPF_FORMATADO}</td>
          <td>${c.NOME || '—'}</td>
          <td style="white-space:nowrap;"><span class="badge bg-${cor}">${c.STATUS_V8}</span></td>
          <td style="font-size:10px; color:#555; max-width:180px;"${obsTitle}>${obsExibir || '—'}</td>
          <td class="text-success fw-bold" style="white-space:nowrap;">${margem}</td>
          <td class="text-primary fw-bold" style="white-space:nowrap;">${simulacao}</td>
          <td style="white-space:nowrap; color:#777;">${c.DATA_SIM_DISPLAY || '—'}</td>
          <td onclick="event.stopPropagation()"><a href="/modulos/banco_dados/consulta.php?busca=${c.CPF}&cpf_selecionado=${c.CPF}&acao=visualizar" target="_blank"
                 class="btn fw-bold" style="font-size:10px; padding:1px 7px; border:1px solid #6f42c1; border-radius:4px; color:#6f42c1;">Ver</a></td>
        </tr>`;
    }

    function v8ToggleCheckCliente(tr) {
        const cb = tr.querySelector('.v8-check-cliente');
        if (!cb) return;
        cb.checked = !cb.checked;
        tr.style.background = cb.checked ? '#f0ebff' : '';
        v8AtualizarSelecaoContador();
    }

    function v8AtualizarSelecaoContador() {
        const marcados = document.querySelectorAll('.v8-check-cliente:checked').length;
        const total    = document.querySelectorAll('.v8-check-cliente').length;
        // Atualiza checkbox do header
        const cbAll = document.getElementById('v8CheckTodos');
        if (cbAll) {
            cbAll.indeterminate = marcados > 0 && marcados < total;
            cbAll.checked = total > 0 && marcados === total;
        }
        // Atualiza barra de campanha
        if (v8CampanhaIdSel) {
            const qtd = marcados > 0 ? marcados : v8ClientesFiltrados.length;
            document.getElementById('v8CampanhaQtdSel').textContent = qtd;
        }
        // Atualiza contador geral
        const txt = marcados > 0
            ? `${v8ClientesFiltrados.length} registro(s) — <strong>${marcados} selecionado(s)</strong>`
            : `${v8ClientesFiltrados.length} registro(s)`;
        document.getElementById('v8ClientesContador').innerHTML = txt;
    }

    function v8ToggleTodosClientes(cb) {
        document.querySelectorAll('.v8-check-cliente').forEach(c => {
            c.checked = cb.checked;
            const tr = c.closest('tr');
            if (tr) tr.style.background = cb.checked ? '#f0ebff' : '';
        });
        v8AtualizarSelecaoContador();
    }

    function v8RenderizarClientesLote(lista) {
        v8ClientesListaAtual = lista;
        v8ClientesOffset = 0;
        const tb = document.getElementById('v8ClientesTabela');
        document.getElementById('v8ClientesContador').innerHTML = lista.length + ' registro(s)';
        document.getElementById('v8CampanhaDropdown').style.display = lista.length ? '' : 'none';
        const btnAud = document.getElementById('v8BtnAuditoria');
        if (btnAud) btnAud.style.display = lista.length ? '' : 'none';

        if (lista.length === 0) {
            tb.innerHTML = '<div class="text-muted text-center py-4">Nenhum cliente encontrado.</div>';
            return;
        }

        const pagina = lista.slice(0, V8_CLIENTES_PAGE);
        v8ClientesOffset = pagina.length;

        let html = `<table class="table table-sm table-hover mb-0" style="font-size:12px;">
          <thead class="table-dark sticky-top">
            <tr>
              <th style="width:32px; text-align:center;">
                <input type="checkbox" id="v8CheckTodos" class="form-check-input" title="Selecionar todos visíveis"
                       onchange="v8ToggleTodosClientes(this)" style="cursor:pointer;">
              </th>
              <th style="width:120px;">CPF</th>
              <th>Nome</th>
              <th style="width:150px;">Status</th>
              <th style="width:190px;">Observação</th>
              <th style="width:90px;">Margem</th>
              <th style="width:90px;">Simulação</th>
              <th style="width:120px;">Data Simulação</th>
              <th style="width:55px;">Ação</th>
            </tr>
          </thead>
          <tbody id="v8ClientesTbody">${pagina.map(v8GerarLinhaCliente).join('')}</tbody>
        </table>`;

        if (lista.length > V8_CLIENTES_PAGE) {
            const restantes = lista.length - V8_CLIENTES_PAGE;
            html += `<div id="v8ClientesBtnMais" class="text-center py-2 border-top" style="background:#f8f9fa;">
                <button class="btn btn-sm btn-outline-secondary fw-bold" style="font-size:12px;" onclick="v8CarregarMaisClientes()">
                  <i class="fas fa-chevron-down me-1"></i> Carregar mais 100
                  <span class="badge bg-secondary ms-1">${restantes} restantes</span>
                </button>
            </div>`;
        }

        tb.innerHTML = html;
    }

    function v8CarregarMaisClientes() {
        const prox = v8ClientesListaAtual.slice(v8ClientesOffset, v8ClientesOffset + V8_CLIENTES_PAGE);
        v8ClientesOffset += prox.length;
        const tbody = document.getElementById('v8ClientesTbody');
        if (tbody) tbody.innerHTML += prox.map(v8GerarLinhaCliente).join('');
        const restantes = v8ClientesListaAtual.length - v8ClientesOffset;
        const btnDiv = document.getElementById('v8ClientesBtnMais');
        if (btnDiv) {
            if (restantes > 0) {
                btnDiv.querySelector('button').innerHTML = `<i class="fas fa-chevron-down me-1"></i> Carregar mais 100 <span class="badge bg-secondary ms-1">${restantes} restantes</span>`;
            } else {
                btnDiv.remove();
            }
        }
    }

    async function v8ReprocessarGrupo(id, statusV8, observacao) {
        const labelStatus = observacao ? statusV8 + ' — ' + observacao : statusV8;
        v8Confirmar('🔄 Reprocessar Grupo',
            `Reprocessar todos os CPFs com:<br><br>
            <strong>${labelStatus}</strong><br><br>
            <span class="text-success fw-bold">✅ Sem novo custo</span> para AGUARDANDO DATAPREV e ERROS.<br>
            <span class="text-warning fw-bold">⚠️ Cria novo consentimento</span> para CANCELADO e REJEITADO.`,
            'btn-primary', '#1a73e8',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_grupo',
                    { id_lote: id, status_v8: statusV8, observacao: observacao }, true, "Reprocessando...");
                if (res.success) {
                    v8Toast("✅ " + res.msg, "success");
                    if (res.cpf_dono) v8AcordarRobo(res.cpf_dono);
                    // Invalida cache e recarrega a lista
                    delete v8GruposDataCache[id];
                    const bdyC = document.getElementById('rep-consent-' + id);
                    const bdyM = document.getElementById('rep-margem-' + id);
                    if (bdyC && bdyC.style.display !== 'none') bdyC.innerHTML = '<div class="text-center text-muted py-2" style="font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Atualizando...</div>';
                    if (bdyM && bdyM.style.display !== 'none') bdyM.innerHTML = '<div class="text-center text-muted py-2" style="font-size:12px;"><i class="fas fa-spinner fa-spin"></i> Atualizando...</div>';
                    setTimeout(() => v8CarregarLotesCSV(), 1500);
                } else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
            });
    }

    async function v8ReprocessarConsentimento(id) {
        const lote = (windowDadosLoteAtual || []).find(l => l.ID == id);
        const qtd = lote ? (lote.funil?.dataprev || '?') : '?';
        v8Confirmar('📡 Reprocessar Consentimento',
            `<strong>${qtd} CPF(s)</strong> estão aguardando retorno da Dataprev.<br><br>
            Esta ação irá <strong>re-consultar o consentimento já existente</strong> na V8 para verificar se a Dataprev já processou a resposta.<br><br>
            <span class="text-success fw-bold">✅ Sem novo custo</span> — nenhum novo consentimento é criado.<br>
            <small class="text-muted">Se a Dataprev ainda não respondeu, o CPF volta para a fila de espera.</small>`,
            'btn-primary', '#1a73e8',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_consentimento', { id_lote: id }, true, "Reprocessando...");
                if(res.success) { v8Toast("✅ " + res.msg, "success"); if(res.cpf_dono) v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); }
                else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
            });
    }

    async function v8ReprocessarErros(id) {
        const lote = (windowDadosLoteAtual || []).find(l => l.ID == id);
        const qtd = lote ? ((lote.funil?.m_err || 0) + (lote.funil?.s_err || 0) + (lote.funil?.c_err || 0)) : '?';
        v8Confirmar('⚠️ Reprocessar Erros',
            `<strong>${qtd} CPF(s)</strong> com erro serão reprocessados.<br><br>
            Esta ação retenta a consulta de margem e simulação para CPFs com:<br>
            <ul class="mb-1 mt-1 text-start" style="font-size:0.9em">
              <li><strong>Erro de Margem</strong> — retorno negativo da Dataprev</li>
              <li><strong>Erro de Simulação</strong> — margem encontrada, mas simulação falhou</li>
              <li><strong>Erro de Consulta</strong> — falha na leitura do consentimento</li>
            </ul>
            <span class="text-success fw-bold">✅ Sem novo custo</span> — reutiliza o consentimento já existente na V8.<br>
            <small class="text-muted">CPFs com rejeição definitiva (demitido, CNPJ inválido, etc.) continuarão com erro.</small>`,
            'btn-warning', '#ffc107',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_erros', { id_lote: id }, true, "Reprocessando...");
                if(res.success) { v8Toast("✅ " + res.msg, "success"); if(res.cpf_dono) v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); }
                else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
            });
    }

    async function v8ReprocessarTodos(id) {
        const lote = (windowDadosLoteAtual || []).find(l => l.ID == id);
        const total = lote ? (lote.QTD_TOTAL || '?') : '?';
        v8Confirmar('🔁 Reprocessar Tudo',
            `⚠️ <strong>ATENÇÃO: Esta é a ação mais drástica.</strong><br><br>
            <strong>Todos os ${total} CPFs</strong> do lote serão zerados e o processo reiniciará completamente do zero.<br><br>
            Isso inclui:<br>
            <ul class="mb-1 mt-1 text-start" style="font-size:0.9em">
              <li>CPFs com erro</li>
              <li>CPFs já simulados com sucesso</li>
              <li>CPFs aguardando Dataprev</li>
            </ul>
            <span class="text-danger fw-bold">⚠️ Pode gerar novo custo</span> — novos consentimentos serão solicitados para todos os CPFs.<br>
            <small class="text-muted">Use apenas se quiser refazer o lote inteiro do início.</small>`,
            'btn-danger', '#dc3545',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_todos', { id_lote: id }, true, "Reprocessando...");
                if(res.success) { v8Toast("✅ " + res.msg, "success"); if(res.cpf_dono) v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); }
                else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
            });
    }

    function v8ExportarResultado(id) {
        const lote = (windowDadosLoteAtual || []).find(l => l.ID == id);
        const qtd_ok = lote ? (lote.funil?.s_ok || 0) : '?';
        v8Confirmar('📁 Exportar Resultado',
            `Será gerada uma planilha CSV com os <strong>${qtd_ok} CPF(s) que possuem valor simulado</strong> (valor líquido maior que zero).<br><br>
            <span class="text-muted" style="font-size:0.9em">CPFs sem simulação, com erro ou sem valor líquido <strong>não serão incluídos</strong> na exportação.</span>`,
            'btn-success', '#198754',
            () => { window.open(`ajax_api_v8_lote_csv.php?acao=exportar_resultado_lote&id_lote=${id}`, '_blank'); });
    }

    async function v8PausarRetomarLote(id, acao) {
        const cfgs = {
            'PAUSAR':        { t: '⏸️ Pausar Robô',       d: 'O robô será pausado. Os CPFs já processados são mantidos.',                        cor: 'btn-warning', b: '#ffc107' },
            'RETOMAR':       { t: '▶️ Ligar Robô',        d: 'O robô ficará aguardando o horário de início configurado para processar.',           cor: 'btn-primary', b: '#0d6efd' },
            'RETOMAR_LIMITE':{ t: '⏩ Continuar Hoje',    d: 'Ignora o limite diário e continua processando o lote hoje.',                         cor: 'btn-primary', b: '#0d6efd' }
        };
        const cfg = cfgs[acao] || cfgs['PAUSAR'];
        v8Confirmar(cfg.t, cfg.d, cfg.cor, cfg.b, async () => {
            const res = await v8Req('ajax_api_v8_lote_csv.php', 'pausar_retomar_lote', { id_lote: id, acao_lote: acao }, true, "Aguarde...");
            if(res.success) { if(res.cpf_dono && acao !== 'PAUSAR') v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); }
            else { v8Toast("❌ " + (res.msg||"Erro"), "error", 6000); }
        });
    }

    async function v8ExcluirLote(id) {
        v8Confirmar('🗑️ Apagar Lote',
            '⚠️ <strong>Esta ação é irreversível.</strong> O lote e todo o histórico vinculado serão apagados permanentemente.',
            'btn-danger', '#dc3545',
            async () => {
                const res = await v8Req('ajax_api_v8_lote_csv.php', 'excluir_lote', { id_lote: id }, true, "Apagando...");
                if(res.success) v8CarregarLotesCSV(); else v8Toast("❌ " + (res.msg||"Erro ao excluir"), "error", 6000);
            });
    }
    
    async function v8VerificarAgendamentosDiarios() {
        try {
            const res = await v8Req('ajax_api_v8_lote_csv.php', 'verificar_agendamentos_diarios', {}, false);
            if (res && res.disparados > 0) { v8CarregarLotesCSV(); }
        } catch(e) {}
    }

    async function v8ForcarProcessamentoLote() {
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'forcar_processamento_lote', {}, true, "Destravando...");
        if(res.success) { v8Toast("✅ " + res.msg, "success"); v8CarregarLotesCSV(); }
    }

    document.getElementById('form_upload_lote_v8').addEventListener('submit', async function(e) {
        e.preventDefault(); 
        let btn = document.getElementById('btn_enviar_lote_v8');
        let txtOriginal = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando...';
        btn.disabled = true;
        
        let fd = new FormData(this);
        const isAppend = document.getElementById('chk_is_append').checked;
        fd.append('acao', isAppend ? 'append_csv_lote' : 'upload_csv_lote');
        
        try {
            let req = await fetch('ajax_api_v8_lote_csv.php', { method: 'POST', body: fd });
            let res = await req.json();
            btn.innerHTML = txtOriginal; btn.disabled = false;
            if(res.success) {
                v8Toast("✅ " + res.msg, "success");
                this.reset();
                document.getElementById('chk_is_append').checked = false;
                v8ToggleAppendMode();
                v8CarregarLotesCSV();
                if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo();
            } 
            else { v8Toast("❌ " + (res.msg||"Erro no upload"), "error", 6000); }
        } catch(err) { btn.innerHTML = txtOriginal; btn.disabled = false; v8Toast("❌ Falha de comunicação.", "error", 6000); }
    });

    // --- ANOTAÇÕES ---
    async function v8AbrirModalAnotacoes(idLote) {
        document.getElementById('anotacao_lote_id').value = idLote;
        document.getElementById('anotacao_lote_texto').value = 'Carregando...';
        modalAnotacoesLoteObj.show();
        
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'buscar_anotacao_lote', { id_lote: idLote }, false);
        if (res.success) { document.getElementById('anotacao_lote_texto').value = res.anotacoes || ''; } 
        else { document.getElementById('anotacao_lote_texto').value = ''; v8Toast("❌ " + (res.msg||"Erro"), "error", 5000); }
    }

    async function v8SalvarAnotacao() {
        let idLote = document.getElementById('anotacao_lote_id').value;
        let texto = document.getElementById('anotacao_lote_texto').value;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_anotacao_lote', { id_lote: idLote, anotacao: texto }, true, "Salvando...");
        if (res.success) { v8Toast("✅ " + res.msg, "success"); modalAnotacoesLoteObj.hide(); } else { v8Toast("❌ " + (res.msg||"Erro"), "error", 5000); }
    }

    async function v8ExcluirAnotacao() {
        if (!confirm("Tem certeza que deseja apagar a anotação?")) return;
        let idLote = document.getElementById('anotacao_lote_id').value;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_anotacao_lote', { id_lote: idLote, anotacao: '' }, true, "Apagando...");
        if (res.success) { v8Toast("✅ Anotação apagada.", "success"); modalAnotacoesLoteObj.hide(); } else { v8Toast("❌ " + (res.msg||"Erro"), "error", 5000); }
    }

    // --- APPEND LOTE MODAL ---
    function v8AbrirModalAppendLote(id, nome) {
        document.getElementById('formAppendLote').reset();
        document.getElementById('append_lote_id').value = id;
        document.getElementById('append_lote_nome').value = nome;
        modalAppendLoteObj.show();
    }


    document.getElementById('formAppendLote').addEventListener('submit', async function(e) {
        e.preventDefault();
        let btn = document.getElementById('btn_enviar_append');
        let txtOriginal = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Enviando...';
        btn.disabled = true;
        let fd = new FormData(this);
        fd.append('acao', 'append_csv_lote');
        try {
            let req = await fetch('ajax_api_v8_lote_csv.php', { method: 'POST', body: fd });
            let res = await req.json();
            btn.innerHTML = txtOriginal; btn.disabled = false;
            if (res.success) {
                v8Toast("✅ " + res.msg, "success");
                modalAppendLoteObj.hide();
                v8CarregarLotesCSV();
            } else {
                v8Toast("❌ " + (res.msg || "Erro no upload"), "error", 6000);
            }
        } catch(err) {
            btn.innerHTML = txtOriginal; btn.disabled = false;
            v8Toast("❌ Falha de comunicação.", "error", 6000);
        }
    });
</script>