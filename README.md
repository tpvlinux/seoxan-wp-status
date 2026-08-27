# Seoxan WP Status

Plugin de WordPress para diagnóstico (autoload, transients, sesiones de WooCommerce, hit rate de Redis, tamaño de tablas) y para exponer/gestionar de forma remota, vía API con clave propia, el estado y las actualizaciones de núcleo/plugins/temas de un sitio — pensado para integrarse con un panel central de control.

Repositorio privado, uso interno de SeoXan Tech.

## Instalación

Copiar esta carpeta a `wp-content/plugins/seoxan-wp-status/` y activar el plugin desde el escritorio de WordPress.

## Actualizaciones del propio plugin

Este plugin comprueba nuevas versiones contra las releases de este mismo repositorio (ver [self-update.php](self-update.php)). Como el repositorio es privado, cada sitio necesita un token de acceso personal de GitHub (permiso de solo lectura de "Contents" sobre este repo) definido en su `wp-config.php`:

```php
define('SEOXAN_STATUS_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx');
```

Sin ese token, el plugin sigue funcionando con normalidad, pero no detectará versiones nuevas de sí mismo (avisa de esto con un aviso en sus propias páginas de admin).

## Publicar una versión nueva

1. Subir el número de `Version:` en la cabecera de [seoxan-wp-status.php](seoxan-wp-status.php).
2. Commit y push.
3. Crear una release/tag en GitHub con ese mismo número (`gh release create vX.Y.Z --generate-notes`, o desde la web).

No hace falta build ni adjuntar ningún `.zip` a mano — Plugin Update Checker usa el zip que genera GitHub automáticamente para esa release.

## Documentación

Ver [CLAUDE.md](CLAUDE.md) para la arquitectura completa del plugin.
