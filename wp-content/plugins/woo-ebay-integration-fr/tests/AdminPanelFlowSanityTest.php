<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = file_get_contents($root . '/views/admin-page.php');
$admin = file_get_contents($root . '/src/Services/AdminPage.php');
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php');
$failures = [];

$assertContains = static function (string $haystack, string $needle, string $label) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};

$section = static function (string $html, string $start, string $end = ''): string {
    $startPos = strpos($html, $start);
    if ($startPos === false) {
        return '';
    }
    $endPos = $end !== '' ? strpos($html, $end, $startPos + strlen($start)) : false;
    return substr($html, $startPos, $endPos === false ? null : $endPos - $startPos);
};

$publish = $section($view, 'data-wei-module="publish"', 'data-wei-module="french-content"');
$french = $section($view, 'data-wei-module="french-content"', 'data-wei-module="ebay-categories"');
$categories = $section($view, 'data-wei-module="ebay-categories"', 'data-wei-module="ebay-settings"');
$advanced = $section($view, '<summary>Advanced / Debug');

$stockPos = strpos($view, 'data-wei-module="stock-synchronization"');
$publishPos = strpos($view, 'data-wei-module="publish"');
$frenchPos = strpos($view, 'data-wei-module="french-content"');
$categoriesPos = strpos($view, 'data-wei-module="ebay-categories"');
$settingsPos = strpos($view, 'data-wei-module="ebay-settings"');
if ($stockPos === false || $publishPos === false || $frenchPos === false || $categoriesPos === false || $settingsPos === false || !($stockPos < $publishPos && $publishPos < $frenchPos && $frenchPos < $categoriesPos && $categoriesPos < $settingsPos)) {
    $failures[] = 'Admin module order must be Stock synchronization, Publish, French Content, Kategorie eBay, then Ustawienia eBay.';
}

foreach (['3. French Content', 'Generate / refresh French content for one product', 'Product ID / SKU', 'Generate / refresh French content for this product', 'Generate / refresh French content for all products', 'Generate / refresh stale French content only', 'Preview French listing template'] as $needle) {
    $assertContains($french, $needle, 'French Content module');
}
foreach (['called_ebay_api' => 'false', 'updated_ebay_listing' => 'false', 'wei_fr_generate_french_content_batch'] as $needle => $expected) {
    $assertContains($french, is_string($needle) ? $needle : $expected, 'French Content safety summary');
}

foreach (['4. Kategorie eBay', 'Section 1 — Status / Audyt', 'Section 2 — Ręczne mapowanie kategoriami', 'Section 3 — Diagnostyka mappingu', 'Section 4 — Automatyczne sugestie / legacy tools', 'Section 5 — Ovoko / Supplier import, future', 'Run category readiness audit', 'Generate category-mapping-worklist.csv', 'Import filled category-mapping-worklist.csv', 'Generate blocked category fix report'] as $needle) {
    $assertContains($categories, $needle, 'Kategorie eBay module');
}
foreach (['Generate all eBay.fr category suggestions', 'Auto category mapping', 'Debug batch 50', 'Debug continue batch', 'Resetuj progress sugestii'] as $debugNeedle) {
    if (str_contains($categories, $debugNeedle)) {
        $failures[] = 'Debug category action leaked into main Kategorie eBay module: ' . $debugNeedle;
    }
    $assertContains($advanced, $debugNeedle, 'Advanced / Debug category tools');
}
foreach (['wei_fr_export_product', 'wei_fr_publish_product_offer_only', 'wei_fr_sync_stock'] as $mutatingProductAction) {
    if (str_contains($categories, $mutatingProductAction)) {
        $failures[] = 'Category main actions must not expose product-changing action: ' . $mutatingProductAction;
    }
}

foreach (['2. Publish', 'Run readiness scan', 'Export ready products to eBay', 'Publish ready offers', 'Publish ready products', 'Resetuj postęp publikacji', 'Refresh eBay listing state'] as $needle) {
    $assertContains($publish, $needle, 'Publish module');
}
foreach (['Inspect offer before publish', 'Manual publish offer only', 'Export single product', 'Regenerate French content for one product', 'OAuth', 'Product sync status rows', 'Raw logs'] as $debugNeedle) {
    $assertContains($advanced, $debugNeedle, 'Advanced / Debug tools');
}

$assertContains($admin, "add_action('admin_post_wei_fr_publish_ready_products'", 'Publish ready products hook');
$assertContains($admin, 'public function publish_ready_products()', 'Publish ready products handler');
$assertContains($admin, 'if (empty($preflight[\'ready\']))', 'Publish batch not-ready guard');
$batchStart = strpos($admin, 'private function run_initial_publish_batch(int $batchSize): array');
$notReadyPos = $batchStart === false ? false : strpos($admin, 'if (empty($preflight[\'ready\']))', $batchStart);
$exportPos = $batchStart === false ? false : strpos($admin, '$res = $this->adapter->export_product($productId, null, true);', $batchStart);
if ($batchStart === false || $notReadyPos === false || $exportPos === false || $notReadyPos > $exportPos) {
    $failures[] = 'FR publish ready products must check preflight/not_ready inside run_initial_publish_batch before export/publish.';
}
$guardBlock = ($notReadyPos === false || $exportPos === false) ? '' : substr($admin, $notReadyPos, $exportPos - $notReadyPos);
$assertContains($guardBlock, '$this->accumulate_publish_not_ready_reason($skipSummary, $preflight);', 'FR publish batch records FR not_ready counters before publish');
$assertContains($guardBlock, 'update_post_meta($productId, \'_wei_fr_ebay_last_sync_status\', \'not_ready\');', 'FR publish batch records FR not_ready status meta');
$assertContains($guardBlock, 'update_post_meta($productId, \'_wei_fr_ebay_last_sync_error\', $reason);', 'FR publish batch records FR not_ready reason meta');
$assertContains($guardBlock, 'continue;', 'FR publish batch skips not_ready before publish');
if (str_contains($guardBlock, '_wei_ebay_last_sync_status')) {
    $failures[] = 'FR publish batch guard must not write DE last sync status meta.';
}


$singleStart = strpos($adapter, 'public function generate_french_content_meta_only_for_identifier');
$singleBlock = $singleStart === false ? '' : substr($adapter, $singleStart, 6000);
foreach ([
    'resolve_product_by_id_or_sku($identifier)' => 'single refresh resolves product ID or SKU',
    'generate_french_content_meta_only($productId, $forceRefresh)' => 'single refresh delegates to current schema/template generator',
    "'source_description_field'" => 'single summary includes source description field',
    "'source_description_used'" => 'single summary includes source description used',
    "'schema_version'" => 'single summary includes schema version',
    "'title_generated'" => 'single summary includes generated title',
    "'description_generated'" => 'single summary includes generated description',
    "'translated_fields_count'" => 'single summary includes translated fields count',
    "'google_api_called'" => 'single summary includes Google API called flag',
    "'called_ebay_api' => false" => 'single refresh never calls eBay API',
    "'updated_ebay_listing' => false" => 'single refresh never updates active listings',
] as $needle => $label) {
    $assertContains($singleBlock, $needle, $label);
}
foreach (['createOrReplaceInventoryItem', 'publishOffer', 'revise', 'updateOffer'] as $forbiddenWrite) {
    if (str_contains($singleBlock, $forbiddenWrite)) {
        $failures[] = 'Single French content refresh must not include eBay/listing write call: ' . $forbiddenWrite;
    }
}

$assertContains($admin, "add_action('admin_post_wei_fr_generate_french_content_single'", 'French content single hook');
$assertContains($admin, 'generate_french_content_meta_only_for_identifier($input, true)', 'French content single forces explicit refresh');
$assertContains($admin, 'Preview French listing template for this product', 'French content single preview convenience');
$assertContains($admin, "add_action('admin_post_wei_fr_generate_french_content_batch'", 'French content batch hook');
$assertContains($admin, 'called_ebay_api' . "' => false", 'French content batch no eBay API flag');
$assertContains($admin, 'updated_ebay_listing' . "' => false", 'French content batch no listing update flag');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "AdminPanelFlowSanity tests passed\n";
