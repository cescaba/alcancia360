<?php
/**
 * Resumen semanal de comisiones por correo.
 *
 * Flujo:
 *   1) Un cron semanal (domingo 00:00) encola un correo por cada asesor.
 *   2) Un worker por lotes envía a ritmo controlado (60/h por defecto) usando
 *      FluentSMTP + Brevo como transporte.
 *   3) Pantalla "Resumen semanal" para ajustes, preview y pruebas.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// Opciones / configuración
// ============================================================================

function billetera_summary_is_active() {
    return (bool) get_option('billetera_summary_activo', 0);
}

function billetera_summary_por_hora() {
    $v = (int) get_option('billetera_summary_por_hora', 60);
    return $v > 0 ? $v : 60;
}

/**
 * Correos a enviar por cada corrida del worker.
 * 60/h con 12 corridas/hora => 5 por corrida. El tope por hora móvil del
 * worker garantiza que nunca se supere el ritmo configurado.
 */
function billetera_summary_per_run() {
    $por_hora = billetera_summary_por_hora();
    $por_corrida = (int) ceil($por_hora / 12);
    return max(1, min($por_corrida, 200));
}

/**
 * Máximo de correos por día (0 = sin límite). Sirve para respetar el plan de
 * Brevo (p. ej. 300/día en el gratuito): si la cola no termina hoy, sigue mañana.
 */
function billetera_summary_por_dia() {
    return max(0, (int) get_option('billetera_summary_por_dia', 0));
}

/**
 * Día de la semana del envío automático (0 = domingo .. 6 = sábado).
 */
function billetera_summary_seed_dow() {
    $v = (int) get_option('billetera_summary_seed_dow', 0);
    return ($v >= 0 && $v <= 6) ? $v : 0;
}

/**
 * Hora del envío automático (0..23).
 */
function billetera_summary_seed_hour() {
    $v = (int) get_option('billetera_summary_seed_hour', 0);
    return ($v >= 0 && $v <= 23) ? $v : 0;
}

function billetera_summary_dow_nombre($dow) {
    $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $dow = (int) $dow;
    return $dias[$dow] ?? $dias[0];
}

function billetera_summary_test_email() {
    $email = get_option('billetera_summary_test_email', '');
    return is_email($email) ? $email : '';
}

function billetera_summary_from_email() {
    $from = get_option('billetera_summary_from_email', '');
    if (is_email($from)) {
        return $from;
    }
    return get_option('admin_email');
}

function billetera_summary_from_name() {
    $name = trim((string) get_option('billetera_summary_from_name', ''));
    return $name !== '' ? $name : get_bloginfo('name');
}

// ============================================================================
// Tabla de cola
// ============================================================================

function billetera_summary_queue_table() {
    global $wpdb;
    return $wpdb->prefix . 'billetera_email_queue';
}

function billetera_summary_create_queue_table() {
    global $wpdb;
    $table           = billetera_summary_queue_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        email varchar(190) NOT NULL,
        week_start datetime NOT NULL,
        week_end datetime NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        error text NULL,
        created_at datetime NOT NULL,
        sent_at datetime NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY user_id (user_id),
        KEY week_start (week_start)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ============================================================================
// Cron
// ============================================================================

add_filter('cron_schedules', 'billetera_summary_cron_schedules');
function billetera_summary_cron_schedules($schedules) {
    // 4 min (menos que el cron del servidor de 5 min) para que cada corrida
    // del cron del servidor siempre encuentre el evento vencido y no se
    // "desincronice" (evita que el envío caiga a la mitad).
    if (!isset($schedules['billetera_summary_4min'])) {
        $schedules['billetera_summary_4min'] = [
            'interval' => 4 * MINUTE_IN_SECONDS,
            'display'  => 'Cada 4 minutos (Billetera)',
        ];
    }
    return $schedules;
}

add_action('billetera_summary_seed_hook', 'billetera_summary_cron_seed');
function billetera_summary_cron_seed() {
    if (!billetera_summary_is_active()) {
        return;
    }
    billetera_summary_seed_queue(false);
}

add_action('billetera_summary_worker_hook', 'billetera_summary_process_batch');

/**
 * Programa los eventos si no están agendados (no requiere reactivar el plugin).
 */
add_action('init', 'billetera_summary_maybe_schedule');
function billetera_summary_maybe_schedule() {
    if (!wp_next_scheduled('billetera_summary_worker_hook')) {
        wp_schedule_event(time() + 60, 'billetera_summary_4min', 'billetera_summary_worker_hook');
    }
    if (!wp_next_scheduled('billetera_summary_seed_hook')) {
        wp_schedule_event(billetera_summary_next_seed_ts(), 'weekly', 'billetera_summary_seed_hook');
    }
}

function billetera_summary_unschedule() {
    wp_clear_scheduled_hook('billetera_summary_worker_hook');
    wp_clear_scheduled_hook('billetera_summary_seed_hook');
}

/**
 * Reagenda el encolado semanal según el día/hora configurados.
 */
function billetera_summary_reschedule_seed() {
    wp_clear_scheduled_hook('billetera_summary_seed_hook');
    wp_schedule_event(billetera_summary_next_seed_ts(), 'weekly', 'billetera_summary_seed_hook');
}

/**
 * Timestamp del próximo día/hora de envío configurados (hora local del sitio).
 */
function billetera_summary_next_seed_ts() {
    $now         = billetera_stats_local_now();
    $dow         = (int) $now->format('w'); // 0 (dom) .. 6 (sáb)
    $target_dow  = billetera_summary_seed_dow();
    $target_hour = billetera_summary_seed_hour();

    $days = ($target_dow - $dow + 7) % 7;
    $candidate = (clone $now)->modify("+$days days")->setTime($target_hour, 0, 0);

    if ($candidate <= $now) {
        $candidate->modify('+7 days');
    }

    return $candidate->getTimestamp();
}

// ============================================================================
// Rango de la última semana (lunes 00:00 → domingo 00:00 excl.)
// ============================================================================

function billetera_summary_last_week_range() {
    $now = billetera_stats_local_now();
    $dow = (int) $now->format('N');

    $monday = (clone $now)->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);

    $start = (clone $monday)->modify('-7 days');
    $end   = $monday;

    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}

function billetera_summary_week_label($week_start, $week_end) {
    $tz    = wp_timezone();
    $start = new DateTime($week_start, $tz);
    $last  = (new DateTime($week_end, $tz))->modify('-1 day');

    return date_i18n('j M', $start->getTimestamp()) . ' – ' . date_i18n('j M Y', $last->getTimestamp());
}

// ============================================================================
// Datos de la semana
// ============================================================================

function billetera_summary_fmt_sol($n) {
    return 'S/ ' . number_format((float) $n, 2, '.', ',');
}

function billetera_summary_stats_usuario($user_id, $desde, $hasta) {
    global $wpdb;
    $v   = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $cat = $wpdb->prefix . 'billetera_categorias';
    $lin = $wpdb->prefix . 'billetera_lineas';

    $row = $wpdb->get_row($wpdb->prepare("
        SELECT SUM(monto_comision_sol * bonus_multiplier) AS total,
               COUNT(*) AS ventas,
               SUM(cantidad) AS unidades
        FROM $v
        WHERE usuario_id = %d
          AND creado_en >= %s AND creado_en < %s
          AND (monto_comision_sol * bonus_multiplier) > 0
    ", $user_id, $desde, $hasta));

    $total    = round(floatval($row->total ?? 0), 2);
    $ventas   = intval($row->ventas ?? 0);
    $unidades = intval($row->unidades ?? 0);

    $por_linea = $wpdb->get_results($wpdb->prepare("
        SELECT l.nombre AS label,
               SUM(v.monto_comision_sol * v.bonus_multiplier) AS total,
               COUNT(*) AS ventas
        FROM $v v
        JOIN $sub s ON s.id = v.subcategoria_id
        JOIN $cat c ON c.id = s.categoria_id
        JOIN $lin l ON l.codigo = c.linea_codigo
        WHERE v.usuario_id = %d
          AND v.creado_en >= %s AND v.creado_en < %s
          AND (v.monto_comision_sol * v.bonus_multiplier) > 0
        GROUP BY l.codigo
        ORDER BY total DESC
    ", $user_id, $desde, $hasta));

    $por_categoria = $wpdb->get_results($wpdb->prepare("
        SELECT c.nombre AS label,
               SUM(v.monto_comision_sol * v.bonus_multiplier) AS total,
               COUNT(*) AS ventas
        FROM $v v
        JOIN $sub s ON s.id = v.subcategoria_id
        JOIN $cat c ON c.id = s.categoria_id
        WHERE v.usuario_id = %d
          AND v.creado_en >= %s AND v.creado_en < %s
          AND (v.monto_comision_sol * v.bonus_multiplier) > 0
        GROUP BY c.id
        ORDER BY total DESC
        LIMIT 10
    ", $user_id, $desde, $hasta));

    $map = function ($rows) {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'label'  => $r->label,
                'total'  => round(floatval($r->total), 2),
                'ventas' => intval($r->ventas),
            ];
        }
        return $out;
    };

    return [
        'total'         => $total,
        'ventas'        => $ventas,
        'unidades'      => $unidades,
        'ticket'        => $ventas > 0 ? round($total / $ventas, 2) : 0,
        'por_linea'     => $map($por_linea),
        'por_categoria' => $map($por_categoria),
    ];
}

// ============================================================================
// Render del correo — Diseño v2 (600px, tablas inline, compatible email)
// + variante cierre de mes (hook para segundo diseño)
// ============================================================================

function billetera_summary_logo_url() {
    $url = get_template_directory_uri() . '/assets/img/logo-gildemeister.png';
    return apply_filters('billetera_summary_logo_url', $url);
}

function billetera_summary_logo_simple_url() {
    $url = get_template_directory_uri() . '/assets/img/logo.png';
    return apply_filters('billetera_summary_logo_simple_url', $url);
}

function billetera_summary_header_brand_url() {
    $url = get_template_directory_uri() . '/assets/img/header-gildemeister.png';
    $check = get_template_directory() . '/assets/img/header-gildemeister.png';
    if (!file_exists($check)) {
        return '';
    }
    return apply_filters('billetera_summary_header_brand_url', $url);
}

function billetera_summary_alcancia_url() {
    // SVG no es email-safe; si luego se exporta PNG usarlo aquí.
    // Por ahora se usa moneda CSS + fallback sin imagen.
    $png = get_template_directory() . '/assets/img/email/alcancia.png';
    if (file_exists($png)) {
        return apply_filters(
            'billetera_summary_alcancia_url',
            get_template_directory_uri() . '/assets/img/email/alcancia.png'
        );
    }
    return apply_filters('billetera_summary_alcancia_url', '');
}

function billetera_summary_subject($week_start, $week_end, $user = null) {
    if (billetera_summary_variant($week_start, $week_end) === 'cierre') {
        try {
            $tz = wp_timezone();
            $ref = (new DateTime($week_end, $tz))->modify('-1 day');
            $mes = mb_convert_case(mb_strtolower(date_i18n('F', $ref->getTimestamp()), 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            return 'Cierre de ' . $mes . ' · Tu comisión ya está en camino';
        } catch (Exception $ex) {
        }
    }
    return 'Tu resumen de comisiones · ' . billetera_summary_week_label($week_start, $week_end);
}

function billetera_summary_cierre_label($week_end) {
    try {
        $tz = wp_timezone();
        $ref = (new DateTime($week_end, $tz))->modify('-1 day');
        return 'CIERRE DE ' . mb_strtoupper(date_i18n('F', $ref->getTimestamp()), 'UTF-8') . ' ' . $ref->format('Y');
    } catch (Exception $ex) {
        return 'CIERRE DE MES';
    }
}

/**
 * Datos extra solo para el template de cierre.
 */
function billetera_summary_get_payload_cierre($user_id, $p) {
    global $wpdb;
    $user_id = intval($user_id);
    $v = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $mes_inicio = $p['mes_inicio'] ?? '';
    $mes_fin = $p['mes_fin'] ?? '';
    $mes_actual = floatval($p['mes_actual'] ?? 0);
    $meta = floatval($p['meta'] ?? 0);

    $detalle = [];
    if ($mes_inicio !== '' && $mes_fin !== '') {
        $detalle = $wpdb->get_results($wpdb->prepare(
            "SELECT s.nombre AS producto, COUNT(*) AS ventas,
                    ROUND(SUM(v.monto_comision_sol * v.bonus_multiplier), 2) AS comision
             FROM $v v JOIN $sub s ON s.id = v.subcategoria_id
             WHERE v.usuario_id = %d AND v.creado_en >= %s AND v.creado_en < %s
             GROUP BY s.id, s.nombre ORDER BY comision DESC",
            $user_id, $mes_inicio, $mes_fin
        ));
    }
    $mes_ventas = 0;
    foreach ((array) $detalle as $d) {
        $mes_ventas += intval($d->ventas);
    }

    // Acumulado año + mejor mes del año.
    $acum_ano = 0;
    $es_mejor = false;
    try {
        $tz = wp_timezone();
        $ref = new DateTime($mes_inicio, $tz);
        $y0 = $ref->format('Y-01-01 00:00:00');
        $y1 = (clone $ref)->modify('first day of January next year')->format('Y-m-01 00:00:00');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE_FORMAT(creado_en, '%%Y-%%m') AS ym, SUM(monto_comision_sol * bonus_multiplier) AS total
             FROM $v WHERE usuario_id = %d AND creado_en >= %s AND creado_en < %s GROUP BY ym",
            $user_id, $y0, $y1
        ));
        $max = 0;
        $cur_ym = $ref->format('Y-m');
        $cur_tot = 0;
        foreach ((array) $rows as $r) {
            $t = floatval($r->total);
            if ($t > $max) {
                $max = $t;
            }
            if ($r->ym === $cur_ym) {
                $cur_tot = $t;
            }
            $acum_ano += $t;
        }
        $es_mejor = ($cur_tot > 0 && $cur_tot >= $max);
    } catch (Exception $ex) {
    }
    $acum_ano = round($acum_ano, 2);

    // Siguiente mes: meta, días y por día.
    $meta_next = function_exists('billetera_get_meta_por_usuario') ? floatval(billetera_get_meta_por_usuario($user_id)) : $meta;
    $next_upper = '';
    $next_cap = '';
    $next_days = 30;
    try {
        $tz = wp_timezone();
        $ref = new DateTime($mes_inicio, $tz);
        $nx = (clone $ref)->modify('first day of next month');
        $next_upper = mb_strtoupper(date_i18n('F', $nx->getTimestamp()), 'UTF-8');
        $lc = mb_strtolower($next_upper, 'UTF-8');
        $next_cap = mb_convert_case($lc, MB_CASE_TITLE, 'UTF-8');
        $next_days = intval($nx->format('t'));
    } catch (Exception $ex) {
    }
    $next_por_dia = $next_days > 0 ? round($meta_next / $next_days, 2) : $meta_next;

    $pct = $meta > 0 ? round(($mes_actual / $meta) * 100, 1) : 0;
    $supero = ($mes_actual >= $meta && $meta > 0);
    $excedente = $supero ? round($mes_actual - $meta, 2) : 0;

    return [
        'detalle_producto' => $detalle ?: [],
        'mes_ventas' => $mes_ventas,
        'acum_ano' => $acum_ano,
        'es_mejor' => $es_mejor,
        'pct' => $pct,
        'supero' => $supero,
        'excedente' => $excedente,
        'meta_next' => $meta_next,
        'next_upper' => $next_upper,
        'next_cap' => $next_cap,
        'next_days' => $next_days,
        'next_por_dia' => $next_por_dia,
        'ano' => (new DateTime($mes_inicio))->format('Y'),
    ];
}

/**
 * ¿Semana que cruza fin de mes? week = [lunes 00:00, lunes siguiente 00:00).
 * Si el mes de week_start != mes de (week_end - 1 día) => es semana de cierre.
 * Cuando se tenga el segundo diseño, aquí se bifurca el template.
 */
function billetera_summary_is_closing_week($week_start, $week_end) {
    try {
        $tz = wp_timezone();
        $s = new DateTime($week_start, $tz);
        $e = (new DateTime($week_end, $tz))->modify('-1 day');
        return $s->format('Y-m') !== $e->format('Y-m');
    } catch (Exception $ex) {
        return false;
    }
}

function billetera_summary_variant($week_start, $week_end) {
    $v = billetera_summary_is_closing_week($week_start, $week_end) ? 'cierre' : 'semanal';
    return apply_filters('billetera_summary_variant', $v, $week_start, $week_end);
}

function billetera_summary_week_label_long($week_start, $week_end) {
    try {
        $tz = wp_timezone();
        $s = new DateTime($week_start, $tz);
        $e = (new DateTime($week_end, $tz))->modify('-1 day');
        // "SEMANA DEL 5 AL 11 DE OCTUBRE"
        $txt = sprintf(
            'SEMANA DEL %s AL %s DE %s',
            date_i18n('j', $s->getTimestamp()),
            date_i18n('j', $e->getTimestamp()),
            mb_strtoupper(date_i18n('F', $e->getTimestamp()), 'UTF-8')
        );
        return $txt;
    } catch (Exception $ex) {
        return '';
    }
}

function billetera_summary_month_name_upper($week_end) {
    try {
        $tz = wp_timezone();
        $e = (new DateTime($week_end, $tz))->modify('-1 day');
        return mb_strtoupper(date_i18n('F', $e->getTimestamp()), 'UTF-8');
    } catch (Exception $ex) {
        return '';
    }
}

function billetera_summary_month_name_cap($week_end) {
    $u = billetera_summary_month_name_upper($week_end);
    return $u !== '' ? mb_convert_case(mb_strtolower($u, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : '';
}

function billetera_summary_first_names($display_name) {
    $parts = preg_split('/\s+/', trim((string) $display_name));
    $parts = array_values(array_filter($parts));
    if (count($parts) >= 2) {
        return $parts[0] . ' ' . $parts[1];
    }
    return $parts[0] ?? '';
}

/**
 * Payload completo para el template v2. Reutiliza funciones existentes.
 */
function billetera_summary_get_payload($user_id, $week_start, $week_end, $stats_semana = null) {
    global $wpdb;
    $user_id = intval($user_id);
    $tz = wp_timezone();

    try {
        $last_day = (new DateTime($week_end, $tz))->modify('-1 day');
    } catch (Exception $ex) {
        $last_day = billetera_stats_local_now();
    }
    $base_mes = (clone $last_day)->modify('first day of this month')->setTime(0, 0, 0);
    $mes_inicio = $base_mes->format('Y-m-d H:i:s');
    $mes_fin = (clone $base_mes)->modify('first day of next month')->format('Y-m-01 00:00:00');

    if (!is_array($stats_semana)) {
        $stats_semana = billetera_summary_stats_usuario($user_id, $week_start, $week_end);
    }

    $resumen_mes = function_exists('billetera_stats_resumen_usuario')
        ? billetera_stats_resumen_usuario($user_id, $base_mes)
        : ['mes_actual' => 0, 'meta' => 1200, 'fill_percent' => 0, 'fill_real' => 0, 'faltan' => 1200];

    $mes_actual = round(floatval($resumen_mes['mes_actual'] ?? 0), 2);
    $meta = floatval($resumen_mes['meta'] ?? 1200);
    $fill = floatval($resumen_mes['fill_real'] ?? 0);
    if ($fill <= 0 && $meta > 0) {
        $fill = round(($mes_actual / $meta) * 100, 1);
    }
    $faltan = max(round($meta - $mes_actual, 2), 0);

    // Días para el cierre (calendario): fin de mes - último día de la semana.
    $dias_mes = intval($last_day->format('t'));
    $dia_ref = intval($last_day->format('j'));
    $dias_cierre = max($dias_mes - $dia_ref, 0);
    $por_dia = $dias_cierre > 0 ? round($faltan / $dias_cierre, 2) : $faltan;

    // Detalle de ventas de la semana (fecha, producto, placa, comisión).
    $v = $wpdb->prefix . 'billetera_ventas';
    $sub = $wpdb->prefix . 'billetera_subcategorias';
    $ventas = $wpdb->get_results($wpdb->prepare(
        "SELECT v.creado_en AS fecha, s.nombre AS producto, v.id_type, v.id_value,
                ROUND(v.monto_comision_sol * v.bonus_multiplier, 2) AS comision
         FROM $v v
         JOIN $sub s ON s.id = v.subcategoria_id
         WHERE v.usuario_id = %d AND v.creado_en >= %s AND v.creado_en < %s
         ORDER BY v.creado_en DESC
         LIMIT 10",
        $user_id, $week_start, $week_end
    ));

    // Ranking del mes de referencia (tienda + global) con conteo de ventas.
    $ranking = ['tienda' => ['rank' => 0, 'total' => 0], 'global' => ['rank' => 0, 'total' => 0], 'diff_txt' => ''];
    if (function_exists('billetera_stats_ranking')) {
        // billetera_stats_ranking usa mes actual; recalculamos para el mes de la semana.
        $tot = $wpdb->get_results($wpdb->prepare(
            "SELECT usuario_id, SUM(monto_comision_sol * bonus_multiplier) AS total, COUNT(*) AS ventas
             FROM $v WHERE creado_en >= %s AND creado_en < %s AND (monto_comision_sol * bonus_multiplier) > 0
             GROUP BY usuario_id",
            $mes_inicio, $mes_fin
        ));
        $bal = [];
        $nventas = [];
        foreach ($tot as $t) {
            $bal[intval($t->usuario_id)] = floatval($t->total);
            $nventas[intval($t->usuario_id)] = intval($t->ventas);
        }
        $tienda_id = intval(get_user_meta($user_id, '_tienda_asociada', true));
        if ($tienda_id) {
            $ases = get_users(['meta_key' => '_tienda_asociada', 'meta_value' => $tienda_id, 'fields' => 'ID', 'role__not_in' => ['jefe_venta']]);
            $tb = [];
            foreach ($ases as $aid) {
                $tb[intval($aid)] = $bal[intval($aid)] ?? 0;
            }
            arsort($tb, SORT_NUMERIC);
            $pos = array_search($user_id, array_keys($tb), true);
            $ranking['tienda'] = ['rank' => $pos === false ? 0 : $pos + 1, 'total' => count($tb)];
        }
        $glob = get_users(['role__in' => ['asesor', 'jefe_venta'], 'fields' => 'ID', 'number' => -1]);
        $gb = [];
        foreach ($glob as $gid) {
            $gb[intval($gid)] = $bal[intval($gid)] ?? 0;
        }
        arsort($gb, SORT_NUMERIC);
        $keys = array_keys($gb);
        $posg = array_search($user_id, $keys, true);
        $ranking['global'] = ['rank' => $posg === false ? 0 : $posg + 1, 'total' => count($gb)];
        // Texto "+N ventas sobre el #2" (solo si hay contexto).
        if ($posg !== false) {
            $mis_ventas = $nventas[$user_id] ?? 0;
            if ($posg === 0 && isset($keys[1])) {
                $seg = $nventas[intval($keys[1])] ?? 0;
                $d = $mis_ventas - $seg;
                if ($d > 0) {
                    $ranking['diff_txt'] = '+' . $d . ($d === 1 ? ' venta sobre el #2' : ' ventas sobre el #2');
                }
            } elseif ($posg > 0 && isset($keys[$posg - 1])) {
                $prev = $nventas[intval($keys[$posg - 1])] ?? 0;
                $d = $prev - $mis_ventas;
                if ($d > 0) {
                    $ranking['diff_txt'] = 'Te faltan ' . $d . ($d === 1 ? ' venta para el puesto anterior' : ' ventas para el puesto anterior');
                }
            }
        }
    }

    $para_meta = function_exists('billetera_stats_para_meta')
        ? billetera_stats_para_meta($user_id, 'mes', $base_mes, floatval($faltan))
        : ['alcanzada' => false, 'filas' => []];

    $camp = function_exists('billetera_campanas_para_usuario')
        ? billetera_campanas_para_usuario($user_id)
        : [];
    $camp1 = $camp[0] ?? null;

    return [
        'semana' => $stats_semana,
        'mes_actual' => $mes_actual,
        'meta' => $meta,
        'fill' => $fill,
        'fill_bar' => min(max($fill, 0), 100),
        'faltan' => $faltan,
        'dias_cierre' => $dias_cierre,
        'por_dia' => $por_dia,
        'ventas_detalle' => $ventas ?: [],
        'ranking' => $ranking,
        'para_meta' => $para_meta,
        'campana' => $camp1,
        'mes_inicio' => $mes_inicio,
        'mes_fin' => $mes_fin,
    ];
}

function billetera_summary_render_semanal($user, $stats, $week_start, $week_end) {
    $variant = billetera_summary_variant($week_start, $week_end);
    // Hook para el segundo diseño (cierre). Por ahora ambos usan v2 semanal.
    $html = apply_filters('billetera_summary_render_variant', null, $user, $stats, $week_start, $week_end, $variant);
    if (is_string($html) && $html !== '') {
        return $html;
    }

    $logo   = billetera_summary_logo_url();
    $logo_simple = billetera_summary_logo_simple_url();
    $brand  = billetera_summary_header_brand_url();
    $alcancia_img = billetera_summary_alcancia_url();
    $nombre_full = $user ? $user->display_name : '';
    $nombre = billetera_summary_first_names($nombre_full);
    $label_long = billetera_summary_week_label_long($week_start, $week_end);
    $label_short = billetera_summary_week_label($week_start, $week_end);
    $mes_cap = billetera_summary_month_name_cap($week_end);
    $site   = get_bloginfo('name');
    $url    = home_url('/');

    $p = billetera_summary_get_payload($user ? $user->ID : 0, $week_start, $week_end, $stats);
    $sem = $p['semana'];
    $sem_total = round(floatval($sem['total'] ?? 0), 2);
    $sem_ventas = intval($sem['ventas'] ?? 0);
    $mes_actual = $p['mes_actual'];
    $meta = $p['meta'];
    $fill = $p['fill'];
    $fill_bar = $p['fill_bar'];
    $faltan = $p['faltan'];
    $dias = $p['dias_cierre'];
    $por_dia = $p['por_dia'];

    $F = "font-family:'Barlow','Roboto',Arial,Helvetica,sans-serif";
    $FC = "font-family:'Barlow Condensed','Roboto Condensed','Barlow','Roboto',Arial,Helvetica,sans-serif";

    // --- Próximo paso (para llegar a la meta) ---
    $filas_pm = $p['para_meta']['filas'] ?? [];
    if (!empty($filas_pm)) {
        $top = array_slice($filas_pm, 0, 3);
        $tit = intval($top[0]['necesarias']) . ' ventas de ' . $top[0]['sub'];
        $detalles = [];
        for ($i = 1; $i < count($top); $i++) {
            $detalles[] = 'o ' . intval($top[$i]['necesarias']) . ' de ' . $top[$i]['sub'];
        }
        $bajada_pm = implode(' · ', $detalles);
        if ($bajada_pm !== '') {
            $bajada_pm .= '. Comisión referencial según tus registros.';
        } else {
            $bajada_pm = 'Comisión referencial según tus registros.';
        }
    } else {
        $tit = $faltan > 0 ? 'Sigue sumando ventas este mes' : '¡Meta alcanzada!';
        $bajada_pm = $faltan > 0 ? 'Cada registro acerca tu alcancía a la meta.' : 'Buen trabajo, mantén el ritmo.';
    }

    // --- Campaña ---
    $bloque_camp = '';
    if (!empty($p['campana'])) {
        $c = $p['campana'];
        $et = trim((string) ($c['etiqueta'] ?? 'CAMPAÑA'));
        if (!empty($c['hasta'])) {
            try {
                $h = new DateTime($c['hasta'], wp_timezone());
                $et .= ' · HASTA EL ' . mb_strtoupper(date_i18n('d M', $h->getTimestamp()), 'UTF-8');
            } catch (Exception $ex) {
            }
        }
        $bloque_camp = '<tr><td style="padding:10px 32px 0;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0A6CB4" style="background:#0A6CB4;background:linear-gradient(90deg,#0A6CB4 0%,#0B4F86 100%);padding:16px 18px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td><div style="display:inline-block;background:#F5C563;font-size:9.5px;font-weight:700;letter-spacing:1px;color:#5A3D06;padding:2px 7px;' . $F . '">' . esc_html(mb_strtoupper($et, 'UTF-8')) . '</div>'
            . '<div style="font-size:18px;font-weight:700;color:#FFFFFF;margin-top:6px;' . $FC . '">' . esc_html($c['titulo']) . '</div>'
            . (!empty($c['bajada']) ? '<div style="font-size:12px;color:rgba(255,255,255,0.8);margin-top:2px;' . $F . '">' . esc_html($c['bajada']) . '</div>' : '')
            . '</td>'
            . '</tr></table></td></tr></table></td></tr>';
    }

    $pct_txt = rtrim(rtrim(number_format($fill, 1, '.', ''), '0'), '.') . '%';
    $bar_w = (int) max(2, min(100, round($fill_bar)));
    $coin_txt = '+' . (string) intval(round($sem_total));

    $html_out = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>@import url("https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@700&family=Roboto:wght@400;500;700&family=Roboto+Condensed:wght@700&display=swap");</style><title>' . esc_html($site) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#E4EAF1;color:#0E2438;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E4EAF1;padding:0;"><tr><td align="center">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;">'

    // Header azul
    . '<tr><td style="background:#0A6CB4;padding:18px 28px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle"><img src="' . esc_url($logo_simple) . '" alt="" width="48" height="28" style="width:48px;height:28px;display:block;border:0;"></td>'
    . '<td valign="middle" style="padding-left:12px;">'
    . ($brand !== '' ? '<img src="' . esc_url($brand) . '" alt="Gildemeister" height="14" style="height:14px;display:block;border:0;">' : '<div style="font-size:13px;font-weight:700;color:#FFFFFF;' . $F . '">GILDEMEISTER</div>')
    . '<div style="font-size:11px;color:rgba(255,255,255,0.85);margin-top:3px;' . $F . '">Mi alcancía 360 · Postventa</div>'
    . '</td></tr></table></td>'
    . '<td align="right" valign="middle"><div style="display:inline-block;background:rgba(255,255,255,0.14);border-radius:12px;font-size:10.5px;font-weight:700;letter-spacing:1.2px;color:#FFFFFF;padding:5px 10px;' . $F . '">RESUMEN SEMANAL</div></td>'
    . '</tr></table></td></tr>'

    // Hero
    . '<tr><td bgcolor="#FBF6EA" style="background:#FBF6EA;background:linear-gradient(180deg,#FBF6EA 0%,#FFFFFF 100%);padding:30px 32px 26px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle" width="360">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#8A5F0F;' . $F . '">' . esc_html($label_long) . '</div>'
    . '<div style="font-size:18px;font-weight:500;color:#0E2438;margin-top:10px;' . $F . '">Hola ' . esc_html($nombre) . ', esta semana sumaste</div>'
    . '<div style="font-size:60px;line-height:72px;font-weight:700;color:#0A6CB4;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($sem_total)) . '</div>'
    . '<div style="font-size:15px;color:#5B6B80;' . $F . '">a tu alcancía de ' . esc_html($mes_cap) . '. ¡Sigue así!</div>'
    . '</td>'
    . '<td align="center" valign="middle" width="176">'
    . '<div style="display:inline-block;background-color:#F8D27A;background:linear-gradient(180deg,#F8D27A 0%,#C98A1E 100%);border:1px solid #B77C16;border-radius:20px;font-size:12.8px;font-weight:700;color:#5A3D06;padding:10px 14px;' . $FC . '">' . esc_html($coin_txt) . '</div>'
    . ($alcancia_img !== ''
        ? '<div style="margin-top:6px;"><img src="' . esc_url($alcancia_img) . '" alt="Alcancía" width="156" style="width:156px;max-width:156px;display:block;border:0;margin:0 auto;"></div>'
        : '')
    . '</td>'
    . '</tr></table></td></tr>'

    // KPIs
    . '<tr><td style="padding:24px 32px 8px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #DCE3EB;"><tr>'
    . '<td width="33%" valign="top" style="padding:14px 16px;background:#FFFFFF;"><div style="font-size:26px;font-weight:700;color:#0E2438;' . $FC . '">' . intval($sem_ventas) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">VENTAS DE LA SEMANA</div></td>'
    . '<td width="34%" valign="top" style="padding:14px 16px;background:#F5F8FB;border-left:1px solid #DCE3EB;border-right:1px solid #DCE3EB;"><div style="font-size:26px;font-weight:700;color:#1B6E46;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($sem_total)) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">COMISIÓN DE LA SEMANA</div></td>'
    . '<td width="33%" valign="top" style="padding:14px 16px;background:#FFFFFF;"><div style="font-size:26px;font-weight:700;color:#0A6CB4;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($mes_actual)) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">ACUMULADO ' . esc_html(billetera_summary_month_name_upper($week_end)) . '</div></td>'
    . '</tr></table></td></tr>'

    // Avance meta
    . '<tr><td style="padding:18px 32px 0;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#5B6B80;' . $F . '">TU AVANCE DE META · ' . esc_html(billetera_summary_month_name_upper($week_end)) . '</td>'
    . '<td align="right" style="font-size:20px;font-weight:700;color:#8A5F0F;' . $FC . '">' . esc_html($pct_txt) . '</td>'
    . '</tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;"><tr><td bgcolor="#E4EAF1" style="background:#E4EAF1;border-radius:6px;height:12px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;">'
    . '<div style="background:#F5C563;background:linear-gradient(90deg,#C98A1E 0%,#F5C563 100%);border-radius:6px;height:12px;width:' . intval($bar_w) . '%;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</div>'
    . '</td></tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="padding-top:8px;font-size:12px;font-weight:600;color:#0E2438;' . $F . '">' . esc_html(billetera_summary_fmt_sol($mes_actual)) . ' ahorrados</td>'
    . '<td align="right" style="padding-top:8px;font-size:12px;color:#5B6B80;' . $F . '">Meta ' . esc_html(billetera_summary_fmt_sol($meta)) . '</td>'
    . '</tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td width="33%" style="padding:10px 5px 0 0;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">Te faltan</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($faltan)) . '</div></div></td>'
    . '<td width="34%" style="padding:10px 5px 0 5px;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">Días para el cierre</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . intval($dias) . ' días</div></div></td>'
    . '<td width="33%" style="padding:10px 0 0 5px;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">Necesitas por día</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($por_dia)) . '</div></div></td>'
    . '</tr></table></td></tr>'

    // Ranking + próximo paso (cajas de igual altura: fondo en la celda)
    . '<tr><td style="padding:14px 32px 0;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td width="262" valign="top" bgcolor="#F5F8FB" style="background:#F5F8FB;border:1px solid #DCE3EB;padding:16px;">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#5B6B80;' . $F . '">TU RANKING</div>'
    . '<div style="margin-top:4px;"><span style="font-size:34px;font-weight:700;color:#8A5F0F;' . $FC . '">#' . intval($p['ranking']['tienda']['rank']) . '</span> <span style="font-size:13px;color:#5B6B80;' . $F . '">de ' . intval($p['ranking']['tienda']['total']) . ' en tu tienda</span></div>'
    . '<div style="font-size:12px;color:#5B6B80;margin-top:2px;' . $F . '">#' . intval($p['ranking']['global']['rank']) . ' de ' . intval($p['ranking']['global']['total']) . ' a nivel global'
    . ($p['ranking']['diff_txt'] !== '' ? ' · ' . esc_html($p['ranking']['diff_txt']) : '') . '</div>'
    . '</td>'
    . '<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>'
    . '<td width="262" valign="top" bgcolor="#FBF6EA" style="background:#FBF6EA;border:1px solid #F0D9A8;padding:16px;">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#8A5F0F;' . $F . '">PARA LLEGAR A TU META</div>'
    . '<div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:4px;' . $FC . '">' . esc_html($tit) . '</div>'
    . '<div style="font-size:12px;color:#5B6B80;margin-top:2px;' . $F . '">' . esc_html($bajada_pm) . '</div>'
    . '</td>'
    . '</tr></table></td></tr>'

    . $bloque_camp

    // CTA
    . '<tr><td align="center" style="padding:22px 32px 30px;">'
    . '<a href="' . esc_url($url) . '" style="display:inline-block;background:#0A6CB4;font-size:15px;font-weight:700;letter-spacing:1.5px;color:#FFFFFF;text-decoration:none;padding:14px 36px;' . $FC . '">VER MI ALCANCÍA</a>'
    . '<div style="margin-top:12px;"><a href="' . esc_url($url) . '" style="font-size:12px;font-weight:600;color:#0A6CB4;text-decoration:none;' . $F . '">¿Te falta registrar una venta? Regístrala aquí</a></div>'
    . '</td></tr>'

    // Footer
    . '<tr><td style="background:#F5F8FB;padding:20px 32px;">'
    . '<div style="font-size:11px;line-height:13px;color:#5B6B80;' . $F . '">Montos referenciales sujetos a validación del jefe de taller. El pago se realiza al cierre de mes. Por privacidad, el ranking solo muestra número de ventas.</div>'
    . '<div style="font-size:11px;line-height:13px;color:#8494A6;margin-top:8px;' . $F . '">Correo automático de Mi Alcancía 360 · Gildemeister Postventa · No respondas a este mensaje.</div>'
    . '<div style="margin-top:8px;"><a href="' . esc_url($url) . '" style="font-size:11px;font-weight:600;color:#0A6CB4;text-decoration:none;' . $F . '">Configurar notificaciones</a></div>'
    . '</td></tr>'

    . '</table></td></tr></table></body></html>';

    return $html_out;
}

function billetera_summary_bloque_campana($camp, $url, $F, $FC) {
    if (empty($camp)) {
        return '';
    }
    $c = $camp;
    $et = trim((string) ($c['etiqueta'] ?? 'CAMPAÑA'));
    if (!empty($c['hasta'])) {
        try {
            $h = new DateTime($c['hasta'], wp_timezone());
            $et .= ' · HASTA EL ' . mb_strtoupper(date_i18n('d M', $h->getTimestamp()), 'UTF-8');
        } catch (Exception $ex) {
        }
    }
    return '<tr><td style="padding:10px 32px 0;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td bgcolor="#0A6CB4" style="background:#0A6CB4;background:linear-gradient(90deg,#0A6CB4 0%,#0B4F86 100%);padding:16px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td><div style="display:inline-block;background:#F5C563;font-size:9.5px;font-weight:700;letter-spacing:1px;color:#5A3D06;padding:2px 7px;' . $F . '">' . esc_html(mb_strtoupper($et, 'UTF-8')) . '</div>'
        . '<div style="font-size:18px;font-weight:700;color:#FFFFFF;margin-top:6px;' . $FC . '">' . esc_html($c['titulo']) . '</div>'
        . (!empty($c['bajada']) ? '<div style="font-size:12px;color:rgba(255,255,255,0.8);margin-top:2px;' . $F . '">' . esc_html($c['bajada']) . '</div>' : '')
        . '</td>'
        . '</tr></table></td></tr></table></td></tr>';
}

function billetera_summary_render_cierre($user, $stats, $week_start, $week_end) {
    $logo_simple = billetera_summary_logo_simple_url();
    $brand  = billetera_summary_header_brand_url();
    $alcancia_img = billetera_summary_alcancia_url();
    $nombre = billetera_summary_first_names($user ? $user->display_name : '');
    $site   = get_bloginfo('name');
    $url    = home_url('/');

    $p = billetera_summary_get_payload($user ? $user->ID : 0, $week_start, $week_end, $stats);
    $c = billetera_summary_get_payload_cierre($user ? $user->ID : 0, $p);

    $mes_actual = $p['mes_actual'];
    $meta = $p['meta'];
    $pct = $c['pct'];
    $supero = $c['supero'];
    $excedente = $c['excedente'];
    $mes_ventas = $c['mes_ventas'];
    $detalle = $c['detalle_producto'];
    $acum_ano = $c['acum_ano'];
    $ano = $c['ano'];
    $mes_upper = billetera_summary_month_name_upper($week_end);
    $mes_cap = billetera_summary_month_name_cap($week_end);
    $cierre_label = billetera_summary_cierre_label($week_end);

    $F = "font-family:'Barlow','Roboto',Arial,Helvetica,sans-serif";
    $FC = "font-family:'Barlow Condensed','Roboto Condensed','Barlow','Roboto',Arial,Helvetica,sans-serif";

    $pct_txt = rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '%';
    $bar_w = (int) max(2, min(100, round($pct)));
    $coin_txt = esc_html($pct_txt);
    $amount_color = $supero ? '#1B6E46' : '#0A6CB4';

    $hero_title = $supero
        ? '¡Felicitaciones, ' . $nombre . '! Superaste tu meta y cobras'
        : 'Hola ' . $nombre . ', cerraste ' . mb_strtolower($mes_cap, 'UTF-8') . ' con';
    $hero_sub = $pct_txt . ' de tu meta de ' . billetera_summary_fmt_sol($meta)
        . ($supero && $c['es_mejor'] ? ' · tu mejor mes del año.' : ($supero ? '.' : '.'));

    // Detalle por producto: sección eliminada (se conserva $detalle para "Repite").

    $meta_line = $supero
        ? esc_html(billetera_summary_fmt_sol($mes_actual)) . ' · meta superada'
        : esc_html(billetera_summary_fmt_sol($mes_actual)) . ' ahorrados';
    $box1_label = $supero ? 'Superaste tu meta por' : 'Te faltó para tu meta';
    $box1_val = $supero ? billetera_summary_fmt_sol($excedente) : billetera_summary_fmt_sol($p['faltan']);

    $top = $detalle[0] ?? null;
    if ($top && $mes_ventas > 0) {
        $repite_tit = 'Con ' . intval($mes_ventas) . ' ventas como las de ' . mb_strtolower($mes_cap, 'UTF-8');
        $repite_baj = 'vuelves a superar tu meta. Tu producto más fuerte: ' . $top->producto . ' (' . billetera_summary_fmt_sol($top->comision) . ').';
    } else {
        $repite_tit = 'Registra tus primeras ventas';
        $repite_baj = 'Cada venta te acerca a tu meta del próximo mes.';
    }

    $bloque_camp = billetera_summary_bloque_campana($p['campana'], $url, $F, $FC);

    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><style>@import url("https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@700&family=Roboto:wght@400;500;700&family=Roboto+Condensed:wght@700&display=swap");</style><title>' . esc_html($site) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#E4EAF1;color:#0E2438;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#E4EAF1;padding:0;"><tr><td align="center">'
    . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#FFFFFF;">'
    . '<tr><td style="background:#0A6CB4;padding:18px 28px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle"><img src="' . esc_url($logo_simple) . '" alt="" width="48" height="28" style="width:48px;height:28px;display:block;border:0;"></td>'
    . '<td valign="middle" style="padding-left:12px;">'
    . ($brand !== '' ? '<img src="' . esc_url($brand) . '" alt="Gildemeister" height="14" style="height:14px;display:block;border:0;">' : '<div style="font-size:13px;font-weight:700;color:#FFFFFF;' . $F . '">GILDEMEISTER</div>')
    . '<div style="font-size:11px;color:rgba(255,255,255,0.85);margin-top:3px;' . $F . '">Mi alcancía 360 · Postventa</div>'
    . '</td></tr></table></td>'
    . '<td align="right" valign="middle"><div style="display:inline-block;background:rgba(255,255,255,0.14);border-radius:12px;font-size:10.5px;font-weight:700;letter-spacing:1.2px;color:#FFFFFF;padding:5px 10px;' . $F . '">CIERRE DE MES</div></td>'
    . '</tr></table></td></tr>'
    . '<tr><td bgcolor="#FBF6EA" style="background:#FBF6EA;background:linear-gradient(180deg,#FBF6EA 0%,#FFFFFF 100%);padding:30px 32px 26px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td valign="middle" width="360">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#8A5F0F;' . $F . '">' . esc_html($cierre_label) . '</div>'
    . '<div style="font-size:18px;font-weight:500;color:#0E2438;margin-top:10px;line-height:22px;' . $F . '">' . esc_html($hero_title) . '</div>'
    . '<div style="font-size:60px;line-height:72px;font-weight:700;color:' . $amount_color . ';' . $FC . '">' . esc_html(billetera_summary_fmt_sol($mes_actual)) . '</div>'
    . '<div style="font-size:15px;color:#5B6B80;' . $F . '">' . esc_html($hero_sub) . '</div>'
    . '</td>'
    . '<td align="center" valign="middle" width="176">'
    . '<div style="display:inline-block;background-color:#F8D27A;background:linear-gradient(180deg,#F8D27A 0%,#C98A1E 100%);border:1px solid #B77C16;border-radius:20px;font-size:12px;font-weight:700;color:#5A3D06;padding:10px 14px;' . $FC . '">' . $coin_txt . '</div>'
    . ($alcancia_img !== ''
        ? '<div style="margin-top:6px;"><img src="' . esc_url($alcancia_img) . '" alt="Alcancía" width="156" style="width:156px;max-width:156px;display:block;border:0;margin:0 auto;"></div>'
        : '')
    . '</td>'
    . '</tr></table></td></tr>'
    . '<tr><td style="padding:24px 32px 8px;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #DCE3EB;"><tr>'
    . '<td width="33%" valign="top" style="padding:14px 16px;background:#FFFFFF;"><div style="font-size:26px;font-weight:700;color:#0E2438;' . $FC . '">' . intval($mes_ventas) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">VENTAS DEL MES</div></td>'
    . '<td width="34%" valign="top" style="padding:14px 16px;background:#F5F8FB;border-left:1px solid #DCE3EB;border-right:1px solid #DCE3EB;"><div style="font-size:26px;font-weight:700;color:#1B6E46;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($mes_actual)) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">COMISIÓN A COBRAR</div></td>'
    . '<td width="33%" valign="top" style="padding:14px 16px;background:#FFFFFF;"><div style="font-size:26px;font-weight:700;color:#8A5F0F;' . $FC . '">' . esc_html($pct_txt) . '</div><div style="font-size:10px;font-weight:600;letter-spacing:1px;color:#8494A6;margin-top:4px;' . $F . '">DE TU META</div></td>'
    . '</tr></table></td></tr>'
    . '<tr><td style="padding:18px 32px 0;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#5B6B80;' . $F . '">TU META DE ' . esc_html($mes_upper) . '</td>'
    . '<td align="right" style="font-size:20px;font-weight:700;color:#1B6E46;' . $FC . '">' . esc_html($pct_txt) . '</td>'
    . '</tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;"><tr><td bgcolor="#E4EAF1" style="background:#E4EAF1;border-radius:6px;height:12px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;">'
    . '<div style="background:#2E9E67;background:linear-gradient(90deg,#1B6E46 0%,#2E9E67 100%);border-radius:6px;height:12px;width:' . intval($bar_w) . '%;font-size:0;line-height:0;mso-line-height-rule:exactly;">&nbsp;</div>'
    . '</td></tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td style="padding-top:8px;font-size:12px;font-weight:600;color:#0E2438;' . $F . '">' . $meta_line . '</td>'
    . '<td align="right" style="padding-top:8px;font-size:12px;color:#5B6B80;' . $F . '">Meta ' . esc_html(billetera_summary_fmt_sol($meta)) . '</td>'
    . '</tr></table>'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td width="33%" style="padding:10px 5px 0 0;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">' . esc_html($box1_label) . '</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . esc_html($box1_val) . '</div></div></td>'
    . '<td width="34%" style="padding:10px 5px 0 5px;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">Ventas validadas</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . intval($mes_ventas) . ' de ' . intval($mes_ventas) . '</div></div></td>'
    . '<td width="33%" style="padding:10px 0 0 5px;"><div style="background:#F5F8FB;padding:10px 12px;"><div style="font-size:11px;color:#5B6B80;' . $F . '">Acumulado ' . esc_html($ano) . '</div><div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:2px;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($acum_ano)) . '</div></div></td>'
    . '</tr></table></td></tr>'
    . '<tr><td style="padding:14px 32px 0;">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
    . '<td width="262" valign="top" bgcolor="#F5F8FB" style="background:#F5F8FB;border:1px solid #DCE3EB;padding:16px;">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#5B6B80;' . $F . '">TU NUEVA META · ' . esc_html($c['next_upper']) . '</div>'
    . '<div style="margin-top:4px;"><span style="font-size:34px;font-weight:700;color:#0A6CB4;' . $FC . '">' . esc_html(billetera_summary_fmt_sol($c['meta_next'])) . '</span> <span style="font-size:13px;color:#5B6B80;' . $F . '">en ' . intval($c['next_days']) . ' días</span></div>'
    . '<div style="font-size:12px;color:#5B6B80;margin-top:2px;' . $F . '">Tu alcancía de ' . esc_html(mb_strtolower($c['next_cap'], 'UTF-8')) . ' empieza en S/ 0. Necesitas unos ' . esc_html(billetera_summary_fmt_sol($c['next_por_dia'])) . ' por día.</div>'
    . '</td>'
    . '<td width="12" style="font-size:0;line-height:0;">&nbsp;</td>'
    . '<td width="262" valign="top" bgcolor="#FBF6EA" style="background:#FBF6EA;border:1px solid #F0D9A8;padding:16px;">'
    . '<div style="font-size:11px;font-weight:600;letter-spacing:1.4px;color:#8A5F0F;' . $F . '">REPITE ' . esc_html($mes_upper) . '</div>'
    . '<div style="font-size:17px;font-weight:700;color:#0E2438;margin-top:4px;line-height:20px;' . $FC . '">' . esc_html($repite_tit) . '</div>'
    . '<div style="font-size:12px;color:#5B6B80;margin-top:2px;' . $F . '">' . esc_html($repite_baj) . '</div>'
    . '</td>'
    . '</tr></table></td></tr>'
    . $bloque_camp
    . '<tr><td align="center" style="padding:22px 32px 30px;">'
    . '<a href="' . esc_url($url) . '" style="display:inline-block;background:#0A6CB4;font-size:15px;font-weight:700;letter-spacing:1.5px;color:#FFFFFF;text-decoration:none;padding:14px 36px;' . $FC . '">VER MI HISTORIAL</a>'
    . '<div style="margin-top:12px;"><a href="' . esc_url($url) . '" style="font-size:12px;font-weight:600;color:#0A6CB4;text-decoration:none;' . $F . '">Empieza ' . esc_html(mb_strtolower($c['next_cap'], 'UTF-8')) . ': registra tu primera venta</a></div>'
    . '</td></tr>'
    . '<tr><td style="background:#F5F8FB;padding:20px 32px;">'
    . '<div style="font-size:11px;line-height:13px;color:#5B6B80;' . $F . '">Comisión validada por tu jefe de taller. Si tienes alguna observación sobre tu pago, escríbele a tu jefe de taller o a RR.HH.</div>'
    . '<div style="font-size:11px;line-height:13px;color:#8494A6;margin-top:8px;' . $F . '">Correo automático de Mi Alcancía 360 · Gildemeister Postventa · No respondas a este mensaje.</div>'
    . '<div style="margin-top:8px;"><a href="' . esc_url($url) . '" style="font-size:11px;font-weight:600;color:#0A6CB4;text-decoration:none;' . $F . '">Configurar notificaciones</a></div>'
    . '</td></tr>'
    . '</table></td></tr></table></body></html>';
}

function billetera_summary_render_email($user, $stats, $week_start, $week_end) {
    $variant = billetera_summary_variant($week_start, $week_end);
    $html = apply_filters('billetera_summary_render_variant', null, $user, $stats, $week_start, $week_end, $variant);
    if (is_string($html) && $html !== '') {
        return $html;
    }
    if ($variant === 'cierre') {
        return billetera_summary_render_cierre($user, $stats, $week_start, $week_end);
    }
    return billetera_summary_render_semanal($user, $stats, $week_start, $week_end);
}

function billetera_summary_render_text($user, $stats, $week_start, $week_end) {
    $nombre = $user ? $user->display_name : '';
    $variant = billetera_summary_variant($week_start, $week_end);
    $p = billetera_summary_get_payload($user ? $user->ID : 0, $week_start, $week_end, $stats);

    if ($variant === 'cierre') {
        $c = billetera_summary_get_payload_cierre($user ? $user->ID : 0, $p);
        $t = 'Cierre de mes · ' . billetera_summary_cierre_label($week_end) . "\n";
        $t .= "Hola $nombre\n";
        $t .= ($c['supero'] ? '¡Felicitaciones! Superaste tu meta y cobras ' : 'Cerraste el mes con ') . billetera_summary_fmt_sol($p['mes_actual']) . "\n";
        $t .= 'Meta: ' . billetera_summary_fmt_sol($p['meta']) . ' (' . $c['pct'] . "%)\n";
        $t .= 'Ventas del mes: ' . intval($c['mes_ventas']) . ' · Acumulado año: ' . billetera_summary_fmt_sol($c['acum_ano']) . "\n\n";
        $t .= 'Nueva meta ' . $c['next_upper'] . ': ' . billetera_summary_fmt_sol($c['meta_next']) . ' en ' . intval($c['next_days']) . " días\n";
        $t .= "\n" . home_url('/') . "\n";
        return $t;
    }

    $label  = billetera_summary_week_label($week_start, $week_end);
    $label_long = billetera_summary_week_label_long($week_start, $week_end);

    $p = billetera_summary_get_payload($user ? $user->ID : 0, $week_start, $week_end, $stats);
    $sem = $p['semana'];

    $t = "Resumen semanal de comisiones\n";
    $t .= "Hola $nombre\n";
    $t .= "$label_long ($label)\n\n";
    $t .= 'Comisión semana: ' . billetera_summary_fmt_sol($sem['total'] ?? 0) . "\n";
    $t .= 'Ventas semana: ' . intval($sem['ventas'] ?? 0) . "\n";
    $t .= 'Acumulado mes: ' . billetera_summary_fmt_sol($p['mes_actual']) . "\n";
    $t .= 'Meta: ' . billetera_summary_fmt_sol($p['meta']) . ' (' . $p['fill'] . "%)\n";
    $t .= 'Te faltan: ' . billetera_summary_fmt_sol($p['faltan']) . ' · Días cierre: ' . intval($p['dias_cierre']) . ' · Por día: ' . billetera_summary_fmt_sol($p['por_dia']) . "\n\n";

    $t .= 'Ranking: #' . intval($p['ranking']['tienda']['rank']) . ' de ' . intval($p['ranking']['tienda']['total']) . ' en tienda · #' . intval($p['ranking']['global']['rank']) . ' de ' . intval($p['ranking']['global']['total']) . " global\n";
    if (!empty($p['ranking']['diff_txt'])) {
        $t .= $p['ranking']['diff_txt'] . "\n";
    }
    if (!empty($p['campana'])) {
        $t .= 'Campaña: ' . $p['campana']['titulo'] . "\n";
    }

    $t .= "\n" . home_url('/') . "\n";
    return $t;
}

/**
 * Fallback de texto plano para clientes que no renderizan HTML.
 */
add_action('phpmailer_init', 'billetera_summary_phpmailer_alt_body');
function billetera_summary_phpmailer_alt_body($phpmailer) {
    if (!empty($GLOBALS['billetera_summary_alt_body'])) {
        $phpmailer->AltBody = $GLOBALS['billetera_summary_alt_body'];
    }
}

// ============================================================================
// Encolado
// ============================================================================

/**
 * Encola un correo por cada asesor con email para la última semana.
 * Devuelve un resumen del resultado.
 */
function billetera_summary_seed_queue($force = false) {
    global $wpdb;
    $table = billetera_summary_queue_table();

    list($week_start, $week_end) = billetera_summary_last_week_range();

    if (!$force && !billetera_summary_is_active()) {
        return ['inserted' => 0, 'skipped' => true, 'reason' => 'inactivo', 'week_start' => $week_start, 'week_end' => $week_end];
    }

    if (!$force && get_option('billetera_summary_ultima_semana') === $week_start) {
        return ['inserted' => 0, 'skipped' => true, 'reason' => 'ya_encolada', 'week_start' => $week_start, 'week_end' => $week_end];
    }

    $existentes = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT user_id FROM $table WHERE week_start = %s",
        $week_start
    ));
    $existentes = array_map('intval', (array) $existentes);

    $inserted = 0;
    $offset   = 0;
    $chunk    = 500;

    while (true) {
        $users = get_users([
            'role'    => 'asesor',
            'fields'  => ['ID', 'user_email'],
            'number'  => $chunk,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        if (empty($users)) {
            break;
        }
        $offset += $chunk;

        $placeholders = [];
        $params       = [];
        $now          = current_time('mysql');

        foreach ($users as $u) {
            $uid   = intval($u->ID);
            $email = sanitize_email($u->user_email);

            if (!$email || !is_email($email) || in_array($uid, $existentes, true)) {
                continue;
            }

            $placeholders[] = '(%d, %s, %s, %s, %s, 0, %s)';
            array_push($params, $uid, $email, $week_start, $week_end, 'pending', $now);
            $inserted++;
        }

        if ($placeholders) {
            $sql = "INSERT INTO $table (user_id, email, week_start, week_end, status, attempts, created_at) VALUES "
                . implode(',', $placeholders);
            $wpdb->query($wpdb->prepare($sql, ...$params));
        }

        if (count($users) < $chunk) {
            break;
        }
    }

    update_option('billetera_summary_ultima_semana', $week_start, false);

    return ['inserted' => $inserted, 'skipped' => false, 'week_start' => $week_start, 'week_end' => $week_end];
}

// ============================================================================
// Worker
// ============================================================================

function billetera_summary_process_batch($force = false) {
    global $wpdb;
    $table = billetera_summary_queue_table();

    if (!$force && !billetera_summary_is_active()) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'reason' => 'inactivo'];
    }

    if (get_transient('billetera_summary_lock')) {
        return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'reason' => 'bloqueado'];
    }
    set_transient('billetera_summary_lock', 1, 4 * MINUTE_IN_SECONDS);

    // Tope por hora móvil y tope por día: nunca superar "por_hora" en los
    // últimos 60 min ni "por_dia" en el día calendario local.
    if ($force) {
        $per_run = billetera_summary_per_run();
    } else {
        $en_ultima_hora = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE status = 'sent' AND sent_at >= %s",
            (clone billetera_stats_local_now())->modify('-1 hour')->format('Y-m-d H:i:s')
        ));
        $cupo = billetera_summary_por_hora() - $en_ultima_hora;
        if ($cupo <= 0) {
            delete_transient('billetera_summary_lock');
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'reason' => 'cupo_hora'];
        }
        $per_run = min(billetera_summary_per_run(), $cupo);

        $por_dia = billetera_summary_por_dia();
        if ($por_dia > 0) {
            $enviados_hoy = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = 'sent' AND sent_at >= %s",
                billetera_stats_local_now()->format('Y-m-d 00:00:00')
            ));
            $cupo_dia = $por_dia - $enviados_hoy;
            if ($cupo_dia <= 0) {
                delete_transient('billetera_summary_lock');
                return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'reason' => 'cupo_dia'];
            }
            $per_run = min($per_run, $cupo_dia);
        }
    }

    $rows    = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
        $per_run
    ));

    $sent   = 0;
    $failed = 0;

    foreach ($rows as $row) {
        if (billetera_summary_send_one($row)) {
            $sent++;
        } else {
            $failed++;
        }
    }

    delete_transient('billetera_summary_lock');

    return ['processed' => count($rows), 'sent' => $sent, 'failed' => $failed];
}

/**
 * Envía el resumen de una fila de la cola. Actualiza su estado.
 * $override_email fuerza el destinatario (para pruebas).
 */
function billetera_summary_send_one($row, $override_email = '') {
    global $wpdb;
    $table = billetera_summary_queue_table();

    $user = get_userdata(intval($row->user_id));
    if (!$user || !is_email($user->user_email)) {
        $wpdb->update(
            $table,
            ['status' => 'skipped', 'error' => 'Usuario sin email válido', 'sent_at' => current_time('mysql')],
            ['id' => $row->id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        return false;
    }

    $to = $override_email !== '' ? $override_email : $user->user_email;

    $stats   = billetera_summary_stats_usuario($user->ID, $row->week_start, $row->week_end);
    $subject = billetera_summary_subject($row->week_start, $row->week_end, $user);
    $html    = billetera_summary_render_email($user, $stats, $row->week_start, $row->week_end);
    $text    = billetera_summary_render_text($user, $stats, $row->week_start, $row->week_end);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . billetera_summary_from_name() . ' <' . billetera_summary_from_email() . '>',
    ];

    $GLOBALS['billetera_summary_alt_body'] = $text;
    $ok = wp_mail($to, $subject, $html, $headers);
    unset($GLOBALS['billetera_summary_alt_body']);

    $attempts = intval($row->attempts) + 1;

    if ($ok) {
        $wpdb->update(
            $table,
            ['status' => 'sent', 'attempts' => $attempts, 'error' => null, 'sent_at' => current_time('mysql')],
            ['id' => $row->id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
        return true;
    }

    $status = $attempts >= 3 ? 'failed' : 'pending';
    $wpdb->update(
        $table,
        ['status' => $status, 'attempts' => $attempts, 'error' => 'wp_mail() devolvió false'],
        ['id' => $row->id],
        ['%s', '%d', '%s'],
        ['%d']
    );
    return false;
}

// ============================================================================
// Helpers para la pantalla admin
// ============================================================================

function billetera_summary_sample_users($limit = 200) {
    global $wpdb;
    $v = $wpdb->prefix . 'billetera_ventas';

    $ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT usuario_id
        FROM $v
        WHERE creado_en >= %s
        ORDER BY usuario_id DESC
        LIMIT %d
    ", (clone billetera_stats_local_now())->modify('-60 days')->format('Y-m-d H:i:s'), $limit));

    if (!$ids) {
        return get_users([
            'role'    => 'asesor',
            'fields'  => ['ID', 'display_name', 'user_email'],
            'number'  => 50,
            'orderby' => 'ID',
            'order'   => 'DESC',
        ]);
    }

    return get_users([
        'include' => array_map('intval', $ids),
        'fields'  => ['ID', 'display_name', 'user_email'],
    ]);
}

// ============================================================================
// Preview (admin_post)
// ============================================================================

add_action('admin_post_billetera_summary_preview', 'billetera_summary_preview_output');
function billetera_summary_preview_output() {
    // OCULTAMIENTO TEMPORAL para administrador_hyundai (bloquea acceso directo por URL).
    $cap_resumen = defined('BILLETERA_CATALOG_CAP') ? BILLETERA_CATALOG_CAP : 'manage_options';
    if ((function_exists('billetera_catalog_is_hyundai_admin') && billetera_catalog_is_hyundai_admin()) || !current_user_can($cap_resumen)) {
        wp_die('Sin permiso');
    }
    check_admin_referer('billetera_summary_preview');

    $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $user = get_userdata($uid);
    if (!$user) {
        wp_die('Usuario inválido');
    }

    list($week_start, $week_end) = billetera_summary_last_week_range();
    // ?variant=cierre fuerza una semana que cruza fin de mes para previsualizar el template de cierre.
    if (isset($_GET['variant']) && $_GET['variant'] === 'cierre' && !billetera_summary_is_closing_week($week_start, $week_end)) {
        try {
            $tz = wp_timezone();
            $ref = billetera_stats_local_now()->modify('first day of last month')->modify('last day of this month');
            $monday = (clone $ref)->modify('monday this week')->setTime(0, 0, 0);
            // Si el lunes cae después del último día, retrocede una semana.
            if ($monday > $ref) {
                $monday->modify('-7 days');
            }
            $week_start = $monday->format('Y-m-d H:i:s');
            $week_end = (clone $monday)->modify('+7 days')->format('Y-m-d H:i:s');
        } catch (Exception $ex) {
        }
    }
    $stats = billetera_summary_stats_usuario($uid, $week_start, $week_end);

    header('Content-Type: text/html; charset=UTF-8');
    echo billetera_summary_render_email($user, $stats, $week_start, $week_end);
    exit;
}

// ============================================================================
// Pantalla admin
// ============================================================================

function billetera_render_resumen_page() {
    // OCULTAMIENTO TEMPORAL para administrador_hyundai (bloquea acceso directo por URL).
    $cap_resumen = defined('BILLETERA_CATALOG_CAP') ? BILLETERA_CATALOG_CAP : 'manage_options';
    if ((function_exists('billetera_catalog_is_hyundai_admin') && billetera_catalog_is_hyundai_admin()) || !current_user_can($cap_resumen)) {
        wp_die('Sin permiso');
    }

    global $wpdb;
    $table = billetera_summary_queue_table();
    $msg   = '';
    $err   = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bc_action'])) {
        check_admin_referer('billetera_catalog');
        $action = sanitize_key($_POST['bc_action']);

        if ($action === 'save_config') {
            $prev_dow  = billetera_summary_seed_dow();
            $prev_hour = billetera_summary_seed_hour();

            update_option('billetera_summary_activo', isset($_POST['activo']) ? 1 : 0, false);
            update_option('billetera_summary_por_hora', max(1, intval($_POST['por_hora'] ?? 60)), false);
            update_option('billetera_summary_por_dia', max(0, intval($_POST['por_dia'] ?? 0)), false);
            update_option('billetera_summary_seed_dow', max(0, min(6, intval($_POST['seed_dow'] ?? 0))), false);
            update_option('billetera_summary_seed_hour', max(0, min(23, intval($_POST['seed_hour'] ?? 0))), false);
            update_option('billetera_summary_test_email', sanitize_email(wp_unslash($_POST['test_email'] ?? '')), false);
            update_option('billetera_summary_from_email', sanitize_email(wp_unslash($_POST['from_email'] ?? '')), false);
            update_option('billetera_summary_from_name', sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')), false);

            if (billetera_summary_seed_dow() !== $prev_dow || billetera_summary_seed_hour() !== $prev_hour) {
                billetera_summary_reschedule_seed();
            }

            $msg = 'Configuración guardada.';
        } elseif ($action === 'seed_now') {
            $res = billetera_summary_seed_queue(true);
            $msg = 'Cola generada: ' . intval($res['inserted']) . ' correo(s) encolado(s) para ' . billetera_summary_week_label($res['week_start'], $res['week_end']) . '.';
        } elseif ($action === 'process_now') {
            $res = billetera_summary_process_batch(true);
            $msg = 'Lote procesado: ' . intval($res['processed']) . ' correo(s) · enviados ' . intval($res['sent']) . ' · fallidos ' . intval($res['failed']) . '.';
        } elseif ($action === 'requeue_failed') {
            $n = $wpdb->query("UPDATE $table SET status = 'pending', attempts = 0, error = NULL WHERE status = 'failed'");
            $msg = intval($n) . ' correo(s) fallido(s) devueltos a la cola.';
        } elseif ($action === 'clear_queue') {
            $n = $wpdb->query("DELETE FROM $table WHERE status IN ('sent', 'failed', 'skipped')");
            $msg = intval($n) . ' registro(s) limpiados.';
        } elseif ($action === 'send_test') {
            $test_to = billetera_summary_test_email();
            if (!$test_to) {
                $err = 'Configura primero un correo de prueba.';
            } else {
                $uid  = intval($_POST['test_user_id'] ?? 0);
                $user = $uid ? get_userdata($uid) : null;
                if (!$user) {
                    $err = 'Selecciona un asesor válido para la prueba.';
                } else {
                    list($week_start, $week_end) = billetera_summary_last_week_range();
                    $stats   = billetera_summary_stats_usuario($user->ID, $week_start, $week_end);
                    $subject = '[PRUEBA] ' . billetera_summary_subject($week_start, $week_end, $user);
                    $html    = billetera_summary_render_email($user, $stats, $week_start, $week_end);
                    $text    = billetera_summary_render_text($user, $stats, $week_start, $week_end);

                    $headers = [
                        'Content-Type: text/html; charset=UTF-8',
                        'From: ' . billetera_summary_from_name() . ' <' . billetera_summary_from_email() . '>',
                    ];

                    $GLOBALS['billetera_summary_alt_body'] = $text;
                    $ok = wp_mail($test_to, $subject, $html, $headers);
                    unset($GLOBALS['billetera_summary_alt_body']);

                    if ($ok) {
                        $msg = 'Correo de prueba enviado a ' . $test_to . ' (datos de ' . $user->display_name . ').';
                    } else {
                        $err = 'wp_mail() no pudo enviar el correo de prueba. Revisa FluentSMTP.';
                    }
                }
            }
        }
    }

    $activo     = billetera_summary_is_active();
    $por_hora   = billetera_summary_por_hora();
    $por_dia    = billetera_summary_por_dia();
    $seed_dow   = billetera_summary_seed_dow();
    $seed_hour  = billetera_summary_seed_hour();
    $test_email = billetera_summary_test_email();
    $from_email = get_option('billetera_summary_from_email', '');
    $from_name  = get_option('billetera_summary_from_name', '');
    $per_run    = billetera_summary_per_run();

    list($week_start, $week_end) = billetera_summary_last_week_range();
    $week_label = billetera_summary_week_label($week_start, $week_end);

    $counts_raw = $wpdb->get_results("SELECT status, COUNT(*) AS c FROM $table GROUP BY status");
    $counts = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($counts_raw as $c) {
        $counts[$c->status] = intval($c->c);
    }

    $next_worker = wp_next_scheduled('billetera_summary_worker_hook');
    $next_seed   = wp_next_scheduled('billetera_summary_seed_hook');

    $sample_users = billetera_summary_sample_users();

    $preview_url = wp_nonce_url(
        admin_url('admin-post.php?action=billetera_summary_preview&user_id=' . (int) ($sample_users ? $sample_users[0]->ID : 0)),
        'billetera_summary_preview'
    );
    $preview_cierre_url = wp_nonce_url(
        admin_url('admin-post.php?action=billetera_summary_preview&user_id=' . (int) ($sample_users ? $sample_users[0]->ID : 0) . '&variant=cierre'),
        'billetera_summary_preview'
    );

    billetera_bc_open('Resumen semanal', 'Envío automático del resumen de comisiones por correo (FluentSMTP + Brevo).');
    billetera_bc_notices($msg, $err);
    ?>
    <div class="bc-card">
        <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
            <div>
                <strong>Estado:</strong>
                <?php if ($activo): ?>
                    <span style="color:#146C43;font-weight:bold;">Activo</span>
                <?php else: ?>
                    <span style="color:#b3352a;font-weight:bold;">Inactivo</span>
                <?php endif; ?>
            </div>
            <div><strong>Semana a resumir:</strong> <?php echo esc_html($week_label); ?></div>
            <div><strong>Ritmo:</strong> <?php echo intval($por_hora); ?>/hora<?php echo $por_dia > 0 ? ' · ' . intval($por_dia) . '/día' : ''; ?> (<?php echo intval($per_run); ?> por corrida)</div>
            <div><strong>Envío automático:</strong> <?php echo esc_html(billetera_summary_dow_nombre($seed_dow)); ?> a las <?php echo sprintf('%02d:00', $seed_hour); ?></div>
        </div>
        <p style="margin:14px 0 0;color:#5b6b80;">
            Próximo envío (worker): <?php echo $next_worker ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_worker), 'Y-m-d H:i')) : '—'; ?> ·
            Próximo encolado semanal: <?php echo $next_seed ? esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_seed), 'Y-m-d H:i')) : '—'; ?>
        </p>
    </div>

    <div class="bc-card">
        <h2 style="margin-top:0;">Cola</h2>
        <p>
            Pendientes: <strong><?php echo intval($counts['pending']); ?></strong> ·
            Enviados: <strong><?php echo intval($counts['sent']); ?></strong> ·
            Fallidos: <strong><?php echo intval($counts['failed']); ?></strong> ·
            Omitidos: <strong><?php echo intval($counts['skipped']); ?></strong>
        </p>
        <form method="post" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="seed_now">
            <button type="submit" class="button">Generar cola ahora</button>
        </form>
        <form method="post" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="process_now">
            <button type="submit" class="button button-primary">Procesar lote ahora</button>
        </form>
        <form method="post" style="display:inline-block;margin-right:8px;">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="requeue_failed">
            <button type="submit" class="button">Reintentar fallidos</button>
        </form>
        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="clear_queue">
            <button type="submit" class="button bc-danger" onclick="return confirm('¿Limpiar registros enviados/fallidos?');">Limpiar historial</button>
        </form>
    </div>

    <div class="bc-card">
        <h2 style="margin-top:0;">Configuración</h2>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="save_config">

            <div class="bc-field">
                <label class="bc-label">
                    <input type="checkbox" name="activo" value="1" <?php checked($activo); ?>>
                    Enviar automáticamente cada semana
                </label>
            </div>
            <div class="bc-field">
                <label class="bc-label">Día del envío</label>
                <select name="seed_dow" class="bc-input">
                    <?php for ($d = 0; $d <= 6; $d++): ?>
                        <option value="<?php echo $d; ?>" <?php selected($seed_dow, $d); ?>><?php echo esc_html(billetera_summary_dow_nombre($d)); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Hora del envío (0–23)</label>
                <select name="seed_hour" class="bc-input">
                    <?php for ($h = 0; $h <= 23; $h++): ?>
                        <option value="<?php echo $h; ?>" <?php selected($seed_hour, $h); ?>><?php echo sprintf('%02d:00', $h); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="bc-field">
                <label class="bc-label">Correos por hora</label>
                <input type="number" min="1" max="600" name="por_hora" class="bc-input" value="<?php echo intval($por_hora); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Máximo por día (0 = sin límite)</label>
                <input type="number" min="0" max="100000" name="por_dia" class="bc-input" value="<?php echo intval($por_dia); ?>">
                <p style="margin:6px 0 0;color:#8494a6;font-size:12px;">Ponlo al límite de tu plan de Brevo (ej. 300 en el gratis). Si la cola no termina, sigue al día siguiente.</p>
            </div>
            <div class="bc-field">
                <label class="bc-label">Correo de prueba (todos los tests llegan aquí)</label>
                <input type="email" name="test_email" class="bc-input" value="<?php echo esc_attr($test_email); ?>" placeholder="pruebas@tudominio.com">
            </div>
            <div class="bc-field">
                <label class="bc-label">Remitente: email (opcional)</label>
                <input type="email" name="from_email" class="bc-input" value="<?php echo esc_attr($from_email); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
            </div>
            <div class="bc-field">
                <label class="bc-label">Remitente: nombre (opcional)</label>
                <input type="text" name="from_name" class="bc-input" value="<?php echo esc_attr($from_name); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary">Guardar configuración</button>
            </div>
        </form>
    </div>

    <div class="bc-card">
        <h2 style="margin-top:0;">Probar correo</h2>
        <p style="color:#5b6b80;">Envía el resumen de un asesor a tu correo de prueba. Usa los datos reales de la semana <?php echo esc_html($week_label); ?>.</p>
        <form method="post" class="bc-grid">
            <?php wp_nonce_field('billetera_catalog'); ?>
            <input type="hidden" name="bc_action" value="send_test">
            <div class="bc-field">
                <label class="bc-label">Asesor de muestra</label>
                <select name="test_user_id" class="bc-input">
                    <?php if (!$sample_users): ?>
                        <option value="0">No hay asesores</option>
                    <?php else: foreach ($sample_users as $u): ?>
                        <option value="<?php echo intval($u->ID); ?>"><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
            <div class="bc-field bc-field--actions">
                <button type="submit" class="button button-primary" <?php disabled(!$test_email); ?>>Enviar correo de prueba</button>
                <a class="button" href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">Ver preview semanal</a>
                <a class="button" href="<?php echo esc_url($preview_cierre_url); ?>" target="_blank" rel="noopener">Ver preview cierre</a>
            </div>
        </form>
        <?php if (!$test_email): ?>
            <p style="color:#b3352a;">Configura un correo de prueba arriba para habilitar el envío.</p>
        <?php endif; ?>
    </div>
    <?php
    billetera_bc_close();
}
