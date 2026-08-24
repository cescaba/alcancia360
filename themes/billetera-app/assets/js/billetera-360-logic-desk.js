// Billetera 360 - Lógica del formulario desktop "Registrar venta"

(function () {
    var ID_PLACEHOLDERS = { placa: 'ABC-123', vin: 'KMHXX00XXXX000000', ot: 'OT-004521', factura: 'F001-000123' };
    var ID_LABELS = { placa: 'Placa', vin: 'VIN', ot: 'OT', factura: 'N° Factura' };

    var seg = document.getElementById('desk-id-seg');
    if (!seg) return;

    var idLabel = document.getElementById('desk-id-label');
    var idInput = document.getElementById('desk-id-input');
    var marcaSelect = document.getElementById('desk-marca-select');
    var catSelect = document.getElementById('desk-cat-select');
    var subSelect = document.getElementById('desk-sub-select');
    var cantidadWrap = document.getElementById('desk-cantidad-wrap');
    var cantidadInput = document.getElementById('desk-cantidad-input');
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

    var segButtons = seg.querySelectorAll('button');
    var idType = 'factura';
    var subcategoriesData = {};

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
        fetch(billetera.ajax_url + '?action=billetera_get_marcas')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    res.data.forEach(function (marca) {
                        var opt = document.createElement('option');
                        opt.value = marca.id;
                        opt.textContent = marca.nombre;
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
        previewAmt.textContent = 'S/ 0.00';
        previewHint.textContent = 'Elige categoría y subcategoría para ver el monto.';
        comisionBox.classList.remove('is-ready');
    }

    function handleMarcaChange() {
        var marcaId = marcaSelect.value;
        catSelect.innerHTML = '';
        subSelect.innerHTML = '';
        subcategoriesData = {};
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
            body: 'action=billetera_get_categorias&marca_id=' + marcaId
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    res.data.forEach(function (cat) {
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

        loadComision();
        validate();
    }

    function handleCantidadChange() {
        loadComision();
        validate();
    }

    function loadComision() {
        var subId = subSelect.value;
        if (!subId) {
            resetPreview();
            return;
        }

        var cantidad = getCantidad();
        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_get_comision&subcategoria_id=' + subId + '&cantidad=' + cantidad
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    previewAmt.textContent = res.data.amount_formatted;
                    previewHint.textContent = 'Comisión calculada según catálogo oficial.';
                    comisionBox.classList.add('is-ready');
                } else {
                    resetPreview();
                }
                validate();
            });
    }

    function validate() {
        var sub = subSelect.value;
        var idOk = idInput.value.trim().length > 2;
        submitBtn.disabled = !(sub && idOk);
    }

    function handleSubmit() {
        if (submitBtn.disabled) return;
        var subId = subSelect.value;
        var cantidad = getCantidad();
        var idVal = idInput.value.trim();

        fetch(billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_register_sale_v2&subcategoria_id=' + subId + '&cantidad=' + cantidad + '&id_type=' + idType + '&id_value=' + encodeURIComponent(idVal)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    var amount = data.data.amount || 0;
                    if (gainAmount) gainAmount.textContent = '+S/ ' + amount.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    if (overlay) overlay.classList.add('show');
                    resetForm();
                    setTimeout(loadUserData, 1500);
                    setTimeout(function () { if (overlay) overlay.classList.remove('show'); }, 4000);
                }
            });
    }

    function resetForm() {
        idInput.value = '';
        marcaSelect.value = '';
        catSelect.innerHTML = '<option value="">Primero elige una marca</option>';
        catSelect.disabled = true;
        subSelect.innerHTML = '<option value="">Primero elige una categoría</option>';
        subSelect.disabled = true;
        subcategoriesData = {};
        if (cantidadWrap) cantidadWrap.style.display = 'none';
        if (cantidadInput) cantidadInput.value = 1;
        resetPreview();
        submitBtn.disabled = true;
    }

    function loadUserData() {
        fetch(billetera.ajax_url + '?action=billetera_get_balance')
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
            var meta = (m.asesor_nombre || (m.id_value || '')) + ' · ' + timeAgo(new Date(m.created_at));
            var row = document.createElement('div');
            row.className = 'reg-desk__recent-row' + (i % 2 === 1 ? ' reg-desk__recent-row--alt' : '');
            row.innerHTML =
                '<div><div class="reg-desk__recent-name">' + nombre + '</div>' +
                '<div class="reg-desk__recent-meta">' + meta + '</div></div>' +
                '<div class="reg-desk__recent-amt">+' + fmt(Number(m.amount) || 0) + '</div>';
            recentList.appendChild(row);
        });
    }

    segButtons.forEach(function (b) { b.addEventListener('click', handleIdType); });
    idInput.addEventListener('input', validate);
    marcaSelect.addEventListener('change', handleMarcaChange);
    catSelect.addEventListener('change', handleCatChange);
    subSelect.addEventListener('change', handleSubChange);
    if (cantidadInput) cantidadInput.addEventListener('input', handleCantidadChange);
    submitBtn.addEventListener('click', handleSubmit);
    clearBtn.addEventListener('click', resetForm);

    loadMarcas();
    loadUserData();
})();
