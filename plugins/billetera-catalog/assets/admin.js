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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAutoSlug);
    } else {
        initAutoSlug();
    }
})();
