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
        wp_schedule_event(billetera_summary_next_sunday_ts(), 'weekly', 'billetera_summary_seed_hook');
    }
}

function billetera_summary_unschedule() {
    wp_clear_scheduled_hook('billetera_summary_worker_hook');
    wp_clear_scheduled_hook('billetera_summary_seed_hook');
}

/**
 * Timestamp del próximo domingo 00:00 (hora local del sitio).
 */
function billetera_summary_next_sunday_ts() {
    $now  = billetera_stats_local_now();
    $dow  = (int) $now->format('N'); // 1 (lun) .. 7 (dom)
    $days = (7 - $dow) % 7;
    if ($days === 0) {
        $days = 7;
    }
    $target = (clone $now)->modify("+$days days")->setTime(0, 0, 0);
    return $target->getTimestamp();
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
// Render del correo
// ============================================================================

function billetera_summary_logo_url() {
    $url = get_template_directory_uri() . '/assets/img/logo-gildemeister.png';
    return apply_filters('billetera_summary_logo_url', $url);
}

function billetera_summary_subject($week_start, $week_end, $user = null) {
    return 'Tu resumen de comisiones · ' . billetera_summary_week_label($week_start, $week_end);
}

function billetera_summary_render_email($user, $stats, $week_start, $week_end) {
    $logo   = billetera_summary_logo_url();
    $nombre = $user ? $user->display_name : '';
    $label  = billetera_summary_week_label($week_start, $week_end);
    $site   = get_bloginfo('name');
    $url    = home_url('/');

    $tiene = $stats['ventas'] > 0;

    $rows = '';
    foreach ($stats['por_linea'] as $l) {
        $rows .= '<tr>'
            . '<td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:14px;color:#0e2438;">' . esc_html($l['label']) . '</td>'
            . '<td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:13px;color:#5b6b80;text-align:center;">' . intval($l['ventas']) . '</td>'
            . '<td style="padding:10px 0;border-bottom:1px solid #eef2f7;font-size:14px;color:#0a6cb4;text-align:right;font-weight:bold;">' . esc_html(billetera_summary_fmt_sol($l['total'])) . '</td>'
            . '</tr>';
    }

    $desglose = '';
    if ($rows !== '') {
        $desglose = '
        <tr><td style="padding:8px 28px 4px;">
          <div style="font-size:13px;font-weight:bold;letter-spacing:.06em;text-transform:uppercase;color:#5b6b80;margin:12px 0 4px;">Desglose por línea</div>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            <tr>
              <td style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8494a6;padding-bottom:6px;">Línea</td>
              <td style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8494a6;text-align:center;padding-bottom:6px;">Ventas</td>
              <td style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#8494a6;text-align:right;padding-bottom:6px;">Comisión</td>
            </tr>
            ' . $rows . '
          </table>
        </td></tr>';
    }

    $cuerpo = $tiene
        ? '
        <tr><td style="padding:24px 28px 8px;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
            <td width="33%" style="padding:0 6px 0 0;vertical-align:top;">
              <div style="background:#f3f6fa;border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:24px;font-weight:bold;color:#0a6cb4;">' . intval($stats['ventas']) . '</div>
                <div style="font-size:12px;color:#5b6b80;margin-top:2px;">Ventas</div>
              </div>
            </td>
            <td width="33%" style="padding:0 3px;vertical-align:top;">
              <div style="background:#f3f6fa;border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:24px;font-weight:bold;color:#0a6cb4;">' . intval($stats['unidades']) . '</div>
                <div style="font-size:12px;color:#5b6b80;margin-top:2px;">Unidades</div>
              </div>
            </td>
            <td width="33%" style="padding:0 0 0 6px;vertical-align:top;">
              <div style="background:#f3f6fa;border-radius:10px;padding:16px;text-align:center;">
                <div style="font-size:20px;font-weight:bold;color:#0a6cb4;">' . esc_html(billetera_summary_fmt_sol($stats['ticket'])) . '</div>
                <div style="font-size:12px;color:#5b6b80;margin-top:2px;">Ticket promedio</div>
              </div>
            </td>
          </tr></table>
        </td></tr>
        ' . $desglose . '
        <tr><td style="padding:20px 28px 4px;font-size:14px;color:#5b6b80;line-height:1.5;">
          ¡Buen trabajo esta semana! Sigue registrando tus ventas al cierre para que tu alcancía crezca.
        </td></tr>'
        : '
        <tr><td style="padding:28px;font-size:15px;color:#5b6b80;line-height:1.6;">
          Esta semana no registramos ventas con comisión a tu nombre.<br>
          Cuando cierres una venta, regístrala en la app para verla aquí.
        </td></tr>';

    $html = '<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . esc_html($site) . '</title>
</head>
<body style="margin:0;padding:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#0e2438;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #dce3eb;">
        <tr><td style="padding:20px 28px;border-bottom:3px solid #f5c563;">
          <img src="' . esc_url($logo) . '" alt="' . esc_attr($site) . '" height="42" style="height:42px;display:block;border:0;">
        </td></tr>
        <tr><td style="background:#0a2c4e;padding:26px 28px;color:#ffffff;">
          <div style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#9fc3e0;">Resumen semanal de comisiones</div>
          <div style="font-size:14px;color:#cdd9e6;margin-top:6px;">Hola ' . esc_html($nombre) . ' · ' . esc_html($label) . '</div>
          <div style="font-size:34px;font-weight:bold;color:#f5c563;margin-top:14px;">' . esc_html(billetera_summary_fmt_sol($stats['total'])) . '</div>
          <div style="font-size:12px;color:#9fc3e0;margin-top:2px;">comisión acumulada de la semana</div>
        </td></tr>
        ' . $cuerpo . '
        <tr><td style="padding:20px 28px 28px;">
          <a href="' . esc_url($url) . '" style="display:inline-block;background:#0a6cb4;color:#ffffff;text-decoration:none;font-size:14px;font-weight:bold;padding:12px 22px;border-radius:8px;">Ver mi alcancía</a>
        </td></tr>
        <tr><td style="padding:18px 28px;background:#f3f6fa;border-top:1px solid #dce3eb;">
          <div style="font-size:12px;color:#8494a6;line-height:1.6;">
            &copy; ' . date_i18n('Y') . ' ' . esc_html($site) . '. Todos los derechos reservados.<br>
            Este es un correo automático, por favor no responder.
          </div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

    return $html;
}

function billetera_summary_render_text($user, $stats, $week_start, $week_end) {
    $nombre = $user ? $user->display_name : '';
    $label  = billetera_summary_week_label($week_start, $week_end);

    $t = "Resumen semanal de comisiones\n";
    $t .= "Hola $nombre\n";
    $t .= "Semana: $label\n\n";
    $t .= 'Comisión total: ' . billetera_summary_fmt_sol($stats['total']) . "\n";
    $t .= 'Ventas: ' . intval($stats['ventas']) . "\n";
    $t .= 'Unidades: ' . intval($stats['unidades']) . "\n\n";

    if (!empty($stats['por_linea'])) {
        $t .= "Desglose por línea:\n";
        foreach ($stats['por_linea'] as $l) {
            $t .= '- ' . $l['label'] . ': ' . billetera_summary_fmt_sol($l['total']) . "\n";
        }
    } else {
        $t .= "Esta semana no registramos ventas con comisión a tu nombre.\n";
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

    // Tope por hora móvil: nunca enviar más de "por_hora" en los últimos 60
    // minutos, sin importar con qué frecuencia se dispare el cron.
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
    if (!current_user_can(BILLETERA_CATALOG_CAP)) {
        wp_die('Sin permiso');
    }
    check_admin_referer('billetera_summary_preview');

    $uid = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    $user = get_userdata($uid);
    if (!$user) {
        wp_die('Usuario inválido');
    }

    list($week_start, $week_end) = billetera_summary_last_week_range();
    $stats = billetera_summary_stats_usuario($uid, $week_start, $week_end);

    header('Content-Type: text/html; charset=UTF-8');
    echo billetera_summary_render_email($user, $stats, $week_start, $week_end);
    exit;
}

// ============================================================================
// Pantalla admin
// ============================================================================

function billetera_render_resumen_page() {
    if (!current_user_can(BILLETERA_CATALOG_CAP)) {
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
            update_option('billetera_summary_activo', isset($_POST['activo']) ? 1 : 0, false);
            update_option('billetera_summary_por_hora', max(1, intval($_POST['por_hora'] ?? 60)), false);
            update_option('billetera_summary_test_email', sanitize_email(wp_unslash($_POST['test_email'] ?? '')), false);
            update_option('billetera_summary_from_email', sanitize_email(wp_unslash($_POST['from_email'] ?? '')), false);
            update_option('billetera_summary_from_name', sanitize_text_field(wp_unslash($_POST['from_name'] ?? '')), false);
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
            <div><strong>Ritmo:</strong> <?php echo intval($por_hora); ?>/hora (<?php echo intval($per_run); ?> por corrida)</div>
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
                    Enviar automáticamente cada domingo
                </label>
            </div>
            <div class="bc-field">
                <label class="bc-label">Correos por hora</label>
                <input type="number" min="1" max="600" name="por_hora" class="bc-input" value="<?php echo intval($por_hora); ?>">
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
                <a class="button" href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">Ver preview</a>
            </div>
        </form>
        <?php if (!$test_email): ?>
            <p style="color:#b3352a;">Configura un correo de prueba arriba para habilitar el envío.</p>
        <?php endif; ?>
    </div>
    <?php
    billetera_bc_close();
}
