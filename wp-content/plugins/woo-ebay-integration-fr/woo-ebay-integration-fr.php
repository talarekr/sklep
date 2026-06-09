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

if (!function_exists('wei_fr_class_path')) {
    function wei_fr_class_path(string $class): ?string
    {
        if (strpos($class, 'WEI_FR\\') !== 0) {
            return null;
        }

        $relative = str_replace('WEI_FR\\', '', $class);
        $relative = str_replace('\\', '/', $relative);

        return WEI_FR_PLUGIN_DIR . 'src/' . $relative . '.php';
    }
}

if (!function_exists('wei_fr_autoload')) {
    function wei_fr_autoload(string $class): void
    {
        $path = wei_fr_class_path($class);

        if ($path !== null && file_exists($path)) {
            require_once $path;
        }
    }
}

spl_autoload_register('wei_fr_autoload');
error_log('Woo eBay Integration FR: FR autoloader registered');

if (!function_exists('wei_fr_ensure_class_available')) {
    function wei_fr_ensure_class_available(string $class, string $label): bool
    {
        if (class_exists($class)) {
            error_log('Woo eBay Integration FR: ' . $label . ' class available');

            return true;
        }

        wei_fr_autoload($class);

        if (class_exists($class)) {
            error_log('Woo eBay Integration FR: ' . $label . ' class available');

            return true;
        }

        $path = wei_fr_class_path($class);
        if ($path !== null && file_exists($path)) {
            require_once $path;
        }

        if (class_exists($class)) {
            error_log('Woo eBay Integration FR: ' . $label . ' class available');

            return true;
        }

        error_log('Woo eBay Integration FR: ' . $label . ' class unavailable after autoload/file fallback. Check plugin deploy completeness.');

        return false;
    }
}

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
        if (!wei_fr_ensure_class_available(WEI_FR\Services\EbayAuth::class, 'EbayAuth')) {
            error_log('Woo eBay Integration FR: OAuth bootstrap skipped because WEI_FR\Services\EbayAuth is not available. Check plugin autoload/deploy completeness.');

            return null;
        }

        if (!wei_fr_ensure_class_available(WEI_FR\Services\Logger::class, 'Logger')) {
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
    if (!wei_fr_ensure_class_available(WEI_FR\Plugin::class, 'WEI_FR Plugin')) {
        error_log('Woo eBay Integration FR: plugin boot skipped because WEI_FR\Plugin is not available. Check plugin autoload/deploy completeness.');

        return;
    }

    if (!class_exists('WooCommerce')) {
        return;
    }

    (new WEI_FR\Plugin())->boot();
    error_log('Woo eBay Integration FR: FR plugin booted');
});
