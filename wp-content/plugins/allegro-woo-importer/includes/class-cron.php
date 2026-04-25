<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Cron
{
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
        $this->importer->import_offers();
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
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_has_scheduled_action(Plugin::MISSING_IMPORT_CRON_HOOK)) {
                as_schedule_single_action(time() + 2, Plugin::MISSING_IMPORT_CRON_HOOK, [], 'awi');
            }
            return;
        }

        if (!wp_next_scheduled(Plugin::MISSING_IMPORT_CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, Plugin::MISSING_IMPORT_CRON_HOOK);
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

        if (!wp_next_scheduled(Plugin::CRON_HOOK)) {
            wp_schedule_event(time() + 60, $interval, Plugin::CRON_HOOK);
        }
    }

    public static function clear_schedule(): void
    {
        $timestamp = wp_next_scheduled(Plugin::CRON_HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, Plugin::CRON_HOOK);
            $timestamp = wp_next_scheduled(Plugin::CRON_HOOK);
        }
    }

    public static function on_activation(): void
    {
        if (!wp_next_scheduled(Plugin::CRON_HOOK)) {
            $settings = Plugin::get_settings();
            $interval = $settings['cron_interval'] ?? 'manual';
            if ($interval !== 'manual') {
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
}
