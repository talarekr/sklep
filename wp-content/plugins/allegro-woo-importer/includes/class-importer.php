<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Importer
{
    private const CHECKPOINT_OPTION_KEY = 'awi_import_checkpoint';
    private const BATCH_LIMIT = 40;

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

        $offset = 0;
        $limit = 100;
        $page_no = 1;
        $page_token = '';
        $processed = 0;
        $created = 0;
        $updated = 0;
        $errors = 0;
        $fetched_from_api = 0;
        $seen_offer_ids = [];
        $total_count_from_api = null;

        do {
            if (function_exists('set_time_limit')) {
                @set_time_limit(30);
            }

            $page = $this->client->get_offers((string) $settings['offer_status'], $offset, $limit, $page_token);
            if (is_wp_error($page)) {
                $errors++;
                $this->logger->error('Failed to fetch offers page.', [
                    'page_no' => $page_no,
                    'offset' => $offset,
                    'page_token' => $page_token,
                    'error' => $page->get_error_message(),
                ]);
                break;
            }

            $offers = $page['offers'] ?? [];
            if (empty($offers) || !is_array($offers)) {
                $this->logger->info('Offers page returned no results.', [
                    'page_no' => $page_no,
                    'offset' => $offset,
                    'page_token' => $page_token,
                    'total_fetched_from_api' => $fetched_from_api,
                ]);
                break;
            }

            $batch_size = count($offers);
            $fetched_from_api += $batch_size;
            $total_count_from_api = $this->extract_total_count($page, $total_count_from_api);

            $this->logger->info('Fetched offers batch from Allegro API.', [
                'page_no' => $page_no,
                'offset' => $offset,
                'page_token' => $page_token,
                'batch_size' => $batch_size,
                'total_fetched_from_api' => $fetched_from_api,
                'reported_total_count' => $total_count_from_api,
            ]);

            foreach ($offers as $offer_basic) {
                $offer_id = sanitize_text_field((string) ($offer_basic['id'] ?? ''));
                if ($offer_id === '') {
                    continue;
                }

                if (isset($seen_offer_ids[$offer_id])) {
                    $this->logger->warning('Skipping duplicate offer id from paginated API response.', [
                        'offer_id' => $offer_id,
                        'page_no' => $page_no,
                        'offset' => $offset,
                    ]);
                    continue;
                }
                $seen_offer_ids[$offer_id] = true;

                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $errors++;
                    $this->logger->error('Failed to fetch offer details.', ['offer_id' => $offer_id, 'error' => $details->get_error_message()]);
                    continue;
                }

                $next_page_token = $this->extract_next_page_token($page);
                $next_offset = $offset + $batch_size;
                $next_page_no = $page_no + 1;
                $has_more = $this->has_more_pages($batch_size, $limit, $next_offset, $total_count_from_api, $next_page_token);

                if ($has_more) {
                    $this->save_checkpoint([
                        'offset' => $next_offset,
                        'page_no' => $next_page_no,
                        'page_token' => $next_page_token !== '' ? $next_page_token : '',
                        'total_processed' => (int) ($checkpoint['total_processed'] ?? 0) + $processed,
                        'total_count' => $total_count_from_api,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                } else {
                    $this->reset_checkpoint();
                    $this->logger->info('Import checkpoint reset after reaching end of offers.', [
                        'last_offset' => $next_offset,
                        'reported_total_count' => $total_count_from_api,
                    ]);
                }
            } else {
                $this->reset_checkpoint();
                $this->logger->info('Offers page returned no results. Checkpoint reset.', [
                    'offset' => $offset,
                    'page_no' => $page_no,
                    'page_token' => $page_token,
                ]);
            }

            $next_page_token = $this->extract_next_page_token($page);
            if ($next_page_token !== '') {
                $page_token = $next_page_token;
            }

            $offset += $batch_size;
            $page_no++;
            $has_more = $this->has_more_pages($batch_size, $limit, $offset, $total_count_from_api, $next_page_token);
        } while ($has_more);

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
}
