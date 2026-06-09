<?php

namespace GPSEbayFitmentSync\Adapters;

use GPSEbayFitmentSync\DTO\ListingContext;
use GPSEbayFitmentSync\Services\MarketplaceRegistry;

final class WeiMarketplaceMappingAdapter
{
    private $registry;

    public function __construct(MarketplaceRegistry $registry)
    {
        $this->registry = $registry;
    }

    /** @return ListingContext[] */
    public function contexts_for_product(int $productId, ?string $marketplace = null): array
    {
        $contexts = [];
        $marketplaces = $marketplace && $marketplace !== 'all' ? [$marketplace] : ['EBAY_DE', 'EBAY_FR'];
        foreach ($marketplaces as $marketplaceId) {
            $config = $this->registry->get($marketplaceId);
            if (!$config) {
                continue;
            }
            $context = $this->context_from_mapping_table($productId, $marketplaceId, $config);
            if (!$context) {
                $context = $this->context_from_product_meta($productId, $marketplaceId, $config);
            }
            if ($context) {
                $contexts[] = $context;
            }
        }
        return $contexts;
    }

    /** @return int[] */
    public function discover_product_ids(array $args = []): array
    {
        global $wpdb;

        $limit = max(1, min(1000, (int) ($args['limit'] ?? 100)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $marketplace = (string) ($args['marketplace'] ?? 'all');
        $ids = [];

        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        if ($this->table_exists($mappingTable)) {
            $rowKeys = [];
            foreach (['EBAY_DE', 'EBAY_FR'] as $marketplaceId) {
                if ($marketplace !== 'all' && $marketplace !== $marketplaceId) {
                    continue;
                }
                $config = $this->registry->get($marketplaceId);
                if ($config) {
                    $rowKeys[] = (string) $config['marketplace_row_key'];
                }
            }

            if ($rowKeys) {
                $columns = $this->columns($mappingTable);
                $productColumn = in_array('woo_product_id', $columns, true) ? 'woo_product_id' : 'product_id';
                $idChecks = [];
                foreach (['remote_inventory_id', 'remote_offer_id', 'remote_listing_id'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $idChecks[] = "({$column} IS NOT NULL AND {$column} <> '')";
                    }
                }
                $marketplaceSql = "marketplace IN (" . implode(',', array_fill(0, count($rowKeys), '%s')) . ")";
                if (in_array('marketplace_id', $columns, true)) {
                    $idsForMarketplace = $marketplace === 'all' ? ['EBAY_DE', 'EBAY_FR'] : [$marketplace];
                    $marketplaceSql = '(' . $marketplaceSql . " OR marketplace_id IN (" . implode(',', array_fill(0, count($idsForMarketplace), '%s')) . "))";
                    $params = array_merge($rowKeys, $idsForMarketplace);
                } else {
                    $params = $rowKeys;
                }
                $where = $marketplaceSql;
                if ($idChecks) {
                    $where .= ' AND (' . implode(' OR ', $idChecks) . ')';
                }
                $params[] = $limit;
                $params[] = $offset;
                $sql = "SELECT DISTINCT {$productColumn} FROM {$mappingTable} WHERE {$where} ORDER BY {$productColumn} ASC LIMIT %d OFFSET %d";
                $rows = $wpdb->get_col($wpdb->prepare($sql, $params));
                foreach ((array) $rows as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }

        if (count($ids) < $limit) {
            foreach ($this->discover_product_ids_from_meta($marketplace, $limit - count($ids), $offset) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function count_mapped_products(?string $marketplace = null): int
    {
        global $wpdb;
        $ids = [];
        $mappingTable = $wpdb->prefix . 'marketplace_mappings';
        $marketplace = $marketplace ?: 'all';

        if ($this->table_exists($mappingTable)) {
            $rowKeys = [];
            foreach (['EBAY_DE', 'EBAY_FR'] as $marketplaceId) {
                if ($marketplace !== 'all' && $marketplace !== $marketplaceId) {
                    continue;
                }
                $config = $this->registry->get($marketplaceId);
                if ($config) {
                    $rowKeys[] = (string) $config['marketplace_row_key'];
                }
            }
            if ($rowKeys) {
                $columns = $this->columns($mappingTable);
                $productColumn = in_array('woo_product_id', $columns, true) ? 'woo_product_id' : 'product_id';
                $idChecks = [];
                foreach (['remote_inventory_id', 'remote_offer_id', 'remote_listing_id'] as $column) {
                    if (in_array($column, $columns, true)) {
                        $idChecks[] = "({$column} IS NOT NULL AND {$column} <> '')";
                    }
                }
                $where = "marketplace IN (" . implode(',', array_fill(0, count($rowKeys), '%s')) . ")";
                $params = $rowKeys;
                if ($idChecks) {
                    $where .= ' AND (' . implode(' OR ', $idChecks) . ')';
                }
                $rows = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT {$productColumn} FROM {$mappingTable} WHERE {$where}", $params));
                foreach ($rows as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
            }
        }

        foreach ($this->discover_product_ids_from_meta($marketplace, 100000, 0) as $id) {
            $ids[$id] = $id;
        }

        return count($ids);
    }

    private function context_from_mapping_table(int $productId, string $marketplaceId, array $config): ?ListingContext
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        if (!$this->table_exists($table)) {
            return null;
        }
        $columns = $this->columns($table);
        $productColumn = in_array('woo_product_id', $columns, true) ? 'woo_product_id' : 'product_id';
        $where = ["{$productColumn} = %d", 'marketplace = %s'];
        $params = [$productId, (string) $config['marketplace_row_key']];
        if (in_array('marketplace_id', $columns, true)) {
            $where[] = '(marketplace_id = %s OR marketplace_id IS NULL OR marketplace_id = \'\')';
            $params[] = $marketplaceId;
        }
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT 1';
        $row = $wpdb->get_row($wpdb->prepare($sql, $params), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        return $this->make_context($productId, $marketplaceId, $config, 'wp_marketplace_mappings', $row);
    }

    private function context_from_product_meta(int $productId, string $marketplaceId, array $config): ?ListingContext
    {
        $prefix = $marketplaceId === 'EBAY_FR' ? '_wei_fr_ebay' : '_wei_ebay';
        $raw = [
            'sku' => get_post_meta($productId, $prefix . '_sku', true),
            'remote_inventory_id' => get_post_meta($productId, $prefix . '_inventory_id', true),
            'remote_inventory_item_id' => get_post_meta($productId, $prefix . '_inventory_item_id', true),
            'remote_offer_id' => get_post_meta($productId, $prefix . '_offer_id', true),
            'remote_listing_id' => get_post_meta($productId, $prefix . '_listing_id', true),
            'remote_item_id' => get_post_meta($productId, $prefix . '_item_id', true),
            'ebay_category_id' => get_post_meta($productId, $prefix . '_category_id', true),
            'listing_status' => get_post_meta($productId, $prefix . '_listing_status', true),
            'export_status' => get_post_meta($productId, $prefix . '_export_status', true),
        ];
        if (trim((string) ($raw['sku'] ?: $raw['remote_inventory_id'] ?: $raw['remote_inventory_item_id'] ?: $raw['remote_offer_id'] ?: $raw['remote_listing_id'] ?: $raw['remote_item_id'])) === '') {
            return null;
        }
        return $this->make_context($productId, $marketplaceId, $config, 'product_meta_fallback', $raw);
    }

    private function make_context(int $productId, string $marketplaceId, array $config, string $source, array $row): ListingContext
    {
        $sku = $row['remote_inventory_id'] ?? $row['inventory_item_sku'] ?? $row['sku'] ?? $row['remote_inventory_item_id'] ?? '';
        $listingId = $row['remote_listing_id'] ?? $row['listing_id'] ?? $row['remote_item_id'] ?? '';
        $categoryId = $row['ebay_category_id'] ?? $row['category_id'] ?? $row['remote_category_id'] ?? '';
        return new ListingContext([
            'product_id' => $productId,
            'marketplace' => $marketplaceId,
            'plugin_key' => (string) $config['plugin_key'],
            'mapping_source' => $source,
            'inventory_item_sku' => (string) $sku,
            'offer_id' => (string) ($row['remote_offer_id'] ?? $row['offer_id'] ?? ''),
            'listing_id' => (string) $listingId,
            'ebay_category_id' => (string) $categoryId,
            'listing_status' => (string) ($row['listing_status'] ?? $row['status'] ?? ''),
            'export_status' => (string) ($row['export_status'] ?? ''),
            'public_url' => (string) ($row['public_url'] ?? ''),
            'raw' => $row,
        ]);
    }

    /** @return int[] */
    private function discover_product_ids_from_meta(string $marketplace, int $limit, int $offset): array
    {
        global $wpdb;
        if ($limit <= 0) {
            return [];
        }
        $keys = [];
        if ($marketplace === 'all' || $marketplace === 'EBAY_DE') {
            $keys = array_merge($keys, ['_wei_ebay_sku', '_wei_ebay_inventory_id', '_wei_ebay_offer_id', '_wei_ebay_listing_id', '_wei_ebay_item_id']);
        }
        if ($marketplace === 'all' || $marketplace === 'EBAY_FR') {
            $keys = array_merge($keys, ['_wei_fr_ebay_sku', '_wei_fr_ebay_inventory_id', '_wei_fr_ebay_inventory_item_id', '_wei_fr_ebay_offer_id', '_wei_fr_ebay_listing_id', '_wei_fr_ebay_item_id']);
        }
        if (!$keys) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $params = array_merge($keys, [$limit, $offset]);
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> '' ORDER BY post_id ASC LIMIT %d OFFSET %d",
            $params
        )));
    }

    private function table_exists(string $table): bool
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function columns(string $table): array
    {
        static $cache = [];
        if (!isset($cache[$table])) {
            global $wpdb;
            $cache[$table] = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0));
        }
        return $cache[$table];
    }
}
