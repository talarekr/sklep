<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';
$serviceSource = file_get_contents($root . '/src/Services/VehicleCompatibilityAuditService.php') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($adminSource, "add_action('admin_post_wei_vehicle_compatibility_diagnostics'"), 'Single-product vehicle diagnostics admin hook must be registered.');
$assert(str_contains($adminSource, "add_action('admin_post_wei_run_vehicle_compatibility_audit'"), 'Batch vehicle compatibility audit admin hook must be registered.');
$assert(str_contains($viewSource, 'Vehicle compatibility diagnostics'), 'Admin must render vehicle compatibility diagnostics block outside Kategorie eBay.');
$categoryStart = strpos($viewSource, 'data-wei-module="ebay-categories"');
$categoryEnd = strpos($viewSource, 'data-wei-module="ebay-settings"', $categoryStart === false ? 0 : $categoryStart);
$categorySection = $categoryStart === false ? '' : substr($viewSource, $categoryStart, $categoryEnd === false ? null : $categoryEnd - $categoryStart);
$assert(!str_contains($categorySection, 'Run vehicle compatibility readiness audit'), 'Kategorie eBay module must stay focused on category mapping and not expose vehicle audit controls.');
$assert(str_contains($viewSource, 'name="action" value="wei_run_vehicle_compatibility_audit"'), 'Batch audit button must submit dedicated action.');
$assert(str_contains($adminSource, "'called_ebay_api' => false") || str_contains($serviceSource, "'called_ebay_api' => false"), 'Audit/preview must declare no eBay API calls.');
$assert(str_contains($serviceSource, 'buildProductCompatibilityPayload'), 'Future compatibility payload integration point must exist.');
$assert(str_contains($serviceSource, 'compatibleProducts') && str_contains($serviceSource, 'kTypeValue'), 'Future payload must be shaped for Inventory API compatibleProducts kTypeValue.');
$assert(str_contains($viewSource, 'compatibility_enhancement_missing') && !str_contains($viewSource, 'missing_ktype'), 'Admin must display missing KType/ePID as a non-blocking compatibility enhancement.');
foreach (['product_id', 'sku', 'product_title', 'woo_category_id', 'woo_category_name', 'ebay_category_id', 'category_status', 'ovoko_car_id', 'ktype_values', 'epid_values', 'make', 'model', 'year', 'trim', 'engine_code', 'engine_capacity', 'fuel', 'power', 'detected_manufacturer_part_number', 'mpn_source', 'mapped_item_specific_names', 'mpn_present_in_final_item_specifics_payload', 'compatibility_status', 'missing_fields', 'notes'] as $column) {
    $assert(str_contains($serviceSource, "'" . $column . "'"), 'Vehicle audit CSV header must include ' . $column . '.');
}
foreach (['create_offer', 'publish_offer', 'update_offer', 'bulk_update_price_quantity', 'revise'] as $forbidden) {
    $assert(!str_contains($serviceSource, $forbidden), 'Vehicle audit service must not publish/revise/call mutating eBay methods: ' . $forbidden);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Vehicle compatibility admin readiness tests passed\n";
