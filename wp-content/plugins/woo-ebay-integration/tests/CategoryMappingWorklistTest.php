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
$assert(str_contains($adminSource, 'CATEGORY_MAPPING_WORKLIST_FILENAME'), 'Download allow-list must include category-mapping-worklist.csv.');
$assert(str_contains($viewSource, 'Download category-mapping-worklist.csv'), 'Admin UI must show a download link after the worklist export completes.');

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
