<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Services/EbayPriceResolver.php';

use WEI_FR\Services\EbayPriceResolver;
use WEI_FR\Services\Logger;

$GLOBALS['wei_fr_test_options'] = [];
$GLOBALS['wei_fr_test_transients'] = [];
$GLOBALS['wei_fr_test_remote_gets'] = [];
$GLOBALS['wei_fr_test_product_price_written'] = false;

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('get_transient')) {
    function get_transient($key) { return $GLOBALS['wei_fr_test_transients'][$key] ?? false; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $expiration = 0) { $GLOBALS['wei_fr_test_transients'][$key] = $value; return true; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $GLOBALS['wei_fr_test_options'][$key] ?? $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null) { $GLOBALS['wei_fr_test_options'][$key] = $value; return true; }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) {
        $GLOBALS['wei_fr_test_remote_gets'][] = [$url, $args];
        return [
            'response' => ['code' => 200],
            'body' => json_encode(['rates' => [['mid' => 4.0, 'effectiveDate' => '2026-06-02', 'no' => '105/A/NBP/2026']]]),
        ];
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return false; }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy, $args = []) { return []; }
}
if (!function_exists('get_ancestors')) {
    function get_ancestors($term_id, $taxonomy) { return []; }
}
if (!function_exists('get_term')) {
    function get_term($term_id, $taxonomy) { return null; }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($value): string { return strtolower((string) $value); }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$product = new class {
    private string $price = '100';
    public function get_price(): string { return $this->price; }
    public function set_price($price): void { $GLOBALS['wei_fr_test_product_price_written'] = true; $this->price = (string) $price; }
    public function get_category_ids(): array { return []; }
};

$resolver = new EbayPriceResolver(new Logger());
$result = $resolver->resolve($product, 2080, ['ebay_default_markup_percent' => 25, 'nbp_rate_cache_ttl_hours' => 12], true);
$assert($result['ready'] === true, 'FR readiness price resolution should fetch the NBP EUR rate even in side-effect-suppressed audit mode.');
$assert(count($GLOBALS['wei_fr_test_remote_gets']) === 1, 'FR exchange-rate fetching must use the public NBP API exactly once when no FR cache exists.');
$assert(str_contains((string) $GLOBALS['wei_fr_test_remote_gets'][0][0], 'api.nbp.pl/api/exchangerates/rates/a/eur'), 'FR exchange-rate fetching must use NBP Table A EUR API, not eBay.');
$assert($result['nbp_eur_rate_status'] === 'available', 'FR diagnostics must report nbp_eur_rate_status=available after a successful fetch.');
$assert($result['nbp_eur_rate_value'] === 4.0, 'FR diagnostics must expose nbp_eur_rate_value.');
$assert($result['nbp_eur_rate_date'] === '2026-06-02', 'FR diagnostics must expose nbp_eur_rate_date.');
$assert($result['nbp_eur_rate_source'] === 'nbp_table_a', 'FR diagnostics must expose nbp_eur_rate_source.');
$assert($result['ebay_price_eur'] === 31.25, 'FR price calculation must apply markup in PLN before converting to EUR.');
$assert($GLOBALS['wei_fr_test_product_price_written'] === false, 'FR price calculation must not write the changed price back to Woo.');
$assert(isset($GLOBALS['wei_fr_test_transients']['wei_fr_nbp_eur_rate']), 'FR must cache the NBP rate under the FR-specific transient key.');
$assert(isset($GLOBALS['wei_fr_test_options']['wei_fr_nbp_eur_rate_last']), 'FR must persist the last NBP rate under the FR-specific option key.');
$assert(!isset($GLOBALS['wei_fr_test_options']['wei_nbp_eur_rate_last']), 'FR must not write the DE NBP option key.');

$adapterSource = file_get_contents(__DIR__ . '/../src/Adapters/EbayAdapter.php');
$viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
$schedulerSource = file_get_contents(__DIR__ . '/../src/Services/AutoSyncScheduler.php');
$assert(str_contains($adapterSource, "manufacturer_from_french_content(\$content, ['Fabricant'])"), 'Marque mapping must prefer FR translated Fabricant content.');
$assert(str_contains($adapterSource, "manufacturer_from_french_content(\$content, ['Producent'])"), 'Marque mapping must fall back to source Polish Producent content.');
$assert(str_contains($adapterSource, "['hersteller', 'marke', 'marque']"), 'Required FR aspect Marque must be filled from the resolved manufacturer when reliable.');
$assert(str_contains($adapterSource, 'marque_readiness'), 'Preflight diagnostics must expose where Marque was derived from.');
$assert(str_contains($viewSource, 'nbp_eur_rate_status') && str_contains($viewSource, 'nbp_eur_rate_cached_at'), 'FR admin settings/readiness UI must expose NBP EUR rate diagnostics.');
$assert(str_contains($schedulerSource, "'nbp_eur_rate_status'") && str_contains($schedulerSource, "'nbp_eur_rate_value'"), 'FR readiness audit CSV rows must include NBP EUR rate diagnostics.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "FR NBP rate and Marque readiness tests passed\n";
