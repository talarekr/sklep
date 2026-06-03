<?php

declare(strict_types=1);

namespace WEI_FR\Interfaces { interface MarketplaceAdapterInterface {} interface TranslationProviderInterface {} }
namespace WEI_FR\Repositories { class CategoryMappingRepository {} class MappingRepository {} }
namespace WEI_FR\Services { class EbayClient {} class EbayTaxonomyService {} class EbaySkuGenerator {} class EbayPriceResolver {} class EbayConditionResolver { public const DEFAULT_ITEM_CONDITION = 'used'; } class EbayShippingPolicyResolver { public const POLICY_30_EUR = 'policy-30'; public const POLICY_50_EUR = 'policy-50'; public const POLICY_100_EUR = 'policy-100'; } class EbayFrenchContentTranslator { public const META_TITLE = '_wei_fr_ebay_title'; public const META_DESCRIPTION = '_wei_fr_ebay_description_html'; public const META_SOURCE = '_wei_fr_ebay_source'; public function __construct(...$args) {} public function cached(int $productId, array $source): array { return ['ready' => false, 'source_hash' => $this->source_hash($source), 'cached_translation_hash' => '']; } public function source_hash(array $source): string { return md5(json_encode($source)); } public function untranslated_fields(array $cached): array { return []; } public function detects_untranslated_spec_value(string $value): bool { return preg_match('/[ąćęłńóśźż]|\b(lewy|lewa|prawy|prawa|przód|przod|tył|tyl|manualna|manualny|mechaniczna|mechaniczny|automatyczna|automatyczny|benzyna|używany|uzywany|czarny|biały|bialy|srebrny|szary|niebieski|czerwony|zielony|żółty|zolty)\b/iu', $value) === 1; } }  class CategoryMappingSafety { public const DEFAULT_AUTO_CONFIDENCE_THRESHOLD = 0.85; } class Logger { public function info(string $message, array $context = []): void {} public function error(string $message, array $context = []): void {} } }
namespace WEI_FR\Services\Translation { class GoogleCloudTranslateProvider {} class OpenAiTranslationProvider {} }
namespace WEI_FR { class Plugin { public const OPTION_KEY = 'wei_fr_ebay_settings'; public const DEFAULT_EBAY_SELLER_USERNAME = 'gpswiss'; } }
namespace {
    $GLOBALS['wei_fr_test_options'] = [];
    $GLOBALS['wei_fr_test_post_meta'] = [];

    function is_wp_error($value) { return false; }
    function get_option($key, $default = false) { return $GLOBALS['wei_fr_test_options'][$key] ?? $default; }
    function get_post_meta($post_id, $key = '', $single = false) { return $GLOBALS['wei_fr_test_post_meta'][(int) $post_id][$key] ?? ''; }
    function get_post_field($field, $post_id) { return $GLOBALS['wei_fr_test_post_fields'][(int) $post_id][$field] ?? ''; }
    function wc_get_product($product_id) { return $GLOBALS['wei_fr_test_products'][(int) $product_id] ?? null; }
    function wc_get_products($args) { return []; }
    function metadata_exists($type, $object_id, $meta_key) { return array_key_exists($meta_key, $GLOBALS['wei_fr_test_post_meta'][(int) $object_id] ?? []); }
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
    function wp_kses_post($text) { return (string) $text; }
    function wpautop($text) { return '<p>' . (string) $text . '</p>'; }
    function wc_attribute_label($name, $product = null) { return (string) $name; }
    function esc_url_raw($url) { return (string) $url; }
    function esc_url($url) { return 'ESC_URL:' . htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }

    require_once __DIR__ . '/../src/Adapters/EbayAdapter.php';

    use WEI_FR\Adapters\EbayAdapter;
    use WEI_FR\Plugin;
    use WEI_FR\Repositories\CategoryMappingRepository;
    use WEI_FR\Repositories\MappingRepository;
    use WEI_FR\Services\EbayClient;
    use WEI_FR\Services\EbayTaxonomyService;
    use WEI_FR\Services\Logger;

    class WeiTemplatePreviewFakeProduct
    {
        public function __construct(private int $id, private string $sku, private string $name, private string $description = '') {}
        public function get_id(): int { return $this->id; }
        public function get_sku(): string { return $this->sku; }
        public function get_name(): string { return $this->name; }
        public function get_description(): string { return $this->description; }
        public function get_short_description(): string { return ''; }
        public function get_attributes(): array { return []; }
    }

    $failures = [];

    $valid = EbayAdapter::validate_ebay_fr_rendered_html('<div style="width:100%;height:auto;background:#fff;font-weight:900;text-align:center;border-radius:8px;">Beschreibung</div>');
    if (empty($valid['valid'])) {
        $failures[] = 'Expected normal English CSS properties to pass rendered HTML validation.';
    }

    $invalid = EbayAdapter::validate_ebay_fr_rendered_html('<div style="szerokość:100%;wysokość:auto;tło:#fff;waga czcionki:900;wyrównanie tekstu:center;promień obramowania:8px;wypełnienie:1px;margines:0;kolor:#fff;">Opis</div>');
    if (!empty($invalid['valid']) || ($invalid['error'] ?? '') !== 'invalid_translated_html_css') {
        $failures[] = 'Expected translated Polish CSS property names to fail with invalid_translated_html_css.';
    }
    foreach (['szerokość', 'wysokość', 'tło', 'waga czcionki', 'wyrównanie tekstu', 'promień obramowania', 'wypełnienie', 'margines', 'kolor:#'] as $term) {
        if (!in_array($term, $invalid['matches'] ?? [], true)) {
            $failures[] = 'Expected validation matches to include ' . $term;
        }
    }

    $adapter = (new \ReflectionClass(EbayAdapter::class))->newInstanceWithoutConstructor();
    $resolveSameVehicle = new \ReflectionMethod(EbayAdapter::class, 'resolve_ebay_same_vehicle_url_for_product');
    $resolveSameVehicle->setAccessible(true);
    $renderCta = new \ReflectionMethod(EbayAdapter::class, 'render_ebay_same_vehicle_cta_html');
    $renderCta->setAccessible(true);
    $renderSpecs = new \ReflectionMethod(EbayAdapter::class, 'render_ebay_template_specification_rows');
    $renderSpecs->setAccessible(true);
    $fieldDiagnostics = new \ReflectionMethod(EbayAdapter::class, 'french_template_field_diagnostics');
    $fieldDiagnostics->setAccessible(true);



    $mappingMethod = new \ReflectionMethod(EbayAdapter::class, 'ebay_fr_template_field_mapping');
    $mappingMethod->setAccessible(true);
    $mapping = $mappingMethod->invoke($adapter);
    foreach ([
        'Kod koloru' => 'Code couleur',
        'Kod silnika' => 'Code moteur',
        'Kolor' => 'Couleur',
        'Koła napędowe' => 'Transmission',
        'Moc silnika' => 'Puissance moteur',
        'Model' => 'Modèle',
        'Modyfikacja' => 'Variante / Version',
        'Numer części' => 'Numéro de pièce',
        'Okres' => 'Période de production',
        'Pojemność silnika' => 'Cylindrée',
        'Pozycja kierownicy' => 'Position du volant',
        'Producent' => 'Fabricant',
        'Rodzaj paliwa' => 'Type de carburant',
        'Rok produkcji samochodu' => 'Année du véhicule',
        'Typ skrzyni biegów' => 'Type de boîte de vitesses',
    ] as $polishLabel => $expectedFrenchLabel) {
        if (($mapping[$polishLabel]['french_label'] ?? '') !== $expectedFrenchLabel) {
            $failures[] = 'Expected FR template label ' . $polishLabel . ' to render as ' . $expectedFrenchLabel . '. Got ' . json_encode($mapping[$polishLabel] ?? null);
        }
    }
    foreach (['Farbcode', 'Motorcode', 'Farbe', 'Antrieb', 'Motorleistung', 'Modell', 'Teilenummer', 'Bauzeitraum', 'Hubraum', 'Lenkradposition', 'Hersteller', 'Kraftstoffart', 'Getriebeart'] as $germanLabel) {
        if (str_contains(json_encode($mapping, JSON_UNESCAPED_UNICODE), $germanLabel)) {
            $failures[] = 'Expected FR template field mapping not to contain German label: ' . $germanLabel;
        }
    }

    $translatedSpecFields = [
        ['polish_label' => 'Kolor', 'french_label' => 'Couleur', 'source_value' => 'Biały', 'value' => 'Biały', 'translated_value' => 'Blanc'],
        ['polish_label' => 'Koła napędowe', 'french_label' => 'Transmission', 'source_value' => 'Przód', 'value' => 'Przód', 'translated_value' => 'Traction avant'],
        ['polish_label' => 'Pozycja kierownicy', 'french_label' => 'Position du volant', 'source_value' => 'Lewa strona', 'value' => 'Lewa strona', 'translated_value' => 'Volant à gauche'],
        ['polish_label' => 'Typ skrzyni biegów', 'french_label' => 'Type de boîte de vitesses', 'source_value' => 'Automatyczny', 'value' => 'Automatyczny', 'translated_value' => 'Automatique'],
        ['polish_label' => 'Rodzaj paliwa', 'french_label' => 'Type de carburant', 'source_value' => 'Diesel', 'value' => 'Diesel', 'translated_value' => 'Diesel', 'translation_source' => 'local_override'],
    ];
    $specHtml = $renderSpecs->invoke($adapter, $translatedSpecFields);
    foreach (['Blanc', 'Traction avant', 'Volant à gauche', 'Automatique', 'Diesel'] as $expectedTranslatedValue) {
        if (!str_contains($specHtml, $expectedTranslatedValue)) {
            $failures[] = 'Expected rendered French specification template to use translated value: ' . $expectedTranslatedValue;
        }
    }
    foreach (['Biały', 'Przód', 'Lewa strona', 'Automatyczny'] as $unexpectedRawValue) {
        if (str_contains($specHtml, $unexpectedRawValue)) {
            $failures[] = 'Expected rendered French specification template not to use raw Polish source value when translated_value exists: ' . $unexpectedRawValue;
        }
    }
    $diagnostics = $fieldDiagnostics->invoke($adapter, $translatedSpecFields);
    foreach ($diagnostics as $diagnostic) {
        if (($diagnostic['source_value'] ?? '') !== 'Diesel' && ($diagnostic['source_value'] ?? '') === ($diagnostic['value_used_in_template'] ?? '')) {
            $failures[] = 'Expected diagnostics value_used_in_template to differ from raw Polish source value when translated_value exists. Got ' . json_encode($diagnostic);
        }
        if (($diagnostic['translated_value'] ?? '') !== ($diagnostic['value_used_in_template'] ?? '')) {
            $failures[] = 'Expected diagnostics value_used_in_template to equal translated_value. Got ' . json_encode($diagnostic);
        }
        foreach (['polish_label', 'french_label', 'source_value', 'translated_value', 'value_used_in_template', 'translation_source', 'is_detected_untranslated'] as $requiredDiagnosticKey) {
            if (!array_key_exists($requiredDiagnosticKey, $diagnostic)) {
                $failures[] = 'Expected template field diagnostics to include ' . $requiredDiagnosticKey . '. Got ' . json_encode($diagnostic);
            }
        }
        if (($diagnostic['source_value'] ?? '') === 'Diesel' && (($diagnostic['is_detected_untranslated'] ?? '') !== 'no' || ($diagnostic['warning'] ?? '') !== '')) {
            $failures[] = 'Expected Diesel diagnostics to be valid French/language-neutral and not stale. Got ' . json_encode($diagnostic);
        }
    }

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => 'gpswiss'];
    $GLOBALS['wei_fr_test_post_meta'][101] = ['_ovoko_car_id' => 'CAR:456'];
    $sameVehicle = $resolveSameVehicle->invoke($adapter, 101);
    $expectedUrl = 'https://www.ebay.fr/sch/i.html?_ssn=gpswiss&_nkw=CAR%3A456';
    if (($sameVehicle['url'] ?? '') !== $expectedUrl) {
        $failures[] = 'Expected eBay.fr same-vehicle URL to use seller username and URL-encoded/sanitized ovoko car ID. Got ' . json_encode($sameVehicle);
    }
    if (($sameVehicle['token'] ?? '') !== 'CAR:456') {
        $failures[] = 'Expected same-vehicle token/content reference to be the Ovoko car ID without a search prefix, while preserving URL-encodable separators.';
    }
    if (empty($sameVehicle['metadata']['same_vehicle_cta_visible'])) {
        $failures[] = 'Expected product with ovoko_car_id and seller username to show CTA metadata.';
    }

    $html = $renderCta->invoke($adapter, (string) $sameVehicle['url'], (string) $sameVehicle['token']);
    if (!str_contains($html, 'Voir plus de pièces de ce véhicule')) {
        $failures[] = 'Expected French same-vehicle CTA button copy in rendered HTML.';
    }
    foreach (['Toutes les pièces disponibles du même véhicule.', 'ID véhicule :', 'CAR:456', 'display:none'] as $unexpectedSameVehicleHtml) {
        if (str_contains($html, $unexpectedSameVehicleHtml)) {
            $failures[] = 'Expected live same-vehicle CTA HTML to render only the button/link, but found ' . $unexpectedSameVehicleHtml;
        }
    }
    if (!str_contains($html, 'href="ESC_URL:https://www.ebay.fr/sch/i.html?_ssn=gpswiss&amp;_nkw=CAR%3A456"')) {
        $failures[] = 'Expected rendered CTA href to pass through esc_url and preserve encoded eBay.fr search URL.';
    }
    if (!str_contains($html, 'target="_blank"') || !str_contains($html, 'rel="noopener"')) {
        $failures[] = 'Expected CTA link to open in a new tab with noopener rel.';
    }

    $GLOBALS['wei_fr_test_post_meta'][102] = [];
    $missingCar = $resolveSameVehicle->invoke($adapter, 102);
    if (($missingCar['url'] ?? '') !== '' || !empty($missingCar['metadata']['same_vehicle_cta_visible']) || ($missingCar['metadata']['reason'] ?? '') !== 'missing_ovoko_car_id') {
        $failures[] = 'Expected product without ovoko_car_id to suppress same-vehicle CTA.';
    }
    if ($renderCta->invoke($adapter, '', '') !== '') {
        $failures[] = 'Expected renderer to output no CTA when URL and ovoko_car_id are empty.';
    }

    $GLOBALS['wei_fr_test_post_meta'][103] = ['donor_vehicle_id' => 'DONOR-789'];
    $donorVehicle = $resolveSameVehicle->invoke($adapter, 103);
    if (($donorVehicle['url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=gpswiss&_nkw=DONOR-789') {
        $failures[] = 'Expected donor_vehicle_id fallback meta key to build the same-vehicle URL.';
    }


    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = [];
    $GLOBALS['wei_fr_test_post_meta'][104] = ['_ovoko_car_id' => '456'];
    $defaultSeller = $resolveSameVehicle->invoke($adapter, 104);
    if (($defaultSeller['url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=gpswiss&_nkw=456') {
        $failures[] = 'Expected default seller username gpswiss to build the same-vehicle URL when settings are missing.';
    }
    if (($defaultSeller['metadata']['seller_source'] ?? '') !== 'default_gpswiss') {
        $failures[] = 'Expected missing seller settings to be diagnosed as seller_source=default_gpswiss. Got ' . json_encode($defaultSeller['metadata'] ?? []);
    }

    $GLOBALS['wei_fr_test_products'][10907] = new WeiTemplatePreviewFakeProduct(10907, 'SKU-10907', 'Preview product', 'Source description');
    $GLOBALS['wei_fr_test_post_fields'][10907] = ['post_content' => 'Source description'];
    $GLOBALS['wei_fr_test_post_meta'][10907] = ['_ovoko_car_id' => '456', '_product_attributes' => []];
    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => ''];
    $previewAdapter = new EbayAdapter(new EbayClient(), new MappingRepository(), new CategoryMappingRepository(), new EbayTaxonomyService(), new Logger());
    $adminPreview = $previewAdapter->preview_ebay_fr_description_template('10907');
    if (($adminPreview['result'] ?? '') !== 'success') {
        $failures[] = 'Expected admin preview path to succeed for product 10907 fixture. Got ' . json_encode($adminPreview);
    }
    if (($adminPreview['same_vehicle_url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=gpswiss&_nkw=456') {
        $failures[] = 'Expected admin preview path metadata to use the same seller resolver as the CTA URL builder and default gpswiss when the saved setting is empty. Got ' . json_encode($adminPreview['same_vehicle_cta'] ?? []);
    }
    if (empty($adminPreview['same_vehicle_cta_visible'])) {
        $failures[] = 'Expected admin preview path to expose same_vehicle_cta_visible=true when seller and Ovoko car ID resolve.';
    }
    if (($adminPreview['same_vehicle_seller_source'] ?? '') !== 'default_gpswiss' || ($adminPreview['same_vehicle_cta']['seller_source'] ?? '') !== 'default_gpswiss') {
        $failures[] = 'Expected admin preview diagnostics to expose seller_source=default_gpswiss for an empty saved setting. Got ' . json_encode($adminPreview);
    }
    $previewHtml = (string) ($adminPreview['html'] ?? '');
    foreach (['Zgodność / dopasowanie', 'Nadaje się do', 'Ważne instrukcje', 'Compatibilité / ajustement', 'Compatible avec', 'Instructions importantes', 'Veuillez comparer le numéro de pièce et les photos avant achat.', 'Veuillez vérifier avec le numéro de pièce.'] as $removedFitmentText) {
        if (str_contains($previewHtml, $removedFitmentText)) {
            $failures[] = 'Expected live template preview HTML to omit compatibility/fitment section text, but found ' . $removedFitmentText;
        }
    }
    if (!str_contains($previewHtml, 'Voir plus de pièces de ce véhicule')) {
        $failures[] = 'Expected live template preview HTML to keep the same-vehicle CTA button.';
    }
    $specPosition = strpos($previewHtml, 'Spécifications');
    $ctaPosition = strpos($previewHtml, 'Voir plus de pièces de ce véhicule');
    $deliveryPosition = strpos($previewHtml, 'Livraison dans toute l’Europe');
    if ($specPosition === false || $ctaPosition === false || $deliveryPosition === false || !($specPosition < $ctaPosition && $ctaPosition < $deliveryPosition)) {
        $failures[] = 'Expected live template order to be specifications table, same-vehicle CTA, then Europe delivery section.';
    }
    if (!str_contains($previewHtml, '</table></div><div style="margin:18px 0 24px;text-align:center;"><a href="ESC_URL:https://www.ebay.fr/sch/i.html?_ssn=gpswiss&amp;_nkw=456"')) {
        $failures[] = 'Expected same-vehicle CTA to render immediately after the specifications table block without an intervening compatibility placeholder.';
    }

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => 'settings-seller'];
    $GLOBALS['wei_fr_test_post_meta'][105] = ['_ovoko_car_id' => 'SETTINGS:321'];
    $settingsSeller = $resolveSameVehicle->invoke($adapter, 105);
    if (($settingsSeller['url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=settings-seller&_nkw=SETTINGS%3A321' || ($settingsSeller['metadata']['seller_source'] ?? '') !== 'settings') {
        $failures[] = 'Expected non-empty seller setting to be used with seller_source=settings. Got ' . json_encode($settingsSeller);
    }

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => ''];
    if (!defined('WEI_FR_EBAY_SELLER_USERNAME')) {
        define('WEI_FR_EBAY_SELLER_USERNAME', 'constant-seller');
    }
    $GLOBALS['wei_fr_test_post_meta'][106] = ['_ovoko_car_id' => 'CONST:321'];
    $constantSeller = $resolveSameVehicle->invoke($adapter, 106);
    if (($constantSeller['url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=constant-seller&_nkw=CONST%3A321') {
        $failures[] = 'Expected configured seller username constant fallback to build the same-vehicle URL when settings are empty.';
    }
    if (($constantSeller['metadata']['seller_source'] ?? '') !== 'constant') {
        $failures[] = 'Expected constant seller fallback to diagnose seller_source=constant. Got ' . json_encode($constantSeller['metadata'] ?? []);
    }

    $GLOBALS['wei_fr_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => '   '];
    $GLOBALS['wei_fr_test_post_meta'][107] = ['_ovoko_car_id' => '456'];
    $emptySavedSeller = $resolveSameVehicle->invoke($adapter, 107);
    if (($emptySavedSeller['url'] ?? '') !== 'https://www.ebay.fr/sch/i.html?_ssn=constant-seller&_nkw=456' || ($emptySavedSeller['metadata']['seller_source'] ?? '') !== 'constant') {
        $failures[] = 'Expected empty saved seller setting to be ignored and constant fallback to be used. Got ' . json_encode($emptySavedSeller);
    }


    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo "EbayDescriptionTemplateSafety tests passed\n";
}
