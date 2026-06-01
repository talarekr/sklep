<?php

require_once __DIR__ . '/../src/Services/CategoryMappingSafety.php';
require_once __DIR__ . '/../src/Services/EbayDeCategoryRuleMapper.php';

use WEI\Services\EbayDeCategoryRuleMapper;

$mapper = new EbayDeCategoryRuleMapper();
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cases = [
    'ac_hose' => ['Wąż / Przewód klimatyzacji A/C', 'AUDI A4 przewód wąż klimatyzacji', '33544'],
    'ac_compressor' => ['Kompresor / Sprężarka klimatyzacji A/C', 'Klimakompressor Audi', '33543'],
    'ac_panel' => ['Panel klimatyzacji', 'Klimabedienteil Audi', '262082'],
    'interior_trim' => ['Wnętrze > Listwy dekoracyjne', 'Osłona słupka A kratka nawiewu', '33705'],
    'control_module' => ['Sterownik / Moduł', 'Moduł komfortu ECU', '33596'],
    'car_speaker' => ['Audio > Głośnik', 'Głośnik drzwi Audi', '179671'],
    'wiring_harness' => ['Wiązka elektryczna', 'Wiązka przewodów kabelbaum', '179847'],
    'spare_wheel' => ['Koło zapasowe', 'Koło dojazdowe notrad', '179679'],
    'tow_hook' => ['Hak holowniczy', 'Komplet haka holowniczego', '33653'],
    'complete_engine' => ['Silnik kompletny', 'Jednostka napędowa kompletna', '33615'],
    'power_steering_hose' => ['Przewód wspomagania', 'Servolenkungsschlauch Audi', '262225'],
    'adblue_hose' => ['Przewód AdBlue', 'AdBlue Leitung Harnstoffleitung', '33605'],
];

foreach ($cases as $intent => [$path, $title, $expectedId]) {
    $rec = $mapper->recommend(['woo_category_path' => 'Części samochodowe > ' . $path, 'woo_subcategory_name' => $path, 'product_title' => $title]);
    $assert(($rec['detected_intent'] ?? '') === $intent, "Expected intent {$intent}, got " . json_encode($rec, JSON_UNESCAPED_UNICODE));
    $assert(($rec['recommended_ebay_category_id'] ?? '') === $expectedId, "Expected {$intent} category {$expectedId}, got " . json_encode($rec, JSON_UNESCAPED_UNICODE));
    $assert(str_contains((string) ($rec['recommended_ebay_category_path'] ?? ''), 'Auto & Motorrad: Teile'), "{$intent} must stay in automotive path");
    $assert(!str_contains(strtolower((string) ($rec['recommended_ebay_category_path'] ?? '')), 'motorrad- & rollerteile'), "{$intent} must not map to motorcycle family");
}

$panel = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Panel klimatyzacji', 'product_title' => 'Klimabedienteil panel klimatyzacji']);
$assert(($panel['recommended_ebay_category_id'] ?? '') !== '33544', 'Panel klimatyzacji must not map to AC hose category 33544.');

$camera = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Kamera cofania', 'product_title' => 'Kamera cofania Audi']);
$assert(($camera['recommended_ebay_category_id'] ?? '') !== '33544', 'Kamera cofania must not map to AC hose category 33544.');

$module = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Sterownik / Moduł komfortu', 'product_title' => 'Sterownik moduł komfortu']);
$assert(!str_contains(strtolower((string) ($module['recommended_ebay_category_path'] ?? '')), 'heimwerker'), 'Sterownik / Moduł must not map to Heimwerker.');

$speaker = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Audio > Głośnik', 'product_title' => 'Głośnik drzwi']);
$assert(!str_contains(strtolower((string) ($speaker['recommended_ebay_category_path'] ?? '')), 'motorrad- & rollerteile'), 'Głośnik must not map to motorcycle.');

$spare = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Koło zapasowe', 'product_title' => 'Koło zapasowe dojazdowe']);
$assert(!str_contains(strtolower((string) ($spare['recommended_ebay_category_path'] ?? '')), 'komplettrad'), 'Koło zapasowe must not map to complete wheel set.');
$validation = $mapper->validate_recommendation($spare, null, 'EBAY_DE');
$assert(empty($validation['apply_candidate']), 'Koło zapasowe recommendation should remain manual-review/low confidence, not auto-apply.');

$ac = $mapper->recommend(['woo_category_path' => 'Części samochodowe > Wąż / Przewód klimatyzacji A/C', 'product_title' => 'Przewód klimatyzacji Audi']);
$assert(($ac['recommended_ebay_category_id'] ?? '') === '33544', 'Wąż / Przewód klimatyzacji A/C may map to 33544.');
$acValidation = $mapper->validate_recommendation($ac, null, 'EBAY_DE');
$assert(!empty($acValidation['apply_candidate']), 'High-confidence AC hose should be an apply candidate after automotive leaf validation.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'EbayDeCategoryRuleMapper tests passed' . PHP_EOL;
