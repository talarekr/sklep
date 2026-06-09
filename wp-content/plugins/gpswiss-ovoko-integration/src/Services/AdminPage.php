<?php

namespace GPSwiss\Ovoko\Services;

class AdminPage
{
    private OvokoIntegrationService $service;
    private string $lastHookSuffix = '';
    private string $lastPageSlug = '';
    private string $autorunScriptUrl = '';
    private string $autorunScriptPath = '';
    private bool $autorunScriptExists = false;
    private bool $autorunScriptEnqueued = false;
    private string $autorunScriptVersion = '1.0.0';

    public function __construct(OvokoIntegrationService $service)
    {
        $this->service = $service;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_gpswiss_ovoko_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_gpswiss_ovoko_save_crm_import_settings', [$this, 'handle_save_crm_import_settings']);
        add_action('admin_post_gpswiss_ovoko_test_callback', [$this, 'handle_test_callback']);
        add_action('admin_post_gpswiss_ovoko_check_supply_connector', [$this, 'handle_check_supply_connector']);
        add_action('admin_post_gpswiss_ovoko_check_rrr_api', [$this, 'handle_check_rrr_api']);
        add_action('admin_post_gpswiss_ovoko_auto_sync_endpoint_analysis', [$this, 'handle_auto_sync_endpoint_analysis']);
        add_action('admin_post_gpswiss_ovoko_probe_event_sources_for_part', [$this, 'handle_probe_event_sources_for_part']);
        add_action('admin_post_gpswiss_ovoko_probe_updated_from_delta', [$this, 'handle_probe_updated_from_delta']);
        add_action('admin_post_gpswiss_ovoko_dry_run_auto_sync', [$this, 'handle_dry_run_auto_sync']);
        add_action('admin_post_gpswiss_ovoko_manual_live_date_from_sync', [$this, 'handle_manual_live_date_from_sync']);
        add_action('admin_post_gpswiss_ovoko_manual_single_part_probe', [$this, 'handle_manual_single_part_probe']);
        add_action('admin_post_gpswiss_ovoko_manual_single_part_stock_sync', [$this, 'handle_manual_single_part_stock_sync']);
        add_action('admin_post_gpswiss_ovoko_dry_run_sale_sync', [$this, 'handle_dry_run_sale_sync']);
        add_action('admin_post_gpswiss_ovoko_bidirectional_enable', [$this, 'handle_bidirectional_enable']);
        add_action('admin_post_gpswiss_ovoko_bidirectional_pause', [$this, 'handle_bidirectional_pause']);
        add_action('admin_post_gpswiss_ovoko_bidirectional_run_now', [$this, 'handle_bidirectional_run_now']);
        add_action('admin_post_gpswiss_ovoko_bidirectional_retry_failed_sales', [$this, 'handle_bidirectional_retry_failed_sales']);
        add_action('admin_post_gpswiss_ovoko_bidirectional_clear_recent_runs', [$this, 'handle_bidirectional_clear_recent_runs']);
        add_action('admin_post_gpswiss_ovoko_single_order_mark_sold_live_probe', [$this, 'handle_single_order_mark_sold_live_probe']);
        add_action('admin_post_gpswiss_ovoko_analyze_sale_stock_endpoint', [$this, 'handle_analyze_sale_stock_endpoint']);
        add_action('admin_post_gpswiss_ovoko_analyze_internal_notes_backfill_api', [$this, 'handle_analyze_internal_notes_backfill_api']);
        add_action('admin_post_gpswiss_ovoko_dry_run_internal_notes_price_backfill', [$this, 'handle_dry_run_internal_notes_price_backfill']);
        add_action('admin_post_gpswiss_ovoko_preview_woo_to_ovoko_create_part', [$this, 'handle_preview_woo_to_ovoko_create_part']);
        add_action('admin_post_gpswiss_ovoko_create_crm_only_part_from_woo', [$this, 'handle_create_crm_only_part_from_woo']);
        add_action('admin_post_gpswiss_ovoko_repair_crm_only_part_link', [$this, 'handle_repair_crm_only_part_link']);
        add_action('admin_post_gpswiss_ovoko_batch_crm_only_preview', [$this, 'handle_batch_crm_only_preview']);
        add_action('admin_post_gpswiss_ovoko_batch_crm_only_import', [$this, 'handle_batch_crm_only_import']);
        add_action('admin_post_gpswiss_ovoko_batch_crm_only_csv', [$this, 'handle_batch_crm_only_csv']);
        add_action('admin_post_gpswiss_ovoko_read_part_statuses', [$this, 'handle_read_part_statuses']);
        add_action('admin_post_gpswiss_ovoko_single_part_internal_notes_live_probe', [$this, 'handle_single_part_internal_notes_live_probe']);
        add_action('admin_post_gpswiss_ovoko_test_api_connection', [$this, 'handle_test_api_connection']);
        add_action('admin_post_gpswiss_ovoko_test_updatepart_place_for_product_43302', [$this, 'handle_test_updatepart_place_for_product_43302']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_parts_sample', [$this, 'handle_preview_rrr_parts_sample']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_single_part', [$this, 'handle_preview_rrr_single_part']);
        add_action('admin_post_gpswiss_ovoko_probe_ovoko_image_url_variants', [$this, 'handle_probe_ovoko_image_url_variants']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_woo_create', [$this, 'handle_preview_rrr_woo_create']);
        add_action('admin_post_gpswiss_ovoko_create_rrr_woo_draft', [$this, 'handle_create_rrr_woo_draft']);
        add_action('admin_post_gpswiss_ovoko_preview_listing_image_status', [$this, 'handle_preview_listing_image_status']);
        add_action('admin_post_gpswiss_ovoko_generate_listing_image', [$this, 'handle_generate_listing_image']);
        add_action('admin_post_gpswiss_ovoko_apply_technical_attributes', [$this, 'handle_apply_technical_attributes']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_car_details', [$this, 'handle_preview_rrr_car_details']);
        add_action('admin_post_gpswiss_ovoko_probe_rrr_vehicle_endpoints', [$this, 'handle_probe_rrr_vehicle_endpoints']);
        add_action('admin_post_gpswiss_ovoko_probe_vehicle_data_for_car_id', [$this, 'handle_probe_vehicle_data_for_car_id']);
        add_action('admin_post_gpswiss_ovoko_probe_dictionary_value', [$this, 'handle_probe_dictionary_value']);
        add_action('admin_post_gpswiss_ovoko_probe_car_brands_models_raw', [$this, 'handle_probe_car_brands_models_raw']);
        add_action('admin_post_gpswiss_ovoko_apply_rrr_vehicle_data', [$this, 'handle_apply_rrr_vehicle_data']);
        add_action('admin_post_gpswiss_ovoko_preview_title_with_vehicle', [$this, 'handle_preview_title_with_vehicle']);
        add_action('admin_post_gpswiss_ovoko_run_gearbox_exclusion_count', [$this, 'handle_run_gearbox_exclusion_count']);
        add_action('admin_post_gpswiss_ovoko_preview_frontend_part_number_mapping', [$this, 'handle_preview_frontend_part_number_mapping']);
        add_action('admin_post_gpswiss_ovoko_apply_frontend_part_number_mapping', [$this, 'handle_apply_frontend_part_number_mapping']);
        add_action('admin_post_gpswiss_ovoko_preview_allegro_to_ovoko_match', [$this, 'handle_preview_allegro_to_ovoko_match']);
        add_action('admin_post_gpswiss_ovoko_apply_allegro_to_ovoko_details', [$this, 'handle_apply_allegro_to_ovoko_details']);
        add_action('admin_post_gpswiss_ovoko_preview_product_details_table_render_status', [$this, 'handle_preview_product_details_table_render_status']);
        add_action('admin_post_gpswiss_ovoko_probe_rrr_part_search_by_code', [$this, 'handle_probe_rrr_part_search_by_code']);
        add_action('admin_post_gpswiss_ovoko_save_marketplace_category_suggestions_settings', [$this, 'handle_save_marketplace_category_suggestions_settings']);
        add_action('admin_post_gpswiss_ovoko_test_category_prediction_by_code', [$this, 'handle_test_category_prediction_by_code']);
        add_action('admin_post_gpswiss_ovoko_preview_paginated_rrr_part_code_lookup', [$this, 'handle_preview_paginated_rrr_part_code_lookup']);
        add_action('admin_post_gpswiss_ovoko_import_csv_mapping', [$this, 'handle_import_csv_mapping']);
        add_action('admin_post_gpswiss_ovoko_export_missing_ovoko_id', [$this, 'handle_export_missing_ovoko_id']);
        add_action('admin_post_gpswiss_ovoko_bulk_diagnostics_ping', [$this, 'handle_bulk_diagnostics_ping']);
        add_action('admin_post_gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment', [$this, 'handle_bulk_allegro_to_ovoko_details_enrichment']);
        add_action('wp_ajax_gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment', [$this, 'handle_bulk_allegro_to_ovoko_details_enrichment']);
        add_action('admin_post_gpswiss_ovoko_single_enrichment_dry_run', [$this, 'handle_single_enrichment_dry_run']);
        add_action('admin_post_gpswiss_ovoko_update_description_from_listing_text', [$this, 'handle_update_description_from_listing_text']);
        add_action('wp_ajax_gpswiss_ovoko_update_description_from_listing_text', [$this, 'handle_update_description_from_listing_text_ajax']);
        add_action('admin_post_gpswiss_ovoko_update_categories_from_ovoko', [$this, 'handle_update_categories_from_ovoko']);
        add_action('wp_ajax_gpswiss_ovoko_update_categories_from_ovoko', [$this, 'handle_update_categories_from_ovoko_ajax']);
        add_action('admin_post_gpswiss_ovoko_audit_old_categories', [$this, 'handle_audit_old_categories']);
        add_action('admin_post_gpswiss_ovoko_download_category_cleanup_csv', [$this, 'handle_download_category_cleanup_csv']);
        add_action('admin_post_gpswiss_ovoko_export_product_category_assignments', [$this, 'handle_export_product_category_assignments']);
        add_action('admin_post_gpswiss_ovoko_dry_run_delete_all_product_categories', [$this, 'handle_dry_run_delete_all_product_categories']);
        add_action('admin_post_gpswiss_ovoko_delete_all_product_categories', [$this, 'handle_delete_all_product_categories']);
        add_action('admin_post_gpswiss_ovoko_rebuild_categories_from_scratch', [$this, 'handle_rebuild_categories_from_scratch']);
        add_action('wp_ajax_gpswiss_ovoko_rebuild_categories_from_scratch', [$this, 'handle_rebuild_categories_from_scratch_ajax']);
        add_action('wp_ajax_gpswiss_ovoko_category_rebuild_autorun', [$this, 'handle_category_rebuild_autorun_ajax']);
        add_action('admin_post_gpswiss_ovoko_pause_category_rebuild', [$this, 'handle_pause_category_rebuild']);
        add_action('admin_post_gpswiss_ovoko_resume_category_rebuild', [$this, 'handle_resume_category_rebuild']);
        add_action('admin_post_gpswiss_ovoko_post_rebuild_category_audit', [$this, 'handle_post_rebuild_category_audit']);
        add_action('admin_post_gpswiss_ovoko_preview_homepage_menu_changes', [$this, 'handle_preview_homepage_menu_changes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }



    public function enqueue_admin_assets(string $hook): void
    {
        $handle = 'gpswiss-ovoko-admin-autorun';
        $this->lastHookSuffix = $hook;
        $this->lastPageSlug = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $this->autorunScriptPath = dirname(__DIR__, 2) . '/assets/admin-autorun.js';
        $this->autorunScriptUrl = plugins_url('assets/admin-autorun.js', dirname(__DIR__, 2) . '/gpswiss-ovoko-integration.php');
        $this->autorunScriptExists = file_exists($this->autorunScriptPath);
        $this->autorunScriptVersion = $this->autorunScriptExists ? (string) filemtime($this->autorunScriptPath) : '1.0.0';

        $isPluginSlugPage = ($this->lastPageSlug === 'gpswiss-ovoko-integration');
        $isHookMatch = (strpos($hook, 'gpswiss') !== false || strpos($hook, 'ovoko') !== false);
        $isPluginToolsPage = (isset($_GET['page']) && $this->lastPageSlug !== '' && preg_match('/gpswiss|ovoko/i', $this->lastPageSlug) === 1);
        $shouldEnqueue = $isPluginSlugPage || $isHookMatch || $isPluginToolsPage;

        if (!$shouldEnqueue) {
            return;
        }

        wp_enqueue_script($handle, $this->autorunScriptUrl, [], $this->autorunScriptVersion, true);
        $this->autorunScriptEnqueued = true;
        wp_localize_script($handle, 'gpswissOvokoAutorunConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment'),
            'action' => 'gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment',
            'descriptionNonce' => wp_create_nonce('gpswiss_ovoko_update_description_from_listing_text'),
            'descriptionAction' => 'gpswiss_ovoko_update_description_from_listing_text',
            'categoryNonce' => wp_create_nonce('gpswiss_ovoko_update_categories_from_ovoko'),
            'categoryAction' => 'gpswiss_ovoko_update_categories_from_ovoko',
            'adminAutorunJsUrl' => $this->autorunScriptUrl,
            'adminAutorunJsVersion' => $this->autorunScriptVersion,
            'categoryRebuildAutorunNonce' => wp_create_nonce('gpswiss_ovoko_category_rebuild_autorun'),
            'categoryRebuildAutorunAction' => 'gpswiss_ovoko_category_rebuild_autorun',
        ]);
    }

    public function register_admin_page(): void
    {
        add_submenu_page(
            'tools.php',
            'Ovoko Integration',
            'Ovoko Integration',
            'manage_options',
            'gpswiss-ovoko-integration',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        try {
            $data = $this->service->get_dashboard_data();
            $bidirectionalOrchestrator = new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service);
            $data['bidirectional_sync_status'] = $bidirectionalOrchestrator->dashboard_status();
            $data['bidirectional_sync_recent_runs'] = $bidirectionalOrchestrator->recent_runs();
            $data['manual_single_part_stock_sync_logs'] = $this->service->get_manual_single_part_stock_sync_logs();
            $batchCrmOnlyService = new WooToOvokoCrmOnlyBatchImportService($this->service->get_settings());
            $data['crm_only_batch_preview'] = $batchCrmOnlyService->latest_preview();
            $data['crm_only_batch_import_result'] = $batchCrmOnlyService->latest_import_result();
        } catch (\Throwable $e) {
            $bidirectionalOrchestrator = new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service);
            $data = ['settings' => $this->service->get_settings(), 'bidirectional_sync_status' => $bidirectionalOrchestrator->dashboard_status(), 'bidirectional_sync_recent_runs' => $bidirectionalOrchestrator->recent_runs(), 'manual_single_part_stock_sync_logs' => $this->service->get_manual_single_part_stock_sync_logs(), 'crm_only_batch_preview' => [], 'crm_only_batch_import_result' => []];
            $notice = ['type' => 'error', 'text' => 'Dashboard diagnostics temporarily unavailable: ' . $e->getMessage()];
            include dirname(__DIR__, 2) . '/views/admin-page.php';
            return;
        }
        $notice = get_transient('gpswiss_ovoko_notice');
        delete_transient('gpswiss_ovoko_notice');

        $currentAdminHookSuffix = $this->lastHookSuffix;
        $adminPageSlug = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        $autoRunExpectedAssetUrl = $this->autorunScriptUrl;
        $autoRunAssetPath = $this->autorunScriptPath;
        $autoRunFileExists = $this->autorunScriptExists;
        $autoRunScriptEnqueued = $this->autorunScriptEnqueued;
        $autoRunAssetVersion = $this->autorunScriptVersion;

        include dirname(__DIR__, 2) . '/views/admin-page.php';
    }

    public function handle_save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_save_settings');

        $this->service->save_settings($_POST);
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => 'Settings updated.'], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_save_crm_import_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_save_crm_import_settings');

        $settings = $this->service->get_settings();
        $defaultCrmCarId = preg_replace('/\D+/', '', (string) ($_POST['gpswiss_ovoko_default_crm_import_car_id'] ?? ''));
        $defaultCrmCarNote = sanitize_text_field((string) ($_POST['gpswiss_ovoko_default_crm_import_car_note'] ?? 'Placeholder car_id used for CRM-only import. Vehicle must be corrected manually in Ovoko.'));
        $settings['gpswiss_ovoko_default_crm_import_car_id'] = (string) $defaultCrmCarId;
        $settings['gpswiss_ovoko_default_crm_import_car_note'] = $defaultCrmCarNote;

        update_option(OvokoIntegrationService::OPTION_KEY, $settings, false);
        update_option('gpswiss_ovoko_default_crm_import_car_id', $settings['gpswiss_ovoko_default_crm_import_car_id'], false);
        update_option('gpswiss_ovoko_default_crm_import_car_note', $settings['gpswiss_ovoko_default_crm_import_car_note'], false);

        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => 'CRM-only import settings updated.'], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_preview_woo_to_ovoko_create_part(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_preview_woo_to_ovoko_create_part');

        $rawProductId = $_POST['product_id'] ?? null;
        $productId = is_array($rawProductId) ? 0 : (int) (string) wp_unslash((string) $rawProductId);
        $result = (new WooToOvokoCreatePartPreviewService())->preview($productId);
        if ($rawProductId === null || is_array($rawProductId)) {
            $result['ok'] = false;
            $result['would_be_eligible'] = false;
            $result['validations'][] = ['severity' => 'error', 'code' => 'exactly_one_product_id_required', 'message' => 'Exactly one product_id field is required.'];
            $result['validation_errors'][] = ['severity' => 'error', 'code' => 'exactly_one_product_id_required', 'message' => 'Exactly one product_id field is required.'];
        }

        wp_send_json($result);
    }




    public function handle_repair_crm_only_part_link(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_repair_crm_only_part_link');

        $rawProductId = $_POST['product_id'] ?? null;
        $rawPartId = $_POST['part_id'] ?? '';
        $productId = ($rawProductId === null || is_array($rawProductId)) ? 0 : (int) (string) wp_unslash((string) $rawProductId);
        $partId = is_array($rawPartId) ? '' : sanitize_text_field(wp_unslash((string) $rawPartId));
        $result = (new WooToOvokoCrmOnlyImportService($this->service->get_settings()))->repair_product_part_id($productId, $partId);

        set_transient('gpswiss_ovoko_notice', [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_create_crm_only_part_from_woo(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_create_crm_only_part_from_woo');

        $rawProductId = $_POST['product_id'] ?? null;
        $productId = ($rawProductId === null || is_array($rawProductId)) ? 0 : (int) (string) wp_unslash((string) $rawProductId);
        $confirmations = [
            'confirm_placeholder_car_id' => !empty($_POST['confirm_placeholder_car_id']),
            'confirm_live_one_product' => !empty($_POST['confirm_live_one_product']),
            'confirm_no_price_non_public' => !empty($_POST['confirm_no_price_non_public']),
        ];

        $result = (new WooToOvokoCrmOnlyImportService($this->service->get_settings()))->create($productId, $confirmations);
        if ($rawProductId === null || is_array($rawProductId)) {
            $result['ok'] = false;
            $result['status'] = 'blocked';
            $result['error_code'] = 'exactly_one_product_id_required';
            $result['message'] = 'Exactly one product_id field is required.';
        }

        set_transient('gpswiss_ovoko_notice', [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }



    public function handle_batch_crm_only_preview(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_batch_crm_only_preview');

        $result = (new WooToOvokoCrmOnlyBatchImportService($this->service->get_settings()))->preview($this->batch_crm_only_filters_from_request());
        set_transient('gpswiss_ovoko_notice', [
            'type' => 'success',
            'text' => wp_json_encode(['action_name' => $result['action_name'], 'checked_at' => $result['checked_at'], 'summary' => $result['summary']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration#batch-crm-only-import'));
        exit;
    }

    public function handle_batch_crm_only_import(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_batch_crm_only_import');

        $result = (new WooToOvokoCrmOnlyBatchImportService($this->service->get_settings()))->import($this->batch_crm_only_filters_from_request(), [
            'confirm_batch_crm_only_no_price' => !empty($_POST['confirm_batch_crm_only_no_price']),
        ]);
        $type = !empty($result['ok']) && (int) ($result['summary']['failed_count'] ?? 0) === 0 ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', [
            'type' => $type,
            'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration#batch-crm-only-import'));
        exit;
    }

    public function handle_batch_crm_only_csv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_batch_crm_only_csv');

        $service = new WooToOvokoCrmOnlyBatchImportService($this->service->get_settings());
        $preview = $service->latest_preview();
        $errorsOnly = isset($_GET['errors_only']) ? (string) $_GET['errors_only'] === '1' : (!empty($_POST['errors_only']));
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ovoko-crm-only-batch-preview-' . ($errorsOnly ? 'errors-' : 'full-') . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        if (!is_resource($out)) {
            wp_die('Unable to open CSV output stream.');
        }
        foreach ($service->csv_rows($preview, $errorsOnly) as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    private function batch_crm_only_filters_from_request(): array
    {
        return [
            'only_gmail_imported' => !empty($_POST['only_gmail_imported']),
            'product_id_from' => isset($_POST['product_id_from']) ? (int) $_POST['product_id_from'] : 0,
            'product_id_to' => isset($_POST['product_id_to']) ? (int) $_POST['product_id_to'] : 0,
            'created_after' => isset($_POST['created_after']) ? sanitize_text_field((string) wp_unslash($_POST['created_after'])) : '',
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : WooToOvokoCrmOnlyBatchImportService::DEFAULT_BATCH_SIZE,
            'stop_on_error' => !empty($_POST['stop_on_error']),
        ];
    }

    public function handle_read_part_statuses(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_read_part_statuses');

        $result = (new RrrApiClient($this->service->get_settings()))->read_part_statuses();
        update_option('gpswiss_ovoko_part_status_probe_result', $result, false);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_check_supply_connector(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_check_supply_connector');

        $result = $this->service->check_supply_connector_configuration();
        $type = (($result['status'] ?? '') !== 'waiting_for_ovoko_credentials_details') ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_check_rrr_api(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_check_rrr_api');

        $result = $this->service->check_rrr_api_configuration();
        $type = (($result['status'] ?? '') === 'needs_configuration_or_endpoint_confirmation') ? 'warning' : 'success';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }



    public function handle_auto_sync_endpoint_analysis(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_auto_sync_endpoint_analysis');
        $result = (new OvokoAutoSyncDryRunService($this->service))->endpoint_capability_analysis();
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_probe_event_sources_for_part(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_probe_event_sources_for_part');
        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 4303;
        $today = isset($_POST['today']) ? sanitize_text_field((string) $_POST['today']) : gmdate('Y-m-d');
        $yesterday = isset($_POST['yesterday']) ? sanitize_text_field((string) $_POST['yesterday']) : gmdate('Y-m-d', time() - DAY_IN_SECONDS);
        $client = new RrrApiClient($this->service->get_settings());
        $result = $client->probe_ovoko_event_sources_for_part($partId, [$today, $yesterday]);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['found_target_part']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_probe_updated_from_delta(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_probe_updated_from_delta');
        $from = isset($_POST['updated_from']) ? sanitize_text_field((string) $_POST['updated_from']) : '';
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 5;
        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        $client = new RrrApiClient($this->service->get_settings());
        $result = $client->probe_precise_parts_delta_filters($from, $limit, $page);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['delta_sync_confirmed']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_dry_run_auto_sync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_dry_run_auto_sync');
        $result = (new OvokoAutoSyncDryRunService($this->service))->dry_run_ovoko_to_woo([
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 5,
            'page' => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field((string) $_POST['date_from']) : '',
        ]);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 90);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_manual_live_date_from_sync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_manual_live_date_from_sync');
        $result = (new OvokoAutoSyncDryRunService($this->service))->manual_live_date_from_sync([
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'page' => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'date_from' => isset($_POST['date_from']) ? sanitize_text_field((string) $_POST['date_from']) : '',
            'confirmation' => isset($_POST['confirmation']) ? sanitize_text_field((string) $_POST['confirmation']) : '',
        ]);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }



    public function handle_manual_single_part_probe(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_manual_single_part_probe');
        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $result = $this->service->manual_probe_ovoko_single_part_stock($partId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_manual_single_part_stock_sync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_manual_single_part_stock_sync');
        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0;
        $result = $this->service->manual_sync_ovoko_single_part_stock($partId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bidirectional_enable(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_bidirectional_enable');
        $result = (new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service))->request_enable_automatic_sync();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bidirectional_pause(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_bidirectional_pause');
        $result = (new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service))->pause_sync();
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bidirectional_run_now(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_bidirectional_run_now');
        $result = (new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service))->run_now();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bidirectional_retry_failed_sales(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_bidirectional_retry_failed_sales');
        $result = (new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service))->retry_failed_sales();
        set_transient('gpswiss_ovoko_notice', ['type' => 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bidirectional_clear_recent_runs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_bidirectional_clear_recent_runs');
        (new \GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator($this->service))->clear_recent_runs();
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => wp_json_encode(['ok' => true, 'action_name' => 'Clear cron logs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_analyze_sale_stock_endpoint(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_analyze_sale_stock_endpoint');

        $result = (new OvokoAutoSyncDryRunService($this->service))->analyze_woo_to_ovoko_sale_stock_endpoint();
        set_transient('gpswiss_ovoko_notice', ['type' => 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_dry_run_sale_sync(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_dry_run_sale_sync');
        $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        $result = (new OvokoAutoSyncDryRunService($this->service))->dry_run_woo_to_ovoko_sale($orderId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 90);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_single_order_mark_sold_live_probe(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_single_order_mark_sold_live_probe');

        $result = (new OvokoAutoSyncDryRunService($this->service))->single_order_live_probe_mark_sold(
            isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0,
            isset($_POST['confirmation']) ? sanitize_text_field((string) $_POST['confirmation']) : ''
        );
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'error', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_analyze_internal_notes_backfill_api(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_analyze_internal_notes_backfill_api');

        $result = (new OvokoInternalNotesPriceBackfillService($this->service))->endpoint_capability_analysis();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_dry_run_internal_notes_price_backfill(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_dry_run_internal_notes_price_backfill');

        $result = (new OvokoInternalNotesPriceBackfillService($this->service))->dry_run([
            'max_products' => isset($_POST['max_products']) ? (int) $_POST['max_products'] : 100,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'sample_limit' => 50,
        ]);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_single_part_internal_notes_live_probe(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_single_part_internal_notes_live_probe');

        $result = (new OvokoInternalNotesPriceBackfillService($this->service))->single_part_live_probe([
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'ovoko_id' => isset($_POST['ovoko_id']) ? sanitize_text_field((string) $_POST['ovoko_id']) : '',
            'confirmation' => isset($_POST['confirmation']) ? sanitize_text_field((string) $_POST['confirmation']) : '',
        ]);

        $type = !empty($result['critical_warning']) ? 'error' : (!empty($result['ok']) ? 'success' : 'warning');
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_test_api_connection(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_test_api_connection');

        $result = $this->service->test_api_connection();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'error', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_test_updatepart_place_for_product_43302(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_test_updatepart_place_for_product_43302');

        $result = $this->service->test_update_part_place_for_product_43302();
        $type = !empty($result['success_by_json_status_code']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_test_callback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_test_callback');

        $partId = sanitize_text_field((string) ($_POST['part_id'] ?? ''));
        $status = sanitize_text_field((string) ($_POST['status'] ?? 'sold'));
        $result = $this->service->run_local_test_callback($partId, $status);

        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'error', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }



    public function handle_preview_rrr_single_part(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_preview_rrr_single_part');

        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 15;
        $result = $this->service->preview_rrr_single_part($partId);
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_update_description_from_listing_text(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_update_description_from_listing_text');
        $submittedButton = sanitize_key((string) ($_POST['submit_action'] ?? ''));
        $rawDryRunParam = $_POST['dry_run'] ?? null;
        $resolvedDryRun = $submittedButton === 'apply' ? false : true;
        if ($submittedButton !== 'apply' && $submittedButton !== 'dry_run') {
            $resolvedDryRun = !isset($_POST['dry_run']) || !empty($_POST['dry_run']);
        }

        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'ovoko_id' => sanitize_text_field((string) ($_POST['ovoko_id'] ?? '')),
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'dry_run' => $resolvedDryRun,
            'update_only_empty_description' => !array_key_exists('update_only_empty_description', $_POST) || !empty($_POST['update_only_empty_description']),
            'replace_existing_description' => !empty($_POST['replace_existing_description']),
            'save_to_meta_only' => !empty($_POST['save_to_meta_only']),
            'prepend_to_existing_description' => !empty($_POST['prepend_to_existing_description']),
            'stop_on_error' => !empty($_POST['stop_on_error']),
            'listing_text_meta_key' => sanitize_key((string) ($_POST['listing_text_meta_key'] ?? '_ovoko_listing_text')),
        ];
        $result = $this->service->update_woo_description_from_ovoko_listing_text($options);
        $result['submitted_button'] = $submittedButton;
        $result['submit_action'] = $submittedButton;
        $result['raw_dry_run_param'] = is_scalar($rawDryRunParam) ? (string) $rawDryRunParam : wp_json_encode($rawDryRunParam);
        $result['resolved_dry_run'] = $resolvedDryRun;
        $result['save_to_meta_only'] = $options['save_to_meta_only'];
        $result['replace_existing_description'] = $options['replace_existing_description'];
        $result['prepend_to_existing_description'] = $options['prepend_to_existing_description'];
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_update_description_from_listing_text_ajax(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json(['ok' => false, 'error' => 'unauthorized'], 403);
        }
        check_ajax_referer('gpswiss_ovoko_update_description_from_listing_text');

        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'ovoko_id' => sanitize_text_field((string) ($_POST['ovoko_id'] ?? '')),
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'dry_run' => !empty($_POST['dry_run']),
            'update_only_empty_description' => !empty($_POST['update_only_empty_description']),
            'replace_existing_description' => !empty($_POST['replace_existing_description']),
            'save_to_meta_only' => !empty($_POST['save_to_meta_only']),
            'prepend_to_existing_description' => !empty($_POST['prepend_to_existing_description']),
            'stop_on_error' => !empty($_POST['stop_on_error']),
            'listing_text_meta_key' => sanitize_key((string) ($_POST['listing_text_meta_key'] ?? '_ovoko_listing_text')),
        ];

        $result = $this->service->update_woo_description_from_ovoko_listing_text($options);
        $result['done'] = empty($result['next_after_product_id']) || ((int) ($result['next_after_product_id'] ?? 0) <= (int) ($result['after_product_id'] ?? 0)) || empty($result['results']);
        wp_send_json($result);
    }

    

    public function handle_update_categories_from_ovoko(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_update_categories_from_ovoko');
        $submittedButton = sanitize_key((string) ($_POST['submit_action'] ?? ''));
        $resolvedDryRun = $submittedButton === 'apply' ? false : true;
        if ($submittedButton !== 'apply' && $submittedButton !== 'dry_run') {
            $resolvedDryRun = !isset($_POST['dry_run']) || !empty($_POST['dry_run']);
        }
        if (!$resolvedDryRun && (string) ($_POST['confirmation'] ?? '') !== 'REBUILD WOO CATEGORIES FROM OVOKO') {
            $result = ['ok' => false, 'error' => 'confirmation_required', 'required_confirmation' => 'REBUILD WOO CATEGORIES FROM OVOKO'];
            set_transient('gpswiss_ovoko_notice', ['type' => 'error', 'text' => wp_json_encode($result)], 120);
            wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
            exit;
        }
        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'dry_run' => $resolvedDryRun,
            'create_missing_categories' => !array_key_exists('create_missing_categories', $_POST) || !empty($_POST['create_missing_categories']),
            'replace_existing_categories' => !array_key_exists('replace_existing_categories', $_POST) || !empty($_POST['replace_existing_categories']),
            'stop_on_error' => !empty($_POST['stop_on_error']),
        ];
        $result = $this->service->update_woo_categories_from_ovoko($options);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_update_categories_from_ovoko_ajax(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json(['ok' => false, 'error' => 'unauthorized'], 403); }
        check_ajax_referer('gpswiss_ovoko_update_categories_from_ovoko');
        $resolvedDryRun = !array_key_exists('dry_run', $_POST) || !empty($_POST['dry_run']);
        if (!$resolvedDryRun && (string) ($_POST['confirmation'] ?? '') !== 'REBUILD WOO CATEGORIES FROM OVOKO') {
            wp_send_json(['ok' => false, 'error' => 'confirmation_required', 'required_confirmation' => 'REBUILD WOO CATEGORIES FROM OVOKO'], 400);
        }
        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'dry_run' => $resolvedDryRun,
            'create_missing_categories' => !array_key_exists('create_missing_categories', $_POST) || !empty($_POST['create_missing_categories']),
            'replace_existing_categories' => !array_key_exists('replace_existing_categories', $_POST) || !empty($_POST['replace_existing_categories']),
            'stop_on_error' => !empty($_POST['stop_on_error']),
        ];
        $result = $this->service->update_woo_categories_from_ovoko($options);
        $result['done'] = empty($result['next_after_product_id']) || ((int) ($result['next_after_product_id'] ?? 0) <= (int) ($result['after_product_id'] ?? 0)) || empty($result['results']);
        wp_send_json($result);
    }

    public function handle_audit_old_categories(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_audit_old_categories');
        $result = $this->service->audit_old_categories_for_cleanup();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_download_category_cleanup_csv(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_download_category_cleanup_csv');
        $csv = $this->service->build_category_cleanup_csv();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="category-cleanup-audit-' . gmdate('Ymd-His') . '.csv"');
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }



    public function handle_export_product_category_assignments(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_export_product_category_assignments');

        $batchSize = isset($_POST['batch_size']) ? max(1, min(200, (int) wp_unslash($_POST['batch_size']))) : 100;
        $afterProductId = isset($_POST['after_product_id']) ? max(0, (int) wp_unslash($_POST['after_product_id'])) : 0;
        $maxRows = isset($_POST['max_rows']) ? max(0, (int) wp_unslash($_POST['max_rows'])) : 0;

        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="woo-product-category-assignments-' . gmdate('Ymd-His') . '.csv"');
        header('X-Accel-Buffering: no');

        $out = fopen('php://output', 'w');
        if (!is_resource($out)) {
            wp_die('Unable to open CSV output stream.');
        }

        $this->service->stream_current_product_category_assignments_csv($out, [
            'batch_size' => $batchSize,
            'after_product_id' => $afterProductId,
            'max_rows' => $maxRows,
        ]);
        fclose($out);
        exit;
    }

    public function handle_dry_run_delete_all_product_categories(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_dry_run_delete_all_product_categories');
        try {
            $ultraLight = !isset($_POST['ultra_light_dry_run']) || (string) wp_unslash($_POST['ultra_light_dry_run']) === '1';
            $result = $this->service->dry_run_delete_all_product_categories($ultraLight);
        } catch (\Throwable $e) {
            $result = [
                'ok' => false,
                'action_name' => 'Ultra-light dry-run delete all Woo product categories',
                'dry_run' => true,
                'partial' => true,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'warnings' => ['Dry-run delete failed before all checks completed. No products or categories were changed.'],
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'duration' => 0,
            ];
        }
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 180);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_delete_all_product_categories(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_delete_all_product_categories');
        $confirmation = (string) ($_POST['confirmation'] ?? '');
        $result = $this->service->delete_all_product_categories($confirmation);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'error', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_rebuild_categories_from_scratch(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_rebuild_categories_from_scratch');
        $submittedButton = sanitize_key((string) ($_POST['submit_action'] ?? 'dry_run'));
        $dryRun = $submittedButton !== 'apply';
        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 10,
            'dry_run' => $dryRun,
            'confirmation' => (string) ($_POST['confirmation'] ?? ''),
            'stop_on_error' => !empty($_POST['stop_on_error']),
            'rebuild_menu_cache_when_done' => !empty($_POST['rebuild_menu_cache_when_done']),
        ];
        $result = $this->service->rebuild_woo_categories_from_ovoko_from_scratch($options);
        $noticeType = !empty($result['ok']) && empty($result['warnings']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $noticeType, 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_rebuild_categories_from_scratch_ajax(): void
    {
        if (!current_user_can('manage_options')) { wp_send_json(['ok' => false, 'error' => 'unauthorized'], 403); }
        check_ajax_referer('gpswiss_ovoko_rebuild_categories_from_scratch');
        $dryRun = !array_key_exists('dry_run', $_POST) || !empty($_POST['dry_run']);
        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 10,
            'dry_run' => $dryRun,
            'confirmation' => (string) ($_POST['confirmation'] ?? ''),
            'stop_on_error' => !empty($_POST['stop_on_error']),
            'rebuild_menu_cache_when_done' => !empty($_POST['rebuild_menu_cache_when_done']),
        ];
        $result = $this->service->rebuild_woo_categories_from_ovoko_from_scratch($options);
        $result['done'] = !empty($result['paused']) || empty($result['next_after_product_id']) || ((int) ($result['next_after_product_id'] ?? 0) <= (int) ($result['after_product_id'] ?? 0)) || empty($result['results']);
        wp_send_json($result);
    }


    public function handle_category_rebuild_autorun_ajax(): void
    {
        $command = sanitize_key((string) ($_POST['command'] ?? 'status'));
        if (!current_user_can('manage_options')) {
            wp_send_json(['ok' => false, 'command' => $command, 'status' => null, 'message' => 'unauthorized', 'error' => 'unauthorized'], 403);
        }
        if (check_ajax_referer('gpswiss_ovoko_category_rebuild_autorun', '_ajax_nonce', false) === false) {
            wp_send_json(['ok' => false, 'command' => $command, 'status' => $this->service->get_category_rebuild_autorun_status(), 'message' => 'nonce_check_failed', 'error' => 'nonce_check_failed'], 403);
        }
        $options = [
            'confirmation' => (string) ($_POST['confirmation'] ?? ''),
            'start_after_product_id' => isset($_POST['start_after_product_id']) ? (int) $_POST['start_after_product_id'] : null,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 5,
            'cache_rebuild_every_n_batches' => isset($_POST['cache_rebuild_every_n_batches']) ? (int) $_POST['cache_rebuild_every_n_batches'] : 5,
            'stop_on_error' => !array_key_exists('stop_on_error', $_POST) || !empty($_POST['stop_on_error']),
        ];
        if ($options['start_after_product_id'] === null) { unset($options['start_after_product_id']); }
        $result = $this->service->control_category_rebuild_autorun($command, $options);
        update_option('gpswiss_ovoko_category_rebuild_autorun_last_ajax', [
            'at' => gmdate('c'),
            'command' => $command,
            'ok' => !empty($result['ok']),
            'message' => (string) ($result['message'] ?? ''),
            'error' => (string) ($result['error'] ?? ''),
            'status_summary' => (array) ($result['status_summary'] ?? []),
        ], false);
        wp_send_json($result, 200);
    }

    public function handle_pause_category_rebuild(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_pause_category_rebuild');
        $this->service->set_category_rebuild_paused(true);
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => 'Category rebuild paused.'], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_resume_category_rebuild(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_resume_category_rebuild');
        $this->service->set_category_rebuild_paused(false);
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => 'Category rebuild resumed.'], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_post_rebuild_category_audit(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_post_rebuild_category_audit');
        $result = $this->service->post_rebuild_category_audit();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE)], 300);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_homepage_menu_changes(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_homepage_menu_changes');
        $result = $this->service->preview_homepage_menu_changes();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_probe_ovoko_image_url_variants(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_probe_ovoko_image_url_variants');
        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 10994;
        $result = $this->service->probe_ovoko_image_url_variants($partId);
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_rrr_parts_sample(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_preview_rrr_parts_sample');

        $limit = isset($_POST['preview_limit']) ? (int) $_POST['preview_limit'] : 50;
        $page = isset($_POST['preview_page']) ? (int) $_POST['preview_page'] : 1;
        $result = $this->service->preview_rrr_parts_sample($limit, $page);
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_export_missing_ovoko_id(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_export_missing_ovoko_id');
        $this->service->export_missing_ovoko_id_report_csv();
        exit;
    }


    public function handle_create_rrr_woo_draft(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_create_rrr_woo_draft');

        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 10994;
        $result = $this->service->create_woo_draft_product_from_rrr_part($partId);
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_rrr_woo_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_preview_rrr_woo_create');

        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 10994;
        $result = $this->service->preview_woo_product_create_from_rrr_part($partId);
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_listing_image_status(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_preview_listing_image_status');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->preview_listing_image_status($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_generate_listing_image(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_generate_listing_image');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->generate_listing_image_for_ovoko_product($productId, true);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_run_gearbox_exclusion_count(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('gpswiss_ovoko_run_gearbox_exclusion_count');

        $result = $this->service->run_gearbox_exclusion_count();
        $type = !empty($result['ok']) ? 'success' : 'warning';
        set_transient('gpswiss_ovoko_notice', ['type' => $type, 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_apply_technical_attributes(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_apply_technical_attributes');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->apply_ovoko_technical_attributes_to_product($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_rrr_car_details(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_rrr_car_details');
        $carId = isset($_POST['car_id']) ? (int) $_POST['car_id'] : 458;
        $result = $this->service->preview_rrr_car_details($carId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_probe_rrr_vehicle_endpoints(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_probe_rrr_vehicle_endpoints');
        $carId = isset($_POST['car_id']) ? (int) $_POST['car_id'] : 458;
        $result = $this->service->probe_rrr_vehicle_endpoints($carId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration')); exit;
    }

    public function handle_probe_vehicle_data_for_car_id(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_probe_vehicle_data_for_car_id');
        $carId = isset($_POST['car_id']) ? (int) $_POST['car_id'] : 458;
        $result = $this->service->probe_ovoko_vehicle_data_for_car_id($carId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration')); exit;
    }

    public function handle_probe_dictionary_value(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_probe_dictionary_value');
        $dictionaryType = isset($_POST['dictionary_type']) ? sanitize_text_field((string) wp_unslash($_POST['dictionary_type'])) : '';
        $id = isset($_POST['id']) ? sanitize_text_field((string) wp_unslash($_POST['id'])) : '';
        $result = $this->service->probe_ovoko_dictionary_value($dictionaryType, $id);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration')); exit;
    }

    public function handle_probe_car_brands_models_raw(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_probe_car_brands_models_raw');
        $result = $this->service->probe_ovoko_car_brands_models_raw();
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration')); exit;
    }

    public function handle_apply_rrr_vehicle_data(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_apply_rrr_vehicle_data');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $updateTitle = !empty($_POST['update_title']);
        $result = $this->service->apply_rrr_vehicle_data_to_ovoko_product($productId, $updateTitle);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration')); exit;
    }

    public function handle_preview_title_with_vehicle(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_title_with_vehicle');
        $partId = isset($_POST['part_id']) ? (int) $_POST['part_id'] : 60271;
        $result = $this->service->preview_ovoko_title_with_vehicle_data($partId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_frontend_part_number_mapping(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_frontend_part_number_mapping');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->preview_frontend_part_number_mapping($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_apply_frontend_part_number_mapping(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_apply_frontend_part_number_mapping');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->apply_frontend_part_number_mapping($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_allegro_to_ovoko_match(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_allegro_to_ovoko_match');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->preview_allegro_to_ovoko_match($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_apply_allegro_to_ovoko_details(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_apply_allegro_to_ovoko_details');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $replaceDescription = !empty($_POST['replace_description']);
        $result = $this->service->apply_allegro_to_ovoko_details($productId, $replaceDescription, [
            'raw_replace_description_input' => $_POST['replace_description'] ?? null,
            'form_source' => 'single_update_form',
        ]);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }


    public function handle_preview_product_details_table_render_status(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_product_details_table_render_status');
        $productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        $result = $this->service->preview_product_details_table_render_status($productId);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }



    public function handle_save_marketplace_category_suggestions_settings(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_save_marketplace_category_suggestions_settings');
        $this->service->save_marketplace_category_suggestion_settings($_POST);
        set_transient('gpswiss_ovoko_notice', ['type' => 'success', 'text' => 'Marketplace category suggestion diagnostic settings updated.'], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_test_category_prediction_by_code(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_test_category_prediction_by_code');
        $partCode = isset($_POST['part_code']) ? sanitize_text_field((string) $_POST['part_code']) : '';
        $result = $this->service->test_ovoko_category_prediction_by_code($partCode);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 120);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_probe_rrr_part_search_by_code(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_probe_rrr_part_search_by_code');
        $partNumber = isset($_POST['part_number']) ? sanitize_text_field((string) $_POST['part_number']) : '';
        $result = $this->service->probe_rrr_part_search_by_code($partNumber);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_preview_paginated_rrr_part_code_lookup(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_preview_paginated_rrr_part_code_lookup');
        $partNumber = isset($_POST['part_number']) ? sanitize_text_field((string) $_POST['part_number']) : '';
        $maxPages = isset($_POST['max_pages']) ? (int) $_POST['max_pages'] : 3;
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 100;
        $result = $this->service->preview_paginated_rrr_part_code_lookup($partNumber, $maxPages, $limit);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 30);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_import_csv_mapping(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_import_csv_mapping');
        $csvPath = isset($_POST['csv_file_path']) ? sanitize_text_field((string) $_POST['csv_file_path']) : '';
        $uploadedTmp = isset($_FILES['csv_mapping_file']['tmp_name']) ? (string) $_FILES['csv_mapping_file']['tmp_name'] : '';
        $uploadedName = isset($_FILES['csv_mapping_file']['name']) ? sanitize_file_name((string) $_FILES['csv_mapping_file']['name']) : '';
        $result = $this->service->import_ovoko_csv_mapping($csvPath, $uploadedTmp, $uploadedName);
        set_transient('gpswiss_ovoko_notice', ['type' => !empty($result['ok']) ? 'success' : 'warning', 'text' => wp_json_encode($result)], 60);
        wp_safe_redirect(admin_url('tools.php?page=gpswiss-ovoko-integration'));
        exit;
    }

    public function handle_bulk_allegro_to_ovoko_details_enrichment(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        if (wp_doing_ajax()) {
            check_ajax_referer('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment');
        } else {
            check_admin_referer('gpswiss_ovoko_bulk_allegro_to_ovoko_details_enrichment');
        }
        $rawMinimalResponseInput = $_POST['minimal_response'] ?? null;
        $parsedMinimalResponse = !isset($_POST['minimal_response']) || !empty($_POST['minimal_response']);
        $rawDisableDebugHeavyLogsInput = $_POST['disable_debug_heavy_logs'] ?? null;
        $parsedDisableDebugHeavyLogs = !isset($_POST['disable_debug_heavy_logs']) || !empty($_POST['disable_debug_heavy_logs']);
        $memoryLimitRaw = (string) ini_get('memory_limit');
        $memoryLimitMb = (int) preg_replace('/[^0-9]/', '', $memoryLimitRaw);
        $blockFullBulk = $memoryLimitMb > 0 && $memoryLimitMb <= 128;
        $apiCheck = (array) get_transient('gpswiss_ovoko_last_api_connection_test');
        $apiOk = !empty($apiCheck['ok']);
        $forceOverride = !empty($_POST['force_api_override']);
        if (empty($_POST['dry_run']) && !empty($_POST['apply']) && !$apiOk && !$forceOverride) {
            wp_send_json([
                'ok' => false,
                'error' => 'api_connection_error_blocked_apply',
                'message' => 'Apply blocked because API connection test is in ERROR state. Run Test API connection or use force_api_override=1.',
                'apply_allowed' => false,
                'apply_blocked_reason' => 'api_connection_error',
                'api_connection' => $apiCheck,
            ], 500);
        }
        if ($blockFullBulk && empty($_POST['dry_run'])) {
            wp_send_json([
                'ok' => false,
                'error' => 'full_enrichment_blocked_low_memory_limit',
                'memory_limit' => $memoryLimitRaw,
                'message' => 'Full enrichment/apply is blocked at 128M because /get/part fetch peaks near memory limit. Increase PHP memory_limit to 256M or use CLI/lightweight worker.',
                'apply_allowed' => false,
                'apply_blocked_reason' => 'memory_limit_too_low',
                'recommended_memory_limit' => 256,
            ], 500);
        }
        $options = [
            'dry_run' => !empty($_POST['dry_run']),
            'apply' => !empty($_POST['apply']),
            'details_only' => !isset($_POST['details_only']) || !empty($_POST['details_only']),
            'replace_description' => !empty($_POST['replace_description']),
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 20,
            'offset' => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
            'page' => isset($_POST['page']) ? (int) $_POST['page'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 2,
            'product_ids_csv' => isset($_POST['product_ids_csv']) ? sanitize_text_field((string) $_POST['product_ids_csv']) : '',
            'only_matched' => !empty($_POST['only_matched']),
            'skip_already_enriched' => !empty($_POST['skip_already_enriched']),
            'include_existing_ovoko' => !empty($_POST['include_existing_ovoko']),
            'fast_scan' => !isset($_POST['fast_scan']) || !empty($_POST['fast_scan']),
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'scan_limit' => isset($_POST['scan_limit']) ? (int) $_POST['scan_limit'] : 3,
            'minimal_response' => $parsedMinimalResponse,
            'disable_debug_heavy_logs' => $parsedDisableDebugHeavyLogs,
            'raw_minimal_response_input' => $rawMinimalResponseInput,
            'parsed_minimal_response' => $parsedMinimalResponse,
            'raw_disable_debug_heavy_logs_input' => $rawDisableDebugHeavyLogsInput,
            'parsed_disable_debug_heavy_logs' => $parsedDisableDebugHeavyLogs,
            'form_source' => isset($_POST['form_source']) ? sanitize_text_field((string) $_POST['form_source']) : 'batch_update_form',
        ];
        try {
            $result = $this->service->bulk_allegro_to_ovoko_details_enrichment($options);
            wp_send_json($result, !empty($result['ok']) ? 200 : 500);
        } catch (\Throwable $e) {
            $this->service->log_event('bulk_allegro_to_ovoko_details_enrichment_exception', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            wp_send_json([
                'ok' => false,
                'partial' => false,
                'action_name' => 'Update product cards from Ovoko CSV mapping',
                'error' => 'bulk_request_failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function handle_single_enrichment_dry_run(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_single_enrichment_dry_run');
        $formSource = isset($_POST['form_source']) ? sanitize_text_field((string) $_POST['form_source']) : 'single_update_form';
        $result = $this->service->single_enrichment_dry_run_memory_safe([
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'part_id' => isset($_POST['part_id']) ? (int) $_POST['part_id'] : 0,
            'dry_run' => true,
            'details_only' => true,
            'minimal_response' => true,
            'disable_debug_heavy_logs' => true,
            'debug_full' => !empty($_POST['debug_full']),
            'form_source' => $formSource,
            'handler_used' => 'single_enrichment_dry_run_memory_safe',
        ]);
        wp_send_json($result, !empty($result['ok']) ? 200 : 500);
    }

    public function handle_bulk_diagnostics_ping(): void
    {
        if (!current_user_can('manage_options')) { wp_die('Unauthorized'); }
        check_admin_referer('gpswiss_ovoko_bulk_diagnostics_ping');
        $result = $this->service->bulk_diagnostics_ping([
            'product_ids_csv' => isset($_POST['product_ids_csv']) ? sanitize_text_field((string) $_POST['product_ids_csv']) : '',
            'minimal_response' => !empty($_POST['minimal_response']),
            'disable_debug_heavy_logs' => !empty($_POST['disable_debug_heavy_logs']),
        ]);
        wp_send_json($result, !empty($result['ok']) ? 200 : 500);
    }

}
