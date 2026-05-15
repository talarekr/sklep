<?php

namespace WEI\Services;

class EbayShippingPolicyResolver
{
    public const GROUP_DEFAULT_30_EUR = 'default_30_eur';
    public const GROUP_PARCEL_50_EUR = 'parcel_50_eur';
    public const GROUP_PALLET_100_EUR = 'pallet_100_eur';

    public const POLICY_30_EUR = '259264150013';
    public const POLICY_50_EUR = '259677066013';
    public const POLICY_100_EUR = '259636579013';

    /**
     * @return array{group:string,policy_id:string,source:string,rate_eur:int,default_used:bool,matched_terms:array<int,array<string,mixed>>,missing_terms:array<int,array<string,mixed>>,override:string}
     */
    public static function resolve_for_product(int $productId, array $settings): array
    {
        $override = self::normalize_group((string) get_post_meta($productId, '_wei_ebay_shipping_group_override', true));
        if ($override === '') {
            $override = self::normalize_group((string) get_post_meta($productId, '_ebay_shipping_group_override', true));
        }

        $matchedTerms = [];
        $missingTerms = [];
        $group = self::GROUP_DEFAULT_30_EUR;
        $source = 'default_30_eur';

        if ($override !== '') {
            $group = $override;
            $source = 'product_manual_override';
        } else {
            $categoryGroups = self::category_group_maps($settings);
            $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
            $terms = is_wp_error($terms) ? [] : (array) $terms;
            foreach ($terms as $term) {
                if (!is_object($term)) {
                    continue;
                }
                $termId = (int) ($term->term_id ?? 0);
                if ($termId <= 0) {
                    continue;
                }
                $termData = [
                    'term_id' => $termId,
                    'name' => (string) ($term->name ?? ''),
                    'slug' => (string) ($term->slug ?? ''),
                ];
                if (in_array($termId, $categoryGroups[self::GROUP_PALLET_100_EUR], true)) {
                    $matchedTerms[] = $termData + ['group' => self::GROUP_PALLET_100_EUR];
                    $group = self::GROUP_PALLET_100_EUR;
                    $source = 'woo_category_mapping';
                    continue;
                }
                if (in_array($termId, $categoryGroups[self::GROUP_PARCEL_50_EUR], true)) {
                    $matchedTerms[] = $termData + ['group' => self::GROUP_PARCEL_50_EUR];
                    if ($group !== self::GROUP_PALLET_100_EUR) {
                        $group = self::GROUP_PARCEL_50_EUR;
                        $source = 'woo_category_mapping';
                    }
                    continue;
                }
                $missingTerms[] = $termData;
            }
        }

        $defaultUsed = $group === self::GROUP_DEFAULT_30_EUR && $source === 'default_30_eur';
        return [
            'group' => $group,
            'policy_id' => self::policy_id_for_group($group, $settings),
            'source' => $source,
            'rate_eur' => self::rate_for_group($group),
            'default_used' => $defaultUsed,
            'matched_terms' => $matchedTerms,
            'missing_terms' => $missingTerms,
            'override' => $override,
        ];
    }

    public static function policy_id_for_group(string $group, array $settings): string
    {
        $fallback = trim((string) ($settings['ebay_fulfillment_policy_id'] ?? ''));
        $policy30 = trim((string) ($settings['fulfillment_policy_id_30_eur'] ?? ''));
        if ($policy30 === '') {
            $policy30 = $fallback !== '' ? $fallback : self::POLICY_30_EUR;
        }

        return match (self::normalize_group($group) ?: self::GROUP_DEFAULT_30_EUR) {
            self::GROUP_PALLET_100_EUR => trim((string) ($settings['fulfillment_policy_id_100_eur'] ?? '')) ?: self::POLICY_100_EUR,
            self::GROUP_PARCEL_50_EUR => trim((string) ($settings['fulfillment_policy_id_50_eur'] ?? '')) ?: self::POLICY_50_EUR,
            default => $policy30,
        };
    }

    /** @return array<string,array<int,int>> */
    public static function category_group_maps(array $settings): array
    {
        return [
            self::GROUP_PARCEL_50_EUR => self::parse_term_ids((string) ($settings['shipping_category_ids_50_eur'] ?? '')),
            self::GROUP_PALLET_100_EUR => self::parse_term_ids((string) ($settings['shipping_category_ids_100_eur'] ?? '')),
        ];
    }

    public static function normalize_group(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);
        return match ($value) {
            '30', '30_eur', 'default', 'default_30', self::GROUP_DEFAULT_30_EUR => self::GROUP_DEFAULT_30_EUR,
            '50', '50_eur', 'parcel', 'parcel_50', self::GROUP_PARCEL_50_EUR => self::GROUP_PARCEL_50_EUR,
            '100', '100_eur', 'pallet', 'pallet_100', self::GROUP_PALLET_100_EUR => self::GROUP_PALLET_100_EUR,
            default => '',
        };
    }

    public static function rate_for_group(string $group): int
    {
        return match (self::normalize_group($group) ?: self::GROUP_DEFAULT_30_EUR) {
            self::GROUP_PALLET_100_EUR => 100,
            self::GROUP_PARCEL_50_EUR => 50,
            default => 30,
        };
    }

    /** @return array<int,int> */
    private static function parse_term_ids(string $raw): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $ids[] = (int) $part;
        }
        return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    }
}
