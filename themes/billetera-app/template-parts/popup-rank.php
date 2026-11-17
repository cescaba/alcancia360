<?php
/**
 * Popup "Ranking #1 en tu tienda" (solo asesores).
 * Sale cada vez que el asesor llega al puesto 1 de su tienda (por monto de
 * comisiones del mes, mismo criterio del ranking de la app). No repite hasta
 * que baje del puesto y vuelva a subir. Se chequea al cargar páginas y tras
 * cada venta. Animación: podio sube con el chanchito + corona cae con rebote.
 */
?>
<div class="popup-rank" id="popup-rank" hidden>
    <div class="popup-rank__box">
        <div class="popup-rank__head">
            <button class="popup-rank__close" id="popup-rank-close" type="button" aria-label="Cerrar">✕</button>
            <span class="popup-rank__badge">RANKING</span>
        </div>

        <div class="popup-rank__stage">
            <span class="popup-rank__spark popup-rank__spark--1"></span>
            <span class="popup-rank__spark popup-rank__spark--2"></span>
            <span class="popup-rank__spark popup-rank__spark--3"></span>
            <span class="popup-rank__spark popup-rank__spark--4"></span>
            <img class="popup-rank__pig" src="<?php echo billetera_asset_url('/assets/img/alcancia.svg'); ?>" alt="Alcancía">
            <img class="popup-rank__crown" src="<?php echo billetera_asset_url('/assets/img/corona-rank.svg'); ?>" alt="">
            <div class="popup-rank__podio"><span>1</span></div>
        </div>

        <div class="popup-rank__num">#1</div>
        <div class="popup-rank__title">¡EN TU TIENDA!</div>

        <div class="popup-rank__card">
            <div class="popup-rank__list" id="popup-rank-list">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <div class="popup-rank__row<?php echo $i === 0 ? ' popup-rank__row--first' : ''; ?>" data-row="<?php echo $i; ?>">
                        <span class="popup-rank__pos<?php echo $i === 0 ? ' popup-rank__pos--gold' : ''; ?>"><?php echo $i + 1; ?></span>
                        <span class="popup-rank__name">—</span>
                        <span class="popup-rank__ventas">—</span>
                    </div>
                <?php endfor; ?>
            </div>
            <p class="popup-rank__msg" id="popup-rank-msg"></p>
        </div>

        <button class="popup-rank__cta" id="popup-rank-cta" type="button">CONTINUAR</button>
    </div>
</div>

<input type="hidden" id="popup-rank-nonce" value="<?php echo wp_create_nonce('billetera_popup_rank'); ?>">

<script>
window.BilleteraPopupRank = (function () {
    var el = document.getElementById('popup-rank');
    var closeBtn = document.getElementById('popup-rank-close');
    var ctaBtn = document.getElementById('popup-rank-cta');
    var listEl = document.getElementById('popup-rank-list');
    var msgEl = document.getElementById('popup-rank-msg');

    function markSeen(mesKey) {
        if (!mesKey) return;
        var nonce = (document.getElementById('popup-rank-nonce') || {}).value || '';
        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_popup_rank_visto&nonce=' +
                encodeURIComponent(nonce) +
                '&mes_key=' + encodeURIComponent(mesKey)
        }).catch(function () {});
    }

    var chainNext = false;

    function close() {
        el.hidden = true;
        document.body.style.overflow = '';
        if (chainNext && window.billeteraGoToMovimientos) {
            chainNext = false;
            window.billeteraGoToMovimientos();
        }
    }

    function fillRows(top) {
        var rows = listEl.querySelectorAll('.popup-rank__row');
        for (var i = 0; i < rows.length; i++) {
            var d = (top && top[i]) ? top[i] : null;
            var nameEl = rows[i].querySelector('.popup-rank__name');
            var ventasEl = rows[i].querySelector('.popup-rank__ventas');
            if (!d) {
                rows[i].style.display = 'none';
                continue;
            }
            rows[i].style.display = '';
            nameEl.textContent = d.nombre;
            ventasEl.textContent = d.ventas + (d.ventas === 1 ? ' venta' : ' ventas');
            // Badge "TÚ" en la fila del asesor.
            var old = rows[i].querySelector('.popup-rank__you');
            if (old) old.remove();
            if (d.es_yo) {
                var you = document.createElement('span');
                you.className = 'popup-rank__you';
                you.textContent = 'TÚ';
                rows[i].insertBefore(you, ventasEl);
            }
        }
    }

    function show(d, opts) {
        opts = opts || {};
        chainNext = !!opts.chain;
        fillRows(d.top || []);
        var tienda = d.tienda || 'tu tienda';
        msgEl.textContent = 'Eres el #1 de ' + tienda + '. ¡Defiende tu puesto!';

        // Reinicia las animaciones (podio + corona).
        var stage = el.querySelector('.popup-rank__stage');
        if (stage) {
            var clone = stage.cloneNode(true);
            stage.parentNode.replaceChild(clone, stage);
        }

        el.hidden = false;
        document.body.style.overflow = 'hidden';
        markSeen(d.mes_key);
    }

    function check() {
        if (!window.billetera || !window.billetera.ajax_url) return;
        fetch(window.billetera.ajax_url + '?action=billetera_popup_rank_check')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data) {
                    show(res.data);
                }
            })
            .catch(function () {});
    }

    function init() {
        if (!window.__popupRankEnabled) return; // solo asesores
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (ctaBtn) ctaBtn.addEventListener('click', close);
        if (window.__popupRankTest) {
            show(window.__popupRankTest);
        } else {
            check();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { show: show };
})();
</script>
