<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

$GLOBALS['gps_test_meta'] = array();
$GLOBALS['gps_test_posts'] = array();
$GLOBALS['gps_test_filters'] = array();
$GLOBALS['gps_test_remote_calls'] = array();
$GLOBALS['gps_test_inserted_products'] = array();

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) {
    if ($name === 'gpswiss_ovoko_settings') {
        return array('rrr_api_base_url' => 'https://api.rrr.test', 'rrr_api_username' => 'u', 'rrr_api_password' => 'p', 'rrr_api_user_token' => 't');
    }
    return $default;
}
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $value), '-')); }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_kses_post($value) { return (string) $value; }
function current_time($type, $gmt = 0) { return '2026-06-08 12:00:00'; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat'; }
function get_term_by($field, $value, $taxonomy) {
    if ($taxonomy !== 'product_cat') { return false; }
    $map = array('Turbina' => 321, 'Filtr cząstek stałych Katalizator / FAP / DPF' => 123, 'Brake system' => 1);
    if ($field === 'slug') {
        foreach ($map as $name => $id) {
            if (sanitize_title($name) === $value) { return (object) array('term_id' => $id, 'name' => $name); }
        }
        return false;
    }
    return isset($map[$value]) ? (object) array('term_id' => $map[$value], 'name' => $value) : false;
}
function term_exists($term, $taxonomy = '') { return in_array((int) $term, array(1, 123, 321), true) && $taxonomy === 'product_cat' ? array('term_id' => (int) $term) : null; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { private $code; private $message; public function __construct($code, $message) { $this->code = $code; $this->message = $message; } public function get_error_message() { return $this->message; } public function get_error_data() { return array(); } }
function get_post($id) { return $GLOBALS['gps_test_posts'][$id] ?? null; }
function get_post_meta($id, $key = '', $single = false) {
    if ($key === '') { return array(); }
    return $GLOBALS['gps_test_meta'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][$id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['gps_test_meta'][$id][$key]); }
function apply_filters($tag, $value) {
    $args = func_get_args();
    if (!isset($GLOBALS['gps_test_filters'][$tag])) { return $value; }
    return call_user_func_array($GLOBALS['gps_test_filters'][$tag], array_slice($args, 1));
}
function wp_remote_post($url, $args = array()) {
    $GLOBALS['gps_test_remote_calls'][] = array('url' => $url, 'args' => $args);
    if ((strpos($url, '/get/part/category') !== false || strpos($url, '/get/parts/category') !== false || strpos($url, '/get/categories/suggest') !== false) && strpos($url, '06K145654L') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('status_code' => 'R200', 'data' => array('category_id' => '322', 'category_name' => 'Turbina'))));
    }
    if (strpos($url, '06K145654L') !== false || strpos($url, 'NOCAT') !== false) {
        return array('response' => array('code' => 200), 'body' => json_encode(array('status_code' => 'R200', 'data' => array())));
    }
    return array('response' => array('code' => 200), 'body' => json_encode(array('status_code' => 'R200', 'data' => array(array(
        'id' => 'match-1', 'name' => 'Filtr cząstek stałych Katalizator / FAP / DPF', 'manufacturer_code' => '5Q0131701AN', 'category_id' => '1407', 'internal_notes' => '1800 PLN'
    )))));
}
function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
function wp_insert_post($post, $wp_error = false) { $GLOBALS['gps_test_inserted_products'][] = $post; return 9999; }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

function assert_true($condition, $message, $context = null) {
    if (!$condition) { fwrite(STDERR, $message . PHP_EOL); if ($context !== null) { var_export($context); } exit(1); }
}
function seed_item($id, $code) {
    $GLOBALS['gps_test_posts'][$id] = (object) array('ID' => $id, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_content' => '', 'post_title' => $code);
    $GLOBALS['gps_test_meta'][$id] = array(
        '_gps_gmail_message_id' => 'gmail-' . $id,
        '_gps_gmail_subject' => '17KNS ' . $code,
        '_gps_storage_location' => '17KNS',
        '_gps_detected_part_code' => $code,
        '_gps_normalized_part_code' => $code,
        '_gps_detected_oem_part_number' => $code,
        '_gps_normalized_oem_part_number' => $code,
        '_gps_gmail_import_image_count' => 4,
        '_gps_gmail_images_metadata' => json_encode(array(array('attachment_id' => 'img-1'))),
        '_gps_staging_status' => 'imported_from_gmail',
        '_gps_gmail_created_product_id' => 0,
    );
}

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$full = $reflection->getMethod('run_full_preparation_for_staging_item');
$full->setAccessible(true);
$categorySuggestion = $reflection->getMethod('run_ovoko_category_suggestion_for_staging_item');
$categorySuggestion->setAccessible(true);
$networkParser = $reflection->getMethod('parse_ovoko_category_network_capture');
$networkParser->setAccessible(true);
$predictionDiagnostic = $reflection->getMethod('test_ovoko_category_prediction_by_code');
$predictionDiagnostic->setAccessible(true);

seed_item(60908, '06K145654L');
$result = $full->invoke($plugin, 60908);
assert_true($result['ovoko_enrichment_status'] === 'no_match', '60908-style item should keep no existing Ovoko match.', $result);
assert_true($result['ovoko_match_count'] === 0 && $result['ovoko_selected_match_id'] === '', 'Category suggestion must not create a fake selected match.', $result);
assert_true($result['ovoko_category_suggestion_status'] === 'completed' && $result['ovoko_category_suggestion_category_name'] === 'Turbina', 'Part-code category endpoint response should persist Turbina.', $result);
assert_true($result['ovoko_category_suggestion_confidence'] === 'high' && $result['ovoko_category_suggestion_source_type'] === 'api_candidate_code_lookup', 'Completed category suggestion must persist high confidence/source_type.', $result);
assert_true($result['category_mapping_status'] === 'mapped' && $result['suggested_woo_category_id'] === 321, 'Turbina suggestion should map to Woo category.', $result);
assert_true(($GLOBALS['gps_test_meta'][60908]['_gps_suggested_category_source'] ?? '') === 'ovoko_category_prediction_by_code', 'Mapping source should be category prediction by code.', $GLOBALS['gps_test_meta'][60908] ?? array());
assert_true($result['ovoko_price_suggestion_status'] === 'no_price' && $result['ovoko_price_suggestion_pln'] === '', 'Category suggestion must not create a price suggestion.', $result);
assert_true($result['selected_price_source'] === '' && $result['selected_price_pln'] === '', 'Selected price should remain empty without manual or Ovoko match price.', $result);
assert_true($result['woo_draft_blocking_reasons'] === array('missing_selected_price'), 'Readiness blockers should reduce to missing_selected_price only.', $result);
assert_true(empty($GLOBALS['gps_test_inserted_products']), 'Full preparation must not create Woo products.', $GLOBALS['gps_test_inserted_products']);
foreach ($GLOBALS['gps_test_remote_calls'] as $call) {
    assert_true(strpos($call['url'], '/crm/') === false && stripos($call['url'], 'allegro') === false, 'Full preparation must not call Ovoko write endpoints or Allegro.', $GLOBALS['gps_test_remote_calls']);
}
assert_true(count($GLOBALS['gps_test_remote_calls']) >= 1 && strpos($GLOBALS['gps_test_remote_calls'][0]['url'], '/v2/get/parts') !== false, 'Existing-part lookup should remain the first Ovoko call.', $GLOBALS['gps_test_remote_calls']);

$firstSummary = $result;
$secondSummary = $full->invoke($plugin, 60908);
assert_true($firstSummary['suggested_woo_category_id'] === $secondSummary['suggested_woo_category_id'] && $secondSummary['woo_draft_blocking_reasons'] === array('missing_selected_price'), 'Full preparation should be idempotent.', $secondSummary);

seed_item(60849, '5Q0131701AN');
$matched = $full->invoke($plugin, 60849);
assert_true($matched['ovoko_enrichment_status'] === 'suggested' && $matched['ovoko_match_count'] === 1, 'Existing Ovoko match should be selected.', $matched);
assert_true($matched['ovoko_price_suggestion_status'] === 'completed' && $matched['selected_price_pln'] === '1800', 'Existing Ovoko match should provide selected price.', $matched);
assert_true($matched['category_mapping_status'] === 'mapped' && $matched['suggested_woo_category_id'] === 123, 'Existing Ovoko match category should map.', $matched);
assert_true($matched['woo_draft_readiness_status'] === 'ready_to_create_product', 'Existing match with price/category should be ready.', $matched);

seed_item(70000, 'NOCAT');
unset($GLOBALS['gps_test_filters']['gps_gmail_product_importer_ovoko_category_suggestion_by_part_code']);
$noCat = $full->invoke($plugin, 70000);
assert_true($noCat['ovoko_enrichment_status'] === 'no_match' && $noCat['ovoko_category_suggestion_status'] === 'unavailable', 'No match and no category endpoint should be clear.', $noCat);
assert_true($noCat['category_mapping_status'] === 'missing_ovoko_category_resolution', 'No code-specific category suggestion should block on Ovoko category resolution, not manual mapping.', $noCat);
assert_true(in_array('missing_selected_price', $noCat['woo_draft_blocking_reasons'], true) && in_array('missing_ovoko_category_resolution', $noCat['woo_draft_blocking_reasons'], true), 'No match/no category should leave price and category blockers.', $noCat);

$GLOBALS['gps_test_filters']['gps_gmail_product_importer_ovoko_category_suggestion_by_part_code'] = function($default, $code) {
    return $code === '06K145654L' ? array('status' => 'completed', 'category_id' => '322', 'category_name' => 'Turbina', 'source_type' => 'panel_network_capture', 'confidence' => 'high', 'source' => 'test_fixture_panel_network_capture', 'raw_response' => array('category' => 'Turbina')) : $default;
};

seed_item(60909, '06K145654L');
$GLOBALS['gps_test_meta'][60909]['_gps_suggested_woo_category_id'] = 1;
$GLOBALS['gps_test_meta'][60909]['_gps_suggested_woo_category_path'] = 'Brake system';
$GLOBALS['gps_test_meta'][60909]['_gps_suggested_woo_category_confidence'] = 'medium';
$GLOBALS['gps_test_meta'][60909]['_gps_suggested_category_source'] = 'ovoko_category_prediction_by_code';
$GLOBALS['gps_test_filters']['gps_gmail_product_importer_ovoko_category_suggestion_by_part_code'] = function($default, $code) {
    return $code === '06K145654L' ? array('status' => 'completed', 'category_id' => '1', 'category_name' => 'Brake system', 'source_type' => 'category_tree_fallback', 'confidence' => 'low', 'source' => 'rrr_categories_tree', 'raw_response' => array('category_id' => '1', 'category_name' => 'Brake system')) : $default;
};
$wrongBrake = $full->invoke($plugin, 60909);
assert_true($wrongBrake['ovoko_category_suggestion_status'] === 'no_code_specific_suggestion', 'Weak category-tree fallback must be downgraded, not completed.', $wrongBrake);
assert_true($wrongBrake['ovoko_category_suggestion_confidence'] === 'none' && $wrongBrake['ovoko_category_suggestion_category_id'] === '' && $wrongBrake['ovoko_category_suggestion_category_name'] === '', 'Weak Brake system fallback must not persist a category.', $wrongBrake);
assert_true($wrongBrake['category_mapping_status'] === 'missing_ovoko_category_resolution' && $wrongBrake['suggested_woo_category_id'] === 0, 'Weak Brake system fallback must not map or keep stale mapped category.', $wrongBrake);
assert_true(in_array('missing_ovoko_category_resolution', $wrongBrake['woo_draft_blocking_reasons'], true), 'Weak fallback should create missing_ovoko_category_resolution blocker.', $wrongBrake);

$GLOBALS['gps_test_filters']['gps_gmail_product_importer_ovoko_category_suggestion_by_part_code'] = function($default, $code) {
    return $code === '06K145654L' ? array('status' => 'completed', 'category_id' => '322', 'category_name' => 'Turbina', 'source_type' => 'panel_network_capture', 'confidence' => 'high', 'source' => 'test_fixture_panel_network_capture', 'raw_response' => array('category' => 'Turbina')) : $default;
};
$categoryOnly = $categorySuggestion->invoke($plugin, 60908, '06K145654L');
assert_true($categoryOnly['result'] === 'completed' && ($GLOBALS['gps_test_meta'][60908]['_gps_ovoko_raw_selected_match'] ?? '') === '', 'Standalone category suggestion must not write selected match meta.', $categoryOnly);



$diagnostic = $predictionDiagnostic->invoke($plugin, '06K145654L', 'Turbina');
assert_true($diagnostic['result'] === 'completed' && ($diagnostic['final_selected_category_source']['parsed_category_name'] ?? '') === 'Turbina', 'Prediction diagnostic should select the safe code-specific Turbina API candidate.', $diagnostic);
assert_true(!empty($diagnostic['sources_attempted']) && ($diagnostic['final_selected_category_source']['endpoint_classification'] ?? '') === 'api_candidate', 'Prediction diagnostic should report attempts and endpoint classification.', $diagnostic);

$captured = $networkParser->invoke($plugin, array('method' => 'POST', 'url' => 'https://api.rrr.test/v2/get/parts/category?manufacturer_code=06K145654L', 'body' => array('username' => 'u', 'password' => 'p', 'user_token' => 't')), array('category_id' => '322', 'category_name' => 'Turbina'));
assert_true($captured['status'] === 'completed' && $captured['endpoint_classification'] === 'official_api' && $captured['category_name'] === 'Turbina', 'Network capture parser should classify and extract a safe code-specific API category response.', $captured);
$panelCapture = $networkParser->invoke($plugin, "POST https://panel.ovoko.test/ajax/category/predict?manufacturer_code=06K145654L
Cookie: PHPSESSID=[redacted]", array('category_id' => '322', 'category_name' => 'Turbina'));
assert_true($panelCapture['status'] === 'no_code_specific_suggestion' && $panelCapture['endpoint_classification'] === 'panel_private' && !empty($panelCapture['requires_browser_session_cookies']), 'Panel-private captures must be classified but not automated silently.', $panelCapture);
$uncodedCapture = $networkParser->invoke($plugin, array('method' => 'POST', 'url' => 'https://api.rrr.test/get/categories/tree'), array('category_id' => '1', 'category_name' => 'Brake system'));
assert_true($uncodedCapture['status'] === 'no_code_specific_suggestion' && $uncodedCapture['confidence'] === 'none' && $uncodedCapture['category_id'] === '', 'Network capture parser must reject uncoded category-tree captures.', $uncodedCapture);

echo "Full preparation/category suggestion tests passed\n";
