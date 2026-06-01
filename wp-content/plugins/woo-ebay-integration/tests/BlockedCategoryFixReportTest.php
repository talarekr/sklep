<?php

declare(strict_types=1);

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

$GLOBALS['wei_test_options'] = [];
$GLOBALS['wei_test_upload_dir'] = sys_get_temp_dir() . '/wei-blocked-category-test-' . getmypid();

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        return 'https://example.test/wp-content/' . ltrim($path, '/');
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
if (!function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy)
    {
        return (object) ['term_id' => $termId, 'name' => 'Wąż / Przewód klimatyzacji A/C'];
    }
}
if (!function_exists('get_terms')) {
    function get_terms(array $args = []): array
    {
        return [];
    }
}

require_once __DIR__ . '/../src/Services/CategoryMappingSafety.php';
require_once __DIR__ . '/../src/Services/EbayDeCategoryRuleMapper.php';
require_once __DIR__ . '/../src/Services/EbayTaxonomyService.php';
require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Repositories/CategoryMappingRepository.php';
require_once __DIR__ . '/../src/Services/BlockedCategoryFixReportService.php';

use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\BlockedCategoryFixReportService;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\Logger;

final class BlockedCategoryFixReportTestLogger extends Logger
{
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
}

final class BlockedCategoryFixReportTestTaxonomy extends EbayTaxonomyService
{
    public function __construct() {}

    public function validate_category_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        return [];
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

$assert(str_contains($adminSource, "add_action('admin_post_wei_generate_blocked_category_fix_report'"), 'Fix report admin-post hook must be registered.');
$assert(str_contains($viewSource, 'name="action" value="wei_generate_blocked_category_fix_report"'), 'Fix report button must submit the fix report action.');
$assert(str_contains($adminSource, "'action' => 'generate_blocked_category_fix_report'"), 'Handler response must include action=generate_blocked_category_fix_report.');
$assert(str_contains($adminSource, 'missing_problems_only_csv_run_category_audit_first'), 'Missing problems-only CSV must return the explicit error code.');
$assert(str_contains($adminSource, 'category_dashboard_summary'), 'Dashboard summary must be nested under category_dashboard_summary.');
$assert(str_contains($viewSource, 'download_wei_report'), 'UI must expose an admin download fallback link for generated report files.');

$csvPath = trailingslashit($GLOBALS['wei_test_upload_dir']) . 'source-problems.csv';
wp_mkdir_p(dirname($csvPath));
$fh = fopen($csvPath, 'wb');
fputcsv($fh, ['status', 'product_id', 'title', 'woo_category_id', 'woo_category_path', 'current_ebay_category_id', 'current_ebay_category_path']);
fputcsv($fh, ['blocked_by_category', '123', 'Przewód klimatyzacji Audi A4', '55', 'Części samochodowe > Wąż / Przewód klimatyzacji A/C', '33516', 'Old path']);
fputcsv($fh, ['valid', '124', 'Ignored product', '56', 'Części samochodowe > Inne', '33516', 'Old path']);
fclose($fh);

$repo = (new ReflectionClass(CategoryMappingRepository::class))->newInstanceWithoutConstructor();
$service = new BlockedCategoryFixReportService($repo, new BlockedCategoryFixReportTestTaxonomy(), new BlockedCategoryFixReportTestLogger());
$result = $service->generate_from_audit($csvPath, 'EBAY_DE');

$assert(($result['action'] ?? '') === 'generate_blocked_category_fix_report', 'Report result must expose the fix report action.');
$assert(($result['result'] ?? '') === 'success', 'Report generation should succeed for writable uploads.');
$assert(($result['blocked_by_category_rows'] ?? null) === 1, 'Report must count blocked_by_category_rows.');
$assert(array_key_exists('fix_import_csv_url', $result) && (string) $result['fix_import_csv_url'] !== '', 'Report must return fix_import_csv_url.');
$assert(($result['fix_import_csv_exists'] ?? false) === true, 'Report must confirm fix import CSV exists.');
$assert((int) ($result['fix_import_csv_size'] ?? 0) > 0, 'Report must confirm fix import CSV size is greater than zero.');
$assert(!isset($result['marketplace_id']), 'Fix report result must not be replaced by the generic category dashboard summary.');

$GLOBALS['wei_test_upload_dir'] = '/proc/wei-blocked-category-test-' . getmypid();
$unwritable = $service->generate_from_audit($csvPath, 'EBAY_DE');
$assert(($unwritable['result'] ?? '') === 'error', 'Unwritable uploads must make the report fail.');
$assert(($unwritable['error'] ?? '') === 'upload_dir_not_writable', 'Unwritable uploads must return upload_dir_not_writable.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Blocked category fix report tests passed\n";
