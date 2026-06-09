<?php
/**
 * Plugin Name: GPS eBay Fitment Sync
 * Description: Prepares TecDoc/Apify KType fitment data for products already listed on eBay marketplaces. Does not sync anything to eBay.
 * Version: 0.1.0
 * Author: GPS
 * Text Domain: gps-ebay-fitment-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GPS_EBAY_FITMENT_SYNC_FILE', __FILE__);
define('GPS_EBAY_FITMENT_SYNC_DIR', plugin_dir_path(__FILE__));
define('GPS_EBAY_FITMENT_SYNC_VERSION', '0.1.0');

spl_autoload_register(static function ($class) {
    $prefix = 'GPS_Ebay_Fitment\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = GPS_EBAY_FITMENT_SYNC_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, static function () {
    GPS_Ebay_Fitment\Database\Installer::install();
});

add_action('plugins_loaded', static function () {
    GPS_Ebay_Fitment\Plugin::instance()->init();
});
