<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$client = file_get_contents($root . '/src/Services/EbayClient.php') ?: '';
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
    'template_setting_option_key',
    'template_setting_raw_value',
    'template_setting_effective_value',
    'template_setting_default_used',
    'template_setting_enabled',
    'description_source_used',
    'inventory_product_description_length',
    'inventory_product_description_within_4000',
    'offer_listing_description_length',
    'inventory_product_description_contains_template_markers',
    'offer_listing_description_contains_template_markers',
    'sent_inventory_product_description_is_html_template',
    'sent_offer_listing_description_is_html_template',
    'inventory_product_description_template_too_long_warning',
    'sent_description_is_html_template',
    'contains_template_markers',
    'has_expédition_internationale_rapide',
    'has_spécifications',
    'has_livraison_dans_toute_l_europe',
    'has_achetez_en_toute_confiance',
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
    'eBay.fr HTML description template',
    'Enable eBay.fr HTML description template for published listings',
    'Current status:',
    'Save eBay.fr template setting',
    'FR HTML template is disabled; live listings will show only the short translated description.',
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
    "'template_setting_option_key' => (string) (\$descriptionPayloadDiagnostics['template_setting_option_key'] ?? '')",
    "'template_setting_raw_value' => (string) (\$descriptionPayloadDiagnostics['template_setting_raw_value'] ?? '')",
    "'template_setting_effective_value' => (string) (\$descriptionPayloadDiagnostics['template_setting_effective_value'] ?? '')",
    "'template_setting_default_used' => (string) (\$descriptionPayloadDiagnostics['template_setting_default_used'] ?? '')",
    "'template_setting_enabled' => !empty(\$descriptionPayloadDiagnostics['template_setting_enabled'])",
    "'description_source_used' => (string) (\$descriptionPayloadDiagnostics['description_source_used'] ?? '')",
    "'inventory_product_description_length' => (int) (\$descriptionPayloadDiagnostics['inventory_product_description_length'] ?? 0)",
    "'inventory_product_description_within_4000' => !empty(\$descriptionPayloadDiagnostics['inventory_product_description_within_4000'])",
    "'inventory_product_description_template_too_long_warning' => (string) (\$descriptionPayloadDiagnostics['inventory_product_description_template_too_long_warning'] ?? '')",
    "'offer_listing_description_length' => (int) (\$descriptionPayloadDiagnostics['offer_listing_description_length'] ?? 0)",
    "'sent_inventory_product_description_is_html_template' => (string) (\$descriptionPayloadDiagnostics['sent_inventory_product_description_is_html_template'] ?? '')",
    "'sent_offer_listing_description_is_html_template' => (string) (\$descriptionPayloadDiagnostics['sent_offer_listing_description_is_html_template'] ?? '')",
    "'sent_description_is_html_template' => (string) (\$descriptionPayloadDiagnostics['sent_description_is_html_template'] ?? '')",
    "'contains_template_markers' => (string) (\$descriptionPayloadDiagnostics['contains_template_markers'] ?? '')",
] as $needle) {
    $assertContains($adapter, $needle, 'Adapter publish result diagnostics');
}

$assertContains($admin, '$errors = array_values(array_filter($rows', 'Error CSV writer must derive error rows.');
$assertContains($view, 'href="<?php echo esc_url($listingUrl); ?>"', 'Admin listing URL must be clickable.');
$assertNotContains($admin, "'wei-ebay-integration/'", 'FR publish reports must not use the DE upload directory.');
$assertNotContains($view, 'wei-ebay-integration/fr-publish', 'FR publish UI must not link to DE reports.');


foreach ([
    'Preview publish payload for one ready product',
    'inventory.product.description',
    'offer.listingDescription',
    'item_specifics',
    'No eBay API call',
    'template_setting_option_key',
    'template_setting_raw_value',
    'template_setting_effective_value',
    'template_setting_default_used',
    'template_setting_enabled',
] as $needle) {
    $assertContains($adapter . $view . $admin, $needle, 'FR publish payload dry-run preview');
}

foreach ([
    "\$offer['listingDescription'] = \$html",
    "\$inventory['product']['description'] = \$shortDescription",
    "resolve_product_id_by_fr_listing_or_offer_id",
] as $needle) {
    $assertContains($adapter, $needle, 'Controlled single FR listing description revise must keep inventory short, update offer HTML, and resolve listing IDs');
}

foreach ([
    'INVENTORY_PRODUCT_DESCRIPTION_LIMIT = 4000',
    'inventory_api_safe_product_description',
    "'source' => 'short_translated_french_content_for_inventory_item'",
    "'template_attached_to' => 'offer.listingDescription'",
    '$offerListingDescriptionLength',
] as $needle) {
    $assertContains($adapter, $needle, 'FR template publish guard must keep full HTML on offer.listingDescription and inventory.product.description short');
}


foreach ([
    "'errors' => \$ebay_errors",
    "'errorId' => \$ebay_error_id",
    "'message' => \$first_ebay_message",
    "'domain' => \$ebay_error_domain",
    "'category' => \$ebay_error_category",
    "'inputRefIds' => \$ebay_error_input_ref_ids",
    "'parameters' => \$ebay_error_parameters",
    "'response_body' => \$response_body",
    "'http_status' => \$status",
] as $needle) {
    $assertContains($client, $needle, 'eBay createOrReplaceInventoryItem API error logging must expose full response diagnostics');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "FR publish logging visibility tests passed\n";
