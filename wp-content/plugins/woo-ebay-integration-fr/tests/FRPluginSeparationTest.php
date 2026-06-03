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
$auth = $read('src/Services/EbayAuth.php');
$view = $read('views/admin-page.php');
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
$assert(str_contains($auth, "CALLBACK_PAGE_SLUG = 'ebay-auth-callback'"), 'FR OAuth callback page slug must use the shared eBay callback.');
$assert(str_contains($auth, "LEGACY_CALLBACK_PAGE_SLUG = 'ebay-fr-auth-callback'"), 'FR OAuth may retain legacy FR callback detection for compatibility.');
$assert(str_contains($auth . $view, 'state_prefix'), 'FR OAuth/admin diagnostics must identify the state-prefix callback router.');
$assert(str_contains($view, 'Shared eBay OAuth callback URL, routed by state to FR'), 'FR settings must label the shared callback routing clearly.');
$assert(str_contains($auth, "STATE_TRANSIENT_PREFIX = 'wei_fr_oauth_state_'"), 'FR OAuth state key prefix must be FR-specific.');
$assert(!str_contains($auth, 'wei_ebay_oauth_state_') && !str_contains($auth, 'wei_oauth_state_'), 'FR OAuth state validation must not use DE state keys.');
$assert(str_contains($auth, 'self::CALLBACK_PAGE_SLUG'), 'FR OAuth browser callback URLs must be generated from the shared callback slug.');
$assert(str_contains($admin, 'AutoSyncScheduler::HOOK_DELTA_SYNC'), 'FR diagnostics must render the FR scheduler hook constant.');
$assert(!str_contains($admin . $view, 'wei_ebay_run_scheduled_sync'), 'FR diagnostics must not use the DE scheduler hook.');
$assert(!str_contains($admin . $adapter, 'wei_ebay_cached_policies'), 'FR diagnostics must not use DE cached policy option keys.');
$assert(str_contains($admin . $adapter, 'normalize_fr_cached_policies'), 'FR cached policies must be normalized before diagnostics/readiness use them.');
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
