<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('seoxan-status/v1', '/updates', [
        'methods'             => 'GET',
        'callback'            => 'seoxan_api_updates_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
    ]);

    register_rest_route('seoxan-status/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => 'seoxan_api_health_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
    ]);

    // Actualizaciones remotas: solo actúan sobre plugins/temas ya instalados
    // (nunca instalan software nuevo) y sobre la actualización de núcleo que
    // WordPress ya tiene detectada.
    register_rest_route('seoxan-status/v1', '/update-plugin', [
        'methods'             => 'POST',
        'callback'            => 'seoxan_api_update_plugin_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
        'args'                => [
            'plugin' => [
                'required'          => true,
                'type'              => 'string',
                'description'       => 'Ruta del plugin, p.ej. "akismet/akismet.php" (la misma clave que devuelve /updates).',
            ],
        ],
    ]);

    register_rest_route('seoxan-status/v1', '/update-theme', [
        'methods'             => 'POST',
        'callback'            => 'seoxan_api_update_theme_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
        'args'                => [
            'theme' => [
                'required'    => true,
                'type'        => 'string',
                'description' => 'Slug del tema (stylesheet), p.ej. "storefront".',
            ],
        ],
    ]);

    register_rest_route('seoxan-status/v1', '/update-core', [
        'methods'             => 'POST',
        'callback'            => 'seoxan_api_update_core_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
    ]);

    // Permite consultar después el resultado de la última actualización
    // lanzada por API, sin depender de haber capturado la respuesta del POST
    // original (útil si la consola central hace polling o si la conexión
    // se cortó antes de recibir la respuesta).
    register_rest_route('seoxan-status/v1', '/last-update', [
        'methods'             => 'GET',
        'callback'            => 'seoxan_api_last_update_endpoint',
        'permission_callback' => 'seoxan_api_permission_check',
    ]);
});

/**
 * Comprueba la API Key enviada por el cliente remoto antes de dar acceso
 * a cualquier endpoint. Acepta la cabecera X-Seoxan-Api-Key o un Bearer
 * token en Authorization.
 */
function seoxan_api_permission_check(WP_REST_Request $request)
{
    if (!seoxan_has_api_key()) {
        return new WP_Error('seoxan_no_key', 'No hay ninguna API Key configurada en este WordPress.', ['status' => 403]);
    }

    $ip = seoxan_api_get_client_ip();

    if (seoxan_api_ip_is_locked($ip)) {
        return new WP_Error('seoxan_locked', 'Demasiados intentos fallidos. Inténtalo de nuevo en unos minutos.', ['status' => 429]);
    }

    $provided = seoxan_api_extract_key($request);

    if (!seoxan_verify_api_key($provided)) {
        seoxan_api_register_failed_attempt($ip);
        return new WP_Error('seoxan_invalid_key', 'API Key inválida.', ['status' => 401]);
    }

    seoxan_api_clear_failed_attempts($ip);
    seoxan_touch_api_key_last_used();

    return true;
}

function seoxan_api_extract_key(WP_REST_Request $request)
{
    $header = $request->get_header('x-seoxan-api-key');
    if ($header) return trim($header);

    $auth = $request->get_header('authorization');
    if ($auth && stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

function seoxan_api_get_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '0.0.0.0';
}

/**
 * Si se solicita ?refresh=1, fuerza a WordPress a comprobar actualizaciones
 * de núcleo/plugins/temas contra WordPress.org antes de responder.
 * Limitado a una vez cada 5 minutos (global) para no abusar del servicio.
 */
function seoxan_api_maybe_force_check(WP_REST_Request $request)
{
    if (!$request->get_param('refresh')) return;
    if (get_transient('seoxan_api_force_check_lock')) return;

    set_transient('seoxan_api_force_check_lock', 1, 5 * MINUTE_IN_SECONDS);

    if (!function_exists('wp_version_check')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    wp_version_check();
    wp_update_plugins();
    wp_update_themes();
}

/**
 * Endpoint principal: estado de actualizaciones pendientes de WordPress
 * (núcleo, plugins y temas).
 */
function seoxan_api_updates_endpoint(WP_REST_Request $request)
{
    seoxan_api_maybe_force_check($request);

    if (!function_exists('get_plugin_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    // Núcleo
    $core_updates = get_core_updates();
    $core_update_available = false;
    $core_new_version = null;

    if (is_array($core_updates) && !empty($core_updates)) {
        $latest = $core_updates[0];
        if (isset($latest->response) && $latest->response === 'upgrade') {
            $core_update_available = true;
            $core_new_version = $latest->current;
        }
    }

    // Plugins
    $plugin_updates = get_plugin_updates();
    $all_plugins = get_plugins();
    $plugins_out = [];

    foreach ($all_plugins as $file => $data) {
        $has_update = isset($plugin_updates[$file]);
        $plugins_out[] = [
            'name'               => $data['Name'],
            'file'               => $file,
            'current_version'    => $data['Version'],
            'new_version'        => $has_update ? $plugin_updates[$file]->update->new_version : null,
            'update_available'   => $has_update,
            'active'             => is_plugin_active($file),
            // Resultado de la última vez que se intentó actualizar ESTE
            // plugin desde esta API (null si nunca se ha intentado). Si
            // "reverted" es true, esa actualización rompió el sitio y se
            // revirtió automáticamente — conviene revisarla antes de
            // reintentarla.
            'last_remote_update' => seoxan_get_update_history_for('plugin', $file),
        ];
    }

    // Temas
    $theme_updates = get_site_transient('update_themes');
    $all_themes = wp_get_themes();
    $themes_out = [];

    foreach ($all_themes as $stylesheet => $theme) {
        $has_update = isset($theme_updates->response[$stylesheet]);
        $themes_out[] = [
            'name'               => $theme->get('Name'),
            'stylesheet'         => $stylesheet,
            'current_version'    => $theme->get('Version'),
            'new_version'        => $has_update ? $theme_updates->response[$stylesheet]['new_version'] : null,
            'update_available'   => $has_update,
            'last_remote_update' => seoxan_get_update_history_for('theme', $stylesheet),
        ];
    }

    return new WP_REST_Response([
        'site'        => home_url(),
        'wp_version'  => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'core'        => [
            'update_available'   => $core_update_available,
            'new_version'        => $core_new_version,
            'last_remote_update' => seoxan_get_update_history_for('core', 'core'),
        ],
        'plugins'     => $plugins_out,
        'themes'      => $themes_out,
        'checked_at'  => current_time('mysql'),
    ], 200);
}

function seoxan_api_health_endpoint(WP_REST_Request $request)
{
    return new WP_REST_Response(['status' => 'ok', 'plugin' => 'seoxan-wp-status'], 200);
}

/**
 * Envuelve la ejecución de una actualización con el candado anti-solape y
 * el formateo de respuesta común a los tres endpoints de actualización.
 */
function seoxan_api_run_update_endpoint(callable $runner)
{
    if (seoxan_api_update_locked()) {
        return new WP_REST_Response([
            'success' => false,
            'error'   => 'Ya hay otra actualización en curso en este sitio. Inténtalo de nuevo en unos minutos.',
        ], 409);
    }

    seoxan_api_lock_update();
    $result = $runner();
    seoxan_api_unlock_update();

    if (is_wp_error($result)) {
        // seoxan_run_update_shielded() puede adjuntar datos extra al error
        // (p.ej. reverted/attempted_version/restored_version cuando se ha
        // revertido automáticamente una actualización que rompió el sitio,
        // o manual_backup_path si ni siquiera se pudo revertir) y un status
        // HTTP más específico que el 500 genérico.
        $data = $result->get_error_data();
        $status = (is_array($data) && isset($data['status'])) ? $data['status'] : 500;
        $body = ['success' => false, 'error' => $result->get_error_message()];
        if (is_array($data)) {
            unset($data['status']);
            $body = array_merge($body, $data);
        }
        return new WP_REST_Response($body, $status);
    }

    return new WP_REST_Response(array_merge(['success' => true], $result), 200);
}

/**
 * POST /update-plugin — actualiza un plugin ya instalado a su última versión.
 */
function seoxan_api_update_plugin_endpoint(WP_REST_Request $request)
{
    $plugin = sanitize_text_field($request->get_param('plugin'));

    return seoxan_api_run_update_endpoint(function () use ($plugin) {
        return seoxan_run_plugin_update($plugin);
    });
}

/**
 * POST /update-theme — actualiza un tema ya instalado a su última versión.
 */
function seoxan_api_update_theme_endpoint(WP_REST_Request $request)
{
    $theme = sanitize_text_field($request->get_param('theme'));

    return seoxan_api_run_update_endpoint(function () use ($theme) {
        return seoxan_run_theme_update($theme);
    });
}

/**
 * POST /update-core — actualiza el núcleo a la versión que WordPress ya
 * tiene detectada como disponible.
 */
function seoxan_api_update_core_endpoint(WP_REST_Request $request)
{
    return seoxan_api_run_update_endpoint('seoxan_run_core_update');
}

/**
 * GET /last-update — resultado (éxito/fallo) de la última actualización
 * lanzada por API, sea cual sea (plugin, tema o core).
 */
function seoxan_api_last_update_endpoint(WP_REST_Request $request)
{
    $last_update = seoxan_get_last_update_result();
    // Si hay una actualización en curso ahora mismo (candado activo), el
    // cliente lo necesita para no confundir "todavía no hay respuesta" con
    // "no hay ningún registro" o con un registro desactualizado de un
    // intento anterior. Especialmente relevante en /update-core, que puede
    // tardar más que el timeout típico de un proxy/cliente HTTP.
    $locked = seoxan_api_update_locked();

    if (!$last_update) {
        return new WP_REST_Response(['found' => false, 'locked' => $locked], 200);
    }

    return new WP_REST_Response(array_merge(['found' => true, 'locked' => $locked], $last_update), 200);
}
