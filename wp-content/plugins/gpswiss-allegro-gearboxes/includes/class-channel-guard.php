<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class ChannelGuard
{
    /** @var string[] */
    private static array $source_channel_stack = [];

    public static function push_source_channel(string $source_channel): void
    {
        self::$source_channel_stack[] = sanitize_key($source_channel);
    }

    public static function pop_source_channel(): void
    {
        if (count(self::$source_channel_stack) === 0) {
            return;
        }

        array_pop(self::$source_channel_stack);
    }

    public static function current_source_channel(): string
    {
        if (count(self::$source_channel_stack) === 0) {
            return '';
        }

        return (string) self::$source_channel_stack[count(self::$source_channel_stack) - 1];
    }

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

        if (self::current_source_channel() === SyncQueue::CHANNEL_ALLEGRO_GEARBOXES) {
            return 'source_channel_allegro_gearboxes';
        }

        $gearboxes_enabled = strtolower(trim((string) get_post_meta($product_id, '_channel_allegro_gearboxes_enabled', true)));
        if ($gearboxes_enabled !== 'yes') {
            return 'allegro_gearboxes_channel_not_enabled';
        }

        $source_account = strtolower(trim((string) get_post_meta($product_id, '_source_account', true)));
        if ($source_account !== SecondaryProductMeta::ACCOUNT) {
            return $source_account === '' ? 'missing_source_account' : 'different_source_account';
        }

        $offer_id = trim((string) get_post_meta($product_id, SecondaryProductMeta::OFFER_ID_META_KEY, true));
        if ($offer_id === '') {
            return 'missing_secondary_allegro_offer_id';
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
            'source_channel' => self::current_source_channel(),
        ]);

        return false;
    }
}
