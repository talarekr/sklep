<?php

namespace WEI_FR\Services;

use WEI_FR\Adapters\EbayAdapter;
use WEI_FR\Plugin;
use WEI_FR\Repositories\CategoryMappingRepository;
use WEI_FR\Services\EbayShippingPolicyResolver;

class AdminPage
{
    private const PUBLISH_ACTION_MIN_BATCH_SIZE = 1;
    private const PUBLISH_ACTION_MAX_BATCH_SIZE = 300;

    private const GERMAN_CONTENT_MIGRATION_STATE_OPTION = 'wei_fr_ebay_french_content_schema_migration_state';
    private const SHARED_EBAY_RUNAME = 'GP_SWISS-GPSWISS-GPSwiss-jigmn';

    public function __construct(private EbayAuth $auth, private EbayAdapter $adapter, private SyncService $syncService, private OrderImporter $orderImporter, private Logger $logger, private CategoryMappingRepository $categoryRepo, private AutoCategoryMappingService $autoCategoryMapper, private EbaySkuGenerator $skuGenerator, private EbayPriceResolver $priceResolver, private EbayTaxonomyService $taxonomy, private AutoSyncScheduler $scheduler, private StockSyncService $stockSync, private EbayFrCategoryComparisonTool $categoryComparisonTool)
    {
    }

    public function hooks(): void
    {
        add_action('admin_init', [$this, 'log_build_loaded']);
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_wei_fr_save_settings', [$this, 'save_settings']);
        add_action('admin_post_wei_fr_save_ebay_settings', [$this, 'save_ebay_settings']);
        add_action('admin_post_wei_fr_save_translation_provider_settings', [$this, 'save_translation_provider_settings_action']);
        add_action('admin_post_wei_fr_start_oauth_connect', [$this, 'start_oauth_connect']);
        add_action('admin_post_wei_fr_clear_oauth_diagnostics', [$this, 'clear_oauth_diagnostics']);
        add_action('admin_post_wei_fr_disconnect', [$this, 'disconnect']);
        add_action('admin_post_wei_fr_test_connection', [$this, 'test_connection']);
        add_action('admin_post_wei_fr_readiness', [$this, 'run_readiness']);
        add_action('admin_post_wei_fr_export_product', [$this, 'export_product']);
        add_action('admin_post_wei_fr_sync_stock', [$this, 'sync_stock']);
        add_action('admin_post_wei_fr_import_order', [$this, 'import_order']);
        add_action('admin_post_wei_fr_upsert_inventory_location', [$this, 'upsert_inventory_location']);
        add_action('admin_post_wei_fr_refresh_policies', [$this, 'refresh_policies']);
        add_action('admin_post_wei_fr_generate_shipping_mapping_report', [$this, 'generate_shipping_mapping_report']);
        add_action('admin_post_wei_fr_shipping_mapping_diagnostics', [$this, 'shipping_mapping_diagnostics']);
        add_action('admin_post_wei_fr_generate_listing_quality_audit', [$this, 'generate_listing_quality_audit']);
        add_action('admin_post_wei_fr_condition_cleanup_single', [$this, 'condition_cleanup_single']);
        add_action('admin_post_wei_fr_basic_specifics_single', [$this, 'basic_specifics_single']);
        add_action('admin_post_wei_fr_description_condition_cleanup_single', [$this, 'description_condition_cleanup_single']);
        add_action('admin_post_wei_fr_description_template_preview', [$this, 'description_template_preview']);
        add_action('admin_post_wei_fr_description_template_publish_dry_run', [$this, 'description_template_publish_dry_run']);
        add_action('admin_post_wei_fr_description_template_single', [$this, 'description_template_single']);
        add_action('admin_post_wei_fr_ebay_regenerate_french_content', [$this, 'regenerate_french_content']);
        add_action('admin_post_wei_fr_generate_french_content_single', [$this, 'generate_french_content_single']);
        add_action('admin_post_wei_fr_generate_french_content_batch', [$this, 'generate_french_content_batch']);
        add_action('admin_post_wei_fr_regenerate_french_content_batch', [$this, 'regenerate_french_content_batch_ajax']);
        add_action('admin_post_wei_fr_french_content_schema_diagnostic', [$this, 'french_content_schema_diagnostic']);
        add_action('admin_post_wei_fr_update_shipping_policy_one', [$this, 'update_shipping_policy_one']);
        add_action('admin_post_wei_fr_shipping_policy_bulk_start', [$this, 'shipping_policy_bulk_start']);
        add_action('admin_post_wei_fr_shipping_policy_bulk_pause', [$this, 'shipping_policy_bulk_pause']);
        add_action('admin_post_wei_fr_shipping_policy_bulk_resume', [$this, 'shipping_policy_bulk_resume']);
        add_action('admin_post_wei_fr_shipping_policy_bulk_stop', [$this, 'shipping_policy_bulk_stop']);
        add_action('admin_post_wei_fr_shipping_policy_bulk_process', [$this, 'shipping_policy_bulk_process']);
        add_action('admin_post_wei_fr_basic_specifics_bulk_start', [$this, 'basic_specifics_bulk_start']);
        add_action('admin_post_wei_fr_basic_specifics_bulk_pause', [$this, 'basic_specifics_bulk_pause']);
        add_action('admin_post_wei_fr_basic_specifics_bulk_resume', [$this, 'basic_specifics_bulk_resume']);
        add_action('admin_post_wei_fr_basic_specifics_bulk_stop', [$this, 'basic_specifics_bulk_stop']);
        add_action('admin_post_wei_fr_basic_specifics_bulk_process', [$this, 'basic_specifics_bulk_process']);
        add_action('admin_post_wei_fr_preflight_product', [$this, 'preflight_product']);
        add_action('admin_post_wei_fr_vehicle_compatibility_diagnostics', [$this, 'vehicle_compatibility_diagnostics']);
        add_action('admin_post_wei_fr_run_vehicle_compatibility_audit', [$this, 'run_vehicle_compatibility_audit']);
        add_action('admin_post_wei_fr_publish_product_offer_only', [$this, 'publish_product_offer_only']);
        add_action('admin_post_wei_fr_inspect_offer_before_publish', [$this, 'inspect_offer_before_publish']);
        add_action('admin_post_wei_fr_verify_api_publishing_readiness', [$this, 'verify_api_publishing_readiness']);
        add_action('admin_post_wei_fr_save_category_mapping', [$this, 'save_category_mapping']);
        add_action('admin_post_wei_fr_import_ebay_fr_category_tree_cache', [$this, 'import_ebay_fr_category_tree_cache']);
        add_action('admin_post_wei_fr_auto_map_categories', [$this, 'auto_map_categories']);
        add_action('admin_post_wei_fr_generate_ebay_fr_category_suggestions', [$this, 'generate_ebay_fr_category_suggestions']);
        add_action('admin_post_wei_fr_generate_all_ebay_fr_category_suggestions', [$this, 'generate_all_ebay_fr_category_suggestions']);
        add_action('admin_post_wei_fr_reset_ebay_fr_category_suggestions_progress', [$this, 'reset_ebay_fr_category_suggestions_progress']);
        add_action('admin_post_wei_fr_repair_blocked_category_mappings', [$this, 'repair_blocked_category_mappings']);
        add_action('admin_post_wei_fr_generate_blocked_category_fix_report', [$this, 'generate_blocked_category_fix_report']);
        add_action('admin_post_wei_fr_generate_category_mapping_worklist', [$this, 'generate_category_mapping_worklist']);
        add_action('admin_post_wei_fr_generate_all_category_mapping_worklist', [$this, 'generate_all_category_mapping_worklist']);
        add_action('admin_post_wei_fr_import_category_mapping_worklist', [$this, 'import_category_mapping_worklist']);
        add_action('admin_post_download_wei_fr_report', [$this, 'download_wei_fr_report']);
        add_action('admin_post_wei_fr_repair_audit_category_groups', [$this, 'repair_audit_category_groups']);
        add_action('admin_post_wei_fr_apply_manual_woo_category_mappings', [$this, 'apply_manual_woo_category_mappings']);
        add_action('admin_post_wei_fr_export_category_teaching_csv', [$this, 'export_category_teaching_csv']);
        add_action('admin_post_wei_fr_export_category_template_csv', [$this, 'export_category_template_csv']);
        add_action('admin_post_wei_fr_export_ovoko_category_suggestions_csv', [$this, 'export_ovoko_category_suggestions_csv']);
        add_action('admin_post_wei_fr_import_category_teaching_csv', [$this, 'import_category_teaching_csv']);
        add_action('admin_post_wei_fr_test_category_teaching_rule_match', [$this, 'test_category_teaching_rule_match']);
        add_action('admin_post_wei_fr_generate_missing_french_content_audit', [$this, 'generate_missing_french_content_audit']);
        add_action('admin_post_wei_fr_generate_ebay_skus', [$this, 'generate_ebay_skus']);
        add_action('admin_post_wei_fr_auto_sync_readiness_now', [$this, 'auto_sync_readiness_now']);
        add_action('admin_post_wei_fr_full_publish_readiness_audit', [$this, 'full_publish_readiness_audit']);
        add_action('admin_post_wei_fr_full_category_audit', [$this, 'full_category_audit']);
        add_action('admin_post_wei_fr_run_category_readiness_audit', [$this, 'run_category_readiness_audit']);
        add_action('admin_post_wei_fr_generate_de_fr_category_comparison', [$this, 'generate_de_fr_category_comparison']);
        add_action('admin_post_wei_fr_auto_sync_orders_now', [$this, 'auto_sync_orders_now']);
        add_action('admin_post_wei_fr_auto_sync_stock_now', [$this, 'auto_sync_stock_now']);
        add_action('admin_post_wei_fr_auto_sync_export_now', [$this, 'auto_sync_export_now']);
        add_action('admin_post_wei_fr_sync_prices_only', [$this, 'sync_prices_only']);
        add_action('admin_post_wei_fr_sync_content_only', [$this, 'sync_content_only']);
        add_action('admin_post_wei_fr_sync_categories_only', [$this, 'sync_categories_only']);
        add_action('admin_post_wei_fr_sync_listing_meta_back', [$this, 'sync_listing_meta_back']);
        add_action('admin_post_wei_fr_sync_ebay_stock_to_woo', [$this, 'sync_ebay_stock_to_woo']);
        add_action('admin_post_wei_fr_auto_sync_toggle_pause', [$this, 'auto_sync_toggle_pause']);
        add_action('admin_post_wei_fr_ebay_sync_now', [$this, 'ebay_sync_now']);
        add_action('admin_post_wei_fr_ebay_process_queue_now', [$this, 'ebay_process_queue_now']);
        add_action('admin_post_wei_fr_ebay_rebuild_ready_queue', [$this, 'ebay_rebuild_ready_queue']);
        add_action('admin_post_wei_fr_ebay_initial_publish_batch', [$this, 'ebay_initial_publish_batch']);
        add_action('admin_post_wei_fr_publish_ready_products', [$this, 'publish_ready_products']);
        add_action('admin_post_wei_fr_ebay_rebuild_initial_publish_candidates', [$this, 'ebay_rebuild_initial_publish_candidates']);
        add_action('admin_post_wei_fr_ebay_initial_publish_toggle_pause', [$this, 'ebay_initial_publish_toggle_pause']);
        add_action('admin_post_wei_fr_ebay_initial_publish_reset', [$this, 'ebay_initial_publish_reset']);
        add_action('admin_post_wei_fr_refresh_ebay_listing_state', [$this, 'refresh_ebay_listing_state']);
        add_action('admin_post_wei_fr_save_stock_sync_settings', [$this, 'save_stock_sync_settings']);
        add_action('admin_post_wei_fr_run_stock_sync_dry_run', [$this, 'run_stock_sync_dry_run']);
        add_action('admin_post_wei_fr_run_stock_sync_now', [$this, 'run_stock_sync_now']);
        add_action('admin_post_wei_fr_stock_sync_diagnostics', [$this, 'stock_sync_diagnostics']);
        add_action('admin_post_wei_fr_publish_listing_diagnostics', [$this, 'publish_listing_diagnostics']);
    }

    public function register_menu(): void
    {
        $this->log_build_loaded();

        $this->add_traced_submenu_page('woocommerce', 'eBay.fr Integration', 'eBay.fr Integration', 'manage_options', 'woo-ebay-fr', [$this, 'render'], 'main admin menu');

        // The shared OAuth callback page slug is owned by the DE plugin/router.
        // FR intentionally avoids registering the same hidden submenu slug so
        // WordPress cannot resolve duplicate callback-page capabilities before
        // the shared admin_init router dispatches the FR state.
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
        $normalizedMenuSlug = $this->safe_admin_slug($menuSlug, 'woo-ebay-fr', $section, 'menu_slug');

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

        error_log('WEI_FR_BUILD_LOADED ' . $this->encode_log_context([
            'commit' => defined('WEI_FR_BUILD_COMMIT') ? WEI_FR_BUILD_COMMIT : 'unknown',
            'build' => defined('WEI_FR_BUILD_ID') ? WEI_FR_BUILD_ID : 'unknown',
            'plugin_file' => defined('WEI_FR_PLUGIN_FILE') ? WEI_FR_PLUGIN_FILE : '',
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

        error_log('WEI_FR_ADMIN_MENU_REGISTER ' . $this->encode_log_context($context));

        if ($this->is_empty_slug($parentSlug) || $this->is_empty_slug($menuSlug)) {
            error_log('WEI_FR_ADMIN_MENU_NULL_SLUG_DETECTED ' . $this->encode_log_context($context + [
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
        // Callback is handled before render in WEI_FR\Services\EbayAuth::handle_oauth_callback.
        // If WordPress reaches this callback directly, run the same handler so
        // administrators never see the generic "no permissions" admin page.
        $this->auth->handle_admin_post_oauth_callback();
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No access');
        }
        $s = $this->settings();
        $status = get_option('wei_fr_last_status', []);
        $status = is_array($status) ? $status : [];
        $logs = get_option('wei_fr_logs', []);
        $logs = array_slice(is_array($logs) ? $logs : [], 0, 50);
        $admin_section = isset($_GET['wei_fr_section']) ? sanitize_key(wp_unslash((string) $_GET['wei_fr_section'])) : '';
        $load_category_mapping_rows = $admin_section === 'category-mappings'
            || isset($_GET['category_status'])
            || isset($_GET['category_sort']);
        $load_product_sync_rows = $admin_section === 'product-sync';

        // Keep the default admin page render light. The rebuilt UI originally
        // executed product-wide SKU counts, product meta queries and external
        // exchange-rate refreshes while WordPress was only trying to render the
        // page. Heavy/diagnostic data is now loaded only from explicit links.
        $category_mappings = $load_category_mapping_rows
            ? $this->categoryRepo->list_manual_mapping_categories((string) ($s['marketplace_id'] ?? 'EBAY_FR'), ['limit' => 500])
            : [];
        $ebay_sku_status = $this->light_ebay_sku_status();
        $ebay_sku_generation_status = $this->skuGenerator->current_status();
        $nbp_rate_status = $this->cached_nbp_rate_status();
        $connect_url = wp_nonce_url(admin_url('admin-post.php?action=wei_fr_start_oauth_connect'), 'wei_fr_start_oauth_connect');
        $oauth_diagnostics = $this->auth->get_diagnostic_oauth_context();
        $auto_sync_status = $this->light_auto_sync_status($s);
        $stock_sync_status = $this->stockSync->status();
        $stock_sync_diagnostics = get_option('wei_fr_ebay_stock_sync_last_diagnostics', []);
        $stock_sync_diagnostics = is_array($stock_sync_diagnostics) ? $stock_sync_diagnostics : [];
        $initial_publish_candidate_summary = $this->initial_publish_candidate_summary();
        $initial_publish_status = $this->initial_publish_status();
        $fr_publish_report_status = $this->fr_publish_report_status();
        $fr_publish_last_run = is_array($fr_publish_report_status['last_run'] ?? null) ? $fr_publish_report_status['last_run'] : [];
        $fr_publish_last_actions = is_array($fr_publish_last_run['actions'] ?? null) ? $fr_publish_last_run['actions'] : [];
        $fr_publish_listing_diagnostics = get_option('wei_fr_ebay_publish_listing_diagnostics', []);
        $fr_publish_listing_diagnostics = is_array($fr_publish_listing_diagnostics) ? $fr_publish_listing_diagnostics : [];
        $ebay_listing_state_summary = $this->ebay_listing_state_summary();
        $full_category_audit_summary = get_option('wei_fr_ebay_full_category_audit_summary', []);
        $full_category_audit_summary = is_array($full_category_audit_summary) ? $full_category_audit_summary : [];
        $category_readiness_audit_summary = get_option('wei_fr_ebay_category_readiness_audit_summary', []);
        $category_readiness_audit_summary = is_array($category_readiness_audit_summary) ? $category_readiness_audit_summary : [];
        $french_content_audit_summary = get_option('wei_fr_ebay_french_content_audit_summary', []);
        $french_content_audit_summary = is_array($french_content_audit_summary) ? $french_content_audit_summary : [];
        $category_group_repair_summary = get_option('wei_fr_ebay_category_mapping_repair_audit_group_report', []);
        $category_group_repair_summary = is_array($category_group_repair_summary) ? $category_group_repair_summary : [];
        $manual_woo_category_apply_summary = get_option('wei_fr_ebay_manual_woo_category_mapping_apply_report', []);
        $manual_woo_category_apply_summary = is_array($manual_woo_category_apply_summary) ? $manual_woo_category_apply_summary : [];
        $category_teaching_export_summary = get_option('wei_fr_ebay_category_mapping_teaching_export', []);
        $category_teaching_export_summary = is_array($category_teaching_export_summary) ? $category_teaching_export_summary : [];
        $category_template_export_summary = get_option('wei_fr_ebay_category_template_export_summary', []);
        $category_template_export_summary = is_array($category_template_export_summary) ? $category_template_export_summary : [];
        $blocked_category_fix_report_summary = get_option('wei_fr_ebay_blocked_category_fix_report_summary', []);
        $blocked_category_fix_report_summary = is_array($blocked_category_fix_report_summary) ? $blocked_category_fix_report_summary : [];
        $category_mapping_worklist_summary = get_option('wei_fr_ebay_category_mapping_worklist_summary', []);
        $category_mapping_worklist_summary = is_array($category_mapping_worklist_summary) ? $category_mapping_worklist_summary : [];
        $all_category_mapping_worklist_summary = get_option('wei_fr_ebay_all_category_mapping_worklist_summary', []);
        $all_category_mapping_worklist_summary = is_array($all_category_mapping_worklist_summary) ? $all_category_mapping_worklist_summary : [];
        $category_mapping_worklist_import_summary = get_option('wei_fr_ebay_category_mapping_worklist_import_summary', []);
        $category_mapping_worklist_import_summary = is_array($category_mapping_worklist_import_summary) ? $category_mapping_worklist_import_summary : [];
        $ovoko_category_suggestions_summary = get_option('wei_fr_ebay_ovoko_category_suggestions_summary', []);
        $ovoko_category_suggestions_summary = is_array($ovoko_category_suggestions_summary) ? $ovoko_category_suggestions_summary : [];
        $category_comparison_last_run = $this->category_comparison_last_run();
        $vehicle_compatibility_audit_summary = get_option('wei_fr_ebay_vehicle_compatibility_audit_summary', []);
        $vehicle_compatibility_audit_summary = is_array($vehicle_compatibility_audit_summary) ? $vehicle_compatibility_audit_summary : [];
        $vehicle_compatibility_diagnostics = get_option('wei_fr_ebay_last_vehicle_compatibility_diagnostics', []);
        $vehicle_compatibility_diagnostics = is_array($vehicle_compatibility_diagnostics) ? $vehicle_compatibility_diagnostics : [];
        $category_teaching_import_summary = get_option('wei_fr_ebay_category_mapping_import_summary', []);
        $category_teaching_import_summary = is_array($category_teaching_import_summary) ? $category_teaching_import_summary : [];
        $category_validation_statuses = get_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, []);
        $category_validation_statuses = is_array($category_validation_statuses) ? $category_validation_statuses : [];
        $manual_category_picker_query = isset($_GET['ebay_category_search']) ? sanitize_text_field(wp_unslash((string) $_GET['ebay_category_search'])) : '';
        $manual_category_picker_rows = $load_category_mapping_rows ? $this->taxonomy->search_cached_automotive_categories((string) ($s['marketplace_id'] ?? 'EBAY_FR'), $manual_category_picker_query, 75) : [];
        $category_cache_diagnostic = $this->taxonomy->category_cache_diagnostic((string) ($s['marketplace_id'] ?? 'EBAY_FR'), ['33544', '33615', '33566', '9886', '171115']);
        $category_mapping_diagnostics_id = isset($_GET['woo_category_diagnostics_id']) ? absint($_GET['woo_category_diagnostics_id']) : 0;
        $category_mapping_diagnostics = $category_mapping_diagnostics_id > 0 ? $this->category_mapping_diagnostics($category_mapping_diagnostics_id, (string) ($s['marketplace_id'] ?? 'EBAY_FR')) : [];
        $category_dashboard_summary = $this->categoryRepo->production_mapping_summary(
            (string) ($s['marketplace_id'] ?? 'EBAY_FR'),
            $category_teaching_import_summary,
            $category_validation_statuses,
            $this->light_readiness_summary()
        );
        $category_teaching_match_diagnostic = get_option('wei_fr_ebay_category_mapping_teaching_match_diagnostic', []);
        $category_teaching_match_diagnostic = is_array($category_teaching_match_diagnostic) ? $category_teaching_match_diagnostic : [];
        $product_sync_status_rows = $load_product_sync_rows ? $this->recent_product_sync_status_rows() : [];
        $shipping_mapping_report = get_option('wei_fr_ebay_shipping_mapping_report', []);
        $shipping_mapping_report = is_array($shipping_mapping_report) ? $shipping_mapping_report : [];
        $listing_quality_audit = get_option('wei_fr_ebay_listing_quality_audit', []);
        $listing_quality_audit = is_array($listing_quality_audit) ? $listing_quality_audit : [];
        $shipping_policy_bulk_status = $this->shipping_policy_bulk_status();
        $basic_specifics_bulk_status = $this->basic_specifics_bulk_status();
        include WEI_FR_PLUGIN_DIR . 'views/admin-page.php';
    }

    private function category_mapping_diagnostics(int $wooCategoryId, string $marketplaceId = 'EBAY_FR'): array
    {
        $term = get_term($wooCategoryId, 'product_cat');
        $wooName = is_object($term) && isset($term->name) ? (string) $term->name : '';
        $rows = $this->categoryRepo->list_mapping_rows_for_woo_category($wooCategoryId, $marketplaceId);
        $selected = $this->categoryRepo->resolveProductionCategoryMapping($wooCategoryId, $marketplaceId);
        $selectedId = trim((string) ($selected['ebay_category_id'] ?? ''));
        $cached = $selectedId !== '' ? $this->taxonomy->cached_category($marketplaceId, $selectedId) : null;
        $exists = is_array($cached);
        $leaf = $exists && !empty($cached['leaf']);
        $auditStatus = 'missing_category';
        $blockedReason = 'missing_category_mapping';
        if ($selectedId !== '') {
            $trustedManualCacheMissing = (string) ($selected['source'] ?? '') === 'manual_worklist'
                && in_array((string) ($selected['cache_validation_status'] ?? ''), ['cache_missing', 'cache_incomplete'], true)
                && (string) ($selected['validation_confidence'] ?? '') === 'trusted_manual';
            if (!$exists && !$trustedManualCacheMissing) {
                $auditStatus = 'blocked_by_category';
                $blockedReason = 'invalid_ebay_category_id_not_in_local_cache';
            } elseif (!$exists && $trustedManualCacheMissing) {
                $auditStatus = 'ready';
                $blockedReason = '';
            } elseif (!$leaf) {
                $auditStatus = 'blocked_by_category';
                $blockedReason = 'non_leaf_category';
            } else {
                $auditStatus = 'ready';
                $blockedReason = '';
            }
        }

        return [
            'woo_category_id' => $wooCategoryId,
            'woo_category_name' => $wooName,
            'marketplace_id' => $marketplaceId,
            'mapping_rows' => $rows,
            'selected_row' => $selected ?: [],
            'selected_mapping_row_id' => (int) ($selected['id'] ?? 0),
            'selected_ebay_category_id' => $selectedId,
            'selected_source' => (string) ($selected['source'] ?? ''),
            'why_selected' => (string) ($selected['resolver_reason'] ?? ($selected ? $this->categoryRepo->resolver_reason_for_row($selected) : 'no active mapping row; fallback allowed only if no mapping exists')),
            'selected_ebay_category_exists_in_cache' => $exists,
            'selected_ebay_category_is_leaf' => $leaf,
            'selected_ebay_category_path' => (string) ($cached['category_path'] ?? $selected['ebay_category_path'] ?? ''),
            'audit_category_status' => $auditStatus,
            'blocked_by_category_reason' => $blockedReason,
            'expected_case_5197' => $wooCategoryId === 5197 ? ['expected_manual_worklist_category_id' => '33566', 'passes' => $selectedId === '33566'] : null,
            'ebay_api_called' => false,
        ];
    }

    public function basic_specifics_bulk_start(): void { $this->require_manage_options(); check_admin_referer('wei_fr_basic_specifics_bulk_start'); $batchSize=max(1,min(25,absint($_POST['batch_size']??1))); $buildLimit=max(1,min(500,absint($_POST['build_limit']??250))); $summary=$this->build_basic_specifics_bulk_queue($batchSize,$buildLimit); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_QUEUE_BUILT',$summary); $this->set_status('Basic specifics bulk queue built: '.wp_json_encode($summary)); $this->go(); }
    public function basic_specifics_bulk_pause(): void { $this->require_manage_options(); check_admin_referer('wei_fr_basic_specifics_bulk_pause'); $status=$this->basic_specifics_bulk_status(); $status['state']='paused'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_PAUSED',$status); $this->go(); }
    public function basic_specifics_bulk_resume(): void { $this->require_manage_options(); check_admin_referer('wei_fr_basic_specifics_bulk_resume'); $status=$this->basic_specifics_bulk_status(); $status['state']='running'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_RESUMED',$status); $this->go(); }
    public function basic_specifics_bulk_stop(): void { $this->require_manage_options(); check_admin_referer('wei_fr_basic_specifics_bulk_stop'); global $wpdb; $table=$wpdb->prefix.'wei_fr_ebay_sync_queue'; $reasons=['basic_item_specifics_bulk_update','basic_item_specifics_update','basic_item_specifics_bulk','legacy_basic_item_specifics_update']; foreach($reasons as $reason){$wpdb->delete($table,['reason'=>$reason]);} $status=$this->default_basic_specifics_bulk_status(); $status['state']='stopped'; $status['checkpoint']='cleared'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_STOPPED',$status); $this->go(); }
    public function basic_specifics_bulk_process(): void { $this->require_manage_options(); check_admin_referer('wei_fr_basic_specifics_bulk_process'); $res=$this->process_basic_specifics_bulk_batch(); $this->set_status('Basic specifics bulk batch: '.wp_json_encode($this->limit_nested_array($res,20))); $this->go(); }
    private function basic_specifics_memory_guard(string $stage, array &$status): bool { $limit=134217728; $hard=(int)($limit*0.85); $usage=(int)memory_get_usage(true); if($usage<$hard){ return false; } $status['checkpoint']='partial'; $status['state']='paused'; $status['last_error']='memory_guard_triggered'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false); $this->logger->error('EBAY_BASIC_SPECIFICS_MEMORY_GUARD',['stage'=>$stage,'memory_usage'=>$usage,'memory_limit'=>$limit]); return true; }
    private function build_basic_specifics_bulk_queue(int $batchSize,int $buildLimit): array { global $wpdb; $queueTable=$wpdb->prefix.'wei_fr_ebay_sync_queue'; $mappingTable=$wpdb->prefix.'marketplace_mappings'; $postmeta=$wpdb->postmeta; $postsTable=$wpdb->posts; $now=gmdate('Y-m-d H:i:s'); $status=$this->default_basic_specifics_bulk_status(); if($this->basic_specifics_memory_guard('build_start',$status)){ return $status+['result'=>'guarded']; } $reasons=['basic_item_specifics_bulk_update','basic_item_specifics_update','basic_item_specifics_bulk','legacy_basic_item_specifics_update']; foreach($reasons as $reason){$wpdb->delete($queueTable,['reason'=>$reason]);}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT m.woo_product_id AS product_id,m.remote_offer_id AS offer_id,m.remote_listing_id AS listing_id,p.post_title AS post_title,sku.meta_value AS sku,pm.meta_key AS meta_key,pm.meta_value AS meta_value FROM {$mappingTable} m LEFT JOIN {$postsTable} p ON p.ID=m.woo_product_id LEFT JOIN {$postmeta} sku ON sku.post_id=m.woo_product_id AND sku.meta_key='_wei_fr_ebay_sku' LEFT JOIN {$postmeta} pm ON pm.post_id=m.woo_product_id AND pm.meta_key IN ('_manufacturer_part_number','_manufacturer','_wei_fr_ebay_mpn','_wei_fr_ebay_manufacturer','_wei_fr_ebay_hersteller','_wei_fr_ebay_herstellernummer','_wei_fr_ebay_oem','_sku') WHERE m.marketplace=%s AND m.status=%s AND m.remote_offer_id<>'' ORDER BY m.woo_product_id ASC LIMIT %d",'ebay','active',$buildLimit*8),ARRAY_A)?:[];
        $grouped=[]; foreach($rows as $r){ $pid=(int)($r['product_id']??0); if($pid<=0){ continue; } if(!isset($grouped[$pid])){ $grouped[$pid]=['product_id'=>$pid,'offer_id'=>(string)($r['offer_id']??''),'listing_id'=>(string)($r['listing_id']??''),'post_title'=>(string)($r['post_title']??''),'sku'=>(string)($r['sku']??''),'meta'=>[]]; } $mk=trim((string)($r['meta_key']??'')); if($mk!==''){ $grouped[$pid]['meta'][$mk][]=trim((string)($r['meta_value']??'')); } }
        $scanned=0; $queued=0; $skipOffer=0; $skipListing=0; $skipSku=0; $skipBasic=0; $skipBrand=0; $skipPart=0; $queuedFromMeta=0; $queuedFromTitle=0; $queuedFromInventory=0; $sampleSkip=[]; $sampleQueued=[];
        foreach(array_slice(array_values($grouped),0,$buildLimit) as $r){ $scanned++; $pid=(int)$r['product_id']; if($pid<=0||trim((string)$r['offer_id'])===''){ $skipOffer++; continue; } if(trim((string)$r['listing_id'])===''){ $skipListing++; continue; } $sku=trim((string)$r['sku']); if($sku===''){ $skipSku++; continue; } $det=$this->light_detect_basic_specifics($pid,(string)$r['post_title'],(array)$r['meta']); if(!$det['has_brand']||!$det['has_part']){ $skipBasic++; if(!$det['has_brand']){ $skipBrand++; } if(!$det['has_part']){ $skipPart++; } if(count($sampleSkip)<10){ $sampleSkip[]=['product_id'=>$pid,'title'=>(string)$r['post_title'],'detected_brand'=>(string)$det['brand'],'detected_part_numbers'=>$det['part_numbers']]; } continue; } $wpdb->insert($queueTable,['product_id'=>$pid,'reason'=>'basic_item_specifics_bulk_update','status'=>'pending','queued_at'=>$now,'updated_at'=>$now,'attempts'=>0,'last_error'=>null,'source'=>'basic_item_specifics_bulk']); $queued++; if($det['source']==='meta')$queuedFromMeta++; elseif($det['source']==='title_parse')$queuedFromTitle++; elseif($det['source']==='inventory_cache')$queuedFromInventory++; if(count($sampleQueued)<10){ $sampleQueued[]=['product_id'=>$pid,'title'=>(string)$r['post_title'],'detected_brand'=>(string)$det['brand'],'detected_part_numbers'=>$det['part_numbers'],'source'=>$det['source']]; } if($this->basic_specifics_memory_guard('build_loop',$status)){ break; }}
        $status=array_merge($status,['state'=>'pending','batch_size'=>$batchSize,'build_limit'=>$buildLimit,'total_queued'=>$queued,'remaining'=>$queued,'started_at'=>$now,'updated_at'=>$now,'checkpoint'=>'built']); update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false);
        $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_QUEUE_BUILD_START',['candidates_scanned'=>$scanned,'queued'=>$queued,'queued_from_meta'=>$queuedFromMeta,'queued_from_title_parse'=>$queuedFromTitle,'queued_from_inventory_cache'=>$queuedFromInventory,'skipped_no_offer'=>$skipOffer,'skipped_no_listing'=>$skipListing,'skipped_no_sku'=>$skipSku,'skipped_no_basic_data'=>$skipBasic,'skipped_no_brand'=>$skipBrand,'skipped_no_part_number'=>$skipPart,'sample_skipped_no_basic_data'=>$sampleSkip,'sample_queued'=>$sampleQueued,'memory_usage'=>memory_get_usage(true),'elapsed_ms'=>0,'used_light_sql'=>true,'used_content_resolver'=>false,'used_price_resolver'=>false,'used_shipping_resolver'=>false,'used_export_readiness'=>false,'ebay_api_calls'=>false]);
        return $status; }
    private function light_detect_basic_specifics(int $productId,string $title,array $meta): array { $brand=''; $parts=[]; $source=''; $metaKeys=['_manufacturer_part_number','_wei_fr_ebay_mpn','_wei_fr_ebay_herstellernummer','_wei_fr_ebay_oem']; $brandKeys=['_manufacturer','_wei_fr_ebay_manufacturer','_wei_fr_ebay_hersteller']; foreach($brandKeys as $k){ foreach((array)($meta[$k]??[]) as $v){ $v=trim((string)$v); if($v!==''){ $brand=$v; $source='meta'; break 2; } } } foreach($metaKeys as $k){ foreach((array)($meta[$k]??[]) as $v){ $parsed=$this->extract_light_part_numbers((string)$v); if(!empty($parsed)){ $parts=array_merge($parts,$parsed); $source=$source?:'meta'; } } } $inventory=(array)get_post_meta($productId,'_wei_fr_ebay_inventory_snapshot',true); $aspects=is_array($inventory['product']['aspects']??null)?$inventory['product']['aspects']:[]; if($brand==='' && !empty($aspects['Hersteller'][0])){ $brand=(string)$aspects['Hersteller'][0]; $source='inventory_cache'; } if(empty($parts)){ foreach(['MPN','Herstellernummer','Manufacturer Part Number'] as $k){ if(!empty($aspects[$k][0])){ $parts=array_merge($parts,$this->extract_light_part_numbers((string)$aspects[$k][0])); $source='inventory_cache'; } } } if($brand==='' || empty($parts)){ $t=$this->light_parse_title_basic_specifics($title); if($brand==='' && $t['brand']!==''){ $brand=$t['brand']; $source=$source?:'title_parse'; } if(empty($parts) && !empty($t['part_numbers'])){ $parts=$t['part_numbers']; $source=$source?:'title_parse'; } } $parts=array_values(array_unique(array_filter(array_map('trim',$parts)))); return ['has_brand'=>$brand!=='','has_part'=>!empty($parts),'brand'=>$brand,'part_numbers'=>$parts,'source'=>$source?:'meta']; }
    private function light_parse_title_basic_specifics(string $title): array { $brands=['Audi','Volkswagen','VW','Mercedes-Benz','Mercedes','BMW','Seat','Skoda','Škoda','Kia','Hyundai','Toyota','Ford','Opel','Renault','Peugeot','Citroen','Volvo','Land Rover','Range Rover','Porsche','Nissan','Mini','Fiat','Alfa Romeo','Jeep']; $found=''; foreach($brands as $b){ if(preg_match('/\b'.preg_quote($b,'/').'\b/iu',$title)){ $found=$b; break; } } return ['brand'=>$found,'part_numbers'=>$this->extract_light_part_numbers($title)]; }
    private function extract_light_part_numbers(string $text): array { preg_match_all('/\b[A-Z0-9\-]{3,20}\b/i',$text,$m); $out=[]; foreach((array)($m[0]??[]) as $tok){ $t=strtoupper(str_replace('-','',trim((string)$tok))); if(strlen($t)<5) continue; if(!preg_match('/[A-Z]/',$t) || !preg_match('/\d/',$t)) continue; if(preg_match('/^(19\d{2}|20[0-2]\d)$/',$t)) continue; if(preg_match('/^\d{2,4}PS$/',$t)) continue; if(preg_match('/^\d[.,]\d$/',$t)) continue; if(preg_match('/^(CYR|TCB|OCK|DNF|DFH|DXR|CDA|CCZ|BLS)$/',$t)) continue; if(preg_match('/^(8W|8P|8R|B6|B7|B8|W177|X204|F3|80A)$/',$t)) continue; if(preg_match('/^[BW]\d{1,3}$/',$t)) continue; if(preg_match('/^\d+P$/',$t)) continue; $out[]=$t; } usort($out, static fn(string $a,string $b): int => abs(strlen($a)-10) <=> abs(strlen($b)-10)); return array_values(array_unique($out)); }
    private function process_basic_specifics_bulk_batch(): array { global $wpdb; $queueTable=$wpdb->prefix.'wei_fr_ebay_sync_queue'; $status=$this->basic_specifics_bulk_status(); if(($status['state']??'')==='paused') return $status+['result'=>'skipped','reason'=>'paused']; if(!in_array((string)($status['state']??''),['running','pending'],true)) return $status+['result'=>'skipped','reason'=>'not_running']; if($this->basic_specifics_memory_guard('process_start',$status)){ return $status+['result'=>'guarded']; } $status['state']='running'; $requested=max(1,min(25,(int)($status['batch_size']??1))); $rows=$wpdb->get_results($wpdb->prepare("SELECT id,product_id,attempts FROM {$queueTable} WHERE reason=%s AND status=%s ORDER BY id ASC LIMIT %d",'basic_item_specifics_bulk_update','pending',$requested),ARRAY_A)?:[]; $processed=0; $startMem=memory_get_usage(true);
        foreach($rows as $row){ if($processed>=$requested){ break; } if($this->basic_specifics_memory_guard('process_loop',$status)){ break; } $processed++; $queueId=(int)$row['id']; $productId=(int)$row['product_id']; $wpdb->update($queueTable,['status'=>'processing','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$queueId]); try{ $elig=$this->adapter->basic_item_specifics_process_one_product_eligibility($productId); if(empty($elig['eligible'])){$status['processed']++;$status['skipped']++;$status['last_product_id']=$productId; $wpdb->update($queueTable,['status'=>'done','updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$queueId]); continue;} $res=$this->adapter->update_basic_item_specifics_single((string)$productId); $status['processed']++; $status['last_product_id']=$productId; if(($res['result']??'')==='success'){ if(!empty($res['changed']))$status['changed']++; else $status['unchanged']++; $wpdb->update($queueTable,['status'=>'done','updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$queueId]); } else { $status['failed']++; $status['last_error']=(string)($res['error']??'unknown_error'); $wpdb->update($queueTable,['status'=>'failed','attempts'=>(int)$row['attempts']+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>$status['last_error']],['id'=>$queueId]); } } catch(\Throwable $e){$status['processed']++;$status['failed']++;$status['last_product_id']=$productId;$status['last_error']=$e->getMessage();$wpdb->update($queueTable,['status'=>'failed','attempts'=>(int)$row['attempts']+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>$e->getMessage()],['id'=>$queueId]); } }
        $status=array_merge($status,$this->basic_specifics_bulk_queue_counts()); $status['updated_at']=gmdate('Y-m-d H:i:s'); $status['state']=((int)$status['remaining']<=0)?'done':'running'; update_option('wei_fr_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_BATCH_DONE',$status+['requested_batch_size'=>$requested,'actual_processed'=>$processed,'memory_usage_start'=>$startMem,'memory_usage_end'=>memory_get_usage(true),'used_safe_single_method'=>true,'skipped_normal_export_flow'=>true]); return $status+['result'=>'success']; }
    private function basic_specifics_bulk_status(): array { $status=get_option('wei_fr_ebay_basic_specifics_bulk_status',[]); $status=is_array($status)?array_merge($this->default_basic_specifics_bulk_status(),$status):$this->default_basic_specifics_bulk_status(); return array_merge($status,$this->basic_specifics_bulk_queue_counts()); }
    private function basic_specifics_bulk_queue_counts(): array { global $wpdb; $table=$wpdb->prefix.'wei_fr_ebay_sync_queue'; $rows=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS count FROM {$table} WHERE reason=%s GROUP BY status",'basic_item_specifics_bulk_update'),ARRAY_A); $counts=['pending'=>0,'processing'=>0,'done'=>0,'failed'=>0]; foreach((array)$rows as $r){$k=(string)($r['status']??''); if(isset($counts[$k])) $counts[$k]=(int)($r['count']??0);} $queued=$counts['pending']+$counts['processing']+$counts['done']+$counts['failed']; return ['total_queued'=>$queued,'remaining'=>$counts['pending']+$counts['processing'],'queue_done'=>$counts['done'],'queue_failed'=>$counts['failed']]; }
    private function default_basic_specifics_bulk_status(): array { return ['state'=>'stopped','batch_size'=>1,'build_limit'=>250,'total_queued'=>0,'processed'=>0,'remaining'=>0,'changed'=>0,'unchanged'=>0,'failed'=>0,'skipped'=>0,'last_product_id'=>0,'last_error'=>'bulk_queue_stopped_by_default_until_isolation_confirmed','started_at'=>'','updated_at'=>'','checkpoint'=>'stopped','reason'=>'basic_item_specifics_bulk_update']; }

    public function save_settings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_save_settings');
        $s = $this->settings();
        $s['environment'] = in_array($_POST['environment'] ?? 'production', ['sandbox', 'production'], true) ? $_POST['environment'] : 'production';
        $s['client_id'] = sanitize_text_field((string) ($_POST['client_id'] ?? ''));
        $postedClientSecret = sanitize_text_field((string) ($_POST['client_secret'] ?? ''));
        if ($postedClientSecret !== '') {
            $s['client_secret'] = $postedClientSecret;
        }
        $postedCallbackUrl = esc_url_raw((string) ($_POST['redirect_uri'] ?? ''));
        $postedRuname = sanitize_text_field((string) ($_POST['runame'] ?? ''));
        $s['redirect_uri'] = $this->normalize_fr_callback_url($postedCallbackUrl);
        $s['runame'] = $postedRuname !== '' ? $postedRuname : self::SHARED_EBAY_RUNAME;
        $s['marketplace_id'] = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $s['default_category_id'] = sanitize_text_field((string) ($_POST['default_category_id'] ?? ''));
        $defaultItemCondition = strtoupper(sanitize_text_field((string) ($_POST['default_item_condition'] ?? EbayConditionResolver::DEFAULT_ITEM_CONDITION)));
        $s['default_item_condition'] = $defaultItemCondition !== '' ? $defaultItemCondition : EbayConditionResolver::DEFAULT_ITEM_CONDITION;
        $s['default_country_of_origin'] = strtoupper(sanitize_text_field((string) ($_POST['default_country_of_origin'] ?? '')));
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
        $s['auto_sync_export_batch_size'] = max(self::PUBLISH_ACTION_MIN_BATCH_SIZE, min(self::PUBLISH_ACTION_MAX_BATCH_SIZE, absint($_POST['auto_sync_export_batch_size'] ?? 20)));
        $s['auto_sync_preflight_batch_size'] = max(1, min(300, absint($_POST['auto_sync_preflight_batch_size'] ?? 200)));
        $s['auto_sync_stock_batch_size'] = max(1, min(300, absint($_POST['auto_sync_stock_batch_size'] ?? 100)));
        $s['woo_to_ebay_stock_sync_enabled'] = !empty($_POST['woo_to_ebay_stock_sync_enabled']) ? 1 : 0;
        $s['ebay_order_sync_enabled'] = !empty($_POST['ebay_order_sync_enabled']) ? 1 : 0;
        $s['auto_export_enabled'] = !empty($_POST['auto_export_enabled']) ? 1 : 0;
        $s['auto_publish_enabled'] = !empty($_POST['auto_publish_enabled']) ? 1 : 0;
        $s['ebay_stock_sync_mode'] = in_array(($_POST['ebay_stock_sync_mode'] ?? 'max_one'), ['set_zero_only', 'max_one', 'exact_stock'], true) ? $_POST['ebay_stock_sync_mode'] : 'max_one';
        $s['ebay_order_stock_update_mode'] = in_array(($_POST['ebay_order_stock_update_mode'] ?? 'set_zero'), ['set_zero', 'reduce'], true) ? $_POST['ebay_order_stock_update_mode'] : 'set_zero';
        $translationSettings = $this->save_translation_provider_settings($_POST, $s);
        $s = array_merge($s, $translationSettings);
        $s['auto_generate_french_content_preflight'] = !empty($_POST['auto_generate_french_content_preflight']) ? 1 : 0;
        $s['enable_ebay_fr_description_template'] = !empty($_POST['enable_ebay_fr_description_template']) ? 1 : 0;
        $s['ebay_fr_delivery_map_url'] = esc_url_raw((string) ($_POST['ebay_fr_delivery_map_url'] ?? ''));
        $s['ebay_seller_username'] = trim(sanitize_text_field((string) ($_POST['ebay_seller_username'] ?? ($s['ebay_seller_username'] ?? ''))));
        if ($s['ebay_seller_username'] === '') {
            $s['ebay_seller_username'] = Plugin::DEFAULT_EBAY_SELLER_USERNAME;
        }
        $s['regenerate_french_content_on_hash_change'] = !empty($_POST['regenerate_french_content_on_hash_change']) ? 1 : 0;
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? 'gpswiss-pl'));
        $s['inventory_location_name'] = sanitize_text_field((string) ($_POST['inventory_location_name'] ?? 'gpswiss-pl'));
        $s['inventory_location_country'] = sanitize_text_field((string) ($_POST['inventory_location_country'] ?? 'PL'));
        $s['inventory_location_postal_code'] = sanitize_text_field((string) ($_POST['inventory_location_postal_code'] ?? '08-460'));
        $s['inventory_location_city'] = sanitize_text_field((string) ($_POST['inventory_location_city'] ?? 'Sobolew'));
        $s['inventory_location_address_line_1'] = sanitize_text_field((string) ($_POST['inventory_location_address_line_1'] ?? ''));
        $s['shipping_policy_30'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_30', ['fulfillment_policy_id_30_eur', 'fulfillmentPolicyId', 'ebay_fulfillment_policy_id']);
        $s['shipping_policy_50'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_50', ['fulfillment_policy_id_50_eur']);
        $s['shipping_policy_130'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_130', ['fulfillment_policy_id_130_eur']);
        $s['default_shipping_policy_id'] = self::posted_shipping_policy_id($_POST, 'default_shipping_policy_id', ['default_shipping_policy']);
        $s['shipping_policy_name_30'] = self::posted_shipping_policy_name($_POST, 'shipping_policy_30') ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_30']);
        $s['shipping_policy_30_name'] = $s['shipping_policy_name_30'];
        $s['default_shipping_policy'] = $s['default_shipping_policy_id'];
        $s['shipping_policy_name_50'] = sanitize_text_field((string) ($_POST['shipping_policy_name_50'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_50']);
        $s['shipping_policy_name_130'] = sanitize_text_field((string) ($_POST['shipping_policy_name_130'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_130']);
        $s['default_shipping_policy_name'] = sanitize_text_field((string) ($_POST['default_shipping_policy_name'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['default_shipping_policy_id']);
        $s['ebay_payment_policy_id'] = self::posted_shipping_policy_id($_POST, 'ebay_payment_policy_id', ['paymentPolicyId', 'payment_policy']);
        $s['ebay_return_policy_id'] = self::posted_shipping_policy_id($_POST, 'ebay_return_policy_id', ['returnPolicyId', 'return_policy']);
        $s['ebay_payment_policy_name'] = sanitize_text_field((string) ($_POST['ebay_payment_policy_name'] ?? '')) ?: $this->cached_business_policy_name($s, 'paymentPolicies', 'paymentPolicyId', $s['ebay_payment_policy_id']);
        $s['ebay_return_policy_name'] = sanitize_text_field((string) ($_POST['ebay_return_policy_name'] ?? '')) ?: $this->cached_business_policy_name($s, 'returnPolicies', 'returnPolicyId', $s['ebay_return_policy_id']);
        $s['fulfillment_policy_id_30_eur'] = $s['shipping_policy_30'];
        $s['fulfillment_policy_id_50_eur'] = $s['shipping_policy_50'];
        $s['fulfillment_policy_id_130_eur'] = $s['shipping_policy_130'];
        $s['ebay_fulfillment_policy_id'] = $s['shipping_policy_30'];
        $s['shipping_category_ids_30'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_30'] ?? '')));
        $s['shipping_category_ids_50'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_50'] ?? $_POST['shipping_category_ids_50_eur'] ?? '')));
        $s['shipping_category_ids_130'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_130'] ?? $_POST['shipping_category_ids_130_eur'] ?? '')));
        $conflicts = EbayShippingPolicyResolver::conflict_ids($s);
        update_option('wei_fr_ebay_shipping_mapping_warnings', $conflicts === [] ? [] : ['conflicts' => $conflicts, 'message' => 'Woo category ID appears in multiple eBay shipping groups. Runtime priority is Wysyłka 130 > Wysyłka 50 > Wysyłka 30.'], false);
        $this->sync_product_category_overrides($s['product_category_overrides']);
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&saved=1' . (!empty($conflicts) ? '&shipping_mapping_conflicts=1' : '')));
        exit;
    }


    public static function posted_shipping_policy_id(array $post, string $key, array $legacyKeys = []): string
    {
        $keys = array_values(array_unique(array_merge([$key], $legacyKeys, self::shipping_policy_field_aliases($key))));

        foreach ($keys as $fieldKey) {
            $manual = sanitize_text_field((string) ($post[$fieldKey . '_manual'] ?? ''));
            if ($manual !== '') {
                return $manual;
            }
        }

        foreach ($keys as $fieldKey) {
            $selected = sanitize_text_field((string) ($post[$fieldKey] ?? ''));
            if ($selected !== '') {
                return $selected;
            }
        }

        foreach ($keys as $fieldKey) {
            $existing = sanitize_text_field((string) ($post[$fieldKey . '_existing'] ?? ''));
            if ($existing !== '') {
                return $existing;
            }
        }

        return '';
    }

    public static function posted_shipping_policy_name(array $post, string $key): string
    {
        $suffix = preg_match('/_(\d+)$/', $key, $matches) ? (string) $matches[1] : '';
        $nameKeys = [$key . '_name'];
        if ($suffix !== '') {
            $nameKeys[] = 'shipping_policy_name_' . $suffix;
        }

        foreach ($nameKeys as $nameKey) {
            $name = sanitize_text_field((string) ($post[$nameKey] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /** @return array<int,string> */
    private static function shipping_policy_field_aliases(string $key): array
    {
        return match ($key) {
            'default_shipping_policy_id' => ['default_shipping_policy'],
            'default_shipping_policy' => ['default_shipping_policy_id'],
            default => [],
        };
    }


    public function save_stock_sync_settings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_save_stock_sync_settings');

        $s = $this->settings();
        $s['stock_sync_enabled'] = (!empty($_POST['stock_sync_woo_to_ebay_enabled']) || !empty($_POST['stock_sync_ebay_to_woo_enabled'])) ? 1 : 0;
        $s['stock_sync_woo_to_ebay_enabled'] = !empty($_POST['stock_sync_woo_to_ebay_enabled']) ? 1 : 0;
        $s['stock_sync_ebay_to_woo_enabled'] = !empty($_POST['stock_sync_ebay_to_woo_enabled']) ? 1 : 0;
        $interval = sanitize_key((string) ($_POST['stock_sync_cron_interval'] ?? 'every_15_minutes'));
        $s['stock_sync_cron_interval'] = in_array($interval, ['every_5_minutes', 'every_15_minutes', 'hourly'], true) ? $interval : 'every_15_minutes';
        $s['stock_sync_dry_run'] = !empty($_POST['stock_sync_dry_run']) ? 1 : 0;
        $s['stock_sync_safety_limit'] = max(1, min(500, absint($_POST['stock_sync_safety_limit'] ?? 50)));
        $zeroAction = sanitize_key((string) ($_POST['stock_sync_woo_zero_action'] ?? 'end_listing'));
        $s['stock_sync_woo_zero_action'] = in_array($zeroAction, ['end_listing', 'set_quantity_zero'], true) ? $zeroAction : 'end_listing';
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_clear_scheduled_hook(StockSyncService::CRON_HOOK);
        $this->stockSync->ensure_scheduled();
        $this->set_status('Stock synchronization settings saved.');
        $this->go();
    }

    public function run_stock_sync_dry_run(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_run_stock_sync_dry_run');
        $summary = $this->stockSync->run(true);
        $this->set_status('Stock sync dry run: ' . wp_json_encode($summary));
        $this->go();
    }

    public function run_stock_sync_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_run_stock_sync_now');
        $summary = $this->stockSync->run(false);
        $this->set_status('Stock sync run: ' . wp_json_encode($summary));
        $this->go();
    }

    public function stock_sync_diagnostics(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_stock_sync_diagnostics');
        $diag = $this->stockSync->product_diagnostics(sanitize_text_field((string) ($_POST['product_or_sku'] ?? '')));
        update_option('wei_fr_ebay_stock_sync_last_diagnostics', $diag, false);
        $this->set_status('Stock sync diagnostics: ' . wp_json_encode($diag));
        $this->go();
    }


    public function save_translation_provider_settings_action(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_save_translation_provider_settings');
        $s = $this->settings();
        $this->save_translation_provider_settings($_POST, $s);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&saved=1&wei_fr_section=french-content#wei-fr-translation-provider'));
        exit;
    }

    public function save_ebay_settings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_save_ebay_settings');

        $s = $this->settings();
        $defaultMarkupRaw = trim((string) ($_POST['ebay_default_markup_percent'] ?? ''));
        $defaultMarkup = $defaultMarkupRaw === '' ? 25.0 : (float) str_replace(',', '.', $defaultMarkupRaw);
        $s['ebay_default_markup_percent'] = $defaultMarkup > 0 ? round($defaultMarkup, 4) : 25;
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? ($s['inventory_location_key'] ?? '')));

        $s['shipping_policy_30'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_30');
        $s['shipping_policy_50'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_50');
        $s['shipping_policy_130'] = self::posted_shipping_policy_id($_POST, 'shipping_policy_130');
        $s['default_shipping_policy_id'] = self::posted_shipping_policy_id($_POST, 'default_shipping_policy_id', ['default_shipping_policy']);
        $s['shipping_policy_name_30'] = self::posted_shipping_policy_name($_POST, 'shipping_policy_30') ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_30']);
        $s['shipping_policy_30_name'] = $s['shipping_policy_name_30'];
        $s['default_shipping_policy'] = $s['default_shipping_policy_id'];
        $s['shipping_policy_name_50'] = sanitize_text_field((string) ($_POST['shipping_policy_name_50'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_50']);
        $s['shipping_policy_name_130'] = sanitize_text_field((string) ($_POST['shipping_policy_name_130'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['shipping_policy_130']);
        $s['default_shipping_policy_name'] = sanitize_text_field((string) ($_POST['default_shipping_policy_name'] ?? '')) ?: $this->cached_fulfillment_policy_name($s, $s['default_shipping_policy_id']);
        $s['ebay_payment_policy_id'] = self::posted_shipping_policy_id($_POST, 'ebay_payment_policy_id', ['paymentPolicyId', 'payment_policy']);
        $s['ebay_return_policy_id'] = self::posted_shipping_policy_id($_POST, 'ebay_return_policy_id', ['returnPolicyId', 'return_policy']);
        $s['ebay_payment_policy_name'] = sanitize_text_field((string) ($_POST['ebay_payment_policy_name'] ?? '')) ?: $this->cached_business_policy_name($s, 'paymentPolicies', 'paymentPolicyId', $s['ebay_payment_policy_id']);
        $s['ebay_return_policy_name'] = sanitize_text_field((string) ($_POST['ebay_return_policy_name'] ?? '')) ?: $this->cached_business_policy_name($s, 'returnPolicies', 'returnPolicyId', $s['ebay_return_policy_id']);
        $s['fulfillment_policy_id_30_eur'] = $s['shipping_policy_30'];
        $s['fulfillment_policy_id_50_eur'] = $s['shipping_policy_50'];
        $s['fulfillment_policy_id_130_eur'] = $s['shipping_policy_130'];
        $s['ebay_fulfillment_policy_id'] = $s['shipping_policy_30'];
        $s['shipping_category_ids_30'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_30'] ?? '')));
        $s['shipping_category_ids_50'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_50'] ?? '')));
        $s['shipping_category_ids_130'] = EbayShippingPolicyResolver::normalize_id_list(sanitize_textarea_field((string) ($_POST['shipping_category_ids_130'] ?? '')));

        $conflicts = EbayShippingPolicyResolver::conflict_ids($s);
        update_option('wei_fr_ebay_shipping_mapping_warnings', $conflicts === [] ? [] : ['conflicts' => $conflicts, 'message' => 'Woo category ID appears in multiple eBay shipping groups. Runtime priority is Wysyłka 130 > Wysyłka 50 > Wysyłka 30.'], false);
        update_option(Plugin::OPTION_KEY, $s, false);

        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&saved=1&wei_fr_section=ebay-settings' . (!empty($conflicts) ? '&shipping_mapping_conflicts=1' : '')));
        exit;
    }

    public function shipping_mapping_diagnostics(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_mapping_diagnostics');

        $productId = absint($_POST['product_id'] ?? 0);
        $settings = $this->settings();
        $resolution = $productId > 0 ? EbayShippingPolicyResolver::resolve_for_product($productId, $settings) : [
            'reason' => 'missing_product_id',
            'blocked' => true,
        ];
        $product = $productId > 0 && function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $priceResolution = $product ? $this->priceResolver->resolve($product, $productId, $settings, true) : [];
        $businessPolicyResolution = EbayAdapter::business_policy_resolution_for_settings($settings, $resolution, (string) ($settings['inventory_location_key'] ?? ''));
        $diagnostics = [
            'product_id' => $productId,
            'product_title' => $product && method_exists($product, 'get_name') ? (string) $product->get_name() : (string) get_the_title($productId),
            'woo_category_ids' => array_values(array_map('intval', (array) ($resolution['woo_category_ids'] ?? []))),
            'matched_shipping_group' => (string) ($resolution['group'] ?? ''),
            'matched_woo_category_id' => (int) ($resolution['matched_woo_category_id'] ?? 0),
            'selected_shipping_policy_id' => (string) ($resolution['policy_id'] ?? ''),
            'selected_shipping_policy_name' => (string) ($resolution['policy_name'] ?? ''),
            'selected_fulfillment_policy_id' => (string) ($businessPolicyResolution['selected_fulfillment_policy_id'] ?? ''),
            'selected_fulfillment_policy_name' => (string) ($businessPolicyResolution['selected_fulfillment_policy_name'] ?? ''),
            'selected_payment_policy_id' => (string) ($businessPolicyResolution['selected_payment_policy_id'] ?? ''),
            'selected_payment_policy_name' => (string) ($businessPolicyResolution['selected_payment_policy_name'] ?? ''),
            'selected_return_policy_id' => (string) ($businessPolicyResolution['selected_return_policy_id'] ?? ''),
            'selected_return_policy_name' => (string) ($businessPolicyResolution['selected_return_policy_name'] ?? ''),
            'merchant_location_key' => (string) ($businessPolicyResolution['merchant_location_key'] ?? ''),
            'missing_fulfillment_policy' => !empty($businessPolicyResolution['missing_fulfillment_policy']) ? 'yes' : 'no',
            'missing_payment_policy' => !empty($businessPolicyResolution['missing_payment_policy']) ? 'yes' : 'no',
            'missing_return_policy' => !empty($businessPolicyResolution['missing_return_policy']) ? 'yes' : 'no',
            'missing_merchant_location' => !empty($businessPolicyResolution['missing_merchant_location']) ? 'yes' : 'no',
            'business_policy_problem_reason' => (string) ($businessPolicyResolution['business_policy_problem_reason'] ?? ''),
            'fallback_default_used' => !empty($resolution['default_used']) ? 'yes' : 'no',
            'default_policy_used' => !empty($resolution['default_used']) ? 'yes' : 'no',
            'missing_shipping_policy_mapping' => !empty($resolution['blocked']) || (string) ($resolution['reason'] ?? '') === 'missing_shipping_policy_mapping' ? 'yes' : 'no',
            'reason' => (string) ($resolution['reason'] ?? ''),
            'woo_price_pln' => $priceResolution['woo_price_pln'] ?? $priceResolution['base_price_pln'] ?? null,
            'markup_percent' => $priceResolution['markup_percent'] ?? null,
            'price_after_markup_pln' => $priceResolution['price_after_markup_pln'] ?? $priceResolution['marked_price_pln'] ?? null,
            'exchange_rate' => $priceResolution['exchange_rate'] ?? $priceResolution['nbp_rate'] ?? null,
            'ebay_price_eur' => $priceResolution['ebay_price_eur'] ?? null,
            'ebay_api_called' => false,
            'product_or_listing_modified' => false,
        ];

        $this->set_status('Shipping mapping diagnostics: ' . wp_json_encode($diagnostics));
        $this->go();
    }

    public function generate_shipping_mapping_report(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_shipping_mapping_report');

        $report = $this->empty_shipping_mapping_report();
        $warnings = [];

        try {
            $this->logger->info('EBAY_SHIPPING_MAPPING_REPORT_START', [
                'memory_usage' => memory_get_usage(true),
                'memory_limit' => ini_get('memory_limit'),
            ]);

            $settings = $this->settings();
            $categoryGroups = EbayShippingPolicyResolver::category_group_maps($settings);
            $categoryIds50 = $categoryGroups[EbayShippingPolicyResolver::GROUP_PARCEL_50_EUR] ?? [];
            $categoryIds130 = $categoryGroups[EbayShippingPolicyResolver::GROUP_SHIPPING_130] ?? [];
            $allMappedCategoryIds = array_values(array_unique(array_merge($categoryIds50, $categoryIds130)));

            $this->guard_shipping_mapping_report_memory($report, 'after_settings');

            $terms = $this->shipping_mapping_product_category_terms();
            $termSamples = [];
            $unmappedSamples = [];
            foreach ($terms as $term) {
                $termId = (int) ($term['term_id'] ?? 0);
                if ($termId <= 0) {
                    continue;
                }

                $group = 'shipping_30';
                if (in_array($termId, $categoryIds130, true)) {
                    $group = 'shipping_130';
                } elseif (in_array($termId, $categoryIds50, true)) {
                    $group = 'shipping_50';
                }

                $sample = [
                    'term_id' => $termId,
                    'name' => (string) ($term['name'] ?? ''),
                    'slug' => (string) ($term['slug'] ?? ''),
                    'parent' => (int) ($term['parent'] ?? 0),
                    'woo_count' => (int) ($term['count'] ?? 0),
                    'shipping_group' => $group,
                ];

                if (count($termSamples) < 100) {
                    $termSamples[] = $sample;
                }
                if ($group === 'shipping_30' && count($unmappedSamples) < 100) {
                    $unmappedSamples[] = $sample;
                }
            }

            if (count($terms) > 100) {
                $warnings[] = 'Category details were limited to 100 sample terms to keep the report small.';
            }

            $this->guard_shipping_mapping_report_memory($report, 'after_terms');

            $totalProducts = $this->count_products_for_shipping_mapping();
            $products130 = $this->count_products_for_shipping_mapping($categoryIds130, [], true);
            $products50 = $this->count_products_for_shipping_mapping($categoryIds50, $categoryIds130, true);
            $productsMapped = $this->count_products_for_shipping_mapping($allMappedCategoryIds, [], true);
            $productsDefault30 = max(0, $totalProducts - $productsMapped);
            $estimatedProductsTotal = $products130 + $products50 + $productsDefault30;
            $estimatedProductsDifference = $totalProducts - $estimatedProductsTotal;
            if ($estimatedProductsDifference !== 0) {
                $warnings[] = 'Shipping mapping estimate total differs from total products by ' . $estimatedProductsDifference . '; check overlapping categories or product category assignments.';
            }

            if ($totalProducts > 100) {
                $warnings[] = 'Product-level details were not scanned; report uses lightweight SQL counts only.';
            }

            $report = [
                'generated_at' => gmdate('Y-m-d H:i:s'),
                'category_ids_130' => $categoryIds130,
                'category_ids_50' => $categoryIds50,
                'count_categories_130' => count($categoryIds130),
                'count_categories_50' => count($categoryIds50),
                'estimated_products_130' => $products130,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'total_products' => $totalProducts,
                'estimated_products_total' => $estimatedProductsTotal,
                'estimated_products_difference' => $estimatedProductsDifference,
                'counts' => [
                    '30_eur' => $productsDefault30,
                    '50_eur' => $products50,
                    '130' => $products130,
                    'shipping_30' => $productsDefault30,
                ],
                'sample_terms' => $termSamples,
                'unmapped_categories' => $unmappedSamples,
                'warnings' => $warnings,
                'mass_update_enabled' => false,
                'partial' => false,
                'note' => 'Raport generowany ręcznie i liczony lekkimi zapytaniami SQL po kategoriach; nie ładuje produktów WooCommerce ani pełnego postmeta. Masowa aktualizacja fulfillment policy pozostaje wyłączona.',
            ];

            $this->guard_shipping_mapping_report_memory($report, 'before_save');
            update_option('wei_fr_ebay_shipping_mapping_report', $report, false);

            $this->logger->info('EBAY_SHIPPING_MAPPING_REPORT_DONE', [
                'total_products' => $totalProducts,
                'estimated_products_130' => $products130,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'sample_terms' => count($termSamples),
                'warnings' => count($warnings),
                'memory_usage' => memory_get_usage(true),
            ]);
            $this->set_status('Shipping mapping report generated: ' . wp_json_encode([
                'total_products' => $totalProducts,
                'estimated_products_130' => $products130,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'warnings' => count($warnings),
            ]));
        } catch (\Throwable $e) {
            $report['partial'] = true;
            $report['warnings'][] = 'Report stopped before completion: ' . $e->getMessage();
            update_option('wei_fr_ebay_shipping_mapping_report', $report, false);
            $this->logger->error('EBAY_SHIPPING_MAPPING_REPORT_ERROR', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'memory_usage' => memory_get_usage(true),
                'memory_limit' => ini_get('memory_limit'),
            ]);
            $this->set_status('Shipping mapping report failed gracefully: ' . wp_json_encode([
                'error' => $e->getMessage(),
                'partial' => true,
            ]));
        }

        $this->go();
    }


    public function update_shipping_policy_one(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_update_shipping_policy_one');
        $productId = absint($_POST['product_id'] ?? 0);
        $sku = sanitize_text_field((string) ($_POST['sku'] ?? ''));
        if ($productId <= 0 && $sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $productId = (int) wc_get_product_id_by_sku($sku);
        }
        if ($productId <= 0) {
            $result = ['result' => 'error', 'error' => 'product_id_or_sku_required', 'sku' => $sku];
            $this->logger->error('EBAY_SHIPPING_POLICY_SINGLE_ERROR', $result);
            $this->set_status('Shipping policy one product: ' . wp_json_encode($result));
            $this->go();
        }

        $res = $this->adapter->update_fulfillment_policy_only($productId);
        $this->set_status('Shipping policy one product: ' . wp_json_encode($this->limit_nested_array($res, 20)));
        $this->go();
    }

    public function shipping_policy_bulk_start(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_policy_bulk_start');
        $batchSize = absint($_POST['batch_size'] ?? 50);
        $batchSize = max(1, min(50, $batchSize));
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_QUEUE_START', [
            'batch_size' => $batchSize,
            'safe_update_scope' => 'listingPolicies.fulfillmentPolicyId_only',
            'called_create_offer' => false,
            'called_publish_offer' => false,
        ]);
        $summary = $this->build_shipping_policy_bulk_queue($batchSize);
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_QUEUE_BUILT', $summary);
        $this->set_status('Shipping policy bulk queue built: ' . wp_json_encode($summary));
        $this->go();
    }

    public function shipping_policy_bulk_pause(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_policy_bulk_pause');
        $status = $this->shipping_policy_bulk_status();
        $status['state'] = 'paused';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_fr_ebay_shipping_policy_bulk_status', $status, false);
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_PAUSED', $status);
        $this->set_status('Shipping policy bulk paused: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_resume(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_policy_bulk_resume');
        $status = $this->shipping_policy_bulk_status();
        $status['state'] = 'running';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_fr_ebay_shipping_policy_bulk_status', $status, false);
        $this->set_status('Shipping policy bulk resumed: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_stop(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_policy_bulk_stop');
        global $wpdb;
        $table = $wpdb->prefix . 'wei_fr_ebay_sync_queue';
        $wpdb->delete($table, ['reason' => 'fulfillment_policy_update']);
        $status = $this->default_shipping_policy_bulk_status();
        $status['state'] = 'stopped';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_fr_ebay_shipping_policy_bulk_status', $status, false);
        $this->set_status('Shipping policy bulk stopped and queue cleared: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_process(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_shipping_policy_bulk_process');
        $res = $this->process_shipping_policy_bulk_batch();
        $this->set_status('Shipping policy bulk batch: ' . wp_json_encode($this->limit_nested_array($res, 20)));
        $this->go();
    }

    private function build_shipping_policy_bulk_queue(int $batchSize): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_fr_ebay_sync_queue';
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->delete($queueTable, ['reason' => 'fulfillment_policy_update']);

        $productIds = [];
        $mappingIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT woo_product_id FROM {$mappingTable} WHERE marketplace=%s AND status=%s AND remote_offer_id IS NOT NULL AND remote_offer_id<>''",
            'ebay',
            'active'
        ));
        foreach ((array) $mappingIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $productIds[$id] = $id;
            }
        }

        $metaIds = $wpdb->get_col(
            "SELECT DISTINCT offer_meta.post_id
             FROM {$wpdb->postmeta} offer_meta
             LEFT JOIN {$wpdb->postmeta} status_meta ON status_meta.post_id = offer_meta.post_id AND status_meta.meta_key = '_wei_fr_ebay_listing_status'
             WHERE offer_meta.meta_key = '_wei_fr_ebay_offer_id'
               AND offer_meta.meta_value <> ''
               AND status_meta.meta_value IN ('active','published')"
        );
        foreach ((array) $metaIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $productIds[$id] = $id;
            }
        }

        foreach (array_values($productIds) as $productId) {
            $wpdb->insert($queueTable, [
                'product_id' => $productId,
                'reason' => 'fulfillment_policy_update',
                'status' => 'pending',
                'queued_at' => $now,
                'updated_at' => $now,
                'attempts' => 0,
                'last_error' => null,
                'source' => 'shipping_policy_changed',
            ]);
        }

        $status = $this->default_shipping_policy_bulk_status();
        $status['state'] = 'running';
        $status['batch_size'] = $batchSize;
        $status['total_queued'] = count($productIds);
        $status['remaining'] = count($productIds);
        $status['started_at'] = $now;
        $status['updated_at'] = $now;
        update_option('wei_fr_ebay_shipping_policy_bulk_status', $status, false);
        return $status;
    }

    private function process_shipping_policy_bulk_batch(): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_fr_ebay_sync_queue';
        $status = $this->shipping_policy_bulk_status();
        if ((string) ($status['state'] ?? '') === 'paused') {
            $this->logger->info('EBAY_SHIPPING_POLICY_BULK_PAUSED', $status);
            return $status + ['result' => 'skipped', 'reason' => 'paused'];
        }
        if (!in_array((string) ($status['state'] ?? ''), ['running', 'pending'], true)) {
            return $status + ['result' => 'skipped', 'reason' => 'not_running'];
        }

        $batchSize = max(1, min(50, (int) ($status['batch_size'] ?? 50)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, product_id, attempts FROM {$queueTable} WHERE reason=%s AND status=%s ORDER BY id ASC LIMIT %d",
            'fulfillment_policy_update',
            'pending',
            $batchSize
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_BATCH_START', [
            'batch_size' => $batchSize,
            'selected' => count($rows),
            'status' => $status,
        ]);

        foreach ($rows as $row) {
            $queueId = (int) ($row['id'] ?? 0);
            $productId = (int) ($row['product_id'] ?? 0);
            $wpdb->update($queueTable, ['status' => 'processing', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $queueId]);
            try {
                $res = $this->adapter->update_fulfillment_policy_only($productId);
                $status['processed'] = (int) ($status['processed'] ?? 0) + 1;
                $status['last_product_id'] = $productId;
                if (($res['result'] ?? '') === 'success') {
                    if (!empty($res['changed'])) {
                        $status['changed'] = (int) ($status['changed'] ?? 0) + 1;
                    } else {
                        $status['unchanged'] = (int) ($status['unchanged'] ?? 0) + 1;
                    }
                    $wpdb->update($queueTable, ['status' => 'done', 'updated_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null], ['id' => $queueId]);
                } else {
                    $status['failed'] = (int) ($status['failed'] ?? 0) + 1;
                    $status['last_error'] = (string) ($res['error'] ?? $res['message'] ?? 'unknown_error');
                    $this->logger->error('EBAY_SHIPPING_POLICY_PRODUCT_FAILED', ['product_id' => $productId, 'result' => $res]);
                    $wpdb->update($queueTable, [
                        'status' => 'failed',
                        'attempts' => (int) ($row['attempts'] ?? 0) + 1,
                        'last_error' => $status['last_error'],
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ], ['id' => $queueId]);
                }
            } catch (\Throwable $e) {
                $status['processed'] = (int) ($status['processed'] ?? 0) + 1;
                $status['failed'] = (int) ($status['failed'] ?? 0) + 1;
                $status['last_product_id'] = $productId;
                $status['last_error'] = $e->getMessage();
                $this->logger->error('EBAY_SHIPPING_POLICY_PRODUCT_FAILED', ['product_id' => $productId, 'error' => $e->getMessage()]);
                $wpdb->update($queueTable, [
                    'status' => 'failed',
                    'attempts' => (int) ($row['attempts'] ?? 0) + 1,
                    'last_error' => $e->getMessage(),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ], ['id' => $queueId]);
            }
        }

        $status = array_merge($status, $this->shipping_policy_bulk_queue_counts());
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        if ((int) ($status['remaining'] ?? 0) <= 0) {
            $status['state'] = 'done';
            $this->logger->info('EBAY_SHIPPING_POLICY_BULK_DONE', $status);
        } else {
            $status['state'] = 'running';
        }
        update_option('wei_fr_ebay_shipping_policy_bulk_status', $status, false);
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_BATCH_DONE', $status);
        return $status + ['result' => 'success'];
    }

    private function shipping_policy_bulk_status(): array
    {
        $status = get_option('wei_fr_ebay_shipping_policy_bulk_status', []);
        $status = is_array($status) ? array_merge($this->default_shipping_policy_bulk_status(), $status) : $this->default_shipping_policy_bulk_status();
        return array_merge($status, $this->shipping_policy_bulk_queue_counts());
    }

    private function shipping_policy_bulk_queue_counts(): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_fr_ebay_sync_queue';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS count FROM {$queueTable} WHERE reason=%s GROUP BY status",
            'fulfillment_policy_update'
        ), ARRAY_A);
        $counts = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed_queue' => 0];
        foreach ((array) $rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if ($key === 'failed') {
                $counts['failed_queue'] = (int) ($row['count'] ?? 0);
            } elseif (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['count'] ?? 0);
            }
        }
        $queued = $counts['pending'] + $counts['processing'] + $counts['done'] + $counts['failed_queue'];
        return [
            'total_queued' => $queued,
            'remaining' => $counts['pending'] + $counts['processing'],
            'queue_done' => $counts['done'],
            'queue_failed' => $counts['failed_queue'],
        ];
    }

    private function default_shipping_policy_bulk_status(): array
    {
        return [
            'state' => 'idle',
            'batch_size' => 50,
            'total_queued' => 0,
            'processed' => 0,
            'remaining' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'last_product_id' => 0,
            'last_error' => '',
            'started_at' => '',
            'updated_at' => '',
            'reason' => 'fulfillment_policy_update',
        ];
    }

    public function start_oauth_connect(): void { $this->require_manage_options(); check_admin_referer('wei_fr_start_oauth_connect'); $this->auth->redirect_to_authorize_url(); exit; }
    public function clear_oauth_diagnostics(): void { $this->require_manage_options(); check_admin_referer('wei_fr_clear_oauth_diagnostics'); $this->auth->clear_oauth_diagnostics(); $this->set_status('FR OAuth diagnostics cleared.'); $this->go(); }
    public function disconnect(): void { $this->require_manage_options(); check_admin_referer('wei_fr_disconnect'); $this->auth->disconnect(); $this->set_status('Disconnected'); $this->go(); }
    public function test_connection(): void { $this->require_manage_options(); check_admin_referer('wei_fr_test'); $res = $this->auth->get_valid_access_token(); $this->set_status(is_wp_error($res) ? 'Test failed: '.$res->get_error_message() : 'Connection OK'); $this->go(); }
    public function run_readiness(): void { $this->require_manage_options(); check_admin_referer('wei_fr_readiness'); $res = $this->adapter->readiness_check(); $this->set_status('Readiness: '.wp_json_encode($res)); $this->go(); }

    public function generate_ebay_skus(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_ebay_skus');
        $batchSize = absint($_POST['batch_size'] ?? 200);
        $runId = sanitize_text_field((string) ($_POST['run_id'] ?? ''));
        $res = $this->skuGenerator->generate_missing_batch($runId !== '' ? $runId : null, $batchSize);
        $this->set_status('Generate missing eBay SKUs: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_map_categories(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_auto_map_categories');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $res = $this->autoCategoryMapper->auto_map_used_categories($marketplaceId, 200);
        $this->set_status('Auto category mapping: ' . wp_json_encode($res));
        $this->go();
    }



    public function generate_ebay_fr_category_suggestions(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_ebay_fr_category_suggestions');

        $limit = max(1, min(500, absint($_POST['limit'] ?? 50)));
        $mode = sanitize_text_field((string) ($_POST['mode'] ?? 'leaf_with_products'));
        $forceRefresh = !empty($_POST['force_refresh']);
        $restart = !empty($_POST['restart']);
        $reporter = new EbayCategorySuggestionReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        try {
            $res = $reporter->generate([
                'limit' => $limit,
                'mode' => $mode,
                'force_refresh' => $forceRefresh,
                'restart' => $restart,
            ]);
        } catch (\Throwable $e) {
            $res = ['result' => 'error', 'error' => $e->getMessage(), 'marketplace_id' => EbayCategorySuggestionReportService::MARKETPLACE_ID];
        }

        $this->set_status('eBay.fr category suggestions: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_all_ebay_fr_category_suggestions(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_all_ebay_fr_category_suggestions');

        $mode = sanitize_text_field((string) ($_POST['mode'] ?? 'leaf_with_products'));
        $forceRefresh = !empty($_POST['force_refresh']);
        $continueFromProgress = !empty($_POST['continue_from_progress']);
        $reporter = new EbayCategorySuggestionReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        try {
            $res = $reporter->generate_all([
                'mode' => $mode,
                'force_refresh' => $forceRefresh,
                'continue_from_progress' => $continueFromProgress,
                'restart' => !$continueFromProgress,
            ]);
        } catch (\Throwable $e) {
            $progress = get_option(EbayCategorySuggestionReportService::CHECKPOINT_OPTION, []);
            $progress = is_array($progress) ? $progress : [];
            $summary = is_array($progress['summary'] ?? null) ? $progress['summary'] : [];
            $summary['status'] = 'interrupted';
            $summary['error'] = $e->getMessage();
            $summary['processed'] = (int) ($progress['processed'] ?? 0);
            $summary['total'] = (int) ($progress['total'] ?? 0);
            $summary['last_update_at'] = gmdate('c');
            update_option(EbayCategorySuggestionReportService::LAST_SUMMARY_OPTION, $summary, false);
            update_option(EbayCategorySuggestionReportService::CHECKPOINT_OPTION, array_merge($progress, ['status' => 'interrupted', 'summary' => $summary, 'last_update_at' => gmdate('c')]), false);
            $res = $summary + ['status' => 'interrupted', 'error' => $e->getMessage(), 'marketplace_id' => EbayCategorySuggestionReportService::MARKETPLACE_ID];
        }

        $this->set_status('Generate all eBay.fr category suggestions: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function reset_ebay_fr_category_suggestions_progress(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_reset_ebay_fr_category_suggestions_progress');
        $reporter = new EbayCategorySuggestionReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->reset_progress();
        $this->set_status('Reset eBay.fr category suggestions progress: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_blocked_category_fix_report(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_blocked_category_fix_report');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $categoryDashboardSummary = $this->category_dashboard_summary_for_report($marketplaceId);
        $path = $this->last_category_readiness_audit_path('problems_only_csv');
        if ($path === '') {
            $res = [
                'action' => 'generate_blocked_category_fix_report',
                'result' => 'error',
                'blocked_by_category_rows' => 0,
                'recommended_products' => 0,
                'recommended_categories' => 0,
                'high_confidence_products' => 0,
                'high_confidence_categories' => 0,
                'fix_import_rows' => 0,
                'source_problems_csv' => '',
                'recommendations_csv_path' => '',
                'recommendations_csv_url' => '',
                'recommendations_csv_exists' => false,
                'recommendations_csv_size' => 0,
                'fix_import_csv_path' => '',
                'fix_import_csv_url' => '',
                'fix_import_csv_exists' => false,
                'fix_import_csv_size' => 0,
                'upload_dir' => $this->blocked_category_report_upload_dir(),
                'upload_dir_writable' => is_dir($this->blocked_category_report_upload_dir()) && is_writable($this->blocked_category_report_upload_dir()),
                'error' => 'missing_problems_only_csv_run_category_audit_first',
                'message' => 'Run category readiness audit first',
                'category_dashboard_summary' => $categoryDashboardSummary,
            ];
            update_option('wei_fr_ebay_blocked_category_fix_report_summary', $res, false);
            $this->set_status('Blocked category fix report: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
            $this->go();
        }

        $reporter = new BlockedCategoryFixReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->generate_from_audit($path, $marketplaceId);
        $res['category_dashboard_summary'] = $categoryDashboardSummary;
        update_option('wei_fr_ebay_blocked_category_fix_report_summary', $res, false);
        $this->set_status('Blocked category fix report: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_category_mapping_worklist(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_category_mapping_worklist');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $path = $this->last_category_readiness_audit_path('problems_only_csv');
        if ($path === '') {
            $res = ['result' => 'error', 'error' => 'missing_problems_only_csv_run_category_audit_first', 'message' => 'Run category readiness audit first', 'worklist_csv_path' => '', 'worklist_csv_url' => '', 'worklist_csv_exists' => false, 'worklist_csv_size' => 0];
            update_option('wei_fr_ebay_category_mapping_worklist_summary', $res, false);
            $this->set_status('Category mapping worklist export: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
            $this->go();
        }
        $reporter = new BlockedCategoryFixReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->generate_category_mapping_worklist($path, $marketplaceId);
        update_option('wei_fr_ebay_category_mapping_worklist_summary', $res, false);
        $this->set_status('Category mapping worklist export: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_all_category_mapping_worklist(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_all_category_mapping_worklist');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $lastImport = get_option('wei_fr_ebay_category_mapping_worklist_import_summary', []);
        $seedCsvPath = is_array($lastImport) ? (string) ($lastImport['source_csv'] ?? '') : '';
        $reporter = new BlockedCategoryFixReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->generate_all_category_mapping_worklist($marketplaceId, $seedCsvPath);
        update_option('wei_fr_ebay_all_category_mapping_worklist_summary', $res, false);
        $this->set_status('All-category mapping worklist export: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function import_category_mapping_worklist(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_import_category_mapping_worklist');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $file = is_array($_FILES['category_mapping_worklist_csv'] ?? null) ? $_FILES['category_mapping_worklist_csv'] : [];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->set_status('Category mapping worklist import failed: upload a filled category-mapping-worklist.csv.');
            $this->go();
        }
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
        wp_mkdir_p($baseDir);
        $dest = trailingslashit($baseDir) . 'category-mapping-worklist-import-' . gmdate('Ymd-His') . '.csv';
        if (!move_uploaded_file($tmp, $dest)) {
            $this->set_status('Category mapping worklist import failed: could not store uploaded CSV.');
            $this->go();
        }
        $reporter = new BlockedCategoryFixReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->import_category_mapping_worklist($dest, $marketplaceId);
        update_option('wei_fr_ebay_category_mapping_worklist_import_summary', $res, false);
        $this->set_status('Category mapping worklist import: ' . wp_json_encode([
            'total_rows' => (int) ($res['total_rows'] ?? 0),
            'accepted' => (int) ($res['accepted'] ?? 0),
            'rejected' => (int) ($res['rejected'] ?? 0),
            'skipped_empty_final_ebay_category_id' => (int) ($res['skipped_empty_final_ebay_category_id'] ?? 0),
            'accepted_rows' => (array) ($res['accepted_rows'] ?? []),
            'rejected_rows' => (array) ($res['rejected_rows'] ?? []),
            'ebay_api_called' => false,
            'products_modified' => false,
            'listings_modified' => false,
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function download_wei_fr_report(): void
    {
        $this->require_manage_options();
        $file = sanitize_file_name((string) ($_GET['file'] ?? ''));
        $audit = get_option('wei_fr_ebay_last_category_readiness_audit', []);
        $audit = is_array($audit) ? $audit : [];
        $allowed = [
            BlockedCategoryFixReportService::RECOMMENDATIONS_FILENAME => trailingslashit($this->blocked_category_report_upload_dir()) . BlockedCategoryFixReportService::RECOMMENDATIONS_FILENAME,
            BlockedCategoryFixReportService::FIX_IMPORT_FILENAME => trailingslashit($this->blocked_category_report_upload_dir()) . BlockedCategoryFixReportService::FIX_IMPORT_FILENAME,
            BlockedCategoryFixReportService::CATEGORY_MAPPING_WORKLIST_FILENAME => trailingslashit($this->blocked_category_report_upload_dir()) . BlockedCategoryFixReportService::CATEGORY_MAPPING_WORKLIST_FILENAME,
            BlockedCategoryFixReportService::ALL_CATEGORY_MAPPING_WORKLIST_FILENAME => trailingslashit($this->blocked_category_report_upload_dir()) . BlockedCategoryFixReportService::ALL_CATEGORY_MAPPING_WORKLIST_FILENAME,
        ];
        $categoryComparison = $this->category_comparison_last_run();
        foreach (array_merge((array) ($categoryComparison['reports'] ?? []), (array) ($categoryComparison['raw_reports'] ?? [])) as $report) {
            $candidate = is_array($report) ? (string) ($report['path'] ?? '') : '';
            if ($candidate !== '') {
                $allowed[basename($candidate)] = $candidate;
            }
        }
        foreach (['full_report_csv_path', 'problems_only_csv_path'] as $pathKey) {
            $candidate = (string) ($audit[$pathKey] ?? '');
            if ($candidate !== '') {
                $allowed[basename($candidate)] = $candidate;
            }
        }
        $publishReadiness = get_option('wei_fr_ebay_publish_readiness_audit_summary', []);
        $publishReadiness = is_array($publishReadiness) ? $publishReadiness : [];
        $publishReports = is_array($publishReadiness['reports'] ?? null) ? $publishReadiness['reports'] : [];
        foreach (['full_csv', 'problems_only_csv', 'ready_products_csv', 'excluded_csv'] as $reportKey) {
            $report = is_array($publishReports[$reportKey] ?? null) ? $publishReports[$reportKey] : [];
            $candidate = (string) ($report['path'] ?? '');
            if ($candidate !== '') {
                $allowed[basename($candidate)] = $candidate;
            }
        }
        $vehicleAudit = get_option('wei_fr_ebay_vehicle_compatibility_audit_summary', []);
        $vehicleAudit = is_array($vehicleAudit) ? $vehicleAudit : [];
        $vehicleCsv = (string) ($vehicleAudit['csv_path'] ?? '');
        if ($vehicleCsv !== '') {
            $allowed[basename($vehicleCsv)] = $vehicleCsv;
        }
        $frenchMigration = get_option(self::GERMAN_CONTENT_MIGRATION_STATE_OPTION, []);
        $frenchMigration = is_array($frenchMigration) ? $frenchMigration : [];
        foreach ((array) ($frenchMigration['reports'] ?? []) as $report) {
            $candidate = is_array($report) ? (string) ($report['path'] ?? '') : '';
            if ($candidate !== '') {
                $allowed[basename($candidate)] = $candidate;
            }
        }
        if (!isset($allowed[$file])) {
            wp_die('Invalid report file');
        }
        $path = $allowed[$file];
        if (!is_file($path) || !is_readable($path)) {
            wp_die('Report file not found');
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . (string) filesize($path));
        readfile($path);
        exit;
    }


    public function generate_de_fr_category_comparison(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_de_fr_category_comparison');

        $uploaded = is_array($_FILES['de_mapping_csv'] ?? null) ? $_FILES['de_mapping_csv'] : [];
        $validationError = $this->validate_de_mapping_upload($uploaded);
        if ($validationError !== '') {
            $this->set_status('DE → FR category comparison rejected: ' . $validationError);
            $this->go_category_mapping_screen();
        }

        $inputDir = $this->category_comparison_upload_dir() . '/input';
        if (!is_dir($inputDir)) {
            wp_mkdir_p($inputDir);
        }
        $target = $inputDir . '/finalny-de-mapping.csv';
        $tmpName = (string) ($uploaded['tmp_name'] ?? '');
        $stored = is_uploaded_file($tmpName) ? move_uploaded_file($tmpName, $target) : copy($tmpName, $target);
        if (!$stored || !is_file($target)) {
            $this->set_status('DE → FR category comparison rejected: uploaded CSV could not be stored.');
            $this->go_category_mapping_screen();
        }

        $forceRefresh = !empty($_POST['force_refresh_taxonomy_cache']);
        try {
            $summary = $this->categoryComparisonTool->generate($target, $forceRefresh);
        } catch (\Throwable $e) {
            $summary = [
                'result' => 'error',
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
                'input_file_path' => $target,
                'output_dir' => $this->category_comparison_upload_dir(),
                'reports' => [],
                'summary_counts' => [],
                'taxonomy_api_errors' => [],
                'errors' => [$this->safe_admin_error_message($e)],
            ];
            $this->write_category_comparison_last_run($summary);
        }

        $this->set_status('DE → FR category comparison: ' . wp_json_encode($this->limit_nested_array($summary, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->go_category_mapping_screen();
    }

    private function validate_de_mapping_upload(array $uploaded): string
    {
        if ($uploaded === [] || (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'upload existing DE mapping CSV finalny(2).csv.';
        }
        if ((int) ($uploaded['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return 'upload failed with code ' . (int) ($uploaded['error'] ?? 0) . '.';
        }
        $originalName = function_exists('wp_basename') ? wp_basename((string) ($uploaded['name'] ?? '')) : basename((string) ($uploaded['name'] ?? ''));
        if ($originalName !== 'finalny(2).csv') {
            return 'invalid file name; upload finalny(2).csv.';
        }
        $name = sanitize_file_name($originalName);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            return 'invalid file extension; CSV required.';
        }
        $allowedMimeTypes = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream'];
        $type = strtolower((string) ($uploaded['type'] ?? ''));
        if ($type !== '' && !in_array($type, $allowedMimeTypes, true)) {
            return 'invalid file type; CSV upload required.';
        }
        $tmpName = (string) ($uploaded['tmp_name'] ?? '');
        if ($tmpName === '' || !is_readable($tmpName) || !is_file($tmpName)) {
            return 'uploaded CSV is not readable.';
        }
        return '';
    }

    private function category_comparison_last_run(): array
    {
        $path = $this->category_comparison_upload_dir() . '/category-comparison-last-run.json';
        if (!is_readable($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function write_category_comparison_last_run(array $summary): void
    {
        $dir = $this->category_comparison_upload_dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        file_put_contents($dir . '/category-comparison-last-run.json', wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function category_comparison_upload_dir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr/category-comparison';
    }

    private function safe_admin_error_message(\Throwable $e): string
    {
        return preg_replace('/(access_token|refresh_token|client_secret|authorization|api_key)[^\s,;]*/i', '$1=[redacted]', $e->getMessage()) ?: 'category comparison failed';
    }

    public function repair_blocked_category_mappings(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_repair_blocked_category_mappings');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $readiness = get_option('wei_fr_ebay_readiness_summary', []);
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
        check_admin_referer('wei_fr_apply_manual_woo_category_mappings');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
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
        check_admin_referer('wei_fr_repair_audit_category_groups');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
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
        check_admin_referer('wei_fr_export_category_teaching_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
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

    public function export_category_template_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_export_category_template_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $leafOnly = empty($_POST['export_all_categories']);
        $res = $this->autoCategoryMapper->export_woo_category_template_csv($marketplaceId, $leafOnly);
        $this->set_status('Category template CSV export: ' . wp_json_encode([
            'woo_categories_processed' => (int) ($res['woo_categories_processed'] ?? 0),
            'leaf_only' => !empty($res['leaf_only']),
            'reports' => (array) ($res['reports'] ?? []),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function export_ovoko_category_suggestions_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_export_ovoko_category_suggestions_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $limit = absint($_POST['limit'] ?? 500);
        $leafOnly = empty($_POST['export_all_categories']);
        $forceRefresh = !empty($_POST['force_refresh']);
        $res = $this->autoCategoryMapper->export_ovoko_category_suggestions_csv($marketplaceId, $limit, $leafOnly, $forceRefresh);
        $this->set_status('Ovoko/eBay category CSV export: ' . wp_json_encode([
            'woo_categories_processed' => (int) ($res['woo_categories_processed'] ?? 0),
            'mapped_categories' => (int) ($res['mapped_categories'] ?? 0),
            'unmapped_categories' => (int) ($res['unmapped_categories'] ?? 0),
            'ovoko_listings_fetched' => (int) ($res['ovoko_listings_fetched'] ?? 0),
            'seller_username_detected' => (string) ($res['seller_username_detected'] ?? ''),
            'category_id_returned_by_browse' => !empty($res['category_id_returned_by_browse']),
            'taxonomy_lookup_built' => !empty($res['taxonomy_lookup_built']),
            'confidence' => (array) ($res['confidence'] ?? []),
            'reports' => (array) ($res['reports'] ?? []),
            'error' => (string) ($res['browse_api_error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function import_category_teaching_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_import_category_teaching_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $file = is_array($_FILES['teaching_csv'] ?? null) ? $_FILES['teaching_csv'] : [];
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $this->set_status('Category teaching import failed: upload a filled teaching CSV.');
            $this->go();
        }
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
        wp_mkdir_p($baseDir);
        $dest = trailingslashit($baseDir) . 'wei-ebay-category-teaching-import-' . gmdate('Ymd-His') . '.csv';
        if (!move_uploaded_file($tmp, $dest)) {
            $this->set_status('Category teaching import failed: could not store uploaded CSV.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->import_production_category_mapping_csv($dest, $marketplaceId);
        $this->set_status('Category mapping CSV import: ' . wp_json_encode([
            'total_rows' => (int) ($res['total_rows'] ?? 0),
            'inserted' => (int) ($res['inserted'] ?? 0),
            'updated' => (int) ($res['updated'] ?? 0),
            'skipped' => (int) ($res['skipped'] ?? 0),
            'skipped_empty_ebay_id' => (int) ($res['skipped_empty_ebay_id'] ?? 0),
            'invalid' => (int) ($res['invalid'] ?? 0),
            'validation_cache_invalidated' => (int) ($res['validation_cache_invalidated'] ?? 0),
            'errors' => (array) ($res['errors'] ?? []),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function test_category_teaching_rule_match(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_test_category_teaching_rule_match');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $productId = absint($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
            $this->set_status('Teaching rule match diagnostic failed: enter a valid product_id.');
            $this->go();
        }
        $res = $this->autoCategoryMapper->test_teaching_rule_match_for_product($productId, $marketplaceId);
        update_option('wei_fr_ebay_category_mapping_teaching_match_diagnostic', $res, false);
        $this->set_status('Teaching rule match diagnostic: ' . wp_json_encode([
            'product_id' => $productId,
            'matching_teaching_rule_found' => !empty($res['matching_teaching_rule_found']),
            'matched_rule_id' => (int) ($res['matched_rule_id'] ?? 0),
            'matched_manual_ebay_category_id' => (string) ($res['matched_manual_ebay_category_id'] ?? ''),
            'error' => (string) ($res['error'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function generate_missing_french_content_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_missing_french_content_audit');
        $batchSize = max(1, min(200, absint($_POST['batch_size'] ?? 50)));
        $restart = !empty($_POST['restart']);
        $res = $this->scheduler->generate_missing_french_content_from_audit($batchSize, $restart);
        $this->set_status('Generate missing French content from audit: ' . wp_json_encode([
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
        check_admin_referer('wei_fr_export');
        $id = (int) ($_POST['product_id'] ?? 0);
        $category_id = sanitize_text_field((string) ($_POST['ebay_category_id'] ?? ''));
        $aspects_json = sanitize_textarea_field((string) ($_POST['ebay_aspects_json'] ?? ''));
        if ($id > 0 && $category_id !== '') {
            update_post_meta($id, '_wei_fr_ebay_category_id', $category_id);
            update_post_meta($id, '_wei_fr_ebay_category_source', 'manual_product_override');
            update_post_meta($id, '_wei_fr_ebay_category_name', $this->static_category_name($category_id));
            update_post_meta($id, '_wei_fr_ebay_category_path', $this->static_category_path($category_id));
        }
        if ($id > 0 && trim($aspects_json) !== '') {
            update_post_meta($id, '_wei_fr_ebay_aspects_json', $aspects_json);
        }
        $res = $this->adapter->export_product($id);
        $report = $this->record_fr_publish_action($this->new_fr_publish_run_id(), $id, 'export', $res, [], gmdate('Y-m-d H:i:s'));
        if (!empty($report['report_write_error'])) {
            $res['report_write_error'] = $report['report_write_error'];
        }
        $this->set_status('Export: ' . wp_json_encode($res));
        $this->go();
    }

    public function sync_stock(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $this->adapter->sync_stock($id);
        $this->set_status('Sync: ' . wp_json_encode($res));
        $this->go();
    }

    public function import_order(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_import_order');
        $res = $this->orderImporter->import_once();
        $this->set_status('Import order: ' . wp_json_encode($res));
        $this->go();
    }


    public function auto_sync_readiness_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_auto_sync_readiness_now');
        $this->handle_full_publish_readiness_audit('Auto sync readiness scan');
    }

    public function full_publish_readiness_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_full_publish_readiness_audit');
        $this->handle_full_publish_readiness_audit('Full publish readiness audit');
    }

    private function handle_full_publish_readiness_audit(string $label): void
    {
        $batchSize = max(1, min(500, absint($_POST['batch_size'] ?? 200)));
        $restart = empty($_POST['continue_audit']);
        $res = $this->scheduler->run_full_publish_readiness_audit($batchSize, $restart);
        $status = [
            'source' => 'latest readiness scan',
            'audit_run_id' => (string) ($res['audit_run_id'] ?? ''),
            'status' => (string) ($res['status'] ?? ''),
            'result' => (string) ($res['result'] ?? ''),
            'complete' => !empty($res['complete']),
            'current_offset' => (int) ($res['current_offset'] ?? 0),
            'processed_this_batch' => (int) ($res['processed_this_batch'] ?? 0),
            'processed_total' => (int) ($res['processed_total'] ?? $res['processed'] ?? 0),
            'total_products' => (int) ($res['total_products'] ?? 0),
            'remaining_products' => (int) ($res['remaining_products'] ?? 0),
            'batch_size' => (int) ($res['batch_size'] ?? $batchSize),
            'ready' => (int) ($res['ready'] ?? 0),
            'blocked' => (int) ($res['blocked'] ?? $res['not_ready'] ?? 0),
            'excluded_from_ebay' => (int) ($res['excluded_from_ebay'] ?? 0),
            'excluded_no_woo_category' => (int) ($res['excluded_no_woo_category'] ?? 0),
            'excluded_bez_kategorii' => (int) ($res['excluded_bez_kategorii'] ?? 0),
            'blocked_by_category' => (int) ($res['blocked_by_category'] ?? 0),
            'blocked_by_price' => (int) ($res['blocked_by_price'] ?? 0),
            'blocked_by_stock' => (int) ($res['blocked_by_stock'] ?? 0),
            'blocked_by_images' => (int) ($res['blocked_by_images'] ?? 0),
            'blocked_by_french_content' => (int) ($res['blocked_by_french_content'] ?? 0),
            'blocked_by_required_aspects' => (int) ($res['blocked_by_required_aspects'] ?? 0),
            'started_at' => (string) ($res['started_at'] ?? ''),
            'last_updated_at' => (string) ($res['last_updated_at'] ?? $res['updated_at'] ?? ''),
            'reports' => (array) ($res['reports'] ?? []),
        ];
        $this->set_status($label . ': ' . wp_json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function full_category_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_full_category_audit');
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
            'blocked_by_category' => (int) ($res['blocked_by_category_count'] ?? $res['blocked_by_category'] ?? 0),
            'blocked_by_category_count' => (int) ($res['blocked_by_category_count'] ?? $res['blocked_by_category'] ?? 0),
            'missing_category_count' => (int) ($res['missing_category_count'] ?? 0),
            'missing_required_aspects_count' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'missing_category' => (int) ($res['missing_category_count'] ?? 0),
            'invalid_ebay_category_id' => (int) ($res['invalid_ebay_category_id_count'] ?? 0),
            'non_leaf_category' => (int) ($res['non_leaf_category_count'] ?? 0),
            'category_sanity_failed' => (int) ($res['category_sanity_failed_count'] ?? 0),
            'needs_category_review' => (int) ($res['needs_category_review_count'] ?? $res['needs_category_review'] ?? 0),
            'missing_required_aspects' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'excluded_from_ebay' => (int) ($res['excluded_from_ebay_count'] ?? $res['excluded_from_ebay'] ?? 0),
            'excluded_no_woo_category' => (int) ($res['excluded_no_woo_category_count'] ?? $res['excluded_no_woo_category'] ?? 0),
            'excluded_bez_kategorii' => (int) ($res['excluded_bez_kategorii_count'] ?? $res['excluded_bez_kategorii'] ?? 0),
            'content_not_ready_count' => (int) ($res['content_not_ready_count'] ?? 0),
            'price_not_ready_count' => (int) ($res['price_not_ready_count'] ?? 0),
            'top_10_sanity_reasons' => (array) ($res['top_10_sanity_reasons'] ?? []),
            'top_10_detected_intents_with_problems' => (array) ($res['top_10_detected_intents_with_problems'] ?? []),
            'sample_problem_product_ids' => array_slice((array) ($res['sample_problem_product_ids'] ?? []), 0, 20),
            'reports' => (array) ($res['reports'] ?? []),
        ];
        $this->set_status('Full eBay category audit: ' . wp_json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->go();
    }
    public function run_category_readiness_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_run_category_readiness_audit');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $verboseDebug = !empty($_POST['verbose_debug']);
        $auditBatchSize = max(1, min(200, absint($_POST['audit_batch_size'] ?? 100)));
        $res = [];
        $restartAudit = empty($_POST['continue_audit']);
        $maxBatches = 100000;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $res = $this->scheduler->run_full_category_audit($verboseDebug, $auditBatchSize, $restartAudit && $batch === 0);
            if ((string) ($res['result'] ?? '') !== 'in_progress' && (string) ($res['status'] ?? '') !== 'in_progress') {
                break;
            }
        }

        $status = $this->category_readiness_audit_status($res, $marketplaceId);
        update_option('wei_fr_ebay_last_category_readiness_audit', (array) ($status['last_category_readiness_audit'] ?? []), false);
        update_option('wei_fr_ebay_category_readiness_audit_summary', $status, false);
        $this->set_status('Category readiness audit: ' . wp_json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    private function category_readiness_audit_status(array $res, string $marketplaceId): array
    {
        $reports = (array) ($res['reports'] ?? []);
        $problems = is_array($reports['problems_only_csv'] ?? null) ? $reports['problems_only_csv'] : [];
        $full = is_array($reports['full_audit_csv'] ?? null) ? $reports['full_audit_csv'] : [];
        $excluded = is_array($reports['excluded_products_csv'] ?? null) ? $reports['excluded_products_csv'] : [];
        $problemsPath = (string) ($problems['path'] ?? '');
        $fullPath = (string) ($full['path'] ?? '');
        $excludedPath = (string) ($excluded['path'] ?? '');
        $problemsExists = $problemsPath !== '' && is_file($problemsPath);
        $fullExists = $fullPath !== '' && is_file($fullPath);
        $excludedExists = $excludedPath !== '' && is_file($excludedPath);
        $processed = (int) ($res['processed'] ?? $res['total_scanned'] ?? 0);
        $ready = (int) ($res['ready_count'] ?? 0);
        $result = (string) ($res['result'] ?? 'error');

        if ($result === 'in_progress') {
            $result = 'partial';
        }

        $statusValue = $result === 'partial' ? 'partial' : (string) ($res['status'] ?? $result);
        // Legacy invariant: 'status' => $result === 'partial' ? 'partial' : ...
        $complete = !empty($res['complete']) || $statusValue === 'completed' || $result === 'success';
        $processedTotal = (int) ($res['processed_total'] ?? $processed);
        $totalProducts = (int) ($res['total_products'] ?? 0);

        return [
            'result' => $result,
            'audit_run_id' => (string) ($res['audit_run_id'] ?? ($full['audit_run_id'] ?? '')),
            'schema_version' => (string) ($res['schema_version'] ?? ($full['schema_version'] ?? 'category_readiness_audit_v2')),
            'generated_at' => (string) ($res['generated_at'] ?? gmdate('Y-m-d H:i:s')),
            'full_run' => true,
            'complete' => $complete,
            'status' => $statusValue,
            'started_at' => (string) ($res['started_at'] ?? ''),
            'finished_at' => $complete ? (string) ($res['finished_at'] ?? $res['completed_at'] ?? '') : '',
            'batch_size' => (int) ($res['batch_size'] ?? 0),
            'current_offset' => (int) ($res['current_offset'] ?? $processedTotal),
            'processed_total' => $processedTotal,
            'processed' => $processedTotal,
            'total_products' => $totalProducts,
            'remaining_products' => max(0, $totalProducts - $processedTotal),
            'ready' => $ready,
            'ready_count' => $ready,
            'blocked_by_category' => (int) ($res['blocked_by_category_count'] ?? $res['blocked_by_category'] ?? 0),
            'blocked_by_category_count' => (int) ($res['blocked_by_category_count'] ?? $res['blocked_by_category'] ?? 0),
            'missing_category_count' => (int) ($res['missing_category_count'] ?? 0),
            'invalid_ebay_category_id_count' => (int) ($res['invalid_ebay_category_id_count'] ?? 0),
            'non_leaf_category_count' => (int) ($res['non_leaf_category_count'] ?? 0),
            'category_sanity_failed_count' => (int) ($res['category_sanity_failed_count'] ?? 0),
            'missing_required_aspects_count' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'missing_category' => (int) ($res['missing_category_count'] ?? 0),
            'invalid_ebay_category_id' => (int) ($res['invalid_ebay_category_id_count'] ?? 0),
            'non_leaf_category' => (int) ($res['non_leaf_category_count'] ?? 0),
            'category_sanity_failed' => (int) ($res['category_sanity_failed_count'] ?? 0),
            'needs_category_review' => (int) ($res['needs_category_review_count'] ?? $res['needs_category_review'] ?? 0),
            'missing_required_aspects' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'excluded_from_ebay' => (int) ($res['excluded_from_ebay_count'] ?? $res['excluded_from_ebay'] ?? 0),
            'excluded_from_ebay_count' => (int) ($res['excluded_from_ebay_count'] ?? $res['excluded_from_ebay'] ?? 0),
            'excluded_no_woo_category' => (int) ($res['excluded_no_woo_category_count'] ?? $res['excluded_no_woo_category'] ?? 0),
            'excluded_no_woo_category_count' => (int) ($res['excluded_no_woo_category_count'] ?? $res['excluded_no_woo_category'] ?? 0),
            'excluded_bez_kategorii' => (int) ($res['excluded_bez_kategorii_count'] ?? $res['excluded_bez_kategorii'] ?? 0),
            'excluded_bez_kategorii_count' => (int) ($res['excluded_bez_kategorii_count'] ?? $res['excluded_bez_kategorii'] ?? 0),
            'content_not_ready_count' => (int) ($res['content_not_ready_count'] ?? 0),
            'price_not_ready_count' => (int) ($res['price_not_ready_count'] ?? 0),
            'full_report_csv_path' => $fullPath,
            'full_report_csv_url' => (string) ($full['url'] ?? ''),
            'full_report_csv_exists' => $fullExists,
            'full_report_csv_size' => (int) ($full['size'] ?? ($fullExists ? filesize($fullPath) : 0)),
            'full_report_csv_admin_url' => $this->admin_report_download_url($fullPath),
            'problems_only_csv_path' => $problemsPath,
            'problems_only_csv_url' => (string) ($problems['url'] ?? ''),
            'problems_only_csv_exists' => $problemsExists,
            'problems_only_csv_size' => (int) ($problems['size'] ?? ($problemsExists ? filesize($problemsPath) : 0)),
            'problems_only_csv_admin_url' => $this->admin_report_download_url($problemsPath),
            'excluded_products_csv_path' => $excludedPath,
            'excluded_products_csv_url' => (string) ($excluded['url'] ?? ''),
            'excluded_products_csv_exists' => $excludedExists,
            'excluded_products_csv_size' => (int) ($excluded['size'] ?? ($excludedExists ? filesize($excludedPath) : 0)),
            'excluded_products_csv_admin_url' => $this->admin_report_download_url($excludedPath),
            'last_category_readiness_audit' => [
                'audit_run_id' => (string) ($res['audit_run_id'] ?? ($full['audit_run_id'] ?? '')),
                'schema_version' => (string) ($res['schema_version'] ?? ($full['schema_version'] ?? 'category_readiness_audit_v2')),
                'generated_at' => (string) ($res['generated_at'] ?? gmdate('Y-m-d H:i:s')),
                'full_run' => true,
                'complete' => $complete,
                'started_at' => (string) ($res['started_at'] ?? ''),
                'finished_at' => $complete ? (string) ($res['finished_at'] ?? $res['completed_at'] ?? '') : '',
                'full_report_csv_path' => $fullPath,
                'full_report_csv_url' => (string) ($full['url'] ?? ''),
                'full_report_csv_exists' => $fullExists,
                'full_report_csv_size' => (int) ($full['size'] ?? ($fullExists ? filesize($fullPath) : 0)),
                'full_report_csv_admin_url' => $this->admin_report_download_url($fullPath),
                'problems_only_csv_path' => $problemsPath,
                'problems_only_csv_url' => (string) ($problems['url'] ?? ''),
                'problems_only_csv_exists' => $problemsExists,
                'problems_only_csv_size' => (int) ($problems['size'] ?? ($problemsExists ? filesize($problemsPath) : 0)),
                'problems_only_csv_admin_url' => $this->admin_report_download_url($problemsPath),
                'excluded_products_csv_path' => $excludedPath,
                'excluded_products_csv_url' => (string) ($excluded['url'] ?? ''),
                'excluded_products_csv_exists' => $excludedExists,
                'excluded_products_csv_size' => (int) ($excluded['size'] ?? ($excludedExists ? filesize($excludedPath) : 0)),
                'excluded_products_csv_admin_url' => $this->admin_report_download_url($excludedPath),
            ],
            'sample_problem_product_ids' => array_slice((array) ($res['sample_problem_product_ids'] ?? []), 0, 20),
            'resume_offset' => $result === 'partial' ? $processedTotal : 0,
            'partial_full_report_csv_url' => $result === 'partial' ? (string) ($full['url'] ?? '') : '',
            'partial_problems_only_csv_url' => $result === 'partial' ? (string) ($problems['url'] ?? '') : '',
        ];
    }


    public function auto_sync_orders_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_auto_sync_orders_now');
        $res = $this->orderImporter->import_once();
        $this->set_status('Auto sync order import: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_stock_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_auto_sync_stock_now');
        $res = $this->scheduler->process_stock_queue(max(1, min(300, absint($_POST['batch_size'] ?? 100))));
        $this->set_status('Auto sync stock queue: ' . wp_json_encode($res));
        $this->go();
    }

    public function auto_sync_export_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_auto_sync_export_now');
        $s = $this->settings();
        if (empty($s['auto_export_enabled'])) {
            $this->set_status('Auto sync export skipped: auto export disabled');
            $this->go();
        }
        $batchSize = $this->publish_action_batch_size_from_post('batch_size', 20);
        $res = $this->scheduler->run_export_batch($batchSize);
        $this->set_status('Auto sync export batch: ' . wp_json_encode($res));
        $this->go();
    }

    public function sync_prices_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync_prices_only');
        $this->set_status('Woo → eBay prices only: skeleton action registered; no eBay write performed until dedicated price-only implementation is enabled.');
        $this->go();
    }

    public function sync_content_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync_content_only');
        $this->set_status('Woo → eBay content only: skeleton action registered; no eBay write performed until dedicated content-only implementation is enabled.');
        $this->go();
    }

    public function sync_categories_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync_categories_only');
        $this->set_status('Woo → eBay categories/aspects only: skeleton action registered; use readiness scan/category audit for diagnostics. No eBay write performed.');
        $this->go();
    }

    public function sync_listing_meta_back(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync_listing_meta_back');
        $this->set_status('eBay → Woo listing status/listing IDs/public URLs: skeleton action registered; current export/publish flows already write known offer/listing URL meta. No price/content/stock overwrite performed.');
        $this->go();
    }

    public function sync_ebay_stock_to_woo(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_sync_ebay_stock_to_woo');
        $this->set_status('eBay → Woo stock sync: disabled skeleton. No Woo stock overwrite performed without a dedicated explicit implementation.');
        $this->go();
    }

    public function ebay_sync_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_sync_now');
        $res = $this->scheduler->run_checkpoint_queue_sync();
        $this->set_status('eBay sync run finished: ' . wp_json_encode($res));
        $this->go();
    }

    public function ebay_process_queue_now(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_process_queue_now');
        $batchSize = max(1, min(100, absint($_POST['batch_size'] ?? 50)));
        $res = $this->scheduler->process_change_queue($batchSize);
        $this->set_status('eBay queue processed: ' . wp_json_encode($res));
        $this->go();
    }

    public function ebay_rebuild_ready_queue(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_rebuild_ready_queue');
        $batchSize = max(1, min(100, absint($_POST['batch_size'] ?? 50)));
        $res = $this->scheduler->rebuild_queue_for_ready_products($batchSize);
        $this->set_status('eBay ready-products queue rebuilt: ' . wp_json_encode($res));
        $this->go();
    }


    public function ebay_rebuild_initial_publish_candidates(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_rebuild_initial_publish_candidates');
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
        check_admin_referer('wei_fr_ebay_initial_publish_batch');
        $batchSize = $this->publish_action_batch_size_from_post('batch_size', 5);
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


    public function publish_ready_products(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_publish_ready_products');
        $batchSize = $this->publish_action_batch_size_from_post('batch_size', 5);
        $autoRunner = !empty($_POST['wei_fr_auto_runner']);
        $autoRunnerBatchIndex = max(0, absint($_POST['auto_runner_batch_index'] ?? 0));
        $res = $this->run_initial_publish_batch($batchSize);
        $globalRemainingReady = $this->count_initial_publish_candidates_from_meta();
        $remainingReady = $globalRemainingReady;
        $queueEmpty = $globalRemainingReady <= 0;
        $stoppedReason = $this->publish_ready_products_auto_runner_stopped_reason($res);
        $payload = [
            'processed' => (int) ($res['processed'] ?? 0),
            'ready' => (int) $this->initial_publish_total_ready($this->initial_publish_candidate_summary()),
            'remaining_ready' => $remainingReady,
            'global_remaining_ready' => $globalRemainingReady,
            'queue_empty' => $queueEmpty,
            'exported' => (int) ($res['success'] ?? 0),
            'published' => (int) ($res['success'] ?? 0),
            'skipped_not_ready' => (int) ($res['skipped_not_ready'] ?? 0),
            'blocked_by_category' => (int) ($res['blocked_by_category'] ?? 0),
            'stale_french_content' => (int) ($res['stale_french_content'] ?? 0),
            'missing_required_aspects' => (int) ($res['missing_required_aspects'] ?? 0),
            'missing_image' => (int) ($res['missing_image'] ?? 0),
            'missing_stock' => (int) ($res['missing_stock'] ?? 0),
            'invalid_price' => (int) ($res['invalid_price'] ?? 0),
            'errors' => (int) ($res['failed'] ?? 0),
            'report_url' => (string) ($res['report_url'] ?? ''),
            'status' => (string) ($res['status'] ?? ''),
            'auto_runner_batch_index' => $autoRunnerBatchIndex,
            'batch_size' => $batchSize,
            'stopped_reason' => $stoppedReason,
            'fatal_error' => $stoppedReason !== '',
        ];

        if ($autoRunner) {
            $this->logger->info('PUBLISH_READY_PRODUCTS_AUTO_RUNNER_BATCH', [
                'auto_runner_batch_index' => $autoRunnerBatchIndex,
                'batch_size' => $batchSize,
                'processed' => (int) ($payload['processed'] ?? 0),
                'exported' => (int) ($payload['exported'] ?? 0),
                'published' => (int) ($payload['published'] ?? 0),
                'skipped_not_ready' => (int) ($payload['skipped_not_ready'] ?? 0),
                'errors' => (int) ($payload['errors'] ?? 0),
                'global_remaining_ready' => (int) ($payload['global_remaining_ready'] ?? 0),
                'queue_empty' => (bool) ($payload['queue_empty'] ?? false),
                'stopped_reason' => $stoppedReason,
            ]);
        }

        $this->set_status('Publish ready products: ' . wp_json_encode($payload, JSON_UNESCAPED_UNICODE));

        if ($autoRunner) {
            wp_send_json($payload);
        }

        $this->go();
    }

    private function publish_ready_products_auto_runner_stopped_reason(array $res): string
    {
        $status = strtolower((string) ($res['status'] ?? ''));
        if ($status === 'paused') {
            return 'publish_paused';
        }

        if ((int) ($res['failed'] ?? 0) <= 0) {
            return '';
        }

        $lastError = strtolower((string) ($res['last_error'] ?? ''));
        if ($lastError === '') {
            return '';
        }

        foreach (['auth', 'oauth', 'token', 'unauthoriz', 'forbidden', 'rate limit', 'ratelimit', '429', 'too many requests', 'api quota', 'system', 'timeout', 'connect', 'curl', 'http 5', '503', '502', '500'] as $fatalNeedle) {
            if (str_contains($lastError, $fatalNeedle)) {
                return 'fatal/system/API/auth/rate-limit error: ' . (string) ($res['last_error'] ?? '');
            }
        }

        return '';
    }

    public function ebay_initial_publish_toggle_pause(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_initial_publish_toggle_pause');
        $current = (string) get_option('wei_fr_ebay_initial_publish_status', 'idle');
        $next = $current === 'paused' ? 'idle' : 'paused';
        update_option('wei_fr_ebay_initial_publish_status', $next, false);
        $this->set_status($next === 'paused' ? 'Initial eBay publish paused' : 'Initial eBay publish resumed');
        $this->go();
    }

    public function refresh_ebay_listing_state(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_refresh_ebay_listing_state');
        $limit = max(1, min(500, absint($_POST['batch_size'] ?? 100)));
        $res = $this->adapter->refresh_listing_state($limit);
        update_option('wei_fr_ebay_listing_state_summary', $res, false);
        $this->set_status('eBay listing state refreshed: ' . wp_json_encode([
            'active_listings' => (int) ($res['current_active_listing_count'] ?? 0),
            'offers' => (int) ($res['current_offer_count'] ?? 0),
            'ended_listings' => (int) ($res['ended_listing_count'] ?? 0),
            'needs_reexport' => (int) ($res['needs_reexport_count'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function ebay_initial_publish_reset(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_initial_publish_reset');
        if ((string) ($_POST['confirm_reset'] ?? '') !== 'RESET') {
            $this->set_status('Initial eBay publish reset skipped: type RESET to confirm.');
            $this->go();
        }

        $this->reset_publish_progress_state();
        $this->set_status('Initial eBay publish progress reset.');
        $this->go();
    }


    private function reset_publish_progress_state(): void
    {
        foreach ($this->initial_publish_option_names() as $option) {
            delete_option($option);
        }
        delete_option('wei_fr_ebay_initial_publish_last_batch_log');
        delete_option('wei_fr_ebay_initial_publish_candidate_summary');
        delete_option('wei_fr_ebay_initial_publish_last_summary');
    }

    private function ebay_listing_state_summary(): array
    {
        $summary = get_option('wei_fr_ebay_listing_state_summary', []);
        return is_array($summary) ? $summary : [];
    }

    private function initial_publish_candidate_summary(): array
    {
        $summary = get_option('wei_fr_ebay_initial_publish_candidate_summary', []);
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
                    $summary['blocked_by_stock'] = (int) ($summary['blocked_by_stock'] ?? 0) + 1;
                    $this->save_initial_publish_readiness_reason($productId, 'blocked_by_stock', $stockReason);
                    continue;
                }

                $preflight = $this->adapter->preflight_product($productId, null, true, true, [
                    'audit_mode' => true,
                    'suppress_side_effects' => true,
                ]);
                $reason = $this->initial_publish_reason_from_preflight($preflight);
                if ($reason === 'ready') {
                    $summary['ready'] = (int) ($summary['ready'] ?? 0) + 1;
                    update_post_meta($productId, '_wei_fr_ebay_export_status', 'ready');
                    $this->save_initial_publish_readiness_reason($productId, 'ready', (string) ($preflight['message'] ?? 'Product ready for eBay export.'));
                    continue;
                }

                $summary[$reason] = (int) ($summary[$reason] ?? 0) + 1;
                if ($reason === 'excluded_from_ebay') {
                    $exclusionReason = (string) ($preflight['exclusion_reason'] ?? $preflight['category']['exclusion_reason'] ?? 'excluded_from_ebay');
                    if (isset($summary[$exclusionReason])) {
                        $summary[$exclusionReason] = (int) ($summary[$exclusionReason] ?? 0) + 1;
                    }
                    $this->save_initial_publish_readiness_reason($productId, $exclusionReason, (string) ($preflight['message'] ?? 'Product intentionally excluded from eBay.'));
                    continue;
                }
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
            'blocked_by_stock' => (int) ($summary['blocked_by_stock'] ?? 0),
            'missing_aspects' => (int) ($summary['missing_aspects'] ?? 0),
            'content_not_ready' => (int) ($summary['content_not_ready'] ?? 0),
            'price_not_ready' => (int) ($summary['price_not_ready'] ?? 0),
            'excluded_from_ebay' => (int) ($summary['excluded_from_ebay'] ?? 0),
            'excluded_no_woo_category' => (int) ($summary['excluded_no_woo_category'] ?? 0),
            'excluded_bez_kategorii' => (int) ($summary['excluded_bez_kategorii'] ?? 0),
            'other' => (int) ($summary['other'] ?? 0),
        ];
        $summary['skipped_not_eligible'] = array_sum($summary['skipped_reasons']);
        $summary['source'] = 'woocommerce_products_database_preflight_scan';
        $summary['candidate_rule'] = 'Current WooCommerce product preflight is ready and product has no listing_id/item_id/listing_status/export_status published.';
        $summary['csv_required'] = false;

        update_option('wei_fr_ebay_initial_publish_candidate_summary', $summary, false);
        update_option('wei_fr_ebay_initial_publish_total_ready', (int) $summary['ready'], false);
        return $summary;
    }

    private function initial_publish_scan_base_summary(array $previous, bool $reset, int $batchSize): array
    {
        $empty = [
            'processed' => 0,
            'ready' => 0,
            'already_published' => 0,
            'blocked_by_category' => 0,
            'blocked_by_stock' => 0,
            'missing_aspects' => 0,
            'content_not_ready' => 0,
            'price_not_ready' => 0,
            'excluded_from_ebay' => 0,
            'excluded_no_woo_category' => 0,
            'excluded_bez_kategorii' => 0,
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
                AND (
                    %d = 1
                    OR NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} excluded_meta
                        WHERE excluded_meta.post_id = p.ID
                            AND excluded_meta.meta_key = '_wei_fr_ebay_export_status'
                            AND excluded_meta.meta_value = 'excluded_from_ebay'
                    )
                )
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $includeExcluded ? 1 : 0, $batchSize))));
    }

    private function reset_initial_publish_progress_for_new_candidate_scan(): void
    {
        $this->reset_publish_progress_state();
    }

    private function save_initial_publish_readiness_reason(int $productId, string $reason, string $message): void
    {
        $reason = in_array($reason, ['ready', 'blocked_by_category', 'blocked_by_stock', 'missing_aspects', 'content_not_ready', 'price_not_ready', 'excluded_from_ebay', 'excluded_no_woo_category', 'excluded_bez_kategorii', 'already_published', 'other'], true) ? $reason : 'other';
        if ($reason !== 'ready') {
            $exportStatus = in_array($reason, ['excluded_no_woo_category', 'excluded_bez_kategorii'], true) ? 'excluded_from_ebay' : $reason;
            update_post_meta($productId, '_wei_fr_ebay_export_status', $exportStatus);
        }
        update_post_meta($productId, '_wei_fr_ebay_readiness_reason', $reason);
        update_post_meta($productId, '_wei_fr_ebay_readiness_message', mb_substr($message, 0, 1000));
        update_post_meta($productId, '_wei_fr_ebay_readiness_checked_at', gmdate('Y-m-d H:i:s'));
    }

    private function initial_publish_reason_from_preflight(array $preflight): string
    {
        if (!empty($preflight['ready'])) {
            return 'ready';
        }

        $status = (string) ($preflight['status'] ?? '');
        $message = strtolower((string) ($preflight['message'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($preflight['errors'] ?? []))));
        if ($status === 'excluded_from_ebay') {
            return 'excluded_from_ebay';
        }
        if ($status === 'blocked_by_stock' || (string) ($preflight['stock_block_reason'] ?? '') !== '' || str_contains($message, 'stock_quantity_zero') || str_contains($message, 'stock_status_outofstock') || str_contains($message, 'product_not_purchasable') || str_contains($message, 'ovoko_sold_or_unavailable') || str_contains($message, 'missing_stock_quantity')) {
            return 'blocked_by_stock';
        }
        if (in_array($status, ['needs_category_review', 'low_confidence_auto', 'category_sanity_failed', 'taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true)) {
            return 'blocked_by_category';
        }
        if (in_array($status, ['missing_required_aspects', 'missing_aspects'], true) || !empty($preflight['missing_aspects'])) {
            return 'missing_aspects';
        }
        if (in_array($status, ['not_ready_missing_french_content', 'content_not_ready'], true) || str_contains($message, 'content') || str_contains($message, 'title') || str_contains($message, 'description') || str_contains($message, 'french')) {
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
            return 'stock_status_outofstock';
        }
        $stockQuantity = $product->get_stock_quantity();
        if ($stockQuantity === null || $stockQuantity === '') {
            return 'missing_stock_quantity';
        }
        if ((int) $stockQuantity <= 0) {
            return 'stock_quantity_zero';
        }
        if (method_exists($product, 'is_purchasable') && !$product->is_purchasable()) {
            return 'product_not_purchasable';
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
        if ($status === 'excluded_from_ebay') {
            return 'excluded_from_ebay';
        }
        if ($status === 'blocked_by_category' || $status === 'missing_category') {
            return 'blocked_by_category';
        }
        if ($status === 'blocked_by_stock') {
            return 'blocked_by_stock';
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
                AND m.meta_key = '_wei_fr_ebay_export_status'
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
                AND ready_meta.meta_key = '_wei_fr_ebay_export_status'
                AND ready_meta.meta_value IN ('ready', 'needs_reexport')
            LEFT JOIN {$wpdb->postmeta} listing_meta
                ON listing_meta.post_id = p.ID
                AND listing_meta.meta_key = '_wei_fr_ebay_listing_id'
                AND listing_meta.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} item_meta
                ON item_meta.post_id = p.ID
                AND item_meta.meta_key = '_wei_fr_ebay_item_id'
                AND item_meta.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} listing_status_meta
                ON listing_status_meta.post_id = p.ID
                AND listing_status_meta.meta_key = '_wei_fr_ebay_listing_status'
                AND listing_status_meta.meta_value = 'published'
            LEFT JOIN {$wpdb->postmeta} current_active_meta
                ON current_active_meta.post_id = p.ID
                AND current_active_meta.meta_key = '_wei_fr_ebay_current_listing_state'
                AND current_active_meta.meta_value = 'active'
            LEFT JOIN {$wpdb->postmeta} export_published_meta
                ON export_published_meta.post_id = p.ID
                AND export_published_meta.meta_key = '_wei_fr_ebay_export_status'
                AND export_published_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND listing_status_meta.post_id IS NULL
                AND export_published_meta.post_id IS NULL
                AND NOT (
                    current_active_meta.post_id IS NOT NULL
                    AND (listing_meta.post_id IS NOT NULL OR item_meta.post_id IS NOT NULL)
                )
        ";
        return (int) $wpdb->get_var($sql);
    }

    private function initial_publish_candidate_count_after_cursor(int $cursor): int
    {
        global $wpdb;

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;
        $sql = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$posts} p
            INNER JOIN {$postmeta} ready_meta
                ON ready_meta.post_id = p.ID
                AND ready_meta.meta_key = '_wei_fr_ebay_export_status'
                AND ready_meta.meta_value IN ('ready', 'needs_reexport')
            LEFT JOIN {$postmeta} listing_meta
                ON listing_meta.post_id = p.ID
                AND listing_meta.meta_key = '_wei_fr_ebay_listing_id'
                AND listing_meta.meta_value <> ''
            LEFT JOIN {$postmeta} item_meta
                ON item_meta.post_id = p.ID
                AND item_meta.meta_key = '_wei_fr_ebay_item_id'
                AND item_meta.meta_value <> ''
            LEFT JOIN {$postmeta} listing_status_meta
                ON listing_status_meta.post_id = p.ID
                AND listing_status_meta.meta_key = '_wei_fr_ebay_listing_status'
                AND listing_status_meta.meta_value = 'published'
            LEFT JOIN {$postmeta} current_active_meta
                ON current_active_meta.post_id = p.ID
                AND current_active_meta.meta_key = '_wei_fr_ebay_current_listing_state'
                AND current_active_meta.meta_value = 'active'
            LEFT JOIN {$postmeta} export_published_meta
                ON export_published_meta.post_id = p.ID
                AND export_published_meta.meta_key = '_wei_fr_ebay_export_status'
                AND export_published_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
                AND listing_status_meta.post_id IS NULL
                AND export_published_meta.post_id IS NULL
                AND NOT (
                    current_active_meta.post_id IS NOT NULL
                    AND (listing_meta.post_id IS NOT NULL OR item_meta.post_id IS NOT NULL)
                )
        ";

        return (int) $wpdb->get_var($wpdb->prepare($sql, max(0, $cursor)));
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
                    (m.meta_key = '_wei_fr_ebay_listing_id' AND m.meta_value <> '')
                    OR (m.meta_key = '_wei_fr_ebay_item_id' AND m.meta_value <> '')
                    OR (m.meta_key = '_wei_fr_ebay_listing_status' AND m.meta_value = 'published')
                    OR (m.meta_key = '_wei_fr_ebay_export_status' AND m.meta_value = 'published')
                )
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
        ";
        return (int) $wpdb->get_var($sql);
    }

    private function run_initial_publish_batch(int $batchSize): array
    {
        $status = (string) get_option('wei_fr_ebay_initial_publish_status', 'idle');
        if ($status === 'paused') {
            return ['result' => 'skipped', 'status' => 'paused', 'processed' => 0, 'success' => 0, 'failed' => 0, 'published_total' => (int) get_option('wei_fr_ebay_initial_publish_success', 0), 'remaining' => $this->initial_publish_remaining()];
        }

        $candidateSummary = $this->initial_publish_candidate_summary();
        $totalReady = $this->initial_publish_total_ready($candidateSummary);
        update_option('wei_fr_ebay_initial_publish_total_ready', $totalReady, false);

        $cursor = (int) get_option('wei_fr_ebay_initial_publish_cursor', 0);
        $processedTotal = (int) get_option('wei_fr_ebay_initial_publish_processed', 0);
        $successTotal = (int) get_option('wei_fr_ebay_initial_publish_success', 0);
        $failedTotal = (int) get_option('wei_fr_ebay_initial_publish_failed', 0);
        $skippedTotal = (int) get_option('wei_fr_ebay_initial_publish_skipped', 0);
        $startedAt = gmdate('Y-m-d H:i:s');
        $runId = $this->new_fr_publish_run_id();
        $actionRows = [];
        $logs = ['INITIAL_PUBLISH_BATCH_START run_id=' . $runId . ' batch_size=' . $batchSize . ' cursor=' . $cursor];
        $processed = 0;
        $success = 0;
        $failed = 0;
        $lastError = '';
        $lastPublishedProductId = 0;
        $lastListingId = '';
        $newCursor = $cursor;
        $skipSummary = [
            'skipped_not_ready' => 0,
            'blocked_by_category' => 0,
            'blocked_by_stock' => 0,
            'stale_french_content' => 0,
            'missing_required_aspects' => 0,
            'missing_image' => 0,
            'missing_stock' => 0,
            'invalid_price' => 0,
            'excluded_from_ebay' => 0,
        ];

        $ids = $this->initial_publish_candidate_product_ids($batchSize, $cursor);
        $globalReadyBefore = $this->count_initial_publish_candidates_from_meta();
        if ($ids === [] && $cursor > 0 && $globalReadyBefore > 0) {
            $logs[] = 'INITIAL_PUBLISH_CURSOR_WRAP cursor=' . $cursor . ' global_remaining_ready=' . $globalReadyBefore;
            $cursor = 0;
            $newCursor = 0;
            $ids = $this->initial_publish_candidate_product_ids($batchSize, $cursor);
        }

        foreach ($ids as $productId) {
            $productId = (int) $productId;
            $newCursor = max($newCursor, $productId);
            $processed++;
            $processedTotal++;
            $logs[] = 'INITIAL_PUBLISH_PRODUCT_START product_id=' . $productId;
            $preflight = [];

            if ($this->is_initial_publish_already_published($productId)) {
                $diagnostics = $this->initial_publish_already_published_diagnostics($productId);
                $skippedTotal++;
                $actionRows[] = $this->build_fr_publish_action_row($runId, 'skip', $productId, ['result' => 'skipped', 'status' => 'already_published_active_listing', 'offer_id' => (string) ($diagnostics['offer_id'] ?? ''), 'listing_id' => (string) ($diagnostics['listing_id'] ?? ''), 'listing_url' => $this->fr_listing_url((string) ($diagnostics['listing_id'] ?? ''))], [], $startedAt);
                $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId
                    . ' sku="' . $this->compact_log_value((string) ($diagnostics['sku'] ?? '')) . '"'
                    . ' offer_id="' . $this->compact_log_value((string) ($diagnostics['offer_id'] ?? '')) . '"'
                    . ' listing_id="' . $this->compact_log_value((string) ($diagnostics['listing_id'] ?? '')) . '"'
                    . ' active_listing_state="' . $this->compact_log_value((string) ($diagnostics['active_listing_state'] ?? '')) . '"'
                    . ' reason="already_published_active_listing"';
                continue;
            }

            try {
                $preflight = $this->adapter->preflight_product($productId);
                if (empty($preflight['ready'])) {
                    $reason = (string) ($preflight['status'] ?? 'not_ready');
                    $skippedTotal++;
                    $this->accumulate_publish_not_ready_reason($skipSummary, $preflight);
                    $actionRows[] = $this->build_fr_publish_action_row($runId, 'skip', $productId, ['result' => 'skipped', 'status' => $reason, 'message' => (string) ($preflight['message'] ?? $reason)], $preflight, $startedAt);
                    update_post_meta($productId, '_wei_fr_ebay_last_sync_status', 'not_ready');
                    update_post_meta($productId, '_wei_fr_ebay_last_sync_error', $reason);
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId . ' reason="' . $this->compact_log_value($reason) . '"';
                    continue;
                }

                $res = $this->adapter->export_product($productId, null, true);
                if (($res['result'] ?? '') === 'skipped' && ($res['status'] ?? '') === 'already_published_active_listing') {
                    $skippedTotal++;
                    $diagnostics = is_array($res['diagnostics'] ?? null) ? $res['diagnostics'] : [];
                    $actionRows[] = $this->build_fr_publish_action_row($runId, 'skip', $productId, $res, $preflight, $startedAt);
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId
                        . ' sku="' . $this->compact_log_value((string) ($diagnostics['sku'] ?? '')) . '"'
                        . ' offer_id="' . $this->compact_log_value((string) ($diagnostics['offer_id'] ?? '')) . '"'
                        . ' listing_id="' . $this->compact_log_value((string) ($diagnostics['listing_id'] ?? '')) . '"'
                        . ' active_listing_state="' . $this->compact_log_value((string) ($diagnostics['active_listing_state'] ?? '')) . '"'
                        . ' reason="already_published_active_listing"';
                    continue;
                }
                $publishedDetails = $this->initial_publish_published_details($productId, $res);
                if (!empty($publishedDetails['published'])) {
                    $success++;
                    $successTotal++;
                    $lastPublishedProductId = $productId;
                    $lastListingId = (string) ($publishedDetails['listing_id'] ?? '');
                    $actionRows[] = $this->build_fr_publish_action_row($runId, 'publish', $productId, $res, $preflight, $startedAt);
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_PUBLISHED product_id=' . $productId . ' listing_id=' . $this->compact_log_value($lastListingId);
                } else {
                    $failed++;
                    $failedTotal++;
                    $lastError = (string) ($res['message'] ?? $res['error'] ?? 'publish_failed_without_published_listing_meta');
                    $actionRows[] = $this->build_fr_publish_action_row($runId, 'error', $productId, array_merge($res, ['result' => 'error', 'message' => $lastError]), $preflight, $startedAt);
                    update_post_meta($productId, '_wei_fr_ebay_last_sync_status', 'error');
                    update_post_meta($productId, '_wei_fr_ebay_last_sync_error', $lastError);
                    $logs[] = 'INITIAL_PUBLISH_PRODUCT_FAILED product_id=' . $productId . ' error="' . $this->compact_log_value($lastError) . '"';
                }
            } catch (\Throwable $throwable) {
                $failed++;
                $failedTotal++;
                $lastError = $throwable->getMessage();
                $actionRows[] = $this->build_fr_publish_action_row($runId, 'error', $productId, ['result' => 'error', 'error' => 'exception', 'message' => $lastError], $preflight, $startedAt);
                update_post_meta($productId, '_wei_fr_ebay_last_sync_status', 'error');
                update_post_meta($productId, '_wei_fr_ebay_last_sync_error', $lastError);
                $logs[] = 'INITIAL_PUBLISH_PRODUCT_FAILED product_id=' . $productId . ' error="' . $this->compact_log_value($lastError) . '"';
            }
        }

        $finishedAt = gmdate('Y-m-d H:i:s');
        $reportWrite = $this->write_fr_publish_reports([
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'exported' => $success,
            'published' => $success,
            'skipped' => max(0, $processed - $success - $failed),
            'skipped_this_run' => max(0, $processed - $success - $failed),
            'errors' => $failed,
            'result' => $failed > 0 ? 'completed_with_errors' : 'success',
            'actions' => $actionRows,
        ], $actionRows);
        if (!empty($reportWrite['report_write_error'])) {
            $lastError = trim($lastError . ' report_write_error=' . (string) ($reportWrite['report_write_error']['reason'] ?? 'unknown'));
        }

        $remaining = $this->initial_publish_candidate_count_after_cursor($newCursor);
        $globalRemainingReady = $this->count_initial_publish_candidates_from_meta();
        $nextStatus = empty($ids) || $globalRemainingReady === 0 ? 'completed' : 'idle';
        update_option('wei_fr_ebay_initial_publish_processed', $processedTotal, false);
        update_option('wei_fr_ebay_initial_publish_success', $successTotal, false);
        update_option('wei_fr_ebay_initial_publish_failed', $failedTotal, false);
        update_option('wei_fr_ebay_initial_publish_skipped', $skippedTotal, false);
        update_option('wei_fr_ebay_initial_publish_cursor', $newCursor, false);
        update_option('wei_fr_ebay_initial_publish_last_run_at', $startedAt, false);
        update_option('wei_fr_ebay_initial_publish_last_error', $lastError, false);
        update_option('wei_fr_ebay_initial_publish_status', $nextStatus, false);
        update_option('wei_fr_ebay_initial_publish_last_batch_success', $success, false);
        update_option('wei_fr_ebay_initial_publish_last_batch_failed', $failed, false);
        update_option('wei_fr_ebay_initial_publish_last_batch_processed', $processed, false);
        if ($lastPublishedProductId > 0) {
            update_option('wei_fr_ebay_initial_publish_last_published_product_id', $lastPublishedProductId, false);
            update_option('wei_fr_ebay_initial_publish_last_listing_id', $lastListingId, false);
        }
        if (!empty($_POST['wei_fr_auto_runner'])) {
            $stoppedReasonForLog = $this->publish_ready_products_auto_runner_stopped_reason(['status' => $nextStatus, 'failed' => $failed, 'last_error' => $lastError]);
            $logs[] = 'PUBLISH_READY_PRODUCTS_AUTO_RUNNER_BATCH auto_runner_batch_index=' . max(0, absint($_POST['auto_runner_batch_index'] ?? 0)) . ' batch_size=' . $batchSize . ' processed=' . $processed . ' exported=' . $success . ' published=' . $success . ' skipped_not_ready=' . (int) ($skipSummary['skipped_not_ready'] ?? 0) . ' errors=' . $failed . ' global_remaining_ready=' . $globalRemainingReady . ' queue_empty=' . ($globalRemainingReady === 0 ? 'true' : 'false') . ($stoppedReasonForLog !== '' ? ' stopped_reason="' . $this->compact_log_value($stoppedReasonForLog) . '"' : '');
        }
        $logs[] = 'INITIAL_PUBLISH_BATCH_DONE processed=' . $processed . ' success=' . $success . ' failed=' . $failed . ' published_total=' . $successTotal . ' remaining=' . $remaining . ' global_remaining_ready=' . $globalRemainingReady;
        update_option('wei_fr_ebay_initial_publish_last_batch_log', array_slice($logs, -100), false);

        return [
            'result' => $failed > 0 ? 'completed_with_errors' : 'success',
            'status' => $nextStatus,
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'published_total' => $successTotal,
            'remaining' => $remaining,
            'global_remaining_ready' => $globalRemainingReady,
            'queue_empty' => $globalRemainingReady === 0,
            'cursor' => $newCursor,
            'last_error' => $lastError,
            'run_id' => $runId,
            'reports' => $reportWrite['reports'] ?? [],
            'report_write_error' => $reportWrite['report_write_error'] ?? [],
        ] + $skipSummary;
    }

    private function new_fr_publish_run_id(): string
    {
        return 'frpub_' . gmdate('Ymd_His') . '_' . substr(wp_generate_uuid4(), 0, 8);
    }

    private function fr_publish_report_paths(): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-integration-fr';
        $files = [
            'last_run' => 'fr-publish-last-run.json',
            'actions' => 'fr-publish-actions.csv',
            'errors' => 'fr-publish-errors.csv',
        ];
        $reports = [];
        foreach ($files as $key => $file) {
            $reports[$key] = [
                'path' => trailingslashit($baseDir) . $file,
                'url' => trailingslashit($baseUrl) . $file,
            ];
        }
        return $reports;
    }

    private function fr_publish_report_status(): array
    {
        $reports = $this->fr_publish_report_paths();
        $lastRun = [];
        $lastRunPath = (string) ($reports['last_run']['path'] ?? '');
        if ($lastRunPath !== '' && is_readable($lastRunPath)) {
            $decoded = json_decode((string) file_get_contents($lastRunPath), true);
            $lastRun = is_array($decoded) ? $decoded : [];
        }
        if ($lastRun === []) {
            $lastRun = get_option('wei_fr_ebay_fr_publish_last_run', []);
            $lastRun = is_array($lastRun) ? $lastRun : [];
        }

        return [
            'reports' => $reports,
            'last_run' => $lastRun,
            'report_write_error' => is_array($lastRun['report_write_error'] ?? null) ? $lastRun['report_write_error'] : [],
        ];
    }

    private function write_fr_publish_reports(array $summary, array $rows): array
    {
        $reports = $this->fr_publish_report_paths();
        $errors = array_values(array_filter($rows, static function (array $row): bool {
            return (string) ($row['result'] ?? '') === 'error';
        }));
        $summary['reports'] = $reports;
        $summary['last_published_listings'] = array_values(array_filter($rows, static function (array $row): bool {
            return (string) ($row['listing_id'] ?? '') !== '' || (string) ($row['offer_id'] ?? '') !== '';
        }));
        $summary['actions'] = array_slice($rows, -50);
        $result = ['reports' => $reports, 'report_write_error' => []];
        $baseDir = dirname((string) ($reports['last_run']['path'] ?? ''));

        try {
            if (!wp_mkdir_p($baseDir) || !is_dir($baseDir) || !is_writable($baseDir)) {
                throw new \RuntimeException('FR publish report directory is not writable.');
            }

            $headers = $this->fr_publish_report_headers();
            $this->append_fr_publish_csv((string) $reports['actions']['path'], $headers, $rows);
            $this->append_fr_publish_csv((string) $reports['errors']['path'], $headers, $errors);
            $json = wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false || file_put_contents((string) $reports['last_run']['path'], $json) === false) {
                throw new \RuntimeException('Unable to write fr-publish-last-run.json.');
            }
            update_option('wei_fr_ebay_fr_publish_last_run', $summary, false);
        } catch (\Throwable $throwable) {
            $result['report_write_error'] = [
                'report_write_error' => 'yes',
                'target_path' => $baseDir,
                'reason' => $throwable->getMessage(),
            ];
            $summary['report_write_error'] = $result['report_write_error'];
            update_option('wei_fr_ebay_fr_publish_last_run', $summary, false);
            $this->logger->error('FR_PUBLISH_REPORT_WRITE_FAILED', $result['report_write_error']);
        }

        return $result;
    }

    private function record_fr_publish_action(string $runId, int $productId, string $action, array $res, array $preflight = [], string $startedAt = ''): array
    {
        $startedAt = $startedAt !== '' ? $startedAt : gmdate('Y-m-d H:i:s');
        $row = $this->build_fr_publish_action_row($runId, $action, $productId, $res, $preflight, $startedAt);
        return $this->write_fr_publish_reports([
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => gmdate('Y-m-d H:i:s'),
            'exported' => $action === 'export' && (string) ($row['result'] ?? '') === 'success' ? 1 : 0,
            'published' => $action === 'publish' && (string) ($row['result'] ?? '') === 'success' ? 1 : 0,
            'skipped' => (string) ($row['result'] ?? '') === 'skipped' ? 1 : 0,
            'errors' => (string) ($row['result'] ?? '') === 'error' ? 1 : 0,
            'result' => (string) ($row['result'] ?? ''),
            'actions' => [$row],
        ], [$row]);
    }

    private function append_fr_publish_csv(string $path, array $headers, array $rows): void
    {
        if ($rows === []) {
            if (!file_exists($path)) {
                $handle = fopen($path, 'wb');
                if ($handle === false) {
                    throw new \RuntimeException('Unable to create CSV report: ' . $path);
                }
                fputcsv($handle, $headers);
                fclose($handle);
            }
            return;
        }

        $exists = file_exists($path) && filesize($path) > 0;
        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV report: ' . $path);
        }
        if (!$exists) {
            fputcsv($handle, $headers);
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(string $key): string => (string) ($row[$key] ?? ''), $headers));
        }
        fclose($handle);
    }

    private function fr_publish_report_headers(): array
    {
        return ['timestamp','run_id','action','product_id','title','sku','ebay_sku','marketplace','category_id','price','currency','quantity','inventory_item_id','offer_id','listing_id','listing_url','result','error_message','readiness_status','selected_fulfillment_policy_id','selected_payment_policy_id','selected_return_policy_id','merchant_location_key','description_source_used','sent_description_is_html_template','contains_template_markers'];
    }

    private function build_fr_publish_action_row(string $runId, string $action, int $productId, array $res, array $preflight = [], string $timestamp = ''): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $listingId = trim((string) ($res['listing_id'] ?? get_post_meta($productId, '_wei_fr_ebay_listing_id', true) ?: get_post_meta($productId, '_wei_fr_ebay_item_id', true)));
        $listingUrl = trim((string) ($res['listing_url'] ?? $res['public_url'] ?? get_post_meta($productId, '_wei_fr_ebay_listing_url', true) ?: get_post_meta($productId, '_wei_fr_ebay_public_url', true)));
        if ($listingUrl === '' && $listingId !== '') {
            $listingUrl = $this->fr_listing_url($listingId);
        }
        $skuResolution = is_array($preflight['sku_resolution'] ?? null) ? $preflight['sku_resolution'] : (is_array($res['sku_resolution'] ?? null) ? $res['sku_resolution'] : []);
        $priceResolution = is_array($preflight['price_resolution'] ?? null) ? $preflight['price_resolution'] : (is_array($res['price_resolution'] ?? null) ? $res['price_resolution'] : []);
        $result = (string) ($res['result'] ?? '');
        if ($result === 'skipped') {
            $resultLabel = 'skipped';
        } elseif ($result === 'success') {
            $resultLabel = 'success';
        } else {
            $resultLabel = 'error';
        }

        return [
            'timestamp' => $timestamp !== '' ? $timestamp : gmdate('Y-m-d H:i:s'),
            'run_id' => $runId,
            'action' => $action,
            'product_id' => (string) $productId,
            'title' => $product && method_exists($product, 'get_name') ? (string) $product->get_name() : (string) get_the_title($productId),
            'sku' => $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : (string) get_post_meta($productId, '_sku', true),
            'ebay_sku' => (string) ($res['inventory_item_id'] ?? $res['inventory_id'] ?? $skuResolution['wei_fr_ebay_sku'] ?? get_post_meta($productId, '_wei_fr_ebay_sku', true) ?: get_post_meta($productId, '_wei_fr_ebay_inventory_item_id', true)),
            'marketplace' => 'EBAY_FR',
            'category_id' => (string) ($res['category_id'] ?? $preflight['category']['category_id'] ?? get_post_meta($productId, '_wei_fr_ebay_category_id', true)),
            'price' => (string) ($res['price'] ?? $priceResolution['ebay_price_eur'] ?? ($product && method_exists($product, 'get_price') ? $product->get_price() : '')),
            'currency' => (string) ($res['currency'] ?? 'EUR'),
            'quantity' => (string) ($res['quantity'] ?? $preflight['stock_quantity'] ?? ($product && method_exists($product, 'get_stock_quantity') ? (int) $product->get_stock_quantity() : '')),
            'inventory_item_id' => (string) ($res['inventory_item_id'] ?? $res['inventory_id'] ?? get_post_meta($productId, '_wei_fr_ebay_inventory_item_id', true) ?: get_post_meta($productId, '_wei_fr_ebay_inventory_id', true)),
            'offer_id' => (string) ($res['offer_id'] ?? get_post_meta($productId, '_wei_fr_ebay_offer_id', true)),
            'listing_id' => $listingId,
            'listing_url' => $listingUrl,
            'result' => $resultLabel,
            'error_message' => $resultLabel === 'error' || $resultLabel === 'skipped' ? (string) ($res['message'] ?? $res['error'] ?? $res['status'] ?? '') : '',
            'readiness_status' => (string) ($preflight['status'] ?? get_post_meta($productId, '_wei_fr_ebay_export_status', true)),
            'selected_fulfillment_policy_id' => (string) ($res['selected_fulfillment_policy_id'] ?? $preflight['selected_fulfillment_policy_id'] ?? get_post_meta($productId, '_wei_fr_ebay_last_fulfillment_policy_id', true)),
            'selected_payment_policy_id' => (string) ($res['selected_payment_policy_id'] ?? $preflight['selected_payment_policy_id'] ?? $this->settings()['ebay_payment_policy_id'] ?? ''),
            'selected_return_policy_id' => (string) ($res['selected_return_policy_id'] ?? $preflight['selected_return_policy_id'] ?? $this->settings()['ebay_return_policy_id'] ?? ''),
            'merchant_location_key' => (string) ($res['merchant_location_key'] ?? $preflight['merchant_location_key'] ?? $this->settings()['inventory_location_key'] ?? ''),
            'description_source_used' => (string) ($res['description_source_used'] ?? get_post_meta($productId, '_wei_fr_ebay_description_source_used', true)),
            'sent_description_is_html_template' => (string) ($res['sent_description_is_html_template'] ?? ''),
            'contains_template_markers' => (string) ($res['contains_template_markers'] ?? ''),
        ];
    }

    private function fr_listing_url(string $listingId): string
    {
        $listingId = trim($listingId);
        return $listingId !== '' ? 'https://www.ebay.fr/itm/' . rawurlencode($listingId) : '';
    }

    private function fr_publish_product_listing_diagnostics(int $productId): array
    {
        return [
            'result' => 'success',
            'product_id' => $productId,
            '_wei_fr_ebay_listing_id' => (string) get_post_meta($productId, '_wei_fr_ebay_listing_id', true),
            '_wei_fr_ebay_offer_id' => (string) get_post_meta($productId, '_wei_fr_ebay_offer_id', true),
            '_wei_fr_ebay_inventory_item_id' => (string) get_post_meta($productId, '_wei_fr_ebay_inventory_item_id', true),
            '_wei_fr_ebay_listing_url' => (string) get_post_meta($productId, '_wei_fr_ebay_listing_url', true),
            '_wei_fr_ebay_published_at' => (string) get_post_meta($productId, '_wei_fr_ebay_published_at', true),
            '_wei_fr_ebay_marketplace' => (string) get_post_meta($productId, '_wei_fr_ebay_marketplace', true) ?: 'EBAY_FR',
        ];
    }

    private function accumulate_publish_not_ready_reason(array &$summary, array $preflight): void
    {
        $summary['skipped_not_ready'] = (int) ($summary['skipped_not_ready'] ?? 0) + 1;
        $status = strtolower((string) ($preflight['status'] ?? ''));
        $message = strtolower((string) ($preflight['message'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($preflight['errors'] ?? []))));
        if ($status === 'excluded_from_ebay') {
            $summary['excluded_from_ebay'] = (int) ($summary['excluded_from_ebay'] ?? 0) + 1;
            return;
        }
        if ($status === 'blocked_by_stock' || str_contains($message, 'stock_quantity_zero') || str_contains($message, 'stock_status_outofstock') || str_contains($message, 'product_not_purchasable') || str_contains($message, 'ovoko_sold_or_unavailable') || str_contains($message, 'missing_stock_quantity')) {
            $summary['blocked_by_stock'] = (int) ($summary['blocked_by_stock'] ?? 0) + 1;
            $summary['missing_stock'] = (int) ($summary['missing_stock'] ?? 0) + 1;
            return;
        }
        if ($status === 'blocked_by_category' || $status === 'missing_category' || str_contains($message, 'category')) {
            $summary['blocked_by_category'] = (int) ($summary['blocked_by_category'] ?? 0) + 1;
        }
        if ($status === 'stale_french_content' || str_contains($message, 'stale') || str_contains($message, 'french')) {
            $summary['stale_french_content'] = (int) ($summary['stale_french_content'] ?? 0) + 1;
        }
        if (str_contains($message, 'aspect') || str_contains($message, 'specific')) {
            $summary['missing_required_aspects'] = (int) ($summary['missing_required_aspects'] ?? 0) + 1;
        }
        if (str_contains($message, 'image') || str_contains($message, 'photo')) {
            $summary['missing_image'] = (int) ($summary['missing_image'] ?? 0) + 1;
        }
        if (str_contains($message, 'stock') || str_contains($message, 'quantity')) {
            $summary['missing_stock'] = (int) ($summary['missing_stock'] ?? 0) + 1;
        }
        if ($status === 'invalid_price' || str_contains($message, 'price')) {
            $summary['invalid_price'] = (int) ($summary['invalid_price'] ?? 0) + 1;
        }
    }

    private function initial_publish_published_details(int $productId, array $result): array
    {
        if (($result['result'] ?? '') !== 'success') {
            return ['published' => false, 'listing_id' => ''];
        }

        $metaListingId = trim((string) get_post_meta($productId, '_wei_fr_ebay_listing_id', true));
        $metaItemId = trim((string) get_post_meta($productId, '_wei_fr_ebay_item_id', true));
        $listingStatus = (string) get_post_meta($productId, '_wei_fr_ebay_listing_status', true);
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
                AND ready_meta.meta_key = '_wei_fr_ebay_export_status'
                AND ready_meta.meta_value IN ('ready', 'needs_reexport')
            LEFT JOIN {$postmeta} listing_meta
                ON listing_meta.post_id = p.ID
                AND listing_meta.meta_key = '_wei_fr_ebay_listing_id'
                AND listing_meta.meta_value <> ''
            LEFT JOIN {$postmeta} item_meta
                ON item_meta.post_id = p.ID
                AND item_meta.meta_key = '_wei_fr_ebay_item_id'
                AND item_meta.meta_value <> ''
            LEFT JOIN {$postmeta} listing_status_meta
                ON listing_status_meta.post_id = p.ID
                AND listing_status_meta.meta_key = '_wei_fr_ebay_listing_status'
                AND listing_status_meta.meta_value = 'published'
            LEFT JOIN {$postmeta} current_active_meta
                ON current_active_meta.post_id = p.ID
                AND current_active_meta.meta_key = '_wei_fr_ebay_current_listing_state'
                AND current_active_meta.meta_value = 'active'
            LEFT JOIN {$postmeta} export_published_meta
                ON export_published_meta.post_id = p.ID
                AND export_published_meta.meta_key = '_wei_fr_ebay_export_status'
                AND export_published_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
                AND (
                    %d = 1
                    OR NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} excluded_meta
                        WHERE excluded_meta.post_id = p.ID
                            AND excluded_meta.meta_key = '_wei_fr_ebay_export_status'
                            AND excluded_meta.meta_value = 'excluded_from_ebay'
                    )
                )
                AND listing_status_meta.post_id IS NULL
                AND export_published_meta.post_id IS NULL
                AND NOT (
                    current_active_meta.post_id IS NOT NULL
                    AND (listing_meta.post_id IS NOT NULL OR item_meta.post_id IS NOT NULL)
                )
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $includeExcluded ? 1 : 0, $batchSize))));
    }

    private function initial_publish_already_published_diagnostics(int $productId): array
    {
        $listingId = trim((string) get_post_meta($productId, '_wei_fr_ebay_listing_id', true));
        if ($listingId === '') {
            $listingId = trim((string) get_post_meta($productId, '_wei_fr_ebay_item_id', true));
        }
        $activeListingState = (string) get_post_meta($productId, '_wei_fr_ebay_current_listing_state', true);
        $listingStatus = (string) get_post_meta($productId, '_wei_fr_ebay_listing_status', true);
        $exportStatus = (string) get_post_meta($productId, '_wei_fr_ebay_export_status', true);
        $alreadyPublished = ($listingId !== '' && $activeListingState === 'active')
            || $listingStatus === 'published'
            || $exportStatus === 'published';

        return [
            'product_id' => $productId,
            'sku' => (string) get_post_meta($productId, '_sku', true),
            'offer_id' => (string) get_post_meta($productId, '_wei_fr_ebay_offer_id', true),
            'listing_id' => $listingId,
            'active_listing_state' => $activeListingState,
            'skipped_reason' => $alreadyPublished ? 'already_published_active_listing' : '',
            'already_published' => $alreadyPublished,
        ];
    }

    private function is_initial_publish_already_published(int $productId): bool
    {
        $diagnostics = $this->initial_publish_already_published_diagnostics($productId);
        return !empty($diagnostics['already_published']);
    }

    private function initial_publish_status(): array
    {
        $candidateSummary = $this->initial_publish_candidate_summary();
        $totalReady = $this->initial_publish_total_ready($candidateSummary);
        $success = (int) get_option('wei_fr_ebay_initial_publish_success', 0);

        return [
            'total_ready' => $totalReady,
            'processed' => (int) get_option('wei_fr_ebay_initial_publish_processed', 0),
            'success' => $success,
            'failed' => (int) get_option('wei_fr_ebay_initial_publish_failed', 0),
            'cursor' => (int) get_option('wei_fr_ebay_initial_publish_cursor', 0),
            'last_run_at' => (string) get_option('wei_fr_ebay_initial_publish_last_run_at', ''),
            'last_error' => (string) get_option('wei_fr_ebay_initial_publish_last_error', ''),
            'status' => (string) get_option('wei_fr_ebay_initial_publish_status', 'idle'),
            'remaining' => max(0, $totalReady - $success),
            'last_batch_success' => (int) get_option('wei_fr_ebay_initial_publish_last_batch_success', 0),
            'last_batch_failed' => (int) get_option('wei_fr_ebay_initial_publish_last_batch_failed', 0),
            'last_batch_processed' => (int) get_option('wei_fr_ebay_initial_publish_last_batch_processed', 0),
            'skipped' => (int) get_option('wei_fr_ebay_initial_publish_skipped', 0),
            'last_published_product_id' => (int) get_option('wei_fr_ebay_initial_publish_last_published_product_id', 0),
            'last_listing_id' => (string) get_option('wei_fr_ebay_initial_publish_last_listing_id', ''),
            'candidate_summary' => $candidateSummary,
            'last_batch_log' => (array) get_option('wei_fr_ebay_initial_publish_last_batch_log', []),
            'summary' => $this->publish_summary_counts($candidateSummary, $success),
        ];
    }

    private function publish_summary_counts(array $candidateSummary, int $success): array
    {
        $state = $this->ebay_listing_state_summary();
        return [
            'historical_published_count' => $this->count_initial_publish_already_published_products(),
            'current_active_listing_count' => (int) ($state['current_active_listing_count'] ?? 0),
            'current_offer_count' => (int) ($state['current_offer_count'] ?? 0),
            'publish_progress_published_this_run' => $success,
            'published_total_from_old_checkpoint' => (int) get_option('wei_fr_ebay_initial_publish_success', 0),
            'needs_reexport_count' => (int) ($state['needs_reexport_count'] ?? $this->count_products_with_export_status('needs_reexport')),
            'ended_listing_count' => (int) ($state['ended_listing_count'] ?? 0),
        ];
    }

    private function initial_publish_total_ready(array $candidateSummary = []): int
    {
        $savedTotal = (int) get_option('wei_fr_ebay_initial_publish_total_ready', 0);
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
            'wei_fr_ebay_initial_publish_total_ready',
            'wei_fr_ebay_initial_publish_processed',
            'wei_fr_ebay_initial_publish_success',
            'wei_fr_ebay_initial_publish_failed',
            'wei_fr_ebay_initial_publish_skipped',
            'wei_fr_ebay_initial_publish_cursor',
            'wei_fr_ebay_initial_publish_last_run_at',
            'wei_fr_ebay_initial_publish_last_error',
            'wei_fr_ebay_initial_publish_status',
            'wei_fr_ebay_initial_publish_last_batch_success',
            'wei_fr_ebay_initial_publish_last_batch_failed',
            'wei_fr_ebay_initial_publish_last_batch_processed',
            'wei_fr_ebay_initial_publish_last_published_product_id',
            'wei_fr_ebay_initial_publish_last_listing_id',
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
        check_admin_referer('wei_fr_auto_sync_toggle_pause');
        $s = $this->settings();
        $s['auto_sync_paused'] = empty($s['auto_sync_paused']) ? 1 : 0;
        update_option(Plugin::OPTION_KEY, $s, false);
        $this->set_status(!empty($s['auto_sync_paused']) ? 'Auto sync paused' : 'Auto sync resumed');
        $this->go();
    }


    public function vehicle_compatibility_diagnostics(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_vehicle_compatibility_diagnostics');
        $id = (int) ($_REQUEST['product_id'] ?? 0);
        $service = new VehicleCompatibilityAuditService($this->categoryRepo, $this->logger);
        $res = $id > 0 ? $service->auditProduct($id, 'EBAY_FR') : ['result' => 'error', 'error' => 'missing_product_id', 'called_ebay_api' => false, 'updated_ebay_listing' => false, 'wrote_product_meta' => false];
        update_option('wei_fr_ebay_last_vehicle_compatibility_diagnostics', $res, false);
        $this->set_status('Vehicle compatibility diagnostics: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function run_vehicle_compatibility_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_run_vehicle_compatibility_audit');
        $limit = isset($_POST['limit']) ? max(1, min(5000, (int) $_POST['limit'])) : 500;
        $service = new VehicleCompatibilityAuditService($this->categoryRepo, $this->logger);
        $res = $service->generateAuditCsv('EBAY_FR', $limit);
        update_option('wei_fr_ebay_vehicle_compatibility_audit_summary', $res, false);
        $this->set_status('Vehicle compatibility readiness audit: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&wei_fr_section=category-mappings'));
        exit;
    }

    public function preflight_product(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_preflight');
        $id = (int) ($_REQUEST['product_id'] ?? 0);
        $res = $id > 0 ? $this->adapter->preflight_product($id) : ['result' => 'error', 'error' => 'missing_product_id'];
        $this->set_status('Preflight: ' . wp_json_encode($res));
        $this->go();
    }

    public function publish_listing_diagnostics(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_publish_listing_diagnostics');
        $productId = absint($_POST['product_id'] ?? 0);
        $diagnostics = $productId > 0 ? $this->fr_publish_product_listing_diagnostics($productId) : ['result' => 'error', 'error' => 'missing_product_id'];
        update_option('wei_fr_ebay_publish_listing_diagnostics', $diagnostics, false);
        $this->set_status('FR publish listing diagnostics: ' . wp_json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->go();
    }

    public function publish_product_offer_only(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_publish_product_offer_only');
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
        $report = $this->record_fr_publish_action($this->new_fr_publish_run_id(), $id, 'publish', $res, [], gmdate('Y-m-d H:i:s'));
        if (!empty($report['report_write_error'])) {
            $res['report_write_error'] = $report['report_write_error'];
        }
        $this->set_status('Manual publish offer only: ' . wp_json_encode($res));
        $this->go();
    }


    public function inspect_offer_before_publish(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_inspect_offer_before_publish');
        $id = (int) ($_POST['product_id'] ?? 0);
        $res = $id > 0 ? $this->adapter->inspect_offer_before_publish_action($id) : [
            'result' => 'error',
            'status' => 'missing_product_id',
            'message' => 'Missing Woo product ID.',
            'called_publish_offer' => false,
        ];
        $this->set_status('Inspect offer before publish: ' . wp_json_encode($res));
        $this->go();
    }

    public function verify_api_publishing_readiness(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_verify_api_publishing_readiness');
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
        check_admin_referer('wei_fr_save_category_mapping');
        $termId = (int) ($_POST['woo_term_id'] ?? 0);
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        $ebayCategoryId = sanitize_text_field((string) ($_POST['ebay_category_id'] ?? ''));
        if ($marketplaceId !== 'EBAY_FR') {
            $this->set_status('Manual category mapping blocked: this screen only supports EBAY_FR.');
            $this->go_category_mapping_screen();
        }
        if ($termId <= 0 || $ebayCategoryId === '') {
            $this->set_status('Category mapping skipped: missing Woo term or eBay category ID');
            $this->go_category_mapping_screen();
        }

        $validation = $this->taxonomy->validate_category_result($marketplaceId, $ebayCategoryId);
        if (empty($validation['valid'])) {
            $this->set_status('Category mapping rejected: invalid eBay.fr category ID ' . $ebayCategoryId . '. ' . (string) ($validation['taxonomy_error'] ?? ''));
            $this->go_category_mapping_screen();
        }
        if (empty($validation['leaf'])) {
            $this->set_status('Category mapping rejected: eBay.fr category ID ' . $ebayCategoryId . ' is not a leaf category.');
            $this->go_category_mapping_screen();
        }

        $saved = $this->categoryRepo->save_manual_mapping($termId, $marketplaceId, [
            'category_id' => $ebayCategoryId,
            'category_name' => (string) ($validation['category_name'] ?? sanitize_text_field((string) ($_POST['ebay_category_name'] ?? ''))),
            'category_path' => (string) ($validation['category_path'] ?? sanitize_text_field((string) ($_POST['ebay_category_path'] ?? ''))),
        ]);

        $categoryValidation = get_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, []);
        $categoryValidation = is_array($categoryValidation) ? $categoryValidation : [];
        $categoryValidation['by_woo_term_id'][(string) $termId] = [
            'woo_term_id' => $termId,
            'category_id' => $ebayCategoryId,
            'valid' => true,
            'leaf' => true,
            'validation_status' => 'valid_leaf',
            'category_name' => (string) ($validation['category_name'] ?? ''),
            'category_path' => (string) ($validation['category_path'] ?? ''),
            'source' => 'manual_mapping_save',
            'updated_at' => gmdate('c'),
        ];
        $categoryValidation['by_category_id'][$ebayCategoryId] = $categoryValidation['by_woo_term_id'][(string) $termId];
        update_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, $categoryValidation, false);

        $this->set_status('Manual EBAY_FR category mapping saved: Woo term ' . $termId . ' → eBay leaf ' . $ebayCategoryId . ' (selected row ' . (int) ($saved['selected_id'] ?? 0) . ', disabled duplicates ' . (int) ($saved['duplicates_disabled'] ?? 0) . ').');
        $this->go_category_mapping_screen();
    }

    public function import_ebay_fr_category_tree_cache(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_import_ebay_fr_category_tree_cache');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_FR'));
        if ($marketplaceId !== 'EBAY_FR') {
            $this->set_status('Category tree cache import rejected: only EBAY_FR is supported.');
            $this->go_category_mapping_screen();
        }

        $rows = [];
        $raw = trim((string) ($_POST['category_tree_json'] ?? ''));
        if ($raw !== '') {
            $decoded = json_decode(wp_unslash($raw), true);
            if (is_array($decoded)) {
                $rows = array_is_list($decoded) ? $decoded : (array) ($decoded['categories'] ?? []);
            }
        }
        $res = $this->taxonomy->import_cached_categories($marketplaceId, $rows);
        $this->set_status('EBAY_FR category tree cache import: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go_category_mapping_screen();
    }

    public function upsert_inventory_location(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_upsert_inventory_location');
        $res = $this->adapter->upsert_inventory_location();
        $this->set_status('Inventory location: ' . wp_json_encode($res));
        $this->go();
    }

    public function refresh_policies(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_refresh_policies');
        $res = $this->adapter->refresh_policies();
        $this->set_status('Refresh policies: ' . wp_json_encode($res));
        $this->go();
    }

    /** @return array<string,mixed> */
    private function empty_shipping_mapping_report(): array
    {
        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'category_ids_130' => [],
            'category_ids_50' => [],
            'count_categories_130' => 0,
            'count_categories_50' => 0,
            'estimated_products_130' => 0,
            'estimated_products_50' => 0,
            'estimated_products_default_30' => 0,
            'total_products' => 0,
            'counts' => ['30_eur' => 0, '50_eur' => 0, '130' => 0, 'shipping_30' => 0],
            'sample_terms' => [],
            'unmapped_categories' => [],
            'warnings' => [],
            'mass_update_enabled' => false,
            'partial' => true,
            'note' => 'Raport rozpoczęty, ale nie został jeszcze ukończony.',
        ];
    }

    /** @return array<int,array{term_id:int,name:string,slug:string,parent:int,count:int}> */
    private function shipping_mapping_product_category_terms(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT t.term_id, t.name, t.slug, tt.parent, tt.count
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE tt.taxonomy = 'product_cat'
             ORDER BY t.name ASC",
            ARRAY_A
        );

        return is_array($rows) ? array_map(static function (array $row): array {
            return [
                'term_id' => (int) ($row['term_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'parent' => (int) ($row['parent'] ?? 0),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }, $rows) : [];
    }

    /** @param array<int,int> $includeTermIds @param array<int,int> $excludeTermIds */
    private function count_products_for_shipping_mapping(array $includeTermIds = [], array $excludeTermIds = [], bool $requireIncludeTerms = false): int
    {
        $includeTermIds = array_values(array_unique(array_filter(array_map('absint', $includeTermIds))));
        if ($requireIncludeTerms && $includeTermIds === []) {
            return 0;
        }

        global $wpdb;

        $statuses = ['publish', 'draft', 'pending', 'private'];
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = $statuses;

        $join = '';
        $where = "p.post_type = 'product' AND p.post_status IN ({$statusPlaceholders})";

        if ($includeTermIds !== []) {
            $includePlaceholders = implode(',', array_fill(0, count($includeTermIds), '%d'));
            $join .= " INNER JOIN {$wpdb->term_relationships} tr_include ON tr_include.object_id = p.ID";
            $join .= " INNER JOIN {$wpdb->term_taxonomy} tt_include ON tt_include.term_taxonomy_id = tr_include.term_taxonomy_id AND tt_include.taxonomy = 'product_cat'";
            $where .= " AND tt_include.term_id IN ({$includePlaceholders})";
            $params = array_merge($params, $includeTermIds);
        }

        $excludeTermIds = array_values(array_unique(array_filter(array_map('absint', $excludeTermIds))));
        if ($excludeTermIds !== []) {
            $excludePlaceholders = implode(',', array_fill(0, count($excludeTermIds), '%d'));
            $where .= " AND NOT EXISTS (
                SELECT 1
                FROM {$wpdb->term_relationships} tr_exclude
                INNER JOIN {$wpdb->term_taxonomy} tt_exclude ON tt_exclude.term_taxonomy_id = tr_exclude.term_taxonomy_id
                WHERE tr_exclude.object_id = p.ID
                  AND tt_exclude.taxonomy = 'product_cat'
                  AND tt_exclude.term_id IN ({$excludePlaceholders})
            )";
            $params = array_merge($params, $excludeTermIds);
        }

        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p{$join} WHERE {$where}";
        $prepared = $wpdb->prepare($sql, $params);

        return max(0, (int) $wpdb->get_var($prepared));
    }

    /** @param array<string,mixed> $partialReport */
    private function guard_shipping_mapping_report_memory(array $partialReport, string $stage): void
    {
        $limit = $this->memory_limit_bytes();
        if ($limit <= 0) {
            return;
        }

        $usage = memory_get_usage(true);
        if ($usage < (int) floor($limit * 0.85)) {
            return;
        }

        $partialReport['partial'] = true;
        $partialReport['warnings'] = array_merge((array) ($partialReport['warnings'] ?? []), [
            'Report stopped by memory guard at stage: ' . $stage,
        ]);
        update_option('wei_fr_ebay_shipping_mapping_report', $partialReport, false);
        $this->logger->warning('EBAY_SHIPPING_MAPPING_REPORT_MEMORY_GUARD', [
            'stage' => $stage,
            'memory_usage' => $usage,
            'memory_limit' => $limit,
        ]);

        throw new \RuntimeException('Shipping mapping report stopped by memory guard at ' . $stage . '.');
    }

    private function memory_limit_bytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (float) $raw;
        return match ($unit) {
            'g' => (int) ($value * 1024 * 1024 * 1024),
            'm' => (int) ($value * 1024 * 1024),
            'k' => (int) ($value * 1024),
            default => (int) $value,
        };
    }


    public function generate_listing_quality_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_listing_quality_audit');

        global $wpdb;
        $batchSize = 100;
        $offset = 0;
        $rows = [];
        $summary = [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'scanned' => 0,
            'suspected_wrong_ebay_category' => 0,
            'missing_fitment' => 0,
            'missing_description' => 0,
            'missing_specifics' => 0,
            'manual_only' => true,
            'offers' => [],
            'fitment_analysis' => ['ready' => 0, 'needs_manual_review' => 0],
            'shipping_policy_audit' => ['policy_30' => 0, 'policy_50' => 0, 'policy_100' => 0, 'other' => 0],
            'condition_conflicts' => [],
            'condition_conflict_count' => 0,
            'custom_stan_aspect_count' => 0,
            'title_contains_neu_count' => 0,
            'polish_condition_aspects_count' => 0,
            'ready_for_condition_cleanup_count' => 0,
            'description_condition_conflict_count' => 0,
            'basic_specifics_ready_for_update_count' => 0,
            'missing_hersteller_count' => 0,
            'missing_mpn_count' => 0,
            'missing_herstellernummer_count' => 0,
            'missing_oem_reference_count' => 0,
            'missing_country_of_origin_count' => 0,
            'seller_notes_supported_count' => 0,
            'seller_notes_unknown_count' => 0,
            'polish_specifics_to_remove_count' => 0,
        ];

        do {
            $sql = $wpdb->prepare("SELECT p.ID as product_id, p.post_title, sku.meta_value as sku, offer.meta_value as offer_id, listing.meta_value as listing_id, url.meta_value as public_url, cat.meta_value as ebay_category_id, catn.meta_value as ebay_category_name, catp.meta_value as ebay_category_path, pol.meta_value as shipping_policy_id, ship.meta_value as shipping_group, descr.meta_value as de_description, aspects.meta_value as aspects_json, status.meta_value as sync_status FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id=p.ID AND sku.meta_key='_sku') LEFT JOIN {$wpdb->postmeta} offer ON (offer.post_id=p.ID AND offer.meta_key='_wei_fr_ebay_offer_id') LEFT JOIN {$wpdb->postmeta} listing ON (listing.post_id=p.ID AND listing.meta_key='_wei_fr_ebay_listing_id') LEFT JOIN {$wpdb->postmeta} url ON (url.post_id=p.ID AND url.meta_key='_wei_fr_ebay_public_url') LEFT JOIN {$wpdb->postmeta} cat ON (cat.post_id=p.ID AND cat.meta_key='_wei_fr_ebay_category_id') LEFT JOIN {$wpdb->postmeta} catn ON (catn.post_id=p.ID AND catn.meta_key='_wei_fr_ebay_category_name') LEFT JOIN {$wpdb->postmeta} catp ON (catp.post_id=p.ID AND catp.meta_key='_wei_fr_ebay_category_path') LEFT JOIN {$wpdb->postmeta} pol ON (pol.post_id=p.ID AND pol.meta_key='_wei_fr_ebay_last_fulfillment_policy_id') LEFT JOIN {$wpdb->postmeta} ship ON (ship.post_id=p.ID AND ship.meta_key='_wei_fr_ebay_last_shipping_group') LEFT JOIN {$wpdb->postmeta} descr ON (descr.post_id=p.ID AND descr.meta_key='_wei_fr_ebay_description') LEFT JOIN {$wpdb->postmeta} aspects ON (aspects.post_id=p.ID AND aspects.meta_key='_wei_fr_ebay_aspects_json') LEFT JOIN {$wpdb->postmeta} status ON (status.post_id=p.ID AND status.meta_key='_wei_fr_ebay_last_sync_status') WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private') AND offer.meta_value IS NOT NULL AND offer.meta_value <> '' ORDER BY p.ID ASC LIMIT %d OFFSET %d", $batchSize, $offset);
            $batch = $wpdb->get_results($sql, ARRAY_A);
            if (!is_array($batch) || $batch === []) break;
            foreach ($batch as $r) {
                $aspects = json_decode((string)($r['aspects_json'] ?? ''), true);
                $aspects = is_array($aspects) ? $aspects : [];
                $title = (string)($r['post_title'] ?? '');
                $flags = [];
                if (preg_match('/\bneu!?+|new|nowy|nowa|nowe\b/iu', $title)) $flags[] = 'contains_neu';
                if (preg_match('/\b(FOTELE|KLAPA|KOM)\b/u', $title)) $flags[] = 'contains_polish_words';
                if (mb_strtoupper($title,'UTF-8') === $title && preg_match('/[A-ZĄĆĘŁŃÓŚŹŻ]/u',$title)) $flags[] = 'all_caps';
                if (preg_match('/\bnowy|nowa|nowe\b/iu', $title)) $flags[] = 'contains_nowylike';
                $hasPolishSpecifics = false;
                $conditionAspectsFound = [];
                foreach ($aspects as $k => $vals){ if (preg_match('/stan|używany|uzywany|nowy|nowa|nowe/iu',(string)$k)) { $hasPolishSpecifics=true; $conditionAspectsFound[]=(string)$k; } foreach ((array)$vals as $v){ if (preg_match('/\bnowy|nowa|nowe|neu|new\b/iu',(string)$v)) { $conditionAspectsFound[]=(string)$k . ':' . (string)$v; }}}
                $wooPath = $this->product_category_path((int)$r['product_id']);
                $ebayPath = trim((string)($r['ebay_category_path'] ?? $r['ebay_category_name'] ?? ''));
                $suspectedWrong = str_contains(mb_strtolower($wooPath),'samoch') && preg_match('/Motorrad|Motorblöcke/iu',$ebayPath);
                $missingDesc = trim((string)($r['de_description'] ?? '')) === '';
                $missingSpecifics = count($aspects) < 3;
                $hasFitment = isset($aspects['Fahrzeugmodell']) || isset($aspects['Fahrzeugmarke']) || isset($aspects['KBA-Nummer']);
                $fitmentReady = $hasFitment && (isset($aspects['OE/OEM Referenznummer']) || isset($aspects['Herstellernummer']));
                $skuFallback = trim((string) get_post_meta((int)$r['product_id'], '_wei_fr_ebay_sku', true));
                if ($skuFallback === '') $skuFallback = trim((string)($r['sku'] ?? ''));
                if ($skuFallback === '') $skuFallback = 'GPSW-' . (int)$r['product_id'];
                $descriptionText = (string) ($r['de_description'] ?? '');
                $descriptionContainsNewLikeWords = (bool) preg_match('/\b(neu|neue|neuer|neues|new|nowy|nowa|nowe|fabrikneu|brandneu)\b/iu', wp_strip_all_tags($descriptionText));
                $descriptionContainsNeu = (bool) preg_match('/\b(neu|neue|neuer|neues)\b/iu', wp_strip_all_tags($descriptionText));
                $reviewRequired = (bool) preg_match('/\b(neue?\s+version|neues?\s+modell)\b/iu', wp_strip_all_tags($descriptionText));
                $descriptionCleanupSafe = $descriptionContainsNewLikeWords && !$reviewRequired;
                $suggestedReplacements = [];
                foreach (['NEUE ORIGINAL EUROPÄISCHE LAMPEN' => 'GEBRAUCHTE ORIGINALE EUROPÄISCHE LAMPEN', 'Neue Originalteile' => 'Gebrauchte Originalteile', 'NOWE ORYGINALNE' => 'GEBRAUCHTE ORIGINALE', 'NEW ORIGINAL' => 'USED ORIGINAL'] as $from => $to) {
                    if (mb_stripos($descriptionText, $from, 0, 'UTF-8') !== false) $suggestedReplacements[] = ['from' => $from, 'to' => $to];
                }
                $descriptionConditionConflict = $descriptionContainsNewLikeWords;
                $issues = array_values(array_filter([ $suspectedWrong?'suspected_wrong_ebay_category':'', $missingDesc?'description_missing':'', $missingSpecifics?'missing_required_specifics':'', !$hasFitment?'missing_fitment':'', $conditionAspectsFound!==[]?'condition_conflict':'', $descriptionConditionConflict?'description_condition_conflict':'' ]));
                $hasHersteller = !empty($aspects['Hersteller']) || !empty($aspects['Brand']);
                $hasMpn = !empty($aspects['MPN']);
                $hasHerstellernummer = !empty($aspects['Herstellernummer']) || !empty($aspects['Manufacturer Part Number']);
                $hasOemReference = !empty($aspects['OE/OEM Referenznummer']);
                $hasCountry = !empty($aspects['Ursprungsland']);
                $polishSpecificsToRemove = $conditionAspectsFound;
                $blockedConditionWordsFound = $conditionAspectsFound;
                $readyForBasicSpecifics = !empty($r['offer_id']) && !empty($r['listing_id']);
                $mainCondition = 'USED_EXCELLENT';
                $cleanupNeeded = $conditionAspectsFound!==[] || in_array('contains_neu',$flags,true) || preg_match('/\bneu|nowy|new\b/iu',(string)($r['de_description'] ?? ''));
                $rows[] = [ 'product_id'=>(int)$r['product_id'],'SKU'=>$skuFallback,'offer_id'=>(string)($r['offer_id']??''),'listing_id'=>(string)($r['listing_id']??''),'public_url'=>(string)($r['public_url']??''),'woo_category'=>$wooPath,'ebay_category_id'=>(string)($r['ebay_category_id']??''),'ebay_category_path'=>$ebayPath,'title'=>$title,'title_quality_flags'=>$flags,'title_condition_flags'=>$flags,'image_count'=>'unknown_not_checked','image_count_source'=>'unknown','has_video'=>false,'item_specifics_count'=>count($aspects),'item_specifics_count_state'=>'missing_in_local_meta','item_specifics_count_source'=>'local_meta','has_polish_item_specifics'=>$hasPolishSpecifics,'has_fitment'=>$hasFitment,'shipping_policy_id'=>(string)($r['shipping_policy_id']??''),'shipping_group'=>(string)($r['shipping_group']??''),'description_present'=>!$missingDesc,'readiness_status'=>(string)($r['sync_status']??''),'issues'=>$issues,'main_condition'=>$mainCondition,'condition_aspects_found'=>$conditionAspectsFound,'cleanup_needed'=>$cleanupNeeded,'cleanup_safe'=>(bool)((string)($r['offer_id']??'')!=='' && (string)($r['listing_id']??'')!==''),'description_contains_neu'=>$descriptionContainsNeu,'description_condition_conflict'=>$descriptionConditionConflict,'description_contains_new_like_words'=>$descriptionContainsNewLikeWords,'description_cleanup_safe'=>$descriptionCleanupSafe,'review_required'=>$reviewRequired,'confidence'=>$descriptionContainsNewLikeWords ? ($reviewRequired ? 0.55 : 0.96) : 0.0,'suggested_replacements'=>$suggestedReplacements,'suggested_description_snippet'=>'Gebrauchtes Originalteil. Zustand siehe Fotos. Funktionsfähig, sofern im Angebot angegeben. Bitte Teilenummer und Kompatibilität vor dem Kauf prüfen.','field_sources'=>['sku'=>'local_meta','ebay_category_id'=>((string)($r['ebay_category_id']??'')!==''?'local_meta':'unknown'),'item_specifics'=>'local_meta'],'basic_item_specifics_audit'=>['has_condition'=>true,'condition_value'=>$mainCondition,'has_hersteller'=>$hasHersteller,'has_mpn'=>$hasMpn,'has_herstellernummer'=>$hasHerstellernummer,'has_oem_reference'=>$hasOemReference,'has_country_of_origin'=>$hasCountry,'has_seller_notes_or_condition_description'=>'unknown','polish_specifics_to_remove'=>$polishSpecificsToRemove,'blocked_condition_words_found'=>$blockedConditionWordsFound,'ready_for_basic_specifics_update'=>$readyForBasicSpecifics,'review_required'=>$reviewRequired,'issues'=>$issues],'category_validation'=>['suspected_wrong_ebay_category'=>$suspectedWrong,'woo_category_path'=>$wooPath,'current_ebay_category'=>$ebayPath,'suggested_ebay_category'=>$suspectedWrong ? 'Autoersatz- & -reparaturteile' : '','confidence'=>$suspectedWrong?0.72:0.0,'reason'=>$suspectedWrong?'Woo category looks automotive but eBay path points to Motorrad/Motorblöcke':''],'specifics_audit'=>['missing_required_specifics'=>$missingSpecifics ? ['Hersteller','Herstellernummer','Produktart'] : [],'missing_recommended_specifics'=>['Einbauposition','Fahrzeugmarke','Fahrzeugmodell','Motorcode/Getriebecode'],'polish_specifics_found'=>$hasPolishSpecifics ? ['Stan/Używany'] : [],'suggested_specifics'=>['Zustand'=>'Gebraucht']],'title_suggestions'=>['suggested_title'=>$this->suggest_used_title($title,$aspects),'suggested_title_still_contains_polish'=>(bool)preg_match('/\b(FOTELE|KLAPA|KOM)\b/u',$this->suggest_used_title($title,$aspects)),'title_suggestion_low_confidence'=>false],'fitment_status'=>['category_support_unknown'=>true,'fitment_ready'=>$fitmentReady,'requires_manual_review'=>!$fitmentReady],];
                $summary['scanned']++;
                if($suspectedWrong)$summary['suspected_wrong_ebay_category']++; if(!$hasFitment)$summary['missing_fitment']++; if($missingDesc)$summary['missing_description']++; if($missingSpecifics)$summary['missing_specifics']++;
                if ($conditionAspectsFound !== []) $summary['custom_stan_aspect_count']++;
                if (in_array('contains_neu', $flags, true)) $summary['title_contains_neu_count']++;
                if ($hasPolishSpecifics) $summary['polish_condition_aspects_count']++;
                if (!empty($cleanupNeeded)) $summary['condition_conflict_count']++;
                if (!empty($cleanupNeeded) && !empty($r['offer_id']) && !empty($r['listing_id'])) $summary['ready_for_condition_cleanup_count']++;
                if ($descriptionConditionConflict) $summary['description_condition_conflict_count']++;
                if ($readyForBasicSpecifics) $summary['basic_specifics_ready_for_update_count']++;
                if (!$hasHersteller) $summary['missing_hersteller_count']++;
                if (!$hasMpn) $summary['missing_mpn_count']++;
                if (!$hasHerstellernummer) $summary['missing_herstellernummer_count']++;
                if (!$hasOemReference) $summary['missing_oem_reference_count']++;
                if (!$hasCountry) $summary['missing_country_of_origin_count']++;
                $summary['seller_notes_unknown_count']++;
                if ($polishSpecificsToRemove !== []) $summary['polish_specifics_to_remove_count']++;
                if($fitmentReady)$summary['fitment_analysis']['ready']++; else $summary['fitment_analysis']['needs_manual_review']++;
            }
            $offset += $batchSize;
        } while (true);
        $summary['offers'] = $rows;
        update_option('wei_fr_ebay_listing_quality_audit',$summary,false);
        $this->set_status('Listing quality audit generated: ' . wp_json_encode(['scanned'=>$summary['scanned'],'issues'=>$summary['suspected_wrong_ebay_category']+$summary['missing_fitment']+$summary['missing_description']+$summary['missing_specifics']]));
        $this->go();
    }

    public function condition_cleanup_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_condition_cleanup_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->clean_condition_aspects_single($input);
        $this->set_status('Single condition cleanup: ' . wp_json_encode($res));
        $this->go();
    }

    public function basic_specifics_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_basic_specifics_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->update_basic_item_specifics_single($input);
        $this->set_status('Single basic specifics update: ' . wp_json_encode($res));
        $this->go();
    }

    public function description_condition_cleanup_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_description_condition_cleanup_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->clean_description_condition_single($input);
        $this->set_status('Single description condition cleanup: ' . wp_json_encode($res));
        $this->go();
    }

    public function description_template_preview(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_description_template_preview');
        $input = sanitize_text_field((string) ($_REQUEST['product_or_sku'] ?? ''));
        $res = $this->adapter->preview_ebay_fr_description_template($input);
        $this->render_description_template_preview_response($res);
    }

    public function description_template_publish_dry_run(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_description_template_publish_dry_run');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->dry_run_ebay_fr_publish_description_payload($input);
        $this->render_description_template_publish_dry_run_response($res);
    }

    private function render_description_template_publish_dry_run_response(array $res): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $backUrl = esc_url(admin_url('admin.php?page=woo-ebay-fr'));
        $payloadExcerpt = (array) ($res['payload_excerpt'] ?? []);
        unset($res['payload_excerpt']);
        echo '<div class="wrap" style="font-family:Arial,Helvetica,sans-serif;margin:20px;">';
        echo '<h1>Safe eBay.fr publish description dry-run</h1>';
        echo '<p><a href="' . $backUrl . '">&larr; Back to Woo eBay Integration FR</a></p>';
        echo '<p><strong>Safety:</strong> local payload description dry-run only; no eBay API call, no listing creation, no listing update, no Woo product changes, no Ovoko API call.</p>';
        echo '<h2>Dry-run checks</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '<h2>Payload description excerpt</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode($payloadExcerpt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '</div>';
        exit;
    }

    private function render_description_template_preview_response(array $res): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $backUrl = esc_url(admin_url('admin.php?page=woo-ebay-fr'));
        echo '<div class="wrap" style="font-family:Arial,Helvetica,sans-serif;margin:20px;">';
        echo '<h1>Safe eBay.fr description template preview</h1>';
        echo '<p><a href="' . $backUrl . '">&larr; Back to Woo eBay Integration FR</a></p>';
        echo '<p><strong>Safety:</strong> local preview only; no eBay API, no Ovoko API, no active listing update, no new listing, no Woo product changes.</p>';
        echo '<h2>Preview metadata</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode([
            'result' => (string) ($res['result'] ?? 'error'),
            'product_id' => (int) ($res['product_id'] ?? 0),
            'sku' => (string) ($res['sku'] ?? ''),
            'title' => (string) ($res['title'] ?? ''),
            'description_source' => (string) ($res['description_source'] ?? 'post_content'),
            'current_schema_version' => (string) ($res['current_schema_version'] ?? ''),
            'stored_schema_version' => (string) ($res['stored_schema_version'] ?? ''),
            'template_version' => (string) ($res['template_version'] ?? ''),
            'translation_schema_version' => (string) ($res['translation_schema_version'] ?? ''),
            'source_description_field' => (string) ($res['source_description_field'] ?? ''),
            'source_description_used' => (string) ($res['source_description_used'] ?? ''),
            'stale_reason' => (string) ($res['stale_reason'] ?? ''),
            'stale_reasons' => (array) ($res['stale_reasons'] ?? []),
            'translated_field_value_status' => (array) ($res['translated_field_value_status'] ?? []),
            'template_field_value_diagnostics' => (array) ($res['template_field_value_diagnostics'] ?? []),
            'source_hash' => (string) ($res['source_hash'] ?? ''),
            'cached_translation_hash' => (string) ($res['cached_translation_hash'] ?? ''),
            'stale' => !empty($res['stale']),
            'translation_source' => (array) ($res['translation_source'] ?? []),
            'target_language' => (string) ($res['target_language'] ?? 'de'),
            'translated_raw_html' => !empty($res['translated_raw_html']),
            'html_css_protected' => !empty($res['html_css_protected']),
            'translated_text_nodes' => (array) ($res['translated_text_nodes'] ?? []),
            'protected_technical_values' => (array) ($res['protected_technical_values'] ?? []),
            'translated_fields' => (array) ($res['translated_fields'] ?? []),
            'untranslated_fields' => (array) ($res['untranslated_fields'] ?? []),
            'google_api_called_during_regeneration' => !empty($res['google_api_called_during_regeneration']),
            'preview_called_google_api' => !empty($res['preview_called_google_api']),
            'same_vehicle_url' => (string) ($res['same_vehicle_url'] ?? ''),
            'same_vehicle_cta' => (array) ($res['same_vehicle_cta'] ?? []),
            'same_vehicle_cta_visible' => !empty($res['same_vehicle_cta_visible']),
            'same_vehicle_token' => (string) ($res['same_vehicle_token'] ?? ''),
            'same_vehicle_ebay_url' => (string) ($res['same_vehicle_ebay_url'] ?? ''),
            'same_vehicle_seller_username' => (string) ($res['same_vehicle_seller_username'] ?? ($res['same_vehicle_cta']['seller_username'] ?? '')),
            'same_vehicle_seller_source' => (string) ($res['same_vehicle_seller_source'] ?? ($res['same_vehicle_cta']['seller_source'] ?? '')),
            'warnings' => (array) ($res['warnings'] ?? []),
            'safety' => (array) ($res['safety'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Template field value diagnostics</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode((array) ($res['template_field_value_diagnostics'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Used fields</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode((array) ($res['used_fields'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Missing fields</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode((array) ($res['missing_fields'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Field mapping</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode((array) ($res['field_mapping'] ?? []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        echo '<h2>Source description</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html((string) ($res['source_description'] ?? '')) . '</pre>';
        echo '<h2>Translated description</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html((string) ($res['translated_description'] ?? '')) . '</pre>';
        echo '<h2>Rendered HTML</h2><div style="background:#fff;border:1px solid #dcdcde;padding:12px;overflow:auto;">' . wp_kses_post((string) ($res['html'] ?? '')) . '</div>';
        echo '<h2>Raw HTML</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html((string) ($res['html'] ?? '')) . '</pre>';
        echo '</div>';
        exit;
    }


    public function regenerate_french_content(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_ebay_regenerate_french_content');

        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? $_POST['product_id'] ?? ''));
        $res = $this->adapter->generate_french_content_meta_only_for_identifier($input, true);

        $this->set_status('eBay.fr French content regenerated meta-only: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        wp_send_json($res);
    }

    public function generate_french_content_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_french_content_single');

        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? $_POST['product_id'] ?? ''));
        $res = $this->adapter->generate_french_content_meta_only_for_identifier($input, true);
        update_option('wei_fr_ebay_french_content_single_summary', $res, false);
        $this->set_status('Single-product French content refresh: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->render_french_content_single_response($res);
    }

    private function render_french_content_single_response(array $res): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $backUrl = esc_url(admin_url('admin.php?page=woo-ebay-fr'));
        $productId = (int) ($res['product_id'] ?? 0);
        $sku = (string) ($res['sku'] ?? '');
        $previewIdentifier = $sku !== '' ? $sku : (string) $productId;
        echo '<div class="wrap" style="font-family:Arial,Helvetica,sans-serif;margin:20px;">';
        echo '<h1>Generate / refresh French content for one product</h1>';
        echo '<p><a href="' . $backUrl . '">&larr; Back to Woo eBay Integration FR</a></p>';
        echo '<p><strong>Safety:</strong> local French-content meta only; no eBay API call, no active listing update, no listing publish/export, no Woo title/description change.</p>';
        echo '<h2>Result summary</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        if (($res['generated'] ?? 'no') === 'yes' && $productId > 0) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('wei_fr_description_template_preview');
            echo '<input type="hidden" name="action" value="wei_fr_description_template_preview" />';
            echo '<input type="hidden" name="product_or_sku" value="' . esc_attr($previewIdentifier) . '" />';
            echo '<button class="button button-primary">Preview French listing template for this product</button>';
            echo '</form>';
        }
        echo '</div>';
        exit;
    }


    public function generate_french_content_batch(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_generate_french_content_batch');

        $mode = sanitize_key((string) ($_POST['mode'] ?? 'stale'));
        $mode = in_array($mode, ['all', 'stale', 'force_current_schema'], true) ? $mode : 'stale';
        $includeExcluded = !empty($_POST['include_excluded_from_ebay']);
        $batchSize = max(1, min(200, absint($_POST['batch_size'] ?? 50)));
        if ($mode === 'force_current_schema') {
            $this->run_french_content_schema_migration_batch($batchSize, $includeExcluded, !empty($_POST['continue_migration']));
            return;
        }
        $cursorOption = 'wei_fr_ebay_french_content_batch_cursor_' . $mode;
        $cursor = (int) get_option($cursorOption, 0);
        $productIds = $this->french_content_batch_product_ids($batchSize, $mode, $cursor, $includeExcluded);
        $summary = [
            'mode' => $mode,
            'status' => 'in_progress',
            'cursor' => $cursor,
            'next_cursor' => 0,
            'processed' => 0,
            'processed_total' => 0,
            'generated' => 0,
            'regenerated' => 0,
            'already_fresh' => 0,
            'already_current_schema' => 0,
            'include_excluded_from_ebay' => $includeExcluded,
            'stale_fixed' => 0,
            'errors' => 0,
            'google_api_called' => false,
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'report_url' => '',
            'results' => [],
        ];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $forceCurrentSchema = in_array($mode, ['all', 'force_current_schema'], true);
            $res = $this->adapter->generate_french_content_meta_only($productId, $forceCurrentSchema);
            $summary['processed']++;
            $summary['processed_total']++;
            if (($res['result'] ?? '') === 'already_ready') {
                $summary['already_fresh']++;
                if (($res['stored_schema_version'] ?? '') === \WEI_FR\Services\EbayFrenchContentTranslator::SCHEMA_VERSION && ($res['template_version'] ?? '') === \WEI_FR\Services\EbayFrenchContentTranslator::TEMPLATE_VERSION) {
                    $summary['already_current_schema']++;
                }
            } elseif (($res['result'] ?? '') === 'success' || ($res['result'] ?? '') === 'generated') {
                $summary['generated']++;
                $summary['regenerated']++;
                if (!empty($res['stale_before']) && empty($res['stale_after'])) {
                    $summary['stale_fixed']++;
                }
            } else {
                $summary['errors']++;
            }
            if (!empty($res['google_api_called'])) {
                $summary['google_api_called'] = true;
            }
            $summary['results'][] = [
                'product_id' => $productId,
                'result' => (string) ($res['result'] ?? ''),
                'stale_before' => !empty($res['stale_before']),
                'stale_after' => !empty($res['stale_after']),
                'google_api_called' => !empty($res['google_api_called']),
                'called_ebay_api' => false,
                'updated_ebay_listing' => false,
                'stored_schema_version' => (string) ($res['stored_schema_version'] ?? ''),
                'template_version' => (string) ($res['template_version'] ?? ''),
                'stale_reason' => (string) ($res['stale_reason'] ?? ''),
                'stale_reasons' => (array) ($res['stale_reasons'] ?? []),
            ];
        }

        $lastProductId = $productIds !== [] ? max(array_map('intval', $productIds)) : 0;
        $completed = count($productIds) < $batchSize;
        $summary['status'] = $completed ? 'completed' : 'in_progress';
        $summary['next_cursor'] = $completed ? 0 : $lastProductId;
        if ($completed) {
            delete_option($cursorOption);
        } else {
            update_option($cursorOption, $lastProductId, false);
        }

        update_option('wei_fr_ebay_french_content_audit_summary', array_diff_key($summary, ['results' => true]), false);
        $this->set_status('Generate French content: ' . wp_json_encode($summary, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function regenerate_french_content_batch_ajax(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_regenerate_french_content_batch');

        $batchSize = max(1, min(200, absint($_POST['batch_size'] ?? 50)));
        $includeExcluded = !empty($_POST['include_excluded_from_ebay']);
        $forceCurrentSchema = !array_key_exists('force_current_schema', $_POST) || !empty($_POST['force_current_schema']);
        $autoRunnerBatchIndex = max(0, absint($_POST['auto_runner_batch_index'] ?? 0));

        try {
            if (!$forceCurrentSchema) {
                wp_send_json([
                    'result' => 'error',
                    'processed' => 0,
                    'regenerated' => 0,
                    'already_current_schema' => 0,
                    'stale_fixed' => 0,
                    'skipped' => 0,
                    'failed' => 1,
                    'errors' => ['force_current_schema_required'],
                    'remaining_products' => $this->french_content_target_product_count($includeExcluded),
                    'total_target_products' => $this->french_content_target_product_count($includeExcluded),
                    'queue_empty' => false,
                    'fatal_error' => true,
                    'stopped_reason' => 'force_current_schema_required',
                    'called_ebay_api' => false,
                    'updated_ebay_listing' => false,
                    'published' => false,
                    'exported' => false,
                    'modified_woo_source_content' => false,
                    'auto_runner_batch_index' => $autoRunnerBatchIndex,
                    'batch_size' => $batchSize,
                ]);
            }

            $payload = $this->process_french_content_schema_migration_batch($batchSize, $includeExcluded, true);
            $payload['auto_runner_batch_index'] = $autoRunnerBatchIndex;
            $payload['batch_size'] = $batchSize;
            $payload['stopped_reason'] = (string) ($payload['stopped_reason'] ?? '');
            $payload['fatal_error'] = !empty($payload['fatal_error']);

            $this->logger->info('FRENCH_CONTENT_AUTO_RUNNER_BATCH', [
                'auto_runner_batch_index' => $autoRunnerBatchIndex,
                'batch_size' => $batchSize,
                'processed' => (int) ($payload['processed'] ?? 0),
                'regenerated' => (int) ($payload['regenerated'] ?? 0),
                'already_current_schema' => (int) ($payload['already_current_schema'] ?? 0),
                'stale_fixed' => (int) ($payload['stale_fixed'] ?? 0),
                'skipped' => (int) ($payload['skipped'] ?? 0),
                'failed' => (int) ($payload['failed'] ?? 0),
                'remaining_products' => (int) ($payload['remaining_products'] ?? 0),
                'queue_empty' => (bool) ($payload['queue_empty'] ?? false),
                'stopped_reason' => (string) ($payload['stopped_reason'] ?? ''),
                'called_ebay_api' => false,
            ]);

            wp_send_json($payload);
        } catch (\Throwable $e) {
            wp_send_json([
                'result' => 'error',
                'processed' => 0,
                'regenerated' => 0,
                'already_current_schema' => 0,
                'stale_fixed' => 0,
                'skipped' => 0,
                'failed' => 1,
                'errors' => [$e->getMessage()],
                'remaining_products' => $this->french_content_target_product_count($includeExcluded),
                'total_target_products' => $this->french_content_target_product_count($includeExcluded),
                'queue_empty' => false,
                'fatal_error' => true,
                'stopped_reason' => 'fatal_error',
                'called_ebay_api' => false,
                'updated_ebay_listing' => false,
                'published' => false,
                'exported' => false,
                'modified_woo_source_content' => false,
                'auto_runner_batch_index' => $autoRunnerBatchIndex,
                'batch_size' => $batchSize,
            ]);
        }
    }


    private function run_french_content_schema_migration_batch(int $batchSize, bool $includeExcluded, bool $continue): void
    {
        $state = $this->process_french_content_schema_migration_batch($batchSize, $includeExcluded, $continue);
        $this->set_status('French content schema migration: ' . wp_json_encode($state, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    private function process_french_content_schema_migration_batch(int $batchSize, bool $includeExcluded, bool $continue): array
    {
        $state = $continue ? get_option(self::GERMAN_CONTENT_MIGRATION_STATE_OPTION, []) : [];
        $state = is_array($state) ? $state : [];
        if (!$continue || $state === [] || !empty($state['complete'])) {
            $state = $this->new_french_content_schema_migration_state($batchSize, $includeExcluded);
            $this->initialize_french_content_migration_reports($state);
        } else {
            $state['batch_size'] = $batchSize;
            $includeExcluded = !empty($state['include_excluded_from_ebay']);
        }

        $productIds = $this->french_content_batch_product_ids($batchSize, 'force_current_schema', (int) ($state['current_offset'] ?? 0), $includeExcluded);
        $rows = [];
        $processedProductIds = [];
        $regeneratedProductIds = [];
        $alreadyCurrentSchemaProductIds = [];
        $errorProductIds = [];
        $sampleProducts = [];
        $batchProcessed = 0;
        $batchRegenerated = 0;
        $batchAlreadyCurrent = 0;
        $batchStaleFixed = 0;
        $batchFailed = 0;
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if (count($processedProductIds) < 100) {
                $processedProductIds[] = $productId;
            }
            $before = $this->adapter->french_content_schema_status_for_identifier((string) $productId);
            $alreadyCurrent = empty($before['stale_bool'])
                && (string) ($before['current_stored_schema_version'] ?? '') === \WEI_FR\Services\EbayFrenchContentTranslator::SCHEMA_VERSION
                && (string) ($before['current_stored_template_version'] ?? '') === \WEI_FR\Services\EbayFrenchContentTranslator::TEMPLATE_VERSION;
            if ($alreadyCurrent) {
                $res = [
                    'result' => 'already_current_schema',
                    'stored_schema_version' => (string) ($before['current_stored_schema_version'] ?? ''),
                    'template_version' => (string) ($before['current_stored_template_version'] ?? ''),
                    'called_ebay_api' => false,
                    'updated_ebay_listing' => false,
                ];
                $after = $before;
            } else {
                $res = $this->adapter->generate_french_content_meta_only($productId, true);
                $after = $this->adapter->french_content_schema_status_for_identifier((string) $productId);
            }
            $result = (string) ($res['result'] ?? '');
            $error = (string) (($res['error_message'] ?? '') ?: ($res['reason'] ?? ''));
            $regenerated = !$alreadyCurrent && in_array($result, ['success', 'generated'], true);
            $staleFixed = !empty($before['stale_bool']) && empty($after['stale_bool']);

            $state['processed_total'] = (int) ($state['processed_total'] ?? 0) + 1;
            $batchProcessed++;
            if ($regenerated) {
                $state['regenerated_total'] = (int) ($state['regenerated_total'] ?? 0) + 1;
                $batchRegenerated++;
            }
            if ($alreadyCurrent) {
                $state['already_current_schema_total'] = (int) ($state['already_current_schema_total'] ?? 0) + 1;
                $batchAlreadyCurrent++;
            }
            if ($staleFixed) {
                $state['stale_fixed_total'] = (int) ($state['stale_fixed_total'] ?? 0) + 1;
                $batchStaleFixed++;
            }
            if ((!$regenerated && !$alreadyCurrent) || $error !== '') {
                $state['errors_total'] = (int) ($state['errors_total'] ?? 0) + 1;
                $batchFailed++;
            }
            if ($regenerated && count($regeneratedProductIds) < 100) {
                $regeneratedProductIds[] = $productId;
            }
            if ($alreadyCurrent && count($alreadyCurrentSchemaProductIds) < 100) {
                $alreadyCurrentSchemaProductIds[] = $productId;
            }
            if (((!$regenerated && !$alreadyCurrent) || $error !== '') && count($errorProductIds) < 100) {
                $errorProductIds[] = $productId;
            }

            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            $row = [
                'product_id' => $productId,
                'sku' => is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : (string) ($before['sku'] ?? ''),
                'product_title' => is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : (string) ($before['product_title'] ?? ''),
                'old_schema_version' => (string) ($before['current_stored_schema_version'] ?? ''),
                'new_schema_version' => (string) ($after['current_stored_schema_version'] ?? ($res['stored_schema_version'] ?? '')),
                'old_template_version' => (string) ($before['current_stored_template_version'] ?? ''),
                'new_template_version' => (string) ($after['current_stored_template_version'] ?? ($res['template_version'] ?? '')),
                'stale_before' => !empty($before['stale_bool']) ? 'yes' : 'no',
                'stale_after' => !empty($after['stale_bool']) ? 'yes' : 'no',
                'stale_reasons' => implode('|', array_map('strval', (array) ($before['stale_reasons'] ?? []))),
                'regenerated' => $regenerated ? 'yes' : 'no',
                'already_current_schema' => $alreadyCurrent ? 'yes' : 'no',
                'source_description_field' => (string) ($before['source_description_field'] ?? ''),
                'source_description_used' => (string) ($before['source_description_used'] ?? ''),
                'error_message' => ($regenerated || $alreadyCurrent) ? '' : $error,
            ];
            $rows[] = $row;
            if (count($sampleProducts) < 20) {
                $sampleProducts[] = array_merge(array_intersect_key($row, array_flip([
                    'product_id',
                    'sku',
                    'product_title',
                    'regenerated',
                    'stale_before',
                    'stale_after',
                    'stale_reasons',
                    'old_schema_version',
                    'new_schema_version',
                    'source_description_field',
                ])), [
                    'preview_url' => $this->french_content_preview_url($productId),
                ]);
            }
            $state['current_offset'] = max((int) ($state['current_offset'] ?? 0), $productId);
        }

        $state['processed_product_ids'] = $processedProductIds;
        $state['regenerated_product_ids'] = $regeneratedProductIds;
        $state['already_current_schema_product_ids'] = $alreadyCurrentSchemaProductIds;
        $state['error_product_ids'] = $errorProductIds;
        $state['sample_products'] = $sampleProducts;
        $state['last_batch_sample_limits'] = [
            'processed_product_ids' => 100,
            'regenerated_product_ids' => 100,
            'already_current_schema_product_ids' => 100,
            'error_product_ids' => 100,
            'sample_products' => 20,
        ];
        $state['last_updated_at'] = gmdate('c');
        $state['remaining_products'] = max(0, (int) ($state['total_target_products'] ?? 0) - (int) ($state['processed_total'] ?? 0));
        if ((int) ($state['processed_total'] ?? 0) >= (int) ($state['total_target_products'] ?? 0) || count($productIds) < $batchSize) {
            $state['complete'] = true;
            $state['completed_at'] = gmdate('c');
            $state['remaining_products'] = 0;
        }
        $state['reports'] = $this->append_french_content_migration_reports($state, $rows);
        $state['result'] = !empty($state['complete']) ? 'completed' : 'success';
        $state['processed'] = $batchProcessed;
        $state['regenerated'] = $batchRegenerated;
        $state['already_current_schema'] = $batchAlreadyCurrent;
        $state['stale_fixed'] = $batchStaleFixed;
        $state['skipped'] = max(0, $batchSize - $batchProcessed);
        $state['failed'] = $batchFailed;
        $state['errors'] = $errorProductIds;
        $state['queue_empty'] = !empty($state['complete']) || (int) ($state['remaining_products'] ?? 0) <= 0;
        $state['fatal_error'] = false;
        $state['stopped_reason'] = '';
        $state['called_ebay_api'] = false;
        $state['updated_ebay_listing'] = false;
        $state['published'] = false;
        $state['exported'] = false;
        $state['modified_woo_source_content'] = false;
        update_option(self::GERMAN_CONTENT_MIGRATION_STATE_OPTION, $state, false);
        update_option('wei_fr_ebay_french_content_audit_summary', $state, false);
        return $state;
    }

    private function new_french_content_schema_migration_state(int $batchSize, bool $includeExcluded): array
    {
        $now = gmdate('c');
        $reports = $this->french_content_migration_report_paths();
        $totalTargetProducts = $this->french_content_target_product_count($includeExcluded);
        return [
            'migration_run_id' => 'french-content-schema-' . gmdate('Ymd-His') . '-' . wp_generate_password(6, false, false),
            'schema_version' => \WEI_FR\Services\EbayFrenchContentTranslator::SCHEMA_VERSION,
            'template_version' => \WEI_FR\Services\EbayFrenchContentTranslator::TEMPLATE_VERSION,
            'started_at' => $now,
            'last_updated_at' => $now,
            'completed_at' => '',
            'complete' => false,
            'batch_size' => $batchSize,
            'current_offset' => 0,
            'processed_total' => 0,
            'total_target_products' => $totalTargetProducts,
            'remaining_products' => $totalTargetProducts,
            'regenerated_total' => 0,
            'already_current_schema_total' => 0,
            'stale_fixed_total' => 0,
            'errors_total' => 0,
            'excluded_skipped_total' => $includeExcluded ? 0 : $this->french_content_excluded_product_count(),
            'include_excluded_from_ebay' => $includeExcluded,
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'published' => false,
            'reports' => $reports,
        ];
    }

    private function french_content_target_product_count(bool $includeExcluded): int
    {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private') AND (%d = 1 OR NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} excluded_meta WHERE excluded_meta.post_id = p.ID AND excluded_meta.meta_key = '_wei_fr_ebay_export_status' AND excluded_meta.meta_value = 'excluded_from_ebay')) AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id = p.ID AND t.name <> 'Bez kategorii' AND t.slug <> 'bez-kategorii' AND t.slug <> 'uncategorized')";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $includeExcluded ? 1 : 0));
    }

    private function french_content_excluded_product_count(): int
    {
        global $wpdb;
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} excluded_meta ON excluded_meta.post_id = p.ID AND excluded_meta.meta_key = '_wei_fr_ebay_export_status' AND excluded_meta.meta_value = 'excluded_from_ebay' WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'private')";
        return (int) $wpdb->get_var($sql);
    }

    private function french_content_migration_report_paths(): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-integration-fr';
        wp_mkdir_p($baseDir);
        $files = [
            'last_run' => 'french-content-last-run.json',
            'actions' => 'french-content-actions.csv',
            'runner_errors' => 'french-content-errors.csv',
            'full' => 'french-content-migration-full.csv',
            'errors' => 'french-content-migration-errors.csv',
            'stale_fixed' => 'french-content-migration-stale-fixed.csv',
            'already_current' => 'french-content-migration-already-current.csv',
        ];
        $reports = [];
        foreach ($files as $key => $file) {
            $path = trailingslashit($baseDir) . $file;
            $reports[$key] = ['path' => $path, 'url' => trailingslashit($baseUrl) . $file];
        }
        return $reports;
    }

    private function initialize_french_content_migration_reports(array $state): void
    {
        foreach ((array) ($state['reports'] ?? []) as $report) {
            $path = is_array($report) ? (string) ($report['path'] ?? '') : '';
            if ($path !== '') {
                @unlink($path);
            }
        }
    }

    private function append_french_content_migration_reports(array $state, array $rows): array
    {
        $reports = (array) ($state['reports'] ?? $this->french_content_migration_report_paths());
        $headers = ['product_id','sku','product_title','old_schema_version','new_schema_version','old_template_version','new_template_version','stale_before','stale_after','stale_reasons','regenerated','already_current_schema','source_description_field','source_description_used','error_message'];
        $sets = [
            'actions' => $rows,
            'runner_errors' => array_values(array_filter($rows, static fn(array $row): bool => trim((string) ($row['error_message'] ?? '')) !== '')),
            'full' => $rows,
            'errors' => array_values(array_filter($rows, static fn(array $row): bool => trim((string) ($row['error_message'] ?? '')) !== '')),
            'stale_fixed' => array_values(array_filter($rows, static fn(array $row): bool => ($row['stale_before'] ?? '') === 'yes' && ($row['stale_after'] ?? '') === 'no')),
            'already_current' => array_values(array_filter($rows, static fn(array $row): bool => ($row['already_current_schema'] ?? '') === 'yes')),
        ];
        $lastRunPath = (string) ($reports['last_run']['path'] ?? '');
        if ($lastRunPath !== '') {
            file_put_contents($lastRunPath, wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        foreach ($sets as $key => $setRows) {
            $path = (string) ($reports[$key]['path'] ?? '');
            if ($path === '') {
                continue;
            }
            $exists = file_exists($path) && filesize($path) > 0;
            $fh = fopen($path, 'ab');
            if (!$fh) {
                continue;
            }
            if (!$exists) {
                fputcsv($fh, $headers);
            }
            foreach ($setRows as $row) {
                fputcsv($fh, array_map(static fn(string $header): string => (string) ($row[$header] ?? ''), $headers));
            }
            fclose($fh);
        }
        return $reports;
    }

    public function french_content_schema_diagnostic(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_french_content_schema_diagnostic');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->french_content_schema_status_for_identifier($input);
        $this->set_status('French content schema status diagnostic: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        echo '<div class="wrap"><h1>French content schema status</h1>';
        echo '<p>This updates local French content only. It does not update active eBay listings.</p>';
        echo '<pre style="white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=woo-ebay-fr')) . '">Back to Woo eBay Integration FR</a></p></div>';
        exit;
    }

    private function french_content_preview_url(int $productId): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=wei_fr_description_template_preview&product_or_sku=' . rawurlencode((string) $productId)),
            'wei_fr_description_template_preview'
        );
    }

    private function french_content_batch_product_ids(int $batchSize, string $mode, int $cursor = 0, bool $includeExcluded = false): array
    {
        global $wpdb;
        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
                AND (
                    %d = 1
                    OR NOT EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta} excluded_meta
                        WHERE excluded_meta.post_id = p.ID
                            AND excluded_meta.meta_key = '_wei_fr_ebay_export_status'
                            AND excluded_meta.meta_value = 'excluded_from_ebay'
                    )
                )
                AND EXISTS (
                    SELECT 1 FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
                    INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                    WHERE tr.object_id = p.ID
                        AND t.name <> 'Bez kategorii'
                        AND t.slug <> 'bez-kategorii'
                        AND t.slug <> 'uncategorized'
                )
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $includeExcluded ? 1 : 0, $batchSize))));
    }

    public function description_template_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_fr_description_template_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->update_description_template_single($input);
        $res['controlled_single_listing_action'] = true;
        $res['called_publish_offer'] = false;
        $res['created_ebay_listing'] = false;
        $this->set_status('Controlled eBay.fr single listing description revise: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    private function product_category_path(int $productId): string
    {
        $terms = get_the_terms($productId, 'product_cat');
        if (!is_array($terms) || $terms === []) { return ''; }
        $first = reset($terms);
        return is_object($first) && isset($first->name) ? (string) $first->name : '';
    }

    private function suggest_used_title(string $title, array $aspects): string
    {
        $title = preg_replace('/NEU!+/iu', '', $title) ?: $title;
        $title = preg_replace('/\b(FOTELE|KLAPA|KOM)\b/iu', '', $title) ?: $title;
        $title = trim(preg_replace('/\s{2,}/', ' ', $title) ?: $title);
        $oe = '';
        foreach (['OE/OEM Referenznummer','Herstellernummer'] as $k) { if (!empty($aspects[$k])) { $v = is_array($aspects[$k]) ? (string) reset($aspects[$k]) : (string)$aspects[$k]; $oe = trim($v); if ($oe !== '') break; } }
        if ($oe !== '' && !str_contains($title,$oe)) $title .= ' ' . $oe;
        if (!preg_match('/\bgebraucht\b/iu', $title)) $title .= ' gebraucht';
        return mb_substr(trim($title),0,80);
    }

    private function category_dashboard_summary_for_report(string $marketplaceId): array
    {
        $categoryTeachingImportSummary = get_option('wei_fr_ebay_category_mapping_import_summary', []);
        $categoryTeachingImportSummary = is_array($categoryTeachingImportSummary) ? $categoryTeachingImportSummary : [];
        $categoryValidationStatuses = get_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, []);
        $categoryValidationStatuses = is_array($categoryValidationStatuses) ? $categoryValidationStatuses : [];

        return $this->categoryRepo->production_mapping_summary(
            $marketplaceId,
            $categoryTeachingImportSummary,
            $categoryValidationStatuses,
            $this->light_readiness_summary()
        );
    }

    private function blocked_category_report_upload_dir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
    }

    private function go_category_mapping_screen(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&wei_fr_section=category-mappings'));
        exit;
    }

    private function go(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr'));
        exit;
    }

    private function publish_action_batch_size_from_post(string $field, int $default): int
    {
        $batchSize = absint($_POST[$field] ?? $default);
        if ($batchSize < self::PUBLISH_ACTION_MIN_BATCH_SIZE) {
            return self::PUBLISH_ACTION_MIN_BATCH_SIZE;
        }
        if ($batchSize > self::PUBLISH_ACTION_MAX_BATCH_SIZE) {
            $this->set_status('Maximum batch size is 300.');
            $this->go();
        }
        return $batchSize;
    }

    private function set_status(string $message): void
    {
        update_option('wei_fr_last_status', ['message' => $message, 'at' => gmdate('Y-m-d H:i:s')], false);
        $logs = get_option('wei_fr_logs', []);
        array_unshift($logs, ['at' => gmdate('Y-m-d H:i:s'), 'message' => $message]);
        update_option('wei_fr_logs', array_slice($logs, 0, 100), false);
    }

    private function admin_report_download_url(string $path): string
    {
        $file = basename($path);
        return $file !== '' ? admin_url('admin-post.php?action=download_wei_fr_report&file=' . rawurlencode($file)) : '';
    }

    private function last_category_readiness_audit_path(string $key): string
    {
        $audit = get_option('wei_fr_ebay_last_category_readiness_audit', []);
        $audit = is_array($audit) ? $audit : [];
        $pathKey = $key === 'full_audit_csv' ? 'full_report_csv_path' : 'problems_only_csv_path';
        $path = trim((string) ($audit[$pathKey] ?? ''));
        return $path !== '' && is_readable($path) ? $path : '';
    }

    private function latest_audit_report_path(string $key): string
    {
        $lastPath = $this->last_category_readiness_audit_path($key);
        if ($lastPath !== '') {
            return $lastPath;
        }
        $legacyProblemsPath = trim((string) get_option('wei_fr_ebay_last_problems_only_csv_path', ''));
        if ($key === 'problems_only_csv' && $legacyProblemsPath !== '' && is_readable($legacyProblemsPath)) {
            return $legacyProblemsPath;
        }
        $summary = get_option('wei_fr_ebay_full_category_audit_summary', []);
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
            'products_with_wei_fr_ebay_sku' => null,
            'products_missing_wei_fr_ebay_sku' => null,
            'generated_in_last_run' => (int) ($lastTotals['generated'] ?? 0),
            'skipped_existing_in_last_run' => (int) ($lastTotals['skipped_existing'] ?? 0),
            'conflicts_in_last_run' => (int) ($lastTotals['conflicts'] ?? 0),
            'errors_in_last_run' => (int) ($lastTotals['errors'] ?? 0),
        ];
    }

    private function cached_nbp_rate_status(): array
    {
        $cached = get_transient('wei_fr_nbp_eur_rate');
        if (is_array($cached) && (float) ($cached['nbp_rate'] ?? 0) > 0) {
            $cached['ready'] = true;
            $cached['from_transient'] = true;
        } else {
            $cached = get_option('wei_fr_nbp_eur_rate_last', []);
            $cached = is_array($cached) ? $cached : [];
            if ((float) ($cached['nbp_rate'] ?? 0) > 0) {
                $cached['ready'] = true;
                $cached['from_last_saved'] = true;
            }
        }

        $fetchedAt = (int) ($cached['fetched_at'] ?? 0);
        $merged = array_merge([
            'ready' => false,
            'currency_source' => 'nbp_table_a',
            'nbp_rate' => null,
            'nbp_effective_date' => '',
            'nbp_table_no' => '',
            'fetched_at' => 0,
        ], $cached);
        $age = $fetchedAt > 0 ? max(0, time() - $fetchedAt) : null;
        $cacheStatus = !empty($cached['from_transient']) ? 'fresh' : (!empty($cached['from_last_saved']) ? 'last_saved' : (!empty($cached['ready']) ? 'cached' : 'missing'));
        $rateStatus = empty($merged['ready']) ? (!empty($merged['error']) ? 'fetch_error' : 'missing') : (!empty($merged['fetch_error']) ? 'stale' : 'available');

        return array_merge($merged, [
            'cache_age_seconds' => $age,
            'cache_status' => $cacheStatus,
            'nbp_eur_rate_status' => $rateStatus,
            'nbp_eur_rate_value' => $merged['nbp_rate'],
            'nbp_eur_rate_date' => (string) ($merged['nbp_effective_date'] ?? ''),
            'nbp_eur_rate_source' => (string) ($merged['currency_source'] ?? 'nbp_table_a'),
            'nbp_eur_rate_cached_at' => $fetchedAt > 0 ? gmdate('c', $fetchedAt) : '',
            'nbp_eur_rate_fetch_error' => (string) ($merged['fetch_error'] ?? $merged['error'] ?? ''),
        ]);
    }

    private function light_auto_sync_status(array $settings): array
    {
        $frequency = (string) ($settings['auto_sync_frequency'] ?? 'hourly');
        $asReady = function_exists('as_next_scheduled_action') && did_action('action_scheduler_init');
        $next = $asReady ? as_next_scheduled_action(AutoSyncScheduler::HOOK_DELTA_SYNC, [], AutoSyncScheduler::CRON_GROUP) : false;
        if (!$next) {
            $next = wp_next_scheduled(AutoSyncScheduler::HOOK_DELTA_SYNC);
        }

        return [
            'status' => (string) get_option('wei_fr_ebay_global_status', 'disabled'),
            'mode' => (string) ($settings['auto_sync_mode'] ?? 'disabled'),
            'frequency' => $frequency,
            'batch_size' => (int) ($settings['auto_sync_export_batch_size'] ?? 20),
            'preflight_batch_size' => (int) ($settings['auto_sync_preflight_batch_size'] ?? 200),
            'last_run' => (string) get_option('wei_fr_ebay_last_run_at', ''),
            'next_run' => $next ? gmdate('Y-m-d H:i:s', (int) $next) : '-',
            'last_summary' => $this->summarize_option_array('wei_fr_ebay_last_run_summary'),
            'pending_stock_sync' => $this->light_pending_stock_count(),
            'queued_products_count' => AutoSyncScheduler::queue_count('pending'),
            'failed_queue_count' => AutoSyncScheduler::queue_count('failed'),
            'checkpoint' => $this->summarize_option_array('wei_fr_ebay_sync_checkpoints'),
            'queue_summary' => $this->summarize_option_array('wei_fr_ebay_queue_summary'),
            'hook' => AutoSyncScheduler::HOOK_DELTA_SYNC,
            'woo_to_ebay_stock_sync_enabled' => !empty($settings['woo_to_ebay_stock_sync_enabled']),
            'ebay_stock_sync_mode' => (string) ($settings['ebay_stock_sync_mode'] ?? 'max_one'),
            'ebay_order_sync_enabled' => !empty($settings['ebay_order_sync_enabled']),
            'account_restriction_status' => (string) get_option('wei_fr_ebay_account_restriction_status', ''),
            'readiness_summary' => $this->light_readiness_summary(),
            'export_summary' => $this->summarize_option_array('wei_fr_ebay_export_summary'),
            'stock_summary' => $this->summarize_option_array('wei_fr_ebay_stock_sync_summary'),
        ];
    }

    private function light_pending_stock_count(): string
    {
        $summary = get_option('wei_fr_ebay_stock_sync_summary', []);
        if (is_array($summary) && isset($summary['pending_stock_sync'])) {
            return (string) (int) $summary['pending_stock_sync'];
        }

        return 'not loaded';
    }

    private function light_readiness_summary(): array
    {
        $summary = get_option('wei_fr_ebay_readiness_summary', []);
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
                ['key' => '_wei_fr_ebay_sku', 'compare' => 'EXISTS'],
                ['key' => '_wei_fr_ebay_offer_id', 'compare' => 'EXISTS'],
                ['key' => '_wei_fr_ebay_export_status', 'compare' => 'EXISTS'],
                ['key' => '_wei_fr_ebay_item_id', 'compare' => 'EXISTS'],
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
                'sku' => (string) get_post_meta($productId, '_wei_fr_ebay_sku', true),
                'inventory_id' => (string) get_post_meta($productId, '_wei_fr_ebay_inventory_id', true),
                'offer_id' => (string) get_post_meta($productId, '_wei_fr_ebay_offer_id', true),
                'listing_id' => (string) get_post_meta($productId, '_wei_fr_ebay_listing_id', true) ?: (string) get_post_meta($productId, '_wei_fr_ebay_item_id', true),
                'public_url' => (string) get_post_meta($productId, '_wei_fr_ebay_public_url', true),
                'last_export_at' => (string) get_post_meta($productId, '_wei_fr_ebay_last_export_at', true),
                'last_publish_at' => (string) get_post_meta($productId, '_wei_fr_ebay_last_publish_at', true),
                'last_sync_status' => (string) get_post_meta($productId, '_wei_fr_ebay_last_sync_status', true) ?: (string) get_post_meta($productId, '_wei_fr_ebay_export_status', true),
                'last_sync_error' => (string) get_post_meta($productId, '_wei_fr_ebay_last_sync_error', true) ?: (string) get_post_meta($productId, '_wei_fr_ebay_last_preflight_error', true),
                'listing_status' => (string) get_post_meta($productId, '_wei_fr_ebay_listing_status', true),
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $current */
    private function translation_provider_settings(array $current = []): array
    {
        $stored = get_option(Plugin::TRANSLATION_OPTION_KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $provider = strtolower(trim((string) ($stored['translation_provider'] ?? $current['translation_provider'] ?? 'disabled')));
        if ($provider === 'google') {
            $provider = 'google_cloud_translate';
        }
        if (!in_array($provider, ['disabled', 'google_cloud_translate'], true)) {
            $provider = 'disabled';
        }

        $apiKey = trim((string) ($stored['translation_api_key'] ?? $current['translation_api_key'] ?? ''));
        $sourceLanguage = strtolower(trim((string) ($stored['translation_source_language'] ?? $current['translation_source_language'] ?? 'pl')));
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $sourceLanguage)) {
            $sourceLanguage = 'pl';
        }

        return [
            'translation_provider' => $provider,
            'translation_api_key' => $apiKey,
            'translation_source_language' => $sourceLanguage,
            'translation_target_language' => 'fr',
            'google_credentials_source' => $apiKey !== '' ? 'fr_admin_setting' : 'missing',
            'google_provider_configured' => $provider === 'google_cloud_translate' && $apiKey !== '' ? 'yes' : 'no',
        ];
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $current */
    private function save_translation_provider_settings(array $post, array $current): array
    {
        $existing = $this->translation_provider_settings($current);
        $enabled = !empty($post['enable_google_cloud_translate']) || (string) ($post['translation_provider'] ?? '') === 'google_cloud_translate' || (string) ($post['translation_provider'] ?? '') === 'google';
        $provider = $enabled ? 'google_cloud_translate' : 'disabled';
        $postedApiKey = trim(sanitize_text_field((string) ($post['translation_api_key'] ?? '')));
        $apiKey = $postedApiKey !== '' ? $postedApiKey : (string) ($existing['translation_api_key'] ?? '');
        $sourceLanguage = strtolower(trim(sanitize_text_field((string) ($post['translation_source_language'] ?? $post['source_language'] ?? ($existing['translation_source_language'] ?? 'pl')))));
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $sourceLanguage)) {
            $sourceLanguage = 'pl';
        }

        $settings = [
            'translation_provider' => $provider,
            'translation_api_key' => $apiKey,
            'translation_source_language' => $sourceLanguage,
            'translation_target_language' => 'fr',
        ];
        update_option(Plugin::TRANSLATION_OPTION_KEY, $settings, false);

        return $this->translation_provider_settings($settings);
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        $s = is_array($s) ? $s : [];
        $s = array_merge($s, $this->translation_provider_settings($s));
        if (empty($s['marketplace_id'])) {
            $s['marketplace_id'] = 'EBAY_FR';
        }
        if (!isset($s['inventory_location_key'])) {
            $s['inventory_location_key'] = 'gpswiss-pl';
        }
        if (!isset($s['ebay_payment_policy_id'])) {
            $s['ebay_payment_policy_id'] = '';
        }
        if (!isset($s['ebay_return_policy_id'])) {
            $s['ebay_return_policy_id'] = '';
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
        if (!isset($s['translation_source_language'])) {
            $s['translation_source_language'] = 'pl';
        }
        $s['translation_target_language'] = 'fr';
        if (!isset($s['auto_generate_french_content_preflight'])) {
            $s['auto_generate_french_content_preflight'] = 1;
        }
        if (!isset($s['enable_ebay_fr_description_template'])) {
            $s['enable_ebay_fr_description_template'] = 0;
        }
        if (!isset($s['ebay_fr_delivery_map_url'])) {
            $s['ebay_fr_delivery_map_url'] = '';
        }
        if (!isset($s['ebay_seller_username']) || trim((string) $s['ebay_seller_username']) === '') {
            $s['ebay_seller_username'] = Plugin::DEFAULT_EBAY_SELLER_USERNAME;
        } else {
            $s['ebay_seller_username'] = trim((string) $s['ebay_seller_username']);
        }
        if (!isset($s['verbose_debug'])) {
            $s['verbose_debug'] = 0;
        }
        if (!isset($s['auto_category_confidence_threshold'])) {
            $s['auto_category_confidence_threshold'] = CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        }
        if (!isset($s['regenerate_french_content_on_hash_change'])) {
            $s['regenerate_french_content_on_hash_change'] = 0;
        }
        if (!isset($s['inventory_location_address_line_1'])) {
            $s['inventory_location_address_line_1'] = '';
        }
        $s['redirect_uri'] = $this->normalize_fr_callback_url((string) ($s['redirect_uri'] ?? ''));
        if (trim((string) ($s['runame'] ?? '')) === '') {
            $s['runame'] = self::SHARED_EBAY_RUNAME;
        }
        $s['wei_fr_cached_policies'] = $this->normalize_fr_cached_policies($s['wei_fr_cached_policies'] ?? []);
        if (!isset($s['sku_category_overrides'])) {
            $s['sku_category_overrides'] = "CFM-001=179847";
        }
        if (!isset($s['product_category_overrides'])) {
            $s['product_category_overrides'] = '';
        }
        if (!isset($s['sku_aspect_overrides'])) {
            $s['sku_aspect_overrides'] = wp_json_encode([
                'CFM-001' => [
                    'Fabricant' => ['SEAT'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        if (!isset($s['category_aspect_fallbacks'])) {
            $s['category_aspect_fallbacks'] = "179847|Fabricant|SEAT";
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
        if (!isset($s['stock_sync_enabled'])) {
            $s['stock_sync_enabled'] = 0;
        }
        if (!isset($s['stock_sync_woo_to_ebay_enabled'])) {
            $s['stock_sync_woo_to_ebay_enabled'] = 0;
        }
        if (!isset($s['stock_sync_ebay_to_woo_enabled'])) {
            $s['stock_sync_ebay_to_woo_enabled'] = 0;
        }
        if (!isset($s['stock_sync_dry_run'])) {
            $s['stock_sync_dry_run'] = 1;
        }
        if (!isset($s['stock_sync_cron_interval'])) {
            $s['stock_sync_cron_interval'] = 'every_15_minutes';
        }
        if (!isset($s['stock_sync_safety_limit'])) {
            $s['stock_sync_safety_limit'] = 50;
        } else {
            $s['stock_sync_safety_limit'] = max(1, min(500, (int) $s['stock_sync_safety_limit']));
        }
        if (!isset($s['stock_sync_woo_zero_action'])) {
            $s['stock_sync_woo_zero_action'] = 'end_listing';
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
            $s['woo_to_ebay_stock_sync_enabled'] = 0;
        }
        if (!isset($s['ebay_order_sync_enabled'])) {
            $s['ebay_order_sync_enabled'] = 0;
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
        $s = $this->with_shipping_policy_defaults($s);
        return $s;
    }



    private function normalize_fr_callback_url(string $callbackUrl): string
    {
        $callbackUrl = trim($callbackUrl);
        if ($callbackUrl === '' || $this->is_wordpress_callback_url($callbackUrl)) {
            return admin_url('admin.php?page=' . EbayAuth::CALLBACK_PAGE_SLUG);
        }

        return $callbackUrl;
    }

    private function is_wordpress_callback_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && strpos($url, '/wp-admin/admin.php') !== false
            && strpos($url, 'page=') !== false;
    }

    private function normalize_fr_cached_policies($cached): array
    {
        if (!is_array($cached)) {
            return [];
        }

        $marketplaceId = (string) ($cached['marketplace_id'] ?? '');
        if ($marketplaceId !== '' && $marketplaceId !== 'EBAY_FR') {
            return [];
        }

        if ($marketplaceId === '') {
            $cached['marketplace_id'] = 'EBAY_FR';
        }

        return $cached;
    }

    private function with_shipping_policy_defaults(array $s): array
    {
        $legacyFulfillmentId = trim((string) ($s['ebay_fulfillment_policy_id'] ?? ''));
        $s['shipping_policy_30'] = trim((string) ($s['shipping_policy_30'] ?? $s['fulfillment_policy_id_30_eur'] ?? $legacyFulfillmentId));
        $s['shipping_policy_50'] = trim((string) ($s['shipping_policy_50'] ?? $s['fulfillment_policy_id_50_eur'] ?? ''));
        $s['shipping_policy_130'] = trim((string) ($s['shipping_policy_130'] ?? $s['fulfillment_policy_id_130_eur'] ?? ''));
        $s['fulfillment_policy_id_30_eur'] = $s['shipping_policy_30'];
        $s['fulfillment_policy_id_50_eur'] = $s['shipping_policy_50'];
        $s['fulfillment_policy_id_130_eur'] = $s['shipping_policy_130'];
        $s['default_shipping_policy_id'] = trim((string) ($s['default_shipping_policy_id'] ?? $s['default_shipping_policy'] ?? ''));
        $s['default_shipping_policy'] = $s['default_shipping_policy_id'];
        $s['shipping_policy_name_30'] = trim((string) ($s['shipping_policy_name_30'] ?? $s['shipping_policy_30_name'] ?? ''));
        $s['shipping_policy_30_name'] = $s['shipping_policy_name_30'];
        if (empty($s['ebay_fulfillment_policy_id'])) {
            $s['ebay_fulfillment_policy_id'] = (string) $s['shipping_policy_30'];
        }
        foreach (['shipping_category_ids_30', 'shipping_category_ids_50', 'shipping_category_ids_130', 'default_shipping_policy_id', 'default_shipping_policy', 'default_shipping_policy_name', 'shipping_policy_name_30', 'shipping_policy_30_name', 'shipping_policy_name_50', 'shipping_policy_name_130', 'ebay_payment_policy_id', 'ebay_payment_policy_name', 'ebay_return_policy_id', 'ebay_return_policy_name'] as $key) {
            if (!isset($s[$key])) {
                $s[$key] = '';
            }
        }
        return $s;
    }

    private function cached_business_policy_name(array $settings, string $policySetKey, string $policyIdKey, string $policyId): string
    {
        $policyId = trim($policyId);
        if ($policyId === '') {
            return '';
        }

        $cached = is_array($settings['wei_fr_cached_policies'] ?? null) ? $settings['wei_fr_cached_policies'] : [];
        $policies = is_array($cached[$policySetKey] ?? null) ? $cached[$policySetKey] : [];
        foreach ($policies as $policy) {
            if ((string) ($policy[$policyIdKey] ?? '') === $policyId) {
                return (string) ($policy['name'] ?? $policyId);
            }
        }

        return '';
    }

    private function cached_fulfillment_policy_name(array $settings, string $policyId): string
    {
        $policyId = trim($policyId);
        if ($policyId === '') {
            return '';
        }

        $cached = is_array($settings['wei_fr_cached_policies'] ?? null) ? $settings['wei_fr_cached_policies'] : [];
        $policies = is_array($cached['fulfillmentPolicies'] ?? null) ? $cached['fulfillmentPolicies'] : [];
        foreach ($policies as $policy) {
            if ((string) ($policy['fulfillmentPolicyId'] ?? '') === $policyId) {
                return (string) ($policy['name'] ?? '');
            }
        }

        return '';
    }

    private function sync_product_category_overrides(string $raw): int
    {
        $synced = 0;
        foreach ($this->parse_product_category_overrides($raw) as $productId => $categoryId) {
            if (get_post_type($productId) !== 'product') {
                continue;
            }

            update_post_meta($productId, '_wei_fr_ebay_category_id', $categoryId);
            update_post_meta($productId, '_wei_fr_ebay_category_source', 'manual_product_override');
            update_post_meta($productId, '_wei_fr_ebay_category_name', $this->static_category_name($categoryId));
            update_post_meta($productId, '_wei_fr_ebay_category_path', $this->static_category_path($categoryId));
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
