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
        <p>eBay RuName: <input type="text" name="runame" value="<?php echo esc_attr($s['runame'] ?? ''); ?>" class="regular-text" /></p>
        <p>Marketplace ID: <input type="text" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" class="regular-text" /></p>
        <p>Default eBay Category ID: <input type="text" name="default_category_id" value="<?php echo esc_attr($s['default_category_id'] ?? ''); ?>" class="regular-text" /></p>
        <h3>Inventory Location</h3>
        <p>Merchant Location Key: <input type="text" name="inventory_location_key" value="<?php echo esc_attr($s['inventory_location_key'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
        <p>Name: <input type="text" name="inventory_location_name" value="<?php echo esc_attr($s['inventory_location_name'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
        <p>Country: <input type="text" name="inventory_location_country" value="<?php echo esc_attr($s['inventory_location_country'] ?? 'PL'); ?>" class="regular-text" /></p>
        <p>Postal code: <input type="text" name="inventory_location_postal_code" value="<?php echo esc_attr($s['inventory_location_postal_code'] ?? '08-460'); ?>" class="regular-text" /></p>
        <p>City: <input type="text" name="inventory_location_city" value="<?php echo esc_attr($s['inventory_location_city'] ?? 'Sobolew'); ?>" class="regular-text" /></p>
        <p>Address line 1: <input type="text" name="inventory_location_address_line_1" value="<?php echo esc_attr($s['inventory_location_address_line_1'] ?? ''); ?>" class="regular-text" /></p>
        <h3>Business Policies</h3>
        <p>Fulfillment policy name: <input type="text" name="fulfillment_policy_name" value="<?php echo esc_attr($s['fulfillment_policy_name'] ?? 'GP Swiss Shipping'); ?>" class="regular-text" /></p>
        <p>Payment policy name: <input type="text" name="payment_policy_name" value="<?php echo esc_attr($s['payment_policy_name'] ?? 'GP Swiss Payments'); ?>" class="regular-text" /></p>
        <p>Return policy name: <input type="text" name="return_policy_name" value="<?php echo esc_attr($s['return_policy_name'] ?? 'GP Swiss Returns'); ?>" class="regular-text" /></p>
        <p>Callback URL (info only): <code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></p>
        <p><button class="button button-primary">Save settings</button></p>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_upsert_inventory_location'); ?>
        <input type="hidden" name="action" value="wei_upsert_inventory_location" />
        <p><button class="button">Create / Update inventory location</button></p>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_upsert_business_policies'); ?>
        <input type="hidden" name="action" value="wei_upsert_business_policies" />
        <p><button class="button">Create / Update business policies</button></p>
    </form>

    <h2>2. Authorization</h2>
    <p><a class="button" href="<?php echo esc_url($connect_url); ?>">Connect eBay</a></p>
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


    <h2>Debug</h2>
    <ul>
        <li><strong>Client ID:</strong> <code><?php echo esc_html((string) ($s['client_id'] ?? '')); ?></code></li>
        <li><strong>Environment:</strong> <code><?php echo esc_html((string) ($s['environment'] ?? 'production')); ?></code></li>
        <li><strong>Marketplace ID:</strong> <code><?php echo esc_html((string) ($s['marketplace_id'] ?? 'EBAY_DE')); ?></code></li>
        <li><strong>Default eBay Category ID:</strong> <code><?php echo esc_html((string) ($s['default_category_id'] ?? '')); ?></code></li>
        <li><strong>RuName:</strong> <code><?php echo esc_html((string) ($s['runame'] ?? '')); ?></code></li>
        <li><strong>Callback URL:</strong> <code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></li>
        <li><strong>Authorize URL:</strong> <code style="word-break:break-all"><?php echo esc_html($connect_url); ?></code></li>
    </ul>

    <h2>5. Logs</h2>
    <p>Last status: <?php echo esc_html(($status['at'] ?? '-') . ' ' . ($status['message'] ?? '')); ?></p>
    <ul>
        <?php foreach ((array) $logs as $log): ?>
            <li><?php echo esc_html(($log['at'] ?? '') . ' ' . ($log['message'] ?? '')); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
