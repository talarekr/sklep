<?php

namespace GPSwiss\Ovoko\Services;

class OvokoInternalNotesPriceBackfillService
{
    private const PRICE_LINE_PREFIX = 'woo_price=';

    public function __construct(private OvokoIntegrationService $service)
    {
    }

    public function endpoint_capability_analysis(): array
    {
        return [
            'ok' => true,
            'action_name' => 'Analyze Ovoko internal_notes update capability',
            'mode' => 'analysis_only_no_write',
            'checked_at' => gmdate('c'),
            'documentation_source' => [
                'url' => 'https://api.rrr.lt/openapi/swagger.yaml',
                'verified_on' => '2026-05-29',
                'summary' => 'Official RRR OpenAPI spec lists POST /crm/updatePart with application/x-www-form-urlencoded body and updatePartRequest schema.',
            ],
            'endpoint' => [
                'method' => 'POST',
                'path' => '/crm/updatePart',
                'content_type' => 'application/x-www-form-urlencoded',
                'required_parameters' => ['username', 'password', 'user_token', 'part_id'],
                'target_identifier' => 'part_id (Ovoko/RRR part id stored locally as _ovoko_part_id or ovoko_id)',
                'internal_notes_parameter' => 'internal_notes',
                'success_rule' => 'Treat as success only when JSON status_code is R200; HTTP 200 alone is not enough.',
            ],
            'partial_update_assessment' => [
                'can_send_only_internal_notes_plus_auth_and_part_id' => true,
                'full_payload_required_by_schema' => false,
                'update_semantics' => 'partial_update_by_omitted_fields_in_schema',
                'evidence' => 'OpenAPI updatePartRequest requires only username, password, user_token and part_id. Other fields, including category_id, price, status, notes, place, photo/photos and internal_notes, are optional.',
                'risk_level' => 'medium_until_live_probe',
                'risk_notes' => [
                    'The schema indicates omitted optional fields are not required, but the public spec does not explicitly state whether omitted fields are ignored or nulled server-side.',
                    'Do not run live backfill until a controlled single-part probe confirms that sending only part_id + internal_notes leaves price, stock/status, photos, public notes, category and description unchanged.',
                    'This implementation intentionally has no live write path.',
                ],
            ],
            'fields_not_sent_by_dry_run' => ['price', 'original_currency', 'status', 'quality', 'category_id', 'car_id', 'notes', 'place', 'photo', 'photos', 'manufacturer_code', 'visible_code', 'other_code'],
            'future_live_backfill_design' => $this->future_live_backfill_design(),
            'no_write_to_ovoko' => true,
            'no_write_to_woo' => true,
        ];
    }

    public function dry_run(array $options = []): array
    {
        $maxProducts = isset($options['max_products']) ? (int) $options['max_products'] : 100;
        $maxProducts = max(1, min(500, $maxProducts));
        $afterProductId = isset($options['after_product_id']) ? max(0, (int) $options['after_product_id']) : 0;
        $sampleLimit = isset($options['sample_limit']) ? (int) $options['sample_limit'] : 50;
        $sampleLimit = max(1, min(50, $sampleLimit));

        $client = new RrrApiClient($this->service->get_settings());
        $productIds = $this->query_product_ids($maxProducts, $afterProductId);
        $totalProducts = $this->count_woo_products();

        $report = [
            'ok' => true,
            'action_name' => 'Dry-run backfill Ovoko internal_notes prices from Woo',
            'mode' => 'dry_run_only_no_write',
            'checked_at' => gmdate('c'),
            'endpoint_analysis' => $this->endpoint_capability_analysis(),
            'query' => [
                'max_products' => $maxProducts,
                'after_product_id' => $afterProductId,
                'processed_products' => 0,
                'next_after_product_id' => 0,
                'sample_limit' => $sampleLimit,
            ],
            'woo_products_total' => $totalProducts,
            'counter_scope' => 'Counters other than woo_products_total apply to this dry-run batch only.',
            'woo_products_with_ovoko_id' => 0,
            'woo_products_missing_ovoko_id' => 0,
            'woo_products_with_price' => 0,
            'woo_products_missing_price' => 0,
            'ovoko_internal_notes_empty' => 0,
            'ovoko_internal_notes_has_price' => 0,
            'ovoko_internal_notes_would_append_price' => 0,
            'conflicts_existing_different_price' => 0,
            'api_fetch_errors' => 0,
            'sample' => [],
            'safety' => [
                'writes_to_ovoko' => false,
                'writes_to_woo' => false,
                'changes_products' => false,
                'changes_stock' => false,
                'changes_descriptions' => false,
                'changes_categories' => false,
                'touches_images' => false,
            ],
            'proposed_format' => self::PRICE_LINE_PREFIX . '350.00 PLN',
            'future_live_backfill_design' => $this->future_live_backfill_design(),
        ];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            $report['query']['processed_products']++;
            $report['query']['next_after_product_id'] = $productId;

            $ovokoId = $this->get_product_ovoko_id($productId);
            $wooPrice = $this->get_product_price($productId);
            $sampleRow = [
                'product_id' => $productId,
                'ovoko_id' => $ovokoId,
                'woo_price' => $wooPrice,
                'current_internal_notes_excerpt' => '',
                'detected_internal_notes_price' => null,
                'proposed_internal_notes_excerpt' => '',
                'action' => '',
            ];

            if ($wooPrice === null) {
                $report['woo_products_missing_price']++;
            } else {
                $report['woo_products_with_price']++;
            }

            if ($ovokoId === '') {
                $report['woo_products_missing_ovoko_id']++;
                $sampleRow['action'] = 'skip_missing_ovoko_id';
                $this->append_sample($report, $sampleRow, $sampleLimit);
                continue;
            }
            $report['woo_products_with_ovoko_id']++;

            $fetch = $client->preview_fetch_single_part((int) $ovokoId);
            if (empty($fetch['ok'])) {
                $report['api_fetch_errors']++;
                $sampleRow['action'] = 'error';
                $sampleRow['error'] = (string) ($fetch['msg'] ?? $fetch['message'] ?? $fetch['status_code'] ?? 'fetch_failed');
                $this->append_sample($report, $sampleRow, $sampleLimit);
                continue;
            }

            $record = $client->extract_single_part_record((array) ($fetch['payload'] ?? []));
            $currentNotes = is_scalar($record['internal_notes'] ?? null) ? (string) $record['internal_notes'] : '';
            $detected = $this->detect_internal_notes_price($currentNotes);
            $proposedNotes = $wooPrice === null ? $currentNotes : $this->append_price_line($currentNotes, $wooPrice);

            $sampleRow['current_internal_notes_excerpt'] = $this->excerpt($currentNotes);
            $sampleRow['detected_internal_notes_price'] = $detected['price'];
            $sampleRow['proposed_internal_notes_excerpt'] = $this->excerpt($proposedNotes);

            if (trim($currentNotes) === '') {
                $report['ovoko_internal_notes_empty']++;
            }
            if ($detected['found']) {
                $report['ovoko_internal_notes_has_price']++;
            }

            if ($wooPrice === null) {
                $sampleRow['proposed_internal_notes_excerpt'] = $sampleRow['current_internal_notes_excerpt'];
                $sampleRow['action'] = 'skip_missing_price';
                $this->append_sample($report, $sampleRow, $sampleLimit);
                continue;
            }

            if ($detected['found']) {
                if (!$this->prices_equal((float) $detected['price'], $wooPrice)) {
                    $report['conflicts_existing_different_price']++;
                    $sampleRow['action'] = 'conflict';
                } else {
                    $sampleRow['action'] = 'skip_has_price';
                }
                $this->append_sample($report, $sampleRow, $sampleLimit);
                continue;
            }

            $report['ovoko_internal_notes_would_append_price']++;
            $sampleRow['action'] = 'append';
            $this->append_sample($report, $sampleRow, $sampleLimit);
        }

        return $report;
    }

    public function single_part_live_probe(array $input = []): array
    {
        $productId = isset($input['product_id']) ? (int) $input['product_id'] : 0;
        $ovokoId = trim((string) ($input['ovoko_id'] ?? ''));
        $confirmation = trim((string) ($input['confirmation'] ?? ''));
        $expectedConfirmation = 'UPDATE OVOKO INTERNAL NOTES ONLY';

        $report = [
            'ok' => false,
            'action_name' => 'Single-part live probe: update Ovoko internal_notes only',
            'mode' => 'single_part_live_write_internal_notes_only',
            'checked_at' => gmdate('c'),
            'status' => 'not_started',
            'critical_warning' => false,
            'critical_warning_code' => '',
            'inputs' => [
                'product_id' => $productId > 0 ? $productId : null,
                'ovoko_id' => $ovokoId,
                'confirmation_matches' => $confirmation === $expectedConfirmation,
            ],
            'safety' => [
                'writes_to_woo' => false,
                'changes_stock' => false,
                'changes_ovoko_price' => false,
                'changes_ovoko_category' => false,
                'changes_ovoko_photos' => false,
                'changes_public_description' => false,
                'write_scope' => 'POST /crm/updatePart with auth, part_id and internal_notes only',
            ],
            'request' => [
                'endpoint_path' => '/crm/updatePart',
                'sent_fields' => ['username' => '[redacted]', 'password' => '[redacted]', 'user_token' => '[redacted]', 'part_id' => null, 'internal_notes' => '[computed_after_read_before]'],
            ],
            'before' => [],
            'after' => [],
            'comparison' => [],
            'update_response' => [],
        ];

        if ($confirmation !== $expectedConfirmation) {
            $report['status'] = 'failed_confirmation_required';
            $report['message'] = 'Type the exact confirmation phrase before running this live probe.';
            return $report;
        }

        if (($productId > 0 && $ovokoId !== '') || ($productId <= 0 && $ovokoId === '')) {
            $report['status'] = 'failed_identifier_scope';
            $report['message'] = 'Provide exactly one identifier: either product_id or ovoko_id.';
            return $report;
        }

        if ($ovokoId !== '' && !preg_match('/^\d+$/', $ovokoId)) {
            $report['status'] = 'failed_invalid_ovoko_id';
            $report['message'] = 'ovoko_id must be numeric.';
            return $report;
        }

        if ($productId > 0) {
            $ovokoId = $this->get_product_ovoko_id($productId);
            if ($ovokoId === '') {
                $report['status'] = 'failed_missing_product_ovoko_id';
                $report['message'] = 'Woo product does not have _ovoko_part_id/ovoko_id meta.';
                return $report;
            }
        } else {
            $productId = $this->find_product_id_by_ovoko_id($ovokoId);
            if ($productId <= 0) {
                $report['status'] = 'failed_missing_woo_product_for_ovoko_id';
                $report['message'] = 'A Woo product is required so the probe can append the Woo price.';
                return $report;
            }
        }

        $wooPrice = $this->get_product_price($productId);
        $report['inputs']['product_id'] = $productId;
        $report['inputs']['ovoko_id'] = $ovokoId;
        $report['inputs']['woo_price'] = $wooPrice;
        $report['request']['sent_fields']['part_id'] = $ovokoId;

        if ($wooPrice === null) {
            $report['status'] = 'failed_missing_woo_price';
            $report['message'] = 'Woo product has no positive price to append.';
            return $report;
        }

        $client = new RrrApiClient($this->service->get_settings());
        $beforeFetch = $client->preview_fetch_single_part((int) $ovokoId);
        if (empty($beforeFetch['ok'])) {
            $report['status'] = 'failed_read_before';
            $report['read_before_response'] = $this->summarize_fetch_response($beforeFetch);
            return $report;
        }

        $beforeRecord = $client->extract_single_part_record((array) ($beforeFetch['payload'] ?? []));
        $beforeSnapshot = $this->snapshot_probe_fields($beforeRecord);
        $currentNotes = is_scalar($beforeRecord['internal_notes'] ?? null) ? (string) $beforeRecord['internal_notes'] : '';
        $detected = $this->detect_internal_notes_price($currentNotes);
        $proposedNotes = $this->append_price_line($currentNotes, $wooPrice);
        $report['before'] = $beforeSnapshot;
        $report['proposed_internal_notes_line'] = self::PRICE_LINE_PREFIX . number_format($wooPrice, 2, '.', '') . ' PLN';
        $report['proposed_internal_notes_excerpt'] = $this->excerpt($proposedNotes);

        if ($detected['found']) {
            $report['status'] = 'failed_existing_price_marker';
            $report['message'] = 'Current internal_notes already contain a supported price marker; probe will not append a duplicate line.';
            $report['detected_internal_notes_price'] = $detected;
            return $report;
        }

        $update = $client->update_part_internal_notes_only($ovokoId, $proposedNotes);
        $report['update_response'] = $this->sanitize_update_response($update);
        if ((string) ($update['status_code'] ?? '') !== 'R200') {
            $report['status'] = 'failed_updatepart_status_code_not_R200';
            $report['message'] = 'updatePart did not return status_code=R200.';
            return $report;
        }

        $afterFetch = $client->preview_fetch_single_part((int) $ovokoId);
        if (empty($afterFetch['ok'])) {
            $report['status'] = 'failed_read_after';
            $report['read_after_response'] = $this->summarize_fetch_response($afterFetch);
            return $report;
        }

        $afterRecord = $client->extract_single_part_record((array) ($afterFetch['payload'] ?? []));
        $afterSnapshot = $this->snapshot_probe_fields($afterRecord);
        $report['after'] = $afterSnapshot;
        $comparison = $this->compare_probe_snapshots($beforeSnapshot, $afterSnapshot, $proposedNotes);
        $report['comparison'] = $comparison;

        if (!empty($comparison['unexpected_field_changed'])) {
            $report['status'] = 'critical_warning';
            $report['critical_warning'] = true;
            $report['critical_warning_code'] = 'unexpected_field_changed';
            $report['ok'] = false;
            return $report;
        }

        if (empty($comparison['internal_notes_changed_expected'])) {
            $report['status'] = 'failed_internal_notes_not_changed_as_expected';
            return $report;
        }

        $report['ok'] = true;
        $report['status'] = 'success_internal_notes_only_confirmed_for_this_probe';
        return $report;
    }

    private function query_product_ids(int $maxProducts, int $afterProductId): array
    {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status IN ('publish', 'draft', 'private')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $afterProductId,
            $maxProducts
        ));

        return array_map('intval', (array) $rows);
    }

    private function count_woo_products(): int
    {
        $counts = wp_count_posts('product');
        return (int) (($counts->publish ?? 0) + ($counts->draft ?? 0) + ($counts->private ?? 0));
    }

    private function get_product_ovoko_id(int $productId): string
    {
        $ovokoId = trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        if ($ovokoId === '') {
            $ovokoId = trim((string) get_post_meta($productId, 'ovoko_id', true));
        }
        return preg_match('/^\d+$/', $ovokoId) ? $ovokoId : '';
    }

    private function get_product_price(int $productId): ?float
    {
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        if (!$product) {
            return null;
        }

        $raw = (string) $product->get_regular_price();
        if (trim($raw) === '') {
            $raw = (string) $product->get_price();
        }
        $raw = str_replace(',', '.', trim($raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $price = (float) $raw;
        return $price > 0 ? round($price, 2) : null;
    }

    private function detect_internal_notes_price(string $notes): array
    {
        $trimmed = trim($notes);
        if ($trimmed === '') {
            return ['found' => false, 'price' => null, 'source' => 'empty'];
        }

        if (preg_match('/(?:^|\R)\s*woo_price\s*=\s*(\d+(?:[\.,]\d{1,2})?)\s*(?:PLN|zł)?\s*(?:\R|$)/iu', $notes, $match)) {
            return ['found' => true, 'price' => round((float) str_replace(',', '.', $match[1]), 2), 'source' => 'woo_price_line'];
        }

        if (preg_match('/^\s*(\d+(?:[\.,]\d{1,2})?)\s*(?:PLN|zł)?\s*$/iu', $trimmed, $match)) {
            return ['found' => true, 'price' => round((float) str_replace(',', '.', $match[1]), 2), 'source' => 'plain_numeric_legacy'];
        }

        return ['found' => false, 'price' => null, 'source' => 'none'];
    }

    private function append_price_line(string $currentNotes, float $wooPrice): string
    {
        $line = self::PRICE_LINE_PREFIX . number_format($wooPrice, 2, '.', '') . ' PLN';
        $trimmedRight = rtrim($currentNotes);
        if ($trimmedRight === '') {
            return $line;
        }
        return $trimmedRight . "\n" . $line;
    }

    private function prices_equal(float $left, float $right): bool
    {
        return abs(round($left, 2) - round($right, 2)) < 0.005;
    }

    private function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: '');
        return mb_substr($value, 0, 180);
    }

    private function append_sample(array &$report, array $row, int $sampleLimit): void
    {
        if (count($report['sample']) < $sampleLimit) {
            $report['sample'][] = $row;
        }
    }

    private function find_product_id_by_ovoko_id(string $ovokoId): int
    {
        global $wpdb;

        if ($ovokoId === '' || !preg_match('/^\d+$/', $ovokoId)) {
            return 0;
        }

        $productId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_ovoko_part_id', 'ovoko_id')
               AND meta_value = %s
             ORDER BY post_id ASC
             LIMIT 1",
            $ovokoId
        ));

        return $productId > 0 && get_post_type($productId) === 'product' ? $productId : 0;
    }

    private function snapshot_probe_fields(array $record): array
    {
        $photos = $this->extract_photos_for_snapshot($record);

        return [
            'internal_notes' => is_scalar($record['internal_notes'] ?? null) ? (string) $record['internal_notes'] : '',
            'price' => $this->snapshot_value($record['price'] ?? null),
            'status' => $this->snapshot_value($record['status'] ?? null),
            'category_id' => $this->snapshot_value($record['category_id'] ?? null),
            'notes' => $this->snapshot_value($record['notes'] ?? null),
            'place' => $this->snapshot_value($record['place'] ?? null),
            'photos' => [
                'count' => count($photos),
                'fingerprint' => md5(wp_json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                'sample' => array_slice($photos, 0, 5),
            ],
            'visible_code' => $this->snapshot_value($record['visible_code'] ?? null),
            'manufacturer_code' => $this->snapshot_value($record['manufacturer_code'] ?? null),
            'updated_at' => $this->snapshot_value($record['updated_at'] ?? null),
        ];
    }

    private function extract_photos_for_snapshot(array $record): array
    {
        $raw = [];
        if (isset($record['photos']) && is_array($record['photos'])) {
            $raw = $record['photos'];
        } elseif (isset($record['part_photo_gallery']) && is_array($record['part_photo_gallery'])) {
            $raw = $record['part_photo_gallery'];
        } elseif (isset($record['images']) && is_array($record['images'])) {
            $raw = $record['images'];
        } elseif (isset($record['photo']) && is_array($record['photo'])) {
            $raw = $record['photo'];
        } elseif (isset($record['photo']) && is_scalar($record['photo']) && (string) $record['photo'] !== '') {
            $raw = [(string) $record['photo']];
        }

        $photos = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $photos[] = $this->normalize_for_compare($item);
            } elseif (is_scalar($item)) {
                $photos[] = (string) $item;
            }
        }

        return $photos;
    }

    private function snapshot_value(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return $this->normalize_for_compare($value);
    }

    private function compare_probe_snapshots(array $before, array $after, string $expectedInternalNotes): array
    {
        $fields = ['price', 'status', 'category_id', 'notes', 'place', 'visible_code', 'manufacturer_code'];
        $comparison = [
            'internal_notes_changed_expected' => (string) ($after['internal_notes'] ?? '') === $expectedInternalNotes
                && (string) ($before['internal_notes'] ?? '') !== (string) ($after['internal_notes'] ?? ''),
            'price_unchanged' => true,
            'status_unchanged' => true,
            'category_id_unchanged' => true,
            'notes_unchanged' => true,
            'place_unchanged' => true,
            'photos_unchanged' => (($before['photos']['count'] ?? null) === ($after['photos']['count'] ?? null))
                && (($before['photos']['fingerprint'] ?? '') === ($after['photos']['fingerprint'] ?? '')),
            'visible_code_unchanged' => true,
            'manufacturer_code_unchanged' => true,
            'updated_at_before' => (string) ($before['updated_at'] ?? ''),
            'updated_at_after' => (string) ($after['updated_at'] ?? ''),
            'unexpected_field_changed' => false,
            'unexpected_changed_fields' => [],
        ];

        foreach ($fields as $field) {
            $unchanged = $this->canonical_compare_value($before[$field] ?? null) === $this->canonical_compare_value($after[$field] ?? null);
            $comparison[$field . '_unchanged'] = $unchanged;
            if (!$unchanged) {
                $comparison['unexpected_changed_fields'][] = $field;
            }
        }

        if (empty($comparison['photos_unchanged'])) {
            $comparison['unexpected_changed_fields'][] = 'photos';
        }

        $comparison['unexpected_field_changed'] = count($comparison['unexpected_changed_fields']) > 0;
        return $comparison;
    }

    private function canonical_compare_value(mixed $value): string
    {
        return wp_json_encode($this->normalize_for_compare($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function normalize_for_compare(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalize_for_compare($item);
            }
            ksort($normalized);
            return $normalized;
        }

        if (is_bool($value) || $value === null) {
            return $value;
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return (string) $value;
    }

    private function summarize_fetch_response(array $response): array
    {
        return [
            'ok' => !empty($response['ok']),
            'http_code' => $response['http_code'] ?? null,
            'status_code' => (string) ($response['status_code'] ?? ''),
            'message' => (string) ($response['msg'] ?? $response['message'] ?? ''),
        ];
    }

    private function sanitize_update_response(array $response): array
    {
        unset($response['raw_request'], $response['request_body'], $response['auth'], $response['username'], $response['password'], $response['user_token']);
        $response = $this->redact_secret_values($response);
        if (isset($response['sent_fields']) && is_array($response['sent_fields'])) {
            $response['sent_fields']['username'] = '[redacted]';
            $response['sent_fields']['password'] = '[redacted]';
            $response['sent_fields']['user_token'] = '[redacted]';
            $response['sent_fields']['internal_notes'] = '[provided]';
        }
        return $response;
    }

    private function redact_secret_values(mixed $value): mixed
    {
        $secretKeys = ['username', 'password', 'user_token', 'token', 'auth'];
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), $secretKeys, true)) {
                    $redacted[$key] = '[redacted]';
                    continue;
                }
                $redacted[$key] = $this->redact_secret_values($item);
            }
            return $redacted;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        $settings = $this->service->get_settings();
        foreach (['rrr_api_username', 'rrr_api_password', 'rrr_api_user_token'] as $settingKey) {
            $secret = (string) ($settings[$settingKey] ?? '');
            if ($secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        return $value;
    }

    private function future_live_backfill_design(): array
    {
        return [
            'implemented_now' => false,
            'batch_size' => 'default 25, max 100 after live approval',
            'cursor' => 'after_product_id, ascending Woo product ID, persisted in option gpswiss_ovoko_internal_notes_backfill_status',
            'lock' => 'transient gpswiss_ovoko_internal_notes_backfill_lock with short TTL to prevent concurrent batches',
            'status' => 'option stores idle/running/paused/completed/failed, processed counters, last cursor, last error and timestamps',
            'retry' => 'retry transient API/network failures up to 3 attempts with backoff; permanent validation conflicts are skipped and reported',
            'stop_on_error' => 'operator-configurable; default true for first live run',
            'write_scope' => 'POST /crm/updatePart with only username, password, user_token, part_id and internal_notes after read-before-write',
            'idempotency' => 'never append when a supported price marker already exists; re-fetch internal_notes immediately before write',
            'preflight_required' => 'single-part controlled live probe must confirm partial update does not overwrite omitted fields',
        ];
    }
}
