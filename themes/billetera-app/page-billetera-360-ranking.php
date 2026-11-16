<?php
/**
 * Template Name: Mi alcancia 360 Ranking
 * Description: Mi alcancia 360 - Pantalla "Ranking" (asesores: su puesto; jefes: sucursales + sus asesores)
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
if (!defined('BILLETERA_SHOW_RANKING') || !BILLETERA_SHOW_RANKING) {
    $destino = billetera_get_template_url('page-billetera-360-movimientos.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}

$roles_ranking = (array) $current_user->roles;
$es_jefe = in_array('jefe_venta', $roles_ranking, true);

// Layout de asesor: asesor + administrador. Layout de jefe: jefe_venta.
// (El jefe ve 2 subsecciones: ranking por sucursal y ranking de sus asesores.)
if (!$es_jefe && !array_intersect(array('asesor', 'administrator'), $roles_ranking)) {
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
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-fonts.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-responsive.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-header.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-ranking.css'); ?>">
    <?php if ($es_jefe): ?>
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-ranking-jefe.css'); ?>">
    <?php endif; ?>
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-ranking">
            <?php if ($es_jefe): ?>

            <?php /* =========================================================
                  RANKING DE JEFES: 2 subsecciones (Sucursal / Mis asesores)
                  ========================================================= */ ?>
            <div class="rk-wrap rk-v3 rkj-wrap">

                <div class="rk-head">
                    <div>
                        <h1 class="rk-title">Ranking</h1>
                        <p class="rk-sub" id="rkj-sub">¿Cómo van tus sucursales?</p>
                    </div>
                    <div class="rk-head__actions">
                        <div class="rk-month" id="rk-mes-picker">
                            <button type="button" class="rk-month__btn" id="rk-mes-btn" aria-haspopup="listbox" aria-expanded="false">
                                <span id="rk-mes-label">—</span>
                                <span class="rk-month__chev" aria-hidden="true">▾</span>
                            </button>
                            <div class="rk-month__menu" id="rk-mes-menu" role="listbox" aria-label="Elegir mes" hidden></div>
                        </div>
                    </div>
                </div>

                <!-- KPIs del jefe (siguen a la subsección activa) -->
                <div class="rk-kpis" id="rkj-kpis">
                    <div class="rk-kpi">
                        <div class="rk-kpi__val rk-kpi__val--gold rkj-val--txt" id="rkj-kpi1">—</div>
                        <div class="rk-kpi__label" id="rkj-kpi1-label">Ranking de tu sucursal</div>
                    </div>
                    <div class="rk-kpi rk-kpi--alt">
                        <div class="rk-kpi__val" id="rkj-kpi2">—</div>
                        <div class="rk-kpi__label" id="rkj-kpi2-label">Ventas de tu sucursal</div>
                    </div>
                    <div class="rk-kpi">
                        <div class="rk-kpi__val rk-kpi__val--green" id="rkj-kpi3">—</div>
                        <div class="rk-kpi__label" id="rkj-kpi3-label">Avance de meta</div>
                    </div>
                    <div class="rk-kpi rk-kpi--alt">
                        <div class="rk-kpi__val" id="rkj-kpi4">—</div>
                        <div class="rk-kpi__label" id="rkj-kpi4-label">Asesores con ventas</div>
                    </div>
                    <div class="rk-kpi">
                        <div class="rk-kpi__val rkj-val--txt" id="rkj-kpi5">—</div>
                        <div class="rk-kpi__label" id="rkj-kpi5-label">Producto top</div>
                    </div>
                </div>

                <div class="rk-storebar">
                    <span class="rk-storebar__tienda" id="rkj-store-nombre">—</span>
                    <span class="rk-storebar__label">Ventas del mes</span>
                </div>

                <div class="rk-main">
                    <!-- Panel: tu sucursal / tu mejor asesor -->
                    <aside class="rk-me" id="rkj-me">
                        <div class="rk-me__crown" id="rkj-me-crown">
                            <div class="rk-crown" id="rkj-crown-svg" aria-hidden="true"></div>
                            <div class="rk-me__crown-cap" id="rkj-me-crown-cap"></div>
                        </div>
                        <div class="rk-me__body">
                            <div class="rk-me__row">
                                <div>
                                    <div class="rk-me__label" id="rkj-me-main-label">Tu sucursal</div>
                                    <div class="rk-me__puesto"><span id="rkj-me-pos">—</span> <small id="rkj-me-total">de 0</small></div>
                                </div>
                                <div class="rk-me__global">
                                    <div class="rk-me__label" id="rkj-me-sub-label">Ventas del mes</div>
                                    <div class="rk-me__global-val" id="rkj-me-sub">—</div>
                                </div>
                            </div>
                            <div class="rk-me__tienda" id="rkj-me-tienda">—</div>
                            <p class="rk-me__note" id="rkj-me-note">Se ordena por % de meta para comparar sucursales de distinto tamaño.</p>
                        </div>
                    </aside>

                    <div class="rkj-cards">
                    <!-- SUBSECCIÓN: RANKING POR SUCURSAL -->
                    <section class="rkj-card" id="rkj-card-sucursal">
                    <div class="rkj-card__head">
                        <span class="rkj-card__title" id="rkj-suc-title">Ranking por sucursal</span>
                        <div class="rkj-sort">
                            <span class="rkj-sort__label">Ordenar por</span>
                            <div class="rkj-sort__toggle" role="group" aria-label="Ordenar por">
                                <button type="button" class="rkj-sort__btn is-active" data-orden="pct">% de meta</button>
                                <button type="button" class="rkj-sort__btn" data-orden="ventas">Ventas</button>
                            </div>
                        </div>
                    </div>
                    <div class="rkj-colhead" aria-hidden="true">
                        <span class="rkj-c1">#</span>
                        <span class="rkj-c2">Sucursal</span>
                        <span class="rkj-c3">Asesores con ventas</span>
                        <span class="rkj-c4">Ventas / meta</span>
                        <span class="rkj-c5">Avance de meta</span>
                        <span class="rkj-c6">Producto top</span>
                        <span class="rkj-c7" id="rkj-vs-head">Vs —*</span>
                    </div>
                    <div class="rk-loading" id="rkj-loading">Cargando ranking…</div>
                    <div class="rkj-list" id="rkj-list" hidden></div>
                    <div class="rkj-empty" id="rkj-empty" hidden></div>
                    <div class="rkj-error" id="rkj-error" hidden></div>
                    <div class="rkj-foot">
                        <span class="rkj-foot__note" id="rkj-foot-note">*El ranking se ordena por % de meta para comparar sucursales de distinto tamaño.</span>
                    </div>
                </section>

                <!-- SUBSECCIÓN: MIS ASESORES (misma tabla que la de asesores) -->
                <section class="rk-card rk-table" id="rkj-card-equipo" hidden>
                    <div class="rk-table__head">
                        <div class="rk-table__title" id="rkj-eq-title">Ranking de mis asesores</div>
                        <div class="rkj-eq-controls">
                            <div class="rkj-eq-ambito" id="rkj-eq-ambito" role="group" aria-label="Ámbito">
                                <button type="button" class="rkj-eq-ambito__btn is-active" data-eq-ambito="equipo">Mi equipo</button>
                                <button type="button" class="rkj-eq-ambito__btn" data-eq-ambito="global">Global</button>
                            </div>
                            <div class="rk-month rkj-eq-sucursal" id="rkj-eq-sucursal">
                                <button type="button" class="rk-month__btn" id="rkj-eq-suc-btn" aria-haspopup="listbox" aria-expanded="false">
                                    <span id="rkj-eq-suc-label">Todas las sucursales</span>
                                    <span class="rk-month__chev" aria-hidden="true">▾</span>
                                </button>
                                <div class="rk-month__menu" id="rkj-eq-suc-menu" role="listbox" aria-label="Filtrar por sucursal" hidden></div>
                            </div>
                        </div>
                    </div>
                    <div class="rk-pending-bar" id="rkj-pending" hidden aria-hidden="true"></div>
                    <div class="rk-table__colhead" aria-hidden="true">
                        <span class="c-pos">#</span>
                        <span class="c-nombre">Asesor</span>
                        <span class="c-ventas">Ventas</span>
                        <span class="c-ultima">Última venta</span>
                    </div>
                    <div class="rk-list" id="rkj-eq-list" hidden></div>
                    <div class="rkj-empty" id="rkj-eq-empty" hidden></div>
                    <div class="rkj-error" id="rkj-eq-error" hidden></div>
                    <div class="rk-table__foot" id="rkj-eq-foot" hidden>
                        <span id="rkj-eq-mostrando">Mostrando 10 de 0 asesores</span>
                        <button type="button" class="rk-link" id="rkj-eq-ver-todos">Ver todos</button>
                    </div>
                </section>
                    </div>
                </div>

                <p class="rk-note-mobile">El ranking de sucursales se ordena por % de meta y el de asesores por comisiones del mes. Por privacidad, no se muestran montos ni comisiones de ningún asesor.</p>

            </div>

            <script>
            window.billetera = {
                ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce_ranking: '<?php echo wp_create_nonce('billetera_get_ranking'); ?>'
            };
            </script>
            <script src="<?php echo billetera_asset_url('/assets/js/billetera-360-ranking-jefe.js'); ?>"></script>

            <?php else: ?>

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

                <div class="rk-storebar">
                    <span class="rk-storebar__tienda" id="rk-store-nombre">—</span>
                    <span class="rk-storebar__label">Ventas del mes</span>
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
                                    <div class="rk-me__label" id="rk-me-main-label">Tu puesto</div>
                                    <div class="rk-me__puesto"><span id="rk-me-pos">—</span> <small id="rk-me-total">de 0</small></div>
                                </div>
                                <div class="rk-me__global">
                                    <div class="rk-me__label" id="rk-me-sub-label">Global</div>
                                    <div class="rk-me__global-val" id="rk-me-global">—</div>
                                </div>
                            </div>
                            <div class="rk-me__tienda" id="rk-me-tienda">—</div>
                            <p class="rk-me__note">Se ordena por comisiones del mes y se reinicia el día 1. Por privacidad, no se muestran montos ni comisiones de ningún asesor.</p>
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

                <p class="rk-note-mobile">Se ordena por comisiones del mes y se reinicia el día 1. Por privacidad, no se muestran montos ni comisiones de ningún asesor.</p>

            </div>

            <script>
            window.billetera = {
                ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce_ranking: '<?php echo wp_create_nonce('billetera_get_ranking'); ?>'
            };
            </script>
            <script src="<?php echo billetera_asset_url('/assets/js/billetera-360-ranking.js'); ?>"></script>

            <?php endif; ?>

            <!-- BOTTOM TABBAR -->
            <?php get_template_part('template-parts/bottom-tabbar'); ?>
        </div>
    </div>
</div>

</body>
</html>
