<?php

namespace GPSEbayFitmentSync\Services;

final class OemPartNumberResolver
{
    /**
     * TecDoc lookup input must be resolved only from the Woo/TecDoc part-number field.
     * Diagnostic identifiers (SKU, Ovoko IDs, EAN/GTIN, title words, make/model, MPN, etc.)
     * intentionally live outside this resolver's part_number_candidates output.
     */
    private $metaKeys = [
        '_part_number' => 'high',
        'part_number' => 'high',
        '_gps_normalized_part_code' => 'medium',
        '_gps_detected_part_code' => 'medium',
    ];

    private $attributeSlugs = [
        'pa_numer-czesci',
        'pa_numer_czesci',
        'numer-czesci',
        'numer_czesci',
    ];

    public function resolve(int $productId): array
    {
        $candidates = [];

        foreach ($this->metaKeys as $key => $confidence) {
            $value = $this->normalize_candidate(get_post_meta($productId, $key, true));
            if ($value !== '') {
                $candidates[] = ['value' => $value, 'source' => 'meta:' . $key, 'confidence' => $confidence];
            }
        }

        foreach ($this->part_number_attribute_taxonomies() as $taxonomy) {
            $value = $this->taxonomy_attribute_value($productId, $taxonomy);
            if ($value !== '') {
                $candidates[] = ['value' => $value, 'source' => 'attribute:' . $taxonomy, 'confidence' => 'high'];
            }
        }

        foreach ($this->custom_product_attribute_values($productId) as $attribute) {
            $value = $this->normalize_candidate($attribute['value'] ?? '');
            if ($value !== '') {
                $candidates[] = ['value' => $value, 'source' => 'attribute:' . ($attribute['name'] ?? 'Numer części'), 'confidence' => 'high'];
            }
        }

        $unique = $this->unique_candidates($candidates);
        $primary = $unique[0] ?? null;

        return [
            'found' => $primary !== null,
            'value' => $primary['value'] ?? '',
            'source' => $primary['source'] ?? '',
            'confidence' => $primary['confidence'] ?? '',
            'candidates' => $unique,
            'primary_part_number' => $primary['value'] ?? '',
            'primary_part_number_source' => $primary['source'] ?? '',
            'primary_part_number_confidence' => $primary['confidence'] ?? '',
            'part_number_candidates' => $unique,
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

    /** @return string[] */
    private function part_number_attribute_taxonomies(): array
    {
        $taxonomies = [];
        foreach ($this->attributeSlugs as $slug) {
            $taxonomy = strpos($slug, 'pa_') === 0 ? $slug : 'pa_' . $slug;
            if (taxonomy_exists($taxonomy)) {
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }

        foreach (get_taxonomies([], 'objects') as $taxonomy => $object) {
            if (strpos((string) $taxonomy, 'pa_') !== 0) {
                continue;
            }
            $label = isset($object->labels->singular_name) ? (string) $object->labels->singular_name : (string) ($object->label ?? '');
            if ($this->is_part_number_attribute_name($taxonomy) || $this->is_part_number_attribute_name($label)) {
                $taxonomies[$taxonomy] = (string) $taxonomy;
            }
        }

        return array_values($taxonomies);
    }

    private function taxonomy_attribute_value(int $productId, string $taxonomy): string
    {
        $terms = wp_get_post_terms($productId, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }
        return $this->normalize_candidate((string) reset($terms));
    }

    /** @return array<int,array{name:string,value:string}> */
    private function custom_product_attribute_values(int $productId): array
    {
        $attributes = get_post_meta($productId, '_product_attributes', true);
        if (!is_array($attributes)) {
            return [];
        }

        $values = [];
        foreach ($attributes as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }
            $name = (string) ($attribute['name'] ?? '');
            if ($name === '' || !$this->is_part_number_attribute_name($name)) {
                continue;
            }
            if (!empty($attribute['is_taxonomy'])) {
                $taxonomyValue = $this->taxonomy_attribute_value($productId, $name);
                if ($taxonomyValue !== '') {
                    $values[] = ['name' => $name, 'value' => $taxonomyValue];
                }
                continue;
            }
            $values[] = ['name' => $name, 'value' => (string) ($attribute['value'] ?? '')];
        }

        return $values;
    }

    private function is_part_number_attribute_name(string $name): bool
    {
        $normalized = $this->normalize_attribute_name($name);
        return in_array($normalized, ['numer_czesci', 'pa_numer_czesci'], true);
    }

    private function normalize_attribute_name(string $name): string
    {
        $name = strtolower(trim(wp_strip_all_tags($name)));
        $name = strtr($name, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ]);
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        return trim((string) $name, '_');
    }

    private function unique_candidates(array $candidates): array
    {
        $seen = [];
        $unique = [];
        foreach ($candidates as $candidate) {
            $key = strtoupper((string) ($candidate['value'] ?? ''));
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $candidate;
        }
        return $unique;
    }
}
