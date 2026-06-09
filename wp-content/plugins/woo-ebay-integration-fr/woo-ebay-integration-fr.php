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
define('WEI_FR_BUILD_COMMIT', '65aedd3-shared-oauth-callback-router');
define('WEI_FR_BUILD_ID', '2026-06-03-shared-oauth-state-router-v4-build-marker');
define('WEI_FR_OAUTH_CALLBACK_FLOW_VERSION', '2026-06-03-shared-oauth-state-router-v4');

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

if (!function_exists('wei_fr_create_ebay_auth')) {
    /**
     * Safely creates the FR eBay OAuth service.
     *
     * Production deploys may briefly leave the plugin bootstrap newer than the
     * uploaded src/ tree. In that state, avoid taking down all of WordPress with
     * a fatal class-not-found error and log a clear diagnostic instead.
     */
    function wei_fr_create_ebay_auth(): ?WEI_FR\Services\EbayAuth
    {
        if (!class_exists(WEI_FR\Services\EbayAuth::class)) {
            error_log('Woo eBay Integration FR: OAuth bootstrap skipped because WEI_FR\Services\EbayAuth is not available. Check plugin autoload/deploy completeness.');

            return null;
        }

        if (!class_exists(WEI_FR\Services\Logger::class)) {
            error_log('Woo eBay Integration FR: OAuth bootstrap skipped because WEI_FR\Services\Logger is not available. Check plugin autoload/deploy completeness.');

            return null;
        }

        return new WEI_FR\Services\EbayAuth(new WEI_FR\Services\Logger());
    }
}

if (!function_exists('wei_fr_handle_shared_oauth_callback')) {
    /**
     * Global FR OAuth handoff used by the DE shared callback router.
     *
     * @param array<string, mixed> $request
     */
    function wei_fr_handle_shared_oauth_callback(array $request = []): void
    {
        $auth = wei_fr_create_ebay_auth();

        if ($auth === null) {
            return;
        }

        $auth->handle_shared_callback($request);
    }
}

add_action('wei_fr_handle_shared_oauth_callback', 'wei_fr_handle_shared_oauth_callback', 10, 1);

add_action('plugins_loaded', static function (): void {
    $auth = wei_fr_create_ebay_auth();

    if ($auth === null) {
        return;
    }

    $auth->handle_admin_bootstrap_oauth_callback();
}, 0);

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WooCommerce')) {
        return;
    }

    if (!class_exists(WEI_FR\Plugin::class)) {
        error_log('Woo eBay Integration FR: plugin boot skipped because WEI_FR\Plugin is not available. Check plugin autoload/deploy completeness.');

        return;
    }

    (new WEI_FR\Plugin())->boot();
});
