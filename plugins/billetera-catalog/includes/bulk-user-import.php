<?php
/**
 * Funciones para importación masiva de usuarios
 */

if (!defined('ABSPATH')) exit;

/**
 * PASO 1: Crear usuarios masivamente con roles
 *
 * Uso:
 * $usuarios = [
 *   ['user_login' => 'juan.asesor', 'user_email' => 'juan@example.com', 'first_name' => 'Juan', 'last_name' => 'Pérez', 'rol' => 'asesor', 'password' => 'pass123'],
 *   ['user_login' => 'maria.jefe', 'user_email' => 'maria@example.com', 'first_name' => 'María', 'last_name' => 'García', 'rol' => 'jefe_venta', 'password' => 'pass456'],
 * ];
 * $resultado = billetera_bulk_create_users($usuarios);
 */
function billetera_bulk_create_users($usuarios_data) {
    if (!is_array($usuarios_data) || empty($usuarios_data)) {
        return ['success' => false, 'message' => 'Array de usuarios vacío', 'usuarios' => []];
    }

    $usuarios_creados = [];
    $errores = [];

    foreach ($usuarios_data as $idx => $user_data) {
        // Validar datos requeridos
        if (empty($user_data['user_login']) || empty($user_data['user_email'])) {
            $errores[] = "Fila $idx: Falta user_login o user_email";
            continue;
        }

        // Verificar que el usuario no exista
        if (username_exists($user_data['user_login'])) {
            $errores[] = "Usuario '{$user_data['user_login']}' ya existe";
            continue;
        }

        if (email_exists($user_data['user_email'])) {
            $errores[] = "Email '{$user_data['user_email']}' ya existe";
            continue;
        }

        // Preparar datos del usuario
        $userdata = [
            'user_login' => sanitize_user($user_data['user_login']),
            'user_email' => sanitize_email($user_data['user_email']),
            'first_name' => sanitize_text_field($user_data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($user_data['last_name'] ?? ''),
            'user_pass' => $user_data['password'] ?? wp_generate_password(12),
            'role' => 'subscriber', // Rol temporal, se actualiza luego
        ];

        // Crear usuario
        $user_id = wp_insert_user($userdata);

        if (is_wp_error($user_id)) {
            $errores[] = "Error al crear '{$user_data['user_login']}': " . $user_id->get_error_message();
            continue;
        }

        // Asignar rol
        $rol = sanitize_text_field($user_data['rol'] ?? 'asesor');
        $user = new WP_User($user_id);
        $user->set_role($rol);

        $usuarios_creados[] = [
            'user_id' => $user_id,
            'user_login' => $user_data['user_login'],
            'user_email' => $user_data['user_email'],
            'rol' => $rol,
            'password' => $userdata['user_pass'],
        ];
    }

    return [
        'success' => count($usuarios_creados) > 0,
        'message' => count($usuarios_creados) . " usuarios creados, " . count($errores) . " errores",
        'usuarios' => $usuarios_creados,
        'errores' => $errores,
    ];
}

/**
 * PASO 2: Asignar campos personalizados a usuarios
 *
 * Uso:
 * $asignaciones = [
 *   ['user_id' => 123, 'billetera_hyu' => 1, 'billetera_tipo' => 'Venta', 'tienda_id' => 456],
 *   ['user_id' => 124, 'billetera_jmc' => 1, 'billetera_tipo' => 'Post Venta'],
 * ];
 * $resultado = billetera_bulk_assign_fields($asignaciones);
 */
function billetera_bulk_assign_fields($asignaciones) {
    if (!is_array($asignaciones) || empty($asignaciones)) {
        return ['success' => false, 'message' => 'Array de asignaciones vacío'];
    }

    $completadas = 0;
    $errores = [];

    foreach ($asignaciones as $idx => $asignacion) {
        $user_id = intval($asignacion['user_id'] ?? 0);

        if (!$user_id || !get_user_by('id', $user_id)) {
            $errores[] = "Fila $idx: Usuario $user_id no existe";
            continue;
        }

        // Campos booleanos
        $campos_bool = ['billetera_hyu', 'billetera_hcv', 'billetera_gee', 'billetera_jmc'];
        foreach ($campos_bool as $campo) {
            if (isset($asignacion[$campo])) {
                update_user_meta($user_id, $campo, intval($asignacion[$campo]));
            }
        }

        // Campo select (Tipo)
        if (isset($asignacion['billetera_tipo'])) {
            $tipo = sanitize_text_field($asignacion['billetera_tipo']);
            if (in_array($tipo, ['Venta', 'Post Venta'])) {
                update_user_meta($user_id, 'billetera_tipo', $tipo);
            }
        }

        // Sucursal (opcional)
        if (isset($asignacion['tienda_id'])) {
            $tienda_id = intval($asignacion['tienda_id']);
            if ($tienda_id > 0) {
                update_user_meta($user_id, '_tienda_asociada', $tienda_id);
            }
        }

        $completadas++;
    }

    return [
        'success' => $completadas > 0,
        'message' => "$completadas asignaciones completadas, " . count($errores) . " errores",
        'completadas' => $completadas,
        'errores' => $errores,
    ];
}

/**
 * FUNCIÓN COMBINADA: Crear usuarios + Asignar campos en un paso
 *
 * Uso:
 * $usuarios = [
 *   [
 *     'user_login' => 'juan.asesor',
 *     'user_email' => 'juan@example.com',
 *     'first_name' => 'Juan',
 *     'last_name' => 'Pérez',
 *     'rol' => 'asesor',
 *     'password' => 'pass123',
 *     'billetera_hyu' => 1,
 *     'billetera_tipo' => 'Venta',
 *     'tienda_id' => 456,
 *   ],
 * ];
 * $resultado = billetera_bulk_create_and_assign($usuarios);
 */
function billetera_bulk_create_and_assign($usuarios_data) {
    if (!is_array($usuarios_data) || empty($usuarios_data)) {
        return ['success' => false, 'message' => 'Array vacío'];
    }

    $usuarios_creados = [];
    $asignaciones = [];

    // Primero crear todos los usuarios
    foreach ($usuarios_data as $user_data) {
        $userdata = [
            'user_login' => sanitize_user($user_data['user_login']),
            'user_email' => sanitize_email($user_data['user_email']),
            'first_name' => sanitize_text_field($user_data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($user_data['last_name'] ?? ''),
            'user_pass' => $user_data['password'] ?? wp_generate_password(12),
            'role' => 'subscriber',
        ];

        $user_id = wp_insert_user($userdata);

        if (!is_wp_error($user_id)) {
            // Asignar rol
            $rol = sanitize_text_field($user_data['rol'] ?? 'asesor');
            $user = new WP_User($user_id);
            $user->set_role($rol);

            $usuarios_creados[] = $user_id;

            // Preparar asignaciones
            $asignacion = ['user_id' => $user_id];

            if (isset($user_data['billetera_hyu'])) $asignacion['billetera_hyu'] = intval($user_data['billetera_hyu']);
            if (isset($user_data['billetera_hcv'])) $asignacion['billetera_hcv'] = intval($user_data['billetera_hcv']);
            if (isset($user_data['billetera_gee'])) $asignacion['billetera_gee'] = intval($user_data['billetera_gee']);
            if (isset($user_data['billetera_jmc'])) $asignacion['billetera_jmc'] = intval($user_data['billetera_jmc']);
            if (isset($user_data['billetera_tipo'])) $asignacion['billetera_tipo'] = $user_data['billetera_tipo'];
            if (isset($user_data['tienda_id'])) $asignacion['tienda_id'] = intval($user_data['tienda_id']);

            $asignaciones[] = $asignacion;
        }
    }

    // Luego asignar campos
    $resultado_asignacion = billetera_bulk_assign_fields($asignaciones);

    return [
        'success' => count($usuarios_creados) > 0,
        'usuarios_creados' => count($usuarios_creados),
        'campos_asignados' => $resultado_asignacion['completadas'],
        'mensaje' => $resultado_asignacion['message'],
        'usuarios' => $usuarios_creados,
    ];
}
