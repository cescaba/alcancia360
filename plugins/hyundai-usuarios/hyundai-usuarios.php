<?php
/**
 * Plugin Name: Hyundai Usuarios
 * Description: Gestión de Concesionarias, Sucursales y asociación de usuarios (Asesores y Jefes de Venta)
 * Version: 1.1.0
 * Author: VC Studio
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) exit;

// Capacidades para dealer y tienda
define('HU_MANAGE_CAP', 'manage_options');

require_once plugin_dir_path(__FILE__) . 'includes/class-hu-roles.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dealer-tienda.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-user-tienda-association.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hu-utilities.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-hu-user-ingreso.php';

// Instanciar las clases
new HU_Roles();
new HU_DealerTienda();
new HU_UserTiendaAssociation();
new HU_Utilities();
new HU_User_Ingreso();

// Activación: crear roles
register_activation_hook(__FILE__, function() {
    do_action('hu_create_roles');
});

// Desactivación: NO eliminamos los roles
register_deactivation_hook(__FILE__, function() {
    // Los roles se mantienen en la BD para no perder asignaciones de usuarios
});
