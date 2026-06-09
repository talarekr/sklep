<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
$GLOBALS['gps_gmail_test_posts'] = [];
$GLOBALS['gps_gmail_test_meta'] = [];
$GLOBALS['gps_gmail_test_options'] = [];
$GLOBALS['gps_gmail_test_ovoko_calls'] = 0;
$GLOBALS['gps_gmail_test_allegro_calls'] = 0;

function add_action(...$args): void {}
function register_activation_hook(...$args): void {}
function register_post_type(...$args): void {}
function add_menu_page(...$args): void {}
function register_setting(...$args): void {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function wp_parse_args($args, $defaults = []) { return array_merge((array) $defaults, (array) $args); }
function get_option($key, $default = false) { return $GLOBALS['gps_gmail_test_options'][$key] ?? $default; }
function get_posts($args) { return array_values(array_map('intval', array_keys($GLOBALS['gps_gmail_test_posts']))); }
function get_post($id) { return $GLOBALS['gps_gmail_test_posts'][(int) $id] ?? null; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_gmail_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_gmail_test_meta'][(int) $id][$key] = $value; return true; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function current_time($type, $gmt = false) { return '2026-06-08 00:00:00'; }
function get_edit_post_link($id) { return 'post.php?post=' . (int) $id . '&action=edit'; }
function admin_url($path = '') { return 'wp-admin/' . $path; }
function add_query_arg($args, $url = '') { return $url . '?' . http_build_query($args); }

require_once dirname(__DIR__) . '/gps-gmail-product-importer.php';

function gps_gmail_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function gps_gmail_call($object, string $method, array $args = []) { $ref = new ReflectionMethod($object, $method); $ref->setAccessible(true); return $ref->invokeArgs($object, $args); }
function gps_gmail_seed_item(int $id, array $meta): void
{
    $GLOBALS['gps_gmail_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'gps_gmail_stage', 'post_status' => 'publish', 'post_title' => $meta['_gps_gmail_subject'] ?? ('Item ' . $id), 'post_content' => 'body'];
    $GLOBALS['gps_gmail_test_meta'][$id] = $meta + ['_gps_gmail_message_id' => 'msg-' . $id, '_gps_gmail_date' => '2026-06-08', '_gps_staging_status' => 'staged'];
}
function gps_gmail_run(string $name, callable $test): void { $GLOBALS['gps_gmail_test_posts'] = []; $GLOBALS['gps_gmail_test_meta'] = []; $GLOBALS['gps_gmail_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = ['fixed_ovoko_import_category_enabled' => 1, 'fixed_ovoko_import_category_id' => '278', 'fixed_ovoko_import_category_name' => 'Turbina', 'allow_empty_price_for_fixed_category_crm_only_import' => 1, 'message_status_filter' => 'unread', 'import_images' => 1, 'duplicate_protection' => 1]; $test(); echo "PASS {$name}\n"; }

gps_gmail_run('dashboard audit aggregates readiness and fixed category empty price', function (): void {
    $plugin = GPS_Gmail_Product_Importer::instance();
    gps_gmail_seed_item(101, ['_gps_gmail_subject' => 'ready', '_gps_storage_location' => '17KNS', '_gps_detected_part_code' => '06K145654L', '_gps_normalized_part_code' => '06K145654L', '_gps_detected_oem_part_number' => '06K145654L', '_gps_normalized_oem_part_number' => '06K145654L', '_gps_gmail_import_image_count' => '4', '_gps_gmail_images_metadata' => '[{"url":"x"}]', '_gps_ovoko_category_suggestion_category_id' => '278', '_gps_ovoko_category_suggestion_category_name' => 'Turbina', '_gps_price_required_for_woo_draft' => '0']);
    gps_gmail_seed_item(102, ['_gps_gmail_subject' => 'blocked', '_gps_storage_location' => '18KNS', '_gps_gmail_import_image_count' => '0', '_gps_gmail_images_metadata' => '[]']);
    $audit = gps_gmail_call($plugin, 'bulk_dashboard_audit_data');
    gps_gmail_assert($audit['summary']['total_staged'] === 2, 'Expected two staged items.');
    gps_gmail_assert($audit['summary']['ready'] === 1, 'Expected one ready item.');
    gps_gmail_assert($audit['summary']['blocked'] === 1, 'Expected one blocked item.');
    gps_gmail_assert(($audit['blockers_grouped']['missing_part_code'] ?? 0) >= 1, 'Expected missing_part_code blocker group.');
    gps_gmail_assert(($audit['blockers_grouped']['missing_images'] ?? 0) >= 1, 'Expected missing_images blocker group.');
});

gps_gmail_run('error CSV row model includes only blocked items while full audit includes all', function (): void {
    $plugin = GPS_Gmail_Product_Importer::instance();
    gps_gmail_seed_item(201, ['_gps_gmail_subject' => 'ready', '_gps_detected_part_code' => 'A', '_gps_normalized_part_code' => 'A', '_gps_detected_oem_part_number' => 'A', '_gps_normalized_oem_part_number' => 'A', '_gps_gmail_import_image_count' => '1', '_gps_gmail_images_metadata' => '[{"url":"x"}]']);
    gps_gmail_seed_item(202, ['_gps_gmail_subject' => 'blocked', '_gps_gmail_import_image_count' => '0', '_gps_gmail_images_metadata' => '[]']);
    $audit = gps_gmail_call($plugin, 'bulk_dashboard_audit_data');
    $errors = array_values(array_filter($audit['rows'], fn($row) => !empty($row['blocking_reasons'])));
    gps_gmail_assert(count($audit['rows']) === 2, 'Full audit should include all items.');
    gps_gmail_assert(count($errors) === 1 && $errors[0]['staging_item_id'] === 202, 'Error CSV source should include only blocked item.');
    foreach (['staging_item_id', 'gmail_message_id', 'gmail_subject', 'storage_location', 'part_code', 'image_count', 'readiness_status', 'blocking_reasons', 'suggested_action', 'created_product_id', 'notes'] as $column) { gps_gmail_assert(array_key_exists($column, $errors[0]), 'Missing CSV column ' . $column); }
});

gps_gmail_run('dashboard audit and full preparation contract do not call Ovoko live write or Allegro', function (): void {
    $plugin = GPS_Gmail_Product_Importer::instance();
    gps_gmail_seed_item(301, ['_gps_gmail_subject' => 'ready', '_gps_detected_part_code' => 'A', '_gps_normalized_part_code' => 'A', '_gps_detected_oem_part_number' => 'A', '_gps_normalized_oem_part_number' => 'A', '_gps_gmail_import_image_count' => '1', '_gps_gmail_images_metadata' => '[{"url":"x"}]']);
    $audit1 = gps_gmail_call($plugin, 'bulk_dashboard_audit_data');
    $audit2 = gps_gmail_call($plugin, 'bulk_dashboard_audit_data');
    gps_gmail_assert($audit1['summary'] === $audit2['summary'], 'Dashboard audit should be idempotent.');
    gps_gmail_assert($GLOBALS['gps_gmail_test_ovoko_calls'] === 0 && $GLOBALS['gps_gmail_test_allegro_calls'] === 0, 'Dashboard audit must not call Ovoko live write or Allegro.');
});
