<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;

class AdminPage
{
    public function __construct(private EbayAuth $auth, private EbayAdapter $adapter, private SyncService $syncService, private OrderImporter $orderImporter, private Logger $logger)
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
        add_action('admin_post_wei_upsert_business_policies', [$this, 'upsert_business_policies']);
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
        $connect_url = $this->auth->get_authorize_url();
        include WEI_PLUGIN_DIR . 'views/admin-page.php';
    }

    public function save_settings(): void
    {
        check_admin_referer('wei_save_settings');
        $s = $this->settings();
        $s['environment'] = in_array($_POST['environment'] ?? 'production', ['sandbox', 'production'], true) ? $_POST['environment'] : 'production';
        $s['client_id'] = sanitize_text_field((string) ($_POST['client_id'] ?? ''));
        $s['client_secret'] = sanitize_text_field((string) ($_POST['client_secret'] ?? ''));
        $s['runame'] = sanitize_text_field((string) ($_POST['runame'] ?? ''));
        $s['marketplace_id'] = sanitize_text_field((string) ($_POST['marketplace_id'] ?? 'EBAY_DE'));
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? 'gpswiss-pl'));
        $s['inventory_location_name'] = sanitize_text_field((string) ($_POST['inventory_location_name'] ?? 'gpswiss-pl'));
        $s['inventory_location_country'] = sanitize_text_field((string) ($_POST['inventory_location_country'] ?? 'PL'));
        $s['inventory_location_postal_code'] = sanitize_text_field((string) ($_POST['inventory_location_postal_code'] ?? '08-460'));
        $s['inventory_location_city'] = sanitize_text_field((string) ($_POST['inventory_location_city'] ?? 'Sobolew'));
        $s['inventory_location_address_line_1'] = sanitize_text_field((string) ($_POST['inventory_location_address_line_1'] ?? ''));
        $s['fulfillment_policy_name'] = sanitize_text_field((string) ($_POST['fulfillment_policy_name'] ?? 'GP Swiss Shipping'));
        $s['payment_policy_name'] = sanitize_text_field((string) ($_POST['payment_policy_name'] ?? 'GP Swiss Payments'));
        $s['return_policy_name'] = sanitize_text_field((string) ($_POST['return_policy_name'] ?? 'GP Swiss Returns'));
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&saved=1'));
        exit;
    }

    public function disconnect(): void { check_admin_referer('wei_disconnect'); $this->auth->disconnect(); $this->set_status('Disconnected'); $this->go(); }
    public function test_connection(): void { check_admin_referer('wei_test'); $res = $this->auth->get_valid_access_token(); $this->set_status(is_wp_error($res) ? 'Test failed: '.$res->get_error_message() : 'Connection OK'); $this->go(); }
    public function run_readiness(): void { check_admin_referer('wei_readiness'); $res = $this->adapter->readiness_check(); $this->set_status('Readiness: '.wp_json_encode($res)); $this->go(); }

    public function export_product(): void
    {
        check_admin_referer('wei_export');
        $id = (int) ($_POST['product_id'] ?? 0);
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

    public function upsert_inventory_location(): void
    {
        check_admin_referer('wei_upsert_inventory_location');
        $res = $this->adapter->upsert_inventory_location();
        $this->set_status('Inventory location: ' . wp_json_encode($res));
        $this->go();
    }

    public function upsert_business_policies(): void
    {
        check_admin_referer('wei_upsert_business_policies');
        $res = $this->adapter->upsert_business_policies();
        $this->set_status('Business policies: ' . wp_json_encode($res));
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
        if (!isset($s['inventory_location_address_line_1'])) {
            $s['inventory_location_address_line_1'] = '';
        }
        if (empty($s['fulfillment_policy_name'])) {
            $s['fulfillment_policy_name'] = 'GP Swiss Shipping';
        }
        if (empty($s['payment_policy_name'])) {
            $s['payment_policy_name'] = 'GP Swiss Payments';
        }
        if (empty($s['return_policy_name'])) {
            $s['return_policy_name'] = 'GP Swiss Returns';
        }
        return $s;
    }
}
