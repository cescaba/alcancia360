<?php
/**
 * AJAX Handlers for Billetera App
 */

// Agregar nueva venta
add_action('wp_ajax_billetera_add_sale', 'billetera_ajax_add_sale');
function billetera_ajax_add_sale() {
    // Verificar nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'billetera_sales_nonce')) {
        wp_send_json_error(array('message' => 'Verificación de seguridad fallida.'));
    }

    // Verificar que el usuario esté logueado
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'No estás autenticado.'));
    }

    $user_id = get_current_user_id();
    $current_user = wp_get_current_user();

    // Verificar que el usuario sea asesor
    if (!in_array('asesor', $current_user->roles)) {
        wp_send_json_error(array('message' => 'No tienes permiso para agregar ventas.'));
    }

    // Validar datos
    $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
    $description = isset($_POST['description']) ? sanitize_text_field($_POST['description']) : '';

    if ($amount <= 0) {
        wp_send_json_error(array('message' => 'El monto debe ser mayor a 0.'));
    }

    // Insertar venta en la base de datos
    global $wpdb;
    $table_name = $wpdb->prefix . 'billetera_ventas';

    $result = $wpdb->insert(
        $table_name,
        array(
            'usuario_id' => $user_id,
            'subcategoria_id' => 1,
            'cantidad' => 1,
            'monto_comision_sol' => $amount,
            'descripcion' => $description,
        ),
        array('%d', '%d', '%d', '%f', '%s')
    );

    if ($result) {
        wp_send_json_success(array('message' => 'Venta registrada correctamente.'));
    } else {
        wp_send_json_error(array('message' => 'Error al registrar la venta.'));
    }
}

// Eliminar venta
add_action('wp_ajax_billetera_delete_sale', 'billetera_ajax_delete_sale');
function billetera_ajax_delete_sale() {
    // Verificar que el usuario esté logueado
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'No estás autenticado.'));
    }

    $user_id = get_current_user_id();
    $sale_id = isset($_POST['sale_id']) ? intval($_POST['sale_id']) : 0;

    if (!$sale_id) {
        wp_send_json_error(array('message' => 'ID de venta inválido.'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'billetera_ventas';

    // Verificar que la venta pertenece al usuario
    $sale = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE id = %d AND usuario_id = %d",
        $sale_id,
        $user_id
    ));

    if (!$sale) {
        wp_send_json_error(array('message' => 'Venta no encontrada.'));
    }

    // Eliminar venta
    $result = $wpdb->delete(
        $table_name,
        array(
            'id' => $sale_id,
            'usuario_id' => $user_id,
        ),
        array('%d', '%d')
    );

    if ($result) {
        wp_send_json_success(array('message' => 'Venta eliminada correctamente.'));
    } else {
        wp_send_json_error(array('message' => 'Error al eliminar la venta.'));
    }
}
