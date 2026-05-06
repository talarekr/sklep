<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;

class AdminPage
{
    public function __construct(private EbayAuth $auth, private EbayAdapter $adapter, private SyncService $syncService, private OrderImporter $orderImporter, private Logger $logger, private CategoryMappingRepository $categoryRepo, private AutoCategoryMappingService $autoCategoryMapper, private EbaySkuGenerator $skuGenerator, private EbayPriceResolver $priceResolver, private EbayTaxonomyService $taxonomy, private AutoSyncScheduler $scheduler)
    {
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_wei_save_settings', [$this, 'save_settings']);
        add_action('admin_post_wei_disconnect', [$this, 'disconnect']);
        add_action('admin_post_wei_test_connection', [$this, 'test_connection']);
        add_action('admin_post_wei_readiness', [$this, 'run_readiness']);
        add_action('admin_post_wei_export_product', [$this, 'export_product']);
        add_action('admin_post_wei_sync_stock', [$this, 'sync_stock']);
        add_action('admin_post_wei_import_order', [$this, 'import_order']);
        add_action('admin_post_wei_upsert_inventory_location', [$this, 'upsert_inventory_location']);
        add_action('admin_post_wei_refresh_policies', [$this, 'refresh_policies']);
        add_action('admin_post_wei_preflight_product', [$this, 'preflight_product']);
        add_action('admin_post_wei_save_category_mapping', [$this, 'save_category_mapping']);
        add_action('admin_post_wei_auto_map_categories', [$this, 'auto_map_categories']);
        add_action('admin_post_wei_generate_ebay_skus', [$this, 'generate_ebay_skus']);
        add_action('admin_post_wei_auto_sync_readiness_now', [$this, 'auto_sync_readiness_now']);
        add_action('admin_post_wei_auto_sync_orders_now', [$this, 'auto_sync_orders_now']);
        add_action('admin_post_wei_auto_sync_stock_now', [$this, 'auto_sync_stock_now']);
        add_action('admin_post_wei_auto_sync_export_now', [$this, 'auto_sync_export_now']);
        add_action('admin_post_wei_auto_sync_toggle_pause', [$this, 'auto_sync_toggle_pause']);
    }

    public function register_menu(): void
    {
        add_submenu_page('woocommerce', 'eBay Integration', 'eBay Integration', 'manage_options', 'woo-ebay', [$this, 'render']);
        add_submenu_page(null, 'eBay OAuth Callback', 'eBay OAuth Callback', 'manage_woocommerce', 'ebay-auth-callback', [$this, 'render_oauth_callback']);
    }

    public function render_oauth_callback(): void
    {
        // Callback is handled in WEI\Services\EbayAuth::handle_oauth_callback.
        wp_die('OAuth callback page.');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }
        $s = $this->settings();
        $status = get_option('wei_last_status', []);
        $logs = get_option('wei_logs', []);
        $category_mappings = $this->categoryRepo->list_used_woo_categories((string) ($s['marketplace_id'] ?? 'EBAY_DE'));
        $ebay_sku_status = $this->skuGenerator->status_counts();
        $ebay_sku_generation_status = $this->skuGenerator->current_status();
        $nbp_rate_status = $this->priceResolver->get_rate_status($s);
        $connect_url = $this->auth->get_authorize_url();
        $auto_sync_status = AutoSyncScheduler::status_summary();
        include WEI_PLUGIN_DIR . 'views/admin-page.php';
    }

    public function save_settings(): void
    {
        check_admin_referer('wei_save_settings');
        $s = $this->settings();
        $s['environment'] = in_array($_POST['environment'] ?? 'production', ['sandbox', 'production'], true) ? $_POST['environment'] : 'production';
        $s['client_id'] = sanitize_text_field((string) ($_POST['client_id'] ?? ''));
        $postedClientSecret = sanitize_text_field((string) ($_POST['client_secret'] ?? ''));
        if ($postedClientSecret !== '') {
            $s['client_secret'] = $postedClientSecret;
        }
        $s['runame'] = sanitize_text_field((string) ($_POST['runame'] ?? ''));
        $s['marketplace_id'] = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $s['default_category_id'] = sanitize_text_field((string) ($_POST['default_category_id'] ?? ''));
        $threshold = (float) ($_POST['auto_category_confidence_threshold'] ?? CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD);
        $s['auto_category_confidence_threshold'] = $threshold > 0 && $threshold <= 1 ? round($threshold, 4) : CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        $defaultMarkup = (float) ($_POST['ebay_default_markup_percent'] ?? 25);
        $specialMarkup = (float) ($_POST['ebay_special_category_markup_percent'] ?? 30);
        $nbpTtl = (float) ($_POST['nbp_rate_cache_ttl_hours'] ?? 12);
        $s['ebay_default_markup_percent'] = $defaultMarkup > 0 ? round($defaultMarkup, 4) : 25;
        $s['ebay_special_category_markup_percent'] = $specialMarkup > 0 ? round($specialMarkup, 4) : 30;
        $s['nbp_rate_cache_ttl_hours'] = $nbpTtl > 0 ? round($nbpTtl, 4) : 12;
        $s['sku_category_overrides'] = sanitize_textarea_field((string) ($_POST['sku_category_overrides'] ?? ''));
        $s['product_category_overrides'] = sanitize_textarea_field((string) ($_POST['product_category_overrides'] ?? ''));
        $s['sku_aspect_overrides'] = sanitize_textarea_field((string) ($_POST['sku_aspect_overrides'] ?? ''));
        $s['category_aspect_fallbacks'] = sanitize_textarea_field((string) ($_POST['category_aspect_fallbacks'] ?? ''));
        $s['default_hersteller_fallback'] = sanitize_text_field((string) ($_POST['default_hersteller_fallback'] ?? ''));
        $s['use_woo_sku_for_ebay'] = 0;
        $s['ebay_sku_prefix'] = $this->sanitize_ebay_sku_prefix((string) ($_POST['ebay_sku_prefix'] ?? 'GPSW'));
        $s['write_generated_sku_to_woo'] = 0;
        $s['stock_sync_mode'] = in_array(($_POST['stock_sync_mode'] ?? 'set_zero'), ['set_zero', 'reduce'], true) ? $_POST['stock_sync_mode'] : 'set_zero';
        $s['auto_sync_mode'] = in_array(($_POST['auto_sync_mode'] ?? 'disabled'), ['disabled', 'preflight_only', 'export_ready_products', 'orders_stock_only', 'full_sync'], true) ? $_POST['auto_sync_mode'] : 'disabled';
        $s['auto_sync_frequency'] = in_array(($_POST['auto_sync_frequency'] ?? 'hourly'), ['every_15_minutes', 'hourly', 'daily'], true) ? $_POST['auto_sync_frequency'] : 'hourly';
        $s['auto_sync_export_batch_size'] = max(1, min(50, absint($_POST['auto_sync_export_batch_size'] ?? 20)));
        $s['auto_sync_preflight_batch_size'] = max(1, min(300, absint($_POST['auto_sync_preflight_batch_size'] ?? 200)));
        $s['auto_sync_stock_batch_size'] = max(1, min(300, absint($_POST['auto_sync_stock_batch_size'] ?? 100)));
        $s['woo_to_ebay_stock_sync_enabled'] = !empty($_POST['woo_to_ebay_stock_sync_enabled']) ? 1 : 0;
        $s['ebay_order_sync_enabled'] = !empty($_POST['ebay_order_sync_enabled']) ? 1 : 0;
        $s['auto_export_enabled'] = !empty($_POST['auto_export_enabled']) ? 1 : 0;
        $s['auto_publish_enabled'] = !empty($_POST['auto_publish_enabled']) ? 1 : 0;
        $s['ebay_stock_sync_mode'] = in_array(($_POST['ebay_stock_sync_mode'] ?? 'max_one'), ['set_zero_only', 'max_one', 'exact_stock'], true) ? $_POST['ebay_stock_sync_mode'] : 'max_one';
        $s['ebay_order_stock_update_mode'] = in_array(($_POST['ebay_order_stock_update_mode'] ?? 'set_zero'), ['set_zero', 'reduce'], true) ? $_POST['ebay_order_stock_update_mode'] : 'set_zero';
        $provider = strtolower(sanitize_text_field((string) ($_POST['translation_provider'] ?? 'disabled')));
        if ($provider === 'google') {
            $provider = 'google_cloud_translate';
        }
        $postedTranslationApiKey = sanitize_text_field((string) ($_POST['translation_api_key'] ?? ''));
        if ($postedTranslationApiKey !== '') {
            $s['translation_api_key'] = $postedTranslationApiKey;
        }
        $s['translation_provider'] = in_array($provider, ['disabled', 'google_cloud_translate'], true) ? $provider : 'disabled';
        $s['auto_generate_german_content_preflight'] = !empty($_POST['auto_generate_german_content_preflight']) ? 1 : 0;
        $s['regenerate_german_content_on_hash_change'] = !empty($_POST['regenerate_german_content_on_hash_change']) ? 1 : 0;
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? 'gpswiss-pl'));
        $s['inventory_location_name'] = sanitize_text_field((string) ($_POST['inventory_location_name'] ?? 'gpswiss-pl'));
        $s['inventory_location_country'] = sanitize_text_field((string) ($_POST['inventory_location_country'] ?? 'PL'));
        $s['inventory_location_postal_code'] = sanitize_text_field((string) ($_POST['inventory_location_postal_code'] ?? '08-460'));
        $s['inventory_location_city'] = sanitize_text_field((string) ($_POST['inventory_location_city'] ?? 'Sobolew'));
        $s['inventory_location_address_line_1'] = sanitize_text_field((string) ($_POST['inventory_location_address_line_1'] ?? ''));
        $s['ebay_fulfillment_policy_id'] = sanitize_text_field((string) ($_POST['fulfillmentPolicyId'] ?? $_POST['ebay_fulfillment_policy_id'] ?? ''));
        $s['ebay_payment_policy_id'] = sanitize_text_field((string) ($_POST['paymentPolicyId'] ?? $_POST['ebay_payment_policy_id'] ?? ''));
        $s['ebay_return_policy_id'] = sanitize_text_field((string) ($_POST['returnPolicyId'] ?? $_POST['ebay_return_policy_id'] ?? ''));
        $this->sync_product_category_overrides($s['product_category_overrides']);
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&saved=1'));
        exit;
    }

    public function disconnect(): void { check_admin_referer('wei_disconnect'); $this->auth->disconnect(); $this->set_status('Disconnected'); $this->go(); }
    public function test_connection(): void { check_admin_referer('wei_test'); $res = $this->auth->get_valid_access_token(); $this->set_status(is_wp_error($res) ? 'Test failed: '.$res->get_error_message() : 'Connection OK'); $this->go(); }
    public function run_readiness(): void { check_admin_referer('wei_readiness'); $res = $this->adapter->readiness_check(); $this->set_status('Readiness: '.wp_json_encode($res)); $this->go(); }

    public function generate_ebay_skus(): void
    {
        check_admin_referer('wei_generate_ebay_skus');
        $batchSize = absint($_POST['batch_size'] ?? 200);
        $runId = sanitize_text_field((string) ($_POST['run_id'] ?? ''));
        $res = $this->skuGenerator->generate_missing_batch($runId !== '' ? $runId : null, $batchSize);
        $this->set_status('Generate missing eBay SKUs: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_map_categories(): void
    {
        check_admin_referer('wei_auto_map_categories');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $res = $this->autoCategoryMapper->auto_map_used_categories($marketplaceId, 200);
        $this->set_status('Auto category mapping: ' . wp_json_encode($res));
        $this->go();
    }

    public function export_product(): void
    {
        check_admin_referer('wei_export');
        $id = (int) ($_POST['product_id'] ?? 0);
        $category_id = sanitize_text_field((string) ($_POST['ebay_category_id'] ?? ''));
        $aspects_json = sanitize_textarea_field((string) ($_POST['ebay_aspects_json'] ?? ''));
        if ($id > 0 && $category_id !== '') {
            update_post_meta($id, '_wei_ebay_category_id', $category_id);
            update_post_meta($id, '_wei_ebay_category_source', 'manual_product_override');
            update_post_meta($id, '_wei_ebay_category_name', $this->static_category_name($category_id));
            update_post_meta($id, '_wei_ebay_category_path', $this->static_category_path($category_id));
        }
        if ($id > 0 && trim($aspects_json) !== '') {
            update_post_meta($id, '_wei_ebay_aspects_json', $aspects_json);
        }
        $res = $this->adapter->export_product($id);
        $this->set_status('Export: ' . wp_json_encode($res));
        $this->go();
    }

    public function sync_stock(): void
    {
        check_admin_referer('wei_sync');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $this->adapter->sync_stock($id);
        $this->set_status('Sync: ' . wp_json_encode($res));
        $this->go();
    }

    public function import_order(): void
    {
        check_admin_referer('wei_import_order');
        $res = $this->orderImporter->import_once();
        $this->set_status('Import order: ' . wp_json_encode($res));
        $this->go();
    }


    public function auto_sync_readiness_now(): void
    {
        check_admin_referer('wei_auto_sync_readiness_now');
        $res = $this->scheduler->run_readiness_scan(max(1, min(300, absint($_POST['batch_size'] ?? 200))));
        $this->set_status('Auto sync readiness scan: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_orders_now(): void
    {
        check_admin_referer('wei_auto_sync_orders_now');
        $res = $this->orderImporter->import_once();
        $this->set_status('Auto sync order import: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_stock_now(): void
    {
        check_admin_referer('wei_auto_sync_stock_now');
        $res = $this->scheduler->process_stock_queue(max(1, min(300, absint($_POST['batch_size'] ?? 100))));
        $this->set_status('Auto sync stock queue: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_export_now(): void
    {
        check_admin_referer('wei_auto_sync_export_now');
        $s = $this->settings();
        if (empty($s['auto_export_enabled'])) {
            $this->set_status('Auto sync export skipped: auto export disabled');
            $this->go();
        }
        $res = $this->scheduler->run_export_batch(max(1, min(50, absint($_POST['batch_size'] ?? 20))));
        $this->set_status('Auto sync export batch: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_toggle_pause(): void
    {
        check_admin_referer('wei_auto_sync_toggle_pause');
        $s = $this->settings();
        $s['auto_sync_paused'] = empty($s['auto_sync_paused']) ? 1 : 0;
        update_option(Plugin::OPTION_KEY, $s, false);
        $this->set_status(!empty($s['auto_sync_paused']) ? 'Auto sync paused' : 'Auto sync resumed');
        $this->go();
    }

    public function preflight_product(): void
    {
        check_admin_referer('wei_preflight');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $id > 0 ? $this->adapter->preflight_product($id) : ['result' => 'error', 'error' => 'missing_product_id'];
        $this->set_status('Preflight: ' . wp_json_encode($res));
        $this->go();
    }

    public function save_category_mapping(): void
    {
        check_admin_referer('wei_save_category_mapping');
        $termId = (int) ($_POST['woo_term_id'] ?? 0);
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $ebayCategoryId = sanitize_text_field((string) ($_POST['ebay_category_id'] ?? ''));
        if ($termId > 0 && $ebayCategoryId !== '') {
            $postedName = sanitize_text_field((string) ($_POST['ebay_category_name'] ?? ''));
            $postedPath = sanitize_text_field((string) ($_POST['ebay_category_path'] ?? ''));
            $details = ($postedName === '' || $postedPath === '') ? $this->taxonomy->get_category_details_result($marketplaceId, $ebayCategoryId) : [];
            $categoryName = $postedName !== '' ? $postedName : (string) ($details['category_name'] ?? 'unknown');
            $categoryPath = $postedPath !== '' ? $postedPath : (string) ($details['category_path'] ?? 'unknown');
            $this->categoryRepo->upsert([
                'marketplace_id' => $marketplaceId,
                'woo_term_id' => $termId,
                'woo_category_path' => $this->categoryRepo->woo_category_path($termId),
                'ebay_category_id' => $ebayCategoryId,
                'ebay_category_name' => $categoryName !== '' ? $categoryName : 'unknown',
                'ebay_category_path' => $categoryPath !== '' ? $categoryPath : 'unknown',
                'source' => 'manual',
                'confidence' => 1,
                'status' => 'mapped_manual',
                'error_reason' => '',
            ]);
            $this->set_status('Category mapping saved for Woo term ' . $termId . ' → eBay ' . $ebayCategoryId);
        } else {
            $this->set_status('Category mapping skipped: missing Woo term or eBay category ID');
        }
        $this->go();
    }

    public function upsert_inventory_location(): void
    {
        check_admin_referer('wei_upsert_inventory_location');
        $res = $this->adapter->upsert_inventory_location();
        $this->set_status('Inventory location: ' . wp_json_encode($res));
        $this->go();
    }

    public function refresh_policies(): void
    {
        check_admin_referer('wei_refresh_policies');
        $res = $this->adapter->refresh_policies();
        $this->set_status('Refresh policies: ' . wp_json_encode($res));
        $this->go();
    }

    private function go(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay'));
        exit;
    }

    private function set_status(string $message): void
    {
        update_option('wei_last_status', ['message' => $message, 'at' => gmdate('Y-m-d H:i:s')], false);
        $logs = get_option('wei_logs', []);
        array_unshift($logs, ['at' => gmdate('Y-m-d H:i:s'), 'message' => $message]);
        update_option('wei_logs', array_slice($logs, 0, 100), false);
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        $s = is_array($s) ? $s : [];
        if (empty($s['marketplace_id'])) {
            $s['marketplace_id'] = 'EBAY_DE';
        }
        if (empty($s['inventory_location_key'])) {
            $s['inventory_location_key'] = 'gpswiss-pl';
        }
        if (empty($s['inventory_location_name'])) {
            $s['inventory_location_name'] = 'gpswiss-pl';
        }
        if (empty($s['inventory_location_country'])) {
            $s['inventory_location_country'] = 'PL';
        }
        if (empty($s['inventory_location_postal_code'])) {
            $s['inventory_location_postal_code'] = '08-460';
        }
        if (empty($s['inventory_location_city'])) {
            $s['inventory_location_city'] = 'Sobolew';
        }
        if (!isset($s['translation_provider'])) {
            $s['translation_provider'] = 'disabled';
        } elseif ($s['translation_provider'] === 'google') {
            $s['translation_provider'] = 'google_cloud_translate';
        } elseif (!in_array((string) $s['translation_provider'], ['disabled', 'google_cloud_translate'], true)) {
            $s['translation_provider'] = 'disabled';
        }
        if (!isset($s['translation_api_key'])) {
            $s['translation_api_key'] = '';
        }
        if (!isset($s['auto_generate_german_content_preflight'])) {
            $s['auto_generate_german_content_preflight'] = 1;
        }
        if (!isset($s['auto_category_confidence_threshold'])) {
            $s['auto_category_confidence_threshold'] = CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        }
        if (!isset($s['regenerate_german_content_on_hash_change'])) {
            $s['regenerate_german_content_on_hash_change'] = 0;
        }
        if (!isset($s['inventory_location_address_line_1'])) {
            $s['inventory_location_address_line_1'] = '';
        }
        if (!isset($s['wei_cached_policies'])) {
            $s['wei_cached_policies'] = [];
        }
        if (!isset($s['sku_category_overrides'])) {
            $s['sku_category_overrides'] = "CFM-001=179847";
        }
        if (!isset($s['product_category_overrides'])) {
            $s['product_category_overrides'] = '';
        }
        if (!isset($s['sku_aspect_overrides'])) {
            $s['sku_aspect_overrides'] = wp_json_encode([
                'CFM-001' => [
                    'Hersteller' => ['SEAT'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if (!isset($s['category_aspect_fallbacks'])) {
            $s['category_aspect_fallbacks'] = "179847|Hersteller|SEAT";
        }
        if (!isset($s['default_hersteller_fallback'])) {
            $s['default_hersteller_fallback'] = '';
        }
        if (!isset($s['use_woo_sku_for_ebay'])) {
            $s['use_woo_sku_for_ebay'] = 0;
        }
        if (!isset($s['ebay_sku_prefix'])) {
            $s['ebay_sku_prefix'] = 'GPSW';
        }
        $s['write_generated_sku_to_woo'] = 0;
        if (!isset($s['stock_sync_mode'])) {
            $s['stock_sync_mode'] = 'set_zero';
        }
        if (!isset($s['auto_sync_mode'])) {
            $s['auto_sync_mode'] = 'disabled';
        }
        if (!isset($s['auto_sync_frequency'])) {
            $s['auto_sync_frequency'] = 'hourly';
        }
        if (!isset($s['auto_sync_export_batch_size'])) {
            $s['auto_sync_export_batch_size'] = 20;
        }
        if (!isset($s['auto_sync_preflight_batch_size'])) {
            $s['auto_sync_preflight_batch_size'] = 200;
        }
        if (!isset($s['auto_sync_stock_batch_size'])) {
            $s['auto_sync_stock_batch_size'] = 100;
        }
        if (!isset($s['woo_to_ebay_stock_sync_enabled'])) {
            $s['woo_to_ebay_stock_sync_enabled'] = 1;
        }
        if (!isset($s['ebay_order_sync_enabled'])) {
            $s['ebay_order_sync_enabled'] = 1;
        }
        if (!isset($s['auto_export_enabled'])) {
            $s['auto_export_enabled'] = 0;
        }
        if (!isset($s['auto_publish_enabled'])) {
            $s['auto_publish_enabled'] = 0;
        }
        if (!isset($s['ebay_stock_sync_mode'])) {
            $s['ebay_stock_sync_mode'] = 'max_one';
        }
        if (!isset($s['ebay_order_stock_update_mode'])) {
            $s['ebay_order_stock_update_mode'] = 'set_zero';
        }
        if (!isset($s['ebay_default_markup_percent'])) {
            $s['ebay_default_markup_percent'] = 25;
        }
        if (!isset($s['ebay_special_category_markup_percent'])) {
            $s['ebay_special_category_markup_percent'] = 30;
        }
        if (!isset($s['nbp_rate_cache_ttl_hours'])) {
            $s['nbp_rate_cache_ttl_hours'] = 12;
        }
        return $s;
    }


    private function sync_product_category_overrides(string $raw): int
    {
        $synced = 0;
        foreach ($this->parse_product_category_overrides($raw) as $productId => $categoryId) {
            if (get_post_type($productId) !== 'product') {
                continue;
            }

            update_post_meta($productId, '_wei_ebay_category_id', $categoryId);
            update_post_meta($productId, '_wei_ebay_category_source', 'manual_product_override');
            update_post_meta($productId, '_wei_ebay_category_name', $this->static_category_name($categoryId));
            update_post_meta($productId, '_wei_ebay_category_path', $this->static_category_path($categoryId));
            $synced++;
        }

        return $synced;
    }

    private function parse_product_category_overrides(string $raw): array
    {
        $overrides = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = preg_split('/\s*[=:,]\s*/', $line, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $productId = absint($parts[0]);
            $categoryId = trim((string) $parts[1]);
            if ($productId > 0 && $categoryId !== '') {
                $overrides[$productId] = $categoryId;
            }
        }

        return $overrides;
    }

    private function static_category_name(string $categoryId): string
    {
        if ($categoryId === '179847') {
            return 'Kabel, Kabelbäume & Steckverbinder';
        }

        return '';
    }

    private function static_category_path(string $categoryId): string
    {
        if ($categoryId === '179847') {
            return 'Auto & Motorrad: Teile > Autoteile & Zubehör > Kabel, Kabelbäume & Steckverbinder';
        }

        return '';
    }

    private function sanitize_ebay_sku_prefix(string $prefix): string
    {
        $prefix = trim($prefix);
        $prefix = preg_replace('/[^A-Za-z0-9._-]+/', '-', $prefix) ?: '';
        $prefix = trim($prefix, '-_.');
        return $prefix !== '' ? substr($prefix, 0, 20) : 'GPSW';
    }

}
