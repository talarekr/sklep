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
$autoSyncStatus = (array) get_option('gpswiss_ovoko_auto_sync_status', []);
$autoSyncStatusLabel = (string) ($autoSyncStatus['status'] ?? 'idle');
$autoSyncLastSuccessfulAt = (string) ($autoSyncStatus['last_successful_sync_at'] ?? 'not run yet');
$autoSyncLastAttemptedAt = (string) ($autoSyncStatus['last_attempted_sync_at'] ?? 'not run yet');
$autoSyncDeltaConfirmed = !empty($autoSyncStatus['delta_sync_confirmed']);
$autoSyncDeltaFilterUsed = (string) ($autoSyncStatus['delta_filter_used'] ?? '');
$autoSyncCurrentDeltaWindow = (array) ($autoSyncStatus['current_delta_window'] ?? []);
$autoSyncUpdatedAtStats = (array) ($autoSyncStatus['updated_at_stats'] ?? []);
$autoSyncReturnedRecordsCount = (int) ($autoSyncStatus['returned_records_count'] ?? 0);
$autoSyncLastCursor = (array) ($autoSyncStatus['last_cursor'] ?? []);
$autoSyncDateFromUsed = (string) ($autoSyncStatus['date_from_used'] ?? $autoSyncStatus['date_from'] ?? '');
$autoSyncPagesProcessedForDateWindow = (int) ($autoSyncStatus['pages_processed_for_date_window'] ?? 0);
$autoSyncSkippedAlreadySynced = (int) ($autoSyncStatus['skipped_already_synced'] ?? 0);
$autoSyncCreatedFromDelta = (int) ($autoSyncStatus['created_from_delta'] ?? 0);
$autoSyncUpdatedFromDelta = (int) ($autoSyncStatus['updated_from_delta'] ?? 0);
$autoSyncProcessed = (int) ($autoSyncStatus['processed'] ?? 0);
$autoSyncCreated = (int) ($autoSyncStatus['created'] ?? 0);
$autoSyncUpdated = (int) ($autoSyncStatus['updated'] ?? 0);
$autoSyncSkipped = (int) ($autoSyncStatus['skipped'] ?? 0);
$autoSyncWarnings = (array) ($autoSyncStatus['warnings'] ?? []);
$autoSyncErrors = (array) ($autoSyncStatus['errors'] ?? []);
$autoSyncCounts = (array) ($autoSyncStatus['counts'] ?? []);
$autoSyncDashboardCounters = (array) ($autoSyncStatus['dashboard_counters'] ?? []);
$buildMarker = defined('GPSWISS_OVOKO_BUILD_MARKER') ? (string) GPSWISS_OVOKO_BUILD_MARKER : 'dev';
$bidirectionalStatus = (array) ($data['bidirectional_sync_status'] ?? []);
$bidirectionalRecentRuns = array_values(array_filter((array) ($data['bidirectional_sync_recent_runs'] ?? []), 'is_array'));
$manualSinglePartStockSyncLogs = array_values(array_filter((array) ($data['manual_single_part_stock_sync_logs'] ?? []), 'is_array'));

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
$isPartStatusProbeResult = $noticeActionName === 'Read Ovoko/RRR part statuses';
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


    <div class="notice notice-info inline" style="margin:8px 0 14px;">
        <p><strong>Build marker:</strong> <code><?php echo esc_html($buildMarker); ?></code> | Legacy/maintenance tools are now collapsed under <strong>Advanced Settings</strong>.</p>
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
                <?php elseif ($isPartStatusProbeResult): ?>
                    <div class="postbox" style="padding:12px; margin:10px 0 0; max-width:960px;">
                        <h3 style="margin-top:0;">Read Ovoko/RRR part statuses</h3>
                        <ul style="margin:0 0 12px 18px;">
                            <li><strong>Endpoint used:</strong> <code><?php echo esc_html((string) ($noticePayload['endpoint_used'] ?? $noticePayload['endpoint'] ?? '')); ?></code></li>
                            <li><strong>HTTP status:</strong> <code><?php echo esc_html((string) ($noticePayload['http_status'] ?? '')); ?></code></li>
                            <li><strong>Status count:</strong> <code><?php echo esc_html((string) ($noticePayload['status_count'] ?? 0)); ?></code></li>
                            <li><strong>Checked at:</strong> <code><?php echo esc_html((string) ($noticePayload['checked_at'] ?? '')); ?></code></li>
                            <li><strong>No writes:</strong> <code><?php echo !empty($noticePayload['no_ovoko_write']) && !empty($noticePayload['no_woo_write']) ? 'yes' : 'unknown'; ?></code></li>
                            <li><strong>Status catalog scope:</strong> <code><?php echo esc_html((string) ($noticePayload['interpretation_summary']['status_catalog_scope'] ?? 'unknown')); ?></code></li>
                        </ul>
                        <?php $statusRows = array_values(array_filter((array) ($noticePayload['statuses'] ?? []), 'is_array')); ?>
                        <?php if ($statusRows !== []): ?>
                            <table class="widefat striped" style="max-width:900px;"><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Visibility/status signal fields</th></tr></thead><tbody>
                                <?php foreach ($statusRows as $statusRow): ?>
                                    <tr>
                                        <td><code><?php echo esc_html((string) ($statusRow['id'] ?? '')); ?></code></td>
                                        <td><code><?php echo esc_html((string) ($statusRow['code'] ?? '')); ?></code></td>
                                        <td><?php echo esc_html((string) ($statusRow['name'] ?? '')); ?></td>
                                        <td><code><?php echo esc_html(implode(', ', array_map('strval', (array) ($statusRow['visibility_signal_fields'] ?? [])))); ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody></table>
                        <?php endif; ?>
                        <p><strong>Interpretation:</strong> <code>/get/part_status</code> is diagnostic only. The latest probe showed operational stock/sales lifecycle states, not confirmed listing publication or draft visibility controls for <code>/crm/importPart</code>.</p>
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

    <h2>Ovoko ↔ Woo Automatic Sync</h2>


    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #dba617;">
        <h3>Woo → Ovoko CRM-only import settings</h3>
        <p>Configure technical placeholder values used only for CRM-only <code>/crm/importPart</code> previews/imports where Woo/Gmail does not yet know the final Ovoko/RRR vehicle mapping.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:grid;gap:10px;max-width:860px;">
            <?php wp_nonce_field('gpswiss_ovoko_save_crm_import_settings'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_save_crm_import_settings" />
            <label for="gpswiss_ovoko_default_crm_import_car_id"><strong>Default placeholder Ovoko/RRR car_id for CRM-only import</strong></label>
            <input id="gpswiss_ovoko_default_crm_import_car_id" type="number" min="1" step="1" name="gpswiss_ovoko_default_crm_import_car_id" value="<?php echo esc_attr((string) ($data['settings']['gpswiss_ovoko_default_crm_import_car_id'] ?? '')); ?>" class="regular-text" />
            <p class="description">Used only as a technical placeholder required by /crm/importPart for CRM-only imports without price. Staff must review and assign the correct vehicle in Ovoko before publishing.</p>

            <label for="gpswiss_ovoko_default_crm_import_car_note"><strong>Default placeholder car note</strong></label>
            <textarea id="gpswiss_ovoko_default_crm_import_car_note" name="gpswiss_ovoko_default_crm_import_car_note" rows="2" class="large-text"><?php echo esc_textarea((string) ($data['settings']['gpswiss_ovoko_default_crm_import_car_note'] ?? 'Placeholder car_id used for CRM-only import. Vehicle must be corrected manually in Ovoko.')); ?></textarea>
            <p class="description">Example: “Placeholder car_id used for CRM-only import. Vehicle must be corrected manually in Ovoko.”</p>
            <?php submit_button('Save CRM-only import settings', 'primary', 'submit', false); ?>
        </form>
    </div>


    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #00a32a;">
        <h3>Preview Woo → Ovoko CRM-only import payload</h3>
        <p><strong>Safe one-product dry-run only.</strong> This tool previews a CRM-only /crm/importPart payload from one WooCommerce product. It includes photo URLs for internal review, intentionally omits price fields for non-public initial import, and does not write to Ovoko, write to WooCommerce, enqueue work, or publish anything.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <?php wp_nonce_field('gpswiss_ovoko_preview_woo_to_ovoko_create_part'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_preview_woo_to_ovoko_create_part" />
            <label>product_id: <input type="number" min="1" step="1" name="product_id" value="" style="width:140px;" required /></label>
            <?php submit_button('Preview Woo → Ovoko CRM-only import payload', 'secondary', 'submit', false); ?>
        </form>
        <p><strong>Endpoint safety:</strong> create endpoint is documented as <code>/crm/importPart</code>. CRM-only preview includes photos and omits price because the documented e-shop rule requires price &gt; 0.00 plus a photo URL. The live action below is separate, manual, confirmation-gated, one-product-only, and still forbids price fields.</p>

        <details style="margin-top:14px;border:2px solid #d63638;padding:12px;background:#fff7f7;">
            <summary><strong>Create Ovoko CRM-only part from this Woo draft</strong> — danger zone, single product only</summary>
            <p><strong>Action label:</strong> Create Ovoko CRM-only part from Woo draft</p>
            <p><strong>No bulk. No cron. No product-save hook.</strong> This manual form immediately regenerates the CRM-only preview for exactly one Woo draft product and calls <code>/crm/importPart</code> only if all safety checks pass.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="gpswiss-ovoko-crm-only-live-form" style="display:grid;gap:10px;max-width:920px;">
                <?php wp_nonce_field('gpswiss_ovoko_create_crm_only_part_from_woo'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_create_crm_only_part_from_woo" />
                <label><strong>Product ID</strong> <input type="number" min="1" step="1" name="product_id" value="60886" required style="width:140px;" /></label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px;">
                    <div><strong>SKU/external_id:</strong> regenerated from Woo preview immediately before live request</div>
                    <div><strong>category_id:</strong> regenerated from mapped Woo category</div>
                    <div><strong>car_id and source:</strong> regenerated from product meta or configured placeholder</div>
                    <div><strong>endpoint:</strong> <code>/crm/importPart</code></div>
                    <div><strong>omitted price fields:</strong> <code>price</code>, <code>original_price</code>, <code>currency</code></div>
                    <div><strong>photo/photos count:</strong> validated from preview; <code>photo</code> and <code>photos[]</code> are required</div>
                </div>
                <p style="color:#b32d2e;"><strong>Placeholder car warning:</strong> if the preview uses configured placeholder <code>car_id</code>, it is allowed only for this CRM-only no-price import. Staff must correct vehicle mapping in Ovoko before publishing.</p>
                <p><strong>Idempotency:</strong> this action blocks existing <code>_ovoko_part_id</code>, <code>ovoko_part_id</code>, <code>part_id</code>, <code>source_part_id</code>, and <code>external_part_id</code>. external_id duplicate behavior is not fully documented for importPart.</p>
                <label><input type="checkbox" name="confirm_placeholder_car_id" value="1" required /> I understand this uses a placeholder car_id and staff must correct vehicle mapping in Ovoko before publishing.</label>
                <label><input type="checkbox" name="confirm_live_one_product" value="1" required /> I understand this will call /crm/importPart live for one product only.</label>
                <label><input type="checkbox" name="confirm_no_price_non_public" value="1" required /> I understand no price will be sent, so the part should not be available in e-shop according to documentation.</label>
                <?php submit_button('Create CRM-only Ovoko part now', 'delete gpswiss-ovoko-crm-only-live-submit', 'submit', false, ['disabled' => 'disabled']); ?>
            </form>
            <script>
            document.addEventListener('change', function (event) {
                var form = event.target.closest('.gpswiss-ovoko-crm-only-live-form');
                if (!form) { return; }
                var boxes = Array.prototype.slice.call(form.querySelectorAll('input[type="checkbox"][required]'));
                var button = form.querySelector('.gpswiss-ovoko-crm-only-live-submit');
                if (button) { button.disabled = !boxes.every(function (box) { return box.checked; }); }
            });
            </script>
        </details>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #72aee6;">
        <h3>Read Ovoko/RRR part statuses</h3>
        <p><strong>Read-only diagnostic.</strong> Calls only the read endpoint <code>/get/part_status</code> using standard RRR auth form fields. It does not call <code>/crm/importPart</code>, does not call any write endpoint, does not require a product ID, does not write WooCommerce data, and does not touch cron. Current findings treat this endpoint as operational stock/sales status, not publication visibility.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_read_part_statuses'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_read_part_statuses" />
            <?php submit_button('Read Ovoko/RRR part statuses', 'secondary', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #2271b1;">
        <h3>Ovoko ↔ Woo Automatic Sync</h3>
        <p><strong>Production orchestrator.</strong> The hook <code>gpswiss_ovoko_bidirectional_sync</code> runs <code>Ovoko → Woo date_from sync</code> first and then <code>Woo → Ovoko sale queue</code>. The panel switch below is the only Auto cron control.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0;">
            <div style="background:#f6f7f7;padding:10px;"><strong>Auto cron</strong><br><code><?php echo esc_html(!empty($bidirectionalStatus['sync_enabled']) ? 'TAK' : 'NIE'); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>status</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['status'] ?? 'idle')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>last_successful_sync_at</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['last_successful_sync_at'] ?? '') ?: 'not run yet')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>next_scheduled_sync_at</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['next_scheduled_sync_at'] ?? '') ?: 'not scheduled')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>stored_watermark_date</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['stored_watermark_date'] ?? '') ?: 'not set')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>computed_effective_date_from</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['computed_effective_date_from'] ?? $bidirectionalStatus['date_from_used'] ?? '') ?: 'not run yet')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>overlap_days_applied</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['overlap_days_applied'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>date_from_used</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['date_from_used'] ?? '') ?: 'not run yet')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>watermark update</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['watermark_update_reason'] ?? '') ?: 'not run yet')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>current page/cursor</strong><br><code><?php echo esc_html(wp_json_encode($bidirectionalStatus['current_cursor'] ?? ['page' => ($bidirectionalStatus['current_page'] ?? 1)])); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>lock status</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['lock_status'] ?? 'unlocked')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>processed_from_ovoko</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['processed_from_ovoko'] ?? $bidirectionalStatus['processed_total'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>created_from_ovoko</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['created_from_ovoko'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>updated_from_ovoko</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['updated_from_ovoko'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>skipped_from_ovoko</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['skipped_from_ovoko'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>skipped_missing_price</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['skipped_missing_price'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>skipped_already_synced</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['skipped_already_synced'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>pending_woo_to_ovoko_sales</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['pending_woo_to_ovoko_sales'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>successful_woo_to_ovoko_sales</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['successful_woo_to_ovoko_sales'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>failed_woo_to_ovoko_sales</strong><br><code><?php echo esc_html((string) ($bidirectionalStatus['failed_woo_to_ovoko_sales'] ?? 0)); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>last_error</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['last_error'] ?? '') ?: 'none')); ?></code></div>
            <div style="background:#f6f7f7;padding:10px;"><strong>last_warning</strong><br><code><?php echo esc_html((string) (($bidirectionalStatus['last_warning'] ?? '') ?: 'none')); ?></code></div>
        </div>
        <p><strong>Safety:</strong> after deploy <code>ovoko_bidirectional_sync_enabled</code> defaults to <code>false</code>, so Auto cron is <strong>NIE</strong> until you enable it here.</p>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_bidirectional_enable'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bidirectional_enable" />
                <?php submit_button('Enable auto cron', 'primary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_bidirectional_pause'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bidirectional_pause" />
                <?php submit_button('Disable auto cron', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_bidirectional_run_now'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bidirectional_run_now" />
                <?php submit_button('Run sync now', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_bidirectional_retry_failed_sales'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bidirectional_retry_failed_sales" />
                <?php submit_button('Retry failed Woo → Ovoko sales', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_probe_event_sources_for_part'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_probe_event_sources_for_part" />
                <input type="hidden" name="part_id" value="4303" />
                <input type="hidden" name="today" value="2026-05-30" />
                <input type="hidden" name="yesterday" value="2026-05-29" />
                <?php submit_button('Probe Ovoko event sources for part 4303', 'secondary', 'submit', false); ?>
            </form>
        </div>
    </div>


    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #d63638;">
        <h3>Manual Ovoko single-part stock diagnostics</h3>
        <p><strong>Emergency-safe tool for one Ovoko part only.</strong> It fetches <code>/get/part/{part_id}</code>, maps the existing Woo product by Ovoko meta, and can update only Woo stock/status. It does not change price, images, categories, title, description, the existing <code>date_from</code> create/update logic, or Woo → Ovoko sync.</p>
        <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:12px 0;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field('gpswiss_ovoko_manual_single_part_probe'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_manual_single_part_probe" />
                <label>part_id: <input type="number" min="1" name="part_id" value="4303" style="width:120px;" /></label>
                <?php submit_button('Probe part stock only', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <?php wp_nonce_field('gpswiss_ovoko_manual_single_part_stock_sync'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_manual_single_part_stock_sync" />
                <label>part_id: <input type="number" min="1" name="part_id" value="4303" style="width:120px;" /></label>
                <?php submit_button('Sync stock/status for part', 'primary', 'submit', false); ?>
            </form>
        </div>
        <p><strong>Suggested next step after this single-part fix:</strong> use the read-only event-source probe to identify the Ovoko/RRR endpoint that returns sold/status-changed parts by date. Any Woo product reconciliation must remain an emergency fallback only, not the main cron strategy.</p>
        <?php if ($manualSinglePartStockSyncLogs !== []): ?>
            <details style="margin-top:10px;">
                <summary>Recent Manual Ovoko single-part stock sync logs</summary>
                <div style="overflow-x:auto;margin-top:10px;">
                    <table class="widefat striped" style="min-width:980px;">
                        <thead><tr><th>Time</th><th>part_id</th><th>product_id</th><th>Ovoko raw</th><th>Mapped</th><th>Previous Woo</th><th>Changed</th><th>Errors</th></tr></thead>
                        <tbody>
                        <?php foreach ($manualSinglePartStockSyncLogs as $log): ?>
                            <tr>
                                <td><code><?php echo esc_html((string) ($log['checked_at'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($log['part_id'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($log['product_id'] ?? '')); ?></code></td>
                                <td>status=<code><?php echo esc_html((string) ($log['ovoko_status_raw'] ?? '')); ?></code><br>quantity=<code><?php echo esc_html((string) ($log['ovoko_quantity_raw'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($log['mapped_stock_status'] ?? '')); ?></code> / <code><?php echo esc_html((string) ($log['mapped_stock_quantity'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($log['previous_woo_stock_status'] ?? '')); ?></code> / <code><?php echo esc_html((string) ($log['previous_woo_stock_quantity'] ?? '')); ?></code></td>
                                <td><code><?php echo !empty($log['changed']) ? 'true' : 'false'; ?></code></td>
                                <td><code><?php echo esc_html(wp_json_encode((array) ($log['errors'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
            <h3 style="margin:0;">Recent cron runs</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_bidirectional_clear_recent_runs'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_bidirectional_clear_recent_runs" />
                <?php submit_button('Clear cron logs', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php if ($bidirectionalRecentRuns === []): ?>
            <p>No cron runs logged yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;margin-top:12px;">
                <table class="widefat striped" style="min-width:1180px;">
                    <thead>
                    <tr>
                        <th>Time</th>
                        <th>Trigger</th>
                        <th>Status</th>
                        <th>Date from</th>
                        <th>Watermark / overlap</th>
                        <th>Page</th>
                        <th>Ovoko processed / created / updated / skipped</th>
                        <th>Ovoko orders → Woo stock</th>
                        <th>Woo sales processed / success / failed</th>
                        <th>Duration</th>
                        <th>Error / warning</th>
                        <th>Technical</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bidirectionalRecentRuns as $run): ?>
                        <?php
                        $ovokoRun = (array) ($run['ovoko_to_woo'] ?? []);
                        $wooRun = (array) ($run['woo_to_ovoko'] ?? []);
                        $ordersStockRun = (array) ($run['ovoko_orders_to_woo_stock'] ?? []);
                        $skippedTotal = (int) ($ovokoRun['skipped_missing_price'] ?? 0) + (int) ($ovokoRun['skipped_already_synced'] ?? 0);
                        $pageLabel = ($run['page'] ?? null) === null ? '—' : (string) $run['page'];
                        if (($run['next_page'] ?? null) !== null) {
                            $pageLabel .= ' → ' . (string) $run['next_page'];
                        }
                        if (($run['has_more_pages'] ?? null) !== null) {
                            $pageLabel .= !empty($run['has_more_pages']) ? ' (more)' : ' (done)';
                        }
                        $errorWarning = trim((string) (($run['last_error'] ?? '') ?: ($run['last_warning'] ?? '')));
                        ?>
                        <tr>
                            <td><code><?php echo esc_html((string) ($run['started_at'] ?? '')); ?></code><br><small>finished: <?php echo esc_html((string) ($run['finished_at'] ?? '')); ?></small></td>
                            <td><?php echo esc_html((string) ($run['trigger'] ?? '')); ?></td>
                            <td><code><?php echo esc_html((string) ($run['status'] ?? '')); ?></code></td>
                            <td><code><?php echo esc_html((string) (($run['date_from_used'] ?? '') ?: '—')); ?></code></td>
                            <td><small>prev: <code><?php echo esc_html((string) (($run['previous_watermark_date'] ?? '') ?: '—')); ?></code><br>before: <code><?php echo esc_html((string) (($run['stored_watermark_date_before'] ?? '') ?: '—')); ?></code><br>effective: <code><?php echo esc_html((string) (($run['computed_effective_date_from'] ?? '') ?: '—')); ?></code><br>overlap: <code><?php echo esc_html((string) ($run['overlap_days_applied'] ?? 0)); ?></code><br>advance: <code><?php echo esc_html(isset($run['should_advance_watermark']) ? (!empty($run['should_advance_watermark']) ? 'yes' : 'no') : '—'); ?></code><br>updated_to: <code><?php echo esc_html((string) (($run['watermark_updated_to'] ?? '') ?: '—')); ?></code><br>reason: <code><?php echo esc_html((string) (($run['watermark_update_reason'] ?? '') ?: '—')); ?></code></small></td>
                            <td><?php echo esc_html($pageLabel); ?></td>
                            <td><?php echo esc_html((string) ($ovokoRun['processed'] ?? 0)); ?> / <?php echo esc_html((string) ($ovokoRun['created'] ?? 0)); ?> / <?php echo esc_html((string) ($ovokoRun['updated'] ?? 0)); ?> / <?php echo esc_html((string) $skippedTotal); ?><br><small>missing price: <?php echo esc_html((string) ($ovokoRun['skipped_missing_price'] ?? 0)); ?>, already synced: <?php echo esc_html((string) ($ovokoRun['skipped_already_synced'] ?? 0)); ?>, errors: <?php echo esc_html((string) ($ovokoRun['errors_count'] ?? 0)); ?>, warnings: <?php echo esc_html((string) ($ovokoRun['warnings_count'] ?? 0)); ?></small></td>
                            <td><small>endpoint: <code><?php echo esc_html((string) (($ordersStockRun['endpoint'] ?? '') ?: '—')); ?></code><br>window: <code><?php echo esc_html((string) (($ordersStockRun['from_date'] ?? '') ?: '—')); ?></code> → <code><?php echo esc_html((string) (($ordersStockRun['to_date'] ?? '') ?: '—')); ?></code><br>orders/items/parts: <?php echo esc_html((string) ($ordersStockRun['orders_count'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['item_rows_count'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['part_ids_found'] ?? 0)); ?><br>matched/changed/skipped/missing/failed: <?php echo esc_html((string) ($ordersStockRun['matched_products'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['changed'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['skipped_no_change'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['missing_product'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['failed'] ?? 0)); ?><br>errors/warnings: <?php echo esc_html((string) ($ordersStockRun['errors_count'] ?? 0)); ?> / <?php echo esc_html((string) ($ordersStockRun['warnings_count'] ?? 0)); ?><br>changed products: <code><?php echo esc_html(implode(',', array_map('strval', (array) ($ordersStockRun['changed_product_ids'] ?? []))) ?: '—'); ?></code><br>missing parts: <code><?php echo esc_html(implode(',', array_map('strval', (array) ($ordersStockRun['missing_part_ids'] ?? []))) ?: '—'); ?></code></small></td>
                            <td><?php echo esc_html((string) ($wooRun['processed'] ?? 0)); ?> / <?php echo esc_html((string) ($wooRun['success'] ?? 0)); ?> / <?php echo esc_html((string) ($wooRun['failed'] ?? 0)); ?><br><small>skipped: <?php echo esc_html((string) ($wooRun['skipped'] ?? 0)); ?></small></td>
                            <td><?php echo esc_html((string) ($run['duration_seconds'] ?? 0)); ?>s</td>
                            <td><?php echo esc_html($errorWarning !== '' ? $errorWarning : '—'); ?></td>
                            <td>
                                <details>
                                    <summary>Show technical JSON</summary>
                                    <textarea readonly rows="8" style="width:360px;max-width:100%;font-family:monospace;"><?php echo esc_textarea(wp_json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></textarea>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="postbox" style="padding:16px; margin:18px 0 14px; border-left:4px solid #2271b1;">
        <h3>Ovoko listing image single-product probe</h3>
        <p><strong>Safe manual action for one product only.</strong> Use this to test a specific Ovoko-created Woo product (for example <code>60634</code>) without mass reimporting photos, changing prices, changing categories, or touching Woo → Ovoko sync.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin:0 12px 12px 0;">
            <?php wp_nonce_field('gpswiss_ovoko_preview_listing_image_status'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_preview_listing_image_status" />
            <label>product_id: <input type="number" min="1" name="product_id" placeholder="60634" style="width:110px;" /></label>
            <?php submit_button('Preview listing image status', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin:0;">
            <?php wp_nonce_field('gpswiss_ovoko_generate_listing_image'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_generate_listing_image" />
            <label>product_id: <input type="number" min="1" name="product_id" placeholder="60634" style="width:110px;" /></label>
            <?php submit_button('Regenerate listing image for product', 'primary', 'submit', false); ?>
        </form>
    </div>

    <details style="margin-top:18px;" class="gpswiss-ovoko-advanced-tools">
        <summary><strong>Advanced Settings</strong></summary>
        <div class="notice notice-warning inline" style="margin:12px 0;">
            <p><strong>Advanced tools — use only for maintenance/debug. These actions can modify categories/products.</strong></p>
        </div>
        <p>Legacy category rebuild/delete tools, CSV helpers, debug probes, old dry-runs and maintenance actions were moved here to keep the daily sync dashboard clean. Existing confirmations and safeguards are unchanged.</p>


    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #dba617;">
        <h3>Advanced/dev: date_from Ovoko → Woo manual test</h3>
        <p><strong>Manual diagnostics only.</strong> Uses exactly <code>/v2/get/parts?limit={limit}&amp;page={page}&amp;date_from=YYYY-MM-DD</code>; it does not use <code>updated_from</code>, <code>updated_after</code>, <code>from</code>, timestamps, ISO datetimes, or full scans without <code>date_from</code>.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field('gpswiss_ovoko_auto_sync_endpoint_analysis'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_auto_sync_endpoint_analysis" />
            <?php submit_button('Analyze Ovoko/RRR endpoints for safe cron', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php wp_nonce_field('gpswiss_ovoko_dry_run_auto_sync'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_dry_run_auto_sync" />
            <label>date_from: <input type="date" name="date_from" placeholder="YYYY-MM-DD" /></label>
            <label>page: <input type="number" min="1" name="page" value="1" style="width:80px;" /></label>
            <label>batch_size: <input type="number" min="1" max="25" name="batch_size" value="5" style="width:80px;" /></label>
            <?php submit_button('Dry-run date_from Ovoko → Woo delta', 'secondary', 'submit', false); ?>
        </form>
        <hr style="margin:16px 0;" />
        <h4>Manual live date_from Ovoko → Woo sync</h4>
        <p><strong>Live Woo write, manual only.</strong> Creates new Woo products only when the product is missing and a valid PLN price is present in <code>internal_notes</code>. Existing products may update stock/status, description and details only; price, images and categories stay untouched. Automatic cron is controlled only by the main Auto cron switch.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:0;">
            <?php wp_nonce_field('gpswiss_ovoko_manual_live_date_from_sync'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_manual_live_date_from_sync" />
            <label>date_from: <input type="date" name="date_from" required placeholder="YYYY-MM-DD" /></label>
            <label>page: <input type="number" min="1" name="page" value="1" required style="width:80px;" /></label>
            <label>batch_size: <input type="number" min="1" max="5" name="batch_size" value="1" required style="width:80px;" /></label>
            <label style="display:block; margin-top:8px;">confirmation: <input type="text" name="confirmation" required placeholder="RUN OVOKO DATE_FROM LIVE SYNC" style="width:360px;" /></label>
            <?php submit_button('Run manual live date_from Ovoko → Woo sync', 'delete', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #dba617;">
        <h3>Advanced/dev: Woo → Ovoko sale probes</h3>
        <p>Sale probes use the confirmed dedicated endpoint <code>POST /crm/changePartStatus</code> with <code>status=2</code>. The automatic worker is controlled only by the main Auto cron switch.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
            <?php wp_nonce_field('gpswiss_ovoko_analyze_sale_stock_endpoint'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_analyze_sale_stock_endpoint" />
            <?php submit_button('Analyze Woo → Ovoko sale/stock endpoint', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:14px;">
            <?php wp_nonce_field('gpswiss_ovoko_dry_run_sale_sync'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_dry_run_sale_sync" />
            <label>Woo order_id: <input type="number" min="1" name="order_id" placeholder="Order ID" /></label>
            <?php submit_button('Dry-run Woo order → Ovoko sale sync', 'secondary', 'submit', false); ?>
        </form>
        <hr style="margin:16px 0;" />
        <h4>Single-order live probe: mark Ovoko part sold from Woo order</h4>
        <p><strong>Live write for one order item only.</strong> Requires exactly one Woo order item and confirmation phrase <code>MARK OVOKO PART SOLD</code>. Does not enable retries, worker, cron, or checkout live hook.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:0;">
            <?php wp_nonce_field('gpswiss_ovoko_single_order_mark_sold_live_probe'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_single_order_mark_sold_live_probe" />
            <label>Woo order_id: <input type="number" min="1" name="order_id" placeholder="Order ID" /></label>
            <label style="display:block; margin-top:8px;">confirmation: <input type="text" name="confirmation" placeholder="MARK OVOKO PART SOLD" style="width:320px;" /></label>
            <?php submit_button('Run single-order live probe: mark Ovoko part sold', 'delete', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #dba617;">
        <h3>Advanced/dev: internal_notes price backfill diagnostics</h3>
        <p><strong>Not part of the main sync flow.</strong> Kept only as an advanced diagnostic after the decision not to mass-update Ovoko <code>internal_notes</code>. Dry-run remains read-only; the single-part probe is live and should be used only for development verification.</p>
        <ul style="list-style:disc;margin-left:22px;">
            <li>Existing notes are preserved; if no supported price marker exists, the proposed line is appended at the end.</li>
            <li>If <code>internal_notes</code> already contains <code>woo_price=...</code> or a legacy plain numeric price, dry-run skips automatic overwrite and reports conflicts when it differs from Woo.</li>
            <li>Live batch backfill is intentionally not implemented here until a controlled <code>/crm/updatePart</code> probe confirms omitted fields are never overwritten.</li>
        </ul>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin:0 12px 12px 0;">
            <?php wp_nonce_field('gpswiss_ovoko_analyze_internal_notes_backfill_api'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_analyze_internal_notes_backfill_api" />
            <?php submit_button('Analyze internal_notes update API', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:0;">
            <?php wp_nonce_field('gpswiss_ovoko_dry_run_internal_notes_price_backfill'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_dry_run_internal_notes_price_backfill" />
            <label>after_product_id: <input type="number" min="0" name="after_product_id" value="0" style="width:100px;" /></label>
            <label>max_products: <input type="number" min="1" max="500" name="max_products" value="100" style="width:90px;" /></label>
            <?php submit_button('Dry-run backfill Ovoko internal_notes prices from Woo', 'secondary', 'submit', false); ?>
        </form>

        <hr style="margin:16px 0;" />
        <h4>Single-part live probe: update Ovoko internal_notes only</h4>
        <p><strong>Live write for one part only.</strong> The probe reads Ovoko before/after, sends only auth + <code>part_id</code> + <code>internal_notes</code> to <code>/crm/updatePart</code>, appends one <code>woo_price=XXX.XX PLN</code> line, and reports a critical warning if any monitored non-notes field changes.</p>
        <ul style="list-style:disc;margin-left:22px;">
            <li>Fill exactly one identifier: Woo <code>product_id</code> or Ovoko <code>ovoko_id</code>.</li>
            <li>Required confirmation phrase: <code>UPDATE OVOKO INTERNAL NOTES ONLY</code>.</li>
            <li>Does not write Woo, stock, Ovoko price, category, photos or public notes/description.</li>
        </ul>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:0;">
            <?php wp_nonce_field('gpswiss_ovoko_single_part_internal_notes_live_probe'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_single_part_internal_notes_live_probe" />
            <label>product_id: <input type="number" min="1" name="product_id" placeholder="2080" style="width:100px;" /></label>
            <label>ovoko_id: <input type="number" min="1" name="ovoko_id" placeholder="10776" style="width:100px;" /></label>
            <label style="display:block; margin-top:8px;">confirmation: <input type="text" name="confirmation" placeholder="UPDATE OVOKO INTERNAL NOTES ONLY" style="width:360px;" /></label>
            <?php submit_button('Run single-part live probe', 'delete', 'submit', false); ?>
        </form>
    </div>

        <div class="postbox" style="padding:16px; margin-bottom:14px;">
            <h3>Legacy quick diagnostics</h3>
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

        <h2>Legacy / maintenance actions</h2>

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
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('gpswiss_ovoko_probe_vehicle_data_for_car_id'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_probe_vehicle_data_for_car_id" />
            <label for="probe_vehicle_car_id">car_id:</label>
            <input id="probe_vehicle_car_id" type="number" min="1" name="car_id" value="458" />
            <?php submit_button('Probe Ovoko vehicle data for car_id', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('gpswiss_ovoko_probe_dictionary_value'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_probe_dictionary_value" />
            <label for="probe_dictionary_type">dictionary_type:</label>
            <select id="probe_dictionary_type" name="dictionary_type">
                <option value="car_model">car_model</option>
                <option value="car_model_category">car_model_category</option>
                <option value="car_fuel">car_fuel</option>
                <option value="car_gearbox_type">car_gearbox_type</option>
                <option value="car_wheel_drive">car_wheel_drive</option>
                <option value="car_color">car_color</option>
                <option value="car_body_type">car_body_type</option>
            </select>
            <label for="probe_dictionary_id">id:</label>
            <input id="probe_dictionary_id" type="text" name="id" value="1" style="width:80px;" />
            <?php submit_button('Probe Ovoko dictionary value', 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:10px;">
            <?php wp_nonce_field('gpswiss_ovoko_probe_car_brands_models_raw'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_probe_car_brands_models_raw" />
            <?php submit_button('Probe Ovoko car brands/models raw', 'secondary', 'submit', false); ?>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update single existing product from CSV mapping</h3>
        <p>Updates only Ovoko/RRR detail attributes and meta. Does not change price, stock, images, title or publication status.</p>
        <form id="gpswiss_ovoko_description_update_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
        <form id="gpswiss_ovoko_description_update_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
        <form id="gpswiss_ovoko_description_update_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
            <label for="desc_sleep_ms">sleep_ms:</label>
            <input id="desc_sleep_ms" type="number" min="100" step="100" name="sleep_ms" value="1200" />
            <label for="desc_max_runtime">max_runtime (s):</label>
            <input id="desc_max_runtime" type="number" min="0" step="1" name="max_runtime" value="0" />
            <br><br>
            <label><input type="checkbox" name="dry_run" value="1" checked="checked" /> dry_run (default true)</label>
            <label><input type="checkbox" name="save_to_meta_only" value="1" checked="checked" /> save_to_meta_only (default true)</label>
            <label><input type="checkbox" name="update_only_empty_description" value="1" checked="checked" /> update_only_empty_description (default true)</label>
            <label><input type="checkbox" name="replace_existing_description" value="1" /> replace_existing_description (default false)</label>
            <label><input type="checkbox" name="prepend_to_existing_description" value="1" /> prepend_to_existing_description (default false)</label>
            <label><input type="checkbox" name="stop_on_error" value="1" /> stop_on_error</label>
            <br><br>
            <button class="button button-secondary" type="submit" name="submit_action" value="dry_run">Dry run description update</button>
            <button class="button button-primary" type="submit" name="submit_action" value="apply">Apply description update</button>
            <button class="button button-primary" type="button" id="gpswiss_desc_autorun_start">Start auto-run descriptions</button>
            <button class="button" type="button" id="gpswiss_desc_autorun_stop">Stop auto-run descriptions</button>
        </form>
        <div id="gpswiss_desc_autorun_status" style="margin-top:10px;padding:10px;background:#f6f7f7;">
            <strong>Status:</strong> <span data-k="status">stopped</span> |
            Started at: <span data-k="started_at">-</span> |
            Duration: <span data-k="duration_seconds">0</span>s |
            Start after_product_id: <span data-k="start_after_product_id">0</span> |
            Request after_product_id: <span data-k="request_after_product_id">0</span> |
            Response next_after_product_id: <span data-k="response_next_after_product_id">0</span><br>
            Current after_product_id: <span data-k="current_after_product_id">0</span> |
            Last next_after_product_id: <span data-k="last_next_after_product_id">0</span><br>
            Total scanned: <span data-k="total_scanned">0</span> |
            Total with ovoko_id: <span data-k="total_with_ovoko_id">0</span> |
            Total updated: <span data-k="total_updated">0</span> |
            Total old_allegro_description_removed: <span data-k="total_old_allegro_removed">0</span> |
            Total missing_ovoko_id: <span data-k="total_missing_ovoko_id">0</span> |
            Total ovoko_listing_text_missing: <span data-k="total_listing_missing">0</span> |
            Total errors: <span data-k="total_errors">0</span><br>
            Last safe next_after_product_id: <span data-k="last_safe_next_after_product_id">0</span>
            <br>desc_after_product_id_element_found: <span data-k="desc_after_product_id_element_found">false</span> |
            desc_after_product_id_raw_value: <span data-k="desc_after_product_id_raw_value">""</span> |
            parsed_start_after_product_id: <span data-k="parsed_start_after_product_id">0</span><br>
            admin_autorun_js_url: <span data-k="admin_autorun_js_url"><?php echo esc_html((string) ($autoRunExpectedAssetUrl ?? '')); ?></span> |
            admin_autorun_js_version: <span data-k="admin_autorun_js_version"><?php echo esc_html((string) ($autoRunAssetVersion ?? 'n/a')); ?></span> |
            descriptionAction: <span data-k="descriptionAction">gpswiss_ovoko_update_description_from_listing_text</span> |
            descriptionNonce present: <span data-k="descriptionNonce_present">false</span> |
            js_asset_version: <span data-k="js_asset_version"><?php echo esc_html((string) ($autoRunAssetVersion ?? 'n/a')); ?></span>
        </div>
        <pre id="gpswiss_desc_autorun_logs" style="max-height:220px;overflow:auto;background:#111;color:#e6e6e6;padding:10px;"></pre>
    </div>


    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update Woo categories from Ovoko</h3>
        <p>Source of truth: Ovoko <code>category_title_path</code> by product <code>ovoko_id/_ovoko_part_id</code>. Replaces only product category assignments.</p>
        <form id="gpswiss_ovoko_categories_update_form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_update_categories_from_ovoko'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_update_categories_from_ovoko" />
            <label>product_id (optional): <input type="number" min="0" name="product_id" value="0" /></label>
            <label>after_product_id: <input id="category_after_product_id" type="number" min="0" name="after_product_id" value="0" /></label>
            <label>limit: <input id="category_limit" type="number" min="1" max="200" name="limit" value="10" /></label>
            <label>batch_size: <input id="category_batch_size" type="number" min="1" max="200" name="batch_size" value="10" /></label>
            <label>sleep_ms: <input id="category_sleep_ms" type="number" min="100" step="100" name="sleep_ms" value="1200" /></label>
            <label>max_runtime (s): <input id="category_max_runtime" type="number" min="0" step="1" name="max_runtime" value="0" /></label>
            <br><br>
            <label><input type="checkbox" name="dry_run" value="1" checked="checked" /> dry_run (default true)</label>
            <label><input type="checkbox" name="create_missing_categories" value="1" checked="checked" /> create_missing_categories (default true)</label>
            <label><input type="checkbox" name="replace_existing_categories" value="1" checked="checked" /> replace_existing_categories (default true)</label>
            <label><input type="checkbox" name="stop_on_error" value="1" /> stop_on_error</label>
            <br><br>
            <p style="color:#b32d2e;"><strong>Apply requires exact confirmation:</strong> <code>REBUILD WOO CATEGORIES FROM OVOKO</code></p>
            <input type="text" name="confirmation" class="regular-text" placeholder="REBUILD WOO CATEGORIES FROM OVOKO" />
            <br><br>
            <button class="button button-secondary" type="submit" name="submit_action" value="dry_run">Dry run categories update</button>
            <button class="button button-primary" type="submit" name="submit_action" value="apply">Apply categories update</button>
            <button class="button button-primary" type="button" id="gpswiss_cat_autorun_start">Start auto-run categories</button>
            <button class="button" type="button" id="gpswiss_cat_autorun_stop">Stop auto-run categories</button>
        </form>
        <div id="gpswiss_cat_autorun_status" style="margin-top:10px;padding:10px;background:#f6f7f7;">
            <strong>Status:</strong> <span data-k="status">stopped</span> |
            started_at: <span data-k="started_at">-</span> |
            duration: <span data-k="duration_seconds">0</span>s |
            start_after_product_id: <span data-k="start_after_product_id">0</span><br>
            request_after_product_id: <span data-k="request_after_product_id">0</span> |
            response_next_after_product_id: <span data-k="response_next_after_product_id">0</span> |
            current_after_product_id: <span data-k="current_after_product_id">0</span> |
            last_safe_next_after_product_id: <span data-k="last_safe_next_after_product_id">0</span><br>
            total_scanned: <span data-k="total_scanned">0</span> |
            with_ovoko_id: <span data-k="with_ovoko_id">0</span> |
            missing_ovoko_id: <span data-k="missing_ovoko_id">0</span> |
            ovoko_category_found: <span data-k="ovoko_category_found">0</span> |
            ovoko_category_missing: <span data-k="ovoko_category_missing">0</span><br>
            categories_created: <span data-k="categories_created">0</span> |
            categories_existing: <span data-k="categories_existing">0</span> |
            products_categories_updated: <span data-k="products_categories_updated">0</span> |
            products_categories_verified: <span data-k="products_categories_verified">0</span> |
            products_skipped: <span data-k="products_skipped">0</span> |
            errors: <span data-k="errors">0</span><br>
            category_after_product_id_element_found: <span data-k="category_after_product_id_element_found">false</span> |
            category_after_product_id_raw_value: <span data-k="category_after_product_id_raw_value">""</span> |
            parsed_start_after_product_id: <span data-k="parsed_start_after_product_id">0</span><br>
            js_asset_version: <span data-k="js_asset_version"><?php echo esc_html((string) ($autoRunAssetVersion ?? 'n/a')); ?></span> |
            admin_autorun_js_url: <span data-k="admin_autorun_js_url"><?php echo esc_html((string) ($autoRunExpectedAssetUrl ?? '')); ?></span> |
            admin_autorun_js_version: <span data-k="admin_autorun_js_version"><?php echo esc_html((string) ($autoRunAssetVersion ?? 'n/a')); ?></span><br>
            categoryAction: <span data-k="categoryAction">gpswiss_ovoko_update_categories_from_ovoko</span> |
            categoryNonce present: <span data-k="categoryNonce_present">false</span>
        </div>
        <pre id="gpswiss_cat_autorun_logs" style="max-height:220px;overflow:auto;background:#111;color:#e6e6e6;padding:10px;"></pre>
    </div>





    <div class="postbox" style="padding:16px; margin-bottom:14px; border-left:4px solid #b32d2e;">
        <h3>Controlled Woo <code>product_cat</code> rebuild from Ovoko — tools only</h3>
        <p><strong>Scope:</strong> only WooCommerce <code>product_cat</code> terms and product category assignments. Products, images, descriptions, prices, stock, eBay, Allegro, and Ovoko data are not changed by dry-runs.</p>
        <p><strong>New rebuild mode:</strong> uses fresh Ovoko part data by <code>ovoko_id/_ovoko_part_id</code> and full category path resolved from <code>/get/categories/tree</code>. It does not use the old CSV mapping, does not add the old “Motoryzacja” root, does not shorten paths, does not append old categories, and does not keep previous assignments.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?php wp_nonce_field('gpswiss_ovoko_export_product_category_assignments'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_export_product_category_assignments" />
                <label>Batch size <input type="number" name="batch_size" value="100" min="1" max="200" style="width:80px;" /></label>
                <label>After product ID <input type="number" name="after_product_id" value="0" min="0" style="width:110px;" /></label>
                <label>Max rows <input type="number" name="max_rows" value="0" min="0" style="width:90px;" /></label>
                <?php submit_button('Export current product category assignments CSV', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?php wp_nonce_field('gpswiss_ovoko_dry_run_delete_all_product_categories'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_dry_run_delete_all_product_categories" />
                <input type="hidden" name="ultra_light_dry_run" value="1" />
                <span class="description">Ultra-light dry-run: counters only + samples limited to 20.</span>
                <?php submit_button('Ultra-light dry-run delete all Woo product categories', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_post_rebuild_category_audit'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_post_rebuild_category_audit" />
                <?php submit_button('Post-rebuild category audit', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_pause_category_rebuild'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_pause_category_rebuild" />
                <?php submit_button('Pause rebuild', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_resume_category_rebuild'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_resume_category_rebuild" />
                <?php submit_button('Resume rebuild', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="padding:12px;background:#f6f7f7;margin-bottom:12px;">
            <?php wp_nonce_field('gpswiss_ovoko_rebuild_categories_from_scratch'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_rebuild_categories_from_scratch" />
            <h4>Dry-run / batch rebuild from Ovoko</h4>
            <label>product_id sample (optional): <input type="number" min="0" name="product_id" value="0" /></label>
            <label>after_product_id: <input type="number" min="0" name="after_product_id" value="0" /></label>
            <label>batch_size: <input type="number" min="1" max="100" name="batch_size" value="10" /></label>
            <label><input type="checkbox" name="stop_on_error" value="1" /> stop_on_error</label>
            <label><input id="gpswiss_rebuild_menu_cache_after_batch" type="checkbox" name="rebuild_menu_cache_when_done" value="1" checked="checked" /> Rebuild frontend category menu cache after each batch</label>
            <p class="description">Real batches rebuild <code>gp_product_cat_display_data_v2</code> once after the batch only when product/category data changed. Dry-runs always skip cache rebuild.</p>
            <p><button class="button button-secondary" type="submit" name="submit_action" value="dry_run" data-menu-cache-default="0">Dry-run rebuild Woo categories from Ovoko</button></p>
            <p style="margin-top:12px;color:#b32d2e;"><strong>Real rebuild requires exact confirmation:</strong> <code>REBUILD WOO CATEGORIES FROM OVOKO</code></p>
            <input type="text" name="confirmation" class="regular-text" placeholder="REBUILD WOO CATEGORIES FROM OVOKO" />
            <button class="button button-primary" type="submit" name="submit_action" value="apply" data-menu-cache-default="1" style="background:#b32d2e;border-color:#8f2223;color:#fff;">Rebuild Woo categories from Ovoko from scratch</button>
        </form>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var checkbox = document.getElementById('gpswiss_rebuild_menu_cache_after_batch');
            if (!checkbox) { return; }
            document.querySelectorAll('[data-menu-cache-default]').forEach(function (button) {
                button.addEventListener('click', function () {
                    checkbox.checked = button.getAttribute('data-menu-cache-default') === '1';
                });
            });
        });
        </script>
        <?php
        $categoryRebuildAutorunStatus = (array) ($data['category_rebuild_autorun_status'] ?? []);
        $categoryRebuildAutorunDebug = (array) ($data['category_rebuild_autorun_debug'] ?? []);
        $categoryRebuildAutorunLastAjax = (array) ($categoryRebuildAutorunDebug['last_ajax_response'] ?? []);
        $categoryRebuildAutorunStatusSummary = (array) ($categoryRebuildAutorunDebug['summary'] ?? []);
        $categoryRebuildAutorunInvalid = !empty($categoryRebuildAutorunStatus['autorun_status_invalid']) || !empty($categoryRebuildAutorunDebug['invalid']);
        ?>
        <div id="gpswiss_category_rebuild_autorun_box" style="display:block;padding:12px;background:#eef6ff;border:1px solid #72aee6;margin-bottom:12px;">
            <h4>Autorun: Rebuild Woo categories from Ovoko from scratch</h4>
            <p><strong>Safety:</strong> autorun changes only Woo <code>product_cat</code> assignments and primary category. It does not edit descriptions, prices, stock, images, eBay, Allegro, or Ovoko data.</p>
            <?php if ($categoryRebuildAutorunInvalid): ?>
                <div class="notice notice-warning inline" style="margin:8px 0;padding:8px 12px;"><strong>autorun status invalid, reset recommended</strong><br><?php echo esc_html((string) ($categoryRebuildAutorunStatus['autorun_status_invalid_reason'] ?? $categoryRebuildAutorunDebug['invalid_reason'] ?? 'unknown invalid status')); ?></div>
            <?php endif; ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px;">
                <label>start_after_product_id <input id="gpswiss_rebuild_autorun_start_after" type="number" min="0" value="<?php echo esc_attr((string) ($categoryRebuildAutorunStatus['last_safe_next_after_product_id'] ?? 0)); ?>" style="width:110px;" /></label>
                <label>batch_size <input id="gpswiss_rebuild_autorun_batch_size" type="number" min="1" max="100" value="5" style="width:80px;" /></label>
                <label>Rebuild frontend category menu cache every N batches <input id="gpswiss_rebuild_autorun_cache_every" type="number" min="1" max="1000" value="5" style="width:80px;" /></label>
                <label><input id="gpswiss_rebuild_autorun_stop_on_error" type="checkbox" checked="checked" /> stop_on_error</label>
            </div>
            <p><strong>Real autorun requires exact confirmation:</strong> <code>REBUILD WOO CATEGORIES FROM OVOKO</code></p>
            <input id="gpswiss_rebuild_autorun_confirmation" type="text" class="regular-text" placeholder="REBUILD WOO CATEGORIES FROM OVOKO" />
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <button class="button button-primary" type="button" id="gpswiss_rebuild_autorun_start" style="background:#b32d2e;border-color:#8f2223;color:#fff;">Start autorun rebuild categories from Ovoko</button>
                <button class="button" type="button" id="gpswiss_rebuild_autorun_pause">Pause autorun</button>
                <button class="button" type="button" id="gpswiss_rebuild_autorun_resume">Resume autorun</button>
                <button class="button" type="button" id="gpswiss_rebuild_autorun_stop">Stop autorun</button>
                <button class="button" type="button" id="gpswiss_rebuild_autorun_reset">Reset autorun status</button>
            </div>
            <div id="gpswiss_rebuild_autorun_status" style="margin-top:10px;padding:10px;background:#fff;">
                status: <span data-k="status"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['status'] ?? 'idle')); ?></span> |
                current_after_product_id: <span data-k="current_after_product_id"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['current_after_product_id'] ?? 0)); ?></span> |
                next_after_product_id: <span data-k="next_after_product_id"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['next_after_product_id'] ?? 0)); ?></span> |
                last_safe_next_after_product_id: <span data-k="last_safe_next_after_product_id"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['last_safe_next_after_product_id'] ?? 0)); ?></span><br>
                processed_total: <span data-k="processed_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['processed_total'] ?? 0)); ?></span> |
                fixed_total: <span data-k="fixed_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['fixed_total'] ?? 0)); ?></span> |
                skipped_total: <span data-k="skipped_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_total'] ?? 0)); ?></span> |
                errors_total: <span data-k="errors_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['errors_total'] ?? 0)); ?></span><br>
                missing_ovoko_id_total: <span data-k="missing_ovoko_id_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['missing_ovoko_id_total'] ?? 0)); ?></span> |
                missing_category_id_total: <span data-k="missing_category_id_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['missing_category_id_total'] ?? 0)); ?></span> |
                missing_category_path_total: <span data-k="missing_category_path_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['missing_category_path_total'] ?? 0)); ?></span> |
                api_errors_total: <span data-k="api_errors_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['api_errors_total'] ?? 0)); ?></span><br>
                skipped_missing_ovoko_id_total: <span data-k="skipped_missing_ovoko_id_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_missing_ovoko_id_total'] ?? 0)); ?></span> |
                skipped_missing_category_id_total: <span data-k="skipped_missing_category_id_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_missing_category_id_total'] ?? 0)); ?></span> |
                skipped_missing_category_path_total: <span data-k="skipped_missing_category_path_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_missing_category_path_total'] ?? 0)); ?></span> |
                skipped_ovoko_fetch_failed_total: <span data-k="skipped_ovoko_fetch_failed_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_ovoko_fetch_failed_total'] ?? 0)); ?></span> |
                skipped_category_resolution_failed_total: <span data-k="skipped_category_resolution_failed_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['skipped_category_resolution_failed_total'] ?? 0)); ?></span><br>
                categories_created_total: <span data-k="categories_created_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['categories_created_total'] ?? 0)); ?></span> |
                categories_existing_total: <span data-k="categories_existing_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['categories_existing_total'] ?? 0)); ?></span> |
                category_assignments_changed_total: <span data-k="category_assignments_changed_total"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['category_assignments_changed_total'] ?? 0)); ?></span><br>
                cache: <span data-k="menu_cache_rebuild"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['menu_cache_rebuild'] ?? 'skipped')); ?></span> |
                menu_cache_category_count: <span data-k="menu_cache_category_count"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['menu_cache_category_count'] ?? 0)); ?></span> |
                menu_cache_build_duration: <span data-k="menu_cache_build_duration"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['menu_cache_build_duration'] ?? 0)); ?></span> |
                batches_since_last_cache_rebuild: <span data-k="batches_done_since_cache_rebuild"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['batches_done_since_cache_rebuild'] ?? 0)); ?></span><br>
                started_at: <span data-k="started_at"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['started_at'] ?? '')); ?></span> |
                updated_at: <span data-k="updated_at"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['updated_at'] ?? '')); ?></span> |
                duration: <span data-k="duration"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['duration'] ?? 0)); ?></span><br>
                last_error: <span data-k="last_error"><?php echo esc_html((string) ($categoryRebuildAutorunStatus['last_error'] ?? '')); ?></span><br>
                <strong>Autorun debug</strong><br>
                category_rebuild_autorun_ui_loaded: <span data-k="category_rebuild_autorun_ui_loaded">yes</span> |
                category_rebuild_autorun_js_loaded: <span data-k="category_rebuild_autorun_js_loaded" id="gpswiss_rebuild_autorun_js_loaded">no</span> |
                category_rebuild_autorun_nonce_present: <span data-k="category_rebuild_autorun_nonce_present" id="gpswiss_rebuild_autorun_nonce_present">no</span> |
                category_rebuild_autorun_ajax_action: <span data-k="category_rebuild_autorun_ajax_action" id="gpswiss_rebuild_autorun_ajax_action"><?php echo esc_html('gpswiss_ovoko_category_rebuild_autorun'); ?></span><br>
                last_autorun_command: <span data-k="last_autorun_command"><?php echo esc_html((string) ($categoryRebuildAutorunLastAjax['command'] ?? '')); ?></span> |
                last_autorun_error: <span data-k="last_autorun_error"><?php echo esc_html((string) (($categoryRebuildAutorunLastAjax['error'] ?? '') ?: ($categoryRebuildAutorunStatus['last_error'] ?? ''))); ?></span><br>
                category_rebuild_autorun_status_summary:
                <pre data-k="category_rebuild_autorun_status_summary" style="white-space:pre-wrap;max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html(wp_json_encode($categoryRebuildAutorunStatusSummary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                category_rebuild_autorun_status_raw:
                <pre data-k="category_rebuild_autorun_status_raw" style="white-space:pre-wrap;max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html((string) ($categoryRebuildAutorunDebug['raw_json'] ?? 'null')); ?></pre>
                last_ajax_response:
                <pre data-k="last_ajax_response" style="white-space:pre-wrap;max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html(wp_json_encode($categoryRebuildAutorunLastAjax, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                last_batch_result:
                <pre data-k="last_batch_result" style="white-space:pre-wrap;max-height:180px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html(wp_json_encode($categoryRebuildAutorunStatus['last_batch_result'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
            </div>
            <pre id="gpswiss_rebuild_autorun_logs" style="max-height:220px;overflow:auto;background:#111;color:#e6e6e6;padding:10px;"></pre>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="padding:12px;background:#fff5f5;border:1px solid #d63638;">
            <?php wp_nonce_field('gpswiss_ovoko_delete_all_product_categories'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_delete_all_product_categories" />
            <h4>Danger zone: delete all Woo product categories</h4>
            <p>This deletes all <code>product_cat</code> terms and detaches product/category relationships only. It does not delete products, media, tags, attributes, prices, stock, descriptions, eBay, Allegro, or Ovoko data.</p>
            <p><strong>Requires exact confirmation:</strong> <code>DELETE ALL PRODUCT CATEGORIES</code></p>
            <input type="text" name="confirmation" class="regular-text" placeholder="DELETE ALL PRODUCT CATEGORIES" />
            <button class="button" type="submit" style="background:#b32d2e;border-color:#8f2223;color:#fff;">Delete all Woo product categories</button>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Safe cleanup old Woo categories + homepage menu preview</h3>
        <p><strong>Safety:</strong> all actions in this box are dry-run/report-only. They do not delete categories, do not edit products, do not change Ovoko, Allegro, eBay, and do not update menus.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_audit_old_categories'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_audit_old_categories" />
                <?php submit_button('Audit old categories — dry-run only', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_download_category_cleanup_csv'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_download_category_cleanup_csv" />
                <?php submit_button('Download category cleanup CSV', 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gpswiss_ovoko_preview_homepage_menu_changes'); ?>
                <input type="hidden" name="action" value="gpswiss_ovoko_preview_homepage_menu_changes" />
                <?php submit_button('Preview homepage menu changes — dry-run only', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <p><em>Apply operations intentionally are not wired here yet:</em> deleting safe old empty categories and updating the homepage category menu must be added/run only after separate confirmation.</p>
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
        <details style="margin-top:18px;"><summary><strong>Developer diagnostics</strong></summary>
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
    </details>
</div>
