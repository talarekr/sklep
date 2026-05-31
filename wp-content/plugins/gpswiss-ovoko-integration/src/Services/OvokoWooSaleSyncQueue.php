<?php

namespace GPSwiss\Ovoko\Services;

class OvokoWooSaleSyncQueue
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const META_STATUS = 'ovoko_sale_sync_status';
    public const META_ATTEMPTED_AT = 'ovoko_sale_sync_attempted_at';
    public const META_SUCCESS_AT = 'ovoko_sale_sync_success_at';
    public const META_ERROR = 'ovoko_sale_sync_error';
    public const META_REQUEST_ID = 'ovoko_sale_sync_request_id';
    public const META_PART_ID = 'ovoko_sale_sync_part_id';
    public const META_ORDER_ID = 'ovoko_sale_sync_order_id';
    public const META_SOURCE = 'ovoko_sale_sync_source';
    public const META_EXTERNAL_ORDER_ID = 'ovoko_sale_sync_external_order_id';
    public const META_PRODUCT_ID = 'ovoko_sale_sync_product_id';
    public const META_RETRY_COUNT = 'retry_count';

    public const META_KEYS = [
        self::META_STATUS,
        self::META_ATTEMPTED_AT,
        self::META_SUCCESS_AT,
        self::META_ERROR,
        self::META_REQUEST_ID,
        self::META_PART_ID,
        self::META_ORDER_ID,
        self::META_SOURCE,
        self::META_EXTERNAL_ORDER_ID,
        self::META_PRODUCT_ID,
        self::META_RETRY_COUNT,
    ];

    public const SAFE_ORDER_HOOKS = [
        'woocommerce_payment_complete',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_completed',
    ];

    private const MAX_RETRY_COUNT = 6;
    private const WORKER_LIMIT = 10;
    private const EXTERNAL_JOBS_OPTION = 'gpswiss_ovoko_sale_sync_external_jobs';
    private const EXTERNAL_JOB_LIMIT = 200;

    private OvokoIntegrationService $integrationService;

    /** @var array<int,array<string,mixed>> */
    private array $stockSnapshotsBeforeSave = [];

    private static int $ovokoToWooStockSuppressionDepth = 0;

    public function __construct(OvokoIntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function hooks(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'enqueue_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'enqueue_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'enqueue_order'], 20, 1);
        add_filter('gpswiss_ovoko_enqueue_sale_job_result', [$this, 'enqueue_external_sale_job'], 10, 2);
        add_action('woocommerce_before_product_object_save', [$this, 'capture_product_stock_before_save'], 5, 1);
        add_action('woocommerce_after_product_object_save', [$this, 'enqueue_manual_stock_change_if_sold_out'], 20, 1);
    }


    public static function suppress_ovoko_to_woo_stock_hooks(bool $suppressed = true): void
    {
        self::$ovokoToWooStockSuppressionDepth = max(0, self::$ovokoToWooStockSuppressionDepth + ($suppressed ? 1 : -1));
    }

    public static function ovoko_to_woo_stock_hooks_suppressed(): bool
    {
        return self::$ovokoToWooStockSuppressionDepth > 0;
    }

    public static function with_ovoko_to_woo_stock_suppression(callable $callback)
    {
        self::suppress_ovoko_to_woo_stock_hooks(true);
        try {
            return $callback();
        } finally {
            self::suppress_ovoko_to_woo_stock_hooks(false);
        }
    }

    public function capture_product_stock_before_save($product): void
    {
        if (self::ovoko_to_woo_stock_hooks_suppressed() || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return;
        }

        $this->stockSnapshotsBeforeSave[$productId] = $this->read_product_stock_snapshot($productId);
    }

    public function enqueue_manual_stock_change_if_sold_out($product): array
    {
        $emptyResult = ['ok' => true, 'queued' => false, 'skipped' => true, 'reason' => 'not_applicable'];
        if (self::ovoko_to_woo_stock_hooks_suppressed() || !is_object($product) || !method_exists($product, 'get_id')) {
            return array_merge($emptyResult, ['reason' => 'ovoko_to_woo_stock_sync_suppressed']);
        }

        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return array_merge($emptyResult, ['reason' => 'missing_product_id']);
        }

        $previous = $this->stockSnapshotsBeforeSave[$productId] ?? $this->read_product_stock_snapshot($productId);
        unset($this->stockSnapshotsBeforeSave[$productId]);

        $newQuantity = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity('edit') : get_post_meta($productId, '_stock', true);
        $newStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status('edit') : (string) get_post_meta($productId, '_stock_status', true);
        $current = [
            'stock_quantity' => $this->normalize_stock_quantity($newQuantity),
            'stock_status' => $newStatus,
        ];

        if (!$this->stock_changed_to_unavailable($previous, $current)) {
            return array_merge($emptyResult, ['reason' => 'stock_not_changed_to_unavailable']);
        }

        $partId = $this->resolve_product_part_id($productId);
        if ($partId === '') {
            return array_merge($emptyResult, ['reason' => 'missing_or_invalid_ovoko_part_id_product_meta']);
        }

        $queueResult = $this->enqueue_manual_stock_change_job([
            'product_id' => $productId,
            'part_id' => $partId,
            'previous_stock_quantity' => $previous['stock_quantity'],
            'new_stock_quantity' => $current['stock_quantity'],
            'previous_stock_status' => (string) ($previous['stock_status'] ?? ''),
            'new_stock_status' => (string) ($current['stock_status'] ?? ''),
            'changed_by_user_id' => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0,
        ]);

        $this->integrationService->log_event('WOO_MANUAL_STOCK_CHANGE_DETECTED_FOR_OVOKO', [
            'product_id' => $productId,
            'part_id' => $partId,
            'previous_stock_quantity' => $previous['stock_quantity'],
            'new_stock_quantity' => $current['stock_quantity'],
            'previous_stock_status' => (string) ($previous['stock_status'] ?? ''),
            'new_stock_status' => (string) ($current['stock_status'] ?? ''),
            'queue_result' => $queueResult,
        ]);

        return $queueResult;
    }

    public function enqueue_manual_stock_change_job(array $payload): array
    {
        $source = 'woo_manual_stock_change';
        $productId = max(0, (int) ($payload['product_id'] ?? 0));
        $partId = trim((string) ($payload['part_id'] ?? ''));
        $previousQuantity = $this->normalize_stock_quantity($payload['previous_stock_quantity'] ?? null);
        $newQuantity = $this->normalize_stock_quantity($payload['new_stock_quantity'] ?? null);
        $previousStatus = (string) ($payload['previous_stock_status'] ?? '');
        $newStatus = (string) ($payload['new_stock_status'] ?? '');
        $changedByUserId = max(0, (int) ($payload['changed_by_user_id'] ?? 0));
        $response = [
            'ok' => false,
            'source' => $source,
            'product_id' => $productId,
            'part_id' => $partId,
            'status_sent_to_ovoko' => 2,
            'queued' => false,
            'skipped' => false,
            'reason' => '',
        ];

        if (!$this->queue_enabled()) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'queue_paused_by_setting']);
        }
        if ($productId <= 0) {
            return array_merge($response, ['reason' => 'missing_product_id']);
        }
        if ($partId === '') {
            $partId = $this->resolve_product_part_id($productId);
            $response['part_id'] = $partId;
        }
        if ($partId === '' || !preg_match('/^\d+$/', $partId)) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'missing_or_invalid_ovoko_part_id_product_meta']);
        }

        $jobId = $this->build_manual_stock_change_job_id($productId, $partId, $previousQuantity, $newQuantity, $previousStatus, $newStatus);
        $jobs = $this->external_jobs();
        $existing = is_array($jobs[$jobId] ?? null) ? (array) $jobs[$jobId] : [];
        if (($existing['status'] ?? '') === self::STATUS_SUCCESS) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'already_success', 'job_id' => $jobId, 'request_id' => (string) ($existing['request_id'] ?? '')]);
        }
        if (in_array((string) ($existing['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'already_queued', 'job_id' => $jobId, 'request_id' => (string) ($existing['request_id'] ?? '')]);
        }

        $createdAt = gmdate('c');
        $requestId = 'woo-manual-stock-product-' . $productId . '-part-' . $partId . '-' . substr(md5($jobId), 0, 10);
        $job = [
            'job_id' => $jobId,
            'status' => self::STATUS_PENDING,
            'source' => $source,
            'source_order_id' => '',
            'external_order_id' => '',
            'source_item_id' => '',
            'product_id' => $productId,
            'part_id' => $partId,
            'previous_stock_quantity' => $previousQuantity,
            'new_stock_quantity' => $newQuantity,
            'previous_stock_status' => $previousStatus,
            'new_stock_status' => $newStatus,
            'changed_by_user_id' => $changedByUserId,
            'request_id' => $requestId,
            'retry_count' => 0,
            'error' => '',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'status_sent_to_ovoko' => 2,
        ];
        $this->store_external_job($jobId, $job);

        return array_merge($response, ['ok' => true, 'queued' => true, 'reason' => 'queued', 'job_id' => $jobId, 'request_id' => $requestId]);
    }

    public function enqueue_order(int $orderId): array
    {
        $result = [
            'ok' => false,
            'order_id' => $orderId,
            'mode' => 'queue_only_no_ovoko_api_call',
            'queued' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        if (!$this->queue_enabled()) {
            $result['ok'] = true;
            $result['mode'] = 'queue_paused_by_setting';
            $result['skipped'] = 1;
            return $result;
        }

        if (!function_exists('wc_get_order')) {
            $result['errors'][] = 'woocommerce_unavailable';
            return $result;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            $result['errors'][] = 'order_not_found';
            return $result;
        }

        foreach ($order->get_items() as $itemId => $item) {
            $itemId = (int) $itemId;
            $existingStatus = function_exists('wc_get_order_item_meta') ? (string) wc_get_order_item_meta($itemId, self::META_STATUS, true) : '';
            if ($existingStatus === self::STATUS_SUCCESS) {
                $result['skipped']++;
                continue;
            }

            $partId = $this->resolve_item_part_id($item);
            $requestId = 'woo-' . $orderId . '-item-' . $itemId . '-part-' . ($partId !== '' ? $partId : 'missing');
            $status = $partId !== '' ? self::STATUS_PENDING : self::STATUS_SKIPPED;
            $error = $partId !== '' ? '' : 'missing_or_invalid_ovoko_part_id_product_meta';
            $this->write_item_meta($itemId, [
                self::META_STATUS => $status,
                self::META_PART_ID => $partId,
                self::META_ORDER_ID => (string) $orderId,
                self::META_SOURCE => 'woo_order',
                self::META_EXTERNAL_ORDER_ID => '',
                self::META_PRODUCT_ID => (string) $this->resolve_item_product_id($item),
                self::META_REQUEST_ID => $requestId,
                self::META_RETRY_COUNT => (string) max(0, (int) (function_exists('wc_get_order_item_meta') ? wc_get_order_item_meta($itemId, self::META_RETRY_COUNT, true) : 0)),
                self::META_ERROR => $error,
            ]);

            if ($partId === '') {
                $result['skipped']++;
                continue;
            }
            $result['queued']++;
        }

        $result['ok'] = $result['errors'] === [];
        return $result;
    }



    public function enqueue_external_sale_job(array $result, array $payload): array
    {
        $source = (string) ($payload['source'] ?? '');
        $sourceOrderId = trim((string) ($payload['source_order_id'] ?? $payload['ebay_order_id'] ?? $payload['external_order_id'] ?? ''));
        $sourceItemId = trim((string) ($payload['source_item_id'] ?? $payload['ebay_line_item_id'] ?? ''));
        $productId = max(0, (int) ($payload['product_id'] ?? 0));
        $partId = trim((string) ($payload['ovoko_part_id'] ?? $payload['part_id'] ?? ''));

        $response = [
            'ok' => false,
            'source' => $source,
            'source_order_id' => $sourceOrderId,
            'external_order_id' => $sourceOrderId,
            'source_item_id' => $sourceItemId,
            'product_id' => $productId,
            'part_id' => $partId,
            'status_sent_to_ovoko' => 2,
            'queued' => false,
            'skipped' => false,
            'reason' => '',
        ];

        if ($source !== 'ebay_order') {
            $response['reason'] = 'unsupported_external_sale_source';
            return $response;
        }
        if (!$this->queue_enabled()) {
            $response['ok'] = true;
            $response['skipped'] = true;
            $response['reason'] = 'queue_paused_by_setting';
            return $response;
        }
        if ($sourceOrderId === '' || $productId <= 0) {
            $response['reason'] = $sourceOrderId === '' ? 'missing_source_order_id' : 'missing_product_id';
            return $response;
        }
        if ($partId === '') {
            $partId = $this->resolve_product_part_id($productId);
            $response['part_id'] = $partId;
        }
        if ($partId === '' || !preg_match('/^\d+$/', $partId)) {
            $response['ok'] = true;
            $response['skipped'] = true;
            $response['reason'] = 'missing_or_invalid_ovoko_part_id_product_meta';
            $this->store_external_job($this->build_external_job_id($source, $sourceOrderId, $sourceItemId, $productId, $partId), $response + [
                'status' => self::STATUS_SKIPPED,
                'error' => 'missing_or_invalid_ovoko_part_id_product_meta',
                'retry_count' => 0,
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'request_id' => 'ebay-' . $sourceOrderId . '-item-' . ($sourceItemId !== '' ? $sourceItemId : 'line') . '-part-missing',
            ]);
            return $response;
        }

        $jobId = $this->build_external_job_id($source, $sourceOrderId, $sourceItemId, $productId, $partId);
        $jobs = $this->external_jobs();
        $existing = is_array($jobs[$jobId] ?? null) ? (array) $jobs[$jobId] : [];
        if (($existing['status'] ?? '') === self::STATUS_SUCCESS) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'already_success', 'job_id' => $jobId, 'request_id' => (string) ($existing['request_id'] ?? '')]);
        }
        if (in_array((string) ($existing['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return array_merge($response, ['ok' => true, 'skipped' => true, 'reason' => 'already_queued', 'job_id' => $jobId, 'request_id' => (string) ($existing['request_id'] ?? '')]);
        }

        $requestId = 'ebay-' . $sourceOrderId . '-item-' . ($sourceItemId !== '' ? $sourceItemId : 'line') . '-part-' . $partId;
        $job = [
            'job_id' => $jobId,
            'status' => self::STATUS_PENDING,
            'source' => $source,
            'source_order_id' => $sourceOrderId,
            'external_order_id' => $sourceOrderId,
            'source_item_id' => $sourceItemId,
            'product_id' => $productId,
            'part_id' => $partId,
            'request_id' => $requestId,
            'retry_count' => 0,
            'error' => '',
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'status_sent_to_ovoko' => 2,
        ];
        $this->store_external_job($jobId, $job);

        return array_merge($response, ['ok' => true, 'queued' => true, 'reason' => 'queued', 'job_id' => $jobId, 'request_id' => $requestId]);
    }

    public function process_queue(array $options = []): array
    {
        $retryFailedOnly = !empty($options['retry_failed_only']);
        $result = [
            'ok' => true,
            'action_name' => $retryFailedOnly ? 'Retry failed Woo → Ovoko sale sync' : 'Process Woo → Ovoko sale queue',
            'endpoint' => '/crm/changePartStatus',
            'payload' => ['part_id' => '{ovoko_sale_sync_part_id}', 'status' => 2],
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'items' => [],
        ];

        if (!$this->worker_enabled(!empty($options['force']))) {
            $result['ok'] = false;
            $result['status'] = 'paused';
            $result['message'] = 'Woo → Ovoko sale worker is disabled because Auto cron is set to NIE.';
            return $result;
        }

        $jobs = $this->load_retryable_jobs($retryFailedOnly, self::WORKER_LIMIT);
        $externalJobs = $this->load_retryable_external_jobs($retryFailedOnly, max(0, self::WORKER_LIMIT - count($jobs)));
        if ($jobs === [] && $externalJobs === []) {
            $result['message'] = 'No pending/retryable Woo → Ovoko sale jobs.';
            return $result;
        }

        $saleSyncService = new OvokoAutoSyncDryRunService($this->integrationService);
        foreach ($jobs as $job) {
            $itemId = (int) ($job['order_item_id'] ?? 0);
            $orderId = (int) ($job['order_id'] ?? 0);
            $partId = (string) ($job['part_id'] ?? '');
            $requestId = (string) ($job['request_id'] ?? '');
            $source = (string) ($job['source'] ?? 'woo_order') ?: 'woo_order';
            $externalOrderId = (string) ($job['external_order_id'] ?? '');
            $row = ['source' => $source, 'order_item_id' => $itemId, 'order_id' => $orderId, 'external_order_id' => $externalOrderId, 'part_id' => $partId, 'status_sent_to_ovoko' => 2, 'status_sent' => 2, 'request_id' => $requestId];

            if ($itemId <= 0 || $orderId <= 0 || $partId === '' || !preg_match('/^\d+$/', $partId)) {
                $this->write_item_meta($itemId, [self::META_STATUS => self::STATUS_SKIPPED, self::META_ERROR => 'invalid_order_item_or_part_id']);
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'invalid_order_item_or_part_id'];
                continue;
            }

            $existingStatus = function_exists('wc_get_order_item_meta') ? (string) wc_get_order_item_meta($itemId, self::META_STATUS, true) : '';
            if ($existingStatus === self::STATUS_SUCCESS) {
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'already_success', 'http_status' => null, 'status_code' => '', 'error' => ''];
                continue;
            }

            $itemResult = $saleSyncService->mark_order_item_sold_in_ovoko($orderId, $itemId, [
                'mode' => $retryFailedOnly ? 'automatic_sale_queue_retry_worker' : 'automatic_sale_queue_worker',
                'read_before_after' => false,
            ]);
            $apiResponse = (array) ($itemResult['api_response'] ?? []);
            $retryable = empty($itemResult['ok']) && $this->is_retryable_response($apiResponse);
            $syncMetaAfter = (array) ($itemResult['sync_meta_after'] ?? []);
            $nextRetryCount = (int) ($syncMetaAfter[self::META_RETRY_COUNT] ?? $job['retry_count'] ?? 0);
            $status = !empty($itemResult['ok']) ? self::STATUS_SUCCESS : self::STATUS_FAILED;

            $result['processed']++;
            if (!empty($itemResult['ok'])) {
                $result['success']++;
            } else {
                $result['failed']++;
                $result['ok'] = false;
                $result['errors'][] = [
                    'order_item_id' => $itemId,
                    'part_id' => $partId,
                    'retryable' => $retryable,
                    'message' => (string) (((array) ($itemResult['errors'] ?? []))[0] ?? 'changePartStatus failed'),
                ];
            }
            $result['items'][] = $row + [
                'status' => $status,
                'retryable' => $retryable,
                'retry_count' => $nextRetryCount,
                'http_status' => $apiResponse['http_status'] ?? null,
                'status_code' => (string) ($apiResponse['status_code'] ?? ''),
                'shared_service' => 'OvokoAutoSyncDryRunService::mark_order_item_sold_in_ovoko',
            ];
        }

        foreach ($externalJobs as $job) {
            $row = [
                'source' => (string) ($job['source'] ?? 'ebay_order'),
                'job_id' => (string) ($job['job_id'] ?? ''),
                'order_id' => 0,
                'external_order_id' => (string) ($job['external_order_id'] ?? $job['source_order_id'] ?? ''),
                'source_item_id' => (string) ($job['source_item_id'] ?? ''),
                'product_id' => (int) ($job['product_id'] ?? 0),
                'part_id' => (string) ($job['part_id'] ?? ''),
                'status_sent_to_ovoko' => 2,
                'status_sent' => 2,
                'request_id' => (string) ($job['request_id'] ?? ''),
                'previous_stock_quantity' => $job['previous_stock_quantity'] ?? null,
                'new_stock_quantity' => $job['new_stock_quantity'] ?? null,
                'previous_stock_status' => (string) ($job['previous_stock_status'] ?? ''),
                'new_stock_status' => (string) ($job['new_stock_status'] ?? ''),
                'changed_by_user_id' => (int) ($job['changed_by_user_id'] ?? 0),
            ];

            if ($row['job_id'] === '' || $row['product_id'] <= 0 || $row['part_id'] === '' || !preg_match('/^\d+$/', $row['part_id'])) {
                $this->update_external_job_status($row['job_id'], self::STATUS_SKIPPED, 'invalid_external_sale_job');
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'invalid_external_sale_job', 'http_status' => null, 'status_code' => '', 'error' => 'invalid_external_sale_job'];
                continue;
            }

            if ((string) ($job['status'] ?? '') === self::STATUS_SUCCESS) {
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'already_success', 'http_status' => null, 'status_code' => '', 'error' => ''];
                continue;
            }

            $itemResult = $saleSyncService->mark_product_sold_in_ovoko($row['product_id'], $row['part_id'], [
                'mode' => $retryFailedOnly ? 'automatic_sale_queue_retry_worker' : 'automatic_sale_queue_worker',
                'source' => $row['source'],
                'source_order_id' => $row['external_order_id'],
                'source_item_id' => $row['source_item_id'],
                'request_id' => $row['request_id'],
            ]);
            $apiResponse = (array) ($itemResult['api_response'] ?? []);
            $retryable = empty($itemResult['ok']) && $this->is_retryable_response($apiResponse);
            $status = !empty($itemResult['ok']) ? self::STATUS_SUCCESS : self::STATUS_FAILED;
            $error = $status === self::STATUS_SUCCESS ? '' : (string) (((array) ($itemResult['errors'] ?? []))[0] ?? 'changePartStatus failed');
            $nextRetryCount = $status === self::STATUS_SUCCESS ? (int) ($job['retry_count'] ?? 0) : ((int) ($job['retry_count'] ?? 0) + 1);
            $this->update_external_job_status($row['job_id'], $status, $error, $nextRetryCount);

            $result['processed']++;
            if ($status === self::STATUS_SUCCESS) {
                $result['success']++;
            } else {
                $result['failed']++;
                $result['ok'] = false;
                $result['errors'][] = ['source' => $row['source'], 'external_order_id' => $row['external_order_id'], 'product_id' => $row['product_id'], 'part_id' => $row['part_id'], 'retryable' => $retryable, 'message' => $error];
            }
            $result['items'][] = $row + [
                'status' => $status,
                'retryable' => $retryable,
                'retry_count' => $nextRetryCount,
                'http_status' => $apiResponse['http_status'] ?? null,
                'status_code' => (string) ($apiResponse['status_code'] ?? ''),
                'error' => $error,
                'shared_service' => 'OvokoAutoSyncDryRunService::mark_product_sold_in_ovoko',
            ];
        }

        return $result;
    }

    public function status_counts(): array
    {
        global $wpdb;

        $counts = ['pending' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
        if (!isset($wpdb) || empty($wpdb->prefix)) {
            return $this->add_external_status_counts($counts);
        }

        $metaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metaTable));
        if ($tableExists !== $metaTable) {
            return $this->add_external_status_counts($counts);
        }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value, COUNT(*) AS total FROM {$metaTable} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS), ARRAY_A);
        if (!is_array($rows)) {
            return $this->add_external_status_counts($counts);
        }

        foreach ($rows as $row) {
            $status = (string) ($row['meta_value'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $this->add_external_status_counts($counts);
    }

    public function design_summary(): array
    {
        return [
            'queue_enabled_by_default' => false,
            'safe_order_hooks_prepared' => self::SAFE_ORDER_HOOKS,
            'meta_keys' => self::META_KEYS,
            'worker_endpoint' => '/crm/changePartStatus',
            'worker_payload' => ['part_id' => '{ovoko_sale_sync_part_id}', 'status' => 2],
            'shared_live_path' => 'OvokoAutoSyncDryRunService::mark_order_item_sold_in_ovoko for Woo order items; OvokoAutoSyncDryRunService::mark_product_sold_in_ovoko for eBay order and Woo manual stock product jobs.',
            'external_queue_storage' => self::EXTERNAL_JOBS_OPTION,
            'external_sources' => ['ebay_order', 'woo_manual_stock_change'],
            'idempotency' => 'Skip if order item ovoko_sale_sync_status is success; external eBay jobs are keyed by source + source_order_id + source_item_id + product_id + part_id; Woo manual stock change jobs are keyed by source + product_id + part_id + previous/new quantity/status and skipped after success.',
            'retry_policy' => 'Retry network/timeout/5xx/rate-limit failures only; do not retry missing part_id, already-success, invalid item, or validation errors.',
            'checkout_live_send' => false,
            'cancel_refund_policy' => 'Design only; do not automatically restore status in Ovoko without separate business decision.',
        ];
    }



    private function add_external_status_counts(array $counts): array
    {
        foreach ($this->external_jobs() as $job) {
            $status = (string) ($job['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        return $counts;
    }

    private function load_retryable_jobs(bool $failedOnly, int $limit): array
    {
        global $wpdb;
        if (!isset($wpdb) || empty($wpdb->prefix)) {
            return [];
        }

        $metaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $statusPlaceholders = $failedOnly ? '%s' : '%s,%s';
        $statuses = $failedOnly ? [self::STATUS_FAILED] : [self::STATUS_PENDING, self::STATUS_FAILED];
        $sql = "SELECT s.order_item_id,
                    s.meta_value AS status,
                    part.meta_value AS part_id,
                    req.meta_value AS request_id,
                    ord.meta_value AS order_id,
                    src.meta_value AS source,
                    ext.meta_value AS external_order_id,
                    prod.meta_value AS product_id,
                    retry.meta_value AS retry_count,
                    err.meta_value AS error
                FROM {$metaTable} s
                LEFT JOIN {$metaTable} part ON part.order_item_id = s.order_item_id AND part.meta_key = %s
                LEFT JOIN {$metaTable} req ON req.order_item_id = s.order_item_id AND req.meta_key = %s
                LEFT JOIN {$metaTable} ord ON ord.order_item_id = s.order_item_id AND ord.meta_key = %s
                LEFT JOIN {$metaTable} src ON src.order_item_id = s.order_item_id AND src.meta_key = %s
                LEFT JOIN {$metaTable} ext ON ext.order_item_id = s.order_item_id AND ext.meta_key = %s
                LEFT JOIN {$metaTable} prod ON prod.order_item_id = s.order_item_id AND prod.meta_key = %s
                LEFT JOIN {$metaTable} retry ON retry.order_item_id = s.order_item_id AND retry.meta_key = %s
                LEFT JOIN {$metaTable} err ON err.order_item_id = s.order_item_id AND err.meta_key = %s
                WHERE s.meta_key = %s AND s.meta_value IN ({$statusPlaceholders})
                ORDER BY s.order_item_id ASC
                LIMIT %d";
        $params = [self::META_PART_ID, self::META_REQUEST_ID, self::META_ORDER_ID, self::META_SOURCE, self::META_EXTERNAL_ORDER_ID, self::META_PRODUCT_ID, self::META_RETRY_COUNT, self::META_ERROR, self::META_STATUS];
        $params = array_merge($params, $statuses, [$limit]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $jobs = [];
        foreach ((array) $rows as $row) {
            $retryCount = max(0, (int) ($row['retry_count'] ?? 0));
            if ((string) ($row['status'] ?? '') === self::STATUS_FAILED && ($retryCount >= self::MAX_RETRY_COUNT || !$this->is_retryable_error_text((string) ($row['error'] ?? '')))) {
                continue;
            }
            $jobs[] = $row + ['retry_count' => $retryCount, 'source' => (string) ($row['source'] ?? '') ?: 'woo_order'];
        }

        return $jobs;
    }

    private function is_retryable_response(array $response): bool
    {
        $http = (int) ($response['http_status'] ?? 0);
        $message = strtolower((string) ($response['message'] ?? ''));
        if ($http === 0) {
            return true;
        }
        if ($http === 429 || $http >= 500) {
            return true;
        }

        return str_contains($message, 'timed out') || str_contains($message, 'timeout') || str_contains($message, 'request failed');
    }

    private function is_retryable_error_text(string $error): bool
    {
        $normalized = strtolower($error);
        if ($normalized === '') {
            return true;
        }

        foreach (['timeout', 'timed out', 'request failed', '5', 'rate', '429', 'temporar'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function write_item_meta(int $itemId, array $meta): void
    {
        if ($itemId <= 0 || !function_exists('wc_update_order_item_meta')) {
            return;
        }

        foreach ($meta as $key => $value) {
            wc_update_order_item_meta($itemId, (string) $key, is_scalar($value) ? (string) $value : wp_json_encode($value));
        }
    }

    private function queue_enabled(): bool
    {
        return (bool) get_option(OvokoBidirectionalSyncOrchestrator::ENABLED_OPTION, false);
    }

    private function worker_enabled(bool $force = false): bool
    {
        return $force || $this->queue_enabled();
    }



    private function resolve_item_product_id($item): int
    {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        if ($product && method_exists($product, 'get_id')) {
            return (int) $product->get_id();
        }
        return is_object($item) && method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
    }

    private function resolve_product_part_id(int $productId): string
    {
        $parentProductId = 0;
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($productId);
            $parentProductId = $product && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        }
        foreach ([$productId, $parentProductId] as $candidateProductId) {
            if ($candidateProductId <= 0) {
                continue;
            }
            foreach (['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'] as $key) {
                $value = (string) get_post_meta($candidateProductId, $key, true);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }
        return '';
    }


    private function read_product_stock_snapshot(int $productId): array
    {
        return [
            'stock_quantity' => $this->normalize_stock_quantity(get_post_meta($productId, '_stock', true)),
            'stock_status' => (string) get_post_meta($productId, '_stock_status', true),
        ];
    }

    private function normalize_stock_quantity($quantity): ?int
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }
        return is_numeric($quantity) ? (int) $quantity : null;
    }

    private function stock_changed_to_unavailable(array $previous, array $current): bool
    {
        $previousQuantity = $this->normalize_stock_quantity($previous['stock_quantity'] ?? null);
        $newQuantity = $this->normalize_stock_quantity($current['stock_quantity'] ?? null);
        $previousStatus = (string) ($previous['stock_status'] ?? '');
        $newStatus = (string) ($current['stock_status'] ?? '');

        $quantityDroppedToZero = $previousQuantity !== null && $previousQuantity > 0 && $newQuantity !== null && $newQuantity === 0;
        $statusChangedToOutOfStock = $previousStatus === 'instock' && $newStatus === 'outofstock';

        return $quantityDroppedToZero || $statusChangedToOutOfStock;
    }

    private function build_manual_stock_change_job_id(int $productId, string $partId, ?int $previousQuantity, ?int $newQuantity, string $previousStatus, string $newStatus): string
    {
        return 'manual-stock-' . md5(implode('|', [
            'woo_manual_stock_change',
            (string) $productId,
            $partId,
            $previousQuantity === null ? 'null' : (string) $previousQuantity,
            $newQuantity === null ? 'null' : (string) $newQuantity,
            $previousStatus,
            $newStatus,
        ]));
    }

    private function build_external_job_id(string $source, string $sourceOrderId, string $sourceItemId, int $productId, string $partId): string
    {
        return 'external-' . md5(implode('|', [$source, $sourceOrderId, $sourceItemId, (string) $productId, $partId]));
    }

    private function external_jobs(): array
    {
        $jobs = get_option(self::EXTERNAL_JOBS_OPTION, []);
        return is_array($jobs) ? array_filter($jobs, 'is_array') : [];
    }

    private function store_external_job(string $jobId, array $job): void
    {
        if ($jobId === '') {
            return;
        }
        $jobs = $this->external_jobs();
        $jobs[$jobId] = $job + ['job_id' => $jobId];
        if (count($jobs) > self::EXTERNAL_JOB_LIMIT) {
            uasort($jobs, static fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
            $jobs = array_slice($jobs, 0, self::EXTERNAL_JOB_LIMIT, true);
        }
        update_option(self::EXTERNAL_JOBS_OPTION, $jobs, false);
    }

    private function load_retryable_external_jobs(bool $failedOnly, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $jobs = [];
        foreach ($this->external_jobs() as $jobId => $job) {
            $status = (string) ($job['status'] ?? '');
            if ($failedOnly && $status !== self::STATUS_FAILED) {
                continue;
            }
            if (!$failedOnly && !in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
                continue;
            }
            $retryCount = max(0, (int) ($job['retry_count'] ?? 0));
            if ($status === self::STATUS_FAILED && ($retryCount >= self::MAX_RETRY_COUNT || !$this->is_retryable_error_text((string) ($job['error'] ?? '')))) {
                continue;
            }
            $jobs[] = $job + ['job_id' => (string) $jobId, 'retry_count' => $retryCount];
            if (count($jobs) >= $limit) {
                break;
            }
        }
        return $jobs;
    }

    private function update_external_job_status(string $jobId, string $status, string $error = '', ?int $retryCount = null): void
    {
        if ($jobId === '') {
            return;
        }
        $jobs = $this->external_jobs();
        if (!isset($jobs[$jobId]) || !is_array($jobs[$jobId])) {
            return;
        }
        $jobs[$jobId]['status'] = $status;
        $jobs[$jobId]['error'] = $error;
        $jobs[$jobId]['updated_at'] = gmdate('c');
        $jobs[$jobId]['attempted_at'] = gmdate('c');
        if ($status === self::STATUS_SUCCESS) {
            $jobs[$jobId]['success_at'] = gmdate('c');
        }
        if ($retryCount !== null) {
            $jobs[$jobId]['retry_count'] = max(0, $retryCount);
        }
        update_option(self::EXTERNAL_JOBS_OPTION, $jobs, false);
    }

    private function resolve_item_part_id($item): string
    {
        $product = is_object($item) && method_exists($item, 'get_product') ? $item->get_product() : null;
        $productId = $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $parentProductId = $product && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $keys = ['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'];

        foreach ([$productId, $parentProductId] as $candidateProductId) {
            if ($candidateProductId <= 0) {
                continue;
            }
            foreach ($keys as $key) {
                $value = (string) get_post_meta($candidateProductId, $key, true);
                if ($value !== '' && preg_match('/^\d+$/', $value)) {
                    return $value;
                }
            }
        }

        return '';
    }
}
