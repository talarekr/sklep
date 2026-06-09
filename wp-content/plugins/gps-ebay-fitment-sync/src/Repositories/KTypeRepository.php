<?php

namespace GPSEbayFitmentSync\Repositories;

final class KTypeRepository
{
    public const META_LIST = '_gps_fitment_ktype_list';
    public const META_SOURCE = '_gps_fitment_source';
    public const META_CONFIDENCE = '_gps_fitment_confidence';
    public const META_STATUS = '_gps_fitment_status';
    public const META_LAST_CHECKED = '_gps_fitment_last_checked_at';
    public const META_LAST_ERROR = '_gps_fitment_last_error';
    public const META_COUNT = '_gps_fitment_ktype_count';
    public const META_OEM_SOURCE = '_gps_fitment_oem_source';
    public const META_OEM_VALUE = '_gps_fitment_oem_value';

    public function read_for_product(int $productId): array
    {
        $list = $this->normalize_list(get_post_meta($productId, self::META_LIST, true));
        $count = count($list);
        $storedCount = (int) get_post_meta($productId, self::META_COUNT, true);

        return [
            'ktype_list' => $list,
            'ktype_count' => $count > 0 ? $count : $storedCount,
            'source' => (string) get_post_meta($productId, self::META_SOURCE, true),
            'confidence' => (string) get_post_meta($productId, self::META_CONFIDENCE, true),
            'status' => (string) get_post_meta($productId, self::META_STATUS, true),
            'last_checked_at' => (string) get_post_meta($productId, self::META_LAST_CHECKED, true),
            'last_error' => (string) get_post_meta($productId, self::META_LAST_ERROR, true),
            'oem_source' => (string) get_post_meta($productId, self::META_OEM_SOURCE, true),
            'oem_value' => (string) get_post_meta($productId, self::META_OEM_VALUE, true),
        ];
    }

    public function normalize_list($value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            $decoded = null;
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
            }
            $value = is_array($decoded) ? $decoded : preg_split('/[\s,;|]+/', $trimmed);
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['ktype'] ?? $item['id'] ?? '';
            }
            $item = preg_replace('/\D+/', '', (string) $item);
            if ($item !== '') {
                $normalized[$item] = $item;
            }
        }

        return array_values($normalized);
    }
}
