<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$deAdminSource = file_get_contents(dirname(__DIR__, 1) . '/../woo-ebay-integration/src/Services/AdminPage.php') ?: '';
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'Visual eBay.fr description preview' => 'FR dry-run page must include the visual preview heading.',
    'This is the HTML that will be sent to eBay.fr as offer.listingDescription.' => 'FR dry-run page must explain the exact offer.listingDescription source.',
    'This is local rendering; eBay may sanitize some HTML/CSS.' => 'FR dry-run page must show local-rendering sanitization warning.',
    'offer_listingDescription_length' => 'FR dry-run preview diagnostics must include offer_listingDescription_length.',
    'contains_template_markers' => 'FR dry-run preview diagnostics must include contains_template_markers.',
    'sent_offer_listing_description_is_html_template' => 'FR dry-run preview diagnostics must include sent_offer_listing_description_is_html_template.',
    'raw_offer_listingDescription' => 'FR dry-run page must expose raw offer.listingDescription.',
    'raw_inventory_product_description' => 'FR dry-run page must expose raw inventory.product.description.',
    '<iframe title="Visual eBay.fr description preview" sandbox=""' => 'FR dry-run visual preview must render in a sandboxed iframe.',
    'esc_attr($offerListingDescription)' => 'FR iframe srcdoc must use the exact offer.listingDescription HTML escaped as an attribute.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

foreach ([
    "'source' => 'offer.listingDescription'" => 'Adapter visual preview must declare offer.listingDescription as the preview source.',
    "'html' => \$listingDescription" => 'Adapter visual preview HTML must be the final listingDescription.',
    "'raw_offer_listingDescription' => \$listingDescription" => 'Adapter must expose the raw final offer.listingDescription.',
    "'raw_inventory_product_description' => \$productDescription" => 'Adapter must expose the raw final inventory.product.description.',
    "'no_ebay_api' => true" => 'Dry-run safety flags must keep no_ebay_api=true.',
    "'no_listing_created' => true" => 'Dry-run safety flags must keep no_listing_created=true.',
    "'no_listing_updated' => true" => 'Dry-run safety flags must keep no_listing_updated=true.',
    "'no_woo_changes' => true" => 'Dry-run safety flags must keep no_woo_changes=true.',
] as $needle => $message) {
    $assert(str_contains($adapterSource, $needle), $message);
}

$assert(!str_contains($deAdminSource, 'Visual eBay.fr description preview'), 'DE admin behavior must remain unchanged and not include the FR visual preview heading.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish dry-run visual preview tests passed\n";
