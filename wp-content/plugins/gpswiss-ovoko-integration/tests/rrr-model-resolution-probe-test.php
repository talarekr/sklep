<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\RrrApiClient;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';

$GLOBALS['gpswiss_model_probe_requests'] = [];
$GLOBALS['gpswiss_model_probe_writes'] = [];
$GLOBALS['gpswiss_model_probe_transients'] = [];

class GPSwissModelProbeWpError
{
    public function get_error_code(): string { return 'test_error'; }
    public function get_error_message(): string { return 'test error'; }
}

function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_title(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($value))); }
function esc_url_raw(string $value): string { return trim($value); }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }
function get_transient(string $key) { return $GLOBALS['gpswiss_model_probe_transients'][$key] ?? false; }
function set_transient(string $key, $value, int $expiration = 0): bool { $GLOBALS['gpswiss_model_probe_transients'][$key] = $value; return true; }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
function is_wp_error($value): bool { return $value instanceof GPSwissModelProbeWpError; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_model_probe_writes'][] = ['update_post_meta', $id, $key, $value]; return true; }
function update_option(string $key, mixed $value, ?bool $autoload = null): bool { $GLOBALS['gpswiss_model_probe_writes'][] = ['update_option', $key, $value, $autoload]; return true; }

function wp_remote_post(string $url, array $args): array
{
    $GLOBALS['gpswiss_model_probe_requests'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];
    $path = (string) parse_url($url, PHP_URL_PATH);
    $carRows = [
        '/get/car/493' => ['id' => '493', 'car_model' => '22', 'car_model_category' => '7', 'car_fuel' => '1', 'car_gearbox_type' => '2', 'car_wheel_drive' => '3', 'car_body_type' => '4', 'car_color' => '5'],
        '/get/car/494' => ['id' => '494', 'car_model' => '545', 'car_model_category' => '8', 'car_fuel' => '1', 'car_gearbox_type' => '2', 'car_wheel_drive' => '3', 'car_body_type' => '4', 'car_color' => '6'],
        '/get/car/495' => ['id' => '495', 'car_model' => '9999', 'car_model_category' => '9', 'car_fuel' => '1', 'car_gearbox_type' => '2', 'car_wheel_drive' => '3', 'car_body_type' => '4', 'car_color' => '7'],
    ];
    if (isset($carRows[$path])) {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => $carRows[$path]])];
    }
    if ($path === '/v2/get/cars') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => [['id' => '1', 'car_model' => '1']]])];
    }
    if ($path === '/get/model/22') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => ['id' => '22', 'name' => 'Direct Model 22']])];
    }
    if ($path === '/get/car_model/545') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => ['id' => '545', 'name' => 'Direct Model 545']])];
    }
    if ($path === '/get/car_brands') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => array_map(static fn(int $id): array => ['id' => (string) $id, 'name' => 'Brand ' . $id], range(10, 120))])];
    }
    if ($path === '/get/car_models/10') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => [['id' => '22', 'name' => 'Cached Model 22', 'brand_id' => '10']]])];
    }
    if ($path === '/get/car_models/11') {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => []])];
    }
    return ['response' => ['code' => 404], 'body' => json_encode(['status_code' => 'R404', 'msg' => 'not found'])];
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }

function gpswiss_model_probe_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$client = new RrrApiClient([
    'rrr_api_base_url' => 'https://api.rrr.test',
    'rrr_api_username' => 'user',
    'rrr_api_password' => 'pass',
    'rrr_api_user_token' => 'token',
]);

$result = $client->probe_ovoko_model_resolution(['493', '494', '495'], ['22', '545']);
$urls = array_map(static fn(array $row): string => $row['url'], $GLOBALS['gpswiss_model_probe_requests']);

foreach (['493', '494', '495'] as $carId) {
    gpswiss_model_probe_assert(array_filter($urls, static fn(string $url): bool => str_contains($url, '/get/car/' . $carId)) !== [], 'Requested car ' . $carId . ' must be hydrated directly.');
    gpswiss_model_probe_assert($result['samples'][$carId]['raw_car_record'] !== [], 'Hydrated raw car record ' . $carId . ' must be non-empty.');
}
gpswiss_model_probe_assert(array_filter($urls, static fn(string $url): bool => str_contains($url, '/v2/get/cars')) === [], 'Model resolution probe must not diagnose requested IDs from /v2/get/cars page 1.');
gpswiss_model_probe_assert($result['candidate_endpoints_tried'] !== [], 'Candidate endpoints tried must be populated.');
gpswiss_model_probe_assert(isset($result['model_cache']['requested_model_ids']['22']), 'Requested model 22 must have endpoint diagnostics.');
gpswiss_model_probe_assert(isset($result['model_cache']['requested_model_ids']['545']), 'Requested model 545 must have endpoint diagnostics.');
gpswiss_model_probe_assert($result['model_cache']['requested_model_ids']['22']['direct_lookup_results'][0]['http_code'] === 200, 'Model 22 direct endpoint HTTP status must be reported.');
gpswiss_model_probe_assert($result['model_cache']['requested_model_ids']['545']['direct_lookup_results'][1]['http_code'] === 200, 'Model 545 direct endpoint HTTP status must be reported.');
gpswiss_model_probe_assert($result['model_cache']['model_cache_incomplete'] === true, 'Incomplete staged all-brand model cache must be reported.');
gpswiss_model_probe_assert($result['samples']['495']['unresolved_reason'] === 'unresolved because model cache incomplete; continue staged cache ticks or build the model dictionary cache before judging CSV coverage', 'Incomplete cache should be named as unresolved reason.');
gpswiss_model_probe_assert($result['csv_safe_for_laravel_import'] === false, 'CSV must not be marked safe while model cache is incomplete.');
gpswiss_model_probe_assert($GLOBALS['gpswiss_model_probe_writes'] === [], 'Probe must not perform Woo/local/marketplace/Laravel writes.');

echo "PASS model resolution probe hydrates requested cars, reports endpoint/model-cache diagnostics, and stays read-only\n";
