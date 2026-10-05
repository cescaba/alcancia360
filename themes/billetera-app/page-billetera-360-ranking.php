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
    wp_die('Acceso restringido. Solo administradores, asesores y jefes de venta pueden acceder.');
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
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
            <div class="rk-wrap">

                <div class="rk-head">
                    <div>
                        <h1 class="rk-title">Ranking</h1>
                        <p class="rk-sub">Tu posición del mes · <span id="rk-mes">—</span></p>
                    </div>
                </div>

                <div class="rk-hero">
                    <div class="rk-hero__item">
                        <div class="rk-hero__label">En mi tienda</div>
                        <div class="rk-hero__value" id="rk-rank-tienda">—</div>
                        <div class="rk-hero__hint" id="rk-total-tienda"></div>
                    </div>
                    <div class="rk-hero__item">
                        <div class="rk-hero__label">Global</div>
                        <div class="rk-hero__value" id="rk-rank-global">—</div>
                        <div class="rk-hero__hint" id="rk-total-global"></div>
                    </div>
                </div>

                <div class="rk-tabs" id="rk-tabs">
                    <button type="button" class="rk-tab is-active" data-ambito="tienda">Mi tienda</button>
                    <button type="button" class="rk-tab" data-ambito="global">Global</button>
                </div>

                <div class="rk-card">
                    <div class="rk-card__head" id="rk-card-title">Ranking de mi tienda</div>
                    <div class="rk-loading" id="rk-loading">Cargando ranking…</div>
                    <div class="rk-list" id="rk-list" hidden></div>
                    <div class="rk-empty" id="rk-empty" hidden></div>
                    <div class="rk-error" id="rk-error" hidden></div>
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
