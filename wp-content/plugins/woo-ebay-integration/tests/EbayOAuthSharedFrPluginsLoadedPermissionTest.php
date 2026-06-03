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
    final class WeiSharedPermissionRedirect extends RuntimeException
    {
        public function __construct(public readonly string $location)
        {
            parent::__construct('redirect');
        }
    }

    $GLOBALS['wei_shared_permission_options'] = [];
    $GLOBALS['wei_shared_permission_actions'] = [];
    $GLOBALS['wei_shared_permission_handler_called'] = false;
    $GLOBALS['wei_shared_permission_wp_die_called'] = false;
    $GLOBALS['wei_shared_permission_uploads_basedir'] = sys_get_temp_dir() . '/wei-shared-permission-test-' . getmypid();

    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_shared_permission_options'][$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = true): bool
    {
        $GLOBALS['wei_shared_permission_options'][$key] = $value;
        return true;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://gpswiss.pl/wp-admin/' . ltrim($path, '/');
    }

    function get_current_user_id(): int
    {
        return 0;
    }

    function is_user_logged_in(): bool
    {
        return false;
    }

    function current_user_can(string $capability): bool
    {
        return false;
    }

    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wei_shared_permission_actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
        return true;
    }

    function has_action(string $hook, $callback = false)
    {
        foreach ($GLOBALS['wei_shared_permission_actions'] as $action) {
            if (($action['hook'] ?? '') !== $hook) {
                continue;
            }
            if ($callback === false || ($action['callback'] ?? null) === $callback) {
                return $action['priority'] ?? 10;
            }
        }

        return false;
    }

    function do_action(string $hook, ...$args): void
    {
        foreach ($GLOBALS['wei_shared_permission_actions'] as $action) {
            if (($action['hook'] ?? '') === $hook && is_callable($action['callback'] ?? null)) {
                call_user_func_array($action['callback'], array_slice($args, 0, (int) ($action['accepted_args'] ?? 1)));
            }
        }
    }

    function wp_upload_dir($time = null, bool $create_dir = true, bool $refresh_cache = false): array
    {
        return ['basedir' => $GLOBALS['wei_shared_permission_uploads_basedir']];
    }

    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0775, true);
    }

    function current_time(string $type): string
    {
        return '2026-06-03 12:00:00';
    }

    function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress')
    {
        throw new WeiSharedPermissionRedirect($location);
    }

    function wp_die($message = '', $title = '', $args = []): void
    {
        $GLOBALS['wei_shared_permission_wp_die_called'] = true;
        throw new RuntimeException('wp_die: ' . (string) $message);
    }

    function wp_json_encode($value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }

    if (!defined('MINUTE_IN_SECONDS')) {
        define('MINUTE_IN_SECONDS', 60);
    }

    require_once __DIR__ . '/../src/Services/Logger.php';
    require_once __DIR__ . '/../src/Services/EbayAuth.php';

    use WEI\Plugin as DePlugin;
    use WEI\Services\EbayAuth as DeEbayAuth;
    use WEI\Services\Logger as DeLogger;

    add_action('wei_fr_handle_shared_oauth_callback', static function (array $request): void {
        $GLOBALS['wei_shared_permission_handler_called'] = true;
    }, 10, 1);

    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $GLOBALS['wei_shared_permission_options'][DePlugin::OPTION_KEY] = [
        'client_id' => 'de-client',
        'client_secret' => 'de-secret',
        'refresh_token' => '',
    ];

    $_GET = ['page' => 'ebay-auth-callback', 'state' => 'wei_fr:NON_ADMIN_STATE', 'code' => 'FR_CODE'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&state=wei_fr:NON_ADMIN_STATE&code=FR_CODE';

    $auth = new DeEbayAuth(new DeLogger());
    $redirectLocation = '';
    try {
        $auth->handle_admin_bootstrap_oauth_callback();
    } catch (WeiSharedPermissionRedirect $redirect) {
        $redirectLocation = $redirect->location;
    }

    $debugPath = $GLOBALS['wei_shared_permission_uploads_basedir'] . '/wei-ebay-integration-fr/oauth-shared-callback-debug.json';
    $debugPayload = is_readable($debugPath) ? (array) json_decode((string) file_get_contents($debugPath), true) : [];
    $deSettings = $GLOBALS['wei_shared_permission_options'][DePlugin::OPTION_KEY];

    $assert(($debugPayload['event'] ?? '') === 'WEI_SHARED_OAUTH_FR_ADMIN_PERMISSION_DENIED', 'Non-admin debug file must record controlled FR admin permission denial.');
    $assert(($debugPayload['hook'] ?? '') === 'plugins_loaded', 'Non-admin debug file must show plugins_loaded handled the FR callback without admin_init.');
    $assert(str_contains($redirectLocation, 'page=woo-ebay'), 'Non-admin callback must redirect to the controlled DE admin error page.');
    $assert(str_contains($redirectLocation, 'oauth_error=fr_admin_permission_denied'), 'Non-admin callback redirect must include a controlled fr_admin_permission_denied error.');
    $assert(($GLOBALS['wei_shared_permission_handler_called'] ?? false) === false, 'Non-admin callback must not call the FR shared handler.');
    $assert(($GLOBALS['wei_shared_permission_wp_die_called'] ?? false) === false, 'Non-admin callback must not fall through to the WordPress permission denial page.');
    $assert(($deSettings['refresh_token'] ?? '') === '', 'Non-admin callback must not save an FR token into DE options.');
    $assert(($deSettings['token_exchange_attempted'] ?? null) !== true, 'Non-admin callback must not attempt DE token exchange.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo 'EbayOAuthSharedFrPluginsLoadedPermission regression tests passed' . PHP_EOL;
}
