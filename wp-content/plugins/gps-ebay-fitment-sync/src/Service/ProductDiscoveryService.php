<?php

namespace GPS_Ebay_Fitment\Service;

use GPS_Ebay_Fitment\Registry\MarketplaceRegistry;
use GPS_Ebay_Fitment\Repository\FitmentSyncRepository;
use GPS_Ebay_Fitment\Resolver\PartNumberResolver;

class ProductDiscoveryService
{
    private $registry;
    private $repository;
    private $part_number_resolver;

    public function __construct(MarketplaceRegistry $registry, FitmentSyncRepository $repository, PartNumberResolver $part_number_resolver)
    {
        $this->registry = $registry;
        $this->repository = $repository;
        $this->part_number_resolver = $part_number_resolver;
    }

    public function scan($args = [])
    {
        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 100;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $dry_run = !empty($args['dry_run']);
        $marketplace_filter = isset($args['marketplace']) ? $args['marketplace'] : 'all';
        $marketplaces = $this->registry->enabled();
        if ($marketplace_filter !== 'all' && isset($marketplaces[$marketplace_filter])) {
            $marketplaces = [$marketplace_filter => $marketplaces[$marketplace_filter]];
        }

        $prepared = [];
        foreach ($marketplaces as $marketplace_id => $config) {
            foreach ($this->discover_from_mapping_table($marketplace_id, $config, $limit, $offset) as $record) {
                $prepared[] = $this->prepare_row($marketplace_id, $config, $record, 'marketplace_mappings', $dry_run);
            }
            foreach ($this->discover_from_meta($marketplace_id, $config, $limit, $offset) as $record) {
                $prepared[] = $this->prepare_row($marketplace_id, $config, $record, 'product_meta', $dry_run);
            }
        }

        return ['dry_run' => $dry_run, 'rows' => $prepared, 'count' => count($prepared)];
    }

    private function prepare_row($marketplace_id, $config, $record, $source, $dry_run)
    {
        $part = $this->part_number_resolver->resolve($record['product_id']);
        $status = $part['part_number'] === '' ? 'missing_part_number' : 'missing_ktype';
        if (empty($record['listing_id'])) {
            $status = 'missing_listing';
        } elseif (empty($record['ebay_category_id'])) {
            $status = 'missing_category';
        }
        $row = [
            'product_id' => (int) $record['product_id'],
            'marketplace' => $marketplace_id,
            'plugin_key' => $config['plugin_key'],
            'mapping_source' => $source,
            'listing_id' => isset($record['listing_id']) ? $record['listing_id'] : '',
            'offer_id' => isset($record['offer_id']) ? $record['offer_id'] : '',
            'inventory_item_sku' => isset($record['inventory_item_sku']) ? $record['inventory_item_sku'] : '',
            'ebay_category_id' => isset($record['ebay_category_id']) ? $record['ebay_category_id'] : '',
            'fitment_status' => $status,
            'part_number' => $part['part_number'],
            'part_number_source' => $part['source'],
            'ktype_count' => (int) get_post_meta($record['product_id'], '_gps_fitment_ktype_count', true),
        ];
        $result = $this->repository->upsert_row($row, $dry_run);
        return is_array($result) ? $result : array_merge($row, ['id' => $result]);
    }

    private function discover_from_mapping_table($marketplace_id, $config, $limit, $offset)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return [];
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $selects = [
            'product_id' => $this->first_existing_column($columns, ['product_id', 'woo_product_id', 'post_id']),
            'listing_id' => $this->first_existing_column($columns, ['remote_listing_id', 'listing_id', 'item_id']),
            'offer_id' => $this->first_existing_column($columns, ['remote_offer_id', 'offer_id']),
            'inventory_item_sku' => $this->first_existing_column($columns, ['remote_inventory_id', 'inventory_item_sku', 'sku']),
            'ebay_category_id' => $this->first_existing_column($columns, ['ebay_category_id', 'remote_category_id', 'category_id']),
        ];
        if (!$selects['product_id'] || !in_array('marketplace', $columns, true)) {
            return [];
        }
        $fields = [];
        foreach ($selects as $alias => $column) {
            $fields[] = $column ? "{$column} AS {$alias}" : "'' AS {$alias}";
        }
        $where = ['marketplace = %s'];
        $params = [$config['mapping_marketplace']];
        if (in_array('marketplace_id', $columns, true)) {
            $where[] = 'marketplace_id = %s';
            $params[] = $marketplace_id;
        }
        $remote_conditions = [];
        foreach (['remote_inventory_id', 'remote_offer_id', 'remote_listing_id'] as $remote_column) {
            if (in_array($remote_column, $columns, true)) {
                $remote_conditions[] = "({$remote_column} IS NOT NULL AND {$remote_column} <> '')";
            }
        }
        if ($remote_conditions) {
            $where[] = '(' . implode(' OR ', $remote_conditions) . ')';
        }
        $params[] = $limit;
        $params[] = $offset;
        $sql = 'SELECT ' . implode(', ', $fields) . " FROM {$table} WHERE " . implode(' AND ', $where) . ' LIMIT %d OFFSET %d';
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    private function discover_from_meta($marketplace_id, $config, $limit, $offset)
    {
        global $wpdb;
        $keys = $marketplace_id === 'EBAY_FR'
            ? ['_wei_fr_ebay_sku', '_wei_fr_ebay_inventory_id', '_wei_fr_ebay_inventory_item_id', '_wei_fr_ebay_offer_id', '_wei_fr_ebay_listing_id', '_wei_fr_ebay_item_id', '_wei_fr_ebay_category_id']
            : ['_wei_ebay_sku', '_wei_ebay_inventory_id', '_wei_ebay_offer_id', '_wei_ebay_listing_id', '_wei_ebay_item_id', '_wei_ebay_category_id'];
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $params = array_merge($keys, [$limit, $offset]);
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> '' LIMIT %d OFFSET %d",
            $params
        ));
        $records = [];
        foreach ($product_ids as $product_id) {
            $records[] = [
                'product_id' => (int) $product_id,
                'listing_id' => $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_listing_id' : '_wei_ebay_listing_id') ?: $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_item_id' : '_wei_ebay_item_id'),
                'offer_id' => $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_offer_id' : '_wei_ebay_offer_id'),
                'inventory_item_sku' => $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_inventory_id' : '_wei_ebay_inventory_id') ?: $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_inventory_item_id' : '_wei_ebay_sku') ?: $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_sku' : '_wei_ebay_sku'),
                'ebay_category_id' => $this->meta($product_id, $marketplace_id === 'EBAY_FR' ? '_wei_fr_ebay_category_id' : '_wei_ebay_category_id'),
            ];
        }
        return $records;
    }

    private function first_existing_column($columns, $candidates)
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return null;
    }

    private function meta($product_id, $key)
    {
        return (string) get_post_meta($product_id, $key, true);
    }
}
