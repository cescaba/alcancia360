<?php
/**
 * Template Name: Mi alcancia 360 Gerencia
 * Description: Mi alcancia 360 - Pantalla "Mi concesionaria" (vista del gerente):
 *              sucursales de su concesionaria, KPIs y comparativa. Sin montos.
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

// Sucursales de la(s) concesionaria(s) del gerente.
$tiendas_gerente = function_exists('billetera_gerente_tiendas') ? billetera_gerente_tiendas($current_user->ID) : array();

$alcancia_img = billetera_asset_url('/assets/img/alcancia.svg');
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
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-equipo.css'); ?>">
    <link rel="stylesheet" href="<?php echo billetera_asset_url('/assets/css/billetera-360-gerencia.css'); ?>">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">

        <div class="screen active" id="screen-gerencia">
            <div class="st-wrap">

                <div class="st-head">
                    <div>
                        <h1 class="st-title">Mi concesionaria</h1>
                        <p class="st-sub">Ventas y avance de metas de las sucursales que administras.</p>
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
                        <div class="st-stepper" id="st-stepper">
                            <button type="button" class="st-step__btn" id="st-prev" aria-label="Mes anterior">‹</button>
                            <span class="st-step__label" id="st-step-label">—</span>
                            <button type="button" class="st-step__btn" id="st-next" aria-label="Mes siguiente" disabled>›</button>
                        </div>
                    </div>
                </div>

                <div class="st-loading" id="st-loading">Cargando tu concesionaria…</div>

                <?php if (empty($tiendas_gerente)): ?>
                    <div class="st-body">
                        <div class="gr-empty">
                            No eres gerente de ninguna concesionaria. Te deben asignar en la
                            concesionaria correspondiente para ver esta vista.
                        </div>
                    </div>
                <?php else: ?>

                <div class="st-body" id="st-body" hidden>

                    <!-- KPIs de la concesionaria (unidades, sin montos) -->
                    <div class="st-kpis5">
                        <div class="st-kpi5">
                            <div class="st-kpi5__val st-kpi5__val--blue" id="gr-kpi-ventas">—</div>
                            <div class="st-kpi5__label">Ventas</div>
                        </div>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val st-kpi5__val--gold" id="gr-kpi-avance">—</div>
                            <div class="st-kpi5__label">Avance de meta</div>
                        </div>
                        <div class="st-kpi5">
                            <div class="st-kpi5__val" id="gr-kpi-faltan">—</div>
                            <div class="st-kpi5__label">Faltan para la meta</div>
                        </div>
                        <div class="st-kpi5 st-kpi5--alt">
                            <div class="st-kpi5__val" id="gr-kpi-proy">—</div>
                            <div class="st-kpi5__label">Proyección del mes</div>
                        </div>
                    </div>

                    <!-- Comparativa de sucursales -->
                    <section class="gr-card">
                        <div class="gr-card__head">
                            <span class="gr-card__title">Comparativa de sucursales</span>
                            <span class="gr-card__periodo" id="gr-periodo-label">—</span>
                        </div>
                        <div class="gr-table-wrap">
                            <table class="gr-table">
                                <thead>
                                    <tr>
                                        <th>Sucursal</th>
                                        <th class="gr-num">Ventas</th>
                                        <th class="gr-num">Meta</th>
                                        <th class="gr-num">Avance</th>
                                        <th class="gr-num">Faltan</th>
                                        <th class="gr-num">Últ. venta</th>
                                    </tr>
                                </thead>
                                <tbody id="gr-tbody">
                                    <tr><td colspan="6" class="gr-empty-cell">—</td></tr>
                                </tbody>
                                <tfoot id="gr-tfoot">
                                    <tr class="gr-total">
                                        <td>Total</td>
                                        <td class="gr-num" id="gr-total-ventas">—</td>
                                        <td class="gr-num" id="gr-total-meta">—</td>
                                        <td class="gr-num" id="gr-total-avance">—</td>
                                        <td class="gr-num" id="gr-total-faltan">—</td>
                                        <td class="gr-num"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <p class="gr-note">*La meta se mide en unidades (ventas). No se muestran montos ni comisiones.</p>
                    </section>

                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<script>
window.billeteraGerencia = {
    ajax_url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
    nonce: '<?php echo wp_create_nonce('billetera_get_gerencia'); ?>',
    tiene_tiendas: <?php echo empty($tiendas_gerente) ? 'false' : 'true'; ?>
};
</script>
<script src="<?php echo billetera_asset_url('/assets/js/billetera-360-gerencia.js'); ?>"></script>
<?php get_footer(); ?>
