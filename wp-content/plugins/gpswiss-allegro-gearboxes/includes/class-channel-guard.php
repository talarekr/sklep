<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class ChannelGuard
{
    public static function is_gearboxes_outbound_allowed(int $product_id): bool
    {
        return self::get_gearboxes_block_reason($product_id) === '';
    }

    public static function get_gearboxes_block_reason(int $product_id): string
    {
        $product_id = max(0, $product_id);
        if ($product_id <= 0) {
            return 'invalid_product_id';
        }

        $gearboxes_enabled = strtolower(trim((string) get_post_meta($product_id, '_channel_allegro_gearboxes_enabled', true)));
        if ($gearboxes_enabled !== 'yes') {
            return 'allegro_gearboxes_channel_not_enabled';
        }

        $source_account = strtolower(trim((string) get_post_meta($product_id, '_source_account', true)));
        if ($source_account !== '' && $source_account !== SecondaryProductMeta::ACCOUNT) {
            return 'different_source_account';
        }

        return '';
    }

    public static function assert_gearboxes_outbound_allowed(int $product_id, string $operation, Logger $logger): bool
    {
        $reason = self::get_gearboxes_block_reason($product_id);
        if ($reason === '') {
            return true;
        }

        $logger->info('ALLEGRO_GEARBOXES_OUTBOUND_SKIPPED_BY_GUARD', [
            'product_id' => max(0, $product_id),
            'operation' => sanitize_key($operation),
            'reason' => $reason,
        ]);

        return false;
    }
}
