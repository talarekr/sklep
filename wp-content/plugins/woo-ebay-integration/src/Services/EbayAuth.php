<?php

namespace WEI\Services;

use WEI\Plugin;

class EbayAuth
{
    private const AUTH_URL = 'https://auth.ebay.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
    public const CALLBACK_PAGE_SLUG = 'ebay-auth-callback';
    public const ADMIN_POST_CALLBACK_ACTION = 'wei_ebay_auth_callback';
    public const APP_SCOPE = 'https://api.ebay.com/oauth/api_scope';
    private const SCOPES = 'https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.account https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly';

    public function __construct(private Logger $logger)
    {
    }

    public function get_authorize_url(): string
    {
        $s = $this->settings();
        $state = wp_generate_password(20, false, false);
        set_transient('wei_oauth_state_' . $state, ['user_id' => get_current_user_id()], 10 * MINUTE_IN_SECONDS);

        $oauthRedirectParam = $this->oauth_redirect_param($s);

        $params = [
            'client_id' => $s['client_id'] ?? '',
            'response_type' => 'code',
            'redirect_uri' => $oauthRedirectParam,
            'scope' => self::SCOPES,
            'state' => $state,
        ];

        $this->store_oauth_diagnostics([
            'oauth_status' => ((string) ($s['refresh_token'] ?? '') !== '') ? 'connected' : 'not_connected',
            'oauth_redirect_param_used' => $oauthRedirectParam,
            'token_exchange_attempted' => false,
        ], false);

        return self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function disconnect(): void
    {
        $s = $this->settings();
        $s['access_token'] = '';
        $s['refresh_token'] = '';
        $s['expires_at'] = 0;
        $s['app_access_token'] = '';
        $s['app_access_token_expires_at'] = 0;
        $s['oauth_status'] = 'not_connected';
        $s['token_exchange_success'] = false;
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    public function handle_oauth_callback(): void
    {
        $this->maybe_intercept_oauth_callback('admin_init');
    }

    public function handle_current_screen_oauth_callback(): void
    {
        $this->maybe_intercept_oauth_callback('current_screen');
    }

    public function handle_load_oauth_callback(): void
    {
        $this->maybe_intercept_oauth_callback('load-admin_page_ebay-auth-callback');
    }

    public function handle_woocommerce_load_oauth_callback(): void
    {
        $this->maybe_intercept_oauth_callback('load-woocommerce_page_ebay-auth-callback');
    }

    public function handle_admin_bootstrap_oauth_callback(): void
    {
        $this->maybe_intercept_oauth_callback('plugins_loaded');
    }

    public function handle_admin_post_oauth_callback(): void
    {
        $this->store_intercept_diagnostics('admin_post_' . self::ADMIN_POST_CALLBACK_ACTION, false);
        $this->process_oauth_callback(false);
    }

    public function maybe_intercept_oauth_callback(string $hook): void
    {
        if (!$this->is_oauth_callback_request()) {
            return;
        }

        $this->store_intercept_diagnostics($hook, true);
        $this->process_oauth_callback(true);
        exit;
    }

    public function mark_callback_page_registered(): void
    {
        $this->store_oauth_diagnostics(['callback_page_registered' => true], false);
    }

    public function callback_url(): string
    {
        return admin_url('admin.php?page=' . self::CALLBACK_PAGE_SLUG);
    }

    public function admin_post_callback_url(): string
    {
        return admin_url('admin-post.php?action=' . self::ADMIN_POST_CALLBACK_ACTION);
    }

    public function configured_redirect_uri(): string
    {
        return $this->oauth_redirect_param($this->settings());
    }

    private function process_oauth_callback(bool $interceptedByAdminInit = false): void
    {
        if (!current_user_can('manage_options')) {
            $this->store_oauth_diagnostics([
                'callback_intercepted_by_admin_init' => $interceptedByAdminInit,
                'page_param' => $this->request_value('page'),
                'code_received' => $this->request_value('code') !== '',
                'state_received' => $this->request_value('state') !== '',
                'token_exchange_attempted' => false,
                'token_exchange_success' => false,
                'token_exchange_error' => 'not_wordpress_administrator',
            ]);
            wp_die(esc_html__('Please log in as WordPress administrator and retry eBay Connect.', 'woo-ebay-integration'), esc_html__('eBay OAuth callback', 'woo-ebay-integration'), ['response' => 403]);
        }

        $state = $this->request_value('state');
        $statePayload = $state !== '' ? get_transient('wei_oauth_state_' . $state) : false;
        $stateValid = is_array($statePayload);
        $error = $this->request_value('error');
        $errorDescription = $this->request_value('error_description');
        $expiresIn = $this->request_value('expires_in');
        $code = $this->request_value('code');
        $redirectUri = $this->configured_redirect_uri();

        $this->store_oauth_diagnostics([
            'oauth_status' => $error !== '' ? 'callback_error' : 'callback_received',
            'callback_intercepted_by_admin_init' => $interceptedByAdminInit,
            'page_param' => $this->request_value('page'),
            'code_received' => $code !== '',
            'state_received' => $state !== '',
            'expires_in_received' => $expiresIn,
            'state_valid' => $stateValid,
            'token_exchange_attempted' => false,
            'token_exchange_success' => false,
            'token_exchange_error' => '',
            'refresh_token_saved' => false,
            'oauth_error' => $error,
            'error_description' => $errorDescription,
            'redirect_uri_used' => $redirectUri,
            'oauth_redirect_param_used' => $redirectUri,
        ]);

        if (!$stateValid) {
            $this->redirect_with_error('invalid_state', [
                'oauth_error' => $error !== '' ? $error : 'invalid_state',
                'error_description' => $errorDescription,
                'state_valid' => '0',
                'redirect_uri_used' => $redirectUri,
            ]);
        }

        delete_transient('wei_oauth_state_' . $state);

        if ($error !== '') {
            $this->redirect_with_error($error, [
                'oauth_error' => $error,
                'error_description' => $errorDescription,
                'state_valid' => '1',
                'redirect_uri_used' => $redirectUri,
            ]);
        }

        if ($code === '') {
            $this->redirect_with_error('missing_code', [
                'oauth_error' => 'missing_code',
                'error_description' => $errorDescription,
                'state_valid' => '1',
                'redirect_uri_used' => $redirectUri,
            ]);
        }

        $this->exchange_code($code, $redirectUri);
    }

    public function get_valid_access_token(): string|\WP_Error
    {
        $s = $this->settings();
        $token = (string) ($s['access_token'] ?? '');
        $exp = (int) ($s['expires_at'] ?? 0);
        if ($token !== '' && $exp > (time() + 120)) {
            return $token;
        }

        $refresh = (string) ($s['refresh_token'] ?? '');
        if ($refresh === '') {
            return new \WP_Error('wei_missing_refresh', 'Missing eBay refresh token');
        }

        return $this->refresh_access_token($refresh);
    }


    public function get_valid_application_access_token(): string|\WP_Error
    {
        $s = $this->settings();
        $token = (string) ($s['app_access_token'] ?? '');
        $exp = (int) ($s['app_access_token_expires_at'] ?? 0);
        if ($token !== '' && $exp > (time() + 120)) {
            return $token;
        }

        return $this->request_application_access_token();
    }

    public function get_diagnostic_oauth_context(): array
    {
        $s = $this->settings();
        $hasRefreshToken = (string) ($s['refresh_token'] ?? '') !== '';
        $callbackUrl = $this->callback_url();
        $ebayRuname = $this->ebay_runame($s);
        $oauthRedirectParam = $this->oauth_redirect_param($s);

        return [
            'client_id_configured' => (string) ($s['client_id'] ?? '') !== '',
            'runame_configured' => $ebayRuname !== '',
            'oauth_status' => (string) ($s['oauth_status'] ?? ($hasRefreshToken ? 'connected' : 'not_connected')),
            'has_refresh_token' => $hasRefreshToken,
            'callback_intercepted_by_admin_init' => $s['callback_intercepted_by_admin_init'] ?? null,
            'intercept_hook' => (string) ($s['intercept_hook'] ?? ''),
            'request_uri' => (string) ($s['request_uri'] ?? ''),
            'raw_get_keys' => is_array($s['raw_get_keys'] ?? null) ? $s['raw_get_keys'] : [],
            'callback_page_registered' => $s['callback_page_registered'] ?? false,
            'page_param' => (string) ($s['page_param'] ?? ''),
            'code_received' => $s['code_received'] ?? null,
            'state_received' => $s['state_received'] ?? null,
            'callback_url' => $callbackUrl,
            'browser_callback_url' => $callbackUrl,
            'admin_post_callback_url' => $this->admin_post_callback_url(),
            'ebay_runame' => $ebayRuname,
            'oauth_redirect_param_used' => (string) ($s['oauth_redirect_param_used'] ?? $oauthRedirectParam),
            'redirect_uri_configured' => $oauthRedirectParam,
            'state_valid' => $s['state_valid'] ?? null,
            'token_exchange_attempted' => $s['token_exchange_attempted'] ?? null,
            'token_exchange_success' => $s['token_exchange_success'] ?? null,
            'token_exchange_error' => (string) ($s['token_exchange_error'] ?? ''),
            'refresh_token_saved' => $s['refresh_token_saved'] ?? null,
            'refresh_token_present' => $hasRefreshToken,
            'access_token_present' => (string) ($s['access_token'] ?? '') !== '',
            'access_token_expires_at' => (int) ($s['expires_at'] ?? 0),
            'scope_requested' => self::SCOPES,
            'scope_last_returned' => (string) ($s['scope_last_returned'] ?? ''),
            'required_publish_scopes' => [
                'https://api.ebay.com/oauth/api_scope/sell.inventory',
                'https://api.ebay.com/oauth/api_scope/sell.account',
            ],
        ];
    }

    public function get_taxonomy_oauth_context(): array
    {
        return [
            'token_type' => 'application',
            'grant_type' => 'client_credentials',
            'scope' => self::APP_SCOPE,
            'scope_requested' => self::APP_SCOPE,
        ];
    }

    private function exchange_code(string $code, ?string $redirectUri = null): void
    {
        $s = $this->settings();
        $redirectUri = $redirectUri !== null && $redirectUri !== '' ? $redirectUri : $this->oauth_redirect_param($s);
        $this->store_oauth_diagnostics([
            'oauth_status' => 'token_exchange_attempted',
            'token_exchange_attempted' => true,
            'token_exchange_success' => false,
            'token_exchange_error' => '',
            'oauth_redirect_param_used' => $redirectUri,
            'redirect_uri_used' => $redirectUri,
        ]);
        $auth = base64_encode(($s['client_id'] ?? '') . ':' . ($s['client_secret'] ?? ''));
        $r = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
            ],
        ]);

        if (is_wp_error($r)) {
            $message = $r->get_error_message();
            $this->logger->error('OAuth code exchange failed', ['error' => $message, 'oauth_redirect_param_used' => $redirectUri]);
            $this->store_oauth_diagnostics(['oauth_status' => 'token_exchange_failed', 'token_exchange_attempted' => true, 'token_exchange_success' => false, 'token_exchange_error' => $message, 'state_valid' => true, 'redirect_uri_used' => $redirectUri, 'oauth_redirect_param_used' => $redirectUri, 'refresh_token_saved' => false]);
            $this->redirect_with_error('token_exchange_failed', ['oauth_error' => 'token_exchange_failed', 'error_description' => $message, 'state_valid' => '1', 'redirect_uri_used' => $redirectUri]);
        }

        $status = (int) wp_remote_retrieve_response_code($r);
        $data = (array) json_decode((string) wp_remote_retrieve_body($r), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $message = (string) ($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $status));
            $this->logger->error('OAuth code exchange HTTP error', ['status' => $status, 'response' => $data, 'oauth_redirect_param_used' => $redirectUri]);
            $this->store_oauth_diagnostics(['oauth_status' => 'token_exchange_failed', 'token_exchange_attempted' => true, 'token_exchange_success' => false, 'token_exchange_error' => $message, 'state_valid' => true, 'redirect_uri_used' => $redirectUri, 'oauth_redirect_param_used' => $redirectUri, 'refresh_token_saved' => false]);
            $this->redirect_with_error('token_exchange_http_error', ['oauth_error' => (string) ($data['error'] ?? 'token_exchange_http_error'), 'error_description' => $message, 'state_valid' => '1', 'redirect_uri_used' => $redirectUri]);
        }

        $this->persist_token($data);
        $refreshTokenSaved = (string) ($data['refresh_token'] ?? '') !== '' || (string) ($this->settings()['refresh_token'] ?? '') !== '';
        $this->store_oauth_diagnostics(['oauth_status' => 'connected', 'token_exchange_attempted' => true, 'token_exchange_success' => true, 'token_exchange_error' => '', 'state_valid' => true, 'redirect_uri_used' => $redirectUri, 'oauth_redirect_param_used' => $redirectUri, 'refresh_token_saved' => $refreshTokenSaved]);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&ebay_connected=1&oauth_status=connected'));
        exit;
    }


    private function request_application_access_token(): string|\WP_Error
    {
        $s = $this->settings();
        $clientId = (string) ($s['client_id'] ?? '');
        $clientSecret = (string) ($s['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return new \WP_Error('wei_missing_app_credentials', 'Missing eBay App client_id or client_secret for application access token');
        }

        $this->logger->info('Requesting eBay application access token', $this->get_taxonomy_oauth_context());

        $r = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'scope' => self::APP_SCOPE,
            ],
        ]);

        if (is_wp_error($r)) {
            return $r;
        }

        $status = (int) wp_remote_retrieve_response_code($r);
        $data = (array) json_decode((string) wp_remote_retrieve_body($r), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            return new \WP_Error('wei_app_oauth_http_error', 'eBay application OAuth HTTP error', [
                'status' => $status,
                'response' => $data,
                'token_type' => 'application',
                'grant_type' => 'client_credentials',
                'scope' => self::APP_SCOPE,
                'scope_requested' => self::APP_SCOPE,
            ]);
        }

        $s['app_access_token'] = (string) $data['access_token'];
        $s['app_access_token_expires_at'] = time() + max(0, (int) ($data['expires_in'] ?? 0));
        update_option(Plugin::OPTION_KEY, $s, false);

        return (string) $data['access_token'];
    }

    private function is_oauth_callback_request(): bool
    {
        $getPage = $this->sanitize_raw((string) ($_GET['page'] ?? ''));
        $requestPage = $this->sanitize_raw((string) ($_REQUEST['page'] ?? ''));
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        return $getPage === self::CALLBACK_PAGE_SLUG
            || $requestPage === self::CALLBACK_PAGE_SLUG
            || strpos($requestUri, 'page=' . self::CALLBACK_PAGE_SLUG) !== false;
    }

    private function store_intercept_diagnostics(string $hook, bool $interceptedByAdminInit): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        error_log('WEI OAuth callback intercept hit: request_uri=' . $requestUri . ' hook=' . $hook);

        $this->store_oauth_diagnostics([
            'callback_intercepted_by_admin_init' => $interceptedByAdminInit,
            'intercept_hook' => $hook,
            'request_uri' => $requestUri,
            'page_param' => $this->request_value('page'),
            'raw_get_keys' => array_keys($_GET),
            'code_received' => $this->request_value('code') !== '',
            'state_received' => $this->request_value('state') !== '',
            'expires_in_received' => $this->request_value('expires_in'),
            'token_exchange_attempted' => false,
        ], false);
    }

    private function request_value(string $key): string
    {
        return $this->sanitize_raw((string) ($_REQUEST[$key] ?? $_GET[$key] ?? ''));
    }

    private function sanitize_raw(string $value): string
    {
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
    }

    private function redirect_with_error(string $error, array $extra = []): void
    {
        $args = ['page' => 'woo-ebay', 'ebay_error' => $error];
        foreach (['oauth_error', 'error_description', 'state_valid', 'redirect_uri_used'] as $key) {
            if (array_key_exists($key, $extra)) {
                $args[$key] = is_bool($extra[$key]) ? ($extra[$key] ? '1' : '0') : (string) $extra[$key];
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private function refresh_access_token(string $refresh): string|\WP_Error
    {
        $s = $this->settings();
        $auth = base64_encode(($s['client_id'] ?? '') . ':' . ($s['client_secret'] ?? ''));
        $r = wp_remote_post(self::TOKEN_URL, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh,
                'scope' => self::SCOPES,
            ],
        ]);

        if (is_wp_error($r)) {
            return $r;
        }

        $data = (array) json_decode((string) wp_remote_retrieve_body($r), true);
        $this->persist_token($data);
        return (string) ($data['access_token'] ?? '');
    }

    private function persist_token(array $data): void
    {
        $s = $this->settings();
        $s['access_token'] = (string) ($data['access_token'] ?? '');
        $s['refresh_token'] = (string) ($data['refresh_token'] ?? ($s['refresh_token'] ?? ''));
        $s['expires_at'] = time() + max(0, (int) ($data['expires_in'] ?? 0));
        if ($s['refresh_token'] !== '') {
            $s['oauth_status'] = 'connected';
        }
        if (isset($data['scope'])) {
            $s['scope_last_returned'] = (string) $data['scope'];
        }
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function redirect_uri(array $settings): string
    {
        return $this->oauth_redirect_param($settings);
    }

    private function oauth_redirect_param(array $settings): string
    {
        $runame = $this->ebay_runame($settings);
        if ($runame !== '') {
            return $runame;
        }

        $legacyRedirectUri = trim((string) ($settings['redirect_uri'] ?? ''));
        return $legacyRedirectUri !== '' ? $legacyRedirectUri : $this->callback_url();
    }

    private function ebay_runame(array $settings): string
    {
        $runame = trim((string) ($settings['runame'] ?? ''));
        if ($runame !== '') {
            return $runame;
        }

        $legacyRedirectUri = trim((string) ($settings['redirect_uri'] ?? ''));
        return filter_var($legacyRedirectUri, FILTER_VALIDATE_URL) ? '' : $legacyRedirectUri;
    }

    private function store_oauth_diagnostics(array $diagnostics, bool $touchCallbackTime = true): void
    {
        $s = $this->settings();
        $keys = ['oauth_status', 'callback_intercepted_by_admin_init', 'intercept_hook', 'request_uri', 'raw_get_keys', 'callback_page_registered', 'page_param', 'code_received', 'state_received', 'expires_in_received', 'state_valid', 'token_exchange_attempted', 'token_exchange_success', 'token_exchange_error', 'refresh_token_saved', 'oauth_error', 'error_description', 'redirect_uri_used', 'oauth_redirect_param_used'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $diagnostics)) {
                $s[$key] = $diagnostics[$key];
            }
        }
        if ($touchCallbackTime) {
            $s['last_oauth_callback_at'] = current_time('mysql');
        }
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        return is_array($s) ? $s : [];
    }
}
