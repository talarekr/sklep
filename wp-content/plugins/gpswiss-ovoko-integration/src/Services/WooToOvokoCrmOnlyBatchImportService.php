<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCrmOnlyBatchImportService
{
    private const GMAIL_SKU_PREFIX = 'GPS-GMAIL-';
    private const DEFAULT_LOOKAHEAD_WINDOW = 50;
    private const DEFAULT_MAX_SCAN_WINDOWS = 20;

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
        $startedAt = microtime(true);
        $mode = (string) ($args['mode'] ?? 'live');
        $allowedModes = ['live', 'preview', 'find_candidate', 'preview_one', 'live_one'];
        if (!in_array($mode, $allowedModes, true)) {
            $mode = 'live';
        }
        $batchSize = max(1, min(50, (int) ($args['batch_size'] ?? 10)));
        $stopOnFirstError = !empty($args['stop_on_first_error']);
        $onlyGmailImported = !array_key_exists('only_gmail_imported', $args) || !empty($args['only_gmail_imported']);
        $cursor = max(0, (int) ($args['cursor'] ?? $args['after_product_id'] ?? 0));
        $productId = max(0, (int) ($args['product_id'] ?? 0));
        $productIdFrom = max(0, (int) ($args['product_id_from'] ?? 0));
        $productIdTo = max(0, (int) ($args['product_id_to'] ?? 0));
        $createdAfter = $this->sanitize_created_after((string) ($args['created_after'] ?? ''));
        $batchNumber = max(1, (int) ($args['batch_number'] ?? 1));

        $this->log_marker('CRM_ONLY_BATCH_START', [
            'mode' => $mode,
            'batch_number' => $batchNumber,
        ]);
        $this->log_marker('CRM_ONLY_BATCH_PARAMS', [
            'mode' => $mode,
            'batch_size' => $batchSize,
            'cursor' => $cursor,
            'product_id' => $productId,
            'product_id_from' => $productIdFrom,
            'product_id_to' => $productIdTo,
            'created_after_present' => $createdAfter !== '',
            'only_gmail_imported' => $onlyGmailImported,
            'stop_on_first_error' => $stopOnFirstError,
        ]);

        if ($productIdFrom > 0 && $cursor < ($productIdFrom - 1)) {
            $cursor = $productIdFrom - 1;
        }

        if ($mode === 'preview_one' || $mode === 'live_one') {
            return $this->run_single_product_mode($mode, $productId, $batchNumber, $batchSize, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter, $startedAt);
        }

        $this->log_marker('CRM_ONLY_BATCH_BEFORE_QUERY', [
            'cursor' => $cursor,
            'sku_like' => self::GMAIL_SKU_PREFIX . '%',
            'lookahead_limit' => $this->candidate_lookahead_limit($batchSize),
        ]);
        $candidateScanStartedAt = microtime(true);
        $candidateScan = $this->find_candidate_product_ids($batchSize, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $queryMs = (int) $candidateScan['query_ms'];
        $candidateScanMs = $this->elapsed_ms($candidateScanStartedAt);
        $productIds = $candidateScan['ids'];
        $this->log_marker('CRM_ONLY_BATCH_AFTER_QUERY', [
            'query_ms' => $queryMs,
            'raw_id_count' => count($candidateScan['raw_ids']),
            'checked_count' => $candidateScan['checked_count'],
            'candidate_count' => count($productIds),
            'last_checked_product_id' => $candidateScan['last_checked_product_id'],
            'candidate_scan_ms' => $candidateScanMs,
        ]);
        $this->log_marker('CRM_ONLY_BATCH_CANDIDATE_IDS', [
            'candidate_ids' => implode(',', array_map('strval', $productIds)),
            'checked_count' => $candidateScan['checked_count'],
        ]);

        if ($mode === 'find_candidate') {
            $candidateScan = $this->evaluate_candidate_eligibility($candidateScan, 1);
            $productIds = $candidateScan['ids'];
            $firstCandidate = isset($productIds[0]) ? (int) $productIds[0] : 0;
            $result = [
                'ok' => true,
                'mode' => 'find_candidate',
                'candidate_found' => $firstCandidate > 0,
                'first_candidate_product_id' => $firstCandidate,
                'checked_count' => (int) $candidateScan['checked_count'],
                'next_cursor' => $firstCandidate > 0 ? $firstCandidate : (int) $candidateScan['last_checked_product_id'],
                'stop_reason' => $firstCandidate > 0 ? '' : $this->find_candidate_stop_reason($candidateScan),
                'skipped_counts' => $candidateScan['skipped_counts'],
                'query_ms' => $queryMs,
                'candidate_scan_ms' => $this->elapsed_ms($candidateScanStartedAt),
                'total_ms' => $this->elapsed_ms($startedAt),
            ];
            $this->copy_scan_diagnostics($result, $candidateScan);
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', [
                'mode' => 'find_candidate',
                'candidate_found' => $result['candidate_found'],
                'checked_count' => $result['checked_count'],
                'stop_reason' => $result['stop_reason'],
                'query_ms' => $queryMs,
                'candidate_scan_ms' => $result['candidate_scan_ms'],
                'total_ms' => $result['total_ms'],
            ]);
            return $result;
        }

        $result = $this->base_result($mode, $batchNumber, $batchSize, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $result['query_ms'] = $queryMs;
        $result['candidate_scan_ms'] = $candidateScanMs;
        $result['candidate_checked_count'] = (int) $candidateScan['checked_count'];
        $result['skipped_counts'] = $candidateScan['skipped_counts'];
        $this->copy_scan_diagnostics($result, $candidateScan);
        $result['already_imported_count'] = (int) $candidateScan['skipped_counts']['skipped_already_imported'];
        foreach ((array) ($candidateScan['skipped_recoverable_items'] ?? []) as $skippedItem) {
            $result['items'][] = $skippedItem;
            $result['repair_needed_count']++;
            if ((string) ($skippedItem['skip_reason'] ?? '') === 'ovoko_photo_file_missing') {
                $result['photo_file_missing_count']++;
                $result['skipped_photo_file_missing']++;
            }
        }
        $lastProductId = $cursor;
        $lastCheckedProductId = (int) $candidateScan['last_checked_product_id'];
        $stoppedBeforeScanExhausted = false;

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $lastProductId = max($lastProductId, $productId);
            $result['attempted']++;

            $validationStartedAt = microtime(true);
            $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_START', ['product_id' => $productId]);
            if ($this->has_imported_meta($productId)) {
                $validationMs = $this->elapsed_ms($validationStartedAt);
                $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'already_imported', 'validation_ms' => $validationMs]);
                $result['already_imported_count']++;
                $result['items'][] = ['product_id' => $productId, 'status' => 'already_imported'];
                continue;
            }
            $recoverableState = $this->recoverable_retry_blocked_state($productId);
            if (!empty($recoverableState['blocked'])) {
                $validationMs = $this->elapsed_ms($validationStartedAt);
                $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'skipped_recoverable', 'recoverable_part_id' => (string) ($recoverableState['recoverable_part_id'] ?? ''), 'validation_ms' => $validationMs]);
                $result['repair_needed_count']++;
                $result['skipped_counts']['skipped_recoverable_retry_blocked']++;
                $skippedItem = $this->recoverable_skipped_item($productId, $recoverableState);
                if ((string) ($skippedItem['skip_reason'] ?? '') === 'ovoko_photo_file_missing') {
                    $result['photo_file_missing_count']++;
                    $result['skipped_photo_file_missing']++;
                    $result['skipped_counts']['skipped_photo_file_missing']++;
                }
                $result['items'][] = $skippedItem;
                continue;
            }
            $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'candidate', 'validation_ms' => $this->elapsed_ms($validationStartedAt)]);

            $previewStartedAt = microtime(true);
            $this->log_marker('CRM_ONLY_BATCH_PREVIEW_START', ['product_id' => $productId]);
            $preview = $this->previewService->preview($productId);
            $previewMs = $this->elapsed_ms($previewStartedAt);
            $this->log_marker('CRM_ONLY_BATCH_PREVIEW_DONE', ['product_id' => $productId, 'preview_ms' => $previewMs, 'would_be_eligible' => !empty($preview['would_be_eligible']), 'validation_error_count' => count((array) ($preview['validation_errors'] ?? []))]);
            $previewCodes = $this->validation_codes($preview);
            $this->add_preview_blocker_counts($result, $previewCodes);

            if ($mode !== 'live') {
                if (!empty($preview['would_be_eligible']) && empty($preview['validation_errors'])) {
                    $result['eligible_count']++;
                } else {
                    $result['blocked_count']++;
                    $result['skipped_counts'][$this->preview_blocker_bucket($previewCodes)]++;
                    $this->append_limited($result['examples_not_imported_not_selected'], $this->not_selected_example($productId, 'blocked_validation:' . implode(',', $previewCodes)), 5);
                }
                $result['items'][] = $this->preview_item($productId, $preview, $previewCodes);
                if ($result['eligible_count'] >= $batchSize) {
                    $stoppedBeforeScanExhausted = true;
                    break;
                }
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
                $result['skipped_counts'][$this->preview_blocker_bucket($previewCodes)]++;
                $this->append_limited($result['examples_not_imported_not_selected'], $this->not_selected_example($productId, 'blocked_validation:' . implode(',', $previewCodes)), 5);
                $result['items'][] = ['product_id' => $productId, 'status' => 'blocked', 'validation_codes' => $previewCodes];
                if ($stopOnFirstError) {
                    $result['stop_reason'] = 'preview_ineligible_stop_on_first_error';
                    $stoppedBeforeScanExhausted = true;
                    break;
                }
                continue;
            }

            $result['eligible_count']++;
            if ($result['eligible_count'] > $batchSize) {
                $stoppedBeforeScanExhausted = true;
                break;
            }

            $importStartedAt = microtime(true);
            $this->log_marker('CRM_ONLY_BATCH_IMPORT_START', ['product_id' => $productId]);
            $import = $this->importService->create($productId, $this->live_confirmations($preview));
            $importMs = $this->elapsed_ms($importStartedAt);
            $this->log_marker('CRM_ONLY_BATCH_IMPORT_DONE', ['product_id' => $productId, 'import_ms' => $importMs, 'ok' => !empty($import['ok']), 'status' => (string) ($import['status'] ?? ''), 'error_code' => (string) ($import['error_code'] ?? '')]);
            $result['imported_items'][] = $import;

            if (!empty($import['ok'])) {
                $result['items'][] = ['product_id' => $productId, 'status' => (string) ($import['status'] ?? ''), 'ok' => true, 'part_id' => (string) ($import['part_id'] ?? '')];
                $result['success_count']++;
            } elseif ($this->is_photo_file_missing_import_result($import)) {
                $result['repair_needed_count']++;
                $result['photo_file_missing_count']++;
                $result['skipped_photo_file_missing']++;
                $result['skipped_counts']['skipped_photo_file_missing']++;
                $result['items'][] = $this->photo_file_missing_item($productId);
                $this->append_limited($result['examples_not_imported_not_selected'], $this->not_selected_example($productId, 'repair_needed:ovoko_photo_file_missing'), 5);
            } else {
                $result['items'][] = ['product_id' => $productId, 'status' => (string) ($import['status'] ?? ''), 'ok' => false, 'part_id' => (string) ($import['part_id'] ?? '')];
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
                    $stoppedBeforeScanExhausted = true;
                    break;
                }
            }
            if ($result['eligible_count'] >= $batchSize) {
                $stoppedBeforeScanExhausted = true;
                break;
            }
        }

        $result['last_product_id_processed'] = $lastProductId > $cursor ? $lastProductId : 0;
        $result['next_cursor'] = $stoppedBeforeScanExhausted ? $lastProductId : max($lastProductId, $lastCheckedProductId);
        $result['next_product_id'] = $result['next_cursor'];
        $hasMoreStartedAt = microtime(true);
        $result['has_more'] = $this->has_more_candidates((int) $result['next_cursor'], $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $result['has_more_query_ms'] = $this->elapsed_ms($hasMoreStartedAt);
        $result['should_continue'] = $result['has_more'] && ($result['attempted'] > 0 || $lastCheckedProductId > $cursor) && $result['stop_reason'] === '';
        if ($result['attempted'] === 0) {
            $result['stop_reason'] = $result['has_more'] ? 'no_eligible_products_in_lookahead' : 'no_eligible_products';
            $result['should_continue'] = $result['has_more'];
        } elseif (!$result['has_more'] && $result['stop_reason'] === '') {
            $result['stop_reason'] = 'no_more_candidates';
        }
        $result['ok'] = $result['failed_count'] === 0 && ($stopOnFirstError ? $result['stop_reason'] !== 'import_error_stop_on_first_error' : true);
        $result['eligible'] = $result['eligible_count'];
        $result['total_attempted'] = $result['attempted'];
        $result['total_ms'] = $this->elapsed_ms($startedAt);
        $result['summary'] = $this->raw_summary($result);
        $result['raw_summary'] = $result['summary'];

        $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', [
            'mode' => $mode,
            'attempted' => $result['attempted'],
            'success_count' => $result['success_count'],
            'failed_count' => $result['failed_count'],
            'blocked_count' => $result['blocked_count'],
            'query_ms' => $result['query_ms'],
            'total_ms' => $result['total_ms'],
            'stop_reason' => $result['stop_reason'],
        ]);

        return $result;
    }

    private function run_single_product_mode(string $mode, int $productId, int $batchNumber, int $batchSize, int $cursor, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter, float $startedAt): array
    {
        $result = $this->base_result($mode, $batchNumber, 1, $cursor, $onlyGmailImported, $productIdFrom, $productIdTo, $createdAfter);
        $result['product_id'] = $productId;
        $result['has_more'] = false;
        $result['should_continue'] = false;

        if ($productId <= 0) {
            $result['ok'] = false;
            $result['failed_count'] = 1;
            $result['stop_reason'] = 'missing_product_id';
            $result['errors'][] = ['code' => 'missing_product_id', 'message' => 'product_id is required for ' . $mode . '.'];
            $result['total_ms'] = $this->elapsed_ms($startedAt);
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'ok' => false, 'stop_reason' => $result['stop_reason'], 'total_ms' => $result['total_ms']]);
            return $result;
        }

        $validationStartedAt = microtime(true);
        $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_START', ['product_id' => $productId]);
        $alreadyImported = $this->has_imported_meta($productId);
        $validationMs = $this->elapsed_ms($validationStartedAt);
        $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => $alreadyImported ? 'already_imported' : 'candidate', 'validation_ms' => $validationMs]);
        if ($alreadyImported) {
            $result['attempted'] = 1;
            $result['already_imported_count'] = 1;
            $result['items'][] = ['product_id' => $productId, 'status' => 'already_imported'];
            $result['stop_reason'] = 'already_imported';
            $result['total_attempted'] = 1;
            $result['total_ms'] = $this->elapsed_ms($startedAt);
            $result['summary'] = $this->raw_summary($result);
            $result['raw_summary'] = $result['summary'];
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'ok' => true, 'stop_reason' => $result['stop_reason'], 'total_ms' => $result['total_ms']]);
            return $result;
        }

        $recoverableState = $this->recoverable_retry_blocked_state($productId);
        if (!empty($recoverableState['blocked'])) {
            $result['attempted'] = 1;
            $result['repair_needed_count'] = 1;
            $result['skipped_counts']['skipped_recoverable_retry_blocked'] = 1;
            $skippedItem = $this->recoverable_skipped_item($productId, $recoverableState);
            if ((string) ($skippedItem['skip_reason'] ?? '') === 'ovoko_photo_file_missing') {
                $result['photo_file_missing_count'] = 1;
                $result['skipped_photo_file_missing'] = 1;
                $result['skipped_counts']['skipped_photo_file_missing'] = 1;
            }
            $result['items'][] = $skippedItem;
            $result['stop_reason'] = 'recoverable_retry_blocked';
            $result['total_attempted'] = 1;
            $result['total_ms'] = $this->elapsed_ms($startedAt);
            $result['summary'] = $this->raw_summary($result);
            $result['raw_summary'] = $result['summary'];
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'ok' => true, 'stop_reason' => $result['stop_reason'], 'total_ms' => $result['total_ms']]);
            return $result;
        }

        $previewStartedAt = microtime(true);
        $this->log_marker('CRM_ONLY_BATCH_PREVIEW_START', ['product_id' => $productId]);
        $preview = $this->previewService->preview($productId);
        $previewMs = $this->elapsed_ms($previewStartedAt);
        $this->log_marker('CRM_ONLY_BATCH_PREVIEW_DONE', ['product_id' => $productId, 'preview_ms' => $previewMs, 'would_be_eligible' => !empty($preview['would_be_eligible']), 'validation_error_count' => count((array) ($preview['validation_errors'] ?? []))]);
        $previewCodes = $this->validation_codes($preview);
        $this->add_preview_blocker_counts($result, $previewCodes);
        $result['attempted'] = 1;
        $result['last_product_id_processed'] = $productId;
        $result['next_cursor'] = $productId;
        $result['next_product_id'] = $productId;

        if ($mode === 'preview_one') {
            if (!empty($preview['would_be_eligible']) && empty($preview['validation_errors'])) {
                $result['eligible_count'] = 1;
            } else {
                $result['blocked_count'] = 1;
            }
            $result['items'][] = $this->preview_item($productId, $preview, $previewCodes);
            $result['preview'] = $this->preview_item($productId, $preview, $previewCodes);
            $result['eligible'] = $result['eligible_count'];
            $result['total_attempted'] = 1;
            $result['preview_ms'] = $previewMs;
            $result['total_ms'] = $this->elapsed_ms($startedAt);
            $result['summary'] = $this->raw_summary($result);
            $result['raw_summary'] = $result['summary'];
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'preview_ms' => $previewMs, 'total_ms' => $result['total_ms']]);
            return $result;
        }

        if (empty($preview['would_be_eligible']) || !empty($preview['validation_errors'])) {
            $result['ok'] = false;
            $result['blocked_count'] = 1;
            $result['stop_reason'] = 'preview_ineligible';
            $result['errors'][] = ['product_id' => $productId, 'code' => 'preview_ineligible', 'message' => 'CRM-only preview is not eligible for live import.', 'validation_codes' => $previewCodes];
            $result['items'][] = ['product_id' => $productId, 'status' => 'blocked', 'validation_codes' => $previewCodes];
            $result['eligible'] = 0;
            $result['total_attempted'] = 1;
            $result['preview_ms'] = $previewMs;
            $result['total_ms'] = $this->elapsed_ms($startedAt);
            $result['summary'] = $this->raw_summary($result);
            $result['raw_summary'] = $result['summary'];
            $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'ok' => false, 'stop_reason' => $result['stop_reason'], 'preview_ms' => $previewMs, 'total_ms' => $result['total_ms']]);
            return $result;
        }

        $importStartedAt = microtime(true);
        $this->log_marker('CRM_ONLY_BATCH_IMPORT_START', ['product_id' => $productId]);
        $import = $this->importService->create($productId, $this->live_confirmations($preview));
        $importMs = $this->elapsed_ms($importStartedAt);
        $this->log_marker('CRM_ONLY_BATCH_IMPORT_DONE', ['product_id' => $productId, 'import_ms' => $importMs, 'ok' => !empty($import['ok']), 'status' => (string) ($import['status'] ?? ''), 'error_code' => (string) ($import['error_code'] ?? '')]);
        $result['imported_items'][] = $import;
        if (!empty($import['ok'])) {
            $result['items'][] = ['product_id' => $productId, 'status' => (string) ($import['status'] ?? ''), 'ok' => true, 'part_id' => (string) ($import['part_id'] ?? '')];
            $result['success_count'] = 1;
        } elseif ($this->is_photo_file_missing_import_result($import)) {
            $result['items'][] = $this->photo_file_missing_item($productId);
            $result['repair_needed_count'] = 1;
            $result['photo_file_missing_count'] = 1;
            $result['skipped_photo_file_missing'] = 1;
            $result['skipped_counts']['skipped_photo_file_missing'] = 1;
            $result['stop_reason'] = 'ovoko_photo_file_missing_repair_needed';
        } else {
            $result['items'][] = ['product_id' => $productId, 'status' => (string) ($import['status'] ?? ''), 'ok' => false, 'part_id' => (string) ($import['part_id'] ?? '')];
            $result['ok'] = false;
            $result['failed_count'] = 1;
            if ((string) ($import['error_code'] ?? '') === 'recoverable_part_id_blocks_retry') {
                $result['repair_needed_count'] = 1;
            }
            $result['errors'][] = ['product_id' => $productId, 'code' => (string) ($import['error_code'] ?? 'import_failed'), 'message' => (string) ($import['message'] ?? 'Import failed.'), 'result' => $import];
            $result['stop_reason'] = 'import_failed';
        }
        $result['eligible'] = $result['eligible_count'];
        $result['total_attempted'] = 1;
        $result['preview_ms'] = $previewMs;
        $result['import_ms'] = $importMs;
        $result['total_ms'] = $this->elapsed_ms($startedAt);
        $result['summary'] = $this->raw_summary($result);
        $result['raw_summary'] = $result['summary'];
        $this->log_marker('CRM_ONLY_BATCH_RESULT_READY', ['mode' => $mode, 'product_id' => $productId, 'ok' => !empty($result['ok']), 'import_ms' => $importMs, 'total_ms' => $result['total_ms'], 'stop_reason' => $result['stop_reason']]);
        return $result;
    }

    private function base_result(string $mode, int $batchNumber, int $batchSize, int $cursor, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        return [
            'ok' => true,
            'mode' => $mode,
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
            'photo_file_missing_count' => 0,
            'skipped_photo_file_missing' => 0,
            'has_more' => false,
            'should_continue' => false,
            'stop_reason' => '',
            'next_cursor' => $cursor,
            'next_product_id' => $cursor,
            'last_product_id_processed' => 0,
            'gps_gmail_total_draft_count' => 0,
            'gps_gmail_total_without_ovoko_meta_count' => 0,
            'gps_gmail_total_with_ovoko_meta_count' => 0,
            'scan_window_first_id' => 0,
            'scan_window_last_id' => 0,
            'windows_scanned' => 0,
            'max_scan_windows' => $this->max_scan_windows(),
            'checked_count' => 0,
            'has_more_gps_gmail_after_cursor' => false,
            'next_unchecked_gps_gmail_id' => 0,
            'max_checked' => 0,
            'examples_already_imported' => [],
            'examples_not_imported_not_selected' => [],
            'errors' => [],
            'imported_items' => [],
            'items' => [],
            'no_ebay_write' => true,
            'uses_existing_crm_only_import_service' => true,
            'dry_run_supported' => true,
            'summary' => [],
        ];
    }

    private function find_candidate_product_ids(int $limit, int $afterProductId, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        $queryStartedAt = microtime(true);
        $ids = [];
        $rawIds = [];
        $checkedCount = 0;
        $lastCheckedProductId = $afterProductId;
        $scanWindowFirstId = 0;
        $scanWindowLastId = 0;
        $windowsScanned = 0;
        $skippedRecoverableItems = [];
        $skippedCounts = $this->empty_skipped_counts();
        $examplesAlreadyImported = [];
        $examplesNotImportedNotSelected = [];
        $lookaheadWindow = $this->candidate_lookahead_limit($limit);
        $maxScanWindows = $this->max_scan_windows();
        $maxChecked = $lookaheadWindow * $maxScanWindows;
        $scanCursor = $afterProductId;

        while ($windowsScanned < $maxScanWindows && $checkedCount < $maxChecked) {
            $windowIds = $this->query_candidate_product_ids($lookaheadWindow, $scanCursor, $productIdFrom, $productIdTo, $createdAfter);
            if ($windowIds === []) {
                break;
            }

            $windowsScanned++;
            $rawIds = array_merge($rawIds, $windowIds);
            if ($scanWindowFirstId <= 0) {
                $scanWindowFirstId = (int) $windowIds[0];
            }
            $scanWindowLastId = (int) end($windowIds);

            foreach ($windowIds as $rawId) {
                if ($checkedCount >= $maxChecked) {
                    break 2;
                }

                $productId = (int) $rawId;
                $checkedCount++;
                $lastCheckedProductId = max($lastCheckedProductId, $productId);
                $scanCursor = $lastCheckedProductId;
                $skippedCounts['found_gps_gmail_drafts']++;
                $validationStartedAt = microtime(true);
                $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_START', ['product_id' => $productId]);

                if ($onlyGmailImported && !$this->matches_gmail_imported_filter($productId)) {
                    $skippedCounts['skipped_other_blocked']++;
                    $this->append_limited($examplesNotImportedNotSelected, $this->not_selected_example($productId, 'not_gmail_imported_filter'), 5);
                    $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'not_gmail_imported_filter', 'validation_ms' => $this->elapsed_ms($validationStartedAt)]);
                    continue;
                }

                if ($this->has_imported_meta($productId)) {
                    $skippedCounts['skipped_already_imported']++;
                    $this->append_limited($examplesAlreadyImported, $this->already_imported_example($productId), 5);
                    $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'already_imported', 'validation_ms' => $this->elapsed_ms($validationStartedAt)]);
                    continue;
                }

                $recoverableState = $this->recoverable_retry_blocked_state($productId);
                if (!empty($recoverableState['blocked'])) {
                    $skippedCounts['skipped_recoverable_retry_blocked']++;
                    $skippedItem = $this->recoverable_skipped_item($productId, $recoverableState);
                    if ((string) ($skippedItem['skip_reason'] ?? '') === 'ovoko_photo_file_missing') {
                        $skippedCounts['skipped_photo_file_missing']++;
                    }
                    $skippedRecoverableItems[] = $skippedItem;
                    $this->append_limited($examplesNotImportedNotSelected, $this->not_selected_example($productId, 'repair_needed:' . (string) ($recoverableState['error_code'] ?? 'recoverable_part_id_blocks_retry')), 5);
                    $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'skipped_recoverable', 'recoverable_part_id' => (string) ($recoverableState['recoverable_part_id'] ?? ''), 'validation_ms' => $this->elapsed_ms($validationStartedAt)]);
                    continue;
                }

                $this->log_marker('CRM_ONLY_BATCH_VALIDATE_PRODUCT_DONE', ['product_id' => $productId, 'status' => 'gps_gmail_candidate', 'validation_ms' => $this->elapsed_ms($validationStartedAt)]);
                $ids[] = $productId;
            }

            if (count($windowIds) < $lookaheadWindow) {
                break;
            }
        }

        $hasMoreAfterCursor = $this->next_gps_gmail_id_after($lastCheckedProductId, $productIdFrom, $productIdTo, $createdAfter);
        $diagnostics = $this->gps_gmail_draft_diagnostics($lastCheckedProductId, $productIdFrom, $productIdTo, $createdAfter);
        $queryMs = $this->elapsed_ms($queryStartedAt);

        return array_merge($diagnostics, [
            'ids' => $ids,
            'raw_ids' => $rawIds,
            'checked_count' => $checkedCount,
            'last_checked_product_id' => $lastCheckedProductId,
            'query_ms' => $queryMs,
            'skipped_counts' => $skippedCounts,
            'skipped_recoverable_items' => $skippedRecoverableItems,
            'scan_window_first_id' => $scanWindowFirstId,
            'scan_window_last_id' => $scanWindowLastId,
            'windows_scanned' => $windowsScanned,
            'max_scan_windows' => $maxScanWindows,
            'max_checked' => $maxChecked,
            'has_more_gps_gmail_after_cursor' => $hasMoreAfterCursor > 0,
            'next_unchecked_gps_gmail_id' => $hasMoreAfterCursor,
            'examples_already_imported' => $examplesAlreadyImported,
            'examples_not_imported_not_selected' => $examplesNotImportedNotSelected,
        ]);
    }

    private function evaluate_candidate_eligibility(array $candidateScan, int $eligibleLimit): array
    {
        $eligibleIds = [];
        foreach ((array) ($candidateScan['ids'] ?? []) as $productId) {
            $productId = (int) $productId;
            $recoverableState = $this->recoverable_retry_blocked_state($productId);
            if (!empty($recoverableState['blocked'])) {
                $candidateScan['skipped_counts']['skipped_recoverable_retry_blocked']++;
                $skippedItem = $this->recoverable_skipped_item($productId, $recoverableState);
                if ((string) ($skippedItem['skip_reason'] ?? '') === 'ovoko_photo_file_missing') {
                    $candidateScan['skipped_counts']['skipped_photo_file_missing']++;
                }
                $candidateScan['skipped_recoverable_items'][] = $skippedItem;
                $this->append_limited($candidateScan['examples_not_imported_not_selected'], $this->not_selected_example($productId, 'repair_needed:' . (string) ($recoverableState['error_code'] ?? 'recoverable_part_id_blocks_retry')), 5);
                continue;
            }
            $previewStartedAt = microtime(true);
            $this->log_marker('CRM_ONLY_BATCH_PREVIEW_START', ['product_id' => $productId, 'phase' => 'find_candidate']);
            $preview = $this->previewService->preview($productId);
            $previewMs = $this->elapsed_ms($previewStartedAt);
            $codes = $this->validation_codes($preview);
            $this->log_marker('CRM_ONLY_BATCH_PREVIEW_DONE', ['product_id' => $productId, 'phase' => 'find_candidate', 'preview_ms' => $previewMs, 'would_be_eligible' => !empty($preview['would_be_eligible']), 'validation_error_count' => count((array) ($preview['validation_errors'] ?? []))]);
            if (!empty($preview['would_be_eligible']) && empty($preview['validation_errors'])) {
                $eligibleIds[] = $productId;
                if (count($eligibleIds) >= $eligibleLimit) {
                    break;
                }
                continue;
            }
            $bucket = $this->preview_blocker_bucket($codes);
            $candidateScan['skipped_counts'][$bucket]++;
            $this->append_limited($candidateScan['examples_not_imported_not_selected'], $this->not_selected_example($productId, 'blocked_validation:' . implode(',', $codes)), 5);
        }
        $candidateScan['ids'] = $eligibleIds;
        return $candidateScan;
    }

    private function empty_skipped_counts(): array
    {
        return [
            'found_gps_gmail_drafts' => 0,
            'skipped_already_imported' => 0,
            'skipped_recoverable_retry_blocked' => 0,
            'skipped_photo_file_missing' => 0,
            'skipped_missing_images' => 0,
            'skipped_missing_part_code' => 0,
            'skipped_other_blocked' => 0,
        ];
    }

    private function find_candidate_stop_reason(array $candidateScan): string
    {
        $counts = (array) ($candidateScan['skipped_counts'] ?? []);
        $found = (int) ($counts['found_gps_gmail_drafts'] ?? 0);
        if ($found <= 0) {
            return 'no_gps_gmail_drafts_found';
        }
        if (!empty($candidateScan['has_more_gps_gmail_after_cursor'])) {
            return 'scan_limit_reached_before_candidate';
        }
        if ((int) ($candidateScan['gps_gmail_total_without_ovoko_meta_count'] ?? 0) <= 0) {
            return 'all_gps_gmail_already_imported';
        }
        if ((int) ($counts['skipped_recoverable_retry_blocked'] ?? 0) > 0
            && ((int) ($counts['skipped_already_imported'] ?? 0) + (int) ($counts['skipped_recoverable_retry_blocked'] ?? 0)) >= $found) {
            return 'only_recoverable_or_blocked_candidates_found';
        }
        if ((int) ($counts['skipped_missing_images'] ?? 0) > 0) {
            return 'gps_gmail_blocked_missing_images';
        }
        if ((int) ($counts['skipped_missing_part_code'] ?? 0) > 0) {
            return 'gps_gmail_blocked_missing_part_code';
        }
        if ((int) ($counts['skipped_other_blocked'] ?? 0) > 0) {
            return 'gps_gmail_blocked_other';
        }
        return 'no_gps_gmail_eligible_candidate_after_full_scan';
    }

    private function candidate_lookahead_limit(int $batchSize): int
    {
        return self::DEFAULT_LOOKAHEAD_WINDOW;
    }

    private function max_scan_windows(): int
    {
        $configured = (int) ($this->settings['crm_only_auto_runner_max_scan_windows'] ?? 0);
        return max(1, min(100, $configured > 0 ? $configured : self::DEFAULT_MAX_SCAN_WINDOWS));
    }

    private function query_candidate_product_ids(int $limit, int $afterProductId, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        global $wpdb;
        $min = max($afterProductId, $productIdFrom > 0 ? $productIdFrom - 1 : 0);
        $where = ["p.post_type = 'product'", "p.post_status = 'draft'", 'p.ID > %d', 'sku.meta_key = %s', 'sku.meta_value LIKE %s'];
        $params = [$min, '_sku', $wpdb->esc_like(self::GMAIL_SKU_PREFIX) . '%'];
        if ($productIdTo > 0) {
            $where[] = 'p.ID <= %d';
            $params[] = $productIdTo;
        }
        if ($createdAfter !== '') {
            $where[] = 'p.post_date >= %s';
            $params[] = $createdAfter . ' 00:00:00';
        }
        $params[] = max(1, min(self::DEFAULT_LOOKAHEAD_WINDOW, $limit));
        $sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID WHERE " . implode(' AND ', $where) . ' ORDER BY p.ID ASC LIMIT %d';
        $prepared = $wpdb->prepare($sql, $params);
        $ids = $wpdb->get_col($prepared);
        return array_values(array_map('intval', is_array($ids) ? $ids : []));
    }

    private function has_more_candidates(int $afterProductId, bool $onlyGmailImported, int $productIdFrom, int $productIdTo, string $createdAfter): bool
    {
        return $this->next_gps_gmail_id_after($afterProductId, $productIdFrom, $productIdTo, $createdAfter) > 0;
    }

    private function matches_gmail_imported_filter(int $productId): bool
    {
        if (trim((string) get_post_meta($productId, '_gps_gmail_import_source', true)) === 'gmail') {
            return true;
        }
        if (trim((string) get_post_meta($productId, '_gps_source_staging_item_id', true)) !== '') {
            return true;
        }
        return str_starts_with((string) get_post_meta($productId, '_sku', true), self::GMAIL_SKU_PREFIX);
    }



    private function copy_scan_diagnostics(array &$result, array $candidateScan): void
    {
        foreach ([
            'gps_gmail_total_draft_count',
            'gps_gmail_total_without_ovoko_meta_count',
            'gps_gmail_total_with_ovoko_meta_count',
            'scan_window_first_id',
            'scan_window_last_id',
            'windows_scanned',
            'max_scan_windows',
            'checked_count',
            'has_more_gps_gmail_after_cursor',
            'next_unchecked_gps_gmail_id',
            'max_checked',
            'examples_already_imported',
            'examples_not_imported_not_selected',
        ] as $key) {
            if (array_key_exists($key, $candidateScan)) {
                $result[$key] = $candidateScan[$key];
            }
        }
    }

    private function gps_gmail_draft_diagnostics(int $afterProductId, int $productIdFrom, int $productIdTo, string $createdAfter): array
    {
        global $wpdb;
        $where = ["p.post_type = 'product'", "p.post_status = 'draft'", 'sku.meta_key = %s', 'sku.meta_value LIKE %s'];
        $params = ['_sku', $wpdb->esc_like(self::GMAIL_SKU_PREFIX) . '%'];
        if ($productIdFrom > 0) {
            $where[] = 'p.ID >= %d';
            $params[] = $productIdFrom;
        }
        if ($productIdTo > 0) {
            $where[] = 'p.ID <= %d';
            $params[] = $productIdTo;
        }
        if ($createdAfter !== '') {
            $where[] = 'p.post_date >= %s';
            $params[] = $createdAfter . ' 00:00:00';
        }

        $fromSql = "{$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID";
        $whereSql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$fromSql} WHERE {$whereSql}", $params));

        $withMetaSql = "SELECT COUNT(DISTINCT p.ID) FROM {$fromSql}
            LEFT JOIN {$wpdb->postmeta} opm ON opm.post_id = p.ID AND opm.meta_key = '_ovoko_part_id' AND opm.meta_value <> ''
            LEFT JOIN {$wpdb->postmeta} npm ON npm.post_id = p.ID AND npm.meta_key = 'ovoko_part_id' AND npm.meta_value <> ''
            WHERE {$whereSql} AND (opm.post_id IS NOT NULL OR npm.post_id IS NOT NULL)";
        $withMeta = (int) $wpdb->get_var($wpdb->prepare($withMetaSql, $params));
        $nextId = $this->next_gps_gmail_id_after($afterProductId, $productIdFrom, $productIdTo, $createdAfter);

        return [
            'gps_gmail_total_draft_count' => $total,
            'gps_gmail_total_with_ovoko_meta_count' => $withMeta,
            'gps_gmail_total_without_ovoko_meta_count' => max(0, $total - $withMeta),
            'has_more_gps_gmail_after_cursor' => $nextId > 0,
            'next_unchecked_gps_gmail_id' => $nextId,
        ];
    }

    private function next_gps_gmail_id_after(int $afterProductId, int $productIdFrom, int $productIdTo, string $createdAfter): int
    {
        global $wpdb;
        $min = max($afterProductId, $productIdFrom > 0 ? $productIdFrom - 1 : 0);
        $where = ["p.post_type = 'product'", "p.post_status = 'draft'", 'p.ID > %d', 'sku.meta_key = %s', 'sku.meta_value LIKE %s'];
        $params = [$min, '_sku', $wpdb->esc_like(self::GMAIL_SKU_PREFIX) . '%'];
        if ($productIdTo > 0) {
            $where[] = 'p.ID <= %d';
            $params[] = $productIdTo;
        }
        if ($createdAfter !== '') {
            $where[] = 'p.post_date >= %s';
            $params[] = $createdAfter . ' 00:00:00';
        }
        $sql = "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID WHERE " . implode(' AND ', $where) . ' ORDER BY p.ID ASC LIMIT 1';
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    private function append_limited(array &$items, array $item, int $limit): void
    {
        if (count($items) < $limit) {
            $items[] = $item;
        }
    }

    private function already_imported_example(int $productId): array
    {
        $ovokoPartId = trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        if ($ovokoPartId === '') {
            $ovokoPartId = trim((string) get_post_meta($productId, 'ovoko_part_id', true));
        }
        return [
            'product_id' => $productId,
            'sku' => (string) get_post_meta($productId, '_sku', true),
            'ovoko_part_id' => $ovokoPartId,
            '_gps_ovoko_crm_only_imported_at' => (string) get_post_meta($productId, '_gps_ovoko_crm_only_imported_at', true),
        ];
    }

    private function not_selected_example(int $productId, string $reason): array
    {
        return [
            'product_id' => $productId,
            'sku' => (string) get_post_meta($productId, '_sku', true),
            'post_status' => (string) get_post_status($productId),
            'has_images' => $this->product_has_images($productId),
            'part_code_present' => $this->product_part_code_present($productId),
            'runner_block_reason' => $reason,
        ];
    }

    private function product_has_images(int $productId): bool
    {
        if ((int) get_post_thumbnail_id($productId) > 0) {
            return true;
        }
        $gallery = trim((string) get_post_meta($productId, '_product_image_gallery', true));
        return $gallery !== '';
    }

    private function product_part_code_present(int $productId): bool
    {
        foreach (['_gps_part_code', '_gps_oem', '_gps_oe', 'part_code', 'oem', '_sku'] as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') {
                return true;
            }
        }
        return false;
    }

    private function elapsed_ms(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function log_marker(string $marker, array $context = []): void
    {
        $safeContext = [];
        foreach ($context as $key => $value) {
            if (is_bool($value)) {
                $safeContext[$key] = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value) || $value === null) {
                $safeContext[$key] = $value === null ? 'null' : (string) $value;
            } elseif (is_array($value)) {
                $safeContext[$key] = implode(',', array_map(static fn($item): string => (string) $item, $value));
            } else {
                $safeContext[$key] = sanitize_text_field((string) $value);
            }
        }
        if ($safeContext === []) {
            error_log($marker);
            return;
        }
        $pairs = [];
        foreach ($safeContext as $key => $value) {
            $pairs[] = sanitize_key((string) $key) . '=' . (string) $value;
        }
        error_log($marker . ' ' . implode(' ', $pairs));
    }

    private function sanitize_created_after(string $value): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function has_imported_meta(int $productId): bool
    {
        if (trim((string) get_post_meta($productId, '_ovoko_part_id', true)) !== '') {
            return true;
        }
        if (trim((string) get_post_meta($productId, 'ovoko_part_id', true)) !== '') {
            return true;
        }
        if (trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_imported_at', true)) === '') {
            return false;
        }
        return $this->has_confirmed_crm_only_part_id($productId);
    }

    private function has_confirmed_crm_only_part_id(int $productId): bool
    {
        foreach (['_gps_ovoko_crm_only_import_result_summary', '_gps_ovoko_crm_only_import_response_summary', '_gps_ovoko_crm_only_import_response_raw'] as $metaKey) {
            $decoded = $this->decode_json_meta($productId, $metaKey);
            if ($decoded === []) {
                continue;
            }
            $partId = $this->extract_part_id_from_decoded_response($decoded);
            $status = strtoupper(trim((string) ($decoded['status'] ?? $decoded['status_code'] ?? $decoded['response_classification']['status_code'] ?? '')));
            $ok = !empty($decoded['ok']) || in_array($status, ['SUCCESS', 'R200', 'R202'], true);
            if ($partId !== '' && $ok) {
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


    private function recoverable_retry_blocked_state(int $productId): array
    {
        $state = [
            'blocked' => false,
            'error_code' => '',
            'recoverable_part_id' => '',
            'message' => '',
            'source' => '',
        ];

        $storedRepairReason = trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_import_repair_reason', true));
        if ($storedRepairReason === 'ovoko_photo_file_missing') {
            $state['blocked'] = true;
            $state['error_code'] = 'ovoko_photo_file_missing';
            $state['message'] = 'Ovoko rejected photo file; image must be checked/reuploaded before retry';
            $state['source'] = '_gps_ovoko_crm_only_import_repair_reason';
        }

        $lastError = $this->decode_json_meta($productId, '_gps_ovoko_crm_only_import_last_error');
        $lastErrorCode = trim((string) ($lastError['code'] ?? $lastError['error_code'] ?? ''));
        $lastErrorMessage = trim((string) ($lastError['message'] ?? ''));
        if ($lastErrorCode === 'ovoko_photo_file_missing') {
            $state['blocked'] = true;
            $state['error_code'] = 'ovoko_photo_file_missing';
            $state['message'] = 'Ovoko rejected photo file; image must be checked/reuploaded before retry';
            $state['source'] = $state['source'] !== '' ? $state['source'] : '_gps_ovoko_crm_only_import_last_error';
        }
        if ($lastErrorCode === 'recoverable_part_id_blocks_retry') {
            $state['blocked'] = true;
            $state['error_code'] = $lastErrorCode;
            $state['message'] = $lastErrorMessage;
            $state['source'] = '_gps_ovoko_crm_only_import_last_error';
        }
        $lastErrorRecoverablePartId = trim((string) ($lastError['recoverable_part_id'] ?? ''));
        if ($lastErrorRecoverablePartId !== '') {
            $state['blocked'] = true;
            $state['recoverable_part_id'] = $lastErrorRecoverablePartId;
            $state['source'] = $state['source'] !== '' ? $state['source'] : '_gps_ovoko_crm_only_import_last_error';
        }

        $storedRecoverablePartId = trim((string) get_post_meta($productId, '_gps_ovoko_crm_only_import_recoverable_part_id', true));
        if ($storedRecoverablePartId !== '') {
            $state['blocked'] = true;
            $state['recoverable_part_id'] = $storedRecoverablePartId;
            $state['source'] = $state['source'] !== '' ? $state['source'] : '_gps_ovoko_crm_only_import_recoverable_part_id';
        }

        $lastResponse = $this->decode_json_meta($productId, '_gps_ovoko_crm_only_import_last_response_raw');
        if ($lastResponse !== [] && $this->decoded_response_is_photo_file_missing($lastResponse)) {
            $state['blocked'] = true;
            $state['error_code'] = 'ovoko_photo_file_missing';
            $state['message'] = 'Ovoko rejected photo file; image must be checked/reuploaded before retry';
            $state['source'] = $state['source'] !== '' ? $state['source'] : '_gps_ovoko_crm_only_import_last_response_raw';
        }
        if ($lastResponse !== []) {
            $responsePartId = $this->extract_part_id_from_decoded_response($lastResponse);
            if ($responsePartId !== '') {
                $state['blocked'] = true;
                $state['recoverable_part_id'] = $state['recoverable_part_id'] !== '' ? $state['recoverable_part_id'] : $responsePartId;
                $state['source'] = $state['source'] !== '' ? $state['source'] : '_gps_ovoko_crm_only_import_last_response_raw';
            }
        }

        $haystack = strtolower($lastErrorMessage . ' ' . wp_json_encode($lastResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($haystack !== ' ' && str_contains($haystack, 'already contains part_id')) {
            $state['blocked'] = true;
            $state['source'] = $state['source'] !== '' ? $state['source'] : 'already_contains_part_id_message';
        }

        if (!empty($state['blocked']) && $state['message'] === '') {
            $state['message'] = 'needs manual repair/link or stale recoverable clear';
        }
        if (!empty($state['blocked']) && $state['error_code'] === '') {
            $state['error_code'] = 'recoverable_part_id_blocks_retry';
        }

        return $state;
    }

    private function decode_json_meta(int $productId, string $metaKey): array
    {
        $raw = trim((string) get_post_meta($productId, $metaKey, true));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extract_part_id_from_decoded_response(array $decoded): string
    {
        $candidates = [
            $decoded['recoverable_part_id'] ?? null,
            $decoded['part_id'] ?? null,
            $decoded['id'] ?? null,
            $decoded['data']['recoverable_part_id'] ?? null,
            $decoded['data']['part_id'] ?? null,
            $decoded['data']['id'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return function_exists('sanitize_text_field') ? sanitize_text_field($candidate) : $candidate;
            }
        }
        return '';
    }

    private function recoverable_skipped_item(int $productId, array $state): array
    {
        if ((string) ($state['error_code'] ?? '') === 'ovoko_photo_file_missing') {
            return $this->photo_file_missing_item($productId);
        }

        return [
            'product_id' => $productId,
            'status' => 'repair_needed',
            'skip_reason' => 'skipped_recoverable_retry_blocked',
            'error_code' => (string) ($state['error_code'] ?? 'recoverable_part_id_blocks_retry'),
            'recoverable_part_id' => (string) ($state['recoverable_part_id'] ?? ''),
            'message' => 'needs manual repair/link or stale recoverable clear',
        ];
    }

    private function photo_file_missing_item(int $productId): array
    {
        return [
            'product_id' => $productId,
            'status' => 'repair_needed',
            'skip_reason' => 'ovoko_photo_file_missing',
            'error_code' => 'ovoko_photo_file_missing',
            'message' => 'Ovoko rejected photo file; image must be checked/reuploaded before retry',
        ];
    }

    private function is_photo_file_missing_import_result(array $import): bool
    {
        return (string) ($import['error_code'] ?? '') === 'ovoko_photo_file_missing'
            || (string) ($import['skip_reason'] ?? '') === 'ovoko_photo_file_missing';
    }

    private function decoded_response_is_photo_file_missing(array $response): bool
    {
        $statusCode = strtoupper(trim((string) ($response['status_code'] ?? $response['status'] ?? '')));
        $haystack = strtolower(wp_json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        return $statusCode === 'R400' && str_contains($haystack, 'photo') && str_contains($haystack, 'file does not exist');
    }


    private function preview_blocker_bucket(array $codes): string
    {
        if (array_intersect($codes, ['missing_images', 'inaccessible_image_url', 'photo_must_equal_first_photos']) !== []) {
            return 'skipped_missing_images';
        }
        if (in_array('missing_part_identifier', $codes, true)) {
            return 'skipped_missing_part_code';
        }
        return 'skipped_other_blocked';
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
            'photo_file_missing_count' => $result['photo_file_missing_count'],
            'skipped_photo_file_missing' => $result['skipped_photo_file_missing'],
            'has_more' => $result['has_more'],
            'next_cursor' => $result['next_cursor'],
            'stop_reason' => $result['stop_reason'],
        ];
    }
}
