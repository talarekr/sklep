<?php

namespace WEI\Database;

class Migrations
{
    public static function activate(): void
    {
        self::create_mappings_table();
        self::create_category_mappings_table();
    }

    public static function maybe_upgrade(): void
    {
        self::create_mappings_table();
        self::create_category_mappings_table();
    }

    private static function create_category_mappings_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace_id VARCHAR(32) NOT NULL DEFAULT 'EBAY_DE',
            woo_term_id BIGINT UNSIGNED NOT NULL,
            woo_category_path TEXT NULL,
            ebay_category_id VARCHAR(64) NOT NULL,
            ebay_category_name VARCHAR(191) NULL,
            ebay_category_path TEXT NULL,
            source VARCHAR(32) NOT NULL DEFAULT 'manual',
            confidence DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
            status VARCHAR(32) NOT NULL DEFAULT 'mapped_manual',
            sample_product_ids TEXT NULL,
            suggestion_payload LONGTEXT NULL,
            error_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_marketplace_woo_term (marketplace_id, woo_term_id),
            KEY idx_ebay_category (marketplace_id, ebay_category_id),
            KEY idx_status (marketplace_id, status)
        ) {$charset};";

        dbDelta($sql);
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
