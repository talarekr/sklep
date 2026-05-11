<?php

namespace GAG;

if (!defined('ABSPATH')) {
    exit;
}

class SecondaryProductMeta
{
    public const OFFER_ID_META_KEY = '_secondary_allegro_offer_id';
    public const ACCOUNT = 'allegro_gearboxes';

    public static function apply(int $product_id, string $offer_id = ''): void
    {
        $product_id = max(0, $product_id);
        if ($product_id <= 0) {
            return;
        }

        update_post_meta($product_id, '_source_marketplace', 'allegro');
        update_post_meta($product_id, '_source_account', self::ACCOUNT);
        update_post_meta($product_id, '_source_channel', self::ACCOUNT);

        update_post_meta($product_id, '_allegro_export_blocked', 'yes');
        update_post_meta($product_id, '_ebay_export_allowed', 'yes');

        update_post_meta($product_id, '_channel_allegro_main_enabled', 'no');
        update_post_meta($product_id, '_channel_allegro_gearboxes_enabled', 'yes');
        update_post_meta($product_id, '_channel_ebay_de_enabled', 'yes');
        update_post_meta($product_id, '_channel_woocommerce_enabled', 'yes');

        if ($offer_id !== '') {
            update_post_meta($product_id, self::OFFER_ID_META_KEY, sanitize_text_field($offer_id));
        }

        update_post_meta($product_id, '_secondary_allegro_account', self::ACCOUNT);
        update_post_meta($product_id, '_imported_from_secondary_allegro', 'yes');
    }
}
