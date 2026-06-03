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
    'FULL_PUBLISH_READINESS_AUDIT_STATE_OPTION',
    'audit_run_id',
    'current_offset',
    'processed_this_batch',
    'remaining_products',
    'complete',
    'publish_readiness_should_stop_for_time',
    'persist_full_publish_readiness_state',
    'suppress_side_effects',
] as $needle) {
    $assert(str_contains($schedulerSource, $needle), 'Resumable publish readiness audit missing scheduler support for: ' . $needle);
}

$methodStart = strpos($schedulerSource, 'public function run_full_publish_readiness_audit');
$methodEnd = strpos($schedulerSource, 'private function publish_readiness_csv_paths', $methodStart);
$methodSource = substr($schedulerSource, $methodStart, $methodEnd - $methodStart);
$assert(str_contains($methodSource, 'product_ids_for_preflight_page((int) ($state[\'current_offset\''), 'Full publish readiness audit must resume from current_offset.');
$assert(!str_contains($methodSource, 'for ($offset = 0; $offset < $totalProducts; $offset += $batchSize)'), 'Full publish readiness audit must not scan all pages in one request.');
foreach (['create_offer', 'publish_offer', 'revise', 'publish_product_offer_only'] as $forbidden) {
    $assert(!str_contains($methodSource, $forbidden), 'Full publish readiness audit must remain read-only: ' . $forbidden);
}

foreach (['continue_audit', 'Continue readiness audit', 'PARTIAL / IN PROGRESS', 'remaining_products', 'complete'] as $needle) {
    $assert(str_contains($viewSource, $needle) || str_contains($adminSource, $needle), 'Publish readiness UI/admin missing: ' . $needle);
}

$assert(str_contains($schedulerSource, 'fputcsv($fh, $headers, '), 'Publish readiness CSV header writes must call fputcsv.');
$assert(str_contains($schedulerSource, 'fputcsv($fh, array_map'), 'Publish readiness CSV row appends must use fputcsv.');
$assert(str_contains($schedulerSource, "', '\\\\');"), 'CSV writes must pass an explicit escape parameter.');

echo "Resumable publish readiness audit tests passed\n";
