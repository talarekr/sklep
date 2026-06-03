<?php

namespace WEI_FR\Services;

use WEI_FR\Plugin;

class EbayAuth
{
    private const AUTH_URL = 'https://auth.ebay.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
    public const CALLBACK_PAGE_SLUG = 'ebay-auth-callback';
    public const LEGACY_CALLBACK_PAGE_SLUG = 'ebay-fr-auth-callback';
    public const ADMIN_POST_CALLBACK_ACTION = 'wei_fr_ebay_auth_callback';
    private const STATE_PREFIX = 'wei_fr:';
    private const STATE_TRANSIENT_PREFIX = 'wei_fr_oauth_state_';
    private const SHARED_EBAY_RUNAME = 'GP_SWISS-GPSWISS-GPSwiss-jigmn';
    public const APP_SCOPE = 'https://api.ebay.com/oauth/api_scope';
    private const SCOPES = 'https://api.ebay.com/oauth/api_scope/sell.inventory https://api.ebay.com/oauth/api_scope/sell.account https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly';

    public function __construct(private Logger $logger)
    {
    }

    public function get_authorize_url(bool $startNewAttempt = false): string
    {
        $s = $this->settings();
        $attemptId = $this->new_oauth_attempt_id();
        $state = self::STATE_PREFIX . wp_generate_password(20, false, false);
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, ['user_id' => get_current_user_id(), 'attempt_id' => $attemptId], 10 * MINUTE_IN_SECONDS);

        $oauthRedirectParam = $this->oauth_redirect_param($s);

        $params = [
            'client_id' => $s['client_id'] ?? '',
            'response_type' => 'code',
            'redirect_uri' => $oauthRedirectParam,
            'scope' => self::SCOPES,
            'state' => $state,
        ];
        $authorizeUrl = self::AUTH_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        if ($startNewAttempt) {
            $this->start_oauth_attempt_diagnostics($attemptId, $oauthRedirectParam, $authorizeUrl, $state);
        } else {
            $this->store_authorize_url_diagnostics($authorizeUrl, $oauthRedirectParam, $state);
        }

        return $authorizeUrl;
    }

    public function redirect_to_authorize_url(): void
    {
        $authorizeUrl = $this->get_authorize_url(true);
        $headersAlreadySent = headers_sent($headersFile, $headersLine);
        $this->store_oauth_diagnostics([
            'oauth_connect_redirect_attempted' => true,
            'oauth_connect_redirect_target_type' => $this->redirect_target_type($authorizeUrl),
            'oauth_connect_redirect_headers_sent' => $headersAlreadySent,
            'oauth_connect_redirect_headers_sent_at' => $headersAlreadySent ? ($headersFile . ':' . $headersLine) : '',
            'oauth_connect_redirect_result' => false,
            'oauth_connect_redirect_error' => $headersAlreadySent ? 'headers_already_sent' : '',
        ], false);

        if ($headersAlreadySent) {
            return;
        }

        $redirected = wp_redirect($authorizeUrl);
        $this->store_oauth_diagnostics([
            'oauth_connect_redirect_attempted' => true,
            'oauth_connect_redirect_target_type' => $this->redirect_target_type($authorizeUrl),
            'oauth_connect_redirect_headers_sent' => false,
            'oauth_connect_redirect_headers_sent_at' => '',
            'oauth_connect_redirect_result' => (bool) $redirected,
            'oauth_connect_redirect_error' => $redirected ? '' : 'wp_redirect_returned_false',
        ], false);
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
        if (!$this->is_oauth_callback_request()) {
            return;
        }

        $this->store_intercept_diagnostics('plugins_loaded', false, 'plugins_loaded_deferred_to_admin_init');
        add_action('admin_init', [$this, 'handle_oauth_callback'], 0);
    }

    public function handle_admin_post_oauth_callback(): void
    {
        $this->store_intercept_diagnostics('admin_post_' . self::ADMIN_POST_CALLBACK_ACTION, false, 'admin_post_deferred_to_admin_init');
        wp_safe_redirect(add_query_arg($this->safe_callback_query_args(), $this->callback_url()));
        exit;
    }

    public function maybe_intercept_oauth_callback(string $hook): void
    {
        if (!$this->is_oauth_callback_request()) {
            return;
        }

        if ($hook !== 'admin_init') {
            $this->store_intercept_diagnostics($hook, false);
            return;
        }

        $this->store_intercept_diagnostics($hook, true);
        $this->process_oauth_callback(true, $hook);
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

    private function process_oauth_callback(bool $interceptedByAdminInit = false, string $callbackHookStage = 'process_oauth_callback'): void
    {
        $callbackAttemptId = $this->callback_attempt_id_from_state();
        $capabilityDiagnostics = $this->callback_capability_diagnostics($callbackHookStage, 'manage_options');
        if (!$capabilityDiagnostics['is_user_logged_in'] || !$capabilityDiagnostics['current_user_can_manage_options']) {
            $this->store_oauth_diagnostics($capabilityDiagnostics + [
                'callback_intercepted_by_admin_init' => $interceptedByAdminInit,
                'page_param' => $this->request_value('page'),
                'code_received' => $this->request_value('code') !== '',
                'state_received' => $this->request_value('state') !== '',
                'code_exists_in_get_before_capability_rejection' => array_key_exists('code', $_GET) && $this->sanitize_raw((string) $_GET['code']) !== '',
                'state_exists_in_get_before_capability_rejection' => array_key_exists('state', $_GET) && $this->sanitize_raw((string) $_GET['state']) !== '',
                'token_exchange_attempted' => false,
                'token_exchange_success' => false,
                'token_exchange_error' => 'not_wordpress_administrator',
                'oauth_last_attempt_id' => $callbackAttemptId,
                'oauth_shared_callback' => true,
                'oauth_callback_router' => 'state_prefix',
                'routed_plugin' => 'FR',
                'routed_marketplace' => 'EBAY_FR',
            ]);
            wp_die(esc_html__('Please log in as WordPress administrator and retry eBay Connect.', 'woo-ebay-integration-fr'), esc_html__('eBay OAuth callback', 'woo-ebay-integration-fr'), ['response' => 403]);
        }

        $state = $this->request_value('state');
        $statePayload = $state !== '' ? get_transient(self::STATE_TRANSIENT_PREFIX . $state) : false;
        $stateValid = is_array($statePayload);
        $callbackAttemptId = $stateValid ? (string) ($statePayload['attempt_id'] ?? $callbackAttemptId) : $callbackAttemptId;
        $error = $this->request_value('error');
        $errorDescription = $this->request_value('error_description');
        $expiresIn = $this->request_value('expires_in');
        $code = $this->request_value('code');
        $redirectUri = $this->configured_redirect_uri();

        $this->store_oauth_diagnostics([
            'oauth_status' => $error !== '' ? 'callback_error' : 'callback_received',
            'callback_intercepted_by_admin_init' => $interceptedByAdminInit,
            'current_user_id' => $capabilityDiagnostics['current_user_id'],
            'is_user_logged_in' => $capabilityDiagnostics['is_user_logged_in'],
            'current_user_can_manage_options' => $capabilityDiagnostics['current_user_can_manage_options'],
            'required_capability' => $capabilityDiagnostics['required_capability'],
            'callback_hook_stage' => $capabilityDiagnostics['callback_hook_stage'],
            'page_param' => $this->request_value('page'),
            'code_received' => $code !== '',
            'state_received' => $state !== '',
            'expires_in_received' => $expiresIn,
            'state_valid' => $stateValid,
            'token_exchange_attempted' => false,
            'token_exchange_success' => false,
            'token_exchange_error' => '',
            'oauth_last_attempt_id' => $callbackAttemptId,
            'refresh_token_saved' => false,
            'oauth_error' => $error,
            'error_description' => $errorDescription,
            'redirect_uri_used' => $redirectUri,
            'oauth_redirect_param_used' => $redirectUri,
            'oauth_shared_callback' => true,
            'oauth_callback_router' => 'state_prefix',
            'routed_plugin' => 'FR',
            'routed_marketplace' => 'EBAY_FR',
        ]);

        if (!$stateValid) {
            $this->redirect_with_error('invalid_state', [
                'oauth_error' => $error !== '' ? $error : 'invalid_state',
                'error_description' => $errorDescription,
                'state_valid' => '0',
                'redirect_uri_used' => $redirectUri,
            ]);
        }

        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

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
            return new \WP_Error('wei_fr_missing_refresh', 'Missing eBay refresh token');
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
            'fr_plugin_version' => $this->fr_plugin_version(),
            'fr_plugin_commit' => $this->fr_plugin_commit(),
            'oauth_callback_flow_version' => $this->oauth_callback_flow_version(),
            'client_id_configured' => (string) ($s['client_id'] ?? '') !== '',
            'runame_configured' => $ebayRuname !== '',
            'oauth_status' => (string) ($s['oauth_status'] ?? ($hasRefreshToken ? 'connected' : 'not_connected')),
            'has_refresh_token' => $hasRefreshToken,
            'callback_detected_at_plugins_loaded' => $s['callback_detected_at_plugins_loaded'] ?? null,
            'callback_intercepted_by_admin_init' => $s['callback_intercepted_by_admin_init'] ?? null,
            'intercept_hook' => (string) ($s['intercept_hook'] ?? ''),
            'request_uri' => (string) ($s['request_uri'] ?? ''),
            'raw_get_keys' => is_array($s['raw_get_keys'] ?? null) ? $s['raw_get_keys'] : [],
            'current_user_id' => (int) ($s['current_user_id'] ?? 0),
            'is_user_logged_in' => $s['is_user_logged_in'] ?? null,
            'current_user_can_manage_options' => $s['current_user_can_manage_options'] ?? null,
            'required_capability' => (string) ($s['required_capability'] ?? 'manage_options'),
            'callback_hook_stage' => (string) ($s['callback_hook_stage'] ?? ''),
            'code_exists_in_get_before_capability_rejection' => $s['code_exists_in_get_before_capability_rejection'] ?? null,
            'state_exists_in_get_before_capability_rejection' => $s['state_exists_in_get_before_capability_rejection'] ?? null,
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
            'oauth_shared_callback' => $s['oauth_shared_callback'] ?? true,
            'oauth_callback_router' => (string) ($s['oauth_callback_router'] ?? 'state_prefix'),
            'routed_plugin' => (string) ($s['routed_plugin'] ?? 'FR'),
            'routed_marketplace' => (string) ($s['routed_marketplace'] ?? 'EBAY_FR'),
            'cached_policies' => $this->normalize_fr_cached_policies($s['wei_fr_cached_policies'] ?? []),
            'state_valid' => $s['state_valid'] ?? null,
            'token_exchange_attempted' => $s['token_exchange_attempted'] ?? null,
            'token_exchange_success' => $s['token_exchange_success'] ?? null,
            'token_exchange_error' => (string) ($s['token_exchange_error'] ?? ''),
            'oauth_last_attempt_id' => (string) ($s['oauth_last_attempt_id'] ?? ''),
            'refresh_token_saved' => $s['refresh_token_saved'] ?? null,
            'refresh_token_present' => $hasRefreshToken,
            'access_token_present' => (string) ($s['access_token'] ?? '') !== '',
            'access_token_expires_at' => (int) ($s['expires_at'] ?? 0),
            'scope_requested' => self::SCOPES,
            'scope_last_returned' => (string) ($s['scope_last_returned'] ?? ''),
            'oauth_authorize_url_redacted' => (string) ($s['oauth_authorize_url_redacted'] ?? ''),
            'oauth_authorize_url_host' => (string) ($s['oauth_authorize_url_host'] ?? ''),
            'oauth_authorize_url_has_client_id' => $s['oauth_authorize_url_has_client_id'] ?? null,
            'oauth_authorize_url_redirect_uri' => (string) ($s['oauth_authorize_url_redirect_uri'] ?? ''),
            'oauth_authorize_url_state_prefix' => (string) ($s['oauth_authorize_url_state_prefix'] ?? ''),
            'oauth_authorize_url_has_scope' => $s['oauth_authorize_url_has_scope'] ?? null,
            'oauth_connect_redirect_attempted' => $s['oauth_connect_redirect_attempted'] ?? null,
            'oauth_connect_redirect_target_type' => (string) ($s['oauth_connect_redirect_target_type'] ?? ''),
            'oauth_connect_redirect_headers_sent' => $s['oauth_connect_redirect_headers_sent'] ?? null,
            'oauth_connect_redirect_headers_sent_at' => (string) ($s['oauth_connect_redirect_headers_sent_at'] ?? ''),
            'oauth_connect_redirect_result' => $s['oauth_connect_redirect_result'] ?? null,
            'oauth_connect_redirect_error' => (string) ($s['oauth_connect_redirect_error'] ?? ''),
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
            'oauth_shared_callback' => true,
            'oauth_callback_router' => 'state_prefix',
            'routed_plugin' => 'FR',
            'routed_marketplace' => 'EBAY_FR',
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
        $this->store_oauth_diagnostics(['oauth_status' => 'connected', 'token_exchange_attempted' => true, 'token_exchange_success' => true, 'token_exchange_error' => '', 'state_valid' => true, 'redirect_uri_used' => $redirectUri, 'oauth_redirect_param_used' => $redirectUri, 'refresh_token_saved' => $refreshTokenSaved, 'oauth_shared_callback' => true, 'oauth_callback_router' => 'state_prefix', 'routed_plugin' => 'FR', 'routed_marketplace' => 'EBAY_FR']);
        wp_safe_redirect(admin_url('admin.php?page=woo-ebay-fr&ebay_connected=1&oauth_status=connected'));
        exit;
    }


    private function request_application_access_token(): string|\WP_Error
    {
        $s = $this->settings();
        $clientId = (string) ($s['client_id'] ?? '');
        $clientSecret = (string) ($s['client_secret'] ?? '');
        if ($clientId === '' || $clientSecret === '') {
            return new \WP_Error('wei_fr_missing_app_credentials', 'Missing eBay App client_id or client_secret for application access token');
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
            return new \WP_Error('wei_fr_app_oauth_http_error', 'eBay application OAuth HTTP error', [
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
        $state = $this->request_value('state');
        $isSharedCallback = $getPage === self::CALLBACK_PAGE_SLUG
            || $requestPage === self::CALLBACK_PAGE_SLUG
            || strpos($requestUri, 'page=' . self::CALLBACK_PAGE_SLUG) !== false;
        $isLegacyFrCallback = $getPage === self::LEGACY_CALLBACK_PAGE_SLUG
            || $requestPage === self::LEGACY_CALLBACK_PAGE_SLUG
            || strpos($requestUri, 'page=' . self::LEGACY_CALLBACK_PAGE_SLUG) !== false;

        return ($isSharedCallback && str_starts_with($state, self::STATE_PREFIX)) || $isLegacyFrCallback;
    }

    private function store_intercept_diagnostics(string $hook, bool $interceptedByAdminInit, ?string $callbackHookStage = null): void
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        error_log('WEI OAuth callback intercept hit: request_uri=' . $requestUri . ' hook=' . $hook);

        if ($hook === 'plugins_loaded') {
            $this->store_oauth_diagnostics([
                'callback_detected_at_plugins_loaded' => true,
            ], false);
            return;
        }

        $this->store_oauth_diagnostics($this->callback_capability_diagnostics($callbackHookStage ?? $hook, 'manage_options') + [
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

    public function clear_oauth_diagnostics(): void
    {
        $s = $this->settings();
        foreach ($this->oauth_diagnostic_keys() as $key) {
            unset($s[$key]);
        }
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function start_oauth_attempt_diagnostics(string $attemptId, string $oauthRedirectParam, string $authorizeUrl, string $state): void
    {
        $s = $this->settings();
        foreach ($this->stale_oauth_attempt_keys() as $key) {
            unset($s[$key]);
        }
        $s['fr_plugin_version'] = $this->fr_plugin_version();
        $s['fr_plugin_commit'] = $this->fr_plugin_commit();
        $s['oauth_callback_flow_version'] = $this->oauth_callback_flow_version();
        $s['oauth_last_attempt_id'] = $attemptId;
        $s['oauth_status'] = ((string) ($s['refresh_token'] ?? '') !== '') ? 'connected' : 'not_connected';
        $s['oauth_redirect_param_used'] = $oauthRedirectParam;
        $s['oauth_shared_callback'] = true;
        $s['oauth_callback_router'] = 'state_prefix';
        $s['routed_plugin'] = 'FR';
        $s['routed_marketplace'] = 'EBAY_FR';
        $s['last_oauth_attempt_started_at'] = current_time('mysql');
        $s = array_merge($s, $this->authorize_url_diagnostics($authorizeUrl, $oauthRedirectParam, $state));
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function store_authorize_url_diagnostics(string $authorizeUrl, string $oauthRedirectParam, string $state): void
    {
        $s = $this->settings();
        $s = array_merge($s, $this->authorize_url_diagnostics($authorizeUrl, $oauthRedirectParam, $state));
        update_option(Plugin::OPTION_KEY, $s, false);
    }

    private function authorize_url_diagnostics(string $authorizeUrl, string $oauthRedirectParam, string $state): array
    {
        parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $query);

        return [
            'oauth_authorize_url_redacted' => $this->redact_authorize_url($authorizeUrl),
            'oauth_authorize_url_host' => (string) parse_url($authorizeUrl, PHP_URL_HOST),
            'oauth_authorize_url_has_client_id' => (string) ($query['client_id'] ?? '') !== '',
            'oauth_authorize_url_redirect_uri' => (string) ($query['redirect_uri'] ?? $oauthRedirectParam),
            'oauth_authorize_url_state_prefix' => str_starts_with((string) ($query['state'] ?? $state), self::STATE_PREFIX) ? self::STATE_PREFIX : '',
            'oauth_authorize_url_has_scope' => (string) ($query['scope'] ?? '') !== '',
            'oauth_connect_redirect_attempted' => false,
            'oauth_connect_redirect_target_type' => $this->redirect_target_type($authorizeUrl),
            'oauth_connect_redirect_headers_sent' => null,
            'oauth_connect_redirect_headers_sent_at' => '',
            'oauth_connect_redirect_result' => null,
            'oauth_connect_redirect_error' => '',
        ];
    }

    private function redact_authorize_url(string $authorizeUrl): string
    {
        if ($authorizeUrl === '') {
            return '';
        }

        return (string) preg_replace('/([?&]client_id=)[^&]*/', '$1redacted', $authorizeUrl);
    }

    private function redirect_target_type(string $url): string
    {
        if ($url === '') {
            return 'empty';
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($host === 'auth.ebay.com' && $path === '/oauth2/authorize') {
            return 'ebay_authorize_url';
        }

        if (str_contains($path, '/wp-admin/') || str_contains($url, 'wp-admin')) {
            return 'wp_admin';
        }

        return 'other';
    }

    private function new_oauth_attempt_id(): string
    {
        $random = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
        return gmdate('YmdHis') . '-' . $random;
    }

    private function callback_attempt_id_from_state(): string
    {
        $state = $this->request_value('state');
        $statePayload = $state !== '' ? get_transient(self::STATE_TRANSIENT_PREFIX . $state) : false;
        if (is_array($statePayload) && (string) ($statePayload['attempt_id'] ?? '') !== '') {
            return (string) $statePayload['attempt_id'];
        }

        return (string) ($this->settings()['oauth_last_attempt_id'] ?? '');
    }

    private function safe_callback_query_args(): array
    {
        $args = ['page' => self::CALLBACK_PAGE_SLUG];
        foreach (['code', 'state', 'expires_in', 'error', 'error_description'] as $key) {
            $value = $this->request_value($key);
            if ($value !== '') {
                $args[$key] = $value;
            }
        }
        return $args;
    }

    private function stale_oauth_attempt_keys(): array
    {
        return [
            'token_exchange_error',
            'code_received',
            'state_received',
            'state_valid',
            'token_exchange_attempted',
            'token_exchange_success',
            'callback_hook_stage',
            'current_user_id',
            'is_user_logged_in',
            'current_user_can_manage_options',
            'callback_detected_at_plugins_loaded',
            'callback_intercepted_by_admin_init',
            'intercept_hook',
            'request_uri',
            'raw_get_keys',
            'code_exists_in_get_before_capability_rejection',
            'state_exists_in_get_before_capability_rejection',
            'oauth_error',
            'error_description',
            'refresh_token_saved',
            'oauth_shared_callback',
            'oauth_callback_router',
            'routed_plugin',
            'routed_marketplace',
            'oauth_authorize_url_redacted',
            'oauth_authorize_url_host',
            'oauth_authorize_url_has_client_id',
            'oauth_authorize_url_redirect_uri',
            'oauth_authorize_url_state_prefix',
            'oauth_authorize_url_has_scope',
            'oauth_connect_redirect_attempted',
            'oauth_connect_redirect_target_type',
            'oauth_connect_redirect_headers_sent',
            'oauth_connect_redirect_headers_sent_at',
            'oauth_connect_redirect_result',
            'oauth_connect_redirect_error',
        ];
    }

    private function oauth_diagnostic_keys(): array
    {
        return array_values(array_unique(array_merge($this->stale_oauth_attempt_keys(), [
            'fr_plugin_version',
            'fr_plugin_commit',
            'oauth_callback_flow_version',
            'oauth_status',
            'required_capability',
            'callback_page_registered',
            'page_param',
            'expires_in_received',
            'redirect_uri_used',
            'oauth_redirect_param_used',
            'oauth_last_attempt_id',
            'last_oauth_attempt_started_at',
            'last_oauth_callback_at',
            'oauth_authorize_url_redacted',
            'oauth_authorize_url_host',
            'oauth_authorize_url_has_client_id',
            'oauth_authorize_url_redirect_uri',
            'oauth_authorize_url_state_prefix',
            'oauth_authorize_url_has_scope',
            'oauth_connect_redirect_attempted',
            'oauth_connect_redirect_target_type',
            'oauth_connect_redirect_headers_sent',
            'oauth_connect_redirect_headers_sent_at',
            'oauth_connect_redirect_result',
            'oauth_connect_redirect_error',
        ])));
    }

    private function fr_plugin_version(): string
    {
        return defined('WEI_FR_PLUGIN_VERSION') ? (string) WEI_FR_PLUGIN_VERSION : '0.1.0';
    }

    private function fr_plugin_commit(): string
    {
        return defined('WEI_FR_BUILD_COMMIT') ? (string) WEI_FR_BUILD_COMMIT : 'unknown';
    }

    private function oauth_callback_flow_version(): string
    {
        return defined('WEI_FR_OAUTH_CALLBACK_FLOW_VERSION') ? (string) WEI_FR_OAUTH_CALLBACK_FLOW_VERSION : '2026-06-03-shared-oauth-router-v1';
    }

    private function callback_capability_diagnostics(string $callbackHookStage, string $requiredCapability): array
    {
        $currentUserId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $isUserLoggedIn = function_exists('is_user_logged_in') ? (bool) is_user_logged_in() : $currentUserId > 0;
        $canManageOptions = function_exists('current_user_can') ? (bool) current_user_can($requiredCapability) : false;

        return [
            'current_user_id' => $currentUserId,
            'is_user_logged_in' => $isUserLoggedIn,
            'current_user_can_manage_options' => $canManageOptions,
            'required_capability' => $requiredCapability,
            'callback_hook_stage' => $callbackHookStage,
        ];
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
        $args = ['page' => 'woo-ebay-fr', 'ebay_error' => $error];
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
        if ($legacyRedirectUri !== '' && !$this->is_wordpress_callback_url($legacyRedirectUri)) {
            return $legacyRedirectUri;
        }

        return $this->callback_url();
    }


    private function is_wordpress_callback_url(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && strpos($url, '/wp-admin/admin.php') !== false
            && strpos($url, 'page=') !== false;
    }

    private function ebay_runame(array $settings): string
    {
        $runame = trim((string) ($settings['runame'] ?? ''));
        if ($runame !== '') {
            return $runame;
        }

        $legacyRedirectUri = trim((string) ($settings['redirect_uri'] ?? ''));
        if ($legacyRedirectUri !== '' && !filter_var($legacyRedirectUri, FILTER_VALIDATE_URL)) {
            return $legacyRedirectUri;
        }

        return self::SHARED_EBAY_RUNAME;
    }


    private function normalize_fr_cached_policies($cached): array
    {
        if (!is_array($cached)) {
            return ['marketplace_id' => 'EBAY_FR'];
        }

        if ((string) ($cached['marketplace_id'] ?? '') !== 'EBAY_FR') {
            $cached['marketplace_id'] = 'EBAY_FR';
        }

        return $cached;
    }

    private function store_oauth_diagnostics(array $diagnostics, bool $touchCallbackTime = true): void
    {
        $s = $this->settings();
        $keys = ['fr_plugin_version', 'fr_plugin_commit', 'oauth_callback_flow_version', 'oauth_status', 'callback_detected_at_plugins_loaded', 'callback_intercepted_by_admin_init', 'intercept_hook', 'request_uri', 'raw_get_keys', 'current_user_id', 'is_user_logged_in', 'current_user_can_manage_options', 'required_capability', 'callback_hook_stage', 'code_exists_in_get_before_capability_rejection', 'state_exists_in_get_before_capability_rejection', 'callback_page_registered', 'page_param', 'code_received', 'state_received', 'expires_in_received', 'state_valid', 'token_exchange_attempted', 'token_exchange_success', 'token_exchange_error', 'oauth_last_attempt_id', 'refresh_token_saved', 'oauth_error', 'error_description', 'redirect_uri_used', 'oauth_redirect_param_used', 'oauth_shared_callback', 'oauth_callback_router', 'routed_plugin', 'routed_marketplace', 'oauth_authorize_url_redacted', 'oauth_authorize_url_host', 'oauth_authorize_url_has_client_id', 'oauth_authorize_url_redirect_uri', 'oauth_authorize_url_state_prefix', 'oauth_authorize_url_has_scope', 'oauth_connect_redirect_attempted', 'oauth_connect_redirect_target_type', 'oauth_connect_redirect_headers_sent', 'oauth_connect_redirect_headers_sent_at', 'oauth_connect_redirect_result', 'oauth_connect_redirect_error'];
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
