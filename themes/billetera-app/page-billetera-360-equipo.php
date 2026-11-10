<?php
/**
 * Template Name: Mi alcancia 360 Equipo
 * Description: Mi alcancia 360 - Pantalla "Mi equipo" (vista del jefe de venta)
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$allowed_roles = array('jefe_venta', 'administrator');
if (!array_intersect($allowed_roles, $current_user->roles)) {
    // Asesores van a su vista de desempeño.
    $destino = billetera_get_template_url('page-billetera-360-estadisticas.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}

$meses = array('Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
$mes_label = $meses[intval(current_time('n')) - 1] . ' ' . current_time('Y');
$registro_url = function_exists('billetera_get_template_url') ? billetera_get_template_url('page-billetera-360-responsive.php') : '#';
$alcancia_img = get_template_directory_uri() . '/assets/img/alcancia.svg';

// Sucursales donde este usuario es jefe de venta (multi-sucursal).
$tiendas_jefe = array();
$tiendas_all = get_posts(array('post_type' => 'tienda', 'numberposts' => -1, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC'));
foreach ($tiendas_all as $t) {
    $jefes = get_post_meta($t->ID, '_jefes_venta_asociados', true);
    if (!is_array($jefes)) {
        $jefes = $jefes ? [$jefes] : [];
    }
    if (in_array((string) $current_user->ID, array_map('strval', $jefes), true)) {
        $tiendas_jefe[intval($t->ID)] = $t->post_title;
    }
}
if (empty($tiendas_jefe)) {
    $tid_jefe = intval(get_user_meta($current_user->ID, '_tienda_asociada', true));
    if ($tid_jefe) {
        $t = get_post($tid_jefe);
        if ($t) {
            $tiendas_jefe[$tid_jefe] = $t->post_title;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi equipo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-fonts.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-responsive.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-header.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-alcancia.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-stats.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-equipo.css'); ?>">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-equipo">
            <div class="st-wrap">

                <div class="st-head">
                    <div>
                        <h1 class="st-title">Mi equipo</h1>
                        <p class="st-sub">¿Cómo va mi equipo este mes y cuánto voy ganando por sus ventas?</p>
                    </div>
                    <div class="st-head__actions">
                        <?php if (!empty($tiendas_jefe)): ?>
                        <!-- Selector de sucursal (multi-sucursal) -->
                        <div class="eq-sucursal" id="eq-sucursal">
                            <button type="button" class="eq-sucursal__btn" id="eq-sucursal-btn" aria-haspopup="listbox" aria-expanded="false">
                                <span class="eq-sucursal__label">Sucursal:</span>
                                <span class="eq-sucursal__val" id="eq-sucursal-val">Todas (<?php echo count($tiendas_jefe); ?>)</span>
                                <span class="eq-sucursal__chev" aria-hidden="true">▾</span>
                            </button>
                            <div class="eq-sucursal__menu" id="eq-sucursal-menu" role="listbox" aria-label="Sucursal" hidden>
                                <button type="button" class="eq-sucursal__opt is-active" role="option" aria-selected="true" data-tienda="0">Todas (<?php echo count($tiendas_jefe); ?>)</button>
                                <?php foreach ($tiendas_jefe as $tid_j => $tname_j): ?>
                                <button type="button" class="eq-sucursal__opt" role="option" aria-selected="false" data-tienda="<?php echo esc_attr($tid_j); ?>"><?php echo esc_html($tname_j); ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="st-period" id="st-period">
                            <button type="button" class="st-period__btn is-active" data-periodo="mes">Mes</button>
                            <button type="button" class="st-period__btn" data-periodo="ano">Año</button>
                            <button type="button" class="st-period__btn" data-periodo="historico">Histórico</button>
                        </div>
                        <div class="st-stepper" id="st-stepper">
                            <button type="button" class="st-step__btn" id="st-prev" aria-label="Anterior">‹</button>
                            <span class="st-step__label" id="st-step-label">—</span>
                            <button type="button" class="st-step__btn" id="st-next" aria-label="Siguiente">›</button>
                        </div>
                    </div>
                </div>

                <div class="st-loading" id="st-loading">Cargando tu equipo…</div>

                <div class="st-body" id="st-body" hidden>
                    <div class="st-pending-bar" id="st-pending" hidden aria-hidden="true"></div>

                    <!-- BANNER CAMPAÑA (reglas de bonificación, editable en admin) -->
                    <div class="st-banner" id="st-banner" hidden>
                        <div class="st-banner__main">
                            <div class="st-banner__top">
                                <span class="st-banner__tag" id="st-banner-tag">CAMPAÑA · EVENTO</span>
                                <span class="st-banner__dates" id="st-banner-dates"></span>
                            </div>
                            <div class="st-banner__title" id="st-banner-title"></div>
                            <div class="st-banner__sub" id="st-banner-sub"></div>
                        </div>
                        <div class="st-banner__side">
                            <div class="st-banner__dots" id="st-banner-dots"></div>
                        </div>
                    </div>

                    <!-- KPIs del equipo -->
                    <div class="st-kpis5">
                        <div class="st-kpi5">
                            <div class="st-kpi5__val st-kpi5__val--blue" id="k5-ventas">—</div>
                            <div class="st-kpi5__label">Ventas del equipo</div>
                        </div>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val st-kpi5__val--gold" id="k5-avance">—</div>
                            <div class="st-kpi5__label">Avance de meta del equipo</div>
                        </div>
                        <div class="st-kpi5">
                            <div class="st-kpi5__val" id="k5-faltan">—</div>
                            <div class="st-kpi5__label">Te faltan</div>
                        </div>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val" id="k5-cierre">—</div>
                            <div class="st-kpi5__label">Para el cierre</div>
                        </div>
                        <div class="st-kpi5">
                            <div class="st-kpi5__val" id="k5-proy">—</div>
                            <div class="st-kpi5__label">Proyección al cierre</div>
                        </div>
                    </div>

                    <!-- ALCANCÍA DEL JEFE + TU COMISIÓN DE JEFE -->
                    <div class="st-row">
                        <aside class="st-goal">
                            <div class="st-goal__pig">
                                <div class="alc-pig-bob">
                                    <div class="alc-pig">
                                        <button type="button" class="alc-pig__btn" id="st-pig-btn" aria-label="Toca la alcancía">
                                            <span class="alc-pig__coin"></span>
                                            <img class="alc-pig__base" src="<?php echo esc_url($alcancia_img); ?>" alt="Alcancía">
                                            <img class="alc-pig__fill" id="st-pig-fill" src="<?php echo esc_url($alcancia_img); ?>" alt="">
                                        </button>
                                    </div>
                                </div>
                                <div class="st-goal__cap" id="st-goal-cap">— de la meta del equipo</div>
                            </div>
                            <div class="st-goal__body">
                                <div class="st-goal__row">
                                    <div>
                                        <div class="st-goal__label">Mi pocito de jefe</div>
                                        <div class="st-goal__pocito" id="st-pocito">—</div>
                                    </div>
                                    <div class="st-goal__meta">
                                        <div class="st-goal__label">Meta equipo</div>
                                        <div class="st-goal__meta-val" id="st-goal-meta">—</div>
                                    </div>
                                </div>
                                <div class="st-goal__bar">
                                    <div class="st-goal__fill" id="st-goal-fill"></div>
                                    <div class="st-goal__mark" id="st-goal-mark"></div>
                                </div>
                                <div class="st-goal__pace" id="st-goal-pace"></div>
                            </div>
                        </aside>

                        <section class="eq-aporta">
                            <div class="eq-aporta__head">
                                <span class="eq-aporta__title">Tu comisión de jefe</span>
                                <span class="eq-aporta__mes" id="eq-aporta-mes">—</span>
                            </div>
                            <div class="eq-aporta__colhead" aria-hidden="true">
                                <span class="eq-aporta__c1">#</span>
                                <span>Vendedor</span>
                                <span>Comisión que te aporta</span>
                            </div>
                            <div class="eq-aporta__list" id="eq-aporta-list"></div>
                            <div class="eq-aporta__foot">
                                <span id="eq-aporta-total-note">—</span>
                                <span class="eq-aporta__note">Se genera con las ventas validadas de tu equipo.</span>
                            </div>
                        </section>
                    </div>

                    <!-- CHART + ¿QUÉ VENDE TU EQUIPO? -->
                    <div class="st-row st-row--eq">
                        <section class="st-chartcard">
                            <div class="st-chartcard__head">
                                <div class="st-chartper" id="st-chartper">
                                    <button type="button" class="st-chartper__btn" id="st-chartper-btn" aria-haspopup="listbox" aria-expanded="false">
                                        <span aria-hidden="true">📅</span>
                                        <span id="st-chartper-label">Últimos 12 meses</span>
                                        <span aria-hidden="true">▾</span>
                                    </button>
                                    <div class="st-chartper__menu" id="st-chartper-menu" role="listbox" aria-label="Periodo del gráfico" hidden></div>
                                </div>
                                <div class="st-chartlegend"><span class="st-chartlegend__sw"></span>Meta <b id="st-chart-meta">—</b></div>
                            </div>
                            <div class="st-chartwrap">
                                <div class="st-chartmetal" id="st-chart-metal" hidden></div>
                                <div class="st-chart" id="st-trend"></div>
                            </div>
                            <div class="st-chartfoot" id="st-trend-detail"></div>
                        </section>

                        <aside class="st-prod">
                            <div class="st-prod__head">
                                <span class="st-prod__title" id="eq-mix-title">¿Qué vende tu equipo?</span>
                                <span class="st-prod__total" id="eq-mix-total">—</span>
                            </div>
                            <div class="st-vista" id="eq-break-tabs">
                                <button type="button" class="st-vista__btn is-active" data-break="categoria">Categoría</button>
                                <button type="button" class="st-vista__btn" data-break="subcategoria">Subcategoría</button>
                            </div>
                            <div class="st-breakdown" id="eq-mix-list"></div>
                        </aside>
                    </div>

                    <!-- TOP VENDEDORES DEL EQUIPO -->
                    <section class="eq-top">
                        <div class="eq-top__head">
                            <span class="eq-top__title" id="eq-top-title">Top vendedores del equipo · <span id="eq-top-mes">—</span></span>
                            <button type="button" class="eq-top__toggle" id="eq-top-toggle" hidden>Ver todos</button>
                        </div>
                        <div class="eq-top__colhead" aria-hidden="true">
                            <span class="eq-top__c1">#</span>
                            <span class="eq-top__c2">Asesor</span>
                            <span class="eq-top__c3">Racha</span>
                            <span class="eq-top__c4">Ventas</span>
                        </div>
                        <div id="eq-top-list"></div>
                        <div class="eq-top__note">Ranking por número de ventas. Por privacidad no se muestran las comisiones de tus asesores.</div>
                    </section>

                    <!-- ALERTAS DEL EQUIPO -->
                    <section class="eq-alerts">
                        <div class="eq-alerts__head">
                            <span class="eq-alerts__title">Alertas y recomendaciones</span>
                            <span class="eq-alerts__pend" id="eq-alerts-pend" hidden></span>
                        </div>
                        <div class="eq-alerts__row">
                            <div class="eq-alert" id="eq-alert-sin">
                                <div class="eq-alert__top">
                                    <span class="eq-alert__bar eq-alert__bar--danger" aria-hidden="true"></span>
                                    <div class="eq-alert__t" id="eq-alert-sin-t">—</div>
                                </div>
                                <div class="eq-alert__d" id="eq-alert-sin-d">—</div>
                                <button type="button" class="eq-alert__cta" id="eq-alert-sin-cta">Ver asesores →</button>
                            </div>
                        </div>
                    </section>

                    <div class="st-error" id="st-error" hidden></div>
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
    nonce_mi_equipo: '<?php echo wp_create_nonce('billetera_get_mi_equipo'); ?>'
};
</script>
<script src="<?php echo billetera_asset_url('/assets/js/billetera-360-equipo.js'); ?>"></script>

</body>
</html>
