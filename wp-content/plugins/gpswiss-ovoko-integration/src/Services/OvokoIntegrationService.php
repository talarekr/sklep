<?php

namespace GPSwiss\Ovoko\Services;

use GPSwiss\Ovoko\DTO\NormalizedOvokoPart;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class OvokoIntegrationService
{
    private const CSV_MAPPING_CACHE_TRANSIENT = 'gpswiss_ovoko_csv_mapping_index_v2';
    public const OPTION_KEY = 'gpswiss_ovoko_settings';
    public const EVENTS_OPTION_KEY = 'gpswiss_ovoko_recent_events';
    public const COUNTERS_OPTION_KEY = 'gpswiss_ovoko_event_counters';
    public const DEDUP_OPTION_KEY = 'gpswiss_ovoko_processed_event_ids';
    public const GEARBOX_COUNT_OPTION_KEY = 'gpswiss_ovoko_gearbox_exclusion_count';
    private const CATEGORY_REBUILD_AUTORUN_STATUS_OPTION = 'gpswiss_ovoko_category_rebuild_autorun_status';
    private const CATEGORY_REBUILD_AUTORUN_LOCK_OPTION = 'gpswiss_ovoko_category_rebuild_autorun_lock';
    public const CATEGORY_REBUILD_AUTORUN_STATUS_OPTION_NAME = self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION;
    public const CATEGORY_REBUILD_AUTORUN_LOCK_OPTION_NAME = self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION;

    private const PART_ID_META_KEYS = [
        '_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id',
        'external_part_id', '_allegro_source_id', '_ovoko_source_id',
    ];

    private const OVOKO_META_FIELDS = [
        '_ovoko_part_id','_ovoko_status','_ovoko_updated_at','_ovoko_source_payload_hash',
        '_ovoko_vehicle_make','_ovoko_vehicle_model','_ovoko_vehicle_generation','_ovoko_year',
        '_ovoko_engine_code','_ovoko_gearbox_code','_ovoko_oe_numbers','_ovoko_category',
        '_ovoko_source_url','_ovoko_images','_ovoko_price','_ovoko_raw_payload',
    ];

    private string $pluginFile;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function defaults(): array
    {
        return [
            'ovoko_callback_enabled' => false,
            'ovoko_callback_dry_run' => true,
            'ovoko_callback_header_name' => 'gpswiss',
            'ovoko_callback_header_secret' => '',
            'ovoko_supply_connector_enabled' => false,
            'ovoko_supply_connector_base_url' => 'https://supply-connector.ms.ovoko.com',
            'ovoko_supply_connector_token' => '',
            'ovoko_supply_connector_api_key' => '',
            'ovoko_integration_id' => '',
            'ovoko_sync_enabled' => false,
            'ovoko_sync_dry_run' => true,
            'ovoko_sync_mode' => 'disabled',
            'ovoko_sync_batch_limit' => 10,
            'ovoko_last_sync_at' => '',
            'rrr_api_enabled' => false,
            'rrr_api_dry_run' => true,
            'rrr_api_base_url' => 'https://api.rrr.lt',
            'rrr_api_username' => '',
            'rrr_api_password' => '',
            'rrr_api_user_token' => '',
            'ovoko_original_image_bearer_token' => '',
            'ovoko_exclude_gearbox_products' => true,
            'ovoko_csv_mapping' => [],
            'ovoko_csv_mapping_status' => [],
        ];
    }

    public function get_settings(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), $this->defaults());
    }

    public function save_settings(array $settings): void
    {
        $clean = $this->get_settings();
        $clean['ovoko_callback_enabled'] = !empty($settings['ovoko_callback_enabled']);
        $clean['ovoko_callback_dry_run'] = !empty($settings['ovoko_callback_dry_run']);
        $clean['ovoko_callback_header_name'] = sanitize_key((string) ($settings['ovoko_callback_header_name'] ?? 'gpswiss'));
        $newSecret = sanitize_text_field((string) ($settings['ovoko_callback_header_secret'] ?? ''));
        if ($newSecret !== '') {
            $clean['ovoko_callback_header_secret'] = $newSecret;
        }
        $clean['ovoko_supply_connector_enabled'] = !empty($settings['ovoko_supply_connector_enabled']);
        $clean['ovoko_supply_connector_base_url'] = esc_url_raw((string) ($settings['ovoko_supply_connector_base_url'] ?? 'https://supply-connector.ms.ovoko.com'));
        $newToken = sanitize_text_field((string) ($settings['ovoko_supply_connector_token'] ?? ''));
        if ($newToken !== '') {
            $clean['ovoko_supply_connector_token'] = $newToken;
        }
        $newApiKey = sanitize_text_field((string) ($settings['ovoko_supply_connector_api_key'] ?? ''));
        if ($newApiKey !== '') {
            $clean['ovoko_supply_connector_api_key'] = $newApiKey;
        }
        $clean['ovoko_integration_id'] = sanitize_text_field((string) ($settings['ovoko_integration_id'] ?? ''));
        $clean['ovoko_sync_enabled'] = !empty($settings['ovoko_sync_enabled']);
        $clean['ovoko_sync_dry_run'] = !empty($settings['ovoko_sync_dry_run']);
        $mode = sanitize_key((string) ($settings['ovoko_sync_mode'] ?? 'disabled'));
        $clean['ovoko_sync_mode'] = in_array($mode, ['disabled','preview_only','manual_single','batch_dry_run'], true) ? $mode : 'disabled';
        $clean['ovoko_sync_batch_limit'] = max(1, min(100, (int) ($settings['ovoko_sync_batch_limit'] ?? 10)));
        $clean['rrr_api_enabled'] = !empty($settings['rrr_api_enabled']);
        $clean['rrr_api_dry_run'] = !empty($settings['rrr_api_dry_run']);
        $clean['rrr_api_base_url'] = esc_url_raw((string) ($settings['rrr_api_base_url'] ?? 'https://api.rrr.lt'));
        $newRrrUsername = sanitize_text_field((string) ($settings['rrr_api_username'] ?? ''));
        if ($newRrrUsername !== '') {
            $clean['rrr_api_username'] = $newRrrUsername;
        }
        $newRrrPassword = sanitize_text_field((string) ($settings['rrr_api_password'] ?? ''));
        if ($newRrrPassword !== '') {
            $clean['rrr_api_password'] = $newRrrPassword;
        }
        $newRrrUserToken = sanitize_text_field((string) ($settings['rrr_api_user_token'] ?? ''));
        if ($newRrrUserToken !== '') {
            $clean['rrr_api_user_token'] = $newRrrUserToken;
        }
        $newOriginalImageToken = sanitize_text_field((string) ($settings['ovoko_original_image_bearer_token'] ?? ''));
        if ($newOriginalImageToken !== '') {
            $clean['ovoko_original_image_bearer_token'] = $newOriginalImageToken;
        }
        $clean['ovoko_exclude_gearbox_products'] = !isset($settings['ovoko_exclude_gearbox_products']) || !empty($settings['ovoko_exclude_gearbox_products']);
        update_option(self::OPTION_KEY, $clean, false);
    }

    public function register_routes(): void
    {
        register_rest_route('gpswiss-ovoko/v1', '/callback', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'handle_callback'],
        ]);
    }

    public function handle_callback(WP_REST_Request $request): WP_REST_Response
    {
        $settings = $this->get_settings();
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = [];
        }

        $context = $this->build_log_context($payload);
        $this->log('OVOKO_CALLBACK_RECEIVED', $context);
        $this->increment_counter('received');

        if (empty($settings['ovoko_callback_enabled'])) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Callback disabled'], 403);
        }

        $auth = $this->authorize_request($request, $settings, $context);
        if ($auth instanceof WP_Error) {
            return new WP_REST_Response(['ok' => false, 'message' => $auth->get_error_message()], (int) $auth->get_error_code());
        }

        if (!empty($context['event_id']) && $this->is_duplicate((string) $context['event_id'])) {
            $this->increment_counter('duplicate');
            $this->log('OVOKO_CALLBACK_DUPLICATE_SKIPPED', $context);
            return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
        }

        $this->mark_processed((string) ($context['event_id'] ?? ''));
        $result = $this->process_callback($payload, $settings);
        $this->append_recent_event($result['context']);

        return new WP_REST_Response($result['response'], $result['status']);
    }

    private function authorize_request(WP_REST_Request $request, array $settings, array $context): ?WP_Error
    {
        $name = (string) $settings['ovoko_callback_header_name'];
        $secret = (string) $settings['ovoko_callback_header_secret'];
        $provided = $request->get_header($name);

        if ($name === '' || $secret === '' || $provided === '') {
            $this->increment_counter('auth_failed');
            $this->log('OVOKO_CALLBACK_AUTH_FAILED', $context + ['header_name' => $name]);
            return new WP_Error('401', 'Missing callback authentication header.');
        }

        if (!hash_equals($secret, (string) $provided)) {
            $this->increment_counter('auth_failed');
            $this->log('OVOKO_CALLBACK_AUTH_FAILED', $context + ['header_name' => $name]);
            return new WP_Error('403', 'Invalid callback authentication header.');
        }

        return null;
    }

    private function process_callback(array $payload, array $settings, array $options = []): array
    {
        $context = $this->build_log_context($payload);
        $dryRun = !empty($settings['ovoko_callback_dry_run']);
        $forcedLocalDryRun = !empty($options['forced_local_dry_run']);
        if ($forcedLocalDryRun) {
            $dryRun = true;
            $context['forced_local_dry_run'] = true;
            $context['dry_run_reason'] = 'Local callback test enforces dry-run';
        }
        $context['dry_run'] = $dryRun;

        if (($context['event_type'] ?? '') !== 'part.status.changed') {
            $context['action_that_would_be_taken'] = 'unsupported_event';
            $this->log('OVOKO_CALLBACK_UNSUPPORTED_EVENT', $context + ['reason' => 'Unsupported event_type']);
            $this->increment_counter('unsupported');
            return ['status' => 200, 'response' => ['ok' => true, 'ignored' => true, 'message' => 'Unsupported event_type'], 'context' => $context];
        }

        $context['product'] = $this->find_product_by_part_id((string) ($context['part_id'] ?? ''));
        if (empty($context['product'])) {
            $this->log('OVOKO_CALLBACK_PRODUCT_NOT_FOUND', $context);
        }

        $context = $this->enrich_product_context($context);
        $this->log('OVOKO_CALLBACK_PART_STATUS_CHANGED', $context);

        $action = 'no_action';
        if (($context['status'] ?? '') === 'sold' && !empty($context['product_id'])) {
            $action = 'set_stock_zero_and_outofstock';
        }
        $context['action_that_would_be_taken'] = $action;

        if ($action !== 'no_action' && $dryRun) {
            $this->log('OVOKO_CALLBACK_DRY_RUN', $context);
            $this->increment_counter('dry_run');
            return ['status' => 200, 'response' => ['ok' => true, 'dry_run' => true, 'action' => $action, 'forced_local_dry_run' => $forcedLocalDryRun], 'context' => $context];
        }

        if ($action === 'no_action') {
            return ['status' => 200, 'response' => ['ok' => true, 'dry_run' => $dryRun, 'action' => $action], 'context' => $context];
        }

        if ($dryRun) {
            $this->log('OVOKO_CALLBACK_DRY_RUN', $context);
            $this->increment_counter('dry_run');
            return ['status' => 200, 'response' => ['ok' => true, 'dry_run' => true, 'action' => $action, 'forced_local_dry_run' => $forcedLocalDryRun], 'context' => $context];
        }

        $this->log('OVOKO_CALLBACK_APPLIED', $context);
        $this->increment_counter('applied');
        return ['status' => 200, 'response' => ['ok' => true, 'dry_run' => $dryRun, 'action' => $action], 'context' => $context];
    }

    private function build_log_context(array $payload): array
    {
        return [
            'event_id' => sanitize_text_field((string) ($payload['event_id'] ?? '')),
            'event_type' => sanitize_text_field((string) ($payload['event_type'] ?? '')),
            'timestamp' => sanitize_text_field((string) ($payload['timestamp'] ?? '')),
            'part_id' => sanitize_text_field((string) ($payload['event_data']['part_id'] ?? '')),
            'status' => sanitize_text_field((string) ($payload['event_data']['status'] ?? '')),
        ];
    }

    private function find_product_by_part_id(string $partId): array
    {
        if ($partId === '' || !post_type_exists('product')) {
            return [];
        }

        foreach (self::PART_ID_META_KEYS as $metaKey) {
            $ids = get_posts([
                'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1,
                'fields' => 'ids', 'meta_key' => $metaKey, 'meta_value' => $partId,
            ]);
            if (!empty($ids[0])) {
                return ['product_id' => (int) $ids[0], 'matched_meta_key' => $metaKey];
            }
        }

        return [];
    }

    private function enrich_product_context(array $context): array
    {
        $productId = (int) ($context['product']['product_id'] ?? 0);
        $context['product_id'] = $productId ?: null;
        $context['matched_meta_key'] = $context['product']['matched_meta_key'] ?? null;
        $context['sku'] = $productId ? (string) get_post_meta($productId, '_sku', true) : null;
        $context['current_stock'] = $productId ? get_post_meta($productId, '_stock', true) : null;
        unset($context['product']);
        return $context;
    }

    private function log(string $code, array $context): void
    {
        error_log('[GPSWISS_OVOKO] ' . $code . ' ' . wp_json_encode($context));
    }

    public function log_event(string $code, array $context = []): void
    {
        $this->log($code, $context);
    }

    private function increment_counter(string $key): void
    {
        $counters = wp_parse_args(get_option(self::COUNTERS_OPTION_KEY, []), [
            'received' => 0, 'auth_failed' => 0, 'duplicate' => 0, 'dry_run' => 0, 'applied' => 0, 'unsupported' => 0, 'failed' => 0,
        ]);
        $counters[$key] = (int) ($counters[$key] ?? 0) + 1;
        update_option(self::COUNTERS_OPTION_KEY, $counters, false);
    }

    private function is_duplicate(string $eventId): bool
    {
        return $eventId !== '' && in_array($eventId, (array) get_option(self::DEDUP_OPTION_KEY, []), true);
    }

    private function mark_processed(string $eventId): void
    {
        if ($eventId === '') return;
        $items = (array) get_option(self::DEDUP_OPTION_KEY, []);
        $items[] = $eventId;
        $items = array_values(array_unique(array_slice($items, -500)));
        update_option(self::DEDUP_OPTION_KEY, $items, false);
    }

    private function append_recent_event(array $context): void
    {
        $events = (array) get_option(self::EVENTS_OPTION_KEY, []);
        $events[] = $context;
        $events = array_slice($events, -20);
        update_option(self::EVENTS_OPTION_KEY, $events, false);
    }

    public function get_dashboard_data(): array
    {
        $settings = $this->get_settings();
        $storeUrl = home_url('/');
        $wooActive = class_exists('WooCommerce');

        $metaKeyCounts = $this->count_products_for_mapping_meta_keys();
        $withPartId = (int) array_sum($metaKeyCounts);
        $allProducts = (int) wp_count_posts('product')->publish + (int) wp_count_posts('product')->draft;
        $lastLookupFoundProduct = $this->did_last_lookup_find_product();

        $connectorClient = new OvokoSupplyConnectorClient($settings);
        $syncService = new OvokoProductSyncService();
        $connectorCheck = (array) get_transient('gpswiss_ovoko_last_supply_connector_check');
        $rrrCheck = (array) get_transient('gpswiss_ovoko_last_rrr_api_check');
        $fixturePart = NormalizedOvokoPart::from_array($syncService->developer_sample_fixture());
        $fixtureWithHash = NormalizedOvokoPart::from_array($fixturePart->to_array() + ['payload_hash' => $syncService->calculate_payload_hash($fixturePart)]);
        $excludeEnabled = !empty($settings['ovoko_exclude_gearbox_products']);
        $cachedExclusionCount = $this->get_cached_gearbox_exclusion_count();

        return [
            'callback_url' => rest_url('gpswiss-ovoko/v1/callback'),
            'settings' => $settings,
            'counters' => wp_parse_args(get_option(self::COUNTERS_OPTION_KEY, []), [
                'received' => 0, 'auth_failed' => 0, 'duplicate' => 0, 'dry_run' => 0, 'applied' => 0, 'unsupported' => 0, 'failed' => 0,
            ]),
            'recent_events' => array_reverse((array) get_option(self::EVENTS_OPTION_KEY, [])),
            'woo_active' => $wooActive,
            'woo_rest_reachable' => null,
            'store_url' => $storeUrl,
            'store_https' => str_starts_with($storeUrl, 'https://'),
            'rest_permalink_ready' => str_contains((string) get_option('permalink_structure', ''), '/'),
            'required_meta_fields' => self::OVOKO_META_FIELDS,
            'with_ovoko_part_id' => $withPartId,
            'without_ovoko_part_id' => max(0, $allProducts - $withPartId),
            'mapping_meta_keys' => self::PART_ID_META_KEYS,
            'mapping_meta_key_counts' => $metaKeyCounts,
            'last_lookup_found_product' => $lastLookupFoundProduct,
            'readiness_report' => $this->build_readiness_report($wooActive, $settings, $withPartId, $lastLookupFoundProduct),
            'supply_connector_check' => $connectorCheck,
            'supply_connector_resources' => $connectorClient->list_supported_resources_from_static_analysis(),
            'rrr_api_check' => $rrrCheck,
            'api_connection_test' => (array) get_transient('gpswiss_ovoko_last_api_connection_test'),
            'sync_preview_fixture' => $fixtureWithHash->to_array(),
            'sync_preview_meta_mapping' => $syncService->map_to_woo_meta_preview($fixtureWithHash),
            'sync_preview_match' => ['mode' => 'manual_only', 'note' => 'match preview moved to manual actions only to keep admin page lightweight'],
            'csv_mapping_status' => (array) ($settings['ovoko_csv_mapping_status'] ?? []),
            'category_rebuild_autorun_status' => $this->get_category_rebuild_autorun_status(),
            'category_rebuild_autorun_debug' => $this->get_category_rebuild_autorun_debug(),
            'excluded_from_ovoko_sync' => [
                'enabled' => $excludeEnabled,
                'detected_products_count' => (int) ($cachedExclusionCount['count'] ?? 0),
                'last_calculated_at' => (string) ($cachedExclusionCount['calculated_at'] ?? ''),
                'info' => 'gearbox exclusion count is manual/cached only to avoid heavy product meta scans during page load',
            ],
        ];
    }

    private function get_cached_gearbox_exclusion_count(): array
    {
        $value = get_option(self::GEARBOX_COUNT_OPTION_KEY, []);
        return [
            'count' => (int) ($value['count'] ?? 0),
            'calculated_at' => sanitize_text_field((string) ($value['calculated_at'] ?? '')),
        ];
    }

    private function count_products_for_mapping_meta_keys(): array
    {
        global $wpdb;
        $counts = array_fill_keys(self::PART_ID_META_KEYS, 0);
        if (!$wpdb) {
            return $counts;
        }

        $placeholders = implode(', ', array_fill(0, count(self::PART_ID_META_KEYS), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are prepared via $wpdb->prepare
        $sql = "SELECT pm.meta_key, COUNT(DISTINCT p.ID) AS cnt FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'product' AND pm.meta_key IN ($placeholders) AND pm.meta_value <> '' GROUP BY pm.meta_key";
        $rows = $wpdb->get_results($wpdb->prepare($sql, self::PART_ID_META_KEYS), ARRAY_A);

        foreach ((array) $rows as $row) {
            $key = (string) ($row['meta_key'] ?? '');
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $counts;
    }

    private function build_readiness_report(bool $wooActive, array $settings, int $withPartId, bool $lastLookupFoundProduct): array
    {
        return [
            'callback_receiver_ready' => !empty($settings['ovoko_callback_enabled']) && !empty($settings['ovoko_callback_header_secret']),
            'mapping_ready' => $withPartId > 0 || $lastLookupFoundProduct,
            'full_import_data_ready' => false,
            'ovoko_woocommerceintegration_needed' => true,
            'risks' => [
                'Potential duplicates between legacy Allegro-import products and future Ovoko-master products.',
                'Part ID collisions across old source fields before source normalization.',
            ],
            'source_tagging_proposal' => ['source=allegro_import', 'source=ovoko_master'],
        ];
    }

    private function did_last_lookup_find_product(): bool
    {
        $events = array_reverse((array) get_option(self::EVENTS_OPTION_KEY, []));
        foreach ($events as $event) {
            if (!isset($event['part_id']) || $event['part_id'] === '') {
                continue;
            }
            return !empty($event['product_id']);
        }
        return false;
    }

    public function check_supply_connector_configuration(): array
    {
        $client = new OvokoSupplyConnectorClient($this->get_settings());
        $result = $client->check_configuration();
        $result['checked_at'] = gmdate('c');
        set_transient('gpswiss_ovoko_last_supply_connector_check', $result, DAY_IN_SECONDS);
        return $result;
    }

    public function check_rrr_api_configuration(): array
    {
        $client = new RrrApiClient($this->get_settings());
        $result = $client->check_configuration();
        $result['checked_at'] = gmdate('c');
        set_transient('gpswiss_ovoko_last_rrr_api_check', $result, DAY_IN_SECONDS);
        return $result;
    }


    public function test_api_connection(): array
    {
        $client = new RrrApiClient($this->get_settings());
        $result = $client->diagnose_connection(['/get/part/10994', '/get/car/458']);
        $result['checked_at'] = gmdate('c');
        set_transient('gpswiss_ovoko_last_api_connection_test', $result, DAY_IN_SECONDS);
        return $result;
    }


    public function test_update_part_place_for_product_43302(): array
    {
        $productId = 43302;
        $partId = '10854';
        $place = 'HGF10854';
        $client = new RrrApiClient($this->get_settings());
        $result = $client->test_update_part_place_once($partId, $place);

        $maskedParams = [
            'username' => '[REDACTED]',
            'password' => '[REDACTED]',
            'user_token' => '[REDACTED]',
            'part_id' => $partId,
            'place' => $place,
        ];

        $apiMessage = (string) ($result['message'] ?? '');
        $statusCode = (string) ($result['status_code'] ?? '');

        return [
            'ok' => $statusCode === 'R200',
            'action_name' => 'Test Ovoko place update',
            'scope' => 'single_request_only',
            'test_product_id' => $productId,
            'request_endpoint' => (string) ($result['endpoint'] ?? ''),
            'request_params' => $maskedParams,
            'http_status' => $result['http_status'] ?? null,
            'raw_response_body' => (string) ($result['raw_response_body'] ?? ''),
            'parsed_json' => $result['parsed_json'] ?? null,
            'status_code' => $statusCode,
            'api_message_or_error' => $apiMessage,
            'success_by_json_status_code' => $statusCode === 'R200',
            'checked_at' => gmdate('c'),
            'safety' => [
                'single_request_sent' => true,
                'no_batch' => true,
                'no_product_iteration' => true,
                'no_woo_product_write' => true,
                'no_price_change' => true,
                'no_stock_change' => true,
                'no_images_change' => true,
                'no_gallery_change' => true,
                'no_listing_image_change' => true,
                'no_title_change' => true,
                'no_description_change' => true,
                'no_publication_status_change' => true,
                'no_allegro_changes' => true,
                'no_ebay_changes' => true,
                'no_cron_changes' => true,
                'no_other_batch_changes' => true,
            ],
        ];
    }

    public function run_gearbox_exclusion_count(): array
    {
        try {
            $count = (new OvokoProductSyncService())->count_excluded_gearbox_products_sql_fast();
            $payload = ['count' => $count, 'calculated_at' => gmdate('c')];
            update_option(self::GEARBOX_COUNT_OPTION_KEY, $payload, false);
            return ['ok' => true, 'mode' => 'manual_sql_count', 'count' => $count, 'calculated_at' => $payload['calculated_at'], 'no_woo_writes' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'mode' => 'manual_sql_count', 'message' => $e->getMessage(), 'no_woo_writes' => true];
        }
    }

    public function preview_rrr_parts_sample(int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(50, $limit));
        $page = max(1, $page);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_parts_sample($limit, $page);
        $records = (array) ($result['records'] ?? []);
        $normalized = [];
        $statusDistribution = [];
        foreach ($records as $record) {
            $status = sanitize_text_field((string) ($record['status'] ?? ''));
            if (!isset($statusDistribution[$status])) {
                $statusDistribution[$status] = 0;
            }
            $statusDistribution[$status]++;

            $previewRecord = [
                'part_id' => sanitize_text_field((string) ($record['id'] ?? '')),
                'title' => sanitize_text_field((string) ($record['name'] ?? '')),
                'status' => $status,
                'updated_at' => sanitize_text_field((string) ($record['updated_at'] ?? '')),
                'external_id' => isset($record['external_id']) ? sanitize_text_field((string) $record['external_id']) : null,
                'raw_payload_summary' => [
                    'keys' => ['id', 'external_id', 'name', 'status', 'updated_at'],
                    'source' => '/v2/get/parts',
                    'full_payload_omitted' => true,
                ],
            ];
            $normalized[] = $previewRecord;
        }

        return [
            'ok' => !empty($result['ok']),
            'mode' => 'preview_only',
            'notice' => 'Preview only — no Woo products were created or updated.',
            'request' => [
                'method' => 'POST',
                'path' => '/v2/get/parts?limit=' . $limit . '&page=' . $page,
                'form_data_fields' => ['username', 'password', 'user_token'],
            ],
            'status_code' => $result['status_code'] ?? '',
            'msg' => $result['msg'] ?? '',
            'pagination' => (array) ($result['pagination'] ?? []),
            'records_count' => count($normalized),
            'status_distribution' => $statusDistribution,
            'records' => $normalized,
            'no_write_to_woo' => true,
            'diagnostic_note' => 'API total_count may include inactive/sold/archived parts until status semantics are confirmed by Ovoko/RRR.',
            'checked_at' => gmdate('c'),
        ];
    }



    public function preview_rrr_single_part(int $partId): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $payload = (array) ($result['payload'] ?? []);
        $record = $client->extract_single_part_record($payload);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $imagePlanPreview = (new OvokoImageImportPlan())->preview_image_import_plan($normalized, $partId, $this->get_cached_authenticated_original_urls($partId));
        $imageUrlFields = $this->scan_image_url_fields($payload);
        $imageFieldDiagnostics = $this->build_image_field_diagnostics($payload);
        $salesChannelsDiagnostic = $client->build_sales_channels_diagnostic($payload);
        $syncService = new OvokoProductSyncService();
        $match = $syncService->preview_match_rrr_record([
            'part_id' => (string) ($normalized['part_id'] ?? ''),
            'external_id' => (string) ($normalized['external_id'] ?? ''),
        ]);
        $imagePlan = (new OvokoImageImportPlan())->preview_image_import_plan($normalized, $partId, $this->get_cached_authenticated_original_urls($partId));
        $imagePolicy = (array) ($imagePlan['image_source_policy'] ?? []);
        $imageCleanSourceFound = !empty($imagePolicy['clean_source_found']);
        $imageWarning = $imageCleanSourceFound ? '' : 'Only public Ovoko watermarked image URLs found.';

        return [
            'ok' => !empty($result['ok']),
            'mode' => 'preview_only',
            'request' => [
                'method' => 'POST',
                'path' => '/get/part/' . $partId,
                'content_type' => 'application/x-www-form-urlencoded',
                'form_data_fields' => ['username', 'password', 'user_token'],
            ],
            'status_code' => (string) ($result['status_code'] ?? ''),
            'msg' => (string) ($result['msg'] ?? ''),
            'part_id' => $partId,
            'response_top_level_keys' => array_values(array_map('strval', array_keys($payload))),
            'single_part_summary' => $normalized,
            'image_url_fields_found' => $imageUrlFields,
            'image_field_diagnostics' => $imageFieldDiagnostics,
            'woo_meta_mapping_preview' => $this->build_rrr_single_part_woo_meta_preview($normalized),
            'same_vehicle_grouping_preview' => $this->build_same_vehicle_grouping_preview($normalized),
            'woo_match_preview' => $match,
            'preview_image_import_plan' => $imagePlanPreview,
            'raw_payload_summary' => [
                'top_level_keys' => array_values(array_map('strval', array_keys($payload))),
                'record_keys' => array_values(array_map('strval', array_keys((array) $record))),
                'channel_related_keys_found' => (array) ($normalized['price_diagnostic_channel_keys'] ?? []),
                'allegro_price_available' => !empty($normalized['allegro_price_available']),
                'allegro_price_location' => (string) ($normalized['allegro_price_location'] ?? 'not_found_in_get_part_payload'),
                'full_payload_omitted' => true,
            ],
            'sales_channels_diagnostic' => $salesChannelsDiagnostic,
            'no_write_to_woo' => true,
            'readme_question' => implode(' ', array_filter([
                (($normalized['vehicle_data_status'] ?? '') === 'car_id_only')
                    ? 'Which endpoint returns full car details for car_id? Possibly /get/car/{id} or Cars v2 — confirm with RRR/Ovoko.'
                    : '',
                'Which endpoint or field returns channel-specific Allegro price for a part?',
                'Which endpoint returns sales channels / Allegro offer ID / Allegro channel price for a part?',
            ])),
            'checked_at' => gmdate('c'),
        ];
    }


    private function enrich_with_vehicle_data(array $normalized, RrrApiClient $client): array
    {
        $carId = (string) ($normalized['car_id'] ?? '');
        $vehicleDebug = [
            'vehicle_fetch_attempted' => $carId !== '',
            'vehicle_fetch_success' => false,
            'vehicle_endpoint_used' => '',
            'vehicle_raw_car_id' => $carId,
            'vehicle_data_status' => (string) ($normalized['vehicle_data_status'] ?? ''),
            'vehicle_dictionary_resolution_status' => (string) ($normalized['vehicle_dictionary_resolution_status'] ?? ''),
            'vehicle_dictionary_resolution_source' => (string) ($normalized['vehicle_dictionary_resolution_source'] ?? ''),
        ];

        if ($carId !== '') {
            $vehiclePreview = $client->preview_fetch_car_by_id($carId);
            if (!empty($vehiclePreview['ok'])) {
                $normalized = array_merge($normalized, (array) ($vehiclePreview['normalized'] ?? []));
                $vehicleDebug['vehicle_fetch_success'] = true;
                $vehicleDebug['vehicle_endpoint_used'] = (string) ($vehiclePreview['endpoint_used'] ?? '');
            }
            $vehicleDebug['vehicle_data_status'] = (string) ($normalized['vehicle_data_status'] ?? $vehicleDebug['vehicle_data_status']);
            $vehicleDebug['vehicle_dictionary_resolution_status'] = (string) ($normalized['vehicle_dictionary_resolution_status'] ?? $vehicleDebug['vehicle_dictionary_resolution_status']);
            $vehicleDebug['vehicle_dictionary_resolution_source'] = (string) ($normalized['vehicle_dictionary_resolution_source'] ?? $vehicleDebug['vehicle_dictionary_resolution_source']);
        }

        return ['normalized' => $normalized, 'vehicle_debug' => $vehicleDebug];
    }

    public function preview_woo_product_create_from_rrr_part(int $partId): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $payload = (array) ($result['payload'] ?? []);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $vehicleEnriched = $this->enrich_with_vehicle_data($normalized, $client);
        $normalized = (array) ($vehicleEnriched['normalized'] ?? $normalized);
        $vehicleDebug = (array) ($vehicleEnriched['vehicle_debug'] ?? []);
        $syncService = new OvokoProductSyncService();
        $match = $syncService->preview_match_rrr_record([
            'part_id' => (string) ($normalized['part_id'] ?? ''),
            'external_id' => (string) ($normalized['external_id'] ?? ''),
        ]);
        $imagePlanBuilder = new OvokoImageImportPlan();
        $imagePlanPreview = $imagePlanBuilder->preview_image_import_plan($normalized, $partId, $this->get_cached_authenticated_original_urls($partId));
        $imageUrlFields = $this->scan_image_url_fields($payload);

        $hasExisting = !empty($match['matched_product_id']);
        $wouldAction = $hasExisting ? 'would_update_existing_product' : 'would_create_new_product';
        $priceWriteReady = (($normalized['price_source'] ?? '') === 'internal_notes_plain_price') && empty($normalized['price_review_required']);
        $createBlocked = !$priceWriteReady;
        $blockedReason = $createBlocked ? 'missing_valid_woo_price' : '';
        $excludedFromSync = !empty($match['excluded_from_ovoko_sync']);
        if ($excludedFromSync) {
            $createBlocked = true;
            $blockedReason = 'gearbox_standalone_catalog';
        }

        $statusRaw = (string) ($normalized['status'] ?? '');
        $stockStatusPreview = in_array($statusRaw, ['0', 'active', 'available'], true) ? 'instock' : 'outofstock';
        $notes = (string) ($normalized['notes'] ?? '');
        $description = trim($notes . "\n\n" . 'Używana część samochodowa. Przed zakupem potwierdź kompatybilność po numerze OE/MPN.');
        $titlePreview = $this->build_woo_product_title_from_rrr_part($normalized);

        return [
            'ok' => !empty($result['ok']),
            'mode' => 'preview_only',
            'action_name' => 'Preview Woo product create from RRR part',
            'notice' => 'Preview only — no Woo product was created or updated.',
            'request' => ['method' => 'POST', 'path' => '/get/part/' . $partId, 'form_data_fields' => ['username', 'password', 'user_token']],
            'status_code' => (string) ($result['status_code'] ?? ''),
            'msg' => (string) ($result['msg'] ?? ''),
            'part_id' => $partId,
            'single_part_summary' => $normalized,
            'woo_match_preview' => $match,
            'would_action' => $wouldAction,
            'create_blocked' => $createBlocked,
            'reason' => $blockedReason,
            'excluded_from_ovoko_sync' => $excludedFromSync,
            'post_draft_preview' => [
                'post_title' => (string) ($titlePreview['proposed_title'] ?? ''),
                'post_status' => 'draft',
                'regular_price' => $priceWriteReady ? (string) ($normalized['woo_target_price'] ?? '') : null,
                'currency' => 'PLN',
                'stock_status_preview' => $stockStatusPreview,
                'description' => $description,
                'short_description' => $notes,
                'sku_candidate' => 'GPSW-OVK-' . (string) $partId,
                'category_preview' => (string) ($normalized['category_title_path'] ?? ''),
                'images_preview' => [
                    'images_count' => count((array) ($normalized['part_photo_gallery'] ?? [])),
                    'urls' => (array) ($normalized['part_photo_gallery'] ?? []),
                    'download_to_media_library' => false,
                ],
                'source_url' => (string) (($normalized['show_url'] ?? '') ?: ($normalized['shop_url'] ?? '')),
                'manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                'mpn_oe_candidate' => (string) ($normalized['manufacturer_code'] ?? ''),
                'car_id' => (string) ($normalized['car_id'] ?? ''),
                'same_vehicle_grouping' => $this->build_same_vehicle_grouping_preview($normalized),
            ],
            'preview_image_import_plan' => $imagePlanPreview,
            'image_url_fields_found' => $imageUrlFields,
            'title_builder_preview' => $titlePreview,
            'woo_meta_preview' => [
                '_ovoko_part_id' => (string) ($normalized['part_id'] ?? ''),
                '_ovoko_car_id' => (string) ($normalized['car_id'] ?? ''),
                '_ovoko_status' => (string) ($normalized['status'] ?? ''),
                '_ovoko_updated_at' => (string) ($normalized['updated_at'] ?? ''),
                '_ovoko_category' => (string) ($normalized['category_title_path'] ?? ''),
                '_ovoko_category_id' => (string) ($normalized['category_id'] ?? ''),
                '_ovoko_source_url' => (string) (($normalized['show_url'] ?? '') ?: ($normalized['shop_url'] ?? '')),
                '_ovoko_images' => (array) ($normalized['part_photo_gallery'] ?? []),
                '_ovoko_price' => (string) ($normalized['price'] ?? ''),
                '_ovoko_original_price' => (string) ($normalized['original_price'] ?? ''),
                '_ovoko_internal_notes_price_source' => (string) ($normalized['price_source'] ?? ''),
                '_ovoko_woo_target_price' => $priceWriteReady ? (string) ($normalized['woo_target_price'] ?? '') : null,
                '_ovoko_woo_target_currency' => $priceWriteReady ? 'PLN' : null,
                '_ovoko_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_mpn' => (string) ($normalized['manufacturer_code'] ?? ''),
                'mpn' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_gpswiss_part_number' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_part_number' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_ovoko_title_source' => (string) ($titlePreview['_ovoko_title_source'] ?? ''),
                '_ovoko_title_review_required' => (string) ($titlePreview['_ovoko_title_review_required'] ?? ''),
                '_ovoko_title_missing_vehicle_fields' => (string) ($titlePreview['_ovoko_title_missing_vehicle_fields'] ?? ''),
                '_ovoko_title_generated_from' => (string) ($titlePreview['_ovoko_title_generated_from'] ?? ''),
                '_ovoko_quality' => (string) ($normalized['quality'] ?? ''),
                '_ovoko_position' => (string) ($normalized['position'] ?? ''),
                'source' => 'ovoko_master',
                'product_attributes_preview' => $this->build_ovoko_technical_attributes_from_normalized($normalized),
            ],
            'create_flow_debug' => [
                'vehicle_fetch_attempted' => (bool) ($vehicleDebug['vehicle_fetch_attempted'] ?? false),
                'vehicle_fetch_success' => (bool) ($vehicleDebug['vehicle_fetch_success'] ?? false),
                'vehicle_endpoint_used' => (string) ($vehicleDebug['vehicle_endpoint_used'] ?? ''),
                'vehicle_raw_car_id' => (string) ($vehicleDebug['vehicle_raw_car_id'] ?? ''),
                'vehicle_data_status' => (string) ($vehicleDebug['vehicle_data_status'] ?? ''),
                'vehicle_dictionary_resolution_status' => (string) ($vehicleDebug['vehicle_dictionary_resolution_status'] ?? ''),
                'vehicle_dictionary_resolution_source' => (string) ($vehicleDebug['vehicle_dictionary_resolution_source'] ?? ''),
                'vehicle_make' => (string) ($normalized['vehicle_make'] ?? ''),
                'vehicle_make_short' => (string) ($normalized['vehicle_make_short'] ?? ''),
                'vehicle_model' => (string) ($normalized['vehicle_model'] ?? ''),
                'vehicle_generation' => (string) ($normalized['vehicle_generation'] ?? ''),
                'vehicle_engine_marketing' => (string) ($normalized['vehicle_engine_marketing'] ?? ''),
                'title_builder_input_keys' => array_values(array_map('strval', array_keys($normalized))),
                'title_builder_vehicle_prefix' => (string) ($titlePreview['vehicle_title_prefix'] ?? ''),
                'title_builder_vehicle_model' => (string) ($titlePreview['vehicle_model'] ?? ''),
                'title_builder_vehicle_generation' => (string) ($titlePreview['vehicle_generation'] ?? ''),
                'title_builder_generation_contains_model' => !empty($titlePreview['generation_contains_model']),
                'title_builder_vehicle_prefix_before_dedupe' => (string) ($titlePreview['vehicle_prefix_before_dedupe'] ?? ''),
                'title_builder_vehicle_prefix_after_dedupe' => (string) ($titlePreview['vehicle_prefix_after_dedupe'] ?? ''),
                'title_builder_notes' => (string) ($normalized['notes'] ?? ''),
                'title_builder_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                'title_builder_output' => (string) ($titlePreview['proposed_title'] ?? ''),
                'title_source' => (string) ($titlePreview['_ovoko_title_source'] ?? ''),
                'title_review_required' => (string) ($titlePreview['_ovoko_title_review_required'] ?? 'yes') === 'yes',
            ],
            'price_safety' => [
                'price_source' => (string) ($normalized['price_source'] ?? ''),
                'price_review_required' => !empty($normalized['price_review_required']),
                'write_ready' => $priceWriteReady,
                'ovoko_price_informational' => ['price' => $normalized['price'] ?? null, 'original_price' => $normalized['original_price'] ?? null],
            ],
            'no_write_to_woo' => true,
            'checked_at' => gmdate('c'),
        ];
    }


    public function create_woo_draft_product_from_rrr_part(int $partId): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $payload = (array) ($result['payload'] ?? []);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $vehicleEnriched = $this->enrich_with_vehicle_data($normalized, $client);
        $normalized = (array) ($vehicleEnriched['normalized'] ?? $normalized);
        $vehicleDebug = (array) ($vehicleEnriched['vehicle_debug'] ?? []);
        $syncService = new OvokoProductSyncService();
        $match = $syncService->preview_match_rrr_record([
            'part_id' => (string) ($normalized['part_id'] ?? ''),
            'external_id' => (string) ($normalized['external_id'] ?? ''),
        ]);

        $existingProductId = (int) ($match['matched_product_id'] ?? 0);
        if ($existingProductId > 0) {
            return [
                'ok' => false,
                'action_name' => 'Create Woo draft product from RRR part',
                'existing_product_found' => true,
                'part_id' => $partId,
                'created' => false,
                'existing_product_id' => $existingProductId,
                'edit_link' => get_edit_post_link($existingProductId, 'raw'),
                'no_ebay_publish' => true,
                'no_allegro_publish' => true,
                'no_batch' => true,
            ];
        }

        $validations = $this->build_rrr_manual_create_validations($result, $normalized, $match);
        $blocking = array_filter($validations, static fn($v) => empty($v['passed']) && !empty($v['blocking']));
        if (!empty($blocking)) {
            return [
                'ok' => false,
                'action_name' => 'Create Woo draft product from RRR part',
                'part_id' => $partId,
                'created' => false,
                'existing_product_found' => false,
                'create_blocked' => true,
                'blocking_validations' => array_values($blocking),
                'validations' => $validations,
                'no_ebay_publish' => true,
                'no_allegro_publish' => true,
                'no_batch' => true,
            ];
        }

        $titlePreview = $this->build_woo_product_title_from_rrr_part($normalized);
        $productId = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'draft',
            'post_title' => (string) ($titlePreview['proposed_title'] ?? ''),
            'post_content' => (string) ($normalized['notes'] ?? ''),
            'post_excerpt' => (string) ($normalized['notes'] ?? ''),
        ], true);
        if (is_wp_error($productId)) {
            return ['ok' => false, 'action_name' => 'Create Woo draft product from RRR part', 'created' => false, 'error' => $productId->get_error_message()];
        }
        $productId = (int) $productId;

        update_post_meta($productId, '_sku', 'GPSW-OVK-' . $partId);
        update_post_meta($productId, '_regular_price', (string) ($normalized['woo_target_price'] ?? ''));
        update_post_meta($productId, '_price', (string) ($normalized['woo_target_price'] ?? ''));
        if (defined('WC_VERSION')) {
            update_post_meta($productId, '_product_version', WC_VERSION);
        }

        $meta = [
            '_ovoko_part_id' => (string) ($normalized['part_id'] ?? ''),
            '_ovoko_car_id' => (string) ($normalized['car_id'] ?? ''),
            '_ovoko_status' => (string) ($normalized['status'] ?? ''),
            '_ovoko_updated_at' => (string) ($normalized['updated_at'] ?? ''),
            '_ovoko_category' => (string) ($normalized['category_title_path'] ?? ''),
            '_ovoko_category_id' => (string) ($normalized['category_id'] ?? ''),
            '_ovoko_source_url' => (string) (($normalized['show_url'] ?? '') ?: ($normalized['shop_url'] ?? '')),
            '_ovoko_images' => (array) ($normalized['part_photo_gallery'] ?? []),
            '_ovoko_price' => (string) ($normalized['price'] ?? ''),
            '_ovoko_original_price' => (string) ($normalized['original_price'] ?? ''),
            '_ovoko_internal_notes_price_source' => (string) ($normalized['price_source'] ?? ''),
            '_ovoko_woo_target_price' => (string) ($normalized['woo_target_price'] ?? ''),
            '_ovoko_woo_target_currency' => 'PLN',
            '_ovoko_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
            '_mpn' => (string) ($normalized['manufacturer_code'] ?? ''),
            'mpn' => (string) ($normalized['manufacturer_code'] ?? ''),
            '_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
            '_gpswiss_part_number' => (string) ($normalized['manufacturer_code'] ?? ''),
            '_part_number' => (string) ($normalized['manufacturer_code'] ?? ''),
            '_ovoko_title_source' => (string) ($titlePreview['_ovoko_title_source'] ?? ''),
            '_ovoko_title_review_required' => (string) ($titlePreview['_ovoko_title_review_required'] ?? ''),
            '_ovoko_title_missing_vehicle_fields' => (string) ($titlePreview['_ovoko_title_missing_vehicle_fields'] ?? ''),
            '_ovoko_title_generated_from' => (string) ($titlePreview['_ovoko_title_generated_from'] ?? ''),
            '_ovoko_quality' => (string) ($normalized['quality'] ?? ''),
            '_ovoko_position' => (string) ($normalized['position'] ?? ''),
            '_ovoko_vehicle_make' => (string) ($normalized['vehicle_make'] ?? ''),
            '_ovoko_vehicle_make_short' => (string) ($normalized['vehicle_make_short'] ?? ''),
            '_ovoko_vehicle_model' => (string) ($normalized['vehicle_model'] ?? ''),
            '_ovoko_vehicle_generation' => (string) ($normalized['vehicle_generation'] ?? ''),
            '_ovoko_vehicle_engine_marketing' => (string) ($normalized['vehicle_engine_marketing'] ?? ''),
            '_ovoko_vehicle_year' => (string) ($normalized['vehicle_year'] ?? ''),
            '_ovoko_vehicle_period' => (string) ($normalized['vehicle_period'] ?? ''),
            '_ovoko_vehicle_fuel' => (string) ($normalized['vehicle_fuel'] ?? ''),
            '_ovoko_vehicle_engine_capacity_cc' => (string) ($normalized['vehicle_engine_capacity_cc'] ?? ''),
            '_ovoko_vehicle_engine_capacity_l' => (string) ($normalized['vehicle_engine_capacity_l'] ?? ''),
            '_ovoko_vehicle_engine_power_kw' => (string) ($normalized['vehicle_engine_power_kw'] ?? ''),
            '_ovoko_engine_code' => (string) ($normalized['vehicle_engine_code'] ?? ''),
            '_ovoko_gearbox_type' => (string) ($normalized['vehicle_gearbox_type'] ?? ''),
            '_ovoko_vehicle_body_type' => (string) ($normalized['vehicle_body_type'] ?? ''),
            '_ovoko_vehicle_drive_wheels' => (string) ($normalized['vehicle_drive_wheels'] ?? ''),
            '_ovoko_vehicle_steering_position' => (string) ($normalized['vehicle_steering_position'] ?? ''),
            '_ovoko_vehicle_color' => (string) ($normalized['vehicle_color'] ?? ''),
            '_ovoko_vehicle_color_code' => (string) ($normalized['vehicle_color_code'] ?? ''),
            '_ovoko_vehicle_dictionary_resolution_status' => (string) ($normalized['vehicle_dictionary_resolution_status'] ?? ''),
            '_ovoko_vehicle_dictionary_resolution_source' => (string) ($normalized['vehicle_dictionary_resolution_source'] ?? ''),
            'source' => 'ovoko_master',
        ];
        foreach ($meta as $k=>$v) update_post_meta($productId,$k,$v);
        $this->upsert_custom_product_attributes($productId, $this->build_ovoko_technical_attributes_from_normalized($normalized));
        wp_set_object_terms($productId, 'simple', 'product_type');
        $imageImportResult = $this->import_ovoko_images_for_product($productId, $normalized, $partId);
        $listingImageResult = $this->generate_listing_image_for_ovoko_product($productId);

        return [
            'ok' => true,
            'action_name' => 'Create Woo draft product from RRR part',
            'created' => true,
            'created_product_id' => $productId,
            'edit_link' => get_edit_post_link($productId, 'raw'),
            'status' => 'draft',
            'sku' => 'GPSW-OVK-' . $partId,
            'price' => (string) ($normalized['woo_target_price'] ?? ''),
            'images_import_attempted' => true,
            'images_count' => (int) $imageImportResult['images_count'],
            'images_import_failed' => (bool) $imageImportResult['images_import_failed'],
            'imported_attachment_ids' => (array) $imageImportResult['imported_attachment_ids'],
            'reused_attachment_ids' => (array) $imageImportResult['reused_attachment_ids'],
            'failed_urls' => (array) $imageImportResult['failed_urls'],
            'featured_attachment_id' => (int) $imageImportResult['featured_attachment_id'],
            'gallery_attachment_ids' => (array) $imageImportResult['gallery_attachment_ids'],
            'image_model' => 'allegro_compatible',
            'listing_image_attempted' => (bool) ($listingImageResult['listing_image_attempted'] ?? false),
            'listing_image_generated' => (bool) ($listingImageResult['listing_image_generated'] ?? false),
            'listing_image_id' => (int) ($listingImageResult['listing_image_id'] ?? 0),
            'listing_image_source_id' => (int) ($listingImageResult['listing_image_source_id'] ?? 0),
            'listing_image_strategy' => (string) ($listingImageResult['listing_image_strategy'] ?? 'thumbnail_fallback'),
            'listing_image_meta_written' => (array) ($listingImageResult['listing_image_meta_written'] ?? []),
            'listing_image_errors' => (array) ($listingImageResult['errors'] ?? []),
            'create_flow_debug' => [
                'vehicle_fetch_attempted' => (bool) ($vehicleDebug['vehicle_fetch_attempted'] ?? false),
                'vehicle_fetch_success' => (bool) ($vehicleDebug['vehicle_fetch_success'] ?? false),
                'vehicle_endpoint_used' => (string) ($vehicleDebug['vehicle_endpoint_used'] ?? ''),
                'vehicle_raw_car_id' => (string) ($vehicleDebug['vehicle_raw_car_id'] ?? ''),
                'vehicle_data_status' => (string) ($vehicleDebug['vehicle_data_status'] ?? ''),
                'vehicle_dictionary_resolution_status' => (string) ($vehicleDebug['vehicle_dictionary_resolution_status'] ?? ''),
                'vehicle_dictionary_resolution_source' => (string) ($vehicleDebug['vehicle_dictionary_resolution_source'] ?? ''),
                'vehicle_make' => (string) ($normalized['vehicle_make'] ?? ''),
                'vehicle_make_short' => (string) ($normalized['vehicle_make_short'] ?? ''),
                'vehicle_model' => (string) ($normalized['vehicle_model'] ?? ''),
                'vehicle_generation' => (string) ($normalized['vehicle_generation'] ?? ''),
                'vehicle_engine_marketing' => (string) ($normalized['vehicle_engine_marketing'] ?? ''),
                'title_builder_input_keys' => array_values(array_map('strval', array_keys($normalized))),
                'title_builder_vehicle_prefix' => (string) ($titlePreview['vehicle_title_prefix'] ?? ''),
                'title_builder_vehicle_model' => (string) ($titlePreview['vehicle_model'] ?? ''),
                'title_builder_vehicle_generation' => (string) ($titlePreview['vehicle_generation'] ?? ''),
                'title_builder_generation_contains_model' => !empty($titlePreview['generation_contains_model']),
                'title_builder_vehicle_prefix_before_dedupe' => (string) ($titlePreview['vehicle_prefix_before_dedupe'] ?? ''),
                'title_builder_vehicle_prefix_after_dedupe' => (string) ($titlePreview['vehicle_prefix_after_dedupe'] ?? ''),
                'title_builder_notes' => (string) ($normalized['notes'] ?? ''),
                'title_builder_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                'title_builder_output' => (string) ($titlePreview['proposed_title'] ?? ''),
                'title_source' => (string) ($titlePreview['_ovoko_title_source'] ?? ''),
                'title_review_required' => (string) ($titlePreview['_ovoko_title_review_required'] ?? 'yes') === 'yes',
            ],
            'validations' => $validations,
            'no_ebay_publish' => true,
            'no_allegro_publish' => true,
            'no_batch' => true,
            'image_watermark_policy_checked' => true,
            'image_clean_source_found' => $imageCleanSourceFound,
            'image_source_selected' => (string) ($imagePlan['selected_source'] ?? ''),
            'image_watermark_warning' => $imageWarning,
            'image_url_fields_found' => $this->scan_image_url_fields($payload),
        ];
    }

    public function apply_manual_live_date_from_part(array $record, array $payload, array $options): array
    {
        $client = new RrrApiClient($this->get_settings());
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $partId = trim((string) (($record['id'] ?? $record['part_id'] ?? '') ?: ($normalized['part_id'] ?? '')));
        if ($partId !== '') {
            $normalized['part_id'] = $partId;
        }
        $vehicleEnriched = $this->enrich_with_vehicle_data($normalized, $client);
        $normalized = (array) ($vehicleEnriched['normalized'] ?? $normalized);
        $productId = max(0, (int) ($options['product_id'] ?? 0));
        $updatedAt = trim((string) (($options['updated_at'] ?? '') ?: ($record['updated_at'] ?? $normalized['updated_at'] ?? '')));
        $syncHash = trim((string) ($options['sync_hash'] ?? ''));
        $price = (array) ($options['price'] ?? []);
        $priceValue = (string) ($price['price'] ?? '');
        $status = strtolower(trim((string) (($record['status'] ?? '') ?: ($normalized['status'] ?? ''))));
        $unavailable = in_array($status, ['sold', 'deleted', 'removed', 'inactive', 'unavailable', 'reserved'], true);
        $stockQty = $unavailable ? 0 : 1;
        $stockStatus = $unavailable ? 'outofstock' : 'instock';
        $description = trim((string) (($record['notes'] ?? '') ?: ($normalized['notes'] ?? '')));
        $attributes = $this->build_ovoko_technical_attributes_from_normalized($normalized);
        $categoryId = trim((string) (($record['category_id'] ?? '') ?: ($normalized['category_id'] ?? '')));
        $categoryResolution = ['ok' => false, 'category_tree_resolved_path' => '', 'category_tree_endpoint_used' => ''];
        if ($categoryId !== '') {
            $categoryResolution = $client->resolve_category_path_from_tree((int) $categoryId);
        }
        $categoryPath = trim((string) ($categoryResolution['category_tree_resolved_path'] ?? ''));

        if ($partId === '') {
            return ['ok' => false, 'reason' => 'missing_part_id', 'product_id' => $productId, 'created' => false, 'updated' => false];
        }

        if ($productId <= 0) {
            if (empty($price['ok']) || (float) $priceValue <= 0) {
                return ['ok' => false, 'reason' => 'new_product_missing_price_in_internal_notes', 'part_id' => $partId, 'created' => false, 'updated' => false, 'published' => false];
            }
            $titlePreview = $this->build_woo_product_title_from_rrr_part($normalized);
            $productId = wp_insert_post([
                'post_type' => 'product',
                'post_status' => 'publish',
                'post_title' => (string) (($titlePreview['proposed_title'] ?? '') ?: ($record['name'] ?? $normalized['title'] ?? ('Ovoko part ' . $partId))),
                'post_content' => $description,
                'post_excerpt' => $description,
            ], true);
            if (is_wp_error($productId)) {
                return ['ok' => false, 'reason' => 'product_create_failed', 'part_id' => $partId, 'error' => $productId->get_error_message(), 'created' => false, 'updated' => false];
            }
            $productId = (int) $productId;
            update_post_meta($productId, '_sku', 'GPSW-OVK-' . $partId);
            update_post_meta($productId, '_regular_price', $priceValue);
            update_post_meta($productId, '_price', $priceValue);
            if ($categoryPath !== '') {
                $termId = $this->ensure_manual_live_product_category_path($categoryPath, $categoryId);
                if ($termId > 0) {
                    wp_set_post_terms($productId, [$termId], 'product_cat', false);
                    $this->set_primary_product_category($productId, $termId);
                    update_post_meta($productId, '_gpswiss_ovoko_assigned_category_path', $categoryPath);
                    update_post_meta($productId, '_gpswiss_ovoko_assigned_category_id', $categoryId);
                }
            }
            $imageImportResult = $this->import_ovoko_images_for_product($productId, $normalized, (int) $partId);
            $created = true;
            $updated = false;
        } else {
            wp_update_post(['ID' => $productId, 'post_content' => $description, 'post_excerpt' => $description]);
            $imageImportResult = ['images_import_attempted' => false, 'images_count' => 0, 'imported_attachment_ids' => [], 'reused_attachment_ids' => [], 'failed_urls' => []];
            $created = false;
            $updated = true;
        }

        wp_set_object_terms($productId, 'simple', 'product_type');
        update_post_meta($productId, '_manage_stock', 'yes');
        update_post_meta($productId, '_stock', (string) $stockQty);
        update_post_meta($productId, '_stock_status', $stockStatus);
        update_post_meta($productId, '_ovoko_part_id', $partId);
        update_post_meta($productId, '_ovoko_status', (string) (($record['status'] ?? '') ?: ($normalized['status'] ?? '')));
        update_post_meta($productId, '_ovoko_updated_at', $updatedAt);
        update_post_meta($productId, '_ovoko_category_id', $categoryId);
        update_post_meta($productId, '_ovoko_category', $categoryPath);
        update_post_meta($productId, '_ovoko_source_url', (string) (($normalized['show_url'] ?? '') ?: ($normalized['shop_url'] ?? '')));
        update_post_meta($productId, '_ovoko_images', (array) ($normalized['part_photo_gallery'] ?? []));
        update_post_meta($productId, '_ovoko_internal_notes_price_source', !empty($price['ok']) ? 'internal_notes' : '');
        update_post_meta($productId, '_ovoko_woo_target_currency', 'PLN');
        update_post_meta($productId, '_ovoko_manufacturer_code', (string) ($normalized['manufacturer_code'] ?? ''));
        update_post_meta($productId, '_mpn', (string) ($normalized['manufacturer_code'] ?? ''));
        update_post_meta($productId, 'mpn', (string) ($normalized['manufacturer_code'] ?? ''));
        update_post_meta($productId, '_manufacturer_code', (string) ($normalized['manufacturer_code'] ?? ''));
        update_post_meta($productId, '_gpswiss_part_number', (string) ($normalized['manufacturer_code'] ?? ''));
        update_post_meta($productId, '_part_number', (string) ($normalized['manufacturer_code'] ?? ''));
        $this->upsert_custom_product_attributes($productId, $attributes);
        $this->write_ovoko_table_meta_from_attributes($productId, $attributes);
        if ($updatedAt !== '') {
            update_post_meta($productId, '_gpswiss_ovoko_last_synced_updated_at', $updatedAt);
        }
        if ($syncHash !== '') {
            update_post_meta($productId, '_gpswiss_ovoko_last_synced_hash', $syncHash);
        }
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            if ($product && method_exists($product, 'save')) {
                $product->save();
            }
        }

        return [
            'ok' => true,
            'part_id' => $partId,
            'product_id' => $productId,
            'created_product_id' => $created ? $productId : 0,
            'created' => $created,
            'updated' => $updated,
            'price_written' => $created,
            'price_untouched' => !$created,
            'stock_status' => $stockStatus,
            'stock_quantity' => $stockQty,
            'description_updated' => $description !== '',
            'details_updated' => $attributes !== [],
            'category_tree_resolution_ok' => !empty($categoryResolution['ok']) && $categoryPath !== '',
            'category_path_from_tree' => $categoryPath,
            'category_policy' => $created ? 'assigned_from_category_id_tree' : 'verify_only_no_category_write',
            'images_policy' => $created ? 'imported_for_new_product' : 'untouched_existing_product',
            'images_import_result' => $imageImportResult,
            'idempotency_meta_written' => ['_gpswiss_ovoko_last_synced_updated_at' => $updatedAt, '_gpswiss_ovoko_last_synced_hash' => $syncHash],
            'published' => $created,
        ];
    }

    private function ensure_manual_live_product_category_path(string $path, string $ovokoCategoryId = ''): int
    {
        if (!taxonomy_exists('product_cat')) {
            return 0;
        }
        $segments = preg_split('/\s*(?:>|\/|»|→)\s*/u', $path) ?: [];
        $segments = array_values(array_filter(array_map(static fn($segment) => trim(wp_strip_all_tags((string) $segment)), $segments), static fn($segment) => $segment !== ''));
        $parent = 0;
        $termId = 0;
        foreach ($segments as $index => $segment) {
            $existing = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'name' => $segment, 'parent' => $parent, 'number' => 1]);
            $term = !is_wp_error($existing) && !empty($existing[0]) ? $existing[0] : null;
            if (!$term) {
                $created = wp_insert_term($segment, 'product_cat', ['parent' => $parent]);
                if (is_wp_error($created)) {
                    return 0;
                }
                $term = get_term((int) $created['term_id'], 'product_cat');
            }
            if (!$term || is_wp_error($term)) {
                return 0;
            }
            $termId = (int) $term->term_id;
            update_term_meta($termId, '_gpswiss_ovoko_category_path', $path);
            update_term_meta($termId, '_gpswiss_ovoko_category_level_index', (string) $index);
            if ($ovokoCategoryId !== '' && $index === count($segments) - 1) {
                update_term_meta($termId, '_gpswiss_ovoko_category_id', $ovokoCategoryId);
            }
            $parent = $termId;
        }
        return $termId;
    }


    public function preview_listing_image_status(int $productId): array
    {
        $productId = max(1, $productId);
        $thumbnailId = (int) get_post_thumbnail_id($productId);
        $galleryRaw = (string) get_post_meta($productId, '_product_image_gallery', true);
        $galleryIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $galleryRaw)))));
        $listingImageId = (int) get_post_meta($productId, '_awi_listing_image_id', true);
        $listingSourceId = (int) get_post_meta($productId, '_awi_listing_image_source_id', true);
        $generatedAt = (string) get_post_meta($productId, '_awi_listing_image_generated_at', true);
        $frontendSource = $listingImageId > 0 ? 'awi_listing_image_id' : ($thumbnailId > 0 ? '_thumbnail_id' : 'none');
        $thumbnailDimensions = $thumbnailId > 0 ? wp_get_attachment_metadata($thumbnailId) : [];
        $listingDimensions = $listingImageId > 0 ? wp_get_attachment_metadata($listingImageId) : [];
        $listingSameAsThumbnail = ($listingImageId > 0 && $thumbnailId > 0 && $listingImageId === $thumbnailId);
        $selection = $this->select_best_listing_source_image($productId);
        $selectedSourceId = (int) ($selection['selected_source_image_id'] ?? 0);
        $selectedMetrics = $selectedSourceId > 0 ? $this->get_attachment_trim_metrics_for_listing_selection($selectedSourceId) : null;
        $selectedScore = is_array($selectedMetrics) ? $this->calculate_listing_source_quality_score($selectedMetrics) : 0.0;
        $selectedTier = is_array($selectedMetrics) ? $this->determine_listing_source_quality_tier($selectedMetrics) : 'unknown';
        $reason = 'missing_thumbnail';
        if ($listingImageId > 0 && !$listingSameAsThumbnail) {
            $reason = 'real_generated_listing_image_present';
        } elseif ($listingSameAsThumbnail) {
            $reason = 'listing_image_equals_thumbnail_fallback';
        } elseif ($thumbnailId > 0) {
            $reason = 'missing_listing_image_meta';
        }

        return [
            'ok' => true,
            'product_id' => $productId,
            'thumbnail_id' => $thumbnailId,
            'gallery_ids' => $galleryIds,
            'listing_image_id' => $listingImageId,
            'listing_image_source_id' => $listingSourceId,
            'listing_image_generated_at' => $generatedAt,
            'frontend_image_source' => $frontendSource,
            'frontend_expected_meta_key' => '_awi_listing_image_id',
            'thumbnail_url' => $thumbnailId > 0 ? (string) wp_get_attachment_url($thumbnailId) : '',
            'listing_image_url' => $listingImageId > 0 ? (string) wp_get_attachment_url($listingImageId) : '',
            'listing_image_is_same_as_thumbnail' => $listingSameAsThumbnail,
            'thumbnail_dimensions' => ['width' => (int) ($thumbnailDimensions['width'] ?? 0), 'height' => (int) ($thumbnailDimensions['height'] ?? 0)],
            'listing_image_dimensions' => ['width' => (int) ($listingDimensions['width'] ?? 0), 'height' => (int) ($listingDimensions['height'] ?? 0)],
            'strategy' => $listingImageId > 0 && !$listingSameAsThumbnail ? 'ovoko_copied_allegro_generator' : 'real_generator_required',
            'needs_real_generated_listing_image' => $listingImageId <= 0 || $listingSameAsThumbnail,
            'needs_listing_image_generation' => $listingImageId <= 0 || $listingSameAsThumbnail,
            'reason' => $reason,
            'candidate_source_image_ids' => (array) ($selection['candidate_source_image_ids'] ?? []),
            'selected_source_image_id' => $selectedSourceId,
            'selected_source_aspect_ratio' => (float) ($selection['selected_source_aspect_ratio'] ?? 0.0),
            'selected_source_square_fill_ratio' => (float) ($selection['selected_source_square_fill_ratio'] ?? 0.0),
            'selected_source_selection_reason' => (string) ($selection['selected_source_selection_reason'] ?? 'no_valid_image_candidates'),
            'listing_quality_tier' => $selectedTier,
            'listing_quality_score' => round((float) $selectedScore, 6),
            'standard_quality_tier_before_boost' => $selectedTier,
            'standard_quality_score_before_boost' => round((float) $selectedScore, 6),
            'target_fill_ratio' => (float) get_post_meta($listingImageId, '_awi_listing_target_fill_ratio', true),
            'image_width' => (int) get_post_meta($listingImageId, '_awi_listing_source_width', true),
            'image_height' => (int) get_post_meta($listingImageId, '_awi_listing_source_height', true),
            'object_width' => (int) get_post_meta($listingImageId, '_awi_listing_object_width', true),
            'object_height' => (int) get_post_meta($listingImageId, '_awi_listing_object_height', true),
            'crop_width' => (int) get_post_meta($listingImageId, '_awi_listing_crop_width', true),
            'crop_height' => (int) get_post_meta($listingImageId, '_awi_listing_crop_height', true),
            'final_rendered_width' => (int) get_post_meta($listingImageId, '_awi_listing_rendered_width', true),
            'final_rendered_height' => (int) get_post_meta($listingImageId, '_awi_listing_rendered_height', true),
        ];
    }

    public function probe_ovoko_image_url_variants(int $partId): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $payload = (array) ($result['payload'] ?? []);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $sourceUrls = array_slice((array) ($normalized['part_photo_gallery'] ?? []), 0, 3);
        $token = trim((string) (($this->get_settings())['ovoko_original_image_bearer_token'] ?? ''));
        $authUsed = $token !== '';
        $probes = [];
        $workingOriginalUrls = [];
        foreach ($sourceUrls as $sourceUrl) {
            $sourceUrl = trim((string) $sourceUrl);
            if ($sourceUrl === '') {
                continue;
            }
            $perUrl = [];
            foreach ($this->build_original_image_candidates($sourceUrl) as $candidateUrl) {
                if ($candidateUrl === '') {
                    continue;
                }
                $requestArgs = [
                    'timeout' => 7,
                    'redirection' => 2,
                    'headers' => [
                        'User-Agent' => 'GPSwissWooImporter/1.0',
                    ],
                ];
                if ($authUsed) {
                    $requestArgs['headers']['Authorization'] = 'Bearer ' . $token;
                }
                $head = wp_remote_head($candidateUrl, $requestArgs);
                if (is_wp_error($head) || (int) wp_remote_retrieve_response_code($head) >= 400) {
                    $head = wp_remote_get($candidateUrl, $requestArgs);
                }
                $headers = !is_wp_error($head) ? (array) wp_remote_retrieve_headers($head) : [];
                $httpCode = !is_wp_error($head) ? (int) wp_remote_retrieve_response_code($head) : 0;
                $contentType = (string) ($headers['content-type'] ?? '');
                $likelyClean = $httpCode >= 200 && $httpCode < 300 && str_starts_with(strtolower($contentType), 'image/');
                if ($likelyClean) {
                    $workingOriginalUrls[] = $candidateUrl;
                }
                $perUrl[] = [
                    'source_url' => $sourceUrl,
                    'candidate_url' => $candidateUrl,
                    'auth_used' => $authUsed,
                    'http_code' => $httpCode,
                    'content_type' => $contentType,
                    'content_length' => (string) ($headers['content-length'] ?? ''),
                    'dimensions' => '',
                    'likely_clean_source' => $likelyClean,
                    'reason' => $likelyClean ? 'candidate_responded_with_image_content' : 'non_2xx_or_non_image_response',
                    'error' => is_wp_error($head) ? $head->get_error_message() : '',
                ];
            }
            $probes[] = ['source_url' => $sourceUrl, 'probes' => $perUrl];
        }
        $workingOriginalUrls = array_values(array_unique($workingOriginalUrls));
        set_transient('gpswiss_ovoko_original_probe_' . $partId, [
            'part_id' => $partId,
            'clean_source_found' => !empty($workingOriginalUrls),
            'image_source_selected' => !empty($workingOriginalUrls) ? 'authenticated_original' : 'public_watermarked_fallback',
            'image_watermark_warning' => !empty($workingOriginalUrls) ? '' : 'Only public Ovoko watermarked image URLs found or authenticated original image probe failed.',
            'authenticated_original_urls' => $workingOriginalUrls,
            'checked_at' => gmdate('c'),
        ], 12 * HOUR_IN_SECONDS);

        return ['ok' => !empty($result['ok']), 'mode' => 'diagnostic_probe', 'action_name' => 'Probe Ovoko original image auth', 'part_id' => $partId, 'token_configured' => $authUsed, 'limits' => ['max_images' => 3, 'max_requests' => 15], 'probes' => $probes, 'clean_source_found' => !empty($workingOriginalUrls), 'image_source_selected' => !empty($workingOriginalUrls) ? 'authenticated_original' : 'public_watermarked_fallback', 'image_watermark_warning' => !empty($workingOriginalUrls) ? '' : 'Only public Ovoko watermarked image URLs found or authenticated original image probe failed.', 'checked_at' => gmdate('c')];
    }

    private function get_cached_authenticated_original_urls(int $partId): array
    {
        $cached = get_transient('gpswiss_ovoko_original_probe_' . $partId);
        return is_array($cached) ? array_values(array_filter((array) ($cached['authenticated_original_urls'] ?? []), 'is_string')) : [];
    }

    private function build_original_image_candidates(string $sourceUrl): array
    {
        $candidates = [];
        $candidates[] = preg_replace('~/((br|bl|tr|tl)/)?\\d{2,4}x\\d{2,4}/~', '/$1original/', $sourceUrl, 1);
        $candidates[] = preg_replace('~/((br|bl|tr|tl)/)?\\d{2,4}x\\d{2,4}/~', '/original/', $sourceUrl, 1);
        return array_values(array_unique(array_filter(array_map(static fn($u) => is_string($u) ? $u : '', $candidates))));
    }

    private function scan_image_url_fields(array $payload): array
    {
        $found = [];
        $walker = function ($value, string $path) use (&$walker, &$found): void {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $walker($v, $path === '' ? (string) $k : $path . '.' . (string) $k);
                }
                return;
            }
            if (!is_string($value)) {
                return;
            }
            $url = trim($value);
            if ($url === '' || stripos($url, 'http') !== 0) {
                return;
            }
            if (preg_match('~\.(jpg|jpeg|png|webp)(\?.*)?$~i', $url) || str_contains($url, 'images.ovoko.com') || preg_match('~(cdn|photo|gallery|image)~i', $url)) {
                $found[] = ['field_path' => $path, 'url' => $url];
            }
        };
        $walker($payload, '');
        return array_values($found);
    }

    private function build_image_field_diagnostics(array $payload): array
    {
        $out = [];
        foreach ($this->scan_image_url_fields($payload) as $row) {
            $url = (string) ($row['url'] ?? '');
            $parsed = wp_parse_url($url);
            $path = (string) ($parsed['path'] ?? '');
            preg_match('~/(\\d{2,4}x\\d{2,4})/~', $path, $m);
            $out[] = ['field_path' => (string) ($row['field_path'] ?? ''), 'url_sample' => $url, 'host' => (string) ($parsed['host'] ?? ''), 'path' => $path, 'detected_size_variant' => (string) ($m[1] ?? ''), 'contains_watermark_likely' => $this->is_likely_watermarked_url($url), 'reason' => str_contains($url, 'images.ovoko.com') ? 'public_ovoko_image_host' : 'url_pattern_review_required'];
        }
        return $out;
    }

    private function is_likely_watermarked_url(string $url): bool
    {
        return str_contains($url, 'images.ovoko.com') || (bool) preg_match('~/(br|tl|bl)/~', $url);
    }

    private function build_candidate_image_variant_url(string $sourceUrl, string $variant): string
    {
        if ($variant === '') return $sourceUrl;
        $parsed = wp_parse_url($sourceUrl);
        $scheme = (string) ($parsed['scheme'] ?? '');
        $host = (string) ($parsed['host'] ?? '');
        $path = (string) ($parsed['path'] ?? '');
        if ($scheme === '' || $host === '' || $path === '') return '';
        $newPath = preg_replace('~/((br|bl|tl)/)?\\d{2,4}x\\d{2,4}/~', $variant, $path, 1);
        return is_string($newPath) && $newPath !== '' ? ($scheme . '://' . $host . $newPath) : '';
    }

    public function generate_listing_image_for_ovoko_product(int $productId): array
    {
        $status = $this->preview_listing_image_status($productId);
        $selection = $this->select_best_listing_source_image($productId);
        $selectedSourceId = (int) ($selection['selected_source_image_id'] ?? 0);
        $result = [
            'ok' => true,
            'product_id' => $productId,
            'listing_image_attempted' => true,
            'listing_image_generated' => false,
            'listing_image_id' => 0,
            'listing_image_source_id' => 0,
            'listing_image_strategy' => 'ovoko_copied_allegro_generator_exact',
            'listing_image_meta_written' => [],
            'errors' => [],
        ];

        if ($selectedSourceId <= 0) {
            $result['errors'][] = 'missing_source_image';
            return $result;
        }

        $createdListingId = $this->create_ovoko_listing_image_attachment_from_source($selectedSourceId, $productId);
        if (is_wp_error($createdListingId)) {
            $result['reason'] = 'real_generator_unavailable';
            $result['errors'][] = $createdListingId->get_error_code() . ': ' . $createdListingId->get_error_message();
            return $result;
        }

        update_post_meta($productId, '_awi_listing_image_id', (int) $createdListingId);
        update_post_meta($productId, '_awi_listing_image_source_id', $selectedSourceId);
        update_post_meta($productId, '_awi_listing_image_generated_at', gmdate('Y-m-d H:i:s'));
        update_post_meta($productId, '_awi_listing_selected_source_image_id', $selectedSourceId);
        update_post_meta($productId, '_awi_listing_selected_source_aspect_ratio', (float) ($selection['selected_source_aspect_ratio'] ?? 0.0));
        update_post_meta($productId, '_awi_listing_source_selection_reason', (string) ($selection['selected_source_selection_reason'] ?? ''));
        $result['listing_image_id'] = (int) $createdListingId;
        $result['listing_image_source_id'] = $selectedSourceId;
        $result['listing_image_generated'] = ((int) $createdListingId > 0 && (int) $createdListingId !== $selectedSourceId);
        $result['listing_image_strategy'] = 'ovoko_copied_allegro_generator_exact';
        $result['listing_image_meta_written'] = ['_awi_listing_image_id', '_awi_listing_image_source_id', '_awi_listing_image_generated_at', '_awi_listing_variant', '_awi_listing_source_id', '_awi_listing_selected_source_image_id', '_awi_listing_selected_source_aspect_ratio', '_awi_listing_source_selection_reason'];
        $result = array_merge($result, $this->preview_listing_image_status($productId));

        return $result;
    }

    private function create_ovoko_listing_image_attachment_from_source(int $sourceAttachmentId, int $productId)
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            return new \WP_Error('gd_missing', 'Brak biblioteki GD wymaganej do generowania obrazu listingowego.');
        }
        $sourcePath = get_attached_file($sourceAttachmentId);
        if (!is_string($sourcePath) || $sourcePath === '' || !file_exists($sourcePath)) {
            return new \WP_Error('missing_source_file', 'Brak pliku źródłowego dla zdjęcia listingowego.');
        }
        $sourceBlob = file_get_contents($sourcePath);
        $sourceImage = is_string($sourceBlob) ? @imagecreatefromstring($sourceBlob) : false;
        if (!$sourceImage) {
            return new \WP_Error('source_decode_failed', 'Nie udało się odczytać obrazu źródłowego.');
        }
        $sourceWidth = imagesx($sourceImage); $sourceHeight = imagesy($sourceImage);
        $canvasSize = 900;
        $bbox = $this->detect_non_white_bbox($sourceImage, $sourceWidth, $sourceHeight);
        $objectX = 0; $objectY = 0; $objectWidth = $sourceWidth; $objectHeight = $sourceHeight;
        if (is_array($bbox)) { $objectX = (int) $bbox['x']; $objectY = (int) $bbox['y']; $objectWidth = (int) $bbox['width']; $objectHeight = (int) $bbox['height']; }
        $objectAspectRatio = $objectWidth / max(1, $objectHeight);
        $renderProfile = ($objectAspectRatio < 0.55 || $objectAspectRatio > 2.0) ? 'boost_generic' : 'standard';
        $targetRatio = $renderProfile === 'standard' ? 0.96 : 0.995;
        $cropPaddingRatio = $renderProfile === 'standard' ? 0.08 : 0.02;
        $desiredSide = (int) round(max($objectWidth, $objectHeight) * (1 + $cropPaddingRatio));
        $cropSide = max(1, min($desiredSide, min($sourceWidth, $sourceHeight)));
        $objectCenterX = $objectX + ($objectWidth / 2); $objectCenterY = $objectY + ($objectHeight / 2);
        $cropX = max(0, min((int) round($objectCenterX - ($cropSide / 2)), $sourceWidth - $cropSide));
        $cropY = max(0, min((int) round($objectCenterY - ($cropSide / 2)), $sourceHeight - $cropSide));
        $cropWidth = $cropSide; $cropHeight = $cropSide;
        $targetObjectSize = (int) round($canvasSize * $targetRatio);
        $scale = $targetObjectSize / max(1, max($cropWidth, $cropHeight));
        $targetWidth = max(1, (int) round($cropWidth * $scale));
        $targetHeight = max(1, (int) round($cropHeight * $scale));
        $dstX = (int) floor(($canvasSize - $targetWidth) / 2);
        $dstY = (int) floor(($canvasSize - $targetHeight) / 2);
        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $sourceImage, $dstX, $dstY, $cropX, $cropY, $targetWidth, $targetHeight, $cropWidth, $cropHeight);
        $uploadDir = wp_upload_dir();
        $targetFilename = wp_unique_filename((string) $uploadDir['path'], sanitize_file_name(pathinfo((string) basename($sourcePath), PATHINFO_FILENAME) . '-awi-listing.jpg'));
        $targetPath = trailingslashit((string) $uploadDir['path']) . $targetFilename;
        $saved = imagejpeg($canvas, $targetPath, 90);
        imagedestroy($sourceImage); imagedestroy($canvas);
        if (!$saved) {
            return new \WP_Error('listing_image_save_failed', 'Nie udało się zapisać obrazu listingowego.');
        }
        $attachmentId = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => sanitize_text_field((string) get_the_title($sourceAttachmentId)) . ' listing', 'post_status' => 'inherit', 'post_parent' => $productId], $targetPath, $productId);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata((int) $attachmentId, $targetPath);
        if (is_array($meta)) { wp_update_attachment_metadata((int) $attachmentId, $meta); }
        update_post_meta((int) $attachmentId, '_awi_listing_variant', 1);
        update_post_meta((int) $attachmentId, '_awi_listing_source_id', $sourceAttachmentId);
        update_post_meta((int) $attachmentId, '_awi_listing_target_fill_ratio', $targetRatio);
        update_post_meta((int) $attachmentId, '_awi_listing_source_width', $sourceWidth);
        update_post_meta((int) $attachmentId, '_awi_listing_source_height', $sourceHeight);
        update_post_meta((int) $attachmentId, '_awi_listing_object_width', $objectWidth);
        update_post_meta((int) $attachmentId, '_awi_listing_object_height', $objectHeight);
        update_post_meta((int) $attachmentId, '_awi_listing_crop_width', $cropWidth);
        update_post_meta((int) $attachmentId, '_awi_listing_crop_height', $cropHeight);
        update_post_meta((int) $attachmentId, '_awi_listing_rendered_width', $targetWidth);
        update_post_meta((int) $attachmentId, '_awi_listing_rendered_height', $targetHeight);
        update_post_meta((int) $attachmentId, '_awi_listing_render_profile', $renderProfile);
        return (int) $attachmentId;
    }

    private function select_best_listing_source_image(int $productId): array
    {
        $thumbnailId = (int) get_post_thumbnail_id($productId);
        $galleryIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', (string) get_post_meta($productId, '_product_image_gallery', true))))));
        $candidateIds = array_values(array_unique(array_filter(array_merge([$thumbnailId], $galleryIds))));
        $best = null; $bestScore = null;
        foreach ($candidateIds as $candidateId) {
            $metrics = $this->get_attachment_trim_metrics_for_listing_selection((int) $candidateId);
            if (!is_array($metrics)) { continue; }
            $score = $this->calculate_listing_source_quality_score($metrics);
            if ($best === null || $score > (float) $bestScore) { $best = $metrics; $bestScore = $score; }
        }
        if (!is_array($best)) { return ['candidate_source_image_ids' => $candidateIds, 'selected_source_image_id' => 0, 'selected_source_aspect_ratio' => 0.0, 'selected_source_square_fill_ratio' => 0.0, 'selected_source_selection_reason' => 'no_valid_image_candidates']; }
        $tier = $this->determine_listing_source_quality_tier($best);
        $reason = sprintf('listing_first_quality_score:score=%.6f;tier=%s;aspect_ratio=%.6f;aspect_distance=%.6f;object_area_ratio=%.6f;square_fill_ratio=%.6f', (float) $bestScore, $tier, (float) $best['aspect_ratio'], (float) $best['aspect_distance_from_square'], (float) $best['object_area_ratio'], (float) $best['square_fill_ratio']);
        return ['candidate_source_image_ids' => $candidateIds, 'selected_source_image_id' => (int) $best['attachment_id'], 'selected_source_aspect_ratio' => round((float) $best['aspect_ratio'], 6), 'selected_source_square_fill_ratio' => round((float) $best['square_fill_ratio'], 6), 'selected_source_selection_reason' => $reason];
    }

    private function determine_listing_source_quality_tier(array $metrics): string { $s = (float) ($metrics['square_fill_ratio'] ?? 0.0); $a = (float) ($metrics['aspect_ratio'] ?? 0.0); if ($s >= 0.60 && $a >= 0.55 && $a <= 1.80) { return 'preferred'; } if ($s < 0.50 || $a < 0.55 || $a > 1.80) { return 'degraded'; } return 'acceptable'; }
    private function calculate_listing_source_quality_score(array $metrics): float { $s = max(0.0, min(1.0, (float) ($metrics['square_fill_ratio'] ?? 0.0))); $a = max(0.000001, (float) ($metrics['aspect_ratio'] ?? 1.0)); $d = abs(1.0 - $a); $o = max(0.0, min(1.0, (float) ($metrics['object_area_ratio'] ?? 0.0))); $score = ($s * 1000.0) + ($o * 150.0) - ($d * 110.0); if ($s >= 0.60) { $score += 120.0; } elseif ($s < 0.50) { $score -= 260.0; } if ($s < 0.40) { $score -= 220.0; } if ($a < 0.55 || $a > 1.80) { $score -= 220.0; } if ($a < 0.40 || $a > 2.50) { $score -= 420.0; } return $score; }
    private function get_attachment_trim_metrics_for_listing_selection(int $attachmentId): ?array { $path = get_attached_file($attachmentId); if (!is_string($path) || $path === '' || !file_exists($path)) { return null; } $blob = file_get_contents($path); $img = is_string($blob) ? @imagecreatefromstring($blob) : false; if (!$img) { return null; } $w = imagesx($img); $h = imagesy($img); if ($w <= 0 || $h <= 0) { imagedestroy($img); return null; } $bbox = $this->detect_non_white_bbox($img, $w, $h); imagedestroy($img); $ow = is_array($bbox) ? (int) $bbox['width'] : $w; $oh = is_array($bbox) ? (int) $bbox['height'] : $h; $ratio = $ow / max(1, $oh); $long = max($ow, $oh); $short = min($ow, $oh); return ['attachment_id' => $attachmentId, 'aspect_ratio' => $ratio, 'aspect_distance_from_square' => abs(1.0 - $ratio), 'square_fill_ratio' => $short / max(1, $long), 'object_area_ratio' => ($ow * $oh) / max(1, ($w * $h))]; }
    private function detect_non_white_bbox($img, int $w, int $h): ?array { $minX = $w; $minY = $h; $maxX = -1; $maxY = -1; for ($y = 0; $y < $h; $y += 2) { for ($x = 0; $x < $w; $x += 2) { $rgb = imagecolorat($img, $x, $y); $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF; if ($r < 245 || $g < 245 || $b < 245) { $minX = min($minX, $x); $minY = min($minY, $y); $maxX = max($maxX, $x); $maxY = max($maxY, $y); } } } if ($maxX < 0 || $maxY < 0) { return null; } return ['x' => $minX, 'y' => $minY, 'width' => max(1, $maxX - $minX + 1), 'height' => max(1, $maxY - $minY + 1)]; }

    public function build_woo_product_title_from_rrr_part(array $normalizedPart): array
    {
        $makeShortRaw = trim((string) ($normalizedPart['vehicle_make_short'] ?? ''));
        $makeRaw = trim((string) ($normalizedPart['vehicle_make'] ?? ''));
        $makeMap = ['Volkswagen' => 'VW', 'Mercedes-Benz' => 'Mercedes', 'BMW' => 'BMW', 'Audi' => 'Audi'];
        $make = strtoupper($makeShortRaw !== '' ? $makeShortRaw : ($makeMap[$makeRaw] ?? $makeRaw));
        $model = strtoupper(trim((string) ($normalizedPart['vehicle_model'] ?? '')));
        $generation = strtoupper(trim((string) ($normalizedPart['vehicle_generation'] ?? '')));
        $engine = strtoupper(trim((string) (($normalizedPart['vehicle_engine_marketing'] ?? '') ?: ($normalizedPart['vehicle_engine_marketing_name'] ?? ''))));
        $notes = strtoupper(trim((string) ($normalizedPart['notes'] ?? '')));
        $manufacturerCode = strtoupper(trim((string) ($normalizedPart['manufacturer_code'] ?? '')));
        $name = strtoupper(trim((string) ($normalizedPart['title'] ?? '')));
        $required = ['vehicle_make' => $make, 'vehicle_model' => $model, 'vehicle_generation' => $generation, 'vehicle_engine_marketing' => $engine];
        $missing = [];
        foreach ($required as $field => $value) { if ($value === '') { $missing[] = $field; } }
        $hasFullVehicleData = empty($missing);
        $fallbackTitle = $this->join_title_parts([$notes, $manufacturerCode]);
        if ($fallbackTitle === '') { $fallbackTitle = $this->join_title_parts([$name, $notes, $manufacturerCode]); }
        $vehiclePrefixData = $this->build_vehicle_title_prefix($normalizedPart);
        $vehiclePrefix = (string) ($vehiclePrefixData['vehicle_prefix_after_dedupe'] ?? '');
        $proposedTitle = $hasFullVehicleData ? $this->join_title_parts([$vehiclePrefix, $notes, $manufacturerCode]) : $fallbackTitle;

        return [
            'proposed_title' => $proposedTitle,
            'proposed_title_fallback' => $fallbackTitle,
            'vehicle_title_prefix' => $vehiclePrefix,
            'vehicle_model' => (string) ($vehiclePrefixData['vehicle_model'] ?? ''),
            'vehicle_generation' => (string) ($vehiclePrefixData['vehicle_generation'] ?? ''),
            'generation_contains_model' => !empty($vehiclePrefixData['generation_contains_model']),
            'vehicle_prefix_before_dedupe' => (string) ($vehiclePrefixData['vehicle_prefix_before_dedupe'] ?? ''),
            'vehicle_prefix_after_dedupe' => (string) ($vehiclePrefixData['vehicle_prefix_after_dedupe'] ?? ''),
            'title_review_required' => !$hasFullVehicleData,
            'missing_vehicle_fields' => $missing,
            '_ovoko_title_source' => $hasFullVehicleData ? 'vehicle_data_rrr_with_local_confirmed_dictionary' : 'fallback_missing_vehicle_data',
            '_ovoko_title_review_required' => $hasFullVehicleData ? 'no' : 'yes',
            '_ovoko_title_missing_vehicle_fields' => implode('/', $missing),
            '_ovoko_title_generated_from' => $hasFullVehicleData ? 'vehicle_make_short + vehicle_model_generation + vehicle_engine_marketing + notes + manufacturer_code' : 'notes+manufacturer_code_fallback',
        ];
    }

    private function build_vehicle_title_prefix(array $vehicleData): array
    {
        $makeShortRaw = trim((string) ($vehicleData['vehicle_make_short'] ?? ''));
        $makeRaw = trim((string) ($vehicleData['vehicle_make'] ?? ''));
        $makeMap = ['Volkswagen' => 'VW', 'Mercedes-Benz' => 'Mercedes', 'BMW' => 'BMW', 'Audi' => 'Audi'];
        $make = strtoupper($makeShortRaw !== '' ? $makeShortRaw : ($makeMap[$makeRaw] ?? $makeRaw));
        $model = strtoupper(trim((string) ($vehicleData['vehicle_model'] ?? '')));
        $generation = strtoupper(trim((string) ($vehicleData['vehicle_generation'] ?? '')));
        $engine = strtoupper(trim((string) (($vehicleData['vehicle_engine_marketing'] ?? '') ?: ($vehicleData['vehicle_engine_marketing_name'] ?? ''))));
        $vehiclePrefixBeforeDedupe = $this->join_title_parts([$make, $model, $generation, $engine]);

        $modelNormalized = $this->normalize_vehicle_compare_value($model);
        $generationNormalized = $this->normalize_vehicle_compare_value($generation);
        $generationContainsModel = $modelNormalized !== '' && $generationNormalized !== '' && str_starts_with($generationNormalized, $modelNormalized);

        $modelAndGenerationPart = $generationContainsModel ? $generation : $this->join_title_parts([$model, $generation]);
        $vehiclePrefixAfterDedupe = $this->join_title_parts([$make, $modelAndGenerationPart, $engine]);

        return [
            'vehicle_model' => $model,
            'vehicle_generation' => $generation,
            'generation_contains_model' => $generationContainsModel,
            'vehicle_prefix_before_dedupe' => $vehiclePrefixBeforeDedupe,
            'vehicle_prefix_after_dedupe' => $vehiclePrefixAfterDedupe,
        ];
    }

    private function normalize_vehicle_compare_value(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(trim($value), 'UTF-8')));
    }

    private function join_title_parts(array $parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $clean[] = preg_replace('/\s+/', ' ', $value);
            }
        }
        return implode(' ', array_values(array_unique($clean)));
    }

    private function import_ovoko_images_for_product(int $productId, array $normalized, int $partId): array
    {
        $imagePlan = (new OvokoImageImportPlan())->preview_image_import_plan($normalized, $partId);
        $urls = (array) ($imagePlan['source_image_urls'] ?? []);
        $urls = array_values(array_slice($urls, 0, OvokoImageImportPlan::MAX_IMAGES_PER_PRODUCT));
        $importedAttachmentIds = [];
        $reusedAttachmentIds = [];
        $failedUrls = [];
        $attachmentIdsInOrder = [];

        if (empty($urls)) {
            return ['images_count' => 0, 'images_import_failed' => true, 'imported_attachment_ids' => [], 'reused_attachment_ids' => [], 'failed_urls' => [], 'featured_attachment_id' => 0, 'gallery_attachment_ids' => []];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        foreach ($urls as $imageUrl) {
            $attachmentId = $this->find_attachment_by_awi_source_url($imageUrl);
            if ($attachmentId > 0) {
                $attachmentIdsInOrder[] = $attachmentId;
                $reusedAttachmentIds[] = $attachmentId;
                continue;
            }

            $attachmentId = $this->sideload_ovoko_image($imageUrl, $productId, $partId);
            if ($attachmentId <= 0) {
                $failedUrls[] = $imageUrl;
                continue;
            }
            $attachmentIdsInOrder[] = $attachmentId;
            $importedAttachmentIds[] = $attachmentId;
        }

        $attachmentIdsInOrder = array_values(array_unique(array_map('intval', $attachmentIdsInOrder)));
        $featuredId = (int) ($attachmentIdsInOrder[0] ?? 0);
        $galleryIds = array_values(array_slice($attachmentIdsInOrder, 1));

        if ($featuredId > 0) {
            update_post_meta($productId, '_thumbnail_id', $featuredId);
        }
        update_post_meta($productId, '_product_image_gallery', implode(',', $galleryIds));

        return [
            'images_count' => count($urls),
            'images_import_failed' => empty($attachmentIdsInOrder),
            'imported_attachment_ids' => array_values(array_unique(array_map('intval', $importedAttachmentIds))),
            'reused_attachment_ids' => array_values(array_unique(array_map('intval', $reusedAttachmentIds))),
            'failed_urls' => $failedUrls,
            'featured_attachment_id' => $featuredId,
            'gallery_attachment_ids' => $galleryIds,
        ];
    }

    private function find_attachment_by_awi_source_url(string $imageUrl): int
    {
        $imageUrl = trim($imageUrl);
        if ($imageUrl === '') {
            return 0;
        }
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_awi_source_url',
            'meta_value' => $imageUrl,
        ]);
        return (int) ($ids[0] ?? 0);
    }

    private function sideload_ovoko_image(string $imageUrl, int $productId, int $partId): int
    {
        add_filter('http_request_timeout', [$this, 'ovoko_image_download_timeout']);
        $tmp = download_url($imageUrl);
        remove_filter('http_request_timeout', [$this, 'ovoko_image_download_timeout']);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $path = wp_parse_url($imageUrl, PHP_URL_PATH);
        $filename = basename((string) $path);
        if ($filename === '' || $filename === '0') {
            $filename = 'ovoko-' . $partId . '.jpg';
        }
        $fileArray = ['name' => sanitize_file_name($filename), 'tmp_name' => $tmp];
        $attachmentId = media_handle_sideload($fileArray, $productId);
        if (is_wp_error($attachmentId)) {
            @unlink($tmp);
            return 0;
        }

        $attachmentId = (int) $attachmentId;
        update_post_meta($attachmentId, '_awi_source_url', $imageUrl);
        update_post_meta($attachmentId, '_ovoko_source_url', $imageUrl);
        update_post_meta($attachmentId, '_ovoko_part_id', (string) $partId);
        update_post_meta($attachmentId, '_ovoko_imported_image', 'yes');
        return $attachmentId;
    }

    public function ovoko_image_download_timeout(): int
    {
        return 20;
    }

    private function build_rrr_manual_create_validations(array $result, array $normalized, array $match): array
    {
        $priceSource = (string) ($normalized['price_source'] ?? '');
        $priceReviewRequired = !empty($normalized['price_review_required']);
        $wooTargetPrice = (float) ($normalized['woo_target_price'] ?? 0);
        return [
            ['name' => 'rrr_status_code_r200', 'passed' => ((string) ($result['status_code'] ?? '')) === 'R200', 'blocking' => true],
            ['name' => 'no_existing_match', 'passed' => empty($match['matched_product_id']), 'blocking' => true],
            ['name' => 'create_blocked_false', 'passed' => ($priceSource === 'internal_notes_plain_price' && !$priceReviewRequired), 'blocking' => true],
            ['name' => 'gearbox_exclusion_false', 'passed' => empty($match['excluded_from_ovoko_sync']), 'blocking' => true],
            ['name' => 'price_source_internal_notes_plain_price', 'passed' => $priceSource === 'internal_notes_plain_price', 'blocking' => true],
            ['name' => 'price_review_required_false', 'passed' => !$priceReviewRequired, 'blocking' => true],
            ['name' => 'woo_target_price_gt_zero', 'passed' => $wooTargetPrice > 0, 'blocking' => true],
            ['name' => 'images_present_warning_only', 'passed' => !empty((array) ($normalized['part_photo_gallery'] ?? [])), 'blocking' => false],
        ];
    }

    private function build_rrr_single_part_woo_meta_preview(array $normalized): array
    {
        $priceSource = (string) ($normalized['price_source'] ?? '');
        $isPriceReady = in_array($priceSource, ['internal_notes_plain_price', 'allegro_channel_price'], true);
        return [
            'source' => 'ovoko_master',
            'price_source' => $priceSource,
            'price_review_required' => !empty($normalized['price_review_required']),
            'price_reason' => (string) ($normalized['price_reason'] ?? ''),
            'part_meta' => [
                '_ovoko_part_id' => (string) ($normalized['part_id'] ?? ''),
                '_ovoko_status' => (string) ($normalized['status'] ?? ''),
                '_ovoko_updated_at' => (string) ($normalized['updated_at'] ?? ''),
                '_ovoko_category' => (string) ($normalized['category_title_path'] ?? ''),
                '_ovoko_category_id' => (string) ($normalized['category_id'] ?? ''),
                '_ovoko_source_url' => (string) (($normalized['show_url'] ?? '') ?: ($normalized['shop_url'] ?? '')),
                '_ovoko_images' => (array) ($normalized['part_photo_gallery'] ?? []),
                '_ovoko_price' => (string) ($normalized['price'] ?? ''),
                '_ovoko_original_price' => (string) ($normalized['original_price'] ?? ''),
                '_ovoko_allegro_price' => (($priceSource === 'allegro_channel_price') ? (string) ($normalized['allegro_channel_price'] ?? '') : null),
                '_ovoko_internal_notes_price_source' => (($priceSource === 'internal_notes_plain_price') ? 'internal_notes_plain_price' : null),
                '_ovoko_woo_target_price' => $isPriceReady ? (string) ($normalized['woo_target_price'] ?? '') : null,
                '_ovoko_woo_target_currency' => $isPriceReady ? (string) ($normalized['woo_target_currency'] ?? '') : null,
                '_ovoko_currency' => (string) ($normalized['currency'] ?? ''),
                '_ovoko_manufacturer_code' => (string) ($normalized['manufacturer_code'] ?? ''),
                '_ovoko_visible_code' => (string) ($normalized['visible_code'] ?? ''),
                '_ovoko_other_code' => (string) ($normalized['other_code'] ?? ''),
                '_ovoko_quality' => (string) ($normalized['quality'] ?? ''),
                '_ovoko_position' => (string) ($normalized['position'] ?? ''),
                '_ovoko_place' => (string) ($normalized['place'] ?? ''),
                '_ovoko_raw_payload' => 'preview_only_omitted',
            ],
            'woo_price_write_preview' => $isPriceReady ? [
                'source' => $priceSource,
                'target_price' => (string) ($normalized['woo_target_price'] ?? ''),
                'target_currency' => (string) ($normalized['woo_target_currency'] ?? ''),
                'write_ready' => true,
                'no_write_to_woo' => true,
            ] : [
                'write_ready' => false,
                'blocked_reason' => (string) ($normalized['price_reason'] ?? ''),
                'no_write_to_woo' => true,
            ],
            'vehicle_meta' => [
                '_ovoko_car_id' => (string) (($normalized['car_id'] ?? '') ?: ($normalized['vehicle_id'] ?? '')),
                '_ovoko_vehicle_make' => (string) ($normalized['vehicle_make'] ?? ''),
                '_ovoko_vehicle_model' => (string) ($normalized['vehicle_model'] ?? ''),
                '_ovoko_vehicle_generation' => (string) ($normalized['vehicle_generation'] ?? ''),
                '_ovoko_vehicle_year' => (string) ($normalized['vehicle_year'] ?? ''),
                '_ovoko_vehicle_fuel' => (string) ($normalized['vehicle_fuel'] ?? ''),
                '_ovoko_vehicle_engine_capacity' => (string) ($normalized['vehicle_engine_capacity'] ?? ''),
                '_ovoko_vehicle_engine_power_kw' => (string) ($normalized['vehicle_engine_power_kw'] ?? ''),
                '_ovoko_engine_code' => (string) ($normalized['vehicle_engine_code'] ?? ''),
                '_ovoko_gearbox_type' => (string) ($normalized['vehicle_gearbox_type'] ?? ''),
                '_ovoko_vehicle_body_type' => (string) ($normalized['vehicle_body_type'] ?? ''),
                '_ovoko_vehicle_drive_wheels' => (string) ($normalized['vehicle_drive_wheels'] ?? ''),
                '_ovoko_vehicle_steering_position' => (string) ($normalized['vehicle_steering_position'] ?? ''),
                '_ovoko_vehicle_color' => (string) ($normalized['vehicle_color'] ?? ''),
                '_ovoko_vehicle_color_code' => (string) ($normalized['vehicle_color_code'] ?? ''),
                '_ovoko_vehicle_period' => (string) ($normalized['vehicle_period'] ?? ''),
            ],
        ];
    }

    private function build_same_vehicle_grouping_preview(array $normalized): array
    {
        $carId = (string) (($normalized['car_id'] ?? '') ?: ($normalized['vehicle_id'] ?? ''));
        return [
            'car_id' => $carId,
            'car_id_available' => $carId !== '',
            'future_query' => $carId !== '' ? 'products where _ovoko_car_id = ' . $carId : 'unavailable_without_car_id',
            'message' => 'This will enable ‘Other parts from this vehicle’ links after import/mapping.',
        ];
    }

    public function apply_ovoko_technical_attributes_to_product(int $productId): array
    {
        $productId = max(1, $productId);
        if (get_post_type($productId) !== 'product') {
            return ['ok' => false, 'action_name' => 'Apply Ovoko technical attributes to Woo product', 'reason' => 'invalid_product_id', 'product_id' => $productId];
        }
        $partId = (int) get_post_meta($productId, '_ovoko_part_id', true);
        if ($partId <= 0) {
            return ['ok' => false, 'action_name' => 'Apply Ovoko technical attributes to Woo product', 'reason' => 'missing_ovoko_part_id', 'product_id' => $productId];
        }
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $normalized = $client->normalize_rrr_single_part_payload((array) ($result['payload'] ?? []));

        $manufacturerCode = (string) ($normalized['manufacturer_code'] ?? '');
        update_post_meta($productId, '_ovoko_manufacturer_code', $manufacturerCode);
        update_post_meta($productId, '_mpn', $manufacturerCode);
        update_post_meta($productId, 'mpn', $manufacturerCode);
        update_post_meta($productId, '_manufacturer_code', $manufacturerCode);
        update_post_meta($productId, '_gpswiss_part_number', $manufacturerCode);
        update_post_meta($productId, '_part_number', $manufacturerCode);

        $attrs = $this->build_ovoko_technical_attributes_from_normalized($normalized);
        $this->upsert_custom_product_attributes($productId, $attrs);

        return ['ok' => true, 'action_name' => 'Apply Ovoko technical attributes to Woo product', 'product_id' => $productId, 'part_id' => $partId, 'saved_meta' => ['_ovoko_manufacturer_code','_mpn','mpn','_manufacturer_code','_gpswiss_part_number','_part_number'], 'saved_attributes' => $attrs, 'price_unchanged' => true, 'stock_unchanged' => true, 'no_ebay_publish' => true, 'no_allegro_publish' => true];
    }

    public function preview_frontend_part_number_mapping(int $productId): array
    {
        $productId = max(1, $productId);
        $expectedMetaKey = '_part_number';
        if (get_post_type($productId) !== 'product') {
            return ['ok' => false, 'action_name' => 'Frontend part number mapping', 'product_id' => $productId, 'reason' => 'invalid_product_id', 'expected_frontend_meta_key' => $expectedMetaKey];
        }

        $currentValue = sanitize_text_field((string) get_post_meta($productId, $expectedMetaKey, true));
        $ovokoManufacturerCode = sanitize_text_field((string) get_post_meta($productId, '_ovoko_manufacturer_code', true));
        $wouldWrite = $ovokoManufacturerCode;
        $frontendShouldShow = $currentValue !== '' ? $currentValue : ($wouldWrite !== '' ? $wouldWrite : 'Brak');

        return [
            'ok' => true,
            'action_name' => 'Frontend part number mapping',
            'product_id' => $productId,
            'expected_frontend_meta_key' => $expectedMetaKey,
            'current_value' => $currentValue,
            'ovoko_manufacturer_code' => $ovokoManufacturerCode,
            'would_write_value' => $wouldWrite,
            'frontend_should_show' => $frontendShouldShow,
        ];
    }

    public function apply_frontend_part_number_mapping(int $productId): array
    {
        $productId = max(1, $productId);
        $expectedMetaKey = '_part_number';
        if (get_post_type($productId) !== 'product') {
            return ['ok' => false, 'action_name' => 'Apply frontend part number mapping', 'reason' => 'invalid_product_id', 'product_id' => $productId];
        }

        $manufacturerCode = sanitize_text_field((string) get_post_meta($productId, '_ovoko_manufacturer_code', true));
        if ($manufacturerCode === '') {
            return ['ok' => false, 'action_name' => 'Apply frontend part number mapping', 'reason' => 'missing_ovoko_manufacturer_code', 'product_id' => $productId];
        }

        update_post_meta($productId, $expectedMetaKey, $manufacturerCode);
        return [
            'ok' => true,
            'action_name' => 'Apply frontend part number mapping',
            'product_id' => $productId,
            'expected_frontend_meta_key' => $expectedMetaKey,
            'written_value' => $manufacturerCode,
            'price_unchanged' => true,
            'stock_unchanged' => true,
            'publication_unchanged' => true,
            'images_unchanged' => true,
            'no_ebay_publish' => true,
            'no_allegro_publish' => true,
        ];
    }

    public function preview_rrr_car_details(int $carId = 458): array
    {
        $carId = max(1, $carId);
        $client = new RrrApiClient($this->get_settings());
        $preview = $client->preview_fetch_car_by_id((string) $carId);

        return [
            'ok' => !empty($preview['ok']),
            'mode' => 'preview_only',
            'action_name' => 'Preview RRR car details',
            'car_id' => $carId,
            'endpoint_confirmed' => !empty($preview['endpoint_confirmed']),
            'endpoint_used' => (string) ($preview['endpoint_used'] ?? ''),
            'details' => (array) ($preview['normalized'] ?? []),
            'vehicle_data_status' => !empty($preview['ok']) ? 'vehicle_data_rrr' : 'car_id_only',
            'probe' => (array) ($preview['probe'] ?? []),
        ];
    }

    public function probe_rrr_vehicle_endpoints(int $carId = 458): array
    {
        $client = new RrrApiClient($this->get_settings());
        $vehicleProbe = $client->probe_vehicle_endpoints($carId);
        $dictProbe = $client->probe_vehicle_dictionary_endpoints($carId);
        return ['ok'=>true,'mode'=>'preview_only','action_name'=>'Probe RRR vehicle endpoints','vehicle_probe'=>$vehicleProbe,'dictionary_probe'=>$dictProbe];
    }

    public function probe_rrr_part_search_by_code(string $partNumber): array
    {
        $client = new RrrApiClient($this->get_settings());
        return $client->probe_part_search_by_code($partNumber);
    }

    public function preview_paginated_rrr_part_code_lookup(string $partNumber, int $maxPages = 3, int $limit = 100): array
    {
        $partNumber = trim($partNumber);
        if ($partNumber === '') {
            return ['ok' => false, 'action_name' => 'Preview paginated RRR part code lookup', 'reason' => 'missing_part_number'];
        }
        $maxPages = max(1, min(10, $maxPages));
        $limit = max(1, min(100, $limit));
        $client = new RrrApiClient($this->get_settings());
        $pagesScanned = 0;
        $recordsScanned = 0;
        $totalCount = null;
        $matches = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $sample = $client->preview_fetch_parts_sample($limit, $page);
            $pagesScanned++;
            $rows = (array) ($sample['records'] ?? []);
            $recordsScanned += count($rows);
            if ($totalCount === null && isset($sample['pagination']['total_count'])) {
                $totalCount = (int) $sample['pagination']['total_count'];
            }
            foreach ($rows as $row) {
                $raw = (array) ($row ?? []);
                foreach (['manufacturer_code', 'visible_code', 'other_code', 'external_id'] as $field) {
                    if (strcasecmp((string) ($raw[$field] ?? ''), $partNumber) === 0) {
                        $matches[] = ['id' => (string) ($raw['id'] ?? ''), 'field' => $field, 'value' => (string) $raw[$field]];
                    }
                }
            }
            if (count($rows) < $limit) {
                break;
            }
        }
        return ['ok' => true, 'mode' => 'preview_only', 'action_name' => 'Preview paginated RRR part code lookup', 'part_number' => $partNumber, 'limit' => $limit, 'max_pages' => $maxPages, 'pages_scanned' => $pagesScanned, 'records_scanned' => $recordsScanned, 'total_count' => $totalCount, 'exact_matches' => $matches, 'stopped_reason' => $pagesScanned >= $maxPages ? 'max_pages_reached' : 'last_page_or_short_page'];
    }

    public function preview_ovoko_title_with_vehicle_data(int $partId = 60271): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $normalized = $client->normalize_rrr_single_part_payload((array) ($result['payload'] ?? []));
        $title = $this->build_woo_product_title_from_rrr_part($normalized);
        return ['ok' => !empty($result['ok']), 'mode' => 'preview_only', 'action_name' => 'Preview Ovoko title with vehicle data', 'part_id' => $partId, 'car_id' => (string) ($normalized['car_id'] ?? ''), 'fallback_title' => (string) ($title['proposed_title_fallback'] ?? ''), 'vehicle_title_prefix' => (string) ($title['vehicle_title_prefix'] ?? ''), 'proposed_full_title' => (string) ($title['proposed_title'] ?? ''), 'title_review_required' => (string) ($title['_ovoko_title_review_required'] ?? 'yes'), 'missing_vehicle_fields' => (array) ($title['missing_vehicle_fields'] ?? []), 'vehicle_data_source' => (string) ($title['_ovoko_title_source'] ?? ''), 'endpoint_used' => (string) ($title['endpoint_used'] ?? '')];
    }

    public function apply_rrr_vehicle_data_to_ovoko_product(int $productId, bool $updateTitle = false): array
    {
        $carId = (string) get_post_meta($productId, '_ovoko_car_id', true);
        $oldTitle = (string) get_the_title($productId);
        $client = new RrrApiClient($this->get_settings());
        $vehicle = $client->preview_fetch_car_by_id($carId);
        $metaWritten = 0; $attributesWritten = 0; $titleUpdated = false; $proposedTitle = $oldTitle;
        if (!empty($vehicle['ok'])) {
            $v=(array)$vehicle['normalized'];
            $map=['_ovoko_vehicle_make'=>'vehicle_make','_ovoko_vehicle_make_short'=>'vehicle_make_short','_ovoko_vehicle_model'=>'vehicle_model','_ovoko_vehicle_generation'=>'vehicle_generation','_ovoko_vehicle_engine_marketing'=>'vehicle_engine_marketing','_ovoko_vehicle_year'=>'vehicle_year','_ovoko_vehicle_period'=>'vehicle_period','_ovoko_vehicle_fuel'=>'vehicle_fuel','_ovoko_vehicle_engine_capacity_cc'=>'vehicle_engine_capacity_cc','_ovoko_vehicle_engine_capacity_l'=>'vehicle_engine_capacity_l','_ovoko_vehicle_engine_power_kw'=>'vehicle_engine_power_kw','_ovoko_engine_code'=>'vehicle_engine_code','_ovoko_gearbox_type'=>'vehicle_gearbox_type','_ovoko_vehicle_body_type'=>'vehicle_body_type','_ovoko_vehicle_drive_wheels'=>'vehicle_drive_wheels','_ovoko_vehicle_steering_position'=>'vehicle_steering_position','_ovoko_vehicle_color'=>'vehicle_color','_ovoko_vehicle_color_code'=>'vehicle_color_code','_ovoko_vehicle_dictionary_resolution_status'=>'vehicle_dictionary_resolution_status','_ovoko_vehicle_dictionary_resolution_source'=>'vehicle_dictionary_resolution_source'];
            foreach($map as $k=>$f){ update_post_meta($productId,$k,(string)($v[$f]??'')); $metaWritten++; }
            $this->upsert_custom_product_attributes($productId,array_filter(['Producent'=>$v['vehicle_make']??'','Model'=>$v['vehicle_model']??'','Modyfikacja'=>$v['vehicle_generation']??'','Rodzaj paliwa'=>$v['vehicle_fuel']??'','Pojemność silnika'=>!empty($v['vehicle_engine_capacity_cc']) ? ((string)$v['vehicle_engine_capacity_cc']).' cm³' : '','Moc silnika'=>!empty($v['vehicle_engine_power_kw']) ? ((string)$v['vehicle_engine_power_kw']).' kW' : '','Kod silnika'=>$v['vehicle_engine_code']??'','Typ skrzyni biegów'=>$v['vehicle_gearbox_type']??'','Typ sylwetki'=>$v['vehicle_body_type']??'','Koła napędowe'=>$v['vehicle_drive_wheels']??'','Pozycja kierownicy'=>$v['vehicle_steering_position']??'','Kolor'=>$v['vehicle_color']??'','Kod koloru'=>$v['vehicle_color_code']??'','Okres'=>$v['vehicle_period']??'','Rok produkcji samochodu'=>$v['vehicle_year']??'']));
            $attributesWritten=15;
            if($updateTitle){ $normalizedForTitle=['notes'=>(string)get_post_field('post_excerpt',$productId),'manufacturer_code'=>(string)get_post_meta($productId,'_ovoko_manufacturer_code',true)]+$v; $titleData=$this->build_woo_product_title_from_rrr_part($normalizedForTitle); $proposedTitle=(string)($titleData['proposed_title']??$oldTitle); wp_update_post(['ID'=>$productId,'post_title'=>$proposedTitle]); $titleUpdated=true; }
        }
        return ['ok'=>true,'action_name'=>'Apply RRR vehicle data to Ovoko product','product_id'=>$productId,'car_id'=>$carId,'vehicle_data_found'=>!empty($vehicle['ok']),'endpoint_used'=>(string)($vehicle['endpoint_used']??''),'vehicle_meta_written'=>$metaWritten,'vehicle_attributes_written'=>$attributesWritten,'old_title'=>$oldTitle,'proposed_title'=>$proposedTitle,'title_updated'=>$titleUpdated,'no_price_change'=>true,'no_stock_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true];
    }


    public function preview_allegro_to_ovoko_match(int $productId, array $options = []): array
    {
        $productId = max(1, $productId);
        if (get_post_type($productId) !== 'product') {
            return ['ok' => false, 'action_name' => 'Preview Allegro to Ovoko match', 'product_id' => $productId, 'reason' => 'invalid_product_id'];
        }
        $audit = $this->collect_allegro_product_context($productId);
        $match = $this->resolve_ovoko_match_for_allegro_product($productId, $audit, $options);
        return [
            'ok' => true,
            'action_name' => 'Preview Allegro to Ovoko match',
            'product_id' => $productId,
            'is_allegro_product' => $audit['is_allegro_product'],
            'allegro_offer_id' => $audit['allegro_offer_id'],
            'detected_allegro_meta_keys' => $audit['detected_allegro_meta_keys'],
            'current_part_number' => $audit['current_part_number'],
            'current_ovoko_part_id' => $audit['current_ovoko_part_id'],
            'current_ovoko_car_id' => $audit['current_ovoko_car_id'],
            'post_content_preview' => $audit['post_content_preview'],
            'old_description_would_be_hidden_or_replaced' => true,
            'source_data_available' => !empty($match['part_normalized']),
            'match' => $match,
            'no_write_to_woo' => true,'no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true,
        ];
    }

    public function apply_allegro_to_ovoko_details(int $productId, bool $replaceDescription = false, array $options = []): array
    {
        $detailsOnly = !empty($options['details_only']);
        $preview = $this->preview_allegro_to_ovoko_match($productId, ['details_only' => $detailsOnly, 'minimal_response' => true, 'disable_debug_heavy_logs' => true]);
        if (empty($preview['is_allegro_product'])) return ['ok'=>false,'action_name'=>'Apply Allegro to Ovoko details enrichment','reason'=>'not_allegro_product','product_id'=>$productId];
        $match = (array) ($preview['match'] ?? []);
        $guardStatus = $this->build_apply_guard_status();
        if (empty($guardStatus['apply_allowed'])) {
            return ['ok'=>false,'action_name'=>'Apply Allegro to Ovoko details enrichment','reason'=>(string) ($guardStatus['apply_blocked_reason'] ?: 'memory_guard_before_write'),'product_id'=>$productId,'memory_usage_mb'=>(float) ($guardStatus['before_write_mb'] ?? 0),'memory_limit'=>(string) ($guardStatus['memory_limit'] ?? ini_get('memory_limit')),'memory_limit_mb'=>(int) ($guardStatus['memory_limit_mb'] ?? $this->memory_limit_mb()),'memory_usage_ratio_before_write'=>(float) ($guardStatus['memory_usage_ratio_before_write'] ?? 0),'apply_allowed'=>false,'apply_blocked_reason'=>(string) ($guardStatus['apply_blocked_reason'] ?? 'memory_guard_before_write'),'recommended_memory_limit'=>$guardStatus['recommended_memory_limit'] ?? null];
        }
        if (($match['match_confidence'] ?? '') !== 'high' && ($match['match_confidence'] ?? '') !== 'high_existing_ovoko_part_id') return ['ok'=>false,'action_name'=>'Apply Allegro to Ovoko details enrichment','reason'=>'match_not_high','product_id'=>$productId,'match_confidence'=>$match['match_confidence'] ?? 'unknown_low_coverage'];
        $normalized = (array) ($match['part_normalized'] ?? []);
        $carId = (string) ($normalized['car_id'] ?? '');
        $writtenMeta=[];
        if($carId!==''){ update_post_meta($productId,'_ovoko_car_id',$carId); $writtenMeta[]='_ovoko_car_id'; }
        if (!empty($normalized['part_id'])) { update_post_meta($productId,'_ovoko_part_id',(string)$normalized['part_id']); $writtenMeta[]='_ovoko_part_id'; }
        $pn=(string)($normalized['manufacturer_code']??''); if($pn!=='' && (string)get_post_meta($productId,'_part_number',true)===''){ update_post_meta($productId,'_part_number',$pn); $writtenMeta[]='_part_number';}
        $enriched = $this->build_enriched_normalized_for_apply($normalized);
        $rawAttrs = $this->build_ovoko_technical_attributes_from_normalized($enriched);
        $filterDebug = [];
        $attrs = $this->filter_customer_facing_technical_attributes($rawAttrs, $filterDebug);
        $vehicleLabel = $this->build_vehicle_label_from_attributes($attrs);
        $vehicleSlug = $this->build_unique_vehicle_slug($productId, $carId, $vehicleLabel, (string) ($attrs['Rok produkcji samochodu'] ?? ''));
        if ($vehicleLabel !== '') { update_post_meta($productId, '_gpswiss_vehicle_label', $vehicleLabel); $writtenMeta[] = '_gpswiss_vehicle_label'; }
        if ($vehicleSlug !== '') { update_post_meta($productId, '_gpswiss_vehicle_slug', $vehicleSlug); $writtenMeta[] = '_gpswiss_vehicle_slug'; }
        $this->upsert_custom_product_attributes($productId,$attrs);
        $metaWrittenForTable = $this->write_ovoko_table_meta_from_attributes($productId, $attrs);
        $replaced=false;
        if($replaceDescription){
            wp_update_post(['ID'=>$productId,'post_content'=>'']);
            $replaced=true;
        }
        $previewTable = $this->preview_product_details_table_render_status($productId);
        return ['ok'=>true,'action_name'=>'Apply Allegro to Ovoko details enrichment','product_id'=>$productId,'details_only'=>$detailsOnly,'matched_ovoko_part_id'=>(string)($normalized['part_id']??''),'matched_ovoko_car_id'=>$carId,'car_id_confirmed'=>$carId!=='','vehicle_label'=>$vehicleLabel,'vehicle_slug'=>$vehicleSlug,'vehicle_parts_url'=>$vehicleSlug !== '' ? home_url('/czesci-z-pojazdu/' . $vehicleSlug . '/') : '','old_parts_url'=>$carId!=='' ? add_query_arg('ovoko_car_id', rawurlencode($carId), get_post_type_archive_link('product')) : '','vehicle_meta_written'=>$writtenMeta,'attributes_written'=>array_keys($attrs),'meta_written_for_table'=>$metaWrittenForTable,'details_style_enabled_after_apply'=>!empty($previewTable['product_details_style_enabled']),'table_rows_after_apply'=>$previewTable['table_rows_that_would_render'] ?? [],'skipped_fields'=>$filterDebug,'debug'=>['normalized_input'=>$normalized,'normalized_enriched'=>$enriched,'raw_attributes'=>$rawAttrs,'source_used'=>['Producent'=>(string)(($enriched['_vehicle_field_sources']['vehicle_make'] ?? 'unknown')),'Model'=>(string)(($enriched['_vehicle_field_sources']['vehicle_model'] ?? 'unknown')),'Modyfikacja'=>(string)(($enriched['_vehicle_field_sources']['vehicle_generation'] ?? 'unknown')),'Okres'=>(string)(($enriched['_vehicle_field_sources']['vehicle_period'] ?? 'unknown')),'Przebieg'=>(string)(($enriched['_vehicle_field_sources']['mileage_km'] ?? 'unknown')),'Typ sylwetki'=>(string)(($enriched['_vehicle_field_sources']['vehicle_body_type'] ?? 'unknown'))],'car_raw_selected_fields'=>(array)($enriched['_car_raw_selected_fields'] ?? []),'dictionary_local_mapping'=>['status'=>(string)($enriched['vehicle_dictionary_resolution_status'] ?? ''),'source'=>(string)($enriched['vehicle_dictionary_resolution_source'] ?? ''),'hits'=>array_intersect_key($enriched,array_flip(['vehicle_make','vehicle_model','vehicle_generation','vehicle_fuel','vehicle_gearbox_type','vehicle_body_type','vehicle_drive_wheels','vehicle_color','vehicle_period','vehicle_year','mileage_km']))]],'raw_replace_description_input'=>$options['raw_replace_description_input'] ?? null,'parsed_replace_description'=>$replaceDescription,'old_description_replaced'=>$replaced,'description_policy'=>$replaced?'clear_post_content_for_ovoko_style_tabs':'keep_existing_post_content','no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true] + $guardStatus;
    }

    public function preview_product_details_table_render_status(int $productId): array
    {
        $source = sanitize_text_field((string) get_post_meta($productId, 'source', true));
        $partId = sanitize_text_field((string) get_post_meta($productId, '_ovoko_part_id', true));
        $carId = sanitize_text_field((string) get_post_meta($productId, '_ovoko_car_id', true));
        $attrs = (array) get_post_meta($productId, '_product_attributes', true);
        $labels = [];
        foreach ($attrs as $a) { if (!empty($a['is_visible'])) { $labels[] = (string) ($a['name'] ?? ''); } }
        $tableRows = $this->get_product_details_table_rows($productId);
        $enabled = $source === 'ovoko_master' || $partId !== '' || $carId !== '' || !empty($tableRows);
        $reason = $partId !== '' ? '_ovoko_part_id' : ($carId !== '' ? '_ovoko_car_id' : (!empty($tableRows) ? 'customer_visible_attributes' : 'none'));
        return ['ok'=>true,'action_name'=>'Preview product details table render status','product_id'=>$productId,'product_details_style_enabled'=>$enabled,'detection_reason'=>$reason,'_ovoko_part_id'=>$partId,'_ovoko_car_id'=>$carId,'source'=>$source,'source_meta'=>$source,'custom_attributes_found'=>!empty($attrs),'custom_attributes_labels'=>$labels,'customer_visible_details_found'=>!empty($tableRows),'table_rows_that_would_render'=>$tableRows,'reason_if_table_empty'=>empty($tableRows)?'no_whitelisted_meta_or_visible_attributes':'','no_write_to_woo'=>true];
    }

    private function collect_allegro_product_context(int $productId, bool $minimal = false): array
    {
        $keys=['_allegro_offer_id','allegro_offer_id','_awi_offer_id','_awi_source','source']; $det=[]; $offer='';
        foreach($keys as $k){$v=sanitize_text_field((string)get_post_meta($productId,$k,true)); if($v!==''){ $det[$k]=$v; if($offer===''&&str_contains($k,'offer_id'))$offer=$v;}}
        $source=strtolower((string)($det['_awi_source']??$det['source']??''));
        $isAllegro=$offer!==''||str_contains($source,'allegro');
        return ['is_allegro_product'=>$isAllegro,'allegro_offer_id'=>$offer,'detected_allegro_meta_keys'=>$det,'current_part_number'=>sanitize_text_field((string)get_post_meta($productId,'_part_number',true)),'current_ovoko_part_id'=>sanitize_text_field((string)get_post_meta($productId,'_ovoko_part_id',true)),'current_ovoko_car_id'=>sanitize_text_field((string)get_post_meta($productId,'_ovoko_car_id',true)),'post_content_preview'=>$minimal ? '' : mb_substr(wp_strip_all_tags((string)get_post_field('post_content',$productId)),0,220)];
    }

    public function export_missing_ovoko_id_report_csv(): void
    {
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }

            while (ob_get_level()) {
                ob_end_clean();
            }

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="woo_products_missing_ovoko_id.csv"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $out = fopen('php://output', 'wb');
            if ($out === false) {
                wp_die('Cannot open CSV output stream.');
            }

            $summary = $this->stream_missing_ovoko_id_report_rows($out);

            fputcsv($out, []);
            foreach ((array) $summary as $key => $value) {
                fputcsv($out, [(string) $key, (string) $value]);
            }

            fclose($out);
            exit;
        } catch (\Throwable $e) {
            wp_die('Export failed: ' . esc_html($e->getMessage()));
        }
    }

    private function stream_missing_ovoko_id_report_rows($out): array
    {
        $batchSize = max(1, (int) ($_GET['batch_size'] ?? 50));
        $startAfterProductId = max(0, (int) ($_GET['start_after_product_id'] ?? 0));
        $isBatchOnly = !empty($_GET['batch_only']) || !empty($_GET['missing_ovoko_batch_only']);
        $maxToProcess = max(0, (int) ($_GET['limit'] ?? ($isBatchOnly ? 500 : 0)));
        $processedInRequest = 0;

        $settings = $this->get_settings();
        $csvMap = (array) ($settings['ovoko_csv_mapping'] ?? []);
        $summary = ['total_products_checked' => 0, 'products_with_ovoko_id' => 0, 'products_missing_ovoko_id' => 0, 'missing_allegro_id_count' => 0, 'no_csv_match_count' => 0, 'not_allegro_product_count' => 0, 'ovoko_id_conflict_count' => 0, 'api_error_count' => 0, 'unknown_count' => 0];
        $lastId = $startAfterProductId;

        fputcsv($out, ['product_id', 'sku', 'title', 'status', 'allegro_id', 'ovoko_id', '_ovoko_part_id', 'reason', 'last_sync_action', 'last_sync_error', 'last_sync_skip_reason']);

        global $wpdb;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_status FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
                'product',
                $lastId,
                $batchSize
            ), ARRAY_A);

            $rows = (array) $rows;
            if (empty($rows)) {
                break;
            }

            error_log(sprintf('[gpswiss_ovoko_export_missing_batch_start] batch_start_last_id=%d batch_count=%d processed_total=%d mem=%d peak=%d', $lastId, count($rows), $summary['total_products_checked'], memory_get_usage(true), memory_get_peak_usage(true)));

            foreach ($rows as $row) {
                $productId = (int) ($row['ID'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }
                $lastId = $productId;
                $summary['total_products_checked']++;
                $processedInRequest++;

                $ovokoId = $this->read_post_meta_single_sql($productId, 'ovoko_id');
                $ovokoPartId = $this->read_post_meta_single_sql($productId, '_ovoko_part_id');
                if ($ovokoId !== '' || $ovokoPartId !== '') {
                    $summary['products_with_ovoko_id']++;
                    $this->clear_product_caches($productId);
                    continue;
                }
                $summary['products_missing_ovoko_id']++;

                $allegroId = $this->read_first_meta_value_sql($productId, ['_allegro_offer_id', 'allegro_offer_id', '_awi_offer_id']);
                $awiSource = strtolower($this->read_first_meta_value_sql($productId, ['_awi_source', 'source']));

                $lastAction = $this->read_first_meta_value_sql($productId, ['last_sync_action']);
                $lastError = $this->read_first_meta_value_sql($productId, ['last_sync_error']);
                $lastSkip = $this->read_first_meta_value_sql($productId, ['last_sync_skip_reason']);

                $reason = 'unknown';
                if ($allegroId === '') {
                    $reason = 'missing_allegro_id';
                } elseif (str_contains($awiSource, 'allegro') === false && str_contains($lastSkip, 'not_allegro_product')) {
                    $reason = 'not_allegro_product';
                } elseif (str_contains($lastSkip, 'not_allegro_product')) {
                    $reason = 'not_allegro_product';
                } elseif (str_contains($lastSkip, 'ovoko_id_conflict')) {
                    $reason = 'ovoko_id_conflict';
                } elseif (str_contains($lastSkip, 'api_error') || $lastError !== '') {
                    $reason = 'api_error';
                } else {
                    $csvEntries = (array) ($csvMap[strtolower($allegroId)] ?? []);
                    if (empty($csvEntries)) {
                        $reason = 'no_csv_match';
                    }
                }

                $summary[$reason . '_count'] = (int) ($summary[$reason . '_count'] ?? 0) + 1;

                fputcsv($out, [
                    (string) $productId,
                    $this->read_post_meta_single_sql($productId, '_sku'),
                    sanitize_text_field((string) ($row['post_title'] ?? '')),
                    sanitize_text_field((string) ($row['post_status'] ?? '')),
                    $allegroId,
                    $ovokoId,
                    $ovokoPartId,
                    $reason,
                    $lastAction,
                    $lastError,
                    $lastSkip,
                ]);

                $this->clear_product_caches($productId);

                if ($processedInRequest % 500 === 0) {
                    error_log(sprintf('[gpswiss_ovoko_export_missing] processed=%d missing_count=%d last_id=%d mem=%d peak=%d', $summary['total_products_checked'], $summary['products_missing_ovoko_id'], $lastId, memory_get_usage(true), memory_get_peak_usage(true)));
                }

                if ($maxToProcess > 0 && $processedInRequest >= $maxToProcess) {
                    $summary['partial_export'] = 1;
                    $summary['next_start_after_product_id'] = $lastId;
                    break 2;
                }
            }

            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
            unset($rows);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            error_log(sprintf('[gpswiss_ovoko_export_missing_batch_end] batch_start_last_id=%d batch_count=%d processed_total=%d mem=%d peak=%d', $lastId, $batchSize, $summary['total_products_checked'], memory_get_usage(true), memory_get_peak_usage(true)));
        } while (true);

        $summary['export_start_after_product_id'] = $startAfterProductId;
        $summary['export_limit'] = $maxToProcess;
        $summary['last_processed_product_id'] = $lastId;
        return $summary;
    }

    private function read_post_meta_single_sql(int $productId, string $metaKey): string
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $productId,
            $metaKey
        ));

        return sanitize_text_field((string) $value);
    }

    private function read_first_meta_value_sql(int $productId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->read_post_meta_single_sql($productId, (string) $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function clear_product_caches(int $productId, array $metaKeysToClear = []): void
    {
        clean_post_cache($productId);
        wp_cache_delete($productId, 'post_meta');

        foreach ($metaKeysToClear as $metaKey) {
            wp_cache_delete($productId . ':' . $metaKey, 'post_meta');
        }
    }

    private function read_first_meta_value(int $productId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = sanitize_text_field((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function resolve_ovoko_match_for_allegro_product(int $productId, array $audit, array $options = []): array
    {
        $detailsOnly = !empty($options['details_only']);
        $client = new RrrApiClient($this->get_settings());
        $partId = (int) ($audit['current_ovoko_part_id'] ?? 0);
        if ($partId > 0) { $single=$client->preview_fetch_single_part($partId); $norm=$client->normalize_rrr_single_part_payload((array)($single['payload']??[]), ['details_only' => $detailsOnly]); return ['match_confidence'=>!empty($single['ok'])?'high_existing_ovoko_part_id':'none','review_required'=>empty($single['ok']),'part_normalized'=>$norm,'identifiers_used'=>['_ovoko_part_id'],'rrr_endpoint'=>'/get/part/{id}']; }
        $candidate=''; foreach(['_part_number','_mpn','mpn','_manufacturer_code','_gpswiss_part_number'] as $k){$v=sanitize_text_field((string)get_post_meta($productId,$k,true)); if($v!==''){ $candidate=$v; break; }}
        $csvMap=(array)($this->get_settings()['ovoko_csv_mapping']??[]); $normalizedCandidate=$this->normalize_part_code($candidate);
        if($normalizedCandidate!=='' && !empty($csvMap)){
            $csvCandidates=(array)($csvMap[$normalizedCandidate]??[]);
            if(count($csvCandidates)===1){
                $csvRow=(array)$csvCandidates[0]; $norm=$this->build_normalized_from_csv_row($csvRow); $apiWarning='';
                if(!empty($csvRow['id'])){ $single=$client->preview_fetch_single_part((int)$csvRow['id']); if(!empty($single['ok'])){ $apiNorm=$client->normalize_rrr_single_part_payload((array)($single['payload']??[]), ['details_only' => $detailsOnly]); if((string)($apiNorm['car_id']??'')!==''){ $norm['car_id']=(string)$apiNorm['car_id']; } } else { $apiWarning='same-vehicle grouping requires car_id confirmation from API'; } }
                return ['match_confidence'=>'high','review_required'=>false,'matched_by'=>'csv_exact_code','matched_ovoko_part_id'=>(string)($csvRow['id']??''),'part_number_candidate'=>$candidate,'part_normalized'=>$norm,'api_confirmation_warning'=>$apiWarning];
            }
            if(count($csvCandidates)>1){
                return ['match_confidence'=>'ambiguous','review_required'=>true,'matched_by'=>'ambiguous_csv_duplicate_code','part_number_candidate'=>$candidate,'candidates'=>array_map(static fn($r)=>['id'=>(string)($r['id']??''),'part_name'=>(string)($r['part_name']??''),'vehicle_info'=>(string)($r['vehicle_info']??''),'year'=>(string)($r['year']??''),'color'=>(string)($r['color']??'')],$csvCandidates),'part_normalized'=>[]];
            }
        }
        if ($candidate !== '') {
            $search = $client->preview_search_part_by_code($candidate, 10, 1);
            $records = (array) ($search['records'] ?? []);
            $totalCount = (int) (($search['pagination']['total_count'] ?? 0));
            $recordsCount = (int) ($search['records_count'] ?? count($records));
            $statusCode = (string) ($search['status_code'] ?? '');
            $exactAllowed = ['manufacturer_code', 'visible_code', 'other_code', 'external_id'];
            $exactField = '';
            $matchedRecord = [];
            if ($recordsCount === 1 && !empty($records[0]) && is_array($records[0])) {
                $row = (array) $records[0];
                foreach ($exactAllowed as $field) {
                    $value = isset($row[$field]) ? (string) $row[$field] : '';
                    if ($value !== '' && strcasecmp($value, $candidate) === 0) {
                        $exactField = $field;
                        $matchedRecord = $row;
                        break;
                    }
                }
            }
            $isHigh = $statusCode === 'R200' && $totalCount === 1 && $recordsCount === 1 && $exactField !== '' && !empty($matchedRecord['id']);
            if ($isHigh) {
                $single = $client->preview_fetch_single_part((int) $matchedRecord['id']);
                $norm = $client->normalize_rrr_single_part_payload((array) ($single['payload'] ?? []), ['details_only' => $detailsOnly]);
                $carId = (string) ($norm['car_id'] ?? '');
                return [
                    'match_confidence' => 'high',
                    'review_required' => false,
                    'matched_by' => 'api_search_exact_code',
                    'exact_match_field' => $exactField,
                    'matched_ovoko_part_id' => sanitize_text_field((string) ($matchedRecord['id'] ?? '')),
                    'matched_ovoko_name' => sanitize_text_field((string) ($matchedRecord['name'] ?? '')),
                    'part_number_candidate' => $candidate,
                    'search_endpoint' => (string) ($search['path'] ?? ''),
                    'part_normalized' => $norm,
                    'proposed_enrichment' => [
                        'proposed_ovoko_part_id' => sanitize_text_field((string) ($matchedRecord['id'] ?? '')),
                        'proposed_ovoko_car_id' => $carId,
                        'proposed_visible_attributes' => array_keys($this->build_ovoko_technical_attributes_from_normalized($norm)),
                        'old_description_preview' => mb_substr(wp_strip_all_tags((string) get_post_field('post_content', $productId)), 0, 220),
                        'description_after_policy' => 'clear_post_content_for_ovoko_style_tabs',
                        'replace_description' => true,
                    ],
                    'car_data_fetch' => ['car_id' => $carId, 'car_fetch_attempted' => $carId !== ''],
                    'rrr_endpoint' => '/get/part/' . (int) ($matchedRecord['id'] ?? 0),
                ];
            }
            return ['match_confidence' => 'unknown_low_coverage', 'review_required' => true, 'identifiers_used' => ['_part_number/_mpn/mpn/_manufacturer_code/_gpswiss_part_number'], 'part_number_candidate' => $candidate, 'matches_found' => $recordsCount, 'part_normalized' => [], 'search_method' => 'rrr_search:' . (string) ($search['path'] ?? ''), 'coverage' => 'search_endpoint', 'searched_pages' => [1], 'searched_total_records' => $recordsCount, 'total_count' => $totalCount, 'reason' => 'Search endpoint returned non-single exact results.', 'rrr_endpoint' => (string) ($search['path'] ?? '')];
        }
        $sample=$client->preview_fetch_parts_sample(100,1); $rows=(array)($sample['records']??[]);
        return ['match_confidence'=>'unknown_low_coverage','review_required'=>true,'identifiers_used'=>['_part_number/_mpn/mpn/_manufacturer_code/_gpswiss_part_number'],'part_number_candidate'=>$candidate,'matches_found'=>'0_sample_only','part_normalized'=>[],'search_method'=>'sample_page_1_only','coverage'=>'sample_only','searched_pages'=>[1],'searched_total_records'=>count($rows),'total_count'=>$sample['pagination']['total_count'] ?? null,'reason'=>'Only first page sampled; cannot conclude no match.','rrr_endpoint'=>'/v2/get/parts?limit=100&page=1','diagnostics_readme_question'=>'Need confirmed search endpoint by part code to avoid low-coverage sampling.','next_recommended_action'=>'Run Probe RRR part search by code; if still unavailable run Preview paginated RRR part code lookup.'];
    }



    public function import_ovoko_csv_mapping(string $csvPath, string $uploadedTmpPath = '', string $uploadedName = ''): array
    {
        $sourcePath = $uploadedTmpPath !== '' ? $uploadedTmpPath : $csvPath;
        if ($sourcePath === '' || !is_readable($sourcePath)) return ['ok' => false, 'action_name' => 'Import Ovoko CSV mapping', 'reason' => 'csv_not_readable'];

        $delimiters = [';' => 'semicolon', ',' => 'comma', "	" => 'tab'];
        $probe = $this->detect_best_csv_delimiter_and_header($sourcePath, $delimiters);
        if (empty($probe['ok'])) return ['ok' => false, 'action_name' => 'Import Ovoko CSV mapping', 'reason' => 'invalid_header'];

        $detectedDelimiter = (string) ($probe['delimiter_label'] ?? 'unknown');
        $delimiter = (string) ($probe['delimiter'] ?? ';');
        $rawHeaders = (array) ($probe['raw_headers'] ?? []);
        $normalizedHeaders = (array) ($probe['normalized_headers'] ?? []);

        $ovokoIdAliases = ['ovoko_id', 'ovoko id', 'id', 'part id', 'part_id', 'id czesci', 'id części'];
        $allegroIdAliases = ['allegro_id', 'allegro id', 'offer_id', 'offer id', '_allegro_offer_id', 'allegro_offer_id', '_awi_offer_id'];

        $ovokoIdColumn = $this->find_header_by_aliases($normalizedHeaders, $ovokoIdAliases);
        $allegroIdColumn = $this->find_header_by_aliases($normalizedHeaders, $allegroIdAliases);

        $headerMap = [];
        foreach ($rawHeaders as $i => $rawHeader) {
            $headerMap[] = ['index' => $i, 'raw' => (string) $rawHeader, 'normalized' => (string) ($normalizedHeaders[$i] ?? '')];
        }

        $h = fopen($sourcePath, 'rb');
        if ($h === false) return ['ok' => false, 'action_name' => 'Import Ovoko CSV mapping', 'reason' => 'fopen_failed'];
        fgetcsv($h, 0, $delimiter);

        $rowsTotal = 0; $rowsWithAllegroId = 0; $rowsWithOvokoId = 0; $index = []; $duplicateRows = 0; $firstRowSafeSample = [];
        while (($row = fgetcsv($h, 0, $delimiter)) !== false) {
            $rowsTotal++;
            $assoc = [];
            foreach ($rawHeaders as $i => $k) { $assoc[(string) $k] = (string) ($row[$i] ?? ''); }
            if ($rowsTotal === 1) {
                $firstRowSafeSample = [
                    'ovoko_id' => sanitize_text_field((string)($ovokoIdColumn !== null ? ($row[$ovokoIdColumn] ?? '') : '')),
                    'allegro_id' => sanitize_text_field((string)($allegroIdColumn !== null ? ($row[$allegroIdColumn] ?? '') : '')),
                    'part_name' => sanitize_text_field((string)($this->value_from_assoc_by_normalized_header($assoc, $normalizedHeaders, ['nazwa czesci']) ?? '')),
                ];
            }
            if ($allegroIdColumn === null || $ovokoIdColumn === null) continue;
            $allegroId = sanitize_text_field((string) ($row[$allegroIdColumn] ?? ''));
            $ovokoId = sanitize_text_field((string) ($row[$ovokoIdColumn] ?? ''));
            if ($allegroId === '') continue;
            $rowsWithAllegroId++;
            if ($ovokoId === '') continue;
            $rowsWithOvokoId++;
            $entry = ['id' => $ovokoId, 'ovoko_id' => $ovokoId, 'allegro_id' => $allegroId];
            $index[strtolower($allegroId)][] = $entry;
        }
        fclose($h);

        $uniqueCodes=count($index); $duplicateCodes=0;
        foreach($index as $list){ if(count($list)>1){$duplicateCodes++; $duplicateRows+=count($list);} }

        $status=[
            'rows_total'=>$rowsTotal,
            'rows_with_allegro_id'=>$rowsWithAllegroId,
            'rows_with_ovoko_id'=>$rowsWithOvokoId,
            'unique_allegro_ids'=>$uniqueCodes,
            'duplicate_allegro_ids_count'=>$duplicateCodes,
            'duplicate_rows_count'=>$duplicateRows,
            'imported_at'=>gmdate('c'),
            'file_name'=>$uploadedName!==''?$uploadedName:basename($csvPath),
            'file_hash'=>hash_file('sha256',$sourcePath),
            'detected_delimiter'=>$detectedDelimiter,
            'raw_headers'=>$rawHeaders,
            'normalized_headers'=>$normalizedHeaders,
            'header_map'=>$headerMap,
            'first_row_safe_sample'=>$firstRowSafeSample,
            'allegro_id_column_found'=>$allegroIdColumn !== null,
            'allegro_id_column_name'=>$allegroIdColumn !== null ? (string) ($rawHeaders[$allegroIdColumn] ?? '') : '',
            'ovoko_id_column_found'=>$ovokoIdColumn !== null,
            'ovoko_id_column_name'=>$ovokoIdColumn !== null ? (string) ($rawHeaders[$ovokoIdColumn] ?? '') : '',
        ];

        $settings=$this->get_settings();
        $settings['ovoko_csv_mapping']=$index; $settings['ovoko_csv_mapping_status']=$status; update_option(self::OPTION_KEY,$settings,false);
        delete_transient(self::CSV_MAPPING_CACHE_TRANSIENT);
        return ['ok'=>true,'action_name'=>'Import Ovoko CSV mapping','status'=>$status];
    }

    public function bulk_allegro_to_ovoko_details_enrichment(array $options = []): array
    {
        $started = microtime(true);
        $minimalResponse = true;
        $stages = [['stage' => 'handler_started', 'elapsed_ms' => 0, 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)]];
        $dryRun = array_key_exists('dry_run', $options) ? !empty($options['dry_run']) : true;
        $matchOnly = false;
        $guard = $this->check_memory_guard_before_enrichment(110);
        if (!empty($guard)) {
            return $guard;
        }
        $replaceDescription = !empty($options['replace_description']);
        $limitMb = $this->memory_limit_mb();
        $maxItemsPerRequest = $limitMb >= 256 ? 3 : 2;
        $maxRuntimeSeconds = 18;
        $batchSize = max(1, min((int) ($options['batch_size'] ?? 1), $maxItemsPerRequest));
        $offset = max(0, (int) ($options['offset'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        $limit = max(1, min((int) ($options['limit'] ?? $batchSize), $maxItemsPerRequest));
        $onlyMatched = !empty($options['only_matched']);
        $imageSafetyStrict = !array_key_exists('image_safety_strict', $options) || !empty($options['image_safety_strict']);
        $stopOnError = !empty($options['stop_on_error']);
        $skipAlreadyEnriched = !empty($options['skip_already_enriched']);
        $includeExistingOvoko = !empty($options['include_existing_ovoko']);
        $csvIndexType = 'full';
        $csvIndexMeta = $this->get_cached_csv_mapping_index($csvIndexType);
        $stages[] = ['stage' => 'csv_mapping_loaded', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'csv_mapping_rows' => (int) ($csvIndexMeta['csv_mapping_rows'] ?? 0), 'unique_codes' => (int) ($csvIndexMeta['unique_codes'] ?? 0), 'duplicate_codes' => (int) ($csvIndexMeta['duplicate_codes'] ?? 0), 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
        if (empty($csvIndexMeta['index'])) {
            return ['ok' => false, 'action_name' => 'Update product cards from Ovoko CSV mapping', 'reason' => 'csv_mapping_not_loaded'];
        }
        $hasProductIdsCsv = trim((string) ($options['product_ids_csv'] ?? '')) !== '';
        $selectionSource = $hasProductIdsCsv ? 'product_ids_csv' : 'fast_scan';
        if (!$hasProductIdsCsv) {
            $stages[] = ['stage' => 'query_started', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
        }
        $scanLimit = max(1, min((int) ($options['scan_limit'] ?? $batchSize), $maxItemsPerRequest));
        $candidateIds = array_slice($this->resolve_bulk_product_ids($options, $offset, $limit, $batchSize, $page, $includeExistingOvoko), 0, $scanLimit);
        if (!$hasProductIdsCsv) {
            $stages[] = ['stage' => 'query_finished', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'ids_count' => count($candidateIds)];
        }
        $stages[] = ['stage' => 'product_ids_parsed', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'ids_count' => count($candidateIds), 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
        $stages[] = ['stage' => 'product_loop_started', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
        $results = []; $counts = ['updated'=>0,'dry_run'=>0,'no_csv_match'=>0,'ambiguous_csv_match'=>0,'api_error'=>0,'safety_violation'=>0,'memory_guard'=>0,'already_enriched_skipped'=>0,'not_allegro_product'=>0,'error'=>0,'woo_found_by_allegro_id'=>0,'ovoko_id_saved'=>0,'ovoko_id_conflict'=>0,'woo_write_error'=>0];
        $matched = 0; $enriched = 0; $skipped = 0; $reviewRequired = 0; $errors = 0; $processed = 0;
        $partial = false; $stoppedReason = '';
        $ids = array_slice($candidateIds, 0, $batchSize);
        $scannedCandidateIds = $hasProductIdsCsv ? [] : array_map('intval', $candidateIds);
        $selectedProductIds = [];
        $skippedNonAllegroLike = 0;
        foreach ($ids as $productId) {
            if (($processed >= $maxItemsPerRequest) || ((microtime(true) - $started) >= $maxRuntimeSeconds)) {
                $partial = true;
                $stoppedReason = $processed >= $maxItemsPerRequest ? 'max_items_per_request' : 'max_runtime_seconds';
                break;
            }
            $productStarted = microtime(true);
            $processed++;
            $selectedProductIds[] = (int) $productId;
            $stages[] = ['stage' => 'before_product_meta_read', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
            $audit = $this->collect_allegro_product_context($productId, false);
            $stages[] = ['stage' => 'after_product_meta_read', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
            if (!$includeExistingOvoko && empty($audit['is_allegro_product'])) { $skipped++; $skippedNonAllegroLike++; $counts['not_allegro_product']++; $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'status'=>'skipped_not_allegro_product','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]); unset($audit); if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); } continue; }
            if ($skipAlreadyEnriched && (string)($audit['current_ovoko_part_id'] ?? '') !== '') { $skipped++; $counts['already_enriched_skipped']++; $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'status'=>'skipped_already_enriched','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]); unset($audit); if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); } continue; }
            $match = $this->resolve_ovoko_match_for_allegro_product_csv_only($productId, (array) $csvIndexMeta['index']);
            $stages[] = ['stage' => 'after_csv_match', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
            $confidence = (string) ($match['match_confidence'] ?? 'none');
            $isMatched = in_array($confidence, ['high','high_existing_ovoko_part_id'], true);
            if ($isMatched) { $matched++; $counts['woo_found_by_allegro_id']++; }
            $allegroId = sanitize_text_field((string) ($audit['allegro_offer_id'] ?? ''));
            $row = ['product_id'=>$productId,'allegro_id'=>$allegroId,'matched_ovoko_part_id'=>(string)($match['matched_ovoko_part_id'] ?? ($match['part_normalized']['part_id'] ?? '')),'matched_ovoko_car_id'=>(string)($match['part_normalized']['car_id'] ?? ''),'match_confidence'=>$confidence,'action'=>'skipped','would_write_attributes_count'=>0,'would_write_attributes_labels'=>[],'skipped_fields_count'=>0,'vehicle_label'=>'','vehicle_slug'=>'','vehicle_parts_url'=>'','no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_status_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true];
            if (!$isMatched) { $skipped++; if ((string)($confidence) === 'ambiguous') { $reviewRequired++; $counts['ambiguous_csv_match']++; $results[] = array_merge($row, ['action'=>'skipped', 'skip_reason'=>'ambiguous_csv_match']); } else { $counts['no_csv_match']++; $results[] = array_merge($row, ['action'=>'skipped', 'skip_reason'=>'no_csv_match']); } $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'ovoko_id'=>(string) ($row['matched_ovoko_part_id'] ?? ''),'status'=>'skipped_no_match','skip_reason'=>'no_csv_match','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]); unset($audit, $match, $row); if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); } if ($onlyMatched) { continue; } continue; }
            $partId = (int) ($row['matched_ovoko_part_id'] ?? 0);
            $detailsError = '';
            $detailsNormalized = (array) ($match['part_normalized'] ?? []);
            if ($partId <= 0) {
                $detailsError = 'no_part_id_after_csv_match';
            } else {
                $stages[] = ['stage' => 'before_api_part_fetch', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'part_id' => $partId];
                $client = new RrrApiClient($this->get_settings());
                $single = $client->preview_fetch_single_part($partId);
                $stages[] = ['stage' => 'after_api_part_fetch', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'part_id' => $partId, 'api_ok' => !empty($single['ok'])];
                if (empty($single['ok'])) {
                    $detailsError = 'api_error_part_fetch';
                } else {
                    $detailsNormalized = $client->normalize_rrr_single_part_payload((array) ($single['payload'] ?? []), ['details_only' => true]);
                    $stages[] = ['stage' => 'before_api_car_fetch', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'car_id' => (string) ($detailsNormalized['car_id'] ?? '')];
                    $stages[] = ['stage' => 'after_api_car_fetch', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'car_id' => (string) ($detailsNormalized['car_id'] ?? '')];
                }
            }
            if ($detailsError !== '') {
                $skipped++;
                $errors++;
                $counts['api_error']++;
                $results[] = array_merge($row, ['action' => 'skipped', 'skip_reason' => 'api_error', 'error' => $detailsError, 'ovoko_id'=>(string) ($row['matched_ovoko_part_id'] ?? ''), 'elapsed_ms' => (int) round((microtime(true)-$productStarted)*1000)]);
                unset($audit, $match, $detailsNormalized, $row);
                if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
                continue;
            }
            $row['matched_ovoko_car_id'] = (string) ($detailsNormalized['car_id'] ?? '');
            $currentOvokoId = sanitize_text_field((string) get_post_meta($productId, '_ovoko_part_id', true));
            $newOvokoId = sanitize_text_field((string) ($row['matched_ovoko_part_id'] ?? ''));
            if ($currentOvokoId !== '' && $newOvokoId !== '' && $currentOvokoId !== $newOvokoId) {
                $skipped++;
                $counts['ovoko_id_conflict']++;
                $results[] = array_merge($row, ['action'=>'skipped', 'skip_reason'=>'ovoko_id_conflict', 'current_ovoko_id'=>$currentOvokoId, 'csv_ovoko_id'=>$newOvokoId, 'ovoko_id'=>$newOvokoId]);
                $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'current_ovoko_id'=>$currentOvokoId,'csv_ovoko_id'=>$newOvokoId,'skip_reason'=>'ovoko_id_conflict','status'=>'skipped_conflict','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]);
                continue;
            }
            $row += ['apply_allowed' => true, 'apply_blocked_reason' => '', 'memory_peak_mb' => round(memory_get_peak_usage(true)/1048576, 2)];
            if ($dryRun || empty($options['apply'])) {
                $counts['dry_run']++;
                $enrichedDry = $this->build_enriched_normalized_for_apply($detailsNormalized);
                $rawAttrsDry = $this->build_ovoko_technical_attributes_from_normalized($enrichedDry);
                $filterDebugDry = [];
                $attrsDry = $this->filter_customer_facing_technical_attributes($rawAttrsDry, $filterDebugDry);
                $stages[] = ['stage' => 'after_build_attributes', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'product_id' => (int) $productId, 'attributes_count' => count($attrsDry)];
                $vehicleLabel = $this->build_vehicle_label_from_attributes($attrsDry);
                $vehicleSlug = $this->build_unique_vehicle_slug($productId, (string) ($detailsNormalized['car_id'] ?? ''), $vehicleLabel, (string) ($attrsDry['Rok produkcji samochodu'] ?? ''));
                $results[] = array_merge($row, ['action'=>'dry_run', 'ovoko_id'=>$newOvokoId, 'elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000), 'would_write_attributes_count'=>count($attrsDry), 'would_write_attributes_labels'=>array_values(array_map('strval', array_keys($attrsDry))), 'skipped_fields_count'=>count($filterDebugDry), 'vehicle_label' => $vehicleLabel, 'vehicle_slug' => $vehicleSlug, 'vehicle_parts_url' => $vehicleSlug !== '' ? home_url('/czesci-z-pojazdu/' . $vehicleSlug . '/') : '', 'no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_status_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true,'apply_allowed'=>(bool) ($row['apply_allowed'] ?? true), 'apply_blocked_reason'=>(string) ($row['apply_blocked_reason'] ?? ''), 'memory_peak_mb'=>round(memory_get_peak_usage(true)/1048576, 2)]);
                $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'ovoko_id'=>$newOvokoId,'status'=>'matched_dry_run','action'=>'dry_run','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]);
                unset($audit, $match, $detailsNormalized, $enrichedDry, $rawAttrsDry, $filterDebugDry, $attrsDry, $vehicleLabel, $vehicleSlug, $row);
                if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
                continue;
            }
            if ($newOvokoId !== '' && $currentOvokoId !== $newOvokoId) {
                $writeOk1 = update_post_meta($productId, '_ovoko_part_id', $newOvokoId);
                $writeOk2 = update_post_meta($productId, 'ovoko_id', $newOvokoId);
                if ($writeOk1 === false || $writeOk2 === false) {
                    $skipped++;
                    $errors++;
                    $counts['woo_write_error']++;
                    $results[] = array_merge($row, ['action'=>'skipped', 'skip_reason'=>'woo_write_error', 'ovoko_id'=>$newOvokoId]);
                    $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'ovoko_id'=>$newOvokoId,'status'=>'error_woo_write','skip_reason'=>'woo_write_error','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]);
                    continue;
                }
                $counts['ovoko_id_saved']++;
            }
            $before = $this->capture_product_safety_snapshot($productId);
            $apply = $this->apply_allegro_to_ovoko_details($productId, $replaceDescription);
            if (empty($apply['ok'])) { $errors++; $counts['error']++; $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'ovoko_id'=>$newOvokoId,'status'=>'error_apply_failed','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]); $results[] = array_merge($row, ['action'=>'error', 'ovoko_id'=>$newOvokoId]); continue; }
            $after = $this->capture_product_safety_snapshot($productId);
            $safety = $this->compare_product_safety_snapshot($before, $after);
            $imageChangedKeys = $this->diff_snapshot_keys($before, $after, $this->image_safety_keys());
            $safetyDebug = ['image_snapshot_before' => array_intersect_key($before, array_flip($this->image_safety_keys())), 'image_snapshot_after' => array_intersect_key($after, array_flip($this->image_safety_keys())), 'image_changed_keys' => $imageChangedKeys];
            $safetyViolation = in_array(false, $safety, true) || ($imageSafetyStrict && !empty($imageChangedKeys));
            if ($safetyViolation) {
                $errors++;
                $counts['safety_violation']++;
                $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'status'=>'error_safety_violation','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000),'image_changed_keys'=>$imageChangedKeys,'image_safety_strict'=>$imageSafetyStrict ? 1 : 0]);
                $results[] = array_merge($row, ['action'=>'error','error'=>'safety_violation'], $safety, $safetyDebug);
                if ($stopOnError) {
                    $partial = true;
                    $stoppedReason = 'stop_on_error_safety_violation';
                    break;
                }
                continue;
            }
            $enriched++; $counts['updated']++;
            $this->log_event('bulk_allegro_to_ovoko_product', ['product_id'=>$productId,'allegro_id'=>$allegroId,'ovoko_id'=>$newOvokoId,'status'=>'enriched','action'=>'updated','elapsed_ms'=>(int) round((microtime(true)-$productStarted)*1000)]);
            $results[] = array_merge($row, ['action'=>'updated','ovoko_id'=>$newOvokoId,'vehicle_label'=>(string)($apply['vehicle_label'] ?? ''),'vehicle_slug'=>(string)($apply['vehicle_slug'] ?? ''),'vehicle_parts_url'=>(string)($apply['vehicle_parts_url'] ?? ''),'attributes_count'=>count((array)($apply['attributes_written'] ?? [])),'attributes_written'=>(array)($apply['attributes_written'] ?? []),'skipped_fields'=>(array)($apply['skipped_fields'] ?? []),'no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_status_change'=>true,'no_ebay_publish'=>true,'no_allegro_publish'=>true,'no_batch'=>true], $safety, $safetyDebug);
            unset($audit, $match, $normalized, $detailsNormalized, $enrichedDry, $rawAttrsDry, $filterDebugDry, $attrsDry, $before, $apply, $after, $safety, $row);
            if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
        }
        $duration = round(microtime(true) - $started, 3);
        if ($stoppedReason === '' && count($candidateIds) >= $batchSize) {
            $partial = true;
            $stoppedReason = 'batch_size_limit';
        }
        $stages[] = ['stage' => 'before_response', 'elapsed_ms' => (int) round((microtime(true) - $started) * 1000), 'processed' => $processed, 'memory_usage' => memory_get_usage(true), 'memory_peak' => memory_get_peak_usage(true)];
        $guardStatus = $this->build_apply_guard_status();
        $lastProcessedId = !empty($selectedProductIds) ? (int) end($selectedProductIds) : max(0, (int) ($options['after_product_id'] ?? $options['last_seen_product_id'] ?? 0));
        $isDone = $processed === 0 || $lastProcessedId <= 0 || (!$partial && $processed < $batchSize);
        $response = ['ok'=>true,'partial'=>$partial,'done'=>$isDone,'selection_source'=>$selectionSource,'csv_index_type'=>$csvIndexType,'stopped_reason'=>$stoppedReason,'action_name'=>'Update product cards from Ovoko CSV mapping','handler_used'=>'bulk_update_cards','form_source'=>(string) ($options['form_source'] ?? 'batch_update_form'),'stages'=>$stages,'total_scanned'=>count($ids),'processed'=>$processed,'updated'=>$enriched,'skipped'=>$skipped,'errors'=>$errors,'dry_run'=>$dryRun,'replace_description'=>$replaceDescription,'image_safety_strict'=>$imageSafetyStrict,'stop_on_error'=>$stopOnError,'max_runtime_seconds'=>$maxRuntimeSeconds,'max_items_per_request'=>$maxItemsPerRequest,'counts_by_reason'=>$counts,'csv_rows_loaded'=>(int) ($csvIndexMeta['csv_mapping_rows'] ?? 0),'woo_products_found_by_allegro_id'=>(int) ($counts['woo_found_by_allegro_id'] ?? 0),'products_with_saved_ovoko_id'=>(int) ($counts['ovoko_id_saved'] ?? 0),'updated_from_ovoko'=>(int) $enriched,'skipped_no_woo_for_allegro_id'=>(int) ($counts['no_csv_match'] ?? 0),'skipped_ovoko_id_conflict'=>(int) ($counts['ovoko_id_conflict'] ?? 0),'ovoko_api_errors'=>(int) ($counts['api_error'] ?? 0),'woo_write_errors'=>(int) ($counts['woo_write_error'] ?? 0),'sample_results'=>array_slice($results,0,10),'next_offset'=>$offset + $processed,'next_page'=>$page + 1,'after_product_id'=>max(0, (int) ($options['after_product_id'] ?? $options['last_seen_product_id'] ?? 0)),'next_after_product_id'=>$lastProcessedId,'duration'=>$duration,'memory_peak_mb'=>round(memory_get_peak_usage(true)/1048576,2)] + $guardStatus;
        unset($response['scanned_candidate_ids'], $response['selected_product_ids']);
        return $response;
    }

    public function single_enrichment_dry_run_memory_safe(array $options = []): array
    {
        $productId = max(1, (int) ($options['product_id'] ?? 0));
        if (get_post_type($productId) !== 'product') return ['ok' => false, 'error' => 'invalid_product_id', 'product_id' => $productId];
        $memoryStages = [];
        $memoryStages[] = ['stage' => 'before_api_part_fetch', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $detailsOnly = !array_key_exists('details_only', $options) || !empty($options['details_only']);
        $preview = $this->preview_allegro_to_ovoko_match($productId, ['details_only' => $detailsOnly, 'minimal_response' => true, 'disable_debug_heavy_logs' => true]);
        $memoryStages[] = ['stage' => 'after_api_part_fetch', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $preview = ['match' => (array) ($preview['match'] ?? [])];
        $memoryStages[] = ['stage' => 'after_part_minimized', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $match = (array) ($preview['match'] ?? []);
        $normalized = (array) ($match['part_normalized'] ?? []);
        unset($preview);
        $memoryStages[] = ['stage' => 'after_unset_raw_part_payload', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        if (function_exists('gc_collect_cycles')) { gc_collect_cycles(); }
        $memoryStages[] = ['stage' => 'after_gc_collect_cycles', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $memoryStages[] = ['stage' => 'before_api_car_fetch', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $memoryStages[] = ['stage' => 'after_api_car_fetch', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $memoryStages[] = ['stage' => 'before_normalize', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $enriched = $this->build_enriched_normalized_for_apply($normalized);
        $memoryStages[] = ['stage' => 'after_normalize', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $memoryStages[] = ['stage' => 'before_build_attributes', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $rawAttrs = $this->build_ovoko_technical_attributes_from_normalized($enriched);
        $filterDebug = [];
        $attrs = $this->filter_customer_facing_technical_attributes($rawAttrs, $filterDebug);
        $memoryStages[] = ['stage' => 'after_build_attributes', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $memoryStages[] = ['stage' => 'before_response', 'memory_usage_mb' => round(memory_get_usage(true)/1048576, 2)];
        $vehicleLabel = $this->build_vehicle_label_from_attributes($attrs);
        $vehicleSlug = $this->build_unique_vehicle_slug($productId, (string) ($normalized['car_id'] ?? ''), $vehicleLabel, (string) ($attrs['Rok produkcji samochodu'] ?? ''));
        return ['ok'=>true,'action_name'=>'Single enrichment dry-run','handler_used'=>(string) ($options['handler_used'] ?? 'single_enrichment_dry_run_memory_safe'),'form_source'=>(string) ($options['form_source'] ?? 'single_update_form'),'product_id'=>$productId,'dry_run'=>true,'details_only'=>$detailsOnly,'minimal_response'=>true,'disable_debug_heavy_logs'=>true,'matched_ovoko_part_id'=>(string)($match['matched_ovoko_part_id'] ?? ($normalized['part_id'] ?? '')),'matched_ovoko_car_id'=>(string)($normalized['car_id'] ?? ''),'match_confidence'=>(string)($match['match_confidence'] ?? 'none'),'vehicle_label'=>$vehicleLabel,'vehicle_slug'=>$vehicleSlug,'vehicle_parts_url'=>$vehicleSlug !== '' ? home_url('/czesci-z-pojazdu/' . $vehicleSlug . '/') : '','old_parts_url'=>!empty($normalized['car_id']) ? add_query_arg('ovoko_car_id', rawurlencode((string) $normalized['car_id']), get_post_type_archive_link('product')) : '','would_write_attributes_count'=>count($attrs),'would_write_attributes_labels'=>array_keys($attrs),'skipped_fields_count'=>count($filterDebug),'no_price_change'=>true,'no_stock_change'=>true,'no_images_change'=>true,'no_title_change'=>true,'no_status_change'=>true,'memory_peak_mb'=>round(memory_get_peak_usage(true)/1048576, 2),'memory_stages'=>$memoryStages] + $this->build_apply_guard_status($memoryStages);
    }

    public function update_woo_description_from_ovoko_listing_text(array $options = []): array
    {
        $productId = max(0, (int) ($options['product_id'] ?? 0));
        $afterProductId = max(0, (int) ($options['after_product_id'] ?? 0));
        $limit = max(1, min(100, (int) ($options['limit'] ?? 1)));
        $batchSize = max(1, min(100, (int) ($options['batch_size'] ?? 1)));
        $overrideOvokoId = isset($options['ovoko_id']) ? trim((string) $options['ovoko_id']) : '';
        $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
        $replaceExisting = !empty($options['replace_existing_description']);
        $saveToMetaOnly = !array_key_exists('save_to_meta_only', $options) || !empty($options['save_to_meta_only']);
        $prepend = !empty($options['prepend_to_existing_description']);
        $updateOnlyEmpty = !array_key_exists('update_only_empty_description', $options) || !empty($options['update_only_empty_description']);
        $stopOnError = !empty($options['stop_on_error']);
        $showFullPayloadDebug = !empty($options['show_full_ovoko_part_payload_for_description_debug']);
        $metaKey = sanitize_key((string) ($options['listing_text_meta_key'] ?? '_ovoko_listing_text'));
        $counts = ['total_scanned'=>0,'with_ovoko_id'=>0,'missing_ovoko_id'=>0,'ovoko_listing_text_found'=>0,'ovoko_listing_text_missing'=>0,'woo_description_empty'=>0,'skipped_existing_description'=>0,'already_contains_listing_text'=>0,'description_updated'=>0,'saved_to_meta_only'=>0,'old_allegro_description_removed'=>0,'errors'=>0];

        $productIds = [];
        if ($productId > 0) {
            $productIds[] = $productId;
        } else {
            $query = new \WP_Query(['post_type' => 'product', 'post_status' => ['publish','draft','private'], 'fields' => 'ids', 'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true, 'post__not_in' => $afterProductId > 0 ? range(1, $afterProductId) : []]);
            $productIds = array_map('intval', (array) $query->posts);
        }
        if (empty($productIds)) return ['ok' => true, 'dry_run' => $dryRun, 'counts' => $counts, 'results' => [], 'after_product_id' => $afterProductId, 'next_after_product_id' => $afterProductId, 'batch_size' => $batchSize, 'limit' => $limit, 'action_name' => 'Update Woo descriptions from Ovoko listing text'];

        $results = [];
        foreach ($productIds as $pid) {
            $counts['total_scanned']++;
            $singleResult = $this->update_single_woo_description_from_ovoko_listing_text($pid, $overrideOvokoId, $dryRun, $replaceExisting, $saveToMetaOnly, $prepend, $updateOnlyEmpty, $metaKey, $showFullPayloadDebug, $counts);
            $results[] = $singleResult;
            if (empty($singleResult['ok']) && $stopOnError) break;
        }
        return ['ok' => $counts['errors'] === 0, 'action_name' => 'Update Woo descriptions from Ovoko listing text', 'dry_run' => $dryRun, 'save_to_meta_only' => $saveToMetaOnly, 'update_only_empty_description' => $updateOnlyEmpty, 'replace_existing_description' => $replaceExisting, 'prepend_to_existing_description' => $prepend, 'limit' => $limit, 'batch_size' => $batchSize, 'after_product_id' => $afterProductId, 'next_after_product_id' => max($afterProductId, (int) end($productIds)), 'results' => $results, 'product_id' => (int) ($results[0]['product_id'] ?? 0), 'ovoko_id' => (string) ($results[0]['ovoko_id'] ?? ''), 'ovoko_listing_text_found' => (bool) ($results[0]['ovoko_listing_text_found'] ?? false), 'ovoko_listing_text_source_key' => (string) ($results[0]['ovoko_listing_text_source_key'] ?? ''), 'ovoko_listing_text' => (string) ($results[0]['ovoko_listing_text'] ?? ''), 'woo_post_content_length' => (int) ($results[0]['woo_post_content_length'] ?? 0), 'woo_short_description_length' => (int) ($results[0]['woo_short_description_length'] ?? 0), 'planned_action' => (string) ($results[0]['planned_action'] ?? ''), 'counts' => $counts];
    }

    private function update_single_woo_description_from_ovoko_listing_text(int $productId, string $overrideOvokoId, bool $dryRun, bool $replaceExisting, bool $saveToMetaOnly, bool $prepend, bool $updateOnlyEmpty, string $metaKey, bool $showFullPayloadDebug, array &$counts): array
    {
        $sourceMethod = __METHOD__;
        $product = wc_get_product($productId);
        if (!$product) { $counts['errors']++; return ['ok'=>false,'reason'=>'product_not_found','product_id'=>$productId]; }
        $ovokoId = $overrideOvokoId !== '' ? $overrideOvokoId : trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        if ($ovokoId === '') { $counts['missing_ovoko_id']++; return ['ok'=>false,'reason'=>'missing_ovoko_id','product_id'=>$productId]; }
        $counts['with_ovoko_id']++;

        $client = $this->build_rrr_api_client();
        if ($client === null) {
            $counts['errors']++;
            return [
                'ok' => false,
                'product_id' => $productId,
                'ovoko_id' => $ovokoId,
                'error' => 'RRR API client unavailable',
                'api_error' => 'rrr_api_client_not_initialized',
                'source_method' => $sourceMethod,
            ];
        }

        $single = $client->preview_fetch_single_part((int) $ovokoId);
        if (empty($single['ok'])) {
            $counts['errors']++;
            return [
                'ok' => false,
                'reason' => 'ovoko_fetch_failed',
                'product_id' => $productId,
                'ovoko_id' => $ovokoId,
                'error' => 'Failed to fetch part details from Ovoko API',
                'api_error' => (string) ($single['msg'] ?? ($single['error'] ?? 'unknown_api_error')),
                'source_method' => $sourceMethod,
                'fetch' => $single,
            ];
        }
        $payload = (array) ($single['payload'] ?? []);
        $detected = $this->extract_ovoko_listing_text($payload);
        $debugPayload = $this->build_ovoko_listing_text_debug($payload, $showFullPayloadDebug);
        $listingText = $detected['text'];
        $listingSource = $detected['source_key'];
        if ($listingText === '') $counts['ovoko_listing_text_missing']++; else $counts['ovoko_listing_text_found']++;
        $currentContent = (string) get_post_field('post_content', $productId);
        $currentShort = (string) $product->get_short_description();
        $isContentEmpty = trim(wp_strip_all_tags($currentContent)) === '';
        if ($isContentEmpty) $counts['woo_description_empty']++;
        $normalizedListingText = $this->normalize_description_text_for_compare($listingText);
        $normalizedCurrentContent = $this->normalize_description_text_for_compare($currentContent);
        $alreadyContainsListingText = $normalizedListingText !== '' && $normalizedCurrentContent !== '' && strpos($normalizedCurrentContent, $normalizedListingText) !== false;
        $hasWitamOfertaDotyczy = stripos($currentContent, 'Witam oferta dotyczy:') !== false;
        $containsOldAllegroTextBefore = $hasWitamOfertaDotyczy;
        $replaceMode = $replaceExisting && !$saveToMetaOnly;
        $prependEffective = $replaceMode ? false : $prepend;
        $shouldSkipExisting = !$isContentEmpty && $updateOnlyEmpty && !$replaceExisting && !$prependEffective;
        $shouldSkipBecauseAlreadyContains = !$saveToMetaOnly && !$isContentEmpty && $prependEffective && $alreadyContainsListingText;
        $plannedAction = $saveToMetaOnly ? 'save_to_meta_only' : ($shouldSkipExisting ? 'skip_existing_description' : ($replaceMode ? 'replace_post_content_from_ovoko' : 'update_post_content'));
        if ($shouldSkipBecauseAlreadyContains) {
            $plannedAction = 'already_contains_listing_text';
        }
        $result = ['ok'=>true,'dry_run'=>$dryRun,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'ovoko_listing_text_found'=>$listingText !== '','ovoko_listing_text_source_key'=>$listingSource,'ovoko_listing_text'=>$listingText,'ovoko_listing_text_length'=>mb_strlen($listingText),'woo_post_content_length'=>mb_strlen($currentContent),'woo_short_description_length'=>mb_strlen($currentShort),'planned_action'=>$plannedAction,'woo_description_is_empty'=>$isContentEmpty,'replace_existing_description'=>$replaceExisting,'save_to_meta_only'=>$saveToMetaOnly,'prepend_to_existing_description'=>$prepend,'prepend_to_existing_description_effective'=>$prependEffective,'update_only_empty_description'=>$updateOnlyEmpty,'listing_text_meta_key'=>$metaKey,'ovoko_description_debug'=>$debugPayload,'description_updated_verified'=>false,'wp_update_post_result'=>null,'wp_update_post_error'=>null,'post_content_source_field'=>'post_content','post_content_before_length'=>mb_strlen($currentContent),'post_content_after_length'=>mb_strlen($currentContent),'post_content_before_preview'=>mb_substr($currentContent, 0, 240),'post_content_after_preview'=>mb_substr($currentContent, 0, 240),'attempted_post_content_length'=>0,'post_content_after_contains_listing_text'=>$alreadyContainsListingText,'post_content_after_contains_old_allegro_text'=>$containsOldAllegroTextBefore,'current_post_content_preview'=>mb_substr($currentContent, 0, 240),'planned_new_post_content_preview'=>mb_substr($listingText, 0, 240),'current_length'=>mb_strlen($currentContent),'new_length'=>mb_strlen($listingText),'contains_witam_oferta_dotyczy'=>$hasWitamOfertaDotyczy,'old_allegro_description_will_be_removed'=>$replaceMode && $listingText !== '' && !$isContentEmpty,'write_target_product_id'=>$productId,'write_target_ovoko_id'=>$ovokoId];
        if ($listingText === '') {
            $result['planned_action'] = 'skip_ovoko_listing_text_missing';
            $result['reason'] = 'ovoko_listing_text_missing';
            return $result;
        }
        if ($dryRun) return $result;
        if ($saveToMetaOnly) { update_post_meta($productId, $metaKey, $listingText); $counts['saved_to_meta_only']++; }
        elseif ($shouldSkipExisting) { $counts['skipped_existing_description']++; }
        elseif ($shouldSkipBecauseAlreadyContains) {
            $counts['already_contains_listing_text']++;
            $result['description_updated_verified'] = true;
            $result['post_content_after_contains_listing_text'] = true;
            $result['skipped_verified'] = true;
        }
        else {
            $newContent = $prependEffective && !$isContentEmpty ? trim($listingText . "\n\n" . $currentContent) : $listingText;
            $result['attempted_post_content_length'] = mb_strlen($newContent);
            $update = wp_update_post(['ID'=>$productId,'post_content'=>$newContent], true);
            if (is_wp_error($update)) {
                $counts['errors']++;
                $result['ok'] = false;
                $result['reason'] = 'woo_update_failed';
                $result['error'] = $update->get_error_message();
                $result['woo_write_error'] = true;
                $result['error_message'] = $update->get_error_message();
                $result['wp_update_post_error'] = $update->get_error_message();
            } else {
                $result['wp_update_post_result'] = (int) $update;
                $afterContent = (string) get_post_field('post_content', $productId);
                $containsListingText = $listingText !== '' && strpos($afterContent, $listingText) !== false;
                $containsOldAllegroTextAfter = stripos($afterContent, 'Witam oferta dotyczy:') !== false;
                $verified = $replaceMode ? ($afterContent === $listingText) : $containsListingText;
                $result['post_content_after_length'] = mb_strlen($afterContent);
                $result['post_content_after_preview'] = mb_substr($afterContent, 0, 240);
                $result['post_content_after_contains_listing_text'] = $containsListingText;
                $result['post_content_after_contains_old_allegro_text'] = $containsOldAllegroTextAfter;
                $result['description_updated_verified'] = $verified && !$containsOldAllegroTextAfter;
                if ($replaceMode && !$containsOldAllegroTextAfter && $containsOldAllegroTextBefore) { $counts['old_allegro_description_removed']++; }
                if ($verified) {
                    $counts['description_updated']++;
                } else {
                    $result['ok'] = false;
                    $result['warning'] = 'post_content_write_not_verified';
                }
            }
        }
        $result['counts'] = $counts;
        return $result;
    }






    public function stream_current_product_category_assignments_csv($fh, array $options = []): array
    {
        if (!is_resource($fh)) {
            return ['ok' => false, 'error' => 'invalid_csv_stream'];
        }

        $batchSize = max(1, min(200, (int) ($options['batch_size'] ?? 100)));
        $afterProductId = max(0, (int) ($options['after_product_id'] ?? 0));
        $maxRows = max(0, (int) ($options['max_rows'] ?? 0));
        $rowsWritten = 0;
        $lastProductId = $afterProductId;
        $categoryPathCache = [];

        fputcsv($fh, ['product_id','SKU','title','ovoko_id','current_category_ids','current_category_paths','current_primary_category_id','current_primary_category_path'], ',', '"', '\\');

        while ($maxRows === 0 || $rowsWritten < $maxRows) {
            $remainingRows = $maxRows > 0 ? ($maxRows - $rowsWritten) : $batchSize;
            $limit = min($batchSize, $remainingRows);
            $products = $this->get_product_category_assignment_export_products($lastProductId, $limit);
            if (empty($products)) {
                break;
            }

            $productIds = array_map(static fn(array $row): int => (int) $row['ID'], $products);
            $metaByProduct = $this->get_product_category_assignment_export_meta($productIds);
            $termsByProduct = $this->get_product_category_assignment_export_terms($productIds, $categoryPathCache);

            foreach ($products as $productRow) {
                $productId = (int) $productRow['ID'];
                $meta = $metaByProduct[$productId] ?? [];
                $terms = $termsByProduct[$productId] ?? ['ids' => [], 'paths' => []];
                $primaryId = $this->resolve_export_primary_category_id($meta);

                fputcsv($fh, [
                    $productId,
                    (string) ($meta['_sku'] ?? ''),
                    (string) ($productRow['post_title'] ?? ''),
                    $this->resolve_export_ovoko_id($meta),
                    implode('|', $terms['ids']),
                    implode('|', array_filter($terms['paths'])),
                    $primaryId > 0 ? (string) $primaryId : '',
                    $primaryId > 0 ? $this->get_product_cat_path_cached($primaryId, $categoryPathCache) : '',
                ], ',', '"', '\\');

                $rowsWritten++;
                $lastProductId = $productId;
                if ($maxRows > 0 && $rowsWritten >= $maxRows) {
                    break;
                }
            }

            fflush($fh);
            $this->cleanup_product_category_assignment_export_batch($productIds);
            unset($products, $productIds, $metaByProduct, $termsByProduct);
        }

        return [
            'ok' => true,
            'streamed' => true,
            'storage' => 'php://output',
            'batch_size' => $batchSize,
            'after_product_id' => $afterProductId,
            'max_rows' => $maxRows,
            'rows_written' => $rowsWritten,
            'last_product_id' => $lastProductId,
            'data_changed' => false,
        ];
    }

    public function dry_run_delete_all_product_categories(bool $ultraLight = true): array
    {
        global $wpdb;
        $started = microtime(true);
        $counts = [
            'total_product_categories' => 0,
            'categories_with_products' => 0,
            'empty_categories' => 0,
            'product_category_relationships' => 0,
            'affected_products' => 0,
            'total_published_private_products' => 0,
            'products_with_ovoko_id' => 0,
            'products_missing_ovoko_id' => 0,
        ];
        $samples = [
            'categories' => [],
            'affected_products' => [],
        ];
        $warnings = ['Dry-run only: no product_cat terms or relationships were deleted. Products, media, descriptions, prices, stock, eBay, Allegro, and Ovoko are not touched.'];
        $logs = [];
        $logStage = function (string $stage, array $context = []) use ($started, &$logs): void {
            $entry = [
                'stage' => $stage,
                'duration' => round(microtime(true) - $started, 3),
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ] + $context;
            $logs[] = $entry;
            $this->log('dry_run_delete_all_product_categories_' . $stage, $entry);
        };
        $runCount = function (string $key, string $sql) use ($wpdb, &$counts, $logStage): int {
            $value = (int) $wpdb->get_var($sql);
            $counts[$key] = $value;
            $logStage('each_count_query_done', ['count_key' => $key, 'value' => $value]);
            return $value;
        };

        $logStage('action_start', ['ultra_light' => $ultraLight]);

        try {
            $totalCategories = $runCount(
                'total_product_categories',
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", 'product_cat')
            );
            $runCount(
                'product_category_relationships',
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s", 'product_cat')
            );
            $runCount(
                'affected_products',
                $wpdb->prepare("SELECT COUNT(DISTINCT tr.object_id) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s", 'product_cat')
            );
            $categoriesWithProducts = $runCount(
                'categories_with_products',
                $wpdb->prepare("SELECT COUNT(DISTINCT tr.term_taxonomy_id) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tt.taxonomy = %s", 'product_cat')
            );
            $counts['empty_categories'] = max(0, $totalCategories - $categoriesWithProducts);
            $logStage('each_count_query_done', ['count_key' => 'empty_categories', 'value' => $counts['empty_categories'], 'calculated_from' => ['total_product_categories', 'categories_with_products']]);
            $totalPublishedPrivateProducts = $runCount(
                'total_published_private_products',
                $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','private')", 'product')
            );
            $ovokoMetaKeys = $this->ovoko_product_id_meta_keys();
            $metaPlaceholders = implode(',', array_fill(0, count($ovokoMetaKeys), '%s'));
            $productsWithOvoko = $runCount(
                'products_with_ovoko_id',
                $wpdb->prepare(
                    "SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = %s AND p.post_status IN ('publish','private') AND pm.meta_key IN ({$metaPlaceholders}) AND pm.meta_value IS NOT NULL AND pm.meta_value <> ''",
                    array_merge(['product'], $ovokoMetaKeys)
                )
            );
            $counts['products_missing_ovoko_id'] = max(0, $totalPublishedPrivateProducts - $productsWithOvoko);
            $logStage('each_count_query_done', ['count_key' => 'products_missing_ovoko_id', 'value' => $counts['products_missing_ovoko_id'], 'calculated_from' => ['total_published_private_products', 'products_with_ovoko_id']]);

            $categoryRows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.parent, tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY t.term_id ASC LIMIT 20",
                    'product_cat'
                ),
                ARRAY_A
            );
            foreach ($categoryRows as $row) {
                $termId = (int) ($row['term_id'] ?? 0);
                $samples['categories'][] = [
                    'term_id' => $termId,
                    'term_taxonomy_id' => (int) ($row['term_taxonomy_id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'parent' => (int) ($row['parent'] ?? 0),
                    'count' => (int) ($row['count'] ?? 0),
                    'path' => $termId > 0 ? $this->get_product_cat_path($termId) : '',
                ];
            }
            $logStage('sample_query_done', ['sample_key' => 'categories', 'rows' => count($samples['categories']), 'limit' => 20]);

            $productRows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT p.ID, p.post_title, p.post_status FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_type = %s ORDER BY p.ID ASC LIMIT 20",
                    'product_cat',
                    'product'
                ),
                ARRAY_A
            );
            foreach ($productRows as $row) {
                $samples['affected_products'][] = [
                    'product_id' => (int) ($row['ID'] ?? 0),
                    'post_title' => (string) ($row['post_title'] ?? ''),
                    'post_status' => (string) ($row['post_status'] ?? ''),
                ];
            }
            $logStage('sample_query_done', ['sample_key' => 'affected_products', 'rows' => count($samples['affected_products']), 'limit' => 20]);

            $duration = round(microtime(true) - $started, 3);
            $logStage('action_end', ['duration' => $duration]);

            return [
                'ok' => true,
                'action_name' => 'Ultra-light dry-run delete all Woo product categories',
                'dry_run' => true,
                'ultra_light' => $ultraLight,
                'sample_limit' => 20,
                'total_product_categories' => $counts['total_product_categories'],
                'categories_with_products' => $counts['categories_with_products'],
                'empty_categories' => $counts['empty_categories'],
                'product_category_relationships' => $counts['product_category_relationships'],
                'affected_products' => $counts['affected_products'],
                'total_published_private_products' => $counts['total_published_private_products'],
                'products_with_ovoko_id' => $counts['products_with_ovoko_id'],
                'products_missing_ovoko_id' => $counts['products_missing_ovoko_id'],
                'samples' => $samples,
                'warnings' => $warnings,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'duration' => $duration,
                'logs' => $logs,
            ];
        } catch (\Throwable $e) {
            $warnings[] = 'Dry-run delete failed before all checks completed. Partial counts are shown; no products or categories were changed.';
            $duration = round(microtime(true) - $started, 3);
            $logStage('action_end', ['duration' => $duration, 'error' => $e->getMessage()]);

            return [
                'ok' => false,
                'action_name' => 'Ultra-light dry-run delete all Woo product categories',
                'dry_run' => true,
                'ultra_light' => $ultraLight,
                'partial' => true,
                'sample_limit' => 20,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'counts' => $counts,
                'samples' => $samples,
                'warnings' => $warnings,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
                'memory_usage_mb' => round(memory_get_usage(true) / 1048576, 2),
                'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
                'duration' => $duration,
                'logs' => $logs,
            ] + $counts;
        }
    }

    public function delete_all_product_categories(string $confirmation): array
    {
        if ($confirmation !== 'DELETE ALL PRODUCT CATEGORIES') {
            return ['ok' => false, 'action_name' => 'Delete all Woo product categories', 'error' => 'confirmation_required', 'required_confirmation' => 'DELETE ALL PRODUCT CATEGORIES'];
        }
        global $wpdb;
        $dry = $this->dry_run_delete_all_product_categories();
        $affectedProductIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_type = %s",
            'product_cat', 'product'
        ));
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'DESC']);
        $deleted = 0;
        $errors = [];
        foreach ((array) $terms as $term) {
            if (!($term instanceof \WP_Term)) { continue; }
            $deletedResult = wp_delete_term((int) $term->term_id, 'product_cat');
            if (is_wp_error($deletedResult)) {
                $errors[] = ['term_id' => (int) $term->term_id, 'error' => $deletedResult->get_error_message()];
            } elseif ($deletedResult) {
                $deleted++;
            }
        }
        foreach (array_map('intval', (array) $affectedProductIds) as $pid) {
            $this->clear_product_primary_category($pid);
            clean_post_cache($pid);
        }
        if (function_exists('gp_clear_product_category_display_cache')) { gp_clear_product_category_display_cache(); }
        return [
            'ok' => $errors === [],
            'action_name' => 'Delete all Woo product categories',
            'deleted_categories' => $deleted,
            'detached_relationships' => (int) ($dry['product_category_relationships'] ?? 0),
            'affected_products' => count((array) $affectedProductIds),
            'errors' => $errors,
        ];
    }


    public function get_category_rebuild_autorun_status(): array
    {
        $stored = get_option(self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION, null);
        return $this->normalize_category_rebuild_autorun_status($stored);
    }

    public function get_category_rebuild_autorun_debug(): array
    {
        $stored = get_option(self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION, null);
        $status = $this->normalize_category_rebuild_autorun_status($stored);
        $rawJson = wp_json_encode($stored, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($rawJson === false) {
            $rawJson = 'json_encode_failed';
        }

        return [
            'option_name' => self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION,
            'lock_option_name' => self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION,
            'raw' => $stored,
            'raw_json' => $rawJson,
            'summary' => $this->summarize_category_rebuild_autorun_status($status),
            'invalid' => !empty($status['autorun_status_invalid']),
            'invalid_reason' => (string) ($status['autorun_status_invalid_reason'] ?? ''),
            'last_ajax_response' => get_option('gpswiss_ovoko_category_rebuild_autorun_last_ajax', []),
        ];
    }

    private function normalize_category_rebuild_autorun_status($stored): array
    {
        $default = $this->default_category_rebuild_autorun_status();
        $invalidReason = '';
        $required = ['status','current_after_product_id','next_after_product_id','last_safe_next_after_product_id','processed_total','errors_total'];

        if ($stored === false || $stored === null) {
            $status = $default;
        } elseif (!is_array($stored)) {
            $status = $default;
            $invalidReason = 'stored status is not an array';
        } else {
            $missing = [];
            foreach ($required as $key) {
                if (!array_key_exists($key, $stored)) {
                    $missing[] = $key;
                }
            }
            if ($missing !== []) {
                $invalidReason = 'stored status missing fields: ' . implode(', ', $missing);
            }
            $status = wp_parse_args($stored, $default);
        }

        $allowedStatuses = ['idle','running','paused','error','done'];
        if (!in_array((string) ($status['status'] ?? ''), $allowedStatuses, true)) {
            $invalidReason = trim($invalidReason . '; unknown status: ' . (string) ($status['status'] ?? ''));
            $status['status'] = 'invalid';
        }

        $status['autorun_status_invalid'] = $invalidReason !== '';
        $status['autorun_status_invalid_reason'] = $invalidReason;
        return $status;
    }

    private function summarize_category_rebuild_autorun_status(array $status): array
    {
        return [
            'status' => (string) ($status['status'] ?? 'idle'),
            'invalid' => !empty($status['autorun_status_invalid']),
            'invalid_reason' => (string) ($status['autorun_status_invalid_reason'] ?? ''),
            'current_after_product_id' => (int) ($status['current_after_product_id'] ?? 0),
            'next_after_product_id' => (int) ($status['next_after_product_id'] ?? 0),
            'last_safe_next_after_product_id' => (int) ($status['last_safe_next_after_product_id'] ?? 0),
            'processed_total' => (int) ($status['processed_total'] ?? 0),
            'errors_total' => (int) ($status['errors_total'] ?? 0),
            'last_error' => (string) ($status['last_error'] ?? ''),
            'updated_at' => (string) ($status['updated_at'] ?? ''),
        ];
    }

    private function default_category_rebuild_autorun_status(): array
    {
        return [
            'start_after_product_id' => 0,
            'current_after_product_id' => 0,
            'next_after_product_id' => 0,
            'last_safe_next_after_product_id' => 0,
            'batch_size' => 5,
            'cache_rebuild_every_n_batches' => 5,
            'batches_done_since_cache_rebuild' => 0,
            'status' => 'idle',
            'stop_on_error' => true,
            'processed_total' => 0,
            'fixed_total' => 0,
            'skipped_total' => 0,
            'errors_total' => 0,
            'missing_ovoko_id_total' => 0,
            'missing_category_id_total' => 0,
            'missing_category_path_total' => 0,
            'skipped_missing_ovoko_id_total' => 0,
            'skipped_missing_category_id_total' => 0,
            'skipped_missing_category_path_total' => 0,
            'skipped_ovoko_fetch_failed_total' => 0,
            'skipped_category_resolution_failed_total' => 0,
            'api_errors_total' => 0,
            'categories_created_total' => 0,
            'categories_existing_total' => 0,
            'category_assignments_changed_total' => 0,
            'started_at' => '',
            'updated_at' => '',
            'duration' => 0,
            'last_batch_result' => null,
            'last_error' => '',
            'menu_cache_rebuild' => 'skipped',
            'menu_cache_category_count' => 0,
            'menu_cache_build_duration' => 0,
            'menu_cache_error' => '',
            'batches_since_last_cache_rebuild' => 0,
        ];
    }

    private function save_category_rebuild_autorun_status(array $status): array
    {
        $status = wp_parse_args($status, $this->default_category_rebuild_autorun_status());
        $status['updated_at'] = gmdate('c');
        if ((string) ($status['started_at'] ?? '') !== '') {
            $status['duration'] = round(max(0, time() - strtotime((string) $status['started_at'])), 3);
        }
        update_option(self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION, $status, false);
        return $status;
    }

    public function reset_category_rebuild_autorun_status(): array
    {
        delete_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION);
        delete_option(self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION);
        return $this->get_category_rebuild_autorun_status();
    }

    public function control_category_rebuild_autorun(string $command, array $options = []): array
    {
        $status = $this->get_category_rebuild_autorun_status();
        if ($command === 'status') {
            return $this->category_rebuild_autorun_response(true, $command, $status, 'status_loaded');
        }
        if ($command === 'reset') {
            return $this->category_rebuild_autorun_response(true, $command, $this->reset_category_rebuild_autorun_status(), 'autorun_status_reset');
        }
        if ($command === 'pause') {
            if (($status['status'] ?? '') === 'running') { $status['status'] = 'paused'; }
            return $this->category_rebuild_autorun_response(true, $command, $this->save_category_rebuild_autorun_status($status), $command . '_accepted');
        }
        if ($command === 'stop') {
            if (in_array((string) ($status['status'] ?? ''), ['running','paused','error'], true)) { $status['status'] = 'idle'; }
            delete_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION);
            return $this->category_rebuild_autorun_response(true, $command, $this->save_category_rebuild_autorun_status($status), $command . '_accepted');
        }
        if ($command === 'resume') {
            $status['current_after_product_id'] = max(0, (int) ($status['last_safe_next_after_product_id'] ?? 0));
            $status['next_after_product_id'] = (int) $status['current_after_product_id'];
            $status['status'] = 'running';
            $status['last_error'] = '';
            return $this->category_rebuild_autorun_response(true, $command, $this->save_category_rebuild_autorun_status($status), $command . '_accepted');
        }
        if ($command === 'start') {
            if ((string) ($options['confirmation'] ?? '') !== 'REBUILD WOO CATEGORIES FROM OVOKO') {
                return $this->category_rebuild_autorun_response(false, $command, $status, 'confirmation_required', 'confirmation_required') + ['required_confirmation' => 'REBUILD WOO CATEGORIES FROM OVOKO'];
            }
            if (($status['status'] ?? '') === 'running' && empty($status['autorun_status_invalid'])) {
                return $this->category_rebuild_autorun_response(false, $command, $status, 'autorun_already_running', 'autorun_already_running');
            }
            $lastSafe = max(0, (int) ($status['last_safe_next_after_product_id'] ?? 0));
            $startAfter = array_key_exists('start_after_product_id', $options) ? max(0, (int) $options['start_after_product_id']) : $lastSafe;
            $status = $this->default_category_rebuild_autorun_status();
            $status['start_after_product_id'] = $startAfter;
            $status['current_after_product_id'] = $startAfter;
            $status['next_after_product_id'] = $startAfter;
            $status['last_safe_next_after_product_id'] = $lastSafe;
            $status['batch_size'] = max(1, min(100, (int) ($options['batch_size'] ?? 5)));
            $status['cache_rebuild_every_n_batches'] = max(1, min(1000, (int) ($options['cache_rebuild_every_n_batches'] ?? 5)));
            $status['stop_on_error'] = !array_key_exists('stop_on_error', $options) || !empty($options['stop_on_error']);
            $status['status'] = 'running';
            $status['started_at'] = gmdate('c');
            $status['last_error'] = '';
            return $this->category_rebuild_autorun_response(true, $command, $this->save_category_rebuild_autorun_status($status), $command . '_accepted');
        }
        if ($command === 'tick') {
            return $this->run_category_rebuild_autorun_tick($status);
        }
        return $this->category_rebuild_autorun_response(false, $command, $status, 'unknown_command', 'unknown_command');
    }

    private function category_rebuild_autorun_response(bool $ok, string $command, array $status, string $message = '', string $error = ''): array
    {
        return [
            'ok' => $ok,
            'command' => $command,
            'status' => $status,
            'message' => $message,
            'error' => $error,
            'status_summary' => $this->summarize_category_rebuild_autorun_status($status),
        ];
    }

    private function run_category_rebuild_autorun_tick(array $status): array
    {
        if (($status['status'] ?? '') !== 'running') {
            return $this->category_rebuild_autorun_response(true, 'tick', $status, 'autorun_not_running') + ['done' => true];
        }
        $lock = get_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION, []);
        if (is_array($lock) && (int) ($lock['expires_at'] ?? 0) > time()) {
            return $this->category_rebuild_autorun_response(false, 'tick', $status, 'autorun_batch_already_running', 'autorun_batch_already_running');
        }
        delete_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION);
        add_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION, ['started_at' => gmdate('c'), 'expires_at' => time() + 600], '', 'no');
        try {
            $batchesSince = max(0, (int) ($status['batches_done_since_cache_rebuild'] ?? 0));
            $interval = max(1, (int) ($status['cache_rebuild_every_n_batches'] ?? 5));
            $shouldRebuildCache = ($batchesSince + 1) >= $interval;
            $batch = $this->rebuild_woo_categories_from_ovoko_from_scratch([
                'after_product_id' => max(0, (int) ($status['current_after_product_id'] ?? 0)),
                'batch_size' => max(1, (int) ($status['batch_size'] ?? 5)),
                'dry_run' => false,
                'confirmation' => 'REBUILD WOO CATEGORIES FROM OVOKO',
                'stop_on_error' => !empty($status['stop_on_error']),
                'rebuild_menu_cache_when_done' => $shouldRebuildCache,
                'force_menu_cache_rebuild_when_done' => true,
                'batches_since_last_cache_rebuild' => $batchesSince,
            ]);
            $counts = (array) ($batch['counts'] ?? []);
            $status['last_batch_result'] = $batch;
            $status['current_after_product_id'] = (int) ($batch['next_after_product_id'] ?? $status['current_after_product_id']);
            $status['next_after_product_id'] = (int) ($batch['next_after_product_id'] ?? $status['next_after_product_id']);
            $status['last_safe_next_after_product_id'] = max((int) ($status['last_safe_next_after_product_id'] ?? 0), (int) ($batch['last_safe_next_after_product_id'] ?? 0));
            foreach (['processed','fixed','skipped','errors','missing_ovoko_id','missing_category_id','missing_category_path','skipped_missing_ovoko_id','skipped_missing_category_id','skipped_missing_category_path','skipped_ovoko_fetch_failed','skipped_category_resolution_failed','api_errors','categories_created','categories_existing','category_assignments_changed'] as $key) {
                $target = $key . '_total';
                $status[$target] = (int) ($status[$target] ?? 0) + (int) ($counts[$key] ?? $batch[$key] ?? 0);
            }
            $status['menu_cache_rebuild'] = (string) ($batch['menu_cache_rebuild'] ?? 'skipped');
            $status['menu_cache_category_count'] = (int) ($batch['menu_cache_category_count'] ?? 0);
            $status['menu_cache_build_duration'] = (float) ($batch['menu_cache_build_duration'] ?? 0);
            $status['menu_cache_error'] = (string) ($batch['menu_cache_error'] ?? '');
            if (in_array($status['menu_cache_rebuild'], ['done','failed'], true)) { $batchesSince = 0; } else { $batchesSince++; }
            $status['batches_done_since_cache_rebuild'] = $batchesSince;
            $status['batches_since_last_cache_rebuild'] = $batchesSince;
            if (!empty($batch['menu_cache_rebuild_error'])) { $status['last_error'] = (string) $batch['menu_cache_rebuild_error']; }
            $latestStoredStatus = get_option(self::CATEGORY_REBUILD_AUTORUN_STATUS_OPTION, []);
            $requestedStatus = is_array($latestStoredStatus) ? (string) ($latestStoredStatus['status'] ?? 'running') : 'running';
            if (in_array($requestedStatus, ['paused','idle'], true)) {
                $status['status'] = $requestedStatus;
            }
            $batchErrors = (int) ($counts['errors'] ?? $batch['errors'] ?? 0);
            if (($batchErrors > 0 || empty($batch['ok'])) && !empty($status['stop_on_error'])) {
                $status['status'] = 'error';
                $status['last_error'] = (string) ($batch['error'] ?? 'errors_in_batch');
            } elseif (!empty($batch['done'])) {
                $status['status'] = 'done';
            }
            return $this->category_rebuild_autorun_response(!empty($batch['ok']), 'tick', $this->save_category_rebuild_autorun_status($status), !empty($batch['ok']) ? 'tick_completed' : 'tick_failed', empty($batch['ok']) ? (string) ($batch['error'] ?? 'batch_failed') : '') + ['done' => in_array((string) $status['status'], ['done','paused','idle','error'], true), 'batch' => $batch];
        } catch (\Throwable $e) {
            $status['status'] = 'error';
            $status['api_errors_total'] = (int) ($status['api_errors_total'] ?? 0) + 1;
            $status['last_error'] = $e->getMessage();
            return $this->category_rebuild_autorun_response(false, 'tick', $this->save_category_rebuild_autorun_status($status), 'tick_exception', $e->getMessage());
        } finally {
            delete_option(self::CATEGORY_REBUILD_AUTORUN_LOCK_OPTION);
        }
    }

    public function rebuild_woo_categories_from_ovoko_from_scratch(array $options = []): array
    {
        $started = microtime(true);
        $productId = max(0, (int) ($options['product_id'] ?? 0));
        $afterProductId = max(0, (int) ($options['after_product_id'] ?? 0));
        $batchSize = max(1, min(100, (int) ($options['batch_size'] ?? 10)));
        $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
        if (!$dryRun && (string) ($options['confirmation'] ?? '') !== 'REBUILD WOO CATEGORIES FROM OVOKO') {
            return ['ok' => false, 'action_name' => 'Rebuild Woo categories from Ovoko from scratch', 'dry_run' => false, 'error' => 'confirmation_required', 'required_confirmation' => 'REBUILD WOO CATEGORIES FROM OVOKO'];
        }
        if (!$dryRun && $this->is_category_rebuild_paused()) {
            return ['ok' => true, 'paused' => true, 'action_name' => 'Rebuild Woo categories from Ovoko from scratch', 'dry_run' => false, 'after_product_id' => $afterProductId, 'next_after_product_id' => $afterProductId, 'last_safe_next_after_product_id' => $afterProductId, 'results' => [], 'counts' => []];
        }

        $ids = $productId > 0 ? [$productId] : $this->get_product_ids_after($afterProductId, $batchSize);
        $categorySnapshot = $this->load_ovoko_category_tree_snapshot_for_batch();
        $counts = ['processed'=>0,'fixed'=>0,'skipped'=>0,'errors'=>0,'missing_ovoko_id'=>0,'missing_category_id'=>0,'missing_category_path'=>0,'skipped_missing_ovoko_id'=>0,'skipped_missing_category_id'=>0,'skipped_missing_category_path'=>0,'skipped_ovoko_fetch_failed'=>0,'skipped_category_resolution_failed'=>0,'api_errors'=>0,'categories_created'=>0,'categories_existing'=>0,'category_assignments_changed'=>0];
        if ($ids !== [] && empty($categorySnapshot['ok'])) {
            $counts['errors'] = 1;
            $counts['api_errors'] = 1;
            $snapshotError = (string) ($categorySnapshot['error'] ?? 'category_tree_snapshot_failed');
            return [
                'ok' => false,
                'action_name' => $dryRun ? 'Dry-run rebuild Woo categories from Ovoko' : 'Rebuild Woo categories from Ovoko from scratch',
                'dry_run' => $dryRun,
                'batch_size' => $batchSize,
                'after_product_id' => $afterProductId,
                'next_after_product_id' => $afterProductId,
                'last_safe_next_after_product_id' => $afterProductId,
                'processed' => 0,
                'fixed' => 0,
                'skipped' => 0,
                'errors' => 1,
                'api_errors' => 1,
                'error' => $snapshotError,
                'counts' => $counts,
                'results' => [],
                'done' => false,
                'category_tree_snapshot' => [
                    'ok' => false,
                    'endpoint' => (string) ($categorySnapshot['endpoint'] ?? ''),
                    'categories_total_loaded' => 0,
                    'fetched_at' => (string) ($categorySnapshot['fetched_at'] ?? ''),
                    'error' => $snapshotError,
                ],
            ];
        }
        $legacyCounts = ['total_scanned'=>0,'with_ovoko_id'=>0,'missing_ovoko_id'=>0,'ovoko_category_found'=>0,'ovoko_category_missing'=>0,'categories_created'=>0,'categories_existing'=>0,'products_categories_updated'=>0,'products_categories_verified'=>0,'products_skipped'=>0,'errors'=>0,'skipped_ovoko_fetch_failed'=>0,'skipped_category_resolution_failed'=>0,'category_assignments_changed'=>0];
        $results = [];
        $lastSafe = $afterProductId;
        foreach ($ids as $pid) {
            $legacyCounts['total_scanned']++;
            $counts['processed']++;
            $single = $this->update_single_woo_category_from_ovoko((int) $pid, $dryRun, true, true, $legacyCounts, $categorySnapshot, false);
            $single['would_remove_old_categories'] = (array) ($single['categories_to_be_replaced'] ?? []);
            $single['would_create_terms'] = (array) ($single['categories_that_would_be_created'] ?? []);
            $single['would_assign_terms'] = (array) ($single['planned_category_hierarchy'] ?? []);
            $single['would_set_primary_category'] = (string) ($single['planned_final_category']['name'] ?? '') !== '' ? ($single['planned_final_category'] ?? null) : null;
            $single['expected_ovoko_category_path'] = (string) ($single['resolved_full_ovoko_category_path'] ?? $single['ovoko_category_title_path'] ?? '');
            $reason = (string) ($single['reason'] ?? '');
            $warnings = array_values(array_unique(array_map('strval', (array) ($single['warnings'] ?? []))));
            if ($reason === 'missing_ovoko_id') {
                $single['ok'] = true;
                $single['skipped'] = true;
                $single['errors'] = [];
                $single['error'] = '';
                $warnings[] = 'missing_ovoko_id';
                $counts['missing_ovoko_id']++;
                $counts['skipped_missing_ovoko_id']++;
            } elseif (trim((string) ($single['ovoko_id'] ?? '')) !== '') {
                if (trim((string) ($single['category_id'] ?? '')) === '') {
                    $warnings[] = 'missing_category_id';
                    $counts['missing_category_id']++;
                    $counts['skipped_missing_category_id']++;
                }
                if (trim((string) ($single['expected_ovoko_category_path'] ?? '')) === '') {
                    $warnings[] = 'missing_category_path';
                    $counts['missing_category_path']++;
                    $counts['skipped_missing_category_path']++;
                }
            }
            $single['warnings'] = array_values(array_unique($warnings));
            $isRealError = $this->is_category_rebuild_real_product_error($single);
            if ($isRealError) { $counts['errors']++; }
            if ($reason === 'ovoko_fetch_failed') { $counts['skipped_ovoko_fetch_failed']++; }
            if (in_array($reason, ['category_resolution_failed','ovoko_category_tree_resolution_failed'], true)) { $counts['skipped_category_resolution_failed']++; }
            if ($reason === 'rrr_api_client_not_initialized') { $counts['api_errors']++; }
            if (!empty($single['category_assignment_changed'])) { $counts['category_assignments_changed']++; }
            if (!empty($single['category_update_verified']) || ($dryRun && empty($reason))) { $counts['fixed']++; } else { $counts['skipped']++; }
            $results[] = $this->summarize_category_rebuild_product_result($single);
            $lastSafe = max($lastSafe, (int) $pid);
            if (!$dryRun && !empty($single['category_update_verified']) && (int) ($single['assigned_term_ids_after'][0] ?? 0) > 0) {
                $deepest = (int) end($single['assigned_term_ids_after']);
                $this->set_primary_product_category($pid, $deepest);
            }
            if (!$dryRun && !empty($options['stop_on_error']) && $isRealError) { break; }
        }
        $counts['categories_created'] = (int) $legacyCounts['categories_created'];
        $counts['categories_existing'] = (int) $legacyCounts['categories_existing'];
        $counts['category_assignments_changed'] = max((int) $counts['category_assignments_changed'], (int) ($legacyCounts['category_assignments_changed'] ?? 0));
        $next = $ids === [] ? $afterProductId : max($afterProductId, (int) end($ids));
        $done = $productId > 0 || $ids === [] || count($ids) < $batchSize;
        $hasRealChanges = !$dryRun && ((int) $counts['category_assignments_changed'] > 0 || (int) $counts['categories_created'] > 0);
        $forceFinalCacheRebuild = !$dryRun && !empty($options['force_menu_cache_rebuild_when_done']) && $done;
        $cacheReport = $this->rebuild_frontend_category_menu_cache_after_batch($dryRun, !empty($options['rebuild_menu_cache_when_done']) || $forceFinalCacheRebuild, $hasRealChanges || $forceFinalCacheRebuild);
        $skippedProductsByReason = ['missing_ovoko_id'=>[],'ovoko_fetch_failed'=>[],'missing_category_id'=>[],'missing_category_path'=>[]];
        foreach ($results as $resultItem) {
            $resultReason = (string) ($resultItem['reason'] ?? '');
            $resultWarnings = (array) ($resultItem['warnings'] ?? []);
            foreach (array_keys($skippedProductsByReason) as $skipReason) {
                if ($resultReason === $skipReason || in_array($skipReason, $resultWarnings, true)) {
                    $skippedProductsByReason[$skipReason][] = $resultItem;
                }
            }
        }
        $warnings = [];
        if ($cacheReport['menu_cache_rebuild'] === 'failed') {
            $warnings[] = 'Frontend category menu cache rebuild failed after this batch; product category mapping continued.';
        }
        $result = [
            'ok' => $counts['errors'] === 0,
            'action_name' => $dryRun ? 'Dry-run rebuild Woo categories from Ovoko' : 'Rebuild Woo categories from Ovoko from scratch',
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'after_product_id' => $afterProductId,
            'next_after_product_id' => $next,
            'last_safe_next_after_product_id' => $lastSafe,
            'processed' => $counts['processed'],
            'fixed' => $counts['fixed'],
            'skipped' => $counts['skipped'],
            'errors' => $counts['errors'],
            'missing_ovoko_id' => $counts['missing_ovoko_id'],
            'missing_category_id' => $counts['missing_category_id'],
            'missing_category_path' => $counts['missing_category_path'],
            'api_errors' => $counts['api_errors'],
            'skipped_missing_ovoko_id' => $counts['skipped_missing_ovoko_id'],
            'skipped_missing_category_id' => $counts['skipped_missing_category_id'],
            'skipped_missing_category_path' => $counts['skipped_missing_category_path'],
            'skipped_ovoko_fetch_failed' => $counts['skipped_ovoko_fetch_failed'],
            'skipped_category_resolution_failed' => $counts['skipped_category_resolution_failed'],
            'duration' => round(microtime(true) - $started, 3),
            'counts' => $counts,
            'legacy_counts' => $legacyCounts,
            'category_tree_snapshot' => [
                'ok' => !empty($categorySnapshot['ok']),
                'endpoint' => (string) ($categorySnapshot['endpoint'] ?? ''),
                'categories_total_loaded' => count((array) ($categorySnapshot['map'] ?? [])),
                'fetched_at' => (string) ($categorySnapshot['fetched_at'] ?? ''),
                'error' => (string) ($categorySnapshot['error'] ?? ''),
            ],
            'results' => $results,
            'skipped_products_by_reason' => $skippedProductsByReason,
            'done' => $done,
            'menu_cache_rebuild' => $cacheReport['menu_cache_rebuild'],
            'menu_cache_category_count' => $cacheReport['menu_cache_category_count'],
            'menu_cache_build_duration' => $cacheReport['menu_cache_build_duration'],
            'menu_cache_error' => $cacheReport['menu_cache_error'],
            'menu_cache_rebuild_error' => $cacheReport['menu_cache_rebuild_error'],
            'menu_cache_rebuild_result' => $cacheReport['menu_cache_rebuild_result'],
            'batches_since_last_cache_rebuild' => (int) ($options['batches_since_last_cache_rebuild'] ?? 0),
            'warnings' => $warnings,
            'rules' => ['no_csv_mapping','no_motoryzacja_root','no_shortened_paths','replace_existing_product_cat_assignments','product_data_not_touched','no_ebay_or_allegro_changes','no_ovoko_writes'],
        ];
        update_option('gpswiss_ovoko_last_category_rebuild_batch', $result, false);
        return $result;
    }

    private function rebuild_frontend_category_menu_cache_after_batch(bool $dryRun, bool $enabled, bool $hasRealChanges): array
    {
        $currentStatus = function_exists('gp_get_product_category_display_cache_status') ? gp_get_product_category_display_cache_status() : [];
        $report = [
            'menu_cache_rebuild' => 'skipped',
            'menu_cache_category_count' => (int) ($currentStatus['category_count'] ?? 0),
            'menu_cache_build_duration' => (float) ($currentStatus['build_duration'] ?? 0),
            'menu_cache_error' => '',
            'menu_cache_rebuild_error' => '',
            'menu_cache_rebuild_result' => null,
        ];

        if ($dryRun || !$enabled || !$hasRealChanges) {
            return $report;
        }

        if (!function_exists('gp_rebuild_product_category_display_cache')) {
            $report['menu_cache_rebuild'] = 'failed';
            $report['menu_cache_error'] = 'gp_rebuild_product_category_display_cache is unavailable.';
            $report['menu_cache_rebuild_error'] = $report['menu_cache_error'];
            return $report;
        }

        $cacheResult = gp_rebuild_product_category_display_cache();
        $status = is_array($cacheResult) ? (array) ($cacheResult['status'] ?? []) : [];
        $error = is_array($cacheResult) ? (string) ($cacheResult['message'] ?? ($status['last_error'] ?? '')) : 'Invalid cache rebuild response.';
        if (!empty($cacheResult['ok'])) {
            $report['menu_cache_rebuild'] = 'done';
            $error = (string) ($status['last_error'] ?? '');
        } else {
            $report['menu_cache_rebuild'] = 'failed';
        }

        $report['menu_cache_category_count'] = (int) ($status['category_count'] ?? 0);
        $report['menu_cache_build_duration'] = (float) ($status['build_duration'] ?? 0);
        $report['menu_cache_error'] = $error;
        $report['menu_cache_rebuild_error'] = $report['menu_cache_rebuild'] === 'failed' ? $error : '';
        $report['menu_cache_rebuild_result'] = $cacheResult;

        return $report;
    }

    public function set_category_rebuild_paused(bool $paused): void
    {
        update_option('gpswiss_ovoko_category_rebuild_paused', $paused ? '1' : '0', false);
    }

    private function is_category_rebuild_paused(): bool
    {
        return get_option('gpswiss_ovoko_category_rebuild_paused', '0') === '1';
    }

    public function post_rebuild_category_audit(): array
    {
        global $wpdb;
        $totalProducts = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", 'product'));
        $withOvoko = $this->count_products_with_ovoko_id(true);
        $missingOvoko = $this->count_products_with_ovoko_id(false);
        $withCategory = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND tt.taxonomy = %s", 'product', 'product_cat'));
        $withoutCategory = max(0, $totalProducts - $withCategory);
        $withFullPath = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_gpswiss_ovoko_assigned_category_path' AND meta_value <> ''");
        $failed = [];
        $idsWithout = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN (SELECT DISTINCT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s) c ON c.object_id = p.ID WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft') AND c.object_id IS NULL ORDER BY p.ID ASC LIMIT 20", 'product_cat', 'product'));
        foreach ((array) $idsWithout as $pid) { $failed[] = ['product_id' => (int) $pid, 'title' => (string) get_the_title((int) $pid), 'ovoko_id' => $this->get_product_ovoko_id((int) $pid)]; }
        $lastCategoryRebuildBatch = get_option('gpswiss_ovoko_last_category_rebuild_batch', []);
        $skippedProductsByReason = is_array($lastCategoryRebuildBatch) ? (array) ($lastCategoryRebuildBatch['skipped_products_by_reason'] ?? []) : [];
        foreach (['missing_ovoko_id','ovoko_fetch_failed','missing_category_id','missing_category_path'] as $skipReason) {
            if (!array_key_exists($skipReason, $skippedProductsByReason)) {
                $skippedProductsByReason[$skipReason] = [];
            }
        }
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $maxDepth = 0;
        $top = [];
        foreach ((array) $terms as $term) {
            if (!($term instanceof \WP_Term)) { continue; }
            $depth = count(get_ancestors((int) $term->term_id, 'product_cat', 'taxonomy')) + 1;
            $maxDepth = max($maxDepth, $depth);
            if ((int) $term->parent === 0) { $top[] = (string) $term->name; }
        }
        return ['ok' => true, 'action_name' => 'Post-rebuild category audit', 'total_products' => $totalProducts, 'products_with_ovoko_id' => $withOvoko, 'products_missing_ovoko_id' => $missingOvoko, 'products_with_category' => $withCategory, 'products_without_category' => $withoutCategory, 'products_with_full_ovoko_path' => $withFullPath, 'products_failed_category_assignment' => $withoutCategory, 'sample_failures' => $failed, 'skipped_products_by_reason' => $skippedProductsByReason, 'total_product_categories_after_rebuild' => is_wp_error($terms) ? 0 : count((array) $terms), 'max_category_depth' => $maxDepth, 'top_level_categories_created' => array_values(array_unique($top)), 'menu_cache_status' => function_exists('gp_get_product_category_display_cache_status') ? gp_get_product_category_display_cache_status() : null];
    }

    private function get_product_category_assignment_export_products(int $afterProductId, int $limit): array
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC LIMIT %d",
            'product',
            $afterProductId,
            max(1, $limit)
        );
        return array_map(static fn($row): array => (array) $row, (array) $wpdb->get_results($sql, ARRAY_A));
    }

    private function get_product_category_assignment_export_meta(array $productIds): array
    {
        global $wpdb;
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0));
        if (empty($productIds)) {
            return [];
        }

        $metaKeys = [
            '_sku',
            '_ovoko_part_id',
            'ovoko_id',
            '_ovoko_id',
            '_yoast_wpseo_primary_product_cat',
            'rank_math_primary_product_cat',
            '_gpswiss_primary_product_cat',
        ];
        $idPlaceholders = implode(',', array_fill(0, count($productIds), '%d'));
        $keyPlaceholders = implode(',', array_fill(0, count($metaKeys), '%s'));
        $sql = $wpdb->prepare(
            "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$idPlaceholders}) AND meta_key IN ({$keyPlaceholders})",
            ...array_merge($productIds, $metaKeys)
        );

        $metaByProduct = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $postId = (int) ($row['post_id'] ?? 0);
            $metaKey = (string) ($row['meta_key'] ?? '');
            if ($postId <= 0 || $metaKey === '') {
                continue;
            }
            $metaByProduct[$postId][$metaKey] = (string) ($row['meta_value'] ?? '');
        }
        return $metaByProduct;
    }

    private function get_product_category_assignment_export_terms(array $productIds, array &$categoryPathCache): array
    {
        global $wpdb;
        $productIds = array_values(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0));
        if (empty($productIds)) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($productIds), '%d'));
        $sql = $wpdb->prepare(
            "SELECT tr.object_id, t.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id WHERE tr.object_id IN ({$idPlaceholders}) AND tt.taxonomy = %s ORDER BY tr.object_id ASC, t.name ASC",
            ...array_merge($productIds, ['product_cat'])
        );

        $termsByProduct = [];
        foreach ((array) $wpdb->get_results($sql, ARRAY_A) as $row) {
            $productId = (int) ($row['object_id'] ?? 0);
            $termId = (int) ($row['term_id'] ?? 0);
            if ($productId <= 0 || $termId <= 0) {
                continue;
            }
            if (!isset($termsByProduct[$productId])) {
                $termsByProduct[$productId] = ['ids' => [], 'paths' => []];
            }
            $termsByProduct[$productId]['ids'][] = $termId;
            $termsByProduct[$productId]['paths'][] = $this->get_product_cat_path_cached($termId, $categoryPathCache);
        }
        return $termsByProduct;
    }

    private function resolve_export_ovoko_id(array $meta): string
    {
        foreach (['_ovoko_part_id', 'ovoko_id', '_ovoko_id'] as $key) {
            $value = trim((string) ($meta[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function resolve_export_primary_category_id(array $meta): int
    {
        foreach (['_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat', '_gpswiss_primary_product_cat'] as $key) {
            $value = (int) ($meta[$key] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    private function get_product_cat_path_cached(int $termId, array &$categoryPathCache): string
    {
        if (!array_key_exists($termId, $categoryPathCache)) {
            $categoryPathCache[$termId] = $this->get_product_cat_path($termId);
        }
        return (string) $categoryPathCache[$termId];
    }

    private function cleanup_product_category_assignment_export_batch(array $productIds): void
    {
        foreach (array_map('intval', $productIds) as $productId) {
            if ($productId > 0 && function_exists('clean_post_cache')) {
                clean_post_cache($productId);
            }
        }

        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    private function get_product_ids_after(int $afterProductId, int $limit): array
    {
        global $wpdb;
        $limitSql = $limit > 0 ? $wpdb->prepare('LIMIT %d', $limit) : '';
        $sql = $wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND ID > %d ORDER BY ID ASC {$limitSql}", 'product', $afterProductId);
        return array_map('intval', (array) $wpdb->get_col($sql));
    }

    private function get_product_ovoko_id(int $productId): string
    {
        $ovokoId = trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        if ($ovokoId === '') { $ovokoId = trim((string) get_post_meta($productId, 'ovoko_id', true)); }
        if ($ovokoId === '') { $ovokoId = trim((string) get_post_meta($productId, '_ovoko_id', true)); }
        return $ovokoId;
    }

    private function ovoko_product_id_meta_keys(): array
    {
        return array_values(array_unique(array_merge(self::PART_ID_META_KEYS, [
            '_ovoko_id',
            'ovoko_id',
            '_ovoko_part_id',
            'ovoko_part_id',
        ])));
    }

    private function count_products_with_ovoko_id(bool $with): int
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft')", 'product'));
        $count = 0;
        foreach (array_map('intval', (array) $ids) as $pid) {
            $has = $this->get_product_ovoko_id($pid) !== '';
            if (($with && $has) || (!$with && !$has)) { $count++; }
        }
        return $count;
    }

    private function get_product_cat_path(int $termId): string
    {
        $term = get_term($termId, 'product_cat');
        if (!$term || is_wp_error($term)) { return ''; }
        $ancestors = array_reverse(get_ancestors($termId, 'product_cat', 'taxonomy'));
        $names = [];
        foreach ($ancestors as $ancestorId) {
            $ancestor = get_term((int) $ancestorId, 'product_cat');
            if ($ancestor && !is_wp_error($ancestor)) { $names[] = (string) $ancestor->name; }
        }
        $names[] = (string) $term->name;
        return implode(' / ', $names);
    }

    private function get_primary_product_category_id(int $productId): int
    {
        foreach (['_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat', '_gpswiss_primary_product_cat'] as $key) {
            $value = (int) get_post_meta($productId, $key, true);
            if ($value > 0) { return $value; }
        }
        return 0;
    }

    private function set_primary_product_category(int $productId, int $termId): void
    {
        if ($termId <= 0) { return; }
        update_post_meta($productId, '_yoast_wpseo_primary_product_cat', (string) $termId);
        update_post_meta($productId, 'rank_math_primary_product_cat', (string) $termId);
        update_post_meta($productId, '_gpswiss_primary_product_cat', (string) $termId);
    }

    private function clear_product_primary_category(int $productId): void
    {
        foreach (['_yoast_wpseo_primary_product_cat', 'rank_math_primary_product_cat', '_gpswiss_primary_product_cat'] as $key) { delete_post_meta($productId, $key); }
    }

    public function update_woo_categories_from_ovoko(array $options = []): array
    {
        $productId = max(0, (int) ($options['product_id'] ?? 0));
        $afterProductId = max(0, (int) ($options['after_product_id'] ?? 0));
        $limit = max(1, min(200, (int) ($options['limit'] ?? 10)));
        $batchSize = max(1, min(200, (int) ($options['batch_size'] ?? 10)));
        $dryRun = !array_key_exists('dry_run', $options) || !empty($options['dry_run']);
        $createMissing = !array_key_exists('create_missing_categories', $options) || !empty($options['create_missing_categories']);
        $replaceExisting = !array_key_exists('replace_existing_categories', $options) || !empty($options['replace_existing_categories']);
        $stopOnError = !empty($options['stop_on_error']);

        $counts = ['total_scanned'=>0,'with_ovoko_id'=>0,'missing_ovoko_id'=>0,'ovoko_category_found'=>0,'ovoko_category_missing'=>0,'categories_created'=>0,'categories_existing'=>0,'products_categories_updated'=>0,'products_categories_verified'=>0,'products_skipped'=>0,'errors'=>0,'skipped_ovoko_fetch_failed'=>0,'skipped_category_resolution_failed'=>0];
        $productIds = [];
        if ($productId > 0) { $productIds[] = $productId; }
        else {
            $query = new \WP_Query(['post_type'=>'product','post_status'=>['publish','draft','private'],'fields'=>'ids','posts_per_page'=>$limit,'orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'post__not_in'=>$afterProductId>0?range(1,$afterProductId):[]]);
            $productIds = array_slice(array_map('intval', (array) $query->posts), 0, $batchSize);
        }
        if ($productIds === []) return ['ok'=>true,'action_name'=>'Update Woo categories from Ovoko','dry_run'=>$dryRun,'counts'=>$counts,'results'=>[],'after_product_id'=>$afterProductId,'next_after_product_id'=>$afterProductId,'limit'=>$limit,'batch_size'=>$batchSize];
        $results=[];
        foreach($productIds as $pid){
            $counts['total_scanned']++;
            $single=$this->update_single_woo_category_from_ovoko($pid,$dryRun,$createMissing,$replaceExisting,$counts);
            $results[]=$single;
            if (empty($single['ok']) && $stopOnError) break;
        }
        return ['ok'=>$counts['errors']===0,'action_name'=>'Update Woo categories from Ovoko','dry_run'=>$dryRun,'create_missing_categories'=>$createMissing,'replace_existing_categories'=>$replaceExisting,'after_product_id'=>$afterProductId,'next_after_product_id'=>max($afterProductId,(int) end($productIds)),'limit'=>$limit,'batch_size'=>$batchSize,'results'=>$results,'counts'=>$counts];
    }

    private function load_ovoko_category_tree_snapshot_for_batch(): array
    {
        $client = $this->build_rrr_api_client();
        if ($client === null) {
            return ['ok' => false, 'error' => 'rrr_api_client_not_initialized', 'map' => []];
        }

        $snapshot = $client->fetch_categories_tree_snapshot();
        $nodes = (array) ($snapshot['nodes'] ?? []);
        $snapshot['map'] = $client->build_category_path_map_from_nodes($nodes);
        unset($snapshot['nodes']);

        return $snapshot;
    }

    private function summarize_category_rebuild_product_result(array $single): array
    {
        return [
            'ok' => !empty($single['ok']),
            'product_id' => (int) ($single['product_id'] ?? 0),
            'ovoko_id' => (string) ($single['ovoko_id'] ?? ''),
            'category_id' => (string) ($single['category_id'] ?? ''),
            'expected_path' => (string) ($single['expected_ovoko_category_path'] ?? $single['resolved_full_ovoko_category_path'] ?? $single['ovoko_category_title_path'] ?? ''),
            'current_path' => $this->format_current_product_category_path((array) ($single['current_woo_categories'] ?? [])),
            'would_create_terms' => (array) ($single['would_create_terms'] ?? []),
            'would_assign_final_term' => $single['would_set_primary_category'] ?? null,
            'simulated_category_creation_sequence' => (array) ($single['simulated_category_creation_sequence'] ?? []),
            'warnings' => (array) ($single['warnings'] ?? []),
            'errors' => (array) ($single['errors'] ?? []),
            'reason' => (string) ($single['reason'] ?? ''),
            'skipped' => !empty($single['skipped']),
            'planned_action' => (string) ($single['planned_action'] ?? ''),
            'category_assignment_attempted' => !empty($single['category_assignment_attempted']),
        ];
    }

    private function is_category_rebuild_real_product_error(array $single): bool
    {
        $reason = (string) ($single['reason'] ?? '');
        if (in_array($reason, ['missing_ovoko_id','ovoko_fetch_failed','missing_category_id','missing_category_path','category_resolution_failed','ovoko_category_missing','ovoko_category_tree_resolution_failed'], true)) {
            return false;
        }
        return empty($single['ok']) || !empty($single['error']) || (is_array($single['errors'] ?? null) && (array) $single['errors'] !== []);
    }

    private function format_current_product_category_path(array $currentCategories): string
    {
        if ($currentCategories === []) {
            return '';
        }
        $names = [];
        foreach ($currentCategories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $name = trim((string) ($category['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(' / ', $names);
    }

    private function update_single_woo_category_from_ovoko(int $productId, bool $dryRun, bool $createMissing, bool $replaceExisting, array &$counts, ?array $categorySnapshot = null, bool $includeDebug = true): array
    {
        $product=wc_get_product($productId);
        if(!$product){$counts['errors']++;return ['ok'=>false,'product_id'=>$productId,'reason'=>'product_not_found'];}
        $ovokoId=trim((string)get_post_meta($productId,'_ovoko_part_id',true));
        if($ovokoId===''){$ovokoId=trim((string)get_post_meta($productId,'ovoko_id',true));}
        if($ovokoId===''){ $counts['missing_ovoko_id']++; $counts['products_skipped']++; return ['ok'=>true,'skipped'=>true,'product_id'=>$productId,'ovoko_id'=>'','reason'=>'missing_ovoko_id','planned_action'=>'skip','errors'=>[],'warnings'=>['missing_ovoko_id'],'category_update_verified'=>false,'category_assignment_changed'=>false,'category_assignment_attempted'=>false]; }
        $counts['with_ovoko_id']++;
        $client=$this->build_rrr_api_client();
        if($client===null){$counts['errors']++;return ['ok'=>false,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'reason'=>'rrr_api_client_not_initialized'];}
        $single = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $single = $client->preview_fetch_single_part((int) $ovokoId);
            if (!empty($single['ok'])) {
                break;
            }
        }
        if (empty($single['ok'])) {
            $counts['products_skipped'] = (int) ($counts['products_skipped'] ?? 0) + 1;
            $counts['skipped_ovoko_fetch_failed'] = (int) ($counts['skipped_ovoko_fetch_failed'] ?? 0) + 1;
            return ['ok'=>true,'skipped'=>true,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'reason'=>'ovoko_fetch_failed','planned_action'=>'skip','errors'=>[],'warnings'=>['ovoko_fetch_failed'],'category_update_verified'=>false,'category_assignment_changed'=>false,'category_assignment_attempted'=>false,'fetch'=>$single];
        }
        $payload=(array)($single['payload']??[]);
        $record = $client->extract_single_part_record($payload);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $categoryDiagnostics = $this->build_ovoko_category_diagnostics($payload, $record, $normalized, $categorySnapshot, $includeDebug);
        $categoryResolve = $categoryDiagnostics['resolution'];
        $path = (string) ($categoryResolve['resolved_full_ovoko_category_path'] ?? '');
        $debug = $includeDebug ? [
            'top_level_keys' => array_values(array_map('strval', array_keys($payload))),
            'nested_keys_depth_4' => $this->collect_nested_keys_to_depth($payload, 4),
            'category_candidate_paths_checked' => $categoryResolve['checked_paths'] ?? [],
            'category_title_path_source_key' => $categoryResolve['category_title_path_source_key'] ?? '',
            'raw_category_title_path_value' => $categoryResolve['raw_category_title_path'] ?? null,
            'all_category_related_fields' => $categoryDiagnostics['all_category_related_fields'],
            'full_category_related_payload_fragment' => $categoryDiagnostics['full_category_related_payload_fragment'],
            'category_tree_payload_fragment' => $categoryDiagnostics['category_tree_payload_fragment'] ?? [],
            'category_endpoint_probe' => $categoryDiagnostics['category_endpoint_probe'],
            'categories_endpoints_debug' => $categoryDiagnostics['categories_endpoints_debug'] ?? [],
            'category_resolution' => $categoryResolve,
            'categories_endpoint_used' => $categoryResolve['categories_endpoint_used'] ?? '',
            'categories_payload_shape' => $categoryResolve['categories_payload_shape'] ?? [],
            'categories_sample_records' => $categoryResolve['categories_sample_records'] ?? [],
            'categories_sample_keys' => $categoryResolve['categories_sample_keys'] ?? [],
            'category_322_found_in_categories_endpoint' => $categoryResolve['category_322_found_in_categories_endpoint'] ?? null,
            'category_322_node' => $categoryResolve['category_322_node'] ?? null,
            'category_322_parent_chain' => $categoryResolve['category_322_parent_chain'] ?? [],
            'category_322_resolved_path' => $categoryResolve['category_322_resolved_path'] ?? '',
        ] : [];
        if ($path === '' || trim((string) ($categoryResolve['category_id'] ?? '')) === '') {
            $counts['ovoko_category_missing'] = (int) ($counts['ovoko_category_missing'] ?? 0) + 1;
            $counts['products_skipped'] = (int) ($counts['products_skipped'] ?? 0) + 1;
            $debug['fields_matching_category_title_path'] = $this->collect_matching_nested_fields($payload, ['category', 'title', 'path']);
            $missingCategoryId = trim((string) ($categoryResolve['category_id'] ?? '')) === '';
            $reason = $missingCategoryId ? 'missing_category_id' : 'missing_category_path';
            $warnings = $missingCategoryId ? ['missing_category_id'] : ['missing_category_path'];
            if ($missingCategoryId && $path === '') {
                $warnings[] = 'missing_category_path';
            }
            return ['ok'=>true,'skipped'=>true,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'category_id'=>(string) ($categoryResolve['category_id'] ?? ''),'ovoko_category_title_path'=>'','resolved_full_ovoko_category_path'=>$path,'reason'=>$reason,'planned_action'=>'skip','errors'=>[],'warnings'=>array_values(array_unique($warnings)),'category_update_verified'=>false,'category_assignment_changed'=>false,'category_assignment_attempted'=>false,'debug'=>$debug];
        }
        if (($categoryResolve['category_resolution_confidence'] ?? 'low') !== 'high') {
            $counts['products_skipped'] = (int) ($counts['products_skipped'] ?? 0) + 1;
            $counts['skipped_category_resolution_failed'] = (int) ($counts['skipped_category_resolution_failed'] ?? 0) + 1;
            return ['ok'=>true,'skipped'=>true,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'category_id'=>(string) ($categoryResolve['category_id'] ?? ''),'ovoko_category_title_path'=>$path,'resolved_full_ovoko_category_path'=>$path,'reason'=>'category_resolution_failed','category_resolution_confidence'=>$categoryResolve['category_resolution_confidence'] ?? 'low','planned_action'=>'skip','errors'=>[],'warnings'=>['category_resolution_failed'],'category_update_verified'=>false,'category_assignment_changed'=>false,'category_assignment_attempted'=>false,'debug'=>$debug];
        }
        $counts['ovoko_category_found']++;
        $treeParentChain = is_array($categoryResolve['category_tree_parent_chain'] ?? null) ? $categoryResolve['category_tree_parent_chain'] : [];
        $levels = $this->extract_category_levels_from_parent_chain_or_path($treeParentChain, $path);
        $currentTerms=wp_get_post_terms($productId,'product_cat',['fields'=>'all']);
        $currentCats=[];$currentIds=[];
        foreach((array)$currentTerms as $t){$currentCats[]=['term_id'=>(int)$t->term_id,'name'=>(string)$t->name,'parent'=>(int)$t->parent];$currentIds[]=(int)$t->term_id;}
        $hier=[];$missing=[];$parent=0;$finalId=0;$chainOk=true;$levelIndex=0;$pendingByOvokoId=[];$pendingByLevel=[];
        foreach($levels as $level){
            $levelIndex++;
            $name=(string) ($level['name'] ?? '');
            $ovokoCategoryId=(int) ($level['ovoko_category_id'] ?? 0);
            $parentOvokoCategoryId=(int) ($level['parent_ovoko_category_id'] ?? 0);
            $parentPendingCreate = $parentOvokoCategoryId > 0 && !empty($pendingByOvokoId[$parentOvokoCategoryId]);
            if (!$parentPendingCreate && $levelIndex > 1 && !empty($pendingByLevel[$levelIndex - 1])) {
                $parentPendingCreate = true;
                $parentOvokoCategoryId = (int) ($pendingByLevel[$levelIndex - 1]['ovoko_category_id'] ?? 0);
            }
            $pendingParent = $parentPendingCreate ? (array) (($pendingByOvokoId[$parentOvokoCategoryId] ?? []) ?: ($pendingByLevel[$levelIndex - 1] ?? [])) : [];
            $plannedParentId = $parentPendingCreate ? null : $parent;

            $term=null;
            if(!$parentPendingCreate){
                $existing=get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$name,'parent'=>$parent]);
                $term=$existing[0]??null;
            }

            if($term){$counts['categories_existing']++;}
            else {
                $missingItem=['name'=>$name,'parent_id'=>$plannedParentId,'ovoko_category_id'=>$ovokoCategoryId,'level_index'=>$levelIndex];
                if($parentPendingCreate){
                    $missingItem=$this->add_pending_parent_plan_fields($missingItem, $pendingParent, $levelIndex, $parentOvokoCategoryId);
                } else {
                    $missingItem['parent_pending_create']=false;
                    $missingItem['parent_resolved_after_create']=false;
                }
                $missing[]=$missingItem;
                if(!$dryRun && $createMissing){
                    if($parentPendingCreate){$counts['errors']++;return ['ok'=>false,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'reason'=>'category_parent_pending_before_create','pending_parent'=>$pendingParent];}
                    $created=wp_insert_term($name,'product_cat',['parent'=>$parent]); if(is_wp_error($created)){$counts['errors']++;return ['ok'=>false,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'error'=>$created->get_error_message(),'reason'=>'category_create_failed'];} $term=get_term((int)$created['term_id'],'product_cat'); $counts['categories_created']++; }
                if(!$term){
                    $pending=['name'=>$name,'level_index'=>$levelIndex,'ovoko_category_id'=>$ovokoCategoryId,'pending_ref'=>'pending:level_'.$levelIndex];
                    if($ovokoCategoryId > 0){$pendingByOvokoId[$ovokoCategoryId]=$pending;}
                    $pendingByLevel[$levelIndex]=$pending;
                }
            }

            $hierItem=['name'=>$name,'parent_id'=>$plannedParentId,'exists'=>$term?true:false,'term_id'=>$term?(int)$term->term_id:0,'ovoko_category_id'=>$ovokoCategoryId,'level_index'=>$levelIndex];
            if($parentPendingCreate){
                $hierItem=$this->add_pending_parent_plan_fields($hierItem, $pendingParent, $levelIndex, $parentOvokoCategoryId);
            }
            $hier[]=$hierItem;

            if(!$term){$chainOk=false; if($dryRun){continue;} break;}
            if(!$dryRun && $ovokoCategoryId > 0){
                update_term_meta((int)$term->term_id, '_gpswiss_ovoko_category_id', (string)$ovokoCategoryId);
                update_term_meta((int)$term->term_id, '_gpswiss_ovoko_category_level_index', (string)$levelIndex);
                update_term_meta((int)$term->term_id, '_gpswiss_ovoko_category_path', (string)$path);
            }
            $parent=(int)$term->term_id;$finalId=$parent;
        }
        $creationSequence=$this->build_category_creation_sequence_preview($hier, $levels, $finalId);
        $result=['ok'=>true,'product_id'=>$productId,'ovoko_id'=>$ovokoId,'current_woo_categories'=>$currentCats,'ovoko_category_title_path'=>$path,'raw_category_title_path'=>$categoryResolve['raw_category_title_path'] ?? '','category_id'=>$categoryResolve['category_id'] ?? '','category_title_path_source_key'=>$categoryResolve['category_title_path_source_key'] ?? '','all_category_related_fields'=>$categoryDiagnostics['all_category_related_fields'],'resolved_full_ovoko_category_path'=>$categoryResolve['resolved_full_ovoko_category_path'] ?? '','category_resolution_method'=>$categoryResolve['category_resolution_method'] ?? '','category_resolution_confidence'=>$categoryResolve['category_resolution_confidence'] ?? 'low','parsed_category_levels'=>$levels,'planned_category_hierarchy'=>$hier,'planned_final_category'=>end($levels)?:'','categories_existing_by_parent_chain'=>$chainOk,'categories_that_would_be_created'=>$missing,'simulated_category_creation_sequence'=>$creationSequence,'categories_to_be_replaced'=>array_values(array_diff($currentIds,[$finalId])),'planned_action'=>$dryRun?'dry_run':'apply','errors'=>[],'warnings'=>[],'category_update_verified'=>false,'category_assignment_changed'=>false,'debug'=>$debug];
        if($dryRun){return $result;}
        if(!$chainOk || $finalId<=0){$counts['products_skipped']++;$counts['errors']++;$result['ok']=false;$result['reason']='category_hierarchy_unresolved';return $result;}
        $setIds=$replaceExisting?[$finalId]:array_values(array_unique(array_merge($currentIds,[$finalId])));
        sort($currentIds);
        $sortedSetIds = $setIds;
        sort($sortedSetIds);
        $result['category_assignment_changed'] = $currentIds !== $sortedSetIds;
        if (!empty($result['category_assignment_changed'])) { $counts['category_assignments_changed'] = (int) ($counts['category_assignments_changed'] ?? 0) + 1; }
        $result['category_assignment_attempted']=true;
        $set=wp_set_post_terms($productId,$setIds,'product_cat',false);
        if(is_wp_error($set)){$counts['errors']++;$result['ok']=false;$result['reason']='product_category_assign_failed';$result['error']=$set->get_error_message();return $result;}
        $counts['products_categories_updated']++;
        $afterIds=array_map('intval',wp_get_post_terms($productId,'product_cat',['fields'=>'ids']));
        $verified=in_array($finalId,$afterIds,true);
        $result['assigned_term_ids_after']=$afterIds;
        $result['category_update_verified']=$verified;
        if($verified){
            $deepTerm=get_term($finalId,'product_cat');
            $parentTerm=$deepTerm && !is_wp_error($deepTerm) ? get_term((int) $deepTerm->parent,'product_cat') : null;
            $grandParentTerm=$parentTerm && !is_wp_error($parentTerm) ? get_term((int) $parentTerm->parent,'product_cat') : null;
            $result['assigned_category_verification']=[
                'assigned_category_name'=>$deepTerm && !is_wp_error($deepTerm) ? (string) $deepTerm->name : '',
                'assigned_category_parent_name'=>$parentTerm && !is_wp_error($parentTerm) ? (string) $parentTerm->name : '',
                'assigned_category_grandparent_name'=>$grandParentTerm && !is_wp_error($grandParentTerm) ? (string) $grandParentTerm->name : '',
            ];
        }
        if($verified){
            $this->set_primary_product_category($productId, $finalId);
            update_post_meta($productId, '_gpswiss_ovoko_assigned_category_path', (string) $path);
            update_post_meta($productId, '_gpswiss_ovoko_assigned_category_id', (string) ($categoryResolve['category_id'] ?? ''));
            $counts['products_categories_verified']++;
        } else {$counts['errors']++;}
        return $result;
    }


    private function add_pending_parent_plan_fields(array $item, array $pendingParent, int $levelIndex, int $parentOvokoCategoryId): array
    {
        $plannedParentLevelIndex = (int) ($pendingParent['level_index'] ?? max(1, $levelIndex - 1));
        $plannedParentName = (string) ($pendingParent['name'] ?? '');
        $plannedParentOvokoCategoryId = $parentOvokoCategoryId > 0 ? $parentOvokoCategoryId : (int) ($pendingParent['ovoko_category_id'] ?? 0);
        $plannedParentRef = (string) ($pendingParent['pending_ref'] ?? 'pending:level_' . $plannedParentLevelIndex);

        $item['parent_id'] = null;
        $item['parent_pending_create'] = true;
        $item['parent_pending_ref'] = $plannedParentRef;
        $item['parent_resolved_after_create'] = true;
        $item['planned_parent_level_index'] = $plannedParentLevelIndex;
        $item['planned_parent_name'] = $plannedParentName;
        $item['planned_parent_ovoko_category_id'] = $plannedParentOvokoCategoryId;
        $item['planned_parent_ref'] = $plannedParentRef;
        $item['parent_name'] = $plannedParentName;
        $item['parent_ovoko_category_id'] = $plannedParentOvokoCategoryId;
        $item['parent_level_index'] = $plannedParentLevelIndex;

        return $item;
    }

    private function build_category_creation_sequence_preview(array $hierarchy, array $levels, int $finalId): array
    {
        $steps = [];
        $createdRefsByLevel = [];

        foreach ($hierarchy as $item) {
            if (!is_array($item)) {
                continue;
            }
            $levelIndex = (int) ($item['level_index'] ?? 0);
            if ($levelIndex <= 0) {
                continue;
            }

            $exists = !empty($item['exists']);
            $step = [
                'step' => $levelIndex,
                'level_index' => $levelIndex,
                'action' => $exists ? 'existing' : 'would_create',
                'name' => (string) ($item['name'] ?? ''),
                'ovoko_category_id' => (int) ($item['ovoko_category_id'] ?? 0),
            ];

            if ($exists) {
                $step['term_id'] = (int) ($item['term_id'] ?? 0);
                $step['parent_id'] = (int) ($item['parent_id'] ?? 0);
            } elseif (!empty($item['parent_pending_create'])) {
                $parentLevel = (int) ($item['planned_parent_level_index'] ?? $item['parent_level_index'] ?? max(1, $levelIndex - 1));
                $step['parent_id'] = null;
                $step['parent_ref'] = $createdRefsByLevel[$parentLevel] ?? ('created_step_' . $parentLevel);
                $step['parent_resolved_after_create'] = true;
                $step['planned_parent_level_index'] = $parentLevel;
                $step['planned_parent_name'] = (string) ($item['planned_parent_name'] ?? $item['parent_name'] ?? '');
                $step['planned_parent_ovoko_category_id'] = (int) ($item['planned_parent_ovoko_category_id'] ?? $item['parent_ovoko_category_id'] ?? 0);
            } else {
                $step['parent_id'] = (int) ($item['parent_id'] ?? 0);
            }

            if (!$exists) {
                $createdRefsByLevel[$levelIndex] = 'created_step_' . $levelIndex;
                $step['creates_ref'] = $createdRefsByLevel[$levelIndex];
            }

            $steps[] = $step;
        }

        $lastLevel = end($levels);
        $finalLevel = is_array($lastLevel) ? (int) (($lastLevel['level_index'] ?? count($levels))) : count($levels);
        $finalStep = [
            'step' => 'final_assign',
            'action' => 'final_assign',
            'level_index' => $finalLevel,
            'set_primary_category_level_index' => $finalLevel,
        ];
        if (isset($createdRefsByLevel[$finalLevel])) {
            $finalStep['term_ref'] = $createdRefsByLevel[$finalLevel];
        } elseif ($finalId > 0) {
            $finalStep['term_id'] = $finalId;
        } else {
            $finalStep['term_ref'] = 'level_' . $finalLevel;
        }
        $steps[] = $finalStep;

        return $steps;
    }

    private function extract_category_levels_from_parent_chain_or_path(array $chain, string $fallbackPath): array
    {
        $levels = [];
        if ($chain !== []) {
            foreach ($chain as $i => $node) {
                if (!is_array($node)) { continue; }
                $name = trim((string) ($node['pl'] ?? $node['name'] ?? ''));
                if ($name === '') { continue; }
                $levels[] = [
                    'name' => $name,
                    'ovoko_category_id' => (int) ($node['id'] ?? 0),
                    'parent_ovoko_category_id' => (int) ($node['parent_id'] ?? 0),
                    'level_index' => $i + 1,
                ];
            }
        }
        if ($levels === []) {
            $flat = array_values(array_filter(array_map('trim', explode('/', $fallbackPath)), fn($v) => $v !== ''));
            $flat = $this->normalize_ovoko_part_category_path_segments($flat);
            foreach ($flat as $i => $name) {
                $levels[] = ['name' => (string) $name, 'ovoko_category_id' => 0, 'parent_ovoko_category_id' => 0, 'level_index' => $i + 1];
            }
        }
        return $levels;
    }

    private function normalize_ovoko_part_category_path_segments(array $segments): array
    {
        $segments = array_values(array_filter(array_map(static fn($v) => trim((string) $v), $segments), static fn($v) => $v !== ''));
        if ($segments !== [] && in_array(mb_strtolower((string) end($segments)), ['produkt', 'product'], true)) {
            array_pop($segments);
        }
        if ($segments !== [] && mb_strtolower((string) $segments[0]) === 'motoryzacja') {
            array_shift($segments);
        }
        $knownPartRoots = ['szyby','drzwi','silnik','skrzynia biegów','karoseria','zawieszenie','układ hamulcowy','elektryka','oświetlenie','wnętrze','koła','opony','wydech','chłodzenie','paliwo','klimatyzacja'];
        foreach ($segments as $index => $segment) {
            if (in_array(mb_strtolower($segment), $knownPartRoots, true)) {
                return array_values(array_slice($segments, $index));
            }
        }
        return $segments;
    }

    private function build_ovoko_category_diagnostics(array $payload, array $record, array $normalized, ?array $categorySnapshot = null, bool $includeEndpointProbe = true): array
    {
        $allCategoryRelatedFields = $this->collect_matching_nested_fields_any($payload, ['category', 'path', 'breadcrumb']);
        $fragment = [];
        foreach ($allCategoryRelatedFields as $path => $value) {
            $fragment[$path] = $value;
        }
        $categoryId = (string) ($record['category_id'] ?? ($normalized['category_id'] ?? ''));
        $resolved = $this->resolve_ovoko_category_title_path($payload, $record, $normalized);
        $resolved['category_id'] = $categoryId;
        $resolved['category_id_path'] = (string) ($this->first_non_empty_value_from_paths($payload, ['category_id_path','data.category_id_path','part.category_id_path']) ?? '');
        $resolved['category_resolution_method'] = $resolved['category_title_path_source_key'] !== '' ? 'payload_field_direct' : 'not_resolved';
        $resolved['category_resolution_confidence'] = $resolved['resolved_full_ovoko_category_path'] !== '' ? 'medium' : 'low';
        $client = $this->build_rrr_api_client();
        $endpointProbe = null;
        $treeResolution = null;
        if ($categorySnapshot !== null && !empty($categorySnapshot['map']) && $client !== null) {
            $treeResolution = $client->resolve_category_path_from_map((int) $categoryId, (array) $categorySnapshot['map']);
        } elseif ($includeEndpointProbe && $client !== null) {
            $endpointProbe = $client->probe_category_endpoints((int) $categoryId);
            $treeResolution = $client->resolve_category_path_from_tree((int) $categoryId);
        }
        $partsCategoriesResolution = null;
        if (is_array($treeResolution)) {
            $resolved['category_tree_endpoint_used'] = (string) ($treeResolution['category_tree_endpoint_used'] ?? '');
            $resolved['category_tree_node_found'] = !empty($treeResolution['category_tree_node_found']);
            $resolved['category_tree_node'] = $treeResolution['category_tree_node'] ?? null;
            $resolved['category_tree_parent_chain'] = $treeResolution['category_tree_parent_chain'] ?? [];
            $resolved['category_tree_resolved_path'] = (string) ($treeResolution['category_tree_resolved_path'] ?? '');
            if (!empty($treeResolution['ok']) && trim((string) ($treeResolution['category_tree_resolved_path'] ?? '')) !== '') {
                $resolved['resolved_full_ovoko_category_path'] = trim((string) $treeResolution['category_tree_resolved_path']);
                $resolved['category_resolution_method'] = 'get_categories_tree_by_id';
                $resolved['category_resolution_confidence'] = 'high';
            } else {
                $resolved['category_resolution_method'] = 'ovoko_category_tree_resolution_failed';
                $resolved['category_resolution_confidence'] = 'low';
            }
        }
        $resolved['categories_endpoint_used'] = (string) ($resolved['category_tree_endpoint_used'] ?? '');
        $resolved['categories_payload_shape'] = $treeResolution['categories_payload_shape'] ?? [];
        $resolved['categories_sample_records'] = $treeResolution['categories_sample_records'] ?? [];
        $resolved['categories_sample_keys'] = $treeResolution['categories_sample_keys'] ?? [];
        $resolved['category_target_id'] = $treeResolution['category_target_id'] ?? (int) $categoryId;
        $resolved['category_target_search_performed'] = $treeResolution['category_target_search_performed'] ?? true;
        $resolved['category_target_found'] = $treeResolution['category_target_found'] ?? null;
        $resolved['category_target_node_if_found'] = $treeResolution['category_target_node_if_found'] ?? null;
        $resolved['category_target_nearby_records'] = $treeResolution['category_target_nearby_records'] ?? [];
        $resolved['category_target_parent_chain'] = $treeResolution['category_target_parent_chain'] ?? [];
        $resolved['category_target_resolved_path'] = (string) ($treeResolution['category_target_resolved_path'] ?? '');
        $resolved['categories_total_loaded'] = $treeResolution['categories_total_loaded'] ?? 0;
        $resolved['category_id_322_search_performed'] = $treeResolution['category_id_322_search_performed'] ?? (((int) $categoryId) === 322);
        $resolved['category_id_322_found'] = $treeResolution['category_id_322_found'] ?? null;
        $resolved['category_id_322_node_if_found'] = $treeResolution['category_id_322_node_if_found'] ?? null;
        $resolved['category_id_322_nearby_records'] = $treeResolution['category_id_322_nearby_records'] ?? [];
        $resolved['count_nodes_with_id_field'] = $treeResolution['count_nodes_with_id_field'] ?? 0;
        $resolved['min_category_id'] = $treeResolution['min_category_id'] ?? null;
        $resolved['max_category_id'] = $treeResolution['max_category_id'] ?? null;
        $resolved['category_322_found_in_categories_endpoint'] = ((int) $categoryId === 322) ? !empty($resolved['category_tree_node_found']) : null;
        $resolved['category_322_node'] = ((int) $categoryId === 322) ? ($resolved['category_tree_node'] ?? null) : null;
        $resolved['category_322_parent_chain'] = ((int) $categoryId === 322) ? ($resolved['category_tree_parent_chain'] ?? []) : [];
        $resolved['category_322_resolved_path'] = ((int) $categoryId === 322) ? (string) ($resolved['category_tree_resolved_path'] ?? '') : '';
        return [
            'resolution' => $resolved,
            'all_category_related_fields' => $allCategoryRelatedFields,
            'full_category_related_payload_fragment' => $fragment,
            'category_endpoint_probe' => $endpointProbe,
            'category_tree_payload_fragment' => $treeResolution['category_tree_payload_fragment'] ?? [],
            'parts_categories_payload_fragment' => $partsCategoriesResolution['parts_categories_records_matching_category_id'] ?? [],
            'categories_endpoints_debug' => $treeResolution['categories_endpoints_debug'] ?? [],
        ];
    }

    private function build_rrr_api_client(): ?RrrApiClient
    {
        try {
            return new RrrApiClient($this->get_settings());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolve_ovoko_category_title_path(array $payload, array $record, array $normalized): array
    {
        $candidates = [
            'category_title_path' => $record['category_title_path'] ?? null,
            'data.category_title_path' => is_array($payload['data'] ?? null) ? (($payload['data']['category_title_path'] ?? null)) : null,
            'part.category_title_path' => is_array($payload['part'] ?? null) ? (($payload['part']['category_title_path'] ?? null)) : null,
            'list.0.0.category_title_path' => $payload['list'][0][0]['category_title_path'] ?? null,
            'list.0.category_title_path' => $payload['list'][0]['category_title_path'] ?? null,
            'category.title_path' => is_array($payload['category'] ?? null) ? (($payload['category']['title_path'] ?? null)) : null,
            'category.path' => is_array($payload['category'] ?? null) ? (($payload['category']['path'] ?? null)) : null,
        ];

        // Source of truth should come from the same normalized part record as descriptions.
        if (trim((string) ($normalized['category_title_path'] ?? '')) !== '') {
            $candidates = ['category_title_path' => $normalized['category_title_path']] + $candidates;
        }

        foreach ($candidates as $sourceKey => $value) {
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return [
                    'resolved_full_ovoko_category_path' => $trimmed,
                    'category_title_path_source_key' => $sourceKey,
                    'raw_category_title_path' => $value,
                    'checked_paths' => array_keys($candidates),
                ];
            }
        }

        return [
            'resolved_full_ovoko_category_path' => '',
            'category_title_path_source_key' => '',
            'raw_category_title_path' => null,
            'checked_paths' => array_keys($candidates),
        ];
    }

    private function collect_matching_nested_fields_any(array $payload, array $needles): array
    {
        $flat = $this->flatten_nested_fields($payload, 8);
        $matches = [];
        foreach ($flat as $path => $value) {
            $lower = strtolower($path);
            foreach ($needles as $needle) {
                if (str_contains($lower, strtolower($needle))) {
                    $matches[$path] = $value;
                    break;
                }
            }
        }
        return $matches;
    }

    private function flatten_nested_fields(array $payload, int $maxDepth = 8, string $prefix = '', int $depth = 1): array
    {
        if ($depth > $maxDepth) { return []; }
        $out = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) { $out += $this->flatten_nested_fields($value, $maxDepth, $path, $depth + 1); }
            else { $out[$path] = is_scalar($value) ? (string) $value : gettype($value); }
        }
        return $out;
    }

    private function first_non_empty_value_from_paths(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $node = $payload;
            foreach (explode('.', $path) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) { $node = null; break; }
                $node = $node[$part];
            }
            $val = trim((string) $node);
            if ($val !== '') { return $val; }
        }
        return null;
    }

    private function collect_nested_keys_to_depth(array $value, int $maxDepth = 4, string $prefix = '', int $depth = 1): array
    {
        if ($depth > $maxDepth) {
            return [];
        }
        $paths = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            $paths[] = $path;
            if (is_array($child)) {
                $paths = array_merge($paths, $this->collect_nested_keys_to_depth($child, $maxDepth, $path, $depth + 1));
            }
        }
        return array_values(array_unique($paths));
    }

    private function collect_matching_nested_fields(array $payload, array $needles): array
    {
        $allPaths = $this->collect_nested_keys_to_depth($payload, 8);
        $matches = [];
        foreach ($allPaths as $path) {
            $lower = strtolower($path);
            $ok = true;
            foreach ($needles as $needle) {
                if (strpos($lower, strtolower($needle)) === false) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $matches[] = $path;
            }
        }
        return $matches;
    }

    private function normalize_description_text_for_compare(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\s*\/\s*p\s*>/iu', "\n", $normalized) ?? $normalized;
        $normalized = wp_strip_all_tags($normalized);
        $normalized = strtolower($normalized);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function extract_ovoko_listing_text(array $payload): array
    {
        $candidates = [
            'description','description_text','advert_description','advertisement_description','announcement_description','listing_description',
            'listing_text','text','content','comment','notes','note','public_description','part_description','seller_description',
            'ad_text','ad_description','announcement_text','description_for_sale','sale_description','advert_text','name'
        ];

        $best = ['source_key' => '', 'text' => ''];
        $walker = static function ($node, string $path = '') use (&$walker, $candidates, &$best): void {
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                $k = (string) $key;
                $nextPath = $path === '' ? $k : $path . '.' . $k;
                if (is_array($value)) {
                    $walker($value, $nextPath);
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $normalizedKey = strtolower($k);
                $stringValue = trim((string) $value);
                if ($stringValue === '') {
                    continue;
                }
                if (in_array($normalizedKey, $candidates, true)) {
                    if ($best['text'] === '' || mb_strlen($stringValue) > mb_strlen($best['text'])) {
                        $best = ['source_key' => $nextPath, 'text' => sanitize_textarea_field($stringValue)];
                    }
                }
            }
        };
        $walker($payload, '');

        return $best;
    }

    private function build_ovoko_listing_text_debug(array $payload, bool $showFullPayload): array
    {
        $maxDepth = 4;
        $topLevelKeys = array_values(array_map('strval', array_keys($payload)));
        $nestedKeys = [];
        $textFields = [];
        $phrases = ['SILNIK KOMPLETNY', 'PRZEBIEG', 'PERFECT', 'CENA ZA SILNIK'];
        $phraseMatches = [];

        $walk = static function ($node, string $path = '', int $depth = 0) use (&$walk, $maxDepth, &$nestedKeys, &$textFields, $phrases, &$phraseMatches): void {
            if ($depth > $maxDepth || !is_array($node)) return;
            foreach ($node as $key => $value) {
                $k = (string) $key;
                $nextPath = $path === '' ? $k : $path . '.' . $k;
                $nestedKeys[] = $nextPath;
                if (is_array($value)) {
                    $walk($value, $nextPath, $depth + 1);
                    continue;
                }
                if (!is_scalar($value)) continue;
                $text = trim((string) $value);
                if (mb_strlen($text) > 10) {
                    $textFields[] = ['path' => $nextPath, 'value' => $text, 'length' => mb_strlen($text)];
                }
                $textUpper = mb_strtoupper($text);
                foreach ($phrases as $phrase) {
                    if ($text !== '' && mb_strpos($textUpper, $phrase) !== false) {
                        $phraseMatches[] = ['found_phrase' => true, 'phrase' => $phrase, 'path' => $nextPath, 'value' => $text];
                    }
                }
            }
        };
        $walk($payload, '', 0);

        $integrationMethods = array_values(array_filter(get_class_methods(RrrApiClient::class), static fn($m) => str_starts_with($m, 'preview_') || str_starts_with($m, 'fetch_') || str_contains($m, 'part')));

        return [
            'show_full_ovoko_part_payload_for_description_debug' => $showFullPayload,
            'top_level_keys' => $topLevelKeys,
            'nested_keys_depth_4' => $nestedKeys,
            'string_fields_length_gt_10' => $textFields,
            'phrase_search' => [
                'phrases' => $phrases,
                'found_phrase' => !empty($phraseMatches),
                'matches' => $phraseMatches,
            ],
            'integration_part_fetch_methods_found' => $integrationMethods,
            'endpoint_diagnostic_hint' => 'If /get/part/{id} lacks listing text, likely fetched in Ovoko SPA/private endpoint; inspect browser network for fields labeled Tekst ogłoszenia / Opis (treść ogłoszenia).',
            'full_payload' => $showFullPayload ? $payload : null,
        ];
    }

    private function build_vehicle_label_from_attributes(array $attrs): string
    {
        $parts = [];
        foreach (['Producent', 'Model'] as $k) { if (!empty($attrs[$k])) { $parts[] = trim((string) $attrs[$k]); } }
        if (!empty($attrs['Pojemność silnika'])) {
            $parts[] = $this->normalize_vehicle_engine_for_slug((string) $attrs['Pojemność silnika'], false);
        }
        if (!empty($attrs['Rodzaj paliwa'])) { $parts[] = trim((string) $attrs['Rodzaj paliwa']); }
        return trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($parts))) ?? '');
    }

    private function normalize_vehicle_engine_for_slug(string $value, bool $forSlug = true): string
    {
        $decoded = html_entity_decode((string) rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/\s+/', ' ', $decoded ?? '') ?? '';
        if (preg_match('/(\d{3,5})\s*(?:cm(?:3|³)|ccm|cc)\b/ui', $decoded, $m)) {
            $cc = (int) $m[1];
            if ($cc >= 950 && $cc <= 9999) {
                $liters = number_format(round($cc / 1000, 1), 1, '.', '');
                return $forSlug ? str_replace('.', '-', $liters) : $liters;
            }
        }
        return $forSlug ? sanitize_title($decoded) : trim($decoded);
    }

    private function normalize_vehicle_slug(string $label): string
    {
        $decoded = html_entity_decode((string) rawurldecode($label), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = preg_replace('/%[0-9a-f]{2}/i', ' ', $decoded ?? '') ?? '';
        $decoded = preg_replace('/\b(\d{3,5})\s*(?:cm(?:3|³)|ccm|cc)\b/ui', '$1', $decoded ?? '') ?? '';
        $decoded = str_replace(['³', '²', '¹'], '', $decoded);
        if (preg_match('/\b(\d{3,5})\b/u', $decoded, $m) && preg_match('/\b(?:cm(?:3|³)|ccm|cc)\b/ui', $label)) {
            $engine = $this->normalize_vehicle_engine_for_slug($m[1] . ' cc', true);
            if ($engine !== '') {
                $decoded = preg_replace('/\b'.preg_quote($m[1], '/').'\b/u', str_replace('-', ' ', $engine), $decoded, 1) ?? $decoded;
            }
        }
        $slug = sanitize_title($decoded);
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug ?? '') ?? '';
        return trim((string) preg_replace('/-+/', '-', $slug), '-');
    }

    private function build_unique_vehicle_slug(int $productId, string $carId, string $label, string $year): string
    {
        $base = $this->normalize_vehicle_slug($label);
        if ($base === '') { $base = 'pojazd'; }
        $candidates = [$base];
        $yearClean = preg_replace('/[^0-9]/', '', $year) ?? '';
        if ($yearClean !== '') { $candidates[] = $base . '-' . $yearClean; }
        if ($carId !== '') { $candidates[] = $base . '-' . sanitize_title($carId); }
        foreach ($candidates as $candidate) {
            $existing = get_posts(['post_type'=>'product','post_status'=>['publish','private'],'posts_per_page'=>1,'fields'=>'ids','post__not_in'=>[$productId],'meta_query'=>[['key'=>'_gpswiss_vehicle_slug','value'=>$candidate,'compare'=>'=']]]);
            if (empty($existing)) { return $candidate; }
        }
        return $base . '-' . wp_generate_password(4, false, false);
    }

    private function memory_limit_mb(): int
    {
        $raw = strtolower(trim((string) ini_get('memory_limit')));
        if ($raw === '' || $raw === '-1') { return -1; }
        $value = (float) preg_replace('/[^0-9.]/', '', $raw);
        if (str_ends_with($raw, 'g')) { return (int) round($value * 1024); }
        if (str_ends_with($raw, 'k')) { return (int) round($value / 1024); }
        return (int) round($value);
    }

    private function build_apply_guard_status(array $memoryStages = []): array
    {
        $limitRaw = (string) ini_get('memory_limit');
        $limitMb = $this->memory_limit_mb();
        $beforeWriteMb = round(memory_get_usage(true) / 1048576, 2);
        $ratioBeforeWrite = ($limitMb > 0) ? round($beforeWriteMb / $limitMb, 4) : 0.0;
        $afterPartFetchMb = 0.0;
        foreach ($memoryStages as $stage) {
            if ((string) ($stage['stage'] ?? '') === 'after_api_part_fetch') {
                $afterPartFetchMb = (float) ($stage['memory_usage_mb'] ?? 0);
                break;
            }
        }
        if ($limitMb > 0 && $limitMb <= 128 && ($afterPartFetchMb > 115 || $beforeWriteMb > 115)) {
            return ['apply_allowed' => false, 'apply_blocked_reason' => 'memory_limit_too_low', 'recommended_memory_limit' => 256, 'memory_limit' => $limitRaw, 'memory_limit_mb' => $limitMb, 'memory_usage_ratio_before_write' => $ratioBeforeWrite, 'before_write_mb' => $beforeWriteMb];
        }
        if ($limitMb >= 256 && $beforeWriteMb < 200) {
            return ['apply_allowed' => true, 'apply_blocked_reason' => '', 'recommended_memory_limit' => null, 'memory_limit' => $limitRaw, 'memory_limit_mb' => $limitMb, 'memory_usage_ratio_before_write' => $ratioBeforeWrite, 'before_write_mb' => $beforeWriteMb];
        }
        if ($limitMb > 0 && $beforeWriteMb > round($limitMb * 0.8, 2)) {
            return ['apply_allowed' => false, 'apply_blocked_reason' => 'memory_guard_before_write', 'recommended_memory_limit' => 256, 'memory_limit' => $limitRaw, 'memory_limit_mb' => $limitMb, 'memory_usage_ratio_before_write' => $ratioBeforeWrite, 'before_write_mb' => $beforeWriteMb];
        }
        $recommended = ($limitMb > 0 && $limitMb < 256) ? 256 : null;
        return ['apply_allowed' => true, 'apply_blocked_reason' => '', 'recommended_memory_limit' => $recommended, 'memory_limit' => $limitRaw, 'memory_limit_mb' => $limitMb, 'memory_usage_ratio_before_write' => $ratioBeforeWrite, 'before_write_mb' => $beforeWriteMb];
    }

    private function check_memory_guard_before_enrichment(int $guardMb): array
    {
        $usageMb = round(memory_get_usage(true) / 1048576, 2);
        $limitRaw = (string) ini_get('memory_limit');
        $limitMb = $this->memory_limit_mb();
        $thresholdMb = (float) $guardMb;

        if ($limitMb > 0 && $limitMb <= 128) {
            $thresholdMb = 115.0;
        } elseif ($limitMb >= 256) {
            $thresholdMb = max(200.0, round($limitMb * 0.8, 2));
        } elseif ($limitMb > 0) {
            $thresholdMb = round($limitMb * 0.8, 2);
        }

        $ratio = $limitMb > 0 ? round($usageMb / $limitMb, 4) : 0.0;
        if ($usageMb <= $thresholdMb) { return []; }

        return [
            'ok' => false,
            'error' => 'memory_guard_before_enrichment',
            'memory_usage_mb' => $usageMb,
            'memory_limit' => $limitRaw,
            'memory_limit_mb' => $limitMb,
            'memory_usage_ratio' => $ratio,
            'guard_threshold_mb' => $thresholdMb,
            'guard_context' => 'before_enrichment',
            'suggestion' => 'run via CLI or increase memory limit',
        ];
    }

    private function resolve_bulk_product_ids(array $options, int $offset, int $limit, int $batchSize, int $page, bool $includeExistingOvoko): array
    {
        $csv = trim((string) ($options['product_ids_csv'] ?? ''));
        if ($csv !== '') { return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $csv))))); }

        $fastScan = !array_key_exists('fast_scan', $options) || !empty($options['fast_scan']);
        $afterProductId = max(0, (int) ($options['after_product_id'] ?? $options['last_seen_product_id'] ?? 0));
        $scanLimit = max(1, min((int) ($options['scan_limit'] ?? $limit ?? $batchSize), 20));

        if ($fastScan) {
            return $this->scan_candidate_product_ids_keyset($afterProductId, $scanLimit, $includeExistingOvoko);
        }

        $perPage = min($batchSize, $limit, 5);
        $queryOffset = $offset > 0 ? $offset : (($page - 1) * $perPage);
        return get_posts(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>$perPage,'offset'=>$queryOffset,'no_found_rows'=>true,'orderby'=>'ID','order'=>'ASC']);
    }

    private function scan_candidate_product_ids_keyset(int $afterProductId, int $scanLimit, bool $includeExistingOvoko): array
    {
        global $wpdb;
        $postStatuses = ['publish', 'private', 'draft', 'pending', 'future'];
        $statusPlaceholders = implode(',', array_fill(0, count($postStatuses), '%s'));
        $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ({$statusPlaceholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
        $params = array_merge(['product'], $postStatuses, [$afterProductId, $scanLimit]);
        $candidateIds = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $params)));
        if ($candidateIds === []) { return []; }

        if ($includeExistingOvoko) { return $candidateIds; }

        $selected = [];
        foreach ($candidateIds as $productId) {
            if ((string) get_post_meta($productId, 'source', true) === 'ovoko_master') { continue; }
            $selected[] = $productId;
        }
        return $selected;
    }

    private function get_cached_csv_mapping_index(string $indexType = 'full'): array
    {
        $cacheKey = self::CSV_MAPPING_CACHE_TRANSIENT . '_' . ($indexType === 'lightweight' ? 'light' : 'full');
        $cached = get_transient($cacheKey);
        if (is_array($cached) && !empty($cached['index'])) { return $cached; }
        $csvMap = (array) ($this->get_settings()['ovoko_csv_mapping'] ?? []);
        $rows = 0; $duplicateCodes = 0;
        $index = [];
        foreach ($csvMap as $code => $list) {
            $items = (array) $list;
            $rows += count($items);
            if (count($items) > 1) { $duplicateCodes++; }
            if ($indexType === 'lightweight') {
                $index[(string) $code] = array_values(array_filter(array_map(static function ($row) {
                    $id = sanitize_text_field((string) (((array) $row)['id'] ?? ''));
                    return $id !== '' ? $id : null;
                }, $items)));
            } else {
                $index[(string) $code] = $items;
            }
        }
        $data = ['index' => $index, 'csv_mapping_rows' => $rows, 'unique_codes' => count($csvMap), 'duplicate_codes' => $duplicateCodes, 'index_type' => ($indexType === 'lightweight' ? 'lightweight' : 'full')];
        set_transient($cacheKey, $data, 10 * MINUTE_IN_SECONDS);
        return $data;
    }

    private function resolve_ovoko_match_for_allegro_product_csv_only(int $productId, array $csvIndex): array
    {
        $audit = $this->collect_allegro_product_context($productId, true);
        $allegroId = strtolower(trim((string) ($audit['allegro_offer_id'] ?? '')));
        if ($allegroId === '') {
            return ['match_confidence'=>'none','review_required'=>false,'matched_by'=>'missing_allegro_id','part_normalized'=>[]];
        }
        $matches = (array) ($csvIndex[$allegroId] ?? []);
        if (count($matches) !== 1) {
            return ['match_confidence'=>'none','review_required'=>count($matches) > 1,'matched_by'=>count($matches) > 1 ? 'ambiguous_allegro_id_in_csv' : 'csv_not_found_for_allegro_id','part_normalized'=>[]];
        }
        $row = (array) $matches[0];
        $ovokoId = (string) ($row['ovoko_id'] ?? $row['id'] ?? '');
        return ['match_confidence'=>'high','review_required'=>false,'matched_by'=>'csv_allegro_id_exact','matched_ovoko_part_id'=>$ovokoId,'part_normalized'=>['part_id'=>$ovokoId]];
    }

    public function bulk_diagnostics_ping(array $options = []): array
    {
        $started = microtime(true);
        $stages = [['stage'=>'handler_started','elapsed_ms'=>0,'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)], ['stage'=>'plugin_loaded','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)]];
        $csv = $this->get_cached_csv_mapping_index();
        $stages[] = ['stage'=>'csv_mapping_loaded','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];

        $q1 = microtime(true);
        $simpleIds = get_posts(['post_type'=>'product','post_status'=>'any','fields'=>'ids','posts_per_page'=>1,'no_found_rows'=>true,'orderby'=>'ID','order'=>'ASC']);
        $querySimpleMs = (int) round((microtime(true)-$q1)*1000);

        $q2 = microtime(true);
        $candidateIds = $this->resolve_bulk_product_ids(['batch_size'=>1,'limit'=>1,'offset'=>0,'page'=>1,'include_existing_ovoko'=>true,'fast_scan'=>true,'scan_limit'=>1] + $options, 0, 1, 1, 1, true);
        $queryCandidateMs = (int) round((microtime(true)-$q2)*1000);

        $queryByProductIdsMs = null;
        if (trim((string) ($options['product_ids_csv'] ?? '')) !== '') {
            $q3 = microtime(true);
            $idsFromCsv = $this->resolve_bulk_product_ids($options, 0, 1, 1, 1, true);
            $stages[] = ['stage'=>'product_ids_parsed','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'ids_count'=>count($idsFromCsv),'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
            if (!empty($idsFromCsv[0])) {
                $pid=(int)$idsFromCsv[0];
                $stages[] = ['stage'=>'before_product_meta_read','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'product_id'=>$pid,'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
                foreach (['_allegro_offer_id','_part_number','_mpn','mpn','_manufacturer_code','_gpswiss_part_number','_ovoko_part_id','_ovoko_car_id'] as $key) { get_post_meta($pid, $key, true); }
                $stages[] = ['stage'=>'after_product_meta_read','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'product_id'=>$pid,'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
                $this->resolve_ovoko_match_for_allegro_product_csv_only($pid, (array) ($csv['index'] ?? []));
                $stages[] = ['stage'=>'after_match','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'product_id'=>$pid,'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
            }
            $queryByProductIdsMs = (int) round((microtime(true)-$q3)*1000);
        }

        $stages[] = ['stage'=>'query_finished','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
        $stages[] = ['stage'=>'before_response','elapsed_ms'=>(int) round((microtime(true)-$started)*1000),'memory_usage'=>memory_get_usage(true),'memory_peak'=>memory_get_peak_usage(true)];
        return ['ok'=>true,'action_name'=>'Bulk diagnostics / ping','stages'=>$stages,'query_simple_no_meta_ms'=>$querySimpleMs,'query_current_candidate_ms'=>$queryCandidateMs,'query_by_product_ids_ms'=>$queryByProductIdsMs,'csv_mapping_status'=>['csv_mapping_rows'=>(int)($csv['csv_mapping_rows']??0),'unique_codes'=>(int)($csv['unique_codes']??0),'duplicate_codes'=>(int)($csv['duplicate_codes']??0)],'product_query_test'=>['ids'=>array_values(array_slice(array_map('intval',$simpleIds),0,1)),'candidate_ids'=>array_values(array_slice(array_map('intval',$candidateIds),0,5))],'memory_limit'=>ini_get('memory_limit'),'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2)];
    }

    private function capture_product_safety_snapshot(int $productId): array
    {
        return ['price'=>(string)get_post_meta($productId,'_price',true),'regular_price'=>(string)get_post_meta($productId,'_regular_price',true),'sale_price'=>(string)get_post_meta($productId,'_sale_price',true),'stock_quantity'=>(string)get_post_meta($productId,'_stock',true),'stock_status'=>(string)get_post_meta($productId,'_stock_status',true),'manage_stock'=>(string)get_post_meta($productId,'_manage_stock',true),'thumbnail_id'=>(string)get_post_thumbnail_id($productId),'thumbnail_meta'=>(string)get_post_meta($productId,'_thumbnail_id',true),'gallery'=>(string)get_post_meta($productId,'_product_image_gallery',true),'listing_image'=>(string)get_post_meta($productId,'_listing_image',true),'awi_listing_image_id'=>(string)get_post_meta($productId,'_awi_listing_image_id',true),'awi_listing_image_source_id'=>(string)get_post_meta($productId,'_awi_listing_image_source_id',true),'post_title'=>(string)get_the_title($productId),'post_status'=>(string)get_post_status($productId)];
    }

    private function compare_product_safety_snapshot(array $before, array $after): array
    {
        $imageChangedKeys = $this->diff_snapshot_keys($before, $after, $this->image_safety_keys());
        return ['no_price_change'=>($before['price']??'')===($after['price']??'') && ($before['regular_price']??'')===($after['regular_price']??'') && ($before['sale_price']??'')===($after['sale_price']??''),'no_stock_change'=>($before['stock_quantity']??'')===($after['stock_quantity']??'') && ($before['stock_status']??'')===($after['stock_status']??'') && ($before['manage_stock']??'')===($after['manage_stock']??''),'no_images_change'=>empty($imageChangedKeys),'no_title_change'=>($before['post_title']??'')===($after['post_title']??''),'no_status_change'=>($before['post_status']??'')===($after['post_status']??'')];
    }

    private function image_safety_keys(): array
    {
        return ['thumbnail_id', 'thumbnail_meta', 'gallery', 'listing_image', 'awi_listing_image_id', 'awi_listing_image_source_id'];
    }

    private function diff_snapshot_keys(array $before, array $after, array $keys): array
    {
        $changed = [];
        foreach ($keys as $key) {
            if ((string) ($before[$key] ?? '') !== (string) ($after[$key] ?? '')) {
                $changed[] = (string) $key;
            }
        }
        return $changed;
    }

    private function detect_best_csv_delimiter_and_header(string $sourcePath, array $delimiters): array
    {
        $best = ['score' => -1, 'ok' => false];
        foreach ($delimiters as $delimiter => $label) {
            $h = fopen($sourcePath, 'rb');
            if ($h === false) continue;
            $header = fgetcsv($h, 0, (string) $delimiter);
            fclose($h);
            if (!is_array($header) || count($header) === 0) continue;
            $raw = array_map(static fn($v) => (string) $v, $header);
            $normalized = array_map(fn($v) => $this->normalize_csv_header((string) $v), $raw);
            $nonEmpty = count(array_filter($normalized, static fn($v) => $v !== ''));
            $unique = count(array_unique($normalized));
            $score = ($nonEmpty * 10) + $unique;
            if ($score > (int) $best['score']) {
                $best = ['ok' => true, 'delimiter' => (string) $delimiter, 'delimiter_label' => $label, 'raw_headers' => $raw, 'normalized_headers' => $normalized, 'score' => $score];
            }
        }
        return $best;
    }

    private function normalize_csv_header(string $header): string
    {
        $h = preg_replace('/^\xEF\xBB\xBF/u', '', $header) ?? $header;
        $h = trim($h);
        $h = strtolower($h);
        $h = strtr($h, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
        $h = preg_replace('/["\'`]/u', '', $h) ?? $h;
        $h = preg_replace('/[^a-z0-9_\s]/u', ' ', $h) ?? $h;
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;
        return trim($h);
    }

    private function find_header_by_aliases(array $normalizedHeaders, array $aliases): ?int
    {
        $normalizedAliases = array_map(fn($a) => $this->normalize_csv_header((string) $a), $aliases);
        foreach ($normalizedHeaders as $i => $header) {
            if (in_array((string) $header, $normalizedAliases, true)) return (int) $i;
        }
        return null;
    }

    private function value_from_assoc_by_normalized_header(array $assoc, array $normalizedHeaders, array $aliases): string
    {
        $index = $this->find_header_by_aliases($normalizedHeaders, $aliases);
        if ($index === null) return '';
        $keys = array_keys($assoc);
        $key = (string) ($keys[$index] ?? '');
        return (string) ($assoc[$key] ?? '');
    }

    private function short_make(string $make): string { $m=trim($make); return match($m){'Volkswagen'=>'VW','Mercedes-Benz'=>'Mercedes',default=>$m}; }



    private function normalize_part_code(string $value): string
    {
        $v = strtoupper(str_replace(' ', '', trim($value)));
        if (preg_match('/^[0-9]+\.0$/', $v)) { $v = substr($v, 0, -2); }
        return $v;
    }

    private function build_normalized_from_csv_row(array $csvRow): array
    {
        $vehicleParsed = $this->parse_vehicle_info_from_csv((string) ($csvRow['vehicle_info'] ?? ''));
        return ['part_id'=>(string)($csvRow['id']??''),'manufacturer_code'=>(string)($csvRow['manufacturer_code']??''),'vehicle_make'=>(string)($vehicleParsed['manufacturer']??''),'vehicle_model'=>(string)($vehicleParsed['model']??''),'vehicle_generation'=>(string)($vehicleParsed['model_modification']??''),'vehicle_year'=>(string)(($csvRow['year']??'')?:($vehicleParsed['year']??'')),'vehicle_fuel'=>(string)($csvRow['fuel_type']??''),'vehicle_engine_capacity_cc'=>(string)($vehicleParsed['engine_capacity_cc']??''),'vehicle_engine_power_kw'=>(string)($vehicleParsed['engine_power_kw']??''),'vehicle_gearbox_type'=>(string)($csvRow['gearbox_type']??''),'vehicle_drive_wheels'=>(string)($csvRow['drive']??''),'vehicle_color'=>(string)($csvRow['color']??''),'vehicle_period'=>(string)($vehicleParsed['period']??''),'vehicle_raw_info'=>(string)($vehicleParsed['raw_value']??''),'vehicle_parse_confidence'=>(string)($vehicleParsed['confidence']??'low'),'csv_parse_debug'=>(array)$vehicleParsed];
    }

    private function parse_vehicle_info_from_csv(string $vehicleInfo): array
    {
        $raw=trim($vehicleInfo); $result=['raw_value'=>$raw,'confidence'=>'low']; if($raw==='') return $result;
        preg_match('/^([^,(]+)(?:\s*\(([^)]*)\))?(?:,\s*(\d{4}))?/u', $raw, $base);
        $label = trim((string)($base[1] ?? '')); $period = trim((string)($base[2] ?? '')); $year = trim((string)($base[3] ?? ''));
        $period = preg_replace('/\s*-\s*/u', '-', $period) ?? $period;
        $period = str_replace('-)', '--', $period);
        $period = str_replace(['(',')',' '], '', $period);
        if (preg_match('/^\d{4}-$/', $period)) { $period .= '-'; }
        preg_match('/(\d+)\s*k[wW]/u', $raw, $power); preg_match('/(\d+)\s*cm(?:3|³)/u', $raw, $cap);
        $known = ['Mercedes-Benz','Volkswagen','VW','Audi','BMW','Peugeot','Citroen','Citroën','Renault','Opel','Ford','Toyota','Nissan','Hyundai','Kia','Fiat','Volvo','Skoda','Škoda','Seat'];
        $make=''; $model=$label;
        foreach ($known as $brand) { if (stripos($label, $brand) === 0) { $make = $brand; $model = trim(substr($label, strlen($brand))); break; } }
        $confidence = ($make!=='' && $year!=='' && !empty($power[1]) && !empty($cap[1])) ? 'high' : (($label!=='' && ($year!=='' || !empty($power[1]) || !empty($cap[1]))) ? 'medium' : 'low');
        return ['raw_value'=>$raw,'confidence'=>$confidence,'manufacturer'=>$make,'model'=>$model,'model_modification'=>$model,'period'=>$period,'year'=>$year,'engine_power_kw'=>(string)($power[1] ?? ''),'engine_capacity_cc'=>(string)($cap[1] ?? '')];
    }

    public function preview_ovoko_technical_attributes_from_normalized(array $normalized): array
    {
        return $this->build_ovoko_technical_attributes_from_normalized($normalized);
    }

    private function build_ovoko_technical_attributes_from_normalized(array $normalized): array
    {
        return array_filter([
            'Numer części' => (string) ($normalized['manufacturer_code'] ?? ''),
            'Kod widoczny' => (string) ($normalized['visible_code'] ?? ''),
            'Inny kod części' => (string) ($normalized['other_code'] ?? ''),
            'ID części Ovoko' => (string) ($normalized['part_id'] ?? ''),
            'ID pojazdu Ovoko' => (string) (($normalized['car_id'] ?? '') ?: ($normalized['vehicle_id'] ?? '')),
            'Producent' => (string) ($normalized['vehicle_make'] ?? ''),
            'Model' => (string) ($normalized['vehicle_model'] ?? ''),
            'Modyfikacja' => (string) ($normalized['vehicle_generation'] ?? ''),
            'Rodzaj paliwa' => (string) ($normalized['vehicle_fuel'] ?? ''),
            'Pojemność silnika' => !empty($normalized['vehicle_engine_capacity_cc']) ? ((string) $normalized['vehicle_engine_capacity_cc']) . ' cm³' : '',
            'Moc silnika' => !empty($normalized['vehicle_engine_power_kw']) ? ((string) $normalized['vehicle_engine_power_kw']) . ' kW' : '',
            'Kod silnika' => (string) ($normalized['vehicle_engine_code'] ?? ''),
            'Typ skrzyni biegów' => (string) ($normalized['vehicle_gearbox_type'] ?? ''),
            'Typ sylwetki' => (string) ($normalized['vehicle_body_type'] ?? ''),
            'Koła napędowe' => (string) ($normalized['vehicle_drive_wheels'] ?? ''),
            'Pozycja kierownicy' => (string) ($normalized['vehicle_steering_position'] ?? ''),
            'Kolor' => (string) ($normalized['vehicle_color'] ?? ''),
            'Kod koloru' => (string) ($normalized['vehicle_color_code'] ?? ''),
            'Okres' => (string) ($normalized['vehicle_period'] ?? ''),
            'Rok produkcji samochodu' => (string) ($normalized['vehicle_year'] ?? ''),
            'Przebieg' => !empty($normalized['mileage_km']) ? ((string) $normalized['mileage_km']) . ' km' : '',
            'Kategoria Ovoko' => (string) ($normalized['category_title_path'] ?? ''),
            'Stan' => (string) (($normalized['quality'] ?? '') ?: ''),
            'Status' => (string) ($normalized['status'] ?? ''),
            'Pozycja' => (string) ($normalized['position'] ?? ''),
            'Źródło' => 'Ovoko / RRR',
        ], static fn($v) => $v !== '');
    }

    private function upsert_custom_product_attributes(int $productId, array $technicalAttributes): void
    {
        $existing = (array) get_post_meta($productId, '_product_attributes', true);
        $position = count($existing);
        foreach ($technicalAttributes as $label => $value) {
            $key = sanitize_title($label);
            $existing[$key] = ['name' => $label, 'value' => $value, 'position' => $position++, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 0];
        }
        update_post_meta($productId, '_product_attributes', $existing);
    }


    private function write_ovoko_table_meta_from_attributes(int $productId, array $attrs): array
    {
        $map=['Numer części'=>'_ovoko_part_number','Producent'=>'_ovoko_vehicle_make','Model'=>'_ovoko_vehicle_model','Modyfikacja'=>'_ovoko_vehicle_generation','Rodzaj paliwa'=>'_ovoko_vehicle_fuel','Pojemność silnika'=>'_ovoko_vehicle_engine_capacity_l','Moc silnika'=>'_ovoko_vehicle_engine_power_kw','Kod silnika'=>'_ovoko_engine_code','Typ skrzyni biegów'=>'_ovoko_gearbox_type','Typ sylwetki'=>'_ovoko_vehicle_body_type','Koła napędowe'=>'_ovoko_vehicle_drive_wheels','Pozycja kierownicy'=>'_ovoko_vehicle_steering_position','Kolor'=>'_ovoko_vehicle_color','Kod koloru'=>'_ovoko_vehicle_color_code','Okres'=>'_ovoko_vehicle_period','Rok produkcji samochodu'=>'_ovoko_vehicle_year','Przebieg'=>'_ovoko_mileage_km'];
        $written=[]; foreach ($map as $label=>$key) { if (!empty($attrs[$label])) { update_post_meta($productId,$key,(string)$attrs[$label]); $written[]=$key; } }
        return $written;
    }

    
    private function build_enriched_normalized_for_apply(array $normalized): array
    {
        $enriched = $normalized;
        $debugSources = [];
        $applySource = static function(array &$target, string $field, $value, string $source, bool $overwrite = false) use (&$debugSources): void {
            if ($value === '' || $value === null) { return; }
            if (!$overwrite && !empty($target[$field])) { return; }
            $target[$field] = $value;
            $debugSources[$field] = $source;
        };
        $csvMap=(array)($this->get_settings()['ovoko_csv_mapping']??[]);
        $code=$this->normalize_part_code((string)($normalized['manufacturer_code']??''));
        $csvRow=[];
        if($code!=='' && !empty($csvMap[$code][0]) && is_array($csvMap[$code][0])){
            $csvRow=(array)$csvMap[$code][0];
            $csvNormalized = $this->build_normalized_from_csv_row($csvRow);
            foreach ($csvNormalized as $k => $v) { $applySource($enriched, (string)$k, $v, 'csv_row', false); }
        }
        $client = new RrrApiClient($this->get_settings());
        $carId = (string)($enriched['car_id'] ?? '');
        $carRaw = [];
        if($carId!==''){
            $car = $client->preview_fetch_car_by_id($carId);
            if(!empty($car['ok'])){
                $carRaw = (array)($car['raw_record'] ?? []);
                foreach((array)($car['normalized'] ?? []) as $k=>$v){
                    $applySource($enriched, (string)$k, $v, 'car_endpoint', true);
                }
            }
        }
        $enriched['_vehicle_field_sources'] = $debugSources;
        $enriched['_car_raw_selected_fields'] = array_intersect_key($carRaw, array_flip(['car_model','car_model_category','car_model_years','car_years','car_mileage','car_body_type','car_engine_code','car_gearbox_code','car_interior','defectation_notes','car_body_number','car_engine_number']));
        return $enriched;
    }

private function filter_customer_facing_technical_attributes(array $attrs, array &$skipped = []): array
    {
        $blocked=['id części ovoko','id pojazdu ovoko','źródło','kategoria ovoko','id allegro','sku','dostępność'];
        $blockedTokens=['debug','allegro','ebay','batch','cron','cache','import','technical','technicz'];
        $internalStatus=['kupiony','reserved','rezerwacja','sold','sprzedany'];
        $result=[];
        foreach($attrs as $label=>$value){
            $name=mb_strtolower(trim((string)$label));
            $v=trim((string)$value);
            if($v===''||$v==='-'||in_array(mb_strtolower($v),['null','brak','n/a'],true)){ $skipped[$label]='empty_or_placeholder'; continue;}
            if(in_array($name,$blocked,true)){ $skipped[$label]='blocked_label'; continue;}
            if(($v==='0' || ctype_digit($v)) && (str_contains($name,'status') || str_contains($name,'pozycja'))){ $skipped[$label]='raw_numeric_code'; continue; }
            if(str_contains($name,'status') && in_array(mb_strtolower($v),$internalStatus,true)){ $skipped[$label]='internal_status'; continue;}
            $skip=false; foreach($blockedTokens as $t){ if(str_contains($name,$t)){ $skip=true; break; } }
            if($skip){ $skipped[$label]='blocked_token'; continue;}
            $result[$label]=$v;
        }
        return $result;
    }

    private function get_product_details_table_rows(int $productId): array
    {
        $rows=[];
        $attrs=(array)get_post_meta($productId,'_product_attributes',true);
        foreach($attrs as $a){
            $name=trim((string)($a['name']??''));
            $v=trim((string)($a['value']??''));
            if(!empty($a['is_visible']) && $name!==''){$rows[$name]=$v;}
        }
        return $this->filter_customer_facing_technical_attributes($rows);
    }


    public function audit_old_categories_for_cleanup(): array
    {
        if (!taxonomy_exists('product_cat')) {
            return ['ok' => false, 'error' => 'product_cat taxonomy is not available'];
        }

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'all']);
        if (is_wp_error($terms)) {
            return ['ok' => false, 'error' => $terms->get_error_message()];
        }

        $termsById = [];
        $childrenByParent = [];
        foreach ((array) $terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $termId = (int) $term->term_id;
            $termsById[$termId] = $term;
            $parentId = (int) $term->parent;
            if (!isset($childrenByParent[$parentId])) {
                $childrenByParent[$parentId] = [];
            }
            $childrenByParent[$parentId][] = $termId;
        }

        $directProductCounts = $this->get_direct_product_counts_by_category();
        $ovokoTreeIds = $this->detect_ovoko_product_category_tree_ids($termsById);
        $menuCategoryIds = $this->detect_homepage_menu_product_category_ids($termsById, $childrenByParent);
        $systemCategoryIds = $this->detect_system_product_category_ids($termsById);

        $rows = [];
        $counts = [
            'total_product_categories' => count($termsById),
            'ovoko_tree_categories' => count($ovokoTreeIds),
            'old_categories_candidates' => 0,
            'categories_with_products' => 0,
            'empty_old_categories' => 0,
            'blocked_from_deletion' => 0,
            'safe_to_delete' => 0,
            'in_homepage_menu' => count($menuCategoryIds),
        ];

        foreach ($termsById as $termId => $term) {
            $productCount = (int) ($directProductCounts[$termId] ?? 0);
            $children = $childrenByParent[$termId] ?? [];
            $childrenProductCount = $this->count_descendant_products($termId, $childrenByParent, $directProductCounts);
            $inOvokoTree = isset($ovokoTreeIds[$termId]);
            $hasOvokoDescendant = $this->has_descendant_in_set($termId, $childrenByParent, $ovokoTreeIds);
            $inMenu = isset($menuCategoryIds[$termId]);
            $isSystem = isset($systemCategoryIds[$termId]);
            $reasonParts = [];

            if ($productCount > 0) {
                $reasonParts[] = 'has_direct_products';
                $counts['categories_with_products']++;
            }
            if ($childrenProductCount > 0) {
                $reasonParts[] = 'has_children_with_products';
            }
            if ($inOvokoTree) {
                $reasonParts[] = 'in_ovoko_tree';
            }
            if ($hasOvokoDescendant) {
                $reasonParts[] = 'parent_needed_for_ovoko_tree';
            }
            if ($isSystem) {
                $reasonParts[] = 'system_or_special_category';
            }
            if ($inMenu) {
                $reasonParts[] = 'used_in_homepage_or_wp_menu';
            }

            $isOldCandidate = !$inOvokoTree && !$hasOvokoDescendant;
            if ($isOldCandidate) {
                $counts['old_categories_candidates']++;
                if ($productCount === 0 && $childrenProductCount === 0) {
                    $counts['empty_old_categories']++;
                }
            }

            $safeToDelete = $isOldCandidate && $productCount === 0 && $childrenProductCount === 0 && !$isSystem && !$inMenu;
            if ($safeToDelete) {
                $counts['safe_to_delete']++;
                $reason = 'safe_to_delete_empty_old_category';
            } else {
                $counts['blocked_from_deletion']++;
                if ($reasonParts === []) {
                    $reasonParts[] = 'blocked_by_safety_policy';
                }
                $reason = implode('|', array_values(array_unique($reasonParts)));
            }

            $parent = isset($termsById[(int) $term->parent]) ? $termsById[(int) $term->parent] : null;
            $rows[] = [
                'category_id' => $termId,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'parent_id' => (int) $term->parent,
                'parent_name' => $parent instanceof \WP_Term ? (string) $parent->name : '',
                'product_count' => $productCount,
                'children_count' => count($children),
                'children_product_count' => $childrenProductCount,
                'in_ovoko_tree' => $inOvokoTree ? 'yes' : 'no',
                'in_menu' => $inMenu ? 'yes' : 'no',
                'safe_to_delete' => $safeToDelete ? 'yes' : 'no',
                'reason' => $reason,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            if ($a['safe_to_delete'] !== $b['safe_to_delete']) {
                return $a['safe_to_delete'] === 'yes' ? -1 : 1;
            }
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'ok' => true,
            'action_name' => 'Audit old categories — dry-run only',
            'dry_run' => true,
            'no_deletions_performed' => true,
            'counts' => $counts,
            'safe_to_delete' => array_values(array_filter($rows, static fn(array $row): bool => $row['safe_to_delete'] === 'yes')),
            'blocked_from_deletion' => array_values(array_filter($rows, static fn(array $row): bool => $row['safe_to_delete'] !== 'yes')),
            'rows' => $rows,
        ];
    }

    public function build_category_cleanup_csv(): string
    {
        $audit = $this->audit_old_categories_for_cleanup();
        $handle = fopen('php://temp', 'r+');
        $headers = ['category_id', 'name', 'slug', 'parent_id', 'parent_name', 'product_count', 'children_count', 'in_ovoko_tree', 'in_menu', 'safe_to_delete', 'reason'];
        fputcsv($handle, $headers);
        foreach ((array) ($audit['rows'] ?? []) as $row) {
            fputcsv($handle, array_map(static fn(string $key) => (string) ($row[$key] ?? ''), $headers));
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        return (string) $csv;
    }

    public function preview_homepage_menu_changes(): array
    {
        $audit = $this->audit_old_categories_for_cleanup();
        if (empty($audit['ok'])) {
            return $audit;
        }

        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'all']);
        $termsById = [];
        $childrenByParent = [];
        foreach ((array) $terms as $term) {
            if (!$term instanceof \WP_Term) {
                continue;
            }
            $termId = (int) $term->term_id;
            $termsById[$termId] = $term;
            $childrenByParent[(int) $term->parent][] = $termId;
        }

        $directProductCounts = $this->get_direct_product_counts_by_category();
        $ovokoTreeIds = $this->detect_ovoko_product_category_tree_ids($termsById);
        $menuCategoryIds = $this->detect_homepage_menu_product_category_ids($termsById, $childrenByParent);
        $systemCategoryIds = $this->detect_system_product_category_ids($termsById);
        $currentVisible = [];
        foreach (array_keys($menuCategoryIds) as $termId) {
            if (!isset($termsById[$termId])) {
                continue;
            }
            $term = $termsById[$termId];
            $currentVisible[] = $this->category_menu_row($term, $termsById, $directProductCounts, isset($ovokoTreeIds[$termId]));
        }

        $topOvoko = [];
        foreach (array_keys($ovokoTreeIds) as $termId) {
            if (!isset($termsById[$termId])) {
                continue;
            }
            $parentId = (int) $termsById[$termId]->parent;
            if ($parentId !== 0 && isset($ovokoTreeIds[$parentId])) {
                continue;
            }
            if (isset($systemCategoryIds[$termId])) {
                continue;
            }
            $topOvoko[] = $this->category_menu_row($termsById[$termId], $termsById, $directProductCounts, true);
        }
        usort($topOvoko, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        $oldMenuItems = array_values(array_filter($currentVisible, static fn(array $row): bool => empty($row['in_ovoko_tree'])));
        $newItems = array_values(array_filter($topOvoko, static fn(array $row): bool => !empty($row['in_ovoko_tree'])));

        return [
            'ok' => true,
            'action_name' => 'Preview homepage menu changes — dry-run only',
            'dry_run' => true,
            'no_menu_changes_performed' => true,
            'mechanisms' => [
                ['type' => 'custom_theme_template', 'file' => 'template-parts/home/store-header.php', 'description' => 'Dynamic "Wszystkie kategorie" list from product_cat under Motoryzacja, excluding technical parent categories.'],
                ['type' => 'custom_theme_template', 'file' => 'template-parts/home/category-mega.php', 'description' => 'Static category mega section with placeholder # links.'],
                ['type' => 'custom_theme_template', 'file' => 'template-parts/home/popular-products.php', 'description' => 'Static shortcut/product sections resolved against Woo product_cat names/slugs.'],
                ['type' => 'wordpress_nav_menus', 'file' => 'database', 'description' => 'WP nav menu product_cat items, if configured.'],
            ],
            'current_visible_category_menu_items' => $currentVisible,
            'old_menu_items_to_remove_or_replace' => $oldMenuItems,
            'new_top_level_ovoko_items_to_add' => $newItems,
            'plan' => [
                'recommended_menu_depth' => 'top_level_only_initially',
                'recommended_order' => 'alphabetical_by_category_name_unless_business_priority_is_confirmed',
                'links' => 'use get_term_link(product_cat) for each Ovoko category',
                'manual_confirmation_required' => true,
                'future_apply_actions' => ['delete_safe_old_empty_categories', 'update_homepage_category_menu'],
            ],
        ];
    }

    private function category_menu_row(\WP_Term $term, array $termsById, array $directProductCounts, bool $inOvokoTree): array
    {
        $link = get_term_link($term);
        $parent = isset($termsById[(int) $term->parent]) ? $termsById[(int) $term->parent] : null;
        return [
            'category_id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'parent_id' => (int) $term->parent,
            'parent_name' => $parent instanceof \WP_Term ? (string) $parent->name : '',
            'product_count' => (int) ($directProductCounts[(int) $term->term_id] ?? 0),
            'children_count' => count(get_term_children((int) $term->term_id, 'product_cat') ?: []),
            'in_ovoko_tree' => $inOvokoTree,
            'top_level' => (int) $term->parent === 0,
            'url' => is_wp_error($link) ? '' : (string) $link,
        ];
    }

    private function get_direct_product_counts_by_category(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT tt.term_id, COUNT(DISTINCT p.ID) AS product_count
             FROM {$wpdb->term_taxonomy} tt
             LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')
             WHERE tt.taxonomy = 'product_cat'
             GROUP BY tt.term_id",
            ARRAY_A
        );
        $counts = [];
        foreach ((array) $rows as $row) {
            $counts[(int) $row['term_id']] = (int) $row['product_count'];
        }
        return $counts;
    }

    private function detect_ovoko_product_category_tree_ids(array $termsById): array
    {
        $ids = [];
        $markerKeys = ['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id', '_gpswiss_ovoko_category_path', 'gpswiss_ovoko_category_path'];
        foreach ($termsById as $termId => $term) {
            foreach ($markerKeys as $key) {
                $value = get_term_meta((int) $termId, $key, true);
                if (trim((string) $value) !== '') {
                    $this->add_term_and_ancestors_to_set((int) $termId, $termsById, $ids);
                    break;
                }
            }
        }

        $ovokoProductIds = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'fields' => 'ids',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_ovoko_part_id', 'compare' => 'EXISTS'],
                ['key' => 'ovoko_id', 'compare' => 'EXISTS'],
            ],
        ]);
        foreach ((array) $ovokoProductIds as $productId) {
            $termIds = wp_get_post_terms((int) $productId, 'product_cat', ['fields' => 'ids']);
            foreach ((array) $termIds as $termId) {
                $this->add_term_and_ancestors_to_set((int) $termId, $termsById, $ids);
            }
            $path = trim((string) get_post_meta((int) $productId, '_ovoko_category', true));
            foreach ($this->find_term_ids_by_category_path($path, $termsById) as $termId) {
                $this->add_term_and_ancestors_to_set((int) $termId, $termsById, $ids);
            }
        }

        return $ids;
    }

    private function find_term_ids_by_category_path(string $path, array $termsById): array
    {
        $parts = array_values(array_filter(array_map('trim', preg_split('/\s*>\s*|\s*\/\s*/', $path) ?: [])));
        if ($parts === []) {
            return [];
        }
        $parent = 0;
        $matched = [];
        foreach ($parts as $name) {
            $found = null;
            foreach ($termsById as $term) {
                if ((int) $term->parent === $parent && sanitize_title((string) $term->name) === sanitize_title($name)) {
                    $found = $term;
                    break;
                }
            }
            if (!$found instanceof \WP_Term) {
                break;
            }
            $matched[] = (int) $found->term_id;
            $parent = (int) $found->term_id;
        }
        return $matched;
    }

    private function add_term_and_ancestors_to_set(int $termId, array $termsById, array &$set): void
    {
        while ($termId > 0 && isset($termsById[$termId])) {
            $set[$termId] = true;
            $termId = (int) $termsById[$termId]->parent;
        }
    }

    private function detect_homepage_menu_product_category_ids(array $termsById, array $childrenByParent): array
    {
        $ids = [];
        foreach ((array) wp_get_nav_menus() as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            foreach ((array) $items as $item) {
                if (($item->object ?? '') === 'product_cat') {
                    $ids[(int) $item->object_id] = true;
                }
            }
        }

        foreach ($this->detect_store_header_dynamic_category_ids($termsById, $childrenByParent) as $termId) {
            $ids[$termId] = true;
        }

        $hardcodedLabels = ['Silniki', 'Skrzynia biegów', 'Filtry DPF', 'Felgi', 'Fotele', 'Zwrotnice', 'Silniki i osprzęt', 'Skrzynie biegów i napędy', 'Felgi i opony', 'Układ kierowniczy', 'Układ hamulcowy', 'Oświetlenie', 'Zawieszenie', 'Elektronika', 'Wnętrze / kokpit', 'Karoseria', 'Chłodzenie', 'Akcesoria'];
        foreach ($hardcodedLabels as $label) {
            foreach ($termsById as $termId => $term) {
                $haystack = sanitize_title((string) $term->slug . ' ' . (string) $term->name);
                $needle = sanitize_title($label);
                if ($needle !== '' && (str_contains($haystack, $needle) || str_contains($needle, $haystack))) {
                    $ids[(int) $termId] = true;
                }
            }
        }

        return $ids;
    }

    private function detect_store_header_dynamic_category_ids(array $termsById, array $childrenByParent): array
    {
        $root = null;
        foreach ($termsById as $term) {
            if (sanitize_title((string) $term->slug) === 'motoryzacja' || sanitize_title((string) $term->name) === 'motoryzacja') {
                $root = $term;
                break;
            }
        }
        if (!$root instanceof \WP_Term) {
            return [];
        }
        $ids = [];
        $queue = [(int) $root->term_id];
        $technicalSlugs = ['motoryzacja', 'czesci-samochodowe'];
        while ($queue !== []) {
            $parentId = array_shift($queue);
            foreach (($childrenByParent[$parentId] ?? []) as $childId) {
                $child = $termsById[$childId] ?? null;
                if (!$child instanceof \WP_Term) {
                    continue;
                }
                if (in_array(sanitize_title((string) $child->slug), $technicalSlugs, true)) {
                    $queue[] = (int) $child->term_id;
                    continue;
                }
                if ((int) $child->count > 0) {
                    $ids[(int) $child->term_id] = true;
                }
            }
        }
        return $ids;
    }

    private function detect_system_product_category_ids(array $termsById): array
    {
        $ids = [];
        $defaultProductCat = (int) get_option('default_product_cat', 0);
        if ($defaultProductCat > 0) {
            $ids[$defaultProductCat] = true;
        }
        $specialSlugs = ['uncategorized', 'bez-kategorii', 'motoryzacja', 'czesci-samochodowe'];
        foreach ($termsById as $termId => $term) {
            if (in_array(sanitize_title((string) $term->slug), $specialSlugs, true)) {
                $ids[(int) $termId] = true;
            }
        }
        return $ids;
    }

    private function count_descendant_products(int $termId, array $childrenByParent, array $directProductCounts): int
    {
        $total = 0;
        foreach (($childrenByParent[$termId] ?? []) as $childId) {
            $total += (int) ($directProductCounts[$childId] ?? 0);
            $total += $this->count_descendant_products((int) $childId, $childrenByParent, $directProductCounts);
        }
        return $total;
    }

    private function has_descendant_in_set(int $termId, array $childrenByParent, array $set): bool
    {
        foreach (($childrenByParent[$termId] ?? []) as $childId) {
            if (isset($set[$childId]) || $this->has_descendant_in_set((int) $childId, $childrenByParent, $set)) {
                return true;
            }
        }
        return false;
    }

    public function run_local_test_callback(string $partId, string $status): array
    {
        $settings = $this->get_settings();
        $settings['ovoko_callback_dry_run'] = true;

        $payload = [
            'event_id' => wp_generate_uuid4(),
            'event_type' => 'part.status.changed',
            'timestamp' => gmdate('c'),
            'event_data' => ['part_id' => $partId, 'status' => $status],
        ];

        $result = $this->process_callback($payload, $settings, ['forced_local_dry_run' => true]);
        $this->append_recent_event($result['context']);
        $result['response']['message'] = 'Forced local dry-run executed. No stock changes were applied.';
        return $result['response'];
    }
}
