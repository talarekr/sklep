<?php

namespace GPSwiss\Ovoko\Services;

class OvokoBidirectionalSyncOrchestrator
{
    public const STATUS_OPTION = 'gpswiss_ovoko_bidirectional_sync_status';
    public const ENABLED_OPTION = 'gpswiss_ovoko_bidirectional_sync_enabled';
    public const LOCK_TRANSIENT = 'gpswiss_ovoko_bidirectional_sync_lock';
    public const CRON_HOOK = 'gpswiss_ovoko_bidirectional_sync';
    public const DELTA_FILTER = 'date_from';

    private OvokoIntegrationService $integrationService;
    private OvokoWooSaleSyncQueue $saleQueue;

    public function __construct(OvokoIntegrationService $integrationService, ?OvokoWooSaleSyncQueue $saleQueue = null)
    {
        $this->integrationService = $integrationService;
        $this->saleQueue = $saleQueue ?: new OvokoWooSaleSyncQueue($integrationService);
    }

    public function hooks(): void
    {
        add_action(self::CRON_HOOK, [$this, 'run_cron_guarded']);
    }

    public function dashboard_status(): array
    {
        $stored = get_option(self::STATUS_OPTION, []);
        $status = wp_parse_args(is_array($stored) ? $stored : [], $this->default_status());
        $legacyOvokoToWoo = (array) get_option('gpswiss_ovoko_auto_sync_status', []);
        $saleCounts = $this->saleQueue->status_counts();

        $status['sync_enabled'] = (bool) get_option(self::ENABLED_OPTION, false);
        $status['delta_filter_used'] = self::DELTA_FILTER;
        $status['date_from_used'] = (string) ($status['date_from_used'] ?: ($legacyOvokoToWoo['date_from_used'] ?? $legacyOvokoToWoo['date_from'] ?? ''));
        $status['last_successful_sync_at'] = (string) ($status['last_successful_sync_at'] ?: ($legacyOvokoToWoo['last_successful_sync_at'] ?? ''));
        $status['processed_total'] = (int) ($status['processed_total'] ?: ($legacyOvokoToWoo['processed'] ?? 0));
        $status['created_from_ovoko'] = (int) ($status['created_from_ovoko'] ?: ($legacyOvokoToWoo['created_from_delta'] ?? $legacyOvokoToWoo['created'] ?? 0));
        $status['updated_from_ovoko'] = (int) ($status['updated_from_ovoko'] ?: ($legacyOvokoToWoo['updated_from_delta'] ?? $legacyOvokoToWoo['updated'] ?? 0));
        $status['skipped_from_ovoko'] = (int) ($status['skipped_from_ovoko'] ?: ($legacyOvokoToWoo['skipped_already_synced'] ?? $legacyOvokoToWoo['skipped'] ?? 0));
        $status['pending_woo_to_ovoko_sales'] = (int) $saleCounts['pending'];
        $status['successful_woo_to_ovoko_sales'] = (int) $saleCounts['success'];
        $status['failed_woo_to_ovoko_sales'] = (int) $saleCounts['failed'];
        $status['lock_status'] = get_transient(self::LOCK_TRANSIENT) ? 'locked' : 'unlocked';
        $status['automation_guard'] = $this->live_cron_allowed() ? 'live_cron_allowed' : 'live_cron_requires_explicit_code_confirmation';

        return $status;
    }

    public function request_enable_automatic_sync(): array
    {
        if (!$this->live_cron_allowed()) {
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), [
                'status' => 'paused',
                'last_error' => 'automatic_live_cron_not_enabled_without_GPSWISS_OVOKO_ALLOW_LIVE_BIDIRECTIONAL_CRON',
            ]), false);

            return [
                'ok' => false,
                'action_name' => 'Enable automatic Ovoko ↔ Woo sync',
                'message' => 'Prepared only. Automatic live cron was not enabled because explicit code-level confirmation is still missing.',
                'required_confirmation' => 'Define GPSWISS_OVOKO_ALLOW_LIVE_BIDIRECTIONAL_CRON=true after manual live tests are approved.',
                'cron_scheduled' => false,
            ];
        }

        update_option(self::ENABLED_OPTION, true, false);
        update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'idle', 'last_error' => '']), false);

        return ['ok' => true, 'action_name' => 'Enable automatic Ovoko ↔ Woo sync', 'cron_scheduled' => false, 'message' => 'Enabled flag saved; scheduling policy can be attached by deployment cron.'];
    }

    public function pause_sync(): array
    {
        update_option(self::ENABLED_OPTION, false, false);
        update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'paused']), false);
        wp_clear_scheduled_hook(self::CRON_HOOK);

        return ['ok' => true, 'action_name' => 'Disable / Pause sync', 'sync_enabled' => false, 'cron_scheduled' => false];
    }

    public function run_now(): array
    {
        if (!$this->live_cron_allowed()) {
            return [
                'ok' => false,
                'action_name' => 'Run sync now',
                'message' => 'Live bidirectional run is intentionally blocked at this stage.',
                'planned_sequence' => ['Ovoko → Woo date_from delta', 'Woo → Ovoko sale queue'],
                'delta_endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
                'woo_to_ovoko_endpoint' => '/crm/changePartStatus status=2',
                'no_live_cron_started' => true,
            ];
        }

        return $this->run_cron_guarded();
    }

    public function retry_failed_sales(): array
    {
        return [
            'ok' => false,
            'action_name' => 'Retry failed Woo → Ovoko sale sync',
            'message' => 'Retry worker is designed but live sends remain blocked until approval.',
            'retry_policy' => 'Retry only network/5xx/rate-limit failures; skip missing part_id and already-success items.',
            'endpoint' => '/crm/changePartStatus',
            'status' => 2,
            'no_ovoko_api_call' => true,
        ];
    }

    public function run_cron_guarded(): array
    {
        if (!$this->live_cron_allowed() || !get_option(self::ENABLED_OPTION, false)) {
            return ['ok' => false, 'status' => 'paused', 'message' => 'Bidirectional live cron is not enabled.'];
        }

        if (get_transient(self::LOCK_TRANSIENT)) {
            return ['ok' => false, 'status' => 'running', 'message' => 'Sync lock is already active.'];
        }

        set_transient(self::LOCK_TRANSIENT, gmdate('c'), 30 * MINUTE_IN_SECONDS);
        try {
            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'running', 'last_error' => '']), false);

            // Live implementation hook point: 1) Ovoko → Woo date_from delta, then 2) Woo → Ovoko sale queue.
            $result = [
                'ok' => false,
                'status' => 'paused',
                'message' => 'Live implementation is intentionally not wired in this refactor stage.',
                'planned_sequence' => ['ovoko_to_woo_date_from_delta', 'woo_to_ovoko_sale_queue'],
            ];

            update_option(self::STATUS_OPTION, array_merge($this->dashboard_status(), ['status' => 'paused', 'last_error' => $result['message']]), false);
            return $result;
        } finally {
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public function architecture_plan(): array
    {
        return [
            'orchestrator' => self::CRON_HOOK,
            'sequence' => [
                '1_ovoko_to_woo' => [
                    'endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
                    'allowed_delta_filter' => 'date_from YYYY-MM-DD only',
                    'forbidden_filters' => ['updated_from', 'updated_after', 'from', 'timestamps', 'ISO datetime', 'full page scan without date_from'],
                    'existing_products' => ['stock/status sync', 'description sync', 'details sync', 'categories verify-only', 'price untouched', 'images untouched'],
                    'new_products' => ['require valid internal_notes price', 'price from internal_notes only', 'categories from category_id + /get/categories/tree', 'images may import', 'missing price skip/draft not publish'],
                    'idempotency_meta' => ['_gpswiss_ovoko_last_synced_updated_at', '_gpswiss_ovoko_last_synced_hash'],
                ],
                '2_woo_to_ovoko' => $this->saleQueue->design_summary(),
            ],
            'live_cron_enabled_now' => false,
        ];
    }

    private function default_status(): array
    {
        return [
            'sync_enabled' => false,
            'status' => 'idle',
            'last_successful_sync_at' => '',
            'next_scheduled_sync_at' => '',
            'delta_filter_used' => self::DELTA_FILTER,
            'date_from_used' => '',
            'processed_total' => 0,
            'created_from_ovoko' => 0,
            'updated_from_ovoko' => 0,
            'skipped_from_ovoko' => 0,
            'pending_woo_to_ovoko_sales' => 0,
            'successful_woo_to_ovoko_sales' => 0,
            'failed_woo_to_ovoko_sales' => 0,
            'last_error' => '',
            'lock_status' => 'unlocked',
        ];
    }

    private function live_cron_allowed(): bool
    {
        return defined('GPSWISS_OVOKO_ALLOW_LIVE_BIDIRECTIONAL_CRON') && GPSWISS_OVOKO_ALLOW_LIVE_BIDIRECTIONAL_CRON;
    }
}
