// Mi alcancia 360 - Ranking (posición del asesor, solo nombres)
(function () {
    'use strict';

    if (!window.billetera || !window.billetera.ajax_url) return;

    var data = null;
    var ambito = 'tienda';

    var els = {
        loading: document.getElementById('rk-loading'),
        list: document.getElementById('rk-list'),
        empty: document.getElementById('rk-empty'),
        error: document.getElementById('rk-error'),
        cardTitle: document.getElementById('rk-card-title'),
        tabs: document.getElementById('rk-tabs')
    };

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function load() {
        if (els.error) els.error.hidden = true;

        var body = new URLSearchParams();
        body.append('action', 'billetera_get_ranking');
        body.append('nonce', window.billetera.nonce_ranking);

        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.success) {
                    showError((res && res.data && res.data.message) || 'No se pudo cargar el ranking.');
                    return;
                }
                data = res.data;
                if (els.loading) els.loading.hidden = true;
                render();
            })
            .catch(function () {
                showError('No se pudo cargar el ranking.');
            });
    }

    function showError(msg) {
        if (els.loading) els.loading.hidden = true;
        if (els.list) els.list.hidden = true;
        if (els.empty) els.empty.hidden = true;
        if (els.error) { els.error.textContent = msg; els.error.hidden = false; }
    }

    function render() {
        if (!data) return;
        setText('rk-mes', data.mes_label || '');
        renderHero('tienda', data.tienda);
        renderHero('global', data.global);
        renderList();
    }

    function renderHero(key, scope) {
        var val = document.getElementById('rk-rank-' + key);
        var hint = document.getElementById('rk-total-' + key);
        if (!val) return;

        if (!scope || !scope.rank) {
            val.textContent = '—';
        } else {
            val.textContent = '#' + scope.rank;
        }

        if (hint) {
            if (scope && scope.total) {
                if (key === 'tienda' && data.tienda_label) {
                    hint.textContent = 'de ' + scope.total + ' en ' + data.tienda_label;
                } else {
                    hint.textContent = 'de ' + scope.total + ' asesores';
                }
            } else {
                hint.textContent = '';
            }
        }
    }

    function renderList() {
        if (!els.list) return;
        els.list.innerHTML = '';

        var scope = data[ambito] || {};
        var rows = scope.top || [];
        var ventana = scope.ventana || [];

        if (!rows.length) {
            els.list.hidden = true;
            if (els.empty) {
                els.empty.hidden = false;
                els.empty.textContent = ambito === 'tienda'
                    ? 'Tu tienda aún no tiene asesores con ventas este mes.'
                    : 'Aún no hay ventas registradas este mes.';
            }
            return;
        }

        if (els.empty) els.empty.hidden = true;
        els.list.hidden = false;

        if (els.cardTitle) {
            els.cardTitle.textContent = ambito === 'tienda' ? 'Ranking de mi tienda' : 'Ranking global';
        }

        rows.forEach(function (r) { els.list.appendChild(makeRow(r)); });

        if (!scope.en_top && ventana.length) {
            var gap = document.createElement('div');
            gap.className = 'rk-gap';
            gap.textContent = 'Tu posición';
            els.list.appendChild(gap);
            ventana.forEach(function (r) { els.list.appendChild(makeRow(r)); });
        }
    }

    function makeRow(r) {
        var row = document.createElement('div');
        row.className = 'rk-row' + (r.es_yo ? ' is-me' : '');

        var pos = document.createElement('span');
        pos.className = 'rk-row__pos';
        if (r.pos >= 1 && r.pos <= 3) {
            pos.classList.add('rk-row__pos--' + r.pos);
        }
        pos.textContent = r.pos;

        var name = document.createElement('span');
        name.className = 'rk-row__name';
        name.textContent = r.nombre || '';

        row.appendChild(pos);
        row.appendChild(name);

        if (r.es_yo) {
            var tag = document.createElement('span');
            tag.className = 'rk-row__tag';
            tag.textContent = 'Tú';
            row.appendChild(tag);
        }

        return row;
    }

    if (els.tabs) {
        els.tabs.addEventListener('click', function (e) {
            var btn = e.target.closest('.rk-tab');
            if (!btn) return;
            els.tabs.querySelectorAll('.rk-tab').forEach(function (b) { b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            ambito = btn.dataset.ambito;
            renderList();
        });
    }

    load();
})();
