<?php
/**
 * Utilidades generales para Hyundai Usuarios
 * Shortcodes, redirecciones y control de acceso
 */

if (!defined('ABSPATH')) exit;

class HU_Utilities {

    public function __construct() {
        // Shortcodes
        add_shortcode('cerrar_sesion', [$this, 'cerrar_sesion_shortcode']);
        add_shortcode('cambiar_password', [$this, 'formulario_cambio_password_shortcode']);

        // Formulario personalizado de protección de posts
        add_filter('the_password_form', [$this, 'formulario_proteccion_personalizado']);

        // Control de acceso para suscriptores
        add_action('after_setup_theme', [$this, 'ocultar_barra_suscriptores']);
        add_action('admin_init', [$this, 'redirigir_suscriptores']);

        // Control de acceso para asesor/jefe_venta
        add_action('after_setup_theme', [$this, 'ocultar_barra_usuarios_operacionales']);

        // Ocultar mensaje de confirmación de email a administrador_hyundai (muy temprano)
        add_action('admin_init', [$this, 'ocultar_email_confirmation_admin'], 1);
    }

    /**
     * Shortcode: Cerrar sesión
     */
    public function cerrar_sesion_shortcode() {
        $logout_url = wp_logout_url(home_url());
        return '<a href="' . esc_url($logout_url) . '" class="btn-logout">Cerrar sesión</a>';
    }

    /**
     * Shortcode: Formulario cambio de contraseña
     */
    public function formulario_cambio_password_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>Debes iniciar sesión para cambiar tu contraseña.</p>';
        }

        $html = '
        <form method="post" class="form-cambio-password">
            <p>
                <label for="new_password" style="font-size: 14px;line-height: 1.5;display: inline-block;margin-bottom: 3px;">Nueva contraseña</label><br>
                <input type="password" name="new_password" id="new_password" required>
            </p>
            <p>
                <label for="confirm_password" style="font-size: 14px;line-height: 1.5;display: inline-block;margin-bottom: 3px;">Confirmar contraseña</label><br>
                <input type="password" name="confirm_password" id="confirm_password" required>
            </p>
            <p>
                <input type="submit" style="background: #002C5F;border-color: #002C5F;color: #fff;" name="change_password_submit" value="Cambiar contraseña">
            </p>
        </form>';

        if (isset($_POST['change_password_submit'])) {
            $new_password = sanitize_text_field($_POST['new_password']);
            $confirm_password = sanitize_text_field($_POST['confirm_password']);

            if ($new_password === $confirm_password) {
                $user_id = get_current_user_id();
                wp_set_password($new_password, $user_id);
                wp_logout();
                wp_redirect(home_url('/login/?cambio=ok'));
                exit;
            } else {
                $html .= '<p style="color:red;">Las contraseñas no coinciden.</p>';
            }
        }

        return $html;
    }

    /**
     * Ocultar admin bar a suscriptores
     */
    public function ocultar_barra_suscriptores() {
        if (current_user_can('subscriber')) {
            show_admin_bar(false);
        }
    }

    /**
     * Redirigir suscriptores y gerente fuera del admin
     */
    public function redirigir_suscriptores() {
        $user = wp_get_current_user();
        $roles = (array) $user->roles;

        // Redirigir si es suscriptor o gerente
        if ((in_array('subscriber', $roles, true) || in_array('gerente', $roles, true)) && !defined('DOING_AJAX')) {
            wp_redirect(home_url());
            exit;
        }
    }

    /**
     * Ocultar admin bar a asesor, jefe_venta y gerente
     */
    public function ocultar_barra_usuarios_operacionales() {
        $user = wp_get_current_user();
        $roles = (array) $user->roles;

        if (in_array('asesor', $roles, true) || in_array('jefe_venta', $roles, true) || in_array('gerente', $roles, true)) {
            show_admin_bar(false);
        }
    }

    /**
     * Formulario personalizado para posts protegidos con contraseña
     */
    public function formulario_proteccion_personalizado($output) {
        $output = '
            <div class="protected-wrapper">
                <div class="protected-content">
                    <img decoding="async" src="' . esc_url('https://hyundaiproduct.com/wp-content/uploads/2024/07/cropped-Logo-Hyundai-Main.jpg') . '" alt="Logo" class="protected-logo">
                    <p>Este contenido está protegido. Por favor, introduce la contraseña para verlo:</p>
                    <form action="' . esc_url(site_url('wp-login.php?action=postpass', 'login_post')) . '" method="post">
                        <input name="post_password" type="password" size="20" placeholder="Contraseña" />
                        <input type="submit" name="Submit" value="Acceder" />
                    </form>
                </div>
            </div>
        ';
        return $output;
    }

    /**
     * Ocultar mensaje de confirmación de email a administradores
     */
    public function ocultar_email_confirmation_admin() {
        $user = wp_get_current_user();
        if (!$user->ID) {
            return;
        }

        // Para administrador_hyundai: ocultar completamente el aviso de email
        if (in_array('administrador_hyundai', (array) $user->roles, true)) {
            // Remover el hook que muestra el aviso
            remove_action('admin_notices', 'confirm_admin_email', 0);
            remove_action('admin_notices', 'confirm_admin_email', 10);
            remove_action('admin_notices', 'confirm_admin_email', 999);

            // Limpiar la opción de email pendiente si existe
            delete_option('new_admin_email');
        }
    }
}
