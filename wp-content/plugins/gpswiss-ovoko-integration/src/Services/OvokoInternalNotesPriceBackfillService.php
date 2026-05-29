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
