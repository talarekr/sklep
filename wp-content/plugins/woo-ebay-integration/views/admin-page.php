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
        <p>Product Category Overrides (dev/debug):<br />
            <textarea name="product_category_overrides" class="large-text code" rows="3" placeholder="43582=179847"><?php echo esc_textarea((string) ($s['product_category_overrides'] ?? '')); ?></textarea><br />
            <span class="description">Dev/debug exception list: one product per line as product_id=categoryId. Saved to <code>_wei_ebay_category_*</code> meta with source <code>manual_product_override</code>; high-level exception review UI can replace this later.</span>
        </p>
        <details>
            <summary>Developer/debug SKU category fallback</summary>
            <p>SKU Category Overrides:<br />
                <textarea name="sku_category_overrides" class="large-text code" rows="3" placeholder="CFM-001=179847"><?php echo esc_textarea((string) ($s['sku_category_overrides'] ?? '')); ?></textarea><br />
                <span class="description">Legacy debug-only fallback. Do not use SKU as the durable category override mechanism because eBay SKU can differ from WooCommerce SKU.</span>
            </p>
        </details>
        <h3>Safe SKU / aspect defaults</h3>
        <p><label><input type="checkbox" name="use_woo_sku_for_ebay" value="1" disabled="disabled" /> Use WooCommerce SKU for eBay when present</label><br />
            <span class="description"><strong>Disabled.</strong> eBay uses only the plugin-owned <code>_wei_ebay_sku</code> value, generated automatically when missing. WooCommerce <code>_sku</code> is never changed.</span></p>
        <p>eBay-only SKU prefix: <input type="text" name="ebay_sku_prefix" value="<?php echo esc_attr((string) ($s['ebay_sku_prefix'] ?? 'GPSW')); ?>" class="regular-text" placeholder="GPSW" /><br />
            <span class="description">Generated format: <code>{prefix}-{product_id}</code>; future variants: <code>{prefix}-{product_id}-{variation_id}</code>. Stored in <code>_wei_ebay_sku</code> only.</span></p>
        <p><strong>WooCommerce SKU write-back:</strong> <code>disabled</code><br />
            <span class="description">For Allegro isolation this plugin never writes generated eBay SKU to WooCommerce <code>_sku</code>.</span></p>
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
    <?php
    $buildEbayCategoryUrl = static function (string $categoryId, array $categoryData = []): string {
        $preferredUrl = '';
        $urlKeys = ['categoryWebUrl', 'categoryUrl', 'webUrl', 'url'];
        foreach ($urlKeys as $urlKey) {
            if (isset($categoryData[$urlKey]) && is_scalar($categoryData[$urlKey])) {
                $preferredUrl = trim((string) $categoryData[$urlKey]);
                break;
            }
            if (isset($categoryData['raw_summary']) && is_array($categoryData['raw_summary']) && isset($categoryData['raw_summary'][$urlKey]) && is_scalar($categoryData['raw_summary'][$urlKey])) {
                $preferredUrl = trim((string) $categoryData['raw_summary'][$urlKey]);
                break;
            }
        }

        if ($preferredUrl !== '' && preg_match('#^https?://#i', $preferredUrl)) {
            return $preferredUrl;
        }

        return $categoryId !== '' ? 'https://www.ebay.de/b/' . rawurlencode($categoryId) : '';
    };
    ?>
    <table class="widefat striped">
        <thead><tr><th>Woo category</th><th>Products</th><th>eBay categoryId</th><th>eBay category name/path</th><th>eBay DE link</th><th>Source</th><th>Confidence</th><th>Status</th><th>Last updated</th><th>Error / best suggestion</th><th>Manual fallback</th></tr></thead>
        <tbody>
        <?php foreach ((array) ($category_mappings ?? []) as $row): ?>
            <?php
            $statusValue = (string) ($row['status'] ?? '');
            if ($statusValue === '') {
                $statusValue = empty($row['ebay_category_id']) ? 'unmapped' : 'mapped_manual';
            }
            $debug = json_decode((string) ($row['suggestion_payload'] ?? ''), true);
            $best = [];
            $bestSuggestion = '';
            if (is_array($debug)) {
                $best = is_array($debug['best'] ?? null) ? $debug['best'] : [];
                $bestSuggestion = trim((string) (($best['category_id'] ?? '') . ' ' . ($best['category_path'] ?? $best['category_name'] ?? '')));
            }
            $displayCategoryId = trim((string) ($row['ebay_category_id'] ?? ''));
            if ($displayCategoryId === '' && $statusValue === 'needs_category_review') {
                $displayCategoryId = trim((string) ($best['category_id'] ?? ''));
            }
            $displayCategoryPath = trim((string) (($row['ebay_category_name'] ?? '') . ' ' . ($row['ebay_category_path'] ?? '')));
            if ($displayCategoryPath === '' && $statusValue === 'needs_category_review') {
                $displayCategoryPath = trim((string) (($best['category_path'] ?? '') . ' ' . ($best['category_name'] ?? '')));
            }
            $categoryUrl = $buildEbayCategoryUrl($displayCategoryId, $best);
            $statusColor = in_array($statusValue, ['mapped_manual', 'mapped_auto'], true) ? '#008a20' : (in_array($statusValue, ['needs_category_review'], true) ? '#996800' : '#b32d2e');
            ?>
            <tr>
                <td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td>
                <td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td>
                <td><code><?php echo esc_html($displayCategoryId); ?></code></td>
                <td><?php echo esc_html($displayCategoryPath); ?></td>
                <td><?php if ($categoryUrl !== ''): ?><a href="<?php echo esc_url($categoryUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('Open on eBay DE'); ?></a><?php endif; ?></td>
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


    <h2>5. eBay-only SKU preparation</h2>
    <?php
    $skuActiveRun = is_array($ebay_sku_generation_status['active_run'] ?? null) ? $ebay_sku_generation_status['active_run'] : [];
    $skuLastRun = is_array($ebay_sku_generation_status['last_run'] ?? null) ? $ebay_sku_generation_status['last_run'] : [];
    $skuLastTotals = is_array($skuLastRun['totals'] ?? null) ? $skuLastRun['totals'] : [];
    $skuActiveTotals = is_array($skuActiveRun['totals'] ?? null) ? $skuActiveRun['totals'] : [];
    ?>
    <p class="description">Generates plugin-owned <code>_wei_ebay_sku</code> values only. WooCommerce <code>_sku</code> is never written, Allegro data is untouched, and no eBay publish/export is started.</p>
    <table class="widefat striped" style="max-width:900px">
        <tbody>
            <tr><th scope="row">Products total eligible</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_total_eligible'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Products with _wei_ebay_sku</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_with_wei_ebay_sku'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Products missing _wei_ebay_sku</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_missing_wei_ebay_sku'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Generated in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['generated_in_last_run'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Skipped existing in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['skipped_existing_in_last_run'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Conflicts in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['conflicts_in_last_run'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Errors in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['errors_in_last_run'] ?? '0')); ?></code></td></tr>
            <tr><th scope="row">Active run</th><td><code><?php echo esc_html((string) ($skuActiveRun['run_id'] ?? '-')); ?></code><?php if ($skuActiveRun): ?> processed <code><?php echo esc_html((string) ($skuActiveTotals['processed'] ?? '0')); ?></code>, remaining <code><?php echo esc_html((string) ($skuActiveRun['remaining_missing'] ?? '0')); ?></code><?php endif; ?></td></tr>
            <tr><th scope="row">Last run</th><td><code><?php echo esc_html((string) ($skuLastRun['run_id'] ?? '-')); ?></code><?php if ($skuLastRun): ?> processed <code><?php echo esc_html((string) ($skuLastTotals['processed'] ?? '0')); ?></code>, wrote_woo_sku <code>false</code><?php endif; ?></td></tr>
        </tbody>
    </table>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em">
        <?php wp_nonce_field('wei_generate_ebay_skus'); ?>
        <input type="hidden" name="action" value="wei_generate_ebay_skus" />
        <?php if (!empty($skuActiveRun['run_id'])): ?><input type="hidden" name="run_id" value="<?php echo esc_attr((string) $skuActiveRun['run_id']); ?>" /><?php endif; ?>
        <label>Batch size <input type="number" name="batch_size" value="200" min="1" max="500" /></label>
        <button class="button button-primary"><?php echo !empty($skuActiveRun['run_id']) ? 'Continue eBay SKU generation' : 'Generate missing eBay SKUs'; ?></button>
    </form>

    <h2>6. Preflight / export actions</h2>
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
        <li><strong>Use Woo SKU for eBay:</strong> <code><?php echo !empty($s['use_woo_sku_for_ebay']) ? 'on' : 'off'; ?></code></li>
        <li><strong>eBay-only SKU prefix:</strong> <code><?php echo esc_html((string) ($s['ebay_sku_prefix'] ?? 'GPSW')); ?></code></li>
        <li><strong>Products total eligible:</strong> <code><?php echo esc_html((string) ($ebay_sku_status['products_total_eligible'] ?? '0')); ?></code></li>
        <li><strong>Products missing _wei_ebay_sku:</strong> <code><?php echo esc_html((string) ($ebay_sku_status['products_missing_wei_ebay_sku'] ?? '0')); ?></code></li>
        <li><strong>Products with generated eBay SKU:</strong> <code><?php echo esc_html((string) ($ebay_sku_status['products_with_generated_ebay_sku'] ?? '0')); ?></code></li>
        <li><strong>Product Category Overrides (dev/debug):</strong> <code><?php echo esc_html((string) ($s['product_category_overrides'] ?? '')); ?></code></li>
        <li><strong>SKU Category Overrides (legacy/debug):</strong> <code><?php echo esc_html((string) ($s['sku_category_overrides'] ?? '')); ?></code></li>
        <li><strong>SKU Aspect Overrides:</strong> <code><?php echo esc_html((string) ($s['sku_aspect_overrides'] ?? '')); ?></code></li>
        <li><strong>Translation Provider:</strong> <code><?php echo esc_html((string) ($s['translation_provider'] ?? 'disabled')); ?></code></li>
        <li><strong>Auto German Content:</strong> <code><?php echo !empty($s['auto_generate_german_content_preflight']) ? 'on' : 'off'; ?></code></li>
        <li><strong>Regenerate on Hash Change:</strong> <code><?php echo !empty($s['regenerate_german_content_on_hash_change']) ? 'on' : 'off'; ?></code></li>
        <li><strong>RuName:</strong> <code><?php echo esc_html((string) ($s['runame'] ?? '')); ?></code></li>
        <li><strong>Callback URL:</strong> <code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></li>
        <li><strong>Authorize URL:</strong> <code style="word-break:break-all"><?php echo esc_html($connect_url); ?></code></li>
    </ul>

    <h2>7. Logs</h2>
    <p>Last status: <?php echo esc_html(($status['at'] ?? '-') . ' ' . ($status['message'] ?? '')); ?></p>
    <ul>
        <?php foreach ((array) $logs as $log): ?>
            <li><?php echo esc_html(($log['at'] ?? '') . ' ' . ($log['message'] ?? '')); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
