<?php

namespace GPSwiss\Ovoko\Services;

class OvokoBidirectionalSyncOrchestrator
{
    public const STATUS_OPTION = 'gpswiss_ovoko_bidirectional_sync_status';
    public const RECENT_RUNS_OPTION = 'gpswiss_ovoko_bidirectional_sync_recent_runs';
    public const ENABLED_OPTION = 'ovoko_bidirectional_sync_enabled';
    public const LOCK_TRANSIENT = 'gpswiss_ovoko_bidirectional_sync_lock';
    public const CRON_HOOK = 'gpswiss_ovoko_bidirectional_sync';
    public const CRON_SCHEDULE = 'gpswiss_ovoko_every_15_minutes';
    public const DELTA_FILTER = 'date_from';
    private const DEFAULT_BATCH_LIMIT = 5;
    private const WATERMARK_OVERLAP_DAYS = 1;
    private const LOCK_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;
    private const RECENT_RUNS_LIMIT = 20;

    private OvokoIntegrationService $integrationService;
    private OvokoWooSaleSyncQueue $saleQueue;

    public function __construct(OvokoIntegrationService $integrationService, ?OvokoWooSaleSyncQueue $saleQueue = null)
    {
        $this->integrationService = $integrationService;
        $this->saleQueue = $saleQueue ?: new OvokoWooSaleSyncQueue($integrationService);
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'register_cron_schedule']);
        add_action('init', [$this, 'ensure_schedule_state']);
        add_action(self::CRON_HOOK, [$this, 'run_cron_guarded']);
    }

    public function register_cron_schedule(array $schedules): array
    {
        $schedules[self::CRON_SCHEDULE] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => 'Every 15 minutes (GPSwiss Ovoko ↔ Woo)',
        ];

        return $schedules;
    }

    public function ensure_schedule_state(): void
    {
        if ($this->auto_cron_enabled()) {
            $this->schedule_cron_if_needed();
            return;
        }

        if (wp_next_scheduled(self::CRON_HOOK)) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    public function dashboard_status(): array
    {
        $stored = get_option(self::STATUS_OPTION, []);
        $status = wp_parse_args(is_array($stored) ? $stored : [], $this->default_status());
        $legacyOvokoToWoo = (array) get_option('gpswiss_ovoko_auto_sync_status', []);
        $saleCounts = $this->saleQueue->status_counts();
        $next = wp_next_scheduled(self::CRON_HOOK);

        $status['sync_enabled'] = (bool) get_option(self::ENABLED_OPTION, false);
        if (!$status['sync_enabled'] && !in_array((string) ($status['status'] ?? ''), ['running', 'error'], true)) {
            $status['status'] = 'paused';
        }
        $status['delta_filter_used'] = self::DELTA_FILTER;
        $status['stored_watermark_date'] = (string) ($status['stored_watermark_date'] ?: ($legacyOvokoToWoo['stored_watermark_date'] ?? $legacyOvokoToWoo['last_successful_sync_date'] ?? ''));
        $status['date_from_used'] = (string) ($status['date_from_used'] ?: ($legacyOvokoToWoo['date_from_used'] ?? $legacyOvokoToWoo['date_from'] ?? ''));
        $status['last_successful_sync_at'] = (string) ($status['last_successful_sync_at'] ?: ($legacyOvokoToWoo['last_successful_sync_at'] ?? ''));
        $status['last_successful_sync_date'] = (string) ($status['last_successful_sync_date'] ?: ($legacyOvokoToWoo['last_successful_sync_date'] ?? ''));
        $status['current_page'] = (int) ($status['current_page'] ?: (($legacyOvokoToWoo['last_cursor']['next_page'] ?? 1)));
        $status['current_cursor'] = is_array($status['current_cursor']) ? $status['current_cursor'] : [];
        $status['processed_from_ovoko'] = (int) ($status['processed_from_ovoko'] ?: ($legacyOvokoToWoo['processed'] ?? 0));
        $status['processed_total'] = (int) $status['processed_from_ovoko'];
        $status['created_from_ovoko'] = (int) ($status['created_from_ovoko'] ?: ($legacyOvokoToWoo['created_from_delta'] ?? $legacyOvokoToWoo['created'] ?? 0));
        $status['updated_from_ovoko'] = (int) ($status['updated_from_ovoko'] ?: ($legacyOvokoToWoo['updated_from_delta'] ?? $legacyOvokoToWoo['updated'] ?? 0));
        $status['skipped_from_ovoko'] = (int) ($status['skipped_from_ovoko'] ?: ($legacyOvokoToWoo['skipped'] ?? 0));
        $status['skipped_missing_price'] = (int) ($status['skipped_missing_price'] ?: ($legacyOvokoToWoo['skipped_missing_price'] ?? 0));
        $status['skipped_already_synced'] = (int) ($status['skipped_already_synced'] ?: ($legacyOvokoToWoo['skipped_already_synced'] ?? 0));
        $status['pending_woo_to_ovoko_sales'] = (int) $saleCounts['pending'];
        $status['successful_woo_to_ovoko_sales'] = (int) $saleCounts['success'];
        $status['failed_woo_to_ovoko_sales'] = (int) $saleCounts['failed'];
        $status['lock_status'] = get_transient(self::LOCK_TRANSIENT) ? 'locked' : 'unlocked';
        $status['lock_started_at'] = (string) (get_transient(self::LOCK_TRANSIENT) ?: '');
        $status['next_scheduled_sync_at'] = $next ? gmdate('c', (int) $next) : 'not scheduled';

        return $status;
    }

    public function recent_runs(): array
    {
        $runs = get_option(self::RECENT_RUNS_OPTION, []);
        if (!is_array($runs)) {
            return [];
        }

        return array_values(array_filter($runs, 'is_array'));
    }

    public function clear_recent_runs(): void
    {
        delete_option(self::RECENT_RUNS_OPTION);
    }

    public function request_enable_automatic_sync(): array
    {
        update_option(self::ENABLED_OPTION, true, false);
        $scheduled = $this->schedule_cron_if_needed();
        update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'idle', 'last_error' => '', 'last_warning' => '']), false);

        return ['ok' => true, 'action_name' => 'Enable auto cron', 'sync_enabled' => true, 'cron_scheduled' => $scheduled, 'schedule' => self::CRON_SCHEDULE];
    }

    public function pause_sync(): array
    {
        update_option(self::ENABLED_OPTION, false, false);
        update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'paused']), false);
        wp_clear_scheduled_hook(self::CRON_HOOK);

        return ['ok' => true, 'action_name' => 'Disable auto cron', 'sync_enabled' => false, 'cron_scheduled' => false, 'next_scheduled_sync_at' => 'not scheduled'];
    }

    public function run_now(): array
    {
        return $this->run_cron_guarded('manual');
    }

    public function retry_failed_sales(): array
    {
        return $this->saleQueue->process_queue(['retry_failed_only' => true, 'force' => true]);
    }

    public function run_cron_guarded(string $trigger = 'cron'): array
    {
        $startedAt = gmdate('c');
        $startedAtFloat = microtime(true);

        if ($trigger === 'cron' && !$this->auto_cron_enabled()) {
            $finishedAt = gmdate('c');
            $status = array_merge($this->dashboard_status(), [
                'status' => 'paused',
                'last_warning' => 'Auto cron is disabled in the plugin panel.',
            ]);
            update_option(self::STATUS_OPTION, $status, false);
            $this->append_recent_run($this->build_recent_run_entry($startedAt, $finishedAt, $startedAtFloat, $trigger, 'skipped_disabled', [], [], 'auto cron disabled.'));
            return ['ok' => false, 'status' => 'skipped_disabled', 'message' => 'Auto cron is disabled.', 'trigger' => $trigger];
        }

        if (get_transient(self::LOCK_TRANSIENT)) {
            $finishedAt = gmdate('c');
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'running', 'last_warning' => 'Skipped overlapping run because lock is active.']), false);
            $this->append_recent_run($this->build_recent_run_entry($startedAt, $finishedAt, $startedAtFloat, $trigger, 'skipped_locked', [], [], 'existing lock, run skipped.'));
            return ['ok' => false, 'status' => 'skipped_locked', 'message' => 'Sync lock is already active.', 'trigger' => $trigger];
        }

        set_transient(self::LOCK_TRANSIENT, $startedAt, self::LOCK_TTL_SECONDS);
        try {
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'running', 'last_error' => '', 'last_warning' => '', 'lock_started_at' => $startedAt]), false);

            $ovokoResult = $this->run_ovoko_to_woo_delta();
            $saleResult = $this->saleQueue->process_queue(['retry_failed_only' => false, 'force' => $trigger !== 'cron']);
            $ok = !empty($ovokoResult['ok']) && !empty($saleResult['ok']);
            $now = gmdate('c');
            $status = array_merge($this->dashboard_status(), [
                'status' => $ok ? 'idle' : 'error',
                'last_attempted_sync_at' => $now,
                'last_successful_sync_at' => $ok ? $now : (string) ($this->dashboard_status()['last_successful_sync_at'] ?? ''),
                'last_error' => $ok ? '' : $this->first_error_message([$ovokoResult, $saleResult]),
                'last_warning' => $this->first_warning_message([$ovokoResult, $saleResult]),
            ]);
            update_option(self::STATUS_OPTION, $status, false);
            $this->append_recent_run($this->build_recent_run_entry($startedAt, $now, $startedAtFloat, $trigger, $ok ? 'success' : 'error', $ovokoResult, $saleResult, (string) $status['last_warning'], (string) $status['last_error']));

            return ['ok' => $ok, 'status' => $status['status'], 'trigger' => $trigger, 'sequence' => ['ovoko_to_woo_date_from_delta' => $ovokoResult, 'woo_to_ovoko_sale_queue' => $saleResult]];
        } catch (\Throwable $e) {
            $finishedAt = gmdate('c');
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'error', 'last_error' => $e->getMessage(), 'last_attempted_sync_at' => $finishedAt]), false);
            $this->append_recent_run($this->build_recent_run_entry($startedAt, $finishedAt, $startedAtFloat, $trigger, 'error', [], [], '', $e->getMessage()));
            return ['ok' => false, 'status' => 'error', 'trigger' => $trigger, 'message' => $e->getMessage()];
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public function architecture_plan(): array
    {
        return [
            'orchestrator' => self::CRON_HOOK,
            'schedule' => self::CRON_SCHEDULE,
            'sequence' => [
                '1_ovoko_to_woo' => [
                    'endpoint' => 'event/status-change endpoint selected by read-only Ovoko/RRR probe; current safe candidate remains /v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD until probe proves a better source',
                    'allowed_delta_filter' => 'Ovoko/RRR changed/sold/status-event list by date; date_from is only a candidate/fallback source',
                    'forbidden_filters' => ['Woo product batch reconciliation as primary cron logic', 'full page scan without an Ovoko event/date filter'],
                    'existing_products' => ['stock/status sync', 'description sync', 'details sync', 'categories verify-only', 'price untouched', 'images untouched'],
                    'new_products' => ['require valid internal_notes price', 'price from internal_notes only', 'categories from category_id + /get/categories/tree', 'images may import', 'missing price skip'],
                    'idempotency_meta' => ['_gpswiss_ovoko_last_synced_updated_at', '_gpswiss_ovoko_last_synced_hash'],
                ],
                '2_woo_to_ovoko' => $this->saleQueue->design_summary(),
            ],
            'auto_cron_enabled_now' => $this->auto_cron_enabled(),
        ];
    }

    private function run_ovoko_to_woo_delta(): array
    {
        $status = $this->dashboard_status();
        $dateWindow = $this->build_date_from_window($status);
        $dateFrom = (string) $dateWindow['date_from'];
        $page = $this->resolve_page_for_window($status, $dateFrom);
        $limit = self::DEFAULT_BATCH_LIMIT;
        $runner = new OvokoAutoSyncDryRunService($this->integrationService);
        $result = $runner->manual_live_date_from_sync([
            'confirmation' => 'RUN OVOKO DATE_FROM LIVE SYNC',
            'date_from' => $dateFrom,
            'page' => $page,
            'batch_size' => $limit,
        ]);

        $processed = (int) ($result['processed'] ?? 0);
        $created = (int) ($result['created'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $skippedMissingPrice = (int) ($result['skipped_missing_price'] ?? 0);
        $skippedAlreadySynced = (int) ($result['skipped_already_synced'] ?? 0);
        $skipped = $skippedMissingPrice + $skippedAlreadySynced + max(0, $processed - $created - $updated - $skippedMissingPrice - $skippedAlreadySynced);
        $pagination = (array) ($result['source_fetch']['pagination'] ?? []);
        $totalCount = isset($pagination['total_count']) ? (int) $pagination['total_count'] : 0;
        $hasMorePages = $totalCount > 0 ? ($page * $limit) < $totalCount : $processed >= $limit;
        $nextPage = $hasMorePages ? $page + 1 : 1;
        $now = gmdate('c');
        $runDate = gmdate('Y-m-d');
        $stored = $this->dashboard_status();
        $errors = (array) ($result['errors'] ?? []);
        $shouldAdvanceWatermark = !empty($result['ok']) && !$hasMorePages && $errors === [];
        $watermarkUpdatedTo = $shouldAdvanceWatermark ? $runDate : (string) ($stored['stored_watermark_date'] ?? '');
        $watermarkUpdateReason = $shouldAdvanceWatermark
            ? 'advanced_after_full_successful_run'
            : (!empty($result['ok']) ? ($hasMorePages ? 'not_advanced_more_pages_remaining' : 'not_advanced_errors_present') : 'not_advanced_run_failed');
        update_option(self::STATUS_OPTION, array_merge($stored, [
            'previous_watermark_date' => (string) ($dateWindow['previous_watermark_date'] ?? ''),
            'stored_watermark_date_before' => (string) ($dateWindow['stored_watermark_date_before'] ?? ''),
            'stored_watermark_date' => $watermarkUpdatedTo,
            'computed_effective_date_from' => $dateFrom,
            'overlap_days_applied' => (int) ($dateWindow['overlap_days_applied'] ?? 0),
            'date_from_used' => $dateFrom,
            'date_from_source' => (string) ($dateWindow['source'] ?? ''),
            'should_advance_watermark' => $shouldAdvanceWatermark,
            'watermark_updated_to' => $watermarkUpdatedTo,
            'watermark_update_reason' => $watermarkUpdateReason,
            'current_page' => $nextPage,
            'current_cursor' => ['stored_watermark_date' => $watermarkUpdatedTo, 'effective_date_from_used' => $dateFrom, 'date_from' => $dateFrom, 'page' => $page, 'next_page' => $nextPage, 'limit' => $limit, 'scope' => 'ovoko_changed_parts_date_window_only'],
            'processed_from_ovoko' => $processed,
            'processed_total' => $processed,
            'created_from_ovoko' => $created,
            'updated_from_ovoko' => $updated,
            'skipped_from_ovoko' => $skipped,
            'skipped_missing_price' => $skippedMissingPrice,
            'skipped_already_synced' => $skippedAlreadySynced,
            'last_successful_sync_date' => $shouldAdvanceWatermark ? $runDate : (string) ($stored['last_successful_sync_date'] ?? ''),
            'last_ovoko_to_woo_sync_at' => $now,
        ]), false);

        return $result + [
            'previous_watermark_date' => (string) ($dateWindow['previous_watermark_date'] ?? ''),
            'stored_watermark_date_before' => (string) ($dateWindow['stored_watermark_date_before'] ?? ''),
            'stored_watermark_date' => $watermarkUpdatedTo,
            'computed_effective_date_from' => $dateFrom,
            'overlap_days_applied' => (int) ($dateWindow['overlap_days_applied'] ?? 0),
            'date_from_used' => $dateFrom,
            'should_advance_watermark' => $shouldAdvanceWatermark,
            'watermark_updated_to' => $watermarkUpdatedTo,
            'watermark_update_reason' => $watermarkUpdateReason,
            'page' => $page,
            'next_page' => $nextPage,
            'has_more_pages' => $hasMorePages,
        ];
    }

    private function build_date_from_window(array $status): array
    {
        $settings = $this->integrationService->get_settings();
        $configuredStart = trim((string) ($settings['ovoko_bidirectional_sync_start_date'] ?? ''));
        $storedWatermark = trim((string) ($status['stored_watermark_date'] ?? ''));
        $legacyLast = trim((string) ($status['last_successful_sync_date'] ?? ''));
        $previousWatermark = preg_match('/^\d{4}-\d{2}-\d{2}$/', $storedWatermark) ? $storedWatermark : $legacyLast;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $previousWatermark)) {
            $timestamp = strtotime($previousWatermark . ' 00:00:00 UTC') ?: time();
            return [
                'date_from' => gmdate('Y-m-d', $timestamp - (self::WATERMARK_OVERLAP_DAYS * DAY_IN_SECONDS)),
                'source' => 'stored_watermark_date_minus_overlap',
                'previous_watermark_date' => $previousWatermark,
                'stored_watermark_date_before' => $storedWatermark,
                'overlap_days_applied' => self::WATERMARK_OVERLAP_DAYS,
            ];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $configuredStart)) {
            return [
                'date_from' => $configuredStart,
                'source' => 'setting_ovoko_bidirectional_sync_start_date',
                'previous_watermark_date' => '',
                'stored_watermark_date_before' => $storedWatermark,
                'overlap_days_applied' => 0,
            ];
        }

        return [
            'date_from' => gmdate('Y-m-d'),
            'source' => 'fallback_today',
            'previous_watermark_date' => '',
            'stored_watermark_date_before' => $storedWatermark,
            'overlap_days_applied' => 0,
        ];
    }

    private function resolve_page_for_window(array $status, string $dateFrom): int
    {
        $cursor = is_array($status['current_cursor'] ?? null) ? $status['current_cursor'] : [];
        if ((string) ($cursor['date_from'] ?? '') === $dateFrom) {
            return max(1, (int) ($status['current_page'] ?? $cursor['next_page'] ?? 1));
        }

        return 1;
    }

    private function default_status(): array
    {
        return [
            'sync_enabled' => false,
            'status' => 'idle',
            'last_successful_sync_at' => '',
            'last_successful_sync_date' => '',
            'last_attempted_sync_at' => '',
            'next_scheduled_sync_at' => '',
            'previous_watermark_date' => '',
            'stored_watermark_date_before' => '',
            'stored_watermark_date' => '',
            'computed_effective_date_from' => '',
            'overlap_days_applied' => 0,
            'should_advance_watermark' => false,
            'watermark_updated_to' => '',
            'watermark_update_reason' => '',
            'delta_filter_used' => self::DELTA_FILTER,
            'date_from_used' => '',
            'date_from_source' => '',
            'current_page' => 1,
            'current_cursor' => [],
            'processed_from_ovoko' => 0,
            'processed_total' => 0,
            'created_from_ovoko' => 0,
            'updated_from_ovoko' => 0,
            'skipped_from_ovoko' => 0,
            'skipped_missing_price' => 0,
            'skipped_already_synced' => 0,
            'pending_woo_to_ovoko_sales' => 0,
            'successful_woo_to_ovoko_sales' => 0,
            'failed_woo_to_ovoko_sales' => 0,
            'last_error' => '',
            'last_warning' => '',
            'lock_status' => 'unlocked',
            'lock_started_at' => '',
        ];
    }

    private function append_recent_run(array $entry): void
    {
        $runs = $this->recent_runs();
        array_unshift($runs, $entry);
        $runs = array_slice($runs, 0, self::RECENT_RUNS_LIMIT);
        update_option(self::RECENT_RUNS_OPTION, $runs, false);
    }

    private function build_recent_run_entry(string $startedAt, string $finishedAt, float $startedAtFloat, string $trigger, string $status, array $ovokoResult = [], array $saleResult = [], string $lastWarning = '', string $lastError = ''): array
    {
        $ovokoErrors = (array) ($ovokoResult['errors'] ?? []);
        $ovokoWarnings = (array) ($ovokoResult['warnings'] ?? []);
        $page = (int) ($ovokoResult['page'] ?? 0);
        $nextPage = (int) ($ovokoResult['next_page'] ?? 0);

        return [
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => round(max(0, microtime(true) - $startedAtFloat), 3),
            'trigger' => $trigger === 'manual' ? 'manual' : 'cron',
            'status' => $status,
            'previous_watermark_date' => (string) ($ovokoResult['previous_watermark_date'] ?? ''),
            'stored_watermark_date_before' => (string) ($ovokoResult['stored_watermark_date_before'] ?? ''),
            'stored_watermark_date' => (string) ($ovokoResult['stored_watermark_date'] ?? ''),
            'computed_effective_date_from' => (string) ($ovokoResult['computed_effective_date_from'] ?? ''),
            'overlap_days_applied' => (int) ($ovokoResult['overlap_days_applied'] ?? 0),
            'date_from_used' => (string) ($ovokoResult['date_from_used'] ?? $ovokoResult['date_from'] ?? ''),
            'should_advance_watermark' => isset($ovokoResult['should_advance_watermark']) ? (bool) $ovokoResult['should_advance_watermark'] : null,
            'watermark_updated_to' => (string) ($ovokoResult['watermark_updated_to'] ?? ''),
            'watermark_update_reason' => (string) ($ovokoResult['watermark_update_reason'] ?? ''),
            'page' => $page > 0 ? $page : null,
            'next_page' => $nextPage > 0 ? $nextPage : null,
            'has_more_pages' => isset($ovokoResult['has_more_pages']) ? (bool) $ovokoResult['has_more_pages'] : null,
            'ovoko_to_woo' => [
                'processed' => (int) ($ovokoResult['processed'] ?? 0),
                'created' => (int) ($ovokoResult['created'] ?? 0),
                'updated' => (int) ($ovokoResult['updated'] ?? 0),
                'skipped_missing_price' => (int) ($ovokoResult['skipped_missing_price'] ?? 0),
                'skipped_already_synced' => (int) ($ovokoResult['skipped_already_synced'] ?? 0),
                'errors_count' => count($ovokoErrors),
                'warnings_count' => count($ovokoWarnings),
            ],
            'woo_to_ovoko' => [
                'processed' => (int) ($saleResult['processed'] ?? 0),
                'success' => (int) ($saleResult['success'] ?? 0),
                'failed' => (int) ($saleResult['failed'] ?? 0),
                'skipped' => (int) ($saleResult['skipped'] ?? 0),
            ],
            'last_error' => $lastError,
            'last_warning' => $lastWarning,
            'raw' => [
                'ovoko_to_woo_date_from_delta' => $ovokoResult,
                'woo_to_ovoko_sale_queue' => $saleResult,
            ],
        ];
    }

    private function first_warning_message(array $results): string
    {
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            if (!empty($result['warning'])) {
                return (string) $result['warning'];
            }
            $warnings = (array) ($result['warnings'] ?? []);
            foreach ($warnings as $key => $warning) {
                if (is_scalar($warning)) {
                    return is_string($key) ? $key : (string) $warning;
                }
                if (is_array($warning)) {
                    return (string) ($warning['message'] ?? $warning['code'] ?? 'sync_warning');
                }
            }
        }

        return '';
    }

    private function schedule_cron_if_needed(): bool
    {
        if (wp_next_scheduled(self::CRON_HOOK)) {
            return true;
        }

        return (bool) wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    private function auto_cron_enabled(): bool
    {
        return (bool) get_option(self::ENABLED_OPTION, false);
    }

    private function first_error_message(array $results): string
    {
        foreach ($results as $result) {
            if (!is_array($result) || !empty($result['ok'])) {
                continue;
            }
            if (!empty($result['message'])) {
                return (string) $result['message'];
            }
            $errors = (array) ($result['errors'] ?? []);
            $first = reset($errors);
            if (is_array($first)) {
                return (string) (($first['message'] ?? '') ?: ($first['code'] ?? 'sync_failed'));
            }
            if (is_scalar($first)) {
                return (string) $first;
            }
        }

        return 'sync_failed';
    }
}
