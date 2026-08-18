<?php
/**
 * Header template
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
    <?php wp_body_open(); ?>

    <div class="site-wrapper">
        <header class="site-header">
            <nav>
                <div class="logo">
                    <?php bloginfo('name'); ?>
                </div>
                <ul>
                    <?php
                    if (is_user_logged_in()) {
                        $current_user = wp_get_current_user();
                        echo '<li>Hola, ' . esc_html($current_user->display_name) . '</li>';
                        echo '<li><a href="' . wp_logout_url(home_url()) . '">Cerrar Sesión</a></li>';
                    } else {
                        echo '<li><a href="' . wp_login_url() . '">Iniciar Sesión</a></li>';
                    }
                    ?>
                </ul>
            </nav>
        </header>
