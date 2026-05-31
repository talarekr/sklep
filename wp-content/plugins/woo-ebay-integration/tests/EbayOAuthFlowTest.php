<?php

declare(strict_types=1);

namespace WEI {
    if (!class_exists(__NAMESPACE__ . '\\Plugin')) {
        class Plugin
        {
            public const OPTION_KEY = 'wei_ebay_settings';
        }
    }
}

namespace {
    $GLOBALS['wei_test_options'] = [];
    $GLOBALS['wei_test_transients'] = [];

    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_test_options'][$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = true): bool
    {
        $GLOBALS['wei_test_options'][$key] = $value;
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
        $GLOBALS['wei_test_transients'][$key] = $value;
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

    use WEI\Plugin;
    use WEI\Services\EbayAuth;
    use WEI\Services\Logger;

    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $GLOBALS['wei_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'GPSWISS-GPSwiss-PRD-dbddbd5ea-53182c46',
        'client_secret' => 'secret',
        'redirect_uri' => 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback',
        'runame' => 'GP_SWISS-GPSWISS-GPSwiss-jigmn',
        'refresh_token' => '',
    ];

    $auth = new EbayAuth(new Logger());
    $authorizeUrl = $auth->get_authorize_url();
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeParams);
    $diagnostics = $auth->get_diagnostic_oauth_context();

    $assert(($authorizeParams['redirect_uri'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Authorization URL must use eBay RuName as redirect_uri.');
    $assert(($diagnostics['callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback', 'Diagnostics callback_url must be the full browser callback URL.');
    $assert(($diagnostics['browser_callback_url'] ?? '') === 'https://gpswiss.pl/wp-admin/admin.php?page=ebay-auth-callback', 'Diagnostics browser_callback_url must be the full browser callback URL.');
    $assert(($diagnostics['ebay_runame'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics ebay_runame must be separated from callback URL.');
    $assert(($diagnostics['oauth_redirect_param_used'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'Diagnostics oauth_redirect_param_used must be the RuName.');
    $assert(($diagnostics['token_exchange_attempted'] ?? true) === false, 'Connect URL generation must not attempt token exchange or refresh-token API calls.');

    $ref = new ReflectionClass(EbayAuth::class);
    $source = file_get_contents($ref->getFileName());
    $assert(str_contains($source, '\'redirect_uri\' => $redirectUri'), 'Token exchange body must use the same OAuth redirect parameter variable.');
    $assert(str_contains($source, 'callback_intercepted_by_admin_init'), 'OAuth diagnostics must track admin_init callback interception.');
    $assert(str_contains($source, 'Please log in as WordPress administrator and retry eBay Connect.'), 'Non-admin callback must show a clear administrator login message.');
    $assert(str_contains($source, '\'code_received\' => $code !== \'\''), 'Callback diagnostics must record code_received for code callbacks.');
    $assert(str_contains($source, '\'oauth_status\' => $error !== \'\' ? \'callback_error\' : \'callback_received\''), 'Callback diagnostics must record clear eBay error/decline callbacks.');
    $assert(str_contains($source, "new \\WP_Error('wei_missing_refresh', 'Missing eBay refresh token')"), 'Export without refresh token must still report wei_missing_refresh / Missing eBay refresh token.');

    $pluginSource = file_get_contents(__DIR__ . '/../src/Plugin.php');
    $assert(str_contains($pluginSource, "add_action('admin_init', [\$auth, 'handle_oauth_callback'], 0)"), 'Plugin must register the OAuth callback interceptor on global admin_init priority 0.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo 'EbayOAuthFlow regression tests passed' . PHP_EOL;
}
