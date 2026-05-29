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
        $client = new RrrApiClient($this->integrationService->get_settings());
        $storedStatus = (array) get_option(self::STATUS_OPTION, []);
        $dateWindow = $this->build_current_date_from_window((string) ($storedStatus['last_successful_sync_date'] ?? ''), (string) ($options['date_from'] ?? ''));
        $dateFrom = (string) ($dateWindow['date_from'] ?? gmdate('Y-m-d'));
        $fetch = $client->preview_fetch_parts_by_date_from($dateFrom, $limit, $page);
        $records = [];
        $rawRecords = (array) ($fetch['raw_records'] ?? []);
        foreach ((array) ($fetch['records'] ?? []) as $index => $record) {
            if (!is_array($record)) {
                continue;
            }
            $payload = is_array($rawRecords[$index] ?? null) ? (array) $rawRecords[$index] : $record;
            $records[] = ['record' => $record + $payload, 'payload' => $payload];
        }

        $report = $this->empty_ovoko_to_woo_report($limit, $page);
        $report['delta_sync_confirmed'] = true;
        $report['delta_filter_used'] = 'date_from';
        $report['date_from'] = $dateFrom;
        $report['date_from_used'] = $dateFrom;
        $report['pages_processed_for_date_window'] = 1;
        $report['current_delta_window'] = $dateWindow;
        $report['last_successful_sync_at'] = (string) ($storedStatus['last_successful_sync_at'] ?? '');
        $report['last_successful_sync_date'] = (string) ($storedStatus['last_successful_sync_date'] ?? '');
        $report['last_successful_dry_run_at'] = gmdate('c');
        $report['last_successful_dry_run_date'] = gmdate('Y-m-d');
        $report['last_attempted_sync_at'] = gmdate('c');
        $report['live_cron_enabled'] = false;
        $report['warnings']['live_cron_disabled_until_manual_date_from_dry_run_is_approved'] = ($report['warnings']['live_cron_disabled_until_manual_date_from_dry_run_is_approved'] ?? 0) + 1;
        if (empty($fetch['ok'])) {
            $report['ok'] = false;
            $report['errors'][] = [
                'code' => 'date_from_fetch_failed',
                'status_code' => (string) ($fetch['status_code'] ?? ''),
                'message' => (string) ($fetch['msg'] ?? $fetch['message'] ?? ''),
            ];
        }
        $report['source_fetch'] = [
            'ok' => !empty($fetch['ok']),
            'mode' => 'manual_date_from_delta_dry_run',
            'status_code' => (string) ($fetch['status_code'] ?? ''),
            'pagination' => (array) ($fetch['pagination'] ?? []),
            'endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
            'date_from' => $dateFrom,
            'delta_filter_applied' => true,
            'delta_filter_used' => 'date_from',
            'disallowed_filters' => ['updated_from','updated_after','from','timestamps','ISO datetime'],
            'no_woo_write' => true,
            'no_ovoko_write' => true,
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
        $report['updated_at_stats'] = $this->summarize_returned_updated_at_values($records, $dateFrom . ' 00:00:00 UTC');
        $report['last_cursor'] = [
            'page' => $page,
            'next_page' => $page + 1,
            'date_from' => $dateFrom,
            'scope' => 'date_from_delta_window_only',
            'not_full_scan' => true,
        ];
        $report['created_from_delta'] = (int) ($report['counts']['products_would_create'] ?? 0);
        $report['updated_from_delta'] = (int) ($report['counts']['products_would_update'] ?? 0);
        $report['skipped_already_synced'] = (int) ($report['counts']['products_would_skip_already_synced'] ?? 0);
        $report['dashboard_counters'] = $this->dashboard_price_counters((array) ($report['counts'] ?? []));
        $report['checked_at'] = gmdate('c');
        update_option(self::STATUS_OPTION, $this->summarize_status($report), false);
        return $report;
    }

    public function manual_live_date_from_sync(array $options = []): array
    {
        $confirmation = trim((string) ($options['confirmation'] ?? ''));
        $limit = max(1, min(5, (int) ($options['batch_size'] ?? 1)));
        $page = max(1, (int) ($options['page'] ?? 1));
        $dateFrom = trim((string) ($options['date_from'] ?? ''));
        $report = [
            'ok' => true,
            'action_name' => 'Run manual live date_from Ovoko → Woo sync',
            'mode' => 'manual_live_date_from_delta_no_cron',
            'endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
            'delta_filter_used' => 'date_from',
            'disallowed_filters_not_used' => ['updated_from', 'updated_after', 'from', 'timestamps', 'ISO datetime', 'full scan without date_from'],
            'date_from' => $dateFrom,
            'date_from_used' => $dateFrom,
            'page' => $page,
            'batch_size' => $limit,
            'max_batch_size' => 5,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped_missing_price' => 0,
            'skipped_already_synced' => 0,
            'errors' => [],
            'warnings' => [],
            'created_product_ids' => [],
            'skipped_part_ids' => [],
            'items' => [],
            'idempotency_meta_keys' => ['_gpswiss_ovoko_last_synced_updated_at', '_gpswiss_ovoko_last_synced_hash'],
            'existing_product_policy' => 'stock/status, description and details may update; price untouched; images untouched; categories verify-only',
            'automatic_cron_enabled' => false,
            'woo_to_ovoko_worker_touched' => false,
            'checked_at' => gmdate('c'),
        ];

        if ($confirmation !== 'RUN OVOKO DATE_FROM LIVE SYNC') {
            $report['ok'] = false;
            $report['errors'][] = ['code' => 'confirmation_required', 'message' => 'Type RUN OVOKO DATE_FROM LIVE SYNC to run this live manual sync.'];
            return $report;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $report['ok'] = false;
            $report['errors'][] = ['code' => 'invalid_date_from', 'message' => 'date_from must be YYYY-MM-DD.'];
            return $report;
        }
        if (!function_exists('wc_get_product') || !post_type_exists('product')) {
            $report['ok'] = false;
            $report['errors'][] = ['code' => 'woocommerce_unavailable', 'message' => 'WooCommerce product APIs are unavailable.'];
            return $report;
        }

        $client = new RrrApiClient($this->integrationService->get_settings());
        $fetch = $client->preview_fetch_parts_by_date_from($dateFrom, $limit, $page);
        $report['source_fetch'] = [
            'ok' => !empty($fetch['ok']),
            'status_code' => (string) ($fetch['status_code'] ?? ''),
            'pagination' => (array) ($fetch['pagination'] ?? []),
            'endpoint' => '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD',
            'date_from' => $dateFrom,
            'delta_filter_applied' => true,
            'delta_filter_used' => 'date_from',
        ];
        if (empty($fetch['ok'])) {
            $report['ok'] = false;
            $report['errors'][] = ['code' => 'date_from_fetch_failed', 'status_code' => (string) ($fetch['status_code'] ?? ''), 'message' => (string) ($fetch['msg'] ?? $fetch['message'] ?? '')];
            return $report;
        }

        $rawRecords = (array) ($fetch['raw_records'] ?? []);
        foreach ((array) ($fetch['records'] ?? []) as $index => $record) {
            if (!is_array($record)) {
                continue;
            }
            $payload = is_array($rawRecords[$index] ?? null) ? (array) $rawRecords[$index] : $record;
            $record = $record + $payload;
            $partId = trim((string) ($record['id'] ?? $record['part_id'] ?? ''));
            $normalized = $client->normalize_rrr_single_part_payload($payload);
            if (($normalized['part_id'] ?? '') === '' && $partId !== '') {
                $normalized['part_id'] = $partId;
            }
            $updatedAt = trim((string) ($record['updated_at'] ?? $normalized['updated_at'] ?? ''));
            $incomingHash = $this->stable_hash($this->normalize_payload_for_idempotency($partId, $updatedAt, $payload));
            $productId = $this->find_product_id_by_ovoko_id($partId);
            $alreadySynced = false;
            if ($productId > 0 && $partId !== '' && $updatedAt !== '') {
                $lastSyncedUpdatedAt = $this->get_first_product_meta($productId, ['_gpswiss_ovoko_last_synced_updated_at']);
                $lastSyncedHash = $this->get_first_product_meta($productId, ['_gpswiss_ovoko_last_synced_hash']);
                $alreadySynced = $lastSyncedUpdatedAt === $updatedAt && $lastSyncedHash !== '' && hash_equals($lastSyncedHash, $incomingHash);
            }
            if ($alreadySynced) {
                $report['processed']++;
                $report['skipped_already_synced']++;
                $report['skipped_part_ids'][] = $partId;
                $report['items'][] = ['part_id' => $partId, 'product_id' => $productId, 'action' => 'skipped_already_synced'];
                continue;
            }

            $price = self::parse_internal_notes_price($record['internal_notes'] ?? null);
            if ($productId <= 0 && empty($price['ok'])) {
                $report['processed']++;
                $report['skipped_missing_price']++;
                $report['warnings']['new_product_missing_price_in_internal_notes'] = (int) ($report['warnings']['new_product_missing_price_in_internal_notes'] ?? 0) + 1;
                $report['skipped_part_ids'][] = $partId;
                $report['items'][] = ['part_id' => $partId, 'action' => 'skipped_missing_price', 'warning' => 'new_product_missing_price_in_internal_notes', 'published' => false];
                continue;
            }

            $result = $this->integrationService->apply_manual_live_date_from_part($record, $payload, [
                'product_id' => $productId,
                'price' => $price,
                'sync_hash' => $incomingHash,
                'updated_at' => $updatedAt,
            ]);
            $report['processed']++;
            $report['items'][] = $result;
            if (empty($result['ok'])) {
                $report['ok'] = false;
                $report['errors'][] = ['part_id' => $partId, 'product_id' => (int) ($result['product_id'] ?? 0), 'code' => (string) ($result['reason'] ?? 'sync_failed'), 'message' => (string) ($result['error'] ?? '')];
                $report['skipped_part_ids'][] = $partId;
                continue;
            }
            if (!empty($result['created'])) {
                $report['created']++;
                $report['created_product_ids'][] = (int) ($result['product_id'] ?? $result['created_product_id'] ?? 0);
            } elseif (!empty($result['updated'])) {
                $report['updated']++;
            }
        }

        update_option(self::STATUS_OPTION, array_merge((array) get_option(self::STATUS_OPTION, []), [
            'last_manual_live_sync_at' => gmdate('c'),
            'last_manual_live_sync_date_from' => $dateFrom,
            'live_cron_enabled' => false,
            'date_from_used' => $dateFrom,
        ]), false);

        return $report;
    }


    public function dry_run_woo_to_ovoko_sale(int $orderId): array
    {
        $endpointAnalysis = $this->sale_stock_endpoint_analysis();
        $status = $this->get_sale_sync_status();
        $result = [
            'ok' => true,
            'action_name' => 'Dry-run Woo order → Ovoko sale sync',
            'mode' => 'dry_run_no_ovoko_write_no_woo_write',
            'order_id' => $orderId,
            'order_status' => '',
            'endpoint_confirmed' => true,
            'proposed_endpoint' => (string) ($endpointAnalysis['confirmed_endpoint'] ?? '/crm/changePartStatus'),
            'proposed_method' => (string) ($endpointAnalysis['method'] ?? ''),
            'recommended_endpoint_policy' => 'Use only the confirmed dedicated endpoint POST /crm/changePartStatus with status=2 for Woo sale sync. Dry-run remains read-only; automatic live workers and checkout hooks remain disabled.',
            'candidate_endpoints_to_confirm_with_ovoko' => array_keys((array) ($endpointAnalysis['endpoint_candidates'] ?? [])),
            'endpoint_contract_analysis' => $endpointAnalysis,
            'idempotency_meta_keys' => $this->sale_sync_meta_keys(),
            'queue_design' => $this->sale_queue_design(),
            'conflict_policy' => $this->conflict_policy(),
            'order_items' => [],
            'items' => [],
            'warnings' => [],
            'errors' => [],
            'status_panel' => $status,
            'live_write_enabled' => false,
            'manual_single_order_live_probe_enabled' => true,
            'automatic_live_worker_enabled' => false,
            'no_ovoko_write' => true,
            'no_woo_write' => true,
            'checked_at' => gmdate('c'),
        ];

        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            $result['ok'] = $orderId <= 0;
            $result['warnings'][] = $orderId <= 0 ? 'missing_order_id' : 'woocommerce_unavailable';
            return $result;
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            $result['ok'] = false;
            $result['errors'][] = 'order_not_found';
            return $result;
        }

        $result['order_status'] = method_exists($order, 'get_status') ? (string) $order->get_status() : '';

        foreach ($order->get_items() as $itemId => $item) {
            $product = method_exists($item, 'get_product') ? $item->get_product() : null;
            $productId = $product ? (int) $product->get_id() : 0;
            $ovokoId = $productId > 0 ? $this->get_first_product_meta($productId, ['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id']) : '';
            $syncMeta = $this->get_order_item_sale_sync_meta((int) $itemId);
            $alreadySynced = (string) ($syncMeta['ovoko_sale_sync_status'] ?? '') === 'success';
            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0;
            $requestId = $syncMeta['ovoko_sale_sync_request_id'] !== ''
                ? (string) $syncMeta['ovoko_sale_sync_request_id']
                : 'woo-' . $orderId . '-item-' . (int) $itemId . '-part-' . ($ovokoId !== '' ? $ovokoId : 'missing');
            $itemWarnings = [];
            if ($ovokoId === '') {
                $itemWarnings[] = 'missing_ovoko_part_id_product_meta';
            }
            if ($alreadySynced) {
                $itemWarnings[] = 'already_synced_skip_live_send';
            }
            if ($qty > 1) {
                $itemWarnings[] = 'quantity_greater_than_1_requires_confirmed_ovoko_partial_stock_contract';
            }

            $proposedPayload = [
                'part_id' => $ovokoId,
                'status' => 2,
                'request_id' => $requestId . ' (local only; not sent to Ovoko)',
            ];

            $row = [
                'order_item_id' => (int) $itemId,
                'order_id' => $orderId,
                'order_status' => $result['order_status'],
                'product_id' => $productId,
                'sku' => $product ? (string) $product->get_sku() : '',
                'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
                'quantity' => $qty,
                'ovoko_id' => $ovokoId,
                'part_id' => $ovokoId,
                'product_was_sent_to_ovoko' => $ovokoId !== '',
                'has_ovoko_id' => $ovokoId !== '',
                'idempotency_key' => $requestId,
                'sync_meta' => $syncMeta,
                'already_synced' => $alreadySynced,
                'would_enqueue' => $ovokoId !== '' && !$alreadySynced,
                'would_send' => false,
                'skip_live_send_reason' => $alreadySynced ? 'order_item_already_successfully_synced' : 'dry_run_only_automatic_live_worker_disabled_use_manual_single_order_probe',
                'proposed_endpoint' => (string) ($endpointAnalysis['confirmed_endpoint'] ?? ''),
                'proposed_method' => (string) ($endpointAnalysis['method'] ?? ''),
                'proposed_payload' => $proposedPayload,
                'would_send_payload' => $proposedPayload,
                'warnings' => $itemWarnings,
                'errors' => $ovokoId === '' ? ['missing_part_mapping'] : [],
            ];
            $result['order_items'][] = $row;
            $result['items'][] = $row;
        }

        return $result;
    }

    public function analyze_woo_to_ovoko_sale_stock_endpoint(): array
    {
        $analysis = $this->sale_stock_endpoint_analysis();
        $analysis['docs_evidence']['runtime_public_docs_probe'] = $this->sale_stock_public_docs_probe();
        return $analysis + [
            'ok' => true,
            'action_name' => 'Analyze Woo → Ovoko sale/stock endpoint',
            'checked_at' => gmdate('c'),
        ];
    }

    public function endpoint_capability_analysis(): array
    {
        $client = new RrrApiClient($this->integrationService->get_settings());
        $sample = $client->preview_fetch_parts_sample(1, 1);
        $deltaProbe = $client->probe_parts_delta_filter_support();
        $preciseDeltaProbe = (array) ($deltaProbe['precise_updated_from_probe'] ?? []);
        $record = (array) (($sample['records'][0] ?? []));
        $fields = array_values(array_map('strval', array_keys($record)));
        $hasUpdatedAt = array_key_exists('updated_at', $record);
        $hasReserved = array_key_exists('reserved_user', $record) || array_key_exists('reserved_date', $record);

        return [
            'ok' => true,
            'action_name' => 'Ovoko/RRR endpoint capability analysis for safe cron',
            'read_endpoints_confirmed_in_client' => ['/v2/get/parts?limit={n}&page={p}', '/get/part/{part_id}', '/get/categories/tree', '/v2/get/parts/categories'],
            'write_endpoint_seen_in_client' => ['/crm/updatePart' => 'Only tested for place/internal_notes update; not approved for sale/stock/status sync.'],
            'woo_to_ovoko_sale_stock_endpoint_analysis' => $this->sale_stock_endpoint_analysis(),
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
                'delta_filter_used' => (string) ($preciseDeltaProbe['delta_filter_used'] ?? ($deltaProbe['delta_filter_used'] ?? '')),
                'delta_filter_probe' => $deltaProbe,
                'precise_updated_from_probe' => $preciseDeltaProbe,
                'delta_cron_rules' => $this->delta_cron_rules((string) ($preciseDeltaProbe['delta_filter_used'] ?? '')),
                'live_sync_scope_after_delta_confirmation' => $this->live_sync_scope_design(),
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
        $updatedAt = trim((string) ($record['updated_at'] ?? $normalized['updated_at'] ?? ''));
        $incomingSyncHash = $this->stable_hash($this->normalize_payload_for_idempotency($partId, $updatedAt, $payload));
        $lastSyncedUpdatedAt = $productId > 0 ? $this->get_first_product_meta($productId, ['_gpswiss_ovoko_last_synced_updated_at']) : '';
        $lastSyncedHash = $productId > 0 ? $this->get_first_product_meta($productId, ['_gpswiss_ovoko_last_synced_hash']) : '';
        $alreadySynced = $productId > 0 && $partId !== '' && $updatedAt !== '' && $lastSyncedUpdatedAt === $updatedAt && $lastSyncedHash !== '' && hash_equals($lastSyncedHash, $incomingSyncHash);
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
            'products_would_update' => $isExistingProduct && !$alreadySynced,
            'products_would_skip' => $newProductBlockedByPrice || $alreadySynced,
            'products_would_skip_already_synced' => $alreadySynced,
            'skipped_already_synced' => $alreadySynced,
            'title' => (string) ($record['name'] ?? $normalized['title'] ?? ''),
            'updated_at' => $updatedAt,
            'idempotency' => [
                'meta_updated_at_key' => '_gpswiss_ovoko_last_synced_updated_at',
                'meta_hash_key' => '_gpswiss_ovoko_last_synced_hash',
                'incoming_updated_at' => $updatedAt,
                'incoming_hash' => $incomingSyncHash,
                'stored_updated_at' => $lastSyncedUpdatedAt,
                'stored_hash' => $lastSyncedHash,
                'skip_reason' => $alreadySynced ? 'skipped_already_synced' : '',
                'dry_run_no_meta_write' => true,
            ],
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
            'stock_would_update' => !$alreadySynced && ($productId <= 0 || $currentStockStatus !== $stock['target_stock_status']),
            'stock' => $stock + ['current_woo_stock_status' => $currentStockStatus],
            'products_unavailable_in_ovoko' => !empty($stock['products_unavailable_in_ovoko']),
            'descriptions_would_update' => !$alreadySynced && $incomingDescription !== '' && $this->stable_hash($currentDescription) !== $this->stable_hash($incomingDescription),
            'descriptions_empty_from_ovoko' => $incomingDescription === '',
            'description' => [
                'current_length' => mb_strlen($currentDescription),
                'current_hash' => $this->stable_hash($currentDescription),
                'incoming_length' => mb_strlen($incomingDescription),
                'incoming_hash' => $this->stable_hash($incomingDescription),
                'sample_excerpt' => mb_substr(wp_strip_all_tags($incomingDescription), 0, 180),
            ],
            'details_would_update' => !$alreadySynced && $incomingDetails !== [] && $currentDetailsHash !== $incomingDetailsHash,
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
            'categories_would_update' => !$alreadySynced && $categoriesWouldUpdate,
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
        $keys = ['existing_product_price_untouched','existing_product_internal_notes_price_ignored','existing_product_internal_notes_missing_ignored','new_product_price_from_internal_notes_ok','new_product_missing_price_in_internal_notes','new_product_invalid_internal_notes_price','new_product_would_create_as_draft_or_skip_due_to_missing_price','existing_products_images_untouched','new_products_images_would_import','existing_products_missing_images_warning','descriptions_would_update','descriptions_empty_from_ovoko','details_would_update','details_missing_from_ovoko','details_empty_from_ovoko','details_fields_changed','stock_would_update','categories_would_update','products_would_create','products_would_update','products_would_skip','products_would_skip_already_synced','products_unavailable_in_ovoko'];
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

    private function normalize_payload_for_idempotency(string $partId, string $updatedAt, array $payload): array
    {
        ksort($payload);
        return [
            'part_id' => $partId,
            'updated_at' => $updatedAt,
            'payload' => $payload,
        ];
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

    private function build_current_date_from_window(string $lastSuccessfulSyncDate, string $requestedDateFrom = ''): array
    {
        $requestedDateFrom = trim($requestedDateFrom);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDateFrom)) {
            $dateFrom = $requestedDateFrom;
            $source = 'manual_requested_date_from';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($lastSuccessfulSyncDate))) {
            $timestamp = strtotime(trim($lastSuccessfulSyncDate) . ' 00:00:00 UTC') ?: time();
            $dateFrom = gmdate('Y-m-d', $timestamp - DAY_IN_SECONDS);
            $source = 'last_successful_sync_date_minus_1_day_overlap';
        } else {
            $dateFrom = gmdate('Y-m-d');
            $source = 'fallback_today';
        }

        return [
            'date_from' => $dateFrom,
            'delta_from' => $dateFrom,
            'delta_to' => gmdate('Y-m-d'),
            'overlap_days' => $source === 'last_successful_sync_date_minus_1_day_overlap' ? 1 : 0,
            'filter_granularity' => 'date_only',
            'source' => $source,
            'idempotency_required' => true,
            'idempotency_meta_keys' => ['_gpswiss_ovoko_last_synced_updated_at', '_gpswiss_ovoko_last_synced_hash'],
        ];
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
            'last_successful_sync_at' => (string) ($report['last_successful_sync_at'] ?? ''),
            'last_successful_sync_date' => (string) ($report['last_successful_sync_date'] ?? ''),
            'last_successful_dry_run_at' => (string) ($report['last_successful_dry_run_at'] ?? ''),
            'last_successful_dry_run_date' => (string) ($report['last_successful_dry_run_date'] ?? ''),
            'last_attempted_sync_at' => (string) ($report['last_attempted_sync_at'] ?? gmdate('c')),
            'last_delta_from' => (string) ($report['current_delta_window']['delta_from'] ?? ''),
            'last_delta_to' => (string) ($report['current_delta_window']['delta_to'] ?? ''),
            'date_from' => (string) ($report['date_from'] ?? $report['date_from_used'] ?? ''),
            'date_from_used' => (string) ($report['date_from_used'] ?? $report['date_from'] ?? ''),
            'pages_processed_for_date_window' => (int) ($report['pages_processed_for_date_window'] ?? 0),
            'current_delta_window' => (array) ($report['current_delta_window'] ?? []),
            'last_cursor' => $report['last_cursor'] ?? [],
            'processed_changed_products' => (int) ($report['processed_changed_products'] ?? $report['processed'] ?? 0),
            'returned_records_count' => (int) ($report['returned_records_count'] ?? $report['processed'] ?? 0),
            'updated_at_stats' => (array) ($report['updated_at_stats'] ?? []),
            'processed' => (int) ($report['processed'] ?? 0),
            'created' => (int) ($counts['products_would_create'] ?? 0),
            'updated' => (int) ($counts['products_would_update'] ?? 0),
            'skipped' => (int) ($counts['products_would_skip'] ?? 0),
            'created_from_delta' => (int) ($counts['products_would_create'] ?? 0),
            'updated_from_delta' => (int) ($counts['products_would_update'] ?? 0),
            'skipped_already_synced' => (int) ($counts['products_would_skip_already_synced'] ?? 0),
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


    private function delta_cron_rules(string $confirmedFilter): array
    {
        $filter = $confirmedFilter !== '' ? $confirmedFilter : 'not_confirmed';
        return [
            'delta_sync_confirmed' => $confirmedFilter !== '',
            'delta_filter_used' => $filter,
            'no_full_scan' => true,
            'no_page_based_full_dataset_scan' => true,
            'paging_scope' => 'Page/cursor only inside the confirmed delta result window, never across the full catalog.',
            'lock' => self::LOCK_OPTION,
            'status_fields' => ['delta_sync_confirmed','delta_filter_used','last_successful_sync_at','last_successful_sync_date','last_delta_from','last_delta_to','last_cursor/page','processed_changed_products','skipped_already_synced','created_from_delta','updated_from_delta','errors','warnings'],
            'date_from_dedup_required' => $confirmedFilter === 'date_from',
            'date_from_dedup_meta_keys' => ['_gpswiss_ovoko_last_synced_updated_at','_gpswiss_ovoko_last_synced_hash'],
            'date_from_skip_condition' => 'If part_id + updated_at + normalized payload hash did not change, skip/no-op.',
        ];
    }

    private function live_sync_scope_design(): array
    {
        return [
            'existing_woo_products' => [
                'stock_status' => 'sync',
                'description' => 'sync',
                'technical_details' => 'sync',
                'categories' => 'verify_only_do_not_change',
                'price' => 'do_not_touch',
                'images' => 'do_not_touch',
            ],
            'new_ovoko_products' => [
                'create_only_when_internal_notes_price_valid' => true,
                'price_source' => 'internal_notes_only',
                'categories_source' => 'category_id + /get/categories/tree',
                'images' => 'may_import_from_ovoko_for_new_products_only',
                'missing_price_policy' => 'skip_or_draft_but_do_not_publish',
            ],
        ];
    }

    private function sale_stock_public_docs_probe(): array
    {
        $settings = $this->integrationService->get_settings();
        $baseUrl = rtrim((string) ($settings['rrr_api_base_url'] ?? 'https://api.rrr.lt'), '/');
        $paths = ['/docs', '/docs/', '/swagger', '/swagger.json', '/openapi.json', '/openapi/swagger.yaml'];
        $results = [];

        if (!function_exists('wp_remote_get')) {
            return [
                'executed' => false,
                'reason' => 'wordpress_http_api_unavailable',
                'paths' => $paths,
            ];
        }

        foreach ($paths as $path) {
            $response = wp_remote_get($baseUrl . $path, ['timeout' => 8]);
            if (is_wp_error($response)) {
                $results[] = [
                    'path' => $path,
                    'ok' => false,
                    'http_status' => null,
                    'error' => $response->get_error_code() . ': ' . $response->get_error_message(),
                ];
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            $results[] = [
                'path' => $path,
                'ok' => $code >= 200 && $code < 300,
                'http_status' => $code,
                'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
                'body_preview' => mb_substr($body, 0, 240),
            ];
        }

        return [
            'executed' => true,
            'base_url' => $baseUrl,
            'method' => 'GET',
            'write_safe' => true,
            'results' => $results,
            'confirmed_openapi_contract_found' => count(array_filter($results, static fn(array $row): bool => !empty($row['ok']))) > 0,
        ];
    }

    private function sale_stock_endpoint_analysis(): array
    {
        return [
            'mode' => 'confirmed_change_part_status_endpoint_but_automatic_worker_disabled',
            'endpoint_candidates' => [
                '/crm/changePartStatus' => [
                    'exists_in_current_client' => true,
                    'confirmed_in_docs_for_this_account' => true,
                    'payload_confirmed' => true,
                    'safe_to_use_for_manual_single_order_probe' => true,
                    'safe_to_use_for_automatic_worker' => false,
                    'evidence' => 'Official Ovoko/RRR OpenAPI confirmation: POST form-urlencoded auth + part_id + status. Success requires HTTP 200 and JSON status_code=R200.',
                ],
                '/crm/updatePart' => [
                    'exists_in_current_client' => true,
                    'confirmed_in_docs_for_this_account' => true,
                    'payload_confirmed_for_sale_stock_status' => false,
                    'safe_to_use_for_sale_stock_status' => false,
                    'implemented_payloads' => [
                        'part_id + internal_notes',
                        'part_id + place',
                    ],
                    'evidence' => 'Not used for Woo sale sync start because /crm/changePartStatus is dedicated to status-only changes.',
                ],
            ],
            'confirmed_endpoint' => '/crm/changePartStatus',
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'required_fields' => [
                'auth' => ['username', 'password', 'user_token'],
                'part_identifier' => 'part_id',
                'status' => '2 for Sold out / Sprzedano',
            ],
            'supported_status_values' => [
                '0' => 'In stock / Na stanie',
                '1' => 'Reserved / Zarezerwowano',
                '2' => 'Sold out / Sprzedano',
                '3' => 'Returned / Zwrot',
                '4' => 'Written off / Wycofany',
            ],
            'can_mark_sold' => true,
            'can_reserve' => true,
            'can_set_stock_zero' => false,
            'can_decrement_quantity' => false,
            'can_restore_on_cancel' => false,
            'risk_level' => 'low_for_manual_single_order_probe_with_confirmation_high_for_automation_until_probe_success',
            'docs_evidence' => [
                'official_rrr_openapi_confirmation' => 'POST /crm/changePartStatus, form-urlencoded, required username/password/user_token/part_id/status, success HTTP 200 + JSON status_code=R200.',
                'status_endpoint_decision' => 'Use status=2 for Woo sale. Do not use /crm/updatePart for initial sale sync.',
            ],
            'current_client_support' => [
                'read_parts' => ['/v2/get/parts?limit={limit}&page={page}', '/v2/get/parts?limit={limit}&page={page}&date_from=YYYY-MM-DD', '/crm/export/part', '/get/part/{part_id}'],
                'write_probes' => ['/crm/updatePart internal_notes only', '/crm/updatePart place only'],
                'sale_status_write' => '/crm/changePartStatus status=2',
                'automatic_worker' => false,
                'checkout_hook_live' => false,
            ],
            'crm_update_part_assessment' => [
                'can_be_safely_used_for_status_stock_sale' => false,
                'reason' => '/crm/changePartStatus is the dedicated and safer endpoint for changing status only. /crm/updatePart sale fields stay out of scope for this first step.',
            ],
            'idempotency' => [
                'remote_idempotency_key_confirmed' => false,
                'local_design' => 'One ovoko_sale_sync_request_id per Woo order item; skip live send when ovoko_sale_sync_status=success.',
                'meta_keys' => $this->sale_sync_meta_keys(),
            ],
            'trigger_design' => $this->sale_queue_design(),
            'cancel_refund_design' => 'Status 0 (In stock) and 3 (Returned) exist, but no automatic restore/return action is implemented until a business decision is made.',
            'manual_single_order_live_probe_enabled' => true,
            'live_write_enabled' => false,
            'automatic_live_worker_enabled' => false,
            'no_woo_write' => true,
            'decision' => 'Dry-run and admin-only single-order live probe are available. No automatic Woo → Ovoko worker, cron, or checkout hook is enabled yet.',
        ];
    }

    public function single_order_live_probe_mark_sold(int $orderId, string $confirmation): array
    {
        $confirmation = trim($confirmation);
        $client = new RrrApiClient($this->integrationService->get_settings());
        $result = [
            'ok' => false,
            'action_name' => 'Single-order live probe: mark Ovoko part sold from Woo order',
            'mode' => 'admin_manual_single_order_live_probe',
            'order_id' => $orderId,
            'required_confirmation' => 'MARK OVOKO PART SOLD',
            'confirmed_endpoint' => '/crm/changePartStatus',
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'sent_status' => 2,
            'no_woo_product_stock_change' => true,
            'automatic_worker_enabled' => false,
            'checkout_hook_live_enabled' => false,
            'warnings' => [],
            'errors' => [],
            'item' => null,
            'before_part' => null,
            'api_response' => null,
            'after_part' => null,
            'checked_at' => gmdate('c'),
        ];

        if ($orderId <= 0) {
            $result['errors'][] = 'missing_order_id';
            return $result;
        }

        if ($confirmation !== 'MARK OVOKO PART SOLD') {
            $result['errors'][] = 'confirmation_phrase_mismatch';
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

        $items = $order->get_items();
        if (count($items) !== 1) {
            $result['errors'][] = 'single_order_item_required';
            $result['item_count'] = count($items);
            return $result;
        }

        $itemId = (int) array_key_first($items);
        $item = $items[$itemId];
        $product = method_exists($item, 'get_product') ? $item->get_product() : null;
        $productId = $product ? (int) $product->get_id() : (method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0);
        $parentProductId = $product && method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $ovokoId = $productId > 0 ? $this->get_first_product_meta($productId, ['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id']) : '';
        if ($ovokoId === '' && $parentProductId > 0) {
            $ovokoId = $this->get_first_product_meta($parentProductId, ['_ovoko_part_id', 'ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id']);
        }

        $syncMeta = $this->get_order_item_sale_sync_meta($itemId);
        $requestId = (string) ($syncMeta['ovoko_sale_sync_request_id'] ?? '');
        if ($requestId === '') {
            $requestId = 'woo-' . $orderId . '-item-' . $itemId . '-part-' . ($ovokoId !== '' ? $ovokoId : 'missing');
        }

        $result['item'] = [
            'order_item_id' => $itemId,
            'order_id' => $orderId,
            'product_id' => $productId,
            'parent_product_id' => $parentProductId,
            'sku' => $product ? (string) $product->get_sku() : '',
            'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
            'quantity' => method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : null,
            'ovoko_id' => $ovokoId,
            'part_id' => $ovokoId,
            'request_id' => $requestId,
            'sync_meta_before' => $syncMeta,
        ];

        if ((string) ($syncMeta['ovoko_sale_sync_status'] ?? '') === 'success') {
            $result['ok'] = true;
            $result['skipped'] = true;
            $result['warnings'][] = 'already_successfully_synced_no_second_send';
            return $result;
        }

        if ($productId <= 0 || $ovokoId === '' || !preg_match('/^\d+$/', $ovokoId)) {
            $result['errors'][] = $productId <= 0 ? 'missing_product_id' : 'missing_or_invalid_ovoko_part_id_product_meta';
            return $result;
        }

        $beforeFetch = $client->preview_fetch_single_part((int) $ovokoId);
        $result['before_part'] = $this->summarize_ovoko_part_fetch($client, $beforeFetch);

        $attemptedAt = gmdate('c');
        $apiResponse = $client->change_part_status($ovokoId, 2);
        $result['api_response'] = $apiResponse;

        $afterFetch = !empty($apiResponse['ok']) ? $client->preview_fetch_single_part((int) $ovokoId) : ['ok' => false, 'message' => 'Skipped read-after because changePartStatus did not succeed.'];
        $result['after_part'] = $this->summarize_ovoko_part_fetch($client, $afterFetch);
        $afterStatus = (string) ($result['after_part']['status'] ?? '');
        $success = !empty($apiResponse['ok']) && $afterStatus === '2';
        $error = '';

        if (empty($apiResponse['ok'])) {
            $error = (string) ($apiResponse['message'] ?? 'changePartStatus failed');
        } elseif ($afterStatus !== '2') {
            $error = 'read_after_status_mismatch_expected_2_got_' . ($afterStatus !== '' ? $afterStatus : 'missing');
        }

        if (function_exists('wc_update_order_item_meta')) {
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_status', $success ? 'success' : 'failed');
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_attempted_at', $attemptedAt);
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_success_at', $success ? gmdate('c') : '');
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_error', $error);
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_request_id', $requestId);
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_part_id', $ovokoId);
            wc_update_order_item_meta($itemId, 'ovoko_sale_sync_order_id', (string) $orderId);
            wc_update_order_item_meta($itemId, 'retry_count', (string) ($success ? (int) ($syncMeta['retry_count'] ?? 0) : ((int) ($syncMeta['retry_count'] ?? 0) + 1)));
        } else {
            $result['warnings'][] = 'wc_update_order_item_meta_unavailable_meta_not_written';
        }

        $result['ok'] = $success;
        $result['meta_written'] = function_exists('wc_update_order_item_meta');
        $result['sync_meta_after'] = $this->get_order_item_sale_sync_meta($itemId);
        if (!$success && $error !== '') {
            $result['errors'][] = $error;
        }

        return $result;
    }

    private function summarize_ovoko_part_fetch(RrrApiClient $client, array $fetch): array
    {
        $payload = is_array($fetch['payload'] ?? null) ? (array) $fetch['payload'] : [];
        $record = $payload !== [] ? $client->extract_single_part_record($payload) : [];
        if ($record === [] && is_array($fetch['raw_records'][0] ?? null)) {
            $record = (array) $fetch['raw_records'][0];
        }
        if ($record === [] && is_array($fetch['first_record'] ?? null)) {
            $record = (array) $fetch['first_record'];
        }

        return [
            'ok' => !empty($fetch['ok']) || !empty($fetch['success']),
            'http_code' => $fetch['http_code'] ?? null,
            'status_code' => (string) ($fetch['status_code'] ?? ''),
            'message' => (string) ($fetch['msg'] ?? $fetch['message'] ?? ''),
            'part_id' => sanitize_text_field((string) ($record['id'] ?? $record['part_id'] ?? '')),
            'status' => sanitize_text_field((string) ($record['status'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($record['updated_at'] ?? '')),
            'name' => sanitize_text_field((string) ($record['name'] ?? '')),
            'record_found' => $record !== [],
        ];
    }

    private function sale_sync_meta_keys(): array
    {
        return ['ovoko_sale_sync_status','ovoko_sale_sync_attempted_at','ovoko_sale_sync_success_at','ovoko_sale_sync_error','ovoko_sale_sync_request_id','ovoko_sale_sync_part_id','ovoko_sale_sync_order_id','retry_count'];
    }

    private function get_order_item_sale_sync_meta(int $itemId): array
    {
        $meta = [];
        foreach ($this->sale_sync_meta_keys() as $key) {
            $meta[$key] = function_exists('wc_get_order_item_meta') ? (string) wc_get_order_item_meta($itemId, $key, true) : '';
        }
        return $meta;
    }

    private function sale_queue_design(): array
    {
        return [
            'live_enabled' => false,
            'trigger_hooks_to_prepare_later' => [
                'woocommerce_payment_complete',
                'woocommerce_order_status_processing',
                'woocommerce_order_status_completed',
            ],
            'cancel_refund_hooks_separate_flow' => [
                'woocommerce_order_status_cancelled',
                'woocommerce_order_refunded',
            ],
            'checkout_policy' => 'Never call Ovoko directly during checkout/payment completion; enqueue a job only after a safe order status transition.',
            'worker' => 'Separate WP-Cron/server-cron worker sends queued jobs to Ovoko after endpoint confirmation.',
            'retry' => [
                'retry_on' => ['network_error', '5xx', 'rate_limit'],
                'do_not_retry_on' => ['missing_part_id', 'endpoint_not_confirmed', 'already_success'],
                'retry_count_field' => 'retry_count',
                'last_error_field' => 'last_error',
                'strategy' => 'bounded exponential backoff with admin-visible failed state',
            ],
            'admin_panel_fields' => ['pending_sale_sync', 'success_sale_sync', 'failed_sale_sync', 'retry_count', 'last_error', 'sample_failed_order_items'],
            'idempotency' => 'One request_id per order item; skip if order item meta ovoko_sale_sync_status is success.',
            'queue_storage_candidate' => self::SALE_QUEUE_OPTION,
        ];
    }

    private function sync_modes_design(): array
    {
        return ['dry_run_sync' => 'Manual diagnostics only; page-based dry-run is not a cron strategy.', 'manual_run' => 'Admin Run sync now button remains disabled for live writes until delta sync is confirmed.', 'wp_cron_server_cron' => 'Do not schedule live page-based scans; enable cron only after official Ovoko/RRR delta endpoint or date filter is confirmed.'];
    }

    private function cron_design(): array
    {
        return ['live_cron' => 'disabled_until_delta_sync_confirmed', 'delta_window' => 'delta_from = last_successful_sync_at - 5 minutes overlap; delta_to = now - 2 minutes delay', 'cursor' => 'last_cursor/page is only for the current delta window; never for full-dataset cron scans', 'lock' => self::LOCK_OPTION, 'status_fields' => ['running/idle/error','delta_sync_confirmed','delta_filter_used','last_successful_sync_at','last_successful_sync_date','last_attempted_sync_at','last_delta_from','last_delta_to','last_cursor/page','processed_changed_products','skipped_already_synced','created_from_delta','updated_from_delta','errors','warnings'], 'parallel_runs' => 'blocked by lock/transient before any work starts'];
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
