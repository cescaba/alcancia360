<?php
/**
 * Template Name: Mi alcancia 360 Movimientos - Todos
 * Description: Mi alcancia 360 - Historial de movimientos (agrupado por mes)
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

$roles   = (array) $current_user->roles;
$es_jefe = in_array('jefe_venta', $roles, true);

// Detectar URL de páginas por template
function get_page_by_template($template_name) {
    global $wpdb;
    $page = $wpdb->get_row($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         INNER JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
         WHERE {$wpdb->posts}.post_type = 'page'
         AND {$wpdb->posts}.post_status = 'publish'
         AND {$wpdb->postmeta}.meta_key = '_wp_page_template'
         AND {$wpdb->postmeta}.meta_value = %s
         LIMIT 1",
        $template_name
    ));
    return $page ? get_permalink($page->ID) : '#';
}

$alcancia_url  = get_page_by_template('page-billetera-360-movimientos.php');
$registro_url  = get_page_by_template('page-billetera-360-responsive.php');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-alcancia.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-historial.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">
        <div class="screen active" id="screen-todos">

            <!-- MOBILE -->
            <div class="alc-wrap">
                <div class="alc-head">
                    <h1 class="alc-title">Historial</h1>
                    <a class="alc-back" href="<?php echo esc_url($alcancia_url); ?>">Volver</a>
                </div>

                <div class="alc-content">
                    <div id="hist-list"></div>
                    <p class="alc-empty" id="hist-empty" style="display:none;">No hay movimientos registrados aún.</p>
                </div>
            </div>

            <!-- DESKTOP -->
            <div class="hist-desk">
                <div class="hist-desk__head">
                    <div>
                        <div class="hist-desk__title">Historial</div>
                        <div class="hist-desk__sub">Todos tus movimientos, ordenados por mes.</div>
                    </div>
                    <a class="hist-desk__reg" href="<?php echo esc_url($registro_url); ?>">Registrar venta</a>
                </div>
                <div id="hist-desk-list"></div>
                <p class="hist-desk__empty" id="hist-desk-empty" style="display:none;">No hay movimientos registrados aún.</p>
            </div>

        </div>

        <?php get_template_part('template-parts/bottom-tabbar'); ?>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    es_jefe: <?php echo $es_jefe ? 'true' : 'false'; ?>,
    nonce_get_all_movements: '<?php echo wp_create_nonce('billetera_get_all_movements'); ?>'
};
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-todos.js"></script>

</body>
</html>
