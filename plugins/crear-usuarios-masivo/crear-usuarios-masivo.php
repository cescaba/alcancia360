<?php
/**
 * Plugin Name: Crear Usuarios Masivo
 * Description: Crea múltiples usuarios desde un formulario en el admin
 * Version: 1.0.0
 * Author: VC Studio
 */

if (!defined('ABSPATH')) exit;

class CrearUsuariosMasivo {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_crear_usuarios_masivo', [$this, 'procesar_usuarios']);
    }

    public function add_admin_menu() {
        add_management_page(
            'Crear Usuarios Masivo',
            'Crear Usuarios Masivo',
            'manage_options',
            'crear-usuarios-masivo',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        ?>
        <div class="wrap">
            <h1>Crear Usuarios Masivo</h1>

            <div style="background: #fff; padding: 20px; border-radius: 5px; max-width: 900px;">
                <h2>Instrucciones</h2>
                <p>Pega los datos de los usuarios en formato JSON abajo y haz click en "Crear Usuarios"</p>

                <h3>Formato JSON esperado:</h3>
                <pre style="background: #f4f4f4; padding: 15px; border-radius: 3px; overflow-x: auto;">
[
  {
    "user_login": "juan.asesor",
    "user_email": "juan@example.com",
    "first_name": "Juan",
    "last_name": "Pérez",
    "tienda_id": "456",
    "rol": "asesor",
    "password": "password123"
  },
  {
    "user_login": "maria.jefe",
    "user_email": "maria@example.com",
    "first_name": "María",
    "last_name": "González",
    "tienda_id": "457",
    "rol": "jefe_venta",
    "password": "password456"
  }
]
                </pre>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="form-usuarios">
                    <input type="hidden" name="action" value="crear_usuarios_masivo">
                    <?php wp_nonce_field('crear_usuarios_nonce', 'nonce'); ?>

                    <p>
                        <label for="usuarios_json" style="display: block; margin-bottom: 10px; font-weight: bold;">
                            Datos de Usuarios (JSON):
                        </label>
                        <textarea
                            name="usuarios_json"
                            id="usuarios_json"
                            rows="15"
                            style="width: 100%; font-family: monospace; padding: 10px; border: 1px solid #ccc; border-radius: 3px;"
                            placeholder="Pega aquí el JSON con los datos de usuarios..."
                            required></textarea>
                    </p>

                    <p>
                        <button type="submit" class="button button-primary button-large" style="padding: 10px 20px; font-size: 16px;">
                            ✓ Crear Usuarios
                        </button>
                    </p>
                </form>
            </div>

            <?php if (isset($_GET['status'])): ?>
                <div style="margin-top: 30px; padding: 20px; background: #fff; border-radius: 5px;">
                    <?php
                    $status = sanitize_text_field($_GET['status']);
                    if ($status === 'success'):
                        $created = isset($_GET['created']) ? intval($_GET['created']) : 0;
                        $errors = isset($_GET['errors']) ? intval($_GET['errors']) : 0;
                    ?>
                        <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 3px; color: #155724;">
                            <strong>✓ Proceso completado</strong><br>
                            Usuarios creados: <strong><?php echo $created; ?></strong><br>
                            Errores: <strong><?php echo $errors; ?></strong>
                        </div>
                    <?php elseif ($status === 'error'): ?>
                        <div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 3px; color: #721c24;">
                            <strong>✗ Error en el proceso</strong><br>
                            <?php echo isset($_GET['message']) ? sanitize_text_field($_GET['message']) : 'Intenta nuevamente'; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function procesar_usuarios() {
        // Verificar permisos y nonce
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para hacer esto');
        }

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'crear_usuarios_nonce')) {
            wp_die('Error de seguridad');
        }

        if (!isset($_POST['usuarios_json'])) {
            wp_die('Faltan datos');
        }

        $json_data = stripslashes($_POST['usuarios_json']);
        $usuarios = json_decode($json_data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_redirect(add_query_arg([
                'page' => 'crear-usuarios-masivo',
                'status' => 'error',
                'message' => 'JSON inválido: ' . json_last_error_msg()
            ], admin_url('tools.php')));
            exit;
        }

        if (!is_array($usuarios)) {
            wp_redirect(add_query_arg([
                'page' => 'crear-usuarios-masivo',
                'status' => 'error',
                'message' => 'El JSON debe ser un array de objetos'
            ], admin_url('tools.php')));
            exit;
        }

        $created = 0;
        $errors = 0;

        foreach ($usuarios as $user_data) {
            // Validar campos requeridos
            if (empty($user_data['user_login']) || empty($user_data['user_email'])) {
                $errors++;
                continue;
            }

            $user_id = wp_insert_user([
                'user_login' => sanitize_user($user_data['user_login']),
                'user_email' => sanitize_email($user_data['user_email']),
                'first_name' => isset($user_data['first_name']) ? sanitize_text_field($user_data['first_name']) : '',
                'last_name' => isset($user_data['last_name']) ? sanitize_text_field($user_data['last_name']) : '',
                'user_pass' => $user_data['password'] ?? wp_generate_password(),
                'role' => isset($user_data['rol']) ? sanitize_text_field($user_data['rol']) : 'subscriber'
            ]);

            if (!is_wp_error($user_id)) {
                // Agregar tienda asociada si existe
                if (!empty($user_data['tienda_id'])) {
                    update_user_meta($user_id, '_tienda_asociada', sanitize_text_field($user_data['tienda_id']));
                }
                $created++;
            } else {
                $errors++;
            }
        }

        wp_redirect(add_query_arg([
            'page' => 'crear-usuarios-masivo',
            'status' => 'success',
            'created' => $created,
            'errors' => $errors
        ], admin_url('tools.php')));
        exit;
    }
}

new CrearUsuariosMasivo();
