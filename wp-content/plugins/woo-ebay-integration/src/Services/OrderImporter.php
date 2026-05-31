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
                $lineItemId = trim((string) ($line['lineItemId'] ?? ''));
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

                $ovokoPartId = $this->resolve_ovoko_part_id($product_id);
                $this->logger->info('EBAY_ORDER_SALE_DETECTED', ['source' => 'ebay_order', 'ebay_order_id' => $order_id, 'ebay_line_item_id' => $lineItemId !== '' ? $lineItemId : ($itemId !== '' ? $itemId : $offerId), 'product_id' => $product_id, 'ovoko_part_id' => $ovokoPartId, 'quantity' => $qty, 'old_stock' => $oldStock, 'new_stock' => $newStock]);
                $ovokoQueue = $this->enqueue_ovoko_sale_job_from_ebay_order($order_id, $lineItemId !== '' ? $lineItemId : ($itemId !== '' ? $itemId : $offerId), $product_id, $ovokoPartId, [
                    'sku' => $sku,
                    'offer_id' => $offerId,
                    'item_id' => $itemId,
                    'quantity' => $qty,
                    'old_stock' => $oldStock,
                    'new_stock' => $newStock,
                    'resolved_by' => (string) $resolution['resolved_by'],
                ]);

                $this->logger->info('Woo stock updated from eBay order', ['product_id' => $product_id, 'ebay_sku' => $sku, 'ebay_order_id' => $order_id, 'old_stock' => $oldStock, 'new_stock' => $newStock, 'mode' => $mode, 'resolved_by' => $resolution['resolved_by'], 'wrote_allegro' => false, 'ovoko_sale_queue' => $ovokoQueue]);
                $processed[] = ['order_id' => $order_id, 'product_id' => $product_id, 'result' => 'stock_synced_to_woo', 'old_stock' => $oldStock, 'new_stock' => $newStock, 'resolved_by' => $resolution['resolved_by'], 'ovoko_part_id' => $ovokoPartId, 'ovoko_sale_queue' => $ovokoQueue];
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



    private function enqueue_ovoko_sale_job_from_ebay_order(string $orderId, string $lineItemId, int $productId, string $ovokoPartId, array $context): array
    {
        $payload = [
            'source' => 'ebay_order',
            'source_order_id' => $orderId,
            'ebay_order_id' => $orderId,
            'source_item_id' => $lineItemId,
            'ebay_line_item_id' => $lineItemId,
            'product_id' => $productId,
            'ovoko_part_id' => $ovokoPartId,
            'part_id' => $ovokoPartId,
            'status_sent_to_ovoko' => 2,
            'context' => $context,
        ];
        $default = ['ok' => false, 'queued' => false, 'skipped' => true, 'reason' => 'gpswiss_ovoko_queue_filter_unavailable'] + $payload;
        $result = apply_filters('gpswiss_ovoko_enqueue_sale_job_result', $default, $payload);
        $result = is_array($result) ? $result : $default;

        $logContext = [
            'source' => 'ebay_order',
            'ebay_order_id' => $orderId,
            'ebay_line_item_id' => $lineItemId,
            'product_id' => $productId,
            'ovoko_part_id' => $ovokoPartId,
            'queue_result' => $result,
        ];
        if (!empty($result['queued'])) {
            $this->logger->info('EBAY_ORDER_OVOKO_SALE_JOB_QUEUED', $logContext);
        } elseif (!empty($result['skipped'])) {
            $this->logger->warning('EBAY_ORDER_OVOKO_SALE_JOB_SKIPPED', $logContext);
        } else {
            $this->logger->error('EBAY_ORDER_OVOKO_SALE_JOB_FAILED', $logContext);
        }

        return $result;
    }

    private function resolve_ovoko_part_id(int $productId): string
    {
        $parentProductId = 0;
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            $parentProductId = $product && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        }
        foreach ([$productId, $parentProductId] as $candidateProductId) {
            if ($candidateProductId <= 0) {
                continue;
            }
            foreach (['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'] as $key) {
                $value = (string) get_post_meta($candidateProductId, $key, true);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }
        return '';
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
