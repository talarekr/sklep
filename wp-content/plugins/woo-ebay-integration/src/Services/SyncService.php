<?php

namespace WEI\Services;

use WEI\Interfaces\MarketplaceAdapterInterface;
use WEI\Repositories\MappingRepository;

class SyncService
{
    public function __construct(private MarketplaceAdapterInterface $adapter, private MappingRepository $repo, private Logger $logger)
    {
    }

    public function handle_stock_change($product): void
    {
        if (!$product) return;
        $this->adapter->sync_stock((int) $product->get_id());
    }

    public function sync_stock_batch(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT woo_product_id, woo_variation_id FROM {$table} WHERE marketplace=%s LIMIT 50", 'ebay'), ARRAY_A);
        foreach ((array) $rows as $row) {
            $this->adapter->sync_stock((int) $row['woo_product_id'], isset($row['woo_variation_id']) ? (int) $row['woo_variation_id'] : null);
        }
    }
}
