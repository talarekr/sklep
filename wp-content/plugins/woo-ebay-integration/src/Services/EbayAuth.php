<?php

namespace WEI\Services;

use WEI\Plugin;

class EbayAuth
{
    private const AUTH_URL = 'https://auth.ebay.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
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
            'redirect_uri' => $s['runame'] ?? '',
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
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    public function handle_oauth_callback(): void
    {
        $page = sanitize_text_field((string) ($_GET['page'] ?? ''));
        if ($page !== 'ebay-auth-callback') {
            return;
        }

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions to access this page.'));
        }

        $state = sanitize_text_field((string) ($_GET['state'] ?? ''));
        if ($state === '' || !get_transient('wei_oauth_state_' . $state)) {
            $this->redirect_with_error('invalid_state');
        }

        delete_transient('wei_oauth_state_' . $state);

        $error = sanitize_text_field((string) ($_GET['error'] ?? ''));
        if ($error !== '') {
            $this->redirect_with_error($error);
        }

        $code = sanitize_text_field((string) ($_GET['code'] ?? ''));
        if ($code === '') {
            $this->redirect_with_error('missing_code');
        }

        $this->exchange_code($code);
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

    private function exchange_code(string $code): void
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
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $s['runame'] ?? '',
            ],
        ]);

        if (is_wp_error($r)) {
            $this->logger->error('OAuth code exchange failed', ['error' => $r->get_error_message()]);
            $this->redirect_with_error('token_exchange_failed');
        }

        $status = (int) wp_remote_retrieve_response_code($r);
        $data = (array) json_decode((string) wp_remote_retrieve_body($r), true);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $this->logger->error('OAuth code exchange HTTP error', ['status' => $status, 'response' => $data]);
            $this->redirect_with_error('token_exchange_http_error');
        }

        $this->persist_token($data);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&ebay_connected=1'));
        exit;
    }

    private function redirect_with_error(string $error): void
    {
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay&ebay_error=' . rawurlencode($error)));
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
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        return is_array($s) ? $s : [];
    }
}
