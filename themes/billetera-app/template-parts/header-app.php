<?php
/**
 * Header de Mi alcancia 360
 * - Móvil / tablet: topbar superior + tabbar inferior
 * - Desktop: sidebar izquierdo
 */

$current_user = wp_get_current_user();
$user_id       = $current_user->ID;
$roles         = (array) $current_user->roles;
$es_jefe       = in_array('jefe_venta', $roles, true);

$iniciales = billetera_get_iniciales($current_user);
$foto_url  = function_exists('billetera_get_foto_url') ? billetera_get_foto_url($user_id) : '';
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

$url_registro    = billetera_get_template_url('page-billetera-360-responsive.php');
$url_movimientos = billetera_get_template_url('page-billetera-360-movimientos.php');
$url_historial   = billetera_get_template_url('page-billetera-360-movimientos-todos.php');
$url_stats       = billetera_get_template_url('page-billetera-360-estadisticas.php');
$url_equipo      = billetera_get_template_url('page-billetera-360-equipo.php');
$url_ranking     = billetera_get_template_url('page-billetera-360-ranking.php');
$url_perfil      = billetera_get_template_url('page-billetera-360-perfil.php');
$url_cambiar     = home_url('/cambiar-contrasena');
$logout_url      = wp_logout_url(home_url());

$current_slug       = get_page_template_slug();
$nav_registro_active = ($current_slug === 'page-billetera-360-responsive.php');
$nav_alcancia_active = in_array($current_slug, array('page-billetera-360-movimientos.php', 'page-billetera-360-movimientos-todos.php'), true);
$nav_stats_active    = ($current_slug === 'page-billetera-360-estadisticas.php');
$nav_equipo_active   = ($current_slug === 'page-billetera-360-equipo.php');
$nav_ranking_active  = ($current_slug === 'page-billetera-360-ranking.php');

$home_url = $es_jefe ? $url_movimientos : $url_registro;

// Subsección activa del ranking de jefes (?tab=sucursal | equipo en la página de ranking).
$nav_ranking_tab = '';
if ($nav_ranking_active) {
    $nav_ranking_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'sucursal';
    if (!in_array($nav_ranking_tab, array('sucursal', 'equipo'), true)) {
        $nav_ranking_tab = 'sucursal';
    }
}
?>

<?php
// Menú de usuario (compartido entre topbar y sidebar)
ob_start();
?>
<div class="app-header__menu" data-app-user-menu>
    <div class="app-header__menu-head">
        <span class="app-header__menu-avatar"><?php if ($foto_url): ?><img class="bh-avatar-img" src="<?php echo esc_url($foto_url); ?>" alt=""><?php else: ?><?php echo esc_html($iniciales); ?><?php endif; ?></span>
        <div class="app-header__menu-user">
            <div class="app-header__menu-name"><?php echo esc_html($current_user->display_name); ?></div>
            <div class="app-header__menu-role"><?php echo esc_html($rol_label . ($tienda_label ? ' · ' . $tienda_label : '')); ?></div>
        </div>
    </div>

    <nav class="app-header__menu-list">
        <a class="app-header__menu-item" href="<?php echo esc_url($url_perfil); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8"/></svg>
            <span>Mi perfil</span>
        </a>

        <?php if (in_array('asesor', (array) $current_user->roles)): ?>
        <a class="app-header__menu-item" href="<?php echo esc_url($url_registro); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            <span>Registrar venta</span>
        </a>
        <?php endif; ?>

        <?php if (BILLETERA_SHOW_EQUIPO && $es_jefe && $url_equipo && $url_equipo !== '#'): ?>
        <a class="app-header__menu-item" href="<?php echo esc_url($url_equipo); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8M22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span>Mi equipo</span>
        </a>
        <?php endif; ?>

        <a class="app-header__menu-item" href="<?php echo esc_url($url_movimientos); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8M12 7v5l4 2"/></svg>
            <span>Historial de comisiones</span>
        </a>

        <?php if (BILLETERA_SHOW_DESEMPENO && !$es_jefe): ?>
        <a class="app-header__menu-item" href="<?php echo esc_url($url_stats); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
            <span>Mi desempeño</span>
        </a>
        <?php endif; ?>

        <?php if (BILLETERA_SHOW_RANKING): ?>
        <a class="app-header__menu-item" href="<?php echo esc_url($url_ranking); ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"/></svg>
            <span>Ranking</span>
        </a>
        <?php endif; ?>

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

    <div class="app-header__menu-foot">Mi alcancia 360</div>
</div>
<?php
$user_menu_html = ob_get_clean();
?>

<!-- TOPBAR (desktop) -->
<header class="app-topbar">
    <div class="app-topbar__inner">
        <a class="app-topbar__brand" href="<?php echo esc_url($home_url); ?>">
            <img class="app-topbar__logo" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/logo.png'); ?>" alt="Gildemeister">
            <span class="app-topbar__brand-text">
                <img class="app-topbar__wordmark" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/header-gildemeister.png'); ?>" alt="Mi alcancia 360">
                <span class="app-topbar__brand-sub">Mi alcancia 360 · Postventa</span>
            </span>
        </a>

        <div class="app-topbar__actions">
            <?php if (!$es_jefe): ?>
            <div class="app-topbar__racha" title="Racha de ventas (días consecutivos)">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="#F5C563"><path d="M12 2c1 4-2 5-2 8a4 4 0 0 0 8 0c0-1-.4-2-1-3 3 2 5 5 5 8a10 10 0 1 1-20 0C2 8 8 6 12 2z"></path></svg>
                <?php echo intval($racha); ?> días de racha
            </div>
            <?php endif; ?>

            <div class="app-topbar__user">
                <div class="app-topbar__user-info">
                    <div class="app-topbar__user-name"><?php echo esc_html($current_user->display_name); ?></div>
                    <div class="app-topbar__user-role"><?php echo esc_html($rol_label . ($tienda_label ? ' · ' . $tienda_label : '')); ?></div>
                </div>
                <button class="app-topbar__avatar-btn" type="button" aria-label="Menú de usuario" data-app-user-toggle>
                    <?php if ($foto_url): ?><img class="bh-avatar-img" src="<?php echo esc_url($foto_url); ?>" alt=""><?php else: ?><span class="app-topbar__avatar"><?php echo esc_html($iniciales); ?></span><?php endif; ?>
                </button>
                <div class="app-topbar__backdrop" data-app-user-backdrop></div>
                <?php echo $user_menu_html; ?>
            </div>
        </div>
    </div>
</header>

<!-- TOPBAR (móvil / tablet) -->
<header class="app-header">
    <div class="app-header__inner">

        <!-- Brand -->
        <a class="app-header__brand" href="<?php echo esc_url($home_url); ?>">
            <img class="app-header__logo" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/logo.png'); ?>" alt="Gildemeister">
            <span class="app-header__brand-text">
                <img class="app-header__wordmark" src="<?php echo esc_url(get_template_directory_uri() . '/assets/img/header-gildemeister.png'); ?>" alt="Mi alcancia 360">
                <span class="app-header__brand-sub">Mi alcancia 360 · Postventa</span>
            </span>
        </a>

        <!-- Acciones -->
        <div class="app-header__actions">

            <!-- Racha -->
            <?php if (!$es_jefe): ?>
            <div class="app-header__racha" title="Racha de ventas (días consecutivos)">
                <svg class="app-header__flame" viewBox="0 0 24 24" width="12" height="12" fill="#F5C563">
                    <path d="M12 2c1 4-2 5-2 8a4 4 0 0 0 8 0c0-1-.4-2-1-3 3 2 5 5 5 8a10 10 0 1 1-20 0C2 8 8 6 12 2z"></path>
                </svg>
                <span class="app-header__racha-num"><?php echo intval($racha); ?></span>
            </div>
            <?php endif; ?>

            <!-- Usuario -->
            <div class="app-header__user">
                <button class="app-header__avatar-btn" type="button" aria-label="Menú de usuario" data-app-user-toggle>
                    <?php if ($foto_url): ?><img class="bh-avatar-img" src="<?php echo esc_url($foto_url); ?>" alt=""><?php else: ?><span class="app-header__avatar-initials"><?php echo esc_html($iniciales); ?></span><?php endif; ?>
                </button>

                <div class="app-header__backdrop" data-app-user-backdrop></div>

                <?php echo $user_menu_html; ?>
            </div>
        </div>
    </div>
</header>

<!-- SIDEBAR (desktop) -->
<aside class="app-sidebar">
    <nav class="app-sidebar__nav">
        <div class="app-sidebar__section">Operación</div>
        <?php if (in_array('asesor', (array) $current_user->roles)): ?>
        <a class="app-sidebar__link<?php echo $nav_registro_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_registro); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"></path></svg>
            <span>Registrar venta</span>
        </a>
        <?php endif; ?>
        <a class="app-sidebar__link<?php echo $nav_alcancia_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_movimientos); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9c0-8 14-8 14 0"></path><rect x="2" y="9" width="20" height="11" rx="1"></rect><path d="M16 14h2"></path></svg>
            <span>Mi alcancía</span>
        </a>
        <?php if (BILLETERA_SHOW_DESEMPENO && !$es_jefe): ?>
        <a class="app-sidebar__link<?php echo $nav_stats_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_stats); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path></svg>
            <span>Mi desempeño</span>
        </a>
        <?php endif; ?>
        <?php if (BILLETERA_SHOW_EQUIPO && $es_jefe && $url_equipo && $url_equipo !== '#'): ?>
        <a class="app-sidebar__link<?php echo $nav_equipo_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_equipo); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8M22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
            <span>Mi equipo</span>
        </a>
        <?php endif; ?>
        <?php if (BILLETERA_SHOW_RANKING): ?>
        <?php if ($es_jefe && $url_ranking && $url_ranking !== '#'): ?>
        <div class="app-sidebar__group" data-sidebar-group="ranking" data-open-on-load="<?php echo $nav_ranking_active ? '1' : '0'; ?>">
            <a class="app-sidebar__link<?php echo $nav_ranking_active ? ' is-active is-active-soft' : ''; ?>" href="<?php echo esc_url($url_ranking); ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"></path></svg>
                <span>Ranking</span>
                <svg class="app-sidebar__chev" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
            </a>
            <div class="app-sidebar__sub">
                <a class="app-sidebar__sublink<?php echo $nav_ranking_tab === 'sucursal' ? ' is-active' : ''; ?>" data-ranking-tab="sucursal" href="<?php echo esc_url(add_query_arg('tab', 'sucursal', $url_ranking)); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l1.2-5h15.6L21 9M3 9h18M4.5 9v10.5h15V9M9 19.5v-6h6v6M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"></path></svg>
                    <span>Sucursal</span>
                </a>
                <a class="app-sidebar__sublink<?php echo $nav_ranking_tab === 'equipo' ? ' is-active' : ''; ?>" data-ranking-tab="equipo" href="<?php echo esc_url(add_query_arg('tab', 'equipo', $url_ranking)); ?>">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8M22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                    <span>Mis asesores</span>
                </a>
            </div>
        </div>
        <?php else: ?>
        <a class="app-sidebar__link<?php echo $nav_ranking_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_ranking); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"></path></svg>
            <span>Ranking</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <div class="app-sidebar__section app-sidebar__section--spaced">Mi cuenta</div>
        <a class="app-sidebar__link" href="<?php echo esc_url($url_historial); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v5h5M3.05 13A9 9 0 1 0 6 5.3L3 8M12 7v5l4 2"></path></svg>
            <span>Historial</span>
        </a>
        <a class="app-sidebar__link" href="<?php echo esc_url($url_perfil); ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8"/></svg>
            <span>Mi perfil</span>
        </a>
    </nav>

    <div class="app-sidebar__foot">Mi alcancia 360</div>
</aside>

<script>
(function () {
    function closeAllMenus() {
        document.querySelectorAll('[data-app-user-menu].is-open').forEach(function (m) {
            m.classList.remove('is-open');
        });
        document.querySelectorAll('[data-app-user-backdrop].is-open').forEach(function (b) {
            b.classList.remove('is-open');
        });
    }

    // Acordeón del sidebar: grupos (ej. Ranking de jefes) que se abren/cierran.
    // - Se abre solo si el usuario está dentro de la sección (data-open-on-load).
    // - Si no, respeta la última preferencia guardada en localStorage.
    // - El chevron es la zona de toggle; el resto del link navega normal.
    function initSidebarGroups() {
        document.querySelectorAll('[data-sidebar-group]').forEach(function (group) {
            var link = group.querySelector('.app-sidebar__link');
            var chev = group.querySelector('.app-sidebar__chev');
            var key = 'billetera_sidebar_' + group.dataset.sidebarGroup + '_open';

            var open;
            if (group.dataset.openOnLoad === '1') {
                open = true; // dentro de la sección: siempre expandido
            } else {
                try { open = localStorage.getItem(key) === '1'; } catch (e) { open = false; }
            }

            function apply() {
                group.classList.toggle('is-open', open);
                if (chev) chev.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            if (chev) {
                chev.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    open = !open;
                    try { localStorage.setItem(key, open ? '1' : '0'); } catch (err) {}
                    apply();
                });
            }

            // Estado inicial (expandido si está dentro de la sección).
            apply();
        });
    }

    function initAppHeader() {
        var toggles = document.querySelectorAll('[data-app-user-toggle]');

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function (e) {
                e.stopPropagation();
                var container = toggle.closest('.app-header__user, .app-sidebar__user, .app-topbar__user');
                if (!container) return;
                var menu = container.querySelector('[data-app-user-menu]');
                var backdrop = container.querySelector('[data-app-user-backdrop]');
                if (!menu) return;

                var isOpen = menu.classList.contains('is-open');
                closeAllMenus();
                if (!isOpen) {
                    menu.classList.add('is-open');
                    if (backdrop) backdrop.classList.add('is-open');
                }
            });
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('[data-app-user-menu]') && !e.target.closest('[data-app-user-toggle]')) {
                closeAllMenus();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeAllMenus(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAppHeader();
            initSidebarGroups();
        });
    } else {
        initAppHeader();
        initSidebarGroups();
    }
})();
</script>

<?php
// Popup "Meta del mes superada" (solo asesores): CSS + markup + controlador.
// Se evalúa en cualquier página de la app al primer ingreso del mes.
$es_asesor_popup = in_array('asesor', $roles, true);
?>
<link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/popup-meta.css'); ?>">
<link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/popup-rank.css'); ?>">
<?php if ($es_asesor_popup && isset($_GET['meta_test'])): ?>
<script>
// PRUEBA del popup de meta: ?meta_test=1 (datos de ejemplo).
window.__popupMetaEnabled = true;
window.__popupMetaTest = { pct: 111, ahorrado: 1330, meta: 1200, mes: 'septiembre', mes_key: 'test', es_mejor_mes: true };
</script>
<?php elseif ($es_asesor_popup): ?>
<script>window.__popupMetaEnabled = true;</script>
<?php endif; ?>
<?php if ($es_asesor_popup && isset($_GET['rank_test'])): ?>
<script>
// PRUEBA del popup de ranking: ?rank_test=1|2|3 (datos de ejemplo).
window.__popupRankEnabled = true;
var __rankTestPos = parseInt('<?php echo isset($_GET['rank_test']) ? intval($_GET['rank_test']) : 1; ?>', 10) || 1;
if (__rankTestPos < 1 || __rankTestPos > 3) __rankTestPos = 1;
window.__popupRankTest = {
    pos: __rankTestPos,
    top: [
        { pos: 1, nombre: __rankTestPos === 1 ? 'Tú (ejemplo)' : 'Eva Rafaella Cuenca', ventas: 1, es_yo: __rankTestPos === 1 },
        { pos: 2, nombre: __rankTestPos === 2 ? 'Tú (ejemplo)' : 'José Luis Pardo', ventas: 0, es_yo: __rankTestPos === 2 },
        { pos: 3, nombre: __rankTestPos === 3 ? 'Tú (ejemplo)' : 'Maria Fernanda Lopez', ventas: 0, es_yo: __rankTestPos === 3 },
    ],
    tienda: 'WIGO MOTORS · Surquillo',
    mes_key: 'test'
};
</script>
<?php elseif ($es_asesor_popup): ?>
<script>window.__popupRankEnabled = true;</script>
<?php endif; ?>
<?php get_template_part('template-parts/popup-meta'); ?>
<?php get_template_part('template-parts/popup-rank'); ?>
