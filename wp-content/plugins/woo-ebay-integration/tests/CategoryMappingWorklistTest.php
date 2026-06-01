<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$serviceSource = file_get_contents($root . '/src/Services/BlockedCategoryFixReportService.php') ?: '';
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$repoSource = file_get_contents($root . '/src/Repositories/CategoryMappingRepository.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

foreach (['blocked_by_category', 'invalid_ebay_category_id', 'non_leaf_category', 'missing_category', 'needs_category_review', 'category_sanity_failed'] as $problemType) {
    $assert(str_contains($serviceSource, "'" . $problemType . "'"), 'Worklist export must include category problem type ' . $problemType . '.');
}

foreach (['woo_category_id','woo_category_name','blocked_product_count','total_product_count_in_category','current_ebay_category_id','current_ebay_category_name','current_ebay_category_path','problem_type','sample_product_ids','sample_product_titles','final_ebay_category_id','manual_notes'] as $header) {
    $assert(str_contains($serviceSource, "'" . $header . "'"), 'category-mapping-worklist.csv must include column ' . $header . '.');
}

$assert(str_contains($serviceSource, 'generate_category_mapping_worklist'), 'Worklist export service method must exist.');
$assert(str_contains($serviceSource, 'import_category_mapping_worklist'), 'Worklist import service method must exist.');
$assert(str_contains($serviceSource, 'if ($problemType === \'\')') && str_contains($serviceSource, 'continue;'), 'Worklist export must exclude non-category readiness problems and ready rows.');
$assert(str_contains($serviceSource, 'sample_product_titles') && str_contains($serviceSource, 'product_title'), 'Worklist export must include sample product titles.');
$assert(str_contains($serviceSource, 'skipped_empty_final_ebay_category_id') && str_contains($serviceSource, 'if ($finalCategoryId === \'\')'), 'Import must skip empty final_ebay_category_id rows.');
$assert(str_contains($serviceSource, "'invalid_ebay_category_id'") && str_contains($serviceSource, 'cached_category($marketplaceId, $finalCategoryId)'), 'Import must reject invalid eBay category IDs using cached taxonomy only.');
$assert(str_contains($serviceSource, "'non_leaf_category'") && str_contains($serviceSource, "empty(\$category['leaf'])"), 'Import must reject non-leaf categories.');
$assert(str_contains($serviceSource, "'ebay_api_called' => false"), 'Import must report that it does not call eBay API.');
$assert(!str_contains($serviceSource, 'get_category_details_result($marketplaceId, $finalCategoryId') && !str_contains($serviceSource, 'validate_category_result($marketplaceId, $finalCategoryId'), 'Import must not call taxonomy methods that can call eBay API.');
$assert(!str_contains($serviceSource, 'update_post_meta(') && !str_contains($serviceSource, 'export_product('), 'Worklist import must not modify products or publish listings.');
$assert(str_contains($repoSource, "'source' => 'manual_worklist'"), 'Valid import must save category mapping with source manual_worklist.');
$assert(str_contains($repoSource, 'disabled_duplicate'), 'Import must deduplicate/deactivate older duplicate mappings.');
$assert(str_contains($adminSource, "add_action('admin_post_wei_generate_category_mapping_worklist'") && str_contains($adminSource, "add_action('admin_post_wei_import_category_mapping_worklist'"), 'Admin hooks must exist for worklist export/import.');
$assert(str_contains($adminSource, 'CATEGORY_MAPPING_WORKLIST_FILENAME'), 'Download allow-list must include category-mapping-worklist.csv.');
$assert(str_contains($viewSource, 'Download category-mapping-worklist.csv'), 'Admin UI must show a download link after the worklist export completes.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category mapping worklist tests passed\n";
