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
        return 7;
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
    $authorizeUrl = $auth->get_authorize_url();
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeParams);
    $diagnostics = $auth->get_diagnostic_oauth_context();

    $assert(($authorizeParams['redirect_uri'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Authorization URL must use eBay RuName as redirect_uri.');
    $assert(($diagnostics['callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-fr-auth-callback', 'Diagnostics callback_url must be the full browser callback URL.');
    $assert(($diagnostics['browser_callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-fr-auth-callback', 'Diagnostics browser_callback_url must be the full browser callback URL.');
    $assert(($diagnostics['ebay_runame'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics ebay_runame must be separated from callback URL.');
    $assert(($diagnostics['oauth_redirect_param_used'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics oauth_redirect_param_used must be the RuName.');
    $assert(($diagnostics['token_exchange_attempted'] ?? true) === false, 'Connect URL generation must not attempt token exchange or refresh-token API calls.');

    $ref = new ReflectionClass(EbayAuth::class);
    $source = file_get_contents($ref->getFileName());
    $assert(str_contains($source, '\'redirect_uri\' => $redirectUri'), 'Token exchange body must use the same OAuth redirect parameter variable.');
    $assert(str_contains($source, 'callback_intercepted_by_admin_init'), 'OAuth diagnostics must track admin_init callback interception.');
    $assert(str_contains($source, 'intercept_hook'), 'OAuth diagnostics must store the hook that intercepted the callback.');
    $assert(str_contains($source, 'request_uri'), 'OAuth diagnostics must store the raw callback request URI.');
    $assert(str_contains($source, 'raw_get_keys'), 'OAuth diagnostics must store raw GET keys for callback debugging.');
    $assert(str_contains($source, 'WEI OAuth callback intercept hit: request_uri='), 'OAuth callback intercept must emit temporary error_log diagnostics.');
    $assert(str_contains($source, 'Please log in as WordPress administrator and retry eBay Connect.'), 'Non-admin callback must show a clear administrator login message.');
    $assert(str_contains($source, '\'code_received\' => $code !== \'\''), 'Callback diagnostics must record code_received for code callbacks.');
    $assert(str_contains($source, '\'oauth_status\' => $error !== \'\' ? \'callback_error\' : \'callback_received\''), 'Callback diagnostics must record clear eBay error/decline callbacks.');
    $assert(str_contains($source, "new \\WP_Error('wei_fr_missing_refresh', 'Missing eBay refresh token')"), 'Export without refresh token must still report wei_fr_missing_refresh / Missing eBay refresh token.');

    $pluginSource = file_get_contents(__DIR__ . '/../src/Plugin.php');
    $bootstrapSource = file_get_contents(__DIR__ . '/../woo-ebay-integration-fr.php');
    $assert(str_contains($pluginSource, "add_action('admin_init', [\$auth, 'handle_oauth_callback'], 0)"), 'Plugin must register the OAuth callback interceptor on global admin_init priority 0.');
    $assert(str_contains($pluginSource, "add_action('current_screen', [\$auth, 'handle_current_screen_oauth_callback'], 0)"), 'Plugin must register current_screen OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('load-admin_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [\$auth, 'handle_load_oauth_callback'], 0)"), 'Plugin must register load-admin_page OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('load-woocommerce_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [\$auth, 'handle_woocommerce_load_oauth_callback'], 0)"), 'Plugin must register WooCommerce submenu load OAuth callback fallback.');
    $assert(str_contains($pluginSource, "add_action('admin_post_nopriv_' . EbayAuth::ADMIN_POST_CALLBACK_ACTION"), 'Plugin must register nopriv admin-post OAuth callback fallback.');
    $assert(str_contains($bootstrapSource, 'handle_admin_bootstrap_oauth_callback'), 'Main plugin file must register a bootstrap-level callback interceptor before WooCommerce-dependent boot.');

    $isCallbackRequest = $ref->getMethod('is_oauth_callback_request');
    $isCallbackRequest->setAccessible(true);

    $_GET = ['page' => 'ebay-fr-auth-callback', 'code' => 'CODE1', 'state' => 'STATE1'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-fr-auth-callback&code=CODE1&state=STATE1';
    $assert($isCallbackRequest->invoke($auth) === true, 'Callback detector must catch admin.php callback via $_GET page.');

    $_GET = ['code' => 'CODE2'];
    $_REQUEST = ['page' => 'ebay-fr-auth-callback', 'code' => 'CODE2'];
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
    $assert($isCallbackRequest->invoke($auth) === true, 'Callback detector must catch admin.php callback via $_REQUEST page.');

    $_GET = ['code' => 'CODE3'];
    $_REQUEST = ['code' => 'CODE3'];
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-fr-auth-callback&code=CODE3';
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
