<div class="wrap">
    <h1>eBay Integration</h1>

    <h2>1. API Settings</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_save_settings'); ?>
        <input type="hidden" name="action" value="wei_save_settings" />
        <p>Environment:
            <select name="environment">
                <option value="sandbox" <?php selected(($s['environment'] ?? ''), 'sandbox'); ?>>sandbox</option>
                <option value="production" <?php selected(($s['environment'] ?? 'production'), 'production'); ?>>production</option>
            </select>
        </p>
        <p>Client ID: <input type="text" name="client_id" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>" class="regular-text" /></p>
        <p>Client Secret: <input type="password" name="client_secret" value="<?php echo esc_attr($s['client_secret'] ?? ''); ?>" class="regular-text" /></p>
        <p>Redirect URL: <input type="text" name="redirect_uri" value="<?php echo esc_attr($s['redirect_uri'] ?? 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback'); ?>" class="regular-text" /></p>
        <p><button class="button button-primary">Save settings</button></p>
    </form>

    <h2>2. Authorization</h2>
    <p><a class="button" href="<?php echo esc_url($connect_url . '&wei_ebay_oauth=1'); ?>">Connect eBay</a></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form>
    <p>Token expires at (unix): <?php echo esc_html((string) ($s['expires_at'] ?? '')); ?></p>

    <h2>3. Readiness check</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run readiness check</button></form>

    <h2>4. MVP actions</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_export'); ?><input type="hidden" name="action" value="wei_export_product" />
        <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Export product</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_sync'); ?><input type="hidden" name="action" value="wei_sync_stock" />
        <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Sync stock</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_import_order'); ?><input type="hidden" name="action" value="wei_import_order" /><button class="button">Import one eBay order</button></form>

    <h2>5. Logs</h2>
    <p>Last status: <?php echo esc_html(($status['at'] ?? '-') . ' ' . ($status['message'] ?? '')); ?></p>
    <ul>
        <?php foreach ((array) $logs as $log): ?>
            <li><?php echo esc_html(($log['at'] ?? '') . ' ' . ($log['message'] ?? '')); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
