// Mi alcancia 360 - Estadísticas personales del asesor
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var periodo = 'mes';
    var breakTab = 'categoria';
    var trendRows = [];
    var baseMes = null; // YYYY-MM
    var baseAno = null; // YYYY
    var mesesN = 12; // 6 | 12 para el gráfico
    var campanas = [];
    var bannerIdx = 0;
    var bannerTimer = null;

    var MESES_LARGOS = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    var els = {
        loading: document.getElementById('st-loading'),
        body: document.getElementById('st-body'),
        error: document.getElementById('st-error'),
        period: document.getElementById('st-period'),
        breakTabs: document.getElementById('st-break-tabs'),
        trend: document.getElementById('st-trend'),
        trendDetail: document.getElementById('st-trend-detail'),
        breakdown: document.getElementById('st-breakdown'),
        stepper: document.getElementById('st-stepper'),
        prev: document.getElementById('st-prev'),
        next: document.getElementById('st-next'),
        stepLabel: document.getElementById('st-step-label'),
        chartper: document.getElementById('st-chartper'),
        chartperBtn: document.getElementById('st-chartper-btn'),
        chartperMenu: document.getElementById('st-chartper-menu'),
        chartperLabel: document.getElementById('st-chartper-label')
    };

    function curMesKey() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1);
    }

    function curAno() { return String(new Date().getFullYear()); }

    function shiftMes(key, delta) {
        var m = /^(\d{4})-(\d{2})$/.exec(key || '');
        if (!m) return curMesKey();
        var y = parseInt(m[1], 10), mo = parseInt(m[2], 10) + delta;
        while (mo < 1) { mo += 12; y--; }
        while (mo > 12) { mo -= 12; y++; }
        return y + '-' + (mo < 10 ? '0' : '') + mo;
    }

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
        if (els.error) els.error.hidden = true;

        var screen = document.getElementById('screen-stats');
        var pending = document.getElementById('st-pending');
        if (!data) {
            // Primera carga (pantalla vacía): texto de carga.
            showLoading(true);
        } else {
            // Cambios (periodo/mes/año): conservar datos + indicador sutil.
            if (screen) screen.classList.add('st-is-loading');
            if (pending) pending.hidden = false;
        }

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_mi_stats');
        body.append('nonce', window.billetera.nonce_mi_stats);
        body.append('periodo', periodo);
        if (periodo === 'mes' && baseMes) body.append('mes', baseMes);
        if (periodo === 'ano' && baseAno) body.append('ano', baseAno);
        body.append('meses', String(mesesN));

        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var screen = document.getElementById('screen-stats');
                var pending = document.getElementById('st-pending');
                if (screen) screen.classList.remove('st-is-loading');
                if (pending) pending.hidden = true;
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudieron cargar tus estadísticas.');
                    return;
                }
                data = res.data;
                if (data.base_mes) baseMes = data.base_mes;
                if (data.base_ano) baseAno = data.base_ano;
                render();
                renderStepper();
                showLoading(false);
            })
            .catch(function () {
                var screen = document.getElementById('screen-stats');
                var pending = document.getElementById('st-pending');
                if (screen) screen.classList.remove('st-is-loading');
                if (pending) pending.hidden = true;
                showError('No se pudieron cargar tus estadísticas.');
            });
    }

    function render() {
        if (!data) return;
        renderBanner();
        renderResumen(data.resumen || {});
        renderMeta(data.resumen || {});
        renderTrend(data.tendencia || []);
        renderBreakdown();
        renderParaMeta();
    }

    // Banner de campaña (reglas de bonificación). Sin campañas vigentes se oculta.
    function renderBanner() {
        var box = document.getElementById('st-banner');
        if (!box) return;
        campanas = (data && data.campanas) || [];
        stopBannerTimer();
        if (!campanas.length) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        bannerIdx = 0;
        buildBannerDots();
        showBanner(0);
        if (campanas.length > 1) {
            bannerTimer = setInterval(function () {
                showBanner((bannerIdx + 1) % campanas.length);
            }, 7000);
        }
    }

    function stopBannerTimer() {
        if (bannerTimer) {
            clearInterval(bannerTimer);
            bannerTimer = null;
        }
    }

    function buildBannerDots() {
        var dots = document.getElementById('st-banner-dots');
        if (!dots) return;
        dots.innerHTML = '';
        if (campanas.length < 2) return;
        campanas.forEach(function (c, i) {
            var d = document.createElement('button');
            d.type = 'button';
            d.className = 'st-banner__dot' + (i === 0 ? ' is-active' : '');
            d.setAttribute('aria-label', 'Campaña ' + (i + 1));
            d.addEventListener('click', function () {
                stopBannerTimer();
                showBanner(i);
                bannerTimer = setInterval(function () {
                    showBanner((bannerIdx + 1) % campanas.length);
                }, 7000);
            });
            dots.appendChild(d);
        });
    }

    function showBanner(i) {
        var c = campanas[i];
        if (!c) return;
        bannerIdx = i;
        setText('st-banner-tag', c.etiqueta || 'CAMPAÑA · EVENTO');
        setText('st-banner-title', c.titulo || '');
        setText('st-banner-sub', c.bajada || '');
        setText('st-banner-dates', formatoVigencia(c.desde, c.hasta));
        var dots = document.getElementById('st-banner-dots');
        if (dots) {
            var items = dots.querySelectorAll('.st-banner__dot');
            items.forEach(function (d, j) { d.classList.toggle('is-active', j === i); });
        }
    }

    function formatoVigencia(desde, hasta) {
        function parts(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '');
            if (!m) return null;
            return { d: parseInt(m[3], 10), m: parseInt(m[2], 10) };
        }
        var a = parts(desde), b = parts(hasta);
        if (a && b) {
            if (a.m === b.m) return 'Del ' + a.d + ' al ' + b.d + ' de ' + MESES_LARGOS[b.m - 1];
            return 'Del ' + a.d + ' de ' + MESES_LARGOS[a.m - 1] + ' al ' + b.d + ' de ' + MESES_LARGOS[b.m - 1];
        }
        if (b) return 'Hasta el ' + b.d + ' de ' + MESES_LARGOS[b.m - 1];
        if (a) return 'Desde el ' + a.d + ' de ' + MESES_LARGOS[a.m - 1];
        return '';
    }

    function renderResumen(r) {
        setText('k5-comision', money(r.mes_actual));
        setText('k5-avance', (r.fill_real || 0) + '%');
        setText('k5-faltan', money(r.faltan));
        var rest = Math.max(0, (Number(r.dias_laborables) || 0) - (Number(r.dias_laborables_transcurridos) || 0));
        setText('k5-cierre', rest + (rest === 1 ? ' día' : ' días'));
        setText('k5-proy', money(r.proyeccion));
    }

    function renderMeta(r) {
        var fill = Math.max(0, Math.min(Number(r.fill_percent) || 0, 100));
        var fillEl = document.getElementById('st-goal-fill');
        if (fillEl) fillEl.style.width = fill + '%';
        var pig = document.getElementById('st-pig-fill');
        if (pig) pig.style.clipPath = 'inset(' + (100 - fill) + '% 0 0 0)';
        setText('st-goal-cap', (r.fill_real || 0) + '% de tu meta mensual');
        setText('st-pocito', money(r.mes_actual));
        setText('st-goal-meta', money(r.meta));

        var mark = document.getElementById('st-goal-mark');
        var total = Number(r.dias_laborables) || 0;
        var trans = Number(r.dias_laborables_transcurridos) || 0;
        if (mark) mark.style.left = (total > 0 ? Math.min(100, trans / total * 100) : 0) + '%';

        // Ritmo ideal hoy vs avance real.
        var pace = document.getElementById('st-goal-pace');
        if (pace) {
            var meta = Number(r.meta) || 0;
            var actual = Number(r.mes_actual) || 0;
            var ideal = (data && data.es_actual === false) ? meta : (total > 0 ? meta * trans / total : meta);
            var diff = actual - ideal;
            var dir = diff >= 0
                ? '<b class="up">vas ' + money(Math.abs(diff)) + ' arriba</b>'
                : '<b class="down">vas ' + money(Math.abs(diff)) + ' abajo</b>';
            pace.innerHTML = 'Ritmo ideal hoy ' + money(ideal) + ' · ' + dir;
        }
    }

    function renderStepper() {
        if (!els.stepper) return;
        if (periodo === 'historico') {
            els.stepper.classList.add('is-hidden');
            return;
        }
        els.stepper.classList.remove('is-hidden');
        if (els.stepLabel) els.stepLabel.textContent = (data && data.base_label) || '—';
        // › deshabilitado al llegar al periodo actual.
        if (els.next) {
            var enActual = !data || data.es_actual !== false;
            if (periodo === 'ano') {
                enActual = String(baseAno || curAno()) >= curAno();
            }
            els.next.disabled = !!enActual;
        }
    }

    function step(delta) {
        if (periodo === 'historico') return;
        if (periodo === 'ano') {
            var y = parseInt(baseAno || curAno(), 10) + delta;
            if (y > parseInt(curAno(), 10)) y = parseInt(curAno(), 10);
            baseAno = String(y);
        } else {
            var next = shiftMes(baseMes || curMesKey(), delta);
            if (next > curMesKey()) next = curMesKey();
            baseMes = next;
        }
        load(periodo);
    }

    var MESES_FULL = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];

    function moneyShort0(n) {
        return 'S/ ' + Number(n || 0).toLocaleString('es-PE', { maximumFractionDigits: 0 });
    }

    function renderTrend(rows) {
        if (!els.trend) return;
        els.trend.innerHTML = '';
        trendRows = rows || [];

        var meta = Number((data.resumen || {}).meta) || 0;
        setText('st-chart-meta', moneyShort0(meta));

        if (!trendRows.length) {
            els.trend.appendChild(emptyNote('Aún no tienes ventas en este periodo.'));
            var metal0 = document.getElementById('st-chart-metal');
            if (metal0) metal0.hidden = true;
            if (els.trendDetail) els.trendDetail.textContent = '';
            return;
        }

        var max = meta;
        trendRows.forEach(function (r) { max = Math.max(max, Number(r.total) || 0); });
        if (max <= 0) max = 1;

        var PH = 150; // alto del área de barras en px
        var baseMes = data.base_mes;

        trendRows.forEach(function (r, idx) {
            var tot = Number(r.total) || 0;
            var col = document.createElement('div');
            var isCur = baseMes && r.mes === baseMes;
            col.className = 'st-chart__col' + (isCur ? ' is-current' : '') + (tot > 0 ? ' has-value' : '');
            col.setAttribute('role', 'button');
            col.setAttribute('tabindex', '0');

            var barH = tot > 0 ? Math.max(tot / max * PH, 2) : 0;

            var val = document.createElement('div');
            val.className = 'st-chart__val';
            val.textContent = shortMoney(tot);
            val.style.bottom = (barH + 4) + 'px';

            var wrap = document.createElement('div');
            wrap.className = 'st-chart__bar-wrap';
            wrap.style.height = PH + 'px';
            var bar = document.createElement('div');
            bar.className = 'st-chart__bar';
            bar.style.height = barH + 'px';
            wrap.appendChild(bar);
            wrap.appendChild(val);

            var label = document.createElement('div');
            label.className = 'st-chart__label';
            label.textContent = r.label;

            col.appendChild(wrap);
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

        var metal = document.getElementById('st-chart-metal');
        if (metal) {
            if (meta > 0) {
                metal.hidden = false;
                metal.style.bottom = (17 + meta / max * PH) + 'px';
            } else {
                metal.hidden = true;
            }
        }

        // Por defecto: el mejor mes.
        var best = 0;
        trendRows.forEach(function (r, i) {
            if (Number(r.total) > Number(trendRows[best].total)) best = i;
        });
        selectTrend(best, true);
    }

    function selectTrend(idx, isBest) {
        if (!trendRows.length || idx < 0) return;

        var cols = els.trend.querySelectorAll('.st-chart__col');
        cols.forEach(function (c, i) { c.classList.toggle('is-active', i === idx); });

        var r = trendRows[idx];
        if (els.trendDetail) {
            var meta = Number((data.resumen || {}).meta) || 0;
            var pct = meta > 0 ? Math.round(Number(r.total) / meta * 100) : 0;
            var m = /^(\d{4})-(\d{2})$/.exec(r.mes || '');
            var nombre = m ? MESES_FULL[parseInt(m[2], 10) - 1] : r.label;
            if (isBest) {
                els.trendDetail.innerHTML = 'Mejor mes: ' + nombre + ' · <b>' + money(r.total) + ' (' + pct + '% de la meta)</b>';
            } else {
                els.trendDetail.textContent = r.label + ' · ' + money(r.total) + ' · ' +
                    r.ventas + (r.ventas === 1 ? ' venta' : ' ventas');
            }
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

        var total = 0;
        rows.forEach(function (r) { total += Number(r.total) || 0; });
        var titulo = '¿En qué ganas más?';
        if (periodo === 'historico') titulo += ' · Histórico';
        else if (data.base_ano) titulo += ' · ' + data.base_ano;
        setText('st-prod-title', titulo);
        setText('st-prod-total', money(total));

        if (!rows.length) {
            els.breakdown.appendChild(emptyNote('Sin ventas en este periodo.'));
            return;
        }

        rows.forEach(function (r, i) {
            var row = document.createElement('div');
            row.className = 'st-bd__row' + (i % 2 === 1 ? ' st-bd__row--alt' : '');

            var head = document.createElement('div');
            head.className = 'st-bd__head';
            var left = document.createElement('span');
            left.className = 'st-bd__label';
            left.textContent = r.label;
            var ventas = document.createElement('span');
            ventas.className = 'st-bd__ventas';
            ventas.textContent = r.ventas + (r.ventas === 1 ? ' venta' : ' ventas');
            left.appendChild(document.createTextNode(' '));
            left.appendChild(ventas);
            var amount = document.createElement('span');
            amount.className = 'st-bd__amount';
            amount.textContent = money(r.total);
            head.appendChild(left);
            head.appendChild(amount);

            row.appendChild(head);
            els.breakdown.appendChild(row);
        });
    }

    function catInitials(cat) {
        if (!cat) return '--';
        var stop = ['de', 'del', 'y', 'la', 'el', 'los', 'las'];
        var words = String(cat).split(/[\s\-]+/).filter(function (w) { return w && stop.indexOf(w.toLowerCase()) === -1; });
        if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
        return String(cat).slice(0, 2).toUpperCase();
    }

    function renderParaMeta() {
        var list = document.getElementById('st-need-list');
        var empty = document.getElementById('st-need-empty');
        if (!list) return;
        list.innerHTML = '';
        if (empty) empty.hidden = true;

        var r = (data && data.resumen) || {};
        var pm = (data && data.para_meta) || { alcanzada: false, filas: [] };
        var faltan = Number(r.faltan) || 0;
        var total = Number(r.dias_laborables) || 0;
        var trans = Number(r.dias_laborables_transcurridos) || 0;
        var rest = Math.max(0, total - trans);

        var diaEl = document.getElementById('st-need-dia');
        if (diaEl) {
            diaEl.textContent = (pm.alcanzada || rest <= 0) ? money(0) : money(faltan / rest);
        }

        if (pm.alcanzada) {
            if (empty) {
                empty.hidden = false;
                empty.textContent = '¡Meta alcanzada! Buen trabajo este mes.';
            }
            return;
        }

        if (!pm.filas || !pm.filas.length) {
            if (empty) {
                empty.hidden = false;
                empty.textContent = 'Registra ventas para ver el cálculo.';
            }
            return;
        }

        pm.filas.forEach(function (f, i) {
            var row = document.createElement('div');
            row.className = 'st-need__row' + (i === 0 ? ' st-need__row--first' : (i % 2 === 1 ? ' st-need__row--alt' : ''));

            var badge = document.createElement('div');
            badge.className = 'st-need__badge';
            var b = document.createElement('span');
            b.textContent = catInitials(f.cat);
            badge.appendChild(b);

            var prod = document.createElement('div');
            prod.className = 'st-need__prod';
            prod.textContent = f.cat + ' · ' + f.sub;

            var com = document.createElement('div');
            com.className = 'st-need__com';
            com.textContent = money(f.unit);

            var nec = document.createElement('div');
            nec.className = 'st-need__nec';
            nec.textContent = String(f.necesarias);

            row.appendChild(badge);
            row.appendChild(prod);
            row.appendChild(com);
            row.appendChild(nec);
            list.appendChild(row);
        });
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
            baseMes = null;
            baseAno = null;
            load(btn.dataset.periodo);
        });
    }

    if (els.prev) {
        els.prev.addEventListener('click', function () { step(-1); });
    }

    if (els.next) {
        els.next.addEventListener('click', function () { step(1); });
    }

    if (els.breakTabs) {
        els.breakTabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.st-vista__btn');
            if (!btn) return;
            els.breakTabs.querySelectorAll('.st-vista__btn').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            breakTab = btn.dataset.break;
            renderBreakdown();
        });
    }

    // Dropdown periodo del gráfico (6 / 12 meses).
    var CHART_OPTS = [
        { v: 12, label: 'Últimos 12 meses' },
        { v: 6, label: 'Últimos 6 meses' }
    ];

    function buildChartperMenu() {
        if (!els.chartperMenu || els.chartperMenu.dataset.built === '1') return;
        CHART_OPTS.forEach(function (o) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'st-chartper__item' + (o.v === mesesN ? ' is-selected' : '');
            b.setAttribute('role', 'option');
            b.dataset.v = String(o.v);
            b.textContent = o.label;
            b.addEventListener('click', function () {
                mesesN = parseInt(b.dataset.v, 10) || 12;
                if (els.chartperLabel) els.chartperLabel.textContent = o.label;
                markChartperSelected();
                closeChartperMenu();
                load(periodo);
            });
            els.chartperMenu.appendChild(b);
        });
        els.chartperMenu.dataset.built = '1';
    }

    function markChartperSelected() {
        if (!els.chartperMenu) return;
        els.chartperMenu.querySelectorAll('.st-chartper__item').forEach(function (b) {
            var on = parseInt(b.dataset.v, 10) === mesesN;
            b.classList.toggle('is-selected', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (els.chartperLabel) {
            var cur = CHART_OPTS.filter(function (o) { return o.v === mesesN; })[0];
            els.chartperLabel.textContent = cur ? cur.label : 'Últimos 12 meses';
        }
    }

    function closeChartperMenu() {
        if (!els.chartperMenu || !els.chartper || !els.chartperBtn) return;
        els.chartperMenu.hidden = true;
        els.chartperBtn.setAttribute('aria-expanded', 'false');
    }

    // Tap del chanchito: misma animación que Mi alcancía (pop + moneda + chispas + sonido).
    var pigBtn = document.getElementById('st-pig-btn');
    if (pigBtn) {
        pigBtn.addEventListener('click', function () {
            stPlayCoinSound();
            this.style.animation = 'none';
            void this.offsetWidth;
            this.style.animation = '';
            var coin = this.querySelector('.alc-pig__coin');
            if (coin) {
                coin.style.animation = 'none';
                void coin.offsetWidth;
                coin.style.animation = '';
            }
            var pig = this.closest('.alc-pig');
            if (pig) stSpawnSparks(pig);
        });
    }

    function stSpawnSparks(container) {
        if (!container) return;
        for (var i = 0; i < 9; i++) {
            var spark = document.createElement('span');
            spark.className = 'alc-spark';
            spark.style.left = '52%';
            spark.style.top = '26%';
            var angle = -Math.PI / 2 + (Math.random() - 0.5) * Math.PI * 1.1;
            var dist = 42 + Math.random() * 66;
            spark.style.setProperty('--dx', (Math.cos(angle) * dist).toFixed(0) + 'px');
            spark.style.setProperty('--dy', (Math.sin(angle) * dist).toFixed(0) + 'px');
            spark.style.setProperty('--rot', (Math.random() * 540 - 270).toFixed(0) + 'deg');
            spark.style.animationDelay = (Math.random() * 0.14).toFixed(2) + 's';
            container.appendChild(spark);
            setTimeout(function () {
                if (spark.parentNode) spark.parentNode.removeChild(spark);
            }, 1400);
        }
    }

    var _stAudioCtx = null;
    function stPlayCoinSound() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!_stAudioCtx) _stAudioCtx = new AC();
            if (_stAudioCtx.state === 'suspended') _stAudioCtx.resume();
            var ctx = _stAudioCtx;
            [740, 1040].forEach(function (freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'triangle';
                osc.frequency.value = freq;
                var t = ctx.currentTime + i * 0.08;
                gain.gain.setValueAtTime(0.0001, t);
                gain.gain.exponentialRampToValueAtTime(0.09, t + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.28);
                osc.connect(gain).connect(ctx.destination);
                osc.start(t);
                osc.stop(t + 0.3);
            });
        } catch (e) {}
    }

    if (els.chartperBtn) {
        els.chartperBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            buildChartperMenu();
            markChartperSelected();
            if (els.chartperMenu.hidden) {
                els.chartperMenu.hidden = false;
                els.chartperBtn.setAttribute('aria-expanded', 'true');
            } else {
                closeChartperMenu();
            }
        });
        document.addEventListener('click', function (e) {
            if (!els.chartperMenu || els.chartperMenu.hidden) return;
            if (e.target.closest && e.target.closest('#st-chartper')) return;
            closeChartperMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeChartperMenu();
        });
    }

    load('mes');
})();
