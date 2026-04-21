<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroAuth
{
    public const CALLBACK_ACTION = 'awi_oauth_callback';

    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public static function get_default_redirect_uri(): string
    {
        return admin_url('admin-post.php?action=' . self::CALLBACK_ACTION);
    }

    public function hooks(): void
    {
        add_action('admin_post_' . self::CALLBACK_ACTION, [$this, 'handle_oauth_callback']);
    }

    public function get_authorization_url(): string
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);
        $redirect_uri = $this->resolve_redirect_uri($settings);

        $state = wp_generate_password(32, false, false);
        set_transient('awi_oauth_state_' . $state, [
            'user_id' => get_current_user_id(),
            'created_at' => time(),
        ], 10 * MINUTE_IN_SECONDS);

        $query = [
            'response_type' => 'code',
            'client_id' => $settings['client_id'],
            'redirect_uri' => $redirect_uri,
            'state' => $state,
        ];

        return add_query_arg($query, $base . '/auth/oauth/authorize');
    }

    public function handle_oauth_callback(): void
    {
        $this->logger->info('OAuth callback started.', ['request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '']);

        if (!current_user_can('manage_woocommerce')) {
            $this->logger->error('OAuth callback rejected due to missing capability.');
            $this->redirect_to_settings('error', __('Brak uprawnień do zakończenia autoryzacji Allegro.', 'allegro-woo-importer'));
        }

        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        if ($state === '') {
            $this->logger->error('OAuth callback missing state.');
            $this->redirect_to_settings('error', __('Brak parametru state w callbacku OAuth.', 'allegro-woo-importer'));
        }

        $state_data = get_transient('awi_oauth_state_' . $state);
        if (empty($state_data) || !is_array($state_data)) {
            $this->logger->error('OAuth callback invalid state.', ['state' => $state]);
            $this->redirect_to_settings('error', __('Nieprawidłowy lub wygasły parametr state.', 'allegro-woo-importer'));
        }

        delete_transient('awi_oauth_state_' . $state);

        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        if ($error !== '') {
            $this->logger->error('OAuth callback returned Allegro error.', ['error' => $error]);
            $this->redirect_to_settings('error', sprintf(__('Allegro zwróciło błąd autoryzacji: %s', 'allegro-woo-importer'), $error));
        }

        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '';
        $this->logger->info('OAuth callback code inspection.', ['has_code' => $code !== '' ? 'yes' : 'no']);

        if ($code === '') {
            $this->logger->error('OAuth callback missing code after state validation.');
            $this->redirect_to_settings('error', __('Brak parametru code w callbacku OAuth.', 'allegro-woo-importer'));
        }

        $token_response = $this->request_token([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->resolve_redirect_uri(Plugin::get_settings()),
        ]);

        if (is_wp_error($token_response)) {
            $this->logger->error('OAuth token exchange failed.', ['error' => $token_response->get_error_message()]);
            $this->redirect_to_settings('error', __('Nie udało się wymienić code na access token.', 'allegro-woo-importer'));
        }

        $this->persist_token_response($token_response);
        $this->logger->info('OAuth token exchange successful.');

        $this->redirect_to_settings('success', __('Połączono z Allegro poprawnie.', 'allegro-woo-importer'));
    }

    public function get_valid_access_token()
    {
        $settings = Plugin::get_settings();

        if (empty($settings['access_token'])) {
            return new \WP_Error('awi_missing_token', __('Brak access tokena Allegro.', 'allegro-woo-importer'));
        }

        $expires_at = !empty($settings['token_expires_at']) ? strtotime((string) $settings['token_expires_at']) : 0;
        if ($expires_at > (time() + 60)) {
            return $settings['access_token'];
        }

        if (empty($settings['refresh_token'])) {
            return new \WP_Error('awi_missing_refresh_token', __('Brak refresh tokena Allegro.', 'allegro-woo-importer'));
        }

        $this->logger->info('Refreshing Allegro access token.');

        $refresh_response = $this->request_token([
            'grant_type' => 'refresh_token',
            'refresh_token' => $settings['refresh_token'],
            'redirect_uri' => $this->resolve_redirect_uri($settings),
        ]);

        if (is_wp_error($refresh_response)) {
            $this->logger->error('Token refresh failed.', ['error' => $refresh_response->get_error_message()]);
            return $refresh_response;
        }

        if (empty($refresh_response['refresh_token'])) {
            $refresh_response['refresh_token'] = $settings['refresh_token'];
        }

        $this->persist_token_response($refresh_response);

        $new_settings = Plugin::get_settings();
        return $new_settings['access_token'] ?: new \WP_Error('awi_missing_token_after_refresh', __('Nie udało się zapisać odświeżonego tokena.', 'allegro-woo-importer'));
    }

    private function request_token(array $body)
    {
        $settings = Plugin::get_settings();
        $base = $this->get_auth_base_url($settings['environment']);

        if (empty($settings['client_id']) || empty($settings['client_secret'])) {
            return new \WP_Error('awi_missing_client_credentials', __('Brak client_id lub client_secret w ustawieniach.', 'allegro-woo-importer'));
        }

        $credentials = base64_encode($settings['client_id'] . ':' . $settings['client_secret']);
        $response = wp_remote_post($base . '/auth/oauth/token', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'body' => http_build_query($body, '', '&'),
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('OAuth token request transport error.', ['error' => $response->get_error_message()]);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($status < 200 || $status > 299 || !is_array($data)) {
            $this->logger->error('OAuth token request returned error response.', ['status' => $status, 'body' => $raw]);
            return new \WP_Error('awi_token_request_failed', __('Błąd podczas pobierania tokena Allegro.', 'allegro-woo-importer'), ['status' => $status, 'body' => $raw]);
        }

        return $data;
    }

    private function persist_token_response(array $token_data): void
    {
        $existing = Plugin::get_settings();
        $expires_in = isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 0;
        $expires_at = gmdate('Y-m-d H:i:s', time() + max(0, $expires_in));

        Plugin::update_settings([
            'redirect_uri' => $this->resolve_redirect_uri($existing),
            'access_token' => sanitize_text_field($token_data['access_token'] ?? ''),
            'refresh_token' => sanitize_text_field($token_data['refresh_token'] ?? ($existing['refresh_token'] ?? '')),
            'token_type' => sanitize_text_field($token_data['token_type'] ?? ''),
            'token_scope' => sanitize_text_field($token_data['scope'] ?? ''),
            'token_expires_at' => $expires_at,
            'connected_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $this->logger->info('OAuth token persisted.', ['expires_at' => $expires_at]);
    }

    private function resolve_redirect_uri(array $settings): string
    {
        $configured = isset($settings['redirect_uri']) ? trim((string) $settings['redirect_uri']) : '';

        if ($configured !== '') {
            return esc_url_raw($configured);
        }

        return self::get_default_redirect_uri();
    }

    private function redirect_to_settings(string $status, string $message): void
    {
        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_oauth_status' => $status,
            'awi_oauth_message' => $message,
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    private function get_auth_base_url(string $environment): string
    {
        return $environment === 'sandbox' ? 'https://allegro.pl.allegrosandbox.pl' : 'https://allegro.pl';
    }
}
