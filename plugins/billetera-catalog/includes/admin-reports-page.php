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
// Capability: administrator y administrador_hyundai
// ---------------------------------------------------------------------------
add_action('admin_init', 'billetera_reports_ensure_cap');
function billetera_reports_ensure_cap() {
    $role = get_role('administrator');
    if ($role && !$role->has_cap(BILLETERA_REPORTS_CAP)) {
        $role->add_cap(BILLETERA_REPORTS_CAP);
    }

    // El reporte también lo ve el administrador_hyundai
    $hu = get_role('administrador_hyundai');
    if ($hu && !$hu->has_cap(BILLETERA_REPORTS_CAP)) {
        $hu->add_cap(BILLETERA_REPORTS_CAP);
    }
}

function billetera_reports_can_access() {
    if (!current_user_can(BILLETERA_REPORTS_CAP)) {
        return false;
    }
    $user = wp_get_current_user();
    return (bool) array_intersect(['administrator', 'administrador_hyundai'], (array) $user->roles);
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
// Detalle por venta (fecha, sucursal, identificador y adjuntos)
// ---------------------------------------------------------------------------
function billetera_reports_detalle_query($desde, $hasta, $tipo = '', $paged = 1, $per_page = 50) {
    global $wpdb;

    $v    = $wpdb->prefix . 'billetera_ventas';
    $sub  = $wpdb->prefix . 'billetera_subcategorias';
    $cat  = $wpdb->prefix . 'billetera_categorias';
    $lin  = $wpdb->prefix . 'billetera_lineas';
    $adj  = $wpdb->prefix . 'billetera_adjuntos';
    $um   = $wpdb->usermeta;

    // Filtro por tipo del vendedor (Venta / Post Venta; '' = todos).
    $tipo_sql = '';
    $params = [$desde . ' 00:00:00', $hasta . ' 23:59:59'];
    if ($tipo !== '') {
        $tipo_sql = "AND EXISTS (
            SELECT 1 FROM $um t2
            WHERE t2.user_id = v.usuario_id AND t2.meta_key = 'billetera_tipo'
              AND (CASE WHEN t2.meta_value = '' THEN 'Venta' ELSE t2.meta_value END) = %s
        )";
        $params[] = $tipo;
    }

    $offset = max(0, (intval($paged) - 1) * intval($per_page));

    // $per_page <= 0 = sin paginación (para la descarga CSV).
    $limit_sql = ($per_page > 0) ? " LIMIT %d OFFSET %d" : "";

    $sql = "
        SELECT v.id, v.creado_en, v.id_type, v.id_value,
               v.monto_comision_sol, v.bonus_multiplier, v.cantidad, v.usuario_id,
               u.display_name AS asesor,
               tt.meta_value AS tipo_vendedor,
               tm.meta_value AS tienda_id,
               s.nombre AS subcategoria, c.nombre AS categoria, l.nombre AS linea
        FROM $v v
        LEFT JOIN {$wpdb->users} u ON u.ID = v.usuario_id
        LEFT JOIN $um tt ON tt.user_id = v.usuario_id AND tt.meta_key = 'billetera_tipo'
        LEFT JOIN $um tm ON tm.user_id = v.usuario_id AND tm.meta_key = '_tienda_asociada'
        LEFT JOIN $sub s ON s.id = v.subcategoria_id
        LEFT JOIN $cat c ON c.id = s.categoria_id
        LEFT JOIN $lin l ON l.codigo = COALESCE(v.linea_codigo, (SELECT b.linea_codigo FROM {$wpdb->prefix}billetera_subcategoria_lineas b WHERE b.subcategoria_id = s.id ORDER BY b.linea_codigo ASC LIMIT 1))
        WHERE v.creado_en >= %s AND v.creado_en <= %s
        $tipo_sql
        ORDER BY v.creado_en ASC, v.id ASC
        $limit_sql
    ";

    if ($per_page > 0) {
        $params[] = intval($per_page);
        $params[] = $offset;
    }
    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

    // Conteo total para la paginación (mismos filtros, sin LIMIT).
    $count_sql = "
        SELECT COUNT(*) FROM $v v
        WHERE v.creado_en >= %s AND v.creado_en <= %s
        $tipo_sql
    ";
    $count_params = array_slice($params, 0, ($tipo !== '') ? 3 : 2);
    $total = intval($wpdb->get_var($wpdb->prepare($count_sql, ...$count_params)));

    // Totales del rango completo (para la fila Total): ventas, unidades y comisión.
    $tot_sql = "
        SELECT COUNT(*) AS ventas, COALESCE(SUM(v.cantidad), 0) AS unidades,
               COALESCE(SUM(v.monto_comision_sol * v.bonus_multiplier), 0) AS com
        FROM $v v
        WHERE v.creado_en >= %s AND v.creado_en <= %s
        $tipo_sql
    ";
    $tot_row = $wpdb->get_row($wpdb->prepare($tot_sql, ...$count_params));

    // Adjuntos: mapeados por grupo de venta (usuario + fecha + tipo + valor),
    // porque el archivo se vincula a la PRIMERA venta de esa venta multi-item.
    $adj_map = [];
    if ($rows) {
        $in_ids = implode(',', array_map('intval', wp_list_pluck($rows, 'id')));
        $adj_rows = $wpdb->get_results("
            SELECT a.nombre, a.archivo, gv.usuario_id, gv.creado_en, gv.id_type, gv.id_value
            FROM $adj a
            INNER JOIN $v gv ON gv.id = a.venta_id
        ");
        foreach ((array) $adj_rows as $a) {
            $key = implode('|', [intval($a->usuario_id), (string) $a->creado_en, (string) $a->id_type, (string) $a->id_value]);
            $adj_map[$key][] = $a;
        }
    }

    return [
        'rows'  => $rows,
        'total' => $total,
        'totales' => [
            'ventas'   => intval($tot_row->ventas ?? 0),
            'unidades' => intval($tot_row->unidades ?? 0),
            'com'      => round(floatval($tot_row->com ?? 0), 2),
        ],
        'adjuntos' => $adj_map,
        'jefes_map' => billetera_reports_jefes_map($rows),
    ];
}

/**
 * Mapa tienda_id => [nombres de jefes de venta asociados].
 *
 * Mismo criterio que el registro de comisiones espejo
 * (billetera_register_jefe_comision): se listan los jefes cuyo
 * billetera_tipo coincide con el tipo del vendedor (sin meta o vacío
 * cuenta como "Venta"); si ninguno coincide, se listan todos los jefes.
 *
 * @return array<int, array> Cada entrada: ['todos' => [id => nombre], 'tipo' => [id => tipo]]
 */
function billetera_reports_jefes_map($rows) {
    $map = [];
    if (!$rows) {
        return $map;
    }

    $tienda_ids = [];
    foreach ($rows as $r) {
        $tid = intval($r->tienda_id);
        if ($tid) {
            $tienda_ids[$tid] = true;
        }
    }

    foreach (array_keys($tienda_ids) as $tid) {
        $jefes = get_post_meta($tid, '_jefes_venta_asociados', true);
        if (!is_array($jefes)) {
            $jefes = $jefes ? [$jefes] : [];
        }

        $todos = [];
        $tipos = [];
        foreach ($jefes as $jid) {
            $jid = intval($jid);
            if (!$jid) {
                continue;
            }
            $u = get_userdata($jid);
            if (!$u) {
                continue;
            }
            $todos[$jid] = $u->display_name;
            $jt = get_user_meta($jid, 'billetera_tipo', true);
            $tipos[$jid] = ($jt === '' || $jt === null) ? 'Venta' : trim((string) $jt);
        }

        if ($todos) {
            $map[$tid] = ['todos' => $todos, 'tipo' => $tipos];
        }
    }

    return $map;
}

/**
 * Texto del/de los jefe(s) de sucursal para una fila del reporte.
 */
function billetera_reports_jefe_texto($jefes_map, $tienda_id, $tipo_vendedor) {
    $entry = $jefes_map[intval($tienda_id)] ?? null;
    if (!$entry) {
        return '';
    }

    $want = ($tipo_vendedor === '' || $tipo_vendedor === null) ? 'Venta' : trim((string) $tipo_vendedor);
    $sel = array_keys(array_filter($entry['tipo'], function ($t) use ($want) {
        return $t === $want;
    }));
    if (!$sel) {
        $sel = array_keys($entry['todos']);
    }

    return implode(', ', array_map(function ($jid) use ($entry) {
        return $entry['todos'][$jid];
    }, $sel));
}

function billetera_reports_adjunto_url($archivo) {
    $up = wp_upload_dir();
    return trailingslashit($up['baseurl']) . 'billetera-adjuntos/' . ltrim($archivo, '/');
}

// ---------------------------------------------------------------------------
// Descarga CSV (admin_post) — detalle por venta
// ---------------------------------------------------------------------------
add_action('admin_post_billetera_reports_export', 'billetera_reports_export');
function billetera_reports_export() {
    if (!billetera_reports_can_access()) {
        wp_die('Sin permiso');
    }
    check_admin_referer('billetera_reports_export');

    list($desde, $hasta) = billetera_reports_parse_range();
    $tipo = billetera_reports_tipo_filter();
    $det  = billetera_reports_detalle_query($desde, $hasta, $tipo, 1, 0); // sin límite

    $filename = 'reporte_comisiones_' . $desde . '_a_' . $hasta . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    // Directiva de separador: Excel la respeta sin importar el idioma/región
    // (evita que todas las columnas se vean amontonadas en una sola celda).
    // Sin BOM UTF-8: al usar la línea sep=;, Excel entra por su parser CSV
    // antiguo y asume ANSI/Windows-1252 — por eso el contenido se convierte a
    // esa página de códigos (así los acentos Ñ/á/é se ven correctos).
    fwrite($out, "sep=;\r\n");

    // UTF-8 → Windows-1252 (transliteración; fallback al texto original).
    $cp = function ($s) {
        $s = (string) $s;
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return ($out === false || $out === null) ? $s : $out;
    };

    fputcsv($out, array_map($cp, ['Fecha', 'Sucursal', 'Asesor', 'Jefe de sucursal', 'Tipo', 'Categoría', 'Subcategoría', 'Tipo identificador', 'Identificador', 'Unidades', 'Comisión (S/)', 'Adjuntos']), ';', '"');

    $fmt = function ($n) {
        return number_format(floatval($n), 2, ',', '');
    };
    $tienda_names = [];
    $tot_und = 0;
    $tot_com = 0.0;

    foreach ($det['rows'] as $d) {
        $tid = intval($d->tienda_id);
        if (!isset($tienda_names[$tid])) {
            $tienda_names[$tid] = $tid ? html_entity_decode(get_the_title($tid), ENT_QUOTES, 'UTF-8') : '';
        }
        $tipo_v = trim((string) $d->tipo_vendedor);
        $tipo_v = ($tipo_v === '') ? 'Venta' : $tipo_v;

        // Adjuntos de la venta (por grupo: usuario + fecha + tipo + valor).
        $key = implode('|', [intval($d->usuario_id), (string) $d->creado_en, (string) $d->id_type, (string) $d->id_value]);
        $adj_txt = '';
        if (!empty($det['adjuntos'][$key])) {
            $parts = [];
            foreach ($det['adjuntos'][$key] as $a) {
                $parts[] = $a->nombre . ' (' . billetera_reports_adjunto_url($a->archivo) . ')';
            }
            $adj_txt = implode(' | ', $parts);
        }

        $com_fila = floatval($d->monto_comision_sol) * floatval($d->bonus_multiplier);
        $tot_und += intval($d->cantidad);
        $tot_com += $com_fila;

        $jefe_txt = billetera_reports_jefe_texto($det['jefes_map'], $tid, $tipo_v);

        fputcsv($out, array_map($cp, [
            mysql2date('d/m/Y', $d->creado_en),
            $tienda_names[$tid] ?: '',
            $d->asesor ?: '',
            $jefe_txt,
            $tipo_v,
            $d->categoria ?: '',
            $d->subcategoria ?: '',
            $d->id_type ?: '',
            $d->id_value ?: '',
            intval($d->cantidad),
            $fmt($com_fila),
            $adj_txt,
        ]), ';', '"');
    }

    fputcsv($out, array_map($cp, ['TOTAL', '', '', '', '', '', '', '', '', $tot_und, $fmt($tot_com), '']), ';', '"');

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

    $paged    = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 50;
    $det = billetera_reports_detalle_query($desde, $hasta, $tipo, $paged, $per_page);
    $det_pages = max(1, (int) ceil($det['total'] / $per_page));
    $det_desde = (($paged - 1) * $per_page) + 1;
    $det_hasta = min($paged * $per_page, $det['total']);
    $tienda_names = [];

    $fmt_sol = function ($n) {
        return 'S/ ' . number_format(floatval($n), 2, '.', ',');
    };

    billetera_bc_open('Reporte de comisiones', 'Detalle por venta: fecha, sucursal, asesor, identificador, comisión y adjuntos. Solo administradores (Hyundai o administrador normal) pueden acceder.');
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
            <h2 style="margin:0;">Reporte <?php echo esc_html($desde); ?> → <?php echo esc_html($hasta); ?></h2>
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span style="color:#6b7280;font-size:12.5px;">Mostrando <?php echo $det['total'] ? $det_desde : 0; ?>–<?php echo $det_hasta; ?> de <?php echo $det['total']; ?> venta(s) · página <?php echo $paged; ?> de <?php echo $det_pages; ?></span>
                <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="billetera_reports_export">
                    <input type="hidden" name="desde" value="<?php echo esc_attr($desde); ?>">
                    <input type="hidden" name="hasta" value="<?php echo esc_attr($hasta); ?>">
                    <input type="hidden" name="tipo" value="<?php echo esc_attr($tipo); ?>">
                    <?php wp_nonce_field('billetera_reports_export'); ?>
                    <button type="submit" class="button button-primary">Descargar CSV</button>
                </form>
            </div>
        </div>

        <?php if (!$det['rows']): ?>
            <p style="margin-top:16px;color:#5b6b80;">No hay ventas en el periodo seleccionado.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Sucursal</th>
                        <th>Asesor</th>
                        <th>Jefe de sucursal</th>
                        <th>Tipo</th>
                        <th>Categoría</th>
                        <th>Subcategoría</th>
                        <th>Tipo ident.</th>
                        <th>Identificador</th>
                        <th style="text-align:center;">Unidades</th>
                        <th style="text-align:right;">Comisión</th>
                        <th>Adjuntos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($det['rows'] as $d):
                        $tid_d = intval($d->tienda_id);
                        if (!isset($tienda_names[$tid_d])) {
                            $tienda_names[$tid_d] = $tid_d ? html_entity_decode(get_the_title($tid_d), ENT_QUOTES, 'UTF-8') : '';
                        }
                        $tipo_v = trim((string) $d->tipo_vendedor);
                        $tipo_v = ($tipo_v === '') ? 'Venta' : $tipo_v;
                        $key_adj = implode('|', [intval($d->usuario_id), (string) $d->creado_en, (string) $d->id_type, (string) $d->id_value]);
                        $adjuntos = $det['adjuntos'][$key_adj] ?? [];
                    ?>
                        <tr>
                            <td><?php echo esc_html(mysql2date('d/m/Y', $d->creado_en)); ?></td>
                            <td><?php echo esc_html($tienda_names[$tid_d] ?: '—'); ?></td>
                            <td><?php echo esc_html($d->asesor ?: '—'); ?></td>
                            <td><?php echo esc_html(billetera_reports_jefe_texto($det['jefes_map'], $tid_d, $tipo_v) ?: '—'); ?></td>
                            <td><?php echo esc_html($tipo_v); ?></td>
                            <td><?php echo esc_html($d->categoria ?: '—'); ?></td>
                            <td><?php echo esc_html($d->subcategoria ?: '—'); ?> <span style="color:#9aa5b1;">· <?php echo esc_html($d->linea); ?></span></td>
                            <td><?php echo esc_html(ucfirst((string) $d->id_type) ?: '—'); ?></td>
                            <td><code><?php echo esc_html((string) $d->id_value ?: '—'); ?></code></td>
                            <td style="text-align:center;"><?php echo intval($d->cantidad); ?></td>
                            <td style="text-align:right;"><?php echo esc_html($fmt_sol(floatval($d->monto_comision_sol) * floatval($d->bonus_multiplier))); ?></td>
                            <td>
                                <?php if ($adjuntos): ?>
                                    <?php foreach ($adjuntos as $a): ?>
                                        <a href="<?php echo esc_url(billetera_reports_adjunto_url($a->archivo)); ?>" target="_blank" rel="noopener" class="bc-link" title="<?php echo esc_attr($a->nombre); ?>">📎 <?php echo esc_html(wp_html_excerpt($a->nombre, 28, '…')); ?></a>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#c3ccd8;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="9">Total del periodo (<?php echo intval($det['totales']['ventas']); ?> ventas)</th>
                        <th style="text-align:center;"><?php echo intval($det['totales']['unidades']); ?></th>
                        <th style="text-align:right;"><?php echo esc_html($fmt_sol($det['totales']['com'])); ?></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>

            <?php if ($det_pages > 1): ?>
                <div style="margin-top:14px;text-align:right;">
                    <?php for ($p = 1; $p <= $det_pages; $p++): ?>
                        <?php if ($p === $paged) { echo '<strong style="padding:4px 8px;">' . $p . '</strong>'; } else { ?>
                            <a class="button" style="padding:2px 9px;" href="<?php echo esc_url(add_query_arg(['page' => 'billetera_reporte_comisiones', 'desde' => $desde, 'hasta' => $hasta, 'tipo' => $tipo, 'paged' => $p], admin_url('admin.php'))); ?>"><?php echo $p; ?></a>
                        <?php } ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
    billetera_bc_close();
}