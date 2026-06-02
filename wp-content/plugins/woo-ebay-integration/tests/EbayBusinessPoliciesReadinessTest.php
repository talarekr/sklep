<?php

declare(strict_types=1);

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string { return trim((string) $value); }
}

require_once __DIR__ . '/../src/Interfaces/MarketplaceAdapterInterface.php';
require_once __DIR__ . '/../src/Services/EbayShippingPolicyResolver.php';
require_once __DIR__ . '/../src/Adapters/EbayAdapter.php';
require_once __DIR__ . '/../src/Services/AdminPage.php';

use WEI\Adapters\EbayAdapter;
use WEI\Services\AdminPage;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$shipping130 = [
    'group' => 'shipping_130',
    'policy_id' => '259636579013',
    'policy_name' => '',
    'blocked' => false,
    'reason' => '',
];
$completeSettings = [
    'ebay_payment_policy_id' => '259264220013',
    'ebay_return_policy_id' => '259264151013',
    'inventory_location_key' => 'gpswiss-pl',
];

$r = EbayAdapter::business_policy_resolution_for_settings($completeSettings, $shipping130, 'gpswiss-pl');
$assert($r['selected_fulfillment_policy_id'] === '259636579013', 'Product 2088-style shipping_130 fulfillment policy ID must resolve.');
$assert($r['selected_fulfillment_policy_name'] === '', 'Empty fulfillment policy name is allowed in diagnostics.');
$assert($r['missing_fulfillment_policy'] === false, 'Fulfillment must not be missing when selected_fulfillment_policy_id is present even if name is empty.');
$assert($r['business_policy_problem_reason'] === '', 'All present business policy IDs and merchant location must not block shipping policy readiness.');
$assert($r['selected_payment_policy_id'] === '259264220013', 'Product 2088-style readiness must show paymentPolicyId 259264220013.');
$assert($r['selected_return_policy_id'] === '259264151013', 'Product 2088-style readiness must show returnPolicyId 259264151013.');
$assert($r['merchant_location_key'] === 'gpswiss-pl', 'Product 2088-style readiness must reuse merchantLocationKey gpswiss-pl.');
$assert($r['missing_payment_policy'] === false && $r['missing_return_policy'] === false && $r['missing_merchant_location'] === false, 'Product 2088-style readiness must show no missing business policy fields.');

$r = EbayAdapter::business_policy_resolution_for_settings(array_merge($completeSettings, ['ebay_payment_policy_id' => '']), $shipping130, 'gpswiss-pl');
$assert($r['missing_payment_policy'] === true && $r['business_policy_problem_reason'] === 'missing_payment_policy', 'Missing payment policy must block with missing_payment_policy.');

$r = EbayAdapter::business_policy_resolution_for_settings(array_merge($completeSettings, ['ebay_return_policy_id' => '']), $shipping130, 'gpswiss-pl');
$assert($r['missing_return_policy'] === true && $r['business_policy_problem_reason'] === 'missing_return_policy', 'Missing return policy must block with missing_return_policy.');

$r = EbayAdapter::business_policy_resolution_for_settings(array_merge($completeSettings, ['inventory_location_key' => '']), $shipping130, '');
$assert($r['missing_merchant_location'] === true && $r['business_policy_problem_reason'] === 'missing_merchant_location', 'Missing merchant location must block with missing_merchant_location.');

$r = EbayAdapter::business_policy_resolution_for_settings($completeSettings, ['policy_id' => '', 'policy_name' => ''], 'gpswiss-pl');
$assert($r['missing_fulfillment_policy'] === true && $r['business_policy_problem_reason'] === 'missing_fulfillment_policy', 'Missing fulfillment policy must block with missing_fulfillment_policy after payment/return/location are present.');

$post = [
    'ebay_payment_policy_id' => '',
    'ebay_payment_policy_id_manual' => '259264220013',
    'ebay_return_policy_id' => '',
    'ebay_return_policy_id_manual' => '259264151013',
];
$assert(AdminPage::posted_shipping_policy_id($post, 'ebay_payment_policy_id', ['paymentPolicyId', 'payment_policy']) === '259264220013', 'Manual payment policy ID 259264220013 must save and reload.');
$assert(AdminPage::posted_shipping_policy_id($post, 'ebay_return_policy_id', ['returnPolicyId', 'return_policy']) === '259264151013', 'Manual return policy ID 259264151013 must save and reload.');

$adapterSource = file_get_contents(__DIR__ . '/../src/Adapters/EbayAdapter.php');
$adminSource = file_get_contents(__DIR__ . '/../src/Services/AdminPage.php');
$viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
$diagnosticsStart = strpos($adminSource, 'public function shipping_mapping_diagnostics');
$diagnosticsEnd = strpos($adminSource, 'public function generate_shipping_mapping_report', $diagnosticsStart);
$diagnosticsSource = $diagnosticsStart !== false && $diagnosticsEnd !== false ? substr($adminSource, $diagnosticsStart, $diagnosticsEnd - $diagnosticsStart) : '';
$saveStart = strpos($adminSource, 'public function save_ebay_settings');
$saveEnd = strpos($adminSource, 'public function shipping_mapping_diagnostics', $saveStart);
$saveSource = $saveStart !== false && $saveEnd !== false ? substr($adminSource, $saveStart, $saveEnd - $saveStart) : '';

foreach (['selected_fulfillment_policy_id', 'selected_payment_policy_id', 'selected_return_policy_id', 'merchant_location_key', 'missing_payment_policy', 'missing_return_policy', 'missing_merchant_location', 'business_policy_problem_reason'] as $field) {
    $assert(str_contains($adapterSource, $field) && str_contains($adminSource, $field), 'Readiness and diagnostics must include ' . $field . '.');
}
$assert(str_contains($adapterSource, "'fulfillmentPolicyId'") && str_contains($adapterSource, 'selected_fulfillment_policy_id'), 'Offer payload must use resolved fulfillment policy ID.');
$assert(str_contains($adapterSource, "'paymentPolicyId'") && str_contains($adapterSource, 'selected_payment_policy_id'), 'Offer payload must use saved payment policy ID.');
$assert(str_contains($adapterSource, "'returnPolicyId'") && str_contains($adapterSource, 'selected_return_policy_id'), 'Offer payload must use saved return policy ID.');
$assert(str_contains($adapterSource, "'merchantLocationKey' => \$this->merchant_location_key()"), 'Offer payload must use merchantLocationKey from settings.');
$assert(str_contains($viewSource, 'SECTION C — Business policies'), 'Module 4 must expose SECTION C — Business policies.');
$assert(str_contains($viewSource, "\$renderBusinessPolicyControl('ebay_payment_policy_id'") && str_contains($viewSource, "\$field . '_manual'"), 'Payment policy selector and manual fallback must be visible.');
$assert(str_contains($viewSource, "\$renderBusinessPolicyControl('ebay_return_policy_id'") && str_contains($viewSource, "\$field . '_manual'"), 'Return policy selector and manual fallback must be visible.');
$assert(str_contains($viewSource, 'Required by eBay Inventory API offer creation.'), 'Merchant location helper text must be visible.');
$assert(str_contains($adminSource, "'259264220013'") && str_contains($adminSource, "'259264151013'"), 'Admin settings defaults must include known payment and return policy IDs.');
$assert(str_contains($adapterSource, "'gpswiss-pl'") && str_contains($adapterSource, "'259264220013'") && str_contains($adapterSource, "'259264151013'"), 'Adapter settings defaults must reuse marketplace business policy values.');
$assert($saveSource !== '' && !str_contains($saveSource, 'client->'), 'Settings save must not call the eBay API.');
$assert($diagnosticsSource !== '' && !str_contains($diagnosticsSource, 'client->'), 'Diagnostics must not call the eBay API.');
$assert(!str_contains($diagnosticsSource, 'update_post_meta') && !str_contains($diagnosticsSource, 'create_or_replace') && !str_contains($diagnosticsSource, 'publish'), 'Diagnostics must not modify products/listings or publish.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "eBay business policies readiness tests passed\n";
