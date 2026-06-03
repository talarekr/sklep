<?php

declare(strict_types=1);

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('get_post_meta')) {
    $GLOBALS['wei_fr_test_meta'] = [];
    function get_post_meta($post_id, $key = '', $single = false) {
        return $GLOBALS['wei_fr_test_meta'][$post_id][$key] ?? ($single ? '' : []);
    }
    function update_post_meta($post_id, $key, $value) { $GLOBALS['wei_fr_test_meta'][$post_id][$key] = $value; return true; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) { return (string) $text; }
}

require_once __DIR__ . '/../src/Interfaces/TranslationProviderInterface.php';
require_once __DIR__ . '/../src/Services/EbayFrenchContentTranslator.php';

use WEI_FR\Interfaces\TranslationProviderInterface;
use WEI_FR\Services\EbayFrenchContentTranslator;

class WeiFakeGoogleProvider implements TranslationProviderInterface
{
    public int $calls = 0;
    public array $texts = [];
    public array $targets = [];
    public array $formats = [];

    public function is_configured(): bool { return true; }
    public function provider_key(): string { return 'google_cloud_translate'; }
    public function translate_product_content(\WC_Product $product, array $context): array { return []; }
    public function translate_texts(array $texts, string $source = 'pl', string $target = 'fr', string $format = 'text'): array
    {
        $this->calls++;
        $map = [
            'RAMKA STELAŻ KOSZ RADIA 2 DIN PRZEŁĄCZNIKI' => 'CADRE SUPPORT PANIER RADIO 2 DIN INTERRUPTEURS',
            'Test title' => 'Titre de test',
            'Kolor' => 'Couleur',
            'Rodzaj paliwa' => 'Type de carburant',
            'Szary' => 'Gris',
            'Benzyna' => 'Essence',
            'Typ skrzyni biegów' => 'Type de boîte de vitesses',
            'Koła napędowe' => 'Transmission',
            'Automatyczny' => 'Automatique',
        ];
        $this->targets[] = $target;
        $this->formats[] = $format;
        return array_map(function ($text) use ($map) {
            $this->texts[] = (string) $text;
            return $map[(string) $text] ?? ('FR ' . $text);
        }, $texts);
    }
}

$failures = [];
$translator = new EbayFrenchContentTranslator();
$source = [
    'product_id' => 10907,
    'title' => 'Test title',
    'description' => 'RAMKA STELAŻ KOSZ RADIA 2 DIN PRZEŁĄCZNIKI',
    'fields' => [
        ['polish_label' => 'Kolor', 'french_label' => 'Couleur', 'value' => 'Szary'],
        ['polish_label' => 'Rodzaj paliwa', 'french_label' => 'Type de carburant', 'value' => 'Benzyna'],
        ['polish_label' => 'Typ skrzyni biegów', 'french_label' => 'Type de boîte de vitesses', 'value' => 'Automatyczny'],
        ['polish_label' => 'Numer części', 'french_label' => 'Numéro de pièce', 'value' => '80B816720'],
        ['polish_label' => 'Kod silnika', 'french_label' => 'Code moteur', 'value' => 'DPU'],
        ['polish_label' => 'Kod koloru', 'french_label' => 'Code couleur', 'value' => 'LZ7S'],
        ['polish_label' => 'Model', 'french_label' => 'Modèle', 'value' => 'Q5 SQ5 FY'],
        ['polish_label' => 'Fahrzeugreferenz', 'french_label' => 'Référence véhicule', 'value' => 'GPSWCarID:456'],
    ],
    'aspects_source' => [
        'Kolor' => ['Szary'],
        'Rodzaj paliwa' => ['Benzyna'],
        'Typ skrzyni biegów' => ['Automatyczny'],
        'Numer części' => ['80B816720'],
        'Kod silnika' => ['DPU'],
        'Kod koloru' => ['LZ7S'],
        'Model' => ['Q5 SQ5 FY'],
        'Fahrzeugreferenz' => ['GPSWCarID:456'],
    ],
];
$provider = new WeiFakeGoogleProvider();
$payload = $translator->refresh(10907, $source, $provider);

if (($payload['target_language'] ?? '') !== 'fr' || ($payload['translated_raw_html'] ?? true) !== false || ($payload['html_css_protected'] ?? false) !== true) {
    $failures[] = 'Expected payload metadata to force French target and mark raw HTML/CSS as protected.';
}
if (array_unique($provider->targets) !== ['fr']) {
    $failures[] = 'Expected every Google provider call to use target language fr.';
}
if (in_array('html', $provider->formats, true)) {
    $failures[] = 'Expected raw HTML/CSS never to be sent to Google Translate as html format.';
}
if (($payload['description'] ?? '') !== 'CADRE SUPPORT PANIER RADIO 2 DIN INTERRUPTEURS') {
    $failures[] = 'Expected translated description to come from current Woo source description, not old Allegro cache.';
}
if (($payload['fields'][0]['value'] ?? '') !== 'Gris' || ($payload['fields'][1]['value'] ?? '') !== 'Essence' || ($payload['fields'][2]['value'] ?? '') !== 'Automatique') {
    $failures[] = 'Expected HTML specification values to be translated to French.';
}

if (($payload['fields'][3]['value'] ?? '') !== '80B816720' || ($payload['fields'][4]['value'] ?? '') !== 'DPU' || ($payload['fields'][5]['value'] ?? '') !== 'LZ7S' || ($payload['fields'][6]['value'] ?? '') !== 'Q5 SQ5 FY' || ($payload['fields'][7]['value'] ?? '') !== 'GPSWCarID:456') {
    $failures[] = 'Expected technical values, model names, and same-vehicle token to stay untranslated.';
}
foreach (['80B816720', 'DPU', 'LZ7S', 'Q5 SQ5 FY', 'GPSWCarID:456'] as $protectedText) {
    if (in_array($protectedText, $provider->texts, true)) {
        $failures[] = 'Expected protected value not to be sent to Google Translate: ' . $protectedText;
    }
}
foreach (['80B816720', 'DPU', 'LZ7S', 'Q5 SQ5 FY', 'GPSWCarID:456'] as $protectedText) {
    if (!in_array($protectedText, $payload['protected_technical_values'] ?? [], true)) {
        $failures[] = 'Expected protected technical values metadata to include ' . $protectedText;
    }
}

if (($payload['aspects']['Couleur'][0] ?? '') !== 'Gris' || ($payload['aspects']['Type de carburant'][0] ?? '') !== 'Essence' || ($payload['aspects']['Type de boîte de vitesses'][0] ?? '') !== 'Automatique') {
    $failures[] = 'Expected eBay item specifics/aspects to use the same French translations.';
}

$cached = $translator->cached(10907, $source);
if (!empty($cached['stale'])) {
    $failures[] = 'Expected freshly cached payload to be non-stale after admin regeneration refresh.';
}
if (($cached['source_hash'] ?? '') !== ($cached['cached_translation_hash'] ?? '')) {
    $failures[] = 'Expected admin regeneration to write a fresh cached content hash that matches the current source hash.';
}
foreach (['called_ebay_api', 'updated_ebay_listing', 'created_ebay_listing', 'modified_woo_product'] as $safetyFlag) {
    if (!array_key_exists($safetyFlag, $payload) || $payload[$safetyFlag] !== false) {
        $failures[] = 'Expected French content refresh safety flag ' . $safetyFlag . ' to be false.';
    }
}

$changed = $source;
$changed['description'] = 'ZMIENIONY OPIS WOO';
$stale = $translator->cached(10907, $changed);
if (empty($stale['stale'])) {
    $failures[] = 'Expected stale=true when Woo source description changes.';
}
if ($provider->calls <= 0) {
    $failures[] = 'Expected regeneration to call the Google provider.';
}

$mergedAspects = $translator->translate_aspects_from_cache(10907, $source, ['MPN' => ['8P0'], 'Producent' => ['AUDI'], 'Kolor' => ['Szary']]);
if (($mergedAspects['MPN'][0] ?? '') !== '8P0' || ($mergedAspects['Fabricant'][0] ?? '') !== 'AUDI' || ($mergedAspects['Couleur'][0] ?? '') !== 'Gris') {
    $failures[] = 'Expected translated cached aspects to preserve required fallback aspects and translated values.';
}

// Additional override coverage.
$GLOBALS['wei_fr_test_meta'] = [];
$provider2 = new WeiFakeGoogleProvider();
$payload2 = $translator->refresh(10908, ['product_id' => 10908, 'title' => 'Test title', 'description' => 'Opis', 'fields' => [
    ['polish_label' => 'Pozycja kierownicy', 'value' => 'Lewa strona'],
    ['polish_label' => 'Kolor', 'value' => 'Biały'],
    ['polish_label' => 'Kolor', 'value' => 'Srebrny'],
    ['polish_label' => 'Stan', 'value' => 'Nowy'],
    ['polish_label' => 'Typ skrzyni biegów', 'value' => 'Manualny'],
    ['polish_label' => 'Stan', 'value' => 'Używany'],
    ['polish_label' => 'Rodzaj paliwa', 'value' => 'Diesel'],
    ['polish_label' => 'Koła napędowe', 'value' => 'AWD'],
    ['polish_label' => 'Koła napędowe', 'value' => 'Przód'],
    ['polish_label' => 'Typ skrzyni biegów', 'value' => 'Automatyczny'],
]], $provider2);
$overrideValues = array_column($payload2['fields'], 'value');
foreach (['Volant à gauche', 'Blanc', 'Argent', 'Neuf', 'Boîte manuelle', 'Occasion', 'Diesel', 'Transmission intégrale', 'Traction avant', 'Automatique'] as $expectedOverride) {
    if (!in_array($expectedOverride, $overrideValues, true)) {
        $failures[] = 'Expected PL -> FR override value: ' . $expectedOverride;
    }
}


foreach ($payload2['fields'] as $field) {
    if (in_array(($field['source_value'] ?? ''), ['Biały', 'Lewa strona', 'Używany', 'Diesel', 'AWD'], true) && trim((string) ($field['translated_value'] ?? '')) === '') {
        $failures[] = 'Expected product-2081-style known source value to store non-empty translated_value. Got ' . json_encode($field);
    }
}
if ($translator->untranslated_fields(['fields' => [['french_label' => 'Type de carburant', 'value' => 'Diesel', 'source_value' => 'Diesel', 'translated_value' => 'Diesel']]]) !== []) {
    $failures[] = 'Expected Diesel to be accepted as valid French/language-neutral and not flagged stale.';
}

// Stale detection for legacy French content caches generated under older source/template rules.
$GLOBALS['wei_fr_test_meta'] = [];
$currentHash = $translator->source_hash($source);
update_post_meta(20001, EbayFrenchContentTranslator::META_HASH, $currentHash);
update_post_meta(20001, EbayFrenchContentTranslator::META_PAYLOAD, [
    'title' => 'Alter Titel',
    'description' => 'Hallo, das Angebot gilt für ein altes Allegro Angebot. TEIL IN FUNKTIONSFÄHIGEM ZUSTAND.',
    'fields' => [
        ['french_label' => 'Couleur', 'value' => 'Czarny'],
        ['french_label' => 'Position de montage', 'value' => 'Przód'],
        ['french_label' => 'Lenkradposition', 'value' => 'Lewa strona'],
        ['french_label' => 'Type de carburant', 'value' => 'Benzyna'],
        ['french_label' => 'Type de boîte de vitesses', 'value' => 'Mechaniczna'],
        ['french_label' => 'Type de carburant', 'value' => 'Diesel', 'source_value' => 'Diesel', 'translated_value' => ''],
    ],
    'aspects' => [],
    'source_hash' => $currentHash,
    'translation_source' => 'legacy_cache',
]);
$legacyCached = $translator->cached(20001, $source);
foreach (['old_schema_version', 'old_template_version', 'old_allegro_description_marker', 'untranslated_spec_values'] as $expectedReason) {
    if (!in_array($expectedReason, $legacyCached['stale_reasons'] ?? [], true)) {
        $failures[] = 'Expected legacy cached content to be stale because of ' . $expectedReason;
    }
}
if (empty($legacyCached['stale'])) {
    $failures[] = 'Expected old cached content without schema version and old Allegro markers to be stale.';
}

$GLOBALS['wei_fr_test_meta'] = [];
$provider3 = new WeiFakeGoogleProvider();
$payload3 = $translator->refresh(20002, ['product_id' => 20002, 'title' => 'Test title', 'description' => 'Opis produktu Woo', 'description_source' => 'post_content', 'fields' => [
    ['polish_label' => 'Kolor', 'value' => 'Czarny'],
    ['polish_label' => 'Pozycja', 'value' => 'Przód'],
    ['polish_label' => 'Pozycja kierownicy', 'value' => 'Lewa strona'],
    ['polish_label' => 'Rodzaj paliwa', 'value' => 'Benzyna'],
    ['polish_label' => 'Typ skrzyni biegów', 'value' => 'Mechaniczna'],
    ['polish_label' => 'Koła napędowe', 'value' => 'Przód'],
]], $provider3);
if (($payload3['french_content_schema_version'] ?? '') !== EbayFrenchContentTranslator::SCHEMA_VERSION || ($payload3['template_version'] ?? '') !== EbayFrenchContentTranslator::TEMPLATE_VERSION) {
    $failures[] = 'Expected force regeneration/refresh payload to store current schema and template version.';
}
$payload3Values = array_column($payload3['fields'], 'value');
foreach (['Noir', 'Avant', 'Volant à gauche', 'Essence', 'Boîte manuelle', 'Traction avant'] as $expectedValue) {
    if (!in_array($expectedValue, $payload3Values, true)) {
        $failures[] = 'Expected regenerated content to translate Polish spec value: ' . $expectedValue;
    }
}
$cached3 = $translator->cached(20002, ['product_id' => 20002, 'title' => 'Test title', 'description' => 'Opis produktu Woo', 'description_source' => 'post_content', 'fields' => [
    ['polish_label' => 'Kolor', 'value' => 'Czarny'],
    ['polish_label' => 'Pozycja', 'value' => 'Przód'],
    ['polish_label' => 'Pozycja kierownicy', 'value' => 'Lewa strona'],
    ['polish_label' => 'Rodzaj paliwa', 'value' => 'Benzyna'],
    ['polish_label' => 'Typ skrzyni biegów', 'value' => 'Mechaniczna'],
    ['polish_label' => 'Koła napędowe', 'value' => 'Przód'],
]]);
if (!empty($cached3['stale']) || ($cached3['stale_reason'] ?? '') !== 'current') {
    $failures[] = 'Expected regenerated content with current schema/template/source to be current.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "EbayFrenchContentTranslator tests passed\n";
