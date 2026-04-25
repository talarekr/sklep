<?php
/**
 * Plugin Name: Allegro Woo Importer
 * Description: Production-ready Allegro to WooCommerce importer (OAuth2, scheduled sync, product mapping, logs).
 * Version: 1.0.0
 * Author: Codex
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Text Domain: allegro-woo-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AWI_PLUGIN_FILE', __FILE__);
define('AWI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AWI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AWI_VERSION', '1.0.0');
if (!defined('AWI_SKIP_IMAGES')) {
    define('AWI_SKIP_IMAGES', true);
}

require_once AWI_PLUGIN_DIR . 'includes/class-plugin.php';

\AWI\Plugin::instance();
