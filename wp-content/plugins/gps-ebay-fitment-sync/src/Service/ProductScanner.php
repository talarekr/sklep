<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Support\PartNumberCandidateValidator;
use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class ProductScanner
{
    private Database $database;
    private PartNumberNormalizer $normalizer;
    private Settings $settings;
    private PartNumberCandidateValidator $validator;

    public function __construct(Database $database, PartNumberNormalizer $normalizer, Settings $settings, ?PartNumberCandidateValidator $validator = null)
    {
        $this->database = $database;
        $this->normalizer = $normalizer;
        $this->settings = $settings;
        $this->validator = $validator ?: new PartNumberCandidateValidator($normalizer);
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

        $acceptedRows = [];
        $rejectedRows = [];
        $suspiciousRows = [];
        $unique = [];
        $productsWithRaw = 0;
        $acceptedProducts = [];
        $rejectedProducts = [];
        foreach ($ids as $productId) {
            $resolved = $this->resolve_product_part_number((int) $productId);
            if (!$resolved) {
                continue;
            }
            $productsWithRaw++;
            $productAccepted = false;
            $productRejected = false;
            foreach ($this->validator->candidates($resolved['part_number_raw']) as $candidate) {
                $base = [
                    'product_id' => (int) $productId,
                    'sku' => (string) get_post_meta((int) $productId, '_sku', true),
                    'part_number_raw' => (string) $candidate['raw'],
                    'part_number_normalized' => (string) $candidate['normalized'],
                    'source_field' => $resolved['source_field'],
                    'source_raw' => $resolved['part_number_raw'],
                    'warnings' => $candidate['warnings'] ?? [],
                ];

                if (!empty($candidate['accepted'])) {
                    $acceptedRows[] = $base;
                    $unique[$base['part_number_normalized']] = $base['part_number_raw'];
                    $productAccepted = true;
                    if (!empty($base['warnings'])) {
                        $suspiciousRows[] = $base;
                    }
                    if ($persistMap) {
                        $this->database->upsert_product_map($base);
                    }
                    continue;
                }

                $rejectedRows[] = array_merge($base, [
                    'rejection_reason' => (string) ($candidate['rejection_reason'] ?? 'rejected'),
                ]);
                $productRejected = true;
            }

            if ($productAccepted) {
                $acceptedProducts[(int) $productId] = true;
            }
            if (!$productAccepted && $productRejected) {
                $rejectedProducts[(int) $productId] = true;
            }
        }

        $cached = $this->database->count_cached(array_keys($unique));

        return [
            'total_scanned_products' => count($ids),
            'products_with_raw_part_number' => $productsWithRaw,
            'accepted_products' => count($acceptedProducts),
            'rejected_products' => count($rejectedProducts),
            'unique_accepted_part_numbers' => count($unique),
            'already_cached_count' => $cached,
            'not_cached_count' => max(0, count($unique) - $cached),
            'rejected_count' => count($rejectedRows),
            'suspicious_count' => count($suspiciousRows),
            'accepted_rows' => $acceptedRows,
            'rejected_rows' => $rejectedRows,
            'suspicious_rows' => $suspiciousRows,
            'accepted_sample' => array_slice($acceptedRows, 0, 25),
            'rejected_sample' => array_slice($rejectedRows, 0, 25),
            'suspicious_sample' => array_slice($suspiciousRows, 0, 25),
            'unique_part_numbers' => $unique,
            'unique_accepted_part_number_values' => array_keys($unique),
            'persisted_product_map' => $persistMap,
            'batch_size' => (int) $this->settings->get('batch_size'),
            // Backward-compatible aliases for older admin result consumers.
            'products_with_part_number' => count($acceptedRows),
            'unique_normalized_part_numbers' => count($unique),
            'sample' => array_slice($acceptedRows, 0, 25),
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
