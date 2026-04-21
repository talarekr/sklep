<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroAuth
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function hooks(): void
    {
        add_action('admin_init', [$this, 'handle_oauth_callback']);
    }

    public function get_authorization_url(): string
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);

        $state = wp_generate_password(20, false, false);
        set_transient('awi_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        $query = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'],
            'state' => $state,
        ];

        return add_query_arg($query, $base . '/auth/oauth/authorize');
    }

    public function handle_oauth_callback(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'awi-settings') {
            return;
        }

        if (!isset($_GET['awi_oauth'])) {
            return;
        }

        $settings = Plugin::get_settings();
        $state_key = 'awi_oauth_state_' . get_current_user_id();
        $saved_state = get_transient($state_key);

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if (empty($saved_state) || $saved_state !== $state) {
            $this->logger->error('OAuth callback rejected due to invalid state.');
            add_settings_error('awi_messages', 'awi_oauth_state', __('Błędny stan OAuth (state).', 'allegro-woo-importer'), 'error');
            return;
        }

        delete_transient($state_key);

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        if (empty($code)) {
            $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : 'unknown_error';
            $this->logger->error('OAuth callback without code.', ['error' => $error]);
            add_settings_error('awi_messages', 'awi_oauth_code', sprintf(__('Autoryzacja Allegro nieudana: %s', 'allegro-woo-importer'), $error), 'error');
            return;
        }

        $token_response = $this->request_token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $settings['redirect_uri'],
        ]);

        if (is_wp_error($token_response)) {
            $this->logger->error('OAuth token exchange failed.', ['error' => $token_response->get_error_message()]);
            add_settings_error('awi_messages', 'awi_oauth_exchange', __('Nie udało się pobrać tokena Allegro.', 'allegro-woo-importer'), 'error');
            return;
        }

        $this->persist_token_response($token_response);
        add_settings_error('awi_messages', 'awi_oauth_ok', __('Połączono z Allegro poprawnie.', 'allegro-woo-importer'), 'updated');
    }

    public function get_valid_access_token()
    {
        $settings = Plugin::get_settings();

        if (empty($settings['access_token'])) {
            return new \WP_Error('awi_missing_token', __('Brak access tokena Allegro.', 'allegro-woo-importer'));
        }

        $expires_at = !empty($settings['token_expires_at']) ? strtotime((string) $settings['token_expires_at']) : 0;
        if ($expires_at > (time() + 60)) {
            return $settings['access_token'];
        }

        if (empty($settings['refresh_token'])) {
            return new \WP_Error('awi_missing_refresh_token', __('Brak refresh tokena Allegro.', 'allegro-woo-importer'));
        }

        $refresh_response = $this->request_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $settings['refresh_token'],
            'redirect_uri' => $settings['redirect_uri'],
        ]);

        if (is_wp_error($refresh_response)) {
            $this->logger->error('Token refresh failed.', ['error' => $refresh_response->get_error_message()]);
            return $refresh_response;
        }

        $this->persist_token_response($refresh_response);

        $new_settings = Plugin::get_settings();
        return $new_settings['access_token'] ?: new \WP_Error('awi_missing_token_after_refresh', __('Nie udało się zapisać odświeżonego tokena.', 'allegro-woo-importer'));
    }

    private function request_token(array $body)
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);

        $credentials = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);

        $response = wp_remote_post($base . '/auth/oauth/token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($status < 200 || $status > 299 || !is_array($data)) {
            return new \WP_Error('awi_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'allegro-woo-importer'), ['status' => $status, 'body' => $raw]);
        }

        return $data;
    }

    private function persist_token_response(array $token_data): void
    {
        $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 0;
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(0, $expires_in));

        Plugin::update_settings([
            'access_token' => sanitize_text_field($token_data['access_token'] ?? ''),
            'refresh_token' => sanitize_text_field($token_data['refresh_token'] ?? ''),
            'token_type' => sanitize_text_field($token_data['token_type'] ?? ''),
            'token_scope' => sanitize_text_field($token_data['scope'] ?? ''),
            'token_expires_at' => $expires_at,
            'connected_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OAuth token persisted.', ['expires_at' => $expires_at]);
    }

    private function get_auth_base_url(string $environment): string
    {
        return $environment === 'sandbox' ? 'https://allegro.pl.allegrosandbox.pl' : 'https://allegro.pl';
    }
}
