<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Importer
{
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
        $processed = 0;
        $created = 0;
        $updated = 0;
        $errors = 0;

        do {
            $page = $this->client->get_offers((string) $settings['offer_status'], $offset, $limit);
            if (is_wp_error($page)) {
                $errors++;
                $this->logger->error('Failed to fetch offers page.', ['offset' => $offset, 'error' => $page->get_error_message()]);
                break;
            }

            $offers = $page['offers'] ?? [];
            if (empty($offers) || !is_array($offers)) {
                break;
            }

            foreach ($offers as $offer_basic) {
                $offer_id = sanitize_text_field((string) ($offer_basic['id'] ?? ''));
                if ($offer_id === '') {
                    continue;
                }

                $details = $this->client->get_offer_details($offer_id);
                if (is_wp_error($details)) {
                    $errors++;
                    $this->logger->error('Failed to fetch offer details.', ['offer_id' => $offer_id, 'error' => $details->get_error_message()]);
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
                    $this->logger->error('Product upsert failed.', ['offer_id' => $offer_id, 'error' => $result['error'] ?? 'unknown']);
                }
            }

            $offset += $limit;
            $total_count = isset($page['count']) ? (int) $page['count'] : ($offset + count($offers));
            $has_more = $offset < $total_count && count($offers) === $limit;
        } while ($has_more);

        $summary = [
            'date' => gmdate('Y-m-d H:i:s'),
            'offers' => $processed,
            'created' => $created,
            'updated' => $updated,
            'errors' => $errors,
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
}
