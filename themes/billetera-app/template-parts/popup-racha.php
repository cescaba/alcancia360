<?php
/**
 * Popup de racha (semana perfecta de días).
 * Se muestra tras registrar la primera venta del último día laboral de la
 * semana (sábado) cuando el asesor vendió los 6 días (Lun-Sáb).
 * La data llega en la respuesta AJAX del registro de venta y el JS la muestra
 * al cerrar el overlay de celebración. Estática: sin data PHP necesaria.
 */
?>
<div class="popup-racha" id="popup-racha" hidden>
    <div class="popup-racha__box">
        <div class="popup-racha__head">
            <button class="popup-racha__close" id="popup-racha-close" type="button" aria-label="Cerrar">✕</button>
            <span class="popup-racha__badge">RACHA</span>
        </div>

        <div class="popup-racha__stage">
            <span class="popup-racha__spark popup-racha__spark--1"></span>
            <span class="popup-racha__spark popup-racha__spark--2"></span>
            <span class="popup-racha__spark popup-racha__spark--3"></span>
            <span class="popup-racha__spark popup-racha__spark--4"></span>
            <svg class="popup-racha__llama" viewBox="0 0 258 250" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path class="popup-racha__llama-outer" fill="#F5C563" d="M128.8 6.72021C147.84 58.2402 219.52 91.8402 228.48 168C237.44 230.72 185.92 253.12 128.8 253.12C67.2 253.12 20.16 224 26.88 163.52C31.36 116.48 60.48 91.8402 71.68 56.0002C89.6 80.6402 96.32 91.8402 103.04 98.5602C103.04 62.7202 112 33.6002 128.8 6.72021Z"/>
                <path class="popup-racha__llama-inner" fill="#E3A94A" d="M128.8 78.3999C143.36 109.76 188.16 132.16 185.92 185.92C183.68 224 159.04 239.68 128.8 239.68C94.08 239.68 69.44 219.52 73.92 181.44C78.4 147.84 114.24 125.44 128.8 78.3999Z"/>
            </svg>
            <img class="popup-racha__pig" src="<?php echo billetera_asset_url('/assets/img/alcancia.svg'); ?>" alt="Alcancía">
        </div>

        <div class="popup-racha__num">6</div>
        <div class="popup-racha__title">¡DÍAS DE RACHA!</div>

        <div class="popup-racha__card">
            <div class="popup-racha__coins" id="popup-racha-coins">
                <?php
                // 6 días laborales: Lun-Sáb. El último es HOY (destacado).
                $dias_labels = ['LU', 'MA', 'MI', 'JU', 'VI', 'HOY'];
                foreach ($dias_labels as $i => $lbl): ?>
                    <div class="popup-racha__coin-slot<?php echo $i === 5 ? ' is-today' : ''; ?>">
                        <span class="popup-racha__coin-label"><?php echo esc_html($lbl); ?></span>
                        <span class="popup-racha__coin"><span>✓</span></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="popup-racha__msg" id="popup-racha-msg"></p>
        </div>

        <button class="popup-racha__cta" id="popup-racha-cta" type="button">CONTINUAR</button>
    </div>
</div>

<script>
// Controlador del popup de racha. window.__popupRachaPending lo llena el JS
// de registro de venta cuando el backend devuelve popup_racha.
window.BilleteraPopupRacha = (function () {
    var el = document.getElementById('popup-racha');
    var closeBtn = document.getElementById('popup-racha-close');
    var ctaBtn = document.getElementById('popup-racha-cta');
    var msgEl = document.getElementById('popup-racha-msg');
    var data = null;

    function markSeen() {
        if (!data || !data.semana) return;
        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_popup_racha_visto&nonce=' +
                encodeURIComponent(window.billetera.nonce_register_sale) +
                '&semana=' + encodeURIComponent(data.semana)
        }).catch(function () {});
    }

    function close() {
        el.hidden = true;
        el.classList.remove('show');
        document.body.style.overflow = '';
        data = null;
    }

    function show(d) {
        data = d;
        msgEl.textContent = '¡Imparable! Vendiste todos los días de la semana. Solo el ' +
            (Number(d.pct) || 0) + '% de los asesores lo logra.';
        el.hidden = false;
        el.classList.add('show');
        document.body.style.overflow = 'hidden';
        markSeen();
    }

    function init() {
        if (closeBtn) closeBtn.addEventListener('click', function () {
            close();
            if (window.billeteraGoToMovimientos) window.billeteraGoToMovimientos();
        });
        if (ctaBtn) ctaBtn.addEventListener('click', function () {
            close();
            if (window.billeteraGoToMovimientos) window.billeteraGoToMovimientos();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    return { show: show };
})();
</script>
