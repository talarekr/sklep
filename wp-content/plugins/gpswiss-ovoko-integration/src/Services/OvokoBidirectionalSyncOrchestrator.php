<?php

namespace GPSwiss\Ovoko\Services;

class OvokoBidirectionalSyncOrchestrator
{
    public const STATUS_OPTION = 'gpswiss_ovoko_bidirectional_sync_status';
    public const ENABLED_OPTION = 'ovoko_bidirectional_sync_enabled';
    public const LOCK_TRANSIENT = 'gpswiss_ovoko_bidirectional_sync_lock';
    public const CRON_HOOK = 'gpswiss_ovoko_bidirectional_sync';
    public const CRON_SCHEDULE = 'gpswiss_ovoko_every_15_minutes';
    public const DELTA_FILTER = 'date_from';
    private const DEFAULT_BATCH_LIMIT = 5;
    private const LOCK_TTL_SECONDS = 30 * MINUTE_IN_SECONDS;

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
        if ($trigger === 'cron' && !$this->auto_cron_enabled()) {
            $status = array_merge($this->dashboard_status(), [
                'status' => 'paused',
                'last_warning' => 'Auto cron is disabled in the plugin panel.',
            ]);
            update_option(self::STATUS_OPTION, $status, false);
            return ['ok' => false, 'status' => 'paused', 'message' => 'Auto cron is disabled.', 'trigger' => $trigger];
        }

        if (get_transient(self::LOCK_TRANSIENT)) {
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'running', 'last_warning' => 'Skipped overlapping run because lock is active.']), false);
            return ['ok' => false, 'status' => 'running', 'message' => 'Sync lock is already active.', 'trigger' => $trigger];
        }

        set_transient(self::LOCK_TRANSIENT, gmdate('c'), self::LOCK_TTL_SECONDS);
        $startedAt = gmdate('c');
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
                'last_warning' => (string) (($ovokoResult['warning'] ?? '') ?: ($saleResult['warning'] ?? '')),
            ]);
            update_option(self::STATUS_OPTION, $status, false);

            return ['ok' => $ok, 'status' => $status['status'], 'trigger' => $trigger, 'sequence' => ['ovoko_to_woo_date_from_delta' => $ovokoResult, 'woo_to_ovoko_sale_queue' => $saleResult]];
        } catch (\Throwable $e) {
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'error', 'last_error' => $e->getMessage(), 'last_attempted_sync_at' => gmdate('c')]), false);
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
                    'endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
                    'allowed_delta_filter' => 'date_from YYYY-MM-DD only',
                    'forbidden_filters' => ['updated_from', 'updated_after', 'from', 'timestamps', 'ISO datetime', 'full page scan without date_from'],
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
        $stored = $this->dashboard_status();
        update_option(self::STATUS_OPTION, array_merge($stored, [
            'date_from_used' => $dateFrom,
            'date_from_source' => (string) ($dateWindow['source'] ?? ''),
            'current_page' => $nextPage,
            'current_cursor' => ['date_from' => $dateFrom, 'page' => $page, 'next_page' => $nextPage, 'limit' => $limit, 'scope' => 'date_from_delta_window_only'],
            'processed_from_ovoko' => $processed,
            'processed_total' => $processed,
            'created_from_ovoko' => $created,
            'updated_from_ovoko' => $updated,
            'skipped_from_ovoko' => $skipped,
            'skipped_missing_price' => $skippedMissingPrice,
            'skipped_already_synced' => $skippedAlreadySynced,
            'last_successful_sync_date' => !empty($result['ok']) && !$hasMorePages ? gmdate('Y-m-d') : (string) ($stored['last_successful_sync_date'] ?? ''),
            'last_ovoko_to_woo_sync_at' => $now,
        ]), false);

        return $result + ['date_from_used' => $dateFrom, 'page' => $page, 'next_page' => $nextPage, 'has_more_pages' => $hasMorePages];
    }

    private function build_date_from_window(array $status): array
    {
        $settings = $this->integrationService->get_settings();
        $configuredStart = trim((string) ($settings['ovoko_bidirectional_sync_start_date'] ?? ''));
        $last = trim((string) ($status['last_successful_sync_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
            $timestamp = strtotime($last . ' 00:00:00 UTC') ?: time();
            return ['date_from' => gmdate('Y-m-d', $timestamp - DAY_IN_SECONDS), 'source' => 'last_successful_sync_date_minus_1_day_overlap'];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $configuredStart)) {
            return ['date_from' => $configuredStart, 'source' => 'setting_ovoko_bidirectional_sync_start_date'];
        }

        return ['date_from' => gmdate('Y-m-d'), 'source' => 'fallback_today'];
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
