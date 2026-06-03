<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . ltrim($relative, '/'));
    if ($contents === false) {
        throw new RuntimeException('Unable to read ' . $relative);
    }
    return $contents;
};
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$admin = $read('src/Services/AdminPage.php');
$plugin = $read('src/Plugin.php');
$service = $read('src/Services/EbayFrCategoryComparisonTool.php');
$view = $read('views/admin-page.php');

$assert(str_contains($plugin, '$categoryComparisonTool = new EbayFrCategoryComparisonTool'), 'Plugin must construct the reusable category comparison service.');
$assert(str_contains($plugin, 'compare-auto') && str_contains($plugin, '$categoryComparisonTool->generate'), 'WP-CLI compare-auto command must call the reusable service.');
$assert(str_contains($admin, "admin_post_wei_fr_generate_de_fr_category_comparison"), 'Admin action must be registered for the browser category comparison tool.');
$assert(str_contains($admin, 'public function generate_de_fr_category_comparison()'), 'Admin action handler must exist.');
$assert(str_contains($admin, '$this->require_manage_options();') && str_contains($admin, "check_admin_referer('wei_fr_generate_de_fr_category_comparison')"), 'Upload/action must require manage_options and a nonce.');
$assert(str_contains($admin, 'validate_de_mapping_upload') && str_contains($admin, 'pathinfo($name, PATHINFO_EXTENSION') && str_contains($admin, '$allowedMimeTypes'), 'Invalid uploaded files must be rejected by extension and MIME checks.');
$assert(str_contains($admin, "'wei-ebay-integration-fr/category-comparison'") && str_contains($admin, "'/input'") && str_contains($admin, "'/finalny-de-mapping.csv'"), 'Valid CSV uploads must be copied under the FR category-comparison input directory.');
$assert(str_contains($service, "'wei-ebay-integration-fr/category-comparison'") && !str_contains($service, "woo-ebay-integration/category-comparison"), 'Generated reports must be under the FR upload directory, not the DE plugin directory.');
$assert(str_contains($service, "'started_at'") && str_contains($service, "'finished_at'") && str_contains($service, "'summary_counts'") && str_contains($service, "'reports'"), 'Service must return report paths/counts and status fields.');
$assert(str_contains($service, 'ebay_de_auto_categories.csv') && str_contains($service, 'ebay_fr_auto_categories.csv') && str_contains($service, 'ebay_de_fr_auto_category_comparison.csv') && str_contains($service, 'ebay_de_to_fr_category_mapping_candidates.csv'), 'Service must generate all requested CSV report names.');
$assert(str_contains($service, 'ebay-de-category-subtree-131090.json') && str_contains($service, 'ebay-fr-category-subtree-6030.json'), 'Service must keep raw taxonomy JSON reports.');
$assert(str_contains($service, 'category-comparison-last-run.json') && str_contains($admin, 'category_comparison_last_run()'), 'Last-run summary JSON must be written and read after page refreshes.');
$assert(str_contains($view, 'DE → FR category comparison') && str_contains($view, 'Generate DE → FR category comparison reports'), 'Category mapping/readiness panel must render the new DE → FR section and button.');
$assert(str_contains($view, 'Force refresh eBay taxonomy cache') && str_contains($view, 'unchecked') === false, 'Force refresh checkbox must be present and not rendered checked by default.');
$assert(str_contains($view, 'Last generated reports') && str_contains($view, 'Public URL') && str_contains($view, 'Raw JSON reports if generated'), 'UI must render last-run report links and raw JSON report links.');

foreach (['publish_product', 'publish_ready_products', 'save_manual_mapping', 'import_category_mapping_worklist', 'apply_manual_woo_category_mappings'] as $forbidden) {
    $handlerStart = strpos($admin, 'public function generate_de_fr_category_comparison()');
    $handlerEnd = strpos($admin, 'private function validate_de_mapping_upload', $handlerStart);
    $handler = $handlerStart !== false && $handlerEnd !== false ? substr($admin, $handlerStart, $handlerEnd - $handlerStart) : '';
    $assert(!str_contains($handler, $forbidden), 'No publish/import action may be triggered by the category comparison handler: ' . $forbidden);
}

$assert(!str_contains($admin, 'wei_ebay_settings') && !str_contains($admin, 'woo-ebay-integration/category-comparison'), 'DE plugin options/files must not be modified by the admin tool.');
$assert(!str_contains($service, 'wei_ebay_settings') && !str_contains($service, 'save_manual_mapping'), 'DE/FR category mappings must not be written by the comparison service.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category comparison admin tool tests passed\n";
