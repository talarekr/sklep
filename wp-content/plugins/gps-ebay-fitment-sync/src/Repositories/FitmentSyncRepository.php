<?php

namespace GPSEbayFitmentSync\Repositories;

use GPSEbayFitmentSync\Database\Migrations;

final class FitmentSyncRepository
{
    public function table_name(): string
    {
        return Migrations::table_name();
    }

    public function upsert(array $data): int
    {
        global $wpdb;
        $table = $this->table_name();
        $now = current_time('mysql');
        $productId = (int) ($data['product_id'] ?? 0);
        $marketplace = (string) ($data['marketplace'] ?? '');
        $pluginKey = (string) ($data['plugin_key'] ?? '');
        $existingId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE product_id=%d AND marketplace=%s AND plugin_key=%s LIMIT 1",
            $productId,
            $marketplace,
            $pluginKey
        ));

        $defaults = [
            'product_id' => $productId,
            'marketplace' => $marketplace,
            'plugin_key' => $pluginKey,
            'mapping_source' => '',
            'listing_id' => '',
            'offer_id' => '',
            'inventory_item_sku' => '',
            'ebay_category_id' => '',
            'compatibility_mode' => 'ktype',
            'fitment_status' => 'pending',
            'oem_value' => '',
            'oem_source' => '',
            'ktype_count' => 0,
            'request_hash' => '',
            'last_lookup_at' => null,
            'last_synced_at' => null,
            'last_checked_at' => $now,
            'last_error' => '',
            'raw_request_id' => '',
            'raw_response_excerpt' => '',
            'updated_at' => $now,
        ];
        $payload = array_merge($defaults, $data);

        if ($existingId > 0) {
            $wpdb->update($table, $payload, ['id' => $existingId]);
            return $existingId;
        }

        $payload['created_at'] = $now;
        $wpdb->insert($table, $payload);
        return (int) $wpdb->insert_id;
    }

    public function counts_by_status(): array
    {
        global $wpdb;
        $table = $this->table_name();
        $rows = (array) $wpdb->get_results("SELECT marketplace, fitment_status, COUNT(*) AS total FROM {$table} GROUP BY marketplace, fitment_status", ARRAY_A);
        $counts = ['total' => 0, 'by_marketplace' => [], 'by_status' => []];
        foreach ($rows as $row) {
            $marketplace = (string) ($row['marketplace'] ?? '');
            $status = (string) ($row['fitment_status'] ?? '');
            $total = (int) ($row['total'] ?? 0);
            $counts['total'] += $total;
            $counts['by_marketplace'][$marketplace] = ($counts['by_marketplace'][$marketplace] ?? 0) + $total;
            $counts['by_status'][$status] = ($counts['by_status'][$status] ?? 0) + $total;
        }
        return $counts;
    }

    public function recent_rows(array $args = []): array
    {
        global $wpdb;
        $limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $status = (string) ($args['status'] ?? '');
        $marketplace = (string) ($args['marketplace'] ?? '');
        $where = ['1=1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'fitment_status=%s';
            $params[] = $status;
        }
        if ($marketplace !== '' && $marketplace !== 'all') {
            $where[] = 'marketplace=%s';
            $params[] = $marketplace;
        }
        $params[] = $limit;
        $sql = "SELECT * FROM {$this->table_name()} WHERE " . implode(' AND ', $where) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public function export_rows(string $type): array
    {
        global $wpdb;
        $where = '1=1';
        $params = [];
        if ($type === 'missing_oem') {
            $where = 'fitment_status IN (%s,%s)';
            $params[] = 'missing_oem';
            $params[] = 'missing_part_number';
        } elseif ($type === 'missing_ktype') {
            $where = 'fitment_status=%s';
            $params[] = 'missing_ktype';
        } elseif ($type === 'ready') {
            $where = 'fitment_status=%s';
            $params[] = 'ready';
        } elseif ($type === 'errors') {
            $where = 'fitment_status=%s';
            $params[] = 'error';
        }
        $sql = "SELECT * FROM {$this->table_name()} WHERE {$where} ORDER BY marketplace ASC, product_id ASC";
        return $params ? (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : (array) $wpdb->get_results($sql, ARRAY_A);
    }
}
