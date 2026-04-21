<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    private AllegroAuth $auth;
    private Importer $importer;
    private Logger $logger;
    private Cron $cron;

    public function __construct(AllegroAuth $auth, Importer $importer, Logger $logger, Cron $cron)
    {
        $this->auth = $auth;
        $this->importer = $importer;
        $this->logger = $logger;
        $this->cron = $cron;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_awi_manual_import', [$this, 'handle_manual_import']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Allegro Import', 'allegro-woo-importer'),
            __('Allegro Import', 'allegro-woo-importer'),
            'manage_woocommerce',
            'awi-settings',
            [$this, 'render_page'],
            'dashicons-update'
        );
    }

    public function register_settings(): void
    {
        register_setting('awi_settings_group', Plugin::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => Plugin::get_settings(),
        ]);
    }

    public function sanitize_settings(array $input): array
    {
        $current = Plugin::get_settings();
        $redirect_uri = esc_url_raw($input['redirect_uri'] ?? '');
        if ($redirect_uri === '') {
            $redirect_uri = AllegroAuth::get_default_redirect_uri();
        }

        $clean = [
            'client_id' => sanitize_text_field($input['client_id'] ?? ''),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
            'redirect_uri' => $redirect_uri,
            'environment' => in_array(($input['environment'] ?? 'production'), ['production', 'sandbox'], true) ? $input['environment'] : 'production',
            'sync_mode' => in_array(($input['sync_mode'] ?? 'create_update'), ['create_only', 'update_only', 'create_update'], true) ? $input['sync_mode'] : 'create_update',
            'inactive_product_status' => in_array(($input['inactive_product_status'] ?? 'draft'), ['draft', 'private'], true) ? $input['inactive_product_status'] : 'draft',
            'cron_interval' => in_array(($input['cron_interval'] ?? 'manual'), ['manual', 'awi_15_minutes', 'hourly', 'daily'], true) ? $input['cron_interval'] : 'manual',
            'offer_status' => sanitize_text_field($input['offer_status'] ?? 'ACTIVE'),
        ];

        return array_merge($current, $clean);
    }

    public function handle_manual_import(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'allegro-woo-importer'));
        }

        check_admin_referer('awi_manual_import');

        $token = $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            $this->logger->error('Manual import blocked due to missing token.', ['error' => $token->get_error_message()]);
            $redirect = add_query_arg([
                'page' => 'awi-settings',
                'awi_import_error' => $token->get_error_message(),
            ], admin_url('admin.php'));

            wp_safe_redirect($redirect);
            exit;
        }

        $summary = $this->importer->import_offers();

        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_import_done' => '1',
            'awi_created' => (int) ($summary['created'] ?? 0),
            'awi_updated' => (int) ($summary['updated'] ?? 0),
            'awi_errors' => (int) ($summary['errors'] ?? 0),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_GET['awi_oauth_status'])) {
            $status = sanitize_text_field((string) wp_unslash($_GET['awi_oauth_status']));
            $message = isset($_GET['awi_oauth_message']) ? sanitize_text_field((string) wp_unslash($_GET['awi_oauth_message'])) : '';
            add_settings_error('awi_messages', 'awi_oauth_notice', $message !== '' ? $message : __('Status autoryzacji OAuth został zaktualizowany.', 'allegro-woo-importer'), $status === 'success' ? 'updated' : 'error');
        }

        if (isset($_GET['awi_import_error'])) {
            $message = sanitize_text_field((string) wp_unslash($_GET['awi_import_error']));
            add_settings_error('awi_messages', 'awi_import_error', $message, 'error');
        }

        if (isset($_GET['awi_import_done'])) {
            add_settings_error(
                'awi_messages',
                'awi_import_done',
                sprintf(
                    __('Import zakończony. Utworzono: %1$d, zaktualizowano: %2$d, błędy: %3$d', 'allegro-woo-importer'),
                    (int) ($_GET['awi_created'] ?? 0),
                    (int) ($_GET['awi_updated'] ?? 0),
                    (int) ($_GET['awi_errors'] ?? 0)
                ),
                'updated'
            );
        }

        $settings = Plugin::get_settings();
        if (empty($settings['redirect_uri'])) {
            $settings['redirect_uri'] = AllegroAuth::get_default_redirect_uri();
        }
        $history = get_option(Plugin::HISTORY_OPTION_KEY, []);
        if (!is_array($history)) {
            $history = [];
        }

        $oauth_url = $this->auth->get_authorization_url();
        $log_tail = $this->logger->read_tail(80);

        include AWI_PLUGIN_DIR . 'templates/admin-page.php';
    }
}
