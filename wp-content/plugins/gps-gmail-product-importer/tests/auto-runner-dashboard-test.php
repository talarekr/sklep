<?php

declare(strict_types=1);

$plugin = file_get_contents(dirname(__DIR__) . '/gps-gmail-product-importer.php');
if ($plugin === false) {
    throw new RuntimeException('Unable to read plugin file.');
}

function gps_auto_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$required = [
    "wp_ajax_gps_gmail_product_importer_auto_stage_batch" => 'Step 1 AJAX endpoint must be registered.',
    "wp_ajax_gps_gmail_product_importer_auto_prepare_batch" => 'Step 2 AJAX endpoint must be registered.',
    "wp_ajax_gps_gmail_product_importer_auto_create_woo_drafts_batch" => 'Step 3 AJAX endpoint must be registered.',
    "Auto runner — Stage unread Gmail messages" => 'Step 1 auto-runner card must be rendered.',
    "Auto runner — Full preparation audit" => 'Step 2 auto-runner card must be rendered.',
    "Auto runner — Create Woo drafts for ready items" => 'Step 3 auto-runner card must be rendered.',
    "data-ajax-url" => 'Auto-runner cards must use AJAX/fetch instead of full page reloads.',
    "fetch(runner.dataset.ajaxUrl" => 'Browser loop must call admin-ajax with fetch.',
    "check_ajax_referer(self::NONCE_ACTION, 'nonce', false)" => 'AJAX endpoints must require a nonce.',
    "current_user_can('manage_options')" => 'AJAX endpoints must require manage_options.',
    "process_batch(false, max(1, min(25, absint(\$_POST['batch_size'] ?? 5))), true, 'unread')" => 'Step 1 auto-run endpoint must force is:unread staging.',
    "run_full_preparation_batch(max(1, min(25, absint(\$_POST['batch_size'] ?? \$_POST['limit'] ?? 25)))" => 'Step 2 auto-run endpoint must call preparation audit only.',
    "create_woo_drafts_from_ready_staging(max(1, min(25, absint(\$_POST['batch_size'] ?? 10))))" => 'Step 3 auto-run endpoint must call Woo draft creation only.',
    "'no_woo_product_created' => true" => 'Preparation/staging responses must declare no Woo creation.',
    "'no_ovoko_write' => true" => 'Auto-runner batch responses must declare no Ovoko live writes.',
    "'no_allegro_call' => true" => 'Auto-runner batch responses must declare no Allegro calls.',
    "'remaining_messages'" => 'Step 1 counters must include remaining_messages.',
    "'remaining_unprepared'" => 'Step 2 counters must include remaining_unprepared.',
    "'remaining_ready'" => 'Step 3 counters must include remaining_ready.',
    "Show technical details" => 'Raw last_batch_result JSON must be hidden by default.',
    "concurrent_request_blocked" => 'Auto-run endpoints must guard concurrent requests.',
];

foreach ($required as $needle => $message) {
    gps_auto_assert(str_contains($plugin, $needle), $message . " Missing: {$needle}");
}

$stageBlock = substr($plugin, strpos($plugin, 'public function ajax_auto_stage_batch'), strpos($plugin, 'public function ajax_auto_prepare_batch') - strpos($plugin, 'public function ajax_auto_stage_batch'));
gps_auto_assert(!str_contains($stageBlock, 'create_product_from_analysis('), 'Step 1 endpoint must not create Woo products.');
gps_auto_assert(!str_contains($stageBlock, 'run_full_preparation_batch('), 'Step 1 endpoint must not run preparation audit.');

$prepareBlock = substr($plugin, strpos($plugin, 'public function ajax_auto_prepare_batch'), strpos($plugin, 'public function ajax_auto_create_woo_drafts_batch') - strpos($plugin, 'public function ajax_auto_prepare_batch'));
gps_auto_assert(!str_contains($prepareBlock, 'create_woo_drafts_from_ready_staging('), 'Step 2 endpoint must not create Woo drafts.');
gps_auto_assert(!str_contains($prepareBlock, 'process_batch('), 'Step 2 endpoint must not stage Gmail.');

$createBlock = substr($plugin, strpos($plugin, 'public function ajax_auto_create_woo_drafts_batch'), strpos($plugin, 'private function verify_ajax_admin_action') - strpos($plugin, 'public function ajax_auto_create_woo_drafts_batch'));
gps_auto_assert(!str_contains($createBlock, 'process_batch('), 'Step 3 endpoint must not stage Gmail.');
gps_auto_assert(!str_contains($createBlock, 'run_full_preparation_batch('), 'Step 3 endpoint must not run preparation audit.');

gps_auto_assert(str_contains($plugin, "'_gps_woo_draft_readiness_status', 'value' => 'ready_to_create_product'"), 'Woo draft auto-runner must query only ready_to_create_product items.');
gps_auto_assert(str_contains($plugin, "'stopped_reason' = 'remaining_messages_zero'") || str_contains($plugin, "'remaining_messages_zero'"), 'Step 1 stop condition must include remaining_messages = 0.');
gps_auto_assert(str_contains($plugin, "'no_more_ready_items'"), 'Step 3 stop condition must include no ready items remaining.');

echo "Gmail auto-runner dashboard tests passed\n";
