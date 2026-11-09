// Mi alcancia 360 - "Mi equipo": vista agregada del jefe de venta
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var periodo = 'mes';
    var breakTab = 'categoria';
    var trendRows = [];
    var baseMes = null;
    var baseAno = null;
    var mesesN = 12;
    var campanas = [];
    var bannerIdx = 0;
    var bannerTimer = null;
    var topAll = false;
    var tienda = 0; // 0 = todas las sucursales del jefe

    var MESES_LARGOS = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    var MESES_FULL = MESES_LARGOS;

    var els = {
        loading: document.getElementById('st-loading'),
        body: document.getElementById('st-body'),
        error: document.getElementById('st-error'),
        period: document.getElementById('st-period'),
        breakTabs: document.getElementById('eq-break-tabs'),
        mixList: document.getElementById('eq-mix-list'),
        mixTitle: document.getElementById('eq-mix-title'),
        mixTotal: document.getElementById('eq-mix-total'),
        trend: document.getElementById('st-trend'),
        trendDetail: document.getElementById('st-trend-detail'),
        aportaList: document.getElementById('eq-aporta-list'),
        aportaMore: document.getElementById('eq-aporta-more'),
        aportaMes: document.getElementById('eq-aporta-mes'),
        aportaNote: document.getElementById('eq-aporta-total-note'),
        topList: document.getElementById('eq-top-list'),
        topMes: document.getElementById('eq-top-mes'),
        topToggle: document.getElementById('eq-top-toggle'),
        alertPend: document.getElementById('eq-alerts-pend'),
        stepper: document.getElementById('st-stepper'),
        prev: document.getElementById('st-prev'),
        next: document.getElementById('st-next'),
        stepLabel: document.getElementById('st-step-label'),
        chartper: document.getElementById('st-chartper'),
        chartperBtn: document.getElementById('st-chartper-btn'),
        chartperMenu: document.getElementById('st-chartper-menu'),
        chartperLabel: document.getElementById('st-chartper-label'),
        sucursal: document.getElementById('eq-sucursal'),
        sucursalBtn: document.getElementById('eq-sucursal-btn'),
        sucursalMenu: document.getElementById('eq-sucursal-menu'),
        sucursalVal: document.getElementById('eq-sucursal-val')
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

    function moneyShort(n) {
        n = Number(n) || 0;
        if (n >= 1000) return 'S/ ' + (n / 1000).toFixed(1) + 'k';
        return 'S/ ' + n.toFixed(0);
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

    function mesMes(key) {
        var m = /^(\d{4})-(\d{2})$/.exec(key || '');
        return m ? (MESES_FULL[parseInt(m[2], 10) - 1] + ' ' + m[1]) : '—';
    }

    function load(p) {
        if (p) periodo = p;
        if (els.error) els.error.hidden = true;

        var screen = document.getElementById('screen-equipo');
        var pending = document.getElementById('st-pending');
        if (!data) {
            showLoading(true);
        } else {
            if (screen) screen.classList.add('st-is-loading');
            if (pending) pending.hidden = false;
        }

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_mi_equipo');
        body.append('nonce', window.billetera.nonce_mi_equipo);
        body.append('periodo', periodo);
        if (periodo === 'mes' && baseMes) body.append('mes', baseMes);
        if (periodo === 'ano' && baseAno) body.append('ano', baseAno);
        body.append('meses', String(mesesN));
        body.append('tienda', String(tienda));

        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var screen = document.getElementById('screen-equipo');
                var pending = document.getElementById('st-pending');
                if (screen) screen.classList.remove('st-is-loading');
                if (pending) pending.hidden = true;
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudo cargar tu equipo.');
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
                var screen = document.getElementById('screen-equipo');
                var pending = document.getElementById('st-pending');
                if (screen) screen.classList.remove('st-is-loading');
                if (pending) pending.hidden = true;
                showError('No se pudo cargar tu equipo.');
            });
    }

    function render() {
        if (!data) return;
        renderBanner();
        renderKpis();
        renderGoal();
        renderAporta();
        renderTrend(data.tendencia || []);
        renderBreakdown();
        renderTop();
        renderAlertas();
    }

    // Banner de campaña (mismo motor que Mi desempeño).
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

    function renderKpis() {
        var k = (data && data.kpis) || {};
        setText('k5-ventas', String(Number(k.ventas) || 0));
        setText('k5-avance', (k.avance || 0) + '%');
        setText('k5-faltan', (Number(k.faltan_ventas) || 0) + ' ventas');
        setText('k5-cierre', (Number(k.dias_rest) || 0) + (Number(k.dias_rest) === 1 ? ' día' : ' días'));
        setText('k5-proy', '~' + (Number(k.proy_ventas) || 0) + ' ventas');
    }

    function renderGoal() {
        var k = (data && data.kpis) || {};
        var jefe = (data && data.jefe) || {};
        var ritmo = (data && data.ritmo) || {};

        var fill = (k.meta_ventas > 0) ? Math.min(100, Math.round(k.ventas / k.meta_ventas * 100)) : 0;
        var fillEl = document.getElementById('st-goal-fill');
        if (fillEl) fillEl.style.width = fill + '%';
        var pig = document.getElementById('st-pig-fill');
        if (pig) pig.style.clipPath = 'inset(' + (100 - fill) + '% 0 0 0)';

        setText('st-goal-cap', fill + '% de la meta del equipo');
        setText('st-pocito', money(jefe.com_mes));
        setText('st-goal-meta', (k.meta_ventas || 0) + ' ventas');

        var mark = document.getElementById('st-goal-mark');
        var total = Number(k.dias_laborables) || 0;
        var trans = Number(k.dias_laborables_transcurridos) || 0;
        if (mark && total > 0) mark.style.left = Math.min(100, trans / total * 100) + '%';

        var pace = document.getElementById('st-goal-pace');
        if (pace) {
            var ideal = Number(ritmo.ideal_ventas) || 0;
            var behind = Number(ritmo.behind) || 0;
            if (k.meta_ventas > 0 && behind > 0) {
                pace.innerHTML = 'Ritmo ideal hoy ' + ideal + ' ventas · <b class="down">van ' + behind + ' abajo</b>';
            } else if (k.meta_ventas > 0) {
                pace.innerHTML = 'Ritmo ideal hoy ' + ideal + ' ventas · <b class="up">al ritmo</b>';
            } else {
                pace.textContent = '—';
            }
        }
    }

    function renderAporta() {
        if (!els.aportaList || !data) return;
        els.aportaList.innerHTML = '';
        if (els.aportaMore) els.aportaMore.hidden = true;

        var mesLabel = (periodo === 'historico') ? 'Histórico'
            : (periodo === 'ano' ? (data.base_ano || curAno()) : mesMes(data.base_mes));
        if (els.aportaMes) els.aportaMes.textContent = mesLabel;

        var rows = (data.aportan) || [];
        if (els.aportaNote) {
            els.aportaNote.textContent = rows.length
                ? 'Total generado por tu equipo: ' + money((data.kpis || {}).comisiones)
                : 'Cuando tu equipo registre ventas, verás aquí lo que aporta cada uno.';
        }

        if (!rows.length) {
            els.aportaList.appendChild(emptyNote('Aún no hay comisiones de tu equipo.'));
            return;
        }

        var show = rows.slice(0, 4);
        show.forEach(function (row) {
            var li = document.createElement('div');
            li.className = 'eq-aporta__row';

            var name = document.createElement('div');
            name.className = 'eq-aporta__name';
            var initials = document.createElement('span');
            initials.className = 'eq-aporta__ini';
            initials.textContent = iniciales(row.nombre);
            var txt = document.createElement('div');
            var n = document.createElement('div');
            n.className = 'eq-aporta__nom';
            n.textContent = row.nombre;
            var v = document.createElement('div');
            v.className = 'eq-aporta__ventas';
            v.textContent = row.ventas + (row.ventas === 1 ? ' venta del mes' : ' ventas del mes');
            txt.appendChild(n);
            txt.appendChild(v);
            name.appendChild(initials);
            name.appendChild(txt);

            var amt = document.createElement('div');
            amt.className = 'eq-aporta__amt';
            amt.textContent = money(row.com);

            li.appendChild(name);
            li.appendChild(amt);
            els.aportaList.appendChild(li);
        });

        var rest = rows.length - show.length;
        if (rest > 0 && els.aportaMore) {
            els.aportaMore.hidden = false;
            els.aportaMore.textContent = '+ ' + rest + ' vendedor' + (rest === 1 ? '' : 'es') + ' más';
        }
    }

    function iniciales(nombre) {
        var stop = ['de', 'del', 'y', 'la', 'el', 'los', 'las'];
        var words = String(nombre || '').split(/\s+/).filter(function (w) { return w && stop.indexOf(w.toLowerCase()) === -1; });
        if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
        return String(nombre || '--').slice(0, 2).toUpperCase();
    }

    function renderStepper() {
        if (!els.stepper) return;
        if (periodo === 'historico') {
            els.stepper.classList.add('is-hidden');
            return;
        }
        els.stepper.classList.remove('is-hidden');
        if (els.stepLabel) els.stepLabel.textContent = (data && data.base_label) || '—';
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

    function renderTrend(rows) {
        if (!els.trend) return;
        els.trend.innerHTML = '';
        trendRows = rows || [];

        var k = (data && data.kpis) || {};
        var meta = Number(k.meta_ventas) || 0;
        setText('st-chart-meta', meta ? (meta + ' ventas') : '—');

        if (!trendRows.some(function (r) { return Number(r.ventas) > 0; })) {
            els.trend.appendChild(emptyNote('Tu equipo aún no registra ventas en este periodo.'));
            var metal0 = document.getElementById('st-chart-metal');
            if (metal0) metal0.hidden = true;
            if (els.trendDetail) els.trendDetail.textContent = '';
            return;
        }

        var max = meta;
        trendRows.forEach(function (r) { max = Math.max(max, Number(r.ventas) || 0); });
        if (max <= 0) max = 1;

        var PH = 150;

        trendRows.forEach(function (r, idx) {
            var tot = Number(r.ventas) || 0;
            var col = document.createElement('div');
            var isCur = data.base_mes && r.mes === data.base_mes;
            col.className = 'st-chart__col' + (isCur ? ' is-current' : '') + (tot > 0 ? ' has-value' : '');
            col.setAttribute('role', 'button');
            col.setAttribute('tabindex', '0');

            var barH = tot > 0 ? Math.max(tot / max * PH, 2) : 0;

            var val = document.createElement('div');
            val.className = 'st-chart__val';
            val.textContent = String(tot);
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

        var best = 0;
        trendRows.forEach(function (r, i) {
            if (Number(r.ventas) > Number(trendRows[best].ventas)) best = i;
        });
        selectTrend(best, true);
    }

    function selectTrend(idx, isBest) {
        if (!trendRows.length || idx < 0) return;

        var cols = els.trend.querySelectorAll('.st-chart__col');
        cols.forEach(function (c, i) { c.classList.toggle('is-active', idx === i); });

        var r = trendRows[idx];
        if (els.trendDetail) {
            if (isBest) {
                var m = /^(\d{4})-(\d{2})$/.exec(r.mes || '');
                var nombre = m ? MESES_FULL[parseInt(m[2], 10) - 1] : r.label;
                els.trendDetail.innerHTML = 'Mejor mes: ' + nombre + ' · <b>' + r.ventas + ' ventas del equipo</b>';
            } else {
                els.trendDetail.textContent = r.label + ' · ' + r.ventas + (r.ventas === 1 ? ' venta' : ' ventas') +
                    (Number(r.total) > 0 ? ' · ' + money(r.total) : '');
            }
        }
    }

    function renderBreakdown() {
        if (!els.mixList || !data) return;
        els.mixList.innerHTML = '';

        var key = 'por_' + breakTab;
        var rows = data[key] || [];

        var totalVentas = 0;
        rows.forEach(function (r) { totalVentas += Number(r.ventas) || 0; });

        var titulo = '¿Qué vende tu equipo?';
        if (periodo === 'historico') titulo += ' · Histórico';
        else if (periodo === 'ano') titulo += ' · ' + (data.base_ano || curAno());
        if (els.mixTitle) els.mixTitle.textContent = titulo;
        if (els.mixTotal) els.mixTotal.textContent = totalVentas + (totalVentas === 1 ? ' venta' : ' ventas');

        if (!rows.length) {
            els.mixList.appendChild(emptyNote('Sin ventas en este periodo.'));
            return;
        }

        // Todas las filas; el máx. de 4 visibles + scroll lo maneja el CSS de #eq-mix-list.
        rows.forEach(function (r, idx) {
            // Mismo markup de filas que "¿En qué ganas más?" en Mi desempeño,
            // pero con la CANTIDAD de ventas en la derecha (negrita, sin montos).
            var row = document.createElement('div');
            row.className = 'st-bd__row' + (idx % 2 === 1 ? ' st-bd__row--alt' : '');

            var head = document.createElement('div');
            head.className = 'st-bd__head';

            var left = document.createElement('span');
            left.className = 'st-bd__label';
            left.textContent = r.label;

            var ventas = document.createElement('span');
            ventas.className = 'st-bd__amount';   // bold; aquí muestra la cantidad de ventas
            ventas.textContent = r.ventas + (r.ventas === 1 ? ' venta' : ' ventas');

            head.appendChild(left);
            head.appendChild(ventas);

            row.appendChild(head);
            els.mixList.appendChild(row);
        });
    }

    function renderTop() {
        if (!els.topList || !data) return;
        els.topList.innerHTML = '';

        var rows = (data.top) || [];
        var mesLabel = (periodo === 'historico') ? 'Histórico'
            : (periodo === 'ano' ? (data.base_ano || curAno()) : mesMes(data.base_mes));
        setText('eq-top-mes', mesLabel);

        var visibleMax = 6;
        var show = topAll ? rows : rows.slice(0, visibleMax);
        var hayMas = rows.length > visibleMax;

        if (els.topToggle) {
            els.topToggle.hidden = !hayMas;
            els.topToggle.textContent = topAll
                ? 'Ver menos'
                : ('Ver los ' + rows.length + ' asesores');
        }

        if (!rows.length) {
            els.topList.appendChild(emptyNote('Sin asesores en tu equipo.'));
            return;
        }

        show.forEach(function (row, i) {
            var li = document.createElement('div');
            li.className = 'eq-top__row' + (i === 0 ? ' eq-top__row--gold' : (i % 2 === 1 ? ' eq-top__row--alt' : ''));

            var puesto = document.createElement('span');
            puesto.className = 'eq-top__puesto' + (i === 0 ? ' eq-top__puesto--gold' : '');
            puesto.textContent = (i + 1);

            var nom = document.createElement('span');
            nom.className = 'eq-top__nom';
            nom.textContent = row.nombre;

            var suc = document.createElement('span');
            suc.className = 'eq-top__suc';
            suc.textContent = row.sucursal || '—';

            var vts = document.createElement('span');
            vts.className = 'eq-top__vts' + (i === 0 ? ' eq-top__vts--gold' : '');
            vts.textContent = String(row.ventas);

            li.appendChild(puesto);
            li.appendChild(nom);
            li.appendChild(suc);
            li.appendChild(vts);
            els.topList.appendChild(li);
        });

        if (topAll && hayMas) return;
    }

    function renderAlertas() {
        var alerts = (data && data.alertas) || {};
        var k = (data && data.kpis) || {};

        var periodoAlerta = 'este mes';
        if (periodo === 'historico') periodoAlerta = 'histórico';
        else if (periodo === 'ano') periodoAlerta = (data.base_ano || curAno());
        else periodoAlerta = (mesMes(data.base_mes) || 'este mes').toLowerCase();

        var sinEl = document.getElementById('eq-alert-sin');
        if (sinEl) {
            var sinT = sinEl.querySelector('.eq-alert__t');
            var sinD = sinEl.querySelector('.eq-alert__d');
            var sinCta = document.getElementById('eq-alert-sin-cta');
            if (Number(alerts.sin_ventas_total) > 0) {
                var nSin = Number(alerts.sin_ventas_total);
                if (sinT) sinT.textContent = nSin + (nSin === 1 ? ' asesor' : ' asesores') + ' sin ventas en ' + periodoAlerta;
                if (sinD) {
                    var porTienda = alerts.sin_ventas_por_tienda || {};
                    var dets = [];
                    var piezas = Object.keys(porTienda).map(function (tk) {
                        return porTienda[tk] + ' de ' + tk;
                    });
                    if (piezas.length) dets.push(piezas.join(' y '));
                    sinD.textContent = (dets.length ? dets.join(' · ') + '. ' : '')
                        + 'Agenda un acompañamiento o una práctica de cierre esta semana.';
                }
                if (sinCta) sinCta.hidden = false;
            } else {
                if (sinT) sinT.textContent = 'Todo el equipo está en actividad';
                if (sinD) sinD.textContent = 'Todos tus asesores registraron ventas este mes. ¡Buen pulso!';
                if (sinCta) sinCta.hidden = true;
            }
        }

        var ritmoEl = document.getElementById('eq-alert-ritmo');
        if (ritmoEl) {
            var ritT = ritmoEl.querySelector('.eq-alert__t');
            var ritD = ritmoEl.querySelector('.eq-alert__d');
            var ritCta = document.getElementById('eq-alert-ritmo-cta');
            var behind = Number(alerts.behind_ventas) || 0;
            var campanasOn = campanas && campanas.length > 0;
            if (behind > 0 && k.meta_ventas > 0) {
                if (ritT) ritT.textContent = 'Van ' + behind + ' ventas detrás del ritmo ideal';
                if (ritD) ritD.textContent = 'Necesitan ~' + (alerts.ventas_dia || 0) + ' ventas por día para llegar a la meta.';
                if (ritCta) ritCta.hidden = !campanasOn;
            } else {
                if (ritT) ritT.textContent = 'El equipo va al ritmo de la meta';
                if (ritD) ritD.textContent = 'Sigue empujando los productos de la campaña para cerrar fuerte.';
                if (ritCta) ritCta.hidden = !campanasOn;
            }
        }

        var pend = document.getElementById('eq-alerts-pend');
        if (pend) {
            var total = (Number(alerts.sin_ventas_total) > 0 ? 1 : 0) + (((Number(alerts.behind_ventas) || 0) > 0) ? 1 : 0);
            if (total > 0) {
                pend.hidden = false;
                pend.textContent = total + ' pendientes';
            } else {
                pend.hidden = true;
            }
        }
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
            topAll = false;
            load(btn.dataset.periodo);
        });
    }

    if (els.prev) els.prev.addEventListener('click', function () { step(-1); });
    if (els.next) els.next.addEventListener('click', function () { step(1); });

    if (els.topToggle) {
        els.topToggle.addEventListener('click', function () {
            topAll = !topAll;
            renderTop();
            if (topAll) {
                var sec = document.querySelector('.eq-top');
                if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
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

    var sinCta = document.getElementById('eq-alert-sin-cta');
    if (sinCta) {
        sinCta.addEventListener('click', function () {
            topAll = true;
            renderTop();
            var sec = document.querySelector('.eq-top');
            if (sec && sec.scrollIntoView) sec.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    var ritCta = document.getElementById('eq-alert-ritmo-cta');
    if (ritCta) {
        ritCta.addEventListener('click', function () {
            var banner = document.getElementById('st-banner');
            if (banner && !banner.hidden && banner.scrollIntoView) {
                banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
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
    }
    document.addEventListener('click', function (e) {
        if (!els.chartperMenu || els.chartperMenu.hidden) return;
        if (e.target.closest && e.target.closest('#st-chartper')) return;
        closeChartperMenu();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeChartperMenu();
    });

    // Dropdown de sucursal ( Todas (N) / cada sucursal del jefe ).
    function closeSucursalMenu() {
        if (!els.sucursalMenu || !els.sucursal || !els.sucursalBtn) return;
        els.sucursalMenu.hidden = true;
        els.sucursalBtn.setAttribute('aria-expanded', 'false');
    }

    function wireSucursal() {
        if (!els.sucursal || !els.sucursalBtn || !els.sucursalMenu) return;

        els.sucursalBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (els.sucursalMenu.hidden) {
                els.sucursalMenu.hidden = false;
                els.sucursalBtn.setAttribute('aria-expanded', 'true');
            } else {
                closeSucursalMenu();
            }
        });

        els.sucursalMenu.addEventListener('click', function (e) {
            var opt = e.target.closest('.eq-sucursal__opt');
            if (!opt) return;
            tienda = parseInt(opt.dataset.tienda, 10) || 0;
            els.sucursalMenu.querySelectorAll('.eq-sucursal__opt').forEach(function (b) {
                var on = b === opt;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            var name = opt.textContent.trim();
            if (els.sucursalVal) els.sucursalVal.textContent = name;
            closeSucursalMenu();
            baseMes = null;
            baseAno = null;
            topAll = false;
            load(periodo);
        });

        document.addEventListener('click', function (e) {
            if (!els.sucursalMenu || els.sucursalMenu.hidden) return;
            if (e.target.closest && e.target.closest('#eq-sucursal')) return;
            closeSucursalMenu();
        });
    }

    wireSucursal();

    // Tap del chanchito (misma animación y sonido que Mi alcancía).
    var pigBtn = document.getElementById('st-pig-btn');
    if (pigBtn) {
        pigBtn.addEventListener('click', function () {
            eqPlayCoinSound();
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
            if (pig) eqSpawnSparks(pig);
        });
    }

    function eqSpawnSparks(container) {
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

    var _eqAudioCtx = null;
    function eqPlayCoinSound() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!_eqAudioCtx) _eqAudioCtx = new AC();
            if (_eqAudioCtx.state === 'suspended') _eqAudioCtx.resume();
            var ctx = _eqAudioCtx;
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

    load('mes');
})();
