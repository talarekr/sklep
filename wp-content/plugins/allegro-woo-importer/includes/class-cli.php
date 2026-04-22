<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Cli
{
    private const CHECKPOINT_OPTION_KEY = 'awi_listing_images_regen_checkpoint';

    private ProductMapper $mapper;
    private Logger $logger;

    public function __construct(ProductMapper $mapper, Logger $logger)
    {
        $this->mapper = $mapper;
        $this->logger = $logger;
    }

    public function register(): void
    {
        if (!defined('WP_CLI') || !\WP_CLI) {
            return;
        }

        \WP_CLI::add_command('awi listing-images regenerate', [$this, 'regenerate_listing_images']);
    }

    /**
     * Regeneruje wariant zdjęcia listingowego dla istniejących produktów.
     *
     * [--batch-size=<n>]
     * : Wielkość partii (domyślnie 100).
     *
     * [--force]
     * : Wymuś przebudowę także dla już przetworzonych produktów.
     *
     * [--reset]
     * : Usuń checkpoint i rozpocznij od początku.
     */
    public function regenerate_listing_images(array $args, array $assoc_args): void
    {
        $batch_size = isset($assoc_args['batch-size']) ? max(1, (int) $assoc_args['batch-size']) : 100;
        $force = isset($assoc_args['force']);
        $reset = isset($assoc_args['reset']);

        if ($reset) {
            delete_option(self::CHECKPOINT_OPTION_KEY);
        }

        $checkpoint = get_option(self::CHECKPOINT_OPTION_KEY, []);
        if (!is_array($checkpoint)) {
            $checkpoint = [];
        }

        $last_product_id = max(0, (int) ($checkpoint['last_product_id'] ?? 0));
        $processed_total = max(0, (int) ($checkpoint['processed_total'] ?? 0));
        $created_total = max(0, (int) ($checkpoint['created_total'] ?? 0));
        $skipped_total = max(0, (int) ($checkpoint['skipped_total'] ?? 0));
        $error_total = max(0, (int) ($checkpoint['error_total'] ?? 0));

        global $wpdb;
        $posts_table = $wpdb->posts;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$posts_table}
            WHERE post_type = 'product'
              AND post_status IN ('publish', 'draft', 'pending', 'private')
              AND ID > %d
            ORDER BY ID ASC
            LIMIT %d",
            $last_product_id,
            $batch_size
        ));

        if (!is_array($ids) || empty($ids)) {
            delete_option(self::CHECKPOINT_OPTION_KEY);
            \WP_CLI::success('Brak kolejnych produktów do przetworzenia. Checkpoint wyczyszczony.');
            return;
        }

        foreach ($ids as $raw_id) {
            $product_id = (int) $raw_id;
            $result = $this->mapper->ensure_listing_image_for_product($product_id, $force);
            $status = (string) ($result['status'] ?? 'error');

            $processed_total++;
            $last_product_id = $product_id;

            if ($status === 'created') {
                $created_total++;
                \WP_CLI::log(sprintf('✓ %d: wygenerowano listing image (ID %d)', $product_id, (int) ($result['listing_image_id'] ?? 0)));
            } elseif ($status === 'skipped') {
                $skipped_total++;
                \WP_CLI::log(sprintf('- %d: pominięto (%s)', $product_id, (string) ($result['reason'] ?? 'brak')));
            } else {
                $error_total++;
                $error_message = (string) ($result['error_message'] ?? ($result['reason'] ?? 'unknown'));
                \WP_CLI::warning(sprintf('%d: błąd (%s)', $product_id, $error_message));
                $this->logger->error('Listing image regeneration failed.', [
                    'product_id' => $product_id,
                    'result' => $result,
                ]);
            }
        }

        update_option(self::CHECKPOINT_OPTION_KEY, [
            'last_product_id' => $last_product_id,
            'processed_total' => $processed_total,
            'created_total' => $created_total,
            'skipped_total' => $skipped_total,
            'error_total' => $error_total,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], false);

        \WP_CLI::success(sprintf(
            'Partia zakończona. processed=%d created=%d skipped=%d errors=%d next_after_id=%d',
            $processed_total,
            $created_total,
            $skipped_total,
            $error_total,
            $last_product_id
        ));
    }
}
