<?php

namespace GPS_Ebay_Fitment\Admin;

use GPS_Ebay_Fitment\Registry\MarketplaceRegistry;
use GPS_Ebay_Fitment\Repository\FitmentSyncRepository;
use GPS_Ebay_Fitment\Repository\TecDocCacheRepository;
use GPS_Ebay_Fitment\Service\ApifyTecDocLookupService;
use GPS_Ebay_Fitment\Service\ProductDiscoveryService;

class AdminPage
{
    private $registry;
    private $fitment_repository;
    private $cache_repository;
    private $discovery_service;
    private $lookup_service;
    private $notices = [];

    public function __construct(MarketplaceRegistry $registry, FitmentSyncRepository $fitment_repository, TecDocCacheRepository $cache_repository, ProductDiscoveryService $discovery_service, ApifyTecDocLookupService $lookup_service)
    {
        $this->registry = $registry;
        $this->fitment_repository = $fitment_repository;
        $this->cache_repository = $cache_repository;
        $this->discovery_service = $discovery_service;
        $this->lookup_service = $lookup_service;
    }

    public function hooks()
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_post']);
        add_action('admin_init', [$this, 'handle_export']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function admin_menu()
    {
        add_submenu_page('woocommerce', 'GPS eBay Fitment', 'GPS eBay Fitment', 'manage_woocommerce', 'gps-ebay-fitment-sync', [$this, 'render']);
    }

    public function register_settings()
    {
        register_setting('gps_ebay_fitment_apify', 'gps_ebay_fitment_apify_settings', [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($value)
    {
        $current = $this->lookup_service->settings();
        $token = isset($value['api_token']) ? trim((string) $value['api_token']) : '';
        if ($token === '********') {
            $token = $current['api_token'];
        }
        return [
            'enabled' => empty($value['enabled']) ? 0 : 1,
            'api_token' => $token,
            'actor_id' => isset($value['actor_id']) ? sanitize_text_field($value['actor_id']) : '',
            'lang_id' => isset($value['lang_id']) ? (int) $value['lang_id'] : 4,
            'country_id' => isset($value['country_id']) ? (int) $value['country_id'] : 63,
            'type_id' => isset($value['type_id']) ? (int) $value['type_id'] : 1,
            'timeout' => isset($value['timeout']) ? max(5, (int) $value['timeout']) : 60,
            'max_articles_per_part_number' => isset($value['max_articles_per_part_number']) ? max(1, (int) $value['max_articles_per_part_number']) : 10,
            'max_ktype_count_before_needs_review' => isset($value['max_ktype_count_before_needs_review']) ? max(1, (int) $value['max_ktype_count_before_needs_review']) : 200,
            'dry_run' => empty($value['dry_run']) ? 0 : 1,
            'cache_ttl_days' => isset($value['cache_ttl_days']) ? max(1, (int) $value['cache_ttl_days']) : 365,
        ];
    }

    public function handle_post()
    {
        if (empty($_POST['gps_ebay_fitment_action']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        check_admin_referer('gps_ebay_fitment_action');
        $action = sanitize_key($_POST['gps_ebay_fitment_action']);
        if ($action === 'scan') {
            $result = $this->discovery_service->scan([
                'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 100,
                'offset' => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
                'marketplace' => isset($_POST['marketplace']) ? sanitize_text_field($_POST['marketplace']) : 'all',
                'dry_run' => !empty($_POST['dry_run']),
            ]);
            set_transient('gps_ebay_fitment_last_result_' . get_current_user_id(), $result, 300);
            wp_safe_redirect($this->page_url(['gps_notice' => 'scan_complete']));
            exit;
        }
        if ($action === 'lookup_single') {
            $part_number = isset($_POST['test_part_number']) ? sanitize_text_field($_POST['test_part_number']) : '';
            $dry_run = !empty($_POST['dry_run']);
            $lookup = $this->lookup_service->lookup($part_number, [
                'force_refresh' => !empty($_POST['force_refresh']),
                'dry_run' => $dry_run,
            ]);
            set_transient('gps_ebay_fitment_last_result_' . get_current_user_id(), ['dry_run' => $dry_run, 'test_part_number' => $part_number, 'rows' => [['result' => $lookup]], 'count' => 1], 300);
            wp_safe_redirect($this->page_url(['gps_notice' => 'lookup_complete']));
            exit;
        }
        if ($action === 'lookup') {
            $dry_run = !empty($_POST['dry_run']);
            $rows = $this->fitment_repository->rows([
                'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 10,
                'offset' => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
                'only_missing_ktype' => !empty($_POST['only_missing_ktype']),
                'only_with_part_number' => !empty($_POST['only_with_part_number']),
            ]);
            $results = [];
            foreach ($rows as $row) {
                if (empty($row['part_number'])) {
                    continue;
                }
                $lookup = $this->lookup_service->lookup($row['part_number'], [
                    'force_refresh' => !empty($_POST['force_refresh']),
                    'dry_run' => $dry_run,
                ]);
                $this->fitment_repository->update_from_lookup($row['id'], $lookup, $dry_run);
                if (!$dry_run && in_array($lookup['status'], ['ready', 'needs_review'], true)) {
                    update_post_meta($row['product_id'], '_gps_fitment_ktype_list', wp_json_encode($lookup['ktype_list']));
                    update_post_meta($row['product_id'], '_gps_fitment_source', 'apify_tecdoc');
                    update_post_meta($row['product_id'], '_gps_fitment_status', $lookup['status']);
                    update_post_meta($row['product_id'], '_gps_fitment_confidence', $lookup['confidence']);
                    update_post_meta($row['product_id'], '_gps_fitment_ktype_count', $lookup['ktype_count']);
                }
                $results[] = ['row_id' => $row['id'], 'product_id' => $row['product_id'], 'result' => $lookup];
            }
            set_transient('gps_ebay_fitment_last_result_' . get_current_user_id(), ['dry_run' => $dry_run, 'rows' => $results, 'count' => count($results)], 300);
            wp_safe_redirect($this->page_url(['gps_notice' => 'lookup_complete']));
            exit;
        }
    }

    public function handle_export()
    {
        if (empty($_GET['gps_ebay_fitment_export']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        check_admin_referer('gps_ebay_fitment_export');
        $type = sanitize_key($_GET['gps_ebay_fitment_export']);
        $status_map = [
            'missing_part_number' => 'missing_part_number',
            'missing_ktype' => 'missing_ktype',
            'ready' => 'ready',
            'needs_review' => 'needs_review',
            'errors' => 'error',
        ];
        $args = ['limit' => 500, 'offset' => 0];
        if (isset($status_map[$type])) {
            $args['status'] = $status_map[$type];
        }
        $rows = $this->fitment_repository->rows($args);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gps-ebay-fitment-' . $type . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['product_id', 'sku', 'title', 'part_number', 'part_number_source', 'ktype_count', 'fitment_status', 'marketplace', 'plugin_key', 'inventory_sku', 'offer_id', 'listing_id', 'ebay_category_id', 'last_lookup_at', 'last_checked_at', 'error']);
        foreach ($rows as $row) {
            fputcsv($out, [$row['product_id'], $row['sku'], $row['post_title'], $row['part_number'], $row['part_number_source'], $row['ktype_count'], $row['fitment_status'], $row['marketplace'], $row['plugin_key'], $row['inventory_item_sku'], $row['offer_id'], $row['listing_id'], $row['ebay_category_id'], $row['last_lookup_at'], $row['last_checked_at'], $row['last_error']]);
        }
        exit;
    }

    public function render()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gps-ebay-fitment-sync'));
        }
        $overview = $this->fitment_repository->overview();
        $settings = $this->lookup_service->settings();
        $last_result = get_transient('gps_ebay_fitment_last_result_' . get_current_user_id());
        $rows = $this->fitment_repository->rows(['limit' => 50]);
        ?>
        <div class="wrap">
            <h1>GPS eBay Fitment Sync</h1>
            <p><strong>Safety:</strong> this plugin prepares KType data only. It never sends compatibility, listing, price, stock, or image updates to eBay.</p>

            <h2>Overview</h2>
            <p>Total eBay mapped fitment rows: <strong><?php echo esc_html($overview['total']); ?></strong></p>
            <h3>Rows per marketplace</h3>
            <?php $this->render_counts_table($overview['by_marketplace'], 'marketplace'); ?>
            <h3>Status counts</h3>
            <?php $this->render_counts_table($overview['by_status'], 'fitment_status'); ?>

            <h2>Apify TecDoc settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields('gps_ebay_fitment_apify'); ?>
                <table class="form-table" role="presentation">
                    <tr><th>Enabled</th><td><label><input type="checkbox" name="gps_ebay_fitment_apify_settings[enabled]" value="1" <?php checked($settings['enabled']); ?>> Enable Apify lookup</label></td></tr>
                    <tr><th>API token</th><td><input type="password" class="regular-text" name="gps_ebay_fitment_apify_settings[api_token]" value="<?php echo esc_attr($settings['api_token'] ? '********' : ''); ?>" autocomplete="new-password"><p class="description">Stored in the option gps_ebay_fitment_apify_settings. Full token is never printed.</p></td></tr>
                    <tr><th>Actor ID</th><td><input type="text" class="regular-text" name="gps_ebay_fitment_apify_settings[actor_id]" value="<?php echo esc_attr($settings['actor_id']); ?>"></td></tr>
                    <tr><th>Lang / country / type</th><td><input type="number" name="gps_ebay_fitment_apify_settings[lang_id]" value="<?php echo esc_attr($settings['lang_id']); ?>"> <input type="number" name="gps_ebay_fitment_apify_settings[country_id]" value="<?php echo esc_attr($settings['country_id']); ?>"> <input type="number" name="gps_ebay_fitment_apify_settings[type_id]" value="<?php echo esc_attr($settings['type_id']); ?>"></td></tr>
                    <tr><th>Limits</th><td>Timeout <input type="number" name="gps_ebay_fitment_apify_settings[timeout]" value="<?php echo esc_attr($settings['timeout']); ?>"> seconds; max articles <input type="number" name="gps_ebay_fitment_apify_settings[max_articles_per_part_number]" value="<?php echo esc_attr($settings['max_articles_per_part_number']); ?>">; needs review above KTypes <input type="number" name="gps_ebay_fitment_apify_settings[max_ktype_count_before_needs_review]" value="<?php echo esc_attr($settings['max_ktype_count_before_needs_review']); ?>"></td></tr>
                    <tr><th>Dry-run / cache</th><td><label><input type="checkbox" name="gps_ebay_fitment_apify_settings[dry_run]" value="1" <?php checked($settings['dry_run']); ?>> Dry-run by default</label>; cache TTL days <input type="number" name="gps_ebay_fitment_apify_settings[cache_ttl_days]" value="<?php echo esc_attr($settings['cache_ttl_days']); ?>"></td></tr>
                </table>
                <?php submit_button('Save Apify settings'); ?>
            </form>

            <h2>Scan / prepare</h2>
            <form method="post">
                <?php wp_nonce_field('gps_ebay_fitment_action'); ?>
                <input type="hidden" name="gps_ebay_fitment_action" value="scan">
                <?php $this->render_batch_fields(100); ?>
                <label>Marketplace <?php $this->render_marketplace_select(); ?></label>
                <label><input type="checkbox" name="only_missing_rows" value="1" checked> only missing rows</label>
                <label><input type="checkbox" name="only_missing_part_number" value="1"> only missing part number</label>
                <label><input type="checkbox" name="only_missing_ktype" value="1"> only missing KType</label>
                <label><input type="checkbox" name="only_ready" value="1"> only ready</label>
                <label><input type="checkbox" name="include_ended" value="1"> include ended/sold listings</label>
                <label><input type="checkbox" name="dry_run" value="1" checked> dry-run</label>
                <?php submit_button('Scan eBay-mapped products and prepare fitment rows'); ?>
            </form>

            <h2>Apify TecDoc lookup</h2>
            <form method="post" style="margin-bottom:1em">
                <?php wp_nonce_field('gps_ebay_fitment_action'); ?>
                <input type="hidden" name="gps_ebay_fitment_action" value="lookup_single">
                <label>Test part number <input type="text" name="test_part_number" value="1T0941329A"></label>
                <label><input type="checkbox" name="force_refresh" value="1"> force refresh</label>
                <label><input type="checkbox" name="dry_run" value="1" checked> dry-run</label>
                <?php submit_button('Test one Apify TecDoc lookup', 'secondary', 'submit', false); ?>
            </form>
            <form method="post">
                <?php wp_nonce_field('gps_ebay_fitment_action'); ?>
                <input type="hidden" name="gps_ebay_fitment_action" value="lookup">
                <?php $this->render_batch_fields(10); ?>
                <label><input type="checkbox" name="force_refresh" value="1"> force refresh</label>
                <label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run']); ?>> dry-run</label>
                <label><input type="checkbox" name="only_missing_ktype" value="1" checked> only missing KType</label>
                <label><input type="checkbox" name="only_with_part_number" value="1" checked> only with part number</label>
                <?php submit_button('Run Apify TecDoc lookup for missing KType'); ?>
            </form>

            <?php if ($last_result): ?>
                <h2>Last batch result</h2>
                <pre style="max-height:320px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;"><?php echo esc_html(wp_json_encode($last_result, JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>

            <h2>CSV export</h2>
            <p>
                <?php foreach (['full_report', 'missing_part_number', 'missing_ktype', 'ready', 'needs_review', 'errors'] as $export): ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url($this->page_url(['gps_ebay_fitment_export' => $export]), 'gps_ebay_fitment_export')); ?>"><?php echo esc_html(str_replace('_', ' ', $export)); ?></a>
                <?php endforeach; ?>
            </p>

            <h2>Results table</h2>
            <table class="widefat striped">
                <thead><tr><th>product ID</th><th>SKU</th><th>title</th><th>part number</th><th>source</th><th>KType count</th><th>status</th><th>marketplace</th><th>plugin key</th><th>inventory SKU</th><th>offer ID</th><th>listing ID</th><th>category</th><th>last lookup</th><th>last checked</th><th>error</th><th>technical details</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php echo esc_html($row['product_id']); ?></td><td><?php echo esc_html($row['sku']); ?></td><td><?php echo esc_html($row['post_title']); ?></td><td><?php echo esc_html($row['part_number']); ?></td><td><?php echo esc_html($row['part_number_source']); ?></td><td><?php echo esc_html($row['ktype_count']); ?></td><td><?php echo esc_html($row['fitment_status']); ?></td><td><?php echo esc_html($row['marketplace']); ?></td><td><?php echo esc_html($row['plugin_key']); ?></td><td><?php echo esc_html($row['inventory_item_sku']); ?></td><td><?php echo esc_html($row['offer_id']); ?></td><td><?php echo esc_html($row['listing_id']); ?></td><td><?php echo esc_html($row['ebay_category_id']); ?></td><td><?php echo esc_html($row['last_lookup_at']); ?></td><td><?php echo esc_html($row['last_checked_at']); ?></td><td><?php echo esc_html($row['last_error']); ?></td><td><?php echo esc_html(substr($row['raw_response_excerpt'], 0, 250)); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_batch_fields($default_limit)
    {
        echo '<label>Limit <input type="number" name="limit" value="' . esc_attr($default_limit) . '" min="1" max="500"></label> ';
        echo '<label>Offset <input type="number" name="offset" value="0" min="0"></label> ';
    }

    private function render_marketplace_select()
    {
        echo '<select name="marketplace"><option value="all">all</option>';
        foreach ($this->registry->enabled() as $id => $config) {
            echo '<option value="' . esc_attr($id) . '">' . esc_html($id . ' - ' . $config['label']) . '</option>';
        }
        echo '</select>';
    }

    private function render_counts_table($rows, $label_key)
    {
        echo '<table class="widefat striped" style="max-width:520px"><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row[$label_key]) . '</td><td>' . esc_html($row['total']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function page_url($args = [])
    {
        return add_query_arg(array_merge(['page' => 'gps-ebay-fitment-sync'], $args), admin_url('admin.php'));
    }
}
