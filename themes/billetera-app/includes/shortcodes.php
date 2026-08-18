<?php
/**
 * Shortcodes for Billetera App
 */

// Shortcode para el dashboard
add_shortcode('billetera_dashboard', 'billetera_dashboard_shortcode');
function billetera_dashboard_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="alert alert-danger">Debes iniciar sesión para ver el dashboard.</div>';
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Verificar que el usuario sea asesor
    if (!in_array('asesor', $current_user->roles)) {
        return '<div class="alert alert-warning">Acceso restringido. Solo asesores pueden ver esta sección.</div>';
    }

    ob_start();
    ?>
    <div class="dashboard">
        <div class="user-info">
            <div>
                <p class="user-greeting">Bienvenido, <?php echo esc_html($current_user->display_name); ?></p>
                <p><?php echo esc_html($current_user->user_email); ?></p>
            </div>
        </div>

        <?php
        // Mostrar estadísticas
        $total_sales = billetera_get_user_total_sales($user_id);
        $sales_count = billetera_get_user_sales_count($user_id);
        $average_sale = $sales_count > 0 ? $total_sales / $sales_count : 0;
        ?>

        <div class="dashboard-grid">
            <div class="stat-card">
                <h3>Saldo Total</h3>
                <div class="value">$<?php echo number_format($total_sales, 2, '.', ','); ?></div>
            </div>
            <div class="stat-card">
                <h3>Número de Ventas</h3>
                <div class="value"><?php echo intval($sales_count); ?></div>
            </div>
            <div class="stat-card">
                <h3>Promedio de Venta</h3>
                <div class="value">$<?php echo number_format($average_sale, 2, '.', ','); ?></div>
            </div>
        </div>

        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h2>Mis Ventas Recientes</h2>
            </div>
            <?php echo billetera_get_user_sales_table($user_id); ?>
        </div>
    </div>

    <?php
    return ob_get_clean();
}

// Shortcode para el formulario de ventas
add_shortcode('billetera_sales_form', 'billetera_sales_form_shortcode');
function billetera_sales_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="alert alert-danger">Debes iniciar sesión para registrar ventas.</div>';
    }

    $current_user = wp_get_current_user();

    // Verificar que el usuario sea asesor
    if (!in_array('asesor', $current_user->roles)) {
        return '<div class="alert alert-warning">Acceso restringido. Solo asesores pueden registrar ventas.</div>';
    }

    ob_start();
    ?>
    <div class="card" style="max-width: 600px; margin: 0 auto;">
        <div class="card-header">
            <h2>Registrar Nueva Venta</h2>
        </div>

        <form id="billetera-sales-form" method="POST">
            <div class="form-group">
                <label for="amount">Monto de Venta ($)</label>
                <input
                    type="number"
                    id="amount"
                    name="amount"
                    step="0.01"
                    min="0"
                    required
                    placeholder="0.00"
                >
            </div>

            <div class="form-group">
                <label for="description">Descripción (opcional)</label>
                <textarea
                    id="description"
                    name="description"
                    placeholder="Describe esta venta..."
                ></textarea>
            </div>

            <?php wp_nonce_field('billetera_sales_nonce', 'billetera_nonce'); ?>

            <button type="submit" class="btn btn-primary btn-block">
                Registrar Venta
            </button>
        </form>

        <div id="form-message" style="margin-top: 1rem;"></div>
    </div>

    <script>
    document.getElementById('billetera-sales-form').addEventListener('submit', function(e) {
        e.preventDefault();

        const amount = document.getElementById('amount').value;
        const description = document.getElementById('description').value;
        const nonce = document.querySelector('input[name="billetera_nonce"]').value;
        const messageDiv = document.getElementById('form-message');

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=billetera_add_sale&amount=' + encodeURIComponent(amount) +
                  '&description=' + encodeURIComponent(description) +
                  '&nonce=' + encodeURIComponent(nonce)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                messageDiv.innerHTML = '<div class="alert alert-success">¡Venta registrada exitosamente!</div>';
                document.getElementById('billetera-sales-form').reset();
                setTimeout(() => location.reload(), 1500);
            } else {
                messageDiv.innerHTML = '<div class="alert alert-danger">' + data.data.message + '</div>';
            }
        })
        .catch(error => {
            messageDiv.innerHTML = '<div class="alert alert-danger">Error al registrar la venta.</div>';
        });
    });
    </script>

    <?php
    return ob_get_clean();
}

/**
 * Funciones auxiliares
 */

function billetera_get_user_total_sales($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'billetera_ventas';

    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(monto_comision_sol) FROM $table_name WHERE usuario_id = %d",
        $user_id
    ));

    return $result ? floatval($result) : 0;
}

function billetera_get_user_sales_count($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'billetera_ventas';

    $result = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE usuario_id = %d",
        $user_id
    ));

    return intval($result);
}

function billetera_get_user_sales_table($user_id, $limit = 10) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'billetera_ventas';

    $sales = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE usuario_id = %d ORDER BY creado_en DESC LIMIT %d",
        $user_id,
        $limit
    ));

    if (empty($sales)) {
        return '<p style="padding: 1rem; text-align: center; color: var(--gray-500);">No hay ventas registradas aún.</p>';
    }

    $html = '<div class="table-responsive"><table>';
    $html .= '<thead><tr>';
    $html .= '<th>Monto</th>';
    $html .= '<th>Descripción</th>';
    $html .= '<th>Fecha</th>';
    $html .= '<th>Acciones</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';

    foreach ($sales as $sale) {
        $html .= '<tr>';
        $html .= '<td><strong>$' . number_format($sale->amount, 2, '.', ',') . '</strong></td>';
        $html .= '<td>' . (empty($sale->description) ? '<em>Sin descripción</em>' : esc_html($sale->description)) . '</td>';
        $html .= '<td>' . date('d/m/Y H:i', strtotime($sale->created_at)) . '</td>';
        $html .= '<td>';
        $html .= '<button class="btn btn-danger btn-delete-sale" data-sale-id="' . intval($sale->id) . '" style="padding: 0.5rem 1rem; font-size: 0.875rem;">Eliminar</button>';
        $html .= '</td>';
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';

    // Script para eliminar ventas
    $html .= '<script>
    document.querySelectorAll(".btn-delete-sale").forEach(btn => {
        btn.addEventListener("click", function() {
            if (confirm("¿Estás seguro de que deseas eliminar esta venta?")) {
                const saleId = this.getAttribute("data-sale-id");
                fetch("' . admin_url('admin-ajax.php') . '", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "action=billetera_delete_sale&sale_id=" + saleId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
        });
    });
    </script>';

    return $html;
}
