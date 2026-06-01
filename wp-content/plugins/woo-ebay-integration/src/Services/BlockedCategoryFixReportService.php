<?php

namespace WEI\Services;

use WEI\Repositories\CategoryMappingRepository;

class BlockedCategoryFixReportService
{
    public const RECOMMENDATIONS_FILENAME = 'blocked_category_mapping_recommendations.csv';
    public const FIX_IMPORT_FILENAME = 'blocked_category_mapping_fix_import.csv';
    public const CATEGORY_MAPPING_WORKLIST_FILENAME = 'category-mapping-worklist.csv';

    public function __construct(private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }

    public function generate_from_audit(string $problemsCsv, string $marketplaceId = 'EBAY_DE'): array
    {
        $rows = $this->read_csv_assoc($problemsCsv);
        $mapper = new EbayDeCategoryRuleMapper();
        $recommendationRows = [];
        $fixRowsByWooTerm = [];
        $blockedRows = 0;
        $recommendedProducts = 0;
        $highConfidenceProducts = 0;
        $recommendedCategories = [];
        $highConfidenceCategories = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== 'blocked_by_category') {
                continue;
            }
            $blockedRows++;
            $wooPath = trim((string) ($row['woo_category_path'] ?? ''));
            $productTitle = trim((string) ($row['title'] ?? $row['product_title'] ?? ''));
            $wooTermId = $this->woo_term_id_for_row($row, $wooPath);
            $wooTerm = $wooTermId > 0 ? get_term($wooTermId, 'product_cat') : null;
            $wooName = is_object($wooTerm) && isset($wooTerm->name) ? (string) $wooTerm->name : $this->last_path_part($wooPath);
            $currentCategoryId = (string) ($row['current_ebay_category_id'] ?? '');
            $currentPath = (string) ($row['current_ebay_category_path'] ?? '');

            $recommendation = $mapper->recommend([
                'woo_subcategory_name' => $wooName,
                'woo_category_path' => $wooPath,
                'product_title' => $productTitle,
                'translated_title' => (string) ($row['translated_title'] ?? ''),
                'sample_product_data' => (string) ($row['top_3_candidates_json'] ?? ''),
            ]);
            $validation = $mapper->validate_recommendation($recommendation, $this->taxonomy, $marketplaceId);
            $apply = !empty($validation['apply_candidate']);
            $confidence = (float) ($recommendation['confidence'] ?? 0);
            $mappingStatus = $apply ? 'review_suggested' : 'needs_manual_review';
            $confidenceLabel = $apply ? 'high' : ($confidence >= EbayDeCategoryRuleMapper::HIGH_CONFIDENCE_THRESHOLD ? 'medium' : 'low');
            if ((string) ($recommendation['recommended_ebay_category_id'] ?? '') !== '') {
                $recommendedProducts++;
                if ($wooTermId > 0) {
                    $recommendedCategories[$wooTermId] = true;
                }
            }
            if ($apply) {
                $highConfidenceProducts++;
                if ($wooTermId > 0) {
                    $highConfidenceCategories[$wooTermId] = true;
                }
            }

            $note = '';
            $exclusionReason = '';
            if (!$apply) {
                $exclusionReason = $this->exclusion_reason($recommendation, $validation, $wooTermId, $confidence);
            }
            if ($wooTermId <= 0) {
                $note = 'woo_category_id_not_resolved_from_audit_row';
            } elseif (!$apply && $confidence >= EbayDeCategoryRuleMapper::HIGH_CONFIDENCE_THRESHOLD) {
                $note = 'high_confidence_but_taxonomy_or_sanity_validation_failed';
            } elseif (!$apply) {
                $note = 'manual_review_required_low_confidence_or_validation';
            }

            $recommendationRows[] = [
                'product_id' => (string) ($row['product_id'] ?? ''),
                'product_title' => $productTitle,
                'woo_category_id' => (string) $wooTermId,
                'woo_category_path' => $wooPath,
                'current_ebay_category_id' => $currentCategoryId,
                'current_ebay_category_path' => $currentPath,
                'detected_intent' => (string) ($recommendation['detected_intent'] ?? $row['detected_intent'] ?? ''),
                'sanity_reason' => (string) ($row['reason'] ?? ''),
                'recommended_ebay_category_id' => (string) ($recommendation['recommended_ebay_category_id'] ?? ''),
                'recommended_ebay_category_path' => (string) ($recommendation['recommended_ebay_category_path'] ?? ''),
                'recommendation_confidence' => $confidence > 0 ? sprintf('%.4F', $confidence) : '',
                'confidence' => $confidenceLabel,
                'mapping_status' => $mappingStatus,
                'recommendation_reason' => (string) ($recommendation['decision_reason'] ?? ''),
                'taxonomy_validation_status' => (string) ($validation['status'] ?? $validation['validation_status'] ?? ''),
                'apply_candidate' => $apply ? '1' : '0',
                'exclusion_reason' => $exclusionReason,
                'note' => $note,
            ];

            if ($apply && $wooTermId > 0 && (string) ($recommendation['recommended_ebay_category_id'] ?? '') !== '') {
                $fixRowsByWooTerm[$wooTermId] = [
                    'woo_subcategory_id' => (string) $wooTermId,
                    'woo_category_id' => (string) $wooTermId,
                    'woo_subcategory_name' => $wooName,
                    'woo_category_path' => $wooPath !== '' ? $wooPath : $this->categoryRepo->woo_category_path($wooTermId),
                    'old_ebay_category_id' => $currentCategoryId,
                    'ebay_category_id' => (string) $recommendation['recommended_ebay_category_id'],
                    'recommended_ebay_category_path' => (string) $recommendation['recommended_ebay_category_path'],
                    'confidence' => sprintf('%.4F', $confidence),
                    'reason' => (string) $recommendation['decision_reason'],
                ];
            }
        }

        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $uploadDirWritable = is_dir($baseDir) && is_writable($baseDir);

        $summary = [
            'action' => 'generate_blocked_category_fix_report',
            'result' => 'success',
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'source_problems_csv' => $problemsCsv,
            'blocked_by_category_rows' => $blockedRows,
            'recommended_products' => $recommendedProducts,
            'recommended_categories' => count($recommendedCategories),
            'high_confidence_products' => $highConfidenceProducts,
            'high_confidence_categories' => count($highConfidenceCategories),
            'fix_import_rows' => count($fixRowsByWooTerm),
            'upload_dir' => $baseDir,
            'upload_dir_writable' => $uploadDirWritable,
            'recommendations_csv_path' => trailingslashit($baseDir) . self::RECOMMENDATIONS_FILENAME,
            'recommendations_csv_url' => trailingslashit($baseUrl) . self::RECOMMENDATIONS_FILENAME,
            'recommendations_csv_exists' => false,
            'recommendations_csv_size' => 0,
            'fix_import_csv_path' => trailingslashit($baseDir) . self::FIX_IMPORT_FILENAME,
            'fix_import_csv_url' => trailingslashit($baseUrl) . self::FIX_IMPORT_FILENAME,
            'fix_import_csv_exists' => false,
            'fix_import_csv_size' => 0,
            'error' => '',
            'supported_intents' => EbayDeCategoryRuleMapper::supported_intents(),
        ];

        if (!$uploadDirWritable) {
            $summary['result'] = 'error';
            $summary['error'] = 'upload_dir_not_writable';
            update_option('wei_ebay_blocked_category_fix_report_summary', $summary, false);
            $this->logger->warning('Blocked category mapping fix report upload directory is not writable', $summary);
            return $summary;
        }

        $reports = [
            'recommendations_csv' => $this->write_csv($baseDir, $baseUrl, self::RECOMMENDATIONS_FILENAME, $recommendationRows, $this->recommendation_headers()),
            'fix_import_csv' => $this->write_csv($baseDir, $baseUrl, self::FIX_IMPORT_FILENAME, array_values($fixRowsByWooTerm), $this->fix_headers()),
        ];

        $recommendationsState = $this->csv_state($baseDir, $baseUrl, self::RECOMMENDATIONS_FILENAME);
        $fixImportState = $this->csv_state($baseDir, $baseUrl, self::FIX_IMPORT_FILENAME);
        $summary = array_merge($summary, [
            'recommendations_csv_path' => $recommendationsState['path'],
            'recommendations_csv_url' => $recommendationsState['url'],
            'recommendations_csv_exists' => $recommendationsState['exists'],
            'recommendations_csv_size' => $recommendationsState['size'],
            'fix_import_csv_path' => $fixImportState['path'],
            'fix_import_csv_url' => $fixImportState['url'],
            'fix_import_csv_exists' => $fixImportState['exists'],
            'fix_import_csv_size' => $fixImportState['size'],
            'reports' => $reports,
        ]);

        if (empty($summary['fix_import_csv_exists']) || (int) $summary['fix_import_csv_size'] <= 0) {
            $summary['result'] = 'error';
            $summary['error'] = 'fix_import_csv_not_written';
        }

        update_option('wei_ebay_blocked_category_fix_report_summary', $summary, false);
        $this->logger->info('Blocked category mapping fix report generated', $summary);
        return $summary;
    }


    public function generate_category_mapping_worklist(string $problemsCsv, string $marketplaceId = 'EBAY_DE'): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $path = trailingslashit($baseDir) . self::CATEGORY_MAPPING_WORKLIST_FILENAME;
        $url = trailingslashit($baseUrl) . self::CATEGORY_MAPPING_WORKLIST_FILENAME;
        $headers = $this->category_mapping_worklist_headers();
        $groups = [];
        $rowsRead = 0;
        $eligibleRows = 0;
        $fh = is_readable($problemsCsv) ? fopen($problemsCsv, 'rb') : false;
        if (!$fh) {
            return ['result' => 'error', 'error' => 'problems_csv_not_readable', 'source_problems_csv' => $problemsCsv, 'worklist_csv_path' => $path, 'worklist_csv_url' => $url, 'worklist_csv_exists' => false, 'worklist_csv_size' => 0, 'rows' => 0];
        }
        $csvHeaders = fgetcsv($fh);
        if (!is_array($csvHeaders)) {
            fclose($fh);
            return ['result' => 'error', 'error' => 'problems_csv_missing_headers', 'source_problems_csv' => $problemsCsv, 'worklist_csv_path' => $path, 'worklist_csv_url' => $url, 'worklist_csv_exists' => false, 'worklist_csv_size' => 0, 'rows' => 0];
        }
        $csvHeaders = array_map(static fn($header): string => trim((string) $header), $csvHeaders);
        while (($data = fgetcsv($fh)) !== false) {
            $rowsRead++;
            $row = [];
            foreach ($csvHeaders as $index => $header) {
                $row[$header] = (string) ($data[$index] ?? '');
            }
            $problemType = $this->category_problem_type_for_audit_row($row);
            if ($problemType === '') {
                continue;
            }
            $wooPath = trim((string) ($row['woo_category_path'] ?? ''));
            $wooTermId = $this->woo_term_id_for_row($row, $wooPath);
            $groupKey = $wooTermId > 0 ? 'id:' . $wooTermId : 'path:' . $wooPath;
            if ($groupKey === 'path:') {
                continue;
            }
            $eligibleRows++;
            if (!isset($groups[$groupKey])) {
                $wooTerm = $wooTermId > 0 ? get_term($wooTermId, 'product_cat') : null;
                $wooName = is_object($wooTerm) && isset($wooTerm->name) ? (string) $wooTerm->name : $this->last_path_part($wooPath);
                $groups[$groupKey] = [
                    'woo_category_id' => $wooTermId > 0 ? (string) $wooTermId : '',
                    'woo_category_name' => $wooName,
                    'blocked_product_count' => 0,
                    'total_product_count_in_category' => $wooTermId > 0 ? (string) $this->total_products_in_woo_category($wooTermId) : '',
                    'current_ebay_category_id' => (string) ($row['current_ebay_category_id'] ?? ''),
                    'current_ebay_category_name' => '',
                    'current_ebay_category_path' => (string) ($row['current_ebay_category_path'] ?? ''),
                    'problem_type' => $problemType,
                    'sample_product_id' => '',
                    'sample_product_title' => '',
                    'sample_product_ids' => [],
                    'sample_product_titles' => [],
                    'final_ebay_category_id' => '',
                    'manual_notes' => '',
                ];
            }
            $groups[$groupKey]['blocked_product_count'] = (int) $groups[$groupKey]['blocked_product_count'] + 1;
            if ($this->category_problem_severity($problemType) < $this->category_problem_severity((string) $groups[$groupKey]['problem_type'])) {
                $groups[$groupKey]['problem_type'] = $problemType;
            }
            foreach (['current_ebay_category_id' => 'current_ebay_category_id', 'current_ebay_category_path' => 'current_ebay_category_path'] as $source => $target) {
                if ((string) $groups[$groupKey][$target] === '' && (string) ($row[$source] ?? '') !== '') {
                    $groups[$groupKey][$target] = (string) $row[$source];
                }
            }
            $pid = trim((string) ($row['product_id'] ?? ''));
            $title = trim((string) ($row['product_title'] ?? $row['title'] ?? ''));
            if ((string) ($groups[$groupKey]['sample_product_title'] ?? '') === '' && $title !== '') {
                $groups[$groupKey]['sample_product_id'] = $pid;
                $groups[$groupKey]['sample_product_title'] = $title;
            }
            if (count($groups[$groupKey]['sample_product_ids']) < 10) {
                if ($pid !== '' && !in_array($pid, $groups[$groupKey]['sample_product_ids'], true)) {
                    $groups[$groupKey]['sample_product_ids'][] = $pid;
                }
            }
            if (count($groups[$groupKey]['sample_product_titles']) < 10) {
                if ($title !== '' && !in_array($title, $groups[$groupKey]['sample_product_titles'], true)) {
                    $groups[$groupKey]['sample_product_titles'][] = $title;
                }
            }
        }
        fclose($fh);

        $rows = array_values(array_map(function (array $group) use ($marketplaceId): array {
            $wooCategoryId = absint($group['woo_category_id'] ?? 0);
            $resolved = $wooCategoryId > 0 ? $this->categoryRepo->resolveProductionCategoryMapping($wooCategoryId, $marketplaceId) : null;
            if (is_array($resolved) && trim((string) ($resolved['ebay_category_id'] ?? '')) !== '') {
                $group['current_ebay_category_id'] = (string) ($resolved['ebay_category_id'] ?? '');
                $group['current_ebay_category_path'] = (string) ($resolved['ebay_category_path'] ?? $group['current_ebay_category_path'] ?? '');
                $group['manual_notes'] = trim((string) ($group['manual_notes'] ?? '') . ' resolver_source=' . (string) ($resolved['source'] ?? '') . ' resolver_row_id=' . (string) ($resolved['id'] ?? ''));
            }
            $categoryId = (string) ($group['current_ebay_category_id'] ?? '');
            if ($categoryId !== '') {
                $cached = $this->taxonomy->cached_category($marketplaceId, $categoryId);
                if (is_array($cached)) {
                    $group['current_ebay_category_name'] = (string) ($cached['category_name'] ?? '');
                    if ((string) ($group['current_ebay_category_path'] ?? '') === '') {
                        $group['current_ebay_category_path'] = (string) ($cached['category_path'] ?? '');
                    }
                }
            }
            $group['sample_product_ids'] = implode('|', array_slice((array) $group['sample_product_ids'], 0, 10));
            $group['sample_product_titles'] = implode(' | ', array_slice((array) $group['sample_product_titles'], 0, 10));
            return $group;
        }, $groups));
        usort($rows, function (array $a, array $b): int {
            $countCmp = ((int) ($b['blocked_product_count'] ?? 0)) <=> ((int) ($a['blocked_product_count'] ?? 0));
            if ($countCmp !== 0) {
                return $countCmp;
            }
            return $this->category_problem_severity((string) ($a['problem_type'] ?? '')) <=> $this->category_problem_severity((string) ($b['problem_type'] ?? ''));
        });
        $report = $this->write_csv($baseDir, $baseUrl, self::CATEGORY_MAPPING_WORKLIST_FILENAME, $rows, $headers);
        $exists = is_file($path);
        $summary = [
            'result' => 'success',
            'marketplace_id' => $marketplaceId,
            'source_problems_csv' => $problemsCsv,
            'rows_read' => $rowsRead,
            'eligible_product_rows' => $eligibleRows,
            'rows' => count($rows),
            'worklist_csv_path' => $path,
            'worklist_csv_url' => $url,
            'worklist_csv_exists' => $exists,
            'worklist_csv_size' => $exists ? (int) filesize($path) : 0,
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'reports' => ['category_mapping_worklist_csv' => $report],
        ];
        update_option('wei_ebay_category_mapping_worklist_summary', $summary, false);
        return $summary;
    }

    public function import_category_mapping_worklist(string $csvPath, string $marketplaceId = 'EBAY_DE'): array
    {
        $summary = ['result' => 'success', 'marketplace_id' => $marketplaceId, 'source_csv' => $csvPath, 'source' => 'manual_worklist', 'total_rows' => 0, 'accepted' => 0, 'accepted_rows' => [], 'accepted_validated_cache' => 0, 'accepted_trusted_manual_cache_missing' => 0, 'rejected_invalid_format' => 0, 'rejected_non_leaf_if_cache_knows_non_leaf' => 0, 'skipped_empty_final_ebay_category_id' => 0, 'skipped' => 0, 'rejected' => 0, 'rejected_rows' => [], 'import_debug_rows' => [], 'inserted_mappings' => 0, 'updated_mappings' => 0, 'deactivated_duplicate_mappings' => 0, 'unchanged_mappings' => 0, 'warnings' => [], 'imported_at' => gmdate('Y-m-d H:i:s'), 'ebay_api_called' => false, 'products_modified' => false, 'listings_modified' => false];
        if ($marketplaceId !== 'EBAY_DE') {
            $summary['result'] = 'error';
            $summary['error'] = 'unsupported_marketplace';
            return $summary;
        }
        $fh = is_readable($csvPath) ? fopen($csvPath, 'rb') : false;
        if (!$fh) {
            $summary['result'] = 'error';
            $summary['error'] = 'csv_not_readable';
            return $summary;
        }
        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            $summary['result'] = 'error';
            $summary['error'] = 'csv_missing_headers';
            return $summary;
        }
        $headers = array_map(static fn($header): string => trim((string) $header), $headers);
        $cacheDiagnostic = $this->taxonomy->category_cache_diagnostic($marketplaceId, ['33544', '33615', '33566', '9886', '171115']);
        $summary['category_cache_diagnostic'] = $cacheDiagnostic;
        $cacheTotal = (int) ($cacheDiagnostic['total_cached_categories'] ?? 0);
        while (($data = fgetcsv($fh)) !== false) {
            $summary['total_rows']++;
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($data[$index] ?? '');
            }
            $wooCategoryId = absint($row['woo_category_id'] ?? 0);
            $finalCategoryId = trim((string) ($row['final_ebay_category_id'] ?? ''));
            if ($finalCategoryId === '') {
                $summary = $this->add_worklist_import_debug_row($summary, $row, $marketplaceId, $finalCategoryId, null, 'skipped', 'skipped_empty_final_ebay_category_id');
                $summary['skipped_empty_final_ebay_category_id']++;
                $summary['skipped']++;
                continue;
            }
            if (!ctype_digit($finalCategoryId)) {
                $summary['rejected_invalid_format']++;
                $summary = $this->add_worklist_import_debug_row($summary, $row, $marketplaceId, $finalCategoryId, null, 'rejected', 'invalid_ebay_category_id_format');
                $summary = $this->reject_worklist_row($summary, $row, 'invalid_ebay_category_id_format');
                continue;
            }
            if ($wooCategoryId <= 0) {
                $category = $this->taxonomy->cached_category($marketplaceId, $finalCategoryId);
                $summary = $this->add_worklist_import_debug_row($summary, $row, $marketplaceId, $finalCategoryId, $category, 'rejected', 'missing_woo_category_id');
                $summary = $this->reject_worklist_row($summary, $row, 'missing_woo_category_id');
                continue;
            }
            $category = $this->taxonomy->cached_category($marketplaceId, $finalCategoryId);
            $decisionReason = '';
            $trustedManualCacheMissing = false;
            if (!is_array($category)) {
                $decisionReason = $cacheTotal <= 0 ? 'cache_missing' : 'cache_incomplete';
                $trustedManualCacheMissing = true;
                $category = [
                    'category_id' => $finalCategoryId,
                    'category_name' => '',
                    'category_path' => '',
                    'leaf' => null,
                ];
            } elseif (empty($category['leaf'])) {
                $decisionReason = 'non_leaf_category';
                $summary['rejected_non_leaf_if_cache_knows_non_leaf']++;
                $summary = $this->add_worklist_import_debug_row($summary, $row, $marketplaceId, $finalCategoryId, $category, 'rejected', $decisionReason);
                $summary = $this->reject_worklist_row($summary, $row, $decisionReason);
                continue;
            } else {
                $decisionReason = 'accepted_validated_cache';
            }
            $validationStatus = $trustedManualCacheMissing ? 'cache_missing' : 'valid_leaf';
            $saved = $this->categoryRepo->save_manual_worklist_mapping($wooCategoryId, $marketplaceId, [
                'category_id' => $finalCategoryId,
                'category_name' => (string) ($category['category_name'] ?? ''),
                'category_path' => (string) ($category['category_path'] ?? ''),
                'cache_validation_status' => $validationStatus,
                'validation_confidence' => $trustedManualCacheMissing ? 'trusted_manual' : 'validated_cache',
                'needs_cache_validation' => $trustedManualCacheMissing ? 1 : 0,
            ]);
            $this->record_worklist_category_validation($wooCategoryId, $marketplaceId, $finalCategoryId, $category, $validationStatus, $trustedManualCacheMissing);
            $summary = $this->add_worklist_import_debug_row($summary, $row, $marketplaceId, $finalCategoryId, $trustedManualCacheMissing ? null : $category, 'accepted', $decisionReason);
            $summary['accepted']++;
            if ($trustedManualCacheMissing) {
                $summary['accepted_trusted_manual_cache_missing']++;
                $summary['warnings'][] = 'Imported as trusted manual mapping because local EBAY_DE category cache is missing/incomplete.';
            } else {
                $summary['accepted_validated_cache']++;
            }
            $operation = (string) ($saved['operation'] ?? 'updated');
            if ($operation === 'inserted') {
                $summary['inserted_mappings']++;
            } elseif ($operation === 'unchanged') {
                $summary['unchanged_mappings']++;
            } else {
                $summary['updated_mappings']++;
            }
            $summary['deactivated_duplicate_mappings'] += (int) ($saved['duplicates_disabled'] ?? 0);
            $resolved = is_array($saved['mapping'] ?? null) ? $saved['mapping'] : [];
            if ((string) ($resolved['ebay_category_id'] ?? '') !== $finalCategoryId) {
                $summary['warnings'][] = 'Resolver selected ' . (string) ($resolved['ebay_category_id'] ?? '(none)') . ' for Woo category ' . $wooCategoryId . ' after importing manual_worklist ' . $finalCategoryId . '. Check active manual mappings and duplicate rows.';
            }
            if (count($summary['accepted_rows']) < 50) {
                $summary['accepted_rows'][] = ['woo_category_id' => $wooCategoryId, 'final_ebay_category_id' => $finalCategoryId, 'selected_id' => (int) ($saved['selected_id'] ?? 0), 'duplicates_disabled' => (int) ($saved['duplicates_disabled'] ?? 0), 'operation' => $operation, 'resolver_selected_ebay_category_id' => (string) ($resolved['ebay_category_id'] ?? ''), 'resolver_selected_source' => (string) ($resolved['source'] ?? ''), 'resolver_reason' => (string) ($resolved['resolver_reason'] ?? ''), 'source' => 'manual_worklist', 'cache_validation_status' => $validationStatus, 'validation_confidence' => $trustedManualCacheMissing ? 'trusted_manual' : 'validated_cache', 'needs_cache_validation' => $trustedManualCacheMissing ? 1 : 0];
            }
        }
        fclose($fh);
        $summary['warnings'] = array_values(array_unique(array_map('strval', (array) ($summary['warnings'] ?? []))));
        update_option('wei_ebay_category_mapping_worklist_import_summary', $summary, false);
        return $summary;
    }


    private function record_worklist_category_validation(int $wooCategoryId, string $marketplaceId, string $categoryId, array $category, string $validationStatus = 'valid_leaf', bool $needsCacheValidation = false): void
    {
        if ($wooCategoryId <= 0 || $categoryId === '') {
            return;
        }
        $validation = get_option('wei_ebay_category_validation_statuses', []);
        $validation = is_array($validation) ? $validation : [];
        $validation['by_woo_term_id'] = is_array($validation['by_woo_term_id'] ?? null) ? $validation['by_woo_term_id'] : [];
        $validation['by_category_id'] = is_array($validation['by_category_id'] ?? null) ? $validation['by_category_id'] : [];
        $entry = [
            'woo_term_id' => $wooCategoryId,
            'category_id' => $categoryId,
            'valid' => true,
            'leaf' => !$needsCacheValidation,
            'validation_status' => $validationStatus,
            'cache_validation_status' => $validationStatus,
            'validation_confidence' => $needsCacheValidation ? 'trusted_manual' : 'validated_cache',
            'needs_cache_validation' => $needsCacheValidation ? 1 : 0,
            'category_name' => (string) ($category['category_name'] ?? ''),
            'category_path' => (string) ($category['category_path'] ?? ''),
            'source' => 'manual_worklist_import',
            'marketplace_id' => $marketplaceId,
            'updated_at' => gmdate('c'),
        ];
        $validation['by_woo_term_id'][(string) $wooCategoryId] = $entry;
        $validation['by_category_id'][$categoryId] = $entry;
        $validation['updated_at'] = gmdate('c');
        $validation['marketplace_id'] = $marketplaceId;
        update_option('wei_ebay_category_validation_statuses', $validation, false);
    }

    private function reject_worklist_row(array $summary, array $row, string $reason): array
    {
        $summary['rejected']++;
        if (count($summary['rejected_rows']) < 100) {
            $summary['rejected_rows'][] = ['woo_category_id' => (string) ($row['woo_category_id'] ?? ''), 'final_ebay_category_id' => (string) ($row['final_ebay_category_id'] ?? ''), 'current_ebay_category_id' => (string) ($row['current_ebay_category_id'] ?? ''), 'reason' => $reason];
        }
        return $summary;
    }

    private function add_worklist_import_debug_row(array $summary, array $row, string $marketplaceId, string $finalCategoryId, ?array $category, string $decision, string $reason): array
    {
        if (count($summary['import_debug_rows'] ?? []) >= 5) {
            return $summary;
        }
        $summary['import_debug_rows'][] = [
            'woo_category_id' => (string) ($row['woo_category_id'] ?? ''),
            'final_ebay_category_id' => $finalCategoryId,
            'current_ebay_category_id' => (string) ($row['current_ebay_category_id'] ?? ''),
            'cache_lookup_marketplace_id' => $marketplaceId,
            'cache_lookup_result' => [
                'found' => is_array($category),
                'leaf' => is_array($category) ? !empty($category['leaf']) : null,
                'category_name' => is_array($category) ? (string) ($category['category_name'] ?? '') : '',
                'category_path' => is_array($category) ? (string) ($category['category_path'] ?? '') : '',
            ],
            'decision' => $decision,
            'reason' => $reason,
        ];
        return $summary;
    }

    private function category_mapping_worklist_headers(): array
    {
        return ['final_ebay_category_id','sample_product_title','woo_category_id','woo_category_name','blocked_product_count','total_product_count_in_category','current_ebay_category_id','current_ebay_category_name','current_ebay_category_path','problem_type','sample_product_id','sample_product_ids','sample_product_titles','manual_notes'];
    }

    private function category_problem_type_for_audit_row(array $row): string
    {
        $status = trim((string) ($row['status'] ?? ''));
        $reason = strtolower(trim((string) (($row['reason'] ?? '') . ' ' . ($row['category_sanity_reason'] ?? ''))));
        if ($status === 'invalid_ebay_category_id' || str_contains($reason, 'invalid_ebay_category_id')) {
            return 'invalid_ebay_category_id';
        }
        if ($status === 'non_leaf_category' || $status === 'non_leaf_ebay_category_id' || str_contains($reason, 'non_leaf')) {
            return 'non_leaf_category';
        }
        if ($status === 'missing_category' || $status === 'unmapped' || str_contains($reason, 'missing_category') || str_contains($reason, 'unmapped')) {
            return 'missing_category';
        }
        if ($status === 'needs_category_review' || str_contains($reason, 'needs_category_review')) {
            return 'needs_category_review';
        }
        if ($status === 'category_sanity_failed' || str_contains($reason, 'sanity')) {
            return 'category_sanity_failed';
        }
        if ($status === 'blocked_by_category') {
            return 'blocked_by_category';
        }
        return '';
    }

    private function category_problem_severity(string $problemType): int
    {
        return match ($problemType) {
            'blocked_by_category' => 10,
            'invalid_ebay_category_id' => 20,
            'non_leaf_category' => 30,
            'missing_category' => 40,
            'needs_category_review' => 50,
            'category_sanity_failed' => 60,
            default => 999,
        };
    }

    private function total_products_in_woo_category(int $wooTermId): int
    {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','private') AND tt.term_id = %d",
            $wooTermId
        ));
        return is_numeric($count) ? (int) $count : 0;
    }

    private function woo_term_id_for_row(array $row, string $wooPath): int
    {
        foreach (['woo_category_id', 'woo_subcategory_id', 'woo_term_id'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '' && ctype_digit($value)) {
                return (int) $value;
            }
        }
        if ($wooPath === '') {
            return 0;
        }
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (!is_array($terms)) {
            return 0;
        }
        foreach ($terms as $term) {
            if (is_object($term) && isset($term->term_id) && $this->categoryRepo->woo_category_path((int) $term->term_id) === $wooPath) {
                return (int) $term->term_id;
            }
        }
        return 0;
    }

    private function read_csv_assoc(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return [];
        }
        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            return [];
        }
        $headers = array_map(static fn($header): string => trim((string) $header), $headers);
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($data[$index] ?? '');
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private function exclusion_reason(array $recommendation, array $validation, int $wooTermId, float $confidence): string
    {
        if ($wooTermId <= 0) {
            return 'woo_category_id_not_resolved';
        }
        if ((string) ($recommendation['recommended_ebay_category_id'] ?? '') === '') {
            return (string) ($recommendation['decision_reason'] ?? 'no_recommended_category');
        }
        if ($confidence < EbayDeCategoryRuleMapper::HIGH_CONFIDENCE_THRESHOLD) {
            return 'confidence_below_safe_import_threshold';
        }
        if (empty($validation['valid']) || empty($validation['leaf']) || empty($validation['automotive'])) {
            return (string) ($validation['status'] ?? $validation['validation_status'] ?? 'taxonomy_validation_failed');
        }
        if ((string) ($recommendation['sanity_status'] ?? '') !== 'pass') {
            return (string) ($recommendation['sanity_reason'] ?? 'sanity_validation_failed');
        }
        return 'not_safe_for_bulk_import';
    }


    private function write_csv(string $baseDir, string $baseUrl, string $filename, array $rows, array $headers): array
    {
        $path = trailingslashit($baseDir) . $filename;
        $fh = fopen($path, 'wb');
        if (!$fh) {
            return ['error' => 'failed_to_open_csv', 'path' => $path, 'rows' => 0];
        }
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn(string $header): string => (string) ($row[$header] ?? ''), $headers));
        }
        fclose($fh);
        return ['path' => $path, 'url' => trailingslashit($baseUrl) . $filename, 'rows' => count($rows)];
    }


    private function csv_state(string $baseDir, string $baseUrl, string $filename): array
    {
        $path = trailingslashit($baseDir) . $filename;
        $exists = is_file($path);
        return [
            'path' => $path,
            'url' => trailingslashit($baseUrl) . $filename,
            'exists' => $exists,
            'size' => $exists ? (int) filesize($path) : 0,
        ];
    }

    private function recommendation_headers(): array
    {
        return ['product_id','product_title','woo_category_id','woo_category_path','current_ebay_category_id','current_ebay_category_path','detected_intent','sanity_reason','recommended_ebay_category_id','recommended_ebay_category_path','recommendation_confidence','confidence','mapping_status','recommendation_reason','taxonomy_validation_status','apply_candidate','exclusion_reason','note'];
    }

    private function fix_headers(): array
    {
        return ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','old_ebay_category_id','ebay_category_id','recommended_ebay_category_path','confidence','reason'];
    }

    private function last_path_part(string $path): string
    {
        $parts = array_values(array_filter(array_map('trim', explode('>', $path))));
        return (string) end($parts);
    }
}
