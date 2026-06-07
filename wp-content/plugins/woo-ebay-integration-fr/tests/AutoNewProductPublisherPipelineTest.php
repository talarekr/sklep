<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Services/AutoNewProductPublisher.php') ?: '';
$plugin = file_get_contents($root . '/src/Plugin.php') ?: '';
$admin = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$view = file_get_contents($root . '/views/admin-page.php') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    "namespace WEI_FR\\Services" => 'FR service must use FR namespace.',
    "public const HOOK = 'wei_fr_ebay_run_new_product_publish'" => 'FR must use a separate new-product cron hook.',
    "private const REPORT_DIR = 'wei-ebay-integration-fr'" => 'FR reports must use the FR upload directory.',
    "private const LAST_RUN_FILE = 'fr-new-product-publish-last-run.json'" => 'FR last-run JSON filename missing.',
    "private const ACTIONS_FILE = 'fr-new-product-publish-actions.csv'" => 'FR actions CSV filename missing.',
    "private const ERRORS_FILE = 'fr-new-product-publish-errors.csv'" => 'FR errors CSV filename missing.',
    "'EBAY_FR'" => 'FR marketplace missing.',
    "'dry_run' => 1" => 'Dry-run must default on.',
    "'enabled' => 0" => 'Automation must default disabled.',
    "'require_manual_approval' => 1" => 'Manual approval must default on.',
    "'_wei_fr_ebay_listing_id'" => 'FR listing-id duplicate guard/meta missing.',
    "'_wei_fr_ebay_offer_id'" => 'FR offer-id duplicate guard/meta missing.',
    "'_wei_fr_ebay_inventory_item_id'" => 'FR inventory item meta missing.',
    "'_wei_fr_ebay_listing_url'" => 'FR listing URL meta missing.',
    "'_wei_fr_ebay_marketplace'" => 'FR marketplace meta missing.',
    "'_wei_fr_ebay_published_at'" => 'FR published-at meta missing.',
    "auto_generate_french_content_preflight" => 'FR must control French content generation.',
    "regenerate_french_content_on_hash_change" => 'FR stale French content refresh control missing.',
    "existing_marketplace_listing_meta" => 'Duplicate listing skip reason missing.',
    'preflight_product($productId, null, true, false' => 'Dry-run/readiness must use existing preflight without persisting status.',
    'export_product($productId, null, true)' => 'Live run must use existing publish/export adapter path.',
] as $needle => $message) {
    $assert(str_contains($service, $needle), $message);
}
$assert(!str_contains($service, 'EBAY_DE'), 'FR publisher must not reference EBAY_DE.');
$assert(!str_contains($service, '_wei_ebay_listing_id'), 'FR publisher must not use DE listing meta.');

foreach ([
    'AutoNewProductPublisher' => 'Plugin must instantiate/register the publisher service.',
    '$newProductPublisher->hooks();' => 'Publisher hooks must be registered separately.',
] as $needle => $message) {
    $assert(str_contains($plugin, $needle), $message);
}
foreach ([
    'wei_fr_save_new_product_publish_settings' => 'FR admin save action missing.',
    'wei_fr_run_new_product_publish_dry_run' => 'FR admin dry-run action missing.',
    'wei_fr_run_new_product_publish_now' => 'FR admin live action missing.',
] as $needle => $message) {
    $assert(str_contains($admin, $needle), $message);
}
foreach ([
    'Auto New Product Publisher' => 'Admin UI section missing.',
    'EBAY_FR' => 'FR UI must identify EBAY_FR.',
    'Auto-generate missing language content' => 'Language content setting missing.',
    'Dry-run' => 'Dry-run button missing.',
    'Start manual run' => 'Manual run button missing.',
] as $needle => $message) {
    $assert(str_contains($view, $needle), $message);
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

print "FR Auto New Product Publisher pipeline tests passed\n";
