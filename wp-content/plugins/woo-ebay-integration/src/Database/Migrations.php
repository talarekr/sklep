<?php

namespace WEI\Database;

class Migrations
{
    public static function activate(): void
    {
        self::create_mappings_table();
        self::create_category_mappings_table();
        self::create_category_teaching_rules_table();
        self::create_ebay_category_tree_cache_table();
        self::create_sync_queue_table();
    }

    public static function maybe_upgrade(): void
    {
        self::create_mappings_table();
        self::create_category_mappings_table();
        self::create_category_teaching_rules_table();
        self::create_ebay_category_tree_cache_table();
        self::create_sync_queue_table();
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
            active TINYINT(1) NOT NULL DEFAULT 1,
            sample_product_ids TEXT NULL,
            suggestion_payload LONGTEXT NULL,
            error_reason TEXT NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_marketplace_woo_term (marketplace_id, woo_term_id),
            KEY idx_ebay_category (marketplace_id, ebay_category_id),
            KEY idx_status (marketplace_id, status),
            KEY idx_active_priority (marketplace_id, woo_term_id, active, source, status)
        ) {$charset};";

        dbDelta($sql);
        self::upgrade_category_mappings_schema($table);
    }

    private static function upgrade_category_mappings_schema(string $table): void
    {
        global $wpdb;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $columns = is_array($columns) ? array_map('strval', $columns) : [];
        if (!in_array('active', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER status");
        }
        if (!in_array('reviewed_at', $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN reviewed_at DATETIME NULL AFTER error_reason");
        }
        $unique = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_marketplace_woo_term' AND Non_unique = 0");
        if ($unique !== null) {
            $wpdb->query("ALTER TABLE {$table} DROP INDEX uniq_marketplace_woo_term");
        }
    }

    private static function create_category_teaching_rules_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace_id VARCHAR(32) NOT NULL DEFAULT 'EBAY_DE',
            woo_category_path_hash CHAR(64) NOT NULL,
            woo_category_path TEXT NOT NULL,
            detected_intent VARCHAR(96) NOT NULL DEFAULT '',
            title_keyword_family VARCHAR(191) NOT NULL DEFAULT '',
            ebay_category_id VARCHAR(64) NOT NULL,
            ebay_category_path TEXT NULL,
            source VARCHAR(64) NOT NULL DEFAULT 'manual_woo_category_mapping',
            rule_note TEXT NULL,
            import_group_id VARCHAR(64) NULL,
            sample_product_ids TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_rule (marketplace_id, woo_category_path_hash, detected_intent, title_keyword_family),
            KEY idx_category (marketplace_id, ebay_category_id),
            KEY idx_source (source)
        ) {$charset};";

        dbDelta($sql);
    }


    private static function create_ebay_category_tree_cache_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'wei_ebay_category_tree_cache';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            marketplace_id VARCHAR(32) NOT NULL DEFAULT 'EBAY_DE',
            category_id VARCHAR(64) NOT NULL,
            parent_category_id VARCHAR(64) NOT NULL DEFAULT '',
            category_name VARCHAR(191) NOT NULL DEFAULT '',
            category_path TEXT NULL,
            is_leaf TINYINT(1) NOT NULL DEFAULT 0,
            is_automotive TINYINT(1) NOT NULL DEFAULT 0,
            imported_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_marketplace_category (marketplace_id, category_id),
            KEY idx_marketplace_parent (marketplace_id, parent_category_id),
            KEY idx_marketplace_leaf (marketplace_id, is_leaf),
            KEY idx_marketplace_automotive (marketplace_id, is_automotive)
        ) {$charset};";

        dbDelta($sql);
    }

    private static function create_sync_queue_table(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(64) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            queued_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            source VARCHAR(96) NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_product_reason (product_id, reason),
            KEY idx_status_queued (status, queued_at),
            KEY idx_product (product_id)
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
