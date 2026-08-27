<?php
if (!defined('ABSPATH')) exit;

/**
 * Página dedicada a la API remota: gestión de la clave y documentación
 * de uso para quien vaya a configurar el servicio externo.
 */
function seoxan_api_settings_page()
{
    // Acciones del panel: generar / revocar API Key
    $new_api_key = null;

    if (isset($_POST['seoxan_api_nonce']) && check_admin_referer('seoxan_api_key_action', 'seoxan_api_nonce')) {
        if (isset($_POST['seoxan_generate_key'])) {
            $new_api_key = seoxan_generate_api_key();
        } elseif (isset($_POST['seoxan_revoke_key'])) {
            seoxan_revoke_api_key();
        }
    }

    $api_meta = seoxan_get_api_key_meta();
    $updates_url = rest_url('seoxan-status/v1/updates');
    $update_plugin_url = rest_url('seoxan-status/v1/update-plugin');
    $update_theme_url = rest_url('seoxan-status/v1/update-theme');
    $update_core_url = rest_url('seoxan-status/v1/update-core');
    $last_update_url = rest_url('seoxan-status/v1/last-update');
    $last_update = seoxan_get_last_update_result();
?>
    <div class="wrap seoxan-status">
        <h1>🔑 API Remota</h1>
        <p class="description">
            Expón el estado de actualizaciones de este WordPress (núcleo, plugins y temas) a un servicio
            externo, de forma autenticada, y permite disparar esas mismas actualizaciones de forma remota
            (por ejemplo desde un panel central de control). Genera una API Key aquí y compártela solo con
            ese servicio.
        </p>

        <div class="seoxan-api-box">

            <?php if ($new_api_key): ?>
                <div class="seoxan-api-key-reveal">
                    <p><strong>Nueva API Key generada.</strong> Cópiala ahora: por seguridad no volverá a mostrarse.</p>
                    <code><?= esc_html($new_api_key) ?></code>
                </div>
            <?php endif; ?>

            <h2>Estado de la clave</h2>

            <?php if ($api_meta): ?>
                <table class="widefat" style="max-width:600px;">
                    <tr>
                        <th>Estado</th>
                        <td><?= seoxan_status_color('ok') ?> Activa</td>
                    </tr>
                    <tr>
                        <th>Clave</th>
                        <td><code><?= esc_html($api_meta['preview']) ?></code></td>
                    </tr>
                    <tr>
                        <th>Creada</th>
                        <td><?= esc_html(date_i18n('d/m/Y H:i', $api_meta['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <th>Último uso</th>
                        <td><?= $api_meta['last_used'] ? esc_html(date_i18n('d/m/Y H:i', $api_meta['last_used'])) : 'Nunca' ?></td>
                    </tr>
                </table>
            <?php else: ?>
                <p><?= seoxan_status_color('warn') ?> No hay ninguna API Key generada todavía. El endpoint remoto no responderá hasta que generes una.</p>
            <?php endif; ?>

            <?php if ($last_update): ?>
                <h3 style="margin-top:20px;">Última actualización remota</h3>
                <table class="widefat" style="max-width:600px;">
                    <tr>
                        <th>Resultado</th>
                        <td><?= seoxan_status_color($last_update['success'] ? 'ok' : 'bad') ?> <?= $last_update['success'] ? 'Correcta' : 'Fallida' ?></td>
                    </tr>
                    <tr>
                        <th>Tipo</th>
                        <td><?= esc_html(ucfirst($last_update['type'])) ?></td>
                    </tr>
                    <tr>
                        <th>Objetivo</th>
                        <td><code><?= esc_html($last_update['target']) ?></code></td>
                    </tr>
                    <tr>
                        <th>Mensaje</th>
                        <td><?= esc_html($last_update['message']) ?></td>
                    </tr>
                    <tr>
                        <th>Fecha</th>
                        <td><?= esc_html($last_update['time']) ?></td>
                    </tr>
                </table>
            <?php endif; ?>

            <form method="post" style="margin-top:15px;">
                <?php wp_nonce_field('seoxan_api_key_action', 'seoxan_api_nonce'); ?>
                <button type="submit" name="seoxan_generate_key" class="button button-primary"
                    onclick="return confirm('¿Generar una nueva API Key? Si ya había una, dejará de funcionar de inmediato.');">
                    <?= $api_meta ? 'Regenerar API Key' : 'Generar API Key' ?>
                </button>
                <?php if ($api_meta): ?>
                    <button type="submit" name="seoxan_revoke_key" class="button"
                        onclick="return confirm('¿Revocar la API Key? El servicio remoto perderá el acceso de inmediato.');">
                        Revocar API Key
                    </button>
                <?php endif; ?>
            </form>
        </div>

        <h2>📖 Cómo consumir la API</h2>

        <div class="seoxan-api-box">
            <p><strong>Endpoint:</strong> <code><?= esc_url($updates_url) ?></code></p>
            <p><strong>Método:</strong> <code>GET</code></p>
            <p><strong>Autenticación</strong> (una de las dos, a elegir):</p>
            <ul style="list-style:disc; margin-left:20px;">
                <li><code>X-Seoxan-Api-Key: TU_CLAVE</code></li>
                <li><code>Authorization: Bearer TU_CLAVE</code></li>
            </ul>
            <p>
                Añade <code>?refresh=1</code> a la URL para forzar una comprobación de actualizaciones
                al momento contra WordPress.org, en vez de usar la última comprobación guardada por WordPress
                (limitado a 1 vez cada 5 minutos para no saturar el servicio).
            </p>

            <h3>Ejemplo de petición (curl)</h3>
            <textarea readonly style="width:100%;height:70px;font-family:monospace;">curl -H "X-Seoxan-Api-Key: TU_CLAVE" "<?= esc_url($updates_url) ?>"</textarea>

            <h3>Ejemplo de respuesta</h3>
            <textarea readonly style="width:100%;height:220px;font-family:monospace;">{
  "site": "https://tuweb.com",
  "wp_version": "6.6.2",
  "php_version": "8.2.10",
  "core": { "update_available": false, "new_version": null },
  "plugins": [
    {
      "name": "WooCommerce",
      "file": "woocommerce/woocommerce.php",
      "current_version": "9.1.0",
      "new_version": "9.2.0",
      "update_available": true,
      "active": true
    }
  ],
  "themes": [
    {
      "name": "Storefront",
      "stylesheet": "storefront",
      "current_version": "4.5.0",
      "new_version": null,
      "update_available": false
    }
  ],
  "checked_at": "2026-08-27 10:00:00"
}</textarea>
        </div>

        <h2>🔄 Disparar actualizaciones remotas</h2>

        <div class="seoxan-api-box">
            <p>
                Estos tres endpoints ejecutan la actualización real (misma acción que "Actualizar ahora" en el
                escritorio de WordPress). Solo actualizan software <strong>ya instalado</strong> en este sitio:
                nunca instalan un plugin o tema nuevo. Usa primero <code>GET /updates</code> para saber qué
                <code>plugin</code>/<code>theme</code> actualizar.
            </p>
            <p>Autenticación: igual que el endpoint anterior (cabecera <code>X-Seoxan-Api-Key</code> o <code>Authorization: Bearer</code>).</p>

            <h3>Actualizar un plugin</h3>
            <p><code>POST <?= esc_url($update_plugin_url) ?></code></p>
            <textarea readonly style="width:100%;height:60px;font-family:monospace;">curl -X POST -H "X-Seoxan-Api-Key: TU_CLAVE" -H "Content-Type: application/json" \
  -d '{"plugin":"akismet/akismet.php"}' "<?= esc_url($update_plugin_url) ?>"</textarea>

            <h3>Actualizar un tema</h3>
            <p><code>POST <?= esc_url($update_theme_url) ?></code></p>
            <textarea readonly style="width:100%;height:60px;font-family:monospace;">curl -X POST -H "X-Seoxan-Api-Key: TU_CLAVE" -H "Content-Type: application/json" \
  -d '{"theme":"storefront"}' "<?= esc_url($update_theme_url) ?>"</textarea>

            <h3>Actualizar el núcleo</h3>
            <p><code>POST <?= esc_url($update_core_url) ?></code> — sin cuerpo; siempre usa la actualización de núcleo que WordPress ya tiene detectada.</p>
            <textarea readonly style="width:100%;height:50px;font-family:monospace;">curl -X POST -H "X-Seoxan-Api-Key: TU_CLAVE" "<?= esc_url($update_core_url) ?>"</textarea>

            <h3>Respuesta</h3>
            <p>
                <code>200</code> con <code>{"success": true, "new_version": "..."}</code> si va bien;
                <code>500</code> con <code>{"success": false, "error": "..."}</code> si falla la actualización;
                <code>409</code> si ya hay otra actualización en curso en el sitio, o si la actualización
                <strong>rompió el sitio y se ha revertido automáticamente</strong> (ver siguiente sección) — en
                ese caso el cuerpo incluye además <code>"reverted": true</code>, <code>"attempted_version"</code>
                y <code>"restored_version"</code>.
            </p>

            <h3>Comprobar el resultado después</h3>
            <p>
                La respuesta del <code>POST</code> ya trae el resultado, pero si la conexión se corta antes de
                recibirla (por ejemplo en una actualización de núcleo, que puede tardar más que el timeout típico
                de un proxy o cliente HTTP) puedes volver a consultarlo en cualquier momento con:
            </p>
            <p><code>GET <?= esc_url($last_update_url) ?></code></p>
            <textarea readonly style="width:100%;height:60px;font-family:monospace;">curl -H "X-Seoxan-Api-Key: TU_CLAVE" "<?= esc_url($last_update_url) ?>"</textarea>
            <p>
                Devuelve el resultado de la última actualización lanzada por API (sea plugin, tema o núcleo), con
                el mismo formato que se ve arriba en "Última actualización remota", más un campo <code>locked</code>
                que indica si hay una actualización en curso ahora mismo en este sitio. Si tu <code>POST</code> se
                queda sin respuesta (timeout), consulta este endpoint para distinguir tres situaciones:
            </p>
            <ul style="list-style:disc; margin-left:20px;">
                <li><code>locked: true</code> → la actualización sigue en marcha, vuelve a consultar en unos segundos.</li>
                <li><code>locked: false</code> y <code>time</code> posterior al momento en que lanzaste el <code>POST</code> → ya terminó; comprueba <code>success</code> para saber el resultado.</li>
                <li><code>locked: false</code> y <code>time</code> anterior (o <code>found: false</code>) → tu petición no llegó a completarse en el servidor; es seguro reintentar el <code>POST</code>.</li>
            </ul>
            <p>Si aún no se ha lanzado ninguna actualización, responde <code>{"found": false, "locked": false}</code>.</p>
        </div>

        <h2>🛡️ Backup y reversión automática</h2>

        <div class="seoxan-api-box">
            <p>
                Antes de tocar nada, cada actualización hace su propia copia de seguridad del plugin/tema/núcleo
                actual. Después de aplicar el cambio, sondea la portada del sitio para comprobar que sigue
                respondiendo con normalidad. Si detecta un error fatal, <strong>restaura automáticamente la
                copia anterior</strong> — la actualización queda revertida, no aplicada — y lo deja registrado
                con detalle: qué versión se intentó (<code>attempted_version</code>) y a qué versión se volvió
                (<code>restored_version</code>). Este resultado se ve tanto en la respuesta del <code>POST</code>
                como en <code>GET /last-update</code> y, por elemento, en el campo <code>last_remote_update</code>
                de cada plugin/tema dentro de <code>GET /updates</code> — así puedes saber, de un vistazo, qué
                plugin ha roto el sitio la última vez que se intentó actualizar.
            </p>
            <p>
                Esto añade un poco de latencia a cada <code>POST</code> (dos sondeos a la portada, antes y
                después, con reintentos) — normalmente unos segundos, más en <code>update-core</code>. Si la
                portada tiene una caché de página completa por delante, podría enmascarar un fatal real; se
                añaden parámetro y cabeceras "no-cache" como mejor esfuerzo, pero no hay garantía frente a toda
                configuración de caché.
            </p>
            <p>
                Si el sitio ya estaba roto <em>antes</em> de la actualización (por algo ajeno a ella), se omite
                el backup y la verificación — revertir no tendría sentido, y el resultado lo indica igualmente.
                Y si, en el peor de los casos, la reversión automática también fallase, la copia de seguridad
                <strong>no se borra</strong>: la respuesta incluye su ruta (<code>manual_backup_path</code>) para
                recuperarla a mano.
            </p>
            <p>
                <strong>Sobre la acumulación de copias:</strong> estas copias son siempre temporales — se borran
                en cuanto se confirma que ya no hacen falta (actualización correcta, o revertida con éxito), en
                la misma petición. Como red de seguridad adicional (por si el proceso muriera de una forma que ni
                siquiera esto pudiera limpiar — sin memoria, proceso matado en seco...), un cron diario barre
                <code>wp-content/seoxan-status-backups/</code> y elimina cualquier copia de más de 24h; las que
                se conservaron a propósito tras un fallo de reversión se dan 30 días de margen antes de
                eliminarse también. En condiciones normales, esta carpeta debería estar vacía la mayor parte del
                tiempo.
            </p>
        </div>

        <h2>Seguridad</h2>

        <div class="seoxan-api-box">
            <ul style="list-style:disc; margin-left:20px;">
                <li>La clave solo se muestra en texto plano una vez, al generarla; WordPress únicamente guarda su hash.</li>
                <li>5 intentos fallidos desde la misma IP bloquean el acceso durante 15 minutos.</li>
                <li>Regenerar la clave invalida la anterior de inmediato.</li>
                <li>Usa siempre HTTPS para llamar al endpoint: la clave viaja en la cabecera de la petición.</li>
                <li><strong>Quien tenga esta clave puede actualizar núcleo, plugins y temas de este sitio.</strong>
                    Trátala como una credencial de administrador y no la compartas fuera de tu panel central de control.</li>
                <li>Las actualizaciones requieren que WordPress pueda escribir directamente en el disco
                    (método de sistema de archivos "direct"); si el hosting exige credenciales FTP/SSH para
                    actualizar desde el escritorio, estos endpoints fallarán igual (también lo necesita el
                    backup previo a cada actualización).</li>
                <li><code>wp-content/seoxan-status-backups/</code> contiene temporalmente el código del
                    plugin/tema/núcleo antes de actualizar — no credenciales ni datos de usuarios, solo código
                    ya público. Se borra sola en cuanto termina cada actualización (ver sección anterior).</li>
            </ul>
        </div>
    </div>
<?php
}
