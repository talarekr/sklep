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
    private const BATCH_LIMIT = 100;
    private const MAX_EXECUTION_TIME_SECONDS = 900;
    private const SOFT_RUNTIME_LIMIT_SECONDS = 840;
    private const RECONCILIATION_SAFETY_LOCK = true;

    private AllegroClient $client;
    private ProductMapper $mapper;
    private Logger $logger;

    public function __construct(AllegroClient $client, ProductMapper $mapper, Logger $logger)
    {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->logger = $logger;
    }

    public function import_offers(): array
    {
        $this->ensure_runtime_limits();
        $settings = Plugin::get_settings();
        $started_at = microtime(true);

        $checkpoint = $this->load_checkpoint();
        $offset = (int) ($checkpoint['offset'] ?? 0);
        $page_no = max(1, (int) ($checkpoint['page_no'] ?? 1));
        $page_token = (string) ($checkpoint['page_token'] ?? '');
        $resume_offer_index = max(0, (int) ($checkpoint['offer_index'] ?? 0));

        $processed = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $fetched_from_api = 0;
        $total_count_from_api = null;
        $last_processed_offer_id = '';

        $this->logger->info('Import batch start (checkpoint resume).', [
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

            $details = $this->client->get_offer_details($offer_id);
            if (is_wp_error($details)) {
                $errors++;
                $this->mark_cycle_state_error('offer_details_fetch_failed');
                $this->logger->error('Failed to fetch offer details.', [
                    'offer_id' => $offer_id,
                    'error' => $details->get_error_message(),
                ]);
                continue;
            }

            $result = $this->mapper->upsert_product($details, $settings);
            $processed++;

            if (($result['result'] ?? '') === 'created') {
                $created++;
            } elseif (($result['result'] ?? '') === 'updated') {
                $updated++;
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
                $this->logger->error('Product upsert failed.', [
                    'offer_id' => $offer_id,
                    'error' => (string) ($result['error'] ?? 'unknown_error'),
                ]);
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
            $deactivated_count = $this->sync_missing_active_offers_to_hidden((string) $settings['inactive_product_status'], $total_count_from_api);
            $this->reset_checkpoint();
            $this->reset_active_seen_offer_ids();
            $this->clear_cycle_state();
            $this->logger->info('Reached end of offers, checkpoint reset for next sync cycle.', [
                'last_offset' => $next_offset,
                'processed_in_run' => $processed,
                'reported_total_count' => $total_count_from_api,
                'deactivated_missing_active_offers' => $deactivated_count,
                'created_in_run' => $created,
                'updated_in_run' => $updated,
                'skipped_in_run' => $skipped,
                'errors_in_run' => $errors,
                'elapsed_time' => round(microtime(true) - $started_at, 3),
                'last_processed_offer_id' => $last_processed_offer_id,
            ]);
        }

        return $this->finalize_summary($processed, $created, $updated, $skipped, $errors, $fetched_from_api, $total_count_from_api, $last_processed_offer_id, $started_at);
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
        $this->logger->info('Import finished.', $summary);

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

    private function reset_checkpoint(): void
    {
        delete_option(self::CHECKPOINT_OPTION_KEY);
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

    private function sync_missing_active_offers_to_hidden(string $inactive_status, ?int $total_count_from_api): int
    {
        if (self::RECONCILIATION_SAFETY_LOCK) {
            $this->logger->warning('Reconciliation safety lock is enabled; destructive product state changes are blocked.', [
                'inactive_status_requested' => $inactive_status,
            ]);
            return 0;
        }

        $settings = Plugin::get_settings();
        $inactive_status = in_array($inactive_status, ['draft', 'private'], true) ? $inactive_status : 'draft';

        if (empty($settings['reconciliation_enabled'])) {
            $this->logger->info('Skipping reconciliation because feature flag is disabled.', [
                'reconciliation_enabled' => false,
            ]);
            return 0;
        }

        if (!$this->can_run_reconciliation($total_count_from_api)) {
            $this->logger->warning('Skipping reconciliation because sync cycle is not confirmed as complete.', [
                'cycle_state' => $this->load_cycle_state(),
                'reported_total_count' => $total_count_from_api,
            ]);
            return 0;
        }

        $seen_offer_ids = array_keys($this->load_active_seen_offer_ids());
        $seen_count = count($seen_offer_ids);
        $proposed_to_hide = $this->count_products_missing_seen_offers($seen_offer_ids);
        $evaluation = $this->evaluate_reconciliation_readiness($settings, $total_count_from_api, $seen_count);
        $this->logger->info('Reconciliation diagnostics.', [
            'reconciliation_enabled' => !empty($settings['reconciliation_enabled']),
            'reconciliation_allowed' => $evaluation['allowed'],
            'reason' => $evaluation['reason'],
            'total_active_offers_seen' => $seen_count,
            'expected_total_count' => $total_count_from_api,
            'products_proposed_for_hide' => $proposed_to_hide,
            'offer_status_filter' => (string) ($settings['offer_status'] ?? ''),
            'cycle_state' => $this->load_cycle_state(),
        ]);

        if (!$evaluation['allowed']) {
            $this->logger->warning('Reconciliation blocked. Products will not be changed.', [
                'reason' => $evaluation['reason'],
            ]);
            return 0;
        }

        $processed = 0;
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
                ],
            ]);

            $product_ids = array_map('intval', (array) $query->posts);
            foreach ($product_ids as $product_id) {
                $offer_id = sanitize_text_field((string) get_post_meta($product_id, '_allegro_offer_id', true));
                if ($offer_id === '' || in_array($offer_id, $seen_offer_ids, true)) {
                    continue;
                }

                $product = wc_get_product($product_id);
                if (!$product instanceof \WC_Product) {
                    continue;
                }

                $status_before = (string) $product->get_status();
                $stock_before = $product->get_stock_quantity();
                $stock_status_before = (string) $product->get_stock_status();

                $product->set_manage_stock(true);
                $product->set_stock_quantity(0);
                $product->set_stock_status('outofstock');
                $product->set_status($inactive_status);
                $product->save();

                $processed++;
                $this->logger->info('Product marked as outofstock during reconciliation.', [
                    'product_id' => $product_id,
                    'offer_id' => $offer_id,
                    'reason' => 'reconciliation_missing_offer_after_full_sync',
                    'status_before' => $status_before,
                    'status_after' => $inactive_status,
                    'stock_before' => $stock_before,
                    'stock_status_before' => $stock_status_before,
                ]);
            }

            $page++;
            wp_reset_postdata();
        } while ($page <= (int) $query->max_num_pages);

        return $processed;
    }

    private function count_products_missing_seen_offers(array $seen_offer_ids): int
    {
        $seen_offer_ids = array_values(array_filter(array_map(static function ($offer_id): string {
            return sanitize_text_field((string) $offer_id);
        }, $seen_offer_ids), static function (string $offer_id): bool {
            return $offer_id !== '';
        }));

        $total_missing = 0;
        $page = 1;

        do {
            $query = new \WP_Query([
                'post_type' => 'product',
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 200,
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
                $offer_id = sanitize_text_field((string) get_post_meta($product_id, '_allegro_offer_id', true));
                if ($offer_id === '' || in_array($offer_id, $seen_offer_ids, true)) {
                    continue;
                }

                $total_missing++;
            }

            $page++;
            wp_reset_postdata();
        } while ($page <= (int) $query->max_num_pages);

        return $total_missing;
    }

    private function evaluate_reconciliation_readiness(array $settings, ?int $total_count_from_api, int $seen_count): array
    {
        if (empty($settings['reconciliation_enabled'])) {
            return [
                'allowed' => false,
                'reason' => 'feature_flag_disabled',
            ];
        }

        if ($total_count_from_api === null) {
            return [
                'allowed' => false,
                'reason' => 'total_count_unavailable',
            ];
        }

        if ($seen_count > $total_count_from_api) {
            return [
                'allowed' => false,
                'reason' => 'seen_count_exceeds_reported_total',
            ];
        }

        return [
            'allowed' => true,
            'reason' => 'ready',
        ];
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
