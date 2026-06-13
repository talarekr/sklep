<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class KTypeBackfillAutoRunner
{
    public const CONFIRMATION_TEXT = 'RUN KTYPE BACKFILL';
    public const MAX_BATCH_LIMIT = 1;
    public const DEFAULT_MAX_APIFY_LOOKUPS_PER_BATCH = 1;
    public const MAX_APIFY_LOOKUPS_PER_BATCH = 1;
    public const DEFAULT_STALL_THRESHOLD_SECONDS = 300;
    public const CHECKPOINT_OPTION = 'gps_ebay_fitment_sync_ktype_backfill_checkpoint';

    private const MAX_ASYNC_ATTEMPTS = 3;
    private const DEFAULT_MAX_TECDOC_ARTICLES_PER_PART_FOR_VEHICLE_LOOKUP = 5;
    private const MAX_TECDOC_ARTICLES_PER_PART_FOR_VEHICLE_LOOKUP = 10;

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
        'deferred_due_to_lookup_cap',
    ];

    private ProductScanner $scanner;
    private FitmentLookupService $lookup;
    private Database $database;
    private AuditCsvExporter $auditCsvExporter;
    private Settings $settings;

    public function __construct(ProductScanner $scanner, FitmentLookupService $lookup, Database $database, AuditCsvExporter $auditCsvExporter, ?Settings $settings = null)
    {
        $this->scanner = $scanner;
        $this->lookup = $lookup;
        $this->database = $database;
        $this->auditCsvExporter = $auditCsvExporter;
        $this->settings = $settings ?: new Settings();
    }

    /**
     * @return array<string, mixed>
     */
    public static function sanitize_options(array $options): array
    {
        $offset = max(0, (int) ($options['offset'] ?? 0));
        return [
            'run_id' => self::safe_run_id((string) ($options['run_id'] ?? '')),
            'started_at' => (string) ($options['started_at'] ?? gmdate('c')),
            'offset' => $offset,
            'start_offset' => max(0, (int) ($options['start_offset'] ?? $offset)),
            'batch_limit' => 1,
            'batch_number' => max(1, (int) ($options['batch_number'] ?? 1)),
            'max_batches' => max(0, (int) ($options['max_batches'] ?? 0)),
            'export_csv' => false,
            'persist_product_map' => !array_key_exists('persist_product_map', $options) || (bool) $options['persist_product_map'],
            'dry_run' => !empty($options['dry_run']),
            'resume' => !empty($options['resume']),
            'stop_on_first_error' => !empty($options['stop_on_first_error']),
            'confirmation' => (string) ($options['confirmation'] ?? ''),
            'max_apify_lookups_per_batch' => 1,
            'last_request_duration_seconds' => max(0.0, (float) ($options['last_request_duration_seconds'] ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run_batch(array $options): array
    {
        $options = self::sanitize_options($options);
        $storedCheckpoint = $this->checkpoint();
        if (!empty($options['resume']) && $storedCheckpoint) {
            $options = $this->options_from_checkpoint($options, $storedCheckpoint);
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
                'batch_limit' => 1,
                'batch_number' => $options['batch_number'],
            ];
            $this->save_checkpoint($this->checkpoint_for_failed_start($options, $runId, 'stopped', (string) $result['error']));
            return $result;
        }

        $offset = (int) $options['offset'];
        $checkpoint = $this->base_checkpoint($options, $runId, $storedCheckpoint);
        $checkpoint['status'] = 'running';
        $checkpoint['current_offset'] = $offset;
        $checkpoint['next_offset'] = $offset;
        $checkpoint['last_error'] = '';
        $checkpoint['last_batch_started_at'] = gmdate('c');
        $checkpoint['in_flight_since'] = $checkpoint['last_batch_started_at'];
        $checkpoint['last_request_duration_seconds'] = (float) $options['last_request_duration_seconds'];
        $this->save_checkpoint($checkpoint);

        $requestStarted = microtime(true);
        $timing = [
            'total_batch_duration_seconds' => 0.0,
            'apify_duration_seconds' => 0.0,
            'csv_duration_seconds' => 0.0,
            'summary_duration_seconds' => 0.0,
            'request_duration_seconds' => (float) $options['last_request_duration_seconds'],
            'delay_duration_seconds' => 0.0,
        ];

        try {
            $scan = $this->scanner->scan(1, $offset, $isLive && !empty($options['persist_product_map']));
            $apifyStarted = microtime(true);
            $item = $this->process_one_scan_result($scan, $isLive, !empty($options['persist_product_map']), $runId);
            $timing['apify_duration_seconds'] = round(microtime(true) - $apifyStarted, 3);
            $timing['total_batch_duration_seconds'] = round(microtime(true) - $requestStarted, 3);
            $timing['request_duration_seconds'] = $timing['total_batch_duration_seconds'];
        } catch (\Throwable $throwable) {
            $checkpoint['status'] = 'error';
            $checkpoint['current_offset'] = $offset;
            $checkpoint['next_offset'] = $offset;
            $checkpoint['last_error'] = $throwable->getMessage();
            $checkpoint['in_flight_since'] = '';
            $checkpoint['last_batch_finished_at'] = gmdate('c');
            $checkpoint['last_request_duration_seconds'] = round(microtime(true) - $requestStarted, 3);
            $this->save_checkpoint($checkpoint);

            return [
                'success' => false,
                'done' => true,
                'stopped_reason' => 'item_error_checkpoint_kept_current_offset',
                'error' => $throwable->getMessage(),
                'run_id' => $runId,
                'offset' => $offset,
                'next_offset' => $offset,
                'batch_limit' => 1,
                'batch_number' => (int) $options['batch_number'],
                'checkpoint' => $this->small_checkpoint($checkpoint),
            ];
        }

        $scanned = (int) ($scan['total_scanned_products'] ?? 0);
        $done = $scanned === 0;
        $pendingAsync = !empty($item['pending_async']);
        $nextOffset = $done ? $offset : ($pendingAsync ? $offset : $offset + 1);
        $stoppedReason = $done ? 'no_products_scanned' : '';
        $status = $done ? 'completed' : 'running';
        $batchCounters = $this->batch_counters($scan, $item);
        if (!$done && !$pendingAsync && (int) $options['max_batches'] > 0 && ((int) ($checkpoint['total_batches_completed'] ?? 0) + 1) >= (int) $options['max_batches']) {
            $done = true;
            $status = 'completed';
            $stoppedReason = 'max_batches_reached';
        }
        if (!$done && !$pendingAsync && !empty($options['stop_on_first_error']) && (int) $batchCounters['errors'] > 0) {
            $done = true;
            $status = 'stopped';
            $stoppedReason = 'stop_on_first_error';
        }

        $checkpoint = $this->checkpoint_after_successful_item($checkpoint, $offset, $nextOffset, $status, $batchCounters, $stoppedReason, $timing, $item);
        $this->save_checkpoint($checkpoint);

        return [
            'success' => true,
            'done' => $done,
            'stopped_reason' => $stoppedReason,
            'run_id' => $runId,
            'started_at' => $options['started_at'],
            'offset' => $offset,
            'next_offset' => $nextOffset,
            'last_completed_offset' => (int) ($checkpoint['last_completed_offset'] ?? $offset),
            'batch_limit' => 1,
            'batch_number' => (int) ($checkpoint['total_batches_completed'] ?? (int) $options['batch_number']),
            'max_batches' => (int) $options['max_batches'],
            'dry_run' => !$isLive,
            'processed_item' => $item,
            'counters' => $batchCounters,
            'aggregate_counters' => $checkpoint['aggregate_counters'],
            'timing' => $timing,
            'checkpoint' => $this->small_checkpoint($checkpoint),
            'csv_generated' => false,
            'csv_path' => '',
            'csv_url' => '',
            'csv_row_count' => 0,
            'csv_error' => '',
        ];
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
        if ($checkpoint && (string) ($checkpoint['run_id'] ?? '') === (string) $summary['run_id']) {
            $audit = $this->auditCsvExporter->export_auto_runner_final($checkpoint, $this->scanner);
            $result = array_merge($result, $audit);
            if (!empty($result['summary_csv_url'])) {
                $checkpoint['final_summary_csv_url'] = (string) $result['summary_csv_url'];
            }
            if (!empty($result['final_csv_url'])) {
                $checkpoint['final_audit_csv_url'] = (string) $result['final_csv_url'];
            }
            if (!empty($result['found_only_csv_url'])) {
                $checkpoint['found_only_csv_url'] = (string) $result['found_only_csv_url'];
            }
            if ((string) ($summary['stopped_reason'] ?? '') === 'completed') {
                $checkpoint['status'] = 'completed';
            }
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
        $checkpoint['status'] = $reason === 'stalled_no_progress' || str_contains($reason, 'request_failed') ? 'error' : 'stopped';
        $checkpoint['stopped_reason'] = $reason;
        $checkpoint['in_flight_since'] = '';
        $checkpoint['last_batch_finished_at'] = gmdate('c');
        $checkpoint['last_error'] = $reason === 'manual_stop' ? (string) ($checkpoint['last_error'] ?? '') : $reason;
        $this->save_checkpoint($checkpoint);
        return $this->small_checkpoint($checkpoint);
    }

    /**
     * @return array<string, mixed>
     */
    private function process_one_scan_result(array $scan, bool $isLive, bool $persistProductMap, string $runId): array
    {
        $acceptedRows = array_values(array_filter((array) ($scan['accepted_rows'] ?? []), 'is_array'));
        $rejectedRows = array_values(array_filter((array) ($scan['rejected_rows'] ?? []), 'is_array'));

        if ($acceptedRows) {
            $candidate = $acceptedRows[0];
            $raw = (string) ($candidate['part_number_raw'] ?? '');
            $normalized = (string) ($candidate['part_number_normalized'] ?? '');
            $cached = $this->database->get_cached_result($normalized);
            if ($cached) {
                $item = $this->small_item_from_result($candidate, $this->cached_result($raw, $normalized, $cached), 'skipped_cached');
            } elseif (!$isLive) {
                $item = $this->small_item($candidate, 'not_run', 0, 0, 0, '', null, false);
            } else {
                $item = $this->process_async_live_lookup($candidate, $runId);
            }

            if ($persistProductMap) {
                $this->database->upsert_product_map($candidate);
            }
            return $item;
        }

        if ($rejectedRows) {
            $candidate = $rejectedRows[0];
            return $this->small_item(
                $candidate,
                'rejected',
                0,
                0,
                0,
                (string) ($candidate['rejection_reason'] ?? 'rejected'),
                null,
                false
            );
        }

        return [
            'product_id' => null,
            'part_number_normalized' => '',
            'status' => 'empty',
            'ktype_count' => 0,
            'article_count' => 0,
            'vehicle_count' => 0,
            'error_message' => '',
            'part_cache_id' => null,
            'from_cache' => false,
        ];
    }


    /**
     * @return array<string, mixed>
     */
    private function process_async_live_lookup(array $candidate, string $runId): array
    {
        $raw = (string) ($candidate['part_number_raw'] ?? '');
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        $cached = $this->database->get_cached_result($normalized);
        if ($cached) {
            return $this->small_item_from_result($candidate, $this->cached_result($raw, $normalized, $cached), 'skipped_cached');
        }

        $this->enforce_vehicle_article_limit($candidate, $runId);
        $active = $this->database->next_active_apify_job($runId, $normalized);
        if (!$active) {
            $existingJobs = $this->database->apify_jobs_for_part($runId, $normalized);
            if (!$existingJobs) {
                return $this->start_articles_job($candidate, $runId);
            }
            return $this->finalize_async_if_ready($candidate, $runId, 'No active Apify job remains before cache finalization.');
        }

        return $this->poll_async_job($candidate, $runId, $active);
    }

    /**
     * @return array<string, mixed>
     */
    private function start_articles_job(array $candidate, string $runId): array
    {
        $raw = (string) ($candidate['part_number_raw'] ?? '');
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        $job = $this->database->find_active_apify_job($runId, $normalized, 'articles');
        if ($job) {
            return $this->async_item($candidate, 'async_articles_running', $job, 'Reused existing async article job.');
        }

        $started = $this->lookup->start_async_run($this->lookup->article_search_payload($normalized));
        $status = !empty($started['success']) ? 'running' : 'failed';
        $job = $this->database->create_apify_job([
            'run_id' => $runId,
            'product_id' => (int) ($candidate['product_id'] ?? 0),
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'step' => 'articles',
            'status' => $status,
            'apify_run_id' => (string) ($started['run_id'] ?? ''),
            'apify_dataset_id' => (string) ($started['dataset_id'] ?? ''),
            'attempts' => 1,
            'last_error' => empty($started['success']) ? (string) ($started['error'] ?? 'Apify async article run start failed.') : null,
        ]);
        if ($status === 'failed') {
            return $this->handle_failed_job($candidate, $runId, $job, (string) ($started['error'] ?? 'Apify async article run start failed.'));
        }

        return $this->async_item($candidate, 'async_articles_running', $job, 'Started async Apify article lookup.');
    }

    /**
     * @return array<string, mixed>
     */
    private function poll_async_job(array $candidate, string $runId, array $job): array
    {
        if ((string) ($job['status'] ?? '') === 'pending') {
            return $this->async_item($candidate, 'async_' . (string) ($job['step'] ?? 'job') . '_running', $job, 'Apify job is pending.');
        }
        if ((string) ($job['status'] ?? '') === 'failed' && (int) ($job['attempts'] ?? 0) < self::MAX_ASYNC_ATTEMPTS) {
            return $this->handle_failed_job($candidate, $runId, $job, (string) ($job['last_error'] ?? 'Apify job failed before polling.'));
        }

        $run = $this->lookup->get_async_run((string) ($job['apify_run_id'] ?? ''));
        $this->database->update_apify_job((int) $job['id'], ['last_checked_at' => current_time('mysql')]);
        if (empty($run['success'])) {
            return $this->handle_failed_job($candidate, $runId, $job, (string) ($run['error'] ?? 'Apify run status check failed.'));
        }

        $apifyStatus = strtoupper((string) ($run['status'] ?? ''));
        $datasetId = (string) ($run['dataset_id'] ?? ($job['apify_dataset_id'] ?? ''));
        if (in_array($apifyStatus, ['READY', 'RUNNING'], true)) {
            return $this->async_item($candidate, 'async_' . (string) ($job['step'] ?? 'job') . '_running', array_merge($job, ['apify_dataset_id' => $datasetId]), 'Apify job is still running.');
        }
        if ($apifyStatus !== 'SUCCEEDED') {
            return $this->handle_failed_job($candidate, $runId, $job, 'Apify run ended with status ' . $apifyStatus . '.');
        }

        $items = $this->lookup->get_async_dataset_items($datasetId);
        if (empty($items['success'])) {
            return $this->handle_failed_job($candidate, $runId, $job, (string) ($items['error'] ?? 'Apify dataset fetch failed.'));
        }

        $this->database->update_apify_job((int) $job['id'], [
            'status' => 'succeeded',
            'apify_dataset_id' => $datasetId,
            'last_error' => null,
            'last_checked_at' => current_time('mysql'),
        ]);

        if ((string) ($job['step'] ?? '') === 'articles') {
            return $this->process_succeeded_articles_job($candidate, $runId, $items['items']);
        }
        return $this->process_succeeded_vehicles_job($candidate, $runId, $job, $items['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function process_succeeded_articles_job(array $candidate, string $runId, array $items): array
    {
        $articles = ApifyClient::parse_articles($items);
        $raw = (string) ($candidate['part_number_raw'] ?? '');
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        if (!$articles) {
            $result = $this->lookup_result($raw, $normalized, 'not_found', [], [], ['No TecDoc articles found for this OEM/part number.']);
            $partId = $this->database->save_lookup($raw, $result);
            $result['cache_part_cache_id'] = $partId > 0 ? $partId : null;
            $this->database->upsert_product_map($candidate);
            return $this->small_item_from_result($candidate, $result, 'not_found');
        }

        $limitedArticles = $this->select_articles_for_vehicle_lookup($articles, $raw, $normalized);
        foreach ($limitedArticles as $article) {
            $articleNo = (string) ($article['articleNo'] ?? '');
            $supplierId = (int) ($article['supplierId'] ?? 0);
            if ($articleNo === '' || $supplierId <= 0 || $this->database->find_active_apify_job($runId, $normalized, 'vehicles', $articleNo, $supplierId)) {
                continue;
            }
            $started = $this->lookup->start_async_run($this->lookup->compatible_vehicles_payload($articleNo, $supplierId));
            $this->database->create_apify_job([
                'run_id' => $runId,
                'product_id' => (int) ($candidate['product_id'] ?? 0),
                'part_number_raw' => $raw,
                'part_number_normalized' => $normalized,
                'step' => 'vehicles',
                'status' => !empty($started['success']) ? 'running' : 'failed',
                'apify_run_id' => (string) ($started['run_id'] ?? ''),
                'apify_dataset_id' => (string) ($started['dataset_id'] ?? ''),
                'article_no' => $articleNo,
                'supplier_id' => $supplierId,
                'attempts' => 1,
                'last_error' => empty($started['success']) ? (string) ($started['error'] ?? 'Apify async vehicle run start failed.') : null,
            ]);
        }
        foreach (array_slice($articles, count($limitedArticles)) as $article) {
            $articleNo = (string) ($article['articleNo'] ?? '');
            $supplierId = (int) ($article['supplierId'] ?? 0);
            if ($articleNo === '' || $supplierId <= 0) {
                continue;
            }
            $this->database->create_apify_job([
                'run_id' => $runId,
                'product_id' => (int) ($candidate['product_id'] ?? 0),
                'part_number_raw' => $raw,
                'part_number_normalized' => $normalized,
                'step' => 'vehicles',
                'status' => 'skipped_due_to_article_limit',
                'article_no' => $articleNo,
                'supplier_id' => $supplierId,
                'attempts' => 0,
                'last_error' => 'article_limit_applied',
            ]);
        }

        $limitDiagnostics = $this->article_limit_diagnostics(count($articles), count($limitedArticles));
        $message = !empty($limitDiagnostics['article_limit_applied']) ? 'Article lookup succeeded; vehicle jobs queued with article_limit_applied warning.' : 'Article lookup succeeded; vehicle jobs queued.';
        return $this->async_item($candidate, 'async_vehicles_running', $this->database->next_active_apify_job($runId, $normalized) ?: [], $message, count($articles), $limitDiagnostics);
    }

    /**
     * @return array<string, mixed>
     */
    private function process_succeeded_vehicles_job(array $candidate, string $runId, array $job, array $items): array
    {
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        $vehicles = ApifyClient::parse_vehicles($items);
        if ($vehicles) {
            foreach ($vehicles as &$vehicle) {
                $vehicle['_articleNo'] = (string) ($job['article_no'] ?? '');
                $vehicle['_supplierId'] = (int) ($job['supplier_id'] ?? 0);
            }
            unset($vehicle);
        }

        return $this->finalize_async_if_ready($candidate, $runId, 'Vehicle job succeeded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function finalize_async_if_ready(array $candidate, string $runId, string $message): array
    {
        $raw = (string) ($candidate['part_number_raw'] ?? '');
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        $this->enforce_vehicle_article_limit($candidate, $runId);
        $jobs = $this->database->apify_jobs_for_part($runId, $normalized);
        foreach ($jobs as $job) {
            $jobStatus = (string) ($job['status'] ?? '');
            if (in_array($jobStatus, ['pending', 'running'], true) || ($jobStatus === 'failed' && (int) ($job['attempts'] ?? 0) < self::MAX_ASYNC_ATTEMPTS)) {
                return $this->async_item($candidate, 'async_' . (string) ($job['step'] ?? 'job') . '_running', $job, $message);
            }
        }

        $articleJobs = array_values(array_filter($jobs, static fn(array $job): bool => (string) ($job['step'] ?? '') === 'articles' && (string) ($job['status'] ?? '') === 'succeeded'));
        $vehicleJobs = array_values(array_filter($jobs, static fn(array $job): bool => (string) ($job['step'] ?? '') === 'vehicles'));
        $activeVehicleJobs = array_values(array_filter($vehicleJobs, static fn(array $job): bool => (string) ($job['status'] ?? '') !== 'skipped_due_to_article_limit'));
        $failed = array_values(array_filter($jobs, static fn(array $job): bool => in_array((string) ($job['status'] ?? ''), ['failed', 'timed_out'], true)));
        if (!$articleJobs) {
            $error = $failed ? (string) ($failed[0]['last_error'] ?? 'Apify article lookup failed.') : 'No article job succeeded.';
            return $this->save_async_final($candidate, $raw, $normalized, 'error', [], [], [$error]);
        }
        if ($failed) {
            return $this->save_async_final($candidate, $raw, $normalized, 'error', [], [], [(string) ($failed[0]['last_error'] ?? 'Apify vehicle lookup failed.')]);
        }

        $articles = [];
        foreach ($activeVehicleJobs as $vehicleJob) {
            $articles[] = ['articleNo' => (string) ($vehicleJob['article_no'] ?? ''), 'supplierId' => (int) ($vehicleJob['supplier_id'] ?? 0), 'supplierName' => ''];
        }
        $vehicles = [];
        foreach ($activeVehicleJobs as $vehicleJob) {
            if ((string) ($vehicleJob['status'] ?? '') !== 'succeeded') {
                continue;
            }
            $dataset = $this->lookup->get_async_dataset_items((string) ($vehicleJob['apify_dataset_id'] ?? ''));
            if (empty($dataset['success'])) {
                return $this->save_async_final($candidate, $raw, $normalized, 'error', [], [], [(string) ($dataset['error'] ?? 'Apify vehicle dataset fetch failed during finalization.')]);
            }
            foreach (ApifyClient::parse_vehicles($dataset['items']) as $vehicle) {
                $vehicle['_articleNo'] = (string) ($vehicleJob['article_no'] ?? '');
                $vehicle['_supplierId'] = (int) ($vehicleJob['supplier_id'] ?? 0);
                $vehicles[] = $vehicle;
            }
        }
        $status = $vehicles ? 'found' : 'not_found';
        $limitDiagnostics = $this->article_limit_diagnostics(count($vehicleJobs), count($activeVehicleJobs));
        $errors = $vehicles ? [] : ['TecDoc article was found, but compatibleCars was empty.'];
        if (!empty($limitDiagnostics['article_limit_applied'])) {
            $errors[] = 'article_limit_applied: only ' . (string) $limitDiagnostics['articles_used_for_vehicle_lookup'] . ' of ' . (string) $limitDiagnostics['articles_found_total'] . ' TecDoc articles were checked for vehicles.';
        }
        return $this->save_async_final($candidate, $raw, $normalized, $status, $articles, $vehicles, $errors, $limitDiagnostics);
    }


    /**
     * @param array<int, array<string, mixed>> $articles
     * @return array<int, array<string, mixed>>
     */
    private function select_articles_for_vehicle_lookup(array $articles, string $raw, string $normalized): array
    {
        $limit = $this->max_tecdoc_articles_per_part_for_vehicle_lookup();
        $rawNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($raw)) ?: '';
        $normalizedNorm = preg_replace('/[^A-Z0-9]/', '', strtoupper($normalized)) ?: '';
        foreach ($articles as $index => &$article) {
            $searchNo = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($article['articleSearchNo'] ?? $article['articleNo'] ?? ''))) ?: '';
            $article['_gps_original_index'] = $index;
            $article['_gps_exact_match'] = $searchNo !== '' && ($searchNo === $rawNorm || $searchNo === $normalizedNorm) ? 1 : 0;
        }
        unset($article);
        usort($articles, static function (array $left, array $right): int {
            $exact = ((int) ($right['_gps_exact_match'] ?? 0)) <=> ((int) ($left['_gps_exact_match'] ?? 0));
            if ($exact !== 0) {
                return $exact;
            }
            return ((int) ($left['_gps_original_index'] ?? 0)) <=> ((int) ($right['_gps_original_index'] ?? 0));
        });
        return array_slice($articles, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function article_limit_diagnostics(int $total, int $used): array
    {
        $skipped = max(0, $total - $used);
        return [
            'articles_found_total' => $total,
            'articles_used_for_vehicle_lookup' => $used,
            'articles_skipped_due_to_limit' => $skipped,
            'article_limit_applied' => $skipped > 0,
        ];
    }

    private function enforce_vehicle_article_limit(array $candidate, string $runId): void
    {
        $normalized = (string) ($candidate['part_number_normalized'] ?? '');
        $vehicleJobs = array_values(array_filter(
            $this->database->apify_jobs_for_part($runId, $normalized),
            static fn(array $job): bool => (string) ($job['step'] ?? '') === 'vehicles'
        ));
        $limit = $this->max_tecdoc_articles_per_part_for_vehicle_lookup();
        $kept = 0;
        foreach ($vehicleJobs as $job) {
            if ((string) ($job['status'] ?? '') === 'skipped_due_to_article_limit') {
                continue;
            }
            $kept++;
            if ($kept <= $limit) {
                continue;
            }
            if (in_array((string) ($job['status'] ?? ''), ['pending', 'running'], true)) {
                $this->database->update_apify_job((int) $job['id'], [
                    'status' => 'skipped_due_to_article_limit',
                    'last_error' => 'article_limit_applied',
                    'last_checked_at' => current_time('mysql'),
                ]);
            }
        }
    }

    private function max_tecdoc_articles_per_part_for_vehicle_lookup(): int
    {
        $value = (int) ($this->settings->get('max_tecdoc_articles_per_part_for_vehicle_lookup') ?? self::DEFAULT_MAX_TECDOC_ARTICLES_PER_PART_FOR_VEHICLE_LOOKUP);
        return max(1, min(self::MAX_TECDOC_ARTICLES_PER_PART_FOR_VEHICLE_LOOKUP, $value));
    }

    /**
     * @return array<string, mixed>
     */
    private function save_async_final(array $candidate, string $raw, string $normalized, string $status, array $articles, array $vehicles, array $errors, array $diagnostics = []): array
    {
        $result = array_merge($this->lookup_result($raw, $normalized, $status, $articles, $vehicles, $errors), $diagnostics);
        $partId = $this->database->save_lookup($raw, $result);
        $result['cache_part_cache_id'] = $partId > 0 ? $partId : null;
        $this->database->upsert_product_map($candidate);
        return $this->small_item_from_result($candidate, $result, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function handle_failed_job(array $candidate, string $runId, array $job, string $error): array
    {
        $attempts = (int) ($job['attempts'] ?? 0);
        if ($attempts < self::MAX_ASYNC_ATTEMPTS) {
            $payload = (string) ($job['step'] ?? '') === 'vehicles'
                ? $this->lookup->compatible_vehicles_payload((string) ($job['article_no'] ?? ''), (int) ($job['supplier_id'] ?? 0))
                : $this->lookup->article_search_payload((string) ($job['part_number_normalized'] ?? ''));
            $started = $this->lookup->start_async_run($payload);
            $this->database->update_apify_job((int) $job['id'], [
                'status' => !empty($started['success']) ? 'running' : 'failed',
                'apify_run_id' => (string) ($started['run_id'] ?? ''),
                'apify_dataset_id' => (string) ($started['dataset_id'] ?? ''),
                'attempts' => $attempts + 1,
                'last_error' => empty($started['success']) ? (string) ($started['error'] ?? $error) : null,
                'last_checked_at' => current_time('mysql'),
            ]);
            return $this->async_item($candidate, 'async_' . (string) ($job['step'] ?? 'job') . '_running', array_merge($job, ['attempts' => $attempts + 1]), 'Retrying Apify job after error: ' . $error);
        }

        $this->database->update_apify_job((int) $job['id'], ['status' => 'failed', 'last_error' => $error, 'last_checked_at' => current_time('mysql')]);
        return $this->finalize_async_if_ready($candidate, $runId, $error);
    }

    /**
     * @return array<string, mixed>
     */
    private function async_item(array $candidate, string $status, array $job, string $message, int $articlesFound = 0, array $diagnostics = []): array
    {
        $item = $this->small_item($candidate, $status, $articlesFound, 0, 0, $message, null, false);
        $item = array_merge($item, $diagnostics);
        $item['pending_async'] = true;
        $item['active_apify_job_id'] = isset($job['apify_run_id']) ? (string) $job['apify_run_id'] : '';
        $item['active_job_step'] = (string) ($job['step'] ?? '');
        $item['active_job_status'] = (string) ($job['status'] ?? '');
        $jobCounts = $this->vehicle_job_counts($candidate);
        $item['vehicle_jobs_completed'] = $jobCounts['completed'];
        $item['vehicle_jobs_total'] = $jobCounts['total_waited'];
        $item['vehicle_jobs_skipped_due_to_article_limit'] = $jobCounts['skipped'];
        $item['finalized_status'] = '';
        return $item;
    }

    private function vehicle_job_counts(array $candidate): array
    {
        $checkpoint = $this->checkpoint();
        $jobs = $this->database->apify_jobs_for_part((string) ($checkpoint['run_id'] ?? ''), (string) ($candidate['part_number_normalized'] ?? ''));
        $counts = ['completed' => 0, 'total_waited' => 0, 'skipped' => 0];
        foreach ($jobs as $job) {
            if ((string) ($job['step'] ?? '') !== 'vehicles') {
                continue;
            }
            if ((string) ($job['status'] ?? '') === 'skipped_due_to_article_limit') {
                $counts['skipped']++;
                continue;
            }
            $counts['total_waited']++;
            if ((string) ($job['status'] ?? '') === 'succeeded') {
                $counts['completed']++;
            }
        }
        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup_result(string $raw, string $normalized, string $status, array $articles, array $vehicles, array $errors): array
    {
        $vehicleIds = [];
        foreach ($vehicles as $vehicle) {
            if (is_array($vehicle)) {
                $id = (string) ($vehicle['vehicleId'] ?? $vehicle['vehicle_id'] ?? '');
                if ($id !== '') {
                    $vehicleIds[] = $id;
                }
            }
        }
        return [
            'part_number_raw' => $raw,
            'part_number_normalized' => $normalized,
            'status' => $status,
            'articles' => $articles,
            'vehicles' => $vehicles,
            'unique_vehicle_ids' => array_values(array_unique($vehicleIds)),
            'errors' => $errors,
            'from_cache' => false,
            'saved' => false,
            'cache_part_cache_id' => null,
            'force_live' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function small_item_from_result(array $candidate, array $result, string $status): array
    {
        $vehicles = is_array($result['vehicles'] ?? null) ? $result['vehicles'] : [];
        $articles = is_array($result['articles'] ?? null) ? $result['articles'] : [];
        $vehicleIds = [];
        if (is_array($result['unique_vehicle_ids'] ?? null)) {
            $vehicleIds = array_map('strval', $result['unique_vehicle_ids']);
        }
        foreach ($vehicles as $vehicle) {
            if (is_array($vehicle)) {
                $id = (string) ($vehicle['vehicleId'] ?? $vehicle['vehicle_id'] ?? '');
                if ($id !== '') {
                    $vehicleIds[] = $id;
                }
            }
        }
        $errors = is_array($result['errors'] ?? null) ? array_map('strval', $result['errors']) : [];
        $item = $this->small_item(
            $candidate,
            $status,
            count($articles),
            count(array_values(array_unique($vehicleIds))),
            count(array_values(array_unique($vehicleIds))),
            implode("\n", $errors),
            isset($result['cache_part_cache_id']) ? (int) $result['cache_part_cache_id'] : null,
            !empty($result['from_cache'])
        );
        foreach (['articles_found_total', 'articles_used_for_vehicle_lookup', 'articles_skipped_due_to_limit', 'article_limit_applied'] as $key) {
            if (array_key_exists($key, $result)) {
                $item[$key] = $result[$key];
            }
        }
        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function small_item(array $candidate, string $status, int $articleCount, int $vehicleCount, int $ktypeCount, string $error, ?int $partCacheId, bool $fromCache): array
    {
        return [
            'product_id' => isset($candidate['product_id']) ? (int) $candidate['product_id'] : null,
            'part_number_normalized' => (string) ($candidate['part_number_normalized'] ?? ''),
            'status' => $status,
            'ktype_count' => $ktypeCount,
            'article_count' => $articleCount,
            'vehicle_count' => $vehicleCount,
            'error_message' => $error,
            'part_cache_id' => $partCacheId,
            'from_cache' => $fromCache,
        ];
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
        $options['batch_limit'] = 1;
        $options['max_batches'] = max(0, (int) ($checkpoint['max_batches'] ?? $options['max_batches']));
        $options['max_apify_lookups_per_batch'] = 1;
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
                'batch_limit' => 1,
                'max_batches' => (int) $options['max_batches'],
                'max_apify_lookups_per_batch' => 1,
                'total_batches_completed' => 0,
                'aggregate_counters' => $this->empty_counters(),
                'batch_csv_urls' => [],
                'final_summary_csv_url' => '',
                'final_audit_csv_url' => '',
                'found_only_csv_url' => '',
                'last_error' => '',
                'previous_run_id' => (string) ($existing['run_id'] ?? ''),
            ];
        }
        $checkpoint['batch_limit'] = 1;
        $checkpoint['max_batches'] = (int) $options['max_batches'];
        $checkpoint['max_apify_lookups_per_batch'] = 1;
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
     * @return array<string, mixed>
     */
    private function checkpoint_after_successful_item(array $checkpoint, int $offset, int $nextOffset, string $status, array $batchCounters, string $stoppedReason, array $timing, array $item): array
    {
        $checkpoint = $this->normalize_checkpoint($checkpoint);
        $aggregate = is_array($checkpoint['aggregate_counters'] ?? null) ? $checkpoint['aggregate_counters'] : $this->empty_counters();
        foreach (self::COUNTER_KEYS as $key) {
            $aggregate[$key] = (int) ($aggregate[$key] ?? 0) + (int) ($batchCounters[$key] ?? 0);
        }

        $checkpoint['status'] = $status;
        $checkpoint['current_offset'] = $offset;
        $checkpoint['next_offset'] = $nextOffset;
        if (empty($item['pending_async'])) {
            $checkpoint['last_completed_offset'] = $offset;
            $checkpoint['last_successful_offset'] = $offset;
        }
        $checkpoint['batch_limit'] = 1;
        $checkpoint['total_batches_completed'] = (int) ($checkpoint['total_batches_completed'] ?? 0) + 1;
        $checkpoint['last_successful_batch_number'] = (int) $checkpoint['total_batches_completed'];
        $checkpoint['aggregate_counters'] = $aggregate;
        $checkpoint['last_batch_finished_at'] = gmdate('c');
        $checkpoint['last_progress_at'] = $checkpoint['last_batch_finished_at'];
        $checkpoint['in_flight_since'] = '';
        $checkpoint['last_request_duration_seconds'] = (float) ($timing['request_duration_seconds'] ?? $timing['total_batch_duration_seconds'] ?? 0.0);
        $checkpoint['last_timing'] = $timing;
        $checkpoint['stopped_reason'] = $stoppedReason;
        $checkpoint['last_error'] = $stoppedReason === 'stop_on_first_error' ? 'Stopped after first item with errors.' : '';
        $checkpoint['last_processed_product_id'] = $item['product_id'];
        $checkpoint['last_processed_part_number'] = (string) ($item['part_number_normalized'] ?? '');
        $checkpoint['last_processed_status'] = (string) ($item['status'] ?? '');
        $checkpoint['last_processed_item'] = $item;

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
            'batch_limit' => 1,
            'max_batches' => (int) ($checkpoint['max_batches'] ?? 0),
            'max_apify_lookups_per_batch' => 1,
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
            'deferred_due_to_lookup_cap' => (int) ($aggregate['deferred_due_to_lookup_cap'] ?? 0),
            'transient_retry_count' => (int) ($fallback['transient_retry_count'] ?? 0),
            'last_http_error' => (string) ($fallback['last_http_error'] ?? ''),
            'retry_delay_seconds' => (int) ($fallback['retry_delay_seconds'] ?? 0),
            'csv_files_count' => 0,
            'batch_csv_urls' => [],
            'previous_run_id' => (string) ($checkpoint['previous_run_id'] ?? ''),
            'last_batch_started_at' => (string) ($checkpoint['last_batch_started_at'] ?? ''),
            'last_batch_finished_at' => (string) ($checkpoint['last_batch_finished_at'] ?? ''),
            'last_progress_at' => (string) ($checkpoint['last_progress_at'] ?? ''),
            'last_successful_batch_number' => (int) ($checkpoint['last_successful_batch_number'] ?? 0),
            'last_successful_offset' => (int) ($checkpoint['last_successful_offset'] ?? -1),
            'last_request_duration_seconds' => (float) ($checkpoint['last_request_duration_seconds'] ?? 0.0),
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
        $checkpoint['batch_limit'] = 1;
        $checkpoint['max_batches'] = max(0, (int) ($checkpoint['max_batches'] ?? 0));
        $checkpoint['total_batches_completed'] = max(0, (int) ($checkpoint['total_batches_completed'] ?? 0));
        $checkpoint['max_apify_lookups_per_batch'] = 1;
        $checkpoint['aggregate_counters'] = $aggregate;
        $checkpoint['batch_csv_urls'] = [];
        $checkpoint['final_summary_csv_url'] = (string) ($checkpoint['final_summary_csv_url'] ?? '');
        $checkpoint['final_audit_csv_url'] = (string) ($checkpoint['final_audit_csv_url'] ?? '');
        $checkpoint['found_only_csv_url'] = (string) ($checkpoint['found_only_csv_url'] ?? '');
        $checkpoint['last_error'] = (string) ($checkpoint['last_error'] ?? '');
        $checkpoint['started_at'] = (string) ($checkpoint['started_at'] ?? gmdate('c'));
        $checkpoint['updated_at'] = (string) ($checkpoint['updated_at'] ?? gmdate('c'));
        $checkpoint['previous_run_id'] = self::safe_run_id((string) ($checkpoint['previous_run_id'] ?? ''));
        $checkpoint['last_batch_started_at'] = (string) ($checkpoint['last_batch_started_at'] ?? '');
        $checkpoint['last_batch_finished_at'] = (string) ($checkpoint['last_batch_finished_at'] ?? '');
        $checkpoint['last_progress_at'] = (string) ($checkpoint['last_progress_at'] ?? '');
        $checkpoint['in_flight_since'] = (string) ($checkpoint['in_flight_since'] ?? '');
        $checkpoint['last_successful_batch_number'] = (int) ($checkpoint['last_successful_batch_number'] ?? 0);
        $checkpoint['last_successful_offset'] = (int) ($checkpoint['last_successful_offset'] ?? -1);
        $checkpoint['stopped_reason'] = (string) ($checkpoint['stopped_reason'] ?? '');
        $checkpoint['last_timing'] = is_array($checkpoint['last_timing'] ?? null) ? $checkpoint['last_timing'] : [];
        $checkpoint['last_request_duration_seconds'] = (float) ($checkpoint['last_request_duration_seconds'] ?? 0.0);
        $checkpoint['last_processed_product_id'] = $checkpoint['last_processed_product_id'] ?? null;
        $checkpoint['last_processed_part_number'] = (string) ($checkpoint['last_processed_part_number'] ?? '');
        $checkpoint['last_processed_status'] = (string) ($checkpoint['last_processed_status'] ?? '');
        $checkpoint['last_processed_item'] = is_array($checkpoint['last_processed_item'] ?? null) ? $checkpoint['last_processed_item'] : [];
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
    private function batch_counters(array $scan, array $item): array
    {
        $status = (string) ($item['status'] ?? '');
        return [
            'total_scanned_products' => (int) ($scan['total_scanned_products'] ?? 0),
            'products_with_raw_part_number' => (int) ($scan['products_with_raw_part_number'] ?? 0),
            'accepted_products' => (int) ($scan['accepted_products'] ?? 0),
            'rejected_products' => (int) ($scan['rejected_products'] ?? 0),
            'skipped_cached' => $status === 'skipped_cached' ? 1 : 0,
            'apify_lookup_attempted' => (!empty($item['product_id']) && !$item['from_cache'] && in_array($status, ['found', 'not_found', 'error'], true)) ? 1 : 0,
            'found' => ($status === 'found' || ($status === 'skipped_cached' && (int) ($item['ktype_count'] ?? 0) > 0)) ? 1 : 0,
            'not_found' => ($status === 'not_found' || ($status === 'skipped_cached' && (int) ($item['ktype_count'] ?? 0) === 0 && (int) ($item['article_count'] ?? 0) === 0)) ? 1 : 0,
            'errors' => $status === 'error' ? 1 : 0,
            'rejected_before_lookup' => $status === 'rejected' ? 1 : 0,
            'deferred_due_to_lookup_cap' => 0,
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
            'cache_part_cache_id' => isset($cached['part_cache']['id']) ? (int) $cached['part_cache']['id'] : null,
            'force_live' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function small_checkpoint(array $checkpoint): array
    {
        $checkpoint = $this->normalize_checkpoint($checkpoint);
        unset($checkpoint['last_processed_item']['articles'], $checkpoint['last_processed_item']['vehicles'], $checkpoint['last_processed_item']['unique_vehicle_ids']);
        return $checkpoint;
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
