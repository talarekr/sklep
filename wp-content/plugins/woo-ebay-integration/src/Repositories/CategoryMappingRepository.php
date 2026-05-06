<?php

namespace WEI\Repositories;

use WEI\Services\Logger;

class CategoryMappingRepository
{
    public function __construct(private Logger $logger)
    {
    }

    public function upsert(array $data): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $now = gmdate('Y-m-d H:i:s');
        $row = array_merge([
            'marketplace_id' => 'EBAY_DE',
            'woo_term_id' => 0,
            'woo_category_path' => '',
            'ebay_category_id' => '',
            'ebay_category_name' => '',
            'ebay_category_path' => '',
            'source' => 'manual',
            'confidence' => 1.0,
            'updated_at' => $now,
        ], $data);

        $existing = $this->find((string) $row['marketplace_id'], (int) $row['woo_term_id']);
        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
            return;
        }

        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
    }

    public function find(string $marketplace_id, int $woo_term_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND woo_term_id=%d LIMIT 1",
            $marketplace_id,
            $woo_term_id
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function list_used_woo_categories(string $marketplace_id = 'EBAY_DE', int $limit = 200): array
    {
        global $wpdb;
        $terms = $wpdb->terms;
        $termTaxonomy = $wpdb->term_taxonomy;
        $relationships = $wpdb->term_relationships;
        $posts = $wpdb->posts;
        $mappings = $wpdb->prefix . 'wei_ebay_category_mappings';

        $sql = $wpdb->prepare(
            "SELECT t.term_id, t.name, tt.parent, COUNT(DISTINCT p.ID) AS product_count,
                    m.ebay_category_id, m.ebay_category_name, m.ebay_category_path, m.source, m.confidence, m.updated_at
             FROM {$terms} t
             INNER JOIN {$termTaxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat'
             INNER JOIN {$relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ('publish','draft','private')
             LEFT JOIN {$mappings} m ON m.woo_term_id = t.term_id AND m.marketplace_id = %s
             GROUP BY t.term_id
             ORDER BY product_count DESC, t.name ASC
             LIMIT %d",
            $marketplace_id,
            $limit
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['woo_category_path'] = $this->woo_category_path((int) $row['term_id']);
        }

        return is_array($rows) ? $rows : [];
    }

    public function woo_category_path(int $term_id): string
    {
        $ancestors = array_reverse(get_ancestors($term_id, 'product_cat'));
        $ids = array_merge($ancestors, [$term_id]);
        $names = [];
        foreach ($ids as $id) {
            $term = get_term((int) $id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $names[] = (string) $term->name;
            }
        }

        return implode(' > ', $names);
    }
}
