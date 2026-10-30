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
    // El catálogo y comisiones es para administrator y administrador_hyundai
    // (Resumen semanal y Reglas se ocultan a hyundai más abajo).
    $role = get_role('administrator');
    if ($role && !$role->has_cap(BILLETERA_CATALOG_CAP)) {
        $role->add_cap(BILLETERA_CATALOG_CAP);
    }

    $hu = get_role('administrador_hyundai');
    if ($hu && !$hu->has_cap(BILLETERA_CATALOG_CAP)) {
        $hu->add_cap(BILLETERA_CATALOG_CAP);
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
    add_submenu_page('billetera_catalogo', 'Metas potenciales', 'Metas potenciales', $cap, 'billetera_metas_admin', 'billetera_render_metas_page');
    add_submenu_page('billetera_catalogo', 'Líneas y Marcas', 'Líneas y Marcas', $cap, 'billetera_lineas_admin', 'billetera_render_lineas_page');
    add_submenu_page('billetera_catalogo', 'Configuración', 'Configuración', $cap, 'billetera_config_admin', 'billetera_render_config_page');
    // OCULTAMIENTO PERMANENTE para administrador_hyundai: estas 2 páginas usan
    // $cap (manage_billetera_catalog) pero se quitan del menú y se bloquea el
    // acceso directo por URL (ver billetera_catalog_ocultar_hyundai y los
    // wp_die en billetera_render_resumen_page / billetera_render_bonus_page).
    add_submenu_page('billetera_catalogo', 'Resumen semanal', 'Resumen semanal', $cap, 'billetera_resumen_admin', 'billetera_render_resumen_page');
    add_submenu_page('billetera_catalogo', 'Reglas de bonificación', 'Reglas de bonificación', $cap, 'billetera_bonus_admin', 'billetera_render_bonus_page');

    // Reporte de comisiones: administrator y administrador_hyundai
    if (defined('BILLETERA_REPORTS_CAP')) {
        add_submenu_page('billetera_catalogo', 'Reporte de comisiones', 'Reporte de comisiones', BILLETERA_REPORTS_CAP, 'billetera_reporte_comisiones', 'billetera_render_reports_page');
    }
}

// ---------------------------------------------------------------------------
// OCULTAMIENTO para el rol administrador_hyundai: quita Resumen semanal y
// Reglas de bonificación del menú (aunque tenga el cap del catálogo) y
// bloquea el acceso directo por URL. Para revertir, borrar este bloque.
// ---------------------------------------------------------------------------
add_action('admin_menu', 'billetera_catalog_ocultar_hyundai', 999);
function billetera_catalog_is_hyundai_admin() {
    $user = wp_get_current_user();
    if (!$user || empty($user->roles)) {
        return false;
    }
    return in_array('administrador_hyundai', (array) $user->roles, true)
        && !in_array('administrator', (array) $user->roles, true);
}
function billetera_catalog_ocultar_hyundai() {
    if (!billetera_catalog_is_hyundai_admin()) {
        return;
    }
    remove_submenu_page('billetera_catalogo', 'billetera_resumen_admin');
    remove_submenu_page('billetera_catalogo', 'billetera_bonus_admin');
}

// ---------------------------------------------------------------------------
// Assets
// ---------------------------------------------------------------------------
add_action('admin_enqueue_scripts', 'billetera_catalog_admin_assets');
function billetera_catalog_admin_assets() {
    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    $pages = ['billetera_catalogo', 'billetera_subcategorias', 'billetera_comisiones_admin', 'billetera_metas_admin', 'billetera_lineas_admin', 'billetera_config_admin', 'billetera_resumen_admin', 'billetera_reporte_comisiones', 'billetera_bonus_admin'];
    if (!in_array($page, $pages, true)) {
        return;
    }

    $base = defined('BILLETERA_CATALOG_URL') ? BILLETERA_CATALOG_URL : plugin_dir_url(dirname(__DIR__) . '/billetera-catalog.php');
    wp_enqueue_style('billetera-catalog-admin', $base . 'assets/admin.css', [], '1.2');
    wp_enqueue_script('billetera-catalog-admin', $base . 'assets/admin.js', [], '1.1', true);
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
    $filtro_sub  = isset($_GET['subcategoria_id']) ? intval($_GET['subcategoria_id']) : 0;
    $filtro_cat  = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : 0;
    $filtro_dist = isset($_GET['distribuidor_id']) ? sanitize_text_field(wp_unslash($_GET['distribuidor_id'])) : '';

    $wheres = [];
    $params = [];
    if ($filtro_sub) {
        $wheres[] = 'co.subcategoria_id = %d';
        $params[] = $filtro_sub;
    }
    if ($filtro_cat) {
        $wheres[] = 's.categoria_id = %d';
        $params[] = $filtro_cat;
    }
    if ($filtro_dist === 'gen') {
        $wheres[] = 'co.distribuidor_id IS NULL';
    } elseif ($filtro_dist !== '' && intval($filtro_dist) > 0) {
        $wheres[] = 'co.distribuidor_id = %d';
        $params[] = intval($filtro_dist);
    }
    $where_sql = $wheres ? ('WHERE ' . implode(' AND ', $wheres)) : '';

    $from_sql = "
        FROM $table co
        JOIN {$wpdb->prefix}billetera_subcategorias s ON s.id = co.subcategoria_id
        JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
        LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
        $where_sql
    ";

    $count_sql = "SELECT COUNT(*) $from_sql";
    $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, ...$params)) : $wpdb->get_var($count_sql));

    $per_page    = 30;
    $total_pages = max(1, (int) ceil($total / $per_page));
    $paged       = max(1, min($total_pages, isset($_GET['paged']) ? intval($_GET['paged']) : 1));
    $offset      = ($paged - 1) * $per_page;

    $data_sql = "SELECT co.*, s.nombre AS subcategoria, c.nombre AS categoria, l.nombre AS linea
        $from_sql
        ORDER BY c.linea_codigo, c.orden_display, s.orden_display, s.nombre, co.rol
        LIMIT %d OFFSET %d";
    $data_params = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($data_sql, ...$data_params));

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
                <div class="bc-filter__group">
                    <label class="bc-label">Categoría</label>
                    <select name="categoria_id" class="bc-select">
                        <option value="0">— Todas —</option>
                        <?php echo billetera_bc_categorias_options($filtro_cat); ?>
                    </select>
                </div>
                <div class="bc-filter__group">
                    <label class="bc-label">Subcategoría</label>
                    <select name="subcategoria_id" class="bc-select">
                        <option value="0">— Todas —</option>
                        <?php echo billetera_bc_subcategorias_options($filtro_sub); ?>
                    </select>
                </div>
                <div class="bc-filter__group">
                    <label class="bc-label">Concesionaria</label>
                    <select name="distribuidor_id" class="bc-select">
                        <option value="" <?php selected($filtro_dist, ''); ?>>— Todas —</option>
                        <option value="gen" <?php selected($filtro_dist, 'gen'); ?>>— Genérica (TODOS) —</option>
                        <?php foreach ($dealers as $d): ?>
                            <option value="<?php echo intval($d->ID); ?>" <?php selected($filtro_dist, (string) $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="button">Filtrar</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_comisiones_admin')); ?>">Limpiar</a>
            </form>
        </div>
        <p class="bc-desc" style="margin:0 0 8px;">
            Mostrando <?php echo $total ? ($offset + 1) : 0; ?>–<?php echo min($offset + $per_page, $total); ?> de <?php echo $total; ?> comisión(es).
        </p>
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
        <?php if ($total_pages > 1): ?>
            <div class="bc-pagination" style="margin-top:14px;text-align:right;">
                <?php
                $pag_base = add_query_arg([
                    'page'            => 'billetera_comisiones_admin',
                    'categoria_id'    => $filtro_cat,
                    'subcategoria_id' => $filtro_sub,
                    'distribuidor_id' => $filtro_dist,
                ], admin_url('admin.php'));
                echo paginate_links([
                    'base'      => $pag_base . '%_%',
                    'format'    => '&paged=%#%',
                    'current'   => $paged,
                    'total'     => $total_pages,
                    'prev_text' => '«',
                    'next_text' => '»',
                    'type'      => 'plain',
                ]);
                ?>
            </div>
        <?php endif; ?>
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

    billetera_bc_open('Metas potenciales', 'Meta mensual por sucursal, línea y tipo. Es la base para el avance y la proyección del asesor.');
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

// ===========================================================================
// REGLAS DE BONIFICACIÓN
// ===========================================================================

/**
 * Helpers admin v2 (solo backoffice): mismo alcance, solapamiento y prueba.
 * Mismo alcance = tupla idéntica (categoría, marca, línea, subcategoría, tienda).
 * Solapan = en cada eje son iguales o uno es "Todas" (alcances que se cruzan).
 * La vigencia se compara como rango [desde|−∞, hasta|+∞].
 */
function billetera_bonus_admin_mismo_alcance($a, $b) {
    $a = billetera_bonus_normalize_regla($a);
    $b = billetera_bonus_normalize_regla($b);
    $ka = [strtolower(trim((string) ($a['scope_slug'] ?? ''))), intval($a['scope_marca_id'] ?? 0), strtolower(trim((string) ($a['scope_linea_codigo'] ?? ''))), intval($a['scope_subcategoria_id'] ?? 0), intval($a['scope_dealer_id'] ?? 0)];
    $kb = [strtolower(trim((string) ($b['scope_slug'] ?? ''))), intval($b['scope_marca_id'] ?? 0), strtolower(trim((string) ($b['scope_linea_codigo'] ?? ''))), intval($b['scope_subcategoria_id'] ?? 0), intval($b['scope_dealer_id'] ?? 0)];
    return $ka === $kb;
}

function billetera_bonus_admin_eje_cruza($x, $y) {
    $x = is_string($x) ? strtolower(trim($x)) : intval($x);
    $y = is_string($y) ? strtolower(trim($y)) : intval($y);
    $wild_x = ($x === '' || $x === 0);
    $wild_y = ($y === '' || $y === 0);
    if ($wild_x || $wild_y) {
        return true;
    }
    return $x === $y;
}

function billetera_bonus_admin_solapan($a, $b) {
    $a = billetera_bonus_normalize_regla($a);
    $b = billetera_bonus_normalize_regla($b);
    // Categoría exacta legacy también cruza con su slug.
    $slug_a = strtolower(trim((string) ($a['scope_slug'] ?? ($a['slug'] ?? ''))));
    $slug_b = strtolower(trim((string) ($b['scope_slug'] ?? ($b['slug'] ?? ''))));
    if (!billetera_bonus_admin_eje_cruza($slug_a, $slug_b)) {
        // Excepción: regla exacta de categoría (scope_categoria_id) vs slug.
        $ca = intval($a['scope_categoria_id'] ?? 0);
        $cb = intval($b['scope_categoria_id'] ?? 0);
        if (!($ca > 0 && $cb > 0 && $ca === $cb)) {
            return false;
        }
    }
    foreach ([['scope_marca_id', 'scope_marca_id'], ['scope_linea_codigo', 'scope_linea_codigo'], ['scope_subcategoria_id', 'scope_subcategoria_id'], ['scope_dealer_id', 'scope_dealer_id']] as $par) {
        if (!billetera_bonus_admin_eje_cruza($a[$par[0]] ?? 0, $b[$par[1]] ?? 0)) {
            return false;
        }
    }
    return true;
}

function billetera_bonus_admin_vigencia_solapa($a, $b) {
    $da = trim((string) ($a['banner_desde'] ?? ''));
    $ha = trim((string) ($a['banner_hasta'] ?? ''));
    $db = trim((string) ($b['banner_desde'] ?? ''));
    $hb = trim((string) ($b['banner_hasta'] ?? ''));
    $desde = max($da !== '' ? $da : '0000-00-00', $db !== '' ? $db : '0000-00-00');
    $hasta = min($ha !== '' ? $ha : '9999-99-99', $hb !== '' ? $hb : '9999-99-99');
    return $desde <= $hasta;
}

/**
 * Prueba de alcance: cuántas ventas y asesores del mes caen en el ámbito.
 * No guarda nada. Devuelve ['ventas'=>int, 'asesores'=>int, 'solapan'=>[descripciones]].
 */
function billetera_bonus_admin_dry_run($regla) {
    global $wpdb;
    $regla = billetera_bonus_normalize_regla($regla);
    $ventas = $wpdb->prefix . 'billetera_ventas';
    $sub    = $wpdb->prefix . 'billetera_subcategorias';
    $cat    = $wpdb->prefix . 'billetera_categorias';
    $now = billetera_stats_local_now();
    $desde = $now->format('Y-m-01 00:00:00');
    $hasta = (clone $now)->modify('first day of next month')->format('Y-m-01 00:00:00');
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
    list($cond_sql, $cond_params) = billetera_bonus_cond_v2($regla);
    $params = array_merge([$desde, $hasta], $cond_params);
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS ventas, COUNT(DISTINCT v.usuario_id) AS asesores
         FROM $ventas v
         JOIN $sub s ON s.id = v.subcategoria_id
         JOIN $cat c ON c.id = s.categoria_id
         WHERE v.creado_en >= %s AND v.creado_en < %s AND ($cond_sql)",
        ...$params
    ));
    $solapan = [];
    foreach (billetera_bonus_reglas() as $r) {
        if (($r['id'] ?? '') === ($regla['id'] ?? '')) {
            continue;
        }
        if (empty($r['activo'])) {
            continue;
        }
        if (!billetera_bonus_admin_solapan($r, $regla)) {
            continue;
        }
        if (!billetera_bonus_admin_vigencia_solapa($r, $regla)) {
            continue;
        }
        $solapan[] = '“' . ($r['nombre'] ?? $r['slug'] ?? $r['id']) . '” (' . billetera_bonus_regla_describe($r) . ')';
    }
    return ['ventas' => intval($row->ventas ?? 0), 'asesores' => intval($row->asesores ?? 0), 'solapan' => $solapan];
}
function billetera_render_bonus_page() {
    // OCULTAMIENTO TEMPORAL para administrador_hyundai (bloquea acceso directo por URL).
    if ((function_exists('billetera_catalog_is_hyundai_admin') && billetera_catalog_is_hyundai_admin()) || !current_user_can(BILLETERA_CATALOG_CAP)) {
        wp_die('Sin permiso');
    }

    global $wpdb;
    $cat_table = $wpdb->prefix . 'billetera_categorias';
    $msg = '';
    $err = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save_rule' || $action === 'dry_run') {
            // ---- Alcance v2: 5 selects independientes ('' / 0 = Todas) ----
            $scope_slug = strtolower(trim(sanitize_text_field(wp_unslash($_POST['scope_categoria_slug'] ?? ''))));
            $scope_marca_id = intval($_POST['scope_marca_id'] ?? 0);
            $scope_linea_codigo = strtolower(trim(sanitize_text_field(wp_unslash($_POST['scope_linea_codigo'] ?? ''))));
            $scope_sub_id = intval($_POST['scope_subcategoria_id'] ?? 0);
            $scope_dealer_id = intval($_POST['scope_dealer_id'] ?? 0);
            $prioridad = max(0, min(100, intval($_POST['prioridad_manual'] ?? 0)));

            if ($scope_slug === '' && $scope_sub_id <= 0) {
                $err = 'Elige al menos una categoría o una subcategoría. Una regla para “todo” sería demasiado amplia.';
            } else {
                // Si se eligió subcategoría, ella manda: derivamos su categoría/línea/marca
                // para que la regla nunca quede en un cruce imposible.
                $subcat_cat_id = 0;
                $nota_sub = '';
                if ($scope_sub_id > 0) {
                    $subrow = $wpdb->get_row($wpdb->prepare(
                        "SELECT s.id, s.nombre AS sub_nombre, c.id AS cat_id, c.nombre AS cat_nombre, c.slug AS cat_slug, c.linea_codigo AS linea_codigo, l.marca_id AS marca_id
                         FROM {$wpdb->prefix}billetera_subcategorias s
                         JOIN $cat_table c ON c.id = s.categoria_id
                         LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
                         WHERE s.id = %d",
                        $scope_sub_id
                    ));
                    if (!$subrow) {
                        $err = 'La subcategoría elegida no existe.';
                    } else {
                        $scope_slug = strtolower(trim((string) $subrow->cat_slug));
                        $scope_linea_codigo = strtolower(trim((string) ($subrow->linea_codigo ?? '')));
                        $scope_marca_id = intval($subrow->marca_id ?? 0);
                        $subcat_cat_id = intval($subrow->cat_id);
                        $nota_sub = ' (alcance ajustado a la subcategoría “' . $subrow->sub_nombre . '”)';
                    }
                }

                if ($err === '') {
                    // Nombre visible: el de la categoría (o de la subcategoría si es específica).
                    if ($scope_sub_id > 0 && isset($subrow)) {
                        $nombre_vis = (string) $subrow->cat_nombre;
                    } else {
                        $nombre_vis = (string) $wpdb->get_var($wpdb->prepare("SELECT nombre FROM $cat_table WHERE LOWER(slug) = %s OR LOWER(nombre) = %s ORDER BY id ASC LIMIT 1", $scope_slug, $scope_slug));
                        if ($nombre_vis === '') {
                            $nombre_vis = $scope_slug;
                        }
                    }
                    // categoria_id legacy (representativa, solo informativa: el motor v2 usa scope_*).
                    if ($subcat_cat_id > 0) {
                        $legacy_cat_id = $subcat_cat_id;
                    } elseif ($scope_linea_codigo !== '' && $scope_slug !== '') {
                        $legacy_cat_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $cat_table WHERE LOWER(slug) = %s AND linea_codigo = %s ORDER BY id ASC LIMIT 1", $scope_slug, $scope_linea_codigo)));
                    } else {
                        $legacy_cat_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $cat_table WHERE LOWER(slug) = %s OR LOWER(nombre) = %s ORDER BY id ASC LIMIT 1", $scope_slug, $scope_slug)));
                    }

                    $rid = sanitize_text_field(wp_unslash($_POST['rule_id'] ?? ''));
                    $nueva = [
                        'id'                     => $rid !== '' ? $rid : ('r_' . wp_generate_password(8, false, false)),
                        // Legacy (informativo; el motor v2 usa scope_*).
                        'categoria_id'           => $legacy_cat_id,
                        'slug'                   => $scope_slug,
                        'nombre'                 => $nombre_vis,
                        'linea_codigo'           => $scope_linea_codigo,
                        'ambito'                 => ($scope_sub_id > 0) ? 'linea' : 'categoria',
                        // Alcance v2.
                        'scope_categoria_id'     => 0,
                        'scope_slug'             => $scope_slug,
                        'scope_marca_id'         => $scope_marca_id,
                        'scope_linea_codigo'     => $scope_linea_codigo,
                        'scope_subcategoria_id'  => $scope_sub_id,
                        'scope_dealer_id'        => $scope_dealer_id,
                        'prioridad_manual'       => $prioridad,
                        'activo'                 => isset($_POST['activo']) ? 1 : 0,
                        'umbral'                 => max(1, intval($_POST['umbral'] ?? 6)),
                        'multiplicador'          => max(0, floatval($_POST['multiplicador'] ?? 2)),
                        'aplica_jefe'            => isset($_POST['aplica_jefe']) ? 1 : 0,
                        'excepcion_dealer_id'    => intval($_POST['excepcion_dealer_id'] ?? 0),
                        'excepcion_usd'          => max(0, floatval($_POST['excepcion_usd'] ?? 0)),
                        'excepcion_jefe_usd'     => max(0, floatval($_POST['excepcion_jefe_usd'] ?? 0)),
                        'excepcion_moneda'       => (isset($_POST['excepcion_moneda']) && $_POST['excepcion_moneda'] === 'PEN') ? 'PEN' : 'USD',
                        'excepcion_modo'         => (isset($_POST['excepcion_modo']) && $_POST['excepcion_modo'] === 'sumar') ? 'sumar' : 'reemplazar',
                        'excepcion_desde_umbral' => isset($_POST['excepcion_desde_umbral']) ? 1 : 0,
                        'banner_activo'          => isset($_POST['banner_activo']) ? 1 : 0,
                        'banner_orden'           => max(0, intval($_POST['banner_orden'] ?? 0)),
                        'banner_etiqueta'        => sanitize_text_field(wp_unslash($_POST['banner_etiqueta'] ?? 'CAMPAÑA · EVENTO')),
                        'banner_titulo'          => sanitize_text_field(wp_unslash($_POST['banner_titulo'] ?? '')),
                        'banner_bajada'          => sanitize_text_field(wp_unslash($_POST['banner_bajada'] ?? '')),
                        'banner_desde'           => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['banner_desde'] ?? '') ? $_POST['banner_desde'] : '',
                        'banner_hasta'           => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['banner_hasta'] ?? '') ? $_POST['banner_hasta'] : '',
                        'banner_cta'             => sanitize_text_field(wp_unslash($_POST['banner_cta'] ?? 'VER BASES')),
                        'banner_alcance'         => 'global',
                        'banner_tiendas'         => [],
                    ];

                    if ($action === 'dry_run') {
                        $dry = billetera_bonus_admin_dry_run($nueva);
                        $msg = 'Prueba de alcance' . $nota_sub . ': este mes hay ' . $dry['ventas'] . ' ventas de ' . $dry['asesores'] . ' asesores dentro de este alcance (' . billetera_bonus_regla_describe($nueva) . ').'
                            . ($dry['solapan'] ? ' Convive con: ' . implode('; ', $dry['solapan']) . '. La más específica tendrá prioridad.' : ' No se solapa con otra regla vigente.');
                    } else {
                        $reglas = billetera_bonus_reglas();
                        $exacto = false;
                        $avisos = [];
                        foreach ($reglas as $r) {
                            if (($r['id'] ?? '') === $nueva['id']) {
                                continue;
                            }
                            if (empty($r['activo']) && empty($nueva['activo'])) {
                                continue;
                            }
                            if (!billetera_bonus_admin_solapan($r, $nueva)) {
                                continue;
                            }
                            if (!billetera_bonus_admin_vigencia_solapa($r, $nueva)) {
                                continue;
                            }
                            if (billetera_bonus_admin_mismo_alcance($r, $nueva)) {
                                $exacto = true;
                                break;
                            }
                            $avisos[] = '“' . ($r['nombre'] ?? $r['slug'] ?? $r['id']) . '” (' . billetera_bonus_regla_describe($r) . ')';
                        }

                        if ($exacto) {
                            $err = 'Ya existe una regla idéntica para ese alcance y vigencia. Edítala en la tabla de abajo en vez de crear otra.';
                        } else {
                            $found = false;
                            foreach ($reglas as $i => $r) {
                                if ($r['id'] === $nueva['id']) {
                                    $reglas[$i] = $nueva;
                                    $found = true;
                                    break;
                                }
                            }
                            if (!$found) {
                                $reglas[] = $nueva;
                            }
                            update_option('billetera_bonus_reglas', $reglas, false);
                            $msg = ($found ? 'Regla actualizada' : 'Regla creada') . $nota_sub . '. Aplica a: ' . billetera_bonus_regla_describe($nueva) . '.';
                            if ($avisos) {
                                $msg .= ' Aviso: convive con ' . implode('; ', $avisos) . '. No se duplican: la regla más específica (subcategoría > tienda > categoría > línea > marca) tendrá prioridad en cada venta.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'delete_rule') {
            $rid    = sanitize_text_field(wp_unslash($_POST['rule_id'] ?? ''));
            $reglas = billetera_bonus_reglas();
            $nuevas = [];
            foreach ($reglas as $r) {
                if ($r['id'] !== $rid) {
                    $nuevas[] = $r;
                }
            }
            update_option('billetera_bonus_reglas', $nuevas, false);
            $msg = 'Regla eliminada.';
        }
    }

    $reglas  = billetera_bonus_reglas();
    $dealers = billetera_bc_dealers();

    $lineas_all = billetera_bc_lineas();
    // Marcas para el filtro (0 = todas).
    $marcas_all = $wpdb->get_results("SELECT id, nombre, slug FROM {$wpdb->prefix}billetera_marcas ORDER BY nombre");
    // Categorías con slug y línea (para el select de categoría por slug).
    $cats_all = $wpdb->get_results(
        "SELECT c.id, c.nombre, c.slug, c.linea_codigo, l.nombre AS linea_nombre, l.marca_id AS marca_id
         FROM {$wpdb->prefix}billetera_categorias c
         LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
         ORDER BY c.linea_codigo, c.orden_display, c.nombre"
    );
    // Subcategorías con su categoría y línea (para cascada).
    $subs_all = $wpdb->get_results(
        "SELECT s.id, s.nombre AS sub_nombre, c.id AS cat_id, c.slug AS cat_slug, c.linea_codigo AS linea_codigo, l.marca_id AS marca_id
         FROM {$wpdb->prefix}billetera_subcategorias s
         JOIN {$wpdb->prefix}billetera_categorias c ON c.id = s.categoria_id
         LEFT JOIN {$wpdb->prefix}billetera_lineas l ON l.codigo = c.linea_codigo
         ORDER BY c.linea_codigo, s.nombre"
    );

    $edit = null;
    if (isset($_GET['edit'])) {
        $rid = sanitize_text_field(wp_unslash($_GET['edit']));
        foreach ($reglas as $r) {
            if ($r['id'] === $rid) {
                $edit = billetera_bonus_normalize_regla($r);
                break;
            }
        }
    }

    $sel_slug   = strtolower(trim((string) ($edit['scope_slug'] ?? ($edit['slug'] ?? ''))));
    $sel_marca  = intval($edit['scope_marca_id'] ?? 0);
    $sel_linea  = strtolower(trim((string) ($edit['scope_linea_codigo'] ?? '')));
    $sel_sub    = intval($edit['scope_subcategoria_id'] ?? 0);
    $sel_dealer = intval($edit['scope_dealer_id'] ?? 0);

    billetera_bc_open('Reglas de bonificación', 'Elige a qué ventas aplica la regla (categoría, marca, línea, subcategoría y tienda), luego el bono y la vigencia. Si dos reglas coinciden en una venta, gana la más específica: subcategoría > tienda > categoría > línea > marca.');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <h2 class="bc-card__title"><?php echo $edit ? 'Editar regla' : 'Nueva regla'; ?></h2>
        <form method="post" class="bc-grid" id="bc-bonus-form">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" id="bc-bonus-action" value="save_rule">
            <input type="hidden" name="rule_id" value="<?php echo esc_attr($edit['id'] ?? ''); ?>">

            <div class="bc-field bc-field--full">
                <div class="nav-tab-wrapper bc-bonus-tabs__nav">
                    <button type="button" class="nav-tab nav-tab-active" data-bc-tab="bc-tab-base">1 · ¿A qué ventas aplica?</button>
                    <button type="button" class="nav-tab" data-bc-tab="bc-tab-bono">2 · ¿Qué bono se da?</button>
                    <button type="button" class="nav-tab" data-bc-tab="bc-tab-excepcion">3 · Excepción (opcional)</button>
                    <button type="button" class="nav-tab" data-bc-tab="bc-tab-banner">4 · Vigencia y banner</button>
                </div>
            </div>

            <div class="bc-bonus-tabs__panel bc-field bc-field--full" id="bc-tab-base">
            <div class="bc-grid">
            <div class="bc-field bc-field--full" style="background:#f0f6ff;border:1px solid #cfe0fb;border-radius:8px;padding:10px 12px;">
                <strong>Aplica a:</strong> <span id="bc-bonus-resumen"><?php echo esc_html(billetera_bonus_regla_describe($edit ?: ['scope_slug' => $sel_slug, 'scope_marca_id' => $sel_marca, 'scope_linea_codigo' => $sel_linea, 'scope_subcategoria_id' => $sel_sub, 'scope_dealer_id' => $sel_dealer, 'nombre' => $sel_slug])); ?></span>
                <p class="bc-desc" style="margin:6px 0 0;">Lee este resumen antes de guardar. “Todas” significa sin filtro en ese nivel. Si una venta cae en dos reglas, <strong>no se duplican</strong>: gana la más específica.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Categoría (o subcategoría abajo)</label>
                <select name="scope_categoria_slug" id="bc-scope-categoria" class="bc-select">
                    <option value="">— Todas las categorías —</option>
                    <?php
                    $seen_slug = [];
                    foreach ($cats_all as $c) {
                        $sl = strtolower(trim((string) $c->slug));
                        if ($sl === '' || isset($seen_slug[$sl])) {
                            continue;
                        }
                        $seen_slug[$sl] = true;
                        echo '<option value="' . esc_attr($sl) . '"' . selected($sel_slug, $sl, false) . '>' . esc_html($c->nombre) . '</option>';
                    }
                    ?>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">Ej. Prepagados o Kit360 (vale para todas sus líneas salvo que filtres abajo).</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">Marca</label>
                <select name="scope_marca_id" id="bc-scope-marca" class="bc-select">
                    <option value="0">— Todas las marcas —</option>
                    <?php foreach ($marcas_all as $m): ?>
                        <option value="<?php echo intval($m->id); ?>" <?php selected($sel_marca, intval($m->id)); ?>><?php echo esc_html($m->nombre); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="bc-field">
                <label class="bc-label">Línea</label>
                <select name="scope_linea_codigo" id="bc-scope-linea" class="bc-select">
                    <option value="">— Todas las líneas —</option>
                    <?php foreach ($lineas_all as $l): ?>
                        <option value="<?php echo esc_attr(strtolower((string) $l->codigo)); ?>" data-marca="<?php echo intval($l->marca_id); ?>" <?php selected($sel_linea, strtolower((string) $l->codigo)); ?>><?php echo esc_html($l->nombre); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">Filtra la categoría elegida a una sola línea (ej. solo Hyundai).</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">Subcategoría</label>
                <select name="scope_subcategoria_id" id="bc-scope-sub" class="bc-select">
                    <option value="0">— Todas las subcategorías —</option>
                    <?php foreach ($subs_all as $s): ?>
                        <option value="<?php echo intval($s->id); ?>" data-cat="<?php echo esc_attr(strtolower(trim((string) $s->cat_slug))); ?>" data-linea="<?php echo esc_attr(strtolower(trim((string) ($s->linea_codigo ?? '')))); ?>" data-marca="<?php echo intval($s->marca_id ?? 0); ?>" <?php selected($sel_sub, intval($s->id)); ?>><?php echo esc_html($s->sub_nombre . ' (' . $s->cat_slug . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">La opción más específica: si la eliges, tiene prioridad sobre las reglas generales.</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">Tienda / concesionaria (alcance)</label>
                <select name="scope_dealer_id" id="bc-scope-dealer" class="bc-select">
                    <option value="0">— Todas las tiendas —</option>
                    <?php foreach ($dealers as $d): ?>
                        <option value="<?php echo intval($d->ID); ?>" <?php selected($sel_dealer, intval($d->ID)); ?>><?php echo esc_html($d->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">Vacío = vale para todas. Si eliges una, la regla solo aplica a ventas de esa tienda.</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">Prioridad manual (0–100)</label>
                <input type="number" min="0" max="100" name="prioridad_manual" class="bc-input" value="<?php echo intval($edit['prioridad_manual'] ?? 0); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Solo para desempatar dos reglas igual de específicas. Normalmente déjalo en 0.</p>
            </div>
            </div><!-- /bc-tab-base -->
            </div>

            <div class="bc-bonus-tabs__panel bc-field bc-field--full" id="bc-tab-bono" hidden>
            <div class="bc-grid">
            <div class="bc-field bc-field--full">
                <h3 style="margin:10px 0 0;">¿Qué bono se da?</h3>
                <p class="bc-desc" style="margin:4px 0 0;">Desde cuántas ventas del mes se activa y por cuánto se multiplica la comisión.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">¿Desde cuántas ventas del mes? *</label>
                <input type="number" min="1" name="umbral" class="bc-input" value="<?php echo intval($edit['umbral'] ?? 6); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Al llegar a esta venta se activa el bono y se ajustan las anteriores del mes.</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">¿Por cuánto se multiplica?</label>
                <input type="number" step="0.1" min="0" name="multiplicador" class="bc-input" value="<?php echo esc_attr($edit['multiplicador'] ?? 2); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Ej. 2 = la comisión se duplica.</p>
            </div>

            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="aplica_jefe" value="1" <?php checked($edit['aplica_jefe'] ?? 1, 1); ?>> También aplica al jefe de venta</label>
            </div>

            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="activo" value="1" <?php checked($edit['activo'] ?? 1, 1); ?>> Regla activa</label>
            </div>
            </div><!-- /bc-tab-bono -->
            </div>

            <div class="bc-bonus-tabs__panel bc-field bc-field--full" id="bc-tab-excepcion" hidden>
            <div class="bc-grid">
            <div class="bc-field bc-field--full">
                <h3 style="margin:10px 0 0;">Excepción por concesionaria (opcional)</h3>
                <p class="bc-desc" style="margin:4px 0 0;">Para la concesionaria elegida, en vez de multiplicar, la comisión pasa a un monto fijo en USD por unidad.</p>
            </div>

            <div class="bc-field">
                <label class="bc-label">Concesionaria</label>
                <select name="excepcion_dealer_id" class="bc-select">
                    <option value="0">— Ninguna —</option>
                    <?php foreach ($dealers as $d): ?>
                        <option value="<?php echo intval($d->ID); ?>" <?php selected($edit['excepcion_dealer_id'] ?? 0, $d->ID); ?>><?php echo esc_html($d->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Moneda del monto fijo</label>
                <select name="excepcion_moneda" class="bc-select">
                    <option value="USD" <?php selected($edit['excepcion_moneda'] ?? 'USD', 'USD'); ?>>Dólares (US$)</option>
                    <option value="PEN" <?php selected($edit['excepcion_moneda'] ?? 'USD', 'PEN'); ?>>Soles (S/)</option>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">Si eliges dólares, se convierte con el tipo de cambio del catálogo.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Monto fijo por unidad (asesor)</label>
                <input type="number" step="0.01" min="0" name="excepcion_usd" class="bc-input" value="<?php echo esc_attr($edit['excepcion_usd'] ?? 0); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Monto fijo por unidad (jefe)</label>
                <input type="number" step="0.01" min="0" name="excepcion_jefe_usd" class="bc-input" value="<?php echo esc_attr($edit['excepcion_jefe_usd'] ?? 0); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Modo de la excepción</label>
                <select name="excepcion_modo" class="bc-select">
                    <option value="reemplazar" <?php selected($edit['excepcion_modo'] ?? 'reemplazar', 'reemplazar'); ?>>Reemplazar la comisión</option>
                    <option value="sumar" <?php selected($edit['excepcion_modo'] ?? 'reemplazar', 'sumar'); ?>>Sumar a la comisión</option>
                </select>
                <p class="bc-desc" style="margin:6px 0 0;">Reemplazar: la comisión pasa a ser el monto fijo. Sumar: se suma el monto fijo a la comisión base (la excepción anula el multiplicador).</p>
            </div>
            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="excepcion_desde_umbral" value="1" <?php checked($edit['excepcion_desde_umbral'] ?? 1, 1); ?>> La excepción aplica desde el umbral (si no, desde la 1.ª venta)</label>
            </div>
            </div><!-- /bc-tab-excepcion -->
            </div>

            <div class="bc-bonus-tabs__panel bc-field bc-field--full" id="bc-tab-banner" hidden>
            <div class="bc-grid">
            <div class="bc-field bc-field--full">
                <h3 style="margin:10px 0 0;">Vigencia y banner de campaña (opcional)</h3>
                <p class="bc-desc" style="margin:4px 0 0;">La vigencia apaga el bono y el banner. El bono solo aplica a ventas con fecha dentro del rango y el banner solo se muestra mientras esté vigente.</p>
            </div>

            <div class="bc-field bc-field--check">
                <label><input type="checkbox" name="banner_activo" value="1" <?php checked($edit['banner_activo'] ?? 0, 1); ?>> Mostrar banner de esta campaña</label>
            </div>
            <div class="bc-field">
                <label class="bc-label">Orden</label>
                <input type="number" min="0" name="banner_orden" class="bc-input" value="<?php echo intval($edit['banner_orden'] ?? 0); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Si hay varias vigentes, se ordenan de menor a mayor.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Etiqueta</label>
                <input type="text" name="banner_etiqueta" class="bc-input" maxlength="30" value="<?php echo esc_attr($edit['banner_etiqueta'] ?? 'CAMPAÑA · EVENTO'); ?>">
            </div>
            <div class="bc-field bc-field--full">
                <label class="bc-label">Título *</label>
                <input type="text" name="banner_titulo" class="bc-input" maxlength="90" value="<?php echo esc_attr($edit['banner_titulo'] ?? ''); ?>" placeholder="Octubre Prepagados: duplica tu comisión en planes 5+ mpps">
            </div>
            <div class="bc-field bc-field--full">
                <label class="bc-label">Bajada</label>
                <input type="text" name="banner_bajada" class="bc-input" maxlength="140" value="<?php echo esc_attr($edit['banner_bajada'] ?? ''); ?>" placeholder="Cada venta de 5+ mpps suma S/ 350 en lugar de S/ 175. Válido en tu tienda.">
            </div>
            <div class="bc-field">
                <label class="bc-label">Vigente desde (bono + banner)</label>
                <input type="date" name="banner_desde" class="bc-input" value="<?php echo esc_attr($edit['banner_desde'] ?? ''); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Vacío = sin inicio. Ventas anteriores no bonifican.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Vigente hasta (bono + banner)</label>
                <input type="date" name="banner_hasta" class="bc-input" value="<?php echo esc_attr($edit['banner_hasta'] ?? ''); ?>">
                <p class="bc-desc" style="margin:6px 0 0;">Vacío = sin fin. Después de esta fecha el bono se desactiva.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Texto del botón</label>
                <input type="text" name="banner_cta" class="bc-input" maxlength="20" value="<?php echo esc_attr($edit['banner_cta'] ?? 'VER BASES'); ?>">
            </div>
            </div><!-- /bc-tab-banner -->
            </div>

            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary" onclick="document.getElementById('bc-bonus-action').value='save_rule';"><?php echo $edit ? 'Guardar cambios' : 'Crear regla'; ?></button>
                <button type="submit" class="button" onclick="document.getElementById('bc-bonus-action').value='dry_run';" title="Calcula cuántas ventas de este mes caen en este alcance, sin guardar">Probar alcance</button>
                <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=billetera_bonus_admin')); ?>">Cancelar</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <h2 class="bc-card__title">Reglas configuradas</h2>
        <p class="bc-desc">Si una venta coincide con varias reglas, <strong>gana solo la más específica</strong> (subcategoría &gt; tienda &gt; categoría &gt; línea &gt; marca; luego prioridad manual). Nunca se suman ni se duplican.</p>
        <?php if (!$reglas): ?>
            <p>Aún no hay reglas. Crea la primera arriba.</p>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Aplica a</th>
                    <th style="width:80px;">Desde</th>
                    <th style="width:90px;">Bono</th>
                    <th style="width:90px;">Este mes</th>
                    <th>Excepción</th>
                    <th>Banner / vigencia</th>
                    <th style="width:80px;">Estado</th>
                    <th style="width:150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reglas as $r):
                    $r = billetera_bonus_normalize_regla($r);
                    $describe = billetera_bonus_regla_describe($r);
                    $especifica = (intval($r['scope_subcategoria_id'] ?? 0) > 0 || intval($r['scope_dealer_id'] ?? 0) > 0) ? ' <span style="background:#e6f4ea;color:#146c43;border-radius:10px;padding:1px 8px;font-size:11px;">Específica</span>' : '';
                    $prio = intval($r['prioridad_manual'] ?? 0) > 0 ? ' <span title="Prioridad manual para desempatar">P' . intval($r['prioridad_manual']) . '</span>' : '';
                    $dry_row = billetera_bonus_admin_dry_run($r);

                    if (!empty($r['excepcion_dealer_id'])) {
                        $sim    = (($r['excepcion_moneda'] ?? 'USD') === 'PEN') ? 'S/ ' : 'US$';
                        $excep  = get_the_title($r['excepcion_dealer_id']) ?: ('#' . intval($r['excepcion_dealer_id']));
                        $excep .= ' · ' . ((($r['excepcion_modo'] ?? 'reemplazar') === 'sumar') ? 'suma' : 'reemplaza');
                        $excep .= ' ' . $sim . number_format((float) $r['excepcion_usd'], 2) . ' / jefe ' . $sim . number_format((float) $r['excepcion_jefe_usd'], 2);
                        $excep .= !empty($r['excepcion_desde_umbral']) ? ' (desde umbral)' : ' (desde 1.ª)';
                    } else {
                        $excep = '—';
                    }

                    $mult_txt = rtrim(rtrim(number_format((float) $r['multiplicador'], 2, '.', ''), '0'), '.');

                    if (!empty($r['banner_activo']) && trim((string) ($r['banner_titulo'] ?? '')) !== '') {
                        $vig = trim(trim((string) ($r['banner_desde'] ?? ''), '-') . ' → ' . trim((string) ($r['banner_hasta'] ?? ''), '-'), ' →');
                        $hoy_txt = current_time('Y-m-d');
                        $vig_estado = '';
                        if (!empty($r['banner_desde']) && $hoy_txt < $r['banner_desde']) {
                            $vig_estado = ' · programado';
                        } elseif (!empty($r['banner_hasta']) && $hoy_txt > $r['banner_hasta']) {
                            $vig_estado = ' · vencido';
                        }
                        $banner_txt = 'Sí' . ($vig !== '' ? ' · ' . $vig : '') . $vig_estado;
                        $banner_title = $vig !== '' ? $vig : 'Sin vigencia limitada';
                    } else {
                        $banner_txt = '—';
                        $banner_title = '';
                    }
                ?>
                    <tr>
                        <td><strong><?php echo esc_html($r['nombre'] ?? $r['slug']); ?></strong><?php echo $especifica; ?><?php echo $prio; ?><br><span style="color:#50575e;font-size:12px;"><?php echo esc_html($describe); ?></span></td>
                        <td><?php echo intval($r['umbral']); ?> ventas</td>
                        <td>×<?php echo esc_html($mult_txt); ?><?php echo !empty($r['aplica_jefe']) ? ' (+jefe)' : ''; ?></td>
                        <td title="Ventas de este mes dentro del alcance"><?php echo intval($dry_row['ventas']); ?> ventas</td>
                        <td><?php echo esc_html($excep); ?></td>
                        <td><span title="<?php echo esc_attr($banner_title); ?>"><?php echo esc_html($banner_txt); ?></span></td>
                        <td><?php echo !empty($r['activo']) ? '<span style="color:#146C43;">Activa</span>' : '<span style="color:#b3352a;">Inactiva</span>'; ?></td>
                        <td>
                            <a class="bc-link" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_bonus_admin', 'edit' => $r['id']], admin_url('admin.php'))); ?>">Editar</a>
                            <span class="bc-sep">|</span>
                            <form method="post" class="bc-inline" onsubmit="return confirm('¿Eliminar esta regla?');">
                                <?php wp_nonce_field('billetera_catalog'); ?>
                                <input type="hidden" name="bc_action" value="delete_rule">
                                <input type="hidden" name="rule_id" value="<?php echo esc_attr($r['id']); ?>">
                                <button type="submit" class="bc-link bc-danger">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
    billetera_bc_close();
}
