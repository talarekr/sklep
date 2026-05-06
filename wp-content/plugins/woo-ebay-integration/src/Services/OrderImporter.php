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
        $orders = $this->adapter->import_orders(['limit' => 20]);
        if (is_wp_error($orders)) {
            return ['result' => 'error', 'error' => $orders->get_error_message()];
        }

        $list = $orders['orders'] ?? [];
        if (!is_array($list) || $list === []) return ['result' => 'skipped', 'reason' => 'no_orders'];

        $processed = [];
        foreach ($list as $order) {
            if (!is_array($order)) continue;
            $order_id = (string) ($order['orderId'] ?? '');
            if ($order_id === '' || in_array((string) ($order['orderFulfillmentStatus'] ?? ''), ['CANCELLED'], true)) continue;

            foreach ((array) ($order['lineItems'] ?? []) as $line) {
                $sku = (string) ($line['sku'] ?? '');
                $mapping = $sku !== '' ? $this->repo->find_by_sku($sku) : null;
                if (!$mapping) continue;

                $product_id = (int) ($mapping['woo_variation_id'] ?: $mapping['woo_product_id']);
                $product = wc_get_product($product_id);
                if (!$product) continue;

                $processedOrders = get_post_meta($product_id, '_wei_processed_ebay_order_ids', true);
                $processedOrders = is_array($processedOrders) ? $processedOrders : [];
                if (in_array($order_id, $processedOrders, true)) {
                    $processed[] = ['order_id' => $order_id, 'product_id' => $product_id, 'result' => 'skipped_already_processed'];
                    continue;
                }

                $qty = max(1, (int) ($line['quantity'] ?? 1));
                $oldStock = (int) $product->get_stock_quantity();
                $settings = get_option(\WEI\Plugin::OPTION_KEY, []);
                $settings = is_array($settings) ? $settings : [];
                $mode = (string) ($settings['stock_sync_mode'] ?? 'set_zero');
                $newStock = $mode === 'reduce' ? max(0, $oldStock - $qty) : 0;
                $product->set_stock_quantity($newStock);
                if ($newStock <= 0) $product->set_stock_status('outofstock');
                $product->save();

                $processedOrders[] = $order_id;
                update_post_meta($product_id, '_wei_processed_ebay_order_ids', array_values(array_unique($processedOrders)));
                update_post_meta($product_id, '_wei_last_ebay_order_id', $order_id);
                update_post_meta($product_id, '_wei_ebay_export_status', 'stock_synced_to_woo');
                $this->logger->info('eBay order stock synced to WooCommerce only', ['product_id' => $product_id, 'sku' => $sku, 'ebay_order_id' => $order_id, 'old_stock' => $oldStock, 'new_stock' => $newStock, 'mode' => $mode]);
                $processed[] = ['order_id' => $order_id, 'product_id' => $product_id, 'result' => 'stock_synced_to_woo', 'old_stock' => $oldStock, 'new_stock' => $newStock];
            }
        }

        return $processed === [] ? ['result' => 'skipped', 'reason' => 'no_mapped_line_items'] : ['result' => 'success', 'processed' => $processed];
    }
}
