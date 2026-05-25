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
    <ul>
        <li>Base URL: <code><?php echo esc_html((string) $data['settings']['rrr_api_base_url']); ?></code></li>
        <li>Credentials configured: <strong><?php echo !empty($data['rrr_api_check']['credentials_configured']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Enabled: <strong><?php echo !empty($data['settings']['rrr_api_enabled']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Dry-run: <strong><?php echo !empty($data['settings']['rrr_api_dry_run']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Last test result: <code><?php echo esc_html(wp_json_encode($data['rrr_api_check'] ?? [])); ?></code></li>
    </ul>
    <p><strong>Production import remains disabled.</strong> Preview/readiness only.</p>
    <p>RRR API uses POST form-data and business success must be checked via <code>status_code</code> in JSON body.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_check_rrr_api'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_check_rrr_api" />
        <?php submit_button('Check RRR API configuration', 'secondary', 'submit', false); ?>
    </form>

    <h2>Preview sync flow (dry-run only)</h2>
    <p>Sample fixture (developer/sample only, no outbound/no import): <code><?php echo esc_html(wp_json_encode($data['sync_preview_fixture'])); ?></code></p>
    <p>Preview Woo meta mapping: <code><?php echo esc_html(wp_json_encode($data['sync_preview_meta_mapping'])); ?></code></p>
    <p>Preview match result: <code><?php echo esc_html(wp_json_encode($data['sync_preview_match'])); ?></code></p>
</div>
