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
    wp_die('Acceso restringido. Solo administradores, asesores y jefes de venta pueden acceder.');
}

$meses = array('Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
$mes_label = $meses[intval(current_time('n')) - 1] . ' ' . current_time('Y');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis estadísticas</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
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
                        <p class="st-sub">Tus comisiones, productos y avance de meta.</p>
                    </div>
                    <div class="st-period" id="st-period">
                        <button type="button" class="st-period__btn is-active" data-periodo="mes">Mes</button>
                        <button type="button" class="st-period__btn" data-periodo="ano">Año</button>
                        <button type="button" class="st-period__btn" data-periodo="historico">Histórico</button>
                    </div>
                </div>

                <div class="st-loading" id="st-loading">Cargando tus estadísticas…</div>

                <div class="st-body" id="st-body" hidden>

                    <!-- RESUMEN -->
                    <div class="st-kpis">
                        <div class="st-kpi st-kpi--primary">
                            <div class="st-kpi__label">Comisión del mes</div>
                            <div class="st-kpi__value" id="st-mes">S/ 0.00</div>
                            <div class="st-kpi__hint" id="st-var-mes"></div>
                        </div>
                        <div class="st-kpi">
                            <div class="st-kpi__label">Ventas del mes</div>
                            <div class="st-kpi__value" id="st-ventas">0</div>
                            <div class="st-kpi__hint" id="st-unidades">0 unidades</div>
                        </div>
                        <div class="st-kpi">
                            <div class="st-kpi__label">Ticket promedio</div>
                            <div class="st-kpi__value" id="st-ticket">S/ 0.00</div>
                            <div class="st-kpi__hint">por venta registrada</div>
                        </div>
                        <div class="st-kpi">
                            <div class="st-kpi__label">Acumulado del año</div>
                            <div class="st-kpi__value" id="st-ano">S/ 0.00</div>
                            <div class="st-kpi__hint" id="st-historico">Histórico S/ 0.00</div>
                        </div>
                    </div>

                    <!-- META -->
                    <div class="st-card st-meta">
                        <div class="st-card__head">
                            <div class="st-card__title">Avance de meta · <?php echo esc_html($mes_label); ?></div>
                            <div class="st-card__hint" id="st-meta-pct">0%</div>
                        </div>
                        <div class="st-bar"><div class="st-bar__fill" id="st-meta-fill"></div></div>
                        <div class="st-meta__foot">
                            <span>Te faltan <b id="st-faltan">S/ 0.00</b></span>
                            <span>Meta <b id="st-meta">S/ 0.00</b></span>
                            <span>Proyección <b id="st-proyeccion">S/ 0.00</b></span>
                        </div>
                    </div>

                    <!-- TENDENCIA -->
                    <div class="st-card">
                        <div class="st-card__head">
                            <div class="st-card__title">Últimos 6 meses</div>
                        </div>
                        <div class="st-chart" id="st-trend"></div>
                        <div class="st-trend-detail" id="st-trend-detail"></div>
                    </div>

                    <!-- DESGLOSE -->
                    <div class="st-card">
                        <div class="st-card__head">
                            <div class="st-card__title">¿En qué ganas más?</div>
                            <div class="st-tabs" id="st-break-tabs">
                                <button type="button" class="st-tab is-active" data-break="categoria">Categoría</button>
                                <button type="button" class="st-tab" data-break="subcategoria">Subcategoría</button>
                                <button type="button" class="st-tab" data-break="linea">Línea</button>
                            </div>
                        </div>
                        <div class="st-breakdown" id="st-breakdown"></div>
                    </div>

                    <!-- RANKING -->
                    <div class="st-card">
                        <div class="st-card__head">
                            <div class="st-card__title">Mi posición del mes</div>
                        </div>
                        <div class="st-rank-grid">
                            <div class="st-rank">
                                <div class="st-rank__value" id="st-rank-tienda">#0 / 0</div>
                                <div class="st-rank__label">En mi tienda</div>
                            </div>
                            <div class="st-rank">
                                <div class="st-rank__value" id="st-rank-global">#0 / 0</div>
                                <div class="st-rank__label">Global</div>
                            </div>
                        </div>
                        <div class="st-rank__racha">Racha actual: <b id="st-racha">0 días</b></div>
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
