<?php
if (!defined('ABSPATH')) exit;

function seoxan_generate_cleanup_sql()
{
    global $wpdb;
    $sql = [];

    // 1. Limpiar transients caducados
    $sql[] = "-- Eliminar transients caducados";
    $sql[] = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP();";
    $sql[] = "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' AND option_name NOT LIKE '_transient_timeout_%';";

    // 2. Limpiar sesiones de WooCommerce antiguas (+48 horas)
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_sessions'")) {
        $sql[] = "-- Limpiar sesiones de WooCommerce con más de 48h";
        $sql[] = "DELETE FROM {$wpdb->prefix}wc_sessions WHERE timestamp < (UNIX_TIMESTAMP() - 172800);";
    }

    // 3. Detectar autoload excesivo (>1 MB)
    $autoload_size = $wpdb->get_var("
        SELECT SUM(LENGTH(option_value)) 
        FROM {$wpdb->options} 
        WHERE autoload = 'yes'
    ");

    if ($autoload_size > 1000000) {
        $sql[] = "-- Autoload supera 1MB. Revisa plugins problemáticos:";
        $autoload_top = $wpdb->get_results("
            SELECT option_name, LENGTH(option_value) AS size
            FROM {$wpdb->options}
            WHERE autoload = 'yes'
            ORDER BY size DESC
            LIMIT 20
        ");

        foreach ($autoload_top as $row) {
            $sql[] = "-- Sugerido para revisar: {$row->option_name} ({$row->size} bytes)";
            $sql[] = "UPDATE {$wpdb->options} SET autoload='no' WHERE option_name='{$row->option_name}';";
        }
    }

    // 4. Limpiar opciones huérfanas de plugins ya borrados
    $sql[] = "-- Opciones que empiezan por 'plugin_' y no existen plugins activos";
    $sql[] = "/* Edita esto según tus plugins reales antes de ejecutar */";
    $sql[] = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'plugin_%';";
    $sql[] = "-- DELETE FROM {$wpdb->options} WHERE option_name='valor_detectado';";

    return implode("\n", $sql);
}
