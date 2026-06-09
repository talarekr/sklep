<?php

namespace GPS_Ebay_Fitment\Resolver;

class PartNumberResolver
{
    private $meta_keys = [
        '_part_number',
        'part_number',
        '_gps_detected_part_code',
        '_gps_normalized_part_code',
    ];

    private $taxonomy_slugs = [
        'pa_numer-czesci',
        'pa_numer_czesci',
    ];

    public function resolve($product_id)
    {
        foreach ($this->meta_keys as $key) {
            $value = $this->clean_value(get_post_meta($product_id, $key, true));
            if ($value !== '') {
                return ['part_number' => $value, 'source' => $key];
            }
        }

        foreach ($this->taxonomy_slugs as $taxonomy) {
            $value = $this->term_value($product_id, $taxonomy);
            if ($value !== '') {
                return ['part_number' => $value, 'source' => $taxonomy];
            }
        }

        $attribute_value = $this->woocommerce_named_attribute($product_id, 'Numer części');
        if ($attribute_value !== '') {
            return ['part_number' => $attribute_value, 'source' => 'attribute:Numer części'];
        }

        return ['part_number' => '', 'source' => ''];
    }

    public function normalize($part_number)
    {
        $part_number = strtoupper(trim((string) $part_number));
        return preg_replace('/[^A-Z0-9]/', '', $part_number);
    }

    private function clean_value($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        return trim(wp_strip_all_tags((string) $value));
    }

    private function term_value($product_id, $taxonomy)
    {
        if (!taxonomy_exists($taxonomy)) {
            return '';
        }
        $terms = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'names']);
        if (is_wp_error($terms) || empty($terms)) {
            return '';
        }
        return $this->clean_value($terms[0]);
    }

    private function lower($value)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    private function woocommerce_named_attribute($product_id, $label)
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product) {
            return '';
        }
        foreach ($product->get_attributes() as $attribute) {
            $name = method_exists($attribute, 'get_name') ? $attribute->get_name() : '';
            $attribute_label = function_exists('wc_attribute_label') ? wc_attribute_label($name, $product) : $name;
            if ($this->lower($attribute_label) !== $this->lower($label)) {
                continue;
            }
            if ($attribute->is_taxonomy()) {
                return $this->term_value($product_id, $name);
            }
            $options = $attribute->get_options();
            return $this->clean_value(is_array($options) ? implode(', ', $options) : $options);
        }
        return '';
    }
}
