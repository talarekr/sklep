<?php

declare(strict_types=1);

namespace WEI\Interfaces { interface MarketplaceAdapterInterface {} interface TranslationProviderInterface {} }
namespace WEI\Repositories { class CategoryMappingRepository {} class MappingRepository {} }
namespace WEI\Services { class EbayClient {} class EbayTaxonomyService {} class EbaySkuGenerator {} class EbayPriceResolver {} class EbayConditionResolver { public const DEFAULT_ITEM_CONDITION = 'used'; } class EbayShippingPolicyResolver { public const POLICY_30_EUR = 'policy-30'; public const POLICY_50_EUR = 'policy-50'; public const POLICY_100_EUR = 'policy-100'; } class EbayGermanContentTranslator {} class CategoryMappingSafety { public const DEFAULT_AUTO_CONFIDENCE_THRESHOLD = 0.85; } class Logger {} }
namespace WEI\Services\Translation { class GoogleCloudTranslateProvider {} class OpenAiTranslationProvider {} }
namespace WEI { class Plugin { public const OPTION_KEY = 'wei_settings'; public const DEFAULT_EBAY_SELLER_USERNAME = 'gpswiss'; } }
namespace {
    $GLOBALS['wei_test_options'] = [];
    $GLOBALS['wei_test_post_meta'] = [];

    function is_wp_error($value) { return false; }
    function get_option($key, $default = false) { return $GLOBALS['wei_test_options'][$key] ?? $default; }
    function get_post_meta($post_id, $key = '', $single = false) { return $GLOBALS['wei_test_post_meta'][(int) $post_id][$key] ?? ''; }
    function esc_url_raw($url) { return (string) $url; }
    function esc_url($url) { return 'ESC_URL:' . htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8'); }
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }

    require_once __DIR__ . '/../src/Adapters/EbayAdapter.php';

    use WEI\Adapters\EbayAdapter;
    use WEI\Plugin;

    $failures = [];

    $valid = EbayAdapter::validate_ebay_de_rendered_html('<div style="width:100%;height:auto;background:#fff;font-weight:900;text-align:center;border-radius:8px;">Beschreibung</div>');
    if (empty($valid['valid'])) {
        $failures[] = 'Expected normal English CSS properties to pass rendered HTML validation.';
    }

    $invalid = EbayAdapter::validate_ebay_de_rendered_html('<div style="szerokość:100%;wysokość:auto;tło:#fff;waga czcionki:900;wyrównanie tekstu:center;promień obramowania:8px;wypełnienie:1px;margines:0;kolor:#fff;">Opis</div>');
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

    $GLOBALS['wei_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => 'gpswiss'];
    $GLOBALS['wei_test_post_meta'][101] = ['_ovoko_car_id' => 'CAR:456'];
    $sameVehicle = $resolveSameVehicle->invoke($adapter, 101);
    $expectedUrl = 'https://www.ebay.de/sch/i.html?_ssn=gpswiss&_nkw=CAR%3A456';
    if (($sameVehicle['url'] ?? '') !== $expectedUrl) {
        $failures[] = 'Expected eBay.de same-vehicle URL to use seller username and URL-encoded/sanitized ovoko car ID. Got ' . json_encode($sameVehicle);
    }
    if (($sameVehicle['token'] ?? '') !== 'CAR:456') {
        $failures[] = 'Expected same-vehicle token/content reference to be the Ovoko car ID without a search prefix, while preserving URL-encodable separators.';
    }
    if (empty($sameVehicle['metadata']['same_vehicle_cta_visible'])) {
        $failures[] = 'Expected product with ovoko_car_id and seller username to show CTA metadata.';
    }

    $html = $renderCta->invoke($adapter, (string) $sameVehicle['url'], (string) $sameVehicle['token']);
    if (!str_contains($html, 'Mehr Teile von diesem Fahrzeug ansehen') || !str_contains($html, 'Alle verfügbaren Teile aus demselben Fahrzeug anzeigen.')) {
        $failures[] = 'Expected German same-vehicle CTA copy in rendered HTML.';
    }
    if (!str_contains($html, 'Fahrzeug-ID: CAR:456') || str_contains($html, 'display:none')) {
        $failures[] = 'Expected visible Fahrzeug-ID reference and no hidden same-vehicle content.';
    }
    if (!str_contains($html, 'href="ESC_URL:https://www.ebay.de/sch/i.html?_ssn=gpswiss&amp;_nkw=CAR%3A456"')) {
        $failures[] = 'Expected rendered CTA href to pass through esc_url and preserve encoded eBay.de search URL.';
    }
    if (!str_contains($html, 'target="_blank"') || !str_contains($html, 'rel="noopener"')) {
        $failures[] = 'Expected CTA link to open in a new tab with noopener rel.';
    }

    $GLOBALS['wei_test_post_meta'][102] = [];
    $missingCar = $resolveSameVehicle->invoke($adapter, 102);
    if (($missingCar['url'] ?? '') !== '' || !empty($missingCar['metadata']['same_vehicle_cta_visible']) || ($missingCar['metadata']['reason'] ?? '') !== 'missing_ovoko_car_id') {
        $failures[] = 'Expected product without ovoko_car_id to suppress same-vehicle CTA.';
    }
    if ($renderCta->invoke($adapter, '', '') !== '') {
        $failures[] = 'Expected renderer to output no CTA when URL and ovoko_car_id are empty.';
    }

    $GLOBALS['wei_test_post_meta'][103] = ['donor_vehicle_id' => 'DONOR-789'];
    $donorVehicle = $resolveSameVehicle->invoke($adapter, 103);
    if (($donorVehicle['url'] ?? '') !== 'https://www.ebay.de/sch/i.html?_ssn=gpswiss&_nkw=DONOR-789') {
        $failures[] = 'Expected donor_vehicle_id fallback meta key to build the same-vehicle URL.';
    }


    $GLOBALS['wei_test_options'][Plugin::OPTION_KEY] = [];
    $GLOBALS['wei_test_post_meta'][104] = ['_ovoko_car_id' => '456'];
    $defaultSeller = $resolveSameVehicle->invoke($adapter, 104);
    if (($defaultSeller['url'] ?? '') !== 'https://www.ebay.de/sch/i.html?_ssn=gpswiss&_nkw=456') {
        $failures[] = 'Expected default seller username gpswiss to build the same-vehicle URL when settings are missing.';
    }

    $GLOBALS['wei_test_options'][Plugin::OPTION_KEY] = ['ebay_seller_username' => ''];
    if (!defined('WEI_EBAY_SELLER_USERNAME')) {
        define('WEI_EBAY_SELLER_USERNAME', 'constant-seller');
    }
    $GLOBALS['wei_test_post_meta'][105] = ['_ovoko_car_id' => 'CONST:321'];
    $constantSeller = $resolveSameVehicle->invoke($adapter, 105);
    if (($constantSeller['url'] ?? '') !== 'https://www.ebay.de/sch/i.html?_ssn=constant-seller&_nkw=CONST%3A321') {
        $failures[] = 'Expected configured seller username constant fallback to build the same-vehicle URL when settings are empty.';
    }

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo "EbayDescriptionTemplateSafety tests passed\n";
}
