// Hyundai Usuarios - Admin: multiselect con buscador y chips
(function () {
    'use strict';

    function initChips(select) {
        if (select.dataset.huChipsInit) return;
        select.dataset.huChipsInit = '1';

        var wrap = document.createElement('div');
        wrap.className = 'hu-chips';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        select.classList.add('hu-chips__native');

        var box = document.createElement('div');
        box.className = 'hu-chips__box';

        var chips = document.createElement('div');
        chips.className = 'hu-chips__chips';

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'hu-chips__input';
        input.setAttribute('autocomplete', 'off');
        input.placeholder = select.dataset.placeholder || 'Buscar…';

        box.appendChild(chips);
        box.appendChild(input);

        var menu = document.createElement('div');
        menu.className = 'hu-chips__menu';
        menu.hidden = true;

        wrap.appendChild(box);
        wrap.appendChild(menu);

        var options = Array.prototype.slice.call(select.options);

        function selectedOptions() {
            return options.filter(function (o) { return o.selected; });
        }

        function renderChips() {
            chips.innerHTML = '';
            selectedOptions().forEach(function (o) {
                var chip = document.createElement('span');
                chip.className = 'hu-chips__chip';

                var label = document.createElement('span');
                label.textContent = o.textContent.trim();

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'Quitar');
                remove.innerHTML = '&times;';
                remove.addEventListener('click', function () {
                    o.selected = false;
                    renderChips();
                    renderMenu(input.value);
                });

                chip.appendChild(label);
                chip.appendChild(remove);
                chips.appendChild(chip);
            });
        }

        function renderMenu(query) {
            var q = (query || '').toLowerCase().trim();
            menu.innerHTML = '';

            var matches = options.filter(function (o) {
                return !o.selected && o.textContent.toLowerCase().indexOf(q) !== -1;
            });

            if (!matches.length) {
                var empty = document.createElement('div');
                empty.className = 'hu-chips__empty';
                empty.textContent = 'Sin resultados';
                menu.appendChild(empty);
                return;
            }

            matches.forEach(function (o) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'hu-chips__opt';
                item.textContent = o.textContent.trim();
                item.addEventListener('click', function () {
                    o.selected = true;
                    input.value = '';
                    renderChips();
                    renderMenu('');
                    input.focus();
                });
                menu.appendChild(item);
            });
        }

        box.addEventListener('click', function (e) {
            if (e.target.closest('.hu-chips__chip')) return;
            input.focus();
        });

        input.addEventListener('focus', function () {
            renderMenu(input.value);
            menu.hidden = false;
        });

        input.addEventListener('input', function () {
            renderMenu(input.value);
            menu.hidden = false;
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && input.value === '') {
                var sel = selectedOptions();
                if (sel.length) {
                    sel[sel.length - 1].selected = false;
                    renderChips();
                }
            }
            if (e.key === 'Escape') {
                menu.hidden = true;
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) menu.hidden = true;
        });

        renderChips();
    }

    function initAll() {
        document.querySelectorAll('select.hu-chips').forEach(initChips);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
