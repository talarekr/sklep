<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;

class AdminPage
{
    public function __construct(private EbayAuth $auth, private EbayAdapter $adapter, private SyncService $syncService, private OrderImporter $orderImporter, private Logger $logger, private CategoryMappingRepository $categoryRepo)
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
        $s['default_category_id'] = sanitize_text_field((string) ($_POST['default_category_id'] ?? ''));
        $s['sku_category_overrides'] = sanitize_textarea_field((string) ($_POST['sku_category_overrides'] ?? ''));
        $s['sku_aspect_overrides'] = sanitize_textarea_field((string) ($_POST['sku_aspect_overrides'] ?? ''));
        $s['category_aspect_fallbacks'] = sanitize_textarea_field((string) ($_POST['category_aspect_fallbacks'] ?? ''));
        $s['default_hersteller_fallback'] = sanitize_text_field((string) ($_POST['default_hersteller_fallback'] ?? ''));
        $s['use_woo_sku_for_ebay'] = !empty($_POST['use_woo_sku_for_ebay']) ? 1 : 0;
        $s['write_generated_sku_to_woo'] = !empty($_POST['write_generated_sku_to_woo']) ? 1 : 0;
        $s['stock_sync_mode'] = in_array(($_POST['stock_sync_mode'] ?? 'set_zero'), ['set_zero', 'reduce'], true) ? $_POST['stock_sync_mode'] : 'set_zero';
        $provider = strtolower(sanitize_text_field((string) ($_POST['translation_provider'] ?? 'disabled')));
        $s['translation_api_key'] = sanitize_text_field((string) ($_POST['translation_api_key'] ?? ''));
        $s['translation_provider'] = in_array($provider, ['disabled', 'openai', 'deepl', 'google'], true) ? $provider : 'disabled';
        if ($s['translation_provider'] !== 'disabled' && $s['translation_api_key'] === '') {
            $s['translation_provider'] = 'disabled';
        }
        $s['auto_generate_german_content_preflight'] = !empty($_POST['auto_generate_german_content_preflight']) ? 1 : 0;
        $s['regenerate_german_content_on_hash_change'] = !empty($_POST['regenerate_german_content_on_hash_change']) ? 1 : 0;
        $s['translation_openai_model'] = sanitize_text_field((string) ($_POST['translation_openai_model'] ?? 'gpt-4o-mini'));
        $s['inventory_location_key'] = sanitize_text_field((string) ($_POST['inventory_location_key'] ?? 'gpswiss-pl'));
        $s['inventory_location_name'] = sanitize_text_field((string) ($_POST['inventory_location_name'] ?? 'gpswiss-pl'));
        $s['inventory_location_country'] = sanitize_text_field((string) ($_POST['inventory_location_country'] ?? 'PL'));
        $s['inventory_location_postal_code'] = sanitize_text_field((string) ($_POST['inventory_location_postal_code'] ?? '08-460'));
        $s['inventory_location_city'] = sanitize_text_field((string) ($_POST['inventory_location_city'] ?? 'Sobolew'));
        $s['inventory_location_address_line_1'] = sanitize_text_field((string) ($_POST['inventory_location_address_line_1'] ?? ''));
        $s['ebay_fulfillment_policy_id'] = sanitize_text_field((string) ($_POST['fulfillmentPolicyId'] ?? $_POST['ebay_fulfillment_policy_id'] ?? ''));
        $s['ebay_payment_policy_id'] = sanitize_text_field((string) ($_POST['paymentPolicyId'] ?? $_POST['ebay_payment_policy_id'] ?? ''));
        $s['ebay_return_policy_id'] = sanitize_text_field((string) ($_POST['returnPolicyId'] ?? $_POST['ebay_return_policy_id'] ?? ''));
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
        $category_id = sanitize_text_field((string) ($_POST['ebay_category_id'] ?? ''));
        $aspects_json = sanitize_textarea_field((string) ($_POST['ebay_aspects_json'] ?? ''));
        if ($id > 0 && $category_id !== '') {
            update_post_meta($id, '_wei_ebay_category_id', $category_id);
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
            $this->categoryRepo->upsert([
                'marketplace_id' => $marketplaceId,
                'woo_term_id' => $termId,
                'woo_category_path' => $this->categoryRepo->woo_category_path($termId),
                'ebay_category_id' => $ebayCategoryId,
                'ebay_category_name' => sanitize_text_field((string) ($_POST['ebay_category_name'] ?? '')),
                'ebay_category_path' => sanitize_text_field((string) ($_POST['ebay_category_path'] ?? '')),
                'source' => 'manual',
                'confidence' => 1,
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
        }
        if (!isset($s['translation_api_key'])) {
            $s['translation_api_key'] = '';
        }
        if (!isset($s['auto_generate_german_content_preflight'])) {
            $s['auto_generate_german_content_preflight'] = 1;
        }
        if (!isset($s['regenerate_german_content_on_hash_change'])) {
            $s['regenerate_german_content_on_hash_change'] = 0;
        }
        if (!isset($s['translation_openai_model'])) {
            $s['translation_openai_model'] = 'gpt-4o-mini';
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
            $s['use_woo_sku_for_ebay'] = 1;
        }
        if (!isset($s['write_generated_sku_to_woo'])) {
            $s['write_generated_sku_to_woo'] = 0;
        }
        if (!isset($s['stock_sync_mode'])) {
            $s['stock_sync_mode'] = 'set_zero';
        }
        return $s;
    }
}
