<?php
/**
 * Template Name: Billetera 360 Responsive
 * Description: Billetera 360 con diseño responsive (Desktop + Móvil)
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$allowed_roles = array('asesor', 'administrator');
if (!array_intersect($allowed_roles, $current_user->roles)) {
    wp_die('Acceso restringido. Solo administradores y asesores pueden acceder.');
}

// Detectar URL de página con template Billetera 360 Movimientos
function get_page_by_template($template_name) {
    global $wpdb;
    $page = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
         WHERE {$wpdb->posts}.post_type = 'page'
         AND {$wpdb->posts}.post_status = 'publish'
         AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
         AND {$wpdb->postmeta}.meta_value = %s
         LIMIT 1",
        $template_name
    ));
    return $page ? get_permalink($page->ID) : '#';
}

$movimientos_url = get_page_by_template('page-billetera-360-movimientos.php');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billetera 360</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
</head>
<body>

<div class="site-wrapper">
    <?php get_template_part('template-parts/header-sidebar'); ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <?php get_template_part('template-parts/header-topbar'); ?>

        <!-- SCREEN: REGISTRO VENTA -->
        <div class="screen active" id="screen-registro">
            <h1>Registrar venta</h1>

            <div class="registro-container">
                <div class="form-card">
                    <p class="field-label">Identificar con</p>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 22px;" id="id-seg">
                        <button style="padding: 9px 4px; background: #f9fafb; border: 1px solid var(--gray-border); border-radius: 10px; cursor: pointer; font-size: 12px; font-weight: 600; color: #7f8ca0;" data-type="placa">Placa</button>
                        <button style="padding: 9px 4px; background: #f9fafb; border: 1px solid var(--gray-border); border-radius: 10px; cursor: pointer; font-size: 12px; font-weight: 600; color: #7f8ca0;" data-type="vin">VIN</button>
                        <button style="padding: 9px 4px; background: #f9fafb; border: 1px solid var(--gray-border); border-radius: 10px; cursor: pointer; font-size: 12px; font-weight: 600; color: #7f8ca0;" data-type="ot">OT</button>
                        <button style="padding: 9px 4px; background: #f9fafb; border: 1px solid var(--gray-border); border-radius: 10px; cursor: pointer; font-size: 12px; font-weight: 600; color: #7f8ca0;" data-type="factura">N° Factura</button>
                    </div>

                    <div class="form-row">
                        <div>
                            <p class="field-label">Identificador</p>
                            <input type="text" id="id-input" placeholder="ABC-123">
                        </div>
                        <div>
                            <p class="field-label">Marca</p>
                            <select id="marca-select">
                                <option value="">Selecciona una marca</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div>
                            <p class="field-label">Categoría</p>
                            <select id="cat-select" disabled>
                                <option value="">Primero elige una marca</option>
                            </select>
                        </div>
                        <div>
                            <p class="field-label">Subcategoría</p>
                            <select id="sub-select" disabled>
                                <option value="">Primero elige una categoría</option>
                            </select>
                        </div>
                    </div>

                    <div id="cantidad-wrap" style="margin-bottom: 22px; display:none;">
                        <div>
                            <p class="field-label">Cantidad</p>
                            <input type="number" id="cantidad-input" min="1" value="1" placeholder="1">
                        </div>
                    </div>
                </div>

                <div class="preview-sidebar">
                    <div class="amount-preview">
                        <span class="label">Comisión estimada</span>
                        <span class="value" id="preview-amt">S/ 0.00</span>
                    </div>
                    <button class="btn btn-primary" id="submit-btn">Registrar venta</button>
                    <p class="hint">La venta se suma a tu billetera al instante y queda visible en tus movimientos.</p>

                    <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--gray-border);">
                        <div class="stat-label">Saldo del mes</div>
                        <div class="stat-value" id="balance-preview">S/ 0.00</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MOBILE TABBAR -->
        <div class="mobile-tabbar">
            <button class="mobile-tab active" id="mobile-tab-registro">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Registrar
            </button>
            <a href="<?php echo esc_url($movimientos_url); ?>" class="mobile-tab" id="mobile-tab-wallet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/></svg>
                Movimientos
            </a>
        </div>
    </div>
</div>

<!-- OVERLAY DE CELEBRACIÓN -->
<div class="overlay" id="overlay">
    <div class="burst-zone" id="burst-zone">
        <div style="text-align: center;">
            <div class="gain-amount" id="gain-amount">+S/ 0.00</div>
            <div class="gain-label">Comisión acreditada</div>
            <button class="btn btn-white" id="continue-btn">Ver mis movimientos</button>
        </div>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>'
};

// User menu toggle
document.addEventListener('DOMContentLoaded', function() {
    // Desktop sidebar
    const desktopUserMenu = document.querySelector('.sidebar .user-menu');
    const desktopDropdown = document.querySelector('.sidebar .user-menu-dropdown');
    if (desktopUserMenu && desktopDropdown) {
        const desktopToggle = desktopUserMenu.querySelector('.user-button');
        if (desktopToggle) {
            desktopToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                desktopDropdown.classList.toggle('active');
            });
        }
    }

    // Mobile topbar
    const mobileTopbar = document.querySelector('.mobile-topbar');
    const mobileDropdown = document.querySelector('.mobile-topbar .user-menu-dropdown');
    if (mobileTopbar && mobileDropdown) {
        const mobileToggle = mobileTopbar.querySelector('.user-button');
        if (mobileToggle) {
            mobileToggle.addEventListener('click', (e) => {
                e.stopPropagation();
                mobileDropdown.classList.toggle('active');
            });
        }
    }

    // Close menu when clicking outside
    document.addEventListener('click', (e) => {
        // Si el click es dentro de .user-menu (desktop), no cerrar
        if (e.target.closest('.sidebar .user-menu')) {
            return;
        }
        // Si el click es dentro del .mobile-topbar, no cerrar
        if (e.target.closest('.mobile-topbar')) {
            return;
        }
        // Cerrar todos los menús
        if (desktopDropdown) desktopDropdown.classList.remove('active');
        if (mobileDropdown) mobileDropdown.classList.remove('active');
    });
});
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-logic.js"></script>

</body>
</html>
