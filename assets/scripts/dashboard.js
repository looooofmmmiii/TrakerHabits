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
    var historySeries = Array.isArray(cfg.history_series) ? cfg.history_series : [];
    var historyStart = cfg.history_start || '';
    var historyEnd = cfg.history_end || '';
    var historyActiveDays = typeof cfg.history_active_days === 'number' ? cfg.history_active_days : parseInt(cfg.history_active_days, 10) || 0;
    var historyTotals = cfg.history_totals || {};
    var weeklyComparison = cfg.weekly_comparison || {};
    var monthlyComparison = cfg.monthly_comparison || {};

    function historyLog(message, payload) {
        if (typeof console !== 'undefined' && console.log) {
            if (typeof payload !== 'undefined') {
                console.log('[HistoryChart]', message, payload);
            } else {
                console.log('[HistoryChart]', message);
            }
        }
    }
    function historyLogError(message, err) {
        if (typeof console !== 'undefined' && console.error) {
            console.error('[HistoryChart:Error]', message, err);
        }
    }

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

    function renderHistoryMvpChart(target, series) {
        var container = typeof target === 'string' ? document.getElementById(target) : target;
        if (!container) {
            historyLogError('Container not found', target);
            return;
        }

        container.innerHTML = '';

        if (!Array.isArray(series) || !series.length) {
            historyLog('No data available for chart', { points: Array.isArray(series) ? series.length : 'n/a' });
            container.innerHTML = '<div class="empty-state">No activity tracked yet</div>';
            return;
        }

        var width = container.clientWidth || container.offsetWidth || 600;
        var height = container.clientHeight || 0;
        if (!width || width < 360) width = 360;
        if (!height || height < 260) height = 260;

        var canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        canvas.style.width = '100%';
        canvas.style.height = height + 'px';
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', 'Habit efficiency history chart');
        container.appendChild(canvas);

        var ctx = canvas.getContext('2d');
        var padding = { l: 56, r: 24, t: 32, b: 56 };
        var plotW = Math.max(10, width - padding.l - padding.r);
        var plotH = Math.max(10, height - padding.t - padding.b);

        var values = series.map(function(item){
            var val = parseFloat(item && item.efficiency);
            if (!isFinite(val)) val = 0;
            if (val < 0) val = 0;
            if (val > 100) val = 100;
            return val;
        });

        var includeYear = false;
        if (series.length >= 2) {
            var firstYear = parseInt(String(series[0].date || '').slice(0, 4), 10);
            var lastYear = parseInt(String(series[series.length - 1].date || '').slice(0, 4), 10);
            if (!isNaN(firstYear) && !isNaN(lastYear) && firstYear !== lastYear) includeYear = true;
        }

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, width, height);

        var gridValues = [0, 25, 50, 75, 100];
        ctx.strokeStyle = '#eef2ff';
        ctx.lineWidth = 1;
        ctx.font = '12px Inter, Arial, sans-serif';
        ctx.fillStyle = '#64748b';
        ctx.textAlign = 'right';
        ctx.textBaseline = 'middle';
        gridValues.forEach(function(val){
            var ratio = val / 100;
            var y = padding.t + (1 - ratio) * plotH;
            ctx.beginPath();
            ctx.moveTo(padding.l, y);
            ctx.lineTo(padding.l + plotW, y);
            ctx.stroke();
            ctx.fillText(val + '%', padding.l - 10, y);
        });

        ctx.strokeStyle = '#cbd5f5';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(padding.l, padding.t);
        ctx.lineTo(padding.l, padding.t + plotH);
        ctx.lineTo(padding.l + plotW, padding.t + plotH);
        ctx.stroke();

        var stepX = series.length > 1 ? plotW / (series.length - 1) : 0;
        var points = series.map(function(item, idx){
            var x = padding.l + (series.length > 1 ? idx * stepX : plotW / 2);
            var value = values[idx];
            var y = padding.t + (1 - (value / 100)) * plotH;
            return { x: x, y: y, label: item && item.date ? item.date : '', value: value };
        });

        ctx.strokeStyle = '#2563eb';
        ctx.lineWidth = 2;
        // draw line path
        ctx.beginPath();
        points.forEach(function(pt, idx){
            if (idx === 0) ctx.moveTo(pt.x, pt.y);
            else ctx.lineTo(pt.x, pt.y);
        });
        ctx.stroke();

        // soft area fill under line for modern look
        if (points.length >= 2) {
            var grad = ctx.createLinearGradient(0, padding.t, 0, padding.t + plotH);
            grad.addColorStop(0, 'rgba(37,99,235,0.22)');
            grad.addColorStop(1, 'rgba(37,99,235,0.03)');
            ctx.fillStyle = grad;
            ctx.beginPath();
            points.forEach(function(pt, idx){
                if (idx === 0) ctx.moveTo(pt.x, pt.y);
                else ctx.lineTo(pt.x, pt.y);
            });
            var last = points[points.length - 1];
            var first = points[0];
            ctx.lineTo(last.x, padding.t + plotH);
            ctx.lineTo(first.x, padding.t + plotH);
            ctx.closePath();
            ctx.fill();
            ctx.strokeStyle = '#2563eb';
            ctx.lineWidth = 2;
            ctx.beginPath();
            points.forEach(function(pt, idx){
                if (idx === 0) ctx.moveTo(pt.x, pt.y);
                else ctx.lineTo(pt.x, pt.y);
            });
            ctx.stroke();
        }

        ctx.fillStyle = '#2563eb';
        points.forEach(function(pt){
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, 4, 0, Math.PI * 2);
            ctx.fill();
        });

        ctx.fillStyle = '#334155';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        var labelIndexes = [];
        if (points.length) labelIndexes.push(0);
        if (points.length > 2) labelIndexes.push(Math.round((points.length - 1) / 2));
        if (points.length > 1) labelIndexes.push(points.length - 1);
        var used = Object.create(null);
        labelIndexes.forEach(function(idx){
            if (idx == null || used[idx]) return;
            used[idx] = true;
            var point = points[idx];
            var label = typeof formatAxisTickLabel === 'function' ? formatAxisTickLabel(point.label, includeYear && (idx === 0 || idx === points.length - 1)) : point.label;
            ctx.fillText(label, point.x, padding.t + plotH + 12);
        });

        if (points.length) {
            var lastPoint = points[points.length - 1];
            var lastValue = values[values.length - 1];
            ctx.textAlign = 'left';
            ctx.textBaseline = 'bottom';
            ctx.fillStyle = '#0f172a';
            ctx.fillText(formatNumberLocal(lastValue, 1) + '%', lastPoint.x + 10, lastPoint.y - 8);
        }

        historyLog('Chart rendered', { points: points.length, width: width, height: height });
    }

    /* Mini SVG chart */
    function renderMiniChart(elId, labels, values, predictedVal) {
        var container = document.getElementById(elId);
        if (!container) return;
        container.innerHTML = '';
        if (!values || values.length === 0) {
            container.innerHTML = '<div class="muted" style="padding:18px;text-align:center">No data</div>';
            return;
        }

        var rect = container.getBoundingClientRect();
        var w = Math.max(320, Math.floor(rect.width)) || 600;
        var h = Math.max(180, Math.floor(rect.height)) || 220;
        var padding = { l: 48, r: 24, t: 24, b: 48 };
        var plotW = Math.max(10, w - padding.l - padding.r);
        var plotH = Math.max(10, h - padding.t - padding.b);

        var pts = values.map(function(v){ return clamp(parseFloat(v) || 0, 0, 100); });
        var maxV = 100;

        var hasPred = typeof predictedVal === 'number';
        var labelCount = (labels && labels.length) ? labels.length : 0;
        var totalSlots = Math.max(labelCount, pts.length + (hasPred ? 1 : 0), 2);
        var stepX = plotW / Math.max(1, totalSlots - 1);

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

        var axisStroke = '#cbd5f5';
        var axisLabelColor = '#475569';

        for (var g = 0; g <= 4; g++) {
            var ratio = g / 4;
            var yGrid = padding.t + ratio * plotH;

            var gridLine = document.createElementNS(svgNS, 'line');
            gridLine.setAttribute('x1', padding.l);
            gridLine.setAttribute('x2', padding.l + plotW);
            gridLine.setAttribute('y1', yGrid);
            gridLine.setAttribute('y2', yGrid);
            gridLine.setAttribute('stroke', '#eef2ff');
            gridLine.setAttribute('stroke-width', '1');
            svg.appendChild(gridLine);

            var tick = document.createElementNS(svgNS, 'line');
            tick.setAttribute('x1', padding.l - 6);
            tick.setAttribute('x2', padding.l);
            tick.setAttribute('y1', yGrid);
            tick.setAttribute('y2', yGrid);
            tick.setAttribute('stroke', axisStroke);
            tick.setAttribute('stroke-width', '1');
            svg.appendChild(tick);

            var tickVal = maxV - ratio * maxV;
            var tickText = document.createElementNS(svgNS, 'text');
            tickText.setAttribute('x', padding.l - 10);
            tickText.setAttribute('y', yGrid + 4);
            tickText.setAttribute('text-anchor', 'end');
            tickText.setAttribute('font-size', '11');
            tickText.setAttribute('fill', axisLabelColor);
            tickText.setAttribute('font-weight', '600');
            var formattedTick = (typeof formatNumberLocal === 'function') ? formatNumberLocal(tickVal, 0) : Math.round(tickVal);
            tickText.textContent = formattedTick + '%';
            svg.appendChild(tickText);
        }

        var yAxis = document.createElementNS(svgNS, 'line');
        yAxis.setAttribute('x1', padding.l);
        yAxis.setAttribute('x2', padding.l);
        yAxis.setAttribute('y1', padding.t);
        yAxis.setAttribute('y2', padding.t + plotH);
        yAxis.setAttribute('stroke', axisStroke);
        yAxis.setAttribute('stroke-width', '1');
        svg.appendChild(yAxis);

        var xAxis = document.createElementNS(svgNS, 'line');
        xAxis.setAttribute('x1', padding.l);
        xAxis.setAttribute('x2', padding.l + plotW);
        xAxis.setAttribute('y1', padding.t + plotH);
        xAxis.setAttribute('y2', padding.t + plotH);
        xAxis.setAttribute('stroke', axisStroke);
        xAxis.setAttribute('stroke-width', '1');
        svg.appendChild(xAxis);

        var includeYearForAxis = false;
        if (labels && labels.length >= 2) {
            var firstYear = parseInt(String(labels[0]).slice(0, 4), 10);
            var lastYear = parseInt(String(labels[labels.length - 1]).slice(0, 4), 10);
            if (!isNaN(firstYear) && !isNaN(lastYear) && firstYear !== lastYear) {
                includeYearForAxis = true;
            }
        }

        if (labels && labels.length) {
            var xLabelIndices = [0];
            if (labels.length > 2) xLabelIndices.push(Math.floor(labels.length / 2));
            if (labels.length > 1) xLabelIndices.push(labels.length - 1);
            var usedIdx = Object.create(null);
            xLabelIndices.forEach(function(idx){
                if (idx == null || usedIdx[idx]) return;
                usedIdx[idx] = true;
                var rawLabel = labels[idx];
                var axisLabel;
                if (typeof formatAxisTickLabel === 'function') {
                    axisLabel = formatAxisTickLabel(rawLabel, includeYearForAxis && (idx === 0 || idx === labels.length - 1));
                } else {
                    axisLabel = String(rawLabel);
                }
                if (!axisLabel) return;
                var textEl = document.createElementNS(svgNS, 'text');
                textEl.setAttribute('x', padding.l + idx * stepX);
                textEl.setAttribute('y', padding.t + plotH + 20);
                textEl.setAttribute('text-anchor', 'middle');
                textEl.setAttribute('font-size', '11');
                textEl.setAttribute('fill', axisLabelColor);
                textEl.textContent = axisLabel;
                svg.appendChild(textEl);
            });
        }

        if (poly.length) {
            var pathD = poly.map(function(p, i){ return (i === 0 ? 'M' : 'L') + p.x + ' ' + p.y; }).join(' ');
            var path = document.createElementNS(svgNS, 'path');
            path.setAttribute('d', pathD);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', '#2563eb');
            path.setAttribute('stroke-width', '2');
            svg.appendChild(path);
        }

        poly.forEach(function(p){
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

        if (hasPred) {
            var predIndex = pts.length;
            var predictedValue = clamp(predictedVal, 0, 100);
            var xPred = padding.l + predIndex * stepX;
            var yPred = padding.t + (1 - (predictedValue / maxV)) * plotH;
            var last = poly.length ? poly[poly.length - 1] : { x: padding.l, y: padding.t + plotH };

            var d = 'M' + last.x + ' ' + last.y + ' L ' + xPred + ' ' + yPred;
            var pPred = document.createElementNS(svgNS, 'path');
            pPred.setAttribute('d', d);
            pPred.setAttribute('fill', 'none');
            pPred.setAttribute('stroke', '#00c853');
            pPred.setAttribute('stroke-width', '1.5');
            pPred.setAttribute('stroke-dasharray', '6 6');
            svg.appendChild(pPred);

            var cp = document.createElementNS(svgNS, 'circle');
            cp.setAttribute('cx', xPred);
            cp.setAttribute('cy', yPred);
            cp.setAttribute('r', 3.5);
            cp.setAttribute('fill', '#00c853');
            svg.appendChild(cp);

            var predLabel = (labels && labels[predIndex]) ? labels[predIndex] : 'Predicted';
            var predAxisLabel;
            if (typeof formatAxisTickLabel === 'function') {
                predAxisLabel = formatAxisTickLabel(predLabel, includeYearForAxis && (predIndex === 0 || (labels && predIndex === labels.length - 1)));
            } else {
                predAxisLabel = String(predLabel);
            }
            var predValueText = (typeof formatNumberLocal === 'function') ? formatNumberLocal(predictedValue, 1) + '%' : predictedValue.toFixed(1) + '%';

            var t = document.createElementNS(svgNS, 'text');
            t.setAttribute('x', xPred);
            t.setAttribute('y', yPred - 10);
            t.setAttribute('text-anchor', 'middle');
            t.setAttribute('font-size', '11');
            t.setAttribute('fill', '#065f46');
            t.textContent = predValueText;
            svg.appendChild(t);

            cp.setAttribute('tabindex', '0');
            cp.setAttribute('aria-label', (predAxisLabel ? predAxisLabel + ': ' : 'Predicted: ') + predValueText);
            cp.addEventListener('mouseenter', function(){ showTooltip(container, xPred, yPred, predAxisLabel || 'Predicted', predictedValue); });
            cp.addEventListener('focus', function(){ showTooltip(container, xPred, yPred, predAxisLabel || 'Predicted', predictedValue); });
            cp.addEventListener('mouseleave', hideTooltip);
            cp.addEventListener('blur', hideTooltip);
        }

        container.appendChild(svg);
    }


    var _tt = null;
    function showTooltip(container, x, y, label, value){ hideTooltip(); _tt = document.createElement('div'); _tt.className = 'mini-tt'; _tt.style.position = 'absolute'; _tt.style.left = (x + 8) + 'px'; _tt.style.top = (y - 18) + 'px'; _tt.style.padding = '6px 8px'; _tt.style.borderRadius = '6px'; _tt.style.background = '#ffffff'; _tt.style.boxShadow = '0 6px 18px rgba(2,6,23,0.06)'; _tt.style.fontSize = '12px'; _tt.textContent = (label ? label + ': ' : '') + value + '%'; container.appendChild(_tt); }
    function hideTooltip(){ try{ if(_tt && _tt.parentNode) _tt.parentNode.removeChild(_tt); _tt = null; }catch(e){} }

    /* ----------------------
       History modal helpers
       ---------------------- */
    var historyModalEl = null;
    var historyRangeButtons = [];
    var currentHistoryRange = 'all';
    var historyTriggerBtn = null;
    var customStartInput = null;
    var customEndInput = null;
    var applyCustomBtn = null;\r\n    var compareToggle = null;\r\n    var historyCompareEnabled = false;
    var dateFormatterFull = null;
    var dateFormatterAxis = null;
    try {
        dateFormatterFull = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    } catch (err) {
        dateFormatterFull = null;
    }
    try {
        dateFormatterAxis = new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' });
    } catch (err) {
        dateFormatterAxis = null;
    }

    function formatNumberLocal(value, decimals) {
        if (typeof decimals !== 'number') decimals = 0;
        var n = parseFloat(value);
        if (!isFinite(n)) n = 0;
        var factor = Math.pow(10, decimals);
        n = Math.round(n * factor) / factor;
        if (Object.is(n, -0)) n = 0;
        return decimals > 0 ? n.toFixed(decimals) : String(Math.round(n));
    }

    function formatDeltaValue(value, suffix, decimals) {
        suffix = suffix || '';
        if (typeof decimals !== 'number') decimals = suffix === '%' ? 1 : 0;
        var n = parseFloat(value);
        if (!isFinite(n)) n = 0;
        var threshold = decimals > 0 ? 0.5 / Math.pow(10, decimals) : 0.5;
        if (Math.abs(n) < threshold) return '0' + suffix;
        var sign = '';
        if (n > 0) sign = '+'; else if (n < 0) sign = '-';
        var absVal = Math.abs(n);
        var body = decimals > 0 ? absVal.toFixed(decimals) : String(Math.round(absVal));
        return sign + body + suffix;
    }

    function formatDateLabel(value) {
        if (!value) return '';
        if (dateFormatterFull) {
            var parts = String(value).split('-');
            if (parts.length === 3) {
                var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                if (!isNaN(dt.getTime())) {
                    return dateFormatterFull.format(dt);
                }
            }
        }
        return String(value);
    }

    function formatAxisTickLabel(value, includeYear) {
        if (!value) return '';
        var raw = value;
        if (typeof raw === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            try {
                var dt = new Date(raw + 'T00:00:00');
                if (!isNaN(dt.getTime())) {
                    if (dateFormatterAxis) {
                        var formatted = dateFormatterAxis.format(dt);
                        if (includeYear) formatted += ' ' + dt.getFullYear();
                        return formatted;
                    }
                    return dt.toLocaleDateString();
                }
            } catch (err) {
                return raw;
            }
        }
        return String(raw);
    }

    function formatRangeLabel(start, end) {
        start = start || historyStart || '';
        end = end || historyEnd || start;
        var a = formatDateLabel(start);
        var b = formatDateLabel(end);
        if (!b || a === b) return a || b || '';
        return a + ' to ' + b;
    }

    function filterHistory(range, customStart, customEnd) {
        if (!Array.isArray(historySeries) || historySeries.length === 0) return [];
        if (!range || range === 'all') return historySeries.slice();
        if (range === 'custom' && customStart && customEnd) {
            var startDate = new Date(customStart + 'T00:00:00');
            var endDate = new Date(customEnd + 'T23:59:59');
            if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || startDate > endDate) {
                historyLogError('Invalid custom range dates', { start: customStart, end: customEnd });
                return historySeries.slice();
            }
            return historySeries.filter(function(day){
                var dt = new Date(day.date + 'T00:00:00');
                if (isNaN(dt.getTime())) return false;
                return dt >= startDate && dt <= endDate;
            });
        }
        var limit = parseInt(range, 10);
        if (!limit || limit <= 0) return historySeries.slice();
        return historySeries.slice(Math.max(0, historySeries.length - limit));
    }

    function summarizeHistory(series) {
        var total = 0;
        var completed = 0;
        var activeDays = 0;
        var start = historyStart || '';
        var end = historyEnd || start;
        if (Array.isArray(series) && series.length) {
            start = series[0].date || start;
            end = series[series.length - 1].date || end;
            series.forEach(function(day){
                var t = parseInt(day.total, 10) || 0;
                var c = parseInt(day.completed, 10) || 0;
                total += t;
                completed += c;
                if (t > 0) activeDays++;
            });
        } else {
            total = parseInt(historyTotals.tracked, 10) || 0;
            completed = parseInt(historyTotals.completed, 10) || 0;
            activeDays = parseInt(historyActiveDays, 10) || 0;
            start = historyStart || start;
            end = historyEnd || end;
        }
        var efficiency = total > 0 ? (completed / total) * 100 : (parseFloat(historyTotals.efficiency) || 0);
        return { start: start, end: end, total: total, completed: completed, activeDays: activeDays, efficiency: efficiency };
    }

    function sanitizeCustomRangeValues(startVal, endVal) {
        var start = startVal || '';
        var end = endVal || '';
        if (!start && !end) return null;
        if (!end) end = start;
        if (!start) start = end;
        if (start && end && start > end) {
            var tmp = start;
            start = end;
            end = tmp;
        }
        return { start: start, end: end };
    }

    function setCustomRangeDefaults() {
        if (!customStartInput || !customEndInput || !Array.isArray(historySeries) || !historySeries.length) return;
        var firstDate = historySeries[0].date || '';
        var lastDate = historySeries[historySeries.length - 1].date || '';
        if (firstDate) {
            customStartInput.min = firstDate;
            customEndInput.min = firstDate;
            if (!customStartInput.value) customStartInput.value = firstDate;
        }
        if (lastDate) {
            customStartInput.max = lastDate;
            customEndInput.max = lastDate;
            if (!customEndInput.value) customEndInput.value = lastDate;
        }
    }

    function handleCustomInputChange() {
        if (!customStartInput || !customEndInput) return;
        var values = sanitizeCustomRangeValues(customStartInput.value, customEndInput.value);
        if (!values) return;
        if (values.start) customStartInput.value = values.start;
        if (values.end) customEndInput.value = values.end;
    }

    function applyCustomRange() {
        if (!customStartInput) return;
        handleCustomInputChange();
        var startVal = customStartInput.value;
        var endVal = customEndInput ? (customEndInput.value || startVal) : startVal;
        var values = sanitizeCustomRangeValues(startVal, endVal);
        if (!values || !values.start) {
            console.warn('Select start date for custom range');
            return;
        }
        updateHistoryView('custom', { customStart: values.start, customEnd: values.end || values.start });
    }

    function updateHistoryHighlights(subset) {
        var bestCompleted = 0;
        var bestCompletedDate = '';
        var bestEfficiency = 0;
        var bestEfficiencyDate = '';
        var activeDays = 0;
        var sumCompleted = 0;

        if (Array.isArray(subset) && subset.length) {
            subset.forEach(function(day){
                var completed = parseInt(day.completed, 10) || 0;
                var efficiency = parseFloat(day.efficiency || 0) || 0;
                if (completed > bestCompleted) {
                    bestCompleted = completed;
                    bestCompletedDate = day.date || '';
                }
                if (efficiency > bestEfficiency) {
                    bestEfficiency = efficiency;
                    bestEfficiencyDate = day.date || '';
                }
                if ((parseInt(day.total, 10) || 0) > 0 || completed > 0) {
                    sumCompleted += completed;
                    activeDays++;
                }
            });
        }

        var avgCompleted = activeDays > 0 ? (sumCompleted / activeDays) : 0;

        writeText('highlight-best-completed-value', formatNumberLocal(bestCompleted, 0));
        writeText('highlight-best-completed-date', bestCompletedDate ? formatDateLabel(bestCompletedDate) : '—');
        writeText('highlight-best-eff-value', formatNumberLocal(bestEfficiency, 1) + '%');
        writeText('highlight-best-eff-date', bestEfficiencyDate ? formatDateLabel(bestEfficiencyDate) : '—');
        writeText('highlight-avg-completed-value', formatNumberLocal(avgCompleted, 1));
    }
    function writeText(id, text) {
        var el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return;
        el.textContent = text;
    }

    function updateDelta(id, value, suffix, decimals) {
        var el = document.getElementById(id);
        if (!el) return;
        var cls = 'neutral';
        if (value > 0) cls = 'positive'; else if (value < 0) cls = 'negative';
        el.classList.remove('positive', 'negative', 'neutral');
        el.classList.add(cls);
        el.textContent = formatDeltaValue(value, suffix, decimals);
    }

    function updateHistoryButtons(range) {
        historyRangeButtons.forEach(function(btn){
            var r = btn.getAttribute('data-range') || 'all';
            btn.classList.toggle('active', r === range);
        });
    }

    function updateHistorySummary(summary) {
        writeText('historyRangeLabel', formatRangeLabel(summary.start, summary.end));
        writeText('historyActiveDays', summary.activeDays);
        writeText('historyCompletionCount', summary.completed + ' / ' + summary.total);
        writeText('historyEfficiencyValue', formatNumberLocal(summary.efficiency, 1) + '%');
    }

    function updateHistoryView(range, opts) {
        if (!range) range = 'all';
        opts = opts || {};
        currentHistoryRange = range;
        updateHistoryButtons(range);
        var subset = filterHistory(range, opts.customStart, opts.customEnd);
        historyLog('Apply range', { range: range, points: Array.isArray(subset) ? subset.length : 'n/a' });
        if (subset.length && range === 'custom') {
            historyStart = subset[0].date;
            historyEnd = subset[subset.length - 1].date;
        }
        var summary = summarizeHistory(subset);
        updateHistorySummary(summary);
        updateHistoryHighlights(subset);
        // dynamic comparison cards based on selected range and toggle
        try {
            if (historyCompareEnabled) {
                if (range === '7') { var cm7 = computeComparisonForRange(7) || weeklyComparison; applyComparisonCard('weekly', cm7); } else { applyComparisonCard('weekly', weeklyComparison); }
                if (range === '30') { var cm30 = computeComparisonForRange(30) || monthlyComparison; applyComparisonCard('monthly', cm30); } else { applyComparisonCard('monthly', monthlyComparison); }
            } else {
                applyComparisonCard('weekly', weeklyComparison);
                applyComparisonCard('monthly', monthlyComparison);
            }
        } catch(e) { console.warn('comparison update error', e); }
        var container = document.getElementById('historyChart');
        if (!container) return;
        if (!subset.length) {
            historyLog('No data for selected range', { range: range });
            container.innerHTML = '<div class="empty-state">No activity tracked yet</div>';
            return;
        }
        try {
            renderHistoryMvpChart(container, subset);
        } catch (err) {
            historyLogError('Failed to render history chart', err);
            container.innerHTML = '<div class="empty-state">Unable to render chart</div>';
        }
    }

    function applyComparisonCard(prefix, comp) {
        comp = comp || {};
        var current = comp.current || {};
        var previous = comp.previous || {};
        var delta = comp.delta || {};
        var currentEffEl = document.getElementById(prefix + '-current-eff');
        if (currentEffEl) {
            writeText(currentEffEl, formatNumberLocal(current.efficiency, 1) + '%');
            var currentLabel = currentEffEl.previousElementSibling;
            if (currentLabel && current.title) currentLabel.textContent = current.title;
        }
        var prevEffEl = document.getElementById(prefix + '-previous-eff');
        if (prevEffEl) {
            writeText(prevEffEl, formatNumberLocal(previous.efficiency, 1) + '%');
            var prevLabel = prevEffEl.previousElementSibling;
            if (prevLabel && previous.title) prevLabel.textContent = previous.title;
        }
        writeText(prefix + '-current-completed', (current.completed != null ? current.completed : 0));
        updateDelta(prefix + '-eff-delta', delta.efficiency != null ? delta.efficiency : 0, '%', 1);
        updateDelta(prefix + '-completed-delta', delta.completed != null ? delta.completed : 0, '', 0);
    }

    function showHistoryModal(range) {
        if (!historyModalEl) return;
        historyModalEl.style.display = 'flex';
        historyModalEl.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function(){
            updateHistoryView(range || currentHistoryRange || 'all');
            var closeBtn = document.getElementById('historyClose');
            if (closeBtn) { try { closeBtn.focus({ preventScroll: true }); } catch (e) { closeBtn.focus(); } }
        });
    }

    function hideHistoryModal() {
        if (!historyModalEl) return;
        historyModalEl.style.display = 'none';
        historyModalEl.setAttribute('aria-hidden', 'true');
        if (historyTriggerBtn) {
            try { historyTriggerBtn.focus({ preventScroll: true }); } catch (err) { historyTriggerBtn.focus(); }
        }
    }

    function initHistoryModal() {
        historyModalEl = document.getElementById('historyModal');
        var toggle = document.getElementById('historyToggle');
        var close = document.getElementById('historyClose');
        if (!historyModalEl || !toggle) return;
        historyTriggerBtn = toggle;
        historyRangeButtons = Array.from(historyModalEl.querySelectorAll('.range-btn')) || [];
        toggle.addEventListener('click', function(){ historyTriggerBtn = toggle; showHistoryModal('all'); });
        if (close) close.addEventListener('click', hideHistoryModal);
        historyModalEl.addEventListener('click', function(evt){ if (evt.target === historyModalEl) hideHistoryModal(); });
        document.addEventListener('keydown', function(evt){ if (evt.key === 'Escape' && historyModalEl && historyModalEl.getAttribute('aria-hidden') === 'false') hideHistoryModal(); });
        historyRangeButtons.forEach(function(btn){ btn.addEventListener('click', function(){ var range = btn.getAttribute('data-range') || 'all'; updateHistoryView(range); }); });
        updateHistoryButtons(currentHistoryRange);
        var initialSummary = summarizeHistory(historySeries);
        updateHistorySummary(initialSummary);
        applyComparisonCard('weekly', weeklyComparison);
        applyComparisonCard('monthly', monthlyComparison);
        if (!historySeries || !historySeries.length) {
            var container = document.getElementById('historyChart');
            if (container) container.innerHTML = '<div class="empty-state">No activity tracked yet</div>';
        }
    }

    function initHistoryPage(rootId) {
        var root = document.getElementById(rootId || 'historyPage');
        if (!root) return;
        historyModalEl = root;
        historyRangeButtons = Array.from(root.querySelectorAll('.range-btn')) || [];
        customStartInput = document.getElementById('historyCustomStart');
        customEndInput = document.getElementById('historyCustomEnd');
        applyCustomBtn = document.getElementById('historyApplyCustom');
        compareToggle = document.getElementById('historyCompareToggle');
        try { historyCompareEnabled = !!(localStorage && localStorage.getItem('historyCompareEnabled') === '1'); } catch(e) { historyCompareEnabled = false; }
        if (compareToggle) {
            compareToggle.checked = historyCompareEnabled;
            compareToggle.addEventListener('change', function(){
                historyCompareEnabled = !!compareToggle.checked;
                try { localStorage.setItem('historyCompareEnabled', historyCompareEnabled ? '1' : '0'); } catch(e){}
                updateHistoryView(currentHistoryRange);
            });
        }
        setCustomRangeDefaults();
        handleCustomInputChange();
        if (customStartInput) {
            customStartInput.addEventListener('change', handleCustomInputChange);
            customStartInput.addEventListener('blur', handleCustomInputChange);
            customStartInput.addEventListener('keypress', function(evt){ if (evt.key === 'Enter') { evt.preventDefault(); applyCustomRange(); } });
        }
        if (customEndInput) {
            customEndInput.addEventListener('change', handleCustomInputChange);
            customEndInput.addEventListener('blur', handleCustomInputChange);
            customEndInput.addEventListener('keypress', function(evt){ if (evt.key === 'Enter') { evt.preventDefault(); applyCustomRange(); } });
        }
        if (applyCustomBtn) {
            applyCustomBtn.addEventListener('click', function(){ applyCustomRange(); });
        }
        var initialBtn = historyRangeButtons.find(function(btn){ return btn.classList.contains('active'); });
        currentHistoryRange = initialBtn ? (initialBtn.getAttribute('data-range') || 'all') : 'all';
        updateHistoryButtons(currentHistoryRange);
        applyComparisonCard('weekly', weeklyComparison);
        applyComparisonCard('monthly', monthlyComparison);
        historyRangeButtons.forEach(function(btn){
            btn.addEventListener('click', function(){
                var range = btn.getAttribute('data-range') || 'all';
                if (range === 'custom') {
                    updateHistoryButtons('custom');
                    if (customStartInput) {
                        try { customStartInput.focus({ preventScroll: true }); } catch (err) { customStartInput.focus(); }
                    }
                    return;
                }
                updateHistoryView(range);
            });
        });
        updateHistoryView(currentHistoryRange);
    }
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
        if (document.getElementById('historyModal')) {
            try { initHistoryModal(); } catch(e){ console.warn('initHistoryModal error', e); }
        }
        if (document.getElementById('historyPage')) {
            try { initHistoryPage(); } catch(e){ console.warn('initHistoryPage error', e); }
        }
        try { if (flashSuccess) showFlash(flashSuccess, 'success'); if (flashError) showFlash(flashError, 'error'); } catch(e){}
        try { renderMiniChart('miniChart', chart_labels, chart_values, predicted); } catch(e){}
    }

    // Export public API
    window.initDashboard = initDashboard;
    window.dashboardHelpers = { saveOrderToServer: saveOrderToServer, toggleDesc: toggleDesc, showFlash: showFlash, renderMiniChart: renderMiniChart, buildWheel: buildWheel, spinWheel: spinWheel, openHistoryModal: showHistoryModal, updateHistoryView: updateHistoryView, initHistoryPage: initHistoryPage };

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


    function computeComparisonForRange(range){
        var n = parseInt(range, 10);
        if (!Array.isArray(historySeries) || !historySeries.length || !n || n <= 0) return null;
        var curr = filterHistory(String(n));
        if (!curr.length) return null;
        var firstDate = curr[0].date || '';
        var i0 = -1;
        for (var i = 0; i < historySeries.length; i++){ if ((historySeries[i] && historySeries[i].date) === firstDate) { i0 = i; break; } }
        if (i0 < 0) i0 = Math.max(0, historySeries.length - curr.length);
        var startPrev = Math.max(0, i0 - curr.length);
        var prev = historySeries.slice(startPrev, i0);
        var sCurr = summarizeHistory(curr);
        var sPrev = summarizeHistory(prev);
        return {
            current: { title: (n===7?'Last 7 days': (n===30?'Last 30 days':'Selected')), efficiency: sCurr.efficiency||0, completed: sCurr.completed||0 },
            previous: { title: (n===7?'Prev 7 days': (n===30?'Prev 30 days':'Previous')), efficiency: sPrev.efficiency||0, completed: sPrev.completed||0 },
            delta: { efficiency: (sCurr.efficiency||0) - (sPrev.efficiency||0), completed: (sCurr.completed||0) - (sPrev.completed||0) }
        };
    }})();








