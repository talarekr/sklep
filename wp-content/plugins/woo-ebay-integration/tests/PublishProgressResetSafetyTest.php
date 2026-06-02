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
    'wei_ebay_initial_publish_total_ready',
    'wei_ebay_initial_publish_processed',
    'wei_ebay_initial_publish_success',
    'wei_ebay_initial_publish_failed',
    'wei_ebay_initial_publish_skipped',
    'wei_ebay_initial_publish_cursor',
    'wei_ebay_initial_publish_last_batch_log',
    'wei_ebay_initial_publish_candidate_summary',
] as $needle) {
    $assertContains($admin, $needle, 'Publish reset clears progress/counter/checkpoint state');
}
foreach ([
    'wei_ebay_category',
    '_wei_ebay_de_',
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
    'Readiness audit counters, export run counters, publish run counters, and eBay active listing refresh counters are intentionally separate.',
    'processed_this_run',
    'Exported offers',
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

$assertContains($admin, "add_action('admin_post_wei_refresh_ebay_listing_state'", 'Refresh action hook');
$assertContains($admin, 'public function refresh_ebay_listing_state()', 'Refresh action handler');
$assertContains($adapter, 'public function refresh_listing_state(int $limit = 100): array', 'Adapter eBay state refresh');
$assertContains($client, 'public function get_offers(array $query = [], array $context = [])', 'Client offer list API');
foreach ([
    "'_wei_ebay_current_listing_state', 'active'",
    "'_wei_ebay_listing_status', 'ended'",
    "'_wei_ebay_export_status', 'needs_reexport'",
    'browse_get_item_by_legacy_id',
    'get_offer($offerId',
] as $needle) {
    $assertContains($adapter, $needle, 'Refresh marks inactive listings as not published/needs re-export');
}

$alreadyPublishedBlock = $methodBlock($admin, 'is_initial_publish_already_published');
$assertContains($alreadyPublishedBlock, "'_wei_ebay_current_listing_state', true) === 'active'", 'Old listing IDs require refreshed active state');
$assertNotContains($alreadyPublishedBlock, "'_wei_ebay_listing_id'", 'Old listing_id alone is not active');
$assertNotContains($alreadyPublishedBlock, "'_wei_ebay_item_id'", 'Old item_id alone is not active');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish progress reset safety tests passed\n";
