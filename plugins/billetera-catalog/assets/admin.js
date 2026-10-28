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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAutoSlug(); initBonusTabs(); });
    } else {
        initAutoSlug();
        initBonusTabs();
    }
})();
