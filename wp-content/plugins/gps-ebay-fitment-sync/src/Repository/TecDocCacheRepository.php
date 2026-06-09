<?php

namespace GPS_Ebay_Fitment\Repository;

class TecDocCacheRepository
{
    const PROVIDER = 'apify_tecdoc';

    public function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'gps_tecdoc_lookup_cache';
    }

    public function get_valid($normalized_part_number, $provider = self::PROVIDER)
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE normalized_part_number = %s AND provider = %s AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())",
            $normalized_part_number,
            $provider
        ), ARRAY_A);
        return $row ?: null;
    }

    public function upsert($result, $ttl_days)
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + (max(1, (int) $ttl_days) * DAY_IN_SECONDS));
        $data = [
            'part_number' => isset($result['part_number']) ? $result['part_number'] : '',
            'normalized_part_number' => isset($result['normalized_part_number']) ? $result['normalized_part_number'] : '',
            'provider' => isset($result['provider']) ? $result['provider'] : self::PROVIDER,
            'status' => isset($result['status']) ? $result['status'] : '',
            'confidence' => isset($result['confidence']) ? (float) $result['confidence'] : 0,
            'ktype_list_json' => wp_json_encode(isset($result['ktype_list']) ? $result['ktype_list'] : []),
            'ktype_count' => isset($result['ktype_count']) ? (int) $result['ktype_count'] : 0,
            'matched_articles_json' => wp_json_encode(isset($result['matched_articles']) ? $result['matched_articles'] : []),
            'matched_makes_json' => wp_json_encode(isset($result['matched_makes']) ? $result['matched_makes'] : []),
            'matched_models_json' => wp_json_encode(isset($result['matched_models']) ? $result['matched_models'] : []),
            'raw_summary_json' => wp_json_encode(isset($result['raw_summary']) ? $result['raw_summary'] : []),
            'last_error' => isset($result['error']) ? (string) $result['error'] : '',
            'fetched_at' => $now,
            'expires_at' => $expires,
            'updated_at' => $now,
        ];

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table()} WHERE normalized_part_number = %s AND provider = %s",
            $data['normalized_part_number'],
            $data['provider']
        ));

        if ($existing_id) {
            $wpdb->update($this->table(), $data, ['id' => (int) $existing_id]);
            return (int) $existing_id;
        }

        $data['created_at'] = $now;
        $wpdb->insert($this->table(), $data);
        return (int) $wpdb->insert_id;
    }

    public function row_to_result($row)
    {
        return [
            'part_number' => $row['part_number'],
            'normalized_part_number' => $row['normalized_part_number'],
            'provider' => $row['provider'],
            'status' => $row['status'],
            'confidence' => (float) $row['confidence'],
            'ktype_list' => json_decode((string) $row['ktype_list_json'], true) ?: [],
            'ktype_count' => (int) $row['ktype_count'],
            'matched_articles' => json_decode((string) $row['matched_articles_json'], true) ?: [],
            'matched_makes' => json_decode((string) $row['matched_makes_json'], true) ?: [],
            'matched_models' => json_decode((string) $row['matched_models_json'], true) ?: [],
            'raw_summary' => json_decode((string) $row['raw_summary_json'], true) ?: [],
            'error' => $row['last_error'],
            'cache_hit' => true,
        ];
    }
}
