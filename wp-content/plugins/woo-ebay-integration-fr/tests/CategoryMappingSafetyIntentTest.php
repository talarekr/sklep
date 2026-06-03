<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/CategoryMappingSafety.php';

use WEI_FR\Services\CategoryMappingSafety;

$cases = [
    ['Przewód klimatyzacji Przód Audi A3 S3 A3 Sportback 8P 1K0820741CN', 'ac_hose'],
    ['AUDI A1 82A 2.0 TFSI DNN 2023 PRZEWÓD WĄŻ KLIMATYZACJI EUROPA 2Q0816721P', 'ac_hose'],
    ['AUDI Q3 F3 83A 35 TDI 2020 PRZEWÓD RURKA WĄŻ KLIMATYZACJI EU 5QF816721J', 'ac_hose'],
    ['Koło zapasowe18 Audi A4 B9 8W0601025', 'spare_wheel'],
    ['KOŁO DOJAZDOWE VW GOLF VII 125/70R18', 'spare_wheel'],
    ['Hak holowniczy Audi A6 C7 nieodpinany 13 pin', 'tow_hook'],
    ['KRATKA ATRAPA ZDERZAKA LEWY TYŁ', 'bumper_grille'],
    ['BELKA WZMOCNIENIE ZDERZAKA PRZÓD', 'bumper_reinforcement'],
    ['KOMPLETNY SZYBERDACH AUDI Q5 FY', 'sunroof'],
    ['RELINGI DACHOWE AUDI Q7 4M', 'roof_rails'],
    ['PRZEKAŹNIK ŚWIEC ŻAROWYCH AUDI A4 B8', 'glow_plug_relay'],
    ['HYBRYDA KONWERTER BATERII AUDI Q5 80A', 'hybrid_battery_converter'],
    ['PODUSZKA SKRZYNI BIEGÓW AUDI A3 8V', 'gearbox_mount'],
    ['PODŁOKIETNIK TUNELU ŚRODKOWEGO AUDI A6 C8', 'armrest_center_console'],
    ['GŁOŚNIK DRZWI PRZEDNICH AUDI A4 B9', 'car_speaker'],
    ['FOTELE FOTEL WNĘTRZE SLINE KOMPLETNE AUDI A5', 'seats'],
    ['PANEL NAWIEWU KLIMATYZACJI AUDI Q5 FY', 'hvac_control_panel'],
    ['DMUCHAWA KLIMATYZACJI AUDI A3 8V', 'hvac_blower'],
    ['Anlasser Audi A4 8K 2.0 TDI', 'starter'],
    ['Anlasserkabelbaum für Lichtmaschine Audi A6', 'wiring_harness'],
    ['AUDI A4 B8 2.0 TFSI CDN ANLASSER-LICHTMASCHINENKABELBAUM 8K0971228 Anlasserkabelbaum für Lichtmaschine', 'wiring_harness'],
    ['starter alternator harness Audi A4', 'wiring_harness'],
    ['wiązka rozrusznik alternator Audi A4', 'wiring_harness'],
    ['AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'wiring_harness'],
    ['Antriebswelle links Audi A5', 'driveshaft'],
    ['Półoś napędowa Audi A4', 'driveshaft'],
    ['Motorsteuergerät Audi A6 4G', 'control_module'],
    ['PDC Parkkontrollmodul Audi Q5', 'control_module'],
    ['Scheibenwaschflüssigkeitsbehälter Audi A3', 'washer_tank'],
    ['Servolenkungsschlauch Audi A4', 'power_steering_hose'],
    ['AUDI A4 PRZEWÓD WSPOMAGANIA SERVOLENKUNG LEITUNG', 'power_steering_hose'],
    ['Dachhimmelleuchte Audi Q7', 'roof_light'],
    ['Pleuellager Hauptlager Audi 2.0 TDI', 'engine_bearing'],
    ['2099 VW T-ROC R-LINE 4MOTION 2024 KRATKA ATRAPA ZDERZAKA LEWY TYŁ 2GA807245G', 'bumper_grille'],
    ['2250 AUDI A4 B8 2.0 TFSI AVANT BELKA WZMOCNIENIE ZDERZAKA PRZÓD EU 8K0807113D', 'bumper_reinforcement'],
    ['2136 LISTWA DEKOR DESKI ROZDZIELCZEJ KONSOLI', 'interior_trim'],
    ['2181 AUDI Q5 SQ5 8R LIFT 3.0 TDI DEH BELKA WZMOCNIENIE ZDERZAKA TYŁ 8R0807313C', 'bumper_reinforcement'],
    ['2284 AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'wiring_harness'],
    ['2085 AUDI A4 PRZEWÓD WSPOMAGANIA SERVOLENKUNG LEITUNG', 'power_steering_hose'],
    ['2122 AUDI A6 WĄŻ WSPOMAGANIA HYDRAULIK SERVOLENKUNGSSCHLAUCH', 'power_steering_hose'],
    ['2123 VW PASSAT PRZEWÓD WSPOMAGANIA SERVOLEITUNG', 'power_steering_hose'],
    ['2279 AUDI Q5 PRZEWÓD WSPOMAGANIA SERVOLENKUNG SCHLAUCH', 'power_steering_hose'],
    ['2217 AUDI A3 DMUCHAWA WENTYLATOR NAWIEWU HVAC BLOWER', 'hvac_blower'],
    ['2218 AUDI Q3 PRZEWÓD RURKA WĄŻ KLIMATYZACJI', 'ac_hose'],
    ['2221 AUDI A1 PRZEWÓD WĄŻ KLIMATYZACJI EUROPA', 'ac_hose'],
    ['2155 AUDI KOŁO ZAPASOWE DOJAZDOWE NOTRAD', 'spare_wheel'],
    ['Panewki silnika Audi 2.0 TDI', 'engine_bearing'],
    ['2090 RAMKA DEKOR KONSOLI ŚRODKOWEJ AUDI A6', 'interior_trim'],
    ['2148 GNIAZDO USB PORT USB AUDI A4', 'usb_socket'],
    ['2151 ANTENA DACHOWA SHARK FIN AUDI Q5', 'roof_antenna'],
    ['2215 LISTWA DEKOR RAMKA NAWIEWU AUDI A3', 'interior_trim'],
];

$failures = [];
foreach ($cases as [$title, $expected]) {
    $actual = CategoryMappingSafety::detect_intent($title);
    if ($actual !== $expected) {
        $failures[] = sprintf('Expected %s for "%s", got %s', $expected, $title, $actual === '' ? '<empty>' : $actual);
    }
}

$negativeChecks = [
    ['Motoryzacja > Części samochodowe > Układ klimatyzacji > Przewody klimatyzacji Przewód klimatyzacji', 'Klimakompressoren & Kupplungen', 'ac_hose_candidate_is_ac_compressor'],
    ['Koło zapasowe18 Audi', 'Automobile > Mercedes-Benz', 'spare_wheel_candidate_is_vehicle_category'],
    ['GŁOŚNIK DRZWI PRZEDNICH', 'Fensterheber & -motoren', 'car_speaker_candidate_is_window_lifter_or_motor'],
    ['AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'Innenausstattung > Fensterheber & -motoren', 'wiring_harness_candidate_is_window_lifter_or_motor'],
    ['Servolenkungsschlauch Audi A4', 'Motoren & Motorenteile > Motorblöcke', 'power_steering_hose_candidate_is_engine_parts'],
    ['AUDI A4 PRZEWÓD WSPOMAGANIA', 'Motoren > Motorblöcke', 'power_steering_hose_candidate_is_engine_parts'],
    ['Przewód klimatyzacji Audi A3', 'Motoren & Motorenteile > Motorteile', 'ac_hose_candidate_is_engine_parts'],
    ['AUDI Q3 PRZEWÓD RURKA WĄŻ KLIMATYZACJI', 'Klimaanlage > Klimakompressoren & Kupplungen', 'ac_hose_candidate_is_ac_compressor'],
    ['PANEL NAWIEWU KLIMATYZACJI', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Hak holowniczy Audi', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Anlasser Audi A4', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Antriebswelle links Audi A5', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Motorsteuergerät Audi A6', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Scheibenwaschflüssigkeitsbehälter Audi A3', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Servolenkungsschlauch Audi A4', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Dachhimmelleuchte Audi Q7', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['Anlasserkabelbaum Audi A6', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['AUDI A4 B8 2.0 TFSI CDN ANLASSER-LICHTMASCHINENKABELBAUM 8K0971228', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', 'engine_bearing_category_mismatch'],
    ['2099 VW T-ROC R-LINE 4MOTION 2024 KRATKA ATRAPA ZDERZAKA LEWY TYŁ 2GA807245G', 'Auto & Motorrad: Teile > Abschlepphaken & Abschleppösen', 'expected_path_keyword_missing'],
    ['2250 AUDI A4 B8 2.0 TFSI AVANT BELKA WZMOCNIENIE ZDERZAKA PRZÓD EU 8K0807113D', 'Auto & Motorrad: Teile > Abschlepphaken & Abschleppösen', 'bumper_reinforcement_candidate_is_tow_hook_family'],
    ['2284 AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'Innenausstattung > Fensterheber & -motoren', 'wiring_harness_candidate_is_window_lifter_or_motor'],
    ['2217 AUDI A3 DMUCHAWA WENTYLATOR NAWIEWU HVAC BLOWER', 'Motoren & Motorenteile > Motorteile', 'hvac_blower_candidate_is_engine_parts'],
    ['2218 AUDI Q3 PRZEWÓD RURKA WĄŻ KLIMATYZACJI', 'Motoren & Motorenteile > Motorteile', 'ac_hose_candidate_is_engine_parts'],
    ['2221 AUDI A1 PRZEWÓD WĄŻ KLIMATYZACJI EUROPA', 'Motoren & Motorenteile > Motorteile', 'ac_hose_candidate_is_engine_parts'],
    ['2155 AUDI KOŁO ZAPASOWE DOJAZDOWE NOTRAD', 'Automobile > Fahrzeuge', 'spare_wheel_candidate_is_vehicle_category'],
    ['2115 AUDI HAK HOLOWNICZY UCHO HOLOWNICZE', 'Scheibenreinigung > Wischerarme', 'tow_hook_candidate_is_wrong_family'],
    ['2232 AUDI ZACZEP HOLOWNICZY', 'Motoren > Motorblöcke', 'tow_hook_candidate_is_engine_parts'],
    ['2136 LISTWA DEKOR DESKI ROZDZIELCZEJ KONSOLI', 'Auto & Motorrad: Teile > In-Car Audio > Lautsprecher', 'interior_trim_candidate_is_audio_or_motorcycle'],
    ['2148 GNIAZDO USB PORT USB AUDI A4', 'Auto & Motorrad: Teile > In-Car Audio > Lautsprecher', 'usb_socket_candidate_is_audio_speaker_family'],
    ['2151 ANTENA DACHOWA SHARK FIN AUDI Q5', 'Auto & Motorrad: Teile > In-Car Audio > Lautsprecher', 'roof_antenna_candidate_is_audio_speaker_family'],
    ['2085 AUDI A4 PRZEWÓD WSPOMAGANIA SERVOLENKUNG LEITUNG', 'Auto & Motorrad: Teile > Motorrad- & Rollerteile > Motoren & Motorteile', 'power_steering_hose_candidate_is_motorcycle_parts_family'],
];

foreach ($negativeChecks as [$source, $candidate, $expectedReason]) {
    $sanity = CategoryMappingSafety::sanity_check($source, $candidate);
    if (!empty($sanity['pass']) || (string) ($sanity['reason'] ?? '') !== $expectedReason) {
        $failures[] = sprintf('Expected sanity failure %s for "%s" => "%s", got %s', $expectedReason, $source, $candidate, json_encode($sanity));
    }
}

$positiveChecks = [
    ['FOTELE FOTEL WNĘTRZE SLINE KOMPLETNE', 'Innenausstattung > Sitze', true],
    ['PANEL NAWIEWU KLIMATYZACJI', 'Innenausstattung > Schalter, Kontrollelemente & Zündschlösser', true],
    ['Przewód klimatyzacji', 'Klimaanlage > Klimaleitung', true],
    ['Przewód klimatyzacji Audi A3', 'Klimaanlage > Kältemittelleitung & Schlauch', true],
    ['Servolenkungsschlauch Audi A4', 'Lenkung > Servolenkung > Leitungen & Schläuche', true],
    ['Pleuellager Hauptlager Audi 2.0 TDI', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', true],
    ['AUDI A4 B8 2.0 TFSI CDN ANLASSER-LICHTMASCHINENKABELBAUM 8K0971228 Anlasserkabelbaum für Lichtmaschine', 'Auto & Motorrad: Teile > Autoelektrik > Kabelbäume & Leitungssätze > Bordnetz', true],
    ['AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'Autoelektrik > Kabelbäume & Leitungssätze > Anlasser Lichtmaschine Generator', true],
    ['Scheibenwaschflüssigkeitsbehälter Audi A3', 'Scheiben- & Scheinwerferreinigung > Wischwasserbehälter, -pumpen & -düsen', true],
    ['2099 VW T-ROC R-LINE 4MOTION 2024 KRATKA ATRAPA ZDERZAKA LEWY TYŁ 2GA807245G', 'Karosserie > Stoßstange > Stoßstangengitter Blende Abdeckung', true],
    ['2250 AUDI A4 B8 2.0 TFSI AVANT BELKA WZMOCNIENIE ZDERZAKA PRZÓD EU 8K0807113D', 'Karosserie > Stoßstange > Prallträger Verstärkung', true],
    ['2181 AUDI Q5 SQ5 8R LIFT 3.0 TDI DEH BELKA WZMOCNIENIE ZDERZAKA TYŁ 8R0807313C', 'Karosserie > Stoßstange > Stoßstangenträger Aufpralldämpfer', true],
    ['2284 AUDI A4 B8 2.0 TFSI CDN WIĄZKA ROZRUSZNIKA ALTERNATORA 8K0971228', 'Autoelektrik > Kabelbäume & Leitungssätze > Anlasser Lichtmaschine Generator', true],
    ['2217 AUDI A3 DMUCHAWA WENTYLATOR NAWIEWU HVAC BLOWER', 'Heizung & Klimaanlage > Gebläse Lüfter Innenraum', true],
    ['2218 AUDI Q3 PRZEWÓD RURKA WĄŻ KLIMATYZACJI', 'Klimaanlage > Kältemittelleitung & Schlauch', true],
    ['2221 AUDI A1 PRZEWÓD WĄŻ KLIMATYZACJI EUROPA', 'Klimaanlage > Klimaleitung Schlauch', true],
    ['2115 AUDI HAK HOLOWNICZY UCHO HOLOWNICZE', 'Karosserie > Anhängerkupplung Abschlepphaken Abschleppöse Zugvorrichtung', true],
    ['2232 AUDI ZACZEP HOLOWNICZY', 'Karosserie > Anhängevorrichtung Abschleppöse', true],
    ['2136 LISTWA DEKOR DESKI ROZDZIELCZEJ KONSOLI', 'Innenausstattung > Armaturenbrett Mittelkonsole Dekorleiste Zierleiste', true],
    ['2148 GNIAZDO USB PORT USB AUDI A4', 'Innenausstattung > Multimedia USB Anschluss Buchse', true],
    ['2151 ANTENA DACHOWA SHARK FIN AUDI Q5', 'Karosserie > Antennen > Dachantenne Shark', true],
];

foreach ($positiveChecks as [$source, $candidate]) {
    $sanity = CategoryMappingSafety::sanity_check($source, $candidate);
    if (empty($sanity['pass'])) {
        $failures[] = sprintf('Expected sanity pass for "%s" => "%s", got %s', $source, $candidate, json_encode($sanity));
    }
}


$selectedCategoryChecks = [
    ['Koło zapasowe18 Audi A4 B9 8W0601025', '12345', 'Auto & Motorrad: Teile > Reifen & Felgen > Kompletträder', ['Reifenbreite', 'Reifenquerschnitt', 'Zollgröße', 'Anzahl der Kompletträder', 'Reifenspezifikation'], 'spare_wheel_mapped_to_complete_wheels'],
    ['Koło zapasowe18 Audi A4 B9 8W0601025', '99999', 'Auto & Motorrad: Teile > Reifen & Felgen > Ersatzrad Notrad Felge', [], ''],
    ['Anlasser Audi A4', '33619', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', [], 'engine_bearing_category_mismatch'],
    ['Pleuellager Hauptlager Audi 2.0 TDI', '33619', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', [], ''],
    ['Koło zapasowe18 Audi A4 B9 8W0601025', '88888', 'Automobile > Mercedes-Benz', [], 'spare_wheel_candidate_is_vehicle_category'],
];

foreach ($selectedCategoryChecks as [$source, $categoryId, $categoryText, $requiredAspects, $expectedReason]) {
    $safety = CategoryMappingSafety::selected_category_check($source, $categoryId, $categoryText, $requiredAspects);
    $actualReason = (string) ($safety['reason'] ?? '');
    if ($expectedReason === '' && empty($safety['pass'])) {
        $failures[] = sprintf('Expected selected category pass for "%s" => "%s", got %s', $source, $categoryText, json_encode($safety));
    }
    if ($expectedReason !== '' && (!empty($safety['pass']) || $actualReason !== $expectedReason)) {
        $failures[] = sprintf('Expected selected category failure %s for "%s" => "%s", got %s', $expectedReason, $source, $categoryText, json_encode($safety));
    }
}


$manualWooMappingChecks = [
    ['2099 VW T-ROC R-LINE 4MOTION 2024 KRATKA ATRAPA ZDERZAKA LEWY TYŁ 2GA807245G', '77777', 'Auto & Motorrad: Teile > Abschlepphaken & Abschleppösen', true, 'expected_path_keyword_missing'],
    ['Części samochodowe > Rozruszniki', '33619', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', true, ''],
    ['Części samochodowe > Wnętrze > Listwy dekoracyjne', '55555', 'Auto & Motorrad: Teile > Motorrad- & Rollerteile > Motoren & Motorteile', false, 'manual_mapping_motorcycle_or_quad_category_for_non_motorcycle_woo_path'],
    ['Części samochodowe > Audio > Głośniki', '44444', 'Innenausstattung > Fensterheber & -motoren', false, 'manual_mapping_window_lifter_family_for_unrelated_products'],
    ['Części samochodowe > Wnętrze > Fotele', '33333', 'Automobile > Fahrzeuge', false, 'manual_mapping_vehicle_category_for_normal_parts'],
    ['Części samochodowe > Wnętrze > Fotele', '22222', 'Motoren & Motorenteile > Motorblöcke', false, 'manual_mapping_engine_or_motor_block_for_unrelated_parts'],
    ['Części samochodowe > Podnośniki szyby', '11111', 'Innenausstattung > Fensterheber & -motoren', true, ''],
    ['Motoryzacja > Części samochodowe > Układ elektryczny, zapłon > Wyposażenie elektryczne > Silniczki szyb', '33706', 'Fensterheber & -motoren', true, ''],
    ['Motoryzacja > Części samochodowe > Układ elektryczny, zapłon > Wyposażenie elektryczne > Podnośniki szyb', '33706', 'Fensterheber & -motoren', true, ''],
];

foreach ($manualWooMappingChecks as [$source, $categoryId, $categoryText, $expectedPass, $expectedReasonOrWarning]) {
    $safety = CategoryMappingSafety::manual_woo_category_mapping_check($source, $categoryId, $categoryText);
    if ($expectedPass && empty($safety['pass'])) {
        $failures[] = sprintf('Expected manual Woo mapping pass for "%s" => "%s", got %s', $source, $categoryText, json_encode($safety));
        continue;
    }
    if (!$expectedPass && (!empty($safety['pass']) || (string) ($safety['reason'] ?? '') !== $expectedReasonOrWarning)) {
        $failures[] = sprintf('Expected manual Woo mapping failure %s for "%s" => "%s", got %s', $expectedReasonOrWarning, $source, $categoryText, json_encode($safety));
        continue;
    }
    if ($expectedPass && (string) ($safety['warning'] ?? '') !== $expectedReasonOrWarning) {
        $failures[] = sprintf('Expected manual Woo mapping warning %s for "%s" => "%s", got %s', $expectedReasonOrWarning, $source, $categoryText, json_encode($safety));
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'CategoryMappingSafety intent tests passed' . PHP_EOL;
