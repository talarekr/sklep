<?php

namespace WEI\Adapters;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayClient;
use WEI\Services\Logger;

class EbayAdapter implements MarketplaceAdapterInterface
{
    public function __construct(private EbayClient $client, private MappingRepository $repo, private Logger $logger)
    {
    }

    public function readiness_check(): array
    {
        $checks = [
            'fulfillment_policy' => $this->client->get_policies('fulfillment_policy'),
            'payment_policy' => $this->client->get_policies('payment_policy'),
            'return_policy' => $this->client->get_policies('return_policy'),
            'locations' => $this->client->get_locations(),
        ];

        foreach ($checks as $k => $v) {
            if (is_wp_error($v)) {
                return [
                    'ready' => false,
                    'failed' => $k,
                    'error' => $v->get_error_message(),
                    'error_details' => $v->get_error_data(),
                ];
            }
        }

        return ['ready' => true, 'details' => $checks];
    }

    public function export_product(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];

        $sku = (string) $product->get_sku();
        if ($sku === '') return ['result' => 'error', 'error' => 'missing_sku'];

        $item = $this->client->create_or_replace_inventory_item($sku, [
            'availability' => ['shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())]],
            'condition' => 'NEW',
            'product' => [
                'title' => $product->get_name(),
                'description' => (string) $product->get_description(),
                'aspects' => ['MPN' => [(string) get_post_meta($product_id, '_part_number', true)]],
                'imageUrls' => array_values(array_filter(array_map('wp_get_attachment_url', array_merge([$product->get_image_id()], $product->get_gallery_image_ids())))),
            ],
        ]);
        if (is_wp_error($item)) return ['result' => 'error', 'error' => $item->get_error_message()];

        $offer = $this->client->create_offer([
            'sku' => $sku,
            'marketplaceId' => 'EBAY_US',
            'format' => 'FIXED_PRICE',
            'availableQuantity' => max(0, (int) $product->get_stock_quantity()),
            'listingDuration' => 'GTC',
            'pricingSummary' => ['price' => ['value' => (string) $product->get_price(), 'currency' => get_woocommerce_currency()]],
        ]);
        if (is_wp_error($offer)) return ['result' => 'error', 'error' => $offer->get_error_message()];

        $offer_id = (string) ($offer['offerId'] ?? '');
        $published = $offer_id !== '' ? $this->client->publish_offer($offer_id) : new \WP_Error('wei_offer_missing', 'Missing offerId');
        if (is_wp_error($published)) return ['result' => 'error', 'error' => $published->get_error_message()];

        $listing_id = (string) ($published['listingId'] ?? '');
        $this->repo->upsert([
            'marketplace' => 'ebay',
            'woo_product_id' => $product_id,
            'woo_variation_id' => $variation_id,
            'sku' => $sku,
            'remote_inventory_id' => $sku,
            'remote_offer_id' => $offer_id,
            'remote_listing_id' => $listing_id,
            'marketplace_id' => 'EBAY_US',
            'status' => 'active',
            'last_sync_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return ['result' => 'success', 'offer_id' => $offer_id, 'listing_id' => $listing_id, 'inventory_id' => $sku];
    }

    public function sync_stock(int $product_id, ?int $variation_id = null): array
    {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return ['result' => 'error', 'error' => 'product_not_found'];
        $sku = (string) $product->get_sku();
        $map = $this->repo->find_by_sku($sku);
        if (!$map) return ['result' => 'skipped', 'reason' => 'mapping_not_found'];

        $res = $this->client->bulk_update_price_quantity([[
            'offerId' => $map['remote_offer_id'],
            'shipToLocationAvailability' => ['quantity' => max(0, (int) $product->get_stock_quantity())],
            'price' => ['value' => (string) $product->get_price(), 'currency' => get_woocommerce_currency()],
        ]]);

        if (is_wp_error($res)) return ['result' => 'error', 'error' => $res->get_error_message()];

        $this->repo->upsert(array_merge($map, ['last_sync_at' => gmdate('Y-m-d H:i:s')]));
        return ['result' => 'success'];
    }

    public function import_orders(array $query = []): array
    {
        return $this->client->get_orders($query);
    }
}
