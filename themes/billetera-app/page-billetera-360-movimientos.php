<?php
/**
 * Template Name: Billetera 360 Movimientos
 * Description: Billetera 360 - Pantalla de Movimientos
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

// Detectar URL de página con template Billetera 360 Responsive
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

$registro_url = get_page_by_template('page-billetera-360-responsive.php');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billetera 360 - Movimientos</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
</head>
<body>

<div class="site-wrapper">
    <?php get_template_part('template-parts/header-sidebar'); ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <?php get_template_part('template-parts/header-topbar'); ?>

        <!-- SCREEN: MOVIMIENTOS -->
        <div class="screen active" id="screen-wallet">
            <div class="balance-card">
                <div class="balance-label">Saldo del mes</div>
                <div class="balance-value" id="balance-value">S/ 0.00</div>
                <div class="balance-sub">
                    <div>
                        <div class="balance-sub-label">Acumulado</div>
                        <div class="balance-sub-value" id="accum-value">S/ 0.00</div>
                    </div>
                    <div>
                        <div class="balance-sub-label">Ranking dealer</div>
                        <div class="balance-sub-value">#3 de 14</div>
                    </div>
                </div>
            </div>

            <div class="section-title">Movimientos recientes</div>

            <div class="movements-container">
                <div class="movs" id="movs-list"></div>
            </div>
        </div>

        <!-- MOBILE TABBAR -->
        <div class="mobile-tabbar">
            <a href="<?php echo esc_url($registro_url); ?>" class="mobile-tab" id="mobile-tab-registro">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Registrar
            </a>
            <button class="mobile-tab active" id="mobile-tab-wallet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/></svg>
                Movimientos
            </button>
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
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-movimientos.js"></script>

</body>
</html>
