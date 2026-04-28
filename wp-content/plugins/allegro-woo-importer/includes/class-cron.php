<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Cron
{
    private const ACTION_SCHEDULER_GROUP = 'awi';
    private const MANUAL_ACTION_SCHEDULER_GROUP = 'awi-manual';
    private const SCHEDULER_ARGS = [];
    private const ALREADY_EXISTS_LOG_THROTTLE_SECONDS = 300;
    private const ADMIN_REPAIR_THROTTLE_SECONDS = 300;
    private const ALREADY_EXISTS_LOG_TRANSIENT_KEY = 'awi_background_sync_exists_log';
    private const ADMIN_REPAIR_TRANSIENT_KEY = 'awi_background_sync_admin_repair';

    private Importer $importer;
    private Logger $logger;

    public function __construct(Importer $importer, Logger $logger)
    {
        $this->importer = $importer;
        $this->logger = $logger;
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'register_intervals']);
        add_action(Plugin::CRON_HOOK, [$this, 'run_scheduled_import'], 10, 1);
        add_action(Plugin::MISSING_IMPORT_CRON_HOOK, [$this, 'run_missing_import_batch']);
        add_action('update_option_' . Plugin::OPTION_KEY, [$this, 'reschedule_if_needed'], 10, 2);
        add_action('admin_init', [$this, 'maybe_repair_schedule_admin_only']);

        if (Plugin::is_safe_mode_enabled()) {
            self::clear_schedule();
            $this->logger->warning('Safe mode enabled: cron import schedule cleared.');
            return;
        }
    }

    public function register_intervals(array $schedules): array
    {
        $schedules['awi_15_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Co 15 minut (Allegro Import)', 'allegro-woo-importer'),
        ];

        return $schedules;
    }

    public function run_scheduled_import(array $context = []): void
    {
        if (Plugin::is_safe_mode_enabled()) {
            $this->logger->warning('Safe mode enabled: scheduled import skipped.');
            return;
        }

        $trigger = sanitize_key((string) ($context['trigger'] ?? 'scheduled'));
        $is_manual_trigger = $trigger === 'manual_sync';
        $manual_context = [
            'trigger' => $trigger,
            'request_id' => sanitize_text_field((string) ($context['request_id'] ?? '')),
            'user_id' => isset($context['user_id']) ? (int) $context['user_id'] : 0,
        ];

        $this->logger->info('Cron import started.');
        $this->logger->info('EVENT_SYNC_SCHEDULED_RUN_START', [
            'hook' => Plugin::CRON_HOOK,
            'trigger' => $trigger,
        ]);
        try {
            $event_sync_summary = $this->importer->run_event_based_sync();
            $this->logger->info('EVENT_SYNC_SCHEDULED_RUN_RESULT', [
                'hook' => Plugin::CRON_HOOK,
                'status' => (string) ($event_sync_summary['status'] ?? 'unknown'),
                'reason' => (string) ($event_sync_summary['reason'] ?? ''),
                'processed_events' => (int) ($event_sync_summary['processed_events'] ?? 0),
                'offer_events' => (int) ($event_sync_summary['offer_events'] ?? 0),
                'order_events' => (int) ($event_sync_summary['order_events'] ?? 0),
                'trigger' => $trigger,
            ]);

            if (($event_sync_summary['status'] ?? '') === 'fallback_required') {
                $this->logger->warning('EVENT_SYNC_ERROR', [
                    'stage' => 'fallback_to_full_import',
                    'reason' => (string) ($event_sync_summary['reason'] ?? 'unknown'),
                ]);
                $this->logger->warning('EVENT_SYNC_FALLBACK_FULL_IMPORT_STARTED', [
                    'reason' => (string) ($event_sync_summary['reason'] ?? 'unknown'),
                ]);
                $this->importer->mark_fallback_full_import_started((string) ($event_sync_summary['reason'] ?? 'unknown'));
                $this->importer->import_offers();
            } else {
                $this->logger->info('EVENT_SYNC_NO_FALLBACK_FULL_IMPORT', [
                    'reason' => 'event_sync_finished_without_fallback_required',
                    'status' => (string) ($event_sync_summary['status'] ?? 'unknown'),
                ]);
            }

            if ($is_manual_trigger) {
                $this->logger->info('MANUAL_SYNC_DONE', $manual_context + [
                    'event_sync_status' => (string) ($event_sync_summary['status'] ?? 'unknown'),
                    'event_sync_reason' => (string) ($event_sync_summary['reason'] ?? ''),
                ]);
            }
        } catch (\Throwable $exception) {
            if ($is_manual_trigger) {
                $this->logger->error('MANUAL_SYNC_ERROR', $manual_context + [
                    'message' => $exception->getMessage(),
                ]);
            }
            $this->logger->error('Scheduled import failed with unhandled exception.', [
                'message' => $exception->getMessage(),
                'trigger' => $trigger,
            ]);
        }
    }

    public function schedule_manual_sync_now(array $context): bool
    {
        if (!function_exists('as_schedule_single_action')) {
            $this->logger->error('MANUAL_SYNC_ERROR', [
                'reason' => 'action_scheduler_unavailable',
            ] + $context);
            return false;
        }
    }

        as_schedule_single_action(time() + 1, Plugin::CRON_HOOK, $context, self::ACTION_SCHEDULER_GROUP);
        return true;
    }

    public function run_missing_import_batch(): void
    {
        if (Plugin::is_safe_mode_enabled()) {
            $this->logger->warning('Safe mode enabled: missing import batch skipped.');
            return;
        }

        $state = $this->importer->run_missing_import_batch();
        if (($state['status'] ?? '') === 'running') {
            $this->schedule_missing_import_batch();
        }
    }

    public function schedule_missing_import_batch(): void
    {
        $action_scheduler_scheduled = false;
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            // as_has_scheduled_action() can return the currently running action inside callback context.
            // Here we care only about queued future runs, so use as_next_scheduled_action().
            $next_action_timestamp = as_next_scheduled_action(Plugin::MISSING_IMPORT_CRON_HOOK, [], 'awi');
            if ((int) $next_action_timestamp <= 0) {
                as_schedule_single_action(time() + 2, Plugin::MISSING_IMPORT_CRON_HOOK, [], 'awi');
                $action_scheduler_scheduled = true;
                $this->logger->info('MISSING_IMPORT_BATCH_ENQUEUED', [
                    'runner' => 'action_scheduler',
                    'delay_seconds' => 2,
                ]);
            } else {
                $this->logger->info('MISSING_IMPORT_BATCH_ALREADY_QUEUED', [
                    'runner' => 'action_scheduler',
                    'next_run_at' => gmdate('Y-m-d H:i:s', (int) $next_action_timestamp),
                    'next_run_timestamp' => (int) $next_action_timestamp,
                ]);
            }
        }

        if (!$action_scheduler_scheduled && !wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, Plugin::MISSING_IMPORT_CRON_HOOK);
            $this->logger->info('MISSING_IMPORT_BATCH_ENQUEUED', [
                'runner' => 'wp_cron_fallback',
                'delay_seconds' => 5,
            ]);
        }
    }

    public function clear_missing_import_schedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Plugin::MISSING_IMPORT_CRON_HOOK, [], 'awi');
        }

        $timestamp = wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, Plugin::MISSING_IMPORT_CRON_HOOK);
            $timestamp = wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK);
        }
    }

    public function reschedule_if_needed($old_value, $new_value): void
    {
        $old_interval = is_array($old_value) ? ($old_value['cron_interval'] ?? 'manual') : 'manual';
        $new_interval = is_array($new_value) ? ($new_value['cron_interval'] ?? 'manual') : 'manual';

        if ($old_interval !== $new_interval) {
            self::clear_schedule();
            $this->schedule_from_settings('settings_interval_changed');
            return;
        }

        $this->schedule_from_settings('settings_saved');
    }

    public function maybe_repair_schedule_admin_only(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (Plugin::is_safe_mode_enabled()) {
            return;
        }

        $last_repair_ts = (int) get_transient(self::ADMIN_REPAIR_TRANSIENT_KEY);
        if ($last_repair_ts > 0 && (time() - $last_repair_ts) < self::ADMIN_REPAIR_THROTTLE_SECONDS) {
            return;
        }

        set_transient(self::ADMIN_REPAIR_TRANSIENT_KEY, time(), self::ADMIN_REPAIR_THROTTLE_SECONDS);
        $this->cleanup_duplicate_main_import_actions();
        $this->schedule_from_settings('admin_repair');
    }

    private function schedule_from_settings(string $source = 'unknown'): void
    {
        if (Plugin::is_safe_mode_enabled()) {
            self::clear_schedule();
            return;
        }

        $settings = Plugin::get_settings();
        $interval = $settings['cron_interval'] ?? 'manual';

        if ($interval === 'manual') {
            self::clear_schedule();
            return;
        }

        if ($this->schedule_main_import_with_action_scheduler($interval, $source)) {
            return;
        }

        if (!wp_next_scheduled(Plugin::CRON_HOOK)) {
            wp_schedule_event(time() + 60, $interval, Plugin::CRON_HOOK);
            $this->logger->warning('BACKGROUND_SYNC_SCHEDULED_WITH_WP_CRON_FALLBACK', [
                'hook' => Plugin::CRON_HOOK,
                'interval' => $interval,
            ]);
        }
    }

    public static function clear_schedule(): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Plugin::CRON_HOOK);
            as_unschedule_all_actions(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
        }

        $timestamp = wp_next_scheduled(Plugin::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, Plugin::CRON_HOOK);
            $timestamp = wp_next_scheduled(Plugin::CRON_HOOK);
        }
    }

    public static function on_activation(): void
    {
        $settings = Plugin::get_settings();
        $interval = $settings['cron_interval'] ?? 'manual';
        if ($interval !== 'manual') {
            $interval_seconds = wp_get_schedules()[$interval]['interval'] ?? 0;
            if (
                function_exists('as_next_scheduled_action')
                && function_exists('as_has_scheduled_action')
                && function_exists('as_schedule_recurring_action')
                && $interval_seconds > 0
            ) {
                $next_action_timestamp = (int) as_next_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
                $has_any_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP) !== false;
                if ($next_action_timestamp <= 0 && !$has_any_scheduled_action) {
                    as_schedule_recurring_action(time() + 60, (int) $interval_seconds, Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
                    return;
                }
            }

            if (!wp_next_scheduled(Plugin::CRON_HOOK)) {
                wp_schedule_event(time() + 60, $interval, Plugin::CRON_HOOK);
            }
        }
    }

    public static function on_deactivation(): void
    {
        self::clear_schedule();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(Plugin::MISSING_IMPORT_CRON_HOOK, [], 'awi');
        }

        $timestamp = wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, Plugin::MISSING_IMPORT_CRON_HOOK);
            $timestamp = wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK);
        }
    }

    private function schedule_main_import_with_action_scheduler(string $interval, string $source): bool
    {
        if (
            !function_exists('as_next_scheduled_action')
            || !function_exists('as_has_scheduled_action')
            || !function_exists('as_schedule_recurring_action')
        ) {
            return false;
        }

        $schedules = wp_get_schedules();
        $interval_seconds = (int) ($schedules[$interval]['interval'] ?? 0);
        if ($interval_seconds <= 0) {
            return false;
        }

        $next_action_timestamp = (int) as_next_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
        $has_any_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP) !== false;
        $next_action_timestamp_any_group = (int) as_next_scheduled_action(Plugin::CRON_HOOK);
        $has_any_scheduled_action_any_group = as_has_scheduled_action(Plugin::CRON_HOOK) !== false;

        if ($next_action_timestamp <= 0 && !$has_any_scheduled_action && $has_any_scheduled_action_any_group) {
            $this->logger->warning('BACKGROUND_SYNC_ALREADY_SCHEDULED', [
                'runner' => 'action_scheduler',
                'hook' => Plugin::CRON_HOOK,
                'next_run_timestamp_any_group' => $next_action_timestamp_any_group,
                'next_run_at_any_group' => $next_action_timestamp_any_group > 0 ? gmdate('Y-m-d H:i:s', $next_action_timestamp_any_group) : '',
                'reason' => 'existing_job_detected_outside_default_group_or_args',
            ]);

            return true;
        }

        if ($next_action_timestamp <= 0 && !$has_any_scheduled_action) {
            as_schedule_recurring_action(time() + 60, $interval_seconds, Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
            $this->logger->info('BACKGROUND_SYNC_SCHEDULED', [
                'runner' => 'action_scheduler',
                'hook' => Plugin::CRON_HOOK,
                'next_run_timestamp_any_group' => $next_action_timestamp_any_group,
                'next_run_at_any_group' => $next_action_timestamp_any_group > 0 ? gmdate('Y-m-d H:i:s', $next_action_timestamp_any_group) : '',
                'reason' => 'existing_job_detected_outside_default_group_or_args',
                'source' => $source,
            ]);

            return true;
        }

        if ($next_action_timestamp <= 0 && !$has_any_scheduled_action) {
            $action_id = as_schedule_recurring_action(time() + 60, $interval_seconds, Plugin::CRON_HOOK, self::SCHEDULER_ARGS, self::ACTION_SCHEDULER_GROUP);
            if ($action_id !== false) {
                $this->logger->info('BACKGROUND_SYNC_SCHEDULED', [
                    'runner' => 'action_scheduler',
                    'hook' => Plugin::CRON_HOOK,
                    'interval' => $interval,
                    'interval_seconds' => $interval_seconds,
                    'action_id' => $action_id,
                    'source' => $source,
                ]);
            }
        } else {
            $this->log_background_sync_already_exists_throttled([
                'runner' => 'action_scheduler',
                'hook' => Plugin::CRON_HOOK,
                'next_run_at' => gmdate('Y-m-d H:i:s', $next_action_timestamp),
                'next_run_timestamp' => $next_action_timestamp,
                'source' => $source,
            ]);
        }

        return true;
    }

    private function log_background_sync_already_exists_throttled(array $context): void
    {
        $last_logged_ts = (int) get_transient(self::ALREADY_EXISTS_LOG_TRANSIENT_KEY);
        if ($last_logged_ts > 0 && (time() - $last_logged_ts) < self::ALREADY_EXISTS_LOG_THROTTLE_SECONDS) {
            return;
        }

        set_transient(self::ALREADY_EXISTS_LOG_TRANSIENT_KEY, time(), self::ALREADY_EXISTS_LOG_THROTTLE_SECONDS);
        $this->logger->info('BACKGROUND_SYNC_ALREADY_EXISTS', $context);
    }

    private function count_pending_actions_for_main_hook(): int
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return 0;
        }

        $actions = as_get_scheduled_actions([
            'hook' => Plugin::CRON_HOOK,
            'status' => 'pending',
            'per_page' => 200,
        ], 'ids');

        return is_array($actions) ? count($actions) : 0;
    }

    private function cleanup_duplicate_main_import_actions(): void
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return;
        }

        $active_actions = as_get_scheduled_actions([
            'hook' => Plugin::CRON_HOOK,
            'status' => 'pending',
            'orderby' => 'date',
            'order' => 'ASC',
            'per_page' => 100,
        ], 'ids');

        if (!is_array($active_actions) || count($active_actions) <= 1) {
            return;
        }

        $action_store = null;
        if (class_exists('\\ActionScheduler') && method_exists('\\ActionScheduler', 'store')) {
            $action_store = \ActionScheduler::store();
        }

        if ($action_store === null || !method_exists($action_store, 'fetch_action') || !method_exists($action_store, 'cancel_action')) {
            return;
        }

        $canonical_action_id = 0;
        foreach ($active_actions as $action_id) {
            $action = $action_store->fetch_action((int) $action_id);
            if (!is_object($action) || !method_exists($action, 'get_group')) {
                continue;
            }

            if ((string) $action->get_group() === self::ACTION_SCHEDULER_GROUP) {
                $canonical_action_id = (int) $action_id;
                break;
            }
        }

        if ($canonical_action_id <= 0) {
            $canonical_action_id = (int) $active_actions[0];
        }

        $removed = 0;
        $removed_ids = [];
        foreach ($active_actions as $action_id) {
            $action_id = (int) $action_id;
            if ($action_id === $canonical_action_id) {
                continue;
            }

            $action_store->cancel_action($action_id);
            $removed++;
            $removed_ids[] = $action_id;
        }

        $this->logger->warning('BACKGROUND_SYNC_DUPLICATES_CLEANED', [
            'hook' => Plugin::CRON_HOOK,
            'group' => self::ACTION_SCHEDULER_GROUP,
            'kept_action_id' => $canonical_action_id,
            'removed_action_ids' => $removed_ids,
            'duplicates_removed' => $removed,
            'active_before' => count($active_actions),
            'active_after' => 1,
        ]);
    }
}
