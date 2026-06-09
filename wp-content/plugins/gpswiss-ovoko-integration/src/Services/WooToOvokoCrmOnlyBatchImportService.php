<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCrmOnlyBatchImportService
{
    private array $settings;
    private WooToOvokoCreatePartPreviewService $previewService;
    private WooToOvokoCrmOnlyImportService $importService;

    public function __construct(array $settings = [], ?WooToOvokoCreatePartPreviewService $previewService = null, ?WooToOvokoCrmOnlyImportService $importService = null)
    {
        $this->settings = $settings;
        $this->previewService = $previewService ?: new WooToOvokoCreatePartPreviewService();
        $this->importService = $importService ?: new WooToOvokoCrmOnlyImportService($settings);
    }

    public function run_one_batch(array $args): array
    {
        $mode = (string) ($args['mode'] ?? 'live');
        $batchSize = max(1, min(50, (int) ($args['batch_size'] ?? 10)));
        $stopOnFirstError = !empty($args['stop_on_first_error']);
        $onlyGmailImported = !array_key_exists('only_gmail_imported', $args) || !empty($args['only_gmail_imported']);
        $cursor = max(0, (int) ($args['cursor'] ?? $args['after_product_id'] ?? 0));
        $productIdFrom = max(0, (int) ($args['product_id_from'] ?? 0));
        $productIdTo = max(0, (int) ($args['product_id_to'] ?? 0));
        $createdAfter = $this->sanitize_created_after((string) ($args['created_after'] ?? ''));
        $batchNumber = max(1, (int) ($args['batch_number'] ?? 1));

        if ($productIdFrom > 0 && $cursor < ($productIdFrom - 1)) {
            $cursor = $productIdFrom - 1;
        }

        $productIds = $this->query_candidate_product_ids($batchSize, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $result = $this->base_result($mode, $batchNumber, $batchSize, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $lastProductId = $cursor;

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $lastProductId = max($lastProductId, $productId);
            $result['attempted']++;

            if ($this->has_imported_meta($productId)) {
                $result['already_imported_count']++;
                $result['items'][] = ['product_id' => $productId, 'status' => 'already_imported'];
                continue;
            }

            $preview = $this->previewService->preview($productId);
            $previewCodes = $this->validation_codes($preview);
            $this->add_preview_blocker_counts($result, $previewCodes);

            if ($mode !== 'live') {
                if (!empty($preview['would_be_eligible']) && empty($preview['validation_errors'])) {
                    $result['eligible_count']++;
                } else {
                    $result['blocked_count']++;
                }
                $result['items'][] = $this->preview_item($productId, $preview, $previewCodes);
                continue;
            }

            if (empty($preview['would_be_eligible']) || !empty($preview['validation_errors'])) {
                $result['blocked_count']++;
                $error = [
                    'product_id' => $productId,
                    'code' => 'preview_ineligible',
                    'message' => 'CRM-only preview is not eligible for live import.',
                    'validation_codes' => $previewCodes,
                ];
                $result['errors'][] = $error;
                $result['items'][] = ['product_id' => $productId, 'status' => 'blocked', 'validation_codes' => $previewCodes];
                if ($stopOnFirstError) {
                    $result['stop_reason'] = 'preview_ineligible_stop_on_first_error';
                    break;
                }
                continue;
            }

            $import = $this->importService->create($productId, $this->live_confirmations($preview));
            $result['imported_items'][] = $import;
            $result['items'][] = ['product_id' => $productId, 'status' => (string) ($import['status'] ?? ''), 'ok' => !empty($import['ok']), 'part_id' => (string) ($import['part_id'] ?? '')];

            if (!empty($import['ok'])) {
                $result['success_count']++;
            } else {
                $result['failed_count']++;
                if ((string) ($import['error_code'] ?? '') === 'recoverable_part_id_blocks_retry') {
                    $result['repair_needed_count']++;
                }
                $result['errors'][] = [
                    'product_id' => $productId,
                    'code' => (string) ($import['error_code'] ?? 'import_failed'),
                    'message' => (string) ($import['message'] ?? 'Import failed.'),
                    'result' => $import,
                ];
                if ($stopOnFirstError) {
                    $result['stop_reason'] = 'import_error_stop_on_first_error';
                    break;
                }
            }
        }

        $result['last_product_id_processed'] = $lastProductId > $cursor ? $lastProductId : 0;
        $result['next_cursor'] = $lastProductId;
        $result['next_product_id'] = $lastProductId;
        $result['has_more'] = $this->has_more_candidates($lastProductId, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $result['should_continue'] = $result['has_more'] && $result['attempted'] > 0 && $result['stop_reason'] === '';
        if ($result['attempted'] === 0) {
            $result['stop_reason'] = 'no_eligible_products';
            $result['should_continue'] = false;
        } elseif (!$result['has_more'] && $result['stop_reason'] === '') {
            $result['stop_reason'] = 'no_more_candidates';
        }
        $result['ok'] = $result['failed_count'] === 0 && ($stopOnFirstError ? $result['stop_reason'] !== 'import_error_stop_on_first_error' : true);
        $result['eligible'] = $result['eligible_count'];
        $result['total_attempted'] = $result['attempted'];
        $result['summary'] = $this->raw_summary($result);
        $result['raw_summary'] = $result['summary'];

        return $result;
    }

    private function base_result(string $mode, int $batchNumber, int $batchSize, int $cursor, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        return [
            'ok' => true,
            'mode' => $mode === 'preview' ? 'preview' : 'live',
            'batch_number' => $batchNumber,
            'batch_size' => $batchSize,
            'cursor' => $cursor,
            'after_product_id' => $cursor,
            'only_gmail_imported' => $onlyGmailImported,
            'product_id_from' => $productIdFrom,
            'product_id_to' => $productIdTo,
            'created_after' => $createdAfter,
            'attempted' => 0,
            'eligible_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'blocked_count' => 0,
            'already_imported_count' => 0,
            'missing_images_count' => 0,
            'missing_part_code_count' => 0,
            'missing_category_count' => 0,
            'repair_needed_count' => 0,
            'has_more' => false,
            'should_continue' => false,
            'stop_reason' => '',
            'next_cursor' => $cursor,
            'next_product_id' => $cursor,
            'last_product_id_processed' => 0,
            'errors' => [],
            'imported_items' => [],
            'items' => [],
            'no_ebay_write' => true,
            'uses_existing_crm_only_import_service' => true,
            'dry_run_supported' => true,
            'summary' => [],
        ];
    }

    private function query_candidate_product_ids(int $limit, int $afterProductId, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        $args = $this->query_args($limit, $afterProductId, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $filter = $this->id_range_filter($afterProductId, $productIdFrom, $productIdTo);
        add_filter('posts_where', $filter, 10, 2);
        $ids = get_posts($args);
        remove_filter('posts_where', $filter, 10);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    private function has_more_candidates(int $afterProductId, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): bool
    {
        return $this->query_candidate_product_ids(1, $afterProductId, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter) !== [];
    }

    private function query_args(int $limit, int $afterProductId, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        $metaQuery = ['relation' => 'AND'];
        foreach (array_merge(WooToOvokoCrmOnlyImportService::all_part_id_meta_keys(), ['_gps_ovoko_crm_only_imported_at']) as $key) {
            $metaQuery[] = ['relation' => 'OR', ['key' => $key, 'compare' => 'NOT EXISTS'], ['key' => $key, 'value' => '', 'compare' => '=']];
        }
        if ($onlyGmailImported) {
            $metaQuery[] = [
                'relation' => 'OR',
                ['key' => '_gps_gmail_import_source', 'value' => 'gmail', 'compare' => '='],
                ['key' => '_gps_source_staging_item_id', 'compare' => 'EXISTS'],
                ['key' => '_sku', 'value' => 'GPS-GMAIL-', 'compare' => 'LIKE'],
            ];
        }
        $dateQuery = [];
        if ($createdAfter !== '') {
            $dateQuery[] = ['after' => $createdAfter . ' 00:00:00', 'inclusive' => true, 'column' => 'post_date'];
        }
        return [
            'post_type' => 'product',
            'post_status' => 'draft',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => $metaQuery,
            'date_query' => $dateQuery,
            'suppress_filters' => false,
        ];
    }

    private function id_range_filter(int $afterProductId, int $productIdFrom, int $productIdTo): callable
    {
        return static function (string $where, \WP_Query $query) use ($afterProductId, $productIdFrom, $productIdTo): string {
            global $wpdb;
            if ($query->get('post_type') !== 'product' || $query->get('fields') !== 'ids') {
                return $where;
            }
            $min = max($afterProductId, $productIdFrom > 0 ? $productIdFrom - 1 : 0);
            if ($min > 0) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $min);
            }
            if ($productIdTo > 0) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID <= %d", $productIdTo);
            }
            return $where;
        };
    }

    private function sanitize_created_after(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function has_imported_meta(int $productId): bool
    {
        foreach (array_merge(WooToOvokoCrmOnlyImportService::all_part_id_meta_keys(), ['_gps_ovoko_crm_only_imported_at']) as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') {
                return true;
            }
        }
        return false;
    }

    private function live_confirmations(array $preview): array
    {
        return [
            'confirm_live_one_product' => true,
            'confirm_no_price_non_public' => true,
            'confirm_placeholder_car_id' => !empty($preview['car_id_is_placeholder']),
        ];
    }

    private function validation_codes(array $preview): array
    {
        $codes = [];
        foreach ((array) ($preview['validation_errors'] ?? []) as $error) {
            $code = (string) ($error['code'] ?? '');
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    private function add_preview_blocker_counts(array &$result, array $codes): void
    {
        if (array_intersect($codes, ['missing_images', 'inaccessible_image_url', 'photo_must_equal_first_photos']) !== []) {
            $result['missing_images_count']++;
        }
        if (in_array('missing_part_identifier', $codes, true)) {
            $result['missing_part_code_count']++;
        }
        $hasMissingCategory = in_array('missing_ovoko_category_id', $codes, true);
        foreach ($codes as $code) {
            if (str_starts_with($code, 'missing_woo_category_mapping_for_ovoko_category_')) {
                $hasMissingCategory = true;
            }
        }
        if ($hasMissingCategory) {
            $result['missing_category_count']++;
        }
        if (array_intersect($codes, ['recoverable_part_id_blocks_retry', 'existing_ovoko_part_id']) !== []) {
            $result['repair_needed_count']++;
        }
    }

    private function preview_item(int $productId, array $preview, array $codes): array
    {
        return [
            'product_id' => $productId,
            'status' => !empty($preview['would_be_eligible']) && empty($preview['validation_errors']) ? 'eligible' : 'blocked',
            'validation_codes' => $codes,
            'part_code' => (string) ($preview['part_identifier']['value'] ?? ''),
            'image_count' => count((array) ($preview['images']['image_urls'] ?? [])),
        ];
    }

    private function raw_summary(array $result): array
    {
        return [
            'attempted' => $result['attempted'],
            'eligible' => $result['eligible_count'],
            'eligible_count' => $result['eligible_count'],
            'success_count' => $result['success_count'],
            'failed_count' => $result['failed_count'],
            'blocked_count' => $result['blocked_count'],
            'already_imported_count' => $result['already_imported_count'],
            'missing_images_count' => $result['missing_images_count'],
            'missing_part_code_count' => $result['missing_part_code_count'],
            'missing_category_count' => $result['missing_category_count'],
            'repair_needed_count' => $result['repair_needed_count'],
            'has_more' => $result['has_more'],
            'next_cursor' => $result['next_cursor'],
            'stop_reason' => $result['stop_reason'],
        ];
    }
}
