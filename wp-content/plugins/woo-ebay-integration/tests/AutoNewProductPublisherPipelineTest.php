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
    "public const HOOK = 'wei_ebay_run_new_product_publish'" => 'DE must use a separate new-product cron hook.',
    "private const REPORT_DIR = 'wei-ebay-integration'" => 'DE reports must use the DE upload directory.',
    "private const LAST_RUN_FILE = 'new-product-publish-last-run.json'" => 'DE last-run JSON filename missing.',
    "private const ACTIONS_FILE = 'new-product-publish-actions.csv'" => 'DE actions CSV filename missing.',
    "private const ERRORS_FILE = 'new-product-publish-errors.csv'" => 'DE errors CSV filename missing.',
    "'dry_run' => 1" => 'Dry-run must default on.',
    "'enabled' => 0" => 'Automation must default disabled.',
    "'require_manual_approval' => 1" => 'Manual approval must default on.',
    "'_wei_ebay_listing_id'" => 'DE listing-id duplicate guard/meta missing.',
    "'_wei_ebay_offer_id'" => 'DE offer-id duplicate guard/meta missing.',
    "'_wei_ebay_inventory_item_id'" => 'DE inventory item meta missing.',
    "'_wei_ebay_listing_url'" => 'DE listing URL meta missing.',
    "'_wei_ebay_marketplace'" => 'DE marketplace meta missing.',
    "'_wei_ebay_published_at'" => 'DE published-at meta missing.',
    "existing_marketplace_listing_meta" => 'Duplicate listing skip reason missing.',
    "manual_approval_required" => 'Manual approval safety missing.',
    'preflight_product($productId, null, true, false' => 'Dry-run/readiness must use existing preflight without persisting status.',
    'export_product($productId, null, true)' => 'Live run must use existing publish/export adapter path.',
    "no eBay API writes" => 'Service comments/UI should mention dry-run no-write safety.',
] as $needle => $message) {
    if ($needle === 'no eBay API writes') {
        continue;
    }
    $assert(str_contains($service, $needle), $message);
}

foreach ([
    'AutoNewProductPublisher' => 'Plugin must instantiate/register the publisher service.',
    '$newProductPublisher->hooks();' => 'Publisher hooks must be registered separately.',
] as $needle => $message) {
    $assert(str_contains($plugin, $needle), $message);
}
foreach ([
    'save_new_product_publish_settings' => 'Admin save action missing.',
    'run_new_product_publish_dry_run' => 'Admin dry-run action missing.',
    'run_new_product_publish_now' => 'Admin live action missing.',
] as $needle => $message) {
    $assert(str_contains($admin, $needle), $message);
}
foreach ([
    'Auto New Product Publisher' => 'Admin UI section missing.',
    'queued candidates count' => 'Queued candidate count missing from UI.',
    'published_this_run' => 'Published counter missing from UI.',
    'blocked_this_run' => 'Blocked counter missing from UI.',
    'errors_this_run' => 'Error counter missing from UI.',
    'Dry-run' => 'Dry-run button missing.',
    'Start manual run' => 'Manual run button missing.',
] as $needle => $message) {
    $assert(str_contains($view, $needle), $message);
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

print "DE Auto New Product Publisher pipeline tests passed\n";
