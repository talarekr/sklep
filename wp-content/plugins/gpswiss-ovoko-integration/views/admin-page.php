<?php
/** @var array $data */
/** @var array|null $notice */
?>
<div class="wrap">
    <h1>Ovoko Integration Readiness</h1>
    <?php if (!empty($notice)): ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?>"><p><?php echo esc_html($notice['text']); ?></p></div>
    <?php endif; ?>

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
        </ul>
    <?php endif; ?>

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
        </ul>
    <?php endif; ?>

    <h2>Preview sync flow (dry-run only)</h2>
    <p>Sample fixture (developer/sample only, no outbound/no import): <code><?php echo esc_html(wp_json_encode($data['sync_preview_fixture'])); ?></code></p>
    <p>Preview Woo meta mapping: <code><?php echo esc_html(wp_json_encode($data['sync_preview_meta_mapping'])); ?></code></p>
    <p>Preview match result: <code><?php echo esc_html(wp_json_encode($data['sync_preview_match'])); ?></code></p>
</div>
