<?php
/**
 * Reporte de comisiones por asesor y subcategoría (descarga CSV).
 *
 * Solo accesible para el rol "administrador_hyundai" mediante la capability
 * dedicada BILLETERA_REPORTS_CAP.
 *
 * Flujo:
 *   1) Se elige un periodo (desde/hasta) y opcionalmente el tipo (Venta o
 *      Post Venta, según el meta del asesor).
 *   2) Se previsualiza en pantalla el resumen por asesor + subcategoría.
 *   3) Se descarga el mismo resumen en CSV (compatible con Excel en español).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BILLETERA_REPORTS_CAP')) {
    define('BILLETERA_REPORTS_CAP', 'view_billetera_reports');
}

// ---------------------------------------------------------------------------
// Capability: solo el rol administrador_hyundai
// ---------------------------------------------------------------------------
add_action('admin_init', 'billetera_reports_ensure_cap');
function billetera_reports_ensure_cap() {
    $role = get_role('administrador_hyundai');
    if ($role && !$role->has_cap(BILLETERA_REPORTS_CAP)) {
        $role->add_cap(BILLETERA_REPORTS_CAP);
    }
}

function billetera_reports_can_access() {
    if (!current_user_can(BILLETERA_REPORTS_CAP)) {
        return false;
    }
    $user = wp_get_current_user();
    return in_array('administrador_hyundai', (array) $user->roles, true);
}

// ---------------------------------------------------------------------------
// Rango de fechas
// ---------------------------------------------------------------------------
function billetera_reports_parse_range() {
    $hoy   = current_time('Y-m-d');
    $desde = isset($_REQUEST['desde']) ? sanitize_text_field(wp_unslash($_REQUEST['desde'])) : '';
    $hasta = isset($_REQUEST['hasta']) ? sanitize_text_field(wp_unslash($_REQUEST['hasta'])) : '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $desde = substr($hoy, 0, 8) . '01';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $hasta = $hoy;
    }
    if ($desde > $hasta) {
        $tmp   = $desde;
        $desde = $hasta;
        $hasta = $tmp;
    }

    return [$desde, $hasta];
}

function billetera_reports_tipo_filter() {
    $tipo = isset($_REQUEST['tipo']) ? sanitize_text_field(wp_unslash($_REQUEST['tipo'])) : '';
    return in_array($tipo, ['Venta', 'Post Venta'], true) ? $tipo : '';
}

// ---------------------------------------------------------------------------
// Consulta agregada: asesor x subcategoría en el periodo
// ---------------------------------------------------------------------------
function billetera_reports_query($desde, $hasta, $tipo = '') {
    global $wpdb;

    $v   = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';
    $lin = $wpdb->prefix . 'billetera_lineas';

    $sql = "
        SELECT v.usuario_id,
               v.subcategoria_id,
               COALESCE(l.nombre, '—') AS linea,
               COALESCE(c.nombre, '—') AS categoria,
               COALESCE(s.nombre, '—') AS subcategoria,
               COUNT(*) AS ventas,
               SUM(v.cantidad) AS unidades,
               SUM(v.monto_comision_sol * v.bonus_multiplier) AS comision
        FROM $v v
        LEFT JOIN $sub s ON s.id = v.subcategoria_id
        LEFT JOIN $cat c ON c.id = s.categoria_id
        LEFT JOIN $lin l ON l.codigo = c.linea_codigo
        WHERE v.creado_en >= %s AND v.creado_en <= %s
          AND (v.monto_comision_sol * v.bonus_multiplier) > 0
        GROUP BY v.usuario_id, v.subcategoria_id
        ORDER BY v.usuario_id ASC, comision DESC
    ";

    $rows = $wpdb->get_results($wpdb->prepare(
        $sql,
        $desde . ' 00:00:00',
        $hasta . ' 23:59:59'
    ));

    $out = [];
    foreach ((array) $rows as $r) {
        $user = get_userdata(intval($r->usuario_id));
        if (!$user) {
            continue;
        }

        $tipo_asesor = trim((string) get_user_meta($user->ID, 'billetera_tipo', true));
        if ($tipo !== '' && $tipo_asesor !== $tipo) {
            continue;
        }

        $out[] = [
            'usuario_id'  => intval($user->ID),
            'asesor'      => $user->display_name,
            'tipo'        => $tipo_asesor !== '' ? $tipo_asesor : '—',
            'linea'       => (string) $r->linea,
            'categoria'   => (string) $r->categoria,
            'subcategoria'=> (string) $r->subcategoria,
            'ventas'      => intval($r->ventas),
            'unidades'    => intval($r->unidades),
            'comision'    => round(floatval($r->comision), 2),
        ];
    }

    return $out;
}

function billetera_reports_totals($rows) {
    $tot = ['ventas' => 0, 'unidades' => 0, 'comision' => 0.0];
    foreach ($rows as $r) {
        $tot['ventas']   += intval($r['ventas']);
        $tot['unidades'] += intval($r['unidades']);
        $tot['comision'] += floatval($r['comision']);
    }
    $tot['comision'] = round($tot['comision'], 2);
    return $tot;
}

// ---------------------------------------------------------------------------
// Descarga CSV (admin_post)
// ---------------------------------------------------------------------------
add_action('admin_post_billetera_reports_export', 'billetera_reports_export');
function billetera_reports_export() {
    if (!billetera_reports_can_access()) {
        wp_die('Sin permiso');
    }
    check_admin_referer('billetera_reports_export');

    list($desde, $hasta) = billetera_reports_parse_range();
    $tipo = billetera_reports_tipo_filter();
    $rows = billetera_reports_query($desde, $hasta, $tipo);
    $tot  = billetera_reports_totals($rows);

    $filename = 'reporte_comisiones_' . $desde . '_a_' . $hasta . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // BOM para que Excel reconozca UTF-8.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Asesor', 'Tipo', 'Línea', 'Categoría', 'Subcategoría', 'Ventas', 'Unidades', 'Comisión (S/)'], ';', '"');

    $fmt = function ($n) {
        return number_format(floatval($n), 2, ',', '');
    };

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['asesor'],
            $r['tipo'],
            $r['linea'],
            $r['categoria'],
            $r['subcategoria'],
            $r['ventas'],
            $r['unidades'],
            $fmt($r['comision']),
        ], ';', '"');
    }

    fputcsv($out, ['TOTAL', '', '', '', '', $tot['ventas'], $tot['unidades'], $fmt($tot['comision'])], ';', '"');

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Pantalla admin
// ---------------------------------------------------------------------------
function billetera_render_reports_page() {
    if (!billetera_reports_can_access()) {
        wp_die('Sin permiso');
    }

    list($desde, $hasta) = billetera_reports_parse_range();
    $tipo = billetera_reports_tipo_filter();
    $rows = billetera_reports_query($desde, $hasta, $tipo);
    $tot  = billetera_reports_totals($rows);

    $fmt_sol = function ($n) {
        return 'S/ ' . number_format(floatval($n), 2, '.', ',');
    };

    billetera_bc_open('Reporte de comisiones', 'Descarga el resumen de comisiones por asesor y subcategoría. Solo el Administrador Hyundai puede acceder.');
    billetera_bc_notices('', '');
    ?>
    <div class="bc-card">
        <form method="get" class="bc-grid">
            <input type="hidden" name="page" value="billetera_reporte_comisiones">
            <div class="bc-field">
                <label class="bc-label">Desde</label>
                <input type="date" name="desde" class="bc-input" value="<?php echo esc_attr($desde); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Hasta</label>
                <input type="date" name="hasta" class="bc-input" value="<?php echo esc_attr($hasta); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Tipo</label>
                <select name="tipo" class="bc-select">
                    <option value="" <?php selected($tipo, ''); ?>>Todos</option>
                    <option value="Venta" <?php selected($tipo, 'Venta'); ?>>Venta</option>
                    <option value="Post Venta" <?php selected($tipo, 'Post Venta'); ?>>Post Venta</option>
                </select>
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary">Ver reporte</button>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <h2 style="margin:0;">Resumen <?php echo esc_html($desde); ?> → <?php echo esc_html($hasta); ?></h2>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="billetera_reports_export">
                <input type="hidden" name="desde" value="<?php echo esc_attr($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo esc_attr($hasta); ?>">
                <input type="hidden" name="tipo" value="<?php echo esc_attr($tipo); ?>">
                <?php wp_nonce_field('billetera_reports_export'); ?>
                <button type="submit" class="button button-primary">Descargar CSV</button>
            </form>
        </div>

        <?php if (!$rows): ?>
            <p style="margin-top:16px;color:#5b6b80;">No hay comisiones registradas en el periodo seleccionado.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>Asesor</th>
                        <th>Tipo</th>
                        <th>Línea</th>
                        <th>Categoría</th>
                        <th>Subcategoría</th>
                        <th style="text-align:center;">Ventas</th>
                        <th style="text-align:center;">Unidades</th>
                        <th style="text-align:right;">Comisión</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['asesor']); ?></td>
                            <td><?php echo esc_html($r['tipo']); ?></td>
                            <td><?php echo esc_html($r['linea']); ?></td>
                            <td><?php echo esc_html($r['categoria']); ?></td>
                            <td><?php echo esc_html($r['subcategoria']); ?></td>
                            <td style="text-align:center;"><?php echo intval($r['ventas']); ?></td>
                            <td style="text-align:center;"><?php echo intval($r['unidades']); ?></td>
                            <td style="text-align:right;"><?php echo esc_html($fmt_sol($r['comision'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5">Total</th>
                        <th style="text-align:center;"><?php echo intval($tot['ventas']); ?></th>
                        <th style="text-align:center;"><?php echo intval($tot['unidades']); ?></th>
                        <th style="text-align:right;"><?php echo esc_html($fmt_sol($tot['comision'])); ?></th>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
    <?php
    billetera_bc_close();
}
