<?php

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('remove_accents')) {
    function remove_accents($text) { return strtr((string) $text, ['ążśźęćńółĄŻŚŹĘĆŃÓŁ' => 'azszecnolAZSZECNOL']); }
}

require_once __DIR__ . '/../src/Services/EbayCategorySuggestionReportService.php';

use WEI\Services\EbayCategorySuggestionReportService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$queries = EbayCategorySuggestionReportService::build_queries('Wąż / Przewód klimatyzacji A/C', 'Części > Wąż / Przewód klimatyzacji A/C', [
    ['id' => 10907, 'title' => 'AUDI Klimaleitung 8K0260701', 'manufacturer' => 'Audi', 'mpn' => '8K0260701'],
]);
$assert(in_array('Klimaanlagenschlauch', $queries, true), 'AC hose query should include Klimaanlagenschlauch.');
$assert(in_array('Klimaleitung', $queries, true), 'AC hose query should include Klimaleitung.');
$assert(in_array('Klimaleitungen Schläuche Anschlüsse Auto', $queries, true), 'AC hose query should include German automotive context.');

$parsed = EbayCategorySuggestionReportService::parse_suggestions([
    [
        'category' => ['categoryId' => '33544', 'categoryName' => 'Klimaleitungen, -schläuche & Anschlüsse'],
        'categoryTreeNodeAncestors' => [
            ['categoryName' => 'Auto & Motorrad: Teile'],
            ['categoryName' => 'Autoteile & Zubehör'],
        ],
        'relevancy' => '0.98',
    ],
], 3);
$assert(($parsed[0]['category_id'] ?? '') === '33544', 'Parser should extract category id.');
$assert(str_contains((string) ($parsed[0]['category_path'] ?? ''), 'Klimaleitungen'), 'Parser should build category path.');
$assert(($parsed[0]['score'] ?? '') === '0.98', 'Parser should keep relevancy score.');

$invalidStatus = EbayCategorySuggestionReportService::mapping_status('61175', ['valid' => false, 'leaf' => false], $parsed);
$assert($invalidStatus === 'invalid_current', 'Invalid current category should produce invalid_current.');

$likelyOk = EbayCategorySuggestionReportService::mapping_status('33544', ['valid' => true, 'leaf' => true], $parsed);
$assert($likelyOk === 'likely_ok', 'Valid leaf current category in top suggestions should produce likely_ok.');

$reportColumns = EbayCategorySuggestionReportService::suggestion_report_columns();
foreach (['woo_subcategory_id', 'current_ebay_category_valid', 'suggested_ebay_category_id_3', 'query_used', 'taxonomy_error'] as $column) {
    $assert(in_array($column, $reportColumns, true), 'Missing suggestions CSV column: ' . $column);
}

$readyColumns = EbayCategorySuggestionReportService::ready_to_import_columns();
$assert($readyColumns === ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','products_count','old_ebay_category_id','ebay_category_id','suggested_ebay_category_path','confidence','mapping_status','note'], 'Ready-to-import CSV columns should match required import shape.');

$assert(!in_array('imported_at', $readyColumns, true), 'Suggestions report must not auto-import mappings.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "EbayCategorySuggestionReportServiceTest passed\n";
