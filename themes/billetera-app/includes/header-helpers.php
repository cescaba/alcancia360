<?php
/**
 * Helpers para el header de la app (Mi alcancia 360)
 * - Iniciales del avatar
 * - Racha de ventas
 * - Notificaciones
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Iniciales para el avatar (ej. "Marco Zevallos" → "MZ")
 */
function billetera_get_iniciales($user) {
    $parts = array_values(array_filter([
        $user->first_name,
        $user->last_name,
    ]));

    $nombre = !empty($parts) ? implode(' ', $parts) : $user->display_name;
    $palabras = preg_split('/\s+/', trim($nombre));

    $iniciales = '';
    foreach ($palabras as $i => $palabra) {
        if ($i >= 2) {
            break;
        }
        $iniciales .= mb_strtoupper(mb_substr($palabra, 0, 1));
    }

    return $iniciales !== '' ? $iniciales : 'U';
}

/**
 * URL de una página por su template (page-billetera-360-*.php)
 */
function billetera_get_template_url($template_name) {
    global $wpdb;

    $page = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
         WHERE {$wpdb->posts}.post_type = 'page'
         AND {$wpdb->posts}.post_status = 'publish'
         AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
         AND {$wpdb->postmeta}.meta_value = %s
         LIMIT 1",
        $template_name
    ));

    return $page ? get_permalink($page->ID) : '#';
}

/**
 * Racha de ventas: días consecutivos con al menos 1 venta.
 * Regla: 1 venta al día mantiene la racha; un día sin ventas la rompe.
 */
function billetera_get_racha($user_id) {
    global $wpdb;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';

    $dias = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(creado_en) AS dia
         FROM $ventas_table
         WHERE usuario_id = %d
         ORDER BY dia DESC
         LIMIT 366",
        $user_id
    ));

    if (empty($dias)) {
        return 0;
    }

    $hoy = new DateTime('today', wp_timezone());
    $cursor = clone $hoy;

    // Filtrar solo días de lunes (1) a sábado (6), excluir domingo (0)
    $dias_laborales = [];
    foreach ($dias as $dia) {
        $dt = new DateTime($dia, wp_timezone());
        $dia_semana = $dt->format('w'); // 0=domingo, 1=lunes, ..., 6=sábado
        if ($dia_semana != 0) { // Si no es domingo
            $dias_laborales[] = $dia;
        }
    }

    if (empty($dias_laborales)) {
        return 0;
    }

    // Retroceder desde hoy ignorando domingos
    $set = array_flip($dias_laborales);
    $racha = 0;

    while (true) {
        $dia_semana = $cursor->format('w'); // 0=domingo, 1=lunes, ..., 6=sábado

        // Si es domingo, saltar al sábado anterior
        if ($dia_semana == 0) {
            $cursor->modify('-1 day');
            continue;
        }

        // Si el día laboral existe en ventas, contar
        if (isset($set[$cursor->format('Y-m-d')])) {
            $racha++;
            $cursor->modify('-1 day');
        } else {
            // Si no hay venta en este día laboral, rompe la racha
            break;
        }
    }

    return $racha;
}

/**
 * Notificaciones recientes según el rol del usuario.
 * Devuelve un array de ['text' => ..., 'time' => ...]
 */
function billetera_get_notificaciones($user_id) {
    global $wpdb;
    $user = get_userdata($user_id);
    if (!$user) {
        return [];
    }

    $roles = (array) $user->roles;
    $ventas_table = $wpdb->prefix . 'billetera_ventas';
    $subs_table = $wpdb->prefix . 'billetera_subcategorias';
    $categorias_table = $wpdb->prefix . 'billetera_categorias';
    $notificaciones = [];

    if (in_array('jefe_venta', $roles, true)) {
        // Sucursales donde este usuario es jefe de venta
        $tienda_ids = [];
        $tiendas = get_posts([
            'post_type' => 'tienda',
            'numberposts' => -1,
            'post_status' => 'publish',
        ]);

        foreach ($tiendas as $t) {
            $jefes = get_post_meta($t->ID, '_jefes_venta_asociados', true);
            if (!is_array($jefes)) {
                $jefes = $jefes ? [$jefes] : [];
            }
            if (in_array((string) $user_id, array_map('strval', $jefes), true)) {
                $tienda_ids[] = $t->ID;
            }
        }

        if (empty($tienda_ids)) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($tienda_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.creado_en, v.monto_comision_sol, v.bonus_multiplier,
                    s.nombre AS subcategoria, u.display_name AS asesor_nombre
             FROM $ventas_table v
             INNER JOIN $subs_table s ON v.subcategoria_id = s.id
             INNER JOIN {$wpdb->users} u ON v.usuario_id = u.ID
             INNER JOIN {$wpdb->usermeta} um ON um.user_id = v.usuario_id AND um.meta_key = '_tienda_asociada'
             WHERE um.meta_value IN ($ph)
             ORDER BY v.creado_en DESC
             LIMIT 5",
            ...$tienda_ids
        ));

        foreach ($rows as $r) {
            $monto = floatval($r->monto_comision_sol) * floatval($r->bonus_multiplier);
            $notificaciones[] = [
                'text' => 'El asesor ' . $r->asesor_nombre . ' registró ' . $r->subcategoria
                    . ' (+S/ ' . number_format($monto, 2, '.', ',') . ' para ti)',
                'time' => $r->creado_en,
            ];
        }

        return $notificaciones;
    }

    if (in_array('asesor', $roles, true)) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT v.creado_en, v.monto_comision_sol, v.bonus_multiplier,
                    s.nombre AS subcategoria, c.nombre AS categoria
             FROM $ventas_table v
             INNER JOIN $subs_table s ON v.subcategoria_id = s.id
             INNER JOIN $categorias_table c ON s.categoria_id = c.id
             WHERE v.usuario_id = %d
             ORDER BY v.creado_en DESC
             LIMIT 5",
            $user_id
        ));

        foreach ($rows as $r) {
            $monto = floatval($r->monto_comision_sol) * floatval($r->bonus_multiplier);
            $texto = 'Venta acreditada: ' . $r->subcategoria
                . ' (+S/ ' . number_format($monto, 2, '.', ',') . ')';

            if ($r->categoria === 'Prepagados' && floatval($r->bonus_multiplier) > 1) {
                $texto = '¡Bonus 2x aplicado en ' . $r->subcategoria
                    . '! (+S/ ' . number_format($monto, 2, '.', ',') . ')';
            }

            $notificaciones[] = [
                'text' => $texto,
                'time' => $r->creado_en,
            ];
        }

        return $notificaciones;
    }

    return [];
}
