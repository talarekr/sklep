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
    public const META_RETRY_COUNT = 'retry_count';

    public const META_KEYS = [
        self::META_STATUS,
        self::META_ATTEMPTED_AT,
        self::META_SUCCESS_AT,
        self::META_ERROR,
        self::META_REQUEST_ID,
        self::META_PART_ID,
        self::META_ORDER_ID,
        self::META_RETRY_COUNT,
    ];

    public const SAFE_ORDER_HOOKS = [
        'woocommerce_payment_complete',
        'woocommerce_order_status_processing',
        'woocommerce_order_status_completed',
    ];

    private const MAX_RETRY_COUNT = 6;
    private const WORKER_LIMIT = 10;

    private OvokoIntegrationService $integrationService;

    public function __construct(OvokoIntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function hooks(): void
    {
        add_action('woocommerce_payment_complete', [$this, 'enqueue_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'enqueue_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [$this, 'enqueue_order'], 20, 1);
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
        if ($jobs === []) {
            $result['message'] = 'No pending/retryable Woo → Ovoko sale jobs.';
            return $result;
        }

        $saleSyncService = new OvokoAutoSyncDryRunService($this->integrationService);
        foreach ($jobs as $job) {
            $itemId = (int) ($job['order_item_id'] ?? 0);
            $orderId = (int) ($job['order_id'] ?? 0);
            $partId = (string) ($job['part_id'] ?? '');
            $requestId = (string) ($job['request_id'] ?? '');
            $row = ['order_item_id' => $itemId, 'order_id' => $orderId, 'part_id' => $partId, 'request_id' => $requestId];

            if ($itemId <= 0 || $orderId <= 0 || $partId === '' || !preg_match('/^\d+$/', $partId)) {
                $this->write_item_meta($itemId, [self::META_STATUS => self::STATUS_SKIPPED, self::META_ERROR => 'invalid_order_item_or_part_id']);
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'invalid_order_item_or_part_id'];
                continue;
            }

            $existingStatus = function_exists('wc_get_order_item_meta') ? (string) wc_get_order_item_meta($itemId, self::META_STATUS, true) : '';
            if ($existingStatus === self::STATUS_SUCCESS) {
                $result['skipped']++;
                $result['items'][] = $row + ['status' => self::STATUS_SKIPPED, 'reason' => 'already_success'];
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

        return $result;
    }

    public function status_counts(): array
    {
        global $wpdb;

        $counts = ['pending' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0];
        if (!isset($wpdb) || empty($wpdb->prefix)) {
            return $counts;
        }

        $metaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metaTable));
        if ($tableExists !== $metaTable) {
            return $counts;
        }

        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_value, COUNT(*) AS total FROM {$metaTable} WHERE meta_key = %s GROUP BY meta_value", self::META_STATUS), ARRAY_A);
        if (!is_array($rows)) {
            return $counts;
        }

        foreach ($rows as $row) {
            $status = (string) ($row['meta_value'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    public function design_summary(): array
    {
        return [
            'queue_enabled_by_default' => false,
            'safe_order_hooks_prepared' => self::SAFE_ORDER_HOOKS,
            'meta_keys' => self::META_KEYS,
            'worker_endpoint' => '/crm/changePartStatus',
            'worker_payload' => ['part_id' => '{ovoko_sale_sync_part_id}', 'status' => 2],
            'shared_live_path' => 'OvokoAutoSyncDryRunService::mark_order_item_sold_in_ovoko (also used by the confirmed manual single-order live probe)',
            'idempotency' => 'Skip if order item ovoko_sale_sync_status is success; keep one ovoko_sale_sync_request_id per order item.',
            'retry_policy' => 'Retry network/timeout/5xx/rate-limit failures only; do not retry missing part_id, already-success, invalid item, or validation errors.',
            'checkout_live_send' => false,
            'cancel_refund_policy' => 'Design only; do not automatically restore status in Ovoko without separate business decision.',
        ];
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
                    retry.meta_value AS retry_count,
                    err.meta_value AS error
                FROM {$metaTable} s
                LEFT JOIN {$metaTable} part ON part.order_item_id = s.order_item_id AND part.meta_key = %s
                LEFT JOIN {$metaTable} req ON req.order_item_id = s.order_item_id AND req.meta_key = %s
                LEFT JOIN {$metaTable} ord ON ord.order_item_id = s.order_item_id AND ord.meta_key = %s
                LEFT JOIN {$metaTable} retry ON retry.order_item_id = s.order_item_id AND retry.meta_key = %s
                LEFT JOIN {$metaTable} err ON err.order_item_id = s.order_item_id AND err.meta_key = %s
                WHERE s.meta_key = %s AND s.meta_value IN ({$statusPlaceholders})
                ORDER BY s.order_item_id ASC
                LIMIT %d";
        $params = [self::META_PART_ID, self::META_REQUEST_ID, self::META_ORDER_ID, self::META_RETRY_COUNT, self::META_ERROR, self::META_STATUS];
        $params = array_merge($params, $statuses, [$limit]);
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        $jobs = [];
        foreach ((array) $rows as $row) {
            $retryCount = max(0, (int) ($row['retry_count'] ?? 0));
            if ((string) ($row['status'] ?? '') === self::STATUS_FAILED && ($retryCount >= self::MAX_RETRY_COUNT || !$this->is_retryable_error_text((string) ($row['error'] ?? '')))) {
                continue;
            }
            $jobs[] = $row + ['retry_count' => $retryCount];
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
