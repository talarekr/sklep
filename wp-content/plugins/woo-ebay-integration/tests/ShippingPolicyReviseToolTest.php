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
    "'_wei_ebay_last_fulfillment_policy_id'" => 'DE must compare stored fulfillment policy meta.',
    "'_wei_ebay_last_shipping_group'" => 'DE must compare/store DE shipping group meta.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

foreach ([
    'fulfillment_policy_changed' => 'Dry-run/live evaluation must detect changed policies.',
    'fulfillment_policy_unchanged' => 'Dry-run/live evaluation must detect unchanged policies.',
    'old_fulfillment_policy_id_unknown' => 'Rows with unknown old policy must be skipped safely.',
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
    'wei-ebay-integration' => 'DE reports must use the DE upload directory.',
    'shipping-policy-revise-last-run.json' => 'DE last-run JSON filename missing.',
    'shipping-policy-revise-actions.csv' => 'DE actions CSV filename missing.',
    'shipping-policy-revise-errors.csv' => 'DE errors CSV filename missing.',
    "'timestamp','run_id'" => 'CSV columns must include timestamp and run_id.',
    "'marketplace','dry_run','product_id','title','sku','ebay_sku','listing_id','offer_id','listing_url'" => 'CSV columns must include product/listing identity fields.',
    "'old_shipping_group','new_shipping_group','old_fulfillment_policy_id','new_fulfillment_policy_id','changed','skipped','reason','action','result','error_message'" => 'CSV columns must include policy/action/result fields.',
    "'checked' => 0" => 'Summary must include checked count.',
    "'changed' => 0" => 'Summary must include changed count.',
    "'unchanged' => 0" => 'Summary must include unchanged count.',
    "'skipped' => 0" => 'Summary must include skipped count.',
    "'errors' => 0" => 'Summary must include errors count.',
    '\'dry_run\' => $dryRun' => 'Summary must include dry_run flag.',
    '\'started_at\' => $startedAt' => 'Summary must include started_at.',
    "'finished_at'" => 'Summary must include finished_at.',
    'report_write_error' => 'Report write failures must be surfaced.',
    'target_path' => 'Report write failures must include target path.',
    'reason' => 'Report write failures must include reason.',
] as $needle => $message) {
    $assert(str_contains($adminSource . $viewSource, $needle), $message);
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

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "DE shipping policy revise tool tests passed\n";
