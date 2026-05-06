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
        <p>SKU Category Overrides:<br />
            <textarea name="sku_category_overrides" class="large-text code" rows="3" placeholder="CFM-001=179847"><?php echo esc_textarea((string) ($s['sku_category_overrides'] ?? '')); ?></textarea><br />
            <span class="description">One SKU per line as SKU=categoryId. MVP fallback for EBAY_DE while Taxonomy API validation is added.</span>
        </p>
        <h3>Safe SKU / aspect defaults</h3>
        <p><label><input type="checkbox" name="use_woo_sku_for_ebay" value="1" <?php checked(!empty($s['use_woo_sku_for_ebay'])); ?> /> Use WooCommerce SKU for eBay when present</label><br />
            <span class="description">If Woo SKU is empty, the plugin stores a stable eBay-only SKU in <code>_wei_ebay_sku</code> and does not touch <code>_sku</code>.</span></p>
        <p><label><input type="checkbox" name="write_generated_sku_to_woo" value="1" <?php checked(!empty($s['write_generated_sku_to_woo'])); ?> /> Write generated eBay SKU to WooCommerce SKU</label><br />
            <span class="description"><strong>Default OFF for Allegro safety.</strong> Enable only if you know Woo SKU is not managed by Allegro sync.</span></p>
        <p>Default manufacturer / Hersteller fallback: <input type="text" name="default_hersteller_fallback" value="<?php echo esc_attr($s['default_hersteller_fallback'] ?? ''); ?>" class="regular-text" placeholder="SEAT" /></p>
        <p>Category aspect fallbacks:<br />
            <textarea name="category_aspect_fallbacks" class="large-text code" rows="3" placeholder="179847|Hersteller|SEAT"><?php echo esc_textarea((string) ($s['category_aspect_fallbacks'] ?? '')); ?></textarea><br />
            <span class="description">One fallback per line: eBay category ID | aspect name | value. Used after Woo attributes/meta/taxonomies.</span></p>
        <p>Stock sync mode:
            <select name="stock_sync_mode">
                <option value="set_zero" <?php selected(($s['stock_sync_mode'] ?? 'set_zero'), 'set_zero'); ?>>Set to zero after eBay sale</option>
                <option value="reduce" <?php selected(($s['stock_sync_mode'] ?? 'set_zero'), 'reduce'); ?>>Reduce by sold quantity</option>
            </select>
        </p>
        <details>
            <summary>Developer/debug JSON aspects fallback</summary>
            <p>eBay Aspects / Item specifics (JSON):<br />
                <textarea name="sku_aspect_overrides" class="large-text code" rows="7" placeholder='{&quot;CFM-001&quot;:{&quot;Hersteller&quot;:[&quot;SEAT&quot;]}}'><?php echo esc_textarea((string) ($s['sku_aspect_overrides'] ?? '')); ?></textarea><br />
                <span class="description">Debug-only fallback. Main UX should use mappings/fallbacks above, not manual JSON per product.</span>
            </p>
        </details>
        <h3>German Content Generator for EBAY_DE</h3>
        <p>Translation provider:
            <select name="translation_provider">
                <?php $provider = (string) ($s['translation_provider'] ?? 'disabled'); ?>
                <option value="disabled" <?php selected($provider, 'disabled'); ?>>Disabled</option>
                <option value="google_cloud_translate" <?php selected($provider, 'google_cloud_translate'); ?>>Google Cloud Translate</option>
            </select><br />
            <span class="description">Used only for generated eBay DE meta content. WooCommerce title/description and Allegro data are not changed.</span>
        </p>
        <p>Google Translation API key: <input type="password" name="translation_api_key" value="<?php echo esc_attr((string) ($s['translation_api_key'] ?? '')); ?>" class="regular-text" autocomplete="off" /></p>
        <p><label><input type="checkbox" name="auto_generate_german_content_preflight" value="1" <?php checked(!empty($s['auto_generate_german_content_preflight'])); ?> /> Auto-generate missing German content during preflight</label><br />
            <span class="description">Preflight writes only <code>_wei_ebay_de_*</code> meta and does not call eBay inventory/offer/publish APIs.</span></p>
        <p><label><input type="checkbox" name="regenerate_german_content_on_hash_change" value="1" <?php checked(!empty($s['regenerate_german_content_on_hash_change'])); ?> /> Regenerate German content when source hash changes</label><br />
            <span class="description">When disabled, existing generated/custom meta is reused and marked stale in logs/preflight if the Polish source title/description changed.</span></p>
        <h3>Inventory Location</h3>
        <p>Merchant Location Key: <input type="text" name="inventory_location_key" value="<?php echo esc_attr($s['inventory_location_key'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
        <p>Name: <input type="text" name="inventory_location_name" value="<?php echo esc_attr($s['inventory_location_name'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
        <p>Country: <input type="text" name="inventory_location_country" value="<?php echo esc_attr($s['inventory_location_country'] ?? 'PL'); ?>" class="regular-text" /></p>
        <p>Postal code: <input type="text" name="inventory_location_postal_code" value="<?php echo esc_attr($s['inventory_location_postal_code'] ?? '08-460'); ?>" class="regular-text" /></p>
        <p>City: <input type="text" name="inventory_location_city" value="<?php echo esc_attr($s['inventory_location_city'] ?? 'Sobolew'); ?>" class="regular-text" /></p>
        <p>Address line 1: <input type="text" name="inventory_location_address_line_1" value="<?php echo esc_attr($s['inventory_location_address_line_1'] ?? ''); ?>" class="regular-text" /></p>
        <h3>Business Policies (manual in eBay)</h3>
        <?php $cached = is_array($s['wei_cached_policies'] ?? null) ? $s['wei_cached_policies'] : []; ?>
        <?php $fulfillmentPolicies = is_array($cached['fulfillmentPolicies'] ?? null) ? $cached['fulfillmentPolicies'] : []; ?>
        <?php $paymentPolicies = is_array($cached['paymentPolicies'] ?? null) ? $cached['paymentPolicies'] : []; ?>
        <?php $returnPolicies = is_array($cached['returnPolicies'] ?? null) ? $cached['returnPolicies'] : []; ?>

        <p>Fulfillment policy:
            <select name="fulfillmentPolicyId">
                <option value="">-- select --</option>
                <?php foreach ($fulfillmentPolicies as $policy): ?>
                    <?php $policyId = (string) ($policy['fulfillmentPolicyId'] ?? ''); ?>
                    <option value="<?php echo esc_attr($policyId); ?>" <?php selected((string) ($s['ebay_fulfillment_policy_id'] ?? ''), $policyId); ?>>
                        <?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>Payment policy:
            <select name="paymentPolicyId">
                <option value="">-- select --</option>
                <?php foreach ($paymentPolicies as $policy): ?>
                    <?php $policyId = (string) ($policy['paymentPolicyId'] ?? ''); ?>
                    <option value="<?php echo esc_attr($policyId); ?>" <?php selected((string) ($s['ebay_payment_policy_id'] ?? ''), $policyId); ?>>
                        <?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>Return policy:
            <select name="returnPolicyId">
                <option value="">-- select --</option>
                <?php foreach ($returnPolicies as $policy): ?>
                    <?php $policyId = (string) ($policy['returnPolicyId'] ?? ''); ?>
                    <option value="<?php echo esc_attr($policyId); ?>" <?php selected((string) ($s['ebay_return_policy_id'] ?? ''), $policyId); ?>>
                        <?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>Callback URL (info only): <code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></p>
        <p><button class="button button-primary">Save settings</button></p>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_upsert_inventory_location'); ?>
        <input type="hidden" name="action" value="wei_upsert_inventory_location" />
        <p><button class="button">Create / Update inventory location</button></p>
    </form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_refresh_policies'); ?>
        <input type="hidden" name="action" value="wei_refresh_policies" />
        <p><button class="button">Refresh policies from eBay</button></p>
    </form>

    <h2>2. Authorization</h2>
    <p><a class="button" href="<?php echo esc_url($connect_url); ?>">Connect eBay</a></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form>
    <p>Token expires at (unix): <?php echo esc_html((string) ($s['expires_at'] ?? '')); ?></p>

    <?php if (!empty($status['message'])): ?>
        <div class="notice <?php echo str_contains((string) $status['message'], '"result":"error"') ? 'notice-error' : 'notice-info'; ?>">
            <p><?php echo esc_html((string) $status['message']); ?></p>
        </div>
    <?php endif; ?>

    <h2>3. Readiness check</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run readiness check</button></form>

    <h2>4. Category Mapping (Woo/Allegro → eBay DE)</h2>
    <p>Auto-map scans WooCommerce product categories currently used by products, translates/normalizes the category query to German, and stores only confirmed high-confidence eBay DE leaf mappings automatically. Medium/low confidence rows remain blocked for export until reviewed.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 12px;">
        <?php wp_nonce_field('wei_auto_map_categories'); ?>
        <input type="hidden" name="action" value="wei_auto_map_categories" />
        <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
        <button class="button button-primary">Auto-map unmapped categories</button>
        <span class="description">Runs in WP Admin only; it does not export or publish offers.</span>
    </form>
    <table class="widefat striped">
        <thead><tr><th>Woo category</th><th>Products</th><th>Current eBay category</th><th>Source</th><th>Confidence</th><th>Status</th><th>Last updated</th><th>Error / best suggestion</th><th>Manual fallback</th></tr></thead>
        <tbody>
        <?php foreach ((array) ($category_mappings ?? []) as $row): ?>
            <?php
            $statusValue = (string) ($row['status'] ?? '');
            if ($statusValue === '') {
                $statusValue = empty($row['ebay_category_id']) ? 'unmapped' : 'mapped_manual';
            }
            $debug = json_decode((string) ($row['suggestion_payload'] ?? ''), true);
            $bestSuggestion = '';
            if (is_array($debug)) {
                $best = is_array($debug['best'] ?? null) ? $debug['best'] : [];
                $bestSuggestion = trim((string) (($best['category_id'] ?? '') . ' ' . ($best['category_path'] ?? $best['category_name'] ?? '')));
            }
            $statusColor = in_array($statusValue, ['mapped_manual', 'mapped_auto'], true) ? '#008a20' : (in_array($statusValue, ['needs_category_review'], true) ? '#996800' : '#b32d2e');
            ?>
            <tr>
                <td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td>
                <td><code><?php echo esc_html((string) ($row['ebay_category_id'] ?? '')); ?></code><br /><?php echo esc_html(trim((string) (($row['ebay_category_name'] ?? '') . ' ' . ($row['ebay_category_path'] ?? '')))); ?></td>
                <td><?php echo esc_html((string) ($row['source'] ?? '')); ?></td>
                <td><?php echo esc_html(isset($row['confidence']) ? number_format((float) $row['confidence'], 4) : ''); ?></td>
                <td><span style="color:<?php echo esc_attr($statusColor); ?>"><?php echo esc_html($statusValue); ?></span></td>
                <td><?php echo esc_html((string) ($row['updated_at'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($row['error_reason'] ?? '')); ?><?php if ($bestSuggestion !== ''): ?><br /><span class="description"><?php echo esc_html($bestSuggestion); ?></span><?php endif; ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wei_save_category_mapping'); ?>
                        <input type="hidden" name="action" value="wei_save_category_mapping" />
                        <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                        <input type="hidden" name="woo_term_id" value="<?php echo esc_attr((string) ($row['term_id'] ?? '0')); ?>" />
                        <input type="text" name="ebay_category_id" placeholder="179847" value="<?php echo esc_attr((string) ($row['ebay_category_id'] ?? '')); ?>" size="8" />
                        <input type="text" name="ebay_category_name" placeholder="eBay category name" value="<?php echo esc_attr((string) ($row['ebay_category_name'] ?? '')); ?>" />
                        <button class="button">Save fallback</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>5. Preflight / export actions</h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_export'); ?><input type="hidden" name="action" value="wei_export_product" />
        <p><input type="number" name="product_id" placeholder="Woo product ID" />
        <input type="text" name="ebay_category_id" placeholder="eBay category ID override (optional)" /></p>
        <p><textarea name="ebay_aspects_json" class="large-text code" rows="4" placeholder='{&quot;Hersteller&quot;:[&quot;SEAT&quot;]}'></textarea><br />
        <span class="description">Optional per-product eBay aspects JSON saved before export.</span></p>
        <button class="button button-primary">Preflight + export product</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_preflight'); ?><input type="hidden" name="action" value="wei_preflight_product" />
        <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Preflight only</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_sync'); ?><input type="hidden" name="action" value="wei_sync_stock" />
        <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Sync stock</button></form>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_import_order'); ?><input type="hidden" name="action" value="wei_import_order" /><button class="button">Import one eBay order</button></form>


    <h2>Debug</h2>
    <ul>
        <li><strong>Client ID:</strong> <code><?php echo esc_html((string) ($s['client_id'] ?? '')); ?></code></li>
        <li><strong>Environment:</strong> <code><?php echo esc_html((string) ($s['environment'] ?? 'production')); ?></code></li>
        <li><strong>Marketplace ID:</strong> <code><?php echo esc_html((string) ($s['marketplace_id'] ?? 'EBAY_DE')); ?></code></li>
        <li><strong>Default eBay Category ID:</strong> <code><?php echo esc_html((string) ($s['default_category_id'] ?? '')); ?></code></li>
        <li><strong>SKU Category Overrides:</strong> <code><?php echo esc_html((string) ($s['sku_category_overrides'] ?? '')); ?></code></li>
        <li><strong>SKU Aspect Overrides:</strong> <code><?php echo esc_html((string) ($s['sku_aspect_overrides'] ?? '')); ?></code></li>
        <li><strong>Translation Provider:</strong> <code><?php echo esc_html((string) ($s['translation_provider'] ?? 'disabled')); ?></code></li>
        <li><strong>Auto German Content:</strong> <code><?php echo !empty($s['auto_generate_german_content_preflight']) ? 'on' : 'off'; ?></code></li>
        <li><strong>Regenerate on Hash Change:</strong> <code><?php echo !empty($s['regenerate_german_content_on_hash_change']) ? 'on' : 'off'; ?></code></li>
        <li><strong>RuName:</strong> <code><?php echo esc_html((string) ($s['runame'] ?? '')); ?></code></li>
        <li><strong>Callback URL:</strong> <code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></li>
        <li><strong>Authorize URL:</strong> <code style="word-break:break-all"><?php echo esc_html($connect_url); ?></code></li>
    </ul>

    <h2>6. Logs</h2>
    <p>Last status: <?php echo esc_html(($status['at'] ?? '-') . ' ' . ($status['message'] ?? '')); ?></p>
    <ul>
        <?php foreach ((array) $logs as $log): ?>
            <li><?php echo esc_html(($log['at'] ?? '') . ' ' . ($log['message'] ?? '')); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
