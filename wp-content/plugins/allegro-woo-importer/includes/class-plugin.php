<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin
{
    public const OPTION_KEY = 'awi_settings';
    public const HISTORY_OPTION_KEY = 'awi_import_history';
    public const CRON_HOOK = 'awi_run_scheduled_import';
    public const MISSING_IMPORT_CRON_HOOK = 'awi_run_missing_import_batch';
    public const SAFE_MODE_OPTION_KEY = 'awi_safe_mode_enabled';

    private static ?self $instance = null;
    private Logger $logger;
    private Settings $settings;
    private AllegroAuth $auth;
    private AllegroClient $client;
    private ProductMapper $mapper;
    private Importer $importer;
    private Cron $cron;
    private Cli $cli;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->load_dependencies();

        $this->logger = new Logger();
        $this->auth = new AllegroAuth($this->logger);
        $this->client = new AllegroClient($this->auth, $this->logger);
        $this->mapper = new ProductMapper($this->client, $this->logger);
        $this->importer = new Importer($this->client, $this->mapper, $this->logger);
        $this->cron = new Cron($this->importer, $this->logger);
        $this->cli = new Cli($this->mapper, $this->logger);
        $this->settings = new Settings($this->auth, $this->importer, $this->logger, $this->cron, $this->mapper);

        add_action('plugins_loaded', [$this, 'bootstrap'], 20);

        register_activation_hook(AWI_PLUGIN_FILE, [Cron::class, 'on_activation']);
        register_deactivation_hook(AWI_PLUGIN_FILE, [Cron::class, 'on_deactivation']);
    }

    private function load_dependencies(): void
    {
        require_once AWI_PLUGIN_DIR . 'includes/class-logger.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-allegro-auth.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-allegro-client.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-product-mapper.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-importer.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-cron.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-cli.php';
        require_once AWI_PLUGIN_DIR . 'includes/class-settings.php';
    }

    public function bootstrap(): void
    {
        if (!class_exists('WooCommerce')) {
            $this->logger->warning('WooCommerce is not active. Importer initialization skipped.');
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        $this->settings->hooks();
        $this->cron->hooks();
        $this->auth->hooks();
        $this->cli->register();
        add_action('woocommerce_product_set_stock_status', [$this, 'handle_woo_stock_status_change'], 10, 3);
    }

    public function handle_woo_stock_status_change($product_id, $status, $product): void
    {
        if ((string) $status !== 'outofstock') {
            return;
        }

        $this->importer->sync_woo_sold_out_to_allegro((int) $product_id);
    }

    public function woocommerce_missing_notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html__('Allegro Woo Importer wymaga aktywnej wtyczki WooCommerce.', 'allegro-woo-importer') . '</p></div>';
    }

    public static function get_settings(): array
    {
        $defaults = [
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'environment' => 'production',
            'sync_mode' => 'create_update',
            'inactive_product_status' => 'draft',
            'cron_interval' => 'manual',
            'offer_status' => 'ACTIVE',
            'reconciliation_enabled' => false,
            'destructive_sync_enabled' => false,
            'destructive_sync_dry_run' => true,
            'destructive_sync_max_changes' => 10,
            'access_token' => '',
            'refresh_token' => '',
            'expires_at' => '',
            'token_expires_at' => '',
            'token_scope' => '',
            'token_type' => '',
            'connected_at' => '',
            'awi_order_events_access_denied_notice' => 0,
            'last_sync_at' => '',
            'last_sync_created' => 0,
            'last_sync_updated' => 0,
            'last_sync_errors' => 0,
            'last_sync_offers' => 0,
        ];

        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $defaults);
    }

    public static function update_settings(array $new_settings): bool
    {
        $current = self::get_settings();
        $merged = array_merge($current, $new_settings);

        return update_option(self::OPTION_KEY, $merged, false);
    }

    public static function add_history(array $entry): void
    {
        $history = get_option(self::HISTORY_OPTION_KEY, []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, $entry);
        $history = array_slice($history, 0, 30);

        update_option(self::HISTORY_OPTION_KEY, $history, false);
    }

    public static function get_listing_image_id_for_product(int $product_id): int
    {
        $instance = self::instance();
        return $instance->mapper->get_preferred_listing_image_id($product_id);
    }

    public static function is_safe_mode_enabled(): bool
    {
        $raw = get_option(self::SAFE_MODE_OPTION_KEY, null);
        if ($raw === null) {
            return true;
        }

        return !empty($raw);
    }
}
