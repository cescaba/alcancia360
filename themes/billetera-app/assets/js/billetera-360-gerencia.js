// Mi alcancia 360 - Vista de GERENTE ("Mi concesionaria") v1
// Endpoint: action=billetera_get_gerencia. Solo lectura, sin montos.
(function () {
    'use strict';

    var cfg = window.billeteraGerencia || {};
    var els = {
        loading: document.getElementById('st-loading'),
        body: document.getElementById('st-body'),
        stepperLabel: document.getElementById('st-step-label'),
        prev: document.getElementById('st-prev'),
        next: document.getElementById('st-next'),
        kpiVentas: document.getElementById('gr-kpi-ventas'),
        kpiAvance: document.getElementById('gr-kpi-avance'),
        kpiFaltan: document.getElementById('gr-kpi-faltan'),
        kpiProy: document.getElementById('gr-kpi-proy'),
        tbody: document.getElementById('gr-tbody'),
        tfootVentas: document.getElementById('gr-total-ventas'),
        tfootMeta: document.getElementById('gr-total-meta'),
        tfootAvance: document.getElementById('gr-total-avance'),
        tfootFaltan: document.getElementById('gr-total-faltan'),
        periodoLabel: document.getElementById('gr-periodo-label'),
        sucBtn: document.getElementById('eq-sucursal-btn'),
        sucVal: document.getElementById('eq-sucursal-val'),
        sucMenu: document.getElementById('eq-sucursal-menu')
    };

    if (!cfg.tiene_tiendas) {
        if (els.loading) { els.loading.hidden = true; }
        return;
    }

    // ---- Estado: mes seleccionado (Y-m) y sucursal (0 = todas) ----
    var now = new Date();
    var mesActual = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    var mesSel = mesActual;
    var tiendaSel = 0;
    var req = 0;

    var MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    function labelMes(ym) {
        var parts = ym.split('-');
        return MESES[parseInt(parts[1], 10) - 1] + ' ' + parts[0];
    }

    function shiftMes(ym, delta) {
        var parts = ym.split('-');
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1 + delta;
        while (m < 0) { m += 12; y--; }
        while (m > 11) { m -= 12; y++; }
        return y + '-' + String(m + 1).padStart(2, '0');
    }

    function fmtFecha(ymdhis) {
        if (!ymdhis) return '—';
        var d = new Date(ymdhis.replace(' ', 'T'));
        if (isNaN(d)) return '—';
        return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function fmtAvance(v) {
        return (v === null || v === undefined) ? '—' : (Math.round(v * 10) / 10) + '%';
    }

    // ---- Selector de sucursal (mismo patrón que Mi equipo) ----
    function closeSucMenu() {
        if (els.sucMenu) { els.sucMenu.hidden = true; }
        if (els.sucBtn) { els.sucBtn.setAttribute('aria-expanded', 'false'); }
    }

    if (els.sucBtn && els.sucMenu) {
        els.sucBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = !els.sucMenu.hidden;
            els.sucMenu.hidden = open;
            els.sucBtn.setAttribute('aria-expanded', String(!open));
        });
        els.sucMenu.addEventListener('click', function (e) {
            var btn = e.target.closest('.eq-sucursal__opt');
            if (!btn) return;
            tiendaSel = parseInt(btn.getAttribute('data-tienda'), 10) || 0;
            els.sucMenu.querySelectorAll('.eq-sucursal__opt').forEach(function (o) {
                var isSel = o === btn;
                o.classList.toggle('is-active', isSel);
                o.setAttribute('aria-selected', String(isSel));
            });
            if (tiendaSel === 0) {
                // "Todas (N)" ya viene como texto del botón
                els.sucVal.textContent = btn.textContent;
            } else {
                els.sucVal.textContent = btn.textContent;
            }
            closeSucMenu();
            load();
        });
        document.addEventListener('click', function () { closeSucMenu(); });
    }

    // ---- Stepper de mes ----
    function updateStepper() {
        if (els.stepperLabel) { els.stepperLabel.textContent = labelMes(mesSel); }
        if (els.next) { els.next.disabled = (mesSel >= mesActual); }
    }

    if (els.prev) {
        els.prev.addEventListener('click', function () {
            mesSel = shiftMes(mesSel, -1);
            updateStepper();
            load();
        });
    }
    if (els.next) {
        els.next.addEventListener('click', function () {
            if (mesSel >= mesActual) return;
            mesSel = shiftMes(mesSel, 1);
            updateStepper();
            load();
        });
    }

    // ---- Render ----
    function render(data) {
        var t = data.total || {};

        if (els.kpiVentas) els.kpiVentas.textContent = t.ventas != null ? t.ventas : '—';
        if (els.kpiAvance) els.kpiAvance.textContent = fmtAvance(t.avance);
        if (els.kpiFaltan) {
            els.kpiFaltan.textContent = (t.faltan === null || t.faltan === undefined) ? '—' : t.faltan;
        }
        if (els.kpiProy) els.kpiProy.textContent = t.proyeccion != null ? t.proyeccion : '—';
        if (els.periodoLabel) els.periodoLabel.textContent = data.periodo_label || labelMes(mesSel);

        if (els.tbody) {
            els.tbody.innerHTML = '';
            var sucs = data.sucursales || [];
            if (!sucs.length) {
                var tr0 = document.createElement('tr');
                var td0 = document.createElement('td');
                td0.colSpan = 6;
                td0.className = 'gr-empty-cell';
                td0.textContent = 'Sin sucursales asignadas.';
                tr0.appendChild(td0);
                els.tbody.appendChild(tr0);
            }
            sucs.forEach(function (s) {
                var tr = document.createElement('tr');

                var tdN = document.createElement('td');
                tdN.textContent = s.nombre;
                tr.appendChild(tdN);

                [s.ventas, s.meta, fmtAvance(s.avance), (s.faltan === null || s.faltan === undefined) ? '—' : s.faltan, fmtFecha(s.ultima)]
                    .forEach(function (val) {
                        var td = document.createElement('td');
                        td.className = 'gr-num';
                        td.textContent = String(val);
                        tr.appendChild(td);
                    });

                els.tbody.appendChild(tr);
            });
        }

        if (els.tfootVentas) els.tfootVentas.textContent = t.ventas != null ? t.ventas : '—';
        if (els.tfootMeta) els.tfootMeta.textContent = t.meta != null ? t.meta : '—';
        if (els.tfootAvance) els.tfootAvance.textContent = fmtAvance(t.avance);
        if (els.tfootFaltan) {
            els.tfootFaltan.textContent = (t.faltan === null || t.faltan === undefined) ? '—' : t.faltan;
        }
    }

    // ---- Carga ----
    function load() {
        var my = ++req;
        if (els.loading) { els.loading.hidden = false; }
        if (els.body) { els.body.hidden = true; }

        var body = 'action=billetera_get_gerencia'
            + '&nonce=' + encodeURIComponent(cfg.nonce)
            + '&mes=' + encodeURIComponent(mesSel)
            + '&tienda=' + encodeURIComponent(tiendaSel);

        fetch(cfg.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (my !== req) return;
                if (res && res.success && res.data) {
                    render(res.data);
                    if (els.loading) { els.loading.hidden = true; }
                    if (els.body) { els.body.hidden = false; }
                } else {
                    if (els.loading) {
                        els.loading.hidden = false;
                        els.loading.textContent = (res && res.data && res.data.message) ? res.data.message : 'No se pudo cargar la vista.';
                    }
                }
            })
            .catch(function () {
                if (my !== req) return;
                if (els.loading) {
                    els.loading.hidden = false;
                    els.loading.textContent = 'No se pudo cargar la vista.';
                }
            });
    }

    updateStepper();
    load();
})();
