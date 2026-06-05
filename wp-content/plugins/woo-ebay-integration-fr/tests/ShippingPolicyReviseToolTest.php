<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    "add_action('admin_post_wei_fr_shipping_policy_revise_run'" => 'FR admin-post action must be registered.',
    'check_admin_referer(\'wei_fr_shipping_policy_revise_run\')' => 'FR revise tool must be nonce protected.',
    'require_manage_options()' => 'FR revise tool must require manage_options.',
    'Revise existing listings shipping policy' => 'FR admin UI section must be visible.',
    'Run dry-run' => 'FR dry-run button must be visible.',
    'Run live revise changed offers only' => 'FR live revise button must be visible.',
    'listingPolicies.fulfillmentPolicyId' => 'FR UI must document exact fulfillment-policy-only scope.',
    'wei-fr-shipping-policy-revise-runner' => 'FR optional auto runner must be isolated from publish runner.',
] as $needle => $message) {
    $assert(str_contains($adminSource . $viewSource, $needle), $message);
}

foreach ([
    "'EBAY_FR'" => 'FR marketplace must remain EBAY_FR.',
    "'_wei_fr_ebay_listing_id'" => 'FR must use FR listing_id meta.',
    "'_wei_fr_ebay_offer_id'" => 'FR must use FR offer_id meta.',
    "'_wei_fr_ebay_marketplace'" => 'FR must use FR marketplace meta.',
    "'_wei_fr_ebay_listing_status'" => 'FR must use FR listing status meta.',
    "'_wei_fr_ebay_last_fulfillment_policy_id'" => 'FR must compare stored fulfillment policy meta.',
    "'_wei_fr_ebay_last_shipping_group'" => 'FR must compare/store FR shipping group meta.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

foreach ([
    'fulfillment_policy_changed' => 'Dry-run/live evaluation must detect changed policies.',
    'fulfillment_policy_unchanged' => 'Dry-run/live evaluation must detect unchanged policies.',
    'old_fulfillment_policy_id_unknown' => 'Rows with unknown old policy after offer read must be skipped safely.',
    'missing_offer_id' => 'Rows missing offer meta must be skipped.',
    'missing_listing_id' => 'Rows missing listing meta must be skipped.',
    "['ended', 'sold', 'completed', 'closed', 'deleted']" => 'Ended/sold listings must be skipped.',
    'no_shipping_group_resolved' => 'Rows with no resolved shipping group must be skipped.',
    'wrong_marketplace' => 'Wrong marketplace rows must be skipped.',
    'missing_marketplace' => 'Missing marketplace rows must be skipped.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

foreach ([
    'wei-ebay-integration-fr' => 'FR reports must use the FR upload directory.',
    'fr-shipping-policy-revise-last-run.json' => 'FR last-run JSON filename missing.',
    'fr-shipping-policy-revise-actions.csv' => 'FR actions CSV filename missing.',
    'fr-shipping-policy-revise-errors.csv' => 'FR errors CSV filename missing.',
    "'timestamp','run_id'" => 'CSV columns must include timestamp and run_id.',
    "'marketplace','dry_run','product_id','title','sku','ebay_sku','listing_id','offer_id','listing_url'" => 'CSV columns must include product/listing identity fields.',
    "'old_shipping_group','new_shipping_group','old_fulfillment_policy_id','old_fulfillment_policy_source','ebay_offer_read_attempted','ebay_offer_read_success','ebay_offer_read_error','current_offer_fulfillment_policy_id','new_fulfillment_policy_id','changed','skipped','reason','action','result','error_message'" => 'CSV columns must include policy/action/result fields.',
    "'checked' => 0" => 'Summary must include checked count.',
    "'changed' => 0" => 'Summary must include changed count.',
    "'unchanged' => 0" => 'Summary must include unchanged count.',
    "'skipped' => 0" => 'Summary must include skipped count.',
    "'errors' => 0" => 'Summary must include errors count.',
    '\'dry_run\' => $dryRun' => 'Summary must include dry_run flag.',
    '\'started_at\' => $startedAt' => 'Summary must include started_at.',
    "'finished_at'" => 'Summary must include finished_at.',
    'old_fulfillment_policy_source' => 'Rows/CSV must include old policy source.',
    'ebay_offer_read_attempted' => 'Rows/CSV must include offer read attempted.',
    'ebay_offer_read_success' => 'Rows/CSV must include offer read success.',
    'ebay_offer_read_error' => 'Rows/CSV must include offer read errors.',
    'current_offer_fulfillment_policy_id' => 'Rows/CSV must include current offer policy.',
    'offer_read_attempted' => 'Summary must count offer read attempts.',
    'offer_read_success' => 'Summary must count offer read success.',
    'offer_read_errors' => 'Summary must count offer read errors.',
    'skipped_unknown_policy_after_read' => 'Summary must count unknown policies after read.',
    'dry_run_get_offer_only' => 'Dry-run offer reads must be labeled read-only.',
    'report_write_error' => 'Report write failures must be surfaced.',
    'target_path' => 'Report write failures must include target path.',
    'reason' => 'Report write failures must include reason.',
] as $needle => $message) {
    $assert(str_contains($adminSource . $viewSource, $needle), $message);
}

$readBlockStart = strpos($adapterSource, 'public function read_existing_offer_fulfillment_policy_id');
$readBlockEnd = strpos($adapterSource, 'public function revise_existing_offer_fulfillment_policy_only', $readBlockStart);
$readBlock = $readBlockStart === false ? '' : substr($adapterSource, $readBlockStart, $readBlockEnd - $readBlockStart);
foreach ([
    'safe_read_scope' => 'FR read fallback must log read-only scope.',
    'listingPolicies' => 'FR read fallback must inspect listing policies.',
    'fulfillmentPolicyId' => 'FR read fallback must extract fulfillmentPolicyId.',
    'get_offer' => 'FR read fallback must read an existing offer.',
    'called_update_offer' => 'FR read fallback must explicitly avoid offer updates.',
    'called_create_offer' => 'FR read fallback must explicitly avoid offer creation.',
    'called_publish_offer' => 'FR read fallback must explicitly avoid publishing.',
    'called_inventory_availability_update' => 'FR read fallback must explicitly avoid availability changes.',
    'called_price_update' => 'FR read fallback must explicitly avoid price changes.',
] as $needle => $message) {
    $assert(str_contains($readBlock, $needle), $message);
}
foreach (['update_offer(', 'create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assert(!str_contains($readBlock, $forbidden), 'FR read fallback must not call ' . $forbidden);
}

$reviseBlockStart = strpos($adapterSource, 'public function revise_existing_offer_fulfillment_policy_only');
$reviseBlockEnd = strpos($adapterSource, 'public function update_fulfillment_policy_only', $reviseBlockStart);
$reviseBlock = $reviseBlockStart === false ? '' : substr($adapterSource, $reviseBlockStart, $reviseBlockEnd - $reviseBlockStart);
foreach ([
    'safe_update_scope' => 'Live method must log the safe update scope.',
    'listingPolicies' => 'Live method must update listingPolicies.',
    'fulfillmentPolicyId' => 'Live method must update fulfillmentPolicyId.',
    'get_offer' => 'Live method must read an existing offer before updating.',
    'update_offer' => 'Live method must update an existing offer.',
    'called_create_offer' => 'Live method must explicitly avoid offer creation.',
    'called_publish_offer' => 'Live method must explicitly avoid publishing.',
    'called_inventory_availability_update' => 'Live method must explicitly avoid availability changes.',
    'called_price_update' => 'Live method must explicitly avoid price changes.',
] as $needle => $message) {
    $assert(str_contains($reviseBlock, $needle), $message);
}
foreach (['create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assert(!str_contains($reviseBlock, $forbidden), 'Live revise method must not call ' . $forbidden);
}

foreach (['wei_ebay_settings', '_wei_ebay_offer_id', 'wei-ebay-integration/', 'EBAY_DE'] as $forbidden) {
    $assert(!str_contains(substr($adminSource, strpos($adminSource, 'public function shipping_policy_revise_run'), strpos($adminSource, 'public function update_shipping_policy_one') - strpos($adminSource, 'public function shipping_policy_revise_run')), $forbidden), 'FR admin revise code must not touch DE token: ' . $forbidden);
}



$runnerBlockStart = strpos($viewSource, 'id="wei-fr-shipping-policy-revise-runner"');
$runnerBlockEnd = strpos($viewSource, '<h4>Latest shipping-policy revise report</h4>', $runnerBlockStart);
$runnerBlock = $runnerBlockStart === false ? '' : substr($viewSource, $runnerBlockStart, $runnerBlockEnd - $runnerBlockStart);
foreach ([
    'wei-auto-runner' => 'FR revise runner must reuse the proven auto-runner visual pattern.',
    'data-admin-post-url' => 'FR revise runner must post to admin-post like publish/French content runners.',
    'Delay between batches' => 'FR revise runner must expose a delay control.',
    'id="wei-fr-shipping-policy-revise-delay" type="number" min="0" max="3600" step="1" value="5"' => 'FR revise delay must default to 5 seconds.',
    '<option value="dry_run">dry-run</option>' => 'FR revise mode must include dry-run.',
    '<option value="live">live revise changed offers only</option>' => 'FR revise mode must include fulfillment-policy-only live revise.',
    'data-revise-counter="batches_completed"' => 'FR revise runner must show batches_completed.',
    'data-revise-counter="total_checked"' => 'FR revise runner must show total_checked.',
    'data-revise-counter="total_changed"' => 'FR revise runner must show total_changed.',
    'data-revise-counter="total_unchanged"' => 'FR revise runner must show total_unchanged.',
    'data-revise-counter="total_skipped"' => 'FR revise runner must show total_skipped.',
    'data-revise-counter="total_errors"' => 'FR revise runner must show total_errors.',
    'data-revise-counter="remaining_candidates"' => 'FR revise runner must show remaining_candidates.',
    'data-revise-counter="state"' => 'FR revise runner must show state.',
    'data-revise-counter="stopped_reason"' => 'FR revise runner must show stopped_reason.',
    'data-revise-counter="last_batch_result"' => 'FR revise runner must show last batch JSON.',
] as $needle => $message) {
    $assert(str_contains($runnerBlock, $needle), $message);
}

foreach ([
    'if (reviseState.inFlight)' => 'FR JS must guard against concurrent revise requests.',
    "throw new Error('double_submit_prevented')" => 'FR JS must fail fast on double submit attempts.',
    'await runReviseBatch(batchIndex)' => 'FR JS must wait for each revise request to finish.',
    'await waitRevise(reviseNumberFromInput(reviseDelay, 5, 0, 3600) * 1000)' => 'FR JS must wait the configured delay between batches.',
    "reviseStop.addEventListener('click'" => 'FR JS must implement Stop behavior.',
    "stopReviseRunner('manual_stop')" => 'FR Stop must set a manual stop reason.',
    'summary.queue_empty === true || summary.completed === true' => 'FR loop must continue until queue_empty/completed.',
    'summary.fatal_error || summary.stopped_reason' => 'FR loop must stop on fatal error / stopped reason.',
    "window.addEventListener('beforeunload'" => 'FR runner must abort/stop on page unload.',
    "formData.append('action', reviseRunner.dataset.action || 'wei_fr_shipping_policy_revise_run')" => 'FR JS must use the FR action only.',
    "formData.append('_wpnonce', reviseRunner.dataset.nonce || '')" => 'FR JS must send the FR nonce.',
    "formData.append('auto_runner_batch_index', String(batchIndex))" => 'FR JS must send batch index for paged browser batches.',
] as $needle => $message) {
    $assert(str_contains($viewSource, $needle), $message);
}

foreach ([
    "'auto_runner_batch_index' => $" . "autoRunnerBatchIndex" => 'FR endpoint summary must include auto_runner_batch_index.',
    "'queue_empty' => false" => 'FR endpoint summary must include queue_empty.',
    "'completed' => false" => 'FR endpoint summary must include completed.',
    "'remaining_candidates' => 0" => 'FR endpoint summary must include remaining_candidates.',
    "'remaining_listings' => 0" => 'FR endpoint summary must include remaining_listings.',
    "'fatal_error' => false" => 'FR endpoint summary must include fatal_error.',
    "'stopped_reason' => ''" => 'FR endpoint summary must include stopped_reason.',
    "'report_urls' => $" . "paths['urls']" => 'FR endpoint summary must include report URLs.',
    '$lastRun = $summary + [' => 'FR endpoint JSON must expose counters at the top level as well as under summary.',
    'shipping_policy_revise_candidate_page($batchSize, max(0, ($autoRunnerBatchIndex - 1) * $batchSize))' => 'FR endpoint must page candidates for automatic browser batches.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

$reviseRunBlockStart = strpos($adminSource, 'private function run_shipping_policy_revise_batch');
$reviseRunBlockEnd = strpos($adminSource, 'private function shipping_policy_revise_candidate_page', $reviseRunBlockStart);
$reviseRunBlock = $reviseRunBlockStart === false ? '' : substr($adminSource, $reviseRunBlockStart, $reviseRunBlockEnd - $reviseRunBlockStart);
$dryRunWriteStart = strpos($reviseRunBlock, "if (!$" . "dryRun)");
$beforeLiveWriteBlock = $dryRunWriteStart === false ? $reviseRunBlock : substr($reviseRunBlock, 0, $dryRunWriteStart);
foreach (['revise_existing_offer_fulfillment_policy_only(', 'update_offer(', 'create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assert(!str_contains($beforeLiveWriteBlock, $forbidden), 'FR dry-run mode must not write via ' . $forbidden);
}

foreach (['create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item(', 'title', 'description', 'category'] as $forbidden) {
    $assert(!str_contains($reviseBlock, $forbidden), 'FR live revise method must not change publish/stock/price/title/description/category via token ' . $forbidden);
}
$assert(str_contains($reviseBlock, "\$payload['listingPolicies']['fulfillmentPolicyId']"), 'FR live revise method must only target listingPolicies.fulfillmentPolicyId.');
$assert(str_contains($adminSource, "'EBAY_FR'"), 'FR admin revise code must use EBAY_FR.');
$assert(str_contains($viewSource, 'data-action="wei_fr_shipping_policy_revise_run"'), 'FR runner must keep the FR action separated.');
$assert(str_contains($viewSource, "wp_create_nonce('wei_fr_shipping_policy_revise_run')"), 'FR runner must keep the FR nonce separated.');
$assert(str_contains($adminSource, 'wei-ebay-integration-fr'), 'FR reports must stay in the FR report directory.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "FR shipping policy revise tool tests passed\n";
