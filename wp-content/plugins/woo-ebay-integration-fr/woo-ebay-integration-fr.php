<?php
/**
 * Plugin Name: Woo eBay Integration FR
 * Description: Isolated eBay integration for WooCommerce with adapter-ready architecture.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEI_FR_PLUGIN_FILE', __FILE__);
define('WEI_FR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WEI_FR_PLUGIN_VERSION', '0.1.0');
define('WEI_FR_BUILD_COMMIT', '4c51538-fr-build-marker');
define('WEI_FR_BUILD_ID', '2026-06-03-fr-admin-diagnostics-build-marker');
define('WEI_FR_OAUTH_CALLBACK_FLOW_VERSION', '2026-06-03-fr-capability-diagnostics-v2');

spl_autoload_register(function (string $class): void {
    if (strpos($class, 'WEI_FR\\') !== 0) {
        return;
    }

    $relative = str_replace('WEI_FR\\', '', $class);
    $relative = str_replace('\\', '/', $relative);
    $path = WEI_FR_PLUGIN_DIR . 'src/' . $relative . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

register_activation_hook(WEI_FR_PLUGIN_FILE, ['WEI_FR\\Database\\Migrations', 'activate']);

add_action('plugins_loaded', static function (): void {
    $auth = new WEI_FR\Services\EbayAuth(new WEI_FR\Services\Logger());
    $auth->handle_admin_bootstrap_oauth_callback();
}, 0);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    (new WEI_FR\Plugin())->boot();
});
