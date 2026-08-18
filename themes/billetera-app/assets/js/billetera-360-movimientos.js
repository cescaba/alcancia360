// Billetera 360 - Movimientos

const balanceValue = document.getElementById('balance-value');
const accumValue = document.getElementById('accum-value');
const movsListEl = document.getElementById('movs-list');

function init() {
    // Load user data
    loadUserData();
}

function loadUserData() {
    fetch(billetera.ajax_url + '?action=billetera_get_balance')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                updateBalance(res.data.balance, res.data.accumulated);
                renderMovements(res.data.movements);
            }
        });
}

function updateBalance(balance, accumulated) {
    balanceValue.textContent = 'S/ ' + balance.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
    accumValue.textContent = 'S/ ' + accumulated.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function renderMovements(movements) {
    movsListEl.innerHTML = '';
    movements.forEach(m => {
        const card = document.createElement('div');
        card.className = 'mov-card';

        const isBonus = m.bonus_multiplier > 1;
        const isPrepagados = m.categoria === 'Prepagados';

        const row = document.createElement('div');
        row.className = 'mov';
        row.innerHTML = `
            <div class="mov-badge" style="background: #146C4322; color: #146C43;">V</div>
            <div class="mov-body">
                <div class="mov-name">
                    ${escapeHtml(m.subcategoria || 'Venta')}
                    ${isBonus && isPrepagados ? '<span class="bonus-badge">2x</span>' : ''}
                </div>
                <div class="mov-meta">${escapeHtml((m.id_type ? m.id_type.toUpperCase() + ' ' : '') + (m.id_value || ''))} · ${escapeHtml(getTimeLabel(new Date(m.created_at)))}</div>
            </div>
            <div class="mov-amt">+S/ ${m.amount.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
        `;

        card.appendChild(row);
        movsListEl.appendChild(card);
    });
}

function getTimeLabel(date) {
    const now = new Date();
    const diffMs = now - date;
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays === 0) return 'hoy';
    if (diffDays === 1) return 'ayer';
    if (diffDays < 7) return 'hace ' + diffDays + ' días';
    return date.toLocaleDateString('es-PE');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
