// Mi alcancia 360 - Estadísticas personales del asesor
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var periodo = 'mes';
    var breakTab = 'categoria';
    var trendRows = [];

    var els = {
        loading: document.getElementById('st-loading'),
        body: document.getElementById('st-body'),
        error: document.getElementById('st-error'),
        period: document.getElementById('st-period'),
        breakTabs: document.getElementById('st-break-tabs'),
        trend: document.getElementById('st-trend'),
        trendDetail: document.getElementById('st-trend-detail'),
        breakdown: document.getElementById('st-breakdown')
    };

    function money(n) {
        return 'S/ ' + Number(n || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function showLoading(on) {
        if (els.loading) els.loading.hidden = !on;
        if (els.body) els.body.hidden = on;
    }

    function showError(msg) {
        if (els.error) { els.error.textContent = msg; els.error.hidden = false; }
        if (els.loading) els.loading.hidden = true;
    }

    function load(p) {
        if (p) periodo = p;
        showLoading(true);
        if (els.error) els.error.hidden = true;

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_mi_stats');
        body.append('nonce', window.billetera.nonce_mi_stats);
        body.append('periodo', periodo);

        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudieron cargar tus estadísticas.');
                    return;
                }
                data = res.data;
                render();
                showLoading(false);
            })
            .catch(function () {
                showError('No se pudieron cargar tus estadísticas.');
            });
    }

    function render() {
        if (!data) return;
        renderResumen(data.resumen || {});
        renderMeta(data.resumen || {});
        renderTrend(data.tendencia || []);
        renderBreakdown();
        renderRanking(data.ranking || {}, data.racha || 0);
    }

    function renderResumen(r) {
        setText('st-mes', money(r.mes_actual));
        setText('st-ventas', r.ventas_mes || 0);
        setText('st-unidades', (r.unidades_mes || 0) + ' unidades');
        setText('st-ticket', money(r.ticket_promedio));
        setText('st-ano', money(r.acumulado_ano));
        setText('st-historico', 'Histórico ' + money(r.historico));

        var varEl = document.getElementById('st-var-mes');
        if (varEl) {
            if (r.var_mes === null || typeof r.var_mes === 'undefined') {
                varEl.textContent = 'Sin datos del mes anterior';
                varEl.className = 'st-kpi__hint';
            } else {
                var up = r.var_mes >= 0;
                varEl.textContent = (up ? '▲ ' : '▼ ') + Math.abs(r.var_mes).toFixed(1) + '% vs mes anterior';
                varEl.className = 'st-kpi__hint ' + (up ? 'is-up' : 'is-down');
            }
        }
    }

    function renderMeta(r) {
        var fill = Math.max(0, Math.min(Number(r.fill_percent) || 0, 100));
        var bar = document.getElementById('st-meta-fill');
        if (bar) bar.style.width = fill + '%';
        setText('st-meta-pct', (r.fill_real || 0) + '%');
        setText('st-faltan', money(r.faltan));
        setText('st-meta', money(r.meta));
        setText('st-proyeccion', money(r.proyeccion));
    }

    function renderTrend(rows) {
        if (!els.trend) return;
        els.trend.innerHTML = '';
        trendRows = rows;

        if (!rows.length) {
            els.trend.appendChild(emptyNote('Aún no tienes ventas en este periodo.'));
            if (els.trendDetail) els.trendDetail.textContent = '';
            return;
        }

        var max = 0;
        rows.forEach(function (r) { max = Math.max(max, Number(r.total) || 0); });

        rows.forEach(function (r, idx) {
            var col = document.createElement('div');
            col.className = 'st-chart__col';
            col.setAttribute('role', 'button');
            col.setAttribute('tabindex', '0');

            var pct = max > 0 ? Math.max((Number(r.total) || 0) / max * 100, 2) : 2;

            var barWrap = document.createElement('div');
            barWrap.className = 'st-chart__bar-wrap';
            var bar = document.createElement('div');
            bar.className = 'st-chart__bar';
            bar.style.height = pct + '%';
            barWrap.appendChild(bar);

            var val = document.createElement('div');
            val.className = 'st-chart__val';
            val.textContent = shortMoney(r.total);

            var label = document.createElement('div');
            label.className = 'st-chart__label';
            label.textContent = r.label;

            col.appendChild(val);
            col.appendChild(barWrap);
            col.appendChild(label);

            col.addEventListener('click', function () { selectTrend(idx); });
            col.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectTrend(idx);
                }
            });

            els.trend.appendChild(col);
        });

        // Por defecto, el mes más reciente
        selectTrend(rows.length - 1);
    }

    function selectTrend(idx) {
        if (!trendRows.length || idx < 0) return;

        var cols = els.trend.querySelectorAll('.st-chart__col');
        cols.forEach(function (c, i) { c.classList.toggle('is-active', i === idx); });

        var r = trendRows[idx];
        if (els.trendDetail) {
            els.trendDetail.textContent = r.label + ' · ' + money(r.total) + ' · ' +
                r.ventas + (r.ventas === 1 ? ' venta' : ' ventas');
        }
    }

    function shortMoney(n) {
        n = Number(n) || 0;
        if (n >= 1000) return 'S/ ' + (n / 1000).toFixed(1) + 'k';
        return 'S/ ' + n.toFixed(0);
    }

    function renderBreakdown() {
        if (!els.breakdown || !data) return;
        els.breakdown.innerHTML = '';

        var key = 'por_' + breakTab;
        var rows = data[key] || [];

        if (!rows.length) {
            els.breakdown.appendChild(emptyNote('Sin ventas en este periodo.'));
            return;
        }

        var max = 0;
        rows.forEach(function (r) { max = Math.max(max, Number(r.total) || 0); });

        rows.forEach(function (r) {
            var row = document.createElement('div');
            row.className = 'st-bd__row';

            var head = document.createElement('div');
            head.className = 'st-bd__head';
            var label = document.createElement('span');
            label.className = 'st-bd__label';
            label.textContent = r.label;
            var amount = document.createElement('span');
            amount.className = 'st-bd__amount';
            amount.textContent = money(r.total);
            head.appendChild(label);
            head.appendChild(amount);

            var track = document.createElement('div');
            track.className = 'st-bd__track';
            var fillBar = document.createElement('div');
            fillBar.className = 'st-bd__fill';
            fillBar.style.width = (max > 0 ? Math.max((Number(r.total) || 0) / max * 100, 3) : 3) + '%';
            track.appendChild(fillBar);

            var meta = document.createElement('div');
            meta.className = 'st-bd__meta';
            meta.textContent = r.ventas + (r.ventas === 1 ? ' venta' : ' ventas');

            row.appendChild(head);
            row.appendChild(track);
            row.appendChild(meta);
            els.breakdown.appendChild(row);
        });
    }

    function renderRanking(rank, racha) {
        var t = rank.tienda || { rank: 0, total: 0 };
        var g = rank.global || { rank: 0, total: 0 };
        setText('st-rank-tienda', '#' + (t.rank || 0) + ' / ' + (t.total || 0));
        setText('st-rank-global', '#' + (g.rank || 0) + ' / ' + (g.total || 0));
        setText('st-racha', (racha || 0) + (racha === 1 ? ' día' : ' días'));
    }

    function emptyNote(msg) {
        var p = document.createElement('div');
        p.className = 'st-empty';
        p.textContent = msg;
        return p;
    }

    // Eventos
    if (els.period) {
        els.period.addEventListener('click', function (e) {
            var btn = e.target.closest('.st-period__btn');
            if (!btn) return;
            els.period.querySelectorAll('.st-period__btn').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            load(btn.dataset.periodo);
        });
    }

    if (els.breakTabs) {
        els.breakTabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.st-tab');
            if (!btn) return;
            els.breakTabs.querySelectorAll('.st-tab').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            breakTab = btn.dataset.break;
            renderBreakdown();
        });
    }

    load('mes');
})();
