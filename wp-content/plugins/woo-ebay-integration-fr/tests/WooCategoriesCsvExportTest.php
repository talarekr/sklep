<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php');
$view = file_get_contents($root . '/views/admin-page.php');
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

$exportMethod = $section($admin, 'public function export_woo_categories_csv(): void', 'public function import_category_mapping_worklist(): void');
$generatorMethod = $section($admin, 'private function generate_woo_categories_csv_export(): array', 'private function woo_categories_csv_export_paths(): array');
$termsMethod = $section($admin, 'private function woo_product_category_terms_for_export(): array', 'private function woo_product_category_direct_product_ids(): array');
$directProductIdsMethod = $section($admin, 'private function woo_product_category_direct_product_ids(): array', 'private function woo_category_mapping_ids(string $marketplaceId): array');
$mappingMethod = $section($admin, 'private function woo_category_mapping_ids(string $marketplaceId): array', 'private function woo_category_full_path_from_rows');
$pathMethod = $section($admin, 'private function woo_category_full_path_from_rows', 'private function woo_category_excluded_from_ebay_value');
$exportView = $section($view, 'data-wei-category-section="woo-categories-csv-export"', 'data-wei-category-section="reczne-mapowanie"');

$assertContains($admin, "add_action('admin_post_wei_fr_export_woo_categories_csv'", 'admin-post hook');
$assertContains($exportMethod, '$this->require_manage_options();', 'admin capability guard');
$assertContains($exportMethod, "check_admin_referer('wei_fr_export_woo_categories_csv');", 'nonce guard');
$assertContains($exportMethod, '$this->go_category_mapping_screen();', 'category-screen redirect');
$assertContains($exportMethod, 'called_ebay_api', 'no eBay API summary flag');
$assertContains($exportMethod, 'modified_products', 'no product mutation summary flag');
$assertContains($exportMethod, 'modified_categories', 'no category mutation summary flag');

foreach (['term_id', 'parent_id', 'name', 'slug', 'full_path', 'level', 'product_count', 'direct_count', 'children_count', 'taxonomy', 'excluded_from_ebay', 'mapped_ebay_de_category_id', 'mapped_ebay_fr_category_id'] as $column) {
    $assertContains($generatorMethod, "'{$column}'", 'CSV column');
}
$assertContains($termsMethod, "WHERE tt.taxonomy = 'product_cat'", 'product_cat taxonomy query');
if (str_contains($termsMethod, 'INNER JOIN {$wpdb->term_relationships}') || str_contains($termsMethod, 'p.post_type')) {
    $failures[] = 'All-category export query must not require products, so empty product_cat terms are included.';
}
$assertContains($generatorMethod, '$calculateProductCount($termId)', 'recursive product_count');
$assertContains($directProductIdsMethod, 'SELECT DISTINCT tt.term_id, p.ID AS product_id', 'direct product ID query');
$assertContains($generatorMethod, '$' . 'directCounts[(int) $' . 'termId] = count((array) $' . 'productIds);', 'direct_count derived from direct product IDs with term IDs preserved');
$assertContains($pathMethod, "implode(' > '", 'nested category full_path delimiter');
$assertContains($generatorMethod, "woo_category_mapping_ids('EBAY_DE')", 'DE mapping lookup');
$assertContains($generatorMethod, "woo_category_mapping_ids('EBAY_FR')", 'FR mapping lookup');
$assertContains($mappingMethod, "wei_ebay_category_mappings", 'legacy DE mappings table');
$assertContains($mappingMethod, "wei_fr_ebay_category_mappings", 'FR mappings table');
$assertContains($admin, "'path' => trailingslashit($" . "baseDir) . 'woo-product-categories.csv'", 'export path filename');
$assertContains($admin, "'url' => trailingslashit($" . "baseUrl) . 'woo-product-categories.csv'", 'export url filename');
$assertContains($admin, "get_option('wei_fr_woo_product_categories_export_summary'", 'download allow-list summary option');
$assertContains($view, 'Export Woo categories CSV', 'admin button label');
$assertContains($exportView, "wp_nonce_field('wei_fr_export_woo_categories_csv')", 'view nonce');
$assertContains($exportView, 'path', 'view path label');
$assertContains($exportView, 'url', 'view url label');
$assertContains($exportView, 'total categories exported', 'view total label');
$assertContains($exportView, 'generated_at', 'view generated_at label');
$assertContains($exportView, 'Download Woo categories CSV', 'view download link');

foreach (['update_post_meta', 'wp_update_post', 'wp_set_object_terms', 'wp_insert_term', 'wp_update_term', 'wp_delete_term', 'publish_product', 'export_product(', 'get_policies(', 'get_default_category_tree_id('] as $forbidden) {
    if (str_contains($exportMethod . $generatorMethod . $termsMethod . $directProductIdsMethod . $mappingMethod, $forbidden)) {
        $failures[] = 'Woo category CSV export must be read-only and offline; forbidden call found: ' . $forbidden;
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Woo categories CSV export checks passed.\n";
