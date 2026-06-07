<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

$GLOBALS['gps_test_options'] = array();
$GLOBALS['gps_test_meta'] = array();

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $GLOBALS['gps_test_options'][$name] ?? $default; }
function update_option($name, $value, $autoload = null) { $GLOBALS['gps_test_options'][$name] = $value; return true; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function esc_url_raw($value) { return (string) $value; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags); }
function current_time($type, $gmt = 0) { return '2026-06-07 11:37:28'; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_test_meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][$id][$key] = $value; return true; }
function is_wp_error($value) { return $value instanceof WP_Error; }

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '', $data = null) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$analyze = $reflection->getMethod('analyze_allegro_price_offers');
$analyze->setAccessible(true);
$run = $reflection->getMethod('run_allegro_price_research_for_staging_item');
$run->setAccessible(true);
$readiness = $reflection->getMethod('readiness_status');
$readiness->setAccessible(true);

function offer($id, $title, $price) {
    return array(
        'id' => (string) $id,
        'name' => $title,
        'sellingMode' => array('price' => array('amount' => (string) $price, 'currency' => 'PLN')),
    );
}
function assert_true($condition, $message, $payload = null) {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        if ($payload !== null) { var_export($payload); }
        exit(1);
    }
}

$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();
$result = $run->invoke($plugin, 60849);
assert_true($result['result'] === 'not_configured', 'Missing/disabled Allegro settings should keep not_configured.', $result);
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_allegro_price_research_status'] ?? '') === 'not_configured', 'Not configured status meta was not persisted.');
assert_true(($GLOBALS['gps_test_meta'][60849]['_gps_allegro_price_suggestion'] ?? 'sentinel') === '', 'Not configured state must not write a suggestion.');

$settings = GPS_Gmail_Product_Importer::default_settings();
$settings['allegro_min_filtered_offer_count'] = 5;
$settings['allegro_readiness_min_confidence'] = 'medium';

$noMatch = $analyze->invoke($plugin, array('5Q0131701AN'), array(offer(1, 'filtr DPF 03L131512DQ', '900.00')), $settings);
assert_true($noMatch['status'] === 'no_match' && $noMatch['filtered_offer_count'] === 0 && $noMatch['confidence'] === 'no_match', 'Irrelevant offers should produce no_match.', $noMatch);

$one = $analyze->invoke($plugin, array('5Q0131701AN'), array(offer(2, 'Filtr DPF katalizator 5Q0131701AN', '1200.00')), $settings);
assert_true($one['status'] === 'completed' && $one['filtered_offer_count'] === 1 && $one['confidence'] === 'low' && $one['suggestion'] === '1200.00', 'One exact-priced match should be low-confidence completed research.', $one);

$many = $analyze->invoke($plugin, array('5Q0131701AN'), array(
    offer(3, 'Filtr DPF katalizator 5Q0131701AN', '900.00'),
    offer(4, '5Q0131701AN FAP DPF', '1100.00'),
    offer(5, 'Katalizator 5Q0131701AN VW', '1000.00'),
    offer(6, 'DPF 5Q0131701AN kompletny', '1300.00'),
    offer(7, 'Filtr 5Q0131701AN', '1200.00'),
    offer(8, 'Regeneracja 5Q0131701AN usługa', '300.00'),
), $settings);
assert_true($many['filtered_offer_count'] === 5 && $many['median_pln'] === '1100.00' && $many['min_pln'] === '900.00' && $many['max_pln'] === '1300.00' && $many['confidence'] === 'high', 'Multiple matches should filter repair services and compute median/min/max.', $many);

$baseReadyAnalysis = array(
    'message_id' => 'gmail-60849',
    'detected_part_code' => '5Q0131701AN',
    'normalized_part_code' => '5Q0131701AN',
    'detected_oem_part_number' => '5Q0131701AN',
    'normalized_oem_part_number' => '5Q0131701AN',
    'image_attachments_found' => 2,
    'images' => array(array('attachment_id' => 'img-1')),
    'ovoko_enrichment_status' => 'suggested',
    'category_mapping_status' => 'mapped',
    'suggested_woo_category_id' => 123,
    'suggested_woo_category_confidence' => 'medium',
    'suggested_category_source' => 'ovoko_enrichment',
    'shipping_group' => 'shipping_30',
);
$missingAllegro = $readiness->invoke($plugin, $baseReadyAnalysis, 'imported_from_gmail', 0);
assert_true(in_array('missing_allegro_price_research', $missingAllegro['blocking_reasons'], true), 'Readiness must be blocked when Allegro research is missing.', $missingAllegro);

$validAllegro = $baseReadyAnalysis + array(
    'allegro_price_research_status' => 'completed',
    'allegro_price_suggestion' => '1100.00',
    'allegro_price_currency' => 'PLN',
    'allegro_price_filtered_offer_count' => 5,
    'allegro_price_confidence' => 'high',
);
$ready = $readiness->invoke($plugin, $validAllegro, 'imported_from_gmail', 0);
assert_true($ready['status'] === 'ready_to_create_product' && $ready['blocking_reasons'] === array(), 'Readiness should pass when completed Allegro research is valid.', $ready);

echo "Allegro price research tests passed\n";
