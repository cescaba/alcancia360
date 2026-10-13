<?php
/**
 * Oculta el aviso de All-in-One WP Migration para el rol administrador_hyundai.
 *
 * El plugin All-in-One WP Migration (ServMask) muestra, en cualquier pantalla
 * del admin, el aviso:
 *
 *   "Your current profile role does not have Export/Import capabilities enabled."
 *   ("Tu perfil actual no tiene activadas las capacidades de Exportación/Importación.")
 *
 * Ese aviso se añade cuando el usuario es "super admin" (en sitio simple = tiene
 * la capability `delete_users`, algo que SÍ tiene administrador_hyundai) pero no
 * tiene `export`/`import` + `manage_options`. Como el rol administrador_hyundai
 * está diseñado a propósito sin `manage_options`, el aviso le sale siempre.
 *
 * Aquí lo removemos únicamente para ese rol: ni se registra el callback (evita
 * el aviso por completo) y además dejamos un CSS de respaldo por si el plugin
 * cambia la forma en que lo imprime.
 */

if (!defined('ABSPATH')) exit;

class HU_Admin_Notices {

    const ROLE = 'administrador_hyundai';

    /** Método de All-in-One WP Migration que imprime el aviso. */
    const AI1WM_NOTICE_METHOD = 'missing_role_capability_notice';

    public function __construct() {
        // Debe correr después del admin_init (prioridad 10) de All-in-One,
        // que es donde ese plugin registra el aviso.
        add_action('admin_init', [$this, 'remove_ai1wm_capability_notice'], 20);

        // Respaldo visual: oculta el aviso por su enlace a la KB de ServMask.
        add_action('admin_head', [$this, 'print_hide_css']);
    }

    /**
     * ¿El usuario actual es administrador_hyundai?
     */
    private function is_admin_hyundai() {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }
        return in_array(self::ROLE, (array) $user->roles, true);
    }

    /**
     * Quita el callback que imprime el aviso de AIO (solo para administrador_hyundai).
     */
    public function remove_ai1wm_capability_notice() {
        if (!$this->is_admin_hyundai()) {
            return;
        }

        foreach (['admin_notices', 'network_admin_notices'] as $hook) {
            $this->remove_callback($hook, self::AI1WM_NOTICE_METHOD);
        }
    }

    /**
     * Remueve de un hook cualquier callback [objeto, $method].
     */
    private function remove_callback($hook, $method) {
        global $wp_filter;

        if (empty($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
            return;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = isset($callback['function']) ? $callback['function'] : null;
                if (is_array($fn) && isset($fn[0], $fn[1])
                    && is_object($fn[0]) && $fn[1] === $method) {
                    remove_action($hook, $fn, $priority);
                }
            }
        }
    }

    /**
     * CSS de respaldo: oculta el aviso por el enlace a la KB de ServMask.
     * No depende del idioma ni del nombre de la clase del aviso.
     */
    public function print_hide_css() {
        if (!$this->is_admin_hyundai()) {
            return;
        }
        ?>
        <style>
            .error:has(a[href*="how-to-add-import-and-export-capabilities"]),
            .notice:has(a[href*="how-to-add-import-and-export-capabilities"]),
            .notice-error:has(a[href*="how-to-add-import-and-export-capabilities"]) {
                display: none !important;
            }
        </style>
        <?php
    }
}
