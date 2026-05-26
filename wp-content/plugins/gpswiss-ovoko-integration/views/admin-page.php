<?php
/** @var array $data */
/** @var array|null $notice */

$csvStatus = (array) ($data['csv_mapping_status'] ?? []);
$memoryLimitRaw = (string) ini_get('memory_limit');
$memoryLimitMb = (int) preg_replace('/[^0-9]/', '', $memoryLimitRaw);
$blockFullBulk = $memoryLimitMb > 0 && $memoryLimitMb <= 128;
$showAdvancedTools = defined('GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS') ? (bool) GPSWISS_OVOKO_SHOW_ADVANCED_TOOLS : true;

$apiOk = !empty($data['rrr_api_check']['ok']) || !empty($data['supply_connector_check']['ok']);
$csvLoaded = !empty($csvStatus['rows_total']);
$lastSyncStatus = (string) ($data['settings']['ovoko_sync_mode'] ?? 'unknown');

$noticePayload = null;
if (!empty($notice['text']) && is_string($notice['text'])) {
    $decoded = json_decode($notice['text'], true);
    if (is_array($decoded)) {
        $noticePayload = $decoded;
    }
}
?>
<div class="wrap" style="max-width:1180px;">
    <h1>Ovoko / RRR Integration</h1>

    <div class="notice notice-info"><p>
        <strong>API connection:</strong> <?php echo $apiOk ? 'OK' : 'error'; ?> |
        <strong>CSV mapping:</strong> <?php echo $csvLoaded ? 'loaded' : 'not loaded'; ?> |
        <strong>CSV rows:</strong> <?php echo esc_html((string) ($csvStatus['rows_total'] ?? 0)); ?> |
        <strong>Unique codes:</strong> <?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? 0)); ?> |
        <strong>Duplicates:</strong> <?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? 0)); ?> |
        <strong>PHP memory_limit:</strong> <?php echo esc_html($memoryLimitRaw); ?> |
        <strong>Last sync status:</strong> <?php echo esc_html($lastSyncStatus); ?>
    </p></div>

    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
            <p><?php echo esc_html((string) ($notice['text'] ?? '')); ?></p>
            <?php if (is_array($noticePayload)): ?>
                <ul style="margin-left:18px;">
                    <li><strong>product_id:</strong> <code><?php echo esc_html((string) ($noticePayload['product_id'] ?? ($noticePayload['sample_results'][0]['product_id'] ?? ''))); ?></code></li>
                    <li><strong>matched_ovoko_part_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_part_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_part_id'] ?? ''))); ?></code></li>
                    <li><strong>matched_ovoko_car_id:</strong> <code><?php echo esc_html((string) ($noticePayload['matched_ovoko_car_id'] ?? ($noticePayload['sample_results'][0]['matched_ovoko_car_id'] ?? ''))); ?></code></li>
                    <li><strong>attributes_count:</strong> <code><?php echo esc_html((string) ($noticePayload['attributes_count'] ?? ($noticePayload['sample_results'][0]['attributes_count'] ?? $noticePayload['sample_results'][0]['would_write_attributes_count'] ?? ''))); ?></code></li>
                    <li><strong>no_price_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_price_change'] ?? ($noticePayload['sample_results'][0]['no_price_change'] ?? ''))); ?></code></li>
                    <li><strong>no_stock_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_stock_change'] ?? ($noticePayload['sample_results'][0]['no_stock_change'] ?? ''))); ?></code></li>
                    <li><strong>no_images_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_images_change'] ?? ($noticePayload['sample_results'][0]['no_images_change'] ?? ''))); ?></code></li>
                    <li><strong>no_title_change:</strong> <code><?php echo esc_html((string) ($noticePayload['no_title_change'] ?? ($noticePayload['sample_results'][0]['no_title_change'] ?? ''))); ?></code></li>
                    <li><strong>memory_peak_mb:</strong> <code><?php echo esc_html((string) ($noticePayload['memory_peak_mb'] ?? ($noticePayload['sample_results'][0]['memory_peak_mb'] ?? ''))); ?></code></li>
                </ul>
                <details><summary>Show technical JSON</summary><pre><?php echo esc_html(wp_json_encode($noticePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <h2>Main actions</h2>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Create Woo draft product from RRR part</h3>
        <p>Creates Woo draft product. Does not publish to Allegro/eBay/batches.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
            <?php wp_nonce_field('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment" />
            <input type="hidden" name="batch_size" value="1" />
            <input type="hidden" name="limit" value="1" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <label for="single_product_id">product_id:</label>
            <input id="single_product_id" type="number" min="1" name="product_ids_csv" value="2081" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run</button>
            <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply update</button>
        </form>
    </div>

    <div class="postbox" style="padding:16px; margin-bottom:14px;">
        <h3>Update product cards from CSV mapping</h3>
        <p>CSV maps part number to Ovoko part ID.</p>
        <?php if ($blockFullBulk): ?><div class="notice notice-warning"><p>Apply is blocked for low memory_limit. Use dry-run or increase to 256M.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'); ?>
            <input type="hidden" name="action" value="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment" />
            <input type="hidden" name="details_only" value="1" />
            <input type="hidden" name="minimal_response" value="1" />
            <input type="hidden" name="disable_debug_heavy_logs" value="1" />
            <label for="bulk_product_ids_csv">product_ids_csv (optional):</label>
            <input id="bulk_product_ids_csv" type="text" class="regular-text" name="product_ids_csv" value="" />
            <label for="bulk_after_product_id">after_product_id:</label>
            <input id="bulk_after_product_id" type="number" min="0" name="after_product_id" value="0" />
            <label for="bulk_limit">limit:</label>
            <input id="bulk_limit" type="number" min="1" max="50" name="limit" value="3" />
            <label for="bulk_batch_size">batch_size:</label>
            <input id="bulk_batch_size" type="number" min="1" max="3" name="batch_size" value="1" />
            <label><input type="checkbox" name="replace_description" value="1" /> Replace old Allegro description</label>
            <br><br>
            <button class="button button-secondary" type="submit" name="dry_run" value="1">Dry run batch</button>
            <button class="button button-primary" type="submit" name="apply" value="1" <?php disabled($blockFullBulk); ?>>Apply batch</button>
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
