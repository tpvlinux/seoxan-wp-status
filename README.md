# Seoxan WP Status

Plugin de WordPress para diagnóstico (autoload, transients, sesiones de WooCommerce, hit rate de Redis, tamaño de tablas) y para exponer/gestionar de forma remota, vía API con clave propia, el estado y las actualizaciones de núcleo/plugins/temas de un sitio — pensado para integrarse con un panel central de control.

Uso interno de SeoXan Tech.

## Instalación

Copiar esta carpeta a `wp-content/plugins/seoxan-wp-status/` y activar el plugin desde el escritorio de WordPress.

## Actualizaciones del propio plugin

Este plugin comprueba nuevas versiones contra las releases de este mismo repositorio (ver [self-update.php](self-update.php)). El repositorio es público, así que esto es automático en cualquier sitio donde esté instalado — no hace falta configurar nada. En cuanto se publica una release con un número de versión superior, aparece en **Plugins** / **Escritorio → Actualizaciones** como cualquier otro plugin.

## Publicar una versión nueva

1. Subir el número de `Version:` en la cabecera de [seoxan-wp-status.php](seoxan-wp-status.php).
2. Commit y push.
3. Crear una release/tag en GitHub con ese mismo número (`gh release create vX.Y.Z --generate-notes`, o desde la web).

No hace falta build ni adjuntar ningún `.zip` a mano — Plugin Update Checker usa el zip que genera GitHub automáticamente para esa release.

## Documentación

Ver [CLAUDE.md](CLAUDE.md) para la arquitectura completa del plugin.
