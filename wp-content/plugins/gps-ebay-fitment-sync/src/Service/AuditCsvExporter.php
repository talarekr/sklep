<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class AuditCsvExporter
{
    public const AUDIT_SUBDIR = 'gps-ebay-fitment-sync/audit';

    private const COLUMNS = [
        'run_id',
        'run_type',
        'product_id',
        'sku',
        'source_field',
        'source_raw',
        'part_number_raw',
        'part_number_normalized',
        'validation_status',
        'rejection_reason',
        'warnings',
        'cache_status',
        'lookup_attempted',
        'lookup_status',
        'article_count',
        'vehicle_count',
        'unique_vehicle_ids',
        'ktype_count',
        'supplier_names',
        'article_numbers',
        'error_message',
        'not_found_reason',
        'from_cache',
        'saved_to_cache',
        'part_cache_id',
        'created_at',
    ];

    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @return string[]
     */
    public static function columns(): array
    {
        return self::COLUMNS;
    }

    /**
     * @return array<string, mixed>
     */
    public function export_backfill(array $scan, array $backfill, int $offset, int $limit): array
    {
        $runId = $this->run_id('backfill');
        $resultsByPart = [];
        foreach (($backfill['processed'] ?? []) as $result) {
            if (is_array($result) && isset($result['part_number_normalized'])) {
                $resultsByPart[(string) $result['part_number_normalized']] = $result;
            }
        }

        $rows = [];
        foreach (($scan['accepted_rows'] ?? $scan['accepted_sample'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $normalized = (string) ($candidate['part_number_normalized'] ?? '');
            $result = $resultsByPart[$normalized] ?? null;
            $rows[] = $this->row_from_accepted_candidate($runId, 'backfill', $candidate, is_array($result) ? $result : null);
        }

        foreach (($scan['rejected_rows'] ?? $scan['rejected_sample'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $rows[] = $this->row_from_rejected_candidate($runId, 'backfill', $candidate);
            }
        }

        $filename = sprintf('gps-fitment-backfill-audit-%s-offset-%d-limit-%d.csv', gmdate('Ymd-His'), max(0, $offset), max(1, $limit));

        return $this->write($filename, $rows, $runId);
    }

    /**
     * @return array<string, mixed>
     */
    public function export_scan_preview(array $scan, int $offset, int $limit): array
    {
        $runId = $this->run_id('scan_preview');
        $rows = [];
        foreach (($scan['accepted_rows'] ?? $scan['accepted_sample'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $rows[] = $this->row_from_scan_candidate($runId, $candidate);
            }
        }

        foreach (($scan['rejected_rows'] ?? $scan['rejected_sample'] ?? []) as $candidate) {
            if (is_array($candidate)) {
                $rows[] = $this->row_from_rejected_candidate($runId, 'scan_preview', $candidate);
            }
        }

        $filename = sprintf('gps-fitment-scan-preview-audit-%s-offset-%d-limit-%d.csv', gmdate('Ymd-His'), max(0, $offset), max(1, $limit));

        return $this->write($filename, $rows, $runId);
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function row_from_scan_candidate(string $runId, array $candidate): array
    {
        $cached = $this->database->get_cached_result((string) ($candidate['part_number_normalized'] ?? ''));
        $row = $this->empty_row($runId, 'scan_preview', $candidate);
        $row['validation_status'] = empty($candidate['warnings']) ? 'accepted' : 'suspicious';
        $row['warnings'] = $this->join_values($candidate['warnings'] ?? []);
        $row['cache_status'] = $cached ? 'hit' : 'miss';
        $row['lookup_attempted'] = 'no';
        $row['lookup_status'] = 'not_run';
        $row['from_cache'] = $cached ? 'yes' : 'no';
        if ($cached) {
            $this->merge_lookup_result($row, $this->cached_result_for_row((string) ($candidate['part_number_raw'] ?? ''), (string) ($candidate['part_number_normalized'] ?? ''), $cached), false);
            $row['lookup_status'] = 'not_run';
            $row['lookup_attempted'] = 'no';
        }

        return $row;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function row_from_accepted_candidate(string $runId, string $runType, array $candidate, ?array $result): array
    {
        $row = $this->empty_row($runId, $runType, $candidate);
        $row['validation_status'] = empty($candidate['warnings']) ? 'accepted' : 'suspicious';
        $row['warnings'] = $this->join_values($candidate['warnings'] ?? []);

        if (!$result) {
            $cached = $this->database->get_cached_result((string) ($candidate['part_number_normalized'] ?? ''));
            $row['cache_status'] = $cached ? 'hit' : 'miss';
            $row['lookup_attempted'] = 'no';
            $row['lookup_status'] = 'not_run';
            $row['from_cache'] = $cached ? 'yes' : 'no';
            if ($cached) {
                $this->merge_lookup_result($row, $this->cached_result_for_row((string) ($candidate['part_number_raw'] ?? ''), (string) ($candidate['part_number_normalized'] ?? ''), $cached), false);
            }
            return $row;
        }

        $this->merge_lookup_result($row, $result, true);
        return $row;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function row_from_rejected_candidate(string $runId, string $runType, array $candidate): array
    {
        $row = $this->empty_row($runId, $runType, $candidate);
        $row['validation_status'] = 'rejected';
        $row['rejection_reason'] = (string) ($candidate['rejection_reason'] ?? 'rejected');
        $row['warnings'] = $this->join_values($candidate['warnings'] ?? []);
        $row['cache_status'] = 'not_saved';
        $row['lookup_attempted'] = 'no';
        $row['lookup_status'] = 'rejected';
        $row['not_found_reason'] = '';
        return $row;
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function empty_row(string $runId, string $runType, array $candidate): array
    {
        return [
            'run_id' => $runId,
            'run_type' => $runType,
            'product_id' => isset($candidate['product_id']) ? (int) $candidate['product_id'] : '',
            'sku' => (string) ($candidate['sku'] ?? ''),
            'source_field' => (string) ($candidate['source_field'] ?? ''),
            'source_raw' => (string) ($candidate['source_raw'] ?? ''),
            'part_number_raw' => (string) ($candidate['part_number_raw'] ?? $candidate['raw'] ?? ''),
            'part_number_normalized' => (string) ($candidate['part_number_normalized'] ?? $candidate['normalized'] ?? ''),
            'validation_status' => '',
            'rejection_reason' => '',
            'warnings' => '',
            'cache_status' => 'miss',
            'lookup_attempted' => 'no',
            'lookup_status' => 'not_run',
            'article_count' => 0,
            'vehicle_count' => 0,
            'unique_vehicle_ids' => '',
            'ktype_count' => 0,
            'supplier_names' => '',
            'article_numbers' => '',
            'error_message' => '',
            'not_found_reason' => '',
            'from_cache' => 'no',
            'saved_to_cache' => 'no',
            'part_cache_id' => '',
            'created_at' => current_time('mysql'),
        ];
    }

    /**
     * @param array<string, string|int|bool|null> $row
     */
    private function merge_lookup_result(array &$row, array $result, bool $markLookup): void
    {
        $status = (string) ($result['status'] ?? 'error');
        $fromCache = !empty($result['from_cache']);
        $saved = !empty($result['saved']);
        $articles = is_array($result['articles'] ?? null) ? $result['articles'] : [];
        $vehicles = is_array($result['vehicles'] ?? null) ? $result['vehicles'] : [];
        $vehicleIds = $this->vehicle_ids($result, $vehicles);
        $errors = is_array($result['errors'] ?? null) ? array_map('strval', $result['errors']) : [];

        $row['cache_status'] = $fromCache ? 'hit' : ($saved ? 'saved' : 'miss');
        $row['lookup_attempted'] = ($markLookup && !$fromCache) ? 'yes' : 'no';
        $row['lookup_status'] = $fromCache ? 'skipped_cached' : $status;
        $row['article_count'] = count($articles);
        $row['vehicle_count'] = count($vehicleIds);
        $row['unique_vehicle_ids'] = implode(',', $vehicleIds);
        $row['ktype_count'] = count($vehicleIds);
        $row['supplier_names'] = $this->article_values($articles, ['supplierName', 'supplier_name']);
        $row['article_numbers'] = $this->article_values($articles, ['articleNo', 'article_no']);
        $row['error_message'] = $status === 'error' ? implode("\n", $errors) : '';
        $row['not_found_reason'] = $status === 'not_found' ? (implode("\n", $errors) ?: 'No TecDoc articles found for this OEM/part number.') : '';
        $row['from_cache'] = $fromCache ? 'yes' : 'no';
        $row['saved_to_cache'] = $saved ? 'yes' : 'no';
        $row['part_cache_id'] = isset($result['cache_part_cache_id']) && $result['cache_part_cache_id'] !== null ? (int) $result['cache_part_cache_id'] : '';
    }

    private function cached_result_for_row(string $raw, string $normalized, array $cached): array
    {
        return [
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'status' => (string) ($cached['part_cache']['status'] ?? ''),
            'articles' => $cached['articles'] ?? [],
            'vehicles' => $cached['vehicles'] ?? [],
            'unique_vehicle_ids' => $cached['unique_vehicle_ids'] ?? [],
            'errors' => !empty($cached['part_cache']['error_message']) ? explode("\n", (string) $cached['part_cache']['error_message']) : [],
            'from_cache' => true,
            'saved' => false,
            'cache_part_cache_id' => isset($cached['part_cache']['id']) ? (int) $cached['part_cache']['id'] : null,
        ];
    }

    /**
     * @return string[]
     */
    private function vehicle_ids(array $result, array $vehicles): array
    {
        $ids = [];
        if (is_array($result['unique_vehicle_ids'] ?? null)) {
            $ids = array_map('strval', $result['unique_vehicle_ids']);
        }
        foreach ($vehicles as $vehicle) {
            if (!is_array($vehicle)) {
                continue;
            }
            $id = (string) ($vehicle['vehicleId'] ?? $vehicle['vehicle_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
    }

    private function article_values(array $articles, array $keys): string
    {
        $values = [];
        foreach ($articles as $article) {
            if (!is_array($article)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($article[$key]) && (string) $article[$key] !== '') {
                    $values[] = (string) $article[$key];
                    break;
                }
            }
        }

        return implode(',', array_values(array_unique($values)));
    }

    private function join_values($values): string
    {
        if (!is_array($values)) {
            return (string) $values;
        }

        return implode(' | ', array_map('strval', $values));
    }

    /**
     * @return array<string, mixed>
     */
    private function write(string $filename, array $rows, string $runId): array
    {
        $rows = $this->sort_rows($rows);
        $directory = $this->audit_directory();
        if ($directory === '') {
            return $this->failed_result($runId, count($rows), 'Unable to resolve WordPress uploads directory.');
        }

        if (!is_dir($directory) && !wp_mkdir_p($directory)) {
            return $this->failed_result($runId, count($rows), 'Unable to create audit CSV directory.');
        }

        $path = $directory . '/' . sanitize_file_name($filename);
        $handle = fopen($path, 'wb');
        if (!$handle) {
            return $this->failed_result($runId, count($rows), 'Unable to open audit CSV file for writing.');
        }

        fputcsv($handle, self::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), self::COLUMNS));
        }
        fclose($handle);

        return [
            'csv_generated' => true,
            'csv_path' => $path,
            'csv_url' => $this->audit_url(basename($path)),
            'csv_row_count' => count($rows),
            'run_id' => $runId,
            'csv_error' => '',
        ];
    }

    /**
     * @return array<int, array<string, string|int|bool|null>>
     */
    private function sort_rows(array $rows): array
    {
        $order = [
            'found' => 10,
            'not_found' => 20,
            'error' => 30,
            'rejected' => 40,
            'skipped_cached' => 50,
            'not_run' => 60,
        ];

        usort($rows, static function (array $a, array $b) use ($order): int {
            $groupA = $order[(string) ($a['lookup_status'] ?? '')] ?? 99;
            $groupB = $order[(string) ($b['lookup_status'] ?? '')] ?? 99;
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }

            $partCompare = strcmp((string) ($a['part_number_normalized'] ?? ''), (string) ($b['part_number_normalized'] ?? ''));
            if ($partCompare !== 0) {
                return $partCompare;
            }

            return (int) ($a['product_id'] ?? 0) <=> (int) ($b['product_id'] ?? 0);
        });

        return $rows;
    }

    private function audit_directory(): string
    {
        $uploads = wp_upload_dir(null, false);
        $basedir = isset($uploads['basedir']) ? rtrim((string) $uploads['basedir'], '/\\') : '';
        if ($basedir === '') {
            return '';
        }

        return $basedir . '/' . self::AUDIT_SUBDIR;
    }

    private function audit_url(string $basename): string
    {
        $uploads = wp_upload_dir(null, false);
        $baseurl = isset($uploads['baseurl']) ? rtrim((string) $uploads['baseurl'], '/\\') : '';
        if ($baseurl === '') {
            return '';
        }

        return $baseurl . '/' . self::AUDIT_SUBDIR . '/' . rawurlencode($basename);
    }

    /**
     * @return array<string, mixed>
     */
    private function failed_result(string $runId, int $rowCount, string $error): array
    {
        return [
            'csv_generated' => false,
            'csv_path' => '',
            'csv_url' => '',
            'csv_row_count' => $rowCount,
            'run_id' => $runId,
            'csv_error' => $error,
        ];
    }

    private function run_id(string $type): string
    {
        return $type . '-' . gmdate('YmdHis') . '-' . substr(hash('sha256', uniqid('', true)), 0, 8);
    }
}
