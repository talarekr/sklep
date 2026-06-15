<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoDonorCarsCsvExportService;
use GPSwiss\Ovoko\Services\RrrApiClient;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';
require_once dirname(__DIR__) . '/src/Services/OvokoDonorCarsCsvExportService.php';

$GLOBALS['gpswiss_donor_dict_requests'] = [];
$GLOBALS['gpswiss_donor_dict_transients'] = [];
$GLOBALS['gpswiss_donor_dict_upload_dir'] = sys_get_temp_dir() . '/gpswiss-donor-dict-export-' . md5(__FILE__);

function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_title(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', trim($value))); }
function esc_url_raw(string $value): string { return trim($value); }
function wp_json_encode($value, int $flags = 0): string { return json_encode($value, $flags); }
function wp_upload_dir($time = null, bool $create_dir = true, bool $refresh_cache = false): array { return ['basedir' => $GLOBALS['gpswiss_donor_dict_upload_dir']]; }
function trailingslashit(string $value): string { return rtrim($value, '/') . '/'; }
function wp_mkdir_p(string $target): bool { return is_dir($target) || mkdir($target, 0777, true); }
function admin_url(string $path = ''): string { return 'https://wp.test/wp-admin/' . ltrim($path, '/'); }
function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
function wp_create_nonce(string $action): string { return 'nonce-' . $action; }
function is_wp_error($value): bool { return false; }
function get_transient(string $key) { return $GLOBALS['gpswiss_donor_dict_transients'][$key] ?? false; }
function set_transient(string $key, $value, int $expiration = 0): bool { $GLOBALS['gpswiss_donor_dict_transients'][$key] = $value; return true; }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

function wp_remote_post(string $url, array $args): array
{
    $GLOBALS['gpswiss_donor_dict_requests'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    if (str_contains($url, '/v2/get/cars')) {
        return ['response' => ['code' => 200], 'body' => json_encode(['status_code' => 'R200', 'pagination' => ['page' => 1, 'limit' => 100, 'total_count' => 3], 'data' => [[
            'id' => '493', 'external_id' => 'DONOR-493', 'car_model_category' => '10', 'car_model' => '4930', 'car_fuel' => '2', 'car_gearbox_type' => '1', 'car_wheel_drive' => '3', 'car_wheel_type' => '0', 'car_body_type' => '5', 'car_color' => '9', 'year' => '2015'
        ], [
            'id' => '494', 'external_id' => 'DONOR-494', 'car_model_category' => '10', 'car_model' => '4940', 'car_fuel' => '2', 'car_gearbox_type' => '1', 'car_wheel_drive' => '3', 'car_wheel_type' => '2', 'car_body_type' => '5', 'car_color' => '9', 'year' => '2016'
        ], [
            'id' => '495', 'external_id' => 'DONOR-495', 'car_model_category' => '10', 'car_model' => '4950', 'car_fuel' => '2', 'car_gearbox_type' => '1', 'car_wheel_drive' => '3', 'car_wheel_type' => '1', 'car_body_type' => '5', 'car_color' => '9', 'year' => '2017'
        ]]])];
    }
    $payload = ['status_code' => 'R200', 'data' => []];
    if (str_ends_with($path, '/get/car_brands')) { $payload['data'] = [['id' => '10', 'name' => 'Maserati']]; }
    elseif (str_ends_with($path, '/get/car_models/10')) { $payload['data'] = [['id' => '4950', 'name' => 'Levante', 'brand_id' => '10']]; }
    elseif (str_ends_with($path, '/get/fuel')) { $payload['data'] = [['id' => '2', 'name' => 'Benzyna']]; }
    elseif (str_ends_with($path, '/get/gearbox_type')) { $payload['data'] = [['id' => '1', 'name' => 'Automatyczny']]; }
    elseif (str_ends_with($path, '/get/wheel_drive')) { $payload['data'] = [['id' => '3', 'name' => 'AWD']]; }
    elseif (str_ends_with($path, '/get/wheel_type')) { $payload['data'] = [['id' => '2', 'name' => 'API prawa strona'], ['id' => '1', 'name' => 'Lewa strona']]; }
    elseif (str_ends_with($path, '/get/car_body_type')) { $payload['data'] = [['id' => '5', 'name' => 'SUV']]; }
    elseif (str_ends_with($path, '/get/color')) { $payload['data'] = [['id' => '9', 'name' => 'Szary']]; }
    return ['response' => ['code' => 200], 'body' => json_encode($payload)];
}
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }

function gpswiss_dict_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }

$client = new RrrApiClient(['rrr_api_base_url' => 'https://api.rrr.test', 'rrr_api_username' => 'u', 'rrr_api_password' => 'p', 'rrr_api_user_token' => 't']);
$GLOBALS['gpswiss_donor_dict_transients']['gpswiss_ovoko_staged_model_cache_v1'] = [
    'all_brand_model_cache_complete' => true,
    'model_id_to_record' => ['4930' => ['id' => '4930', 'name' => 'Ghibli', 'brand_id' => '10'], '4940' => ['id' => '4940', 'name' => 'Quattroporte', 'brand_id' => '10'], '4950' => ['id' => '4950', 'name' => 'Levante', 'brand_id' => '10']],
    'model_id_to_brand_id' => ['4930' => '10', '4940' => '10', '4950' => '10'],
    'brand_id_to_name' => ['10' => 'Maserati'],
];
$exporter = new OvokoDonorCarsCsvExportService($client);
$exporter->start();
$result = $exporter->process_page(1);
$csv = file($exporter->paths()['csv'], FILE_IGNORE_NEW_LINES);
$headers = str_getcsv($csv[0]);
$rows = [];
foreach (array_slice($csv, 1) as $line) {
    $parsed = array_combine($headers, str_getcsv($line));
    $rows[$parsed['ovoko_car_id']] = $parsed;
}
$row = $rows['495'];
$summary = json_decode((string) file_get_contents($exporter->paths()['summary']), true);

gpswiss_dict_assert($result['ok'] === true, 'Export page should complete.');
gpswiss_dict_assert($row['car_model_raw_id'] === '4950' && $row['car_fuel_raw_id'] === '2' && $row['car_wheel_type_raw_id'] === '1', 'Raw dictionary IDs must be preserved.');
gpswiss_dict_assert($row['make'] === 'Maserati' && $row['model'] === 'Levante', 'Readable make/model must be resolved.');
gpswiss_dict_assert($row['fuel'] === 'Benzyna' && $row['gearbox'] === 'Automatyczny' && $row['drive'] === 'AWD', 'Readable drivetrain labels must be resolved.');
gpswiss_dict_assert($row['steering_side'] === 'Lewa strona' && $row['body_type'] === 'SUV' && $row['color'] === 'Szary', 'Readable steering/body/color labels must be resolved.');
gpswiss_dict_assert($rows['493']['car_wheel_type_raw_id'] === '0' && $rows['493']['steering_side'] === 'Lewa strona' && $rows['493']['vehicle_steering_position'] === 'Lewa strona', 'car_wheel_type=0 must use local left-side fallback and fill both steering columns.');
gpswiss_dict_assert($rows['494']['steering_side'] === 'API prawa strona' && $rows['494']['vehicle_steering_position'] === 'API prawa strona', 'API wheel_type label must win over local fallback.');
gpswiss_dict_assert($row['vehicle_fuel'] === 'Benzyna' && $row['vehicle_gearbox_type'] === 'Automatyczny', 'Normalized aliases must be filled.');
gpswiss_dict_assert($summary['resolved_model_count'] === 3 && $summary['unresolved_model_count'] === 0, 'Model summary counts mismatch.');
gpswiss_dict_assert($summary['resolved_fuel_count'] === 3 && $summary['resolved_drive_count'] === 3 && $summary['resolved_body_type_count'] === 3 && $summary['resolved_color_count'] === 3, 'Resolved summary counts mismatch.');
gpswiss_dict_assert(in_array('dictionary_api', $summary['dictionary_sources_used'], true), 'Dictionary source should include dictionary_api.');
gpswiss_dict_assert($summary['readable_fuel_count'] === 3 && $summary['readable_gearbox_count'] === 3 && $summary['readable_steering_side_count'] === 3 && $summary['resolved_steering_side_count'] === 3 && $summary['unresolved_steering_side_count'] === 0, 'Readable summary counts mismatch.');
gpswiss_dict_assert($summary['csv_safe_for_laravel_import'] === true, 'Completed model cache and populated simple dictionaries should be Laravel safe.');
gpswiss_dict_assert(!array_filter($GLOBALS['gpswiss_donor_dict_requests'], static fn($r) => str_contains($r['url'], '/get/car_models/')), 'Export must not call car model endpoints.');
gpswiss_dict_assert(!array_filter($GLOBALS['gpswiss_donor_dict_requests'], static fn($r) => str_contains($r['url'], 'ebay') || str_contains($r['url'], 'allegro')), 'Export must not call marketplace APIs.');

echo "PASS donor cars export preserves raw IDs, resolves readable dictionary labels, fills aliases, and remains read-only\n";
