<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\RrrApiClient;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';

$GLOBALS['gpswiss_rrr_donor_test_requests'] = [];
$GLOBALS['gpswiss_rrr_donor_test_writes'] = [];

class GPSwissRrrDonorTestWpError
{
    public function get_error_code(): string { return 'test_error'; }
    public function get_error_message(): string { return 'test error'; }
}

function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_title(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($value))); }
function esc_url_raw(string $value): string { return trim($value); }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }
function get_transient(string $key) { return false; }
function set_transient(string $key, $value, int $expiration = 0): bool { return true; }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
function is_wp_error($value): bool { return $value instanceof GPSwissRrrDonorTestWpError; }
function wp_remote_post(string $url, array $args): array
{
    $GLOBALS['gpswiss_rrr_donor_test_requests'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];
    if (str_contains($url, '/get/car_brands')) {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => [['id' => '88', 'name' => 'Test Brand']]])];
    }
    if (str_contains($url, '/get/car_models/88')) {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'data' => [['id' => '77', 'name' => 'Test Model', 'brand_id' => '88']]])];
    }
    $body = str_contains($url, '/get/car/321')
        ? [
            'status_code' => 'R200',
            'data' => [
                'id' => '321',
                'car_model' => '77',
                'car_model_category' => '88',
                'car_engine_code' => 'CZDA',
                'car_fuel' => '1',
                'car_mileage' => '123456',
                'car_body_number' => 'SECRET-VIN-LIKE',
            ],
        ]
        : [
            'status_code' => 'R200',
            'pagination' => ['page' => 1, 'limit' => 5, 'total_count' => 12],
            'data' => [
                [
                    'id' => '321',
                    'external_id' => 'DONOR-321',
                    'car_model' => '77',
                    'car_model_category' => '88',
                    'car_engine_code' => 'CZDA',
                    'car_fuel' => '1',
                    'car_mileage' => '123456',
                    'car_body_number' => 'SECRET-VIN-LIKE',
                ],
            ],
        ];

    return ['response' => ['code' => 200], 'body' => json_encode($body)];
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_rrr_donor_test_writes'][] = ['update_post_meta', $id, $key, $value]; return true; }
function update_option(string $key, mixed $value, ?bool $autoload = null): bool { $GLOBALS['gpswiss_rrr_donor_test_writes'][] = ['update_option', $key, $value, $autoload]; return true; }

function gpswiss_donor_assert(bool $condition, string $message): void
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

$result = $client->probe_donor_cars_api(5, 1, true);

gpswiss_donor_assert($result['ok'] === true, 'Donor cars probe should be successful for fixture.');
gpswiss_donor_assert($result['request']['path'] === '/v2/get/cars?limit=5&page=1', 'Probe must use bounded donor cars endpoint.');
gpswiss_donor_assert($result['http_status'] === 200, 'HTTP status should be reported.');
gpswiss_donor_assert($result['api_status_code'] === 'R200', 'API status should be reported.');
gpswiss_donor_assert($result['pagination']['page'] === 1 && $result['pagination']['limit'] === 5 && $result['pagination']['total_count'] === 12, 'Pagination summary mismatch.');
gpswiss_donor_assert($result['cars_returned'] === 1, 'Returned car count mismatch.');
gpswiss_donor_assert($result['first_car_ids'] === ['321'], 'First car IDs should be reported.');
gpswiss_donor_assert(in_array('car_model', $result['first_record_keys'], true), 'First record keys should be reported.');
gpswiss_donor_assert($result['sample_records'][0]['safe_sample']['car_body_number'] === '[redacted]', 'Sensitive body/VIN-like field must be redacted.');
gpswiss_donor_assert($result['first_car_hydration']['executed'] === true, 'Single-car hydration should run for first car.');
gpswiss_donor_assert($result['first_car_hydration']['path'] === '/get/car/321', 'Single-car hydration endpoint mismatch.');
gpswiss_donor_assert($result['csv_export_feasible'] === true, 'Successful donor records should mark CSV feasible.');
gpswiss_donor_assert($result['no_woo_write'] === true && $result['no_ovoko_write'] === true && $result['no_marketplace_calls'] === true, 'No-write flags missing.');
gpswiss_donor_assert($result['dictionary_resolution_diagnostics']['dictionary_probe_called'] === true, 'Live donor probe must call dictionary diagnostics.');
gpswiss_donor_assert(in_array('/get/car_brands', $result['dictionary_resolution_diagnostics']['endpoints_called'], true), 'Dictionary diagnostics must show endpoints called.');
gpswiss_donor_assert(count($GLOBALS['gpswiss_rrr_donor_test_requests']) >= 2, 'Expected donor list, dictionary requests, and one hydration request.');
gpswiss_donor_assert(str_contains($GLOBALS['gpswiss_rrr_donor_test_requests'][0]['url'], '/v2/get/cars?limit=5&page=1'), 'First request must be donor list.');
gpswiss_donor_assert(array_filter($GLOBALS['gpswiss_rrr_donor_test_requests'], static fn($r) => str_contains($r['url'], '/get/car/321')) !== [], 'Probe must hydrate first car.');
gpswiss_donor_assert($GLOBALS['gpswiss_rrr_donor_test_writes'] === [], 'Probe must not perform writes.');

echo "PASS donor cars probe is bounded, sanitized, hydrated once, and read-only\n";
