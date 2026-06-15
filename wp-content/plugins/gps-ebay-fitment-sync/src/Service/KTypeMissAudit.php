<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class KTypeMissAudit
{
    private const BATCH_SIZE = 500;
    private const PREVIEW_LIMIT = 50;
    /** @var string[] */
    private const CSV_COLUMNS = ['product_id','sku','title','raw_oem_fields','normalized_oem_values','oem_count','brand_manufacturer','mpn_article_number','ean','has_ktype_cache','ktype_count','ktype_cache_source','last_ktype_lookup_status','last_ktype_lookup_error','dropoff_reason'];

    public function __construct(ProductScanner $scanner) {}

    public function run(int $previewLimit = self::PREVIEW_LIMIT): array
    {
        $before = memory_get_usage(true);
        $previewLimit = max(0, min(200, $previewLimit));
        $summary = $this->summary();
        $rows = $this->fetch_rows(0, $previewLimit, true);
        return [
            'summary' => array_merge($summary, ['memory_usage_after' => memory_get_usage(true), 'peak_memory_usage' => memory_get_peak_usage(true)]),
            'rows' => $rows,
            'preview_limit' => $previewLimit,
            'note' => 'Read-only local KType miss audit. Summary uses aggregate SQL; admin rows are a bounded preview only. No Apify, TecDoc, eBay API, or Woo writes are performed.',
            'memory_diagnostics' => ['memory_limit' => ini_get('memory_limit'), 'memory_usage_before' => $before, 'memory_usage_after' => memory_get_usage(true), 'peak_memory_usage' => memory_get_peak_usage(true), 'batch_size' => self::BATCH_SIZE, 'rows_streamed' => 0],
        ];
    }

    public function export_csv(): array
    {
        $before = memory_get_usage(true);
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'gps-ebay-fitment-sync/audit';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $file = $dir . '/ebay-ktype-miss-audit-' . gmdate('Ymd-His') . '.csv';
        $url = trailingslashit($upload['baseurl']) . 'gps-ebay-fitment-sync/audit/' . basename($file);
        $out = fopen($file, 'w');
        if (!$out) { return ['path' => '', 'url' => '', 'summary' => [], 'columns' => self::CSV_COLUMNS, 'error' => 'Unable to open CSV for writing.']; }
        $summary = $this->summary();
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($summary as $key => $value) {
            if (is_array($value)) { foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); } continue; }
            fputcsv($out, [$key, (string) $value]);
        }
        fputcsv($out, []); fputcsv($out, self::CSV_COLUMNS);
        $rowsStreamed = 0; $last = 0;
        do {
            $rows = $this->fetch_rows($last, self::BATCH_SIZE, true);
            $batchCount = count($rows);
            foreach ($rows as $row) { $last = max($last, (int) $row['product_id']); fputcsv($out, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::CSV_COLUMNS)); $rowsStreamed++; }
            if ($rowsStreamed % self::BATCH_SIZE === 0) { fflush($out); }
            unset($rows);
        } while ($batchCount === self::BATCH_SIZE);
        fclose($out);
        return ['path' => $file, 'url' => $url, 'summary' => $summary, 'columns' => self::CSV_COLUMNS, 'memory_diagnostics' => ['memory_limit' => ini_get('memory_limit'), 'memory_usage_before' => $before, 'memory_usage_after' => memory_get_usage(true), 'peak_memory_usage' => memory_get_peak_usage(true), 'batch_size' => self::BATCH_SIZE, 'rows_streamed' => $rowsStreamed]];
    }


    public function stream_csv_download(): void
    {
        $before = memory_get_usage(true);
        $filename = 'ebay-ktype-miss-audit-' . gmdate('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-GPS-Audit-Memory-Limit: ' . (string) ini_get('memory_limit'));
        header('X-GPS-Audit-Memory-Usage-Before: ' . (string) $before);
        $out = fopen('php://output', 'w');
        if (!$out) { return; }
        $summary = $this->summary();
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($summary as $key => $value) { if (is_array($value)) { foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); } } else { fputcsv($out, [$key, (string) $value]); } }
        fputcsv($out, []); fputcsv($out, self::CSV_COLUMNS);
        $last = 0;
        do { $rows = $this->fetch_rows($last, self::BATCH_SIZE, true); $batchCount = count($rows); foreach ($rows as $row) { $last = max($last, (int) $row['product_id']); fputcsv($out, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::CSV_COLUMNS)); } fflush($out); unset($rows); } while ($batchCount === self::BATCH_SIZE);
        fclose($out);
    }

    private function summary(): array
    {
        global $wpdb; $tables = Database::table_names(); $map = $tables['product_map']; $part = $tables['part_cache']; $mapping = $wpdb->prefix . 'marketplace_mappings';
        $hasMappingSql = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mapping)) === $mapping ? "EXISTS (SELECT 1 FROM {$mapping} mm WHERE mm.woo_product_id=pm.product_id AND mm.marketplace IN ('ebay','ebay_fr','ebay_de','fr','de','EBAY_FR','EBAY_DE') AND mm.remote_listing_id<>'')" : '0';
        $missingWhere = 'GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) <= 0';
        $brands = $this->top_meta_counts('_brand', $missingWhere);
        return [
            'total_products_with_oem' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$map}"),
            'products_with_local_ktype' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) > 0"),
            'products_without_ktype' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE {$missingWhere}"),
            'products_with_multiple_oems' => (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT product_id FROM {$map} GROUP BY product_id HAVING COUNT(DISTINCT part_number_normalized) > 1) x"),
            'products_with_ebay_mapping_but_no_ktype' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE {$missingWhere} AND ({$hasMappingSql})"),
            'top_missing_brands' => $brands,
            'top_oem_normalization_patterns' => [ 'stored_product_map' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$map}") ],
            'memory_limit' => ini_get('memory_limit'), 'batch_size' => self::BATCH_SIZE,
        ];
    }

    private function top_meta_counts(string $metaKey, string $missingWhere): array
    {
        global $wpdb; $tables = Database::table_names(); $map = $tables['product_map']; $part = $tables['part_cache'];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(pmmeta.meta_value,''),'(missing)') reason, COUNT(DISTINCT pm.product_id) c FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id LEFT JOIN {$wpdb->postmeta} pmmeta ON pmmeta.post_id=pm.product_id AND pmmeta.meta_key=%s WHERE {$missingWhere} GROUP BY reason ORDER BY c DESC LIMIT 10", $metaKey), ARRAY_A) ?: [];
        $out = []; foreach ($rows as $row) { $out[(string) ($row['reason'] ?? '(missing)')] = (int) ($row['c'] ?? 0); } return $out;
    }

    private function fetch_rows(int $lastProductId, int $limit, bool $missingOnly): array
    {
        global $wpdb; $tables = Database::table_names(); $map = $tables['product_map']; $part = $tables['part_cache']; $where = $missingOnly ? 'AND GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) <= 0' : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT pm.product_id, MAX(pm.sku) sku, p.post_title title, GROUP_CONCAT(DISTINCT CONCAT(pm.source_field,'=',pm.part_number_raw) SEPARATOR '|') raw_oem_fields, GROUP_CONCAT(DISTINCT pm.part_number_normalized SEPARATOR '|') normalized_oem_values, COUNT(DISTINCT pm.part_number_normalized) oem_count, MAX(GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0))) ktype_count, MAX(COALESCE(pc.status, pm.status, '')) last_ktype_lookup_status, MAX(COALESCE(pc.error_message, '')) last_ktype_lookup_error FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id LEFT JOIN {$wpdb->posts} p ON p.ID=pm.product_id WHERE pm.product_id > %d {$where} GROUP BY pm.product_id, p.post_title ORDER BY pm.product_id ASC LIMIT %d", $lastProductId, $limit), ARRAY_A) ?: [];
        foreach ($rows as &$row) {
            $row['brand_manufacturer'] = ''; $row['mpn_article_number'] = ''; $row['ean'] = ''; $row['has_ktype_cache'] = ((int) ($row['ktype_count'] ?? 0)) > 0 ? 'yes' : 'no'; $row['ktype_cache_source'] = 'product_map'; $row['dropoff_reason'] = ((int) ($row['ktype_count'] ?? 0)) > 0 ? '' : 'no_local_ktype_cache';
        }
        unset($row); return $rows;
    }
}
