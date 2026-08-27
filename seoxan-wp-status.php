<?php
/*
Plugin Name: Seoxan WP Status
Plugin URI: https://seoxan.es
Description: Panel profesional de diagnóstico para WordPress y WooCommerce: autoload, transients, sesiones, Redis, tamaño de tablas.
Version: 1.6.1
Author: Alex Rubio / SeoXan Tech
Author URI: https://seoxan.es
*/

if (!defined('ABSPATH')) exit;

define('SEOXAN_STATUS_PATH', plugin_dir_path(__FILE__));
define('SEOXAN_STATUS_URL', plugin_dir_url(__FILE__));

// Cargar archivos
require_once SEOXAN_STATUS_PATH . 'functions-status.php';
require_once SEOXAN_STATUS_PATH . 'admin-page.php';
require_once SEOXAN_STATUS_PATH . 'sql-cleaner.php';
require_once SEOXAN_STATUS_PATH . 'api-key.php';
require_once SEOXAN_STATUS_PATH . 'updates-runner.php';
require_once SEOXAN_STATUS_PATH . 'rest-api.php';
require_once SEOXAN_STATUS_PATH . 'api-page.php';
require_once SEOXAN_STATUS_PATH . 'self-update.php';

// Limpieza diaria de backups temporales huérfanos en
// wp-content/seoxan-status-backups/ (ver seoxan_cleanup_stale_backups()
// en updates-runner.php). Se programa en cada carga (idempotente) para
// cubrir tanto instalaciones nuevas como actualizaciones del propio plugin
// en sitios donde ya estaba activo.
add_action('init', 'seoxan_schedule_backup_cleanup');
register_activation_hook(__FILE__, 'seoxan_schedule_backup_cleanup');
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('seoxan_status_cleanup_backups_event');
});

// Agregar menú en el panel admin
add_action('admin_menu', function () {
    add_menu_page(
        'Seoxan WP Status',
        'Seoxan Status',
        'manage_options',
        'seoxan-wp-status',
        'seoxan_wp_status_page',
        'dashicons-admin-tools',
        3
    );

    // Renombra la entrada de submenú generada automáticamente para el propio top-level
    add_submenu_page(
        'seoxan-wp-status',
        'Seoxan WP Status',
        '📊 Estado',
        'manage_options',
        'seoxan-wp-status',
        'seoxan_wp_status_page'
    );

    // Subapartado dedicado a la API remota
    add_submenu_page(
        'seoxan-wp-status',
        'Seoxan Status - API Remota',
        '🔑 API Remota',
        'manage_options',
        'seoxan-wp-status-api',
        'seoxan_api_settings_page'
    );

    wp_enqueue_style('seoxan-status-css', SEOXAN_STATUS_URL . 'assets/style.css');
});
