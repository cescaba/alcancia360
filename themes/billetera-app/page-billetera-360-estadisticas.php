<?php
/**
 * Template Name: Mi alcancia 360 Estadisticas
 * Description: Mi alcancia 360 - Pantalla "Mi desempeño" (estadísticas del asesor)
 */

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url());
    exit;
}

$current_user = wp_get_current_user();
$allowed_roles = array('asesor', 'administrator', 'jefe_venta');
if (!array_intersect($allowed_roles, $current_user->roles)) {
    wp_die('Acceso restringido. Solo administradores y asesores pueden acceder a Mi desempeño.');
}

// "Mi desempeño": interruptor maestro + solo asesores (y administradores).
// Jefes de venta no entran aunque el interruptor esté activo.
if (!defined('BILLETERA_SHOW_DESEMPENO') || !BILLETERA_SHOW_DESEMPENO) {
    $destino = billetera_get_template_url('page-billetera-360-movimientos.php');
    if (!$destino || $destino === '#') {
        $destino = home_url('/');
    }
    wp_safe_redirect($destino);
    exit;
}

$roles_desempeno = (array) $current_user->roles;
if (!array_intersect(array('asesor', 'administrator'), $roles_desempeno)) {
    $destino = billetera_get_template_url('page-billetera-360-movimientos.php');
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
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis estadísticas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-alcancia.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-stats.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-stats">
            <div class="st-wrap">

                <div class="st-head">
                    <div>
                        <h1 class="st-title">Mi desempeño</h1>
                        <p class="st-sub">¿Cómo voy este mes y qué me falta para llegar a mi meta?</p>
                    </div>
                    <div class="st-head__actions">
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

                <div class="st-loading" id="st-loading">Cargando tus estadísticas…</div>

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
                        <span class="st-banner__ad">Publicidad</span>
                    </div>

                    <!-- KPIs v3 -->
                    <div class="st-kpis5">
                        <div class="st-kpi5">
                            <div class="st-kpi5__val st-kpi5__val--blue" id="k5-comision">—</div>
                            <div class="st-kpi5__label">Comisión del mes</div>
                        </div>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val st-kpi5__val--gold" id="k5-avance">—</div>
                            <div class="st-kpi5__label">Avance de meta</div>
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

                    <!-- META + PARA LLEGAR -->
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
                                <div class="st-goal__cap" id="st-goal-cap">— de tu meta mensual</div>
                            </div>
                            <div class="st-goal__body">
                                <div class="st-goal__row">
                                    <div>
                                        <div class="st-goal__label">Mi pocito</div>
                                        <div class="st-goal__pocito" id="st-pocito">—</div>
                                    </div>
                                    <div class="st-goal__meta">
                                        <div class="st-goal__label">Meta</div>
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

                        <section class="st-need">
                            <div class="st-need__head">
                                <span class="st-need__title">Para llegar a tu meta</span>
                                <span class="st-need__dia">Necesitas <b id="st-need-dia">—</b> por día</span>
                            </div>
                            <div class="st-need__colhead" aria-hidden="true">
                                <span class="c-cat">Cat.</span>
                                <span class="c-prod">Producto</span>
                                <span class="c-com">Comisión por venta</span>
                                <span class="c-nec">Ventas necesarias</span>
                            </div>
                            <div id="st-need-list"></div>
                            <div class="st-need__empty" id="st-need-empty" hidden></div>
                            <div class="st-need__foot">
                                <a class="st-need__cta" href="<?php echo esc_url($registro_url); ?>">Registrar venta</a>
                            </div>
                        </section>
                    </div>

                    <!-- CHART + PRODUCTO -->
                    <div class="st-row">
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
                                <span class="st-prod__title" id="st-prod-title">¿En qué ganas más?</span>
                                <span class="st-prod__total" id="st-prod-total">—</span>
                            </div>
                            <div class="st-vista" id="st-break-tabs">
                                <button type="button" class="st-vista__btn is-active" data-break="categoria">Categoría</button>
                                <button type="button" class="st-vista__btn" data-break="subcategoria">Subcategoría</button>
                                <button type="button" class="st-vista__btn" data-break="linea">Línea</button>
                            </div>
                            <div class="st-breakdown" id="st-breakdown"></div>
                        </aside>
                    </div>

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
    nonce_mi_stats: '<?php echo wp_create_nonce('billetera_get_mi_stats'); ?>'
};
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-stats.js"></script>

</body>
</html>
