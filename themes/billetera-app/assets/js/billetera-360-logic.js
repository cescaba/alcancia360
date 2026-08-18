// Billetera 360 - Lógica completa con AJAX

const ID_PLACEHOLDERS = {
    placa: "ABC-123",
    vin: "KMHXX00XXXX000000",
    ot: "OT-004521",
    factura: "F001-000123"
};

// State
let state = {
    screen: 'registro',
    idType: 'placa',
    idValue: '',
    marca: '',
    category: '',
    subcategory: '',
    balance: 0,
    accumulated: 0,
    movements: []
};

let marcasData = {};
let categoriesData = {};
let subcategoriesData = {};

// Elements
const idSegButtons = document.querySelectorAll('#id-seg button');
const idInput = document.getElementById('id-input');
const marcaSelect = document.getElementById('marca-select');
const catSelect = document.getElementById('cat-select');
const subSelect = document.getElementById('sub-select');
const cantidadWrap = document.getElementById('cantidad-wrap');
const cantidadInput = document.getElementById('cantidad-input');
const previewAmt = document.getElementById('preview-amt');
const submitBtn = document.getElementById('submit-btn');
const navRegistro = document.getElementById('nav-registro');
const navWallet = document.getElementById('nav-wallet');
const screenRegistro = document.getElementById('screen-registro');
const screenWallet = document.getElementById('screen-wallet');
const overlay = document.getElementById('overlay');
const gainAmount = document.getElementById('gain-amount');
const continueBtn = document.getElementById('continue-btn');
const balancePreview = document.getElementById('balance-preview');
const balanceValue = document.getElementById('balance-value');
const accumValue = document.getElementById('accum-value');
const movsListEl = document.getElementById('movs-list');

// Initialize
function init() {
    // Mark first ID button as active
    idSegButtons[0].classList.add('active');
    idInput.placeholder = ID_PLACEHOLDERS[state.idType];

    // Event listeners
    idSegButtons.forEach(btn => {
        btn.addEventListener('click', handleIdTypeChange);
    });

    idInput.addEventListener('input', validate);
    marcaSelect.addEventListener('change', handleMarcaChange);
    catSelect.addEventListener('change', handleCategoryChange);
    subSelect.addEventListener('change', handleSubcategoryChange);
    cantidadInput.addEventListener('input', handleCantidadChange);
    submitBtn.addEventListener('click', handleSubmit);
    navRegistro.addEventListener('click', () => switchScreen('registro'));
    continueBtn.addEventListener('click', goToMovimientos);

    // Mobile tabs
    const mobileTabRegistro = document.getElementById('mobile-tab-registro');
    if (mobileTabRegistro) mobileTabRegistro.addEventListener('click', () => switchScreen('registro'));

    // Load marcas
    loadMarcas();

    // Load user data
    loadUserData();

    // User menu
    document.getElementById('user-toggle').addEventListener('click', () => {
        document.getElementById('user-dropdown').classList.toggle('active');
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.user-menu')) {
            document.getElementById('user-dropdown').classList.remove('active');
        }
    });
}

function loadMarcas() {
    fetch(billetera.ajax_url + '?action=billetera_get_marcas')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                res.data.forEach(marca => {
                    marcasData[marca.id] = marca;
                    const opt = document.createElement('option');
                    opt.value = marca.id;
                    opt.textContent = marca.nombre;
                    marcaSelect.appendChild(opt);
                });
            }
        });
}

function handleIdTypeChange(e) {
    idSegButtons.forEach(btn => btn.classList.remove('active'));
    e.target.closest('button').classList.add('active');
    state.idType = e.target.closest('button').dataset.type;
    idInput.placeholder = ID_PLACEHOLDERS[state.idType];
    idInput.value = '';
    validate();
}

function handleMarcaChange() {
    const marcaId = marcaSelect.value;
    state.marca = marcaId;
    catSelect.innerHTML = '';
    subSelect.innerHTML = '';
    subcategoriesData = {};
    categoriesData = {};

    if (!marcaId) {
        catSelect.disabled = true;
        const opt = document.createElement('option');
        opt.textContent = 'Primero elige una marca';
        catSelect.appendChild(opt);
        subSelect.disabled = true;
        const opt2 = document.createElement('option');
        opt2.textContent = 'Primero elige una marca';
        subSelect.appendChild(opt2);
        validate();
        return;
    }

    catSelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Selecciona una categoría';
    catSelect.appendChild(placeholder);

    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_get_categorias&marca_id=' + marcaId
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                res.data.forEach(cat => {
                    categoriesData[cat.id] = cat;
                    const opt = document.createElement('option');
                    opt.value = cat.id;
                    opt.textContent = cat.nombre;
                    catSelect.appendChild(opt);
                });
            }
            validate();
        });
}

function handleCategoryChange() {
    const catId = catSelect.value;
    state.category = catId;
    subSelect.innerHTML = '';

    if (!catId) {
        subSelect.disabled = true;
        const option = document.createElement('option');
        option.textContent = 'Primero elige una categoría';
        subSelect.appendChild(option);
        validate();
        return;
    }

    subSelect.disabled = false;
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Selecciona una subcategoría';
    subSelect.appendChild(placeholder);

    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_get_subcategorias&categoria_id=' + catId
    })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                res.data.forEach(sub => {
                    subcategoriesData[sub.id] = sub;
                    const opt = document.createElement('option');
                    opt.value = sub.id;
                    opt.textContent = sub.nombre;
                    subSelect.appendChild(opt);
                });
            }
            validate();
        });
}

function handleSubcategoryChange() {
    const subId = subSelect.value;
    state.subcategory = subId;

    if (subId && subcategoriesData[subId] && Number(subcategoriesData[subId].por_unidad) === 1) {
        cantidadWrap.style.display = 'block';
        cantidadInput.value = 1;
    } else {
        cantidadWrap.style.display = 'none';
        cantidadInput.value = 1;
    }

    loadComision();
    validate();
}

function handleCantidadChange() {
    loadComision();
    validate();
}

function loadComision() {
    const subId = subSelect.value;
    if (!subId) {
        previewAmt.textContent = 'S/ 0.00';
        previewAmt.classList.remove('ready');
        return;
    }

    const cantidad = parseInt(cantidadInput.value) || 1;
    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_get_comision&subcategoria_id=' + subId + '&cantidad=' + cantidad
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            previewAmt.textContent = res.data.amount_formatted;
            previewAmt.classList.add('ready');
        } else {
            previewAmt.textContent = 'S/ 0.00';
            previewAmt.classList.remove('ready');
        }
    });
}

function validate() {
    const sub = subSelect.value;
    const idOk = idInput.value.trim().length > 2;

    let isValid = sub && idOk;

    // Solo resetear la comisión si NO hay subcategoría
    if (!sub) {
        previewAmt.textContent = 'S/ 0.00';
        previewAmt.classList.remove('ready');
    }

    if (isValid) {
        submitBtn.classList.add('ready');
    } else {
        submitBtn.classList.remove('ready');
    }
}

function handleSubmit() {
    if (!submitBtn.classList.contains('ready')) return;

    const subId = subSelect.value;
    const cantidad = parseInt(cantidadInput.value) || 1;
    const idType = state.idType;
    const idVal = idInput.value.trim();

    // Register via AJAX
    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_register_sale_v2&subcategoria_id=' + subId + '&cantidad=' + cantidad + '&id_type=' + idType + '&id_value=' + encodeURIComponent(idVal)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const amount = data.data.amount || 0;

            // Show celebration
            gainAmount.textContent = '+S/ ' + amount.toFixed(2);
            overlay.classList.add('show');
            spawnCoins(document.getElementById('burst-zone'));

            // Reset form
            resetForm();

            // Reload data after celebration
            setTimeout(() => {
                loadUserData();
            }, 1500);

            // Auto-close overlay after 4 seconds
            setTimeout(() => {
                closeOverlay();
            }, 4000);
        }
    });
}

function spawnCoins(zone) {
    for (let i = 0; i < 18; i++) {
        const coin = document.createElement('div');
        coin.className = 'coin';
        const angle = Math.random() * Math.PI * 2;
        const dist = 60 + Math.random() * 90;
        const dx = Math.cos(angle) * dist;
        const dy = Math.sin(angle) * dist - 40;
        const rot = (Math.random() * 720 - 360).toFixed(0);
        const delay = Math.random() * 0.15;
        coin.style.animation = `coinburst 0.9s cubic-bezier(0.15, 0.7, 0.3, 1) ${delay}s forwards`;
        coin.style.setProperty('--dx', dx + 'px');
        coin.style.setProperty('--dy', dy + 'px');
        coin.style.setProperty('--rot', rot + 'deg');
        zone.appendChild(coin);
        setTimeout(() => coin.remove(), 1300);
    }
}

function closeOverlay() {
    overlay.classList.remove('show');
    switchScreen('registro');
}

function goToMovimientos() {
    overlay.classList.remove('show');
    const movLink = document.querySelector('a[href*="movimientos"]');
    if (movLink) {
        window.location.href = movLink.href;
    }
}

function resetForm() {
    idInput.value = '';
    marcaSelect.value = '';
    catSelect.innerHTML = '<option value="">Selecciona una categoría</option>';
    catSelect.disabled = true;
    subSelect.innerHTML = '<option value="">Primero elige una categoría</option>';
    subSelect.disabled = true;
    cantidadWrap.style.display = 'none';
    cantidadInput.value = 1;
    validate();
}

function switchScreen(name) {
    state.screen = name;
    screenRegistro.classList.toggle('active', name === 'registro');
    screenWallet.classList.toggle('active', name === 'wallet');
    navRegistro.classList.toggle('active', name === 'registro');
    navWallet.classList.toggle('active', name === 'wallet');

    // Update mobile tabs
    const mobileTabRegistro = document.getElementById('mobile-tab-registro');
    const mobileTabWallet = document.getElementById('mobile-tab-wallet');
    if (mobileTabRegistro) mobileTabRegistro.classList.toggle('active', name === 'registro');
    if (mobileTabWallet) mobileTabWallet.classList.toggle('active', name === 'wallet');

    overlay.classList.remove('show');

    if (name === 'wallet') {
        loadUserData();
    }
}

function loadUserData() {
    fetch(billetera.ajax_url + '?action=billetera_get_balance')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                state.balance = res.data.balance;
                state.accumulated = res.data.accumulated;
                state.movements = res.data.movements.map(m => ({
                    name: (m.id_type ? m.id_type.toUpperCase() + ' ' : '') + (m.id_value || ''),
                    meta: getTimeLabel(new Date(m.created_at)),
                    amount: m.amount,
                    icon: 'V',
                    color: '#146C43'
                }));
                updateBalance();
                renderMovements();
            }
        });
}

function updateBalance() {
    balancePreview.textContent = 'S/ ' + state.balance.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
    balanceValue.textContent = 'S/ ' + state.balance.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
    accumValue.textContent = 'S/ ' + state.accumulated.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function renderMovements() {
    movsListEl.innerHTML = '';
    state.movements.forEach(m => {
        const row = document.createElement('div');
        row.className = 'movement-row';
        row.innerHTML = `
            <div class="movement-badge" style="background: ${m.color}22; color: ${m.color};">${m.icon}</div>
            <div class="movement-info">
                <div class="movement-name">${escapeHtml(m.name)}</div>
                <div class="movement-meta">${escapeHtml(m.meta)}</div>
            </div>
            <div class="movement-amount">+S/ ${m.amount.toLocaleString('es-PE', {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
        `;
        movsListEl.appendChild(row);
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
