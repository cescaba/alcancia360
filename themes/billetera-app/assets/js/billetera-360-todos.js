// Mi alcancia 360 - Historial de movimientos (agrupado por mes)

const MONTHS_FULL = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

const ID_LABELS = {
    placa: 'Placa',
    vin: 'VIN',
    ot: 'OT',
    factura: 'N° Factura'
};

const CAT_COLORS = ['#5B5FA8', '#C98A1E', '#0A6CB4', '#B05C34', '#B04E7C', '#1B6E46', '#7A5C16'];
const STOP_WORDS = ['de', 'del', 'y', 'la', 'el', 'los', 'las', 'liqui', 'moly'];

const listEl = document.getElementById('hist-list');
const emptyEl = document.getElementById('hist-empty');
const deskList = document.getElementById('hist-desk-list');
const deskEmpty = document.getElementById('hist-desk-empty');

function init() {
    fetch(billetera.ajax_url + '?action=billetera_get_all_movements')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            render(res.data.movements || []);
        });
}

function parseDate(s) {
    return new Date((s || '').replace(' ', 'T'));
}

function groupByMonth(movements) {
    const groups = [];
    movements.forEach(function (m) {
        const d = parseDate(m.created_at);
        const label = MONTHS_FULL[d.getMonth()] + ' ' + d.getFullYear();
        let g = groups[groups.length - 1];
        if (!g || g.label !== label) {
            g = { label: label, total: 0, items: [] };
            groups.push(g);
        }
        g.items.push(m);
        g.total += Number(m.amount) || 0;
    });
    return groups;
}

function render(movements) {
    const groups = groupByMonth(movements);

    if (!movements.length) {
        if (listEl) listEl.innerHTML = '';
        if (deskList) deskList.innerHTML = '';
        if (emptyEl) emptyEl.style.display = 'block';
        if (deskEmpty) deskEmpty.style.display = 'block';
        return;
    }

    if (emptyEl) emptyEl.style.display = 'none';
    if (deskEmpty) deskEmpty.style.display = 'none';

    renderMobile(groups);
    renderDesktop(groups);
}

function renderMobile(groups) {
    if (!listEl) return;
    listEl.innerHTML = '';
    groups.forEach(function (g) {
        const month = document.createElement('div');
        month.className = 'hist-month';

        const head = document.createElement('div');
        head.className = 'hist-month__head';
        head.innerHTML = '<span class="hist-month__name">' + g.label + '</span>' +
            '<span class="hist-month__total">' + fmt(g.total) + '</span>';

        const list = document.createElement('div');
        list.className = 'alc-mov-list';
        g.items.forEach(function (m) {
            list.appendChild(buildMov(m));
        });

        month.appendChild(head);
        month.appendChild(list);
        listEl.appendChild(month);
    });
}

function renderDesktop(groups) {
    if (!deskList) return;
    deskList.innerHTML = '';
    groups.forEach(function (g) {
        const month = document.createElement('div');
        month.className = 'hist-desk__month';

        month.innerHTML =
            '<div class="hist-desk__month-head">' +
                '<span class="hist-desk__month-name">' + g.label + '</span>' +
                '<span class="hist-desk__month-total">Total ' + fmt(g.total) + '</span>' +
            '</div>' +
            '<div class="hist-desk__tr hist-desk__tr--head">' +
                '<div class="hist-desk__td">Cat.</div>' +
                '<div class="hist-desk__td">Producto</div>' +
                '<div class="hist-desk__td">' + ((window.billetera && window.billetera.es_jefe) ? 'Asesor' : 'Identificador') + '</div>' +
                '<div class="hist-desk__td">Fecha</div>' +
                '<div class="hist-desk__td hist-desk__td--right">Comisión</div>' +
            '</div>';

        g.items.forEach(function (m) {
            month.appendChild(buildDeskRow(m));
        });

        deskList.appendChild(month);
    });
}

function buildMov(m) {
    const row = document.createElement('div');
    row.className = 'alc-mov';

    const color = catColor(m.categoria || '');
    const nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
    const ident = m.asesor_nombre ? m.asesor_nombre : ((ID_LABELS[m.id_type] || (m.id_type || '').toUpperCase()) + ' ' + (m.id_value || ''));
    const meta = ident + ' · ' + fullDate(parseDate(m.created_at));

    const badge = document.createElement('div');
    badge.className = 'alc-mov__badge';
    badge.style.background = hexA(color, 0.1);
    badge.style.borderColor = hexA(color, 0.333);
    badge.textContent = catInitials(m.categoria || '');

    const body = document.createElement('div');
    body.className = 'alc-mov__body';

    const name = document.createElement('div');
    name.className = 'alc-mov__name';
    name.textContent = nombre;

    const metaEl = document.createElement('div');
    metaEl.className = 'alc-mov__meta';
    metaEl.textContent = meta;

    body.appendChild(name);
    body.appendChild(metaEl);

    const amt = document.createElement('div');
    amt.className = 'alc-mov__amt';
    amt.textContent = '+' + fmt(Number(m.amount) || 0);
    if (Number(m.bonus_multiplier) > 1) {
        const b = document.createElement('span');
        b.className = 'alc-mov__bonus';
        b.textContent = '2x';
        amt.appendChild(b);
    }

    row.appendChild(badge);
    row.appendChild(body);
    row.appendChild(amt);

    return row;
}

function buildDeskRow(m) {
    const row = document.createElement('div');
    row.className = 'hist-desk__tr';

    const color = catColor(m.categoria || '');
    const nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
    const fecha = fullDate(parseDate(m.created_at));

    const badge = document.createElement('div');
    badge.className = 'hist-desk__td';
    badge.innerHTML = '<span class="hist-desk__badge" style="background:' + hexA(color, 0.1) + ';border-color:' + hexA(color, 0.333) + '">' + catInitials(m.categoria || '') + '</span>';

    const prod = document.createElement('div');
    prod.className = 'hist-desk__td hist-desk__td--prod';
    prod.textContent = nombre;

    const id = document.createElement('div');
    id.className = 'hist-desk__td hist-desk__td--id' + (m.asesor_nombre ? ' hist-desk__td--asesor' : '');
    id.textContent = m.asesor_nombre || (m.id_value || '');

    const fechaEl = document.createElement('div');
    fechaEl.className = 'hist-desk__td hist-desk__td--fecha';
    fechaEl.textContent = fecha;

    const amt = document.createElement('div');
    amt.className = 'hist-desk__td hist-desk__td--amt';
    amt.textContent = '+' + fmt(Number(m.amount) || 0);

    row.appendChild(badge);
    row.appendChild(prod);
    row.appendChild(id);
    row.appendChild(fechaEl);
    row.appendChild(amt);

    return row;
}

function catInitials(cat) {
    if (!cat) return '--';
    const words = cat.split(/[\s\-]+/).filter(w => w && STOP_WORDS.indexOf(w.toLowerCase()) === -1);
    if (words.length >= 2) {
        return (words[0][0] + words[1][0]).toUpperCase();
    }
    return cat.slice(0, 2).toUpperCase();
}

function catColor(cat) {
    let h = 0;
    for (let i = 0; i < cat.length; i++) {
        h = (h * 31 + cat.charCodeAt(i)) % 997;
    }
    return CAT_COLORS[h % CAT_COLORS.length];
}

function hexA(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
}

function fmt(n) {
    return 'S/ ' + Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fullDate(date) {
    if (isNaN(date.getTime())) return '';
    return date.getDate() + ' ' + MONTHS_SHORT[date.getMonth()] + ' ' + date.getFullYear();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
