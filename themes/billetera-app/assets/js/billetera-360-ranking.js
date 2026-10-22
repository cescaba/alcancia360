// Mi alcancia 360 - Ranking v3 (solo nombres, orden por N° ventas)
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var ambito = 'tienda';
    var mesActual = null;
    var mesViendo = null;
    var expandido = false;

    var els = {
        loading: document.getElementById('rk-loading'),
        list: document.getElementById('rk-list'),
        empty: document.getElementById('rk-empty'),
        error: document.getElementById('rk-error'),
        cardTitle: document.getElementById('rk-card-title'),
        tabs: document.getElementById('rk-tabs'),
        verMes: document.getElementById('rk-ver-mes'),
        foot: document.getElementById('rk-foot'),
        mostrando: document.getElementById('rk-mostrando'),
        verTodos: document.getElementById('rk-ver-todos'),
        mesSelect: null,
        mesPicker: document.getElementById('rk-mes-picker'),
        mesBtn: document.getElementById('rk-mes-btn'),
        mesMenu: document.getElementById('rk-mes-menu'),
        mesLabel: document.getElementById('rk-mes-label')
    };

    var MESES_NOMBRE = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    var MESES_UPPER = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];
    var MESES_SHORT = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function load(mes) {
        if (els.error) els.error.hidden = true;
        var table = document.getElementById('rk-table');
        var pending = document.getElementById('rk-pending');

        if (!data) {
            // Primera carga (pantalla vacía): skeleton con shimmer.
            renderSkeleton();
            document.getElementById('screen-ranking').classList.add('rk-is-loading');
        } else {
            // Cambio de mes (<1s): conservar datos, indicador sutil.
            if (table) table.classList.add('is-pending');
            if (pending) pending.hidden = false;
            document.getElementById('screen-ranking').classList.add('rk-is-loading');
        }

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_ranking');
        body.append('nonce', window.billetera.nonce_ranking);
        if (mes) body.append('mes', mes);

        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                document.getElementById('screen-ranking').classList.remove('rk-is-loading');
                var table = document.getElementById('rk-table');
                var pending = document.getElementById('rk-pending');
                if (table) table.classList.remove('is-pending');
                if (pending) pending.hidden = true;
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudo cargar el ranking.');
                    return;
                }
                data = res.data;
                if (!mesActual && data.mes) mesActual = data.mes;
                mesViendo = data.mes || mesActual;
                expandido = false;
                if (els.loading) els.loading.hidden = true;
                render();
            })
            .catch(function () {
                document.getElementById('screen-ranking').classList.remove('rk-is-loading');
                var table = document.getElementById('rk-table');
                var pending = document.getElementById('rk-pending');
                if (table) table.classList.remove('is-pending');
                if (pending) pending.hidden = true;
                showError('No se pudo cargar el ranking.');
            });
    }

    // Skeleton con la forma real (KPIs + Mi puesto + 8 filas) para el cambio de mes.
    function renderSkeleton() {
        if (els.loading) els.loading.hidden = true;
        if (els.error) els.error.hidden = true;
        if (els.empty) els.empty.hidden = true;
        if (els.foot) els.foot.hidden = true;

        ['kpi-tienda', 'kpi-global', 'kpi-ultima', 'kpi-ventaja', 'kpi-ventas'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.innerHTML = '<span class="rk-skel rk-skel--val">&nbsp;</span>';
        });
        var pos = document.getElementById('rk-me-pos');
        if (pos) pos.innerHTML = '<span class="rk-skel rk-skel--val">&nbsp;</span>';
        var mg = document.getElementById('rk-me-global');
        if (mg) mg.innerHTML = '<span class="rk-skel rk-skel--label">&nbsp;</span>';
        var mt = document.getElementById('rk-me-tienda');
        if (mt) mt.innerHTML = '<span class="rk-skel rk-skel--label">&nbsp;</span>';
        var crown = document.getElementById('rk-crown-svg');
        if (crown) crown.innerHTML = '<span class="rk-skel" style="display:block;width:110px;height:120px;">&nbsp;</span>';

        if (els.cardTitle) els.cardTitle.innerHTML = '<span class="rk-skel rk-skel--title">&nbsp;</span>';

        if (els.list) {
            els.list.hidden = false;
            els.list.innerHTML = '';
            for (var i = 0; i < 8; i++) {
                var row = document.createElement('div');
                row.className = 'rk-skel-row' + (i % 2 === 1 ? ' rk-skel-row--alt' : '');
                row.setAttribute('aria-hidden', 'true');
                row.innerHTML =
                    '<span class="rk-skel rk-skel__circle">&nbsp;</span>' +
                    '<span class="rk-skel rk-skel__line">&nbsp;</span>' +
                    '<span class="rk-skel rk-skel__num">&nbsp;</span>' +
                    '<span class="rk-skel rk-skel__date">&nbsp;</span>';
                els.list.appendChild(row);
            }
        }
    }

    function showError(msg) {
        if (els.loading) els.loading.hidden = true;
        if (els.list) els.list.hidden = true;
        if (els.empty) els.empty.hidden = true;
        if (els.foot) els.foot.hidden = true;
        if (els.error) { els.error.textContent = msg; els.error.hidden = false; }
    }

    function parseFecha(s) {
        if (!s) return null;
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function haceTexto(s) {
        var d = parseFecha(s);
        if (!d) return '—';
        var now = new Date();
        var diff = Math.floor((now - d) / 86400000);
        if (diff <= 0) return 'hoy';
        if (diff === 1) return 'ayer';
        if (diff < 30) return 'hace ' + diff + ' días';
        return d.getDate() + ' ' + MESES_SHORT[d.getMonth()] + ' ' + d.getFullYear();
    }

    function mesUpper(mesLabel) {
        // mesLabel: "Octubre 2026" -> "OCTUBRE"
        if (!mesLabel) return '';
        return String(mesLabel).split(' ')[0].toUpperCase();
    }

    function render() {
        if (!data) return;
        setText('rk-mes', data.mes_label || '');
        renderMesSelector();
        renderKpis();
        renderMe();
        renderList();
        renderVerMes();
    }

    function mesKey(y, m) {
        return y + '-' + (m < 10 ? '0' + m : '' + m);
    }

    function parseMesKey(key) {
        var m = /^(\d{4})-(\d{2})$/.exec(key || '');
        if (!m) return null;
        return { y: parseInt(m[1], 10), m: parseInt(m[2], 10) };
    }

    function renderMesSelector() {
        if (els.mesLabel) els.mesLabel.textContent = data.mes_label || '—';
        buildMesMenu();
        markMesSelected();
    }

    function mesesDisponibles() {
        var base = parseMesKey(mesActual) || parseMesKey(data && data.mes);
        if (!base) return [];
        var out = [];
        for (var i = 0; i < 12; i++) {
            var mm = base.m - i;
            var yy = base.y;
            while (mm < 1) { mm += 12; yy--; }
            out.push({ key: mesKey(yy, mm), label: MESES_NOMBRE[mm - 1] + ' ' + yy });
        }
        return out;
    }

    function buildMesMenu() {
        if (!els.mesMenu || els.mesMenu.dataset.built === '1') return;
        if (!mesActual && !(data && data.mes)) return;
        var meses = mesesDisponibles();
        if (!meses.length) return;
        els.mesMenu.innerHTML = '';
        meses.forEach(function (m, idx) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'rk-month__item';
            b.setAttribute('role', 'option');
            b.dataset.mes = m.key;
            b.dataset.idx = String(idx);
            var check = document.createElement('span');
            check.className = 'rk-month__check';
            check.textContent = '✓';
            var txt = document.createElement('span');
            txt.textContent = m.label;
            b.appendChild(check);
            b.appendChild(txt);
            if (idx === 0) {
                var tag = document.createElement('span');
                tag.className = 'rk-month__tag';
                tag.textContent = 'actual';
                b.appendChild(tag);
            }
            b.addEventListener('click', function () {
                closeMesMenu();
                if (m.key !== mesViendo) load(m.key);
                if (els.mesBtn) els.mesBtn.focus();
            });
            els.mesMenu.appendChild(b);
        });
        els.mesMenu.dataset.built = '1';
    }

    function markMesSelected() {
        if (!els.mesMenu) return;
        var items = els.mesMenu.querySelectorAll('.rk-month__item');
        items.forEach(function (b) {
            var on = b.dataset.mes === mesViendo;
            b.classList.toggle('is-selected', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
    }

    function openMesMenu() {
        if (!els.mesMenu || !els.mesPicker || !els.mesBtn) return;
        buildMesMenu();
        markMesSelected();
        els.mesMenu.hidden = false;
        els.mesPicker.classList.add('open');
        els.mesBtn.setAttribute('aria-expanded', 'true');
        var sel = els.mesMenu.querySelector('.rk-month__item.is-selected') || els.mesMenu.querySelector('.rk-month__item');
        if (sel) sel.focus();
    }

    function closeMesMenu() {
        if (!els.mesMenu || !els.mesPicker || !els.mesBtn) return;
        els.mesMenu.hidden = true;
        els.mesPicker.classList.remove('open');
        els.mesBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleMesMenu() {
        if (!els.mesMenu || els.mesMenu.hidden) openMesMenu();
        else closeMesMenu();
    }

    function scopeActual() {
        return data[ambito] || {};
    }

    function renderKpis() {
        var t = data.tienda || {};
        var g = data.global || {};

        setText('kpi-tienda', (t.rank ? '#' + t.rank + ' / ' + t.total : '—'));
        setText('kpi-global', (g.rank ? '#' + g.rank + ' / ' + g.total : '—'));

        var ultimaTxt = haceTexto(t.mi_ultima);
        // Spec: "hace 2 días" en mayúsculas de número? Solo texto.
        setText('kpi-ultima', ultimaTxt === '—' ? '—' : ultimaTxt);

        var vLabel = document.getElementById('kpi-ventaja-label');
        if ((t.rank || 0) === 1) {
            var v = Number(t.ventaja) || 0;
            setText('kpi-ventaja', '+ ' + v + (v === 1 ? ' venta' : ' ventas'));
            if (vLabel) vLabel.textContent = 'Ventaja sobre el #2';
        } else {
            var f = Number(t.faltan) || 0;
            setText('kpi-ventaja', (t.rank ? f + (f === 1 ? ' venta' : ' ventas') : '—'));
            if (vLabel) vLabel.textContent = t.rank ? 'Te faltan para el #1' : 'Ventaja sobre el #2';
        }

        setText('kpi-ventas', String(Number(t.mi_ventas) || 0));
    }

    function renderMe() {
        var t = data.tienda || {};
        var g = data.global || {};
        var rank = Number(t.rank) || 0;

        setText('rk-me-pos', rank ? '#' + rank : '—');
        setText('rk-me-total', 'de ' + (t.total || 0));
        setText('rk-me-global', (g.rank ? '#' + g.rank + ' / ' + g.total : '—'));
        setText('rk-me-tienda', (data.tienda_label || '—').toUpperCase());

        var crown = document.getElementById('rk-me-crown');
        var cap = document.getElementById('rk-me-crown-cap');
        var mesU = mesUpper(data.mes_label);
        if (crown) {
            // Insignia solo para top 3; si no, se oculta toda la caja.
            crown.style.display = (rank >= 1 && rank <= 3) ? '' : 'none';
        }
        if (rank >= 1 && rank <= 3) {
            paintCrown(rank);
        }
        if (cap) {
            if (rank === 1) cap.textContent = 'Líder de tu tienda en ' + mesU;
            else if (rank >= 2 && rank <= 3) cap.textContent = 'Top ' + rank + ' de tu tienda en ' + mesU;
        }
    }

    // Escudo por puesto: misma forma, paleta oro/plata/bronce.
    // Estrellas solo para el #1.
    function paintCrown(rank) {
        var host = document.getElementById('rk-crown-svg');
        if (!host) return;
        var palettes = {
            1: { star: '#E0A63E', mid: '#C98A1E', ribbon: '#E0A63E', stars: true },
            2: { star: '#B8C3D1', mid: '#8494A6', ribbon: '#B8C3D1', stars: false },
            3: { star: '#D99A5B', mid: '#D99A5B', ribbon: '#D99A5B', stars: false }
        };
        var p = palettes[rank] || palettes[1];
        var stars = p.stars
            ? '<path d="M32.6562 8.59375L34.7188 12.9938L39.5312 13.6813L36.0938 16.9812L36.9188 21.6562L32.6562 18.0813L28.3938 20.2812L29.2188 15.6062L25.7812 13.6813L30.5938 12.9938L32.6562 8.59375Z" fill="' + p.star + '"/>'
            + '<path d="M55 2.14844L57.707 7.92344L64.0234 8.82578L59.5117 13.157L60.5945 19.293L55 14.6008L49.4055 17.4883L50.4883 11.3523L45.9766 8.82578L52.293 7.92344L55 2.14844Z" fill="' + p.star + '"/>'
            + '<path d="M77.3438 8.59375L79.4062 12.9938L84.2188 13.6813L80.7812 16.9812L81.6063 21.6562L77.3438 18.0813L73.0813 20.2812L73.9062 15.6062L70.4688 13.6813L75.2812 12.9938L77.3438 8.59375Z" fill="' + p.star + '"/>'
            : '';
        host.innerHTML =
            '<svg width="110" height="145" viewBox="0 0 110 145" fill="none" xmlns="http://www.w3.org/2000/svg">'
            + stars
            + '<path d="M55 22.3438L101.406 37.8125V84.2188C101.406 110 79.9219 129.766 55 139.219C30.0781 129.766 8.59375 110 8.59375 84.2188V37.8125L55 22.3438Z" fill="white" stroke-width="1.71875" stroke="#E4EAF1"/>'
            + '<path d="M55 30.0781L95.3906 43.8281V84.2188C95.3906 105.703 76.4844 122.891 55 131.656C33.5156 122.891 14.6094 105.703 14.6094 84.2188V43.8281L55 30.0781Z" fill="' + p.mid + '"/>'
            + '<path d="M55 37.8125L88.5156 48.9844V84.2188C88.5156 101.406 73.0469 116.187 55 124.094C36.9531 116.187 21.4844 101.406 21.4844 84.2188V48.9844L55 37.8125Z" fill="#0A6CB4"/>'
            + '<path d="M55 37.8125L21.4844 48.9844V84.2188C21.4844 101.406 36.9531 116.187 55 124.094V37.8125Z" fill="#2E8BC9"/>'
            + '<path d="M3.4375 97.9688H106.562L94.5312 109.141L106.562 120.312H3.4375L15.4688 109.141L3.4375 97.9688Z" fill="' + p.ribbon + '" stroke-width="2.40625" stroke-linejoin="round" stroke="white"/>'
            + '</svg>';
    }

    function renderVerMes() {
        if (!els.verMes) return;
        if (data.es_actual === false) {
            els.verMes.textContent = 'Ver mes actual';
        } else {
            els.verMes.textContent = 'Ver mes anterior';
        }
    }

    function renderList() {
        if (!els.list) return;
        els.list.innerHTML = '';

        var scope = scopeActual();
        var todos = scope.todos || scope.top || [];
        var total = scope.total || todos.length;

        if (!todos.length || !total) {
            els.list.hidden = true;
            if (els.foot) els.foot.hidden = true;
            if (els.empty) {
                els.empty.hidden = false;
                els.empty.textContent = ambito === 'tienda'
                    ? 'Tu tienda aún no tiene asesores con ventas este mes.'
                    : 'Aún no hay ventas registradas este mes.';
            }
            if (els.cardTitle) {
                els.cardTitle.textContent = ambito === 'tienda'
                    ? 'Ranking · ' + (data.tienda_label || '').toUpperCase()
                    : 'Ranking global';
            }
            return;
        }

        if (els.empty) els.empty.hidden = true;
        els.list.hidden = false;

        if (els.cardTitle) {
            els.cardTitle.textContent = ambito === 'tienda'
                ? 'RANKING · ' + (data.tienda_label || '').toUpperCase()
                : 'RANKING GLOBAL';
        }

        var LIMITE = 10;
        var visibles = expandido ? todos : todos.slice(0, LIMITE);

        var con = visibles.filter(function (r) { return Number(r.ventas) > 0; });
        var sin = visibles.filter(function (r) { return Number(r.ventas) <= 0; });

        con.forEach(function (r, i) {
            els.list.appendChild(makeRow(r, i % 2 === 1));
        });

        var sinTotal = Number(scope.sin_ventas) || 0;
        if (sin.length || (!expandido && sinTotal > 0 && visibles.length >= LIMITE)) {
            // Separador "sin ventas"
            var mesU = mesUpper(data.mes_label);
            var posEmpate = null;
            var todosSin = (scope.todos || []).filter(function (r) { return Number(r.ventas) <= 0; });
            if (todosSin.length) posEmpate = todosSin[0].pos;
            var sep = document.createElement('div');
            sep.className = 'rk-sep';
            var left = document.createElement('span');
            left.textContent = 'Sin ventas en ' + mesU;
            var right = document.createElement('span');
            right.textContent = sinTotal + ' asesores · empatados en #' + (posEmpate || '—');
            sep.appendChild(left);
            sep.appendChild(right);
            els.list.appendChild(sep);
        }

        sin.forEach(function (r, i) {
            els.list.appendChild(makeRow(r, (con.length + i) % 2 === 1));
        });

        // Si hay ventana (usuario fuera del top y no expandido), mostrarla al final.
        if (!expandido && !scope.en_top && (scope.ventana || []).length) {
            var yaIncluido = visibles.some(function (r) { return r.es_yo; });
            if (!yaIncluido) {
                var gap = document.createElement('div');
                gap.className = 'rk-sep';
                var gl = document.createElement('span');
                gl.textContent = 'Tu posición';
                var gr = document.createElement('span');
                gr.textContent = '';
                gap.appendChild(gl);
                gap.appendChild(gr);
                els.list.appendChild(gap);
                scope.ventana.forEach(function (r, i) {
                    els.list.appendChild(makeRow(r, i % 2 === 1));
                });
            }
        }

        // Footer mostrando / ver todos
        if (els.foot) {
            if (total > LIMITE) {
                els.foot.hidden = false;
                if (els.mostrando) {
                    els.mostrando.textContent = expandido
                        ? 'Mostrando ' + total + ' de ' + total + ' asesores'
                        : 'Mostrando ' + visibles.length + ' de ' + total + ' asesores';
                }
                if (els.verTodos) {
                    els.verTodos.textContent = expandido ? 'Ver menos' : 'Ver los ' + total;
                    els.verTodos.hidden = false;
                }
            } else {
                els.foot.hidden = false;
                if (els.mostrando) {
                    els.mostrando.textContent = 'Mostrando ' + total + ' de ' + total + ' asesores';
                }
                if (els.verTodos) {
                    els.verTodos.hidden = true;
                }
            }
        }
    }

    function makeRow(r, alt) {
        var row = document.createElement('div');
        row.className = 'rk-row' + (alt ? ' rk-row--alt' : '') + (r.es_yo ? ' is-me' : '');

        var posWrap = document.createElement('span');
        posWrap.className = 'rk-row__pos';
        var pos = document.createElement('span');
        var p = Number(r.pos) || 0;
        if (p >= 1 && p <= 3 && Number(r.ventas) > 0) {
            pos.className = 'rk-pos rk-pos--' + p;
            pos.textContent = String(p);
        } else {
            pos.className = 'rk-pos rk-pos--tie';
            pos.textContent = '=';
        }
        posWrap.appendChild(pos);
        row.appendChild(posWrap);

        var name = document.createElement('span');
        name.className = 'rk-row__name';
        name.textContent = r.nombre || '';
        row.appendChild(name);

        if (r.es_yo) {
            var tag = document.createElement('span');
            tag.className = 'rk-tag';
            tag.textContent = 'Tú';
            name.appendChild(tag);
        }

        var v = document.createElement('span');
        var nv = Number(r.ventas) || 0;
        v.className = 'rk-row__ventas' + (nv <= 0 ? ' rk-row__ventas--zero' : '');
        v.textContent = String(nv);
        row.appendChild(v);

        var u = document.createElement('span');
        var hasUlt = !!r.ultima;
        u.className = 'rk-row__ultima' + (hasUlt ? '' : ' rk-row__ultima--none');
        u.textContent = hasUlt ? haceTexto(r.ultima) : '—';
        row.appendChild(u);

        return row;
    }

    if (els.mesBtn) {
        els.mesBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleMesMenu();
        });
        els.mesBtn.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openMesMenu();
            }
        });
        document.addEventListener('click', function (e) {
            if (!els.mesMenu || els.mesMenu.hidden) return;
            if (e.target.closest && (e.target.closest('#rk-mes-picker'))) return;
            closeMesMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (!els.mesMenu || els.mesMenu.hidden) return;
            if (e.key === 'Escape') {
                closeMesMenu();
                els.mesBtn.focus();
                return;
            }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                var items = Array.prototype.slice.call(els.mesMenu.querySelectorAll('.rk-month__item'));
                if (!items.length) return;
                e.preventDefault();
                var idx = items.indexOf(document.activeElement);
                if (e.key === 'ArrowDown') idx = (idx + 1) % items.length;
                else idx = (idx - 1 + items.length) % items.length;
                items[idx].focus();
            }
        });
    }

    if (els.tabs) {
        els.tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.rk-tab');
            if (!btn) return;
            els.tabs.querySelectorAll('.rk-tab').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            ambito = btn.dataset.ambito;
            expandido = false;
            renderList();
        });
    }

    if (els.verTodos) {
        els.verTodos.addEventListener('click', function () {
            expandido = !expandido;
            renderList();
        });
    }

    if (els.verMes) {
        els.verMes.addEventListener('click', function () {
            if (!data) return;
            if (data.es_actual === false) {
                load(mesActual);
            } else if (data.mes_anterior) {
                load(data.mes_anterior);
            }
        });
    }

    load(null);
})();
