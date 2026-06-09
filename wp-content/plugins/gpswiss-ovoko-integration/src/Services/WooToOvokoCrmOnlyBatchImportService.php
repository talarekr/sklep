<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCrmOnlyBatchImportService
{
    public const ACTION_NAME = 'Batch CRM-only import from Woo drafts';
    public const PREVIEW_OPTION = 'gpswiss_ovoko_crm_only_batch_preview';
    public const PREVIEW_TTL_SECONDS = 1800;
    public const DEFAULT_BATCH_SIZE = 10;

    /** @var callable|null */
    private $importer;

    public function __construct(private array $settings = [], ?callable $importer = null)
    {
        $this->importer = $importer;
    }

    public function default_filters(array $filters = []): array
    {
        return [
            'only_gmail_imported' => array_key_exists('only_gmail_imported', $filters) ? (bool) $filters['only_gmail_imported'] : true,
            'product_id_from' => max(0, (int) ($filters['product_id_from'] ?? 0)),
            'product_id_to' => max(0, (int) ($filters['product_id_to'] ?? 0)),
            'created_after' => $this->sanitize_date((string) ($filters['created_after'] ?? '')),
            'batch_size' => $this->sanitize_batch_size((int) ($filters['batch_size'] ?? self::DEFAULT_BATCH_SIZE)),
            'stop_on_error' => !empty($filters['stop_on_error']),
        ];
    }

    public function preview(array $filters = []): array
    {
        $filters = $this->default_filters($filters);
        $checkedAt = gmdate('c');
        $productIds = $this->find_candidate_product_ids($filters);
        $rows = [];
        $summary = $this->empty_summary(count($productIds));
        $previewService = new WooToOvokoCreatePartPreviewService();

        foreach ($productIds as $productId) {
            $preview = $previewService->preview($productId);
            $row = $this->row_from_preview($productId, $preview);
            $this->apply_batch_guards($row, $productId);
            $rows[] = $row;
            $this->accumulate_summary($summary, $row);
        }

        $result = [
            'ok' => true,
            'action_name' => self::ACTION_NAME,
            'mode' => 'batch_preview_no_ovoko_write_no_woo_write',
            'no_ovoko_write' => true,
            'no_woo_write' => true,
            'no_cron' => true,
            'no_product_save_hook' => true,
            'endpoint_path' => WooToOvokoCrmOnlyImportService::ENDPOINT_PATH,
            'filters' => $filters,
            'checked_at' => $checkedAt,
            'preview_token' => $this->preview_token($filters, $checkedAt, $rows),
            'summary' => $summary,
            'rows' => $rows,
        ];

        $this->store_preview($result);
        return $result;
    }

    public function import(array $filters, array $confirmations): array
    {
        $filters = $this->default_filters($filters);
        $preview = $this->latest_preview();
        if (!$this->preview_is_recent($preview)) {
            return $this->blocked_import_result($filters, 'recent_batch_preview_required', 'Run batch CRM-only preview shortly before live import.');
        }
        if (!$this->filters_match((array) ($preview['filters'] ?? []), $filters)) {
            return $this->blocked_import_result($filters, 'batch_preview_filters_changed', 'Live import filters must match the latest batch preview.');
        }
        if (empty($confirmations['confirm_batch_crm_only_no_price'])) {
            return $this->blocked_import_result($filters, 'batch_confirmation_required', 'Explicit batch CRM-only no-price confirmation is required.');
        }

        $eligibleRows = array_values(array_filter((array) ($preview['rows'] ?? []), static fn(array $row): bool => !empty($row['latest_preview_eligible'])));
        $eligibleRows = array_slice($eligibleRows, 0, $filters['batch_size']);
        $importer = new WooToOvokoCrmOnlyImportService($this->settings, $this->importer);
        $result = [
            'ok' => true,
            'action_name' => 'Import eligible Woo drafts to Ovoko CRM-only',
            'endpoint_path' => WooToOvokoCrmOnlyImportService::ENDPOINT_PATH,
            'filters' => $filters,
            'preview_checked_at' => (string) ($preview['checked_at'] ?? ''),
            'batch_size' => $filters['batch_size'],
            'summary' => ['total_attempted' => 0, 'success_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'repair_needed_count' => 0, 'created_part_ids' => [], 'products_updated_with_part_ids' => []],
            'rows' => [],
        ];

        foreach ($eligibleRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $preflight = $this->live_preflight_blocker($productId, (string) ($row['external_id'] ?? ''));
            if ($preflight !== '') {
                $result['summary']['skipped_count']++;
                $result['rows'][] = $this->import_row($row, 'skipped', '', '', $preflight, 'Skipped by live idempotency guard.');
                continue;
            }

            $result['summary']['total_attempted']++;
            $single = $importer->create($productId, [
                'confirm_placeholder_car_id' => true,
                'confirm_live_one_product' => true,
                'confirm_no_price_non_public' => true,
            ]);
            $partId = (string) ($single['part_id'] ?? '');
            $classification = (array) ($single['response_classification'] ?? []);
            $statusCode = (string) ($classification['status_code'] ?? (($single['response']['status_code'] ?? '') ?: ''));
            $message = (string) ($classification['message'] ?? ($single['message'] ?? ''));
            if (!empty($single['ok'])) {
                $result['summary']['success_count']++;
                if ($partId !== '') {
                    $result['summary']['created_part_ids'][] = $partId;
                    $result['summary']['products_updated_with_part_ids'][] = $productId;
                }
                $result['rows'][] = $this->import_row($row, 'success', $partId, $statusCode, '', $message);
                continue;
            }
            if ($partId !== '' || (string) ($single['recoverable_part_id'] ?? '') !== '') {
                $result['summary']['repair_needed_count']++;
                $result['rows'][] = $this->import_row($row, 'repair-needed', $partId !== '' ? $partId : (string) $single['recoverable_part_id'], $statusCode, 'repair/link part ID', $message);
            } else {
                $result['summary']['failed_count']++;
                $result['rows'][] = $this->import_row($row, 'failed', '', $statusCode, (string) ($single['error_code'] ?? 'ovoko_import_failed'), $message);
            }
            if ($filters['stop_on_error']) {
                break;
            }
        }

        $result['summary']['created_part_ids'] = array_values(array_unique($result['summary']['created_part_ids']));
        $result['summary']['products_updated_with_part_ids'] = array_values(array_unique($result['summary']['products_updated_with_part_ids']));
        update_option('gpswiss_ovoko_crm_only_batch_last_import_result', $result, false);
        return $result;
    }

    public function latest_preview(): array
    {
        $preview = function_exists('get_option') ? get_option(self::PREVIEW_OPTION, []) : [];
        return is_array($preview) ? $preview : [];
    }

    public function latest_import_result(): array
    {
        $result = function_exists('get_option') ? get_option('gpswiss_ovoko_crm_only_batch_last_import_result', []) : [];
        return is_array($result) ? $result : [];
    }

    public function csv_rows(array $preview, bool $errorsOnly): array
    {
        $headers = ['product_id', 'sku', 'title', 'storage_location', 'part_code', 'image_count', 'category_id', 'price_empty', 'preview_status', 'blockers_warnings', 'external_id', 'repair_part_id'];
        $rows = [$headers];
        foreach ((array) ($preview['rows'] ?? []) as $row) {
            if ($errorsOnly && (string) ($row['preview_status'] ?? '') === 'eligible') {
                continue;
            }
            $rows[] = [
                (string) ($row['product_id'] ?? ''),
                (string) ($row['sku'] ?? ''),
                (string) ($row['title'] ?? ''),
                (string) ($row['storage_location'] ?? ''),
                (string) ($row['part_code'] ?? ''),
                (string) ($row['image_count'] ?? '0'),
                (string) ($row['category_id'] ?? ''),
                !empty($row['price_empty']) ? 'yes' : 'no',
                (string) ($row['preview_status'] ?? ''),
                implode('; ', (array) ($row['blockers_warnings'] ?? [])),
                (string) ($row['external_id'] ?? ''),
                (string) ($row['repair_part_id'] ?? ''),
            ];
        }
        return $rows;
    }

    private function find_candidate_product_ids(array $filters): array
    {
        $args = [
            'post_type' => 'product',
            'post_status' => 'draft',
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ];
        if ($filters['created_after'] !== '') {
            $args['date_query'] = [['after' => $filters['created_after'], 'inclusive' => true]];
        }
        if ($filters['only_gmail_imported']) {
            $args['meta_query'] = [
                'relation' => 'OR',
                ['key' => '_sku', 'value' => 'GPS-GMAIL-', 'compare' => 'LIKE'],
                ['key' => '_gps_gmail_staging_item_id', 'compare' => 'EXISTS'],
                ['key' => '_gps_detected_part_code', 'compare' => 'EXISTS'],
            ];
        }
        $ids = function_exists('get_posts') ? get_posts($args) : [];
        $ids = array_map('intval', is_array($ids) ? $ids : []);
        return array_values(array_filter($ids, function (int $id) use ($filters): bool {
            if ($filters['product_id_from'] > 0 && $id < $filters['product_id_from']) { return false; }
            if ($filters['product_id_to'] > 0 && $id > $filters['product_id_to']) { return false; }
            if ($filters['only_gmail_imported'] && !$this->is_gmail_imported_product($id)) { return false; }
            return true;
        }));
    }

    private function is_gmail_imported_product(int $productId): bool
    {
        $sku = (string) get_post_meta($productId, '_sku', true);
        return str_starts_with($sku, 'GPS-GMAIL-')
            || trim((string) get_post_meta($productId, '_gps_gmail_staging_item_id', true)) !== ''
            || trim((string) get_post_meta($productId, '_gps_detected_part_code', true)) !== '';
    }

    private function row_from_preview(int $productId, array $preview): array
    {
        $payload = (array) ($preview['proposed_payload'] ?? []);
        $images = (array) ($preview['images'] ?? []);
        $legacy = (array) ($preview['legacy_payload_summary'] ?? []);
        $errors = array_map(static fn(array $entry): string => (string) ($entry['code'] ?? ''), (array) ($preview['validation_errors'] ?? []));
        $warnings = array_map(static fn(array $entry): string => (string) ($entry['code'] ?? ''), (array) ($preview['validation_warnings'] ?? []));
        $partCode = (string) ($payload['visible_code'] ?? ($legacy['part_identifier'] ?? ''));
        $row = [
            'product_id' => $productId,
            'sku' => (string) ($payload['sku'] ?? get_post_meta($productId, '_sku', true)),
            'title' => function_exists('get_the_title') ? (string) get_the_title($productId) : '',
            'storage_location' => (string) ($payload['place'] ?? ($preview['listing_text_preview'] ?? '')),
            'part_code' => $partCode,
            'image_count' => count((array) ($images['image_urls'] ?? ($payload['photos[]'] ?? []))),
            'category_id' => $payload['category_id'] ?? ($preview['fixed_category_id'] ?? null),
            'price_empty' => trim((string) get_post_meta($productId, '_price', true)) === '',
            'preview_status' => !empty($preview['would_be_eligible']) ? 'eligible' : 'blocked',
            'blockers_warnings' => array_values(array_filter(array_merge($errors, $warnings))),
            'validation_errors' => $errors,
            'validation_warnings' => $warnings,
            'external_id' => (string) ($payload['external_id'] ?? ''),
            'already_imported' => false,
            'repair_needed' => false,
            'repair_part_id' => '',
            'latest_preview_eligible' => !empty($preview['would_be_eligible']),
            'preview' => $preview,
        ];
        if ($row['part_code'] === '') { $row['blockers_warnings'][] = 'missing_part_code'; }
        if ((int) $row['image_count'] <= 0) { $row['blockers_warnings'][] = 'missing_images'; }
        if ($row['category_id'] === null || $row['category_id'] === '') { $row['blockers_warnings'][] = 'missing_category'; }
        return $row;
    }

    private function apply_batch_guards(array &$row, int $productId): void
    {
        if ($this->has_existing_part_id_meta($productId)) {
            $row['already_imported'] = true;
            $row['preview_status'] = 'already-imported';
            $row['latest_preview_eligible'] = false;
            $row['blockers_warnings'][] = 'has_existing_ovoko_part_id';
        }
        $recoverable = $this->recoverable_part_id($productId);
        if ($recoverable !== '') {
            $row['repair_needed'] = true;
            $row['repair_part_id'] = $recoverable;
            $row['preview_status'] = 'repair-needed';
            $row['latest_preview_eligible'] = false;
            $row['blockers_warnings'][] = 'last_failed_import_with_part_id_repair_needed';
        }
        $duplicate = $this->duplicate_external_id_linked_product_id((string) ($row['external_id'] ?? ''), $productId);
        if ($duplicate > 0) {
            $row['preview_status'] = 'blocked';
            $row['latest_preview_eligible'] = false;
            $row['blockers_warnings'][] = 'duplicate_external_id_already_linked_locally:' . $duplicate;
        }
    }

    private function accumulate_summary(array &$summary, array $row): void
    {
        if (!empty($row['already_imported'])) { $summary['already_imported']++; $summary['has_existing_ovoko_part_id']++; return; }
        if (!empty($row['repair_needed'])) { $summary['last_failed_import_with_part_id_repair_needed']++; $summary['blocked']++; return; }
        if (!empty($row['latest_preview_eligible'])) { $summary['eligible']++; return; }
        $summary['blocked']++;
        $codes = (array) ($row['blockers_warnings'] ?? []);
        if (in_array('missing_images', $codes, true)) { $summary['missing_images']++; }
        if (in_array('missing_part_identifier', $codes, true) || in_array('missing_part_code', $codes, true)) { $summary['missing_part_code']++; }
        if (in_array('missing_ovoko_category_id', $codes, true) || in_array('missing_category', $codes, true)) { $summary['missing_category']++; }
        if (in_array('existing_ovoko_part_id', $codes, true) || in_array('has_existing_ovoko_part_id', $codes, true)) { $summary['has_existing_ovoko_part_id']++; }
        $known = ['missing_images','missing_part_identifier','missing_part_code','missing_ovoko_category_id','missing_category','existing_ovoko_part_id','has_existing_ovoko_part_id'];
        if (array_values(array_diff($codes, $known)) !== []) { $summary['other_validation_errors']++; }
    }

    private function empty_summary(int $total): array
    {
        return ['total_checked' => $total, 'eligible' => 0, 'blocked' => 0, 'already_imported' => 0, 'missing_images' => 0, 'missing_part_code' => 0, 'missing_category' => 0, 'has_existing_ovoko_part_id' => 0, 'last_failed_import_with_part_id_repair_needed' => 0, 'other_validation_errors' => 0];
    }

    private function live_preflight_blocker(int $productId, string $externalId): string
    {
        if ($this->has_existing_part_id_meta($productId)) { return 'already_has_ovoko_part_id'; }
        if ($this->recoverable_part_id($productId) !== '') { return 'last_response_contains_part_id_repair_needed'; }
        $duplicate = $this->duplicate_external_id_linked_product_id($externalId, $productId);
        return $duplicate > 0 ? 'duplicate_external_id_already_linked_locally:' . $duplicate : '';
    }

    private function has_existing_part_id_meta(int $productId): bool
    {
        foreach (WooToOvokoCrmOnlyImportService::all_part_id_meta_keys() as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') { return true; }
        }
        return false;
    }

    private function recoverable_part_id(int $productId): string
    {
        $direct = trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_import_recoverable_part_id', true));
        if ($direct !== '') { return $direct; }
        $raw = trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_import_last_response_raw', true));
        if ($raw === '') { return ''; }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) { return ''; }
        foreach ([$decoded['part_id'] ?? null, $decoded['id'] ?? null, $decoded['data']['part_id'] ?? null, $decoded['data']['id'] ?? null] as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') { return $candidate; }
        }
        return '';
    }

    private function duplicate_external_id_linked_product_id(string $externalId, int $currentProductId): int
    {
        if ($externalId === '' || !function_exists('get_posts')) { return 0; }
        $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => 2, 'fields' => 'ids', 'meta_key' => '_sku', 'meta_value' => $externalId]);
        foreach (array_map('intval', is_array($ids) ? $ids : []) as $id) {
            if ($id !== $currentProductId && $this->has_existing_part_id_meta($id)) { return $id; }
        }
        return 0;
    }

    private function store_preview(array $result): void
    {
        if (function_exists('update_option')) {
            update_option(self::PREVIEW_OPTION, $result, false);
        }
    }

    private function preview_is_recent(array $preview): bool
    {
        $checkedAt = strtotime((string) ($preview['checked_at'] ?? ''));
        return $checkedAt !== false && (time() - $checkedAt) <= self::PREVIEW_TTL_SECONDS;
    }

    private function filters_match(array $previewFilters, array $liveFilters): bool
    {
        foreach (['only_gmail_imported', 'product_id_from', 'product_id_to', 'created_after', 'batch_size', 'stop_on_error'] as $key) {
            if (($previewFilters[$key] ?? null) !== ($liveFilters[$key] ?? null)) { return false; }
        }
        return true;
    }

    private function blocked_import_result(array $filters, string $code, string $message): array
    {
        return ['ok' => false, 'status' => 'blocked', 'action_name' => 'Import eligible Woo drafts to Ovoko CRM-only', 'filters' => $filters, 'error_code' => $code, 'message' => $message, 'summary' => ['total_attempted' => 0, 'success_count' => 0, 'skipped_count' => 0, 'failed_count' => 0, 'repair_needed_count' => 0, 'created_part_ids' => [], 'products_updated_with_part_ids' => []], 'rows' => []];
    }

    private function import_row(array $previewRow, string $status, string $partId, string $statusCode, string $actionNeeded, string $message): array
    {
        return ['product_id' => (int) ($previewRow['product_id'] ?? 0), 'sku' => (string) ($previewRow['sku'] ?? ''), 'external_id' => (string) ($previewRow['external_id'] ?? ''), 'status' => $status, 'ovoko_part_id' => $partId, 'response_status_code' => $statusCode, 'message' => $message, 'action_needed' => $actionNeeded];
    }

    private function preview_token(array $filters, string $checkedAt, array $rows): string
    {
        return hash('sha256', wp_json_encode([$filters, $checkedAt, array_column($rows, 'product_id')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function sanitize_batch_size(int $batchSize): int
    {
        return min(100, max(1, $batchSize));
    }

    private function sanitize_date(string $date): string
    {
        $date = trim($date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }
}
