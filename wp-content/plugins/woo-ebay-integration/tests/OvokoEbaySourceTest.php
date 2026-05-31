<?php

declare(strict_types=1);

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}

require_once __DIR__ . '/../src/Services/AutoCategoryMappingService.php';

use WEI\Services\AutoCategoryMappingService;

$service = (new ReflectionClass(AutoCategoryMappingService::class))->newInstanceWithoutConstructor();
$invoke = static function (string $method, array $args = []) use ($service) {
    $ref = new ReflectionMethod(AutoCategoryMappingService::class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($service, $args);
};

$failures = [];

if ($invoke('legacy_item_id_from_ebay_url', ['https://www.ebay.de/itm/277935341444']) !== '277935341444') {
    $failures[] = 'Expected concrete Ovoko auction URL to yield legacy item ID 277935341444';
}

$extracted = $invoke('extract_ebay_item_urls_from_html', ['<a href="https://www.ebay.de/itm/277935341444?hash=x">Ovoko</a>']);
if ($extracted !== ['https://www.ebay.de/itm/277935341444']) {
    $failures[] = 'Expected public store HTML extraction to normalize Ovoko item URL';
}

if (!$invoke('is_expected_ovoko_seller', ['OvokoParts-Germany'])) {
    $failures[] = 'Expected OvokoParts-Germany seller to be accepted';
}

if (!$invoke('is_expected_ovoko_seller', ['ovokoparts-germany'])) {
    $failures[] = 'Expected lowercase ovokoparts-germany seller variant to be accepted';
}

if ($invoke('is_expected_ovoko_seller', ['atushop'])) {
    $failures[] = 'Expected atushop seller to be rejected';
}

$listing = $invoke('ovoko_listing_from_browse_item', [[
    'itemId' => 'v1|277935341444|0',
    'legacyItemId' => '277935341444',
    'title' => 'Ovoko used auto part example',
    'seller' => ['username' => 'OvokoParts-Germany'],
    'itemWebUrl' => 'https://www.ebay.de/itm/277935341444',
    'categoryId' => '174106',
], 'browse_get_item_by_legacy_id']);

if (($listing['seller_username'] ?? '') !== 'OvokoParts-Germany') {
    $failures[] = 'Expected browse getItem payload to expose seller username OvokoParts-Germany';
}

if (($listing['category_id'] ?? '') !== '174106') {
    $failures[] = 'Expected browse getItem payload to expose categoryId';
}

$diagnostics = $invoke('ovoko_listing_diagnostics', [[
    'store_slug_used' => 'usedautocarparts',
    'seller_usernames_seen' => ['OvokoParts-Germany', 'atushop'],
    'rejected_non_ovoko_listings' => 1,
], [$listing]]);

foreach (['store_slug_used', 'expected_sellers', 'seller_usernames_seen', 'accepted_ovoko_listings', 'rejected_non_ovoko_listings'] as $key) {
    if (!array_key_exists($key, $diagnostics)) {
        $failures[] = 'Expected diagnostics to include ' . $key;
    }
}

if (($diagnostics['store_slug_used'] ?? '') !== 'usedautocarparts') {
    $failures[] = 'Expected store_slug_used to be usedautocarparts';
}

if (($diagnostics['accepted_ovoko_listings'] ?? 0) !== 1 || ($diagnostics['rejected_non_ovoko_listings'] ?? 0) !== 1) {
    $failures[] = 'Expected diagnostics to count one accepted Ovoko listing and one rejected non-Ovoko listing';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Ovoko eBay source tests passed\n";
