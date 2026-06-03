<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$translatorSource = file_get_contents($root . '/src/Services/EbayFrenchContentTranslator.php') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$assertContains = static function (string $haystack, string $needle, string $message) use ($assert): void {
    $assert(str_contains($haystack, $needle), $message . ' Missing: ' . $needle);
};

$frenchSectionStart = strpos($viewSource, 'data-wei-module="french-content"');
$frenchSectionEnd = $frenchSectionStart === false ? false : strpos($viewSource, 'data-wei-module="category-mappings"', $frenchSectionStart);
$frenchSection = $frenchSectionStart === false ? '' : substr($viewSource, $frenchSectionStart, $frenchSectionEnd === false ? null : $frenchSectionEnd - $frenchSectionStart);

foreach ([
    'Auto French Content runner' => 'French auto-runner heading is required.',
    'id="wei-french-content-auto-runner"' => 'French auto-runner wrapper is required.',
    'data-action="wei_fr_regenerate_french_content_batch"' => 'French auto-runner must call the local French content endpoint.',
    "wp_create_nonce('wei_fr_regenerate_french_content_batch')" => 'French auto-runner nonce is required.',
    'id="wei-auto-french-content-batch-size" type="number" min="1" max="200" value="50"' => 'Batch-size input must default to 50 and be bounded/sanitized.',
    'id="wei-auto-french-content-delay" type="number" min="0" max="3600" step="1" value="5"' => 'Delay input must default to 5 seconds.',
    'id="wei-auto-french-content-start"' => 'Start button missing.',
    'id="wei-auto-french-content-stop"' => 'Stop button missing.',
    'id="wei-auto-french-content-include-excluded" type="checkbox"' => 'Explicit include-excluded checkbox missing.',
    'No eBay API calls, no listing updates, no publishing.' => 'Safety help text must be visible.',
    'data-counter="batches_completed"' => 'batches_completed counter missing.',
    'data-counter="total_processed"' => 'total_processed counter missing.',
    'data-counter="total_regenerated"' => 'total_regenerated counter missing.',
    'data-counter="total_already_current"' => 'total_already_current counter missing.',
    'data-counter="total_stale_fixed"' => 'total_stale_fixed counter missing.',
    'data-counter="total_skipped"' => 'total_skipped counter missing.',
    'data-counter="total_failed"' => 'total_failed counter missing.',
    'data-counter="remaining_products"' => 'remaining_products counter missing.',
    'data-counter="total_target_products"' => 'total_target_products counter missing.',
    'data-counter="state"' => 'runner state counter missing.',
    'data-counter="stopped_reason"' => 'stopped_reason counter missing.',
    'data-counter="last_batch_result"' => 'last_batch_result display missing.',
] as $needle => $message) {
    $assertContains($frenchSection, $needle, $message);
}

foreach ([
    "formData.append('action', runner.dataset.action || 'wei_fr_regenerate_french_content_batch')" => 'JS must call only the local French-content endpoint.',
    "formData.append('_wpnonce', runner.dataset.nonce || '')" => 'JS must send nonce.',
    "formData.append('include_excluded_from_ebay', includeExcludedInput.checked ? '1' : '0')" => 'Excluded products must be included only when explicitly checked.',
    "formData.append('force_current_schema', '1')" => 'Runner must enforce current schema regeneration path.',
    "formData.append('wei_fr_auto_french_content_runner', '1')" => 'JS must identify auto-runner requests.',
    'if (state.running || state.inFlight)' => 'Start handler must prevent concurrent requests.',
    'if (state.inFlight)' => 'Batch handler must reject double-submit.',
    "startButton.disabled = status === 'running'" => 'Start button must disable while running.',
    "stopRunner('manual_stop')" => 'Stop button must stop before next batch.',
    'await runBatch(batchIndex)' => 'Runner must wait for each request to finish.',
    'await wait(numberFromInput(delayInput, 5, 0, 3600) * 1000)' => 'Runner must wait configured delay.',
    'batch.fatal_error || batch.stopped_reason' => 'Fatal errors must stop runner.',
    'batch.queue_empty === true || remaining === 0' => 'Queue empty/remaining zero must complete runner.',
    'window.addEventListener(\'beforeunload\'' => 'Runner must not continue after page unload.',
] as $needle => $message) {
    $assertContains($viewSource, $needle, $message);
}

foreach ([
    "add_action('admin_post_wei_fr_regenerate_french_content_batch', [\$this, 'regenerate_french_content_batch_ajax']);" => 'Endpoint hook missing.',
    'public function regenerate_french_content_batch_ajax(): void' => 'Endpoint handler missing.',
    '$this->require_manage_options();' => 'Endpoint must require manage_options.',
    "check_admin_referer('wei_fr_regenerate_french_content_batch');" => 'Endpoint must require nonce.',
    "max(1, min(200, absint(\$_POST['batch_size'] ?? 50)))" => 'Batch size must be sanitized and bounded.',
    '$includeExcluded = !empty($_POST[\'include_excluded_from_ebay\']);' => 'Excluded inclusion must default false.',
    '$forceCurrentSchema = !array_key_exists(\'force_current_schema\', $_POST) || !empty($_POST[\'force_current_schema\']);' => 'Endpoint must enforce force_current_schema by default.',
    'process_french_content_schema_migration_batch($batchSize, $includeExcluded, true)' => 'Endpoint must reuse schema migration path.',
    'generate_french_content_meta_only($productId, true)' => 'Runner path must be same meta-only generation path as product 2081.',
    "'called_ebay_api' => false" => 'Response must assert no eBay API calls.',
    "'updated_ebay_listing' => false" => 'Response must assert no listing writes.',
    "'published' => false" => 'Response must assert no publishing.',
    "'exported' => false" => 'Response must assert no export.',
    "'modified_woo_source_content' => false" => 'Response must assert no Woo source title/description writes.',
    "'remaining_products'" => 'Response must include remaining_products.',
    "'total_target_products'" => 'Response must include total_target_products.',
    "'queue_empty'" => 'Response must include queue_empty.',
    "'fatal_error'" => 'Response must include fatal_error.',
    "'stopped_reason'" => 'Response must include stopped_reason.',
    "'sample_products'" => 'Response must include sample previews when available.',
    "'reports'" => 'Response must include report paths/URLs.',
] as $needle => $message) {
    $assertContains($adminSource, $needle, $message);
}

$endpointStart = strpos($adminSource, 'public function regenerate_french_content_batch_ajax(): void');
$endpointBlock = $endpointStart === false ? '' : substr($adminSource, $endpointStart, 7000);
foreach (['export_product(', 'publishOffer', 'updateOffer', 'createOffer', 'bulkCreateOffer', 'bulkPublishOffer', 'syncService->export', 'update_post([' ] as $forbidden) {
    $assert(!str_contains($endpointBlock, $forbidden), 'French auto-runner endpoint must not call eBay/publish/listing/Woo source write API: ' . $forbidden);
}
$assert(str_contains($endpointBlock, 'wp_send_json($payload)'), 'Endpoint must return JSON for browser runner.');

foreach ([
    "excluded_meta.meta_value = 'excluded_from_ebay'" => 'Excluded products must be skipped by default.',
    "tt.taxonomy = 'product_cat'" => 'Products must have a Woo category by default.',
    "t.name <> 'Bez kategorii'" => 'Bez kategorii products must be skipped by default.',
    "t.slug <> 'bez-kategorii'" => 'Bez kategorii slug must be skipped by default.',
] as $needle => $message) {
    $assertContains($adminSource, $needle, $message);
}

foreach ([
    'french-content-last-run.json' => 'Last-run JSON report missing.',
    'french-content-actions.csv' => 'Actions CSV report missing.',
    'french-content-errors.csv' => 'Errors CSV report missing.',
    "file_put_contents(\$lastRunPath, wp_json_encode(\$state" => 'Last-run JSON must be updated.',
] as $needle => $message) {
    $assertContains($adminSource, $needle, $message);
}

foreach ([
    "public const SCHEMA_VERSION = '2026-06-new-ebay-fr-template-v1';" => 'Required schema version changed or missing.',
    "public const TEMPLATE_VERSION = 'ebay-fr-product-card-template-v1';" => 'Required template version changed or missing.',
    "public const TRANSLATION_SCHEMA_VERSION = 'pl-fr-spec-overrides-2026-06-v3';" => 'Required translation schema version changed or missing.',
] as $needle => $message) {
    $assertContains($translatorSource, $needle, $message);
}

$assert(str_contains($adapterSource, 'french_content_schema_status_for_identifier'), 'Product 2081 readiness diagnostic path must remain available.');
$assert(str_contains($adapterSource, "'ready' => !empty(\$cached['ready'])"), 'Product 2081 generation status must still expose ready=true.');
$assert(!str_contains($frenchSection, 'woo-ebay-integration-de'), 'French runner UI must not alter DE plugin behavior.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "French content auto-runner tests passed\n";
