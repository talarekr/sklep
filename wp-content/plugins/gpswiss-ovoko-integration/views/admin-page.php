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
            <tr><th>Dry-run mode</th><td><label><input type="checkbox" name="ovoko_callback_dry_run" value="1" <?php checked(!empty($data['settings']['ovoko_callback_dry_run'])); ?> /> Enabled</label></td></tr>
            <tr><th>Header name</th><td><input type="text" name="ovoko_callback_header_name" value="<?php echo esc_attr((string) $data['settings']['ovoko_callback_header_name']); ?>" class="regular-text" /></td></tr>
            <tr><th>Header secret</th><td><input type="password" name="ovoko_callback_header_secret" value="<?php echo esc_attr((string) $data['settings']['ovoko_callback_header_secret']); ?>" class="regular-text" />
            <p>Configured: <strong><?php echo !empty($data['settings']['ovoko_callback_header_secret']) ? 'Yes' : 'No'; ?></strong></p></td></tr>
        </table>
        <?php submit_button('Save settings'); ?>
    </form>

    <h2>WooCommerce REST API readiness</h2>
    <ul>
        <li>Store URL: <code><?php echo esc_html($data['store_url']); ?></code></li>
        <li>HTTPS enabled: <strong><?php echo !empty($data['store_https']) ? 'Yes' : 'No'; ?></strong></li>
        <li>REST permalink ready: <strong><?php echo !empty($data['rest_permalink_ready']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Woo REST reachable: <strong><?php echo !empty($data['woo_rest_reachable']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Needed Woo API permissions: products read/write, stock read/write, categories read/write (optional), media/images (if required).</li>
    </ul>

    <h2>Required data for Ovoko</h2>
    <ol>
        <li>Store URL</li><li>WooCommerce Consumer Key</li><li>WooCommerce Consumer Secret</li>
        <li>Callback URL</li><li>Callback Header Name</li><li>Callback Header Secret</li><li>Optional username/integration id</li>
    </ol>

    <h2>Callback counters</h2>
    <p>received: <?php echo (int) $data['counters']['received']; ?> | auth_failed: <?php echo (int) $data['counters']['auth_failed']; ?> | duplicate: <?php echo (int) $data['counters']['duplicate']; ?> | dry_run: <?php echo (int) $data['counters']['dry_run']; ?> | applied: <?php echo (int) $data['counters']['applied']; ?> | failed: <?php echo (int) $data['counters']['failed']; ?></p>

    <h2>Mapping readiness</h2>
    <p>Products with <code>_ovoko_part_id</code>: <?php echo (int) $data['with_ovoko_part_id']; ?> | without: <?php echo (int) $data['without_ovoko_part_id']; ?></p>
    <p>Mapping meta keys: <code><?php echo esc_html(implode(', ', $data['mapping_meta_keys'])); ?></code></p>

    <h2>Technical report (Ovoko → Woo → eBay readiness)</h2>
    <ul>
        <li>Callback receiver ready: <strong><?php echo !empty($data['readiness_report']['callback_receiver_ready']) ? 'Yes' : 'No'; ?></strong></li>
        <li>part_id mapping ready: <strong><?php echo !empty($data['readiness_report']['mapping_ready']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Full import data ready now: <strong><?php echo !empty($data['readiness_report']['full_import_data_ready']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Need Ovoko WooCommerceIntegration activation: <strong><?php echo !empty($data['readiness_report']['ovoko_woocommerceintegration_needed']) ? 'Yes' : 'No'; ?></strong></li>
        <li>Suggested source tags: <code><?php echo esc_html(implode(', ', $data['readiness_report']['source_tagging_proposal'])); ?></code></li>
    </ul>

    <h3>Risk notes</h3>
    <ul><?php foreach ($data['readiness_report']['risks'] as $risk): ?><li><?php echo esc_html($risk); ?></li><?php endforeach; ?></ul>

    <h2>Recent callback events</h2>
    <table class="widefat striped"><thead><tr><th>event_id</th><th>event_type</th><th>part_id</th><th>status</th><th>product_id</th><th>sku</th><th>dry_run</th><th>action</th></tr></thead><tbody>
    <?php foreach (array_slice($data['recent_events'], 0, 10) as $event): ?>
        <tr><td><code><?php echo esc_html((string) ($event['event_id'] ?? '')); ?></code></td><td><?php echo esc_html((string) ($event['event_type'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['part_id'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['status'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['product_id'] ?? '')); ?></td><td><?php echo esc_html((string) ($event['sku'] ?? '')); ?></td><td><?php echo !empty($event['dry_run']) ? 'Yes' : 'No'; ?></td><td><?php echo esc_html((string) ($event['action_that_would_be_taken'] ?? '')); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>

    <h2>Test Ovoko callback locally (dry-run only)</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('gpswiss_ovoko_test_callback'); ?>
        <input type="hidden" name="action" value="gpswiss_ovoko_test_callback" />
        <input type="text" name="part_id" placeholder="part_id" required />
        <input type="text" name="status" placeholder="status" value="sold" required />
        <?php submit_button('Run local callback test', 'secondary', 'submit', false); ?>
    </form>
</div>
