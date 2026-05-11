<?php
/**
 * Plugin Name: GPSwiss Allegro Gearboxes
 * Description: Separate Allegro gearboxes account importer for WooCommerce.
 * Version: 1.0.0
 * Author: Codex
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Text Domain: gpswiss-allegro-gearboxes
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GAG_PLUGIN_FILE', __FILE__);
define('GAG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GAG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GAG_VERSION', '1.0.0');
define('GAG_PLUGIN_BUILD', '2026-05-11-initial-separation');
if (!defined('GAG_SKIP_IMAGES')) {
    define('GAG_SKIP_IMAGES', true);
}

require_once GAG_PLUGIN_DIR . 'includes/class-plugin.php';

\GAG\Plugin::instance();
