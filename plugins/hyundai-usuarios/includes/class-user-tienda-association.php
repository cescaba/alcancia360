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
        add_action('restrict_manage_users', [$this, 'render_tienda_filter']);
        add_filter('pre_get_users', [$this, 'filter_users_by_tienda']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_styles']);

        // Migración one-time: precarga _tiendas_del_jefe de los jefes que
        // todavía no lo tienen, a partir de _jefes_venta_asociados de las
        // tiendas. Permite montar este esquema en un entorno nuevo sin
        // correr scripts manuales.
        add_action('admin_init', [$this, 'migrate_jefe_tiendas']);
    }

    /**
     * Migración one-time (bandera: opción hu_jefe_tiendas_migrated).
     * Para cada jefe de venta, precarga _tiendas_del_jefe:
     * 1. Con las tiendas donde aparece en _jefes_venta_asociados.
     * 2. Si no aparece listado en ninguna tienda, con su _tienda_asociada
     *    previa (asignación implícita del esquema anterior, ahora explícita).
     * Si ya tiene asignaciones (array no vacío), no se toca.
     */
    public function migrate_jefe_tiendas() {
        if (get_option('hu_jefe_tiendas_migrated') === '1') {
            return;
        }

        $tiendas = get_posts([
            'post_type'   => 'tienda',
            'numberposts' => -1,
            'post_status' => 'any',
        ]);
        $map = []; // uid => [tid, ...]
        foreach ($tiendas as $t) {
            $jefes = get_post_meta($t->ID, '_jefes_venta_asociados', true);
            if (!is_array($jefes)) {
                $jefes = $jefes ? [$jefes] : [];
            }
            foreach (array_map('intval', (array) $jefes) as $uid) {
                if ($uid) {
                    $map[$uid][] = intval($t->ID);
                }
            }
        }

        $jefes = get_users(['role' => 'jefe_venta', 'fields' => 'ID', 'number' => -1]);
        foreach ($jefes as $uid) {
            $uid = intval($uid);
            $existente = get_user_meta($uid, '_tiendas_del_jefe', true);
            if (is_array($existente) && !empty($existente)) {
                // Ya tiene asignaciones explícitas: no se toca.
                continue;
            }

            $nuevas = array_values(array_unique((array) ($map[$uid] ?? [])));
            if (empty($nuevas)) {
                // No listado en ninguna tienda: derivar de su sucursal
                // asociada previa para no perder la asignación.
                $tid = intval(get_user_meta($uid, '_tienda_asociada', true));
                if ($tid) {
                    $nuevas = [$tid];
                }
            }

            // sync_jefe_tiendas guarda el perfil y espeja el cambio en las
            // tiendas (queda consistente en ambas direcciones).
            self::sync_jefe_tiendas($uid, $nuevas);
        }

        update_option('hu_jefe_tiendas_migrated', '1');
    }

    /**
     * Carga el CSS/JS del admin en todo el panel (son assets pequeños y el
     * widget de chips puede aparecer en varias pantallas: perfil, crear
     * usuario, editar usuario, metaboxes de tiendas).
     */
    public function enqueue_admin_styles() {
        wp_enqueue_style('hu-admin', HU_PLUGIN_URL . 'assets/admin.css', [], '1.1');
        // El widget .hu-chips (buscador + chips) necesita su JS para inicializar.
        wp_enqueue_script('hu-admin', HU_PLUGIN_URL . 'assets/admin.js', [], '1.1', true);
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
            <tr id="hu-row-asesor-tienda">
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
                    <p class="description"><?php esc_html_e('La sucursal con la que trabajará este usuario (aplica a Asesores).', 'hyundai-usuarios'); ?></p>
                </td>
            </tr>
            <tr id="hu-row-jefe-tiendas">
                <th scope="row">
                    <label for="jefe_tiendas"><?php esc_html_e('Sucursales que administra', 'hyundai-usuarios'); ?></label>
                </th>
                <td>
                    <select name="jefe_tiendas[]" id="jefe_tiendas" multiple="multiple" class="hu-chips" data-placeholder="Buscar sucursal…">
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
                    <p class="description"><?php esc_html_e('Solo aplica a Jefes de Venta. Usa Ctrl (o Cmd) para elegir varias sucursales.', 'hyundai-usuarios'); ?></p>
                </td>
            </tr>
        </table>
        <script>
        (function () {
            var role = document.getElementById('role');
            var rowAsesor = document.getElementById('hu-row-asesor-tienda');
            var rowJefe = document.getElementById('hu-row-jefe-tiendas');
            if (!role || !rowAsesor || !rowJefe) return;

            function toggle() {
                var r = role.value;
                rowAsesor.style.display = (r === 'asesor') ? '' : 'none';
                rowJefe.style.display = (r === 'jefe_venta') ? '' : 'none';
            }
            role.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }

    /**
     * Agregar campo de Sucursal en el perfil del usuario
     */
    public function add_user_tienda_field($user) {
        // Mostrar solo para roles asesor y jefe_venta
        $es_jefe   = in_array('jefe_venta', (array) $user->roles, true);
        $es_asesor = in_array('asesor', (array) $user->roles, true);
        if (!$es_jefe && !$es_asesor) {
            return;
        }

        $tiendas = get_posts([
            'post_type' => 'tienda',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        // Jefe de Venta: multiselect de sucursales que administra (fuente de
        // verdad: user meta _tiendas_del_jefe). Se espeja en cada tienda
        // (_jefes_venta_asociados) al guardar.
        if ($es_jefe) {
            $mis_tiendas = get_user_meta($user->ID, '_tiendas_del_jefe', true);
            if (!is_array($mis_tiendas)) {
                $mis_tiendas = $mis_tiendas ? [$mis_tiendas] : [];
            }
            $mis_tiendas = array_map('intval', (array) $mis_tiendas);
            ?>
            <h3><?php esc_html_e('Sucursales que administras', 'hyundai-usuarios'); ?></h3>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="jefe_tiendas"><?php esc_html_e('Selecciona las Sucursales', 'hyundai-usuarios'); ?></label>
                    </th>
                    <td>
                        <select name="jefe_tiendas[]" id="jefe_tiendas" multiple="multiple" class="hu-chips" data-placeholder="Buscar sucursal…">
                            <?php foreach ($tiendas as $t): ?>
                                <option value="<?php echo esc_attr($t->ID); ?>"
                                    <?php echo in_array(intval($t->ID), $mis_tiendas, true) ? 'selected="selected"' : ''; ?>>
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
                        <p class="description"><?php esc_html_e('Busca y haz clic para agregar o quitar sucursales. Estas sucursales definen tu equipo, tus metas y tus comisiones de jefe.', 'hyundai-usuarios'); ?></p>
                    </td>
                </tr>
            </table>
            <?php
            return;
        }
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
                                <?php echo selected($tienda_id ?? '', $t->ID, false); ?>>
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

        $user = get_userdata($user_id);
        $es_jefe = $user && in_array('jefe_venta', (array) $user->roles, true);

        if ($es_jefe) {
            // Jefe: fuente de verdad es _tiendas_del_jefe (multiselect del perfil).
            if (isset($_POST['jefe_tiendas'])) {
                $nuevas = array_map('intval', (array) $_POST['jefe_tiendas']);
            } elseif (isset($_POST['tienda_asociada']) && $_POST['tienda_asociada'] !== '') {
                // Compatibilidad con el formulario de creación (select simple).
                $nuevas = [intval($_POST['tienda_asociada'])];
            } else {
                $nuevas = [];
            }
            self::sync_jefe_tiendas($user_id, $nuevas);
            return;
        }

        // Asesor (comportamiento previo)
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
     * Guardar las sucursales de un jefe (user meta _tiendas_del_jefe) y
     * sincronizar el espejo en cada tienda (_jefes_venta_asociados).
     */
    public static function sync_jefe_tiendas($user_id, $nuevas_ids) {
        $user_id = intval($user_id);
        if (!$user_id) {
            return;
        }

        $nuevas_ids = array_values(array_unique(array_filter(array_map('intval', (array) $nuevas_ids))));
        update_user_meta($user_id, '_tiendas_del_jefe', $nuevas_ids);

        $tiendas_all = get_posts([
            'post_type'   => 'tienda',
            'numberposts' => -1,
            'post_status' => 'any',
        ]);
        foreach ($tiendas_all as $t) {
            $jefes = get_post_meta($t->ID, '_jefes_venta_asociados', true);
            if (!is_array($jefes)) {
                $jefes = $jefes ? [$jefes] : [];
            }
            $jefes   = array_map('intval', (array) $jefes);
            $antes   = in_array($user_id, $jefes, true);
            $despues = in_array(intval($t->ID), $nuevas_ids, true);
            if ($antes === $despues) {
                continue;
            }
            if ($despues) {
                $jefes[] = $user_id;
            } else {
                $jefes = array_values(array_diff($jefes, [$user_id]));
            }
            update_post_meta($t->ID, '_jefes_venta_asociados', array_values(array_unique($jefes)));
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
     * Renderizar columna de Sucursal en lista de usuarios.
     * Jefes de venta: todas las sucursales que administran (_tiendas_del_jefe).
     * Asesores y resto: su sucursal asociada única (_tienda_asociada).
     */
    public function render_users_column($value, $column_name, $user_id) {
        if ($column_name === 'tienda_asociada') {
            $user = get_userdata($user_id);
            $es_jefe = $user && in_array('jefe_venta', (array) $user->roles, true);

            if ($es_jefe) {
                $out = [];
                foreach (array_map('intval', (array) get_user_meta($user_id, '_tiendas_del_jefe', true)) as $tid) {
                    if (!$tid) {
                        continue;
                    }
                    $t = get_post($tid);
                    if (!$t || $t->post_type !== 'tienda') {
                        continue;
                    }
                    $codigo = get_post_meta($tid, '_codigo', true);
                    $display = esc_html($t->post_title);
                    if ($codigo) {
                        $display .= ' (' . esc_html($codigo) . ')';
                    }
                    $out[] = '<a href="' . esc_url(get_edit_post_link($tid)) . '">' . $display . '</a>';
                }
                return $out ? implode('<br>', $out) : '—';
            }

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

    /**
     * Filtro por Sucursal en la lista de usuarios (users.php).
     */
    public function render_tienda_filter($which) {
        if ($which !== 'top' || is_network_admin()) {
            return;
        }

        $selected = isset($_GET['tienda_filter']) ? intval($_GET['tienda_filter']) : 0;
        $tiendas = get_posts([
            'post_type'   => 'tienda',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby'     => 'title',
            'order'       => 'ASC',
        ]);
        ?>
        <label class="screen-reader-text" for="tienda_filter"><?php esc_html_e('Filtrar por sucursal', 'hyundai-usuarios'); ?></label>
        <select name="tienda_filter" id="tienda_filter">
            <option value="0"><?php esc_html_e('Todas las sucursales', 'hyundai-usuarios'); ?></option>
            <?php foreach ($tiendas as $t): ?>
                <option value="<?php echo esc_attr($t->ID); ?>" <?php selected($selected, $t->ID); ?>>
                    <?php
                    echo esc_html($t->post_title);
                    $codigo = get_post_meta($t->ID, '_codigo', true);
                    if ($codigo) {
                        echo ' (' . esc_html($codigo) . ')';
                    }
                    ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button" style="margin-left:6px;"><?php esc_html_e('Filtrar', 'hyundai-usuarios'); ?></button>
        <?php
    }

    /**
     * Aplica el filtro por Sucursal a la consulta de usuarios.
     */
    public function filter_users_by_tienda($query) {
        global $pagenow;

        if (!is_admin() || is_network_admin() || $pagenow !== 'users.php') {
            return $query;
        }

        $tienda_id = isset($_GET['tienda_filter']) ? intval($_GET['tienda_filter']) : 0;
        if ($tienda_id <= 0) {
            return $query;
        }

        $meta_query = (array) $query->get('meta_query');
        // Asesores por _tienda_asociada + jefes por _tiendas_del_jefe
        // (array serializado → LIKE con comillas evita falsos positivos).
        $meta_query[] = [
            'relation' => 'OR',
            [
                'key'     => '_tienda_asociada',
                'value'   => (string) $tienda_id,
                'compare' => '=',
            ],
            [
                'key'     => '_tiendas_del_jefe',
                'value'   => '"' . $tienda_id . '"',
                'compare' => 'LIKE',
            ],
        ];
        $query->set('meta_query', $meta_query);

        return $query;
    }
}
