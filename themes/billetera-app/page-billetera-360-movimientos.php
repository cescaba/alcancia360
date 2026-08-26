<?php
/**
 * Template Name: Mi alcancia 360 Movimientos
 * Description: Mi alcancia 360 - Pantalla "Mi alcancía"
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

$roles        = (array) $current_user->roles;
$es_jefe      = in_array('jefe_venta', $roles, true);
$show_ranking = !$es_jefe;

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

$todos_url  = get_page_by_template('page-billetera-360-movimientos-todos.php');
$registro_url = get_page_by_template('page-billetera-360-responsive.php');
$alcancia_img = get_template_directory_uri() . '/assets/img/alcancia.svg';

$meses = array('Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
$mes_label = $meses[intval(date('n')) - 1] . ' ' . date('Y');
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi alcancía</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-alcancia.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <!-- MAIN CONTENT -->
    <div class="main-content">

        <!-- SCREEN: MI ALCANCÍA -->
        <div class="screen active" id="screen-wallet">
            <div class="alc-wrap">

                <div class="alc-head">
                    <h1 class="alc-title">Mi alcancía</h1>
                    <span class="alc-month"><?php echo esc_html($mes_label); ?></span>
                </div>

                <!-- Chancho -->
                <div class="alc-pig-zone">
                    <div class="alc-pig-bob" id="alc-pig-bob">
                        <div class="alc-pig">
                            <button type="button" class="alc-pig__btn" id="alc-pig-btn" aria-label="Toca la alcancía">
                                <span class="alc-pig__coin"></span>
                                <img class="alc-pig__base" src="<?php echo esc_url($alcancia_img); ?>" alt="Alcancía">
                                <img class="alc-pig__fill" id="alc-pig-fill" src="<?php echo esc_url($alcancia_img); ?>" alt="">
                            </button>
                        </div>
                    </div>
                    <div class="alc-pig-hint" id="alc-pig-hint">Toca la alcancía</div>
                </div>

                <div class="alc-content">
                    <!-- Saldo / Avance -->
                    <div class="alc-stats">
                        <div class="alc-stats__saldo">
                            <div class="alc-stats__label">Mi pocito</div>
                            <div class="alc-stats__value alc-stats__value--blue" id="saldo-mes">S/ 0.00</div>
                        </div>
                        <div class="alc-stats__avance">
                            <div class="alc-stats__label">Avance meta</div>
                            <div class="alc-stats__value alc-stats__value--gold" id="avance-meta">0%</div>
                        </div>
                    </div>

                    <!-- Barra de progreso -->
                    <div class="alc-progress">
                        <div class="alc-progress__bar">
                            <div class="alc-progress__fill" id="bar-fill"></div>
                        </div>
                        <div class="alc-progress__meta">
                            <span>Te faltan <b id="faltan">S/ 0.00</b></span>
                            <span id="meta-label">Meta S/ 0.00</span>
                        </div>
                    </div>

                    <!-- Stats 3 columnas -->
                    <div class="alc-grid<?php echo $show_ranking ? '' : ' alc-grid--no-ranking'; ?>">
                        <div class="alc-grid__item">
                            <div class="alc-grid__value" id="acumulado-ano">S/ 0.00</div>
                            <div class="alc-grid__label">Acumulado año</div>
                        </div>
                        <div class="alc-grid__item alc-grid__item--alt">
                            <div class="alc-grid__value" id="ventas-mes">0</div>
                            <div class="alc-grid__label">Ventas del mes</div>
                        </div>
                        <?php if ($show_ranking): ?>
                        <div class="alc-grid__item">
                            <div class="alc-grid__value alc-grid__value--gold" id="ranking">#0 / 0</div>
                            <div class="alc-grid__label">Ranking en mi tienda</div>
                        </div>
                        <div class="alc-grid__item alc-grid__item--alt">
                            <div class="alc-grid__value alc-grid__value--gold" id="ranking-global">#0 / 0</div>
                            <div class="alc-grid__label">Ranking global</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Movimientos -->
                    <div class="alc-mov-head">
                        <div class="alc-mov-title">Movimientos recientes</div>
                        <a class="alc-mov-all" href="<?php echo esc_url($todos_url); ?>">Ver todo</a>
                    </div>
                    <div class="alc-mov-list" id="movs-list"></div>
                </div>

            </div>

            <!-- DESKTOP: Mi alcancía -->
            <div class="alc-desk">
                <div class="alc-desk__head">
                    <div>
                        <div class="alc-desk__title">Mi alcancía</div>
                        <div class="alc-desk__sub">Comisiones acumuladas del periodo en curso.</div>
                    </div>
                    <div class="alc-desk__head-actions">
                        <div class="alc-desk__month"><?php echo esc_html($mes_label); ?></div>
                        <a class="alc-desk__reg" href="<?php echo esc_url($registro_url); ?>">Registrar venta</a>
                    </div>
                </div>

                <div class="alc-desk__grid">
                    <div class="alc-desk__card">
                        <div class="alc-desk__pig-zone">
                            <div class="alc-desk__pig">
                                <button type="button" class="alc-desk__pig-btn" id="alc-desk-pig-btn" aria-label="Toca la alcancía">
                                    <span class="alc-desk__pig-coin"></span>
                                    <img class="alc-desk__pig-base" src="<?php echo esc_url($alcancia_img); ?>" alt="Alcancía">
                                    <img class="alc-desk__pig-fill" id="desk-pig-fill" src="<?php echo esc_url($alcancia_img); ?>" alt="">
                                </button>
                            </div>
                            <div class="alc-desk__pig-pct" id="alc-desk-pig-hint">0% de tu meta mensual</div>
                        </div>
                        <div class="alc-desk__card-body">
                            <div class="alc-desk__saldo-label">Mi pocito</div>
                            <div class="alc-desk__saldo" id="desk-saldo">S/ 0.00</div>
                            <div class="alc-desk__bar"><div class="alc-desk__bar-fill" id="desk-bar-fill"></div></div>
                            <div class="alc-desk__meta">
                                <span>Te faltan <b id="desk-faltan">S/ 0.00</b></span>
                                <span id="desk-meta-label">Meta S/ 0.00</span>
                            </div>
                        </div>
                    </div>

                    <div class="alc-desk__right">
                        <div class="alc-desk__stats<?php echo $show_ranking ? '' : ' alc-desk__stats--no-ranking'; ?>">
                            <div class="alc-desk__stat">
                                <div class="alc-desk__stat-val" id="desk-acumulado-ano">S/ 0.00</div>
                                <div class="alc-desk__stat-label">Acumulado año</div>
                            </div>
                            <div class="alc-desk__stat">
                                <div class="alc-desk__stat-val" id="desk-ventas-mes">0</div>
                                <div class="alc-desk__stat-label">Ventas del mes</div>
                            </div>
                            <?php if ($show_ranking): ?>
                            <div class="alc-desk__stat">
                                <div class="alc-desk__stat-val alc-desk__stat-val--gold" id="desk-ranking">#0 / 0</div>
                                <div class="alc-desk__stat-label">Ranking en mi tienda</div>
                            </div>
                            <div class="alc-desk__stat">
                                <div class="alc-desk__stat-val alc-desk__stat-val--gold" id="desk-ranking-global">#0 / 0</div>
                                <div class="alc-desk__stat-label">Ranking global</div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="alc-desk__movs">
                            <div class="alc-desk__movs-head">
                                <div class="alc-desk__movs-title">Movimientos del mes</div>
                                <a class="alc-desk__movs-all" href="<?php echo esc_url($todos_url); ?>">Ver historial completo</a>
                            </div>
                            <div class="alc-desk__table">
                                <div class="alc-desk__tr alc-desk__tr--head">
                                    <div class="alc-desk__td">Cat.</div>
                                    <div class="alc-desk__td">Producto</div>
                                    <div class="alc-desk__td"><?php echo $es_jefe ? 'Asesor' : 'Identificador'; ?></div>
                                    <div class="alc-desk__td">Fecha</div>
                                    <div class="alc-desk__td alc-desk__td--right">Comisión</div>
                                </div>
                                <div id="desk-movs-list"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BOTTOM TABBAR -->
        <?php get_template_part('template-parts/bottom-tabbar'); ?>
    </div>
</div>

<script>
window.billetera = {
    ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>'
};
</script>
<script src="<?php echo get_template_directory_uri(); ?>/assets/js/billetera-360-movimientos.js"></script>

</body>
</html>
