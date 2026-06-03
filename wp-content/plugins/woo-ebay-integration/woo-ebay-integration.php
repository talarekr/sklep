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
define('WEI_BUILD_COMMIT', '65aedd3-shared-oauth-callback-router');
define('WEI_BUILD_ID', '2026-06-03-bootstrap-fr-oauth-router-v5-build-marker');
define('WEI_OAUTH_CALLBACK_FLOW_VERSION', '2026-06-03-bootstrap-fr-oauth-router-v5');

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

if (!function_exists('wei_shared_oauth_fr_bootstrap_router')) {
    function wei_shared_oauth_fr_bootstrap_router(): void
    {
        if (function_exists('is_admin') && !is_admin()) {
            return;
        }

        $page = (string) ($_GET['page'] ?? '');
        if (function_exists('wp_unslash')) {
            $page = (string) wp_unslash($page);
        }

        if ($page !== WEI\Services\EbayAuth::CALLBACK_PAGE_SLUG) {
            return;
        }

        $state = (string) ($_GET['state'] ?? '');
        if (function_exists('wp_unslash')) {
            $state = (string) wp_unslash($state);
        }

        if ($state === '' || strpos($state, 'wei_fr:') !== 0) {
            return;
        }

        if (empty($_GET['code']) && empty($_GET['error'])) {
            return;
        }

        (new WEI\Services\EbayAuth(new WEI\Services\Logger()))->route_foreign_fr_oauth_callback('bootstrap_admin_init');
    }
}

if (function_exists('add_action')) {
    error_log('WEI_SHARED_OAUTH_FR_BOOTSTRAP_ROUTER_REGISTERED: {"hook":"admin_init","priority":0,"source":"woo-ebay-integration.php"}');
    add_action('admin_init', 'wei_shared_oauth_fr_bootstrap_router', 0);
}

add_action('plugins_loaded', static function (): void {
    $auth = new WEI\Services\EbayAuth(new WEI\Services\Logger());
    $auth->handle_admin_bootstrap_oauth_callback();
}, 0);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    (new WEI\Plugin())->boot();
});
