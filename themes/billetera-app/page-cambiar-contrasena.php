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

$perfil_url = function_exists('billetera_get_template_url')
    ? billetera_get_template_url('page-billetera-360-perfil.php')
    : home_url();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar Contraseña</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-password.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-password">
            <div class="pw-wrap">

                <div class="pw-head">
                    <a class="pw-back" href="<?php echo esc_url($perfil_url); ?>">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#0A6CB4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
                        Cambiar contraseña
                    </a>
                </div>

                <div class="pw-card">
                    <div class="pw-message" id="message"></div>

                    <form id="password-form" novalidate>
                        <div class="pw-field">
                            <p class="pw-label">Contraseña actual</p>
                            <input type="password" id="current-password" name="current-password" placeholder="Ingresa tu contraseña actual" required>
                        </div>

                        <div class="pw-field">
                            <p class="pw-label">Nueva contraseña</p>
                            <input type="password" id="new-password" name="new-password" placeholder="Ingresa tu nueva contraseña" required>
                        </div>

                        <div class="pw-field">
                            <p class="pw-label">Confirmar nueva contraseña</p>
                            <input type="password" id="confirm-password" name="confirm-password" placeholder="Confirma tu nueva contraseña" required>
                        </div>

                        <button type="submit" class="pw-submit">Cambiar contraseña</button>
                        <a class="pw-cancel" href="<?php echo esc_url($perfil_url); ?>">Cancelar</a>
                    </form>
                </div>

            </div>
        </div>

        <?php get_template_part('template-parts/bottom-tabbar'); ?>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>'
};
</script>
<script>
document.getElementById('password-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const currentPassword = document.getElementById('current-password').value;
    const newPassword = document.getElementById('new-password').value;
    const confirmPassword = document.getElementById('confirm-password').value;
    const messageDiv = document.getElementById('message');

    function showMessage(text, type) {
        messageDiv.textContent = text;
        messageDiv.className = 'pw-message ' + (type === 'error' ? 'pw-message--error' : 'pw-message--success');
        messageDiv.style.display = 'block';
    }

    if (!currentPassword || !newPassword || !confirmPassword) {
        showMessage('Completa todos los campos', 'error');
        return;
    }

    if (newPassword !== confirmPassword) {
        showMessage('Las contraseñas no coinciden', 'error');
        return;
    }

    if (newPassword.length < 8) {
        showMessage('La contraseña debe tener al menos 8 caracteres', 'error');
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
            showMessage('Contraseña cambiada exitosamente. Redirigiendo...', 'success');
            setTimeout(() => {
                window.location.href = '<?php echo esc_url($perfil_url); ?>';
            }, 2000);
        } else {
            showMessage((res.data && res.data.message) || 'Error al cambiar contraseña', 'error');
        }
    })
    .catch(() => {
        showMessage('Error de conexión al cambiar contraseña', 'error');
    });
});
</script>

</body>
</html>
