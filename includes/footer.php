</div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- ===== SISTEMA GLOBAL DE NOTIFICAÇÕES (TOAST) ===== -->
<style>
#crm-toast-wrap { position:fixed; top:16px; right:16px; z-index:999999; display:flex; flex-direction:column; gap:7px; pointer-events:none; }
.crm-toast { display:flex; align-items:flex-start; gap:10px; padding:11px 15px; border-radius:7px; font-size:13px; font-weight:600; color:#fff; box-shadow:0 4px 18px rgba(0,0,0,.28); max-width:380px; pointer-events:auto; opacity:0; transform:translateX(70px); transition:opacity .28s, transform .28s; line-height:1.4; }
.crm-toast.in { opacity:1; transform:translateX(0); }
.crm-toast-icon { font-size:16px; flex-shrink:0; margin-top:1px; }
.crm-toast-close { margin-left:auto; flex-shrink:0; background:none; border:none; color:rgba(255,255,255,.75); font-size:15px; cursor:pointer; padding:0 0 0 6px; line-height:1; }
.crm-toast-close:hover { color:#fff; }
.crm-t-success { background:#198754; }
.crm-t-error   { background:#dc3545; }
.crm-t-warning { background:#e67e22; }
.crm-t-info    { background:#0d6efd; }
</style>
<div id="crm-toast-wrap"></div>
<script>
(function(){
    const ICONS = { success:'fa-check-circle', error:'fa-times-circle', warning:'fa-exclamation-triangle', info:'fa-info-circle' };
    window.crmToast = function(msg, tipo, duracao) {
        tipo = tipo || 'success';
        // duracao=0 ou não informado = permanente (usuário fecha com X)
        // duracao>0 = auto-fecha após N ms (legado, uso explícito)
        duracao = (duracao !== undefined && duracao > 0) ? duracao : 0;
        const wrap = document.getElementById('crm-toast-wrap');
        if (!wrap) return;
        const t = document.createElement('div');
        t.className = 'crm-toast crm-t-' + tipo;
        t.innerHTML = '<i class="fas ' + (ICONS[tipo]||'fa-bell') + ' crm-toast-icon"></i>'
                    + '<span>' + msg + '</span>'
                    + '<button class="crm-toast-close" onclick="this.parentNode.remove()">&times;</button>';
        wrap.appendChild(t);
        requestAnimationFrame(function(){ requestAnimationFrame(function(){ t.classList.add('in'); }); });
        if (duracao > 0) {
            setTimeout(function(){
                t.classList.remove('in');
                setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 320);
            }, duracao);
        }
    };
    /* Alias para compatibilidade com o módulo V8 */
    window.v8Toast = window.crmToast;
})();
</script>

<!-- ===== WIDGET GUIA SISTEMA ===== -->
<style>
#guia-widget-btn {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: #fff;
    border: 2.5px solid #0d6efd;
    box-shadow: 0 3px 14px rgba(13,110,253,.35);
    cursor: pointer;
    font-size: 28px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform .18s, box-shadow .18s;
}
#guia-widget-btn:hover { transform: scale(1.1); box-shadow: 0 5px 20px rgba(13,110,253,.5); }
@keyframes guia-pulse {
    0%   { box-shadow: 0 0 0 0 rgba(13,110,253,.6); }
    70%  { box-shadow: 0 0 0 12px rgba(13,110,253,0); }
    100% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
}
#guia-widget-btn.com-guias { animation: guia-pulse 2s ease-out 1.5s 3; }
/* Badge estilo expoente/superscript */
#guia-widget-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 900;
    min-width: 18px;
    height: 18px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #fff;
    line-height: 1;
    box-shadow: 0 2px 6px rgba(220,53,69,.55);
    font-family: Arial, sans-serif;
    letter-spacing: 0;
}
#guia-widget-tooltip {
    position: fixed;
    bottom: 82px;
    right: 18px;
    z-index: 99991;
    background: #1e3a5f;
    color: #fff;
    padding: 8px 13px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    max-width: 230px;
    line-height: 1.4;
    box-shadow: 0 3px 12px rgba(0,0,0,.25);
    display: none;
    pointer-events: none;
}
#guia-widget-tooltip::after {
    content: '';
    position: absolute;
    bottom: -7px; right: 20px;
    border: 7px solid transparent;
    border-bottom: 0;
    border-top-color: #1e3a5f;
}
/* Painel lateral */
#guia-side-panel {
    position: fixed;
    top: 0;
    right: -26%;
    width: 25%;
    height: 100vh;
    z-index: 99992;
    background: #fff;
    box-shadow: -4px 0 24px rgba(0,0,0,.18);
    display: flex;
    flex-direction: column;
    transition: right .3s ease;
    border-left: 3px solid #0d6efd;
}
#guia-side-panel.open { right: 0; }
#guia-panel-header {
    background: #0d6efd;
    color: #fff;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
#guia-panel-header .gp-title { flex: 1; font-weight: 700; font-size: 15px; }
#guia-panel-close {
    background: none;
    border: none;
    color: rgba(255,255,255,.8);
    font-size: 20px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
}
#guia-panel-close:hover { color: #fff; }
#guia-panel-body { flex: 1; overflow-y: auto; padding: 0; }
/* Lista de guias */
.guia-list-item {
    padding: 12px 16px;
    border-bottom: 1px solid #e9ecef;
    cursor: pointer;
    transition: background .15s;
}
.guia-list-item:hover { background: #f0f5ff; }
.guia-list-item .gi-titulo { font-weight: 700; font-size: 13px; color: #1a1a2e; }
.guia-list-item .gi-coment { font-size: 11px; color: #666; margin-top: 2px; }
.guia-list-item .gi-tipo { font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 4px; }
/* View de conteúdo */
#guia-panel-content { padding: 16px; }
#guia-panel-back {
    display: flex;
    align-items: center;
    gap: 6px;
    background: none;
    border: none;
    color: #0d6efd;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
    padding: 8px 16px;
    border-bottom: 1px solid #e9ecef;
    width: 100%;
    text-align: left;
}
#guia-panel-back:hover { background: #f0f5ff; }
#guia-content-titulo { font-size: 14px; font-weight: 700; padding: 10px 16px 6px; color: #1a1a2e; }
#guia-content-coment { font-size: 11px; color: #888; padding: 0 16px 10px; }
#guia-content-area { padding: 0 16px 16px; font-size: 13px; line-height: 1.6; }
#guia-content-area video, #guia-content-area img { max-width: 100%; border-radius: 6px; }
#guia-panel-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.15);
    z-index: 99991;
}
@media (max-width: 768px) {
    #guia-side-panel { width: 90%; right: -91%; }
    #guia-side-panel.open { right: 0; }
}
</style>

<div id="guia-panel-overlay" onclick="guiaPanelFechar()"></div>

<div id="guia-widget-wrap" style="position:fixed;bottom:22px;right:22px;z-index:99990;display:none;">
    <div id="guia-widget-btn" title="Abrir Guia Sistema">
        <span style="position:relative;top:1px;">👨‍🏫</span>
    </div>
    <span id="guia-widget-badge" style="display:none;"></span>
</div>
<div id="guia-widget-tooltip"></div>

<div id="guia-side-panel">
    <div id="guia-panel-header">
        <span style="font-size:20px;">👨‍🏫</span>
        <span class="gp-title">Guia Sistema</span>
        <button id="guia-panel-close" onclick="guiaPanelFechar()">×</button>
    </div>
    <div id="guia-panel-body">
        <!-- Lista ou conteúdo é injetado via JS -->
        <div id="guia-panel-list"></div>
        <div id="guia-panel-view" style="display:none;">
            <button id="guia-panel-back" onclick="guiaPanelVoltarLista()">
                <i class="fas fa-arrow-left"></i> Voltar à lista
            </button>
            <div id="guia-content-titulo"></div>
            <div id="guia-content-coment"></div>
            <div id="guia-content-area"></div>
        </div>
    </div>
</div>

<script>
(function(){
    const GUIA_AJAX = '/modulos/configuracao/anotacoes/guia_ajax.php';
    let _guias = [];
    let _loaded = false;

    const wrap    = document.getElementById('guia-widget-wrap');
    const btn     = document.getElementById('guia-widget-btn');
    const badge   = document.getElementById('guia-widget-badge');
    const tooltip = document.getElementById('guia-widget-tooltip');
    const panel   = document.getElementById('guia-side-panel');
    const overlay = document.getElementById('guia-panel-overlay');
    const pList   = document.getElementById('guia-panel-list');
    const pView   = document.getElementById('guia-panel-view');

    async function carregarGuias() {
        if (_loaded) return;
        _loaded = true;
        try {
            const fd = new FormData(); fd.append('acao','listar_widget');
            const r = await fetch(GUIA_AJAX, {method:'POST', body:fd});
            const j = await r.json();
            if (j.success && j.data && j.data.length) {
                _guias = j.data;
                // Exibe o wrapper
                wrap.style.display = 'block';
                // Badge exponencial — sempre visível, mostra a quantidade
                badge.textContent = _guias.length;
                badge.style.display = 'flex';
                // Pulso de atenção
                btn.classList.add('com-guias');
                renderLista();
            }
        } catch(e) {}
    }

    // Normaliza índice removendo ponto final ("1." → "1")
    function normIdx(idx) { return (idx || '').replace(/\.$/, ''); }

    function buildTree(dados) {
        const byId = {}, byIdx = {};
        dados.forEach(g => {
            byId[g.ID] = {...g, children: []};
            const idx = normIdx(g.INDICE);
            if (idx) byIdx[idx] = byId[g.ID];
        });
        const roots = [];
        dados.forEach(g => {
            const idx = normIdx(g.INDICE);
            if (!idx || !idx.includes('.')) { roots.push(byId[g.ID]); return; }
            const parts = idx.split('.'); parts.pop();
            const parent = byIdx[parts.join('.')];
            parent ? parent.children.push(byId[g.ID]) : roots.push(byId[g.ID]);
        });
        return roots;
    }

    function renderNode(node, depth) {
        const hasKids = node.children.length > 0;
        const pad = 12 + depth * 16;
        const cid = 'gn-' + node.ID;
        const idxLabel = normIdx(node.INDICE);
        const badge = idxLabel
            ? `<span style="font-size:10px;font-weight:700;padding:1px 5px;border-radius:3px;background:#e9ecef;color:#495057;flex-shrink:0;">${escG(idxLabel)}</span>`
            : '';
        let h = `<div>`;
        h += `<div class="guia-list-item d-flex align-items-center gap-1" style="padding:9px 12px 9px ${pad}px;cursor:default;">`;
        if (hasKids) {
            h += `<button onclick="guiaToggleNo(event,'${cid}',this)"
                           style="width:18px;height:18px;flex-shrink:0;border:1px solid #ccc;border-radius:3px;background:#f8f9fa;font-weight:bold;font-size:11px;cursor:pointer;padding:0;line-height:1;">+</button>`;
        } else {
            h += `<span style="width:18px;flex-shrink:0;display:inline-block;"></span>`;
        }
        h += `<span class="flex-grow-1 d-flex align-items-center gap-1" onclick="guiaPanelAbrirConteudo(${node.ID})" style="cursor:pointer;">
                  ${badge}
                  <span class="gi-titulo" style="font-size:13px;">${escG(node.TITULO)}</span>
              </span>`;
        h += `</div>`;
        if (hasKids) {
            h += `<div id="${cid}" style="display:none;">`;
            node.children.forEach(c => { h += renderNode(c, depth + 1); });
            h += `</div>`;
        }
        h += `</div>`;
        return h;
    }

    window.guiaToggleNo = function(e, cid, btn) {
        e.stopPropagation();
        const el = document.getElementById(cid);
        if (!el) return;
        const open = el.style.display !== 'none';
        el.style.display = open ? 'none' : '';
        btn.textContent = open ? '+' : '−';
    };

    function renderLista() {
        if (!_guias.length) {
            pList.innerHTML = '<div class="text-center py-5 text-muted small fw-bold">Nenhum guia disponível.</div>';
            return;
        }
        pList.innerHTML = buildTree(_guias).map(r => renderNode(r, 0)).join('');
    }

    function escG(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // Hover tooltip
    btn.addEventListener('mouseenter', function() {
        if (!_guias.length) return;
        const txt = _guias.length === 1
            ? '<b>' + escG(_guias[0].TITULO) + '</b>' + (_guias[0].COMENTARIO ? '<br><span style="font-weight:400;opacity:.85">' + escG(_guias[0].COMENTARIO) + '</span>' : '')
            : '<b>' + _guias.length + ' treinamentos disponíveis</b>';
        tooltip.innerHTML = txt;
        tooltip.style.display = 'block';
    });
    btn.addEventListener('mouseleave', function() { tooltip.style.display = 'none'; });

    btn.addEventListener('click', function() {
        tooltip.style.display = 'none';
        guiaPanelVoltarLista();
        panel.classList.add('open');
        overlay.style.display = 'block';
    });

    window.guiaPanelFechar = function() {
        panel.classList.remove('open');
        overlay.style.display = 'none';
    };

    window.guiaPanelVoltarLista = function() {
        pView.style.display = 'none';
        pList.style.display = '';
        document.getElementById('guia-content-area').innerHTML = '';
    };

    window.guiaPanelAbrirConteudo = function(id) {
        const fd = new FormData(); fd.append('acao','get'); fd.append('id',id);
        fetch(GUIA_AJAX, {method:'POST', body:fd})
            .then(r => r.json()).then(j => {
                if (!j.success) return;
                const d = j.data;
                document.getElementById('guia-content-titulo').textContent = d.TITULO;
                document.getElementById('guia-content-coment').textContent = d.COMENTARIO || '';
                document.getElementById('guia-content-area').innerHTML = d.CONTEUDO_HTML || '<p class="text-muted text-center py-4 small">Conteúdo não disponível.</p>';
                pList.style.display = 'none';
                pView.style.display = '';
            });
    };

    // Carrega após página pronta
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', carregarGuias);
    } else {
        setTimeout(carregarGuias, 800);
    }
})();
</script>
</body>
</html>