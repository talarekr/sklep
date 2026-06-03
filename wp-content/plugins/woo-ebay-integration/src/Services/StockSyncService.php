<?php

namespace WEI\Services;

use WEI\Plugin;
use WEI\Repositories\MappingRepository;

class StockSyncService
{
    public const CRON_HOOK = 'wei_ebay_stock_sync_cron';
    public const LAST_RUN_OPTION = 'wei_ebay_stock_sync_last_run';
    public const LAST_SUCCESSFUL_SYNC_OPTION = 'wei_ebay_stock_sync_last_successful_timestamp';
    public const EVENT_QUEUE_OPTION = 'wei_ebay_stock_sync_event_queue';
    public const PROCESSED_EVENTS_OPTION = 'wei_ebay_stock_sync_processed_event_ids';
    public const LOCK_TRANSIENT = 'wei_ebay_stock_sync_lock';
    private const LOCK_TTL = 600;

    /** @var array<int,string> */
    private const ACTION_HEADERS = [
        'event_id',
        'event_reason',
        'direction',
        'product_id',
        'sku',
        'listing_id',
        'offer_id',
        'old_woo_stock',
        'new_woo_stock',
        'old_woo_status',
        'new_woo_status',
        'ebay_state_before',
        'ebay_state_after',
        'action',
        'dry_run',
        'result',
        'error_message',
        'timestamp',
    ];

    /** @var array<int,array<string,mixed>> */
    private array $stockSnapshotsBeforeSave = [];

    public function __construct(private EbayClient $client, private MappingRepository $repo, private Logger $logger)
    {
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_scheduled']);
        add_action(self::CRON_HOOK, [$this, 'run_cron']);
        add_action('woocommerce_before_product_object_save', [$this, 'capture_product_stock_before_save'], 5, 1);
        add_action('woocommerce_after_product_object_save', [$this, 'enqueue_product_stock_event_if_unavailable'], 20, 1);
        foreach (['woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed'] as $hook) {
            add_action($hook, [$this, 'enqueue_order_stock_events'], 20, 1);
        }
    }

    public function cron_schedules(array $schedules): array
    {
        $schedules['wei_every_5_minutes'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'WEI every 5 minutes'];
        $schedules['wei_every_15_minutes'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'WEI every 15 minutes'];
        return $schedules;
    }

    public function ensure_scheduled(): void
    {
        $settings = $this->settings();
        $interval = $this->cron_interval_key((string) ($settings['stock_sync_cron_interval'] ?? 'every_15_minutes'));
        $next = wp_next_scheduled(self::CRON_HOOK);
        if ($next === false) {
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
            return;
        }

        $event = wp_get_scheduled_event(self::CRON_HOOK);
        if (is_object($event) && (string) ($event->schedule ?? '') !== $interval) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event(time() + 60, $interval, self::CRON_HOOK);
        }
    }

    public function run_cron(): array
    {
        return $this->run(null);
    }

    public function status(): array
    {
        $settings = $this->settings();
        $lastRun = get_option(self::LAST_RUN_OPTION, []);
        $lastRun = is_array($lastRun) ? $lastRun : [];
        return [
            'sync_enabled' => !empty($settings['stock_sync_enabled']),
            'woo_to_ebay_enabled' => !empty($settings['stock_sync_woo_to_ebay_enabled']),
            'ebay_to_woo_enabled' => !empty($settings['stock_sync_ebay_to_woo_enabled']),
            'dry_run' => !empty($settings['stock_sync_dry_run']),
            'last_run' => $lastRun,
            'last_run_time' => (string) ($lastRun['finished_at'] ?? $lastRun['started_at'] ?? ''),
            'last_run_result' => (string) ($lastRun['result'] ?? 'never'),
            'woo_to_ebay_actions_last_run' => (int) ($lastRun['woo_to_ebay_actions'] ?? 0),
            'ebay_to_woo_actions_last_run' => (int) ($lastRun['ebay_to_woo_actions'] ?? 0),
            'errors_last_run' => (int) ($lastRun['errors'] ?? 0),
            'next_scheduled_run' => wp_next_scheduled(self::CRON_HOOK) ?: 0,
            'lock_status' => get_transient(self::LOCK_TRANSIENT) ? 'locked' : 'unlocked',
            'last_successful_sync_timestamp' => (string) get_option(self::LAST_SUCCESSFUL_SYNC_OPTION, ''),
            'queued_events' => count($this->queued_events()),
            'processed_event_ids' => count($this->processed_event_ids()),
            'reports' => $this->report_paths(),
        ];
    }

    public function capture_product_stock_before_save($product): void
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return;
        }
        $this->stockSnapshotsBeforeSave[$productId] = $this->read_product_stock_snapshot($productId);
    }

    public function enqueue_product_stock_event_if_unavailable($product): array
    {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return ['queued' => false, 'reason' => 'missing_product'];
        }
        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return ['queued' => false, 'reason' => 'missing_product_id'];
        }

        $previous = $this->stockSnapshotsBeforeSave[$productId] ?? $this->read_product_stock_snapshot($productId);
        unset($this->stockSnapshotsBeforeSave[$productId]);
        $current = $this->read_product_stock_snapshot($productId, $product);

        $reason = '';
        if ((string) ($current['stock_status'] ?? '') === 'outofstock' && (string) ($previous['stock_status'] ?? '') !== 'outofstock') {
            $reason = 'woo_outofstock';
        } elseif (($current['stock_quantity'] ?? null) !== null && (int) $current['stock_quantity'] <= 0 && (($previous['stock_quantity'] ?? null) === null || (int) $previous['stock_quantity'] > 0)) {
            $reason = 'woo_stock_zero';
        }

        if ($reason === '') {
            return ['queued' => false, 'reason' => 'stock_not_changed_to_unavailable'];
        }

        return $this->enqueue_stock_event($productId, (string) ($current['sku'] ?? ''), $reason, [
            'previous_stock_quantity' => $previous['stock_quantity'] ?? null,
            'new_stock_quantity' => $current['stock_quantity'] ?? null,
            'previous_stock_status' => (string) ($previous['stock_status'] ?? ''),
            'new_stock_status' => (string) ($current['stock_status'] ?? ''),
        ]);
    }

    public function enqueue_order_stock_events($orderId): array
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return ['queued' => 0, 'reason' => 'missing_order'];
        }

        $queued = 0;
        foreach ((array) $order->get_items() as $item) {
            if (!is_object($item)) {
                continue;
            }
            $productId = method_exists($item, 'get_variation_id') && (int) $item->get_variation_id() > 0 ? (int) $item->get_variation_id() : (method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0);
            if ($productId <= 0) {
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            $sku = $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : 'GPSW-' . $productId;
            $result = $this->enqueue_stock_event($productId, $sku, 'woo_order_sold', ['woo_order_id' => (string) $orderId]);
            if (!empty($result['queued'])) {
                $queued++;
            }
        }

        return ['queued' => $queued, 'reason' => 'woo_order_sold'];
    }

    public function enqueue_stock_event(int $productId, string $sku, string $reason, array $context = []): array
    {
        $productId = max(0, $productId);
        $sku = trim($sku) !== '' ? trim($sku) : ($productId > 0 ? 'GPSW-' . $productId : '');
        if ($productId <= 0 && $sku === '') {
            return ['queued' => false, 'reason' => 'missing_product_or_sku'];
        }
        if (!in_array($reason, ['woo_stock_zero', 'woo_order_sold', 'woo_outofstock'], true)) {
            return ['queued' => false, 'reason' => 'unsupported_event_reason'];
        }

        $event = [
            'id' => hash('sha256', implode('|', [$reason, $productId, $sku, (string) ($context['woo_order_id'] ?? ''), gmdate('YmdHi')])) ,
            'reason' => $reason,
            'product_id' => $productId,
            'sku' => $sku,
            'context' => $context,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
        $queue = $this->queued_events();
        $queue[$event['id']] = $event;
        update_option(self::EVENT_QUEUE_OPTION, array_slice($queue, -500, null, true), false);
        return ['queued' => true, 'event_id' => $event['id'], 'reason' => $reason];
    }

    public function run(?bool $forceDryRun = null): array
    {
        $settings = $this->settings();
        $dryRun = $forceDryRun !== null ? $forceDryRun : !empty($settings['stock_sync_dry_run']);
        $summary = $this->empty_summary($dryRun);
        $summary['fallback_scan_limit'] = (int) ($settings['stock_sync_fallback_scan_limit'] ?? 10);

        if (!$this->acquire_lock()) {
            $summary['result'] = 'lock_skip';
            $summary['skipped']++;
            $summary['finished_at'] = gmdate('Y-m-d H:i:s');
            $this->append_error(['action' => 'lock_skip', 'result' => 'skipped', 'error_message' => 'Previous stock sync run is still active.']);
            $this->persist_summary($summary);
            $this->logger->warning('WEI_STOCK_SYNC_LOCK_SKIP', $summary);
            return $summary;
        }

        $summary['lock_used'] = true;
        try {
            if (empty($settings['stock_sync_enabled'])) {
                $summary['result'] = 'disabled';
                $summary['skipped']++;
            } else {
                if (!empty($settings['stock_sync_woo_to_ebay_enabled'])) {
                    $woo = $this->sync_woo_to_ebay($settings, $dryRun, $summary);
                    $summary = $woo;
                }
                if (!empty($settings['stock_sync_ebay_to_woo_enabled'])) {
                    $ebay = $this->sync_ebay_to_woo($settings, $dryRun, $summary);
                    $summary = $ebay;
                }
                $summary['result'] = ((int) $summary['errors'] > 0) ? 'completed_with_errors' : 'completed';
            }
        } catch (\Throwable $throwable) {
            $summary['errors']++;
            $summary['result'] = 'error';
            $this->append_error(['action' => 'run', 'result' => 'error', 'error_message' => $throwable->getMessage()]);
            $this->logger->error('WEI_STOCK_SYNC_ERROR', ['error' => $throwable->getMessage()]);
        } finally {
            $summary['finished_at'] = gmdate('Y-m-d H:i:s');
            if (!$dryRun && in_array((string) $summary['result'], ['completed', 'completed_with_errors'], true) && (int) $summary['errors'] === 0) {
                update_option(self::LAST_SUCCESSFUL_SYNC_OPTION, $summary['finished_at'], false);
            }
            $this->persist_summary($summary);
            $this->release_lock();
        }

        return $summary;
    }

    public function product_diagnostics(string $productOrSku): array
    {
        $productOrSku = trim($productOrSku);
        $productId = $this->product_id_from_sku($productOrSku);
        if ($productId <= 0 && ctype_digit($productOrSku)) {
            $productId = (int) $productOrSku;
        }
        $product = $productId > 0 && function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $sku = $productId > 0 ? 'GPSW-' . $productId : $productOrSku;
        $map = $sku !== '' ? $this->repo->find_by_sku($sku) : null;
        $stock = $product && method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
        $status = $product && method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
        $purchasable = $product && method_exists($product, 'is_purchasable') ? (bool) $product->is_purchasable() : false;
        $soldAt = $productId > 0 ? (string) get_post_meta($productId, '_wei_ebay_sold_at', true) : '';
        $listingStatus = $map ? (string) ($map['status'] ?? '') : '';
        $wouldToEbay = $productId > 0 && $map && $this->has_active_listing($map) && ($soldAt !== '' || $status === 'outofstock' || ($stock !== null && (int) $stock <= 0));
        $wouldToWoo = $productId > 0 && $map && $this->has_active_listing($map);

        return [
            'product_id' => $productId,
            'woo_stock_quantity' => $stock,
            'woo_stock_status' => $status,
            'purchasable' => $purchasable ? 'yes' : 'no',
            'sku' => $sku,
            'offer_id' => (string) ($map['remote_offer_id'] ?? get_post_meta($productId, '_wei_ebay_offer_id', true)),
            'listing_id' => (string) ($map['remote_listing_id'] ?? get_post_meta($productId, '_wei_ebay_listing_id', true)),
            'local_ebay_listing_status' => $listingStatus,
            'last_stock_sync_source' => $productId > 0 ? (string) get_post_meta($productId, '_wei_ebay_stock_sync_source', true) : '',
            'last_stock_sync_action' => $productId > 0 ? (string) get_post_meta($productId, '_wei_ebay_last_stock_sync_action', true) : '',
            'would_sync_to_ebay' => $wouldToEbay ? 'yes' : 'no',
            'would_sync_to_woo' => $wouldToWoo ? 'yes' : 'no',
        ];
    }

    private function sync_woo_to_ebay(array $settings, bool $dryRun, array $summary): array
    {
        $processedIds = $this->processed_event_ids();
        $queue = $this->queued_events();
        $events = array_values(array_filter($queue, static fn($event): bool => is_array($event) && empty($processedIds[(string) ($event['id'] ?? '')])));
        $events = array_merge($events, $this->fallback_stock_events((int) ($settings['stock_sync_fallback_scan_limit'] ?? 10)));
        $actionsProcessed = 0;

        foreach ($events as $event) {
            $summary['woo_to_ebay_checked']++;
            if ($actionsProcessed >= (int) $settings['stock_sync_safety_limit']) {
                $summary['skipped']++;
                break;
            }

            $eventId = (string) ($event['id'] ?? '');
            $reason = (string) ($event['reason'] ?? 'fallback_active_listing_scan');
            $productId = (int) ($event['product_id'] ?? 0);
            $sku = trim((string) ($event['sku'] ?? ''));
            $map = $sku !== '' ? $this->repo->find_by_sku($sku) : null;
            if (!$map && $productId > 0) {
                $map = $this->repo->find_by_product($productId);
            }
            if (!$map && $productId > 0) {
                $map = $this->map_from_product_meta($productId, $sku);
            }
            if (!$map || !$this->has_active_listing($map)) {
                $summary['skipped']++;
                $this->mark_event_processed($eventId, $processedIds, $queue, $dryRun, 'no_active_ebay_listing');
                continue;
            }

            $productId = (int) ($map['woo_variation_id'] ?: $map['woo_product_id'] ?: $productId);
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            if (!$product) {
                $summary['skipped']++;
                continue;
            }
            $stock = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
            $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
            $soldMeta = $this->has_local_sold_meta($productId, (int) ($map['woo_product_id'] ?? 0));
            if (!$soldMeta && $stockStatus !== 'outofstock' && !($stock !== null && (int) $stock <= 0)) {
                $summary['skipped']++;
                $this->mark_event_processed($eventId, $processedIds, $queue, $dryRun, 'local_stock_not_unavailable');
                continue;
            }

            $action = (string) ($settings['stock_sync_woo_zero_action'] ?? 'end_listing');
            $row = array_merge($this->base_action_row('woo_to_ebay', $map, $productId, $stock, $stockStatus, $dryRun, $eventId, $reason), [
                'new_woo_stock' => $stock,
                'new_woo_status' => $stockStatus,
                'ebay_state_before' => 'active',
                'ebay_state_after' => $action === 'set_quantity_zero' ? 'quantity_0' : 'ended_or_unavailable',
                'action' => $action === 'set_quantity_zero' ? 'set_ebay_offer_quantity_zero' : 'end_ebay_listing_or_safe_equivalent',
            ]);
            if ($dryRun) {
                $row['result'] = 'dry_run';
                $this->append_action($row);
                $summary['woo_to_ebay_actions']++;
                $actionsProcessed++;
                continue;
            }

            $fresh = function_exists('wc_get_product') ? wc_get_product($productId) : $product;
            $freshStock = $fresh && method_exists($fresh, 'get_stock_quantity') ? $fresh->get_stock_quantity() : $stock;
            $freshStatus = $fresh && method_exists($fresh, 'get_stock_status') ? (string) $fresh->get_stock_status() : $stockStatus;
            if ($freshStatus !== 'outofstock' && !($freshStock !== null && (int) $freshStock <= 0) && !$soldMeta) {
                $row['result'] = 'skipped';
                $row['error_message'] = 'local_stock_recheck_not_zero';
                $this->append_action($row);
                $summary['skipped']++;
                continue;
            }

            $res = $this->make_ebay_listing_unavailable($map, $productId, $action);
            if (is_wp_error($res)) {
                $row['result'] = 'error';
                $row['error_message'] = $res->get_error_message();
                if ($this->is_retryable_identifier_error($res)) {
                    $row['error_message'] .= ' [retryable_after_id_based_stock_sync_fix]';
                }
                $summary['errors']++;
                $this->append_error($row);
            } else {
                $row['action'] = (string) ($res['action'] ?? $row['action']);
                $row['ebay_state_after'] = (string) ($res['ebay_state_after'] ?? $row['ebay_state_after']);
                $row['result'] = 'success';
                $summary['woo_to_ebay_actions']++;
                $actionsProcessed++;
                update_post_meta($productId, '_wei_ebay_last_stock_sync_action', (string) $row['action']);
                update_post_meta($productId, '_wei_ebay_stock_sync_source', 'woo');
                update_post_meta($productId, '_wei_ebay_listing_status', 'ended');
                $this->repo->upsert(array_merge($map, ['status' => 'ended', 'last_sync_at' => gmdate('Y-m-d H:i:s')]));
                $this->mark_event_processed($eventId, $processedIds, $queue, $dryRun, 'success');
            }
            $this->append_action($row);
        }

        $this->persist_event_state($queue, $processedIds, $dryRun);
        return $summary;
    }

    private function make_ebay_listing_unavailable(array $map, int $productId, string $requestedAction): array|\WP_Error
    {
        $offerId = trim((string) ($map['remote_offer_id'] ?? ''));
        $listingId = trim((string) ($map['remote_listing_id'] ?? ''));
        $sku = trim((string) ($map['sku'] ?? ''));
        $context = [
            'stage' => 'stock_sync_woo_to_ebay_make_unavailable',
            'product_id' => $productId,
            'sku' => $sku,
            'offer_id' => $offerId,
            'listing_id' => $listingId,
        ];

        if ($requestedAction !== 'set_quantity_zero' && $listingId !== '') {
            $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'end_fixed_price_item_by_listing_id', 'listing_id' => $listingId, 'offer_id' => $offerId, 'quantity' => 0, 'auth' => '[REDACTED]']);
            $res = $this->client->end_fixed_price_item_by_listing_id($listingId, 'NotAvailable', array_merge($context, ['stage' => 'stock_sync_end_listing_by_listing_id']));
            if (!is_wp_error($res)) {
                return ['action' => 'end_ebay_listing_by_listing_id', 'ebay_state_after' => 'ended_or_unavailable', 'response' => $res];
            }

            if ($offerId === '' || !$this->should_fallback_after_end_listing_error($res)) {
                return $res;
            }
            $this->logger->warning('WEI_STOCK_SYNC_EBAY_ID_FALLBACK', ['from_api' => 'end_fixed_price_item_by_listing_id', 'to_api' => 'bulk_update_price_quantity', 'listing_id' => $listingId, 'offer_id' => $offerId, 'error' => $res->get_error_message(), 'auth' => '[REDACTED]']);
        }

        if ($offerId !== '') {
            $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'bulk_update_price_quantity', 'offer_id' => $offerId, 'listing_id' => $listingId, 'quantity' => 0, 'auth' => '[REDACTED]']);
            $res = $this->client->bulk_update_price_quantity([[
                'offerId' => $offerId,
                'shipToLocationAvailability' => ['quantity' => 0],
            ]], array_merge($context, ['stage' => 'stock_sync_zero_quantity_by_offer_id']));
            if (!is_wp_error($res)) {
                return ['action' => 'set_ebay_offer_quantity_zero_by_offer_id', 'ebay_state_after' => 'quantity_0', 'response' => $res];
            }

            if ($listingId !== '' && $this->is_retryable_identifier_error($res)) {
                $this->logger->warning('WEI_STOCK_SYNC_EBAY_ID_FALLBACK', ['from_api' => 'bulk_update_price_quantity', 'to_api' => 'end_fixed_price_item_by_listing_id', 'listing_id' => $listingId, 'offer_id' => $offerId, 'error' => $res->get_error_message(), 'auth' => '[REDACTED]']);
                $fallback = $this->client->end_fixed_price_item_by_listing_id($listingId, 'NotAvailable', array_merge($context, ['stage' => 'stock_sync_invalid_sku_fallback_end_by_listing_id']));
                if (!is_wp_error($fallback)) {
                    return ['action' => 'end_ebay_listing_by_listing_id_after_invalid_sku', 'ebay_state_after' => 'ended_or_unavailable', 'response' => $fallback];
                }
            }

            if ($this->is_retryable_identifier_error($res)) {
                $this->logger->warning('WEI_STOCK_SYNC_EBAY_ID_FALLBACK', ['from_api' => 'bulk_update_price_quantity', 'to_api' => 'delete_offer', 'listing_id' => $listingId, 'offer_id' => $offerId, 'error' => $res->get_error_message(), 'auth' => '[REDACTED]']);
                $fallback = $this->client->delete_offer($offerId, array_merge($context, ['stage' => 'stock_sync_invalid_sku_fallback_delete_offer']));
                if (!is_wp_error($fallback)) {
                    return ['action' => 'make_ebay_offer_unavailable_by_offer_id_after_invalid_sku', 'ebay_state_after' => 'ended_or_unavailable', 'response' => $fallback];
                }
            }

            return $res;
        }

        if ($listingId !== '') {
            $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'end_fixed_price_item_by_listing_id', 'listing_id' => $listingId, 'offer_id' => $offerId, 'quantity' => 0, 'auth' => '[REDACTED]']);
            $res = $this->client->end_fixed_price_item_by_listing_id($listingId, 'NotAvailable', array_merge($context, ['stage' => 'stock_sync_end_listing_by_listing_id_without_offer_id']));
            if (!is_wp_error($res)) {
                return ['action' => 'end_ebay_listing_by_listing_id', 'ebay_state_after' => 'ended_or_unavailable', 'response' => $res];
            }
            return $res;
        }

        return new \WP_Error('wei_ebay_stock_sync_missing_remote_id', 'Cannot make eBay listing unavailable without offer_id or listing_id');
    }

    private function should_fallback_after_end_listing_error(\WP_Error $error): bool
    {
        $message = strtolower($error->get_error_message());
        return str_contains($message, 'not found')
            || str_contains($message, 'already ended')
            || str_contains($message, 'cannot be ended')
            || str_contains($message, 'invalid');
    }

    private function is_retryable_identifier_error(\WP_Error $error): bool
    {
        $message = strtolower($error->get_error_message());
        return str_contains($message, 'invalid value for a sku')
            || (str_contains($message, 'sku') && str_contains($message, 'alphanumeric'))
            || (str_contains($message, 'sku') && str_contains($message, 'must not exceed 50'));
    }

    private function sync_ebay_to_woo(array $settings, bool $dryRun, array $summary): array
    {
        $since = (string) get_option(self::LAST_SUCCESSFUL_SYNC_OPTION, '');
        $query = ['limit' => min(50, max(1, (int) $settings['stock_sync_safety_limit']))];
        if ($since !== '') {
            $query['filter'] = 'creationdate:[' . gmdate('Y-m-d\\TH:i:s.000\\Z', strtotime($since) ?: (time() - DAY_IN_SECONDS)) . '..]';
        }
        $orders = $this->client->get_orders($query);
        $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'get_orders', 'since' => $since, 'auth' => '[REDACTED]']);
        if (is_wp_error($orders)) {
            $summary['errors']++;
            $this->append_error(['direction' => 'ebay_to_woo', 'action' => 'fetch_ebay_orders', 'result' => 'error', 'error_message' => $orders->get_error_message()]);
            return $summary;
        }

        $processedIds = $this->processed_event_ids();
        $actionsProcessed = 0;
        foreach ($this->sold_order_lines(is_array($orders) ? $orders : []) as $line) {
            $summary['ebay_to_woo_checked']++;
            if ($actionsProcessed >= (int) $settings['stock_sync_safety_limit']) {
                $summary['skipped']++;
                break;
            }
            $eventId = (string) ($line['event_id'] ?? '');
            if ($eventId !== '' && !empty($processedIds[$eventId])) {
                $summary['skipped']++;
                continue;
            }
            $sku = trim((string) ($line['sku'] ?? ''));
            $listingId = trim((string) ($line['listing_id'] ?? ''));
            $map = $this->resolve_mapping_for_ebay_sale($sku, $listingId);
            $productId = $map ? (int) ($map['woo_variation_id'] ?: $map['woo_product_id']) : $this->product_id_from_sku($sku);
            if ($productId <= 0) {
                $summary['skipped']++;
                $this->append_action(['event_id' => $eventId, 'event_reason' => 'ebay_order_sold', 'direction' => 'ebay_to_woo', 'product_id' => '', 'sku' => $sku, 'listing_id' => $listingId, 'action' => 'skip_unknown_sku', 'dry_run' => $dryRun ? 'yes' : 'no', 'result' => 'skipped', 'error_message' => 'unknown_sku', 'timestamp' => gmdate('Y-m-d H:i:s')]);
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            if (!$product) {
                $summary['skipped']++;
                continue;
            }
            $oldStock = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
            $oldStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
            $map = $map ?: ['woo_product_id' => $productId, 'woo_variation_id' => null, 'sku' => $sku !== '' ? $sku : 'GPSW-' . $productId, 'remote_listing_id' => $listingId, 'remote_offer_id' => ''];
            $row = array_merge($this->base_action_row('ebay_to_woo', $map, $productId, $oldStock, $oldStatus, $dryRun, $eventId, 'ebay_order_sold'), [
                'new_woo_stock' => 0,
                'new_woo_status' => 'outofstock',
                'ebay_state_before' => 'sold',
                'ebay_state_after' => 'sold',
                'action' => 'set_woo_stock_zero_outofstock_mark_sold_enqueue_marketplace_cleanup',
            ]);
            if ($dryRun) {
                $row['result'] = 'dry_run';
                $summary['ebay_to_woo_actions']++;
                $actionsProcessed++;
                $this->append_action($row);
                continue;
            }

            if (function_exists('wc_update_product_stock')) {
                wc_update_product_stock($product, 0, 'set');
            } elseif (method_exists($product, 'set_stock_quantity')) {
                $product->set_stock_quantity(0);
            }
            if (method_exists($product, 'set_stock_status')) {
                $product->set_stock_status('outofstock');
            }
            if (method_exists($product, 'save')) {
                $product->save();
            }
            update_post_meta($productId, '_wei_ebay_sold_at', gmdate('Y-m-d H:i:s'));
            update_post_meta($productId, '_wei_ebay_sold_order_id', (string) ($line['order_id'] ?? ''));
            update_post_meta($productId, '_wei_ebay_stock_sync_source', 'ebay');
            update_post_meta($productId, '_wei_ebay_last_stock_sync_action', (string) $row['action']);
            update_post_meta($productId, '_wei_ebay_listing_status', 'sold');
            update_post_meta($productId, '_wei_marketplace_cleanup_needed', 'yes');
            do_action('wei_marketplace_cleanup_requested', $productId, ['source' => 'ebay', 'order_id' => (string) ($line['order_id'] ?? ''), 'listing_id' => $listingId]);
            $this->repo->upsert(array_merge($map, ['status' => 'sold', 'last_sync_at' => gmdate('Y-m-d H:i:s')]));
            if ($eventId !== '') {
                $processedIds[$eventId] = gmdate('Y-m-d H:i:s');
            }
            $row['result'] = 'success';
            $summary['ebay_to_woo_actions']++;
            $actionsProcessed++;
            $this->append_action($row);
        }

        if (!$dryRun) {
            update_option(self::PROCESSED_EVENTS_OPTION, array_slice($processedIds, -1000, null, true), false);
        }

        return $summary;
    }

    private function sold_order_lines(array $orders): array
    {
        $rows = [];
        $ordersList = is_array($orders['orders'] ?? null) ? $orders['orders'] : [];
        foreach ($ordersList as $order) {
            if (!is_array($order)) {
                continue;
            }
            foreach ((array) ($order['lineItems'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $sku = trim((string) ($line['sku'] ?? $line['inventoryItemSku'] ?? ''));
                if ($sku === '' && is_array($line['lineItemFulfillmentInstructions'] ?? null)) {
                    $sku = trim((string) ($line['lineItemFulfillmentInstructions']['sku'] ?? $line['lineItemFulfillmentInstructions']['inventoryItemSku'] ?? ''));
                }
                $listingId = trim((string) ($line['legacyItemId'] ?? $line['itemId'] ?? $line['listingId'] ?? ''));
                $qty = (int) ($line['quantity'] ?? 1);
                if (($sku !== '' || $listingId !== '') && $qty > 0) {
                    $orderId = (string) ($order['orderId'] ?? '');
                    $lineId = (string) ($line['lineItemId'] ?? $line['legacyItemId'] ?? $listingId);
                    $rows[] = [
                        'sku' => $sku,
                        'order_id' => $orderId,
                        'listing_id' => $listingId,
                        'event_id' => hash('sha256', 'ebay_order_sold|' . $orderId . '|' . $lineId . '|' . $sku . '|' . $listingId),
                    ];
                }
            }
        }
        return $rows;
    }

    private function product_id_from_sku(string $sku): int
    {
        return preg_match('/^GPSW-(\d+)$/', trim($sku), $m) ? (int) $m[1] : 0;
    }

    private function has_active_listing(array $map): bool
    {
        $status = strtolower((string) ($map['status'] ?? 'active'));
        return in_array($status, ['active', 'published'], true)
            && (trim((string) ($map['remote_offer_id'] ?? '')) !== '' || trim((string) ($map['remote_listing_id'] ?? '')) !== '');
    }

    private function has_local_sold_meta(int ...$productIds): bool
    {
        foreach (array_unique($productIds) as $productId) {
            foreach (['_wei_ebay_sold_at', '_wei_ebay_listing_status', '_wei_ebay_availability', '_sold', 'is_sold', '_availability'] as $key) {
                $value = strtolower(trim((string) get_post_meta($productId, $key, true)));
                if ($value !== '' && in_array($value, ['sold', 'ended', 'unavailable', 'outofstock', 'out_of_stock', '1', 'yes', 'true'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function read_product_stock_snapshot(int $productId, $product = null): array
    {
        $product = $product ?: (function_exists('wc_get_product') ? wc_get_product($productId) : null);
        $stock = $product && method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity('edit') : get_post_meta($productId, '_stock', true);
        $status = $product && method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status('edit') : (string) get_post_meta($productId, '_stock_status', true);
        $sku = $product && method_exists($product, 'get_sku') ? (string) $product->get_sku('edit') : '';
        return [
            'stock_quantity' => $stock === '' ? null : $stock,
            'stock_status' => $status,
            'sku' => trim($sku) !== '' ? $sku : 'GPSW-' . $productId,
        ];
    }

    private function queued_events(): array
    {
        $queue = get_option(self::EVENT_QUEUE_OPTION, []);
        return is_array($queue) ? $queue : [];
    }

    private function processed_event_ids(): array
    {
        $ids = get_option(self::PROCESSED_EVENTS_OPTION, []);
        return is_array($ids) ? $ids : [];
    }

    private function mark_event_processed(string $eventId, array &$processedIds, array &$queue, bool $dryRun, string $result): void
    {
        if ($eventId === '' || $dryRun) {
            return;
        }
        $processedIds[$eventId] = gmdate('Y-m-d H:i:s') . ':' . $result;
        unset($queue[$eventId]);
    }

    private function persist_event_state(array $queue, array $processedIds, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }
        update_option(self::EVENT_QUEUE_OPTION, array_slice($queue, -500, null, true), false);
        update_option(self::PROCESSED_EVENTS_OPTION, array_slice($processedIds, -1000, null, true), false);
    }

    private function fallback_stock_events(int $limit): array
    {
        $events = [];
        $limit = max(1, min(25, $limit));
        foreach ($this->repo->list_active_mappings($limit) as $map) {
            if (!$this->has_active_listing($map)) {
                continue;
            }
            $productId = (int) ($map['woo_variation_id'] ?: $map['woo_product_id']);
            $events['mapping:' . $productId] = [
                'id' => 'fallback:' . $productId . ':' . (string) ($map['remote_listing_id'] ?? '') . ':' . gmdate('YmdHi'),
                'reason' => 'fallback_active_listing_scan',
                'product_id' => $productId,
                'sku' => (string) ($map['sku'] ?? ''),
            ];
            if (count($events) >= $limit) {
                return array_values($events);
            }
        }

        foreach ($this->list_active_listing_meta_products($limit - count($events)) as $map) {
            $productId = (int) ($map['woo_product_id'] ?? 0);
            $events['meta:' . $productId] = [
                'id' => 'fallback:' . $productId . ':' . (string) ($map['remote_listing_id'] ?? '') . ':' . gmdate('YmdHi'),
                'reason' => 'fallback_active_listing_scan',
                'product_id' => $productId,
                'sku' => (string) ($map['sku'] ?? ''),
            ];
        }

        return array_values(array_slice($events, 0, $limit, true));
    }

    private function list_active_listing_meta_products(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) {
            return [];
        }

        $postmeta = $wpdb->postmeta;
        $limit = max(1, min(25, $limit));
        $sql = $wpdb->prepare(
            "SELECT p.ID AS product_id, listing.meta_value AS listing_id, offer.meta_value AS offer_id, status.meta_value AS listing_status
             FROM {$wpdb->posts} p
             LEFT JOIN {$postmeta} listing ON listing.post_id = p.ID AND listing.meta_key = '_wei_ebay_listing_id' AND listing.meta_value <> ''
             LEFT JOIN {$postmeta} offer ON offer.post_id = p.ID AND offer.meta_key = '_wei_ebay_offer_id' AND offer.meta_value <> ''
             INNER JOIN {$postmeta} status ON status.post_id = p.ID AND status.meta_key IN ('_wei_ebay_listing_status', 'listing_status') AND status.meta_value IN ('active', 'published')
             WHERE p.post_type IN ('product', 'product_variation') AND (listing.meta_value IS NOT NULL OR offer.meta_value IS NOT NULL)
             ORDER BY p.ID DESC
             LIMIT %d",
            $limit
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        return array_map(static function (array $row): array {
            $productId = (int) ($row['product_id'] ?? 0);
            return [
                'woo_product_id' => $productId,
                'woo_variation_id' => null,
                'sku' => 'GPSW-' . $productId,
                'remote_listing_id' => (string) ($row['listing_id'] ?? ''),
                'remote_offer_id' => (string) ($row['offer_id'] ?? ''),
                'status' => strtolower((string) ($row['listing_status'] ?? 'active')),
            ];
        }, $rows);
    }

    private function map_from_product_meta(int $productId, string $sku = ''): ?array
    {
        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        $listingStatus = strtolower(trim((string) get_post_meta($productId, '_wei_ebay_listing_status', true)));
        if ($listingStatus === '') {
            $listingStatus = strtolower(trim((string) get_post_meta($productId, 'listing_status', true)));
        }
        if (($listingId === '' && $offerId === '') || !in_array($listingStatus, ['active', 'published'], true)) {
            return null;
        }
        return [
            'woo_product_id' => $productId,
            'woo_variation_id' => null,
            'sku' => trim($sku) !== '' ? trim($sku) : 'GPSW-' . $productId,
            'remote_listing_id' => $listingId,
            'remote_offer_id' => $offerId,
            'status' => $listingStatus,
        ];
    }

    private function resolve_mapping_for_ebay_sale(string $sku, string $listingId): ?array
    {
        if ($sku !== '') {
            $map = $this->repo->find_by_sku($sku);
            if ($map) {
                return $map;
            }
        }
        if ($listingId !== '') {
            $map = $this->repo->find_by_listing_id($listingId);
            if ($map) {
                return $map;
            }
        }
        $productId = $this->product_id_from_sku($sku);
        return $productId > 0 ? $this->map_from_product_meta($productId, $sku) : null;
    }

    private function base_action_row(string $direction, array $map, int $productId, $oldStock, string $oldStatus, bool $dryRun, string $eventId = '', string $eventReason = ''): array
    {
        return [
            'event_id' => $eventId,
            'event_reason' => $eventReason,
            'direction' => $direction,
            'product_id' => $productId,
            'sku' => (string) ($map['sku'] ?? ('GPSW-' . $productId)),
            'listing_id' => (string) ($map['remote_listing_id'] ?? ''),
            'offer_id' => (string) ($map['remote_offer_id'] ?? ''),
            'old_woo_stock' => $oldStock,
            'new_woo_stock' => '',
            'old_woo_status' => $oldStatus,
            'new_woo_status' => '',
            'ebay_state_before' => '',
            'ebay_state_after' => '',
            'action' => '',
            'dry_run' => $dryRun ? 'yes' : 'no',
            'result' => '',
            'error_message' => '',
            'timestamp' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function empty_summary(bool $dryRun): array
    {
        return [
            'started_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => '',
            'dry_run' => $dryRun ? 'yes' : 'no',
            'queued_events_start' => count($this->queued_events()),
            'woo_to_ebay_checked' => 0,
            'woo_to_ebay_actions' => 0,
            'ebay_to_woo_checked' => 0,
            'ebay_to_woo_actions' => 0,
            'skipped' => 0,
            'errors' => 0,
            'fallback_scan_limit' => 10, // Active eBay listings only: _wei_ebay_listing_id / _wei_ebay_offer_id with listing_status active/published.
            'lock_used' => false,
            'result' => 'running',
        ];
    }

    private function persist_summary(array $summary): void
    {
        $paths = $this->report_paths();
        if (!empty($paths['last_run_path'])) {
            file_put_contents((string) $paths['last_run_path'], wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        update_option(self::LAST_RUN_OPTION, $summary + ['reports' => $paths], false);
    }

    private function append_action(array $row): void
    {
        $this->append_csv((string) $this->report_paths()['actions_path'], $row);
    }

    private function append_error(array $row): void
    {
        $this->append_csv((string) $this->report_paths()['errors_path'], $row);
    }

    private function append_csv(string $path, array $row): void
    {
        if ($path === '') {
            return;
        }
        $row = $this->normalize_csv_row($row);
        $mode = $this->csv_has_current_header($path) ? 'ab' : 'wb';
        $fh = fopen($path, $mode);
        if (!$fh) {
            return;
        }
        if ($mode === 'wb') {
            fputcsv($fh, self::ACTION_HEADERS);
        }
        fputcsv($fh, array_map(static fn(string $key): string => (string) ($row[$key] ?? ''), self::ACTION_HEADERS));
        fclose($fh);
    }

    private function normalize_csv_row(array $row): array
    {
        foreach (['before', 'after'] as $state) {
            $currentKey = 'ebay_state_' . $state;
            $legacyKey = 'eBay_state_' . $state;
            if (!array_key_exists($currentKey, $row) && array_key_exists($legacyKey, $row)) {
                $row[$currentKey] = $row[$legacyKey];
            }
        }
        return $row;
    }

    private function csv_has_current_header(string $path): bool
    {
        if (!file_exists($path) || filesize($path) <= 0) {
            return false;
        }
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $header = fgetcsv($fh);
        fclose($fh);
        return $header === self::ACTION_HEADERS;
    }

    private function report_paths(): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? sys_get_temp_dir())) . 'wei-ebay-integration';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? '')) . 'wei-ebay-integration';
        if (!is_dir($baseDir)) {
            wp_mkdir_p($baseDir);
        }
        return [
            'last_run_path' => trailingslashit($baseDir) . 'stock-sync-last-run.json',
            'actions_path' => trailingslashit($baseDir) . 'stock-sync-actions.csv',
            'errors_path' => trailingslashit($baseDir) . 'stock-sync-errors.csv',
            'last_run_url' => trailingslashit($baseUrl) . 'stock-sync-last-run.json',
            'actions_url' => trailingslashit($baseUrl) . 'stock-sync-actions.csv',
            'errors_url' => trailingslashit($baseUrl) . 'stock-sync-errors.csv',
        ];
    }

    private function acquire_lock(): bool
    {
        if (get_transient(self::LOCK_TRANSIENT)) {
            return false;
        }
        set_transient(self::LOCK_TRANSIENT, (string) time(), self::LOCK_TTL);
        return true;
    }

    private function release_lock(): void
    {
        delete_transient(self::LOCK_TRANSIENT);
    }

    private function cron_interval_key(string $interval): string
    {
        return match ($interval) {
            'every_5_minutes' => 'wei_every_5_minutes',
            'hourly' => 'hourly',
            default => 'wei_every_15_minutes',
        };
    }

    private function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        $s = is_array($s) ? $s : [];
        $s['stock_sync_enabled'] = isset($s['stock_sync_enabled']) ? (int) $s['stock_sync_enabled'] : 1;
        $s['stock_sync_woo_to_ebay_enabled'] = isset($s['stock_sync_woo_to_ebay_enabled']) ? (int) $s['stock_sync_woo_to_ebay_enabled'] : 1;
        $s['stock_sync_ebay_to_woo_enabled'] = isset($s['stock_sync_ebay_to_woo_enabled']) ? (int) $s['stock_sync_ebay_to_woo_enabled'] : 1;
        $s['stock_sync_dry_run'] = isset($s['stock_sync_dry_run']) ? (int) $s['stock_sync_dry_run'] : 1;
        $s['stock_sync_cron_interval'] = in_array((string) ($s['stock_sync_cron_interval'] ?? ''), ['every_5_minutes', 'every_15_minutes', 'hourly'], true) ? (string) $s['stock_sync_cron_interval'] : 'every_15_minutes';
        $s['stock_sync_safety_limit'] = max(1, min(500, (int) ($s['stock_sync_safety_limit'] ?? 50)));
        $s['stock_sync_fallback_scan_limit'] = max(1, min(25, (int) ($s['stock_sync_fallback_scan_limit'] ?? 10))); // small active-listing-only safety scan
        $s['stock_sync_woo_zero_action'] = in_array((string) ($s['stock_sync_woo_zero_action'] ?? ''), ['end_listing', 'set_quantity_zero'], true) ? (string) $s['stock_sync_woo_zero_action'] : 'end_listing';
        return $s;
    }
}
