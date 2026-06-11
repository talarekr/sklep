<?php
/**
 * Plugin Name: GPS eBay Fitment Sync
 * Description: TecDoc/Apify vehicle compatibility lookup and local KType cache for WooCommerce products. This MVP does not sync anything to eBay.
 * Version: 0.1.0
 * Author: GPS
 * Text Domain: gps-ebay-fitment-sync
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('GPS_EBAY_FITMENT_SYNC_FILE', __FILE__);
define('GPS_EBAY_FITMENT_SYNC_DIR', plugin_dir_path(__FILE__));
define('GPS_EBAY_FITMENT_SYNC_VERSION', '0.1.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'GPS_Ebay_Fitment_Sync\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = GPS_EBAY_FITMENT_SYNC_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, static function (): void {
    GPS_Ebay_Fitment_Sync\Database\Database::activate();
});

register_deactivation_hook(__FILE__, static function (): void {
    GPS_Ebay_Fitment_Sync\Plugin::deactivate();
});

add_action('plugins_loaded', static function (): void {
    GPS_Ebay_Fitment_Sync\Plugin::instance()->init();
});
