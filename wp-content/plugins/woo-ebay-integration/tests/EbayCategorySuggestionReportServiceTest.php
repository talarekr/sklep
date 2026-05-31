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
$joinedQueries = implode(' | ', $queries);
$assert(str_contains($joinedQueries, 'Klimaanlagenschlauch') || str_contains($joinedQueries, 'Klimaleitung'), 'AC hose query should include Klimaanlagenschlauch or Klimaleitung.');
$assert(str_contains($joinedQueries, 'Autoteile'), 'AC hose query should include German automotive context.');
$assert(!str_contains($queries[0] ?? '', 'Wąż'), 'Primary AC hose query must not be raw Polish.');

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

$badAndGood = EbayCategorySuggestionReportService::parse_suggestions([
    ['category' => ['categoryId' => '176984', 'categoryName' => 'CDs'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Musik']]],
    ['category' => ['categoryId' => '9886', 'categoryName' => 'Sonstige'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Sammeln & Seltenes']]],
    ['category' => ['categoryId' => '33544', 'categoryName' => 'Klimaleitungen, -schläuche & Anschlüsse fürs Auto'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Auto & Motorrad: Teile'], ['categoryName' => 'Autoteile & Zubehör']]],
], 5);
$filtered = EbayCategorySuggestionReportService::filter_and_rank_suggestions($badAndGood, 'Wąż / Przewód klimatyzacji A/C', 'Części > Wąż / Przewód klimatyzacji A/C', 'Klimaanlagenschlauch Klimaleitung Autoteile', 3);
$assert(($filtered[0]['category_id'] ?? '') === '33544', 'Automotive AC suggestion should outrank CDs/Sonstige.');
$assert(!in_array('176984', array_map(static fn(array $row): string => (string) ($row['category_id'] ?? ''), $filtered), true), 'CDs should be rejected for automotive Woo category.');
$assert(EbayCategorySuggestionReportService::confidence('61175', ['valid' => false, 'leaf' => false], $filtered, 'invalid_current', 'Klimaanlagenschlauch Klimaleitung Autoteile') === 'high', 'Automotive path with query match should get high confidence.');

$invalidStatus = EbayCategorySuggestionReportService::mapping_status('61175', ['valid' => false, 'leaf' => false], $parsed);
$assert($invalidStatus === 'invalid_current', 'Invalid current category should produce invalid_current.');

$likelyOk = EbayCategorySuggestionReportService::mapping_status('33544', ['valid' => true, 'leaf' => true], $parsed);
$assert($likelyOk === 'likely_ok', 'Valid leaf current category in top suggestions should produce likely_ok.');

$reportColumns = EbayCategorySuggestionReportService::suggestion_report_columns();
foreach (['woo_subcategory_id', 'current_ebay_category_valid', 'suggested_ebay_category_id_3', 'raw_polish_query', 'translated_german_query', 'query_used', 'query_source', 'translation_source', 'taxonomy_error'] as $column) {
    $assert(in_array($column, $reportColumns, true), 'Missing suggestions CSV column: ' . $column);
}

$readyColumns = EbayCategorySuggestionReportService::ready_to_import_columns();
$assert($readyColumns === ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','products_count','old_ebay_category_id','ebay_category_id','suggested_ebay_category_path','confidence','mapping_status','note'], 'Ready-to-import CSV columns should match required import shape.');

$assert(!in_array('imported_at', $readyColumns, true), 'Suggestions report must not auto-import mappings.');

$nonAutoOnly = EbayCategorySuggestionReportService::parse_suggestions([
    ['category' => ['categoryId' => '123', 'categoryName' => 'Kunstdrucke'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Kunst']]],
], 5);
$rejected = [];
$filteredNonAuto = EbayCategorySuggestionReportService::filter_and_rank_suggestions($nonAutoOnly, 'Kamera cofania', 'Części samochodowe > Kamera cofania', 'Rückfahrkamera Autoteile', 3, $rejected);
$assert($filteredNonAuto === [], 'Non-automotive suggestions should be rejected for automotive Woo categories.');
$assert(($rejected[0]['rejected_reason'] ?? '') === 'rejected_non_automotive', 'Rejected non-automotive suggestion should expose rejection reason.');
$assert(EbayCategorySuggestionReportService::mapping_status('', ['valid' => false, 'leaf' => false], $filteredNonAuto) === 'needs_manual_review', 'Only rejected suggestions should force manual review.');
$assert(EbayCategorySuggestionReportService::confidence('', ['valid' => false, 'leaf' => false], $filteredNonAuto, 'needs_manual_review', 'Rückfahrkamera Autoteile') === 'low', 'Only rejected suggestions should get low confidence.');

$cameraVsAc = EbayCategorySuggestionReportService::parse_suggestions([
    ['category' => ['categoryId' => '33544', 'categoryName' => 'Klimaleitungen, -schläuche & Anschlüsse'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Auto & Motorrad: Teile'], ['categoryName' => 'Autoteile & Zubehör']]],
], 5);
$rejected = [];
$filteredCamera = EbayCategorySuggestionReportService::filter_and_rank_suggestions($cameraVsAc, 'Kamera cofania', 'Części samochodowe > Kamera cofania', 'Rückfahrkamera Einparkhilfe Kamera Autoteile', 3, $rejected);
$assert($filteredCamera === [], 'Kamera cofania must not map to Klimaleitungen.');
$assert(in_array(($rejected[0]['rejected_reason'] ?? ''), ['negative_rule_reverse_camera_to_ac_hoses', 'rejected_semantic_mismatch'], true), 'Kamera cofania to Klimaleitungen should be rejected by negative or semantic rule.');

$panelVsAc = EbayCategorySuggestionReportService::filter_and_rank_suggestions($cameraVsAc, 'Panel klimatyzacji', 'Części samochodowe > Panel klimatyzacji', 'Klimabedienteil Klima Bedienteil Autoteile', 3, $rejected);
$assert($panelVsAc === [], 'Panel klimatyzacji must not map to Klimaleitungen.');

$diy = EbayCategorySuggestionReportService::parse_suggestions([
    ['category' => ['categoryId' => '456', 'categoryName' => 'Steuerungen'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Heimwerker']]],
], 5);
$filteredDiy = EbayCategorySuggestionReportService::filter_and_rank_suggestions($diy, 'Sterownik / Moduł komfortu', 'Części samochodowe > Sterownik / Moduł komfortu', 'Steuergerät Komfortmodul Autoteile', 3, $rejected);
$assert($filteredDiy === [], 'Sterownik / Moduł must not map to Heimwerker.');

$household = EbayCategorySuggestionReportService::parse_suggestions([
    ['category' => ['categoryId' => '789', 'categoryName' => 'Stand- & Tischventilatoren'], 'categoryTreeNodeAncestors' => [['categoryName' => 'Haushaltsgeräte']]],
], 5);
$filteredHousehold = EbayCategorySuggestionReportService::filter_and_rank_suggestions($household, 'Wentylator / Komplet', 'Części samochodowe > Wentylator / Komplet', 'Lüfter Gebläse Autoteile', 3, $rejected);
$assert($filteredHousehold === [], 'Wentylator must not map to Haushaltsgeräte.');

$assert(EbayCategorySuggestionReportService::is_uncategorized_woo_category('Bez kategorii', '') === true, 'Bez kategorii should be detected as uncategorized.');

$ref = new ReflectionClass(EbayCategorySuggestionReportService::class);
$service = $ref->newInstanceWithoutConstructor();
$readyMethod = $ref->getMethod('build_ready_row');
$readyMethod->setAccessible(true);
$unsafeReport = [
    'woo_subcategory_id' => '1', 'woo_category_id' => '1', 'woo_subcategory_name' => 'Kamera cofania', 'woo_category_path' => 'Części samochodowe > Kamera cofania',
    'products_count' => '3', 'confidence' => 'low', 'mapping_status' => 'needs_manual_review', 'automotive_path_match' => '1', 'semantic_match_score' => '0', 'note' => 'suggestions_rejected:rejected_semantic_mismatch',
];
$unsafeReady = $readyMethod->invoke($service, $unsafeReport, '', ['category_id' => '33544', 'category_path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klimaleitungen, -schläuche & Anschlüsse']);
$assert(($unsafeReady['ebay_category_id'] ?? '') === '', 'Ready-to-import row should not include low/rejected/manual-review suggestions.');
$assert(($unsafeReady['mapping_status'] ?? '') === 'needs_manual_review', 'Unsafe ready-to-import row should be marked manual review.');
$safeReport = [
    'woo_subcategory_id' => '2', 'woo_category_id' => '2', 'woo_subcategory_name' => 'Wąż / Przewód klimatyzacji A/C', 'woo_category_path' => 'Części samochodowe > Wąż / Przewód klimatyzacji A/C',
    'products_count' => '3', 'confidence' => 'high', 'mapping_status' => 'review_suggested', 'automotive_path_match' => '1', 'semantic_match_score' => '0.75', 'note' => 'Suggestion only; no production mapping was changed.',
];
$safeReady = $readyMethod->invoke($service, $safeReport, '', ['category_id' => '33544', 'category_name' => 'Klimaleitungen, -schläuche & Anschlüsse', 'category_path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klimaleitungen, -schläuche & Anschlüsse']);
$assert(($safeReady['ebay_category_id'] ?? '') === '33544', 'Ready-to-import row may include high-confidence automotive semantic match.');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "EbayCategorySuggestionReportServiceTest passed\n";
