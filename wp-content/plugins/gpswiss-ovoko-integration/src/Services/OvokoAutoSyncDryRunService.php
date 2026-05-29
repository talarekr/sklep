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
            'error' => 'No supported Woo price format found in internal_notes. Existing Woo prices stay untouched; new Woo products are skipped until internal_notes contains a valid price.',
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
        $storedStatus = (array) get_option(self::STATUS_OPTION, []);
        $deltaWindow = $this->build_current_delta_window((string) ($storedStatus['last_successful_sync_at'] ?? ''), (string) ($options['updated_after'] ?? ''));
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
        $report['delta_sync_confirmed'] = false;
        $report['delta_filter_used'] = '';
        $report['current_delta_window'] = $deltaWindow;
        $report['last_successful_sync_at'] = (string) ($storedStatus['last_successful_sync_at'] ?? '');
        $report['last_attempted_sync_at'] = gmdate('c');
        $report['live_cron_enabled'] = false;
        $report['warnings']['delta_sync_not_confirmed'] = ($report['warnings']['delta_sync_not_confirmed'] ?? 0) + 1;
        $report['warnings']['live_cron_disabled_until_delta_confirmed'] = ($report['warnings']['live_cron_disabled_until_delta_confirmed'] ?? 0) + 1;
        $report['source_fetch'] = [
            'ok' => !empty($fetch['ok']),
            'mode' => $partId !== '' ? 'manual_single_part_dry_run' : 'manual_page_based_dry_run_not_cron_delta',
            'part_id' => $partId,
            'status_code' => (string) ($fetch['status_code'] ?? ''),
            'pagination' => (array) ($fetch['pagination'] ?? []),
            'delta_filter_applied' => false,
            'delta_filter_warning' => 'delta_sync_not_confirmed',
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
        $report['processed_changed_products'] = $report['processed'];
        $report['returned_records_count'] = count($records);
        $report['updated_at_stats'] = $this->summarize_returned_updated_at_values($records, (string) ($deltaWindow['delta_from'] ?? ''));
        $report['last_cursor'] = ['page' => $page, 'next_page' => $page + 1, 'delta_from' => (string) ($deltaWindow['delta_from'] ?? ''), 'delta_to' => (string) ($deltaWindow['delta_to'] ?? ''), 'scope' => 'manual_page_only_not_live_cron'];
        $report['dashboard_counters'] = $this->dashboard_price_counters((array) ($report['counts'] ?? []));
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
                'from_or_updated_after_filter_confirmed' => !empty($deltaProbe['delta_sync_confirmed']),
                'delta_sync_confirmed' => !empty($deltaProbe['delta_sync_confirmed']),
                'delta_filter_used' => (string) ($deltaProbe['delta_filter_used'] ?? ''),
                'delta_filter_probe' => $deltaProbe,
                'recommendation' => !empty($deltaProbe['delta_sync_confirmed'])
                    ? 'Delta candidate found in read-only probe; review returned records and Ovoko/RRR documentation before enabling live cron.'
                    : 'Live cron must remain disabled. Manual page-based dry-run is allowed only for diagnostics; ask Ovoko/RRR for the official changed-products endpoint or date filter.',
                'cron_frequency' => 'Live cron disabled until delta sync is confirmed; do not run page-based full scans every 10–15 minutes.',
                'batch_size' => 'After delta confirmation, process only changed products in the delta window; never scan all pages per cron tick.',
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
        $priceWarning = (string) ($price['warning'] ?? '');
        $isExistingProduct = $productId > 0;
        $newProductMissingPrice = !$isExistingProduct && $priceWarning === 'missing_price_in_internal_notes';
        $newProductInvalidPrice = !$isExistingProduct && $priceWarning === 'invalid_internal_notes_price';
        $newProductBlockedByPrice = !$isExistingProduct && (empty($price['ok']) || $newProductMissingPrice || $newProductInvalidPrice);
        if ($newProductMissingPrice) {
            $warnings[] = 'new_product_missing_price_in_internal_notes';
            $warnings[] = 'new_product_would_create_as_draft_or_skip_due_to_missing_price';
        } elseif ($newProductInvalidPrice) {
            $warnings[] = 'new_product_invalid_internal_notes_price';
            $warnings[] = 'new_product_would_create_as_draft_or_skip_due_to_missing_price';
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
        $categoryPathFromPayload = trim((string) (($record['category_title_path'] ?? '') ?: ($normalized['category_title_path'] ?? '')));
        $categoryPathFromTree = '';
        $categoryTreeEndpointUsed = '';
        $categoryTreeResolutionOk = false;
        if ($categoryId !== '') {
            $tree = $client->resolve_category_path_from_tree((int) $categoryId);
            $categoryPathFromTree = trim((string) ($tree['category_tree_resolved_path'] ?? ''));
            $categoryTreeEndpointUsed = (string) ($tree['category_tree_endpoint_used'] ?? '');
            $categoryTreeResolutionOk = !empty($tree['ok']) && $categoryPathFromTree !== '';
            if (!$categoryTreeResolutionOk) {
                $warnings[] = 'category_tree_resolution_failed';
            }
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
        $currentWooCategoryPath = $productId > 0 ? $this->get_current_product_category_path($productId) : '';
        $categoryAction = $isExistingProduct ? 'verify_only' : ($categoryTreeResolutionOk && !$newProductBlockedByPrice ? 'would_assign_from_tree' : 'skip');
        $categoriesWouldUpdate = !$isExistingProduct && !$newProductBlockedByPrice && $categoryId !== '' && $categoryTreeResolutionOk;
        $incomingDetailsHash = $this->stable_hash($incomingDetails);
        $currentDetailsHash = $this->stable_hash($currentDetails);
        $changedFields = $this->changed_assoc_keys($currentDetails, $incomingDetails);

        return [
            'part_id' => $partId,
            'product_id' => $productId,
            'product_exists' => $isExistingProduct,
            'products_would_create' => !$isExistingProduct && !$newProductBlockedByPrice,
            'products_would_update' => $isExistingProduct,
            'products_would_skip' => $newProductBlockedByPrice,
            'title' => (string) ($record['name'] ?? $normalized['title'] ?? ''),
            'existing_product_price_untouched' => $isExistingProduct,
            'existing_product_internal_notes_price_ignored' => $isExistingProduct && $priceWarning !== 'missing_price_in_internal_notes',
            'existing_product_internal_notes_missing_ignored' => $isExistingProduct && $priceWarning === 'missing_price_in_internal_notes',
            'new_product_price_from_internal_notes_ok' => !$isExistingProduct && !empty($price['ok']),
            'new_product_missing_price_in_internal_notes' => $newProductMissingPrice,
            'new_product_invalid_internal_notes_price' => $newProductInvalidPrice,
            'new_product_would_create_as_draft_or_skip_due_to_missing_price' => $newProductBlockedByPrice,
            'price' => [
                'current_woo' => $currentPrice,
                'incoming_from_internal_notes' => $price,
                'policy' => $isExistingProduct ? 'existing_woo_price_untouched_internal_notes_ignored' : 'new_product_price_from_internal_notes_only',
                'safe_default_for_new_product_without_valid_internal_notes_price' => 'skip_create',
            ],
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
            'categories_would_update' => $categoriesWouldUpdate,
            'current_woo_category_path' => $currentWooCategoryPath,
            'ovoko_category_id' => $categoryId,
            'category_path_from_tree' => $categoryPathFromTree,
            'category_path_from_payload' => $categoryPathFromPayload,
            'category_action' => $categoryAction,
            'category_tree_resolution_ok' => $categoryTreeResolutionOk,
            'category' => [
                'category_id' => $categoryId,
                'ovoko_category_id' => $categoryId,
                'current_woo_category_path' => $currentWooCategoryPath,
                'category_path_from_tree' => $categoryPathFromTree,
                'category_path_from_payload' => $categoryPathFromPayload,
                'category_action' => $categoryAction,
                'category_tree_resolution_ok' => $categoryTreeResolutionOk,
                'category_tree_endpoint_used' => $categoryTreeEndpointUsed,
                'path_source' => 'category_id_get_categories_tree',
                'assignment_policy' => $isExistingProduct ? 'existing_product_verify_only_no_category_write' : 'new_product_assign_from_category_tree_only',
                'uses_payload_breadcrumb_as_source_of_truth' => false,
                'uses_old_csv' => false,
                'uses_root_motoryzacja' => false,
                'path_shortened' => false,
            ],
            'existing_products_images_untouched' => $productId > 0,
            'new_products_images_would_import' => $productId <= 0 && $imageCount > 0,
            'existing_products_missing_images_warning' => $productId > 0 && $existingImageCount === 0,
            'images' => ['existing_woo_image_count' => $existingImageCount, 'incoming_ovoko_image_count' => $imageCount, 'policy' => $productId > 0 ? 'do_not_touch_existing_images' : 'would_import_for_new_product_only'],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function empty_ovoko_to_woo_report(int $limit, int $page): array
    {
        $keys = ['existing_product_price_untouched','existing_product_internal_notes_price_ignored','existing_product_internal_notes_missing_ignored','new_product_price_from_internal_notes_ok','new_product_missing_price_in_internal_notes','new_product_invalid_internal_notes_price','new_product_would_create_as_draft_or_skip_due_to_missing_price','existing_products_images_untouched','new_products_images_would_import','existing_products_missing_images_warning','descriptions_would_update','descriptions_empty_from_ovoko','details_would_update','details_missing_from_ovoko','details_empty_from_ovoko','details_fields_changed','stock_would_update','categories_would_update','products_would_create','products_would_update','products_would_skip','products_unavailable_in_ovoko'];
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
            'price_policy' => [
                'existing_products' => 'Woo price is untouched; Ovoko internal_notes price is ignored.',
                'new_products' => 'Price may come only from Ovoko internal_notes; safer default is skip create when missing or invalid.',
                'fallback_to_other_ovoko_price_fields' => false,
            ],
            'safety' => ['no_woo_product_create' => true, 'no_woo_product_update' => true, 'no_price_change' => true, 'no_stock_change' => true, 'no_description_change' => true, 'no_category_change' => true, 'no_existing_image_change' => true, 'no_ovoko_write' => true, 'no_live_cron_without_confirmed_delta' => true],
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

    private function get_current_product_category_path(int $productId): string
    {
        if ($productId <= 0 || !function_exists('wp_get_post_terms')) {
            return '';
        }

        $terms = wp_get_post_terms($productId, 'product_cat', ['fields' => 'all']);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }

        $paths = [];
        foreach ((array) $terms as $term) {
            if (!is_object($term) || !isset($term->term_id)) {
                continue;
            }

            $paths[] = $this->format_product_category_term_path($term);
        }

        $paths = array_values(array_unique(array_filter($paths, static fn($path) => trim((string) $path) !== '')));
        return implode(' | ', $paths);
    }

    private function format_product_category_term_path(object $term): string
    {
        $names = [];
        if (function_exists('get_ancestors')) {
            $ancestorIds = array_reverse(array_map('intval', (array) get_ancestors((int) $term->term_id, 'product_cat')));
            foreach ($ancestorIds as $ancestorId) {
                $ancestor = get_term($ancestorId, 'product_cat');
                if ($ancestor && !is_wp_error($ancestor)) {
                    $names[] = (string) $ancestor->name;
                }
            }
        }
        $names[] = (string) ($term->name ?? '');

        return implode(' / ', array_values(array_filter(array_map('trim', $names), static fn($name) => $name !== '')));
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

    private function build_current_delta_window(string $lastSuccessfulSyncAt, string $requestedDeltaFrom = ''): array
    {
        $overlapSeconds = 5 * MINUTE_IN_SECONDS;
        $delaySeconds = 2 * MINUTE_IN_SECONDS;
        $requestedTimestamp = trim($requestedDeltaFrom) !== '' ? strtotime($requestedDeltaFrom) : false;
        $lastSuccessfulTimestamp = trim($lastSuccessfulSyncAt) !== '' ? strtotime($lastSuccessfulSyncAt) : false;
        $fromTimestamp = $requestedTimestamp ?: ($lastSuccessfulTimestamp ? ($lastSuccessfulTimestamp - $overlapSeconds) : (time() - DAY_IN_SECONDS));
        $toTimestamp = time() - $delaySeconds;

        return [
            'delta_from' => gmdate('c', $fromTimestamp),
            'delta_to' => gmdate('c', $toTimestamp),
            'overlap_seconds' => $overlapSeconds,
            'delay_seconds' => $delaySeconds,
            'source' => $requestedTimestamp ? 'manual_requested_delta_from' : ($lastSuccessfulTimestamp ? 'last_successful_sync_at_with_overlap' : 'fallback_last_24h_for_diagnostics_only'),
        ];
    }

    private function summarize_returned_updated_at_values(array $records, string $deltaFrom): array
    {
        $deltaFromTimestamp = trim($deltaFrom) !== '' ? strtotime($deltaFrom) : false;
        $timestamps = [];
        $older = [];
        foreach ($records as $row) {
            $record = (array) ($row['record'] ?? $row);
            $updatedAt = trim((string) ($record['updated_at'] ?? ''));
            if ($updatedAt === '') {
                continue;
            }
            $timestamp = strtotime($updatedAt);
            if (!$timestamp) {
                continue;
            }
            $timestamps[] = $timestamp;
            if ($deltaFromTimestamp && $timestamp < $deltaFromTimestamp) {
                $older[] = [
                    'id' => (string) ($record['id'] ?? $record['part_id'] ?? ''),
                    'updated_at' => $updatedAt,
                ];
            }
        }

        return [
            'updated_at_present' => $timestamps !== [],
            'min_updated_at' => $timestamps !== [] ? gmdate('c', min($timestamps)) : '',
            'max_updated_at' => $timestamps !== [] ? gmdate('c', max($timestamps)) : '',
            'has_records_older_than_delta_from' => $older !== [],
            'records_older_than_delta_from_count' => count($older),
            'records_older_than_delta_from_sample' => array_slice($older, 0, 5),
        ];
    }

    private function dashboard_price_counters(array $counts): array
    {
        return [
            'existing_product_price_untouched_total' => (int) ($counts['existing_product_price_untouched'] ?? 0),
            'existing_product_internal_notes_price_ignored_total' => (int) ($counts['existing_product_internal_notes_price_ignored'] ?? 0),
            'existing_product_internal_notes_missing_ignored_total' => (int) ($counts['existing_product_internal_notes_missing_ignored'] ?? 0),
            'new_product_price_from_internal_notes_ok_total' => (int) ($counts['new_product_price_from_internal_notes_ok'] ?? 0),
            'new_product_missing_price_in_internal_notes_total' => (int) ($counts['new_product_missing_price_in_internal_notes'] ?? 0),
            'new_product_invalid_internal_notes_price_total' => (int) ($counts['new_product_invalid_internal_notes_price'] ?? 0),
        ];
    }

    private function summarize_status(array $report): array
    {
        $counts = (array) ($report['counts'] ?? []);
        return [
            'status' => 'idle',
            'delta_sync_confirmed' => !empty($report['delta_sync_confirmed']),
            'delta_filter_used' => (string) ($report['delta_filter_used'] ?? ''),
            'last_successful_sync_at' => !empty($report['delta_sync_confirmed']) ? (string) ($report['current_delta_window']['delta_to'] ?? '') : (string) ($report['last_successful_sync_at'] ?? ''),
            'last_attempted_sync_at' => (string) ($report['last_attempted_sync_at'] ?? gmdate('c')),
            'last_delta_from' => (string) ($report['current_delta_window']['delta_from'] ?? ''),
            'last_delta_to' => (string) ($report['current_delta_window']['delta_to'] ?? ''),
            'current_delta_window' => (array) ($report['current_delta_window'] ?? []),
            'last_cursor' => $report['last_cursor'] ?? [],
            'processed_changed_products' => (int) ($report['processed_changed_products'] ?? $report['processed'] ?? 0),
            'returned_records_count' => (int) ($report['returned_records_count'] ?? $report['processed'] ?? 0),
            'updated_at_stats' => (array) ($report['updated_at_stats'] ?? []),
            'processed' => (int) ($report['processed'] ?? 0),
            'created' => (int) ($counts['products_would_create'] ?? 0),
            'updated' => (int) ($counts['products_would_update'] ?? 0),
            'skipped' => (int) ($counts['products_would_skip'] ?? 0),
            'errors' => (array) ($report['errors'] ?? []),
            'warnings' => (array) ($report['warnings'] ?? []),
            'counts' => $counts,
            'dashboard_counters' => $this->dashboard_price_counters($counts),
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
        return ['dry_run_sync' => 'Manual diagnostics only; page-based dry-run is not a cron strategy.', 'manual_run' => 'Admin Run sync now button remains disabled for live writes until delta sync is confirmed.', 'wp_cron_server_cron' => 'Do not schedule live page-based scans; enable cron only after official Ovoko/RRR delta endpoint or date filter is confirmed.'];
    }

    private function cron_design(): array
    {
        return ['live_cron' => 'disabled_until_delta_sync_confirmed', 'delta_window' => 'delta_from = last_successful_sync_at - 5 minutes overlap; delta_to = now - 2 minutes delay', 'cursor' => 'last_cursor/page is only for the current delta window; never for full-dataset cron scans', 'lock' => self::LOCK_OPTION, 'status_fields' => ['running/idle/error','last_successful_sync_at','last_attempted_sync_at','last_delta_from','last_delta_to','last_cursor','processed_changed_products','created','updated','skipped','errors','warnings'], 'parallel_runs' => 'blocked by lock/transient before any work starts'];
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
