<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Database;

use GPS_Ebay_Fitment_Sync\Support\PartNumberNormalizer;

final class Database
{
    private const DB_VERSION = '0.1.2';
    private const DB_OPTION = 'gps_ebay_fitment_sync_db_version';

    private PartNumberNormalizer $normalizer;
    /** @var array<string, mixed> */
    private array $lastSaveDebug = [];

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
        $jobs = $wpdb->prefix . 'gps_fitment_apify_jobs';

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

        dbDelta("CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(191) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            part_number_raw varchar(191) NOT NULL,
            part_number_normalized varchar(191) NOT NULL,
            step varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            apify_run_id varchar(191) NULL,
            apify_dataset_id varchar(191) NULL,
            article_no varchar(191) NULL,
            supplier_id bigint(20) unsigned NULL,
            article_cache_id bigint(20) unsigned NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            last_checked_at datetime NULL,
            PRIMARY KEY  (id),
            KEY run_part_step_status (run_id, part_number_normalized, step, status),
            KEY apify_run_id (apify_run_id),
            KEY part_step_article (part_number_normalized, step, article_no, supplier_id)
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

    /**
     * @return array<string, string>
     */
    public static function table_names(): array
    {
        global $wpdb;
        return [
            'part_cache' => $wpdb->prefix . 'gps_fitment_part_cache',
            'article_cache' => $wpdb->prefix . 'gps_fitment_article_cache',
            'vehicle_cache' => $wpdb->prefix . 'gps_fitment_vehicle_cache',
            'product_map' => $wpdb->prefix . 'gps_fitment_product_map',
            'apify_jobs' => $wpdb->prefix . 'gps_fitment_apify_jobs',
        ];
    }

    /**
     * Re-runs dbDelta and returns schema diagnostics. This is safe for admin repair because
     * dbDelta adds/updates missing columns and indexes without dropping cache data.
     */
    public static function repair_schema(): array
    {
        self::create_tables();
        update_option(self::DB_OPTION, self::DB_VERSION, false);

        return self::schema_diagnostics();
    }

    /**
     * @return array<string, mixed>
     */
    public static function schema_diagnostics(): array
    {
        global $wpdb;
        $tables = self::table_names();
        $requiredPartColumns = self::required_part_cache_columns();
        $tableDetails = [];
        $schemaOk = true;
        $lastError = '';

        foreach ($tables as $key => $table) {
            $exists = self::table_exists($table);
            $columns = $exists ? self::table_columns($table) : [];
            $indexes = $exists ? self::table_indexes($table) : [];
            $missingColumns = $key === 'part_cache' ? array_values(array_diff($requiredPartColumns, array_keys($columns))) : [];
            $missingIndexes = [];
            if ($key === 'part_cache' && !isset($indexes['part_number_normalized'])) {
                $missingIndexes[] = 'part_number_normalized';
            }
            if (!$exists || $missingColumns || $missingIndexes) {
                $schemaOk = false;
            }
            if (!empty($wpdb->last_error)) {
                $lastError = (string) $wpdb->last_error;
            }

            $tableDetails[$key] = [
                'name' => $table,
                'exists' => $exists,
                'columns' => array_keys($columns),
                'missing_columns' => $missingColumns,
                'indexes' => array_keys($indexes),
                'missing_indexes' => $missingIndexes,
            ];
        }

        return [
            'tables' => $tableDetails,
            'schema_ok' => $schemaOk,
            'required_part_cache_columns' => $requiredPartColumns,
            'last_db_error' => $lastError,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cache_diagnostics(string $partNumber): array
    {
        global $wpdb;
        $normalized = $this->normalizer->normalize($partNumber);
        $schema = self::schema_diagnostics();
        $partTable = $this->table('part_cache');
        $row = null;
        $alternateRows = [];
        $lastError = (string) ($schema['last_db_error'] ?? '');

        if (!empty($schema['tables']['part_cache']['exists'])) {
            $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $partTable . ' WHERE part_number_normalized = %s', $normalized), ARRAY_A);
            if (!empty($wpdb->last_error)) {
                $lastError = (string) $wpdb->last_error;
            }

            $alternateRows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT id, part_number_raw, part_number_normalized, status, article_count, vehicle_count FROM ' . $partTable . ' WHERE part_number_raw = %s OR LOWER(part_number_normalized) = LOWER(%s) OR LOWER(part_number_raw) = LOWER(%s) ORDER BY id ASC LIMIT 10',
                    $partNumber,
                    $normalized,
                    $partNumber
                ),
                ARRAY_A
            ) ?: [];
            if (!empty($wpdb->last_error)) {
                $lastError = (string) $wpdb->last_error;
            }
        }

        return [
            'cache_lookup_key' => $normalized,
            'table_name' => $partTable,
            'table_exists' => !empty($schema['tables']['part_cache']['exists']),
            'schema_ok' => !empty($schema['schema_ok']),
            'row_exists' => is_array($row),
            'row_id' => is_array($row) ? (int) $row['id'] : null,
            'row_status' => is_array($row) ? (string) ($row['status'] ?? '') : '',
            'row_article_count' => is_array($row) ? (int) ($row['article_count'] ?? 0) : null,
            'row_vehicle_count' => is_array($row) ? (int) ($row['vehicle_count'] ?? 0) : null,
            'alternate_rows' => $alternateRows,
            'tables' => $schema['tables'],
            'last_db_error' => $lastError,
        ];
    }

    /**
     * @return string[]
     */
    private static function required_part_cache_columns(): array
    {
        return [
            'id',
            'part_number_raw',
            'part_number_normalized',
            'status',
            'article_count',
            'vehicle_count',
            'last_lookup_at',
            'error_message',
            'response_hash',
            'created_at',
            'updated_at',
        ];
    }

    private static function table_exists(string $table): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function table_columns(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SHOW COLUMNS FROM ' . $table, ARRAY_A) ?: [];
        $columns = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[(string) $row['Field']] = $row;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function table_indexes(string $table): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SHOW INDEX FROM ' . $table, ARRAY_A) ?: [];
        $indexes = [];
        foreach ($rows as $row) {
            if (isset($row['Key_name'])) {
                $indexes[(string) $row['Key_name']][] = $row;
            }
        }

        return $indexes;
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

        $this->lastSaveDebug = [
            'saved' => false,
            'part_cache_id' => null,
            'last_db_error' => '',
            'operation' => '',
            'verified' => false,
        ];

        $normalized = $this->normalizer->normalize((string) ($result['part_number_normalized'] ?? $raw));
        if ($normalized === '') {
            $this->lastSaveDebug['last_db_error'] = 'Part number is empty after normalization.';
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
            'vehicle_count' => count(array_unique(array_filter(array_map(static fn($vehicle) => (string) ($vehicle['vehicleId'] ?? $vehicle['vehicle_id'] ?? ''), $vehicles)))),
            'last_lookup_at' => $now,
            'error_message' => empty($result['errors']) ? null : implode("\n", array_map('strval', $result['errors'])),
            'response_hash' => $hash,
            'updated_at' => $now,
        ];

        if ($existing) {
            $this->lastSaveDebug['operation'] = 'update';
            $updateResult = $wpdb->update($this->table('part_cache'), $data, ['id' => (int) $existing['id']]);
            if ($updateResult === false) {
                $this->lastSaveDebug['last_db_error'] = (string) ($wpdb->last_error ?? 'Part cache update failed.');
                return 0;
            }
            $partId = (int) $existing['id'];
        } else {
            $this->lastSaveDebug['operation'] = 'insert';
            $data['created_at'] = $now;
            $insertResult = $wpdb->insert($this->table('part_cache'), $data);
            if ($insertResult === false || (int) $wpdb->insert_id <= 0) {
                $this->lastSaveDebug['last_db_error'] = (string) ($wpdb->last_error ?? 'Part cache insert failed.');
                return 0;
            }
            $partId = (int) $wpdb->insert_id;
        }

        $verifiedPart = $this->get_part_cache($normalized);
        if (!$verifiedPart || (int) ($verifiedPart['id'] ?? 0) <= 0) {
            $this->lastSaveDebug['part_cache_id'] = $partId;
            $this->lastSaveDebug['last_db_error'] = (string) ($wpdb->last_error ?? 'Part cache row was not found after save.');
            return 0;
        }

        $partId = (int) $verifiedPart['id'];
        $this->lastSaveDebug['part_cache_id'] = $partId;
        $this->lastSaveDebug['verified'] = true;

        $wpdb->delete($this->table('article_cache'), ['part_cache_id' => $partId]);
        $wpdb->delete($this->table('vehicle_cache'), ['part_cache_id' => $partId]);

        $articleIdMap = [];
        foreach ($articles as $article) {
            $articleNo = (string) ($article['articleNo'] ?? '');
            $supplierId = isset($article['supplierId']) ? (int) $article['supplierId'] : null;
            $inserted = $wpdb->insert($this->table('article_cache'), [
                'part_cache_id' => $partId,
                'article_id' => isset($article['articleId']) ? (int) $article['articleId'] : null,
                'article_no' => $articleNo,
                'supplier_id' => $supplierId,
                'supplier_name' => isset($article['supplierName']) ? (string) $article['supplierName'] : null,
                'raw_json' => wp_json_encode($article),
                'created_at' => $now,
            ]);
            if ($inserted !== false) {
                $articleIdMap[$articleNo . ':' . (string) $supplierId] = (int) $wpdb->insert_id;
            } elseif (empty($this->lastSaveDebug['last_db_error'])) {
                $this->lastSaveDebug['last_db_error'] = (string) ($wpdb->last_error ?? 'Article cache insert failed.');
            }
        }

        $seenVehicles = [];
        foreach ($vehicles as $vehicle) {
            $vehicleId = isset($vehicle['vehicleId']) ? (int) $vehicle['vehicleId'] : (int) ($vehicle['vehicle_id'] ?? 0);
            if ($vehicleId <= 0 || isset($seenVehicles[$vehicleId])) {
                continue;
            }
            $seenVehicles[$vehicleId] = true;
            $articleKey = (string) ($vehicle['_articleNo'] ?? '') . ':' . (string) ($vehicle['_supplierId'] ?? '');
            $inserted = $wpdb->insert($this->table('vehicle_cache'), [
                'part_cache_id' => $partId,
                'article_cache_id' => $articleIdMap[$articleKey] ?? null,
                'vehicle_id' => $vehicleId,
                'manufacturer_name' => $this->vehicle_field($vehicle, ['manufacturerName', 'manufacturer_name', 'manuName']),
                'model_name' => $this->vehicle_field($vehicle, ['modelName', 'model_name']),
                'type_name' => $this->vehicle_field($vehicle, ['typeName', 'type_name', 'carName']),
                'raw_json' => wp_json_encode($vehicle),
                'created_at' => $now,
            ]);
            if ($inserted === false && empty($this->lastSaveDebug['last_db_error'])) {
                $this->lastSaveDebug['last_db_error'] = (string) ($wpdb->last_error ?? 'Vehicle cache insert failed.');
            }
        }

        $this->lastSaveDebug['saved'] = true;
        return $partId;
    }

    /**
     * @return array<string, mixed>
     */
    public function last_save_debug(): array
    {
        return $this->lastSaveDebug;
    }


    /**
     * @return array<string, mixed>|null
     */
    public function find_active_apify_job(string $runId, string $normalized, string $step, ?string $articleNo = null, ?int $supplierId = null): ?array
    {
        foreach ($this->apify_jobs_for_part($runId, $normalized) as $job) {
            if ((string) ($job['step'] ?? '') !== $step || !in_array((string) ($job['status'] ?? ''), ['pending', 'running'], true)) {
                continue;
            }
            if ($articleNo !== null && (string) ($job['article_no'] ?? '') !== $articleNo) {
                continue;
            }
            if ($supplierId !== null && (int) ($job['supplier_id'] ?? 0) !== $supplierId) {
                continue;
            }
            return $job;
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function create_apify_job(array $data): array
    {
        global $wpdb;
        $now = current_time('mysql');
        $row = array_merge([
            'run_id' => '',
            'product_id' => 0,
            'part_number_raw' => '',
            'part_number_normalized' => '',
            'step' => 'articles',
            'status' => 'pending',
            'apify_run_id' => null,
            'apify_dataset_id' => null,
            'article_no' => null,
            'supplier_id' => null,
            'article_cache_id' => null,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'last_checked_at' => null,
        ], $data);
        $wpdb->insert($this->table('apify_jobs'), $row);
        $row['id'] = (int) $wpdb->insert_id;
        return $row;
    }

    public function update_apify_job(int $id, array $data): void
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        $wpdb->update($this->table('apify_jobs'), $data, ['id' => $id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function apify_jobs_for_part(string $runId, string $normalized): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . $this->table('apify_jobs') . ' WHERE run_id = %s AND part_number_normalized = %s ORDER BY id ASC', $runId, $this->normalizer->normalize($normalized)), ARRAY_A) ?: [];
        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function next_active_apify_job(string $runId, string $normalized): ?array
    {
        foreach ($this->apify_jobs_for_part($runId, $normalized) as $job) {
            $status = (string) ($job['status'] ?? '');
            if (in_array($status, ['pending', 'running'], true) || ($status === 'failed' && (int) ($job['attempts'] ?? 0) < 3)) {
                return $job;
            }
        }
        return null;
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
