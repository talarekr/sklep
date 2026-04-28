<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Importer
{
    private const CHECKPOINT_OPTION_KEY = 'awi_import_checkpoint';
    private const ACTIVE_SEEN_OFFERS_OPTION_KEY = 'awi_active_seen_offer_ids';
    private const CYCLE_STATE_OPTION_KEY = 'awi_import_cycle_state';
    private const IMPORT_LOCK_OPTION_KEY = 'awi_import_lock';
    private const MISSING_IMPORT_CHECKPOINT_OPTION_KEY = 'awi_missing_import_checkpoint';
    private const EVENT_SYNC_CHECKPOINT_OPTION_KEY = 'awi_event_sync_checkpoint';
    private const EVENT_SYNC_STATUS_OPTION_KEY = 'awi_event_sync_status';
    private const BATCH_LIMIT = 50;
    private const MISSING_IMPORT_BATCH_LIMIT = 50;
    private const MAX_EXECUTION_TIME_SECONDS = 900;
    private const SOFT_RUNTIME_LIMIT_SECONDS = 840;
    private const IMPORT_LOCK_TTL_SECONDS = self::MAX_EXECUTION_TIME_SECONDS + 60;
    private const OFFER_STATUS_SYNC_BATCH_SIZE = 50;
    private const EVENT_SYNC_MAX_GAP_SECONDS = DAY_IN_SECONDS;

    private AllegroClient $client;
    private ProductMapper $mapper;
    private Logger $logger;

    public function __construct(AllegroClient $client, ProductMapper $mapper, Logger $logger)
    {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->logger = $logger;
    }

    public function import_offers(array $resume_override = []): array
    {
        $this->ensure_runtime_limits();
        $started_at = microtime(true);
        $lock_context = $this->build_lock_context();
        $lock_acquired = $this->acquire_import_lock($lock_context);
        if (!$lock_acquired) {
            $this->logger->warning('Import skipped: another import process is already running.', [
                'lock_context' => $lock_context,
                'lock_option_key' => self::IMPORT_LOCK_OPTION_KEY,
                'lock_ttl_seconds' => self::IMPORT_LOCK_TTL_SECONDS,
            ]);

            return [
                'date' => gmdate('Y-m-d H:i:s'),
                'offers' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'fetched_from_api' => 0,
                'reported_total_count' => null,
                'last_processed_offer_id' => '',
                'elapsed_time' => round(max(0, microtime(true) - $started_at), 3),
                'skipped_due_to_lock' => true,
            ];
        }

        try {
            $settings = Plugin::get_settings();

            $checkpoint = $this->load_checkpoint();
            $offset = (int) ($checkpoint['offset'] ?? 0);
            $page_no = max(1, (int) ($checkpoint['page_no'] ?? 1));
            $page_token = (string) ($checkpoint['page_token'] ?? '');
            $resume_offer_index = max(0, (int) ($checkpoint['offer_index'] ?? 0));

            $has_override_offset = array_key_exists('offset', $resume_override) && $resume_override['offset'] !== null;
            $has_override_page = array_key_exists('page_no', $resume_override) && $resume_override['page_no'] !== null;
            $has_override_offer_index = array_key_exists('offer_index', $resume_override) && $resume_override['offer_index'] !== null;
            if ($has_override_offset || $has_override_page || $has_override_offer_index) {
                $offset = $has_override_offset ? max(0, (int) $resume_override['offset']) : $offset;
                $page_no = $has_override_page
                    ? max(1, (int) $resume_override['page_no'])
                    : ($has_override_offset ? $this->calculate_page_no_from_offset($offset) : $page_no);
                $resume_offer_index = $has_override_offer_index ? max(0, (int) $resume_override['offer_index']) : $resume_offer_index;
                $page_token = '';

                if (function_exists('awi_log')) {
                    awi_log('MANUAL_RESUME_OVERRIDE', [
                        'offset' => $offset,
                        'page' => $page_no,
                        'index' => $resume_offer_index,
                    ]);
                }
                $this->logger->warning('MANUAL_RESUME_OVERRIDE', [
                    'offset' => $offset,
                    'page' => $page_no,
                    'index' => $resume_offer_index,
                ]);
            }

            $processed = 0;
            $created = 0;
            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $fetched_from_api = 0;
            $total_count_from_api = null;
            $last_processed_offer_id = '';

        $this->logger->info('SYNC_START', [
            'offset' => $offset,
            'page_no' => $page_no,
            'page_token' => $page_token,
            'resume_offer_index' => $resume_offer_index,
            'batch_limit' => self::BATCH_LIMIT,
            'max_execution_time_target' => self::MAX_EXECUTION_TIME_SECONDS,
            'soft_runtime_limit_seconds' => self::SOFT_RUNTIME_LIMIT_SECONDS,
            'checkpoint_state' => $checkpoint,
            'cycle_state' => $this->load_cycle_state(),
            'active_seen_count' => count($this->load_active_seen_offer_ids()),
            'safe_mode_enabled' => Plugin::is_safe_mode_enabled(),
            'php_max_execution_time' => (int) ini_get('max_execution_time'),
            'php_memory_limit' => (string) ini_get('memory_limit'),
        ]);

        if ($this->is_cycle_start($offset, $page_no, $resume_offer_index)) {
            $this->reset_active_seen_offer_ids();
            $this->initialize_cycle_state();
            $this->logger->info('Reset active offers set for a new sync cycle.');
        }

        $page = $this->client->get_offers((string) $settings['offer_status'], $offset, self::BATCH_LIMIT, $page_token);
        if (is_wp_error($page)) {
            $errors++;
            $this->mark_cycle_state_error('offers_batch_fetch_failed');
            $this->logger->error('Failed to fetch offers batch.', [
                'page_no' => $page_no,
                'offset' => $offset,
                'page_token' => $page_token,
                'error' => $page->get_error_message(),
            ]);

            return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
        }

        $offers = $page['offers'] ?? [];
        if (!is_array($offers) || $offers === []) {
            $this->reset_checkpoint();
            $this->logger->info('IMPORT OFFSET: page empty, checkpoint reset.', [
                'page_no' => $page_no,
                'offset' => $offset,
                'page_token' => $page_token,
                'fetched' => 0,
            ]);

            return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
        }

        $batch_size = count($offers);
        $fetched_from_api = $batch_size;
        $total_count_from_api = $this->extract_total_count($page, $total_count_from_api);

        $this->logger->info('IMPORT OFFSET: fetched offers batch.', [
            'page_no' => $page_no,
            'offset' => $offset,
            'page_token' => $page_token,
            'fetched' => $batch_size,
            'batch_size' => $batch_size,
            'resume_offer_index' => $resume_offer_index,
            'reported_total_count' => $total_count_from_api,
        ]);

        if ($resume_offer_index >= $batch_size) {
            $this->logger->warning('Invalid resume_offer_index detected; resetting to 0 for current batch.', [
                'offset' => $offset,
                'batch_limit' => self::BATCH_LIMIT,
                'offers_count' => $batch_size,
                'invalid_resume_offer_index' => $resume_offer_index,
            ]);

            $resume_offer_index = 0;
            $this->save_checkpoint([
                'offset' => $offset,
                'page_no' => $page_no,
                'page_token' => $page_token,
                'offer_index' => 0,
                'total_processed' => (int) ($checkpoint['total_processed'] ?? 0),
                'total_count' => $total_count_from_api,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }

        $start_index = $resume_offer_index;

        for ($index = $start_index; $index < $batch_size; $index++) {
            if ($index > $start_index) {
                usleep(200000); // 0.2s
            }

            if (memory_get_usage(true) > 256 * 1024 * 1024) {
                if (function_exists('awi_log')) {
                    awi_log('MEMORY_LIMIT_APPROACHING', []);
                }
                $this->logger->warning('MEMORY_LIMIT_APPROACHING', [
                    'memory_usage_bytes' => memory_get_usage(true),
                    'memory_threshold_bytes' => 256 * 1024 * 1024,
                    'offset' => $offset,
                    'page_no' => $page_no,
                    'offer_index' => $index,
                ]);
                return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
            }

            if (function_exists('set_time_limit')) {
                @set_time_limit(self::MAX_EXECUTION_TIME_SECONDS);
            }

            $offer_basic = $offers[$index] ?? [];
            $offer_id = sanitize_text_field((string) ($offer_basic['id'] ?? ''));
            if ($offer_id === '') {
                $skipped++;
                $this->logger->warning('Skipping offer from list due to missing id.', [
                    'page_no' => $page_no,
                    'offset' => $offset,
                    'index' => $index,
                ]);
                continue;
            }

            $last_processed_offer_id = $offer_id;
            $this->remember_active_offer_id($offer_id);
            $this->logger->info('Importing Allegro offer.', [
                'offer_id' => $offer_id,
                'page_no' => $page_no,
                'offset' => $offset,
                'index' => $index,
                'batch_size' => $batch_size,
            ]);

            try {
                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $errors++;
                    $this->mark_cycle_state_error('offer_details_fetch_failed');
                    $this->logger->error('OFFER_IMPORT_FAILED', [
                        'offer_id' => $offer_id,
                        'stage' => 'get_offer_details',
                        'error' => $details->get_error_message(),
                    ]);
                    continue;
                }
                $this->logger->info('OFFER_DETAILS_FETCHED', [
                    'offer_id' => $offer_id,
                    'details_keys' => is_array($details) ? array_keys($details) : [],
                ]);

                $result = $this->mapper->upsert_product($details, $settings);
                $processed++;

                if (($result['result'] ?? '') === 'created') {
                    $created++;
                    $this->logger->info('SYNC_NEW_OFFER_IMPORTED', [
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                        'action' => 'import',
                        'reason' => 'offer_not_found_in_woo',
                    ]);
                } elseif (($result['result'] ?? '') === 'updated') {
                    $updated++;
                    $this->logger->info('SYNC_EXISTING_OFFER_UPDATED', [
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                        'action' => 'update',
                        'reason' => 'offer_found_in_woo',
                    ]);
                } elseif (($result['result'] ?? '') === 'skipped') {
                    $skipped++;
                    $this->logger->warning('Offer skipped during product upsert.', [
                        'offer_id' => $offer_id,
                        'skip_existing' => !empty($result['product_id']),
                        'skip_reason' => (string) ($result['error'] ?? $result['reason'] ?? 'unknown'),
                        'product_id' => (int) ($result['product_id'] ?? 0),
                    ]);
                    if (!empty($result['product_id'])) {
                        $this->logger->info('SKIP EXISTING: offer already mapped to product.', [
                            'offer_id' => $offer_id,
                            'product_id' => (int) $result['product_id'],
                            'skip_reason' => (string) ($result['error'] ?? $result['reason'] ?? 'unknown'),
                        ]);
                    }
                } elseif (($result['result'] ?? '') === 'error') {
                    $errors++;
                    $this->mark_cycle_state_error('product_upsert_failed');
                    $this->logger->error('OFFER_IMPORT_FAILED', [
                        'offer_id' => $offer_id,
                        'stage' => (string) ($result['stage'] ?? 'product_upsert'),
                        'error' => (string) ($result['error'] ?? 'unknown_error'),
                        'reason' => (string) ($result['reason'] ?? ''),
                    ]);
                    continue;
                }

                $this->logger->info('OFFER_IMPORT_DONE', [
                    'offer_id' => $offer_id,
                    'result' => (string) ($result['result'] ?? 'unknown'),
                    'product_id' => (int) ($result['product_id'] ?? 0),
                ]);
            } catch (\Throwable $throwable) {
                $errors++;
                $this->mark_cycle_state_error('offer_import_unhandled_exception');
                $this->logger->error('OFFER_IMPORT_FAILED', [
                    'offer_id' => $offer_id,
                    'stage' => 'unexpected_exception',
                    'error' => $throwable->getMessage(),
                ]);
                continue;
            }

            if ($this->should_stop_for_runtime($started_at)) {
                $this->save_checkpoint([
                    'offset' => $offset,
                    'page_no' => $page_no,
                    'page_token' => $page_token,
                    'offer_index' => $index + 1,
                    'total_processed' => (int) ($checkpoint['total_processed'] ?? 0) + $processed,
                    'total_count' => $total_count_from_api,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

                $this->logger->warning('Stopping import safely due to runtime limit, checkpoint saved.', [
                    'offset' => $offset,
                    'page_no' => $page_no,
                    'offer_index' => $index + 1,
                    'batch_size' => $batch_size,
                    'processed_in_run' => $processed,
                ]);

                return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
            }
        }

        if ($processed === 0 && $batch_size > 0) {
            $this->save_checkpoint([
                'offset' => $offset,
                'page_no' => $page_no,
                'page_token' => $page_token,
                'offer_index' => 0,
                'total_processed' => (int) ($checkpoint['total_processed'] ?? 0),
                'total_count' => $total_count_from_api,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->logger->warning('Batch returned offers but processed_in_run is 0; keeping checkpoint on current offset.', [
                'offset' => $offset,
                'page_no' => $page_no,
                'offers_count' => $batch_size,
                'processed_in_run' => $processed,
                'skipped_in_run' => $skipped,
            ]);

            return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
        }

        $next_page_token = $this->extract_next_page_token($page);
        $next_offset = $offset + $batch_size;
        $next_page_no = $page_no + 1;
        $has_more = $this->has_more_pages($batch_size, self::BATCH_LIMIT, $next_offset, $total_count_from_api, $next_page_token);
        $completion_reason = $this->determine_cycle_completion_reason($batch_size, $next_offset, $total_count_from_api, $next_page_token);
        $this->logger->info('Cycle completion evaluation.', [
            'has_more' => $has_more,
            'reason' => $completion_reason,
            'batch_size' => $batch_size,
            'next_offset' => $next_offset,
            'expected_total_count' => $total_count_from_api,
            'next_page_token' => $next_page_token,
            'total_active_offers_seen' => count($this->load_active_seen_offer_ids()),
        ]);

        if ($has_more) {
            $this->save_checkpoint([
                'offset' => $next_offset,
                'page_no' => $next_page_no,
                'page_token' => $next_page_token,
                'offer_index' => 0,
                'total_processed' => (int) ($checkpoint['total_processed'] ?? 0) + $processed,
                'total_count' => $total_count_from_api,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $this->logger->info('Import batch completed, checkpoint moved to next page.', [
                'next_offset' => $next_offset,
                'next_page_no' => $next_page_no,
                'next_page_token' => $next_page_token,
                'current_offset' => $offset,
                'current_page_no' => $page_no,
                'current_page_token' => $page_token,
                'processed_in_run' => $processed,
                'created_in_run' => $created,
                'updated_in_run' => $updated,
                'batch_size' => $batch_size,
                'skipped_in_run' => $skipped,
                'errors_in_run' => $errors,
                'elapsed_time' => round(microtime(true) - $started_at, 3),
                'last_processed_offer_id' => $last_processed_offer_id,
            ]);
        } else {
            $deactivated_count = $this->sync_linked_products_by_offer_id((string) $settings['inactive_product_status']);
            $this->reset_checkpoint();
            $this->reset_active_seen_offer_ids();
            $this->clear_cycle_state();
            $this->logger->info('Reached end of offers, checkpoint reset for next sync cycle.', [
                'last_offset' => $next_offset,
                'processed_in_run' => $processed,
                'reported_total_count' => $total_count_from_api,
                'sync_allegro_missing_or_ended_count' => $deactivated_count,
                'created_in_run' => $created,
                'updated_in_run' => $updated,
                'skipped_in_run' => $skipped,
                'errors_in_run' => $errors,
                'elapsed_time' => round(microtime(true) - $started_at, 3),
                'last_processed_offer_id' => $last_processed_offer_id,
            ]);
        }

            return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
        } finally {
            $this->release_import_lock($lock_context);
        }
    }

    public function start_missing_import(): array
    {
        $checkpoint = $this->get_missing_import_checkpoint();
        $checkpoint['status'] = 'running';
        $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->save_missing_import_checkpoint($checkpoint);
        $this->logger->info('MISSING_IMPORT_START', $checkpoint);

        return $checkpoint;
    }

    public function run_event_based_sync(): array
    {
        $this->ensure_runtime_limits();
        $started_at = microtime(true);
        $checkpoint = $this->get_event_sync_checkpoint();
        $lock_context = $this->build_lock_context();
        $lock_context['mode'] = 'event_sync';
        $this->logger->info('EVENT_SYNC_START', [
            'stage' => 'attempt',
            'checkpoint' => $checkpoint,
        ]);

        if (!$this->acquire_import_lock($lock_context)) {
            $this->logger->warning('EVENT_SYNC_ERROR', [
                'stage' => 'acquire_lock',
                'reason' => 'import_lock_active',
            ]);
            $this->logger->warning('EVENT_SYNC_SKIPPED', [
                'reason' => 'import_lock_active',
                'checkpoint' => $checkpoint,
            ]);

            return [
                'status' => 'skipped_due_to_lock',
            ];
        }

        try {
            $this->logger->info('EVENT_SYNC_START', [
                'stage' => 'running',
                'checkpoint' => $checkpoint,
            ]);

            $now = time();
            $last_success_ts = (int) ($checkpoint['last_success_ts'] ?? 0);
            $gap_seconds = $last_success_ts > 0 ? max(0, $now - $last_success_ts) : 0;
            if ($last_success_ts > 0 && $gap_seconds > self::EVENT_SYNC_MAX_GAP_SECONDS) {
                $this->logger->warning('EVENT_SYNC_ERROR', [
                    'stage' => 'gap_detection',
                    'reason' => 'checkpoint_gap_exceeded',
                    'gap_seconds' => $gap_seconds,
                    'max_gap_seconds' => self::EVENT_SYNC_MAX_GAP_SECONDS,
                ]);

                return [
                    'status' => 'fallback_required',
                    'reason' => 'checkpoint_gap_exceeded',
                ];
            }

            $offer_events_response = $this->client->get_offer_events([
                'from' => (string) ($checkpoint['last_offer_event_id'] ?? ''),
                'limit' => 100,
            ]);
            if (is_wp_error($offer_events_response)) {
                $this->logger->error('EVENT_SYNC_ERROR', [
                    'stage' => 'fetch_offer_events',
                    'reason' => $offer_events_response->get_error_message(),
                ]);

                return [
                    'status' => 'fallback_required',
                    'reason' => 'offer_events_api_error',
                ];
            }

            $order_events_response = $this->client->get_order_events([
                'from' => (string) ($checkpoint['last_order_event_id'] ?? ''),
                'limit' => 100,
            ]);
            if (is_wp_error($order_events_response)) {
                if ($this->is_order_events_access_denied_error($order_events_response)) {
                    $this->logger->warning('EVENT_SYNC_ORDER_EVENTS_DISABLED_ACCESS_DENIED', [
                        'stage' => 'fetch_order_events',
                        'reason' => $order_events_response->get_error_message(),
                        'required_scope' => AllegroAuth::ORDER_EVENTS_REQUIRED_SCOPE,
                    ]);
                    $this->logger->warning('ALLEGRO_OAUTH_SCOPES_INSUFFICIENT_FOR_ORDER_EVENTS', [
                        'required_scope' => AllegroAuth::ORDER_EVENTS_REQUIRED_SCOPE,
                        'source' => 'event_sync_order_events_call',
                        'reauthorization_required' => true,
                    ]);

                    $settings = Plugin::get_settings();
                    $settings['awi_order_events_access_denied_notice'] = 1;
                    Plugin::update_settings($settings);

                    $order_events_response = [
                        'events' => [],
                    ];
                } else {
                $this->logger->error('EVENT_SYNC_ERROR', [
                    'stage' => 'fetch_order_events',
                    'reason' => $order_events_response->get_error_message(),
                ]);

                return [
                    'status' => 'fallback_required',
                    'reason' => 'order_events_api_error',
                ];
                }
            }

            $settings = Plugin::get_settings();
            $inactive_status = in_array((string) ($settings['inactive_product_status'] ?? 'draft'), ['draft', 'private'], true)
                ? (string) $settings['inactive_product_status']
                : 'draft';

            $offer_events = $this->sort_events_by_time_and_id($this->normalize_events_list($offer_events_response, ['offerEvents', 'events']));
            $order_events = $this->sort_events_by_time_and_id($this->normalize_events_list($order_events_response, ['events', 'orderEvents']));
            if (count($offer_events) === 0 && count($order_events) === 0) {
                $this->logger->info('EVENT_SYNC_NO_EVENTS', [
                    'last_offer_event_id' => (string) ($checkpoint['last_offer_event_id'] ?? ''),
                    'last_order_event_id' => (string) ($checkpoint['last_order_event_id'] ?? ''),
                ]);
            }
            $processed = 0;
            $last_error = '';

            foreach ($offer_events as $event) {
                $event_id = sanitize_text_field((string) ($event['id'] ?? ''));
                $offer_id = sanitize_text_field((string) ($event['offer']['id'] ?? $event['offerId'] ?? ''));
                $type = sanitize_text_field((string) ($event['type'] ?? 'unknown'));
                $occurred_at = sanitize_text_field((string) ($event['occurredAt'] ?? ''));
                $this->logger->info('EVENT_SYNC_EVENT_RECEIVED', [
                    'stream' => 'offer_events',
                    'event_id' => $event_id,
                    'event_type' => $type,
                    'offer_id' => $offer_id,
                    'occurred_at' => $occurred_at,
                ]);
                if ($offer_id !== '') {
                    $processed_ok = $this->sync_single_offer_from_event($offer_id, $inactive_status, $settings, 'offer_events', $event_id, $type);
                    if (!$processed_ok) {
                        $last_error = 'offer_event_processing_failed';
                        $this->save_event_sync_status('event_based', 'error', $last_error, $checkpoint);
                        return [
                            'status' => 'error',
                            'reason' => $last_error,
                        ];
                    }
                }

                if ($event_id !== '') {
                    $checkpoint['last_offer_event_id'] = $event_id;
                }
                $checkpoint['last_success_ts'] = time();
                $checkpoint['last_success_at'] = gmdate('Y-m-d H:i:s');
                $this->save_event_sync_checkpoint($checkpoint);
                $processed++;
            }

            foreach ($order_events as $event) {
                $event_id = sanitize_text_field((string) ($event['id'] ?? ''));
                $type = sanitize_text_field((string) ($event['type'] ?? 'unknown'));
                $occurred_at = sanitize_text_field((string) ($event['occurredAt'] ?? ''));
                $offer_ids = $this->extract_offer_ids_from_order_event($event);
                $this->logger->info('EVENT_SYNC_EVENT_RECEIVED', [
                    'stream' => 'order_events',
                    'event_id' => $event_id,
                    'event_type' => $type,
                    'offer_ids' => $offer_ids,
                    'occurred_at' => $occurred_at,
                ]);
                $order_processed_ok = true;
                foreach ($offer_ids as $offer_id) {
                    $this->logger->info('EVENT_SYNC_ORDER_SOLD', [
                        'event_id' => $event_id,
                        'event_type' => $type,
                        'offer_id' => $offer_id,
                    ]);
                    $processed_ok = $this->sync_single_offer_from_event($offer_id, $inactive_status, $settings, 'order_events', $event_id, $type);
                    if (!$processed_ok) {
                        $order_processed_ok = false;
                        $last_error = 'order_event_processing_failed';
                        break;
                    }
                }
                if (!$order_processed_ok) {
                    $this->save_event_sync_status('event_based', 'error', $last_error, $checkpoint);
                    return [
                        'status' => 'error',
                        'reason' => $last_error,
                    ];
                }

                if ($event_id !== '') {
                    $checkpoint['last_order_event_id'] = $event_id;
                }
                $checkpoint['last_success_ts'] = time();
                $checkpoint['last_success_at'] = gmdate('Y-m-d H:i:s');
                $this->save_event_sync_checkpoint($checkpoint);
                $processed++;
            }

            $summary = [
                'status' => 'ok',
                'processed_events' => $processed,
                'offer_events' => count($offer_events),
                'order_events' => count($order_events),
                'elapsed_time' => round(max(0, microtime(true) - $started_at), 3),
            ];
            $this->logger->info('EVENT_SYNC_DONE', $summary);
            $this->save_event_sync_status('event_based', 'ok', '', $checkpoint);

            return $summary;
        } finally {
            $this->release_import_lock($lock_context);
        }
    }

    public function continue_missing_import(): array
    {
        $checkpoint = $this->get_missing_import_checkpoint();
        if (($checkpoint['status'] ?? '') === 'completed') {
            $checkpoint['status'] = 'running';
            $checkpoint['current_offset'] = 0;
        } else {
            $checkpoint['status'] = 'running';
        }

        $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->save_missing_import_checkpoint($checkpoint);

        return $checkpoint;
    }

    public function pause_missing_import(): array
    {
        $checkpoint = $this->get_missing_import_checkpoint();
        if (($checkpoint['status'] ?? '') === 'running') {
            $checkpoint['status'] = 'paused';
            $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->save_missing_import_checkpoint($checkpoint);
        }

        return $checkpoint;
    }

    public function reset_missing_import_checkpoint(): array
    {
        delete_option(self::MISSING_IMPORT_CHECKPOINT_OPTION_KEY);
        return $this->get_missing_import_checkpoint();
    }

    public function get_missing_import_checkpoint(): array
    {
        $checkpoint = get_option(self::MISSING_IMPORT_CHECKPOINT_OPTION_KEY, []);
        if (!is_array($checkpoint)) {
            $checkpoint = [];
        }

        return [
            'status' => in_array((string) ($checkpoint['status'] ?? 'paused'), ['running', 'completed', 'failed', 'paused'], true)
                ? (string) ($checkpoint['status'] ?? 'paused')
                : 'paused',
            'current_offset' => max(0, (int) ($checkpoint['current_offset'] ?? 0)),
            'total_checked' => max(0, (int) ($checkpoint['total_checked'] ?? 0)),
            'existing_skipped' => max(0, (int) ($checkpoint['existing_skipped'] ?? 0)),
            'missing_imported' => max(0, (int) ($checkpoint['missing_imported'] ?? 0)),
            'errors' => max(0, (int) ($checkpoint['errors'] ?? 0)),
            'last_checked_offer_id' => sanitize_text_field((string) ($checkpoint['last_checked_offer_id'] ?? '')),
            'last_imported_offer_id' => sanitize_text_field((string) ($checkpoint['last_imported_offer_id'] ?? '')),
            'total_count' => isset($checkpoint['total_count']) && is_numeric($checkpoint['total_count']) ? max(0, (int) $checkpoint['total_count']) : null,
            'updated_at' => sanitize_text_field((string) ($checkpoint['updated_at'] ?? '')),
        ];
    }

    public function run_missing_import_batch(): array
    {
        $checkpoint = $this->get_missing_import_checkpoint();
        if (($checkpoint['status'] ?? '') !== 'running') {
            return $checkpoint;
        }

        $started_at = microtime(true);
        $lock_context = $this->build_lock_context();
        $lock_context['mode'] = 'missing_import';
        if (!$this->acquire_import_lock($lock_context)) {
            $checkpoint['status'] = 'failed';
            $checkpoint['errors'] = (int) ($checkpoint['errors'] ?? 0) + 1;
            $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->save_missing_import_checkpoint($checkpoint);
            return $checkpoint;
        }

        try {
            $offset = (int) ($checkpoint['current_offset'] ?? 0);
            $page = $this->client->get_offers('ACTIVE', $offset, self::MISSING_IMPORT_BATCH_LIMIT, '');
            if (is_wp_error($page)) {
                $checkpoint['status'] = 'failed';
                $checkpoint['errors'] = (int) ($checkpoint['errors'] ?? 0) + 1;
                $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
                $this->save_missing_import_checkpoint($checkpoint);
                $this->logger->error('MISSING_IMPORT_BATCH_DONE', [
                    'current_offset' => $offset,
                    'errors_in_batch' => 1,
                    'error' => $page->get_error_message(),
                ]);
                return $checkpoint;
            }

            $offers = is_array($page['offers'] ?? null) ? $page['offers'] : [];
            $total_count = $this->extract_total_count($page, isset($checkpoint['total_count']) && is_numeric($checkpoint['total_count']) ? (int) $checkpoint['total_count'] : null);
            $batch_size = count($offers);
            $checked_in_batch = 0;
            $existing_skipped_in_batch = 0;
            $missing_imported_in_batch = 0;
            $errors_in_batch = 0;
            $last_checked_offer_id = '';
            $last_imported_offer_id = (string) ($checkpoint['last_imported_offer_id'] ?? '');

            $this->logger->info('MISSING_IMPORT_BATCH_START', [
                'current_offset' => $offset,
                'batch_size' => self::MISSING_IMPORT_BATCH_LIMIT,
                'fetched' => $batch_size,
                'total_count' => $total_count,
            ]);

            foreach ($offers as $offer_basic) {
                $offer_id = sanitize_text_field((string) ($offer_basic['id'] ?? ''));
                if ($offer_id === '') {
                    $errors_in_batch++;
                    continue;
                }

                $checked_in_batch++;
                $last_checked_offer_id = $offer_id;
                $this->logger->info('MISSING_IMPORT_OFFER_CHECK', [
                    'offer_id' => $offer_id,
                    'current_offset' => $offset,
                ]);
                $this->logger->info('CHECK', [
                    'mode' => 'missing_import',
                    'offer_id' => $offer_id,
                    'current_offset' => $offset,
                ]);

                $existing_product_id = $this->mapper->find_existing_product_id_for_offer($offer_basic);
                if ($existing_product_id > 0) {
                    $existing_skipped_in_batch++;
                    $this->logger->info('MISSING_IMPORT_OFFER_SKIP already_exists', [
                        'offer_id' => $offer_id,
                        'product_id' => $existing_product_id,
                    ]);
                    $this->logger->info('SKIP', [
                        'mode' => 'missing_import',
                        'offer_id' => $offer_id,
                        'product_id' => $existing_product_id,
                        'reason' => 'already_exists_offer_or_sku',
                        'source' => 'basic_check',
                    ]);
                    continue;
                }

                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $errors_in_batch++;
                    $this->logger->error('MISSING_IMPORT_OFFER_FAILED', [
                        'offer_id' => $offer_id,
                        'stage' => 'get_offer_details',
                        'error' => $details->get_error_message(),
                    ]);
                    continue;
                }

                $existing_from_details = $this->mapper->find_existing_product_id_for_offer($details);
                if ($existing_from_details > 0) {
                    $existing_skipped_in_batch++;
                    $this->logger->info('MISSING_IMPORT_OFFER_SKIP already_exists', [
                        'offer_id' => $offer_id,
                        'product_id' => $existing_from_details,
                        'source' => 'details_check',
                    ]);
                    $this->logger->info('SKIP', [
                        'mode' => 'missing_import',
                        'offer_id' => $offer_id,
                        'product_id' => $existing_from_details,
                        'reason' => 'already_exists_offer_or_sku',
                        'source' => 'details_check',
                    ]);
                    continue;
                }

                $details_images = $details['images'] ?? [];
                $details_images_count = is_array($details_images) ? count($details_images) : 0;
                $product_set_count = is_array($details['productSet'] ?? null) ? count((array) $details['productSet']) : 0;
                $this->logger->info('MISSING_IMPORT_DETAILS_PAYLOAD', [
                    'offer_id' => $offer_id,
                    'top_level_images_count' => $details_images_count,
                    'has_top_level_images_array' => is_array($details_images),
                    'product_set_count' => $product_set_count,
                    'details_keys' => is_array($details) ? array_keys($details) : [],
                ]);

                $result = $this->mapper->upsert_product($details, [
                    'sync_mode' => 'create_only',
                    'inactive_product_status' => 'draft',
                ]);

                if (($result['result'] ?? '') === 'created') {
                    $missing_imported_in_batch++;
                    $last_imported_offer_id = $offer_id;
                    $this->logger->info('MISSING_IMPORT_OFFER_IMPORTED', [
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                    ]);
                    $this->logger->info('IMPORTED', [
                        'mode' => 'missing_import',
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                    ]);
                    continue;
                }

                if (($result['result'] ?? '') === 'skipped' && !empty($result['product_id'])) {
                    $existing_skipped_in_batch++;
                    $this->logger->info('MISSING_IMPORT_OFFER_SKIP already_exists', [
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                        'source' => 'upsert_guard',
                    ]);
                    $this->logger->info('SKIP', [
                        'mode' => 'missing_import',
                        'offer_id' => $offer_id,
                        'product_id' => (int) ($result['product_id'] ?? 0),
                        'reason' => 'already_exists_offer_or_sku',
                        'source' => 'upsert_guard',
                    ]);
                    continue;
                }

                $errors_in_batch++;
                $this->logger->error('MISSING_IMPORT_OFFER_FAILED', [
                    'offer_id' => $offer_id,
                    'result' => (string) ($result['result'] ?? 'unknown'),
                    'error' => (string) ($result['error'] ?? $result['reason'] ?? 'unknown_error'),
                    'stage' => (string) ($result['stage'] ?? 'product_upsert'),
                ]);
            }

            $next_offset = $offset + $batch_size;
            $has_more = $batch_size > 0 && (
                $total_count === null
                || $next_offset < $total_count
            );

            $checkpoint['current_offset'] = $has_more ? $next_offset : $next_offset;
            $checkpoint['total_checked'] = (int) ($checkpoint['total_checked'] ?? 0) + $checked_in_batch;
            $checkpoint['existing_skipped'] = (int) ($checkpoint['existing_skipped'] ?? 0) + $existing_skipped_in_batch;
            $checkpoint['missing_imported'] = (int) ($checkpoint['missing_imported'] ?? 0) + $missing_imported_in_batch;
            $checkpoint['errors'] = (int) ($checkpoint['errors'] ?? 0) + $errors_in_batch;
            $checkpoint['last_checked_offer_id'] = $last_checked_offer_id;
            $checkpoint['last_imported_offer_id'] = $last_imported_offer_id;
            $checkpoint['total_count'] = $total_count;
            $checkpoint['status'] = $has_more ? 'running' : 'completed';
            $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->save_missing_import_checkpoint($checkpoint);

            $summary = [
                'current_offset' => $offset,
                'next_offset' => $next_offset,
                'batch_size' => self::MISSING_IMPORT_BATCH_LIMIT,
                'checked_in_batch' => $checked_in_batch,
                'existing_skipped_in_batch' => $existing_skipped_in_batch,
                'missing_imported_in_batch' => $missing_imported_in_batch,
                'errors_in_batch' => $errors_in_batch,
                'total_checked' => $checkpoint['total_checked'],
                'existing_skipped' => $checkpoint['existing_skipped'],
                'missing_imported' => $checkpoint['missing_imported'],
                'errors' => $checkpoint['errors'],
                'total_count' => $checkpoint['total_count'],
                'last_checked_offer_id' => $checkpoint['last_checked_offer_id'],
                'elapsed_time' => round(max(0, microtime(true) - $started_at), 3),
            ];
            $this->logger->info('MISSING_IMPORT_BATCH_DONE', $summary);
            $this->logger->info('BATCH_DONE', ['mode' => 'missing_import'] + $summary);

            if (!$has_more) {
                $this->logger->info('MISSING_IMPORT_COMPLETED', $checkpoint);
                $this->logger->info('COMPLETED', ['mode' => 'missing_import'] + $checkpoint);
            }

            return $checkpoint;
        } catch (\Throwable $throwable) {
            $checkpoint['status'] = 'failed';
            $checkpoint['errors'] = (int) ($checkpoint['errors'] ?? 0) + 1;
            $checkpoint['updated_at'] = gmdate('Y-m-d H:i:s');
            $this->save_missing_import_checkpoint($checkpoint);
            $this->logger->error('MISSING_IMPORT_OFFER_FAILED', [
                'stage' => 'unexpected_exception',
                'error' => $throwable->getMessage(),
            ]);
            return $checkpoint;
        } finally {
            $this->release_import_lock($lock_context);
        }
    }

    public function sync_woo_sold_out_to_allegro(int $product_id): void
    {
        $product_id = max(0, $product_id);
        if ($product_id <= 0) {
            return;
        }

        $offer_id = sanitize_text_field((string) get_post_meta($product_id, '_allegro_offer_id', true));
        if ($offer_id === '') {
            $this->logger->warning('SYNC_WOO_SOLD_OUT_SKIPPED', [
                'product_id' => $product_id,
                'reason' => 'missing_allegro_offer_id_meta',
                'meta_key' => '_allegro_offer_id',
            ]);
            return;
        }

        $status = $this->client->get_offer_status_snapshot($offer_id);
        if (is_wp_error($status)) {
            $this->logger->error('SYNC_ERROR', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'action' => 'woo_sold_out_fetch_offer_status',
                'reason' => $status->get_error_message(),
            ]);
            return;
        }

        $publication_status = strtoupper((string) ($status['publication_status'] ?? 'INACTIVE'));
        if ($publication_status !== 'ACTIVE') {
            $this->logger->info('SYNC_WOO_SOLD_OUT_PUSHED_TO_ALLEGRO', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'action' => 'skip',
                'reason' => 'offer_already_not_active',
            ]);
            return;
        }

        $response = $this->client->set_offer_stock_to_zero($offer_id);
        if (is_wp_error($response)) {
            $this->logger->error('SYNC_ERROR', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'action' => 'woo_sold_out_set_offer_stock_zero',
                'reason' => $response->get_error_message(),
            ]);
            return;
        }

        $this->logger->info('SYNC_WOO_SOLD_OUT_PUSHED_TO_ALLEGRO', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'action' => 'set_offer_stock_zero',
            'reason' => 'woo_stock_reached_zero',
        ]);
    }

    private function finalize_summary(
        int $processed,
        int $created,
        int $updated,
        int $skipped,
        int $errors,
        int $fetched_from_api,
        ?int $total_count_from_api,
        string $last_processed_offer_id,
        ?float $started_at = null
    ): array
    {
        $elapsed_time = $started_at !== null ? round(max(0, microtime(true) - $started_at), 3) : null;
        $summary = [
            'date' => gmdate('Y-m-d H:i:s'),
            'offers' => $processed,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
            'fetched_from_api' => $fetched_from_api,
            'reported_total_count' => $total_count_from_api,
            'last_processed_offer_id' => $last_processed_offer_id,
            'elapsed_time' => $elapsed_time,
        ];

        Plugin::update_settings([
            'last_sync_at' => $summary['date'],
            'last_sync_offers' => $processed,
            'last_sync_created' => $created,
            'last_sync_updated' => $updated,
            'last_sync_errors' => $errors,
        ]);

        Plugin::add_history($summary);
        if ($errors > 0) {
            $this->logger->error('SYNC_ERROR', [
                'action' => 'sync_summary',
                'reason' => 'run_finished_with_errors',
                'errors' => $errors,
                'last_processed_offer_id' => $last_processed_offer_id,
            ]);
        }
        $this->logger->info('SYNC_DONE', $summary);

        return $summary;
    }

    private function ensure_runtime_limits(): void
    {
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) self::MAX_EXECUTION_TIME_SECONDS);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::MAX_EXECUTION_TIME_SECONDS);
        }
    }

    private function build_lock_context(): array
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field((string) wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $action = isset($_REQUEST['action']) ? sanitize_text_field((string) wp_unslash($_REQUEST['action'])) : '';
        $context = function_exists('wp_doing_cron') && wp_doing_cron() ? 'cron' : 'admin';

        return [
            'context' => $context,
            'action' => $action,
            'request_uri' => $request_uri,
            'timestamp' => time(),
        ];
    }

    private function acquire_import_lock(array $lock_context): bool
    {
        $payload = $lock_context + [
            'locked_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => time() + self::IMPORT_LOCK_TTL_SECONDS,
        ];

        if (add_option(self::IMPORT_LOCK_OPTION_KEY, $payload, '', false)) {
            return true;
        }

        $existing = get_option(self::IMPORT_LOCK_OPTION_KEY, []);
        $existing_expires_at = is_array($existing) ? (int) ($existing['expires_at'] ?? 0) : 0;
        if ($existing_expires_at > 0 && $existing_expires_at < time()) {
            delete_option(self::IMPORT_LOCK_OPTION_KEY);
            if (add_option(self::IMPORT_LOCK_OPTION_KEY, $payload, '', false)) {
                $this->logger->warning('Recovered stale import lock before new batch run.', [
                    'existing_lock' => $existing,
                    'new_lock' => $payload,
                ]);
                return true;
            }
        }

        $this->logger->warning('Import lock already active; batch run rejected.', [
            'existing_lock' => $existing,
            'incoming_lock' => $payload,
        ]);

        return false;
    }

    private function release_import_lock(array $lock_context): void
    {
        $existing = get_option(self::IMPORT_LOCK_OPTION_KEY, []);
        delete_option(self::IMPORT_LOCK_OPTION_KEY);

        $this->logger->info('Import lock released.', [
            'released_by' => $lock_context,
            'previous_lock' => $existing,
        ]);
    }

    private function load_checkpoint(): array
    {
        $checkpoint = get_option(self::CHECKPOINT_OPTION_KEY, []);
        if (!is_array($checkpoint)) {
            return [];
        }

        return [
            'offset' => max(0, (int) ($checkpoint['offset'] ?? 0)),
            'page_no' => max(1, (int) ($checkpoint['page_no'] ?? 1)),
            'page_token' => sanitize_text_field((string) ($checkpoint['page_token'] ?? '')),
            'offer_index' => max(0, (int) ($checkpoint['offer_index'] ?? 0)),
            'total_processed' => max(0, (int) ($checkpoint['total_processed'] ?? 0)),
            'total_count' => isset($checkpoint['total_count']) && is_numeric($checkpoint['total_count']) ? max(0, (int) $checkpoint['total_count']) : null,
            'updated_at' => sanitize_text_field((string) ($checkpoint['updated_at'] ?? '')),
        ];
    }

    private function save_checkpoint(array $checkpoint): void
    {
        update_option(self::CHECKPOINT_OPTION_KEY, $checkpoint, false);

        $this->logger->info('Import checkpoint saved.', [
            'offset' => (int) ($checkpoint['offset'] ?? 0),
            'page_no' => (int) ($checkpoint['page_no'] ?? 1),
            'page_token' => (string) ($checkpoint['page_token'] ?? ''),
            'offer_index' => (int) ($checkpoint['offer_index'] ?? 0),
            'total_processed' => (int) ($checkpoint['total_processed'] ?? 0),
            'total_count' => isset($checkpoint['total_count']) && is_numeric($checkpoint['total_count']) ? (int) $checkpoint['total_count'] : null,
        ]);
    }

    private function save_missing_import_checkpoint(array $checkpoint): void
    {
        update_option(self::MISSING_IMPORT_CHECKPOINT_OPTION_KEY, $checkpoint, false);
    }

    private function get_event_sync_checkpoint(): array
    {
        $checkpoint = get_option(self::EVENT_SYNC_CHECKPOINT_OPTION_KEY, []);
        if (!is_array($checkpoint)) {
            $checkpoint = [];
        }

        return [
            'last_offer_event_id' => sanitize_text_field((string) ($checkpoint['last_offer_event_id'] ?? '')),
            'last_order_event_id' => sanitize_text_field((string) ($checkpoint['last_order_event_id'] ?? '')),
            'last_success_ts' => max(0, (int) ($checkpoint['last_success_ts'] ?? 0)),
            'last_success_at' => sanitize_text_field((string) ($checkpoint['last_success_at'] ?? '')),
        ];
    }

    private function save_event_sync_checkpoint(array $checkpoint): void
    {
        update_option(self::EVENT_SYNC_CHECKPOINT_OPTION_KEY, $checkpoint, false);
        $this->logger->info('EVENT_SYNC_CHECKPOINT_SAVED', $checkpoint);
    }

    public function get_event_sync_status(): array
    {
        $status = get_option(self::EVENT_SYNC_STATUS_OPTION_KEY, []);
        if (!is_array($status)) {
            $status = [];
        }

        return [
            'last_run_at' => sanitize_text_field((string) ($status['last_run_at'] ?? '')),
            'last_run_mode' => sanitize_text_field((string) ($status['last_run_mode'] ?? '')),
            'last_status' => sanitize_text_field((string) ($status['last_status'] ?? '')),
            'last_error' => sanitize_text_field((string) ($status['last_error'] ?? '')),
            'checkpoint' => is_array($status['checkpoint'] ?? null) ? (array) $status['checkpoint'] : $this->get_event_sync_checkpoint(),
        ];
    }

    public function mark_fallback_full_import_started(string $reason): void
    {
        $checkpoint = $this->get_event_sync_checkpoint();
        $this->save_event_sync_status('fallback_full_import', 'started', $reason, $checkpoint);
    }

    private function calculate_page_no_from_offset(int $offset): int
    {
        return max(1, (int) floor(max(0, $offset) / self::BATCH_LIMIT) + 1);
    }

    private function reset_checkpoint(): void
    {
        delete_option(self::CHECKPOINT_OPTION_KEY);
    }

    private function normalize_events_list(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_array($payload[$key] ?? null)) {
                return (array) $payload[$key];
            }
        }

        return [];
    }

    private function extract_offer_ids_from_order_event(array $event): array
    {
        $ids = [];
        $line_items = is_array($event['lineItems'] ?? null) ? (array) $event['lineItems'] : [];
        foreach ($line_items as $line_item) {
            if (!is_array($line_item)) {
                continue;
            }

            $offer_id = sanitize_text_field((string) ($line_item['offer']['id'] ?? $line_item['offerId'] ?? ''));
            if ($offer_id !== '') {
                $ids[] = $offer_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function is_order_events_access_denied_error(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
        if ($status !== 403) {
            return false;
        }

        $body = is_array($data) ? (string) ($data['body'] ?? '') : '';
        if ($body === '') {
            return true;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return true;
        }

        $error_code = sanitize_text_field((string) ($decoded['errors'][0]['code'] ?? $decoded['code'] ?? ''));
        if ($error_code === '') {
            return true;
        }

        return strtolower($error_code) === 'accessdenied';
    }

    private function sync_single_offer_from_event(
        string $offer_id,
        string $inactive_status,
        array $settings,
        string $stream,
        string $event_id,
        string $event_type
    ): bool {
        if ($this->is_terminal_offer_event_type($event_type)) {
            $this->apply_archived_or_ended_offer_to_woo($offer_id, $inactive_status, $event_id, $event_type, $stream);
            return true;
        }

        $details = $this->client->get_offer_details($offer_id);
        if (is_wp_error($details)) {
            $reason = $details->get_error_code() === 'awi_api_error_404' ? 'offer_missing' : $details->get_error_message();
            if ($details->get_error_code() !== 'awi_api_error_404') {
                $this->logger->error('EVENT_SYNC_ERROR', [
                    'stage' => 'get_offer_details',
                    'stream' => $stream,
                    'event_id' => $event_id,
                    'event_type' => $event_type,
                    'offer_id' => $offer_id,
                    'reason' => $reason,
                ]);
                return false;
            }

            $this->apply_archived_or_ended_offer_to_woo($offer_id, $inactive_status, $event_id, $event_type, $stream);
            $this->logger->info('EVENT_SYNC_OFFER_ENDED', [
                'offer_id' => $offer_id,
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'reason' => 'offer_missing',
            ]);
            return true;
        }

        $result = $this->mapper->upsert_product($details, $settings);
        if (($result['result'] ?? '') === 'created') {
            $this->logger->info('EVENT_SYNC_NEW_OFFER_IMPORTED', [
                'offer_id' => $offer_id,
                'product_id' => (int) ($result['product_id'] ?? 0),
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
        } elseif (($result['result'] ?? '') === 'updated') {
            $this->logger->info('EVENT_SYNC_OFFER_UPDATED', [
                'offer_id' => $offer_id,
                'product_id' => (int) ($result['product_id'] ?? 0),
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
        } elseif (($result['result'] ?? '') === 'error') {
            $this->logger->error('EVENT_SYNC_ERROR', [
                'stage' => (string) ($result['stage'] ?? 'upsert_product'),
                'offer_id' => $offer_id,
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'reason' => (string) ($result['error'] ?? 'unknown_error'),
            ]);
            return false;
        }

        $publication_status = strtoupper((string) ($details['publication']['status'] ?? 'INACTIVE'));
        $stock_available = isset($details['stock']['available']) && is_numeric($details['stock']['available'])
            ? max(0, (int) $details['stock']['available'])
            : null;
        if ($publication_status !== 'ACTIVE' || $stock_available === 0) {
            $reason = $publication_status !== 'ACTIVE'
                ? 'publication_' . strtolower($publication_status)
                : 'stock_zero';
            $this->apply_inactive_state_by_offer_id($offer_id, $inactive_status, $reason);
            $this->logger->info('EVENT_SYNC_OFFER_ENDED', [
                'offer_id' => $offer_id,
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
                'reason' => $reason,
            ]);
        }

        if ($stream === 'order_events') {
            $this->apply_order_sold_state_by_offer_id($offer_id, $inactive_status, $event_id, $event_type);
        }

        return true;
    }

    private function is_terminal_offer_event_type(string $event_type): bool
    {
        $event_type = strtoupper(sanitize_text_field($event_type));
        return in_array($event_type, [
            'OFFER_ARCHIVED',
            'OFFER_ENDED',
            'OFFER_DEACTIVATED',
            'OFFER_FINISHED',
        ], true);
    }

    private function apply_archived_or_ended_offer_to_woo(
        string $offer_id,
        string $inactive_status,
        string $event_id,
        string $event_type,
        string $stream
    ): void {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 100,
            'meta_query' => [
                [
                    'key' => '_allegro_offer_id',
                    'value' => $offer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $product_ids = array_map('intval', (array) $query->posts);
        if (count($product_ids) === 0) {
            $this->logger->warning('missing_linked_product_for_offer', [
                'offer_id' => $offer_id,
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
            wp_reset_postdata();
            return;
        }

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
            $product->set_status($inactive_status);
            $product->save();

            $this->logger->info('EVENT_SYNC_OFFER_ARCHIVED_APPLIED_TO_WOO', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'post_status' => $inactive_status,
                'stock_status' => 'outofstock',
                'stream' => $stream,
                'event_id' => $event_id,
                'event_type' => $event_type,
            ]);
        }

        wp_reset_postdata();
    }

    private function apply_order_sold_state_by_offer_id(string $offer_id, string $inactive_status, string $event_id, string $event_type): void
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 100,
            'meta_query' => [
                [
                    'key' => '_allegro_offer_id',
                    'value' => $offer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        foreach (array_map('intval', (array) $query->posts) as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                continue;
            }

            $previous_stock = $product->get_stock_quantity();
            $previous_stock_status = (string) $product->get_stock_status();
            $previous_status = (string) $product->get_status();

            $product->set_manage_stock(true);
            $product->set_stock_quantity(0);
            $product->set_stock_status('outofstock');
            $product->set_status($inactive_status);
            $product->save();

            $this->logger->info('EVENT_SYNC_ORDER_APPLIED_TO_WOO', [
                'event_id' => $event_id,
                'event_type' => $event_type,
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'previous_stock' => $previous_stock,
                'new_stock' => 0,
                'previous_stock_status' => $previous_stock_status,
                'new_stock_status' => 'outofstock',
                'previous_product_status' => $previous_status,
                'new_product_status' => $inactive_status,
                'action' => 'set_outofstock_and_hide',
            ]);
        }

        wp_reset_postdata();
    }

    private function apply_inactive_state_by_offer_id(string $offer_id, string $inactive_status, string $reason): void
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 100,
            'meta_query' => [
                [
                    'key' => '_allegro_offer_id',
                    'value' => $offer_id,
                    'compare' => '=',
                ],
            ],
        ]);

        $settings = Plugin::get_settings();
        $dry_run = !empty($settings['destructive_sync_dry_run']);
        $destructive_enabled = !empty($settings['destructive_sync_enabled']);
        $changes = 0;
        foreach (array_map('intval', (array) $query->posts) as $product_id) {
            $this->apply_inactive_offer_state($product_id, $offer_id, $inactive_status, $dry_run, $destructive_enabled, $changes, $reason);
        }
        wp_reset_postdata();
    }

    private function save_event_sync_status(string $mode, string $status, string $error, array $checkpoint): void
    {
        update_option(self::EVENT_SYNC_STATUS_OPTION_KEY, [
            'last_run_at' => gmdate('Y-m-d H:i:s'),
            'last_run_mode' => $mode,
            'last_status' => $status,
            'last_error' => $error,
            'checkpoint' => $checkpoint,
        ], false);
    }

    private function sort_events_by_time_and_id(array $events): array
    {
        usort($events, static function ($a, $b): int {
            $a_time = is_array($a) ? strtotime((string) ($a['occurredAt'] ?? '')) : 0;
            $b_time = is_array($b) ? strtotime((string) ($b['occurredAt'] ?? '')) : 0;
            if ($a_time === $b_time) {
                $a_id = is_array($a) ? (string) ($a['id'] ?? '') : '';
                $b_id = is_array($b) ? (string) ($b['id'] ?? '') : '';
                return strcmp($a_id, $b_id);
            }

            return $a_time <=> $b_time;
        });

        return $events;
    }

    private function should_stop_for_runtime(float $started_at): bool
    {
        return (microtime(true) - $started_at) >= self::SOFT_RUNTIME_LIMIT_SECONDS;
    }

    private function extract_total_count(array $page, ?int $current): ?int
    {
        $candidates = [
            $page['totalCount'] ?? null,
            $page['total_count'] ?? null,
            $page['count']['total'] ?? null,
            $page['pagination']['totalCount'] ?? null,
            $page['searchMeta']['totalCount'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return max(0, (int) $candidate);
            }
        }

        return $current;
    }

    private function extract_next_page_token(array $page): string
    {
        $candidates = [
            $page['nextPageToken'] ?? null,
            $page['next_page_token'] ?? null,
            $page['page']['id'] ?? null,
            $page['page']['next'] ?? null,
            $page['pagination']['nextPageToken'] ?? null,
            $page['pagination']['next'] ?? null,
            $page['searchMeta']['nextPageToken'] ?? null,
            $page['links']['next'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    private function has_more_pages(
        int $batch_size,
        int $limit,
        int $offset,
        ?int $total_count_from_api,
        string $next_page_token
    ): bool {
        if ($next_page_token !== '') {
            return true;
        }

        if ($total_count_from_api !== null) {
            return $offset < $total_count_from_api;
        }

        if ($batch_size <= 0) {
            return false;
        }

        return true;
    }

    private function determine_cycle_completion_reason(
        int $batch_size,
        int $next_offset,
        ?int $total_count_from_api,
        string $next_page_token
    ): string {
        if ($next_page_token !== '') {
            return 'next_page_token_present';
        }

        if ($total_count_from_api !== null) {
            return $next_offset < $total_count_from_api
                ? 'offset_below_total_count'
                : 'offset_reached_or_exceeded_total_count';
        }

        return $batch_size > 0
            ? 'fallback_continue_until_empty_batch'
            : 'fallback_empty_batch';
    }

    private function is_cycle_start(int $offset, int $page_no, int $resume_offer_index): bool
    {
        return $offset === 0 && $page_no === 1 && $resume_offer_index === 0;
    }

    private function reset_active_seen_offer_ids(): void
    {
        delete_option(self::ACTIVE_SEEN_OFFERS_OPTION_KEY);
    }

    private function load_active_seen_offer_ids(): array
    {
        $value = get_option(self::ACTIVE_SEEN_OFFERS_OPTION_KEY, []);
        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $offer_id) {
            $offer_id = sanitize_text_field((string) $offer_id);
            if ($offer_id !== '') {
                $clean[$offer_id] = true;
            }
        }

        return $clean;
    }

    private function remember_active_offer_id(string $offer_id): void
    {
        $offer_id = sanitize_text_field($offer_id);
        if ($offer_id === '') {
            return;
        }

        $seen = $this->load_active_seen_offer_ids();
        if (isset($seen[$offer_id])) {
            return;
        }

        $seen[$offer_id] = true;
        update_option(self::ACTIVE_SEEN_OFFERS_OPTION_KEY, array_keys($seen), false);
    }

    private function sync_linked_products_by_offer_id(string $inactive_status): int
    {
        $settings = Plugin::get_settings();
        $inactive_status = in_array($inactive_status, ['draft', 'private'], true) ? $inactive_status : 'draft';
        $destructive_enabled = !empty($settings['destructive_sync_enabled']);
        $dry_run = !empty($settings['destructive_sync_dry_run']);
        $max_changes = max(1, (int) ($settings['destructive_sync_max_changes'] ?? 10));

        $changes = 0;
        $page = 1;

        do {
            $query = new \WP_Query([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => self::OFFER_STATUS_SYNC_BATCH_SIZE,
                'paged' => $page,
                'meta_query' => [
                    [
                        'key' => '_allegro_offer_id',
                        'compare' => 'EXISTS',
                    ],
                ],
            ]);

            $product_ids = array_map('intval', (array) $query->posts);
            foreach ($product_ids as $product_id) {
                if ($changes >= $max_changes) {
                    break 2;
                }

                $offer_id = sanitize_text_field((string) get_post_meta($product_id, '_allegro_offer_id', true));
                if ($offer_id === '') {
                    continue;
                }

                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $reason = $details->get_error_code() === 'awi_api_error_404' ? 'offer_missing' : 'api_error';
                    if ($reason !== 'offer_missing') {
                        $this->logger->error('SYNC_ERROR', [
                            'offer_id' => $offer_id,
                            'product_id' => $product_id,
                            'action' => 'fetch_offer_status',
                            'reason' => $details->get_error_message(),
                        ]);
                        continue;
                    }

                    $this->apply_inactive_offer_state($product_id, $offer_id, $inactive_status, $dry_run, $destructive_enabled, $changes, 'offer_missing');
                    continue;
                }

                $publication_status = strtoupper((string) ($details['publication']['status'] ?? 'INACTIVE'));
                $stock_available = isset($details['stock']['available']) && is_numeric($details['stock']['available'])
                    ? max(0, (int) $details['stock']['available'])
                    : null;

                if ($publication_status !== 'ACTIVE' || $stock_available === 0) {
                    $reason = $publication_status !== 'ACTIVE'
                        ? 'publication_' . strtolower($publication_status)
                        : 'stock_zero';
                    $this->apply_inactive_offer_state($product_id, $offer_id, $inactive_status, $dry_run, $destructive_enabled, $changes, $reason);
                }
            }

            $page++;
            wp_reset_postdata();
        } while ($page <= (int) $query->max_num_pages);

        return $changes;
    }

    private function apply_inactive_offer_state(
        int $product_id,
        string $offer_id,
        string $inactive_status,
        bool $dry_run,
        bool $destructive_enabled,
        int &$changes,
        string $reason
    ): void {
        $product = wc_get_product($product_id);
        if (!$product instanceof \WC_Product) {
            return;
        }

        if (!$destructive_enabled || $dry_run) {
            $this->logger->warning('SYNC_ALLEGRO_MISSING_OR_ENDED', [
                'offer_id' => $offer_id,
                'product_id' => $product_id,
                'action' => 'dry_run_skip_destructive',
                'reason' => $reason,
                'destructive_sync_enabled' => $destructive_enabled,
                'dry_run' => $dry_run,
            ]);
            return;
        }

        $product->set_manage_stock(true);
        $product->set_stock_quantity(0);
        $product->set_stock_status('outofstock');
        $product->set_status($inactive_status);
        $product->save();

        $changes++;
        $this->logger->info('SYNC_ALLEGRO_MISSING_OR_ENDED', [
            'offer_id' => $offer_id,
            'product_id' => $product_id,
            'action' => 'set_outofstock_and_hide',
            'reason' => $reason,
            'status_after' => $inactive_status,
        ]);
    }

    public function restore_active_offers_to_instock(): array
    {
        $restored = 0;
        $checked = 0;
        $errors = 0;
        $page = 1;

        do {
            $query = new \WP_Query([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 100,
                'paged' => $page,
                'meta_query' => [
                    [
                        'key' => '_allegro_offer_id',
                        'compare' => 'EXISTS',
                    ],
                    [
                        'relation' => 'OR',
                        [
                            'key' => '_stock_status',
                            'value' => 'outofstock',
                            'compare' => '=',
                        ],
                        [
                            'key' => '_stock',
                            'value' => 0,
                            'type' => 'NUMERIC',
                            'compare' => '<=',
                        ],
                    ],
                ],
            ]);

            $product_ids = array_map('intval', (array) $query->posts);
            foreach ($product_ids as $product_id) {
                $offer_id = sanitize_text_field((string) get_post_meta($product_id, '_allegro_offer_id', true));
                if ($offer_id === '') {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product instanceof \WC_Product) {
                    continue;
                }

                $checked++;
                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $errors++;
                    $this->logger->error('Recovery failed to fetch offer details.', [
                        'product_id' => $product_id,
                        'offer_id' => $offer_id,
                        'error' => $details->get_error_message(),
                    ]);
                    continue;
                }

                $publication_status = strtoupper((string) ($details['publication']['status'] ?? 'INACTIVE'));
                if ($publication_status !== 'ACTIVE') {
                    continue;
                }

                $stock_raw = $details['stock']['available'] ?? null;
                $stock_available = is_numeric($stock_raw) ? max(0, (int) $stock_raw) : null;
                $stock_after = ($stock_available !== null && $stock_available > 0) ? $stock_available : 1;

                $status_before = (string) $product->get_status();
                $stock_before = $product->get_stock_quantity();
                $stock_status_before = (string) $product->get_stock_status();

                $product->set_manage_stock(true);
                $product->set_stock_quantity($stock_after);
                $product->set_stock_status('instock');
                $product->set_status('publish');
                $product->save();

                $restored++;
                $this->logger->info('Product restored to instock during recovery.', [
                    'product_id' => $product_id,
                    'offer_id' => $offer_id,
                    'reason' => 'recovery_offer_active',
                    'status_before' => $status_before,
                    'status_after' => 'publish',
                    'stock_before' => $stock_before,
                    'stock_after' => $stock_after,
                    'stock_status_before' => $stock_status_before,
                    'stock_status_after' => 'instock',
                ]);
            }

            $page++;
            wp_reset_postdata();
        } while ($page <= (int) $query->max_num_pages);

        $summary = [
            'checked' => $checked,
            'restored' => $restored,
            'errors' => $errors,
        ];
        $this->logger->info('Recovery pass completed.', $summary);

        return $summary;
    }

    private function initialize_cycle_state(): void
    {
        update_option(self::CYCLE_STATE_OPTION_KEY, [
            'started_at' => gmdate('Y-m-d H:i:s'),
            'has_errors' => false,
            'reconciliation_allowed' => true,
        ], false);
    }

    private function clear_cycle_state(): void
    {
        delete_option(self::CYCLE_STATE_OPTION_KEY);
    }

    private function load_cycle_state(): array
    {
        $state = get_option(self::CYCLE_STATE_OPTION_KEY, []);
        if (!is_array($state)) {
            return [];
        }

        return [
            'started_at' => sanitize_text_field((string) ($state['started_at'] ?? '')),
            'has_errors' => !empty($state['has_errors']),
            'reconciliation_allowed' => !isset($state['reconciliation_allowed']) || (bool) $state['reconciliation_allowed'],
            'last_error_reason' => sanitize_text_field((string) ($state['last_error_reason'] ?? '')),
            'last_error_at' => sanitize_text_field((string) ($state['last_error_at'] ?? '')),
        ];
    }

    private function mark_cycle_state_error(string $reason): void
    {
        $state = $this->load_cycle_state();
        if (empty($state)) {
            return;
        }

        $state['has_errors'] = true;
        $state['reconciliation_allowed'] = false;
        $state['last_error_reason'] = sanitize_text_field($reason);
        $state['last_error_at'] = gmdate('Y-m-d H:i:s');
        update_option(self::CYCLE_STATE_OPTION_KEY, $state, false);
    }

    private function can_run_reconciliation(?int $total_count_from_api): bool
    {
        if ($total_count_from_api === null) {
            return false;
        }

        $state = $this->load_cycle_state();
        if (empty($state)) {
            return false;
        }

        return !$state['has_errors'] && !empty($state['reconciliation_allowed']);
    }
}
