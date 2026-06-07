<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $default; }
function absint($value) { return abs((int) $value); }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$readiness = $reflection->getMethod('readiness_status');
$readiness->setAccessible(true);

$baseReadyAnalysis = array(
    'detected_part_code' => '5Q0131701AN',
    'normalized_part_code' => '5Q0131701AN',
    'detected_oem_part_number' => '5Q0131701AN',
    'normalized_oem_part_number' => '5Q0131701AN',
    'image_attachments_found' => 2,
    'images' => array(array('attachment_id' => 'img-1')),
    'ovoko_enrichment_status' => 'suggested',
    'allegro_price_research_status' => 'researched',
    'allegro_price_suggestion' => '199.99',
    'allegro_price_currency' => 'PLN',
    'allegro_price_filtered_offer_count' => 5,
    'allegro_price_confidence' => 'high',
    'category_mapping_status' => 'mapped',
    'suggested_woo_category_id' => 123,
    'suggested_woo_category_confidence' => 'medium',
    'suggested_category_source' => 'ovoko_enrichment',
    'shipping_group' => 'shipping_30',
);

$ready = $readiness->invoke($plugin, $baseReadyAnalysis, 'imported_from_gmail', 0);
if ($ready['status'] !== 'ready_to_create_product' || $ready['blocking_reasons'] !== array()) {
    fwrite(STDERR, 'Complete minimum data should be ready_to_create_product with no blockers.' . PHP_EOL);
    var_export($ready);
    exit(1);
}

$staleReadyItem = $baseReadyAnalysis;
$staleReadyItem['ovoko_enrichment_status'] = '';
$staleReadyItem['allegro_price_research_status'] = '';
$staleReadyItem['allegro_price_suggestion'] = '';
$staleReadyItem['allegro_price_currency'] = '';
$staleReadyItem['category_mapping_status'] = '';
$staleReadyItem['suggested_woo_category_id'] = 0;
$staleReadyItem['suggested_woo_category_confidence'] = 'low';
$staleReadyItem['suggested_category_source'] = 'none';
$staleReadyItem['shipping_group'] = '';

$notReady = $readiness->invoke($plugin, $staleReadyItem, 'imported_from_gmail', 0);
$expectedBlockers = array('missing_ovoko_enrichment', 'missing_allegro_price_research', 'missing_category_mapping', 'missing_shipping_group');
if ($notReady['status'] !== 'needs_review') {
    fwrite(STDERR, 'Missing enrichment/mapping/shipping should force needs_review.' . PHP_EOL);
    var_export($notReady);
    exit(1);
}
foreach ($expectedBlockers as $blocker) {
    if (!in_array($blocker, $notReady['blocking_reasons'], true)) {
        fwrite(STDERR, sprintf('Expected blocker %s was missing.%s', $blocker, PHP_EOL));
        var_export($notReady);
        exit(1);
    }
}

$created = $readiness->invoke($plugin, $baseReadyAnalysis, 'imported_from_gmail', 999);
if ($created['status'] !== 'needs_review' || !in_array('product_already_created', $created['blocking_reasons'], true)) {
    fwrite(STDERR, 'Existing created product ID must block readiness.' . PHP_EOL);
    var_export($created);
    exit(1);
}

echo "Readiness validation tests passed\n";
