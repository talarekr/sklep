<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Services/StockSyncService.php');
$client = file_get_contents($root . '/src/Services/EbayClient.php');

$failures = [];
$assertContains = static function (string $source, string $needle, string $label) use (&$failures): void {
    if (!str_contains($source, $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};
$assertNotContains = static function (string $source, string $needle, string $label) use (&$failures): void {
    if (str_contains($source, $needle)) {
        $failures[] = $label . ' must not contain: ' . $needle;
    }
};
$methodBlock = static function (string $source, string $method): string {
    $needle = 'function ' . $method . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return '';
};

$unavailableBlock = $methodBlock($service, 'make_ebay_listing_unavailable');
$retryBlock = $methodBlock($service, 'is_retryable_identifier_error');
$syncBlock = $methodBlock($service, 'sync_woo_to_ebay');

foreach ([
    'end_fixed_price_item_by_listing_id($listingId',
    "'listing_id' => \$listingId",
    "'offer_id' => \$offerId",
    "'sku' => \$sku",
    "'offerId' => \$offerId",
    "'shipToLocationAvailability' => ['quantity' => 0]",
    'stock_sync_invalid_sku_fallback_end_by_listing_id',
    'stock_sync_invalid_sku_fallback_delete_offer',
    'end_ebay_listing_by_listing_id_after_invalid_sku',
    'make_ebay_offer_unavailable_by_offer_id_after_invalid_sku',
    'WEI_STOCK_SYNC_EBAY_ID_FALLBACK',
    'is_ebay_already_closed_error($res)',
    'already_closed_success_response($res',
    'already_closed_success',
] as $needle) {
    $assertContains($unavailableBlock, $needle, 'Woo → eBay unavailable ID fallback');
}

$listingEndPos = strpos($unavailableBlock, 'end_fixed_price_item_by_listing_id($listingId');
$bulkOfferPos = strpos($unavailableBlock, 'bulk_update_price_quantity');
if ($listingEndPos === false || $bulkOfferPos === false || $listingEndPos > $bulkOfferPos) {
    $failures[] = 'Woo → eBay end_listing path must prefer listing_id before Inventory bulk quantity fallback.';
}

foreach ([
    'invalid value for a sku',
    'alphanumeric',
    'must not exceed 50',
] as $needle) {
    $assertContains($retryBlock, $needle, 'Retryable invalid SKU detection');
}
$assertContains($syncBlock, 'retryable_after_id_based_stock_sync_fix', 'Invalid SKU CSV retry marker');
foreach (['ebay_already_closed_detected', 'event_marked_processed', 'local_listing_state_after', 'retryable'] as $needle) {
    $assertContains($service, $needle, 'Already-closed idempotent success diagnostics');
}
$assertContains($client, 'function bulk_update_price_quantity(array $requests, array $context = [])', 'Bulk update context for diagnostics');
$assertContains($client, 'function end_fixed_price_item_by_listing_id', 'Trading listing-id end fallback client');
$assertContains($client, "'X-EBAY-API-CALL-NAME' => $" . "callName", 'Trading API call-name header');
$assertContains($client, "'X-EBAY-API-IAF-TOKEN' => $" . "token", 'Trading API OAuth token header');
$assertContains($client, '<ItemID>', 'Trading API listing ItemID payload');
$assertNotContains($unavailableBlock, 'publish_offer(', 'Woo → eBay unavailable must not publish/relist');
$assertNotContains($unavailableBlock, 'create_offer(', 'Woo → eBay unavailable must not create offers');
$assertNotContains($unavailableBlock, 'create_or_replace_inventory_item(', 'Woo → eBay unavailable must not touch inventory item SKU endpoint');
$assertNotContains($unavailableBlock, "['quantity' => 1]", 'Woo → eBay unavailable must not increase stock');
$assertNotContains($unavailableBlock, 'publish_offer(', 'Already-closed idempotent success must not publish/relist');
$assertNotContains($unavailableBlock, "['quantity' => 1]", 'Already-closed idempotent success must not increase stock');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Hyphenated SKU ID fallback stock sync test passed\n";
