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
        duracao = (duracao !== undefined) ? duracao : (tipo === 'error' ? 6000 : tipo === 'warning' ? 5000 : 4000);
        const wrap = document.getElementById('crm-toast-wrap');
        if (!wrap) return;
        const t = document.createElement('div');
        t.className = 'crm-toast crm-t-' + tipo;
        t.innerHTML = '<i class="fas ' + (ICONS[tipo]||'fa-bell') + ' crm-toast-icon"></i>'
                    + '<span>' + msg + '</span>'
                    + '<button class="crm-toast-close" onclick="this.parentNode.remove()">&times;</button>';
        wrap.appendChild(t);
        requestAnimationFrame(function(){ requestAnimationFrame(function(){ t.classList.add('in'); }); });
        setTimeout(function(){
            t.classList.remove('in');
            setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 320);
        }, duracao);
    };
    /* Alias para compatibilidade com o módulo V8 */
    window.v8Toast = window.crmToast;
})();
</script>
</body>
</html>