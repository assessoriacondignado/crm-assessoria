<?php
// Arquivo: index.api.v8.lote.vsc.php
// Módulo isolado para gestão de Lotes CSV da V8
@session_start();
?>

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
                <li><b>1:</b> Limite de <b>1.000 CPFs</b> por importação.</li>
                <li><b>2:</b> Cada usuário pode rodar <b>1 lote em simultâneo</b>.</li>
                <li><b>3:</b> O usuário pode ter lotes agendados, criando um fluxo de 1 lote por dia.</li>
                <li><b>4:</b> Formatos aceitos em Excel (.csv):
                    <ul class="mb-0 ps-3">
                        <li><b>1 - Lista da assessoria:</b> Somente a coluna CPF.</li>
                        <li><b>2 - Lista externa:</b> Colunas CPF, NOME, DATA NASCIMENTO, SEXO.</li>
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
                    <button type="submit" class="btn btn-success btn-sm w-100 fw-bold shadow-sm" id="btn_enviar_lote_v8"><i class="fas fa-play me-1"></i> Iniciar Lote</button>
                </div>
            </div>
            
            <div id="box_opcoes_novolote">
                <div class="col-12 mt-3 mb-1"><hr class="m-0"><small class="text-muted fw-bold mt-1 d-block"><i class="fas fa-cogs"></i> Opções de Agendamento e Limites</small></div>
                <div class="row g-2 align-items-end text-start">
                    <div class="col-md-3">
                        <label class="fw-bold small text-dark mb-1">Agendamento:</label>
                        <select name="agendamento_tipo" class="form-select form-select-sm border-primary" onchange="v8MudarTipoAgendamento(this.value)">
                            <option value="IMEDIATO">Imediato (Agora)</option>
                            <option value="PROGRAMADO">Data/Hora Específica</option>
                            <option value="DIA_MES">Todo dia X do mês</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-none" id="div_data_hora_agendada">
                        <label class="fw-bold small text-dark mb-1">Início:</label>
                        <input type="datetime-local" name="data_hora_agendada" class="form-control form-control-sm border-primary">
                    </div>
                    <div class="col-md-2 d-none" id="div_dia_mes_agendado">
                        <label class="fw-bold small text-dark mb-1">Dia do Mês (1 a 31):</label>
                        <input type="number" min="1" max="31" name="dia_mes_agendado" class="form-control form-control-sm border-primary" placeholder="Ex: 5">
                    </div>
                    <div class="col-md-2">
                        <label class="fw-bold small text-dark mb-1">Limite Diário:</label>
                        <input type="number" name="limite_diario" class="form-control form-control-sm border-primary" value="0" title="0 = Sem Limite" placeholder="0 = Sem Limite">
                    </div>
                    <div class="col-md-3 pb-0">
                        <div class="form-check w-100 border p-1 rounded bg-white border-dark shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="v8_somente_simular" name="somente_simular" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="v8_somente_simular" data-bs-toggle="tooltip" title="Não gera novos consentimentos. Recupera margem e simula.">
                                <i class="fas fa-bolt text-warning"></i> Somente Simular
                            </label>
                        </div>
                    </div>
                </div>

                <div class="col-12 mt-3 mb-1"><hr class="m-0"><small class="text-primary fw-bold mt-1 d-block"><i class="fas fa-robot"></i> Automação Pós-Aprovação (Opcional)</small></div>
                <div class="row g-2 text-start">
                    <div class="col-md-4">
                        <div class="form-check border p-1 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="v8_atualizar_telefone" name="atualizar_telefone" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="v8_atualizar_telefone">
                                <i class="fas fa-phone-alt text-success"></i> Telefones via FC (Desconta Saldo)
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check border p-1 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="v8_enviar_whats" name="enviar_whats" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="v8_enviar_whats">
                                <i class="fab fa-whatsapp text-success"></i> Enviar Aprovação no Grupo (W-API)
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check border p-1 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="v8_enviar_arquivo_whatsapp" name="enviar_arquivo_whatsapp" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="v8_enviar_arquivo_whatsapp">
                                <i class="fas fa-file-csv text-success"></i> Enviar CSV no Fim (W-API)
                            </label>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-end mb-3 mt-4">
    <h5 class="fw-bold text-dark mb-0"><i class="fas fa-list me-2"></i> Gerenciador de Lotes Exportação V8</h5>
    <div class="d-flex gap-2">
        <?php if (function_exists('verificaPermissao') && verificaPermissao($pdo, 'SUBMENU_OP_INTEGRACAO_V8_CONSULTA_LOTE_EXPORTAR_TUDO', 'FUNCAO')): ?>
        <button class="btn btn-outline-danger btn-sm fw-bold shadow-sm bg-white border-dark" onclick="v8ExportarTudoLotes()"><i class="fas fa-file-export me-1"></i> Exportar Tudo</button>
        <?php endif; ?>
        
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

<div class="table-responsive border border-dark rounded shadow-sm" style="min-height: 350px; max-height: 1200px; overflow-y: auto; overflow-x: auto;">
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
                <h5 class="modal-title fw-bold text-uppercase"><i class="fas fa-edit text-warning me-2"></i> Editar Configurações do Lote V8</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light p-4">
                <form id="formEditarLoteV8" class="row g-3">
                    <input type="hidden" id="edit_lote_id">
                    <div class="col-md-12">
                        <label class="fw-bold small text-dark mb-1">Nome do Agrupamento:</label>
                        <input type="text" id="edit_lote_agrupamento" class="form-control border-secondary fw-bold" required>
                    </div>
                    <div class="col-12 mt-3 mb-1"><hr class="m-0"><small class="text-muted fw-bold mt-1 d-block"><i class="fas fa-cogs"></i> Opções de Agendamento e Limites</small></div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-dark mb-1">Agendamento:</label>
                        <select id="edit_agendamento_tipo" class="form-select border-primary" onchange="v8MudarTipoAgendamentoEdit(this.value)">
                            <option value="IMEDIATO">Imediato (Agora)</option>
                            <option value="PROGRAMADO">Data/Hora Específica</option>
                            <option value="DIA_MES">Todo dia X do mês</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-none" id="div_edit_data_hora_agendada">
                        <label class="fw-bold small text-dark mb-1">Início:</label>
                        <input type="datetime-local" id="edit_data_hora_agendada" class="form-control border-primary">
                    </div>
                    <div class="col-md-4 d-none" id="div_edit_dia_mes_agendado">
                        <label class="fw-bold small text-dark mb-1">Dia do Mês (1 a 31):</label>
                        <input type="number" min="1" max="31" id="edit_dia_mes_agendado" class="form-control border-primary" placeholder="Ex: 5">
                    </div>
                    <div class="col-md-4">
                        <label class="fw-bold small text-dark mb-1">Limite Diário (0 = Sem Limite):</label>
                        <input type="number" id="edit_limite_diario" class="form-control border-primary">
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
                    <div class="col-md-4">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_atualizar_telefone" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_atualizar_telefone">
                                <i class="fas fa-phone-alt text-success"></i> Telefones via FC
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_enviar_whats" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_enviar_whats">
                                <i class="fab fa-whatsapp text-success"></i> Aprovação no W-API
                            </label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check border p-2 rounded bg-white border-info shadow-sm mb-0">
                            <input class="form-check-input ms-1" type="checkbox" id="edit_enviar_arquivo_whatsapp" value="1">
                            <label class="form-check-label fw-bold text-dark ms-1" style="font-size: 12px; cursor:pointer;" for="edit_enviar_arquivo_whatsapp">
                                <i class="fas fa-file-csv text-success"></i> Enviar CSV Fim (W-API)
                            </label>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light border-top border-secondary">
                <button type="button" class="btn btn-danger fw-bold shadow-sm border-dark" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success fw-bold px-5 shadow-sm border-dark" onclick="v8SalvarEdicaoLote()"><i class="fas fa-save"></i> Salvar Alterações</button>
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
                    <input type="hidden" id="append_lote_id" name="id_lote">
                    <div class="alert alert-warning small fw-bold border-warning shadow-sm mb-3">
                        <i class="fas fa-info-circle"></i> Os CPFs que já existem neste lote serão ignorados automaticamente para evitar duplicidade.
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-dark mb-1">Lote Destino:</label>
                        <input type="text" id="append_lote_nome" class="form-control border-dark fw-bold text-muted bg-light" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="fw-bold small text-dark mb-1">Novo Arquivo CSV:</label>
                        <input type="file" name="arquivo_csv" class="form-control border-success" accept=".csv" required>
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
<script>
    let modalEditarLoteObj, modalAnotacoesLoteObj, modalAppendLoteObj;
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

        const tabLote = document.querySelector('[data-bs-target="#tab-lote-csv"]');
        if(tabLote) {
            tabLote.addEventListener('shown.bs.tab', function (e) {
                v8CarregarLotesCSV();
                if(typeof windowListaChavesCache !== 'undefined') {
                    let o = '<option value="">-- Selecione a Chave/Cliente --</option>'; 
                    windowListaChavesCache.forEach(c => { o += `<option value="${c.ID}">${c.CLIENTE_NOME} (Saldo: R$ ${c.SALDO})</option>`; }); 
                    document.querySelectorAll('.v8-dropdown-clientes').forEach(s => s.innerHTML = o);
                }
            });
            tabLote.addEventListener('hidden.bs.tab', function (e) {
                if(intervaloLoteV8) { clearInterval(intervaloLoteV8); intervaloLoteV8 = null; }
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

    function v8MudarTipoAgendamento(val) {
        document.getElementById('div_data_hora_agendada').classList.add('d-none');
        document.getElementById('div_dia_mes_agendado').classList.add('d-none');
        if (val === 'PROGRAMADO') document.getElementById('div_data_hora_agendada').classList.remove('d-none');
        if (val === 'DIA_MES') document.getElementById('div_dia_mes_agendado').classList.remove('d-none');
    }

    function v8MudarTipoAgendamentoEdit(val) {
        document.getElementById('div_edit_data_hora_agendada').classList.add('d-none');
        document.getElementById('div_edit_dia_mes_agendado').classList.add('d-none');
        if (val === 'PROGRAMADO') document.getElementById('div_edit_data_hora_agendada').classList.remove('d-none');
        if (val === 'DIA_MES') document.getElementById('div_edit_dia_mes_agendado').classList.remove('d-none');
    }

    // --- FUNÇÃO EXPORTAR TUDO (MÁSTER) ---
    function v8ExportarTudoLotes() {
        if(!confirm("Deseja exportar todos os CPFs de TODOS os lotes que estão filtrados na tabela atualmente?\n\nDependendo da quantidade de CPFs, o download pode levar alguns segundos para começar.")) return;
        
        let regras = v8ObterRegrasLote();
        let encodedRegras = encodeURIComponent(regras);
        
        let url = `ajax_api_v8_lote_csv.php?acao=exportar_tudo_lotes&regras=${encodedRegras}`;
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
                    <option value="l.ID">ID do Lote</option>
                    <option value="l.NOME_IMPORTACAO">Nome do Agrupamento</option>
                    <option value="c.CLIENTE_NOME">Nome do Cliente/Credencial V8</option>
                    <option value="l.STATUS_FILA">Status Progresso (Ex: CONCLUIDO)</option>
                    <option value="l.STATUS_LOTE">Status do Robô (ATIVO/INATIVO)</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm border-dark regra-operador text-dark fw-bold">
                    <option value="contem">Contém</option>
                    <option value="nao_contem">Não contém</option>
                    <option value="comeca_com">Começa com</option>
                    <option value="igual">Exatamente igual a</option>
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
        
        if (r.success) { 
            windowDadosLoteAtual = r.data; 
            tb.innerHTML = ''; let temRodando = false; 
            if (r.data.length === 0) return tb.innerHTML = '<tr><td colspan="6" class="py-4 text-center text-muted fw-bold">Nenhum lote localizado com os filtros atuais.</td></tr>'; 
            
            r.data.forEach(l => { 
                let badge = ''; let agendamentoInfo = ''; let btnPausarLi = '';
                
                if(l.AGENDAMENTO_TIPO === 'PROGRAMADO') agendamentoInfo = `<span class="badge bg-warning text-dark ms-1">⏳ ${l.DATA_HORA_AGENDADA || 'Imediato'}</span>`;
                if(l.AGENDAMENTO_TIPO === 'DIA_MES') agendamentoInfo = `<span class="badge bg-warning text-dark ms-1">⏳ Dia ${l.DIA_MES_AGENDADO || 'Atual'}</span>`;
                if(l.LIMITE_DIARIO > 0) agendamentoInfo += `<span class="badge bg-info text-dark ms-1">Lmt: ${l.LIMITE_DIARIO} (Hj: ${l.PROCESSADOS_HOJE})</span>`;

                let statusAtual = l.STATUS_FILA || l.status_fila || l.Status_Fila;
                if (!statusAtual) statusAtual = 'PENDENTE';
                statusAtual = String(statusAtual).toUpperCase();

                let agendamentoTipoAtual = l.AGENDAMENTO_TIPO || l.agendamento_tipo || '';
                if (statusAtual === 'PENDENTE' && agendamentoTipoAtual !== 'IMEDIATO') {
                    statusAtual = 'AGENDADO';
                }

                let idLoteReal = l.ID || l.id;

                if (statusAtual === 'AGENDADO') { 
                    badge = `<span class="badge bg-secondary border border-dark text-white"><i class="fas fa-calendar-alt"></i> AGENDADO</span>`; 
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar (Cancelar Agend.)</button></li>`;
                } else if (statusAtual === 'PROCESSANDO') { 
                    temRodando = true; 
                    badge = `<span class="badge bg-primary shadow-sm"><i class="fas fa-cogs fa-spin"></i> PROCESSANDO</span>`; 
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Lote</button></li>`;
                } else if (statusAtual === 'PENDENTE') { 
                    temRodando = true; 
                    badge = `<span class="badge bg-info text-dark shadow-sm"><i class="fas fa-hourglass-half"></i> NA FILA</span>`; 
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8PausarRetomarLote(${idLoteReal}, 'PAUSAR')"><i class="fas fa-pause me-2"></i> Pausar Lote</button></li>`;
                } else if (statusAtual === 'PAUSADO') {
                    badge = `<span class="badge bg-warning text-dark"><i class="fas fa-pause-circle"></i> PAUSADO</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-primary" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR')"><i class="fas fa-play me-2"></i> Retomar Lote</button></li>`;
                } else if (statusAtual === 'PROCESSADO PARCIAL') {
                    badge = `<span class="badge bg-warning text-dark"><i class="fas fa-hand-paper"></i> PROCESSADO PARCIAL</span>`;
                    btnPausarLi = `<li><button class="dropdown-item fw-bold text-primary" onclick="v8PausarRetomarLote(${idLoteReal}, 'RETOMAR_LIMITE')"><i class="fas fa-forward me-2"></i> Continuar Hoje</button></li>`;
                } else if (statusAtual === 'CONCLUIDO') {
                    badge = `<span class="badge bg-success shadow-sm"><i class="fas fa-check-circle"></i> CONCLUIDO</span>`;
                } else {
                    badge = `<span class="badge bg-danger">${statusAtual}</span>`;
                }

                let qtdTotalAtual = l.QTD_TOTAL || l.qtd_total || 0;
                let qtdProcessadaAtual = l.QTD_PROCESSADA || l.qtd_processada || 0;
                let pNum = qtdTotalAtual > 0 ? Math.round((qtdProcessadaAtual / qtdTotalAtual) * 100) : 0; 
                
                let f = l.funil || {c_ok:0, c_err:0, m_ok:0, m_err:0, s_ok:0, s_err:0, dataprev:0};
                let c_ok = f.c_ok || 0; let c_err = f.c_err || 0;
                let m_ok = f.m_ok || 0; let m_err = f.m_err || 0;
                let s_ok = f.s_ok || 0; let s_err = f.s_err || 0;
                let dataprev = f.dataprev || 0;

                let htmlDataprev = dataprev > 0 ? `<div class="mb-1 text-danger fw-bold" style="font-size:10px;"><i class="fas fa-clock text-secondary" style="width:15px;"></i> Dataprev: ${dataprev}</div>` : '';

                let funilHtml = `
                <div class="text-start d-inline-block" style="font-size: 11px; min-width: 130px;">
                    ${htmlDataprev}
                    <div class="mb-1"><i class="fas fa-id-card text-secondary" style="width:15px;"></i> Consen.: <span class="text-success fw-bold">${c_ok}</span> / <span class="text-danger">${c_err}</span></div>
                    <div class="mb-1"><i class="fas fa-search-dollar text-secondary" style="width:15px;"></i> Margem: <span class="text-success fw-bold">${m_ok}</span> / <span class="text-danger">${m_err}</span></div>
                    <div class="mb-1"><i class="fas fa-calculator text-secondary" style="width:15px;"></i> Simul.: <span class="text-success fw-bold">${s_ok}</span> / <span class="text-danger">${s_err}</span></div>
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

                let btnAnotacoesLi = `<li><button class="dropdown-item fw-bold text-info" onclick="v8AbrirModalAnotacoes(${idLoteReal})"><i class="fas fa-sticky-note text-dark me-2"></i> <span class="text-dark">Anotações</span></button></li>`;
                
                let novoStatusTroca = statusLote === 'ATIVO' ? 'INATIVO' : 'ATIVO';
                let btnAlternarStatusLi = `<li><button class="dropdown-item fw-bold text-secondary" onclick="v8AlternarStatusLote(${idLoteReal}, '${novoStatusTroca}')"><i class="fas fa-power-off me-2"></i> Tornar ${novoStatusTroca}</button></li><li><hr class="dropdown-divider"></li>`;

                let btnExportarLi = `<li><a class="dropdown-item fw-bold text-success" href="ajax_api_v8_lote_csv.php?acao=exportar_resultado_lote&id_lote=${idLoteReal}" target="_blank"><i class="fas fa-file-excel me-2"></i> Exportar Resultado</a></li>`;
                let btnRepErrosLi = `<li><button class="dropdown-item fw-bold text-warning" onclick="v8ReprocessarSimulacao(${idLoteReal})" data-bs-toggle="tooltip" data-bs-placement="left" title="Recupera o registro de margem e faz nova simulação."><i class="fas fa-sync-alt text-dark me-2"></i> <span class="text-dark">Reprocessar Simulação</span></button></li>`;
                let btnRepTodosLi = `<li><button class="dropdown-item fw-bold text-dark" onclick="v8ReprocessarTodos(${idLoteReal})" data-bs-toggle="tooltip" data-bs-placement="left" title="Zera todo o histórico e reprocessa o lote."><i class="fas fa-redo me-2"></i> Reprocessar Todos</button></li>`;
                
                let btnEnviarWhatsLi = `<li><button class="dropdown-item fw-bold text-success" onclick="v8EnviarRelatorioLoteWhats(${idLoteReal})"><i class="fab fa-whatsapp me-2"></i> Enviar Relatório Whats</button></li>`;

                let btnEditarLi = '';
                if (!restricaoLoteEditar) {
                    if (statusAtual === 'PAUSADO') {
                        btnEditarLi = `<li><button class="dropdown-item fw-bold text-primary" onclick="v8AbrirModalEditarLote(${idLoteReal})"><i class="fas fa-edit me-2"></i> Editar Lote</button></li>`;
                    } else {
                        btnEditarLi = `<li><button class="dropdown-item fw-bold text-muted" disabled title="O Lote precisa estar PAUSADO para edição"><i class="fas fa-edit me-2"></i> Editar Lote (Requer Pausa)</button></li>`;
                    }
                }

                let btnApagarLi = '';
                if (!restricaoLoteExcluir) {
                    btnApagarLi = `<li><hr class="dropdown-divider"></li><li><button class="dropdown-item fw-bold text-danger" onclick="v8ExcluirLote(${idLoteReal})"><i class="fas fa-trash me-2"></i> Apagar Lote</button></li>`;
                }

                let menuAcoes = `
                <div class="btn-group dropstart w-100">
                  <button class="btn btn-sm btn-dark dropdown-toggle fw-bold shadow-sm w-100" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="markMenuAsOpen()">
                    <i class="fas fa-bars"></i> Mais
                  </button>
                  <ul class="dropdown-menu shadow-lg border-dark">
                    ${btnAnotacoesLi}
                    ${btnAlternarStatusLi}
                    ${btnPausarLi}
                    ${btnEditarLi}
                    ${btnExportarLi}
                    ${btnEnviarWhatsLi}
                    ${btnRepErrosLi}
                    ${btnRepTodosLi}
                    ${btnApagarLi}
                  </ul>
                </div>`;

                tb.innerHTML += `<tr class="bg-white border-bottom border-secondary">
                    <td class="align-middle fw-bold text-muted">${idLoteReal}</td>
                    <td class="align-middle">${infoListaOrigem}</td>
                    <td class="align-middle">${infoLoteGeral}</td>
                    <td class="align-middle fw-bold text-nowrap">${badge} <span class="ms-2 small text-muted">${pNum}%</span></td>
                    <td class="align-middle fs-6 text-nowrap bg-light border-start border-end border-secondary">${funilHtml}</td>
                    <td class="p-2 align-middle text-center" style="vertical-align: middle;">${labelStatusLote}${menuAcoes}</td>
                </tr>`; 
            }); 
            
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]')); 
            tooltipTriggerList.map(function (tooltipTriggerEl) { return new bootstrap.Tooltip(tooltipTriggerEl); }); 
            
            if(temRodando) { 
                if(!intervaloLoteV8) intervaloLoteV8 = setInterval(v8CarregarLotesCSV, 10000); 
                if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo(); 
            } else { 
                if(intervaloLoteV8) { clearInterval(intervaloLoteV8); intervaloLoteV8 = null; if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo(); } 
            } 
        } else {
            tb.innerHTML = `<tr><td colspan="6" class="py-4 text-center text-danger fw-bold"><i class="fas fa-exclamation-triangle"></i> Erro: ${r.msg}</td></tr>`;
        }
    }

    // --- FUNÇÕES DE AÇÃO DO LOTE ---
    async function v8AlternarStatusLote(idLote, novoStatus) {
        if(!confirm(`Deseja tornar este lote ${novoStatus}?`)) return;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'alternar_status_lote', { id_lote: idLote, novo_status: novoStatus }, true, "Alterando Status...");
        if(res.success) { v8CarregarLotesCSV(); } else { alert("❌ Erro: " + res.msg); }
    }

    function v8AbrirModalEditarLote(id) {
        let lote = windowDadosLoteAtual.find(l => l.ID == id || l.id == id);
        if (!lote) return alert("Erro: Lote não encontrado na tela.");

        document.getElementById('edit_lote_id').value = id;
        document.getElementById('edit_lote_agrupamento').value = lote.NOME_IMPORTACAO || lote.nome_importacao;
        
        let agTipo = lote.AGENDAMENTO_TIPO || lote.agendamento_tipo || 'IMEDIATO';
        document.getElementById('edit_agendamento_tipo').value = agTipo;
        v8MudarTipoAgendamentoEdit(agTipo);

        let dh = lote.DATA_HORA_AGENDADA || lote.data_hora_agendada;
        document.getElementById('edit_data_hora_agendada').value = dh ? dh.substring(0, 16) : '';
        document.getElementById('edit_dia_mes_agendado').value = lote.DIA_MES_AGENDADO || lote.dia_mes_agendado || '';
        document.getElementById('edit_limite_diario').value = lote.LIMITE_DIARIO || lote.limite_diario || 0;

        document.getElementById('edit_somente_simular').checked = (lote.SOMENTE_SIMULAR == 1 || lote.somente_simular == 1);
        document.getElementById('edit_atualizar_telefone').checked = (lote.ATUALIZAR_TELEFONE == 1 || lote.atualizar_telefone == 1);
        document.getElementById('edit_enviar_whats').checked = (lote.ENVIAR_WHATSAPP == 1 || lote.enviar_whatsapp == 1);
        document.getElementById('edit_enviar_arquivo_whatsapp').checked = (lote.ENVIAR_ARQUIVO_WHATSAPP == 1 || lote.enviar_arquivo_whatsapp == 1);

        modalEditarLoteObj.show();
    }

    async function v8SalvarEdicaoLote() {
        let payload = {
            id_lote: document.getElementById('edit_lote_id').value,
            agrupamento: document.getElementById('edit_lote_agrupamento').value,
            agendamento_tipo: document.getElementById('edit_agendamento_tipo').value,
            data_hora_agendada: document.getElementById('edit_data_hora_agendada').value,
            dia_mes_agendado: document.getElementById('edit_dia_mes_agendado').value,
            limite_diario: document.getElementById('edit_limite_diario').value,
            somente_simular: document.getElementById('edit_somente_simular').checked ? 1 : 0,
            atualizar_telefone: document.getElementById('edit_atualizar_telefone').checked ? 1 : 0,
            enviar_whats: document.getElementById('edit_enviar_whats').checked ? 1 : 0,
            enviar_arquivo_whatsapp: document.getElementById('edit_enviar_arquivo_whatsapp').checked ? 1 : 0
        };

        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_edicao_lote', payload, true, "Salvando Edição...");
        if(res.success) { alert("✅ " + res.msg); modalEditarLoteObj.hide(); v8CarregarLotesCSV(); } 
        else { alert("❌ Erro: " + res.msg); }
    }

    async function v8EnviarRelatorioLoteWhats(id) {
        if(!confirm("Deseja gerar o arquivo CSV deste lote e disparar agora mesmo no WhatsApp da equipe?")) return;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'enviar_relatorio_whatsapp', { id_lote: id }, true, "Gerando e Enviando...");
        if(res.success) { alert("✅ " + res.msg); } else { alert("❌ Erro: " + res.msg); }
    }

    async function v8ReprocessarSimulacao(id) {
        if(!confirm('Deseja recuperar as margens e reprocessar as simulações apenas dos CPFs com ERRO? \n\n⚠️ O sistema irá recuperar os IDs disponíveis e refazer a simulação padrão, sem gerar novas consultas na Dataprev.')) return;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_simulacao', { id_lote: id }, true, "Reprocessando...");
        if(res.success) { alert("✅ " + res.msg); if(res.cpf_dono) v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); } 
        else { alert("❌ Erro: " + res.msg); }
    }
    
    async function v8ReprocessarTodos(id) {
        if(!confirm('⚠️ ATENÇÃO: Deseja ZERAR e reprocessar TODOS os CPFs deste lote do zero? \n\nO lote inteiro será reiniciado e os custos de API poderão ser cobrados novamente.')) return;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'reprocessar_todos', { id_lote: id }, true, "Reprocessando...");
        if(res.success) { alert("✅ " + res.msg); if(res.cpf_dono) v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); } 
        else { alert("❌ Erro: " + res.msg); }
    }

    async function v8PausarRetomarLote(id, acao) {
        let msg = acao === 'PAUSAR' ? 'Deseja pausar o processamento deste lote?' : (acao === 'RETOMAR_LIMITE' ? 'Deseja ignorar o limite e continuar processando o lote hoje?' : 'Deseja retomar o processamento deste lote?');
        if(!confirm(msg)) return;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'pausar_retomar_lote', { id_lote: id, acao_lote: acao }, true, "Aguarde...");
        if(res.success) { if(res.cpf_dono && acao !== 'PAUSAR') v8AcordarRobo(res.cpf_dono); v8CarregarLotesCSV(); } 
        else { alert("❌ Erro: " + res.msg); }
    }

    async function v8ExcluirLote(id) { 
        if(!confirm('Apagar Lote e todo o histórico vinculado?')) return; 
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'excluir_lote', { id_lote: id }, true, "Apagando..."); 
        if(res.success) v8CarregarLotesCSV(); else alert("Erro: " + res.msg); 
    }
    
    async function v8ForcarProcessamentoLote() { 
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'forcar_processamento_lote', {}, true, "Destravando..."); 
        if(res.success) { alert("✅ " + res.msg); v8CarregarLotesCSV(); } 
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
                alert("✅ " + res.msg); 
                this.reset(); 
                document.getElementById('chk_is_append').checked = false; // reseta toggle
                v8ToggleAppendMode(); 
                v8CarregarLotesCSV(); 
                if(typeof v8AtualizarSaldosTopo === "function") v8AtualizarSaldosTopo(); 
                if(res.cpf_dono) v8AcordarRobo(res.cpf_dono);
            } 
            else { alert("❌ Erro: " + res.msg); }
        } catch(err) { btn.innerHTML = txtOriginal; btn.disabled = false; alert("❌ Falha de comunicação."); }
    });

    // --- ANOTAÇÕES ---
    async function v8AbrirModalAnotacoes(idLote) {
        document.getElementById('anotacao_lote_id').value = idLote;
        document.getElementById('anotacao_lote_texto').value = 'Carregando...';
        modalAnotacoesLoteObj.show();
        
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'buscar_anotacao_lote', { id_lote: idLote }, false);
        if (res.success) { document.getElementById('anotacao_lote_texto').value = res.anotacoes || ''; } 
        else { document.getElementById('anotacao_lote_texto').value = ''; alert("Erro: " + res.msg); }
    }

    async function v8SalvarAnotacao() {
        let idLote = document.getElementById('anotacao_lote_id').value;
        let texto = document.getElementById('anotacao_lote_texto').value;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_anotacao_lote', { id_lote: idLote, anotacao: texto }, true, "Salvando...");
        if (res.success) { alert("✅ " + res.msg); modalAnotacoesLoteObj.hide(); } else { alert("❌ Erro: " + res.msg); }
    }

    async function v8ExcluirAnotacao() {
        if (!confirm("Tem certeza que deseja apagar a anotação?")) return;
        let idLote = document.getElementById('anotacao_lote_id').value;
        const res = await v8Req('ajax_api_v8_lote_csv.php', 'salvar_anotacao_lote', { id_lote: idLote, anotacao: '' }, true, "Apagando...");
        if (res.success) { alert("✅ Anotação apagada."); modalAnotacoesLoteObj.hide(); } else { alert("❌ Erro: " + res.msg); }
    }
</script>