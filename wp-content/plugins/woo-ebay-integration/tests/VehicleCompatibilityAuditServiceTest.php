<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$GLOBALS['wei_test_meta'] = [
    101 => ['_sku' => ['SKU-KTYPE'], 'ktype_id' => ['12345'], 'Producent' => ['Audi']],
    102 => ['_sku' => ['SKU-EPID'], '_epid' => ['EPID-9']],
    103 => ['_sku' => ['SKU-BASIC'], 'make' => ['BMW'], 'model' => ['3 Series'], 'year' => ['2018']],
    104 => ['_sku' => ['SKU-OVOKO'], 'ovoko_car_id' => ['CAR-77']],
];
$GLOBALS['wei_test_titles'] = [101 => 'KType product', 102 => 'ePID product', 103 => 'Basic vehicle product', 104 => 'Ovoko only product'];
$GLOBALS['wei_test_product_ids'] = [104, 103, 102, 101];
$GLOBALS['wei_test_api_calls'] = 0;

if (!function_exists('get_post_meta')) {
    function get_post_meta($productId, $key = '', $single = false) {
        $meta = $GLOBALS['wei_test_meta'][(int) $productId] ?? [];
        if ($key === '') {
            return $meta;
        }
        $value = $meta[$key] ?? [];
        if ($single) {
            return is_array($value) ? ($value[0] ?? '') : $value;
        }
        return $value;
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($productId) { return $GLOBALS['wei_test_titles'][(int) $productId] ?? ''; }
}
if (!function_exists('wc_get_product')) {
    function wc_get_product($productId) {
        return new class((int) $productId) {
            public function __construct(private int $id) {}
            public function get_sku(): string { return (string) get_post_meta($this->id, '_sku', true); }
            public function get_name(): string { return (string) get_the_title($this->id); }
            public function get_attributes(): array { return []; }
        };
    }
}
if (!function_exists('wc_get_products')) {
    function wc_get_products($args) { return array_slice($GLOBALS['wei_test_product_ids'], 0, (int) ($args['limit'] ?? 10)); }
}
if (!function_exists('get_the_terms')) {
    function get_the_terms($productId, $taxonomy) {
        return [(object) ['term_id' => 55, 'name' => 'Części samochodowe']];
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir() { return ['basedir' => sys_get_temp_dir(), 'baseurl' => 'http://example.test/uploads']; }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0775, true); }
}
if (!function_exists('remove_accents')) {
    function remove_accents($text) { return strtr($text, ['ę' => 'e', 'ś' => 's', 'ń' => 'n', 'ó' => 'o', 'ł' => 'l', 'ż' => 'z', 'ź' => 'z', 'ą' => 'a', 'ć' => 'c', 'Ę' => 'E', 'Ś' => 'S', 'Ń' => 'N', 'Ó' => 'O', 'Ł' => 'L', 'Ż' => 'Z', 'Ź' => 'Z', 'Ą' => 'A', 'Ć' => 'C']); }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get() { $GLOBALS['wei_test_api_calls']++; return []; }
}

require_once $root . '/src/Services/VehicleCompatibilityAuditService.php';

use WEI\Services\VehicleCompatibilityAuditService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$service = new VehicleCompatibilityAuditService();
$assert($service->auditProduct(101)['compatibility_status'] === 'ready_ktype', 'Product with KType becomes ready_ktype.');
$assert($service->auditProduct(102)['compatibility_status'] === 'ready_epid', 'Product with ePID becomes ready_epid.');
$basicStatus = $service->auditProduct(103)['compatibility_status'];
$assert(in_array($basicStatus, ['missing_ktype', 'insufficient_vehicle_data'], true), 'Product with make/model/year but no KType is not ready.');
$assert($service->auditProduct(104)['compatibility_status'] !== 'ready_ktype' && $service->auditProduct(104)['compatibility_status'] !== 'ready_epid', 'Product with Ovoko car ID only does not become ready.');

$headers = $service->csvHeaders();
foreach (['product_id', 'sku', 'product_title', 'woo_category_id', 'woo_category_name', 'ebay_category_id', 'category_status', 'ovoko_car_id', 'ktype_values', 'epid_values', 'make', 'model', 'year', 'trim', 'engine_code', 'engine_capacity', 'fuel', 'power', 'compatibility_status', 'missing_fields', 'notes'] as $column) {
    $assert(in_array($column, $headers, true), 'Audit CSV contains expected column: ' . $column);
}

$csv = $service->generateAuditCsv('EBAY_DE', 4);
$assert(($csv['result'] ?? '') === 'success', 'CSV audit generation succeeds.');
$assert(is_file((string) ($csv['csv_path'] ?? '')), 'CSV audit file exists.');
$assert(($csv['called_ebay_api'] ?? true) === false, 'CSV audit declares no eBay API calls.');
$assert(($csv['updated_ebay_listing'] ?? true) === false, 'CSV audit declares no listing updates.');
$assert($GLOBALS['wei_test_api_calls'] === 0, 'No live eBay API calls are made during audit/preview.');

$payload = $service->buildProductCompatibilityPayload(101, 'EBAY_DE');
$assert(($payload['compatibleProducts'][0]['kTypeValue'] ?? '') === '12345', 'Future payload preview includes compatibleProducts.kTypeValue for KType.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Vehicle compatibility audit service tests passed\n";
