// Mi alcancia 360 - Adjuntos de venta (documentos opcionales)
// Crea el widget en cada [data-adjuntos]. JPG/PNG/PDF, máx 5 MB, hasta 3.
// Sube cada archivo al elegirlo y guarda tokens; la venta los vincula al
// registrarse (action=billetera_adjunto_subir / register_sale_multi).
(function () {
    'use strict';

    var MAX_FILES = 3;
    var MAX_SIZE = 5 * 1024 * 1024;
    var EXT_OK = ['jpg', 'jpeg', 'png', 'pdf'];
    var TIPOS_TXT = 'JPG, PNG o PDF · hasta 5 MB · máx. 3 archivos';

    var ICON_CLIP = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
    var ICON_FILE = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>';
    var ICON_X = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>';

    var tokens = [];

    function extDe(nombre) {
        var i = nombre.lastIndexOf('.');
        return i >= 0 ? nombre.slice(i + 1).toLowerCase() : '';
    }

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function acortar(nombre, max) {
        max = max || 34;
        if (nombre.length <= max) return nombre;
        var punto = nombre.lastIndexOf('.');
        var ext = punto >= 0 ? nombre.slice(punto) : '';
        var base = punto >= 0 ? nombre.slice(0, punto) : nombre;
        return base.slice(0, max - ext.length - 1) + '…' + ext;
    }

    function construir(root) {
        var variant = root.getAttribute('data-adjuntos-variant') || '';
        var v = variant ? '-' + variant : '';

        root.innerHTML =
            '<p class="reg-label">Documento <span class="adj-opt">(opcional)</span></p>' +
            '<div class="adj-drop" data-drop tabindex="0" role="button" aria-label="Adjuntar documento">' +
                '<span class="adj-drop__icon">' + ICON_CLIP + '</span>' +
                '<span class="adj-drop__txt">' +
                    '<strong>Adjuntar documento</strong>' +
                    '<span>' + TIPOS_TXT + '</span>' +
                '</span>' +
                '<input type="file" data-input accept=".jpg,.jpeg,.png,.pdf" multiple hidden>' +
            '</div>' +
            '<div class="adj-chips" data-chips></div>' +
            '<p class="adj-err" data-err hidden></p>';

        var drop = root.querySelector('[data-drop]');
        var input = root.querySelector('[data-input]');
        var chips = root.querySelector('[data-chips]');
        var err = root.querySelector('[data-err]');

        drop.addEventListener('click', function (e) {
            e.preventDefault();
            input.click();
        });
        drop.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                input.click();
            }
        });

        input.addEventListener('change', function () {
            subirArchivos(Array.prototype.slice.call(input.files || []));
            input.value = '';
        });

        function setError(msg) {
            err.textContent = msg || '';
            err.hidden = !msg;
        }

        function pintar() {
            chips.innerHTML = '';
            tokens.forEach(function (t) {
                var chip = document.createElement('span');
                chip.className = 'adj-chip';
                chip.innerHTML = ICON_FILE +
                    '<span class="adj-chip__name" title="' + esc(t.nombre) + '">' + esc(acortar(t.nombre)) + '</span>' +
                    '<button type="button" class="adj-chip__x" data-token="' + esc(t.token) + '" aria-label="Quitar ' + esc(t.nombre) + '">' + ICON_X + '</button>';
                chips.appendChild(chip);
            });
            drop.classList.toggle('is-full', tokens.length >= MAX_FILES);
        }

        chips.addEventListener('click', function (e) {
            var x = e.target.closest('.adj-chip__x');
            if (!x) return;
            tokens = tokens.filter(function (t) { return t.token !== x.getAttribute('data-token'); });
            pintar();
            setError('');
        });

        function subirArchivos(files) {
            if (!files.length) return;
            setError('');

            var espacio = MAX_FILES - tokens.length;
            if (espacio <= 0) {
                setError('Máximo ' + MAX_FILES + ' archivos por venta.');
                return;
            }
            if (files.length > espacio) {
                setError('Solo puedes agregar ' + espacio + ' archivo(s) más.');
                files = files.slice(0, espacio);
            }

            var cfg = (window.billetera || {});
            var f0 = files[0];
            if (EXT_OK.indexOf(extDe(f0.name)) === -1) {
                setError('“' + f0.name + '”: solo JPG, PNG o PDF.');
                return;
            }
            if (f0.size > MAX_SIZE) {
                setError('“' + acortar(f0.name, 20) + '” supera los 5 MB.');
                return;
            }

            drop.classList.add('is-uploading');

            var fd = new FormData();
            fd.append('action', 'billetera_adjunto_subir');
            fd.append('nonce', cfg.nonce_register_sale || '');
            fd.append('archivo', f0, f0.name);

            fetch(cfg.ajax_url || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    drop.classList.remove('is-uploading');
                    if (res && res.success && res.data) {
                        tokens.push({ token: res.data.token, nombre: res.data.nombre });
                        pintar();
                        // Subir el siguiente en cola
                        if (files.length > 1) {
                            subirArchivos(files.slice(1));
                        }
                    } else {
                        setError((res && res.data && res.data.message) || 'No se pudo adjuntar el archivo.');
                    }
                })
                .catch(function () {
                    drop.classList.remove('is-uploading');
                    setError('No se pudo adjuntar el archivo. Intenta de nuevo.');
                });
        }

        pintar();
    }

    function init() {
        document.querySelectorAll('[data-adjuntos]').forEach(construir);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // API para el flujo de venta
    window.BilleteraAdjuntos = {
        tokens: function () {
            return tokens.map(function (t) { return t.token; });
        },
        reset: function () {
            tokens = [];
            document.querySelectorAll('[data-adjuntos]').forEach(function (root) {
                var chips = root.querySelector('[data-chips]');
                var err = root.querySelector('[data-err]');
                if (chips) chips.innerHTML = '';
                if (err) { err.hidden = true; }
                var drop = root.querySelector('[data-drop]');
                if (drop) drop.classList.remove('is-full', 'is-uploading');
            });
        }
    };
})();
