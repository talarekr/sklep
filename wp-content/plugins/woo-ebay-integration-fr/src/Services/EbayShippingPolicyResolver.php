<?php

namespace WEI_FR\Services;

class EbayShippingPolicyResolver
{
    public const GROUP_SHIPPING_30 = 'shipping_30';
    public const GROUP_SHIPPING_50 = 'shipping_50';
    public const GROUP_SHIPPING_130 = 'shipping_130';

    /** @deprecated Backward-compatible group alias. */
    public const GROUP_DEFAULT_30_EUR = self::GROUP_SHIPPING_30;
    /** @deprecated Backward-compatible group alias. */
    public const GROUP_PARCEL_50_EUR = self::GROUP_SHIPPING_50;
    /** @return array<int,string> */
    public static function priority_groups(): array
    {
        return [self::GROUP_SHIPPING_130, self::GROUP_SHIPPING_50, self::GROUP_SHIPPING_30];
    }

    /**
     * @return array{group:string,policy_id:string,policy_name:string,source:string,rate_eur:int,default_used:bool,matched_terms:array<int,array<string,mixed>>,missing_terms:array<int,array<string,mixed>>,override:string,matched_woo_category_id:int,woo_category_ids:array<int,int>,reason:string,blocked:bool}
     */
    public static function resolve_for_product(int $productId, array $settings): array
    {
        $override = self::normalize_group((string) get_post_meta($productId, '_wei_fr_ebay_shipping_group_override', true));
        if ($override === '') {
            $override = self::normalize_group((string) get_post_meta($productId, '_ebay_shipping_group_override', true));
        }

        $matchedTerms = [];
        $missingTerms = [];
        $matchedWooCategoryId = 0;
        $group = '';
        $source = 'woo_category_mapping';
        $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
        $terms = function_exists('is_wp_error') && is_wp_error($terms) ? [] : (array) $terms;
        $wooCategoryIds = [];
        $termDataById = [];

        foreach ($terms as $term) {
            if (!is_object($term)) {
                continue;
            }
            $termId = (int) ($term->term_id ?? 0);
            if ($termId <= 0) {
                continue;
            }
            $wooCategoryIds[] = $termId;
            $termDataById[$termId] = [
                'term_id' => $termId,
                'name' => (string) ($term->name ?? ''),
                'slug' => (string) ($term->slug ?? ''),
            ];
        }
        $wooCategoryIds = array_values(array_unique($wooCategoryIds));

        if ($override !== '') {
            $group = $override;
            $source = 'product_manual_override';
        } else {
            $categoryGroups = self::category_group_maps($settings);
            foreach (self::priority_groups() as $candidateGroup) {
                foreach ($wooCategoryIds as $termId) {
                    if (in_array($termId, $categoryGroups[$candidateGroup] ?? [], true)) {
                        $group = $candidateGroup;
                        $matchedWooCategoryId = $termId;
                        $matchedTerms[] = ($termDataById[$termId] ?? ['term_id' => $termId, 'name' => '', 'slug' => '']) + ['group' => $candidateGroup];
                        break 2;
                    }
                }
            }

            foreach ($wooCategoryIds as $termId) {
                if ($termId !== $matchedWooCategoryId) {
                    $missingTerms[] = $termDataById[$termId] ?? ['term_id' => $termId, 'name' => '', 'slug' => ''];
                }
            }
        }

        $defaultUsed = false;
        if ($group === '') {
            $defaultPolicyId = self::default_policy_id($settings);
            if ($defaultPolicyId !== '') {
                $group = 'default';
                $source = 'default_shipping_policy';
                $defaultUsed = true;
            }
        }

        $policyId = $group === 'default' ? self::default_policy_id($settings) : self::policy_id_for_group($group, $settings);
        $policyName = $group === 'default' ? self::default_policy_name($settings) : self::policy_name_for_group($group, $settings);
        $reason = '';
        $blocked = false;
        if ($group === '') {
            $reason = 'missing_fr_shipping_policy_mapping';
            $source = 'no_matching_woo_category';
            $blocked = true;
        } elseif ($policyId === '') {
            $reason = 'missing_fr_shipping_policy_mapping';
            $blocked = true;
        }

        return [
            'group' => $group,
            'policy_id' => $policyId,
            'policy_name' => $policyName,
            'source' => $source,
            'rate_eur' => self::rate_for_group($group),
            'default_used' => $defaultUsed,
            'matched_terms' => $matchedTerms,
            'missing_terms' => $missingTerms,
            'override' => $override,
            'matched_woo_category_id' => $matchedWooCategoryId,
            'woo_category_ids' => $wooCategoryIds,
            'reason' => $reason,
            'blocked' => $blocked,
        ];
    }

    public static function policy_id_for_group(string $group, array $settings): string
    {
        return match (self::normalize_group($group)) {
            self::GROUP_SHIPPING_130 => self::first_setting($settings, ['shipping_policy_130', 'fulfillment_policy_id_130_eur']),
            self::GROUP_SHIPPING_50 => self::first_setting($settings, ['shipping_policy_50', 'fulfillment_policy_id_50_eur']),
            self::GROUP_SHIPPING_30 => self::first_setting($settings, ['shipping_policy_30', 'fulfillment_policy_id_30_eur']),
            default => '',
        };
    }

    public static function policy_name_for_group(string $group, array $settings): string
    {
        return match (self::normalize_group($group)) {
            self::GROUP_SHIPPING_130 => self::first_setting($settings, ['shipping_policy_name_130', 'fulfillment_policy_name_130_eur']),
            self::GROUP_SHIPPING_50 => self::first_setting($settings, ['shipping_policy_name_50', 'fulfillment_policy_name_50_eur']),
            self::GROUP_SHIPPING_30 => self::first_setting($settings, ['shipping_policy_30_name', 'shipping_policy_name_30', 'fulfillment_policy_name_30_eur']),
            default => '',
        };
    }

    public static function default_policy_id(array $settings): string
    {
        return self::first_setting($settings, ['default_shipping_policy_id', 'default_shipping_policy', 'ebay_default_shipping_policy_id']);
    }

    public static function default_policy_name(array $settings): string
    {
        return self::first_setting($settings, ['default_shipping_policy_name', 'ebay_default_shipping_policy_name']);
    }

    /** @return array<string,array<int,int>> */
    public static function category_group_maps(array $settings): array
    {
        return [
            self::GROUP_SHIPPING_30 => self::parse_term_ids((string) ($settings['shipping_category_ids_30'] ?? $settings['shipping_category_ids_30_eur'] ?? '')),
            self::GROUP_SHIPPING_50 => self::parse_term_ids((string) ($settings['shipping_category_ids_50'] ?? $settings['shipping_category_ids_50_eur'] ?? '')),
            self::GROUP_SHIPPING_130 => self::parse_term_ids((string) ($settings['shipping_category_ids_130'] ?? $settings['shipping_category_ids_130_eur'] ?? '')),
        ];
    }

    /** @return array<int,string> */
    public static function conflict_ids(array $settings): array
    {
        $seen = [];
        $conflicts = [];
        foreach (self::category_group_maps($settings) as $group => $ids) {
            foreach ($ids as $id) {
                if (isset($seen[$id]) && !in_array($group, $seen[$id], true)) {
                    $seen[$id][] = $group;
                    $conflicts[$id] = implode(', ', $seen[$id]);
                    continue;
                }
                $seen[$id] = [$group];
            }
        }
        ksort($conflicts);
        return $conflicts;
    }

    public static function normalize_group(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);
        return match ($value) {
            '30', '30_eur', 'wysylka_30', 'wysyłka_30', 'shipping_30', 'default_30' => self::GROUP_SHIPPING_30,
            '50', '50_eur', 'wysylka_50', 'wysyłka_50', 'shipping_50', 'parcel_50' => self::GROUP_SHIPPING_50,
            '130', '130_eur', 'wysylka_130', 'wysyłka_130', 'shipping_130' => self::GROUP_SHIPPING_130,
            default => '',
        };
    }

    public static function rate_for_group(string $group): int
    {
        return match (self::normalize_group($group)) {
            self::GROUP_SHIPPING_130 => 130,
            self::GROUP_SHIPPING_50 => 50,
            self::GROUP_SHIPPING_30 => 30,
            default => 0,
        };
    }

    public static function normalize_id_list(string $raw): string
    {
        return implode(',', self::parse_term_ids($raw));
    }

    /** @return array<int,int> */
    public static function parse_term_ids(string $raw): array
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

    /** @param array<string,mixed> $settings @param array<int,string> $keys */
    private static function first_setting(array $settings, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($settings[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}
