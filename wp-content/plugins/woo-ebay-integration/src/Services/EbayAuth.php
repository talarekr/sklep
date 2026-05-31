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

        $params = [
            'client_id' => $s['client_id'] ?? '',
            'response_type' => 'code',
            'redirect_uri' => $this->redirect_uri($s),
            'scope' => self::SCOPES,
            'state' => $state,
        ];

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
        $page = sanitize_text_field((string) ($_GET['page'] ?? ''));
        if ($page !== self::CALLBACK_PAGE_SLUG) {
            return;
        }

        $this->process_oauth_callback();
    }

    public function handle_admin_post_oauth_callback(): void
    {
        $this->process_oauth_callback();
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
        return $this->redirect_uri($this->settings());
    }

    private function process_oauth_callback(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions to access this page.'));
        }

        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        $statePayload = $state !== '' ? get_transient('wei_oauth_state_' . $state) : false;
        $stateValid = is_array($statePayload);
        $error = sanitize_text_field((string) ($_GET['error'] ?? ''));
        $errorDescription = sanitize_text_field((string) ($_GET['error_description'] ?? ''));
        $redirectUri = $this->configured_redirect_uri();

        $this->store_oauth_diagnostics([
            'oauth_status' => $error !== '' ? 'callback_error' : 'callback_received',
            'state_valid' => $stateValid,
            'token_exchange_success' => false,
            'token_exchange_error' => '',
            'oauth_error' => $error,
            'error_description' => $errorDescription,
            'redirect_uri_used' => $redirectUri,
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

        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
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
        return [
            'client_id_configured' => (string) ($s['client_id'] ?? '') !== '',
            'runame_configured' => (string) ($s['runame'] ?? '') !== '',
            'oauth_status' => (string) ($s['oauth_status'] ?? (((string) ($s['refresh_token'] ?? '') !== '') ? 'connected' : 'not_connected')),
            'has_refresh_token' => (string) ($s['refresh_token'] ?? '') !== '',
            'callback_url' => $this->callback_url(),
            'admin_post_callback_url' => $this->admin_post_callback_url(),
            'redirect_uri_configured' => $this->redirect_uri($s),
            'state_valid' => $s['state_valid'] ?? null,
            'token_exchange_success' => $s['token_exchange_success'] ?? null,
            'token_exchange_error' => (string) ($s['token_exchange_error'] ?? ''),
            'refresh_token_present' => (string) ($s['refresh_token'] ?? '') !== '',
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
        $redirectUri = $redirectUri !== null && $redirectUri !== '' ? $redirectUri : $this->redirect_uri($s);
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
            $this->logger->error('OAuth code exchange failed', ['error' => $message, 'redirect_uri_used' => $redirectUri]);
            $this->store_oauth_diagnostics(['oauth_status' => 'token_exchange_failed', 'token_exchange_success' => false, 'token_exchange_error' => $message, 'state_valid' => true, 'redirect_uri_used' => $redirectUri]);
            $this->redirect_with_error('token_exchange_failed', ['oauth_error' => 'token_exchange_failed', 'error_description' => $message, 'state_valid' => '1', 'redirect_uri_used' => $redirectUri]);
        }

        $status = (int) wp_remote_retrieve_response_code($r);
        $data = (array) json_decode((string) wp_remote_retrieve_body($r), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $message = (string) ($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $status));
            $this->logger->error('OAuth code exchange HTTP error', ['status' => $status, 'response' => $data, 'redirect_uri_used' => $redirectUri]);
            $this->store_oauth_diagnostics(['oauth_status' => 'token_exchange_failed', 'token_exchange_success' => false, 'token_exchange_error' => $message, 'state_valid' => true, 'redirect_uri_used' => $redirectUri]);
            $this->redirect_with_error('token_exchange_http_error', ['oauth_error' => (string) ($data['error'] ?? 'token_exchange_http_error'), 'error_description' => $message, 'state_valid' => '1', 'redirect_uri_used' => $redirectUri]);
        }

        $this->persist_token($data);
        $this->store_oauth_diagnostics(['oauth_status' => 'connected', 'token_exchange_success' => true, 'token_exchange_error' => '', 'state_valid' => true, 'redirect_uri_used' => $redirectUri]);
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
        $redirectUri = trim((string) ($settings['runame'] ?? ''));
        if ($redirectUri === '') {
            $redirectUri = trim((string) ($settings['redirect_uri'] ?? ''));
        }

        return $redirectUri !== '' ? $redirectUri : $this->callback_url();
    }

    private function store_oauth_diagnostics(array $diagnostics): void
    {
        $s = $this->settings();
        $keys = ['oauth_status', 'state_valid', 'token_exchange_success', 'token_exchange_error', 'oauth_error', 'error_description', 'redirect_uri_used'];
        foreach ($keys as $key) {
            if (array_key_exists($key, $diagnostics)) {
                $s[$key] = $diagnostics[$key];
            }
        }
        $s['last_oauth_callback_at'] = current_time('mysql');
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        return is_array($s) ? $s : [];
    }
}
