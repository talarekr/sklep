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
        add_action('update_option_' . Plugin::OPTION_KEY, [$this, 'reschedule_if_needed'], 10, 2);

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
        $this->logger->info('Cron import started.');
        $this->importer->import_offers();
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
    }
}
