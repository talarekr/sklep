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
        add_action('admin_post_gpswiss_ovoko_test_callback', [$this, 'handle_test_callback']);
        add_action('admin_post_gpswiss_ovoko_check_supply_connector', [$this, 'handle_check_supply_connector']);
        add_action('admin_post_gpswiss_ovoko_check_rrr_api', [$this, 'handle_check_rrr_api']);
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
        add_action('admin_post_gpswiss_ovoko_apply_rrr_vehicle_data', [$this, 'handle_apply_rrr_vehicle_data']);
        add_action('admin_post_gpswiss_ovoko_preview_title_with_vehicle', [$this, 'handle_preview_title_with_vehicle']);
        add_action('admin_post_gpswiss_ovoko_run_gearbox_exclusion_count', [$this, 'handle_run_gearbox_exclusion_count']);
        add_action('admin_post_gpswiss_ovoko_preview_frontend_part_number_mapping', [$this, 'handle_preview_frontend_part_number_mapping']);
        add_action('admin_post_gpswiss_ovoko_apply_frontend_part_number_mapping', [$this, 'handle_apply_frontend_part_number_mapping']);
        add_action('admin_post_gpswiss_ovoko_preview_allegro_to_ovoko_match', [$this, 'handle_preview_allegro_to_ovoko_match']);
        add_action('admin_post_gpswiss_ovoko_apply_allegro_to_ovoko_details', [$this, 'handle_apply_allegro_to_ovoko_details']);
        add_action('admin_post_gpswiss_ovoko_preview_product_details_table_render_status', [$this, 'handle_preview_product_details_table_render_status']);
        add_action('admin_post_gpswiss_ovoko_probe_rrr_part_search_by_code', [$this, 'handle_probe_rrr_part_search_by_code']);
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
        } catch (\Throwable $e) {
            $data = ['settings' => $this->service->get_settings()];
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
        $options = [
            'product_id' => isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0,
            'after_product_id' => isset($_POST['after_product_id']) ? (int) $_POST['after_product_id'] : 0,
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 1,
            'batch_size' => isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : 1,
            'dry_run' => !array_key_exists('dry_run', $_POST) || !empty($_POST['dry_run']),
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
        $result = $this->service->generate_listing_image_for_ovoko_product($productId);
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
