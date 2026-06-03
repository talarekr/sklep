<?php

namespace WEI\Services;

use WEI\Plugin;
use WEI\Repositories\MappingRepository;

class StockSyncService
{
    public const CRON_HOOK = 'wei_ebay_stock_sync_cron';
    public const LAST_RUN_OPTION = 'wei_ebay_stock_sync_last_run';
    public const LOCK_TRANSIENT = 'wei_ebay_stock_sync_lock';
    private const LOCK_TTL = 600;

    /** @var array<int,string> */
    private const ACTION_HEADERS = [
        'direction',
        'product_id',
        'sku',
        'listing_id',
        'offer_id',
        'old_woo_stock',
        'new_woo_stock',
        'old_woo_status',
        'new_woo_status',
        'eBay_state_before',
        'eBay_state_after',
        'action',
        'dry_run',
        'result',
        'error_message',
        'timestamp',
    ];

    public function __construct(private EbayClient $client, private MappingRepository $repo, private Logger $logger)
    {
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_scheduled']);
        add_action(self::CRON_HOOK, [$this, 'run_cron']);
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
            'reports' => $this->report_paths(),
        ];
    }

    public function run(?bool $forceDryRun = null): array
    {
        $settings = $this->settings();
        $dryRun = $forceDryRun !== null ? $forceDryRun : !empty($settings['stock_sync_dry_run']);
        $summary = $this->empty_summary($dryRun);

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
        foreach ($this->repo->list_active_mappings((int) $settings['stock_sync_safety_limit']) as $map) {
            $summary['woo_to_ebay_checked']++;
            if (!$this->has_active_listing($map)) {
                $summary['skipped']++;
                continue;
            }
            $productId = (int) ($map['woo_variation_id'] ?: $map['woo_product_id']);
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            if (!$product) {
                $summary['skipped']++;
                continue;
            }
            $stock = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
            $stockStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
            $soldMeta = $this->has_local_sold_meta($productId, (int) $map['woo_product_id']);
            if (!$soldMeta && $stockStatus !== 'outofstock' && !($stock !== null && (int) $stock <= 0)) {
                $summary['skipped']++;
                continue;
            }

            $action = (string) ($settings['stock_sync_woo_zero_action'] ?? 'end_listing');
            $row = array_merge($this->base_action_row('woo_to_ebay', $map, $productId, $stock, $stockStatus, $dryRun), [
                'new_woo_stock' => $stock,
                'new_woo_status' => $stockStatus,
                'eBay_state_before' => 'active',
                'eBay_state_after' => $action === 'set_quantity_zero' ? 'quantity_0' : 'ended_or_unavailable',
                'action' => $action === 'set_quantity_zero' ? 'set_ebay_offer_quantity_zero' : 'end_ebay_listing_or_safe_equivalent',
            ]);
            if ($dryRun) {
                $row['result'] = 'dry_run';
                $this->append_action($row);
                $summary['woo_to_ebay_actions']++;
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

            $res = $this->client->bulk_update_price_quantity([[
                'offerId' => (string) ($map['remote_offer_id'] ?? ''),
                'shipToLocationAvailability' => ['quantity' => 0],
            ]]);
            $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'bulk_update_price_quantity', 'offer_id' => (string) ($map['remote_offer_id'] ?? ''), 'quantity' => 0, 'auth' => '[REDACTED]']);
            if (is_wp_error($res)) {
                $row['result'] = 'error';
                $row['error_message'] = $res->get_error_message();
                $summary['errors']++;
                $this->append_error($row);
            } else {
                $row['result'] = 'success';
                $summary['woo_to_ebay_actions']++;
                update_post_meta($productId, '_wei_ebay_last_stock_sync_action', (string) $row['action']);
                update_post_meta($productId, '_wei_ebay_stock_sync_source', 'woo');
                update_post_meta($productId, '_wei_ebay_listing_status', 'ended');
                $this->repo->upsert(array_merge($map, ['status' => 'ended', 'last_sync_at' => gmdate('Y-m-d H:i:s')]));
            }
            $this->append_action($row);
        }
        return $summary;
    }

    private function sync_ebay_to_woo(array $settings, bool $dryRun, array $summary): array
    {
        $orders = $this->client->get_orders(['limit' => min(50, (int) $settings['stock_sync_safety_limit'])]);
        $this->logger->info('WEI_STOCK_SYNC_EBAY_API_CALL', ['api' => 'get_orders', 'auth' => '[REDACTED]']);
        if (is_wp_error($orders)) {
            $summary['errors']++;
            $this->append_error(['direction' => 'ebay_to_woo', 'action' => 'fetch_ebay_orders', 'result' => 'error', 'error_message' => $orders->get_error_message()]);
            return $summary;
        }

        foreach ($this->sold_order_lines(is_array($orders) ? $orders : []) as $line) {
            $summary['ebay_to_woo_checked']++;
            $sku = (string) ($line['sku'] ?? '');
            $productId = $this->product_id_from_sku($sku);
            if ($productId <= 0) {
                $summary['skipped']++;
                $this->append_action(['direction' => 'ebay_to_woo', 'product_id' => '', 'sku' => $sku, 'action' => 'skip_unknown_sku', 'dry_run' => $dryRun ? 'yes' : 'no', 'result' => 'skipped', 'error_message' => 'unknown_sku', 'timestamp' => gmdate('Y-m-d H:i:s')]);
                continue;
            }
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            if (!$product) {
                $summary['skipped']++;
                continue;
            }
            $oldStock = method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null;
            $oldStatus = method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '';
            $map = $this->repo->find_by_sku($sku) ?: ['woo_product_id' => $productId, 'sku' => $sku, 'remote_listing_id' => (string) ($line['listing_id'] ?? ''), 'remote_offer_id' => ''];
            $row = array_merge($this->base_action_row('ebay_to_woo', $map, $productId, $oldStock, $oldStatus, $dryRun), [
                'new_woo_stock' => 0,
                'new_woo_status' => 'outofstock',
                'eBay_state_before' => 'sold',
                'eBay_state_after' => 'sold',
                'action' => 'set_woo_stock_zero_outofstock_mark_sold',
            ]);
            if ($dryRun) {
                $row['result'] = 'dry_run';
                $summary['ebay_to_woo_actions']++;
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
            $this->repo->upsert(array_merge($map, ['status' => 'sold', 'last_sync_at' => gmdate('Y-m-d H:i:s')]));
            $row['result'] = 'success';
            $summary['ebay_to_woo_actions']++;
            $this->append_action($row);
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
                $sku = (string) ($line['sku'] ?? $line['legacyItemId'] ?? '');
                if ($sku === '' && is_array($line['lineItemFulfillmentInstructions'] ?? null)) {
                    $sku = (string) ($line['lineItemFulfillmentInstructions']['sku'] ?? '');
                }
                $qty = (int) ($line['quantity'] ?? 1);
                if ($sku !== '' && $qty > 0) {
                    $rows[] = ['sku' => $sku, 'order_id' => (string) ($order['orderId'] ?? ''), 'listing_id' => (string) ($line['legacyItemId'] ?? '')];
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
        return !in_array($status, ['ended', 'sold', 'inactive', 'unavailable'], true)
            && (trim((string) ($map['remote_offer_id'] ?? '')) !== '' || trim((string) ($map['remote_listing_id'] ?? '')) !== '' || trim((string) ($map['sku'] ?? '')) !== '');
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

    private function base_action_row(string $direction, array $map, int $productId, $oldStock, string $oldStatus, bool $dryRun): array
    {
        return [
            'direction' => $direction,
            'product_id' => $productId,
            'sku' => (string) ($map['sku'] ?? ('GPSW-' . $productId)),
            'listing_id' => (string) ($map['remote_listing_id'] ?? ''),
            'offer_id' => (string) ($map['remote_offer_id'] ?? ''),
            'old_woo_stock' => $oldStock,
            'new_woo_stock' => '',
            'old_woo_status' => $oldStatus,
            'new_woo_status' => '',
            'eBay_state_before' => '',
            'eBay_state_after' => '',
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
            'woo_to_ebay_checked' => 0,
            'woo_to_ebay_actions' => 0,
            'ebay_to_woo_checked' => 0,
            'ebay_to_woo_actions' => 0,
            'skipped' => 0,
            'errors' => 0,
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
        $exists = file_exists($path) && filesize($path) > 0;
        $fh = fopen($path, 'ab');
        if (!$fh) {
            return;
        }
        if (!$exists) {
            fputcsv($fh, self::ACTION_HEADERS);
        }
        fputcsv($fh, array_map(static fn(string $key): string => (string) ($row[$key] ?? ''), self::ACTION_HEADERS));
        fclose($fh);
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
        $s['stock_sync_woo_zero_action'] = in_array((string) ($s['stock_sync_woo_zero_action'] ?? ''), ['end_listing', 'set_quantity_zero'], true) ? (string) $s['stock_sync_woo_zero_action'] : 'end_listing';
        return $s;
    }
}
