<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class OemKtypeEbayCoverageAudit
{
    public const DEFAULT_RUN_ID = 'ebay-inventory-fitment-20260614-173648-mv5VkJ';
    private ProductScanner $scanner;
    private EbayFitmentPreview $preview;

    public function __construct(ProductScanner $scanner, EbayFitmentPreview $preview)
    {
        $this->scanner = $scanner;
        $this->preview = $preview;
    }

    public function run(string $runId = self::DEFAULT_RUN_ID): array
    {
        $rows = $this->rows($runId);
        return ['run_id' => $runId, 'summary' => $this->summary($rows, $runId), 'rows' => $rows, 'note' => 'Read-only local DB audit. No Apify, eBay API, or Woo writes are performed.'];
    }

    public function export_csv(string $runId = self::DEFAULT_RUN_ID): array
    {
        $audit = $this->run($runId);
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'gps-ebay-fitment-sync/audit';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $file = $dir . '/oem-ktype-ebay-coverage-' . sanitize_file_name($runId) . '-' . gmdate('Ymd-His') . '.csv';
        $url = trailingslashit($upload['baseurl']) . 'gps-ebay-fitment-sync/audit/' . basename($file);
        $out = fopen($file, 'w');
        $summary = $audit['summary'];
        fputcsv($out, ['summary_key', 'summary_value']);
        foreach ($summary as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) { fputcsv($out, [$key . '.' . $subKey, (string) $subValue]); }
            } else {
                fputcsv($out, [$key, (string) $value]);
            }
        }
        fputcsv($out, []);
        $cols = ['product_id','sku','oem','part_number_normalized','has_ktype_cache','ktype_count','has_fr_mapping','has_de_mapping','fr_item_id','de_item_id','fr_offer_id','de_offer_id','last_run_fr_status','last_run_de_status','dropoff_reason_fr','dropoff_reason_de'];
        fputcsv($out, $cols);
        foreach ($audit['rows'] as $row) { fputcsv($out, array_map(static fn(string $c): string => (string) ($row[$c] ?? ''), $cols)); }
        fclose($out);
        return ['path' => $file, 'url' => $url, 'summary' => $summary, 'columns' => $cols];
    }

    private function rows(string $runId): array
    {
        global $wpdb;
        $ids = $this->product_ids();
        $rows = [];
        foreach ($ids as $productId) {
            $resolved = $this->scanner->accepted_product_part_number((int) $productId);
            if (!$resolved) { continue; }
            $preview = $this->preview->inventory_fitment_preview((int) $productId, 'both');
            $product = is_array($preview['product'] ?? null) ? $preview['product'] : [];
            $fr = $preview['results']['EBAY_FR'] ?? [];
            $de = $preview['results']['EBAY_DE'] ?? [];
            $frLog = $this->latest_log($runId, (int) $productId, 'EBAY_FR');
            $deLog = $this->latest_log($runId, (int) $productId, 'EBAY_DE');
            $ktypeCount = (int) ($product['ktype_count'] ?? 0);
            $frItem = (string) ($fr['item_id'] ?? '');
            $deItem = (string) ($de['item_id'] ?? '');
            $rows[] = [
                'product_id' => (string) $productId,
                'sku' => (string) get_post_meta((int) $productId, '_sku', true),
                'oem' => (string) ($resolved['part_number_raw'] ?? ''),
                'part_number_normalized' => (string) ($resolved['part_number_normalized'] ?? ''),
                'has_ktype_cache' => $ktypeCount > 0 ? 'yes' : 'no',
                'ktype_count' => (string) $ktypeCount,
                'has_fr_mapping' => $frItem !== '' ? 'yes' : 'no',
                'has_de_mapping' => $deItem !== '' ? 'yes' : 'no',
                'fr_item_id' => $frItem,
                'de_item_id' => $deItem,
                'fr_offer_id' => (string) ($fr['offer_id'] ?? ''),
                'de_offer_id' => (string) ($de['offer_id'] ?? ''),
                'last_run_fr_status' => (string) ($frLog['status'] ?? ''),
                'last_run_de_status' => (string) ($deLog['status'] ?? ''),
                'dropoff_reason_fr' => $this->dropoff_reason($ktypeCount, $frItem, $frLog, 'fr'),
                'dropoff_reason_de' => $this->dropoff_reason($ktypeCount, $deItem, $deLog, 'de'),
            ];
        }
        return $rows;
    }

    private function summary(array $rows, string $runId): array
    {
        global $wpdb;
        $tables = Database::table_names();
        $log = $tables['ebay_sync_log'];
        $blocked = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(blocked_reason,''),'unknown') reason, COUNT(*) c FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status='blocked' GROUP BY reason ORDER BY c DESC", $runId), ARRAY_A) ?: [];
        $errors = $wpdb->get_results($wpdb->prepare("SELECT COALESCE(NULLIF(error_message,''),'unknown') reason, COUNT(*) c FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status='error' GROUP BY reason ORDER BY c DESC", $runId), ARRAY_A) ?: [];
        return [
            'total_oem_products' => count($rows),
            'products_with_local_ktype_cache' => count(array_filter($rows, static fn($r) => $r['has_ktype_cache'] === 'yes')),
            'oem_products_missing_local_ktype' => count(array_filter($rows, static fn($r) => $r['has_ktype_cache'] !== 'yes')),
            'products_with_ktype_missing_fr_mapping' => count(array_filter($rows, static fn($r) => $r['has_ktype_cache'] === 'yes' && $r['has_fr_mapping'] !== 'yes')),
            'products_with_ktype_missing_de_mapping' => count(array_filter($rows, static fn($r) => $r['has_ktype_cache'] === 'yes' && $r['has_de_mapping'] !== 'yes')),
            'products_with_ktype_and_both_mappings' => count(array_filter($rows, static fn($r) => $r['has_ktype_cache'] === 'yes' && $r['has_fr_mapping'] === 'yes' && $r['has_de_mapping'] === 'yes')),
            'last_run_unique_products_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT product_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND status IN ('success','warning_success')", $runId)),
            'last_run_unique_fr_listings_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ebay_item_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND marketplace='EBAY_FR' AND status IN ('success','warning_success') AND ebay_item_id<>''", $runId)),
            'last_run_unique_de_listings_successful' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ebay_item_id) FROM {$log} WHERE run_id=%s AND api_mode='inventory' AND marketplace='EBAY_DE' AND status IN ('success','warning_success') AND ebay_item_id<>''", $runId)),
            'blocked_by_reason' => $this->pairs($blocked),
            'errors_by_category' => $this->pairs($errors),
        ];
    }

    private function product_ids(): array
    {
        global $wpdb;
        $postTypes = apply_filters('gps_ebay_fitment_sync_product_post_types', ['product', 'product_variation']);
        $placeholders = implode(',', array_fill(0, count($postTypes), '%s'));
        return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status NOT IN ('trash','auto-draft') ORDER BY ID ASC", $postTypes)) ?: []);
    }

    private function latest_log(string $runId, int $productId, string $marketplace): array
    {
        global $wpdb;
        $table = Database::table_names()['ebay_sync_log'];
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE run_id=%s AND product_id=%d AND marketplace=%s AND api_mode='inventory' ORDER BY id DESC LIMIT 1", $runId, $productId, $marketplace), ARRAY_A) ?: [];
    }

    private function dropoff_reason(int $ktypeCount, string $itemId, array $log, string $market): string
    {
        if ($ktypeCount <= 0) { return 'missing_ktype'; }
        if ($itemId === '') { return $market === 'fr' ? 'missing_fr_mapping' : 'missing_de_mapping'; }
        if (!$log) { return 'not_in_last_run'; }
        $status = (string) ($log['status'] ?? '');
        if (in_array($status, ['success', 'warning_success'], true)) { return ''; }
        if ($status === 'blocked') { return (string) (($log['blocked_reason'] ?? '') ?: 'blocked'); }
        if ($status === 'error') { return 'error:' . (string) (($log['error_message'] ?? '') ?: 'unknown'); }
        return $status !== '' ? $status : 'unknown';
    }

    private function pairs(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) { $out[(string) ($row['reason'] ?? 'unknown')] = (int) ($row['c'] ?? 0); }
        return $out;
    }
}
