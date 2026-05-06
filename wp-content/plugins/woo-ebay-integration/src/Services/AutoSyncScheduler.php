<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;

class AutoSyncScheduler
{
    public const HOOK_RUN = 'wei_ebay_auto_sync_run';
    public const HOOK_STOCK = 'wei_ebay_process_stock_sync_queue';
    public const CRON_GROUP = 'wei_ebay_auto_sync';
    private const LOCK_KEY = 'wei_ebay_auto_sync_lock';
    private const LOCK_TTL = 3600;
    private const READINESS_NOT_READY_LIMIT = 50;
    private const READINESS_BUCKET_LIMIT = 25;


    public function __construct(private EbayAdapter $adapter, private OrderImporter $orderImporter, private Logger $logger)
    {
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_scheduled']);
        add_action(self::HOOK_RUN, [$this, 'run_scheduled']);
        add_action(self::HOOK_STOCK, [$this, 'process_stock_queue']);
        add_action('woocommerce_reduce_order_stock', [$this, 'queue_order_stock_sync']);
        add_action('woocommerce_product_set_stock', [$this, 'queue_product_stock_sync']);
        add_action('woocommerce_variation_set_stock', [$this, 'queue_product_stock_sync']);
        add_action('woocommerce_order_status_processing', [$this, 'queue_order_id_stock_sync']);
        add_action('woocommerce_order_status_completed', [$this, 'queue_order_id_stock_sync']);
        add_action('save_post_product', [$this, 'queue_saved_product_stock_sync'], 10, 3);
    }

    public function cron_schedules(array $schedules): array
    {
        $schedules['wei_every_15_minutes'] = ['interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'WEI every 15 minutes'];
        return $schedules;
    }

    public function ensure_scheduled(): void
    {
        $settings = $this->settings();
        $mode = (string) ($settings['auto_sync_mode'] ?? 'disabled');
        if ($mode === 'disabled' || !empty($settings['auto_sync_paused'])) {
            $this->clear_scheduled();
            $this->set_global_status(!empty($settings['auto_sync_paused']) ? 'paused' : 'disabled');
            return;
        }

        $frequency = $this->frequency($settings);
        if ($this->action_scheduler_available()) {
            $next = as_next_scheduled_action(self::HOOK_RUN, [], self::CRON_GROUP);
            if (!$next) {
                as_schedule_recurring_action(time() + 60, $this->frequency_seconds($frequency), self::HOOK_RUN, [], self::CRON_GROUP, true);
            }
            return;
        }

        if (!wp_next_scheduled(self::HOOK_RUN)) {
            wp_schedule_event(time() + 60, $this->wp_cron_recurrence($frequency), self::HOOK_RUN);
        }
    }

    public function clear_scheduled(): void
    {
        if ($this->action_scheduler_available()) {
            as_unschedule_all_actions(self::HOOK_RUN, [], self::CRON_GROUP);
            as_unschedule_all_actions(self::HOOK_STOCK, [], self::CRON_GROUP);
        }
        $runTs = wp_next_scheduled(self::HOOK_RUN);
        if ($runTs) {
            wp_unschedule_event($runTs, self::HOOK_RUN);
        }
        $stockTs = wp_next_scheduled(self::HOOK_STOCK);
        if ($stockTs) {
            wp_unschedule_event($stockTs, self::HOOK_STOCK);
        }
    }

    public function run_scheduled(): array
    {
        $settings = $this->settings();
        $mode = (string) ($settings['auto_sync_mode'] ?? 'disabled');
        if ($mode === 'disabled') {
            $this->set_global_status('disabled');
            return ['result' => 'skipped', 'reason' => 'disabled'];
        }
        if (!empty($settings['auto_sync_paused'])) {
            $this->set_global_status('paused');
            return ['result' => 'skipped', 'reason' => 'paused'];
        }
        if (!$this->acquire_lock('scheduler')) {
            $this->logger->warning('eBay auto sync skipped because previous run is still active', ['mode' => $mode]);
            return ['result' => 'skipped', 'reason' => 'locked'];
        }

        $runId = 'wei_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
        $summary = $this->blank_summary($runId, $mode);
        $this->set_global_status('running', $summary);

        try {
            if (in_array($mode, ['preflight_only', 'export_ready_products', 'full_sync'], true)) {
                $summary = array_merge($summary, $this->run_readiness_scan($this->preflight_batch_size($settings)));
            }
            if (in_array($mode, ['orders_stock_only', 'full_sync'], true) && !empty($settings['ebay_order_sync_enabled'])) {
                $orders = $this->orderImporter->import_once();
                $summary['orders_imported'] = count((array) ($orders['processed'] ?? []));
                $summary['woo_stock_updates'] = count(array_filter((array) ($orders['processed'] ?? []), static fn ($row): bool => is_array($row) && (string) ($row['result'] ?? '') === 'stock_synced_to_woo'));
                if (($orders['result'] ?? '') === 'error') {
                    $summary['errors']++;
                }
            }
            if (in_array($mode, ['orders_stock_only', 'full_sync'], true) && !empty($settings['woo_to_ebay_stock_sync_enabled'])) {
                $stock = $this->process_stock_queue($this->stock_batch_size($settings), false);
                $summary['ebay_stock_updates'] = (int) ($stock['updated'] ?? 0);
                $summary['pending_stock_sync'] = self::pending_stock_count();
                $summary['errors'] += (int) ($stock['errors'] ?? 0);
            }
            if (in_array($mode, ['export_ready_products', 'full_sync'], true)) {
                $export = $this->run_export_batch($this->export_batch_size($settings));
                $summary['exported'] = (int) ($export['exported'] ?? 0);
                $summary['published'] = (int) ($export['published'] ?? 0);
                $summary['skipped'] += (int) ($export['skipped'] ?? 0);
                $summary['errors'] += (int) ($export['errors'] ?? 0);
                if (($export['status'] ?? '') === 'blocked_by_ebay_account_restriction') {
                    $summary['status'] = 'blocked_by_ebay_account_restriction';
                }
            }

            $summary['finished_at'] = gmdate('Y-m-d H:i:s');
            $summary['pending_stock_sync'] = self::pending_stock_count();
            $finalStatus = (string) ($summary['status'] ?? '');
            if ($finalStatus === '') {
                $finalStatus = $summary['errors'] > 0 ? 'completed_with_errors' : 'completed';
            }
            $this->set_global_status($finalStatus, $summary);
            $this->logger->info('eBay auto sync run completed', $summary + ['wrote_woo_sku' => false, 'wrote_woo_price' => false, 'wrote_allegro' => false]);
            return ['result' => $summary['errors'] > 0 ? 'completed_with_errors' : 'success', 'summary' => $summary];
        } finally {
            $this->release_lock();
        }
    }

    public function run_readiness_scan(int $batchSize = 200): array
    {
        $ids = $this->product_ids_for_preflight($batchSize);
        $summary = [
            'processed' => 0,
            'ready' => 0,
            'not_ready' => 0,
            'blocked_by_category' => 0,
            'missing_german_content' => 0,
            'missing_required_aspects' => 0,
            'invalid_price' => 0,
            'missing_exchange_rate' => 0,
            'missing_image' => 0,
            'missing_stock' => 0,
            'missing_policies_location' => 0,
            'errors' => 0,
            'not_ready_items' => [],
            'not_ready_examples' => [],
            'blocked_by_category_items' => [],
            'missing_required_aspects_items' => [],
            'invalid_price_items' => [],
            'missing_exchange_rate_items' => [],
            'missing_german_content_items' => [],
            'missing_image_items' => [],
            'missing_stock_items' => [],
            'missing_policies_location_items' => [],
            'not_ready_sample_ids' => [],
            'blocked_by_category_sample_ids' => [],
            'missing_required_aspects_sample_ids' => [],
        ];

        foreach ($ids as $productId) {
            $result = $this->adapter->preflight_product($productId);
            $summary['processed']++;
            update_post_meta($productId, '_wei_ebay_last_preflight_at', gmdate('Y-m-d H:i:s'));
            if (!empty($result['ready'])) {
                $summary['ready']++;
                update_post_meta($productId, '_wei_ebay_export_status', 'ready');
                delete_post_meta($productId, '_wei_ebay_last_preflight_error');
                continue;
            }

            $summary['not_ready']++;
            $status = (string) ($result['status'] ?? 'not_ready');
            $item = $this->readiness_item($productId, $result);
            $this->persist_not_ready_preflight_status($productId, $result, $item);
            $this->append_limited($summary['not_ready_items'], $item, self::READINESS_NOT_READY_LIMIT);
            $summary['not_ready_examples'] = $summary['not_ready_items'];
            $this->append_limited($summary['not_ready_sample_ids'], $productId, self::READINESS_BUCKET_LIMIT);

            if ($this->is_category_blocked_status($status)) {
                $summary['blocked_by_category']++;
                $this->append_limited($summary['blocked_by_category_items'], $item, self::READINESS_BUCKET_LIMIT);
                $this->append_limited($summary['blocked_by_category_sample_ids'], $productId, self::READINESS_BUCKET_LIMIT);
            } elseif ($status === 'not_ready_missing_german_content') {
                $summary['missing_german_content']++;
                $this->append_limited($summary['missing_german_content_items'], $item, self::READINESS_BUCKET_LIMIT);
            } elseif ($status === 'missing_required_aspects') {
                $summary['missing_required_aspects']++;
                $this->append_limited($summary['missing_required_aspects_items'], $item, self::READINESS_BUCKET_LIMIT);
                $this->append_limited($summary['missing_required_aspects_sample_ids'], $productId, self::READINESS_BUCKET_LIMIT);
            } elseif ($status === 'invalid_price') {
                $summary['invalid_price']++;
                $this->append_limited($summary['invalid_price_items'], $item, self::READINESS_BUCKET_LIMIT);
            } elseif ($status === 'missing_exchange_rate') {
                $summary['missing_exchange_rate']++;
                $this->append_limited($summary['missing_exchange_rate_items'], $item, self::READINESS_BUCKET_LIMIT);
            }
            $errors = array_map('strtolower', (array) ($result['errors'] ?? []));
            foreach ($errors as $error) {
                if (str_contains($error, 'image')) {
                    $summary['missing_image']++;
                    $this->append_limited($summary['missing_image_items'], $item, self::READINESS_BUCKET_LIMIT);
                }
                if (str_contains($error, 'stock')) {
                    $summary['missing_stock']++;
                    $this->append_limited($summary['missing_stock_items'], $item, self::READINESS_BUCKET_LIMIT);
                }
                if (str_contains($error, 'polic') || str_contains($error, 'location')) {
                    $summary['missing_policies_location']++;
                    $this->append_limited($summary['missing_policies_location_items'], $item, self::READINESS_BUCKET_LIMIT);
                }
            }
        }

        $summary['last_run'] = gmdate('Y-m-d H:i:s');
        update_option('wei_ebay_readiness_summary', $summary, false);
        $this->logger->info('eBay readiness scan completed', $this->readiness_log_context($summary));
        return $summary;
    }

    private function readiness_item(int $productId, array $result): array
    {
        $product = wc_get_product($productId);
        $category = is_array($result['category'] ?? null) ? $result['category'] : [];
        $mapping = is_array($category['mapping'] ?? null) ? $category['mapping'] : [];
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $skuResolution = is_array($result['sku_resolution'] ?? null) ? $result['sku_resolution'] : [];
        $priceResolution = is_array($result['price_resolution'] ?? null) ? $result['price_resolution'] : [];
        $status = (string) ($result['status'] ?? 'not_ready');
        $errors = array_values(array_map('strval', (array) ($result['errors'] ?? [])));
        $missingAspects = array_values(array_map('strval', (array) ($result['missing_aspects'] ?? [])));
        $requiredAspects = array_values(array_map('strval', (array) ($result['required_aspects'] ?? [])));
        $categoryName = (string) ($category['category_name'] ?? $mapping['ebay_category_name'] ?? '');
        $categoryPath = (string) ($category['category_path'] ?? $mapping['ebay_category_path'] ?? '');
        $categoryReason = (string) ($category['sanity_reason'] ?? $mapping['error_reason'] ?? '');
        $message = (string) ($result['message'] ?? '');
        $productTitle = $product ? (string) $product->get_name() : (string) get_the_title($productId);
        $suggestionDiagnostics = $this->category_suggestion_diagnostics($mapping, $productTitle, $content);

        return [
            'product_id' => $productId,
            'product_title' => $productTitle,
            'edit_url' => (string) get_edit_post_link($productId, ''),
            'status' => $status,
            'primary_reason' => $this->primary_readiness_reason($status, $errors, $missingAspects, $message, $categoryReason),
            'errors' => $errors,
            'category_id' => (string) ($category['category_id'] ?? $mapping['ebay_category_id'] ?? ''),
            'category_name' => $categoryName,
            'category_path' => $categoryPath,
            'category_status' => (string) ($category['status'] ?? $mapping['status'] ?? ''),
            'category_sanity_reason' => $categoryReason,
            'woo_category_path' => (string) ($mapping['woo_category_path'] ?? ''),
            'mapping_id' => (int) ($mapping['id'] ?? 0),
            'mapping_status' => (string) ($mapping['status'] ?? ''),
            'mapping_error_reason' => (string) ($mapping['error_reason'] ?? ''),
            'best_candidate_category_id' => (string) ($suggestionDiagnostics['best_candidate_category_id'] ?? ''),
            'best_candidate_name' => (string) ($suggestionDiagnostics['best_candidate_name'] ?? ''),
            'best_candidate_path' => (string) ($suggestionDiagnostics['best_candidate_path'] ?? ''),
            'selected_candidate' => (array) ($suggestionDiagnostics['selected_candidate'] ?? []),
            'rejected_best_reason' => (string) ($suggestionDiagnostics['rejected_best_reason'] ?? ''),
            'top_candidates' => (array) ($suggestionDiagnostics['top_candidates'] ?? []),
            'detected_intent' => (string) ($suggestionDiagnostics['detected_intent'] ?? ''),
            'intent_source_text_used' => (string) ($suggestionDiagnostics['intent_source_text_used'] ?? ''),
            'why_no_intent_match' => (string) ($suggestionDiagnostics['why_no_intent_match'] ?? ''),
            'missing_aspects' => $missingAspects,
            'required_aspects' => $requiredAspects,
            'price_ready' => !empty($priceResolution['ready']),
            'content_ready' => !empty($content['title']) && !empty($content['description']),
            'sku' => $product ? (string) $product->get_sku() : '',
            'ebay_sku' => (string) ($skuResolution['sku'] ?? ''),
        ];
    }


    private function category_suggestion_diagnostics(array $mapping, string $productTitle = '', array $content = []): array
    {
        $payload = json_decode((string) ($mapping['suggestion_payload'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $sourceText = trim(implode(' ', array_filter([
            $productTitle,
            (string) ($mapping['woo_category_path'] ?? ''),
            (string) ($content['title'] ?? ''),
            (string) ($content['description'] ?? ''),
            (string) ($payload['query_de'] ?? ''),
            (string) ($payload['query_source'] ?? ''),
        ], static fn($value): bool => trim((string) $value) !== '')));
        $detectedIntent = (string) ($payload['intent'] ?? '');
        if ($detectedIntent === '') {
            $detectedIntent = CategoryMappingSafety::detect_intent($sourceText);
        }
        $intentSource = (string) ($payload['intent_source_text_used'] ?? '');
        if ($intentSource === '') {
            $intentSource = CategoryMappingSafety::normalized_intent_source_text($sourceText);
        }

        $topCandidates = array_values(array_filter((array) ($payload['top_candidates'] ?? []), 'is_array'));
        $selected = is_array($payload['selected_candidate'] ?? null) ? $payload['selected_candidate'] : [];
        $best = $topCandidates[0] ?? $selected;

        return [
            'detected_intent' => $detectedIntent,
            'intent_source_text_used' => $intentSource,
            'why_no_intent_match' => $detectedIntent === '' ? ((string) ($payload['why_no_intent_match'] ?? '') ?: 'no_keywords_matched') : '',
            'selected_candidate' => $selected,
            'rejected_best_reason' => (string) ($payload['rejected_best_reason'] ?? ''),
            'top_candidates' => array_slice(array_map(static function (array $candidate): array {
                return [
                    'category_id' => (string) ($candidate['category_id'] ?? ''),
                    'name' => (string) ($candidate['name'] ?? $candidate['category_name'] ?? ''),
                    'path' => (string) ($candidate['path'] ?? $candidate['category_path'] ?? ''),
                    'score' => (float) ($candidate['score'] ?? 0),
                    'sanity_reason' => (string) ($candidate['sanity_reason'] ?? ''),
                ];
            }, $topCandidates), 0, 3),
            'best_candidate_category_id' => (string) ($best['category_id'] ?? ''),
            'best_candidate_name' => (string) ($best['name'] ?? $best['category_name'] ?? ''),
            'best_candidate_path' => (string) ($best['path'] ?? $best['category_path'] ?? ''),
        ];
    }

    private function primary_readiness_reason(string $status, array $errors, array $missingAspects, string $message, string $categoryReason): string
    {
        if ($this->is_category_blocked_status($status)) {
            return $categoryReason !== '' ? 'blocked_by_category: ' . $categoryReason : 'blocked_by_category';
        }
        if ($status === 'missing_required_aspects') {
            return 'missing_required_aspects' . ($missingAspects !== [] ? ': ' . implode(', ', $missingAspects) : '');
        }
        if ($status === 'not_ready_missing_german_content') {
            return 'missing_german_content';
        }
        if ($status === 'invalid_price' || $status === 'missing_exchange_rate') {
            return $status;
        }
        if ($errors !== []) {
            return implode('; ', $errors);
        }

        return $message !== '' ? $message : $status;
    }

    private function persist_not_ready_preflight_status(int $productId, array $result, array $item): void
    {
        update_post_meta($productId, '_wei_ebay_export_status', 'not_ready');
        update_post_meta($productId, '_wei_ebay_last_preflight_error', (string) ($result['message'] ?? $item['primary_reason'] ?? 'not_ready'));
        update_post_meta($productId, '_wei_ebay_last_preflight_reason', (string) ($item['primary_reason'] ?? 'not_ready'));
        update_post_meta($productId, '_wei_ebay_last_missing_aspects', (array) ($item['missing_aspects'] ?? []));
        update_post_meta($productId, '_wei_ebay_last_category_status', (string) ($item['category_status'] ?? ''));
        update_post_meta($productId, '_wei_ebay_last_category_reason', (string) ($item['category_sanity_reason'] ?? ''));
        update_post_meta($productId, '_wei_ebay_last_preflight_at', gmdate('Y-m-d H:i:s'));
    }

    private function append_limited(array &$items, $item, int $limit): void
    {
        if (count($items) < $limit) {
            $items[] = $item;
        }
    }

    private function is_category_blocked_status(string $status): bool
    {
        return in_array($status, ['needs_category_review', 'low_confidence_auto', 'category_sanity_failed', 'taxonomy_api_forbidden', 'suggestion_failed', 'unmapped'], true);
    }

    private function readiness_log_context(array $summary): array
    {
        return [
            'processed' => (int) ($summary['processed'] ?? 0),
            'ready' => (int) ($summary['ready'] ?? 0),
            'not_ready' => (int) ($summary['not_ready'] ?? 0),
            'blocked_by_category' => (int) ($summary['blocked_by_category'] ?? 0),
            'missing_required_aspects' => (int) ($summary['missing_required_aspects'] ?? 0),
            'not_ready_sample_ids' => (array) ($summary['not_ready_sample_ids'] ?? []),
            'blocked_by_category_sample_ids' => (array) ($summary['blocked_by_category_sample_ids'] ?? []),
            'missing_required_aspects_sample_ids' => (array) ($summary['missing_required_aspects_sample_ids'] ?? []),
        ];
    }

    public function run_export_batch(int $batchSize = 20): array
    {
        $settings = $this->settings();
        if (empty($settings['auto_export_enabled'])) {
            return ['result' => 'skipped', 'reason' => 'auto_export_disabled', 'skipped' => 0, 'exported' => 0, 'published' => 0, 'errors' => 0];
        }
        $ids = $this->product_ids_by_export_status('ready', $batchSize);
        $summary = ['result' => 'success', 'exported' => 0, 'published' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($ids as $productId) {
            update_post_meta($productId, '_wei_ebay_export_status', 'queued_for_export');
            $res = $this->adapter->export_product($productId);
            if (($res['result'] ?? '') === 'success') {
                $summary['exported']++;
                $status = (string) get_post_meta($productId, '_wei_ebay_export_status', true);
                if ($status === 'published') {
                    $summary['published']++;
                }
            } else {
                $summary['errors']++;
                if (($res['stage'] ?? '') === 'publishOffer' && $this->is_account_restriction_result($res)) {
                    update_post_meta($productId, '_wei_ebay_export_status', 'publish_blocked_account');
                    $summary['status'] = 'blocked_by_ebay_account_restriction';
                    $this->set_global_status('blocked_by_ebay_account_restriction', ['last_error' => $res]);
                    break;
                }
                update_post_meta($productId, '_wei_ebay_export_status', 'export_error');
                update_post_meta($productId, '_wei_ebay_last_preflight_error', (string) ($res['message'] ?? $res['error'] ?? 'export_error'));
            }
        }
        update_option('wei_ebay_export_summary', $summary + ['last_run' => gmdate('Y-m-d H:i:s')], false);
        return $summary;
    }

    public function process_stock_queue(int $batchSize = 50, bool $withLock = true): array
    {
        $settings = $this->settings();
        if (empty($settings['woo_to_ebay_stock_sync_enabled'])) {
            return ['result' => 'skipped', 'reason' => 'woo_to_ebay_stock_sync_disabled', 'updated' => 0, 'errors' => 0];
        }
        if ($withLock && !$this->acquire_lock('stock_queue')) {
            return ['result' => 'skipped', 'reason' => 'locked', 'updated' => 0, 'errors' => 0];
        }
        try {
            $ids = self::pending_stock_product_ids($batchSize);
            $summary = ['result' => 'success', 'processed' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
            foreach ($ids as $productId) {
                $summary['processed']++;
                $source = (string) get_post_meta($productId, '_wei_ebay_stock_sync_source', true);
                $res = $this->adapter->sync_stock($productId);
                if (($res['result'] ?? '') === 'success') {
                    update_post_meta($productId, '_wei_ebay_stock_sync_pending', '0');
                    update_post_meta($productId, '_wei_ebay_last_stock_sync_at', gmdate('Y-m-d H:i:s'));
                    update_post_meta($productId, '_wei_ebay_export_status', 'stock_synced_to_ebay');
                    $summary['updated']++;
                    $this->logger->info('eBay stock sync processed', ['source' => $source, 'product_id' => $productId, 'ebay_sku' => $res['sku'] ?? '', 'old_woo_stock' => get_post_meta($productId, '_wei_ebay_stock_sync_old_woo_stock', true), 'new_woo_stock' => get_post_meta($productId, '_wei_ebay_stock_sync_new_woo_stock', true), 'ebay_quantity' => $res['quantity'] ?? null, 'wrote_allegro' => false]);
                } elseif (($res['result'] ?? '') === 'skipped') {
                    update_post_meta($productId, '_wei_ebay_stock_sync_pending', '0');
                    $summary['skipped']++;
                    $this->logger->info('eBay stock sync skipped unchanged', ['source' => $source, 'product_id' => $productId, 'reason' => $res['reason'] ?? '', 'wrote_allegro' => false]);
                } else {
                    $retries = (int) get_post_meta($productId, '_wei_ebay_stock_sync_retry_count', true) + 1;
                    update_post_meta($productId, '_wei_ebay_stock_sync_retry_count', (string) $retries);
                    update_post_meta($productId, '_wei_ebay_last_stock_sync_error', (string) ($res['error'] ?? $res['message'] ?? 'stock_sync_error'));
                    $summary['errors']++;
                    $this->logger->error('eBay stock sync failed', ['source' => $source, 'product_id' => $productId, 'error' => $res, 'retry_count' => $retries, 'wrote_allegro' => false]);
                }
            }
            update_option('wei_ebay_stock_sync_summary', $summary + ['last_run' => gmdate('Y-m-d H:i:s'), 'pending_stock_sync' => self::pending_stock_count()], false);
            return $summary;
        } finally {
            if ($withLock) {
                $this->release_lock();
            }
        }
    }

    public function queue_product_stock_sync($product, string $source = 'woo_stock_change'): void
    {
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        if ($this->is_ebay_order_stock_context()) {
            $source = 'ebay_order_sync';
        }
        $this->queue_product_id((int) $product->get_id(), $source);
    }

    public function queue_order_stock_sync($order): void
    {
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return;
        }
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $productId = (int) (method_exists($item, 'get_variation_id') && $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id());
            $this->queue_product_id($productId, 'woo_order');
        }
    }

    public function queue_order_id_stock_sync(int $orderId): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        if ($order) {
            $this->queue_order_stock_sync($order);
        }
    }

    public function queue_saved_product_stock_sync(int $postId, \WP_Post $post, bool $update): void
    {
        if (!$update || wp_is_post_revision($postId)) {
            return;
        }
        $this->queue_product_id($postId, 'save_post_product');
    }

    public function queue_product_id(int $productId, string $source): void
    {
        if ($productId <= 0 || get_post_type($productId) !== 'product' && get_post_type($productId) !== 'product_variation') {
            return;
        }
        update_post_meta($productId, '_wei_ebay_stock_sync_pending', '1');
        update_post_meta($productId, '_wei_ebay_stock_sync_queued_at', gmdate('Y-m-d H:i:s'));
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if ($product && is_object($product) && method_exists($product, 'get_stock_quantity')) {
            update_post_meta($productId, '_wei_ebay_stock_sync_new_woo_stock', (string) max(0, (int) $product->get_stock_quantity()));
        }
        update_post_meta($productId, '_wei_ebay_stock_sync_source', $source);
        update_post_meta($productId, '_wei_ebay_export_status', 'stock_sync_pending_to_ebay');
        $this->logger->info('Woo stock change queued for eBay sync', ['source' => $source, 'product_id' => $productId, 'wrote_allegro' => false]);
        $this->schedule_stock_queue_once();
    }

    public static function mark_ebay_order_stock_context(bool $active): void
    {
        $GLOBALS['wei_ebay_order_stock_sync_active'] = $active;
    }

    private function is_ebay_order_stock_context(): bool
    {
        return !empty($GLOBALS['wei_ebay_order_stock_sync_active']);
    }

    private function schedule_stock_queue_once(): void
    {
        if ($this->action_scheduler_available()) {
            if (!as_next_scheduled_action(self::HOOK_STOCK, [], self::CRON_GROUP)) {
                as_schedule_single_action(time() + 60, self::HOOK_STOCK, [], self::CRON_GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled(self::HOOK_STOCK)) {
            wp_schedule_single_event(time() + 60, self::HOOK_STOCK);
        }
    }

    public static function status_summary(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $frequency = (string) ($settings['auto_sync_frequency'] ?? 'hourly');
        $next = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::HOOK_RUN, [], self::CRON_GROUP) : false;
        if (!$next) {
            $next = wp_next_scheduled(self::HOOK_RUN);
        }
        return [
            'status' => (string) get_option('wei_ebay_global_status', 'disabled'),
            'mode' => (string) ($settings['auto_sync_mode'] ?? 'disabled'),
            'frequency' => $frequency,
            'batch_size' => (int) ($settings['auto_sync_export_batch_size'] ?? 20),
            'preflight_batch_size' => (int) ($settings['auto_sync_preflight_batch_size'] ?? 200),
            'last_run' => (string) get_option('wei_ebay_last_run_at', ''),
            'next_run' => $next ? gmdate('Y-m-d H:i:s', (int) $next) : '-',
            'last_summary' => get_option('wei_ebay_last_run_summary', []),
            'pending_stock_sync' => self::pending_stock_count(),
            'woo_to_ebay_stock_sync_enabled' => !empty($settings['woo_to_ebay_stock_sync_enabled']),
            'ebay_stock_sync_mode' => (string) ($settings['ebay_stock_sync_mode'] ?? 'max_one'),
            'ebay_order_sync_enabled' => !empty($settings['ebay_order_sync_enabled']),
            'account_restriction_status' => (string) get_option('wei_ebay_account_restriction_status', ''),
            'readiness_summary' => get_option('wei_ebay_readiness_summary', []),
            'export_summary' => get_option('wei_ebay_export_summary', []),
            'stock_summary' => get_option('wei_ebay_stock_sync_summary', []),
        ];
    }

    public static function pending_stock_count(): int
    {
        $query = new \WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => [['key' => '_wei_ebay_stock_sync_pending', 'value' => '1']],
            'no_found_rows' => false,
        ]);
        return (int) $query->found_posts;
    }

    public static function pending_stock_product_ids(int $limit): array
    {
        return array_map('intval', get_posts([
            'post_type' => ['product', 'product_variation'],
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => max(1, $limit),
            'orderby' => 'meta_value',
            'meta_key' => '_wei_ebay_stock_sync_queued_at',
            'order' => 'ASC',
            'meta_query' => [['key' => '_wei_ebay_stock_sync_pending', 'value' => '1']],
        ]));
    }

    private function acquire_lock(string $owner): bool
    {
        $lock = get_transient(self::LOCK_KEY);
        if (is_array($lock) && (int) ($lock['expires_at'] ?? 0) > time()) {
            return false;
        }
        set_transient(self::LOCK_KEY, ['owner' => $owner, 'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => time() + self::LOCK_TTL], self::LOCK_TTL);
        return true;
    }

    private function release_lock(): void
    {
        delete_transient(self::LOCK_KEY);
    }

    private function set_global_status(string $status, array $summary = []): void
    {
        update_option('wei_ebay_global_status', $status, false);
        if ($status === 'blocked_by_ebay_account_restriction') {
            update_option('wei_ebay_account_restriction_status', 'detected', false);
        }
        if ($summary !== []) {
            update_option('wei_ebay_last_run_summary', $summary, false);
        }
        if (in_array($status, ['completed', 'completed_with_errors', 'blocked_by_ebay_account_restriction', 'error'], true)) {
            update_option('wei_ebay_last_run_at', gmdate('Y-m-d H:i:s'), false);
        }
    }

    private function product_ids_for_preflight(int $limit): array
    {
        return array_map('intval', get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => max(1, $limit),
            'orderby' => 'ID',
            'order' => 'ASC',
        ]));
    }

    private function product_ids_by_export_status(string $status, int $limit): array
    {
        return array_map('intval', get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => max(1, $limit),
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [['key' => '_wei_ebay_export_status', 'value' => $status]],
        ]));
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        $settings = is_array($settings) ? $settings : [];
        $settings += [
            'auto_sync_mode' => 'disabled',
            'auto_sync_frequency' => 'hourly',
            'auto_sync_export_batch_size' => 20,
            'auto_sync_preflight_batch_size' => 200,
            'woo_to_ebay_stock_sync_enabled' => 1,
            'ebay_order_sync_enabled' => 1,
            'ebay_stock_sync_mode' => 'max_one',
            'ebay_order_stock_update_mode' => 'set_zero',
            'auto_export_enabled' => 0,
            'auto_publish_enabled' => 0,
        ];
        return $settings;
    }

    private function frequency(array $settings): string
    {
        $frequency = (string) ($settings['auto_sync_frequency'] ?? 'hourly');
        return in_array($frequency, ['every_15_minutes', 'hourly', 'daily'], true) ? $frequency : 'hourly';
    }

    private function frequency_seconds(string $frequency): int
    {
        return match ($frequency) {
            'every_15_minutes' => 15 * MINUTE_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            default => HOUR_IN_SECONDS,
        };
    }

    private function wp_cron_recurrence(string $frequency): string
    {
        return $frequency === 'every_15_minutes' ? 'wei_every_15_minutes' : $frequency;
    }

    private function export_batch_size(array $settings): int
    {
        return max(1, min(50, (int) ($settings['auto_sync_export_batch_size'] ?? 20)));
    }

    private function preflight_batch_size(array $settings): int
    {
        return max(1, min(300, (int) ($settings['auto_sync_preflight_batch_size'] ?? 200)));
    }

    private function stock_batch_size(array $settings): int
    {
        return max(1, min(300, (int) ($settings['auto_sync_stock_batch_size'] ?? 100)));
    }

    private function blank_summary(string $runId, string $mode): array
    {
        return [
            'run_id' => $runId,
            'mode' => $mode,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'finished_at' => '',
            'processed' => 0,
            'ready' => 0,
            'exported' => 0,
            'published' => 0,
            'skipped' => 0,
            'errors' => 0,
            'orders_imported' => 0,
            'woo_stock_updates' => 0,
            'ebay_stock_updates' => 0,
            'pending_stock_sync' => 0,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ];
    }

    private function action_scheduler_available(): bool
    {
        return function_exists('as_schedule_recurring_action') && function_exists('as_next_scheduled_action') && function_exists('as_unschedule_all_actions');
    }

    private function is_account_restriction_result(array $result): bool
    {
        $haystack = strtolower(wp_json_encode($result) ?: '');
        return str_contains($haystack, 'german tax rules') || str_contains($haystack, 'violation of our policy');
    }
}
