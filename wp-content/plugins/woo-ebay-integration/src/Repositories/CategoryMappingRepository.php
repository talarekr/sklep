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
            'status' => 'mapped_manual',
            'sample_product_ids' => '',
            'suggestion_payload' => '',
            'error_reason' => '',
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
                    m.ebay_category_id, m.ebay_category_name, m.ebay_category_path, m.source, m.confidence, m.status, m.updated_at, m.error_reason, m.sample_product_ids, m.suggestion_payload
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


    public function list_woo_categories_for_suggestions(string $marketplace_id = 'EBAY_DE', int $limit = 50, int $offset = 0, string $mode = 'leaf_with_products'): array
    {
        global $wpdb;
        $terms = $wpdb->terms;
        $termTaxonomy = $wpdb->term_taxonomy;
        $relationships = $wpdb->term_relationships;
        $posts = $wpdb->posts;
        $mappings = $wpdb->prefix . 'wei_ebay_category_mappings';

        $limit = max(1, min(10000, $limit));
        $offset = max(0, $offset);
        $where = ["tt.taxonomy = 'product_cat'"];
        if ($mode === 'leaf_with_products') {
            $where[] = "NOT EXISTS (SELECT 1 FROM {$termTaxonomy} child_tt WHERE child_tt.taxonomy = 'product_cat' AND child_tt.parent = t.term_id)";
            $where[] = "COUNT(DISTINCT p.ID) > 0";
        } elseif ($mode === 'with_products') {
            $where[] = "COUNT(DISTINCT p.ID) > 0";
        }

        $having = '';
        $plainWhere = [];
        foreach ($where as $clause) {
            if (str_contains($clause, 'COUNT(')) {
                $having = 'HAVING ' . $clause;
            } else {
                $plainWhere[] = $clause;
            }
        }

        $sql = $wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.parent, COUNT(DISTINCT p.ID) AS product_count,
                    m.ebay_category_id, m.ebay_category_name, m.ebay_category_path, m.source, m.confidence, m.status, m.updated_at, m.error_reason, m.sample_product_ids, m.suggestion_payload
             FROM {$terms} t
             INNER JOIN {$termTaxonomy} tt ON tt.term_id = t.term_id
             LEFT JOIN {$relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             LEFT JOIN {$posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ('publish','draft','private')
             LEFT JOIN {$mappings} m ON m.woo_term_id = t.term_id AND m.marketplace_id = %s
             WHERE " . implode(' AND ', $plainWhere) . "
             GROUP BY t.term_id
             {$having}
             ORDER BY product_count DESC, t.name ASC
             LIMIT %d OFFSET %d",
            $marketplace_id,
            $limit,
            $offset
        );

        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ((array) $rows as &$row) {
            $row['woo_category_path'] = $this->woo_category_path((int) $row['term_id']);
        }

        return is_array($rows) ? $rows : [];
    }

    public function sample_products_for_category(int $term_id, int $limit = 5): array
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => max(1, min(10, $limit)),
            'fields' => 'ids',
            'tax_query' => [[
                'taxonomy' => 'product_cat',
                'field' => 'term_id',
                'terms' => [$term_id],
                'include_children' => false,
            ]],
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);

        $samples = [];
        foreach ((array) $query->posts as $product_id) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) $product_id) : null;
            if (!$product) {
                continue;
            }
            $samples[] = [
                'id' => (int) $product_id,
                'title' => (string) $product->get_name(),
                'translated_title' => trim((string) get_post_meta((int) $product_id, '_wei_ebay_de_title', true)),
                'description' => trim(wp_strip_all_tags((string) $product->get_description() . ' ' . (string) $product->get_short_description())),
                'mpn' => $this->product_meta_or_attribute($product, (int) $product_id, ['_mpn', 'mpn', '_part_number', 'part_number', '_oem_number', 'oem_number', '_oe_number'], ['MPN', 'Herstellernummer', 'OEM', 'Numer części', 'Numer czesci']),
                'manufacturer' => $this->product_meta_or_attribute($product, (int) $product_id, ['_manufacturer', '_brand'], ['Producent', 'Marka', 'Manufacturer', 'Brand']),
            ];
        }

        return $samples;
    }

    private function product_meta_or_attribute($product, int $product_id, array $meta_keys, array $attributes): string
    {
        foreach ($meta_keys as $meta_key) {
            $value = trim((string) get_post_meta($product_id, (string) $meta_key, true));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ($attributes as $attribute) {
            if (!method_exists($product, 'get_attribute')) {
                continue;
            }
            $value = trim(wp_strip_all_tags((string) $product->get_attribute((string) $attribute)));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }


    public function upsert_teaching_rule(array $data): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $now = gmdate('Y-m-d H:i:s');
        $wooPath = trim((string) ($data['woo_category_path'] ?? ''));
        $row = array_merge([
            'marketplace_id' => 'EBAY_DE',
            'woo_category_path_hash' => hash('sha256', $this->normalize_rule_text($wooPath)),
            'woo_category_path' => $wooPath,
            'detected_intent' => '',
            'title_keyword_family' => '',
            'ebay_category_id' => '',
            'ebay_category_path' => '',
            'source' => 'manual_woo_category_mapping',
            'rule_note' => '',
            'import_group_id' => '',
            'sample_product_ids' => '',
            'updated_at' => $now,
        ], $data);
        $row['woo_category_path_hash'] = hash('sha256', $this->normalize_rule_text((string) $row['woo_category_path']));
        $row['detected_intent'] = trim((string) $row['detected_intent']);
        $row['title_keyword_family'] = $this->normalize_keyword_family((string) $row['title_keyword_family']);

        $source = (string) $row['source'];
        if ($source === 'manual_woo_category_mapping') {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE marketplace_id=%s AND woo_category_path_hash=%s ORDER BY updated_at DESC LIMIT 1",
                (string) $row['marketplace_id'],
                (string) $row['woo_category_path_hash']
            ), ARRAY_A);
        } else {
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE marketplace_id=%s AND woo_category_path_hash=%s AND detected_intent=%s AND title_keyword_family=%s LIMIT 1",
                (string) $row['marketplace_id'],
                (string) $row['woo_category_path_hash'],
                (string) $row['detected_intent'],
                (string) $row['title_keyword_family']
            ), ARRAY_A);
        }
        if (is_array($existing)) {
            $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
            return 'updated';
        }

        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
        return 'inserted';
    }

    public function find_teaching_rule(string $marketplaceId, string $wooCategoryPath, string $detectedIntent = '', string $title = '', string $keywordFamily = ''): ?array
    {
        $manualRule = $this->find_manual_woo_category_rule($marketplaceId, $wooCategoryPath, $title, $keywordFamily);
        if ($manualRule) {
            return $manualRule;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $pathHash = hash('sha256', $this->normalize_rule_text($wooCategoryPath));
        $intent = trim($detectedIntent);
        $families = array_values(array_unique([
            $this->normalize_keyword_family($keywordFamily),
            $this->keyword_family_from_title($title),
            '',
        ]));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND woo_category_path_hash=%s AND source<>%s ORDER BY updated_at DESC",
            $marketplaceId,
            $pathHash,
            'manual_woo_category_mapping'
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        if ($rows === []) {
            return null;
        }

        if (count($rows) === 1) {
            return $rows[0];
        }

        foreach ($families as $family) {
            foreach ($rows as $row) {
                if ((string) ($row['detected_intent'] ?? '') === $intent && (string) ($row['title_keyword_family'] ?? '') === $family) {
                    return $row;
                }
            }
        }

        if ($intent !== '') {
            foreach ($families as $family) {
                foreach ($rows as $row) {
                    if ((string) ($row['detected_intent'] ?? '') === '' && (string) ($row['title_keyword_family'] ?? '') === $family) {
                        return $row;
                    }
                }
            }
        }

        foreach ($families as $family) {
            if ($family === '') {
                continue;
            }
            foreach ($rows as $row) {
                if ((string) ($row['title_keyword_family'] ?? '') === $family) {
                    return $row;
                }
            }
        }

        foreach ($rows as $row) {
            if ((string) ($row['detected_intent'] ?? '') === '' && (string) ($row['title_keyword_family'] ?? '') === '') {
                return $row;
            }
        }

        return $rows[0];
    }

    public function find_manual_woo_category_rule(string $marketplaceId, string $wooCategoryPath, string $title = '', string $keywordFamily = ''): ?array
    {
        $rows = $this->manual_woo_category_rules_for_path($marketplaceId, $wooCategoryPath);
        if ($rows === []) {
            return null;
        }
        if (count($rows) === 1) {
            return $rows[0];
        }

        $families = array_values(array_unique(array_filter([
            $this->normalize_keyword_family($keywordFamily),
            $this->keyword_family_from_title($title),
        ], static fn(string $family): bool => $family !== '')));

        foreach ($families as $family) {
            foreach ($rows as $row) {
                if ((string) ($row['title_keyword_family'] ?? '') === $family) {
                    return $row;
                }
            }
        }

        foreach ($rows as $row) {
            if ((string) ($row['title_keyword_family'] ?? '') === '') {
                return $row;
            }
        }

        return $rows[0];
    }

    public function manual_woo_category_rules_for_path(string $marketplaceId, string $wooCategoryPath): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND woo_category_path_hash=%s AND source=%s AND ebay_category_id<>'' ORDER BY updated_at DESC",
            $marketplaceId,
            $this->woo_category_path_hash($wooCategoryPath),
            'manual_woo_category_mapping'
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function list_manual_woo_category_rules(string $marketplaceId = 'EBAY_DE', int $limit = 1000): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND source=%s AND ebay_category_id<>'' ORDER BY woo_category_path_hash ASC, updated_at DESC LIMIT %d",
            $marketplaceId,
            'manual_woo_category_mapping',
            max(1, min(5000, $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function nearest_teaching_rules(string $marketplaceId, string $wooCategoryPath = '', string $detectedIntent = '', int $limit = 10): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $limit = max(1, min(20, $limit));
        $pathHash = $wooCategoryPath !== '' ? $this->woo_category_path_hash($wooCategoryPath) : '';
        $intent = trim($detectedIntent);
        if ($pathHash !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *, 'same_woo_category_path' AS nearest_reason FROM {$table} WHERE marketplace_id=%s AND woo_category_path_hash=%s ORDER BY updated_at DESC LIMIT %d",
                $marketplaceId,
                $pathHash,
                $limit
            ), ARRAY_A);
            if (is_array($rows) && count($rows) >= $limit) {
                return $rows;
            }
            $found = is_array($rows) ? $rows : [];
        } else {
            $found = [];
        }
        if ($intent !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT *, 'same_detected_intent' AS nearest_reason FROM {$table} WHERE marketplace_id=%s AND detected_intent=%s ORDER BY updated_at DESC LIMIT %d",
                $marketplaceId,
                $intent,
                $limit
            ), ARRAY_A);
            foreach ((array) $rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $already = false;
                foreach ($found as $existing) {
                    if ((int) ($existing['id'] ?? 0) === $id) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $found[] = $row;
                }
                if (count($found) >= $limit) {
                    break;
                }
            }
        }
        return array_slice($found, 0, $limit);
    }

    public function list_teaching_rules(string $marketplaceId = 'EBAY_DE', int $limit = 200): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_teaching_rules';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s ORDER BY updated_at DESC LIMIT %d",
            $marketplaceId,
            max(1, min(500, $limit))
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function keyword_family_from_title(string $title): string
    {
        $normalized = $this->normalize_rule_text($title);
        $families = [
            'speaker' => ['glosnik', 'glosniki', 'speaker', 'lautsprecher'],
            'interior_trim' => ['listwa', 'dekor', 'dekoracyjna', 'kokpit', 'konsola', 'trim', 'zierleiste'],
            'power_steering_hose' => ['servolenkung', 'wspomagania', 'przewod', 'przewodow', 'leitung', 'schlauch'],
            'gearbox_mount' => ['poduszka', 'lapa', 'getriebe', 'skrzyni', 'lager'],
            'engine_bearing' => ['panewka', 'panewki', 'bearing', 'hauptlager', 'pleuel'],
            'wiring_harness' => ['wiazka', 'wiazki', 'kabel', 'kabelbaum', 'leitungssatz'],
            'ac_hose' => ['klima', 'klimatyzacji', 'klimaleitung', 'kaeltemittel', 'przewod'],
        ];
        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($normalized, $needle)) {
                    return $family;
                }
            }
        }
        return '';
    }

    public function normalize_keyword_family(string $family): string
    {
        $family = strtolower(remove_accents(trim($family)));
        $family = preg_replace('/[^a-z0-9_-]+/', '_', $family) ?: '';
        return trim($family, '_-');
    }

    public function normalize_rule_text(string $text): string
    {
        $text = strtolower(remove_accents(wp_strip_all_tags($text)));
        $text = str_replace(['ß', 'ü', 'ö', 'ä'], ['ss', 'u', 'o', 'a'], $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    public function woo_category_path_hash(string $wooCategoryPath): string
    {
        return hash('sha256', $this->normalize_rule_text($wooCategoryPath));
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
