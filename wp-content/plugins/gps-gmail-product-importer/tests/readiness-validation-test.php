<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
$GLOBALS['gps_test_options'] = array();
function get_option($name, $default = false) { return $GLOBALS['gps_test_options'][$name] ?? $default; }
function absint($value) { return abs((int) $value); }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat'; }
function term_exists($term, $taxonomy = '') { return ((int) $term === 123 && $taxonomy === 'product_cat') ? array('term_id' => 123, 'term_taxonomy_id' => 123) : null; }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$readiness = $reflection->getMethod('readiness_status');
$readiness->setAccessible(true);
$wooReadiness = $reflection->getMethod('woo_draft_readiness_status');
$wooReadiness->setAccessible(true);
$marketplaceReadiness = $reflection->getMethod('marketplace_readiness_status');
$marketplaceReadiness->setAccessible(true);

$baseReadyAnalysis = array(
    'staging_item_id' => 60849,
    'message_id' => 'gmail-60849',
    'detected_part_code' => '5Q0131701AN',
    'normalized_part_code' => '5Q0131701AN',
    'detected_oem_part_number' => '5Q0131701AN',
    'normalized_oem_part_number' => '5Q0131701AN',
    'image_attachments_found' => 2,
    'images' => array(array('attachment_id' => 'img-1')),
    'ovoko_enrichment_status' => 'suggested',
    'ovoko_price_suggestion_status' => 'completed',
    'ovoko_price_suggestion_pln' => '199.99',
    'ovoko_price_suggestion_currency' => 'PLN',
    'ovoko_price_suggestion_source' => 'ovoko_internal_notes',
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
$staleReadyItem['ovoko_price_suggestion_status'] = '';
$staleReadyItem['ovoko_price_suggestion_pln'] = '';
$staleReadyItem['ovoko_price_suggestion_currency'] = '';
$staleReadyItem['category_mapping_status'] = '';
$staleReadyItem['suggested_woo_category_id'] = 0;
$staleReadyItem['suggested_woo_category_confidence'] = 'low';
$staleReadyItem['suggested_category_source'] = 'none';
$staleReadyItem['shipping_group'] = '';

$notReady = $readiness->invoke($plugin, $staleReadyItem, 'imported_from_gmail', 0);
$expectedBlockers = array('missing_ovoko_enrichment', 'missing_selected_price', 'missing_category_mapping');
if ($notReady['status'] !== 'needs_review') {
    fwrite(STDERR, 'Missing enrichment/price/mapping should force Woo draft needs_review.' . PHP_EOL);
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


$emptyShippingGroup = $baseReadyAnalysis;
$emptyShippingGroup['shipping_group'] = '';
$wooReadyWithoutShipping = $wooReadiness->invoke($plugin, $emptyShippingGroup, 'imported_from_gmail', 0);
if ($wooReadyWithoutShipping['status'] !== 'ready_to_create_product' || in_array('missing_shipping_group', $wooReadyWithoutShipping['blocking_reasons'], true)) {
    fwrite(STDERR, 'Empty shipping group must not block Woo draft readiness.' . PHP_EOL);
    var_export($wooReadyWithoutShipping);
    exit(1);
}
$marketplaceBlockedWithoutShipping = $marketplaceReadiness->invoke($plugin, $emptyShippingGroup, 'imported_from_gmail', 0);
if ($marketplaceBlockedWithoutShipping['status'] !== 'needs_review' || !in_array('missing_shipping_group', $marketplaceBlockedWithoutShipping['blocking_reasons'], true)) {
    fwrite(STDERR, 'Empty shipping group should still block marketplace readiness.' . PHP_EOL);
    var_export($marketplaceBlockedWithoutShipping);
    exit(1);
}


$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['fixed_ovoko_import_category_enabled'] = 1;
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['fixed_ovoko_import_category_id'] = '278';
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['fixed_ovoko_import_category_name'] = 'Turbina';
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['allow_empty_price_for_fixed_category_crm_only_import'] = 1;

$fixedNoPrice = $baseReadyAnalysis;
$fixedNoPrice['staging_item_id'] = 60908;
$fixedNoPrice['ovoko_enrichment_status'] = '';
$fixedNoPrice['ovoko_price_suggestion_status'] = 'no_price';
$fixedNoPrice['ovoko_price_suggestion_pln'] = '';
$fixedNoPrice['ovoko_price_suggestion_currency'] = '';
$fixedNoPrice['category_mapping_status'] = 'missing_ovoko_category_resolution';
$fixedNoPrice['suggested_woo_category_id'] = 0;
$fixedNoPrice['suggested_woo_category_confidence'] = '';
$fixedNoPrice['suggested_category_source'] = '';
$fixedNoPrice['shipping_group'] = '';
$fixedReady = $wooReadiness->invoke($plugin, $fixedNoPrice, 'imported_from_gmail', 0);
if ($fixedReady['status'] !== 'ready_to_create_product' || $fixedReady['blocking_reasons'] !== array()) {
    fwrite(STDERR, 'Fixed category + empty price allowed should let item 60908 become ready_to_create_product without price/category mapping/enrichment.' . PHP_EOL);
    var_export($fixedReady);
    exit(1);
}
$fixedMarketplace = $marketplaceReadiness->invoke($plugin, $fixedNoPrice, 'imported_from_gmail', 0);
if ($fixedMarketplace['status'] !== 'needs_review' || !in_array('missing_shipping_group', $fixedMarketplace['blocking_reasons'], true)) {
    fwrite(STDERR, 'Fixed category Woo readiness must not remove marketplace-specific blockers.' . PHP_EOL);
    var_export($fixedMarketplace);
    exit(1);
}

$created = $readiness->invoke($plugin, $baseReadyAnalysis, 'imported_from_gmail', 999);
if ($created['status'] !== 'needs_review' || !in_array('product_already_created', $created['blocking_reasons'], true)) {
    fwrite(STDERR, 'Existing created product ID must block readiness.' . PHP_EOL);
    var_export($created);
    exit(1);
}

echo "Readiness validation tests passed\n";
