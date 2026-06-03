<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$view = file_get_contents($root . '/views/admin-page.php') ?: '';
$failures = [];

$assertContains = static function (string $source, string $needle, string $message) use (&$failures): void {
    if (!str_contains($source, $needle)) {
        $failures[] = $message . ' missing: ' . $needle;
    }
};
$assertNotContains = static function (string $source, string $needle, string $message) use (&$failures): void {
    if (str_contains($source, $needle)) {
        $failures[] = $message . ' must not contain: ' . $needle;
    }
};

foreach ([
    "'wei-ebay-integration-fr'",
    "'fr-publish-last-run.json'",
    "'fr-publish-actions.csv'",
    "'fr-publish-errors.csv'",
    'write_fr_publish_reports',
    'record_fr_publish_action',
    'publish_product_offer_only',
    'build_fr_publish_action_row',
    'report_write_error',
    'target_path',
    'reason',
] as $needle) {
    $assertContains($admin, $needle, 'FR publish report writer');
}

foreach ([
    'timestamp',
    'run_id',
    'action',
    'product_id',
    'title',
    'sku',
    'ebay_sku',
    'marketplace',
    'category_id',
    'price',
    'currency',
    'quantity',
    'inventory_item_id',
    'offer_id',
    'listing_id',
    'listing_url',
    'result',
    'error_message',
    'readiness_status',
    'selected_fulfillment_policy_id',
    'selected_payment_policy_id',
    'selected_return_policy_id',
    'merchant_location_key',
    'description_source_used',
    'sent_description_is_html_template',
    'contains_template_markers',
] as $needle) {
    $assertContains($admin, $needle, 'FR publish CSV/log schema');
}

foreach ([
    'Latest FR publish/export logs',
    'Last publish run summary',
    'Last published listings',
    'Open on eBay.fr',
    'last-run JSON',
    'actions CSV',
    'errors CSV',
    'Product-level FR listing meta diagnostics',
    '_wei_fr_ebay_listing_id',
    '_wei_fr_ebay_offer_id',
    '_wei_fr_ebay_inventory_item_id',
    '_wei_fr_ebay_listing_url',
    '_wei_fr_ebay_published_at',
    '_wei_fr_ebay_marketplace',
] as $needle) {
    $assertContains($view, $needle, 'FR publish admin visibility');
}

foreach ([
    "update_post_meta(\$metaProductId, '_wei_fr_ebay_marketplace', \$marketplaceId)",
    "'listing_url' => \$this->listing_public_url(\$listing_id, \$marketplaceId)",
    "'selected_fulfillment_policy_id' => (string) (\$businessPolicyResolution['selected_fulfillment_policy_id'] ?? '')",
    "'description_source_used' => (string) (\$descriptionPayloadDiagnostics['description_source_used'] ?? '')",
    "'sent_description_is_html_template' => (string) (\$descriptionPayloadDiagnostics['sent_description_is_html_template'] ?? '')",
    "'contains_template_markers' => (string) (\$descriptionPayloadDiagnostics['contains_template_markers'] ?? '')",
] as $needle) {
    $assertContains($adapter, $needle, 'Adapter publish result diagnostics');
}

$assertContains($admin, '$errors = array_values(array_filter($rows', 'Error CSV writer must derive error rows.');
$assertContains($view, 'href="<?php echo esc_url($listingUrl); ?>"', 'Admin listing URL must be clickable.');
$assertNotContains($admin, "'wei-ebay-integration/'", 'FR publish reports must not use the DE upload directory.');
$assertNotContains($view, 'wei-ebay-integration/fr-publish', 'FR publish UI must not link to DE reports.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "FR publish logging visibility tests passed\n";
