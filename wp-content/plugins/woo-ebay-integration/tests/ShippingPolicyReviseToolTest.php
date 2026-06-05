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
    "add_action('admin_post_wei_shipping_policy_revise_run'" => 'DE admin-post action must be registered.',
    'check_admin_referer(\'wei_shipping_policy_revise_run\')' => 'DE revise tool must be nonce protected.',
    'require_manage_options()' => 'DE revise tool must require manage_options.',
    'Revise existing listings shipping policy' => 'DE admin UI section must be visible.',
    'Run dry-run' => 'DE dry-run button must be visible.',
    'Run live revise changed offers only' => 'DE live revise button must be visible.',
    'listingPolicies.fulfillmentPolicyId' => 'DE UI must document exact fulfillment-policy-only scope.',
    'wei-shipping-policy-revise-runner' => 'DE optional auto runner must be isolated from publish runner.',
] as $needle => $message) {
    $assert(str_contains($adminSource . $viewSource, $needle), $message);
}

foreach ([
    "'EBAY_DE'" => 'DE marketplace must remain EBAY_DE.',
    "'_wei_ebay_listing_id'" => 'DE must use DE listing_id meta.',
    "'_wei_ebay_offer_id'" => 'DE must use DE offer_id meta.',
    "'_wei_ebay_marketplace'" => 'DE must use DE marketplace meta.',
    "'_wei_ebay_listing_status'" => 'DE must use DE listing status meta.',
    "'_wei_ebay_inventory_item_id'" => 'DE must check inventory item ID meta.',
    "'_wei_ebay_inventory_id'" => 'DE must check legacy inventory ID meta.',
    "'_wei_ebay_item_id'" => 'DE must check legacy item/listing ID meta.',
    "'_wei_ebay_public_url'" => 'DE must check legacy public URL meta.',
    "'listing_status'" => 'DE must check legacy listing_status meta.',
    "'marketplace_mappings.remote_listing_id'" => 'DE must check marketplace mapping listing IDs.',
    "'marketplace_mappings.remote_offer_id'" => 'DE must check marketplace mapping offer IDs.',
    "'implicit_EBAY_DE'" => 'Missing marketplace meta must be treated as implicit EBAY_DE.',
    "'_wei_ebay_last_fulfillment_policy_id'" => 'DE must compare stored fulfillment policy meta.',
    "'_wei_ebay_last_shipping_group'" => 'DE must compare/store DE shipping group meta.',
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
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

foreach ([
    'wei-ebay-integration' => 'DE reports must use the DE upload directory.',
    'shipping-policy-revise-last-run.json' => 'DE last-run JSON filename missing.',
    'shipping-policy-revise-actions.csv' => 'DE actions CSV filename missing.',
    'shipping-policy-revise-errors.csv' => 'DE errors CSV filename missing.',
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
    'candidate_products_scanned' => 'Summary must include candidate scan diagnostics.',
    'products_with_listing_id' => 'Summary must count products with listing IDs.',
    'products_with_offer_id' => 'Summary must count products with offer IDs.',
    'products_with_listing_and_offer' => 'Summary must count products with listing and offer IDs.',
    'skipped_missing_listing_id' => 'Summary must count missing listing ID skips.',
    'skipped_missing_offer_id' => 'Summary must count missing offer ID skips.',
    'skipped_marketplace_mismatch' => 'Summary must count marketplace mismatches.',
    'legacy_meta_detected' => 'Summary must count legacy meta detected.',
    'No DE listings found. Checked meta keys:' => 'No listing warning must list checked meta keys.',
    'sample_candidate_products' => 'Debug sample candidate products must be recorded.',
    'meta_keys_found' => 'Rows/debug sample must show found meta keys.',
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
    'safe_read_scope' => 'DE read fallback must log read-only scope.',
    'listingPolicies' => 'DE read fallback must inspect listing policies.',
    'fulfillmentPolicyId' => 'DE read fallback must extract fulfillmentPolicyId.',
    'get_offer' => 'DE read fallback must read an existing offer.',
    'called_update_offer' => 'DE read fallback must explicitly avoid offer updates.',
    'called_create_offer' => 'DE read fallback must explicitly avoid offer creation.',
    'called_publish_offer' => 'DE read fallback must explicitly avoid publishing.',
    'called_inventory_availability_update' => 'DE read fallback must explicitly avoid availability changes.',
    'called_price_update' => 'DE read fallback must explicitly avoid price changes.',
] as $needle => $message) {
    $assert(str_contains($readBlock, $needle), $message);
}
foreach (['update_offer(', 'create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assert(!str_contains($readBlock, $forbidden), 'DE read fallback must not call ' . $forbidden);
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

foreach (['wei_fr_ebay_settings', '_wei_fr_ebay_offer_id', 'wei-ebay-integration-fr', 'EBAY_FR'] as $forbidden) {
    $assert(!str_contains($adminSource, $forbidden), 'DE admin revise code must not touch FR token: ' . $forbidden);
}



$runnerBlockStart = strpos($viewSource, 'id="wei-shipping-policy-revise-runner"');
$runnerBlockEnd = strpos($viewSource, '<h4>Latest shipping-policy revise report</h4>', $runnerBlockStart);
$runnerBlock = $runnerBlockStart === false ? '' : substr($viewSource, $runnerBlockStart, $runnerBlockEnd - $runnerBlockStart);
foreach ([
    'wei-auto-runner' => 'DE revise runner must reuse the proven auto-runner visual pattern.',
    'data-admin-post-url' => 'DE revise runner must post to admin-post like publish/French content runners.',
    'Delay between batches' => 'DE revise runner must expose a delay control.',
    'id="wei-shipping-policy-revise-delay" type="number" min="0" max="3600" step="1" value="5"' => 'DE revise delay must default to 5 seconds.',
    '<option value="dry_run">dry-run</option>' => 'DE revise mode must include dry-run.',
    '<option value="live">live revise changed offers only</option>' => 'DE revise mode must include fulfillment-policy-only live revise.',
    'data-revise-counter="batches_completed"' => 'DE revise runner must show batches_completed.',
    'data-revise-counter="total_checked"' => 'DE revise runner must show total_checked.',
    'data-revise-counter="total_changed"' => 'DE revise runner must show total_changed.',
    'data-revise-counter="total_unchanged"' => 'DE revise runner must show total_unchanged.',
    'data-revise-counter="total_skipped"' => 'DE revise runner must show total_skipped.',
    'data-revise-counter="total_errors"' => 'DE revise runner must show total_errors.',
    'data-revise-counter="remaining_candidates"' => 'DE revise runner must show remaining_candidates.',
    'data-revise-counter="state"' => 'DE revise runner must show state.',
    'data-revise-counter="stopped_reason"' => 'DE revise runner must show stopped_reason.',
    'data-revise-counter="last_batch_result"' => 'DE revise runner must show last batch JSON.',
] as $needle => $message) {
    $assert(str_contains($runnerBlock, $needle), $message);
}

foreach ([
    'if (reviseState.inFlight)' => 'DE JS must guard against concurrent revise requests.',
    "throw new Error('double_submit_prevented')" => 'DE JS must fail fast on double submit attempts.',
    'await runReviseBatch(batchIndex)' => 'DE JS must wait for each revise request to finish.',
    'await waitRevise(reviseNumberFromInput(reviseDelay, 5, 0, 3600) * 1000)' => 'DE JS must wait the configured delay between batches.',
    "reviseStop.addEventListener('click'" => 'DE JS must implement Stop behavior.',
    "stopReviseRunner('manual_stop')" => 'DE Stop must set a manual stop reason.',
    'summary.queue_empty === true || summary.completed === true' => 'DE loop must continue until queue_empty/completed.',
    'summary.fatal_error || summary.stopped_reason' => 'DE loop must stop on fatal error / stopped reason.',
    "window.addEventListener('beforeunload'" => 'DE runner must abort/stop on page unload.',
    "formData.append('action', reviseRunner.dataset.action || 'wei_shipping_policy_revise_run')" => 'DE JS must use the DE action only.',
    "formData.append('_wpnonce', reviseRunner.dataset.nonce || '')" => 'DE JS must send the DE nonce.',
    "formData.append('auto_runner_batch_index', String(batchIndex))" => 'DE JS must send batch index for paged browser batches.',
] as $needle => $message) {
    $assert(str_contains($viewSource, $needle), $message);
}

foreach ([
    "'auto_runner_batch_index' => $" . "autoRunnerBatchIndex" => 'DE endpoint summary must include auto_runner_batch_index.',
    "'queue_empty' => false" => 'DE endpoint summary must include queue_empty.',
    "'completed' => false" => 'DE endpoint summary must include completed.',
    "'remaining_candidates' => 0" => 'DE endpoint summary must include remaining_candidates.',
    "'remaining_listings' => 0" => 'DE endpoint summary must include remaining_listings.',
    "'fatal_error' => false" => 'DE endpoint summary must include fatal_error.',
    "'stopped_reason' => ''" => 'DE endpoint summary must include stopped_reason.',
    "'report_urls' => $" . "paths['urls']" => 'DE endpoint summary must include report URLs.',
    '$lastRun = $summary + [' => 'DE endpoint JSON must expose counters at the top level as well as under summary.',
    'shipping_policy_revise_candidate_page($batchSize, max(0, ($autoRunnerBatchIndex - 1) * $batchSize))' => 'DE endpoint must page candidates for automatic browser batches.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

$reviseRunBlockStart = strpos($adminSource, 'private function run_shipping_policy_revise_batch');
$reviseRunBlockEnd = strpos($adminSource, 'private function shipping_policy_revise_candidate_page', $reviseRunBlockStart);
$reviseRunBlock = $reviseRunBlockStart === false ? '' : substr($adminSource, $reviseRunBlockStart, $reviseRunBlockEnd - $reviseRunBlockStart);
$dryRunWriteStart = strpos($reviseRunBlock, "if (!$" . "dryRun)");
$beforeLiveWriteBlock = $dryRunWriteStart === false ? $reviseRunBlock : substr($reviseRunBlock, 0, $dryRunWriteStart);
foreach (['revise_existing_offer_fulfillment_policy_only(', 'update_offer(', 'create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item('] as $forbidden) {
    $assert(!str_contains($beforeLiveWriteBlock, $forbidden), 'DE dry-run mode must not write via ' . $forbidden);
}

foreach (['create_offer(', 'publish_offer(', 'bulk_update_price_quantity(', 'create_or_replace_inventory_item(', 'title', 'description', 'category'] as $forbidden) {
    $assert(!str_contains($reviseBlock, $forbidden), 'DE live revise method must not change publish/stock/price/title/description/category via token ' . $forbidden);
}
$assert(str_contains($reviseBlock, "\$payload['listingPolicies']['fulfillmentPolicyId']"), 'DE live revise method must only target listingPolicies.fulfillmentPolicyId.');
$assert(str_contains($adminSource, "'EBAY_DE'"), 'DE admin revise code must use EBAY_DE.');
$assert(str_contains($viewSource, 'data-action="wei_shipping_policy_revise_run"'), 'DE runner must keep the DE action separated.');
$assert(str_contains($viewSource, "wp_create_nonce('wei_shipping_policy_revise_run')"), 'DE runner must keep the DE nonce separated.');
$assert(str_contains($adminSource, 'wei-ebay-integration'), 'DE reports must stay in the DE report directory.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "DE shipping policy revise tool tests passed\n";
