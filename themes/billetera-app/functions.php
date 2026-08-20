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

// ===== LOGIN PERSONALIZADO BILLETERA 360 =====
add_action('login_head', 'billetera_login_styles');
function billetera_login_styles() {
    ?>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        body { margin: 0; }
        a { color: #0c5c9f; }
        a:hover { color: #1a3d6f; }
        ::placeholder { color: #a8b2bf; }

        body.login {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(160deg, #2d4563, #1a2841);
            font-family: Inter, sans-serif;
            padding: 24px;
            box-sizing: border-box;
        }

        #login {
            width: 380px;
            max-width: 100%;
            background: white;
            border-radius: 24px;
            padding: 40px 32px 32px;
            box-shadow: 0 30px 60px rgba(12, 45, 82, 0.35);
            margin: 0 !important;
            border: none !important;
        }


        .login-header {
            display: flex;
            justify-content: center;
            margin-bottom: 28px;
        }

        .login-header img {
            width: 88px;
            height: 88px;
            border-radius: 20px;
        }

        .login-title {
            font-family: Poppins, sans-serif;
            font-weight: 700;
            font-size: 20px;
            color: #1a2841;
            text-align: center;
            margin: 0 0 4px !important;
            letter-spacing: -0.01em;
            display: block !important;
        }

        .login-subtitle {
            font-family: Inter, sans-serif;
            font-size: 13px;
            color: #7f8ca0;
            text-align: center;
            margin: 0 0 28px !important;
        }

        .login-header {
            display: flex;
            justify-content: center;
            margin-bottom: 28px;
        }

        .login-header img {
            width: 88px;
            height: 88px;
            border-radius: 20px;
        }

        .login h1.login-title {
            font-family: Poppins, sans-serif;
            font-weight: 700;
            font-size: 20px;
            color: #1a2841;
            text-align: center;
            margin: 0 0 4px;
            letter-spacing: -0.01em;
            display: block;
        }

        .login p.login-subtitle {
            font-family: Inter, sans-serif;
            font-size: 13px;
            color: #7f8ca0;
            text-align: center;
            margin: 0 0 28px;
        }

        .login form {
            margin-top: 0 !important;
            border: none !important;
            padding: 2px;
            box-shadow: none !important;
        }

        .login .user-login-wrap {
            margin-bottom: 14px;
        }

        .login .user-pass-wrap {
            margin-bottom: 8px;
            position: relative;
        }
        #backtoblog{
            display:none;
        }
        .login label {
            display: block;
            font-family: Inter, sans-serif;
            font-size: 12px;
            font-weight: 600;
            color: #7f8ca0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 6px !important;
        }

        .login input[type="text"],
        .login input[type="password"] {
            width: 100% !important;
            box-sizing: border-box !important;
            background: #f7f9fb !important;
            border: 1px solid #d9dfe6 !important;
            border-radius: 12px !important;
            padding: 13px 14px !important;
            color: #1a2841 !important;
            font-family: Inter, sans-serif !important;
            font-size: 14px !important;
            outline: none !important;
        }

        .login input[type="text"]:focus,
        .login input[type="password"]:focus {
            border-color: #0c2d52 !important;
            background: white !important;
        }

        .login input::placeholder {
            color: #a8b2bf;
        }

        .login .forgetmenot {
            display: flex;
            justify-content: flex-end;
            margin: 22px 0 16px;
        }

        .login .forgetmenot label {
            display: none;
        }

        .login .forgetmenot input[type="checkbox"] {
            display: none;
        }

        .login .forgetmenot a {
            font-family: Inter, sans-serif;
            font-size: 12.5px;
            font-weight: 500;
            text-decoration: none;
        }

        .login .button.button-primary {
            width: 100% !important;
            background: #0c2d52 !important;
            color: white !important;
            border: none !important;
            border-radius: 14px !important;
            padding: 15px !important;
            font-family: Poppins, sans-serif !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            height: auto !important;
            line-height: 1.4 !important;
        }

        .login .button.button-primary:hover {
            background: #1a3d6f !important;
        }

        .login .error {
            background: #fef2f2;
            border: 1px solid #e5b1b1;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
            font-family: Inter, sans-serif;
            font-size: 12.5px;
            color: #74362f;
        }

        .login .message {
            background: #f0fdf4;
            border: 1px solid #a7f3d0;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 16px;
            font-family: Inter, sans-serif;
            font-size: 12.5px;
            color: #065f46;
        }

        #nav {
            display: none !important;
        }

        .login #nav {
            display: none !important;
        }

        .login-back-link {
            display: none !important;
        }

        .login-footer {
            font-family: Inter, sans-serif;
            font-size: 11.5px;
            color: #a8b2bf;
            text-align: center;
            margin-top: 22px;
            line-height: 1.5;
        }
    </style>
    <?php
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
    $logo_url = get_template_directory_uri() . '/assets/img/logo-gildemeister.png';
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const loginDiv = document.getElementById('login');
        if (!loginDiv) return;

        // Crear el header con logo
        const header = document.createElement('div');
        header.className = 'login-header';
        header.innerHTML = '<img src="<?php echo esc_url($logo_url); ?>" alt="Gildemeister Retail">';

        // Crear título
        const title = document.createElement('h1');
        title.className = 'login-title';
        title.textContent = 'Billetera 360';

        // Crear subtítulo
        const subtitle = document.createElement('p');
        subtitle.className = 'login-subtitle';
        subtitle.textContent = 'Ingresa con tu cuenta de colaborador';

        // Encontrar el form
        const form = loginDiv.querySelector('form');
        if (form) {
            // Insertar antes del form
            form.parentNode.insertBefore(header, form);
            form.parentNode.insertBefore(title, form);
            form.parentNode.insertBefore(subtitle, form);
        }

        // Ocultar el h1 de WordPress
        const h1 = loginDiv.querySelector('h1:first-child');
        if (h1 && h1 !== title) {
            h1.style.display = 'none';
        }

        // Ocultar el nav de WordPress
        const nav = document.getElementById('nav');
        if (nav) {
            nav.style.display = 'none';
        }

        // Agregar footer dentro de #login
        const footer = document.createElement('p');
        footer.className = 'login-footer';
        footer.innerHTML = 'Uso interno · Gildemeister Retail<br>Comunícate con Sistemas si tienes problemas de acceso.';
        loginDiv.appendChild(footer);
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
            return '';
        }
    }
    return $translated;
}

// Redirigir al login a la página de Billetera 360
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

