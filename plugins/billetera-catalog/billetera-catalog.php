<?php
/**
 * Plugin Name: Billetera Catalog
 * Plugin URI: https://billetera.local
 * Description: Gestión de catálogo de comisiones y categorías
 * Version: 1.0.0
 * Author: Studio Peru
 * License: GPL v2 or later
 * Text Domain: billetera-catalog
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BILLETERA_CATALOG_URL')) {
    define('BILLETERA_CATALOG_URL', plugin_dir_url(__FILE__));
}

// Incluir funciones de importación masiva de usuarios
require_once(plugin_dir_path(__FILE__) . 'includes/bulk-user-import.php');
require_once(plugin_dir_path(__FILE__) . 'includes/csv-import.php');
require_once(plugin_dir_path(__FILE__) . 'includes/admin-import-page.php');

// Panel de administración: Catálogo y Comisiones
require_once(plugin_dir_path(__FILE__) . 'includes/admin-catalog-page.php');

// Resumen semanal de comisiones por correo
require_once(plugin_dir_path(__FILE__) . 'includes/weekly-summary.php');

// Reporte de comisiones por asesor (solo administrador_hyundai)
require_once(plugin_dir_path(__FILE__) . 'includes/admin-reports-page.php');

// Crear tablas de catálogo al activar
register_activation_hook(__FILE__, 'billetera_create_catalog_tables');

// Desagendar los crons del resumen semanal al desactivar
register_deactivation_hook(__FILE__, 'billetera_summary_unschedule');

// Migración en caliente: mantiene el esquema sin reactivar el plugin
add_action('plugins_loaded', 'billetera_maybe_upgrade_schema');

function billetera_maybe_upgrade_schema() {
    if (get_option('billetera_db_version') === '1.5') {
        return;
    }

    global $wpdb;

    // --- 1.2: columna asesor_id en ventas ---
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ventas_table'") == $ventas_table) {
        $column_names = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $ventas_table"), 'Field');
        if (!in_array('asesor_id', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN asesor_id bigint(20)");
        }
        // --- 1.5: bandera de bonificación aplicada (incluye excepción de monto fijo) ---
        if (!in_array('bonus_multiplier', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN bonus_multiplier decimal(3, 1) DEFAULT 1.0");
        }
        if (!in_array('bonus_aplicado', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN bonus_aplicado tinyint(1) NOT NULL DEFAULT 0");
        }
    }

    // --- 1.3: líneas (hyu/hcv/gee/jmc) independientes de las marcas ---
    billetera_create_lineas_table();
    billetera_seed_lineas();
    billetera_migrate_categorias_to_lineas();
    billetera_reset_metas_table();

    // --- 1.4: tabla de cola del resumen semanal por correo ---
    billetera_summary_create_queue_table();

    update_option('billetera_db_version', '1.5');
}

/**
 * Crea la tabla de LÍNEAS (hyu, hcv, gee, jmc) si no existe.
 * Cada línea pertenece a una marca (agrupador).
 */
function billetera_create_lineas_table() {
    global $wpdb;
    $lineas_table = $wpdb->prefix . 'billetera_lineas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$lineas_table'") == $lineas_table) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $lineas_table (
        codigo varchar(3) NOT NULL,
        nombre varchar(100) NOT NULL,
        marca_id mediumint(9) NOT NULL,
        orden_display int(3) DEFAULT 0,
        activo tinyint(1) DEFAULT 1,
        creado_en datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (codigo),
        KEY marca_id (marca_id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

/**
 * Inserta las 4 líneas base mapeadas a su marca (por slug), si la tabla está vacía.
 */
function billetera_seed_lineas() {
    global $wpdb;
    $lineas_table = $wpdb->prefix . 'billetera_lineas';
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    $count = intval($wpdb->get_var("SELECT COUNT(*) FROM $lineas_table"));
    if ($count > 0) {
        return;
    }

    $marca_ids = [];
    foreach ($wpdb->get_results("SELECT id, slug FROM $marcas_table") as $m) {
        $marca_ids[$m->slug] = intval($m->id);
    }

    $lineas = [
        ['hyu', 'Hyundai Autos',    'hyundai', 1],
        ['hcv', 'Hyundai Camiones', 'hyundai', 2],
        ['gee', 'Geely',            'geely',   3],
        ['jmc', 'JMC',              'jmc',     4],
    ];

    foreach ($lineas as $l) {
        if (empty($marca_ids[$l[2]])) {
            continue;
        }
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $lineas_table (codigo, nombre, marca_id, orden_display, activo) VALUES (%s, %s, %d, %d, 1)",
            $l[0], $l[1], $marca_ids[$l[2]], $l[3]
        ));
    }
}

/**
 * Migra categorías de marca_id a linea_codigo.
 * hyundai → hyu, y además clona toda su rama (subcategorías y comisiones) a hcv.
 */
function billetera_migrate_categorias_to_lineas() {
    global $wpdb;
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    if ($wpdb->get_var("SHOW TABLES LIKE '$categorias_table'") != $categorias_table) {
        return;
    }

    $columns = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $categorias_table"), 'Field');
    if (!in_array('linea_codigo', $columns)) {
        $wpdb->query("ALTER TABLE $categorias_table ADD COLUMN linea_codigo varchar(3) NULL AFTER id");
    }

    if (in_array('marca_id', $columns)) {
        $map = [
            'hyundai' => 'hyu',
            'jmc'     => 'jmc',
            'geely'   => 'gee',
        ];
        foreach ($wpdb->get_results("SELECT id, slug FROM $marcas_table") as $m) {
            if (!isset($map[$m->slug])) {
                continue;
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE $categorias_table SET linea_codigo = %s WHERE marca_id = %d AND (linea_codigo IS NULL OR linea_codigo = '')",
                $map[$m->slug], $m->id
            ));
        }

        // Quitar marca_id ANTES de clonar (es NOT NULL y bloquearía los inserts)
        $pending = intval($wpdb->get_var("SELECT COUNT(*) FROM $categorias_table WHERE linea_codigo IS NULL OR linea_codigo = ''"));
        if ($pending > 0) {
            return; // Hay filas sin línea; no continuar para no perder datos
        }

        $wpdb->query("ALTER TABLE $categorias_table DROP INDEX categoria_unica");
        $wpdb->query("ALTER TABLE $categorias_table DROP INDEX marca_id");
        $wpdb->query("ALTER TABLE $categorias_table DROP COLUMN marca_id");
        $wpdb->query("ALTER TABLE $categorias_table ADD UNIQUE KEY categoria_unica (linea_codigo, slug)");
        $wpdb->query("ALTER TABLE $categorias_table ADD KEY linea_codigo (linea_codigo)");

        // Clonar la rama de hyu hacia hcv
        $hyu_cats = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM $categorias_table WHERE linea_codigo = %s", 'hyu'
        ));
        foreach ($hyu_cats as $cat_id) {
            billetera_clone_categoria((int) $cat_id, 'hcv');
        }
    }
}

/**
 * Clona una categoría (con sus subcategorías y comisiones) a otra línea.
 */
function billetera_clone_categoria($categoria_id, $target_linea) {
    global $wpdb;
    $cat_table = $wpdb->prefix . 'billetera_categorias';
    $sub_table = $wpdb->prefix . 'billetera_subcategorias';
    $com_table = $wpdb->prefix . 'billetera_comisiones';

    $cat = $wpdb->get_row($wpdb->prepare("SELECT * FROM $cat_table WHERE id = %d", $categoria_id));
    if (!$cat) {
        return;
    }

    // Evitar duplicar si ya existe esa línea+slug
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $cat_table WHERE linea_codigo = %s AND slug = %s",
        $target_linea, $cat->slug
    ));
    if ($exists) {
        return;
    }

    $wpdb->insert($cat_table, [
        'linea_codigo'  => $target_linea,
        'nombre'        => $cat->nombre,
        'slug'          => $cat->slug,
        'orden_display' => $cat->orden_display,
        'activo'        => $cat->activo,
    ], ['%s', '%s', '%s', '%d', '%d']);
    $new_cat_id = intval($wpdb->insert_id);
    if (!$new_cat_id) {
        return;
    }

    foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $sub_table WHERE categoria_id = %d", $categoria_id)) as $sub) {
        $wpdb->insert($sub_table, [
            'categoria_id'  => $new_cat_id,
            'nombre'        => $sub->nombre,
            'por_unidad'    => $sub->por_unidad,
            'orden_display' => $sub->orden_display,
            'activo'        => $sub->activo,
        ], ['%d', '%s', '%d', '%d', '%d']);
        $new_sub_id = intval($wpdb->insert_id);
        if (!$new_sub_id) {
            continue;
        }

        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $com_table WHERE subcategoria_id = %d", $sub->id)) as $com) {
            $wpdb->insert($com_table, [
                'subcategoria_id' => $new_sub_id,
                'distribuidor_id' => $com->distribuidor_id,
                'rol'             => $com->rol,
                'monto'           => $com->monto,
                'moneda'          => $com->moneda,
                'activo'          => $com->activo,
            ], ['%d', '%d', '%s', '%f', '%s', '%d']);
        }
    }
}

/**
 * Recrea la tabla de METAS con linea_codigo (resetea datos).
 */
function billetera_reset_metas_table() {
    global $wpdb;
    $metas_table = $wpdb->prefix . 'billetera_metas';
    $wpdb->query("DROP TABLE IF EXISTS $metas_table");
    billetera_create_metas_table();
}

/**
 * Esquema de METAS: sucursal x línea x tipo (Venta / Post Venta).
 */
function billetera_create_metas_table() {
    global $wpdb;
    $metas_table = $wpdb->prefix . 'billetera_metas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$metas_table'") == $metas_table) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $metas_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        tienda_id bigint(20) NOT NULL,
        linea_codigo varchar(3) NOT NULL,
        tipo varchar(20) NOT NULL,
        meta decimal(10, 2) NOT NULL DEFAULT 0,
        creado_en datetime DEFAULT CURRENT_TIMESTAMP,
        actualizado_en datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY cruce_unico (tienda_id, linea_codigo, tipo),
        KEY tienda_id (tienda_id),
        KEY linea_codigo (linea_codigo)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function billetera_create_catalog_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Tabla de MARCAS
    $marcas_table = $wpdb->prefix . 'billetera_marcas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$marcas_table'") != $marcas_table) {
        $sql = "CREATE TABLE $marcas_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            nombre varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Tabla de LÍNEAS (hyu/hcv/gee/jmc) — cada una agrupada en una marca
    billetera_create_lineas_table();
    billetera_seed_lineas();

    // Tabla de CATEGORÍAS (pertenecen a una línea, no a la marca)
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$categorias_table'") != $categorias_table) {
        $sql = "CREATE TABLE $categorias_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            linea_codigo varchar(3) NOT NULL,
            nombre varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            orden_display int(3) DEFAULT 0,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY linea_codigo (linea_codigo),
            UNIQUE KEY categoria_unica (linea_codigo, slug)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Tabla de SUBCATEGORÍAS
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$subcategorias_table'") != $subcategorias_table) {
        $sql = "CREATE TABLE $subcategorias_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            categoria_id mediumint(9) NOT NULL,
            nombre varchar(150) NOT NULL,
            por_unidad tinyint(1) DEFAULT 0,
            orden_display int(3) DEFAULT 0,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY categoria_id (categoria_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Tabla de COMISIONES (espejo del Excel)
    // distribuidor_id = NULL significa comisión genérica/base (TODOS)
    // distribuidor_id = post_id significa comisión específica para ese dealer
    $comisiones_table = $wpdb->prefix . 'billetera_comisiones';
    if ($wpdb->get_var("SHOW TABLES LIKE '$comisiones_table'") != $comisiones_table) {
        $sql = "CREATE TABLE $comisiones_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            subcategoria_id mediumint(9) NOT NULL,
            distribuidor_id bigint(20),
            rol varchar(50) NOT NULL,
            monto decimal(10, 2) NOT NULL,
            moneda varchar(3) NOT NULL,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY subcategoria_id (subcategoria_id),
            KEY distribuidor_id (distribuidor_id),
            KEY rol (rol),
            UNIQUE KEY comision_unica (subcategoria_id, distribuidor_id, rol)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Tabla de VENTAS (registro de ventas de asesores - TODO en SOL)
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ventas_table'") != $ventas_table) {
        $sql = "CREATE TABLE $ventas_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            usuario_id bigint(20) NOT NULL,
            asesor_id bigint(20),
            subcategoria_id mediumint(9) NOT NULL,
            cantidad int(11) DEFAULT 1,
            monto_comision_sol decimal(10, 2) NOT NULL,
            id_type varchar(50),
            id_value varchar(255),
            bonus_multiplier decimal(3, 1) DEFAULT 1.0,
            bonus_aplicado tinyint(1) NOT NULL DEFAULT 0,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY usuario_id (usuario_id),
            KEY subcategoria_id (subcategoria_id),
            KEY creado_en (creado_en)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    } else {
        // Agregar columnas si no existen
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $ventas_table");
        $column_names = array_column((array) $columns, 'Field');

        if (!in_array('id_type', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN id_type varchar(50)");
        }

        if (!in_array('id_value', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN id_value varchar(255)");
        }

        if (!in_array('bonus_multiplier', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN bonus_multiplier decimal(3, 1) DEFAULT 1.0");
        }

        if (!in_array('bonus_aplicado', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN bonus_aplicado tinyint(1) NOT NULL DEFAULT 0");
        }

        if (!in_array('asesor_id', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN asesor_id bigint(20)");
        }
    }

    // Tabla de CONFIGURACIÓN (tipo de cambio, etc)
    $configuracion_table = $wpdb->prefix . 'billetera_configuracion';
    if ($wpdb->get_var("SHOW TABLES LIKE '$configuracion_table'") != $configuracion_table) {
        $sql = "CREATE TABLE $configuracion_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            clave varchar(100) NOT NULL,
            valor longtext NOT NULL,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY clave (clave)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Insertar configuración por defecto
    billetera_insert_default_config();

    // Tabla de METAS por sucursal x línea x tipo (Venta / Post Venta)
    billetera_create_metas_table();

    // Tabla de cola del resumen semanal por correo
    billetera_summary_create_queue_table();
}

function billetera_insert_default_config() {
    global $wpdb;
    $configuracion_table = $wpdb->prefix . 'billetera_configuracion';

    $defaults = [
        'tipo_cambio_usd_sol' => '3.5',
        'meta_asesor' => '1200',
    ];

    foreach ($defaults as $clave => $valor) {
        $existe = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $configuracion_table WHERE clave = %s",
            $clave
        ));

        if (!$existe) {
            $wpdb->insert($configuracion_table, [
                'clave' => $clave,
                'valor' => $valor,
            ], ['%s', '%s']);
        }
    }
}

// Helper: Obtener líneas (hyu/hcv/gee/jmc) permitidas para un usuario
function billetera_get_lineas_permitidas($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    // Mapeo 1:1 de permisos a códigos de línea
    $permisos = [
        'billetera_hyu' => 'hyu',
        'billetera_hcv' => 'hcv',
        'billetera_gee' => 'gee',
        'billetera_jmc' => 'jmc',
    ];

    $codigos = [];

    foreach ($permisos as $meta_key => $codigo) {
        if (get_user_meta($user_id, $meta_key, true)) {
            $codigos[] = $codigo;
        }
    }

    if (empty($codigos)) {
        return [];
    }

    global $wpdb;
    $lineas_table = $wpdb->prefix . 'billetera_lineas';
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    $placeholders = implode(',', array_fill(0, count($codigos), '%s'));
    $lineas = $wpdb->get_results($wpdb->prepare("
        SELECT l.codigo, l.nombre, l.marca_id, l.orden_display,
               m.nombre AS marca_nombre, m.slug AS marca_slug
        FROM $lineas_table l
        LEFT JOIN $marcas_table m ON l.marca_id = m.id
        WHERE l.activo = 1 AND l.codigo IN ($placeholders)
        ORDER BY l.orden_display ASC, l.nombre ASC
    ", ...$codigos));

    return $lineas ?: [];
}

// Alias de compatibilidad: devuelve las líneas permitidas
function billetera_get_marcas_permitidas($user_id = null) {
    return billetera_get_lineas_permitidas($user_id);
}

// AJAX: Obtener líneas (filtradas por permisos del usuario)
add_action('wp_ajax_billetera_get_lineas', 'billetera_ajax_get_lineas');
add_action('wp_ajax_nopriv_billetera_get_lineas', 'billetera_ajax_get_lineas');
// Alias por compatibilidad con clientes previos
add_action('wp_ajax_billetera_get_marcas', 'billetera_ajax_get_lineas');
add_action('wp_ajax_nopriv_billetera_get_marcas', 'billetera_ajax_get_lineas');

function billetera_ajax_get_lineas() {
    $user_id = get_current_user_id();
    $lineas = billetera_get_lineas_permitidas($user_id);
    wp_send_json_success($lineas);
}

// AJAX: Obtener categorías por línea
add_action('wp_ajax_billetera_get_categorias', 'billetera_ajax_get_categorias');
add_action('wp_ajax_nopriv_billetera_get_categorias', 'billetera_ajax_get_categorias');

function billetera_ajax_get_categorias() {
    global $wpdb;
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    $linea_codigo = sanitize_text_field($_POST['linea_codigo'] ?? '');
    $user_id = get_current_user_id();

    if (!$linea_codigo) {
        wp_send_json_error(['message' => 'Línea inválida']);
    }

    // Validar que el usuario tiene permiso para esta línea
    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    if (!in_array($linea_codigo, $codigos_permitidos, true)) {
        wp_send_json_error(['message' => 'No tienes permiso para acceder a esta línea']);
    }

    $categorias = $wpdb->get_results($wpdb->prepare("
        SELECT id, nombre, slug
        FROM $categorias_table
        WHERE linea_codigo = %s AND activo = 1
        ORDER BY orden_display ASC
    ", $linea_codigo));

    wp_send_json_success($categorias);
}

// AJAX: Obtener subcategorías de una categoría
add_action('wp_ajax_billetera_get_subcategorias', 'billetera_ajax_get_subcategorias');
add_action('wp_ajax_nopriv_billetera_get_subcategorias', 'billetera_ajax_get_subcategorias');

function billetera_ajax_get_subcategorias() {
    global $wpdb;
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    $categoria_id = intval($_POST['categoria_id'] ?? 0);
    $user_id = get_current_user_id();

    if (!$categoria_id) {
        wp_send_json_error(['message' => 'ID de categoría inválido']);
    }

    // Obtener la línea de la categoría
    $categoria = $wpdb->get_row($wpdb->prepare(
        "SELECT linea_codigo FROM $categorias_table WHERE id = %d",
        $categoria_id
    ));

    if (!$categoria) {
        wp_send_json_error(['message' => 'Categoría no encontrada']);
    }

    // Validar que el usuario tiene permiso para esta línea
    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    if (!in_array($categoria->linea_codigo, $codigos_permitidos, true)) {
        wp_send_json_error(['message' => 'No tienes permiso para acceder a esta categoría']);
    }

    $subcategorias = $wpdb->get_results($wpdb->prepare("
        SELECT id, nombre, por_unidad
        FROM $subcategorias_table
        WHERE categoria_id = %d AND activo = 1
        ORDER BY orden_display ASC
    ", $categoria_id));

    // Convertir por_unidad a int
    foreach ($subcategorias as $sub) {
        $sub->por_unidad = (int) $sub->por_unidad;
    }

    wp_send_json_success($subcategorias);
}

// AJAX: Registrar venta
add_action('wp_ajax_billetera_register_sale_v2', 'billetera_ajax_register_sale_v2');

function billetera_ajax_register_sale_v2() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_register_sale')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    $allowed_roles = ['asesor', 'administrator', 'jefe_venta'];

    if (!array_intersect($allowed_roles, $current_user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    global $wpdb;
    $subcategoria_id = intval($_POST['subcategoria_id'] ?? 0);
    $cantidad = intval($_POST['cantidad'] ?? 1);
    $id_type = sanitize_text_field($_POST['id_type'] ?? '');
    $id_value = sanitize_text_field($_POST['id_value'] ?? '');
    $fecha = sanitize_text_field($_POST['fecha'] ?? date('Y-m-d'));
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    if (!$subcategoria_id || $cantidad < 1 || !$id_type || !$id_value) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    // Validar que el usuario tiene permiso para esta subcategoría (mediante su línea)
    $sub_linea = $wpdb->get_row($wpdb->prepare(
        "SELECT c.linea_codigo FROM $subcategorias_table s
         INNER JOIN $categorias_table c ON s.categoria_id = c.id
         WHERE s.id = %d",
        $subcategoria_id
    ));

    if (!$sub_linea) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    if (!in_array($sub_linea->linea_codigo, $codigos_permitidos, true)) {
        wp_send_json_error(['message' => 'No tienes permiso para registrar ventas de este producto']);
    }

    // Validar que la fecha sea válida y no sea futura
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        wp_send_json_error(['message' => 'Formato de fecha inválido']);
    }
    $fecha_timestamp = strtotime($fecha);
    if ($fecha_timestamp > strtotime(date('Y-m-d'))) {
        wp_send_json_error(['message' => 'No puedes registrar ventas futuras']);
    }
    if ($fecha_timestamp === false) {
        wp_send_json_error(['message' => 'Fecha inválida']);
    }
    $fecha_datetime = date('Y-m-d H:i:s', $fecha_timestamp);

    // Obtener rol del usuario
    $user_role = $current_user->roles[0];

    // Obtener distribuidor_id del usuario
    // Usuario → meta '_tienda_asociada' → tienda_id
    // Tienda → meta '_dealer_id' → distribuidor_id
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    $distribuidor_id = null;

    if ($tienda_id) {
        $distribuidor_id = get_post_meta($tienda_id, '_dealer_id', true);
    }

    // Obtener comisión: primero específica del distribuidor, luego la genérica (NULL)
    $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, $user_role);

    if (!$comision) {
        wp_send_json_error(['message' => 'Comisión no encontrada']);
    }

    // Convertir a SOL y multiplicar por cantidad
    $monto_total_sol = billetera_convert_to_sol($comision) * $cantidad;

    // Aplicar reglas de bonificación (categoría, umbral, multiplicador y excepción)
    $regla_bonus = billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $distribuidor_id, $cantidad, true, $fecha_datetime);
    if ($regla_bonus['override_sol'] !== null) {
        // Excepción "reemplazar"
        $monto_total_sol  = $regla_bonus['override_sol'];
        $bonus_multiplier = 1.0;
    } elseif ($regla_bonus['add_sol'] !== null) {
        // Excepción "sumar": comisión base + fijo (sin multiplicador)
        $monto_total_sol  = ($monto_total_sol * $regla_bonus['multiplier']) + $regla_bonus['add_sol'];
        $bonus_multiplier = 1.0;
    } else {
        $bonus_multiplier = $regla_bonus['multiplier'];
    }
    $bonus_aplicado = ($bonus_multiplier > 1 || !empty($regla_bonus['excepcion'])) ? 1 : 0;

    // Guardar venta en nueva tabla
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    $result = $wpdb->insert($ventas_table, [
        'usuario_id' => $user_id,
        'subcategoria_id' => $subcategoria_id,
        'cantidad' => $cantidad,
        'monto_comision_sol' => $monto_total_sol,
        'id_type' => $id_type,
        'id_value' => $id_value,
        'bonus_multiplier' => $bonus_multiplier,
        'bonus_aplicado' => $bonus_aplicado,
        'creado_en' => $fecha_datetime,
    ], ['%d', '%d', '%d', '%f', '%s', '%s', '%f', '%d', '%s']);

    if ($result) {
        // Registrar la comisión del jefe de venta de la sucursal (con la misma regla de bonificación)
        billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $user_id, $fecha_datetime, $regla_bonus['jefe_multiplier'], $regla_bonus['jefe_override_sol'], $regla_bonus['jefe_add_sol']);

        // El ranking del mes cambió: invalida la caché
        billetera_rank_cache_bust();

        $monto_mostrado = $monto_total_sol * $bonus_multiplier;
        wp_send_json_success([
            'message' => 'Venta registrada',
            'amount' => floatval($monto_mostrado),
            'id_type_label' => ucfirst($id_type),
            'id_value' => $id_value,
            'bonus_multiplier' => floatval($bonus_multiplier),
            'bonus_categoria' => $regla_bonus['categoria_nombre'],
            'bonus_aplicado' => ($regla_bonus['multiplier'] > 1 || !empty($regla_bonus['excepcion'])) ? 1 : 0,
        ]);
    } else {
        wp_send_json_error(['message' => 'Error al registrar venta']);
    }
}

function billetera_get_tipo_cambio() {
    global $wpdb;
    $configuracion_table = $wpdb->prefix . 'billetera_configuracion';

    $tipo_cambio = $wpdb->get_var($wpdb->prepare(
        "SELECT valor FROM $configuracion_table WHERE clave = %s",
        'tipo_cambio_usd_sol'
    ));

    return floatval($tipo_cambio ?? 3.5);
}

function billetera_get_meta_asesor() {
    global $wpdb;
    $configuracion_table = $wpdb->prefix . 'billetera_configuracion';

    $meta = $wpdb->get_var($wpdb->prepare(
        "SELECT valor FROM $configuracion_table WHERE clave = %s",
        'meta_asesor'
    ));

    return floatval($meta ?? 1200);
}

// Meta por usuario: suma de wp_billetera_metas por su tienda + marcas + tipo.
// Fallback a meta global (1200) si no tiene tienda/tipo/marcas o no hay fila.
function billetera_get_meta_por_usuario($user_id) {
    $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
    $tipo = trim((string) get_user_meta($user_id, 'billetera_tipo', true));

    if (!$tienda_id || $tipo === '') {
        return billetera_get_meta_asesor();
    }

    $lineas = billetera_get_lineas_permitidas($user_id);
    if (empty($lineas)) {
        return billetera_get_meta_asesor();
    }
    $codigos = array_values(array_filter(array_column((array) $lineas, 'codigo')));
    if (empty($codigos)) {
        return billetera_get_meta_asesor();
    }

    global $wpdb;
    $metas_table = $wpdb->prefix . 'billetera_metas';
    $placeholders = implode(',', array_fill(0, count($codigos), '%s'));
    $params = array_merge([$tienda_id, $tipo], $codigos);
    $query = "SELECT SUM(meta) FROM $metas_table WHERE tienda_id = %d AND tipo = %s AND linea_codigo IN ($placeholders)";
    $sum = $wpdb->get_var($wpdb->prepare($query, ...$params));

    if ($sum === null || floatval($sum) <= 0) {
        return billetera_get_meta_asesor();
    }

    return floatval($sum);
}

function billetera_find_comision($subcategoria_id, $distribuidor_id, $rol) {
    global $wpdb;
    $comisiones_table = $wpdb->prefix . 'billetera_comisiones';

    if ($distribuidor_id) {
        $comision = $wpdb->get_row($wpdb->prepare("
            SELECT c.monto, c.moneda
            FROM $comisiones_table c
            WHERE c.subcategoria_id = %d
            AND c.distribuidor_id = %d
            AND c.rol = %s
            AND c.activo = 1
            LIMIT 1
        ", $subcategoria_id, $distribuidor_id, $rol));

        if ($comision) {
            return $comision;
        }
    }

    return $wpdb->get_row($wpdb->prepare("
        SELECT c.monto, c.moneda
        FROM $comisiones_table c
        WHERE c.subcategoria_id = %d
        AND c.distribuidor_id IS NULL
        AND c.rol = %s
        AND c.activo = 1
        LIMIT 1
    ", $subcategoria_id, $rol));
}

function billetera_convert_to_sol($comision) {
    $monto_sol = floatval($comision->monto);
    if ($comision->moneda === 'USD') {
        $monto_sol = $monto_sol * billetera_get_tipo_cambio();
    }
    return $monto_sol;
}

// ============================================================================
// REGLAS DE BONIFICACIÓN (configurables desde el admin)
// Cada regla elige una categoría (toda la categoría o solo una línea), un
// umbral, un multiplicador y una excepción opcional por concesionaria
// (monto fijo en USD por unidad, para el asesor y para el jefe).
// ============================================================================

/**
 * Lee las reglas de bonificación (opción). Siembra las reglas por defecto la 1.ª vez.
 */
function billetera_bonus_reglas() {
    $reglas = get_option('billetera_bonus_reglas', null);
    if ($reglas === null) {
        $reglas = billetera_bonus_seed_reglas();
    }
    return is_array($reglas) ? $reglas : [];
}

/**
 * Reglas por defecto (Prepagados y Kit360) para no perder la config actual.
 */
function billetera_bonus_seed_reglas() {
    global $wpdb;
    $cat    = $wpdb->prefix . 'billetera_categorias';
    $reglas = [];

    $base = [
        'ambito'                 => 'categoria',
        'activo'                 => 1,
        'umbral'                 => 6,
        'multiplicador'          => 2,
        'aplica_jefe'            => 1,
        'excepcion_dealer_id'    => 0,
        'excepcion_usd'          => 0,
        'excepcion_jefe_usd'     => 0,
        'excepcion_moneda'       => 'USD',
        'excepcion_modo'         => 'reemplazar',
        'excepcion_desde_umbral' => 1,
    ];

    $pre = $wpdb->get_row("SELECT id, nombre, slug, linea_codigo FROM $cat WHERE LOWER(slug) = 'prepagados' OR LOWER(nombre) = 'prepagados' ORDER BY id ASC LIMIT 1");
    if ($pre) {
        $reglas[] = array_merge($base, [
            'id'            => 'r_prepagados',
            'categoria_id'  => intval($pre->id),
            'slug'          => $pre->slug,
            'nombre'        => $pre->nombre,
            'linea_codigo'  => $pre->linea_codigo,
            'umbral'        => max(1, (int) get_option('billetera_bonus_prepagados_umbral', 6)),
            'multiplicador' => (float) get_option('billetera_bonus_prepagados_multiplicador', 2),
            'aplica_jefe'   => (int) get_option('billetera_bonus_prepagados_jefe', 1),
        ]);
    }

    $kit = $wpdb->get_row("SELECT id, nombre, slug, linea_codigo FROM $cat WHERE LOWER(slug) = 'kit360' OR LOWER(nombre) = 'kit360' ORDER BY id ASC LIMIT 1");
    if ($kit) {
        $reglas[] = array_merge($base, [
            'id'                     => 'r_kit360',
            'categoria_id'           => intval($kit->id),
            'slug'                   => $kit->slug,
            'nombre'                 => $kit->nombre,
            'linea_codigo'           => $kit->linea_codigo,
            'umbral'                 => max(1, (int) get_option('billetera_bonus_kit360_umbral', 6)),
            'multiplicador'          => (float) get_option('billetera_bonus_kit360_multiplicador', 2),
            'aplica_jefe'            => (int) get_option('billetera_bonus_kit360_jefe', 1),
            'excepcion_dealer_id'    => (int) get_option('billetera_bonus_kit360_excepcion_dealer', 0),
            'excepcion_usd'          => (float) get_option('billetera_bonus_kit360_excepcion_usd', 100),
            'excepcion_jefe_usd'     => (float) get_option('billetera_bonus_kit360_excepcion_jefe_usd', 50),
            'excepcion_desde_umbral' => (int) get_option('billetera_bonus_kit360_excepcion_desde_umbral', 1),
        ]);
    }

    update_option('billetera_bonus_reglas', $reglas, false);
    return $reglas;
}

/**
 * Vigencia de campaña (reusa fechas del banner): fuera de [desde,hasta]
 * el bono no aplica. Vacío = sin límite.
 */
function billetera_regla_vigente_en($regla, $fecha_ymd) {
    $fecha = substr((string) $fecha_ymd, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        return true;
    }
    $desde = (string) ($regla['banner_desde'] ?? '');
    $hasta = (string) ($regla['banner_hasta'] ?? '');
    if ($desde !== '' && $fecha < $desde) {
        return false;
    }
    if ($hasta !== '' && $fecha > $hasta) {
        return false;
    }
    return true;
}

/**
 * Busca la regla activa que aplica a una categoría según su ámbito.
 *
 * Prioridad:
 *   1. Regla de ámbito "linea" cuya categoría coincide exactamente.
 *   2. Regla de ámbito "categoria" cuya categoría coincide exactamente.
 *   3. Regla de ámbito "categoria" que coincide por slug (se toma la última,
 *      es decir la más reciente, para que una regla nueva no quede
 *      ensombrecida por una regla por defecto con el mismo slug).
 */

function billetera_bonus_find_regla($categoria_id, $slug, $fecha_ymd = null) {
    $categoria_id = intval($categoria_id);
    $slug         = strtolower((string) $slug);
    if ($fecha_ymd === null) {
        $fecha_ymd = billetera_stats_local_now()->format('Y-m-d');
    }

    $exact_cat = null;
    $by_slug   = null;

    foreach (billetera_bonus_reglas() as $r) {
        if (empty($r['activo'])) {
            continue;
        }
        if (!billetera_regla_vigente_en($r, $fecha_ymd)) {
            continue;
        }
        $rid    = intval($r['categoria_id'] ?? 0);
        $ambito = $r['ambito'] ?? 'categoria';

        if ($ambito === 'linea') {
            // La regla de línea exacta es la más específica: gana de inmediato.
            if ($rid === $categoria_id) {
                return $r;
            }
            continue;
        }

        if (strtolower($r['slug'] ?? '') === $slug) {
            if ($rid === $categoria_id && $exact_cat === null) {
                $exact_cat = $r;
            }
            $by_slug = $r; // nos quedamos con la última coincidencia
        }
    }

    return $exact_cat !== null ? $exact_cat : $by_slug;
}

/**
 * Condición SQL de categoría para una regla: [sql, params].
 * 'categoria' = todas las líneas (por slug); 'linea' = solo esa categoría.
 */
function billetera_bonus_cond($regla) {
    if (($regla['ambito'] ?? 'categoria') === 'linea') {
        return ['c.id = %d', [intval($regla['categoria_id'])]];
    }
    $m = strtolower($regla['slug'] ?? '');
    return ['(LOWER(c.slug) = %s OR LOWER(c.nombre) = %s)', [$m, $m]];
}

/**
 * Calcula la regla de bonificación para una venta y, si corresponde,
 * aplica el ajuste retroactivo de las ventas anteriores del mes.
 *
 * Devuelve:
 *   multiplier, override_sol (asesor) y jefe_multiplier, jefe_override_sol.
 *   override_sol reemplaza el monto base; si es null se usa el del catálogo.
 */
function billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $dealer_id, $cantidad, $aplicar_retro = true, $fecha_datetime = null) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';

    $out = [
        'multiplier'        => 1.0,
        'override_sol'      => null,
        'add_sol'           => null,
        'jefe_multiplier'   => 1.0,
        'jefe_override_sol' => null,
        'jefe_add_sol'      => null,
        'regla'             => null,
        'excepcion'         => false,
        'categoria_nombre'  => null,
    ];

    $catrow = $wpdb->get_row($wpdb->prepare("
        SELECT c.id, c.slug, c.nombre
        FROM $sub s
        JOIN $cat c ON c.id = s.categoria_id
        WHERE s.id = %d
    ", $subcategoria_id));

    if (!$catrow) {
        return $out;
    }

    $fecha_ymd = $fecha_datetime ? substr((string) $fecha_datetime, 0, 10) : billetera_stats_local_now()->format('Y-m-d');
    $regla = billetera_bonus_find_regla($catrow->id, $catrow->slug, $fecha_ymd);
    if (!$regla) {
        return $out;
    }
    $out['regla']            = $regla['id'];
    $out['categoria_nombre'] = $regla['nombre'] ?? $catrow->nombre;

    list($cond, $cond_params) = billetera_bonus_cond($regla);

    $mes_inicio = substr($fecha_ymd, 0, 7) . '-01 00:00:00';
    // El conteo del umbral solo suma ventas dentro de la vigencia.
    $desde_vig = '';
    if (!empty($regla['banner_desde'])) {
        $desde_vig = (string) $regla['banner_desde'] . ' 00:00:00';
    }
    $inicio = $desde_vig !== '' && $desde_vig > $mes_inicio ? $desde_vig : $mes_inicio;
    $params     = array_merge([$user_id], $cond_params, [$inicio]);
    $count      = (int) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(v.id)
        FROM $ventas v
        JOIN $sub s ON v.subcategoria_id = s.id
        JOIN $cat c ON s.categoria_id = c.id
        WHERE v.usuario_id = %d
          AND $cond
          AND v.creado_en >= %s
    ", ...$params));

    $umbral   = max(1, (int) $regla['umbral']);
    $califica = ($count >= $umbral - 1);
    $retro    = ($count === $umbral - 1);

    $es_excepcion  = (intval($regla['excepcion_dealer_id']) > 0 && intval($dealer_id) === intval($regla['excepcion_dealer_id']));
    $usa_excepcion = $es_excepcion && (!empty($regla['excepcion_desde_umbral']) ? $califica : true);
    $tc            = billetera_get_tipo_cambio();

    if ($usa_excepcion && (float) $regla['excepcion_usd'] > 0) {
        $out['excepcion'] = true;
        $modo       = (($regla['excepcion_modo'] ?? 'reemplazar') === 'sumar') ? 'sumar' : 'reemplazar';
        $factor     = (($regla['excepcion_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc;
        $fixed      = round((float) $regla['excepcion_usd'] * $factor * $cantidad, 2);
        $fixed_jefe = round((float) $regla['excepcion_jefe_usd'] * $factor * $cantidad, 2);
        $con_jefe   = !empty($regla['aplica_jefe']);

        if ($modo === 'sumar') {
            // Excepción "sumar": la excepción anula el multiplicador, así que el
            // monto queda como comisión base + monto fijo (base × 1 + fijo).
            $out['multiplier'] = 1.0;
            $out['add_sol']    = $fixed;

            // El jefe también pierde el multiplicador: recibe su comisión base + su monto fijo.
            // Si su monto fijo es 0, queda con su comisión normal (sin bono).
            $jefe_fijo              = ($con_jefe && (float) $regla['excepcion_jefe_usd'] > 0) ? $fixed_jefe : null;
            $out['jefe_multiplier'] = 1.0;
            $out['jefe_add_sol']    = $jefe_fijo;
        } else {
            // Reemplaza la comisión por el monto fijo
            $out['override_sol']      = $fixed;
            $out['jefe_override_sol'] = ($con_jefe && (float) $regla['excepcion_jefe_usd'] > 0) ? $fixed_jefe : null;
        }
    } elseif ($califica) {
        $out['multiplier']      = (float) $regla['multiplicador'];
        $out['jefe_multiplier'] = !empty($regla['aplica_jefe']) ? (float) $regla['multiplicador'] : 1.0;
    }

    // El retroactivo solo aplica cuando la regla "arranca" en esta venta
    // (no cuando la excepción está activa desde la 1.ª venta).
    $retro = $retro && !($es_excepcion && empty($regla['excepcion_desde_umbral']));
    if ($retro && $aplicar_retro && ($califica || $usa_excepcion)) {
        billetera_bonus_retroactivo($regla, $user_id, $mes_inicio, $out, $tc);
    }

    return $out;
}

/**
 * Ajusta retroactivamente las ventas anteriores del mes cuando se alcanza el umbral.
 */
function billetera_bonus_retroactivo($regla, $user_id, $mes_inicio, $out, $tc) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';

    $limite = max(1, (int) $regla['umbral'] - 1);
    list($cond, $cond_params) = billetera_bonus_cond($regla);

    // No tocar ventas anteriores a la vigencia.
    $inicio = (string) $mes_inicio;
    if (!empty($regla['banner_desde'])) {
        $desde_dt = (string) $regla['banner_desde'] . ' 00:00:00';
        if ($desde_dt > $inicio) {
            $inicio = $desde_dt;
        }
    }

    $subcat_in = "(SELECT s.id FROM $sub s JOIN $cat c ON c.id = s.categoria_id WHERE $cond)";

    $factor = (($regla['excepcion_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc;

    if ($out['override_sol'] !== null) {
        // Reemplazar: el monto pasa a ser el fijo (USD * TC * cantidad)
        $usd    = (float) $regla['excepcion_usd'];
        $params = array_merge([$usd * $factor, $user_id], $cond_params, [$inicio, $limite]);
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET v.monto_comision_sol = (%f * v.cantidad), v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_override_sol'] !== null) {
            $usd_j  = (float) $regla['excepcion_jefe_usd'];
            $params = array_merge([$usd_j * $factor, $user_id], $cond_params, [$inicio]);
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET v.monto_comision_sol = (%f * v.cantidad), v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s
            ", ...$params));
        }
    } elseif ($out['add_sol'] !== null) {
        // Sumar: monto = base + fijo (la excepción anula el multiplicador)
        $mult   = (float) $out['multiplier'];
        $usd    = (float) $regla['excepcion_usd'];
        $params = array_merge([$mult, $usd * $factor, $user_id], $cond_params, [$inicio, $limite]);
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * v.cantidad), v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_add_sol'] !== null) {
            $mult_j = (float) $out['jefe_multiplier'];
            $usd_j  = (float) $regla['excepcion_jefe_usd'];
            $params = array_merge([$mult_j, $usd_j * $factor, $user_id], $cond_params, [$inicio]);
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * v.cantidad), v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s
            ", ...$params));
        }
    } else {
        $mult   = (float) $out['multiplier'];
        $params = array_merge([$mult, $user_id], $cond_params, [$inicio, $limite]);
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET v.bonus_multiplier = %f, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_multiplier'] > 1.0) {
            $params = array_merge([$out['jefe_multiplier'], $user_id], $cond_params, [$inicio]);
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET v.bonus_multiplier = %f, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s
            ", ...$params));
        }
    }
}

function billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $registrante_id = 0, $fecha_datetime = null, $bonus_multiplier = 1.0, $override_sol = null, $add_sol = null) {
    if (!$fecha_datetime) {
        $fecha_datetime = date('Y-m-d H:i:s');
    }
    if (!$tienda_id) {
        return;
    }

    // Obtener tipo del asesor/registrante
    $tipo_asesor = get_user_meta($registrante_id, 'billetera_tipo', true);

    $jefes = get_post_meta($tienda_id, '_jefes_venta_asociados', true);
    if (!is_array($jefes)) {
        $jefes = $jefes ? [$jefes] : [];
    }

    foreach ($jefes as $jefe_id) {
        $jefe_id = intval($jefe_id);
        if (!$jefe_id) {
            continue;
        }

        // Si quien registra es el propio jefe, no duplicar su comisión
        if ($jefe_id === intval($registrante_id)) {
            continue;
        }

        // Validar que el jefe tenga el mismo tipo que el asesor
        $tipo_jefe = get_user_meta($jefe_id, 'billetera_tipo', true);
        if ($tipo_asesor !== $tipo_jefe) {
            continue;
        }

        if ($override_sol !== null) {
            // Reemplazar: monto fijo (ya incluye cantidad), no depende del catálogo
            $monto_jefe  = floatval($override_sol);
            $bm_jefe     = 1.0;
            $bm_aplicado = 1;
        } elseif ($add_sol !== null) {
            // Sumar: (base × multiplicador) + monto fijo
            $comision    = billetera_find_comision($subcategoria_id, $distribuidor_id, 'jefe_venta');
            $base        = $comision ? (billetera_convert_to_sol($comision) * $cantidad) : 0.0;
            $monto_jefe  = ($base * floatval($bonus_multiplier)) + floatval($add_sol);
            $bm_jefe     = 1.0;
            $bm_aplicado = 1;
        } else {
            $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, 'jefe_venta');
            if (!$comision) {
                continue;
            }
            $monto_jefe  = billetera_convert_to_sol($comision) * $cantidad;
            $bm_jefe     = floatval($bonus_multiplier);
            $bm_aplicado = ($bm_jefe > 1) ? 1 : 0;
        }

        global $wpdb;
        $ventas_table = $wpdb->prefix . 'billetera_ventas';
        $wpdb->insert($ventas_table, [
            'usuario_id' => $jefe_id,
            'asesor_id' => intval($registrante_id),
            'subcategoria_id' => $subcategoria_id,
            'cantidad' => $cantidad,
            'monto_comision_sol' => $monto_jefe,
            'id_type' => $id_type,
            'id_value' => $id_value,
            'bonus_multiplier' => $bm_jefe,
            'bonus_aplicado' => $bm_aplicado,
            'creado_en' => $fecha_datetime,
        ], ['%d', '%d', '%d', '%d', '%f', '%s', '%s', '%f', '%d', '%s']);
    }
}

// AJAX: Calcular comisión por subcategoría
add_action('wp_ajax_billetera_get_comision', 'billetera_ajax_get_comision');

function billetera_ajax_get_comision() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_comision')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();
    $allowed_roles = ['asesor', 'administrator', 'jefe_venta'];

    if (!array_intersect($allowed_roles, $current_user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    global $wpdb;
    $subcategoria_id = intval($_POST['subcategoria_id'] ?? 0);
    $cantidad = intval($_POST['cantidad'] ?? 1);
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    if (!$subcategoria_id || $cantidad < 1) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    // Validar que el usuario tiene permiso para esta subcategoría (mediante su línea)
    $sub_linea = $wpdb->get_row($wpdb->prepare(
        "SELECT c.linea_codigo FROM $subcategorias_table s
         INNER JOIN $categorias_table c ON s.categoria_id = c.id
         WHERE s.id = %d",
        $subcategoria_id
    ));

    if (!$sub_linea) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    if (!in_array($sub_linea->linea_codigo, $codigos_permitidos, true)) {
        wp_send_json_error(['message' => 'No tienes permiso para acceder a este producto']);
    }

    // Obtener rol del usuario
    $user_role = $current_user->roles[0];

    // Obtener distribuidor_id del usuario
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    $distribuidor_id = null;

    if ($tienda_id) {
        $distribuidor_id = get_post_meta($tienda_id, '_dealer_id', true);
    }

    // Obtener comisión: primero específica del distribuidor, luego la genérica (NULL)
    $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, $user_role);

    if (!$comision) {
        wp_send_json_error(['message' => 'Comisión no encontrada']);
    }

    // Convertir a SOL y multiplicar por cantidad
    $monto_total_sol = billetera_convert_to_sol($comision) * $cantidad;

    // Aplicar reglas de bonificación (sin retroactivo: solo previsualización)
    $regla_bonus = billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $distribuidor_id, $cantidad, false);
    if ($regla_bonus['override_sol'] !== null) {
        $monto_total_sol  = $regla_bonus['override_sol'];
        $bonus_multiplier = 1.0;
    } elseif ($regla_bonus['add_sol'] !== null) {
        $monto_total_sol  = ($monto_total_sol * $regla_bonus['multiplier']) + $regla_bonus['add_sol'];
        $bonus_multiplier = 1.0;
    } else {
        $bonus_multiplier = $regla_bonus['multiplier'];
    }

    $monto_mostrado = $monto_total_sol * $bonus_multiplier;

    wp_send_json_success([
        'amount' => floatval($monto_mostrado),
        'amount_formatted' => 'S/ ' . number_format($monto_mostrado, 2, '.', ',')
    ]);
}

// AJAX: Obtener saldo y movimientos del usuario
// Nota contable: los asesores ven también las ventas con comisión 0.
// Los jefes mantienen el filtro > 0 para no alterar su vista.
function billetera_usuario_incluye_ceros($user_id) {
    $u = get_userdata($user_id);
    $roles = $u ? (array) $u->roles : [];
    return in_array('asesor', $roles, true);
}

add_action('wp_ajax_billetera_get_balance', 'billetera_ajax_get_balance');

function billetera_ajax_get_balance() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_balance')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    // Saldo actual (mes)
    $current_month = current_time('Y-m-01');
    $balance = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(monto_comision_sol * bonus_multiplier) FROM $ventas_table WHERE usuario_id = %d AND creado_en >= %s",
        $user_id,
        $current_month
    )) ?? 0);

    // Saldo acumulado (total)
    $accumulated = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(monto_comision_sol * bonus_multiplier) FROM $ventas_table WHERE usuario_id = %d",
        $user_id
    )) ?? 0);

    // Acumulado del año
    $year_start = current_time('Y-01-01');
    $acumulado_ano = floatval($wpdb->get_var($wpdb->prepare(
        "SELECT SUM(monto_comision_sol * bonus_multiplier) FROM $ventas_table WHERE usuario_id = %d AND creado_en >= %s",
        $user_id,
        $year_start
    )) ?? 0);

    // Ventas del mes
    $ventas_mes = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $ventas_table WHERE usuario_id = %d AND creado_en >= %s",
        $user_id,
        $current_month
    )));

    // Ranking dentro del taller (asesores de la misma tienda, por saldo del mes)
    $rank = 0;
    $rank_total = 0;
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    if ($tienda_id) {
        $asesores = get_users([
            'meta_key' => '_tienda_asociada',
            'meta_value' => $tienda_id,
            'fields' => 'ID',
            'role__not_in' => ['jefe_venta'],
        ]);
        $rank_total = count($asesores);

        if ($rank_total > 0) {
            $balances = [];
            foreach ($asesores as $aid) {
                $balances[$aid] = floatval($wpdb->get_var($wpdb->prepare(
                    "SELECT SUM(monto_comision_sol * bonus_multiplier) FROM $ventas_table WHERE usuario_id = %d AND creado_en >= %s",
                    $aid,
                    $current_month
                )) ?? 0);
            }
            arsort($balances, SORT_NUMERIC);
            $pos = array_search($user_id, array_keys($balances), true);
            if ($pos !== false) {
                $rank = $pos + 1;
            }
        }
    }

    // Ranking global (todos los asesores del sistema)
    $rank_global = 0;
    $rank_total_global = 0;
    $todos_asesores = get_users([
        'role__in' => ['asesor', 'jefe_venta'],
        'fields' => 'ID',
        'number' => -1,
    ]);
    $rank_total_global = count($todos_asesores);

    if ($rank_total_global > 0) {
        $balances_global = [];
        foreach ($todos_asesores as $aid) {
            $balances_global[$aid] = floatval($wpdb->get_var($wpdb->prepare(
                "SELECT SUM(monto_comision_sol * bonus_multiplier) FROM $ventas_table WHERE usuario_id = %d AND creado_en >= %s",
                $aid,
                $current_month
            )) ?? 0);
        }
        arsort($balances_global, SORT_NUMERIC);
        $pos_global = array_search($user_id, array_keys($balances_global), true);
        if ($pos_global !== false) {
            $rank_global = $pos_global + 1;
        }
    }

    // Últimos movimientos
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $filtro_ceros = billetera_usuario_incluye_ceros($user_id) ? "" : "AND (v.monto_comision_sol * v.bonus_multiplier) > 0";
    $movements = $wpdb->get_results($wpdb->prepare("
        SELECT ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, v.bonus_aplicado, c.nombre as categoria, u.display_name as asesor_nombre
        FROM $ventas_table v
        LEFT JOIN $subcategorias_table s ON v.subcategoria_id = s.id
        LEFT JOIN $categorias_table c ON s.categoria_id = c.id
        LEFT JOIN {$wpdb->users} u ON v.asesor_id = u.ID
        WHERE v.usuario_id = %d
        $filtro_ceros
        ORDER BY v.creado_en DESC, v.id DESC
        LIMIT 10
    ", $user_id));

    $meta = billetera_get_meta_por_usuario($user_id);
    $fill_percent = $meta > 0 ? round(($balance / $meta) * 100, 1) : 0;
    if ($fill_percent > 100) {
        $fill_percent = 100;
    }

    $racha = function_exists('billetera_get_racha') ? billetera_get_racha($user_id) : 0;

    wp_send_json_success([
        'balance' => $balance,
        'accumulated' => $accumulated,
        'acumulado_ano' => $acumulado_ano,
        'ventas_mes' => $ventas_mes,
        'ranking' => ['rank' => $rank, 'total' => $rank_total],
        'ranking_global' => ['rank' => $rank_global, 'total' => $rank_total_global],
        'racha' => $racha,
        'meta' => $meta,
        'fill_percent' => $fill_percent,
        'movements' => $movements,
    ]);
}

// AJAX: Obtener todos los movimientos del usuario
add_action('wp_ajax_billetera_get_all_movements', 'billetera_ajax_get_all_movements');

function billetera_ajax_get_all_movements() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_all_movements')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    $filtro_ceros = billetera_usuario_incluye_ceros($user_id) ? "" : "AND (v.monto_comision_sol * v.bonus_multiplier) > 0";

    $movements = $wpdb->get_results($wpdb->prepare("
        SELECT ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, v.bonus_aplicado, c.nombre as categoria, u.display_name as asesor_nombre
        FROM $ventas_table v
        LEFT JOIN $subcategorias_table s ON v.subcategoria_id = s.id
        LEFT JOIN $categorias_table c ON s.categoria_id = c.id
        LEFT JOIN {$wpdb->users} u ON v.asesor_id = u.ID
        WHERE v.usuario_id = %d
        $filtro_ceros
        ORDER BY v.creado_en DESC, v.id DESC
    ", $user_id));

    wp_send_json_success(['movements' => $movements]);
}

// ============================================================================
// BANNERS DE CAMPAÑA (enlazados a reglas de bonificación, editables en admin)
// Se muestran en "Mi desempeño". Sin montos sensibles: solo textos del admin
// + conteo de ventas del mes en el ámbito de la regla.
// ============================================================================

/**
 * Ventas del mes actual del usuario dentro del ámbito de una regla.
 */
function billetera_campana_ventas_mes($user_id, $regla) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';

    $now   = billetera_stats_local_now();
    $desde = $now->format('Y-m-01 00:00:00');
    $hasta = (clone $now)->modify('first day of next month')->format('Y-m-01 00:00:00');
    // El conteo cuadra con el bono: solo dentro de la vigencia.
    if (!empty($regla['banner_desde'])) {
        $d = (string) $regla['banner_desde'] . ' 00:00:00';
        if ($d > $desde) {
            $desde = $d;
        }
    }
    if (!empty($regla['banner_hasta'])) {
        $h = (new DateTime((string) $regla['banner_hasta'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d 00:00:00');
        if ($h < $hasta) {
            $hasta = $h;
        }
    }

    list($cond_sql, $cond_params) = billetera_bonus_cond($regla);
    $params = array_merge([intval($user_id), $desde, $hasta], $cond_params);

    return intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $ventas v
         JOIN $sub s ON s.id = v.subcategoria_id
         JOIN $cat c ON c.id = s.categoria_id
         WHERE v.usuario_id = %d AND v.creado_en >= %s AND v.creado_en < %s AND ($cond_sql)",
        ...$params
    )));
}

/**
 * Campañas vigentes para un usuario (asesor): reglas con banner activo
 * dentro de vigencia. Sin segmentación por tienda: global para todos.
 */
function billetera_campanas_para_usuario($user_id) {
    $user_id   = intval($user_id);
    $hoy       = billetera_stats_local_now()->format('Y-m-d');

    $out = [];
    foreach (billetera_bonus_reglas() as $r) {
        if (empty($r['activo']) || empty($r['banner_activo'])) {
            continue;
        }
        if (trim((string) ($r['banner_titulo'] ?? '')) === '') {
            continue;
        }
        $desde = (string) ($r['banner_desde'] ?? '');
        $hasta = (string) ($r['banner_hasta'] ?? '');
        if ($desde !== '' && $hoy < $desde) {
            continue;
        }
        if ($hasta !== '' && $hoy > $hasta) {
            continue;
        }
        $out[] = [
            'id'       => (string) ($r['id'] ?? ''),
            'etiqueta' => trim((string) ($r['banner_etiqueta'] ?? '')) !== '' ? (string) $r['banner_etiqueta'] : 'CAMPAÑA · EVENTO',
            'titulo'   => (string) $r['banner_titulo'],
            'bajada'   => (string) ($r['banner_bajada'] ?? ''),
            'desde'    => $desde,
            'hasta'    => $hasta,
            'cta'      => trim((string) ($r['banner_cta'] ?? '')) !== '' ? (string) $r['banner_cta'] : 'VER BASES',
            'umbral'   => intval($r['umbral'] ?? 0),
            'ventas'   => billetera_campana_ventas_mes($user_id, $r),
            'orden'    => intval($r['banner_orden'] ?? 0),
        ];
    }

    usort($out, function ($a, $b) {
        if ($a['orden'] !== $b['orden']) {
            return $a['orden'] <=> $b['orden'];
        }
        return strcmp($a['titulo'], $b['titulo']);
    });

    return $out;
}

// ============================================================================
// ESTADÍSTICAS PERSONALES DEL ASESOR ("Mi desempeño")
// ============================================================================

// AJAX: Estadísticas personales (una sola llamada)
add_action('wp_ajax_billetera_get_mi_stats', 'billetera_ajax_get_mi_stats');

function billetera_ajax_get_mi_stats() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_mi_stats')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();

    if (!defined('BILLETERA_SHOW_DESEMPENO') || !BILLETERA_SHOW_DESEMPENO) {
        wp_send_json_error(['message' => 'Mi desempeño desactivado']);
    }
    if (!array_intersect(['asesor', 'administrator'], (array) $current_user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    $periodo = isset($_POST['periodo']) ? sanitize_text_field($_POST['periodo']) : 'mes';
    if (!in_array($periodo, ['mes', 'ano', 'historico'], true)) {
        $periodo = 'mes';
    }

    // Base navegable para el stepper (‹ ›): mes YYYY-MM o año YYYY.
    // Por defecto, mes/año actual. No se admiten bases futuras.
    $now = billetera_stats_local_now();
    $base = clone $now;
    if ($periodo === 'mes') {
        $mes_param = isset($_POST['mes']) ? sanitize_text_field(wp_unslash($_POST['mes'])) : '';
        if (preg_match('/^(\d{4})-(\d{2})$/', $mes_param, $m)) {
            try {
                $cand = new DateTime(sprintf('%04d-%02d-01 00:00:00', $m[1], $m[2]), wp_timezone());
                if ($cand <= $now) {
                    $base = $cand;
                }
            } catch (Exception $e) {
            }
        }
    } elseif ($periodo === 'ano') {
        $ano_param = isset($_POST['ano']) ? sanitize_text_field(wp_unslash($_POST['ano'])) : '';
        if (preg_match('/^(\d{4})$/', $ano_param, $m)) {
            try {
                $cand = new DateTime(sprintf('%04d-01-01 00:00:00', $m[1]), wp_timezone());
                if ($cand <= $now) {
                    $base = $cand;
                }
            } catch (Exception $e) {
            }
        }
    }

    $meses_es = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $base_label = ($periodo === 'ano')
        ? $base->format('Y')
        : $meses_es[intval($base->format('n'))] . ' ' . $base->format('Y');

    $resumen = billetera_stats_resumen_usuario($user_id, $base);
    $racha   = function_exists('billetera_get_racha') ? intval(billetera_get_racha($user_id)) : 0;

    $meses_n = isset($_POST['meses']) ? max(2, min(12, intval($_POST['meses']))) : 12;

    wp_send_json_success([
        'periodo'          => $periodo,
        'base_mes'         => $base->format('Y-m'),
        'base_ano'         => $base->format('Y'),
        'base_label'       => $base_label,
        'es_actual'        => ($base->format('Y-m') === $now->format('Y-m')),
        'resumen'          => $resumen,
        'tendencia'        => billetera_stats_tendencia_usuario($user_id, $meses_n, $base),
        'por_categoria'    => billetera_stats_desglose($user_id, $periodo, 'categoria', $base),
        'por_subcategoria' => billetera_stats_desglose($user_id, $periodo, 'subcategoria', $base),
        'por_linea'        => billetera_stats_desglose($user_id, $periodo, 'linea', $base),
        'para_meta'        => billetera_stats_para_meta($user_id, $periodo, $base, floatval($resumen['faltan'] ?? 0)),
        'ranking'          => billetera_stats_ranking($user_id),
        'racha'            => $racha,
        'campanas'         => billetera_campanas_para_usuario($user_id),
    ]);
}

/**
 * "Ahora" en la zona horaria del sitio (no UTC).
 *
 * Nota de almacenamiento: tanto el registro de ventas como los reportes usan
 * el día calendario LOCAL (Perú) de `date()` —que WordPress fuerza a UTC—, es
 * decir, se guarda directo la fecha/hora ya "local-izada" en columnas DATETIME.
 * Los cortes de mes se calculan como texto de fecha, sin volver a convertir a
 * UTC, para ser consistentes con lo ya guardado.
 */
function billetera_stats_local_now() {
    return new DateTime('now', wp_timezone());
}

/**
 * Rango de fechas [desde, hasta) como texto de fecha local, según el periodo
 * y una base opcional (para el stepper ‹ ›). Devuelve null en histórico.
 */
function billetera_stats_rango($periodo, $base = null) {
    $now = billetera_stats_local_now();
    if (!($base instanceof DateTime)) {
        $base = $now;
    }

    $mes_inicio = (clone $base)->modify('first day of this month')->setTime(0, 0, 0);
    $mes_fin    = (clone $base)->modify('first day of next month')->setTime(0, 0, 0);

    if ($periodo === 'ano') {
        $ano_inicio = (clone $base)->setDate((int) $base->format('Y'), 1, 1)->setTime(0, 0, 0);
        // Año en curso: hasta hoy (fin del mes actual). Año pasado: año completo.
        $hasta = ($base->format('Y') === $now->format('Y')) ? $mes_fin : (clone $ano_inicio)->modify('+1 year');
        return [$ano_inicio->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s')];
    }
    if ($periodo === 'historico') {
        return [null, null];
    }
    return [$mes_inicio->format('Y-m-d H:i:s'), $mes_fin->format('Y-m-d H:i:s')];
}

/**
 * Resumen: comisión mes actual/anterior/año/histórico, ventas, unidades,
 * ticket promedio, meta, avance, faltante y proyección de cierre.
 */
function billetera_stats_resumen_usuario($user_id, $base = null) {
    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';

    $now = billetera_stats_local_now();
    if (!($base instanceof DateTime)) {
        $base = $now;
    }
    $es_mes_actual = ($base->format('Y-m') === $now->format('Y-m'));

    $mes_inicio = $base->format('Y-m-01 00:00:00');
    $mes_fin    = (clone $base)->modify('first day of next month')->format('Y-m-01 00:00:00');
    $mes_prev   = (clone $base)->modify('first day of last month')->format('Y-m-01 00:00:00');
    $ano_inicio = $base->format('Y-01-01 00:00:00');
    $ano_fin    = (clone $base)->setDate((int) $base->format('Y') + 1, 1, 1)->setTime(0, 0, 0)->format('Y-m-d H:i:s');

    $filtro_ceros = billetera_usuario_incluye_ceros($user_id) ? "" : "AND (monto_comision_sol * bonus_multiplier) > 0";

    $row = $wpdb->get_row($wpdb->prepare("
        SELECT
            SUM(CASE WHEN creado_en >= %s AND creado_en < %s THEN monto_comision_sol * bonus_multiplier ELSE 0 END) AS mes_actual,
            SUM(CASE WHEN creado_en >= %s AND creado_en < %s THEN monto_comision_sol * bonus_multiplier ELSE 0 END) AS mes_anterior,
            SUM(CASE WHEN creado_en >= %s AND creado_en < %s THEN monto_comision_sol * bonus_multiplier ELSE 0 END) AS acumulado_ano,
            SUM(monto_comision_sol * bonus_multiplier) AS historico,
            SUM(CASE WHEN creado_en >= %s AND creado_en < %s THEN 1 ELSE 0 END) AS ventas_mes,
            SUM(CASE WHEN creado_en >= %s AND creado_en < %s THEN cantidad ELSE 0 END) AS unidades_mes,
            COUNT(*) AS ventas_historicas
        FROM $v
        WHERE usuario_id = %d $filtro_ceros
    ",
        $mes_inicio, $mes_fin,
        $mes_prev, $mes_inicio,
        $ano_inicio, $ano_fin,
        $mes_inicio, $mes_fin,
        $mes_inicio, $mes_fin,
        $user_id
    ));

    $mes_actual   = floatval($row->mes_actual ?? 0);
    $mes_anterior = floatval($row->mes_anterior ?? 0);
    $ventas_mes   = intval($row->ventas_mes ?? 0);
    $unidades_mes = intval($row->unidades_mes ?? 0);

    $meta = floatval(billetera_get_meta_por_usuario($user_id));
    $fill = $meta > 0 ? round(($mes_actual / $meta) * 100, 1) : 0;

    // Proyección sobre días laborables (lun–sáb), consistente con la racha.
    // Mes cerrado (stepper): sin proyección, se muestra lo real.
    $dia_actual  = $es_mes_actual ? intval($now->format('j')) : 31;
    $anio_actual = intval($base->format('Y'));
    $mes_num     = intval($base->format('n'));
    list($lab_trans, $lab_total) = billetera_stats_dias_laborables_mes($anio_actual, $mes_num, $dia_actual);
    $lab_trans  = max($lab_trans, 1);
    $proyeccion = $es_mes_actual ? round($mes_actual / $lab_trans * $lab_total, 2) : round($mes_actual, 2);

    $var_mes = $mes_anterior > 0 ? round((($mes_actual - $mes_anterior) / $mes_anterior) * 100, 1) : null;

    return [
        'mes_actual'        => round($mes_actual, 2),
        'mes_anterior'      => round($mes_anterior, 2),
        'acumulado_ano'     => round(floatval($row->acumulado_ano ?? 0), 2),
        'historico'         => round(floatval($row->historico ?? 0), 2),
        'ventas_mes'        => $ventas_mes,
        'unidades_mes'      => $unidades_mes,
        'ventas_historicas' => intval($row->ventas_historicas ?? 0),
        'ticket_promedio'   => $ventas_mes > 0 ? round($mes_actual / $ventas_mes, 2) : 0,
        'meta'              => $meta,
        'fill_percent'      => min(round($fill, 1), 100),
        'fill_real'         => round($fill, 1),
        'faltan'            => max(round($meta - $mes_actual, 2), 0),
        'proyeccion'        => $proyeccion,
        'var_mes'           => $var_mes,
        'dias_laborables'   => $lab_total,
        'dias_laborables_transcurridos' => $lab_trans,
    ];
}

/**
 * Días laborables (lunes a sábado, excluye domingo) de un mes.
 * Si se pasa $hasta_dia, también devuelve los transcurridos hasta ese día.
 * Devuelve [transcurridos, total].
 */
function billetera_stats_dias_laborables_mes($year, $month, $hasta_dia = null) {
    $tz = wp_timezone();
    $dias_mes = (int) (new DateTime(sprintf('%04d-%02d-01', $year, $month), $tz))->format('t');

    $total = 0;
    $transcurridos = 0;
    for ($d = 1; $d <= $dias_mes; $d++) {
        $fecha = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dow = (int) (new DateTime($fecha, $tz))->format('w'); // 0 = domingo
        if ($dow === 0) {
            continue;
        }
        $total++;
        if ($hasta_dia !== null && $d <= $hasta_dia) {
            $transcurridos++;
        }
    }

    return [$transcurridos, $total];
}

/**
 * Tendencia de los últimos N meses hasta la base (rellena meses sin ventas con 0).
 */
function billetera_stats_tendencia_usuario($user_id, $meses = 6, $base = null) {
    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';

    $meses = max(1, intval($meses));
    $now   = billetera_stats_local_now();
    if (!($base instanceof DateTime)) {
        $base = $now;
    }
    $desde = (clone $base)->modify('-' . ($meses - 1) . ' months')->format('Y-m-01 00:00:00');

    $offset = (int) $base->format('P'); // ej. -05:00
    $filtro_ceros = billetera_usuario_incluye_ceros($user_id) ? "" : "AND (monto_comision_sol * bonus_multiplier) > 0";
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT DATE_FORMAT(DATE_ADD(creado_en, INTERVAL %d MINUTE), '%%Y-%%m') AS mes,
               SUM(monto_comision_sol * bonus_multiplier) AS total,
               COUNT(*) AS ventas
        FROM $v
        WHERE usuario_id = %d
          AND creado_en >= %s
          $filtro_ceros
        GROUP BY mes
        ORDER BY mes ASC
    ", $offset * 60, $user_id, $desde));

    $meses_abbr = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
    $map = [];
    foreach ($rows as $r) {
        $map[$r->mes] = $r;
    }

    $out = [];
    for ($i = $meses - 1; $i >= 0; $i--) {
        $ts  = (clone $base)->modify("-$i months");
        $key = $ts->format('Y-m');
        $r   = $map[$key] ?? null;
        $out[] = [
            'mes'    => $key,
            'label'  => $meses_abbr[intval($ts->format('n'))] . ' ' . $ts->format('y'),
            'total'  => $r ? round(floatval($r->total), 2) : 0,
            'ventas' => $r ? intval($r->ventas) : 0,
        ];
    }

    return $out;
}

/**
 * Desglose por categoría, subcategoría o línea para el periodo (y base).
 */
function billetera_stats_desglose($user_id, $periodo, $tipo, $base = null) {
    global $wpdb;
    $v   = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';
    $lin = $wpdb->prefix . 'billetera_lineas';

    list($desde, $hasta) = billetera_stats_rango($periodo, $base);

    $where  = billetera_usuario_incluye_ceros($user_id) ? "v.usuario_id = %d" : "v.usuario_id = %d AND (v.monto_comision_sol * v.bonus_multiplier) > 0";
    $params = [$user_id];
    if ($desde !== null) {
        $where   .= " AND v.creado_en >= %s";
        $params[] = $desde;
    }
    if ($hasta !== null) {
        $where   .= " AND v.creado_en < %s";
        $params[] = $hasta;
    }

    if ($tipo === 'linea') {
        $select = "l.nombre AS label";
        $join   = "JOIN $lin l ON l.codigo = c.linea_codigo";
        $group  = "l.codigo";
    } elseif ($tipo === 'categoria') {
        $select = "c.nombre AS label";
        $join   = "";
        $group  = "c.id";
    } else {
        $select = "s.nombre AS label";
        $join   = "";
        $group  = "s.id";
    }

    $sql = "SELECT $select,
                   SUM(v.monto_comision_sol * v.bonus_multiplier) AS total,
                   COUNT(*) AS ventas
            FROM $v v
            JOIN $sub s ON s.id = v.subcategoria_id
            JOIN $cat c ON c.id = s.categoria_id
            $join
            WHERE $where
            GROUP BY $group
            ORDER BY total DESC
            LIMIT 20";

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'label'  => $r->label,
            'total'  => round(floatval($r->total), 2),
            'ventas' => intval($r->ventas),
        ];
    }
    return $out;
}

/**
 * "Para llegar a tu meta": por subcategoría, comisión unitaria promedio según
 * los registros del periodo (sin aplicar bonos) y ventas necesarias para
 * cubrir lo que falta. Ordenado por camino más corto. Referencial.
 */
function billetera_stats_para_meta($user_id, $periodo, $base, $faltan) {
    if ($faltan <= 0) {
        return ['alcanzada' => true, 'filas' => []];
    }
    global $wpdb;
    $v   = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';

    list($desde, $hasta) = billetera_stats_rango($periodo, $base);

    $where  = "v.usuario_id = %d";
    $params = [intval($user_id)];
    if ($desde !== null) {
        $where   .= " AND v.creado_en >= %s";
        $params[] = $desde;
    }
    if ($hasta !== null) {
        $where   .= " AND v.creado_en < %s";
        $params[] = $hasta;
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.nombre AS sub, c.nombre AS cat,
                SUM(v.monto_comision_sol) AS monto, SUM(v.cantidad) AS cant, COUNT(*) AS ventas
         FROM $v v
         JOIN $sub s ON s.id = v.subcategoria_id
         JOIN $cat c ON c.id = s.categoria_id
         WHERE $where
         GROUP BY s.id, s.nombre, c.nombre
         HAVING cant > 0 AND monto > 0
         ORDER BY monto DESC
         LIMIT 40",
        ...$params
    ));

    $filas = [];
    foreach ((array) $rows as $r) {
        $unit = floatval($r->monto) / max(1, intval($r->cant));
        if ($unit <= 0) {
            continue;
        }
        $filas[] = [
            'cat'        => (string) $r->cat,
            'sub'        => (string) $r->sub,
            'unit'       => round($unit, 2),
            'ventas'     => intval($r->ventas),
            'necesarias' => (int) ceil($faltan / $unit),
        ];
    }

    usort($filas, function ($a, $b) {
        if ($a['necesarias'] !== $b['necesarias']) {
            return $a['necesarias'] <=> $b['necesarias'];
        }
        return $b['unit'] <=> $a['unit'];
    });

    return ['alcanzada' => false, 'filas' => array_slice($filas, 0, 8)];
}

/**
 * Ranking del mes: dentro de la tienda y global (asesores/jefes).
 */
function billetera_stats_ranking($user_id) {
    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';
    $mes_inicio = billetera_stats_local_now()->format('Y-m-01 00:00:00');

    $totales = $wpdb->get_results($wpdb->prepare("
        SELECT usuario_id, SUM(monto_comision_sol * bonus_multiplier) AS total
        FROM $v
        WHERE creado_en >= %s AND (monto_comision_sol * bonus_multiplier) > 0
        GROUP BY usuario_id
    ", $mes_inicio));

    $balances = [];
    foreach ($totales as $t) {
        $balances[intval($t->usuario_id)] = floatval($t->total);
    }

    $user_id = intval($user_id);

    // Tienda
    $tienda_rank = ['rank' => 0, 'total' => 0];
    $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
    if ($tienda_id) {
        $asesores = get_users([
            'meta_key'     => '_tienda_asociada',
            'meta_value'   => $tienda_id,
            'fields'       => 'ID',
            'role__not_in' => ['jefe_venta'],
        ]);
        $tienda_balances = [];
        foreach ($asesores as $aid) {
            $tienda_balances[intval($aid)] = $balances[intval($aid)] ?? 0;
        }
        arsort($tienda_balances, SORT_NUMERIC);
        $pos = array_search($user_id, array_keys($tienda_balances), true);
        $tienda_rank = ['rank' => $pos === false ? 0 : $pos + 1, 'total' => count($tienda_balances)];
    }

    // Global
    $globales = get_users([
        'role__in' => ['asesor', 'jefe_venta'],
        'fields'   => 'ID',
        'number'   => -1,
    ]);
    $global_balances = [];
    foreach ($globales as $gid) {
        $global_balances[intval($gid)] = $balances[intval($gid)] ?? 0;
    }
    arsort($global_balances, SORT_NUMERIC);
    $posg = array_search($user_id, array_keys($global_balances), true);
    $global_rank = ['rank' => $posg === false ? 0 : $posg + 1, 'total' => count($global_balances)];

    return ['tienda' => $tienda_rank, 'global' => $global_rank];
}

// ============================================================================
// RANKING CON NOMBRES ("Ranking" - solo nombres, nunca montos)
// ============================================================================

add_action('wp_ajax_billetera_get_ranking', 'billetera_ajax_get_ranking');

function billetera_ajax_get_ranking() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_ranking')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $current_user = wp_get_current_user();
    if (!defined('BILLETERA_SHOW_RANKING') || !BILLETERA_SHOW_RANKING) {
        wp_send_json_error(['message' => 'Ranking desactivado']);
    }
    if (!array_intersect(['asesor', 'administrator'], (array) $current_user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    $user_id = get_current_user_id();
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $now = billetera_stats_local_now();

    // Mes solicitado (YYYY-MM) para "Ver mes anterior". Por defecto, mes actual.
    $mes_param = isset($_POST['mes']) ? sanitize_text_field(wp_unslash($_POST['mes'])) : '';
    if (!preg_match('/^\d{4}-\d{2}$/', $mes_param)) {
        $mes_param = $now->format('Y-m');
    }
    try {
        $tz = wp_timezone();
        $base = new DateTime($mes_param . '-01 00:00:00', $tz);
    } catch (Exception $e) {
        $base = (clone $now)->setDate((int) $now->format('Y'), (int) $now->format('m'), 1)->setTime(0, 0, 0);
        $mes_param = $base->format('Y-m');
    }
    $desde = $base->format('Y-m-01 00:00:00');
    $hasta = (clone $base)->modify('first day of next month')->format('Y-m-01 00:00:00');
    $es_actual = ($base->format('Y-m') === $now->format('Y-m'));
    $mes_ant = (clone $base)->modify('first day of last month')->format('Y-m');

    wp_send_json_success([
        'periodo'      => 'mes',
        'mes'          => $base->format('Y-m'),
        'mes_label'    => $meses[intval($base->format('n'))] . ' ' . $base->format('Y'),
        'mes_anterior' => $mes_ant,
        'es_actual'    => $es_actual,
        'tienda_label' => billetera_ranking_tienda_label($user_id),
        'tienda'       => billetera_ranking_lista($user_id, 'tienda', 10, 2, $desde, $hasta),
        'global'       => billetera_ranking_lista($user_id, 'global', 10, 2, $desde, $hasta),
    ]);
}

/**
 * Invalida las cachés de ranking (se llama al registrar una venta).
 */
function billetera_rank_cache_bust() {
    $v = (int) get_option('billetera_rank_version', 1);
    update_option('billetera_rank_version', $v + 1, false);
}

/**
 * Stats de ranking por mes: ventas (conteo, columna pública), total (monto para
 * ordenar, nunca se expone) y última venta. Cacheado por versión + mes.
 */
function billetera_ranking_stats_mes($desde = null, $hasta = null) {
    global $wpdb;

    if ($desde === null || $hasta === null) {
        $now = billetera_stats_local_now();
        $desde = $now->format('Y-m-01 00:00:00');
        $hasta = (clone $now)->modify('first day of next month')->format('Y-m-01 00:00:00');
    }

    $key = 'billetera_rank_stats_' . (int) get_option('billetera_rank_version', 1) . '_' . md5($desde . '|' . $hasta);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }

    $v = $wpdb->prefix . 'billetera_ventas';
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT usuario_id, COUNT(*) AS ventas, SUM(monto_comision_sol * bonus_multiplier) AS total, MAX(creado_en) AS ultima
        FROM $v
        WHERE creado_en >= %s AND creado_en < %s
        GROUP BY usuario_id
    ", $desde, $hasta));

    $stats = [];
    foreach ((array) $rows as $r) {
        $stats[intval($r->usuario_id)] = [
            'ventas' => intval($r->ventas),
            'total'  => floatval($r->total),
            'ultima' => $r->ultima ? (string) $r->ultima : null,
        ];
    }

    set_transient($key, $stats, 90);
    return $stats;
}

/**
 * Totales por usuario del mes actual (uid => monto), cacheado.
 * Nunca se expone al cliente: solo se usa para ordenar.
 * (Compat: ahora deriva de las stats por ventas.)
 */
function billetera_ranking_balances_mes() {
    $stats = billetera_ranking_stats_mes();
    $bal = [];
    foreach ($stats as $uid => $s) {
        $bal[intval($uid)] = floatval($s['total']);
    }
    return $bal;
}

/**
 * IDs de usuarios que participan del ranking según el ámbito.
 */
function billetera_ranking_scope_ids($user_id, $ambito) {
    if ($ambito === 'tienda') {
        $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
        if (!$tienda_id) {
            return [];
        }
        return array_map('intval', get_users([
            'meta_key'     => '_tienda_asociada',
            'meta_value'   => $tienda_id,
            'fields'       => 'ID',
            'role__not_in' => ['jefe_venta'],
        ]));
    }

    // Global: cada rol compite entre sí. Un asesor solo ve asesores.
    $user  = get_userdata($user_id);
    $roles = $user ? (array) $user->roles : [];
    $roles_scope = in_array('asesor', $roles, true)
        ? ['asesor']
        : ['asesor', 'jefe_venta'];

    return array_map('intval', get_users([
        'role__in' => $roles_scope,
        'fields'   => 'ID',
        'number'   => -1,
    ]));
}

/**
 * Nombres (solo display_name) para una lista de IDs ya ordenada.
 * $stats: mapa uid => ['ventas'=>int,'total'=>float,'ultima'=>?string] para
 * enriquecer sin exponer montos (solo ventas + última venta).
 * $pos_map: mapa uid => pos (ranking con empates). Si se omite, secuencial.
 */
function billetera_ranking_items($ids, $user_id, $offset = 0, $stats = [], $pos_map = []) {
    if (empty($ids)) {
        return [];
    }

    $users = get_users([
        'include' => $ids,
        'fields'  => ['ID', 'display_name'],
    ]);
    $nombres = [];
    foreach ($users as $u) {
        $nombres[intval($u->ID)] = $u->display_name;
    }

    $user_id = intval($user_id);
    $out = [];
    foreach (array_values($ids) as $i => $id) {
        $id = intval($id);
        $s = $stats[$id] ?? ['ventas' => 0, 'total' => 0.0, 'ultima' => null];
        $out[] = [
            'pos'    => isset($pos_map[$id]) ? intval($pos_map[$id]) : ($offset + $i + 1),
            'nombre' => $nombres[$id] ?? ('Usuario #' . $id),
            'es_yo'  => ($id === $user_id),
            'ventas' => intval($s['ventas'] ?? 0),
            'ultima' => $s['ultima'] ?? null,
        ];
    }
    return $out;
}

/**
 * Leaderboard de un ámbito: top N + ventana alrededor del usuario.
 * Ordena por MONTO de comisiones del mes (total DESC, empates comparten posición).
 * Desempate visible: N° de ventas DESC, luego ID ASC.
 * Devuelve SOLO posición, nombre, ventas y última venta (sin montos).
 * La distancia al #1 se expone como porcentaje ciego (pct_lider / ventaja_pct),
 * nunca como soles, para no filtrar montos del líder.
 */
function billetera_ranking_lista($user_id, $ambito, $top = 10, $ventana = 2, $desde = null, $hasta = null) {
    $stats = billetera_ranking_stats_mes($desde, $hasta);
    $ids      = billetera_ranking_scope_ids($user_id, $ambito);
    $user_id  = intval($user_id);

    // Orden: MONTO total DESC, ventas DESC (desempate visible), ID ASC.
    $orden = $ids;
    usort($orden, function ($a, $b) use ($stats) {
        $ta = round(floatval($stats[$a]['total'] ?? 0.0), 2);
        $tb = round(floatval($stats[$b]['total'] ?? 0.0), 2);
        if ($ta !== $tb) {
            return $tb <=> $ta;
        }
        $va = intval($stats[$a]['ventas'] ?? 0);
        $vb = intval($stats[$b]['ventas'] ?? 0);
        if ($va !== $vb) {
            return $vb <=> $va;
        }
        return $a <=> $b;
    });

    // Posición con empates (1 + N estrictamente delante por MONTO redondeado a 2 decimales).
    $pos_map = [];
    $pos_actual = 0;
    $total_prev = null;
    $delante = 0;
    foreach ($orden as $id) {
        $tt = round(floatval($stats[$id]['total'] ?? 0.0), 2);
        if ($total_prev === null || $tt !== $total_prev) {
            $pos_actual = $delante + 1;
            $total_prev = $tt;
        }
        $pos_map[$id] = $pos_actual;
        $delante++;
    }

    $total      = count($orden);
    $mi_rank    = $pos_map[$user_id] ?? 0;
    $mi_ventas  = $stats[$user_id]['ventas'] ?? 0;
    $mi_ultima  = $stats[$user_id]['ultima'] ?? null;
    $mi_total   = round(floatval($stats[$user_id]['total'] ?? 0.0), 2);

    // Montos internos (NO se exponen): líder y segundo por posición.
    $lider_total = 0.0;
    $segundo_total = 0.0;
    if (!empty($orden)) {
        $lider_total = round(floatval($stats[$orden[0]]['total'] ?? 0.0), 2);
        foreach ($orden as $id) {
            if (($pos_map[$id] ?? 1) > 1) {
                $segundo_total = round(floatval($stats[$id]['total'] ?? 0.0), 2);
                break;
            }
        }
        if ($total === 1) {
            $segundo_total = 0.0;
        }
    }

    // Porcentaje ciego (sin soles): cuánto del líder tengo / cuánto supero al #2.
    $pct_lider = 0;
    if ($lider_total > 0 && $mi_total > 0) {
        $pct_lider = (int) max(0, min(100, round($mi_total / $lider_total * 100)));
    } elseif ($mi_rank === 1) {
        $pct_lider = 100;
    }
    $ventaja_pct = null;
    if ($mi_rank === 1 && $mi_total > 0 && $segundo_total > 0) {
        $ventaja_pct = (int) max(0, round(($mi_total - $segundo_total) / $segundo_total * 100));
    }

    // Referencias de conteo (públicas, la columna Ventas sigue visible).
    $ventas_lider = 0;
    $ventas_segundo = 0;
    if (!empty($orden)) {
        $ventas_lider = $stats[$orden[0]]['ventas'] ?? 0;
        foreach ($orden as $id) {
            if (($pos_map[$id] ?? 1) > 1) {
                $ventas_segundo = $stats[$id]['ventas'] ?? 0;
                break;
            }
        }
        if ($total === 1) {
            $ventas_segundo = 0;
        }
    }

    // Con / sin ventas.
    $con_ventas = 0;
    foreach ($orden as $id) {
        if (($stats[$id]['ventas'] ?? 0) > 0) {
            $con_ventas++;
        }
    }

    $slice_top = array_slice($orden, 0, $top);
    // ¿Estoy en el top visible?
    $en_top = in_array($user_id, $slice_top, true);

    $items_ventana = [];
    $mi_idx = array_search($user_id, $orden, true);
    if ($mi_idx !== false && !$en_top) {
        $ini   = max(0, $mi_idx - $ventana);
        $fin   = min($total - 1, $mi_idx + $ventana);
        $slice = array_slice($orden, $ini, $fin - $ini + 1);
        $items_ventana = billetera_ranking_items($slice, $user_id, $ini, $stats, $pos_map);
    }

    return [
        'rank'           => $mi_rank,
        'total'          => $total,
        'mi_ventas'      => intval($mi_ventas),
        'mi_ultima'      => $mi_ultima,
        // Conteo de ventas (columna pública). El gap oficial es pct_* (ciego, sin soles).
        'ventas_lider'   => intval($ventas_lider),
        'ventas_segundo' => intval($ventas_segundo),
        'ventaja'        => ($mi_rank === 1) ? max(0, intval($mi_ventas) - intval($ventas_segundo)) : 0,
        'faltan'         => ($mi_rank > 1) ? max(0, intval($ventas_lider) - intval($mi_ventas)) : 0,
        'pct_lider'      => intval($pct_lider),
        'ventaja_pct'    => $ventaja_pct === null ? null : intval($ventaja_pct),
        'con_ventas'     => intval($con_ventas),
        'sin_ventas'     => intval($total - $con_ventas),
        'en_top'         => $en_top,
        'top'            => billetera_ranking_items($slice_top, $user_id, 0, $stats, $pos_map),
        'ventana'        => $items_ventana,
        'todos'          => billetera_ranking_items($orden, $user_id, 0, $stats, $pos_map),
    ];
}

/**
 * Nombre de la tienda del usuario (para el subtítulo).
 */
function billetera_ranking_tienda_label($user_id) {
    $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
    if (!$tienda_id) {
        return '';
    }
    $tienda = get_post($tienda_id);
    return $tienda ? $tienda->post_title : '';
}

// AJAX: Cambiar contraseña
add_action('wp_ajax_billetera_change_password', 'billetera_ajax_change_password');

function billetera_ajax_change_password() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_change_password')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (empty($current_password) || empty($new_password)) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    $user = get_user_by('id', $user_id);

    if (!wp_check_password($current_password, $user->user_pass, $user_id)) {
        wp_send_json_error(['message' => 'La contraseña actual es incorrecta']);
    }

    wp_set_password($new_password, $user_id);

    wp_send_json_success(['message' => 'Contraseña cambiada exitosamente']);
}
