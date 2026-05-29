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

if (!defined('GPSWISS_OVOKO_BUILD_MARKER')) {
    define('GPSWISS_OVOKO_BUILD_MARKER', '0.1.0-safe-sync-ui-advanced');
}

require_once __DIR__ . '/src/Contracts/OvokoConnectorInterface.php';
require_once __DIR__ . '/src/DTO/NormalizedOvokoPart.php';
require_once __DIR__ . '/src/Services/OvokoSupplyConnectorClient.php';
require_once __DIR__ . '/src/Services/RrrApiClient.php';
require_once __DIR__ . '/src/Services/OvokoProductSyncService.php';
require_once __DIR__ . '/src/Services/OvokoImageImportPlan.php';
require_once __DIR__ . '/src/Services/OvokoIntegrationService.php';
require_once __DIR__ . '/src/Services/OvokoAutoSyncDryRunService.php';
require_once __DIR__ . '/src/Services/AdminPage.php';
require_once __DIR__ . '/src/Plugin.php';

add_action('plugins_loaded', static function (): void {
    $plugin = new GPSwiss\Ovoko\Plugin(__FILE__);
    $plugin->boot();
});
