<?php

namespace WEI\Services;

class EbayDeCategoryRuleMapper
{
    public const HIGH_CONFIDENCE_THRESHOLD = 0.92;

    /** @var array<string,array<string,mixed>> */
    private const CATEGORY_CATALOG = [
        '33544' => ['name' => 'Klimaleitungen, -schläuche & Anschlüsse', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klima- & Heizungsanlagen > Klimaleitungen, -schläuche & Anschlüsse', 'leaf' => true],
        '33543' => ['name' => 'Klimakompressoren & Kupplungen', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klima- & Heizungsanlagen > Klimakompressoren & Kupplungen', 'leaf' => true],
        '262082' => ['name' => 'Klimaregelungsteile', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klima- & Heizungsanlagen > Klimaregelungsteile', 'leaf' => true],
        '61864' => ['name' => 'Kondensatoren', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Klima- & Heizungsanlagen > Kondensatoren', 'leaf' => true],
        '33615' => ['name' => 'Motoren', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Motoren & Motorenteile > Motoren', 'leaf' => true],
        '33613' => ['name' => 'Motorblöcke', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Motoren & Motorenteile > Motorblöcke', 'leaf' => true],
        '33617' => ['name' => 'Zylinderköpfe', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Motoren & Motorenteile > Zylinderköpfe', 'leaf' => true],
        '38657' => ['name' => 'Ölwannen', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Motoren & Motorenteile > Ölwannen', 'leaf' => true],
        '33573' => ['name' => 'Lichtmaschinen', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Lichtmaschinen, Anlasser, Komponenten > Lichtmaschinen', 'leaf' => true],
        '33576' => ['name' => 'Anlasser', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Lichtmaschinen, Anlasser, Komponenten > Anlasser', 'leaf' => true],
        '33742' => ['name' => 'Turbolader & Teile', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Turbolader, Kompressoren & Ladeluftkühler > Turbolader & Teile', 'leaf' => true],
        '33705' => ['name' => 'Innenabdeckungen, Zierleisten & Dekors', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Innenausstattung > Innenabdeckungen, Zierleisten & Dekors', 'leaf' => true],
        '33596' => ['name' => 'ECUs & Steuergeräte', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Autoelektrik > ECUs & Steuergeräte', 'leaf' => true],
        '179671' => ['name' => 'Lautsprecher', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Autoelektronik, GPS & Sicherheitstechnik > Car Audio > Lautsprecher', 'leaf' => true],
        '179847' => ['name' => 'Kabelbäume', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Autoelektrik > Kabelbäume', 'leaf' => true],
        '262225' => ['name' => 'Servolenkungsschläuche', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Federung & Lenkung > Servolenkungsschläuche', 'leaf' => true],
        '33653' => ['name' => 'Anhängerkupplungen & Komplettsätze', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Anhängerkupplungen & Abschleppteile > Anhängerkupplungen & Komplettsätze', 'leaf' => true],
        '179679' => ['name' => 'Felgen', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Autoreifen & Felgen > Felgen', 'leaf' => true],
        '33701' => ['name' => 'Fahrzeugsitze, Teile & Zubehör', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Innenausstattung > Fahrzeugsitze, Teile & Zubehör', 'leaf' => true],
        '262172' => ['name' => 'Panorama-, Schiebedächer & Teile', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Karosserie-, Anbauteile & Zubehör > Auto-, Cabrio-, Panorama- & Schiebedächer > Panorama-, Schiebedächer & Teile', 'leaf' => true],
        '33640' => ['name' => 'Stoßstangen & -verstärker', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Karosserie-, Anbauteile & Zubehör > Stoßstangen & Teile > Stoßstangen & -verstärker', 'leaf' => true],
        '33605' => ['name' => 'Abgasreinigung', 'path' => 'Auto & Motorrad: Teile > Autoteile & Zubehör > Auspuff- & Abgassysteme > Abgasreinigung', 'leaf' => true],
    ];

    /** @param array<string,mixed> $context */
    public function recommend(array $context): array
    {
        $wooName = $this->normalize((string) ($context['woo_subcategory_name'] ?? ''));
        $wooPath = $this->normalize((string) ($context['woo_category_path'] ?? ''));
        $productTitle = $this->normalize((string) ($context['product_title'] ?? ''));
        $translatedTitle = $this->normalize((string) ($context['translated_title'] ?? ''));
        $sampleProductData = $this->normalize((string) ($context['sample_product_data'] ?? ''));
        $titleSource = trim(implode(' ', array_filter([$productTitle, $translatedTitle])));
        $source = trim(implode(' ', array_filter([$wooName, $wooPath, $titleSource, $sampleProductData])));

        if ($this->is_uncategorized($wooName, $wooPath)) {
            return $this->empty_result('uncategorized', 'uncategorized_always_needs_manual_review');
        }

        $rule = $this->match_rule($source, $wooName, $wooPath, $titleSource);
        if ($rule === []) {
            return $this->empty_result('unknown_intent', 'no_supported_family_rule_matched');
        }

        if ((string) ($rule['category_id'] ?? '') === '') {
            return $this->empty_result((string) $rule['intent'], (string) ($rule['reason'] ?? 'manual_review_required'));
        }

        $category = self::CATEGORY_CATALOG[(string) $rule['category_id']] ?? null;
        if (!is_array($category)) {
            return $this->empty_result((string) $rule['intent'], 'rule_category_missing_from_catalog');
        }

        $sanity = CategoryMappingSafety::selected_category_check($source, (string) $rule['category_id'], (string) $category['path']);
        return [
            'recommended_ebay_category_id' => (string) $rule['category_id'],
            'recommended_ebay_category_name' => (string) $category['name'],
            'recommended_ebay_category_path' => (string) $category['path'],
            'confidence' => (float) $rule['confidence'],
            'decision_reason' => (string) $rule['reason'],
            'detected_intent' => (string) $rule['intent'],
            'sanity_status' => !empty($sanity['pass']) ? 'pass' : 'failed',
            'sanity_reason' => (string) ($sanity['reason'] ?? ''),
        ];
    }

    public function validate_recommendation(array $recommendation, ?EbayTaxonomyService $taxonomy = null, string $marketplaceId = 'EBAY_DE'): array
    {
        $categoryId = (string) ($recommendation['recommended_ebay_category_id'] ?? '');
        if ($categoryId === '') {
            return ['status' => 'missing_recommendation', 'valid' => false, 'leaf' => false, 'automotive' => false, 'apply_candidate' => false];
        }

        $validation = [];
        if ($taxonomy !== null) {
            $validation = $taxonomy->validate_category_result($marketplaceId, $categoryId);
        }
        if ($validation === [] || (string) ($validation['validation_status'] ?? '') === 'invalid_ebay_category_id') {
            $validation = $this->static_validation($categoryId, (string) ($validation['taxonomy_error'] ?? 'taxonomy_not_available'));
        }

        $path = (string) ($validation['category_path'] ?? $recommendation['recommended_ebay_category_path'] ?? '');
        $automotive = $this->is_automotive_path($path);
        $valid = !empty($validation['valid']) && !empty($validation['leaf']) && $automotive;
        $status = $valid ? 'valid_leaf_automotive' : (string) ($validation['validation_status'] ?? 'invalid_or_non_automotive');
        if (!empty($validation['valid']) && !empty($validation['leaf']) && !$automotive) {
            $status = 'non_automotive_category_path';
        }

        return $validation + [
            'status' => $status,
            'valid' => $valid,
            'leaf' => !empty($validation['leaf']),
            'automotive' => $automotive,
            'apply_candidate' => $valid && (float) ($recommendation['confidence'] ?? 0) >= self::HIGH_CONFIDENCE_THRESHOLD && (string) ($recommendation['sanity_status'] ?? '') === 'pass',
        ];
    }

    public static function supported_intents(): array
    {
        return ['ac_hose','ac_compressor','ac_panel','ac_condenser','ac_other','cooling_hose','interior_trim','interior_mirror','interior_light','headliner','control_module','car_speaker','wiring_harness','spare_wheel','tow_hook','complete_engine','engine_block','cylinder_head','oil_pan','alternator','starter','turbocharger','power_steering_hose','adblue_hose','gearbox_cover','seats','sunroof','bumper_reinforcement','uncategorized'];
    }

    private function match_rule(string $source, string $wooName = '', string $wooPath = '', string $titleSource = ''): array
    {
        if ($this->contains_any($source, ['kamera cofania', 'ruckfahrkamera', 'rueckfahrkamera', 'rear camera', 'backup camera'])) {
            return [];
        }
        if ($this->is_cooling_hose_intent($wooName . ' ' . $wooPath)) {
            return $this->empty_rule('cooling_hose', 'cooling_hose_needs_manual_review_no_confirmed_ebay_de_leaf');
        }
        if ($this->is_roof_interior_non_sunroof_intent($wooName)) {
            return $this->roof_interior_rule($wooName);
        }
        if ($this->is_ac_other_intent($wooName, $wooPath)) {
            $specificAcRule = $this->match_specific_ac_rule($titleSource);
            if ($specificAcRule !== []) {
                return $specificAcRule;
            }
            return $this->empty_rule('ac_other', 'ac_other_generic_needs_manual_review_without_specific_sample_title');
        }
        if ($this->is_tow_hook_intent($source)) {
            return $this->rule('tow_hook', '33653', 0.96, 'tow_hook_priority_rule_not_wiring_harness');
        }
        if ($this->is_cooling_hose_intent($source)) {
            return $this->empty_rule('cooling_hose', 'cooling_hose_needs_manual_review_no_confirmed_ebay_de_leaf');
        }
        if ($this->is_roof_interior_non_sunroof_intent($source)) {
            return $this->roof_interior_rule($source);
        }
        if ($this->contains_any($source, ['kompresor klimatyzacji', 'sprezarka klimatyzacji', 'sprężarka klimatyzacji', 'klimakompressor', 'ac compressor'])) {
            return $this->rule('ac_compressor', '33543', 0.97, 'ac_compressor_positive_rule_not_ac_hose');
        }
        if ($this->contains_any($source, ['panel klimatyzacji', 'panel nawiewu', 'sterownik klimatyzacji', 'klimabedienteil', 'klimasteuerung', 'heizungsbedienteil', 'bedienfeld klima'])) {
            return $this->rule('ac_panel', '262082', 0.95, 'ac_panel_positive_rule_not_ac_hose');
        }
        if ($this->contains_any($source, ['skraplacz klimatyzacji', 'chlodnica klimatyzacji', 'chłodnica klimatyzacji', 'klimakondensator', 'kondensator klimaanlage'])) {
            return $this->rule('ac_condenser', '61864', 0.95, 'ac_condenser_positive_rule_not_ac_hose');
        }
        if ($this->contains_any($source, ['zawor rozprezny klimatyzacji', 'zawór rozprężny klimatyzacji', 'ausdehnungsventil'])) {
            return $this->rule('ac_expansion_valve', '262082', 0.88, 'ac_expansion_valve_review_rule');
        }
        if ($this->contains_any($source, ['przewod klimatyzacji', 'przewód klimatyzacji', 'waz klimatyzacji', 'wąż klimatyzacji', 'rurka klimatyzacji', 'klimaleitung', 'klimaschlauch', 'kaltemittelleitung', 'kaeltemittelleitung'])) {
            return $this->rule('ac_hose', '33544', 0.98, 'ac_hose_positive_rule');
        }

        if ($this->contains_any($source, ['silnik kompletny', 'silniki kompletne', 'silnik / komplet', 'jednostka napedowa', 'jednostka napędowa', 'komplettmotor', 'complete engine'])) {
            return $this->rule('complete_engine', '33615', 0.96, 'complete_engine_positive_rule');
        }
        if ($this->contains_any($source, ['slupek silnika', 'słupek silnika', 'motorblock', 'engine block'])) {
            return $this->rule('engine_block', '33613', 0.93, 'engine_block_positive_rule_not_complete_engine');
        }
        if ($this->contains_any($source, ['glowica silnika', 'głowica silnika', 'zylinderkopf'])) {
            return $this->rule('cylinder_head', '33617', 0.95, 'cylinder_head_positive_rule');
        }
        if ($this->contains_any($source, ['miska olejowa', 'olwanne', 'oelwanne'])) {
            return $this->rule('oil_pan', '38657', 0.95, 'oil_pan_positive_rule');
        }
        if ($this->contains_any($source, ['alternator', 'lichtmaschine', 'generator'])) {
            return $this->rule('alternator', '33573', 0.95, 'alternator_positive_rule');
        }
        if ($this->contains_any($source, ['rozrusznik', 'anlasser', 'starter'])) {
            return $this->rule('starter', '33576', 0.95, 'starter_positive_rule');
        }
        if ($this->contains_any($source, ['turbosprezarka', 'turbosprężarka', 'turbolader', 'turbocharger'])) {
            return $this->rule('turbocharger', '33742', 0.95, 'turbocharger_positive_rule');
        }

        if ($this->contains_any($source, ['glosnik', 'głośnik', 'glosniki', 'głośniki', 'lautsprecher', 'speaker'])) {
            return $this->rule('car_speaker', '179671', 0.95, 'car_speaker_positive_rule_not_motorcycle');
        }
        if ($this->contains_any($source, ['sterownik', 'modul', 'moduł', 'steuergerat', 'steuergeraet', 'komfortsteuergerat', 'ecu'])) {
            return $this->rule('control_module', '33596', 0.94, 'control_module_positive_rule_automotive_ecu');
        }
        if ($this->contains_any($source, ['wiazka', 'wiązka', 'kabelbaum', 'leitungssatz', 'wiring harness'])) {
            return $this->rule('wiring_harness', '179847', 0.95, 'wiring_harness_positive_rule');
        }
        if ($this->contains_any($source, ['kolo zapasowe', 'koło zapasowe', 'dojazdowe', 'ersatzrad', 'notrad', 'reserverad'])) {
            return $this->rule('spare_wheel', '179679', 0.76, 'spare_wheel_review_rule_avoids_complete_wheel_set');
        }
        if ($this->contains_any($source, ['hak holowniczy', 'komplet haka', 'hak', 'ucho holownicze', 'zaczep holowniczy', 'anhangerkupplung', 'anhaengerkupplung', 'abschlepphaken', 'abschleppose', 'abschleppoese', 'tow hook', 'towbar', 'tow bar'])) {
            return $this->rule('tow_hook', '33653', 0.94, 'tow_hook_positive_rule');
        }
        if ($this->contains_any($source, ['przewod wspomagania', 'przewód wspomagania', 'waz wspomagania', 'wąż wspomagania', 'servolenkungsschlauch', 'servoleitung', 'power steering hose'])) {
            return $this->rule('power_steering_hose', '262225', 0.95, 'power_steering_hose_positive_rule_not_motorcycle_not_engine');
        }
        if ($this->contains_any($source, ['przewod adblue', 'przewód adblue', 'waz adblue', 'wąż adblue', 'adblue leitung', 'adblue schlauch', 'harnstoffleitung'])) {
            return $this->rule('adblue_hose', '33605', 0.90, 'adblue_hose_review_rule_exhaust_treatment_not_engine');
        }
        if ($this->contains_any($source, ['oslona skrzyni', 'osłona skrzyni', 'pokrywa skrzyni', 'getriebeabdeckung', 'gearbox cover'])) {
            return $this->rule('gearbox_cover', '33613', 0.70, 'gearbox_cover_review_rule_needs_manual_category_confirmation');
        }
        if ($this->contains_any($source, ['fotel', 'fotele', 'kanapa', 'siedzenie', 'sitz', 'sitze'])) {
            return $this->rule('seats', '33701', 0.95, 'seats_positive_rule');
        }
        if ($this->contains_any($source, ['szyberdach', 'dach panoramiczny', 'schiebedach', 'panoramadach', 'sunroof'])) {
            return $this->rule('sunroof', '262172', 0.95, 'sunroof_positive_rule');
        }
        if ($this->contains_any($source, ['belka zderzaka', 'wzmocnienie zderzaka', 'pralltrager', 'pralltraeger', 'stossstangentrager', 'stossstangentraeger', 'stoßstangenträger', 'bumper reinforcement'])) {
            return $this->rule('bumper_reinforcement', '33640', 0.90, 'bumper_reinforcement_rule_not_window_lifter_not_turbo');
        }
        if ($this->contains_any($source, ['listwa', 'oslona slupka', 'osłona słupka', 'slupek a', 'słupek a', 'slupek b', 'słupek b', 'slupek c', 'słupek c', 'tapicerka', 'kratka nawiewu', 'plastik wnetrza', 'plastik wnętrza', 'dekor', 'zierleiste', 'verkleidung', 'blende', 'innenabdeckung'])) {
            return $this->rule('interior_trim', '33705', 0.94, 'interior_trim_positive_rule_not_switch_audio_motorcycle');
        }

        return [];
    }

    private function match_specific_ac_rule(string $source): array
    {
        if ($source === '') {
            return [];
        }
        if ($this->contains_any($source, ['kompresor', 'sprezarka', 'sprężarka', 'klimakompressor', 'ac compressor'])) {
            return $this->rule('ac_compressor', '33543', 0.96, 'ac_other_sample_title_ac_compressor');
        }
        if ($this->contains_any($source, ['panel', 'sterownik klimatyzacji', 'klimabedienteil', 'klimasteuerung', 'bedienfeld klima'])) {
            return $this->rule('ac_panel', '262082', 0.95, 'ac_other_sample_title_ac_panel');
        }
        if ($this->contains_any($source, ['skraplacz', 'kondensator', 'chlodnica klimatyzacji', 'chłodnica klimatyzacji', 'klimakondensator', 'kondensator klimaanlage'])) {
            return $this->rule('ac_condenser', '61864', 0.95, 'ac_other_sample_title_ac_condenser');
        }
        if ($this->contains_any($source, ['przewod klimatyzacji', 'przewód klimatyzacji', 'waz klimatyzacji', 'wąż klimatyzacji', 'rurka klimatyzacji', 'klimaleitung', 'klimaschlauch', 'kaltemittelleitung', 'kaeltemittelleitung'])) {
            return $this->rule('ac_hose', '33544', 0.96, 'ac_other_sample_title_ac_hose');
        }
        return [];
    }

    private function is_uncategorized(string $wooName, string $wooPath): bool
    {
        $value = trim($wooName !== '' ? $wooName : $wooPath);
        return $value === 'bez kategorii' || str_ends_with(trim($wooPath), 'bez kategorii');
    }

    private function is_ac_other_intent(string $wooName, string $wooPath): bool
    {
        $source = trim($wooName . ' ' . $wooPath);
        return $this->contains_any($source, ['inne elementy ukladu klimatyzacji', 'inne elementy układu klimatyzacji', 'pozostale elementy klimatyzacji', 'pozostałe elementy klimatyzacji']);
    }

    private function is_tow_hook_intent(string $source): bool
    {
        return $this->contains_any($source, ['hak holowniczy', 'komplet haka', 'ucho holownicze', 'zaczep holowniczy', 'anhangerkupplung', 'anhaengerkupplung', 'tow hook', 'towbar', 'tow bar'])
            || (bool) preg_match('/(^|[^a-z0-9])hak([^a-z0-9]|$)/', $source);
    }

    private function is_cooling_hose_intent(string $source): bool
    {
        return $this->contains_any($source, ['przewod / waz chlodnicy', 'przewód / wąż chłodnicy', 'waz chlodnicy', 'wąż chłodnicy', 'przewod chlodnicy', 'przewód chłodnicy', 'przewod chlodzenia', 'przewód chłodzenia', 'kühlerschlauch', 'kuhlerschlauch', 'kühlmittelschlauch', 'kuhlmittelschlauch', 'coolant hose', 'radiator hose']);
    }

    private function is_roof_interior_non_sunroof_intent(string $source): bool
    {
        return $this->contains_any($source, ['lusterko wsteczne', 'innenruckspiegel', 'innenrueckspiegel', 'rear view mirror', 'panel oswietlenia wnetrza', 'panel oświetlenia wnętrza', 'lampka wnetrza', 'lampka wnętrza', 'oswietlenie podsufitki', 'oświetlenie podsufitki', 'uchwyt sufitowy', 'podsufitka', 'tapicerka dachu', 'dachhimmel', 'headliner']);
    }

    private function roof_interior_rule(string $source): array
    {
        if ($this->contains_any($source, ['lusterko wsteczne', 'innenruckspiegel', 'innenrueckspiegel', 'rear view mirror'])) {
            return $this->empty_rule('interior_mirror', 'interior_mirror_needs_manual_review_no_confirmed_ebay_de_leaf');
        }
        if ($this->contains_any($source, ['panel oswietlenia wnetrza', 'panel oświetlenia wnętrza', 'lampka wnetrza', 'lampka wnętrza', 'oswietlenie podsufitki', 'oświetlenie podsufitki', 'uchwyt sufitowy'])) {
            return $this->empty_rule('interior_light', 'interior_light_needs_manual_review_no_confirmed_ebay_de_leaf');
        }
        return $this->rule('headliner', '33705', 0.89, 'headliner_review_rule_not_sunroof');
    }

    private function empty_rule(string $intent, string $reason): array
    {
        return ['intent' => $intent, 'category_id' => '', 'confidence' => 0.0, 'reason' => $reason];
    }

    private function rule(string $intent, string $categoryId, float $confidence, string $reason): array
    {
        return ['intent' => $intent, 'category_id' => $categoryId, 'confidence' => $confidence, 'reason' => $reason];
    }

    private function static_validation(string $categoryId, string $note = ''): array
    {
        $category = self::CATEGORY_CATALOG[$categoryId] ?? null;
        if (!is_array($category)) {
            return ['category_id' => $categoryId, 'valid' => false, 'leaf' => false, 'category_path' => '', 'validation_status' => 'invalid_ebay_category_id', 'taxonomy_error' => $note];
        }
        return ['category_id' => $categoryId, 'valid' => true, 'leaf' => !empty($category['leaf']), 'category_name' => (string) $category['name'], 'category_path' => (string) $category['path'], 'validation_status' => 'static_known_leaf_taxonomy_fallback', 'taxonomy_error' => $note];
    }

    private function empty_result(string $intent, string $reason): array
    {
        return ['recommended_ebay_category_id' => '', 'recommended_ebay_category_name' => '', 'recommended_ebay_category_path' => '', 'confidence' => 0.0, 'decision_reason' => $reason, 'detected_intent' => $intent, 'sanity_status' => 'not_applicable', 'sanity_reason' => ''];
    }

    private function is_automotive_path(string $path): bool
    {
        $normalized = $this->normalize($path);
        return str_contains($normalized, 'auto & motorrad: teile') && str_contains($normalized, 'autoteile');
    }

    private function normalize(string $text): string
    {
        $text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
        $text = function_exists('remove_accents') ? remove_accents($text) : (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = str_replace(['ß', 'ü', 'ö', 'ä'], ['ss', 'u', 'o', 'a'], (string) $text);
        return strtolower((string) preg_replace('/\s+/', ' ', $text));
    }

    private function contains_any(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $this->normalize((string) $needle))) {
                return true;
            }
        }
        return false;
    }
}
