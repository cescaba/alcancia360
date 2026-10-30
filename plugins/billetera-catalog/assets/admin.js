// Billetera Catalog - Admin: pequeñas mejoras del panel
(function () {
    'use strict';

    function initAutoSlug() {
        var forms = document.querySelectorAll('.bc-wrap form');
        forms.forEach(function (form) {
            var nombre = form.querySelector('input[name="nombre"]');
            var slug = form.querySelector('input[name="slug"]');
            if (!nombre || !slug || slug.readOnly) return;

            var touched = slug.value.trim() !== '';
            slug.addEventListener('input', function () { touched = true; });

            nombre.addEventListener('input', function () {
                if (touched) return;
                slug.value = nombre.value
                    .toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
            });
        });
    }

    function initBonusTabs() {
        var nav = document.querySelector('.bc-bonus-tabs__nav');
        if (!nav) return;
        var form = nav.closest('form');
        if (!form) return;
        var buttons = Array.prototype.slice.call(nav.querySelectorAll('[data-bc-tab]'));
        var panels = Array.prototype.slice.call(form.querySelectorAll('.bc-bonus-tabs__panel'));
        if (!buttons.length || !panels.length) return;

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = btn.getAttribute('data-bc-tab');
                buttons.forEach(function (b) { b.classList.toggle('nav-tab-active', b === btn); });
                panels.forEach(function (p) { p.hidden = (p.id !== target); });
            });
        });
    }

    function initBonusCascade() {
        var form = document.getElementById('bc-bonus-form');
        if (!form) return;
        var selCat = form.querySelector('#bc-scope-categoria');
        var selMarca = form.querySelector('#bc-scope-marca');
        var selLinea = form.querySelector('#bc-scope-linea');
        var selSub = form.querySelector('#bc-scope-sub');
        var resumen = form.querySelector('#bc-bonus-resumen');
        if (!selCat || !selSub) return;

        function text(sel) {
            var o = sel.options[sel.selectedIndex];
            return o ? o.textContent.trim() : '';
        }

        function filterLineas() {
            if (!selMarca || !selLinea) return;
            var m = selMarca.value;
            Array.prototype.forEach.call(selLinea.options, function (o) {
                if (!o.value) { o.hidden = false; return; }
                o.hidden = (m !== '0' && o.getAttribute('data-marca') !== m);
            });
            var cur = selLinea.options[selLinea.selectedIndex];
            if (cur && cur.hidden) selLinea.value = '';
        }

        function filterSubs() {
            var slug = (selCat.value || '').toLowerCase();
            var lin = (selLinea ? selLinea.value : '').toLowerCase();
            var m = selMarca ? selMarca.value : '0';
            Array.prototype.forEach.call(selSub.options, function (o) {
                if (!o.value || o.value === '0') { o.hidden = false; return; }
                var ok = true;
                if (slug && (o.getAttribute('data-cat') || '').toLowerCase() !== slug) ok = false;
                if (lin && (o.getAttribute('data-linea') || '').toLowerCase() !== lin) ok = false;
                if (m !== '0' && (o.getAttribute('data-marca') || '') !== m) ok = false;
                o.hidden = !ok;
            });
            var cur = selSub.options[selSub.selectedIndex];
            if (cur && cur.hidden) selSub.value = '0';
        }

        function updateResumen() {
            if (!resumen) return;
            var parts = [text(selCat) || 'Todas las categorías', text(selMarca) || 'Todas las marcas', text(selLinea) || 'Todas las líneas', text(selSub) || 'Todas las subcategorías'];
            var selDealer = form.querySelector('#bc-scope-dealer');
            parts.push(selDealer ? (text(selDealer) || 'Todas las tiendas') : 'Todas las tiendas');
            resumen.textContent = parts.join(' · ');
        }

        function refresh() { filterLineas(); filterSubs(); updateResumen(); }
        [selCat, selMarca, selLinea, selSub].forEach(function (s) {
            if (s) s.addEventListener('change', refresh);
        });
        var selDealer = form.querySelector('#bc-scope-dealer');
        if (selDealer) selDealer.addEventListener('change', updateResumen);
        refresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAutoSlug(); initBonusTabs(); initBonusCascade(); });
    } else {
        initAutoSlug();
        initBonusTabs();
        initBonusCascade();
    }
})();
