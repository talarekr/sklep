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


    public static function is_sonstige_category(string $ebayText): bool
    {
        return self::contains_any(self::normalize($ebayText), ['sonstige']);
    }

    public static function is_specific_woo_category(string $wooPath): bool
    {
        return self::expected_path_keywords($wooPath) !== [] || self::contains_any(self::normalize($wooPath), [
            'turbiny', 'turbina', 'turbo', 'alternatory', 'alternator', 'rozruszniki', 'rozrusznik',
            'chlodnice', 'chlodnica', 'klimatyzacja', 'wydech', 'wnetrze', 'fotele', 'fotel', 'lusterka', 'lusterko', 'lusterka', 'turbolader',
        ]);
    }

    public static function expected_path_keywords(string $wooPath): array
    {
        $woo = self::normalize($wooPath);
        $rules = [
            [['drzwi', 'tuer', 'tueren', 'turen'], ['tuer', 'tueren', 'turen', 'karosserie', 'karosserieteile']],
            [['silniki kompletne', 'silnik kompletny', 'komplettmotoren', 'komplettmotor', 'complete engine'], ['motoren', 'komplettmotor', 'complete engine']],
            [['silniki', 'silnik', 'motor', 'motoren'], ['motor', 'motoren', 'motorteile', 'komplettmotor']],
            [['skrzynie biegow', 'skrzynia biegow', 'kompletne skrzynie', 'getriebe'], ['getriebe', 'schaltgetriebe', 'automatikgetriebe']],
            [['lampy', 'reflektor', 'oswietlenie', 'beleuchtung', 'scheinwerfer', 'leuchte'], ['beleuchtung', 'scheinwerfer', 'ruckleuchte', 'ruckleuchten', 'blinker', 'leuchte']],
            [['zderzak', 'zderzaki', 'stossstange', 'stosstange'], ['stossstange', 'stosstange', 'karosserie']],
            [['blotnik', 'blotniki', 'kotflugel'], ['kotflugel', 'karosserie']],
            [['maska', 'motorhaube'], ['motorhaube', 'karosserie']],
            [['klapa', 'heckklappe', 'kofferraum'], ['heckklappe', 'kofferraum', 'karosserie']],
            [['hamulce', 'uklad hamulcowy', 'bremse', 'bremsen'], ['bremse', 'bremsen', 'brems']],
            [['zawieszenie', 'fahrwerk', 'federung'], ['fahrwerk', 'federung', 'achse', 'lenkung']],
            [['turbo', 'turbina', 'turbiny'], ['turbolader', 'turbo']],
            [['alternator', 'alternatory', 'lichtmaschine', 'generator'], ['lichtmaschine', 'generator']],
            [['rozrusznik', 'rozruszniki', 'anlasser', 'starter'], ['anlasser', 'starter']],
            [['chlodnica', 'chlodnice', 'kuhler', 'kuhlung'], ['kuhler', 'kuhlung']],
            [['lusterka', 'lusterko', 'spiegel'], ['spiegel', 'aussenspiegel']],
            [['wydech', 'auspuff'], ['auspuff', 'abgasanlage']],
            [['klimatyzacja', 'klima'], ['klimaanlage', 'klima']],
            [['wnetrze', 'fotele', 'fotel', 'siedzenie'], ['innenausstattung', 'sitz', 'sitze']],
        ];

        foreach ($rules as $rule) {
            if (self::contains_any($woo, $rule[0])) {
                return $rule[1];
            }
        }

        return [];
    }

    public static function matched_expected_keywords(string $wooPath, string $ebayPath): bool
    {
        $expected = self::expected_path_keywords($wooPath);
        if ($expected === []) {
            return true;
        }

        return self::contains_any(self::normalize($ebayPath), $expected);
    }

    public static function sanity_check(string $wooPath, string $ebayPath): array
    {
        $woo = self::normalize($wooPath);
        $ebay = self::normalize($ebayPath);

        if (self::is_complete_engine_intent($wooPath) && self::contains_any($ebay, self::complete_engine_part_negative_keywords())) {
            return ['pass' => false, 'reason' => 'complete_engine_candidate_is_engine_part'];
        }

        if (self::is_sonstige_category($ebayPath) && self::is_specific_woo_category($wooPath)) {
            return ['pass' => false, 'reason' => 'auto_mapping_to_sonstige_for_specific_woo_category'];
        }

        $expected = self::expected_path_keywords($wooPath);
        if ($expected !== [] && !self::contains_any($ebay, $expected)) {
            return ['pass' => false, 'reason' => 'expected_path_keyword_missing'];
        }

        if (self::contains_any($woo, ['karoserii', 'drzwi', 'maska', 'zderzak', 'blotnik', 'klapa']) && self::contains_any($ebay, ['autoelektrik'])) {
            return ['pass' => false, 'reason' => 'expected_path_keyword_missing'];
        }

        if (self::contains_any($woo, ['silnik', 'osprzet silnika']) && self::contains_any($ebay, ['innenausstattung', 'karosserie', 'beleuchtung'])) {
            return ['pass' => false, 'reason' => 'expected_path_keyword_missing'];
        }

        return ['pass' => true, 'reason' => ''];
    }

    public static function category_intent(string $wooPath): string
    {
        return self::is_complete_engine_intent($wooPath) ? 'complete_engine' : '';
    }

    public static function is_complete_engine_intent(string $wooPath): bool
    {
        return self::contains_any(self::normalize($wooPath), ['silniki kompletne', 'silnik kompletny', 'komplettmotoren', 'komplettmotor', 'complete engine']);
    }

    public static function complete_engine_part_negative_keywords(): array
    {
        return ['ventile', 'olfilter', 'oelfilter', 'filter', 'olpumpen', 'oelpumpen', 'kurbelwellen', 'zylinderkopfe', 'zylinderkoepfe', 'kolben', 'lager', 'reparatursatze', 'reparatursaetze', 'dichtungen', 'nockenwellen', 'zahnriemen', 'steuerketten', 'einspritzdusen', 'einspritzduesen', 'pleuel', 'kleinteile'];
    }

    public static function complete_engine_preferred_keywords(): array
    {
        return ['motoren', 'komplettmotor', 'complete engine'];
    }

    private static function normalize(string $text): string
    {
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
        $text = function_exists('remove_accents') ? remove_accents($text) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = str_replace(['ß', 'ü', 'ö', 'ä'], ['ss', 'u', 'o', 'a'], (string) $text);
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
