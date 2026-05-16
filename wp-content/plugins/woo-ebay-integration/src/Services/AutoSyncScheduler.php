<?php

namespace WEI\Services;

use WEI\Adapters\EbayAdapter;
use WEI\Plugin;

class AutoSyncScheduler
{
    public const HOOK_RUN = 'wei_ebay_auto_sync_run';
    public const HOOK_DELTA_SYNC = 'wei_ebay_run_scheduled_sync';
    public const HOOK_STOCK = 'wei_ebay_process_stock_sync_queue';
    public const CRON_GROUP = 'wei_ebay_auto_sync';
    private const LOCK_KEY = 'wei_ebay_auto_sync_lock';
    private const DELTA_LOCK_KEY = 'wei_ebay_delta_sync_lock';
    private const CHECKPOINT_OPTION = 'wei_ebay_sync_checkpoints';
    private const QUEUE_BATCH_SIZE = 50;
    private const LOCK_TTL = 900;
    private const READINESS_NOT_READY_LIMIT = 50;
    private const READINESS_BUCKET_LIMIT = 25;
    private const FULL_CATEGORY_AUDIT_BATCH_SIZE = 150;
    private const FULL_CATEGORY_AUDIT_STATE_OPTION = 'wei_ebay_full_category_audit_state';
    private const GERMAN_CONTENT_AUDIT_STATE_OPTION = 'wei_ebay_german_content_audit_state';
    private const GERMAN_CONTENT_AUDIT_BATCH_SIZE = 50;



    public function __construct(private EbayAdapter $adapter, private OrderImporter $orderImporter, private Logger $logger)
    {
    }

    public function hooks(): void
    {
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_scheduled']);
        add_action(self::HOOK_DELTA_SYNC, [$this, 'run_checkpoint_queue_sync']);
        add_action(self::HOOK_RUN, [$this, 'run_scheduled']);
        add_action(self::HOOK_STOCK, [$this, 'process_stock_queue']);
        add_action('woocommerce_reduce_order_stock', [$this, 'queue_order_stock_sync']);
        add_action('woocommerce_product_set_stock', [$this, 'queue_product_stock_sync']);
        add_action('woocommerce_variation_set_stock', [$this, 'queue_product_stock_sync']);
        add_action('woocommerce_order_status_processing', [$this, 'queue_order_id_stock_sync']);
        add_action('woocommerce_order_status_completed', [$this, 'queue_order_id_stock_sync']);
        add_action('save_post_product', [$this, 'queue_saved_product_stock_sync'], 10, 3);
        add_action('woocommerce_new_product', [$this, 'queue_new_product_sync']);
        add_action('woocommerce_update_product', [$this, 'queue_updated_product_sync']);
        add_action('woocommerce_product_set_regular_price', [$this, 'queue_product_price_sync']);
        add_action('woocommerce_product_set_sale_price', [$this, 'queue_product_price_sync']);
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
            $next = as_next_scheduled_action(self::HOOK_DELTA_SYNC, [], self::CRON_GROUP);
            if (!$next) {
                as_schedule_recurring_action(time() + 60, 15 * MINUTE_IN_SECONDS, self::HOOK_DELTA_SYNC, [], self::CRON_GROUP, true);
            }
            return;
        }

        if (!wp_next_scheduled(self::HOOK_DELTA_SYNC)) {
            wp_schedule_event(time() + 60, 'wei_every_15_minutes', self::HOOK_DELTA_SYNC);
        }
    }

    public function clear_scheduled(): void
    {
        if ($this->action_scheduler_available()) {
            as_unschedule_all_actions(self::HOOK_RUN, [], self::CRON_GROUP);
            as_unschedule_all_actions(self::HOOK_DELTA_SYNC, [], self::CRON_GROUP);
            as_unschedule_all_actions(self::HOOK_STOCK, [], self::CRON_GROUP);
        }
        $runTs = wp_next_scheduled(self::HOOK_RUN);
        if ($runTs) {
            wp_unschedule_event($runTs, self::HOOK_RUN);
        }
        $deltaTs = wp_next_scheduled(self::HOOK_DELTA_SYNC);
        if ($deltaTs) {
            wp_unschedule_event($deltaTs, self::HOOK_DELTA_SYNC);
        }
        $stockTs = wp_next_scheduled(self::HOOK_STOCK);
        if ($stockTs) {
            wp_unschedule_event($stockTs, self::HOOK_STOCK);
        }
    }

    public function run_scheduled(): array
    {
        return $this->run_checkpoint_queue_sync();
    }

    public function run_checkpoint_queue_sync(?int $batchSize = null): array
    {
        $settings = $this->settings();
        $this->logger->info('EBAY_SYNC_START', ['hook' => self::HOOK_DELTA_SYNC]);
        $this->logger->info('EBAY_SYNC_NO_FULL_SCAN', ['full_product_scan' => false, 'full_audit' => false, 'content_generation' => false]);

        if ((string) ($settings['auto_sync_mode'] ?? 'disabled') === 'disabled') {
            $this->save_checkpoint(['last_run_status' => 'disabled']);
            return ['result' => 'skipped', 'reason' => 'disabled'];
        }
        if (!empty($settings['auto_sync_paused'])) {
            $this->save_checkpoint(['last_run_status' => 'paused']);
            return ['result' => 'skipped', 'reason' => 'paused'];
        }

        $checkpoint = $this->get_checkpoint();
        $this->logger->info('EBAY_SYNC_CHECKPOINT_LOADED', $this->compact_checkpoint($checkpoint));

        if (!$this->acquire_delta_lock('ebay_event_sync')) {
            $this->logger->warning('EBAY_SYNC_LOCK_ALREADY_RUNNING', ['lock' => self::DELTA_LOCK_KEY]);
            return ['result' => 'skipped', 'reason' => 'locked'];
        }
        $this->logger->info('EBAY_SYNC_LOCK_ACQUIRED', ['lock' => self::DELTA_LOCK_KEY]);

        $summary = [
            'result' => 'success',
            'orders_imported' => 0,
            'orders_skipped' => 0,
            'listing_meta_checked' => 0,
            'queue_processed' => 0,
            'queue_succeeded' => 0,
            'queue_failed' => 0,
            'queue_skipped' => 0,
            'errors' => 0,
            'batch_size' => $batchSize ?? $this->queue_batch_size($settings),
            'started_at' => gmdate('Y-m-d H:i:s'),
        ];

        try {
            if (!empty($settings['ebay_order_sync_enabled'])) {
                $this->logger->info('EBAY_SYNC_ORDER_ENDPOINT_CALL', ['since' => (string) ($checkpoint['last_ebay_order_sync_at'] ?? ''), 'limit' => 50]);
                $orders = $this->orderImporter->import_since((string) ($checkpoint['last_ebay_order_sync_at'] ?? ''), 50);
                if (($orders['result'] ?? '') === 'error') {
                    $summary['errors']++;
                    $this->save_checkpoint(['last_run_status' => 'error', 'last_error' => (string) ($orders['error'] ?? 'order_sync_error')]);
                } else {
                    $processed = (array) ($orders['processed'] ?? []);
                    $summary['orders_imported'] = count($processed);
                    if ($processed === []) {
                        $summary['orders_skipped']++;
                        $this->logger->info('EBAY_SYNC_NO_NEW_ORDERS', ['reason' => (string) ($orders['reason'] ?? 'empty_delta')]);
                    }
                    $this->save_checkpoint(['last_ebay_order_sync_at' => gmdate('Y-m-d H:i:s')]);
                }
            }

            $listing = $this->sync_listing_meta_delta(50);
            $summary['listing_meta_checked'] = (int) ($listing['checked'] ?? 0);
            $this->save_checkpoint(['last_ebay_offer_sync_at' => gmdate('Y-m-d H:i:s'), 'last_ebay_inventory_sync_at' => gmdate('Y-m-d H:i:s')]);

            $this->logger->info('EBAY_SYNC_QUEUE_LOADED', ['queued' => self::queue_count('pending'), 'failed' => self::queue_count('failed'), 'batch_size' => $summary['batch_size']]);
            $queue = $this->process_change_queue((int) $summary['batch_size'], false);
            $summary['queue_processed'] = (int) ($queue['processed'] ?? 0);
            $summary['queue_succeeded'] = (int) ($queue['succeeded'] ?? 0);
            $summary['queue_failed'] = (int) ($queue['failed'] ?? 0);
            $summary['queue_skipped'] = (int) ($queue['skipped'] ?? 0);
            $summary['errors'] += (int) ($queue['failed'] ?? 0);

            $status = $summary['errors'] > 0 ? 'completed_with_errors' : 'completed';
            $summary['finished_at'] = gmdate('Y-m-d H:i:s');
            $this->save_checkpoint([
                'last_success_at' => gmdate('Y-m-d H:i:s'),
                'last_success_ts' => time(),
                'last_run_status' => $status,
                'last_error' => $summary['errors'] > 0 ? 'one_or_more_queue_items_failed' : '',
                'last_processed_counts' => $summary,
            ]);
            $this->set_global_status($status, $summary);
            $this->logger->info('EBAY_SYNC_DONE', $summary);
            return ['result' => $status === 'completed' ? 'success' : 'completed_with_errors', 'summary' => $summary];
        } catch (\Throwable $throwable) {
            $this->save_checkpoint(['last_run_status' => 'error', 'last_error' => $throwable->getMessage()]);
            $this->set_global_status('error', ['last_error' => $throwable->getMessage()]);
            $this->logger->error('EBAY_SYNC_PRODUCT_FAILED', ['stage' => 'sync_unhandled_exception', 'error' => $throwable->getMessage()]);
            return ['result' => 'error', 'error' => $throwable->getMessage()];
        } finally {
            $this->release_delta_lock();
            $this->logger->info('EBAY_SYNC_LOCK_RELEASED', ['lock' => self::DELTA_LOCK_KEY]);
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
        if (empty($suggestionDiagnostics['selected_candidate']) && (string) ($category['selected_candidate_category_id'] ?? '') !== '') {
            $suggestionDiagnostics['selected_candidate'] = [
                'category_id' => (string) ($category['selected_candidate_category_id'] ?? ''),
                'category_name' => (string) ($category['selected_candidate_category_name'] ?? ''),
                'category_path' => (string) ($category['selected_candidate_category_path'] ?? ''),
                'confidence' => (float) ($category['selected_candidate_confidence'] ?? 0),
                'source' => (string) ($category['selected_candidate_source'] ?? ''),
                'reason' => (string) ($category['sanity_reason'] ?? '') !== '' ? 'rejected: ' . (string) ($category['sanity_reason'] ?? '') : 'rejected',
            ];
        }
        $expectedCategoryKeywords = $this->expected_category_keywords_for_readiness_item($productTitle, $mapping, $content, (string) ($suggestionDiagnostics['detected_intent'] ?? ''));

        return [
            'product_id' => $productId,
            'product_title' => $productTitle,
            'edit_url' => (string) get_edit_post_link($productId, ''),
            'status' => $status,
            'primary_reason' => $this->primary_readiness_reason($status, $errors, $missingAspects, $message, $categoryReason),
            'errors' => $errors,
            'category_id' => (string) (($category['category_id'] ?? '') !== '' ? $category['category_id'] : ($mapping['ebay_category_id'] ?? '')),
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
            'expected_category_keywords' => $expectedCategoryKeywords,
            'missing_aspects' => $missingAspects,
            'required_aspects' => $requiredAspects,
            'price_ready' => !empty($priceResolution['ready']),
            'content_ready' => !empty($content['title']) && !empty($content['description']),
            'sku' => $product ? (string) $product->get_sku() : '',
            'ebay_sku' => (string) ($skuResolution['sku'] ?? ''),
        ];
    }


    private function expected_category_keywords_for_readiness_item(string $productTitle, array $mapping, array $content, string $detectedIntent): array
    {
        if ($detectedIntent !== '') {
            return CategoryMappingSafety::expected_keywords_for_intent($detectedIntent);
        }

        $sourceText = trim(implode(' ', array_filter([
            $productTitle,
            (string) ($mapping['woo_category_path'] ?? ''),
            (string) ($content['title'] ?? ''),
            (string) ($content['description'] ?? ''),
        ], static fn($value): bool => trim((string) $value) !== '')));

        return CategoryMappingSafety::expected_path_keywords($sourceText);
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
                    'category_name' => (string) ($candidate['category_name'] ?? $candidate['name'] ?? ''),
                    'category_path' => (string) ($candidate['category_path'] ?? $candidate['path'] ?? ''),
                    'confidence' => (float) ($candidate['confidence'] ?? $candidate['score'] ?? 0),
                    'reason' => (string) ($candidate['reason'] ?? (!empty($candidate['sanity_pass']) ? 'accepted' : 'rejected')),
                    'matched_keywords' => array_values(array_map('strval', (array) ($candidate['matched_keywords'] ?? []))),
                    'rejected_by_guard_reason' => (string) ($candidate['rejected_by_guard_reason'] ?? $candidate['sanity_reason'] ?? ''),
                    'sanity_reason' => (string) ($candidate['sanity_reason'] ?? ''),
                ];
            }, $topCandidates), 0, 5),
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
            'blocked_by_category_diagnostics' => !empty($this->settings()['verbose_debug']) ? $this->blocked_by_category_log_diagnostics((array) ($summary['blocked_by_category_items'] ?? [])) : [],
            'missing_required_aspects_sample_ids' => (array) ($summary['missing_required_aspects_sample_ids'] ?? []),
        ];
    }

    private function blocked_by_category_log_diagnostics(array $items): array
    {
        return array_map(static function (array $item): array {
            $selected = (array) ($item['selected_candidate'] ?? []);
            $topCandidates = array_slice((array) ($item['top_candidates'] ?? []), 0, 5);
            $decision = 'no_candidate';
            $reason = (string) ($item['category_sanity_reason'] ?? $item['mapping_error_reason'] ?? $item['primary_reason'] ?? '');
            if ((string) ($item['category_id'] ?? '') !== '') {
                $decision = 'accepted';
                $reason = 'accepted';
            } elseif ($selected !== [] || $topCandidates !== []) {
                $decision = 'rejected';
                if ($reason === '') {
                    $reason = (string) ($item['rejected_best_reason'] ?? 'category_mapping_requires_review');
                }
            }

            return [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'title' => (string) ($item['product_title'] ?? ''),
                'detected_intent' => (string) ($item['detected_intent'] ?? ''),
                'category_id' => (string) ($item['category_id'] ?? ''),
                'ebay_category_path' => (string) ($item['category_path'] ?? ''),
                'selected_candidate_category_id' => (string) ($selected['category_id'] ?? $item['best_candidate_category_id'] ?? ''),
                'selected_candidate_category_name' => (string) ($selected['category_name'] ?? $selected['name'] ?? $item['best_candidate_name'] ?? ''),
                'selected_candidate_category_path' => (string) ($selected['category_path'] ?? $selected['path'] ?? $item['best_candidate_path'] ?? ''),
                'selected_candidate_confidence' => (float) ($selected['confidence'] ?? $selected['score'] ?? 0),
                'selected_candidate_source' => (string) ($selected['source'] ?? ''),
                'top_taxonomy_candidates' => array_values(array_map(static function (array $candidate): array {
                    return [
                        'category_id' => (string) ($candidate['category_id'] ?? ''),
                        'category_name' => (string) ($candidate['category_name'] ?? $candidate['name'] ?? ''),
                        'category_path' => (string) ($candidate['category_path'] ?? $candidate['path'] ?? ''),
                        'confidence' => (float) ($candidate['confidence'] ?? $candidate['score'] ?? 0),
                        'reason' => (string) ($candidate['reason'] ?? (!empty($candidate['sanity_pass']) ? 'accepted' : 'rejected')),
                        'matched_keywords' => array_values(array_map('strval', (array) ($candidate['matched_keywords'] ?? []))),
                        'rejected_by_guard_reason' => (string) ($candidate['rejected_by_guard_reason'] ?? $candidate['sanity_reason'] ?? ''),
                    ];
                }, $topCandidates)),
                'final_decision' => ['decision' => $decision, 'reason' => $reason],
                'category_sanity_reason' => (string) ($item['category_sanity_reason'] ?? ''),
                'why_no_intent_match' => (string) ($item['why_no_intent_match'] ?? ''),
                'expected_keywords' => array_values(array_map('strval', (array) ($item['expected_category_keywords'] ?? []))),
            ];
        }, $items);
    }


    public function run_full_category_audit(bool $verboseDebug = false): array
    {
        $state = get_option(self::FULL_CATEGORY_AUDIT_STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        if (($state['status'] ?? '') !== 'in_progress') {
            $state = $this->new_full_category_audit_state($verboseDebug);
        } else {
            $state['verbose_debug'] = $verboseDebug || !empty($state['verbose_debug']);
        }

        $batchSize = self::FULL_CATEGORY_AUDIT_BATCH_SIZE;
        $ids = $this->product_ids_for_preflight_page((int) ($state['offset'] ?? 0), $batchSize);
        $processedThisBatch = 0;
        $verboseDebug = !empty($state['verbose_debug']);

        foreach ($ids as $productId) {
            $result = $this->adapter->preflight_product($productId, null, !$verboseDebug, false, [
                'audit_mode' => true,
                'suppress_side_effects' => true,
                'suppress_verbose_logs' => !$verboseDebug,
            ]);
            $item = $this->readiness_item($productId, $result);
            $auditStatus = $this->audit_status($result, $item);
            $reason = $this->audit_reason($result, $item, $auditStatus);
            $row = $this->audit_csv_row($productId, $result, $item, $auditStatus, $reason);
            $this->append_audit_tmp_row((string) $state['tmp_rows_path'], $row);

            $state['total_scanned'] = (int) ($state['total_scanned'] ?? 0) + 1;
            $state[$auditStatus . '_count'] = (int) ($state[$auditStatus . '_count'] ?? 0) + 1;
            $processedThisBatch++;

            if ($auditStatus !== 'ready') {
                $this->append_limited($state['sample_problem_product_ids'], $productId, self::READINESS_BUCKET_LIMIT);
                $reasonKey = $reason !== '' ? $reason : $auditStatus;
                $state['reason_counts'][$reasonKey] = (int) ($state['reason_counts'][$reasonKey] ?? 0) + 1;
                $intent = trim((string) ($item['detected_intent'] ?? ''));
                $intent = $intent !== '' ? $intent : 'unknown_intent';
                $state['intent_problem_counts'][$intent] = (int) ($state['intent_problem_counts'][$intent] ?? 0) + 1;
            }

            if ($verboseDebug) {
                $this->append_audit_tmp_row((string) $state['tmp_debug_path'], [
                    'product_id' => $productId,
                    'audit_status' => $auditStatus,
                    'reason' => $reason,
                    'readiness_item' => $item,
                    'preflight' => $result,
                ]);
            }
        }

        $state['offset'] = (int) ($state['offset'] ?? 0) + $processedThisBatch;
        $state['processed_this_batch'] = $processedThisBatch;
        $state['updated_at'] = gmdate('Y-m-d H:i:s');

        if ($processedThisBatch < $batchSize || (int) ($state['offset'] ?? 0) >= (int) ($state['total_products'] ?? 0)) {
            return $this->complete_full_category_audit($state);
        }

        update_option(self::FULL_CATEGORY_AUDIT_STATE_OPTION, $state, false);
        $summary = $this->full_category_audit_summary_from_state($state, 'in_progress');
        update_option('wei_ebay_full_category_audit_summary', $summary, false);
        if ($verboseDebug) {
            $this->logger->info('eBay full category audit batch completed', [
                'processed_this_batch' => $processedThisBatch,
                'processed' => (int) $summary['total_scanned'],
                'total' => (int) $summary['total_products'],
            ]);
        }

        return $summary;
    }

    private function new_full_category_audit_state(bool $verboseDebug): array
    {
        $startedAt = gmdate('Y-m-d H:i:s');
        $runSlug = 'wei-ebay-category-audit-' . gmdate('Ymd-His');
        $tmp = $this->full_category_audit_tmp_paths($runSlug);
        foreach ([$tmp['rows'], $tmp['debug']] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }

        return [
            'status' => 'in_progress',
            'result' => 'in_progress',
            'started_at' => $startedAt,
            'completed_at' => '',
            'updated_at' => $startedAt,
            'run_slug' => $runSlug,
            'verbose_debug' => $verboseDebug,
            'batch_size' => self::FULL_CATEGORY_AUDIT_BATCH_SIZE,
            'offset' => 0,
            'total_products' => $this->product_count_for_preflight(),
            'total_scanned' => 0,
            'ready_count' => 0,
            'blocked_by_category_count' => 0,
            'missing_category_count' => 0,
            'missing_required_aspects_count' => 0,
            'content_not_ready_count' => 0,
            'price_not_ready_count' => 0,
            'sample_problem_product_ids' => [],
            'reason_counts' => [],
            'intent_problem_counts' => [],
            'reports' => [],
            'tmp_rows_path' => $tmp['rows'],
            'tmp_debug_path' => $tmp['debug'],
        ];
    }

    private function full_category_audit_tmp_paths(string $runSlug): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        return [
            'rows' => trailingslashit($baseDir) . $runSlug . '-rows.tmp.ndjson',
            'debug' => trailingslashit($baseDir) . $runSlug . '-debug-products.tmp.ndjson',
        ];
    }

    private function append_audit_tmp_row(string $path, array $row): void
    {
        file_put_contents($path, wp_json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function complete_full_category_audit(array $state): array
    {
        $state['status'] = 'completed';
        $state['result'] = 'success';
        $state['completed_at'] = gmdate('Y-m-d H:i:s');
        $summary = $this->full_category_audit_summary_from_state($state, 'success');
        $summary['reports'] = $this->write_audit_reports_from_tmp((string) $state['run_slug'], (string) $state['tmp_rows_path'], (string) $state['tmp_debug_path'], $summary, !empty($state['verbose_debug']));
        update_option('wei_ebay_full_category_audit_summary', $summary, false);
        update_option(self::FULL_CATEGORY_AUDIT_STATE_OPTION, $state + ['reports' => $summary['reports']], false);
        $this->logger->info('eBay full category audit completed', [
            'processed' => (int) $summary['total_scanned'],
            'total' => (int) $summary['total_products'],
            'ready' => (int) $summary['ready_count'],
            'blocked' => (int) $summary['blocked_by_category_count'],
            'missing_category' => (int) $summary['missing_category_count'],
            'missing_aspects' => (int) $summary['missing_required_aspects_count'],
            'reports' => $summary['reports'],
        ]);
        return $summary;
    }

    private function full_category_audit_summary_from_state(array $state, string $result): array
    {
        $reasonCounts = (array) ($state['reason_counts'] ?? []);
        $intentProblemCounts = (array) ($state['intent_problem_counts'] ?? []);
        arsort($reasonCounts);
        arsort($intentProblemCounts);
        return [
            'result' => $result,
            'status' => (string) ($state['status'] ?? $result),
            'started_at' => (string) ($state['started_at'] ?? ''),
            'completed_at' => (string) ($state['completed_at'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'verbose_debug' => !empty($state['verbose_debug']),
            'batch_size' => (int) ($state['batch_size'] ?? self::FULL_CATEGORY_AUDIT_BATCH_SIZE),
            'processed_this_batch' => (int) ($state['processed_this_batch'] ?? 0),
            'processed' => (int) ($state['total_scanned'] ?? 0),
            'total_products' => (int) ($state['total_products'] ?? 0),
            'total_scanned' => (int) ($state['total_scanned'] ?? 0),
            'ready_count' => (int) ($state['ready_count'] ?? 0),
            'blocked_by_category_count' => (int) ($state['blocked_by_category_count'] ?? 0),
            'missing_category_count' => (int) ($state['missing_category_count'] ?? 0),
            'missing_required_aspects_count' => (int) ($state['missing_required_aspects_count'] ?? 0),
            'content_not_ready_count' => (int) ($state['content_not_ready_count'] ?? 0),
            'price_not_ready_count' => (int) ($state['price_not_ready_count'] ?? 0),
            'sample_problem_product_ids' => (array) ($state['sample_problem_product_ids'] ?? []),
            'top_10_sanity_reasons' => array_slice($reasonCounts, 0, 10, true),
            'top_10_detected_intents_with_problems' => array_slice($intentProblemCounts, 0, 10, true),
            'reports' => (array) ($state['reports'] ?? []),
        ];
    }

    private function audit_status(array $result, array $item): string
    {
        if (!empty($result['ready'])) {
            return 'ready';
        }

        $status = (string) ($result['status'] ?? 'not_ready');
        $category = is_array($result['category'] ?? null) ? $result['category'] : [];
        $categoryId = trim((string) ($category['category_id'] ?? $item['category_id'] ?? ''));
        $selected = (array) ($item['selected_candidate'] ?? []);
        $hasCandidate = trim((string) ($selected['category_id'] ?? $item['best_candidate_category_id'] ?? '')) !== '';
        $source = (string) ($category['source'] ?? '');
        if ($this->is_category_blocked_status($status) || $categoryId === '') {
            if ($status === 'unmapped' || ($categoryId === '' && !$hasCandidate) || $source === 'missing_category_mapping') {
                return 'missing_category';
            }
            return 'blocked_by_category';
        }
        if ($status === 'missing_required_aspects' || (array) ($item['missing_aspects'] ?? []) !== []) {
            return 'missing_required_aspects';
        }
        $errors = array_map('strtolower', array_map('strval', (array) ($result['errors'] ?? [])));
        if ($status === 'not_ready_missing_german_content' || empty($item['content_ready']) || $this->errors_contain_any($errors, ['title', 'description', 'german', 'image', 'content'])) {
            return 'content_not_ready';
        }
        if ($status === 'invalid_price' || $status === 'missing_exchange_rate' || empty($item['price_ready'])) {
            return 'price_not_ready';
        }

        return 'content_not_ready';
    }

    private function audit_reason(array $result, array $item, string $auditStatus): string
    {
        if ($auditStatus === 'ready') {
            return '';
        }
        if ($auditStatus === 'missing_required_aspects') {
            return 'missing_required_aspects: ' . implode(', ', (array) ($item['missing_aspects'] ?? []));
        }
        if ($auditStatus === 'price_not_ready') {
            $price = is_array($result['price_resolution'] ?? null) ? $result['price_resolution'] : [];
            return (string) ($price['error'] ?? $result['status'] ?? 'price_not_ready');
        }
        if ($auditStatus === 'blocked_by_category' || $auditStatus === 'missing_category') {
            return (string) ($item['category_sanity_reason'] ?? $item['mapping_error_reason'] ?? $item['primary_reason'] ?? $auditStatus);
        }
        return (string) ($item['primary_reason'] ?? $result['message'] ?? $auditStatus);
    }

    private function audit_csv_row(int $productId, array $result, array $item, string $auditStatus, string $reason): array
    {
        $category = is_array($result['category'] ?? null) ? $result['category'] : [];
        $mapping = is_array($category['mapping'] ?? null) ? $category['mapping'] : [];
        $selected = (array) ($item['selected_candidate'] ?? []);
        $proposedId = (string) ($selected['category_id'] ?? $item['best_candidate_category_id'] ?? $item['category_id'] ?? '');
        $proposedName = (string) ($selected['category_name'] ?? $selected['name'] ?? $item['best_candidate_name'] ?? '');
        $proposedPath = (string) ($selected['category_path'] ?? $selected['path'] ?? $item['best_candidate_path'] ?? '');
        if ($proposedId === '' && (string) ($item['category_id'] ?? '') !== '') {
            $proposedId = (string) $item['category_id'];
            $proposedName = (string) ($item['category_name'] ?? '');
            $proposedPath = (string) ($item['category_path'] ?? '');
        }

        $currentCategoryId = (string) ($item['category_id'] ?? '');
        if ($currentCategoryId === '') {
            $currentCategoryId = (string) ($mapping['ebay_category_id'] ?? '');
        }
        $currentCategoryName = (string) ($item['category_name'] ?? '');
        if ($currentCategoryName === '') {
            $currentCategoryName = (string) ($mapping['ebay_category_name'] ?? '');
        }
        $currentCategoryPath = (string) ($item['category_path'] ?? '');
        if ($currentCategoryPath === '') {
            $currentCategoryPath = (string) ($mapping['ebay_category_path'] ?? '');
        }

        return [
            'product_id' => $productId,
            'sku' => (string) ($item['sku'] ?? ''),
            'wei_ebay_sku' => (string) ($item['ebay_sku'] ?? ''),
            'title' => (string) ($item['product_title'] ?? ''),
            'woo_category_path' => $this->product_category_paths($productId),
            'detected_intent' => (string) ($item['detected_intent'] ?? ''),
            'current_ebay_category_id' => $currentCategoryId,
            'current_ebay_category_name' => $currentCategoryName,
            'current_ebay_category_path' => $currentCategoryPath,
            'mapping_status' => (string) ($item['mapping_status'] ?? $category['status'] ?? ''),
            'mapping_source' => (string) ($category['source'] ?? $mapping['source'] ?? ''),
            'mapping_confidence' => (string) ($category['confidence'] ?? $mapping['confidence'] ?? ''),
            'proposed_ebay_category_id' => $proposedId,
            'proposed_ebay_category_name' => $proposedName,
            'proposed_ebay_category_path' => $proposedPath,
            'status' => $auditStatus,
            'reason' => $reason,
            'missing_aspects' => implode('|', array_map('strval', (array) ($item['missing_aspects'] ?? []))),
            'required_aspects' => implode('|', array_map('strval', (array) ($item['required_aspects'] ?? []))),
            'top_3_candidates_json' => wp_json_encode(array_slice((array) ($item['top_candidates'] ?? []), 0, 3), JSON_UNESCAPED_UNICODE),
            'edit_url' => (string) ($item['edit_url'] ?? ''),
        ];
    }

    private function write_audit_reports_from_tmp(string $runSlug, string $rowsPath, string $debugPath, array $summary, bool $verboseDebug): array
    {
        $fullRows = [];
        $problemRows = [];
        $missingCategoryRows = [];
        $missingAspectsRows = [];
        if (is_readable($rowsPath)) {
            $fh = fopen($rowsPath, 'rb');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    $row = json_decode(trim($line), true);
                    if (!is_array($row)) {
                        continue;
                    }
                    $fullRows[] = $row;
                    $status = (string) ($row['status'] ?? '');
                    if ($status !== 'ready') {
                        $problemRows[] = $row;
                    }
                    if ($status === 'missing_category') {
                        $missingCategoryRows[] = $row;
                    }
                    if ($status === 'missing_required_aspects') {
                        $missingAspectsRows[] = $row;
                    }
                }
                fclose($fh);
            }
        }

        $debugDetails = [
            'started_at' => (string) ($summary['started_at'] ?? ''),
            'completed_at' => (string) ($summary['completed_at'] ?? ''),
            'verbose_debug' => $verboseDebug,
            'summary' => $summary,
        ];
        if ($verboseDebug && is_readable($debugPath)) {
            $debugDetails['products'] = [];
            $fh = fopen($debugPath, 'rb');
            if ($fh) {
                while (($line = fgets($fh)) !== false) {
                    $row = json_decode(trim($line), true);
                    if (is_array($row)) {
                        $debugDetails['products'][] = $row;
                    }
                }
                fclose($fh);
            }
        }

        return $this->write_audit_reports($runSlug, $fullRows, $problemRows, $missingCategoryRows, $missingAspectsRows, $debugDetails);
    }

    private function write_audit_reports(string $runSlug, array $fullRows, array $problemRows, array $missingCategoryRows, array $missingAspectsRows, array $debugDetails): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        if (!wp_mkdir_p($baseDir)) {
            return ['error' => 'failed_to_create_audit_upload_dir', 'dir' => $baseDir];
        }
        $reports = [];
        $reports['full_audit_csv'] = $this->write_csv_report($baseDir, $baseUrl, $runSlug . '-full.csv', $fullRows);
        $reports['problems_only_csv'] = $this->write_csv_report($baseDir, $baseUrl, $runSlug . '-problems.csv', $problemRows);
        $reports['missing_category_csv'] = $this->write_csv_report($baseDir, $baseUrl, $runSlug . '-missing-category.csv', $missingCategoryRows);
        $reports['missing_required_aspects_csv'] = $this->write_csv_report($baseDir, $baseUrl, $runSlug . '-missing-required-aspects.csv', $missingAspectsRows);
        $jsonPath = trailingslashit($baseDir) . $runSlug . '-debug.json';
        file_put_contents($jsonPath, wp_json_encode($debugDetails, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $reports['debug_json'] = ['path' => $jsonPath, 'url' => trailingslashit($baseUrl) . basename($jsonPath)];
        return $reports;
    }

    private function write_csv_report(string $baseDir, string $baseUrl, string $filename, array $rows): array
    {
        $path = trailingslashit($baseDir) . $filename;
        $fh = fopen($path, 'wb');
        if (!$fh) {
            return ['error' => 'failed_to_open_csv', 'path' => $path];
        }
        $headers = $rows !== [] ? array_keys($rows[0]) : [
            'product_id', 'sku', 'wei_ebay_sku', 'title', 'woo_category_path', 'detected_intent',
            'current_ebay_category_id', 'current_ebay_category_name', 'current_ebay_category_path',
            'mapping_status', 'mapping_source', 'mapping_confidence', 'proposed_ebay_category_id',
            'proposed_ebay_category_name', 'proposed_ebay_category_path', 'status', 'reason',
            'missing_aspects', 'required_aspects', 'top_3_candidates_json', 'edit_url',
        ];
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn($header) => (string) ($row[$header] ?? ''), $headers));
        }
        fclose($fh);
        return ['path' => $path, 'url' => trailingslashit($baseUrl) . $filename, 'rows' => count($rows)];
    }

    private function product_category_paths(int $productId): string
    {
        $terms = wp_get_post_terms($productId, 'product_cat');
        if (is_wp_error($terms)) {
            return '';
        }
        $paths = [];
        foreach ((array) $terms as $term) {
            if (is_object($term) && isset($term->term_id)) {
                $paths[] = $this->woo_category_path((int) $term->term_id);
            }
        }
        return implode(' | ', array_values(array_filter(array_unique($paths))));
    }

    private function woo_category_path(int $termId): string
    {
        $parts = [];
        $ancestors = array_reverse(get_ancestors($termId, 'product_cat'));
        $ancestors[] = $termId;
        foreach ($ancestors as $id) {
            $term = get_term((int) $id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $parts[] = (string) $term->name;
            }
        }
        return implode(' > ', $parts);
    }

    private function errors_contain_any(array $errors, array $needles): bool
    {
        foreach ($errors as $error) {
            foreach ($needles as $needle) {
                if (str_contains((string) $error, $needle)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function generate_missing_german_content_from_audit(int $batchSize = self::GERMAN_CONTENT_AUDIT_BATCH_SIZE, bool $restart = false): array
    {
        $batchSize = max(1, min(200, $batchSize));
        $state = get_option(self::GERMAN_CONTENT_AUDIT_STATE_OPTION, []);
        $state = is_array($state) ? $state : [];
        $problemsCsv = $this->latest_audit_report_path('problems_only_csv');
        if ($problemsCsv === '') {
            return ['result' => 'error', 'error' => 'problems_csv_not_found', 'message' => 'Run the full category audit first so the problems CSV can be used as the source of truth.'];
        }

        $fingerprint = md5($problemsCsv . '|' . (string) @filemtime($problemsCsv) . '|' . (string) @filesize($problemsCsv));
        if ($restart || ($state['status'] ?? '') !== 'in_progress' || (string) ($state['source_fingerprint'] ?? '') !== $fingerprint) {
            $state = $this->new_german_content_audit_state($problemsCsv, $fingerprint, $batchSize);
        } else {
            $state['batch_size'] = $batchSize;
        }

        $ids = $this->problem_csv_product_ids($problemsCsv, static function (array $row): bool {
            return (string) ($row['status'] ?? '') === 'content_not_ready'
                && trim((string) ($row['reason'] ?? '')) === 'missing_german_content';
        });
        $state['eligible_total'] = count($ids);
        $offset = max(0, (int) ($state['offset'] ?? 0));
        $batchIds = array_slice($ids, $offset, $batchSize);
        $diagnostics = [];
        $processedThisBatch = 0;

        foreach ($batchIds as $productId) {
            $result = $this->adapter->generate_german_content_meta_only((int) $productId);
            $processedThisBatch++;
            $state['processed'] = (int) ($state['processed'] ?? 0) + 1;
            $bucket = match ((string) ($result['result'] ?? 'failed')) {
                'generated' => 'generated',
                'already_ready' => 'already_ready',
                'skipped' => 'skipped',
                default => 'failed',
            };
            $state[$bucket] = (int) ($state[$bucket] ?? 0) + 1;
            $diagnostics[] = [
                'product_id' => (int) $productId,
                'result' => (string) ($result['result'] ?? 'failed'),
                'source' => (string) ($result['source'] ?? ''),
                'provider' => (string) ($result['provider'] ?? ''),
                'title_length' => (int) ($result['title_length'] ?? 0),
                'description_length' => (int) ($result['description_length'] ?? 0),
                'error_message' => (string) ($result['error_message'] ?? $result['reason'] ?? ''),
                'ebay_api_calls' => 'false',
                'published' => 'false',
                'offer_write_calls' => 'false',
                'wrote_woo_sku' => 'false',
                'wrote_woo_price' => 'false',
                'wrote_allegro' => 'false',
            ];
        }

        $state['offset'] = $offset + $processedThisBatch;
        $state['processed_this_batch'] = $processedThisBatch;
        $state['updated_at'] = gmdate('Y-m-d H:i:s');

        if ($processedThisBatch === 0 || (int) ($state['offset'] ?? 0) >= (int) ($state['eligible_total'] ?? 0)) {
            $state['status'] = 'completed';
            $state['result'] = ((int) ($state['failed'] ?? 0)) > 0 ? 'completed_with_errors' : 'success';
            $state['completed_at'] = gmdate('Y-m-d H:i:s');
        }

        $state['reports'] = $this->write_german_content_diagnostics($state, $diagnostics);
        update_option(self::GERMAN_CONTENT_AUDIT_STATE_OPTION, $state, false);
        $summary = $this->german_content_audit_summary($state);
        update_option('wei_ebay_german_content_audit_summary', $summary, false);
        $this->logger->info('German content audit batch completed', [
            'processed_this_batch' => $processedThisBatch,
            'processed' => (int) ($summary['processed'] ?? 0),
            'eligible_total' => (int) ($summary['eligible_total'] ?? 0),
            'generated' => (int) ($summary['generated'] ?? 0),
            'failed' => (int) ($summary['failed'] ?? 0),
            'status' => (string) ($summary['status'] ?? ''),
            'ebay_api_calls' => false,
            'published' => false,
            'offer_write_calls' => false,
            'wrote_woo_sku' => false,
            'wrote_woo_price' => false,
            'wrote_allegro' => false,
        ]);

        return $summary;
    }

    private function latest_audit_report_path(string $key): string
    {
        $summary = get_option('wei_ebay_full_category_audit_summary', []);
        $summary = is_array($summary) ? $summary : [];
        $reports = is_array($summary['reports'] ?? null) ? $summary['reports'] : [];
        $report = is_array($reports[$key] ?? null) ? $reports[$key] : [];
        $path = trim((string) ($report['path'] ?? ''));
        return $path !== '' && is_readable($path) ? $path : '';
    }

    private function new_german_content_audit_state(string $problemsCsv, string $fingerprint, int $batchSize): array
    {
        $startedAt = gmdate('Y-m-d H:i:s');
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        wp_mkdir_p($baseDir);
        $runSlug = 'wei-ebay-german-content-' . gmdate('Ymd-His');
        return [
            'status' => 'in_progress',
            'result' => 'in_progress',
            'started_at' => $startedAt,
            'completed_at' => '',
            'updated_at' => $startedAt,
            'run_slug' => $runSlug,
            'source_problems_csv' => $problemsCsv,
            'source_fingerprint' => $fingerprint,
            'batch_size' => $batchSize,
            'offset' => 0,
            'eligible_total' => 0,
            'processed' => 0,
            'processed_this_batch' => 0,
            'generated' => 0,
            'already_ready' => 0,
            'failed' => 0,
            'skipped' => 0,
            'diagnostics_csv_path' => trailingslashit($baseDir) . $runSlug . '-diagnostics.csv',
            'diagnostics_json_path' => trailingslashit($baseDir) . $runSlug . '-summary.json',
            'reports' => [],
        ];
    }

    private function german_content_audit_summary(array $state): array
    {
        return [
            'result' => (string) ($state['result'] ?? ''),
            'status' => (string) ($state['status'] ?? ''),
            'started_at' => (string) ($state['started_at'] ?? ''),
            'completed_at' => (string) ($state['completed_at'] ?? ''),
            'updated_at' => (string) ($state['updated_at'] ?? ''),
            'source_problems_csv' => (string) ($state['source_problems_csv'] ?? ''),
            'batch_size' => (int) ($state['batch_size'] ?? 0),
            'eligible_total' => (int) ($state['eligible_total'] ?? 0),
            'processed' => (int) ($state['processed'] ?? 0),
            'processed_this_batch' => (int) ($state['processed_this_batch'] ?? 0),
            'generated' => (int) ($state['generated'] ?? 0),
            'already_ready' => (int) ($state['already_ready'] ?? 0),
            'failed' => (int) ($state['failed'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'reports' => (array) ($state['reports'] ?? []),
            'safety' => [
                'ebay_api_calls' => false,
                'publish' => false,
                'offer_write_calls' => false,
                'woo_sku_changes' => false,
                'woo_price_changes' => false,
                'allegro_changes' => false,
            ],
        ];
    }

    private function write_german_content_diagnostics(array $state, array $rows): array
    {
        $csvPath = (string) ($state['diagnostics_csv_path'] ?? '');
        $jsonPath = (string) ($state['diagnostics_json_path'] ?? '');
        if ($csvPath !== '' && $rows !== []) {
            $exists = file_exists($csvPath) && filesize($csvPath) > 0;
            $fh = fopen($csvPath, 'ab');
            if ($fh) {
                $headers = array_keys($rows[0]);
                if (!$exists) {
                    fputcsv($fh, $headers);
                }
                foreach ($rows as $row) {
                    fputcsv($fh, array_map(static fn($header) => (string) ($row[$header] ?? ''), $headers));
                }
                fclose($fh);
            }
        }
        if ($jsonPath !== '') {
            file_put_contents($jsonPath, wp_json_encode($this->german_content_audit_summary($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-audits';
        $baseUrl = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-audits';
        return [
            'diagnostics_csv' => ['path' => $csvPath, 'url' => $csvPath !== '' ? trailingslashit($baseUrl) . basename($csvPath) : ''],
            'summary_json' => ['path' => $jsonPath, 'url' => $jsonPath !== '' ? trailingslashit($baseUrl) . basename($jsonPath) : ''],
        ];
    }

    private function problem_csv_product_ids(string $path, callable $filter): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $ids = [];
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
        while (($values = fgetcsv($fh)) !== false) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = (string) ($values[$index] ?? '');
            }
            if (!$filter($row)) {
                continue;
            }
            $productId = absint($row['product_id'] ?? 0);
            if ($productId > 0) {
                $ids[] = $productId;
            }
        }
        fclose($fh);
        return array_values(array_unique($ids));
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
        $this->queue_product_change($postId, 'content_changed', 'save_post_product');
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
        $this->queue_product_change($productId, 'stock_changed', $source);
        $this->logger->info('Woo stock change queued for eBay sync', ['source' => $source, 'product_id' => $productId, 'wrote_allegro' => false]);
        $this->schedule_stock_queue_once();
    }

    public function queue_new_product_sync(int $productId): void
    {
        $this->queue_product_change($productId, 'publish_requested', 'woocommerce_new_product');
    }

    public function queue_updated_product_sync(int $productId): void
    {
        $this->queue_product_change($productId, 'content_changed', 'woocommerce_update_product');
        $this->queue_product_change($productId, 'price_changed', 'woocommerce_update_product');
    }

    public function queue_product_price_sync($product): void
    {
        if (is_object($product) && method_exists($product, 'get_id')) {
            $this->queue_product_change((int) $product->get_id(), 'price_changed', 'woocommerce_price_change');
        }
    }

    public function queue_product_change(int $productId, string $reason, string $source = 'manual'): void
    {
        if ($productId <= 0 || (get_post_type($productId) !== 'product' && get_post_type($productId) !== 'product_variation')) {
            return;
        }
        $allowed = ['stock_changed', 'price_changed', 'content_changed', 'category_changed', 'publish_requested'];
        if (!in_array($reason, $allowed, true)) {
            $reason = 'content_changed';
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $now = gmdate('Y-m-d H:i:s');
        $existingId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE product_id=%d AND reason=%s LIMIT 1", $productId, $reason));
        $data = ['status' => 'pending', 'queued_at' => $now, 'updated_at' => $now, 'last_error' => '', 'source' => $source];
        if ($existingId > 0) {
            $wpdb->update($table, $data, ['id' => $existingId]);
        } else {
            $wpdb->insert($table, $data + ['product_id' => $productId, 'reason' => $reason, 'attempts' => 0]);
        }
        update_post_meta($productId, '_wei_ebay_last_sync_status', 'queued_' . $reason);
        update_post_meta($productId, '_wei_ebay_sync_queued_at', $now);
        $this->logger->info('EBAY_SYNC_PRODUCT_QUEUED', ['product_id' => $productId, 'reason' => $reason, 'source' => $source]);
    }

    public function process_change_queue(int $batchSize = self::QUEUE_BATCH_SIZE, bool $withLock = true): array
    {
        if ($withLock && !$this->acquire_delta_lock('ebay_queue_sync')) {
            $this->logger->warning('EBAY_SYNC_LOCK_ALREADY_RUNNING', ['lock' => self::DELTA_LOCK_KEY, 'mode' => 'ebay_queue_sync']);
            return ['result' => 'skipped', 'reason' => 'locked', 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
        }
        try {
            $rows = $this->queue_rows(max(1, min(100, $batchSize)));
            $summary = ['result' => 'success', 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'skipped' => 0];
            foreach ($rows as $row) {
                $summary['processed']++;
                $result = $this->process_queue_row($row);
                $bucket = (string) ($result['bucket'] ?? 'failed');
                if (isset($summary[$bucket])) {
                    $summary[$bucket]++;
                }
            }
            update_option('wei_ebay_queue_summary', $summary + ['last_run' => gmdate('Y-m-d H:i:s'), 'queued' => self::queue_count('pending'), 'failed_count' => self::queue_count('failed')], false);
            return $summary;
        } finally {
            if ($withLock) {
                $this->release_delta_lock();
                $this->logger->info('EBAY_SYNC_LOCK_RELEASED', ['lock' => self::DELTA_LOCK_KEY, 'mode' => 'ebay_queue_sync']);
            }
        }
    }

    public function rebuild_queue_for_ready_products(int $batchSize = self::QUEUE_BATCH_SIZE): array
    {
        $ids = $this->product_ids_by_export_status('ready', max(1, min(100, $batchSize)));
        foreach ($ids as $productId) {
            $this->queue_product_change((int) $productId, 'publish_requested', 'rebuild_ready_products');
        }
        return ['result' => 'success', 'queued' => count($ids), 'batch_size' => max(1, min(100, $batchSize))];
    }

    private function process_queue_row(array $row): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $id = (int) ($row['id'] ?? 0);
        $productId = (int) ($row['product_id'] ?? 0);
        $reason = (string) ($row['reason'] ?? 'content_changed');
        $attempts = (int) ($row['attempts'] ?? 0);
        $wpdb->update($table, ['status' => 'processing', 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);

        try {
            $settings = $this->settings();
            if ($reason === 'stock_changed') {
                $res = $this->adapter->sync_stock($productId);
            } elseif ($reason === 'publish_requested' && empty($settings['auto_publish_enabled']) && (string) ($row['source'] ?? '') !== 'manual_publish_requested') {
                $res = ['result' => 'skipped', 'reason' => 'auto_publish_disabled'];
            } elseif (in_array($reason, ['price_changed', 'content_changed', 'category_changed'], true) && !$this->has_active_ebay_listing($productId)) {
                $res = ['result' => 'skipped', 'reason' => 'no_active_ebay_listing'];
            } else {
                $res = $this->adapter->export_product($productId);
            }

            if (($res['result'] ?? '') === 'success') {
                $wpdb->update($table, ['status' => 'done', 'updated_at' => gmdate('Y-m-d H:i:s'), 'last_error' => ''], ['id' => $id]);
                update_post_meta($productId, '_wei_ebay_last_synced_at', gmdate('Y-m-d H:i:s'));
                update_post_meta($productId, '_wei_ebay_last_sync_status', 'synced_' . $reason);
                delete_post_meta($productId, '_wei_ebay_last_sync_error');
                $this->logger->info('EBAY_SYNC_PRODUCT_PROCESSED', ['product_id' => $productId, 'reason' => $reason, 'result' => 'success']);
                return ['bucket' => 'succeeded'];
            }

            if (($res['result'] ?? '') === 'skipped') {
                $wpdb->update($table, ['status' => 'done', 'updated_at' => gmdate('Y-m-d H:i:s'), 'last_error' => (string) ($res['reason'] ?? 'skipped')], ['id' => $id]);
                update_post_meta($productId, '_wei_ebay_last_sync_status', 'skipped_' . $reason);
                $this->logger->info('EBAY_SYNC_PRODUCT_PROCESSED', ['product_id' => $productId, 'reason' => $reason, 'result' => 'skipped', 'skip_reason' => (string) ($res['reason'] ?? '')]);
                return ['bucket' => 'skipped'];
            }

            return $this->mark_queue_row_failed($id, $productId, $reason, $attempts, (string) ($res['error'] ?? $res['message'] ?? 'sync_error'));
        } catch (\Throwable $throwable) {
            return $this->mark_queue_row_failed($id, $productId, $reason, $attempts, $throwable->getMessage());
        }
    }


    private function has_active_ebay_listing(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        $listingId = trim((string) get_post_meta($productId, '_wei_ebay_listing_id', true));
        if ($listingId === '') {
            $listingId = trim((string) get_post_meta($productId, '_wei_ebay_item_id', true));
        }
        $offerId = trim((string) get_post_meta($productId, '_wei_ebay_offer_id', true));
        if ($listingId !== '' && $offerId !== '') {
            return true;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT remote_offer_id, remote_listing_id, status FROM {$table} WHERE marketplace=%s AND (woo_product_id=%d OR woo_variation_id=%d) ORDER BY updated_at DESC LIMIT 1",
            'ebay',
            $productId,
            $productId
        ), ARRAY_A);
        if (!is_array($row)) {
            return false;
        }

        $mappingOfferId = trim((string) ($row['remote_offer_id'] ?? ''));
        $mappingListingId = trim((string) ($row['remote_listing_id'] ?? ''));
        $mappingStatus = strtolower(trim((string) ($row['status'] ?? '')));

        return $mappingOfferId !== ''
            && $mappingListingId !== ''
            && !in_array($mappingStatus, ['ended', 'deleted', 'inactive', 'failed'], true);
    }

    private function mark_queue_row_failed(int $id, int $productId, string $reason, int $attempts, string $error): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $attempts++;
        $status = $attempts >= 3 ? 'failed' : 'pending';
        $wpdb->update($table, ['status' => $status, 'attempts' => $attempts, 'last_error' => $error, 'updated_at' => gmdate('Y-m-d H:i:s')], ['id' => $id]);
        update_post_meta($productId, '_wei_ebay_last_sync_status', $status . '_' . $reason);
        update_post_meta($productId, '_wei_ebay_last_sync_error', $error);
        $this->logger->error('EBAY_SYNC_PRODUCT_FAILED', ['product_id' => $productId, 'reason' => $reason, 'attempts' => $attempts, 'status' => $status, 'error' => $error]);
        return ['bucket' => 'failed'];
    }

    private function queue_rows(int $limit): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE status IN ('pending','retry') ORDER BY queued_at ASC, id ASC LIMIT %d", $limit);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public static function queue_count(string $status = ''): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_sync_queue';
        if ($status !== '') {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status=%s", $status));
        }
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    private function sync_listing_meta_delta(int $limit): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'marketplace_mappings';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT woo_product_id, woo_variation_id, remote_offer_id, remote_listing_id FROM {$table} WHERE marketplace=%s AND (remote_offer_id<>'' OR remote_listing_id<>'') ORDER BY updated_at DESC LIMIT %d", 'ebay', max(1, min(100, $limit))), ARRAY_A);
        $checked = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $productId = (int) ($row['woo_variation_id'] ?: $row['woo_product_id']);
            if ($productId <= 0) {
                continue;
            }
            $listingId = (string) ($row['remote_listing_id'] ?? '');
            update_post_meta($productId, '_wei_ebay_offer_id', (string) ($row['remote_offer_id'] ?? ''));
            update_post_meta($productId, '_wei_ebay_listing_id', $listingId);
            update_post_meta($productId, '_wei_ebay_item_id', $listingId);
            if ($listingId !== '') {
                update_post_meta($productId, '_wei_ebay_public_url', 'https://www.ebay.de/itm/' . rawurlencode($listingId));
                update_post_meta($productId, '_wei_ebay_listing_status', 'mapped');
            }
            update_post_meta($productId, '_wei_ebay_last_synced_at', gmdate('Y-m-d H:i:s'));
            $checked++;
        }
        return ['checked' => $checked];
    }

    private function get_checkpoint(): array
    {
        $checkpoint = get_option(self::CHECKPOINT_OPTION, []);
        $checkpoint = is_array($checkpoint) ? $checkpoint : [];
        return $checkpoint + [
            'last_ebay_order_sync_at' => '',
            'last_ebay_inventory_sync_at' => '',
            'last_ebay_offer_sync_at' => '',
            'last_success_at' => '',
            'last_success_ts' => 0,
            'last_run_status' => '',
            'last_error' => '',
            'last_processed_counts' => [],
        ];
    }

    private function save_checkpoint(array $updates): void
    {
        $checkpoint = array_merge($this->get_checkpoint(), $updates);
        update_option(self::CHECKPOINT_OPTION, $checkpoint, false);
        $this->logger->info('EBAY_SYNC_CHECKPOINT_SAVED', $this->compact_checkpoint($checkpoint));
    }

    private function compact_checkpoint(array $checkpoint): array
    {
        return array_intersect_key($checkpoint, array_flip(['last_ebay_order_sync_at', 'last_ebay_inventory_sync_at', 'last_ebay_offer_sync_at', 'last_success_at', 'last_success_ts', 'last_run_status', 'last_error']));
    }

    private function acquire_delta_lock(string $owner): bool
    {
        $lock = get_option(self::DELTA_LOCK_KEY, []);
        if (is_array($lock) && (int) ($lock['expires_at'] ?? 0) > time()) {
            return false;
        }
        update_option(self::DELTA_LOCK_KEY, ['owner' => $owner, 'started_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => time() + self::LOCK_TTL], false);
        return true;
    }

    private function release_delta_lock(): void
    {
        delete_option(self::DELTA_LOCK_KEY);
    }

    private function queue_batch_size(array $settings): int
    {
        return max(1, min(100, (int) ($settings['ebay_delta_queue_batch_size'] ?? $settings['auto_sync_stock_batch_size'] ?? self::QUEUE_BATCH_SIZE)));
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
        $next = function_exists('as_next_scheduled_action') ? as_next_scheduled_action(self::HOOK_DELTA_SYNC, [], self::CRON_GROUP) : false;
        if (!$next) {
            $next = wp_next_scheduled(self::HOOK_DELTA_SYNC);
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
            'queued_products_count' => self::queue_count('pending'),
            'failed_queue_count' => self::queue_count('failed'),
            'checkpoint' => get_option(self::CHECKPOINT_OPTION, []),
            'hook' => self::HOOK_DELTA_SYNC,
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
        return $this->product_ids_for_preflight_page(0, $limit < 0 ? -1 : max(1, $limit));
    }

    private function product_ids_for_preflight_page(int $offset, int $limit): array
    {
        return array_map('intval', get_posts([
            'post_type' => 'product',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => $limit < 0 ? -1 : max(1, $limit),
            'offset' => max(0, $offset),
            'orderby' => 'ID',
            'order' => 'ASC',
        ]));
    }

    private function product_count_for_preflight(): int
    {
        $counts = wp_count_posts('product');
        return is_object($counts) ? (int) ($counts->publish ?? 0) : 0;
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
            'auto_sync_frequency' => 'every_15_minutes',
            'auto_sync_export_batch_size' => 20,
            'auto_sync_preflight_batch_size' => 200,
            'woo_to_ebay_stock_sync_enabled' => 1,
            'ebay_order_sync_enabled' => 1,
            'ebay_stock_sync_mode' => 'max_one',
            'ebay_order_stock_update_mode' => 'set_zero',
            'auto_export_enabled' => 0,
            'auto_publish_enabled' => 0,
            'ebay_delta_queue_batch_size' => self::QUEUE_BATCH_SIZE,
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
