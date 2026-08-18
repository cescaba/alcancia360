<?php
/**
 * Gestión de Roles para Hyundai Usuarios
 * Crea 4 roles con capabilities simples y claras
 */

if (!defined('ABSPATH')) exit;

class HU_Roles {

    public function __construct() {
        add_action('hu_create_roles', [$this, 'create_roles']);
        add_action('admin_menu', [$this, 'register_migration_page'], 20);
        add_action('admin_menu', [$this, 'register_cleanup_page'], 21);
        add_action('admin_menu', [$this, 'filter_menu_by_role'], 25);
        add_action('admin_menu', [$this, 'remove_unwanted_menus_for_admin_hyundai'], 99);
        add_action('admin_notices', [$this, 'show_migration_notice']);
    }

    /**
     * Crear roles (solo si no existen)
     */
    public function create_roles() {
        // Asesor: solo lectura básica
        if (!get_role('asesor')) {
            add_role('asesor', 'Asesor', ['read' => true]);
        }

        // Jefe de Venta (NUEVO: jefe_venta, reemplaza al jefe_de_venta antiguo)
        if (!get_role('jefe_venta')) {
            add_role('jefe_venta', 'Jefe de Venta', ['read' => true]);
        }

        // Gerente: solo lectura básica
        if (!get_role('gerente')) {
            add_role('gerente', 'Gerente', ['read' => true]);
        }

        // Administrador Hyundai: gestionar usuarios y dealers/tiendas (SIN manage_options)
        $admin_hu_role = get_role('administrador_hyundai');
        if (!$admin_hu_role) {
            add_role(
                'administrador_hyundai',
                'Administrador Hyundai',
                [
                    'read' => true,
                    'manage_users' => true,
                    'edit_users' => true,
                    'list_users' => true,
                    'create_users' => true,
                    'delete_users' => true,
                    'promote_users' => true,
                    'edit_dealers' => true,
                ]
            );
            $admin_hu_role = get_role('administrador_hyundai');
        }

        // Siempre asegurar que tenga estas capabilities
        if ($admin_hu_role) {
            $admin_hu_role->add_cap('read');
            $admin_hu_role->add_cap('manage_users');
            $admin_hu_role->add_cap('edit_users');
            $admin_hu_role->add_cap('list_users');
            $admin_hu_role->add_cap('create_users');
            $admin_hu_role->add_cap('delete_users');
            $admin_hu_role->add_cap('promote_users');

            // Capabilities base (necesarias para crear posts)
            $admin_hu_role->add_cap('edit_posts');
            $admin_hu_role->add_cap('publish_posts');

            // Capabilities para dealer (completo)
            $dealer_caps = [
                'create_dealers',
                'read_dealer',
                'read_private_dealers',
                'edit_dealers',
                'edit_others_dealers',
                'edit_private_dealers',
                'edit_published_dealers',
                'publish_dealers',
                'delete_dealers',
                'delete_others_dealers',
                'delete_private_dealers',
                'delete_published_dealers',
            ];
            foreach ($dealer_caps as $cap) {
                $admin_hu_role->add_cap($cap);
            }

            // Capabilities para tienda (completo)
            $tienda_caps = [
                'create_tiendas',
                'read_tienda',
                'read_private_tiendas',
                'edit_tiendas',
                'edit_others_tiendas',
                'edit_private_tiendas',
                'edit_published_tiendas',
                'publish_tiendas',
                'delete_tiendas',
                'delete_others_tiendas',
                'delete_private_tiendas',
                'delete_published_tiendas',
            ];
            foreach ($tienda_caps as $cap) {
                $admin_hu_role->add_cap($cap);
            }

            $admin_hu_role->remove_cap('manage_options');
        }

        // Dar capabilities al administrator nativo también
        $admin_role = get_role('administrator');
        if ($admin_role) {
            // Dealer
            $admin_role->add_cap('create_dealers');
            $admin_role->add_cap('edit_dealers');
            $admin_role->add_cap('edit_published_dealers');
            $admin_role->add_cap('edit_private_dealers');
            $admin_role->add_cap('edit_others_dealers');
            $admin_role->add_cap('delete_dealers');
            $admin_role->add_cap('delete_published_dealers');
            $admin_role->add_cap('delete_private_dealers');
            $admin_role->add_cap('delete_others_dealers');
            $admin_role->add_cap('publish_dealers');
            $admin_role->add_cap('read_private_dealers');

            // Tienda
            $admin_role->add_cap('create_tiendas');
            $admin_role->add_cap('edit_tiendas');
            $admin_role->add_cap('edit_published_tiendas');
            $admin_role->add_cap('edit_private_tiendas');
            $admin_role->add_cap('edit_others_tiendas');
            $admin_role->add_cap('delete_tiendas');
            $admin_role->add_cap('delete_published_tiendas');
            $admin_role->add_cap('delete_private_tiendas');
            $admin_role->add_cap('delete_others_tiendas');
            $admin_role->add_cap('publish_tiendas');
            $admin_role->add_cap('read_private_tiendas');
        }
    }

    /**
     * Registrar página de debug
     */
    public function register_debug_page() {
        add_submenu_page(
            'index.php',
            'HU Debug',
            'HU Debug',
            'read',
            'hu-debug',
            [$this, 'render_debug_page']
        );
    }

    /**
     * Renderizar página de debug
     */
    public function render_debug_page() {
        $user = wp_get_current_user();
        ?>
        <div class="wrap">
            <h1>Debug - Hyundai Usuarios</h1>
            <div style="background: #f0f0f0; padding: 20px; border-left: 4px solid #ff0000; margin: 20px 0;">
                <h2>Usuario Actual</h2>
                <p><strong>ID:</strong> <?php echo $user->ID; ?></p>
                <p><strong>Nombre:</strong> <?php echo $user->user_login; ?></p>
                <p><strong>Rol(es):</strong> <?php echo implode(', ', (array) $user->roles); ?></p>

                <h2>Capabilities</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr style="background: #fff; border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px;"><strong>read</strong></td>
                        <td style="padding: 10px;"><?php echo current_user_can('read') ? '✅ SÍ' : '❌ NO'; ?></td>
                    </tr>
                    <tr style="background: #fff; border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px;"><strong>manage_users</strong></td>
                        <td style="padding: 10px;"><?php echo current_user_can('manage_users') ? '✅ SÍ' : '❌ NO'; ?></td>
                    </tr>
                    <tr style="background: #fff; border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px;"><strong>edit_dealers</strong></td>
                        <td style="padding: 10px;"><?php echo current_user_can('edit_dealers') ? '✅ SÍ' : '❌ NO'; ?></td>
                    </tr>
                    <tr style="background: #fff; border-bottom: 1px solid #ddd;">
                        <td style="padding: 10px;"><strong>manage_options</strong></td>
                        <td style="padding: 10px;"><?php echo current_user_can('manage_options') ? '✅ SÍ' : '❌ NO'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Filtrar menú por rol: remover Migrar/Limpieza para administrador_hyundai
     */
    public function filter_menu_by_role() {
        $user = wp_get_current_user();

        // Si es administrador_hyundai, remover Migrar Usuarios y Limpieza de Roles
        if (in_array('administrador_hyundai', (array) $user->roles, true)) {
            remove_submenu_page('red_concesionarios', 'hu-migrate');
            remove_submenu_page('red_concesionarios', 'hu-cleanup');
        }
    }

    /**
     * Remover menús no deseados para administrador_hyundai
     */
    public function remove_unwanted_menus_for_admin_hyundai() {
        global $menu;
        $user = wp_get_current_user();

        // Solo aplicar a administrador_hyundai
        if (!in_array('administrador_hyundai', (array) $user->roles, true)) {
            return;
        }

        // Menús que SÍ puede ver
        $allowed_menus = [
            'users.php',              // Usuarios
            'red_concesionarios',     // Red de Concesionarias
            'hc-cursos',              // KPIs Training
        ];

        // Remover todos los menús excepto los permitidos
        if (!empty($menu)) {
            foreach ($menu as $key => $menu_item) {
                $menu_slug = $menu_item[2] ?? '';

                // Si el slug no está en la lista de permitidos, removerlo
                if (!in_array($menu_slug, $allowed_menus, true)) {
                    unset($menu[$key]);
                }
            }
        }
    }

    /**
     * Mostrar aviso si hay usuarios para migrar
     */
    public function show_migration_notice() {
        if (!current_user_can('manage_options')) return;

        $users = count_users();
        $customer_count = isset($users['avail_roles']['customer']) ? $users['avail_roles']['customer'] : 0;
        $jefe_old_count = isset($users['avail_roles']['jefe_de_venta']) ? $users['avail_roles']['jefe_de_venta'] : 0;
        $author_count = isset($users['avail_roles']['author']) ? $users['avail_roles']['author'] : 0;

        if ($customer_count === 0 && $jefe_old_count === 0 && $author_count === 0) {
            return;
        }

        $total = $customer_count + $jefe_old_count + $author_count;
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Hyundai Usuarios:</strong> Tienes ' . $total . ' usuarios para migrar. ';
        echo '<a href="' . admin_url('admin.php?page=hu-migrate') . '" class="button button-primary">Migrar ahora</a>';
        echo '</p></div>';
    }

    /**
     * Registrar página de migración (solo para super admin)
     */
    public function register_migration_page() {
        add_submenu_page(
            'red_concesionarios',
            'Migrar Usuarios',
            'Migrar Usuarios',
            'manage_options',
            'hu-migrate',
            [$this, 'render_migration_page']
        );
    }

    /**
     * Página de migración
     */
    public function render_migration_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        // Procesar migración
        if (isset($_POST['hu_migrate']) && wp_verify_nonce($_POST['hu_nonce'], 'hu_migrate')) {
            $this->do_migration();
        }

        $customer_users = get_users(['role' => 'customer']);
        $jefe_old_users = get_users(['role' => 'jefe_de_venta']);
        $author_users = get_users(['role' => 'author']);
        $customer_count = count($customer_users);
        $jefe_count = count($jefe_old_users);
        $author_count = count($author_users);
        $total = $customer_count + $jefe_count + $author_count;
        ?>
        <div class="wrap">
            <h1>Migración de Usuarios - Hyundai Usuarios</h1>

            <?php if ($total > 0): ?>
                <div class="card" style="padding: 20px;">
                    <h2>Migración de Usuarios</h2>
                    <p>Se migrará un total de <strong><?php echo $total; ?> usuarios</strong>:</p>
                    <ul style="list-style: disc; margin-left: 20px;">
                        <?php if ($customer_count > 0): ?>
                            <li><?php echo $customer_count; ?> usuarios "customer" → "asesor"</li>
                        <?php endif; ?>
                        <?php if ($jefe_count > 0): ?>
                            <li><?php echo $jefe_count; ?> usuarios "jefe_de_venta" → "jefe_venta"</li>
                        <?php endif; ?>
                        <?php if ($author_count > 0): ?>
                            <li><?php echo $author_count; ?> usuarios "author" → "administrador_hyundai"</li>
                        <?php endif; ?>
                    </ul>
                    <p style="margin-top: 20px;">Los usuarios mantendrán todos sus datos. Solo cambiaremos sus roles.</p>

                    <form method="post" style="margin-top: 20px;">
                        <?php wp_nonce_field('hu_migrate', 'hu_nonce'); ?>
                        <input type="hidden" name="hu_migrate" value="1">
                        <button type="submit" class="button button-primary button-large"
                            onclick="return confirm('Migrar <?php echo $total; ?> usuarios. ¿Continuar?');">
                            ✓ Ejecutar Migración
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card" style="padding: 20px;">
                    <h2>✓ Migración completa</h2>
                    <p>No hay usuarios para migrar. El sistema está actualizado.</p>
                    <p>Puedes desactivar el plugin Capability Manager sin problemas.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Ejecutar migración de todos los roles
     */
    private function do_migration() {
        $migrated_asesor = 0;
        $migrated_jefe = 0;
        $migrated_admin = 0;

        // Migrar customer → asesor
        $customer_users = get_users(['role' => 'customer']);
        foreach ($customer_users as $user) {
            $user_obj = new WP_User($user->ID);
            $user_obj->remove_role('customer');
            $user_obj->add_role('asesor');
            $migrated_asesor++;
        }

        // Migrar jefe_de_venta → jefe_venta
        $jefe_users = get_users(['role' => 'jefe_de_venta']);
        foreach ($jefe_users as $user) {
            $user_obj = new WP_User($user->ID);
            $user_obj->remove_role('jefe_de_venta');
            $user_obj->add_role('jefe_venta');
            $migrated_jefe++;
        }

        // Migrar author → administrador_hyundai
        $author_users = get_users(['role' => 'author']);
        foreach ($author_users as $user) {
            $user_obj = new WP_User($user->ID);
            $user_obj->remove_role('author');
            $user_obj->add_role('administrador_hyundai');
            $migrated_admin++;
        }

        echo '<div class="notice notice-success"><p>';
        echo '✓ <strong>Migración completada:</strong><br>';
        if ($migrated_asesor > 0) {
            echo '  • ' . $migrated_asesor . ' usuarios customer → asesor<br>';
        }
        if ($migrated_jefe > 0) {
            echo '  • ' . $migrated_jefe . ' usuarios jefe_de_venta → jefe_venta<br>';
        }
        if ($migrated_admin > 0) {
            echo '  • ' . $migrated_admin . ' usuarios author → administrador_hyundai<br>';
        }
        echo '<br><strong>Ahora puedes desactivar Capability Manager.</strong>';
        echo '</p></div>';
    }

    /**
     * Registrar página de limpieza de roles antiguos
     */
    public function register_cleanup_page() {
        add_submenu_page(
            'red_concesionarios',
            'Limpieza de Roles',
            'Limpieza de Roles',
            'manage_options',
            'hu-cleanup',
            [$this, 'render_cleanup_page']
        );
    }

    /**
     * Página de limpieza de roles antiguos
     */
    public function render_cleanup_page() {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos.');
        }

        // Procesar limpieza
        if (isset($_POST['hu_cleanup']) && wp_verify_nonce($_POST['hu_nonce'], 'hu_cleanup')) {
            $this->do_cleanup();
        }
        ?>
        <div class="wrap">
            <h1>Limpieza de Roles Antiguos - Hyundai Usuarios</h1>

            <div class="card" style="padding: 20px;">
                <h2>Eliminar roles antiguos</h2>
                <p>Esta operación elimina los roles antiguos de Capability Manager:</p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>✓ Elimina "customer"</li>
                    <li>✓ Elimina "author"</li>
                    <li>✓ Elimina "jefe_de_venta"</li>
                    <li>✓ Los usuarios ya están migrados a los nuevos roles</li>
                </ul>
                <p style="margin-top: 20px;"><strong>Nota:</strong> Ejecuta esto solo después de completar la migración de usuarios.</p>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field('hu_cleanup', 'hu_nonce'); ?>
                    <input type="hidden" name="hu_cleanup" value="1">
                    <button type="submit" class="button button-primary button-large"
                        onclick="return confirm('¿Ejecutar limpieza de roles antiguos?');">
                        ✓ Ejecutar Limpieza
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Ejecutar limpieza de roles antiguos
     */
    private function do_cleanup() {
        remove_role('customer');
        remove_role('author');
        remove_role('jefe_de_venta');

        echo '<div class="notice notice-success"><p>';
        echo '✓ <strong>Limpieza completada:</strong><br>';
        echo '  • "customer" eliminado<br>';
        echo '  • "author" eliminado<br>';
        echo '  • "jefe_de_venta" eliminado<br>';
        echo '</p></div>';
    }
}
