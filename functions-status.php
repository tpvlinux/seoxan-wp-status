<?php
if (!defined('ABSPATH')) exit;

// Detectar prefijo dinámico
global $wpdb;
$prefix = $wpdb->prefix;

// 1. Tamaño de autoload
function seoxan_get_autoload_size()
{
    global $wpdb;

    return $wpdb->get_var("
        SELECT SUM(LENGTH(option_value)) 
        FROM {$wpdb->options}
        WHERE autoload = 'yes'
    ");
}

// 2. Top 20 autoload
function seoxan_get_autoload_top()
{
    global $wpdb;

    return $wpdb->get_results("
        SELECT option_name, LENGTH(option_value) AS size
        FROM {$wpdb->options}
        WHERE autoload = 'yes'
        ORDER BY size DESC
        LIMIT 20
    ");
}

// 3. Contar transients
function seoxan_get_transients_count()
{
    global $wpdb;

    return $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->options}
        WHERE option_name LIKE '%transient%'
    ");
}

// 4. Sesiones WooCommerce
function seoxan_get_wc_sessions()
{
    global $wpdb;

    $table = $wpdb->prefix . "woocommerce_sessions";
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
        return $wpdb->get_var("SELECT COUNT(*) FROM $table");
    }
    return 0;
}

// 5. Redis info
function seoxan_get_redis_info()
{
    if (!class_exists('Redis')) return false;

    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
        $info = $redis->info('Stats');

        return [
            'hits' => $info['keyspace_hits'] ?? 0,
            'misses' => $info['keyspace_misses'] ?? 0
        ];
    } catch (Exception $e) {
        return false;
    }
}

// 6. Tamaño de tablas
function seoxan_get_table_sizes()
{
    global $wpdb;

    return $wpdb->get_results("
        SHOW TABLE STATUS LIKE '{$wpdb->prefix}%'
    ");
}
