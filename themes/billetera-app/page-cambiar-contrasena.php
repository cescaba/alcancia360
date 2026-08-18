<?php
/**
 * Template Name: Cambiar Contraseña
 * Description: Página para cambiar contraseña
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
</head>
<body>

<div class="site-wrapper">
    <?php get_template_part('template-parts/header-sidebar'); ?>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <?php get_template_part('template-parts/header-topbar'); ?>

        <!-- SCREEN: CAMBIAR CONTRASEÑA -->
        <div class="screen active" id="screen-password">
            <h1>Cambiar Contraseña</h1>

            <div class="password-card">
                <div id="message" class="message" style="display: none;"></div>

                <form id="password-form">
                    <div>
                        <p class="field-label">Contraseña Actual</p>
                        <input type="password" id="current-password" name="current-password" placeholder="Ingresa tu contraseña actual" required>
                    </div>

                    <div>
                        <p class="field-label">Nueva Contraseña</p>
                        <input type="password" id="new-password" name="new-password" placeholder="Ingresa tu nueva contraseña" required>
                    </div>

                    <div>
                        <p class="field-label">Confirmar Nueva Contraseña</p>
                        <input type="password" id="confirm-password" name="confirm-password" placeholder="Confirma tu nueva contraseña" required>
                    </div>

                    <button type="submit" class="btn btn-primary" style="margin-top: 24px; width: 100%;">Cambiar Contraseña</button>
                    <a href="<?php echo home_url('/registrar-venta'); ?>" class="btn btn-secondary" style="margin-top: 12px; display: block; text-align: center; text-decoration: none;">Cancelar</a>
                </form>
            </div>
        </div>

        <!-- MOBILE TABBAR -->
        <div class="mobile-tabbar">
            <a href="<?php echo home_url('/registrar-venta'); ?>" class="mobile-tab" id="mobile-tab-registro">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Registrar
            </a>
            <a href="<?php echo home_url('/movimientos'); ?>" class="mobile-tab" id="mobile-tab-wallet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M16 14h2"/></svg>
                Movimientos
            </a>
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
<script>
document.getElementById('password-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const currentPassword = document.getElementById('current-password').value;
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const messageDiv = document.getElementById('message');

    if (newPassword !== confirmPassword) {
        messageDiv.innerHTML = '<div class="alert-error">Las contraseñas no coinciden</div>';
        messageDiv.style.display = 'block';
        return;
    }

    if (newPassword.length < 8) {
        messageDiv.innerHTML = '<div class="alert-error">La contraseña debe tener al menos 8 caracteres</div>';
        messageDiv.style.display = 'block';
        return;
    }

    fetch(window.billetera.ajax_url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=billetera_change_password&current_password=' + encodeURIComponent(currentPassword) + '&new_password=' + encodeURIComponent(newPassword)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            messageDiv.innerHTML = '<div class="alert-success">Contraseña cambiada exitosamente. Redirigiendo...</div>';
            messageDiv.style.display = 'block';
            setTimeout(() => {
                window.location.href = '<?php echo home_url('/registrar-venta'); ?>';
            }, 2000);
        } else {
            messageDiv.innerHTML = '<div class="alert-error">' + (res.message || 'Error al cambiar contraseña') + '</div>';
            messageDiv.style.display = 'block';
        }
    });
});
</script>

</body>
</html>
