<?php

namespace WEI\Services;

class CategoryMappingSafety
{
    public const DEFAULT_AUTO_CONFIDENCE_THRESHOLD = 0.90;

    public static function threshold(array $settings): float
    {
        $threshold = (float) ($settings['auto_category_confidence_threshold'] ?? self::DEFAULT_AUTO_CONFIDENCE_THRESHOLD);
        if ($threshold <= 0 || $threshold > 1) {
            return self::DEFAULT_AUTO_CONFIDENCE_THRESHOLD;
        }
        return round($threshold, 4);
    }

    public static function evaluate_auto_mapping(string $wooPath, string $ebayPath, float $confidence, array $settings): array
    {
        $threshold = self::threshold($settings);
        $sanity = self::sanity_check($wooPath, $ebayPath);
        if (!$sanity['pass']) {
            return [
                'accepted' => false,
                'status' => 'category_sanity_failed',
                'ui_status' => 'blocked_by_sanity',
                'confidence' => $confidence,
                'threshold' => $threshold,
                'sanity_check_pass' => false,
                'sanity_reason' => $sanity['reason'],
                'block_reason' => 'category mapping requires review',
            ];
        }

        if ($confidence < $threshold) {
            return [
                'accepted' => false,
                'status' => 'low_confidence_auto',
                'ui_status' => 'blocked_by_threshold',
                'confidence' => $confidence,
                'threshold' => $threshold,
                'sanity_check_pass' => true,
                'sanity_reason' => '',
                'block_reason' => 'category mapping requires review',
            ];
        }

        return [
            'accepted' => true,
            'status' => 'mapped_auto',
            'ui_status' => 'accepted_auto',
            'confidence' => $confidence,
            'threshold' => $threshold,
            'sanity_check_pass' => true,
            'sanity_reason' => '',
            'block_reason' => '',
        ];
    }

    public static function sanity_check(string $wooPath, string $ebayPath): array
    {
        $woo = self::normalize($wooPath);
        $ebay = self::normalize($ebayPath);

        if (self::contains_any($woo, ['karoserii', 'drzwi', 'maska', 'zderzak', 'blotnik', 'klapa']) && self::contains_any($ebay, ['autoelektrik'])) {
            return ['pass' => false, 'reason' => 'Woo body/door category cannot be auto-mapped to eBay Autoelektrik.'];
        }

        if (self::contains_any($woo, ['oswietlenie', 'lampy', 'reflektor']) && !self::contains_any($ebay, ['beleuchtung', 'scheinwerfer', 'ruckleuchten', 'blinker'])) {
            return ['pass' => false, 'reason' => 'Woo lighting category must auto-map to an eBay lighting category.'];
        }

        if (self::contains_any($woo, ['silnik', 'osprzet silnika']) && self::contains_any($ebay, ['innenausstattung', 'karosserie', 'beleuchtung'])) {
            return ['pass' => false, 'reason' => 'Woo engine category cannot be auto-mapped to eBay interior, body, or lighting categories.'];
        }

        if (self::contains_any($woo, ['uklad hamulcowy', 'hamulce']) && !self::contains_any($ebay, ['brems'])) {
            return ['pass' => false, 'reason' => 'Woo brake category must auto-map to an eBay brake category.'];
        }

        if (self::contains_any($woo, ['zawieszenie']) && !self::contains_any($ebay, ['fahrwerk', 'federung', 'lenkung', 'achse'])) {
            return ['pass' => false, 'reason' => 'Woo suspension category must auto-map to an eBay chassis, suspension, steering, or axle category.'];
        }

        return ['pass' => true, 'reason' => ''];
    }

    private static function normalize(string $text): string
    {
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
        $text = function_exists('remove_accents') ? remove_accents($text) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        return strtolower((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }
}
