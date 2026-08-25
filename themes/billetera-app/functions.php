<?php
/**
 * Billetera App Theme Functions
 */

// Agregar soporte para características
add_theme_support('title-tag');
add_theme_support('post-thumbnails');
add_theme_support('custom-logo');

// Registrar estilos y scripts
function billetera_scripts() {
    wp_enqueue_style('billetera-style', get_stylesheet_uri());
}
add_action('wp_enqueue_scripts', 'billetera_scripts');

// Las tablas ahora se crean en el plugin billetera-catal
// No es necesario crear tablas aquí

// El rol "asesor" se gestiona con otro plugin
// No es necesario crearlo aquí

// Redirigir usuarios no autenticados al login
function billetera_redirect_unauthenticated() {
    if (!is_user_logged_in() && !is_page(get_option('billetera_login_page'))) {
        if (!in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'))) {
            wp_safe_remote_post(wp_login_url());
            wp_redirect(wp_login_url());
            exit;
        }
    }
}
add_action('template_redirect', 'billetera_redirect_unauthenticated');


// Registrar meta boxes
require get_template_directory() . '/includes/metaboxes.php';

// Helpers del header (racha, notificaciones, iniciales)
require get_template_directory() . '/includes/header-helpers.php';

// Foto de perfil (subida + usermeta)
require get_template_directory() . '/includes/foto-perfil.php';

// ===== LOGIN PERSONALIZADO MI ALCANCIA 360 =====
add_action('login_enqueue_scripts', 'billetera_login_enqueue');
function billetera_login_enqueue() {
    wp_enqueue_style('billetera-fonts', get_template_directory_uri() . '/assets/css/billetera-360-fonts.css', array(), '1.0');
    wp_enqueue_style('billetera-login', get_template_directory_uri() . '/assets/css/billetera-360-login.css', array(), '1.0');
}

add_filter('login_headerurl', 'billetera_login_logo_url');
function billetera_login_logo_url() {
    return home_url();
}

add_filter('login_headertext', 'billetera_login_logo_text');
function billetera_login_logo_text() {
    return '';
}

add_action('login_head', 'billetera_login_js_reorganize');
function billetera_login_js_reorganize() {
    $logo_url     = get_template_directory_uri() . '/assets/img/logo.png';
    $wordmark_url = get_template_directory_uri() . '/assets/img/header-gildemeister.png';
    $alcancia_url = get_template_directory_uri() . '/assets/img/alcancia.svg';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var login = document.getElementById('login');
        if (!login) return;

        var wpLogo = login.querySelector('h1.wp-login-logo');
        if (wpLogo) wpLogo.style.display = 'none';
        var back = document.getElementById('backtoblog');
        if (back) back.style.display = 'none';
        var privacy = login.querySelector('.privacy-policy-page-link');
        if (privacy) privacy.style.display = 'none';

        var hero = document.createElement('div');
        hero.className = 'login-hero';
        hero.innerHTML =
            '<div class="login-brand">' +
                '<img class="login-brand-mark" src="<?php echo esc_url($logo_url); ?>" alt="Gildemeister">' +
                '<span class="login-brand-text">' +
                    '<img class="login-brand-wordmark" src="<?php echo esc_url($wordmark_url); ?>" alt="Mi alcancia 360">' +
                    '<span class="login-brand-sub">Mi alcancia 360 &middot; Postventa</span>' +
                '</span>' +
            '</div>' +
            '<div class="login-hero-main">' +
                '<div class="login-pig"><img src="<?php echo esc_url($alcancia_url); ?>" alt="Mi alcancia 360"></div>' +
                '<div class="login-hero-copy">' +
                    '<div class="login-hero-title">Mi alcancia 360</div>' +
                    '<div class="login-hero-sub">Registro de ventas y comisiones</div>' +
                    '<div class="login-hero-desk-title">Tu comisión,<br>a la vista</div>' +
                    '<div class="login-hero-desk-sub">Registra la venta apenas la cierras y mira crecer tu alcancía. El monto sale del catálogo oficial, sin cálculos manuales.</div>' +
                '</div>' +
            '</div>' +
            '<div class="login-hero-foot">Acceso exclusivo para personal de postventa</div>';

        var body = document.createElement('div');
        body.className = 'login-body';

        var bodyTitle = document.createElement('div');
        bodyTitle.className = 'login-body-title';
        bodyTitle.textContent = 'Iniciar sesión';
        var bodySub = document.createElement('div');
        bodySub.className = 'login-body-sub';
        bodySub.textContent = 'Usa tu DNI o correo corporativo.';
        body.appendChild(bodyTitle);
        body.appendChild(bodySub);

        var error = document.getElementById('login_error');
        var message = login.querySelector('.message');
        if (error) body.appendChild(error);
        if (message) body.appendChild(message);

        var form = document.getElementById('loginform');
        if (form) body.appendChild(form);

        var note = document.createElement('div');
        note.className = 'login-note';
        note.textContent = 'Acceso exclusivo para personal de postventa. Si no tienes usuario, solicítalo a tu jefe de taller.';
        body.appendChild(note);

        login.insertBefore(hero, login.firstChild);
        login.appendChild(body);

        var userInput = document.getElementById('user_login');
        var passInput = document.getElementById('user_pass');
        if (userInput) userInput.setAttribute('placeholder', '45881207 o correo@gildemeister.pe');
        if (passInput) passInput.setAttribute('placeholder', '••••••••');

        var pwBtn = login.querySelector('.wp-pwd .wp-hide-pw');
        if (pwBtn && passInput) {
            var eyeOn = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            var eyeOff = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"></path><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
            pwBtn.innerHTML = eyeOn;
            pwBtn.setAttribute('aria-label', 'Mostrar contraseña');
            pwBtn.addEventListener('click', function() {
                var show = passInput.type === 'password';
                passInput.type = show ? 'text' : 'password';
                pwBtn.innerHTML = show ? eyeOff : eyeOn;
                pwBtn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
            });
        }

        var forgetmenot = form ? form.querySelector('.forgetmenot') : null;
        if (forgetmenot) {
            var tip = forgetmenot.querySelector('.wp-toggletip-wrapper, .wp-toggletip, button[type="button"]');
            if (tip) tip.remove();

            var remember = document.getElementById('rememberme');
            if (remember) remember.checked = true;

            var nav = document.getElementById('nav');
            var lost = nav ? nav.querySelector('.wp-login-lost-password') : null;
            var submit = form.querySelector('.submit');
            var meta = document.createElement('div');
            meta.className = 'login-meta';
            meta.appendChild(forgetmenot);
            if (lost) {
                lost.className = 'login-forgot-link';
                meta.appendChild(lost);
            }
            form.insertBefore(meta, submit);

            if (nav) nav.style.display = 'none';
        }

        var submitBtn = document.getElementById('wp-submit');
        function refreshSubmit() {
            var u = userInput ? userInput.value.trim() : '';
            var p = passInput ? passInput.value : '';
            if (submitBtn) submitBtn.disabled = !(u && p);
        }
        if (userInput) userInput.addEventListener('input', refreshSubmit);
        if (passInput) passInput.addEventListener('input', refreshSubmit);
        refreshSubmit();
    });
    </script>
    <?php
}

add_filter('gettext', 'billetera_translate_login_text', 20, 3);
function billetera_translate_login_text($translated, $original, $domain) {
    if ($domain === 'default') {
        if ($original === 'Username or Email Address') {
            return 'Usuario';
        }
        if ($original === 'Password') {
            return 'Contraseña';
        }
        if ($original === 'Log In') {
            return 'Ingresar';
        }
        if ($original === 'Remember Me') {
            return 'Mantener sesión iniciada';
        }
        if ($original === 'Lost your password?') {
            return '¿Olvidaste tu clave?';
        }
    }
    return $translated;
}

// Redirigir al login a la página de Mi alcancia 360
add_action('wp_login', 'billetera_redirect_after_login', 10, 2);
function billetera_redirect_after_login($_, $user) {
    $user_roles = (array) $user->roles;

    // Jefe de venta → Movimientos; asesor/administrador → Registrar venta
    if (in_array('jefe_venta', $user_roles, true)) {
        $template = 'page-billetera-360-movimientos.php';
    } elseif (array_intersect(array('asesor', 'administrator'), $user_roles)) {
        $template = 'page-billetera-360-responsive.php';
    } else {
        return;
    }

    global $wpdb;
    $page = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
         WHERE {$wpdb->posts}.post_type = 'page'
         AND {$wpdb->posts}.post_status = 'publish'
         AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
         AND {$wpdb->postmeta}.meta_value = %s
         LIMIT 1",
        $template
    ));

    if ($page) {
        wp_redirect(get_permalink($page->ID));
        exit;
    }
}

add_filter( 'login_display_language_dropdown', '__return_false' );
