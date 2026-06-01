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
            'active' => 1,
            'sample_product_ids' => '',
            'suggestion_payload' => '',
            'error_reason' => '',
            'updated_at' => $now,
        ], $data);

        $existing = $this->resolveProductionCategoryMapping((int) $row['woo_term_id'], (string) $row['marketplace_id']);
        $incomingSource = (string) $row['source'];
        $manualSources = ['manual', 'manual_woo_category_mapping', 'manual_teaching_csv', 'manual_worklist'];
        if ($existing && in_array((string) ($existing['source'] ?? ''), $manualSources, true) && !in_array($incomingSource, $manualSources, true)) {
            $this->logger->info('Skipped non-manual category mapping upsert because an active manual mapping has priority', [
                'marketplace_id' => (string) $row['marketplace_id'],
                'woo_term_id' => (int) $row['woo_term_id'],
                'incoming_source' => $incomingSource,
                'manual_mapping_id' => (int) ($existing['id'] ?? 0),
            ]);
            return;
        }
        if ($existing) {
            $wpdb->update($table, $row, ['id' => (int) $existing['id']]);
            return;
        }

        $row['created_at'] = $now;
        $wpdb->insert($table, $row);
    }

    public function find(string $marketplace_id, int $woo_term_id): ?array
    {
        return $this->resolveProductionCategoryMapping($woo_term_id, $marketplace_id);
    }

    public function resolveProductionCategoryMapping(int $wooCategoryId, string $marketplaceId = 'EBAY_DE'): ?array
    {
        $rows = $this->list_mapping_rows_for_woo_category($wooCategoryId, $marketplaceId);
        foreach ($rows as $row) {
            if ((int) ($row['resolver_priority'] ?? 99) < 90) {
                $row['resolver_reason'] = $this->resolver_reason_for_row($row);
                return $row;
            }
        }
        return null;
    }

    public function list_mapping_rows_for_woo_category(int $wooCategoryId, string $marketplaceId = 'EBAY_DE'): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *, CASE WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source IN ('manual','manual_woo_category_mapping','manual_teaching_csv') THEN 10 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source='manual_worklist' THEN 20 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source IN ('ovoko_import','supplier_import') THEN 30 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source IN ('import','csv_import','normal_import') THEN 40 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source IN ('rule','auto_taxonomy') THEN 50 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' AND source IN ('legacy','legacy_import') THEN 60 WHEN COALESCE(active,1)=1 AND status NOT LIKE 'disabled%%' AND TRIM(COALESCE(ebay_category_id,''))<>'' THEN 70 ELSE 90 END AS resolver_priority FROM {$table} WHERE marketplace_id=%s AND woo_term_id=%d ORDER BY resolver_priority ASC, COALESCE(NULLIF(reviewed_at, ''), NULLIF(updated_at, ''), created_at) DESC, id DESC",
            $marketplaceId,
            $wooCategoryId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function resolver_reason_for_row(array $row): string
    {
        $priority = (int) ($row['resolver_priority'] ?? 99);
        $source = (string) ($row['source'] ?? '');
        return match ($priority) {
            10 => 'selected active manual mapping by highest production priority',
            20 => 'selected active manual_worklist mapping ahead of imports, rules, legacy and fallback',
            30 => 'selected active ovoko_import/supplier_import mapping because no active manual mapping exists',
            40 => 'selected active normal import mapping because no higher-priority mapping exists',
            50 => 'selected active rule mapping because no higher-priority mapping exists',
            60 => 'selected active legacy mapping because no higher-priority mapping exists',
            70 => 'selected active mapping source ' . $source . ' because no known higher-priority mapping exists',
            default => 'not selected: inactive or disabled mapping',
        };
    }

    public function save_manual_mapping(int $wooCategoryId, string $marketplaceId, array $category): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $now = gmdate('Y-m-d H:i:s');
        $categoryId = trim((string) ($category['category_id'] ?? ''));
        $row = [
            'marketplace_id' => $marketplaceId,
            'woo_term_id' => $wooCategoryId,
            'woo_category_path' => $this->woo_category_path($wooCategoryId),
            'ebay_category_id' => $categoryId,
            'ebay_category_name' => (string) ($category['category_name'] ?? ''),
            'ebay_category_path' => (string) ($category['category_path'] ?? ''),
            'source' => 'manual',
            'confidence' => 1.0,
            'status' => 'mapped_manual',
            'active' => 1,
            'error_reason' => '',
            'reviewed_at' => $now,
            'updated_at' => $now,
        ];

        $existingManual = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table} WHERE marketplace_id=%s AND woo_term_id=%d AND source IN ('manual','manual_woo_category_mapping','manual_teaching_csv','manual_worklist') ORDER BY updated_at DESC, id DESC LIMIT 1",
            $marketplaceId,
            $wooCategoryId
        ), ARRAY_A);
        if (is_array($existingManual)) {
            $wpdb->update($table, $row, ['id' => (int) $existingManual['id']]);
            $selectedId = (int) $existingManual['id'];
        } else {
            $row['created_at'] = $now;
            $wpdb->insert($table, $row);
            $selectedId = (int) $wpdb->insert_id;
        }

        $duplicatesDisabled = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET active=0, status=%s, error_reason=%s, updated_at=%s WHERE marketplace_id=%s AND woo_term_id=%d AND id<>%d AND status NOT LIKE 'disabled%%' AND source NOT IN ('manual','manual_woo_category_mapping','manual_teaching_csv','manual_worklist')",
            'disabled_duplicate',
            'Disabled after manual EBAY_DE category mapping save; resolver uses active manual mapping first.',
            $now,
            $marketplaceId,
            $wooCategoryId,
            $selectedId
        ));

        return [
            'selected_id' => $selectedId,
            'duplicates_disabled' => is_numeric($duplicatesDisabled) ? (int) $duplicatesDisabled : 0,
            'mapping' => $this->resolveProductionCategoryMapping($wooCategoryId, $marketplaceId),
        ];
    }


    public function save_manual_worklist_mapping(int $wooCategoryId, string $marketplaceId, array $category): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_mappings';
        $now = gmdate('Y-m-d H:i:s');
        $categoryId = trim((string) ($category['category_id'] ?? ''));
        $row = [
            'marketplace_id' => $marketplaceId,
            'woo_term_id' => $wooCategoryId,
            'woo_category_path' => $this->woo_category_path($wooCategoryId),
            'ebay_category_id' => $categoryId,
            'ebay_category_name' => (string) ($category['category_name'] ?? ''),
            'ebay_category_path' => (string) ($category['category_path'] ?? ''),
            'source' => 'manual_worklist',
            'confidence' => 1.0,
            'status' => 'mapped_manual',
            'active' => 1,
            'cache_validation_status' => (string) ($category['cache_validation_status'] ?? 'valid_leaf'),
            'validation_confidence' => (string) ($category['validation_confidence'] ?? 'validated_cache'),
            'needs_cache_validation' => !empty($category['needs_cache_validation']) ? 1 : 0,
            'error_reason' => '',
            'reviewed_at' => $now,
            'updated_at' => $now,
        ];

        $existingWorklist = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND woo_term_id=%d AND source=%s ORDER BY COALESCE(NULLIF(reviewed_at, ''), NULLIF(updated_at, ''), created_at) DESC, id DESC LIMIT 1",
            $marketplaceId,
            $wooCategoryId,
            'manual_worklist'
        ), ARRAY_A);

        $operation = 'inserted';
        $unchanged = false;
        if (is_array($existingWorklist)) {
            $selectedId = (int) $existingWorklist['id'];
            $unchanged = trim((string) ($existingWorklist['ebay_category_id'] ?? '')) === $categoryId
                && (string) ($existingWorklist['source'] ?? '') === 'manual_worklist'
                && (string) ($existingWorklist['status'] ?? '') === 'mapped_manual'
                && (int) ($existingWorklist['active'] ?? 1) === 1;
            if ($unchanged) {
                $operation = 'unchanged';
                $wpdb->update($table, ['active' => 1, 'status' => 'mapped_manual', 'cache_validation_status' => (string) ($category['cache_validation_status'] ?? 'valid_leaf'), 'validation_confidence' => (string) ($category['validation_confidence'] ?? 'validated_cache'), 'needs_cache_validation' => !empty($category['needs_cache_validation']) ? 1 : 0, 'reviewed_at' => $now, 'updated_at' => $now, 'error_reason' => ''], ['id' => $selectedId]);
            } else {
                $operation = 'updated';
                $wpdb->update($table, $row, ['id' => $selectedId]);
            }
        } else {
            $row['created_at'] = $now;
            $inserted = $wpdb->insert($table, $row);
            $selectedId = (int) ($wpdb->insert_id ?? 0);
            if (!$inserted || $selectedId <= 0) {
                $existingAny = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$table} WHERE marketplace_id=%s AND woo_term_id=%d ORDER BY COALESCE(NULLIF(reviewed_at, ''), NULLIF(updated_at, ''), created_at) DESC, id DESC LIMIT 1",
                    $marketplaceId,
                    $wooCategoryId
                ), ARRAY_A);
                if (is_array($existingAny)) {
                    $selectedId = (int) $existingAny['id'];
                    $operation = trim((string) ($existingAny['ebay_category_id'] ?? '')) === $categoryId && (string) ($existingAny['source'] ?? '') === 'manual_worklist' ? 'unchanged' : 'updated';
                    $wpdb->update($table, $row, ['id' => $selectedId]);
                }
            }
        }

        $duplicatesDisabled = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET active=0, status=%s, error_reason=%s, updated_at=%s WHERE marketplace_id=%s AND woo_term_id=%d AND id<>%d AND status NOT LIKE 'disabled%%' AND source NOT IN ('manual','manual_woo_category_mapping','manual_teaching_csv')",
            'disabled_duplicate',
            'Disabled after manual_worklist EBAY_DE category mapping import; resolver uses active manual/manual_worklist mappings before legacy/import/rule rows.',
            $now,
            $marketplaceId,
            $wooCategoryId,
            $selectedId
        ));

        return [
            'selected_id' => $selectedId,
            'duplicates_disabled' => is_numeric($duplicatesDisabled) ? (int) $duplicatesDisabled : 0,
            'operation' => $operation,
            'unchanged' => $operation === 'unchanged',
            'mapping' => $this->resolveProductionCategoryMapping($wooCategoryId, $marketplaceId),
        ];
    }


    public function list_manual_mapping_categories(string $marketplaceId = 'EBAY_DE', array $args = []): array
    {
        $rows = $this->list_used_woo_categories($marketplaceId, (int) ($args['limit'] ?? 500));
        $includeSamples = array_key_exists('with_samples', $args) ? !empty($args['with_samples']) : true;
        foreach ($rows as &$row) {
            $termId = (int) ($row['term_id'] ?? 0);
            $resolved = $termId > 0 ? $this->resolveProductionCategoryMapping($termId, $marketplaceId) : null;
            if ($resolved) {
                foreach (['id','ebay_category_id','ebay_category_name','ebay_category_path','source','confidence','status','active','reviewed_at','updated_at','error_reason','sample_product_ids','suggestion_payload','resolver_reason'] as $key) {
                    $row[$key] = $resolved[$key] ?? ($row[$key] ?? '');
                }
                $row['resolver_selected_id'] = (int) ($resolved['id'] ?? 0);
            }
            $row['mapping_rows'] = $termId > 0 ? $this->list_mapping_rows_for_woo_category($termId, $marketplaceId) : [];
            $row['sample_products'] = $includeSamples && $termId > 0 ? $this->sample_products_for_category($termId, 10) : [];
        }
        return $rows;
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

    public function production_mapping_summary(string $marketplaceId = 'EBAY_DE', array $lastImport = [], array $validation = [], array $readiness = []): array
    {
        global $wpdb;
        $terms = $wpdb->terms;
        $termTaxonomy = $wpdb->term_taxonomy;
        $relationships = $wpdb->term_relationships;
        $posts = $wpdb->posts;
        $mappings = $wpdb->prefix . 'wei_ebay_category_mappings';

        $mappedCondition = "TRIM(COALESCE(m.ebay_category_id, '')) <> ''";
        $totalCategories = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$termTaxonomy} tt WHERE tt.taxonomy = 'product_cat'");
        $categoriesWithProducts = (int) $wpdb->get_var("SELECT COUNT(DISTINCT tt.term_id) FROM {$termTaxonomy} tt INNER JOIN {$relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ('publish','draft','private') WHERE tt.taxonomy = 'product_cat'");

        $mappedCategories = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT tt.term_id)
             FROM {$termTaxonomy} tt
             INNER JOIN {$mappings} m ON m.woo_term_id = tt.term_id AND m.marketplace_id = %s
             WHERE tt.taxonomy = 'product_cat' AND {$mappedCondition}",
            $marketplaceId
        ));
        $mappedCategoriesWithProducts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT tt.term_id)
             FROM {$termTaxonomy} tt
             INNER JOIN {$relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$posts} p ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status IN ('publish','draft','private')
             INNER JOIN {$mappings} m ON m.woo_term_id = tt.term_id AND m.marketplace_id = %s
             WHERE tt.taxonomy = 'product_cat' AND {$mappedCondition}",
            $marketplaceId
        ));

        $mappingRowsTotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$mappings}");
        $mappingRowsEbayDe = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$mappings} WHERE marketplace_id = %s", $marketplaceId));
        $mappingRowsWithCategoryId = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$mappings} WHERE marketplace_id = %s AND TRIM(COALESCE(ebay_category_id, '')) <> ''", $marketplaceId));

        $resolvedRowsWithProducts = $this->list_manual_mapping_categories($marketplaceId, ['limit' => 10000, 'with_samples' => false]);
        $resolvedMappedWithProducts = 0;
        foreach ($resolvedRowsWithProducts as $resolvedRow) {
            if (trim((string) ($resolvedRow['ebay_category_id'] ?? '')) !== '') {
                $resolvedMappedWithProducts++;
            }
        }
        $mappedCategoriesWithProducts = $resolvedRowsWithProducts !== [] ? $resolvedMappedWithProducts : $mappedCategoriesWithProducts;

        $statusRows = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(status, ''), '(empty)') AS status, COUNT(*) AS count FROM {$mappings} WHERE marketplace_id = %s GROUP BY COALESCE(NULLIF(status, ''), '(empty)') ORDER BY count DESC, status ASC",
            $marketplaceId
        ), ARRAY_A);
        $statusCounts = [];
        foreach ((array) $statusRows as $row) {
            $statusCounts[(string) ($row['status'] ?? '(empty)')] = (int) ($row['count'] ?? 0);
        }

        $samples = $wpdb->get_results($wpdb->prepare(
            "SELECT m.woo_term_id, t.name AS woo_category_name, m.woo_category_path, m.ebay_category_id, m.ebay_category_name, m.ebay_category_path, m.status, m.source, m.updated_at
             FROM {$mappings} m
             LEFT JOIN {$terms} t ON t.term_id = m.woo_term_id
             WHERE m.marketplace_id = %s AND TRIM(COALESCE(m.ebay_category_id, '')) <> ''
             ORDER BY m.updated_at DESC, m.id DESC
             LIMIT 10",
            $marketplaceId
        ), ARRAY_A);

        $validationByWooTerm = is_array($validation['by_woo_term_id'] ?? null) ? $validation['by_woo_term_id'] : [];
        $validationByCategoryId = is_array($validation['by_category_id'] ?? null) ? $validation['by_category_id'] : [];
        $validationStatus = 'not_run';
        if ($validationByWooTerm !== [] || $validationByCategoryId !== []) {
            $validationStatus = count($validationByWooTerm) >= $mappedCategories && $mappedCategories > 0 ? 'completed' : 'partial';
        }

        $validCategories = 0;
        $invalidCategoryId = 0;
        $nonLeafCategory = 0;
        foreach ($validationByWooTerm as $termValidation) {
            if (!is_array($termValidation)) {
                continue;
            }
            $categoryId = trim((string) ($termValidation['category_id'] ?? ''));
            if ($categoryId === '') {
                continue;
            }
            $isValid = !empty($termValidation['valid']);
            $isLeaf = !empty($termValidation['leaf']);
            if ($isValid && $isLeaf) {
                $validCategories++;
            } elseif (!$isValid) {
                $invalidCategoryId++;
            } else {
                $nonLeafCategory++;
            }
        }

        $blockedByCategory = (int) ($readiness['blocked_by_category'] ?? $readiness['blocked_by_category_total'] ?? 0);
        $needsReview = (int) ($statusCounts['needs_category_review'] ?? 0)
            + (int) ($statusCounts['needs_manual_review'] ?? 0)
            + (int) ($statusCounts['low_confidence_auto'] ?? 0)
            + (int) ($statusCounts['category_sanity_failed'] ?? 0);

        return [
            'marketplace_id' => $marketplaceId,
            'total_categories' => $totalCategories,
            'categories_with_products' => $categoriesWithProducts,
            'mapped_categories' => $mappedCategories,
            'unmapped_categories' => max(0, $totalCategories - $mappedCategories),
            'mapped_categories_with_products' => $mappedCategoriesWithProducts,
            'unmapped_categories_with_products' => max(0, $categoriesWithProducts - $mappedCategoriesWithProducts),
            'validation_status' => $validationStatus,
            'valid_categories' => $validCategories,
            'invalid_category_id' => $invalidCategoryId,
            'non_leaf_category' => $nonLeafCategory,
            'blocked_by_category' => $blockedByCategory,
            'needs_review' => $needsReview,
            'products_affected' => (int) ($readiness['blocked_by_category_products'] ?? $readiness['products_affected'] ?? 0),
            'last_import' => [
                'total_rows' => (int) ($lastImport['total_rows'] ?? 0),
                'updated' => (int) ($lastImport['updated'] ?? 0),
                'inserted' => (int) ($lastImport['inserted'] ?? 0),
                'skipped' => (int) ($lastImport['skipped'] ?? 0),
                'invalid' => (int) ($lastImport['invalid'] ?? 0),
                'imported_at' => (string) ($lastImport['imported_at'] ?? ''),
                'source_csv' => (string) ($lastImport['source_csv'] ?? ''),
            ],
            'mapping_table' => $mappings,
            'mapping_rows_total' => $mappingRowsTotal,
            'mapping_rows_ebay_de' => $mappingRowsEbayDe,
            'mapping_rows_with_category_id' => $mappingRowsWithCategoryId,
            'mapping_status_counts' => $statusCounts,
            'sample_mappings' => is_array($samples) ? $samples : [],
            'diagnostics' => [
                'mapping_table' => $mappings,
                'mapping_rows_total' => $mappingRowsTotal,
                'mapping_rows_ebay_de' => $mappingRowsEbayDe,
                'mapping_rows_with_category_id' => $mappingRowsWithCategoryId,
                'mapping_status_counts' => $statusCounts,
                'sample_mappings' => is_array($samples) ? $samples : [],
            ],
        ];
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
