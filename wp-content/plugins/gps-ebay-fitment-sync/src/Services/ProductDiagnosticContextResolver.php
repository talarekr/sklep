<?php

namespace GPSEbayFitmentSync\Services;

final class ProductDiagnosticContextResolver
{
    private $makeAttributeNames = ['pa_marka', 'pa_producent', 'marka', 'producent'];
    private $modelAttributeNames = ['pa_model', 'model'];

    public function resolve(int $productId, array $partNumberResolution = []): array
    {
        $title = (string) get_the_title($productId);
        $ignored = $this->ignored_identifiers($productId);
        $partNumbers = $this->candidate_values($partNumberResolution['part_number_candidates'] ?? $partNumberResolution['candidates'] ?? []);
        $contextWords = $this->detected_context_words($title, $partNumbers, $ignored);
        $makeValues = $this->attribute_values($productId, $this->makeAttributeNames);
        $modelValues = $this->attribute_values($productId, $this->modelAttributeNames);

        return [
            'title' => $title,
            'detected_make_from_title' => $this->first_make_from_title($title, $makeValues),
            'detected_model_from_title' => $this->first_model_from_title($contextWords, $modelValues),
            'detected_context_words' => $contextWords,
            'product_attributes_make' => $makeValues,
            'product_attributes_model' => $modelValues,
            'ignored_identifiers' => $ignored,
        ];
    }

    private function ignored_identifiers(int $productId): array
    {
        $map = [
            'ovoko_part_id' => ['_ovoko_part_id', 'ovoko_part_id'],
            'sku' => ['_sku'],
            'ean' => ['_ean', 'ean'],
            'gtin' => ['_gtin', 'gtin'],
            'mpn' => ['_mpn', 'mpn', '_manufacturer_code', 'manufacturer_code'],
        ];

        $ignored = [];
        foreach ($map as $label => $keys) {
            foreach ($keys as $key) {
                $value = $this->normalize_identifier(get_post_meta($productId, $key, true));
                if ($value !== '') {
                    $ignored[$label] = $value;
                    break;
                }
            }
        }
        return $ignored;
    }

    private function normalize_identifier($value): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_filter(array_map('strval', $value)));
        }
        $value = trim(wp_strip_all_tags((string) $value));
        return preg_replace('/\s+/', ' ', $value);
    }

    private function detected_context_words(string $title, array $partNumbers, array $ignoredIdentifiers): array
    {
        preg_match_all('/\b[\p{L}0-9][\p{L}0-9\-]{2,29}\b/u', $title, $matches);
        $blocked = array_map('strtoupper', array_filter(array_merge($partNumbers, array_values($ignoredIdentifiers))));
        $words = [];
        foreach ($matches[0] ?? [] as $word) {
            $clean = trim((string) $word, " \t\n\r\0\x0B-_");
            if ($clean === '' || preg_match('/^\d+$/', $clean)) {
                continue;
            }
            $upper = strtoupper($clean);
            if (in_array($upper, $blocked, true)) {
                continue;
            }
            if (!isset($words[$upper])) {
                $words[$upper] = $upper;
            }
        }
        return array_values($words);
    }

    private function first_make_from_title(string $title, array $attributeMakes): string
    {
        foreach ($attributeMakes as $make) {
            if ($make !== '' && stripos($title, $make) !== false) {
                return $make;
            }
        }
        $knownMakes = ['Volkswagen', 'VW', 'Audi', 'Skoda', 'Škoda', 'Seat', 'BMW', 'Mercedes', 'Opel', 'Ford', 'Toyota', 'Renault', 'Peugeot', 'Citroen', 'Citroën', 'Fiat', 'Nissan', 'Volvo'];
        foreach ($knownMakes as $make) {
            if (preg_match('/\b' . preg_quote($make, '/') . '\b/i', $title)) {
                return $make;
            }
        }
        return '';
    }

    private function first_model_from_title(array $contextWords, array $attributeModels): string
    {
        foreach ($attributeModels as $model) {
            if (in_array(strtoupper($model), $contextWords, true)) {
                return $model;
            }
        }
        return $contextWords[0] ?? '';
    }

    private function attribute_values(int $productId, array $names): array
    {
        $values = [];
        foreach ($names as $name) {
            $taxonomy = strpos($name, 'pa_') === 0 ? $name : 'pa_' . $name;
            if (taxonomy_exists($taxonomy)) {
                $terms = wp_get_post_terms($productId, $taxonomy, ['fields' => 'names']);
                if (!is_wp_error($terms)) {
                    foreach ((array) $terms as $term) {
                        $value = $this->normalize_identifier($term);
                        if ($value !== '') {
                            $values[$value] = $value;
                        }
                    }
                }
            }
        }

        $attributes = get_post_meta($productId, '_product_attributes', true);
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $name = (string) ($attribute['name'] ?? '');
                if (!$this->matches_any_attribute_name($name, $names)) {
                    continue;
                }
                if (!empty($attribute['is_taxonomy'])) {
                    continue;
                }
                $value = $this->normalize_identifier($attribute['value'] ?? '');
                if ($value !== '') {
                    $values[$value] = $value;
                }
            }
        }

        return array_values($values);
    }

    private function matches_any_attribute_name(string $name, array $accepted): bool
    {
        $normalized = $this->normalize_attribute_name($name);
        foreach ($accepted as $candidate) {
            if ($normalized === $this->normalize_attribute_name($candidate)) {
                return true;
            }
        }
        return false;
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

    private function candidate_values(array $candidates): array
    {
        $values = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['value'])) {
                $values[] = (string) $candidate['value'];
            }
        }
        return $values;
    }
}
