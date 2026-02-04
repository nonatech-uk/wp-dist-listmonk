<?php
/**
 * Plugin Name: Distribution - Listmonk
 * Plugin URI: https://github.com/nonatech-uk/wp-dist-listmonk
 * Description: Listmonk newsletter integration for Distribution
 * Version: 1.0.0
 * Author: NonaTech Services Ltd
 * License: GPL v2 or later
 * Text Domain: dist-listmonk
 * Requires Plugins: distribution
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PARISH_DIST_LISTMONK_VERSION', '1.0.0');
define('PARISH_DIST_LISTMONK_DIR', plugin_dir_path(__FILE__));
define('PARISH_DIST_LISTMONK_URL', plugin_dir_url(__FILE__));

// Check for core plugin
add_action('plugins_loaded', function() {
    if (!class_exists('Parish_Distribution')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Parish Distribution - Listmonk requires the Parish Distribution plugin to be installed and activated.', 'parish-dist-listmonk');
            echo '</p></div>';
        });
        return;
    }

    require_once PARISH_DIST_LISTMONK_DIR . 'includes/class-dist-listmonk.php';
    require_once PARISH_DIST_LISTMONK_DIR . 'includes/class-dist-listmonk-api.php';
    require_once PARISH_DIST_LISTMONK_DIR . 'includes/class-github-updater.php';

    $listmonk = Dist_Listmonk::get_instance();
    $listmonk->init();

    // Initialize GitHub updater
    if (is_admin()) {
        new Dist_Listmonk_GitHub_Updater(
            __FILE__,
            'nonatech-uk/wp-dist-listmonk',
            PARISH_DIST_LISTMONK_VERSION
        );
    }
});

function parish_dist_listmonk_activate() {
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'parish_dist_listmonk_activate');

function parish_dist_listmonk_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'parish_dist_listmonk_deactivate');
