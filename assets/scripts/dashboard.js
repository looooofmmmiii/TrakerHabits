(function(){
    'use strict';

    // Read config injected by server
    var cfg = window.__DASHBOARD_CONFIG__ || {};
    var CSRF = cfg.csrf || '';
    var SAVE_DEBOUNCE_MS = cfg.save_debounce_ms || 600;
    var chart_labels = cfg.chart_labels || [];
    var chart_values = cfg.chart_values || [];
    var predicted = typeof cfg.predicted !== 'undefined' ? cfg.predicted : null;
    window.INCOMPLETE = cfg.incomplete || [];
    var flashSuccess = cfg.flash_success || null;
    var flashError = cfg.flash_error || null;

    var saveTimer = null;

    /* ----------------------
       Save / Sortable helpers
       ---------------------- */
    function saveOrderToServer(){
    var cards = Array.from(document.querySelectorAll('.grid .habit-card'));
    // only include not-done items in the order we send
    var order = cards
        .filter(function(c){ return String(c.dataset.done || '0') !== '1'; })
        .map(function(c){ return parseInt(c.dataset.hid,10) || 0; })
        .filter(Boolean);
    if (!order.length) return;

    fetch('dashboard.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order: order, csrf_token: CSRF })
    })
    .then(function(resp){
        if (!resp.ok) return resp.text().then(function(t){ throw new Error('Server ' + resp.status + ': ' + (t||resp.statusText)); });
        var ct = resp.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) return resp.text().then(function(t){ throw new Error('Invalid JSON response: ' + t); });
        return resp.json();
    })
    .then(function(json){
        if (json && json.ok && Array.isArray(json.order) && json.order.length) {
            var grid = document.querySelector('.grid');
            if (grid) {
                // build map of elements
                var map = {};
                Array.from(grid.querySelectorAll('.habit-card')).forEach(function(c){
                    var id = parseInt(c.dataset.hid,10)||0; if (id) map[id]=c;
                });
                // append only not-done elements in server canonical order
                json.order.forEach(function(id){
                    var el = map[id];
                    if (!el) return;
                    if (String(el.dataset.done || '0') === '1') return; // leave completed items where they are
                    grid.appendChild(el);
                });
            }
        }
        if (json && json.ok) showFlash('Order saved', 'success');
        else showFlash((json && json.error) ? json.error : 'Save failed', 'error');
    })
    .catch(function(err){ console.error('saveOrder error', err); showFlash('Network error while saving order — ' + (err && err.message ? err.message : 'check console'), 'error'); });
}


    function initSortableGrid(){
        var grid = document.querySelector('.grid');
        if (!grid) return;

        // Try to create Sortable — if library not loaded yet, poll a few times
        var attempts = 0;
        function tryCreate(){
            attempts++;
            if (typeof Sortable === 'undefined') {
                if (attempts > 30) { // ~3s total
                    console.warn('SortableJS missing — reorder disabled');
                    return;
                }
                setTimeout(tryCreate, 100);
                return;
            }

            // create sortable instance
            try {
                Sortable.create(grid, {
                    animation: 160,
                    handle: '.drag-handle',
                    draggable: '.habit-card',
                    chosenClass: 'sortable-chosen',
                    ghostClass: 'sortable-ghost',
                    onEnd: function(){ clearTimeout(saveTimer); saveTimer = setTimeout(saveOrderToServer, SAVE_DEBOUNCE_MS); }
                });
            } catch (e) {
                console.error('Sortable.create error', e);
            }
        }

        tryCreate();
    }

    /* ----------------------
       UI / utilities
       ---------------------- */
    function toggleDesc(id) {
        var elId = (typeof id === 'number') ? 'desc-' + id : 'desc-' + String(id);
        var el = document.getElementById(elId) || document.getElementById('desc-full-' + id);
        var wrapper = el ? el.closest('.habit-desc') : null;
        if (!wrapper) return;
        var btn = wrapper.querySelector('.desc-toggle');
        var expanded = wrapper.classList.toggle('expanded');
        if (btn) {
            btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            btn.textContent = expanded ? 'Show less' : 'Show more';
        }
    }

    function initDescriptionToggles() {
        document.querySelectorAll('.habit-desc').forEach(function(wrap){
            var txt = wrap.querySelector('.desc-text');
            var btn = wrap.querySelector('.desc-toggle');
            if (!txt || !btn) return;
            var isClipped = txt.scrollHeight > txt.clientHeight + 1;
            btn.style.display = isClipped ? 'inline-block' : 'none';
            wrap.querySelectorAll('a').forEach(function(a){ a.addEventListener('click', function(ev){ ev.stopPropagation(); }); });
        });
    }

    function toggleCard(card){
        var details = card.querySelector('.habit-details');
        if (!details) return;
        var expanded = card.getAttribute('aria-expanded') === 'true';
        if (expanded) { details.style.display = 'none'; details.setAttribute('aria-hidden','true'); card.setAttribute('aria-expanded','false'); }
        else { details.style.display = 'block'; details.setAttribute('aria-hidden','false'); card.setAttribute('aria-expanded','true'); }
    }

    function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

    function showFlash(message, type) {
        var root = document.getElementById('flash'); if (!root) return;
        var el = document.createElement('div'); el.textContent = message; el.setAttribute('role','status'); el.style.padding = '10px 14px'; el.style.borderRadius = '10px'; el.style.marginTop = '8px'; el.style.boxShadow = '0 6px 18px rgba(2,6,23,0.08)'; el.style.background = (type === 'error') ? '#fee2e2' : '#ecfdf5'; el.style.color = (type === 'error') ? '#991b1b' : '#065f46'; root.appendChild(el); setTimeout(function(){ el.style.opacity = '0'; el.style.transition = 'opacity .6s ease'; }, 2200); setTimeout(function(){ try{ root.removeChild(el); }catch(e){} }, 3000);
    }

    /* Roulette helpers */
    function buildWheel(items, container) {
        if (!container) return; container.innerHTML = '';
        if (!items || items.length === 0) return;
        var n = items.length; var seg = 360 / n;
        var palette = ['#f97316','#fb923c','#f43f5e','#f87171','#f59e0b','#34d399','#60a5fa','#a78bfa'];
        var stops = [];
        for (var i = 0; i < n; i++) { var color = palette[i % palette.length]; var start = i * seg; var end = (i+1) * seg; stops.push(color + ' ' + start + 'deg ' + end + 'deg'); }
        container.style.background = 'conic-gradient(' + stops.join(',') + ')'; container.style.transform = 'rotate(0deg)'; container.style.transition = 'transform 4s cubic-bezier(.17,.67,.34,1)';
        for (var i = 0; i < n; i++) {
            var lbl = document.createElement('div'); lbl.className = 'wheel-label-item'; lbl.setAttribute('role','presentation'); lbl.style.position = 'absolute'; lbl.style.left = '50%'; lbl.style.top = '50%'; lbl.style.transformOrigin = '0 0'; var angle = (i + 0.5) * seg; lbl.style.transform = 'rotate(' + angle + 'deg) translate(0, -138px) rotate(-' + angle + 'deg)'; lbl.style.fontSize = '13px'; lbl.style.pointerEvents = 'none'; lbl.style.width = '120px'; lbl.style.textAlign = 'center'; lbl.style.marginLeft = '-60px'; lbl.style.color = '#062a2a'; lbl.textContent = items[i].title || 'Untitled'; container.appendChild(lbl);
        }
    }

    var _spinning = false;
    function spinWheel(items, container, labelEl, goToEl) {
        if (_spinning) return; if (!items || items.length === 0) return; _spinning = true;
        var n = items.length; var seg = 360 / n; var index = Math.floor(Math.random() * n); var rounds = Math.floor(Math.random() * 3) + 4; var randOffset = (Math.random() - 0.5) * (seg * 0.6); var targetMid = index * seg + seg/2; var targetAngle = rounds * 360 + (360 - (targetMid + randOffset));
        container.style.transition = 'transform 4.2s cubic-bezier(.17,.67,.34,1)'; requestAnimationFrame(function(){ container.style.transform = 'rotate(' + targetAngle + 'deg)'; });
        if (labelEl) labelEl.textContent = 'Spinning...';
        setTimeout(function(){ _spinning = false; var final = targetAngle % 360; container.style.transition = 'none'; container.style.transform = 'rotate(' + (final) + 'deg)'; var landed = Math.floor(((360 - final + seg/2) % 360) / seg); landed = (landed + n) % n; var item = items[landed] || items[index] || {title: 'Unknown', id: null}; if (labelEl) labelEl.textContent = item.title || 'Selected'; showFlash('Selected: ' + item.title, 'success'); if (goToEl) { if (item.id) { goToEl.href = 'habit_history.php?id=' + encodeURIComponent(item.id); goToEl.style.display = 'inline-block'; } else { goToEl.style.display = 'none'; } } }, 4400);
    }

    /* Mini SVG chart */
    function renderMiniChart(elId, labels, values, predictedVal) {
    var container = document.getElementById(elId); if (!container) return; container.innerHTML = '';
    if (!values || values.length === 0) { container.innerHTML = '<div class="muted" style="padding:18px;text-align:center">No data</div>'; return; }

    var rect = container.getBoundingClientRect();
    var w = Math.max(320, Math.floor(rect.width)) || 600;
    var h = Math.max(120, Math.floor(rect.height)) || 140;
    var padding = {l:28, r:12, t:12, b:22};
    var plotW = w - padding.l - padding.r;
    var plotH = h - padding.t - padding.b;

    var pts = values.map(function(v){ return clamp(parseFloat(v) || 0, 0, 100); });
    var maxV = 100;

    // determine total slots (historical points + optional predicted)
    var hasPred = (typeof predictedVal === 'number');
    var labelCount = (labels && labels.length) ? labels.length : 0;
    // total slots should reflect labels if provided, else values + predicted
    var totalSlots = Math.max(labelCount, pts.length + (hasPred ? 1 : 0), 2);
    var stepX = plotW / Math.max(1, totalSlots - 1);

    // compute positions for historical points
    var poly = [];
    for (var i = 0; i < pts.length; i++) {
        var x = padding.l + i * stepX;
        var y = padding.t + (1 - (pts[i] / maxV)) * plotH;
        poly.push({ x: x, y: y, v: pts[i], label: (labels && labels[i]) ? labels[i] : '' });
    }

    var svgNS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('width', '100%');
    svg.setAttribute('height', h);
    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'Efficiency by day chart');

    // grid lines
    for (var g = 0; g <= 4; g++) {
        var y = padding.t + (g / 4) * plotH;
        var line = document.createElementNS(svgNS, 'line');
        line.setAttribute('x1', padding.l);
        line.setAttribute('x2', w - padding.r);
        line.setAttribute('y1', y);
        line.setAttribute('y2', y);
        line.setAttribute('stroke', '#eef2ff');
        line.setAttribute('stroke-width', '1');
        svg.appendChild(line);
    }

    // path for historical points
    if (poly.length) {
        var pathD = poly.map(function(p, i){ return (i === 0 ? 'M' : 'L') + p.x + ' ' + p.y; }).join(' ');
        var path = document.createElementNS(svgNS, 'path');
        path.setAttribute('d', pathD);
        path.setAttribute('fill', 'none');
        path.setAttribute('stroke', '#2563eb');
        path.setAttribute('stroke-width', '2');
        svg.appendChild(path);
    }

    // circles + tooltips for historical
    poly.forEach(function(p, i){
        var c = document.createElementNS(svgNS, 'circle');
        c.setAttribute('cx', p.x);
        c.setAttribute('cy', p.y);
        c.setAttribute('r', 4);
        c.setAttribute('fill', '#2563eb');
        c.setAttribute('tabindex', '0');
        c.setAttribute('aria-label', (p.label || '') + ': ' + p.v + '%');
        svg.appendChild(c);
        (function(px, py, lbl, val){
            c.addEventListener('mouseenter', function(){ showTooltip(container, px, py, lbl, val); });
            c.addEventListener('focus', function(){ showTooltip(container, px, py, lbl, val); });
            c.addEventListener('mouseleave', hideTooltip);
            c.addEventListener('blur', hideTooltip);
        })(p.x, p.y, p.label, p.v);
    });

    // predicted point (dashed) placed exactly one slot after last historical point
    if (hasPred) {
        var last = poly.length ? poly[poly.length - 1] : { x: padding.l, y: padding.t + (1 - 0) * plotH };
        var predIndex = pts.length; // position index for predicted
        var xPred = padding.l + predIndex * stepX;
        var yPred = padding.t + (1 - (clamp(predictedVal, 0, 100) / maxV)) * plotH;

        // dashed connector from last to predicted
        var d = 'M' + last.x + ' ' + last.y + ' L ' + xPred + ' ' + yPred;
        var pPred = document.createElementNS(svgNS, 'path');
        pPred.setAttribute('d', d);
        pPred.setAttribute('fill', 'none');
        pPred.setAttribute('stroke', '#00ff51ff');
        pPred.setAttribute('stroke-width', '1.5');
        pPred.setAttribute('stroke-dasharray', '6 6');
        svg.appendChild(pPred);

        // predicted circle + label
        var cp = document.createElementNS(svgNS, 'circle');
        cp.setAttribute('cx', xPred);
        cp.setAttribute('cy', yPred);
        cp.setAttribute('r', 3.5);
        cp.setAttribute('fill', '#00ff51ff');
        svg.appendChild(cp);

        var t = document.createElementNS(svgNS, 'text');
        t.setAttribute('x', xPred);
        t.setAttribute('y', yPred - 8);
        t.setAttribute('text-anchor', 'middle');
        t.setAttribute('font-size', '11');
        t.setAttribute('fill', '#003224ff');
        t.textContent = predictedVal + '%';
        svg.appendChild(t);

        // aria-friendly tooltip via mouse events on predicted circle
        cp.setAttribute('tabindex', '0');
        cp.setAttribute('aria-label', ((labels && labels[predIndex]) ? labels[predIndex] + ': ' : 'Predicted: ') + predictedVal + '%');
        cp.addEventListener('mouseenter', function(){ showTooltip(container, xPred, yPred, (labels && labels[predIndex]) ? labels[predIndex] : 'Predicted', predictedVal); });
        cp.addEventListener('focus', function(){ showTooltip(container, xPred, yPred, (labels && labels[predIndex]) ? labels[predIndex] : 'Predicted', predictedVal); });
        cp.addEventListener('mouseleave', hideTooltip);
        cp.addEventListener('blur', hideTooltip);
    }

    container.appendChild(svg);
}


    var _tt = null;
    function showTooltip(container, x, y, label, value){ hideTooltip(); _tt = document.createElement('div'); _tt.className = 'mini-tt'; _tt.style.position = 'absolute'; _tt.style.left = (x + 8) + 'px'; _tt.style.top = (y - 18) + 'px'; _tt.style.padding = '6px 8px'; _tt.style.borderRadius = '6px'; _tt.style.background = '#ffffff'; _tt.style.boxShadow = '0 6px 18px rgba(2,6,23,0.06)'; _tt.style.fontSize = '12px'; _tt.textContent = (label ? label + ': ' : '') + value + '%'; container.appendChild(_tt); }
    function hideTooltip(){ try{ if(_tt && _tt.parentNode) _tt.parentNode.removeChild(_tt); _tt = null; }catch(e){} }

    /* ----------------------
       Init function (call on DOM ready)
       ---------------------- */
    function initDashboard(opts) {
        opts = opts || {};
        try { initDescriptionToggles(); } catch(e){ console.warn('initDescriptionToggles error', e); }
        window.addEventListener('resize', function(){ clearTimeout(window._descResizeTimer); window._descResizeTimer = setTimeout(initDescriptionToggles, 250); });
        var pb = document.getElementById('progressBar'); if (pb) { var val = parseInt(pb.getAttribute('aria-valuenow') || '0',10); setTimeout(function(){ pb.style.width = Math.max(0, Math.min(100, val)) + '%'; }, 60); }
        var open = document.getElementById('rouletteOpen'); var modal = document.getElementById('rouletteModal'); var close = document.getElementById('closeModal'); var spinBtn = document.getElementById('spinBtn'); var wheel = document.getElementById('wheel'); var wheelLabel = document.getElementById('wheelLabel'); var goTo = document.getElementById('goToTask');
        if (open && modal && close) { open.addEventListener('click', function(){ if (!window.INCOMPLETE || window.INCOMPLETE.length === 0) { showFlash('No incomplete tasks to spin', 'error'); return; } buildWheel(window.INCOMPLETE, wheel); modal.style.display = 'flex'; modal.setAttribute('aria-hidden','false'); if (wheelLabel) wheelLabel.textContent = 'Press Spin'; if (goTo) goTo.style.display = 'none'; }); close.addEventListener('click', function(){ modal.style.display = 'none'; modal.setAttribute('aria-hidden','true'); }); }
        if (spinBtn) { spinBtn.addEventListener('click', function(){ if (!window.INCOMPLETE || window.INCOMPLETE.length === 0) return; spinWheel(window.INCOMPLETE, wheel, wheelLabel, goTo); }); }
        document.querySelectorAll('.habit-card').forEach(function(card){ card.addEventListener('click', function(e){ if (e.target && (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('form'))) return; toggleCard(card); }); card.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleCard(card); } }); });
        initSortableGrid();
        try { if (flashSuccess) showFlash(flashSuccess, 'success'); if (flashError) showFlash(flashError, 'error'); } catch(e){}
        try { renderMiniChart('miniChart', chart_labels, chart_values, predicted); } catch(e){}
    }

    // Export public API
    window.initDashboard = initDashboard;
    window.dashboardHelpers = { saveOrderToServer: saveOrderToServer, toggleDesc: toggleDesc, showFlash: showFlash, renderMiniChart: renderMiniChart, buildWheel: buildWheel, spinWheel: spinWheel };

    // Auto-init on page load (fixes case where external loader didn't call init)
    try {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function(){ try { initDashboard(); } catch(e){ console.error('initDashboard error', e); } });
        } else {
            initDashboard();
        }
    } catch (e) {
        console.error('auto-init failed', e);
    }

})();
