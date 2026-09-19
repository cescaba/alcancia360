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
        $fila = $idx + 2; // +2 porque fila 1 es encabezado, $idx empieza en 0

        // Validar datos requeridos
        if (empty($user_data['user_login']) || empty($user_data['user_email'])) {
            $errores[] = [
                'fila' => $fila,
                'login' => $user_data['user_login'] ?? 'VACÍO',
                'email' => $user_data['user_email'] ?? 'VACÍO',
                'razon' => 'Falta user_login o user_email'
            ];
            continue;
        }

        // Verificar que el usuario no exista
        if (username_exists($user_data['user_login'])) {
            $errores[] = [
                'fila' => $fila,
                'login' => $user_data['user_login'],
                'email' => $user_data['user_email'],
                'razon' => "Usuario '{$user_data['user_login']}' YA EXISTE en la BD"
            ];
            continue;
        }

        if (email_exists($user_data['user_email'])) {
            $errores[] = [
                'fila' => $fila,
                'login' => $user_data['user_login'],
                'email' => $user_data['user_email'],
                'razon' => "Email '{$user_data['user_email']}' YA EXISTE en la BD"
            ];
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
        'message' => count($usuarios_creados) . " usuarios creados, " . count($errores) . " NO se importaron",
        'usuarios_creados_count' => count($usuarios_creados),
        'errores_count' => count($errores),
        'usuarios' => $usuarios_creados,
        'errores' => $errores,
        'errores_resumen' => array_slice($errores, 0, 10), // Primeros 10 errores
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
        return [
            'success' => false,
            'message' => 'Array de asignaciones vacío',
            'completadas' => 0,
            'errores' => []
        ];
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
        error_log("billetera_bulk_create_and_assign: Array vacío");
        return ['success' => false, 'message' => 'Array vacío', 'usuarios_creados' => 0, 'campos_asignados' => 0, 'errores' => []];
    }

    $usuarios_creados = [];
    $usuarios_existentes = [];
    $usuarios_actualizados = [];
    $asignaciones = [];
    $errores_creacion = [];

    error_log("billetera_bulk_create_and_assign: Iniciando con " . count($usuarios_data) . " usuarios");

    // Primero crear todos los usuarios
    foreach ($usuarios_data as $idx => $user_data) {
        $user_login = sanitize_user($user_data['user_login']);
        $user_email = sanitize_email($user_data['user_email']);

        // Verificar si ya existe por user_login o email
        $user_exists = get_user_by('login', $user_login);
        if (!$user_exists) {
            $user_exists = get_user_by('email', $user_email);
        }

        // Si existe, actualizar rol y campos personalizados
        if ($user_exists) {
            $user_id = $user_exists->ID;
            error_log("Usuario existente: {$user_login} (ID: $user_id), actualizando campos y rol...");
            $usuarios_existentes[] = $user_login;

            // Actualizar rol si está especificado
            if (isset($user_data['rol'])) {
                $rol = sanitize_text_field($user_data['rol']);
                $user = new WP_User($user_id);
                $user->set_role($rol);
            }

            // Preparar asignaciones para actualizar
            $asignacion = ['user_id' => $user_id];
            if (isset($user_data['billetera_hyu'])) $asignacion['billetera_hyu'] = intval($user_data['billetera_hyu']);
            if (isset($user_data['billetera_hcv'])) $asignacion['billetera_hcv'] = intval($user_data['billetera_hcv']);
            if (isset($user_data['billetera_gee'])) $asignacion['billetera_gee'] = intval($user_data['billetera_gee']);
            if (isset($user_data['billetera_jmc'])) $asignacion['billetera_jmc'] = intval($user_data['billetera_jmc']);
            if (isset($user_data['billetera_tipo'])) $asignacion['billetera_tipo'] = $user_data['billetera_tipo'];
            if (isset($user_data['tienda_id'])) $asignacion['tienda_id'] = intval($user_data['tienda_id']);

            $asignaciones[] = $asignacion;
            $usuarios_actualizados[] = $user_login;
            continue;
        }

        // Si no existe, crearlo
        $userdata = [
            'user_login' => $user_login,
            'user_email' => $user_email,
            'first_name' => sanitize_text_field($user_data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($user_data['last_name'] ?? ''),
            'user_pass' => $user_data['password'] ?? wp_generate_password(12),
            'role' => 'subscriber',
        ];

        $user_id = wp_insert_user($userdata);

        if (is_wp_error($user_id)) {
            error_log("Error creando usuario {$userdata['user_login']}: " . $user_id->get_error_message());
            $errores_creacion[] = [
                'fila' => $idx + 2,
                'login' => $userdata['user_login'],
                'email' => $userdata['user_email'],
                'razon' => $user_id->get_error_message(),
            ];
            continue;
        }

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

    error_log("Usuarios creados: " . count($usuarios_creados) . ", Usuarios existentes: " . count($usuarios_existentes) . ", Asignaciones preparadas: " . count($asignaciones));

    // Luego asignar campos (para nuevos y existentes)
    $resultado_asignacion = billetera_bulk_assign_fields($asignaciones);

    error_log("Resultado asignaciones: " . json_encode($resultado_asignacion));

    $total_procesados = count($usuarios_creados) + count($usuarios_existentes);
    $mensaje = count($usuarios_creados) . " NUEVOS creados + " . count($usuarios_existentes) . " EXISTENTES actualizados = " . $total_procesados . " totales procesados. " . $resultado_asignacion['message'];

    return [
        'success' => $total_procesados > 0,
        'usuarios_creados' => count($usuarios_creados),
        'usuarios_existentes' => count($usuarios_existentes),
        'usuarios_actualizados' => count($usuarios_actualizados),
        'campos_asignados' => $resultado_asignacion['completadas'] ?? 0,
        'mensaje' => $mensaje,
        'usuarios' => $usuarios_creados,
        'errores' => $errores_creacion,
    ];
}
