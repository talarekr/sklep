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

$publish = $section($view, 'data-wei-module="publish"', 'data-wei-module="german-content"');
$german = $section($view, 'data-wei-module="german-content"', 'data-wei-module="ebay-categories"');
$categories = $section($view, 'data-wei-module="ebay-categories"', 'data-wei-module="ebay-settings"');
$advanced = $section($view, '<summary>Advanced / Debug');

$stockPos = strpos($view, 'data-wei-module="stock-synchronization"');
$publishPos = strpos($view, 'data-wei-module="publish"');
$germanPos = strpos($view, 'data-wei-module="german-content"');
$categoriesPos = strpos($view, 'data-wei-module="ebay-categories"');
$settingsPos = strpos($view, 'data-wei-module="ebay-settings"');
if ($stockPos === false || $publishPos === false || $germanPos === false || $categoriesPos === false || $settingsPos === false || !($stockPos < $publishPos && $publishPos < $germanPos && $germanPos < $categoriesPos && $categoriesPos < $settingsPos)) {
    $failures[] = 'Admin module order must be Stock synchronization, Publish, German Content, Kategorie eBay, then Ustawienia eBay.';
}

foreach (['3. German Content', 'Generate / refresh German content for one product', 'Product ID / SKU', 'Generate / refresh German content for this product', 'Generate / refresh German content for all products', 'Generate / refresh stale German content only', 'Preview German listing template'] as $needle) {
    $assertContains($german, $needle, 'German Content module');
}
foreach (['called_ebay_api' => 'false', 'updated_ebay_listing' => 'false', 'wei_generate_german_content_batch'] as $needle => $expected) {
    $assertContains($german, is_string($needle) ? $needle : $expected, 'German Content safety summary');
}

foreach (['4. Kategorie eBay', 'Section 1 — Status / Audyt', 'Section 2 — Ręczne mapowanie kategoriami', 'Section 3 — Diagnostyka mappingu', 'Section 4 — Automatyczne sugestie / legacy tools', 'Section 5 — Ovoko / Supplier import, future', 'Run category readiness audit', 'Generate category-mapping-worklist.csv', 'Import filled category-mapping-worklist.csv', 'Generate blocked category fix report'] as $needle) {
    $assertContains($categories, $needle, 'Kategorie eBay module');
}
foreach (['Generate all eBay.de category suggestions', 'Auto category mapping', 'Debug batch 50', 'Debug continue batch', 'Resetuj progress sugestii'] as $debugNeedle) {
    if (str_contains($categories, $debugNeedle)) {
        $failures[] = 'Debug category action leaked into main Kategorie eBay module: ' . $debugNeedle;
    }
    $assertContains($advanced, $debugNeedle, 'Advanced / Debug category tools');
}
foreach (['wei_export_product', 'wei_publish_product_offer_only', 'wei_sync_stock'] as $mutatingProductAction) {
    if (str_contains($categories, $mutatingProductAction)) {
        $failures[] = 'Category main actions must not expose product-changing action: ' . $mutatingProductAction;
    }
}

foreach (['2. Publish', 'Run readiness scan', 'Export ready products to eBay', 'Publish ready offers', 'Publish ready products', 'Resetuj postęp publikacji', 'Refresh eBay listing state'] as $needle) {
    $assertContains($publish, $needle, 'Publish module');
}
foreach (['Inspect offer before publish', 'Manual publish offer only', 'Export single product', 'Regenerate German content for one product', 'OAuth', 'Product sync status rows', 'Raw logs'] as $debugNeedle) {
    $assertContains($advanced, $debugNeedle, 'Advanced / Debug tools');
}

$assertContains($admin, "add_action('admin_post_wei_publish_ready_products'", 'Publish ready products hook');
$assertContains($admin, 'public function publish_ready_products()', 'Publish ready products handler');
$assertContains($admin, 'if (empty($preflight[\'ready\']))', 'Publish batch not-ready guard');
$notReadyPos = strpos($admin, 'if (empty($preflight[\'ready\']))');
$exportPos = strpos($admin, '$res = $this->adapter->export_product($productId, null, true);', $notReadyPos === false ? 0 : $notReadyPos);
if ($notReadyPos === false || $exportPos === false || $notReadyPos > $exportPos) {
    $failures[] = 'Publish ready products must check preflight/not_ready before export/publish.';
}
$guardBlock = $notReadyPos === false ? '' : substr($admin, $notReadyPos, 600);
$assertContains($guardBlock, 'continue;', 'Publish batch skips not_ready before publish');
$assertContains($guardBlock, "'_wei_ebay_last_sync_status', 'not_ready'", 'Publish batch records not_ready reason');


$singleStart = strpos($adapter, 'public function generate_german_content_meta_only_for_identifier');
$singleBlock = $singleStart === false ? '' : substr($adapter, $singleStart, 6000);
foreach ([
    'resolve_product_by_id_or_sku($identifier)' => 'single refresh resolves product ID or SKU',
    'generate_german_content_meta_only($productId, $forceRefresh)' => 'single refresh delegates to current schema/template generator',
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
        $failures[] = 'Single German content refresh must not include eBay/listing write call: ' . $forbiddenWrite;
    }
}

$assertContains($admin, "add_action('admin_post_wei_generate_german_content_single'", 'German content single hook');
$assertContains($admin, 'generate_german_content_meta_only_for_identifier($input, true)', 'German content single forces explicit refresh');
$assertContains($admin, 'Preview German listing template for this product', 'German content single preview convenience');
$assertContains($admin, "add_action('admin_post_wei_generate_german_content_batch'", 'German content batch hook');
$assertContains($admin, 'called_ebay_api' . "' => false", 'German content batch no eBay API flag');
$assertContains($admin, 'updated_ebay_listing' . "' => false", 'German content batch no listing update flag');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "AdminPanelFlowSanity tests passed\n";
