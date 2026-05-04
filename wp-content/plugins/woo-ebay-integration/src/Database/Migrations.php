<?php

namespace WEI\Database;

class Migrations
{
    public static function activate(): void
    {
        self::create_mappings_table();
    }

    public static function maybe_upgrade(): void
    {
        self::create_mappings_table();
    }

    private static function create_mappings_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'marketplace_mappings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace VARCHAR(32) NOT NULL,
            woo_product_id BIGINT UNSIGNED NOT NULL,
            woo_variation_id BIGINT UNSIGNED NULL,
            sku VARCHAR(191) NOT NULL,
            remote_inventory_id VARCHAR(191) NULL,
            remote_offer_id VARCHAR(191) NULL,
            remote_listing_id VARCHAR(191) NULL,
            marketplace_id VARCHAR(32) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            last_sync_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_marketplace_product (marketplace, woo_product_id, woo_variation_id),
            KEY idx_marketplace_sku (marketplace, sku),
            KEY idx_offer (marketplace, remote_offer_id),
            KEY idx_listing (marketplace, remote_listing_id)
        ) {$charset};";

        dbDelta($sql);
    }
}
