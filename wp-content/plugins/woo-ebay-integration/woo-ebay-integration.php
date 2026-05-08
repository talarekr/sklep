<?php
/**
 * Plugin Name: Woo eBay Integration
 * Description: Isolated eBay integration for WooCommerce with adapter-ready architecture.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEI_PLUGIN_FILE', __FILE__);
define('WEI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WEI_BUILD_COMMIT', '9d9c575-diagnostics');
define('WEI_BUILD_ID', '2026-05-08-admin-menu-null-slug-diagnostics');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'WEI\\') !== 0) {
        return;
    }

    $relative = str_replace('WEI\\', '', $class);
    $relative = str_replace('\\', '/', $relative);
    $path = WEI_PLUGIN_DIR . 'src/' . $relative . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

register_activation_hook(WEI_PLUGIN_FILE, ['WEI\\Database\\Migrations', 'activate']);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    (new WEI\Plugin())->boot();
});
