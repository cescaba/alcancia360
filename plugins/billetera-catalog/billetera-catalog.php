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

// Incluir funciones de importación masiva de usuarios
require_once(plugin_dir_path(__FILE__) . 'includes/bulk-user-import.php');
require_once(plugin_dir_path(__FILE__) . 'includes/csv-import.php');
require_once(plugin_dir_path(__FILE__) . 'includes/admin-import-page.php');

// Crear tablas de catálogo al activar
register_activation_hook(__FILE__, 'billetera_create_catalog_tables');

// Migración en caliente: asegura que la columna asesor_id exista sin reactivar el plugin
add_action('plugins_loaded', 'billetera_maybe_upgrade_schema');

function billetera_maybe_upgrade_schema() {
    if (get_option('billetera_db_version') === '1.2') {
        return;
    }

    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ventas_table'") == $ventas_table) {
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $ventas_table");
        $column_names = array_column((array) $columns, 'Field');
        if (!in_array('asesor_id', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN asesor_id bigint(20)");
        }
    }

    // Tabla de METAS por sucursal x marca x tipo (sin reactivar)
    $metas_table = $wpdb->prefix . 'billetera_metas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$metas_table'") != $metas_table) {
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $metas_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tienda_id bigint(20) NOT NULL,
            marca_id mediumint(9) NOT NULL,
            tipo varchar(20) NOT NULL,
            meta decimal(10, 2) NOT NULL DEFAULT 0,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            actualizado_en datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cruce_unico (tienda_id, marca_id, tipo),
            KEY tienda_id (tienda_id),
            KEY marca_id (marca_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    update_option('billetera_db_version', '1.2');
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

    // Tabla de CATEGORÍAS
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$categorias_table'") != $categorias_table) {
        $sql = "CREATE TABLE $categorias_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            marca_id mediumint(9) NOT NULL,
            nombre varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            orden_display int(3) DEFAULT 0,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY marca_id (marca_id),
            UNIQUE KEY categoria_unica (marca_id, slug)
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

    // Tabla de METAS por sucursal x marca x tipo (Venta / Post Venta)
    $metas_table = $wpdb->prefix . 'billetera_metas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$metas_table'") != $metas_table) {
        $sql = "CREATE TABLE $metas_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            tienda_id bigint(20) NOT NULL,
            marca_id mediumint(9) NOT NULL,
            tipo varchar(20) NOT NULL,
            meta decimal(10, 2) NOT NULL DEFAULT 0,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            actualizado_en datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cruce_unico (tienda_id, marca_id, tipo),
            KEY tienda_id (tienda_id),
            KEY marca_id (marca_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
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

// Helper: Obtener marcas permitidas para un usuario según sus permisos
function billetera_get_marcas_permitidas($user_id = null) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    // Mapeo de permisos a slugs de marca
    $permisos = [
        'billetera_hyu' => 'hyundai',
        'billetera_hcv' => 'hyundai',
        'billetera_gee' => 'geely',
        'billetera_jmc' => 'jmc',
    ];

    $slugs_permitidos = [];

    foreach ($permisos as $meta_key => $slug) {
        $valor = get_user_meta($user_id, $meta_key, true);
        if ($valor) {
            if (!in_array($slug, $slugs_permitidos)) {
                $slugs_permitidos[] = $slug;
            }
        }
    }

    // Si no tiene permisos, retornar array vacío
    if (empty($slugs_permitidos)) {
        return [];
    }

    // Obtener marcas por slugs
    global $wpdb;
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    $placeholders = implode(',', array_fill(0, count($slugs_permitidos), '%s'));
    $marcas = $wpdb->get_results($wpdb->prepare(
        "SELECT id, nombre, slug
         FROM $marcas_table
         WHERE activo = 1 AND slug IN ($placeholders)
         ORDER BY nombre ASC",
        ...$slugs_permitidos
    ));

    return $marcas ?: [];
}

// AJAX: Obtener marcas (filtradas por permisos del usuario)
add_action('wp_ajax_billetera_get_marcas', 'billetera_ajax_get_marcas');
add_action('wp_ajax_nopriv_billetera_get_marcas', 'billetera_ajax_get_marcas');

function billetera_ajax_get_marcas() {
    $user_id = get_current_user_id();

    // Obtener marcas permitidas para el usuario
    $marcas = billetera_get_marcas_permitidas($user_id);

    wp_send_json_success($marcas);
}

// AJAX: Obtener categorías por marca
add_action('wp_ajax_billetera_get_categorias', 'billetera_ajax_get_categorias');
add_action('wp_ajax_nopriv_billetera_get_categorias', 'billetera_ajax_get_categorias');

function billetera_ajax_get_categorias() {
    global $wpdb;
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    $marca_id = intval($_POST['marca_id'] ?? 0);
    $user_id = get_current_user_id();

    if (!$marca_id) {
        wp_send_json_error(['message' => 'ID de marca inválido']);
    }

    // Validar que el usuario tiene permiso para esta marca
    $marcas_permitidas = billetera_get_marcas_permitidas($user_id);
    $marca_ids_permitidos = array_column($marcas_permitidas, 'id');

    if (!in_array($marca_id, $marca_ids_permitidos)) {
        wp_send_json_error(['message' => 'No tienes permiso para acceder a esta marca']);
    }

    $categorias = $wpdb->get_results($wpdb->prepare("
        SELECT id, nombre, slug
        FROM $categorias_table
        WHERE marca_id = %d AND activo = 1
        ORDER BY orden_display ASC
    ", $marca_id));

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

    // Obtener la marca de la categoría
    $categoria = $wpdb->get_row($wpdb->prepare(
        "SELECT marca_id FROM $categorias_table WHERE id = %d",
        $categoria_id
    ));

    if (!$categoria) {
        wp_send_json_error(['message' => 'Categoría no encontrada']);
    }

    // Validar que el usuario tiene permiso para esta marca
    $marcas_permitidas = billetera_get_marcas_permitidas($user_id);
    $marca_ids_permitidos = array_column($marcas_permitidas, 'id');

    if (!in_array($categoria->marca_id, $marca_ids_permitidos)) {
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

    // Validar que el usuario tiene permiso para esta subcategoría (mediante su marca)
    $sub_marca = $wpdb->get_row($wpdb->prepare(
        "SELECT c.marca_id FROM $subcategorias_table s
         INNER JOIN $categorias_table c ON s.categoria_id = c.id
         WHERE s.id = %d",
        $subcategoria_id
    ));

    if (!$sub_marca) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    $marcas_permitidas = billetera_get_marcas_permitidas($user_id);
    $marca_ids_permitidos = array_column($marcas_permitidas, 'id');

    if (!in_array($sub_marca->marca_id, $marca_ids_permitidos)) {
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

    // Verificar si es venta de Prepagados y aplicar bonus si es necesario
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $bonus_multiplier = 1.0;

    $sub_data = $wpdb->get_row($wpdb->prepare("
        SELECT c.nombre as categoria_nombre
        FROM $subcategorias_table s
        INNER JOIN $categorias_table c ON s.categoria_id = c.id
        WHERE s.id = %d
    ", $subcategoria_id));

    if ($sub_data && $sub_data->categoria_nombre === 'Prepagados') {
        $current_month = date('Y-m-01');
        $prepagados_count = intval($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(v.id) FROM {$wpdb->prefix}billetera_ventas v
            INNER JOIN $subcategorias_table s ON v.subcategoria_id = s.id
            INNER JOIN $categorias_table c ON s.categoria_id = c.id
            WHERE v.usuario_id = %d
            AND c.nombre = 'Prepagados'
            AND v.creado_en >= %s
        ", $user_id, $current_month)));

        if ($prepagados_count >= 5) {
            $bonus_multiplier = 2.0;

            // Si es exactamente la 6ta venta, actualizar las 5 anteriores
            if ($prepagados_count === 5) {
                $wpdb->query($wpdb->prepare("
                    UPDATE {$wpdb->prefix}billetera_ventas v
                    SET v.bonus_multiplier = 2.0
                    WHERE v.usuario_id = %d
                    AND v.subcategoria_id IN (
                        SELECT s.id FROM $subcategorias_table s
                        INNER JOIN $categorias_table c ON s.categoria_id = c.id
                        WHERE c.nombre = 'Prepagados'
                    )
                    AND v.creado_en >= %s
                    ORDER BY v.id DESC
                    LIMIT 5
                ", $user_id, $current_month));
            }
        }
    }

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
        'creado_en' => $fecha_datetime,
    ], ['%d', '%d', '%d', '%f', '%s', '%s', '%f', '%s']);

    if ($result) {
        // Registrar la comisión del jefe de venta de la sucursal (sin bonus 2x)
        billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $user_id, $fecha_datetime);

        $monto_mostrado = $monto_total_sol * $bonus_multiplier;
        wp_send_json_success([
            'message' => 'Venta registrada',
            'amount' => floatval($monto_mostrado),
            'id_type_label' => ucfirst($id_type),
            'id_value' => $id_value,
            'bonus_multiplier' => floatval($bonus_multiplier),
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

    $marcas = billetera_get_marcas_permitidas($user_id);
    if (empty($marcas)) {
        return billetera_get_meta_asesor();
    }
    $marca_ids = array_map('intval', array_column((array) $marcas, 'id'));
    $marca_ids = array_values(array_filter($marca_ids));
    if (empty($marca_ids)) {
        return billetera_get_meta_asesor();
    }

    global $wpdb;
    $metas_table = $wpdb->prefix . 'billetera_metas';
    $placeholders = implode(',', array_fill(0, count($marca_ids), '%d'));
    $params = array_merge([$tienda_id, $tipo], $marca_ids);
    $query = "SELECT SUM(meta) FROM $metas_table WHERE tienda_id = %d AND tipo = %s AND marca_id IN ($placeholders)";
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

function billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $registrante_id = 0, $fecha_datetime = null) {
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

        $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, 'jefe_venta');
        if (!$comision) {
            continue;
        }

        $monto_jefe = billetera_convert_to_sol($comision) * $cantidad;

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
            'bonus_multiplier' => 1.0,
            'creado_en' => $fecha_datetime,
        ], ['%d', '%d', '%d', '%d', '%f', '%s', '%s', '%f', '%s']);
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

    // Validar que el usuario tiene permiso para esta subcategoría (mediante su marca)
    $sub_marca = $wpdb->get_row($wpdb->prepare(
        "SELECT c.marca_id FROM $subcategorias_table s
         INNER JOIN $categorias_table c ON s.categoria_id = c.id
         WHERE s.id = %d",
        $subcategoria_id
    ));

    if (!$sub_marca) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    $marcas_permitidas = billetera_get_marcas_permitidas($user_id);
    $marca_ids_permitidos = array_column($marcas_permitidas, 'id');

    if (!in_array($sub_marca->marca_id, $marca_ids_permitidos)) {
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

    // Verificar si es Prepagados y aplicar bonus si corresponde
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $bonus_multiplier = 1.0;

    $sub_data = $wpdb->get_row($wpdb->prepare("
        SELECT c.nombre as categoria_nombre
        FROM $subcategorias_table s
        INNER JOIN $categorias_table c ON s.categoria_id = c.id
        WHERE s.id = %d
    ", $subcategoria_id));

    if ($sub_data && $sub_data->categoria_nombre === 'Prepagados') {
        $current_month = date('Y-m-01');
        $prepagados_count = intval($wpdb->get_var($wpdb->prepare("
            SELECT COUNT(v.id) FROM {$wpdb->prefix}billetera_ventas v
            INNER JOIN $subcategorias_table s ON v.subcategoria_id = s.id
            INNER JOIN $categorias_table c ON s.categoria_id = c.id
            WHERE v.usuario_id = %d
            AND c.nombre = 'Prepagados'
            AND v.creado_en >= %s
        ", $user_id, $current_month)));

        if ($prepagados_count >= 5) {
            $bonus_multiplier = 2.0;
        }
    }

    $monto_mostrado = $monto_total_sol * $bonus_multiplier;

    wp_send_json_success([
        'amount' => floatval($monto_mostrado),
        'amount_formatted' => 'S/ ' . number_format($monto_mostrado, 2, '.', ',')
    ]);
}

// AJAX: Obtener saldo y movimientos del usuario
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
    $current_month = date('Y-m-01');
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
    $year_start = date('Y-01-01');
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
    $movements = $wpdb->get_results($wpdb->prepare("
        SELECT ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, c.nombre as categoria, u.display_name as asesor_nombre
        FROM $ventas_table v
        LEFT JOIN $subcategorias_table s ON v.subcategoria_id = s.id
        LEFT JOIN $categorias_table c ON s.categoria_id = c.id
        LEFT JOIN {$wpdb->users} u ON v.asesor_id = u.ID
        WHERE v.usuario_id = %d
        AND (v.monto_comision_sol * v.bonus_multiplier) > 0
        ORDER BY v.creado_en DESC
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

    $movements = $wpdb->get_results($wpdb->prepare("
        SELECT ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, c.nombre as categoria, u.display_name as asesor_nombre
        FROM $ventas_table v
        LEFT JOIN $subcategorias_table s ON v.subcategoria_id = s.id
        LEFT JOIN $categorias_table c ON s.categoria_id = c.id
        LEFT JOIN {$wpdb->users} u ON v.asesor_id = u.ID
        WHERE v.usuario_id = %d
        AND (v.monto_comision_sol * v.bonus_multiplier) > 0
        ORDER BY v.creado_en DESC
    ", $user_id));

    wp_send_json_success(['movements' => $movements]);
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
