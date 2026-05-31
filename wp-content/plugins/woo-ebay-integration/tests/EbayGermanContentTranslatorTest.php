<?php

declare(strict_types=1);

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
}
if (!function_exists('get_post_meta')) {
    $GLOBALS['wei_test_meta'] = [];
    function get_post_meta($post_id, $key = '', $single = false) {
        return $GLOBALS['wei_test_meta'][$post_id][$key] ?? ($single ? '' : []);
    }
    function update_post_meta($post_id, $key, $value) { $GLOBALS['wei_test_meta'][$post_id][$key] = $value; return true; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($text) { return (string) $text; }
}

require_once __DIR__ . '/../src/Interfaces/TranslationProviderInterface.php';
require_once __DIR__ . '/../src/Services/EbayGermanContentTranslator.php';

use WEI\Interfaces\TranslationProviderInterface;
use WEI\Services\EbayGermanContentTranslator;

class WeiFakeGoogleProvider implements TranslationProviderInterface
{
    public int $calls = 0;
    public array $texts = [];

    public function is_configured(): bool { return true; }
    public function provider_key(): string { return 'google_cloud_translate'; }
    public function translate_product_content(\WC_Product $product, array $context): array { return []; }
    public function translate_texts(array $texts, string $source = 'pl', string $target = 'de', string $format = 'text'): array
    {
        $this->calls++;
        $map = [
            'RAMKA STELAŻ KOSZ RADIA 2 DIN PRZEŁĄCZNIKI' => 'RAHMEN HALTER KORB RADIO 2 DIN SCHALTER',
            'Test title' => 'Testtitel',
            'Kolor' => 'Farbe',
            'Rodzaj paliwa' => 'Kraftstoffart',
            'Szary' => 'Grau',
            'Benzyna' => 'Benzin',
            'Typ skrzyni biegów' => 'Getriebeart',
            'Automatyczny' => 'Automatik',
        ];
        return array_map(function ($text) use ($map) {
            $this->texts[] = (string) $text;
            return $map[(string) $text] ?? ('DE ' . $text);
        }, $texts);
    }
}

$failures = [];
$translator = new EbayGermanContentTranslator();
$source = [
    'product_id' => 10907,
    'title' => 'Test title',
    'description' => 'RAMKA STELAŻ KOSZ RADIA 2 DIN PRZEŁĄCZNIKI',
    'fields' => [
        ['polish_label' => 'Kolor', 'german_label' => 'Farbe', 'value' => 'Szary'],
        ['polish_label' => 'Rodzaj paliwa', 'german_label' => 'Kraftstoffart', 'value' => 'Benzyna'],
        ['polish_label' => 'Typ skrzyni biegów', 'german_label' => 'Getriebeart', 'value' => 'Automatyczny'],
    ],
    'aspects_source' => [
        'Kolor' => ['Szary'],
        'Rodzaj paliwa' => ['Benzyna'],
        'Typ skrzyni biegów' => ['Automatyczny'],
    ],
];
$provider = new WeiFakeGoogleProvider();
$payload = $translator->refresh(10907, $source, $provider);

if (($payload['description'] ?? '') !== 'RAHMEN HALTER KORB RADIO 2 DIN SCHALTER') {
    $failures[] = 'Expected translated description to come from current Woo source description, not old Allegro cache.';
}
if (($payload['fields'][0]['value'] ?? '') !== 'Grau' || ($payload['fields'][1]['value'] ?? '') !== 'Benzin' || ($payload['fields'][2]['value'] ?? '') !== 'Automatik') {
    $failures[] = 'Expected HTML specification values to be translated to German.';
}
if (($payload['aspects']['Farbe'][0] ?? '') !== 'Grau' || ($payload['aspects']['Kraftstoffart'][0] ?? '') !== 'Benzin' || ($payload['aspects']['Getriebeart'][0] ?? '') !== 'Automatik') {
    $failures[] = 'Expected eBay item specifics/aspects to use the same German translations.';
}

$cached = $translator->cached(10907, $source);
if (!empty($cached['stale'])) {
    $failures[] = 'Expected freshly cached payload to be non-stale.';
}
if (($cached['source_hash'] ?? '') !== ($cached['cached_translation_hash'] ?? '')) {
    $failures[] = 'Expected cached translation hash to match current source hash.';
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
if (($mergedAspects['MPN'][0] ?? '') !== '8P0' || ($mergedAspects['Hersteller'][0] ?? '') !== 'AUDI' || ($mergedAspects['Farbe'][0] ?? '') !== 'Grau') {
    $failures[] = 'Expected translated cached aspects to preserve required fallback aspects and translated values.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "EbayGermanContentTranslator tests passed\n";
