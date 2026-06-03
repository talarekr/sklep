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

namespace WEI_FR {
    if (!class_exists(__NAMESPACE__ . '\\Plugin')) {
        class Plugin
        {
            public const OPTION_KEY = 'wei_fr_ebay_settings';
        }
    }
}

namespace {
    final class WeiSharedRouterRedirect extends RuntimeException
    {
        public function __construct(public readonly string $location)
        {
            parent::__construct('redirect');
        }
    }

    $GLOBALS['wei_shared_router_options'] = [];
    $GLOBALS['wei_shared_router_transients'] = [];
    $GLOBALS['wei_shared_router_wp_die_called'] = false;
    $GLOBALS['wei_shared_router_current_user_can_calls'] = 0;
    $GLOBALS['wei_shared_router_remote_posts'] = [];
    $GLOBALS['wei_shared_router_actions'] = [];
    $GLOBALS['wei_shared_router_uploads_basedir'] = sys_get_temp_dir() . '/wei-shared-router-test-' . getmypid();

    function get_option(string $key, $default = false)
    {
        return $GLOBALS['wei_shared_router_options'][$key] ?? $default;
    }

    function update_option(string $key, $value, bool $autoload = true): bool
    {
        $GLOBALS['wei_shared_router_options'][$key] = $value;
        return true;
    }

    function admin_url(string $path = ''): string
    {
        return 'https://gpswiss.pl/wp-admin/' . ltrim($path, '/');
    }

    function get_current_user_id(): int
    {
        return 7;
    }

    function is_user_logged_in(): bool
    {
        return true;
    }

    function current_user_can(string $capability): bool
    {
        $GLOBALS['wei_shared_router_current_user_can_calls']++;
        return $capability === 'manage_options';
    }

    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wei_shared_router_actions'][] = compact('hook', 'callback', 'priority', 'accepted_args');
        return true;
    }

    function has_action(string $hook, $callback = false)
    {
        foreach ($GLOBALS['wei_shared_router_actions'] as $action) {
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
        foreach ($GLOBALS['wei_shared_router_actions'] as $action) {
            if (($action['hook'] ?? '') === $hook && is_callable($action['callback'] ?? null)) {
                call_user_func_array($action['callback'], array_slice($args, 0, (int) ($action['accepted_args'] ?? 1)));
            }
        }
    }

    function wp_upload_dir($time = null, bool $create_dir = true, bool $refresh_cache = false): array
    {
        return ['basedir' => $GLOBALS['wei_shared_router_uploads_basedir']];
    }

    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0775, true);
    }

    function get_transient(string $key)
    {
        return $GLOBALS['wei_shared_router_transients'][$key] ?? false;
    }

    function set_transient(string $key, $value, int $expiration): bool
    {
        $GLOBALS['wei_shared_router_transients'][$key] = $value;
        return true;
    }

    function delete_transient(string $key): bool
    {
        unset($GLOBALS['wei_shared_router_transients'][$key]);
        return true;
    }

    function current_time(string $type): string
    {
        return '2026-06-03 12:00:00';
    }

    function wp_remote_post(string $url, array $args = [])
    {
        $GLOBALS['wei_shared_router_remote_posts'][] = compact('url', 'args');
        return [
            'response' => ['code' => 200],
            'body' => json_encode([
                'access_token' => 'FR_ACCESS_TOKEN',
                'refresh_token' => 'FR_REFRESH_TOKEN',
                'expires_in' => 7200,
                'scope' => 'fr-scope',
            ]),
        ];
    }

    function wp_remote_retrieve_response_code($response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }

    function wp_remote_retrieve_body($response): string
    {
        return (string) ($response['body'] ?? '');
    }

    function is_wp_error($thing): bool
    {
        return false;
    }

    function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress')
    {
        throw new WeiSharedRouterRedirect($location);
    }

    function wp_die($message = '', $title = '', $args = []): void
    {
        $GLOBALS['wei_shared_router_wp_die_called'] = true;
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
    require_once __DIR__ . '/../../woo-ebay-integration-fr/src/Services/Logger.php';
    require_once __DIR__ . '/../../woo-ebay-integration-fr/src/Services/EbayAuth.php';

    use WEI\Plugin as DePlugin;
    use WEI\Services\EbayAuth as DeEbayAuth;
    use WEI\Services\Logger as DeLogger;
    use WEI_FR\Plugin as FrPlugin;
    use WEI_FR\Services\EbayAuth as FrEbayAuth;
    use WEI_FR\Services\Logger as FrLogger;

    add_action('wei_fr_handle_shared_oauth_callback', static function (array $request): void {
        (new FrEbayAuth(new FrLogger()))->handle_shared_callback($request);
    }, 10, 1);

    $failures = [];
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $state = 'wei_fr:RETURNED_STATE';
    $GLOBALS['wei_shared_router_options'][DePlugin::OPTION_KEY] = [
        'client_id' => 'de-client',
        'client_secret' => 'de-secret',
        'refresh_token' => '',
    ];
    $GLOBALS['wei_shared_router_options'][FrPlugin::OPTION_KEY] = [
        'client_id' => 'fr-client',
        'client_secret' => 'fr-secret',
        'runame' => 'GP_SWISS-GPSWISS-GPSwiss-jigmn',
        'refresh_token' => '',
    ];
    $GLOBALS['wei_shared_router_transients']['wei_fr_oauth_state_' . $state] = [
        'user_id' => 7,
        'attempt_id' => 'attempt-fr-1',
    ];

    $_GET = ['page' => 'ebay-auth-callback', 'state' => $state, 'code' => 'FR_CODE', 'expires_in' => '299'];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ebay-auth-callback&state=wei_fr:RETURNED_STATE&code=FR_CODE&expires_in=299';

    $bootstrapAuth = new DeEbayAuth(new DeLogger());
    $bootstrapAuth->handle_admin_bootstrap_oauth_callback();
    $debugPath = $GLOBALS['wei_shared_router_uploads_basedir'] . '/wei-ebay-integration-fr/oauth-shared-callback-debug.json';
    $debugPayload = is_readable($debugPath) ? (array) json_decode((string) file_get_contents($debugPath), true) : [];
    $registeredAdminInitRouter = false;
    $registeredAdminInitCallback = null;
    foreach ($GLOBALS['wei_shared_router_actions'] as $action) {
        if (($action['hook'] ?? '') === 'admin_init' && ($action['priority'] ?? null) === 0) {
            $registeredAdminInitRouter = true;
            $registeredAdminInitCallback = $action['callback'] ?? null;
            break;
        }
    }

    $redirectLocation = '';
    try {
        if (is_callable($registeredAdminInitCallback)) {
            call_user_func($registeredAdminInitCallback);
        }
    } catch (WeiSharedRouterRedirect $redirect) {
        $redirectLocation = $redirect->location;
    }
    $adminInitDebugPayload = is_readable($debugPath) ? (array) json_decode((string) file_get_contents($debugPath), true) : [];

    $deSettings = $GLOBALS['wei_shared_router_options'][DePlugin::OPTION_KEY];
    $frSettings = $GLOBALS['wei_shared_router_options'][FrPlugin::OPTION_KEY];
    $remotePost = $GLOBALS['wei_shared_router_remote_posts'][0] ?? [];
    $deAuthSource = file_get_contents(__DIR__ . '/../src/Services/EbayAuth.php');

    $assert($registeredAdminInitRouter, 'Exact shared callback URL must register an admin_init router before WordPress can render an insufficient-permissions admin page.');
    $assert(is_callable($registeredAdminInitCallback), 'Registered admin_init priority 0 shared router callback must be callable.');
    $assert(($debugPayload['hook_name'] ?? '') === 'plugins_loaded', 'DE bootstrap must write the early shared callback debug file at plugins_loaded.');
    $assert(($debugPayload['page'] ?? '') === 'ebay-auth-callback', 'Early shared callback debug file must record the callback page parameter.');
    $assert(($debugPayload['state_prefix'] ?? '') === 'wei_fr', 'Early shared callback debug file must record the FR state prefix.');
    $assert(($debugPayload['has_code'] ?? null) === true, 'Early shared callback debug file must record that the OAuth code is present.');
    $assert(($debugPayload['routed_plugin_attempt'] ?? '') === 'FR', 'Early shared callback debug file must record the FR routed plugin attempt.');
    $assert(($debugPayload['event'] ?? '') === 'WEI_SHARED_OAUTH_FR_DETECTED', 'Early shared callback debug file must record the explicit FR detection event.');
    $assert(($adminInitDebugPayload['event'] ?? '') === 'WEI_SHARED_OAUTH_FR_HANDLER_CALLED', 'admin_init priority 0 router must record that it called the FR shared handler.');
    $assert(($adminInitDebugPayload['fr_handler_callable_found'] ?? null) === true, 'admin_init router diagnostics must show that the FR handler callable was found.');
    $assert(($adminInitDebugPayload['current_user_id'] ?? null) === 7, 'admin_init router diagnostics must include current_user_id.');
    $assert(($adminInitDebugPayload['is_user_logged_in'] ?? null) === true, 'admin_init router diagnostics must include is_user_logged_in.');
    $assert(($adminInitDebugPayload['current_user_can_manage_options'] ?? null) === true, 'admin_init router diagnostics must include manage_options capability.');
    $assert($redirectLocation === 'https://gpswiss.pl/wp-admin/admin.php?page=woo-ebay-fr&ebay_connected=1&oauth_status=connected', 'FR callback must redirect back to the FR plugin admin page after success.');
    $assert(($frSettings['refresh_token'] ?? '') === 'FR_REFRESH_TOKEN', 'FR handler must save the returned refresh token in FR-specific options.');
    $assert(($frSettings['access_token'] ?? '') === 'FR_ACCESS_TOKEN', 'FR handler must save the returned access token in FR-specific options.');
    $assert(!array_key_exists('refresh_token', $deSettings) || (string) $deSettings['refresh_token'] === '', 'DE settings must not receive the FR refresh token.');
    $assert(count($GLOBALS['wei_shared_router_remote_posts']) === 1, 'Exactly one token exchange must run for the FR callback.');
    $assert(($remotePost['args']['body']['code'] ?? '') === 'FR_CODE', 'FR token exchange must receive the returned OAuth code.');
    $assert(($remotePost['args']['body']['redirect_uri'] ?? '') === 'GP_SWISS-GPSWISS-GPSwiss-jigmn', 'FR token exchange must use the configured FR RuName redirect_uri.');
    $assert(($frSettings['shared_callback_seen'] ?? null) === true, 'FR diagnostics must record shared_callback_seen.');
    $assert(($frSettings['shared_callback_state_prefix'] ?? '') === 'wei_fr', 'FR diagnostics must record the shared callback state prefix.');
    $assert(($frSettings['shared_callback_routed_before_admin_page_render'] ?? null) === true, 'FR diagnostics must record routing before admin page render.');
    $assert(($frSettings['routed_plugin'] ?? '') === 'FR', 'FR diagnostics must identify the routed plugin.');
    $assert(($frSettings['routed_marketplace'] ?? '') === 'EBAY_FR', 'FR diagnostics must identify the routed marketplace.');
    $assert(($frSettings['code_received'] ?? null) === true, 'FR diagnostics must record that code was received.');
    $assert(($frSettings['state_received'] ?? null) === true, 'FR diagnostics must record that state was received.');
    $assert(($frSettings['callback_hook_stage'] ?? '') === 'admin_init', 'FR diagnostics must record admin_init as the callback hook stage.');
    $assert(($frSettings['token_exchange_success'] ?? null) === true, 'FR diagnostics must record successful token exchange.');
    $assert(($GLOBALS['wei_shared_router_wp_die_called'] ?? false) === false, 'Logged-in admin callback must not produce a WordPress insufficient-permissions wp_die page.');
    $assert(($deSettings['token_exchange_attempted'] ?? null) === false, 'DE handler must not process/token-exchange FR-prefixed state.');
    $assert(($deSettings['routed_plugin'] ?? '') === 'FR', 'DE shared router diagnostics must show FR routing for FR-prefixed state.');
    $frMainSource = file_get_contents(__DIR__ . '/../../woo-ebay-integration-fr/woo-ebay-integration-fr.php');
    $frAuthSource = file_get_contents(__DIR__ . '/../../woo-ebay-integration-fr/src/Services/EbayAuth.php');
    $assert(str_contains($frMainSource, "add_action('wei_fr_handle_shared_oauth_callback'"), 'FR plugin must register the shared OAuth callback handler action.');
    $assert(str_contains($frAuthSource, 'handle_shared_callback'), 'FR OAuth service must expose a shared callback handler method.');
    $assert(str_contains($deAuthSource, 'WEI_SHARED_OAUTH_FR_ADMIN_INIT_ROUTER_REGISTERED'), 'DE shared router source must log admin_init router registration for FR callbacks.');
    $assert(str_contains($deAuthSource, 'WEI_SHARED_OAUTH_FR_HANDLER_FOUND'), 'DE shared router source must log when the FR shared handler is found.');
    $assert(str_contains($deAuthSource, 'WEI_SHARED_OAUTH_FR_HANDLER_CALLED'), 'DE shared router source must log before calling the FR shared handler.');
    $assert(str_contains($deAuthSource, 'WEI_SHARED_OAUTH_FR_ROUTED_AND_EXITING'), 'DE shared router source must log before handing off to FR and exiting.');
    $assert(str_contains($deAuthSource, 'WEI_SHARED_OAUTH_FR_HANDLER_NOT_FOUND'), 'DE shared router source must log and write debug output when the FR handler cannot be found.');

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo 'EbayOAuthSharedFrAdminInitRouter regression tests passed' . PHP_EOL;
}
