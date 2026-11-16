// Mi alcancia 360 - Ranking de JEFES (v1)
// 2 subsecciones: "Sucursal" (ranking por sucursal) y "Mis asesores"
// (misma tabla que la de asesores, acotada a su equipo).
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var ambito = 'sucursal';   // sucursal | equipo
    var orden = 'pct';         // pct | ventas (solo subsección sucursal)
    var expandido = false;
    var eqAmbito = 'equipo';   // equipo | global (subsección Mis asesores)
    var eqTienda = 0;          // 0 = todas las sucursales
    var mesActual = null;
    var mesViendo = null;

    var els = {
        tabs: document.getElementById('rkj-tabs'),
        cardSuc: document.getElementById('rkj-card-sucursal'),
        cardEq: document.getElementById('rkj-card-equipo'),
        sucTitle: document.getElementById('rkj-suc-title'),
        list: document.getElementById('rkj-list'),
        empty: document.getElementById('rkj-empty'),
        error: document.getElementById('rkj-error'),
        loading: document.getElementById('rkj-loading'),
        vsHead: document.getElementById('rkj-vs-head'),
        footNote: document.getElementById('rkj-foot-note'),
        eqList: document.getElementById('rkj-eq-list'),
        eqEmpty: document.getElementById('rkj-eq-empty'),
        eqError: document.getElementById('rkj-eq-error'),
        eqFoot: document.getElementById('rkj-eq-foot'),
        eqMostrando: document.getElementById('rkj-eq-mostrando'),
        eqVerTodos: document.getElementById('rkj-eq-ver-todos'),
        eqTitle: document.getElementById('rkj-eq-title'),
        eqAmbito: document.getElementById('rkj-eq-ambito'),
        eqSucursal: document.getElementById('rkj-eq-sucursal'),
        eqSucBtn: document.getElementById('rkj-eq-suc-btn'),
        eqSucMenu: document.getElementById('rkj-eq-suc-menu'),
        eqSucLabel: document.getElementById('rkj-eq-suc-label'),
        mesPicker: document.getElementById('rk-mes-picker'),
        mesBtn: document.getElementById('rk-mes-btn'),
        mesMenu: document.getElementById('rk-mes-menu'),
        mesLabel: document.getElementById('rk-mes-label')
    };

    var MESES_NOMBRE = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    var MESES_SHORT = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    // Nombre corto para espacios reducidos: primer nombre + primer apellido.
    // Las partículas ("de", "del", ...) se unen al apellido que las sigue.
    // Ej: "JUAN PEREZ GARCIA RAMOS" -> "JUAN PEREZ"; "MARIA DE LOS ANGELES" -> "MARIA DE".
    var NOMBRE_PARTICULAS = ['de', 'del', 'la', 'los', 'las', 'van', 'von', 'da', 'di'];
    function nombreCorto(nombre) {
        var partes = String(nombre || '').trim().split(/\s+/).filter(Boolean);
        if (partes.length <= 2) return partes.join(' ');
        var apellido = partes[1];
        if (NOMBRE_PARTICULAS.indexOf(apellido.toLowerCase()) >= 0 && partes[2]) {
            apellido += ' ' + partes[2];
        }
        return partes[0] + ' ' + apellido;
    }

    // Texto + tooltip (nombre completo) en un elemento.
    function setNombreCorto(id, full) {
        var el = document.getElementById(id);
        if (!el) return;
        var fullTxt = String(full || '');
        el.textContent = fullTxt ? nombreCorto(fullTxt) : '—';
        el.title = fullTxt;
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

    // ================= Carga =================

    function load(mes) {
        if (els.error) els.error.hidden = true;
        if (els.eqError) els.eqError.hidden = true;
        showLoading();

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_ranking_jefe');
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
                hideLoading();
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudo cargar el ranking.');
                    return;
                }
                data = res.data;
                if (!mesActual && data.mes) mesActual = data.mes;
                mesViendo = data.mes || mesActual;
                expandido = false;
                render();
            })
            .catch(function () {
                hideLoading();
                showError('No se pudo cargar el ranking.');
            });
    }

    function showLoading() {
        if (els.list) { els.list.hidden = true; els.list.innerHTML = ''; }
        if (els.eqList) { els.eqList.hidden = true; els.eqList.innerHTML = ''; }
        if (els.loading) els.loading.hidden = false;
        if (els.cardEq) els.cardEq.classList.add('is-pending');
    }

    function hideLoading() {
        if (els.loading) els.loading.hidden = true;
        if (els.cardEq) els.cardEq.classList.remove('is-pending');
    }

    function showError(msg) {
        // El error puede ocurrir en cualquier pestaña: muéstralo en ambas.
        [els.error, els.eqError].forEach(function (el) {
            if (el) { el.textContent = msg; el.hidden = false; }
        });
    }

    // ================= Render =================

    function render() {
        renderMesSelector();
        renderKpis();
        renderStorebar();
        renderMe();
        renderSucursal();
        renderEquipo();
        renderFootNote();
    }

    // Resumen agregado de las sucursales que administra el jefe.
    function misResumen() {
        return data.mis_resumen || null;
    }

    // Set con los ids de todas las sucursales que administra el jefe.
    function misTiendasSet() {
        var s = new Set();
        (data.mis_tiendas || []).forEach(function (t) {
            s.add(Number(t.id));
        });
        return s;
    }

    function pctTxt(p) {
        var v = Math.round(Number(p) * 10) / 10;
        return (v % 1 === 0) ? String(Math.round(v)) : v.toFixed(1);
    }

    function renderKpis() {
        if (!data) return;
        var sc = data.sucursales || [];
        var a = data.asesores || {};
        var todos = a.todos || a.top || [];

        if (ambito === 'sucursal') {
            var r = misResumen();
            var multi = r && Number(r.tiendas) > 1;
            setText('rkj-kpi1', (r && r.mejor_pos ? '#' + r.mejor_pos + ' / ' + sc.length : '—'));
            setText('rkj-kpi1-label', multi ? 'Tu mejor sucursal' : 'Ranking de tu sucursal');
            setText('rkj-kpi2', String(r ? (Number(r.ventas) || 0) : 0));
            setText('rkj-kpi2-label', multi ? 'Ventas de tus sucursales' : 'Ventas de tu sucursal');
            setText('rkj-kpi3', (r ? pctTxt(r.pct) + '%' : '—'));
            setText('rkj-kpi3-label', 'Avance de meta');
            setText('rkj-kpi4', (r ? (Number(r.con_ventas) || 0) + ' de ' + (Number(r.asesores_tot) || 0) : '—'));
            setText('rkj-kpi4-label', 'Asesores con ventas');
            setText('rkj-kpi5', (r && r.producto_top ? r.producto_top : '—'));
            if (document.getElementById('rkj-kpi5')) {
                document.getElementById('rkj-kpi5').title = (r && r.producto_top) || '';
            }
            setText('rkj-kpi5-label', 'Producto top');
        } else {
            var mejor = todos.length ? todos[0] : null;
            var conV = todos.filter(function (r) { return Number(r.ventas) > 0; }).length;
            var ventasEq = todos.reduce(function (s, r) { return s + (Number(r.ventas) || 0); }, 0);
            var ultimaTxt = '—';
            for (var i = 0; i < todos.length; i++) {
                if (todos[i].ultima && (!ultimaTxt || haceTexto(todos[i].ultima) !== '—')) {
                    ultimaTxt = haceTexto(todos[i].ultima);
                }
            }
            setNombreCorto('rkj-kpi1', (mejor ? mejor.nombre : ''));
            setText('rkj-kpi1-label', 'Tu mejor asesor');
            setText('rkj-kpi2', String(todos.length));
            setText('rkj-kpi2-label', 'Asesores a cargo');
            setText('rkj-kpi3', String(conV) + ' de ' + todos.length);
            setText('rkj-kpi3-label', 'Con ventas este mes');
            setText('rkj-kpi4', String(ventasEq));
            setText('rkj-kpi4-label', 'Ventas del equipo');
            setText('rkj-kpi5', ultimaTxt);
            setText('rkj-kpi5-label', 'Última venta del equipo');
        }
    }

    function renderStorebar() {
        if (!data) return;
        var r = misResumen();
        var nombre;
        if (ambito === 'equipo') {
            nombre = 'MIS ASESORES';
        } else if (r && Number(r.tiendas) > 1) {
            nombre = 'MIS SUCURSALES (' + Number(r.tiendas) + ')';
        } else {
            nombre = data.tienda_label || '—';
        }
        setText('rkj-store-nombre', String(nombre).toUpperCase());
    }

    function renderMe() {
        if (!data) return;
        var r = misResumen();
        var sc = data.sucursales || [];
        var a = data.asesores || {};
        var todos = a.todos || a.top || [];
        var crown = document.getElementById('rkj-me-crown');
        var cap = document.getElementById('rkj-me-crown-cap');
        var mesU = mesUpper(data.mes_label);

        if (ambito === 'sucursal') {
            var multi = r && Number(r.tiendas) > 1;
            setText('rkj-me-main-label', multi ? 'Tu mejor sucursal' : 'Tu sucursal');
            setText('rkj-me-pos', (r && r.mejor_pos ? '#' + r.mejor_pos : '—'));
            setText('rkj-me-total', 'de ' + sc.length);
            var posEl = document.getElementById('rkj-me-pos');
            if (posEl) {
                posEl.title = '';
                if (posEl.parentElement) posEl.parentElement.classList.remove('rkj-me-pos--txt');
            }
            setText('rkj-me-sub-label', 'Ventas del mes');
            setText('rkj-me-sub', String(r ? (Number(r.ventas) || 0) : 0));
            setText('rkj-me-tienda', multi
                ? 'MIS SUCURSALES (' + Number(r.tiendas) + ')'
                : (data.tienda_label || '—').toUpperCase());
            setText('rkj-me-note', 'Se ordena por % de meta para comparar sucursales de distinto tamaño.');

            var pos = r ? Number(r.mejor_pos) : 0;
            if (crown) crown.style.display = (pos >= 1 && pos <= 3) ? '' : 'none';
            if (pos >= 1 && pos <= 3) {
                paintCrown(pos);
                if (cap) {
                    var quien = multi ? 'Tu mejor sucursal' : 'Tu sucursal';
                    cap.textContent = (pos === 1)
                        ? quien + ' lidera en ' + mesU
                        : quien + ' es Top ' + pos + ' en ' + mesU;
                }
            }
        } else {
            var mejor = todos.length ? todos[0] : null;
            setText('rkj-me-main-label', 'Tu mejor asesor');
            setNombreCorto('rkj-me-pos', (mejor ? mejor.nombre : ''));
            setText('rkj-me-total', '');
            var posElEq = document.getElementById('rkj-me-pos');
            if (posElEq && posElEq.parentElement) posElEq.parentElement.classList.add('rkj-me-pos--txt');
            setText('rkj-me-sub-label', 'Ventas del mes');
            setText('rkj-me-sub', (mejor ? String(Number(mejor.ventas) || 0) : '—'));
            setText('rkj-me-tienda', 'MIS ASESORES');
            setText('rkj-me-note', 'Se ordena por comisiones del mes y se reinicia el día 1. Por privacidad, no se muestran montos de ningún asesor.');
            if (crown) crown.style.display = 'none';
        }
    }

    // Escudo por puesto (misma paleta del ranking de asesores).
    function paintCrown(rank) {
        var host = document.getElementById('rkj-crown-svg');
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
            + '<path d="M77.3438 8.59375L79.4062 12.9938L84.2188 13.6813L80.7812 16.9812L77.3438 18.0813L73.0812 20.2812L73.9062 15.6062L70.4688 13.6813L75.2812 12.9938L77.3438 8.59375Z" fill="' + p.star + '"/>'
            : '';
        host.innerHTML =
            '<svg width="110" height="145" viewBox="0 0 110 145" fill="none" xmlns="http://www.w3.org/2000/svg">'
            + stars
            + '<path d="M55 22.3438L101.406 37.8125V84.2188C101.406 110 79.9219 129.766 55 139.219C30.0781 129.766 8.59375 110 8.59375 84.2188V37.8125L55 22.3438Z" fill="white" stroke-width="1.71875" stroke="#E4EAF1"/>'
            + '<path d="M55 30.0781L95.3906 43.8281V84.2188C95.3906 105.703 76.4844 122.891 55 131.656C33.5156 122.891 14.6094 105.703 14.6094 84.2188V43.8281L55 30.0781Z" fill="' + p.mid + '"/>'
            + '<path d="M55 37.8125L88.5156 48.9844V84.2188C88.5156 101.406 73.0469 116.187 55 124.094C36.9531 116.187 21.4844 101.406 21.4844 84.2188V48.9844L55 37.8125Z" fill="#0A6CB4"/>'
            + '<path d="M55 37.8125L21.4844 48.9844V84.2188C21.4844 101.406 36.9531 116.187 55 124.094V37.8125Z" fill="#2E8BC9"/>'
            + '<path d="M3.4375 97.9688H106.562L94.5312 109.141L106.562 120.312H3.4375L15.4688 109.141L3.4375 97.9688H3.4375Z" fill="' + p.ribbon + '" stroke-width="2.40625" stroke-linejoin="round" stroke="white"/>'
            + '</svg>';
    }

    function mesUpper(label) {
        return String(label || '').split(' ')[0].toUpperCase();
    }

    function mesCortoDe(label) {
        var p = String(label || '').split(' ');
        var idx = MESES_NOMBRE.indexOf(p[0]);
        if (idx < 0) return label || '';
        return MESES_SHORT[idx].charAt(0).toUpperCase() + MESES_SHORT[idx].slice(1);
    }

    function renderSucursal() {
        if (!data) return;
        var rows = data.sucursales || [];

        setText('rkj-suc-title', 'RANKING POR SUCURSAL' + (data.empresa_label ? ' · ' + data.empresa_label : '').toUpperCase());
        // Cabecera de la columna "Vs" con el mes anterior abreviado.
        if (els.vsHead) {
            els.vsHead.textContent = 'Vs ' + mesUpper(data.mes_anterior_label) + '*';
        }

        if (!els.list) return;
        els.list.innerHTML = '';

        if (!rows.length) {
            els.list.hidden = true;
            if (els.empty) {
                els.empty.hidden = false;
                els.empty.textContent = 'Aún no hay sucursales con asesores de tu tipo.';
            }
            return;
        }
        if (els.empty) els.empty.hidden = true;
        els.list.hidden = false;

        // Orden: el servidor ya viene por % de meta. "Ventas" se re-ordena acá.
        var ordenado = rows.slice(0);
        if (orden === 'ventas') {
            ordenado.sort(function (a, b) {
                if (b.ventas !== a.ventas) return b.ventas - a.ventas;
                var pa = Math.round(Number(a.pct) * 100), pb = Math.round(Number(b.pct) * 100);
                if (pa !== pb) return pb - pa;
                return String(a.nombre).localeCompare(String(b.nombre));
            });
        }

        // Posiciones con empates según la métrica activa.
        var pos = 0, prev = null, delante = 0;
        ordenado.forEach(function (r, i) {
            var key = (orden === 'ventas')
                ? Number(r.ventas)
                : Math.round(Number(r.pct) * 100) / 100;
            if (prev === null || key !== prev) { pos = delante + 1; prev = key; }
            r._pos = pos;
            delante++;
        });

        ordenado.forEach(function (r, i) {
            els.list.appendChild(makeSucRow(r, i));
        });
    }

    function makeSucRow(r, i) {
        var pos = Number(r._pos) || (i + 1);
        var pct = Number(r.pct) || 0;
        var alt = (pos > 1 && (i % 2 === 1));

        var row = document.createElement('div');
        row.className = 'rkj-row' + (alt ? ' rkj-row--alt' : '') + (pos === 1 ? ' rkj-row--top' : '') + (pct < 10 ? ' rkj-row--low' : '');
        // Marca TODAS las sucursales que administra el jefe (no solo la asociada).
        if (misTiendasSet().has(Number(r.tienda_id))) {
            row.classList.add('rkj-row--mine');
        }

        // #
        var posEl = document.createElement('span');
        posEl.className = 'rkj-row__pos';
        posEl.textContent = String(pos);
        row.appendChild(posEl);

        // Sucursal
        var suc = document.createElement('div');
        suc.className = 'rkj-row__suc';
        var nombre = document.createElement('span');
        nombre.className = 'rkj-row__nombre';
        nombre.textContent = r.nombre || ('Sucursal #' + r.tienda_id);
        nombre.title = r.nombre || '';
        suc.appendChild(nombre);
        var dealer = document.createElement('span');
        dealer.className = 'rkj-row__dealer';
        dealer.textContent = r.dealer || '';
        suc.appendChild(dealer);
        row.appendChild(suc);

        // Asesores con ventas ("6 de 7")
        var ases = document.createElement('span');
        ases.className = 'rkj-row__ases';
        ases.textContent = (Number(r.con_ventas) || 0) + ' de ' + (Number(r.asesores_tot) || 0);
        row.appendChild(ases);

        // Ventas / meta ("16 / 35")
        var vm = document.createElement('span');
        vm.className = 'rkj-row__vm';
        vm.textContent = (Number(r.ventas) || 0) + ' / ' + (Number(r.meta_vtas) || 0);
        row.appendChild(vm);

        // Avance de meta (barra + %)
        var av = document.createElement('div');
        av.className = 'rkj-row__avance';
        var bar = document.createElement('div');
        bar.className = 'rkj-bar';
        var fill = document.createElement('div');
        fill.className = 'rkj-bar__fill';
        var w = Math.max(0, Math.min(100, pct));
        fill.style.width = w.toFixed(1) + '%';
        bar.appendChild(fill);
        av.appendChild(bar);
        var pctEl = document.createElement('span');
        pctEl.className = 'rkj-row__pct';
        var pctTxt = (Math.round(pct * 10) / 10);
        pctTxt = (pctTxt % 1 === 0) ? String(Math.round(pctTxt)) : pctTxt.toFixed(1);
        pctEl.textContent = pctTxt + '%';
        av.appendChild(pctEl);
        row.appendChild(av);

        // Producto top
        var prod = document.createElement('span');
        prod.className = 'rkj-row__prod';
        prod.textContent = r.producto_top || '—';
        prod.title = r.producto_top || '';
        row.appendChild(prod);

        // Vs mes anterior
        var vs = document.createElement('span');
        vs.className = 'rkj-row__vs';
        var vp = (r.vs_pct === null || r.vs_pct === undefined) ? null : Number(r.vs_pct);
        if (vp === null) {
            vs.textContent = '—';
        } else if (vp > 0) {
            vs.classList.add('rkj-row__vs--up');
            vs.textContent = '▲ ' + Math.abs(Math.round(vp)) + '%';
        } else if (vp < 0) {
            vs.classList.add('rkj-row__vs--down');
            vs.textContent = '▼ ' + Math.abs(Math.round(vp)) + '%';
        } else {
            vs.textContent = '= 0%';
        }
        row.appendChild(vs);

        return row;
    }

    function renderEquipo() {
        if (!data) return;
        var esGlobal = (eqAmbito === 'global');
        var scope = esGlobal ? (data.asesores_global || {}) : (data.asesores || {});
        var todos = scope.todos || scope.top || [];

        // Filtro por sucursal (solo en modo "Mi equipo").
        if (!esGlobal && eqTienda) {
            todos = todos.filter(function (r) {
                return Number(r.tienda_id) === Number(eqTienda);
            });
        }
        var total = todos.length;

        if (!els.eqList) return;
        els.eqList.innerHTML = '';

        setText('rkj-eq-title', esGlobal
            ? 'RANKING GLOBAL · ASESORES DE ' + mesUpper(data.tipo_label) + ' · ' + mesUpper(data.mes_label)
            : 'RANKING · MIS ASESORES · ' + mesUpper(data.mes_label));

        // El picker de sucursal solo aplica a "Mi equipo".
        if (els.eqSucursal) els.eqSucursal.hidden = esGlobal;
        updateEqSucLabel();

        if (!todos.length || !total) {
            els.eqList.hidden = true;
            if (els.eqFoot) els.eqFoot.hidden = true;
            if (els.eqEmpty) {
                els.eqEmpty.hidden = false;
                els.eqEmpty.textContent = esGlobal
                    ? 'No hay asesores de tu tipo con datos este mes.'
                    : (eqTienda ? 'No hay ventas en esta sucursal este mes.' : 'Tus asesores aún no tienen ventas este mes.');
            }
            return;
        }
        if (els.eqEmpty) els.eqEmpty.hidden = true;
        els.eqList.hidden = false;

        var LIMITE = 10;
        var visibles = expandido ? todos : todos.slice(0, LIMITE);

        var con = visibles.filter(function (r) { return Number(r.ventas) > 0; });
        var sin = visibles.filter(function (r) { return Number(r.ventas) <= 0; });

        // Con filtro de sucursal, la competencia es dentro de esa tienda:
        // se recalcula la posición. Sin filtro se respeta la del servidor.
        var conFiltro = (!esGlobal && eqTienda);
        var pos = 0, prev = null, delante = 0;
        todos.forEach(function (r) {
            var k = Number(r.ventas);
            if (prev === null || k !== prev) { pos = delante + 1; prev = k; }
            r._pos = pos;
            delante++;
        });

        con.forEach(function (r, i) { els.eqList.appendChild(makeEqRow(r, i % 2 === 1, conFiltro)); });
        sin.forEach(function (r, i) { els.eqList.appendChild(makeEqRow(r, (con.length + i) % 2 === 1, conFiltro)); });

        if (els.eqFoot) {
            if (total > LIMITE) {
                els.eqFoot.hidden = false;
                if (els.eqMostrando) {
                    els.eqMostrando.textContent = expandido
                        ? 'Mostrando ' + total + ' de ' + total + ' asesores'
                        : 'Mostrando ' + visibles.length + ' de ' + total + ' asesores';
                }
                if (els.eqVerTodos) {
                    els.eqVerTodos.textContent = expandido ? 'Ver menos' : 'Ver los ' + total + ' asesores';
                    els.eqVerTodos.hidden = false;
                }
            } else {
                els.eqFoot.hidden = false;
                if (els.eqMostrando) {
                    els.eqMostrando.textContent = 'Mostrando ' + total + ' de ' + total + ' asesores';
                }
                if (els.eqVerTodos) els.eqVerTodos.hidden = true;
            }
        }
    }

    function makeEqRow(r, alt, conFiltro) {
        var row = document.createElement('div');
        row.className = 'rk-row' + (alt ? ' rk-row--alt' : '');

        var posWrap = document.createElement('span');
        posWrap.className = 'rk-row__pos';
        var pos = document.createElement('span');
        var p = conFiltro ? (Number(r._pos) || 0) : (Number(r.pos) || 0);
        if (p >= 1 && p <= 3 && Number(r.ventas) > 0) {
            pos.className = 'rk-pos rk-pos--' + p;
        } else {
            pos.className = 'rk-pos rk-pos--tie';
        }
        pos.textContent = String(p || '—');
        posWrap.appendChild(pos);
        row.appendChild(posWrap);

        var name = document.createElement('span');
        name.className = 'rk-row__name';
        name.textContent = r.nombre ? nombreCorto(r.nombre) : '';
        name.title = r.nombre || '';
        row.appendChild(name);

        var v = document.createElement('span');
        var nv = Number(r.ventas) || 0;
        v.className = 'rk-row__ventas' + (nv <= 0 ? ' rk-row__ventas--zero' : '');
        v.textContent = String(nv);
        v.setAttribute('data-u', nv === 1 ? 'venta' : 'ventas');
        row.appendChild(v);

        var u = document.createElement('span');
        var hasUlt = !!r.ultima;
        u.className = 'rk-row__ultima' + (hasUlt ? '' : ' rk-row__ultima--none');
        u.textContent = hasUlt ? haceTexto(r.ultima) : '—';
        row.appendChild(u);

        return row;
    }

    function renderFootNote() {
        if (!data) return;
        if (!els.footNote) return;
        var mesAnt = data.mes_anterior_label || '';
        var comparacion = (data.es_actual === false)
            ? 'el mes de ' + mesAnt
            : 'el mismo día de ' + mesAnt;
        var nota = orden === 'ventas'
            ? 'El ranking se ordena por número de ventas.'
            : 'El ranking se ordena por % de meta para comparar sucursales de distinto tamaño.';
        els.footNote.textContent = '*Comparado con ' + comparacion + '. ' + nota;
    }

    // ================= Tabs =================

    function switchTab(amb) {
        ambito = amb;
        if (els.tabs) {
            els.tabs.querySelectorAll('.rk-tab').forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.ambito === amb);
            });
        }
        if (els.cardSuc) els.cardSuc.hidden = (amb !== 'sucursal');
        if (els.cardEq) els.cardEq.hidden = (amb !== 'equipo');
        var sub = document.getElementById('rkj-sub');
        if (sub) sub.textContent = (amb === 'equipo') ? '¿Cómo van tus asesores?' : '¿Cómo van tus sucursales?';

        // Sincroniza el sidebar (sub-links Sucursal / Mis asesores) y la URL
        // sin recargar la página.
        document.querySelectorAll('.app-sidebar__sublink[data-ranking-tab]').forEach(function (a) {
            a.classList.toggle('is-active', a.dataset.rankingTab === amb);
        });
        try {
            var url = new URL(window.location.href);
            url.searchParams.set('tab', amb);
            window.history.replaceState({}, '', url);
        } catch (e) {}

        // Al cambiar de pestaña, KPIs / storebar / panel siguen a la subsección.
        renderKpis();
        renderStorebar();
        renderMe();

        // Al cambiar de pestaña, el error activo pasa a la pestaña visible.
        var msg = null;
        if (els.error && !els.error.hidden) msg = els.error.textContent;
        if (els.error) els.error.hidden = true;
        if (els.eqError) els.eqError.hidden = true;
        if (msg) showError(msg);
    }

    if (els.tabs) {
        els.tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.rk-tab');
            if (!btn) return;
            switchTab(btn.dataset.ambito || 'sucursal');
        });
    }

    // ================= Ordenar por =================

    document.querySelectorAll('.rkj-sort__btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!data) return;
            orden = btn.dataset.orden || 'pct';
            document.querySelectorAll('.rkj-sort__btn').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            renderSucursal();
            renderFootNote();
        });
    });

    if (els.eqVerTodos) {
        els.eqVerTodos.addEventListener('click', function () {
            expandido = !expandido;
            renderEquipo();
        });
    }

    // ================= "Mis asesores": ámbito + sucursal =================

    document.querySelectorAll('.rkj-eq-ambito__btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!data) return;
            eqAmbito = btn.dataset.eqAmbito || 'equipo';
            expandido = false;
            document.querySelectorAll('.rkj-eq-ambito__btn').forEach(function (b) {
                b.classList.toggle('is-active', b === btn);
            });
            renderEquipo();
        });
    });

    function sucNombre(t) {
        if (!t) return 'Todas las sucursales';
        // Nombre corto: la parte después del último " - " si existe.
        var n = String(t.nombre || '');
        var i = n.lastIndexOf(' - ');
        return (i > 0 && i < n.length - 3) ? n.slice(i + 3) : n;
    }

    function buildEqSucMenu() {
        if (!els.eqSucMenu || !data) return;
        els.eqSucMenu.innerHTML = '';

        var optAll = document.createElement('button');
        optAll.type = 'button';
        optAll.className = 'rk-month__item' + (eqTienda === 0 ? ' is-selected' : '');
        optAll.setAttribute('role', 'option');
        var checkAll = document.createElement('span');
        checkAll.className = 'rk-month__check';
        checkAll.textContent = '✓';
        var txtAll = document.createElement('span');
        txtAll.textContent = 'Todas las sucursales';
        optAll.appendChild(checkAll);
        optAll.appendChild(txtAll);
        optAll.addEventListener('click', function () {
            eqTienda = 0;
            expandido = false;
            closeEqSucMenu();
            renderEquipo();
        });
        els.eqSucMenu.appendChild(optAll);

        (data.mis_tiendas || []).forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'rk-month__item' + (Number(t.id) === Number(eqTienda) ? ' is-selected' : '');
            b.setAttribute('role', 'option');
            var check = document.createElement('span');
            check.className = 'rk-month__check';
            check.textContent = '✓';
            var txt = document.createElement('span');
            txt.textContent = sucNombre(t);
            b.appendChild(check);
            b.appendChild(txt);
            b.addEventListener('click', function () {
                eqTienda = Number(t.id);
                expandido = false;
                closeEqSucMenu();
                renderEquipo();
            });
            els.eqSucMenu.appendChild(b);
        });
    }

    function updateEqSucLabel() {
        if (!els.eqSucLabel || !data) return;
        if (eqTienda === 0) {
            els.eqSucLabel.textContent = 'Todas las sucursales';
            return;
        }
        var match = null;
        (data.mis_tiendas || []).forEach(function (t) {
            if (Number(t.id) === Number(eqTienda)) match = t;
        });
        els.eqSucLabel.textContent = match ? sucNombre(match) : 'Sucursal';
    }

    function openEqSucMenu() {
        if (!els.eqSucMenu || !els.eqSucBtn) return;
        buildEqSucMenu();
        els.eqSucMenu.hidden = false;
        els.eqSucBtn.setAttribute('aria-expanded', 'true');
    }

    function closeEqSucMenu() {
        if (!els.eqSucMenu || !els.eqSucBtn) return;
        els.eqSucMenu.hidden = true;
        els.eqSucBtn.setAttribute('aria-expanded', 'false');
    }

    if (els.eqSucBtn) {
        els.eqSucBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (els.eqSucMenu && els.eqSucMenu.hidden) openEqSucMenu();
            else closeEqSucMenu();
        });
        document.addEventListener('click', function (e) {
            if (!els.eqSucMenu || els.eqSucMenu.hidden) return;
            if (e.target.closest && (e.target.closest('#rkj-eq-sucursal'))) return;
            closeEqSucMenu();
        });
        document.addEventListener('keydown', function (e) {
            if (!els.eqSucMenu || els.eqSucMenu.hidden) return;
            if (e.key === 'Escape') closeEqSucMenu();
        });
    }

    // ================= Mes picker (igual al de asesores) =================

    function mesKey(y, m) { return y + '-' + (m < 10 ? '0' + m : '' + m); }

    function parseMesKey(key) {
        var m = /^(\d{4})-(\d{2})$/.exec(key || '');
        if (!m) return null;
        return { y: parseInt(m[1], 10), m: parseInt(m[2], 10) };
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

    var mqMobile = (typeof window.matchMedia === 'function') ? window.matchMedia('(max-width: 600px)') : null;
    function etiquetaMes(label) {
        if (!(mqMobile && mqMobile.matches)) return label;
        var partes = String(label || '').split(' ');
        var idx = MESES_NOMBRE.indexOf(partes[0]);
        if (idx < 0) return label;
        var corto = MESES_SHORT[idx];
        corto = corto.charAt(0).toUpperCase() + corto.slice(1);
        return [corto].concat(partes.slice(1)).join(' ');
    }
    if (mqMobile && typeof mqMobile.addEventListener === 'function') {
        mqMobile.addEventListener('change', function () {
            if (els.mesMenu) { els.mesMenu.innerHTML = ''; delete els.mesMenu.dataset.built; }
            if (data) renderMesSelector();
        });
    }

    function renderMesSelector() {
        if (els.mesLabel) els.mesLabel.textContent = etiquetaMes(data.mes_label || '—');
        buildMesMenu();
        markMesSelected();
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
            txt.textContent = etiquetaMes(m.label);
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
        els.mesMenu.querySelectorAll('.rk-month__item').forEach(function (b) {
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

    // Init: respeta ?tab=sucursal|equipo (sub-links del sidebar)
    var initialTab = 'sucursal';
    try {
        var qp = new URLSearchParams(window.location.search);
        var t = qp.get('tab');
        if (t === 'equipo' || t === 'sucursal') initialTab = t;
    } catch (e) {}
    switchTab(initialTab);
    load();
})();
