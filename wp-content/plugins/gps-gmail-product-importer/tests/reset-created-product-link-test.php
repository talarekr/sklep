<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

$GLOBALS['gps_test_options'] = array();
$GLOBALS['gps_test_meta'] = array();
$GLOBALS['gps_test_posts'] = array();
$GLOBALS['gps_test_deleted_products'] = array();
$GLOBALS['gps_test_valid_product_cat_terms'] = array(5802 => true);

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $GLOBALS['gps_test_options'][$name] ?? $default; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags); }
function current_time($type, $gmt = 0) { return '2026-06-07 11:37:28'; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_test_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['gps_test_meta'][$id][$key]); return true; }
function get_post($id) { return $GLOBALS['gps_test_posts'][$id] ?? null; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat'; }
function term_exists($term, $taxonomy = '') { return ($taxonomy === 'product_cat' && !empty($GLOBALS['gps_test_valid_product_cat_terms'][(int) $term])) ? array('term_id' => (int) $term, 'term_taxonomy_id' => (int) $term) : null; }
function wp_delete_post($post_id, $force_delete = false) { $GLOBALS['gps_test_deleted_products'][] = $post_id; unset($GLOBALS['gps_test_posts'][$post_id]); return true; }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$resetLink = $reflection->getMethod('reset_created_woo_product_link_for_staging_item');
$resetLink->setAccessible(true);

function assert_true($condition, $message, $payload = null) {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        if ($payload !== null) { var_export($payload); }
        exit(1);
    }
}

function seed_ready_staging_item($linked_product_status = null) {
    $itemId = 60849;
    $productId = 60850;
    $GLOBALS['gps_test_posts'] = array(
        $itemId => (object) array('ID' => $itemId, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_status' => 'private', 'post_content' => 'Body'),
    );
    if ($linked_product_status !== null) {
        $GLOBALS['gps_test_posts'][$productId] = (object) array('ID' => $productId, 'post_type' => 'product', 'post_status' => $linked_product_status);
    }
    $GLOBALS['gps_test_deleted_products'] = array();
    $GLOBALS['gps_test_meta'] = array(
        $itemId => array(
            '_gps_gmail_message_id' => 'gmail-60849',
            '_gps_detected_part_code' => '5Q0131701AN',
            '_gps_normalized_part_code' => '5Q0131701AN',
            '_gps_detected_oem_part_number' => '5Q0131701AN',
            '_gps_normalized_oem_part_number' => '5Q0131701AN',
            '_gps_gmail_import_image_count' => 2,
            '_gps_gmail_images_metadata' => wp_json_encode(array(array('attachment_id' => 'img-1'))),
            '_gps_ovoko_enrichment_status' => 'suggested',
            '_gps_ovoko_selected_match_id' => 'ovoko-match-123',
            '_gps_ovoko_category_id' => 'ov-123',
            '_gps_ovoko_category_name' => 'DPF',
            '_gps_ovoko_category_path' => 'Exhaust > DPF',
            '_gps_ovoko_part_category' => 'Catalyst',
            '_gps_category_mapping_status' => 'mapped',
            '_gps_suggested_woo_category_id' => 5802,
            '_gps_suggested_woo_category_path' => 'Filtr cząstek stałych Katalizator / FAP / DPF',
            '_gps_suggested_woo_category_confidence' => 'medium',
            '_gps_suggested_category_source' => 'ovoko_enrichment',
            '_gps_manual_price_override_enabled' => '1',
            '_gps_manual_price_pln' => '1800',
            '_gps_manual_price_note' => 'keep this manual price',
            '_gps_gmail_created_product_id' => $productId,
            '_gps_gmail_created_product_at' => '2026-06-07 10:00:00',
            '_gps_created_product_status' => 'draft',
            '_gps_staging_status' => 'created_product',
            '_gps_woo_draft_readiness_status' => 'needs_review',
            '_gps_woo_draft_blocking_reasons' => wp_json_encode(array('product_already_created')),
        ),
    );
}

seed_ready_staging_item(null);
$missing = $resetLink->invoke($plugin, 60849, false);
assert_true($missing['result'] === 'reset_created_product_link', 'Reset should be allowed when the linked product no longer exists.', $missing);
assert_true(absint($GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_id'] ?? 0) === 0, 'Missing-product reset should clear the created product link.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_woo_draft_readiness_status'] ?? '') === 'ready_to_create_product', 'Missing-product reset should refresh Woo draft readiness to ready.', $GLOBALS['gps_test_meta'][60849]);
assert_true(json_decode($GLOBALS['gps_test_meta'][60849]['_gps_woo_draft_blocking_reasons'] ?? 'null', true) === array(), 'Missing-product reset should remove product_already_created blocker.', $GLOBALS['gps_test_meta'][60849]);

seed_ready_staging_item('trash');
$trash = $resetLink->invoke($plugin, 60849, false);
assert_true($trash['result'] === 'reset_created_product_link', 'Reset should be allowed when the linked product is in trash.', $trash);
assert_true(absint($GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_id'] ?? 0) === 0, 'Trash-product reset should clear the created product link.', $GLOBALS['gps_test_meta'][60849]);

seed_ready_staging_item('draft');
$blocked = $resetLink->invoke($plugin, 60849, false);
assert_true($blocked['result'] === 'blocked' && $blocked['reason'] === 'linked_product_still_exists', 'Reset should be blocked when linked product exists and force is not checked.', $blocked);
assert_true(absint($GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_id'] ?? 0) === 60850, 'Blocked reset should leave the created product link unchanged.', $GLOBALS['gps_test_meta'][60849]);

seed_ready_staging_item('draft');
$forced = $resetLink->invoke($plugin, 60849, true);
assert_true($forced['result'] === 'reset_created_product_link', 'Forced reset should clear the link when the product still exists.', $forced);
assert_true(isset($GLOBALS['gps_test_posts'][60850]) && $GLOBALS['gps_test_posts'][60850]->post_status === 'draft', 'Forced reset must not delete or trash the linked product.', $GLOBALS['gps_test_posts']);
assert_true($GLOBALS['gps_test_deleted_products'] === array(), 'Forced reset must not call wp_delete_post.', $GLOBALS['gps_test_deleted_products']);
assert_true(absint($GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_id'] ?? 0) === 0, 'Forced reset should clear the created product link.', $GLOBALS['gps_test_meta'][60849]);
assert_true(!isset($GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_at']) && !isset($GLOBALS['gps_test_meta'][60849]['_gps_created_product_status']), 'Reset should clear created-product timestamp/status meta when present.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_ovoko_selected_match_id'] ?? '') === 'ovoko-match-123', 'Reset must not clear Ovoko data.', $GLOBALS['gps_test_meta'][60849]);
assert_true(absint($GLOBALS['gps_test_meta'][60849]['_gps_suggested_woo_category_id'] ?? 0) === 5802, 'Reset must not clear category mapping data.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_manual_price_pln'] ?? '') === '1800', 'Reset must not clear manual price data.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_woo_draft_readiness_status'] ?? '') === 'ready_to_create_product', 'Forced reset should refresh Woo draft readiness.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_readiness_status'] ?? '') === 'ready_to_create_product', 'Forced reset should refresh legacy readiness.', $GLOBALS['gps_test_meta'][60849]);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_staging_status'] ?? '') === 'ready_to_create_product', 'Ready item staging status should be restored after reset.', $GLOBALS['gps_test_meta'][60849]);

seed_ready_staging_item(null);
unset($GLOBALS['gps_test_meta'][60849]['_gps_manual_price_override_enabled'], $GLOBALS['gps_test_meta'][60849]['_gps_manual_price_pln']);
$needsReview = $resetLink->invoke($plugin, 60849, false);
assert_true($needsReview['result'] === 'reset_created_product_link', 'Reset should still run readiness for not-ready items.', $needsReview);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_staging_status'] ?? '') === 'needs_review', 'Not-ready item staging status should become needs_review after reset.', $GLOBALS['gps_test_meta'][60849]);

echo "Reset created Woo product link tests passed\n";
