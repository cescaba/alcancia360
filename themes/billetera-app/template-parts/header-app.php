<?php
/**
 * Header topbar de Billetera 360 (responsive: móvil / tablet / desktop)
 */

$current_user = wp_get_current_user();
$user_id       = $current_user->ID;
$roles         = (array) $current_user->roles;
$es_jefe       = in_array('jefe_venta', $roles, true);

$iniciales = billetera_get_iniciales($current_user);
$racha     = billetera_get_racha($user_id);

$rol_label = $es_jefe ? 'Jefe de Venta' : 'Asesor';

$tienda_label = '';
$tienda_id    = get_user_meta($user_id, '_tienda_asociada', true);
if ($tienda_id) {
    $tienda = get_post($tienda_id);
    if ($tienda) {
        $tienda_label = $tienda->post_title;
    }
}

$notificaciones = billetera_get_notificaciones($user_id);
$notif_count    = count($notificaciones);

$url_registro   = billetera_get_template_url('page-billetera-360-responsive.php');
$url_movimientos = billetera_get_template_url('page-billetera-360-movimientos.php');
$url_cambiar    = home_url('/cambiar-contrasena');
$logout_url     = wp_logout_url(home_url());
?>

<header class="app-header">
    <div class="app-header__inner">

        <!-- Brand -->
        <a class="app-header__brand" href="<?php echo esc_url($es_jefe ? $url_movimientos : $url_registro); ?>">
            <img class="app-header__logo" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/logo.png'); ?>" alt="Gildemeister">
            <span class="app-header__brand-text">
                <img class="app-header__wordmark" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/header-gildemeister.png'); ?>" alt="Billetera 360">
                <span class="app-header__brand-sub">Billetera 360 · Postventa</span>
            </span>
        </a>

        <!-- Acciones -->
        <div class="app-header__actions">

            <!-- Racha -->
            <div class="app-header__racha" title="Racha de ventas (días consecutivos)">
                <svg class="app-header__flame" viewBox="0 0 24 24" width="12" height="12" fill="#F5C563">
                    <path d="M12 2c1 4-2 5-2 8a4 4 0 0 0 8 0c0-1-.4-2-1-3 3 2 5 5 5 8a10 10 0 1 1-20 0C2 8 8 6 12 2z"></path>
                </svg>
                <span class="app-header__racha-num"><?php echo intval($racha); ?></span>
            </div>

            <!-- Usuario -->
            <div class="app-header__user">
                <button class="app-header__avatar-btn" type="button" aria-label="Menú de usuario" data-app-user-toggle>
                    <span class="app-header__avatar-initials"><?php echo esc_html($iniciales); ?></span>
                </button>

                <div class="app-header__backdrop" data-app-user-backdrop></div>

                <div class="app-header__menu" data-app-user-menu>
                    <div class="app-header__menu-head">
                        <span class="app-header__menu-avatar"><?php echo esc_html($iniciales); ?></span>
                        <div class="app-header__menu-user">
                            <div class="app-header__menu-name"><?php echo esc_html($current_user->display_name); ?></div>
                            <div class="app-header__menu-role"><?php echo esc_html($rol_label . ($tienda_label ? ' · ' . $tienda_label : '')); ?></div>
                            <span class="app-header__menu-level">Nivel Plata</span>
                        </div>
                    </div>

                    <nav class="app-header__menu-list">
                        <a class="app-header__menu-item" href="#">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8"/></svg>
                            <span>Mi perfil</span>
                        </a>

                        <a class="app-header__menu-item" href="<?php echo esc_url($url_registro); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                            <span>Registrar venta</span>
                        </a>

                        <a class="app-header__menu-item" href="<?php echo esc_url($url_movimientos); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8M12 7v5l4 2"/></svg>
                            <span>Historial de comisiones</span>
                        </a>

                        <a class="app-header__menu-item" href="#">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M6 4h12v5a6 6 0 0 1-12 0zM6 6H3v2a3 3 0 0 0 3 3M18 6h3v2a3 3 0 0 1-3 3"/></svg>
                            <span>Logros y ranking</span>
                        </a>

                        <a class="app-header__menu-item" href="#">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                            <span>Notificaciones</span>
                            <?php if ($notif_count > 0): ?>
                            <span class="app-header__menu-badge"><?php echo intval($notif_count); ?></span>
                            <?php endif; ?>
                        </a>

                        <a class="app-header__menu-item" href="<?php echo esc_url($url_cambiar); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4"/></svg>
                            <span>Cambiar contraseña</span>
                        </a>
                    </nav>

                    <div class="app-header__menu-divider"></div>

                    <div class="app-header__menu-list">
                        <a class="app-header__menu-item app-header__menu-item--danger" href="<?php echo esc_url($logout_url); ?>">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                            <span>Cerrar sesión</span>
                        </a>
                    </div>

                    <div class="app-header__menu-foot">Billetera 360</div>
                </div>
            </div>
        </div>
    </div>
</header>

<script>
(function () {
    function initAppHeader() {
        var toggle = document.querySelector('[data-app-user-toggle]');
        var menu = document.querySelector('[data-app-user-menu]');
        var backdrop = document.querySelector('[data-app-user-backdrop]');
        if (!toggle || !menu) return;

        function open() {
            menu.classList.add('is-open');
            if (backdrop) backdrop.classList.add('is-open');
        }
        function close() {
            menu.classList.remove('is-open');
            if (backdrop) backdrop.classList.remove('is-open');
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (menu.classList.contains('is-open')) { close(); } else { open(); }
        });

        if (backdrop) {
            backdrop.addEventListener('click', close);
        }

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.app-header__user')) {
                close();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { close(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAppHeader);
    } else {
        initAppHeader();
    }
})();
</script>
