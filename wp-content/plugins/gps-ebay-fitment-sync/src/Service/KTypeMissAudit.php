<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class KTypeMissAudit
{
    private ProductScanner $scanner;

    /** @var string[] */
    private const BRAND_KEYS = ['_brand','brand','_manufacturer','manufacturer','_pa_brand','pa_brand','_make','make'];
    /** @var string[] */
    private const MPN_KEYS = ['_mpn','mpn','_article_number','article_number','_manufacturer_part_number','manufacturer_part_number'];
    /** @var string[] */
    private const EAN_KEYS = ['_ean','ean','_gtin','gtin','_barcode','barcode'];
    /** @var string[] */
    private const CSV_COLUMNS = ['product_id','sku','title','raw_oem_fields','normalized_oem_values','oem_count','brand_manufacturer','mpn_article_number','ean','has_ktype_cache','ktype_count','ktype_cache_source','last_ktype_lookup_status','last_ktype_lookup_error','dropoff_reason'];

    public function __construct(ProductScanner $scanner)
    {
        $this->scanner = $scanner;
    }

    public function run(): array
    {
        $rows = $this->rows();
        return ['summary' => $this->summary($rows), 'rows' => $rows, 'note' => 'Read-only local KType miss audit. No Apify, TecDoc, eBay API, or Woo writes are performed.'];
    }

    public function export_csv(): array
    {
        $audit = $this->run();
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'gps-ebay-fitment-sync/audit';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $file = $dir . '/ebay-ktype-miss-audit-' . gmdate('Ymd-His') . '.csv';
        $url = trailingslashit($upload['baseurl']) . 'gps-ebay-fitment-sync/audit/' . basename($file);
        $out = fopen($file, 'w');
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($audit['summary'] as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); }
                continue;
            }
            fputcsv($out, [$key, (string) $value]);
        }
        fputcsv($out, []);
        fputcsv($out, self::CSV_COLUMNS);
        foreach ($audit['rows'] as $row) { fputcsv($out, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::CSV_COLUMNS)); }
        fclose($out);
        return ['path' => $file, 'url' => $url, 'summary' => $audit['summary'], 'columns' => self::CSV_COLUMNS];
    }

    private function rows(): array
    {
        global $wpdb;
        $ids = $this->product_ids();
        $rows = [];
        foreach ($ids as $productId) {
            $analysis = $this->scanner->analyze_product_part_numbers((int) $productId);
            if (!$analysis['has_raw_oem'] && !$analysis['accepted_candidates']) { continue; }
            $normalized = array_values(array_unique(array_map('strval', $analysis['normalized_values'])));
            $cache = $this->cache_info($normalized, (int) $productId);
            $brand = $this->first_meta((int) $productId, self::BRAND_KEYS);
            $mpn = $this->first_meta((int) $productId, self::MPN_KEYS);
            $ean = $this->first_meta((int) $productId, self::EAN_KEYS);
            $hasMapping = $this->has_ebay_mapping((int) $productId);
            $dropoff = $this->dropoff_reasons($analysis, $cache, $brand, $mpn, $hasMapping);
            $rows[] = [
                'product_id' => (string) $productId,
                'sku' => (string) get_post_meta((int) $productId, '_sku', true),
                'title' => (string) get_the_title((int) $productId),
                'raw_oem_fields' => $this->format_assoc((array) $analysis['raw_fields']),
                'normalized_oem_values' => implode('|', $normalized),
                'oem_count' => (string) count($normalized),
                'brand_manufacturer' => $brand,
                'mpn_article_number' => $mpn,
                'ean' => $ean,
                'has_ktype_cache' => ((int) $cache['ktype_count']) > 0 ? 'yes' : 'no',
                'ktype_count' => (string) $cache['ktype_count'],
                'ktype_cache_source' => (string) $cache['source'],
                'last_ktype_lookup_status' => (string) $cache['status'],
                'last_ktype_lookup_error' => (string) $cache['error'],
                'dropoff_reason' => implode('|', $dropoff),
            ];
        }
        return $rows;
    }

    private function summary(array $rows): array
    {
        $without = array_values(array_filter($rows, static fn(array $row): bool => $row['has_ktype_cache'] !== 'yes'));
        return [
            'total_products_with_oem' => count($rows),
            'products_with_local_ktype' => count(array_filter($rows, static fn(array $row): bool => $row['has_ktype_cache'] === 'yes')),
            'products_without_ktype' => count($without),
            'products_with_multiple_oems' => count(array_filter($rows, static fn(array $row): bool => (int) $row['oem_count'] > 1)),
            'products_with_ebay_mapping_but_no_ktype' => count(array_filter($rows, static fn(array $row): bool => str_contains((string) $row['dropoff_reason'], 'product_has_ebay_mapping_but_no_ktype'))),
            'top_missing_brands' => $this->top_counts(array_map(static fn(array $row): string => (string) $row['brand_manufacturer'], $without)),
            'top_oem_normalization_patterns' => $this->top_counts(array_map(static fn(array $row): string => self::normalization_pattern((string) $row['raw_oem_fields'], (string) $row['normalized_oem_values']), $rows)),
        ];
    }

    private function cache_info(array $normalizedValues, int $productId): array
    {
        global $wpdb;
        $tables = Database::table_names();
        $best = ['ktype_count' => 0, 'source' => '', 'status' => '', 'error' => ''];
        foreach ($normalizedValues as $normalized) {
            if ($normalized === '') { continue; }
            $mapped = $wpdb->get_row($wpdb->prepare("SELECT pm.part_cache_id, pm.vehicle_count, pc.vehicle_count AS cache_vehicle_count, pc.status, pc.error_message FROM {$tables['product_map']} pm LEFT JOIN {$tables['part_cache']} pc ON pc.id=pm.part_cache_id WHERE pm.product_id=%d AND pm.part_number_normalized=%s ORDER BY pm.updated_at DESC LIMIT 1", $productId, $normalized), ARRAY_A) ?: [];
            if ($mapped) {
                $count = max((int) ($mapped['vehicle_count'] ?? 0), (int) ($mapped['cache_vehicle_count'] ?? 0));
                if ($count > (int) $best['ktype_count'] || $best['source'] === '') { $best = ['ktype_count' => $count, 'source' => 'product_map', 'status' => (string) ($mapped['status'] ?? ''), 'error' => (string) ($mapped['error_message'] ?? '')]; }
            }
            $part = $wpdb->get_row($wpdb->prepare("SELECT id, vehicle_count, status, error_message FROM {$tables['part_cache']} WHERE part_number_normalized=%s ORDER BY updated_at DESC LIMIT 1", $normalized), ARRAY_A) ?: [];
            if ($part) {
                $count = (int) ($part['vehicle_count'] ?? 0);
                if ($count > (int) $best['ktype_count'] || $best['source'] === '') { $best = ['ktype_count' => $count, 'source' => 'part_cache', 'status' => (string) ($part['status'] ?? ''), 'error' => (string) ($part['error_message'] ?? '')]; }
            }
        }
        return $best;
    }

    private function dropoff_reasons(array $analysis, array $cache, string $brand, string $mpn, bool $hasMapping): array
    {
        $reasons = [];
        if (!$analysis['has_raw_oem']) { $reasons[] = 'no_oem_detected_by_pipeline'; }
        if (!empty($analysis['normalization_changed'])) { $reasons[] = 'oem_normalization_changed_value'; }
        if ((int) $analysis['accepted_count'] > 1) { $reasons[] = 'multiple_oems_only_first_used'; }
        if ($brand === '' || $mpn === '') { $reasons[] = 'missing_brand_or_mpn'; }
        if ((int) $cache['ktype_count'] <= 0) {
            $reasons[] = 'no_local_ktype_cache';
            $status = strtolower((string) $cache['status']);
            if ($status === '' || $status === 'pending' || $status === 'error') { $reasons[] = 'lookup_failed_or_not_run'; }
            if (in_array($status, ['not_found', 'no_match'], true)) { $reasons[] = 'tecdoc_no_match_cached'; }
            if ($hasMapping) { $reasons[] = 'product_has_ebay_mapping_but_no_ktype'; }
        }
        if (!empty($analysis['rejected_candidates']) && empty($analysis['accepted_candidates'])) { $reasons[] = 'candidate_rejected_by_brand_filter'; }
        return array_values(array_unique($reasons));
    }

    private function has_ebay_mapping(int $productId): bool
    {
        global $wpdb;
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mappingTable)) === $mappingTable) {
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$mappingTable} WHERE woo_product_id=%d AND marketplace IN ('ebay','ebay_fr') AND remote_listing_id<>''", $productId));
            if ($count > 0) { return true; }
        }
        foreach (['_wei_ebay_listing_id','_wei_ebay_item_id','_wei_fr_ebay_listing_id','_wei_fr_ebay_item_id'] as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') { return true; }
        }
        return false;
    }

    private function product_ids(): array
    {
        global $wpdb;
        $postTypes = apply_filters('gps_ebay_fitment_sync_product_post_types', ['product', 'product_variation']);
        $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC", $postTypes)) ?: []);
    }

    private function first_meta(int $productId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = get_post_meta($productId, $key, true);
            if (is_scalar($value) && trim((string) $value) !== '') { return trim((string) $value); }
        }
        return '';
    }

    private function format_assoc(array $fields): string
    {
        $parts = [];
        foreach ($fields as $key => $value) { $parts[] = (string) $key . '=' . str_replace(["\r", "\n"], ' ', (string) $value); }
        return implode('|', $parts);
    }

    private function top_counts(array $values, int $limit = 10): array
    {
        $counts = [];
        foreach ($values as $value) {
            $key = trim($value) !== '' ? trim($value) : '(missing)';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    private static function normalization_pattern(string $raw, string $normalized): string
    {
        $rawValues = [];
        foreach (explode('|', $raw) as $part) {
            $pieces = explode('=', $part, 2);
            if (isset($pieces[1]) && trim($pieces[1]) !== '') { $rawValues[] = trim($pieces[1]); }
        }
        $rawJoined = implode(',', $rawValues);
        if ($rawJoined === '' || $normalized === '') { return '(missing)'; }
        return $rawJoined === $normalized ? 'unchanged' : 'changed';
    }
}
