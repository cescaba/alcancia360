<?php
/**
 * Clase para agregar campo de Fecha de Ingreso a usuarios
 */

if (!defined('ABSPATH')) exit;

class HU_User_Ingreso {

    public function __construct() {
        add_action('show_user_profile', [$this, 'add_ingreso_field']);
        add_action('edit_user_profile', [$this, 'add_ingreso_field']);
        add_action('personal_options_update', [$this, 'save_ingreso_field']);
        add_action('edit_user_profile_update', [$this, 'save_ingreso_field']);
    }

    /**
     * Agregar campo de Fecha de Ingreso
     */
    public function add_ingreso_field($user) {
        $fecha_ingreso = get_user_meta($user->ID, '_fecha_ingreso', true);
        ?>
        <h3><?php esc_html_e('Información de Ingreso', 'hyundai-usuarios'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="fecha_ingreso"><?php esc_html_e('Fecha de Ingreso', 'hyundai-usuarios'); ?></label>
                </th>
                <td>
                    <input type="date" name="fecha_ingreso" id="fecha_ingreso"
                        value="<?php echo esc_attr($fecha_ingreso); ?>"
                        style="padding: 8px;">
                    <p class="description"><?php esc_html_e('Fecha en que el usuario se incorporó.', 'hyundai-usuarios'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Guardar campo de Fecha de Ingreso
     */
    public function save_ingreso_field($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }

        if (isset($_POST['fecha_ingreso'])) {
            $fecha = sanitize_text_field($_POST['fecha_ingreso']);
            update_user_meta($user_id, '_fecha_ingreso', $fecha);
        } else {
            delete_user_meta($user_id, '_fecha_ingreso');
        }
    }
}
