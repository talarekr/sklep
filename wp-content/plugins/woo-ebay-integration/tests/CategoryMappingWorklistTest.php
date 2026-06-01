<?php

declare(strict_types=1);

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['wei_test_options'] = [];
$GLOBALS['wei_test_upload_dir'] = sys_get_temp_dir() . '/wei-category-worklist-test-' . getmypid();
$GLOBALS['wei_test_terms'] = [];

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        return 'https://example.test/wp-content' . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        return [
            'basedir' => $GLOBALS['wei_test_upload_dir'],
            'baseurl' => 'https://example.test/wp-content/uploads',
        ];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0777, true);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $flags = 0): string
    {
        return json_encode($data, $flags | JSON_UNESCAPED_SLASHES) ?: '';
    }
}
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_test_options'][$key] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['wei_test_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $key) ?? '');
    }
}
if (!function_exists('absint')) {
    function absint($value): int
    {
        return abs((int) $value);
    }
}
if (!function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy)
    {
        return isset($GLOBALS['wei_test_terms'][$termId]) ? (object) ['term_id' => $termId, 'name' => $GLOBALS['wei_test_terms'][$termId]] : null;
    }
}
if (!function_exists('get_terms')) {
    function get_terms(array $args = []): array
    {
        return [];
    }
}

final class CategoryMappingWorklistTestWpdb
{
    public string $posts = 'wp_posts';
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy = 'wp_term_taxonomy';

    public function prepare(string $query, ...$args): string
    {
        return vsprintf(str_replace(['%d', '%s'], ['%d', "'%s'"], $query), $args);
    }

    public function get_var(string $query): int
    {
        return 7;
    }
}
$GLOBALS['wpdb'] = new CategoryMappingWorklistTestWpdb();

require_once __DIR__ . '/../src/Services/EbayTaxonomyService.php';
require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Repositories/CategoryMappingRepository.php';
require_once __DIR__ . '/../src/Services/EbayDeCategoryRuleMapper.php';
require_once __DIR__ . '/../src/Services/BlockedCategoryFixReportService.php';

use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\BlockedCategoryFixReportService;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\Logger;

final class CategoryMappingWorklistTestLogger extends Logger
{
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
}

final class CategoryMappingWorklistTestTaxonomy extends EbayTaxonomyService
{
    public int $cachedCategoryCalls = 0;
    public int $apiCalls = 0;

    public function __construct() {}

    public function cached_category(string $marketplace_id, string $category_id): ?array
    {
        $this->cachedCategoryCalls++;
        $categories = [
            '33516' => ['category_id' => '33516', 'category_name' => 'Old cached category', 'category_path' => 'Old > Cached', 'leaf' => true],
            '12345' => ['category_id' => '12345', 'category_name' => 'Manual category', 'category_path' => 'Auto > Manual', 'leaf' => true],
            '99999' => ['category_id' => '99999', 'category_name' => 'Other manual category', 'category_path' => 'Auto > Other', 'leaf' => true],
            '33566' => ['category_id' => '33566', 'category_name' => 'Pompa ABS', 'category_path' => 'Auto > Brakes > ABS pumps', 'leaf' => true],
        ];
        return $categories[$category_id] ?? null;
    }

    public function category_cache_diagnostic(string $marketplace_id = 'EBAY_DE', array $sampleCategoryIds = []): array
    {
        $lookup = [];
        foreach (($sampleCategoryIds !== [] ? $sampleCategoryIds : ['33544', '33615', '33566', '9886', '171115']) as $categoryId) {
            $cached = $this->cached_category($marketplace_id, (string) $categoryId);
            $lookup[(string) $categoryId] = ['found' => is_array($cached), 'leaf' => is_array($cached) ? !empty($cached['leaf']) : null];
        }
        return ['marketplace_id' => $marketplace_id, 'total_cached_categories' => 4, 'cached_leaf_categories' => 4, 'cached_non_leaf_categories' => 0, 'taxonomy_version' => '', 'last_cache_refresh_import_time' => '2026-06-01 00:00:00', 'sample_lookup_by_category_id' => $lookup, 'cache_status' => 'cache_available'];
    }

    public function get_category_details_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        $this->apiCalls++;
        return [];
    }

    public function validate_category_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        $this->apiCalls++;
        return [];
    }
}

final class CategoryMappingWorklistTestRepository extends CategoryMappingRepository
{
    public array $savedMappings = [];
    public array $mappingRows = [];
    public array $manualMappingCategories = [];
    public array $sampleProductsByCategory = [];

    public function __construct() {}

    public function woo_category_path(int $termId): string
    {
        return 'Woo path ' . $termId;
    }

    public function list_mapping_rows_for_woo_category(int $wooCategoryId, string $marketplaceId = 'EBAY_DE'): array
    {
        $rows = $this->mappingRows[$wooCategoryId] ?? [];
        usort($rows, static function (array $a, array $b): int {
            return [(int) ($a['resolver_priority'] ?? 99), (string) ($b['reviewed_at'] ?? $b['updated_at'] ?? ''), (int) ($b['id'] ?? 0)] <=> [(int) ($b['resolver_priority'] ?? 99), (string) ($a['reviewed_at'] ?? $a['updated_at'] ?? ''), (int) ($a['id'] ?? 0)];
        });
        return $rows;
    }

    public function resolveProductionCategoryMapping(int $wooCategoryId, string $marketplaceId = 'EBAY_DE'): ?array
    {
        foreach ($this->list_mapping_rows_for_woo_category($wooCategoryId, $marketplaceId) as $row) {
            if ((int) ($row['resolver_priority'] ?? 99) < 90) {
                $row['resolver_reason'] = $this->resolver_reason_for_row($row);
                return $row;
            }
        }
        return null;
    }



    public function list_manual_mapping_categories(string $marketplaceId = 'EBAY_DE', array $args = []): array
    {
        $rows = [];
        foreach ($this->manualMappingCategories as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            $row['sample_products'] = $this->sampleProductsByCategory[$termId] ?? [];
            $rows[] = $row;
        }
        return $rows;
    }

    public function sample_products_for_category(int $term_id, int $limit = 5): array
    {
        return array_slice($this->sampleProductsByCategory[$term_id] ?? [], 0, max(1, min(10, $limit)));
    }

    public function save_manual_worklist_mapping(int $wooCategoryId, string $marketplaceId, array $category): array
    {
        $selectedId = count($this->savedMappings) + 100;
        $duplicatesDisabled = 0;
        foreach ($this->mappingRows[$wooCategoryId] ?? [] as &$row) {
            if (($row['source'] ?? '') !== 'manual') {
                $row['active'] = 0;
                $row['status'] = 'disabled_duplicate';
                $row['resolver_priority'] = 90;
                $duplicatesDisabled++;
            }
        }
        unset($row);
        $mapping = ['id' => $selectedId, 'woo_term_id' => $wooCategoryId, 'marketplace_id' => $marketplaceId, 'ebay_category_id' => (string) $category['category_id'], 'source' => 'manual_worklist', 'status' => 'mapped_manual', 'active' => 1, 'reviewed_at' => '2026-06-01 00:00:00', 'updated_at' => '2026-06-01 00:00:00', 'resolver_priority' => 20];
        $this->mappingRows[$wooCategoryId][] = $mapping;
        $this->savedMappings[] = ['woo_category_id' => $wooCategoryId, 'marketplace_id' => $marketplaceId, 'category' => $category];
        return ['selected_id' => $selectedId, 'duplicates_disabled' => $duplicatesDisabled, 'operation' => 'inserted', 'mapping' => $this->resolveProductionCategoryMapping($wooCategoryId, $marketplaceId)];
    }
}

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

$expectedHeaders = ['final_ebay_category_id','sample_product_title','woo_category_id','woo_category_name','blocked_product_count','total_product_count_in_category','current_ebay_category_id','current_ebay_category_name','current_ebay_category_path','problem_type','sample_product_id','sample_product_ids','sample_product_titles','manual_notes'];
foreach ($expectedHeaders as $header) {
    $assert(str_contains($serviceSource, "'" . $header . "'"), 'category-mapping-worklist.csv must include column ' . $header . '.');
}
$assert(str_contains($serviceSource, "return ['" . implode("','", $expectedHeaders) . "'];"), 'category-mapping-worklist.csv headers must put final_ebay_category_id first and sample_product_title second.');

$assert(str_contains($serviceSource, 'generate_category_mapping_worklist'), 'Worklist export service method must exist.');
$assert(str_contains($serviceSource, 'generate_all_category_mapping_worklist'), 'All-category worklist export service method must exist.');
$assert(str_contains($serviceSource, 'ALL_CATEGORY_MAPPING_WORKLIST_FILENAME'), 'All-category worklist filename constant must exist.');
$assert(str_contains($serviceSource, 'import_category_mapping_worklist'), 'Worklist import service method must exist.');
$assert(str_contains($serviceSource, 'if ($problemType === \'\')') && str_contains($serviceSource, 'continue;'), 'Worklist export must exclude non-category readiness problems and ready rows.');
$assert(str_contains($serviceSource, 'sample_product_title') && str_contains($serviceSource, 'product_title'), 'Worklist export must include one representative sample product title.');
$assert(str_contains($serviceSource, 'sample_product_id') && str_contains($serviceSource, "['sample_product_id'] = \$pid"), 'Worklist export must include sample_product_id from the same row as sample_product_title.');
$assert(str_contains($serviceSource, 'skipped_empty_final_ebay_category_id') && str_contains($serviceSource, 'if ($finalCategoryId === \'\')'), 'Import must skip empty final_ebay_category_id rows.');
$assert(str_contains($serviceSource, "'invalid_ebay_category_id'") && str_contains($serviceSource, 'cached_category($marketplaceId, $finalCategoryId)'), 'Import must reject invalid eBay category IDs using cached taxonomy only.');
$assert(str_contains($serviceSource, "'non_leaf_category'") && str_contains($serviceSource, "empty(\$category['leaf'])"), 'Import must reject non-leaf categories.');
$assert(str_contains($serviceSource, "'ebay_api_called' => false"), 'Import must report that it does not call eBay API.');
$assert(!str_contains($serviceSource, 'get_category_details_result($marketplaceId, $finalCategoryId') && !str_contains($serviceSource, 'validate_category_result($marketplaceId, $finalCategoryId'), 'Import must not call taxonomy methods that can call eBay API.');
$assert(!str_contains($serviceSource, 'update_post_meta(') && !str_contains($serviceSource, 'export_product('), 'Worklist import must not modify products or publish listings.');
$assert(str_contains($repoSource, "'source' => 'manual_worklist'"), 'Valid import must save category mapping with source manual_worklist.');
$assert(str_contains($repoSource, 'disabled_duplicate'), 'Import must deduplicate/deactivate older duplicate mappings.');
$assert(str_contains($adminSource, "add_action('admin_post_wei_generate_category_mapping_worklist'") && str_contains($adminSource, "add_action('admin_post_wei_import_category_mapping_worklist'"), 'Admin hooks must exist for worklist export/import.');
$assert(str_contains($adminSource, "add_action('admin_post_wei_generate_all_category_mapping_worklist'"), 'Admin hook must exist for all-category worklist export.');
$assert(str_contains($adminSource, 'CATEGORY_MAPPING_WORKLIST_FILENAME'), 'Download allow-list must include category-mapping-worklist.csv.');
$assert(str_contains($adminSource, 'ALL_CATEGORY_MAPPING_WORKLIST_FILENAME'), 'Download allow-list must include all-category-mapping-worklist.csv.');
$assert(str_contains($viewSource, 'Download category-mapping-worklist.csv'), 'Admin UI must show a download link after the worklist export completes.');
$assert(str_contains($viewSource, 'Generate all-category-mapping-worklist.csv') && str_contains($viewSource, 'Download all-category-mapping-worklist.csv'), 'Admin UI must expose all-category worklist generate and download controls.');
$assert(str_contains($viewSource, 'Use all-category-mapping-worklist.csv to complete mapping for every Woo category with products.'), 'Admin UI must explain the all-category mapping worklist purpose.');

$GLOBALS['wei_test_terms'] = [55 => 'Wąż / Przewód klimatyzacji A/C', 77 => 'Czujniki'];
$sourceCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'source-problems.csv';
wp_mkdir_p(dirname($sourceCsv));
$fh = fopen($sourceCsv, 'wb');
fputcsv($fh, ['status', 'product_id', 'product_title', 'woo_category_id', 'woo_category_path', 'current_ebay_category_id', 'current_ebay_category_path']);
fputcsv($fh, ['blocked_by_category', '123', 'Przewód klimatyzacji Audi A4', '55', 'Części samochodowe > Wąż / Przewód klimatyzacji A/C', '33516', '']);
fputcsv($fh, ['blocked_by_category', '124', 'Drugi przewód klimatyzacji Audi A6', '55', 'Części samochodowe > Wąż / Przewód klimatyzacji A/C', '33516', 'Old path']);
fputcsv($fh, ['invalid_ebay_category_id', '777', 'Czujnik ABS BMW E90', '77', 'Części samochodowe > Czujniki', '', '']);
fputcsv($fh, ['ready', '888', 'Ignored ready product', '88', 'Części samochodowe > Ignored', '', '']);
fclose($fh);

$taxonomy = new CategoryMappingWorklistTestTaxonomy();
$repo = new CategoryMappingWorklistTestRepository();
$service = new BlockedCategoryFixReportService($repo, $taxonomy, new CategoryMappingWorklistTestLogger());
$result = $service->generate_category_mapping_worklist($sourceCsv, 'EBAY_DE');
$assert(($result['result'] ?? '') === 'success', 'Worklist export should succeed.');
$assert(($result['rows'] ?? null) === 2, 'Worklist export must stay one row per Woo category.');
$worklistPath = (string) ($result['worklist_csv_path'] ?? '');
$rows = [];
$fh = fopen($worklistPath, 'rb');
$headers = fgetcsv($fh) ?: [];
while (($data = fgetcsv($fh)) !== false) {
    $rows[] = array_combine($headers, $data) ?: [];
}
fclose($fh);

$assert($headers === $expectedHeaders, 'Exported CSV header order must match the simplified worklist layout.');
$assert(($headers[0] ?? '') === 'final_ebay_category_id', 'final_ebay_category_id must be the first exported column.');
$assert(($headers[1] ?? '') === 'sample_product_title', 'sample_product_title must be the second exported column.');
$assert(count($rows) === 2, 'Exported worklist must have one row per Woo category.');
$assert(($rows[0]['woo_category_id'] ?? '') === '55', 'Worklist must keep sorting by blocked_product_count descending.');
$assert(($rows[0]['blocked_product_count'] ?? '') === '2', 'First worklist row must aggregate blocked product count by Woo category.');
$assert(($rows[0]['sample_product_title'] ?? '') === 'Przewód klimatyzacji Audi A4', 'sample_product_title must contain exactly one representative product title.');
$assert(($rows[0]['sample_product_id'] ?? '') === '123', 'sample_product_id must match sample_product_title.');
$assert(($rows[0]['sample_product_titles'] ?? '') === 'Przewód klimatyzacji Audi A4 | Drugi przewód klimatyzacji Audi A6', 'sample_product_titles must keep multiple examples later in the file.');
$assert(($rows[0]['sample_product_ids'] ?? '') === '123|124', 'sample_product_ids must keep multiple IDs later in the file.');
$assert(($rows[1]['sample_product_title'] ?? '') === 'Czujnik ABS BMW E90', 'Second Woo category must have its own representative title.');
$assert(($rows[1]['sample_product_id'] ?? '') === '777', 'Second Woo category sample_product_id must match its representative title.');


$seedCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'manual-seed-worklist.csv';
$fh = fopen($seedCsv, 'wb');
fputcsv($fh, ['final_ebay_category_id', 'sample_product_title', 'woo_category_id', 'woo_category_name', 'manual_notes']);
fputcsv($fh, ['12345', 'Seeded title', '55', 'Wąż / Przewód klimatyzacji A/C', 'preserve me']);
fclose($fh);

$repo->manualMappingCategories = [
    ['term_id' => 55, 'name' => 'Wąż / Przewód klimatyzacji A/C', 'product_count' => 2],
    ['term_id' => 77, 'name' => 'Czujniki', 'product_count' => 3],
    ['term_id' => 88, 'name' => 'Legacy', 'product_count' => 1],
    ['term_id' => 99, 'name' => 'Empty category', 'product_count' => 0],
];
$repo->sampleProductsByCategory = [
    55 => [['id' => 123, 'title' => 'Przewód klimatyzacji Audi A4'], ['id' => 124, 'title' => 'Drugi przewód klimatyzacji Audi A6']],
    77 => [['id' => 777, 'title' => 'Czujnik ABS BMW E90']],
    88 => [['id' => 888, 'title' => 'Legacy sample']],
    99 => [['id' => 999, 'title' => 'Should be excluded']],
];
$repo->mappingRows[77] = [[
    'id' => 20,
    'woo_term_id' => 77,
    'marketplace_id' => 'EBAY_DE',
    'ebay_category_id' => '33566',
    'ebay_category_name' => 'Pompa ABS',
    'ebay_category_path' => 'Auto > Brakes > ABS pumps',
    'source' => 'manual_worklist',
    'status' => 'mapped_manual',
    'active' => 1,
    'resolver_priority' => 20,
]];
$repo->mappingRows[88] = [[
    'id' => 21,
    'woo_term_id' => 88,
    'marketplace_id' => 'EBAY_DE',
    'ebay_category_id' => '262084',
    'source' => 'legacy',
    'status' => 'mapped_auto',
    'active' => 1,
    'resolver_priority' => 60,
]];
$GLOBALS['wei_test_options']['wei_ebay_category_mapping_worklist_import_summary'] = ['source_csv' => $seedCsv];
$allResult = $service->generate_all_category_mapping_worklist('EBAY_DE', $seedCsv);
$assert(($allResult['result'] ?? '') === 'success', 'All-category worklist export should succeed.');
$assert(($allResult['rows'] ?? null) === 3, 'All-category worklist must include every Woo category with products and exclude empty categories.');
$allRows = [];
$fh = fopen((string) ($allResult['worklist_csv_path'] ?? ''), 'rb');
$allHeaders = fgetcsv($fh) ?: [];
while (($data = fgetcsv($fh)) !== false) {
    $allRows[] = array_combine($allHeaders, $data) ?: [];
}
fclose($fh);
$expectedAllHeaders = ['final_ebay_category_id','sample_product_title','woo_category_id','woo_category_name','product_count','current_ebay_category_id','current_ebay_category_name','current_ebay_category_path','current_mapping_source','current_mapping_status','current_audit_status','sample_product_id','sample_product_ids','sample_product_titles','manual_notes'];
$assert($allHeaders === $expectedAllHeaders, 'All-category worklist header order must match the requested user-friendly layout.');
$assert(($allHeaders[0] ?? '') === 'final_ebay_category_id', 'All-category final_ebay_category_id must be first column.');
$assert(($allHeaders[1] ?? '') === 'sample_product_title', 'All-category sample_product_title must be second column.');
$assert(!in_array('99', array_column($allRows, 'woo_category_id'), true), 'All-category worklist must exclude Woo categories with no products.');
$seededRow = array_values(array_filter($allRows, static fn(array $row): bool => ($row['woo_category_id'] ?? '') === '55'))[0] ?? [];
$assert(($seededRow['final_ebay_category_id'] ?? '') === '12345', 'All-category worklist must preserve user-filled seed CSV mappings.');
$assert(($seededRow['manual_notes'] ?? '') === 'preserve me', 'All-category worklist should carry seed manual notes.');
$manualRow = array_values(array_filter($allRows, static fn(array $row): bool => ($row['woo_category_id'] ?? '') === '77'))[0] ?? [];
$assert(($manualRow['final_ebay_category_id'] ?? '') === '33566', 'All-category worklist must prefill trusted current manual_worklist mappings.');
$legacyRow = array_values(array_filter($allRows, static fn(array $row): bool => ($row['woo_category_id'] ?? '') === '88'))[0] ?? [];
$assert(($legacyRow['final_ebay_category_id'] ?? '') === '', 'All-category worklist must not prefill legacy/untrusted current mappings.');
$assert(($allRows[0]['final_ebay_category_id'] ?? 'not-empty') === '', 'All-category worklist must sort empty final_ebay_category_id rows first.');
$assert(($manualRow['sample_product_title'] ?? '') === 'Czujnik ABS BMW E90' && ($manualRow['sample_product_id'] ?? '') === '777', 'All-category representative title and sample_product_id must match.');
$assert(($allResult['ebay_api_called'] ?? null) === false && $taxonomy->apiCalls === 0, 'All-category worklist generation must not call eBay API methods.');

$oldOrderCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'old-order-worklist.csv';
$oldHeaders = ['woo_category_id','woo_category_name','blocked_product_count','total_product_count_in_category','current_ebay_category_id','current_ebay_category_name','current_ebay_category_path','problem_type','sample_product_ids','sample_product_titles','final_ebay_category_id','manual_notes'];
$fh = fopen($oldOrderCsv, 'wb');
fputcsv($fh, $oldHeaders);
fputcsv($fh, ['55', 'Wąż / Przewód klimatyzacji A/C', '2', '7', '33516', 'Old', 'Old > Cached', 'blocked_by_category', '123|124', 'Przewód klimatyzacji Audi A4 | Drugi przewód klimatyzacji Audi A6', '12345', '']);
fclose($fh);
$importResult = $service->import_category_mapping_worklist($oldOrderCsv, 'EBAY_DE');
$assert(($importResult['accepted'] ?? null) === 1, 'Importer must still accept old column order worklists.');
$assert(($repo->savedMappings[0]['woo_category_id'] ?? null) === 55, 'Importer must read woo_category_id by header name from old-order CSV.');
$assert(($repo->savedMappings[0]['category']['category_id'] ?? '') === '12345', 'Importer must read final_ebay_category_id by header name from old-order CSV.');

$shuffledCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'shuffled-worklist.csv';
$fh = fopen($shuffledCsv, 'wb');
fputcsv($fh, ['manual_notes', 'final_ebay_category_id', 'sample_product_title', 'woo_category_id']);
fputcsv($fh, ['category id is intentionally not in the old position', '99999', 'Czujnik ABS BMW E90', '77']);
fclose($fh);
$importResult = $service->import_category_mapping_worklist($shuffledCsv, 'EBAY_DE');
$assert(($importResult['accepted'] ?? null) === 1, 'Importer must accept a shuffled worklist when required headers are present.');
$assert(($repo->savedMappings[1]['woo_category_id'] ?? null) === 77, 'Importer must read woo_category_id by header name from shuffled CSV.');
$assert(($repo->savedMappings[1]['category']['category_id'] ?? '') === '99999', 'Importer must read final_ebay_category_id by header name, not by column position.');
$assert($taxonomy->apiCalls === 0, 'Worklist export/import tests must not call eBay API methods.');
$assert(($importResult['ebay_api_called'] ?? null) === false, 'Import summary must report no eBay API calls.');
$assert(($importResult['products_modified'] ?? null) === false, 'Import summary must report no product modifications.');
$assert(($importResult['listings_modified'] ?? null) === false, 'Import summary must report no listing modifications.');
$assert(array_key_exists('inserted_mappings', $importResult), 'Import summary must include inserted_mappings.');
$assert(array_key_exists('updated_mappings', $importResult), 'Import summary must include updated_mappings.');
$assert(array_key_exists('deactivated_duplicate_mappings', $importResult), 'Import summary must include deactivated_duplicate_mappings.');
$assert(array_key_exists('unchanged_mappings', $importResult), 'Import summary must include unchanged_mappings.');
$assert(array_key_exists('import_debug_rows', $importResult), 'Import summary must include first-row debug diagnostics.');
$assert(($importResult['import_debug_rows'][0]['final_ebay_category_id'] ?? '') === '99999', 'Import debug must record final_ebay_category_id.');
$assert(array_key_exists('category_cache_diagnostic', $importResult), 'Import summary must include category cache diagnostic.');

$missingCacheCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'trusted-manual-cache-missing-worklist.csv';
$fh = fopen($missingCacheCsv, 'wb');
fputcsv($fh, ['final_ebay_category_id', 'sample_product_title', 'woo_category_id', 'woo_category_name']);
fputcsv($fh, ['262243', 'Manual trusted category', '5126', 'Manual trusted']);
fclose($fh);
$importResult = $service->import_category_mapping_worklist($missingCacheCsv, 'EBAY_DE');
$assert(($importResult['accepted'] ?? null) === 1, 'Importer must accept trusted manual worklist mappings when local cache is missing the numeric category ID.');
$assert(($importResult['accepted_trusted_manual_cache_missing'] ?? null) === 1, 'Import summary must count trusted manual cache-missing accepted rows.');
$assert(($importResult['rejected'] ?? null) === 0, 'Cache-missing trusted manual mappings must not be rejected.');
$assert(in_array('Imported as trusted manual mapping because local EBAY_DE category cache is missing/incomplete.', $importResult['warnings'] ?? [], true), 'Import summary must warn about trusted manual cache-missing mappings.');
$assert(($repo->savedMappings[2]['woo_category_id'] ?? null) === 5126, 'Cache-missing trusted manual mapping must still be saved for the Woo category.');
$assert(($repo->savedMappings[2]['category']['category_id'] ?? '') === '262243', 'Cache-missing trusted manual mapping must save the numeric final category ID.');
$assert(($repo->savedMappings[2]['category']['cache_validation_status'] ?? '') === 'cache_missing', 'Cache-missing trusted manual mapping must save cache_validation_status=cache_missing.');
$assert(($repo->savedMappings[2]['category']['validation_confidence'] ?? '') === 'trusted_manual', 'Cache-missing trusted manual mapping must save validation_confidence=trusted_manual.');
$assert(($repo->savedMappings[2]['category']['needs_cache_validation'] ?? null) === 1, 'Cache-missing trusted manual mapping must be flagged for later cache validation.');
$validation = $GLOBALS['wei_test_options']['wei_ebay_category_validation_statuses'] ?? [];
$assert(($validation['by_woo_term_id']['5126']['validation_status'] ?? '') === 'cache_missing', 'Validation cache must record cache_missing for trusted manual cache-missing mappings.');
$assert(($validation['by_woo_term_id']['5126']['validation_confidence'] ?? '') === 'trusted_manual', 'Validation cache must record trusted_manual confidence for cache-missing mappings.');
$assert(($validation['by_woo_term_id']['5126']['needs_cache_validation'] ?? null) === 1, 'Validation cache must flag trusted manual cache-missing mappings for later validation.');
$assert(($validation['by_woo_term_id']['77']['category_id'] ?? '') === '99999', 'Import must mark the Woo category validation cache valid for the imported category-level mapping.');
$assert(($validation['by_woo_term_id']['77']['source'] ?? '') === 'manual_worklist_import', 'Validation cache source must show manual_worklist_import.');

$repo->mappingRows[5197] = [[
    'id' => 1,
    'woo_term_id' => 5197,
    'marketplace_id' => 'EBAY_DE',
    'ebay_category_id' => '262084',
    'source' => 'legacy',
    'status' => 'mapped_auto',
    'active' => 1,
    'updated_at' => '2025-01-01 00:00:00',
    'resolver_priority' => 60,
]];
$pompaCsv = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'pompa-abs-worklist.csv';
$fh = fopen($pompaCsv, 'wb');
fputcsv($fh, ['final_ebay_category_id', 'sample_product_title', 'woo_category_id', 'woo_category_name']);
fputcsv($fh, ['33566', 'Pompa ABS', '5197', 'Pompa ABS']);
fclose($fh);
$importResult = $service->import_category_mapping_worklist($pompaCsv, 'EBAY_DE');
$resolved = $repo->resolveProductionCategoryMapping(5197, 'EBAY_DE');
$assert(($resolved['ebay_category_id'] ?? '') === '33566', 'Resolver diagnostic case 5197 must select manual_worklist category 33566, not legacy 262084.');
$assert(($resolved['source'] ?? '') === 'manual_worklist', 'Resolver diagnostic case 5197 must select manual_worklist source.');
$assert(($importResult['deactivated_duplicate_mappings'] ?? 0) >= 1, 'Import must deactivate duplicate lower-priority mappings.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category mapping worklist tests passed\n";
