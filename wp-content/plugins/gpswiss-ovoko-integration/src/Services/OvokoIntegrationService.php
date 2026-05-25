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
    public const GEARBOX_COUNT_OPTION_KEY = 'gpswiss_ovoko_gearbox_exclusion_count';

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
            'sync_preview_fixture' => $fixtureWithHash->to_array(),
            'sync_preview_meta_mapping' => $syncService->map_to_woo_meta_preview($fixtureWithHash),
            'sync_preview_match' => ['mode' => 'manual_only', 'note' => 'match preview moved to manual actions only to keep admin page lightweight'],
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

    private function short_make(string $make): string { $m=trim($make); return match($m){'Volkswagen'=>'VW','Mercedes-Benz'=>'Mercedes',default=>$m}; }

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
            'Kategoria Ovoko' => (string) ($normalized['category_title_path'] ?? ''),
            'Stan części' => (string) (($normalized['quality'] ?? '') ?: ($normalized['status'] ?? '')),
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
