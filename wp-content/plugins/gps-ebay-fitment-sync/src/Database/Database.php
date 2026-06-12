<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Database;

use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;

final class Database
{
    private const DB_VERSION = '0.1.0';
    private const DB_OPTION = 'gps_ebay_fitment_sync_db_version';

    private PartNumberNormalizer $normalizer;

    public function __construct(PartNumberNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    public static function activate(): void
    {
        self::create_tables();
        update_option(self::DB_OPTION, self::DB_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::activate();
        }
    }

    public static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $part = $wpdb->prefix . 'gps_fitment_part_cache';
        $article = $wpdb->prefix . 'gps_fitment_article_cache';
        $vehicle = $wpdb->prefix . 'gps_fitment_vehicle_cache';
        $map = $wpdb->prefix . 'gps_fitment_product_map';

        dbDelta("CREATE TABLE {$part} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            part_number_raw varchar(191) NOT NULL,
            part_number_normalized varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            article_count int(11) NOT NULL DEFAULT 0,
            vehicle_count int(11) NOT NULL DEFAULT 0,
            last_lookup_at datetime NULL,
            error_message text NULL,
            response_hash varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY part_number_normalized (part_number_normalized)
        ) {$charset};");

        dbDelta("CREATE TABLE {$article} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            part_cache_id bigint(20) unsigned NOT NULL,
            article_id bigint(20) unsigned NULL,
            article_no varchar(191) NOT NULL,
            supplier_id bigint(20) unsigned NULL,
            supplier_name varchar(191) NULL,
            raw_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY part_cache_id (part_cache_id),
            KEY article_supplier (article_no, supplier_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$vehicle} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            part_cache_id bigint(20) unsigned NOT NULL,
            article_cache_id bigint(20) unsigned NULL,
            vehicle_id bigint(20) unsigned NOT NULL,
            manufacturer_name varchar(191) NULL,
            model_name varchar(191) NULL,
            type_name varchar(191) NULL,
            raw_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY part_cache_id (part_cache_id),
            KEY vehicle_id (vehicle_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$map} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            sku varchar(191) NULL,
            part_number_raw varchar(191) NOT NULL,
            part_number_normalized varchar(191) NOT NULL,
            source_field varchar(191) NOT NULL,
            part_cache_id bigint(20) unsigned NULL,
            vehicle_count int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_part (product_id, part_number_normalized),
            KEY part_number_normalized (part_number_normalized),
            KEY part_cache_id (part_cache_id)
        ) {$charset};");
    }

    public function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gps_fitment_' . $name;
    }

    public function get_part_cache(string $partNumber): ?array
    {
        global $wpdb;
        $normalized = $this->normalizer->normalize($partNumber);
        if ($normalized === '') {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->table('part_cache') . ' WHERE part_number_normalized = %s', $normalized), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function get_cached_result(string $partNumber): ?array
    {
        $part = $this->get_part_cache($partNumber);
        if (!$part) {
            return null;
        }

        global $wpdb;
        $articles = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table('article_cache') . ' WHERE part_cache_id = %d ORDER BY id ASC', (int) $part['id']), ARRAY_A) ?: [];
        $vehicles = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table('vehicle_cache') . ' WHERE part_cache_id = %d ORDER BY vehicle_id ASC', (int) $part['id']), ARRAY_A) ?: [];

        return [
            'part_cache' => $part,
            'articles' => $articles,
            'vehicles' => $vehicles,
            'unique_vehicle_ids' => array_values(array_unique(array_map(static fn($row) => (string) $row['vehicle_id'], $vehicles))),
        ];
    }

    public function save_lookup(string $raw, array $result): int
    {
        global $wpdb;

        $normalized = $this->normalizer->normalize((string) ($result['part_number_normalized'] ?? $raw));
        if ($normalized === '') {
            return 0;
        }
        $now = current_time('mysql');
        $status = $result['status'] ?? 'error';
        $articles = $result['articles'] ?? [];
        $vehicles = $result['vehicles'] ?? [];
        $hash = hash('sha256', wp_json_encode(['articles' => $articles, 'vehicles' => $vehicles]));
        $existing = $this->get_part_cache($normalized);

        $data = [
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'status' => $status,
            'article_count' => count($articles),
            'vehicle_count' => count(array_unique(array_map(static fn($vehicle) => (string) ($vehicle['vehicleId'] ?? $vehicle['vehicle_id'] ?? ''), $vehicles))),
            'last_lookup_at' => $now,
            'error_message' => empty($result['errors']) ? null : implode("\n", array_map('strval', $result['errors'])),
            'response_hash' => $hash,
            'updated_at' => $now,
        ];

        if ($existing) {
            $wpdb->update($this->table('part_cache'), $data, ['id' => (int) $existing['id']]);
            $partId = (int) $existing['id'];
            $wpdb->delete($this->table('article_cache'), ['part_cache_id' => $partId]);
            $wpdb->delete($this->table('vehicle_cache'), ['part_cache_id' => $partId]);
        } else {
            $data['created_at'] = $now;
            $wpdb->insert($this->table('part_cache'), $data);
            $partId = (int) $wpdb->insert_id;
        }

        $articleIdMap = [];
        foreach ($articles as $article) {
            $articleNo = (string) ($article['articleNo'] ?? '');
            $supplierId = isset($article['supplierId']) ? (int) $article['supplierId'] : null;
            $wpdb->insert($this->table('article_cache'), [
                'part_cache_id' => $partId,
                'article_id' => isset($article['articleId']) ? (int) $article['articleId'] : null,
                'article_no' => $articleNo,
                'supplier_id' => $supplierId,
                'supplier_name' => isset($article['supplierName']) ? (string) $article['supplierName'] : null,
                'raw_json' => wp_json_encode($article),
                'created_at' => $now,
            ]);
            $articleIdMap[$articleNo . ':' . (string) $supplierId] = (int) $wpdb->insert_id;
        }

        $seenVehicles = [];
        foreach ($vehicles as $vehicle) {
            $vehicleId = isset($vehicle['vehicleId']) ? (int) $vehicle['vehicleId'] : (int) ($vehicle['vehicle_id'] ?? 0);
            if ($vehicleId <= 0 || isset($seenVehicles[$vehicleId])) {
                continue;
            }
            $seenVehicles[$vehicleId] = true;
            $articleKey = (string) ($vehicle['_articleNo'] ?? '') . ':' . (string) ($vehicle['_supplierId'] ?? '');
            $wpdb->insert($this->table('vehicle_cache'), [
                'part_cache_id' => $partId,
                'article_cache_id' => $articleIdMap[$articleKey] ?? null,
                'vehicle_id' => $vehicleId,
                'manufacturer_name' => $this->vehicle_field($vehicle, ['manufacturerName', 'manufacturer_name', 'manuName']),
                'model_name' => $this->vehicle_field($vehicle, ['modelName', 'model_name']),
                'type_name' => $this->vehicle_field($vehicle, ['typeName', 'type_name', 'carName']),
                'raw_json' => wp_json_encode($vehicle),
                'created_at' => $now,
            ]);
        }

        return $partId;
    }

    public function upsert_product_map(array $row): void
    {
        global $wpdb;
        $now = current_time('mysql');
        $normalized = $this->normalizer->normalize((string) $row['part_number_raw']);
        $cache = $this->get_part_cache($normalized);
        $data = [
            'product_id' => (int) $row['product_id'],
            'sku' => (string) ($row['sku'] ?? ''),
            'part_number_raw' => (string) $row['part_number_raw'],
            'part_number_normalized' => $normalized,
            'source_field' => (string) $row['source_field'],
            'part_cache_id' => $cache ? (int) $cache['id'] : null,
            'vehicle_count' => $cache ? (int) $cache['vehicle_count'] : 0,
            'status' => $cache ? (string) $cache['status'] : 'pending',
            'updated_at' => $now,
        ];

        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $this->table('product_map') . ' WHERE product_id = %d AND part_number_normalized = %s', (int) $row['product_id'], $normalized));
        if ($existing) {
            $wpdb->update($this->table('product_map'), $data, ['id' => (int) $existing]);
            return;
        }

        $data['created_at'] = $now;
        $wpdb->insert($this->table('product_map'), $data);
    }

    public function count_cached(array $normalizedPartNumbers): int
    {
        global $wpdb;
        $normalizedPartNumbers = array_values(array_unique(array_filter(array_map(fn($partNumber): string => $this->normalizer->normalize((string) $partNumber), $normalizedPartNumbers))));
        if (!$normalizedPartNumbers) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($normalizedPartNumbers), '%s'));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $this->table('part_cache') . " WHERE part_number_normalized IN ({$placeholders})", $normalizedPartNumbers));
    }

    private function vehicle_field(array $vehicle, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($vehicle[$key]) && $vehicle[$key] !== '') {
                return (string) $vehicle[$key];
            }
        }

        return null;
    }
}
