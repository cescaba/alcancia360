<?php
/**
 * Meta boxes for Billetera App
 */

// ===== CAMPOS PERSONALIZADOS DE USUARIO =====

// Agregar campos personalizados solo al formulario de ADMIN (no al perfil del usuario)
add_action('edit_user_profile', 'billetera_user_custom_fields');
add_action('user_new_form', 'billetera_user_custom_fields');

function billetera_user_custom_fields($user) {
    $hyu = get_user_meta($user->ID, 'billetera_hyu', true);
    $hcv = get_user_meta($user->ID, 'billetera_hcv', true);
    $gee = get_user_meta($user->ID, 'billetera_gee', true);
    $jmc = get_user_meta($user->ID, 'billetera_jmc', true);
    $tipo = get_user_meta($user->ID, 'billetera_tipo', true);
    ?>
    <h3><?php _e('Campos Billetera'); ?></h3>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="billetera_hyu"><?php _e('HYU'); ?></label></th>
            <td>
                <input type="checkbox" name="billetera_hyu" id="billetera_hyu" value="1" <?php checked($hyu, 1); ?> />
                <label for="billetera_hyu"><?php _e('Habilitado'); ?></label>
            </td>
        </tr>
        <tr>
            <th><label for="billetera_hcv"><?php _e('HCV'); ?></label></th>
            <td>
                <input type="checkbox" name="billetera_hcv" id="billetera_hcv" value="1" <?php checked($hcv, 1); ?> />
                <label for="billetera_hcv"><?php _e('Habilitado'); ?></label>
            </td>
        </tr>
        <tr>
            <th><label for="billetera_gee"><?php _e('GEE'); ?></label></th>
            <td>
                <input type="checkbox" name="billetera_gee" id="billetera_gee" value="1" <?php checked($gee, 1); ?> />
                <label for="billetera_gee"><?php _e('Habilitado'); ?></label>
            </td>
        </tr>
        <tr>
            <th><label for="billetera_jmc"><?php _e('JMC'); ?></label></th>
            <td>
                <input type="checkbox" name="billetera_jmc" id="billetera_jmc" value="1" <?php checked($jmc, 1); ?> />
                <label for="billetera_jmc"><?php _e('Habilitado'); ?></label>
            </td>
        </tr>
        <tr>
            <th><label for="billetera_tipo"><?php _e('Tipo'); ?></label></th>
            <td>
                <select name="billetera_tipo" id="billetera_tipo">
                    <option value=""><?php _e('Seleccionar...'); ?></option>
                    <option value="Venta" <?php selected($tipo, 'Venta'); ?>><?php _e('Venta'); ?></option>
                    <option value="Post Venta" <?php selected($tipo, 'Post Venta'); ?>><?php _e('Post Venta'); ?></option>
                </select>
            </td>
        </tr>
    </table>
    <?php
}

// Guardar los campos personalizados
add_action('personal_options_update', 'billetera_save_user_custom_fields');
add_action('edit_user_profile_update', 'billetera_save_user_custom_fields');
add_action('user_register', 'billetera_save_user_custom_fields');

function billetera_save_user_custom_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return false;
    }

    // Guardar campos booleanos
    update_user_meta($user_id, 'billetera_hyu', isset($_POST['billetera_hyu']) ? 1 : 0);
    update_user_meta($user_id, 'billetera_hcv', isset($_POST['billetera_hcv']) ? 1 : 0);
    update_user_meta($user_id, 'billetera_gee', isset($_POST['billetera_gee']) ? 1 : 0);
    update_user_meta($user_id, 'billetera_jmc', isset($_POST['billetera_jmc']) ? 1 : 0);

    // Guardar campo select
    if (isset($_POST['billetera_tipo'])) {
        update_user_meta($user_id, 'billetera_tipo', sanitize_text_field($_POST['billetera_tipo']));
    }
}
