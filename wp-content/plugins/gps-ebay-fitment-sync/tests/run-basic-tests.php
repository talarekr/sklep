<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['gps_test_options'] = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if (array_key_exists((string) $option, $GLOBALS['gps_test_options'])) {
            return $GLOBALS['gps_test_options'][(string) $option];
        }
        if ($option === GPS_Ebay_Fitment_Sync\Support\Settings::OPTION) {
            return [
                'apify_token' => 'test-token',
                'actor_id' => 'Zt16dqMI2yN7Igggl',
                'lang_id' => 4,
                'country_filter_id' => 63,
                'timeout' => 60,
                'batch_size' => 5,
                'max_apify_lookups_per_batch' => 5,
            ];
        }
        return $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null): bool {
        $GLOBALS['gps_test_options'][(string) $option] = $value;
        return true;
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) { return $value; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        return $GLOBALS['gps_test_post_meta'][(int) $post_id][(string) $key] ?? '';
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value) { return trim((string) $value); }
}
if (!function_exists('current_time')) {
    function current_time($type) { return '2026-06-12 00:00:00'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, $create_dir = true) {
        $basedir = sys_get_temp_dir() . '/gps-fitment-tests/wp-content/uploads';
        return [
            'basedir' => $basedir,
            'baseurl' => 'https://example.test/wp-content/uploads',
            'error' => false,
        ];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($target): bool { return is_dir($target) || mkdir($target, 0777, true); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename): string { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $filename) ?? (string) $filename; }
}

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];
    public int $insert_id = 0;
    public string $last_error = '';
    /** @var array<string, bool> */
    public array $failInsertsFor = [];
    /** @var array<string, array<string, bool>> */
    public array $schemas = [];
    /** @var array<string, int> */
    private array $nextIds = [];

    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . str_replace("'", "''", (string) $arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }

        return $query;
    }

    public function insert(string $table, array $data): bool
    {
        if (!empty($this->failInsertsFor[$table])) {
            $this->last_error = 'Simulated insert failure for ' . $table;
            $this->insert_id = 0;
            return false;
        }
        $this->last_error = '';
        $id = $this->nextIds[$table] ?? 1;
        $this->nextIds[$table] = $id + 1;
        $this->insert_id = $id;
        $this->tables[$table] ??= [];
        $this->tables[$table][] = array_merge(['id' => $id], $data);
        return true;
    }

    public function update(string $table, array $data, array $where): bool
    {
        foreach ($this->tables[$table] ?? [] as $index => $row) {
            if ($this->matches($row, $where)) {
                $this->tables[$table][$index] = array_merge($row, $data);
                return true;
            }
        }
        return false;
    }

    public function delete(string $table, array $where): bool
    {
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], fn(array $row): bool => !$this->matches($row, $where)));
        return true;
    }

    public function get_row(string $query, $output = null)
    {
        if (preg_match("/FROM (\S+) WHERE part_number_normalized = '([^']*)'/", $query, $matches)) {
            foreach ($this->tables[$matches[1]] ?? [] as $row) {
                if ((string) $row['part_number_normalized'] === $matches[2]) {
                    return $row;
                }
            }
        }

        return null;
    }

    public function get_results(string $query, $output = null): array
    {
        if (preg_match('/SHOW COLUMNS FROM (\S+)/', $query, $matches)) {
            $columns = array_keys($this->schemas[$matches[1]] ?? []);
            if (!$columns && isset($this->tables[$matches[1]][0])) {
                $columns = array_keys($this->tables[$matches[1]][0]);
            }
            return array_map(fn(string $column): array => ['Field' => $column], $columns);
        }

        if (preg_match('/SHOW INDEX FROM (\S+)/', $query, $matches)) {
            $indexes = [];
            if (($this->schemas[$matches[1]]['part_number_normalized_index'] ?? false) === true) {
                $indexes[] = ['Key_name' => 'part_number_normalized'];
            }
            return $indexes;
        }

        if (preg_match("/SELECT id, part_number_raw, part_number_normalized, status, article_count, vehicle_count FROM (\S+) WHERE part_number_raw = '([^']*)' OR LOWER\(part_number_normalized\) = LOWER\('([^']*)'\) OR LOWER\(part_number_raw\) = LOWER\('([^']*)'\)/", $query, $matches)) {
            return array_values(array_filter($this->tables[$matches[1]] ?? [], static function (array $row) use ($matches): bool {
                return (string) ($row['part_number_raw'] ?? '') === $matches[2]
                    || strtolower((string) ($row['part_number_normalized'] ?? '')) === strtolower($matches[3])
                    || strtolower((string) ($row['part_number_raw'] ?? '')) === strtolower($matches[4]);
            }));
        }

        if (preg_match('/FROM (\S+) WHERE part_cache_id = (\d+)/', $query, $matches)) {
            $rows = array_values(array_filter($this->tables[$matches[1]] ?? [], fn(array $row): bool => (int) $row['part_cache_id'] === (int) $matches[2]));
            if (str_contains($query, 'ORDER BY vehicle_id')) {
                usort($rows, fn(array $a, array $b): int => (int) $a['vehicle_id'] <=> (int) $b['vehicle_id']);
            } else {
                usort($rows, fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
            }
            return $rows;
        }

        return [];
    }

    public function get_col(string $query): array
    {
        if (preg_match('/SELECT ID FROM \S+ .* LIMIT (\d+) OFFSET (\d+)/', $query, $matches)) {
            $limit = (int) $matches[1];
            $offset = (int) $matches[2];
            $ids = array_map(static fn(array $row): int => (int) $row['ID'], $this->tables[$this->posts] ?? []);
            sort($ids);
            return array_slice($ids, $offset, $limit);
        }

        return [];
    }

    public function get_var(string $query)
    {
        if (preg_match("/SHOW TABLES LIKE '([^']*)'/", $query, $matches)) {
            return (isset($this->schemas[$matches[1]]) || isset($this->tables[$matches[1]])) ? $matches[1] : null;
        }

        if (preg_match('/COUNT\(\*\).*FROM (\S+) WHERE part_number_normalized IN \((.*)\)/', $query, $matches)) {
            preg_match_all("/'([^']*)'/", $matches[2], $keys);
            $wanted = array_flip($keys[1]);
            $count = 0;
            foreach ($this->tables[$matches[1]] ?? [] as $row) {
                if (isset($wanted[(string) $row['part_number_normalized']])) {
                    $count++;
                }
            }
            return $count;
        }

        if (preg_match("/SELECT id FROM (\S+) WHERE product_id = (\d+) AND part_number_normalized = '([^']*)'/", $query, $matches)) {
            foreach ($this->tables[$matches[1]] ?? [] as $row) {
                if ((int) $row['product_id'] === (int) $matches[2] && (string) $row['part_number_normalized'] === $matches[3]) {
                    return $row['id'];
                }
            }
        }

        return null;
    }

    private function matches(array $row, array $where): bool
    {
        foreach ($where as $key => $value) {
            if (($row[$key] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }
}

final class FakeHttpResponse
{
    public function __construct(public int $code, public string $body) {}
}

$GLOBALS['gps_test_http_calls'] = 0;

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, array $args) {
        $GLOBALS['gps_test_http_calls']++;
        $payload = json_decode((string) $args['body'], true);
        if (!empty($payload['endpoint_partsSearchArticlesByOem'])) {
            return new FakeHttpResponse(200, json_encode([[ 'articles' => [
                ['articleId' => 101, 'articleNo' => 'A1', 'supplierId' => 1, 'supplierName' => 'S1'],
                ['articleId' => 102, 'articleNo' => 'A2', 'supplierId' => 2, 'supplierName' => 'S2'],
                ['articleId' => 103, 'articleNo' => 'A3', 'supplierId' => 3, 'supplierName' => 'S3'],
                ['articleId' => 104, 'articleNo' => 'A4', 'supplierId' => 4, 'supplierName' => 'S4'],
            ]]]));
        }

        $supplierId = (int) ($payload['parts_supplierId_21'] ?? 0);
        $ranges = [1 => [1, 20], 2 => [21, 40], 3 => [41, 60], 4 => [61, 82]];
        [$start, $end] = $ranges[$supplierId] ?? [1, 0];
        $cars = [];
        for ($vehicleId = $start; $vehicleId <= $end; $vehicleId++) {
            $cars[] = ['vehicleId' => $vehicleId, 'manufacturerName' => 'VW', 'modelName' => 'Touran'];
        }

        return new FakeHttpResponse(200, json_encode([[ 'compatibleCars' => $cars ]]));
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool { return false; }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int { return $response->code; }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string { return $response->body; }
}

require_once __DIR__ . '/../src/Support/PartNumberNormalizer.php';
require_once __DIR__ . '/../src/Support/PartNumberCandidateValidator.php';
require_once __DIR__ . '/../src/Support/Settings.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Service/ApifyClient.php';
require_once __DIR__ . '/../src/Service/AuditCsvExporter.php';
require_once __DIR__ . '/../src/Service/ProductScanner.php';
require_once __DIR__ . '/../src/Service/FitmentLookupService.php';
require_once __DIR__ . '/../src/Service/KTypeBackfillAutoRunner.php';

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Service\ApifyClient;
use GPS_Ebay_Fitment_Sync\Service\AuditCsvExporter;
use GPS_Ebay_Fitment_Sync\Service\FitmentLookupService;
use GPS_Ebay_Fitment_Sync\Service\KTypeBackfillAutoRunner;
use GPS_Ebay_Fitment_Sync\Service\ProductScanner;
use GPS_Ebay_Fitment_Sync\Support\PartNumberCandidateValidator;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

function assert_same($expected, $actual, string $label): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    echo "PASS {$label}" . PHP_EOL;
}

$normalizer = new PartNumberNormalizer();
assert_same('1T0941329A', $normalizer->normalize('1T0 941 329 A'), 'normalizes spaces');
assert_same('1T0941329A', $normalizer->normalize('1T0-941-329-A'), 'normalizes hyphens');
assert_same('ABC123', $normalizer->normalize(' abc.123 '), 'normalizes dots and case');

$validator = new PartNumberCandidateValidator($normalizer);
foreach (['1T0941329A', '4F0422371E', '8R0867287B', '283426179R', 'A2044600143', '06K907425A', '8K0805607A'] as $validPartNumber) {
    $candidate = $validator->validate($validPartNumber);
    assert_same(true, $candidate['accepted'], 'validator accepts ' . $validPartNumber);
    assert_same($normalizer->normalize($validPartNumber), $candidate['normalized'], 'validator normalized ' . $validPartNumber);
}

foreach (['BRAK', 'FOTELE', 'TCB', 'DXR', 'WGD od audiolcar', 'bt-cars-33123', 'Fotel fotele komplet Audi A3 8P LIFT 5D', '0GC300072H WGC 0FN409053C DNF DNFF DNF 264753'] as $invalidPartNumber) {
    $candidate = $validator->validate($invalidPartNumber);
    assert_same(false, $candidate['accepted'], 'validator rejects ' . $invalidPartNumber);
}

$multiCandidates = $validator->candidates('A2043302701 A2043302601');
assert_same(2, count($multiCandidates), 'validator splits two valid OEM candidates');
assert_same('A2043302701', $multiCandidates[0]['normalized'], 'validator first split candidate');
assert_same('A2043302601', $multiCandidates[1]['normalized'], 'validator second split candidate');
assert_same(true, $multiCandidates[0]['accepted'] && $multiCandidates[1]['accepted'], 'validator accepts split OEM candidates');
assert_same(false, in_array('A2043302701A2043302601', array_column($multiCandidates, 'normalized'), true), 'validator does not concatenate multi-code candidates');

$settings = new Settings();
$client = new ApifyClient($settings);
$articlePayload = $client->article_search_payload('1T0941329A');
assert_same(true, $articlePayload['endpoint_partsSearchArticlesByOem'], 'article payload endpoint flag');
assert_same('1T0941329A', $articlePayload['parts_articleOemNo_29'], 'article payload part number');
assert_same(4, $articlePayload['parts_langId_29'], 'article payload language');

$vehiclePayload = $client->compatible_vehicles_payload('711307329332', 95);
assert_same(true, $vehiclePayload['endpoint_partsCompatibleVehiclesByArticleNoSupplierId'], 'vehicle payload endpoint flag');
assert_same('711307329332', $vehiclePayload['parts_articleNo_21'], 'vehicle payload article number');
assert_same(95, $vehiclePayload['parts_supplierId_21'], 'vehicle payload supplier');
assert_same(63, $vehiclePayload['parts_countryFilterId_21'], 'vehicle payload country');

$articles = ApifyClient::parse_articles([[ 'articles' => [[
    'articleId' => 4402546,
    'articleNo' => '711307329332',
    'supplierId' => 95,
    'supplierName' => 'MAGNETI MARELLI',
]]]]);
assert_same(1, count($articles), 'parses article count');
assert_same(4402546, $articles[0]['articleId'], 'parses articleId');
assert_same('711307329332', $articles[0]['articleNo'], 'parses articleNo');
assert_same(95, $articles[0]['supplierId'], 'parses supplierId');

$vehicles = ApifyClient::parse_vehicles([[ 'compatibleCars' => [[
    'vehicleId' => 12345,
    'manufacturerName' => 'VW',
    'modelName' => 'Touran',
], [
    'vehicleId' => 12345,
    'manufacturerName' => 'VW',
], [
    'vehicleId' => 67890,
    'manufacturerName' => 'Skoda',
]]]]);
assert_same(2, count($vehicles), 'parses and de-duplicates vehicles');
assert_same(12345, $vehicles[0]['vehicleId'], 'parses first vehicleId');
assert_same(67890, $vehicles[1]['vehicleId'], 'parses second vehicleId');

$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$database = new Database($normalizer);
$lookup = new FitmentLookupService($database, $client, $normalizer, $settings);

$first = $lookup->lookup('1T0941329A', true, false);
assert_same('found', $first['status'], 'first live save status');
assert_same(false, $first['from_cache'], 'first live save not from cache');
assert_same(true, $first['saved'], 'first live save saved');
assert_same(false, $first['cache_hit'], 'first live save cache miss');
assert_same('1T0941329A', $first['cache_lookup_key'], 'first live save cache lookup key');
assert_same(4, count($first['articles']), 'first live save article count');
assert_same(82, count($first['unique_vehicle_ids']), 'first live save unique vehicle count');
assert_same(5, $GLOBALS['gps_test_http_calls'], 'first live save Apify call count');

$partRows = $wpdb->tables['wp_gps_fitment_part_cache'] ?? [];
assert_same(1, count($partRows), 'one part cache row saved');
assert_same('1T0941329A', $partRows[0]['part_number_raw'], 'part cache raw part number saved');
assert_same('1T0941329A', $partRows[0]['part_number_normalized'], 'part cache normalized part number saved');
assert_same('found', $partRows[0]['status'], 'part cache found status saved');
assert_same(4, $partRows[0]['article_count'], 'part cache article_count saved');
assert_same(82, $partRows[0]['vehicle_count'], 'part cache vehicle_count saved');

$partCacheId = (int) $partRows[0]['id'];
$articleRows = $wpdb->tables['wp_gps_fitment_article_cache'] ?? [];
$vehicleRows = $wpdb->tables['wp_gps_fitment_vehicle_cache'] ?? [];
assert_same(4, count($articleRows), 'article rows saved');
assert_same(82, count($vehicleRows), 'vehicle rows saved');
assert_same(true, array_reduce($articleRows, fn(bool $carry, array $row): bool => $carry && (int) $row['part_cache_id'] === $partCacheId, true), 'article rows linked to part cache');
assert_same(true, array_reduce($vehicleRows, fn(bool $carry, array $row): bool => $carry && (int) $row['part_cache_id'] === $partCacheId, true), 'vehicle rows linked to part cache');


$diagnostics = $database->cache_diagnostics('1T0941329A');
assert_same('1T0941329A', $diagnostics['cache_lookup_key'], 'cache diagnostics lookup key');
assert_same('wp_gps_fitment_part_cache', $diagnostics['table_name'], 'cache diagnostics part table name');
assert_same(true, $diagnostics['table_exists'], 'cache diagnostics part table exists');
assert_same(true, $diagnostics['row_exists'], 'cache diagnostics row exists');
assert_same($partCacheId, $diagnostics['row_id'], 'cache diagnostics row id');
assert_same('found', $diagnostics['row_status'], 'cache diagnostics row status');
assert_same(4, $diagnostics['row_article_count'], 'cache diagnostics article count');
assert_same(82, $diagnostics['row_vehicle_count'], 'cache diagnostics vehicle count');

$GLOBALS['gps_test_http_calls'] = 0;
$cached = $lookup->lookup('1T0-941-329-A', false, false);
assert_same('found', $cached['status'], 'repeat dry-run cache status');
assert_same(true, $cached['from_cache'], 'repeat dry-run from cache');
assert_same(true, $cached['cache_hit'], 'repeat dry-run cache hit debug flag');
assert_same(false, $cached['saved'], 'repeat dry-run not saved');
assert_same('1T0941329A', $cached['part_number_normalized'], 'repeat dry-run normalized key consistent');
assert_same('1T0941329A', $cached['cache_lookup_key'], 'repeat dry-run cache lookup key');
assert_same($partCacheId, $cached['cache_part_cache_id'], 'repeat dry-run cache part id');
assert_same(4, count($cached['articles']), 'repeat dry-run article count from cache');
assert_same(82, count($cached['unique_vehicle_ids']), 'repeat dry-run vehicle count from cache');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'repeat dry-run skips Apify');

$GLOBALS['gps_test_http_calls'] = 0;
$cachedSave = $lookup->lookup('1T0941329A', true, false);
assert_same(true, $cachedSave['from_cache'], 'save action reuses existing cache without force live');
assert_same(false, $cachedSave['saved'], 'save action does not refresh existing cache without force live');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'save action skips Apify for cached part');

$GLOBALS['gps_test_http_calls'] = 0;
$forced = $lookup->lookup('1T0941329A', false, true);
assert_same(false, $forced['from_cache'], 'force live bypasses cache');
assert_same(false, $forced['cache_hit'], 'force live cache hit debug false');
assert_same(true, $forced['force_live'], 'force live debug flag');
assert_same(5, $GLOBALS['gps_test_http_calls'], 'force live calls Apify');

$GLOBALS['gps_test_http_calls'] = 0;
$backfill = $lookup->backfill(['1T0 941 329 A']);
assert_same(1, count($backfill['processed']), 'backfill processed cached part');
assert_same(true, $backfill['processed'][0]['from_cache'], 'backfill uses cache first');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'backfill skips Apify for cached part');

$GLOBALS['gps_test_http_calls'] = 0;
$rejectedBackfill = $lookup->backfill(['BRAK', 'DXR']);
assert_same(0, count($rejectedBackfill['processed']), 'backfill processes no rejected values');
assert_same(2, $rejectedBackfill['rejected_before_lookup'], 'backfill counts rejected before lookup');
assert_same(0, $rejectedBackfill['apify_lookup_attempted'], 'backfill attempts no Apify calls for rejected values');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'backfill rejected values do not call Apify');

$scanWpdb = new FakeWpdb();
$scanWpdb->tables[$scanWpdb->posts] = [
    ['ID' => 10],
    ['ID' => 11],
    ['ID' => 12],
    ['ID' => 13],
];
$GLOBALS['wpdb'] = $scanWpdb;
$GLOBALS['gps_test_post_meta'] = [
    10 => ['_part_number' => '1T0941329A', '_sku' => 'sku-10'],
    11 => ['_part_number' => 'FOTELE', '_sku' => 'sku-11'],
    12 => ['_part_number' => 'A2043302701 A2043302601', '_sku' => 'sku-12'],
    13 => ['_part_number' => '0GC300072H WGC 0FN409053C DNF DNFF DNF 264753', '_sku' => 'sku-13'],
];
$scanDatabase = new Database($normalizer);
$scanner = new ProductScanner($scanDatabase, $normalizer, $settings, $validator);
$scan = $scanner->scan(10, 0, true);
assert_same(4, $scan['total_scanned_products'], 'scanner counts scanned products');
assert_same(4, $scan['products_with_raw_part_number'], 'scanner counts raw part number products');
assert_same(2, $scan['accepted_products'], 'scanner counts accepted products');
assert_same(2, $scan['rejected_products'], 'scanner counts rejected products');
assert_same(3, $scan['unique_accepted_part_numbers'], 'scanner counts unique accepted part numbers');
assert_same(2, $scan['rejected_count'], 'scanner counts rejected candidate rows');
assert_same(2, $scan['suspicious_count'], 'scanner counts split multi-code warnings');
assert_same(['1T0941329A', 'A2043302701', 'A2043302601'], array_keys($scan['unique_part_numbers']), 'scanner accepted unique keys only');
assert_same(3, count($scanWpdb->tables['wp_gps_fitment_product_map'] ?? []), 'scanner persists accepted product map rows only');

$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['gps_test_post_meta'] = [];

$failedWpdb = new FakeWpdb();
$failedWpdb->failInsertsFor['wp_gps_fitment_part_cache'] = true;
$GLOBALS['wpdb'] = $failedWpdb;
$failedDatabase = new Database($normalizer);
$failedLookup = new FitmentLookupService($failedDatabase, $client, $normalizer, $settings);
$GLOBALS['gps_test_http_calls'] = 0;
$failed = $failedLookup->lookup('FAIL-123-A', true, false);
assert_same(false, $failed['saved'], 'failed part insert is not marked saved');
assert_same(null, $failed['cache_part_cache_id'], 'failed part insert has no cache id');
assert_same('Simulated insert failure for wp_gps_fitment_part_cache', $failed['save_debug']['last_db_error'] ?? '', 'failed part insert exposes DB error');
assert_same(0, count($failedWpdb->tables['wp_gps_fitment_article_cache'] ?? []), 'failed part insert does not write article rows');
assert_same(0, count($failedWpdb->tables['wp_gps_fitment_vehicle_cache'] ?? []), 'failed part insert does not write vehicle rows');


$GLOBALS['wpdb'] = $wpdb;
$auditExporter = new AuditCsvExporter($database);
$auditScan = [
    'accepted_rows' => [
        ['product_id' => 30, 'sku' => 'sku, "30"', 'source_field' => '_part_number', 'source_raw' => "FOUND-RAW\nLINE", 'part_number_raw' => 'FOUND-RAW', 'part_number_normalized' => 'FOUND1', 'warnings' => []],
        ['product_id' => 20, 'sku' => 'sku-20', 'source_field' => '_part_number', 'source_raw' => 'NF-RAW', 'part_number_raw' => 'NF-RAW', 'part_number_normalized' => 'NF1', 'warnings' => ['split candidate']],
        ['product_id' => 10, 'sku' => 'sku-10', 'source_field' => '_part_number', 'source_raw' => 'ERR-RAW', 'part_number_raw' => 'ERR-RAW', 'part_number_normalized' => 'ERR1', 'warnings' => []],
        ['product_id' => 40, 'sku' => 'sku-40', 'source_field' => '_part_number', 'source_raw' => 'CACHED-RAW', 'part_number_raw' => 'CACHED-RAW', 'part_number_normalized' => 'CACHED1', 'warnings' => []],
    ],
    'rejected_rows' => [
        ['product_id' => 50, 'sku' => 'sku-50', 'source_field' => '_part_number', 'source_raw' => 'BRAK', 'part_number_raw' => 'BRAK', 'part_number_normalized' => 'BRAK', 'warnings' => [], 'rejection_reason' => 'known placeholder token'],
    ],
];
$auditBackfill = [
    'processed' => [
        [
            'part_number_raw' => 'FOUND-RAW',
            'part_number_normalized' => 'FOUND1',
            'status' => 'found',
            'articles' => [
                ['articleNo' => 'ART,"1"', 'supplierId' => 1, 'supplierName' => "Supplier\nOne"],
                ['articleNo' => 'ART2', 'supplierId' => 2, 'supplierName' => 'Supplier Two'],
            ],
            'vehicles' => [['vehicleId' => 300], ['vehicleId' => 100], ['vehicleId' => 300]],
            'unique_vehicle_ids' => ['300', '100'],
            'errors' => [],
            'from_cache' => false,
            'saved' => true,
            'cache_part_cache_id' => 701,
        ],
        [
            'part_number_raw' => 'NF-RAW',
            'part_number_normalized' => 'NF1',
            'status' => 'not_found',
            'articles' => [],
            'vehicles' => [],
            'unique_vehicle_ids' => [],
            'errors' => ['No TecDoc articles found for this OEM/part number.'],
            'from_cache' => false,
            'saved' => true,
            'cache_part_cache_id' => 702,
        ],
        [
            'part_number_raw' => 'ERR-RAW',
            'part_number_normalized' => 'ERR1',
            'status' => 'error',
            'articles' => [],
            'vehicles' => [],
            'unique_vehicle_ids' => [],
            'errors' => ['Lookup failed, remote error'],
            'from_cache' => false,
            'saved' => false,
            'cache_part_cache_id' => null,
        ],
        [
            'part_number_raw' => 'CACHED-RAW',
            'part_number_normalized' => 'CACHED1',
            'status' => 'found',
            'articles' => [['article_no' => 'CACHED-ART', 'supplier_name' => 'Cached Supplier']],
            'vehicles' => [['vehicle_id' => 900]],
            'unique_vehicle_ids' => ['900'],
            'errors' => [],
            'from_cache' => true,
            'saved' => false,
            'cache_part_cache_id' => 703,
        ],
    ],
];
$csvResult = $auditExporter->export_backfill($auditScan, $auditBackfill, 5, 10);
assert_same(true, $csvResult['csv_generated'], 'audit CSV generated from mock backfill result');
assert_same(5, $csvResult['csv_row_count'], 'audit CSV row count includes accepted and rejected rows');
assert_same(true, str_contains($csvResult['csv_path'], '/wp-content/uploads/gps-ebay-fitment-sync/audit/'), 'audit CSV path remains in plugin-owned uploads audit directory');
assert_same(true, str_contains($csvResult['csv_url'], '/wp-content/uploads/gps-ebay-fitment-sync/audit/'), 'audit CSV URL points at audit directory');

$handle = fopen($csvResult['csv_path'], 'rb');
$headers = fgetcsv($handle);
$csvRows = [];
while (($data = fgetcsv($handle)) !== false) {
    $csvRows[] = array_combine($headers, $data);
}
fclose($handle);
assert_same(AuditCsvExporter::columns(), $headers, 'audit CSV header columns');
assert_same(['found', 'not_found', 'error', 'rejected', 'skipped_cached'], array_column($csvRows, 'lookup_status'), 'audit CSV sorting/grouping order');
assert_same('300,100', $csvRows[0]['unique_vehicle_ids'], 'found row includes vehicle IDs');
assert_same('2', $csvRows[0]['ktype_count'], 'found row includes KType count');
assert_same('2', $csvRows[0]['article_count'], 'found row includes article count');
assert_same("Supplier\nOne,Supplier Two", $csvRows[0]['supplier_names'], 'CSV escaping preserves newline in supplier names');
assert_same('ART,"1",ART2', $csvRows[0]['article_numbers'], 'CSV escaping preserves comma and quotes in article numbers');
assert_same('No TecDoc articles found for this OEM/part number.', $csvRows[1]['not_found_reason'], 'not_found row includes not_found_reason');
assert_same('known placeholder token', $csvRows[3]['rejection_reason'], 'rejected row includes rejection_reason');
assert_same('no', $csvRows[3]['lookup_attempted'], 'rejected row lookup_attempted is no');
assert_same('no', $csvRows[4]['lookup_attempted'], 'cached row does not imply Apify lookup');
assert_same('hit', $csvRows[4]['cache_status'], 'cached row cache status hit');
assert_same('yes', $csvRows[4]['from_cache'], 'cached row from_cache yes');

$scanPreviewWpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $scanPreviewWpdb;
$scanPreviewDatabase = new Database($normalizer);
$scanPreviewWpdb->tables['wp_gps_fitment_part_cache'] = [[
    'id' => 801,
    'part_number_raw' => 'SCAN-CACHED',
    'part_number_normalized' => 'SCANCACHED',
    'status' => 'found',
    'article_count' => 1,
    'vehicle_count' => 1,
    'error_message' => null,
]];
$scanPreviewWpdb->tables['wp_gps_fitment_article_cache'] = [[
    'id' => 1,
    'part_cache_id' => 801,
    'article_no' => 'SCAN-ART',
    'supplier_name' => 'Scan Supplier',
]];
$scanPreviewWpdb->tables['wp_gps_fitment_vehicle_cache'] = [[
    'id' => 1,
    'part_cache_id' => 801,
    'vehicle_id' => 456,
]];
$scanPreviewExporter = new AuditCsvExporter($scanPreviewDatabase);
$scanPreviewResult = $scanPreviewExporter->export_scan_preview([
    'accepted_rows' => [[
        'product_id' => 60,
        'sku' => 'sku-60',
        'source_field' => '_part_number',
        'source_raw' => 'SCAN-CACHED',
        'part_number_raw' => 'SCAN-CACHED',
        'part_number_normalized' => 'SCANCACHED',
        'warnings' => [],
    ]],
    'rejected_rows' => [[
        'product_id' => 61,
        'sku' => 'sku-61',
        'source_field' => '_part_number',
        'source_raw' => 'NOPE',
        'part_number_raw' => 'NOPE',
        'part_number_normalized' => 'NOPE',
        'warnings' => [],
        'rejection_reason' => 'too short',
    ]],
], 0, 2);
$handle = fopen($scanPreviewResult['csv_path'], 'rb');
$headers = fgetcsv($handle);
$previewRows = [];
while (($data = fgetcsv($handle)) !== false) {
    $previewRows[] = array_combine($headers, $data);
}
fclose($handle);
assert_same(true, $scanPreviewResult['csv_generated'], 'scan preview CSV generated optionally');
assert_same('not_run', $previewRows[1]['lookup_status'], 'scan preview cached candidate lookup not run');
assert_same('hit', $previewRows[1]['cache_status'], 'scan preview cached candidate shows cache hit');
assert_same('no', $previewRows[1]['lookup_attempted'], 'scan preview cached candidate does not call Apify');
assert_same('rejected', $previewRows[0]['lookup_status'], 'scan preview rejected candidate marked rejected');


$autoWpdb = new FakeWpdb();
$autoWpdb->tables[$autoWpdb->posts] = [
    ['ID' => 100],
    ['ID' => 101],
    ['ID' => 102],
];
$autoWpdb->tables['wp_gps_fitment_part_cache'] = [[
    'id' => 901,
    'part_number_raw' => '1T0941329A',
    'part_number_normalized' => '1T0941329A',
    'status' => 'found',
    'article_count' => 1,
    'vehicle_count' => 1,
    'error_message' => null,
]];
$autoWpdb->tables['wp_gps_fitment_article_cache'] = [[
    'id' => 1,
    'part_cache_id' => 901,
    'article_no' => 'AUTO-CACHED-ART',
    'supplier_name' => 'Auto Cached Supplier',
]];
$autoWpdb->tables['wp_gps_fitment_vehicle_cache'] = [[
    'id' => 1,
    'part_cache_id' => 901,
    'vehicle_id' => 777,
]];
$GLOBALS['wpdb'] = $autoWpdb;
$GLOBALS['gps_test_post_meta'] = [
    100 => ['_part_number' => '1T0941329A', '_sku' => 'auto-cached'],
    101 => ['_part_number' => 'BRAK', '_sku' => 'auto-rejected'],
    102 => ['_part_number' => '8K0805607A', '_sku' => 'auto-live'],
];
$autoDatabase = new Database($normalizer);
$autoScanner = new ProductScanner($autoDatabase, $normalizer, $settings, $validator);
$autoLookup = new FitmentLookupService($autoDatabase, $client, $normalizer, $settings, $validator);
$autoExporter = new AuditCsvExporter($autoDatabase);
$autoRunner = new KTypeBackfillAutoRunner($autoScanner, $autoLookup, $autoDatabase, $autoExporter);

$confirmationFailed = $autoRunner->run_batch([
    'offset' => 0,
    'batch_limit' => 10,
    'dry_run' => false,
    'confirmation' => 'WRONG',
]);
assert_same(false, $confirmationFailed['success'], 'auto-runner requires exact live confirmation');
assert_same('confirmation_required', $confirmationFailed['stopped_reason'], 'auto-runner confirmation failure stop reason');

$GLOBALS['gps_test_http_calls'] = 0;
$autoBatch = $autoRunner->run_batch([
    'run_id' => 'test-auto-run',
    'offset' => 0,
    'batch_limit' => 99,
    'batch_number' => 1,
    'max_batches' => 0,
    'export_csv' => true,
    'persist_product_map' => true,
    'dry_run' => false,
    'confirmation' => KTypeBackfillAutoRunner::CONFIRMATION_TEXT,
]);
assert_same(true, $autoBatch['success'], 'auto-runner one batch succeeds');
assert_same(1, $autoBatch['batch_limit'], 'auto-runner processes one item per request');
assert_same(1, $autoBatch['next_offset'], 'auto-runner advances offset by one item');
assert_same(1, $autoBatch['counters']['total_scanned_products'], 'auto-runner scans one product per request');
assert_same(0, $autoBatch['counters']['rejected_before_lookup'], 'auto-runner does not scan later rejected rows in same request');
assert_same(1, $autoBatch['counters']['skipped_cached'], 'auto-runner skips cached row');
assert_same(0, $autoBatch['counters']['apify_lookup_attempted'], 'auto-runner does not call Apify for cached row');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'auto-runner cached row does not call Apify');
assert_same(false, $autoBatch['csv_generated'], 'auto-runner does not generate per-request CSV');
assert_same('', $autoBatch['csv_path'], 'auto-runner per-request CSV path is empty');
assert_same(1, count($autoWpdb->tables['wp_gps_fitment_product_map'] ?? []), 'auto-runner persists only the processed accepted product map row');
$autoCheckpoint = get_option(KTypeBackfillAutoRunner::CHECKPOINT_OPTION, []);
assert_same('test-auto-run', $autoCheckpoint['run_id'] ?? '', 'auto-runner checkpoint stores run id after successful batch');
assert_same('running', $autoCheckpoint['status'] ?? '', 'auto-runner checkpoint status remains running after non-terminal batch');
assert_same(1, $autoCheckpoint['next_offset'] ?? null, 'auto-runner checkpoint saves next offset after successful item');
assert_same(0, $autoCheckpoint['last_completed_offset'] ?? null, 'auto-runner checkpoint saves last completed offset after successful batch');
assert_same(1, $autoCheckpoint['total_batches_completed'] ?? null, 'auto-runner checkpoint counts completed batches');
assert_same(0, $autoCheckpoint['aggregate_counters']['apify_lookup_attempted'] ?? null, 'auto-runner checkpoint aggregates counters');

$GLOBALS['gps_test_http_calls'] = 0;
$dryRunBatch = $autoRunner->run_batch([
    'run_id' => 'test-dry-run',
    'offset' => 0,
    'batch_limit' => 10,
    'dry_run' => true,
    'export_csv' => false,
    'persist_product_map' => true,
]);
assert_same(true, $dryRunBatch['success'], 'auto-runner dry-run works without confirmation');
assert_same(0, $dryRunBatch['counters']['apify_lookup_attempted'], 'auto-runner dry-run makes no Apify attempts');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'auto-runner dry-run makes no HTTP calls');

$summaryResult = $autoRunner->final_summary([
    'run_id' => 'test-auto-run',
    'started_at' => '2026-06-12T00:00:00Z',
    'finished_at' => '2026-06-12T00:01:00Z',
    'stopped_reason' => 'completed',
    'start_offset' => 0,
    'final_offset' => 1,
    'batch_limit' => 1,
    'max_batches' => 0,
    'total_batches' => 1,
    'total_scanned_products' => 1,
    'products_with_raw_part_number' => 1,
    'accepted_products' => 1,
    'rejected_products' => 0,
    'skipped_cached' => 1,
    'apify_lookup_attempted' => 0,
    'found' => 1,
    'not_found' => 0,
    'errors' => 0,
    'csv_files_count' => 0,
    'batch_csv_urls' => [],
]);
assert_same(true, $summaryResult['summary_csv_generated'], 'auto-runner final summary CSV generated');
assert_same(true, str_contains($summaryResult['summary_csv_path'], '/wp-content/uploads/gps-ebay-fitment-sync/audit/'), 'auto-runner summary CSV path safety');
$summaryHandle = fopen($summaryResult['summary_csv_path'], 'rb');
$summaryHeaders = fgetcsv($summaryHandle);
$summaryRow = fgetcsv($summaryHandle);
fclose($summaryHandle);
assert_same('run_id', $summaryHeaders[0], 'auto-runner summary CSV starts with run_id');
assert_same('test-auto-run', $summaryRow[0], 'auto-runner summary CSV stores run id');


$GLOBALS['gps_test_options'][KTypeBackfillAutoRunner::CHECKPOINT_OPTION] = [];
$resumeWpdb = new FakeWpdb();
$resumeWpdb->tables[$resumeWpdb->posts] = [
    ['ID' => 200],
    ['ID' => 201],
];
$resumeWpdb->tables['wp_gps_fitment_part_cache'] = [[
    'id' => 1001,
    'part_number_raw' => '1T0941329A',
    'part_number_normalized' => '1T0941329A',
    'status' => 'found',
    'article_count' => 1,
    'vehicle_count' => 1,
    'error_message' => null,
]];
$resumeWpdb->tables['wp_gps_fitment_article_cache'] = [[
    'id' => 1,
    'part_cache_id' => 1001,
    'article_no' => 'RESUME-CACHED-ART',
    'supplier_name' => 'Resume Cached Supplier',
]];
$resumeWpdb->tables['wp_gps_fitment_vehicle_cache'] = [[
    'id' => 1,
    'part_cache_id' => 1001,
    'vehicle_id' => 888,
]];
$GLOBALS['wpdb'] = $resumeWpdb;
$GLOBALS['gps_test_post_meta'] = [
    200 => ['_part_number' => '8K0805607A', '_sku' => 'resume-live'],
    201 => ['_part_number' => '1T0941329A', '_sku' => 'resume-cached'],
];
$resumeDatabase = new Database($normalizer);
$resumeRunner = new KTypeBackfillAutoRunner(
    new ProductScanner($resumeDatabase, $normalizer, $settings, $validator),
    new FitmentLookupService($resumeDatabase, $client, $normalizer, $settings, $validator),
    $resumeDatabase,
    new AuditCsvExporter($resumeDatabase)
);
$GLOBALS['gps_test_http_calls'] = 0;
$resumeFirst = $resumeRunner->run_batch([
    'run_id' => 'resume-run',
    'offset' => 0,
    'batch_limit' => 1,
    'export_csv' => true,
    'dry_run' => false,
    'confirmation' => KTypeBackfillAutoRunner::CONFIRMATION_TEXT,
]);
assert_same(true, $resumeFirst['success'], 'auto-runner resume scenario first batch succeeds');
assert_same(1, $resumeFirst['next_offset'], 'auto-runner resume scenario first checkpoint next offset');
assert_same(5, $GLOBALS['gps_test_http_calls'], 'auto-runner resume scenario first uncached batch calls Apify');
$stoppedCheckpoint = $resumeRunner->stop('manual_stop');
assert_same('stopped', $stoppedCheckpoint['status'] ?? '', 'auto-runner manual stop persists stopped status');
assert_same(1, $stoppedCheckpoint['next_offset'] ?? null, 'auto-runner stopped checkpoint keeps next offset');
$GLOBALS['gps_test_http_calls'] = 0;
$resumeSecond = $resumeRunner->run_batch([
    'offset' => 0,
    'batch_limit' => 1,
    'resume' => true,
    'export_csv' => true,
    'dry_run' => false,
    'confirmation' => KTypeBackfillAutoRunner::CONFIRMATION_TEXT,
]);
assert_same(true, $resumeSecond['success'], 'auto-runner resume batch succeeds');
assert_same(1, $resumeSecond['offset'], 'auto-runner resume starts from stored next offset instead of zero');
assert_same(2, $resumeSecond['next_offset'], 'auto-runner resume advances from stored next offset');
assert_same(1, $resumeSecond['counters']['skipped_cached'], 'auto-runner resume uses cache for cached value');
assert_same(0, $resumeSecond['counters']['apify_lookup_attempted'], 'auto-runner resume does not send cached value to Apify');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'auto-runner cached values are not sent to Apify again on resume');
$resumeCheckpoint = get_option(KTypeBackfillAutoRunner::CHECKPOINT_OPTION, []);
assert_same(2, $resumeCheckpoint['total_batches_completed'] ?? null, 'auto-runner checkpoint aggregates resumed batch count');

// New resilience defaults and browser retry/backoff wiring.
$settingsDefaults = $settings->defaults();
assert_same(5, $settingsDefaults['max_apify_lookups_per_batch'] ?? null, 'max Apify lookup cap default is safe');
$sanitizedSettings = $settings->sanitize(['max_apify_lookups_per_batch' => 99]);
assert_same(10, $sanitizedSettings['max_apify_lookups_per_batch'] ?? null, 'max Apify lookup cap is clamped to 10');
$sanitizedAutoOptions = KTypeBackfillAutoRunner::sanitize_options(['batch_limit' => 999, 'max_apify_lookups_per_batch' => 99]);
assert_same(1, $sanitizedAutoOptions['batch_limit'], 'auto-runner batch limit is forced to one item');
assert_same(1, $sanitizedAutoOptions['max_apify_lookups_per_batch'], 'auto-runner lookup cap is forced to one');
$adminSource = file_get_contents(__DIR__ . '/../src/Admin/AdminPage.php');
assert_same(true, str_contains($adminSource, "request_failed_http_(503|502|504|429)"), 'browser runner treats HTTP 503/502/504/429 as transient');
assert_same(true, str_contains($adminSource, "transient error, retry ' + nextAttempt + '/' + MAX_TRANSIENT_RETRIES"), 'browser runner shows transient retry status');
assert_same(true, str_contains($adminSource, 'REQUEST_TIMEOUT_SECONDS') && str_contains($adminSource, 'data-request-timeout-seconds="180"'), 'browser runner has 180 second request timeout');
assert_same(true, str_contains($adminSource, 'stalled_no_progress') && str_contains($adminSource, 'DEFAULT_STALL_THRESHOLD_SECONDS'), 'browser runner detects no-progress stalls');
assert_same(true, str_contains($adminSource, 'next_retry_time'), 'browser runner shows next retry time');
assert_same(true, str_contains($adminSource, "postBatchWithTransientRetries"), 'browser runner retries the same batch request');
assert_same(true, str_contains($adminSource, "value=\"750\""), 'auto-runner delay default is 750 ms');


$GLOBALS['gps_test_options'][KTypeBackfillAutoRunner::CHECKPOINT_OPTION] = [];
$singleWpdb = new FakeWpdb();
$singleWpdb->tables[$singleWpdb->posts] = [
    ['ID' => 300],
    ['ID' => 301],
];
$GLOBALS['wpdb'] = $singleWpdb;
$GLOBALS['gps_test_post_meta'] = [
    300 => ['_part_number' => '8K0805607A', '_sku' => 'single-live-1'],
    301 => ['_part_number' => '1T0941329A', '_sku' => 'single-live-2'],
];
$singleDatabase = new Database($normalizer);
$singleRunner = new KTypeBackfillAutoRunner(
    new ProductScanner($singleDatabase, $normalizer, $settings, $validator),
    new FitmentLookupService($singleDatabase, $client, $normalizer, $settings, $validator),
    $singleDatabase,
    new AuditCsvExporter($singleDatabase)
);
$GLOBALS['gps_test_http_calls'] = 0;
$singleBatch = $singleRunner->run_batch([
    'run_id' => 'single-run',
    'offset' => 0,
    'batch_limit' => 10,
    'max_apify_lookups_per_batch' => 10,
    'export_csv' => true,
    'dry_run' => false,
    'confirmation' => KTypeBackfillAutoRunner::CONFIRMATION_TEXT,
]);
assert_same(true, $singleBatch['success'], 'one-item lookup request succeeds');
assert_same(1, $singleBatch['counters']['total_scanned_products'], 'one-item lookup scans only one product');
assert_same(1, $singleBatch['counters']['apify_lookup_attempted'], 'one-item lookup performs one Apify lookup');
assert_same(5, $GLOBALS['gps_test_http_calls'], 'one-item lookup performs one Apify chain');
assert_same(false, array_key_exists('scan', $singleBatch), 'auto-runner response omits scan payload');
assert_same(false, array_key_exists('backfill', $singleBatch), 'auto-runner response omits backfill payload');
assert_same(false, array_key_exists('articles', $singleBatch['processed_item']), 'auto-runner processed item omits articles array');
assert_same(false, array_key_exists('vehicles', $singleBatch['processed_item']), 'auto-runner processed item omits vehicles array');
assert_same(false, $singleBatch['csv_generated'], 'auto-runner live loop does not generate per-request CSV');
assert_same(1, get_option(KTypeBackfillAutoRunner::CHECKPOINT_OPTION, [])['next_offset'] ?? null, 'one-item checkpoint advances by one');

assert_same(true, isset($singleBatch['processed_item']['product_id']), 'auto-runner small response includes product id');
assert_same(true, isset($singleBatch['processed_item']['part_number_normalized']), 'auto-runner small response includes normalized part number');
$singleSummary = $singleRunner->final_summary(['run_id' => 'single-run', 'finished_at' => gmdate('c'), 'stopped_reason' => 'completed']);
assert_same(true, $singleSummary['final_csv_generated'], 'auto-runner final CSV generated from persisted state');
assert_same(true, $singleSummary['found_only_csv_generated'], 'auto-runner found-only CSV generated from persisted state');
$singleFinalHandle = fopen($singleSummary['final_csv_path'], 'rb');
$singleFinalHeaders = fgetcsv($singleFinalHandle);
fclose($singleFinalHandle);
assert_same(true, in_array('product_id', $singleFinalHeaders, true) && in_array('part_number_normalized', $singleFinalHeaders, true), 'auto-runner final CSV includes required product columns');

$GLOBALS['gps_test_options'][KTypeBackfillAutoRunner::CHECKPOINT_OPTION] = [];
$rejectWpdb = new FakeWpdb();
$rejectWpdb->tables[$rejectWpdb->posts] = [['ID' => 400]];
$GLOBALS['wpdb'] = $rejectWpdb;
$GLOBALS['gps_test_post_meta'] = [400 => ['_part_number' => 'BRAK', '_sku' => 'reject-only']];
$rejectDatabase = new Database($normalizer);
$rejectRunner = new KTypeBackfillAutoRunner(
    new ProductScanner($rejectDatabase, $normalizer, $settings, $validator),
    new FitmentLookupService($rejectDatabase, $client, $normalizer, $settings, $validator),
    $rejectDatabase,
    new AuditCsvExporter($rejectDatabase)
);
$GLOBALS['gps_test_http_calls'] = 0;
$rejectBatch = $rejectRunner->run_batch([
    'run_id' => 'reject-run',
    'offset' => 0,
    'dry_run' => false,
    'confirmation' => KTypeBackfillAutoRunner::CONFIRMATION_TEXT,
]);
assert_same('rejected', $rejectBatch['processed_item']['status'], 'auto-runner rejected candidate marked rejected');
assert_same(1, $rejectBatch['counters']['rejected_before_lookup'], 'auto-runner rejected candidate counter increments');
assert_same(0, $rejectBatch['counters']['apify_lookup_attempted'], 'auto-runner rejected candidate does not call Apify');
assert_same(0, $GLOBALS['gps_test_http_calls'], 'auto-runner rejected candidate makes no HTTP calls');
assert_same(0, count($rejectWpdb->tables['wp_gps_fitment_product_map'] ?? []), 'auto-runner rejected candidate does not create product map cache row');

$runnerSource = file_get_contents(__DIR__ . '/../src/Service/KTypeBackfillAutoRunner.php');
assert_same(false, str_contains($runnerSource, 'update_post_meta'), 'auto-runner adds no Woo product meta writes');
assert_same(false, str_contains($runnerSource, 'ReviseFixedPriceItem') || str_contains($runnerSource, 'AddFixedPriceItem'), 'auto-runner adds no eBay write API calls');
