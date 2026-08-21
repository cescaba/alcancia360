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

// Crear tablas de catálogo al activar
register_activation_hook(__FILE__, 'billetera_create_catalog_tables');

// Migración en caliente: asegura que la columna asesor_id exista sin reactivar el plugin
add_action('plugins_loaded', 'billetera_maybe_upgrade_schema');

function billetera_maybe_upgrade_schema() {
    if (get_option('billetera_db_version') === '1.1') {
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

    update_option('billetera_db_version', '1.1');
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

// AJAX: Obtener marcas
add_action('wp_ajax_billetera_get_marcas', 'billetera_ajax_get_marcas');
add_action('wp_ajax_nopriv_billetera_get_marcas', 'billetera_ajax_get_marcas');

function billetera_ajax_get_marcas() {
    global $wpdb;
    $marcas_table = $wpdb->prefix . 'billetera_marcas';

    $marcas = $wpdb->get_results("
        SELECT id, nombre, slug
        FROM $marcas_table
        WHERE activo = 1
        ORDER BY nombre ASC
    ");

    wp_send_json_success($marcas);
}

// AJAX: Obtener categorías por marca
add_action('wp_ajax_billetera_get_categorias', 'billetera_ajax_get_categorias');
add_action('wp_ajax_nopriv_billetera_get_categorias', 'billetera_ajax_get_categorias');

function billetera_ajax_get_categorias() {
    global $wpdb;
    $categorias_table = $wpdb->prefix . 'billetera_categorias';

    $marca_id = intval($_POST['marca_id'] ?? 0);

    if (!$marca_id) {
        wp_send_json_error(['message' => 'ID de marca inválido']);
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

    $categoria_id = intval($_POST['categoria_id'] ?? 0);

    if (!$categoria_id) {
        wp_send_json_error(['message' => 'ID de categoría inválido']);
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

    if (!$subcategoria_id || $cantidad < 1 || !$id_type || !$id_value) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

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
    ], ['%d', '%d', '%d', '%f', '%s', '%s', '%f']);

    if ($result) {
        // Registrar la comisión del jefe de venta de la sucursal (sin bonus 2x)
        billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $user_id);

        $monto_mostrado = $monto_total_sol * $bonus_multiplier;
        wp_send_json_success([
            'message' => 'Venta registrada',
            'amount' => floatval($monto_mostrado),
            'id_type_label' => ucfirst($id_type),
            'id_value' => $id_value,
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

function billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $registrante_id = 0) {
    if (!$tienda_id) {
        return;
    }

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
        ], ['%d', '%d', '%d', '%d', '%f', '%s', '%s', '%f']);
    }
}

// AJAX: Calcular comisión por subcategoría
add_action('wp_ajax_billetera_get_comision', 'billetera_ajax_get_comision');

function billetera_ajax_get_comision() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
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

    if (!$subcategoria_id || $cantidad < 1) {
        wp_send_json_error(['message' => 'Datos incompletos']);
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
        ORDER BY v.creado_en DESC
        LIMIT 10
    ", $user_id));

    $meta = billetera_get_meta_asesor();
    $fill_percent = $meta > 0 ? round(($balance / $meta) * 100, 1) : 0;
    if ($fill_percent > 100) {
        $fill_percent = 100;
    }

    wp_send_json_success([
        'balance' => $balance,
        'accumulated' => $accumulated,
        'acumulado_ano' => $acumulado_ano,
        'ventas_mes' => $ventas_mes,
        'ranking' => ['rank' => $rank, 'total' => $rank_total],
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
