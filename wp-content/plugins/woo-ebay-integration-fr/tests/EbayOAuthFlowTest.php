<?php

declare(strict_types=1);

namespace WEI_FR {
    if (!class_exists(__NAMESPACE__ . '\\Plugin')) {
        class Plugin
        {
            public const OPTION_KEY = 'wei_fr_ebay_settings';
        }
    }
}

namespace {
    $GLOBALS['wei_fr_test_options'] = [];
    $GLOBALS['wei_fr_test_transients'] = [];

    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_fr_test_options'][$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = true): bool
    {
        $GLOBALS['wei_fr_test_options'][$key] = $value;
        return true;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://gpswiss.pl/wp-admin/' . ltrim($path, '/');
    }

    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return 'STATE1234567890';
    }

    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['wei_fr_test_current_user_id'] ?? 7);
    }

    function is_user_logged_in(): bool
    {
        return (bool) ($GLOBALS['wei_fr_test_is_user_logged_in'] ?? true);
    }

    function current_user_can(string $capability): bool
    {
        $GLOBALS['wei_fr_test_current_user_can_calls'] = (int) ($GLOBALS['wei_fr_test_current_user_can_calls'] ?? 0) + 1;
        return (bool) ($GLOBALS['wei_fr_test_current_user_can_manage_options'] ?? true);
    }

    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): true
    {
        $GLOBALS['wei_fr_test_actions'][] = compact('hook_name', 'callback', 'priority', 'accepted_args');
        return true;
    }

    function set_transient(string $key, $value, int $expiration): bool
    {
        $GLOBALS['wei_fr_test_transients'][$key] = $value;
        return true;
    }

    function current_time(string $type): string
    {
        return '2026-05-31 00:00:00';
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }

    require_once __DIR__ . '/../src/Services/Logger.php';
    require_once __DIR__ . '/../src/Services/EbayAuth.php';

    use WEI_FR\Plugin;
    use WEI_FR\Services\EbayAuth;
    use WEI_FR\Services\Logger;

    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'GPSWISS-GPSwiss-PRD-dbddbd5ea-53182c46',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-fr-auth-callback',
        'runame' => 'GP_SWISS-GPSWISS-GPSwiss-jigmn',
        'refresh_token' => '',
    ];

    $auth = new EbayAuth(new Logger());
    $authorizeUrl = $auth->get_authorize_url(true);
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeParams);
    $diagnostics = $auth->get_diagnostic_oauth_context();

    $assert(($authorizeParams['redirect_uri'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Authorization URL must use eBay RuName as redirect_uri.');
    $assert(str_starts_with((string) ($authorizeParams['state'] ?? ''), 'wei_fr:'), 'FR Connect state must use the FR state prefix.');
    $assert(isset($GLOBALS['wei_fr_test_transients']['wei_fr_oauth_state_wei_fr:STATE1234567890']), 'FR Connect must store state under the FR-specific OAuth state key.');
    $assert(($diagnostics['callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback', 'Diagnostics callback_url must be the shared browser callback URL.');
    $assert(($diagnostics['browser_callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback', 'Diagnostics browser_callback_url must be the shared browser callback URL.');
    $assert(($diagnostics['ebay_runame'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics ebay_runame must be separated from callback URL.');
    $assert(($diagnostics['oauth_redirect_param_used'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics oauth_redirect_param_used must be the RuName.');
    $assert(($diagnostics['token_exchange_attempted'] ?? false) === false, 'Connect URL generation must not attempt token exchange or refresh-token API calls.');
    $assert(($diagnostics['fr_plugin_version'] ?? '') === '0.1.0', 'FR OAuth diagnostics must expose fr_plugin_version.');
    $assert(($diagnostics['fr_plugin_commit'] ?? '') !== '', 'FR OAuth diagnostics must expose fr_plugin_commit.');
    $assert(($diagnostics['oauth_callback_flow_version'] ?? '') === '2026-06-03-shared-oauth-router-v1', 'FR OAuth diagnostics must expose oauth_callback_flow_version.');
    $assert(($diagnostics['oauth_shared_callback'] ?? null) === true, 'FR OAuth diagnostics must mark the shared callback.');
    $assert(($diagnostics['oauth_callback_router'] ?? '') === 'state_prefix', 'FR OAuth diagnostics must identify the state-prefix router.');
    $assert(($diagnostics['routed_plugin'] ?? '') === 'FR', 'FR OAuth diagnostics must identify the routed plugin.');
    $assert(($diagnostics['routed_marketplace'] ?? '') === 'EBAY_FR', 'FR OAuth diagnostics must identify the routed marketplace.');
    $assert(($diagnostics['cached_policies']['marketplace_id'] ?? '') === 'EBAY_FR', 'FR diagnostics cached_policies.marketplace_id must remain EBAY_FR.');

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'GPSWISS-GPSwiss-PRD-dbddbd5ea-53182c46',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback',
        'runame' => '',
        'refresh_token' => '',
    ];
    $legacyAuthorizeUrl = $auth->get_authorize_url(true);
    parse_str((string) parse_url($legacyAuthorizeUrl, PHP_URL_QUERY), $legacyAuthorizeParams);
    $assert(($legacyAuthorizeParams['redirect_uri'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'FR Connect must use the shared OAuth RuName even when a copied WordPress callback URL is configured.');
    $assert(str_starts_with((string) ($legacyAuthorizeParams['state'] ?? ''), 'wei_fr:'), 'FR legacy-setting Connect state must remain routed to FR.');

    $ref = new ReflectionClass(EbayAuth::class);
    $source = file_get_contents($ref->getFileName());
    $assert(str_contains($source, '\'redirect_uri\' => $redirectUri'), 'Token exchange body must use the same OAuth redirect parameter variable.');
    $assert(str_contains($source, "STATE_TRANSIENT_PREFIX = 'wei_fr_oauth_state_'"), 'OAuth state option/transient prefix must be FR-specific.');
    $assert(!str_contains($source, "'wei_oauth_state_") && !str_contains($source, "'wei_ebay_oauth_state_"), 'FR OAuth state handling must not use DE state keys.');
    $assert(str_contains($source, 'callback_intercepted_by_admin_init'), 'OAuth diagnostics must track admin_init callback interception.');
    $assert(str_contains($source, 'intercept_hook'), 'OAuth diagnostics must store the hook that intercepted the callback.');
    $assert(str_contains($source, 'request_uri'), 'OAuth diagnostics must store the raw callback request URI.');
    $assert(str_contains($source, 'raw_get_keys'), 'OAuth diagnostics must store raw GET keys for callback debugging.');
    $assert(str_contains($source, 'callback_capability_diagnostics'), 'FR OAuth callback must collect capability diagnostics before rejecting callbacks.');
    $assert(str_contains($source, 'current_user_id'), 'FR OAuth diagnostics must include current_user_id.');
    $assert(str_contains($source, 'is_user_logged_in'), 'FR OAuth diagnostics must include is_user_logged_in.');
    $assert(str_contains($source, 'current_user_can_manage_options'), 'FR OAuth diagnostics must include current_user_can_manage_options.');
    $assert(str_contains($source, 'required_capability'), 'FR OAuth diagnostics must include the required callback capability.');
    $assert(str_contains($source, 'callback_hook_stage'), 'FR OAuth diagnostics must include callback_hook_stage.');
    $assert(str_contains($source, 'code_exists_in_get_before_capability_rejection'), 'FR OAuth diagnostics must record whether code existed in $_GET before capability rejection.');
    $assert(str_contains($source, 'state_exists_in_get_before_capability_rejection'), 'FR OAuth diagnostics must record whether state existed in $_GET before capability rejection.');
    $assert(str_contains($source, 'WEI OAuth callback intercept hit: request_uri='), 'OAuth callback intercept must emit temporary error_log diagnostics.');
    $assert(str_contains($source, 'Please log in as WordPress administrator and retry eBay Connect.'), 'Non-admin callback must show a clear administrator login message.');
    $assert(str_contains($source, '\'code_received\' => $code !== \'\''), 'Callback diagnostics must record code_received for code callbacks.');
    $assert(str_contains($source, '\'oauth_status\' => $error !== \'\' ? \'callback_error\' : \'callback_received\''), 'Callback diagnostics must record clear eBay error/decline callbacks.');
    $assert(str_contains($source, "new \\WP_Error('wei_fr_missing_refresh', 'Missing eBay refresh token')"), 'Export without refresh token must still report wei_fr_missing_refresh / Missing eBay refresh token.');

    $pluginSource = file_get_contents(__DIR__ . '/../src/Plugin.php');
    $bootstrapSource = file_get_contents(__DIR__ . '/../woo-ebay-integration-fr.php');
    $adminSource = file_get_contents(__DIR__ . '/../src/Services/AdminPage.php');
    $viewSource = file_get_contents(__DIR__ . '/../views/admin-page.php');
    $assert(str_contains($adminSource, "'manage_options', EbayAuth::CALLBACK_PAGE_SLUG, [\$this, 'render_oauth_callback'], 'oauth callback menu'"), 'Hidden FR callback page must be registered with the FR callback slug and manage_options capability.');
    $assert(str_contains($pluginSource, "add_action('admin_init', [\$auth, 'handle_oauth_callback'], 0)"), 'Plugin must register the OAuth callback interceptor on global admin_init priority 0.');
    $assert(str_contains($pluginSource, "add_action('current_screen', [\$auth, 'handle_current_screen_oauth_callback'], 0)"), 'Plugin must register current_screen OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('load-admin_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [\$auth, 'handle_load_oauth_callback'], 0)"), 'Plugin must register load-admin_page OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('load-woocommerce_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [\$auth, 'handle_woocommerce_load_oauth_callback'], 0)"), 'Plugin must register WooCommerce submenu load OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('admin_post_nopriv_' . EbayAuth::ADMIN_POST_CALLBACK_ACTION"), 'Plugin must register nopriv admin-post OAuth callback fallback.');
    $assert(str_contains($bootstrapSource, 'handle_admin_bootstrap_oauth_callback'), 'Main plugin file must register a bootstrap-level callback interceptor before WooCommerce-dependent boot.');
    $assert(str_contains($bootstrapSource, "define('WEI_FR_PLUGIN_VERSION', '0.1.0')"), 'FR bootstrap must define a visible plugin version marker.');
    $assert(str_contains($bootstrapSource, 'WEI_FR_BUILD_COMMIT'), 'FR bootstrap must define a visible plugin commit marker.');
    $assert(str_contains($bootstrapSource, 'WEI_FR_OAUTH_CALLBACK_FLOW_VERSION'), 'FR bootstrap must define a visible OAuth callback flow version marker.');
    $assert(str_contains($viewSource, 'FR plugin build marker'), 'FR admin footer must render the FR plugin build marker.');
    $assert(str_contains($viewSource, "'fr_plugin_version' => $" . "frPluginVersion"), 'Displayed OAuth JSON must include fr_plugin_version.');
    $assert(str_contains($viewSource, "'fr_plugin_commit' => $" . "frPluginCommit"), 'Displayed OAuth JSON must include fr_plugin_commit.');
    $assert(str_contains($viewSource, "'oauth_callback_flow_version' => $" . "oauthCallbackFlowVersion"), 'Displayed OAuth JSON must include oauth_callback_flow_version.');
    $assert(str_contains($viewSource, "'callback_detected_at_plugins_loaded' => $" . "oauthDiagnostics['callback_detected_at_plugins_loaded']"), 'Displayed OAuth JSON must include callback_detected_at_plugins_loaded.');
    $assert(str_contains($viewSource, "'current_user_id' => (int) ($" . "oauthDiagnostics['current_user_id']"), 'Displayed OAuth JSON must include current_user_id for not_wordpress_administrator diagnostics.');
    $assert(str_contains($viewSource, "'is_user_logged_in' => $" . "oauthDiagnostics['is_user_logged_in']"), 'Displayed OAuth JSON must include is_user_logged_in for not_wordpress_administrator diagnostics.');
    $assert(str_contains($viewSource, "'current_user_can_manage_options' => $" . "oauthDiagnostics['current_user_can_manage_options']"), 'Displayed OAuth JSON must include current_user_can_manage_options for not_wordpress_administrator diagnostics.');
    $assert(str_contains($viewSource, "'required_capability' => (string) ($" . "oauthDiagnostics['required_capability']"), 'Displayed OAuth JSON must include required_capability for not_wordpress_administrator diagnostics.');
    $assert(str_contains($viewSource, "'callback_hook_stage' => (string) ($" . "oauthDiagnostics['callback_hook_stage']"), 'Displayed OAuth JSON must include callback_hook_stage for not_wordpress_administrator diagnostics.');
    $assert(str_contains($source, "store_intercept_diagnostics('plugins_loaded', false, 'plugins_loaded_deferred_to_admin_init')"), 'FR bootstrap callback detection must defer processing until admin_init user/capability context is available.');
    $assert(str_contains($source, 'add_action(' . "'admin_init'" . ', [$this, ' . "'handle_oauth_callback'" . '], 0)'), 'FR bootstrap callback handler must register an admin_init retry instead of processing at plugins_loaded.');
    $assert(!str_contains($source, "maybe_intercept_oauth_callback('plugins_loaded')"), 'FR callback must not process OAuth at plugins_loaded before WordPress administrator capabilities are available.');



    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'GPSWISS-GPSwiss-PRD-dbddbd5ea-53182c46',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-fr-auth-callback',
        'runame' => '',
        'refresh_token' => '',
        'oauth_status' => 'not_connected',
        'token_exchange_error' => 'not_wordpress_administrator',
        'code_received' => true,
        'state_received' => true,
        'token_exchange_success' => false,
    ];

    $auth->get_authorize_url(true);
    $newAttemptDiagnostics = $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY];
    $assert(!array_key_exists('token_exchange_error', $newAttemptDiagnostics), 'Starting a new Connect attempt must clear stale token_exchange_error diagnostics.');
    $assert(!array_key_exists('code_received', $newAttemptDiagnostics), 'Starting a new Connect attempt must clear stale code_received diagnostics.');
    $assert(!array_key_exists('state_received', $newAttemptDiagnostics), 'Starting a new Connect attempt must clear stale state_received diagnostics.');
    $assert(!array_key_exists('token_exchange_success', $newAttemptDiagnostics), 'Starting a new Connect attempt must clear stale token_exchange_success diagnostics.');
    $assert(($newAttemptDiagnostics['oauth_last_attempt_id'] ?? '') !== '', 'Starting a new Connect attempt must write oauth_last_attempt_id.');

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'GPSWISS-GPSwiss-PRD-dbddbd5ea-53182c46',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-fr-auth-callback',
        'runame' => '',
        'refresh_token' => '',
        'oauth_status' => 'not_connected',
        'token_exchange_error' => 'not_wordpress_administrator',
        'code_received' => true,
        'state_received' => true,
        'token_exchange_success' => false,
    ];
    $GLOBALS['wei_fr_test_current_user_can_calls'] = 0;
    $GLOBALS['wei_fr_test_current_user_id'] = 0;
    $GLOBALS['wei_fr_test_is_user_logged_in'] = false;
    $GLOBALS['wei_fr_test_current_user_can_manage_options'] = false;
    $_GET = ['page' => 'ebay-auth-callback', 'code' => 'CODE_BOOTSTRAP', 'state' => 'wei_fr:STATE_BOOTSTRAP'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&code=CODE_BOOTSTRAP&state=wei_fr:STATE_BOOTSTRAP';
    $auth->handle_admin_bootstrap_oauth_callback();
    $bootstrapDiagnostics = $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY];
    $assert(($bootstrapDiagnostics['callback_detected_at_plugins_loaded'] ?? null) === true, 'plugins_loaded must only record neutral callback_detected_at_plugins_loaded diagnostics.');
    $assert(!array_key_exists('intercept_hook', $bootstrapDiagnostics), 'plugins_loaded diagnostics must not write an intercept hook that can look like finalized callback processing.');
    $assert(($bootstrapDiagnostics['token_exchange_error'] ?? '') === 'not_wordpress_administrator', 'plugins_loaded must not rewrite token_exchange_error; stale values are cleared only when starting a new Connect attempt.');
    $assert(($bootstrapDiagnostics['oauth_status'] ?? '') === 'not_connected', 'plugins_loaded must not finalize OAuth callback failure status.');
    $assert(($bootstrapDiagnostics['code_received'] ?? null) === true, 'plugins_loaded must not rewrite code_received from its previous value.');
    $assert(($bootstrapDiagnostics['state_received'] ?? null) === true, 'plugins_loaded must not rewrite state_received from its previous value.');
    $assert(($GLOBALS['wei_fr_test_current_user_can_calls'] ?? 0) === 0, 'plugins_loaded callback detection must not call current_user_can.');
    $assert(($GLOBALS['wei_fr_test_actions'][0]['hook_name'] ?? '') === 'admin_init', 'plugins_loaded callback detection must register admin_init processing.');

    $GLOBALS['wei_fr_test_current_user_id'] = 7;
    $GLOBALS['wei_fr_test_is_user_logged_in'] = true;
    $GLOBALS['wei_fr_test_current_user_can_manage_options'] = true;

    $isCallbackRequest = $ref->getMethod('is_oauth_callback_request');
    $isCallbackRequest->setAccessible(true);

    $_GET = ['page' => 'ebay-auth-callback', 'code' => 'CODE1', 'state' => 'wei_fr:STATE1'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&code=CODE1&state=wei_fr:STATE1';
    $assert($isCallbackRequest->invoke($auth) === true, 'Callback detector must catch admin.php callback via $_GET page.');

    $_GET = ['code' => 'CODE2'];
    $_REQUEST = ['page' => 'ebay-auth-callback', 'code' => 'CODE2', 'state' => 'wei_fr:STATE2'];
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
    $assert($isCallbackRequest->invoke($auth) === true, 'Callback detector must catch admin.php callback via $_REQUEST page.');

    $_GET = ['code' => 'CODE3'];
    $_REQUEST = ['code' => 'CODE3', 'state' => 'wei_fr:STATE3'];
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&code=CODE3&state=wei_fr:STATE3';
    $assert($isCallbackRequest->invoke($auth) === true, 'Callback detector must catch admin.php callback via REQUEST_URI.');

    $_GET = ['page' => 'woo-ebay-fr'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=woo-ebay-fr';
    $assert($isCallbackRequest->invoke($auth) === false, 'Callback detector must ignore normal plugin admin pages.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo 'EbayOAuthFlow regression tests passed' . PHP_EOL;
}
