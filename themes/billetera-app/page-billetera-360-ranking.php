<?php
/**
 * Template Name: Mi alcancia 360 Ranking
 * Description: Mi alcancia 360 - Pantalla "Ranking" (posición del asesor, solo nombres)
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$allowed_roles = array('asesor', 'administrator', 'jefe_venta');
if (!array_intersect($allowed_roles, $current_user->roles)) {
    wp_die('Acceso restringido. Solo administradores y asesores pueden acceder al ranking.');
}

// "Ranking": interruptor maestro + solo asesores (y administradores).
// Jefes de venta no entran aunque el interruptor esté activo.
if (!defined('BILLETERA_SHOW_RANKING') || !BILLETERA_SHOW_RANKING) {
    $destino = billetera_get_template_url('page-billetera-360-movimientos.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}

$roles_ranking = (array) $current_user->roles;
if (!array_intersect(array('asesor', 'administrator'), $roles_ranking)) {
    $destino = billetera_get_template_url('page-billetera-360-movimientos.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-ranking.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-ranking">
            <div class="rk-wrap rk-v3">

                <div class="rk-head">
                    <div>
                        <h1 class="rk-title">Ranking</h1>
                        <p class="rk-sub">¿Dónde estoy y cuánto le llevo al siguiente?</p>
                    </div>
                    <div class="rk-head__actions">
                        <div class="rk-tabs" id="rk-tabs">
                            <button type="button" class="rk-tab is-active" data-ambito="tienda">Mi tienda</button>
                            <button type="button" class="rk-tab" data-ambito="global">Global</button>
                        </div>
                        <div class="rk-month" id="rk-mes-picker">
                            <button type="button" class="rk-month__btn" id="rk-mes-btn" aria-haspopup="listbox" aria-expanded="false">
                                <span id="rk-mes-label">—</span>
                                <span class="rk-month__chev" aria-hidden="true">▾</span>
                            </button>
                            <div class="rk-month__menu" id="rk-mes-menu" role="listbox" aria-label="Elegir mes" hidden></div>
                        </div>
                    </div>
                </div>

                <!-- KPIs v3 -->
                <div class="rk-kpis" id="rk-kpis">
                    <div class="rk-kpi">
                        <div class="rk-kpi__val rk-kpi__val--gold" id="kpi-tienda">—</div>
                        <div class="rk-kpi__label">Ranking en mi tienda</div>
                    </div>
                    <div class="rk-kpi rk-kpi--alt">
                        <div class="rk-kpi__val rk-kpi__val--gold" id="kpi-global">—</div>
                        <div class="rk-kpi__label">Ranking global</div>
                    </div>
                    <div class="rk-kpi">
                        <div class="rk-kpi__val" id="kpi-ultima">—</div>
                        <div class="rk-kpi__label">Tu última venta</div>
                    </div>
                    <div class="rk-kpi rk-kpi--alt">
                        <div class="rk-kpi__val rk-kpi__val--green" id="kpi-ventaja">—</div>
                        <div class="rk-kpi__label" id="kpi-ventaja-label">Ventaja sobre el #2</div>
                    </div>
                    <div class="rk-kpi">
                        <div class="rk-kpi__val" id="kpi-ventas">—</div>
                        <div class="rk-kpi__label">Ventas del mes</div>
                    </div>
                </div>

                <div class="rk-main">
                    <!-- Mi puesto -->
                    <aside class="rk-me" id="rk-me">
                        <div class="rk-me__crown" id="rk-me-crown">
                            <div class="rk-crown" id="rk-crown-svg" aria-hidden="true"></div>
                            <div class="rk-me__crown-cap" id="rk-me-crown-cap">Líder de tu tienda</div>
                        </div>
                        <div class="rk-me__body">
                            <div class="rk-me__row">
                                <div>
                                    <div class="rk-me__label">Tu puesto</div>
                                    <div class="rk-me__puesto"><span id="rk-me-pos">—</span> <small id="rk-me-total">de 0</small></div>
                                </div>
                                <div class="rk-me__global">
                                    <div class="rk-me__label">Global</div>
                                    <div class="rk-me__global-val" id="rk-me-global">—</div>
                                </div>
                            </div>
                            <div class="rk-me__tienda" id="rk-me-tienda">—</div>
                            <p class="rk-me__note">Se ordena por número de ventas registradas en el mes y se reinicia el día 1. Por privacidad, no se muestran montos ni comisiones de ningún asesor.</p>
                        </div>
                    </aside>

                    <!-- Tabla ranking -->
                    <section class="rk-card rk-table" id="rk-table">
                        <div class="rk-table__head">
                            <div class="rk-table__title" id="rk-card-title">Ranking de mi tienda</div>
                            <button type="button" class="rk-link" id="rk-ver-mes">Ver mes anterior</button>
                        </div>
                        <div class="rk-pending-bar" id="rk-pending" hidden aria-hidden="true"></div>
                        <div class="rk-table__colhead" aria-hidden="true">
                            <span class="c-pos">#</span>
                            <span class="c-nombre">Asesor</span>
                            <span class="c-ventas">Ventas</span>
                            <span class="c-ultima">Última venta</span>
                        </div>
                        <div class="rk-loading" id="rk-loading">Cargando ranking…</div>
                        <div class="rk-list" id="rk-list" hidden></div>
                        <div class="rk-empty" id="rk-empty" hidden></div>
                        <div class="rk-error" id="rk-error" hidden></div>
                        <div class="rk-table__foot" id="rk-foot" hidden>
                            <span id="rk-mostrando">Mostrando 10 de 0 asesores</span>
                            <button type="button" class="rk-link" id="rk-ver-todos">Ver todos</button>
                        </div>
                    </section>
                </div>

            </div>

            <!-- BOTTOM TABBAR -->
            <?php get_template_part('template-parts/bottom-tabbar'); ?>
        </div>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    nonce_ranking: '<?php echo wp_create_nonce('billetera_get_ranking'); ?>'
};
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-ranking.js"></script>

</body>
</html>
