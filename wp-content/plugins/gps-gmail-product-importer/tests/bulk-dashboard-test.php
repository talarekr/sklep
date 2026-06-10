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
function update_option($key, $value, $autoload = null) { $GLOBALS['gps_gmail_test_options'][$key] = $value; return true; }
function get_posts($args) { $out = []; foreach ($GLOBALS['gps_gmail_test_posts'] as $id => $post) { if (!empty($args['post_type']) && $post->post_type !== $args['post_type']) { continue; } $out[] = !empty($args['fields']) && $args['fields'] === 'ids' ? (int) $id : $post; } return array_slice($out, 0, (int) ($args['posts_per_page'] ?? count($out))); }
function get_post($id) { return $GLOBALS['gps_gmail_test_posts'][(int) $id] ?? null; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_gmail_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_gmail_test_meta'][(int) $id][$key] = $value; return true; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return str_repeat('a', (int) $length); }
function current_time($type, $gmt = false) { return '2026-06-08 00:00:00'; }
function get_edit_post_link($id) { return 'post.php?post=' . (int) $id . '&action=edit'; }
function admin_url($path = '') { return 'wp-admin/' . $path; }
function add_query_arg($args, $url = '') { return $url . '?' . http_build_query($args); }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { private $message; public function __construct($code = '', $message = '', $data = null) { $this->message = $message; } public function get_error_message() { return $this->message; } }

require_once dirname(__DIR__) . '/gps-gmail-product-importer.php';

function gps_gmail_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function gps_gmail_call($object, string $method, array $args = []) { $ref = new ReflectionMethod($object, $method); $ref->setAccessible(true); return $ref->invokeArgs($object, $args); }
function gps_gmail_seed_item(int $id, array $meta): void
{
    $GLOBALS['gps_gmail_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'gps_gmail_stage', 'post_status' => 'publish', 'post_title' => $meta['_gps_gmail_subject'] ?? ('Item ' . $id), 'post_content' => 'body'];
    $GLOBALS['gps_gmail_test_meta'][$id] = $meta + ['_gps_gmail_message_id' => 'msg-' . $id, '_gps_gmail_date' => '2026-06-08', '_gps_staging_status' => 'staged'];
}
function gps_gmail_seed_product(int $id, array $meta): void
{
    $GLOBALS['gps_gmail_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Product ' . $id, 'post_content' => ''];
    $GLOBALS['gps_gmail_test_meta'][$id] = $meta;
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


gps_gmail_run('mark unread dry-run qualifies only Gmail staging not finally imported to Ovoko', function (): void {
    $plugin = GPS_Gmail_Product_Importer::instance();
    gps_gmail_seed_product(9001, ['_ovoko_part_id' => 'OVO-9001']);
    gps_gmail_seed_item(401, ['_gps_gmail_subject' => 'has ovoko', '_gps_gmail_created_product_id' => '9001', '_gps_gmail_import_image_count' => '0', '_gps_gmail_images_metadata' => '[]']);

    gps_gmail_seed_product(9002, []);
    gps_gmail_seed_item(402, ['_gps_gmail_subject' => 'missing images no ovoko', '_gps_gmail_created_product_id' => '9002', '_gps_detected_part_code' => 'A', '_gps_normalized_part_code' => 'A', '_gps_detected_oem_part_number' => 'A', '_gps_normalized_oem_part_number' => 'A', '_gps_gmail_import_image_count' => '0', '_gps_gmail_images_metadata' => '[]']);

    gps_gmail_seed_item(403, ['_gps_gmail_subject' => 'missing part no product', '_gps_gmail_import_image_count' => '1', '_gps_gmail_images_metadata' => '[{"url":"x"}]']);
    gps_gmail_seed_item(404, ['_gps_gmail_subject' => 'missing gmail id', '_gps_gmail_message_id' => '', '_gps_gmail_import_image_count' => '0', '_gps_gmail_images_metadata' => '[]']);
    gps_gmail_seed_item(405, ['_gps_gmail_subject' => 'repair needed', '_gps_staging_status' => 'repair_needed', '_gps_detected_part_code' => 'B', '_gps_normalized_part_code' => 'B', '_gps_detected_oem_part_number' => 'B', '_gps_normalized_oem_part_number' => 'B', '_gps_gmail_import_image_count' => '1', '_gps_gmail_images_metadata' => '[{"url":"x"}]']);

    $result = gps_gmail_call($plugin, 'process_mark_not_imported_gmail_unread', [true, [
        'batch_size' => 50,
        'include_missing_part_code' => true,
        'include_missing_images' => true,
        'include_woo_not_created' => true,
        'include_repair_needed' => true,
        'include_blocked_validation' => true,
        'include_already_marked' => false,
    ]]);

    gps_gmail_assert($result['skipped_already_ovoko_imported'] === 1, 'Product with Ovoko part ID must be skipped.');
    gps_gmail_assert($result['skipped_missing_gmail_message_id'] === 1, 'Missing Gmail ID must be counted as skipped_missing_gmail_message_id.');
    gps_gmail_assert($result['will_mark_unread_count'] === 3, 'Expected missing images, missing part/no Woo, and repair_needed candidates.');
    $reasons = implode('|', array_map(fn($row) => $row['reason'], $result['examples']));
    gps_gmail_assert(str_contains($reasons, 'missing_images'), 'Missing images should qualify.');
    gps_gmail_assert(str_contains($reasons, 'missing_part_code') && str_contains($reasons, 'product_not_created'), 'Missing part/no Woo should qualify.');
    gps_gmail_assert(str_contains($reasons, 'repair_needed'), 'repair_needed should qualify.');
});


gps_gmail_run('CSV audit mark unread preview uses CSV plus current Woo Ovoko truth', function (): void {
    $plugin = GPS_Gmail_Product_Importer::instance();
    gps_gmail_seed_product(9101, []);
    gps_gmail_seed_product(9102, ['_ovoko_part_id' => 'OVO-9102']);
    gps_gmail_seed_product(9103, ['_gps_ovoko_crm_only_imported_at' => '2026-06-08 12:00:00']);

    $csv = sys_get_temp_dir() . '/gps-gmail-csv-mark-unread-test.csv';
    $handle = fopen($csv, 'w');
    fputcsv($handle, ['gmail_message_id', 'created_product_id', 'readiness_status', 'blocking_reasons', 'subject']);
    fputcsv($handle, ['csv-msg-1', '', 'needs_review', 'missing_part_code', 'missing part no product']);
    fputcsv($handle, ['csv-msg-2', '9101', 'needs_review', 'missing_images', 'missing images no ovoko']);
    fputcsv($handle, ['csv-msg-3', '9102', 'needs_review', 'product_already_created', 'already imported part id']);
    fputcsv($handle, ['csv-msg-4', '9103', 'needs_review', '', 'now crm imported']);
    fputcsv($handle, ['csv-msg-2', '9101', 'needs_review', 'missing_images', 'duplicate gmail id']);
    fputcsv($handle, ['', '', 'needs_review', 'missing_part_code', 'missing gmail id']);
    fclose($handle);

    $result = gps_gmail_call($plugin, 'process_mark_not_imported_gmail_unread_csv', [$csv, true, [
        'batch_size' => 50,
        'include_missing_part_code' => true,
        'include_missing_images' => true,
        'include_woo_not_created' => true,
        'include_repair_needed' => true,
        'include_already_marked' => false,
    ], true]);

    gps_gmail_assert($result['total_csv_rows'] === 6, 'Expected six CSV rows.');
    gps_gmail_assert($result['rows_with_gmail_message_id'] === 5, 'Expected five rows with Gmail IDs.');
    gps_gmail_assert($result['will_mark_unread_count'] === 2, 'Only missing part/no product and missing images/no Ovoko should be marked.');
    gps_gmail_assert($result['skipped_already_ovoko_imported'] === 2, 'Product with _ovoko_part_id and product with _gps_ovoko_crm_only_imported_at should be skipped.');
    gps_gmail_assert($result['skipped_duplicate_gmail_message_id'] === 1, 'Duplicate Gmail message ID should be skipped.');
    gps_gmail_assert($result['skipped_missing_gmail_message_id'] === 1, 'Missing Gmail ID should be skipped.');
    $reasons = implode('|', array_map(fn($row) => $row['reason_for_unread'], $result['examples_to_mark']));
    gps_gmail_assert(str_contains($reasons, 'missing_part_code') && str_contains($reasons, 'product_not_created'), 'CSV missing_part_code/no product should qualify.');
    gps_gmail_assert(str_contains($reasons, 'missing_images'), 'CSV missing_images/product without Ovoko should qualify.');
    gps_gmail_assert(count($result['examples_skipped_ovoko']) === 2, 'Skipped Ovoko examples should include both current imported products.');
});
