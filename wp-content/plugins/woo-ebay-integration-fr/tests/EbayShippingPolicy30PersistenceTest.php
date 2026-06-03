<?php

declare(strict_types=1);

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string { return trim((string) $value); }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key = '', $single = false) { return ''; }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy) { return $GLOBALS['wei_fr_test_product_terms'][(int) $post_id] ?? []; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return false; }
}

require_once __DIR__ . '/../src/Services/EbayShippingPolicyResolver.php';
require_once __DIR__ . '/../src/Services/AdminPage.php';

use WEI_FR\Services\AdminPage;
use WEI_FR\Services\EbayShippingPolicyResolver;

$GLOBALS['wei_fr_test_product_terms'] = [];

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$term = static fn(int $id): object => (object) ['term_id' => $id, 'name' => 'Woo ' . $id, 'slug' => 'woo-' . $id];

$expectedPolicy30 = '259264150013';

$postedSelect = [
    'shipping_policy_30' => $expectedPolicy30,
    'shipping_policy_30_name' => 'Wysyłka 30',
    'default_shipping_policy' => $expectedPolicy30,
];
$saved = [
    'shipping_policy_30' => AdminPage::posted_shipping_policy_id($postedSelect, 'shipping_policy_30'),
    'shipping_policy_name_30' => AdminPage::posted_shipping_policy_name($postedSelect, 'shipping_policy_30'),
    'default_shipping_policy_id' => AdminPage::posted_shipping_policy_id($postedSelect, 'default_shipping_policy_id', ['default_shipping_policy']),
    'shipping_category_ids_30' => '5063',
];
$saved['shipping_policy_30_name'] = $saved['shipping_policy_name_30'];
$saved['default_shipping_policy'] = $saved['default_shipping_policy_id'];
$saved['fulfillment_policy_id_30_eur'] = $saved['shipping_policy_30'];

$assert($saved['shipping_policy_30'] === $expectedPolicy30, 'Wysyłka 30 selected fulfillment policy must save with shipping_policy_30.');
$assert($saved['shipping_policy_name_30'] === 'Wysyłka 30', 'Wysyłka 30 policy name alias shipping_policy_30_name must save.');
$assert($saved['default_shipping_policy_id'] === $expectedPolicy30, 'Default shipping policy selected with default_shipping_policy must save.');

$postedManual = [
    'shipping_policy_30_manual' => $expectedPolicy30,
    'shipping_policy_30' => '',
    'default_shipping_policy_manual' => $expectedPolicy30,
];
$assert(AdminPage::posted_shipping_policy_id($postedManual, 'shipping_policy_30') === $expectedPolicy30, 'Wysyłka 30 manual policy ID fallback must save.');
$assert(AdminPage::posted_shipping_policy_id($postedManual, 'default_shipping_policy_id', ['default_shipping_policy']) === $expectedPolicy30, 'Default shipping manual policy ID fallback must save.');

$GLOBALS['wei_fr_test_product_terms'][30] = [$term(5063)];
$resolved30 = EbayShippingPolicyResolver::resolve_for_product(30, $saved);
$assert($resolved30['group'] === EbayShippingPolicyResolver::GROUP_SHIPPING_30, 'Resolver must match shipping_30 products to Wysyłka 30.');
$assert($resolved30['policy_id'] === $expectedPolicy30, 'Resolver must return Wysyłka 30 policy ID for shipping_30 products.');
$assert($resolved30['blocked'] === false && $resolved30['reason'] === '', 'shipping_30 policy resolution must not report missing_shipping_policy_mapping.');

$GLOBALS['wei_fr_test_product_terms'][31] = [$term(9999)];
$defaultResolved = EbayShippingPolicyResolver::resolve_for_product(31, $saved + ['shipping_category_ids_30' => '5063']);
$assert($defaultResolved['default_used'] === true, 'Resolver must use configured default shipping policy when no shipping group matches.');
$assert($defaultResolved['policy_id'] === $expectedPolicy30, 'Resolver must return Wysyłka 30 ID when default shipping policy is configured as Wysyłka 30.');
$assert($defaultResolved['blocked'] === false && $defaultResolved['reason'] === '', 'Default Wysyłka 30 policy resolution must not report missing_shipping_policy_mapping.');

$viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
$assert(str_contains($viewSource, "\$renderFulfillmentPolicyControl('shipping_policy_30'"), 'UI form must post shipping_policy_30.');
$assert(str_contains($viewSource, "\$field . '_manual'"), 'UI form must post shipping_policy_30_manual.');
$assert(str_contains($viewSource, 'name="shipping_policy_30_name"'), 'UI form must post shipping_policy_30_name.');
$assert(str_contains($viewSource, "\$renderFulfillmentPolicyControl('default_shipping_policy'"), 'UI form must post default_shipping_policy.');
$assert(str_contains($viewSource, "\$field . '_manual'"), 'UI form must post default_shipping_policy_manual.');
$assert(str_contains($viewSource, 'Current saved ID: <code><?php echo esc_html($selectedId); ?></code>'), 'UI must render Current saved ID after saving/reload.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "eBay Wysyłka 30 persistence regression tests passed\n";
