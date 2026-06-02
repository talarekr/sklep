<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Services/EbayPriceResolver.php';
require_once __DIR__ . '/../src/Services/EbayShippingPolicyResolver.php';

use WEI\Services\EbayPriceResolver;
use WEI\Services\Logger;

if (!function_exists('get_transient')) {
    function get_transient($key) { return ['nbp_rate' => 5.0, 'nbp_effective_date' => '2026-01-01', 'nbp_table_no' => '001/A/NBP/2026']; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
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
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return false; }
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
    public function get_price(): string { return '100'; }
    public function get_category_ids(): array { return []; }
};

$resolver = new EbayPriceResolver(new Logger());
$result = $resolver->resolve($product, 123, ['ebay_default_markup_percent' => 25], true);
$assert($result['markup_percent'] === 25.0, 'Default markup % must save/use 25 when configured.');
$assert($result['woo_price_pln'] === 100.0, 'Diagnostics must expose woo_price_pln.');
$assert($result['price_after_markup_pln'] === 125.0, 'Markup 25 must increase Woo price by 25% before EUR conversion.');
$assert($result['exchange_rate'] === 5.0, 'Diagnostics must expose exchange_rate.');
$assert($result['ebay_price_eur'] === 25.0, 'Diagnostics must expose ebay_price_eur after markup and conversion.');

$result = $resolver->resolve($product, 123, ['ebay_default_markup_percent' => ''], true);
$assert($result['markup_percent'] === 25.0, 'Empty markup must fall back to 25.');
$assert($result['price_after_markup_pln'] === 125.0, 'Empty markup fallback must preserve existing 25% behavior.');

$viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
$adminSource = file_get_contents(__DIR__ . '/../src/Services/AdminPage.php');
$adapterSource = file_get_contents(__DIR__ . '/../src/Adapters/EbayAdapter.php');
$schedulerSource = file_get_contents(__DIR__ . '/../src/Services/AutoSyncScheduler.php');
$assert(str_contains($viewSource, '4. Ustawienia eBay') || str_contains($viewSource, '>Ustawienia eBay<'), 'UI must expose Ustawienia eBay as a normal admin module.');
$assert(str_contains($viewSource, 'Preview shipping/price resolution'), 'UI must expose product-level shipping/price diagnostics in the eBay settings module.');
$assert(str_contains($viewSource, 'If set to 25, eBay prices are 25% higher than WooCommerce prices before currency conversion.'), 'UI must include markup helper text.');
$assert(str_contains($adminSource, "add_action('admin_post_wei_save_ebay_settings'") && str_contains($adminSource, 'public function save_ebay_settings'), 'Dedicated visible eBay settings save handler must exist.');
$assert(str_contains($adapterSource, 'selected_shipping_group') && str_contains($adapterSource, 'selected_shipping_policy_id') && str_contains($adapterSource, 'price_after_markup_pln'), 'Preflight readiness must include shipping and price resolution diagnostics.');
$assert(str_contains($schedulerSource, "'selected_shipping_group'") && str_contains($schedulerSource, "'selected_shipping_policy_id'") && str_contains($schedulerSource, "'missing_shipping_policy_mapping'") && str_contains($schedulerSource, "'price_after_markup_pln'") && str_contains($schedulerSource, "'ebay_price_eur'"), 'Publish readiness audit CSV must include selected shipping and price diagnostics.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "eBay settings shipping/price tests passed\n";
