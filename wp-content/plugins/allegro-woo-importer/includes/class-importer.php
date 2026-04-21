<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Importer
{
    private const CHECKPOINT_OPTION_KEY = 'awi_import_checkpoint';
    private const ACTIVE_SEEN_OFFERS_OPTION_KEY = 'awi_active_seen_offer_ids';
    private const BATCH_LIMIT = 40;
    private const SOFT_RUNTIME_LIMIT_SECONDS = 20;

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
        $errors = 0;
        $fetched_from_api = 0;
        $total_count_from_api = null;

        $this->logger->info('Import batch start (checkpoint resume).', [
            'offset' => $offset,
            'page_no' => $page_no,
            'page_token' => $page_token,
            'resume_offer_index' => $resume_offer_index,
            'batch_limit' => self::BATCH_LIMIT,
        ]);

        if ($this->is_cycle_start($offset, $page_no, $resume_offer_index)) {
            $this->reset_active_seen_offer_ids();
            $this->logger->info('Reset active offers set for a new sync cycle.');
        }

        $page = $this->client->get_offers((string) $settings['offer_status'], $offset, self::BATCH_LIMIT, $page_token);
        if (is_wp_error($page)) {
            $errors++;
            $this->logger->error('Failed to fetch offers batch.', [
                'page_no' => $page_no,
                'offset' => $offset,
                'page_token' => $page_token,
                'error' => $page->get_error_message(),
            ]);

            return $this->finalize_summary($processed, $created, $updated, $errors, $fetched_from_api, $total_count_from_api);
        }

        $offers = $page['offers'] ?? [];
        if (!is_array($offers) || $offers === []) {
            $this->reset_checkpoint();
            $this->logger->info('Offers batch is empty, checkpoint reset.', [
                'page_no' => $page_no,
                'offset' => $offset,
                'page_token' => $page_token,
            ]);

            return $this->finalize_summary($processed, $created, $updated, $errors, $fetched_from_api, $total_count_from_api);
        }

        $batch_size = count($offers);
        $fetched_from_api = $batch_size;
        $total_count_from_api = $this->extract_total_count($page, $total_count_from_api);

        $this->logger->info('Fetched Allegro offers batch.', [
            'page_no' => $page_no,
            'offset' => $offset,
            'page_token' => $page_token,
            'batch_size' => $batch_size,
            'resume_offer_index' => $resume_offer_index,
            'reported_total_count' => $total_count_from_api,
        ]);

        $start_index = min($resume_offer_index, $batch_size);

        for ($index = $start_index; $index < $batch_size; $index++) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(20);
            }

            $offer_basic = $offers[$index] ?? [];
            $offer_id = sanitize_text_field((string) ($offer_basic['id'] ?? ''));
            if ($offer_id === '') {
                continue;
            }
            $this->remember_active_offer_id($offer_id);

            $details = $this->client->get_offer_details($offer_id);
            if (is_wp_error($details)) {
                $errors++;
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
            } elseif (($result['result'] ?? '') === 'error') {
                $errors++;
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

                return $this->finalize_summary($processed, $created, $updated, $errors, $fetched_from_api, $total_count_from_api);
            }
        }

        $next_page_token = $this->extract_next_page_token($page);
        $next_offset = $offset + $batch_size;
        $next_page_no = $page_no + 1;
        $has_more = $this->has_more_pages($batch_size, self::BATCH_LIMIT, $next_offset, $total_count_from_api, $next_page_token);

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
                'processed_in_run' => $processed,
                'batch_size' => $batch_size,
            ]);
        } else {
            $deactivated_count = $this->sync_missing_active_offers_to_hidden((string) $settings['inactive_product_status']);
            $this->reset_checkpoint();
            $this->reset_active_seen_offer_ids();
            $this->logger->info('Reached end of offers, checkpoint reset for next sync cycle.', [
                'last_offset' => $next_offset,
                'processed_in_run' => $processed,
                'reported_total_count' => $total_count_from_api,
                'deactivated_missing_active_offers' => $deactivated_count,
            ]);
        }

        return $this->finalize_summary($processed, $created, $updated, $errors, $fetched_from_api, $total_count_from_api);
    }

    private function finalize_summary(int $processed, int $created, int $updated, int $errors, int $fetched_from_api, ?int $total_count_from_api): array
    {
        $summary = [
            'date' => gmdate('Y-m-d H:i:s'),
            'offers' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
            'fetched_from_api' => $fetched_from_api,
            'reported_total_count' => $total_count_from_api,
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
            $page['page']['next'] ?? null,
            $page['pagination']['next'] ?? null,
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

        return $batch_size === $limit;
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

    private function sync_missing_active_offers_to_hidden(string $inactive_status): int
    {
        $inactive_status = in_array($inactive_status, ['draft', 'private'], true) ? $inactive_status : 'draft';
        $seen_offer_ids = array_keys($this->load_active_seen_offer_ids());
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
                $this->logger->info('Product hidden because offer is not active / missing in active offers sync.', [
                    'product_id' => $product_id,
                    'offer_id' => $offer_id,
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
}
