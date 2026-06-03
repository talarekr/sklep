<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php');
$scheduler = file_get_contents($root . '/src/Services/AutoSyncScheduler.php');
$admin = file_get_contents($root . '/src/Services/AdminPage.php');
$failures = [];

$assertContains = static function (string $haystack, string $needle, string $label) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};

$assertNotContains = static function (string $haystack, string $needle, string $label) use (&$failures): void {
    if (str_contains($haystack, $needle)) {
        $failures[] = $label . ' must not contain: ' . $needle;
    }
};

$methodBlock = static function (string $source, string $methodName): string {
    $start = strpos($source, 'function ' . $methodName . '(');
    if ($start === false) {
        return '';
    }
    $nextPublic = strpos($source, "\n    public function", $start + 1);
    $nextPrivate = strpos($source, "\n    private function", $start + 1);
    $ends = array_filter([$nextPublic, $nextPrivate], static fn($pos) => $pos !== false);
    $end = $ends === [] ? strlen($source) : min($ends);
    return substr($source, $start, $end - $start);
};

$stockGuard = $methodBlock($adapter, 'stock_availability_guard');
foreach ([
    'missing_stock_quantity',
    'stock_quantity_zero',
    'stock_status_outofstock',
    'product_not_purchasable',
    'ovoko_sold_or_unavailable',
    "'status' => 'blocked_by_stock'",
    "'message' => 'Skipped product because stock is not available'",
] as $needle) {
    $assertContains($adapter, $needle, 'Adapter stock safety exact status/reason/logging');
}
foreach ([
    '$stockQuantity === null',
    '$stockQuantity <= 0',
    '$stockStatus === \'outofstock\'',
    '!$purchasable',
    'ovoko_status_guard',
] as $needle) {
    $assertContains($stockGuard, $needle, 'Stock guard blocks invalid Woo/Ovoko stock');
}

foreach ([
    'export_preflight',
    'export_inventory_pre_api',
    'export_offer_pre_api',
    'publish_offer_pre_api',
    'manual_publish_pre_api',
] as $needle) {
    $assertContains($adapter, $needle, 'Export/publish immediate stock preflight');
}

$exportBlock = $methodBlock($adapter, 'export_product');
$inventoryGuardPos = strpos($exportBlock, 'export_inventory_pre_api');
$inventoryCallPos = strpos($exportBlock, 'create_or_replace_inventory_item');
$offerGuardPos = strpos($exportBlock, 'export_offer_pre_api');
$updateOfferPos = strpos($exportBlock, 'update_offer');
$publishGuardPos = strpos($exportBlock, 'publish_offer_pre_api');
$publishCallPos = strpos($exportBlock, 'publish_offer');
$assertContains($exportBlock, 'stock_guard_skip_response', 'Blocked stock product is skipped from export');
if ($inventoryGuardPos === false || $inventoryCallPos === false || $inventoryGuardPos > $inventoryCallPos) {
    $failures[] = 'Inventory API call must be preceded by immediate stock guard.';
}
if ($offerGuardPos === false || $updateOfferPos === false || $offerGuardPos > $updateOfferPos) {
    $failures[] = 'Offer API call must be preceded by immediate stock guard.';
}
if ($publishGuardPos === false || $publishCallPos === false || $publishGuardPos > $publishCallPos) {
    $failures[] = 'Publish API call must be preceded by immediate stock guard.';
}

$publishOnlyBlock = $methodBlock($adapter, 'publish_product_offer_only');
$manualGuardPos = strpos($publishOnlyBlock, 'manual_publish_pre_api');
$manualPublishPos = strpos($publishOnlyBlock, 'publish_offer');
if ($manualGuardPos === false || $manualPublishPos === false || $manualGuardPos > $manualPublishPos) {
    $failures[] = 'Manual publish API call must be preceded by immediate stock guard.';
}

foreach ([
    'stock_quantity',
    'stock_status',
    'manage_stock',
    'purchasable',
    'ovoko_status',
    'stock_block_reason',
] as $needle) {
    $assertContains($scheduler, "'" . $needle . "'", 'Readiness CSV stock diagnostics');
}
$assertContains($scheduler, '$status === \'blocked_by_stock\'', 'Readiness CSV blocks stock status');
$assertContains($scheduler, 'append_publish_readiness_csv_row((string) $paths[\'ready\']', 'Ready-products CSV exists for ready only');
$recordBlock = $methodBlock($scheduler, 'record_full_publish_readiness_product');
$assertContains($recordBlock, "if (!empty(\$result['ready']))", 'Ready-products CSV ready branch');
$assertContains($recordBlock, "append_publish_readiness_csv_row((string) \$paths['ready'], \$headers, \$row);", 'Ready-products CSV append only in ready branch');

foreach ([
    'blocked_by_stock',
    'initial_publish_stock_not_ready_reason',
    'stock_quantity_zero',
    'stock_status_outofstock',
    'product_not_purchasable',
    'missing_stock_quantity',
] as $needle) {
    $assertContains($admin, $needle, 'Initial publish readiness stock safety');
}


if (!function_exists('get_post_meta')) {
    function get_post_meta(int $postId, string $key = '', bool $single = false) {
        global $weiStockSafetyTestMeta;
        return $weiStockSafetyTestMeta[$postId][$key] ?? '';
    }
}

require_once $root . '/src/Interfaces/MarketplaceAdapterInterface.php';
require_once $root . '/src/Adapters/EbayAdapter.php';

final class WeiStockSafetyFakeProduct
{
    public function __construct(
        private int $id,
        private $stockQuantity,
        private string $stockStatus = 'instock',
        private bool $manageStock = true,
        private bool $purchasable = true
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_stock_quantity() { return $this->stockQuantity; }
    public function get_stock_status(): string { return $this->stockStatus; }
    public function get_manage_stock(): bool { return $this->manageStock; }
    public function is_purchasable(): bool { return $this->purchasable; }
}

$adapterReflection = new ReflectionClass(WEI_FR\Adapters\EbayAdapter::class);
$adapterInstance = $adapterReflection->newInstanceWithoutConstructor();
$stockGuardMethod = $adapterReflection->getMethod('stock_availability_guard');
$stockGuardMethod->setAccessible(true);
$guard = static function (WeiStockSafetyFakeProduct $product) use ($stockGuardMethod, $adapterInstance): array {
    return $stockGuardMethod->invoke($adapterInstance, $product, $product->get_id(), null);
};

global $weiStockSafetyTestMeta;
$weiStockSafetyTestMeta = [];

foreach ([
    'stock 0 product is blocked_by_stock' => [new WeiStockSafetyFakeProduct(9001, 0), 'stock_quantity_zero'],
    'outofstock product is blocked_by_stock' => [new WeiStockSafetyFakeProduct(9002, 1, 'outofstock'), 'stock_status_outofstock'],
    'sold/unavailable meta product is blocked_by_stock' => [new WeiStockSafetyFakeProduct(9003, 1), 'ovoko_sold_or_unavailable', ['_ovoko_status' => 'sold']],
    'product without valid purchasable stock is blocked_by_stock' => [new WeiStockSafetyFakeProduct(9004, 1, 'instock', true, false), 'product_not_purchasable'],
    'missing quantity product is blocked_by_stock' => [new WeiStockSafetyFakeProduct(9005, null), 'missing_stock_quantity'],
    'ready product with stock > 0 still exports' => [new WeiStockSafetyFakeProduct(9006, 1), ''],
] as $label => $case) {
    [$product, $expectedReason] = $case;
    $weiStockSafetyTestMeta[$product->get_id()] = $case[2] ?? [];
    $result = $guard($product);
    if ($expectedReason === '') {
        if (!empty($result['blocked'])) {
            $failures[] = $label . ' expected unblocked stock guard, got ' . (string) ($result['stock_block_reason'] ?? '');
        }
        continue;
    }
    if (empty($result['blocked']) || (string) ($result['stock_block_reason'] ?? '') !== $expectedReason) {
        $failures[] = $label . ' expected ' . $expectedReason . ', got ' . json_encode($result);
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Stock safety guard tests passed\n";
