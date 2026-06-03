<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php');
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php');
$client = file_get_contents($root . '/src/Services/EbayClient.php');
$view = file_get_contents($root . '/views/admin-page.php');
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
    $next = strpos($source, "\n    public function", $start + 1);
    $nextPrivate = strpos($source, "\n    private function", $start + 1);
    $ends = array_filter([$next, $nextPrivate], static fn($pos) => $pos !== false);
    $end = $ends === [] ? strlen($source) : min($ends);
    return substr($source, $start, $end - $start);
};

$resetBlock = $methodBlock($admin, 'reset_publish_progress_state');
foreach ([
    'wei_fr_ebay_initial_publish_total_ready',
    'wei_fr_ebay_initial_publish_processed',
    'wei_fr_ebay_initial_publish_success',
    'wei_fr_ebay_initial_publish_failed',
    'wei_fr_ebay_initial_publish_skipped',
    'wei_fr_ebay_initial_publish_cursor',
    'wei_fr_ebay_initial_publish_last_batch_log',
    'wei_fr_ebay_initial_publish_candidate_summary',
] as $needle) {
    $assertContains($admin, $needle, 'Publish reset clears progress/counter/checkpoint state');
}
foreach ([
    'wei_fr_ebay_category',
    '_wei_fr_ebay_',
    'refresh_token',
    'access_token',
    'business_policy',
    '_price',
    '_stock',
    '_thumbnail_id',
] as $forbidden) {
    $assertNotContains($resetBlock, $forbidden, 'Publish reset safety block');
}

foreach ([
    'Resetuj postęp publikacji',
    'Refresh eBay listing state',
    'These counters are from the last publish run. Reset progress before starting a new full publish run if listings were ended manually on eBay.',
    'processed_this_run',
    'exported_this_run',
    'published_this_run',
    'skipped_this_run',
    'errors_this_run',
    'historical_published_count',
    'current_active_listing_count',
    'current_offer_count',
    'publish_progress_published_this_run',
    'published_total_from_old_checkpoint',
    'needs_reexport_count',
    'ended_listing_count',
] as $needle) {
    $assertContains($view, $needle, 'Publish UI summary/reset/refresh');
}

$assertContains($admin, "add_action('admin_post_wei_fr_refresh_ebay_listing_state'", 'Refresh action hook');
$assertContains($admin, 'public function refresh_ebay_listing_state()', 'Refresh action handler');
$assertContains($adapter, 'public function refresh_listing_state(int $limit = 100): array', 'Adapter eBay state refresh');
$assertContains($client, 'public function get_offers(array $query = [], array $context = [])', 'Client offer list API');
foreach ([
    "'_wei_fr_ebay_current_listing_state', 'active'",
    "'_wei_fr_ebay_listing_status', 'ended'",
    "'_wei_fr_ebay_export_status', 'needs_reexport'",
    'browse_get_item_by_legacy_id',
    'get_offer($offerId',
] as $needle) {
    $assertContains($adapter, $needle, 'Refresh marks inactive listings as not published/needs re-export');
}

$alreadyPublishedBlock = $methodBlock($admin, 'initial_publish_already_published_diagnostics');
$assertContains($alreadyPublishedBlock, "'_wei_fr_ebay_current_listing_state', true)", 'Old listing IDs require refreshed active state diagnostics');
$assertContains($alreadyPublishedBlock, "\$listingId !== '' && \$activeListingState === 'active'", 'Active listing IDs are skipped only with active state');
$assertContains($alreadyPublishedBlock, "already_published_active_listing", 'Active already-published skip reason is recorded');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish progress reset safety tests passed\n";
