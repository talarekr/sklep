<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroAuth
{
    private const TOKEN_REFRESH_LEEWAY_SECONDS = 300;

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function hooks(): void
    {
        add_action('init', [$this, 'handle_oauth_callback']);
    }

    public function get_authorization_url(): string
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);
        $redirect_uri = $this->get_effective_redirect_uri($settings);

        $state = wp_generate_password(20, false, false);
        set_transient('awi_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

        $query = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $redirect_uri,
            'state' => $state,
        ];

        return add_query_arg($query, $base . '/auth/oauth/authorize');
    }

    public function handle_oauth_callback(): void
    {
        if (!$this->is_oauth_callback_request()) {
            return;
        }

        $this->logger->info('OAuth callback started.');

        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            $this->logger->error('OAuth callback rejected due to missing permissions.');
            return;
        }

        $settings = Plugin::get_settings();
        $state_key = 'awi_oauth_state_' . get_current_user_id();
        $saved_state = get_transient($state_key);
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        if (!empty($saved_state)) {
            if ($saved_state !== $state) {
                $this->logger->error('OAuth callback rejected due to invalid state.', ['state_present' => $state !== '']);
                $this->store_admin_notice('error', __('Błędny stan OAuth (state). Spróbuj połączyć konto ponownie.', 'allegro-woo-importer'));
                $this->redirect_to_settings();
            }
            delete_transient($state_key);
        } elseif ($state !== '') {
            $this->logger->warning('OAuth callback state could not be validated (missing transient).');
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $this->logger->info('OAuth callback code presence checked.', ['code_present' => $code !== '']);

        if (empty($code)) {
            $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : 'unknown_error';
            $error_description = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : '';
            $this->logger->error('OAuth callback without code.', ['error' => $error, 'error_description' => $error_description]);

            $message = sprintf(__('Autoryzacja Allegro nieudana: %s', 'allegro-woo-importer'), $error);
            if ($error_description !== '') {
                $message .= ' (' . $error_description . ')';
            }
            $this->store_admin_notice('error', $message);
            $this->redirect_to_settings();
        }

        $token_response = $this->request_token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->get_effective_redirect_uri($settings),
        ]);

        if (is_wp_error($token_response)) {
            $this->logger->error('OAuth token exchange failed.', ['error' => $token_response->get_error_message(), 'details' => $token_response->get_error_data()]);
            $this->store_admin_notice('error', __('Nie udało się pobrać tokena Allegro.', 'allegro-woo-importer'));
            $this->redirect_to_settings();
        }

        $this->persist_token_response($token_response);
        $this->logger->info('OAuth token exchange succeeded.');
        $this->store_admin_notice('success', __('Połączono z Allegro poprawnie.', 'allegro-woo-importer'));
        $this->redirect_to_settings();
    }

    public function get_connection_callback_uri(): string
    {
        $settings = Plugin::get_settings();

        return $this->get_effective_redirect_uri($settings);
    }

    private function is_oauth_callback_request(): bool
    {
        if (!isset($_GET['awi_oauth'])) {
            return false;
        }

        return true;
    }

    public function get_valid_access_token()
    {
        $settings = Plugin::get_settings();
        $access_token = (string) ($settings['access_token'] ?? '');

        if ($access_token === '') {
            return new \WP_Error('awi_missing_token', __('Brak access tokena Allegro.', 'allegro-woo-importer'));
        }

        $expires_at = $this->extract_token_expiration_timestamp($settings);
        if (!$this->should_refresh_access_token($expires_at)) {
            $this->logger->info('OAuth using existing token.', ['expires_at' => $this->extract_token_expiration_display_value($settings)]);
            return $access_token;
        }

        if (empty($settings['refresh_token'])) {
            $this->logger->error('OAuth refresh failed.', ['reason' => 'missing_refresh_token']);
            return $access_token;
        }

        $refresh_response = $this->request_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $settings['refresh_token'],
            'redirect_uri' => $this->get_effective_redirect_uri($settings),
        ]);

        if (is_wp_error($refresh_response)) {
            $this->logger->error('OAuth refresh failed.', ['error' => $refresh_response->get_error_message()]);
            return $access_token;
        }

        $this->persist_token_response($refresh_response);
        $this->logger->info('OAuth token refreshed successfully.');

        $new_settings = Plugin::get_settings();
        $new_access_token = (string) ($new_settings['access_token'] ?? '');
        if ($new_access_token === '') {
            $this->logger->error('OAuth refresh failed.', ['reason' => 'missing_token_after_refresh']);
            return $access_token;
        }

        return $new_access_token;
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
            $this->logger->error('Allegro token endpoint returned invalid response.', ['status' => $status, 'body' => $raw]);
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
            'expires_at' => $expires_at,
            'token_expires_at' => $expires_at,
            'connected_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OAuth token persisted.', ['expires_at' => $expires_at]);
    }

    private function extract_token_expiration_timestamp(array $settings): int
    {
        $raw = (string) ($settings['expires_at'] ?? $settings['token_expires_at'] ?? '');
        if ($raw === '') {
            return 0;
        }

        $timestamp = strtotime($raw);

        return $timestamp === false ? 0 : (int) $timestamp;
    }

    private function extract_token_expiration_display_value(array $settings): string
    {
        $value = (string) ($settings['expires_at'] ?? $settings['token_expires_at'] ?? '');
        return $value !== '' ? $value : 'unknown';
    }

    private function should_refresh_access_token(int $expires_at): bool
    {
        if ($expires_at <= 0) {
            return true;
        }

        return $expires_at <= (time() + self::TOKEN_REFRESH_LEEWAY_SECONDS);
    }

    private function get_auth_base_url(string $environment): string
    {
        return $environment === 'sandbox' ? 'https://allegro.pl.allegrosandbox.pl' : 'https://allegro.pl';
    }

    private function get_effective_redirect_uri(array $settings): string
    {
        $fallback = home_url('/');
        $base = !empty($settings['redirect_uri']) ? (string) $settings['redirect_uri'] : $fallback;

        return add_query_arg('awi_oauth', '1', $base);
    }

    private function redirect_to_settings(): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'awi-settings'], admin_url('admin.php')));
        exit;
    }

    private function store_admin_notice(string $type, string $message): void
    {
        set_transient(
            'awi_admin_notice_' . get_current_user_id(),
            [
                'type' => $type,
                'message' => $message,
            ],
            5 * MINUTE_IN_SECONDS
        );
    }
}
