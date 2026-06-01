<?php

$root = dirname(__DIR__);
$schedulerSource = file_get_contents($root . '/src/Services/AutoSyncScheduler.php');
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php');
$viewSource = file_get_contents($root . '/views/admin-page.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
};

foreach ([
    'run_full_publish_readiness_audit',
    'processed_total',
    'total_products',
    'blocked_by_price',
    'blocked_by_stock',
    'blocked_by_images',
    'blocked_by_german_content',
    'blocked_by_required_aspects',
    'publish-readiness-full.csv',
    'publish-readiness-problems-only.csv',
    'publish-readiness-ready-products.csv',
    'publish-readiness-excluded.csv',
] as $needle) {
    $assert(str_contains($schedulerSource, $needle), 'Full publish readiness audit missing scheduler support for: ' . $needle);
}

$methodStart = strpos($schedulerSource, 'public function run_full_publish_readiness_audit');
$methodEnd = strpos($schedulerSource, 'private function publish_readiness_csv_paths', $methodStart);
$assert($methodStart !== false && $methodEnd !== false, 'Could not isolate full publish readiness audit method.');
$methodSource = substr($schedulerSource, $methodStart, $methodEnd - $methodStart);
foreach (['export_product', 'publish_product', 'publish_product_offer_only', 'create_offer', 'publish_offer', 'revise'] as $forbidden) {
    $assert(!str_contains($methodSource, $forbidden), 'Full publish readiness audit must not call publish/export action: ' . $forbidden);
}

foreach (['wei_full_publish_readiness_audit', 'handle_full_publish_readiness_audit', 'latest readiness scan'] as $needle) {
    $assert(str_contains($adminSource, $needle) || str_contains($viewSource, $needle), 'Publish readiness admin UI/action missing: ' . $needle);
}

foreach (['Latest readiness scan', 'Latest publish run', 'Latest eBay state refresh'] as $needle) {
    $assert(str_contains($viewSource, $needle), 'Publish dashboard must label counter source: ' . $needle);
}

foreach (['full_csv', 'problems_only_csv', 'ready_products_csv', 'excluded_csv'] as $needle) {
    $assert(str_contains($adminSource, $needle) && str_contains($viewSource, $needle), 'Publish readiness CSV download missing: ' . $needle);
}

echo "Full publish readiness audit tests passed\n";
