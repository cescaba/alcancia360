<?php
/**
 * Página de administración para importar usuarios
 */

if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'billetera_add_import_menu');

function billetera_add_import_menu() {
    add_menu_page(
        'Importar Usuarios',
        'Importar Usuarios',
        'manage_options',
        'billetera_import_users',
        'billetera_render_import_page',
        'dashicons-upload',
        25
    );
}

function billetera_render_import_page() {
    ?>
    <div class="wrap">
        <h1>📥 Importar Usuarios Masivamente</h1>

        <div style="max-width: 900px; margin: 20px 0;">
            <!-- PASO 1: Descargar CSV Ejemplo -->
            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <h2>Paso 1: Descargar CSV de Ejemplo</h2>
                <p>Haz clic para descargar un CSV con la estructura correcta:</p>
                <button class="button button-primary button-large" onclick="descargarCSVEjemplo(); return false;">
                    📥 Descargar CSV Ejemplo
                </button>
            </div>

            <!-- PASO 2: Completar en Excel -->
            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <h2>Paso 2: Completar en Excel</h2>
                <ol>
                    <li>Abre el CSV descargado en Excel</li>
                    <li>Completa las filas con los datos de tus usuarios</li>
                    <li>Guarda el archivo como CSV (Separado por comas)</li>
                </ol>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><strong>Columna</strong></th>
                            <th><strong>Ejemplo</strong></th>
                            <th><strong>Notas</strong></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>nombre</code></td>
                            <td>Juan</td>
                            <td>Requerido</td>
                        </tr>
                        <tr>
                            <td><code>apellido</code></td>
                            <td>Pérez</td>
                            <td>Requerido</td>
                        </tr>
                        <tr>
                            <td><code>user_login</code></td>
                            <td>juan.asesor</td>
                            <td>Único, sin espacios</td>
                        </tr>
                        <tr>
                            <td><code>user_email</code></td>
                            <td>juan@example.com</td>
                            <td>Email único</td>
                        </tr>
                        <tr>
                            <td><code>contraseña</code></td>
                            <td>Temp123456!</td>
                            <td>Mínimo 8 caracteres</td>
                        </tr>
                        <tr>
                            <td><code>rol</code></td>
                            <td>asesor</td>
                            <td>asesor, jefe_venta, gerente, administrador_hyundai</td>
                        </tr>
                        <tr>
                            <td><code>HYU</code></td>
                            <td>1</td>
                            <td>0 o 1 (Hyundai)</td>
                        </tr>
                        <tr>
                            <td><code>HCV</code></td>
                            <td>0</td>
                            <td>0 o 1 (Hyundai)</td>
                        </tr>
                        <tr>
                            <td><code>GEE</code></td>
                            <td>0</td>
                            <td>0 o 1 (Geely)</td>
                        </tr>
                        <tr>
                            <td><code>JMC</code></td>
                            <td>0</td>
                            <td>0 o 1 (JMC)</td>
                        </tr>
                        <tr>
                            <td><code>tipo</code></td>
                            <td>Venta</td>
                            <td>"Venta" o "Post Venta"</td>
                        </tr>
                        <tr>
                            <td><code>tienda</code></td>
                            <td>15</td>
                            <td>ID de la sucursal (opcional)</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- PASO 3: Subir CSV -->
            <div class="card" style="padding: 20px; margin-bottom: 20px;">
                <h2>Paso 3: Subir CSV Completado</h2>
                <form id="csv_import_form" enctype="multipart/form-data" style="max-width: 500px;">
                    <?php wp_nonce_field('billetera_csv_import'); ?>

                    <div style="margin-bottom: 15px;">
                        <label for="csv_file" style="display: block; margin-bottom: 8px; font-weight: bold;">
                            Selecciona tu CSV:
                        </label>
                        <input type="file"
                               id="csv_file"
                               name="csv_file"
                               accept=".csv"
                               required
                               style="padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 100%; box-sizing: border-box;">
                        <small style="color: #666;">Solo archivos CSV (.csv)</small>
                    </div>

                    <button type="submit" class="button button-primary button-large">
                        📤 Importar Usuarios
                    </button>
                </form>

                <div id="import_result" style="margin-top: 20px; display: none;">
                    <div id="import_message"></div>
                </div>
            </div>

            <!-- Info -->
            <div class="card" style="padding: 20px; background-color: #f0f6fc;">
                <h3>ℹ️ Información Importante</h3>
                <ul>
                    <li><strong>Antes de importar:</strong> Ejecuta el SQL para borrar usuarios anteriores (si es necesario)</li>
                    <li><strong>Validación:</strong> El sistema valida que user_login y email sean únicos</li>
                    <li><strong>Permisos:</strong> Solo administradores pueden importar usuarios</li>
                    <li><strong>Errores:</strong> Si hay errores, verás un reporte detallado</li>
                    <li><strong>Sucursales:</strong> El ID de tienda es opcional. Déjalo vacío si no quieres asignar</li>
                </ul>
            </div>
        </div>
    </div>

    <script>
    function descargarCSVEjemplo() {
        // Crear un formulario temporal para descargar el CSV
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?php echo admin_url('admin-ajax.php'); ?>';
        form.innerHTML = `
            <input type="hidden" name="action" value="billetera_download_csv_template">
        `;
        document.body.appendChild(form);
        form.submit();

        // Limpiar después de un pequeño delay
        setTimeout(() => {
            document.body.removeChild(form);
        }, 500);

        return false;
    }

    document.getElementById('csv_import_form').addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        formData.append('action', 'billetera_import_csv');
        formData.append('nonce', '<?php echo wp_create_nonce('billetera_csv_import'); ?>');

        const button = this.querySelector('button');
        button.disabled = true;
        button.textContent = '⏳ Importando...';

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json())
        .then(data => {
            const resultDiv = document.getElementById('import_result');
            const messageDiv = document.getElementById('import_message');

            if (data.success) {
                let erroresHTML = '';
                if (data.data.errores && data.data.errores.length > 0) {
                    erroresHTML = `
                        <details style="margin-top: 15px; padding: 10px; background-color: #fff8e5; border-left: 4px solid #ffb900;">
                            <summary style="cursor: pointer; font-weight: bold;">⚠️ ${data.data.errores_count} usuarios NO se importaron (haz clic para ver)</summary>
                            <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
                                <thead>
                                    <tr style="background-color: #f0f0f0;">
                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Fila</th>
                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">User Login</th>
                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Email</th>
                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Razón</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.data.errores.slice(0, 15).map(err => `
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 8px;">${err.fila || 'N/A'}</td>
                                            <td style="border: 1px solid #ddd; padding: 8px;"><code>${err.login}</code></td>
                                            <td style="border: 1px solid #ddd; padding: 8px;"><code>${err.email}</code></td>
                                            <td style="border: 1px solid #ddd; padding: 8px; color: #c00;">${err.razon}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                            ${data.data.errores.length > 15 ? `<p style="margin-top: 10px; color: #666;">... y ${data.data.errores.length - 15} más</p>` : ''}
                        </details>
                    `;
                }

                messageDiv.innerHTML = `
                    <div class="notice notice-success" style="padding: 15px; margin: 0; border-left: 4px solid #46b450;">
                        <p><strong>✅ Importación completada</strong></p>
                        <ul style="margin: 10px 0;">
                            <li>✅ Usuarios creados: <strong style="color: #46b450;">${data.data.usuarios_creados}</strong></li>
                            <li>✅ Campos asignados: <strong style="color: #46b450;">${data.data.campos_asignados}</strong></li>
                            ${data.data.errores_count > 0 ? `<li>❌ No importados: <strong style="color: #c00;">${data.data.errores_count}</strong></li>` : ''}
                        </ul>
                    </div>
                    ${erroresHTML}
                `;
            } else {
                messageDiv.innerHTML = `
                    <div class="notice notice-error" style="padding: 15px; margin: 0; border-left: 4px solid #dc3545;">
                        <p><strong>❌ Error en la importación</strong></p>
                        <p>${data.data.message}</p>
                    </div>
                `;
            }

            resultDiv.style.display = 'block';
            button.disabled = false;
            button.textContent = '📤 Importar Usuarios';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al procesar: ' + error.message);
            button.disabled = false;
            button.textContent = '📤 Importar Usuarios';
        });
    });
    </script>

    <style>
    .card {
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        background-color: #fff;
    }
    </style>
    <?php
}
