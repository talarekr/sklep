<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Cron
{
    private const ACTION_SCHEDULER_GROUP = 'awi';

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
        add_action(Plugin::CRON_HOOK, [$this, 'run_scheduled_import']);
        add_action(Plugin::MISSING_IMPORT_CRON_HOOK, [$this, 'run_missing_import_batch']);
        add_action('update_option_' . Plugin::OPTION_KEY, [$this, 'reschedule_if_needed'], 10, 2);
        $this->cleanup_duplicate_main_import_actions();

        if (Plugin::is_safe_mode_enabled()) {
            self::clear_schedule();
            $this->logger->warning('Safe mode enabled: cron import schedule cleared.');
            return;
        }

        $this->schedule_from_settings();
    }

    public function register_intervals(array $schedules): array
    {
        $schedules['awi_15_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Co 15 minut (Allegro Import)', 'allegro-woo-importer'),
        ];

        return $schedules;
    }

    public function run_scheduled_import(): void
    {
        if (Plugin::is_safe_mode_enabled()) {
            $this->logger->warning('Safe mode enabled: scheduled import skipped.');
            return;
        }

        $this->logger->info('Cron import started.');
        $event_sync_summary = $this->importer->run_event_based_sync();
        $event_status = (string) ($event_sync_summary['status'] ?? '');
        if ($event_status === 'fallback_required' || $event_status === 'error') {
            $this->logger->warning('EVENT_SYNC_ERROR', [
                'stage' => 'fallback_to_full_import',
                'reason' => (string) ($event_sync_summary['reason'] ?? 'unknown'),
                'event_status' => $event_status,
            ]);
            $this->logger->warning('EVENT_SYNC_FALLBACK_FULL_IMPORT_STARTED', [
                'reason' => (string) ($event_sync_summary['reason'] ?? 'unknown'),
                'event_status' => $event_status,
            ]);
            $this->importer->mark_fallback_full_import_started((string) ($event_sync_summary['reason'] ?? 'unknown'));
            $this->importer->import_offers();
        }
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
            $this->schedule_from_settings();
        }
    }

    private function schedule_from_settings(): void
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

        if ($this->schedule_main_import_with_action_scheduler($interval)) {
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
                $has_group_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP) !== false;
                $has_any_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK) !== false;
                if ($next_action_timestamp <= 0 && !$has_group_scheduled_action && !$has_any_scheduled_action) {
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

    private function schedule_main_import_with_action_scheduler(string $interval): bool
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
        $has_group_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP) !== false;
        $has_any_scheduled_action = as_has_scheduled_action(Plugin::CRON_HOOK) !== false;
        if ($next_action_timestamp <= 0 && !$has_group_scheduled_action && !$has_any_scheduled_action) {
            as_schedule_recurring_action(time() + 60, $interval_seconds, Plugin::CRON_HOOK, [], self::ACTION_SCHEDULER_GROUP);
            $this->logger->info('BACKGROUND_SYNC_SCHEDULED', [
                'runner' => 'action_scheduler',
                'hook' => Plugin::CRON_HOOK,
                'interval' => $interval,
                'interval_seconds' => $interval_seconds,
            ]);
        }

        return true;
    }

    private function cleanup_duplicate_main_import_actions(): void
    {
        if (
            !function_exists('as_get_scheduled_actions')
            || !function_exists('as_unschedule_action')
        ) {
            return;
        }

        $active_actions = as_get_scheduled_actions([
            'hook' => Plugin::CRON_HOOK,
            'status' => 'pending',
            'orderby' => 'date',
            'order' => 'ASC',
            'per_page' => 100,
        ]);

        if (!is_array($active_actions) || count($active_actions) <= 1) {
            return;
        }

        $removed = 0;
        $kept = false;
        foreach ($active_actions as $action) {
            if (!is_object($action) || !method_exists($action, 'get_args') || !method_exists($action, 'get_group')) {
                continue;
            }

            if (!$kept) {
                $kept = true;
                continue;
            }

            $args = $action->get_args();
            $group = (string) $action->get_group();
            as_unschedule_action(Plugin::CRON_HOOK, is_array($args) ? $args : [], $group);
            $removed++;
        }

        if ($removed > 0) {
            $this->logger->warning('BACKGROUND_SYNC_DUPLICATES_CLEANED', [
                'hook' => Plugin::CRON_HOOK,
                'duplicates_removed' => $removed,
                'active_before' => count($active_actions),
                'active_after' => max(1, count($active_actions) - $removed),
            ]);
        }
    }
}
