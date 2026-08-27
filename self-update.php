<?php
if (!defined('ABSPATH')) exit;

/**
 * Actualizaciones del propio plugin, vía GitHub Releases.
 *
 * Este plugin no está en WordPress.org, así que WordPress no comprueba
 * versiones nuevas por sí solo: sin esto, get_plugin_updates() nunca
 * sabría que existe una versión más reciente, y ni GET /updates ni
 * POST /update-plugin (ver rest-api.php / updates-runner.php) podrían
 * hacer nada con él — desde su punto de vista, este plugin simplemente no
 * tendría actualizaciones pendientes nunca.
 *
 * Usa Plugin Update Checker (MIT — ver vendor/plugin-update-checker/license.txt),
 * vendorizado sin gestor de paquetes, apuntando al repositorio en GitHub.
 * En cuanto detecta una versión nueva ahí, se integra de forma transparente
 * con el resto del sistema de actualizaciones de WordPress: no hace falta
 * ningún endpoint ni lógica adicional — GET /updates y POST /update-plugin
 * ya funcionan con este plugin exactamente igual que con cualquier otro.
 *
 * El repositorio es privado, así que hace falta un token de acceso
 * personal de GitHub (con permiso de solo lectura de "Contents" sobre ese
 * repo basta) definido en wp-config.php como SEOXAN_STATUS_GITHUB_TOKEN.
 * Se define fuera del plugin a propósito, para no dejarlo nunca en el
 * propio código ni en el repositorio que este mismo código describe.
 *
 * Cómo publicar una versión nueva: subir la versión en la cabecera de
 * seoxan-wp-status.php, hacer commit, y crear una release/tag en GitHub
 * con ese mismo número (con o sin "v" delante, p.ej. "1.5.0" o "v1.5.0").
 * No hace falta build ni adjuntar un .zip a mano: PUC usa el zip que
 * genera GitHub automáticamente para esa release.
 */

function seoxan_init_self_updater()
{
    $loader = SEOXAN_STATUS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
    if (!file_exists($loader)) {
        return null;
    }

    require_once $loader;

    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
        return null;
    }

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/tpvlinux/seoxan-wp-status/',
        SEOXAN_STATUS_PATH . 'seoxan-wp-status.php',
        'seoxan-wp-status'
    );

    if (defined('SEOXAN_STATUS_GITHUB_TOKEN') && SEOXAN_STATUS_GITHUB_TOKEN) {
        $update_checker->setAuthentication(SEOXAN_STATUS_GITHUB_TOKEN);
    }

    return $update_checker;
}
// Se llama directamente (no dentro de otro hook): PUC registra sus propios
// hooks internos en el constructor y espera poder hacerlo cuanto antes.
seoxan_init_self_updater();

/**
 * Aviso en las páginas de este plugin si el token de GitHub no está
 * configurado: sin él, las comprobaciones de actualización fallan (el
 * repositorio es privado) y nadie se entera de que hay una versión nueva.
 */
add_action('admin_notices', function () {
    if (defined('SEOXAN_STATUS_GITHUB_TOKEN') && SEOXAN_STATUS_GITHUB_TOKEN) return;
    if (!current_user_can('manage_options')) return;

    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if (strpos($page, 'seoxan-wp-status') !== 0) return;

    echo '<div class="notice notice-warning"><p>';
    echo '⚠️ <strong>Seoxan WP Status</strong>: no se ha definido <code>SEOXAN_STATUS_GITHUB_TOKEN</code> en wp-config.php. ';
    echo 'Sin ese token no se pueden comprobar actualizaciones nuevas del propio plugin (el repositorio en GitHub es privado).';
    echo '</p></div>';
});
