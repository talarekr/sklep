<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;

final class KTypeBackfillAutoRunner
{
    public const CONFIRMATION_TEXT = 'RUN KTYPE BACKFILL';
    public const MAX_BATCH_LIMIT = 50;
    public const CHECKPOINT_OPTION = 'gps_ebay_fitment_sync_ktype_backfill_checkpoint';

    private const COUNTER_KEYS = [
        'total_scanned_products',
        'products_with_raw_part_number',
        'accepted_products',
        'rejected_products',
        'skipped_cached',
        'apify_lookup_attempted',
        'found',
        'not_found',
        'errors',
        'rejected_before_lookup',
    ];

    private ProductScanner $scanner;
    private FitmentLookupService $lookup;
    private Database $database;
    private AuditCsvExporter $auditCsvExporter;

    public function __construct(ProductScanner $scanner, FitmentLookupService $lookup, Database $database, AuditCsvExporter $auditCsvExporter)
    {
        $this->scanner = $scanner;
        $this->lookup = $lookup;
        $this->database = $database;
        $this->auditCsvExporter = $auditCsvExporter;
    }

    /**
     * @return array<string, mixed>
     */
    public static function sanitize_options(array $options): array
    {
        return [
            'run_id' => self::safe_run_id((string) ($options['run_id'] ?? '')),
            'started_at' => (string) ($options['started_at'] ?? gmdate('c')),
            'offset' => max(0, (int) ($options['offset'] ?? 0)),
            'start_offset' => max(0, (int) ($options['start_offset'] ?? ($options['offset'] ?? 0))),
            'batch_limit' => max(1, min(self::MAX_BATCH_LIMIT, (int) ($options['batch_limit'] ?? 10))),
            'batch_number' => max(1, (int) ($options['batch_number'] ?? 1)),
            'max_batches' => max(0, (int) ($options['max_batches'] ?? 0)),
            'export_csv' => !array_key_exists('export_csv', $options) || (bool) $options['export_csv'],
            'persist_product_map' => !array_key_exists('persist_product_map', $options) || (bool) $options['persist_product_map'],
            'dry_run' => !empty($options['dry_run']),
            'resume' => !empty($options['resume']),
            'stop_on_first_error' => !empty($options['stop_on_first_error']),
            'confirmation' => (string) ($options['confirmation'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run_batch(array $options): array
    {
        $options = self::sanitize_options($options);
        $checkpoint = $this->checkpoint();
        if (!empty($options['resume']) && $checkpoint) {
            $options = $this->options_from_checkpoint($options, $checkpoint);
        }

        $runId = $options['run_id'] !== '' ? $options['run_id'] : $this->new_run_id();
        $isLive = empty($options['dry_run']);
        if ($isLive && $options['confirmation'] !== self::CONFIRMATION_TEXT) {
            $result = [
                'success' => false,
                'done' => true,
                'stopped_reason' => 'confirmation_required',
                'error' => 'Type exactly ' . self::CONFIRMATION_TEXT . ' to run live KType backfill.',
                'run_id' => $runId,
                'offset' => $options['offset'],
                'next_offset' => $options['offset'],
                'batch_limit' => $options['batch_limit'],
                'batch_number' => $options['batch_number'],
            ];
            $this->save_checkpoint($this->checkpoint_for_failed_start($options, $runId, 'stopped', (string) $result['error']));
            return $result;
        }

        $offset = (int) $options['offset'];
        $limit = (int) $options['batch_limit'];
        $checkpoint = $this->base_checkpoint($options, $runId, $checkpoint);
        $checkpoint['status'] = 'running';
        $checkpoint['current_offset'] = $offset;
        $checkpoint['next_offset'] = $offset;
        $checkpoint['last_error'] = '';
        $this->save_checkpoint($checkpoint);

        try {
            $scan = $this->scanner->scan($limit, $offset, $isLive && !empty($options['persist_product_map']));
            $backfill = $this->build_backfill_result($scan, $isLive);
            $csv = !empty($options['export_csv'])
                ? $this->auditCsvExporter->export_backfill($scan, $backfill, $offset, $limit, $runId)
                : $this->empty_csv_result($runId);
        } catch (\Throwable $throwable) {
            $nextOffset = $offset + $limit;
            $checkpoint['status'] = 'error';
            $checkpoint['current_offset'] = $offset;
            $checkpoint['next_offset'] = $nextOffset;
            $checkpoint['last_error'] = $throwable->getMessage();
            $this->save_checkpoint($checkpoint);

            return [
                'success' => false,
                'done' => true,
                'stopped_reason' => 'batch_error_checkpoint_saved_next_offset',
                'error' => $throwable->getMessage(),
                'run_id' => $runId,
                'offset' => $offset,
                'next_offset' => $nextOffset,
                'batch_limit' => $limit,
                'batch_number' => (int) $options['batch_number'],
                'checkpoint' => $checkpoint,
            ];
        }

        $nextOffset = $offset + $limit;
        $done = ((int) ($scan['total_scanned_products'] ?? 0)) === 0;
        $stoppedReason = $done ? 'no_products_scanned' : '';
        $batchCounters = $this->batch_counters($scan, $backfill);
        $status = $done ? 'completed' : 'running';
        if (!$done && (int) $options['max_batches'] > 0 && ((int) ($checkpoint['total_batches_completed'] ?? 0) + 1) >= (int) $options['max_batches']) {
            $done = true;
            $status = 'completed';
            $stoppedReason = 'max_batches_reached';
        }
        if (!$done && !empty($options['stop_on_first_error']) && (int) $batchCounters['errors'] > 0) {
            $done = true;
            $status = 'stopped';
            $stoppedReason = 'stop_on_first_error';
        }

        $checkpoint = $this->checkpoint_after_successful_batch($checkpoint, $offset, $nextOffset, $limit, $status, $batchCounters, $csv, $stoppedReason);
        $this->save_checkpoint($checkpoint);

        return array_merge([
            'success' => true,
            'done' => $done,
            'stopped_reason' => $stoppedReason,
            'run_id' => $runId,
            'started_at' => $options['started_at'],
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'last_completed_offset' => (int) ($checkpoint['last_completed_offset'] ?? $offset),
            'batch_limit' => $limit,
            'batch_number' => (int) ($checkpoint['total_batches_completed'] ?? (int) $options['batch_number']),
            'max_batches' => (int) $options['max_batches'],
            'dry_run' => !$isLive,
            'scan' => $scan,
            'backfill' => $backfill,
            'counters' => $batchCounters,
            'aggregate_counters' => $checkpoint['aggregate_counters'],
            'checkpoint' => $checkpoint,
        ], $csv);
    }

    /**
     * @return array<string, mixed>
     */
    public function final_summary(array $summary): array
    {
        $checkpoint = $this->checkpoint();
        $summary['run_id'] = self::safe_run_id((string) ($summary['run_id'] ?? ($checkpoint['run_id'] ?? ''))) ?: $this->new_run_id();
        if ($checkpoint && (string) ($checkpoint['run_id'] ?? '') === (string) $summary['run_id']) {
            $summary = $this->summary_from_checkpoint($checkpoint, $summary);
        }
        $summary['finished_at'] = (string) ($summary['finished_at'] ?? gmdate('c'));
        $result = $this->auditCsvExporter->export_final_summary($summary);
        if ($checkpoint && (string) ($checkpoint['run_id'] ?? '') === (string) $summary['run_id'] && !empty($result['summary_csv_url'])) {
            $checkpoint['final_summary_csv_url'] = (string) $result['summary_csv_url'];
            $checkpoint['status'] = (string) ($summary['stopped_reason'] ?? '') === 'completed' ? 'completed' : (string) ($checkpoint['status'] ?? 'stopped');
            $this->save_checkpoint($checkpoint);
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkpoint(): array
    {
        $checkpoint = get_option(self::CHECKPOINT_OPTION, []);
        return is_array($checkpoint) ? $this->normalize_checkpoint($checkpoint) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function stop(string $reason = 'manual_stop'): array
    {
        $checkpoint = $this->checkpoint();
        if (!$checkpoint) {
            return [];
        }
        $checkpoint['status'] = 'stopped';
        $checkpoint['last_error'] = $reason === 'manual_stop' ? (string) ($checkpoint['last_error'] ?? '') : $reason;
        $this->save_checkpoint($checkpoint);
        return $checkpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function options_from_checkpoint(array $options, array $checkpoint): array
    {
        $options['run_id'] = (string) ($checkpoint['run_id'] ?? $options['run_id']);
        $options['started_at'] = (string) ($checkpoint['started_at'] ?? $options['started_at']);
        $options['offset'] = max(0, (int) ($checkpoint['next_offset'] ?? $checkpoint['last_completed_offset'] ?? $options['offset']));
        $options['start_offset'] = max(0, (int) ($checkpoint['start_offset'] ?? $options['start_offset']));
        $options['batch_limit'] = max(1, min(self::MAX_BATCH_LIMIT, (int) ($checkpoint['batch_limit'] ?? $options['batch_limit'])));
        $options['max_batches'] = max(0, (int) ($checkpoint['max_batches'] ?? $options['max_batches']));
        $options['batch_number'] = max(1, (int) ($checkpoint['total_batches_completed'] ?? 0) + 1);
        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function base_checkpoint(array $options, string $runId, array $existing): array
    {
        if ($existing && (string) ($existing['run_id'] ?? '') === $runId) {
            $checkpoint = $existing;
        } else {
            $checkpoint = [
                'run_id' => $runId,
                'status' => 'running',
                'started_at' => (string) $options['started_at'],
                'start_offset' => (int) $options['start_offset'],
                'current_offset' => (int) $options['offset'],
                'next_offset' => (int) $options['offset'],
                'last_completed_offset' => -1,
                'batch_limit' => (int) $options['batch_limit'],
                'max_batches' => (int) $options['max_batches'],
                'total_batches_completed' => 0,
                'aggregate_counters' => $this->empty_counters(),
                'batch_csv_urls' => [],
                'final_summary_csv_url' => '',
                'last_error' => '',
                'previous_run_id' => (string) ($existing['run_id'] ?? ''),
            ];
        }
        $checkpoint['batch_limit'] = (int) $options['batch_limit'];
        $checkpoint['max_batches'] = (int) $options['max_batches'];
        return $this->normalize_checkpoint($checkpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkpoint_for_failed_start(array $options, string $runId, string $status, string $error): array
    {
        $checkpoint = $this->base_checkpoint($options, $runId, $this->checkpoint());
        $checkpoint['status'] = $status;
        $checkpoint['last_error'] = $error;
        return $checkpoint;
    }

    /**
     * @param array<string, mixed> $csv
     * @return array<string, mixed>
     */
    private function checkpoint_after_successful_batch(array $checkpoint, int $offset, int $nextOffset, int $limit, string $status, array $batchCounters, array $csv, string $stoppedReason): array
    {
        $checkpoint = $this->normalize_checkpoint($checkpoint);
        $aggregate = is_array($checkpoint['aggregate_counters'] ?? null) ? $checkpoint['aggregate_counters'] : $this->empty_counters();
        foreach (self::COUNTER_KEYS as $key) {
            $aggregate[$key] = (int) ($aggregate[$key] ?? 0) + (int) ($batchCounters[$key] ?? 0);
        }

        $checkpoint['status'] = $status;
        $checkpoint['current_offset'] = $offset;
        $checkpoint['next_offset'] = $nextOffset;
        $checkpoint['last_completed_offset'] = $offset;
        $checkpoint['batch_limit'] = $limit;
        $checkpoint['total_batches_completed'] = (int) ($checkpoint['total_batches_completed'] ?? 0) + 1;
        $checkpoint['aggregate_counters'] = $aggregate;
        $checkpoint['last_error'] = $stoppedReason === 'stop_on_first_error' ? 'Stopped after first batch with errors.' : '';
        if (!empty($csv['csv_url'])) {
            $urls = is_array($checkpoint['batch_csv_urls'] ?? null) ? $checkpoint['batch_csv_urls'] : [];
            $urls[] = (string) $csv['csv_url'];
            $checkpoint['batch_csv_urls'] = array_values(array_unique($urls));
        }

        return $checkpoint;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary_from_checkpoint(array $checkpoint, array $fallback): array
    {
        $aggregate = is_array($checkpoint['aggregate_counters'] ?? null) ? $checkpoint['aggregate_counters'] : [];
        return array_merge($fallback, [
            'run_id' => (string) ($checkpoint['run_id'] ?? ($fallback['run_id'] ?? '')),
            'started_at' => (string) ($checkpoint['started_at'] ?? ($fallback['started_at'] ?? '')),
            'stopped_reason' => (string) ($fallback['stopped_reason'] ?? ($checkpoint['status'] ?? '')),
            'start_offset' => (int) ($checkpoint['start_offset'] ?? 0),
            'final_offset' => (int) ($checkpoint['next_offset'] ?? 0),
            'batch_limit' => (int) ($checkpoint['batch_limit'] ?? 0),
            'max_batches' => (int) ($checkpoint['max_batches'] ?? 0),
            'total_batches' => (int) ($checkpoint['total_batches_completed'] ?? 0),
            'total_scanned_products' => (int) ($aggregate['total_scanned_products'] ?? 0),
            'products_with_raw_part_number' => (int) ($aggregate['products_with_raw_part_number'] ?? 0),
            'accepted_products' => (int) ($aggregate['accepted_products'] ?? 0),
            'rejected_products' => (int) ($aggregate['rejected_products'] ?? 0),
            'skipped_cached' => (int) ($aggregate['skipped_cached'] ?? 0),
            'apify_lookup_attempted' => (int) ($aggregate['apify_lookup_attempted'] ?? 0),
            'found' => (int) ($aggregate['found'] ?? 0),
            'not_found' => (int) ($aggregate['not_found'] ?? 0),
            'errors' => (int) ($aggregate['errors'] ?? 0),
            'csv_files_count' => count((array) ($checkpoint['batch_csv_urls'] ?? [])),
            'batch_csv_urls' => (array) ($checkpoint['batch_csv_urls'] ?? []),
            'previous_run_id' => (string) ($checkpoint['previous_run_id'] ?? ''),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function empty_counters(): array
    {
        return array_fill_keys(self::COUNTER_KEYS, 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize_checkpoint(array $checkpoint): array
    {
        $aggregate = is_array($checkpoint['aggregate_counters'] ?? null) ? $checkpoint['aggregate_counters'] : [];
        foreach (self::COUNTER_KEYS as $key) {
            $aggregate[$key] = (int) ($aggregate[$key] ?? 0);
        }
        $checkpoint['run_id'] = self::safe_run_id((string) ($checkpoint['run_id'] ?? ''));
        $checkpoint['status'] = in_array((string) ($checkpoint['status'] ?? ''), ['running', 'stopped', 'error', 'completed'], true) ? (string) $checkpoint['status'] : 'stopped';
        $checkpoint['start_offset'] = max(0, (int) ($checkpoint['start_offset'] ?? 0));
        $checkpoint['current_offset'] = max(0, (int) ($checkpoint['current_offset'] ?? 0));
        $checkpoint['next_offset'] = max(0, (int) ($checkpoint['next_offset'] ?? 0));
        $checkpoint['last_completed_offset'] = (int) ($checkpoint['last_completed_offset'] ?? -1);
        $checkpoint['batch_limit'] = max(1, min(self::MAX_BATCH_LIMIT, (int) ($checkpoint['batch_limit'] ?? 10)));
        $checkpoint['max_batches'] = max(0, (int) ($checkpoint['max_batches'] ?? 0));
        $checkpoint['total_batches_completed'] = max(0, (int) ($checkpoint['total_batches_completed'] ?? 0));
        $checkpoint['aggregate_counters'] = $aggregate;
        $checkpoint['batch_csv_urls'] = array_values(array_map('strval', (array) ($checkpoint['batch_csv_urls'] ?? [])));
        $checkpoint['final_summary_csv_url'] = (string) ($checkpoint['final_summary_csv_url'] ?? '');
        $checkpoint['last_error'] = (string) ($checkpoint['last_error'] ?? '');
        $checkpoint['started_at'] = (string) ($checkpoint['started_at'] ?? gmdate('c'));
        $checkpoint['updated_at'] = (string) ($checkpoint['updated_at'] ?? gmdate('c'));
        $checkpoint['previous_run_id'] = self::safe_run_id((string) ($checkpoint['previous_run_id'] ?? ''));
        return $checkpoint;
    }

    private function save_checkpoint(array $checkpoint): void
    {
        $checkpoint = $this->normalize_checkpoint($checkpoint);
        $checkpoint['updated_at'] = gmdate('c');
        update_option(self::CHECKPOINT_OPTION, $checkpoint, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function build_backfill_result(array $scan, bool $isLive): array
    {
        $processed = [];
        $counters = [
            'accepted_lookup_candidates' => 0,
            'rejected_before_lookup' => (int) ($scan['rejected_count'] ?? 0),
            'skipped_cached' => 0,
            'apify_lookup_attempted' => 0,
            'found' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];

        foreach ((array) ($scan['unique_part_numbers'] ?? []) as $normalized => $raw) {
            $normalized = (string) $normalized;
            $raw = (string) $raw;
            if ($normalized === '') {
                continue;
            }
            $counters['accepted_lookup_candidates']++;
            $cached = $this->database->get_cached_result($normalized);
            if ($cached) {
                $counters['skipped_cached']++;
                $result = $this->cached_result($raw, $normalized, $cached);
            } elseif (!$isLive) {
                continue;
            } else {
                $counters['apify_lookup_attempted']++;
                $result = $this->lookup->lookup($raw, true, false);
            }

            $status = (string) ($result['status'] ?? 'error');
            if ($status === 'found') {
                $counters['found']++;
            } elseif ($status === 'not_found') {
                $counters['not_found']++;
            } elseif ($status === 'error' || $status === 'rejected') {
                $counters['errors']++;
            }
            $processed[] = $result;
        }

        return array_merge(['processed' => $processed, 'rejected' => $scan['rejected_rows'] ?? [], 'limit' => self::MAX_BATCH_LIMIT], $counters);
    }

    /**
     * @return array<string, mixed>
     */
    private function batch_counters(array $scan, array $backfill): array
    {
        return [
            'total_scanned_products' => (int) ($scan['total_scanned_products'] ?? 0),
            'products_with_raw_part_number' => (int) ($scan['products_with_raw_part_number'] ?? 0),
            'accepted_products' => (int) ($scan['accepted_products'] ?? 0),
            'rejected_products' => (int) ($scan['rejected_products'] ?? 0),
            'skipped_cached' => (int) ($backfill['skipped_cached'] ?? 0),
            'apify_lookup_attempted' => (int) ($backfill['apify_lookup_attempted'] ?? 0),
            'found' => (int) ($backfill['found'] ?? 0),
            'not_found' => (int) ($backfill['not_found'] ?? 0),
            'errors' => (int) ($backfill['errors'] ?? 0),
            'rejected_before_lookup' => (int) ($backfill['rejected_before_lookup'] ?? 0),
        ];
    }

    private function cached_result(string $raw, string $normalized, array $cached): array
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
            'cache_lookup_key' => $normalized,
            'cache_part_cache_id' => isset($cached['part_cache']['id']) ? (int) $cached['part_cache']['id'] : null,
            'cache_hit' => true,
            'force_live' => false,
            'save_debug' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty_csv_result(string $runId): array
    {
        return [
            'csv_generated' => false,
            'csv_path' => '',
            'csv_url' => '',
            'csv_row_count' => 0,
            'run_id' => $runId,
            'csv_error' => '',
        ];
    }

    private function new_run_id(): string
    {
        return 'ktype-backfill-' . gmdate('YmdHis') . '-' . substr(hash('sha256', uniqid('', true)), 0, 8);
    }

    private static function safe_run_id(string $runId): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '', $runId) ?: '';
    }
}
