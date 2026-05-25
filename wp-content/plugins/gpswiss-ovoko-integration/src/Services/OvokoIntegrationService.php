<?php

namespace GPSwiss\Ovoko\Services;

use GPSwiss\Ovoko\DTO\NormalizedOvokoPart;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class OvokoIntegrationService
{
    public const OPTION_KEY = 'gpswiss_ovoko_settings';
    public const EVENTS_OPTION_KEY = 'gpswiss_ovoko_recent_events';
    public const COUNTERS_OPTION_KEY = 'gpswiss_ovoko_event_counters';
    public const DEDUP_OPTION_KEY = 'gpswiss_ovoko_processed_event_ids';

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
            'ovoko_exclude_gearbox_products' => true,
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
        $restUrl = rest_url('wc/v3');
        $restCheck = wp_remote_get($restUrl, ['timeout' => 8]);
        $wooActive = class_exists('WooCommerce');

        $metaKeyCounts = $this->count_products_for_mapping_meta_keys();
        $withPartId = (int) array_sum($metaKeyCounts);
        $allProducts = (int) wp_count_posts('product')->publish + (int) wp_count_posts('product')->draft;
        $lastLookupFoundProduct = $this->did_last_lookup_find_product();

        $connectorClient = new OvokoSupplyConnectorClient($settings);
        $syncService = new OvokoProductSyncService();
        $connectorCheck = $connectorClient->check_configuration();
        $rrrClient = new RrrApiClient($settings);
        $rrrCheck = $rrrClient->check_configuration();
        $fixturePart = NormalizedOvokoPart::from_array($syncService->developer_sample_fixture());
        $fixtureWithHash = NormalizedOvokoPart::from_array($fixturePart->to_array() + ['payload_hash' => $syncService->calculate_payload_hash($fixturePart)]);
$matchPreview = $syncService->preview_match_existing_product($fixtureWithHash);
        $excludeEnabled = !empty($settings['ovoko_exclude_gearbox_products']);
        $excludedCount = $excludeEnabled ? $syncService->count_excluded_gearbox_products() : 0;

        return [
            'callback_url' => rest_url('gpswiss-ovoko/v1/callback'),
            'settings' => $settings,
            'counters' => wp_parse_args(get_option(self::COUNTERS_OPTION_KEY, []), [
                'received' => 0, 'auth_failed' => 0, 'duplicate' => 0, 'dry_run' => 0, 'applied' => 0, 'unsupported' => 0, 'failed' => 0,
            ]),
            'recent_events' => array_reverse((array) get_option(self::EVENTS_OPTION_KEY, [])),
            'woo_active' => $wooActive,
            'woo_rest_reachable' => !is_wp_error($restCheck) && (int) wp_remote_retrieve_response_code($restCheck) < 500,
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
            'sync_preview_fixture' => $fixtureWithHash->to_array(),
            'sync_preview_meta_mapping' => $syncService->map_to_woo_meta_preview($fixtureWithHash),
            'sync_preview_match' => $matchPreview,
            'excluded_from_ovoko_sync' => [
                'enabled' => $excludeEnabled,
                'detected_products_count' => $excludedCount,
                'info' => 'gearbox standalone products are excluded from Ovoko sync/import/matching',
            ],
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
        return $result;
    }

    public function check_rrr_api_configuration(): array
    {
        $client = new RrrApiClient($this->get_settings());
        $result = $client->check_configuration();
        $result['checked_at'] = gmdate('c');
        return $result;
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
        $salesChannelsDiagnostic = $client->build_sales_channels_diagnostic($payload);
        $syncService = new OvokoProductSyncService();
        $match = $syncService->preview_match_rrr_record([
            'part_id' => (string) ($normalized['part_id'] ?? ''),
            'external_id' => (string) ($normalized['external_id'] ?? ''),
        ]);

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
            'woo_meta_mapping_preview' => $this->build_rrr_single_part_woo_meta_preview($normalized),
            'same_vehicle_grouping_preview' => $this->build_same_vehicle_grouping_preview($normalized),
            'woo_match_preview' => $match,
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

    public function preview_woo_product_create_from_rrr_part(int $partId): array
    {
        $partId = max(1, $partId);
        $client = new RrrApiClient($this->get_settings());
        $result = $client->preview_fetch_single_part($partId);
        $payload = (array) ($result['payload'] ?? []);
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        $syncService = new OvokoProductSyncService();
        $match = $syncService->preview_match_rrr_record([
            'part_id' => (string) ($normalized['part_id'] ?? ''),
            'external_id' => (string) ($normalized['external_id'] ?? ''),
        ]);

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
                'post_title' => (string) ($normalized['title'] ?? ''),
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
                '_ovoko_quality' => (string) ($normalized['quality'] ?? ''),
                '_ovoko_position' => (string) ($normalized['position'] ?? ''),
                'source' => 'ovoko_master',
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
