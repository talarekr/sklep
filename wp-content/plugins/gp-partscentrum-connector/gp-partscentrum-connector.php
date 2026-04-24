<?php
/**
 * Plugin Name: GP Partscentrum Connector
 * Description: Integracja WooCommerce z zewnętrznym panelem partscentrum.jns.pl dla nowych części Skoda.
 * Version: 0.1.0
 * Author: GP Swiss
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GP_PARTSCENTRUM_CONNECTOR_VERSION', '0.1.0');
define('GP_PARTSCENTRUM_CONNECTOR_FILE', __FILE__);
define('GP_PARTSCENTRUM_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('GP_PARTSCENTRUM_CONNECTOR_URL', plugin_dir_url(__FILE__));

require_once GP_PARTSCENTRUM_CONNECTOR_DIR . 'includes/class-gp-partscentrum-client.php';
require_once GP_PARTSCENTRUM_CONNECTOR_DIR . 'includes/class-gp-partscentrum-plugin.php';

function gp_partscentrum_connector_bootstrap(): void
{
    GP_Partscentrum_Plugin::instance();
}
add_action('plugins_loaded', 'gp_partscentrum_connector_bootstrap');

register_activation_hook(__FILE__, ['GP_Partscentrum_Plugin', 'activate']);
