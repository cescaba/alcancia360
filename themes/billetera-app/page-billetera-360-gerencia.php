<?php
/**
 * Template Name: Mi alcancia 360 Gerencia
 * Description: Mi alcancia 360 - Pantalla "Mi concesionaria" (vista del gerente).
 *              Copia del layout de "Mi equipo" con el scope de las sucursales
 *              de la(s) concesionaria(s) donde el usuario es gerente.
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$allowed_roles = array('gerente', 'administrator');
if (!array_intersect($allowed_roles, $current_user->roles)) {
    // Asesores van a su vista de desempeño; jefes a Mi equipo.
    $destino = in_array('jefe_venta', (array) $current_user->roles, true)
        ? billetera_get_template_url('page-billetera-360-equipo.php')
        : billetera_get_template_url('page-billetera-360-estadisticas.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}

$alcancia_img = billetera_asset_url('/assets/img/alcancia.svg');

// Sucursales de la(s) concesionaria(s) del gerente (perfil vía _gerentes_asociados).
// Sin fallback: si no es gerente de ninguna concesionaria, ve el aviso de vacío.
$tiendas_gerente = function_exists('billetera_gerente_tiendas') ? billetera_gerente_tiendas($current_user->ID) : array();

$meta_equipo_v2 = false;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi concesionaria</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-fonts.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-responsive.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-header.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-alcancia.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-stats.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-equipo.css?v=3'); ?>">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-equipo">
            <div class="st-wrap">

                <div class="st-head">
                    <div>
                        <h1 class="st-title">Mi concesionaria</h1>
                        <p class="st-sub">¿Cómo van tus sucursales este mes?</p>
                    </div>
                    <div class="st-head__actions">
                        <?php if (!empty($tiendas_gerente)): ?>
                        <!-- Selector de sucursal (multi-sucursal) -->
                        <div class="eq-sucursal" id="eq-sucursal">
                            <button type="button" class="eq-sucursal__btn" id="eq-sucursal-btn" aria-haspopup="listbox" aria-expanded="false">
                                <span class="eq-sucursal__label">Sucursal:</span>
                                <span class="eq-sucursal__val" id="eq-sucursal-val">Todas (<?php echo count($tiendas_gerente); ?>)</span>
                                <span class="eq-sucursal__chev" aria-hidden="true">▾</span>
                            </button>
                            <div class="eq-sucursal__menu" id="eq-sucursal-menu" role="listbox" aria-label="Sucursal" hidden>
                                <button type="button" class="eq-sucursal__opt is-active" role="option" aria-selected="true" data-tienda="0">Todas (<?php echo count($tiendas_gerente); ?>)</button>
                                <?php foreach ($tiendas_gerente as $tid_g => $tname_g): ?>
                                <button type="button" class="eq-sucursal__opt" role="option" aria-selected="false" data-tienda="<?php echo esc_attr($tid_g); ?>"><?php echo esc_html($tname_g); ?></button>
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

                <div class="st-loading" id="st-loading">Cargando tu concesionaria…</div>

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

                    <!-- KPIs de la concesionaria -->
                    <div class="st-kpis5">
                        <div class="st-kpi5">
                            <div class="st-kpi5__val st-kpi5__val--blue" id="k5-ventas">—</div>
                            <div class="st-kpi5__label">Ventas del equipo</div>
                        </div>

                        <?php if ($meta_equipo_v2): ?>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val st-kpi5__val--gold" id="k5-avance">—</div>
                            <div class="st-kpi5__label">Avance de meta</div>
                        </div>
                        <div class="st-kpi5">
                            <div class="st-kpi5__val" id="k5-faltan">—</div>
                            <div class="st-kpi5__label">Faltan para la meta</div>
                        </div>
                        <?php endif; ?>

                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val" id="k5-cierre">—</div>
                            <div class="st-kpi5__label">Para el cierre</div>
                        </div>
                        <div class="st-kpi5">
                            <div class="st-kpi5__val" id="k5-proy">—</div>
                            <div class="st-kpi5__label">Proyección al cierre</div>
                        </div>
                    </div>

                    <!-- ALCANCÍA + COMISIONES DEL EQUIPO -->
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
                            </div>
                            <div class="st-goal__body">
                                <div class="st-goal__row">
                                    <div>
                                        <div class="st-goal__label">Comisionado del equipo</div>
                                        <div class="st-goal__pocito" id="st-pocito">—</div>
                                    </div>
                                    <?php if ($meta_equipo_v2): ?>
                                    <div>
                                        <div class="st-goal__label">Meta del equipo</div>
                                        <div class="st-goal__meta-val" id="st-goal-meta">—</div>
                                    </div>
                                </div>
                                <div class="st-goal__bar">
                                    <div class="st-goal__fill" id="st-goal-fill"></div>
                                    <div class="st-goal__mark" id="st-goal-mark"></div>
                                </div>
                                <div class="st-goal__pace" id="st-goal-pace"></div>
                                <?php else: ?>
                                </div>
                                <div class="st-goal__minis" id="st-goal-minis">
                                    <div class="st-goal__mini"><span class="st-goal__mini-k">Ventas del periodo</span><b id="st-goal-mini-ventas">—</b></div>
                                    <div class="st-goal__mini"><span class="st-goal__mini-k">Proyección al cierre</span><b id="st-goal-mini-proy">—</b></div>
                                    <div class="st-goal__mini"><span class="st-goal__mini-k">Última venta</span><b id="st-goal-mini-ultima">—</b></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </aside>

                        <?php if ($meta_equipo_v2): ?>
                        <!-- Para llegar a tu meta · v2 (flag $meta_equipo_v2) -->
                        <section class="eq-com2">
                            <div class="eq-com2__meta">
                                <span class="eq-com2__meta-label">Para llegar a tu meta</span>
                                <span class="eq-com2__title-mes" id="eq-com2-mes">—</span>
                            </div>
                            <div class="eq-com2__head">
                                <span class="eq-com2__cat-h">Cat.</span>
                                <span class="eq-com2__concepto">Producto</span>
                                <span class="eq-com2__col eq-com2__comcell">Comisión por venta</span>
                                <span class="eq-com2__col">Ventas necesarias</span>
                            </div>
                            <div id="eq-com2-list"></div>
                            <div class="eq-com2__foot">
                                <span class="eq-com2__nota">Comisión por venta según tus registros, sin bonos. Referencial.</span>
                            </div>
                        </section>
                        <?php else: ?>
                        <section class="eq-aporta">
                            <div class="eq-aporta__head">
                                <span class="eq-aporta__title">Comisiones del equipo</span>
                                <span class="eq-aporta__mes" id="eq-aporta-mes">—</span>
                            </div>
                            <div class="eq-aporta__colhead" aria-hidden="true">
                                <span class="eq-aporta__c1">#</span>
                                <span>Vendedor</span>
                                <span>Comisionado</span>
                            </div>
                            <div class="eq-aporta__list" id="eq-aporta-list"></div>
                            <div class="eq-aporta__foot">
                                <span id="eq-aporta-total-note">—</span>
                                <span class="eq-aporta__note">Se genera con las ventas validadas de las sucursales.</span>
                            </div>
                        </section>
                        <?php endif; ?>
                    </div>

                    <!-- CHART + ¿QUÉ VENDE EL EQUIPO? -->
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
                                <?php if ($meta_equipo_v2): ?>
                                <div class="st-chartlegend"><span class="st-chartlegend__sw"></span>Meta <b id="st-chart-meta">—</b></div>
                                <?php endif; ?>
                            </div>
                            <div class="st-chartwrap">
                                <div class="st-chartmetal" id="st-chart-metal" hidden></div>
                                <div class="st-chart" id="st-trend"></div>
                            </div>
                            <div class="st-chartfoot" id="st-trend-detail"></div>
                        </section>

                        <aside class="st-prod">
                            <div class="st-prod__head">
                                <span class="st-prod__title" id="eq-mix-title">¿Qué vende el equipo?</span>
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
                            <span class="eq-top__c5">Últ. venta</span>
                            <span class="eq-top__c4">Ventas</span>
                            <span class="eq-top__c6">Comisionado</span>
                        </div>
                        <div id="eq-top-list"></div>
                        <div class="eq-top__note">Ranking por número de ventas. "Comisionado" es la suma de las comisiones del asesor en el periodo por sucursal.</div>
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
    nonce_mi_equipo: '<?php echo wp_create_nonce('billetera_get_gerencia'); ?>',
    action_mi_equipo: 'billetera_get_gerencia',
    meta_equipo_v2: <?php echo $meta_equipo_v2 ? 'true' : 'false'; ?>,
    ocultar_aporta: true,
    aportan_max: 5
};
</script>
<script src="<?php echo billetera_asset_url('/assets/js/billetera-360-equipo.js?v=5'); ?>"></script>

</body>
</html>
