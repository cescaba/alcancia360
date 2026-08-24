<?php
/**
 * Foto de perfil del usuario.
 * - Se guarda en la librería de medios (uploads).
 * - El ID del attachment se almacena en wp_usermeta (_foto_perfil).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL de la foto de perfil de un usuario (o '' si no tiene).
 */
function billetera_get_foto_url($user_id) {
    $attach_id = get_user_meta($user_id, '_foto_perfil', true);
    if ($attach_id) {
        $url = wp_get_attachment_image_url(intval($attach_id), 'thumbnail');
        if ($url) {
            return $url;
        }
    }
    return '';
}

/**
 * AJAX: subir foto de perfil.
 */
add_action('wp_ajax_billetera_upload_foto', 'billetera_ajax_upload_foto');
function billetera_ajax_upload_foto() {
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'No autenticado']);
    }

    $user = wp_get_current_user();
    $allowed_roles = ['asesor', 'administrator', 'jefe_venta'];
    if (!array_intersect($allowed_roles, (array) $user->roles)) {
        wp_send_json_error(['message' => 'No tienes permiso']);
    }

    check_ajax_referer('billetera_foto_nonce', 'nonce');

    if (empty($_FILES['foto'])) {
        wp_send_json_error(['message' => 'No se recibió ninguna imagen']);
    }

    $file = $_FILES['foto'];
    if (!empty($file['error']) && intval($file['error']) !== UPLOAD_ERR_OK) {
        wp_send_json_error(['message' => 'Error al subir la imagen']);
    }

    $filetype = wp_check_filetype($file['name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (empty($filetype['type']) || !in_array($filetype['type'], $allowed, true)) {
        wp_send_json_error(['message' => 'Solo se permiten imágenes JPG, PNG, WEBP o GIF']);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload('foto', 0);
    if (is_wp_error($attachment_id)) {
        wp_send_json_error(['message' => $attachment_id->get_error_message()]);
    }

    $old_id = get_user_meta($user->ID, '_foto_perfil', true);
    if ($old_id) {
        wp_delete_attachment(intval($old_id), true);
    }

    update_user_meta($user->ID, '_foto_perfil', $attachment_id);

    wp_send_json_success([
        'url' => wp_get_attachment_image_url($attachment_id, 'thumbnail'),
        'attachment_id' => $attachment_id,
    ]);
}
