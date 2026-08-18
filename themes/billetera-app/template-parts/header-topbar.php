<?php
$current_user = wp_get_current_user();
?>

<!-- MOBILE TOPBAR -->
<div class="mobile-topbar">
    <div class="mobile-brand">
        <div class="mobile-brand-mark">
            <img src="<?php echo get_template_directory_uri(); ?>/assets/img/logo-gildemeister.png" alt="Gildemeister">
        </div>
        <div class="mobile-brand-text">
            <div class="mobile-brand-name">Billetera 360</div>
        </div>
    </div>
    <button class="user-button" id="user-toggle">
        <div class="user-avatar">
            <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"></circle><path d="M4 20c0-4 4-6 8-6s8 2 8 6"></path></svg>
        </div>
    </button>
    <div class="user-menu-dropdown" id="user-dropdown">
        <a href="<?php echo esc_url(home_url('/cambiar-contrasena')); ?>" style="display: block; padding: 12px 16px; text-decoration: none; color: var(--text-primary); font-family: Inter, sans-serif; font-size: 13px; font-weight: 500; border-bottom: 1px solid var(--gray-border); transition: background 0.15s;" onmouseover="this.style.background='var(--gray-light)'" onmouseout="this.style.background='transparent'">Cambiar contraseña</a>
        <button onclick="window.location.href='<?php echo wp_logout_url(home_url()); ?>'">Cerrar sesión</button>
    </div>
</div>
