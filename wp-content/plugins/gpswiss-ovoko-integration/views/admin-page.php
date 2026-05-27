<?php
/** @var array $data */
/** @var array|null $notice */

$csvStatus = (array) ($data['csv_mapping_status'] ?? []);
$memoryLimitRaw = (string) ini_get('memory_limit');
$memoryLimitMb = (int) preg_replace('/[^0-9]/', '', $memoryLimitRaw);
$blockFullBulk = $memoryLimitMb > 0 && $memoryLimitMb <= 128;
$showAdvancedTools = defined('GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS') ? (bool) GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS : true;

$apiConnection = (array) ($data['api_connection_test'] ?? []);
$apiOk = !empty($apiConnection['ok']);
$apiStatusText = $apiOk ? 'OK' : ('ERROR — ' . (string) ($apiConnection['error'] ?? $apiConnection['reason'] ?? 'not tested'));
$csvLoaded = !empty($csvStatus['rows_total']);
$lastSyncStatus = (string) ($data['settings']['ovoko_sync_mode'] ?? 'unknown');

$noticePayload = null;
if (!empty($notice['text']) && is_string($notice['text'])) {
    $decoded = json_decode($notice['text'], true);
    if (is_array($decoded)) {
        $noticePayload = $decoded;
    }
}

$noticeActionName = (string) ($noticePayload['action_name'] ?? '');
$productActionNames = [
    'Create Woo draft product from RRR part',
    'Update product cards from Ovoko CSV mapping',
    'Single enrichment dry-run',
    'Apply Allegro to Ovoko details enrichment',
    'Update Woo descriptions from Ovoko listing text',
];
$hasApiMarkers = isset($noticePayload['status_label']) || isset($noticePayload['tested_endpoint']) || isset($noticePayload['http_status']);
$hasProductId = !empty($noticePayload['product_id']) || !empty($noticePayload['sample_results'][0]['product_id']);
$isApiTestResult = is_array($noticePayload) && $hasApiMarkers && !$hasProductId;
$isKnownProductAction = in_array($noticeActionName, $productActionNames, true);
$showProductSummary = is_array($noticePayload) && !$isApiTestResult && ($isKnownProductAction || $hasProductId);
?>
<div class="wrap" style="max-width:1180px;">
    <h1>Ovoko / RRR Integration</h1>

    <div class="notice notice-info"><p>
        <strong>API connection:</strong> <?php echo esc_html($apiStatusText); ?> |
        <strong>CSV mapping:</strong> <?php echo $csvLoaded ? 'loaded' : 'not loaded'; ?> |
        <strong>CSV rows:</strong> <?php echo esc_html((string) ($csvStatus['rows_total'] ?? 0)); ?> |
        <strong>Unique codes:</strong> <?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? 0)); ?> |
        <strong>Duplicates:</strong> <?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? 0)); ?> |
        <strong>PHP memory_limit:</strong> <?php echo esc_html($memoryLimitRaw); ?> |
        <strong>Last sync status:</strong> <?php echo esc_html($lastSyncStatus); ?>
    </p></div>


    <div style="margin:8px 0 14px;">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_test_api_connection'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_test_api_connection" />
            <?php submit_button('Test API connection', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_test_updatepart_place_for_product_43302'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_test_updatepart_place_for_product_43302" />
            <?php submit_button('Test updatePart place for product 43302', 'secondary', 'submit', false); ?>
        </form>
        <?php if (!empty($apiConnection)): ?>
            <details style="display:inline-block; vertical-align:middle;"><summary>Show API test details</summary><pre><?php echo esc_html(wp_json_encode($apiConnection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-left:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_export_missing_ovoko_id'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_export_missing_ovoko_id" />
            <?php submit_button('Export products missing Ovoko ID', 'secondary', 'submit', false); ?>
        </form>
    </div>

    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
            <p><?php echo esc_html((string) ($notice['text'] ?? '')); ?></p>
            <?php if (is_array($noticePayload)): ?>
                <?php if ($isApiTestResult): ?>
                    <div class="postbox" style="padding:12px; margin:10px 0 0; max-width:760px;">
                        <h3 style="margin-top:0;">API connection: <?php echo esc_html((string) ($noticePayload['status_label'] ?? ($noticePayload['ok'] ? 'OK' : 'ERROR'))); ?></h3>
                        <ul style="margin:0 0 0 18px;">
                            <li><strong>Endpoint:</strong> <code><?php echo esc_html((string) ($noticePayload['tested_endpoint'] ?? '')); ?></code></li>
                            <li><strong>HTTP status:</strong> <code><?php echo esc_html((string) ($noticePayload['http_status'] ?? '')); ?></code></li>
                            <li><strong>Base URL:</strong> <code><?php echo esc_html((string) ($noticePayload['base_url'] ?? '')); ?></code></li>
                            <li><strong>Token present:</strong> <code><?php echo !empty($noticePayload['token_present']) ? 'yes' : 'no'; ?></code></li>
                            <li><strong>Credentials present:</strong> <code><?php echo !empty($noticePayload['credentials_present']) ? 'yes' : 'no'; ?></code></li>
                            <li><strong>Reason:</strong> <code><?php echo esc_html((string) ($noticePayload['reason'] ?? '')); ?></code></li>
                            <li><strong>Checked at:</strong> <code><?php echo esc_html((string) ($noticePayload['checked_at'] ?? $noticePayload['tested_at'] ?? $noticePayload['timestamp'] ?? '')); ?></code></li>
                        </ul>
                    </div>
                <?php elseif ($showProductSummary): ?>
                    <ul style="margin-left:18px;">
                        <li><strong>product_id:</strong> <code><?php echo esc_html((string) ($noticePayload['product_id'] ?? ($noticePayload['sample_results'][0]['product_id'] ?? ''))); ?></code></li>
                        <li><strong>matched_ovoko_part_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_part_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_part_id'] ?? ''))); ?></code></li>
                        <li><strong>matched_ovoko_car_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_car_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_car_id'] ?? ''))); ?></code></li>
                        <?php
                        $summaryAttributesCount = $noticePayload['attributes_count'] ?? null;
                        if ($summaryAttributesCount === null && isset($noticePayload['attributes_written']) && is_array($noticePayload['attributes_written'])) {
                            $summaryAttributesCount = count($noticePayload['attributes_written']);
                        }
                        if ($summaryAttributesCount === null) {
                            $sample = (array) ($noticePayload['sample_results'][0] ?? []);
                            $summaryAttributesCount = $sample['attributes_count'] ?? null;
                            if ($summaryAttributesCount === null && isset($sample['attributes_written']) && is_array($sample['attributes_written'])) {
                                $summaryAttributesCount = count($sample['attributes_written']);
                            }
                            if ($summaryAttributesCount === null) {
                                $summaryAttributesCount = $sample['would_write_attributes_count'] ?? ($noticePayload['would_write_attributes_count'] ?? '');
                            }
                        }
                        ?>
                        <li><strong>attributes_count:</strong> <code><?php echo esc_html((string) $summaryAttributesCount); ?></code></li>
                        <li><strong>no_price_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_price_change'] ?? ($noticePayload['sample_results'][0]['no_price_change'] ?? ''))); ?></code></li>
                        <li><strong>no_stock_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_stock_change'] ?? ($noticePayload['sample_results'][0]['no_stock_change'] ?? ''))); ?></code></li>
                        <li><strong>no_images_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_images_change'] ?? ($noticePayload['sample_results'][0]['no_images_change'] ?? ''))); ?></code></li>
                        <li><strong>no_title_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_title_change'] ?? ($noticePayload['sample_results'][0]['no_title_change'] ?? ''))); ?></code></li>
                        <li><strong>memory_peak_mb:</strong> <code><?php echo esc_html((string) ($noticePayload['memory_peak_mb'] ?? ($noticePayload['sample_results'][0]['memory_peak_mb'] ?? ''))); ?></code></li>
                    </ul>
                <?php endif; ?>
                <details><summary>Show technical JSON</summary><pre><?php echo esc_html(wp_json_encode($noticePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Main actions</h2>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Create Woo draft product from RRR part</h3>
        <p>Creates Woo draft product. Does not publish to Allegro/eBay/batches.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_create_rrr_woo_draft'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_create_rrr_woo_draft" />
            <label for="create_draft_part_id">part_id:</label>
            <input id="create_draft_part_id" type="number" min="1" name="part_id" value="10994" />
            <?php submit_button('Create draft product', 'primary', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update single existing product from CSV mapping</h3>
        <p>Updates only Ovoko/RRR detail attributes and meta. Does not change price, stock, images, title or publication status.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_single_enrichment_dry_run'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_single_enrichment_dry_run" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <input type="hidden" name="form_source" value="single_update_form" />
            <label for="single_product_id">product_id:</label>
            <input id="single_product_id" type="number" min="1" name="product_id" value="2081" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_apply_allegro_to_ovoko_details'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_apply_allegro_to_ovoko_details" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="form_source" value="single_update_form" />
            <label for="single_product_id_apply">product_id:</label>
            <input id="single_product_id_apply" type="number" min="1" name="product_id" value="2081" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <label><input type="checkbox" name="force_api_override" value="1" /> Force apply even when API test fails</label>
            <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply update</button>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update product cards from CSV mapping</h3>
        <p>CSV maps part number to Ovoko part ID.</p>
        <?php if ($blockFullBulk): ?><div class="notice notice-warning"><p>Apply is blocked for low memory_limit. Use dry-run or increase to 256M.</p></div><?php endif; ?>
        <form id="gpswiss_ovoko_batch_update_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <input type="hidden" name="form_source" value="batch_update_form" />
            <label for="bulk_product_ids_csv">product_ids_csv (optional):</label>
            <input id="bulk_product_ids_csv" type="text" class="regular-text" name="product_ids_csv" value="" />
            <label for="bulk_after_product_id">after_product_id:</label>
            <input id="bulk_after_product_id" type="number" min="0" name="after_product_id" value="0" />
            <label for="bulk_limit">limit:</label>
            <input id="bulk_limit" type="number" min="1" max="50" name="limit" value="3" />
            <label for="bulk_batch_size">batch_size:</label>
            <input id="bulk_batch_size" type="number" min="1" max="3" name="batch_size" value="2" />
            <label for="bulk_sleep_ms">sleep_ms:</label>
            <input id="bulk_sleep_ms" type="number" min="250" max="10000" step="250" value="1200" />
            <label for="bulk_limit_total">limit_total:</label>
            <input id="bulk_limit_total" type="number" min="0" step="1" value="0" />
            <label><input type="checkbox" id="bulk_stop_on_error" checked="checked" /> Stop on error</label>
            <label><input type="checkbox" id="bulk_skip_already_enriched" checked="checked" /> Skip already enriched</label>
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <label><input type="checkbox" id="bulk_apply_confirm" /> I understand this will update product details/meta for matching products.</label>
            <br><br>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run selected batch</button>
            <label><input type="checkbox" name="force_api_override" value="1" /> Force apply even when API test fails</label> <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply batch</button>
            <button class="button button-secondary" type="button" id="gpswiss_autorun_start_dry_run">Start auto dry-run</button>
            <button class="button button-primary" type="button" id="gpswiss_autorun_start_apply" style="background:#b32d2e;border-color:#8f2223;color:#fff;">Start auto apply</button>
            <span style="display:inline-block;margin-left:8px;color:#b32d2e;font-weight:600;">Warning: apply mode writes product details/meta changes.</span>
            <button class="button" type="button" id="gpswiss_autorun_pause">Pause</button>
            <button class="button" type="button" id="gpswiss_autorun_resume">Resume</button>
            <button class="button" type="button" id="gpswiss_autorun_stop">Stop</button>
            <button class="button" type="button" id="gpswiss_autorun_reset_state">Reset auto-run state</button>
            <button class="button" type="button" id="gpswiss_autorun_download_jsonl">Download full log JSONL</button>
            <button class="button" type="button" id="gpswiss_autorun_download_csv">Download full log CSV</button>
            <button class="button" type="button" id="gpswiss_autorun_download_skipped_errors_csv">Download skipped/errors CSV</button>
            <button class="button" type="button" id="gpswiss_autorun_download_and_clear">Download and clear log</button>
        </form>
        <div id="gpswiss_autorun_status" style="margin-top:10px;padding:10px;background:#f6f7f7;">
            <!-- current_admin_hook_suffix: <?php echo esc_html((string) ($currentAdminHookSuffix ?? '')); ?> -->
            <strong>Status:</strong> <span data-k="status">idle</span> |
            <strong>Run summary:</strong><br>
            Started at: <span data-k="started_at">-</span> |
            Finished at: <span data-k="finished_at">-</span> |
            Duration: <span data-k="duration_seconds">0</span>s |
            Mode: <span data-k="mode">dry_run</span><br>
            Start after_product_id: <span data-k="start_after_product_id">0</span> |
            Last after_product_id: <span data-k="last_after_product_id">0</span> |
            Next after_product_id: <span data-k="next_after_product_id">0</span><br>
            Total scanned: <span data-k="total_scanned">0</span> |
            Total processed: <span data-k="total_processed">0</span> |
            Total updated: <span data-k="total_updated">0</span> |
            Total skipped: <span data-k="total_skipped">0</span> |
            Total errors: <span data-k="total_errors">0</span><br>
            CSV matched: <span data-k="total_csv_matched">0</span> |
            No CSV match: <span data-k="total_no_csv_match">0</span> |
            Ambiguous CSV match: <span data-k="total_ambiguous_csv_match">0</span> |
            Already enriched skipped: <span data-k="total_already_enriched_skipped">0</span> |
            Not Allegro product: <span data-k="total_not_allegro_product">0</span><br>
            Safety violations: <span data-k="total_safety_violations">0</span> |
            API errors: <span data-k="total_api_error">0</span> |
            Memory guard stops: <span data-k="total_memory_guard">0</span> |
            Other errors: <span data-k="total_other_error">0</span> |
            Batch duration: <span data-k="batch_duration">0</span>s |
            Memory peak MB: <span data-k="memory_peak_mb">0</span><br>
            <span style="color:#b32d2e;" data-k="localstorage_warning"></span>
            <br>
            <strong>Auto-run JS loaded:</strong> <span id="gpswiss_autorun_js_loaded">no</span>
            <br>
            <strong>Auto-run JS expected asset URL:</strong> <code><?php echo esc_html((string) ($autoRunExpectedAssetUrl ?? '')); ?></code><br>
            <strong>Admin page slug:</strong> <code><?php echo esc_html((string) ($adminPageSlug ?? '')); ?></code><br>
            <strong>Hook suffix:</strong> <code><?php echo esc_html((string) ($currentAdminHookSuffix ?? '')); ?></code><br>
            <strong>Auto-run enqueue condition met:</strong> <code><?php echo !empty($autoRunScriptEnqueued) ? 'yes' : 'no'; ?></code><br>
            <strong>Auto-run JS file exists:</strong> <code><?php echo !empty($autoRunFileExists) ? 'true' : 'false'; ?></code>
            <?php if (empty($autoRunFileExists)) : ?>
                <div class="notice notice-error inline"><p>Auto-run JS missing: <?php echo esc_html((string) ($autoRunAssetPath ?? '')); ?></p></div>
            <?php endif; ?>
        </div>
        <p style="margin-top:8px;">
            <button class="button" type="button" id="gpswiss_autorun_js_test">Test auto-run JS</button>
        </p>
        <pre id="gpswiss_autorun_logs" style="max-height:260px;overflow:auto;background:#111;color:#e6e6e6;padding:10px;"></pre>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update Woo descriptions from Ovoko listing text</h3>
        <p>Updates only Woo description/meta from Ovoko listing text. No price/stock/images/gallery/listing-image/title/status/category/Allegro/eBay/attributes sync changes.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_update_description_from_listing_text'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_update_description_from_listing_text" />
            <label for="desc_product_id">product_id (optional):</label>
            <input id="desc_product_id" type="number" min="0" name="product_id" value="0" />
            <label for="desc_after_product_id">after_product_id:</label>
            <input id="desc_after_product_id" type="number" min="0" name="after_product_id" value="0" />
            <label for="desc_limit">limit:</label>
            <input id="desc_limit" type="number" min="1" max="100" name="limit" value="1" />
            <label for="desc_batch_size">batch_size:</label>
            <input id="desc_batch_size" type="number" min="1" max="100" name="batch_size" value="1" />
            <br><br>
            <label><input type="checkbox" name="dry_run" value="1" checked="checked" /> dry_run (default true)</label>
            <label><input type="checkbox" name="save_to_meta_only" value="1" checked="checked" /> save_to_meta_only (default true)</label>
            <label><input type="checkbox" name="update_only_empty_description" value="1" checked="checked" /> update_only_empty_description (default true)</label>
            <label><input type="checkbox" name="replace_existing_description" value="1" /> replace_existing_description (default false)</label>
            <label><input type="checkbox" name="prepend_to_existing_description" value="1" /> prepend_to_existing_description (default false)</label>
            <label><input type="checkbox" name="stop_on_error" value="1" /> stop_on_error</label>
            <br><br>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run description update</button>
            <button class="button button-primary" type="submit" name="apply" value="1">Apply description update</button>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>CSV mapping</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('gpswiss_ovoko_import_csv_mapping'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_import_csv_mapping" />
            <label for="csv_mapping_file">Upload/import CSV:</label>
            <input id="csv_mapping_file" type="file" name="csv_mapping_file" accept=".csv,text/csv" />
            <label for="csv_file_path">or local path:</label>
            <input id="csv_file_path" type="text" class="regular-text" name="csv_file_path" value="/workspace/sklep/parts-stock-2026-05-25.csv" />
            <?php submit_button('Upload/import CSV', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
            <?php wp_nonce_field('gpswiss_ovoko_import_csv_mapping'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_import_csv_mapping" />
            <input type="hidden" name="csv_file_path" value="/workspace/sklep/parts-stock-2026-05-25.csv" />
            <?php submit_button('Rebuild CSV index', 'secondary', 'submit', false); ?>
        </form>
        <ul>
            <li>rows_total: <code><?php echo esc_html((string) ($csvStatus['rows_total'] ?? '0')); ?></code></li>
            <li>unique_part_codes: <code><?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? '0')); ?></code></li>
            <li>duplicate_part_codes_count: <code><?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? '0')); ?></code></li>
            <li>detected delimiter: <code><?php echo esc_html((string) ($csvStatus['delimiter'] ?? 'n/a')); ?></code></li>
            <li>current CSV file name/date: <code><?php echo esc_html((string) (($csvStatus['file_name'] ?? 'n/a') . ' / ' . ($csvStatus['imported_at'] ?? 'n/a'))); ?></code></li>
        </ul>
    </div>

    <?php if ($showAdvancedTools): ?>
    <details style="margin-top:18px;"><summary><strong>Advanced / Diagnostics (developer tools)</strong></summary>
        <div class="postbox" style="padding:16px; margin-top:10px;">
            <p>Technical and legacy tools moved here.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                <?php wp_nonce_field('gpswiss_ovoko_bulk_diagnostics_ping'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bulk_diagnostics_ping" />
                <input type="text" class="regular-text" name="product_ids_csv" value="" placeholder="product_ids_csv" />
                <?php submit_button('Bulk diagnostics / ping', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_single_enrichment_dry_run'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_single_enrichment_dry_run" />
                <label>Product ID:</label><input type="number" min="1" name="product_id" value="2081" />
                <?php submit_button('Single enrichment dry-run (JSON)', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_preview_allegro_to_ovoko_match'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_preview_allegro_to_ovoko_match" />
                <label>Product ID:</label><input type="number" min="1" name="product_id" value="0" />
                <?php submit_button('Legacy preview match', 'secondary', 'submit', false); ?>
            </form>
        </div>
    </details>
    <?php endif; ?>
</div>
