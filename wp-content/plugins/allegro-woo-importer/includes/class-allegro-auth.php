<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroAuth
{
    private const TOKEN_REFRESH_LEEWAY_SECONDS = 300;
    private const TOKEN_REFRESH_LOCK_OPTION_KEY = 'awi_allegro_token_refresh_lock';
    private const TOKEN_REFRESH_LOCK_TTL_SECONDS = 60;
    private const TOKEN_REFRESH_LOCK_WAIT_SECONDS = 12;
    private const AUTH_HTTP_TIMEOUT_SECONDS = 20;
    private const AUTH_HTTP_REDIRECTION_LIMIT = 3;
    private const AUTH_MAX_RETRY_ATTEMPTS = 3;
    private const AUTH_RETRYABLE_STATUS_CODES = [408, 409, 425, 429, 500, 502, 503, 504];

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

        $this->persist_token_response($token_response, $settings);
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
            $this->logger->info('OAuth using existing token.', [
                'expires_at' => $this->extract_token_expiration_display_value($settings),
                'source' => 'oauth_get_valid_access_token',
            ]);
            return $access_token;
        }

        $seconds_to_expiry = $expires_at > 0 ? max(0, $expires_at - time()) : -1;
        $this->logger->warning('TOKEN_EXPIRES_SOON', [
            'expires_at' => $this->extract_token_expiration_display_value($settings),
            'seconds_to_expiry' => $seconds_to_expiry,
            'leeway_seconds' => self::TOKEN_REFRESH_LEEWAY_SECONDS,
        ]);

        $refreshed_token = $this->refresh_access_token_with_lock();
        if (is_wp_error($refreshed_token)) {
            return $refreshed_token;
        }

        return $refreshed_token;
    }

    private function request_token(array $body)
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);

        $credentials = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);

        $url = $base . '/auth/oauth/token';
        $args = [
            'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
            'redirection' => self::AUTH_HTTP_REDIRECTION_LIMIT,
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ];
        $request_started_at = microtime(true);
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error('OAuth token request failed before response.', [
                'request_type' => 'allegro_api',
                'endpoint' => '/auth/oauth/token',
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                'elapsed_time' => round(max(0, microtime(true) - $request_started_at), 3),
                'http_code' => 0,
                'error_reason' => $response->get_error_message(),
            ]);
            return $response;
        }

        for ($attempt = 1; $attempt <= self::AUTH_MAX_RETRY_ATTEMPTS; $attempt++) {
            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = wp_remote_retrieve_body($response);
            $data = json_decode($raw, true);
            $elapsed_time = round(max(0, microtime(true) - $request_started_at), 3);

            if ($status >= 200 && $status <= 299 && is_array($data)) {
                $this->logger->info('OAuth token request completed.', [
                    'request_type' => 'allegro_api',
                    'endpoint' => '/auth/oauth/token',
                    'host' => (string) parse_url($url, PHP_URL_HOST),
                    'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                    'elapsed_time' => $elapsed_time,
                    'http_code' => $status,
                    'error_reason' => '',
                    'attempt' => $attempt,
                ]);
                return $data;
            }

            $is_retryable = in_array($status, self::AUTH_RETRYABLE_STATUS_CODES, true);
            $has_next_attempt = $attempt < self::AUTH_MAX_RETRY_ATTEMPTS;
            if (!$is_retryable || !$has_next_attempt) {
                $this->logger->error('Allegro token endpoint returned invalid response.', [
                    'request_type' => 'allegro_api',
                    'endpoint' => '/auth/oauth/token',
                    'host' => (string) parse_url($url, PHP_URL_HOST),
                    'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                    'elapsed_time' => $elapsed_time,
                    'http_code' => $status,
                    'error_reason' => !is_array($data) ? 'invalid_json' : 'http_status_non_success',
                    'attempt' => $attempt,
                    'body' => $raw,
                ]);
                return new \WP_Error('awi_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'allegro-woo-importer'), ['status' => $status, 'body' => $raw]);
            }

            $sleep_seconds = (int) pow(2, max(0, $attempt - 1));
            $sleep_seconds = max(1, min(10, $sleep_seconds));
            $this->logger->warning('Retrying OAuth token request after retryable response.', [
                'request_type' => 'allegro_api',
                'endpoint' => '/auth/oauth/token',
                'host' => (string) parse_url($url, PHP_URL_HOST),
                'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                'elapsed_time' => $elapsed_time,
                'http_code' => $status,
                'error_reason' => 'retryable_http_status',
                'attempt' => $attempt,
                'retry_after_seconds' => $sleep_seconds,
            ]);

            sleep($sleep_seconds);
            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                $this->logger->error('OAuth token request retry failed before response.', [
                    'request_type' => 'allegro_api',
                    'endpoint' => '/auth/oauth/token',
                    'host' => (string) parse_url($url, PHP_URL_HOST),
                    'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                    'elapsed_time' => round(max(0, microtime(true) - $request_started_at), 3),
                    'http_code' => 0,
                    'error_reason' => $response->get_error_message(),
                    'attempt' => $attempt + 1,
                ]);
                return $response;
            }
        }

        return new \WP_Error('awi_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'allegro-woo-importer'));
    }

    private function persist_token_response(array $token_data, array $current_settings): void
    {
        $access_token = sanitize_text_field((string) ($token_data['access_token'] ?? ''));
        if ($access_token === '') {
            $this->logger->error('TOKEN_REFRESH_FAILED', ['reason' => 'missing_access_token_in_response']);
            return;
        }

        $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 0;
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(0, $expires_in));

        $update_payload = [
            'access_token' => $access_token,
            'token_type' => sanitize_text_field($token_data['token_type'] ?? ''),
            'token_scope' => sanitize_text_field($token_data['scope'] ?? ''),
            'expires_at' => $expires_at,
            'token_expires_at' => $expires_at,
            'connected_at' => gmdate('Y-m-d H:i:s'),
        ];

        $new_refresh_token = sanitize_text_field((string) ($token_data['refresh_token'] ?? ''));
        if ($new_refresh_token !== '') {
            $update_payload['refresh_token'] = $new_refresh_token;
        } elseif (!empty($current_settings['refresh_token'])) {
            $update_payload['refresh_token'] = sanitize_text_field((string) $current_settings['refresh_token']);
        }

        Plugin::update_settings($update_payload);

        $this->logger->info('OAuth token persisted.', ['expires_at' => $expires_at]);
    }

    private function refresh_access_token_with_lock()
    {
        $owner = function_exists('wp_generate_uuid4') ? (string) wp_generate_uuid4() : uniqid('awi_refresh_', true);
        $lock_acquired = $this->acquire_refresh_lock($owner);

        if (!$lock_acquired) {
            $this->logger->warning('TOKEN_REFRESH_START', ['status' => 'lock_exists_waiting']);
            $token_after_wait = $this->wait_for_refresh_result();
            if (!is_wp_error($token_after_wait)) {
                return $token_after_wait;
            }

            if (!$this->acquire_refresh_lock($owner)) {
                return $token_after_wait;
            }
        }

        try {
            return $this->execute_refresh_token_request();
        } finally {
            $this->release_refresh_lock($owner);
        }
    }

    private function execute_refresh_token_request()
    {
        $settings = Plugin::get_settings();
        $refresh_token = sanitize_text_field((string) ($settings['refresh_token'] ?? ''));
        if ($refresh_token === '') {
            $this->logger->error('TOKEN_MISSING_REFRESH_TOKEN');
            return new \WP_Error('awi_missing_refresh_token', __('Brak refresh tokena Allegro.', 'allegro-woo-importer'));
        }

        $this->logger->info('TOKEN_REFRESH_START', [
            'expires_at' => $this->extract_token_expiration_display_value($settings),
        ]);

        $refresh_response = $this->request_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh_token,
            'redirect_uri' => $this->get_effective_redirect_uri($settings),
        ]);

        if (is_wp_error($refresh_response)) {
            $this->logger->error('TOKEN_REFRESH_FAILED', ['error' => $refresh_response->get_error_message()]);
            return new \WP_Error('awi_token_refresh_failed', __('Nie udało się odświeżyć tokena Allegro.', 'allegro-woo-importer'), [
                'error' => $refresh_response->get_error_message(),
            ]);
        }

        $this->persist_token_response($refresh_response, $settings);

        $new_settings = Plugin::get_settings();
        $new_access_token = sanitize_text_field((string) ($new_settings['access_token'] ?? ''));
        if ($new_access_token === '') {
            $this->logger->error('TOKEN_REFRESH_FAILED', ['reason' => 'missing_access_token_after_persist']);
            return new \WP_Error('awi_missing_refreshed_token', __('Brak nowego access tokena po odświeżeniu.', 'allegro-woo-importer'));
        }

        $this->logger->info('TOKEN_REFRESH_SUCCESS', [
            'expires_at' => $this->extract_token_expiration_display_value($new_settings),
            'refresh_token_updated' => !empty($refresh_response['refresh_token']),
        ]);

        return $new_access_token;
    }

    private function wait_for_refresh_result()
    {
        $deadline = time() + self::TOKEN_REFRESH_LOCK_WAIT_SECONDS;

        while ($this->is_refresh_lock_active() && time() < $deadline) {
            usleep(250000);
        }

        $settings = Plugin::get_settings();
        $access_token = sanitize_text_field((string) ($settings['access_token'] ?? ''));
        $expires_at = $this->extract_token_expiration_timestamp($settings);
        if ($access_token !== '' && !$this->should_refresh_access_token($expires_at)) {
            return $access_token;
        }

        return new \WP_Error('awi_token_refresh_timeout', __('Nie udało się odświeżyć tokena Allegro na czas.', 'allegro-woo-importer'));
    }

    private function acquire_refresh_lock(string $owner): bool
    {
        $now = time();
        $lock_payload = [
            'owner' => $owner,
            'expires_at' => $now + self::TOKEN_REFRESH_LOCK_TTL_SECONDS,
            'created_at' => $now,
        ];

        if (add_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY, $lock_payload, '', false)) {
            return true;
        }

        $existing = get_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY, []);
        $expires_at = is_array($existing) ? (int) ($existing['expires_at'] ?? 0) : 0;
        if ($expires_at > 0 && $expires_at <= $now) {
            delete_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY);
            return add_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY, $lock_payload, '', false);
        }

        return false;
    }

    private function release_refresh_lock(string $owner): void
    {
        $existing = get_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY, []);
        $existing_owner = is_array($existing) ? (string) ($existing['owner'] ?? '') : '';
        if ($existing_owner === $owner) {
            delete_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY);
        }
    }

    private function is_refresh_lock_active(): bool
    {
        $existing = get_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY, []);
        if (!is_array($existing) || $existing === []) {
            return false;
        }

        $expires_at = (int) ($existing['expires_at'] ?? 0);
        if ($expires_at <= 0) {
            return false;
        }

        if ($expires_at <= time()) {
            delete_option(self::TOKEN_REFRESH_LOCK_OPTION_KEY);
            return false;
        }

        return true;
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
