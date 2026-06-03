<?php

namespace WEI_FR\Services;

class EbayConditionResolver
{
    public const DEFAULT_ITEM_CONDITION = 'USED';
    public const DEFAULT_EBAY_CONDITION_ENUM = 'USED_EXCELLENT';

    private const CONDITION_ALIASES = [
        'USED' => self::DEFAULT_EBAY_CONDITION_ENUM,
        'UŻYWANY' => self::DEFAULT_EBAY_CONDITION_ENUM,
        'UZYWANY' => self::DEFAULT_EBAY_CONDITION_ENUM,
        'GEBRAUCHT' => self::DEFAULT_EBAY_CONDITION_ENUM,
    ];

    private const SUPPORTED_CONDITIONS = [
        'NEW',
        'LIKE_NEW',
        'NEW_OTHER',
        'NEW_WITH_DEFECTS',
        'MANUFACTURER_REFURBISHED',
        'CERTIFIED_REFURBISHED',
        'EXCELLENT_REFURBISHED',
        'VERY_GOOD_REFURBISHED',
        'GOOD_REFURBISHED',
        'SELLER_REFURBISHED',
        'USED_EXCELLENT',
        'USED_VERY_GOOD',
        'USED_GOOD',
        'USED_ACCEPTABLE',
        'FOR_PARTS_OR_NOT_WORKING',
        'PRE_OWNED_EXCELLENT',
        'PRE_OWNED_FAIR',
    ];

    public static function resolve(string $marketplaceId, array $settings = []): array
    {
        $configured = strtoupper(trim((string) ($settings['default_item_condition'] ?? '')));
        $requestedCondition = $configured !== '' ? $configured : self::DEFAULT_ITEM_CONDITION;
        $condition = self::CONDITION_ALIASES[$requestedCondition] ?? $requestedCondition;

        if (!in_array($condition, self::SUPPORTED_CONDITIONS, true)) {
            $requestedCondition = self::DEFAULT_ITEM_CONDITION;
            $condition = self::DEFAULT_EBAY_CONDITION_ENUM;
        }

        return [
            'condition' => $condition,
            'source' => $requestedCondition === self::DEFAULT_ITEM_CONDITION ? 'default_used_parts' : 'settings.default_item_condition',
            'configured_condition' => $requestedCondition,
            'marketplace_id' => $marketplaceId,
        ];
    }
}
