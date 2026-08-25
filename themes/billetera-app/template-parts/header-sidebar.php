<?php
// Detectar URLs dinámicamente
global $wpdb;
$registro_page = $wpdb->get_row($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
     INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
     WHERE {$wpdb->posts}.post_type = 'page'
     AND {$wpdb->posts}.post_status = 'publish'
     AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
     AND {$wpdb->postmeta}.meta_value = %s
     LIMIT 1",
    'page-billetera-360-responsive.php'
));
$movimientos_page = $wpdb->get_row($wpdb->prepare(
    "SELECT ID FROM {$wpdb->posts}
     INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
     WHERE {$wpdb->posts}.post_type = 'page'
     AND {$wpdb->posts}.post_status = 'publish'
     AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
     AND {$wpdb->postmeta}.meta_value = %s
     LIMIT 1",
    'page-billetera-360-movimientos.php'
));

$registro_url = $registro_page ? get_permalink($registro_page->ID) : '#';
$movimientos_url = $movimientos_page ? get_permalink($movimientos_page->ID) : '#';
$current_user = wp_get_current_user();
?>

<!-- DESKTOP SIDEBAR -->
<div class="sidebar">
    <div class="logo-brand">
        <div class="logo-mark">
            <img src="<?php echo get_template_directory_uri(); ?>/assets/img/logo-gildemeister.png" alt="Gildemeister">
        </div>
        <div class="logo-text">
            <p class="brand-name">Gildemeister</p>
            <p class="brand-sub">Mi alcancia 360</p>
        </div>
    </div>

    <div class="nav-buttons">
        <?php if (!in_array('jefe_venta', (array) $current_user->roles)): ?>
        <a href="<?php echo esc_url($registro_url); ?>" class="nav-button" id="nav-registro">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"></path></svg>
            Registrar venta
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url($movimientos_url); ?>" class="nav-button" id="nav-wallet">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"></rect><path d="M3 10h18M16 14h2"></path></svg>
            Movimientos
        </a>
    </div>

    <div class="user-menu">
        <button class="user-button" id="user-toggle">
            <div class="user-avatar">
                <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-4 4-6 8-6s8 2 8 6"></path></svg>
            </div>
            <div class="user-info">
                <p><?php echo esc_html($current_user->display_name); ?></p>
                <p class="sub"><?php echo in_array('jefe_venta', (array) $current_user->roles) ? 'Jefe de Venta' : 'Asesor'; ?></p>
            </div>
        </button>
        <div class="user-menu-dropdown" id="user-dropdown">
            <a href="<?php echo esc_url(home_url('/cambiar-contrasena')); ?>" style="display: block; padding: 12px 16px; text-decoration: none; color: var(--text-primary); font-family: Inter, sans-serif; font-size: 13px; font-weight: 500; border-bottom: 1px solid var(--gray-border); transition: background 0.15s;" onmouseover="this.style.background='var(--gray-light)'" onmouseout="this.style.background='transparent'">Cambiar contraseña</a>
            <button onclick="window.location.href='<?php echo wp_logout_url(home_url()); ?>'">Cerrar sesión</button>
        </div>
    </div>
</div>
