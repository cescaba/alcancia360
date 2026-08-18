<?php
/**
 * Clase para asociar usuarios (Asesores y Jefes de Venta) con Tiendas
 */

if (!defined('ABSPATH')) exit;

class HU_UserTiendaAssociation {

    public function __construct() {
        add_action('show_user_profile', [$this, 'add_user_tienda_field']);
        add_action('edit_user_profile', [$this, 'add_user_tienda_field']);
        add_action('user_new_form', [$this, 'add_user_tienda_field_create']);
        add_action('personal_options_update', [$this, 'save_user_tienda_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_tienda_field']);
        add_action('user_register', [$this, 'save_user_tienda_field']);
        add_filter('manage_users_columns', [$this, 'add_users_column']);
        add_filter('manage_users_custom_column', [$this, 'render_users_column'], 10, 3);
    }

    /**
     * Agregar campo de Sucursal en formulario de creación de usuario
     */
    public function add_user_tienda_field_create($context = 'add-new-user') {
        $tiendas = get_posts([
            'post_type' => 'tienda',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <h3><?php esc_html_e('Sucursal Asociada', 'hyundai-usuarios'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="tienda_asociada"><?php esc_html_e('Selecciona una Sucursal', 'hyundai-usuarios'); ?></label>
                </th>
                <td>
                    <select name="tienda_asociada" id="tienda_asociada" style="padding: 8px;">
                        <option value="">— <?php esc_html_e('Ninguna', 'hyundai-usuarios'); ?> —</option>
                        <?php foreach ($tiendas as $t): ?>
                            <option value="<?php echo esc_attr($t->ID); ?>">
                                <?php echo esc_html($t->post_title); ?>
                                <?php
                                $codigo = get_post_meta($t->ID, '_codigo', true);
                                if ($codigo) {
                                    echo ' (' . esc_html($codigo) . ')';
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('La sucursal con la que trabajará este usuario.', 'hyundai-usuarios'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Agregar campo de Sucursal en el perfil del usuario
     */
    public function add_user_tienda_field($user) {
        // Mostrar solo para roles asesor y jefe_venta
        if (!in_array('asesor', (array) $user->roles, true) && !in_array('jefe_venta', (array) $user->roles, true)) {
            return;
        }

        $tienda_id = get_user_meta($user->ID, '_tienda_asociada', true);
        $tiendas = get_posts([
            'post_type' => 'tienda',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        ?>
        <h3><?php esc_html_e('Sucursal Asociada', 'hyundai-usuarios'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="tienda_asociada"><?php esc_html_e('Selecciona una Sucursal', 'hyundai-usuarios'); ?></label>
                </th>
                <td>
                    <select name="tienda_asociada" id="tienda_asociada" style="padding: 8px;">
                        <option value="">— <?php esc_html_e('Ninguna', 'hyundai-usuarios'); ?> —</option>
                        <?php foreach ($tiendas as $t): ?>
                            <option value="<?php echo esc_attr($t->ID); ?>"
                                <?php echo selected($tienda_id, $t->ID, false); ?>>
                                <?php echo esc_html($t->post_title); ?>
                                <?php
                                $codigo = get_post_meta($t->ID, '_codigo', true);
                                if ($codigo) {
                                    echo ' (' . esc_html($codigo) . ')';
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('La sucursal con la que trabajará este usuario.', 'hyundai-usuarios'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Guardar campo de Sucursal asociada
     */
    public function save_user_tienda_field($user_id) {
        // Verificar permisos solo si estamos en contexto de edición (no en user_register)
        if (did_action('personal_options_update') || did_action('edit_user_profile_update')) {
            if (!current_user_can('edit_user', $user_id)) {
                return false;
            }
        }

        if (isset($_POST['tienda_asociada'])) {
            $tienda_id = sanitize_text_field($_POST['tienda_asociada']);
            if (!empty($tienda_id)) {
                update_user_meta($user_id, '_tienda_asociada', $tienda_id);
            } else {
                delete_user_meta($user_id, '_tienda_asociada');
            }
        } else {
            delete_user_meta($user_id, '_tienda_asociada');
        }
    }

    /**
     * Agregar columna de Sucursal en lista de usuarios
     */
    public function add_users_column($columns) {
        $columns['tienda_asociada'] = __('Sucursal', 'hyundai-usuarios');
        return $columns;
    }

    /**
     * Renderizar columna de Sucursal en lista de usuarios
     */
    public function render_users_column($value, $column_name, $user_id) {
        if ($column_name === 'tienda_asociada') {
            $tienda_id = get_user_meta($user_id, '_tienda_asociada', true);
            if ($tienda_id) {
                $tienda = get_post($tienda_id);
                if ($tienda) {
                    $codigo = get_post_meta($tienda_id, '_codigo', true);
                    $display = esc_html($tienda->post_title);
                    if ($codigo) {
                        $display .= ' (' . esc_html($codigo) . ')';
                    }
                    return '<a href="' . esc_url(get_edit_post_link($tienda_id)) . '">' . $display . '</a>';
                }
            }
            return '—';
        }
        return $value;
    }
}
