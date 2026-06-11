<?php

declare(strict_types=1);

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

require_once __DIR__ . '/../src/Support/PartNumberNormalizer.php';
require_once __DIR__ . '/../src/Support/Settings.php';
require_once __DIR__ . '/../src/Service/ApifyClient.php';

use GPS_Ebay_Fitment_Sync\Service\ApifyClient;
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

$client = new ApifyClient(new Settings());
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
