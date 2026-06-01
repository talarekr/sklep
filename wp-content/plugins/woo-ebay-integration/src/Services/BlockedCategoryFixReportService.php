<?php

namespace WEI\Services;

use WEI\Repositories\CategoryMappingRepository;

class BlockedCategoryFixReportService
{
    public const RECOMMENDATIONS_FILENAME = 'blocked_category_mapping_recommendations.csv';
    public const FIX_IMPORT_FILENAME = 'blocked_category_mapping_fix_import.csv';

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
                'recommendation_reason' => (string) ($recommendation['decision_reason'] ?? ''),
                'taxonomy_validation_status' => (string) ($validation['status'] ?? $validation['validation_status'] ?? ''),
                'apply_candidate' => $apply ? '1' : '0',
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
        return ['product_id','product_title','woo_category_id','woo_category_path','current_ebay_category_id','current_ebay_category_path','detected_intent','sanity_reason','recommended_ebay_category_id','recommended_ebay_category_path','recommendation_confidence','recommendation_reason','taxonomy_validation_status','apply_candidate','note'];
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
