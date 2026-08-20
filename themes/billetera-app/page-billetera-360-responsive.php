<?php
/**
 * Template Name: Billetera 360 Responsive
 * Description: Billetera 360 con diseño responsive (Desktop + Móvil)
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

// Detectar URL de página con template Billetera 360 Movimientos
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

$movimientos_url = get_page_by_template('page-billetera-360-movimientos.php');
$alcancia_img   = get_template_directory_uri() . '/assets/img/alcancia.svg';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billetera 360</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-registro.css">
</head>
<body>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- SCREEN: REGISTRO VENTA -->
        <div class="screen active" id="screen-registro">
            <div class="reg-wrap">

                <div class="reg-head">
                    <h1 class="reg-title">Registrar venta</h1>
                    <span class="reg-step">Paso 1 de 1</span>
                </div>

                <div class="reg-body">

                    <!-- Alcancía -->
                    <div class="alcancia">
                        <div class="alcancia__pig">
                            <img class="alcancia__pig-base" src="<?php echo esc_url($alcancia_img); ?>" alt="Alcancía">
                            <img class="alcancia__pig-fill" id="alcancia-pig-fill" src="<?php echo esc_url($alcancia_img); ?>" alt="">
                        </div>
                        <div class="alcancia__info">
                            <div class="alcancia__label">En tu alcancía</div>
                            <div class="alcancia__balance" id="alcancia-balance">S/ 0.00</div>
                            <div class="alcancia__bar"><div class="alcancia__bar-fill" id="alcancia-bar-fill"></div></div>
                            <div class="alcancia__missing">Te faltan <b id="alcancia-missing">S/ 0.00</b></div>
                        </div>
                    </div>

                    <!-- Identificar con -->
                    <p class="reg-label">Identificar con</p>
                    <div class="reg-seg" id="id-seg">
                        <button type="button" data-type="placa">Placa</button>
                        <button type="button" data-type="vin">VIN</button>
                        <button type="button" data-type="ot">OT</button>
                        <button type="button" data-type="factura" class="is-active">Factura</button>
                    </div>

                    <!-- Identificador -->
                    <p class="reg-label" id="id-label">N° Factura</p>
                    <div class="reg-field">
                        <input type="text" id="id-input" placeholder="F001-000123">
                    </div>

                    <!-- Marca -->
                    <p class="reg-label">Marca</p>
                    <div class="reg-field">
                        <select id="marca-select">
                            <option value="">Selecciona una marca</option>
                        </select>
                    </div>

                    <!-- Categoría -->
                    <p class="reg-label">Categoría</p>
                    <div class="reg-field">
                        <select id="cat-select" disabled>
                            <option value="">Primero elige una marca</option>
                        </select>
                    </div>

                    <!-- Subcategoría -->
                    <p class="reg-label">Subcategoría</p>
                    <div class="reg-field">
                        <select id="sub-select" disabled>
                            <option value="">Primero elige una categoría</option>
                        </select>
                    </div>

                    <!-- Cantidad -->
                    <div id="cantidad-wrap" style="display: none;">
                        <p class="reg-label">Cantidad</p>
                        <div class="reg-field">
                            <input type="number" id="cantidad-input" min="1" value="1">
                        </div>
                    </div>

                    <!-- Comisión -->
                    <div class="reg-comision" id="reg-comision">
                        <span class="reg-comision__label">Comisión a registrar</span>
                        <span class="reg-comision__value" id="preview-amt">S/ 0.00</span>
                    </div>

                    <!-- Submit -->
                    <button class="reg-submit" id="submit-btn" disabled>Registrar venta</button>
                    <p class="reg-note">La comisión se acredita al cierre del mes previa validación del jefe de taller.</p>

                </div>
            </div>
        </div>

        <!-- BOTTOM TABBAR -->
        <?php get_template_part('template-parts/bottom-tabbar'); ?>
    </div>
</div>

<!-- OVERLAY DE CELEBRACIÓN -->
<div class="overlay" id="overlay">
    <div class="celebrate">
        <div class="celebrate__rings">
            <span></span><span></span>
        </div>
        <div class="celebrate__pig">
            <img class="celebrate__pig-base" src="<?php echo esc_url($alcancia_img); ?>" alt="Alcancía">
            <img class="celebrate__pig-fill" id="celebrate-pig-fill" src="<?php echo esc_url($alcancia_img); ?>" alt="">
        </div>
        <div class="celebrate__amount" id="gain-amount">+S/ 0.00</div>
        <div class="celebrate__label">Comisión acreditada</div>
        <button class="celebrate__btn" id="continue-btn">Ver mi alcancía</button>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
    movimientos_url: '<?php echo esc_url($movimientos_url); ?>'
};
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-logic.js"></script>

</body>
</html>
