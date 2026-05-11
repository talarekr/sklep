<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroAuth
{
    private const REQUIRED_OAUTH_SCOPES = [
        'allegro:api:sale:offers:read',
        'allegro:api:sale:offers:write',
        'allegro:api:orders:read',
    ];
    public const ORDER_EVENTS_REQUIRED_SCOPE = 'allegro:api:orders:read';
    private const TOKEN_REFRESH_LEEWAY_SECONDS = 300;
    private const TOKEN_REFRESH_LOCK_OPTION_KEY = 'gag_allegro_token_refresh_lock';
    private const TOKEN_REFRESH_LOCK_TTL_SECONDS = 60;
    private const TOKEN_REFRESH_LOCK_WAIT_SECONDS = 12;
    private const AUTH_HTTP_TIMEOUT_SECONDS = 20;
    private const AUTH_HTTP_REDIRECTION_LIMIT = 3;
    private const AUTH_MAX_RETRY_ATTEMPTS = 3;
    private const AUTH_RETRYABLE_STATUS_CODES = [408, 409, 425, 429, 500, 502, 503, 504];
    private const AUTH_PRODUCTION_BASE_URL = 'https://allegro.pl';
    private const AUTH_SANDBOX_BASE_URL = 'https://allegro.pl.allegrosandbox.pl';
    private const TOKEN_ENDPOINT_PATH = '/auth/oauth/token';
    private const OAUTH_STATE_TRANSIENT_PREFIX = 'gag_oauth_state_';
    private const OAUTH_STATE_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;
    private const OAUTH_STATE_MAX_ACTIVE = 5;
    private const OAUTH_CALLBACK_MARKER = 'callback';
    private const OAUTH_CALLBACK_PAGE_SLUG = 'allegro-gearboxes';
    private const AUTH_AUTHORIZE_ENDPOINT_PATH = '/auth/oauth/authorize';
    private const OAUTH_CODE_EXCHANGE_LOCK_PREFIX = 'gag_oauth_code_exchange_';
    private const OAUTH_CODE_EXCHANGE_LOCK_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

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
        return $this->prepare_authorization_url(true);
    }

    private function prepare_authorization_url(bool $store_state): string
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);
        $redirect_uri = $this->get_effective_redirect_uri($settings);

        $state = wp_generate_password(32, false, false);
        if ($store_state) {
            $this->store_oauth_state($state, $redirect_uri);

            $this->logger->info('OAuth authorization URL prepared.', $this->build_safe_redirect_uri_diagnostic($redirect_uri, $redirect_uri) + [
                'client_id_hash' => $this->hash_diagnostic_value((string) ($settings['client_id'] ?? '')),
                'client_id_length' => strlen((string) ($settings['client_id'] ?? '')),
                'client_secret_present' => !empty($settings['client_secret']),
                'client_secret_length' => strlen((string) ($settings['client_secret'] ?? '')),
            ]);
        }

        $query = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'scope' => implode(' ', self::REQUIRED_OAUTH_SCOPES),
        ];

        return add_query_arg($query, $base . self::AUTH_AUTHORIZE_ENDPOINT_PATH);
    }

    public function handle_oauth_callback(): void
    {
        if (!$this->is_oauth_callback_request()) {
            return;
        }

        $this->logger->info('OAuth callback started.', $this->build_safe_callback_diagnostic());

        if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
            $this->logger->error('OAuth callback rejected due to missing permissions.', [
                'is_user_logged_in' => is_user_logged_in(),
                'can_manage_woocommerce' => current_user_can('manage_woocommerce'),
                'is_admin_request' => is_admin(),
            ]);
            return;
        }

        $settings = Plugin::get_settings();
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';

        $stored_oauth_state = $this->consume_oauth_state($state);
        if ($stored_oauth_state === null) {
            $this->logger->error('OAuth callback rejected due to invalid state.', $this->build_safe_callback_error_diagnostic() + ['state_present' => $state !== '']);
            $this->store_admin_notice('error', __('Błędny stan OAuth (state). Spróbuj połączyć konto ponownie.', 'gpswiss-allegro-gearboxes'));
            $this->redirect_to_settings();
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $this->logger->info('OAuth callback code presence checked.', ['code_present' => $code !== '']);

        if (empty($code)) {
            $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : 'unknown_error';
            $error_description = isset($_GET['error_description']) ? sanitize_text_field(wp_unslash($_GET['error_description'])) : '';
            $this->logger->error('OAuth callback without code.', $this->build_safe_callback_error_diagnostic() + [
                'error' => $error,
                'error_description' => $error_description,
                'state_present' => $state !== '',
            ]);

            $message = sprintf(__('Autoryzacja Allegro nieudana: %s', 'gpswiss-allegro-gearboxes'), $error);
            if ($error_description !== '') {
                $message .= ' (' . $error_description . ')';
            }
            $this->store_admin_notice('error', $message);
            $this->redirect_to_settings();
        }

        $authorize_redirect_uri = (string) ($stored_oauth_state['redirect_uri'] ?? '');
        $token_exchange_redirect_uri = $this->get_effective_redirect_uri($settings);
        $redirect_uri_diagnostic = $this->build_safe_redirect_uri_diagnostic($authorize_redirect_uri, $token_exchange_redirect_uri);
        $this->logger->info('OAuth redirect URI diagnostic.', $redirect_uri_diagnostic);

        if (!$this->acquire_authorization_code_exchange_lock($code)) {
            $this->logger->warning('OAuth authorization code exchange skipped because code was already handled.', $redirect_uri_diagnostic);
            $this->store_admin_notice('error', __('Ten kod autoryzacyjny Allegro został już obsłużony. Kliknij „Połącz z Allegro” ponownie.', 'gpswiss-allegro-gearboxes'));
            $this->redirect_to_settings();
        }

        $token_response = $this->request_token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $token_exchange_redirect_uri,
        ], $redirect_uri_diagnostic);

        if (is_wp_error($token_response)) {
            $error_data = $token_response->get_error_data();
            $this->logger->error('OAuth token exchange failed.', array_merge([
                'error' => $token_response->get_error_message(),
                'http_code' => is_array($error_data) ? (int) ($error_data['http_code'] ?? $error_data['status'] ?? 0) : 0,
            ], is_array($error_data) ? $error_data : []));
            $this->store_admin_notice('error', __('Nie udało się pobrać tokena Allegro.', 'gpswiss-allegro-gearboxes'));
            $this->redirect_to_settings();
        }

        $this->persist_token_response($token_response, $settings);
        $this->logger->info('OAuth token exchange succeeded.');
        $this->store_admin_notice('success', __('Połączono z Allegro poprawnie.', 'gpswiss-allegro-gearboxes'));
        $this->redirect_to_settings();
    }

    public function get_connection_callback_uri(): string
    {
        $settings = Plugin::get_settings();

        return $this->get_effective_redirect_uri($settings);
    }

    public function build_safe_authorization_url_diagnostic(string $authorization_url): array
    {
        $parts = wp_parse_url($authorization_url);
        if (!is_array($parts)) {
            return [
                'authorize_url_present' => $authorization_url !== '',
                'authorize_url_parseable' => false,
                'authorize_url_scheme' => '',
                'authorize_url_host' => '',
                'authorize_url_path' => '',
                'authorize_url_query_keys' => [],
                'authorize_url_has_client_id' => false,
                'authorize_url_has_redirect_uri' => false,
                'authorize_url_has_response_type' => false,
                'authorize_url_has_state' => false,
                'authorize_url_has_scope' => false,
                'authorize_url_redirect_uri_hash' => '',
                'authorize_url_redirect_uri_length' => 0,
                'authorize_url_is_expected_allegro_endpoint' => false,
            ];
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $query_keys = array_map('strval', array_keys($query));
        sort($query_keys);
        $redirect_uri = isset($query['redirect_uri']) && is_scalar($query['redirect_uri']) ? (string) $query['redirect_uri'] : '';
        $scheme = isset($parts['scheme']) ? (string) $parts['scheme'] : '';
        $host = isset($parts['host']) ? (string) $parts['host'] : '';
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        return [
            'authorize_url_present' => $authorization_url !== '',
            'authorize_url_parseable' => true,
            'authorize_url_scheme' => $scheme,
            'authorize_url_host' => $host,
            'authorize_url_path' => $path,
            'authorize_url_query_keys' => $query_keys,
            'authorize_url_has_client_id' => !empty($query['client_id']),
            'authorize_url_has_redirect_uri' => $redirect_uri !== '',
            'authorize_url_has_response_type' => !empty($query['response_type']),
            'authorize_url_has_state' => !empty($query['state']),
            'authorize_url_has_scope' => !empty($query['scope']),
            'authorize_url_redirect_uri_hash' => $this->hash_diagnostic_value($redirect_uri),
            'authorize_url_redirect_uri_length' => strlen($redirect_uri),
            'authorize_url_is_expected_allegro_endpoint' => $scheme === 'https'
                && in_array($host, ['allegro.pl', 'allegro.pl.allegrosandbox.pl'], true)
                && $path === self::AUTH_AUTHORIZE_ENDPOINT_PATH,
        ];
    }

    public function has_required_order_events_scope(?string $token_scope): bool
    {
        $scopes = $this->normalize_scope_list($token_scope);
        return in_array(self::ORDER_EVENTS_REQUIRED_SCOPE, $scopes, true);
    }

    private function normalize_scope_list(?string $scope_value): array
    {
        $scope_value = trim((string) $scope_value);
        if ($scope_value === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $scope_value);
        if (!is_array($parts)) {
            return [];
        }

        $scopes = [];
        foreach ($parts as $scope) {
            $scope = sanitize_text_field((string) $scope);
            if ($scope === '') {
                continue;
            }

            $scopes[] = $scope;
        }

        return array_values(array_unique($scopes));
    }

    private function is_oauth_callback_request(): bool
    {
        $oauth_marker = isset($_GET['gag_oauth']) ? sanitize_key((string) wp_unslash($_GET['gag_oauth'])) : '';
        if ($oauth_marker !== self::OAUTH_CALLBACK_MARKER) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        $has_oauth_response = isset($_GET['code']) || isset($_GET['error']);
        $is_supported_callback_location = !is_admin() || $page === self::OAUTH_CALLBACK_PAGE_SLUG || $page === 'gag-settings';

        return $is_supported_callback_location && $has_oauth_response;
    }

    public function get_valid_access_token()
    {
        $settings = Plugin::get_settings();
        $access_token = (string) ($settings['access_token'] ?? '');

        if ($access_token === '') {
            return new \WP_Error('gag_missing_token', __('Brak access tokena Allegro.', 'gpswiss-allegro-gearboxes'));
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

    private function request_token(array $body, array $safe_diagnostic_context = [])
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);

        $credentials = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
        $is_authorization_code_grant = ($body['grant_type'] ?? '') === 'authorization_code';
        $max_attempts = $is_authorization_code_grant ? 1 : self::AUTH_MAX_RETRY_ATTEMPTS;

        $url = $base . self::TOKEN_ENDPOINT_PATH;
        $host = (string) parse_url($url, PHP_URL_HOST);
        $args = [
            'method' => 'POST',
            'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
            'redirection' => self::AUTH_HTTP_REDIRECTION_LIMIT,
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $this->build_token_request_body($body),
        ];
        $request_started_at = microtime(true);
        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            $diagnostic = $this->build_safe_token_error_diagnostic($url, 0, '', null, array_merge($safe_diagnostic_context, [
                'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                'elapsed_time' => round(max(0, microtime(true) - $request_started_at), 3),
                'error_reason' => $response->get_error_message(),
                'attempt' => 1,
                'grant_type' => sanitize_key((string) ($body['grant_type'] ?? '')),
            ]));
            $this->logger->error('OAuth token request failed before response.', $diagnostic);
            return new \WP_Error($response->get_error_code(), $response->get_error_message(), $diagnostic);
        }

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            $status = (int) wp_remote_retrieve_response_code($response);
            $raw = (string) wp_remote_retrieve_body($response);
            $data = json_decode($raw, true);
            $elapsed_time = round(max(0, microtime(true) - $request_started_at), 3);

            if ($status >= 200 && $status <= 299 && is_array($data)) {
                $this->logger->info('OAuth token request completed.', [
                    'request_type' => 'allegro_api',
                    'endpoint' => self::TOKEN_ENDPOINT_PATH,
                    'host' => $host,
                    'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                    'elapsed_time' => $elapsed_time,
                    'http_code' => $status,
                    'error_reason' => '',
                    'attempt' => $attempt,
                    'grant_type' => sanitize_key((string) ($body['grant_type'] ?? '')),
                ]);
                return $data;
            }

            $diagnostic = $this->build_safe_token_error_diagnostic($url, $status, $raw, is_array($data) ? $data : null, array_merge($safe_diagnostic_context, [
                'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                'elapsed_time' => $elapsed_time,
                'error_reason' => !is_array($data) ? 'invalid_json' : 'http_status_non_success',
                'attempt' => $attempt,
                'grant_type' => sanitize_key((string) ($body['grant_type'] ?? '')),
            ]));

            $is_retryable = !$is_authorization_code_grant && in_array($status, self::AUTH_RETRYABLE_STATUS_CODES, true);
            $has_next_attempt = $attempt < $max_attempts;
            if (!$is_retryable || !$has_next_attempt) {
                $this->logger->error('Allegro token endpoint returned invalid response.', $diagnostic);
                return new \WP_Error('gag_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'gpswiss-allegro-gearboxes'), $diagnostic);
            }

            $sleep_seconds = (int) pow(2, max(0, $attempt - 1));
            $sleep_seconds = max(1, min(10, $sleep_seconds));
            $this->logger->warning('Retrying OAuth token request after retryable response.', array_merge($diagnostic, [
                'error_reason' => 'retryable_http_status',
                'retry_after_seconds' => $sleep_seconds,
            ]));

            sleep($sleep_seconds);
            $response = wp_remote_post($url, $args);
            if (is_wp_error($response)) {
                $diagnostic = $this->build_safe_token_error_diagnostic($url, 0, '', null, array_merge($safe_diagnostic_context, [
                    'timeout' => self::AUTH_HTTP_TIMEOUT_SECONDS,
                    'elapsed_time' => round(max(0, microtime(true) - $request_started_at), 3),
                    'error_reason' => $response->get_error_message(),
                    'attempt' => $attempt + 1,
                    'grant_type' => sanitize_key((string) ($body['grant_type'] ?? '')),
                ]));
                $this->logger->error('OAuth token request retry failed before response.', $diagnostic);
                return new \WP_Error($response->get_error_code(), $response->get_error_message(), $diagnostic);
            }
        }

        return new \WP_Error('gag_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'gpswiss-allegro-gearboxes'));
    }

    private function build_token_request_body(array $body): string
    {
        $allowed_keys = ['grant_type', 'code', 'redirect_uri', 'refresh_token'];
        $filtered_body = [];

        foreach ($allowed_keys as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }

            $filtered_body[$key] = $key === 'redirect_uri'
                ? $this->normalize_redirect_uri((string) $body[$key])
                : (string) $body[$key];
        }

        return http_build_query($filtered_body, '', '&', PHP_QUERY_RFC3986);
    }

    private function build_safe_token_error_diagnostic(string $url, int $status, string $raw_body, ?array $decoded_body, array $extra = []): array
    {
        $diagnostic = [
            'http_code' => $status,
            'endpoint' => self::TOKEN_ENDPOINT_PATH,
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'error' => '',
            'error_description' => '',
            'body_present' => $raw_body !== '',
            'body_length' => strlen($raw_body),
        ];

        if (is_array($decoded_body)) {
            $diagnostic['error'] = $this->sanitize_token_error_field($decoded_body['error'] ?? '');
            $diagnostic['error_description'] = $this->sanitize_token_error_field($decoded_body['error_description'] ?? '');
        }

        return array_merge($diagnostic, $extra);
    }

    private function sanitize_token_error_field($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = sanitize_text_field((string) $value);
        $value = preg_replace('/(access_token|refresh_token|client_secret|authorization)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $value);

        return is_string($value) ? substr($value, 0, 300) : '';
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
            'gag_order_events_access_denied_notice' => 0,
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
        $owner = function_exists('wp_generate_uuid4') ? (string) wp_generate_uuid4() : uniqid('gag_refresh_', true);
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
            return new \WP_Error('gag_missing_refresh_token', __('Brak refresh tokena Allegro.', 'gpswiss-allegro-gearboxes'));
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
            return new \WP_Error('gag_token_refresh_failed', __('Nie udało się odświeżyć tokena Allegro.', 'gpswiss-allegro-gearboxes'), [
                'error' => $refresh_response->get_error_message(),
            ]);
        }

        $this->persist_token_response($refresh_response, $settings);

        $new_settings = Plugin::get_settings();
        $new_access_token = sanitize_text_field((string) ($new_settings['access_token'] ?? ''));
        if ($new_access_token === '') {
            $this->logger->error('TOKEN_REFRESH_FAILED', ['reason' => 'missing_access_token_after_persist']);
            return new \WP_Error('gag_missing_refreshed_token', __('Brak nowego access tokena po odświeżeniu.', 'gpswiss-allegro-gearboxes'));
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

        return new \WP_Error('gag_token_refresh_timeout', __('Nie udało się odświeżyć tokena Allegro na czas.', 'gpswiss-allegro-gearboxes'));
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
        return $environment === 'sandbox' ? self::AUTH_SANDBOX_BASE_URL : self::AUTH_PRODUCTION_BASE_URL;
    }

    private function get_effective_redirect_uri(array $settings): string
    {
        $base = !empty($settings['redirect_uri'])
            ? $this->normalize_redirect_uri((string) $settings['redirect_uri'])
            : home_url('/');

        if ($this->is_legacy_admin_callback_uri($base)) {
            $base = home_url('/');
        }

        return add_query_arg('gag_oauth', self::OAUTH_CALLBACK_MARKER, $base);
    }

    private function is_legacy_admin_callback_uri(string $redirect_uri): bool
    {
        $parts = wp_parse_url($redirect_uri);
        if (!is_array($parts)) {
            return false;
        }

        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        if (substr($path, -19) !== '/wp-admin/admin.php' && $path !== '/admin.php') {
            return false;
        }

        $query = [];
        if (isset($parts['query']) && is_string($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $page = isset($query['page']) && is_scalar($query['page']) ? sanitize_key((string) $query['page']) : '';

        return $page === self::OAUTH_CALLBACK_PAGE_SLUG || $page === 'gag-settings';
    }

    private function normalize_redirect_uri(string $redirect_uri): string
    {
        $redirect_uri = trim(html_entity_decode($redirect_uri, ENT_QUOTES, 'UTF-8'));

        for ($i = 0; $i < 3 && strpos($redirect_uri, '://') === false; $i++) {
            $decoded_redirect_uri = rawurldecode($redirect_uri);
            if ($decoded_redirect_uri === $redirect_uri) {
                break;
            }

            $redirect_uri = $decoded_redirect_uri;
        }

        return $redirect_uri;
    }

    private function build_safe_callback_diagnostic(): array
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) && is_scalar($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';

        return [
            'callback_request_path' => (string) wp_parse_url($request_uri, PHP_URL_PATH),
            'callback_query_keys' => $this->get_safe_current_query_keys(),
            'error' => isset($_GET['error']) ? $this->sanitize_log_field(wp_unslash($_GET['error'])) : '',
            'error_description' => isset($_GET['error_description']) ? $this->sanitize_log_field(wp_unslash($_GET['error_description'])) : '',
            'state_present' => isset($_GET['state']) && (string) wp_unslash($_GET['state']) !== '',
        ];
    }

    private function build_safe_callback_error_diagnostic(): array
    {
        return [
            'error' => isset($_GET['error']) ? $this->sanitize_log_field(wp_unslash($_GET['error'])) : '',
            'error_description' => isset($_GET['error_description']) ? $this->sanitize_log_field(wp_unslash($_GET['error_description'])) : '',
            'state_present' => isset($_GET['state']) && (string) wp_unslash($_GET['state']) !== '',
        ];
    }

    private function get_safe_current_query_keys(): array
    {
        $keys = array_map('strval', array_keys($_GET));
        sort($keys);

        return $keys;
    }

    private function build_safe_redirect_uri_diagnostic(string $authorize_redirect_uri, string $token_exchange_redirect_uri): array
    {
        return [
            'authorize_redirect_uri_hash' => $this->hash_diagnostic_value($authorize_redirect_uri),
            'token_exchange_redirect_uri_hash' => $this->hash_diagnostic_value($token_exchange_redirect_uri),
            'authorize_redirect_uri_length' => strlen($authorize_redirect_uri),
            'token_exchange_redirect_uri_length' => strlen($token_exchange_redirect_uri),
            'redirect_uri_match' => hash_equals($authorize_redirect_uri, $token_exchange_redirect_uri),
        ];
    }

    private function hash_diagnostic_value(string $value): string
    {
        return $value === '' ? '' : hash('sha256', $value);
    }

    private function acquire_authorization_code_exchange_lock(string $code): bool
    {
        $code_hash = $this->hash_diagnostic_value($code);
        if ($code_hash === '') {
            return false;
        }

        $option_key = self::OAUTH_CODE_EXCHANGE_LOCK_PREFIX . $code_hash;
        $now = time();
        $payload = [
            'created_at' => $now,
            'expires_at' => $now + self::OAUTH_CODE_EXCHANGE_LOCK_TTL_SECONDS,
        ];

        if (add_option($option_key, $payload, '', false)) {
            return true;
        }

        $existing = get_option($option_key, []);
        $expires_at = is_array($existing) ? (int) ($existing['expires_at'] ?? 0) : 0;
        if ($expires_at > 0 && $expires_at <= $now) {
            delete_option($option_key);
            return add_option($option_key, $payload, '', false);
        }

        return false;
    }

    private function get_oauth_state_key(): string
    {
        return self::OAUTH_STATE_TRANSIENT_PREFIX . get_current_user_id();
    }

    private function store_oauth_state(string $state, string $redirect_uri): void
    {
        $states = $this->get_stored_oauth_states();
        array_unshift($states, [
            'state' => $state,
            'created_at' => time(),
            'redirect_uri' => $redirect_uri,
        ]);

        $states = array_slice($states, 0, self::OAUTH_STATE_MAX_ACTIVE);
        set_transient($this->get_oauth_state_key(), $states, self::OAUTH_STATE_TTL_SECONDS);
    }

    private function consume_oauth_state(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $states = $this->get_stored_oauth_states();
        $remaining_states = [];
        $matched_state = null;
        $now = time();

        foreach ($states as $stored_state) {
            $stored_value = sanitize_text_field((string) ($stored_state['state'] ?? ''));
            $created_at = (int) ($stored_state['created_at'] ?? 0);
            $redirect_uri = $this->normalize_redirect_uri((string) ($stored_state['redirect_uri'] ?? ''));
            if ($stored_value === '' || $created_at <= 0 || ($created_at + self::OAUTH_STATE_TTL_SECONDS) < $now) {
                continue;
            }

            if ($matched_state === null && hash_equals($stored_value, $state)) {
                $matched_state = $stored_state;
                continue;
            }

            $remaining_states[] = [
                'state' => $stored_value,
                'created_at' => $created_at,
                'redirect_uri' => $redirect_uri,
            ];
        }

        if ($remaining_states !== []) {
            set_transient($this->get_oauth_state_key(), array_slice($remaining_states, 0, self::OAUTH_STATE_MAX_ACTIVE), self::OAUTH_STATE_TTL_SECONDS);
        } else {
            delete_transient($this->get_oauth_state_key());
        }

        return $matched_state;
    }

    private function get_stored_oauth_states(): array
    {
        $stored = get_transient($this->get_oauth_state_key());
        if (!is_array($stored)) {
            if (is_string($stored) && $stored !== '') {
                return [[
                    'state' => sanitize_text_field($stored),
                    'created_at' => time(),
                    'redirect_uri' => '',
                ]];
            }

            return [];
        }

        $states = [];
        foreach ($stored as $entry) {
            if (is_string($entry) && $entry !== '') {
                $states[] = [
                    'state' => sanitize_text_field($entry),
                    'created_at' => time(),
                    'redirect_uri' => '',
                ];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $state = sanitize_text_field((string) ($entry['state'] ?? ''));
            $created_at = (int) ($entry['created_at'] ?? 0);
            $redirect_uri = $this->normalize_redirect_uri((string) ($entry['redirect_uri'] ?? ''));
            if ($state === '' || $created_at <= 0) {
                continue;
            }

            $states[] = [
                'state' => $state,
                'created_at' => $created_at,
                'redirect_uri' => $redirect_uri,
            ];
        }

        return array_slice($states, 0, self::OAUTH_STATE_MAX_ACTIVE);
    }

    private function redirect_to_settings(): void
    {
        wp_safe_redirect(add_query_arg(['page' => 'gag-settings'], admin_url('admin.php')));
        exit;
    }

    private function store_admin_notice(string $type, string $message): void
    {
        set_transient(
            'gag_admin_notice_' . get_current_user_id(),
            [
                'type' => $type,
                'message' => $message,
            ],
            5 * MINUTE_IN_SECONDS
        );
    }
}
