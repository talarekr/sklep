<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\AdminPage;
use GPSwiss\Ovoko\Services\OvokoDonorCarsCsvExportService;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';
require_once dirname(__DIR__) . '/src/Services/OvokoDonorCarsCsvExportService.php';
require_once dirname(__DIR__) . '/src/Services/AdminPage.php';

$GLOBALS['gpswiss_download_test_upload_dir'] = sys_get_temp_dir() . '/gpswiss-donor-download-test-' . md5(__FILE__);
$GLOBALS['gpswiss_download_test_api_calls'] = [];
$GLOBALS['gpswiss_download_test_writes'] = [];
$GLOBALS['gpswiss_download_test_nonce_actions'] = [];

function current_user_can(string $capability): bool { return $capability === 'manage_options'; }
function sanitize_key(string $key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
function check_admin_referer(string $action): void { $GLOBALS['gpswiss_download_test_nonce_actions'][] = $action; if (!isset($_GET['_wpnonce'])) { wp_die('Nonce missing'); } }
function wp_die(string $message): never { echo $message; exit(70); }
function wp_upload_dir($time = null, bool $create_dir = true): array { return ['basedir' => $GLOBALS['gpswiss_download_test_upload_dir']]; }
function trailingslashit(string $value): string { return rtrim($value, '/\\') . '/'; }
function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986); }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . ltrim($path, '/'); }
function nocache_headers(): void {}
function wp_remote_post(string $url, array $args): array { $GLOBALS['gpswiss_download_test_api_calls'][] = [$url, $args]; return []; }
function wp_mkdir_p(string $dir): bool { $GLOBALS['gpswiss_download_test_writes'][] = ['mkdir', $dir]; return is_dir($dir) || mkdir($dir, 0777, true); }
function update_option(string $key, mixed $value, ?bool $autoload = null): bool { $GLOBALS['gpswiss_download_test_writes'][] = ['update_option', $key]; return true; }

function gpswiss_download_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gpswiss_download_admin_page(): AdminPage
{
    $ref = new ReflectionClass(AdminPage::class);
    return $ref->newInstanceWithoutConstructor();
}

function gpswiss_download_prepare_files(): void
{
    $dir = $GLOBALS['gpswiss_download_test_upload_dir'] . '/gpswiss-ovoko-integration/donor-cars-export';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . '/' . OvokoDonorCarsCsvExportService::CSV_FILENAME, "id,name\n1,Audi\n");
    file_put_contents($dir . '/' . OvokoDonorCarsCsvExportService::SUMMARY_FILENAME, "{\"cars_exported\":1}\n");
}

if (($argv[1] ?? '') === 'child') {
    gpswiss_download_prepare_files();
    $_GET = ['export_id' => 'donor_cars', '_wpnonce' => 'nonce', $argv[2] ?? 'type' => $argv[3] ?? ''];
    if (in_array($argv[2] ?? '', ['export_id', 'ampexport_id'], true)) {
        $_GET['type'] = 'csv';
    }
    if (($argv[4] ?? '') === 'ampnonce') {
        unset($_GET['_wpnonce']);
        $_GET['amp_wpnonce'] = 'nonce';
    }
    $GLOBALS['gpswiss_download_test_api_calls'] = [];
    $GLOBALS['gpswiss_download_test_writes'] = [];
    gpswiss_download_admin_page()->handle_download_donor_cars_export();
}

function gpswiss_download_run_child(string $param, string $value, bool $ampNonce = false): array
{
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($param) . ' ' . escapeshellarg($value) . ($ampNonce ? ' ampnonce' : '') . ' 2>&1';
    exec($cmd, $output, $code);
    return ['code' => $code, 'output' => implode("\n", $output)];
}

$exporter = new OvokoDonorCarsCsvExportService(new GPSwiss\Ovoko\Services\RrrApiClient([]));
$urls = $exporter->download_urls();
gpswiss_download_assert(str_contains($urls['csv'], 'export_id=donor_cars') && str_contains($urls['csv'], 'type=csv'), 'CSV URL must use accepted type=csv and export_id=donor_cars.');
gpswiss_download_assert(!str_contains($urls['csv'], 'ampexport_id=') && !str_contains($urls['csv'], 'amptype='), 'CSV URL must not contain malformed amp-prefixed parameter names.');
gpswiss_download_assert(!str_contains($urls['csv'], '&amp;') && substr_count($urls['csv'], '&') >= 3, 'CSV URL must be raw for JSON/JS and not HTML entity encoded.');
gpswiss_download_assert(str_contains($urls['summary'], 'export_id=donor_cars') && str_contains($urls['summary'], 'type=summary'), 'Summary URL must use accepted type=summary and export_id=donor_cars.');
echo "PASS generated download URLs use accepted donor cars types without double-escaped ampersands\n";

$variants = [
    ['type', 'csv', 'id,name'],
    ['type', 'summary', 'cars_exported'],
    ['type', 'donor_cars_csv', 'id,name'],
    ['type', 'donor_cars_summary', 'cars_exported'],
    ['file', 'csv', 'id,name'],
    ['file', 'summary', 'cars_exported'],
    ['export_type', 'csv', 'id,name'],
    ['export_type', 'summary', 'cars_exported'],
    ['export_type', 'donor_cars_csv', 'id,name'],
    ['export_type', 'donor_cars_summary', 'cars_exported'],
    ['ampexport_id', 'donor_cars', 'id,name'],
    ['amptype', 'csv', 'id,name'],
    ['ampfile', 'summary', 'cars_exported'],
    ['ampexport_type', 'csv', 'id,name'],
];
foreach ($variants as [$param, $value, $expected]) {
    $result = gpswiss_download_run_child($param, $value);
    gpswiss_download_assert($result['code'] === 0 && str_contains($result['output'], $expected), "Handler must accept {$param}={$value}.");
}
echo "PASS download handler accepts current, legacy, and malformed amp-prefixed parameter variants\n";

$ampNonce = gpswiss_download_run_child('amptype', 'csv', true);
gpswiss_download_assert($ampNonce['code'] === 0 && str_contains($ampNonce['output'], 'id,name'), 'Handler must accept malformed amp_wpnonce fallback after normalization.');
echo "PASS download handler accepts amp_wpnonce fallback after normalization\n";

$invalid = gpswiss_download_run_child('type', 'invalid');
gpswiss_download_assert($invalid['code'] === 70 && str_contains($invalid['output'], 'Invalid export type') && str_contains($invalid['output'], 'GPSwiss Ovoko donor cars download invalid export type'), 'Invalid type must log and return clear error.');
echo "PASS invalid type logs diagnostics and returns clear error\n";

$page = gpswiss_download_admin_page();
$pathCheck = new ReflectionMethod(AdminPage::class, 'is_donor_cars_export_file');
$pathCheck->setAccessible(true);
gpswiss_download_prepare_files();
$dir = $GLOBALS['gpswiss_download_test_upload_dir'] . '/gpswiss-ovoko-integration/donor-cars-export';
gpswiss_download_assert($pathCheck->invoke($page, '/etc/passwd', $dir) === false, 'Path traversal/outside file must be blocked.');
echo "PASS path traversal is blocked\n";

gpswiss_download_assert($GLOBALS['gpswiss_download_test_api_calls'] === [], 'Download tests must not call Ovoko API.');
gpswiss_download_assert(array_filter($GLOBALS['gpswiss_download_test_writes'], static fn($w) => $w[0] !== 'mkdir') === [], 'Download handler must not write application data.');
echo "PASS no Ovoko API calls or application writes happen during download\n";


$serviceSource = file_get_contents(dirname(__DIR__) . '/src/Services/OvokoDonorCarsCsvExportService.php');
$fputcsvLines = array_values(array_filter(explode("\n", $serviceSource), static fn(string $line): bool => str_contains($line, 'fputcsv(')));
gpswiss_download_assert($fputcsvLines !== [], 'Donor-cars export service must contain fputcsv calls.');
foreach ($fputcsvLines as $line) {
    gpswiss_download_assert(str_contains($line, ", ',', '\"', '\\\\')"), 'Every donor-cars fputcsv call must pass explicit delimiter, enclosure, and escape arguments.');
}
echo "PASS donor-cars fputcsv calls pass explicit escape argument\n";
