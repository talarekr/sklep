<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$admin = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$adapter = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$view = file_get_contents($root . '/views/admin-page.php') ?: '';
$failures = [];
$assertContains = static function (string $haystack, string $needle, string $label) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};

foreach ([
    'GERMAN_CONTENT_MIGRATION_STATE_OPTION' => 'persistent migration state option',
    'migration_run_id' => 'migration starts fresh with run ID',
    'schema_version' => 'state stores schema version',
    'template_version' => 'state stores template version',
    'started_at' => 'state stores started timestamp',
    'last_updated_at' => 'state stores updated timestamp',
    'completed_at' => 'state stores completed timestamp',
    'complete' => 'state stores completion flag',
    'batch_size' => 'state stores batch size',
    'current_offset' => 'state stores resumable offset',
    'processed_total' => 'state accumulates processed total',
    'total_target_products' => 'state stores total targets',
    'remaining_products' => 'state stores remaining products',
    'regenerated_total' => 'state accumulates regenerated total',
    'already_current_schema_total' => 'state accumulates already-current total',
    'stale_fixed_total' => 'state accumulates stale fixed total',
    'errors_total' => 'state accumulates errors',
    'excluded_skipped_total' => 'state stores excluded skipped count',
    'include_excluded_from_ebay' => 'state stores excluded inclusion flag',
    'processed_product_ids' => 'last summary includes processed product IDs',
    'regenerated_product_ids' => 'last summary includes regenerated product IDs',
    'already_current_schema_product_ids' => 'last summary includes already-current product IDs',
    'error_product_ids' => 'last summary includes error product IDs',
    'sample_products' => 'last summary includes sampled product details',
    'last_batch_sample_limits' => 'last summary documents capped batch sample limits',
] as $needle => $label) {
    $assertContains($admin, $needle, $label);
}

$assertContains($admin, 'new_german_content_schema_migration_state($batchSize, $includeExcluded)', 'force button starts fresh migration state');
$assertContains($admin, "get_option(self::GERMAN_CONTENT_MIGRATION_STATE_OPTION", 'continue loads persisted migration state');
$assertContains($admin, "'force_current_schema', (int) (\$state['current_offset'] ?? 0)", 'continue resumes from current offset');
$assertContains($admin, "\$state['processed_total'] = (int) (\$state['processed_total'] ?? 0) + 1", 'processed_total accumulates across batches');
$assertContains($admin, "\$state['complete'] = true", 'migration marks complete');
$assertContains($admin, "processed_total'] ?? 0) >= (int) (\$state['total_target_products'] ?? 0)", 'complete true when processed total reaches target');
$assertContains($admin, "excluded_meta.meta_value = 'excluded_from_ebay'", 'excluded products are skipped unless included');
$assertContains($admin, 'german-content-migration-full.csv', 'full CSV report filename');
$assertContains($admin, 'german-content-migration-errors.csv', 'errors CSV report filename');
$assertContains($admin, 'german-content-migration-stale-fixed.csv', 'stale fixed CSV report filename');
$assertContains($admin, 'german-content-migration-already-current.csv', 'already current CSV report filename');
$assertContains($admin, 'initialize_german_content_migration_reports($state)', 'fresh run clears old CSV reports so downloads are current run only');
foreach (['product_id','sku','product_title','old_schema_version','new_schema_version','old_template_version','new_template_version','stale_before','stale_after','stale_reasons','regenerated','already_current_schema','source_description_field','source_description_used','error_message'] as $csvColumn) {
    $assertContains($admin, $csvColumn, 'CSV column ' . $csvColumn);
}
$assertContains($admin, "'called_ebay_api' => false", 'migration records no eBay API calls');
$assertContains($admin, "'updated_ebay_listing' => false", 'migration records no active listing updates');
$assertContains($admin, "'published' => false", 'migration records no publish/export actions');
foreach (['processed_product_ids' => 100, 'regenerated_product_ids' => 100, 'already_current_schema_product_ids' => 100, 'error_product_ids' => 100, 'sample_products' => 20] as $sampleKey => $limit) {
    $assertContains($admin, "'" . $sampleKey . "' => " . (string) $limit, 'last summary caps ' . $sampleKey);
}
foreach (['product_id','sku','product_title','regenerated','stale_before','stale_after','stale_reasons','old_schema_version','new_schema_version','source_description_field','preview_url'] as $sampleColumn) {
    $assertContains($admin, $sampleColumn, 'sample product field ' . $sampleColumn);
}
$assertContains($admin, 'german_content_preview_url($productId)', 'sample products include preview URL');
$assertContains($admin, "'result' => 'already_current_schema'", 'already current schema products are skipped in migration batch');
$assertContains($admin, '$regenerated = !$alreadyCurrent', 'already current schema products are not counted as regenerated');
$assertContains($admin, "admin-post.php?action=wei_description_template_preview&product_or_sku=", 'preview URL targets German template preview');
$assertContains($admin, "\$_REQUEST['product_or_sku']", 'preview handler accepts linked product ID');

foreach ([
    'Product ID / SKU' => 'diagnostic accepts product ID/SKU',
    'current_stored_schema_version' => 'diagnostic reports current stored schema',
    'required_schema_version' => 'diagnostic reports required schema',
    'current_stored_template_version' => 'diagnostic reports current stored template',
    'required_template_version' => 'diagnostic reports required template',
    'stale_reasons' => 'diagnostic reports stale reasons',
    'source_description_field' => 'diagnostic reports source description field',
    'source_description_used' => 'diagnostic reports source description used',
    'translated_spec_values_status' => 'diagnostic reports translated spec status',
    'preview_uses_translated_value' => 'diagnostic reports preview translated_value use',
] as $needle => $label) {
    $assertContains($adapter . $view, $needle, $label);
}

foreach (['createOrReplaceInventoryItem', 'publishOffer', 'updateOffer', 'reviseInventoryStatus'] as $forbidden) {
    $migrationStart = strpos($admin, 'private function run_german_content_schema_migration_batch');
    $migrationBlock = $migrationStart === false ? '' : substr($admin, $migrationStart, 9000);
    if (str_contains($migrationBlock, $forbidden)) {
        $failures[] = 'German content migration must not call eBay/listing write API: ' . $forbidden;
    }
}

$assertContains($view, 'Continue German content schema migration', 'admin UI exposes continue button when incomplete');
$assertContains($view, 'IN PROGRESS', 'admin UI status card shows in progress');
$assertContains($view, 'COMPLETE', 'admin UI status card shows complete');
$assertContains($view, 'This updates local German content only. It does not update active eBay listings.', 'admin UI safety copy');
$assertContains($view, 'Last German content migration batch sample (max 20 products)', 'admin UI displays capped batch sample table');
$assertContains($view, '<a class="button button-small" href="<?php echo esc_url($previewUrl); ?>">Preview</a>', 'admin UI shows preview links for sampled products');
$assertContains($view, 'Displayed product IDs and samples are capped', 'admin UI explains sample caps');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "GermanContentMigrationProgress tests passed\n";
