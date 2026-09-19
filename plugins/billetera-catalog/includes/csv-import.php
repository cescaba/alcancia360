<?php
/**
 * Importación de usuarios desde CSV
 */

if (!defined('ABSPATH')) exit;

// AJAX: Descargar CSV de ejemplo (solo usuarios autenticados)
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

    // Crear CSV en memoria (con delimitador punto y coma para compatibilidad con Excel en español)
    $output = fopen('php://memory', 'w');

    // Escribir encabezados (delimitador punto y coma)
    fputcsv($output, $headers, ';', '"');

    // Escribir ejemplos
    foreach ($ejemplos as $row) {
        fputcsv($output, $row, ';', '"');
    }

    // Obtener contenido
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);

    // Asegurarse de que no hay salida anterior
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Headers para descarga
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="usuarios_importar_EJEMPLO.csv"');
    header('Content-Length: ' . strlen($csv_content));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM para UTF-8
    echo "\xEF\xBB\xBF";
    echo $csv_content;
    exit;
}

// AJAX: Importar usuarios desde CSV
add_action('wp_ajax_billetera_import_csv', 'billetera_ajax_import_csv');

function billetera_ajax_import_csv() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_csv_import')) {
        wp_send_json_error(['message' => 'Verificación de seguridad falló']);
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
    $errores_filas = [];
    $handle = fopen($file, 'r');
    $header = null;
    $row_num = 0;

    // Detectar delimitador (coma o punto y coma)
    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (strpos($first_line, ';') !== false) ? ';' : ',';

    while (($data = fgetcsv($handle, 1000, $delimiter)) !== false) {
        $row_num++;

        // Primera fila es encabezado
        if ($header === null) {
            $header = array_map('trim', $data); // Limpiar espacios en encabezados
            continue;
        }

        // Saltar filas vacías
        if (count(array_filter($data)) === 0) {
            continue;
        }

        // Convertir a array asociativo
        if (count($header) !== count($data)) {
            $errores_filas[] = "Fila $row_num: Número de columnas no coincide (esperadas: " . count($header) . ", encontradas: " . count($data) . ")";
            continue;
        }
        $row = array_combine($header, $data);

        // Mapear columnas CSV a formato de función
        $usuario = [
            'first_name' => isset($row['nombre']) ? trim($row['nombre']) : '',
            'last_name' => isset($row['apellido']) ? trim($row['apellido']) : '',
            'user_login' => isset($row['user_login']) ? trim($row['user_login']) : '',
            'user_email' => isset($row['user_email']) ? trim($row['user_email']) : '',
            'password' => isset($row['contraseña']) ? trim($row['contraseña']) : '',
            'rol' => isset($row['rol']) ? trim($row['rol']) : 'asesor',
            'billetera_hyu' => isset($row['HYU']) ? intval($row['HYU']) : 0,
            'billetera_hcv' => isset($row['HCV']) ? intval($row['HCV']) : 0,
            'billetera_gee' => isset($row['GEE']) ? intval($row['GEE']) : 0,
            'billetera_jmc' => isset($row['JMC']) ? intval($row['JMC']) : 0,
            'billetera_tipo' => isset($row['tipo']) ? trim($row['tipo']) : 'Venta',
            'tienda_id' => isset($row['tienda']) ? intval($row['tienda']) : 0,
        ];

        // Validar que no esté vacío
        if (!empty($usuario['user_login']) && !empty($usuario['user_email'])) {
            $usuarios[] = $usuario;
        } else {
            $errores_filas[] = "Fila $row_num: Falta user_login o user_email";
        }
    }

    fclose($handle);

    if (empty($usuarios)) {
        $mensaje_error = "El CSV no tiene usuarios válidos. ";
        if (!empty($errores_filas)) {
            $mensaje_error .= "Errores: " . implode(" | ", array_slice($errores_filas, 0, 5));
        }
        error_log("CSV Import Error: $mensaje_error. Total filas leídas: $row_num");
        wp_send_json_error(['message' => $mensaje_error]);
    }

    error_log("CSV Import: Importando " . count($usuarios) . " usuarios válidos de " . $row_num . " filas totales");

    // Importar usando la función principal
    $resultado = billetera_bulk_create_and_assign($usuarios);

    error_log("CSV Import Result: " . json_encode($resultado));

    wp_send_json_success([
        'message' => $resultado['mensaje'],
        'usuarios_creados' => $resultado['usuarios_creados'],
        'campos_asignados' => $resultado['campos_asignados'],
        'usuarios' => $resultado['usuarios'],
        'errores' => $resultado['errores'] ?? [],
        'errores_count' => count($resultado['errores'] ?? []),
    ]);
}
