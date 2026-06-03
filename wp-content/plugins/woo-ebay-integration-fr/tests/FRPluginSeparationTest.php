<?php

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . ltrim($relative, '/');
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $contents;
};
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$bootstrap = $read('woo-ebay-integration-fr.php');
$plugin = $read('src/Plugin.php');
$adapter = $read('src/Adapters/EbayAdapter.php');
$admin = $read('src/Services/AdminPage.php');
$repo = $read('src/Repositories/MappingRepository.php');
$stock = $read('src/Services/StockSyncService.php');
$taxonomy = $read('src/Services/EbayTaxonomyService.php');
$comparison = $read('src/Services/EbayFrCategoryComparisonTool.php');
$translator = $read('src/Services/EbayFrenchContentTranslator.php');

$assert(str_contains($bootstrap, 'Plugin Name: Woo eBay Integration FR'), 'FR plugin header must use FR plugin identity.');
$assert(str_contains($bootstrap, "strpos($" . "class, 'WEI_FR\\\\')"), 'FR autoloader must use WEI_FR namespace.');
$assert(!str_contains($bootstrap, "strpos($" . "class, 'WEI\\\\')"), 'FR autoloader must not claim DE WEI namespace.');
$assert(str_contains($plugin, "public const OPTION_KEY = 'wei_fr_ebay_settings'"), 'FR settings option key must be separate.');
$assert(str_contains($plugin, 'gpswiss ebay-fr categories compare-auto'), 'FR category comparison WP-CLI command must be registered.');
$assert(str_contains($admin, "'woo-ebay-fr'"), 'FR admin menu slug must be FR-specific.');
$assert(str_contains($admin, "\$s['ebay_payment_policy_id'] = '';"), 'FR payment policy default must be empty/safe.');
$assert(str_contains($admin, "\$s['stock_sync_enabled'] = 0;"), 'FR live stock sync must be disabled by default.');
$assert(str_contains($repo, "'ebay_fr'"), 'FR mapping repository must use ebay_fr marketplace rows.');
$assert(!str_contains($repo, "'ebay'"), 'FR mapping repository must not reuse DE marketplace rows.');

foreach (['_wei_fr_ebay_listing_id', '_wei_fr_ebay_item_id', '_wei_fr_ebay_offer_id', '_wei_fr_ebay_inventory_item_id', '_wei_fr_ebay_export_status', '_wei_fr_ebay_listing_status', '_wei_fr_ebay_listing_url', '_wei_fr_ebay_published_at', '_wei_fr_ebay_last_sync_at', '_wei_fr_ebay_last_error'] as $metaKey) {
    $assert(str_contains($adapter, $metaKey), 'Missing FR listing meta key ' . $metaKey);
}
$assert(!str_contains($adapter, "get_post_meta($" . "metaProductId, '_wei_ebay_listing_id'"), 'FR duplicate guard must not read DE listing meta.');
$assert(str_contains($adapter, 'local_active_listing_diagnostics'), 'FR duplicate guard must exist.');
$assert(str_contains($adapter, 'blocked_by_fr_business_policy'), 'FR readiness must use FR business-policy blocker.');
$assert(str_contains($adapter, 'blocked_by_fr_shipping_policy'), 'FR readiness must use FR shipping-policy blocker.');
$assert(str_contains($adapter, "'EBAY_FR'"), 'FR publish/export/aspects flow must reference EBAY_FR.');
$assert(!str_contains(str_replace($comparison, '', $adapter . $admin . $stock . $taxonomy), 'EBAY_DE'), 'EBAY_DE must not appear in FR publish/export/aspects/settings code.');

$assert(str_contains($translator, "META_TITLE = '_wei_fr_ebay_title'"), 'French content title meta must be FR-specific.');
$assert(str_contains($translator, "META_DESCRIPTION = '_wei_fr_ebay_description_html'"), 'French content description meta must be FR-specific.');
$assert(str_contains($translator, "SCHEMA_VERSION = '2026-06-new-ebay-fr-template-v1'"), 'French schema version must be FR-specific.');
$assert(str_contains($translator, "TEMPLATE_VERSION = 'ebay-fr-product-card-template-v1'"), 'French template version must be FR-specific.');
$assert(str_contains($adapter, 'Voir plus de pièces de ce véhicule'), 'French same-vehicle CTA label must be present.');
$assert(str_contains($adapter, 'https://www.ebay.fr/sch/i.html?_ssn='), 'FR same-vehicle CTA must use ebay.fr seller search.');

$assert(str_contains($stock, 'wei-ebay-integration-fr'), 'FR stock sync reports must use FR upload directory.');
$assert(str_contains($comparison, 'ebay_de_auto_categories.csv'), 'Comparison tool must generate DE category CSV.');
$assert(str_contains($comparison, 'ebay_fr_auto_categories.csv'), 'Comparison tool must generate FR category CSV.');
$assert(str_contains($comparison, 'ebay_de_fr_auto_category_comparison.csv'), 'Comparison tool must generate comparison CSV.');
$assert(str_contains($comparison, 'ebay_de_to_fr_category_mapping_candidates.csv'), 'Comparison tool must generate mapping candidates CSV.');
$assert(str_contains($comparison, "'EBAY_DE'") && str_contains($comparison, "'EBAY_FR'"), 'Comparison tool must compare EBAY_DE and EBAY_FR.');

print "FRPluginSeparation tests passed\n";
