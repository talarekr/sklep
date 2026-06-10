<?php

namespace GPSwiss\Ovoko\Services;

use GPSwiss\Ovoko\DTO\NormalizedOvokoPart;

class OvokoProductSyncService
{
    private const PART_MATCH_META_KEYS = ['_ovoko_part_id', 'ovoko_part_id', 'part_id'];
    private const EXTERNAL_META_KEYS = ['external_id', 'source_part_id', 'external_part_id', '_allegro_source_id', '_ovoko_source_id'];
    private const GEARBOX_HARD_META_KEYS = ['_allegro_gearbox_id', '_gearbox_id', '_gpswiss_allegro_gearboxes'];
    private const GMAIL_PRODUCT_PART_META_KEYS = ['_ovoko_part_id', 'ovoko_part_id'];
    private const GMAIL_PRODUCT_SKU_PREFIX = 'GPS-GMAIL-';

    public function calculate_payload_hash(NormalizedOvokoPart $part): string
    {
        return hash('sha256', wp_json_encode($part->to_array()));
    }

    public function map_to_woo_meta_preview(NormalizedOvokoPart $part): array
    {
        $p = $part->to_array();
        return [
            '_ovoko_part_id' => (string) $p['part_id'], '_ovoko_status' => (string) $p['status'], '_ovoko_updated_at' => gmdate('c'),
            '_ovoko_source_payload_hash' => (string) $p['payload_hash'], '_ovoko_vehicle_make' => (string) $p['vehicle_make'],
            '_ovoko_vehicle_model' => (string) $p['vehicle_model'], '_ovoko_vehicle_generation' => (string) $p['vehicle_generation'],
            '_ovoko_year' => (string) $p['year'], '_ovoko_engine_code' => (string) $p['engine_code'], '_ovoko_gearbox_code' => (string) $p['gearbox_code'],
            '_ovoko_oe_numbers' => wp_json_encode((array) $p['oe_numbers']), '_ovoko_category' => (string) $p['category'], '_ovoko_source_url' => (string) $p['source_url'],
            '_ovoko_images' => wp_json_encode((array) $p['images']), '_ovoko_price' => (string) $p['price'], '_ovoko_raw_payload' => wp_json_encode((array) $p['raw_payload']),
            'source' => 'ovoko_master',
        ];
    }

    public function detect_changes(int $productId, NormalizedOvokoPart $part): array
    {
        $target = $this->map_to_woo_meta_preview($part);
        $changes = [];
        foreach ($target as $key => $newValue) {
            $old = (string) get_post_meta($productId, $key, true);
            if ($old !== (string) $newValue) {
                $changes[$key] = ['old' => $old, 'new' => (string) $newValue];
            }
        }
        return ['product_id' => $productId, 'changed_fields' => $changes, 'would_update' => !empty($changes)];
    }

    public function preview_match_existing_product(NormalizedOvokoPart $part): array
    {
        $partId = $part->get_part_id();
        foreach (self::PART_MATCH_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $partId);
            if (count($ids) === 1) return $this->apply_exclusion_to_match($this->match_result($ids[0], 'part_id_meta', $metaKey, 'high', false, 'Unique exact part_id meta match', []));
            if (count($ids) > 1) return $this->apply_exclusion_to_match($this->match_result(null, 'part_id_meta', $metaKey, 'low', true, 'Multiple products with same part_id meta', $ids));
        }
        foreach (self::EXTERNAL_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $partId);
            if (count($ids) === 1) return $this->apply_exclusion_to_match($this->match_result($ids[0], 'external_meta', $metaKey, 'medium', false, 'Matched external source meta', []));
        }
        $sku = (string) ($part->to_array()['part_id'] ?? '');
        if ($sku !== '') {
            $ids = $this->find_ids_by_meta('_sku', $sku);
            if (count($ids) === 1) return $this->apply_exclusion_to_match($this->match_result($ids[0], 'sku', '_sku', 'medium', true, 'SKU candidate only, requires review', []));
        }
        return $this->apply_exclusion_to_match($this->match_result(null, 'title_candidate', 'post_title', 'low', true, 'No safe exact identifier match. Title matching should be manual review.', []));
    }




    public function find_existing_gmail_product_by_ovoko_part_id(string $partId): array
    {
        $partId = trim($partId);
        if ($partId === '' || !post_type_exists('product')) {
            return ['found' => false, 'part_id' => $partId, 'product_id' => 0, 'sku' => '', 'matched_meta_key' => '', 'skip_reason' => ''];
        }

        foreach (self::GMAIL_PRODUCT_PART_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $partId);
            foreach ($ids as $productId) {
                $sku = (string) get_post_meta($productId, '_sku', true);
                if ($this->is_gps_gmail_sku($sku)) {
                    return [
                        'found' => true,
                        'part_id' => $partId,
                        'product_id' => $productId,
                        'sku' => $sku,
                        'matched_meta_key' => $metaKey,
                        'skip_reason' => 'skipped_existing_gmail_product_by_ovoko_part_id',
                    ];
                }
            }
        }

        return ['found' => false, 'part_id' => $partId, 'product_id' => 0, 'sku' => '', 'matched_meta_key' => '', 'skip_reason' => ''];
    }

    public function is_gps_gmail_product(int $productId): bool
    {
        if ($productId <= 0) {
            return false;
        }

        return $this->is_gps_gmail_sku((string) get_post_meta($productId, '_sku', true));
    }

    public function is_product_excluded_from_ovoko_sync(int $productId): array
    {
        if ($productId <= 0) {
            return ['excluded' => false, 'reason' => '', 'review_required' => false, 'signals' => []];
        }

        $signals = [];
        foreach (self::GEARBOX_HARD_META_KEYS as $metaKey) {
            $value = (string) get_post_meta($productId, $metaKey, true);
            if ($value !== '') {
                $signals[] = 'meta_key_present:' . $metaKey;
            }
        }

        $sourceValues = [
            'source' => (string) get_post_meta($productId, 'source', true),
            '_source' => (string) get_post_meta($productId, '_source', true),
            '_gpswiss_source' => (string) get_post_meta($productId, '_gpswiss_source', true),
        ];
        foreach ($sourceValues as $sourceKey => $sourceValue) {
            $normalized = strtolower(trim($sourceValue));
            if (in_array($normalized, ['gearboxes', 'allegro_gearboxes'], true)) {
                $signals[] = 'source_match:' . $sourceKey . '=' . $normalized;
            }
        }

        $warnings = [];
        $post = get_post($productId);
        $sku = strtolower((string) get_post_meta($productId, '_sku', true));
        $slug = $post ? strtolower((string) $post->post_name) : '';
        if ($sku !== '' && str_contains($sku, 'gearbox')) $warnings[] = 'sku_contains_gearbox';
        if ($slug !== '' && (str_contains($slug, 'gearbox') || str_contains($slug, 'skrzyn'))) $warnings[] = 'slug_contains_gearbox';

        $excluded = !empty($signals);
        return [
            'excluded' => $excluded,
            'reason' => $excluded ? 'gearbox_standalone_catalog' : '',
            'review_required' => $excluded || !empty($warnings),
            'signals' => $signals,
            'warnings' => $warnings,
        ];
    }

    public function count_excluded_gearbox_products(): int
    {
        if (!post_type_exists('product')) return 0;

        $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
        $count = 0;
        foreach ((array) $ids as $id) {
            $result = $this->is_product_excluded_from_ovoko_sync((int) $id);
            if (!empty($result['excluded'])) $count++;
        }
        return $count;
    }

    public function count_excluded_gearbox_products_sql_fast(): int
    {
        global $wpdb;
        if (!$wpdb || !post_type_exists('product')) {
            return 0;
        }

        $metaKeys = array_merge(self::GEARBOX_HARD_META_KEYS, ['source', '_source', '_gpswiss_source']);
        $placeholders = implode(', ', array_fill(0, count($metaKeys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are prepared via $wpdb->prepare
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private') AND pm.meta_key IN ($placeholders) AND ((pm.meta_key IN ('source', '_source', '_gpswiss_source') AND LOWER(pm.meta_value) IN ('gearboxes', 'allegro_gearboxes')) OR (pm.meta_key IN ('_allegro_gearbox_id', '_gearbox_id', '_gpswiss_allegro_gearboxes') AND pm.meta_value <> ''))";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $metaKeys));
    }

    private function apply_exclusion_to_match(array $match): array
    {
        $productId = (int) ($match['matched_product_id'] ?? 0);
        if ($productId <= 0) return $match + ['excluded_from_ovoko_sync' => false, 'exclusion_reason' => null];

        $excluded = $this->is_product_excluded_from_ovoko_sync($productId);
        if (!empty($excluded['excluded'])) {
            $match['excluded_from_ovoko_sync'] = true;
            $match['exclusion_reason'] = 'gearbox_standalone_catalog';
            $match['review_required'] = true;
            $match['confidence'] = 'excluded';
            $match['reason'] = 'Matched product belongs to standalone gearbox catalog and is excluded from Ovoko sync.';
            return $match;
        }

        $match['excluded_from_ovoko_sync'] = false;
        $match['exclusion_reason'] = null;
        return $match;
    }

    public function preview_update_existing_product(int $productId, NormalizedOvokoPart $part): array
    {
        $excluded = $this->is_product_excluded_from_ovoko_sync($productId);
        return ['mode' => 'preview_only', 'product_id' => $productId, 'changes' => $this->detect_changes($productId, $part), 'no_write_performed' => true, 'excluded_from_ovoko_sync' => !empty($excluded['excluded']), 'exclusion_reason' => !empty($excluded['excluded']) ? 'gearbox_standalone_catalog' : null, 'update_blocked' => !empty($excluded['excluded'])];
    }

    public function preview_match_rrr_record(array $record): array
    {
        $partId = sanitize_text_field((string) ($record['part_id'] ?? ''));
        $externalId = sanitize_text_field((string) ($record['external_id'] ?? ''));

        foreach (self::PART_MATCH_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $partId);
            if (count($ids) === 1) return $this->apply_exclusion_to_match($this->match_result($ids[0], 'part_id_meta', $metaKey, 'high', false, 'Unique exact part_id meta match', []));
            if (count($ids) > 1) return $this->apply_exclusion_to_match($this->match_result(null, 'part_id_meta', $metaKey, 'low', true, 'Multiple products with same part_id meta', $ids));
        }

        foreach (self::EXTERNAL_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $externalId);
            if (count($ids) === 1) return $this->apply_exclusion_to_match($this->match_result($ids[0], 'external_meta', $metaKey, 'medium', false, 'Matched external ID source meta', []));
            if (count($ids) > 1) return $this->apply_exclusion_to_match($this->match_result(null, 'external_meta', $metaKey, 'low', true, 'Multiple products with same external id meta', $ids));
        }

        return $this->apply_exclusion_to_match($this->match_result(null, 'none', '', 'low', true, 'No safe exact identifier match.', []));
    }

    public function preview_import_part(string $partId, OvokoSupplyConnectorClient $client): array
    {
        return ['mode' => 'preview_only', 'fetch' => $client->preview_fetch_part($partId), 'no_import_performed' => true, 'no_outbound_write' => true];
    }

    public function developer_sample_fixture(): array
    {
        return ['sample_only' => true, 'not_production' => true, 'no_outbound' => true, 'no_import' => true, 'part_id' => 'OVK-EXAMPLE-123', 'title' => 'Sample gearbox for preview', 'price' => '199.99', 'currency' => 'EUR', 'images' => ['https://example.test/img1.jpg'], 'vehicle_make' => 'Volkswagen', 'vehicle_model' => 'Golf', 'vehicle_generation' => 'VII', 'year' => '2018', 'oe_numbers' => ['02E300012A']];
    }

    private function is_gps_gmail_sku(string $sku): bool
    {
        return str_starts_with(strtoupper(trim($sku)), self::GMAIL_PRODUCT_SKU_PREFIX);
    }

    private function find_ids_by_meta(string $metaKey, string $metaValue): array
    {
        if ($metaValue === '' || !post_type_exists('product')) return [];
        return array_map('intval', (array) get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => 10, 'fields' => 'ids', 'meta_key' => $metaKey, 'meta_value' => $metaValue]));
    }

    private function match_result($id, string $by, string $metaKey, string $confidence, bool $review, string $reason, array $dupes): array
    {
        return ['matched_product_id' => $id ? (int) $id : null, 'matched_by' => $by, 'matched_meta_key' => $metaKey, 'confidence' => $confidence, 'review_required' => $review, 'reason' => $reason, 'duplicate_candidates' => $dupes];
    }
}
