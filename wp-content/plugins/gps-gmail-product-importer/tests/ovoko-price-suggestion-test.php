<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');
$GLOBALS['gps_test_options'] = array();
$GLOBALS['gps_test_meta'] = array();
$GLOBALS['gps_test_posts'] = array();
$GLOBALS['gps_test_next_product_id'] = 9001;
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
function current_time($type, $gmt = 0) { return '2026-06-08 10:00:00'; }
function get_current_user_id() { return 42; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_test_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][$id][$key] = $value; return true; }
function get_post($id) { return $GLOBALS['gps_test_posts'][$id] ?? null; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wc_get_product($id = null) { return true; }
function wp_insert_post($args, $wp_error = false) { $id = $GLOBALS['gps_test_next_product_id']++; $GLOBALS['gps_test_posts'][$id] = (object) ($args + array('ID' => $id)); return $id; }
function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) { return true; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat' || $taxonomy === 'product_type'; }
function term_exists($term, $taxonomy = '') { return ($taxonomy === 'product_cat' && !empty($GLOBALS['gps_test_valid_product_cat_terms'][(int) $term])) ? array('term_id' => (int) $term) : null; }
function has_post_thumbnail($product_id) { return false; }

class WP_Error { private $message; public function __construct($code = '', $message = '', $data = null) { $this->message = $message; } public function get_error_message() { return $this->message; } }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['import_images'] = 0;

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
foreach (array('extract_ovoko_price_suggestion', 'selected_price_for_analysis', 'run_ovoko_price_suggestion_for_staging_item', 'create_product_from_analysis') as $methodName) {
    ${$methodName} = $reflection->getMethod($methodName);
    ${$methodName}->setAccessible(true);
}

function assert_true($condition, $message, $payload = null) {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); if ($payload !== null) { var_export($payload); } exit(1); }
}

$settings = GPS_Gmail_Product_Importer::default_settings();
$checkedAt = '2026-06-08 10:00:00';
$item60849 = array('price' => '564.71', 'currency' => 'EUR', 'original_price' => '2400', 'original_currency' => 'PLN', 'internal_notes' => '1800');
$result = $extract_ovoko_price_suggestion->invoke($plugin, $item60849, $settings, $checkedAt);
assert_true($result['status'] === 'completed' && $result['pln'] === '1800' && $result['source'] === 'ovoko_internal_notes', 'Ovoko internal_notes numeric value should become 1800 PLN.', $result);

$result = $extract_ovoko_price_suggestion->invoke($plugin, array('original_price' => '2400', 'original_currency' => 'PLN', 'price' => '564.71', 'currency' => 'EUR'), $settings, $checkedAt);
assert_true($result['status'] === 'completed' && $result['pln'] === '2400' && $result['source'] === 'ovoko_original_price', 'Ovoko original_price PLN fallback should work.', $result);

$result = $extract_ovoko_price_suggestion->invoke($plugin, array('price' => '100', 'currency' => 'EUR'), $settings, $checkedAt);
assert_true($result['status'] === 'needs_conversion' && $result['currency'] === 'EUR', 'Ovoko EUR price should need conversion without a configured rate.', $result);

$settings['ovoko_eur_to_pln_fallback_rate'] = '4.5';
$result = $extract_ovoko_price_suggestion->invoke($plugin, array('price' => '100', 'currency' => 'EUR'), $settings, $checkedAt);
assert_true($result['status'] === 'completed' && $result['pln'] === '450' && $result['source'] === 'ovoko_price_eur_converted', 'Ovoko EUR price should convert when rate exists.', $result);

$ovokoAnalysis = array('ovoko_price_suggestion_status' => 'completed', 'ovoko_price_suggestion_pln' => '1800', 'ovoko_price_suggestion_currency' => 'PLN');
$selected = $selected_price_for_analysis->invoke($plugin, $ovokoAnalysis + array('manual_price_override_enabled' => true, 'manual_price_pln' => '1900'));
assert_true($selected['source'] === 'manual_override' && $selected['price'] === '1900', 'Manual override must win over Ovoko suggestion.', $selected);
$selected = $selected_price_for_analysis->invoke($plugin, $ovokoAnalysis + array('allegro_price_research_status' => 'completed', 'allegro_price_suggestion' => '2000', 'allegro_price_currency' => 'PLN', 'allegro_price_filtered_offer_count' => 9));
assert_true($selected['source'] === 'ovoko_price_suggestion' && $selected['price'] === '1800', 'Ovoko suggestion must win because Allegro is not part of production pricing.', $selected);
$selected = $selected_price_for_analysis->invoke($plugin, array('allegro_price_research_status' => 'completed', 'allegro_price_suggestion' => '2000', 'allegro_price_currency' => 'PLN', 'allegro_price_filtered_offer_count' => 9));
assert_true($selected === null, 'Allegro alone must not create a selected price.', $selected);
$selected = $selected_price_for_analysis->invoke($plugin, $ovokoAnalysis + array('allegro_price_research_status' => 'error', 'allegro_price_error_http_status' => 403, 'allegro_price_error_code' => 'AccessDenied'));
assert_true($selected['source'] === 'ovoko_price_suggestion' && $selected['price'] === '1800', 'Allegro AccessDenied must not affect Ovoko selected price.', $selected);
$selected = $selected_price_for_analysis->invoke($plugin, array());
assert_true($selected === null, 'No manual override and no Ovoko suggestion should leave no selected price.', $selected);

$GLOBALS['gps_test_meta'][60849]['_gps_ovoko_raw_selected_match'] = wp_json_encode($item60849);
$run = $run_ovoko_price_suggestion_for_staging_item->invoke($plugin, 60849, true);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_ovoko_price_suggestion_status'] ?? '') === 'completed' && ($GLOBALS['gps_test_meta'][60849]['_gps_ovoko_price_suggestion_pln'] ?? '') === '1800', 'Item-scoped Ovoko price suggestion should persist completed 1800 PLN.', $GLOBALS['gps_test_meta'][60849]);

$analysis = array(
    'staging_item_id' => 60849, 'message_id' => 'gmail-60849', 'thread_id' => 'thread-1', 'date' => '2026-06-08', 'from' => 'sender@example.com', 'subject' => 'DPF 5Q0131701AN', 'label' => 'Woo import', 'body' => 'Body', 'storage_location' => 'A1', 'detected_part_code' => '5Q0131701AN', 'normalized_part_code' => '5Q0131701AN', 'detected_oem_part_number' => '5Q0131701AN', 'normalized_oem_part_number' => '5Q0131701AN', 'detected_vehicle_make' => 'VW', 'detected_vehicle_model' => 'Golf', 'detected_vehicle_confidence' => 'high', 'image_attachments_found' => 0, 'image_attachment_set_hash' => 'hash', 'oem_candidates' => array(), 'warnings' => array(), 'images' => array(), 'suggested_woo_category_id' => 5802, 'suggested_woo_category_path' => 'DPF', 'category_mapping_status' => 'mapped', 'suggested_woo_category_confidence' => 'medium', 'suggested_category_source' => 'ovoko_enrichment',
    'selected_price_source' => 'ovoko_price_suggestion', 'ovoko_price_suggestion_status' => 'completed', 'ovoko_price_suggestion_pln' => '1800', 'ovoko_price_suggestion_currency' => 'PLN', 'ovoko_price_suggestion_source' => 'ovoko_internal_notes'
);
$created = $create_product_from_analysis->invoke($plugin, $analysis, null);
$productId = is_array($created) ? (int) ($created['product_id'] ?? 0) : 0;
assert_true($productId > 0 && ($GLOBALS['gps_test_meta'][$productId]['_regular_price'] ?? '') === '1800' && ($GLOBALS['gps_test_meta'][$productId]['_price'] ?? '') === '1800' && ($GLOBALS['gps_test_meta'][$productId]['_gps_selected_price_source'] ?? '') === 'ovoko_price_suggestion', 'Woo draft should use Ovoko selected price when no manual or Allegro exists.', $GLOBALS['gps_test_meta'][$productId] ?? array());

echo "PASS ovoko-price-suggestion-test\n";
