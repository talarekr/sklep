<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/CategoryMappingSafety.php';

use WEI\Services\CategoryMappingSafety;

$cases = [
    ['Przewód klimatyzacji Przód Audi A3 S3 A3 Sportback 8P 1K0820741CN', 'ac_hose'],
    ['AUDI A1 82A 2.0 TFSI DNN 2023 PRZEWÓD WĄŻ KLIMATYZACJI EUROPA 2Q0816721P', 'ac_hose'],
    ['AUDI Q3 F3 83A 35 TDI 2020 PRZEWÓD RURKA WĄŻ KLIMATYZACJI EU 5QF816721J', 'ac_hose'],
    ['Koło zapasowe18 Audi A4 B9 8W0601025', 'spare_wheel'],
    ['KOŁO DOJAZDOWE VW GOLF VII 125/70R18', 'spare_wheel'],
    ['Hak holowniczy Audi A6 C7 nieodpinany 13 pin', 'tow_hook'],
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
    ['Antriebswelle links Audi A5', 'driveshaft'],
    ['Półoś napędowa Audi A4', 'driveshaft'],
    ['Motorsteuergerät Audi A6 4G', 'control_module'],
    ['PDC Parkkontrollmodul Audi Q5', 'control_module'],
    ['Scheibenwaschflüssigkeitsbehälter Audi A3', 'washer_tank'],
    ['Servolenkungsschlauch Audi A4', 'power_steering_hose'],
    ['Dachhimmelleuchte Audi Q7', 'roof_light'],
    ['Pleuellager Hauptlager Audi 2.0 TDI', 'engine_bearing'],
    ['Panewki silnika Audi 2.0 TDI', 'engine_bearing'],
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
    ['Pleuellager Hauptlager Audi 2.0 TDI', 'Motoren & Motorenteile > Motor-, Pleuel- & Hauptlager', true],
    ['AUDI A4 B8 2.0 TFSI CDN ANLASSER-LICHTMASCHINENKABELBAUM 8K0971228 Anlasserkabelbaum für Lichtmaschine', 'Auto & Motorrad: Teile > Autoelektrik > Kabelbäume & Leitungssätze > Bordnetz', true],
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

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'CategoryMappingSafety intent tests passed' . PHP_EOL;
