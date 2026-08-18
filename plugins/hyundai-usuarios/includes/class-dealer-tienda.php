<?php
/**
 * Clase para gestionar Dealers (Concesionarias) y Tiendas (Sucursales)
 */

if (!defined('ABSPATH')) exit;

class HU_DealerTienda {

    private $departamentos_provincias = [
        'Amazonas' => ['Chachapoyas', 'Bagua', 'Bongará', 'Condorcanqui', 'Luya', 'Rodríguez de Mendoza', 'Utcubamba'],
        'Áncash' => ['Huaraz', 'Aija', 'Antonio Raymondi', 'Asunción', 'Bolognesi', 'Carhuaz', 'Carlos Fermín Fitzcarrald', 'Casma', 'Corongo', 'Huari', 'Huarmey', 'Huaylas', 'Mariscal Luzuriaga', 'Ocros', 'Pallasca', 'Pomabamba', 'Recuay', 'Santa', 'Sihuas', 'Yungay'],
        'Apurímac' => ['Abancay', 'Andahuaylas', 'Antabamba', 'Aymaraes', 'Cotabambas', 'Chincheros', 'Grau'],
        'Arequipa' => ['Arequipa', 'Camana', 'Caravelí', 'Castilla', 'Caylloma', 'Condesuyos', 'Islay', 'La Unión'],
        'Ayacucho' => ['Huamanga', 'Cangallo', 'Huanca Sancos', 'Huanta', 'La Mar', 'Lucanas', 'Parinacochas', 'Páucar del Sara Sara', 'Sucre', 'Víctor Fajardo', 'Vilcas Huamán'],
        'Cajamarca' => ['Cajamarca', 'Cajabamba', 'Celendín', 'Chota', 'Contumazá', 'Cutervo', 'Hualgayoc', 'Jaén', 'San Ignacio', 'San Marcos', 'San Miguel', 'San Pablo', 'Santa Cruz'],
        'Callao' => ['Callao'],
        'Cusco' => ['Cusco', 'Acomayo', 'Anta', 'Calca', 'Canas', 'Canchis', 'Chumbivilcas', 'Espinar', 'La Convención', 'Paruro', 'Paucartambo', 'Quispicanchi', 'Urubamba'],
        'Huancavelica' => ['Huancavelica', 'Acobamba', 'Angaraes', 'Castrovirreyna', 'Churcampa', 'Huaytará', 'Tayacaja'],
        'Huánuco' => ['Huánuco', 'Ambo', 'Dos de Mayo', 'Huacaybamba', 'Huamalíes', 'Leoncio Prado', 'Marañón', 'Pachitea', 'Puerto Inca', 'Lauricocha', 'Yarowilca'],
        'Ica' => ['Ica', 'Chincha', 'Nasca', 'Palpa', 'Pisco'],
        'Junín' => ['Huancayo', 'Concepción', 'Chanchamayo', 'Jauja', 'Junín', 'Satipo', 'Tarma', 'Yauli', 'Chupaca'],
        'La Libertad' => ['Trujillo', 'Ascope', 'Bolívar', 'Chepén', 'Julcán', 'Otuzco', 'Pacasmayo', 'Pataz', 'Sánchez Carrión', 'Santiago de Chuco', 'Gran Chimú', 'Virú'],
        'Lambayeque' => ['Chiclayo', 'Ferreñafe', 'Lambayeque'],
        'Lima' => ['Lima', 'Barranca', 'Cajatambo', 'Canta', 'Cañete', 'Huaral', 'Huarochirí', 'Huaura', 'Oyón', 'Yauyos'],
        'Loreto' => ['Maynas', 'Alto Amazonas', 'Datem del Marañón', 'Loreto', 'Mariscal Ramón Castilla', 'Putumayo', 'Requena', 'Ucayali'],
        'Madre de Dios' => ['Tambopata', 'Manu', 'Tahuamanu'],
        'Moquegua' => ['Mariscal Nieto', 'General Sánchez Cerro', 'Ilo'],
        'Pasco' => ['Pasco', 'Daniel Alcides Carrión', 'Oxapampa'],
        'Piura' => ['Piura', 'Ayabaca', 'Huancabamba', 'Morropón', 'Paita', 'Sechura', 'Sullana', 'Talara'],
        'Puno' => ['Puno', 'Azángaro', 'Carabaya', 'Chucuito', 'El Collao', 'Huancané', 'Lampa', 'Melgar', 'Moho', 'San Antonio de Putina', 'San Román', 'Sandia', 'Yunguyo'],
        'San Martín' => ['Moyobamba', 'Bellavista', 'El Dorado', 'Huallaga', 'Lamas', 'Mariscal Cáceres', 'Picota', 'Rioja', 'San Martín', 'Tocache'],
        'Tacna' => ['Tacna', 'Candarave', 'Jorge Basadre', 'Tarata'],
        'Tumbes' => ['Tumbes', 'Contralmirante Villar', 'Zarumilla'],
        'Ucayali' => ['Coronel Portillo', 'Atalaya', 'Padre Abad', 'Purus']
    ];

    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_dealer', [$this, 'save_dealer_fields']);
        add_action('save_post_tienda', [$this, 'save_tienda_fields']);
        add_action('admin_menu', [$this, 'restructure_menu']);
        add_action('admin_footer', [$this, 'enqueue_dynamic_js']);
        add_filter('manage_dealer_posts_columns', [$this, 'add_dealer_columns']);
        add_action('manage_dealer_posts_custom_column', [$this, 'render_dealer_columns'], 10, 2);
        add_filter('manage_tienda_posts_columns', [$this, 'add_tienda_columns']);
        add_action('manage_tienda_posts_custom_column', [$this, 'render_tienda_columns'], 10, 2);
        add_filter('user_has_cap', [$this, 'allow_admin_caps'], 1, 3);
        add_action('admin_init', [$this, 'force_admin_access'], 1);
    }

    /**
     * Permitir que ambos administratores accedan a dealers y tiendas
     */
    public function allow_admin_caps($allcaps, $cap) {
        $user = wp_get_current_user();
        $es_admin_nativo = in_array('administrator', (array) $user->roles, true);
        $es_admin_hyundai = in_array('administrador_hyundai', (array) $user->roles, true);

        if (!$es_admin_nativo && !$es_admin_hyundai) {
            return $allcaps;
        }

        // Permitir todas las capabilities de posts para ambos administratores
        $post_caps = ['read', 'edit_posts', 'edit_others_posts', 'edit_published_posts',
                     'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts'];

        foreach ($post_caps as $post_cap) {
            $allcaps[$post_cap] = true;
        }

        return $allcaps;
    }

    /**
     * Forzar acceso a pages de dealers y tiendas para administradores
     */
    public function force_admin_access() {
        $user = wp_get_current_user();
        $es_admin_nativo = in_array('administrator', (array) $user->roles, true);
        $es_admin_hyundai = in_array('administrador_hyundai', (array) $user->roles, true);

        if (!$es_admin_nativo && !$es_admin_hyundai) {
            return;
        }

        // Si estamos accediendo a edit.php?post_type=dealer o edit.php?post_type=tienda,
        // asegurarse de que tengan permisos
        if (isset($_GET['post_type']) && in_array($_GET['post_type'], ['dealer', 'tienda'], true)) {
            // Forzar que ambos roles tengan edit_posts
            if (!current_user_can('edit_posts')) {
                // Agregar capability temporalmente si no la tiene
                $user_obj = wp_get_current_user();
                $user_obj->add_cap('edit_posts');
            }
        }
    }

    /**
     * Registrar Post Types
     */
    public function register_post_types() {
        register_post_type('dealer', [
            'labels' => [
                'name' => 'Concesionarias',
                'singular_name' => 'Concesionaria',
                'add_new_item' => 'Agregar nueva Concesionaria',
            ],
            'public' => true,
            'menu_icon' => 'dashicons-store',
            'supports' => ['title', 'thumbnail'],
            'show_in_menu' => false,
            'show_in_rest' => true,
            'capability_type' => 'post',
        ]);

        register_post_type('tienda', [
            'labels' => [
                'name' => 'Sucursales',
                'singular_name' => 'Sucursal',
                'add_new_item' => 'Agregar nueva Sucursal',
            ],
            'public' => true,
            'menu_icon' => 'dashicons-admin-home',
            'supports' => ['title'],
            'show_in_menu' => false,
            'show_in_rest' => true,
            'capability_type' => 'post',
        ]);
    }

    /**
     * Reestructurar menú admin
     */
    public function restructure_menu() {
        remove_menu_page('edit.php?post_type=dealer');
        remove_menu_page('edit.php?post_type=tienda');

        add_menu_page(
            'Red de Concesionarias',
            'Red de Concesionarias',
            'read',
            'red_concesionarios',
            '',
            'dashicons-networking',
            6
        );

        add_submenu_page(
            'red_concesionarios',
            'Concesionarias',
            'Concesionarias',
            'read',
            'edit.php?post_type=dealer'
        );

        add_submenu_page(
            'red_concesionarios',
            'Sucursales',
            'Sucursales',
            'read',
            'edit.php?post_type=tienda'
        );

        remove_submenu_page('red_concesionarios', 'red_concesionarios');
    }

    /**
     * Agregar Metaboxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'dealer_info',
            'Información de la Concesionaria',
            [$this, 'render_dealer_metabox'],
            'dealer',
            'normal',
            'high'
        );

        add_meta_box(
            'tienda_info',
            'Información de la Sucursal',
            [$this, 'render_tienda_metabox'],
            'tienda',
            'normal',
            'high'
        );
    }

    /**
     * Renderizar Metabox de Dealer
     */
    public function render_dealer_metabox($post) {
        wp_nonce_field('hu_dealer_nonce', 'hu_dealer_nonce_field');

        $gerentes_asociados = get_post_meta($post->ID, '_gerentes_asociados', true);
        if (!is_array($gerentes_asociados)) {
            $gerentes_asociados = [];
        }

        $gerentes = get_users([
            'role' => 'gerente',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        ?>
        <p>
            <label><strong>Gerentes asociados:</strong></label><br>
            <select name="gerentes_asociados[]" multiple="multiple" style="width:100%; min-height: 160px;">
                <?php foreach ($gerentes as $gerente): ?>
                    <option value="<?php echo esc_attr($gerente->ID); ?>"
                        <?php echo in_array($gerente->ID, $gerentes_asociados, true) ? 'selected="selected"' : ''; ?>>
                        <?php echo esc_html($gerente->display_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <br><small>Mantén presionada la tecla Command o Ctrl para seleccionar más de un gerente.</small>
        </p>
        <?php
    }

    /**
     * Renderizar Metabox de Tienda
     */
    public function render_tienda_metabox($post) {
        wp_nonce_field('hu_tienda_nonce', 'hu_tienda_nonce_field');

        $dealer_id = get_post_meta($post->ID, '_dealer_id', true);
        $codigo = get_post_meta($post->ID, '_codigo', true);
        $departamento = get_post_meta($post->ID, '_departamento', true);
        $provincia = get_post_meta($post->ID, '_provincia', true);
        $jefes_venta_asociados = get_post_meta($post->ID, '_jefes_venta_asociados', true);
        if (!is_array($jefes_venta_asociados)) {
            $jefes_venta_asociados = [];
        }

        $dealers = get_posts([
            'post_type' => 'dealer',
            'numberposts' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $usuarios = get_users([
            'role' => 'jefe_venta',
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);
        ?>

        <p>
            <label><strong>Código:</strong></label><br>
            <input type="text" name="codigo" value="<?php echo esc_attr($codigo); ?>" style="width:100%; padding: 8px;" placeholder="Ej: SUC-001">
        </p>

        <p>
            <label><strong>Concesionaria asociada:</strong></label><br>
            <select name="dealer_id" style="width:100%; padding: 8px;">
                <option value="">— Selecciona una Concesionaria —</option>
                <?php foreach ($dealers as $d): ?>
                    <option value="<?php echo esc_attr($d->ID); ?>"
                        <?php echo selected($dealer_id, $d->ID, false); ?>>
                        <?php echo esc_html($d->post_title); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label><strong>Departamento (opcional):</strong></label><br>
            <select name="departamento" id="departamento-select" style="width:100%; padding: 8px;">
                <option value="">— Selecciona un departamento —</option>
                <?php foreach ($this->departamentos_provincias as $dep => $prov): ?>
                    <option value="<?php echo esc_attr($dep); ?>"
                        <?php echo selected($departamento, $dep, false); ?>>
                        <?php echo esc_html($dep); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label><strong>Provincia (opcional):</strong></label><br>
            <select name="provincia" id="provincia-select" style="width:100%; padding: 8px;">
                <option value="">— Selecciona una provincia —</option>
                <?php
                if ($departamento && isset($this->departamentos_provincias[$departamento])) {
                    foreach ($this->departamentos_provincias[$departamento] as $prov) {
                        echo '<option value="' . esc_attr($prov) . '"' . selected($provincia, $prov, false) . '>' . esc_html($prov) . '</option>';
                    }
                }
                ?>
            </select>
        </p>

        <p>
            <label><strong>Jefes de Venta Asociados (opcional):</strong></label><br>
            <select name="jefes_venta_asociados[]" multiple="multiple" style="width:100%; min-height: 160px;">
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?php echo esc_attr($u->ID); ?>"
                        <?php echo in_array($u->ID, $jefes_venta_asociados, true) ? 'selected="selected"' : ''; ?>>
                        <?php echo esc_html($u->display_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <script>
            const provinciasPorDep = <?php echo json_encode($this->departamentos_provincias); ?>;
        </script>
        <?php
    }

    /**
     * Guardar campos del Dealer
     */
    public function save_dealer_fields($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['hu_dealer_nonce_field']) || !wp_verify_nonce($_POST['hu_dealer_nonce_field'], 'hu_dealer_nonce')) return;

        $gerentes = isset($_POST['gerentes_asociados']) ? (array) $_POST['gerentes_asociados'] : [];
        $gerentes = array_values(array_filter(array_map('intval', $gerentes)));

        update_post_meta($post_id, '_gerentes_asociados', $gerentes);
    }

    /**
     * Guardar campos de la Tienda
     */
    public function save_tienda_fields($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['hu_tienda_nonce_field']) || !wp_verify_nonce($_POST['hu_tienda_nonce_field'], 'hu_tienda_nonce')) return;

        $fields = ['dealer_id', 'codigo', 'departamento', 'provincia'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                update_post_meta($post_id, "_{$f}", sanitize_text_field($_POST[$f]));
            }
        }

        $jefes = isset($_POST['jefes_venta_asociados']) ? (array) $_POST['jefes_venta_asociados'] : [];
        $jefes = array_values(array_filter(array_map('intval', $jefes)));
        update_post_meta($post_id, '_jefes_venta_asociados', $jefes);
    }

    /**
     * JavaScript dinámico para select dependiente de departamento
     */
    public function enqueue_dynamic_js() {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === 'tienda') {
            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const depSelect = document.getElementById('departamento-select');
                    const provSelect = document.getElementById('provincia-select');

                    if (depSelect && provSelect) {
                        depSelect.addEventListener('change', function() {
                            const dep = this.value;
                            provSelect.innerHTML = '<option value="">— Selecciona una provincia —</option>';

                            if (provinciasPorDep[dep]) {
                                provinciasPorDep[dep].forEach(function(prov) {
                                    const opt = document.createElement('option');
                                    opt.value = prov;
                                    opt.textContent = prov;
                                    provSelect.appendChild(opt);
                                });
                            }
                        });
                    }
                });
            </script>
            <?php
        }
    }

    /**
     * Agregar columnas personalizadas en lista de Dealers
     */
    public function add_dealer_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['gerentes'] = 'Gerentes';
            }
        }
        return $new_columns;
    }

    /**
     * Renderizar columnas personalizadas en Dealers
     */
    public function render_dealer_columns($column, $post_id) {
        if ($column === 'gerentes') {
            $gerentes = get_post_meta($post_id, '_gerentes_asociados', true);
            if (is_array($gerentes) && !empty($gerentes)) {
                $nombres = [];
                foreach ($gerentes as $gid) {
                    $user = get_user_by('id', $gid);
                    if ($user) {
                        $nombres[] = esc_html($user->display_name);
                    }
                }
                echo implode(', ', $nombres) ?: '—';
            } else {
                echo '—';
            }
        }
    }

    /**
     * Agregar columnas personalizadas en lista de Tiendas
     */
    public function add_tienda_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['codigo'] = 'Código';
                $new_columns['dealer'] = 'Concesionaria';
                $new_columns['departamento'] = 'Departamento';
                $new_columns['provincia'] = 'Provincia';
                $new_columns['jefe_venta'] = 'Jefe de Venta';
            }
        }
        return $new_columns;
    }

    /**
     * Renderizar columnas personalizadas en Tiendas
     */
    public function render_tienda_columns($column, $post_id) {
        switch ($column) {
            case 'codigo':
                $codigo = get_post_meta($post_id, '_codigo', true);
                echo esc_html($codigo) ?: '—';
                break;

            case 'dealer':
                $dealer_id = get_post_meta($post_id, '_dealer_id', true);
                if ($dealer_id) {
                    echo '<a href="' . esc_url(get_edit_post_link($dealer_id)) . '">' . esc_html(get_the_title($dealer_id)) . '</a>';
                } else {
                    echo '—';
                }
                break;

            case 'departamento':
                $dep = get_post_meta($post_id, '_departamento', true);
                echo esc_html($dep) ?: '—';
                break;

            case 'provincia':
                $prov = get_post_meta($post_id, '_provincia', true);
                echo esc_html($prov) ?: '—';
                break;

            case 'jefe_venta':
                $jefes = get_post_meta($post_id, '_jefes_venta_asociados', true);
                if (is_array($jefes) && !empty($jefes)) {
                    $nombres = [];
                    foreach ($jefes as $jefe_id) {
                        $user = get_user_by('id', (int) $jefe_id);
                        if ($user) {
                            $nombres[] = esc_html($user->display_name);
                        }
                    }
                    echo !empty($nombres) ? implode(', ', $nombres) : '—';
                } else {
                    echo '—';
                }
                break;
        }
    }
}
