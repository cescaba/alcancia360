// Mi alcancia 360 - Lógica del formulario desktop "Registrar venta"

(function () {
    var ID_PLACEHOLDERS = { placa: 'ABC-123', vin: 'KMHXX00XXXX000000', ot: 'OT-004521', factura: 'F001-000123' };
    var ID_LABELS = { placa: 'Placa', vin: 'VIN', ot: 'OT', factura: 'N° Factura' };

    var seg = document.getElementById('desk-id-seg');
    if (!seg) return;

    var idLabel = document.getElementById('desk-id-label');
    var idInput = document.getElementById('desk-id-input');
    var fechaInput = document.getElementById('desk-fecha-input');
    var marcaSelect = document.getElementById('desk-marca-select');
    var catSelect = document.getElementById('desk-cat-select');
    var subSelect = document.getElementById('desk-sub-select');
    var cantidadWrap = document.getElementById('desk-cantidad-wrap');
    var cantidadInput = document.getElementById('desk-cantidad-input');
    var cantidadMinus = document.getElementById('desk-cantidad-minus');
    var cantidadPlus = document.getElementById('desk-cantidad-plus');
    var previewAmt = document.getElementById('desk-preview-amt');
    var previewHint = document.getElementById('desk-preview-hint');
    var comisionBox = document.getElementById('desk-comision');
    var submitBtn = document.getElementById('desk-submit');
    var clearBtn = document.getElementById('desk-clear');
    var balanceEl = document.getElementById('desk-balance');
    var barFill = document.getElementById('desk-bar-fill');
    var faltanEl = document.getElementById('desk-faltan');
    var pigFill = document.getElementById('desk-pig-fill');
    var recentList = document.getElementById('desk-recent');
    var overlay = document.getElementById('overlay');
    var gainAmount = document.getElementById('gain-amount');
    var burstZone = document.getElementById('burst-zone');

    var segButtons = seg.querySelectorAll('button');
    var idType = 'factura';
    var subcategoriesData = {};
    var categoriesData = {};

    // Carrito de la venta actual + preview del producto en edición.
    var cart = [];
    var lastPreview = null;
    var previewReq = 0;
    var curMarca = '';

    var addItemBtn = document.getElementById('desk-add-item-btn');
    var cartWrap = document.getElementById('desk-cart');
    var cartList = document.getElementById('desk-cart-list');
    var cartCount = document.getElementById('desk-cart-count');
    var cartTotal = document.getElementById('desk-cart-total');

    function fmt(n) {
        return 'S/ ' + Number(n).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function timeAgo(date) {
        var now = new Date();
        var d = Math.floor((now - date) / (1000 * 60 * 60 * 24));
        if (d === 0) return 'hoy';
        if (d === 1) return 'ayer';
        if (d < 7) return 'hace ' + d + ' días';
        return date.toLocaleDateString('es-PE');
    }

    function loadMarcas() {
        fetch(billetera.ajax_url + '?action=billetera_get_lineas')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    res.data.forEach(function (linea) {
                        var opt = document.createElement('option');
                        opt.value = linea.codigo;
                        opt.textContent = linea.nombre;
                        marcaSelect.appendChild(opt);
                    });
                }
            });
    }

    function handleIdType(e) {
        var btn = e.target.closest('button');
        if (!btn) return;
        segButtons.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        idType = btn.dataset.type;
        idInput.placeholder = ID_PLACEHOLDERS[idType];
        if (idLabel) idLabel.textContent = ID_LABELS[idType];
        idInput.value = '';
        validate();
    }

    function resetPreview() {
        updateCommissionBox();
    }

    // Caja "Comisión a registrar": con productos en el carrito muestra el total
    // de la venta; sin carrito muestra el preview del producto en edición.
    function updateCommissionBox() {
        if (!previewAmt) return;

        var total = 0;
        for (var i = 0; i < cart.length; i++) {
            total += cart[i].amount;
        }

        if (cart.length > 0) {
            previewAmt.textContent = fmt(total);
            var hint = cart.length + ' producto' + (cart.length > 1 ? 's' : '') + ' en esta venta';
            if (lastPreview) hint += ' · En edición: ' + lastPreview.formatted;
            previewHint.textContent = hint;
            comisionBox.classList.add('is-ready');
            return;
        }

        if (lastPreview) {
            previewAmt.textContent = lastPreview.formatted;
            previewHint.textContent = 'Comisión calculada según catálogo oficial.';
            comisionBox.classList.add('is-ready');
            return;
        }

        previewAmt.textContent = 'S/ 0.00';
        previewHint.textContent = 'Elige categoría y subcategoría para ver el monto.';
        comisionBox.classList.remove('is-ready');
    }

    function handleMarcaChange() {
        var newMarca = marcaSelect.value;
        // Cambiar de marca con productos agregados vacía la venta: confirmar.
        if (cart.length && newMarca !== curMarca) {
            var prevMarca = curMarca;
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
        curMarca = marcaId;
        catSelect.innerHTML = '';
        subSelect.innerHTML = '';
        subcategoriesData = {};
        categoriesData = {};
        lastPreview = null;
        previewReq++;
        resetPreview();

        if (!marcaId) {
            catSelect.disabled = true;
            var optC = document.createElement('option');
            optC.textContent = 'Primero elige una marca';
            catSelect.appendChild(optC);
            subSelect.disabled = true;
            var optS = document.createElement('option');
            optS.textContent = 'Primero elige una categoría';
            subSelect.appendChild(optS);
            validate();
            return;
        }

        catSelect.disabled = false;
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = 'Selecciona una categoría';
        catSelect.appendChild(ph);

        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_get_categorias&linea_codigo=' + encodeURIComponent(marcaId)
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    res.data.forEach(function (cat) {
                        categoriesData[cat.id] = cat;
                        var opt = document.createElement('option');
                        opt.value = cat.id;
                        opt.textContent = cat.nombre;
                        catSelect.appendChild(opt);
                    });
                }
                validate();
            });
    }

    function handleCatChange() {
        var catId = catSelect.value;
        subSelect.innerHTML = '';
        subcategoriesData = {};
        lastPreview = null;
        resetPreview();

        if (!catId) {
            subSelect.disabled = true;
            var opt = document.createElement('option');
            opt.textContent = 'Primero elige una categoría';
            subSelect.appendChild(opt);
            validate();
            return;
        }

        subSelect.disabled = false;
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = 'Selecciona una subcategoría';
        subSelect.appendChild(ph);

        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_get_subcategorias&categoria_id=' + catId
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    res.data.forEach(function (sub) {
                        subcategoriesData[sub.id] = sub;
                        var opt = document.createElement('option');
                        opt.value = sub.id;
                        opt.textContent = sub.nombre;
                        subSelect.appendChild(opt);
                    });
                }
                validate();
            });
    }

    function getCantidad() {
        var n = parseInt(cantidadInput && cantidadInput.value, 10);
        if (isNaN(n) || n < 1) return 1;
        return n;
    }

    function handleSubChange() {
        var subId = subSelect.value;
        if (!subId) {
            if (cantidadWrap) cantidadWrap.style.display = 'none';
            if (cantidadInput) cantidadInput.value = 1;
            lastPreview = null;
            resetPreview();
            validate();
            return;
        }

        var sub = subcategoriesData[subId];
        if (cantidadWrap && sub && Number(sub.por_unidad) === 1) {
            cantidadWrap.style.display = 'block';
        } else {
            cantidadWrap.style.display = 'none';
        }
        if (cantidadInput) cantidadInput.value = 1;

        lastPreview = null;
        loadComision();
        validate();
    }

    function handleCantidadChange() {
        lastPreview = null;
        loadComision();
        validate();
    }

    function stepCantidad(delta) {
        var current = getCantidad();
        var next = Math.max(1, current + delta);
        if (next === current) return;
        if (cantidadInput) cantidadInput.value = next;
        handleCantidadChange();
    }

    function loadComision() {
        var subId = subSelect.value;
        var req = ++previewReq;
        if (!subId) {
            lastPreview = null;
            resetPreview();
            updateAddBtn();
            return;
        }

        var cantidad = getCantidad();
        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_get_comision&nonce=' + encodeURIComponent(billetera.nonce_get_comision) + '&subcategoria_id=' + subId + '&cantidad=' + cantidad
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (req !== previewReq) return;
                if (res.success) {
                    lastPreview = { amount: Number(res.data.amount) || 0, formatted: res.data.amount_formatted };
                } else {
                    lastPreview = null;
                }
                updateCommissionBox();
                updateAddBtn();
                validate();
            });
    }

    function updateAddBtn() {
        if (!addItemBtn) return;
        var subId = subSelect.value;
        var idOk = idInput.value.trim().length > 2;
        var canAdd = subId && idOk && lastPreview !== null;
        addItemBtn.disabled = !canAdd;
        addItemBtn.textContent = canAdd ? ('Agregar · ' + lastPreview.formatted) : 'Agregar a la venta';
    }

    function handleAddItem() {
        var subId = subSelect.value;
        if (!subId || !lastPreview) return;
        if (idInput.value.trim().length <= 2) return;

        var catId = catSelect.value;
        var catNombre = (categoriesData[catId] && categoriesData[catId].nombre) || '';
        var subNombre = (subcategoriesData[subId] && subcategoriesData[subId].nombre) || '';
        var cant = getCantidad();
        var porUnidad = !!(subcategoriesData[subId] && Number(subcategoriesData[subId].por_unidad) === 1);
        var unitAmount = lastPreview.amount / cant;

        // Mismo producto "por unidad": en vez de duplicar la fila, suma cantidad.
        var existing = null;
        if (porUnidad) {
            for (var i = 0; i < cart.length; i++) {
                if (String(cart[i].subId) === String(subId) && cart[i].porUnidad) {
                    existing = cart[i];
                    break;
                }
            }
        }

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
        handleCatChange();
        if (cantidadWrap) cantidadWrap.style.display = 'none';
        if (cantidadInput) cantidadInput.value = 1;
        lastPreview = null;
        resetPreview();
        updateAddBtn();
        validate();
    }

    function removeCartItem(idx) {
        cart.splice(idx, 1);
        renderCart();
        validate();
    }

    function changeCartQty(idx, delta) {
        var item = cart[idx];
        if (!item || !item.porUnidad) return;
        var next = Math.max(1, item.cant + delta);
        if (next === item.cant) return;
        item.cant = next;
        item.amount = item.unitAmount * next;
        renderCart();
        validate();
    }

    function buildQtyStepper(idx, item) {
        var wrap = document.createElement('div');
        wrap.className = 'reg-cart__qty';

        var minus = document.createElement('button');
        minus.type = 'button';
        minus.textContent = '−';
        minus.disabled = item.cant <= 1;
        minus.setAttribute('aria-label', 'Quitar una unidad');
        minus.addEventListener('click', function () { changeCartQty(idx, -1); });

        var num = document.createElement('span');
        num.textContent = String(item.cant);

        var plus = document.createElement('button');
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

        var total = 0;
        cart.forEach(function (item, idx) {
            total += item.amount;
            var row = document.createElement('div');
            row.className = 'reg-cart__row';

            var info = document.createElement('div');
            info.className = 'reg-cart__info';
            var name = document.createElement('div');
            name.className = 'reg-cart__name';
            name.textContent = item.nombre;
            var meta = document.createElement('div');
            meta.className = 'reg-cart__meta';
            if (item.porUnidad) {
                meta.appendChild(buildQtyStepper(idx, item));
            } else {
                meta.textContent = 'Cantidad 1';
            }
            info.appendChild(name);
            info.appendChild(meta);

            var amt = document.createElement('div');
            amt.className = 'reg-cart__amt';
            amt.textContent = fmt(item.amount);

            var rm = document.createElement('button');
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
        if (cartTotal) cartTotal.textContent = fmt(total);
        updateSubmitLabel();
        updateCommissionBox();
    }

    function updateSubmitLabel() {
        if (!submitBtn) return;
        submitBtn.textContent = cart.length > 0 ? ('Registrar venta (' + cart.length + ')') : 'Registrar venta';
    }

    function validate() {
        var idOk = idInput.value.trim().length > 2;
        submitBtn.disabled = !(cart.length > 0 && idOk);
        updateAddBtn();
    }

    function handleSubmit() {
        if (submitBtn.disabled) return;
        if (!cart.length) return;
        var idVal = idInput.value.trim();
        var fecha = fechaInput ? fechaInput.value : new Date().toISOString().split('T')[0];
        var items = cart.map(function (it) {
            return { subcategoria_id: parseInt(it.subId, 10), cantidad: parseInt(it.cant, 10) || 1 };
        });

        submitBtn.disabled = true;

        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_register_sale_multi&nonce=' + encodeURIComponent(billetera.nonce_register_sale) + '&id_type=' + idType + '&id_value=' + encodeURIComponent(idVal) + '&fecha=' + fecha + '&items=' + encodeURIComponent(JSON.stringify(items))
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var amount = data.data.amount || 0;
                    var bonus = Number(data.data.bonus_multiplier) || 1;
                    var bonusCat = data.data.bonus_categoria || '';
                    var bonusAplicado = Number(data.data.bonus_aplicado) || 0;
                    if (gainAmount) {
                        gainAmount.textContent = '+S/ ' + amount.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    }
                    var gainBonus = document.getElementById('gain-bonus');
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
                    if (overlay) overlay.classList.add('show');
                    spawnCoins(burstZone);
                    resetForm();
                    setTimeout(loadUserData, 1500);
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
            .catch(function () {
                submitBtn.disabled = false;
                openRegModal({
                    title: 'Error de conexión',
                    desc: 'No se pudo registrar la venta. Intenta de nuevo.',
                    okLabel: 'Entendido',
                    single: true
                });
            });
    }

    function openRegModal(opts) {
        closeRegModal();
        var ov = document.createElement('div');
        ov.className = 'alc-modal-ov';
        ov.id = 'reg-modal';
        var warn = opts.warn ? '<div class="alc-modal__warn">' + opts.warn + '</div>' : '';
        var actions = opts.single
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
        var cancel = ov.querySelector('[data-act="cancel"]');
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
        var ov = document.getElementById('reg-modal');
        if (ov && ov.parentNode) ov.parentNode.removeChild(ov);
    }

    function spawnCoins(zone) {
        if (!zone) return;
        for (var i = 0; i < 18; i++) {
            var coin = document.createElement('div');
            coin.className = 'coin';
            var angle = Math.random() * Math.PI * 2;
            var dist = 55 + Math.random() * 85;
            var dx = Math.cos(angle) * dist;
            var dy = Math.sin(angle) * dist;
            var rot = (Math.random() * 720 - 360).toFixed(0);
            var delay = Math.random() * 0.15;
            coin.style.animation = 'celebrateCoinburst .9s cubic-bezier(.15,.7,.3,1) ' + delay + 's forwards';
            coin.style.setProperty('--dx', dx + 'px');
            coin.style.setProperty('--dy', dy + 'px');
            coin.style.setProperty('--rot', rot + 'deg');
            zone.appendChild(coin);
            setTimeout(function () { coin.remove(); }, 1300);
        }
    }

    function resetForm() {
        idInput.value = '';
        marcaSelect.value = '';
        catSelect.innerHTML = '<option value="">Primero elige una marca</option>';
        catSelect.disabled = true;
        subSelect.innerHTML = '<option value="">Primero elige una categoría</option>';
        subSelect.disabled = true;
        subcategoriesData = {};
        categoriesData = {};
        curMarca = '';
        cart = [];
        lastPreview = null;
        previewReq++;
        renderCart();
        if (cantidadWrap) cantidadWrap.style.display = 'none';
        if (cantidadInput) cantidadInput.value = 1;
        resetPreview();
        submitBtn.disabled = true;
    }

    function loadUserData() {
        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_get_balance&nonce=' + encodeURIComponent(billetera.nonce_get_balance)
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success) return;
                var d = res.data;
                var balance = d.balance || 0;
                var meta = d.meta || 0;
                var fill = d.fill_percent || 0;

                if (balanceEl) balanceEl.textContent = fmt(balance);
                if (barFill) barFill.style.width = fill + '%';
                if (faltanEl) faltanEl.textContent = fmt(Math.max(0, meta - balance));
                if (pigFill) pigFill.style.clipPath = 'inset(' + (100 - fill) + '% 0 0 0)';

                renderRecent(d.movements || []);
            });
    }

    function renderRecent(movements) {
        if (!recentList) return;
        recentList.innerHTML = '';
        var items = movements.slice(0, 3);
        if (!items.length) {
            recentList.innerHTML = '<div class="reg-desk__recent-empty">Sin registros aún.</div>';
            return;
        }
        items.forEach(function (m, i) {
            var nombre = (m.categoria ? m.categoria : '') + (m.subcategoria ? ' · ' + m.subcategoria : '');
            var bonus = (Number(m.bonus_multiplier) > 1 || Number(m.bonus_aplicado) === 1) ? ' <span class="alc-mov__bonus">' + ((Number(m.bonus_multiplier) > 1) ? (Math.round(Number(m.bonus_multiplier) * 100) / 100) + 'x' : 'Bono') + '</span>' : '';
            var meta = (m.asesor_nombre || (m.id_value || '')) + ' · ' + timeAgo(new Date(m.created_at));
            var row = document.createElement('div');
            row.className = 'reg-desk__recent-row' + (i % 2 === 1 ? ' reg-desk__recent-row--alt' : '');
            row.innerHTML =
                '<div><div class="reg-desk__recent-name">' + nombre + '</div>' +
                '<div class="reg-desk__recent-meta">' + meta + '</div></div>' +
                '<div class="reg-desk__recent-amt">+' + fmt(Number(m.amount) || 0) + bonus + '</div>';
            recentList.appendChild(row);
        });
    }

    segButtons.forEach(function (b) { b.addEventListener('click', handleIdType); });
    idInput.addEventListener('input', validate);
    marcaSelect.addEventListener('change', handleMarcaChange);
    catSelect.addEventListener('change', handleCatChange);
    subSelect.addEventListener('change', handleSubChange);
    if (cantidadInput) cantidadInput.addEventListener('input', handleCantidadChange);
    if (cantidadMinus) cantidadMinus.addEventListener('click', function () { stepCantidad(-1); });
    if (cantidadPlus) cantidadPlus.addEventListener('click', function () { stepCantidad(1); });
    if (addItemBtn) addItemBtn.addEventListener('click', handleAddItem);
    submitBtn.addEventListener('click', handleSubmit);
    clearBtn.addEventListener('click', resetForm);

    loadMarcas();
    loadUserData();
})();
