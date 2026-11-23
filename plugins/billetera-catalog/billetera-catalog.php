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
    if (get_option('billetera_db_version') === '1.8') {
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
        // --- 1.6: TC congelado por venta (los fijos en USD del retroactivo usan
        // el TC de cada venta, no el del día que se alcanza el umbral) ---
        if (!in_array('tc_aplicado', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN tc_aplicado decimal(10, 4) NULL");
        }
        // --- 1.8: línea de la venta (la subcategoría ya no implica línea) ---
        if (!in_array('linea_codigo', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN linea_codigo varchar(3) NULL AFTER subcategoria_id");
        }
    }

    // --- 1.3: líneas (hyu/hcv/gee/jmc) independientes de las marcas ---
    billetera_create_lineas_table();
    billetera_seed_lineas();
    // --- 1.8: categoría global + subcategoría con líneas (checkboxes) ---
    billetera_migrate_categorias_globales();
    billetera_reset_metas_table();

    // --- 1.4: tabla de cola del resumen semanal por correo ---
    billetera_summary_create_queue_table();

    // --- 1.7: metas de sucursal en unidades (línea x categoría x tipo) ---
    billetera_create_metas_sucursal_table();

    update_option('billetera_db_version', '1.8');
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
        ['hyu', 'Hyundai Autos',    'hyundai',   1],
        ['hcv', 'Hyundai Camiones', 'hyundai',   2],
        ['gee', 'Geely',            'geely',     3],
        ['jmc', 'JMC',              'jmc',       4],
        ['mul', 'Multimarcas',      'multimarca', 5],
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
 * Migra al modelo GLOBAL: categoría global (sin linea_codigo, UNIQUE slug),
 * subcategoría global (merge de duplicadas) + tabla puente
 * subcategoria_lineas (checkboxes) + comisiones con linea_codigo.
 * Idempotente: detecta el esquema viejo por la columna linea_codigo de
 * categorías. Es la misma migración aplicada manualmente en dev.
 */
function billetera_migrate_categorias_globales() {
    global $wpdb;
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $sub_table = $wpdb->prefix . 'billetera_subcategorias';
    $com_table = $wpdb->prefix . 'billetera_comisiones';
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    $bridge_table = $wpdb->prefix . 'billetera_subcategoria_lineas';

    if ($wpdb->get_var("SHOW TABLES LIKE '$categorias_table'") != $categorias_table) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();

    // --- Tabla puente (siempre debe existir, incluso en installs nuevos) ---
    if ($wpdb->get_var("SHOW TABLES LIKE '$bridge_table'") != $bridge_table) {
        $wpdb->query("CREATE TABLE $bridge_table (
            subcategoria_id mediumint(9) NOT NULL,
            linea_codigo varchar(3) NOT NULL,
            PRIMARY KEY (subcategoria_id, linea_codigo),
            KEY linea_codigo (linea_codigo)
        ) $charset_collate;");
    }

    $cat_columns = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $categorias_table"), 'Field');
    $com_columns = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $com_table"), 'Field');

    // Esquema viejo: comisiones sin linea_codigo
    $com_sin_linea = !in_array('linea_codigo', $com_columns, true);

    // --- Caso 1: esquema por línea (tiene linea_codigo) → migrar a global ---
    if (in_array('linea_codigo', $cat_columns, true)) {
        // A. Vínculo original subcategoría → línea (fuente de verdad del checkbox)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tmp_bridge_old (
            subcategoria_id mediumint(9) NOT NULL,
            linea_codigo varchar(3) NOT NULL,
            PRIMARY KEY (subcategoria_id, linea_codigo)
        ) $charset_collate");
        $wpdb->query("INSERT IGNORE INTO {$wpdb->prefix}tmp_bridge_old (subcategoria_id, linea_codigo)
            SELECT s.id, c.linea_codigo
            FROM $sub_table s
            JOIN $categorias_table c ON c.id = s.categoria_id");

        // B. comisiones: agregar y llenar linea_codigo con la línea ORIGINAL
        if ($com_sin_linea) {
            $wpdb->query("ALTER TABLE $com_table ADD COLUMN linea_codigo varchar(3) NULL AFTER subcategoria_id");
        }
        $wpdb->query("UPDATE $com_table com
            JOIN $sub_table s ON s.id = com.subcategoria_id
            JOIN $categorias_table c ON c.id = s.categoria_id
            SET com.linea_codigo = c.linea_codigo");

        // C. Fusionar categorías duplicadas (mismo slug → queda el id menor)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tmp_cat_map (
            old_id mediumint(9) NOT NULL PRIMARY KEY,
            new_id mediumint(9) NOT NULL,
            KEY new_id (new_id)
        ) $charset_collate");
        $wpdb->query("INSERT INTO {$wpdb->prefix}tmp_cat_map (old_id, new_id)
            SELECT c.id,
                   (SELECT MIN(c2.id) FROM $categorias_table c2 WHERE c2.slug = c.slug)
            FROM $categorias_table c");
        $wpdb->query("UPDATE $sub_table s
            JOIN {$wpdb->prefix}tmp_cat_map m ON m.old_id = s.categoria_id
            SET s.categoria_id = m.new_id
            WHERE m.old_id <> m.new_id");
        $wpdb->query("DELETE c FROM $categorias_table c
            JOIN {$wpdb->prefix}tmp_cat_map m ON m.old_id = c.id
            WHERE m.old_id <> m.new_id");

        // D. Quitar la dependencia de línea de las categorías
        $cat_indexes = $wpdb->get_col("SHOW INDEX FROM $categorias_table", 2);
        if (in_array('categoria_unica', (array) $cat_indexes, true)) {
            $wpdb->query("ALTER TABLE $categorias_table DROP INDEX categoria_unica");
        }
        if (in_array('linea_codigo', (array) $cat_indexes, true)) {
            $wpdb->query("ALTER TABLE $categorias_table DROP INDEX linea_codigo");
        }
        $wpdb->query("ALTER TABLE $categorias_table DROP COLUMN linea_codigo");
        $wpdb->query("ALTER TABLE $categorias_table ADD UNIQUE KEY categoria_unica (slug)");

        // E. Fusionar subcategorías duplicadas (misma categoría + mismo nombre)
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}tmp_sub_map (
            old_id mediumint(9) NOT NULL PRIMARY KEY,
            new_id mediumint(9) NOT NULL,
            KEY new_id (new_id)
        ) $charset_collate");
        $wpdb->query("INSERT INTO {$wpdb->prefix}tmp_sub_map (old_id, new_id)
            SELECT s.id,
                   (SELECT MIN(s2.id) FROM $sub_table s2
                    WHERE s2.categoria_id = s.categoria_id AND s2.nombre = s.nombre)
            FROM $sub_table s");

        // La clave única vieja (sin línea) CHOCARÍA al fusionar: se cambia ANTES.
        $com_indexes = $wpdb->get_col("SHOW INDEX FROM $com_table", 2);
        if (in_array('comision_unica', (array) $com_indexes, true)) {
            $wpdb->query("ALTER TABLE $com_table DROP INDEX comision_unica");
        }
        $wpdb->query("ALTER TABLE $com_table
            ADD UNIQUE KEY comision_unica (subcategoria_id, linea_codigo, distribuidor_id, rol)");

        $wpdb->query("UPDATE $com_table com
            JOIN {$wpdb->prefix}tmp_sub_map m ON m.old_id = com.subcategoria_id
            SET com.subcategoria_id = m.new_id
            WHERE m.old_id <> m.new_id");
        $wpdb->query("UPDATE $ventas_table v
            JOIN {$wpdb->prefix}tmp_sub_map m ON m.old_id = v.subcategoria_id
            SET v.subcategoria_id = m.new_id
            WHERE m.old_id <> m.new_id");
        $wpdb->query("DELETE s FROM $sub_table s
            JOIN {$wpdb->prefix}tmp_sub_map m ON m.old_id = s.id
            WHERE m.old_id <> m.new_id");

        // F. Reconstruir la tabla puente con los ids nuevos
        $wpdb->query("TRUNCATE TABLE $bridge_table");
        $wpdb->query("INSERT IGNORE INTO $bridge_table (subcategoria_id, linea_codigo)
            SELECT m.new_id, b.linea_codigo
            FROM {$wpdb->prefix}tmp_bridge_old b
            JOIN {$wpdb->prefix}tmp_sub_map m ON m.old_id = b.subcategoria_id");

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tmp_bridge_old");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tmp_cat_map");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tmp_sub_map");
    }

    // --- Caso 2: comisiones sin linea_codigo (installs nuevos o muy viejos) ---
    if (!in_array('linea_codigo', array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $com_table"), 'Field'), true)) {
        $wpdb->query("ALTER TABLE $com_table ADD COLUMN linea_codigo varchar(3) NULL AFTER subcategoria_id");
        // Sin origen de línea: derivar de la puente de cada subcategoría
        $wpdb->query("UPDATE $com_table com
            JOIN $bridge_table b ON b.subcategoria_id = com.subcategoria_id
            SET com.linea_codigo = b.linea_codigo");
    }

    // NOT NULL + índice de línea (idempotente)
    $col_row = $wpdb->get_row("SHOW COLUMNS FROM $com_table LIKE 'linea_codigo'");
    if ($col_row && $col_row->Null === 'YES') {
        $wpdb->query("ALTER TABLE $com_table MODIFY linea_codigo varchar(3) NOT NULL AFTER subcategoria_id");
    }
    $com_indexes = $wpdb->get_col("SHOW INDEX FROM $com_table", 2);
    if (!in_array('linea_codigo', (array) $com_indexes, true)) {
        $wpdb->query("ALTER TABLE $com_table ADD KEY linea_codigo (linea_codigo)");
    }

    // Backfill histórico de ventas: la línea de una venta antigua se deduce de
    // los permisos de línea del vendedor (billetera_hyu, billetera_gee, ...)
    // cruzados con las líneas de la subcategoría. Queda NULL si es ambiguo.
    if ($wpdb->get_var("SHOW TABLES LIKE '$ventas_table'") == $ventas_table) {
        $ven_cols = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM $ventas_table"), 'Field');
        if (in_array('linea_codigo', $ven_cols, true)) {
            $wpdb->query("UPDATE $ventas_table v
                SET v.linea_codigo = (
                    SELECT b.linea_codigo
                    FROM $bridge_table b
                    WHERE b.subcategoria_id = v.subcategoria_id
                      AND EXISTS (SELECT 1 FROM {$wpdb->usermeta} um
                                  WHERE um.user_id = v.usuario_id
                                    AND um.meta_key = CONCAT('billetera_', b.linea_codigo)
                                    AND um.meta_value IS NOT NULL AND um.meta_value <> '')
                    ORDER BY b.linea_codigo ASC
                    LIMIT 1
                )
                WHERE v.linea_codigo IS NULL");
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

/**
 * Esquema de METAS POR SUCURSAL: sucursal x línea x categoría x tipo,
 * definida en UNIDADES (cantidad de ventas) en vez de monto.
 * La meta total de la sucursal es la suma de unidades de sus categorías.
 */
function billetera_create_metas_sucursal_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_metas_sucursal';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") == $table) {
        return;
    }

    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        tienda_id bigint(20) NOT NULL,
        linea_codigo varchar(3) NOT NULL,
        categoria_id mediumint(9) NOT NULL,
        tipo varchar(20) NOT NULL,
        unidades int(9) NOT NULL DEFAULT 0,
        creado_en datetime DEFAULT CURRENT_TIMESTAMP,
        actualizado_en datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY cruce_unico (tienda_id, linea_codigo, categoria_id, tipo),
        KEY tienda_id (tienda_id),
        KEY linea_codigo (linea_codigo),
        KEY categoria_id (categoria_id)
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

    // Tabla de CATEGORÍAS (globales: sin línea; el vínculo con líneas vive
    // en la tabla puente a nivel de SUBCATEGORÍA)
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    if ($wpdb->get_var("SHOW TABLES LIKE '$categorias_table'") != $categorias_table) {
        $sql = "CREATE TABLE $categorias_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            nombre varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            orden_display int(3) DEFAULT 0,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY categoria_unica (slug)
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
            linea_codigo varchar(3) NOT NULL,
            distribuidor_id bigint(20),
            rol varchar(50) NOT NULL,
            monto decimal(10, 2) NOT NULL,
            moneda varchar(3) NOT NULL,
            activo tinyint(1) DEFAULT 1,
            creado_en datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY subcategoria_id (subcategoria_id),
            KEY linea_codigo (linea_codigo),
            KEY distribuidor_id (distribuidor_id),
            KEY rol (rol),
            UNIQUE KEY comision_unica (subcategoria_id, linea_codigo, distribuidor_id, rol)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Tabla puente subcategoría ↔ líneas (checkboxes) + esquema de comisiones
    billetera_migrate_categorias_globales();

    // Tabla de VENTAS (registro de ventas de asesores - TODO en SOL)
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    if ($wpdb->get_var("SHOW TABLES LIKE '$ventas_table'") != $ventas_table) {
        $sql = "CREATE TABLE $ventas_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            usuario_id bigint(20) NOT NULL,
            asesor_id bigint(20),
            subcategoria_id mediumint(9) NOT NULL,
            linea_codigo varchar(3) NULL,
            cantidad int(11) DEFAULT 1,
            monto_comision_sol decimal(10, 2) NOT NULL,
            id_type varchar(50),
            id_value varchar(255),
            bonus_multiplier decimal(3, 1) DEFAULT 1.0,
            bonus_aplicado tinyint(1) NOT NULL DEFAULT 0,
            tc_aplicado decimal(10, 4) NULL,
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

        if (!in_array('tc_aplicado', $column_names)) {
            $wpdb->query("ALTER TABLE $ventas_table ADD COLUMN tc_aplicado decimal(10, 4) NULL");
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

    // Tabla de METAS POR SUCURSAL en unidades (línea x categoría x tipo)
    billetera_create_metas_sucursal_table();

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
        'billetera_mul' => 'mul',
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
        SELECT DISTINCT c.id, c.nombre, c.slug
        FROM $categorias_table c
        INNER JOIN {$wpdb->prefix}billetera_subcategorias s ON s.categoria_id = c.id AND s.activo = 1
        INNER JOIN {$wpdb->prefix}billetera_subcategoria_lineas b ON b.subcategoria_id = s.id AND b.linea_codigo = %s
        WHERE c.activo = 1
        ORDER BY c.orden_display ASC, c.nombre ASC
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
    $bridge_table = $wpdb->prefix . 'billetera_subcategoria_lineas';

    $categoria_id = intval($_POST['categoria_id'] ?? 0);
    $user_id = get_current_user_id();

    if (!$categoria_id) {
        wp_send_json_error(['message' => 'ID de categoría inválido']);
    }

    $cat_existe = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $categorias_table WHERE id = %d AND activo = 1",
        $categoria_id
    )));

    if (!$cat_existe) {
        wp_send_json_error(['message' => 'Categoría no encontrada']);
    }

    // Líneas de la categoría (vía puente, a través de sus subcategorías)
    $cat_lineas = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT b.linea_codigo
        FROM $bridge_table b
        INNER JOIN $subcategorias_table s ON s.id = b.subcategoria_id
        WHERE s.categoria_id = %d
    ", $categoria_id));

    // Validar que el usuario tiene permiso para alguna línea de la categoría
    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    if (empty($cat_lineas) || !array_intersect($cat_lineas, $codigos_permitidos)) {
        wp_send_json_error(['message' => 'No tienes permiso para acceder a esta categoría']);
    }

    // Subcategorías limitadas a las líneas permitidas del usuario
    $placeholders = implode(',', array_fill(0, count($codigos_permitidos), '%s'));
    $subcategorias = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT s.id, s.nombre, s.por_unidad
        FROM $subcategorias_table s
        INNER JOIN $bridge_table b ON b.subcategoria_id = s.id AND b.linea_codigo IN ($placeholders)
        WHERE s.categoria_id = %d AND s.activo = 1
        ORDER BY s.orden_display ASC, s.nombre ASC
    ", ...array_merge($codigos_permitidos, [$categoria_id])));

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

    // La subcategoría debe existir y estar activa
    $sub_existe = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $subcategorias_table WHERE id = %d AND activo = 1",
        $subcategoria_id
    )));
    if (!$sub_existe) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    // Línea de la venta: POST explícito si viene; si no, la que coincida con
    // las líneas permitidas del usuario (la subcategoría puede estar en varias).
    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    $linea_venta = billetera_resolver_linea_venta(
        $subcategoria_id,
        $codigos_permitidos,
        sanitize_text_field($_POST['linea_codigo'] ?? '')
    );

    if (!$linea_venta) {
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
    // El asesor y el jefe registran con fecha de hoy (no pueden elegir ni
    // modificar fechas). Solo el backoffice puede fechar ventas manualmente.
    if (!current_user_can('manage_billetera_catalog')) {
        $fecha = date('Y-m-d');
        $fecha_timestamp = strtotime($fecha);
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
    $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, $user_role, $linea_venta);

    if (!$comision) {
        wp_send_json_error(['message' => 'Comisión no encontrada']);
    }

    // Convertir a SOL y multiplicar por cantidad
    $monto_total_sol = billetera_convert_to_sol($comision) * $cantidad;

    // Aplicar reglas de bonificación (categoría, umbral, multiplicador y excepción)
    $regla_bonus = billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $distribuidor_id, $cantidad, true, $fecha_datetime, $linea_venta);
    list($monto_total_sol, $bonus_multiplier, $bonus_aplicado) = billetera_venta_monto_con_bonus($monto_total_sol, $regla_bonus);

    // Guardar venta en nueva tabla (tc_aplicado congela el tipo de cambio del día:
    // el retroactivo de montos fijos revaloriza cada venta con su propio TC).
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    $tc_ins = billetera_get_tipo_cambio();
    $result = $wpdb->insert($ventas_table, [
        'usuario_id' => $user_id,
        'subcategoria_id' => $subcategoria_id,
        'linea_codigo' => $linea_venta,
        'cantidad' => $cantidad,
        'monto_comision_sol' => $monto_total_sol,
        'id_type' => $id_type,
        'id_value' => $id_value,
        'bonus_multiplier' => $bonus_multiplier,
        'bonus_aplicado' => $bonus_aplicado,
        'tc_aplicado' => $tc_ins,
        'creado_en' => $fecha_datetime,
    ], ['%d', '%d', '%s', '%d', '%f', '%s', '%s', '%f', '%d', '%f', '%s']);

    if ($result) {
        // Registrar la comisión del jefe de venta de la sucursal (con la misma regla de bonificación)
        billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $user_id, $fecha_datetime, $regla_bonus['jefe_multiplier'], $regla_bonus['jefe_override_sol'], $regla_bonus['jefe_add_sol'], $linea_venta);

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
            'bonus_aplicado' => $bonus_aplicado,
            // Popup de racha (semana perfecta). null si no aplica.
            'popup_racha' => billetera_popup_racha_check($user_id),
            // Popup de ranking #1 de la tienda. null si no aplica.
            'popup_rank' => billetera_popup_rank_check($user_id),
            // Popup de primera moneda del mes. null si no aplica.
            'popup_primera' => billetera_popup_primera_check($user_id, $monto_mostrado, $fecha_datetime),
        ]);
    } else {
        wp_send_json_error(['message' => 'Error al registrar venta']);
    }
}

/**
 * Aplica el efecto de la regla de bono al monto base de una venta.
 * Devuelve [monto_a_guardar, bonus_multiplier, bonus_aplicado].
 * En multiplicar: monto base + multiplier (el total se calcula al leer).
 * En fijo/sumar: el monto ya incluye el bono y multiplier queda 1.0.
 */
function billetera_venta_monto_con_bonus($monto_base_sol, $regla_bonus) {
    if ($regla_bonus['override_sol'] !== null) {
        return [(float) $regla_bonus['override_sol'], 1.0, 1];
    }
    if ($regla_bonus['add_sol'] !== null) {
        $monto = ((float) $monto_base_sol * (float) $regla_bonus['multiplier']) + (float) $regla_bonus['add_sol'];
        return [$monto, 1.0, 1];
    }
    $mult = (float) $regla_bonus['multiplier'];
    $aplicado = ($mult > 1 || !empty($regla_bonus['excepcion'])) ? 1 : 0;
    return [(float) $monto_base_sol, $mult, $aplicado];
}

// AJAX: Registrar venta multi-producto (un identificador/fecha, N ítems).
// Atómico: si algún producto falla la validación, no se registra nada.
// Los ítems se insertan en secuencia para que el bono y su retroactivo
// se activen dentro de la misma venta (igual que registrarlos uno a uno).
add_action('wp_ajax_billetera_register_sale_multi', 'billetera_ajax_register_sale_multi');

function billetera_ajax_register_sale_multi() {
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
    $subcategorias_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    $id_type  = sanitize_text_field($_POST['id_type'] ?? '');
    $id_value = sanitize_text_field($_POST['id_value'] ?? '');
    $fecha    = sanitize_text_field($_POST['fecha'] ?? date('Y-m-d'));
    $items_raw = $_POST['items'] ?? '';
    $items     = is_string($items_raw) ? json_decode(wp_unslash($items_raw), true) : null;

    if (!$id_type || !$id_value || !is_array($items) || empty($items)) {
        wp_send_json_error(['message' => 'Datos incompletos']);
    }
    if (count($items) > 20) {
        wp_send_json_error(['message' => 'Máximo 20 productos por venta']);
    }

    // Validar fecha (igual que v2): válida, no futura; solo backoffice fecha atrás.
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        wp_send_json_error(['message' => 'Formato de fecha inválido']);
    }
    $fecha_timestamp = strtotime($fecha);
    if ($fecha_timestamp === false) {
        wp_send_json_error(['message' => 'Fecha inválida']);
    }
    if ($fecha_timestamp > strtotime(date('Y-m-d'))) {
        wp_send_json_error(['message' => 'No puedes registrar ventas futuras']);
    }
    if (!current_user_can('manage_billetera_catalog')) {
        $fecha = date('Y-m-d');
        $fecha_timestamp = strtotime($fecha);
    }
    $fecha_datetime = date('Y-m-d H:i:s', $fecha_timestamp);

    $user_role = $current_user->roles[0];

    // Distribuidor del usuario (para comisiones específicas y reglas de tienda).
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    $distribuidor_id = null;
    if ($tienda_id) {
        $distribuidor_id = get_post_meta($tienda_id, '_dealer_id', true);
    }

    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    // ---- Fase 1: prevalidar todo SIN escribir (todo o nada) ----
    $validados = [];
    $errores = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            $errores[] = 'Producto inválido en la lista';
            continue;
        }
        $sub_id = intval($it['subcategoria_id'] ?? 0);
        $cant   = max(1, intval($it['cantidad'] ?? 1));
        if (!$sub_id) {
            $errores[] = 'Producto inválido en la lista';
            continue;
        }

        $sub_row = $wpdb->get_row($wpdb->prepare(
            "SELECT nombre FROM $subcategorias_table WHERE id = %d AND activo = 1",
            $sub_id
        ));

        if (!$sub_row) {
            $errores[] = 'Producto no encontrado (ID ' . $sub_id . ')';
            continue;
        }

        // Línea del ítem: POST explícito por ítem si viene; si no, la que
        // coincida con las líneas permitidas del usuario.
        $linea_venta = billetera_resolver_linea_venta(
            $sub_id,
            $codigos_permitidos,
            sanitize_text_field($it['linea_codigo'] ?? '')
        );
        if (!$linea_venta) {
            $errores[] = '“' . $sub_row->nombre . '”: no tienes permiso para esa línea';
            continue;
        }

        $comision = billetera_find_comision($sub_id, $distribuidor_id, $user_role, $linea_venta);
        if (!$comision) {
            $errores[] = '“' . $sub_row->nombre . '”: sin comisión configurada';
            continue;
        }

        $validados[] = [
            'sub_id'   => $sub_id,
            'cant'     => $cant,
            'linea'    => $linea_venta,
            'comision' => $comision,
        ];
    }

    if ($errores || empty($validados)) {
        $unicos = array_slice(array_unique($errores), 0, 4);
        wp_send_json_error(['message' => 'No se registró nada. ' . implode('. ', $unicos) . '.']);
    }

    // ---- Fase 2: insertar en secuencia dentro de una transacción ----
    $wpdb->query('START TRANSACTION');

    $total     = 0.0;
    $bonus_max = 1.0;
    $bonus_cat = null;
    $bonus_any = 0;
    $first_id  = null;

    foreach ($validados as $it) {
        $monto_total_sol = billetera_convert_to_sol($it['comision']) * $it['cant'];

        $regla_bonus = billetera_aplicar_regla_bonus($it['sub_id'], $user_id, $distribuidor_id, $it['cant'], true, $fecha_datetime, $it['linea']);
        list($monto_total_sol, $bonus_multiplier, $bonus_aplicado) = billetera_venta_monto_con_bonus($monto_total_sol, $regla_bonus);

        $tc_ins = billetera_get_tipo_cambio();
        $result = $wpdb->insert($ventas_table, [
            'usuario_id' => $user_id,
            'subcategoria_id' => $it['sub_id'],
            'linea_codigo' => $it['linea'],
            'cantidad' => $it['cant'],
            'monto_comision_sol' => $monto_total_sol,
            'id_type' => $id_type,
            'id_value' => $id_value,
            'bonus_multiplier' => $bonus_multiplier,
            'bonus_aplicado' => $bonus_aplicado,
            'tc_aplicado' => $tc_ins,
            'creado_en' => $fecha_datetime,
        ], ['%d', '%d', '%s', '%d', '%f', '%s', '%s', '%f', '%d', '%f', '%s']);

        if (!$result) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Error al registrar la venta']);
        }
        if ($first_id === null) {
            $first_id = (int) $wpdb->insert_id;
        }

        // Espejo del jefe por ítem (con la misma regla de bonificación).
        billetera_register_jefe_comision($tienda_id, $it['sub_id'], $it['cant'], $distribuidor_id, $id_type, $id_value, $user_id, $fecha_datetime, $regla_bonus['jefe_multiplier'], $regla_bonus['jefe_override_sol'], $regla_bonus['jefe_add_sol'], $it['linea']);

        if ($bonus_aplicado) {
            $bonus_any = 1;
            if ($bonus_multiplier > $bonus_max) {
                $bonus_max = $bonus_multiplier;
            }
            if ($bonus_cat === null && !empty($regla_bonus['categoria_nombre'])) {
                $bonus_cat = $regla_bonus['categoria_nombre'];
            }
        }
    }

    // Total real de la venta: se lee de la BD (dentro de la transacción) porque
    // un ítem posterior pudo disparar un retroactivo sobre ítems anteriores
    // de esta misma venta.
    if ($first_id !== null) {
        $total = (float) $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(monto_comision_sol * bonus_multiplier), 0)
            FROM $ventas_table
            WHERE usuario_id = %d
              AND id >= %d
              AND creado_en = %s
              AND id_type = %s
              AND id_value = %s
        ", $user_id, $first_id, $fecha_datetime, $id_type, $id_value));
    }

    $wpdb->query('COMMIT');

    // El ranking del mes cambió: invalida la caché
    billetera_rank_cache_bust();

    wp_send_json_success([
        'message' => 'Venta registrada',
        'amount' => round($total, 2),
        'items' => count($validados),
        'id_type_label' => ucfirst($id_type),
        'id_value' => $id_value,
        'bonus_multiplier' => floatval($bonus_max),
        'bonus_categoria' => $bonus_cat,
        'bonus_aplicado' => $bonus_any,
        // Popup de racha (semana perfecta). null si no aplica.
        'popup_racha' => billetera_popup_racha_check($user_id),
        // Popup de ranking #1 de la tienda. null si no aplica.
        'popup_rank' => billetera_popup_rank_check($user_id),
        // Popup de primera moneda del mes. null si no aplica.
        'popup_primera' => billetera_popup_primera_check($user_id, $total, $fecha_datetime),
    ]);
}

// ===========================================================================
// POPUP DE RACHA (semana perfecta, solo asesores)
// ===========================================================================

/**
 * Popup "Racha": el asesor vendió TODOS los días laborales de la semana
 * (lunes a sábado, domingos no cuentan). Dispara solo el SÁBADO (último día
 * laboral de la semana) en su primera venta de ese día, que completa el 6to
 * día. Se muestra 1 vez por semana: meta _popup_racha_visto = semana ISO.
 * Devuelve los datos del popup o null si no aplica.
 */
function billetera_popup_racha_check($user_id) {
    $u = get_userdata($user_id);
    if (!$u || !in_array('asesor', (array) $u->roles, true)) {
        return null;
    }

    $now = new DateTime('now', wp_timezone());

    // Solo el último día laboral de la semana (sábado) dispara el popup.
    if (intval($now->format('w')) !== 6) {
        return null;
    }

    // Rango de la semana laboral en curso: lunes (hoy -5 días) a sábado.
    $lunes = (clone $now)->modify('-5 days');
    $desde = $lunes->format('Y-m-d 00:00:00');
    $hasta = $now->format('Y-m-d 23:59:59');

    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    // Días con al menos 1 venta propia (la venta que dispara ya está guardada).
    $dias = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT DATE(creado_en)
        FROM $ventas_table
        WHERE usuario_id = %d AND asesor_id IS NULL
          AND creado_en >= %s AND creado_en <= %s
    ", $user_id, $desde, $hasta));

    if (count($dias) < 6) {
        return null;
    }

    // 1 vez por semana: si ya vio el popup esta semana, no vuelve a salir.
    $semana = $now->format('o-\WW');
    if (get_user_meta($user_id, '_popup_racha_visto', true) === $semana) {
        return null;
    }

    // % de asesores con ventas esta semana que vendieron los 6 días
    // ("Solo el X% de los asesores lo logra").
    $pct = 100;
    $asesores = get_users(['role' => 'asesor', 'fields' => 'ID', 'number' => -1]);
    if ($asesores) {
        $in = implode(',', array_fill(0, count($asesores), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT usuario_id, COUNT(DISTINCT DATE(creado_en)) AS dias
            FROM $ventas_table
            WHERE usuario_id IN ($in) AND asesor_id IS NULL
              AND creado_en >= %s AND creado_en <= %s
            GROUP BY usuario_id
        ", array_merge(array_map('intval', $asesores), [$desde, $hasta])));

        $con_ventas = 0;
        $lograron   = 0;
        foreach ((array) $rows as $r) {
            $con_ventas++;
            if (intval($r->dias) >= 6) {
                $lograron++;
            }
        }
        if ($con_ventas > 0) {
            $pct = (int) round(($lograron / $con_ventas) * 100);
        }
    }

    return [
        'dias'   => 6,
        'pct'    => $pct,
        'semana' => $semana,
    ];
}

// AJAX: marca el popup de racha como visto (se guarda la semana ISO).
add_action('wp_ajax_billetera_popup_racha_visto', 'billetera_ajax_popup_racha_visto');
function billetera_ajax_popup_racha_visto() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_register_sale')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $semana = sanitize_text_field($_POST['semana'] ?? '');
    if (!$semana) {
        wp_send_json_error(['message' => 'Semana inválida']);
    }

    update_user_meta(get_current_user_id(), '_popup_racha_visto', $semana);
    wp_send_json_success([]);
}

// ===========================================================================
// POPUP META DEL MES SUPERADA (solo asesores)
// ===========================================================================

/**
 * Popup "Meta del mes": el asesor cerró el MES ANTERIOR con comisiones >= a
 * su meta. Se evalúa al cargar cualquier página de la app y se muestra en el
 * primer ingreso del mes (siempre que sea día laborable, Lun-Sáb, y ya haya
 * pasado el primer día laborable del mes). 1 vez por mes: meta
 * _popup_meta_visto = mes celebrado (Y-m). Devuelve los datos o null.
 */
function billetera_popup_meta_check($user_id) {
    $u = get_userdata($user_id);
    if (!$u || !in_array('asesor', (array) $u->roles, true)) {
        return null;
    }

    $now = new DateTime('now', wp_timezone());

    // Solo días laborables (domingo no dispara).
    if (intval($now->format('w')) === 0) {
        return null;
    }

    // Debe haber pasado el primer día laborable del mes en curso.
    $primer_laborable = new DateTime($now->format('Y-m-01'), wp_timezone());
    while (intval($primer_laborable->format('w')) === 0) {
        $primer_laborable->modify('+1 day');
    }
    if ($now < $primer_laborable) {
        return null;
    }

    // Mes que se celebra: el anterior al actual.
    $mes_celebrado = (clone $now)->modify('first day of previous month');
    $mes_key = $mes_celebrado->format('Y-m');
    $desde   = $mes_celebrado->format('Y-m-01 00:00:00');
    $hasta   = $mes_celebrado->format('Y-m-t 23:59:59');

    // 1 vez por mes.
    if (get_user_meta($user_id, '_popup_meta_visto', true) === $mes_key) {
        return null;
    }

    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    // Comisiones ahorradas en el mes celebrado (ventas propias).
    $ahorrado = floatval($wpdb->get_var($wpdb->prepare("
        SELECT COALESCE(SUM(monto_comision_sol * bonus_multiplier), 0)
        FROM $ventas_table
        WHERE usuario_id = %d AND asesor_id IS NULL
          AND creado_en >= %s AND creado_en <= %s
    ", $user_id, $desde, $hasta)));

    // Meta del asesor (específica o global). Sin meta no hay popup.
    $meta = billetera_get_meta_por_usuario($user_id);
    if ($meta <= 0 || $ahorrado < $meta) {
        return null;
    }

    $pct = (int) round(($ahorrado / $meta) * 100);

    // ¿Su mejor mes del año? Compara contra los meses anteriores del año
    // (sin incluir el mes celebrado). Enero: no hay previos -> es el mejor.
    $es_mejor_mes = true;
    $anio = intval($mes_celebrado->format('Y'));
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT DATE_FORMAT(creado_en, '%Y-%m') AS mes, SUM(monto_comision_sol * bonus_multiplier) AS total
        FROM $ventas_table
        WHERE usuario_id = %d AND asesor_id IS NULL
          AND creado_en >= %s AND creado_en < %s
        GROUP BY mes
    ", $user_id, $anio . '-01-01 00:00:00', $desde));
    foreach ((array) $rows as $r) {
        if (floatval($r->total) > $ahorrado) {
            $es_mejor_mes = false;
            break;
        }
    }

    $meses = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
              7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

    return [
        'pct'          => $pct,
        'ahorrado'     => round($ahorrado, 2),
        'meta'         => round($meta, 2),
        'mes'          => $meses[intval($mes_celebrado->format('n'))],
        'mes_key'      => $mes_key,
        'es_mejor_mes' => $es_mejor_mes,
    ];
}

// AJAX: consulta del popup de meta (se llama al cargar cualquier página de la
// app). Lectura simple, sin cambios de estado: no requiere nonce.
add_action('wp_ajax_billetera_popup_meta_check', 'billetera_ajax_popup_meta_check');
function billetera_ajax_popup_meta_check() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    $data = billetera_popup_meta_check(get_current_user_id());
    wp_send_json_success($data);
}

// AJAX: marca el popup de meta como visto (se guarda el mes celebrado Y-m).
add_action('wp_ajax_billetera_popup_meta_visto', 'billetera_ajax_popup_meta_visto');
function billetera_ajax_popup_meta_visto() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_popup_meta')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $mes_key = sanitize_text_field($_POST['mes_key'] ?? '');
    if (!$mes_key || !preg_match('/^\d{4}-\d{2}$/', $mes_key)) {
        wp_send_json_error(['message' => 'Mes inválido']);
    }

    update_user_meta(get_current_user_id(), '_popup_meta_visto', $mes_key);
    wp_send_json_success([]);
}

// ===========================================================================
// POPUP RANKING TOP 3 DE LA TIENDA (solo asesores)
// ===========================================================================

/**
 * Popup "Puesto en tu tienda": el asesor entra al top 3 del ranking de su
 * tienda (mismo criterio de la app: monto de comisiones del mes DESC, empates
 * comparten posición). Requiere al menos 1 venta en el mes (cubre el
 * reinicio del mes con todos en 0). Se chequea al cargar páginas y tras cada
 * venta. La marca de visto es por puesto: si ya vio ese puesto no repite;
 * si baja se borra la marca y vuelve a salir cuando recupere (o alcance otro)
 * puesto del top 3. Meta: _popup_rank_visto = "Y-m|pos" (compatibilidad: la
 * marca antigua "Y-m" cuenta como visto del puesto 1).
 */
function billetera_popup_rank_check($user_id) {
    $user_id = intval($user_id);
    $u = get_userdata($user_id);
    if (!$u || !in_array('asesor', (array) $u->roles, true)) {
        return null;
    }

    // Solo usuarios de su tienda, de su tipo (mismo scope del ranking "tienda").
    $ids = billetera_ranking_scope_ids($user_id, 'tienda');
    if (count($ids) < 1) {
        return null;
    }

    $stats = billetera_ranking_stats_mes();

    // Solo competidores con al menos 1 venta (reinicio del mes: todos en 0
    // => sin popup). El orden relativo no cambia al excluir a los de 0.
    $con_ventas = [];
    foreach ($ids as $id) {
        $id = intval($id);
        if (intval($stats[$id]['ventas'] ?? 0) > 0) {
            $con_ventas[] = $id;
        }
    }

    $mis_ventas = intval($stats[$user_id]['ventas'] ?? 0);
    if ($mis_ventas < 1) {
        // Ya está en 0 (reinicio del mes): se libera cualquier marca de visto
        // para que el popup vuelva a salir cuando reingrese al top 3.
        delete_user_meta($user_id, '_popup_rank_visto');
        return null;
    }

    list($orden, $pos_map) = billetera_ranking_ordenar_posiciones($con_ventas, $stats);

    $mes_key = (new DateTime('now', wp_timezone()))->format('Y-m');

    // ¿Está en el top 3? (puede compartir la posición en empate, igual que la app).
    $pos_actual = intval($pos_map[$user_id] ?? 99);
    if ($pos_actual < 1 || $pos_actual > 3) {
        // Fuera del top 3: se libera la marca para que vuelva a salir al reingresar.
        if (get_user_meta($user_id, '_popup_rank_visto', true)) {
            delete_user_meta($user_id, '_popup_rank_visto');
        }
        return null;
    }

    // Marca de visto por puesto ("Y-m|pos"). La marca antigua (solo "Y-m")
    // cuenta como visto del puesto 1 para no repetir el popup ya mostrado.
    $visto = (string) get_user_meta($user_id, '_popup_rank_visto', true);
    if ($visto === $mes_key . '|' . $pos_actual || ($pos_actual === 1 && $visto === $mes_key)) {
        return null;
    }

    // Top 3 de la tienda para la tarjeta.
    $top = [];
    foreach (array_slice($orden, 0, 3) as $id) {
        $s = $stats[$id] ?? [];
        $top[] = [
            'pos'    => intval($pos_map[$id]),
            'nombre' => get_userdata($id) ? get_userdata($id)->display_name : ('Usuario #' . $id),
            'ventas' => intval($s['ventas'] ?? 0),
            'es_yo'  => ($id === $user_id),
        ];
    }

    $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
    $tienda = $tienda_id ? get_post($tienda_id) : null;

    return [
        'top'    => $top,
        'pos'    => $pos_actual,
        'tienda' => $tienda ? html_entity_decode($tienda->post_title, ENT_QUOTES, 'UTF-8') : '',
        'mes_key' => $mes_key,
    ];
}

// AJAX: consulta del popup de ranking (al cargar cualquier página de la app).
// Lectura simple: no requiere nonce.
add_action('wp_ajax_billetera_popup_rank_check', 'billetera_ajax_popup_rank_check');
function billetera_ajax_popup_rank_check() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    $data = billetera_popup_rank_check(get_current_user_id());
    wp_send_json_success($data);
}

// AJAX: marca el popup de ranking como visto para el mes y puesto (Y-m|pos).
add_action('wp_ajax_billetera_popup_rank_visto', 'billetera_ajax_popup_rank_visto');
function billetera_ajax_popup_rank_visto() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_popup_rank')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $mes_key = sanitize_text_field($_POST['mes_key'] ?? '');
    if (!$mes_key || !preg_match('/^\d{4}-\d{2}$/', $mes_key)) {
        wp_send_json_error(['message' => 'Mes inválido']);
    }

    $pos = intval($_POST['pos'] ?? 1);
    if ($pos < 1 || $pos > 3) {
        $pos = 1;
    }

    update_user_meta(get_current_user_id(), '_popup_rank_visto', $mes_key . '|' . $pos);
    wp_send_json_success([]);
}

// ===========================================================================
// POPUP PRIMERA MONEDA DEL MES (solo asesores)
// ===========================================================================

/**
 * Popup "Primera moneda": la venta recién registrada es la PRIMERA del mes
 * para el asesor (sin ventas propias previas en el mes en curso). 1 vez por
 * mes: meta _popup_primera_visto = Y-m. Devuelve los datos del popup o null.
 * $monto: comisión total de la venta (con bono); $fecha_datetime: fecha/hora
 * de la venta (Y-m-d H:i:s local, ya insertada en la BD).
 */
function billetera_popup_primera_check($user_id, $monto, $fecha_datetime) {
    $u = get_userdata($user_id);
    if (!$u || !in_array('asesor', (array) $u->roles, true)) {
        return null;
    }
    if (!$fecha_datetime || floatval($monto) <= 0) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $fecha_datetime, wp_timezone());
    if (!$dt) {
        return null;
    }

    $mes_key = $dt->format('Y-m');
    if (get_user_meta($user_id, '_popup_primera_visto', true) === $mes_key) {
        return null;
    }

    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    // ¿Es su primera venta del mes? Sin ventas propias anteriores en el mes.
    $desde   = $dt->format('Y-m-01 00:00:00');
    $previas = intval($wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM $ventas_table
        WHERE usuario_id = %d AND asesor_id IS NULL
          AND creado_en >= %s AND creado_en < %s
    ", $user_id, $desde, $fecha_datetime)));

    if ($previas > 0) {
        return null;
    }

    // "antes que el X% de los asesores": de los asesores con al menos 1 venta
    // propia este mes, qué % registró su primera venta DESPUÉS que esta.
    $pct = 0;
    $asesores = get_users(['role' => 'asesor', 'fields' => 'ID', 'number' => -1]);
    if ($asesores) {
        $in = implode(',', array_fill(0, count($asesores), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT usuario_id, MIN(creado_en) AS primera
            FROM $ventas_table
            WHERE usuario_id IN ($in) AND asesor_id IS NULL
              AND creado_en >= %s
            GROUP BY usuario_id
        ", array_merge(array_map('intval', $asesores), [$desde])));

        $total = 0;
        $later = 0;
        foreach ((array) $rows as $r) {
            $total++;
            if (strcmp((string) $r->primera, $fecha_datetime) > 0) {
                $later++;
            }
        }
        if ($total > 0) {
            $pct = (int) round(($later / $total) * 100);
        }
    }

    // Saludo según la hora de la venta.
    $h = intval($dt->format('G'));
    $saludo = $h < 10 ? '¡Madrugador!' : ($h < 15 ? '¡Temprano!' : '¡Buen arranque!');

    // Hora para el chip: "7:42 a.m."
    $hora = $dt->format('g:i') . ' ' . ($dt->format('a') === 'am' ? 'a.m.' : 'p.m.');

    $meses = [1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
              7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'];

    return [
        'monto'   => round(floatval($monto), 2),
        'hora'    => $hora,
        'mes'     => $meses[intval($dt->format('n'))],
        'pct'     => $pct,
        'saludo'  => $saludo,
        'mes_key' => $mes_key,
    ];
}

// AJAX: marca el popup de primera moneda como visto para el mes (Y-m).
add_action('wp_ajax_billetera_popup_primera_visto', 'billetera_ajax_popup_primera_visto');
function billetera_ajax_popup_primera_visto() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_popup_primera')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $mes_key = sanitize_text_field($_POST['mes_key'] ?? '');
    if (!$mes_key || !preg_match('/^\d{4}-\d{2}$/', $mes_key)) {
        wp_send_json_error(['message' => 'Mes inválido']);
    }

    update_user_meta(get_current_user_id(), '_popup_primera_visto', $mes_key);
    wp_send_json_success([]);
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

// Meta de una SUCURSAL en unidades: suma de las metas por categoría de la
// tabla wp_billetera_metas_sucursal (vista "Metas por sucursal") para la
// tienda y tipo dados. 'configurada' indica si tiene al menos 1 categoría.
function billetera_get_meta_sucursal($tienda_id, $tipo) {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_metas_sucursal';

    $unidades = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(unidades), 0) FROM $table WHERE tienda_id = %d AND tipo = %s",
        intval($tienda_id), $tipo
    )));

    return [
        'unidades'    => $unidades,
        'configurada' => $unidades > 0,
    ];
}

// Mapa tienda_id => unidades totales para un tipo (Venta / Post Venta).
// Evita consultar la tabla por cada sucursal en rankings y reportes.
function billetera_get_meta_sucursal_map($tipo) {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_metas_sucursal';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT tienda_id, SUM(unidades) AS total FROM $table WHERE tipo = %s GROUP BY tienda_id",
        $tipo
    ));

    $out = [];
    foreach ((array) $rows as $r) {
        $out[intval($r->tienda_id)] = intval($r->total);
    }
    return $out;
}

// Desglose por categoría de la meta de una sucursal (unidades por categoría).
function billetera_get_meta_sucursal_rows($tienda_id, $tipo = '') {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_metas_sucursal';

    $where_tipo = '';
    $params = [intval($tienda_id)];
    if ($tipo !== '') {
        $where_tipo = ' AND tipo = %s';
        $params[] = $tipo;
    }

    return $wpdb->get_results($wpdb->prepare(
        "SELECT m.*, c.nombre AS categoria_nombre
         FROM $table m
         LEFT JOIN {$wpdb->prefix}billetera_categorias c ON c.id = m.categoria_id
         WHERE m.tienda_id = %d$where_tipo
         ORDER BY m.tipo, m.linea_codigo, c.nombre",
        ...$params
    ));
}

/**
 * Líneas (códigos) en las que está disponible una subcategoría — los
 * "checkboxes" de la tabla puente, ordenados por orden_display de la línea.
 */
function billetera_sub_lineas($subcategoria_id) {
    global $wpdb;
    $bridge = $wpdb->prefix . 'billetera_subcategoria_lineas';
    $lin = $wpdb->prefix . 'billetera_lineas';

    return $wpdb->get_col($wpdb->prepare("
        SELECT b.linea_codigo
        FROM $bridge b
        JOIN $lin l ON l.codigo = b.linea_codigo AND l.activo = 1
        WHERE b.subcategoria_id = %d
        ORDER BY l.orden_display ASC, l.codigo ASC
    ", intval($subcategoria_id)));
}

/**
 * Resuelve la línea de una venta para una subcategoría:
 * 1) si el POST trae línea válida (disponible en la subcat y permitida), se usa;
 * 2) si no, intersección de líneas de la subcat con las permitidas del usuario;
 * 3) si hay varias, gana la primera por orden_display (criterio estable).
 * Devuelve '' si no hay línea válida.
 */
function billetera_resolver_linea_venta($subcategoria_id, $codigos_permitidos, $linea_post = '') {
    $lineas_sub = billetera_sub_lineas($subcategoria_id);
    if (empty($lineas_sub)) {
        return '';
    }
    $linea_post = strtolower(trim((string) $linea_post));
    if ($linea_post && in_array($linea_post, $lineas_sub, true)) {
        if (empty($codigos_permitidos) || in_array($linea_post, $codigos_permitidos, true)) {
            return $linea_post;
        }
        return '';
    }
    $inter = empty($codigos_permitidos)
        ? $lineas_sub
        : array_values(array_intersect($lineas_sub, $codigos_permitidos));
    if (empty($inter)) {
        return '';
    }
    return (string) $inter[0];
}

/**
 * Inserta el vínculo subcategoría ↔ líneas (checkboxes). $lineas: array de
 * códigos. Reemplaza los vínculos anteriores de esa subcategoría.
 */
function billetera_set_sub_lineas($subcategoria_id, $lineas) {
    global $wpdb;
    $bridge = $wpdb->prefix . 'billetera_subcategoria_lineas';
    $wpdb->delete($bridge, ['subcategoria_id' => intval($subcategoria_id)], ['%d']);
    foreach ((array) $lineas as $codigo) {
        $codigo = sanitize_text_field($codigo);
        if ($codigo === '') {
            continue;
        }
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $bridge (subcategoria_id, linea_codigo) VALUES (%d, %s)",
            intval($subcategoria_id), $codigo
        ));
    }
}

function billetera_find_comision($subcategoria_id, $distribuidor_id, $rol, $linea_codigo = '') {
    global $wpdb;
    $comisiones_table = $wpdb->prefix . 'billetera_comisiones';

    if ($distribuidor_id) {
        $comision = $wpdb->get_row($wpdb->prepare("
            SELECT c.monto, c.moneda
            FROM $comisiones_table c
            WHERE c.subcategoria_id = %d
            AND c.linea_codigo = %s
            AND c.distribuidor_id = %d
            AND c.rol = %s
            AND c.activo = 1
            LIMIT 1
        ", $subcategoria_id, $linea_codigo, $distribuidor_id, $rol));

        if ($comision) {
            return $comision;
        }
    }

    return $wpdb->get_row($wpdb->prepare("
        SELECT c.monto, c.moneda
        FROM $comisiones_table c
        WHERE c.subcategoria_id = %d
        AND c.linea_codigo = %s
        AND c.distribuidor_id IS NULL
        AND c.rol = %s
        AND c.activo = 1
        LIMIT 1
    ", $subcategoria_id, $linea_codigo, $rol));
}

function billetera_convert_to_sol($comision) {
    $monto_sol = floatval($comision->monto);
    if ($comision->moneda === 'USD') {
        $monto_sol = $monto_sol * billetera_get_tipo_cambio();
    }
    return $monto_sol;
}

// ============================================================================
// REGLAS DE BONIFICACIÓN v2 + ESCALERA (configurables desde el admin)
// Alcance en 4 ejes independientes (0/'' = Todas): categoría, marca/línea,
// subcategoría y concesionaria (scope_dealer_id). Entre distintos alcances
// solo UNA gana (la más específica). Con idéntico alcance, las reglas
// forman una escalera por umbral: aplica el tramo más alto alcanzado y al
// subir de tramo se revalorizan las anteriores del mes.
// Alcance en 4 ejes independientes (0/'' = Todas): categoría, marca/línea,
// subcategoría y concesionaria (scope_dealer_id). Solo UNA regla gana por
// venta: la más específica (mayor score). La general nunca se suma.
// Cada regla mantiene umbral, multiplicador y excepción opcional por
// concesionaria (monto fijo en USD/PEN por unidad, para asesor y jefe).
// ============================================================================

/**
 * Lee las reglas de bonificación (opción). Siembra las reglas por defecto la 1.ª vez.
 * Siempre devuelve reglas normalizadas a v2 (con scope_*, efecto_* y creada_en).
 * y con las excepciones legacy migradas a reglas de tienda (efecto fijo/sumar).
 */
function billetera_bonus_reglas() {
    $reglas = get_option('billetera_bonus_reglas', null);
    if ($reglas === null) {
        $reglas = billetera_bonus_seed_reglas();
    }
    if (!is_array($reglas)) {
        return [];
    }
    $out = [];
    $dirty = false;
    foreach ($reglas as $r) {
        if (!is_array($r)) {
            continue;
        }
        $n = billetera_bonus_normalize_regla($r);
        if ($n !== $r) {
            $dirty = true;
        }
        $out[] = $n;
    }
    if (billetera_bonus_migrar_excepciones($out)) {
        $dirty = true;
    }
    if ($dirty) {
        update_option('billetera_bonus_reglas', $out, false);
    }
    return $out;
}

/**
 * Migra excepciones legacy ("tienda X con monto fijo") a reglas aparte.
 * La regla original queda como multiplicador general sin excepción; la nueva
 * es específica de esa tienda con efecto fijo/sumar y el mismo umbral
 * (o umbral 1 si la excepción aplicaba desde la 1.ª venta). Idempotente.
 * Devuelve true si cambió el array.
 */
function billetera_bonus_migrar_excepciones(&$reglas) {
    $dirty = false;
    $ids = [];
    foreach ($reglas as $r) {
        if (isset($r['id'])) {
            $ids[$r['id']] = true;
        }
    }
    foreach ($reglas as $i => $r) {
        if (!empty($r['excepcion_migrada'])) {
            continue;
        }
        $dealer = intval($r['excepcion_dealer_id'] ?? 0);
        $usd    = (float) ($r['excepcion_usd'] ?? 0);
        if ($dealer <= 0 || $usd <= 0) {
            // Nada que partir: se marca para no reprocesar.
            if ($dealer > 0 || $usd > 0 || (float) ($r['excepcion_jefe_usd'] ?? 0) > 0) {
                $reglas[$i]['excepcion_migrada'] = 1;
                $dirty = true;
            }
            continue;
        }
        $modo = ((($r['excepcion_modo'] ?? 'reemplazar') === 'sumar') ? 'sumar' : 'fijo');
        $desde_umbral = !empty($r['excepcion_desde_umbral']);
        $nuevo_id = (string) ($r['id'] ?? 'r_x') . '_tienda';
        $k = 2;
        while (isset($ids[$nuevo_id])) {
            $nuevo_id = (string) ($r['id'] ?? 'r_x') . '_tienda' . $k;
            $k++;
        }
        $ids[$nuevo_id] = true;
        $nueva = billetera_bonus_normalize_regla($r);
        $nueva['id']                    = $nuevo_id;
        $nueva['scope_dealer_id']       = $dealer;
        $nueva['efecto_tipo']           = $modo;
        $nueva['efecto_valor']          = $usd;
        $nueva['efecto_moneda']         = (($r['excepcion_moneda'] ?? 'USD') === 'PEN') ? 'PEN' : 'USD';
        $nueva['efecto_jefe_valor']     = (float) ($r['excepcion_jefe_usd'] ?? 0);
        $nueva['umbral']                = $desde_umbral ? max(1, intval($r['umbral'] ?? 6)) : 1;
        $nueva['banner_activo']         = 0;
        $nueva['excepcion_dealer_id']   = 0;
        $nueva['excepcion_usd']         = 0;
        $nueva['excepcion_jefe_usd']    = 0;
        $nueva['excepcion_migrada']     = 1;
        $reglas[] = $nueva;

        // La original queda como multiplicador general, sin excepción.
        $reglas[$i]['efecto_tipo']         = 'multiplicar';
        $reglas[$i]['efecto_valor']        = (float) ($r['multiplicador'] ?? 2);
        $reglas[$i]['efecto_moneda']       = 'USD';
        $reglas[$i]['efecto_jefe_valor']   = (float) ($r['multiplicador'] ?? 2);
        $reglas[$i]['excepcion_dealer_id'] = 0;
        $reglas[$i]['excepcion_usd']       = 0;
        $reglas[$i]['excepcion_jefe_usd']  = 0;
        $reglas[$i]['excepcion_migrada']   = 1;
        $dirty = true;
    }
    return $dirty;
}

/**
 * Normaliza una regla legacy a v2 sin cambiar su comportamiento.
 * Legacy: ambito=categoria (por slug, todas las líneas) o ambito=linea (una categoría exacta).
 * v2: scope_categoria_id / scope_slug / scope_linea_codigo / scope_marca_id /
 *     scope_subcategoria_id / scope_dealer_id. (prioridad_manual quedó legacy:
 *     se conserva el dato pero el matcher la ignora.)
 */
function billetera_bonus_normalize_regla($r) {
    if (!isset($r['scope_categoria_id'])) {
        $ambito = $r['ambito'] ?? 'categoria';
        if ($ambito === 'linea') {
            $r['scope_categoria_id'] = intval($r['categoria_id'] ?? 0);
            $r['scope_slug']         = '';
            $r['scope_linea_codigo'] = strtolower(trim((string) ($r['linea_codigo'] ?? '')));
        } else {
            $r['scope_categoria_id'] = 0;
            $r['scope_slug']         = strtolower(trim((string) ($r['slug'] ?? '')));
            $r['scope_linea_codigo'] = '';
        }
    }
    if (!isset($r['scope_slug'])) {
        $r['scope_slug'] = '';
    }
    if (!isset($r['scope_linea_codigo'])) {
        $r['scope_linea_codigo'] = strtolower(trim((string) ($r['linea_codigo'] ?? '')));
        // Si la regla legacy era de categoría (por slug), la línea no filtra.
        if (($r['ambito'] ?? 'categoria') !== 'linea') {
            $r['scope_linea_codigo'] = '';
        }
    }
    if (!isset($r['scope_marca_id'])) {
        $r['scope_marca_id'] = 0;
    }
    if (!isset($r['scope_subcategoria_id'])) {
        $r['scope_subcategoria_id'] = 0;
    }
    if (!isset($r['scope_dealer_id'])) {
        $r['scope_dealer_id'] = 0;
    }
    if (!isset($r['prioridad_manual'])) {
        $r['prioridad_manual'] = 0;
    }
    // Fecha de creación: el umbral cuenta ventas desde lo más tarde entre
    // inicio de mes, vigencia y creación (las ventas anteriores a crear la
    // regla no cuentan). Legacy: '' = comportamiento anterior.
    if (!isset($r['creada_en'])) {
        $r['creada_en'] = '';
    }
    // Efecto del bono: multiplicar (×N) | fijo (US$ N en vez de la comisión)
    // | sumar (comisión + US$ N). Las excepciones legacy ya vienen migradas.
    if (!isset($r['efecto_tipo']) || !in_array($r['efecto_tipo'], ['multiplicar', 'fijo', 'sumar'], true)) {
        $r['efecto_tipo'] = 'multiplicar';
        $r['efecto_valor'] = (float) ($r['multiplicador'] ?? 2);
        $r['efecto_moneda'] = 'USD';
        $r['efecto_jefe_valor'] = (float) ($r['multiplicador'] ?? 2);
    }
    if (!isset($r['efecto_moneda']) || !in_array($r['efecto_moneda'], ['USD', 'PEN'], true)) {
        $r['efecto_moneda'] = 'USD';
    }
    $r['scope_categoria_id']   = intval($r['scope_categoria_id']);
    $r['scope_marca_id']       = intval($r['scope_marca_id']);
    $r['scope_subcategoria_id'] = intval($r['scope_subcategoria_id']);
    $r['scope_dealer_id']      = intval($r['scope_dealer_id']);
    $r['prioridad_manual']     = intval($r['prioridad_manual']);
    $r['scope_linea_codigo']   = strtolower(trim((string) $r['scope_linea_codigo']));
    $r['scope_slug']           = strtolower(trim((string) $r['scope_slug']));
    return $r;
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

    $pre = $wpdb->get_row("SELECT id, nombre, slug FROM $cat WHERE LOWER(slug) = 'prepagados' OR LOWER(nombre) = 'prepagados' ORDER BY id ASC LIMIT 1");
    if ($pre) {
        $reglas[] = array_merge($base, [
            'id'            => 'r_prepagados',
            'categoria_id'  => intval($pre->id),
            'slug'          => $pre->slug,
            'nombre'        => $pre->nombre,
            'umbral'        => max(1, (int) get_option('billetera_bonus_prepagados_umbral', 6)),
            'multiplicador' => (float) get_option('billetera_bonus_prepagados_multiplicador', 2),
            'aplica_jefe'   => (int) get_option('billetera_bonus_prepagados_jefe', 1),
        ]);
    }

    $kit = $wpdb->get_row("SELECT id, nombre, slug FROM $cat WHERE LOWER(slug) = 'kit360' OR LOWER(nombre) = 'kit360' ORDER BY id ASC LIMIT 1");
    if ($kit) {
        $reglas[] = array_merge($base, [
            'id'                     => 'r_kit360',
            'categoria_id'           => intval($kit->id),
            'slug'                   => $kit->slug,
            'nombre'                 => $kit->nombre,
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
 * Inicio del conteo del umbral: lo más tarde entre inicio de mes, vigencia
 * y creación de la regla. Las ventas anteriores a crear la regla no cuentan.
 */
function billetera_bonus_inicio_conteo($regla, $mes_inicio) {
    $inicio = (string) $mes_inicio;
    if (!empty($regla['banner_desde'])) {
        $d = (string) $regla['banner_desde'] . ' 00:00:00';
        if ($d > $inicio) {
            $inicio = $d;
        }
    }
    $creada = trim((string) ($regla['creada_en'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $creada)) {
        $c = substr($creada, 0, 10) . ' 00:00:00';
        if ($c > $inicio) {
            $inicio = $c;
        }
    }
    return $inicio;
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
 * Contexto de una venta para el matcher v2: categoría, slug, línea y marca.
 * La línea es la de la venta (resuelta al registrar); si no viene, se toma
 * la primera línea de la subcategoría (tabla puente). Devuelve null si la
 * subcategoría no existe.
 */
function billetera_bonus_ctx($subcategoria_id, $linea_codigo = '') {
    global $wpdb;
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';
    $lin = $wpdb->prefix . 'billetera_lineas';
    $row = $wpdb->get_row($wpdb->prepare("
        SELECT c.id AS categoria_id, c.slug AS slug, c.nombre AS categoria_nombre,
               s.id AS subcategoria_id
        FROM $sub s
        JOIN $cat c ON c.id = s.categoria_id
        WHERE s.id = %d
    ", intval($subcategoria_id)));
    if (!$row) {
        return null;
    }
    $linea = strtolower(trim((string) $linea_codigo));
    if ($linea === '') {
        $lineas = billetera_sub_lineas($subcategoria_id);
        $linea = strtolower((string) ($lineas[0] ?? ''));
    }
    $marca_id = 0;
    if ($linea !== '') {
        $marca_id = intval($wpdb->get_var($wpdb->prepare(
            "SELECT marca_id FROM $lin WHERE codigo = %s", $linea
        )));
    }
    return [
        'categoria_id'    => intval($row->categoria_id),
        'slug'            => strtolower((string) $row->slug),
        'categoria_nombre' => (string) $row->categoria_nombre,
        'linea_codigo'    => $linea,
        'marca_id'        => $marca_id,
        'subcategoria_id' => intval($row->subcategoria_id),
    ];
}

/**
 * Score de especificidad de una regla para un contexto + dealer.
 * Devuelve false si la regla NO aplica. Mayor score = más específica.
 *  +40 subcategoría exacta, +20 concesionaria (alcance), +10 categoría exacta,
 *  +5 línea exacta, +3 slug (toda la categoría), +2 marca exacta.
 *  El desempate manual se eliminó: a igual especificidad gana la más reciente.
 */
function billetera_bonus_regla_match_score($regla, $ctx, $dealer_id) {
    $regla = billetera_bonus_normalize_regla($regla);
    $dealer_id = intval($dealer_id);
    $score = 0;

    $scope_sub = intval($regla['scope_subcategoria_id'] ?? 0);
    if ($scope_sub > 0) {
        if ($ctx['subcategoria_id'] !== $scope_sub) {
            return false;
        }
        $score += 40;
    }

    $scope_dealer = intval($regla['scope_dealer_id'] ?? 0);
    if ($scope_dealer > 0) {
        if ($dealer_id <= 0 || $dealer_id !== $scope_dealer) {
            return false;
        }
        $score += 20;
    }

    $scope_cat = intval($regla['scope_categoria_id'] ?? 0);
    if ($scope_cat > 0) {
        if ($ctx['categoria_id'] !== $scope_cat) {
            return false;
        }
        $score += 10;
    } else {
        $slug = (string) ($regla['scope_slug'] ?? ($regla['slug'] ?? ''));
        $slug = strtolower(trim($slug));
        if ($slug !== '') {
            if ($ctx['slug'] !== $slug) {
                return false;
            }
            $score += 3;
            // Retrocompat: si la regla legacy apuntaba a esta categoría exacta,
            // pequeño plus para preservar "exacta antes que slug genérico".
            if (intval($regla['categoria_id'] ?? 0) === $ctx['categoria_id']) {
                $score += 1;
            }
        }
        // scope_categoria 0 + slug '' = regla global "todas": score 0, aplica a todo.
    }

    $scope_linea = strtolower(trim((string) ($regla['scope_linea_codigo'] ?? '')));
    if ($scope_linea !== '') {
        if ($ctx['linea_codigo'] !== $scope_linea) {
            return false;
        }
        $score += 5;
    }

    $scope_marca = intval($regla['scope_marca_id'] ?? 0);
    if ($scope_marca > 0) {
        if (intval($ctx['marca_id']) !== $scope_marca) {
            return false;
        }
        $score += 2;
    }

    return $score;
}

/**
 * Mejor regla v2 para una venta: solo UNA gana, la más específica.
 * A igual especificidad gana la más reciente (última del array).
 * Devuelve la regla normalizada o null.
 */
function billetera_bonus_find_best_regla($subcategoria_id, $dealer_id = 0, $fecha_ymd = null, $linea_codigo = '') {
    $ctx = billetera_bonus_ctx($subcategoria_id, $linea_codigo);
    if (!$ctx) {
        return null;
    }
    if ($fecha_ymd === null) {
        $fecha_ymd = billetera_stats_local_now()->format('Y-m-d');
    }
    $best = null;
    $best_score = -1;
    $best_idx = -1;
    foreach (billetera_bonus_reglas() as $idx => $r) {
        if (empty($r['activo'])) {
            continue;
        }
        if (!billetera_regla_vigente_en($r, $fecha_ymd)) {
            continue;
        }
        $s = billetera_bonus_regla_match_score($r, $ctx, $dealer_id);
        if ($s === false) {
            continue;
        }
        if ($s > $best_score || ($s === $best_score && $idx >= $best_idx)) {
            $best = billetera_bonus_normalize_regla($r);
            $best_score = $s;
            $best_idx = $idx;
        }
    }
    return $best;
}

/**
 * Condición SQL v2 del ámbito de una regla: [sql, params].
 * Cubre subcategoría / categoría exacta / slug / línea / marca.
 * NOTA: scope_dealer_id NO filtra el conteo (la tabla de ventas no guarda
 * dealer por fila); el alcance de concesionaria se valida en el matcher
 * (venta actual) y el umbral cuenta el resto del ámbito. Se documenta para
 * Fase 2 (agregar columna dealer_id a ventas si se requiere conteo por tienda).
 */
function billetera_bonus_cond_v2($regla) {
    global $wpdb;
    $regla = billetera_bonus_normalize_regla($regla);
    $bridge = $wpdb->prefix . 'billetera_subcategoria_lineas';
    $lin = $wpdb->prefix . 'billetera_lineas';
    $parts = [];
    $params = [];

    $scope_sub = intval($regla['scope_subcategoria_id'] ?? 0);
    if ($scope_sub > 0) {
        $parts[] = 's.id = %d';
        $params[] = $scope_sub;
    } elseif (intval($regla['scope_categoria_id'] ?? 0) > 0) {
        $parts[] = 'c.id = %d';
        $params[] = intval($regla['scope_categoria_id']);
    } else {
        $slug = (string) ($regla['scope_slug'] ?? ($regla['slug'] ?? ''));
        $slug = strtolower(trim($slug));
        if ($slug !== '') {
            $parts[] = '(LOWER(c.slug) = %s OR LOWER(c.nombre) = %s)';
            $params[] = $slug;
            $params[] = $slug;
        }
    }

    $scope_linea = strtolower(trim((string) ($regla['scope_linea_codigo'] ?? '')));
    if ($scope_linea !== '') {
        // Línea por la tabla puente (la subcategoría debe estar marcada en ella)
        $parts[] = "s.id IN (SELECT b.subcategoria_id FROM $bridge b WHERE b.linea_codigo = %s)";
        $params[] = $scope_linea;
    }

    $scope_marca = intval($regla['scope_marca_id'] ?? 0);
    if ($scope_marca > 0) {
        $parts[] = "s.id IN (SELECT b.subcategoria_id FROM $bridge b
                    JOIN $lin l ON l.codigo = b.linea_codigo
                    WHERE l.marca_id = %d)";
        $params[] = $scope_marca;
    }

    if (empty($parts)) {
        return ['1=1', []];
    }
    return [implode(' AND ', $parts), $params];
}

/**
 * Frase humana del alcance (para el admin de Fase 2 y el dry-run).
 * Ej: "Prepagados · Todas las marcas · Línea hyu · Todas las subcategorías · Todas las tiendas".
 */
function billetera_bonus_regla_describe($regla) {
    $regla = billetera_bonus_normalize_regla($regla);
    global $wpdb;
    $cat = $wpdb->prefix . 'billetera_categorias';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $lin = $wpdb->prefix . 'billetera_lineas';
    $mar = $wpdb->prefix . 'billetera_marcas';

    $cat_txt = 'Todas las categorías';
    if (intval($regla['scope_categoria_id'] ?? 0) > 0) {
        $n = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $cat WHERE id = %d", intval($regla['scope_categoria_id'])));
        $cat_txt = $n ? (string) $n : ('Categoría #' . intval($regla['scope_categoria_id']));
    } elseif (trim((string) ($regla['scope_slug'] ?? ($regla['slug'] ?? ''))) !== '') {
        $cat_txt = (string) ($regla['nombre'] ?? $regla['slug']);
    }
    $marca_txt = 'Todas las marcas';
    if (intval($regla['scope_marca_id'] ?? 0) > 0) {
        $n = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $mar WHERE id = %d", intval($regla['scope_marca_id'])));
        $marca_txt = $n ? (string) $n : ('Marca #' . intval($regla['scope_marca_id']));
    }
    $linea_txt = 'Todas las líneas';
    if (trim((string) ($regla['scope_linea_codigo'] ?? '')) !== '') {
        $n = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $lin WHERE codigo = %s", (string) $regla['scope_linea_codigo']));
        $linea_txt = 'Línea ' . ($n ? (string) $n : (string) $regla['scope_linea_codigo']);
    }
    $sub_txt = 'Todas las subcategorías';
    if (intval($regla['scope_subcategoria_id'] ?? 0) > 0) {
        $n = $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $sub WHERE id = %d", intval($regla['scope_subcategoria_id'])));
        $sub_txt = $n ? (string) $n : ('Subcategoría #' . intval($regla['scope_subcategoria_id']));
    }
    $dealer_txt = 'Todas las tiendas';
    if (intval($regla['scope_dealer_id'] ?? 0) > 0) {
        $t = get_the_title(intval($regla['scope_dealer_id']));
        $dealer_txt = 'Solo ' . ($t ? (string) $t : ('#' . intval($regla['scope_dealer_id'])));
    }
    return $cat_txt . ' · ' . $marca_txt . ' · ' . $linea_txt . ' · ' . $sub_txt . ' · ' . $dealer_txt;
}

/**
 * Texto corto del efecto para la tabla del admin.
 * Ej: "×2 (+jefe)" · "Fijo US$100/u" · "Base + S/50/u".
 */
function billetera_bonus_efecto_txt($regla) {
    $regla = billetera_bonus_normalize_regla($regla);
    $tipo  = (string) ($regla['efecto_tipo'] ?? 'multiplicar');
    $sim   = ((($regla['efecto_moneda'] ?? 'USD') === 'PEN') ? 'S/' : 'US$');
    $fmt   = function ($v) {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    };
    if ($tipo === 'fijo') {
        $t = 'Fijo ' . $sim . $fmt($regla['efecto_valor'] ?? 0) . '/u';
        if (!empty($regla['aplica_jefe']) && (float) ($regla['efecto_jefe_valor'] ?? 0) > 0) {
            $t .= ' (jefe ' . $sim . $fmt($regla['efecto_jefe_valor']) . ')';
        }
        return $t;
    }
    if ($tipo === 'sumar') {
        $t = 'Base + ' . $sim . $fmt($regla['efecto_valor'] ?? 0) . '/u';
        if (!empty($regla['aplica_jefe']) && (float) ($regla['efecto_jefe_valor'] ?? 0) > 0) {
            $t .= ' (jefe +' . $sim . $fmt($regla['efecto_jefe_valor']) . ')';
        }
        return $t;
    }
    $mult = $fmt($regla['efecto_valor'] ?? ($regla['multiplicador'] ?? 1));
    return '×' . $mult . (!empty($regla['aplica_jefe']) ? ' (+jefe)' : '');
}

/**
 * Clave de escalera: misma tupla de alcance = mismos tramos.
 * Dos reglas con igual clave y distinto umbral/efecto forman una
 * escalera (ej. umbral 4 ×2 y umbral 8 ×3); se evalúan juntas y
 * aplica el tramo más alto alcanzado, con retro al subir de tramo.
 */
function billetera_bonus_escalera_key($regla) {
    $regla = billetera_bonus_normalize_regla($regla);
    return implode('|', [
        strtolower(trim((string) ($regla['scope_slug'] ?? ''))),
        intval($regla['scope_marca_id'] ?? 0),
        strtolower(trim((string) ($regla['scope_linea_codigo'] ?? ''))),
        intval($regla['scope_subcategoria_id'] ?? 0),
        intval($regla['scope_dealer_id'] ?? 0),
    ]);
}

/**
 * Tramos de la escalera de una regla ganadora: misma clave, activas y
 * vigentes a la fecha de la venta. Ordenados por umbral ascendente
 * (a igual umbral, mayor efecto primero; desempate: más reciente).
 */
function billetera_bonus_escalera_grupo($ganadora, $fecha_ymd) {
    $ganadora = billetera_bonus_normalize_regla($ganadora);
    $key = billetera_bonus_escalera_key($ganadora);
    $grupo = [];
    foreach (billetera_bonus_reglas() as $r) {
        if (empty($r['activo'])) {
            continue;
        }
        if (!billetera_regla_vigente_en($r, $fecha_ymd)) {
            continue;
        }
        $r = billetera_bonus_normalize_regla($r);
        if (billetera_bonus_escalera_key($r) !== $key) {
            continue;
        }
        $grupo[] = $r;
    }
    usort($grupo, function ($a, $b) {
        $ua = max(1, intval($a['umbral'] ?? 1));
        $ub = max(1, intval($b['umbral'] ?? 1));
        if ($ua !== $ub) {
            return $ua <=> $ub;
        }
        $ea = (float) ($a['efecto_valor'] ?? ($a['multiplicador'] ?? 1));
        $eb = (float) ($b['efecto_valor'] ?? ($b['multiplicador'] ?? 1));
        if ($ea !== $eb) {
            return $eb <=> $ea;
        }
        return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
    });
    return $grupo;
}

/**
 * Inicio de conteo unificado de una escalera: el mes, la vigencia más
 * exigente del grupo y la creación más antigua (para que un tramo creado
 * después sí cuente las ventas del mismo mes ya registradas).
 */
function billetera_bonus_grupo_inicio($grupo, $mes_inicio) {
    $inicio = (string) $mes_inicio;
    $max_desde = '';
    $min_creada = '';
    foreach ($grupo as $r) {
        $d = trim((string) ($r['banner_desde'] ?? ''));
        if ($d !== '' && ($max_desde === '' || $d > $max_desde)) {
            $max_desde = $d;
        }
        $c = trim((string) ($r['creada_en'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $c)) {
            $c = substr($c, 0, 10);
            if ($min_creada === '' || $c < $min_creada) {
                $min_creada = $c;
            }
        }
    }
    if ($max_desde !== '') {
        $d = $max_desde . ' 00:00:00';
        if ($d > $inicio) {
            $inicio = $d;
        }
    }
    if ($min_creada !== '') {
        $c = $min_creada . ' 00:00:00';
        if ($c > $inicio) {
            $inicio = $c;
        }
    }
    return $inicio;
}

/**
 * Calcula la regla de bonificación para una venta y, si corresponde,
 * aplica el ajuste retroactivo de las ventas anteriores del mes.
 *
 * Escalera: las reglas con idéntico alcance forman tramos por umbral.
 * Aplica el tramo más alto alcanzado (count >= umbral-1) y al subir de
 * tramo revaloriza las anteriores al nuevo tramo.
 *
 * Devuelve:
 *   multiplier, override_sol (asesor) y jefe_multiplier, jefe_override_sol.
 *   override_sol reemplaza el monto base; si es null se usa el del catálogo.
 */
function billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $dealer_id, $cantidad, $aplicar_retro = true, $fecha_datetime = null, $linea_codigo = '') {
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
    // Motor v2 + escalera: gana la más específica; las de idéntico alcance
    // forman tramos por umbral y aplica el tramo más alto alcanzado.
    $ganadora = billetera_bonus_find_best_regla($subcategoria_id, $dealer_id, $fecha_ymd, $linea_codigo);
    if (!$ganadora) {
        return $out;
    }
    $grupo = billetera_bonus_escalera_grupo($ganadora, $fecha_ymd);
    if (empty($grupo)) {
        $grupo = [billetera_bonus_normalize_regla($ganadora)];
    }
    // El tramo se identifica por su id para el reporte.
    $regla = end($grupo);
    $out['categoria_nombre'] = $ganadora['nombre'] ?? $catrow->nombre;

    list($cond, $cond_params) = billetera_bonus_cond_v2($ganadora);

    $mes_inicio = substr($fecha_ymd, 0, 7) . '-01 00:00:00';
    // Conteo unificado de la escalera (misma condición para todos los tramos).
    $inicio = billetera_bonus_grupo_inicio($grupo, $mes_inicio);
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

    // Tramo más alto alcanzado con la venta en curso (count = previas).
    $tramo = null;
    $prev  = null;
    foreach ($grupo as $t) {
        $u = max(1, (int) ($t['umbral'] ?? 1));
        if ($count >= $u - 1) {
            $tramo = $t;
        }
        if ($count - 1 >= $u - 1 && $count > 0) {
            $prev = $t;
        }
    }
    if (!$tramo) {
        return $out;
    }
    $regla = $tramo;
    $out['regla']            = $regla['id'];
    $out['categoria_nombre'] = $regla['nombre'] ?? $out['categoria_nombre'];

    $umbral   = max(1, (int) $regla['umbral']);
    $califica = true;
    // Retro solo al estrenar tramo (al alcanzar su umbral exacto).
    $retro = ($count === $umbral - 1) && (!$prev || (string) ($prev['id'] ?? '') !== (string) ($regla['id'] ?? ''));

    // Efecto de la regla ganadora (las excepciones legacy ya migraron a reglas
    // de tienda con efecto fijo/sumar, así que aquí no hay casos especiales).
    $tc       = billetera_get_tipo_cambio();
    $tipo     = (string) ($regla['efecto_tipo'] ?? 'multiplicar');
    if (!in_array($tipo, ['multiplicar', 'fijo', 'sumar'], true)) {
        $tipo = 'multiplicar';
    }
    $con_jefe = !empty($regla['aplica_jefe']);

    if ($tipo === 'fijo' && $califica) {
        // Paga un monto fijo por unidad en vez de la comisión.
        $factor     = (($regla['efecto_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc;
        $fixed      = round((float) ($regla['efecto_valor'] ?? 0) * $factor * $cantidad, 2);
        $fixed_jefe = round((float) ($regla['efecto_jefe_valor'] ?? 0) * $factor * $cantidad, 2);
        $out['excepcion']          = true;
        $out['override_sol']       = $fixed;
        $out['jefe_override_sol']  = ($con_jefe && (float) ($regla['efecto_jefe_valor'] ?? 0) > 0) ? $fixed_jefe : null;
    } elseif ($tipo === 'sumar' && $califica) {
        // Comisión base + un extra fijo por unidad (sin multiplicador).
        $factor     = (($regla['efecto_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc;
        $fixed      = round((float) ($regla['efecto_valor'] ?? 0) * $factor * $cantidad, 2);
        $fixed_jefe = round((float) ($regla['efecto_jefe_valor'] ?? 0) * $factor * $cantidad, 2);
        $out['excepcion']       = true;
        $out['multiplier']      = 1.0;
        $out['add_sol']         = $fixed;
        $out['jefe_multiplier'] = 1.0;
        $out['jefe_add_sol']    = ($con_jefe && (float) ($regla['efecto_jefe_valor'] ?? 0) > 0) ? $fixed_jefe : null;
    } elseif ($tipo === 'multiplicar' && $califica) {
        $mult = (float) ($regla['efecto_valor'] ?? ($regla['multiplicador'] ?? 1));
        $out['multiplier']      = $mult;
        $out['jefe_multiplier'] = $con_jefe ? $mult : 1.0;
    }

    // El retroactivo se aplica una sola vez por tramo, cuando se alcanza su umbral.
    // Con umbral 1 es un no-op seguro (aún no hay ventas previas que ajustar).
    // Al subir de tramo revaloriza las anteriores al nuevo tramo.
    if ($retro && $aplicar_retro && $califica) {
        billetera_bonus_retroactivo($regla, $user_id, $mes_inicio, $out, $tc, $prev, $inicio);
    }

    return $out;
}

/**
 * Ajusta retroactivamente las ventas anteriores del mes cuando se alcanza
 * un umbral (o se sube de tramo en una escalera).
 * $max_id (opcional, recalc): solo toca filas con id menor (no posteriores).
 */
function billetera_bonus_retroactivo($regla, $user_id, $mes_inicio, $out, $tc, $regla_prev = null, $inicio_override = null, $max_id = null) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';

    $limite = max(1, (int) $regla['umbral'] - 1);
    list($cond, $cond_params) = billetera_bonus_cond_v2($regla);

    // No tocar ventas anteriores a la vigencia ni a la creación (unificado
    // por escalera cuando viene $inicio_override).
    $inicio = $inicio_override !== null ? (string) $inicio_override : billetera_bonus_inicio_conteo($regla, (string) $mes_inicio);

    $subcat_in = "(SELECT s.id FROM $sub s JOIN $cat c ON c.id = s.categoria_id WHERE $cond)";

    // TC congelado: cada venta reescrita usa su propio tc_aplicado (ventas
    // antiguas sin la columna usan el TC actual como respaldo).
    $es_pen = (($regla['efecto_moneda'] ?? 'USD') === 'PEN');

    $cap_sql = '';
    $cap_params = [];
    if ($max_id !== null) {
        $cap_sql = ' AND v.id < %d';
        $cap_params = [intval($max_id)];
    }

    if ($out['override_sol'] !== null) {
        // Fijo: el monto pasa a ser el fijo por unidad (USD * TC de cada venta * cantidad).
        $usd = (float) ($regla['efecto_valor'] ?? 0);
        if ($es_pen) {
            $params = array_merge([$usd, $user_id], $cond_params, [$inicio], $cap_params, [$limite]);
            $set_monto = "v.monto_comision_sol = (%f * v.cantidad)";
        } else {
            $params = array_merge([$usd, $tc, $user_id], $cond_params, [$inicio], $cap_params, [$limite]);
            $set_monto = "v.monto_comision_sol = (%f * COALESCE(v.tc_aplicado, %f) * v.cantidad)";
        }
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET $set_monto, v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s$cap_sql
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_override_sol'] !== null) {
            $usd_j = (float) ($regla['efecto_jefe_valor'] ?? 0);
            if ($es_pen) {
                $params = array_merge([$usd_j, $user_id], $cond_params, [$inicio], $cap_params);
                $set_monto = "v.monto_comision_sol = (%f * v.cantidad)";
            } else {
                $params = array_merge([$usd_j, $tc, $user_id], $cond_params, [$inicio], $cap_params);
                $set_monto = "v.monto_comision_sol = (%f * COALESCE(v.tc_aplicado, %f) * v.cantidad)";
            }
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET $set_monto, v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s$cap_sql
            ", ...$params));
        }
    } elseif ($out['add_sol'] !== null) {
        // Sumar: subir de tramo aplica solo el delta (nuevo - previo) para no
        // duplicar el extra ya pagado. Sin tramo previo, comportamiento base.
        $mult = (float) $out['multiplier'];
        $usd  = (float) ($regla['efecto_valor'] ?? 0);
        $usd_prev = ($regla_prev && (($regla_prev['efecto_tipo'] ?? '') === 'sumar')) ? (float) ($regla_prev['efecto_valor'] ?? 0) : 0.0;
        // Si el tramo previo era fijo, no se puede recuperar la base: se
        // documenta no mezclar fijo con sumar en la misma escalera.
        $delta = $usd - $usd_prev;
        if ($es_pen) {
            $params = array_merge([$mult, $delta, $user_id], $cond_params, [$inicio], $cap_params, [$limite]);
            $set_monto = "v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * v.cantidad)";
        } else {
            $params = array_merge([$mult, $delta, $tc, $user_id], $cond_params, [$inicio], $cap_params, [$limite]);
            $set_monto = "v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * COALESCE(v.tc_aplicado, %f) * v.cantidad)";
        }
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET $set_monto, v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s$cap_sql
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_add_sol'] !== null) {
            $mult_j = (float) $out['jefe_multiplier'];
            $usd_j  = (float) ($regla['efecto_jefe_valor'] ?? 0);
            $usd_j_prev = ($regla_prev && (($regla_prev['efecto_tipo'] ?? '') === 'sumar')) ? (float) ($regla_prev['efecto_jefe_valor'] ?? 0) : 0.0;
            $delta_j = $usd_j - $usd_j_prev;
            if ($es_pen) {
                $params = array_merge([$mult_j, $delta_j, $user_id], $cond_params, [$inicio], $cap_params);
                $set_monto = "v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * v.cantidad)";
            } else {
                $params = array_merge([$mult_j, $delta_j, $tc, $user_id], $cond_params, [$inicio], $cap_params);
                $set_monto = "v.monto_comision_sol = (v.monto_comision_sol * %f) + (%f * COALESCE(v.tc_aplicado, %f) * v.cantidad)";
            }
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET $set_monto, v.bonus_multiplier = 1.0, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s$cap_sql
            ", ...$params));
        }
    } else {
        $mult   = (float) $out['multiplier'];
        $params = array_merge([$mult, $user_id], $cond_params, [$inicio], $cap_params, [$limite]);
        $wpdb->query($wpdb->prepare("
            UPDATE $ventas v
            SET v.bonus_multiplier = %f, v.bonus_aplicado = 1
            WHERE v.usuario_id = %d
              AND v.subcategoria_id IN $subcat_in
              AND v.creado_en >= %s$cap_sql
            ORDER BY v.id DESC
            LIMIT %d
        ", ...$params));

        if ($out['jefe_multiplier'] > 1.0) {
            $params = array_merge([$out['jefe_multiplier'], $user_id], $cond_params, [$inicio], $cap_params);
            $wpdb->query($wpdb->prepare("
                UPDATE $ventas v
                SET v.bonus_multiplier = %f, v.bonus_aplicado = 1
                WHERE v.asesor_id = %d
                  AND v.subcategoria_id IN $subcat_in
                  AND v.creado_en >= %s$cap_sql
            ", ...$params));
        }
    }
}

function billetera_register_jefe_comision($tienda_id, $subcategoria_id, $cantidad, $distribuidor_id, $id_type, $id_value, $registrante_id = 0, $fecha_datetime = null, $bonus_multiplier = 1.0, $override_sol = null, $add_sol = null, $linea_codigo = '') {
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

        // Validar que el jefe tenga el mismo tipo que el asesor.
        // Sin meta o vacio cuenta como "Venta" (mismo default que el ranking):
        // evita descartar comisiones en silencio cuando el tipo no esta llenado.
        $tipo_asesor_n = ($tipo_asesor === '' || $tipo_asesor === null) ? 'Venta' : trim((string) $tipo_asesor);
        $tipo_jefe = get_user_meta($jefe_id, 'billetera_tipo', true);
        $tipo_jefe_n = ($tipo_jefe === '' || $tipo_jefe === null) ? 'Venta' : trim((string) $tipo_jefe);
        if ($tipo_asesor_n !== $tipo_jefe_n) {
            continue;
        }

        if ($override_sol !== null) {
            // Reemplazar: monto fijo (ya incluye cantidad), no depende del catálogo
            $monto_jefe  = floatval($override_sol);
            $bm_jefe     = 1.0;
            $bm_aplicado = 1;
        } elseif ($add_sol !== null) {
            // Sumar: (base × multiplicador) + monto fijo
            $comision    = billetera_find_comision($subcategoria_id, $distribuidor_id, 'jefe_venta', $linea_codigo);
            $base        = $comision ? (billetera_convert_to_sol($comision) * $cantidad) : 0.0;
            $monto_jefe  = ($base * floatval($bonus_multiplier)) + floatval($add_sol);
            $bm_jefe     = 1.0;
            $bm_aplicado = 1;
        } else {
            $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, 'jefe_venta', $linea_codigo);
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
            'linea_codigo' => $linea_codigo,
            'cantidad' => $cantidad,
            'monto_comision_sol' => $monto_jefe,
            'id_type' => $id_type,
            'id_value' => $id_value,
            'bonus_multiplier' => $bm_jefe,
            'bonus_aplicado' => $bm_aplicado,
            'tc_aplicado' => billetera_get_tipo_cambio(),
            'creado_en' => $fecha_datetime,
        ], ['%d', '%d', '%d', '%s', '%d', '%f', '%s', '%s', '%f', '%d', '%f', '%s']);
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

    // La subcategoría debe existir y estar activa
    $sub_existe = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $subcategorias_table WHERE id = %d AND activo = 1",
        $subcategoria_id
    )));
    if (!$sub_existe) {
        wp_send_json_error(['message' => 'Producto no encontrado']);
    }

    $lineas_permitidas = billetera_get_lineas_permitidas($user_id);
    $codigos_permitidos = array_column($lineas_permitidas, 'codigo');

    // Línea de la venta (igual que al registrar)
    $linea_venta = billetera_resolver_linea_venta(
        $subcategoria_id,
        $codigos_permitidos,
        sanitize_text_field($_POST['linea_codigo'] ?? '')
    );
    if (!$linea_venta) {
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
    $comision = billetera_find_comision($subcategoria_id, $distribuidor_id, $user_role, $linea_venta);

    if (!$comision) {
        wp_send_json_error(['message' => 'Comisión no encontrada']);
    }

    // Convertir a SOL y multiplicar por cantidad
    $monto_total_sol = billetera_convert_to_sol($comision) * $cantidad;

    // Aplicar reglas de bonificación (sin retroactivo: solo previsualización)
    $regla_bonus = billetera_aplicar_regla_bonus($subcategoria_id, $user_id, $distribuidor_id, $cantidad, false, null, $linea_venta);
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
        SELECT v.id as venta_id, ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, v.bonus_aplicado, c.nombre as categoria, u.display_name as asesor_nombre
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
    $es_jefe = in_array('jefe_venta', (array) (get_userdata($user_id)->roles ?? []), true);

    wp_send_json_success([
        'balance' => $balance,
        'accumulated' => $accumulated,
        'acumulado_ano' => $acumulado_ano,
        'ventas_mes' => $ventas_mes,
        'ranking' => ['rank' => $rank, 'total' => $rank_total],
        'ranking_global' => ['rank' => $rank_global, 'total' => $rank_total_global],
        'racha' => $racha,
        'es_jefe' => $es_jefe,
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
        SELECT v.id as venta_id, ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) as amount, v.id_type, v.id_value, v.creado_en as created_at, s.nombre as subcategoria, v.bonus_multiplier, v.bonus_aplicado, c.nombre as categoria, u.display_name as asesor_nombre
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
// ELIMINAR VENTA PROPIA + RECÁLCULO DEL BONO DEL MES
// Solo ventas propias (asesor_id IS NULL) del mes en curso. Borra el espejo
// del jefe y repropaga tramos (mult/fijo; sumar usa delta con base del catálogo).
// ============================================================================

function billetera_base_para_fila($fila) {
    $sub_id = intval($fila->subcategoria_id ?? 0);
    $cant   = max(1, intval($fila->cantidad ?? 1));
    if ($sub_id <= 0) {
        return null;
    }
    $owner_id = intval($fila->usuario_id ?? 0);
    $es_espejo = !empty($fila->asesor_id);
    if ($es_espejo) {
        $rol = 'jefe_venta';
    } else {
        $u = $owner_id ? get_userdata($owner_id) : null;
        $roles = $u ? (array) $u->roles : ['asesor'];
        $rol = in_array('jefe_venta', $roles, true) ? 'jefe_venta' : 'asesor';
    }
    $tienda_id = $owner_id ? get_user_meta($owner_id, '_tienda_asociada', true) : 0;
    $dealer_id = $tienda_id ? get_post_meta($tienda_id, '_dealer_id', true) : null;
    $com = billetera_find_comision($sub_id, $dealer_id, $rol);
    if (!$com) {
        return null;
    }
    $tc_fila = isset($fila->tc_aplicado) && floatval($fila->tc_aplicado) > 0 ? floatval($fila->tc_aplicado) : billetera_get_tipo_cambio();
    $factor = (($com->moneda ?? 'PEN') === 'USD') ? $tc_fila : 1.0;
    return round(floatval($com->monto) * $factor * $cant, 2);
}

/**
 * Repropaga el bono del mes para las ventas propias de un usuario.
 * 1) Resetea (mult>1 → mult=1; bonus con mult=1 → recomputa base).
 * 2) Reproduce en orden cronológico con el mismo motor de tramos + retro.
 * Solo toca filas propias (asesor_id IS NULL); los espejos del jefe no se retocan aquí.
 */
function billetera_bonus_recalcular_usuario_mes($user_id, $fecha_ref_ymd) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return;
    }
    $mes_inicio = substr((string) $fecha_ref_ymd, 0, 7) . '-01 00:00:00';
    $mes_fin_dt = new DateTime($mes_inicio);
    $mes_fin_dt->modify('first day of next month');
    $mes_fin = $mes_fin_dt->format('Y-m-d H:i:s');

    $filas = $wpdb->get_results($wpdb->prepare("
        SELECT v.* FROM $ventas v
        WHERE v.usuario_id = %d AND v.asesor_id IS NULL
          AND v.creado_en >= %s AND v.creado_en < %s
        ORDER BY v.creado_en ASC, v.id ASC
    ", $user_id, $mes_inicio, $mes_fin));
    if (empty($filas)) {
        return;
    }

    // 1) Reset a base.
    foreach ($filas as $f) {
        if ((float) ($f->bonus_multiplier ?? 1) > 1.0) {
            $wpdb->update($ventas, ['bonus_multiplier' => 1.0, 'bonus_aplicado' => 0], ['id' => intval($f->id)], ['%f', '%d'], ['%d']);
        } elseif (intval($f->bonus_aplicado ?? 0) === 1) {
            $base = billetera_base_para_fila($f);
            if ($base !== null) {
                $wpdb->update($ventas, ['monto_comision_sol' => $base, 'bonus_multiplier' => 1.0, 'bonus_aplicado' => 0], ['id' => intval($f->id)], ['%f', '%f', '%d'], ['%d']);
            } else {
                $wpdb->update($ventas, ['bonus_multiplier' => 1.0, 'bonus_aplicado' => 0], ['id' => intval($f->id)], ['%f', '%d'], ['%d']);
            }
        }
    }

    // 2) Replay con el motor de tramos (conteo DB con tope en la fila actual
    // para no contar ventas posteriores, incluidos espejos del jefe).
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    $dealer_id = $tienda_id ? get_post_meta($tienda_id, '_dealer_id', true) : 0;
    $tc = billetera_get_tipo_cambio();
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';

    foreach ($filas as $f) {
        $fid = intval($f->id);
        $fecha_ymd = substr((string) $f->creado_en, 0, 10);
        $ganadora = billetera_bonus_find_best_regla(intval($f->subcategoria_id), $dealer_id, $fecha_ymd, (string) ($f->linea_codigo ?? ''));
        if (!$ganadora) {
            continue;
        }
        $grupo = billetera_bonus_escalera_grupo($ganadora, $fecha_ymd);
        if (empty($grupo)) {
            $grupo = [billetera_bonus_normalize_regla($ganadora)];
        }
        list($cond, $cond_params) = billetera_bonus_cond_v2($ganadora);
        $inicio = billetera_bonus_grupo_inicio($grupo, $mes_inicio);
        if ((string) $f->creado_en < $inicio) {
            continue;
        }
        $params = array_merge([$user_id, (string) $f->creado_en, $fid], $cond_params, [$inicio]);
        $count = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(v.id) FROM $ventas v
            JOIN $sub s ON v.subcategoria_id = s.id
            JOIN $cat c ON s.categoria_id = c.id
            WHERE v.usuario_id = %d
              AND (v.creado_en < %s OR (v.creado_en = %s AND v.id < %d))
              AND $cond AND v.creado_en >= %s
        ", ...$params));

        $tramo = null;
        $prev  = null;
        foreach ($grupo as $t) {
            $u = max(1, intval($t['umbral'] ?? 1));
            if ($count >= $u - 1) {
                $tramo = $t;
            }
            if ($count - 1 >= $u - 1 && $count > 0) {
                $prev = $t;
            }
        }
        if (!$tramo) {
            continue;
        }
        $tipo = (string) ($tramo['efecto_tipo'] ?? 'multiplicar');
        if (!in_array($tipo, ['multiplicar', 'fijo', 'sumar'], true)) {
            $tipo = 'multiplicar';
        }
        $cant = max(1, intval($f->cantidad ?? 1));
        $tc_fila = (isset($f->tc_aplicado) && floatval($f->tc_aplicado) > 0) ? floatval($f->tc_aplicado) : $tc;
        $out = ['multiplier' => 1.0, 'override_sol' => null, 'add_sol' => null, 'jefe_multiplier' => 1.0, 'jefe_override_sol' => null, 'jefe_add_sol' => null];
        $con_jefe = !empty($tramo['aplica_jefe']);
        if ($tipo === 'fijo') {
            $factor = (($tramo['efecto_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc_fila;
            $out['override_sol'] = round((float) ($tramo['efecto_valor'] ?? 0) * $factor * $cant, 2);
            $fj = round((float) ($tramo['efecto_jefe_valor'] ?? 0) * $factor * $cant, 2);
            $out['jefe_override_sol'] = ($con_jefe && (float) ($tramo['efecto_jefe_valor'] ?? 0) > 0) ? $fj : null;
        } elseif ($tipo === 'sumar') {
            $factor = (($tramo['efecto_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tc_fila;
            $out['add_sol'] = round((float) ($tramo['efecto_valor'] ?? 0) * $factor * $cant, 2);
            $fj = round((float) ($tramo['efecto_jefe_valor'] ?? 0) * $factor * $cant, 2);
            $out['jefe_add_sol'] = ($con_jefe && (float) ($tramo['efecto_jefe_valor'] ?? 0) > 0) ? $fj : null;
        } else {
            $mult = (float) ($tramo['efecto_valor'] ?? ($tramo['multiplicador'] ?? 1));
            $out['multiplier'] = $mult;
            $out['jefe_multiplier'] = $con_jefe ? $mult : 1.0;
        }

        if ($tipo === 'fijo' && $out['override_sol'] !== null) {
            $wpdb->update($ventas, ['monto_comision_sol' => $out['override_sol'], 'bonus_multiplier' => 1.0, 'bonus_aplicado' => 1], ['id' => $fid], ['%f', '%f', '%d'], ['%d']);
        } elseif ($tipo === 'sumar' && $out['add_sol'] !== null) {
            $wpdb->query($wpdb->prepare("UPDATE $ventas SET monto_comision_sol = monto_comision_sol + %f, bonus_multiplier = 1.0, bonus_aplicado = 1 WHERE id = %d", (float) $out['add_sol'], $fid));
        } elseif ($tipo === 'multiplicar') {
            $wpdb->update($ventas, ['bonus_multiplier' => (float) $out['multiplier'], 'bonus_aplicado' => 1], ['id' => $fid], ['%f', '%d'], ['%d']);
        }

        $es_nuevo_tramo = ($count === max(1, (int) ($tramo['umbral'] ?? 1)) - 1) && (!$prev || (string) ($prev['id'] ?? '') !== (string) ($tramo['id'] ?? ''));
        if ($es_nuevo_tramo) {
            billetera_bonus_retroactivo($tramo, $user_id, $mes_inicio, $out, $tc, $prev, $inicio, $fid);
        }
    }
}

add_action('wp_ajax_billetera_delete_sale', 'billetera_ajax_delete_sale');

function billetera_ajax_delete_sale() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_delete_sale')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }
    $user_id = get_current_user_id();
    $u = wp_get_current_user();
    if (!array_intersect(['asesor', 'administrator', 'jefe_venta'], (array) $u->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }
    $venta_id = intval($_POST['venta_id'] ?? 0);
    if ($venta_id <= 0) {
        wp_send_json_error(['message' => 'Venta inválida']);
    }
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $v = $wpdb->get_row($wpdb->prepare("SELECT * FROM $ventas WHERE id = %d", $venta_id));
    if (!$v) {
        wp_send_json_error(['message' => 'Venta no encontrada']);
    }
    if (intval($v->usuario_id) !== $user_id && !in_array('administrator', (array) $u->roles, true)) {
        wp_send_json_error(['message' => 'Solo puedes eliminar tus propias ventas']);
    }
    if (!empty($v->asesor_id)) {
        wp_send_json_error(['message' => 'Esta comisión es espejo del jefe y se elimina con la venta del asesor']);
    }
    $now = billetera_stats_local_now();
    $mes_inicio = $now->format('Y-m-01 00:00:00');
    if ((string) $v->creado_en < $mes_inicio) {
        wp_send_json_error(['message' => 'Solo puedes eliminar ventas del mes en curso. Para meses cerrados contacta a backoffice']);
    }

    $owner_id = intval($v->usuario_id);
    $mes_ref = substr((string) $v->creado_en, 0, 10);
    $mes_ini_v = substr((string) $v->creado_en, 0, 7) . '-01 00:00:00';
    $mes_fin_v = (new DateTime($mes_ini_v))->modify('first day of next month')->format('Y-m-d H:i:s');
    $tienda_id = get_user_meta($owner_id, '_tienda_asociada', true);

    $wpdb->query('START TRANSACTION');

    $espejos = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT usuario_id FROM $ventas
        WHERE asesor_id = %d AND subcategoria_id = %d AND creado_en = %s
          AND id_type = %s AND id_value = %s
    ", $owner_id, intval($v->subcategoria_id), (string) $v->creado_en, (string) ($v->id_type ?? ''), (string) ($v->id_value ?? '')));
    $jefes = array_values(array_filter(array_map('intval', (array) $espejos)));

    $wpdb->delete($ventas, [
        'asesor_id' => $owner_id,
        'subcategoria_id' => intval($v->subcategoria_id),
        'creado_en' => (string) $v->creado_en,
        'id_type' => (string) ($v->id_type ?? ''),
        'id_value' => (string) ($v->id_value ?? ''),
    ], ['%d', '%d', '%s', '%s', '%s']);
    $del = $wpdb->delete($ventas, ['id' => $venta_id], ['%d']);
    if (!$del) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error(['message' => 'No se pudo eliminar']);
    }

    billetera_bonus_recalcular_usuario_mes($owner_id, $mes_ref);

    if ($tienda_id) {
        $restantes = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $ventas
            WHERE usuario_id = %d AND asesor_id IS NULL AND creado_en >= %s AND creado_en < %s
            ORDER BY creado_en ASC, id ASC
        ", $owner_id, $mes_ini_v, $mes_fin_v));
        $wpdb->query($wpdb->prepare("DELETE FROM $ventas WHERE asesor_id = %d AND creado_en >= %s AND creado_en < %s", $owner_id, $mes_ini_v, $mes_fin_v));
        $dealer_id = get_post_meta($tienda_id, '_dealer_id', true);
        foreach ($restantes as $r) {
            $fymd = substr((string) $r->creado_en, 0, 10);
            $gan = billetera_bonus_find_best_regla(intval($r->subcategoria_id), $dealer_id, $fymd, (string) ($r->linea_codigo ?? ''));
            if (!$gan) {
                billetera_register_jefe_comision($tienda_id, intval($r->subcategoria_id), max(1, intval($r->cantidad ?? 1)), $dealer_id, (string) ($r->id_type ?? ''), (string) ($r->id_value ?? ''), $owner_id, (string) $r->creado_en, 1.0, null, null, (string) ($r->linea_codigo ?? ''));
                continue;
            }
            $grupo = billetera_bonus_escalera_grupo($gan, $fymd);
            if (empty($grupo)) {
                $grupo = [billetera_bonus_normalize_regla($gan)];
            }
            $inicio = billetera_bonus_grupo_inicio($grupo, $mes_ini_v);
            list($cond, $cp) = billetera_bonus_cond_v2($gan);
            $sub = $wpdb->prefix . 'billetera_subcategorias';
            $cat = $wpdb->prefix . 'billetera_categorias';
            $cnt_params = array_merge([$owner_id, (string) $r->creado_en, (string) $r->creado_en, intval($r->id)], $cp, [$inicio]);
            $cnt = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(v2.id) FROM $ventas v2
                JOIN $sub s ON v2.subcategoria_id = s.id
                JOIN $cat c ON s.categoria_id = c.id
                WHERE v2.usuario_id = %d AND (v2.creado_en < %s OR (v2.creado_en = %s AND v2.id < %d))
                  AND $cond AND v2.creado_en >= %s
            ", ...$cnt_params));
            $tr = null;
            foreach ($grupo as $t) {
                if ($cnt >= max(1, intval($t['umbral'] ?? 1)) - 1) {
                    $tr = $t;
                }
            }
            $jm = 1.0;
            $jo = null;
            $ja = null;
            if ($tr) {
                $tt = (string) ($tr['efecto_tipo'] ?? 'multiplicar');
                $cj = !empty($tr['aplica_jefe']);
                $tcf = (isset($r->tc_aplicado) && floatval($r->tc_aplicado) > 0) ? floatval($r->tc_aplicado) : billetera_get_tipo_cambio();
                $fac = (($tr['efecto_moneda'] ?? 'USD') === 'PEN') ? 1.0 : $tcf;
                $cant = max(1, intval($r->cantidad ?? 1));
                if ($tt === 'fijo') {
                    $fj = round((float) ($tr['efecto_jefe_valor'] ?? 0) * $fac * $cant, 2);
                    $jo = ($cj && (float) ($tr['efecto_jefe_valor'] ?? 0) > 0) ? $fj : null;
                } elseif ($tt === 'sumar') {
                    $fj = round((float) ($tr['efecto_jefe_valor'] ?? 0) * $fac * $cant, 2);
                    $ja = ($cj && (float) ($tr['efecto_jefe_valor'] ?? 0) > 0) ? $fj : null;
                } else {
                    $m = (float) ($tr['efecto_valor'] ?? ($tr['multiplicador'] ?? 1));
                    $jm = $cj ? $m : 1.0;
                }
            }
            billetera_register_jefe_comision($tienda_id, intval($r->subcategoria_id), max(1, intval($r->cantidad ?? 1)), $dealer_id, (string) ($r->id_type ?? ''), (string) ($r->id_value ?? ''), $owner_id, (string) $r->creado_en, $jm, $jo, $ja);
        }
        foreach ($jefes as $jid) {
            billetera_bonus_recalcular_usuario_mes($jid, $mes_ref);
        }
    }

    $wpdb->query('COMMIT');
    billetera_rank_cache_bust();

    wp_send_json_success(['message' => 'Venta eliminada y bono recalculado']);
}

// ============================================================================
// BANNERS DE CAMPAÑA (enlazados a reglas de bonificación, editables en admin)
// Se muestran en "Mi desempeño". Sin montos sensibles: solo textos del admin
// + conteo de ventas del mes en el ámbito de la regla.
// ============================================================================

/**
 * Ventas del mes actual del usuario dentro del ámbito de una regla.
 * Usa el inicio unificado de su escalera para cuadrar con el bono.
 */
function billetera_campana_ventas_mes($user_id, $regla) {
    global $wpdb;
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';

    $now   = billetera_stats_local_now();
    $desde = $now->format('Y-m-01 00:00:00');
    $hasta = (clone $now)->modify('first day of next month')->format('Y-m-01 00:00:00');
    // El conteo cuadra con el bono: inicio unificado de la escalera.
    $grupo_r = billetera_bonus_escalera_grupo($regla, $now->format('Y-m-d'));
    if (!empty($grupo_r)) {
        $desde = billetera_bonus_grupo_inicio($grupo_r, $desde);
    } else {
        $desde = billetera_bonus_inicio_conteo($regla, $desde);
    }
    if (!empty($regla['banner_hasta'])) {
        $h = (new DateTime((string) $regla['banner_hasta'] . ' 00:00:00'))->modify('+1 day')->format('Y-m-d 00:00:00');
        if ($h < $hasta) {
            $hasta = $h;
        }
    }

    list($cond_sql, $cond_params) = billetera_bonus_cond_v2($regla);
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
 * dentro de vigencia. v2: respeta scope_dealer_id (solo se muestra si la
 * tienda actual del usuario coincide).
 */
function billetera_campanas_para_usuario($user_id) {
    $user_id   = intval($user_id);
    $hoy       = billetera_stats_local_now()->format('Y-m-d');

    // Dealer actual del usuario (para filtrar reglas con alcance de tienda).
    $user_dealer = 0;
    $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
    if ($tienda_id) {
        $user_dealer = intval(get_post_meta($tienda_id, '_dealer_id', true));
    }

    $out = [];
    foreach (billetera_bonus_reglas() as $r) {
        if (empty($r['activo']) || empty($r['banner_activo'])) {
            continue;
        }
        if (trim((string) ($r['banner_titulo'] ?? '')) === '') {
            continue;
        }
        $r = billetera_bonus_normalize_regla($r);
        $scope_dealer = intval($r['scope_dealer_id'] ?? 0);
        if ($scope_dealer > 0 && $user_dealer !== $scope_dealer) {
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
        // Clave sin dealer: la regla de tienda tapa a la general para ese usuario.
        $key_sin_dealer = implode('|', [
            strtolower(trim((string) ($r['scope_slug'] ?? ''))),
            intval($r['scope_marca_id'] ?? 0),
            strtolower(trim((string) ($r['scope_linea_codigo'] ?? ''))),
            intval($r['scope_subcategoria_id'] ?? 0),
        ]);
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
            '_key_sin_dealer' => $key_sin_dealer,
            '_dealer'  => $scope_dealer,
        ];
    }

    // Si el usuario tiene regla de tienda y general del mismo alcance, solo
    // ve la de su tienda (la que realmente le aplica el bono). El resto ve la general.
    $con_especifica = [];
    foreach ($out as $b) {
        if (intval($b['_dealer'] ?? 0) > 0) {
            $con_especifica[$b['_key_sin_dealer']] = true;
        }
    }
    if (!empty($con_especifica)) {
        $out = array_values(array_filter($out, function ($b) use ($con_especifica) {
            return !(intval($b['_dealer'] ?? 0) === 0 && isset($con_especifica[$b['_key_sin_dealer']]));
        }));
    }
    foreach ($out as &$b) {
        unset($b['_key_sin_dealer'], $b['_dealer']);
    }
    unset($b);

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
 * Desglose del equipo por categoría o subcategoría para el periodo (y base).
 * Cuenta ventas propias de los asesores (usuarios del equipo).
 */
function billetera_stats_desglose_equipo($ids, $periodo, $tipo, $base) {
    global $wpdb;
    $v   = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';
    $lin = $wpdb->prefix . 'billetera_lineas';

    list($desde, $hasta) = billetera_stats_rango($periodo, $base);
    $h = $ids ? ('v.usuario_id IN (' . implode(',', array_map('intval', $ids)) . ')') : '1=0';
    $p = [];
    if ($desde !== null) {
        $h .= ' AND v.creado_en >= %s';
        $p[] = $desde;
    }
    if ($hasta !== null) {
        $h .= ' AND v.creado_en < %s';
        $p[] = $hasta;
    }

    // La línea (marca) acompaña a cada venta: la guardada en la venta, o la
    // primera línea de la subcategoría para históricos sin dato.
    $linea_expr = "COALESCE(v.linea_codigo, (SELECT b.linea_codigo FROM {$wpdb->prefix}billetera_subcategoria_lineas b WHERE b.subcategoria_id = s.id ORDER BY b.linea_codigo ASC LIMIT 1))";
    $select = "l.nombre AS linea";
    $join   = "LEFT JOIN $lin l ON l.codigo = $linea_expr";
    if ($tipo === 'subcategoria') {
        $select .= ', s.nombre AS label';
        $group  = 's.id';
    } else {
        $select .= ', c.nombre AS label';
        $group  = 'c.id';
    }

    $sql = "SELECT $select,
                   COUNT(*) AS ventas,
                   SUM(v.monto_comision_sol * v.bonus_multiplier) AS total
            FROM $v v
            JOIN $sub s ON s.id = v.subcategoria_id
            JOIN $cat c ON c.id = s.categoria_id
            $join
            WHERE $h
            GROUP BY $group, l.codigo
            ORDER BY ventas DESC, total DESC
            LIMIT 30";

    if (!$ids) {
        return [];
    }
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$p));

    $out = [];
    foreach ((array) $rows as $r) {
        $out[] = [
            'label'  => html_entity_decode((string) $r->label, ENT_QUOTES, 'UTF-8'),
            'linea'  => html_entity_decode((string) ($r->linea ?? ''), ENT_QUOTES, 'UTF-8'),
            'ventas' => intval($r->ventas),
            'total'  => round(floatval($r->total), 2),
        ];
    }
    return $out;
}

// ============================================================================
// MI EQUIPO (jefe de venta): agregados del propio equipo de la tienda
// ============================================================================

// ===========================================================================
// VISTA DE GERENTE ("Mi concesionaria")
// El gerente ve las sucursales de las concesionarias donde está asignado
// (_gerentes_asociados del dealer). Solo lectura: ventas por sucursal,
// avance de meta en unidades y comparativa. SIN montos de comisiones.
// ===========================================================================

/**
 * Concesionarias donde el usuario figura como gerente.
 */
function billetera_gerente_dealers($user_id) {
    $out = [];
    $dealers = get_posts([
        'post_type'   => 'dealer',
        'numberposts' => -1,
        'post_status' => 'any',
        'fields'      => 'ids',
    ]);
    foreach ($dealers as $did) {
        $gerentes = get_post_meta(intval($did), '_gerentes_asociados', true);
        if (is_array($gerentes) && in_array(intval($user_id), array_map('intval', $gerentes), true)) {
            $out[] = intval($did);
        }
    }
    return $out;
}

/**
 * Sucursales (tiendas) de las concesionarias del gerente: id => título.
 */
function billetera_gerente_tiendas($user_id) {
    $dealer_ids = billetera_gerente_dealers($user_id);
    if (!$dealer_ids) {
        return [];
    }
    $tiendas = get_posts([
        'post_type'   => 'tienda',
        'numberposts' => -1,
        'post_status' => 'publish',
        'meta_query'  => [[
            'key'     => '_dealer_id',
            'value'   => $dealer_ids,
            'compare' => 'IN',
            'type'    => 'NUMERIC',
        ]],
        'orderby' => 'title',
        'order'   => 'ASC',
    ]);
    $map = [];
    foreach ($tiendas as $t) {
        $map[intval($t->ID)] = $t->post_title;
    }
    return $map;
}

add_action('wp_ajax_billetera_get_gerencia', 'billetera_ajax_get_gerencia');

function billetera_ajax_get_gerencia() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_gerencia')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();

    if (!array_intersect(['gerente', 'administrator'], (array) $current_user->roles)) {
        wp_send_json_error(['message' => 'Solo los gerentes pueden ver esta vista']);
    }

    $tienda_map = billetera_gerente_tiendas($user_id);
    if (!$tienda_map) {
        wp_send_json_error(['message' => 'No eres gerente de ninguna concesionaria. Te deben asignar en la concesionaria.']);
    }
    $tienda_ids = array_keys($tienda_map);

    // Filtro del selector de sucursal (0 = todas).
    $tienda_sel = isset($_POST['tienda']) ? intval($_POST['tienda']) : 0;
    if ($tienda_sel) {
        if (!in_array($tienda_sel, $tienda_ids, true)) {
            wp_send_json_error(['message' => 'No administras esa sucursal']);
        }
        $tienda_ids = [$tienda_sel];
    }

    // Periodo: mes + selector de mes anterior (misma convención que Mi equipo).
    $now = billetera_stats_local_now();
    $base = clone $now;
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
    $meses_es = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $periodo_label = $meses_es[intval($base->format('n'))] . ' ' . $base->format('Y');
    $es_actual = ($base->format('Y-m') === $now->format('Y-m'));

    list($desde, $hasta) = billetera_stats_rango('mes', $base);

    // Usuarios (asesores + jefes) de las sucursales: sus ventas propias
    // (asesor_id IS NULL; los espejos del jefe no se cuentan aquí).
    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';

    $users = get_users([
        'meta_query' => [[
            'key'     => '_tienda_asociada',
            'value'   => $tienda_ids,
            'compare' => 'IN',
            'type'    => 'NUMERIC',
        ]],
        'role__in' => ['asesor', 'jefe_venta'],
        'number'   => -1,
        'fields'   => 'all',
    ]);
    $user_tienda = [];
    $ids = [];
    foreach ($users as $u) {
        $tid = intval(get_user_meta(intval($u->ID), '_tienda_asociada', true));
        if ($tid && in_array($tid, $tienda_ids, true)) {
            $user_tienda[intval($u->ID)] = $tid;
            $ids[] = intval($u->ID);
        }
    }

    // Ventas del periodo por usuario (solo propias) + última venta histórica.
    $ventas_por_usuario = [];
    $ultima_por_usuario = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT v.usuario_id, COUNT(*) AS ventas
            FROM $v v
            WHERE v.usuario_id IN ($in)
              AND v.asesor_id IS NULL
              AND v.creado_en >= %s AND v.creado_en < %s
            GROUP BY v.usuario_id
        ", $desde, $hasta));
        foreach ((array) $rows as $r) {
            $ventas_por_usuario[intval($r->usuario_id)] = intval($r->ventas);
        }

        $rows_ut = $wpdb->get_results("
            SELECT v.usuario_id, MAX(v.creado_en) AS ultima
            FROM $v v
            WHERE v.usuario_id IN ($in) AND v.asesor_id IS NULL
            GROUP BY v.usuario_id
        ");
        foreach ((array) $rows_ut as $r) {
            $ultima_por_usuario[intval($r->usuario_id)] = $r->ultima ? (string) $r->ultima : null;
        }
    }

    // Meta de cada sucursal en unidades (todas las líneas, categorías y tipos).
    $meta_table = $wpdb->prefix . 'billetera_metas_sucursal';
    $in_t = implode(',', array_map('intval', $tienda_ids));
    $meta_map = [];
    $rows_meta = $wpdb->get_results("SELECT tienda_id, SUM(unidades) AS total FROM $meta_table WHERE tienda_id IN ($in_t) GROUP BY tienda_id");
    foreach ((array) $rows_meta as $r) {
        $meta_map[intval($r->tienda_id)] = intval($r->total);
    }

    // Consolidado por sucursal.
    $sucursales = [];
    $tot_ventas = 0;
    $tot_meta = 0;
    $ultima_global = null;
    foreach ($tienda_ids as $tid) {
        $ventas_t = 0;
        $ultima_t = null;
        foreach ($user_tienda as $uid => $tid_u) {
            if ($tid_u !== $tid) {
                continue;
            }
            $ventas_t += intval($ventas_por_usuario[$uid] ?? 0);
            $u_ultima = $ultima_por_usuario[$uid] ?? null;
            if ($u_ultima && ($ultima_t === null || $u_ultima > $ultima_t)) {
                $ultima_t = $u_ultima;
            }
        }
        $meta_t = intval($meta_map[$tid] ?? 0);
        $sucursales[] = [
            'id'      => $tid,
            'nombre'  => (string) ($tienda_map[$tid] ?? ('#' . $tid)),
            'ventas'  => $ventas_t,
            'meta'    => $meta_t,
            'avance'  => $meta_t > 0 ? min(round(($ventas_t / $meta_t) * 100, 1), 100.0) : null,
            'faltan'  => $meta_t > 0 ? max(0, $meta_t - $ventas_t) : null,
            'ultima'  => $ultima_t,
        ];
        $tot_ventas += $ventas_t;
        $tot_meta += $meta_t;
        if ($ultima_t && ($ultima_global === null || $ultima_t > $ultima_global)) {
            $ultima_global = $ultima_t;
        }
    }

    // Comparativa ordenada por ventas desc.
    usort($sucursales, function ($a, $b) {
        if ($a['ventas'] !== $b['ventas']) {
            return $b['ventas'] <=> $a['ventas'];
        }
        return strcmp($a['nombre'], $b['nombre']);
    });

    // Proyección (solo mes actual): ventas / días laborables transcurridos.
    list($lab_trans, $lab_total) = billetera_stats_dias_laborables_mes(
        intval($base->format('Y')), intval($base->format('n')), $es_actual ? intval($now->format('j')) : 31
    );
    $lab_trans = max($lab_trans, 1);
    $proy = $es_actual ? (int) round($tot_ventas / $lab_trans * $lab_total) : $tot_ventas;

    wp_send_json_success([
        'periodo'      => $base->format('Y-m'),
        'periodo_label'=> $periodo_label,
        'es_actual'    => $es_actual,
        'sucursales'   => $sucursales,
        'total'        => [
            'ventas' => $tot_ventas,
            'meta'   => $tot_meta,
            'avance' => $tot_meta > 0 ? min(round(($tot_ventas / $tot_meta) * 100, 1), 100.0) : null,
            'faltan' => $tot_meta > 0 ? max(0, $tot_meta - $tot_ventas) : null,
            'proyeccion' => $proy,
            'ultima' => $ultima_global,
        ],
    ]);
}

add_action('wp_ajax_billetera_get_mi_equipo', 'billetera_ajax_get_mi_equipo');

function billetera_ajax_get_mi_equipo() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_get_mi_equipo')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
    }

    $current_user = wp_get_current_user();
    $user_id = get_current_user_id();

    if (!array_intersect(['jefe_venta', 'administrator'], (array) $current_user->roles)) {
        wp_send_json_error(['message' => 'Solo los jefes de venta pueden ver Mi equipo']);
    }

    // --- Sucursales donde este usuario es jefe de venta (perfil: _tiendas_del_jefe) ---
    // Sin fallback a _tienda_asociada: si no tiene sucursales asignadas en su
    // perfil, no administra ninguna.
    $tienda_map = billetera_jefe_tiendas($user_id);
    $tienda_ids = array_keys($tienda_map);
    if (!$tienda_ids) {
        wp_send_json_error(['message' => 'No eres jefe de ninguna sucursal. Asignalas en tu perfil.']);
    }

    // Filtro del selector de sucursal del header (0 = todas).
    $tienda_sel = isset($_POST['tienda']) ? intval($_POST['tienda']) : 0;
    if ($tienda_sel && in_array($tienda_sel, $tienda_ids, true)) {
        $tienda_ids = [$tienda_sel];
    } elseif ($tienda_sel) {
        wp_send_json_error(['message' => 'No eres jefe de esa sucursal']);
    }
    $tienda_ids = array_values(array_unique($tienda_ids));

    // Equipo: asesores de las sucursales del jefe (no espejos del jefe).
    // Solo del MISMO TIPO que el jefe (Venta/Post Venta; sin meta = Venta,
    // mismo criterio que el ranking y que las comisiones espejo).
    $tipo_jefe = trim((string) get_user_meta($user_id, 'billetera_tipo', true));
    if ($tipo_jefe === '') {
        $tipo_jefe = 'Venta';
    }
    $asesores = get_users([
        'meta_query' => [[
            'key'     => '_tienda_asociada',
            'value'   => $tienda_ids,
            'compare' => 'IN',
        ]],
        'role__in'   => ['asesor'],
        'number'     => -1,
        'orderby'    => 'display_name',
        'order'      => 'ASC',
    ]);
    $ids = [];
    foreach ($asesores as $a) {
        $t = trim((string) get_user_meta(intval($a->ID), 'billetera_tipo', true));
        if ($t === '') {
            $t = 'Venta';
        }
        if ($t === $tipo_jefe) {
            $ids[] = intval($a->ID);
        }
    }
    $nombres = [];
    foreach ($asesores as $a) {
        $nombres[intval($a->ID)] = $a->display_name;
    }

    // Periodo + base (misma convención que Mi desempeño).
    $now = billetera_stats_local_now();
    $periodo = isset($_POST['periodo']) ? sanitize_text_field($_POST['periodo']) : 'mes';
    if (!in_array($periodo, ['mes', 'ano', 'historico'], true)) {
        $periodo = 'mes';
    }
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
    $es_actual = ($base->format('Y-m') === $now->format('Y-m'));

    list($desde, $hasta) = billetera_stats_rango($periodo, $base);

    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';

    $in    = $ids ? implode(',', array_fill(0, count($ids), '%d')) : '0';
    $h_ids = $ids ? 'v.usuario_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '1=0';
    $h_rng = '';
    $p_rng = [];
    if ($desde !== null) {
        $h_rng  .= ' AND v.creado_en >= %s';
        $p_rng[] = $desde;
    }
    if ($hasta !== null) {
        $h_rng  .= ' AND v.creado_en < %s';
        $p_rng[] = $hasta;
    }

    // ---- KPIs del equipo (ventas propias de los asesores, no espejos) ----
    $com = 0.0;
    $ventas = 0;
    $per_asesor = [];
    $ultima_total = [];
    if ($ids) {
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS ventas, COALESCE(SUM(v.monto_comision_sol * v.bonus_multiplier), 0) AS com
             FROM $v v
             WHERE $h_ids $h_rng",
            ...$p_rng
        ));
        $ventas = intval($row->ventas ?? 0);
        $com    = floatval($row->com ?? 0);

        $rows_pa = $wpdb->get_results($wpdb->prepare(
            "SELECT v.usuario_id, COUNT(*) AS ventas, COALESCE(SUM(v.monto_comision_sol * v.bonus_multiplier), 0) AS com, MAX(v.creado_en) AS ultima
             FROM $v v
             WHERE $h_ids $h_rng
             GROUP BY v.usuario_id",
            ...$p_rng
        ));
        foreach ((array) $rows_pa as $r) {
            $per_asesor[intval($r->usuario_id)] = [
                'ventas' => intval($r->ventas),
                'com'    => round(floatval($r->com), 2),
                'ultima' => $r->ultima ? (string) $r->ultima : null,
            ];
        }

        // Ultima venta HISTORICA de cada asesor (sin filtro de periodo): si el
        // asesor no vendio en el periodo seleccionado, igual se muestra cuando
        // fue su ultima venta real (columna "Ult. venta" del top del equipo).
        $rows_ut = $wpdb->get_results(
            "SELECT v.usuario_id, MAX(v.creado_en) AS ultima
             FROM $v v
             WHERE v.usuario_id IN (" . implode(',', array_map('intval', $ids)) . ")
             GROUP BY v.usuario_id"
        );
        foreach ((array) $rows_ut as $r) {
            $ultima_total[intval($r->usuario_id)] = $r->ultima ? (string) $r->ultima : null;
        }
    }

    // Meta del equipo: suma de las UNIDADES de las metas por sucursal de las
    // tiendas del equipo (mismo criterio que el ranking de jefes, por el tipo
    // del jefe). Si ninguna sucursal tiene metas por categoria, se conserva la
    // regla anterior: suma de las metas de los asesores (en soles) convertida
    // a nº de ventas via ticket promedio.
    $meta_sol = 0.0;
    foreach ($ids as $aid) {
        $meta_sol += floatval(billetera_get_meta_por_usuario($aid));
    }

    $meta_unid = 0;
    foreach ($tienda_ids as $tid_m) {
        $m_suc = billetera_get_meta_sucursal($tid_m, $tipo_jefe);
        $meta_unid += intval($m_suc['unidades']);
    }

    $ticket = $ventas > 0 ? round($com / $ventas, 2) : 0.0;

    if ($meta_unid > 0) {
        // Meta en unidades (sin conversion por ticket).
        $meta_vtas   = $meta_unid;
        $avance      = min(round(($ventas / $meta_unid) * 100, 1), 100.0);
        $faltan_sol  = 0.0;
        $faltan_vtas = max(0, $meta_unid - $ventas);
    } else {
        $meta_vtas = ($meta_sol > 0 && $ticket > 0) ? max(1, (int) round($meta_sol / $ticket)) : 0;
        $avance    = ($meta_sol > 0) ? min(round(($com / $meta_sol) * 100, 1), 100.0) : 0.0;
        $faltan_sol  = max(round($meta_sol - $com, 2), 0.0);
        $faltan_vtas = ($ticket > 0 && $meta_vtas > 0) ? max(0, $meta_vtas - $ventas) : 0;
    }

    // Días laborables y proyección sobre ventas del equipo.
    $dia_actual  = $es_actual ? intval($now->format('j')) : 31;
    list($lab_trans, $lab_total) = billetera_stats_dias_laborables_mes(
        intval($base->format('Y')), intval($base->format('n')), $dia_actual
    );
    $lab_trans = max($lab_trans, 1);
    $proy_vtas = $es_actual ? (int) round($ventas / $lab_trans * $lab_total) : $ventas;

    $rest_dias = max(0, $lab_total - $lab_trans);
    $ideal_vtas = $es_actual ? (int) round($meta_vtas * $lab_trans / max($lab_total, 1)) : $meta_vtas;
    $behind     = max(0, $ideal_vtas - $ventas);

    // ---- Pocito del propio jefe (sus comisiones espejo del mes) ----
    $com_jefe = 0.0;
    if ($periodo === 'historico') {
        $com_jefe = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_comision_sol * bonus_multiplier), 0) FROM $v WHERE usuario_id = %d",
            $user_id
        )) ?? 0);
    } else {
        $com_jefe = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(monto_comision_sol * bonus_multiplier), 0)
             FROM $v
             WHERE usuario_id = %d AND creado_en >= %s AND creado_en < %s",
            $user_id, $desde, $hasta
        )) ?? 0);
    }

    // ---- Tendencia del equipo (ventas por mes, últimos N meses) ----
    $meses_n = isset($_POST['meses']) ? max(2, min(12, intval($_POST['meses']))) : 12;
    $offset  = (int) $base->format('P') * 60;
    $desde_trend = (clone $base)->modify('-' . ($meses_n - 1) . ' months')->format('Y-m-01 00:00:00');

    $rows_trend = $wpdb->get_results($wpdb->prepare("
        SELECT DATE_FORMAT(DATE_ADD(v.creado_en, INTERVAL %d MINUTE), '%%Y-%%m') AS mes,
               COUNT(*) AS ventas,
               SUM(v.monto_comision_sol * v.bonus_multiplier) AS total
        FROM $v v
        WHERE $h_ids AND v.creado_en >= %s
        GROUP BY mes
        ORDER BY mes ASC
    ", $offset, $desde_trend));

    $meses_abbr = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
    $map_trend = [];
    foreach ((array) $rows_trend as $r) {
        $map_trend[$r->mes] = $r;
    }
    $tendencia = [];
    for ($i = $meses_n - 1; $i >= 0; $i--) {
        $ts  = (clone $base)->modify("-$i months");
        $key = $ts->format('Y-m');
        $r   = $map_trend[$key] ?? null;
        $tendencia[] = [
            'mes'    => $key,
            'label'  => $meses_abbr[intval($ts->format('n'))] . ' ' . $ts->format('y'),
            'total'  => $r ? round(floatval($r->total), 2) : 0,
            'ventas' => $r ? intval($r->ventas) : 0,
        ];
    }

    // ---- ¿Qué vende tu equipo? (categoría / subcategoría) ----
    $por_categoria    = billetera_stats_desglose_equipo($ids, $periodo, 'categoria', $base);
    $por_subcategoria = billetera_stats_desglose_equipo($ids, $periodo, 'subcategoria', $base);

    // ---- ¿Cuánto te aporta cada vendedor? (comisiones espejo del jefe) ----
    // Incluye a TODOS los vendedores con ventas propias del periodo: si no hay
    // espejo (venta anterior a la funcionalidad, regla en 0, etc.) aporta S/ 0,
    // para que el jefe vea la contribucion real de cada uno.
    $aportan = [];
    if ($ids) {
        $espejos = [];
        $rows_ap = $wpdb->get_results($wpdb->prepare(
            "SELECT v.asesor_id, SUM(v.monto_comision_sol * v.bonus_multiplier) AS com, COUNT(*) AS ventas
             FROM $v v
             WHERE v.usuario_id = %d AND v.asesor_id <> 0 AND v.asesor_id IS NOT NULL $h_rng
             GROUP BY v.asesor_id",
            $user_id, ...$p_rng
        ));
        foreach ((array) $rows_ap as $r) {
            $aid = intval($r->asesor_id);
            $espejos[$aid] = ['com' => round(floatval($r->com), 2), 'ventas' => intval($r->ventas)];
        }

        foreach ($ids as $aid) {
            $pa = $per_asesor[$aid] ?? null;
            if (!$pa || intval($pa['ventas']) === 0) {
                continue; // sin ventas propias en el periodo: no aporta nada que mostrar
            }
            $aportan[] = [
                'id'     => $aid,
                'nombre' => $nombres[$aid] ?? ('Asesor #' . $aid),
                'com'    => isset($espejos[$aid]) ? $espejos[$aid]['com'] : 0.0,
                'ventas' => intval($pa['ventas']),
            ];
        }
    }
    usort($aportan, function ($a, $b) {
        if ($a['com'] !== $b['com']) {
            return $b['com'] <=> $a['com'];
        }
        return $b['ventas'] <=> $a['ventas'];
    });

    // ---- Top vendedores del equipo (por ventas, sin montos) ----
    // En multi-sucursal, cada asesor muestra su propia sucursal.
    $tienda_labels = [];
    foreach ($tienda_ids as $tid) {
        // Decodifica entidades (&#8211; etc. de wptexturize) para que no se vean
        // literales en textContent del JS (alertas, top, storebar).
        // Nota: wp_specialchars_decode NO decodifica entidades numericas.
        $tienda_labels[$tid] = html_entity_decode(get_the_title($tid), ENT_QUOTES, 'UTF-8');
    }
    $tienda_label = (count($tienda_labels) === 1)
        ? (string) reset($tienda_labels)
        : 'Todas (' . count($tienda_labels) . ')';
    $top = [];
    foreach ($ids as $aid) {
        $pa = $per_asesor[$aid] ?? ['ventas' => 0, 'com' => 0.0, 'ultima' => null];
        $tid_a = intval(get_user_meta($aid, '_tienda_asociada', true));
        $top[] = [
            'id'       => $aid,
            'nombre'   => $nombres[$aid] ?? ('Asesor #' . $aid),
            'sucursal' => $tienda_labels[$tid_a] ?? '-',
            'ventas'   => intval($pa['ventas']),
            'com'      => floatval($pa['com']),
            // Comision que le aporta al jefe (espejo del periodo).
            'aporta'   => isset($espejos[$aid]['com']) ? floatval($espejos[$aid]['com']) : 0.0,
            // Racha actual del asesor (dias consecutivos vendiendo) + señal de
            // "sin ventas" para detectar quien no esta vendiendo.
            'racha'    => function_exists('billetera_get_racha') ? intval(billetera_get_racha($aid)) : 0,
            'ultima'   => $pa['ultima'] ?? null,
            // Fecha de su ultima venta historicamente (para la columna "Ult. venta").
            'ultima_total' => $ultima_total[$aid] ?? null,
        ];
    }
    usort($top, function ($a, $b) {
        if ($a['ventas'] !== $b['ventas']) {
            return $b['ventas'] <=> $a['ventas'];
        }
        return $b['com'] <=> $a['com'];
    });

    // ---- Alertas ----
    $sin_ventas = [];
    $sin_por_tienda = [];   // sucursal => # de asesores sin ventas
    foreach ($ids as $aid) {
        $pa = $per_asesor[$aid] ?? null;
        if (!$pa || intval($pa['ventas']) === 0) {
            $sin_ventas[] = $nombres[$aid] ?? ('Asesor #' . $aid);
            $tid_a = intval(get_user_meta($aid, '_tienda_asociada', true));
            if ($tid_a) {
                $label_a = $tienda_labels[$tid_a] ?? '';
                if ($label_a) {
                    $sin_por_tienda[$label_a] = intval($sin_por_tienda[$label_a] ?? 0) + 1;
                }
            }
        }
    }
    $ventas_dia = $rest_dias > 0 ? round($faltan_vtas / $rest_dias, 1) : 0.0;

    // ---- "Para llegar a tu meta": filas por categoría con meta de sucursal ----
    // Une las metas por categoría de las sucursales del equipo (sumadas cuando
    // hay varias), las ventas del periodo por categoría y el promedio real de
    // comisión espejo del jefe por categoría ("según tus registros, sin bonos").
    $llegar_meta = [];
    $cats_meta = []; // categoria_id => meta sumada + nombre
    foreach ($tienda_ids as $tid_lm) {
        foreach (billetera_get_meta_sucursal_rows($tid_lm, $tipo_jefe) as $row_lm) {
            $cid = intval($row_lm->categoria_id);
            if (!isset($cats_meta[$cid])) {
                $cats_meta[$cid] = [
                    'meta'   => 0,
                    'nombre' => $row_lm->categoria_nombre ?: ('Categoría #' . $cid),
                    'linea'  => $row_lm->linea_codigo ?: '',
                ];
            }
            $cats_meta[$cid]['meta'] += intval($row_lm->unidades);
        }
    }
    if ($cats_meta && $ids) {
        $linea_nombres = [];
        foreach ($wpdb->get_results("SELECT codigo, nombre FROM {$wpdb->prefix}billetera_lineas") as $l) {
            $linea_nombres[$l->codigo] = $l->nombre;
        }

        // Ventas del equipo por categoría en el periodo (ventas propias, sin espejos).
        $ventas_cat = [];
        $rows_vc = $wpdb->get_results($wpdb->prepare("
            SELECT c.id AS categoria_id, COUNT(*) AS ventas
            FROM $v v
            INNER JOIN {$wpdb->prefix}billetera_subcategorias s ON s.id = v.subcategoria_id
            INNER JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
            WHERE v.usuario_id IN ($in) AND v.asesor_id IS NULL $h_rng
            GROUP BY c.id
        ", array_merge($ids, $p_rng)));
        foreach ((array) $rows_vc as $r) {
            $ventas_cat[intval($r->categoria_id)] = intval($r->ventas);
        }

        // Promedio de comisión espejo del jefe por categoría (sus registros).
        $com_cat = [];
        $rows_cc = $wpdb->get_results($wpdb->prepare("
            SELECT c.id AS categoria_id, AVG(v.monto_comision_sol * v.bonus_multiplier) AS prom
            FROM $v v
            INNER JOIN {$wpdb->prefix}billetera_subcategorias s ON s.id = v.subcategoria_id
            INNER JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
            WHERE v.usuario_id = %d AND v.asesor_id IN ($in) $h_rng
            GROUP BY c.id
        ", array_merge([$user_id], $ids, $p_rng)));
        foreach ((array) $rows_cc as $r) {
            $com_cat[intval($r->categoria_id)] = floatval($r->prom);
        }

        foreach ($cats_meta as $cid => $cm) {
            if ($cm['meta'] <= 0) {
                continue;
            }
            $ventas_c = isset($ventas_cat[$cid]) ? $ventas_cat[$cid] : 0;
            $llegar_meta[] = [
                'categoria_id' => $cid,
                'nombre'       => $cm['nombre'],
                'linea'        => isset($linea_nombres[$cm['linea']]) ? $linea_nombres[$cm['linea']] : $cm['linea'],
                'meta'         => intval($cm['meta']),
                'ventas'       => $ventas_c,
                'faltan'       => max(0, intval($cm['meta']) - $ventas_c),
                'com_prom'     => isset($com_cat[$cid]) ? round($com_cat[$cid], 2) : 0.0,
            ];
        }
        usort($llegar_meta, function ($a, $b) {
            if ($a['faltan'] !== $b['faltan']) {
                return $b['faltan'] <=> $a['faltan'];
            }
            return strcasecmp($a['nombre'], $b['nombre']);
        });
    }

    wp_send_json_success([
        'periodo'          => $periodo,
        'base_mes'         => $base->format('Y-m'),
        'base_ano'         => $base->format('Y'),
        'base_label'       => $base_label,
        'es_actual'        => $es_actual,
        'tienda_label'     => $tienda_label,
        'kpis'             => [
            'ventas'       => $ventas,
            'comisiones'   => round($com, 2),
            'meta_sol'     => round($meta_sol, 2),
            'meta_ventas'  => $meta_vtas,
            'avance'       => $avance,
            'faltan_sol'   => $faltan_sol,
            'faltan_ventas'=> $faltan_vtas,
            'dias_rest'    => $rest_dias,
            'proy_ventas'  => $proy_vtas,
            'dias_laborables' => $lab_total,
            'dias_laborables_transcurridos' => $lab_trans,
        ],
        'jefe'             => [
            'com_mes'      => round($com_jefe, 2),
            // Datos para la vista v2 de "Tu comisión de jefe" (flag meta_equipo_v2):
            // ventas espejo del periodo y monto promedio por venta del jefe.
            'espejo_ventas' => isset($espejos) ? array_sum(array_column($espejos, 'ventas')) : 0,
            'espejo_unit'  => (isset($espejos) && array_sum(array_column($espejos, 'ventas')) > 0)
                ? round($com_jefe / array_sum(array_column($espejos, 'ventas')), 2)
                : 0.0,
            // Placeholders: se llenan cuando llegue la data de sucursales.
            'bono'         => null,
            'por_validar'  => ['ventas' => 0, 'com' => 0.0],
        ],
        'ritmo'            => [
            'ideal_ventas' => $ideal_vtas,
            'behind'       => $behind,
            'ventas_dia'   => $ventas_dia,
        ],
        'tendencia'        => $tendencia,
        'por_categoria'    => $por_categoria,
        'por_subcategoria' => $por_subcategoria,
        'aportan'          => $aportan,
        'llegar_meta'      => $llegar_meta,
        'top'              => $top,
        'alertas'          => [
            'sin_ventas_total' => count($sin_ventas),
            'sin_ventas_nombres' => $sin_ventas,
            'sin_ventas_por_tienda' => $sin_por_tienda,
            'behind_ventas'    => $behind,
            'ventas_dia'       => $ventas_dia,
        ],
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

    // La línea de cada venta: la guardada en la venta; para históricos sin
    // dato, la primera línea de la subcategoría (tabla puente).
    $linea_expr = "COALESCE(v.linea_codigo, (SELECT b.linea_codigo FROM {$wpdb->prefix}billetera_subcategoria_lineas b WHERE b.subcategoria_id = s.id ORDER BY b.linea_codigo ASC LIMIT 1))";

    if ($tipo === 'linea') {
        $select = "l.nombre AS label";
        $join   = "JOIN $lin l ON l.codigo = $linea_expr";
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
 * Usa la misma lógica que la app web: stats de billetera_ranking_stats_mes
 * (cuenta todas las ventas, sin filtro de comisión > 0), ámbitos de
 * billetera_ranking_scope_ids (en global un asesor solo compite con asesores)
 * y posiciones con empates compartidos.
 */
function billetera_stats_ranking($user_id) {
    $user_id = intval($user_id);
    $stats = billetera_ranking_stats_mes();

    $rank_en = function ($ambito) use ($user_id, $stats) {
        $ids = billetera_ranking_scope_ids($user_id, $ambito);
        if (empty($ids)) {
            return ['rank' => 0, 'total' => 0];
        }
        list($orden, $pos_map) = billetera_ranking_ordenar_posiciones($ids, $stats);
        return ['rank' => $pos_map[$user_id] ?? 0, 'total' => count($orden)];
    };

    return [
        'tienda' => $rank_en('tienda'),
        'global' => $rank_en('global'),
    ];
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
 * Tipo del asesor (Venta / Post Venta). Usuarios sin meta o vacío se tratan
 * como "Venta" (mismo default del importador CSV).
 */
function billetera_ranking_tipo($user_id) {
    $tipo = trim((string) get_user_meta(intval($user_id), 'billetera_tipo', true));
    return ($tipo === '') ? 'Venta' : $tipo;
}

/**
 * Filtra IDs del ámbito por el tipo (billetera_tipo) del usuario de referencia:
 * asesor de venta y asesor de post venta no compiten entre sí en el ranking.
 * Misma regla que la comisión de jefes (solo bonifica a jefes de su mismo tipo).
 */
function billetera_ranking_filtrar_tipo($ids, $user_id) {
    $ids = array_map('intval', (array) $ids);
    if (empty($ids)) {
        return [];
    }

    $mi_tipo = billetera_ranking_tipo($user_id);

    // Un solo query para los tipos de todos los candidatos (sin meta = Venta).
    global $wpdb;
    $in   = implode(',', $ids);
    $rows = $wpdb->get_results(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'billetera_tipo' AND user_id IN ($in)"
    );

    $tipos = array_fill_keys($ids, 'Venta');
    foreach ((array) $rows as $r) {
        $t = trim((string) $r->meta_value);
        if ($t !== '') {
            $tipos[intval($r->user_id)] = $t;
        }
    }

    $out = [];
    foreach ($tipos as $id => $tipo) {
        if ($tipo === $mi_tipo) {
            $out[] = intval($id);
        }
    }
    return $out;
}

/**
 * IDs de usuarios que participan del ranking según el ámbito.
 * Además del rol, separa por tipo (billetera_tipo): Venta y Post Venta
 * van en tableros distintos, en tienda y en global.
 */
function billetera_ranking_scope_ids($user_id, $ambito) {
    if ($ambito === 'equipo') {
        // Asesores a cargo del jefe (sus tiendas + su tipo). Solo lo usa el
        // AJAX de ranking de jefes.
        return billetera_ranking_jefe_asesores($user_id);
    }

    if ($ambito === 'global_asesores') {
        // Vista "Global" del jefe: TODOS los asesores del país de su tipo
        // (para ver dónde están parados los suyos a nivel nacional).
        $ids = get_users([
            'role'   => 'asesor',
            'fields' => 'ID',
            'number' => -1,
        ]);
        return billetera_ranking_filtrar_tipo($ids, $user_id);
    }

    if ($ambito === 'tienda') {
        $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
        if (!$tienda_id) {
            return [];
        }
        return billetera_ranking_filtrar_tipo(get_users([
            'meta_key'     => '_tienda_asociada',
            'meta_value'   => $tienda_id,
            'fields'       => 'ID',
            'role__not_in' => ['jefe_venta'],
        ]), $user_id);
    }

    // Global: cada rol compite entre sí. Un asesor solo ve asesores.
    $user  = get_userdata($user_id);
    $roles = $user ? (array) $user->roles : [];
    $roles_scope = in_array('asesor', $roles, true)
        ? ['asesor']
        : ['asesor', 'jefe_venta'];

    return billetera_ranking_filtrar_tipo(get_users([
        'role__in' => $roles_scope,
        'fields'   => 'ID',
        'number'   => -1,
    ]), $user_id);
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
            'id'     => $id,
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
 * Orden y posiciones compartidos por todos los rankings (app, dashboard, email).
 * Orden: MONTO total DESC (redondeado a 2 dec), ventas DESC (desempate visible), ID ASC.
 * Posición con empates: 1 + N estrictamente delante por MONTO redondeado a 2 decimales.
 * Devuelve [orden (uids ordenados), pos_map (uid => pos)].
 */
function billetera_ranking_ordenar_posiciones($ids, $stats = []) {
    $orden = array_values(array_map('intval', (array) $ids));
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

    return [$orden, $pos_map];
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

    // Orden por MONTO total DESC + posiciones con empates (helper compartido
    // con el dashboard y el resumen semanal).
    list($orden, $pos_map) = billetera_ranking_ordenar_posiciones($ids, $stats);

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
    // Decodifica entidades HTML (&#8211; etc.): el label va a textContent de JS.
    // Nota: wp_specialchars_decode NO decodifica entidades numericas.
    return $tienda ? html_entity_decode($tienda->post_title, ENT_QUOTES, 'UTF-8') : '';
}

// =====================================================================
// RANKING DE JEFES: ranking por sucursal + ranking de sus asesores
// =====================================================================

/**
 * Sucursales que administra un jefe de venta. Fuente de verdad: el perfil del
 * usuario (user meta _tiendas_del_jefe, multiselect "Sucursales que
 * administras"). Se mantiene espejado en _jefes_venta_asociados de cada tienda
 * al guardar en cualquiera de las dos direcciones. SIN fallback a
 * _tienda_asociada: si el jefe no tiene sucursales asignadas, no administra
 * ninguna.
 */
function billetera_jefe_tiendas($user_id) {
    $val = get_user_meta(intval($user_id), '_tiendas_del_jefe', true);
    if (!is_array($val)) {
        $val = $val ? [$val] : [];
    }
    $out = [];
    foreach (array_map('intval', (array) $val) as $tid) {
        if (!$tid) {
            continue;
        }
        $t = get_post($tid);
        if ($t && $t->post_type === 'tienda') {
            $out[$tid] = html_entity_decode($t->post_title, ENT_QUOTES, 'UTF-8');
        }
    }
    return $out;
}

/**
 * Tiendas donde el usuario es jefe de venta (perfil: _tiendas_del_jefe).
 * Sin fallback a _tienda_asociada (regla anterior eliminada).
 */
function billetera_ranking_jefe_tiendas($user_id) {
    return billetera_jefe_tiendas($user_id);
}

/**
 * IDs de los asesores a cargo del jefe: sus tiendas + su tipo
 * (Venta / Post Venta no compiten, misma regla del ranking de asesores).
 */
function billetera_ranking_jefe_asesores($user_id) {
    $tiendas = array_keys(billetera_ranking_jefe_tiendas($user_id));
    if (empty($tiendas)) {
        return [];
    }

    $ids = get_users([
        'meta_query' => [
            ['key' => '_tienda_asociada', 'value' => $tiendas, 'compare' => 'IN'],
        ],
        'role'   => 'asesor',
        'fields' => 'ID',
        'number' => -1,
    ]);

    return billetera_ranking_filtrar_tipo($ids, $user_id);
}

/**
 * Filas del ranking por sucursal para el jefe: TODAS las sucursales que
 * tengan al menos 1 asesor del tipo del jefe (para comparar su sucursal
 * contra las demas de la compania). Solo ventas de asesores del tipo.
 */
function billetera_ranking_sucursal_rows($user_id, $desde, $hasta, $vs_desde, $vs_hasta) {
    global $wpdb;

    // Asesores (rol asesor) con tienda asociada, agrupados por sucursal.
    $cap_key = $wpdb->prefix . 'capabilities';
    $rows_u = $wpdb->get_results("
        SELECT u.ID AS uid, tm.meta_value AS tienda_id
        FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} tm ON tm.user_id = u.ID AND tm.meta_key = '_tienda_asociada' AND tm.meta_value <> ''
        INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = u.ID
            AND cap.meta_key = '$cap_key'
            AND cap.meta_value LIKE '%\"asesor\"%'
    ");

    $por_tienda = [];
    $todos_ids = [];
    foreach ((array) $rows_u as $r) {
        $uid = intval($r->uid);
        $tid = intval($r->tienda_id);
        if (!$uid || !$tid) {
            continue;
        }
        $todos_ids[$uid] = $tid;
    }
    if (empty($todos_ids)) {
        return [];
    }

    // Solo asesores del tipo del jefe (sin meta = Venta).
    $ids_tipo = billetera_ranking_filtrar_tipo(array_keys($todos_ids), $user_id);
    foreach ($ids_tipo as $uid) {
        $por_tienda[$todos_ids[$uid]][] = intval($uid);
    }
    if (empty($por_tienda)) {
        return [];
    }

    // Info de tiendas + dealer (subtitulo de la fila).
    $tiendas_info = [];
    $posts = get_posts(['post_type' => 'tienda', 'numberposts' => -1, 'post_status' => 'publish']);
    foreach ($posts as $t) {
        $dealer_id = intval(get_post_meta($t->ID, '_dealer_id', true));
        $dealer = $dealer_id ? get_post($dealer_id) : null;
        $tiendas_info[intval($t->ID)] = [
            'nombre' => html_entity_decode($t->post_title, ENT_QUOTES, 'UTF-8'),
            'dealer' => $dealer ? html_entity_decode($dealer->post_title, ENT_QUOTES, 'UTF-8') : '',
        ];
    }

    $all_ids = array_merge([], ...array_values($por_tienda));
    $in_ids = implode(',', array_map('intval', $all_ids));
    $v  = $wpdb->prefix . 'billetera_ventas';
    $sb = $wpdb->prefix . 'billetera_subcategorias';

    // Ventas / comisiones por tienda para un rango dado.
    $ventas_rango = function ($d, $h) use ($wpdb, $v, $in_ids) {
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT tm.meta_value AS tienda_id, COUNT(*) AS ventas,
                   COALESCE(SUM(v.monto_comision_sol * v.bonus_multiplier), 0) AS com
            FROM $v v
            INNER JOIN {$wpdb->usermeta} tm ON tm.user_id = v.usuario_id AND tm.meta_key = '_tienda_asociada'
            WHERE v.usuario_id IN ($in_ids) AND v.creado_en >= %s AND v.creado_en < %s
            GROUP BY tm.meta_value
        ", $d, $h));
        $out = [];
        foreach ((array) $rows as $r) {
            $out[intval($r->tienda_id)] = [
                'ventas' => intval($r->ventas),
                'com'    => floatval($r->com),
            ];
        }
        return $out;
    };

    $cur = $ventas_rango($desde, $hasta);
    $ant = $ventas_rango($vs_desde, $vs_hasta);

    // Producto top por tienda (subcategoria con mas ventas del periodo).
    $rows_prod = $wpdb->get_results($wpdb->prepare("
        SELECT tm.meta_value AS tienda_id, s.nombre, COUNT(*) AS n
        FROM $v v
        INNER JOIN {$wpdb->usermeta} tm ON tm.user_id = v.usuario_id AND tm.meta_key = '_tienda_asociada'
        INNER JOIN $sb s ON s.id = v.subcategoria_id
        WHERE v.usuario_id IN ($in_ids) AND v.creado_en >= %s AND v.creado_en < %s
        GROUP BY tm.meta_value, s.nombre
        ORDER BY n DESC, s.nombre ASC
    ", $desde, $hasta));
    $prod_top = [];
    foreach ((array) $rows_prod as $r) {
        $tid = intval($r->tienda_id);
        if (!isset($prod_top[$tid])) {
            $prod_top[$tid] = html_entity_decode((string) $r->nombre, ENT_QUOTES, 'UTF-8');
        }
    }

    // Meta de la sucursal: UNIDADES por categoría configuradas en la vista
    // "Metas por sucursal" (suma de unidades para el tipo del jefe).
    // Si la sucursal aún no tiene metas por categoría, se conserva la regla
    // anterior: suma de las metas de sus asesores convertida a nº de ventas
    // vía ticket promedio (misma regla de la pantalla "Mi equipo").
    $tipo_jefe = billetera_ranking_tipo($user_id);
    $meta_sucs = billetera_get_meta_sucursal_map($tipo_jefe);
    $rows = [];
    foreach ($por_tienda as $tid => $ids_t) {
        $info = $tiendas_info[$tid] ?? ['nombre' => ('Sucursal #' . $tid), 'dealer' => ''];
        $ventas = intval($cur[$tid]['ventas'] ?? 0);
        $com    = floatval($cur[$tid]['com'] ?? 0.0);

        $meta_sol  = 0.0;
        $meta_vtas = 0;
        $pct       = 0.0;

        if (isset($meta_sucs[$tid]) && $meta_sucs[$tid] > 0) {
            // Meta en unidades: suma de las metas por categoría de la sucursal.
            // Avance: nº de ventas del periodo / meta en unidades (sin tope).
            $meta_vtas = intval($meta_sucs[$tid]);
            $pct = round(($ventas / $meta_vtas) * 100, 1);
        } else {
            foreach ($ids_t as $aid) {
                $meta_sol += floatval(billetera_get_meta_por_usuario($aid));
            }

            // Meta en numero de ventas: monto de la meta / ticket promedio de la
            // sucursal (mismo criterio de la pantalla de equipo).
            $ticket   = $ventas > 0 ? ($com / $ventas) : 0.0;
            $meta_vtas = ($meta_sol > 0 && $ticket > 0) ? (int) round($meta_sol / $ticket) : 0;

            // Avance de meta: comisiones del periodo / meta en soles (sin tope,
            // puede superar 100%; la barra de UI se cap en 100%).
            $pct = ($meta_sol > 0) ? round(($com / $meta_sol) * 100, 1) : 0.0;
        }

        // Asesores con ventas en el periodo (distintos usuarios con venta).
        $con_ventas = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT usuario_id) FROM $v
             WHERE usuario_id IN (" . implode(',', array_map('intval', $ids_t)) . ")
             AND creado_en >= %s AND creado_en < %s",
            $desde, $hasta
        ));

        // Comparacion vs mes anterior (mismo dia si es mes en curso).
        $ant_ventas = intval($ant[$tid]['ventas'] ?? 0);
        $vs_pct = null;
        if ($ant_ventas > 0) {
            $vs_pct = round((($ventas - $ant_ventas) / $ant_ventas) * 100, 0);
        } elseif ($ant_ventas === 0 && $ventas > 0) {
            $vs_pct = 100.0; // Sin ventas el mes pasado: +100%.
        }

        $rows[] = [
            'tienda_id'    => $tid,
            'nombre'       => $info['nombre'],
            'dealer'       => $info['dealer'],
            'asesores_tot' => count($ids_t),
            'con_ventas'   => $con_ventas,
            'ventas'       => $ventas,
            'meta_sol'     => $meta_sol,
            'com'          => $com,
            'meta_vtas'    => $meta_vtas,
            'pct'          => $pct,
            'producto_top' => $prod_top[$tid] ?? '',
            'vs_pct'       => $vs_pct,
        ];
    }

    // Orden default: % de meta DESC; empate por ventas DESC, nombre ASC.
    usort($rows, function ($a, $b) {
        $pa = round(floatval($a['pct']), 2);
        $pb = round(floatval($b['pct']), 2);
        if ($pa !== $pb) {
            return $pb <=> $pa;
        }
        if ($a['ventas'] !== $b['ventas']) {
            return $b['ventas'] <=> $a['ventas'];
        }
        return strcasecmp($a['nombre'], $b['nombre']);
    });

    // Posiciones con empates por % de meta redondeado.
    $pos = 0;
    $prev = null;
    $delante = 0;
    foreach ($rows as $i => $r) {
        $p = round(floatval($r['pct']), 2);
        if ($prev === null || $p !== $prev) {
            $pos = $delante + 1;
            $prev = $p;
        }
        $rows[$i]['pos'] = $pos;
        $delante++;
    }

    return $rows;
}

add_action('wp_ajax_billetera_get_ranking_jefe', 'billetera_ajax_get_ranking_jefe');

/**
 * Ranking para jefes de venta:
 * - sucursales: ranking de todas las sucursales (del tipo del jefe) por
 *   % de meta, con ventas, producto top y comparacion vs mes anterior.
 * - asesores: leaderboard de los asesores a su cargo (misma estructura
 *   que el ranking de asesores).
 */
function billetera_ajax_get_ranking_jefe() {
    global $wpdb;

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
    if (!array_intersect(['jefe_venta', 'administrator'], (array) $current_user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    $user_id = get_current_user_id();
    $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $now = billetera_stats_local_now();

    // Mes solicitado (YYYY-MM). Por defecto, mes actual.
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
    $mes_ant_key = (clone $base)->modify('first day of last month')->format('Y-m');

    // Rango comparable del mes anterior: si es el mes en curso, se compara
    // con el mismo dia del mes anterior; si es un mes pasado, mes completo.
    $dia_actual = $es_actual ? intval($now->format('j')) : intval($base->format('t'));
    $prev = (clone $base)->modify('-1 month');
    $ant_desde = $prev->format('Y-m-01 00:00:00');
    if ($es_actual) {
        $ant_corte = (clone $prev)->modify('+' . ($dia_actual - 1) . ' days');
        $ant_hasta = $ant_corte->format('Y-m-d 23:59:59');
    } else {
        $ant_hasta = $base->format('Y-m-01 00:00:00');
    }

    // Mapa usuario -> tienda (para el filtro por sucursal de "Mis asesores").
    $tid_rows = $wpdb->get_results(
        "SELECT user_id, meta_value AS tid FROM {$wpdb->usermeta} WHERE meta_key = '_tienda_asociada'"
    );
    $tid_map = [];
    foreach ((array) $tid_rows as $r) {
        $tid_map[intval($r->user_id)] = intval($r->tid);
    }

    // Enrich: agrega tienda_id a cada item del leaderboard del equipo.
    $enrich_tienda = function (&$rows) use ($tid_map) {
        foreach ((array) $rows as $i => $row) {
            if (is_array($row)) {
                $rows[$i]['tienda_id'] = $tid_map[intval($row['id'] ?? 0)] ?? 0;
            }
        }
    };

    $lista_equipo = billetera_ranking_lista($user_id, 'equipo', 10, 2, $desde, $hasta);
    foreach (['top', 'ventana', 'todos'] as $k) {
        $enrich_tienda($lista_equipo[$k]);
    }

    // Sus tiendas (para el picker de sucursal).
    $mis_tiendas = [];
    foreach (billetera_ranking_jefe_tiendas($user_id) as $tid => $tnombre) {
        $mis_tiendas[] = ['id' => intval($tid), 'nombre' => $tnombre];
    }

    // Resumen de SUS sucursales (KPIs/panel cuando administra varias).
    $suc_rows = billetera_ranking_sucursal_rows($user_id, $desde, $hasta, $ant_desde, $ant_hasta);
    $mis_tids = array_map('intval', array_column($mis_tiendas, 'id'));
    $agg = [
        'tiendas' => 0, 'ventas' => 0, 'meta_sol' => 0.0, 'com' => 0.0,
        'con_ventas' => 0, 'asesores_tot' => 0, 'mejor_pos' => 0, 'pct' => 0.0, 'producto_top' => '',
    ];
    foreach ($suc_rows as $r) {
        if (!in_array(intval($r['tienda_id']), $mis_tids, true)) {
            continue;
        }
        $agg['tiendas']++;
        $agg['ventas']       += intval($r['ventas']);
        $agg['meta_sol']     += floatval($r['meta_sol']);
        $agg['com']          += floatval($r['com']);
        $agg['con_ventas']   += intval($r['con_ventas']);
        $agg['asesores_tot'] += intval($r['asesores_tot']);
        if (!$agg['mejor_pos'] || intval($r['pos']) < $agg['mejor_pos']) {
            $agg['mejor_pos'] = intval($r['pos']);
        }
    }
    $agg['pct'] = ($agg['meta_sol'] > 0) ? round(($agg['com'] / $agg['meta_sol']) * 100, 1) : 0.0;

    // Producto top combinado de sus sucursales.
    $mis_asesores = billetera_ranking_jefe_asesores($user_id);
    if ($mis_asesores && $mis_tids) {
        $v2 = $wpdb->prefix . 'billetera_ventas';
        $sb2 = $wpdb->prefix . 'billetera_subcategorias';
        $in_a = implode(',', array_map('intval', $mis_asesores));
        $in_t = implode(',', $mis_tids);
        $row_prod = $wpdb->get_row($wpdb->prepare("
            SELECT s.nombre, COUNT(*) AS n
            FROM $v2 v
            INNER JOIN {$wpdb->usermeta} tm ON tm.user_id = v.usuario_id AND tm.meta_key = '_tienda_asociada'
            INNER JOIN $sb2 s ON s.id = v.subcategoria_id
            WHERE v.usuario_id IN ($in_a) AND tm.meta_value IN ($in_t)
            AND v.creado_en >= %s AND v.creado_en < %s
            GROUP BY s.nombre
            ORDER BY n DESC
            LIMIT 1
        ", $desde, $hasta));
        $agg['producto_top'] = $row_prod ? html_entity_decode((string) $row_prod->nombre, ENT_QUOTES, 'UTF-8') : '';
    }

    wp_send_json_success([
        'periodo'        => 'mes',
        'mes'            => $base->format('Y-m'),
        'mes_label'      => $meses[intval($base->format('n'))] . ' ' . $base->format('Y'),
        'mes_anterior'   => $mes_ant_key,
        'mes_anterior_label' => $meses[intval($prev->format('n'))] . ' ' . $prev->format('Y'),
        'dia_actual'     => $dia_actual,
        'es_actual'      => $es_actual,
        'tipo_label'     => billetera_ranking_tipo($user_id),
        'tienda_label'   => billetera_ranking_tienda_label($user_id),
        'mi_tienda_id'   => intval(get_user_meta($user_id, '_tienda_asociada', true)),
        'empresa_label'  => billetera_ranking_empresa_label($user_id),
        'mis_tiendas'    => $mis_tiendas,
        'mis_resumen'    => $agg,
        'sucursales'     => $suc_rows,
        'asesores'       => $lista_equipo,
        'asesores_global' => billetera_ranking_lista($user_id, 'global_asesores', 10, 2, $desde, $hasta),
    ]);
}

/**
 * Nombre del dealer de la tienda del usuario (para el título
 * "RANKING POR SUCURSAL · {DEALER}").
 */
function billetera_ranking_empresa_label($user_id) {
    $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
    if (!$tienda_id) {
        return '';
    }
    $dealer_id = intval(get_post_meta($tienda_id, '_dealer_id', true));
    if (!$dealer_id) {
        return '';
    }
    $dealer = get_post($dealer_id);
    return $dealer ? html_entity_decode($dealer->post_title, ENT_QUOTES, 'UTF-8') : '';
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
