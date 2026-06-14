<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class EbayFitmentPreview
{
    public const DE_LISTING_META = '_wei_ebay_listing_id';
    public const DE_ITEM_META = '_wei_ebay_item_id';
    public const DE_STATUS_META = '_wei_ebay_listing_status';
    public const FR_LISTING_META = '_wei_fr_ebay_listing_id';
    public const FR_ITEM_META = '_wei_fr_ebay_item_id';
    public const FR_STATUS_META = '_wei_fr_ebay_listing_status';

    /** @var string[] */
    private const CSV_COLUMNS = ['product_id','product_title','sku','part_number_normalized','ktype_count','vehicle_ids','ebay_de_item_id','ebay_de_status','ebay_fr_item_id','ebay_fr_status','would_update_de','would_update_fr','blocked_reason_de','blocked_reason_fr','blocked_reason','live_checked_revisable_de','live_checked_revisable_fr','local_active_but_live_ended_de','local_active_but_live_ended_fr'];

    public function query(array $args = []): array
    {
        global $wpdb;
        $limit = max(1, min(1000, (int) ($args['limit'] ?? 50)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $filters = $this->filters($args);
        $rows = $this->base_rows($limit, $offset, $filters);
        $rows = array_map(fn(array $row): array => $this->decorate_row($row), $rows);
        $rows = array_values(array_filter($rows, fn(array $row): bool => $this->row_matches($row, $filters)));

        return [
            'rows' => $rows,
            'counters' => $this->counters($filters),
            'diagnostics' => $this->diagnostics(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    public function one_product(int $productId): ?array
    {
        $result = $this->query(['product_id' => $productId, 'limit' => 1, 'offset' => 0]);
        return $result['rows'][0] ?? null;
    }

    public function stream_csv(array $args = []): void
    {
        $args['limit'] = isset($args['limit']) ? (int) $args['limit'] : 5000;
        $result = $this->query($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="ebay-fitment-preview-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, self::CSV_COLUMNS);
        foreach ($result['rows'] as $row) {
            fputcsv($out, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::CSV_COLUMNS));
        }
        fclose($out);
    }

    private function filters(array $args): array
    {
        return [
            'only_with_ktype' => !empty($args['only_with_ktype']),
            'missing_de' => !empty($args['missing_de']),
            'missing_fr' => !empty($args['missing_fr']),
            'ready_de' => !empty($args['ready_de']),
            'ready_fr' => !empty($args['ready_fr']),
            'product_id' => isset($args['product_id']) && trim((string) $args['product_id']) !== '' ? max(0, (int) $args['product_id']) : null,
            'part_number' => trim((string) ($args['part_number'] ?? '')),
        ];
    }

    private function base_rows(int $limit, int $offset, array $filters): array
    {
        global $wpdb;
        $tables = Database::table_names();
        $where = ['pm.part_cache_id IS NOT NULL'];
        $params = [];
        if (!empty($filters['only_with_ktype'])) { $where[] = 'pc.vehicle_count > 0'; }
        if ($filters['product_id'] !== null && (int) $filters['product_id'] > 0) { $where[] = 'pm.product_id = %d'; $params[] = (int) $filters['product_id']; }
        if ((string) $filters['part_number'] !== '') { $where[] = 'pm.part_number_normalized LIKE %s'; $params[] = '%' . $wpdb->esc_like((string) $filters['part_number']) . '%'; }
        $sql = "SELECT pm.product_id, pm.sku, pm.part_number_normalized, pm.part_cache_id, pc.vehicle_count AS ktype_count, p.post_title AS product_title, p.post_status
            FROM {$tables['product_map']} pm
            LEFT JOIN {$tables['part_cache']} pc ON pc.id = pm.part_cache_id
            LEFT JOIN {$wpdb->posts} p ON p.ID = pm.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY pm.product_id ASC LIMIT %d OFFSET %d";
        $params[] = $limit * 3;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        return array_values(array_filter($rows, 'is_array'));
    }

    private function decorate_row(array $row): array
    {
        $productId = (int) ($row['product_id'] ?? 0);
        $ktypeCount = (int) ($row['ktype_count'] ?? 0);
        $vehicles = $this->vehicle_ids((int) ($row['part_cache_id'] ?? 0));
        $de = $this->listing($productId, 'de');
        $fr = $this->listing($productId, 'fr');
        $productTitle = trim((string) ($row['product_title'] ?? ''));
        $productExists = $productId > 0 && $productTitle !== '';
        $deBlocked = $this->market_blocked_reasons($productExists, $ktypeCount, $de, 'de');
        $frBlocked = $this->market_blocked_reasons($productExists, $ktypeCount, $fr, 'fr');
        $deReady = $deBlocked === [];
        $frReady = $frBlocked === [];
        $blocked = $this->overall_blocked_reasons($deBlocked, $frBlocked, $deReady, $frReady);

        return [
            'product_id' => (string) $productId,
            'product_title' => $productTitle,
            'sku' => (string) ($row['sku'] !== '' ? $row['sku'] : get_post_meta($productId, '_sku', true)),
            'part_number_normalized' => (string) ($row['part_number_normalized'] ?? ''),
            'part_cache_id' => (string) ($row['part_cache_id'] ?? ''),
            'ktype_count' => (string) $ktypeCount,
            'vehicle_ids' => implode(',', $vehicles),
            'sample_ktypes' => implode(',', array_slice($vehicles, 0, 10)),
            'ebay_de_item_id' => $de['item_id'],
            'ebay_de_status' => $de['status'],
            'ebay_fr_item_id' => $fr['item_id'],
            'ebay_fr_status' => $fr['status'],
            'would_update_de' => $deReady ? 'yes' : 'no',
            'would_update_fr' => $frReady ? 'yes' : 'no',
            'blocked_reason_de' => $deBlocked ? implode('|', array_values(array_unique($deBlocked))) : '',
            'blocked_reason_fr' => $frBlocked ? implode('|', array_values(array_unique($frBlocked))) : '',
            'blocked_reason' => $blocked ? implode('|', array_values(array_unique($blocked))) : '',
            'live_checked_revisable_de' => '',
            'live_checked_revisable_fr' => '',
            'local_active_but_live_ended_de' => '',
            'local_active_but_live_ended_fr' => '',
        ];
    }

    private function market_blocked_reasons(bool $productExists, int $ktypeCount, array $listing, string $market): array
    {
        $blocked = [];
        if (!$productExists) { $blocked[] = 'product_not_found'; }
        if ($ktypeCount <= 0) { $blocked[] = 'no_ktype'; }
        if ((string) ($listing['item_id'] ?? '') === '') { $blocked[] = $market === 'fr' ? 'no_ebay_fr_listing' : 'no_ebay_de_listing'; }
        if ((string) ($listing['item_id'] ?? '') !== '' && !$this->status_ready((string) ($listing['status'] ?? ''))) { $blocked[] = 'listing_status_not_active'; }
        return array_values(array_unique($blocked));
    }

    private function overall_blocked_reasons(array $deBlocked, array $frBlocked, bool $deReady, bool $frReady): array
    {
        if ($deReady || $frReady) {
            return array_values(array_unique(array_merge($deReady ? [] : $deBlocked, $frReady ? [] : $frBlocked)));
        }
        return array_values(array_unique(array_merge($deBlocked, $frBlocked)));
    }

    private function row_matches(array $row, array $filters): bool
    {
        if (!empty($filters['missing_de']) && (string) $row['ebay_de_item_id'] !== '') { return false; }
        if (!empty($filters['missing_fr']) && (string) $row['ebay_fr_item_id'] !== '') { return false; }
        if (!empty($filters['ready_de']) && (string) $row['would_update_de'] !== 'yes') { return false; }
        if (!empty($filters['ready_fr']) && (string) $row['would_update_fr'] !== 'yes') { return false; }
        return true;
    }

    private function counters(array $filters): array
    {
        $rows = array_map(fn(array $row): array => $this->decorate_row($row), $this->base_rows(100000, 0, ['only_with_ktype' => true, 'product_id' => null, 'part_number' => '']));
        return [
            'products_with_ktype' => count($rows),
            'products_with_de_listing' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] !== '')),
            'products_with_fr_listing' => count(array_filter($rows, fn($r) => $r['ebay_fr_item_id'] !== '')),
            'products_with_both_de_fr' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] !== '' && $r['ebay_fr_item_id'] !== '')),
            'ready_for_de' => count(array_filter($rows, fn($r) => $r['would_update_de'] === 'yes')),
            'ready_for_fr' => count(array_filter($rows, fn($r) => $r['would_update_fr'] === 'yes')),
            'ready_for_both' => count(array_filter($rows, fn($r) => $r['would_update_de'] === 'yes' && $r['would_update_fr'] === 'yes')),
            'missing_de_listing' => count(array_filter($rows, fn($r) => $r['ebay_de_item_id'] === '')),
            'missing_fr_listing' => count(array_filter($rows, fn($r) => $r['ebay_fr_item_id'] === '')),
            'blocked_count' => count(array_filter($rows, fn($r) => $r['blocked_reason'] !== '')),
        ];
    }

    private function vehicle_ids(int $partCacheId): array
    {
        if ($partCacheId <= 0) { return []; }
        global $wpdb;
        $table = Database::table_names()['vehicle_cache'];
        return array_map('strval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT vehicle_id FROM {$table} WHERE part_cache_id = %d ORDER BY vehicle_id ASC", $partCacheId)) ?: []);
    }

    private function listing(int $productId, string $market): array
    {
        global $wpdb;
        $prefix = $market === 'fr' ? '_wei_fr_ebay_' : '_wei_ebay_';
        $marketplace = $market === 'fr' ? 'ebay_fr' : 'ebay';
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $mapping = $this->table_exists($mappingTable) ? $wpdb->get_row($wpdb->prepare("SELECT remote_listing_id, status FROM {$mappingTable} WHERE marketplace=%s AND woo_product_id=%d ORDER BY updated_at DESC LIMIT 1", $marketplace, $productId), ARRAY_A) : null;
        $itemId = trim((string) ($mapping['remote_listing_id'] ?? ''));
        if ($itemId === '') { $itemId = trim((string) get_post_meta($productId, $prefix . 'listing_id', true)); }
        if ($itemId === '') { $itemId = trim((string) get_post_meta($productId, $prefix . 'item_id', true)); }
        $status = trim((string) ($mapping['status'] ?? ''));
        if ($status === '') { $status = trim((string) get_post_meta($productId, $prefix . 'listing_status', true)); }
        if ($status === '') { $status = trim((string) get_post_meta($productId, $prefix . 'export_status', true)); }
        return ['item_id' => $itemId, 'status' => $status];
    }

    private function status_ready(string $status): bool
    {
        $s = strtolower(trim($status));
        return $s === '' || in_array($s, ['active', 'published'], true);
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public function diagnostics(): array
    {
        global $wpdb;
        return [
            'mapping_table' => $wpdb->prefix . 'marketplace_mappings',
            'mapping_table_exists' => $this->table_exists($wpdb->prefix . 'marketplace_mappings'),
            'de_sources' => ['marketplace_mappings.marketplace=ebay remote_listing_id', self::DE_LISTING_META, self::DE_ITEM_META, self::DE_STATUS_META],
            'fr_sources' => ['marketplace_mappings.marketplace=ebay_fr remote_listing_id', self::FR_LISTING_META, self::FR_ITEM_META, self::FR_STATUS_META],
            'note' => 'Preview reads local DB/cache/meta only; it does not call eBay, Apify, TecDoc, or modify Woo products.',
        ];
    }
}
