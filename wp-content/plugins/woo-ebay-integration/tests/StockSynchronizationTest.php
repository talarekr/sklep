<?php

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Services/StockSyncService.php');
$admin = file_get_contents($root . '/src/Services/AdminPage.php');
$view = file_get_contents($root . '/views/admin-page.php');
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php');
$plugin = file_get_contents($root . '/src/Plugin.php');
$repo = file_get_contents($root . '/src/Repositories/MappingRepository.php');

$failures = [];
$assertContains = static function (string $source, string $needle, string $label) use (&$failures): void {
    if (!str_contains($source, $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};
$assertNotContains = static function (string $source, string $needle, string $label) use (&$failures): void {
    if (str_contains($source, $needle)) {
        $failures[] = $label . ' must not contain: ' . $needle;
    }
};
$pos = static function (string $source, string $needle): int {
    $p = strpos($source, $needle);
    return $p === false ? PHP_INT_MAX : $p;
};

foreach ([
    "public const CRON_HOOK = 'wei_ebay_stock_sync_cron'",
    "public const LOCK_TRANSIENT = 'wei_ebay_stock_sync_lock'",
    'stock-sync-last-run.json',
    'stock-sync-actions.csv',
    'stock-sync-errors.csv',
    'direction',
    'old_woo_stock',
    'eBay_state_after',
    'dry_run',
    'set_woo_stock_zero_outofstock_mark_sold',
    "'_wei_ebay_sold_at'",
    "'_wei_ebay_sold_order_id'",
    "'_wei_ebay_stock_sync_source'",
    "'ebay'",
    "'woo'",
    'GPSW-(\\d+)',
    'bulk_update_price_quantity',
    'get_orders',
    'lock_skip',
    'stock_sync_dry_run',
    "'stock_sync_dry_run'] = isset",
    ': 1;',
    'wc_update_product_stock($product, 0, \'set\')',
    "set_stock_status('outofstock')",
    "'skip_unknown_sku'",
    "'unknown_sku'",
    "'local_stock_recheck_not_zero'",
    "'auth' => '[REDACTED]'",
] as $needle) {
    $assertContains($service, $needle, 'Stock sync service');
}
foreach (['publish_offer(', 'create_offer(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assertNotContains($service, $forbidden, 'Stock sync service never publishes/re-lists');
}

foreach ([
    'add_filter(\'cron_schedules\'',
    'add_action(self::CRON_HOOK',
    'wp_next_scheduled(self::CRON_HOOK)',
    'wp_schedule_event',
    'wp_clear_scheduled_hook',
    'wei_every_5_minutes',
    'wei_every_15_minutes',
    "'hourly'",
] as $needle) {
    $assertContains($service, $needle, 'Cron scheduler');
}

foreach ([
    'StockSyncService',
    '$stockSync->hooks();',
] as $needle) {
    $assertContains($plugin, $needle, 'Plugin boot integration');
}

foreach ([
    'list_active_mappings',
    "status NOT IN ('ended','sold','inactive','unavailable')",
] as $needle) {
    $assertContains($repo, $needle, 'Mapping repository active listing lookup');
}

foreach ([
    'save_stock_sync_settings',
    'run_stock_sync_dry_run',
    'run_stock_sync_now',
    'stock_sync_diagnostics',
    'wei_save_stock_sync_settings',
    'wei_run_stock_sync_dry_run',
    'wei_run_stock_sync_now',
    'wei_stock_sync_diagnostics',
    '$stock_sync_status = $this->stockSync->status();',
] as $needle) {
    $assertContains($admin, $needle, 'Admin actions');
}

foreach ([
    'stock_sync_enabled',
    'stock_sync_woo_to_ebay_enabled',
    'stock_sync_ebay_to_woo_enabled',
    'stock_sync_cron_interval',
    'stock_sync_dry_run',
    'stock_sync_safety_limit',
    'stock_sync_woo_zero_action',
] as $needle) {
    $assertContains($admin, $needle, 'Admin stock sync defaults/settings');
}

foreach ([
    '<h2>1. Stock synchronization</h2>',
    '<h2>2. Publish</h2>',
    '<h2>3. German Content</h2>',
    '<h2>4. Kategorie eBay</h2>',
    '<h2>5. Ustawienia eBay</h2>',
    'Run stock sync dry run now',
    'Run stock sync now',
    'This can end eBay listings or set Woo products out of stock.',
    'Woo → eBay actions last run',
    'eBay → Woo actions last run',
    'Lock status',
    'Product-level diagnostic',
    'product_id / SKU',
    'every 5 minutes',
    'every 15 minutes',
    'hourly',
    'end eBay listing',
    'set eBay offer quantity to 0 if supported',
] as $needle) {
    $assertContains($view, $needle, 'Stock synchronization UI');
}

if (!($pos($view, '<h2>1. Stock synchronization</h2>') < $pos($view, '<h2>2. Publish</h2>'))) {
    $failures[] = 'Stock synchronization module must render above Publish.';
}
if (!($pos($view, '<h2>2. Publish</h2>') < $pos($view, '<h2>3. German Content</h2>') && $pos($view, '<h2>3. German Content</h2>') < $pos($view, '<h2>4. Kategorie eBay</h2>') && $pos($view, '<h2>4. Kategorie eBay</h2>') < $pos($view, '<h2>5. Ustawienia eBay</h2>'))) {
    $failures[] = 'Admin module order must be Stock synchronization, Publish, German Content, Kategorie eBay, Ustawienia eBay.';
}

foreach ([
    "'_wei_ebay_sold_at'",
    'wei_ebay_sold_or_unavailable',
    'stock_status_outofstock',
    'stock_quantity_zero',
] as $needle) {
    $assertContains($adapter, $needle, 'Publish/readiness sold/outofstock guard');
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Stock synchronization tests passed\n";
