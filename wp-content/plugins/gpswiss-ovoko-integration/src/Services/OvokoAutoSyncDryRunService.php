<?php

namespace GPSwiss\Ovoko\Services;

class OvokoAutoSyncDryRunService
{
    public const STATUS_OPTION = 'gpswiss_ovoko_auto_sync_status';
    public const LOCK_OPTION = 'gpswiss_ovoko_auto_sync_lock';
    public const SALE_QUEUE_OPTION = 'gpswiss_ovoko_sale_sync_queue_design_status';

    public function __construct(private OvokoIntegrationService $integrationService)
    {
    }

    public static function parse_internal_notes_price(mixed $internalNotes): array
    {
        if (!is_scalar($internalNotes)) {
            return [
                'ok' => false,
                'warning' => 'missing_price_in_internal_notes',
                'price' => null,
                'currency' => 'PLN',
                'raw' => '',
                'matched_text' => '',
                'supported_formats' => self::supported_price_formats(),
            ];
        }

        $raw = trim((string) $internalNotes);
        if ($raw === '') {
            return [
                'ok' => false,
                'warning' => 'missing_price_in_internal_notes',
                'price' => null,
                'currency' => 'PLN',
                'raw' => $raw,
                'matched_text' => '',
                'supported_formats' => self::supported_price_formats(),
            ];
        }

        $patterns = [
            '/(?:^|[^\p{L}\p{N}])(?:cena|price|woo|sklep|www)\s*[:=\-]?\s*(\d{1,7}(?:[\s\x{00A0}]\d{3})*(?:[,.]\d{1,2})?|\d{1,7}(?:[,.]\d{1,2})?)\s*(?:zł|zl|pln)?(?:$|[^\p{L}\p{N}])/iu',
            '/(?:^|[^\p{L}\p{N}])(\d{1,7}(?:[\s\x{00A0}]\d{3})*(?:[,.]\d{1,2})?|\d{1,7}(?:[,.]\d{1,2})?)\s*(?:zł|zl|pln)(?:$|[^\p{L}\p{N}])/iu',
            '/^\s*(\d{1,7}(?:[\s\x{00A0}]\d{3})*(?:[,.]\d{1,2})?|\d{1,7}(?:[,.]\d{1,2})?)\s*$/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $raw, $matches)) {
                continue;
            }
            $matched = (string) ($matches[1] ?? '');
            $normalized = str_replace(["\xC2\xA0", ' '], '', $matched);
            $normalized = str_replace(',', '.', $normalized);
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
                return self::invalid_price($raw, $matched, 'Price has more than two decimal places or contains unsupported separators.');
            }
            $value = (float) $normalized;
            if ($value <= 0) {
                return self::invalid_price($raw, $matched, 'Price must be greater than 0.');
            }
            return [
                'ok' => true,
                'warning' => '',
                'price' => number_format($value, 2, '.', ''),
                'currency' => 'PLN',
                'raw' => $raw,
                'matched_text' => $matched,
                'supported_formats' => self::supported_price_formats(),
            ];
        }

        return [
            'ok' => false,
            'warning' => strpbrk($raw, '0123456789') === false ? 'missing_price_in_internal_notes' : 'invalid_internal_notes_price',
            'price' => null,
            'currency' => 'PLN',
            'raw' => $raw,
            'matched_text' => '',
            'error' => 'No supported Woo price format found in internal_notes. Price will not be overwritten.',
            'supported_formats' => self::supported_price_formats(),
        ];
    }

    public static function supported_price_formats(): array
    {
        return [
            '250',
            '250.50',
            '250,50',
            'Cena: 250 zł',
            'Woo=250.50 PLN',
            'sklep - 1 250,00 zł',
            'www 1250',
        ];
    }

    private static function invalid_price(string $raw, string $matched, string $error): array
    {
        return [
            'ok' => false,
            'warning' => 'invalid_internal_notes_price',
            'price' => null,
            'currency' => 'PLN',
            'raw' => $raw,
            'matched_text' => $matched,
            'error' => $error,
            'supported_formats' => self::supported_price_formats(),
        ];
    }

    public function dry_run_ovoko_to_woo(array $options = []): array
    {
        $limit = max(1, min(25, (int) ($options['batch_size'] ?? 5)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $partId = trim((string) ($options['part_id'] ?? ''));
        $client = new RrrApiClient($this->integrationService->get_settings());
        $fetch = $partId !== '' ? $client->preview_fetch_single_part((int) $partId) : $client->preview_fetch_parts_sample($limit, $page);
        $records = [];
        if ($partId !== '') {
            $payload = (array) ($fetch['payload'] ?? []);
            $record = $client->extract_single_part_record($payload);
            if ($record !== []) {
                $records[] = ['record' => $record, 'payload' => $payload];
            }
        } else {
            foreach ((array) ($fetch['records'] ?? []) as $record) {
                if (is_array($record)) {
                    $records[] = ['record' => $record, 'payload' => $record];
                }
            }
        }

        $report = $this->empty_ovoko_to_woo_report($limit, $page);
        $report['source_fetch'] = [
            'ok' => !empty($fetch['ok']),
            'mode' => $partId !== '' ? 'single_part' : 'parts_page',
            'part_id' => $partId,
            'status_code' => (string) ($fetch['status_code'] ?? ''),
            'pagination' => (array) ($fetch['pagination'] ?? []),
        ];

        foreach ($records as $row) {
            $item = $this->build_ovoko_to_woo_item_report((array) $row['record'], (array) $row['payload'], $client);
            $report['items'][] = $item;
            $report['processed']++;
            foreach ($report['counts'] as $key => $_) {
                if (!empty($item[$key])) {
                    $report['counts'][$key]++;
                }
            }
            foreach ((array) ($item['warnings'] ?? []) as $warning) {
                $report['warnings'][$warning] = ($report['warnings'][$warning] ?? 0) + 1;
            }
        }

        $report['status'] = 'idle';
        $report['last_cursor'] = ['page' => $page, 'next_page' => $page + 1, 'updated_after' => (string) ($options['updated_after'] ?? '')];
        $report['checked_at'] = gmdate('c');
        update_option(self::STATUS_OPTION, $this->summarize_status($report), false);
        return $report;
    }

    public function dry_run_woo_to_ovoko_sale(int $orderId): array
    {
        $status = $this->get_sale_sync_status();
        $result = [
            'ok' => true,
            'action_name' => 'Dry-run Woo → Ovoko sale/stock sync',
            'mode' => 'dry_run_no_ovoko_write',
            'order_id' => $orderId,
            'endpoint_confirmed' => false,
            'recommended_endpoint_policy' => 'Current client only contains a confirmed write probe for /crm/updatePart place updates; sale/stock/status write endpoint is not confirmed and must remain disabled until Ovoko/RRR confirms the contract.',
            'candidate_endpoints_to_confirm_with_ovoko' => ['/crm/updatePart', '/crm/create/order', '/crm/sellPart', '/crm/reservePart', '/v2/update/part'],
            'idempotency_meta_keys' => $this->sale_sync_meta_keys(),
            'queue_design' => $this->sale_queue_design(),
            'conflict_policy' => $this->conflict_policy(),
            'items' => [],
            'status_panel' => $status,
            'no_ovoko_write' => true,
            'no_woo_write' => true,
            'checked_at' => gmdate('c'),
        ];

        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            $result['ok'] = $orderId <= 0;
            $result['warning'] = $orderId <= 0 ? 'missing_order_id' : 'woocommerce_unavailable';
            return $result;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            $result['ok'] = false;
            $result['warning'] = 'order_not_found';
            return $result;
        }

        foreach ($order->get_items() as $itemId => $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $productId = $product ? (int) $product->get_id() : 0;
            $ovokoId = $productId > 0 ? $this->get_first_product_meta($productId, ['_ovoko_part_id', 'ovoko_part_id', 'part_id']) : '';
            $alreadySynced = wc_get_order_item_meta((int) $itemId, 'ovoko_sale_sync_status', true) === 'success';
            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
            $result['items'][] = [
                'order_item_id' => (int) $itemId,
                'product_id' => $productId,
                'sku' => $product ? (string) $product->get_sku() : '',
                'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
                'quantity' => $qty,
                'quantity_warning' => $qty > 1 ? 'quantity_greater_than_1_requires_confirmed_ovoko_partial_stock_contract' : '',
                'ovoko_id' => $ovokoId,
                'has_ovoko_id' => $ovokoId !== '',
                'already_synced' => $alreadySynced,
                'would_enqueue' => $ovokoId !== '' && !$alreadySynced,
                'would_send_payload' => [
                    'part_id' => $ovokoId,
                    'order_id' => $orderId,
                    'order_item_id' => (int) $itemId,
                    'quantity' => $qty,
                    'request_id' => 'woo-' . $orderId . '-item-' . (int) $itemId,
                ],
                'endpoint_would_use' => 'not_configured_waiting_for_ovoko_rrr_confirmation',
            ];
        }

        return $result;
    }

    public function endpoint_capability_analysis(): array
    {
        $client = new RrrApiClient($this->integrationService->get_settings());
        $sample = $client->preview_fetch_parts_sample(1, 1);
        $deltaProbe = $client->probe_parts_delta_filter_support();
        $record = (array) (($sample['records'][0] ?? []));
        $fields = array_values(array_map('strval', array_keys($record)));
        $hasUpdatedAt = array_key_exists('updated_at', $record);
        $hasReserved = array_key_exists('reserved_user', $record) || array_key_exists('reserved_date', $record);

        return [
            'ok' => true,
            'action_name' => 'Ovoko/RRR endpoint capability analysis for safe cron',
            'read_endpoints_confirmed_in_client' => ['/v2/get/parts?limit={n}&page={p}', '/get/part/{part_id}', '/get/categories/tree', '/v2/get/parts/categories'],
            'write_endpoint_seen_in_client' => ['/crm/updatePart' => 'Only tested for place update; not approved for sale/stock/status sync.'],
            'parts_page_probe' => [
                'ok' => !empty($sample['ok']),
                'status_code' => (string) ($sample['status_code'] ?? ''),
                'pagination' => (array) ($sample['pagination'] ?? []),
                'sample_fields' => $fields,
                'updated_at_field_present_in_sample' => $hasUpdatedAt,
                'reserved_fields_present_in_sample' => $hasReserved,
            ],
            'near_realtime_assessment' => [
                'updated_at_available_in_payload' => $hasUpdatedAt,
                'from_or_updated_after_filter_confirmed' => false,
                'delta_filter_probe' => $deltaProbe,
                'recommendation' => $hasUpdatedAt
                    ? 'Use page-based scans now and ask Ovoko/RRR to confirm updated_after/from filtering before enabling true delta sync.'
                    : 'No delta field confirmed in sample; use full paginated scan with small batches until Ovoko/RRR confirms a change feed.',
                'cron_frequency' => 'Start every 10–15 minutes in dry-run/log mode; reduce to 5 minutes only after API latency/error rate is stable.',
                'batch_size' => '10–25 products per request on shared hosting; one batch per cron tick with lock.',
            ],
            'unavailable_product_policy_recommendation' => $this->unavailable_policy(),
            'no_write_to_woo' => true,
            'no_write_to_ovoko' => true,
            'checked_at' => gmdate('c'),
        ];
    }

    private function build_ovoko_to_woo_item_report(array $record, array $payload, RrrApiClient $client): array
    {
        $partId = trim((string) ($record['id'] ?? $record['part_id'] ?? ''));
        $normalized = $client->normalize_rrr_single_part_payload($payload);
        if (($normalized['part_id'] ?? '') === '' && $partId !== '') {
            $normalized['part_id'] = $partId;
        }
        $productId = $this->find_product_id_by_ovoko_id($partId);
        $product = $productId > 0 && function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $warnings = [];

        $price = self::parse_internal_notes_price($record['internal_notes'] ?? null);
        if (empty($price['ok'])) {
            $warnings[] = (string) ($price['warning'] ?: 'missing_price_in_internal_notes');
        }

        $incomingDescription = trim((string) (($record['notes'] ?? '') ?: ($normalized['notes'] ?? '')));
        $currentDescription = $productId > 0 ? (string) get_post_field('post_content', $productId) : '';
        if ($incomingDescription === '') {
            $warnings[] = 'empty_ovoko_description';
        }

        $incomingDetails = $this->integrationService->preview_ovoko_technical_attributes_from_normalized($normalized);
        $currentDetails = $productId > 0 ? $this->get_current_product_details_summary($productId) : [];
        if ($incomingDetails === []) {
            $warnings[] = empty($record) ? 'details_missing_from_ovoko' : 'details_empty_from_ovoko';
        }

        $categoryId = trim((string) ($record['category_id'] ?? $normalized['category_id'] ?? ''));
        if ($categoryId === '') {
            $warnings[] = 'missing_category_id';
        }
        $categoryPath = (string) ($normalized['category_title_path'] ?? '');
        if ($categoryPath === '' && $categoryId !== '') {
            $tree = $client->resolve_category_path_from_tree((int) $categoryId);
            $categoryPath = (string) ($tree['category_tree_resolved_path'] ?? '');
        }

        $imageCount = count((array) ($normalized['part_photo_gallery'] ?? []));
        $existingImageCount = $product ? $this->get_product_image_count($product) : 0;
        if ($productId > 0 && $existingImageCount === 0) {
            $warnings[] = 'existing_product_missing_images';
        }

        $stock = $this->stock_status_preview((string) ($record['status'] ?? $normalized['status'] ?? ''));
        if (!empty($stock['products_unavailable_in_ovoko'])) {
            $warnings[] = 'products_unavailable_in_ovoko';
        }

        $currentPrice = $product ? (string) $product->get_regular_price() : '';
        $currentStockStatus = $product ? (string) $product->get_stock_status() : '';
        $incomingDetailsHash = $this->stable_hash($incomingDetails);
        $currentDetailsHash = $this->stable_hash($currentDetails);
        $changedFields = $this->changed_assoc_keys($currentDetails, $incomingDetails);

        return [
            'part_id' => $partId,
            'product_id' => $productId,
            'product_exists' => $productId > 0,
            'products_would_create' => $productId <= 0,
            'products_would_update' => $productId > 0,
            'products_would_skip' => false,
            'title' => (string) ($record['name'] ?? $normalized['title'] ?? ''),
            'price_from_internal_notes_ok' => !empty($price['ok']),
            'price_missing_in_internal_notes' => ($price['warning'] ?? '') === 'missing_price_in_internal_notes',
            'price_invalid_internal_notes' => ($price['warning'] ?? '') === 'invalid_internal_notes_price',
            'price' => ['current_woo' => $currentPrice, 'incoming_from_internal_notes' => $price],
            'stock_would_update' => $productId <= 0 || $currentStockStatus !== $stock['target_stock_status'],
            'stock' => $stock + ['current_woo_stock_status' => $currentStockStatus],
            'products_unavailable_in_ovoko' => !empty($stock['products_unavailable_in_ovoko']),
            'descriptions_would_update' => $incomingDescription !== '' && $this->stable_hash($currentDescription) !== $this->stable_hash($incomingDescription),
            'descriptions_empty_from_ovoko' => $incomingDescription === '',
            'description' => [
                'current_length' => mb_strlen($currentDescription),
                'current_hash' => $this->stable_hash($currentDescription),
                'incoming_length' => mb_strlen($incomingDescription),
                'incoming_hash' => $this->stable_hash($incomingDescription),
                'sample_excerpt' => mb_substr(wp_strip_all_tags($incomingDescription), 0, 180),
            ],
            'details_would_update' => $incomingDetails !== [] && $currentDetailsHash !== $incomingDetailsHash,
            'details_missing_from_ovoko' => empty($record),
            'details_empty_from_ovoko' => $incomingDetails === [],
            'details' => [
                'current_details_hash' => $currentDetailsHash,
                'incoming_details_hash' => $incomingDetailsHash,
                'current_details_summary' => array_slice($currentDetails, 0, 8, true),
                'incoming_details_summary' => array_slice($incomingDetails, 0, 8, true),
                'details_fields_changed' => $changedFields,
                'details_fields_sample' => array_slice($incomingDetails, 0, 8, true),
            ],
            'details_fields_changed' => $changedFields !== [],
            'details_fields_sample' => array_slice($incomingDetails, 0, 5, true),
            'categories_would_update' => $categoryId !== '',
            'category' => ['category_id' => $categoryId, 'resolved_path_from_tree_or_payload' => $categoryPath, 'uses_old_csv' => false, 'uses_root_motoryzacja' => false, 'path_shortened' => false],
            'existing_products_images_untouched' => $productId > 0,
            'new_products_images_would_import' => $productId <= 0 && $imageCount > 0,
            'existing_products_missing_images_warning' => $productId > 0 && $existingImageCount === 0,
            'images' => ['existing_woo_image_count' => $existingImageCount, 'incoming_ovoko_image_count' => $imageCount, 'policy' => $productId > 0 ? 'do_not_touch_existing_images' : 'would_import_for_new_product_only'],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function empty_ovoko_to_woo_report(int $limit, int $page): array
    {
        $keys = ['existing_products_images_untouched','new_products_images_would_import','existing_products_missing_images_warning','descriptions_would_update','descriptions_empty_from_ovoko','details_would_update','details_missing_from_ovoko','details_empty_from_ovoko','details_fields_changed','price_from_internal_notes_ok','price_missing_in_internal_notes','price_invalid_internal_notes','stock_would_update','categories_would_update','products_would_create','products_would_update','products_would_skip','products_unavailable_in_ovoko'];
        return [
            'ok' => true,
            'action_name' => 'Dry-run Ovoko → Woo near real-time sync',
            'mode' => 'dry_run_no_woo_writes',
            'batch_size' => $limit,
            'page' => $page,
            'lock_option' => self::LOCK_OPTION,
            'status_option' => self::STATUS_OPTION,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => [],
            'warnings' => [],
            'counts' => array_fill_keys($keys, 0),
            'items' => [],
            'sync_modes_design' => $this->sync_modes_design(),
            'cron_design' => $this->cron_design(),
            'unavailable_product_policy_recommendation' => $this->unavailable_policy(),
            'price_parser_supported_formats' => self::supported_price_formats(),
            'safety' => ['no_woo_product_create' => true, 'no_woo_product_update' => true, 'no_price_change' => true, 'no_stock_change' => true, 'no_description_change' => true, 'no_category_change' => true, 'no_existing_image_change' => true, 'no_ovoko_write' => true],
        ];
    }

    private function find_product_id_by_ovoko_id(string $partId): int
    {
        if ($partId === '' || !post_type_exists('product')) {
            return 0;
        }
        $query = new \WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => ['relation' => 'OR', ['key' => '_ovoko_part_id', 'value' => $partId], ['key' => 'ovoko_part_id', 'value' => $partId], ['key' => 'part_id', 'value' => $partId]],
        ]);
        return (int) ($query->posts[0] ?? 0);
    }

    private function get_current_product_details_summary(int $productId): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if (!$product) {
            return [];
        }
        $summary = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'get_visible') || !$attribute->get_visible()) {
                continue;
            }
            $name = wc_attribute_label($attribute->get_name());
            $values = method_exists($attribute, 'get_options') ? $attribute->get_options() : [];
            $summary[$name] = implode(', ', array_map('strval', (array) $values));
        }
        return array_filter($summary, static fn($v) => trim((string) $v) !== '');
    }

    private function get_product_image_count(object $product): int
    {
        $count = method_exists($product, 'get_image_id') && (int) $product->get_image_id() > 0 ? 1 : 0;
        if (method_exists($product, 'get_gallery_image_ids')) {
            $count += count((array) $product->get_gallery_image_ids());
        }
        return $count;
    }

    private function stock_status_preview(string $status): array
    {
        $normalized = strtolower(trim($status));
        $unavailable = in_array($normalized, ['sold', 'deleted', 'removed', 'inactive', 'unavailable', 'reserved'], true);
        return [
            'ovoko_status' => $status,
            'target_manage_stock' => true,
            'target_stock_quantity' => $unavailable ? 0 : 1,
            'target_stock_status' => $unavailable ? 'outofstock' : 'instock',
            'products_unavailable_in_ovoko' => $unavailable,
            'policy' => 'A: set stock=0 and outofstock; do not delete products. Consider catalog hidden only after business approval.',
        ];
    }

    private function stable_hash(mixed $value): string
    {
        return hash('sha256', is_string($value) ? $value : wp_json_encode($value));
    }

    private function changed_assoc_keys(array $current, array $incoming): array
    {
        $changed = [];
        foreach ($incoming as $key => $value) {
            if ((string) ($current[$key] ?? '') !== (string) $value) {
                $changed[] = (string) $key;
            }
        }
        return $changed;
    }

    private function get_first_product_meta(int $productId, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function summarize_status(array $report): array
    {
        return [
            'status' => 'idle',
            'last_successful_sync_at' => gmdate('c'),
            'last_cursor' => $report['last_cursor'] ?? [],
            'processed' => (int) ($report['processed'] ?? 0),
            'created' => (int) ($report['counts']['products_would_create'] ?? 0),
            'updated' => (int) ($report['counts']['products_would_update'] ?? 0),
            'skipped' => (int) ($report['counts']['products_would_skip'] ?? 0),
            'errors' => (array) ($report['errors'] ?? []),
            'warnings' => (array) ($report['warnings'] ?? []),
        ];
    }

    public function get_sale_sync_status(): array
    {
        return [
            'pending_sale_sync' => 0,
            'success_sale_sync' => 0,
            'failed_sale_sync' => 0,
            'last_error' => '',
            'retry_count' => 0,
            'unsynced_orders' => [],
            'sample_failed_order_items' => [],
            'queue_storage' => self::SALE_QUEUE_OPTION,
            'implementation_status' => 'design_only_not_sending_to_ovoko',
        ];
    }

    private function sale_sync_meta_keys(): array
    {
        return ['ovoko_sale_sync_status','ovoko_sale_sync_attempted_at','ovoko_sale_sync_success_at','ovoko_sale_sync_error','ovoko_sale_sync_request_id','ovoko_sale_sync_order_id','ovoko_sale_sync_part_id'];
    }

    private function sale_queue_design(): array
    {
        return ['trigger_hooks' => ['woocommerce_payment_complete', 'woocommerce_order_status_processing', 'woocommerce_order_status_completed'], 'cancel_refund_hooks_to_design' => ['woocommerce_order_status_cancelled', 'woocommerce_order_refunded'], 'worker' => 'separate WP-Cron/server-cron queue worker; never block checkout', 'idempotency' => 'one request_id per order item; skip if order-item meta status is success', 'retry' => 'exponential retry for API/network failures with admin-visible failed state'];
    }

    private function sync_modes_design(): array
    {
        return ['dry_run_sync' => 'Logs would-create/would-update/price/stock/description/details/category/image warnings only.', 'manual_run' => 'Admin Run sync now button, batched with lock and logs; live writes remain disabled in this stage.', 'wp_cron_server_cron' => 'Schedule dry-run/log first; enable live only after explicit approval.'];
    }

    private function cron_design(): array
    {
        return ['batch_size' => '10–25', 'cursor' => 'page now; updated_after/from only after Ovoko confirms support', 'lock' => self::LOCK_OPTION, 'status_fields' => ['running/idle/error','last_successful_sync_at','last_cursor','processed','created','updated','skipped','errors','warnings'], 'parallel_runs' => 'blocked by lock/transient before any work starts'];
    }

    private function unavailable_policy(): array
    {
        return ['recommended' => 'A', 'A_stock_0_outofstock' => 'Recommended: safest for SEO/order history and reversible; keeps product visible as unavailable.', 'B_draft_private' => 'Stronger hiding, but can break URLs and reporting.', 'C_hidden_catalog' => 'Useful if unavailable products should not appear in listings, but still needs a Woo visibility write.'];
    }

    private function conflict_policy(): array
    {
        return ['woo_sold_ovoko_already_unavailable' => 'Do not retry as success blindly; mark failed_conflict and require admin review/customer handling.', 'ovoko_sold_woo_still_available' => 'Ovoko→Woo cron sets stock=0/outofstock on next batch.', 'woo_cancelled_refunded' => 'Do not restore Ovoko stock until Ovoko confirms reversal endpoint and business policy.'];
    }
}
