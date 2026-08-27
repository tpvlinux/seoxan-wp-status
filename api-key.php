<?php
if (!defined('ABSPATH')) exit;

define('SEOXAN_API_KEY_OPTION', 'seoxan_status_api_key_hash');
define('SEOXAN_API_KEY_META_OPTION', 'seoxan_status_api_key_meta');
define('SEOXAN_API_FAILED_ATTEMPTS_PREFIX', 'seoxan_api_fail_');

/**
 * Genera una nueva API Key y sustituye la anterior (si existía).
 * La clave en texto plano se devuelve UNA sola vez, para mostrarla en
 * pantalla; a partir de ahí solo se conserva su hash SHA-256.
 */
function seoxan_generate_api_key()
{
    $key = 'sxwp_' . bin2hex(random_bytes(32));

    update_option(SEOXAN_API_KEY_OPTION, hash('sha256', $key), false);
    update_option(SEOXAN_API_KEY_META_OPTION, [
        'created_at' => time(),
        'last_used'  => null,
        'preview'    => substr($key, 0, 9) . '…' . substr($key, -4),
    ], false);

    return $key;
}

function seoxan_revoke_api_key()
{
    delete_option(SEOXAN_API_KEY_OPTION);
    delete_option(SEOXAN_API_KEY_META_OPTION);
}

function seoxan_has_api_key()
{
    return (bool) get_option(SEOXAN_API_KEY_OPTION, false);
}

function seoxan_get_api_key_meta()
{
    return get_option(SEOXAN_API_KEY_META_OPTION, false);
}

/**
 * Compara la clave recibida con el hash almacenado, con una comparación
 * segura frente a timing attacks.
 */
function seoxan_verify_api_key($provided_key)
{
    $stored_hash = get_option(SEOXAN_API_KEY_OPTION, '');
    if (!$stored_hash || !$provided_key) return false;

    return hash_equals($stored_hash, hash('sha256', $provided_key));
}

function seoxan_touch_api_key_last_used()
{
    $meta = seoxan_get_api_key_meta();
    if (!$meta) return;
    $meta['last_used'] = time();
    update_option(SEOXAN_API_KEY_META_OPTION, $meta, false);
}

/**
 * Protección básica contra fuerza bruta: bloquea una IP tras 5 intentos
 * fallidos de autenticación durante 15 minutos.
 */
function seoxan_api_ip_is_locked($ip)
{
    return (int) get_transient(SEOXAN_API_FAILED_ATTEMPTS_PREFIX . md5($ip)) >= 5;
}

function seoxan_api_register_failed_attempt($ip)
{
    $key = SEOXAN_API_FAILED_ATTEMPTS_PREFIX . md5($ip);
    $count = (int) get_transient($key);
    set_transient($key, $count + 1, 15 * MINUTE_IN_SECONDS);
}

function seoxan_api_clear_failed_attempts($ip)
{
    delete_transient(SEOXAN_API_FAILED_ATTEMPTS_PREFIX . md5($ip));
}
