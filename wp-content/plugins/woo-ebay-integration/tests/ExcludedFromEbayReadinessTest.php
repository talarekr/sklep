<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$schedulerSource = file_get_contents($root . '/src/Services/AutoSyncScheduler.php') ?: '';
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

$assert(str_contains($adapterSource, "'status' => 'excluded_from_ebay'"), 'Adapter preflight must expose excluded_from_ebay status.');
$assert(str_contains($adapterSource, "'reason' => 'excluded_no_woo_category'"), 'Product with no Woo product_cat must use excluded_no_woo_category.');
$assert(str_contains($adapterSource, "'reason' => 'excluded_bez_kategorii'"), 'Product in Bez kategorii must use excluded_bez_kategorii.');
$assert(str_contains($adapterSource, 'private function ebay_publish_exclusion'), 'Adapter must centralize eBay exclusion detection.');
$assert(str_contains($adapterSource, 'wp_get_post_terms($productId, \'product_cat\')'), 'Exclusion detection must inspect Woo product_cat terms.');
$assert(str_contains($adapterSource, 'is_bez_kategorii_term'), 'Exclusion detection must explicitly identify Bez kategorii terms.');

$preflightBlock = substr($adapterSource, strpos($adapterSource, 'public function preflight_product'), strpos($adapterSource, 'public function publish_product_offer_only') - strpos($adapterSource, 'public function preflight_product'));
$assert(strpos($preflightBlock, 'excluded_from_ebay_preflight') < strpos($preflightBlock, 'resolve_german_content'), 'Excluded products must return from preflight before content/category/aspect readiness checks.');
$assert(strpos($preflightBlock, 'excluded_from_ebay_preflight') < strpos($preflightBlock, 'resolve_category'), 'Excluded products must return from preflight before category resolution.');

$exportBlock = substr($adapterSource, strpos($adapterSource, 'public function export_product'), strpos($adapterSource, 'public function refresh_listing_state') - strpos($adapterSource, 'public function export_product'));
$assert(strpos($exportBlock, "'error' => 'excluded_from_ebay'") < strpos($exportBlock, 'create_or_replace_inventory_item'), 'Excluded products must be blocked before eBay inventory API writes.');
$assert(strpos($exportBlock, "'error' => 'excluded_from_ebay'") < strpos($exportBlock, 'publish_offer'), 'Excluded products must be blocked before live publish calls.');

$assert(str_contains($schedulerSource, "if (\$auditStatus === 'excluded_from_ebay')"), 'Category audit must branch excluded products separately.');
$assert(str_contains($schedulerSource, "elseif (\$auditStatus !== 'ready')"), 'Problems-only CSV must exclude intentionally excluded products.');
$assert(str_contains($schedulerSource, "'excluded_products_csv'"), 'Audit should generate a separate excluded-products CSV for transparency.');
$assert(str_contains($schedulerSource, "'excluded_from_ebay_count'"), 'Audit summary must count excluded_from_ebay separately.');
$assert(str_contains($schedulerSource, "'excluded_no_woo_category_count'"), 'Audit summary must count excluded_no_woo_category separately.');
$assert(str_contains($schedulerSource, "'excluded_bez_kategorii_count'"), 'Audit summary must count excluded_bez_kategorii separately.');
$assert(str_contains($schedulerSource, "if (\$auditStatus !== 'ready' && \$auditStatus !== 'excluded_from_ebay')"), 'Excluded products must not be sampled as problems.');

$detailBlock = substr($schedulerSource, strpos($schedulerSource, 'private function increment_category_audit_detail_counts'), strpos($schedulerSource, 'private function audit_status') - strpos($schedulerSource, 'private function increment_category_audit_detail_counts'));
$assert(str_contains($detailBlock, "if (\$auditStatus === 'excluded_from_ebay')"), 'Detail counters must short-circuit excluded products.');
$assert(str_contains($detailBlock, 'return;'), 'Excluded detail counter branch must return before category blocker counters.');

$assert(str_contains($adminSource, "'excluded_from_ebay' =>"), 'Publish readiness/admin summaries must include excluded_from_ebay.');
$assert(str_contains($adminSource, "'excluded_no_woo_category' =>"), 'Publish readiness/admin summaries must include excluded_no_woo_category.');
$assert(str_contains($adminSource, "'excluded_bez_kategorii' =>"), 'Publish readiness/admin summaries must include excluded_bez_kategorii.');
$assert(str_contains($adminSource, "if (\$status === 'excluded_from_ebay')"), 'Initial publish reason normalization must preserve excluded_from_ebay.');
$assert(str_contains($adminSource, "'excluded_products_csv_admin_url'"), 'Admin status must expose excluded-products CSV download metadata.');
$assert(str_contains($adminSource, "? 'excluded_from_ebay' : \$reason"), 'Specific exclusion sub-reasons must keep excluded_from_ebay as the export status.');

$assert(str_contains($viewSource, 'Excluded from eBay'), 'Dashboard must show excluded-from-eBay count.');
$assert(str_contains($viewSource, 'excluded_no_woo_category'), 'Dashboard must show excluded_no_woo_category count.');
$assert(str_contains($viewSource, 'excluded_bez_kategorii'), 'Dashboard must show excluded_bez_kategorii count.');
$assert(str_contains($viewSource, 'Download excluded-products CSV'), 'UI must expose excluded-products CSV download.');
$assert(str_contains($viewSource, "'blocked' => 'blocked_by_category', 'excluded_from_ebay' => 'excluded_from_ebay'"), 'Category audit KPI must show ready, blocked, and excluded separately.');

$auditBlock = substr($schedulerSource, strpos($schedulerSource, 'public function run_full_category_audit'), strpos($schedulerSource, 'private function new_full_category_audit_state') - strpos($schedulerSource, 'public function run_full_category_audit'));
$assert(str_contains($auditBlock, "'audit_mode' => true") && str_contains($auditBlock, "'suppress_side_effects' => true"), 'Full publish readiness/category audit must remain read-only.');
$assert(!str_contains($auditBlock, 'create_or_replace_inventory_item') && !str_contains($auditBlock, 'create_offer') && !str_contains($auditBlock, 'publish_offer'), 'Readiness audit must not call eBay write APIs.');
$assert(!str_contains($auditBlock, 'update_post_meta('), 'Readiness audit must not modify live listing/product meta.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Excluded-from-eBay readiness tests passed\n";
