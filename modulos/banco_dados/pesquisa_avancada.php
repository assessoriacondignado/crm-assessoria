<div class="collapse" id="painelBuscaAvancada">
    <div class="card border-dark shadow-sm rounded-0 overflow-hidden">
        <div class="card-header bg-dark text-white fw-bold py-2 border-bottom border-dark text-uppercase rounded-0" style="font-size: 0.85rem;">
            <i class="fas fa-filter text-info me-2"></i> Montador de Filtros Aprimorados
        </div>
        <div class="card-body bg-light p-3">
            <form action="" method="GET" id="formBuscaAvancada">
                <input type="hidden" name="busca_avancada" value="1">
                <?php if(isset($is_modo_campanha) && $is_modo_campanha && isset($_GET['id_campanha'])): ?>
                    <input type="hidden" name="id_campanha" value="<?= htmlspecialchars($_GET['id_campanha']) ?>">
                <?php endif; ?>
                
                <div class="d-flex justify-content-between align-items-center mb-3 border-bottom border-dark pb-2">
                    <div class="alert alert-secondary py-1 px-2 small mb-0 border-dark rounded-0" style="font-size: 0.80rem;">
                        <i class="fas fa-lightbulb text-warning"></i> Dica: Use ponto e vírgula (;) para buscar vários. Datas: DD/MM/YYYY.
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input border-dark" type="checkbox" id="filtro_exato" name="filtro_exato" value="1" <?= isset($_GET['filtro_exato']) && $_GET['filtro_exato'] == '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold text-danger small text-uppercase" for="filtro_exato" title="Exige que o cliente tenha registro em todas as tabelas (Cascata estrita)">Filtro Exato (Cascata)</label>
                    </div>
                </div>
                
                <div id="areaFiltros">
                    <?php 
                    $qtd_linhas = max(1, count($filtros_campo ?? [])); 
                    for ($i = 0; $i < $qtd_linhas; $i++): 
                        $c_sel = $filtros_campo[$i] ?? ''; 
                        $o_sel = $filtros_operador[$i] ?? 'contem'; 
                        $v_sel = $filtros_valor[$i] ?? ''; 
                    ?>
                    <div class="row g-2 mb-2 linha-filtro align-items-center">
                        <div class="col-md-3">
                            <select name="campo[]" class="form-select form-select-sm border-dark shadow-sm rounded-0">
                                <optgroup label="Dados Pessoais">
                                    <option value="nome" <?= $c_sel=='nome'?'selected':'' ?>>Nome</option>
                                    <option value="cpf" <?= $c_sel=='cpf'?'selected':'' ?>>CPF</option>
                                    <option value="sexo" <?= $c_sel=='sexo'?'selected':'' ?>>Sexo</option>
                                    <option value="nascimento" <?= $c_sel=='nascimento'?'selected':'' ?>>Nascimento</option>
                                    <option value="idade" <?= $c_sel=='idade'?'selected':'' ?>>Idade</option>
                                    <option value="nome_mae" <?= $c_sel=='nome_mae'?'selected':'' ?>>Nome da Mãe</option>
                                    <option value="nome_pai" <?= $c_sel=='nome_pai'?'selected':'' ?>>Nome do Pai</option>
                                </optgroup>
                                <optgroup label="INSS (Benefícios)">
                                    <option value="inss_especie_ben" <?= $c_sel=='inss_especie_ben'?'selected':'' ?>>Espécie do Benefício</option>
                                    <option value="inss_situacao_ben" <?= $c_sel=='inss_situacao_ben'?'selected':'' ?>>Situação do Benefício</option>
                                    <option value="inss_banco_pag" <?= $c_sel=='inss_banco_pag'?'selected':'' ?>>Banco de Pagamento</option>
                                    <option value="inss_margem" <?= $c_sel=='inss_margem'?'selected':'' ?>>Margem Calculada Livre</option>
                                </optgroup>
                                <optgroup label="INSS (Contratos)">
                                    <option value="inss_matricula" <?= $c_sel=='inss_matricula'?'selected':'' ?>>Matrícula do Contrato</option>
                                    <option value="inss_contrato" <?= $c_sel=='inss_contrato'?'selected':'' ?>>Nº do Contrato</option>
                                    <option value="inss_banco" <?= $c_sel=='inss_banco'?'selected':'' ?>>Banco do Empréstimo</option>
                                    <option value="inss_tipo_emprestimo" <?= $c_sel=='inss_tipo_emprestimo'?'selected':'' ?>>Tipo (Ex: 76, RMC, 98)</option>
                                    <option value="inss_situacao" <?= $c_sel=='inss_situacao'?'selected':'' ?>>Situação do Empréstimo</option>
                                </optgroup>
                                <optgroup label="Documentos">
                                    <option value="rg" <?= $c_sel=='rg'?'selected':'' ?>>RG</option>
                                    <option value="cnh" <?= $c_sel=='cnh'?'selected':'' ?>>CNH</option>
                                    <option value="carteira_profissional" <?= $c_sel=='carteira_profissional'?'selected':'' ?>>Carteira Profissional</option>
                                </optgroup>
                                <optgroup label="Contato & Endereço">
                                    <option value="telefone_cel" <?= $c_sel=='telefone_cel'?'selected':'' ?>>Telefone Celular</option>
                                    <option value="ddd" <?= $c_sel=='ddd'?'selected':'' ?>>DDD</option>
                                    <option value="email" <?= $c_sel=='email'?'selected':'' ?>>E-mail</option>
                                    <option value="cep" <?= $c_sel=='cep'?'selected':'' ?>>CEP</option>
                                    <option value="bairro" <?= $c_sel=='bairro'?'selected':'' ?>>Bairro</option>
                                    <option value="cidade" <?= $c_sel=='cidade'?'selected':'' ?>>Cidade</option>
                                    <option value="uf" <?= $c_sel=='uf'?'selected':'' ?>>UF</option>
                                </optgroup>
                                <optgroup label="Convênios & Matrículas">
                                    <option value="convenio" <?= $c_sel=='convenio'?'selected':'' ?>>Convênio Geral</option>
                                    <option value="matricula" <?= $c_sel=='matricula'?'selected':'' ?>>Matrícula Geral</option>
                                </optgroup>
                                <?php if (isset($perm_filtro_sis) && $perm_filtro_sis): ?>
                                <optgroup label="Sistema">
                                    <option value="agrupamento" <?= $c_sel=='agrupamento'?'selected':'' ?>>Agrupamento</option>
                                    <option value="importacao" <?= $c_sel=='importacao'?'selected':'' ?>>Importação (Nome do Lote)</option>
                                </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select name="operador[]" class="form-select form-select-sm border-dark shadow-sm rounded-0" onchange="verificarVazio(this)">
                                <option value="contem" <?= $o_sel=='contem'?'selected':'' ?>>Contém</option>
                                <option value="igual" <?= $o_sel=='igual'?'selected':'' ?>>Igual a</option>
                                <option value="comeca" <?= $o_sel=='comeca'?'selected':'' ?>>Começa Com</option>
                                <option value="nao_contem" <?= $o_sel=='nao_contem'?'selected':'' ?>>Não Contém</option>
                                <option value="vazio" <?= $o_sel=='vazio'?'selected':'' ?>>É Vazio / Não Tem</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <input type="text" name="valor[]" class="form-control form-control-sm border-dark shadow-sm input-valor rounded-0" value="<?= htmlspecialchars($v_sel) ?>" <?= $o_sel=='vazio' ? 'readonly placeholder="Operador não exige valor"' : 'placeholder="Valor do filtro..."' ?>>
                        </div>
                        <div class="col-md-1 text-center">
                            <button type="button" class="btn btn-outline-danger border-dark btn-sm remover-linha rounded-0"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <div class="d-flex justify-content-between mt-3 border-top border-dark pt-3">
                    <button type="button" class="btn btn-sm btn-dark fw-bold shadow-sm rounded-0" onclick="adicionarFiltro()"><i class="fas fa-plus"></i> Adicionar Regra</button>
                    <div>
                        <a href="consulta.php<?= (isset($is_modo_campanha) && $is_modo_campanha) ? '?id_campanha='.$id_camp : '' ?>" class="btn btn-sm btn-outline-dark shadow-sm bg-white rounded-0">Limpar Tudo</a>
                        <button type="submit" class="btn btn-info text-dark border-dark fw-bold shadow-sm rounded-0 px-4"><i class="fas fa-search me-1"></i> Executar Filtro Cascata</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>