<?php

namespace WEI_FR\Repositories;

use WEI_FR\Services\Logger;

class MappingRepository
{
    public function __construct(private Logger $logger)
    {
    }

    public function upsert(array $data): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $existing = $this->find_by_product((int) $data['woo_product_id'], isset($data['woo_variation_id']) ? (int) $data['woo_variation_id'] : null);
        $now = gmdate('Y-m-d H:i:s');
        $row = array_merge($data, ['updated_at' => $now]);

        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
            return;
        }

        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
    }

    public function find_by_product(int $product_id, ?int $variation_id = null): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        if ($variation_id === null) {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND woo_product_id=%d AND woo_variation_id IS NULL LIMIT 1", 'ebay_fr', $product_id);
        } else {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND woo_product_id=%d AND woo_variation_id=%d LIMIT 1", 'ebay_fr', $product_id, $variation_id);
        }
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function find_by_sku(string $sku): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND sku=%s LIMIT 1", 'ebay_fr', $sku);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }


    public function list_active_mappings(int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $limit = max(1, min(500, $limit));
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND status NOT IN ('ended','sold','inactive','unavailable') AND status IN ('active','published') AND (remote_offer_id IS NOT NULL AND remote_offer_id <> '' OR remote_listing_id IS NOT NULL AND remote_listing_id <> '') ORDER BY updated_at DESC LIMIT %d", 'ebay_fr', $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function find_by_offer_id(string $offer_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND remote_offer_id=%s LIMIT 1", 'ebay_fr', $offer_id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public function find_by_listing_id(string $listing_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE marketplace=%s AND remote_listing_id=%s LIMIT 1", 'ebay_fr', $listing_id);
        $row = $wpdb->get_row($sql, ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
