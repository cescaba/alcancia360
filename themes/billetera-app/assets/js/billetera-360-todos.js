// Billetera 360 - Todos los movimientos

const listEl = document.getElementById('todos-list');
const emptyEl = document.getElementById('todos-empty');

const ID_LABELS = {
    placa: 'Placa',
    vin: 'VIN',
    ot: 'OT',
    factura: 'N° Factura'
};

const CAT_COLORS = ['#5B5FA8', '#C98A1E', '#0A6CB4', '#B05C34', '#B04E7C', '#1B6E46', '#7A5C16'];
const STOP_WORDS = ['de', 'del', 'y', 'la', 'el', 'los', 'las', 'liqui', 'moly'];

function init() {
    fetch(billetera.ajax_url + '?action=billetera_get_all_movements')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            render(res.data.movements || []);
        });
}

function render(movements) {
    if (!listEl) return;

    if (!movements.length) {
        listEl.style.display = 'none';
        if (emptyEl) emptyEl.style.display = 'block';
        return;
    }

    listEl.innerHTML = '';
    movements.forEach(function (m, i) {
        listEl.appendChild(buildRow(m, i === 0));
    });
}

function buildRow(m, isLatest) {
    const row = document.createElement('div');
    row.className = 'alc-mov' + (isLatest ? ' alc-mov--latest' : '');

    const color = catColor(m.categoria || '');
    const nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
    const meta = (ID_LABELS[m.id_type] || (m.id_type || '').toUpperCase()) + ' ' + (m.id_value || '') + ' · ' + timeAgo(new Date(m.created_at));

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

function timeAgo(date) {
    const now = new Date();
    const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24));
    if (diffDays === 0) return 'hoy';
    if (diffDays === 1) return 'ayer';
    if (diffDays < 7) return 'hace ' + diffDays + ' días';
    return date.toLocaleDateString('es-PE');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
