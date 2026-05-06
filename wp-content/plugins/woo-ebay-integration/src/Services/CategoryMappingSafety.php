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
            'chlodnice', 'chlodnica', 'klimatyzacja', 'klima', 'wydech', 'wnetrze', 'fotele', 'fotel', 'kanapa', 'siedzenie', 'glosnik', 'audio', 'panel nawiewu', 'dmuchawa', 'hak holowniczy', 'kolo zapasowe', 'lusterka', 'lusterko', 'lusterka', 'turbolader',
        ]);
    }

    public static function expected_path_keywords(string $wooPath): array
    {
        $intent = self::category_intent($wooPath);
        if ($intent !== '') {
            return self::expected_keywords_for_intent($intent);
        }

        $woo = self::normalize($wooPath);
        $rules = [
            [['drzwi', 'tuer', 'tueren', 'turen'], ['tuer', 'tueren', 'turen', 'karosserie', 'karosserieteile']],
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
            [['przewody klimatyzacji', 'przewod klimatyzacji', 'waz klimatyzacji', 'klimaleitung'], self::expected_keywords_for_intent('ac_hose')],
            [['klimatyzacja', 'klima'], ['klimaanlage', 'klima']],
            [['glosniki', 'glosnik', 'audio'], self::expected_keywords_for_intent('car_speaker')],
            [['fotele', 'fotel', 'kanapa', 'siedzenie', 'sitze'], self::expected_keywords_for_intent('seats')],
            [['panel nawiewu', 'panel klimatyzacji', 'sterownik klimatyzacji'], self::expected_keywords_for_intent('hvac_control_panel')],
            [['dmuchawa', 'wentylator nawiewu'], self::expected_keywords_for_intent('hvac_blower')],
            [['wnetrze'], ['innenausstattung', 'sitz', 'sitze']],
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
        $intent = self::category_intent($wooPath);

        if ($intent === 'spare_wheel' && self::is_complete_wheels_category_text($ebay)) {
            return ['pass' => false, 'reason' => 'spare_wheel_mapped_to_complete_wheels'];
        }

        if (self::is_engine_bearing_category_text($ebay) && !self::is_engine_bearing_intent($wooPath)) {
            return ['pass' => false, 'reason' => 'engine_bearing_category_mismatch'];
        }

        if ($intent === 'seats' && self::contains_any($ebay, ['sitze', 'fahrzeugsitze', 'sitz', 'innenausstattung'])) {
            return ['pass' => true, 'reason' => ''];
        }

        if (self::is_complete_engine_intent($wooPath) && self::contains_any($ebay, self::complete_engine_part_negative_keywords())) {
            return ['pass' => false, 'reason' => 'complete_engine_candidate_is_engine_part'];
        }

        $negativeReason = self::strong_negative_reason($intent, $ebay);
        if ($negativeReason !== '') {
            return ['pass' => false, 'reason' => $negativeReason];
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

    public static function detect_intent(string $sourceText): string
    {
        return self::category_intent($sourceText);
    }

    public static function normalized_intent_source_text(string $sourceText, int $limit = 200): string
    {
        return mb_substr(self::normalize($sourceText), 0, $limit);
    }

    public static function category_intent(string $wooPath): string
    {
        $woo = self::normalize($wooPath);
        $rules = [
            'spare_wheel' => ['kolo zapasowe', 'zapasowe', 'kolo dojazdowe', 'dojazdowe', 'ersatzrad', 'notrad', 'reserverad', 'spare wheel', 'emergency wheel', 'wheel spare'],
            'engine_bearing' => ['motorlager', 'pleuellager', 'hauptlager', 'engine bearing', 'crank bearing', 'connecting rod bearing', 'panewki', 'panewka', 'lozyska silnika', 'lozysko silnika'],
            'wiring_harness' => ['kabelbaum', 'kabelbaume', 'kabelbaeume', 'wiazka przewodow', 'wiazki przewodow', 'wiazka elektryczna', 'wiring harness'],
            'starter' => ['rozrusznik', 'rozruszniki', 'anlasser', 'starter'],
            'driveshaft' => ['antriebswelle', 'antriebswellen', 'gelenkwelle', 'gelenkwellen', 'polos', 'polos napedowa', 'półoś', 'driveshaft', 'drive shaft'],
            'control_module' => ['steuergerat', 'steuergeraet', 'steuergerate', 'steuergeraete', 'steuermodul', 'kontrollmodul', 'control module', 'module'],
            'washer_tank' => ['scheibenwaschflussigkeitsbehalter', 'scheibenwaschfluessigkeitsbehaelter', 'scheibenwaschbehalter', 'scheibenwaschbehaelter', 'waschwasserbehalter', 'waschwasserbehaelter', 'zbiornik spryskiwaczy', 'washer tank'],
            'power_steering_hose' => ['servolenkungsschlauch', 'servolenkung leitung', 'servolenkung schlauch', 'przewod wspomagania', 'waz wspomagania', 'power steering hose'],
            'roof_light' => ['dachhimmelleuchte', 'dachhimmel leuchte', 'lampka podsufitki', 'oswietlenie podsufitki', 'innenleuchte', 'roof light'],
            'tow_hook' => ['hak holowniczy', 'holowniczy', 'nieodpinany 13 pin', '13 pin', 'abschlepphaken', 'anhangerkupplung', 'anhaengerkupplung', 'abschleppose', 'abschleppoese', 'tow hook', 'towbar', 'tow bar'],
            'sunroof' => ['szyberdach', 'dach panoramiczny', 'schiebedach', 'panoramadach', 'sunroof'],
            'gearbox_cover' => ['oslona dolna skrzyni', 'oslona skrzyni', 'unterfahrschutz', 'abdeckung', 'getriebeabdeckung', 'undertray', 'gearbox cover'],
            'adblue_hose' => ['przewod adblue', 'waz adblue', 'adblue leitung', 'adblue schlauch', 'harnstoffleitung'],
            'roof_rails' => ['relingi dachowe', 'reling dachowy', 'dachreling', 'reling', 'roof rails'],
            'hvac_control_panel' => ['panel nawiewu', 'panel klimatyzacji', 'sterownik klimatyzacji', 'klimabedienteil', 'bedienfeld', 'klimasteuerung', 'heizungsbedienteil', 'hvac control panel'],
            'hvac_blower' => ['dmuchawa', 'wentylator nawiewu', 'geblase', 'geblaese', 'innenraumgeblase', 'innenraumgeblaese', 'heizungsgeblase', 'heizungsgeblaese', 'blower motor'],
            'ac_hose' => ['przewody klimatyzacji', 'przewod klimatyzacji', 'rurka klimatyzacji', 'waz klimatyzacji', 'przewod waz klimatyzacji', 'klima leitung', 'klimaleitung', 'klimaschlauch', 'kaltemittelleitung', 'kaeltemittelleitung', 'ac hose'],
            'glow_plug_relay' => ['przekaznik swiec zarowych', 'swiece zarowe', 'gluhkerzenrelais', 'gluehkerzenrelais', 'vorgluhrelais', 'vorgluehrelais', 'glow plug relay'],
            'hybrid_battery_converter' => ['konwerter baterii', 'bateria hybryda', 'hybryda', 'spannungswandler', 'dc dc wandler', 'dc-dc wandler', 'batterie konverter', 'hybrid battery converter'],
            'gearbox_mount' => ['poduszka skrzyni biegow', 'mocowanie skrzyni', 'getriebelager', 'getriebehalter', 'getriebeaufhangung', 'getriebeaufhaengung', 'gearbox mount'],
            'armrest_center_console' => ['podlokietnik', 'tunel srodkowy', 'armlehne', 'mittelarmlehne', 'mittelkonsole', 'center armrest'],
            'car_speaker' => ['glosnik', 'glosniki', 'speaker', 'lautsprecher', 'turlautsprecher', 'tuerlautsprecher', 'audio'],
            'seats' => ['fotele', 'fotel', 'kanapa', 'siedzenie', 'sitz', 'sitze', 'seats'],
            'complete_engine' => ['silniki kompletne', 'silnik kompletny', 'komplettmotoren', 'komplettmotor', 'complete engine'],
        ];

        foreach ($rules as $intent => $keywords) {
            if (self::contains_any($woo, $keywords)) {
                return $intent;
            }
        }

        return '';
    }

    public static function expected_keywords_for_intent(string $intent): array
    {
        return match ($intent) {
            'spare_wheel' => ['ersatzrad', 'notrad', 'reserverad', 'felge', 'felgen'],
            'engine_bearing' => ['motorlager', 'pleuellager', 'hauptlager', 'lager', 'lagerung', 'kurbelwellenlager'],
            'starter' => ['anlasser', 'starter'],
            'driveshaft' => ['antriebswelle', 'antriebswellen', 'gelenkwelle', 'gelenkwellen'],
            'control_module' => ['steuergerat', 'steuergeraet', 'steuergerate', 'steuergeraete', 'modul', 'module'],
            'washer_tank' => ['waschwasserbehalter', 'waschwasserbehaelter', 'scheibenwaschanlage', 'scheibenwaschbehalter', 'scheibenwaschbehaelter'],
            'power_steering_hose' => ['servolenkung', 'leitung', 'schlauch'],
            'roof_light' => ['innenbeleuchtung', 'innenleuchte', 'leuchte', 'dachhimmel'],
            'wiring_harness' => ['kabel', 'kabelbaum', 'kabelbaume', 'kabelbaeume', 'steckverbinder'],
            'tow_hook' => ['anhangerkupplung', 'anhaengerkupplung', 'abschlepphaken', 'abschleppose', 'abschleppoese', 'zugvorrichtung'],
            'sunroof' => ['schiebedach', 'panoramadach', 'dach', 'glasdach'],
            'gearbox_cover' => ['unterfahrschutz', 'abdeckung', 'getriebe', 'motorraum', 'spritzschutz'],
            'adblue_hose' => ['adblue', 'harnstoff', 'leitung', 'schlauch', 'abgasreinigung'],
            'roof_rails' => ['dachreling', 'reling', 'dachtrager', 'dachtraeger', 'trager', 'traeger'],
            'ac_hose' => ['klimaleitung', 'kaltemittelleitung', 'kaeltemittelleitung', 'leitung', 'schlauch', 'klimaschlauch', 'klimaanlage'],
            'glow_plug_relay' => ['gluhkerze', 'gluehkerze', 'vorgluhen', 'vorgluehen', 'relais', 'steuergerat', 'steuergeraet'],
            'hybrid_battery_converter' => ['hybrid', 'batterie', 'spannungswandler', 'wandler', 'dc-dc', 'hochvolt'],
            'gearbox_mount' => ['getriebelager', 'motorlager', 'lagerung', 'halter', 'aufhangung', 'aufhaengung'],
            'armrest_center_console' => ['armlehne', 'mittelarmlehne', 'mittelkonsole', 'innenausstattung'],
            'car_speaker' => ['lautsprecher', 'soundsystem', 'audio', 'autoradio', 'hi-fi', 'hifi'],
            'seats' => ['sitze', 'fahrzeugsitze', 'sitz', 'innenausstattung'],
            'hvac_control_panel' => ['klimabedienteil', 'klimaanlage', 'heizung', 'bedienelement', 'bedienfeld', 'schalter', 'kontrollelemente', 'innenausstattung'],
            'hvac_blower' => ['geblase', 'geblaese', 'lufter', 'luefter', 'heizung', 'klimaanlage', 'innenraum'],
            'complete_engine' => self::complete_engine_preferred_keywords(),
            default => [],
        };
    }

    private static function strong_negative_reason(string $intent, string $ebay): string
    {
        if ($intent === 'roof_rails' && self::contains_any($ebay, ['schiebedach', 'panoramadach', 'glasdach']) && !self::contains_any($ebay, ['reling', 'trager', 'traeger'])) {
            return 'roof_rails_candidate_is_sunroof_or_roof';
        }
        if ($intent === 'sunroof' && self::contains_any($ebay, ['reling', 'dachtrager', 'dachtraeger'])) {
            return 'sunroof_candidate_is_roof_rails';
        }
        if (in_array($intent, ['gearbox_mount', 'gearbox_cover'], true) && self::contains_any($ebay, ['schaltgetriebe', 'automatikgetriebe', 'komplettgetriebe']) && !self::contains_any($ebay, ['lager', 'halter', 'aufhangung', 'aufhaengung', 'abdeckung', 'unterfahrschutz', 'spritzschutz'])) {
            return $intent . '_candidate_is_complete_gearbox';
        }
        if ($intent === 'spare_wheel') {
            if (self::contains_any($ebay, ['automobile', 'fahrzeuge', 'pkw', 'mercedes-benz']) && !self::contains_any($ebay, ['ersatzrad', 'notrad', 'reserverad', 'felge', 'felgen', 'rader', 'raeder', 'reifen'])) {
                return 'spare_wheel_candidate_is_vehicle_category';
            }
        }
        if ($intent === 'ac_hose' && self::contains_any($ebay, ['kompressor', 'kompressoren', 'klimakompressor', 'klimakompressoren', 'kupplungen']) && !self::contains_any($ebay, ['leitung', 'schlauch'])) {
            return 'ac_hose_candidate_is_ac_compressor';
        }
        if ($intent === 'car_speaker' && self::contains_any($ebay, ['fensterheber', 'motoren']) && !self::contains_any($ebay, ['lautsprecher', 'audio', 'soundsystem', 'autoradio', 'hi-fi', 'hifi'])) {
            return 'car_speaker_candidate_is_window_lifter_or_motor';
        }
        if ($intent === 'hvac_control_panel' && self::contains_any($ebay, ['motoren', 'motorteile', 'pleuel', 'hauptlager']) && !self::contains_any($ebay, ['klimaanlage', 'heizung', 'bedienelement', 'bedienfeld', 'schalter', 'kontrollelemente'])) {
            return 'hvac_control_panel_candidate_is_engine_parts';
        }
        if ($intent === 'hvac_blower' && self::contains_any($ebay, ['motoren', 'motorteile', 'pleuel', 'hauptlager']) && !self::contains_any($ebay, ['geblase', 'geblaese', 'lufter', 'luefter', 'heizung', 'klimaanlage', 'innenraum'])) {
            return 'hvac_blower_candidate_is_engine_parts';
        }
        if ($intent === 'tow_hook' && self::contains_any($ebay, ['motoren', 'motorteile', 'pleuel', 'hauptlager']) && !self::contains_any($ebay, ['anhangerkupplung', 'anhaengerkupplung', 'abschlepphaken', 'abschleppose', 'abschleppoese', 'zugvorrichtung'])) {
            return 'tow_hook_candidate_is_engine_parts';
        }
        return '';
    }

    public static function selected_category_check(string $sourceText, string $categoryId, string $categoryText, array $requiredAspects = []): array
    {
        $normalizedCategory = self::normalize($categoryText);
        $intent = self::category_intent($sourceText);

        if (trim($categoryId) === '33619' && !self::is_engine_bearing_intent($sourceText)) {
            return ['pass' => false, 'reason' => 'engine_bearing_category_mismatch'];
        }

        if (self::is_engine_bearing_category_text($normalizedCategory) && !self::is_engine_bearing_intent($sourceText)) {
            return ['pass' => false, 'reason' => 'engine_bearing_category_mismatch'];
        }

        if ($intent === 'spare_wheel') {
            if (self::is_complete_wheels_category_text($normalizedCategory) || self::requires_complete_wheel_aspects($requiredAspects)) {
                return ['pass' => false, 'reason' => 'spare_wheel_mapped_to_complete_wheels'];
            }
        }

        return ['pass' => true, 'reason' => ''];
    }

    public static function is_engine_bearing_intent(string $sourceText): bool
    {
        return self::category_intent($sourceText) === 'engine_bearing';
    }

    private static function is_engine_bearing_category_text(string $normalizedCategory): bool
    {
        return self::contains_any($normalizedCategory, ['motor-, pleuel- & hauptlager', 'motor-, pleuel- und hauptlager', 'pleuel- & hauptlager', 'pleuel und hauptlager'])
            || (self::contains_any($normalizedCategory, ['pleuellager', 'hauptlager']) && self::contains_any($normalizedCategory, ['motor', 'motoren', 'motorteile']));
    }

    private static function is_complete_wheels_category_text(string $normalizedCategory): bool
    {
        return self::contains_any($normalizedCategory, ['komplettrader', 'komplettraeder', 'komplettrad', 'komplettraeder', 'komplette rader', 'komplette raeder']);
    }

    private static function requires_complete_wheel_aspects(array $requiredAspects): bool
    {
        $required = array_map(static fn($name): string => self::normalize((string) $name), $requiredAspects);
        $requiredText = implode(' | ', $required);
        $wheelAspectHits = 0;
        foreach (['reifenbreite', 'reifenquerschnitt', 'zollgrosse', 'zollgroesse', 'anzahl der komplettrader', 'anzahl der komplettraeder', 'reifenspezifikation'] as $aspect) {
            if (str_contains($requiredText, $aspect)) {
                $wheelAspectHits++;
            }
        }

        return $wheelAspectHits >= 3;
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
