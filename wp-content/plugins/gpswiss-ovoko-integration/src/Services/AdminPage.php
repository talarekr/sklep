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

        $data = $this->service->get_dashboard_data();
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
}
