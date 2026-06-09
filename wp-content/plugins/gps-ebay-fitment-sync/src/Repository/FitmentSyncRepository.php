<?php

namespace GPS_Ebay_Fitment\Repository;

class FitmentSyncRepository
{
    public function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'gps_ebay_fitment_sync';
    }

    public function statuses()
    {
        return ['pending', 'missing_part_number', 'missing_ktype', 'no_tecdoc_match', 'ready', 'needs_review', 'too_many_matches', 'missing_listing', 'missing_category', 'skipped', 'error', 'synced'];
    }

    public function upsert_row($row, $dry_run = false)
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $defaults = [
            'product_id' => 0,
            'marketplace' => '',
            'plugin_key' => '',
            'mapping_source' => '',
            'listing_id' => '',
            'offer_id' => '',
            'inventory_item_sku' => '',
            'ebay_category_id' => '',
            'compatibility_mode' => 'ktype',
            'fitment_status' => 'pending',
            'part_number' => '',
            'part_number_source' => '',
            'ktype_count' => 0,
            'request_hash' => '',
            'last_checked_at' => $now,
            'last_error' => '',
            'raw_response_excerpt' => '',
            'updated_at' => $now,
        ];
        $data = array_merge($defaults, $row);
        $data['request_hash'] = $data['request_hash'] ?: hash('sha256', wp_json_encode([$data['product_id'], $data['marketplace'], $data['part_number']]));
        if ($dry_run) {
            $data['dry_run'] = true;
            return $data;
        }

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE product_id = %d AND marketplace = %s",
            (int) $data['product_id'],
            $data['marketplace']
        ));
        if ($existing_id) {
            $wpdb->update($this->table(), $data, ['id' => (int) $existing_id]);
            return (int) $existing_id;
        }
        $data['created_at'] = $now;
        $wpdb->insert($this->table(), $data);
        return (int) $wpdb->insert_id;
    }

    public function overview()
    {
        global $wpdb;
        $table = $this->table();
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $by_marketplace = $wpdb->get_results("SELECT marketplace, COUNT(*) AS total FROM {$table} GROUP BY marketplace", ARRAY_A) ?: [];
        $by_status = $wpdb->get_results("SELECT fitment_status, COUNT(*) AS total FROM {$table} GROUP BY fitment_status", ARRAY_A) ?: [];
        return ['total' => $total, 'by_marketplace' => $by_marketplace, 'by_status' => $by_status];
    }

    public function rows($args = [])
    {
        global $wpdb;
        $limit = isset($args['limit']) ? max(1, min(500, (int) $args['limit'])) : 50;
        $offset = isset($args['offset']) ? max(0, (int) $args['offset']) : 0;
        $where = ['1=1'];
        $params = [];
        if (!empty($args['status'])) {
            $where[] = 'f.fitment_status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['marketplace'])) {
            $where[] = 'f.marketplace = %s';
            $params[] = $args['marketplace'];
        }
        if (!empty($args['only_missing_ktype'])) {
            $where[] = "f.fitment_status IN ('missing_ktype', 'pending')";
        }
        if (!empty($args['only_with_part_number'])) {
            $where[] = "f.part_number <> ''";
        }
        $sql = "SELECT f.*, p.post_title, pm.meta_value AS sku FROM {$this->table()} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.product_id LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = f.product_id AND pm.meta_key = '_sku' WHERE " . implode(' AND ', $where) . ' ORDER BY f.updated_at DESC LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;
        return $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
    }

    public function update_from_lookup($id, $result, $dry_run = false)
    {
        global $wpdb;
        $data = [
            'fitment_status' => $result['status'],
            'ktype_count' => (int) $result['ktype_count'],
            'last_lookup_at' => current_time('mysql', true),
            'last_error' => isset($result['error']) ? (string) $result['error'] : '',
            'raw_response_excerpt' => substr(wp_json_encode(isset($result['raw_summary']) ? $result['raw_summary'] : []), 0, 5000),
            'updated_at' => current_time('mysql', true),
        ];
        if ($dry_run) {
            return $data;
        }
        return $wpdb->update($this->table(), $data, ['id' => (int) $id]);
    }
}
