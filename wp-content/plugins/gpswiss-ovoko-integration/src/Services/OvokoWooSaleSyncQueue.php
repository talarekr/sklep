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

    /** @var string[] */
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

    private OvokoIntegrationService $integrationService;

    public function __construct(OvokoIntegrationService $integrationService)
    {
        $this->integrationService = $integrationService;
    }

    public function hooks(): void
    {
        // Intentionally not enabled by default: queueing can be switched on only after business approval.
        if (!defined('GPSWISS_OVOKO_ENABLE_SALE_QUEUE_HOOKS') || !GPSWISS_OVOKO_ENABLE_SALE_QUEUE_HOOKS) {
            return;
        }

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
            if (function_exists('wc_update_order_item_meta')) {
                wc_update_order_item_meta($itemId, self::META_STATUS, $partId !== '' ? self::STATUS_PENDING : self::STATUS_FAILED);
                wc_update_order_item_meta($itemId, self::META_PART_ID, $partId);
                wc_update_order_item_meta($itemId, self::META_ORDER_ID, (string) $orderId);
                wc_update_order_item_meta($itemId, self::META_REQUEST_ID, $requestId);
                wc_update_order_item_meta($itemId, self::META_RETRY_COUNT, (string) max(0, (int) (function_exists('wc_get_order_item_meta') ? wc_get_order_item_meta($itemId, self::META_RETRY_COUNT, true) : 0)));
                if ($partId === '') {
                    wc_update_order_item_meta($itemId, self::META_ERROR, 'missing_or_invalid_ovoko_part_id_product_meta');
                }
            }

            if ($partId === '') {
                $result['skipped']++;
                continue;
            }
            $result['queued']++;
        }

        $result['ok'] = $result['errors'] === [];
        return $result;
    }

    public function status_counts(): array
    {
        global $wpdb;

        $counts = [
            'pending' => 0,
            'success' => 0,
            'failed' => 0,
        ];

        if (!isset($wpdb) || empty($wpdb->prefix)) {
            return $counts;
        }

        $metaTable = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metaTable));
        if ($tableExists !== $metaTable) {
            return $counts;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value, COUNT(*) AS total FROM {$metaTable} WHERE meta_key = %s GROUP BY meta_value",
                self::META_STATUS
            ),
            ARRAY_A
        );

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
            'idempotency' => 'Skip if order item ovoko_sale_sync_status is success; keep one ovoko_sale_sync_request_id per order item.',
            'retry_policy' => 'Retry network/5xx/rate-limit only; do not retry missing part_id or already-success items.',
            'checkout_live_send' => false,
            'cancel_refund_policy' => 'Design only; do not automatically restore status 0 or 3 without separate business decision.',
        ];
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
