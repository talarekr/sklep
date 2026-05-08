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
        return $this->import_since('', 20);
    }

    public function import_since(string $sinceGmt, int $limit = 50): array
    {
        $query = ['limit' => max(1, min(100, $limit))];
        if ($sinceGmt !== '') {
            $from = gmdate('Y-m-d\TH:i:s.000\Z', max(0, strtotime($sinceGmt) - 300));
            $query['filter'] = 'lastmodifieddate:[' . $from . '..]';
        }

        $orders = $this->adapter->import_orders($query);
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
                if (!is_array($line)) continue;

                $sku = trim((string) ($line['sku'] ?? ''));
                $offerId = trim((string) ($line['offerId'] ?? $line['offer']['offerId'] ?? ''));
                $itemId = trim((string) ($line['legacyItemId'] ?? $line['itemId'] ?? ''));
                $resolution = $this->resolve_line_item_product($sku, $offerId, $itemId);
                if (!$resolution) {
                    $this->logger->warning('eBay order line item skipped: no Woo product mapping', ['sku' => $sku, 'offer_id' => $offerId, 'item_id' => $itemId, 'ebay_order_id' => $order_id]);
                    continue;
                }

                $product_id = (int) $resolution['product_id'];
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
                $mode = (string) ($settings['ebay_order_stock_update_mode'] ?? $settings['stock_sync_mode'] ?? 'set_zero');
                $newStock = $mode === 'reduce' ? max(0, $oldStock - $qty) : 0;
                AutoSyncScheduler::mark_ebay_order_stock_context(true);
                $product->set_stock_quantity($newStock);
                if ($newStock <= 0) $product->set_stock_status('outofstock');
                $product->save();
                AutoSyncScheduler::mark_ebay_order_stock_context(false);

                $processedOrders[] = $order_id;
                update_post_meta($product_id, '_wei_processed_ebay_order_ids', array_values(array_unique($processedOrders)));
                update_post_meta($product_id, '_wei_last_ebay_order_id', $order_id);
                update_post_meta($product_id, '_wei_ebay_export_status', 'stock_synced_to_woo');
                $this->logger->info('Woo stock updated from eBay order', ['product_id' => $product_id, 'ebay_sku' => $sku, 'ebay_order_id' => $order_id, 'old_stock' => $oldStock, 'new_stock' => $newStock, 'mode' => $mode, 'resolved_by' => $resolution['resolved_by'], 'wrote_allegro' => false]);
                $processed[] = ['order_id' => $order_id, 'product_id' => $product_id, 'result' => 'stock_synced_to_woo', 'old_stock' => $oldStock, 'new_stock' => $newStock, 'resolved_by' => $resolution['resolved_by']];
            }
        }

        return $processed === [] ? ['result' => 'skipped', 'reason' => 'no_mapped_line_items'] : ['result' => 'success', 'processed' => $processed];
    }

    private function resolve_line_item_product(string $sku, string $offerId, string $itemId): ?array
    {
        if ($sku !== '') {
            $productId = $this->find_product_id_by_wei_ebay_sku($sku);
            if ($productId > 0) {
                return ['product_id' => $productId, 'resolved_by' => '_wei_ebay_sku'];
            }

            $mapping = $this->repo->find_by_sku($sku);
            if ($mapping) {
                return ['product_id' => (int) ($mapping['woo_variation_id'] ?: $mapping['woo_product_id']), 'resolved_by' => 'mapping_sku'];
            }
        }

        if ($offerId !== '') {
            $mapping = $this->repo->find_by_offer_id($offerId);
            if ($mapping) {
                return ['product_id' => (int) ($mapping['woo_variation_id'] ?: $mapping['woo_product_id']), 'resolved_by' => 'mapping_offer_id'];
            }

            $productId = $this->find_product_id_by_meta('_wei_ebay_offer_id', $offerId);
            if ($productId > 0) {
                return ['product_id' => $productId, 'resolved_by' => '_wei_ebay_offer_id'];
            }
        }

        if ($itemId !== '') {
            $mapping = $this->repo->find_by_listing_id($itemId);
            if ($mapping) {
                return ['product_id' => (int) ($mapping['woo_variation_id'] ?: $mapping['woo_product_id']), 'resolved_by' => 'mapping_item_id'];
            }

            $productId = $this->find_product_id_by_meta('_wei_ebay_item_id', $itemId);
            if ($productId > 0) {
                return ['product_id' => $productId, 'resolved_by' => '_wei_ebay_item_id'];
            }
        }

        return null;
    }

    private function find_product_id_by_wei_ebay_sku(string $sku): int
    {
        return $this->find_product_id_by_meta('_wei_ebay_sku', $sku);
    }

    private function find_product_id_by_meta(string $metaKey, string $metaValue): int
    {
        global $wpdb;

        $productId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s AND p.post_type IN ('product', 'product_variation') LIMIT 1",
            $metaKey,
            $metaValue
        ));

        return $productId;
    }
}
