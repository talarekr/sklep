<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class ChannelGuard
{
    public const SOURCE_ACCOUNT_GEARBOXES = 'allegro_gearboxes';
    public const EXPORT_BLOCKED_YES = 'yes';
    public const MAIN_CHANNEL_DISABLED = 'no';

    /**
     * Returns true when a WooCommerce product must never be pushed to the main Allegro account.
     */
    public static function is_main_allegro_outbound_blocked(int $product_id): bool
    {
        return self::get_main_allegro_block_reason($product_id) !== '';
    }

    /**
     * Returns the first blocking reason for concise logs, or an empty string when outbound is allowed.
     */
    public static function get_main_allegro_block_reason(int $product_id): string
    {
        $product_id = max(0, $product_id);
        if ($product_id <= 0) {
            return 'invalid_product_id';
        }

        $source_account = strtolower(trim((string) get_post_meta($product_id, '_source_account', true)));
        if ($source_account === self::SOURCE_ACCOUNT_GEARBOXES) {
            return 'source_account_allegro_gearboxes';
        }

        $export_blocked = strtolower(trim((string) get_post_meta($product_id, '_allegro_export_blocked', true)));
        if ($export_blocked === self::EXPORT_BLOCKED_YES) {
            return 'allegro_export_blocked';
        }

        $main_channel_enabled = strtolower(trim((string) get_post_meta($product_id, '_channel_allegro_main_enabled', true)));
        if ($main_channel_enabled === self::MAIN_CHANNEL_DISABLED) {
            return 'allegro_main_channel_disabled';
        }

        return '';
    }

    /**
     * Central defensive guard for current and future main-Allegro outbound paths.
     */
    public static function assert_main_allegro_outbound_allowed(int $product_id, string $operation, Logger $logger): bool
    {
        $reason = self::get_main_allegro_block_reason($product_id);
        if ($reason === '') {
            return true;
        }

        $logger->info('ALLEGRO_MAIN_OUTBOUND_SKIPPED_BY_GUARD', [
            'product_id' => max(0, $product_id),
            'operation' => sanitize_key($operation),
            'reason' => $reason,
        ]);

        return false;
    }
}
