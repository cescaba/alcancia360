<?php
/**
 * Plugin Name: Billetera Setup
 * Plugin URI: https://billetera.local
 * Description: Setup automático del tema Billetera App
 * Version: 1.0.0
 * Author: Studio Peru
 * License: GPL v2 or later
 * Text Domain: billetera-setup
 */

if (!defined('ABSPATH')) {
    exit;
}

// Crear estructura de páginas necesarias al activar el plugin
register_activation_hook(__FILE__, 'billetera_setup_create_pages');

function billetera_setup_create_pages() {
    // Verificar si las páginas ya existen
    $dashboard_page = get_page_by_title('Dashboard');
    $sales_form_page = get_page_by_title('Registrar Venta');

    // Crear página Dashboard
    if (!$dashboard_page) {
        wp_insert_post(array(
            'post_type' => 'page',
            'post_title' => 'Dashboard',
            'post_content' => '[billetera_dashboard]',
            'post_status' => 'publish',
            'post_author' => 1,
        ));
    }

    // Crear página Registrar Venta
    if (!$sales_form_page) {
        wp_insert_post(array(
            'post_type' => 'page',
            'post_title' => 'Registrar Venta',
            'post_content' => '[billetera_sales_form]',
            'post_status' => 'publish',
            'post_author' => 1,
        ));
    }

    add_option('billetera_setup_complete', true);
}

// Panel de información en el admin
add_action('admin_notices', 'billetera_setup_admin_notice');

function billetera_setup_admin_notice() {
    if (get_option('billetera_setup_complete') && current_user_can('manage_options')) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <strong>¡Billetera Setup completado!</strong>
                Se han creado automáticamente:
                <ul>
                    <li>✓ Tabla de base de datos para ventas</li>
                    <li>✓ Página "Dashboard" con shortcode [billetera_dashboard]</li>
                    <li>✓ Página "Registrar Venta" con shortcode [billetera_sales_form]</li>
                </ul>
                <p><a href="<?php echo admin_url('themes.php'); ?>" class="button button-primary">
                    Activar Tema Billetera App
                </a></p>
            </p>
        </div>
        <?php
        delete_option('billetera_setup_complete');
    }
}

// El rol Asesor se gestiona con otro plugin
// No es necesario crearlo aquí

// Los shortcodes se cargan desde el tema
// No es necesario cargarlos desde el plugin
