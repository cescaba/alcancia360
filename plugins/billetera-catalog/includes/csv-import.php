<?php
/**
 * Importación de usuarios desde CSV
 */

if (!defined('ABSPATH')) exit;

// AJAX: Descargar CSV de ejemplo
add_action('wp_ajax_billetera_download_csv_template', 'billetera_ajax_download_csv_template');

function billetera_ajax_download_csv_template() {
    // Encabezados del CSV
    $headers = [
        'nombre',          // first_name
        'apellido',        // last_name
        'user_login',
        'user_email',
        'contraseña',      // password
        'rol',             // asesor, jefe_venta, gerente, administrador_hyundai
        'HYU',             // 0 o 1
        'HCV',             // 0 o 1
        'GEE',             // 0 o 1
        'JMC',             // 0 o 1
        'tipo',            // Venta o Post Venta
        'tienda',          // ID de la tienda/sucursal
    ];

    // Datos de ejemplo
    $ejemplos = [
        ['Juan', 'Pérez', 'juan.asesor', 'juan@example.com', 'temp123456', 'asesor', 1, 0, 0, 0, 'Venta', 15],
        ['María', 'García', 'maria.jefe', 'maria@example.com', 'temp654321', 'jefe_venta', 0, 0, 0, 1, 'Post Venta', 16],
        ['Carlos', 'López', 'carlos.asesor', 'carlos@example.com', 'temp111111', 'asesor', 1, 0, 0, 1, 'Venta', 17],
    ];

    // Crear CSV en memoria
    $output = fopen('php://memory', 'w');

    // Escribir encabezados
    fputcsv($output, $headers, ',', '"');

    // Escribir ejemplos
    foreach ($ejemplos as $row) {
        fputcsv($output, $row, ',', '"');
    }

    // Obtener contenido
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);

    // Headers para descarga
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_importar_EJEMPLO.csv"');
    header('Content-Length: ' . strlen($csv_content));
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "\xEF\xBB\xBF"; // BOM para UTF-8
    echo $csv_content;
    exit;
}

// AJAX: Importar usuarios desde CSV
add_action('wp_ajax_billetera_import_csv', 'billetera_ajax_import_csv');

function billetera_ajax_import_csv() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    $user = wp_get_current_user();
    if (!in_array('administrator', $user->roles) && !in_array('administrador_hyundai', $user->roles)) {
        wp_send_json_error(['message' => 'No tienes permisos para importar usuarios']);
    }

    if (empty($_FILES['csv_file'])) {
        wp_send_json_error(['message' => 'No se cargó archivo']);
    }

    $file = $_FILES['csv_file']['tmp_name'];
    if (!is_uploaded_file($file)) {
        wp_send_json_error(['message' => 'Error en la carga del archivo']);
    }

    // Leer CSV
    $usuarios = [];
    $handle = fopen($file, 'r');
    $header = null;
    $row_num = 0;

    while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $row_num++;

        // Primera fila es encabezado
        if ($header === null) {
            $header = $data;
            continue;
        }

        // Convertir a array asociativo
        $row = array_combine($header, $data);

        // Mapear columnas CSV a formato de función
        $usuario = [
            'first_name' => trim($row['nombre'] ?? ''),
            'last_name' => trim($row['apellido'] ?? ''),
            'user_login' => trim($row['user_login'] ?? ''),
            'user_email' => trim($row['user_email'] ?? ''),
            'password' => trim($row['contraseña'] ?? ''),
            'rol' => trim($row['rol'] ?? 'asesor'),
            'billetera_hyu' => intval($row['HYU'] ?? 0),
            'billetera_hcv' => intval($row['HCV'] ?? 0),
            'billetera_gee' => intval($row['GEE'] ?? 0),
            'billetera_jmc' => intval($row['JMC'] ?? 0),
            'billetera_tipo' => trim($row['tipo'] ?? 'Venta'),
            'tienda_id' => intval($row['tienda'] ?? 0),
        ];

        // Validar que no esté vacío
        if (!empty($usuario['user_login']) && !empty($usuario['user_email'])) {
            $usuarios[] = $usuario;
        }
    }

    fclose($handle);

    if (empty($usuarios)) {
        wp_send_json_error(['message' => 'El CSV no tiene usuarios válidos']);
    }

    // Importar usando la función principal
    $resultado = billetera_bulk_create_and_assign($usuarios);

    wp_send_json_success([
        'message' => $resultado['mensaje'],
        'usuarios_creados' => $resultado['usuarios_creados'],
        'campos_asignados' => $resultado['campos_asignados'],
        'usuarios' => $resultado['usuarios'],
    ]);
}
