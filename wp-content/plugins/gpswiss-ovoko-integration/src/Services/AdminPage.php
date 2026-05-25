<?php

namespace GPSwiss\Ovoko\Services;

class AdminPage
{
    private OvokoIntegrationService $service;

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
        add_action('admin_post_gpswiss_ovoko_preview_rrr_parts_sample', [$this, 'handle_preview_rrr_parts_sample']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_single_part', [$this, 'handle_preview_rrr_single_part']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_woo_create', [$this, 'handle_preview_rrr_woo_create']);
        add_action('admin_post_gpswiss_ovoko_create_rrr_woo_draft', [$this, 'handle_create_rrr_woo_draft']);
        add_action('admin_post_gpswiss_ovoko_preview_listing_image_status', [$this, 'handle_preview_listing_image_status']);
        add_action('admin_post_gpswiss_ovoko_generate_listing_image', [$this, 'handle_generate_listing_image']);
        add_action('admin_post_gpswiss_ovoko_apply_technical_attributes', [$this, 'handle_apply_technical_attributes']);
        add_action('admin_post_gpswiss_ovoko_preview_rrr_car_details', [$this, 'handle_preview_rrr_car_details']);
        add_action('admin_post_gpswiss_ovoko_preview_title_with_vehicle', [$this, 'handle_preview_title_with_vehicle']);
        add_action('admin_post_gpswiss_ovoko_run_gearbox_exclusion_count', [$this, 'handle_run_gearbox_exclusion_count']);
        add_action('admin_post_gpswiss_ovoko_preview_frontend_part_number_mapping', [$this, 'handle_preview_frontend_part_number_mapping']);
        add_action('admin_post_gpswiss_ovoko_apply_frontend_part_number_mapping', [$this, 'handle_apply_frontend_part_number_mapping']);
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
}
