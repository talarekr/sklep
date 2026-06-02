<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$methodBlock = static function (string $source, string $methodName): string {
    $start = strpos($source, 'function ' . $methodName . '(');
    if ($start === false) {
        return '';
    }
    $nextPublic = strpos($source, "\n    public function ", $start + 1);
    $nextPrivate = strpos($source, "\n    private function ", $start + 1);
    $ends = array_filter([$nextPublic, $nextPrivate], static fn($pos) => $pos !== false);
    $end = $ends === [] ? strlen($source) : min($ends);
    return substr($source, $start, $end - $start);
};

$candidateBlock = $methodBlock($adminSource, 'initial_publish_candidate_product_ids');
$countBlock = $methodBlock($adminSource, 'count_initial_publish_candidates_from_meta');
$cursorCountBlock = $methodBlock($adminSource, 'initial_publish_candidate_count_after_cursor');
$alreadyPublishedBlock = $methodBlock($adminSource, 'initial_publish_already_published_diagnostics');
$runBatchBlock = $methodBlock($adminSource, 'run_initial_publish_batch');
$exportBlock = $methodBlock($adapterSource, 'export_product');
$diagnosticsBlock = $methodBlock($adapterSource, 'local_active_listing_diagnostics');

foreach ([
    $candidateBlock => 'candidate product query',
    $countBlock => 'global candidate count query',
    $cursorCountBlock => 'cursor candidate count query',
] as $block => $label) {
    $assert($block !== '', $label . ' missing.');
    $assert(str_contains($block, "ready_meta.meta_value IN ('ready', 'needs_reexport')"), $label . ' must still allow ready / needs_reexport products.');
    $assert(str_contains($block, 'listing_meta.meta_key = \'_wei_ebay_listing_id\''), $label . ' must inspect listing_id.');
    $assert(str_contains($block, 'item_meta.meta_key = \'_wei_ebay_item_id\''), $label . ' must inspect item_id.');
    $assert(str_contains($block, 'current_active_meta.meta_value = \'active\''), $label . ' must inspect active listing state.');
    $assert(str_contains($block, 'listing_status_meta.post_id IS NULL'), $label . ' must exclude products marked listing_status=published.');
    $assert(str_contains($block, 'export_published_meta.post_id IS NULL'), $label . ' must exclude products already published in this runner session.');
    $assert(str_contains($block, 'AND NOT ('), $label . ' must exclude active listing IDs without blocking offer-only drafts.');
    $assert(str_contains($block, 'listing_meta.post_id IS NOT NULL OR item_meta.post_id IS NOT NULL'), $label . ' must require an existing listing/item ID before excluding active listings.');
}

foreach ([$candidateBlock, $countBlock, $cursorCountBlock] as $block) {
    $assert(!str_contains($block, "offer_id") && !str_contains($block, "_wei_ebay_offer_id"), 'Existing offer_id alone must not exclude products that do not have an active listing.');
}

foreach ([
    'product_id' => 'diagnostics must include product_id.',
    'sku' => 'diagnostics must include sku.',
    'offer_id' => 'diagnostics must include offer_id.',
    'listing_id' => 'diagnostics must include listing_id.',
    'active_listing_state' => 'diagnostics must include active_listing_state.',
    'skipped_reason' => 'diagnostics must include skipped_reason.',
    'already_published_active_listing' => 'skip reason must be already_published_active_listing.',
] as $needle => $message) {
    $assert(str_contains($alreadyPublishedBlock, $needle), 'Admin ' . $message);
    $assert(str_contains($diagnosticsBlock, $needle), 'Adapter ' . $message);
}

$assert(str_contains($exportBlock, '$activeListingDiagnostics = $this->local_active_listing_diagnostics'), 'Adapter must re-check local listing state immediately before publishOffer.');
$assert(strpos($exportBlock, '$activeListingDiagnostics = $this->local_active_listing_diagnostics') < strpos($exportBlock, '$this->client->publish_offer'), 'Adapter active-listing re-check must happen before publishOffer API call.');
$assert(str_contains($exportBlock, "'result' => 'skipped'"), 'Adapter active-listing guard must return a non-fatal skipped result.');
$assert(str_contains($exportBlock, "'status' => 'already_published_active_listing'"), 'Adapter active-listing guard must return the required skipped status.');
$runnerSkippedResultNeedle = <<<'TXT'
($res['result'] ?? '') === 'skipped'
TXT;
$runnerSkippedStatusNeedle = <<<'TXT'
($res['status'] ?? '') === 'already_published_active_listing'
TXT;
$exportStatusNeedle = <<<'TXT'
update_post_meta($product_id, '_wei_ebay_export_status', $syncStatus)
TXT;
$listingStatusNeedle = <<<'TXT'
update_post_meta($metaProductId, '_wei_ebay_listing_status', 'published')
TXT;
$assert(str_contains($runBatchBlock, $runnerSkippedResultNeedle) && str_contains($runBatchBlock, $runnerSkippedStatusNeedle), 'Publish batch must treat active-listing guard as skip, not error.');
$assert(str_contains($runBatchBlock, 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=') && str_contains($runBatchBlock, 'active_listing_state=') && str_contains($runBatchBlock, 'offer_id='), 'Publish batch logs must include skip diagnostics.');
$assert(str_contains($adapterSource, $exportStatusNeedle), 'Successful publish must mark product export status published so the same runner session cannot pick it again.');
$assert(str_contains($adapterSource, $listingStatusNeedle), 'Successful publish must mark listing_status published so the same runner session cannot pick it again.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish duplicate guard tests passed\n";
