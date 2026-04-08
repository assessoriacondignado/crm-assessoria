<?php require_once '../../includes/header.php'; ?>

<style>
  .table-meta th { font-size: 0.8rem; text-transform: uppercase; white-space: nowrap; background-color: #212529 !important; color: #ffffff !important; border-bottom: 2px solid #000; padding: 15px 10px !important; vertical-align: middle; }
  .table-meta td { font-size: 0.85rem; vertical-align: middle; padding: 12px 10px; }
  .btn-acoes { padding: 4px 8px; font-size: 0.85rem; margin: 0 2px; }
  .status-toggle { cursor: pointer; font-size: 1.3rem; transition: transform 0.2s; }
  .status-toggle:hover { transform: scale(1.15); }
  .desc-text { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle;}
  .tag-badge { cursor: pointer; transition: 0.2s; font-size: 0.75rem; margin: 2px; }
  .tag-badge:hover { background-color: #0d6efd !important; color: white !important; }
</style>

<div class="container-fluid py-4">
    <h3 class="mb-4 text-primary"><i class="fab fa-meta me-2"></i>Gestão META ADS</h3>

    <div class="card shadow-sm border-dark">
        <div class="card-body">
            
            <ul class="nav nav-tabs mb-4 border-dark" id="metaTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active text-dark fw-bold border-dark" data-bs-toggle="tab" data-bs-target="#tab-relatorio" onclick="carregarRelatorio()"><i class="fas fa-chart-line text-primary me-1"></i> Relatório de Anúncios</button></li>
                <li class="nav-item"><button class="nav-link text-dark border-dark" data-bs-toggle="tab" data-bs-target="#tab-vincular" onclick="carregarClientes('vinculo_cliente')"><i class="fas fa-plus-circle text-success me-1"></i> Cadastrar Anúncio</button></li>
                <li class="nav-item"><button class="nav-link text-dark border-dark" data-bs-toggle="tab" data-bs-target="#tab-config" onclick="carregarConfig(); carregarTemplates(); carregarAgendamentos();"><i class="fas fa-cogs text-warning me-1"></i> Configurações API & Avisos</button></li>
            </ul>

            <div class="tab-content">
                
                <div class="tab-pane fade show active" id="tab-relatorio">
                    <div class="row mb-3 align-items-end bg-light p-3 rounded border border-dark shadow-sm">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-dark">Status:</label>
                            <select id="filtro_status" class="form-select border-dark fw-bold text-primary">
                                <option value="ATIVO" selected>Apenas Ativos</option>
                                <option value="INATIVO">Apenas Inativos</option>
                                <option value="TODOS">Todos os Status</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold text-dark">Período de Análise:</label>
                            <select id="filtro_data" class="form-select border-dark" onchange="verificarDataCustom()">
                                <option value="hoje">Últimas 24h (Hoje)</option>
                                <option value="mes">Este Mês</option>
                                <option value="maximum">Vitalício (Tudo)</option>
                                <option value="custom">Data Personalizada...</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-none" id="div_datas_custom">
                            <div class="input-group">
                                <input type="date" id="data_inicio" class="form-control border-dark">
                                <span class="input-group-text bg-dark text-white border-dark">até</span>
                                <input type="date" id="data_fim" class="form-control border-dark">
                            </div>
                        </div>
                        <div class="col-md-5 text-end flex-grow-1">
                            <button class="btn btn-primary btn-sm me-2 fw-bold border-dark shadow-sm" onclick="carregarRelatorio()"><i class="fas fa-filter me-1"></i> Aplicar Filtro</button>
                            <button class="btn btn-success btn-sm me-2 fw-bold border-dark shadow-sm text-dark" onclick="abrirModalEnvioGlobal()"><i class="fab fa-whatsapp me-1"></i> Disparo Global</button>
                            <button class="btn btn-warning btn-sm fw-bold border-dark shadow-sm text-dark" onclick="sincronizarMetaGlob()"><i class="fas fa-sync-alt me-1"></i> Sincronização Global</button>
                        </div>
                    </div>
                    
                    <div class="table-responsive border border-dark rounded shadow-sm">
                        <table class="table table-hover table-meta mb-0 align-middle text-center">
                            <thead class="table-dark text-white border-dark">
                                <tr>
                                    <th title="Ativar ou Ocultar no Relatório">Status</th>
                                    <th>Veiculação</th>
                                    <th class="text-start">Nome da Conta</th>
                                    <th class="text-start">Anúncio</th>
                                    <th class="text-start">Cliente CRM</th>
                                    <th>Descrição</th>
                                    <th>Alcance</th>
                                    <th>Imp.</th>
                                    <th>Freq.</th>
                                    <th>Gasto (Período)</th>
                                    <th class="text-secondary">Gasto (Total)</th>
                                    <th>Conv.</th>
                                    <th>Custo/C.</th>
                                    <th>CTR</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody id="tabela_relatorio">
                                <tr><td colspan="15" class="text-center py-4 fw-bold">Carregando dados...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-vincular">
                    <div class="row w-50 mx-auto bg-light p-4 rounded border border-dark shadow-lg">
                        <h5 class="mb-4 text-success fw-bold text-uppercase border-bottom border-dark pb-2"><i class="fas fa-plus-circle me-2"></i>Novo Anúncio</h5>
                        <div class="col-md-12 mb-3">
                            <label class="form-label small fw-bold">Cliente (Dono do Anúncio):</label>
                            <select id="vinculo_cliente" class="form-select border-dark"></select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label small fw-bold">ID do Anúncio (Ad ID):</label>
                            <input type="text" id="vinculo_ad_id" class="form-control border-dark" placeholder="Ex: 120241900000000">
                        </div>
                        <div class="col-md-12 mb-4">
                            <label class="form-label small fw-bold text-secondary">ID da Conta de Anúncios (Opcional - Puxa Automático):</label>
                            <input type="text" id="vinculo_ad_account_id" class="form-control border-dark" placeholder="Ex: 510130698836095 (Apenas números)">
                        </div>
                        <div class="col-md-12 text-end">
                            <button class="btn btn-success fw-bold border-dark shadow-sm" onclick="salvarVinculo()"><i class="fas fa-save me-2"></i> Inserir Anúncio</button>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab-config">
                    <div class="row w-75 mx-auto">
                        <div class="col-md-12 mb-4">
                            <div class="bg-light p-3 rounded border border-dark shadow-sm d-flex justify-content-between align-items-center">
                                <div class="flex-grow-1 me-3">
                                    <label class="form-label small fw-bold text-primary text-uppercase mb-1"><i class="fas fa-key me-1"></i> Token de Acesso (Meta Graph API):</label>
                                    <input type="password" id="cfg_meta_token" class="form-control border-dark fw-bold" placeholder="EAAI...">
                                </div>
                                <div class="mt-4 pt-1">
                                    <button class="btn btn-primary fw-bold border-dark shadow-sm" onclick="salvarConfig()"><i class="fas fa-save me-1"></i> Salvar</button>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12 mb-4">
                            <div class="bg-white p-0 rounded border border-dark shadow-lg overflow-hidden">
                                <div class="bg-dark text-white p-3 d-flex justify-content-between align-items-center border-bottom border-dark">
                                    <h5 class="m-0 fw-bold text-uppercase"><i class="fas fa-clock text-warning me-2"></i>Agendamentos de Relatório (Cron)</h5>
                                    <button class="btn btn-warning btn-sm fw-bold border-dark text-dark" onclick="abrirModalAgendamento()"><i class="fas fa-plus me-1"></i> Novo Agendamento</button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle text-center mb-0">
                                        <thead class="table-secondary border-dark">
                                            <tr>
                                                <th class="text-start">Mensagem Padrão (Template)</th>
                                                <th>Dias da Semana</th>
                                                <th>Horários de Envio</th>
                                                <th>Status</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody_agendamentos">
                                            <tr><td colspan="5" class="py-4 text-muted fw-bold">Carregando agendamentos...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="bg-white p-0 rounded border border-dark shadow-lg overflow-hidden">
                                <div class="bg-dark text-white p-3 d-flex justify-content-between align-items-center border-bottom border-dark">
                                    <h5 class="m-0 fw-bold text-uppercase"><i class="fas fa-comment-dots text-info me-2"></i>Modelos de Mensagem (Templates)</h5>
                                    <button class="btn btn-info btn-sm fw-bold border-dark text-dark" onclick="abrirModalTemplate()"><i class="fas fa-plus me-1"></i> Novo Modelo</button>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle text-center mb-0">
                                        <thead class="table-secondary border-dark">
                                            <tr>
                                                <th class="text-start">Nome da Mensagem</th>
                                                <th>Local de Vínculo</th>
                                                <th class="text-start">Função</th>
                                                <th>Atualização</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody id="tbody_templates">
                                            <tr><td colspan="5" class="py-4 text-muted fw-bold">Carregando templates...</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEnvioGlobal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-dark shadow-lg">
      <div class="modal-header bg-success text-dark border-bottom border-dark">
        <h5 class="modal-title fw-bold text-uppercase" id="tituloModalEnvio"><i class="fab fa-whatsapp me-2"></i>Disparo no WhatsApp</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body bg-light">
        <input type="hidden" id="envio_unico_dados">
        <div class="mb-3">
            <label class="form-label small fw-bold text-dark">Escolha o Modelo de Mensagem a enviar:</label>
            <select id="global_template_id" class="form-select border-dark fw-bold text-primary">
            </select>
        </div>
        <div class="alert alert-warning border-dark small fw-bold shadow-sm" id="avisoModalEnvio"></div>
        <div id="global_progresso" class="text-center fw-bold text-primary mt-3" style="font-size: 1.1rem;"></div>
      </div>
      <div class="modal-footer bg-white border-top border-dark">
        <button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal" id="btnCancelarGlobal">Cancelar</button>
        <button type="button" class="btn btn-success fw-bold border-dark shadow-sm text-dark" onclick="iniciarEnvioGlobal()" id="btnIniciarGlobal"><i class="fas fa-paper-plane me-1"></i> Iniciar Disparo</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modalAgendamento" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-warning text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-calendar-alt me-2"></i>Configurar Agendamento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="formAgendamento"><input type="hidden" id="agen_id"><div class="mb-3"><label class="form-label small fw-bold text-dark">Mensagem Padrão a ser enviada:</label><select id="agen_template_id" class="form-select border-dark fw-bold text-primary" required><option value="">Carregando modelos...</option></select></div><div class="mb-3"><label class="form-label small fw-bold text-dark d-block">Dias da Semana permitidos:</label><div class="d-flex flex-wrap gap-2"><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="1" id="dia_1"><label class="form-check-label fw-bold small" for="dia_1">Seg</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="2" id="dia_2"><label class="form-check-label fw-bold small" for="dia_2">Ter</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="3" id="dia_3"><label class="form-check-label fw-bold small" for="dia_3">Qua</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="4" id="dia_4"><label class="form-check-label fw-bold small" for="dia_4">Qui</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="5" id="dia_5"><label class="form-check-label fw-bold small" for="dia_5">Sex</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="6" id="dia_6"><label class="form-check-label fw-bold small text-danger" for="dia_6">Sáb</label></div><div class="form-check form-switch"><input class="form-check-input border-dark chk-dia" type="checkbox" value="0" id="dia_0"><label class="form-check-label fw-bold small text-danger" for="dia_0">Dom</label></div></div></div><div class="mb-3"><label class="form-label small fw-bold text-dark">Horários de Disparo:</label><input type="text" id="agen_horarios" class="form-control border-dark fw-bold" placeholder="Ex: 09:00, 14:30, 18:00" required><small class="text-muted">Separe os horários exatos por vírgula.</small></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-warning fw-bold border-dark shadow-sm text-dark"><i class="fas fa-save me-1"></i> Gravar Agendamento</button></div></form></div></div></div>
<div class="modal fade" id="modalTemplate" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-comment-medical text-info me-2"></i>Configurar Mensagem Padrão</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><form id="formTemplate"><input type="hidden" id="tpl_id"><div class="row g-3"><div class="col-md-6"><label class="form-label small fw-bold text-dark">Nome da Mensagem:</label><input type="text" id="tpl_nome" class="form-control border-dark" placeholder="Ex: Relatório Diário Padrão" required></div><div class="col-md-6"><label class="form-label small fw-bold text-dark">Local de Vínculo (Gatilho):</label><select id="tpl_local" class="form-select border-dark fw-bold text-primary" required><option value="">-- Selecione Onde Usar --</option><option value="relatorio_grupo">Relatório de Anúncio (Envio Whats / Cron)</option><option value="registro_diario">Registro Diário de Atividades (Modal Prancheta)</option></select></div><div class="col-md-12"><label class="form-label small fw-bold text-dark">Função / Observação Interna:</label><input type="text" id="tpl_funcao" class="form-control border-dark" placeholder="Ex: Usado para avisar o cliente todos os dias de manhã..."></div><div class="col-md-12 mt-4"><label class="form-label small fw-bold text-dark d-flex justify-content-between">Conteúdo da Mensagem (Texto Final):<span class="text-danger small"><i class="fas fa-info-circle"></i> O WhatsApp aceita emojis e *negrito*</span></label><textarea id="tpl_conteudo" class="form-control border-dark" rows="8" required placeholder="Olá {NOME_CLIENTE}, segue o relatório do anúncio {NOME_ANUNCIO}..."></textarea></div><div class="col-md-12"><div class="alert alert-info border-dark shadow-sm py-2 px-3 mb-0"><div class="fw-bold text-dark mb-2"><i class="fas fa-tags me-1"></i> Tags Dinâmicas (Clique na tag para copiá-la):</div><div id="lista_tags"><span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{NOME_CLIENTE}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{NOME_ANUNCIO}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{CONTA_ANUNCIO}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{STATUS_CONTA}</span> <span class="badge bg-success border border-dark tag-badge" onclick="copiarTag(this)">{SALDO_DISPONIVEL}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{STATUS_WHATS}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{DATA_REFERENCIA}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{ALCANCE}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{IMPRESSOES}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{FREQUENCIA}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{CLIQUES}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{CTR}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{CONVERSAS}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{CUSTO_CONVERSA}</span> <span class="badge bg-secondary border border-dark tag-badge" onclick="copiarTag(this)">{GASTO_PERIODO}</span><hr class="my-2 border-dark opacity-25"><span class="text-dark small fw-bold">Tags Exclusivas para Registro Diário:</span><br><span class="badge bg-warning text-dark border border-dark tag-badge" onclick="copiarTag(this)">{STATUS_DIARIO}</span> <span class="badge bg-warning text-dark border border-dark tag-badge" onclick="copiarTag(this)">{OBSERVACAO_DIA}</span></div></div></div></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success fw-bold border-dark shadow-sm text-dark"><i class="fas fa-save me-1"></i> Gravar Modelo</button></div></form></div></div></div>
<div class="modal fade" id="modalEditar" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-dark text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-warning me-2"></i>Editar Anúncio</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="edit_id"><div class="mb-3"><label class="form-label small fw-bold">Cliente:</label><select id="edit_cliente" class="form-select border-dark"></select></div><div class="mb-3"><label class="form-label small fw-bold">ID do Anúncio (Ad ID):</label><input type="text" id="edit_ad_id" class="form-control border-dark"></div><div class="mb-3"><label class="form-label small fw-bold text-secondary">ID da Conta de Anúncios:</label><input type="text" id="edit_ad_account_id" class="form-control border-dark" placeholder="Opcional - Puxa Automático"></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary fw-bold border-dark text-dark" onclick="salvarEdicao()"><i class="fas fa-save me-1"></i> Salvar Alterações</button></div></div></div></div>
<div class="modal fade" id="modalDescricao" tabindex="-1"><div class="modal-dialog"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-info text-dark border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-align-left me-2"></i>Descrição do Anúncio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="desc_id"><div class="mb-3"><label class="form-label small fw-bold">Escreva os detalhes e informações deste anúncio:</label><textarea id="desc_texto" class="form-control border-dark" rows="6" placeholder="Ex: Campanha de Black Friday, Orçamento de R$ 50/dia..."></textarea></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-info fw-bold border-dark text-dark" onclick="salvarDescricao()"><i class="fas fa-save me-1"></i> Salvar Descrição</button></div></div></div></div>
<div class="modal fade" id="modalRegistros" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content border-dark shadow-lg"><div class="modal-header bg-primary text-white border-bottom border-dark"><h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-clipboard-list text-warning me-2"></i>Registros Diários do Anúncio</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body bg-light"><input type="hidden" id="reg_vinculo_id"><input type="hidden" id="reg_saldo_disponivel"><div class="card border-dark shadow-sm mb-4"><div class="card-header bg-dark text-white fw-bold py-2"><i class="fas fa-plus-circle text-success me-1"></i> Novo Lançamento Diário</div><div class="card-body bg-white"><div class="row g-3"><div class="col-md-4"><label class="small fw-bold">Status do Dia:</label><select id="reg_status" class="form-select border-dark fw-bold text-primary"><option value="RODANDO NORMAL">Rodando Normal</option><option value="EM ANALISE">Em Análise</option><option value="REJEITADO">Rejeitado (Atenção)</option><option value="CONTA BLOQUEADA">Conta Bloqueada</option><option value="FALTA SALDO">Falta Saldo</option><option value="PAUSADO">Pausado Manualmente</option></select></div><div class="col-md-8"><label class="small fw-bold">Observações do dia:</label><input type="text" id="reg_obs" class="form-control border-dark" placeholder="Ex: Troquei o criativo, subi o orçamento..."></div><div class="col-md-12 mt-3 pt-3 border-top border-dark"><div class="form-check form-switch mb-2"><input class="form-check-input border-dark" type="checkbox" id="reg_enviar_whats" checked style="transform: scale(1.3); margin-left: -1.5em; margin-right: 10px;" onchange="toggleWhatsOpcoes()"><label class="form-check-label fw-bold text-success" for="reg_enviar_whats">Notificar Status no WhatsApp</label></div><div id="div_opcoes_whats" class="bg-light border border-dark rounded p-3 mt-2"><div class="row g-3"><div class="col-md-6"><label class="small fw-bold text-dark">Mensagem Padrão:</label><select id="reg_template_select" class="form-select form-select-sm border-dark fw-bold"></select></div><div class="col-md-6"><label class="small fw-bold text-dark">Destino do Envio:</label><select id="reg_destino" class="form-select form-select-sm border-dark fw-bold text-primary"><option value="grupo">No Grupo do Cliente</option><option value="privado">No Privado do Cliente (Individual)</option></select></div></div></div></div><div class="col-md-12 text-end mt-3"><button type="button" class="btn btn-primary fw-bold border-dark shadow-sm" onclick="salvarRegistroDiario()"><i class="fas fa-check me-1"></i> Gravar Lançamento</button></div></div></div></div><h6 class="fw-bold text-dark mb-3"><i class="fas fa-history me-2"></i>Histórico Recente</h6><div class="table-responsive bg-white border border-dark rounded shadow-sm" style="max-height: 300px; overflow-y: auto;"><table class="table table-sm table-hover align-middle mb-0 text-center"><thead class="table-dark text-white sticky-top" style="font-size: 0.8rem;"><tr><th>Data/Hora</th><th>Status</th><th class="text-start">Observação</th><th>Aviso Whats?</th></tr></thead><tbody id="tbody_registros"><tr><td colspan="4" class="text-center py-4 text-muted">Carregando histórico...</td></tr></tbody></table></div></div><div class="modal-footer bg-white border-top border-dark"><button type="button" class="btn btn-outline-dark fw-bold" data-bs-dismiss="modal">Fechar Painel</button></div></div></div></div>

<script>
    async function metaReq(acao, dados = {}) {
        const formData = new FormData(); formData.append('acao', acao);
        for (const key in dados) { formData.append(key, dados[key]); }
        const res = await fetch('meta_ads.ajax.php', { method: 'POST', body: formData });
        return await res.json();
    }

    let listaTemplatesGlobais = [];

    function copiarTag(elemento) {
        navigator.clipboard.writeText(elemento.innerText); const originalText = elemento.innerText; elemento.innerText = "Copiado!";
        elemento.classList.replace('bg-success', 'bg-dark'); elemento.classList.replace('bg-secondary', 'bg-dark');
        setTimeout(() => { elemento.innerText = originalText; elemento.className = elemento.className.replace('bg-dark', 'bg-secondary').replace('bg-dark', 'bg-success'); }, 1500);
    }

    // ==========================================
    // DISPARO NO WHATSAPP (GLOBAL E INDIVIDUAL)
    // ==========================================
    async function abrirModalEnvioGlobal(dadosJson = '') {
        document.getElementById('envio_unico_dados').value = dadosJson;
        
        const sel = document.getElementById('global_template_id');
        sel.innerHTML = '<option value="">Carregando...</option>';
        
        const r = await metaReq('listar_templates');
        if(r.success) {
            let opts = '';
            r.data.forEach(t => {
                // 🟢 CORREÇÃO: Agora puxa TODOS os templates de Relatório (antigos e novos)
                if(t.LOCAL_VINCULO === 'relatorio_grupo' || t.LOCAL_VINCULO === 'disparo_manual') {
                    opts += `<option value="${t.ID}">${t.NOME_DA_MENSAGEM}</option>`;
                }
            });
            sel.innerHTML = opts || '<option value="">(Nenhum modelo de relatório cadastrado)</option>';
        }
        
        const titulo = document.getElementById('tituloModalEnvio');
        const aviso = document.getElementById('avisoModalEnvio');
        
        if (dadosJson) {
            titulo.innerHTML = '<i class="fab fa-whatsapp me-2"></i>Disparo Individual';
            aviso.innerHTML = '<i class="fas fa-info-circle me-1"></i> O sistema enviará a mensagem apenas para o grupo deste cliente específico.';
        } else {
            titulo.innerHTML = '<i class="fab fa-whatsapp me-2"></i>Disparo Global';
            aviso.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> O sistema enviará esta mensagem para <b>todos os anúncios ATIVOS</b> listados na tela. Um delay obrigatório de 10 segundos será aplicado entre cada envio.';
        }
        
        document.getElementById('global_progresso').innerHTML = '';
        document.getElementById('btnIniciarGlobal').disabled = false;
        document.getElementById('btnIniciarGlobal').innerHTML = '<i class="fas fa-paper-plane me-1"></i> Iniciar Disparo';
        new bootstrap.Modal(document.getElementById('modalEnvioGlobal')).show();
    }

    async function iniciarEnvioGlobal() {
        const tplId = document.getElementById('global_template_id').value;
        if(!tplId) return alert("Selecione um modelo de mensagem primeiro!");

        const btnIniciar = document.getElementById('btnIniciarGlobal');
        const btnCancelar = document.getElementById('btnCancelarGlobal');
        const progresso = document.getElementById('global_progresso');
        const dadosUnicos = document.getElementById('envio_unico_dados').value;

        btnIniciar.disabled = true; btnCancelar.disabled = true;

        if (dadosUnicos) {
            progresso.innerHTML = `<i class="fas fa-spinner fa-spin text-dark"></i> Enviando mensagem...`;
            const dados = JSON.parse(decodeURIComponent(dadosUnicos));
            dados.template_id = tplId;
            
            try {
                const r = await metaReq('enviar_aviso_wapi', dados);
                if(r.success) progresso.innerHTML = `<span class="text-success"><i class="fas fa-check-circle"></i> Enviado com sucesso!</span>`;
                else progresso.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle"></i> Falha no envio.</span>`;
            } catch(e) { progresso.innerHTML = `<span class="text-danger"><i class="fas fa-times-circle"></i> Erro de Servidor.</span>`; }
            
            btnIniciar.innerHTML = '<i class="fas fa-check me-1"></i> Finalizado'; btnCancelar.disabled = false;
            setTimeout(() => { bootstrap.Modal.getInstance(document.getElementById('modalEnvioGlobal')).hide(); }, 2500);
        } else {
            const botoes = document.querySelectorAll('.btn-envio-whats-ativo');
            if(botoes.length === 0) { btnIniciar.disabled = false; btnCancelar.disabled = false; return alert("Nenhum anúncio ATIVO na tela!"); }
            if(!confirm(`Deseja iniciar o disparo automático para ${botoes.length} clientes agora?`)) { btnIniciar.disabled = false; btnCancelar.disabled = false; return; }

            let sucesso = 0; let erro = 0;
            for (let i = 0; i < botoes.length; i++) {
                progresso.innerHTML = `<i class="fas fa-spinner fa-spin text-dark"></i> Enviando ${i + 1} de ${botoes.length}... <br><small class="text-muted">(Aguardando delay de 10s)</small>`;
                const dados = JSON.parse(decodeURIComponent(botoes[i].getAttribute('data-json'))); dados.template_id = tplId; 
                try { const r = await metaReq('enviar_aviso_wapi', dados); if(r.success) sucesso++; else erro++; } catch(e) { erro++; }
                if (i < botoes.length - 1) await new Promise(res => setTimeout(res, 10000));
            }
            progresso.innerHTML = `<span class="text-success"><i class="fas fa-check-circle"></i> Concluído! Sucesso: ${sucesso} | Erros: ${erro}</span>`;
            btnIniciar.innerHTML = '<i class="fas fa-check me-1"></i> Finalizado'; btnCancelar.disabled = false;
        }
    }

    // ==========================================
    // FUNÇÕES ORIGINAIS
    // ==========================================
    function abrirModalTemplate() { document.getElementById('formTemplate').reset(); document.getElementById('tpl_id').value = ''; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }
    function editarTemplate(id) { const tpl = listaTemplatesGlobais.find(t => t.ID == id); if(!tpl) return; document.getElementById('tpl_id').value = tpl.ID; document.getElementById('tpl_nome').value = tpl.NOME_DA_MENSAGEM; document.getElementById('tpl_local').value = tpl.LOCAL_VINCULO; document.getElementById('tpl_funcao').value = tpl.FUNCAO; document.getElementById('tpl_conteudo').value = tpl.CONTEUDO_DA_MENSAGEM; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }
    async function excluirTemplate(id) { if(!confirm("Certeza que deseja excluir este modelo de mensagem?")) return; const r = await metaReq('excluir_template', { id: id }); alert(r.msg); carregarTemplates(); }

    document.getElementById('formTemplate').addEventListener('submit', async function(e) { e.preventDefault(); const dados = { id: document.getElementById('tpl_id').value, nome: document.getElementById('tpl_nome').value, local: document.getElementById('tpl_local').value, funcao: document.getElementById('tpl_funcao').value, conteudo: document.getElementById('tpl_conteudo').value }; const r = await metaReq('salvar_template', dados); alert(r.msg); if(r.success) { bootstrap.Modal.getInstance(document.getElementById('modalTemplate')).hide(); carregarTemplates(); carregarAgendamentos(); } });

    async function carregarTemplates() {
        const r = await metaReq('listar_templates'); const tb = document.getElementById('tbody_templates'); const selAgen = document.getElementById('agen_template_id'); 
        if(r.success) {
            listaTemplatesGlobais = r.data; tb.innerHTML = ''; let optAgen = '<option value="">-- Selecione o Template --</option>';
            if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold">Nenhum modelo cadastrado.</td></tr>'; return; }
            r.data.forEach(t => {
                let localFormatado = '';
                if(t.LOCAL_VINCULO === 'relatorio_grupo' || t.LOCAL_VINCULO === 'disparo_manual') localFormatado = '<span class="badge bg-primary text-white border border-dark">Relatório Whats</span>';
                else localFormatado = '<span class="badge bg-warning text-dark border border-dark">Registro Diário</span>';
                
                tb.innerHTML += `<tr class="border-bottom border-dark"><td class="text-start fw-bold text-primary">${t.NOME_DA_MENSAGEM}</td><td>${localFormatado}</td><td class="text-start text-muted small">${t.FUNCAO || '-'}</td><td class="small">${t.DATA_BR}</td><td><button class="btn btn-sm btn-outline-dark fw-bold border-dark shadow-sm me-1" onclick="editarTemplate(${t.ID})" title="Editar"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-danger fw-bold border-dark shadow-sm" onclick="excluirTemplate(${t.ID})" title="Excluir"><i class="fas fa-trash"></i></button></td></tr>`;
                if(t.LOCAL_VINCULO === 'relatorio_grupo' || t.LOCAL_VINCULO === 'disparo_manual') { optAgen += `<option value="${t.ID}">${t.NOME_DA_MENSAGEM}</option>`; }
            });
            if(selAgen) selAgen.innerHTML = optAgen;
        }
    }

    async function carregarAgendamentos() {
        const r = await metaReq('listar_agendamentos'); const tb = document.getElementById('tbody_agendamentos');
        if(r.success) {
            tb.innerHTML = ''; if(r.data.length === 0) { tb.innerHTML = '<tr><td colspan="5" class="py-4 text-muted fw-bold">Nenhum agendamento configurado.</td></tr>'; return; }
            r.data.forEach(a => {
                const iconeStatus = a.STATUS_AGENDAMENTO === 'ATIVO' ? '<i class="fas fa-toggle-on text-success status-toggle"></i>' : '<i class="fas fa-toggle-off text-secondary status-toggle"></i>';
                const opacity = a.STATUS_AGENDAMENTO === 'ATIVO' ? '1' : '0.5';
                const mapaDias = {0:'Dom', 1:'Seg', 2:'Ter', 3:'Qua', 4:'Qui', 5:'Sex', 6:'Sáb'};
                let diasArr = a.DIAS_SEMANA.split(',').map(d => mapaDias[d.trim()] || d); let diasTexto = diasArr.join(', ');
                tb.innerHTML += `<tr class="border-bottom border-dark" style="opacity: ${opacity}"><td class="text-start fw-bold text-primary">${a.NOME_TEMPLATE || '(Template Apagado)'}</td><td class="fw-bold">${diasTexto}</td><td class="fw-bold text-success">${a.HORARIOS}</td><td class="text-center" onclick="mudarStatusAgendamento(${a.ID}, '${a.STATUS_AGENDAMENTO}')" title="Clique para Ativar/Desativar">${iconeStatus}</td><td><button class="btn btn-sm btn-outline-dark fw-bold border-dark shadow-sm me-1" onclick="editarAgendamento(${a.ID}, ${a.TEMPLATE_ID}, '${a.DIAS_SEMANA}', '${a.HORARIOS}')" title="Editar"><i class="fas fa-edit"></i></button><button class="btn btn-sm btn-outline-danger fw-bold border-dark shadow-sm" onclick="excluirAgendamento(${a.ID})" title="Excluir"><i class="fas fa-trash"></i></button></td></tr>`;
            });
        }
    }

    function abrirModalAgendamento() { document.getElementById('formAgendamento').reset(); document.getElementById('agen_id').value = ''; document.querySelectorAll('.chk-dia').forEach(c => c.checked = false); new bootstrap.Modal(document.getElementById('modalAgendamento')).show(); }
    function editarAgendamento(id, tplId, dias, horarios) { document.getElementById('agen_id').value = id; document.getElementById('agen_template_id').value = tplId; document.getElementById('agen_horarios').value = horarios; document.querySelectorAll('.chk-dia').forEach(c => c.checked = false); const diasArr = dias.split(',').map(d => d.trim()); diasArr.forEach(d => { const chk = document.getElementById('dia_' + d); if(chk) chk.checked = true; }); new bootstrap.Modal(document.getElementById('modalAgendamento')).show(); }
    async function mudarStatusAgendamento(id, statusAtual) { const novoStatus = statusAtual === 'ATIVO' ? 'INATIVO' : 'ATIVO'; await metaReq('mudar_status_agendamento', { id: id, status: novoStatus }); carregarAgendamentos(); }
    async function excluirAgendamento(id) { if(!confirm("Deseja apagar este agendamento? Ele não vai mais disparar.")) return; await metaReq('excluir_agendamento', { id: id }); carregarAgendamentos(); }

    document.getElementById('formAgendamento').addEventListener('submit', async function(e) { e.preventDefault(); let diasSelecionados = []; document.querySelectorAll('.chk-dia:checked').forEach(c => diasSelecionados.push(c.value)); if (diasSelecionados.length === 0) return alert("Selecione pelo menos um dia da semana!"); const dados = { id: document.getElementById('agen_id').value, template_id: document.getElementById('agen_template_id').value, horarios: document.getElementById('agen_horarios').value, dias: diasSelecionados.join(',') }; const r = await metaReq('salvar_agendamento', dados); alert(r.msg); if (r.success) { bootstrap.Modal.getInstance(document.getElementById('modalAgendamento')).hide(); carregarAgendamentos(); } });

    function toggleWhatsOpcoes() { const chk = document.getElementById('reg_enviar_whats').checked; const div = document.getElementById('div_opcoes_whats'); if(chk) div.classList.remove('d-none'); else div.classList.add('d-none'); }
    async function carregarSelectTemplatesRegistro() { const sel = document.getElementById('reg_template_select'); sel.innerHTML = '<option value="">Carregando...</option>'; const r = await metaReq('listar_templates'); if(r.success) { let opts = ''; r.data.forEach(t => { if(t.LOCAL_VINCULO === 'registro_diario') opts += `<option value="${t.ID}">${t.NOME_DA_MENSAGEM}</option>`; }); sel.innerHTML = opts || '<option value="">(Nenhum modelo criado para Registro Diário)</option>'; } }

    async function abrirModalRegistros(vinculoId, saldoDisponivel) { document.getElementById('reg_vinculo_id').value = vinculoId; document.getElementById('reg_saldo_disponivel').value = saldoDisponivel; const tb = document.getElementById('tbody_registros'); tb.innerHTML = "<tr><td colspan='4' class='text-center py-4'><i class='fas fa-spinner fa-spin'></i> Carregando histórico...</td></tr>"; await carregarSelectTemplatesRegistro(); toggleWhatsOpcoes(); new bootstrap.Modal(document.getElementById('modalRegistros')).show(); const r = await metaReq('carregar_registros_diarios', { vinculo_id: vinculoId }); if(r.success) { tb.innerHTML = ""; if(r.data.length === 0) return tb.innerHTML = "<tr><td colspan='4' class='text-center text-muted py-3'>Nenhum registro encontrado.</td></tr>"; r.data.forEach(reg => { let badgeWhats = reg.MSG_ENVIADA == 1 ? '<span class="badge bg-success"><i class="fas fa-check"></i> Sim</span>' : '<span class="badge bg-secondary"><i class="fas fa-times"></i> Não</span>'; tb.innerHTML += `<tr class="border-bottom border-dark"><td class="text-muted fw-bold">${reg.DATA_REG_BR}</td><td><span class="badge bg-dark border border-secondary">${reg.STATUS_DIARIO}</span></td><td class="text-start">${reg.OBSERVACAO || '-'}</td><td>${badgeWhats}</td></tr>`; }); } }
    async function salvarRegistroDiario() { const vId = document.getElementById('reg_vinculo_id').value; const saldoDisponivel = document.getElementById('reg_saldo_disponivel').value; const st = document.getElementById('reg_status').value; const obs = document.getElementById('reg_obs').value; const whats = document.getElementById('reg_enviar_whats').checked ? 1 : 0; const tplId = document.getElementById('reg_template_select').value; const destino = document.getElementById('reg_destino').value; if (whats === 1 && !tplId) return alert("Por favor, selecione um Modelo de Mensagem ou crie um em Configurações."); const btn = event.currentTarget; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...'; const r = await metaReq('salvar_registro_diario', { vinculo_id: vId, status: st, observacao: obs, enviar_whats: whats, template_id: tplId, destino: destino, saldo_disponivel: saldoDisponivel }); alert(r.msg); btn.disabled = false; btn.innerHTML = '<i class="fas fa-check me-1"></i> Gravar Lançamento'; if(r.success) { document.getElementById('reg_obs').value = ''; const tb = document.getElementById('tbody_registros'); tb.innerHTML = "<tr><td colspan='4' class='text-center py-4'><i class='fas fa-spinner fa-spin'></i> Atualizando histórico...</td></tr>"; const r2 = await metaReq('carregar_registros_diarios', { vinculo_id: vId }); if(r2.success) { tb.innerHTML = ""; r2.data.forEach(reg => { let badgeWhats = reg.MSG_ENVIADA == 1 ? '<span class="badge bg-success"><i class="fas fa-check"></i> Sim</span>' : '<span class="badge bg-secondary"><i class="fas fa-times"></i> Não</span>'; tb.innerHTML += `<tr class="border-bottom border-dark"><td class="text-muted fw-bold">${reg.DATA_REG_BR}</td><td><span class="badge bg-dark border border-secondary">${reg.STATUS_DIARIO}</span></td><td class="text-start">${reg.OBSERVACAO || '-'}</td><td>${badgeWhats}</td></tr>`; }); } } }

    function verificarDataCustom() { const d = document.getElementById('div_datas_custom'); if (document.getElementById('filtro_data').value === 'custom') { d.classList.remove('d-none'); } else { d.classList.add('d-none'); } }
    async function carregarConfig() { const r = await metaReq('carregar_config'); if(r.success && r.data) { document.getElementById('cfg_meta_token').value = r.data.META_ACCESS_TOKEN || ''; } }
    async function salvarConfig() { const t = document.getElementById('cfg_meta_token').value; const r = await metaReq('salvar_config', { meta_token: t }); alert(r.msg); }
    async function carregarClientes(selectId) { const r = await metaReq('listar_clientes'); if(r.success) { let opts = '<option value="">-- Selecione o Cliente --</option>'; r.data.forEach(c => { opts += `<option value="${c.CPF}">${c.NOME} (Grupo: ${c.GRUPO_WHATS || 'Sem Grupo'})</option>`; }); document.getElementById(selectId).innerHTML = opts; } }
    async function salvarVinculo() { const c = document.getElementById('vinculo_cliente').value; const id = document.getElementById('vinculo_ad_id').value; const acc_id = document.getElementById('vinculo_ad_account_id').value; if(!c || !id) return alert("Preencha o cliente e o ID do Anúncio!"); const r = await metaReq('salvar_vinculo', { cpf_cliente: c, ad_id: id, ad_account_id: acc_id }); alert(r.msg); document.getElementById('vinculo_ad_id').value = ''; document.getElementById('vinculo_ad_account_id').value = ''; carregarRelatorio(); }
    async function mudarStatus(id, status_atual) { const novo_status = status_atual === 'ATIVO' ? 'INATIVO' : 'ATIVO'; await metaReq('mudar_status', { id: id, status: novo_status }); carregarRelatorio(); }
    async function excluirVinculo(id) { if(!confirm("Tem certeza que deseja remover este anúncio do painel?")) return; const r = await metaReq('excluir_vinculo', { id: id }); alert(r.msg); carregarRelatorio(); }
    async function abrirModalEditar(id, cpf, ad_id, acc_id) { await carregarClientes('edit_cliente'); document.getElementById('edit_id').value = id; document.getElementById('edit_cliente').value = cpf; document.getElementById('edit_ad_id').value = ad_id; document.getElementById('edit_ad_account_id').value = acc_id || ''; new bootstrap.Modal(document.getElementById('modalEditar')).show(); }
    async function salvarEdicao() { const id = document.getElementById('edit_id').value; const cpf = document.getElementById('edit_cliente').value; const ad_id = document.getElementById('edit_ad_id').value; const acc_id = document.getElementById('edit_ad_account_id').value; const r = await metaReq('editar_vinculo', { id: id, cpf_cliente: cpf, ad_id: ad_id, ad_account_id: acc_id }); alert(r.msg); bootstrap.Modal.getInstance(document.getElementById('modalEditar')).hide(); carregarRelatorio(); }
    function abrirModalDescricao(id, descEncoded) { document.getElementById('desc_id').value = id; document.getElementById('desc_texto').value = decodeURIComponent(descEncoded); new bootstrap.Modal(document.getElementById('modalDescricao')).show(); }
    async function salvarDescricao() { const id = document.getElementById('desc_id').value; const texto = document.getElementById('desc_texto').value; const r = await metaReq('salvar_descricao', { id: id, descricao: texto }); alert(r.msg); bootstrap.Modal.getInstance(document.getElementById('modalDescricao')).hide(); carregarRelatorio(); }
    async function sincronizarLinha(id) { if(!confirm("Atualizar nomes desta conta na Meta agora?")) return; const r = await metaReq('sincronizar_linha', { id: id }); alert(r.msg); carregarRelatorio(); }
    async function sincronizarMetaGlob() { const btn = event.currentTarget; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sincronizando...'; const r = await metaReq('sincronizar_dados_meta'); alert(r.msg); btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync-alt me-1"></i> Sincronização Global'; carregarRelatorio(); }

    async function carregarRelatorio() {
        const tb = document.getElementById('tabela_relatorio'); tb.innerHTML = "<tr><td colspan='15' class='text-center py-4 fw-bold'><div class='spinner-border spinner-border-sm text-primary me-2'></div> Conectando com a Meta API...</td></tr>";
        const statusFiltro = document.getElementById('filtro_status').value; let filtro = document.getElementById('filtro_data').value; let dataReferenciaTexto = document.getElementById('filtro_data').options[document.getElementById('filtro_data').selectedIndex].text;
        if (filtro === 'custom') { const di = document.getElementById('data_inicio').value; const df = document.getElementById('data_fim').value; if(!di || !df) return alert("Preencha as datas!"); filtro = `${di}|${df}`; dataReferenciaTexto = `De ${di.split('-').reverse().join('/')} até ${df.split('-').reverse().join('/')}`; }

        const r = await metaReq('carregar_relatorio', { filtro_data: filtro, filtro_status: statusFiltro });
        if(r.success) {
            tb.innerHTML = "";
            if(r.data.length === 0) return tb.innerHTML = "<tr><td colspan='15' class='text-center py-4 fw-bold text-muted'>Nenhum anúncio cadastrado/encontrado.</td></tr>";
            
            r.data.forEach(v => {
                const m = v.metricas; const isAtivo = v.STATUS_VINCULO === 'ATIVO'; const icone = isAtivo ? '<i class="fas fa-toggle-on text-success status-toggle"></i>' : '<i class="fas fa-toggle-off text-secondary status-toggle"></i>'; const opacity = isAtivo ? '1' : '0.5';
                const descSegura = v.DESCRICAO ? encodeURIComponent(v.DESCRICAO) : '';
                
                const dadosJson = encodeURIComponent(JSON.stringify({ 
                    vinculo_id: v.ID, grupo_whats: v.GRUPO_WHATS, nome_cliente: v.NOME_CLIENTE, ad_nome: v.NOME_ANUNCIO, conta_nome: v.NOME_CONTA_ANUNCIO, 
                    alcance: m.alcance, impressoes: m.impressoes, frequencia: m.frequencia, cliques: m.cliques, ctr: m.ctr, 
                    conversas: m.conversas, valor_usado: m.valor_usado, custo_conversa: m.custo_conversa, data_referencia: dataReferenciaTexto,
                    conta_status: m.conta_status_texto, whats_status: m.whats_meta_texto, saldo_disponivel: m.saldo_disponivel
                }));

                let tdMetricas = '';
                const classeBtnWhatsAtivo = isAtivo ? 'btn-envio-whats-ativo' : '';

                if(isAtivo || statusFiltro === 'INATIVO' || statusFiltro === 'TODOS') {
                    const badgeConta = m.conta_status_badge ? `<br>${m.conta_status_badge}` : ''; 
                    const badgeSaldo = m.saldo_badge ? ` ${m.saldo_badge}` : ''; 
                    const badgeWhats = m.whats_meta_badge ? `<br>${m.whats_meta_badge}` : '';
                    
                    tdMetricas = `
                        <td class="small fw-bold">${m.veiculacao}</td>
                        <td class="text-primary fw-bold text-start" style="min-width: 240px;">${v.NOME_CONTA_ANUNCIO || '--'} ${badgeConta} ${badgeSaldo} ${badgeWhats}</td>
                        <td class="text-info text-start">${v.NOME_ANUNCIO || 'Sincronize'}</td>
                        <td class="text-muted small text-start">${v.NOME_CLIENTE}</td>
                        <td><button class="btn btn-sm btn-info border-dark shadow-sm" onclick="abrirModalDescricao(${v.ID}, '${descSegura}')" title="Ver/Editar Descrição"><i class="fas fa-file-alt"></i></button></td>
                        <td class="${m.alcance === 'ERRO API' ? 'text-danger fw-bold' : ''}">${m.alcance}</td>
                        <td class="${m.impressoes === 'Bloqueio Meta' ? 'text-danger' : ''}">${m.impressoes}</td>
                        <td>${m.frequencia}</td>
                        <td class="text-danger fw-bold">R$ ${parseFloat(m.valor_usado).toFixed(2).replace('.', ',')}</td>
                        <td class="text-secondary fw-bold">R$ ${parseFloat(m.gasto_total).toFixed(2).replace('.', ',')}</td>
                        <td>${m.conversas}</td><td>R$ ${parseFloat(m.custo_conversa).toFixed(2).replace('.', ',')}</td><td>${m.ctr}</td>
                    `;
                } else { tdMetricas = `<td colspan="13" class="text-center text-muted fst-italic">Anúncio inativo (Oculto no relatório)</td>`; }

                tb.innerHTML += `
                <tr style="opacity: ${opacity}">
                    <td class="text-center" onclick="mudarStatus(${v.ID}, '${v.STATUS_VINCULO}')" title="Clique para Ativar/Desativar">${icone}</td>
                    ${tdMetricas}
                    <td class="text-center text-nowrap">
                        <button class="btn btn-outline-dark btn-acoes shadow-sm border-dark" onclick="abrirModalRegistros(${v.ID}, '${m.saldo_disponivel}')" title="Registros Diários"><i class="fas fa-clipboard-list"></i></button>
                        <button class="btn btn-outline-primary btn-acoes shadow-sm border-dark" onclick="abrirModalEditar(${v.ID}, '${v.CPF_CLIENTE}', '${v.AD_ID}', '${v.AD_ACCOUNT_ID || ''}')" title="Editar"><i class="fas fa-edit"></i></button>
                        <button class="btn btn-outline-warning btn-acoes shadow-sm border-dark text-dark" onclick="sincronizarLinha(${v.ID})" title="Atualizar Nomes Meta"><i class="fas fa-sync-alt"></i></button>
                        <button class="btn btn-outline-success btn-acoes shadow-sm border-dark ${classeBtnWhatsAtivo}" data-json="${dadosJson}" onclick="abrirModalEnvioGlobal('${dadosJson}')" title="Enviar Relatório Whats"><i class="fab fa-whatsapp"></i></button>
                        <button class="btn btn-outline-danger btn-acoes shadow-sm border-dark" onclick="excluirVinculo(${v.ID})" title="Excluir"><i class="fas fa-trash"></i></button>
                    </td>
                </tr>`;
            });
        }
    }

    window.onload = carregarRelatorio;
</script>

</div> 
<?php 
$caminho_footer = $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
if (file_exists($caminho_footer)) { include $caminho_footer; }
?>