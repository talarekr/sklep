<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        if ($option === GPS_Ebay_Fitment_Sync\Support\Settings::OPTION) {
            return [
                'apify_token' => 'test-token',
                'actor_id' => 'Zt16dqMI2yN7Igggl',
                'lang_id' => 4,
                'country_filter_id' => 63,
                'timeout' => 60,
                'batch_size' => 5,
            ];
        }
        return $default;
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

final class FakeWpdb
{
    public string $prefix = 'wp_';
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $tables = [];
    public int $insert_id = 0;
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

    public function get_var(string $query)
    {
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
require_once __DIR__ . '/../src/Support/Settings.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Service/ApifyClient.php';
require_once __DIR__ . '/../src/Service/FitmentLookupService.php';

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Service\ApifyClient;
use GPS_Ebay_Fitment_Sync\Service\FitmentLookupService;
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
