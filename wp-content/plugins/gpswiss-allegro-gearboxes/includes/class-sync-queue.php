<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class SyncQueue
{
    public const OPTION_KEY = 'gag_sync_queue';
    public const CHANNEL_WOOCOMMERCE = 'woocommerce';
    public const CHANNEL_ALLEGRO_MAIN = 'allegro_main';
    public const CHANNEL_ALLEGRO_GEARBOXES = 'allegro_gearboxes';
    public const CHANNEL_EBAY_DE = 'ebay_de';
    public const CHANGE_STOCK_CHANGED = 'stock_changed';

    public static function enqueue_stock_changed(
        int $product_id,
        string $sku,
        string $source_channel,
        string $target_channel,
        $old_value,
        $new_value
    ): bool {
        if ($source_channel === $target_channel) {
            return false;
        }

        if (!self::is_target_enabled_for_product($product_id, $target_channel)) {
            return false;
        }

        $entry = [
            'product_id' => max(0, $product_id),
            'sku' => sanitize_text_field($sku),
            'source_channel' => sanitize_key($source_channel),
            'target_channel' => sanitize_key($target_channel),
            'change_type' => self::CHANGE_STOCK_CHANGED,
            'old_value' => $old_value,
            'new_value' => $new_value,
            'status' => 'pending',
            'attempts' => 0,
            'last_error' => '',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'processed_at' => '',
            'dedupe_key' => self::build_dedupe_key($product_id, $source_channel, $target_channel, self::CHANGE_STOCK_CHANGED),
        ];

        $queue = get_option(self::OPTION_KEY, []);
        if (!is_array($queue)) {
            $queue = [];
        }

        foreach ($queue as $existing) {
            if (is_array($existing) && (string) ($existing['dedupe_key'] ?? '') === $entry['dedupe_key'] && (string) ($existing['status'] ?? '') === 'pending') {
                return false;
            }
        }

        $queue[] = $entry;
        $queue = array_slice($queue, -500);

        return update_option(self::OPTION_KEY, $queue, false);
    }

    public static function is_target_enabled_for_product(int $product_id, string $target_channel): bool
    {
        $meta_key = '_channel_' . sanitize_key($target_channel) . '_enabled';
        return strtolower((string) get_post_meta($product_id, $meta_key, true)) === 'yes';
    }

    private static function build_dedupe_key(int $product_id, string $source_channel, string $target_channel, string $change_type): string
    {
        return md5(implode('|', [max(0, $product_id), sanitize_key($source_channel), sanitize_key($target_channel), sanitize_key($change_type)]));
    }
}
