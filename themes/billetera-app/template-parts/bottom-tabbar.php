<?php
/**
 * Bottom tabbar (navegación inferior móvil/tablet)
 */

$current_slug = get_page_template_slug();
$roles        = (array) wp_get_current_user()->roles;
$es_jefe      = in_array('jefe_venta', $roles, true);

$url_registro    = billetera_get_template_url('page-billetera-360-responsive.php');
$url_movimientos = billetera_get_template_url('page-billetera-360-movimientos.php');

$tab_registro_active = ($current_slug === 'page-billetera-360-responsive.php');
$tab_alcancia_active = in_array($current_slug, array('page-billetera-360-movimientos.php', 'page-billetera-360-movimientos-todos.php'), true);
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
</nav>
