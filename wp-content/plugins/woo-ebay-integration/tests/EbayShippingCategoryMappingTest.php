<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/EbayShippingPolicyResolver.php';

use WEI\Services\EbayShippingPolicyResolver;

$GLOBALS['wei_test_product_terms'] = [];

if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return ''; }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy) { return $GLOBALS['wei_test_product_terms'][(int) $post_id] ?? []; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return false; }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$term = static fn(int $id): object => (object) ['term_id' => $id, 'name' => 'Woo ' . $id, 'slug' => 'woo-' . $id];

$settings = [
    'shipping_category_ids_30' => " 5063, 5066,5063,,\n5069,5122 ",
    'shipping_category_ids_50' => '6001,6002',
    'shipping_category_ids_130' => '7001,7002',
    'shipping_policy_30' => 'POLICY30',
    'shipping_policy_50' => 'POLICY50',
    'shipping_policy_130' => 'POLICY130',
];

$assert(EbayShippingPolicyResolver::normalize_id_list($settings['shipping_category_ids_30']) === '5063,5066,5069,5122', 'Settings parser must trim, ignore empty values, and de-duplicate comma-separated Woo category IDs.');
$conflictSettings = array_merge($settings, ['shipping_category_ids_50' => '6001,5063']);
$conflicts = EbayShippingPolicyResolver::conflict_ids($conflictSettings);
$assert(isset($conflicts[5063]), 'Conflicts must be detected when the same Woo category ID appears in multiple shipping groups.');

$GLOBALS['wei_test_product_terms'][1] = [$term(5063)];
$r = EbayShippingPolicyResolver::resolve_for_product(1, $settings);
$assert($r['group'] === EbayShippingPolicyResolver::GROUP_SHIPPING_30 && $r['policy_id'] === 'POLICY30', 'Product in Wysyłka 30 group must get 30 shipping policy.');

$GLOBALS['wei_test_product_terms'][2] = [$term(6002)];
$r = EbayShippingPolicyResolver::resolve_for_product(2, $settings);
$assert($r['group'] === EbayShippingPolicyResolver::GROUP_SHIPPING_50 && $r['policy_id'] === 'POLICY50', 'Product in Wysyłka 50 group must get 50 shipping policy.');

$GLOBALS['wei_test_product_terms'][3] = [$term(7002)];
$r = EbayShippingPolicyResolver::resolve_for_product(3, $settings);
$assert($r['group'] === EbayShippingPolicyResolver::GROUP_SHIPPING_130 && $r['policy_id'] === 'POLICY130', 'Product in Wysyłka 130 group must get 130 shipping policy.');

$GLOBALS['wei_test_product_terms'][4] = [$term(5066), $term(7001)];
$r = EbayShippingPolicyResolver::resolve_for_product(4, $settings);
$assert($r['group'] === EbayShippingPolicyResolver::GROUP_SHIPPING_130 && $r['matched_woo_category_id'] === 7001, 'Product matching 30 and 130 must get 130 due to priority.');

$GLOBALS['wei_test_product_terms'][5] = [$term(9999)];
$r = EbayShippingPolicyResolver::resolve_for_product(5, $settings + ['default_shipping_policy_id' => 'DEFAULTPOLICY']);
$assert($r['default_used'] === true && $r['policy_id'] === 'DEFAULTPOLICY', 'Product with no matching Woo category must use default shipping policy when configured.');

$r = EbayShippingPolicyResolver::resolve_for_product(5, $settings);
$assert($r['blocked'] === true && $r['reason'] === 'missing_shipping_policy_mapping', 'Product with no matching Woo category and no default must be blocked by missing_shipping_policy_mapping.');

$ebayCategoryOnlySettings = $settings;
$ebayCategoryOnlySettings['default_shipping_policy_id'] = '';
$ebayCategoryOnlySettings['shipping_category_ids_30'] = '';
$ebayCategoryOnlySettings['shipping_category_ids_50'] = '';
$ebayCategoryOnlySettings['shipping_category_ids_130'] = '';
$ebayCategoryOnlySettings['default_category_id'] = '5063';
$GLOBALS['wei_test_product_terms'][6] = [$term(9998)];
$r = EbayShippingPolicyResolver::resolve_for_product(6, $ebayCategoryOnlySettings);
$assert($r['blocked'] === true && $r['reason'] === 'missing_shipping_policy_mapping', 'Old eBay/default category IDs must not be used for shipping category mapping.');

$adminSource = file_get_contents(__DIR__ . '/../src/Services/AdminPage.php');
$adapterSource = file_get_contents(__DIR__ . '/../src/Adapters/EbayAdapter.php');
$viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
$resolverSource = file_get_contents(__DIR__ . '/../src/Services/EbayShippingPolicyResolver.php');
$assert(str_contains($viewSource, 'Select exact Wysyłka 30 fulfillment policy') && str_contains($viewSource, 'fulfillmentPolicyId'), 'Settings UI must allow selecting the exact existing eBay fulfillment policy ID/name.');
$assert(str_contains($viewSource, 'duplicate names are disambiguated by policy ID'), 'Settings UI must make duplicate eBay policy names selectable by exact policy ID.');
$assert(!str_contains($resolverSource, '259264150013') && !str_contains($resolverSource, '259677066013') && !str_contains($resolverSource, '259636579013'), 'Shipping resolver must not hardcode eBay fulfillment policy IDs.');
$assert(!str_contains($resolverSource, 'shipping_category_ids_100_eur'), 'Shipping resolver must not use old 100 EUR/pre-Ovoko shipping category settings.');

$diagnosticsStart = strpos($adminSource, 'public function shipping_mapping_diagnostics');
$diagnosticsEnd = strpos($adminSource, 'public function generate_shipping_mapping_report', $diagnosticsStart);
$diagnosticsSource = $diagnosticsStart !== false && $diagnosticsEnd !== false ? substr($adminSource, $diagnosticsStart, $diagnosticsEnd - $diagnosticsStart) : '';
$assert($diagnosticsSource !== '' && !str_contains($diagnosticsSource, 'client->'), 'Diagnostics must not call eBay API.');
$assert(!str_contains($diagnosticsSource, 'update_post_meta') && !str_contains($diagnosticsSource, 'create_or_replace') && !str_contains($diagnosticsSource, 'publish'), 'Diagnostics must not modify products/listings or publish.');
$assert(str_contains($adapterSource, 'missing_shipping_policy_mapping') && str_contains($adapterSource, 'blocked_by_shipping_policy'), 'Publish readiness must block missing or invalid shipping policy mapping.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "eBay Woo shipping category mapping tests passed\n";
