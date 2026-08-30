<?php
if (!defined('ABSPATH')) exit;

/**
 * Capa de ejecución de actualizaciones remotas (plugin / tema / núcleo).
 *
 * Se apoya en las clases de actualización nativas de WordPress
 * (Plugin_Upgrader / Theme_Upgrader / Core_Upgrader) con un skin silencioso
 * (Automatic_Upgrader_Skin, el mismo que usan las actualizaciones
 * automáticas en segundo plano de WordPress), ya que aquí no hay ninguna
 * pantalla de admin donde volcar el HTML de progreso habitual.
 *
 * Solo actualiza software ya instalado: nunca instala plugins/temas nuevos
 * ni acepta rutas o slugs arbitrarios que no existan ya en el sitio.
 *
 * Blindaje frente a fatales durante la actualización
 * ----------------------------------------------------
 * WordPress ya sustituye los ficheros del plugin/tema/core ANTES de que
 * termine de ejecutarse Plugin_Upgrader::upgrade() / Theme_Upgrader::upgrade()
 * / Core_Upgrader::upgrade() — esos métodos, tras copiar los ficheros,
 * disparan el hook `upgrader_process_complete`, entre otros. Si algún otro
 * plugin activo (típicamente uno de caché) engancha ahí una limpieza que
 * falla (llama a wp_die() o lanza un error/excepción sin capturar),
 * WordPress interpreta que la petición REST ha muerto y responde con su
 * propio JSON de error genérico, que no tiene el formato {success, ...}
 * documentado — y como esto pasa DESPUÉS de que el fichero ya se cambiara,
 * la actualización queda aplicada pero invisible: ni la respuesta ni
 * GET /last-update la reflejan. seoxan_run_update_shielded() evita esto
 * convirtiendo los wp_die() en excepciones capturables y con un shutdown
 * handler de último recurso.
 *
 * Backup y reversión automática
 * ------------------------------
 * Desde WordPress 6.3, WP_Upgrader::install_package() ya hace un backup
 * temporal antes de instalar (en wp-content/upgrade-temp-backup/) — pero lo
 * borra en cuanto la instalación termina con éxito. Eso protege frente a
 * "el paquete llegó corrupto", pero no frente a "el código nuevo se copió
 * perfectamente pero rompe el sitio al cargar" (bug, incompatibilidad...).
 * Para cubrir ese caso, seoxan_run_update_shielded() hace SU PROPIO backup
 * antes de tocar nada (en wp-content/seoxan-status-backups/) y, si tras
 * actualizar el sitio deja de responder con normalidad, restaura ese
 * backup automáticamente — dejando constancia de qué versión rompió el
 * sitio y a qué versión se volvió.
 */

function seoxan_load_upgrader_dependencies()
{
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/theme.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
}

/**
 * Registra el resultado de una actualización remota (éxito, fallo o
 * reversión automática) en dos sitios: un registro "último" de conveniencia
 * (seoxan_status_last_update, lo que expone GET /last-update) y un
 * histórico por elemento (seoxan_status_update_history, indexado por
 * "tipo:objetivo") para poder mostrar, junto a CADA plugin/tema/core en
 * GET /updates, si la última vez que se intentó actualizarlo rompió el
 * sitio. Se llama SIEMPRE en cuanto se conoce el resultado real, antes de
 * intentar construir la respuesta HTTP.
 */
function seoxan_log_update_result($type, $target, $success, $message, $extra = [])
{
    $entry = array_merge([
        'type'      => $type,
        'target'    => $target,
        'success'   => (bool) $success,
        'reverted'  => false,
        'message'   => $message,
        'time'      => current_time('mysql'),
    ], $extra);

    update_option('seoxan_status_last_update', $entry, false);

    $history = get_option('seoxan_status_update_history', []);
    if (!is_array($history)) $history = [];
    $history[$type . ':' . $target] = $entry;
    // Evita que el histórico crezca sin límite en sitios con muchos plugins.
    if (count($history) > 200) {
        $history = array_slice($history, -200, null, true);
    }
    update_option('seoxan_status_update_history', $history, false);
}

function seoxan_get_last_update_result()
{
    return get_option('seoxan_status_last_update', false);
}

/**
 * Resultado de la última actualización remota intentada para un elemento
 * concreto (plugin/tema: su clave habitual; núcleo: 'core'). Pensado para
 * anexarse al listado de GET /updates, junto a cada plugin/tema.
 */
function seoxan_get_update_history_for($type, $target)
{
    $history = get_option('seoxan_status_update_history', []);
    return (is_array($history) && isset($history[$type . ':' . $target])) ? $history[$type . ':' . $target] : null;
}

/**
 * Candado simple para evitar que dos actualizaciones se ejecuten a la vez
 * (por ejemplo, dos llamadas API solapadas), lo que podría dejar archivos
 * a medio escribir.
 */
function seoxan_api_update_locked()
{
    return (bool) get_transient('seoxan_api_update_lock');
}

function seoxan_api_lock_update()
{
    set_transient('seoxan_api_update_lock', 1, 3 * MINUTE_IN_SECONDS);
}

function seoxan_api_unlock_update()
{
    delete_transient('seoxan_api_update_lock');
}

/**
 * Relee la versión de núcleo realmente instalada en disco ahora mismo, sin
 * fiarse de la variable global $wp_version (que ya se cargó al arrancar
 * este request y no se actualiza sola aunque el núcleo se acabe de
 * sustituir dentro del mismo request).
 */
function seoxan_get_installed_core_version()
{
    global $wp_version;

    $version_file = ABSPATH . 'wp-includes/version.php';
    if (file_exists($version_file)) {
        $contents = file_get_contents($version_file);
        if ($contents && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $m)) {
            return $m[1];
        }
    }

    return $wp_version;
}

/* -------------------------------------------------------------------- */
/* Backup y restauración                                                 */
/* -------------------------------------------------------------------- */

function seoxan_backups_root_dir()
{
    return WP_CONTENT_DIR . '/seoxan-status-backups';
}

function seoxan_ensure_backups_dir()
{
    $root = seoxan_backups_root_dir();
    if (!file_exists($root)) {
        wp_mkdir_p($root);
    }
    $index = $root . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silencio, es lo que hay.\n");
    }
    return $root;
}

/**
 * Ruta de origen (carpeta) de un plugin/tema instalado. Para un plugin de
 * un solo fichero (sin carpeta propia) devuelve null a propósito: ese caso
 * se trata aparte porque es un fichero, no una carpeta.
 */
function seoxan_extension_source_dir($type, $target)
{
    if ($type === 'plugin') {
        $dir = dirname($target);
        return $dir === '.' ? null : WP_PLUGIN_DIR . '/' . $dir;
    }
    if ($type === 'theme') {
        return get_theme_root($target) . '/' . $target;
    }
    return null;
}

/**
 * Ficheros y carpetas de núcleo que WordPress sustituye al actualizar
 * (todo excepto wp-content/ y la configuración propia del sitio).
 */
function seoxan_core_backup_items()
{
    $items = ['wp-admin', 'wp-includes'];

    $loose_files = [
        'index.php', 'wp-activate.php', 'wp-blog-header.php', 'wp-comments-post.php',
        'wp-cron.php', 'wp-links-opml.php', 'wp-load.php', 'wp-login.php',
        'wp-mail.php', 'wp-settings.php', 'wp-signup.php', 'wp-trackback.php',
        'xmlrpc.php', 'wp-config-sample.php', 'readme.html', 'license.txt',
    ];
    foreach ($loose_files as $file) {
        if (file_exists(ABSPATH . $file)) {
            $items[] = $file;
        }
    }

    return $items;
}

function seoxan_backup_core_files($backup_path)
{
    wp_mkdir_p($backup_path);

    foreach (seoxan_core_backup_items() as $item) {
        $source = ABSPATH . $item;
        $target = $backup_path . '/' . $item;

        if (is_dir($source)) {
            wp_mkdir_p($target);
            $result = copy_dir($source, $target);
            if (is_wp_error($result)) return $result;
        } elseif (file_exists($source)) {
            if (!@copy($source, $target)) {
                return new WP_Error('seoxan_backup_failed', 'No se pudo copiar ' . $item . ' antes de actualizar el núcleo.');
            }
        }
    }

    return true;
}

function seoxan_restore_core_files($backup_path)
{
    global $wp_filesystem;

    foreach (seoxan_core_backup_items() as $item) {
        $backup_item = $backup_path . '/' . $item;
        $destination = ABSPATH . $item;

        if (!file_exists($backup_item)) continue; // no llegó a respaldarse (no existía)

        if (is_dir($backup_item)) {
            if ($wp_filesystem->exists($destination)) {
                $wp_filesystem->delete($destination, true);
            }
            wp_mkdir_p($destination);
            $result = copy_dir($backup_item, $destination);
            if (is_wp_error($result)) return $result;
        } elseif (!@copy($backup_item, $destination)) {
            return new WP_Error('seoxan_restore_failed', 'No se pudo restaurar ' . $item . ' desde la copia de seguridad del núcleo.');
        }
    }

    return true;
}

/**
 * Copia el plugin/tema/núcleo actual a una carpeta de backup temporal antes
 * de tocar nada. Devuelve la ruta del backup, o un WP_Error si no se pudo
 * hacer la copia — en cuyo caso NO se debe continuar con la actualización,
 * porque no habría forma de revertirla si rompe el sitio.
 */
function seoxan_backup_extension($type, $target)
{
    global $wp_filesystem;

    if (!$wp_filesystem) {
        WP_Filesystem();
    }
    if (!$wp_filesystem) {
        return new WP_Error('seoxan_no_filesystem', 'No se pudo acceder al sistema de archivos en modo directo; no se ha hecho copia de seguridad, así que la actualización no se ha iniciado.');
    }

    $backup_root = seoxan_ensure_backups_dir();
    $backup_path = $backup_root . '/' . $type . '/' . sanitize_file_name(basename($target)) . '-' . time() . '-' . wp_generate_password(6, false, false);

    if ($type === 'core') {
        $result = seoxan_backup_core_files($backup_path);
        return is_wp_error($result) ? $result : $backup_path;
    }

    if ($type === 'plugin' && dirname($target) === '.') {
        // Plugin de un solo fichero, sin carpeta propia.
        $source = WP_PLUGIN_DIR . '/' . $target;
        if (!file_exists($source)) {
            return new WP_Error('seoxan_backup_source_missing', 'No se encontró el fichero del plugin a copiar antes de actualizar.');
        }
        wp_mkdir_p($backup_path);
        if (!@copy($source, $backup_path . '/' . basename($target))) {
            return new WP_Error('seoxan_backup_failed', 'No se pudo copiar el plugin antes de actualizar.');
        }
        return $backup_path;
    }

    $source = seoxan_extension_source_dir($type, $target);
    if (!$source || !$wp_filesystem->exists($source)) {
        return new WP_Error('seoxan_backup_source_missing', 'No se encontró la ruta a copiar antes de actualizar.');
    }

    wp_mkdir_p($backup_path);
    $result = copy_dir($source, $backup_path);

    return is_wp_error($result) ? $result : $backup_path;
}

/**
 * Restaura un backup hecho por seoxan_backup_extension() a su ubicación
 * original, sustituyendo lo que haya ahora mismo.
 */
function seoxan_restore_extension($type, $target, $backup_path)
{
    global $wp_filesystem;

    if (!$wp_filesystem) {
        WP_Filesystem();
    }
    if (!$wp_filesystem) {
        return new WP_Error('seoxan_no_filesystem', 'No se pudo acceder al sistema de archivos en modo directo para restaurar la copia de seguridad.');
    }

    if ($type === 'core') {
        return seoxan_restore_core_files($backup_path);
    }

    if ($type === 'plugin' && dirname($target) === '.') {
        $backup_file = $backup_path . '/' . basename($target);
        if (!file_exists($backup_file)) {
            return new WP_Error('seoxan_restore_missing_backup', 'La copia de seguridad no contiene el fichero esperado.');
        }
        if (!@copy($backup_file, WP_PLUGIN_DIR . '/' . $target)) {
            return new WP_Error('seoxan_restore_failed', 'No se pudo restaurar el plugin desde la copia de seguridad.');
        }
        return true;
    }

    $destination = seoxan_extension_source_dir($type, $target);
    if (!$destination) {
        return new WP_Error('seoxan_restore_bad_target', 'No se pudo determinar la ruta a restaurar.');
    }

    if ($wp_filesystem->exists($destination)) {
        $wp_filesystem->delete($destination, true);
    }
    wp_mkdir_p($destination);

    $result = copy_dir($backup_path, $destination);

    return is_wp_error($result) ? $result : true;
}

function seoxan_delete_backup($backup_path)
{
    global $wp_filesystem;
    if ($backup_path && $wp_filesystem && $wp_filesystem->exists($backup_path)) {
        $wp_filesystem->delete($backup_path, true);
    }
}

/**
 * Rutas de backup que se conservan A PROPÓSITO porque una reversión
 * automática falló (ver manual_backup_path en el histórico) — el
 * limpiador de huérfanos les da más margen antes de borrarlas, en vez de
 * llevarse por delante la única copia de recuperación manual disponible.
 */
function seoxan_get_manual_recovery_backup_paths()
{
    $paths = [];
    $history = get_option('seoxan_status_update_history', []);
    if (is_array($history)) {
        foreach ($history as $entry) {
            if (!empty($entry['manual_backup_path'])) {
                $paths[] = $entry['manual_backup_path'];
            }
        }
    }
    return $paths;
}

/**
 * Limpia backups huérfanos en wp-content/seoxan-status-backups/. En el
 * camino normal, esta carpeta se vacía sola (cada actualización borra su
 * propio backup en cuanto lo confirma innecesario) — esto es solo una red
 * de seguridad para el caso en que el proceso PHP muera de una forma que
 * ni el try/catch ni el shutdown handler puedan atrapar (el proceso
 * eliminado en seco, sin memoria para seguir ejecutando nada más...),
 * dejando un backup atrás sin que nadie lo borrara. Se ejecuta tanto por
 * cron diario como al principio de cada actualización nueva, para que esta
 * carpeta nunca vaya acumulando copias olvidadas en el sitio del usuario.
 *
 * Backups normales: se consideran huérfanos (y se borran) pasadas 24h —
 * tiempo de sobra para que cualquier actualización normal haya terminado.
 * Backups conservados a propósito tras un fallo de reversión se dan más
 * margen (30 días) para una recuperación manual, pero tampoco para siempre.
 */
function seoxan_cleanup_stale_backups()
{
    global $wp_filesystem;

    if (!$wp_filesystem) {
        WP_Filesystem();
    }
    if (!$wp_filesystem) return;

    $root = seoxan_backups_root_dir();
    if (!$wp_filesystem->is_dir($root)) return;

    $kept_paths = seoxan_get_manual_recovery_backup_paths();
    $now = time();

    foreach (['plugin', 'theme', 'core'] as $type) {
        $type_dir = $root . '/' . $type;
        if (!$wp_filesystem->is_dir($type_dir)) continue;

        $entries = $wp_filesystem->dirlist($type_dir);
        if (!$entries) continue;

        foreach ($entries as $name => $info) {
            if (($info['type'] ?? '') !== 'd') continue;

            $path = $type_dir . '/' . $name;

            // La carpeta se llama "{slug}-{timestamp}-{random}"; si no
            // podemos leer el timestamp del nombre, usamos su fecha de
            // modificación como aproximación.
            $created_at = preg_match('/-(\d{10})-[a-zA-Z0-9]+$/', $name, $m)
                ? (int) $m[1]
                : (int) ($info['lastmodunix'] ?? 0);

            $max_age = in_array($path, $kept_paths, true) ? (30 * DAY_IN_SECONDS) : DAY_IN_SECONDS;

            if ($created_at && ($now - $created_at) > $max_age) {
                $wp_filesystem->delete($path, true);
            }
        }
    }
}

/**
 * Programa (si no lo está ya) el cron diario que llama a
 * seoxan_cleanup_stale_backups(). Idempotente: seguro de llamar en cada
 * carga, gracias a la comprobación de wp_next_scheduled().
 */
function seoxan_schedule_backup_cleanup()
{
    if (!wp_next_scheduled('seoxan_status_cleanup_backups_event')) {
        wp_schedule_event(time(), 'daily', 'seoxan_status_cleanup_backups_event');
    }
}
add_action('seoxan_status_cleanup_backups_event', 'seoxan_cleanup_stale_backups');

/* -------------------------------------------------------------------- */
/* Sondeo de salud del sitio                                             */
/* -------------------------------------------------------------------- */

/**
 * Sondea la portada del sitio para saber si responde con normalidad o si
 * hay un error fatal. Reintenta una vez antes de darlo por roto, para no
 * confundir un problema puntual de red con un sitio realmente caído.
 *
 * Limitación conocida: si el sitio tiene una caché de página completa
 * (Varnish, un plugin de caché...) delante, podría servir una copia en
 * caché y enmascarar un fatal real. Se añade un parámetro y cabeceras
 * "no-cache" como mejor esfuerzo, pero no hay garantía frente a toda
 * configuración de caché posible.
 */
function seoxan_probe_site_health($attempts = 2)
{
    $url = add_query_arg('seoxan_probe', time() . '-' . wp_rand(1000, 9999), home_url('/'));

    for ($i = 1; $i <= $attempts; $i++) {
        $response = wp_remote_get($url, [
            'timeout'   => 8,
            'sslverify' => false,
            'headers'   => [
                'Cache-Control' => 'no-cache, no-store',
                'Pragma'        => 'no-cache',
            ],
        ]);

        if (is_wp_error($response)) {
            if ($i < $attempts) {
                usleep(500000);
                continue;
            }
            return ['healthy' => false, 'detail' => 'no se pudo contactar con el sitio (' . $response->get_error_message() . ')'];
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status >= 500) {
            return ['healthy' => false, 'detail' => 'el sitio respondió con un error ' . $status];
        }

        if (seoxan_body_looks_fatal($body)) {
            return ['healthy' => false, 'detail' => 'el sitio muestra una página de error fatal'];
        }

        return ['healthy' => true, 'detail' => 'ok'];
    }

    return ['healthy' => false, 'detail' => 'no se pudo verificar el estado del sitio'];
}

function seoxan_body_looks_fatal($body)
{
    if (!$body) return false;

    $needles = [
        'critical error on this website', // mensaje genérico de WP en producción
        'fatal error',
        'parse error',
        'uncaught error',
        'uncaught exception',
    ];
    $body_lower = strtolower($body);
    foreach ($needles as $needle) {
        if (strpos($body_lower, $needle) !== false) return true;
    }
    return false;
}

/* -------------------------------------------------------------------- */
/* Ejecución blindada                                                    */
/* -------------------------------------------------------------------- */

/**
 * Ejecuta $attempt() blindado frente a errores fatales producidos DURANTE
 * el proceso de actualización, y frente a que el resultado (bueno o malo)
 * rompa el sitio: hace un backup previo y, si tras el cambio el sitio deja
 * de responder con normalidad, restaura automáticamente ese backup.
 *
 * $attempt debe devolver un array (éxito) o un WP_Error (fallo controlado);
 * puede lanzar cualquier Throwable, que aquí se trata como fallo.
 * $get_current_version se usa para comprobar, en cualquier camino, si el
 * cambio se llegó a aplicar de verdad (algunos fatales ocurren después de
 * que WordPress ya haya sustituido los ficheros).
 *
 * @return array|WP_Error
 */
function seoxan_run_update_shielded($type, $target, callable $attempt, callable $get_current_version)
{
    // Aprovecha cada actualización para barrer cualquier backup huérfano de
    // un intento anterior que muriera sin limpiar (ver seoxan_cleanup_stale_backups),
    // sin depender solo del cron diario.
    seoxan_cleanup_stale_backups();

    $version_before = $get_current_version();

    // Si el sitio YA estaba roto antes de tocar nada, un backup/reversión
    // automática no tendría sentido: no sabríamos si esta actualización
    // empeoró algo o si el problema es ajeno a ella, y "revertir" nos
    // dejaría en un estado que ya estaba roto igualmente.
    $pre_health = seoxan_probe_site_health(2);

    $backup_path = null;
    if ($pre_health['healthy']) {
        $backup_path = seoxan_backup_extension($type, $target);
        if (is_wp_error($backup_path)) {
            seoxan_log_update_result($type, $target, false, $backup_path->get_error_message());
            return $backup_path;
        }
    }

    $shielded_done = false;

    // Si algo durante la actualización llama a wp_die() (p.ej. un plugin de
    // caché que aborta al reaccionar a upgrader_process_complete), lo
    // convertimos en una excepción normal y corriente en vez de dejar que
    // WordPress corte la petición con su JSON de error genérico.
    $die_as_exception = function ($message) {
        if (is_wp_error($message)) {
            $message = $message->get_error_message();
        }
        throw new RuntimeException(is_string($message) ? wp_strip_all_tags($message) : 'wp_die() durante la actualización.');
    };
    $die_filter = function () use ($die_as_exception) {
        return $die_as_exception;
    };
    $die_filter_hooks = ['wp_die_handler', 'wp_die_json_handler', 'wp_die_jsonp_handler', 'wp_die_ajax_handler', 'wp_die_xmlrpc_handler', 'wp_die_xml_handler'];
    foreach ($die_filter_hooks as $hook) {
        add_filter($hook, $die_filter, PHP_INT_MAX);
    }

    // Absorbe cualquier salida (HTML del skin, avisos/warnings de terceros)
    // para que nunca contamine la respuesta JSON del endpoint.
    $ob_level = ob_get_level();
    ob_start();

    // Red de seguridad para fatales que ni siquiera try/catch puede atrapar
    // (memoria agotada, timeout de ejecución...). Si hay backup y el
    // fichero llegó a cambiar, intenta revertir aquí mismo.
    register_shutdown_function(function () use (&$shielded_done, $type, $target, $version_before, $get_current_version, $ob_level, $backup_path) {
        if ($shielded_done) return; // el flujo normal ya terminó y registró el resultado

        $error = error_get_last();

        while (ob_get_level() > $ob_level) {
            ob_end_clean();
        }

        $version_after = $get_current_version();
        $changed = $version_after && $version_after !== $version_before;
        $error_detail = $error ? (': ' . $error['message']) : '.';

        $success = false;
        $message = '';

        if ($changed && $backup_path) {
            $restore = seoxan_restore_extension($type, $target, $backup_path);
            if (!is_wp_error($restore)) {
                seoxan_delete_backup($backup_path);
                $message = 'La actualización a ' . $version_after . ' se interrumpió por un error fatal' . $error_detail . ' Se ha revertido automáticamente a la versión anterior (' . $version_before . ').';
                seoxan_log_update_result($type, $target, false, $message, [
                    'reverted'          => true,
                    'attempted_version' => $version_after,
                    'previous_version'  => $version_before,
                ]);
            } else {
                $message = 'La actualización a ' . $version_after . ' se interrumpió por un error fatal' . $error_detail . ' El intento automático de revertir también falló: ' . $restore->get_error_message() . '. Copia de seguridad conservada en: ' . $backup_path;
                seoxan_log_update_result($type, $target, false, $message, [
                    'reverted'           => false,
                    'attempted_version'  => $version_after,
                    'manual_backup_path' => $backup_path,
                ]);
            }
        } elseif ($changed) {
            $success = true;
            $message = 'Actualizado a la versión ' . $version_after . '. El proceso se interrumpió después por un error fatal' . $error_detail . ' El cambio ya se había aplicado (no había copia de seguridad con la que verificar/revertir).';
            seoxan_log_update_result($type, $target, true, $message);
        } else {
            $message = 'La actualización se interrumpió por un error fatal' . $error_detail . ' No se detectó ningún cambio aplicado.';
            seoxan_log_update_result($type, $target, false, $message);
            if ($backup_path) seoxan_delete_backup($backup_path);
        }

        seoxan_api_unlock_update();

        // Mejor esfuerzo: si nadie ha enviado ya cabeceras, devolvemos
        // nosotros mismos un JSON limpio con el formato documentado.
        if (!headers_sent()) {
            status_header($success ? 200 : 500);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            echo wp_json_encode($success
                ? ['success' => true, 'new_version' => $version_after, 'target' => $target]
                : ['success' => false, 'error' => $message]);
        }
    });

    try {
        $result = $attempt();
    } catch (Throwable $e) {
        $result = new WP_Error('seoxan_update_exception', $e->getMessage());
    } finally {
        foreach ($die_filter_hooks as $hook) {
            remove_filter($hook, $die_filter, PHP_INT_MAX);
        }
    }

    while (ob_get_level() > $ob_level) {
        ob_end_clean();
    }

    $version_after = $get_current_version();
    $changed = $version_after && $version_after !== $version_before;

    // Fuente de verdad real, siempre: si la versión instalada no cambió, no
    // ha habido actualización — da igual lo que dijera $attempt(), incluso
    // si no lanzó ningún error. Sin esto, un "éxito" que WordPress reporta
    // sin haber sustituido realmente el fichero (o un fallo interno nuestro
    // no relacionado, como el que motivó este mismo arreglo) se traduciría
    // en un falso success:true de cara al cliente de la API.
    if (!$changed) {
        $message = is_wp_error($result)
            ? $result->get_error_message()
            : 'WordPress no devolvió ningún error, pero la versión instalada sigue siendo la misma (' . $version_before . '); la actualización no llegó a aplicarse.';
        seoxan_log_update_result($type, $target, false, $message);
        if ($backup_path) seoxan_delete_backup($backup_path);
        $shielded_done = true;
        return is_wp_error($result) ? $result : new WP_Error('seoxan_update_not_applied', $message);
    }

    // A partir de aquí el fichero SÍ cambió: éxito normal, o "cambió pese a
    // un error posterior" capturado por el try/catch. Si teníamos backup,
    // comprobamos que el sitio sigue funcionando antes de darlo por bueno.
    if ($backup_path) {
        $post_health = seoxan_probe_site_health(2);

        if (!$post_health['healthy']) {
            $restore = seoxan_restore_extension($type, $target, $backup_path);

            if (is_wp_error($restore)) {
                $message = 'La actualización a ' . $version_after . ' rompió el sitio (' . $post_health['detail'] . ') y el intento automático de revertir a la versión anterior falló: ' . $restore->get_error_message() . '. Copia de seguridad conservada en: ' . $backup_path;
                seoxan_log_update_result($type, $target, false, $message, [
                    'reverted'           => false,
                    'attempted_version'  => $version_after,
                    'manual_backup_path' => $backup_path,
                ]);
                $shielded_done = true;
                return new WP_Error('seoxan_revert_failed', $message, ['status' => 500]);
            }

            seoxan_delete_backup($backup_path);

            $message = 'La actualización a ' . $version_after . ' rompió el sitio (' . $post_health['detail'] . '); se ha revertido automáticamente a la versión anterior (' . $version_before . ').';
            seoxan_log_update_result($type, $target, false, $message, [
                'reverted'          => true,
                'attempted_version' => $version_after,
                'previous_version'  => $version_before,
            ]);
            $shielded_done = true;

            return new WP_Error('seoxan_update_reverted', $message, [
                'status'            => 409,
                'reverted'          => true,
                'attempted_version' => $version_after,
                'restored_version'  => $version_before,
            ]);
        }

        seoxan_delete_backup($backup_path);
    }

    $unprotected_note = (!$backup_path && !$pre_health['healthy'])
        ? ' (el sitio ya mostraba un problema antes de esta actualización — ' . $pre_health['detail'] . ' —, así que no se ha podido verificar automáticamente si esto lo ha empeorado.)'
        : '';

    // Llegados aquí, $changed ya confirmó que la versión instalada cambió
    // de verdad — es un éxito real, tanto si $attempt() no devolvió ningún
    // error como si devolvió uno posterior al cambio (ya reflejado en el
    // mensaje). Se responde siempre con la misma forma, no con lo que
    // devolviera $attempt() (que variaba entre plugin/tema/core) — salvo un
    // posible 'note' (p.ej. avisos de reactivación forzada de un plugin
    // tras la actualización), que si existe se añade al mensaje.
    $note = is_wp_error($result) ? (' (con un aviso posterior: ' . $result->get_error_message() . ')') : '';
    $attempt_note = (!is_wp_error($result) && is_array($result) && !empty($result['note'])) ? (' ' . $result['note']) : '';
    seoxan_log_update_result($type, $target, true, 'Actualizado a la versión ' . $version_after . $note . '.' . $unprotected_note . $attempt_note);
    $shielded_done = true;

    return ['new_version' => $version_after, 'target' => $target];
}

/* -------------------------------------------------------------------- */
/* Actualizaciones concretas                                             */
/* -------------------------------------------------------------------- */

/**
 * Actualiza un plugin ya instalado a su última versión disponible.
 * $plugin_file es la ruta relativa tipo "akismet/akismet.php", la misma
 * clave que devuelve get_plugins().
 *
 * @return array|WP_Error
 */
function seoxan_run_plugin_update($plugin_file)
{
    seoxan_load_upgrader_dependencies();

    $installed = get_plugins();
    if (!isset($installed[$plugin_file])) {
        return new WP_Error('seoxan_plugin_not_found', 'Ese plugin no está instalado en este WordPress.');
    }

    $was_active = is_plugin_active($plugin_file);

    $get_version = function () use ($plugin_file) {
        if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) return null;
        $data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
        return $data['Version'] ?? null;
    };

    $result = seoxan_run_update_shielded('plugin', $plugin_file, function () use ($plugin_file, $was_active) {
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $result = $upgrader->upgrade($plugin_file);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            // Automatic_Upgrader_Skin (a diferencia de WP_Ajax_Upgrader_Skin)
            // no tiene get_errors() — usamos los mensajes de progreso que sí
            // expone como mejor información disponible. La comprobación real
            // de si esto tuvo efecto la hace seoxan_run_update_shielded()
            // comparando la versión instalada antes/después.
            $messages = $skin->get_upgrade_messages();
            return new WP_Error('seoxan_update_failed', $messages
                ? implode(' ', array_map('wp_strip_all_tags', $messages))
                : 'La actualización no se pudo completar (WordPress no dio más detalles).');
        }

        // Plugin_Upgrader::upgrade() SIEMPRE desactiva el plugin antes de
        // sustituir sus ficheros (deactivate_plugin_before_upgrade()), salvo
        // que la petición sea un cron de WordPress — una llamada REST no lo
        // es. Y los filtros que WordPress engancha para ese caso
        // (active_before/active_after) tampoco reactivan nada: solo tocan
        // el modo mantenimiento, y solo cuando sí es cron. Fuera del
        // escritorio de wp-admin (que reactiva aparte, en su propio manejador
        // AJAX), nadie más lo hace — así que lo hacemos nosotros.
        $reactivation_note = '';
        if ($was_active && !is_plugin_active($plugin_file)) {
            // activate_plugin() puede fallar en silencio (devolver un
            // WP_Error, p.ej. si detecta cualquier output inesperado
            // durante el proceso) o directamente lanzar un fatal — ambos
            // casos algo más probables justo cuando el plugin se actualiza
            // a sí mismo dentro de la misma petición que lo está
            // ejecutando. Lo envolvemos para que, pase lo que pase, se
            // llegue igualmente a la comprobación/respaldo de abajo.
            $activation_error = null;
            try {
                $activation = activate_plugin($plugin_file);
                if (is_wp_error($activation)) {
                    $activation_error = $activation->get_error_message();
                }
            } catch (Throwable $e) {
                $activation_error = $e->getMessage();
            }

            // No nos fiamos de que "no dio error" signifique que quedó
            // activo: lo comprobamos de verdad, y si sigue sin estarlo, lo
            // forzamos directamente en la opción — este plugin YA estaba
            // activo y funcionando antes de esta actualización, no hace
            // falta volver a "probarlo" en el sandbox de activate_plugin().
            if (!is_plugin_active($plugin_file)) {
                $active_plugins = (array) get_option('active_plugins', []);
                if (!in_array($plugin_file, $active_plugins, true)) {
                    $active_plugins[] = $plugin_file;
                    sort($active_plugins);
                    update_option('active_plugins', $active_plugins);
                }

                $reactivation_note = $activation_error
                    ? (' (aviso: activate_plugin() falló al reactivarlo — ' . $activation_error . ' — se ha forzado la reactivación directamente.)')
                    : ' (aviso: hubo que forzar la reactivación tras la actualización.)';
            }
        }

        $new_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);

        $attempt_result = [
            'plugin'      => $plugin_file,
            'new_version' => $new_data['Version'],
        ];
        if ($reactivation_note !== '') {
            $attempt_result['note'] = trim($reactivation_note);
        }

        return $attempt_result;
    }, $get_version);

    // Red de seguridad final, para CUALQUIER plugin, independiente del
    // resultado de arriba (éxito, fallo, o lo que sea): Plugin_Upgrader
    // desactiva el plugin en cuanto arranca, antes incluso de saber si la
    // actualización va a salir bien — así que si el intento falla (paquete
    // corrupto, red...) DESPUÉS de esa desactivación pero ANTES de llegar a
    // la reactivación de dentro del intento (que solo se ejecuta en la
    // ruta de éxito), o si un fatal impide llegar siquiera a ese punto, el
    // plugin se queda desactivado sin que nadie se lo pidiera — no es lo
    // que el admin pidió (pidió actualizar, no apagar). Esta comprobación
    // no depende de nada de lo anterior, así que lo arregla en cualquier
    // caso. Especialmente crítico cuando el plugin es este mismo: uno que
    // se desactiva a sí mismo se lleva por delante la propia API que
    // serviría para arreglarlo después.
    if ($was_active && !is_plugin_active($plugin_file)) {
        $active_plugins = (array) get_option('active_plugins', []);
        if (!in_array($plugin_file, $active_plugins, true)) {
            $active_plugins[] = $plugin_file;
            sort($active_plugins);
            update_option('active_plugins', $active_plugins);
        }
    }

    return $result;
}

/**
 * Actualiza un tema ya instalado a su última versión disponible.
 * $stylesheet es el slug de la carpeta del tema.
 *
 * @return array|WP_Error
 */
function seoxan_run_theme_update($stylesheet)
{
    seoxan_load_upgrader_dependencies();

    $theme = wp_get_theme($stylesheet);
    if (!$theme->exists()) {
        return new WP_Error('seoxan_theme_not_found', 'Ese tema no está instalado en este WordPress.');
    }

    $get_version = function () use ($stylesheet) {
        $t = wp_get_theme($stylesheet);
        return $t->exists() ? $t->get('Version') : null;
    };

    return seoxan_run_update_shielded('theme', $stylesheet, function () use ($stylesheet) {
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);

        $result = $upgrader->upgrade($stylesheet);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            $messages = $skin->get_upgrade_messages();
            return new WP_Error('seoxan_update_failed', $messages
                ? implode(' ', array_map('wp_strip_all_tags', $messages))
                : 'La actualización no se pudo completar (WordPress no dio más detalles).');
        }

        $new_theme = wp_get_theme($stylesheet);

        return [
            'theme'       => $stylesheet,
            'new_version' => $new_theme->get('Version'),
        ];
    }, $get_version);
}

/**
 * Actualiza el núcleo de WordPress a la versión que WordPress ya tiene
 * detectada como disponible (la misma que se vería en Escritorio >
 * Actualizaciones). No admite una versión arbitraria.
 *
 * @return array|WP_Error
 */
function seoxan_run_core_update()
{
    seoxan_load_upgrader_dependencies();

    wp_version_check();
    $updates = get_core_updates();

    if (!is_array($updates) || empty($updates) || $updates[0]->response !== 'upgrade') {
        return new WP_Error('seoxan_no_core_update', 'No hay ninguna actualización de núcleo pendiente.');
    }

    $current = $updates[0];

    return seoxan_run_update_shielded('core', 'core', function () use ($current) {
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Core_Upgrader($skin);

        $result = $upgrader->upgrade($current);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($result === false) {
            $messages = $skin->get_upgrade_messages();
            return new WP_Error('seoxan_update_failed', $messages
                ? implode(' ', array_map('wp_strip_all_tags', $messages))
                : 'La actualización no se pudo completar (WordPress no dio más detalles).');
        }

        return ['new_version' => $current->version];
    }, 'seoxan_get_installed_core_version');
}
