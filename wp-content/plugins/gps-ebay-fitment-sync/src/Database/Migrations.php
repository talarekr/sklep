<?php

namespace GPSEbayFitmentSync\Database;

final class Migrations
{
    public static function activate(): void
    {
        self::create_sync_table();
    }

    public static function maybe_upgrade(): void
    {
        self::create_sync_table();
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gps_ebay_fitment_sync';
    }

    private static function create_sync_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            marketplace VARCHAR(32) NOT NULL,
            plugin_key VARCHAR(64) NOT NULL,
            mapping_source VARCHAR(64) NOT NULL DEFAULT '',
            listing_id VARCHAR(128) NULL,
            offer_id VARCHAR(128) NULL,
            inventory_item_sku VARCHAR(191) NULL,
            ebay_category_id VARCHAR(64) NULL,
            compatibility_mode VARCHAR(32) NOT NULL DEFAULT 'ktype',
            fitment_status VARCHAR(32) NOT NULL DEFAULT 'pending',
            oem_value VARCHAR(191) NULL,
            oem_source VARCHAR(191) NULL,
            ktype_count INT UNSIGNED NOT NULL DEFAULT 0,
            request_hash CHAR(64) NULL,
            last_lookup_at DATETIME NULL,
            last_synced_at DATETIME NULL,
            last_checked_at DATETIME NULL,
            last_error TEXT NULL,
            raw_request_id VARCHAR(191) NULL,
            raw_response_excerpt TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_product_marketplace_plugin (product_id, marketplace, plugin_key),
            KEY idx_marketplace_status (marketplace, fitment_status),
            KEY idx_product (product_id),
            KEY idx_inventory_sku (inventory_item_sku),
            KEY idx_listing (listing_id),
            KEY idx_offer (offer_id),
            KEY idx_request_hash (request_hash)
        ) {$charset};";

        dbDelta($sql);
    }
}
