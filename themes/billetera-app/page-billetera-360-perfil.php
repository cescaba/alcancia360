<?php
/**
 * Template Name: Mi alcancia 360 Perfil
 * Description: Mi alcancia 360 - Pantalla "Mi perfil"
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

$user_id   = $current_user->ID;
$roles     = (array) $current_user->roles;
$es_jefe   = in_array('jefe_venta', $roles, true);
$show_ranking = !$es_jefe;

$iniciales   = function_exists('billetera_get_iniciales') ? billetera_get_iniciales($current_user) : 'U';
$rol_label   = $es_jefe ? 'Jefe de Venta' : 'Asesor';

$tienda_label = '';
$tienda_id    = get_user_meta($user_id, '_tienda_asociada', true);
if ($tienda_id) {
    $tienda = get_post($tienda_id);
    if ($tienda) {
        $tienda_label = $tienda->post_title;
    }
}

$user_login = $current_user->user_login;
$user_email = $current_user->user_email;

$foto_url = function_exists('billetera_get_foto_url') ? billetera_get_foto_url($user_id) : '';

$fecha_ingreso = get_user_meta($user_id, '_fecha_ingreso', true);
$ingreso_ts    = $fecha_ingreso ? strtotime($fecha_ingreso) : strtotime($current_user->user_registered);
$meses_full    = array('Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre');
$ingreso_label = $meses_full[intval(date('n', $ingreso_ts)) - 1] . ' ' . date('Y', $ingreso_ts);

$url_cambiar = home_url('/cambiar-contrasena');
$logout_url  = wp_logout_url(home_url());

if (function_exists('billetera_get_template_url')) {
    $url_registro    = billetera_get_template_url('page-billetera-360-responsive.php');
    $url_movimientos = billetera_get_template_url('page-billetera-360-movimientos.php');
    $home_url = $es_jefe ? $url_movimientos : $url_registro;
} else {
    $home_url = home_url();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi perfil</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-fonts.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-responsive.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-header.css">
    <link rel="stylesheet" href="<?php echo get_template_directory_uri(); ?>/assets/css/billetera-360-perfil.css">
</head>
<body <?php body_class(); ?>>

<?php get_template_part('template-parts/header-app'); ?>

<div class="site-wrapper">
    <div class="main-content">
        <div class="perfil-wrap">

            <div class="perfil-head">
                <a class="perfil-back" href="<?php echo esc_url($home_url); ?>">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#0A6CB4" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"></path></svg>
                    Mi perfil
                </a>
            </div>

            <div class="perfil-card">
                <label class="perfil-photo<?php echo $foto_url ? ' perfil-photo--has-foto' : ''; ?>" title="Cambiar foto">
                    <span class="perfil-photo-initials"><?php echo esc_html($iniciales); ?></span>
                    <?php if ($foto_url): ?>
                    <img class="perfil-photo-img" src="<?php echo esc_url($foto_url); ?>" alt="Foto de perfil">
                    <?php endif; ?>
                    <span class="perfil-photo-overlay">
                        <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle></svg>
                        Foto
                    </span>
                    <input type="file" id="perfil-foto-input" accept="image/*">
                </label>
                <div class="perfil-info">
                    <div class="perfil-name"><?php echo esc_html($current_user->display_name); ?></div>
                    <div class="perfil-role"><?php echo esc_html($rol_label . ($tienda_label ? ' · ' . $tienda_label : '')); ?></div>
                </div>
            </div>

            <div class="perfil-stats<?php echo $show_ranking ? '' : ' perfil-stats--no-ranking'; ?>">
                <div class="perfil-stat">
                    <div class="perfil-stat-val" id="perfil-ventas">0</div>
                    <div class="perfil-stat-label">Ventas del mes</div>
                </div>
                <div class="perfil-stat perfil-stat--alt">
                    <div class="perfil-stat-val perfil-stat-val--gold" id="perfil-acumulado">S/0</div>
                    <div class="perfil-stat-label">Comisión acumulada</div>
                </div>
                <?php if ($show_ranking): ?>
                <div class="perfil-stat">
                    <div class="perfil-stat-val perfil-stat-val--blue" id="perfil-ranking">#0</div>
                    <div class="perfil-stat-label">Ranking en mi tienda</div>
                </div>
                <div class="perfil-stat">
                    <div class="perfil-stat-val perfil-stat-val--blue" id="perfil-ranking-global">#0</div>
                    <div class="perfil-stat-label">Ranking global</div>
                </div>
                <?php endif; ?>
            </div>

            <div class="perfil-section-label">Mis datos</div>
            <div class="perfil-datos">
                <div class="perfil-dato">
                    <span>DNI</span>
                    <b><?php echo esc_html($user_login); ?></b>
                </div>
                <div class="perfil-dato">
                    <span>Correo</span>
                    <b><?php echo esc_html($user_email); ?></b>
                </div>
                <div class="perfil-dato">
                    <span>Taller</span>
                    <b><?php echo esc_html($tienda_label ? $tienda_label : '—'); ?></b>
                </div>
                <div class="perfil-dato">
                    <span>Ingreso</span>
                    <b><?php echo esc_html($ingreso_label); ?></b>
                </div>
            </div>

            <a class="perfil-btn" href="<?php echo esc_url($url_cambiar); ?>">Cambiar contraseña</a>
            <a class="perfil-btn perfil-btn--danger" href="<?php echo esc_url($logout_url); ?>">Cerrar sesión</a>

        </div>

        <?php get_template_part('template-parts/bottom-tabbar'); ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=billetera_get_balance')
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.success) return;
            var d = res.data;
            function set(id, v) { var el = document.getElementById(id); if (el) el.textContent = v; }
            set('perfil-ventas', String(d.ventas_mes || 0));
            set('perfil-acumulado', 'S/' + Math.round(d.acumulado_ano || 0).toLocaleString('es-PE'));
            var rank = (d.ranking && d.ranking.rank) ? d.ranking.rank : 0;
            var total = (d.ranking && d.ranking.total) ? d.ranking.total : 0;
            var rankGlobal = (d.ranking_global && d.ranking_global.rank) ? d.ranking_global.rank : 0;
            var totalGlobal = (d.ranking_global && d.ranking_global.total) ? d.ranking_global.total : 0;
            set('perfil-ranking', rank > 0 ? '#' + rank + ' / ' + total : '—');
            set('perfil-ranking-global', rankGlobal > 0 ? '#' + rankGlobal + ' / ' + totalGlobal : '—');
        });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('perfil-foto-input');
    if (!input) return;

    input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (!file) return;

        var fd = new FormData();
        fd.append('action', 'billetera_upload_foto');
        fd.append('nonce', '<?php echo wp_create_nonce('billetera_foto_nonce'); ?>');
        fd.append('foto', file);

        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success) {
                    var label = document.querySelector('.perfil-photo');
                    if (!label) return;
                    var img = label.querySelector('.perfil-photo-img');
                    if (!img) {
                        img = document.createElement('img');
                        img.className = 'perfil-photo-img';
                        img.alt = 'Foto de perfil';
                        label.insertBefore(img, label.querySelector('.perfil-photo-overlay'));
                    }
                    img.src = res.data.url;
                    label.classList.add('perfil-photo--has-foto');

                    var avatars = document.querySelectorAll('.app-header__menu-avatar, .app-topbar__avatar-btn, .app-header__avatar-btn');
                    avatars.forEach(function (av) {
                        av.innerHTML = '<img class="bh-avatar-img" src="' + res.data.url + '" alt="">';
                    });
                } else {
                    alert(res.data && res.data.message ? res.data.message : 'Error al subir la foto');
                }
            })
            .catch(function () {
                alert('Error de conexión al subir la foto');
            });
    });
});
</script>

</body>
</html>
