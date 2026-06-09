<?php

namespace GPS_Ebay_Fitment\Database;

class Installer
{
    const DB_VERSION = '0.1.0';
    const OPTION_VERSION = 'gps_ebay_fitment_sync_db_version';

    public static function maybe_install()
    {
        if (get_option(self::OPTION_VERSION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function install()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $fitment_table = $wpdb->prefix . 'gps_ebay_fitment_sync';
        $cache_table = $wpdb->prefix . 'gps_tecdoc_lookup_cache';

        $sql_fitment = "CREATE TABLE {$fitment_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            marketplace varchar(32) NOT NULL DEFAULT '',
            plugin_key varchar(64) NOT NULL DEFAULT '',
            mapping_source varchar(32) NOT NULL DEFAULT '',
            listing_id varchar(128) NOT NULL DEFAULT '',
            offer_id varchar(128) NOT NULL DEFAULT '',
            inventory_item_sku varchar(191) NOT NULL DEFAULT '',
            ebay_category_id varchar(64) NOT NULL DEFAULT '',
            compatibility_mode varchar(32) NOT NULL DEFAULT 'ktype',
            fitment_status varchar(32) NOT NULL DEFAULT 'pending',
            part_number varchar(191) NOT NULL DEFAULT '',
            part_number_source varchar(64) NOT NULL DEFAULT '',
            ktype_count int(11) NOT NULL DEFAULT 0,
            request_hash varchar(64) NOT NULL DEFAULT '',
            last_lookup_at datetime NULL,
            last_synced_at datetime NULL,
            last_checked_at datetime NULL,
            last_error text NULL,
            raw_request_id varchar(128) NOT NULL DEFAULT '',
            raw_response_excerpt text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY product_marketplace (product_id, marketplace),
            KEY marketplace (marketplace),
            KEY fitment_status (fitment_status),
            KEY part_number (part_number),
            KEY ktype_count (ktype_count),
            KEY last_checked_at (last_checked_at)
        ) {$charset_collate};";

        $sql_cache = "CREATE TABLE {$cache_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            part_number varchar(191) NOT NULL DEFAULT '',
            normalized_part_number varchar(191) NOT NULL DEFAULT '',
            provider varchar(64) NOT NULL DEFAULT 'apify_tecdoc',
            status varchar(32) NOT NULL DEFAULT '',
            confidence decimal(5,2) NOT NULL DEFAULT 0.00,
            ktype_list_json longtext NULL,
            ktype_count int(11) NOT NULL DEFAULT 0,
            matched_articles_json longtext NULL,
            matched_makes_json longtext NULL,
            matched_models_json longtext NULL,
            raw_summary_json longtext NULL,
            last_error text NULL,
            fetched_at datetime NULL,
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY normalized_provider (normalized_part_number, provider),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY ktype_count (ktype_count)
        ) {$charset_collate};";

        dbDelta($sql_fitment);
        dbDelta($sql_cache);
        update_option(self::OPTION_VERSION, self::DB_VERSION, false);
    }
}
