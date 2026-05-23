<?php
/**
 * Plugin Name: GPSwiss Ovoko Integration
 * Description: Standalone Ovoko callback receiver and readiness diagnostics for WooCommerce.
 * Version: 0.1.0
 * Author: GPSwiss
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Services/OvokoIntegrationService.php';
require_once __DIR__ . '/src/Services/AdminPage.php';
require_once __DIR__ . '/src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    $plugin = new GPSwiss\Ovoko\Plugin(__FILE__);
    $plugin->boot();
});
