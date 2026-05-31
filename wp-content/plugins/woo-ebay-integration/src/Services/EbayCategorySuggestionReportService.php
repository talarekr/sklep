<?php

namespace WEI\Services;

use WEI\Repositories\CategoryMappingRepository;

class EbayCategorySuggestionReportService
{
    public const MARKETPLACE_ID = 'EBAY_DE';
    public const VALIDATION_OPTION = 'wei_ebay_category_validation_statuses';
    public const LAST_SUMMARY_OPTION = 'wei_ebay_category_suggestions_summary';
    public const CHECKPOINT_OPTION = 'wei_ebay_category_suggestions_checkpoint';

    public function __construct(private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }

    public function generate(array $args = []): array
    {
        $marketplaceId = self::MARKETPLACE_ID;
        $limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $sampleLimit = max(1, min(5, (int) ($args['sample_limit'] ?? 5)));
        $topLimit = max(3, min(5, (int) ($args['top_limit'] ?? 5)));
        $mode = in_array((string) ($args['mode'] ?? 'leaf_with_products'), ['leaf_with_products', 'with_products', 'all_categories'], true) ? (string) $args['mode'] : 'leaf_with_products';
        $forceRefresh = !empty($args['force_refresh']);
        $restart = !empty($args['restart']);

        if ($restart) {
            delete_option(self::CHECKPOINT_OPTION);
        }
        $checkpoint = get_option(self::CHECKPOINT_OPTION, []);
        $offset = max(0, (int) ($args['offset'] ?? ($checkpoint['offset'] ?? 0)));

        $tree = $this->taxonomy->get_default_category_tree_id_result($marketplaceId, $forceRefresh);
        $categoryTreeId = (string) ($tree['category_tree_id'] ?? '');
        $rows = $this->categoryRepo->list_woo_categories_for_suggestions($marketplaceId, $limit, $offset, $mode);

        $summary = [
            'marketplace_id' => $marketplaceId,
            'category_tree_id' => $categoryTreeId,
            'woo_categories_processed' => 0,
            'mappings_with_current_id' => 0,
            'valid_current_mappings' => 0,
            'invalid_current_mappings' => 0,
            'non_leaf_current_mappings' => 0,
            'suggestions_found' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'needs_manual_review' => 0,
            'api_errors' => 0,
            'batch' => ['limit' => $limit, 'offset' => $offset, 'next_offset' => $offset + count($rows), 'mode' => $mode, 'has_more' => count($rows) === $limit],
            'reports' => ['suggestions_csv' => ['path' => '', 'url' => ''], 'ready_to_import_csv' => ['path' => '', 'url' => '']],
        ];

        $reportRows = [];
        $readyRows = [];
        $existingValidation = get_option(self::VALIDATION_OPTION, []);
        $existingValidation = is_array($existingValidation) ? $existingValidation : [];
        $validationByCategoryId = is_array($existingValidation['by_category_id'] ?? null) ? $existingValidation['by_category_id'] : [];
        $validationByWooTerm = is_array($existingValidation['by_woo_term_id'] ?? null) ? $existingValidation['by_woo_term_id'] : [];

        foreach ($rows as $row) {
            $summary['woo_categories_processed']++;
            $termId = (int) ($row['term_id'] ?? 0);
            $currentId = trim((string) ($row['ebay_category_id'] ?? ''));
            $samples = $this->categoryRepo->sample_products_for_category($termId, $sampleLimit);
            $queries = self::build_queries((string) ($row['name'] ?? ''), (string) ($row['woo_category_path'] ?? ''), $samples);
            $taxonomyError = '';
            $chosenQuery = '';
            $suggestions = [];

            foreach ($queries as $query) {
                $result = $this->taxonomy->get_category_suggestions_result($marketplaceId, $query, $forceRefresh);
                if (($result['status'] ?? '') !== 'ok') {
                    $taxonomyError = trim($taxonomyError . ' ' . (string) ($result['error'] ?? $result['status'] ?? 'suggestion_failed'));
                    $summary['api_errors']++;
                    continue;
                }
                $parsed = self::parse_suggestions((array) ($result['suggestions'] ?? []), $topLimit);
                if ($parsed !== []) {
                    $suggestions = $parsed;
                    $chosenQuery = $query;
                    break;
                }
            }

            $currentValidation = $this->validate_current_category($marketplaceId, $currentId, $forceRefresh);
            if ($currentId !== '') {
                $summary['mappings_with_current_id']++;
                $validationByCategoryId[$currentId] = $currentValidation;
            }
            $validationByWooTerm[(string) $termId] = $currentValidation + [
                'woo_term_id' => $termId,
                'woo_category_path' => (string) ($row['woo_category_path'] ?? ''),
            ];

            if (!empty($currentValidation['valid']) && !empty($currentValidation['leaf'])) {
                $summary['valid_current_mappings']++;
            } elseif ($currentId !== '' && empty($currentValidation['valid'])) {
                $summary['invalid_current_mappings']++;
            } elseif ($currentId !== '' && empty($currentValidation['leaf'])) {
                $summary['non_leaf_current_mappings']++;
            }

            if ($suggestions !== []) {
                $summary['suggestions_found']++;
            }

            $status = self::mapping_status($currentId, $currentValidation, $suggestions);
            $confidence = self::confidence($currentId, $currentValidation, $suggestions, $status);
            if ($confidence === 'high') {
                $summary['high_confidence']++;
            } elseif ($confidence === 'medium') {
                $summary['medium_confidence']++;
            } elseif ($confidence === 'low') {
                $summary['low_confidence']++;
            } else {
                $summary['needs_manual_review']++;
            }

            $best = $suggestions[0] ?? [];
            $report = $this->build_report_row($row, $samples, $currentValidation, $status, $suggestions, $best, $confidence, $chosenQuery, $taxonomyError);
            $reportRows[] = $report;
            $readyRows[] = $this->build_ready_row($report, $currentId, $best);
        }

        $paths = $this->write_reports($reportRows, $readyRows);
        $summary['reports'] = $paths;
        update_option(self::VALIDATION_OPTION, ['by_category_id' => $validationByCategoryId, 'by_woo_term_id' => $validationByWooTerm, 'updated_at' => gmdate('c'), 'marketplace_id' => $marketplaceId, 'category_tree_id' => $categoryTreeId], false);
        update_option(self::LAST_SUMMARY_OPTION, $summary, false);
        update_option(self::CHECKPOINT_OPTION, ['offset' => $summary['batch']['next_offset'], 'updated_at' => gmdate('c'), 'mode' => $mode], false);

        return $summary;
    }

    public static function build_queries(string $wooName, string $wooPath, array $samples = []): array
    {
        $queries = [];
        foreach ([$wooName, str_replace(' > ', ' ', $wooPath)] as $query) {
            $query = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($query)));
            if ($query !== '') {
                $queries[] = $query;
            }
        }

        $normalized = strtolower(remove_accents($wooName . ' ' . $wooPath));
        if (str_contains($normalized, 'klimatyzacji') || str_contains($normalized, 'klima') || str_contains($normalized, 'a/c')) {
            array_push($queries, 'Klimaanlagenschlauch', 'Klimaleitung', 'Klimaleitungen Schläuche Anschlüsse Auto');
        }

        foreach (array_slice($samples, 0, 3) as $sample) {
            $title = trim((string) ($sample['translated_title'] ?? $sample['title'] ?? ''));
            $manufacturer = trim((string) ($sample['manufacturer'] ?? ''));
            $mpn = trim((string) ($sample['mpn'] ?? ''));
            $query = trim($wooName . ' ' . $title . ' ' . $manufacturer . ' ' . $mpn . ' Autoteile');
            $query = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($query)));
            if ($query !== '') {
                $queries[] = mb_substr($query, 0, 300);
            }
        }

        return array_values(array_unique(array_filter($queries)));
    }

    public static function parse_suggestions(array $rawSuggestions, int $limit = 5): array
    {
        $parsed = [];
        foreach ($rawSuggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
            $id = trim((string) ($category['categoryId'] ?? $suggestion['categoryId'] ?? ''));
            $name = trim((string) ($category['categoryName'] ?? $suggestion['categoryName'] ?? ''));
            if ($id === '') {
                continue;
            }
            $pathNames = [];
            foreach ((array) ($suggestion['categoryTreeNodeAncestors'] ?? $suggestion['ancestors'] ?? []) as $ancestor) {
                if (!is_array($ancestor)) {
                    continue;
                }
                $ancestorName = trim((string) ($ancestor['categoryName'] ?? $ancestor['category']['categoryName'] ?? ''));
                if ($ancestorName !== '') {
                    $pathNames[] = $ancestorName;
                }
            }
            if ($name !== '') {
                $pathNames[] = $name;
            }
            $parsed[] = [
                'category_id' => $id,
                'category_name' => $name,
                'category_path' => $pathNames !== [] ? implode(' > ', array_values(array_unique($pathNames))) : $name,
                'score' => isset($suggestion['relevancy']) ? (string) $suggestion['relevancy'] : (isset($suggestion['score']) ? (string) $suggestion['score'] : ''),
                'raw' => $suggestion,
            ];
        }
        return array_slice($parsed, 0, max(1, $limit));
    }

    public static function mapping_status(string $currentId, array $validation, array $suggestions): string
    {
        if ($currentId !== '' && (empty($validation['valid']) || empty($validation['leaf']))) {
            return 'invalid_current';
        }
        $topIds = array_map(static fn(array $row): string => (string) ($row['category_id'] ?? ''), array_slice($suggestions, 0, 3));
        if ($currentId !== '' && in_array($currentId, $topIds, true) && !empty($validation['valid']) && !empty($validation['leaf'])) {
            return 'likely_ok';
        }
        if ($suggestions !== [] && $currentId !== (string) ($suggestions[0]['category_id'] ?? '')) {
            return 'review_suggested';
        }
        return 'needs_manual_review';
    }

    public static function confidence(string $currentId, array $validation, array $suggestions, string $status): string
    {
        if ($status === 'likely_ok' || ($status === 'review_suggested' && $suggestions !== [] && empty($validation['valid']))) {
            return 'high';
        }
        if ($suggestions !== [] && $status === 'review_suggested') {
            return 'medium';
        }
        if ($suggestions !== []) {
            return 'low';
        }
        return 'manual_review';
    }

    public static function suggestion_report_columns(): array
    {
        $columns = ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','woo_category_slug','woo_category_url','products_count','current_ebay_category_id','current_ebay_category_valid','current_ebay_category_leaf','current_ebay_category_path','mapping_status'];
        for ($i = 1; $i <= 3; $i++) {
            array_push($columns, "suggested_ebay_category_id_{$i}", "suggested_ebay_category_name_{$i}", "suggested_ebay_category_path_{$i}", "suggested_ebay_category_score_{$i}");
        }
        return array_merge($columns, ['suggested_ebay_category_id','suggested_ebay_category_path','confidence','sample_product_ids','sample_product_titles','query_used','taxonomy_error','note']);
    }

    public static function ready_to_import_columns(): array
    {
        return ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','products_count','old_ebay_category_id','ebay_category_id','suggested_ebay_category_path','confidence','mapping_status','note'];
    }

    private function validate_current_category(string $marketplaceId, string $categoryId, bool $forceRefresh): array
    {
        if ($categoryId === '') {
            return ['category_id' => '', 'valid' => false, 'leaf' => false, 'category_path' => '', 'validation_status' => 'missing'];
        }
        $details = $this->taxonomy->validate_category_result($marketplaceId, $categoryId, $forceRefresh);
        return $details;
    }

    private function build_report_row(array $row, array $samples, array $currentValidation, string $status, array $suggestions, array $best, string $confidence, string $query, string $error): array
    {
        $parentId = (int) ($row['parent'] ?? 0);
        $sampleIds = array_map(static fn(array $sample): string => (string) ($sample['id'] ?? ''), $samples);
        $sampleTitles = array_map(static fn(array $sample): string => (string) ($sample['translated_title'] ?? $sample['title'] ?? ''), $samples);
        $report = array_fill_keys(self::suggestion_report_columns(), '');
        $report['woo_subcategory_id'] = (string) ($row['term_id'] ?? '');
        $report['woo_category_id'] = (string) ($parentId > 0 ? $parentId : ($row['term_id'] ?? ''));
        $report['woo_subcategory_name'] = (string) ($row['name'] ?? '');
        $report['woo_category_path'] = (string) ($row['woo_category_path'] ?? '');
        $report['woo_category_slug'] = (string) ($row['slug'] ?? '');
        $termLink = function_exists('get_term_link') ? get_term_link((int) ($row['term_id'] ?? 0), 'product_cat') : '';
        $report['woo_category_url'] = is_wp_error($termLink) ? '' : (string) $termLink;
        $report['products_count'] = (string) ($row['product_count'] ?? 0);
        $report['current_ebay_category_id'] = (string) ($row['ebay_category_id'] ?? '');
        $report['current_ebay_category_valid'] = !empty($currentValidation['valid']) ? '1' : '0';
        $report['current_ebay_category_leaf'] = !empty($currentValidation['leaf']) ? '1' : '0';
        $report['current_ebay_category_path'] = (string) ($currentValidation['category_path'] ?? '');
        $report['mapping_status'] = $status;
        foreach (array_slice($suggestions, 0, 3) as $idx => $suggestion) {
            $n = $idx + 1;
            $report["suggested_ebay_category_id_{$n}"] = (string) ($suggestion['category_id'] ?? '');
            $report["suggested_ebay_category_name_{$n}"] = (string) ($suggestion['category_name'] ?? '');
            $report["suggested_ebay_category_path_{$n}"] = (string) ($suggestion['category_path'] ?? '');
            $report["suggested_ebay_category_score_{$n}"] = (string) ($suggestion['score'] ?? '');
        }
        $report['suggested_ebay_category_id'] = (string) ($best['category_id'] ?? '');
        $report['suggested_ebay_category_path'] = (string) ($best['category_path'] ?? '');
        $report['confidence'] = $confidence;
        $report['sample_product_ids'] = implode('|', array_filter($sampleIds));
        $report['sample_product_titles'] = implode(' | ', array_filter($sampleTitles));
        $report['query_used'] = $query;
        $report['taxonomy_error'] = trim($error);
        $report['note'] = $status === 'invalid_current' ? 'Current eBay category is invalid/not found or non-leaf for EBAY_DE; review before import.' : 'Suggestion only; no production mapping was changed.';
        return $report;
    }

    private function build_ready_row(array $report, string $currentId, array $best): array
    {
        return [
            'woo_subcategory_id' => $report['woo_subcategory_id'],
            'woo_category_id' => $report['woo_category_id'],
            'woo_subcategory_name' => $report['woo_subcategory_name'],
            'woo_category_path' => $report['woo_category_path'],
            'products_count' => $report['products_count'],
            'old_ebay_category_id' => $currentId,
            'ebay_category_id' => (string) ($best['category_id'] ?? ''),
            'suggested_ebay_category_path' => (string) ($best['category_path'] ?? ''),
            'confidence' => $report['confidence'],
            'mapping_status' => $report['mapping_status'],
            'note' => $report['note'],
        ];
    }

    private function write_reports(array $reportRows, array $readyRows): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) $upload['basedir']) . 'woo-ebay-integration/category-suggestions';
        wp_mkdir_p($baseDir);
        $stamp = gmdate('Ymd-His');
        $suggestionsPath = $baseDir . '/ebay_de_category_suggestions_' . $stamp . '.csv';
        $readyPath = $baseDir . '/ready_to_import_suggestions.csv';
        $this->write_csv($suggestionsPath, self::suggestion_report_columns(), $reportRows);
        $this->write_csv($readyPath, self::ready_to_import_columns(), $readyRows);
        $baseUrl = trailingslashit((string) $upload['baseurl']) . 'woo-ebay-integration/category-suggestions';
        return [
            'suggestions_csv' => ['path' => $suggestionsPath, 'url' => $baseUrl . '/' . basename($suggestionsPath)],
            'ready_to_import_csv' => ['path' => $readyPath, 'url' => $baseUrl . '/' . basename($readyPath)],
        ];
    }

    private function write_csv(string $path, array $columns, array $rows): void
    {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Unable to write CSV: ' . $path);
        }
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), $columns));
        }
        fclose($fh);
    }
}
