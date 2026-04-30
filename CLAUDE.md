# CLAUDE.md — Referência do Projeto CRM Assessoria Consignado

Este arquivo é lido automaticamente pelo Claude Code no início de cada sessão.
Sempre consulte antes de criar ou editar qualquer funcionalidade.

---

## IDENTIDADE VISUAL / MARCA

| Elemento | Valor |
|---|---|
| Cor principal (laranja) | `#E8621A` |
| Cor secundária (preto) | `#1A1A1A` |
| Cinza de fundo | `#D9D9D9` |
| Branco base | `#FFFFFF` |
| Brand Kit Canva | `kAF6qHkkA64` ("Serv Consig") |

---

## ESTRUTURA DE PASTAS

```
/var/www/html/
├── conexao.php                  — Conexão PDO única
├── includes/
│   ├── header.php               — Menu lateral + sidebar + sessão
│   └── header_busca.php         — Busca do header
├── modulos/
│   ├── banco_dados/             — Consulta, importação, busca de clientes
│   ├── campanhas/               — Campanhas, status, relatórios
│   ├── comercial/               — Produtos, pedidos, comissões
│   ├── financeiro/              — Fluxo de caixa, pagamentos
│   ├── configuracao/            — Integrações (V8, WhatsApp, INSS, Fator)
│   ├── cliente_e_usuario/       — Permissões, cadastro de usuários
│   └── operacional/             — Tarefas, Meta Ads
└── login.php
```

---

## CONEXÃO PDO

```php
include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';
// $pdo já disponível — charset utf8mb4
```

- Sempre use `$_SERVER['DOCUMENT_ROOT']` para includes
- Encoding: `utf8mb4_unicode_ci`
- Prepared statements obrigatórios — nunca concatenar variáveis no SQL

---

## SISTEMA DE PERMISSÕES

### Funções disponíveis (checar_permissoes.php)

```php
// Verifica acesso — MASTER/ADMIN/ADMINISTRADOR sempre passam
verificaPermissao($pdo, 'CHAVE_PERMISSAO', 'TELA')    // tipo: TELA ou FUNCAO
verificaPermissao($pdo, 'CHAVE_PERMISSAO', 'FUNCAO')

// Versão estrita — NÃO bypassa MASTER/ADMIN
verificaPermissaoEstrita($pdo, 'CHAVE_PERMISSAO')

// Para menus no header.php
podeAcessarMenu($pdo, 'CHAVE_MENU')
```

### Grupos especiais (sempre têm acesso em verificaPermissao)
- `MASTER`, `ADMIN`, `ADMINISTRADOR`

### Sessão do usuário logado
```php
$_SESSION['usuario_cpf']    // CPF do usuário
$_SESSION['usuario_id']     // ID do usuário (CLIENTE_USUARIO.ID)
$_SESSION['usuario_nome']   // Nome
$_SESSION['usuario_grupo']  // Grupo (ex: MASTER, CONSULTOR, SUPERVISOR)
```

### Padrão de bloqueio de tela
```php
$caminho_permissoes = $_SERVER['DOCUMENT_ROOT'] . '/modulos/cliente_e_usuario/checar_permissoes.php';
if (file_exists($caminho_permissoes)) include_once $caminho_permissoes;

if (!verificaPermissao($pdo, 'MINHA_CHAVE', 'TELA')) {
    include $caminho_header;
    die("<div class='container mt-5'><div class='alert alert-danger text-center shadow-lg border-dark p-4 rounded-3'><h4 class='fw-bold mb-3'><i class='fas fa-ban'></i> Acesso Negado</h4></div></div>");
}
```

---

## PADRÃO DE ARQUIVOS AJAX

### Cabeçalho obrigatório
```php
<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

include $_SERVER['DOCUMENT_ROOT'] . '/conexao.php';

if (!isset($_SESSION['usuario_cpf'])) {
    echo json_encode(['success' => false, 'msg' => 'Sessão inválida.']); exit;
}
```

### Estrutura de ação
```php
$acao = $_POST['acao'] ?? '';

if ($acao === 'listar') {
    try {
        $stmt = $pdo->prepare("SELECT ...");
        $stmt->execute([$param]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}
```

### Respostas JSON padrão
```php
['success' => true]                              // Operação simples
['success' => true, 'data' => $array]            // Com dados
['success' => true, 'msg' => 'Salvo!']           // Com mensagem
['success' => true, 'redirect' => '/url']        // Com redirect
['success' => false, 'msg' => 'Erro...']         // Erro
```

### Cliente JS (fetch padrão)
```javascript
const fd = new FormData();
fd.append('acao', 'listar');
fd.append('parametro', valor);

fetch('modulo.ajax.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (!res.success) return crmToast(res.msg, 'error');
        // processar res.data
    });
```

---

## COMPONENTES DE UI

### Modal padrão
```html
<div class="modal fade" id="modalExemplo" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-dark shadow-lg rounded-0">
            <div class="modal-header bg-dark text-white border-dark rounded-0 py-2">
                <h6 class="modal-title fw-bold text-uppercase">
                    <i class="fas fa-icon me-2"></i> Título
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <!-- conteúdo -->
            </div>
            <div class="modal-footer bg-white border-top border-dark rounded-0 p-2">
                <button type="button" class="btn btn-sm btn-secondary rounded-0" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-sm btn-primary fw-bold rounded-0">Confirmar</button>
            </div>
        </div>
    </div>
</div>
```

### Tabela padrão
```html
<div class="table-responsive border border-dark shadow-sm">
    <table class="table table-hover table-sm align-middle mb-0 border-dark" style="font-size: 0.80rem;">
        <thead class="table-dark text-uppercase sticky-top">
            <tr>
                <th>Coluna</th>
            </tr>
        </thead>
        <tbody>
            <tr class="border-bottom border-dark">
                <td>Dado</td>
            </tr>
        </tbody>
    </table>
</div>
```

### Botões padrão
```html
<!-- Ação principal -->
<button class="btn btn-sm btn-primary fw-bold rounded-0 shadow-sm border-dark">
    <i class="fas fa-save me-1"></i> Salvar
</button>

<!-- Ação secundária -->
<button class="btn btn-sm btn-outline-secondary fw-bold rounded-0">
    <i class="fas fa-times me-1"></i> Cancelar
</button>

<!-- Perigo -->
<button class="btn btn-sm btn-danger fw-bold rounded-0 shadow-sm">
    <i class="fas fa-trash me-1"></i> Excluir
</button>
```

### Badge de status
```html
<span class="badge bg-success rounded-0">ATIVO</span>
<span class="badge bg-danger rounded-0">INATIVO</span>
<span class="badge bg-warning text-dark rounded-0">PENDENTE</span>
<span class="badge bg-secondary rounded-0">SEM STATUS</span>
```

### Card padrão
```html
<div class="card border-dark shadow-sm rounded-0 mb-3">
    <div class="card-header bg-dark text-white py-2 border-bottom border-dark rounded-0">
        <span class="fw-bold text-uppercase" style="font-size: 0.80rem;">
            <i class="fas fa-icon me-2"></i> Título
        </span>
    </div>
    <div class="card-body bg-light p-3">
        <!-- conteúdo -->
    </div>
</div>
```

### Toast (notificação JS)
```javascript
crmToast('Mensagem de sucesso!', 'success');
crmToast('Erro ao salvar.', 'error');
crmToast('Atenção!', 'warning');
```

---

## MENU SIDEBAR (header.php)

### Padrão de item de menu
```php
<?php if(podeAcessarMenu($pdo, 'MENU_MODULO')): ?>
<div class="sidebar-divider"></div>
<button class="sidebar-item" onclick="sidebarToggleSub(this)">
    <i class="fas fa-icon menu-icon"></i> Nome do Módulo
    <i class="fas fa-chevron-down sidebar-chevron"></i>
</button>
<div class="sidebar-sub">
    <a class="sidebar-subitem" href="/modulos/exemplo/index.php">
        <i class="fas fa-list"></i> Submenu 1
    </a>
    <?php if(podeAcessarMenu($pdo, 'SUBMENU_FUNCAO')): ?>
    <a class="sidebar-subitem" href="/modulos/exemplo/funcao.php">
        <i class="fas fa-cog"></i> Submenu Restrito
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>
```

### Classes sidebar
- `sidebar-item` — Botão de menu principal (com toggle)
- `sidebar-sub` — Container de subitens
- `sidebar-subitem` — Link de subitem
- `sidebar-divider` — Linha separadora entre seções
- `sidebar-chevron` — Ícone de seta expand/collapse

---

## TIPOS DE BUSCA (banco_dados/consulta.php)

### Prefixos de busca rápida (header)
| Prefixo | Busca por |
|---|---|
| `n:nome` | Nome do cliente |
| `c:cpf` | CPF (parcial) |
| `f:telefone` | Telefone |
| CPF 8+ dígitos | CPF direto (sem prefixo) |

### Busca avançada (Filtro Aprimorado)
- CPF exato ou parcial
- Nome (LIKE)
- Agrupamento
- Lote de importação
- Data de inclusão (range)
- Múltiplos filtros combinados com `WHERE 1=1 AND ...`

### Padrão SQL dinâmico
```php
$sql = "SELECT * FROM dados_cadastrais WHERE 1=1";
$params = [];
if (!empty($cpf))  { $sql .= " AND cpf LIKE ?";   $params[] = "%{$cpf}%"; }
if (!empty($nome)) { $sql .= " AND nome LIKE ?";   $params[] = "%{$nome}%"; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
```

---

## HIERARQUIA DE USUÁRIOS

| Grupo | Nível | Acesso |
|---|---|---|
| MASTER | 1 | Tudo — bypass total |
| ADMIN / ADMINISTRADOR | 2 | Tudo — bypass verificaPermissao |
| SUPERVISOR | 3 | Empresa inteira |
| CONSULTOR | 4 | Apenas próprios registros |

- Restrição por empresa: `id_empresa` em todas as tabelas principais
- Restrição por usuário: `id_usuario` / `CPF_USUARIO`

---

## TABELAS PRINCIPAIS

| Tabela | Uso |
|---|---|
| `dados_cadastrais` | Cadastro de clientes (cpf, nome) |
| `telefones` | Telefones dos clientes |
| `enderecos` | Endereços |
| `CLIENTE_USUARIO` | Usuários do sistema |
| `CLIENTE_EMPRESAS` | Empresas |
| `CLIENTE_USUARIO_PERMISSAO` | Permissões por grupo |
| `BANCO_DE_DADOS_CAMPANHA_CAMPANHAS` | Campanhas |
| `BANCO_DE_DADOS_CAMPANHA_REGISTRO_CONTATO` | Registros de atendimento |
| `BANCO_DE_DADOS_CLIENTES_DA_CAMPANHA` | Clientes vinculados a campanhas |
| `INTEGRACAO_V8_IMPORTACAO_LOTE` | Lotes de consulta V8 |
| `CONTROLE_IMPORTACAO_ASSINCRONA` | Fila de importação de planilhas |

---

## CONVENÇÕES GERAIS

- **Nunca** concatenar variáveis diretamente no SQL — sempre prepared statements
- **Sempre** usar `ob_clean()` antes de `header('Content-Type: application/json')` em handlers AJAX que ficam no meio de arquivos PHP grandes
- **Sempre** incluir `checar_permissoes.php` via `include_once`
- **Não** usar `rounded` — padrão do projeto é `rounded-0` (cantos retos)
- **Não** adicionar comentários explicando o que o código faz — apenas o porquê quando não óbvio
- Datas no banco: `datetime` — formatação BR via `DATE_FORMAT(..., '%d/%m/%Y %H:%i')`
- Encoding: sempre `utf8mb4` — nunca `utf8` simples
- FontAwesome 5 Free para ícones (`fas fa-*`, `fab fa-*`)
- Bootstrap 5 para layout e componentes
