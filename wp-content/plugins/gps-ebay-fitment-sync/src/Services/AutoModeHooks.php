<?php

namespace GPSEbayFitmentSync\Services;

final class AutoModeHooks
{
    private $preparation;

    public function __construct(FitmentPreparationService $preparation)
    {
        $this->preparation = $preparation;
    }

    public function hooks(): void
    {
        add_action('gps_ebay_fitment_prepare_product', [$this, 'prepare_product'], 10, 2);
        add_action('added_post_meta', [$this, 'maybe_prepare_from_mapping_meta'], 10, 4);
        add_action('updated_post_meta', [$this, 'maybe_prepare_from_mapping_meta'], 10, 4);
    }

    public function prepare_product(int $productId, array $args = []): void
    {
        $this->preparation->prepare_product($productId, array_merge(['marketplace' => 'all'], $args));
    }

    public function maybe_prepare_from_mapping_meta($metaId, $objectId, $metaKey, $metaValue): void
    {
        $keys = [
            '_wei_ebay_sku', '_wei_ebay_inventory_id', '_wei_ebay_offer_id', '_wei_ebay_listing_id', '_wei_ebay_item_id',
            '_wei_fr_ebay_sku', '_wei_fr_ebay_inventory_id', '_wei_fr_ebay_inventory_item_id', '_wei_fr_ebay_offer_id', '_wei_fr_ebay_listing_id', '_wei_fr_ebay_item_id',
        ];
        if (!in_array((string) $metaKey, $keys, true) || trim((string) $metaValue) === '') {
            return;
        }
        $productId = (int) $objectId;
        if ($productId > 0) {
            do_action('gps_ebay_fitment_auto_prepare_detected', $productId, $metaKey);
            $this->preparation->prepare_product($productId, ['marketplace' => 'all']);
        }
    }
}
