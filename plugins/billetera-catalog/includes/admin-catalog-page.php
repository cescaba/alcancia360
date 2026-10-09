<?php
/**
 * Panel de administración: Catálogo y Comisiones.
 * Permite editar líneas, marcas, categorías, subcategorías, comisiones,
 * metas/tarifas y configuración desde el admin de WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BILLETERA_CATALOG_CAP')) {
    define('BILLETERA_CATALOG_CAP', 'manage_billetera_catalog');
}

// ---------------------------------------------------------------------------
// Capability: se auto-asigna a administradores
// ---------------------------------------------------------------------------
add_action('admin_init', 'billetera_catalog_ensure_cap');
function billetera_catalog_ensure_cap() {
    foreach (['administrator', 'administrador_hyundai'] as $role_name) {
        $role = get_role($role_name);
        if ($role && !$role->has_cap(BILLETERA_CATALOG_CAP)) {
            $role->add_cap(BILLETERA_CATALOG_CAP);
        }
    }
}

// ---------------------------------------------------------------------------
// Menú
// ---------------------------------------------------------------------------
add_action('admin_menu', 'billetera_catalog_admin_menu', 20);
function billetera_catalog_admin_menu() {
    $cap = BILLETERA_CATALOG_CAP;

    add_menu_page(
        'Catálogo y Comisiones',
        'Catálogo y Comisiones',
        $cap,
        'billetera_catalogo',
        'billetera_render_categorias_page',
        'dashicons-tag',
        27
    );

    add_submenu_page('billetera_catalogo', 'Categorías', 'Categorías', $cap, 'billetera_catalogo', 'billetera_render_categorias_page');
    add_submenu_page('billetera_catalogo', 'Subcategorías', 'Subcategorías', $cap, 'billetera_subcategorias', 'billetera_render_subcategorias_page');
    add_submenu_page('billetera_catalogo', 'Comisiones', 'Comisiones', $cap, 'billetera_comisiones_admin', 'billetera_render_comisiones_page');
    add_submenu_page('billetera_catalogo', 'Metas / Tarifas', 'Metas / Tarifas', $cap, 'billetera_metas_admin', 'billetera_render_metas_page');
    add_submenu_page('billetera_catalogo', 'Líneas y Marcas', 'Líneas y Marcas', $cap, 'billetera_lineas_admin', 'billetera_render_lineas_page');
    add_submenu_page('billetera_catalogo', 'Configuración', 'Configuración', $cap, 'billetera_config_admin', 'billetera_render_config_page');
    add_submenu_page('billetera_catalogo', 'Resumen semanal', 'Resumen semanal', $cap, 'billetera_resumen_admin', 'billetera_render_resumen_page');
}

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------
add_action('admin_enqueue_scripts', 'billetera_catalog_admin_assets');
function billetera_catalog_admin_assets() {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $pages = ['billetera_catalogo', 'billetera_subcategorias', 'billetera_comisiones_admin', 'billetera_metas_admin', 'billetera_lineas_admin', 'billetera_config_admin', 'billetera_resumen_admin'];
    if (!in_array($page, $pages, true)) {
        return;
    }

    $base = defined('BILLETERA_CATALOG_URL') ? BILLETERA_CATALOG_URL : plugin_dir_url(dirname(__DIR__) . '/billetera-catalog.php');
    wp_enqueue_style('billetera-catalog-admin', $base . 'assets/admin.css', [], '1.0');
    wp_enqueue_script('billetera-catalog-admin', $base . 'assets/admin.js', [], '1.0', true);
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function billetera_bc_post($key, $default = '') {
    return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default;
}

function billetera_bc_open($title, $desc = '') {
    echo '<div class="wrap bc-wrap">';
    echo '<h1 class="bc-title">' . esc_html($title) . '</h1>';
    if ($desc !== '') {
        echo '<p class="bc-desc">' . esc_html($desc) . '</p>';
    }
}

function billetera_bc_close() {
    echo '</div>';
}

function billetera_bc_notices($msg, $err) {
    if ($msg !== '') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
    if ($err !== '') {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($err) . '</p></div>';
    }
}

function billetera_bc_delete_form($action, $id, $label = 'Eliminar') {
    ?>
    <form method="post" class="bc-inline" onsubmit="return confirm('¿Eliminar este registro? Esta acción no se puede deshacer.');">
        <?php wp_nonce_field('billetera_catalog'); ?>
        <input type="hidden" name="bc_action" value="<?php echo esc_attr($action); ?>">
        <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
        <button type="submit" class="bc-link bc-danger"><?php echo esc_html($label); ?></button>
    </form>
    <?php
}

function billetera_bc_lineas($con_codigo = false) {
    global $wpdb;
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}billetera_lineas ORDER BY orden_display, nombre");
}

function billetera_bc_dealers() {
    return get_posts([
        'post_type'   => 'dealer',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);
}

function billetera_bc_tiendas() {
    return get_posts([
        'post_type'   => 'tienda',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);
}

function billetera_bc_categorias_options($selected = 0) {
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT c.id, c.nombre, c.linea_codigo, l.nombre AS linea_nombre
        FROM {$wpdb->prefix}billetera_categorias c
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        ORDER BY c.linea_codigo, c.orden_display, c.nombre
    ");
    $html = '';
    foreach ($rows as $r) {
        $label = ($r->linea_nombre ?: $r->linea_codigo) . ' · ' . $r->nombre;
        $html .= '<option value="' . intval($r->id) . '"' . selected($selected, $r->id, false) . '>' . esc_html($label) . '</option>';
    }
    return $html;
}

function billetera_bc_subcategorias_options($selected = 0) {
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT s.id, s.nombre, c.nombre AS categoria, l.nombre AS linea
        FROM {$wpdb->prefix}billetera_subcategorias s
        JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        ORDER BY c.linea_codigo, c.orden_display, s.orden_display, s.nombre
    ");
    $html = '';
    foreach ($rows as $r) {
        $label = ($r->linea ?: '—') . ' · ' . $r->categoria . ' · ' . $r->nombre;
        $html .= '<option value="' . intval($r->id) . '"' . selected($selected, $r->id, false) . '>' . esc_html($label) . '</option>';
    }
    return $html;
}

// ===========================================================================
// CATEGORÍAS
// ===========================================================================
function billetera_render_categorias_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_categorias';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save') {
            $id     = intval(billetera_bc_post('id', 0));
            $linea  = sanitize_text_field(billetera_bc_post('linea_codigo'));
            $nombre = sanitize_text_field(billetera_bc_post('nombre'));
            $slug   = sanitize_title(billetera_bc_post('slug'));
            if ($slug === '') {
                $slug = sanitize_title($nombre);
            }
            $orden  = intval(billetera_bc_post('orden_display', 0));
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($linea === '' || $nombre === '') {
                $err = 'Línea y nombre son obligatorios.';
            } else {
                $data = ['linea_codigo' => $linea, 'nombre' => $nombre, 'slug' => $slug, 'orden_display' => $orden, 'activo' => $activo];
                $fmt  = ['%s', '%s', '%s', '%d', '%d'];
                if ($id > 0) {
                    $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                    $msg = 'Categoría actualizada.';
                } else {
                    $wpdb->insert($table, $data, $fmt);
                    if ($wpdb->insert_id) {
                        $msg = 'Categoría creada.';
                    } else {
                        $err = 'No se pudo crear. ¿Ya existe ese slug en la misma línea?';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval(billetera_bc_post('id', 0));
            if ($id) {
                $subs = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}billetera_subcategorias WHERE categoria_id = %d", $id));
                foreach ($subs as $sid) {
                    $wpdb->delete($wpdb->prefix . 'billetera_comisiones', ['subcategoria_id' => intval($sid)], ['%d']);
                }
                $wpdb->delete($wpdb->prefix . 'billetera_subcategorias', ['categoria_id' => $id], ['%d']);
                $wpdb->delete($table, ['id' => $id], ['%d']);
                $msg = 'Categoría eliminada junto con sus subcategorías y comisiones.';
            }
        }
    }

    $edit = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    }

    $lineas = billetera_bc_lineas();
    $rows = $wpdb->get_results("
        SELECT c.*, l.nombre AS linea_nombre,
               (SELECT COUNT(*) FROM {$wpdb->prefix}billetera_subcategorias s WHERE s.categoria_id = c.id) AS total_subs
        FROM $table c
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        ORDER BY c.linea_codigo, c.orden_display, c.nombre
    ");

    billetera_bc_open('Categorías', 'Las categorías pertenecen a una línea (Hyundai Autos, Camiones, Geely, JMC).');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <h2 class="bc-card__title"><?php echo $edit ? 'Editar categoría' : 'Nueva categoría'; ?></h2>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save">
            <input type="hidden" name="id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">

            <div class="bc-field">
                <label class="bc-label">Línea *</label>
                <select name="linea_codigo" class="bc-select" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($lineas as $l): ?>
                        <option value="<?php echo esc_attr($l->codigo); ?>" <?php echo selected($edit->linea_codigo ?? '', $l->codigo, false); ?>><?php echo esc_html($l->nombre); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Nombre *</label>
                <input type="text" name="nombre" class="bc-input" required value="<?php echo esc_attr($edit->nombre ?? ''); ?>" placeholder="Ej: Prepagados">
            </div>
            <div class="bc-field">
                <label class="bc-label">Slug (opcional)</label>
                <input type="text" name="slug" class="bc-input" value="<?php echo esc_attr($edit->slug ?? ''); ?>" placeholder="auto">
            </div>
            <div class="bc-field">
                <label class="bc-label">Orden</label>
                <input type="number" name="orden_display" class="bc-input" value="<?php echo esc_attr($edit->orden_display ?? 0); ?>">
            </div>
            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="activo" value="1" <?php checked($edit ? (int) $edit->activo : 1, 1); ?>> Activo</label>
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Guardar cambios' : 'Crear categoría'; ?></button>
                <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_catalogo')); ?>">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Línea</th>
                    <th>Nombre</th>
                    <th>Slug</th>
                    <th style="width:90px;">Subcat.</th>
                    <th style="width:70px;">Activo</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="7">Sin categorías registradas.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo intval($r->id); ?></td>
                        <td><?php echo esc_html($r->linea_nombre ?: $r->linea_codigo); ?></td>
                        <td><strong><?php echo esc_html($r->nombre); ?></strong></td>
                        <td><code><?php echo esc_html($r->slug); ?></code></td>
                        <td><?php echo intval($r->total_subs); ?></td>
                        <td><?php echo (int) $r->activo ? 'Sí' : 'No'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_catalogo', 'action' => 'edit', 'id' => $r->id], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <?php billetera_bc_delete_form('delete', $r->id); ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    billetera_bc_close();
}

// ===========================================================================
// SUBCATEGORÍAS
// ===========================================================================
function billetera_render_subcategorias_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_subcategorias';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save') {
            $id       = intval(billetera_bc_post('id', 0));
            $cat      = intval(billetera_bc_post('categoria_id', 0));
            $nombre   = sanitize_text_field(billetera_bc_post('nombre'));
            $por_unid = isset($_POST['por_unidad']) ? 1 : 0;
            $orden    = intval(billetera_bc_post('orden_display', 0));
            $activo   = isset($_POST['activo']) ? 1 : 0;

            if ($cat <= 0 || $nombre === '') {
                $err = 'Categoría y nombre son obligatorios.';
            } else {
                $data = ['categoria_id' => $cat, 'nombre' => $nombre, 'por_unidad' => $por_unid, 'orden_display' => $orden, 'activo' => $activo];
                $fmt  = ['%d', '%s', '%d', '%d', '%d'];
                if ($id > 0) {
                    $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                    $msg = 'Subcategoría actualizada.';
                } else {
                    $wpdb->insert($table, $data, $fmt);
                    $msg = $wpdb->insert_id ? 'Subcategoría creada.' : '';
                    if (!$wpdb->insert_id) {
                        $err = 'No se pudo crear la subcategoría.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval(billetera_bc_post('id', 0));
            if ($id) {
                $wpdb->delete($wpdb->prefix . 'billetera_comisiones', ['subcategoria_id' => $id], ['%d']);
                $wpdb->delete($table, ['id' => $id], ['%d']);
                $msg = 'Subcategoría eliminada junto con sus comisiones.';
            }
        }
    }

    $edit = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    }

    $rows = $wpdb->get_results("
        SELECT s.*, c.nombre AS categoria, l.nombre AS linea,
               (SELECT COUNT(*) FROM {$wpdb->prefix}billetera_comisiones co WHERE co.subcategoria_id = s.id) AS total_com
        FROM $table s
        JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        ORDER BY c.linea_codigo, c.orden_display, s.orden_display, s.nombre
    ");

    billetera_bc_open('Subcategorías', 'Las subcategorías son los productos/servicios que reciben comisión.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <h2 class="bc-card__title"><?php echo $edit ? 'Editar subcategoría' : 'Nueva subcategoría'; ?></h2>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save">
            <input type="hidden" name="id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">

            <div class="bc-field bc-field--full">
                <label class="bc-label">Categoría *</label>
                <select name="categoria_id" class="bc-select" required>
                    <option value="">— Selecciona —</option>
                    <?php echo billetera_bc_categorias_options($edit->categoria_id ?? 0); ?>
                </select>
            </div>
            <div class="bc-field bc-field--full">
                <label class="bc-label">Nombre *</label>
                <input type="text" name="nombre" class="bc-input" required value="<?php echo esc_attr($edit->nombre ?? ''); ?>" placeholder="Ej: Cambio de aceite">
            </div>
            <div class="bc-field">
                <label class="bc-label">Orden</label>
                <input type="number" name="orden_display" class="bc-input" value="<?php echo esc_attr($edit->orden_display ?? 0); ?>">
            </div>
            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="por_unidad" value="1" <?php checked($edit ? (int) $edit->por_unidad : 0, 1); ?>> Comisión por unidad</label>
            </div>
            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="activo" value="1" <?php checked($edit ? (int) $edit->activo : 1, 1); ?>> Activo</label>
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Guardar cambios' : 'Crear subcategoría'; ?></button>
                <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_subcategorias')); ?>">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Línea</th>
                    <th>Categoría</th>
                    <th>Subcategoría</th>
                    <th style="width:90px;">x Unidad</th>
                    <th style="width:90px;">Comis.</th>
                    <th style="width:70px;">Activo</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8">Sin subcategorías registradas.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo intval($r->id); ?></td>
                        <td><?php echo esc_html($r->linea ?: '—'); ?></td>
                        <td><?php echo esc_html($r->categoria); ?></td>
                        <td><strong><?php echo esc_html($r->nombre); ?></strong></td>
                        <td><?php echo (int) $r->por_unidad ? 'Sí' : 'No'; ?></td>
                        <td><?php echo intval($r->total_com); ?></td>
                        <td><?php echo (int) $r->activo ? 'Sí' : 'No'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_subcategorias', 'action' => 'edit', 'id' => $r->id], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <?php billetera_bc_delete_form('delete', $r->id); ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    billetera_bc_close();
}

// ===========================================================================
// COMISIONES
// ===========================================================================
function billetera_render_comisiones_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_comisiones';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save') {
            $id         = intval(billetera_bc_post('id', 0));
            $sub        = intval(billetera_bc_post('subcategoria_id', 0));
            $dist_raw   = billetera_bc_post('distribuidor_id', '');
            $dist       = ($dist_raw === '') ? null : intval($dist_raw);
            $rol        = sanitize_text_field(billetera_bc_post('rol'));
            $monto      = floatval(billetera_bc_post('monto', 0));
            $moneda     = strtoupper(sanitize_text_field(billetera_bc_post('moneda', 'PEN')));
            $activo     = isset($_POST['activo']) ? 1 : 0;

            if ($sub <= 0 || !in_array($rol, ['asesor', 'jefe_venta'], true)) {
                $err = 'Subcategoría y rol son obligatorios.';
            } else {
                if ($id <= 0) {
                    if ($dist === null) {
                        $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE subcategoria_id = %d AND distribuidor_id IS NULL AND rol = %s", $sub, $rol)));
                    } else {
                        $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE subcategoria_id = %d AND distribuidor_id = %d AND rol = %s", $sub, $dist, $rol)));
                    }
                }

                $data = [
                    'subcategoria_id' => $sub,
                    'distribuidor_id' => $dist,
                    'rol'             => $rol,
                    'monto'           => $monto,
                    'moneda'          => $moneda,
                    'activo'          => $activo,
                ];
                $fmt = ['%d', '%d', '%s', '%f', '%s', '%d'];

                if ($id > 0) {
                    $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                    $msg = 'Comisión guardada.';
                } else {
                    $wpdb->insert($table, $data, $fmt);
                    $msg = $wpdb->insert_id ? 'Comisión creada.' : '';
                    if (!$wpdb->insert_id) {
                        $err = 'No se pudo crear la comisión.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval(billetera_bc_post('id', 0));
            if ($id) {
                $wpdb->delete($table, ['id' => $id], ['%d']);
                $msg = 'Comisión eliminada.';
            }
        }
    }

    $edit = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    }

    $dealers = billetera_bc_dealers();
    $filtro_sub = isset($_GET['subcategoria_id']) ? intval($_GET['subcategoria_id']) : 0;

    $where = '';
    if ($filtro_sub) {
        $where = $wpdb->prepare('WHERE co.subcategoria_id = %d', $filtro_sub);
    }
    $rows = $wpdb->get_results("
        SELECT co.*, s.nombre AS subcategoria, c.nombre AS categoria, l.nombre AS linea
        FROM $table co
        JOIN {$wpdb->prefix}billetera_subcategorias s ON s.id = co.subcategoria_id
        JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        $where
        ORDER BY c.linea_codigo, c.orden_display, s.orden_display, s.nombre, co.rol
    ");

    billetera_bc_open('Comisiones', 'Define el monto por subcategoría y rol. Déjala como "Genérica (TODOS)" para aplicar a todas las concesionarias.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <h2 class="bc-card__title"><?php echo $edit ? 'Editar comisión' : 'Nueva comisión'; ?></h2>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save">
            <input type="hidden" name="id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">

            <div class="bc-field bc-field--full">
                <label class="bc-label">Subcategoría *</label>
                <select name="subcategoria_id" class="bc-select" required>
                    <option value="">— Selecciona —</option>
                    <?php echo billetera_bc_subcategorias_options($edit->subcategoria_id ?? 0); ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Concesionaria</label>
                <select name="distribuidor_id" class="bc-select">
                    <option value="" <?php echo ($edit && $edit->distribuidor_id === null) || !$edit ? 'selected' : ''; ?>>— Genérica (TODOS) —</option>
                    <?php foreach ($dealers as $d): ?>
                        <option value="<?php echo intval($d->ID); ?>" <?php echo selected($edit->distribuidor_id ?? '', $d->ID, false); ?>><?php echo esc_html($d->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Rol *</label>
                <select name="rol" class="bc-select" required>
                    <option value="asesor" <?php echo selected($edit->rol ?? 'asesor', 'asesor', false); ?>>Asesor</option>
                    <option value="jefe_venta" <?php echo selected($edit->rol ?? '', 'jefe_venta', false); ?>>Jefe de Venta</option>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Monto *</label>
                <input type="number" step="0.01" min="0" name="monto" class="bc-input" required value="<?php echo esc_attr($edit->monto ?? ''); ?>" placeholder="0.00">
            </div>
            <div class="bc-field">
                <label class="bc-label">Moneda</label>
                <select name="moneda" class="bc-select">
                    <option value="PEN" <?php echo selected($edit->moneda ?? 'PEN', 'PEN', false); ?>>S/ (PEN)</option>
                    <option value="USD" <?php echo selected($edit->moneda ?? '', 'USD', false); ?>>$ (USD)</option>
                </select>
            </div>
            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="activo" value="1" <?php checked($edit ? (int) $edit->activo : 1, 1); ?>> Activo</label>
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Guardar cambios' : 'Crear comisión'; ?></button>
                <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_comisiones_admin')); ?>">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <div class="bc-table-tools">
            <form method="get" class="bc-filter">
                <input type="hidden" name="page" value="billetera_comisiones_admin">
                <label class="bc-label">Filtrar por subcategoría</label>
                <select name="subcategoria_id" class="bc-select">
                    <option value="0">— Todas —</option>
                    <?php echo billetera_bc_subcategorias_options($filtro_sub); ?>
                </select>
                <button type="submit" class="button">Filtrar</button>
            </form>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Línea</th>
                    <th>Categoría</th>
                    <th>Subcategoría</th>
                    <th>Concesionaria</th>
                    <th>Rol</th>
                    <th style="width:110px;">Monto</th>
                    <th style="width:70px;">Activo</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="9">Sin comisiones registradas.</td></tr>
                <?php else: foreach ($rows as $r): $dealer_name = $r->distribuidor_id ? get_the_title($r->distribuidor_id) : 'Genérica (TODOS)'; ?>
                    <tr>
                        <td><?php echo intval($r->id); ?></td>
                        <td><?php echo esc_html($r->linea ?: '—'); ?></td>
                        <td><?php echo esc_html($r->categoria); ?></td>
                        <td><strong><?php echo esc_html($r->subcategoria); ?></strong></td>
                        <td><?php echo esc_html($dealer_name ?: '#' . intval($r->distribuidor_id)); ?></td>
                        <td><?php echo esc_html($r->rol); ?></td>
                        <td><?php echo esc_html(($r->moneda === 'USD' ? '$ ' : 'S/ ') . number_format((float) $r->monto, 2)); ?></td>
                        <td><?php echo (int) $r->activo ? 'Sí' : 'No'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_comisiones_admin', 'action' => 'edit', 'id' => $r->id], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <?php billetera_bc_delete_form('delete', $r->id); ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    billetera_bc_close();
}

// ===========================================================================
// METAS / TARIFAS
// ===========================================================================
function billetera_render_metas_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_metas';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save') {
            $id      = intval(billetera_bc_post('id', 0));
            $tienda  = intval(billetera_bc_post('tienda_id', 0));
            $linea   = sanitize_text_field(billetera_bc_post('linea_codigo'));
            $tipo    = sanitize_text_field(billetera_bc_post('tipo'));
            $meta    = floatval(billetera_bc_post('meta', 0));

            if ($tienda <= 0 || $linea === '' || !in_array($tipo, ['Venta', 'Post Venta'], true)) {
                $err = 'Sucursal, línea y tipo son obligatorios.';
            } else {
                if ($id <= 0) {
                    $id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE tienda_id = %d AND linea_codigo = %s AND tipo = %s", $tienda, $linea, $tipo)));
                }
                $data = ['tienda_id' => $tienda, 'linea_codigo' => $linea, 'tipo' => $tipo, 'meta' => $meta];
                $fmt  = ['%d', '%s', '%s', '%f'];
                if ($id > 0) {
                    $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
                    $msg = 'Meta actualizada.';
                } else {
                    $wpdb->insert($table, $data, $fmt);
                    $msg = $wpdb->insert_id ? 'Meta creada.' : '';
                    if (!$wpdb->insert_id) {
                        $err = 'No se pudo crear la meta.';
                    }
                }
            }
        } elseif ($action === 'delete') {
            $id = intval(billetera_bc_post('id', 0));
            if ($id) {
                $wpdb->delete($table, ['id' => $id], ['%d']);
                $msg = 'Meta eliminada.';
            }
        }
    }

    $edit = null;
    if (isset($_GET['action'], $_GET['id']) && $_GET['action'] === 'edit') {
        $edit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    }

    $tiendas = billetera_bc_tiendas();
    $lineas  = billetera_bc_lineas();

    $rows = $wpdb->get_results("
        SELECT m.*, l.nombre AS linea_nombre
        FROM $table m
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = m.linea_codigo
        ORDER BY m.tienda_id, m.linea_codigo, m.tipo
    ");

    billetera_bc_open('Metas / Tarifas', 'Meta mensual por sucursal, línea y tipo. Es la base para el avance y la proyección del asesor.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <h2 class="bc-card__title"><?php echo $edit ? 'Editar meta' : 'Nueva meta'; ?></h2>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save">
            <input type="hidden" name="id" value="<?php echo $edit ? intval($edit->id) : 0; ?>">

            <div class="bc-field bc-field--full">
                <label class="bc-label">Sucursal *</label>
                <select name="tienda_id" class="bc-select" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($tiendas as $t): ?>
                        <option value="<?php echo intval($t->ID); ?>" <?php echo selected($edit->tienda_id ?? 0, $t->ID, false); ?>><?php echo esc_html($t->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Línea *</label>
                <select name="linea_codigo" class="bc-select" required>
                    <option value="">— Selecciona —</option>
                    <?php foreach ($lineas as $l): ?>
                        <option value="<?php echo esc_attr($l->codigo); ?>" <?php echo selected($edit->linea_codigo ?? '', $l->codigo, false); ?>><?php echo esc_html($l->nombre); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Tipo *</label>
                <select name="tipo" class="bc-select" required>
                    <option value="Venta" <?php echo selected($edit->tipo ?? 'Venta', 'Venta', false); ?>>Venta</option>
                    <option value="Post Venta" <?php echo selected($edit->tipo ?? '', 'Post Venta', false); ?>>Post Venta</option>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Meta (S/) *</label>
                <input type="number" step="0.01" min="0" name="meta" class="bc-input" required value="<?php echo esc_attr($edit->meta ?? ''); ?>" placeholder="0.00">
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary"><?php echo $edit ? 'Guardar cambios' : 'Crear meta'; ?></button>
                <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_metas_admin')); ?>">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Sucursal</th>
                    <th>Línea</th>
                    <th>Tipo</th>
                    <th style="width:130px;">Meta</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6">Sin metas registradas.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo intval($r->id); ?></td>
                        <td><?php echo esc_html(get_the_title($r->tienda_id) ?: ('#' . intval($r->tienda_id))); ?></td>
                        <td><?php echo esc_html($r->linea_nombre ?: $r->linea_codigo); ?></td>
                        <td><?php echo esc_html($r->tipo); ?></td>
                        <td>S/ <?php echo esc_html(number_format((float) $r->meta, 2)); ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_metas_admin', 'action' => 'edit', 'id' => $r->id], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <?php billetera_bc_delete_form('delete', $r->id); ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    billetera_bc_close();
}

// ===========================================================================
// LÍNEAS Y MARCAS
// ===========================================================================
function billetera_render_lineas_page() {
    global $wpdb;
    $lineas_t = $wpdb->prefix . 'billetera_lineas';
    $marcas_t = $wpdb->prefix . 'billetera_marcas';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save_marca') {
            $id     = intval(billetera_bc_post('id', 0));
            $nombre = sanitize_text_field(billetera_bc_post('nombre'));
            $slug   = sanitize_title(billetera_bc_post('slug'));
            if ($slug === '') {
                $slug = sanitize_title($nombre);
            }
            $activo = isset($_POST['activo']) ? 1 : 0;

            if ($nombre === '') {
                $err = 'El nombre de la marca es obligatorio.';
            } else {
                $data = ['nombre' => $nombre, 'slug' => $slug, 'activo' => $activo];
                $fmt  = ['%s', '%s', '%d'];
                if ($id > 0) {
                    $wpdb->update($marcas_t, $data, ['id' => $id], $fmt, ['%d']);
                    $msg = 'Marca actualizada.';
                } else {
                    $wpdb->insert($marcas_t, $data, $fmt);
                    $msg = $wpdb->insert_id ? 'Marca creada.' : '';
                    if (!$wpdb->insert_id) {
                        $err = 'No se pudo crear la marca (¿slug duplicado?).';
                    }
                }
            }
        } elseif ($action === 'delete_marca') {
            $id = intval(billetera_bc_post('id', 0));
            if ($id) {
                $en_uso = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $lineas_t WHERE marca_id = %d", $id)));
                if ($en_uso > 0) {
                    $err = 'No se puede eliminar: hay líneas asociadas a esta marca.';
                } else {
                    $wpdb->delete($marcas_t, ['id' => $id], ['%d']);
                    $msg = 'Marca eliminada.';
                }
            }
        } elseif ($action === 'save_linea') {
            $editando = intval(billetera_bc_post('id', 0)) > 0;
            $codigo   = strtolower(sanitize_text_field(billetera_bc_post('codigo')));
            $nombre   = sanitize_text_field(billetera_bc_post('nombre'));
            $marca    = intval(billetera_bc_post('marca_id', 0));
            $orden    = intval(billetera_bc_post('orden_display', 0));
            $activo   = isset($_POST['activo']) ? 1 : 0;

            if ($codigo === '' || $nombre === '' || $marca <= 0) {
                $err = 'Código, nombre y marca son obligatorios.';
            } elseif ($editando) {
                $wpdb->update($lineas_t, ['nombre' => $nombre, 'marca_id' => $marca, 'orden_display' => $orden, 'activo' => $activo], ['codigo' => $codigo], ['%s', '%d', '%d', '%d'], ['%s']);
                $msg = 'Línea actualizada.';
            } else {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT codigo FROM $lineas_t WHERE codigo = %s", $codigo));
                if ($exists) {
                    $err = 'Ya existe una línea con ese código.';
                } else {
                    $wpdb->insert($lineas_t, ['codigo' => $codigo, 'nombre' => $nombre, 'marca_id' => $marca, 'orden_display' => $orden, 'activo' => $activo], ['%s', '%s', '%d', '%d', '%d']);
                    $msg = $wpdb->insert_id ? 'Línea creada.' : '';
                    if (!$wpdb->insert_id) {
                        $err = 'No se pudo crear la línea.';
                    }
                }
            }
        } elseif ($action === 'delete_linea') {
            $codigo = sanitize_text_field(billetera_bc_post('codigo'));
            if ($codigo !== '') {
                $en_uso = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}billetera_categorias WHERE linea_codigo = %s", $codigo)));
                if ($en_uso > 0) {
                    $err = 'No se puede eliminar: hay categorías asociadas a esta línea.';
                } else {
                    $wpdb->delete($lineas_t, ['codigo' => $codigo], ['%s']);
                    $msg = 'Línea eliminada.';
                }
            }
        }
    }

    $edit_marca = null;
    if (isset($_GET['action'], $_GET['marca']) && $_GET['action'] === 'edit_marca') {
        $edit_marca = $wpdb->get_row($wpdb->prepare("SELECT * FROM $marcas_t WHERE id = %d", intval($_GET['marca'])));
    }
    $edit_linea = null;
    if (isset($_GET['action'], $_GET['linea']) && $_GET['action'] === 'edit_linea') {
        $edit_linea = $wpdb->get_row($wpdb->prepare("SELECT * FROM $lineas_t WHERE codigo = %s", sanitize_text_field(wp_unslash($_GET['linea']))));
    }

    $marcas = $wpdb->get_results("SELECT * FROM $marcas_t ORDER BY nombre");
    $lineas = $wpdb->get_results("
        SELECT l.*, m.nombre AS marca_nombre
        FROM $lineas_t l
        LEFT JOIN $marcas_t m ON m.id = l.marca_id
        ORDER BY l.orden_display, l.nombre
    ");

    billetera_bc_open('Líneas y Marcas', 'Las marcas agrupan líneas (Hyundai, Geely, JMC...). Las líneas son hyu, hcv, gee, jmc.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-two-col">
        <div class="bc-card">
            <h2 class="bc-card__title"><?php echo $edit_marca ? 'Editar marca' : 'Nueva marca'; ?></h2>
            <form method="post" class="bc-grid">
                <?php wp_nonce_field('billetera_catalog'); ?>
                <input type="hidden" name="bc_action" value="save_marca">
                <input type="hidden" name="id" value="<?php echo $edit_marca ? intval($edit_marca->id) : 0; ?>">
                <div class="bc-field bc-field--full">
                    <label class="bc-label">Nombre *</label>
                    <input type="text" name="nombre" class="bc-input" required value="<?php echo esc_attr($edit_marca->nombre ?? ''); ?>" placeholder="Ej: Hyundai">
                </div>
                <div class="bc-field bc-field--full">
                    <label class="bc-label">Slug</label>
                    <input type="text" name="slug" class="bc-input" value="<?php echo esc_attr($edit_marca->slug ?? ''); ?>" placeholder="auto">
                </div>
                <div class="bc-field bc-field--check">
                    <label><input type="checkbox" name="activo" value="1" <?php checked($edit_marca ? (int) $edit_marca->activo : 1, 1); ?>> Activo</label>
                </div>
                <div class="bc-field bc-field--actions">
                    <button type="submit" class="button button-primary"><?php echo $edit_marca ? 'Guardar' : 'Crear marca'; ?></button>
                    <?php if ($edit_marca): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_lineas_admin')); ?>">Cancelar</a><?php endif; ?>
                </div>
            </form>

            <table class="wp-list-table widefat fixed striped bc-mt">
                <thead><tr><th>Marca</th><th style="width:70px;">Activo</th><th style="width:130px;">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($marcas as $m): ?>
                    <tr>
                        <td><strong><?php echo esc_html($m->nombre); ?></strong><br><code><?php echo esc_html($m->slug); ?></code></td>
                        <td><?php echo (int) $m->activo ? 'Sí' : 'No'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_lineas_admin', 'action' => 'edit_marca', 'marca' => $m->id], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <?php billetera_bc_delete_form('delete_marca', $m->id); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="bc-card">
            <h2 class="bc-card__title"><?php echo $edit_linea ? 'Editar línea' : 'Nueva línea'; ?></h2>
            <form method="post" class="bc-grid">
                <?php wp_nonce_field('billetera_catalog'); ?>
                <input type="hidden" name="bc_action" value="save_linea">
                <input type="hidden" name="id" value="<?php echo $edit_linea ? 1 : 0; ?>">
                <div class="bc-field">
                    <label class="bc-label">Código *</label>
                    <input type="text" name="codigo" maxlength="3" class="bc-input" required value="<?php echo esc_attr($edit_linea->codigo ?? ''); ?>" placeholder="hyu" <?php echo $edit_linea ? 'readonly' : ''; ?>>
                </div>
                <div class="bc-field">
                    <label class="bc-label">Nombre *</label>
                    <input type="text" name="nombre" class="bc-input" required value="<?php echo esc_attr($edit_linea->nombre ?? ''); ?>" placeholder="Hyundai Autos">
                </div>
                <div class="bc-field">
                    <label class="bc-label">Marca *</label>
                    <select name="marca_id" class="bc-select" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($marcas as $m): ?>
                            <option value="<?php echo intval($m->id); ?>" <?php echo selected($edit_linea->marca_id ?? 0, $m->id, false); ?>><?php echo esc_html($m->nombre); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="bc-field">
                    <label class="bc-label">Orden</label>
                    <input type="number" name="orden_display" class="bc-input" value="<?php echo esc_attr($edit_linea->orden_display ?? 0); ?>">
                </div>
                <div class="bc-field bc-field--check">
                    <label><input type="checkbox" name="activo" value="1" <?php checked($edit_linea ? (int) $edit_linea->activo : 1, 1); ?>> Activo</label>
                </div>
                <div class="bc-field bc-field--actions">
                    <button type="submit" class="button button-primary"><?php echo $edit_linea ? 'Guardar' : 'Crear línea'; ?></button>
                    <?php if ($edit_linea): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_lineas_admin')); ?>">Cancelar</a><?php endif; ?>
                </div>
            </form>

            <table class="wp-list-table widefat fixed striped bc-mt">
                <thead><tr><th>Código</th><th>Nombre</th><th>Marca</th><th style="width:70px;">Activo</th><th style="width:130px;">Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($lineas as $l): ?>
                    <tr>
                        <td><code><?php echo esc_html($l->codigo); ?></code></td>
                        <td><strong><?php echo esc_html($l->nombre); ?></strong></td>
                        <td><?php echo esc_html($l->marca_nombre ?: '—'); ?></td>
                        <td><?php echo (int) $l->activo ? 'Sí' : 'No'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_lineas_admin', 'action' => 'edit_linea', 'linea' => $l->codigo], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <form method="post" class="bc-inline" onsubmit="return confirm('¿Eliminar esta línea?');">
                                <?php wp_nonce_field('billetera_catalog'); ?>
                                <input type="hidden" name="bc_action" value="delete_linea">
                                <input type="hidden" name="codigo" value="<?php echo esc_attr($l->codigo); ?>">
                                <button type="submit" class="bc-link bc-danger">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    billetera_bc_close();
}

// ===========================================================================
// CONFIGURACIÓN
// ===========================================================================
function billetera_render_config_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'billetera_configuracion';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action']) && sanitize_key($_POST['bc_action']) === 'save_config') {
        check_admin_referer('billetera_catalog');
        $claves = isset($_POST['clave']) && is_array($_POST['clave']) ? wp_unslash($_POST['clave']) : [];
        $valores = isset($_POST['valor']) && is_array($_POST['valor']) ? wp_unslash($_POST['valor']) : [];

        foreach ($claves as $i => $clave) {
            $clave = sanitize_key($clave);
            if ($clave === '') {
                continue;
            }
            $valor = isset($valores[$i]) ? sanitize_text_field($valores[$i]) : '';
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE clave = %s", $clave));
            if ($exists) {
                $wpdb->update($table, ['valor' => $valor], ['id' => intval($exists)], ['%s'], ['%d']);
            } else {
                $wpdb->insert($table, ['clave' => $clave, 'valor' => $valor], ['%s', '%s']);
            }
        }
        $msg = 'Configuración guardada.';
    }

    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY clave");

    billetera_bc_open('Configuración', 'Parámetros generales de comisiones y metas.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save_config">
            <?php if (!$rows): ?>
                <p>No hay parámetros registrados.</p>
            <?php else: foreach ($rows as $r): ?>
                <div class="bc-field">
                    <label class="bc-label"><?php echo esc_html($r->clave); ?></label>
                    <input type="text" name="clave[]" value="<?php echo esc_attr($r->clave); ?>" hidden>
                    <input type="text" name="valor[]" class="bc-input" value="<?php echo esc_attr($r->valor); ?>">
                </div>
            <?php endforeach; endif; ?>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary">Guardar configuración</button>
            </div>
        </form>
    </div>
    <?php
    billetera_bc_close();
}
