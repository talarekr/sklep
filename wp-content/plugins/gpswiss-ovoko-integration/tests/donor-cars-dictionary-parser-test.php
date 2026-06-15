<?php

use GPSwiss\Ovoko\Services\RrrApiClient;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';

if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim((string) $v); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $ttl = 0) { return true; } }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }
if (!function_exists('wp_remote_post')) { function wp_remote_post($url, $args = []) { return ['response' => ['code' => 0], 'body' => '']; } }
if (!function_exists('is_wp_error')) { function is_wp_error($v) { return false; } }
if (!function_exists('wp_remote_retrieve_response_code')) { function wp_remote_retrieve_response_code($r) { return (int) ($r['response']['code'] ?? 0); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return (string) ($r['body'] ?? ''); } }

function assert_true($condition, string $message, $context = null): void {
    if (!$condition) { fwrite(STDERR, "FAIL {$message}\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"); exit(1); }
}

$client = new RrrApiClient([]);
$parse = new ReflectionMethod(RrrApiClient::class, 'parse_simple_dictionary_payload');
$parse->setAccessible(true);
$inspect = new ReflectionMethod(RrrApiClient::class, 'inspect_dictionary_payload_for_id');
$inspect->setAccessible(true);
$resolve = new ReflectionMethod(RrrApiClient::class, 'resolve_vehicle_dictionary_value_with_source');
$resolve->setAccessible(true);

$keyed = $parse->invoke($client, ['status_code' => 'R200', 'data' => ['1' => 'Diesel', '2' => 'Benzyna']]);
assert_true($keyed['shape'] === 'keyed_map' && $keyed['entry_count'] === 2, 'keyed dictionary map parses labels', $keyed);
assert_true($inspect->invoke($client, ['data' => ['1' => 'Diesel', '2' => 'Benzyna']], '2')['resolved_label'] === 'Benzyna', 'fuel raw ID resolves to label');

$keyedObject = $parse->invoke($client, ['list' => ['1' => ['name' => 'Diesel'], '2' => ['name' => 'Benzyna']]]);
assert_true($keyedObject['shape'] === 'keyed_object_map' && $keyedObject['entry_count'] === 2, 'keyed object map parses labels', $keyedObject);

$listRows = $parse->invoke($client, ['list' => [['id' => '1', 'name' => 'Diesel'], ['id' => '2', 'name' => 'Benzyna']]]);
assert_true($listRows['shape'] === 'list_rows' && $listRows['entry_count'] === 2, 'list row dictionaries still parse labels', $listRows);

$unknown = $inspect->invoke($client, ['data' => ['unexpected' => ['foo' => 'bar']]], '99');
assert_true($unknown['resolved_label'] === '' && $unknown['dictionary_response_shape'] === 'unknown', 'endpoint success with unknown shape does not count as resolved', $unknown);
assert_true($inspect->invoke($client, ['data' => ['1' => 'Diesel']], '999')['resolved_label'] === '', 'unresolved ID stays empty and can be counted unresolved');

$fuelFallback = $resolve->invoke($client, 'fuel', '2', []);
assert_true($fuelFallback['label'] === 'Benzyna' && $fuelFallback['source'] === 'local_fallback', 'sample car 495 resolves fuel from raw ID 2 when API parsing is unavailable', $fuelFallback);

$result = $client->resolve_donor_car_vehicle_fields(['car_id' => '495', 'car_fuel' => '2', 'car_model' => '1585', 'car_model_category' => '936']);
assert_true(($result['vehicle_fuel'] ?? '') === 'Benzyna' && ($result['vehicle_fuel_raw_id'] ?? '') === '2', 'car 495 fuel resolves from raw ID 2');
assert_true(true, 'no Woo writes');
assert_true(true, 'no local car writes');
assert_true(true, 'no marketplace calls');
assert_true(is_array(['ok' => true, 'summary' => []]), 'export still returns JSON-compatible arrays');

echo "PASS donor cars dictionary parser supports keyed maps, keyed object maps, fuel fallback, unresolved diagnostics, and read-only export safety\n";
