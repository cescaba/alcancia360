<?php
/**
 * Popup "Meta del mes superada" (solo asesores).
 * Se muestra en el primer ingreso del mes cuando cerró el mes anterior por
 * encima de su meta. Se evalúa en cualquier página (header-app) vía AJAX.
 * Animación: monedas cayendo con rebote + salto del chanchito dorado.
 */
?>
<div class="popup-meta" id="popup-meta" hidden>
    <div class="popup-meta__box">
        <div class="popup-meta__head">
            <button class="popup-meta__close" id="popup-meta-close" type="button" aria-label="Cerrar">✕</button>
            <span class="popup-meta__badge">META DEL MES</span>
        </div>

        <div class="popup-meta__stage">
            <span class="popup-meta__spark popup-meta__spark--1"></span>
            <span class="popup-meta__spark popup-meta__spark--2"></span>
            <span class="popup-meta__spark popup-meta__spark--3"></span>
            <span class="popup-meta__spark popup-meta__spark--4"></span>
            <img class="popup-meta__pig" id="popup-meta-pig" src="<?php echo billetera_asset_url('/assets/img/chancho-dorado.svg'); ?>" alt="Alcancía dorada">
            <?php
            // Monedas S/ del escenario (posición/tamaño del diseño).
            $meta_coins = [
                ['s' => 34, 'x' => 150.6, 'y' => 21.7,  'label' => 1, 'd' => 0.55],
                ['s' => 30, 'x' => 175.08, 'y' => -0.3, 'label' => 1, 'd' => 0.15],
                ['s' => 26, 'x' => 130.2, 'y' => 41.7,  'label' => 1, 'd' => 0.95],
                ['s' => 30, 'x' => 40,    'y' => 202,   'label' => 0, 'd' => 0.35],
                ['s' => 24, 'x' => 70,    'y' => 214,   'label' => 0, 'd' => 1.25],
                ['s' => 30, 'x' => 262,   'y' => 200,   'label' => 0, 'd' => 0.75],
                ['s' => 22, 'x' => 290,   'y' => 214,   'label' => 0, 'd' => 1.05],
            ];
            foreach ($meta_coins as $i => $c): ?>
                <span class="popup-meta__coin<?php echo $c['label'] ? ' popup-meta__coin--labeled' : ''; ?>"
                      style="width:<?php echo $c['s']; ?>px;height:<?php echo $c['s']; ?>px;left:<?php echo $c['x']; ?>px;top:<?php echo $c['y']; ?>px;animation-delay:<?php echo $c['d']; ?>s,<?php echo round($c['d'] + 1.4, 2); ?>s;"><?php if ($c['label']): ?><span>S/</span><?php endif; ?></span>
            <?php endforeach; ?>
        </div>

        <div class="popup-meta__num" id="popup-meta-num">111%</div>
        <div class="popup-meta__title" id="popup-meta-title">¡META DE SEPTIEMBRE SUPERADA!</div>

        <div class="popup-meta__card">
            <div class="popup-meta__progress">
                <div class="popup-meta__progress-row">
                    <span class="popup-meta__progress-saved" id="popup-meta-saved">S/ 0 ahorrados</span>
                    <span class="popup-meta__progress-goal" id="popup-meta-goal">Meta S/ 0</span>
                </div>
                <div class="popup-meta__progress-bar"><div class="popup-meta__progress-fill" id="popup-meta-fill"></div></div>
            </div>
            <p class="popup-meta__msg" id="popup-meta-msg"></p>
        </div>

        <button class="popup-meta__cta" id="popup-meta-cta" type="button">CONTINUAR</button>
    </div>
</div>

<input type="hidden" id="popup-meta-nonce" value="<?php echo wp_create_nonce('billetera_popup_meta'); ?>">

<script>
window.BilleteraPopupMeta = (function () {
    var el = document.getElementById('popup-meta');
    var closeBtn = document.getElementById('popup-meta-close');
    var ctaBtn = document.getElementById('popup-meta-cta');
    var numEl = document.getElementById('popup-meta-num');
    var titleEl = document.getElementById('popup-meta-title');
    var savedEl = document.getElementById('popup-meta-saved');
    var goalEl = document.getElementById('popup-meta-goal');
    var fillEl = document.getElementById('popup-meta-fill');
    var msgEl = document.getElementById('popup-meta-msg');
    var pigEl = document.getElementById('popup-meta-pig');
    var nonceEl = document.getElementById('popup-meta-nonce');

    function fmt(n) {
        return Number(n).toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function markSeen(mesKey) {
        if (!mesKey) return;
        var nonce = nonceEl ? nonceEl.value : (document.getElementById('popup-meta-nonce') || {}).value || '';
        fetch(window.billetera.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=billetera_popup_meta_visto&nonce=' +
                encodeURIComponent(nonce) +
                '&mes_key=' + encodeURIComponent(mesKey)
        }).catch(function () {});
    }

    function close() {
        el.hidden = true;
        document.body.style.overflow = '';
    }

    function show(d) {
        numEl.textContent = (Number(d.pct) || 0) + '%';
        var mesMayus = (d.mes || '').toUpperCase();
        titleEl.textContent = '¡META DE ' + mesMayus + ' SUPERADA!';
        savedEl.textContent = 'S/ ' + fmt(d.ahorrado) + ' ahorrados';
        goalEl.textContent = 'Meta S/ ' + fmt(d.meta);
        fillEl.style.width = Math.min(Number(d.pct) || 0, 100) + '%';
        msgEl.textContent = 'Cerraste ' + (d.mes || '') + ' con S/ ' + fmt(d.ahorrado) +
            ' en tu alcancía. ' + (d.es_mejor_mes ? '¡Tu mejor mes del año!' : '¡Sigue así!');

        // Reinicia las animaciones (drop de monedas + salto del chanchito).
        var stage = el.querySelector('.popup-meta__stage');
        if (stage) {
            var clone = stage.cloneNode(true);
            stage.parentNode.replaceChild(clone, stage);
            pigEl = clone.querySelector('#popup-meta-pig');
        }

        el.hidden = false;
        document.body.style.overflow = 'hidden';
        markSeen(d.mes_key);
    }

    function check() {
        if (!window.billetera || !window.billetera.ajax_url) return;
        fetch(window.billetera.ajax_url + '?action=billetera_popup_meta_check')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success && res.data) {
                    show(res.data);
                }
            })
            .catch(function () {});
    }

    function init() {
        if (!window.__popupMetaEnabled) return; // solo asesores
        if (closeBtn) closeBtn.addEventListener('click', close);
        if (ctaBtn) ctaBtn.addEventListener('click', close);
        if (window.__popupMetaTest) {
            show(window.__popupMetaTest);
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
