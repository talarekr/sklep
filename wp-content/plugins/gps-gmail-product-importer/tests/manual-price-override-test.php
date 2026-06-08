<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

$GLOBALS['gps_test_options'] = array();
$GLOBALS['gps_test_meta'] = array();
$GLOBALS['gps_test_posts'] = array();
$GLOBALS['gps_test_next_product_id'] = 9001;
$GLOBALS['gps_test_terms'] = array();
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
function get_current_user_id() { return 42; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_test_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][$id][$key] = $value; return true; }
function get_post($id) { return $GLOBALS['gps_test_posts'][$id] ?? null; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wc_get_product($id = null) { return true; }
function wp_insert_post($args, $wp_error = false) { $id = $GLOBALS['gps_test_next_product_id']++; $GLOBALS['gps_test_posts'][$id] = (object) ($args + array('ID' => $id)); return $id; }
function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) { $GLOBALS['gps_test_terms'][] = compact('object_id', 'terms', 'taxonomy', 'append'); return true; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat' || $taxonomy === 'product_type'; }
function term_exists($term, $taxonomy = '') { return ($taxonomy === 'product_cat' && !empty($GLOBALS['gps_test_valid_product_cat_terms'][(int) $term])) ? array('term_id' => (int) $term, 'term_taxonomy_id' => (int) $term) : null; }
function wp_delete_post($post_id, $force_delete = false) { unset($GLOBALS['gps_test_posts'][$post_id]); return true; }
function has_post_thumbnail($product_id) { return false; }

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '', $data = null) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

require dirname(__DIR__) . '/gps-gmail-product-importer.php';
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['import_images'] = 0;

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$readiness = $reflection->getMethod('readiness_status');
$readiness->setAccessible(true);
$selectPrice = $reflection->getMethod('selected_price_for_analysis');
$selectPrice->setAccessible(true);
$setManual = $reflection->getMethod('set_manual_price_override_for_staging_item');
$setManual->setAccessible(true);
$createDraft = $reflection->getMethod('create_woo_draft_from_staging_item');
$createDraft->setAccessible(true);

function assert_true($condition, $message, $payload = null) {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        if ($payload !== null) { var_export($payload); }
        exit(1);
    }
}

$base = array(
    'staging_item_id' => 60849,
    'message_id' => 'gmail-60849',
    'thread_id' => 'thread-1',
    'date' => '2026-06-07',
    'from' => 'sender@example.com',
    'subject' => 'DPF 5Q0131701AN',
    'label' => 'Woo import',
    'body' => 'Body',
    'storage_location' => 'A1',
    'detected_part_code' => '5Q0131701AN',
    'normalized_part_code' => '5Q0131701AN',
    'detected_oem_part_number' => '5Q0131701AN',
    'normalized_oem_part_number' => '5Q0131701AN',
    'oem_candidates' => array(),
    'detected_vehicle_make' => 'VW',
    'detected_vehicle_model' => 'Golf',
    'detected_vehicle_confidence' => 'high',
    'image_attachments_found' => 2,
    'image_attachment_set_hash' => 'hash',
    'images' => array(array('attachment_id' => 'img-1')),
    'warnings' => array(),
    'ovoko_enrichment_status' => 'suggested',
    'ovoko_category_id' => 'ov-123',
    'ovoko_category_name' => 'DPF',
    'ovoko_category_path' => 'Exhaust > DPF',
    'ovoko_part_category' => 'Catalyst',
    'category_mapping_status' => 'mapped',
    'suggested_woo_category_id' => 5802,
    'suggested_woo_category_path' => 'Filtr cząstek stałych Katalizator / FAP / DPF',
    'suggested_woo_category_confidence' => 'medium',
    'suggested_category_source' => 'ovoko_enrichment',
    'shipping_group' => 'shipping_30',
);

$noPrice = $readiness->invoke($plugin, $base, 'imported_from_gmail', 0);
assert_true($noPrice['status'] === 'needs_review' && in_array('missing_allegro_price_research', $noPrice['blocking_reasons'], true), 'No Allegro and no manual price should block readiness.', $noPrice);

$manual = $base + array('manual_price_override_enabled' => true, 'manual_price_pln' => '1800');
$manualReady = $readiness->invoke($plugin, $manual, 'imported_from_gmail', 0);
assert_true($manualReady['status'] === 'ready_to_create_product' && $manualReady['blocking_reasons'] === array(), 'Valid manual price should allow readiness.', $manualReady);
$selectedManual = $selectPrice->invoke($plugin, $manual + array('allegro_price_research_status' => 'completed', 'allegro_price_suggestion' => '1100.00', 'allegro_price_currency' => 'PLN', 'allegro_price_filtered_offer_count' => 5, 'allegro_price_confidence' => 'high'));
assert_true($selectedManual['source'] === 'manual_override' && $selectedManual['price'] === '1800', 'Manual price should have priority over Allegro suggestion.', $selectedManual);

$invalidManual = $base + array('manual_price_override_enabled' => true, 'manual_price_pln' => '0');
$invalidReady = $readiness->invoke($plugin, $invalidManual, 'imported_from_gmail', 0);
assert_true($invalidReady['status'] === 'needs_review', 'Invalid manual price should block readiness when Allegro is absent.', $invalidReady);

$allegro = $base + array(
    'allegro_price_research_status' => 'completed',
    'allegro_price_suggestion' => '1100.00',
    'allegro_price_currency' => 'PLN',
    'allegro_price_filtered_offer_count' => 5,
    'allegro_price_confidence' => 'high',
);
$selectedAllegro = $selectPrice->invoke($plugin, $allegro);
assert_true($selectedAllegro['source'] === 'allegro_api' && $selectedAllegro['price'] === '1100.00', 'Completed Allegro result should set selected Allegro API price when no manual override is enabled.', $selectedAllegro);
$allegroReady = $readiness->invoke($plugin, $allegro, 'imported_from_gmail', 0);
assert_true($allegroReady['status'] === 'ready_to_create_product', 'Completed valid Allegro research should allow Woo draft readiness.', $allegroReady);

$noMatch = $base + array(
    'allegro_price_research_status' => 'no_match',
    'allegro_price_suggestion' => '',
    'allegro_price_currency' => '',
    'allegro_price_filtered_offer_count' => 0,
    'allegro_price_confidence' => 'no_match',
);
assert_true($selectPrice->invoke($plugin, $noMatch) === null, 'Allegro no_match must not create a selected price.');

$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['allegro_readiness_min_confidence'] = 'medium';
$lowConfidence = $base + array(
    'allegro_price_research_status' => 'completed',
    'allegro_price_suggestion' => '1000.00',
    'allegro_price_currency' => 'PLN',
    'allegro_price_filtered_offer_count' => 1,
    'allegro_price_confidence' => 'low',
);
assert_true($selectPrice->invoke($plugin, $lowConfidence) === null, 'Allegro low confidence below configured threshold must block selected price.');
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['allegro_readiness_min_confidence'] = 'medium';

$GLOBALS['gps_test_posts'][60849] = (object) array('ID' => 60849, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_content' => 'Body');
foreach ($base as $key => $value) {
    if (is_array($value)) { continue; }
    $GLOBALS['gps_test_meta'][60849]['_gps_' . $key] = $value;
}
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_message_id'] = $base['message_id'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_thread_id'] = $base['thread_id'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_date'] = $base['date'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_from'] = $base['from'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_subject'] = $base['subject'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_label'] = $base['label'];
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_import_image_count'] = 2;
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_images_metadata'] = json_encode($base['images']);
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_warnings'] = '[]';
$GLOBALS['gps_test_meta'][60849]['_gps_ovoko_enrichment_status'] = 'suggested';
$GLOBALS['gps_test_meta'][60849]['_gps_staging_status'] = 'imported_from_gmail';
$GLOBALS['gps_test_meta'][60849]['_gps_gmail_created_product_id'] = 0;
$GLOBALS['gps_test_meta'][60849]['_gps_allegro_price_research_status'] = '';

$set = $setManual->invoke($plugin, 60849, array('manual_price_pln' => '1800', 'manual_price_note' => 'manual test price from Ovoko internal_notes / test flow'));
assert_true($set['result'] === 'saved_manual_price_override', 'Manual price action should save staging override.', $set);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_manual_price_override_enabled'] ?? '') === '1', 'Manual price override flag should be enabled.');
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_selected_price_source'] ?? '') === 'manual_override', 'Readiness validation should persist selected manual price source.');
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_allegro_price_research_status'] ?? 'sentinel') === '', 'Manual price must not mark Allegro research completed.');

$created = $createDraft->invoke($plugin, 60849);
$productId = (int) ($created['created_product_id'] ?? 0);
assert_true($created['result'] === 'created_product' && $productId > 0, 'Woo draft should be created from manual-price-ready item.', $created);
assert_true(($GLOBALS['gps_test_meta'][$productId]['_regular_price'] ?? '') === '1800' && ($GLOBALS['gps_test_meta'][$productId]['_price'] ?? '') === '1800', 'Created draft should use selected manual price.', $GLOBALS['gps_test_meta'][$productId] ?? array());
assert_true(($GLOBALS['gps_test_meta'][$productId]['_gps_selected_price_source'] ?? '') === 'manual_override' && ($GLOBALS['gps_test_meta'][$productId]['_gps_source_staging_item_id'] ?? 0) === 60849, 'Created draft should store staging and price source metadata.', $GLOBALS['gps_test_meta'][$productId] ?? array());
assert_true(($GLOBALS['gps_test_meta'][$productId]['_gps_suggested_woo_category_id'] ?? 0) === 5802 && ($GLOBALS['gps_test_meta'][$productId]['_gps_suggested_woo_category_path'] ?? '') === 'Filtr cząstek stałych Katalizator / FAP / DPF' && ($GLOBALS['gps_test_meta'][$productId]['_gps_suggested_category_source'] ?? '') === 'ovoko_enrichment', 'Created draft should keep suggested category metadata.', $GLOBALS['gps_test_meta'][$productId] ?? array());
$categoryAssignments = array_values(array_filter($GLOBALS['gps_test_terms'], function ($item) use ($productId) {
    return (int) $item['object_id'] === $productId && $item['taxonomy'] === 'product_cat';
}));
assert_true(count($categoryAssignments) === 1 && $categoryAssignments[0]['terms'] === array(5802) && $categoryAssignments[0]['append'] === false, 'Created draft should assign mapped Woo product_cat term.', $categoryAssignments);

$invalidItemId = 60851;
$GLOBALS['gps_test_posts'][$invalidItemId] = (object) array('ID' => $invalidItemId, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_content' => 'Body');
foreach ($base as $key => $value) {
    if (is_array($value)) { continue; }
    $GLOBALS['gps_test_meta'][$invalidItemId]['_gps_' . $key] = $value;
}
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_message_id'] = 'gmail-60851';
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_thread_id'] = $base['thread_id'];
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_date'] = $base['date'];
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_from'] = $base['from'];
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_subject'] = $base['subject'];
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_label'] = $base['label'];
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_import_image_count'] = 2;
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_images_metadata'] = json_encode($base['images']);
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_warnings'] = '[]';
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_ovoko_enrichment_status'] = 'suggested';
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_staging_status'] = 'imported_from_gmail';
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_created_product_id'] = 0;
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_suggested_woo_category_id'] = 999999;
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_manual_price_override_enabled'] = '1';
$GLOBALS['gps_test_meta'][$invalidItemId]['_gps_manual_price_pln'] = '1800';
$invalidCreated = $createDraft->invoke($plugin, $invalidItemId);
assert_true($invalidCreated['result'] === 'blocked' && in_array('invalid_category_term', $invalidCreated['readiness']['blocking_reasons'] ?? array(), true), 'Invalid mapped category term should block Woo draft creation.', $invalidCreated);
assert_true(absint($GLOBALS['gps_test_meta'][$invalidItemId]['_gps_gmail_created_product_id'] ?? 0) === 0, 'Invalid mapped category term must not create or link a product.', $GLOBALS['gps_test_meta'][$invalidItemId] ?? array());

$allegroItemId = 60852;
$GLOBALS['gps_test_posts'][$allegroItemId] = (object) array('ID' => $allegroItemId, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_content' => 'Body');
foreach ($base as $key => $value) {
    if (is_array($value)) { continue; }
    $GLOBALS['gps_test_meta'][$allegroItemId]['_gps_' . $key] = $value;
}
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_message_id'] = 'gmail-60852';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_thread_id'] = $base['thread_id'];
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_date'] = $base['date'];
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_from'] = $base['from'];
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_subject'] = $base['subject'];
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_label'] = $base['label'];
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_import_image_count'] = 2;
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_images_metadata'] = json_encode($base['images']);
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_warnings'] = '[]';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_ovoko_enrichment_status'] = 'suggested';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_staging_status'] = 'imported_from_gmail';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_gmail_created_product_id'] = 0;
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_research_status'] = 'completed';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_query'] = '5Q0131701AN';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_filtered_offer_count'] = 7;
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_confidence'] = 'high';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_suggestion'] = '1800.00';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_currency'] = 'PLN';
$GLOBALS['gps_test_meta'][$allegroItemId]['_gps_allegro_price_source'] = 'allegro_api';
$createdAllegro = $createDraft->invoke($plugin, $allegroItemId);
$allegroProductId = (int) ($createdAllegro['created_product_id'] ?? 0);
assert_true($createdAllegro['result'] === 'created_product' && $allegroProductId > 0, 'Woo draft should be created from Allegro-price-ready item.', $createdAllegro);
assert_true(($GLOBALS['gps_test_meta'][$allegroProductId]['_regular_price'] ?? '') === '1800.00' && ($GLOBALS['gps_test_meta'][$allegroProductId]['_price'] ?? '') === '1800.00', 'Woo draft should use Allegro selected price.', $GLOBALS['gps_test_meta'][$allegroProductId] ?? array());
assert_true(($GLOBALS['gps_test_meta'][$allegroProductId]['_gps_selected_price_source'] ?? '') === 'allegro_api', 'Woo draft should store Allegro API selected price source.', $GLOBALS['gps_test_meta'][$allegroProductId] ?? array());
assert_true(($GLOBALS['gps_test_meta'][$allegroProductId]['_gps_allegro_price_query'] ?? '') === '5Q0131701AN' && ($GLOBALS['gps_test_meta'][$allegroProductId]['_gps_allegro_price_filtered_offer_count'] ?? 0) == 7, 'Woo draft should copy Allegro research meta for Ovoko internal_notes.', $GLOBALS['gps_test_meta'][$allegroProductId] ?? array());

echo "Manual price override and category assignment tests passed\n";
