<?php

namespace WEI\Services;

use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\Translation\GoogleCloudTranslateProvider;

class AutoCategoryMappingService
{
    private const REVIEW_CONFIDENCE_THRESHOLD = 0.60;
    private const TOP_CANDIDATE_LIMIT = 10;


    public function __construct(private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }


    public function export_category_mapping_teaching_csv(string $problemsCsv, string $marketplaceId = 'EBAY_DE'): array
    {
        $rows = $this->read_csv_assoc($problemsCsv);
        $groups = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (!in_array($status, ['blocked_by_category', 'missing_category'], true)) {
                continue;
            }
            $wooPath = trim((string) ($row['woo_category_path'] ?? ''));
            $intent = trim((string) ($row['detected_intent'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($wooPath === '' && $intent === '' && $reason === '') {
                continue;
            }
            $family = $this->categoryRepo->keyword_family_from_title((string) ($row['title'] ?? ''));
            $key = implode("\x1f", [$wooPath, $intent, $reason, $family, (string) ($row['current_ebay_category_id'] ?? $row['proposed_ebay_category_id'] ?? '')]);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'woo_category_path' => $wooPath,
                    'detected_intent' => $intent,
                    'sanity_reason' => $reason,
                    'title_keyword_family' => $family,
                    'current_bad_category_id' => (string) ($row['current_ebay_category_id'] ?? $row['proposed_ebay_category_id'] ?? ''),
                    'current_bad_category_path' => (string) ($row['current_ebay_category_path'] ?? $row['proposed_ebay_category_path'] ?? ''),
                    'suggested_manual_ebay_category_id' => '',
                    'suggested_manual_ebay_category_path' => '',
                    'product_ids' => [],
                    'titles' => [],
                ];
            }
            $productId = absint($row['product_id'] ?? 0);
            if ($productId > 0) {
                $groups[$key]['product_ids'][] = $productId;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title !== '') {
                $groups[$key]['titles'][] = $title;
            }
        }

        $exportRows = [];
        foreach ($groups as $group) {
            $ids = array_values(array_unique(array_map('intval', $group['product_ids'])));
            $titles = array_values(array_unique(array_map('strval', $group['titles'])));
            $groupId = substr(hash('sha256', implode('|', [
                $group['woo_category_path'],
                $group['detected_intent'],
                $group['sanity_reason'],
                $group['title_keyword_family'],
                $group['current_bad_category_id'],
            ])), 0, 16);
            $note = $group['title_keyword_family'] !== '' ? 'keyword_family=' . $group['title_keyword_family'] : '';
            $exportRows[] = [
                'group_id' => $groupId,
                'product_count' => count($ids),
                'woo_category_path' => $group['woo_category_path'],
                'detected_intent' => $group['detected_intent'],
                'sanity_reason' => $group['sanity_reason'],
                'current_bad_category_id' => $group['current_bad_category_id'],
                'current_bad_category_path' => $group['current_bad_category_path'],
                'sample_product_ids' => implode('|', array_slice($ids, 0, 12)),
                'sample_titles' => implode(' || ', array_slice($titles, 0, 5)),
                'suggested_manual_ebay_category_id' => $group['suggested_manual_ebay_category_id'],
                'suggested_manual_ebay_category_path' => $group['suggested_manual_ebay_category_path'],
                'manual_ebay_category_id' => '',
                'manual_ebay_category_path' => '',
                'rule_note' => $note,
            ];
        }
        usort($exportRows, static fn(array $a, array $b): int => ((int) ($b['product_count'] ?? 0)) <=> ((int) ($a['product_count'] ?? 0)));

        $reports = $this->write_teaching_export_report($exportRows);
        $summary = [
            'source_problems_csv' => $problemsCsv,
            'marketplace_id' => $marketplaceId,
            'groups_exported' => count($exportRows),
            'reports' => $reports,
        ];
        update_option('wei_ebay_category_mapping_teaching_export', $summary, false);
        $this->logger->info('Category mapping teaching CSV exported', ['groups_exported' => count($exportRows), 'reports' => $reports]);
        return $summary;
    }

    public function import_category_mapping_teaching_csv(string $csvPath, string $marketplaceId = 'EBAY_DE'): array
    {
        $rows = $this->read_csv_assoc($csvPath);
        $summary = [
            'source_csv' => $csvPath,
            'marketplace_id' => $marketplaceId,
            'rows_read' => count($rows),
            'rows_with_manual_category_id' => 0,
            'rules_inserted' => 0,
            'rules_updated' => 0,
            'rows_skipped' => 0,
            'rows_rejected_by_safety' => 0,
            'validation_errors_sample' => [],
            'imported_rule_keys' => [],
            // Back-compat keys used by older admin/status renderers.
            'rows' => count($rows),
            'imported_rules' => 0,
            'skipped_rows' => 0,
            'safety_failed_rows' => 0,
            'details' => [],
        ];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $manualId = trim((string) ($row['manual_ebay_category_id'] ?? $row['suggested_manual_ebay_category_id'] ?? ''));
            if ($manualId === '') {
                $summary['rows_skipped']++;
                $this->append_validation_error($summary, "row {$rowNumber}: missing manual_ebay_category_id");
                continue;
            }
            $summary['rows_with_manual_category_id']++;

            $wooPath = trim((string) ($row['woo_category_path'] ?? ''));
            if ($wooPath === '') {
                $summary['rows_skipped']++;
                $this->append_validation_error($summary, "row {$rowNumber}: missing woo_category_path for manual category {$manualId}");
                continue;
            }

            $manualPath = trim((string) ($row['manual_ebay_category_path'] ?? $row['suggested_manual_ebay_category_path'] ?? ''));
            if ($manualPath === '') {
                $details = $this->taxonomy->get_category_details_result($marketplaceId, $manualId);
                $manualPath = trim((string) ($details['category_path'] ?? $details['category_name'] ?? ''));
            }

            $safety = CategoryMappingSafety::sanity_check($wooPath . ' ' . (string) ($row['detected_intent'] ?? '') . ' ' . (string) ($row['sample_titles'] ?? ''), trim($manualPath . ' ' . $manualId));
            if (empty($safety['pass'])) {
                $reason = (string) ($safety['reason'] ?? 'manual_teaching_rule_failed_safety');
                $summary['rows_rejected_by_safety']++;
                $summary['details'][] = ['row' => $rowNumber, 'group_id' => (string) ($row['group_id'] ?? ''), 'status' => 'safety_failed', 'reason' => $reason, 'category_id' => $manualId, 'woo_category_path' => $wooPath];
                $this->append_validation_error($summary, "row {$rowNumber}: safety rejected category {$manualId}: {$reason}");
                continue;
            }

            $keywordFamily = $this->keyword_family_from_rule_row($row);
            $detectedIntent = trim((string) ($row['detected_intent'] ?? ''));
            $writeResult = $this->categoryRepo->upsert_teaching_rule([
                'marketplace_id' => $marketplaceId,
                'woo_category_path' => $wooPath,
                'detected_intent' => $detectedIntent,
                'title_keyword_family' => $keywordFamily,
                'ebay_category_id' => $manualId,
                'ebay_category_path' => $manualPath,
                'source' => 'manual_teaching_csv',
                'rule_note' => (string) ($row['rule_note'] ?? ''),
                'import_group_id' => (string) ($row['group_id'] ?? ''),
                'sample_product_ids' => (string) ($row['sample_product_ids'] ?? ''),
            ]);
            if ($writeResult === 'updated') {
                $summary['rules_updated']++;
            } else {
                $summary['rules_inserted']++;
            }
            $summary['imported_rules']++;
            $key = [
                'marketplace_id' => $marketplaceId,
                'woo_category_path' => $wooPath,
                'woo_category_path_hash' => $this->categoryRepo->woo_category_path_hash($wooPath),
                'detected_intent' => $detectedIntent,
                'title_keyword_family' => $keywordFamily,
                'manual_ebay_category_id' => $manualId,
            ];
            if (count($summary['imported_rule_keys']) < 10) {
                $summary['imported_rule_keys'][] = $key;
            }
            $summary['details'][] = ['row' => $rowNumber, 'group_id' => (string) ($row['group_id'] ?? ''), 'status' => $writeResult, 'keyword_family' => $keywordFamily, 'category_id' => $manualId] + $key;
        }

        $summary['skipped_rows'] = (int) $summary['rows_skipped'];
        $summary['safety_failed_rows'] = (int) $summary['rows_rejected_by_safety'];
        update_option('wei_ebay_category_mapping_teaching_import', $summary, false);
        $this->logger->info('Category mapping teaching CSV imported', ['rows_read' => count($rows), 'rules_inserted' => (int) $summary['rules_inserted'], 'rules_updated' => (int) $summary['rules_updated'], 'rows_skipped' => (int) $summary['rows_skipped'], 'rows_rejected_by_safety' => (int) $summary['rows_rejected_by_safety']]);
        return $summary;
    }

    public function test_teaching_rule_match_for_product(int $productId, string $marketplaceId = 'EBAY_DE'): array
    {
        $settings = $this->settings();
        $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
        $term = is_array($terms) && $terms !== [] ? reset($terms) : null;
        if (is_wp_error($terms) || !$term || (int) ($term->term_id ?? 0) <= 0) {
            return ['product_id' => $productId, 'error' => 'missing_product_category'];
        }

        $termId = (int) $term->term_id;
        $path = $this->categoryRepo->woo_category_path($termId);
        $samples = $this->categoryRepo->sample_products_for_category($termId, 5);
        $sampleTitle = implode(' ', array_map(static fn(array $sample): string => (string) ($sample['title'] ?? ''), $samples));
        $querySource = $this->build_query($path, $samples);
        $query = $this->translate_query_to_german($querySource, $settings);
        $intent = CategoryMappingSafety::detect_intent(trim($path . ' ' . $query . ' ' . $sampleTitle));
        $family = $this->categoryRepo->keyword_family_from_title($sampleTitle);
        $rule = $this->categoryRepo->find_teaching_rule($marketplaceId, $path, $intent, $sampleTitle, $family);
        return [
            'product_id' => $productId,
            'marketplace_id' => $marketplaceId,
            'woo_category_path' => $path,
            'normalized_woo_category_path' => $this->categoryRepo->normalize_rule_text($path),
            'detected_intent' => $intent,
            'title_keyword_family' => $family,
            'computed_woo_category_path_hash' => $this->categoryRepo->woo_category_path_hash($path),
            'matching_teaching_rule_found' => is_array($rule),
            'matched_rule_id' => is_array($rule) ? (int) ($rule['id'] ?? 0) : 0,
            'matched_manual_ebay_category_id' => is_array($rule) ? (string) ($rule['ebay_category_id'] ?? '') : '',
            'matched_rule' => is_array($rule) ? $this->teaching_rule_debug($rule) : [],
            'nearest_rules' => is_array($rule) ? [] : array_map(fn(array $nearest): array => $this->teaching_rule_debug($nearest), $this->categoryRepo->nearest_teaching_rules($marketplaceId, $path, $intent, 10)),
        ];
    }

    public function auto_map_used_categories(string $marketplaceId = 'EBAY_DE', int $limit = 200): array
    {
        $settings = $this->settings();
        $marketplaceId = trim($marketplaceId) !== '' ? trim($marketplaceId) : (string) ($settings['marketplace_id'] ?? 'EBAY_DE');
        $rows = $this->categoryRepo->list_used_woo_categories($marketplaceId, $limit);
        $summary = ['marketplace_id' => $marketplaceId, 'processed' => 0, 'mapped_auto' => 0, 'low_confidence_auto' => 0, 'category_sanity_failed' => 0, 'needs_category_review' => 0, 'unmapped' => 0, 'taxonomy_api_forbidden' => 0, 'suggestion_failed' => 0, 'skipped_confirmed' => 0, 'threshold' => CategoryMappingSafety::threshold($settings)];

        $tree = $this->taxonomy->get_default_category_tree_id_result($marketplaceId);
        if (($tree['status'] ?? '') === 'taxonomy_api_forbidden') {
            $summary['taxonomy_api_forbidden'] = 1;
            $summary['global_status'] = 'taxonomy_api_forbidden';
            $summary['error'] = (string) ($tree['error'] ?? 'eBay Taxonomy API forbidden');
            $summary['category_tree_id'] = '';
            $this->logger->error('Auto category mapping stopped before processing categories because eBay Taxonomy API is forbidden', [
                'marketplace_id' => $marketplaceId,
                'processed' => 0,
                'status' => 'taxonomy_api_forbidden',
                'error' => $summary['error'],
                'category_tree_id' => '',
                'token_type' => (string) ($tree['token_type'] ?? 'application'),
                'scope' => (string) ($tree['scope'] ?? EbayAuth::APP_SCOPE),
                'scope_requested' => (string) ($tree['scope_requested'] ?? EbayAuth::APP_SCOPE),
            ]);
            return $summary;
        }

        if (($tree['status'] ?? '') === 'ok') {
            $summary['category_tree_id'] = (string) ($tree['category_tree_id'] ?? '');
        }

        foreach ($rows as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            if ($termId <= 0) {
                continue;
            }

            $existing = $this->categoryRepo->find($marketplaceId, $termId);
            if ($this->is_manual_mapping($existing)) {
                $summary['skipped_confirmed']++;
                continue;
            }

            $reevaluated = $this->reevaluate_existing_mapping($existing, $settings);
            if ($reevaluated !== null) {
                $result = $reevaluated;
            } else {
                $result = $this->auto_map_term($termId, $marketplaceId, $settings);
            }
            $summary['processed']++;
            $status = (string) ($result['status'] ?? 'suggestion_failed');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    public function repair_blocked_category_mappings_from_audit_problem_groups(string $problemsCsv, string $marketplaceId = 'EBAY_DE', array $targetReasons = []): array
    {
        $defaultReasons = [
            'car_speaker_candidate_is_motorcycle_parts_family',
            'interior_trim_candidate_is_audio_or_motorcycle',
            'expected_path_keyword_missing',
            'interior_trim_candidate_is_motorcycle_parts_family',
            'power_steering_hose_candidate_is_engine_parts',
            'gearbox_mount_candidate_is_motorcycle_parts_family',
            'engine_bearing_category_mismatch',
        ];
        $targetReasons = $targetReasons !== [] ? array_values(array_unique(array_map('strval', $targetReasons))) : $this->problem_reasons_from_csv($problemsCsv);
        $targetReasons = $targetReasons !== [] ? $targetReasons : $defaultReasons;
        $groups = $this->problem_product_ids_by_reason($problemsCsv, $targetReasons);
        $summary = [
            'source_problems_csv' => $problemsCsv,
            'marketplace_id' => $marketplaceId,
            'target_reasons' => $targetReasons,
            'groups' => [],
            'processed' => 0,
            'fixed_count' => 0,
            'still_blocked_count' => 0,
            'no_candidate_count' => 0,
            'low_confidence_count' => 0,
            'reports' => [],
        ];
        $diagnosticRows = [];

        foreach ($targetReasons as $reason) {
            $ids = array_values(array_unique(array_map('intval', $groups[$reason] ?? [])));
            if ($ids === []) {
                $summary['groups'][$reason] = ['input_products' => 0, 'processed' => 0, 'fixed_count' => 0, 'still_blocked_count' => 0];
                continue;
            }
            $res = $this->repair_blocked_category_mappings($ids, $marketplaceId, count($ids));
            $groupSummary = [
                'input_products' => count($ids),
                'processed' => (int) ($res['processed'] ?? 0),
                'fixed_count' => (int) ($res['fixed_count'] ?? 0),
                'still_blocked_count' => (int) ($res['still_blocked_count'] ?? 0),
                'no_candidate_count' => (int) ($res['no_candidate_count'] ?? 0),
                'low_confidence_count' => (int) ($res['low_confidence_count'] ?? 0),
                'top_block_reasons' => (array) ($res['top_block_reasons'] ?? []),
            ];
            $summary['groups'][$reason] = $groupSummary;
            foreach (['processed', 'fixed_count', 'still_blocked_count', 'no_candidate_count', 'low_confidence_count'] as $key) {
                $summary[$key] += (int) ($groupSummary[$key] ?? 0);
            }
            foreach ((array) ($res['repair_details'] ?? []) as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $diagnosticRows[] = [
                    'audit_reason_group' => $reason,
                    'product_id' => (int) ($detail['product_id'] ?? 0),
                    'result' => empty($detail['reason']) ? 'fixed' : 'still_blocked',
                    'reason' => (string) ($detail['reason'] ?? ''),
                    'fallback_used' => !empty($detail['fallback_used']) ? 'true' : 'false',
                    'fallback_category_id' => (string) ($detail['fallback_category_id'] ?? ''),
                    'fallback_reason' => (string) ($detail['fallback_reason'] ?? ''),
                    'manual_teaching_applied' => !empty($detail['manual_teaching_applied']) ? 'true' : 'false',
                    'manual_teaching_lookup_attempted' => !empty($detail['manual_teaching_lookup_attempted']) ? 'true' : 'false',
                    'manual_teaching_rule_found' => !empty($detail['manual_teaching_rule_found']) ? 'true' : 'false',
                    'manual_teaching_rule_id' => (string) ($detail['manual_teaching_rule_id'] ?? ''),
                    'manual_teaching_category_id' => (string) ($detail['manual_teaching_category_id'] ?? ''),
                    'manual_teaching_rejected_reason' => (string) ($detail['manual_teaching_rejected_reason'] ?? ''),
                    'mapping_write_attempted' => !empty($detail['mapping_write_attempted']) ? 'true' : 'false',
                    'mapping_write_result' => (string) ($detail['mapping_write_result'] ?? ''),
                ];
            }
        }

        $summary['reports'] = $this->write_repair_group_reports($summary, $diagnosticRows);
        update_option('wei_ebay_category_mapping_repair_audit_group_report', $summary, false);
        $this->logger->info('Category mapping audit-group repair completed', [
            'processed' => (int) $summary['processed'],
            'fixed_count' => (int) $summary['fixed_count'],
            'still_blocked_count' => (int) $summary['still_blocked_count'],
            'groups' => array_map(static fn(array $group): array => [
                'input_products' => (int) ($group['input_products'] ?? 0),
                'fixed_count' => (int) ($group['fixed_count'] ?? 0),
                'still_blocked_count' => (int) ($group['still_blocked_count'] ?? 0),
            ], (array) $summary['groups']),
            'reports' => $summary['reports'],
        ]);
        return $summary;
    }

    public function repair_blocked_category_mappings(array $productIds = [], string $marketplaceId = 'EBAY_DE', int $fallbackSampleSize = 200): array
    {
        $settings = $this->settings();
        $marketplaceId = trim($marketplaceId) !== '' ? trim($marketplaceId) : (string) ($settings['marketplace_id'] ?? 'EBAY_DE');
        $eligibleStatuses = ['category_sanity_failed', 'low_confidence_auto', 'needs_category_review', 'missing_category_mapping', 'unmapped', 'suggestion_failed'];
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
        if ($productIds === []) {
            $productIds = $this->recent_product_ids_for_repair($fallbackSampleSize);
        }

        $summary = [
            'processed' => 0,
            'fixed_count' => 0,
            'still_blocked_count' => 0,
            'no_candidate_count' => 0,
            'low_confidence_count' => 0,
            'fixed_product_ids' => [],
            'still_blocked_product_ids' => [],
            'top_block_reasons' => [],
            'repair_details' => [],
        ];
        $reasonCounts = [];
        $processedProducts = [];

        foreach ($productIds as $productId) {
            if (isset($processedProducts[$productId])) {
                continue;
            }
            $processedProducts[$productId] = true;
            $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
            if (is_wp_error($terms) || $terms === []) {
                $summary['processed']++;
                $summary['no_candidate_count']++;
                $reasonCounts['missing_product_category'] = ($reasonCounts['missing_product_category'] ?? 0) + 1;
                $summary['still_blocked_product_ids'][] = ['product_id' => $productId, 'reason' => 'missing_product_category'];
                continue;
            }

            $summary['processed']++;
            $fixed = false;
            $lastReason = 'missing_category_mapping';
            $lastResult = [];
            $fixedResult = [];
            foreach ((array) $terms as $term) {
                $termId = (int) ($term->term_id ?? 0);
                if ($termId <= 0) {
                    continue;
                }
                $existing = $this->categoryRepo->find($marketplaceId, $termId);
                if ($this->is_manual_mapping($existing)) {
                    continue;
                }
                $status = $existing ? (string) ($existing['status'] ?? 'missing_category_mapping') : 'missing_category_mapping';
                if (!in_array($status, $eligibleStatuses, true)) {
                    continue;
                }

                $result = $this->auto_map_term($termId, $marketplaceId, $settings);
                $newStatus = (string) ($result['status'] ?? 'suggestion_failed');
                if (in_array($newStatus, ['mapped_auto', 'mapped_manual_teaching'], true)) {
                    $fixed = true;
                    $fixedResult = $result;
                    break;
                }
                $lastResult = $result;
                $lastReason = (string) ($result['sanity_reason'] ?? $result['error_reason'] ?? $newStatus ?: 'needs_category_review');
                if ($newStatus === 'low_confidence_auto') {
                    $summary['low_confidence_count']++;
                }
                if (in_array($newStatus, ['unmapped', 'suggestion_failed'], true)) {
                    $summary['no_candidate_count']++;
                }
            }

            if ($fixed) {
                $summary['fixed_count']++;
                $fixedDetail = ['product_id' => $productId] + $this->repair_result_diagnostics($fixedResult);
                $summary['fixed_product_ids'][] = $fixedDetail;
                $summary['repair_details'][] = $fixedDetail;
                continue;
            }

            $summary['still_blocked_count']++;
            $reasonCounts[$lastReason] = ($reasonCounts[$lastReason] ?? 0) + 1;
            $blockedDetail = ['product_id' => $productId, 'reason' => $lastReason] + $this->repair_result_diagnostics($lastResult);
            $summary['still_blocked_product_ids'][] = $blockedDetail;
            $summary['repair_details'][] = $blockedDetail;
        }

        arsort($reasonCounts);
        $summary['top_block_reasons'] = array_slice($reasonCounts, 0, 5, true);
        update_option('wei_ebay_category_mapping_repair_report', $summary, false);
        $this->logger->info('Category mapping repair report', [
            'processed' => (int) $summary['processed'],
            'fixed_count' => (int) $summary['fixed_count'],
            'still_blocked_count' => (int) $summary['still_blocked_count'],
            'no_candidate_count' => (int) $summary['no_candidate_count'],
            'low_confidence_count' => (int) $summary['low_confidence_count'],
            'top_block_reasons' => (array) $summary['top_block_reasons'],
        ]);
        return $summary;
    }


    private function problem_reasons_from_csv(string $path): array
    {
        $reasons = [];
        foreach ($this->read_csv_assoc($path) as $row) {
            $status = (string) ($row['status'] ?? '');
            $reason = trim((string) ($row['reason'] ?? ''));
            if ($reason !== '' && in_array($status, ['blocked_by_category', 'missing_category'], true)) {
                $reasons[$reason] = true;
            }
        }
        return array_keys($reasons);
    }

    private function problem_product_ids_by_reason(string $path, array $targetReasons): array
    {
        $groups = array_fill_keys($targetReasons, []);
        if (!is_readable($path)) {
            return $groups;
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return $groups;
        }
        $headers = fgetcsv($fh);
        if (!is_array($headers)) {
            fclose($fh);
            return $groups;
        }
        $headers = array_map(static fn($header): string => trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
        while (($values = fgetcsv($fh)) !== false) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($values[$index] ?? '');
            }
            $status = (string) ($row['status'] ?? '');
            $reason = trim((string) ($row['reason'] ?? ''));
            if (!in_array($status, ['blocked_by_category', 'missing_category'], true) || !isset($groups[$reason])) {
                continue;
            }
            $productId = absint($row['product_id'] ?? 0);
            if ($productId > 0) {
                $groups[$reason][] = $productId;
            }
        }
        fclose($fh);
        foreach ($groups as $reason => $ids) {
            $groups[$reason] = array_values(array_unique(array_map('intval', $ids)));
        }
        return $groups;
    }

    private function write_repair_group_reports(array $summary, array $rows): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $slug = 'wei-ebay-category-group-repair-' . gmdate('Ymd-His');
        $csvPath = trailingslashit($baseDir) . $slug . '-diagnostics.csv';
        $jsonPath = trailingslashit($baseDir) . $slug . '-summary.json';
        $fh = fopen($csvPath, 'wb');
        if ($fh) {
            $headers = $rows !== [] ? array_keys($rows[0]) : ['audit_reason_group', 'product_id', 'result', 'reason', 'fallback_used', 'fallback_category_id', 'fallback_reason', 'manual_teaching_applied', 'manual_teaching_lookup_attempted', 'manual_teaching_rule_found', 'manual_teaching_rule_id', 'manual_teaching_category_id', 'manual_teaching_rejected_reason', 'mapping_write_attempted', 'mapping_write_result'];
            fputcsv($fh, $headers);
            foreach ($rows as $row) {
                fputcsv($fh, array_map(static fn($header) => (string) ($row[$header] ?? ''), $headers));
            }
            fclose($fh);
        }
        file_put_contents($jsonPath, wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return [
            'diagnostics_csv' => ['path' => $csvPath, 'url' => trailingslashit($baseUrl) . basename($csvPath), 'rows' => count($rows)],
            'summary_json' => ['path' => $jsonPath, 'url' => trailingslashit($baseUrl) . basename($jsonPath)],
        ];
    }

    private function repair_result_diagnostics(array $result): array
    {
        return [
            'fallback_used' => !empty($result['fallback_used']),
            'fallback_category_id' => (string) ($result['fallback_category_id'] ?? ''),
            'fallback_reason' => (string) ($result['fallback_reason'] ?? ''),
            'rejected_original_candidate' => (array) ($result['rejected_original_candidate'] ?? []),
            'manual_teaching_applied' => !empty($result['manual_teaching_applied']),
            'manual_teaching_lookup_attempted' => !empty($result['manual_teaching_lookup_attempted']),
            'manual_teaching_rule_found' => !empty($result['manual_teaching_rule_found']),
            'manual_teaching_rule_id' => (int) ($result['manual_teaching_rule_id'] ?? 0),
            'manual_teaching_category_id' => (string) ($result['manual_teaching_category_id'] ?? ''),
            'manual_teaching_rejected_reason' => (string) ($result['manual_teaching_rejected_reason'] ?? ''),
            'mapping_write_attempted' => !empty($result['mapping_write_attempted']),
            'mapping_write_result' => (string) ($result['mapping_write_result'] ?? ''),
        ];
    }

    private function recent_product_ids_for_repair(int $limit): array
    {
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => max(1, min(300, $limit)),
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'no_found_rows' => true,
        ]);
        return array_values(array_map('intval', (array) $query->posts));
    }

    public function auto_map_term(int $termId, string $marketplaceId = 'EBAY_DE', ?array $settings = null): array
    {
        $settings = $settings ?? $this->settings();
        $path = $this->categoryRepo->woo_category_path($termId);
        $samples = $this->categoryRepo->sample_products_for_category($termId, 5);
        $sampleIds = array_map(static fn(array $sample): int => (int) ($sample['id'] ?? 0), $samples);
        $querySource = $this->build_query($path, $samples);
        $query = $this->translate_query_to_german($querySource, $settings);

        $base = [
            'marketplace_id' => $marketplaceId,
            'woo_term_id' => $termId,
            'woo_category_path' => $path,
            'sample_product_ids' => wp_json_encode(array_values(array_filter($sampleIds))),
        ];

        $manualTeaching = $this->manual_teaching_result_for_term($termId, $marketplaceId, $path, $query, $samples, $settings, $base);
        if ($manualTeaching !== null) {
            return $manualTeaching;
        }
        $manualLookupDiagnostics = [
            'manual_teaching_lookup_attempted' => true,
            'manual_teaching_rule_found' => false,
            'manual_teaching_rule_id' => 0,
            'manual_teaching_category_id' => '',
            'manual_teaching_rejected_reason' => '',
            'mapping_write_attempted' => false,
            'mapping_write_result' => 'no_matching_teaching_rule',
        ];

        $result = $this->taxonomy->get_category_suggestions_result($marketplaceId, $query);
        if (($result['status'] ?? '') === 'taxonomy_api_forbidden') {
            $this->categoryRepo->upsert(array_merge($base, [
                'ebay_category_id' => '',
                'ebay_category_name' => '',
                'ebay_category_path' => '',
                'source' => 'suggestion',
                'confidence' => 0,
                'status' => 'taxonomy_api_forbidden',
                'error_reason' => (string) ($result['error'] ?? 'eBay Taxonomy API forbidden'),
                'suggestion_payload' => $this->debug_payload($querySource, $query, [], $result),
            ]));
            return array_merge($manualLookupDiagnostics, ['status' => 'taxonomy_api_forbidden']);
        }

        $suggestions = is_array($result['suggestions'] ?? null) ? $result['suggestions'] : [];
        $evaluation = $this->evaluate_candidates($suggestions, $path, $query, $samples, $marketplaceId, $settings);
        $best = is_array($evaluation['selected_candidate'] ?? null) ? $evaluation['selected_candidate'] : [];
        $categoryId = (string) ($best['category_id'] ?? '');
        $confidence = (float) ($best['confidence'] ?? $best['score'] ?? 0);
        $status = 'unmapped';
        $source = (string) ($best['source'] ?? 'suggestion');
        $errorReason = '';
        $safety = is_array($best['safety'] ?? null) ? $best['safety'] : ['threshold' => CategoryMappingSafety::threshold($settings), 'sanity_check_pass' => true, 'sanity_reason' => ''];

        if ($suggestions === [] && empty($evaluation['top_candidates'])) {
            $status = ($result['status'] ?? '') === 'ok' ? 'unmapped' : 'suggestion_failed';
            $errorReason = (string) ($result['error'] ?? 'No eBay category suggestions returned');
        } elseif ($categoryId === '') {
            $status = 'suggestion_failed';
            $errorReason = 'Suggestion payload did not include a usable categoryId';
        } else {
            $source = in_array($source, ['taxonomy_suggestion', 'local_tree_index', 'intent_fallback'], true) ? 'auto_taxonomy' : $source;
            if (!empty($safety['accepted'])) {
                $status = 'mapped_auto';
            } elseif (($safety['status'] ?? '') === 'category_sanity_failed') {
                $status = 'category_sanity_failed';
                $errorReason = (string) ($safety['sanity_reason'] ?? 'category mapping requires review');
            } elseif ($confidence >= self::REVIEW_CONFIDENCE_THRESHOLD) {
                $status = 'low_confidence_auto';
                $errorReason = 'Best candidate confidence below auto-accept threshold';
            } else {
                $status = 'needs_category_review';
                $errorReason = 'Best candidate confidence below review threshold';
            }
        }

        $best['safety'] = $safety;
        $evaluation['selected_candidate'] = $best;
        if ($errorReason === '' && !empty($evaluation['rejected_best_reason'])) {
            $errorReason = (string) $evaluation['rejected_best_reason'];
        }

        $this->categoryRepo->upsert(array_merge($base, [
            'ebay_category_id' => in_array($status, ['mapped_auto', 'low_confidence_auto', 'category_sanity_failed', 'needs_category_review'], true) ? $categoryId : '',
            'ebay_category_name' => (string) ($best['category_name'] ?? ''),
            'ebay_category_path' => (string) ($best['category_path'] ?? ''),
            'source' => $source,
            'confidence' => $confidence,
            'status' => $status,
            'error_reason' => $errorReason,
            'suggestion_payload' => $this->debug_payload($querySource, $query, $best, $result, $evaluation),
        ]));

        $this->logger->info('Auto category mapping evaluated', ['woo_term_id' => $termId, 'status' => $status, 'confidence' => $confidence, 'threshold' => (float) ($safety['threshold'] ?? CategoryMappingSafety::threshold($settings)), 'sanity_check_pass' => !empty($safety['sanity_check_pass']), 'sanity_reason' => (string) ($safety['sanity_reason'] ?? ''), 'category_id' => $categoryId, 'candidate_source' => $source]);
        return array_merge($manualLookupDiagnostics, ['status' => $status, 'confidence' => $confidence, 'threshold' => (float) ($safety['threshold'] ?? CategoryMappingSafety::threshold($settings)), 'sanity_check_pass' => !empty($safety['sanity_check_pass']), 'sanity_reason' => (string) ($safety['sanity_reason'] ?? ''), 'category_id' => $categoryId, 'fallback_used' => !empty($evaluation['fallback_used']), 'fallback_category_id' => (string) ($evaluation['fallback_category_id'] ?? ''), 'fallback_reason' => (string) ($evaluation['fallback_reason'] ?? ''), 'rejected_original_candidate' => (array) ($evaluation['rejected_original_candidate'] ?? [])]);
    }


    private function manual_teaching_result_for_term(int $termId, string $marketplaceId, string $path, string $query, array $samples, array $settings, array $base): ?array
    {
        $sampleTitle = implode(' ', array_map(static fn(array $sample): string => (string) ($sample['title'] ?? ''), $samples));
        $intent = CategoryMappingSafety::detect_intent(trim($path . ' ' . $query . ' ' . $sampleTitle));
        $family = $this->categoryRepo->keyword_family_from_title($sampleTitle);
        $rule = $this->categoryRepo->find_teaching_rule($marketplaceId, $path, $intent, $sampleTitle, $family);
        $lookupDiagnostics = [
            'manual_teaching_lookup_attempted' => true,
            'manual_teaching_rule_found' => is_array($rule),
            'manual_teaching_rule_id' => is_array($rule) ? (int) ($rule['id'] ?? 0) : 0,
            'manual_teaching_category_id' => is_array($rule) ? (string) ($rule['ebay_category_id'] ?? '') : '',
            'manual_teaching_rejected_reason' => '',
            'mapping_write_attempted' => false,
            'mapping_write_result' => is_array($rule) ? 'not_attempted' : 'no_matching_teaching_rule',
        ];
        if (!$rule || trim((string) ($rule['ebay_category_id'] ?? '')) === '') {
            return null;
        }

        $categoryId = (string) $rule['ebay_category_id'];
        $categoryPath = (string) ($rule['ebay_category_path'] ?? '');
        $details = $categoryPath === '' ? $this->taxonomy->get_category_details_result($marketplaceId, $categoryId) : [];
        $categoryName = (string) ($details['category_name'] ?? '');
        if ($categoryPath === '') {
            $categoryPath = (string) ($details['category_path'] ?? '');
        }
        $categoryText = trim($categoryPath . ' ' . $categoryName . ' ' . $categoryId);
        $safety = CategoryMappingSafety::evaluate_auto_mapping(trim($path . ' ' . $query . ' ' . $sampleTitle), $categoryText, 1.0, $settings);
        if (empty($safety['sanity_check_pass'])) {
            $reason = (string) ($safety['sanity_reason'] ?? 'manual_teaching_rule_failed_safety');
            $this->logger->warning('Manual teaching category rule failed safety checks', ['woo_term_id' => $termId, 'category_id' => $categoryId, 'sanity_reason' => $reason]);
            return array_merge($lookupDiagnostics, ['status' => 'category_sanity_failed', 'confidence' => 1.0, 'threshold' => (float) ($safety['threshold'] ?? CategoryMappingSafety::threshold($settings)), 'sanity_check_pass' => false, 'sanity_reason' => $reason, 'category_id' => $categoryId, 'manual_teaching_applied' => false, 'manual_teaching_rejected_reason' => $reason, 'mapping_write_result' => 'rejected_by_safety']);
        }

        $payload = wp_json_encode([
            'query_source' => mb_substr($query, 0, 500),
            'intent' => $intent,
            'title_keyword_family' => $family,
            'manual_teaching' => true,
            'manual_teaching_rule' => $rule,
            'safety' => $safety,
        ], JSON_UNESCAPED_UNICODE);
        $this->categoryRepo->upsert(array_merge($base, [
            'ebay_category_id' => $categoryId,
            'ebay_category_name' => $categoryName,
            'ebay_category_path' => $categoryPath,
            'source' => 'manual_teaching_csv',
            'confidence' => 1,
            'status' => 'mapped_manual_teaching',
            'error_reason' => '',
            'suggestion_payload' => $payload,
        ]));
        $this->logger->info('Manual teaching category mapping applied', ['woo_term_id' => $termId, 'category_id' => $categoryId, 'intent' => $intent, 'rule_id' => (int) ($rule['id'] ?? 0)]);
        return array_merge($lookupDiagnostics, ['status' => 'mapped_manual_teaching', 'confidence' => 1.0, 'threshold' => (float) ($safety['threshold'] ?? CategoryMappingSafety::threshold($settings)), 'sanity_check_pass' => true, 'sanity_reason' => '', 'category_id' => $categoryId, 'manual_teaching_applied' => true, 'fallback_used' => false, 'fallback_category_id' => '', 'fallback_reason' => '', 'mapping_write_attempted' => true, 'mapping_write_result' => 'written_mapped_manual_teaching']);
    }

    private function is_manual_mapping(?array $mapping): bool
    {
        if (!$mapping || trim((string) ($mapping['ebay_category_id'] ?? '')) === '') {
            return false;
        }

        $status = (string) ($mapping['status'] ?? '');
        $source = (string) ($mapping['source'] ?? '');
        return in_array($status, ['mapped_manual', 'mapped_manual_teaching'], true) || ($status === '' && $source === 'manual') || in_array($source, ['manual', 'manual_teaching_csv'], true);
    }

    private function reevaluate_existing_mapping(?array $mapping, array $settings): ?array
    {
        if (!$mapping || trim((string) ($mapping['ebay_category_id'] ?? '')) === '') {
            return null;
        }

        $status = (string) ($mapping['status'] ?? '');
        $source = (string) ($mapping['source'] ?? '');
        $eligible = ['mapped_auto', 'low_confidence_auto', 'category_sanity_failed', 'needs_category_review'];
        if ($source !== 'auto_taxonomy' && !in_array($status, $eligible, true)) {
            return null;
        }

        $mappingText = trim((string) (($mapping['ebay_category_path'] ?? '') . ' ' . ($mapping['ebay_category_name'] ?? '')));
        $wooPath = (string) ($mapping['woo_category_path'] ?? '');
        $existingSafety = CategoryMappingSafety::sanity_check($wooPath, $mappingText);
        $shouldSearchAgain = in_array($status, ['category_sanity_failed', 'needs_category_review', 'low_confidence_auto'], true)
            || (CategoryMappingSafety::is_sonstige_category($mappingText) && CategoryMappingSafety::is_specific_woo_category($wooPath))
            || empty($existingSafety['pass']);
        if ($shouldSearchAgain && (int) ($mapping['woo_term_id'] ?? 0) > 0) {
            return $this->auto_map_term((int) $mapping['woo_term_id'], (string) ($mapping['marketplace_id'] ?? 'EBAY_DE'), $settings);
        }

        $safety = CategoryMappingSafety::evaluate_auto_mapping(
            $wooPath,
            $mappingText,
            (float) ($mapping['confidence'] ?? 0),
            $settings
        );

        $newStatus = !empty($safety['accepted']) ? 'mapped_auto' : (string) ($safety['status'] ?? 'needs_category_review');
        $reason = '';
        if ($newStatus === 'category_sanity_failed') {
            $reason = (string) ($safety['sanity_reason'] ?? 'category mapping requires review');
        } elseif ($newStatus === 'low_confidence_auto') {
            $reason = 'Best suggestion confidence below auto-accept threshold';
        } elseif ($newStatus === 'needs_category_review') {
            $reason = 'Category mapping requires review';
        }

        $this->categoryRepo->upsert(array_merge($mapping, [
            'source' => 'auto_taxonomy',
            'status' => $newStatus,
            'error_reason' => $reason,
        ]));

        return ['status' => $newStatus, 'confidence' => (float) ($mapping['confidence'] ?? 0), 'threshold' => (float) ($safety['threshold'] ?? CategoryMappingSafety::threshold($settings)), 'sanity_check_pass' => !empty($safety['sanity_check_pass']), 'sanity_reason' => (string) ($safety['sanity_reason'] ?? ''), 'category_id' => (string) ($mapping['ebay_category_id'] ?? '')];
    }

    private function build_query(string $path, array $samples): string
    {
        $parts = [$path];
        foreach ($samples as $sample) {
            foreach (['title', 'description', 'mpn', 'manufacturer'] as $key) {
                $value = trim((string) ($sample[$key] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_unique($parts))) ?: '');
    }

    private function translate_query_to_german(string $query, array $settings): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $cacheKey = 'wei_cat_query_de_' . md5($query);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $translated = $query;
        $providerKey = strtolower((string) ($settings['translation_provider'] ?? 'disabled'));
        if ($providerKey === 'google') {
            $providerKey = 'google_cloud_translate';
        }

        if ($providerKey === 'google_cloud_translate') {
            try {
                $provider = new GoogleCloudTranslateProvider($settings, $this->logger);
                if ($provider->is_configured() && method_exists($provider, 'translate_texts')) {
                    $values = $provider->translate_texts([$query], 'pl', 'de', 'text');
                    $translated = trim((string) ($values[0] ?? $query));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Category mapping query translation failed; using source query', ['error' => $e->getMessage()]);
            }
        }

        $translated = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: $query);
        set_transient($cacheKey, $translated, DAY_IN_SECONDS * 14);
        return $translated;
    }

    private function evaluate_candidates(array $suggestions, string $wooPath, string $query, array $samples, string $marketplaceId, array $settings): array
    {
        $candidates = [];
        $position = 0;
        foreach (array_slice($suggestions, 0, 20) as $suggestion) {
            $position++;
            if (!is_array($suggestion)) {
                continue;
            }
            $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
            $categoryId = trim((string) ($category['categoryId'] ?? ''));
            $categoryName = trim((string) ($category['categoryName'] ?? ''));
            $path = $this->suggestion_path($suggestion, $categoryName);
            $candidates[] = $this->build_candidate($categoryId, $categoryName, $path, $position, 'taxonomy_suggestion', $suggestion, $wooPath, $query, $samples, $marketplaceId, $settings, $this->suggestion_is_leaf($suggestion));
        }

        $needsLocalFallback = $candidates === [];
        foreach ($candidates as $candidate) {
            if (empty($candidate['sanity_pass']) || !empty($candidate['is_sonstige'])) {
                $needsLocalFallback = true;
                break;
            }
        }
        if ($needsLocalFallback) {
            $localPosition = 0;
            foreach ($this->taxonomy->search_local_category_index($marketplaceId, $wooPath . ' ' . $query, CategoryMappingSafety::expected_path_keywords($wooPath . ' ' . $query), 20) as $local) {
                $localPosition++;
                if (!is_array($local)) {
                    continue;
                }
                $candidates[] = $this->build_candidate(
                    (string) ($local['category_id'] ?? ''),
                    (string) ($local['category_name'] ?? ''),
                    (string) ($local['category_path'] ?? ''),
                    $localPosition,
                    'local_tree_index',
                    ['leafCategoryTreeNode' => !empty($local['is_leaf']), 'local_index_score' => (float) ($local['index_score'] ?? 0)],
                    $wooPath,
                    $query,
                    $samples,
                    $marketplaceId,
                    $settings,
                    !empty($local['is_leaf'])
                );
            }
        }

        usort($candidates, static fn(array $a, array $b): int => ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0)));
        $selected = [];
        foreach ($candidates as $candidate) {
            if (!empty($candidate['sanity_pass'])) {
                $selected = $candidate;
                break;
            }
        }
        if ($selected === [] && $candidates !== []) {
            $selected = $candidates[0];
        }

        $fallbackUsed = false;
        $fallbackReason = '';
        $rejectedOriginalCandidate = $selected !== [] ? $this->candidate_debug_summary($selected) : [];
        if (($selected === [] || empty($selected['sanity_pass'])) && CategoryMappingSafety::detect_intent($wooPath . ' ' . $query) !== '') {
            $fallback = $this->intent_fallback_candidate($wooPath, $query, $samples, $marketplaceId, $settings, $candidates);
            if ($fallback !== []) {
                $candidates[] = $fallback;
                $selected = $fallback;
                $fallbackUsed = true;
                $fallbackReason = (string) ($fallback['fallback_reason'] ?? 'intent_safe_family_after_rejections');
            }
        }

        $rawBest = [];
        foreach ($candidates as $candidate) {
            if (($candidate['source'] ?? '') === 'taxonomy_suggestion' && ((int) ($candidate['raw_position'] ?? 0)) === 1) {
                $rawBest = $candidate;
                break;
            }
        }

        $rejectedBestReason = '';
        if ($rawBest !== [] && $selected !== [] && (string) ($rawBest['category_id'] ?? '') !== (string) ($selected['category_id'] ?? '')) {
            $rejectedBestReason = (string) ($rawBest['sanity_reason'] ?? 'lower_scored_candidate');
            if (!empty($rawBest['is_sonstige'])) {
                $rejectedBestReason = 'first_suggestion_was_sonstige';
            } elseif (empty($rawBest['sanity_pass'])) {
                $rejectedBestReason = 'first_suggestion_failed_sanity_' . $rejectedBestReason;
            }
        }

        $rejectedCandidates = [];
        foreach ($candidates as $candidate) {
            if ((string) ($candidate['sanity_reason'] ?? '') !== '') {
                $rejectedCandidates[] = $this->candidate_debug_summary($candidate);
            }
        }

        return [
            'intent' => CategoryMappingSafety::detect_intent($wooPath . ' ' . $query),
            'intent_source_text_used' => CategoryMappingSafety::normalized_intent_source_text($wooPath . ' ' . $query),
            'why_no_intent_match' => CategoryMappingSafety::detect_intent($wooPath . ' ' . $query) === '' ? 'no_keywords_matched' : '',
            'top_candidates' => array_slice(array_map(fn(array $candidate): array => $this->candidate_debug_summary($candidate), $candidates), 0, self::TOP_CANDIDATE_LIMIT),
            'rejected_candidates' => array_slice($rejectedCandidates, 0, self::TOP_CANDIDATE_LIMIT),
            'selected_candidate' => $selected,
            'rejected_best_reason' => $rejectedBestReason,
            'fallback_used' => $fallbackUsed,
            'fallback_category_id' => $fallbackUsed ? (string) ($selected['category_id'] ?? '') : '',
            'fallback_reason' => $fallbackReason,
            'rejected_original_candidate' => $rejectedOriginalCandidate,
        ];
    }

    private function intent_fallback_candidate(string $wooPath, string $query, array $samples, string $marketplaceId, array $settings, array $existingCandidates): array
    {
        $context = $wooPath . ' ' . $query;
        $intent = CategoryMappingSafety::detect_intent($context);
        $fallbackKeywords = $this->intent_fallback_keywords($intent);
        if ($fallbackKeywords === []) {
            return [];
        }

        $existingIds = [];
        foreach ($existingCandidates as $candidate) {
            $id = (string) ($candidate['category_id'] ?? '');
            if ($id !== '') {
                $existingIds[$id] = true;
            }
        }

        $fallbackQuery = trim($context . ' ' . implode(' ', $fallbackKeywords));
        $position = 1000;
        foreach ($this->taxonomy->search_local_category_index($marketplaceId, $fallbackQuery, $fallbackKeywords, 30) as $local) {
            if (!is_array($local)) {
                continue;
            }
            $categoryId = (string) ($local['category_id'] ?? '');
            if ($categoryId === '' || isset($existingIds[$categoryId])) {
                continue;
            }
            $candidate = $this->build_candidate(
                $categoryId,
                (string) ($local['category_name'] ?? ''),
                (string) ($local['category_path'] ?? ''),
                ++$position,
                'intent_fallback',
                ['leafCategoryTreeNode' => !empty($local['is_leaf']), 'local_index_score' => (float) ($local['index_score'] ?? 0), 'fallback_intent' => $intent],
                $wooPath,
                $query,
                $samples,
                $marketplaceId,
                $settings,
                !empty($local['is_leaf'])
            );
            if (!empty($candidate['sanity_pass']) && CategoryMappingSafety::matched_keywords_for_intent($intent, (string) ($candidate['category_path'] ?? '') . ' ' . (string) ($candidate['category_name'] ?? '')) !== []) {
                $candidate['score'] = max((float) ($candidate['score'] ?? 0), (float) CategoryMappingSafety::threshold($settings));
                $candidate['confidence'] = $candidate['score'];
                $candidate['safety'] = CategoryMappingSafety::evaluate_auto_mapping($wooPath . ' ' . $query, (string) ($candidate['category_path'] ?? '') . ' ' . (string) ($candidate['category_name'] ?? ''), (float) $candidate['score'], $settings);
                $candidate['sanity_pass'] = !empty($candidate['safety']['sanity_check_pass']);
                $candidate['sanity_reason'] = (string) ($candidate['safety']['sanity_reason'] ?? '');
                $candidate['fallback_reason'] = 'intent_safe_family_after_rejected_taxonomy_and_local_candidates';
                return $candidate;
            }
        }

        return [];
    }

    private function intent_fallback_keywords(string $intent): array
    {
        return match ($intent) {
            'power_steering_hose' => ['servolenkung', 'hydraulikleitung', 'lenkung', 'leitung', 'schlauch'],
            'tow_hook' => ['anhangerkupplung', 'anhaengerkupplung', 'abschlepphaken', 'abschleppose', 'abschleppoese', 'zugvorrichtung'],
            'ac_hose' => ['klimaanlage', 'klimaleitung', 'kaltemittelleitung', 'kaeltemittelleitung', 'leitung', 'schlauch'],
            'hvac_blower' => ['heizung', 'klimaanlage', 'geblase', 'geblaese', 'lufter', 'luefter', 'innenraum'],
            'adblue_hose' => ['abgasreinigung', 'adblue', 'harnstoffleitung', 'leitung', 'schlauch'],
            'wiring_harness' => ['kabelbaum', 'leitungssatz', 'elektrik', 'kabel', 'bordnetz'],
            'bumper_reinforcement' => ['stossstange', 'stosstange', 'pralltrager', 'pralltraeger', 'verstarkung', 'verstaerkung', 'aufpralldampfer'],
            'interior_trim' => ['innenausstattung', 'verkleidung', 'zierleiste', 'dekorleiste', 'armaturenbrett', 'mittelkonsole', 'blende', 'rahmen'],
            'usb_socket', 'media_port' => ['usb', 'anschluss', 'buchse', 'steckdose', 'multimedia'],
            'roof_antenna' => ['antenne', 'dachantenne', 'antennenfuss', 'antennefuss', 'shark'],
            'gearbox_mount' => ['getriebelager', 'getriebehalter', 'lagerung', 'halter', 'aufhangung', 'aufhaengung'],
            'spare_wheel' => ['ersatzrad', 'notrad', 'reserverad', 'felge', 'felgen'],
            default => [],
        };
    }

    private function build_candidate(string $categoryId, string $categoryName, string $path, int $position, string $source, array $raw, string $wooPath, string $query, array $samples, string $marketplaceId, array $settings, bool $isLeaf): array
    {
        $required = $categoryId !== '' ? $this->taxonomy->get_required_aspects($marketplaceId, $categoryId) : [];
        $scoringRaw = $raw + ['category' => ['categoryName' => $categoryName]];
        $score = $this->score_suggestion($wooPath, $query, $path . ' ' . $categoryName, $scoringRaw, $samples, $required, $isLeaf);
        $safetyContext = trim($wooPath . ' ' . $query);
        $categoryText = trim($path . ' ' . $categoryName);
        $safety = CategoryMappingSafety::evaluate_auto_mapping($safetyContext, $categoryText, $score, $settings);
        $intent = CategoryMappingSafety::detect_intent($safetyContext);
        $matchedIntentKeywords = $intent !== '' ? CategoryMappingSafety::matched_keywords_for_intent($intent, $categoryText) : [];
        if ($intent !== '' && CategoryMappingSafety::expected_keywords_for_intent($intent) !== [] && $matchedIntentKeywords === []) {
            $safety['accepted'] = false;
            $safety['status'] = 'category_sanity_failed';
            $safety['ui_status'] = 'blocked_by_sanity';
            $safety['sanity_check_pass'] = false;
            $safety['sanity_reason'] = 'expected_path_keyword_missing';
        }
        $isSonstige = CategoryMappingSafety::is_sonstige_category($categoryText);

        return [
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'category_path' => $path,
            'raw_position' => $position,
            'score' => $score,
            'confidence' => $score,
            'sanity_pass' => !empty($safety['sanity_check_pass']),
            'sanity_reason' => (string) ($safety['sanity_reason'] ?? ''),
            'is_sonstige' => $isSonstige,
            'required_aspects' => $required,
            'source' => $source,
            'detected_intent' => $intent,
            'matched_intent_keywords' => $matchedIntentKeywords,
            'is_leaf' => $isLeaf,
            'safety' => $safety,
            'raw_summary' => $this->summarize_suggestion($raw),
        ];
    }

    private function candidate_debug_summary(array $candidate): array
    {
        $intent = (string) ($candidate['detected_intent'] ?? '');
        $categoryText = trim((string) (($candidate['category_path'] ?? '') . ' ' . ($candidate['category_name'] ?? '')));
        $sanityReason = (string) ($candidate['sanity_reason'] ?? '');
        $matchedKeywords = array_values(array_map('strval', (array) ($candidate['matched_intent_keywords'] ?? [])));
        if ($intent !== '' && $matchedKeywords === []) {
            $matchedKeywords = CategoryMappingSafety::matched_keywords_for_intent($intent, $categoryText);
        }
        $zeroIntentKeywordMatch = $intent !== '' && CategoryMappingSafety::expected_keywords_for_intent($intent) !== [] && $matchedKeywords === [];
        $accepted = !empty($candidate['sanity_pass']) && $sanityReason === '' && !$zeroIntentKeywordMatch;
        if ($zeroIntentKeywordMatch && $sanityReason === '') {
            $sanityReason = 'expected_path_keyword_missing';
        }
        return [
            'category_id' => (string) ($candidate['category_id'] ?? ''),
            'category_name' => (string) ($candidate['category_name'] ?? ''),
            'category_path' => (string) ($candidate['category_path'] ?? ''),
            'name' => (string) ($candidate['category_name'] ?? ''),
            'path' => (string) ($candidate['category_path'] ?? ''),
            'raw_position' => (int) ($candidate['raw_position'] ?? 0),
            'score' => (float) ($candidate['score'] ?? 0),
            'confidence' => (float) ($candidate['confidence'] ?? $candidate['score'] ?? 0),
            'reason' => $accepted ? 'accepted' : ($sanityReason !== '' ? 'rejected: ' . $sanityReason : 'rejected: lower_scored_candidate'),
            'sanity_pass' => !empty($candidate['sanity_pass']),
            'sanity_reason' => $sanityReason,
            'matched_keywords' => $matchedKeywords,
            'rejected_by_guard_reason' => $sanityReason,
            'is_sonstige' => !empty($candidate['is_sonstige']),
            'required_aspects' => (array) ($candidate['required_aspects'] ?? []),
            'source' => (string) ($candidate['source'] ?? ''),
        ];
    }

    private function score_suggestion(string $wooPath, string $query, string $suggestionText, array $suggestion, array $samples, array $requiredAspects, bool $isLeaf): float
    {
        $queryTokens = $this->tokens($wooPath . ' ' . $query);
        $suggestionTokens = $this->tokens($suggestionText);
        $overlapDenominator = max(1, min(count(array_unique($queryTokens)), count(array_unique($suggestionTokens))));
        $overlap = $queryTokens === [] ? 0 : count(array_intersect($queryTokens, $suggestionTokens)) / $overlapDenominator;
        $score = 0.42 + min(0.24, $overlap * 0.40);

        $automotiveTokens = ['auto', 'fahrzeug', 'kabel', 'leitung', 'kabelbaum', 'motor', 'motoren', 'teile', 'ersatzteile', 'oe', 'oem', 'mpn', 'hersteller'];
        if (array_intersect($queryTokens, $automotiveTokens) || array_intersect($suggestionTokens, $automotiveTokens)) {
            $score += 0.10;
        }

        $isSonstige = CategoryMappingSafety::is_sonstige_category($suggestionText);
        $queryLooksSpecific = CategoryMappingSafety::is_specific_woo_category($wooPath . ' ' . $query);
        $context = $wooPath . ' ' . $query;
        $expected = CategoryMappingSafety::expected_path_keywords($context);
        $expectedMatches = $expected === [] ? [] : array_intersect($expected, $suggestionTokens);
        if ($expected !== []) {
            $normalizedSuggestionText = strtolower(remove_accents(wp_strip_all_tags($suggestionText)));
            $categoryNameText = strtolower(remove_accents(wp_strip_all_tags((string) ($suggestion['category']['categoryName'] ?? ''))));
            foreach ($expected as $keyword) {
                if ($keyword !== '' && str_contains($categoryNameText, $keyword)) {
                    $score += 0.24;
                    break;
                }
            }
            if ($expectedMatches !== []) {
                $score += min(0.30, 0.14 + (count($expectedMatches) * 0.06));
            } elseif ($this->contains_any_text($normalizedSuggestionText, $expected)) {
                $score += 0.10;
            }
        }

        if (CategoryMappingSafety::is_complete_engine_intent($context)) {
            if (CategoryMappingSafety::matched_expected_keywords($context, $suggestionText)) {
                $score += 0.20;
            }
            $normalizedSuggestion = strtolower(remove_accents(wp_strip_all_tags($suggestionText)));
            if ($this->contains_any_text($normalizedSuggestion, CategoryMappingSafety::complete_engine_part_negative_keywords())) {
                $score -= 0.65;
            }
        }

        $intent = CategoryMappingSafety::detect_intent($context);
        $normalizedSuggestion = strtolower(remove_accents(wp_strip_all_tags($suggestionText)));
        if ($intent === 'spare_wheel') {
            if ($this->contains_any_text($normalizedSuggestion, ['ersatzrad', 'notrad', 'reserverad', 'felge', 'felgen'])) {
                $score += 0.35;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['komplettrader', 'komplettraeder', 'automobile', 'fahrzeuge', 'mercedes-benz'])) {
                $score -= 0.75;
            }
        }
        if ($intent !== '' && CategoryMappingSafety::expected_keywords_for_intent($intent) !== []) {
            $matchedIntentKeywords = CategoryMappingSafety::matched_keywords_for_intent($intent, $suggestionText);
            if ($matchedIntentKeywords !== []) {
                $score += min(0.42, 0.22 + (count($matchedIntentKeywords) * 0.05));
            } else {
                $score -= 0.95;
            }
        }
        if ($this->contains_any_text($normalizedSuggestion, ['motorrad- & rollerteile', 'motorradteile', 'rollerteile']) && $intent !== 'motorcycle') {
            $score -= 1.00;
        }
        if ($intent === 'wiring_harness') {
            if ($this->contains_any_text($normalizedSuggestion, ['kabelbaum', 'leitungssatz', 'kabel', 'elektrik', 'bordnetz', 'steckverbinder', 'anlasser', 'lichtmaschine', 'generator'])) {
                $score += 0.35;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['fensterheber', 'fensterhebermotor', 'fensterhebermotoren', 'window regulator', 'window motor'])) {
                $score -= 0.85;
            }
        }
        if ($intent === 'power_steering_hose') {
            if ($this->contains_any_text($normalizedSuggestion, ['servolenkung']) && $this->contains_any_text($normalizedSuggestion, ['leitung', 'schlauch'])) {
                $score += 0.40;
            } elseif ($this->contains_any_text($normalizedSuggestion, ['servolenkung', 'leitung', 'schlauch'])) {
                $score += 0.20;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['motoren', 'motorenteile', 'motorteile', 'motorblock', 'motorblocke', 'motorbloecke', 'komplettmotoren', 'komplettmotor', 'motorrad'])) {
                $score -= 0.85;
            }
        }
        if ($intent === 'ac_hose') {
            if ($this->contains_any_text($normalizedSuggestion, ['klimaleitung', 'kaltemittelleitung', 'kaeltemittelleitung', 'leitung', 'schlauch', 'klimaschlauch', 'klimaanlage'])) {
                $score += 0.35;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['motoren', 'motorenteile', 'motorteile']) && !$this->contains_any_text($normalizedSuggestion, ['klimaleitung', 'kaltemittelleitung', 'kaeltemittelleitung', 'schlauch', 'klimaanlage'])) {
                $score -= 0.85;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['klimakompressor', 'kompressoren', 'kompressor', 'kupplungen']) && !$this->contains_any_text($normalizedSuggestion, ['klimaleitung', 'kaltemittelleitung', 'kaeltemittelleitung', 'leitung', 'schlauch'])) {
                $score -= 0.75;
            }
        }
        if ($intent === 'car_speaker' && $this->contains_any_text($normalizedSuggestion, ['fensterheber', 'motoren'])) {
            $score -= 0.75;
        }
        if ($intent === 'tow_hook') {
            if ($this->contains_any_text($normalizedSuggestion, ['anhangerkupplung', 'anhaengerkupplung', 'abschlepphaken', 'abschleppose', 'abschleppoese', 'zugvorrichtung', 'anhangevorrichtung', 'anhaengevorrichtung'])) {
                $score += 0.35;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['wischerarme', 'wischerarm', 'motoren', 'motorblock', 'motorblocke', 'motorbloecke', 'motorteile', 'pleuel', 'hauptlager', 'sonstige'])) {
                $score -= 0.90;
            }
        }
        if ($intent === 'interior_trim') {
            if ($this->contains_any_text($normalizedSuggestion, ['zierleiste', 'dekorleiste', 'dekoreinlage', 'dekor', 'armaturenbrett', 'mittelkonsole', 'innenausstattung', 'verkleidung'])) {
                $score += 0.35;
            }
            if ($this->contains_any_text($normalizedSuggestion, ['lautsprecher', 'audio', 'soundsystem', 'autoradio', 'hi-fi', 'hifi', 'motorrad'])) {
                $score -= 0.90;
            }
        }
        if (in_array($intent, ['hvac_control_panel', 'hvac_blower'], true) && $this->contains_any_text($normalizedSuggestion, ['motoren', 'motorteile', 'pleuel', 'hauptlager'])) {
            $score -= 0.75;
        }

        if ($isLeaf && !$isSonstige) {
            $score += 0.10;
        } elseif ($isLeaf) {
            $score += 0.01;
        }

        $hasIdentifiers = false;
        $hasManufacturer = false;
        foreach ($samples as $sample) {
            $hasIdentifiers = $hasIdentifiers || trim((string) ($sample['mpn'] ?? '')) !== '';
            $hasManufacturer = $hasManufacturer || trim((string) ($sample['manufacturer'] ?? '')) !== '';
        }
        if ($requiredAspects !== [] && in_array('Hersteller', $requiredAspects, true) && $hasManufacturer) {
            $score += 0.08;
        } elseif ($requiredAspects !== [] && $this->sample_aspects_look_resolvable($requiredAspects, $samples)) {
            $score += 0.04;
        }

        if ($requiredAspects === [] && $queryLooksSpecific && ($isSonstige || !CategoryMappingSafety::matched_expected_keywords($context, $suggestionText))) {
            $score -= 0.08;
        }
        if ($isSonstige) {
            $score -= $queryLooksSpecific ? 0.50 : 0.30;
        }
        if ($queryLooksSpecific && !CategoryMappingSafety::matched_expected_keywords($context, $suggestionText)) {
            $score -= 0.30;
        }
        $sanity = CategoryMappingSafety::sanity_check($context, $suggestionText);
        if (empty($sanity['pass'])) {
            $score -= 0.20;
        }

        return round(min(0.99, max(0.0, $score)), 4);
    }

    private function contains_any_text(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function sample_aspects_look_resolvable(array $requiredAspects, array $samples): bool
    {
        foreach ($requiredAspects as $aspect) {
            $aspect = mb_strtolower((string) $aspect);
            foreach ($samples as $sample) {
                if ((str_contains($aspect, 'hersteller') || str_contains($aspect, 'marke')) && trim((string) ($sample['manufacturer'] ?? '')) !== '') {
                    return true;
                }
                if ((str_contains($aspect, 'referenz') || str_contains($aspect, 'nummer') || str_contains($aspect, 'oe')) && trim((string) ($sample['mpn'] ?? '')) !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    private function suggestion_path(array $suggestion, string $categoryName): string
    {
        $names = [];
        foreach ((array) ($suggestion['categoryTreeNodeAncestors'] ?? []) as $ancestor) {
            if (is_array($ancestor)) {
                $name = trim((string) ($ancestor['categoryName'] ?? $ancestor['category']['categoryName'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        if ($categoryName !== '') {
            $names[] = $categoryName;
        }
        return implode(' > ', array_values(array_unique($names)));
    }

    private function suggestion_is_leaf(array $suggestion): bool
    {
        if (isset($suggestion['category']['leafCategoryTreeNode'])) {
            return (bool) $suggestion['category']['leafCategoryTreeNode'];
        }
        if (isset($suggestion['leafCategoryTreeNode'])) {
            return (bool) $suggestion['leafCategoryTreeNode'];
        }
        return empty($suggestion['childCategoryTreeNodes']);
    }

    private function tokens(string $text): array
    {
        $text = strtolower(remove_accents(wp_strip_all_tags($text)));
        $text = str_replace(['ß', 'ü', 'ö', 'ä'], ['ss', 'u', 'o', 'a'], $text);
        $parts = preg_split('/[^a-z0-9]+/u', $text) ?: [];
        $stop = ['und', 'oder', 'der', 'die', 'das', 'ein', 'eine', 'do', 'dla', 'oraz', 'motoryzacja', 'czesci', 'samochodowe', 'the', 'and'];
        return array_values(array_unique(array_filter($parts, static fn(string $token): bool => mb_strlen($token) >= 3 && !in_array($token, $stop, true))));
    }

    private function debug_payload(string $sourceQuery, string $translatedQuery, array $best, array $result, array $evaluation = []): string
    {
        return wp_json_encode([
            'query_source' => mb_substr($sourceQuery, 0, 500),
            'query_de' => mb_substr($translatedQuery, 0, 500),
            'intent' => (string) ($evaluation['intent'] ?? ''),
            'intent_source_text_used' => (string) ($evaluation['intent_source_text_used'] ?? ''),
            'why_no_intent_match' => (string) ($evaluation['why_no_intent_match'] ?? ''),
            'top_candidates' => (array) ($evaluation['top_candidates'] ?? []),
            'rejected_candidates' => (array) ($evaluation['rejected_candidates'] ?? []),
            'selected_candidate' => $this->candidate_debug_summary($best),
            'rejected_best_reason' => (string) ($evaluation['rejected_best_reason'] ?? ''),
            'fallback_used' => !empty($evaluation['fallback_used']),
            'fallback_category_id' => (string) ($evaluation['fallback_category_id'] ?? ''),
            'fallback_reason' => (string) ($evaluation['fallback_reason'] ?? ''),
            'rejected_original_candidate' => (array) ($evaluation['rejected_original_candidate'] ?? []),
            'best' => $best,
            'taxonomy_status' => $result['status'] ?? '',
            'error' => $result['error'] ?? '',
            'safety' => isset($best['safety']) && is_array($best['safety']) ? $best['safety'] : [],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function summarize_suggestion(array $suggestion): array
    {
        $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
        return [
            'categoryId' => (string) ($category['categoryId'] ?? ''),
            'categoryName' => (string) ($category['categoryName'] ?? ''),
            'path' => $this->suggestion_path($suggestion, (string) ($category['categoryName'] ?? '')),
        ];
    }


    private function append_validation_error(array &$summary, string $message): void
    {
        if (count((array) ($summary['validation_errors_sample'] ?? [])) < 20) {
            $summary['validation_errors_sample'][] = $message;
        }
    }

    private function teaching_rule_debug(array $rule): array
    {
        return [
            'id' => (int) ($rule['id'] ?? 0),
            'nearest_reason' => (string) ($rule['nearest_reason'] ?? ''),
            'marketplace_id' => (string) ($rule['marketplace_id'] ?? ''),
            'woo_category_path' => (string) ($rule['woo_category_path'] ?? ''),
            'woo_category_path_hash' => (string) ($rule['woo_category_path_hash'] ?? ''),
            'detected_intent' => (string) ($rule['detected_intent'] ?? ''),
            'title_keyword_family' => (string) ($rule['title_keyword_family'] ?? ''),
            'manual_ebay_category_id' => (string) ($rule['ebay_category_id'] ?? ''),
            'manual_ebay_category_path' => (string) ($rule['ebay_category_path'] ?? ''),
            'source' => (string) ($rule['source'] ?? ''),
            'updated_at' => (string) ($rule['updated_at'] ?? ''),
        ];
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
        $headers = array_map(static fn($header): string => trim((string) $header, "\xEF\xBB\xBF \t\n\r\0\x0B"), $headers);
        $rows = [];
        while (($values = fgetcsv($fh)) !== false) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($values[$index] ?? '');
            }
            $rows[] = $row;
        }
        fclose($fh);
        return $rows;
    }

    private function write_teaching_export_report(array $rows): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $filename = 'wei-ebay-category-teaching-' . gmdate('Ymd-His') . '.csv';
        $path = trailingslashit($baseDir) . $filename;
        $headers = ['group_id', 'product_count', 'woo_category_path', 'detected_intent', 'sanity_reason', 'current_bad_category_id', 'current_bad_category_path', 'sample_product_ids', 'sample_titles', 'suggested_manual_ebay_category_id', 'suggested_manual_ebay_category_path', 'manual_ebay_category_id', 'manual_ebay_category_path', 'rule_note'];
        $fh = fopen($path, 'wb');
        if ($fh) {
            fputcsv($fh, $headers);
            foreach ($rows as $row) {
                fputcsv($fh, array_map(static fn($header): string => (string) ($row[$header] ?? ''), $headers));
            }
            fclose($fh);
        }
        return ['teaching_csv' => ['path' => $path, 'url' => trailingslashit($baseUrl) . $filename, 'rows' => count($rows)]];
    }

    private function keyword_family_from_rule_row(array $row): string
    {
        $note = (string) ($row['rule_note'] ?? '');
        if (preg_match('/(?:^|[;\s])keyword_family\s*=\s*([A-Za-z0-9_-]+)/', $note, $matches)) {
            return $this->categoryRepo->normalize_keyword_family((string) $matches[1]);
        }
        return $this->categoryRepo->keyword_family_from_title((string) ($row['sample_titles'] ?? ''));
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
    }
}
