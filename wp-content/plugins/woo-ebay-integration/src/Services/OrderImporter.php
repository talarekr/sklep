<?php

namespace WEI\Services;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\MappingRepository;

class OrderImporter
{
    public function __construct(private MarketplaceAdapterInterface $adapter, private MappingRepository $repo, private Logger $logger)
    {
    }

    public function import_once(): array
    {
        $orders = $this->adapter->import_orders(['limit' => 1]);
        if (is_wp_error($orders)) {
            return ['result' => 'error', 'error' => $orders->get_error_message()];
        }

        $list = $orders['orders'] ?? [];
        if (!is_array($list) || $list === []) return ['result' => 'skipped', 'reason' => 'no_orders'];

        $order = $list[0];
        $order_id = (string) ($order['orderId'] ?? '');
        if ($order_id === '') return ['result' => 'error', 'error' => 'missing_order_id'];

        $exists = wc_get_orders(['limit' => 1, 'meta_key' => '_ebay_order_id', 'meta_value' => $order_id]);
        if (!empty($exists)) return ['result' => 'skipped', 'reason' => 'already_imported', 'order_id' => $order_id];

        $wc_order = wc_create_order();
        foreach ((array) ($order['lineItems'] ?? []) as $line) {
            $sku = (string) ($line['sku'] ?? '');
            $mapping = $this->repo->find_by_sku($sku);
            if (!$mapping && !empty($line['listingId'])) {
                continue;
            }
            if (!$mapping) continue;

            $product = wc_get_product((int) $mapping['woo_variation_id'] ?: (int) $mapping['woo_product_id']);
            if (!$product) continue;
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $wc_order->add_product($product, $qty);
        }

        $wc_order->update_meta_data('_ebay_order_id', $order_id);
        $wc_order->save();

        return ['result' => 'success', 'order_id' => $order_id, 'woo_order_id' => $wc_order->get_id()];
    }
}
