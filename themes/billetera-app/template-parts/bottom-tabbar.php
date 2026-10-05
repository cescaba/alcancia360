<?php
/**
 * Bottom tabbar (navegación inferior móvil/tablet)
 */

$current_slug = get_page_template_slug();
$roles        = (array) wp_get_current_user()->roles;
$es_jefe      = in_array('jefe_venta', $roles, true);

$url_registro    = billetera_get_template_url('page-billetera-360-responsive.php');
$url_movimientos = billetera_get_template_url('page-billetera-360-movimientos.php');
$url_stats       = billetera_get_template_url('page-billetera-360-estadisticas.php');
$url_ranking     = billetera_get_template_url('page-billetera-360-ranking.php');

$tab_registro_active = ($current_slug === 'page-billetera-360-responsive.php');
$tab_alcancia_active = in_array($current_slug, array('page-billetera-360-movimientos.php', 'page-billetera-360-movimientos-todos.php'), true);
$tab_stats_active    = ($current_slug === 'page-billetera-360-estadisticas.php');
$tab_ranking_active  = ($current_slug === 'page-billetera-360-ranking.php');
?>

<nav class="bottom-tabbar">
    <a class="bottom-tabbar__tab<?php echo $tab_registro_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_registro); ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"></path></svg>
        <span>Registrar</span>
    </a>
    <a class="bottom-tabbar__tab<?php echo $tab_alcancia_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_movimientos); ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9c0-8 14-8 14 0"></path><rect x="2" y="9" width="20" height="11" rx="1"></rect><path d="M16 14h2"></path></svg>
        <span>Mi alcancía</span>
    </a>
    <a class="bottom-tabbar__tab<?php echo $tab_stats_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_stats); ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path></svg>
        <span>Desempeño</span>
    </a>
    <a class="bottom-tabbar__tab<?php echo $tab_ranking_active ? ' is-active' : ''; ?>" href="<?php echo esc_url($url_ranking); ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3"></path></svg>
        <span>Ranking</span>
    </a>
</nav>
