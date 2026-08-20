// Billetera 360 - Lógica del formulario "Registrar venta"

const ID_PLACEHOLDERS = {
    placa: "ABC-123",
    vin: "KMHXX00XXXX000000",
    ot: "OT-004521",
    factura: "F001-000123"
};

const ID_LABELS = {
    placa: "Placa",
    vin: "VIN",
    ot: "OT",
    factura: "N° Factura"
};

let state = {
    idType: 'factura',
    marca: '',
    category: '',
    subcategory: '',
    balance: 0,
    accumulated: 0,
    meta: 0,
    fillPercent: 0
};

let marcasData = {};
let categoriesData = {};
let subcategoriesData = {};

// Elements
const idSegButtons = document.querySelectorAll('#id-seg button');
const idLabel = document.getElementById('id-label');
const idInput = document.getElementById('id-input');
const marcaSelect = document.getElementById('marca-select');
const catSelect = document.getElementById('cat-select');
const subSelect = document.getElementById('sub-select');
const cantidadWrap = document.getElementById('cantidad-wrap');
const cantidadInput = document.getElementById('cantidad-input');
const previewAmt = document.getElementById('preview-amt');
const regComision = document.getElementById('reg-comision');
const submitBtn = document.getElementById('submit-btn');
const overlay = document.getElementById('overlay');
const gainAmount = document.getElementById('gain-amount');
const continueBtn = document.getElementById('continue-btn');
const alcanciaBalance = document.getElementById('alcancia-balance');
const alcanciaBarFill = document.getElementById('alcancia-bar-fill');
const alcanciaMissing = document.getElementById('alcancia-missing');
const alcanciaPigFill = document.getElementById('alcancia-pig-fill');
const celebratePigFill = document.getElementById('celebrate-pig-fill');

function init() {
    idInput.placeholder = ID_PLACEHOLDERS[state.idType];
    if (idLabel) idLabel.textContent = ID_LABELS[state.idType];

    idSegButtons.forEach(btn => btn.addEventListener('click', handleIdTypeChange));

    idInput.addEventListener('input', validate);
    marcaSelect.addEventListener('change', handleMarcaChange);
    catSelect.addEventListener('change', handleCategoryChange);
    subSelect.addEventListener('change', handleSubcategoryChange);
    cantidadInput.addEventListener('input', handleCantidadChange);
    submitBtn.addEventListener('click', handleSubmit);
    if (continueBtn) continueBtn.addEventListener('click', goToMovimientos);

    loadMarcas();
    loadUserData();
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
    idSegButtons.forEach(btn => btn.classList.remove('is-active'));
    const btn = e.target.closest('button');
    btn.classList.add('is-active');
    state.idType = btn.dataset.type;
    idInput.placeholder = ID_PLACEHOLDERS[state.idType];
    if (idLabel) idLabel.textContent = ID_LABELS[state.idType];
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
        if (regComision) regComision.classList.remove('is-ready');
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
                if (regComision) regComision.classList.add('is-ready');
            } else {
                previewAmt.textContent = 'S/ 0.00';
                if (regComision) regComision.classList.remove('is-ready');
            }
        });
}

function validate() {
    const sub = subSelect.value;
    const idOk = idInput.value.trim().length > 2;

    const isValid = sub && idOk;

    if (isValid) {
        submitBtn.classList.add('is-ready');
        submitBtn.disabled = false;
    } else {
        submitBtn.classList.remove('is-ready');
        submitBtn.disabled = true;
    }
}

function handleSubmit() {
    if (!submitBtn.classList.contains('is-ready')) return;

    const subId = subSelect.value;
    const cantidad = parseInt(cantidadInput.value) || 1;
    const idType = state.idType;
    const idVal = idInput.value.trim();

    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_register_sale_v2&subcategoria_id=' + subId + '&cantidad=' + cantidad + '&id_type=' + idType + '&id_value=' + encodeURIComponent(idVal)
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const amount = data.data.amount || 0;

                gainAmount.textContent = '+S/ ' + amount.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                overlay.classList.add('show');

                resetForm();

                setTimeout(() => {
                    loadUserData();
                }, 1500);

                setTimeout(() => {
                    closeOverlay();
                }, 4000);
            }
        });
}

function closeOverlay() {
    overlay.classList.remove('show');
}

function goToMovimientos() {
    if (overlay) overlay.classList.remove('show');
    if (window.billetera && window.billetera.movimientos_url) {
        window.location.href = window.billetera.movimientos_url;
        return;
    }
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
    previewAmt.textContent = 'S/ 0.00';
    if (regComision) regComision.classList.remove('is-ready');
    validate();
}

function loadUserData() {
    fetch(billetera.ajax_url + '?action=billetera_get_balance')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                state.balance = res.data.balance;
                state.accumulated = res.data.accumulated;
                state.meta = res.data.meta || 0;
                state.fillPercent = res.data.fill_percent || 0;
                updateAlcancia();
            }
        });
}

function updateAlcancia() {
    const meta = state.meta || 0;
    const balance = state.balance || 0;
    const fill = state.fillPercent || 0;

    const bal = 'S/ ' + balance.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const faltante = Math.max(0, meta - balance);
    const fal = 'S/ ' + faltante.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (alcanciaBalance) alcanciaBalance.textContent = bal;
    if (alcanciaMissing) alcanciaMissing.textContent = fal;
    if (alcanciaBarFill) alcanciaBarFill.style.width = fill + '%';

    const clip = 'inset(' + (100 - fill) + '% 0 0 0)';
    if (alcanciaPigFill) alcanciaPigFill.style.clipPath = clip;
    if (celebratePigFill) celebratePigFill.style.clipPath = clip;
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
