<?php
$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php');
$schedulerSource = file_get_contents($root . '/src/Services/AutoSyncScheduler.php');
$viewSource = file_get_contents($root . '/views/admin-page.php');
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['Publish progress', 'Ready products', 'Exported offers', 'Published listings', 'Remaining to export', 'Remaining to publish', 'Export errors', 'Publish errors', 'Excluded from eBay', 'Blocked / not ready', 'Currently active on eBay'] as $needle) {
    $assert(str_contains($viewSource, $needle), "Publish progress card must display {$needle}.");
}

foreach (['Download ready products CSV', 'Download exported offers CSV', 'Download published listings CSV', 'Download export errors CSV', 'Download publish errors CSV'] as $needle) {
    $assert(str_contains($viewSource, $needle), "Publish progress CSV link missing: {$needle}.");
}

foreach (['ready only', 'exported not published', 'published', 'errors'] as $needle) {
    $assert(str_contains($viewSource, $needle), "Publish progress filter missing: {$needle}.");
}

foreach (['wei_ebay_last_completed_readiness_summary', 'latest_completed_ready_product_ids', 'publish_progress_snapshot', 'remaining_to_export', 'remaining_to_publish'] as $needle) {
    $assert(str_contains($adminSource, $needle), "Publish progress counter source/calculation missing: {$needle}.");
}

foreach (['processed_this_run', 'success_this_run', 'failed_this_run', 'skipped_this_run', 'remaining_after_run', 'batch_size'] as $needle) {
    $assert(str_contains($viewSource, $needle), "Batch progress field missing from UI: {$needle}.");
    $assert(str_contains($adminSource . $schedulerSource, $needle), "Batch progress field missing from run summaries: {$needle}.");
}

foreach (['product_id', 'sku', 'offer_id', 'listing_id', 'marketplace_id', 'status', 'last_exported_at', 'last_published_at', 'error_stage', 'error_message'] as $needle) {
    $assert(str_contains($adminSource, "'{$needle}'"), "Publish progress CSV header missing: {$needle}.");
}

$assert(str_contains($adminSource, "add_action('admin_post_wei_download_publish_progress_csv'"), 'CSV download handler must be registered.');
$assert(str_contains($schedulerSource, "'success_this_run'"), 'Export success must increment export batch success counter.');
$assert(str_contains($adminSource, "'_wei_ebay_last_publish_at'"), 'Published counter/report must use local publish metadata.');
$assert(str_contains($adminSource, "'_wei_ebay_offer_id'"), 'Exported counter/report must use local offer metadata.');
$assert(!str_contains($adminSource, 'refresh_listing_state(') || !str_contains($adminSource, 'publish_progress_snapshot(): array\n    {\n        $res = $this->adapter->refresh_listing_state'), 'Dashboard publish progress snapshot must not call eBay listing refresh.');
$assert(!str_contains($adminSource, 'publish_progress_snapshot(): array\n    {\n        update_post_meta'), 'Dashboard publish progress snapshot must not modify products/listings.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Publish progress counter tests passed\n";
