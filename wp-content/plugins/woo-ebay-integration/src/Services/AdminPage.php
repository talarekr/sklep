<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\EbayShippingPolicyResolver;

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
        add_action('admin_post_wei_generate_shipping_mapping_report', [$this, 'generate_shipping_mapping_report']);
        add_action('admin_post_wei_generate_listing_quality_audit', [$this, 'generate_listing_quality_audit']);
        add_action('admin_post_wei_condition_cleanup_single', [$this, 'condition_cleanup_single']);
        add_action('admin_post_wei_basic_specifics_single', [$this, 'basic_specifics_single']);
        add_action('admin_post_wei_description_condition_cleanup_single', [$this, 'description_condition_cleanup_single']);
        add_action('admin_post_wei_description_template_preview', [$this, 'description_template_preview']);
        add_action('admin_post_wei_description_template_publish_dry_run', [$this, 'description_template_publish_dry_run']);
        add_action('admin_post_wei_description_template_single', [$this, 'description_template_single']);
        add_action('admin_post_wei_ebay_regenerate_german_content', [$this, 'regenerate_german_content']);
        add_action('admin_post_wei_generate_german_content_batch', [$this, 'generate_german_content_batch']);
        add_action('admin_post_wei_update_shipping_policy_one', [$this, 'update_shipping_policy_one']);
        add_action('admin_post_wei_shipping_policy_bulk_start', [$this, 'shipping_policy_bulk_start']);
        add_action('admin_post_wei_shipping_policy_bulk_pause', [$this, 'shipping_policy_bulk_pause']);
        add_action('admin_post_wei_shipping_policy_bulk_resume', [$this, 'shipping_policy_bulk_resume']);
        add_action('admin_post_wei_shipping_policy_bulk_stop', [$this, 'shipping_policy_bulk_stop']);
        add_action('admin_post_wei_shipping_policy_bulk_process', [$this, 'shipping_policy_bulk_process']);
        add_action('admin_post_wei_basic_specifics_bulk_start', [$this, 'basic_specifics_bulk_start']);
        add_action('admin_post_wei_basic_specifics_bulk_pause', [$this, 'basic_specifics_bulk_pause']);
        add_action('admin_post_wei_basic_specifics_bulk_resume', [$this, 'basic_specifics_bulk_resume']);
        add_action('admin_post_wei_basic_specifics_bulk_stop', [$this, 'basic_specifics_bulk_stop']);
        add_action('admin_post_wei_basic_specifics_bulk_process', [$this, 'basic_specifics_bulk_process']);
        add_action('admin_post_wei_preflight_product', [$this, 'preflight_product']);
        add_action('admin_post_wei_publish_product_offer_only', [$this, 'publish_product_offer_only']);
        add_action('admin_post_wei_inspect_offer_before_publish', [$this, 'inspect_offer_before_publish']);
        add_action('admin_post_wei_verify_api_publishing_readiness', [$this, 'verify_api_publishing_readiness']);
        add_action('admin_post_wei_save_category_mapping', [$this, 'save_category_mapping']);
        add_action('admin_post_wei_auto_map_categories', [$this, 'auto_map_categories']);
        add_action('admin_post_wei_generate_ebay_de_category_suggestions', [$this, 'generate_ebay_de_category_suggestions']);
        add_action('admin_post_wei_generate_all_ebay_de_category_suggestions', [$this, 'generate_all_ebay_de_category_suggestions']);
        add_action('admin_post_wei_reset_ebay_de_category_suggestions_progress', [$this, 'reset_ebay_de_category_suggestions_progress']);
        add_action('admin_post_wei_repair_blocked_category_mappings', [$this, 'repair_blocked_category_mappings']);
        add_action('admin_post_wei_generate_blocked_category_fix_report', [$this, 'generate_blocked_category_fix_report']);
        add_action('admin_post_download_wei_report', [$this, 'download_wei_report']);
        add_action('admin_post_wei_repair_audit_category_groups', [$this, 'repair_audit_category_groups']);
        add_action('admin_post_wei_apply_manual_woo_category_mappings', [$this, 'apply_manual_woo_category_mappings']);
        add_action('admin_post_wei_export_category_teaching_csv', [$this, 'export_category_teaching_csv']);
        add_action('admin_post_wei_export_category_template_csv', [$this, 'export_category_template_csv']);
        add_action('admin_post_wei_export_ovoko_category_suggestions_csv', [$this, 'export_ovoko_category_suggestions_csv']);
        add_action('admin_post_wei_import_category_teaching_csv', [$this, 'import_category_teaching_csv']);
        add_action('admin_post_wei_test_category_teaching_rule_match', [$this, 'test_category_teaching_rule_match']);
        add_action('admin_post_wei_generate_missing_german_content_audit', [$this, 'generate_missing_german_content_audit']);
        add_action('admin_post_wei_generate_ebay_skus', [$this, 'generate_ebay_skus']);
        add_action('admin_post_wei_auto_sync_readiness_now', [$this, 'auto_sync_readiness_now']);
        add_action('admin_post_wei_full_category_audit', [$this, 'full_category_audit']);
        add_action('admin_post_wei_run_category_readiness_audit', [$this, 'run_category_readiness_audit']);
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
        add_action('admin_post_wei_publish_ready_products', [$this, 'publish_ready_products']);
        add_action('admin_post_wei_ebay_rebuild_initial_publish_candidates', [$this, 'ebay_rebuild_initial_publish_candidates']);
        add_action('admin_post_wei_ebay_initial_publish_toggle_pause', [$this, 'ebay_initial_publish_toggle_pause']);
        add_action('admin_post_wei_ebay_initial_publish_reset', [$this, 'ebay_initial_publish_reset']);
        add_action('admin_post_wei_refresh_ebay_listing_state', [$this, 'refresh_ebay_listing_state']);
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
        $this->add_traced_submenu_page('woocommerce', 'eBay OAuth Callback', 'eBay OAuth Callback', 'manage_options', EbayAuth::CALLBACK_PAGE_SLUG, [$this, 'render_oauth_callback'], 'oauth callback menu');
        $this->auth->mark_callback_page_registered();
        remove_submenu_page('woocommerce', EbayAuth::CALLBACK_PAGE_SLUG);
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
        // Callback is handled before render in WEI\Services\EbayAuth::handle_oauth_callback.
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
        $status = get_option('wei_last_status', []);
        $status = is_array($status) ? $status : [];
        $logs = get_option('wei_logs', []);
        $logs = array_slice(is_array($logs) ? $logs : [], 0, 50);
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
        $oauth_diagnostics = $this->auth->get_diagnostic_oauth_context();
        $auto_sync_status = $this->light_auto_sync_status($s);
        $initial_publish_candidate_summary = $this->initial_publish_candidate_summary();
        $initial_publish_status = $this->initial_publish_status();
        $ebay_listing_state_summary = $this->ebay_listing_state_summary();
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
        $category_template_export_summary = get_option('wei_ebay_category_template_export_summary', []);
        $category_template_export_summary = is_array($category_template_export_summary) ? $category_template_export_summary : [];
        $blocked_category_fix_report_summary = get_option('wei_ebay_blocked_category_fix_report_summary', []);
        $blocked_category_fix_report_summary = is_array($blocked_category_fix_report_summary) ? $blocked_category_fix_report_summary : [];
        $ovoko_category_suggestions_summary = get_option('wei_ebay_ovoko_category_suggestions_summary', []);
        $ovoko_category_suggestions_summary = is_array($ovoko_category_suggestions_summary) ? $ovoko_category_suggestions_summary : [];
        $category_teaching_import_summary = get_option('wei_ebay_category_mapping_import_summary', []);
        $category_teaching_import_summary = is_array($category_teaching_import_summary) ? $category_teaching_import_summary : [];
        $category_validation_statuses = get_option(EbayCategorySuggestionReportService::VALIDATION_OPTION, []);
        $category_validation_statuses = is_array($category_validation_statuses) ? $category_validation_statuses : [];
        $category_dashboard_summary = $this->categoryRepo->production_mapping_summary(
            (string) ($s['marketplace_id'] ?? 'EBAY_DE'),
            $category_teaching_import_summary,
            $category_validation_statuses,
            $this->light_readiness_summary()
        );
        $category_teaching_match_diagnostic = get_option('wei_ebay_category_mapping_teaching_match_diagnostic', []);
        $category_teaching_match_diagnostic = is_array($category_teaching_match_diagnostic) ? $category_teaching_match_diagnostic : [];
        $product_sync_status_rows = $load_product_sync_rows ? $this->recent_product_sync_status_rows() : [];
        $shipping_mapping_report = get_option('wei_ebay_shipping_mapping_report', []);
        $shipping_mapping_report = is_array($shipping_mapping_report) ? $shipping_mapping_report : [];
        $listing_quality_audit = get_option('wei_ebay_listing_quality_audit', []);
        $listing_quality_audit = is_array($listing_quality_audit) ? $listing_quality_audit : [];
        $shipping_policy_bulk_status = $this->shipping_policy_bulk_status();
        $basic_specifics_bulk_status = $this->basic_specifics_bulk_status();
        include WEI_PLUGIN_DIR . 'views/admin-page.php';
    }
    public function basic_specifics_bulk_start(): void { $this->require_manage_options(); check_admin_referer('wei_basic_specifics_bulk_start'); $batchSize=max(1,min(25,absint($_POST['batch_size']??1))); $buildLimit=max(1,min(500,absint($_POST['build_limit']??250))); $summary=$this->build_basic_specifics_bulk_queue($batchSize,$buildLimit); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_QUEUE_BUILT',$summary); $this->set_status('Basic specifics bulk queue built: '.wp_json_encode($summary)); $this->go(); }
    public function basic_specifics_bulk_pause(): void { $this->require_manage_options(); check_admin_referer('wei_basic_specifics_bulk_pause'); $status=$this->basic_specifics_bulk_status(); $status['state']='paused'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_PAUSED',$status); $this->go(); }
    public function basic_specifics_bulk_resume(): void { $this->require_manage_options(); check_admin_referer('wei_basic_specifics_bulk_resume'); $status=$this->basic_specifics_bulk_status(); $status['state']='running'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_RESUMED',$status); $this->go(); }
    public function basic_specifics_bulk_stop(): void { $this->require_manage_options(); check_admin_referer('wei_basic_specifics_bulk_stop'); global $wpdb; $table=$wpdb->prefix.'wei_ebay_sync_queue'; $reasons=['basic_item_specifics_bulk_update','basic_item_specifics_update','basic_item_specifics_bulk','legacy_basic_item_specifics_update']; foreach($reasons as $reason){$wpdb->delete($table,['reason'=>$reason]);} $status=$this->default_basic_specifics_bulk_status(); $status['state']='stopped'; $status['checkpoint']='cleared'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_STOPPED',$status); $this->go(); }
    public function basic_specifics_bulk_process(): void { $this->require_manage_options(); check_admin_referer('wei_basic_specifics_bulk_process'); $res=$this->process_basic_specifics_bulk_batch(); $this->set_status('Basic specifics bulk batch: '.wp_json_encode($this->limit_nested_array($res,20))); $this->go(); }
    private function basic_specifics_memory_guard(string $stage, array &$status): bool { $limit=134217728; $hard=(int)($limit*0.85); $usage=(int)memory_get_usage(true); if($usage<$hard){ return false; } $status['checkpoint']='partial'; $status['state']='paused'; $status['last_error']='memory_guard_triggered'; $status['updated_at']=gmdate('Y-m-d H:i:s'); update_option('wei_ebay_basic_specifics_bulk_status',$status,false); $this->logger->error('EBAY_BASIC_SPECIFICS_MEMORY_GUARD',['stage'=>$stage,'memory_usage'=>$usage,'memory_limit'=>$limit]); return true; }
    private function build_basic_specifics_bulk_queue(int $batchSize,int $buildLimit): array { global $wpdb; $queueTable=$wpdb->prefix.'wei_ebay_sync_queue'; $mappingTable=$wpdb->prefix.'marketplace_mappings'; $postmeta=$wpdb->postmeta; $postsTable=$wpdb->posts; $now=gmdate('Y-m-d H:i:s'); $status=$this->default_basic_specifics_bulk_status(); if($this->basic_specifics_memory_guard('build_start',$status)){ return $status+['result'=>'guarded']; } $reasons=['basic_item_specifics_bulk_update','basic_item_specifics_update','basic_item_specifics_bulk','legacy_basic_item_specifics_update']; foreach($reasons as $reason){$wpdb->delete($queueTable,['reason'=>$reason]);}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT DISTINCT m.woo_product_id AS product_id,m.remote_offer_id AS offer_id,m.remote_listing_id AS listing_id,p.post_title AS post_title,sku.meta_value AS sku,pm.meta_key AS meta_key,pm.meta_value AS meta_value FROM {$mappingTable} m LEFT JOIN {$postsTable} p ON p.ID=m.woo_product_id LEFT JOIN {$postmeta} sku ON sku.post_id=m.woo_product_id AND sku.meta_key='_wei_ebay_sku' LEFT JOIN {$postmeta} pm ON pm.post_id=m.woo_product_id AND pm.meta_key IN ('_manufacturer_part_number','_manufacturer','_wei_ebay_mpn','_wei_ebay_manufacturer','_wei_ebay_hersteller','_wei_ebay_herstellernummer','_wei_ebay_oem','_sku') WHERE m.marketplace=%s AND m.status=%s AND m.remote_offer_id<>'' ORDER BY m.woo_product_id ASC LIMIT %d",'ebay','active',$buildLimit*8),ARRAY_A)?:[];
        $grouped=[]; foreach($rows as $r){ $pid=(int)($r['product_id']??0); if($pid<=0){ continue; } if(!isset($grouped[$pid])){ $grouped[$pid]=['product_id'=>$pid,'offer_id'=>(string)($r['offer_id']??''),'listing_id'=>(string)($r['listing_id']??''),'post_title'=>(string)($r['post_title']??''),'sku'=>(string)($r['sku']??''),'meta'=>[]]; } $mk=trim((string)($r['meta_key']??'')); if($mk!==''){ $grouped[$pid]['meta'][$mk][]=trim((string)($r['meta_value']??'')); } }
        $scanned=0; $queued=0; $skipOffer=0; $skipListing=0; $skipSku=0; $skipBasic=0; $skipBrand=0; $skipPart=0; $queuedFromMeta=0; $queuedFromTitle=0; $queuedFromInventory=0; $sampleSkip=[]; $sampleQueued=[];
        foreach(array_slice(array_values($grouped),0,$buildLimit) as $r){ $scanned++; $pid=(int)$r['product_id']; if($pid<=0||trim((string)$r['offer_id'])===''){ $skipOffer++; continue; } if(trim((string)$r['listing_id'])===''){ $skipListing++; continue; } $sku=trim((string)$r['sku']); if($sku===''){ $skipSku++; continue; } $det=$this->light_detect_basic_specifics($pid,(string)$r['post_title'],(array)$r['meta']); if(!$det['has_brand']||!$det['has_part']){ $skipBasic++; if(!$det['has_brand']){ $skipBrand++; } if(!$det['has_part']){ $skipPart++; } if(count($sampleSkip)<10){ $sampleSkip[]=['product_id'=>$pid,'title'=>(string)$r['post_title'],'detected_brand'=>(string)$det['brand'],'detected_part_numbers'=>$det['part_numbers']]; } continue; } $wpdb->insert($queueTable,['product_id'=>$pid,'reason'=>'basic_item_specifics_bulk_update','status'=>'pending','queued_at'=>$now,'updated_at'=>$now,'attempts'=>0,'last_error'=>null,'source'=>'basic_item_specifics_bulk']); $queued++; if($det['source']==='meta')$queuedFromMeta++; elseif($det['source']==='title_parse')$queuedFromTitle++; elseif($det['source']==='inventory_cache')$queuedFromInventory++; if(count($sampleQueued)<10){ $sampleQueued[]=['product_id'=>$pid,'title'=>(string)$r['post_title'],'detected_brand'=>(string)$det['brand'],'detected_part_numbers'=>$det['part_numbers'],'source'=>$det['source']]; } if($this->basic_specifics_memory_guard('build_loop',$status)){ break; }}
        $status=array_merge($status,['state'=>'pending','batch_size'=>$batchSize,'build_limit'=>$buildLimit,'total_queued'=>$queued,'remaining'=>$queued,'started_at'=>$now,'updated_at'=>$now,'checkpoint'=>'built']); update_option('wei_ebay_basic_specifics_bulk_status',$status,false);
        $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_QUEUE_BUILD_START',['candidates_scanned'=>$scanned,'queued'=>$queued,'queued_from_meta'=>$queuedFromMeta,'queued_from_title_parse'=>$queuedFromTitle,'queued_from_inventory_cache'=>$queuedFromInventory,'skipped_no_offer'=>$skipOffer,'skipped_no_listing'=>$skipListing,'skipped_no_sku'=>$skipSku,'skipped_no_basic_data'=>$skipBasic,'skipped_no_brand'=>$skipBrand,'skipped_no_part_number'=>$skipPart,'sample_skipped_no_basic_data'=>$sampleSkip,'sample_queued'=>$sampleQueued,'memory_usage'=>memory_get_usage(true),'elapsed_ms'=>0,'used_light_sql'=>true,'used_content_resolver'=>false,'used_price_resolver'=>false,'used_shipping_resolver'=>false,'used_export_readiness'=>false,'ebay_api_calls'=>false]);
        return $status; }
    private function light_detect_basic_specifics(int $productId,string $title,array $meta): array { $brand=''; $parts=[]; $source=''; $metaKeys=['_manufacturer_part_number','_wei_ebay_mpn','_wei_ebay_herstellernummer','_wei_ebay_oem']; $brandKeys=['_manufacturer','_wei_ebay_manufacturer','_wei_ebay_hersteller']; foreach($brandKeys as $k){ foreach((array)($meta[$k]??[]) as $v){ $v=trim((string)$v); if($v!==''){ $brand=$v; $source='meta'; break 2; } } } foreach($metaKeys as $k){ foreach((array)($meta[$k]??[]) as $v){ $parsed=$this->extract_light_part_numbers((string)$v); if(!empty($parsed)){ $parts=array_merge($parts,$parsed); $source=$source?:'meta'; } } } $inventory=(array)get_post_meta($productId,'_wei_ebay_inventory_snapshot',true); $aspects=is_array($inventory['product']['aspects']??null)?$inventory['product']['aspects']:[]; if($brand==='' && !empty($aspects['Hersteller'][0])){ $brand=(string)$aspects['Hersteller'][0]; $source='inventory_cache'; } if(empty($parts)){ foreach(['MPN','Herstellernummer','Manufacturer Part Number'] as $k){ if(!empty($aspects[$k][0])){ $parts=array_merge($parts,$this->extract_light_part_numbers((string)$aspects[$k][0])); $source='inventory_cache'; } } } if($brand==='' || empty($parts)){ $t=$this->light_parse_title_basic_specifics($title); if($brand==='' && $t['brand']!==''){ $brand=$t['brand']; $source=$source?:'title_parse'; } if(empty($parts) && !empty($t['part_numbers'])){ $parts=$t['part_numbers']; $source=$source?:'title_parse'; } } $parts=array_values(array_unique(array_filter(array_map('trim',$parts)))); return ['has_brand'=>$brand!=='','has_part'=>!empty($parts),'brand'=>$brand,'part_numbers'=>$parts,'source'=>$source?:'meta']; }
    private function light_parse_title_basic_specifics(string $title): array { $brands=['Audi','Volkswagen','VW','Mercedes-Benz','Mercedes','BMW','Seat','Skoda','Škoda','Kia','Hyundai','Toyota','Ford','Opel','Renault','Peugeot','Citroen','Volvo','Land Rover','Range Rover','Porsche','Nissan','Mini','Fiat','Alfa Romeo','Jeep']; $found=''; foreach($brands as $b){ if(preg_match('/\b'.preg_quote($b,'/').'\b/iu',$title)){ $found=$b; break; } } return ['brand'=>$found,'part_numbers'=>$this->extract_light_part_numbers($title)]; }
    private function extract_light_part_numbers(string $text): array { preg_match_all('/\b[A-Z0-9\-]{3,20}\b/i',$text,$m); $out=[]; foreach((array)($m[0]??[]) as $tok){ $t=strtoupper(str_replace('-','',trim((string)$tok))); if(strlen($t)<5) continue; if(!preg_match('/[A-Z]/',$t) || !preg_match('/\d/',$t)) continue; if(preg_match('/^(19\d{2}|20[0-2]\d)$/',$t)) continue; if(preg_match('/^\d{2,4}PS$/',$t)) continue; if(preg_match('/^\d[.,]\d$/',$t)) continue; if(preg_match('/^(CYR|TCB|OCK|DNF|DFH|DXR|CDA|CCZ|BLS)$/',$t)) continue; if(preg_match('/^(8W|8P|8R|B6|B7|B8|W177|X204|F3|80A)$/',$t)) continue; if(preg_match('/^[BW]\d{1,3}$/',$t)) continue; if(preg_match('/^\d+P$/',$t)) continue; $out[]=$t; } usort($out, static fn(string $a,string $b): int => abs(strlen($a)-10) <=> abs(strlen($b)-10)); return array_values(array_unique($out)); }
    private function process_basic_specifics_bulk_batch(): array { global $wpdb; $queueTable=$wpdb->prefix.'wei_ebay_sync_queue'; $status=$this->basic_specifics_bulk_status(); if(($status['state']??'')==='paused') return $status+['result'=>'skipped','reason'=>'paused']; if(!in_array((string)($status['state']??''),['running','pending'],true)) return $status+['result'=>'skipped','reason'=>'not_running']; if($this->basic_specifics_memory_guard('process_start',$status)){ return $status+['result'=>'guarded']; } $status['state']='running'; $requested=max(1,min(25,(int)($status['batch_size']??1))); $rows=$wpdb->get_results($wpdb->prepare("SELECT id,product_id,attempts FROM {$queueTable} WHERE reason=%s AND status=%s ORDER BY id ASC LIMIT %d",'basic_item_specifics_bulk_update','pending',$requested),ARRAY_A)?:[]; $processed=0; $startMem=memory_get_usage(true);
        foreach($rows as $row){ if($processed>=$requested){ break; } if($this->basic_specifics_memory_guard('process_loop',$status)){ break; } $processed++; $queueId=(int)$row['id']; $productId=(int)$row['product_id']; $wpdb->update($queueTable,['status'=>'processing','updated_at'=>gmdate('Y-m-d H:i:s')],['id'=>$queueId]); try{ $elig=$this->adapter->basic_item_specifics_process_one_product_eligibility($productId); if(empty($elig['eligible'])){$status['processed']++;$status['skipped']++;$status['last_product_id']=$productId; $wpdb->update($queueTable,['status'=>'done','updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$queueId]); continue;} $res=$this->adapter->update_basic_item_specifics_single((string)$productId); $status['processed']++; $status['last_product_id']=$productId; if(($res['result']??'')==='success'){ if(!empty($res['changed']))$status['changed']++; else $status['unchanged']++; $wpdb->update($queueTable,['status'=>'done','updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>null],['id'=>$queueId]); } else { $status['failed']++; $status['last_error']=(string)($res['error']??'unknown_error'); $wpdb->update($queueTable,['status'=>'failed','attempts'=>(int)$row['attempts']+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>$status['last_error']],['id'=>$queueId]); } } catch(\Throwable $e){$status['processed']++;$status['failed']++;$status['last_product_id']=$productId;$status['last_error']=$e->getMessage();$wpdb->update($queueTable,['status'=>'failed','attempts'=>(int)$row['attempts']+1,'updated_at'=>gmdate('Y-m-d H:i:s'),'last_error'=>$e->getMessage()],['id'=>$queueId]); } }
        $status=array_merge($status,$this->basic_specifics_bulk_queue_counts()); $status['updated_at']=gmdate('Y-m-d H:i:s'); $status['state']=((int)$status['remaining']<=0)?'done':'running'; update_option('wei_ebay_basic_specifics_bulk_status',$status,false); $this->logger->info('EBAY_BASIC_SPECIFICS_BULK_BATCH_DONE',$status+['requested_batch_size'=>$requested,'actual_processed'=>$processed,'memory_usage_start'=>$startMem,'memory_usage_end'=>memory_get_usage(true),'used_safe_single_method'=>true,'skipped_normal_export_flow'=>true]); return $status+['result'=>'success']; }
    private function basic_specifics_bulk_status(): array { $status=get_option('wei_ebay_basic_specifics_bulk_status',[]); $status=is_array($status)?array_merge($this->default_basic_specifics_bulk_status(),$status):$this->default_basic_specifics_bulk_status(); return array_merge($status,$this->basic_specifics_bulk_queue_counts()); }
    private function basic_specifics_bulk_queue_counts(): array { global $wpdb; $table=$wpdb->prefix.'wei_ebay_sync_queue'; $rows=$wpdb->get_results($wpdb->prepare("SELECT status,COUNT(*) AS count FROM {$table} WHERE reason=%s GROUP BY status",'basic_item_specifics_bulk_update'),ARRAY_A); $counts=['pending'=>0,'processing'=>0,'done'=>0,'failed'=>0]; foreach((array)$rows as $r){$k=(string)($r['status']??''); if(isset($counts[$k])) $counts[$k]=(int)($r['count']??0);} $queued=$counts['pending']+$counts['processing']+$counts['done']+$counts['failed']; return ['total_queued'=>$queued,'remaining'=>$counts['pending']+$counts['processing'],'queue_done'=>$counts['done'],'queue_failed'=>$counts['failed']]; }
    private function default_basic_specifics_bulk_status(): array { return ['state'=>'stopped','batch_size'=>1,'build_limit'=>250,'total_queued'=>0,'processed'=>0,'remaining'=>0,'changed'=>0,'unchanged'=>0,'failed'=>0,'skipped'=>0,'last_product_id'=>0,'last_error'=>'bulk_queue_stopped_by_default_until_isolation_confirmed','started_at'=>'','updated_at'=>'','checkpoint'=>'stopped','reason'=>'basic_item_specifics_bulk_update']; }

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
        $postedCallbackUrl = esc_url_raw((string) ($_POST['redirect_uri'] ?? ''));
        $postedRuname = sanitize_text_field((string) ($_POST['runame'] ?? ''));
        $s['redirect_uri'] = $postedCallbackUrl !== '' ? $postedCallbackUrl : admin_url('admin.php?page=' . EbayAuth::CALLBACK_PAGE_SLUG);
        $s['runame'] = $postedRuname;
        $s['marketplace_id'] = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
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
        $s['enable_ebay_de_description_template'] = !empty($_POST['enable_ebay_de_description_template']) ? 1 : 0;
        $s['ebay_de_delivery_map_url'] = esc_url_raw((string) ($_POST['ebay_de_delivery_map_url'] ?? ''));
        $s['ebay_seller_username'] = sanitize_text_field((string) ($_POST['ebay_seller_username'] ?? ($s['ebay_seller_username'] ?? '')));
        $s['regenerate_german_content_on_hash_change'] = !empty($_POST['regenerate_german_content_on_hash_change']) ? 1 : 0;
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? 'gpswiss-pl'));
        $s['inventory_location_name'] = sanitize_text_field((string) ($_POST['inventory_location_name'] ?? 'gpswiss-pl'));
        $s['inventory_location_country'] = sanitize_text_field((string) ($_POST['inventory_location_country'] ?? 'PL'));
        $s['inventory_location_postal_code'] = sanitize_text_field((string) ($_POST['inventory_location_postal_code'] ?? '08-460'));
        $s['inventory_location_city'] = sanitize_text_field((string) ($_POST['inventory_location_city'] ?? 'Sobolew'));
        $s['inventory_location_address_line_1'] = sanitize_text_field((string) ($_POST['inventory_location_address_line_1'] ?? ''));
        $s['fulfillment_policy_id_30_eur'] = sanitize_text_field((string) ($_POST['fulfillment_policy_id_30_eur'] ?? $_POST['fulfillmentPolicyId'] ?? $_POST['ebay_fulfillment_policy_id'] ?? ''));
        $s['fulfillment_policy_id_50_eur'] = sanitize_text_field((string) ($_POST['fulfillment_policy_id_50_eur'] ?? ''));
        $s['fulfillment_policy_id_100_eur'] = sanitize_text_field((string) ($_POST['fulfillment_policy_id_100_eur'] ?? ''));
        $s['ebay_fulfillment_policy_id'] = $s['fulfillment_policy_id_30_eur'];
        $s['shipping_category_ids_50_eur'] = sanitize_textarea_field((string) ($_POST['shipping_category_ids_50_eur'] ?? ''));
        $s['shipping_category_ids_100_eur'] = sanitize_textarea_field((string) ($_POST['shipping_category_ids_100_eur'] ?? ''));
        $s['ebay_payment_policy_id'] = sanitize_text_field((string) ($_POST['paymentPolicyId'] ?? $_POST['ebay_payment_policy_id'] ?? ''));
        $s['ebay_return_policy_id'] = sanitize_text_field((string) ($_POST['returnPolicyId'] ?? $_POST['ebay_return_policy_id'] ?? ''));
        $this->sync_product_category_overrides($s['product_category_overrides']);
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&saved=1'));
        exit;
    }

    public function generate_shipping_mapping_report(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_shipping_mapping_report');

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
            $categoryIds100 = $categoryGroups[EbayShippingPolicyResolver::GROUP_PALLET_100_EUR] ?? [];
            $allMappedCategoryIds = array_values(array_unique(array_merge($categoryIds50, $categoryIds100)));

            $this->guard_shipping_mapping_report_memory($report, 'after_settings');

            $terms = $this->shipping_mapping_product_category_terms();
            $termSamples = [];
            $unmappedSamples = [];
            foreach ($terms as $term) {
                $termId = (int) ($term['term_id'] ?? 0);
                if ($termId <= 0) {
                    continue;
                }

                $group = 'default_30_eur';
                if (in_array($termId, $categoryIds100, true)) {
                    $group = 'pallet_100_eur';
                } elseif (in_array($termId, $categoryIds50, true)) {
                    $group = 'parcel_50_eur';
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
                if ($group === 'default_30_eur' && count($unmappedSamples) < 100) {
                    $unmappedSamples[] = $sample;
                }
            }

            if (count($terms) > 100) {
                $warnings[] = 'Category details were limited to 100 sample terms to keep the report small.';
            }

            $this->guard_shipping_mapping_report_memory($report, 'after_terms');

            $totalProducts = $this->count_products_for_shipping_mapping();
            $products100 = $this->count_products_for_shipping_mapping($categoryIds100, [], true);
            $products50 = $this->count_products_for_shipping_mapping($categoryIds50, $categoryIds100, true);
            $productsMapped = $this->count_products_for_shipping_mapping($allMappedCategoryIds, [], true);
            $productsDefault30 = max(0, $totalProducts - $productsMapped);
            $estimatedProductsTotal = $products100 + $products50 + $productsDefault30;
            $estimatedProductsDifference = $totalProducts - $estimatedProductsTotal;
            if ($estimatedProductsDifference !== 0) {
                $warnings[] = 'Shipping mapping estimate total differs from total products by ' . $estimatedProductsDifference . '; check overlapping categories or product category assignments.';
            }

            if ($totalProducts > 100) {
                $warnings[] = 'Product-level details were not scanned; report uses lightweight SQL counts only.';
            }

            $report = [
                'generated_at' => gmdate('Y-m-d H:i:s'),
                'category_ids_100' => $categoryIds100,
                'category_ids_50' => $categoryIds50,
                'count_categories_100' => count($categoryIds100),
                'count_categories_50' => count($categoryIds50),
                'estimated_products_100' => $products100,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'total_products' => $totalProducts,
                'estimated_products_total' => $estimatedProductsTotal,
                'estimated_products_difference' => $estimatedProductsDifference,
                'counts' => [
                    '30_eur' => $productsDefault30,
                    '50_eur' => $products50,
                    '100_eur' => $products100,
                    'default_30_eur' => $productsDefault30,
                ],
                'sample_terms' => $termSamples,
                'unmapped_categories' => $unmappedSamples,
                'warnings' => $warnings,
                'mass_update_enabled' => false,
                'partial' => false,
                'note' => 'Raport generowany ręcznie i liczony lekkimi zapytaniami SQL po kategoriach; nie ładuje produktów WooCommerce ani pełnego postmeta. Masowa aktualizacja fulfillment policy pozostaje wyłączona.',
            ];

            $this->guard_shipping_mapping_report_memory($report, 'before_save');
            update_option('wei_ebay_shipping_mapping_report', $report, false);

            $this->logger->info('EBAY_SHIPPING_MAPPING_REPORT_DONE', [
                'total_products' => $totalProducts,
                'estimated_products_100' => $products100,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'sample_terms' => count($termSamples),
                'warnings' => count($warnings),
                'memory_usage' => memory_get_usage(true),
            ]);
            $this->set_status('Shipping mapping report generated: ' . wp_json_encode([
                'total_products' => $totalProducts,
                'estimated_products_100' => $products100,
                'estimated_products_50' => $products50,
                'estimated_products_default_30' => $productsDefault30,
                'warnings' => count($warnings),
            ]));
        } catch (\Throwable $e) {
            $report['partial'] = true;
            $report['warnings'][] = 'Report stopped before completion: ' . $e->getMessage();
            update_option('wei_ebay_shipping_mapping_report', $report, false);
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
        check_admin_referer('wei_update_shipping_policy_one');
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
        check_admin_referer('wei_shipping_policy_bulk_start');
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
        check_admin_referer('wei_shipping_policy_bulk_pause');
        $status = $this->shipping_policy_bulk_status();
        $status['state'] = 'paused';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_ebay_shipping_policy_bulk_status', $status, false);
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_PAUSED', $status);
        $this->set_status('Shipping policy bulk paused: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_resume(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_shipping_policy_bulk_resume');
        $status = $this->shipping_policy_bulk_status();
        $status['state'] = 'running';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_ebay_shipping_policy_bulk_status', $status, false);
        $this->set_status('Shipping policy bulk resumed: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_stop(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_shipping_policy_bulk_stop');
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $wpdb->delete($table, ['reason' => 'fulfillment_policy_update']);
        $status = $this->default_shipping_policy_bulk_status();
        $status['state'] = 'stopped';
        $status['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option('wei_ebay_shipping_policy_bulk_status', $status, false);
        $this->set_status('Shipping policy bulk stopped and queue cleared: ' . wp_json_encode($status));
        $this->go();
    }

    public function shipping_policy_bulk_process(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_shipping_policy_bulk_process');
        $res = $this->process_shipping_policy_bulk_batch();
        $this->set_status('Shipping policy bulk batch: ' . wp_json_encode($this->limit_nested_array($res, 20)));
        $this->go();
    }

    private function build_shipping_policy_bulk_queue(int $batchSize): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_ebay_sync_queue';
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
             LEFT JOIN {$wpdb->postmeta} status_meta ON status_meta.post_id = offer_meta.post_id AND status_meta.meta_key = '_wei_ebay_listing_status'
             WHERE offer_meta.meta_key = '_wei_ebay_offer_id'
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
        update_option('wei_ebay_shipping_policy_bulk_status', $status, false);
        return $status;
    }

    private function process_shipping_policy_bulk_batch(): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_ebay_sync_queue';
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
        update_option('wei_ebay_shipping_policy_bulk_status', $status, false);
        $this->logger->info('EBAY_SHIPPING_POLICY_BULK_BATCH_DONE', $status);
        return $status + ['result' => 'success'];
    }

    private function shipping_policy_bulk_status(): array
    {
        $status = get_option('wei_ebay_shipping_policy_bulk_status', []);
        $status = is_array($status) ? array_merge($this->default_shipping_policy_bulk_status(), $status) : $this->default_shipping_policy_bulk_status();
        return array_merge($status, $this->shipping_policy_bulk_queue_counts());
    }

    private function shipping_policy_bulk_queue_counts(): array
    {
        global $wpdb;
        $queueTable = $wpdb->prefix . 'wei_ebay_sync_queue';
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



    public function generate_ebay_de_category_suggestions(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_ebay_de_category_suggestions');

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

        $this->set_status('eBay.de category suggestions: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_all_ebay_de_category_suggestions(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_all_ebay_de_category_suggestions');

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

        $this->set_status('Generate all eBay.de category suggestions: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function reset_ebay_de_category_suggestions_progress(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_reset_ebay_de_category_suggestions_progress');
        $reporter = new EbayCategorySuggestionReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->reset_progress();
        $this->set_status('Reset eBay.de category suggestions progress: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }


    public function generate_blocked_category_fix_report(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_blocked_category_fix_report');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $categoryDashboardSummary = $this->category_dashboard_summary_for_report($marketplaceId);
        $path = $this->latest_audit_report_path('problems_only_csv');
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
                'category_dashboard_summary' => $categoryDashboardSummary,
            ];
            update_option('wei_ebay_blocked_category_fix_report_summary', $res, false);
            $this->set_status('Blocked category fix report: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
            $this->go();
        }

        $reporter = new BlockedCategoryFixReportService($this->categoryRepo, $this->taxonomy, $this->logger);
        $res = $reporter->generate_from_audit($path, $marketplaceId);
        $res['category_dashboard_summary'] = $categoryDashboardSummary;
        update_option('wei_ebay_blocked_category_fix_report_summary', $res, false);
        $this->set_status('Blocked category fix report: ' . wp_json_encode($res, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    public function download_wei_report(): void
    {
        $this->require_manage_options();
        $file = sanitize_file_name((string) ($_GET['file'] ?? ''));
        $allowed = [
            BlockedCategoryFixReportService::RECOMMENDATIONS_FILENAME,
            BlockedCategoryFixReportService::FIX_IMPORT_FILENAME,
        ];
        if (!in_array($file, $allowed, true)) {
            wp_die('Invalid report file');
        }
        $path = trailingslashit($this->blocked_category_report_upload_dir()) . $file;
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

    public function export_category_template_csv(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_export_category_template_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
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
        check_admin_referer('wei_export_ovoko_category_suggestions_csv');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
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
    public function run_category_readiness_audit(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_run_category_readiness_audit');
        $marketplaceId = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $verboseDebug = !empty($_POST['verbose_debug']);
        $res = [];

        for ($batch = 0; $batch < 100; $batch++) {
            $res = $this->scheduler->run_full_category_audit($verboseDebug);
            if ((string) ($res['result'] ?? '') !== 'in_progress' && (string) ($res['status'] ?? '') !== 'in_progress') {
                break;
            }
        }

        $status = $this->category_readiness_audit_status($res, $marketplaceId);
        update_option('wei_ebay_category_readiness_audit_summary', $status, false);
        $this->set_status('Category readiness audit: ' . wp_json_encode($status, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    private function category_readiness_audit_status(array $res, string $marketplaceId): array
    {
        $reports = (array) ($res['reports'] ?? []);
        $problems = is_array($reports['problems_only_csv'] ?? null) ? $reports['problems_only_csv'] : [];
        $full = is_array($reports['full_audit_csv'] ?? null) ? $reports['full_audit_csv'] : [];
        $problemsPath = (string) ($problems['path'] ?? '');
        $fullPath = (string) ($full['path'] ?? '');
        $problemsExists = $problemsPath !== '' && is_file($problemsPath);
        $fullExists = $fullPath !== '' && is_file($fullPath);
        $processed = (int) ($res['processed'] ?? $res['total_scanned'] ?? 0);
        $ready = (int) ($res['ready_count'] ?? 0);
        $result = (string) ($res['result'] ?? 'error');

        if (!$problemsExists || (int) ($problems['size'] ?? ($problemsExists ? filesize($problemsPath) : 0)) <= 0) {
            $result = 'error';
        }

        return [
            'action' => 'run_category_readiness_audit',
            'result' => $result,
            'processed' => $processed,
            'ready_count' => $ready,
            'not_ready_count' => max(0, $processed - $ready),
            'blocked_by_category_count' => (int) ($res['blocked_by_category_count'] ?? 0),
            'invalid_ebay_category_id_count' => (int) ($res['invalid_ebay_category_id_count'] ?? 0),
            'non_leaf_category_count' => (int) ($res['non_leaf_category_count'] ?? 0),
            'category_sanity_failed_count' => (int) ($res['category_sanity_failed_count'] ?? 0),
            'missing_required_aspects_count' => (int) ($res['missing_required_aspects_count'] ?? 0),
            'content_not_ready_count' => (int) ($res['content_not_ready_count'] ?? 0),
            'price_not_ready_count' => (int) ($res['price_not_ready_count'] ?? 0),
            'problems_only_csv_path' => $problemsPath,
            'problems_only_csv_url' => (string) ($problems['url'] ?? ''),
            'problems_only_csv_exists' => $problemsExists,
            'problems_only_csv_size' => (int) ($problems['size'] ?? ($problemsExists ? filesize($problemsPath) : 0)),
            'full_report_csv_path' => $fullPath,
            'full_report_csv_url' => (string) ($full['url'] ?? ''),
            'full_report_csv_exists' => $fullExists,
            'full_report_csv_size' => (int) ($full['size'] ?? ($fullExists ? filesize($fullPath) : 0)),
            'category_dashboard_summary' => $this->category_dashboard_summary_for_report($marketplaceId),
        ];
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


    public function publish_ready_products(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_publish_ready_products');
        $batchSize = max(1, min(50, absint($_POST['batch_size'] ?? 5)));
        $res = $this->run_initial_publish_batch($batchSize);
        $this->set_status('Publish ready products: ' . wp_json_encode([
            'processed' => (int) ($res['processed'] ?? 0),
            'ready' => (int) $this->initial_publish_total_ready($this->initial_publish_candidate_summary()),
            'exported' => (int) ($res['success'] ?? 0),
            'published' => (int) ($res['success'] ?? 0),
            'skipped_not_ready' => (int) ($res['skipped_not_ready'] ?? 0),
            'blocked_by_category' => (int) ($res['blocked_by_category'] ?? 0),
            'stale_german_content' => (int) ($res['stale_german_content'] ?? 0),
            'missing_required_aspects' => (int) ($res['missing_required_aspects'] ?? 0),
            'missing_image' => (int) ($res['missing_image'] ?? 0),
            'missing_stock' => (int) ($res['missing_stock'] ?? 0),
            'invalid_price' => (int) ($res['invalid_price'] ?? 0),
            'errors' => (int) ($res['failed'] ?? 0),
            'report_url' => (string) ($res['report_url'] ?? ''),
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

    public function refresh_ebay_listing_state(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_refresh_ebay_listing_state');
        $limit = max(1, min(500, absint($_POST['batch_size'] ?? 100)));
        $res = $this->adapter->refresh_listing_state($limit);
        update_option('wei_ebay_listing_state_summary', $res, false);
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
        check_admin_referer('wei_ebay_initial_publish_reset');
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
        delete_option('wei_ebay_initial_publish_last_batch_log');
        delete_option('wei_ebay_initial_publish_candidate_summary');
        delete_option('wei_ebay_initial_publish_last_summary');
    }

    private function ebay_listing_state_summary(): array
    {
        $summary = get_option('wei_ebay_listing_state_summary', []);
        return is_array($summary) ? $summary : [];
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
        $this->reset_publish_progress_state();
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
                AND ready_meta.meta_value IN ('ready', 'needs_reexport')
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
            LEFT JOIN {$wpdb->postmeta} current_active_meta
                ON current_active_meta.post_id = p.ID
                AND current_active_meta.meta_key = '_wei_ebay_current_listing_state'
                AND current_active_meta.meta_value = 'active'
            LEFT JOIN {$wpdb->postmeta} export_published_meta
                ON export_published_meta.post_id = p.ID
                AND export_published_meta.meta_key = '_wei_ebay_export_status'
                AND export_published_meta.meta_value = 'published'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND (listing_status_meta.post_id IS NULL OR current_active_meta.post_id IS NULL)
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
        $skippedTotal = (int) get_option('wei_ebay_initial_publish_skipped', 0);
        $startedAt = gmdate('Y-m-d H:i:s');
        $logs = ['INITIAL_PUBLISH_BATCH_START batch_size=' . $batchSize . ' cursor=' . $cursor];
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
            'stale_german_content' => 0,
            'missing_required_aspects' => 0,
            'missing_image' => 0,
            'missing_stock' => 0,
            'invalid_price' => 0,
        ];

        $ids = $this->initial_publish_candidate_product_ids($batchSize, $cursor);
        foreach ($ids as $productId) {
            $productId = (int) $productId;
            $newCursor = max($newCursor, $productId);
            $processed++;
            $processedTotal++;
            $logs[] = 'INITIAL_PUBLISH_PRODUCT_START product_id=' . $productId;

            if ($this->is_initial_publish_already_published($productId)) {
                $skippedTotal++;
                $logs[] = 'INITIAL_PUBLISH_PRODUCT_SKIPPED product_id=' . $productId . ' reason="already_published"';
                continue;
            }

            try {
                $preflight = $this->adapter->preflight_product($productId);
                if (empty($preflight['ready'])) {
                    $reason = (string) ($preflight['status'] ?? 'not_ready');
                    $skippedTotal++;
                    $this->accumulate_publish_not_ready_reason($skipSummary, $preflight);
                    update_post_meta($productId, '_wei_ebay_last_sync_status', 'not_ready');
                    update_post_meta($productId, '_wei_ebay_last_sync_error', $reason);
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
        update_option('wei_ebay_initial_publish_skipped', $skippedTotal, false);
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
        ] + $skipSummary;
    }

    private function accumulate_publish_not_ready_reason(array &$summary, array $preflight): void
    {
        $summary['skipped_not_ready'] = (int) ($summary['skipped_not_ready'] ?? 0) + 1;
        $status = strtolower((string) ($preflight['status'] ?? ''));
        $message = strtolower((string) ($preflight['message'] ?? '') . ' ' . implode(' ', array_map('strval', (array) ($preflight['errors'] ?? []))));
        if ($status === 'blocked_by_category' || $status === 'missing_category' || str_contains($message, 'category')) {
            $summary['blocked_by_category'] = (int) ($summary['blocked_by_category'] ?? 0) + 1;
        }
        if ($status === 'stale_german_content' || str_contains($message, 'stale') || str_contains($message, 'german')) {
            $summary['stale_german_content'] = (int) ($summary['stale_german_content'] ?? 0) + 1;
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
                AND ready_meta.meta_value IN ('ready', 'needs_reexport')
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
            LEFT JOIN {$postmeta} current_active_meta
                ON current_active_meta.post_id = p.ID
                AND current_active_meta.meta_key = '_wei_ebay_current_listing_state'
                AND current_active_meta.meta_value = 'active'
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
                AND (listing_status_meta.post_id IS NULL OR current_active_meta.post_id IS NULL)
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d
        ";

        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $batchSize))));
    }

    private function is_initial_publish_already_published(int $productId): bool
    {
        return (string) get_post_meta($productId, '_wei_ebay_current_listing_state', true) === 'active'
            && (
                (string) get_post_meta($productId, '_wei_ebay_listing_status', true) === 'published'
                || (string) get_post_meta($productId, '_wei_ebay_export_status', true) === 'published'
            );
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
            'skipped' => (int) get_option('wei_ebay_initial_publish_skipped', 0),
            'last_published_product_id' => (int) get_option('wei_ebay_initial_publish_last_published_product_id', 0),
            'last_listing_id' => (string) get_option('wei_ebay_initial_publish_last_listing_id', ''),
            'candidate_summary' => $candidateSummary,
            'last_batch_log' => (array) get_option('wei_ebay_initial_publish_last_batch_log', []),
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
            'published_total_from_old_checkpoint' => (int) get_option('wei_ebay_initial_publish_success', 0),
            'needs_reexport_count' => (int) ($state['needs_reexport_count'] ?? $this->count_products_with_export_status('needs_reexport')),
            'ended_listing_count' => (int) ($state['ended_listing_count'] ?? 0),
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
            'wei_ebay_initial_publish_skipped',
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


    public function inspect_offer_before_publish(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_inspect_offer_before_publish');
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

    /** @return array<string,mixed> */
    private function empty_shipping_mapping_report(): array
    {
        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'category_ids_100' => [],
            'category_ids_50' => [],
            'count_categories_100' => 0,
            'count_categories_50' => 0,
            'estimated_products_100' => 0,
            'estimated_products_50' => 0,
            'estimated_products_default_30' => 0,
            'total_products' => 0,
            'counts' => ['30_eur' => 0, '50_eur' => 0, '100_eur' => 0, 'default_30_eur' => 0],
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
        update_option('wei_ebay_shipping_mapping_report', $partialReport, false);
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
        check_admin_referer('wei_generate_listing_quality_audit');

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
            $sql = $wpdb->prepare("SELECT p.ID as product_id, p.post_title, sku.meta_value as sku, offer.meta_value as offer_id, listing.meta_value as listing_id, url.meta_value as public_url, cat.meta_value as ebay_category_id, catn.meta_value as ebay_category_name, catp.meta_value as ebay_category_path, pol.meta_value as shipping_policy_id, ship.meta_value as shipping_group, descr.meta_value as de_description, aspects.meta_value as aspects_json, status.meta_value as sync_status FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} sku ON (sku.post_id=p.ID AND sku.meta_key='_sku') LEFT JOIN {$wpdb->postmeta} offer ON (offer.post_id=p.ID AND offer.meta_key='_wei_ebay_offer_id') LEFT JOIN {$wpdb->postmeta} listing ON (listing.post_id=p.ID AND listing.meta_key='_wei_ebay_listing_id') LEFT JOIN {$wpdb->postmeta} url ON (url.post_id=p.ID AND url.meta_key='_wei_ebay_public_url') LEFT JOIN {$wpdb->postmeta} cat ON (cat.post_id=p.ID AND cat.meta_key='_wei_ebay_category_id') LEFT JOIN {$wpdb->postmeta} catn ON (catn.post_id=p.ID AND catn.meta_key='_wei_ebay_category_name') LEFT JOIN {$wpdb->postmeta} catp ON (catp.post_id=p.ID AND catp.meta_key='_wei_ebay_category_path') LEFT JOIN {$wpdb->postmeta} pol ON (pol.post_id=p.ID AND pol.meta_key='_wei_ebay_last_fulfillment_policy_id') LEFT JOIN {$wpdb->postmeta} ship ON (ship.post_id=p.ID AND ship.meta_key='_wei_ebay_last_shipping_group') LEFT JOIN {$wpdb->postmeta} descr ON (descr.post_id=p.ID AND descr.meta_key='_wei_ebay_de_description') LEFT JOIN {$wpdb->postmeta} aspects ON (aspects.post_id=p.ID AND aspects.meta_key='_wei_ebay_aspects_json') LEFT JOIN {$wpdb->postmeta} status ON (status.post_id=p.ID AND status.meta_key='_wei_ebay_last_sync_status') WHERE p.post_type='product' AND p.post_status IN ('publish','draft','private') AND offer.meta_value IS NOT NULL AND offer.meta_value <> '' ORDER BY p.ID ASC LIMIT %d OFFSET %d", $batchSize, $offset);
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
                $skuFallback = trim((string) get_post_meta((int)$r['product_id'], '_wei_ebay_sku', true));
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
        update_option('wei_ebay_listing_quality_audit',$summary,false);
        $this->set_status('Listing quality audit generated: ' . wp_json_encode(['scanned'=>$summary['scanned'],'issues'=>$summary['suspected_wrong_ebay_category']+$summary['missing_fitment']+$summary['missing_description']+$summary['missing_specifics']]));
        $this->go();
    }

    public function condition_cleanup_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_condition_cleanup_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->clean_condition_aspects_single($input);
        $this->set_status('Single condition cleanup: ' . wp_json_encode($res));
        $this->go();
    }

    public function basic_specifics_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_basic_specifics_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->update_basic_item_specifics_single($input);
        $this->set_status('Single basic specifics update: ' . wp_json_encode($res));
        $this->go();
    }

    public function description_condition_cleanup_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_description_condition_cleanup_single');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->clean_description_condition_single($input);
        $this->set_status('Single description condition cleanup: ' . wp_json_encode($res));
        $this->go();
    }

    public function description_template_preview(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_description_template_preview');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->preview_ebay_de_description_template($input);
        $this->render_description_template_preview_response($res);
    }

    public function description_template_publish_dry_run(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_description_template_publish_dry_run');
        $input = sanitize_text_field((string) ($_POST['product_or_sku'] ?? ''));
        $res = $this->adapter->dry_run_ebay_de_publish_description_payload($input);
        $this->render_description_template_publish_dry_run_response($res);
    }

    private function render_description_template_publish_dry_run_response(array $res): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $backUrl = esc_url(admin_url('admin.php?page=woo-ebay'));
        $payloadExcerpt = (array) ($res['payload_excerpt'] ?? []);
        unset($res['payload_excerpt']);
        echo '<div class="wrap" style="font-family:Arial,Helvetica,sans-serif;margin:20px;">';
        echo '<h1>Safe eBay.de publish description dry-run</h1>';
        echo '<p><a href="' . $backUrl . '">&larr; Back to Woo eBay Integration</a></p>';
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
        $backUrl = esc_url(admin_url('admin.php?page=woo-ebay'));
        echo '<div class="wrap" style="font-family:Arial,Helvetica,sans-serif;margin:20px;">';
        echo '<h1>Safe eBay.de description template preview</h1>';
        echo '<p><a href="' . $backUrl . '">&larr; Back to Woo eBay Integration</a></p>';
        echo '<p><strong>Safety:</strong> local preview only; no eBay API, no Ovoko API, no active listing update, no new listing, no Woo product changes.</p>';
        echo '<h2>Preview metadata</h2><pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:12px;">' . esc_html(wp_json_encode([
            'result' => (string) ($res['result'] ?? 'error'),
            'product_id' => (int) ($res['product_id'] ?? 0),
            'sku' => (string) ($res['sku'] ?? ''),
            'title' => (string) ($res['title'] ?? ''),
            'description_source' => (string) ($res['description_source'] ?? 'post_content'),
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
            'warnings' => (array) ($res['warnings'] ?? []),
            'safety' => (array) ($res['safety'] ?? []),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
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


    public function regenerate_german_content(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_ebay_regenerate_german_content');

        $productId = (int) ($_POST['product_id'] ?? 0);
        $res = $this->adapter->generate_german_content_meta_only($productId, true);
        $res = array_merge($res, [
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'created_ebay_listing' => false,
            'modified_woo_product' => false,
        ]);

        $this->set_status('eBay.de German content regenerated meta-only: ' . wp_json_encode($res));
        wp_send_json($res);
    }


    public function generate_german_content_batch(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_generate_german_content_batch');

        $mode = sanitize_key((string) ($_POST['mode'] ?? 'stale'));
        $mode = in_array($mode, ['all', 'stale'], true) ? $mode : 'stale';
        $batchSize = max(1, min(200, absint($_POST['batch_size'] ?? 50)));
        $cursorOption = 'wei_ebay_german_content_batch_cursor_' . $mode;
        $cursor = (int) get_option($cursorOption, 0);
        $productIds = $this->german_content_batch_product_ids($batchSize, $mode, $cursor);
        $summary = [
            'mode' => $mode,
            'status' => 'in_progress',
            'cursor' => $cursor,
            'next_cursor' => 0,
            'processed' => 0,
            'generated' => 0,
            'already_fresh' => 0,
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
            $res = $this->adapter->generate_german_content_meta_only($productId, $mode === 'all');
            $summary['processed']++;
            if (($res['result'] ?? '') === 'already_ready') {
                $summary['already_fresh']++;
            } elseif (($res['result'] ?? '') === 'success' || ($res['result'] ?? '') === 'generated') {
                $summary['generated']++;
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

        update_option('wei_ebay_german_content_audit_summary', array_diff_key($summary, ['results' => true]), false);
        $this->set_status('Generate German content: ' . wp_json_encode($summary, JSON_UNESCAPED_UNICODE));
        $this->go();
    }

    private function german_content_batch_product_ids(int $batchSize, string $mode, int $cursor = 0): array
    {
        global $wpdb;
        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
                AND p.post_status IN ('publish', 'draft', 'private')
                AND p.ID > %d
            GROUP BY p.ID
            ORDER BY p.ID ASC
            LIMIT %d
        ";
        return array_values(array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, max(0, $cursor), $batchSize))));
    }

    public function description_template_single(): void
    {
        $this->require_manage_options();
        check_admin_referer('wei_description_template_single');
        $res = [
            'result' => 'disabled',
            'reason' => 'description_template_updates_disabled_preview_stage',
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'created_ebay_listing' => false,
        ];
        $this->set_status('eBay.de single description template update disabled: ' . wp_json_encode($res));
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
        $categoryTeachingImportSummary = get_option('wei_ebay_category_mapping_import_summary', []);
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
        return trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
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
        $lastPath = trim((string) get_option('wei_ebay_last_problems_only_csv_path', ''));
        if ($key === 'problems_only_csv' && $lastPath !== '' && is_readable($lastPath)) {
            return $lastPath;
        }
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
        $asReady = function_exists('as_next_scheduled_action') && did_action('action_scheduler_init');
        $next = $asReady ? as_next_scheduled_action(AutoSyncScheduler::HOOK_DELTA_SYNC, [], AutoSyncScheduler::CRON_GROUP) : false;
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
        if (!isset($s['enable_ebay_de_description_template'])) {
            $s['enable_ebay_de_description_template'] = 0;
        }
        if (!isset($s['ebay_de_delivery_map_url'])) {
            $s['ebay_de_delivery_map_url'] = '';
        }
        if (!isset($s['ebay_seller_username'])) {
            $s['ebay_seller_username'] = '';
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
        $s = $this->with_shipping_policy_defaults($s);
        return $s;
    }


    private function with_shipping_policy_defaults(array $s): array
    {
        $legacyFulfillmentId = trim((string) ($s['ebay_fulfillment_policy_id'] ?? ''));
        if (empty($s['fulfillment_policy_id_30_eur'])) {
            $s['fulfillment_policy_id_30_eur'] = $legacyFulfillmentId !== '' ? $legacyFulfillmentId : EbayShippingPolicyResolver::POLICY_30_EUR;
        }
        if (empty($s['ebay_fulfillment_policy_id'])) {
            $s['ebay_fulfillment_policy_id'] = (string) $s['fulfillment_policy_id_30_eur'];
        }
        if (empty($s['fulfillment_policy_id_50_eur'])) {
            $s['fulfillment_policy_id_50_eur'] = EbayShippingPolicyResolver::POLICY_50_EUR;
        }
        if (empty($s['fulfillment_policy_id_100_eur'])) {
            $s['fulfillment_policy_id_100_eur'] = EbayShippingPolicyResolver::POLICY_100_EUR;
        }
        if (!isset($s['shipping_category_ids_50_eur'])) {
            $s['shipping_category_ids_50_eur'] = '';
        }
        if (!isset($s['shipping_category_ids_100_eur'])) {
            $s['shipping_category_ids_100_eur'] = '';
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
