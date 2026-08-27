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
 * Cómo publicar una versión nueva: subir la versión en la cabecera de
 * seoxan-wp-status.php, hacer commit, y crear una release/tag en GitHub
 * con ese mismo número (con o sin "v" delante, p.ej. "1.5.0" o "v1.5.0").
 * No hace falta build ni adjuntar un .zip a mano: PUC usa el zip que
 * genera GitHub automáticamente para esa release.
 */

define('SEOXAN_GITHUB_TOKEN_OPTION', 'seoxan_status_github_token');

/**
 * El repositorio es privado, así que hace falta un token de acceso
 * personal de GitHub (con permiso de solo lectura de "Contents" sobre ese
 * repo basta) para poder comprobar/descargar actualizaciones. Se puede
 * fijar de dos formas:
 *
 *  - Constante SEOXAN_STATUS_GITHUB_TOKEN en wp-config.php. Tiene prioridad
 *    si está definida, para poder forzar un valor puntual en un sitio
 *    concreto sin tocar la base de datos.
 *  - Vía la API remota: POST/DELETE /wp-json/seoxan-status/v1/self-update-token
 *    (autenticado con la misma API Key de siempre — ver rest-api.php),
 *    pensado para poder empujar el MISMO token a muchos sitios de golpe
 *    desde un panel central, en vez de editar wp-config.php uno a uno.
 *    Se guarda en la base de datos (opción no autocargada), no en el
 *    propio código del plugin.
 *
 * Devuelve null si no hay ningún token configurado por ninguna de las dos vías.
 */
function seoxan_get_github_token()
{
    if (defined('SEOXAN_STATUS_GITHUB_TOKEN') && SEOXAN_STATUS_GITHUB_TOKEN) {
        return ['token' => SEOXAN_STATUS_GITHUB_TOKEN, 'source' => 'wp-config'];
    }

    $token = get_option(SEOXAN_GITHUB_TOKEN_OPTION, '');
    if ($token) {
        return ['token' => $token, 'source' => 'option'];
    }

    return null;
}

function seoxan_set_github_token($token)
{
    update_option(SEOXAN_GITHUB_TOKEN_OPTION, $token, false);
}

function seoxan_clear_github_token()
{
    delete_option(SEOXAN_GITHUB_TOKEN_OPTION);
}

/**
 * Nunca se devuelve el token completo por la API ni se muestra en el
 * admin — solo esta vista parcial, igual que ya se hace con la propia API
 * Key del plugin (ver api-key.php).
 */
function seoxan_mask_github_token($token)
{
    $token = (string) $token;
    if (strlen($token) <= 12) {
        return str_repeat('•', max(strlen($token) - 2, 0)) . substr($token, -2);
    }
    return substr($token, 0, 8) . '…' . substr($token, -4);
}

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

    $token_info = seoxan_get_github_token();
    if ($token_info) {
        $update_checker->setAuthentication($token_info['token']);
    }

    return $update_checker;
}
// Se llama directamente (no dentro de otro hook): PUC registra sus propios
// hooks internos en el constructor y espera poder hacerlo cuanto antes.
seoxan_init_self_updater();

/**
 * Aviso en las páginas de este plugin si no hay ningún token de GitHub
 * configurado (ni en wp-config.php ni vía la API): sin él, las
 * comprobaciones de actualización fallan en silencio (el repositorio es
 * privado) y nadie se entera de que hay una versión nueva.
 */
add_action('admin_notices', function () {
    if (seoxan_get_github_token()) return;
    if (!current_user_can('manage_options')) return;

    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if (strpos($page, 'seoxan-wp-status') !== 0) return;

    echo '<div class="notice notice-warning"><p>';
    echo '⚠️ <strong>Seoxan WP Status</strong>: no hay ningún token de GitHub configurado. Sin él no se pueden comprobar ';
    echo 'actualizaciones nuevas del propio plugin (el repositorio es privado). Fíjalo con ';
    echo '<code>POST /wp-json/seoxan-status/v1/self-update-token</code> o con la constante <code>SEOXAN_STATUS_GITHUB_TOKEN</code> en wp-config.php.';
    echo '</p></div>';
});
