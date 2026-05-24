<?php

namespace GPSwiss\Ovoko\Services;

use GPSwiss\Ovoko\DTO\NormalizedOvokoPart;

class OvokoProductSyncService
{
    private const PART_MATCH_META_KEYS = ['_ovoko_part_id', 'ovoko_part_id', 'part_id'];
    private const EXTERNAL_META_KEYS = ['_allegro_source_id', 'source_part_id', 'external_part_id'];

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
            if (count($ids) === 1) return $this->match_result($ids[0], 'part_id_meta', $metaKey, 'high', false, 'Unique exact part_id meta match', []);
            if (count($ids) > 1) return $this->match_result(null, 'part_id_meta', $metaKey, 'low', true, 'Multiple products with same part_id meta', $ids);
        }
        foreach (self::EXTERNAL_META_KEYS as $metaKey) {
            $ids = $this->find_ids_by_meta($metaKey, $partId);
            if (count($ids) === 1) return $this->match_result($ids[0], 'external_meta', $metaKey, 'medium', false, 'Matched external source meta', []);
        }
        $sku = (string) ($part->to_array()['part_id'] ?? '');
        if ($sku !== '') {
            $ids = $this->find_ids_by_meta('_sku', $sku);
            if (count($ids) === 1) return $this->match_result($ids[0], 'sku', '_sku', 'medium', true, 'SKU candidate only, requires review', []);
        }
        return $this->match_result(null, 'title_candidate', 'post_title', 'low', true, 'No safe exact identifier match. Title matching should be manual review.', []);
    }

    public function preview_update_existing_product(int $productId, NormalizedOvokoPart $part): array
    {
        return ['mode' => 'preview_only', 'product_id' => $productId, 'changes' => $this->detect_changes($productId, $part), 'no_write_performed' => true];
    }

    public function preview_import_part(string $partId, OvokoSupplyConnectorClient $client): array
    {
        return ['mode' => 'preview_only', 'fetch' => $client->preview_fetch_part($partId), 'no_import_performed' => true, 'no_outbound_write' => true];
    }

    public function developer_sample_fixture(): array
    {
        return ['sample_only' => true, 'not_production' => true, 'no_outbound' => true, 'no_import' => true, 'part_id' => 'OVK-EXAMPLE-123', 'title' => 'Sample gearbox for preview', 'price' => '199.99', 'currency' => 'EUR', 'images' => ['https://example.test/img1.jpg'], 'vehicle_make' => 'Volkswagen', 'vehicle_model' => 'Golf', 'vehicle_generation' => 'VII', 'year' => '2018', 'oe_numbers' => ['02E300012A']];
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
