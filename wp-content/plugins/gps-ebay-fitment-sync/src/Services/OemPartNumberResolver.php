<?php

namespace GPSEbayFitmentSync\Services;

final class OemPartNumberResolver
{
    private $metaKeys = [
        '_gps_normalized_oem_part_number', '_gps_detected_oem_part_number', '_gps_normalized_part_code', '_gps_detected_part_code',
        '_mpn', 'mpn', '_manufacturer_code', 'manufacturer_code', '_oem_number', 'oem_number', '_oem', 'oem',
        '_part_number', 'part_number', '_part_code', 'part_code', '_catalog_number', 'catalog_number', '_sku', '_ean', 'ean', '_gtin', 'gtin',
        '_ovoko_part_id', 'ovoko_part_id',
    ];

    private $attributeKeys = [
        'pa_oem', 'pa_nr-oem', 'pa_nr_oem', 'pa_mpn', 'pa_numer-czesci', 'pa_numer_czesci',
        'pa_kod-producenta', 'pa_kod_producenta', 'pa_producent', 'pa_marka', 'pa_model',
    ];

    public function resolve(int $productId): array
    {
        $candidates = [];

        foreach ($this->metaKeys as $key) {
            $value = $this->normalize_candidate(get_post_meta($productId, $key, true));
            if ($value !== '') {
                $candidates[] = ['value' => $value, 'source' => 'meta:' . $key, 'confidence' => $this->confidence_for_meta_key($key)];
            }
        }

        foreach ($this->attributeKeys as $taxonomy) {
            $value = $this->attribute_value($productId, $taxonomy);
            if ($value !== '') {
                $candidates[] = ['value' => $value, 'source' => 'attribute:' . $taxonomy, 'confidence' => $this->confidence_for_attribute($taxonomy)];
            }
        }

        $titleCandidate = $this->title_candidate($productId);
        if ($titleCandidate !== '') {
            $candidates[] = ['value' => $titleCandidate, 'source' => 'candidate_from_title', 'confidence' => 'low'];
        }

        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = strtoupper((string) $candidate['value']);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $candidate;
            }
        }

        $primary = null;
        foreach ($unique as $candidate) {
            if (($candidate['confidence'] ?? '') !== 'low' || ($candidate['source'] ?? '') !== 'candidate_from_title') {
                $primary = $candidate;
                break;
            }
        }

        return [
            'found' => $primary !== null,
            'value' => $primary['value'] ?? '',
            'source' => $primary['source'] ?? '',
            'confidence' => $primary['confidence'] ?? 'low',
            'candidates' => $unique,
            'candidate_from_title' => $titleCandidate,
        ];
    }

    private function normalize_candidate($value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter(array_map('strval', $value)));
        }
        $value = trim(wp_strip_all_tags((string) $value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', $value);
        if (strlen($value) < 3 || strlen($value) > 64) {
            return '';
        }
        return $value;
    }

    private function attribute_value(int $productId, string $taxonomy): string
    {
        if (!taxonomy_exists($taxonomy)) {
            return '';
        }
        $terms = wp_get_post_terms($productId, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }
        return $this->normalize_candidate((string) reset($terms));
    }

    private function title_candidate(int $productId): string
    {
        $title = get_the_title($productId);
        if (!is_string($title) || trim($title) === '') {
            return '';
        }
        if (preg_match('/\b([A-Z0-9][A-Z0-9\-\.\/]{4,24})\b/i', $title, $matches)) {
            return $this->normalize_candidate($matches[1]);
        }
        return '';
    }

    private function confidence_for_meta_key(string $key): string
    {
        if (strpos($key, 'ean') !== false || strpos($key, 'gtin') !== false || strpos($key, 'ovoko') !== false || $key === '_sku') {
            return 'medium';
        }
        return 'high';
    }

    private function confidence_for_attribute(string $taxonomy): string
    {
        return in_array($taxonomy, ['pa_producent', 'pa_marka', 'pa_model'], true) ? 'low' : 'medium';
    }
}
