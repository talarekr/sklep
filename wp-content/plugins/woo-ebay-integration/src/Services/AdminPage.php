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
        add_action('admin_init', [$this, 'log_build_loaded']);
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
        add_action('admin_post_wei_publish_product_offer_only', [$this, 'publish_product_offer_only']);
        add_action('admin_post_wei_verify_api_publishing_readiness', [$this, 'verify_api_publishing_readiness']);
        add_action('admin_post_wei_save_category_mapping', [$this, 'save_category_mapping']);
        add_action('admin_post_wei_auto_map_categories', [$this, 'auto_map_categories']);
        add_action('admin_post_wei_repair_blocked_category_mappings', [$this, 'repair_blocked_category_mappings']);
        add_action('admin_post_wei_repair_audit_category_groups', [$this, 'repair_audit_category_groups']);
        add_action('admin_post_wei_apply_manual_woo_category_mappings', [$this, 'apply_manual_woo_category_mappings']);
        add_action('admin_post_wei_export_category_teaching_csv', [$this, 'export_category_teaching_csv']);
        add_action('admin_post_wei_import_category_teaching_csv', [$this, 'import_category_teaching_csv']);
        add_action('admin_post_wei_test_category_teaching_rule_match', [$this, 'test_category_teaching_rule_match']);
        add_action('admin_post_wei_generate_missing_german_content_audit', [$this, 'generate_missing_german_content_audit']);
        add_action('admin_post_wei_generate_ebay_skus', [$this, 'generate_ebay_skus']);
        add_action('admin_post_wei_auto_sync_readiness_now', [$this, 'auto_sync_readiness_now']);
        add_action('admin_post_wei_full_category_audit', [$this, 'full_category_audit']);
        add_action('admin_post_wei_auto_sync_orders_now', [$this, 'auto_sync_orders_now']);
        add_action('admin_post_wei_auto_sync_stock_now', [$this, 'auto_sync_stock_now']);
        add_action('admin_post_wei_auto_sync_export_now', [$this, 'auto_sync_export_now']);
        add_action('admin_post_wei_sync_prices_only', [$this, 'sync_prices_only']);
        add_action('admin_post_wei_sync_content_only', [$this, 'sync_content_only']);
        add_action('admin_post_wei_sync_categories_only', [$this, 'sync_categories_only']);
        add_action('admin_post_wei_sync_listing_meta_back', [$this, 'sync_listing_meta_back']);
        add_action('admin_post_wei_sync_ebay_stock_to_woo', [$this, 'sync_ebay_stock_to_woo']);
        add_action('admin_post_wei_auto_sync_toggle_pause', [$this, 'auto_sync_toggle_pause']);
        add_action('admin_post_wei_ebay_sync_now', [$this, 'ebay_sync_now']);
        add_action('admin_post_wei_ebay_process_queue_now', [$this, 'ebay_process_queue_now']);
        add_action('admin_post_wei_ebay_rebuild_ready_queue', [$this, 'ebay_rebuild_ready_queue']);
        add_action('admin_post_wei_ebay_initial_publish_batch', [$this, 'ebay_initial_publish_batch']);
        add_action('admin_post_wei_ebay_rebuild_initial_publish_candidates', [$this, 'ebay_rebuild_initial_publish_candidates']);
        add_action('admin_post_wei_ebay_initial_publish_toggle_pause', [$this, 'ebay_initial_publish_toggle_pause']);
        add_action('admin_post_wei_ebay_initial_publish_reset', [$this, 'ebay_initial_publish_reset']);
    }

    public function register_menu(): void
    {
        $this->log_build_loaded();

        $this->add_traced_submenu_page('woocommerce', 'eBay Integration', 'eBay Integration', 'manage_options', 'woo-ebay', [$this, 'render'], 'main admin menu');

        // WordPress normalizes both parent and menu slugs through plugin_basename().
        // Passing null as the parent slug for a hidden callback page reaches
        // wp_normalize_path(null), which emits PHP 8.1+ deprecations from
        // wp_is_stream()/str_replace() in wp-includes/functions.php. Register
        // under WooCommerce with a real slug, then remove the submenu entry so
        // the callback remains hidden without sending null into WordPress core.
        $this->add_traced_submenu_page('woocommerce', 'eBay OAuth Callback', 'eBay OAuth Callback', 'manage_woocommerce', 'ebay-auth-callback', [$this, 'render_oauth_callback'], 'oauth callback menu');
        remove_submenu_page('woocommerce', 'ebay-auth-callback');
    }


    /**
     * Registers submenu pages with production-safe diagnostics for values that
     * WordPress later normalizes via plugin_basename()/wp_normalize_path().
     *
     * @param callable $callback
     */
    private function add_traced_submenu_page($parentSlug, $pageTitle, $menuTitle, $capability, $menuSlug, $callback, string $section): void
    {
        $normalizedParentSlug = $this->safe_admin_slug($parentSlug, 'woocommerce', $section, 'parent_slug');
        $normalizedPageTitle = $this->safe_admin_label($pageTitle, $section, 'page_title');
        $normalizedMenuTitle = $this->safe_admin_label($menuTitle, $section, 'menu_title');
        $normalizedCapability = $this->safe_admin_slug($capability, 'manage_options', $section, 'capability');
        $normalizedMenuSlug = $this->safe_admin_slug($menuSlug, 'woo-ebay', $section, 'menu_slug');

        $this->log_admin_menu_register('add_submenu_page', $section, [
            'parent_slug' => $parentSlug,
            'menu_slug' => $menuSlug,
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'callback' => $callback,
            'normalized_parent_slug' => $normalizedParentSlug,
            'normalized_menu_slug' => $normalizedMenuSlug,
        ]);

        add_submenu_page(
            $normalizedParentSlug,
            $normalizedPageTitle,
            $normalizedMenuTitle,
            $normalizedCapability,
            $normalizedMenuSlug,
            $callback
        );
    }

    private function safe_admin_slug($value, string $fallback, string $section, string $field): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        $this->log_admin_render_diagnostic($section, $field, __METHOD__, $value);
        return $fallback;
    }

    private function safe_admin_label($value, string $section, string $field): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        $this->log_admin_render_diagnostic($section, $field, __METHOD__, $value);
        return '';
    }

    public function log_build_loaded(): void
    {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;

        error_log('WEI_BUILD_LOADED ' . $this->encode_log_context([
            'commit' => defined('WEI_BUILD_COMMIT') ? WEI_BUILD_COMMIT : 'unknown',
            'build' => defined('WEI_BUILD_ID') ? WEI_BUILD_ID : 'unknown',
            'plugin_file' => defined('WEI_PLUGIN_FILE') ? WEI_PLUGIN_FILE : '',
            'admin_page_sha1' => sha1_file(__FILE__) ?: '',
            'backtrace' => $this->backtrace_summary(),
        ]));
    }

    /**
     * @param callable $callback
     */
    private function log_admin_menu_register(string $function, string $section, array $fields): void
    {
        $parentSlug = $fields['parent_slug'] ?? null;
        $menuSlug = $fields['menu_slug'] ?? null;
        $context = [
            'function' => $function,
            'section' => $section,
            'parent_slug' => $this->stringify_log_value($parentSlug),
            'menu_slug' => $this->stringify_log_value($menuSlug),
            'page_title' => $this->stringify_log_value($fields['page_title'] ?? null),
            'menu_title' => $this->stringify_log_value($fields['menu_title'] ?? null),
            'callback' => $this->describe_callback($fields['callback'] ?? null),
            'normalized_parent_slug' => $this->stringify_log_value($fields['normalized_parent_slug'] ?? null),
            'normalized_menu_slug' => $this->stringify_log_value($fields['normalized_menu_slug'] ?? null),
            'backtrace' => $this->backtrace_summary(),
        ];

        error_log('WEI_ADMIN_MENU_REGISTER ' . $this->encode_log_context($context));

        if ($this->is_empty_slug($parentSlug) || $this->is_empty_slug($menuSlug)) {
            error_log('WEI_ADMIN_MENU_NULL_SLUG_DETECTED ' . $this->encode_log_context($context + [
                'parent_slug_type' => get_debug_type($parentSlug),
                'menu_slug_type' => get_debug_type($menuSlug),
            ]));
        }
    }

    private function is_empty_slug($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return trim((string) $value) === '';
        }

        return true;
    }

    private function describe_callback($callback): string
    {
        if (is_array($callback) && count($callback) === 2) {
            $target = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $target . '::' . (string) $callback[1];
        }

        if ($callback instanceof \Closure) {
            return 'Closure';
        }

        if (is_string($callback)) {
            return $callback;
        }

        return get_debug_type($callback);
    }

    private function stringify_log_value($value)
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return get_debug_type($value);
    }

    private function backtrace_summary()
    {
        return function_exists('wp_debug_backtrace_summary')
            ? wp_debug_backtrace_summary(null, 0, false)
            : debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    }

    private function encode_log_context(array $context): string
    {
        $json = function_exists('wp_json_encode')
            ? wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
            : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{}';
    }

    private function log_admin_render_diagnostic(string $section, string $field, string $renderer, $value): void
    {
        $summary = $this->backtrace_summary();

        error_log('WEI admin render diagnostic: ' . $this->encode_log_context([
            'section' => $section,
            'field' => $field,
            'renderer' => $renderer,
            'value_type' => get_debug_type($value),
            'value_before_render' => is_scalar($value) || $value === null ? $value : get_debug_type($value),
            'backtrace' => $summary,
        ]));
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
        $status = is_array($status) ? $status : [];
        $logs = get_option('wei_logs', []);
        $logs = array_slice(is_array($logs) ? $logs : [], 0, 20);
        $admin_section = isset($_GET['wei_section']) ? sanitize_key(wp_unslash((string) $_GET['wei_section'])) : '';
        $load_category_mapping_rows = $admin_section === 'category-mappings'
            || isset($_GET['category_status'])
            || isset($_GET['category_sort']);
        $load_product_sync_rows = $admin_section === 'product-sync';

        // Keep the default admin page render light. The rebuilt UI originally
        // executed product-wide SKU counts, product meta queries and external
        // exchange-rate refreshes while WordPress was only trying to render the
        // page. Heavy/diagnostic data is now loaded only from explicit links.
        $category_mappings = $load_category_mapping_rows
            ? $this->categoryRepo->list_used_woo_categories((string) ($s['marketplace_id'] ?? 'EBAY_DE'), 50)
            : [];
        $ebay_sku_status = $this->light_ebay_sku_status();
        $ebay_sku_generation_status = $this->skuGenerator->current_status();
        $nbp_rate_status = $this->cached_nbp_rate_status();
        $connect_url = (string) $this->auth->get_authorize_url();
        $auto_sync_status = $this->light_auto_sync_status($s);
        $initial_publish_candidate_summary = $this->initial_publish_candidate_summary();
        $initial_publish_status = $this->initial_publish_status();
        $full_category_audit_summary = get_option('wei_ebay_full_category_audit_summary', []);
        $full_category_audit_summary = is_array($full_category_audit_summary) ? $full_category_audit_summary : [];
        $german_content_audit_summary = get_option('wei_ebay_german_content_audit_summary', []);
        $german_content_audit_summary = is_array($german_content_audit_summary) ? $german_content_audit_summary : [];
        $category_group_repair_summary = get_option('wei_ebay_category_mapping_repair_audit_group_report', []);
        $category_group_repair_summary = is_array($category_group_repair_summary) ? $category_group_repair_summary : [];
        $manual_woo_category_apply_summary = get_option('wei_ebay_manual_woo_category_mapping_apply_report', []);
        $manual_woo_category_apply_summary = is_array($manual_woo_category_apply_summary) ? $manual_woo_category_apply_summary : [];
        $category_teaching_export_summary = get_option('wei_ebay_category_mapping_teaching_export', []);
        $category_teaching_export_summary = is_array($category_teaching_export_summary) ? $category_teaching_export_summary : [];
        $category_teaching_import_summary = get_option('wei_ebay_category_mapping_teaching_import', []);
        $category_teaching_import_summary = is_array($category_teaching_import_summary) ? $category_teaching_import_summary : [];
        $category_teaching_match_diagnostic = get_option('wei_ebay_category_mapping_teaching_match_diagnostic', []);
        $category_teaching_match_diagnostic = is_array($category_teaching_match_diagnostic) ? $category_teaching_match_diagnostic : [];
        $product_sync_status_rows = $load_product_sync_rows ? $this->recent_product_sync_status_rows() : [];
        include WEI_PLUGIN_DIR . 'views/admin-page.php';
    }

    public function save_settings(): void
    {
        $this->require_manage_options();
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
        $defaultItemCondition = strtoupper(sanitize_text_field((string) ($_POST['default_item_condition'] ?? EbayConditionResolver::DEFAULT_ITEM_CONDITION)));
        $s['default_item_condition'] = $defaultItemCondition !== '' ? $defaultItemCondition : EbayConditionResolver::DEFAULT_ITEM_CONDITION;
        $threshold = (float) ($_POST['auto_category_confidence_threshold'] ?? CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD);
        $s['auto_category_confidence_threshold'] = $threshold > 0 && $threshold <= 1 ? round($threshold, 4) : CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        $defaultMarkup = (float) ($_POST['ebay_default_markup_percent'] ?? 25);
        $specialMarkup = (float) ($_POST['ebay_special_category_markup_percent'] ?? 30);
        $nbpTtl = (float) ($_POST['nbp_rate_cache_ttl_hours'] ?? 12);
        $s['ebay_default_markup_percent'] = $defaultMarkup > 0 ? round($defaultMarkup, 4) : 25;
        $s['ebay_special_category_markup_percent'] = $specialMarkup > 0 ? round($specialMarkup, 4) : 30;
        $s['nbp_rate_cache_ttl_hours'] = $nbpTtl > 0 ? round($nbpTtl, 4) : 12;
        $s['sku_category_overrides'] = sanitize_textarea_field((string) ($_POST['sku_category_overrides'] ?? ''));
        $s['verbose_debug'] = !empty($_POST['verbose_debug']) ? 1 : 0;
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

    public function disconnect(): void { $this->require_manage_options(); check_admin_referer('wei_disconnect'); $this->auth->disconnect(); $this->set_status('Disconnected'); $this->go(); }
    public function test_connection(): void { $this->require_manage_options(); check_admin_referer('wei_test'); $res = $this->auth->get_valid_access_token(); $this->set_status(is_wp_error($res) ? 'Test failed: '.$res->get_error_message() : 'Connection OK'); $this->go(); }
    public function run_readiness(): void { $this->require_manage_options(); check_admin_referer('wei_readiness'); $res = $this->adapter->readiness_check(); $this->set_status('Readiness: '.wp_json_encode($res)); $this->go(); }

    public function generate_ebay_skus(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_ebay_skus');
        $batchSize = absint($_POST['batch_size'] ?? 200);
        $runId = sanitize_text_field((string) ($_POST['run_id'] ?? ''));
        $res = $this->skuGenerator->generate_missing_batch($runId !== '' ? $runId : null, $batchSize);
        $this->set_status('Generate missing eBay SKUs: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_map_categories(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_auto_map_categories');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $res = $this->autoCategoryMapper->auto_map_used_categories($marketplaceId, 200);
        $this->set_status('Auto category mapping: ' . wp_json_encode($res));
        $this->go();
    }


    public function repair_blocked_category_mappings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_repair_blocked_category_mappings');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $readiness = get_option('wei_ebay_readiness_summary', []);
        $productIds = is_array($readiness) ? array_values(array_map('intval', (array) ($readiness['blocked_by_category_sample_ids'] ?? []))) : [];
        $res = $this->autoCategoryMapper->repair_blocked_category_mappings($productIds, $marketplaceId, 200);
        $this->set_status('Category mapping repair: ' . wp_json_encode([
            'processed' => (int) ($res['processed'] ?? 0),
            'fixed_count' => (int) ($res['fixed_count'] ?? 0),
            'still_blocked_count' => (int) ($res['still_blocked_count'] ?? 0),
            'top_block_reasons' => (array) ($res['top_block_reasons'] ?? []),
        ]));
        $this->go();
    }

    public function apply_manual_woo_category_mappings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_apply_manual_woo_category_mappings');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $res = $this->autoCategoryMapper->apply_manual_woo_category_mappings_to_all_products($marketplaceId);
        $this->set_status('Manual Woo category mappings apply: ' . wp_json_encode([
            'manual_rules_loaded' => (int) ($res['manual_rules_loaded'] ?? 0),
            'woo_categories_matched' => (int) ($res['woo_categories_matched'] ?? 0),
            'products_scanned' => (int) ($res['products_scanned'] ?? 0),
            'products_matching_manual_categories' => (int) ($res['products_matching_manual_categories'] ?? 0),
            'mappings_written' => (int) ($res['mappings_written'] ?? 0),
            'already_mapped' => (int) ($res['already_mapped'] ?? 0),
            'skipped_by_hard_safety' => (int) ($res['skipped_by_hard_safety'] ?? 0),
            'top_hard_safety_reasons' => (array) ($res['top_hard_safety_reasons'] ?? []),
            'skipped_sample_rows' => (array) ($res['skipped_sample_rows'] ?? []),
            'errors_sample' => (array) ($res['errors_sample'] ?? []),
        ]));
        $this->go();
    }

    public function repair_audit_category_groups(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_repair_audit_category_groups');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $path = $this->latest_audit_report_path('problems_only_csv');
        if ($path === '') {
            $this->set_status('Audit category group repair failed: problems CSV not found. Run the full category audit first.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->repair_blocked_category_mappings_from_audit_problem_groups($path, $marketplaceId);
        $this->set_status('Audit category group repair: ' . wp_json_encode([
            'processed' => (int) ($res['processed'] ?? 0),
            'fixed_count' => (int) ($res['fixed_count'] ?? 0),
            'still_blocked_count' => (int) ($res['still_blocked_count'] ?? 0),
            'groups' => (array) ($res['groups'] ?? []),
            'reports' => (array) ($res['reports'] ?? []),
        ]));
        $this->go();
    }


    public function export_category_teaching_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_export_category_teaching_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $path = $this->latest_audit_report_path('problems_only_csv');
        if ($path === '') {
            $this->set_status('Category teaching export failed: problems CSV not found. Run the full category audit first.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->export_category_mapping_teaching_csv($path, $marketplaceId);
        $this->set_status('Category teaching export: ' . wp_json_encode([
            'groups_exported' => (int) ($res['groups_exported'] ?? 0),
            'reports' => (array) ($res['reports'] ?? []),
        ]));
        $this->go();
    }

    public function import_category_teaching_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_import_category_teaching_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $file = is_array($_FILES['teaching_csv'] ?? null) ? $_FILES['teaching_csv'] : [];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->set_status('Category teaching import failed: upload a filled teaching CSV.');
            $this->go();
        }
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $dest = trailingslashit($baseDir) . 'wei-ebay-category-teaching-import-' . gmdate('Ymd-His') . '.csv';
        if (!move_uploaded_file($tmp, $dest)) {
            $this->set_status('Category teaching import failed: could not store uploaded CSV.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->import_category_mapping_teaching_csv($dest, $marketplaceId);
        $this->set_status('Category teaching import: ' . wp_json_encode([
            'rows_read' => (int) ($res['rows_read'] ?? $res['rows'] ?? 0),
            'rows_with_manual_category_id' => (int) ($res['rows_with_manual_category_id'] ?? 0),
            'rules_inserted' => (int) ($res['rules_inserted'] ?? 0),
            'rules_updated' => (int) ($res['rules_updated'] ?? 0),
            'rows_skipped' => (int) ($res['rows_skipped'] ?? $res['skipped_rows'] ?? 0),
            'rows_rejected_by_safety' => (int) ($res['rows_rejected_by_safety'] ?? $res['safety_failed_rows'] ?? 0),
            'top_hard_safety_reasons' => (array) ($res['top_hard_safety_reasons'] ?? []),
            'skipped_sample_rows' => (array) ($res['skipped_sample_rows'] ?? []),
        ]));
        $this->go();
    }

    public function test_category_teaching_rule_match(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_test_category_teaching_rule_match');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $productId = absint($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->set_status('Teaching rule match diagnostic failed: enter a valid product_id.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->test_teaching_rule_match_for_product($productId, $marketplaceId);
        update_option('wei_ebay_category_mapping_teaching_match_diagnostic', $res, false);
        $this->set_status('Teaching rule match diagnostic: ' . wp_json_encode([
            'product_id' => $productId,
            'matching_teaching_rule_found' => !empty($res['matching_teaching_rule_found']),
            'matched_rule_id' => (int) ($res['matched_rule_id'] ?? 0),
            'matched_manual_ebay_category_id' => (string) ($res['matched_manual_ebay_category_id'] ?? ''),
            'error' => (string) ($res['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function generate_missing_german_content_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_missing_german_content_audit');
        $batchSize = max(1, min(200, absint($_POST['batch_size'] ?? 50)));
        $restart = !empty($_POST['restart']);
        $res = $this->scheduler->generate_missing_german_content_from_audit($batchSize, $restart);
        $this->set_status('Generate missing German content from audit: ' . wp_json_encode([
            'status' => (string) ($res['status'] ?? ''),
            'processed_this_batch' => (int) ($res['processed_this_batch'] ?? 0),
            'processed' => (int) ($res['processed'] ?? 0),
            'eligible_total' => (int) ($res['eligible_total'] ?? 0),
            'generated' => (int) ($res['generated'] ?? 0),
            'already_ready' => (int) ($res['already_ready'] ?? 0),
            'failed' => (int) ($res['failed'] ?? 0),
            'reports' => (array) ($res['reports'] ?? []),
            'safety' => (array) ($res['safety'] ?? []),
        ]));
        $this->go();
    }

    public function export_product(): void
    {
        $this->require_manage_options();
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
        $this->require_manage_options();
        check_admin_referer('wei_sync');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $this->adapter->sync_stock($id);
        $this->set_status('Sync: ' . wp_json_encode($res));
        $this->go();
    }

    public function import_order(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_import_order');
        $res = $this->orderImporter->import_once();
        $this->set_status('Import order: ' . wp_json_encode($res));
        $this->go();
    }


    public function auto_sync_readiness_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_auto_sync_readiness_now');
        $res = $this->scheduler->run_readiness_scan(max(1, min(300, absint($_POST['batch_size'] ?? 200))));
        $status = [
            'processed' => (int) ($res['processed'] ?? 0),
            'ready' => (int) ($res['ready'] ?? 0),
            'not_ready' => (int) ($res['not_ready'] ?? 0),
            'blocked_by_category' => (int) ($res['blocked_by_category'] ?? 0),
            'missing_required_aspects' => (int) ($res['missing_required_aspects'] ?? 0),
            'not_ready_sample_ids' => (array) ($res['not_ready_sample_ids'] ?? []),
            'blocked_by_category_sample_ids' => (array) ($res['blocked_by_category_sample_ids'] ?? []),
            'missing_required_aspects_sample_ids' => (array) ($res['missing_required_aspects_sample_ids'] ?? []),
        ];
        $this->set_status('Auto sync readiness scan: ' . wp_json_encode($status));
        $this->go();
    }


    public function full_category_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_full_category_audit');
        $verboseDebug = !empty($_POST['verbose_debug']);
        $res = $this->scheduler->run_full_category_audit($verboseDebug);
        $status = [
            'result' => (string) ($res['result'] ?? ''),
            'status' => (string) ($res['status'] ?? ''),
            'processed' => (int) ($res['processed'] ?? $res['total_scanned'] ?? 0),
            'total_products' => (int) ($res['total_products'] ?? 0),
            'processed_this_batch' => (int) ($res['processed_this_batch'] ?? 0),
            'batch_size' => (int) ($res['batch_size'] ?? 0),
            'total_scanned' => (int) ($res['total_scanned'] ?? 0),
            'ready_count' => (int) ($res['ready_count'] ?? 0),
            'blocked_by_category_count' => (int) ($res['blocked_by_category_count'] ?? 0),
            'missing_category_count' => (int) ($res['missing_category_count'] ?? 0),
            'missing_required_aspects_count' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'content_not_ready_count' => (int) ($res['content_not_ready_count'] ?? 0),
            'price_not_ready_count' => (int) ($res['price_not_ready_count'] ?? 0),
            'top_10_sanity_reasons' => (array) ($res['top_10_sanity_reasons'] ?? []),
            'top_10_detected_intents_with_problems' => (array) ($res['top_10_detected_intents_with_problems'] ?? []),
            'sample_problem_product_ids' => array_slice((array) ($res['sample_problem_product_ids'] ?? []), 0, 25),
            'reports' => (array) ($res['reports'] ?? []),
        ];
        $this->set_status('Full eBay category audit: ' . wp_json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function auto_sync_orders_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_auto_sync_orders_now');
        $res = $this->orderImporter->import_once();
        $this->set_status('Auto sync order import: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_stock_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_auto_sync_stock_now');
        $res = $this->scheduler->process_stock_queue(max(1, min(300, absint($_POST['batch_size'] ?? 100))));
        $this->set_status('Auto sync stock queue: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_export_now(): void
    {
        $this->require_manage_options();
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

    public function sync_prices_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_sync_prices_only');
        $this->set_status('Woo → eBay prices only: skeleton action registered; no eBay write performed until dedicated price-only implementation is enabled.');
        $this->go();
    }

    public function sync_content_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_sync_content_only');
        $this->set_status('Woo → eBay content only: skeleton action registered; no eBay write performed until dedicated content-only implementation is enabled.');
        $this->go();
    }

    public function sync_categories_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_sync_categories_only');
        $this->set_status('Woo → eBay categories/aspects only: skeleton action registered; use readiness scan/category audit for diagnostics. No eBay write performed.');
        $this->go();
    }

    public function sync_listing_meta_back(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_sync_listing_meta_back');
        $this->set_status('eBay → Woo listing status/listing IDs/public URLs: skeleton action registered; current export/publish flows already write known offer/listing URL meta. No price/content/stock overwrite performed.');
        $this->go();
    }

    public function sync_ebay_stock_to_woo(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_sync_ebay_stock_to_woo');
        $this->set_status('eBay → Woo stock sync: disabled skeleton. No Woo stock overwrite performed without a dedicated explicit implementation.');
        $this->go();
    }

    public function ebay_sync_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_sync_now');
        $res = $this->scheduler->run_checkpoint_queue_sync();
        $this->set_status('eBay sync run finished: ' . wp_json_encode($res));
        $this->go();
    }

    public function ebay_process_queue_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_process_queue_now');
        $batchSize = max(1, min(100, absint($_POST['batch_size'] ?? 50)));
        $res = $this->scheduler->process_change_queue($batchSize);
        $this->set_status('eBay queue processed: ' . wp_json_encode($res));
        $this->go();
    }

    public function ebay_rebuild_ready_queue(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_rebuild_ready_queue');
        $batchSize = max(1, min(100, absint($_POST['batch_size'] ?? 50)));
        $res = $this->scheduler->rebuild_queue_for_ready_products($batchSize);
        $this->set_status('eBay ready-products queue rebuilt: ' . wp_json_encode($res));
        $this->go();
    }


    public function ebay_rebuild_initial_publish_candidates(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_rebuild_initial_publish_candidates');
        $batchSize = max(1, min(500, absint($_POST['batch_size'] ?? 100)));
        $reset = !empty($_POST['reset_rebuild']);
        $res = $this->rebuild_initial_publish_candidates($batchSize, $reset);
        $this->set_status('Sprawdzenie produktów gotowych do wystawienia: ' . wp_json_encode([
            'status' => (string) ($res['status'] ?? ''),
            'processed' => (int) ($res['processed'] ?? 0),
            'processed_this_batch' => (int) ($res['processed_this_batch'] ?? 0),
            'ready' => (int) ($res['ready'] ?? 0),
            'already_published' => (int) ($res['already_published'] ?? 0),
            'blocked_by_category' => (int) ($res['blocked_by_category'] ?? 0),
            'missing_aspects' => (int) ($res['missing_aspects'] ?? 0),
            'content_not_ready' => (int) ($res['content_not_ready'] ?? 0),
            'price_not_ready' => (int) ($res['price_not_ready'] ?? 0),
            'errors' => (int) ($res['errors'] ?? 0),
            'cursor' => (int) ($res['cursor'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function ebay_initial_publish_batch(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_initial_publish_batch');
        $batchSize = max(1, min(50, absint($_POST['batch_size'] ?? 5)));
        $res = $this->run_initial_publish_batch($batchSize);
        $this->set_status('Initial eBay publish batch: ' . wp_json_encode([
            'processed' => (int) ($res['processed'] ?? 0),
            'success' => (int) ($res['success'] ?? 0),
            'failed' => (int) ($res['failed'] ?? 0),
            'published_total' => (int) ($res['published_total'] ?? 0),
            'remaining' => (int) ($res['remaining'] ?? 0),
            'status' => (string) ($res['status'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function ebay_initial_publish_toggle_pause(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_initial_publish_toggle_pause');
        $current = (string) get_option('wei_ebay_initial_publish_status', 'idle');
        $next = $current === 'paused' ? 'idle' : 'paused';
        update_option('wei_ebay_initial_publish_status', $next, false);
        $this->set_status($next === 'paused' ? 'Initial eBay publish paused' : 'Initial eBay publish resumed');
        $this->go();
    }

    public function ebay_initial_publish_reset(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_initial_publish_reset');
        if ((string) ($_POST['confirm_reset'] ?? '') !== 'RESET') {
            $this->set_status('Initial eBay publish reset skipped: type RESET to confirm.');
            $this->go();
        }

        foreach ($this->initial_publish_option_names() as $option) {
            delete_option($option);
        }
        delete_option('wei_ebay_initial_publish_last_batch_log');
        $this->set_status('Initial eBay publish progress reset.');
        $this->go();
    }


    private function initial_publish_candidate_summary(): array
    {
        $summary = get_option('wei_ebay_initial_publish_candidate_summary', []);
        return is_array($summary) ? $summary : [];
    }

    private function rebuild_initial_publish_candidates(int $batchSize, bool $reset): array
    {
        $previous = $reset ? [] : $this->initial_publish_candidate_summary();
        $summary = $this->initial_publish_scan_base_summary($previous, $reset, $batchSize);
        $cursor = (int) ($summary['cursor'] ?? 0);
        $ids = $this->initial_publish_scan_product_ids($batchSize, $cursor);
        $lastError = '';
        $lastProductId = $cursor;
        $processedThisBatch = 0;

        if ($reset) {
            $this->reset_initial_publish_progress_for_new_candidate_scan();
        }

        foreach ($ids as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }

            $processedThisBatch++;
            $summary['processed'] = (int) ($summary['processed'] ?? 0) + 1;
            $lastProductId = max($lastProductId, $productId);

            try {
                if ($this->is_initial_publish_already_published($productId)) {
                    $summary['already_published'] = (int) ($summary['already_published'] ?? 0) + 1;
                    $this->save_initial_publish_readiness_reason($productId, 'already_published', 'Product already has an eBay listing/item ID or published status.');
                    continue;
                }

                $stockReason = $this->initial_publish_stock_not_ready_reason($productId);
                if ($stockReason !== '') {
                    $summary['other'] = (int) ($summary['other'] ?? 0) + 1;
                    $this->save_initial_publish_readiness_reason($productId, 'other', $stockReason);
                    continue;
                }

                $preflight = $this->adapter->preflight_product($productId, null, true, true, [
                    'audit_mode' => true,
                    'suppress_side_effects' => true,
                ]);
                $reason = $this->initial_publish_reason_from_preflight($preflight);
                if ($reason === 'ready') {
                    $summary['ready'] = (int) ($summary['ready'] ?? 0) + 1;
                    update_post_meta($productId, '_wei_ebay_export_status', 'ready');
                    $this->save_initial_publish_readiness_reason($productId, 'ready', (string) ($preflight['message'] ?? 'Product ready for eBay export.'));
                    continue;
                }

                $summary[$reason] = (int) ($summary[$reason] ?? 0) + 1;
                $this->save_initial_publish_readiness_reason($productId, $reason, (string) ($preflight['message'] ?? 'Product not ready for eBay export.'));
            } catch (\Throwable $throwable) {
                $summary['errors'] = (int) ($summary['errors'] ?? 0) + 1;
                $lastError = $throwable->getMessage();
                $this->save_initial_publish_readiness_reason($productId, 'other', $lastError);
            }
        }

        $completed = count($ids) < $batchSize;
        $summary['status'] = $completed ? 'completed' : 'in_progress';
        $summary['last_error'] = $lastError;
        $summary['last_run_at'] = gmdate('Y-m-d H:i:s');
        $summary['rebuilt_at'] = $summary['last_run_at'];
        $summary['processed_this_batch'] = $processedThisBatch;
        $summary['cursor'] = $completed ? 0 : $lastProductId;
        $summary['offset'] = $summary['cursor'];
        $summary['next_offset'] = $summary['cursor'];
        $summary['batch_size'] = $batchSize;
        $summary['initial_publish_candidates'] = (int) ($summary['ready'] ?? 0);
        $summary['total_ready'] = (int) ($summary['ready'] ?? 0);
        $summary['ready_according_to_audit'] = (int) ($summary['ready'] ?? 0);
        $summary['readiness_status_ready'] = (int) ($summary['ready'] ?? 0);
        $summary['export_status_ready'] = (int) ($summary['ready'] ?? 0);
        $summary['skipped_reasons'] = [
            'already_published' => (int) ($summary['already_published'] ?? 0),
            'blocked_by_category' => (int) ($summary['blocked_by_category'] ?? 0),
            'missing_aspects' => (int) ($summary['missing_aspects'] ?? 0),
            'content_not_ready' => (int) ($summary['content_not_ready'] ?? 0),
            'price_not_ready' => (int) ($summary['price_not_ready'] ?? 0),
            'other' => (int) ($summary['other'] ?? 0),
        ];
        $summary['skipped_not_eligible'] = array_sum($summary['skipped_reasons']);
        $summary['source'] = 'woocommerce_products_database_preflight_scan';
        $summary['candidate_rule'] = 'Current WooCommerce product preflight is ready and product has no listing_id/item_id/listing_status/export_status published.';
        $summary['csv_required'] = false;

        update_option('wei_ebay_initial_publish_candidate_summary', $summary, false);
        update_option('wei_ebay_initial_publish_total_ready', (int) $summary['ready'], false);
        return $summary;
    }

    private function initial_publish_scan_base_summary(array $previous, bool $reset, int $batchSize): array
    {
        $empty = [
            'processed' => 0,
            'ready' => 0,
            'already_published' => 0,
            'blocked_by_category' => 0,
            'missing_aspects' => 0,
            'content_not_ready' => 0,
            'price_not_ready' => 0,
            'other' => 0,
            'errors' => 0,
            'cursor' => 0,
            'status' => 'idle',
            'last_run_at' => '',
            'last_error' => '',
            'batch_size' => $batchSize,
        ];

        return $reset ? $empty : array_merge($empty, array_intersect_key($previous, $empty));
    }

    private function initial_publish_scan_product_ids(int $batchSize, int $cursor): array
    {
        global $wpdb;
        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $batchSize))));
    }

    private function reset_initial_publish_progress_for_new_candidate_scan(): void
    {
        foreach ($this->initial_publish_option_names() as $option) {
            delete_option($option);
        }
        delete_option('wei_ebay_initial_publish_last_batch_log');
    }

    private function save_initial_publish_readiness_reason(int $productId, string $reason, string $message): void
    {
        $reason = in_array($reason, ['ready', 'blocked_by_category', 'missing_aspects', 'content_not_ready', 'price_not_ready', 'already_published', 'other'], true) ? $reason : 'other';
        if ($reason !== 'ready') {
            update_post_meta($productId, '_wei_ebay_export_status', $reason);
        }
        update_post_meta($productId, '_wei_ebay_readiness_reason', $reason);
        update_post_meta($productId, '_wei_ebay_readiness_message', mb_substr($message, 0, 1000));
        update_post_meta($productId, '_wei_ebay_readiness_checked_at', gmdate('Y-m-d H:i:s'));
    }

    private function initial_publish_reason_from_preflight(array $preflight): string
    {
        if (!empty($preflight['ready'])) {
            return 'ready';
        }

        $status = (string) ($preflight['status'] ?? '');
        $message = strtolower((string) ($preflight['message'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($preflight['errors'] ?? []))));
        if (in_array($status, ['needs_category_review', 'low_confidence_auto', 'category_sanity_failed', 'taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true)) {
            return 'blocked_by_category';
        }
        if (in_array($status, ['missing_required_aspects', 'missing_aspects'], true) || !empty($preflight['missing_aspects'])) {
            return 'missing_aspects';
        }
        if (in_array($status, ['not_ready_missing_german_content', 'content_not_ready'], true) || str_contains($message, 'content') || str_contains($message, 'title') || str_contains($message, 'description') || str_contains($message, 'german')) {
            return 'content_not_ready';
        }
        if (in_array($status, ['invalid_price', 'missing_exchange_rate', 'price_not_ready'], true) || str_contains($message, 'price') || str_contains($message, 'exchange rate')) {
            return 'price_not_ready';
        }
        return 'other';
    }

    private function initial_publish_stock_not_ready_reason(int $productId): string
    {
        $product = wc_get_product($productId);
        if (!$product) {
            return 'Product not found.';
        }
        $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
        if ($stockStatus === 'outofstock') {
            return 'Product stock status is outofstock.';
        }
        $stockQuantity = $product->get_stock_quantity();
        if ($stockQuantity !== null && (int) $stockQuantity < 0) {
            return 'Product stock quantity is invalid.';
        }
        return '';
    }

    private function last_full_audit_csv_path(array $auditSummary): string
    {
        $reports = is_array($auditSummary['reports'] ?? null) ? $auditSummary['reports'] : [];
        $full = is_array($reports['full_audit_csv'] ?? null) ? $reports['full_audit_csv'] : [];
        return (string) ($full['path'] ?? '');
    }

    private function normalize_audit_status_for_initial_publish(string $status): string
    {
        $status = trim($status);
        if ($status === 'ready') {
            return 'ready';
        }
        if ($status === 'blocked_by_category' || $status === 'missing_category') {
            return 'blocked_by_category';
        }
        if ($status === 'missing_required_aspects' || $status === 'missing_aspects') {
            return 'missing_aspects';
        }
        if ($status === 'content_not_ready') {
            return 'content_not_ready';
        }
        if ($status === 'price_not_ready') {
            return 'price_not_ready';
        }
        if ($status === '' || $status === 'missing_meta') {
            return 'missing_meta';
        }
        return 'other';
    }

    private function count_products_with_export_status(string $status): int
    {
        global $wpdb;
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = p.ID
                AND m.meta_key = '_wei_ebay_export_status'
                AND m.meta_value = %s
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
        ";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $status));
    }

    private function count_initial_publish_candidates_from_meta(): int
    {
        global $wpdb;
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} ready_meta
                ON ready_meta.post_id = p.ID
                AND ready_meta.meta_key = '_wei_ebay_export_status'
                AND ready_meta.meta_value = 'ready'
            LEFT JOIN {$wpdb->postmeta} listing_meta
                ON listing_meta.post_id = p.ID
                AND listing_meta.meta_key = '_wei_ebay_listing_id'
                AND listing_meta.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} item_meta
                ON item_meta.post_id = p.ID
                AND item_meta.meta_key = '_wei_ebay_item_id'
                AND item_meta.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} listing_status_meta
                ON listing_status_meta.post_id = p.ID
                AND listing_status_meta.meta_key = '_wei_ebay_listing_status'
                AND listing_status_meta.meta_value = 'published'
            LEFT JOIN {$wpdb->postmeta} export_published_meta
                ON export_published_meta.post_id = p.ID
                AND export_published_meta.meta_key = '_wei_ebay_export_status'
                AND export_published_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND listing_meta.post_id IS NULL
                AND item_meta.post_id IS NULL
                AND listing_status_meta.post_id IS NULL
                AND export_published_meta.post_id IS NULL
        ";
        return (int) $wpdb->get_var($sql);
    }

    private function count_initial_publish_already_published_products(): int
    {
        global $wpdb;
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = p.ID
                AND (
                    (m.meta_key = '_wei_ebay_listing_id' AND m.meta_value <> '')
                    OR (m.meta_key = '_wei_ebay_item_id' AND m.meta_value <> '')
                    OR (m.meta_key = '_wei_ebay_listing_status' AND m.meta_value = 'published')
                    OR (m.meta_key = '_wei_ebay_export_status' AND m.meta_value = 'published')
                )
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
        ";
        return (int) $wpdb->get_var($sql);
    }

    private function run_initial_publish_batch(int $batchSize): array
    {
        $status = (string) get_option('wei_ebay_initial_publish_status', 'idle');
        if ($status === 'paused') {
            return ['result' => 'skipped', 'status' => 'paused', 'processed' => 0, 'success' => 0, 'failed' => 0, 'published_total' => (int) get_option('wei_ebay_initial_publish_success', 0), 'remaining' => $this->initial_publish_remaining()];
        }

        $candidateSummary = $this->initial_publish_candidate_summary();
        $totalReady = $this->initial_publish_total_ready($candidateSummary);
        update_option('wei_ebay_initial_publish_total_ready', $totalReady, false);

        $cursor = (int) get_option('wei_ebay_initial_publish_cursor', 0);
        $processedTotal = (int) get_option('wei_ebay_initial_publish_processed', 0);
        $successTotal = (int) get_option('wei_ebay_initial_publish_success', 0);
        $failedTotal = (int) get_option('wei_ebay_initial_publish_failed', 0);
        $startedAt = gmdate('Y-m-d H:i:s');
        $logs = ['INITIAL_PUBLISH_BATCH_START batch_size=' . $batchSize . ' cursor=' . $cursor];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $lastError = '';
        $lastPublishedProductId = 0;
        $lastListingId = '';
        $newCursor = $cursor;

        $ids = $this->initial_publish_candidate_product_ids($batchSize, $cursor);
        foreach ($ids as $productId) {
            $productId = (int) $productId;
            $newCursor = max($newCursor, $productId);
            $processed++;
            $processedTotal++;
            $logs[] = 'INITIAL_PUBLISH_PRODUCT_START product_id=' . $productId;

            if ($this->is_initial_publish_already_published($productId)) {
                $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId . ' reason="already_published"';
                continue;
            }

            try {
                $preflight = $this->adapter->preflight_product($productId);
                if (empty($preflight['ready'])) {
                    $reason = (string) ($preflight['status'] ?? 'not_ready');
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId . ' reason="' . $this->compact_log_value($reason) . '"';
                    continue;
                }

                $res = $this->adapter->export_product($productId, null, true);
                $publishedDetails = $this->initial_publish_published_details($productId, $res);
                if (!empty($publishedDetails['published'])) {
                    $success++;
                    $successTotal++;
                    $lastPublishedProductId = $productId;
                    $lastListingId = (string) ($publishedDetails['listing_id'] ?? '');
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_PUBLISHED product_id=' . $productId . ' listing_id=' . $this->compact_log_value($lastListingId);
                } else {
                    $failed++;
                    $failedTotal++;
                    $lastError = (string) ($res['message'] ?? $res['error'] ?? 'publish_failed_without_published_listing_meta');
                    update_post_meta($productId, '_wei_ebay_last_sync_status', 'error');
                    update_post_meta($productId, '_wei_ebay_last_sync_error', $lastError);
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_FAILED product_id=' . $productId . ' error="' . $this->compact_log_value($lastError) . '"';
                }
            } catch (\Throwable $throwable) {
                $failed++;
                $failedTotal++;
                $lastError = $throwable->getMessage();
                update_post_meta($productId, '_wei_ebay_last_sync_status', 'error');
                update_post_meta($productId, '_wei_ebay_last_sync_error', $lastError);
                $logs[] = 'INITIAL_PUBLISH_PRODUCT_FAILED product_id=' . $productId . ' error="' . $this->compact_log_value($lastError) . '"';
            }
        }

        $remaining = max(0, $totalReady - $successTotal);
        $nextStatus = empty($ids) || $remaining === 0 ? 'completed' : 'idle';
        update_option('wei_ebay_initial_publish_processed', $processedTotal, false);
        update_option('wei_ebay_initial_publish_success', $successTotal, false);
        update_option('wei_ebay_initial_publish_failed', $failedTotal, false);
        update_option('wei_ebay_initial_publish_cursor', $newCursor, false);
        update_option('wei_ebay_initial_publish_last_run_at', $startedAt, false);
        update_option('wei_ebay_initial_publish_last_error', $lastError, false);
        update_option('wei_ebay_initial_publish_status', $nextStatus, false);
        update_option('wei_ebay_initial_publish_last_batch_success', $success, false);
        update_option('wei_ebay_initial_publish_last_batch_failed', $failed, false);
        update_option('wei_ebay_initial_publish_last_batch_processed', $processed, false);
        if ($lastPublishedProductId > 0) {
            update_option('wei_ebay_initial_publish_last_published_product_id', $lastPublishedProductId, false);
            update_option('wei_ebay_initial_publish_last_listing_id', $lastListingId, false);
        }
        $logs[] = 'INITIAL_PUBLISH_BATCH_DONE processed=' . $processed . ' success=' . $success . ' failed=' . $failed . ' published_total=' . $successTotal . ' remaining=' . $remaining;
        update_option('wei_ebay_initial_publish_last_batch_log', array_slice($logs, -100), false);

        return [
            'result' => $failed > 0 ? 'completed_with_errors' : 'success',
            'status' => $nextStatus,
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'published_total' => $successTotal,
            'remaining' => $remaining,
            'cursor' => $newCursor,
            'last_error' => $lastError,
        ];
    }

    private function initial_publish_published_details(int $productId, array $result): array
    {
        if (($result['result'] ?? '') !== 'success') {
            return ['published' => false, 'listing_id' => ''];
        }

        $metaListingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        $metaItemId = trim((string) get_post_meta($productId, '_wei_ebay_item_id', true));
        $listingStatus = (string) get_post_meta($productId, '_wei_ebay_listing_status', true);
        $publishedListingId = $metaListingId !== '' ? $metaListingId : $metaItemId;

        return [
            'published' => $publishedListingId !== '' && $listingStatus === 'published',
            'listing_id' => $publishedListingId,
        ];
    }

    private function initial_publish_candidate_product_ids(int $batchSize, int $cursor): array
    {
        global $wpdb;

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $sql = "
            SELECT p.ID
            FROM {$posts} p
            INNER JOIN {$postmeta} ready_meta
                ON ready_meta.post_id = p.ID
                AND ready_meta.meta_key = '_wei_ebay_export_status'
                AND ready_meta.meta_value = 'ready'
            LEFT JOIN {$postmeta} listing_meta
                ON listing_meta.post_id = p.ID
                AND listing_meta.meta_key = '_wei_ebay_listing_id'
                AND listing_meta.meta_value <> ''
            LEFT JOIN {$postmeta} item_meta
                ON item_meta.post_id = p.ID
                AND item_meta.meta_key = '_wei_ebay_item_id'
                AND item_meta.meta_value <> ''
            LEFT JOIN {$postmeta} listing_status_meta
                ON listing_status_meta.post_id = p.ID
                AND listing_status_meta.meta_key = '_wei_ebay_listing_status'
                AND listing_status_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
                AND listing_meta.post_id IS NULL
                AND item_meta.post_id IS NULL
                AND listing_status_meta.post_id IS NULL
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $batchSize))));
    }

    private function is_initial_publish_already_published(int $productId): bool
    {
        return trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true)) !== ''
            || trim((string) get_post_meta($productId, '_wei_ebay_item_id', true)) !== ''
            || (string) get_post_meta($productId, '_wei_ebay_listing_status', true) === 'published'
            || (string) get_post_meta($productId, '_wei_ebay_export_status', true) === 'published';
    }

    private function initial_publish_status(): array
    {
        $candidateSummary = $this->initial_publish_candidate_summary();
        $totalReady = $this->initial_publish_total_ready($candidateSummary);
        $success = (int) get_option('wei_ebay_initial_publish_success', 0);

        return [
            'total_ready' => $totalReady,
            'processed' => (int) get_option('wei_ebay_initial_publish_processed', 0),
            'success' => $success,
            'failed' => (int) get_option('wei_ebay_initial_publish_failed', 0),
            'cursor' => (int) get_option('wei_ebay_initial_publish_cursor', 0),
            'last_run_at' => (string) get_option('wei_ebay_initial_publish_last_run_at', ''),
            'last_error' => (string) get_option('wei_ebay_initial_publish_last_error', ''),
            'status' => (string) get_option('wei_ebay_initial_publish_status', 'idle'),
            'remaining' => max(0, $totalReady - $success),
            'last_batch_success' => (int) get_option('wei_ebay_initial_publish_last_batch_success', 0),
            'last_batch_failed' => (int) get_option('wei_ebay_initial_publish_last_batch_failed', 0),
            'last_batch_processed' => (int) get_option('wei_ebay_initial_publish_last_batch_processed', 0),
            'last_published_product_id' => (int) get_option('wei_ebay_initial_publish_last_published_product_id', 0),
            'last_listing_id' => (string) get_option('wei_ebay_initial_publish_last_listing_id', ''),
            'candidate_summary' => $candidateSummary,
            'last_batch_log' => (array) get_option('wei_ebay_initial_publish_last_batch_log', []),
        ];
    }

    private function initial_publish_total_ready(array $candidateSummary = []): int
    {
        $savedTotal = (int) get_option('wei_ebay_initial_publish_total_ready', 0);
        if ($savedTotal > 0) {
            return $savedTotal;
        }

        return (int) ($candidateSummary['initial_publish_candidates'] ?? $candidateSummary['ready'] ?? 0);
    }

    private function initial_publish_remaining(): int
    {
        $status = $this->initial_publish_status();
        return (int) ($status['remaining'] ?? 0);
    }

    private function initial_publish_option_names(): array
    {
        return [
            'wei_ebay_initial_publish_total_ready',
            'wei_ebay_initial_publish_processed',
            'wei_ebay_initial_publish_success',
            'wei_ebay_initial_publish_failed',
            'wei_ebay_initial_publish_cursor',
            'wei_ebay_initial_publish_last_run_at',
            'wei_ebay_initial_publish_last_error',
            'wei_ebay_initial_publish_status',
            'wei_ebay_initial_publish_last_batch_success',
            'wei_ebay_initial_publish_last_batch_failed',
            'wei_ebay_initial_publish_last_batch_processed',
            'wei_ebay_initial_publish_last_published_product_id',
            'wei_ebay_initial_publish_last_listing_id',
        ];
    }

    private function compact_log_value(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?: '';
        $value = str_replace(['"', "\n", "\r"], ["'", ' ', ' '], $value);
        return mb_substr($value, 0, 240);
    }

    public function auto_sync_toggle_pause(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_auto_sync_toggle_pause');
        $s = $this->settings();
        $s['auto_sync_paused'] = empty($s['auto_sync_paused']) ? 1 : 0;
        update_option(Plugin::OPTION_KEY, $s, false);
        $this->set_status(!empty($s['auto_sync_paused']) ? 'Auto sync paused' : 'Auto sync resumed');
        $this->go();
    }

    public function preflight_product(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_preflight');
        $id = (int) ($_REQUEST['product_id'] ?? 0);
        $res = $id > 0 ? $this->adapter->preflight_product($id) : ['result' => 'error', 'error' => 'missing_product_id'];
        $this->set_status('Preflight: ' . wp_json_encode($res));
        $this->go();
    }

    public function publish_product_offer_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_publish_product_offer_only');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $id > 0 ? $this->adapter->publish_product_offer_only($id) : [
            'result' => 'error',
            'published' => false,
            'status' => 'missing_product_id',
            'offer_id' => '',
            'listing_id' => '',
            'public_url' => '',
            'message' => 'Missing Woo product ID.',
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];
        $this->set_status('Manual publish offer only: ' . wp_json_encode($res));
        $this->go();
    }

    public function verify_api_publishing_readiness(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_verify_api_publishing_readiness');
        $id = (int) ($_POST['product_id'] ?? 0);
        $writeDiagnosticOffer = !empty($_POST['write_diagnostic_offer']);
        $res = $id > 0 ? $this->adapter->verify_api_publishing_readiness($id, null, $writeDiagnosticOffer) : [
            'result' => 'error',
            'status' => 'missing_product_id',
            'message' => 'Missing Woo product ID.',
            'called_publish_offer' => false,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];
        $this->set_status('Verify eBay API publishing readiness: ' . wp_json_encode($res));
        $this->go();
    }

    public function save_category_mapping(): void
    {
        $this->require_manage_options();
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
        $this->require_manage_options();
        check_admin_referer('wei_upsert_inventory_location');
        $res = $this->adapter->upsert_inventory_location();
        $this->set_status('Inventory location: ' . wp_json_encode($res));
        $this->go();
    }

    public function refresh_policies(): void
    {
        $this->require_manage_options();
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

    private function latest_audit_report_path(string $key): string
    {
        $summary = get_option('wei_ebay_full_category_audit_summary', []);
        $summary = is_array($summary) ? $summary : [];
        $reports = is_array($summary['reports'] ?? null) ? $summary['reports'] : [];
        $report = is_array($reports[$key] ?? null) ? $reports[$key] : [];
        $path = trim((string) ($report['path'] ?? ''));
        return $path !== '' && is_readable($path) ? $path : '';
    }

    private function require_manage_options(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }
    }

    private function light_ebay_sku_status(): array
    {
        $lastRun = $this->skuGenerator->current_status()['last_run'] ?? [];
        $lastTotals = is_array($lastRun) && is_array($lastRun['totals'] ?? null) ? $lastRun['totals'] : [];

        return [
            'products_with_wei_ebay_sku' => null,
            'products_missing_wei_ebay_sku' => null,
            'generated_in_last_run' => (int) ($lastTotals['generated'] ?? 0),
            'skipped_existing_in_last_run' => (int) ($lastTotals['skipped_existing'] ?? 0),
            'conflicts_in_last_run' => (int) ($lastTotals['conflicts'] ?? 0),
            'errors_in_last_run' => (int) ($lastTotals['errors'] ?? 0),
        ];
    }

    private function cached_nbp_rate_status(): array
    {
        $cached = get_transient('wei_nbp_eur_rate');
        if (is_array($cached) && (float) ($cached['nbp_rate'] ?? 0) > 0) {
            $cached['ready'] = true;
            $cached['from_transient'] = true;
        } else {
            $cached = get_option('wei_nbp_eur_rate_last', []);
            $cached = is_array($cached) ? $cached : [];
            if ((float) ($cached['nbp_rate'] ?? 0) > 0) {
                $cached['ready'] = true;
                $cached['from_last_saved'] = true;
            }
        }

        $fetchedAt = (int) ($cached['fetched_at'] ?? 0);
        return array_merge([
            'ready' => false,
            'nbp_rate' => null,
            'nbp_effective_date' => '',
            'nbp_table_no' => '',
            'fetched_at' => 0,
        ], $cached, [
            'cache_age_seconds' => $fetchedAt > 0 ? max(0, time() - $fetchedAt) : null,
            'cache_status' => !empty($cached['from_transient']) ? 'fresh' : (!empty($cached['from_last_saved']) ? 'last_saved' : (!empty($cached['ready']) ? 'cached' : 'missing')),
        ]);
    }

    private function light_auto_sync_status(array $settings): array
    {
        $frequency = (string) ($settings['auto_sync_frequency'] ?? 'hourly');
        $next = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(AutoSyncScheduler::HOOK_DELTA_SYNC, [], AutoSyncScheduler::CRON_GROUP) : false;
        if (!$next) {
            $next = wp_next_scheduled(AutoSyncScheduler::HOOK_DELTA_SYNC);
        }

        return [
            'status' => (string) get_option('wei_ebay_global_status', 'disabled'),
            'mode' => (string) ($settings['auto_sync_mode'] ?? 'disabled'),
            'frequency' => $frequency,
            'batch_size' => (int) ($settings['auto_sync_export_batch_size'] ?? 20),
            'preflight_batch_size' => (int) ($settings['auto_sync_preflight_batch_size'] ?? 200),
            'last_run' => (string) get_option('wei_ebay_last_run_at', ''),
            'next_run' => $next ? gmdate('Y-m-d H:i:s', (int) $next) : '-',
            'last_summary' => $this->summarize_option_array('wei_ebay_last_run_summary'),
            'pending_stock_sync' => $this->light_pending_stock_count(),
            'queued_products_count' => AutoSyncScheduler::queue_count('pending'),
            'failed_queue_count' => AutoSyncScheduler::queue_count('failed'),
            'checkpoint' => $this->summarize_option_array('wei_ebay_sync_checkpoints'),
            'queue_summary' => $this->summarize_option_array('wei_ebay_queue_summary'),
            'hook' => AutoSyncScheduler::HOOK_DELTA_SYNC,
            'woo_to_ebay_stock_sync_enabled' => !empty($settings['woo_to_ebay_stock_sync_enabled']),
            'ebay_stock_sync_mode' => (string) ($settings['ebay_stock_sync_mode'] ?? 'max_one'),
            'ebay_order_sync_enabled' => !empty($settings['ebay_order_sync_enabled']),
            'account_restriction_status' => (string) get_option('wei_ebay_account_restriction_status', ''),
            'readiness_summary' => $this->light_readiness_summary(),
            'export_summary' => $this->summarize_option_array('wei_ebay_export_summary'),
            'stock_summary' => $this->summarize_option_array('wei_ebay_stock_sync_summary'),
        ];
    }

    private function light_pending_stock_count(): string
    {
        $summary = get_option('wei_ebay_stock_sync_summary', []);
        if (is_array($summary) && isset($summary['pending_stock_sync'])) {
            return (string) (int) $summary['pending_stock_sync'];
        }

        return 'not loaded';
    }

    private function light_readiness_summary(): array
    {
        $summary = get_option('wei_ebay_readiness_summary', []);
        $summary = is_array($summary) ? $summary : [];
        foreach (['not_ready_items', 'blocked_by_category_items', 'missing_required_aspects_items', 'invalid_price_items'] as $key) {
            if (isset($summary[$key]) && is_array($summary[$key])) {
                $summary[$key . '_total'] = count($summary[$key]);
                $summary[$key] = array_slice($summary[$key], 0, 20);
            }
        }

        return $summary;
    }

    private function summarize_option_array(string $option): array
    {
        $value = get_option($option, []);
        $value = is_array($value) ? $value : [];

        return $this->limit_nested_array($value);
    }

    private function limit_nested_array(array $value, int $maxItems = 20, int $depth = 0): array
    {
        if ($depth >= 3) {
            return count($value) > $maxItems ? ['_truncated_count' => count($value)] : $value;
        }

        $limited = [];
        $i = 0;
        foreach ($value as $key => $item) {
            if ($i >= $maxItems) {
                $limited['_truncated_count'] = count($value) - $maxItems;
                break;
            }
            $limited[$key] = is_array($item) ? $this->limit_nested_array($item, $maxItems, $depth + 1) : $item;
            $i++;
        }

        return $limited;
    }

    private function recent_product_sync_status_rows(int $limit = 20): array
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => max(1, min(20, $limit)),
            'orderby' => 'modified',
            'order' => 'DESC',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_wei_ebay_sku', 'compare' => 'EXISTS'],
                ['key' => '_wei_ebay_offer_id', 'compare' => 'EXISTS'],
                ['key' => '_wei_ebay_export_status', 'compare' => 'EXISTS'],
                ['key' => '_wei_ebay_item_id', 'compare' => 'EXISTS'],
            ],
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);
        $rows = [];
        foreach ((array) $query->posts as $productId) {
            $productId = (int) $productId;
            $rows[] = [
                'product_id' => $productId,
                'title' => (string) get_the_title($productId),
                'edit_url' => (string) (get_edit_post_link($productId, '') ?: ''),
                'sku' => (string) get_post_meta($productId, '_wei_ebay_sku', true),
                'inventory_id' => (string) get_post_meta($productId, '_wei_ebay_inventory_id', true),
                'offer_id' => (string) get_post_meta($productId, '_wei_ebay_offer_id', true),
                'listing_id' => (string) get_post_meta($productId, '_wei_ebay_listing_id', true) ?: (string) get_post_meta($productId, '_wei_ebay_item_id', true),
                'public_url' => (string) get_post_meta($productId, '_wei_ebay_public_url', true),
                'last_export_at' => (string) get_post_meta($productId, '_wei_ebay_last_export_at', true),
                'last_publish_at' => (string) get_post_meta($productId, '_wei_ebay_last_publish_at', true),
                'last_sync_status' => (string) get_post_meta($productId, '_wei_ebay_last_sync_status', true) ?: (string) get_post_meta($productId, '_wei_ebay_export_status', true),
                'last_sync_error' => (string) get_post_meta($productId, '_wei_ebay_last_sync_error', true) ?: (string) get_post_meta($productId, '_wei_ebay_last_preflight_error', true),
                'listing_status' => (string) get_post_meta($productId, '_wei_ebay_listing_status', true),
            ];
        }
        return $rows;
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
        if (!isset($s['default_item_condition'])) {
            $s['default_item_condition'] = EbayConditionResolver::DEFAULT_ITEM_CONDITION;
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
        if (!isset($s['verbose_debug'])) {
            $s['verbose_debug'] = 0;
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
