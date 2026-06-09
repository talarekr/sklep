<?php
/**
 * Plugin Name: GPS eBay Fitment Sync
 * Description: Safe preparation/audit layer for TecDoc KType fitment on WooCommerce products already mapped to eBay marketplaces.
 * Version: 0.1.0
 * Author: GPS
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: gps-ebay-fitment-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GPS_EBAY_FITMENT_SYNC_FILE', __FILE__);
define('GPS_EBAY_FITMENT_SYNC_DIR', plugin_dir_path(__FILE__));
define('GPS_EBAY_FITMENT_SYNC_VERSION', '0.1.0');

spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'GPSEbayFitmentSync\\') !== 0) {
        return;
    }

    $relative = str_replace('GPSEbayFitmentSync\\', '', $class);
    $relative = str_replace('\\', '/', $relative);
    $path = GPS_EBAY_FITMENT_SYNC_DIR . 'src/' . $relative . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

register_activation_hook(GPS_EBAY_FITMENT_SYNC_FILE, ['GPSEbayFitmentSync\\Database\\Migrations', 'activate']);

add_action('plugins_loaded', static function (): void {
    \GPSEbayFitmentSync\Plugin::instance()->boot();
});
