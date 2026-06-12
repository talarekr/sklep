<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Support;

final class Settings
{
    public const OPTION = 'gps_ebay_fitment_sync_settings';

    public function hooks(): void
    {
        add_action('admin_init', [$this, 'register']);
    }

    public function register(): void
    {
        register_setting('gps_ebay_fitment_sync', self::OPTION, [$this, 'sanitize']);
    }

    public function defaults(): array
    {
        return [
            'apify_token' => '',
            'actor_id' => 'Zt16dqMI2yN7Igggl',
            'lang_id' => 4,
            'country_filter_id' => 63,
            'timeout' => 60,
            'batch_size' => 5,
            'max_apify_lookups_per_batch' => 5,
        ];
    }

    public function all(): array
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($this->defaults(), $stored);
    }

    public function sanitize($value): array
    {
        $current = $this->all();
        $value = is_array($value) ? $value : [];
        $token = isset($value['apify_token']) ? trim((string) $value['apify_token']) : '';
        if ($token === '********') {
            $token = $current['apify_token'];
        }

        return [
            'apify_token' => $token,
            'actor_id' => isset($value['actor_id']) ? sanitize_text_field((string) $value['actor_id']) : 'Zt16dqMI2yN7Igggl',
            'lang_id' => isset($value['lang_id']) ? max(1, (int) $value['lang_id']) : 4,
            'country_filter_id' => isset($value['country_filter_id']) ? max(1, (int) $value['country_filter_id']) : 63,
            'timeout' => isset($value['timeout']) ? max(5, min(300, (int) $value['timeout'])) : 60,
            'batch_size' => isset($value['batch_size']) ? max(1, min(50, (int) $value['batch_size'])) : 5,
            'max_apify_lookups_per_batch' => isset($value['max_apify_lookups_per_batch']) ? max(1, min(10, (int) $value['max_apify_lookups_per_batch'])) : 5,
        ];
    }

    public function get(string $key)
    {
        $settings = $this->all();
        return $settings[$key] ?? null;
    }
}
