// Mi alcancia 360 - Lógica del formulario "Registrar venta"

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

// Carrito de la venta actual: [{subId, nombre, cant, amount}]
let cart = [];
// Último preview de comisión del producto en edición (null si no hay)
let lastPreview = null;
// Token para descartar respuestas tardías de preview
let previewReq = 0;

let marcasData = {};
let categoriesData = {};
let subcategoriesData = {};

// Elements
const idSegButtons = document.querySelectorAll('#id-seg button');
const idLabel = document.getElementById('id-label');
const idInput = document.getElementById('id-input');
const fechaInput = document.getElementById('fecha-input');
const marcaSelect = document.getElementById('marca-select');
const catSelect = document.getElementById('cat-select');
const subSelect = document.getElementById('sub-select');
const cantidadWrap = document.getElementById('cantidad-wrap');
const cantidadInput = document.getElementById('cantidad-input');
const cantidadMinus = document.getElementById('cantidad-minus');
const cantidadPlus = document.getElementById('cantidad-plus');
const addItemBtn = document.getElementById('add-item-btn');
const cartWrap = document.getElementById('reg-cart');
const cartList = document.getElementById('cart-list');
const cartCount = document.getElementById('cart-count');
const cartTotal = document.getElementById('cart-total');
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
const burstZone = document.getElementById('burst-zone');

function init() {
    idInput.placeholder = ID_PLACEHOLDERS[state.idType];
    if (idLabel) idLabel.textContent = ID_LABELS[state.idType];

    idSegButtons.forEach(btn => btn.addEventListener('click', handleIdTypeChange));

    idInput.addEventListener('input', validate);
    marcaSelect.addEventListener('change', handleMarcaChange);
    catSelect.addEventListener('change', handleCategoryChange);
    subSelect.addEventListener('change', handleSubcategoryChange);
    cantidadInput.addEventListener('input', handleCantidadChange);
    if (cantidadMinus) cantidadMinus.addEventListener('click', function () { stepCantidad(-1); });
    if (cantidadPlus) cantidadPlus.addEventListener('click', function () { stepCantidad(1); });
    if (addItemBtn) addItemBtn.addEventListener('click', handleAddItem);
    submitBtn.addEventListener('click', handleSubmit);
    if (continueBtn) continueBtn.addEventListener('click', goToMovimientos);

    loadMarcas();
    loadUserData();
}

function loadMarcas() {
    fetch(billetera.ajax_url + '?action=billetera_get_lineas')
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                res.data.forEach(linea => {
                    marcasData[linea.codigo] = linea;
                    const opt = document.createElement('option');
                    opt.value = linea.codigo;
                    opt.textContent = linea.nombre;
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
    const newMarca = marcaSelect.value;
    // Cambiar de marca con productos agregados vacía la venta: confirmar.
    if (cart.length && newMarca !== state.marca) {
        const prevMarca = state.marca;
        openRegModal({
            title: '¿Cambiar de marca?',
            desc: 'Hay ' + cart.length + ' producto' + (cart.length > 1 ? 's' : '') + ' agregado' + (cart.length > 1 ? 's' : '') + ' a esta venta.',
            warn: 'Se quitarán de la venta si cambias de marca.',
            okLabel: 'Cambiar y vaciar',
            onOk: function () {
                cart = [];
                renderCart();
                applyMarcaChange(newMarca);
            },
            onCancel: function () {
                marcaSelect.value = prevMarca;
            }
        });
        return;
    }
    applyMarcaChange(newMarca);
}

function applyMarcaChange(marcaId) {
    state.marca = marcaId;
    const lineaCodigo = marcaId;
    catSelect.innerHTML = '';
    subSelect.innerHTML = '';
    subcategoriesData = {};
    categoriesData = {};
    lastPreview = null;
    previewReq++;

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
        body: 'action=billetera_get_categorias&linea_codigo=' + encodeURIComponent(lineaCodigo)
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
    lastPreview = null;

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
    lastPreview = null;
    loadComision();
    validate();
}

function stepCantidad(delta) {
    const current = Math.max(1, parseInt(cantidadInput.value) || 1);
    const next = Math.max(1, current + delta);
    if (next === current) return;
    cantidadInput.value = next;
    handleCantidadChange();
}

function loadComision() {
    const subId = subSelect.value;
    const req = ++previewReq;
    if (!subId) {
        previewAmt.textContent = 'S/ 0.00';
        if (regComision) regComision.classList.remove('is-ready');
        lastPreview = null;
        updateAddBtn();
        return;
    }

    const cantidad = Math.max(1, parseInt(cantidadInput.value) || 1);
    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_get_comision&nonce=' + encodeURIComponent(billetera.nonce_get_comision) + '&subcategoria_id=' + subId + '&cantidad=' + cantidad
    })
        .then(r => r.json())
        .then(res => {
            if (req !== previewReq) return;
            if (res.success) {
                previewAmt.textContent = res.data.amount_formatted;
                if (regComision) regComision.classList.add('is-ready');
                lastPreview = { amount: Number(res.data.amount) || 0, formatted: res.data.amount_formatted };
            } else {
                previewAmt.textContent = 'S/ 0.00';
                if (regComision) regComision.classList.remove('is-ready');
                lastPreview = null;
            }
            updateAddBtn();
        });
}

function updateAddBtn() {
    if (!addItemBtn) return;
    const subId = subSelect.value;
    const idOk = idInput.value.trim().length > 2;
    const canAdd = subId && idOk && lastPreview !== null;
    addItemBtn.disabled = !canAdd;
    if (canAdd) {
        addItemBtn.textContent = 'Agregar · ' + lastPreview.formatted;
    } else {
        addItemBtn.textContent = 'Agregar a la venta';
    }
}

function handleAddItem() {
    const subId = subSelect.value;
    if (!subId || !lastPreview) return;
    if (idInput.value.trim().length <= 2) return;

    const catNombre = (categoriesData[state.category] && categoriesData[state.category].nombre) || '';
    const subNombre = (subcategoriesData[subId] && subcategoriesData[subId].nombre) || '';
    const cant = Math.max(1, parseInt(cantidadInput.value) || 1);
    const porUnidad = !!(subcategoriesData[subId] && Number(subcategoriesData[subId].por_unidad) === 1);
    const unitAmount = lastPreview.amount / cant;

    // Mismo producto "por unidad": en vez de duplicar la fila, suma cantidad.
    const existing = porUnidad
        ? cart.find(function (it) { return String(it.subId) === String(subId) && it.porUnidad; })
        : null;

    if (existing) {
        existing.cant += cant;
        existing.amount = existing.unitAmount * existing.cant;
    } else {
        cart.push({
            subId: subId,
            nombre: (catNombre ? catNombre + ' · ' : '') + subNombre,
            cant: cant,
            unitAmount: unitAmount,
            amount: lastPreview.amount,
            porUnidad: porUnidad
        });
    }

    renderCart();

    // Deja la cascada lista para el siguiente producto (misma marca).
    catSelect.value = '';
    handleCategoryChange();
    cantidadWrap.style.display = 'none';
    cantidadInput.value = 1;
    previewAmt.textContent = 'S/ 0.00';
    if (regComision) regComision.classList.remove('is-ready');
    lastPreview = null;
    updateAddBtn();
    validate();
}

function removeCartItem(idx) {
    cart.splice(idx, 1);
    renderCart();
    validate();
}

function changeCartQty(idx, delta) {
    const item = cart[idx];
    if (!item || !item.porUnidad) return;
    const next = Math.max(1, item.cant + delta);
    if (next === item.cant) return;
    item.cant = next;
    item.amount = item.unitAmount * next;
    renderCart();
    validate();
}

function buildQtyStepper(idx, item) {
    const wrap = document.createElement('div');
    wrap.className = 'reg-cart__qty';

    const minus = document.createElement('button');
    minus.type = 'button';
    minus.textContent = '−';
    minus.disabled = item.cant <= 1;
    minus.setAttribute('aria-label', 'Quitar una unidad');
    minus.addEventListener('click', function () { changeCartQty(idx, -1); });

    const num = document.createElement('span');
    num.textContent = String(item.cant);

    const plus = document.createElement('button');
    plus.type = 'button';
    plus.textContent = '+';
    plus.setAttribute('aria-label', 'Agregar una unidad');
    plus.addEventListener('click', function () { changeCartQty(idx, 1); });

    wrap.appendChild(minus);
    wrap.appendChild(num);
    wrap.appendChild(plus);
    return wrap;
}

function renderCart() {
    if (!cartWrap || !cartList) return;
    cartWrap.hidden = cart.length === 0;
    cartList.innerHTML = '';

    let total = 0;
    cart.forEach(function (item, idx) {
        total += item.amount;
        const row = document.createElement('div');
        row.className = 'reg-cart__row';

        const info = document.createElement('div');
        info.className = 'reg-cart__info';
        const name = document.createElement('div');
        name.className = 'reg-cart__name';
        name.textContent = item.nombre;
        const meta = document.createElement('div');
        meta.className = 'reg-cart__meta';
        if (item.porUnidad) {
            meta.appendChild(buildQtyStepper(idx, item));
        } else {
            meta.textContent = 'Cantidad 1';
        }
        info.appendChild(name);
        info.appendChild(meta);

        const amt = document.createElement('div');
        amt.className = 'reg-cart__amt';
        amt.textContent = 'S/ ' + Number(item.amount).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        const rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'reg-cart__rm';
        rm.title = 'Quitar de la venta';
        rm.innerHTML = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="5" y1="5" x2="19" y2="19"/><line x1="19" y1="5" x2="5" y2="19"/></svg>';
        rm.addEventListener('click', function () { removeCartItem(idx); });

        row.appendChild(info);
        row.appendChild(amt);
        row.appendChild(rm);
        cartList.appendChild(row);
    });

    if (cartCount) cartCount.textContent = String(cart.length);
    if (cartTotal) cartTotal.textContent = 'S/ ' + total.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    updateSubmitLabel();
}

function updateSubmitLabel() {
    if (!submitBtn) return;
    submitBtn.textContent = cart.length > 0 ? ('Registrar venta (' + cart.length + ')') : 'Registrar venta';
}

function validate() {
    const idOk = idInput.value.trim().length > 2;

    // Submit: requiere carrito con al menos 1 producto + identificador.
    const isValid = cart.length > 0 && idOk;
    if (isValid) {
        submitBtn.classList.add('is-ready');
        submitBtn.disabled = false;
    } else {
        submitBtn.classList.remove('is-ready');
        submitBtn.disabled = true;
    }

    updateAddBtn();
}

function handleSubmit() {
    if (!submitBtn.classList.contains('is-ready')) return;
    if (!cart.length) return;

    const idType = state.idType;
    const idVal = idInput.value.trim();
    const fecha = fechaInput ? fechaInput.value : new Date().toISOString().split('T')[0];
    const items = cart.map(function (it) {
        return { subcategoria_id: parseInt(it.subId, 10), cantidad: parseInt(it.cant, 10) || 1 };
    });

    submitBtn.disabled = true;

    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_register_sale_multi&nonce=' + encodeURIComponent(billetera.nonce_register_sale) + '&id_type=' + idType + '&id_value=' + encodeURIComponent(idVal) + '&fecha=' + fecha + '&items=' + encodeURIComponent(JSON.stringify(items))
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const amount = data.data.amount || 0;
                const bonus = Number(data.data.bonus_multiplier) || 1;
                const bonusCat = data.data.bonus_categoria || '';
                const bonusAplicado = Number(data.data.bonus_aplicado) || 0;

                gainAmount.textContent = '+S/ ' + amount.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                const gainBonus = document.getElementById('gain-bonus');
                if (gainBonus) {
                    if (bonus > 1) {
                        gainBonus.textContent = 'x' + (Math.round(bonus * 100) / 100) + (bonusCat ? ' ¡Bono ' + bonusCat + '!' : ' ¡Bono!');
                        gainBonus.hidden = false;
                    } else if (bonusAplicado && bonusCat) {
                        gainBonus.textContent = '¡Bono ' + bonusCat + '!';
                        gainBonus.hidden = false;
                    } else {
                        gainBonus.textContent = '';
                        gainBonus.hidden = true;
                    }
                }
                overlay.classList.add('show');

                spawnCoins(burstZone);

                resetForm();

                setTimeout(() => {
                    loadUserData();
                }, 1500);
            } else {
                submitBtn.disabled = false;
                openRegModal({
                    title: 'No se pudo registrar',
                    desc: (data.data && data.data.message) || 'Intenta de nuevo.',
                    okLabel: 'Entendido',
                    single: true
                });
            }
        })
        .catch(() => {
            submitBtn.disabled = false;
            openRegModal({
                title: 'Error de conexión',
                desc: 'No se pudo registrar la venta. Intenta de nuevo.',
                okLabel: 'Entendido',
                single: true
            });
        });
}

function closeOverlay() {
    overlay.classList.remove('show');
}

function spawnCoins(zone) {
    if (!zone) return;
    for (let i = 0; i < 18; i++) {
        const coin = document.createElement('div');
        coin.className = 'coin';
        const angle = Math.random() * Math.PI * 2;
        const dist = 55 + Math.random() * 85;
        const dx = Math.cos(angle) * dist;
        const dy = Math.sin(angle) * dist;
        const rot = (Math.random() * 720 - 360).toFixed(0);
        const delay = Math.random() * 0.15;
        coin.style.animation = 'celebrateCoinburst .9s cubic-bezier(.15,.7,.3,1) ' + delay + 's forwards';
        coin.style.setProperty('--dx', dx + 'px');
        coin.style.setProperty('--dy', dy + 'px');
        coin.style.setProperty('--rot', rot + 'deg');
        zone.appendChild(coin);
        setTimeout(() => coin.remove(), 1300);
    }
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
    state.marca = '';
    state.category = '';
    state.subcategory = '';
    cart = [];
    lastPreview = null;
    previewReq++;
    renderCart();
    validate();
}

// Modal de confirmación/aviso con el tema de la app (mismo estilo que el de eliminar venta).
function openRegModal(opts) {
    closeRegModal();
    const ov = document.createElement('div');
    ov.className = 'alc-modal-ov';
    ov.id = 'reg-modal';
    const warn = opts.warn ? '<div class="alc-modal__warn">' + opts.warn + '</div>' : '';
    const actions = opts.single
        ? '<button type="button" class="alc-modal__btn alc-modal__btn--danger" data-act="ok">' + (opts.okLabel || 'Entendido') + '</button>'
        : '<button type="button" class="alc-modal__btn alc-modal__btn--ghost" data-act="cancel">Volver</button>' +
          '<button type="button" class="alc-modal__btn alc-modal__btn--danger" data-act="ok">' + (opts.okLabel || 'Confirmar') + '</button>';
    ov.innerHTML =
        '<div class="alc-modal" role="dialog" aria-modal="true">' +
            '<div class="alc-modal__title">' + (opts.title || '') + '</div>' +
            '<div class="alc-modal__desc">' + (opts.desc || '') + '</div>' +
            warn +
            '<div class="alc-modal__actions">' + actions + '</div>' +
        '</div>';
    document.body.appendChild(ov);
    ov.querySelector('[data-act="ok"]').addEventListener('click', function () {
        closeRegModal();
        if (opts.onOk) opts.onOk();
    });
    const cancel = ov.querySelector('[data-act="cancel"]');
    if (cancel) {
        cancel.addEventListener('click', function () {
            closeRegModal();
            if (opts.onCancel) opts.onCancel();
        });
    }
    ov.addEventListener('click', function (e) {
        if (e.target === ov) {
            closeRegModal();
            if (opts.onCancel) opts.onCancel();
        }
    });
}

function closeRegModal() {
    const ov = document.getElementById('reg-modal');
    if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
}

function loadUserData() {
    fetch(billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_get_balance&nonce=' + encodeURIComponent(billetera.nonce_get_balance)
    })
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
