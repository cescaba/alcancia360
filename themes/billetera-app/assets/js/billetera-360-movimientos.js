// Billetera 360 - "Mi alcancía" (movimientos)

const el = {
    pigFill: document.getElementById('alc-pig-fill'),
    pigBtn: document.getElementById('alc-pig-btn'),
    pigHint: document.getElementById('alc-pig-hint'),
    saldoMes: document.getElementById('saldo-mes'),
    avanceMeta: document.getElementById('avance-meta'),
    barFill: document.getElementById('bar-fill'),
    faltan: document.getElementById('faltan'),
    metaLabel: document.getElementById('meta-label'),
    acumuladoAno: document.getElementById('acumulado-ano'),
    ventasMes: document.getElementById('ventas-mes'),
    ranking: document.getElementById('ranking'),
    movsList: document.getElementById('movs-list'),
    deskPigFill: document.getElementById('desk-pig-fill'),
    deskPigBtn: document.getElementById('alc-desk-pig-btn'),
    deskPigHint: document.getElementById('alc-desk-pig-hint'),
    deskSaldo: document.getElementById('desk-saldo'),
    deskBarFill: document.getElementById('desk-bar-fill'),
    deskFaltan: document.getElementById('desk-faltan'),
    deskMetaLabel: document.getElementById('desk-meta-label'),
    deskAcumuladoAno: document.getElementById('desk-acumulado-ano'),
    deskVentasMes: document.getElementById('desk-ventas-mes'),
    deskRanking: document.getElementById('desk-ranking'),
    deskPromedio: document.getElementById('desk-promedio'),
    deskMovsList: document.getElementById('desk-movs-list')
};

const ID_LABELS = {
    placa: 'Placa',
    vin: 'VIN',
    ot: 'OT',
    factura: 'N° Factura'
};

const CAT_COLORS = ['#5B5FA8', '#C98A1E', '#0A6CB4', '#B05C34', '#B04E7C', '#1B6E46', '#7A5C16'];
const STOP_WORDS = ['de', 'del', 'y', 'la', 'el', 'los', 'las', 'liqui', 'moly'];

const PIG_PHRASES = [
    function () { return 'Toca la alcancía'; },
    function (ctx) { return ctx.pct + '% de tu meta mensual'; },
    function (ctx) { return ctx.rank > 0 ? ('Eres el número ' + ctx.rank + ' del taller') : 'Eres una pieza clave del taller'; },
    function (ctx) { return ctx.racha > 0 ? ('Llevas ' + ctx.racha + ' días de racha') : '¡Empieza tu racha hoy!'; }
];

let fillPct = 0;
let rank = 0;
let racha = 0;
let mobileHintIdx = 0;
let deskHintIdx = 1;

function pigPhrase(idx, ctx) {
    return PIG_PHRASES[idx % PIG_PHRASES.length](ctx);
}

function renderPigHints() {
    const ctx = { pct: fillPct, rank: rank, racha: racha };
    if (el.pigHint) el.pigHint.textContent = pigPhrase(mobileHintIdx, ctx);
    if (el.deskPigHint) el.deskPigHint.textContent = pigPhrase(deskHintIdx, ctx);
}

function init() {
    attachPigTap(el.pigBtn, '.alc-pig__coin', function () {
        mobileHintIdx = (mobileHintIdx + 1) % PIG_PHRASES.length;
        renderPigHints();
    });
    attachPigTap(el.deskPigBtn, '.alc-desk__pig-coin', function () {
        deskHintIdx = (deskHintIdx + 1) % PIG_PHRASES.length;
        renderPigHints();
    });
    loadUserData();
}

function attachPigTap(btn, coinSelector, onTap) {
    if (!btn) return;
    btn.addEventListener('click', function () {
        playCoinSound();
        this.style.animation = 'none';
        void this.offsetWidth;
        this.style.animation = '';
        const coin = this.querySelector(coinSelector);
        if (coin) {
            coin.style.animation = 'none';
            void coin.offsetWidth;
            coin.style.animation = '';
        }
        if (onTap) onTap();
    });
}

let _audioCtx = null;
function playCoinSound() {
    try {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        if (!_audioCtx) _audioCtx = new AC();
        if (_audioCtx.state === 'suspended') _audioCtx.resume();
        const ctx = _audioCtx;
        [740, 1040].forEach(function (freq, i) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'triangle';
            osc.frequency.value = freq;
            const t = ctx.currentTime + i * 0.08;
            gain.gain.setValueAtTime(0.0001, t);
            gain.gain.exponentialRampToValueAtTime(0.09, t + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.28);
            osc.connect(gain).connect(ctx.destination);
            osc.start(t);
            osc.stop(t + 0.3);
        });
    } catch (e) {}
}

function loadUserData() {
    fetch(billetera.ajax_url + '?action=billetera_get_balance')
        .then(r => r.json())
        .then(res => {
            if (!res.success) return;
            updateStats(res.data);
            renderMovements(res.data.movements || []);
        });
}

function updateStats(d) {
    const balance = d.balance || 0;
    const meta = d.meta || 0;
    const fill = d.fill_percent || 0;

    setText(el.saldoMes, fmt(balance));
    setText(el.avanceMeta, Math.round(fill) + '%');
    if (el.barFill) el.barFill.style.width = fill + '%';
    setText(el.faltan, fmt(Math.max(0, meta - balance)));
    setText(el.metaLabel, 'Meta ' + fmt(meta));
    setText(el.acumuladoAno, fmt(d.acumulado_ano || 0));
    setText(el.ventasMes, String(d.ventas_mes || 0));

    rank = (d.ranking && d.ranking.rank) ? d.ranking.rank : 0;
    racha = Number(d.racha) || 0;
    const total = (d.ranking && d.ranking.total) ? d.ranking.total : 0;
    setText(el.ranking, rank > 0 ? '#' + rank + ' / ' + total : '—');

    const clip = 'inset(' + (100 - fill) + '% 0 0 0)';
    if (el.pigFill) el.pigFill.style.clipPath = clip;

    // Desktop
    setText(el.deskSaldo, fmt(balance));
    if (el.deskBarFill) el.deskBarFill.style.width = fill + '%';
    setText(el.deskFaltan, fmt(Math.max(0, meta - balance)));
    setText(el.deskMetaLabel, 'Meta ' + fmt(meta));
    setText(el.deskAcumuladoAno, fmt(d.acumulado_ano || 0));
    setText(el.deskVentasMes, String(d.ventas_mes || 0));
    setText(el.deskRanking, rank > 0 ? '#' + rank + ' / ' + total : '—');
    const ventasNum = Number(d.ventas_mes) || 0;
    setText(el.deskPromedio, fmt(ventasNum > 0 ? balance / ventasNum : 0));
    if (el.deskPigFill) el.deskPigFill.style.clipPath = clip;

    fillPct = Math.round(fill);
    renderPigHints();
}

function renderMovements(movements) {
    if (el.movsList) {
        el.movsList.innerHTML = '';

        if (!movements.length) {
            el.movsList.innerHTML = '<p class="alc-empty">No hay movimientos registrados aún.</p>';
        } else {
            movements.forEach(function (m, i) {
                el.movsList.appendChild(buildRow(m, i === 0));
            });
        }
    }

    if (el.deskMovsList) {
        el.deskMovsList.innerHTML = '';

        if (!movements.length) {
            el.deskMovsList.innerHTML = '<div class="alc-desk__empty">No hay movimientos registrados aún.</div>';
        } else {
            movements.forEach(function (m) {
                el.deskMovsList.appendChild(buildDeskRow(m));
            });
        }
    }
}

function buildRow(m, isLatest) {
    const row = document.createElement('div');
    row.className = 'alc-mov' + (isLatest ? ' alc-mov--latest' : '');

    const color = catColor(m.categoria || '');
    const nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
    const ident = m.asesor_nombre ? m.asesor_nombre : ((ID_LABELS[m.id_type] || (m.id_type || '').toUpperCase()) + ' ' + (m.id_value || ''));
    const meta = ident + ' · ' + timeAgo(new Date(m.created_at));

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
    row.className = 'alc-desk__tr';

    const color = catColor(m.categoria || '');
    const nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
    const fecha = timeAgo(new Date(m.created_at));

    const badge = document.createElement('div');
    badge.className = 'alc-desk__td';
    badge.innerHTML = '<span class="alc-desk__badge" style="background:' + hexA(color, 0.1) + ';border-color:' + hexA(color, 0.333) + '">' + catInitials(m.categoria || '') + '</span>';

    const prod = document.createElement('div');
    prod.className = 'alc-desk__td alc-desk__td--prod';
    prod.textContent = nombre;

    const id = document.createElement('div');
    id.className = 'alc-desk__td alc-desk__td--id' + (m.asesor_nombre ? ' alc-desk__td--asesor' : '');
    id.textContent = m.asesor_nombre || (m.id_value || '');

    const fechaEl = document.createElement('div');
    fechaEl.className = 'alc-desk__td alc-desk__td--fecha';
    fechaEl.textContent = fecha;

    const amt = document.createElement('div');
    amt.className = 'alc-desk__td alc-desk__td--amt';
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

function timeAgo(date) {
    const now = new Date();
    const diffDays = Math.floor((now - date) / (1000 * 60 * 60 * 24));
    if (diffDays === 0) return 'hoy';
    if (diffDays === 1) return 'ayer';
    if (diffDays < 7) return 'hace ' + diffDays + ' días';
    return date.toLocaleDateString('es-PE');
}

function setText(node, text) {
    if (node) node.textContent = text;
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
