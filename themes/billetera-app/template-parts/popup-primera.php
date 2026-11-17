<?php
/**
 * Popup "Primera moneda del mes" (solo asesores).
 * Sale cuando la venta recién registrada es la primera del mes. Muestra el
 * monto de la venta, la hora en el chip "Nueva moneda", el % de asesores a
 * los que les ganó y animación de moneda girando con destello.
 * Botones: VER MI ALCANCÍA (navega) y CONTINUAR (cierra).
 */
?>
<div class="popup-primera" id="popup-primera" hidden>
    <div class="popup-primera__box">
        <div class="popup-primera__head">
            <button class="popup-primera__close" id="popup-primera-close" type="button" aria-label="Cerrar">✕</button>
            <span class="popup-primera__badge">PRIMERA VENTA DEL MES</span>
        </div>

        <div class="popup-primera__stage">
            <span class="popup-primera__spark popup-primera__spark--1"></span>
            <span class="popup-primera__spark popup-primera__spark--2"></span>
            <span class="popup-primera__spark popup-primera__spark--3"></span>
            <span class="popup-primera__spark popup-primera__spark--4"></span>
            <img class="popup-primera__pig" src="<?php echo billetera_asset_url('/assets/img/alcancia.svg'); ?>" alt="Alcancía">
            <span class="popup-primera__ray popup-primera__ray--main"></span>
            <span class="popup-primera__ray popup-primera__ray--left"></span>
            <span class="popup-primera__ray popup-primera__ray--right"></span>
            <div class="popup-primera__coin" id="popup-primera-coin"><span>+0</span></div>
            <div class="popup-primera__chip" id="popup-primera-chip">Nueva moneda · 7:42 a.m.</div>
        </div>

        <div class="popup-primera__num" id="popup-primera-num">+S/ 0</div>
        <div class="popup-primera__title" id="popup-primera-title">¡PRIMERA MONEDA!</div>

        <div class="popup-primera__card">
            <p class="popup-primera__msg" id="popup-primera-msg"></p>
        </div>

        <button class="popup-primera__cta" id="popup-primera-cta" type="button">VER MI ALCANCÍA</button>
        <button class="popup-primera__continue" id="popup-primera-continue" type="button">CONTINUAR</button>
    </div>
</div>

<input type="hidden" id="popup-primera-nonce" value="<?php echo wp_create_nonce('billetera_popup_primera'); ?>">

<script>
window.BilleteraPopupPrimera = (function () {
    var el = document.getElementById('popup-primera');
    var closeBtn = document.getElementById('popup-primera-close');
    var ctaBtn = document.getElementById('popup-primera-cta');
    var contBtn = document.getElementById('popup-primera-continue');
    var coinEl = document.getElementById('popup-primera-coin');
    var chipEl = document.getElementById('popup-primera-chip');
    var numEl = document.getElementById('popup-primera-num');
    var titleEl = document.getElementById('popup-primera-title');
    var msgEl = document.getElementById('popup-primera-msg');

    var chainNext = false;

    function fmt(n) {
        return Number(n).toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function markSeen(mesKey) {
        if (!mesKey) return;
        var nonce = (document.getElementById('popup-primera-nonce') || {}).value || '';
        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_popup_primera_visto&nonce=' +
                encodeURIComponent(nonce) +
                '&mes_key=' + encodeURIComponent(mesKey)
        }).catch(function () {});
    }

    function close(navigate) {
        el.hidden = true;
        document.body.style.overflow = '';
        if ((chainNext || navigate) && window.billeteraGoToMovimientos) {
            chainNext = false;
            window.billeteraGoToMovimientos();
        }
        chainNext = false;
    }

    function show(d, opts) {
        opts = opts || {};
        chainNext = !!opts.chain;

        var monto = Number(d.monto) || 0;
        coinEl.querySelector('span').textContent = '+' + fmt(monto);
        numEl.textContent = '+S/ ' + fmt(monto);
        chipEl.textContent = 'Nueva moneda · ' + (d.hora || '');
        titleEl.textContent = '¡PRIMERA MONEDA DE ' + (d.mes || '').toUpperCase() + '!';
        msgEl.textContent = (d.saludo || '') + ' Registraste tu primera venta del mes antes que el ' +
            (Number(d.pct) || 0) + '% de los asesores.';

        // Reinicia las animaciones (moneda + chip + rayos).
        var stage = el.querySelector('.popup-primera__stage');
        if (stage) {
            var clone = stage.cloneNode(true);
            stage.parentNode.replaceChild(clone, stage);
            coinEl = clone.querySelector('#popup-primera-coin');
            chipEl = clone.querySelector('#popup-primera-chip');
        }

        el.hidden = false;
        document.body.style.overflow = 'hidden';
        markSeen(d.mes_key);
    }

    function init() {
        if (closeBtn) closeBtn.addEventListener('click', function () { close(false); });
        if (contBtn) contBtn.addEventListener('click', function () { close(false); });
        if (ctaBtn) ctaBtn.addEventListener('click', function () { close(true); });
        if (window.__popupPrimeraTest) {
            show(window.__popupPrimeraTest);
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
