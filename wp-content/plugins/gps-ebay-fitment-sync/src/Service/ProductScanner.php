<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class ProductScanner
{
    private Database $database;
    private PartNumberNormalizer $normalizer;
    private Settings $settings;

    public function __construct(Database $database, PartNumberNormalizer $normalizer, Settings $settings)
    {
        $this->database = $database;
        $this->normalizer = $normalizer;
        $this->settings = $settings;
    }

    public function meta_keys(): array
    {
        $keys = [
            '_manufacturer_code',
            'manufacturer_code',
            '_ovoko_manufacturer_code',
            'ovoko_manufacturer_code',
            '_visible_code',
            'visible_code',
            '_part_number',
            'part_number',
        ];

        return apply_filters('gps_ebay_fitment_sync_part_number_meta_keys', $keys);
    }

    public function scan(int $limit = 100, int $offset = 0, bool $persistMap = false): array
    {
        global $wpdb;

        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $postTypes = apply_filters('gps_ebay_fitment_sync_product_post_types', ['product', 'product_variation']);
        $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
        $params = array_merge($postTypes, [$limit, $offset]);
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('trash', 'auto-draft') ORDER BY ID ASC LIMIT %d OFFSET %d",
            $params
        )) ?: [];

        $rows = [];
        $unique = [];
        foreach ($ids as $productId) {
            $resolved = $this->resolve_product_part_number((int) $productId);
            if (!$resolved) {
                continue;
            }
            $normalized = $this->normalizer->normalize($resolved['part_number_raw']);
            if ($normalized === '') {
                continue;
            }
            $row = [
                'product_id' => (int) $productId,
                'sku' => (string) get_post_meta((int) $productId, '_sku', true),
                'part_number_raw' => $resolved['part_number_raw'],
                'part_number_normalized' => $normalized,
                'source_field' => $resolved['source_field'],
            ];
            $rows[] = $row;
            $unique[$normalized] = $resolved['part_number_raw'];
            if ($persistMap) {
                $this->database->upsert_product_map($row);
            }
        }

        $cached = $this->database->count_cached(array_keys($unique));

        return [
            'total_scanned_products' => count($ids),
            'products_with_part_number' => count($rows),
            'unique_normalized_part_numbers' => count($unique),
            'already_cached_count' => $cached,
            'not_cached_count' => max(0, count($unique) - $cached),
            'sample' => array_slice($rows, 0, 25),
            'unique_part_numbers' => $unique,
            'persisted_product_map' => $persistMap,
            'batch_size' => (int) $this->settings->get('batch_size'),
        ];
    }

    public function resolve_product_part_number(int $productId): ?array
    {
        foreach ($this->meta_keys() as $key) {
            $value = get_post_meta($productId, (string) $key, true);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return ['part_number_raw' => trim((string) $value), 'source_field' => (string) $key];
            }
        }

        return null;
    }
}
