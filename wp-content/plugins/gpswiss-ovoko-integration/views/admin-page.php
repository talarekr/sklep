<?php
/** @var array $data */
/** @var array|null $notice */
?>
<div class="wrap">
    <h1>Ovoko Integration Readiness</h1>
    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['text']); ?></p></div>
    <?php endif; ?>

    <h2>Preview listing image status</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_listing_image_status'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_listing_image_status" />
        <label for="listing_status_product_id">Product ID:</label>
        <input id="listing_status_product_id" type="number" min="1" name="product_id" value="60407" />
        <?php submit_button('Preview listing image status', 'secondary', 'submit', false); ?>
    </form>

    <h2>Generate listing image for Ovoko product</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_generate_listing_image'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_generate_listing_image" />
        <label for="generate_listing_product_id">Product ID:</label>
        <input id="generate_listing_product_id" type="number" min="1" name="product_id" value="60407" />
        <?php submit_button('Generate listing image for Ovoko product', 'secondary', 'submit', false); ?>
    </form>

    <h2>Apply Ovoko technical attributes to Woo product</h2>
    <p><strong>Manual action.</strong> Updates only product technical meta/attributes (no price, no stock, no publish).</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_apply_technical_attributes'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_apply_technical_attributes" />
        <label for="technical_product_id">Product ID:</label>
        <input id="technical_product_id" type="number" min="1" name="product_id" value="60271" />
        <?php submit_button('Apply Ovoko technical attributes to Woo product', 'secondary', 'submit', false); ?>
    </form>

    <h2>Frontend part number mapping</h2>
    <p><strong>Preview/debug.</strong> Checks the exact frontend meta key used by theme for “Numer części”.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_frontend_part_number_mapping'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_frontend_part_number_mapping" />
        <label for="frontend_mapping_preview_product_id">Product ID:</label>
        <input id="frontend_mapping_preview_product_id" type="number" min="1" name="product_id" value="60405" />
        <?php submit_button('Frontend part number mapping', 'secondary', 'submit', false); ?>
    </form>

    <p><strong>Manual action.</strong> Writes Ovoko manufacturer_code to frontend-used part number field only.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_apply_frontend_part_number_mapping'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_apply_frontend_part_number_mapping" />
        <label for="frontend_mapping_apply_product_id">Product ID:</label>
        <input id="frontend_mapping_apply_product_id" type="number" min="1" name="product_id" value="60405" />
        <?php submit_button('Apply frontend part number mapping', 'secondary', 'submit', false); ?>
    </form>


    <h2>Preview Allegro to Ovoko match</h2>
    <p><strong>Preview only.</strong> No Woo writes, no price/stock/images/title/publication changes.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_allegro_to_ovoko_match'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_allegro_to_ovoko_match" />
        <label for="allegro_match_preview_product_id">Product ID:</label>
        <input id="allegro_match_preview_product_id" type="number" min="1" name="product_id" value="0" />
        <?php submit_button('Preview Allegro to Ovoko match', 'secondary', 'submit', false); ?>
    </form>
    <h2>Ovoko CSV mapping file</h2>
    <p><strong>CSV is used only for mapping/enrichment.</strong> No price/stock/images/title updates from CSV.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field('gpswiss_ovoko_import_csv_mapping'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_import_csv_mapping" />
        <label for="csv_mapping_file">Upload CSV:</label>
        <input id="csv_mapping_file" type="file" name="csv_mapping_file" accept=".csv,text/csv" />
        <br />
        <label for="csv_file_path">Or local path:</label>
        <input id="csv_file_path" type="text" class="regular-text" name="csv_file_path" value="/workspace/sklep/parts-stock-2026-05-25.csv" />
        <?php submit_button('Import CSV mapping', 'secondary', 'submit', false); ?>
    </form>
    <?php $csvStatus = (array) ($data['csv_mapping_status'] ?? []); if (!empty($csvStatus)): ?>
        <ul>
            <li>rows_total: <code><?php echo esc_html((string) ($csvStatus['rows_total'] ?? '')); ?></code></li>
            <li>rows_with_part_code: <code><?php echo esc_html((string) ($csvStatus['rows_with_part_code'] ?? '')); ?></code></li>
            <li>unique_part_codes: <code><?php echo esc_html((string) ($csvStatus['unique_part_codes'] ?? '')); ?></code></li>
            <li>duplicate_part_codes_count: <code><?php echo esc_html((string) ($csvStatus['duplicate_part_codes_count'] ?? '')); ?></code></li>
            <li>duplicate_rows_count: <code><?php echo esc_html((string) ($csvStatus['duplicate_rows_count'] ?? '')); ?></code></li>
            <li>imported_at: <code><?php echo esc_html((string) ($csvStatus['imported_at'] ?? '')); ?></code></li>
            <li>file_name: <code><?php echo esc_html((string) ($csvStatus['file_name'] ?? '')); ?></code></li>
            <li>file_hash: <code><?php echo esc_html((string) ($csvStatus['file_hash'] ?? '')); ?></code></li>
        </ul>
    <?php endif; ?>

    <h2>Probe RRR part search by code</h2>
    <p><strong>Diagnostic only.</strong> Read-only probes on <code>/v2/get/parts</code> with candidate search parameters.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_probe_rrr_part_search_by_code'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_probe_rrr_part_search_by_code" />
        <label for="probe_part_number">Part number:</label>
        <input id="probe_part_number" type="text" name="part_number" value="A1778106004" />
        <?php submit_button('Probe RRR part search by code', 'secondary', 'submit', false); ?>
    </form>

    <h2>Preview paginated RRR part code lookup</h2>
    <p><strong>Preview only.</strong> Dry-run paginated scan (limited pages) for exact part code match.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_paginated_rrr_part_code_lookup'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_paginated_rrr_part_code_lookup" />
        <label for="paginated_part_number">Part number:</label>
        <input id="paginated_part_number" type="text" name="part_number" value="A1778106004" />
        <label for="paginated_limit">Limit:</label>
        <input id="paginated_limit" type="number" min="1" max="100" name="limit" value="100" />
        <label for="paginated_max_pages">Max pages:</label>
        <input id="paginated_max_pages" type="number" min="1" max="10" name="max_pages" value="3" />
        <?php submit_button('Preview paginated RRR part code lookup', 'secondary', 'submit', false); ?>
    </form>



    <h2>Preview product details table render status</h2>
    <p><strong>Preview only.</strong> No Woo writes.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_product_details_table_render_status'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_product_details_table_render_status" />
        <label for="details_render_status_product_id">Product ID:</label>
        <input id="details_render_status_product_id" type="number" min="1" name="product_id" value="52878" />
        <?php submit_button('Preview product details table render status', 'secondary', 'submit', false); ?>
    </form>

    <h2>Apply Allegro to Ovoko details enrichment</h2>
    <p><strong>Manual action.</strong> Writes only detail attributes/meta and can replace old Allegro description.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_apply_allegro_to_ovoko_details'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_apply_allegro_to_ovoko_details" />
        <label for="allegro_match_apply_product_id">Product ID:</label>
        <input id="allegro_match_apply_product_id" type="number" min="1" name="product_id" value="0" />
        <label><input type="checkbox" name="replace_description" value="1" checked="checked" /> Replace old description</label>
        <?php submit_button('Apply Allegro to Ovoko details enrichment', 'secondary', 'submit', false); ?>
    </form>

    <?php
    $memoryLimitRaw = (string) ini_get('memory_limit');
    $memoryLimitMb = (int) preg_replace('/[^0-9]/', '', $memoryLimitRaw);
    $blockFullBulk = $memoryLimitMb > 0 && $memoryLimitMb <= 128;
    ?>
    <h2>Bulk Allegro to Ovoko details enrichment</h2>
    <?php if ($blockFullBulk): ?>
    <p><strong>Full enrichment/apply is blocked at 128M because /get/part fetch peaks near memory limit. Increase PHP memory_limit to 256M or use CLI/lightweight worker.</strong></p>
    <?php endif; ?>
    <p><strong>This action updates only Ovoko/RRR detail attributes/meta. It must not change prices, stock, images, titles, publication status, eBay, Allegro, batches or cron settings.</strong></p>
    <p><strong>Warning:</strong> run this manually in small batches (3-5 items per request). Do not run long mass updates in one request.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment" />
        <label><input type="checkbox" name="dry_run" value="1" checked="checked" /> Dry run (default)</label><br />
        <label><input type="checkbox" name="match_only" value="1" <?php checked($blockFullBulk); ?> /> Match only / no API enrichment</label><br />
        <label><input type="checkbox" name="minimal_response" value="1" checked="checked" /> minimal_response</label>
        <label><input type="checkbox" name="disable_debug_heavy_logs" value="1" checked="checked" /> disable_debug_heavy_logs</label><br />
        <label><input type="checkbox" name="replace_description" value="1" <?php disabled($blockFullBulk); ?> /> Replace old Allegro description</label><br />
        <label><input type="checkbox" name="only_matched" value="1" /> only_matched</label>
        <label><input type="checkbox" name="skip_already_enriched" value="1" checked="checked" /> skip_already_enriched</label>
        <label><input type="checkbox" name="include_existing_ovoko" value="1" /> include_existing_ovoko</label>
        <label><input type="checkbox" name="fast_scan" value="1" checked="checked" /> Fast scan / keyset by product ID</label><br />
        <label>Batch size:</label><input type="number" min="1" max="5" name="batch_size" value="1" />
        <label>Limit:</label><input type="number" min="1" max="200" name="limit" value="20" />
        <label>Offset:</label><input type="number" min="0" name="offset" value="0" />
        <label>Page:</label><input type="number" min="1" name="page" value="1" />
        <label>After product ID:</label><input type="number" min="0" name="after_product_id" value="0" />
        <label>Scan limit:</label><input type="number" min="1" max="20" name="scan_limit" value="5" /><br />
        <label for="bulk_product_ids_csv">Product IDs CSV (optional):</label>
        <input id="bulk_product_ids_csv" type="text" class="regular-text" name="product_ids_csv" value="" />
        <?php submit_button('Bulk Allegro to Ovoko details enrichment', 'secondary', 'submit', false); ?>
    </form>
    <h3>Single enrichment dry-run (memory-safe)</h3>
    <p><strong>Dry-run can run at 128M, but apply may be blocked by memory guard (see apply_allowed/apply_blocked_reason in JSON).</strong></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_single_enrichment_dry_run'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_single_enrichment_dry_run" />
        <label for="single_dry_product_id">Product ID:</label>
        <input id="single_dry_product_id" type="number" min="1" name="product_id" value="2081" />
        <label for="single_dry_part_id">Part ID (optional):</label>
        <input id="single_dry_part_id" type="number" min="0" name="part_id" value="0" />
        <label><input type="checkbox" name="debug_full" value="1" /> debug_full=1</label>
        <?php submit_button('Single enrichment dry-run (JSON)', 'secondary', 'submit', false); ?>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
        <?php wp_nonce_field('gpswiss_ovoko_bulk_diagnostics_ping'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_bulk_diagnostics_ping" />
        <label for="ping_product_ids_csv">Product IDs CSV (optional):</label>
        <input id="ping_product_ids_csv" type="text" class="regular-text" name="product_ids_csv" value="" />
        <?php submit_button('Bulk diagnostics / ping', 'secondary', 'submit', false); ?>
    </form>

    <h2>Preview RRR car details</h2>
    <p><strong>Preview only.</strong> No Woo writes.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_rrr_car_details'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_rrr_car_details" />
        <label for="preview_car_id">Car ID:</label>
        <input id="preview_car_id" type="number" min="1" name="car_id" value="458" />
        <?php submit_button('Preview RRR car details', 'secondary', 'submit', false); ?>
    </form>

    <h2>Probe RRR vehicle endpoints</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_probe_rrr_vehicle_endpoints'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_probe_rrr_vehicle_endpoints" />
        <label for="probe_car_id">Car ID:</label>
        <input id="probe_car_id" type="number" min="1" name="car_id" value="458" />
        <?php submit_button('Probe RRR vehicle endpoints', 'secondary', 'submit', false); ?>
    </form>

    <h2>Apply RRR vehicle data to Ovoko product</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_apply_rrr_vehicle_data'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_apply_rrr_vehicle_data" />
        <label for="apply_vehicle_product_id">Product ID:</label>
        <input id="apply_vehicle_product_id" type="number" min="1" name="product_id" value="0" />
        <label><input type="checkbox" name="update_title" value="1" /> Update title</label>
        <?php submit_button('Apply RRR vehicle data to Ovoko product', 'secondary', 'submit', false); ?>
    </form>

<h2>Preview Ovoko title with vehicle data</h2>
    <p><strong>Preview only.</strong> No title updates are applied automatically.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_title_with_vehicle'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_title_with_vehicle" />
        <label for="preview_title_part_id">Part ID:</label>
        <input id="preview_title_part_id" type="number" min="1" name="part_id" value="60271" />
        <?php submit_button('Preview Ovoko title with vehicle data', 'secondary', 'submit', false); ?>
    </form>

    <?php if (empty($data['woo_active'])): ?>
        <div class="notice notice-warning"><p>WooCommerce is not active. Callback receiver still works, but product mapping/readiness is limited.</p></div>
    <?php endif; ?>

    <h2>Settings</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_save_settings'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_save_settings" />
        <table class="form-table">
            <tr><th>Callback URL</th><td><code><?php echo esc_html($data['callback_url']); ?></code></td></tr>
            <tr><th>Callback enabled</th><td><label><input type="checkbox" name="ovoko_callback_enabled" value="1" <?php checked(!empty($data['settings']['ovoko_callback_enabled'])); ?> /> Enabled</label></td></tr>
            <tr><th>Callback dry-run</th><td><label><input type="checkbox" name="ovoko_callback_dry_run" value="1" <?php checked(!empty($data['settings']['ovoko_callback_dry_run'])); ?> /> Enabled</label></td></tr>
            <tr><th>Callback header name</th><td><input type="text" name="ovoko_callback_header_name" value="<?php echo esc_attr((string) $data['settings']['ovoko_callback_header_name']); ?>" class="regular-text" /></td></tr>
            <tr><th>Callback header secret</th><td><input type="password" name="ovoko_callback_header_secret" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['ovoko_callback_header_secret']) ? 'Yes' : 'No'; ?></strong></p></td></tr>

            <tr><th>Supply Connector enabled</th><td><label><input type="checkbox" name="ovoko_supply_connector_enabled" value="1" <?php checked(!empty($data['settings']['ovoko_supply_connector_enabled'])); ?> /> Enabled</label></td></tr>
            <tr><th>Supply Connector base URL</th><td><input type="url" name="ovoko_supply_connector_base_url" value="<?php echo esc_attr((string) $data['settings']['ovoko_supply_connector_base_url']); ?>" class="regular-text" /></td></tr>
            <tr><th>Supply Connector token</th><td><input type="password" name="ovoko_supply_connector_token" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['ovoko_supply_connector_token']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
            <tr><th>Supply Connector API key</th><td><input type="password" name="ovoko_supply_connector_api_key" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['ovoko_supply_connector_api_key']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
            <tr><th>Ovoko integration id</th><td><input type="text" name="ovoko_integration_id" value="<?php echo esc_attr((string) $data['settings']['ovoko_integration_id']); ?>" class="regular-text" /></td></tr>
            <tr><th>Sync enabled</th><td><label><input type="checkbox" name="ovoko_sync_enabled" value="1" <?php checked(!empty($data['settings']['ovoko_sync_enabled'])); ?> /> Enabled</label></td></tr>
            <tr><th>Sync dry-run</th><td><label><input type="checkbox" name="ovoko_sync_dry_run" value="1" <?php checked(!empty($data['settings']['ovoko_sync_dry_run'])); ?> /> Enabled</label></td></tr>
            <tr><th>Sync mode</th><td><select name="ovoko_sync_mode"><?php foreach (['disabled','preview_only','manual_single','batch_dry_run'] as $mode): ?><option value="<?php echo esc_attr($mode); ?>" <?php selected($data['settings']['ovoko_sync_mode'], $mode); ?>><?php echo esc_html($mode); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th>Sync batch limit</th><td><input type="number" min="1" max="100" name="ovoko_sync_batch_limit" value="<?php echo (int) $data['settings']['ovoko_sync_batch_limit']; ?>" /></td></tr>
            <tr><th>Exclude gearbox products from Ovoko</th><td><label><input type="checkbox" name="ovoko_exclude_gearbox_products" value="1" <?php checked(!empty($data['settings']['ovoko_exclude_gearbox_products'])); ?> /> Enabled</label></td></tr>

            <tr><th>RRR API enabled</th><td><label><input type="checkbox" name="rrr_api_enabled" value="1" <?php checked(!empty($data['settings']['rrr_api_enabled'])); ?> /> Enabled</label></td></tr>
            <tr><th>RRR API dry-run</th><td><label><input type="checkbox" name="rrr_api_dry_run" value="1" <?php checked(!empty($data['settings']['rrr_api_dry_run'])); ?> /> Enabled</label></td></tr>
            <tr><th>RRR API base URL</th><td><input type="url" name="rrr_api_base_url" value="<?php echo esc_attr((string) $data['settings']['rrr_api_base_url']); ?>" class="regular-text" /></td></tr>
            <tr><th>RRR API username</th><td><input type="password" name="rrr_api_username" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['rrr_api_username']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
            <tr><th>RRR API password</th><td><input type="password" name="rrr_api_password" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['rrr_api_password']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
            <tr><th>RRR API user_token</th><td><input type="password" name="rrr_api_user_token" value="" class="regular-text" autocomplete="new-password" /><p>Configured: <strong><?php echo !empty($data['settings']['rrr_api_user_token']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
            <tr><th>Ovoko original image bearer token (optional)</th><td><input type="password" name="ovoko_original_image_bearer_token" value="" class="regular-text" autocomplete="new-password" /><p>original_image_token_configured: <strong><?php echo !empty($data['settings']['ovoko_original_image_bearer_token']) ? 'yes' : 'no'; ?></strong></p></td></tr>
        </table>
        <?php submit_button('Save settings'); ?>
    </form>

    <h2>Ovoko Supply Connector readiness</h2>
    <ul>
        <li>Base URL: <code><?php echo esc_html((string) $data['settings']['ovoko_supply_connector_base_url']); ?></code></li>
        <li>Credentials set: <strong><?php echo !empty($data['supply_connector_check']['credentials_set']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Base URL reachable: <strong><?php echo !empty($data['supply_connector_check']['base_url_reachable']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Docs/index reachable: <strong><?php echo !empty($data['supply_connector_check']['docs_or_index_reachable']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Authenticated endpoint confirmed: <strong><?php echo !empty($data['supply_connector_check']['authenticated_endpoint_confirmed']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Supply Connector enabled: <strong><?php echo !empty($data['settings']['ovoko_supply_connector_enabled']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Sync enabled: <strong><?php echo !empty($data['settings']['ovoko_sync_enabled']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Dry-run: <strong><?php echo !empty($data['settings']['ovoko_sync_dry_run']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Sync mode: <code><?php echo esc_html((string) $data['settings']['ovoko_sync_mode']); ?></code></li>
        <li>Batch limit: <strong><?php echo (int) $data['settings']['ovoko_sync_batch_limit']; ?></strong></li>
        <li>Last sync at: <code><?php echo esc_html((string) ($data['settings']['ovoko_last_sync_at'] ?: 'not yet')); ?></code></li>
    </ul>
    <p><strong>Production import remains disabled.</strong> This is scaffold/readiness only.</p>
    <p>Connection check performs public/base availability probes only; authenticated product endpoint is not called until Ovoko confirms it.</p>
    <p>Risk: duplicates between existing Woo catalog and future <code>source=ovoko_master</code> products require manual review for title-based candidates.</p>
    <p>Observed resources: <code><?php echo esc_html(implode(', ', (array) ($data['supply_connector_resources']['observed_resources'] ?? []))); ?></code></p>
    <p>Last connection probes: <code><?php echo esc_html(wp_json_encode($data['supply_connector_check']['public_endpoint_probes'] ?? [])); ?></code></p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_check_supply_connector'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_check_supply_connector" />
        <?php submit_button('Check Supply Connector configuration', 'secondary', 'submit', false); ?>
    </form>


    <h2>RRR API readiness</h2>
    <?php
    $rrrCheck = (array) ($data['rrr_api_check'] ?? []);
    $rrrPublicProbes = (array) ($rrrCheck['public_probes'] ?? []);
    $docsReachable = false;
    $swaggerReachable = false;
    foreach ($rrrPublicProbes as $probe) {
        if (($probe['path'] ?? '') === '/docs/' && !empty($probe['ok'])) {
            $docsReachable = true;
        }
        if (($probe['path'] ?? '') === '/openapi/swagger.yaml' && !empty($probe['ok'])) {
            $swaggerReachable = true;
        }
    }
    $authProbe = (array) ($rrrCheck['auth_probe'] ?? []);
    $pagination = (array) ($authProbe['pagination'] ?? []);
    $firstRecord = (array) ($authProbe['first_record'] ?? []);
    ?>
    <ul>
        <li>Base URL: <code><?php echo esc_html((string) $data['settings']['rrr_api_base_url']); ?></code></li>
        <li>Docs reachable: <strong><?php echo $docsReachable ? 'Yes' : 'No'; ?></strong></li>
        <li>Swagger reachable: <strong><?php echo $swaggerReachable ? 'Yes' : 'No'; ?></strong></li>
        <li>Credentials configured: <strong><?php echo !empty($rrrCheck['credentials_configured']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Auth read-only probe success: <strong><?php echo !empty($authProbe['success']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Status code: <code><?php echo esc_html((string) ($authProbe['status_code'] ?? '')); ?></code></li>
        <li>Message: <code><?php echo esc_html((string) ($authProbe['msg'] ?? '')); ?></code></li>
        <li>Pagination summary: <code><?php echo esc_html('page=' . (string) ($pagination['page'] ?? '-') . ', limit=' . (string) ($pagination['limit'] ?? '-') . ', total_count=' . (string) ($pagination['total_count'] ?? '-')); ?></code></li>
        <li>First record summary: <code><?php echo esc_html('id=' . (string) ($firstRecord['id'] ?? '-') . ', name=' . (string) ($firstRecord['name'] ?? '-') . ', status=' . (string) ($firstRecord['status'] ?? '-') . ', updated_at=' . (string) ($firstRecord['updated_at'] ?? '-')); ?></code></li>
        <li>Production import disabled: <strong>Yes</strong></li>
        <li>Dry-run enabled: <strong><?php echo !empty($data['settings']['rrr_api_dry_run']) ? 'Yes' : 'No'; ?></strong></li>
    </ul>
    <p><strong>Production import remains disabled.</strong> Read-only readiness only.</p>
    <p>RRR API uses POST form-data and business success must be checked via <code>status_code</code> in JSON body.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_check_rrr_api'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_check_rrr_api" />
        <?php submit_button('Check RRR API configuration', 'secondary', 'submit', false); ?>
    </form>

    <h2>Preview RRR parts status distribution</h2>
    <p><strong>Preview only — no Woo products were created or updated.</strong></p>
    <p><em>API total_count may include inactive/sold/archived parts until status semantics are confirmed by Ovoko/RRR.</em></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_rrr_parts_sample'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_rrr_parts_sample" />
        <label for="preview_limit">Limit (default 50, max 50):</label>
        <input id="preview_limit" type="number" min="1" max="50" name="preview_limit" value="50" />
        <label for="preview_page">Page (default 1):</label>
        <input id="preview_page" type="number" min="1" name="preview_page" value="1" />
        <?php submit_button('Preview RRR parts status distribution', 'secondary', 'submit', false); ?>
    </form>
    <?php
    $previewResult = [];
    if (!empty($notice['text'])) {
        $decodedNotice = json_decode((string) $notice['text'], true);
        if (is_array($decodedNotice) && (($decodedNotice['mode'] ?? '') === 'preview_only') && isset($decodedNotice['records'])) {
            $previewResult = $decodedNotice;
        }
    }
    ?>
    <?php if (!empty($previewResult)): ?>
        <h3>Preview result</h3>
        <ul>
            <li>Status code: <code><?php echo esc_html((string) ($previewResult['status_code'] ?? '')); ?></code></li>
            <li>Message: <code><?php echo esc_html((string) ($previewResult['msg'] ?? '')); ?></code></li>
            <li>pagination.page: <code><?php echo esc_html((string) ($previewResult['pagination']['page'] ?? '')); ?></code></li>
            <li>pagination.limit: <code><?php echo esc_html((string) ($previewResult['pagination']['limit'] ?? '')); ?></code></li>
            <li>pagination.total_count: <code><?php echo esc_html((string) ($previewResult['pagination']['total_count'] ?? '')); ?></code></li>
            <li>records_count: <code><?php echo esc_html((string) ($previewResult['records_count'] ?? 0)); ?></code></li>
            <li>status_distribution: <code><?php echo esc_html(wp_json_encode((array) ($previewResult['status_distribution'] ?? []))); ?></code></li>
        </ul>
        <p><em><?php echo esc_html((string) ($previewResult['diagnostic_note'] ?? '')); ?></em></p>
        <h4>Sample records (id, name, status, updated_at)</h4>
        <?php foreach (array_slice((array) ($previewResult['records'] ?? []), 0, 10) as $record): ?>
            <p>
                <code><?php echo esc_html('id=' . (string) ($record['part_id'] ?? '') . ', name=' . (string) ($record['title'] ?? '') . ', status=' . (string) ($record['status'] ?? '') . ', updated_at=' . (string) ($record['updated_at'] ?? '')); ?></code>
            </p>
        <?php endforeach; ?>
    <?php endif; ?>



    <h2>Excluded from Ovoko sync</h2>
    <ul>
        <li>ovoko_exclude_gearbox_products enabled: <strong><?php echo !empty($data['excluded_from_ovoko_sync']['enabled']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Detected gearbox products: <strong><?php echo (int) ($data['excluded_from_ovoko_sync']['detected_products_count'] ?? 0); ?></strong></li>
        <li>Last calculated at: <code><?php echo esc_html((string) (($data['excluded_from_ovoko_sync']['last_calculated_at'] ?? '') ?: 'not calculated automatically')); ?></code></li>
        <li><em><?php echo esc_html((string) ($data['excluded_from_ovoko_sync']['info'] ?? '')); ?></em></li>
    </ul>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_run_gearbox_exclusion_count'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_run_gearbox_exclusion_count" />
        <?php submit_button('Run gearbox exclusion count', 'secondary', 'submit', false); ?>
    </form>



    <h2>Preview RRR single part</h2>
    <p><strong>Read-only preview.</strong> No import, no product writes, no meta writes, no stock changes.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_rrr_single_part'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_rrr_single_part" />
        <label for="part_id">Part ID:</label>
        <input id="part_id" type="number" min="1" name="part_id" value="15" />
        <?php submit_button('Preview RRR single part', 'secondary', 'submit', false); ?>
    </form>
    <?php
    $singlePartPreview = [];
    if (!empty($notice['text'])) {
        $decodedNotice = json_decode((string) $notice['text'], true);
        if (is_array($decodedNotice) && (($decodedNotice['mode'] ?? '') === 'preview_only') && isset($decodedNotice['single_part_summary'])) {
            $singlePartPreview = $decodedNotice;
        }
    }
    ?>
    <?php if (!empty($singlePartPreview)): ?>
        <ul>
            <li>status_code: <code><?php echo esc_html((string) ($singlePartPreview['status_code'] ?? '')); ?></code></li>
            <li>msg: <code><?php echo esc_html((string) ($singlePartPreview['msg'] ?? '')); ?></code></li>
            <li>part_id: <code><?php echo esc_html((string) ($singlePartPreview['part_id'] ?? '')); ?></code></li>
            <li>top-level keys: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['response_top_level_keys'] ?? []))); ?></code></li>
            <li>single part summary: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['single_part_summary'] ?? []))); ?></code></li>
            <li>woo match preview: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['woo_match_preview'] ?? []))); ?></code></li>
            <li>raw_payload_summary: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['raw_payload_summary'] ?? []))); ?></code></li>
            <li>image_url_fields_found: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['image_url_fields_found'] ?? []))); ?></code></li>
            <li>image_field_diagnostics: <code><?php echo esc_html(wp_json_encode((array) ($singlePartPreview['image_field_diagnostics'] ?? []))); ?></code></li>
        </ul>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_probe_ovoko_image_url_variants'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_probe_ovoko_image_url_variants" />
        <label for="probe_ovoko_part_id">Part ID:</label>
        <input id="probe_ovoko_part_id" type="number" min="1" name="part_id" value="10994" />
        <?php submit_button('Probe Ovoko original image auth', 'secondary', 'submit', false); ?>
    </form>

    <h2>Preview Woo product create from RRR part</h2>
    <p><strong>Preview only — no Woo product was created or updated.</strong></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_preview_rrr_woo_create'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_preview_rrr_woo_create" />
        <label for="preview_woo_part_id">Part ID:</label>
        <input id="preview_woo_part_id" type="number" min="1" name="part_id" value="10994" />
        <?php submit_button('Preview Woo product create from RRR part', 'secondary', 'submit', false); ?>
    </form>
    <?php
    $previewWooCreate = [];
    if (!empty($notice['text'])) {
        $decodedNotice = json_decode((string) $notice['text'], true);
        if (is_array($decodedNotice) && (($decodedNotice['action_name'] ?? '') === 'Preview Woo product create from RRR part')) {
            $previewWooCreate = $decodedNotice;
        }
    }
    ?>
    <?php if (!empty($previewWooCreate)): ?>
        <ul>
            <li>would_action: <code><?php echo esc_html((string) ($previewWooCreate['would_action'] ?? '')); ?></code></li>
            <li>create_blocked: <code><?php echo esc_html(!empty($previewWooCreate['create_blocked']) ? 'true' : 'false'); ?></code></li>
            <li>reason: <code><?php echo esc_html((string) ($previewWooCreate['reason'] ?? '')); ?></code></li>
            <li>excluded_from_ovoko_sync: <code><?php echo esc_html(!empty($previewWooCreate['excluded_from_ovoko_sync']) ? 'true' : 'false'); ?></code></li>
            <li>post_draft_preview: <code><?php echo esc_html(wp_json_encode((array) ($previewWooCreate['post_draft_preview'] ?? []))); ?></code></li>
            <li>preview_image_import_plan: <code><?php echo esc_html(wp_json_encode((array) ($previewWooCreate['preview_image_import_plan'] ?? []))); ?></code></li>
            <li>image_url_fields_found: <code><?php echo esc_html(wp_json_encode((array) ($previewWooCreate['image_url_fields_found'] ?? []))); ?></code></li>
            <li>woo_meta_preview: <code><?php echo esc_html(wp_json_encode((array) ($previewWooCreate['woo_meta_preview'] ?? []))); ?></code></li>
            <li>same_vehicle_grouping: <code><?php echo esc_html(wp_json_encode((array) ($previewWooCreate['post_draft_preview']['same_vehicle_grouping'] ?? []))); ?></code></li>
            <li>no_write_to_woo: <code><?php echo esc_html(!empty($previewWooCreate['no_write_to_woo']) ? 'true' : 'false'); ?></code></li>
        </ul>
    <?php endif; ?>



    <h2>Create Woo draft product from RRR part</h2>
    <p><strong>Manual test action.</strong> Creates only Woo draft product. No eBay, no Allegro, no batch, no publish.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_create_rrr_woo_draft'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_create_rrr_woo_draft" />
        <label for="create_woo_part_id">Part ID:</label>
        <input id="create_woo_part_id" type="number" min="1" name="part_id" value="10994" />
        <?php submit_button('Create Woo draft product from RRR part', 'secondary', 'submit', false); ?>
    </form>
    <?php
    $createWooDraft = [];
    if (!empty($notice['text'])) {
        $decodedNotice = json_decode((string) $notice['text'], true);
        if (is_array($decodedNotice) && (($decodedNotice['action_name'] ?? '') === 'Create Woo draft product from RRR part')) {
            $createWooDraft = $decodedNotice;
        }
    }
    ?>
    <?php if (!empty($createWooDraft)): ?>
        <ul>
            <li>created: <code><?php echo esc_html(!empty($createWooDraft['created']) ? 'true' : 'false'); ?></code></li>
            <li>existing_product_found: <code><?php echo esc_html(!empty($createWooDraft['existing_product_found']) ? 'true' : 'false'); ?></code></li>
            <li>created_product_id: <code><?php echo esc_html((string) ($createWooDraft['created_product_id'] ?? '')); ?></code></li>
            <li>edit_link: <code><?php echo esc_html((string) ($createWooDraft['edit_link'] ?? '')); ?></code></li>
            <li>status: <code><?php echo esc_html((string) ($createWooDraft['status'] ?? '')); ?></code></li>
            <li>sku: <code><?php echo esc_html((string) ($createWooDraft['sku'] ?? '')); ?></code></li>
            <li>price: <code><?php echo esc_html((string) ($createWooDraft['price'] ?? '')); ?></code></li>
            <li>no_ebay_publish: <code><?php echo esc_html(!empty($createWooDraft['no_ebay_publish']) ? 'true' : 'false'); ?></code></li>
            <li>no_allegro_publish: <code><?php echo esc_html(!empty($createWooDraft['no_allegro_publish']) ? 'true' : 'false'); ?></code></li>
            <li>no_batch: <code><?php echo esc_html(!empty($createWooDraft['no_batch']) ? 'true' : 'false'); ?></code></li>
            <li>validations: <code><?php echo esc_html(wp_json_encode((array) ($createWooDraft['validations'] ?? []))); ?></code></li>
            <li>image_watermark_policy_checked: <code><?php echo esc_html(!empty($createWooDraft['image_watermark_policy_checked']) ? 'true' : 'false'); ?></code></li>
            <li>image_clean_source_found: <code><?php echo esc_html(!empty($createWooDraft['image_clean_source_found']) ? 'true' : 'false'); ?></code></li>
            <li>image_source_selected: <code><?php echo esc_html((string) ($createWooDraft['image_source_selected'] ?? '')); ?></code></li>
            <li>image_watermark_warning: <code><?php echo esc_html((string) ($createWooDraft['image_watermark_warning'] ?? '')); ?></code></li>
        </ul>
    <?php endif; ?>

    <h2>Preview sync flow (dry-run only)</h2>
    <p>Sample fixture (developer/sample only, no outbound/no import): <code><?php echo esc_html(wp_json_encode($data['sync_preview_fixture'])); ?></code></p>
    <p>Preview Woo meta mapping: <code><?php echo esc_html(wp_json_encode($data['sync_preview_meta_mapping'])); ?></code></p>
    <p>Preview match result: <code><?php echo esc_html(wp_json_encode($data['sync_preview_match'])); ?></code></p>
</div>
