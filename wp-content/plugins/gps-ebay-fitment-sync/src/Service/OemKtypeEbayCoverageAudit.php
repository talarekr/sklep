<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class OemKtypeEbayCoverageAudit
{
    public const DEFAULT_RUN_ID = 'ebay-inventory-fitment-20260614-173648-mv5VkJ';
    private const BATCH_SIZE = 500;
    private const PREVIEW_LIMIT = 50;

    public function __construct(ProductScanner $scanner, EbayFitmentPreview $preview) {}

    public function run(string $runId = self::DEFAULT_RUN_ID, int $previewLimit = self::PREVIEW_LIMIT): array
    {
        $before = memory_get_usage(true);
        $previewLimit = max(0, min(200, $previewLimit));
        $summary = $this->summary($runId);
        $rows = $this->preview_rows($runId, $previewLimit);
        $summary['memory_usage_after'] = memory_get_usage(true);
        $summary['peak_memory_usage'] = memory_get_peak_usage(true);
        return [
            'run_id' => $runId,
            'summary' => $summary,
            'rows' => $rows,
            'preview_limit' => $previewLimit,
            'note' => 'Read-only local DB audit. Summary is aggregate SQL; admin rows are a bounded preview only. No Apify, eBay API, or Woo writes are performed.',
            'memory_diagnostics' => [
                'memory_limit' => ini_get('memory_limit'),
                'memory_usage_before' => $before,
                'memory_usage_after' => memory_get_usage(true),
                'peak_memory_usage' => memory_get_peak_usage(true),
                'batch_size' => self::BATCH_SIZE,
                'rows_streamed' => 0,
            ],
        ];
    }

    public function export_csv(string $runId = self::DEFAULT_RUN_ID): array
    {
        $before = memory_get_usage(true);
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'gps-ebay-fitment-sync/audit';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $file = $dir . '/oem-ktype-ebay-coverage-' . sanitize_file_name($runId) . '-' . gmdate('Ymd-His') . '.csv';
        $url = trailingslashit($upload['baseurl']) . 'gps-ebay-fitment-sync/audit/' . basename($file);
        $out = fopen($file, 'w');
        if (!$out) { return ['path' => '', 'url' => '', 'summary' => [], 'columns' => [], 'error' => 'Unable to open CSV for writing.']; }
        $summary = $this->summary($runId);
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($summary as $key => $value) {
            if (is_array($value)) { foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); } continue; }
            fputcsv($out, [$key, (string) $value]);
        }
        fputcsv($out, []);
        $cols = $this->columns();
        fputcsv($out, $cols);
        $rowsStreamed = $this->stream_rows($runId, $out, $cols);
        fclose($out);
        return ['path' => $file, 'url' => $url, 'summary' => $summary, 'columns' => $cols, 'memory_diagnostics' => ['memory_limit' => ini_get('memory_limit'), 'memory_usage_before' => $before, 'memory_usage_after' => memory_get_usage(true), 'peak_memory_usage' => memory_get_peak_usage(true), 'batch_size' => self::BATCH_SIZE, 'rows_streamed' => $rowsStreamed]];
    }


    public function stream_csv_download(string $runId = self::DEFAULT_RUN_ID): void
    {
        $before = memory_get_usage(true);
        $filename = 'oem-ktype-ebay-coverage-' . gmdate('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-GPS-Audit-Memory-Limit: ' . (string) ini_get('memory_limit'));
        header('X-GPS-Audit-Memory-Usage-Before: ' . (string) $before);
        $out = fopen('php://output', 'w');
        if (!$out) { return; }
        $summary = $this->summary($runId);
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($summary as $key => $value) { if (is_array($value)) { foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); } } else { fputcsv($out, [$key, (string) $value]); } }
        fputcsv($out, []);
        $cols = $this->columns();
        fputcsv($out, $cols);
        $this->stream_rows($runId, $out, $cols);
        fclose($out);
    }

    private function summary(string $runId): array
    {
        global $wpdb;
        $tables = Database::table_names();
        $map = $tables['product_map']; $part = $tables['part_cache']; $log = $tables['ebay_sync_log'];
        $mapping = $wpdb->prefix . 'marketplace_mappings';
        $mappingExists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $mapping)) === $mapping;
        $frJoin = $mappingExists ? "EXISTS (SELECT 1 FROM {$mapping} mm WHERE mm.woo_product_id=pm.product_id AND mm.marketplace IN ('ebay_fr','fr','EBAY_FR') AND mm.remote_listing_id<>'')" : '0';
        $deJoin = $mappingExists ? "EXISTS (SELECT 1 FROM {$mapping} mm WHERE mm.woo_product_id=pm.product_id AND mm.marketplace IN ('ebay_de','de','EBAY_DE') AND mm.remote_listing_id<>'')" : '0';
        $blocked = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(blocked_reason,''),'unknown') reason, COUNT(*) c FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status='blocked' GROUP BY reason ORDER BY c DESC LIMIT 100", $runId), ARRAY_A) ?: [];
        $errors = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(error_message,''),'unknown') reason, COUNT(*) c FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status='error' GROUP BY reason ORDER BY c DESC LIMIT 100", $runId), ARRAY_A) ?: [];
        return [
            'total_oem_products' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$map}"),
            'products_with_local_ktype_cache' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) > 0"),
            'oem_products_missing_local_ktype' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) <= 0"),
            'products_with_ktype_missing_fr_mapping' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) > 0 AND NOT ({$frJoin})"),
            'products_with_ktype_missing_de_mapping' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) > 0 AND NOT ({$deJoin})"),
            'products_with_ktype_and_both_mappings' => (int) $wpdb->get_var("SELECT COUNT(DISTINCT pm.product_id) FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id WHERE GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0)) > 0 AND ({$frJoin}) AND ({$deJoin})"),
            'last_run_unique_products_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT product_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status IN ('success','warning_success')", $runId)),
            'last_run_unique_fr_listings_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ebay_item_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND marketplace='EBAY_FR' AND status IN ('success','warning_success') AND ebay_item_id<>''", $runId)),
            'last_run_unique_de_listings_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ebay_item_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND marketplace='EBAY_DE' AND status IN ('success','warning_success') AND ebay_item_id<>''", $runId)),
            'blocked_by_reason' => $this->pairs($blocked), 'errors_by_category' => $this->pairs($errors), 'memory_limit' => ini_get('memory_limit'), 'batch_size' => self::BATCH_SIZE,
        ];
    }

    private function preview_rows(string $runId, int $limit): array
    {
        if ($limit <= 0) { return []; }
        return $this->fetch_rows($runId, 0, $limit);
    }

    private function stream_rows(string $runId, $out, array $cols): int
    { $count = 0; $last = 0; do { $rows = $this->fetch_rows($runId, $last, self::BATCH_SIZE); $batchCount = count($rows); foreach ($rows as $row) { $last = max($last, (int) $row['product_id']); fputcsv($out, array_map(static fn(string $c): string => (string) ($row[$c] ?? ''), $cols)); $count++; } if ($count % self::BATCH_SIZE === 0) { fflush($out); } unset($rows); } while ($batchCount === self::BATCH_SIZE); return $count; }

    private function fetch_rows(string $runId, int $lastProductId, int $limit): array
    {
        global $wpdb; $tables = Database::table_names(); $map = $tables['product_map']; $part = $tables['part_cache']; $log = $tables['ebay_sync_log'];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT pm.product_id, MAX(pm.sku) sku, MIN(pm.part_number_raw) oem, MIN(pm.part_number_normalized) part_number_normalized, MAX(GREATEST(pm.vehicle_count, COALESCE(pc.vehicle_count,0))) ktype_count, MAX(CASE WHEN l.marketplace='EBAY_FR' THEN l.ebay_item_id ELSE '' END) fr_item_id, MAX(CASE WHEN l.marketplace='EBAY_DE' THEN l.ebay_item_id ELSE '' END) de_item_id, MAX(CASE WHEN l.marketplace='EBAY_FR' THEN l.offer_id ELSE '' END) fr_offer_id, MAX(CASE WHEN l.marketplace='EBAY_DE' THEN l.offer_id ELSE '' END) de_offer_id, MAX(CASE WHEN l.marketplace='EBAY_FR' THEN l.status ELSE '' END) last_run_fr_status, MAX(CASE WHEN l.marketplace='EBAY_DE' THEN l.status ELSE '' END) last_run_de_status FROM {$map} pm LEFT JOIN {$part} pc ON pc.id=pm.part_cache_id LEFT JOIN {$log} l ON l.run_id=%s AND l.api_mode='inventory' AND l.product_id=pm.product_id WHERE pm.product_id > %d GROUP BY pm.product_id ORDER BY pm.product_id ASC LIMIT %d", $runId, $lastProductId, $limit), ARRAY_A) ?: [];
        foreach ($rows as &$row) { $row['has_ktype_cache'] = ((int) ($row['ktype_count'] ?? 0)) > 0 ? 'yes' : 'no'; }
        unset($row);
        return $rows;
    }

    private function columns(): array { return ['product_id','sku','oem','part_number_normalized','has_ktype_cache','ktype_count','fr_item_id','de_item_id','fr_offer_id','de_offer_id','last_run_fr_status','last_run_de_status']; }
    private function pairs(array $rows): array { $out=[]; foreach ($rows as $row) { $out[(string)($row['reason'] ?? 'unknown')] = (int)($row['c'] ?? 0); } return $out; }
}
