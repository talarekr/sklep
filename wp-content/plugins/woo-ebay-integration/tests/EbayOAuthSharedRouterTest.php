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
    $GLOBALS['wei_de_test_options'] = [];
    $GLOBALS['wei_de_test_transients'] = [];
    $GLOBALS['wei_de_test_current_user_can_calls'] = 0;

    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_de_test_options'][$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = true): bool
    {
        $GLOBALS['wei_de_test_options'][$key] = $value;
        return true;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://gpswiss.pl/wp-admin/' . ltrim($path, '/');
    }

    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
    {
        return 'DESTATE123';
    }

    function get_current_user_id(): int
    {
        return 7;
    }

    function current_user_can(string $capability): bool
    {
        $GLOBALS['wei_de_test_current_user_can_calls']++;
        return true;
    }

    function set_transient(string $key, $value, int $expiration): bool
    {
        $GLOBALS['wei_de_test_transients'][$key] = $value;
        return true;
    }

    function current_time(string $type): string
    {
        return '2026-06-03 00:00:00';
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

    $GLOBALS['wei_de_test_options'][Plugin::OPTION_KEY] = [
        'client_id' => 'client',
        'client_secret' => 'secret',
        'runame' => 'GP_SWISS-GPSWISS-GPSwiss-jigmn',
        'refresh_token' => '',
    ];

    $auth = new EbayAuth(new Logger());
    $authorizeUrl = $auth->get_authorize_url();
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeParams);

    $assert(str_starts_with((string) ($authorizeParams['state'] ?? ''), 'wei_de:'), 'DE Connect state must use the DE state prefix.');
    $assert(isset($GLOBALS['wei_de_test_transients']['wei_oauth_state_wei_de:DESTATE123']), 'DE Connect must store prefixed DE state under the DE state key.');

    $_GET = ['page' => 'ebay-auth-callback', 'code' => 'CODE_FR', 'state' => 'wei_fr:FRSTATE'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&code=CODE_FR&state=wei_fr:FRSTATE';
    $auth->maybe_intercept_oauth_callback('admin_init');
    $diagnostics = $GLOBALS['wei_de_test_options'][Plugin::OPTION_KEY];

    $assert(($GLOBALS['wei_de_test_current_user_can_calls'] ?? 0) === 0, 'DE router must not validate or process FR-prefixed state.');
    $assert(($diagnostics['routed_plugin'] ?? '') === 'FR', 'DE diagnostics should show FR-prefixed callbacks are routed away from DE.');
    $assert(($diagnostics['routed_marketplace'] ?? '') === 'EBAY_FR', 'DE diagnostics should identify FR marketplace when skipping FR-prefixed state.');
    $assert(($diagnostics['token_exchange_attempted'] ?? null) === false, 'DE router must not attempt token exchange for FR-prefixed state.');

    $oauthDiagnostics = $auth->get_diagnostic_oauth_context();
    $assert(($oauthDiagnostics['oauth_shared_callback'] ?? null) === true, 'DE diagnostics must mark the shared callback.');
    $assert(($oauthDiagnostics['oauth_callback_router'] ?? '') === 'state_prefix', 'DE diagnostics must identify state-prefix routing.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo 'EbayOAuthSharedRouter regression tests passed' . PHP_EOL;
}
