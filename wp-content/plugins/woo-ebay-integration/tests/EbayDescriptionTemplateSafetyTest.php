<?php

declare(strict_types=1);

namespace WEI\Interfaces { interface MarketplaceAdapterInterface {} interface TranslationProviderInterface {} }
namespace WEI\Repositories { class CategoryMappingRepository {} class MappingRepository {} }
namespace WEI\Services { class EbayClient {} class EbayTaxonomyService {} class EbaySkuGenerator {} class EbayPriceResolver {} class EbayConditionResolver { public const DEFAULT_ITEM_CONDITION = 'used'; } class EbayShippingPolicyResolver {} class EbayGermanContentTranslator {} class CategoryMappingSafety { public const DEFAULT_AUTO_CONFIDENCE_THRESHOLD = 0.85; } class Logger {} }
namespace WEI\Services\Translation { class GoogleCloudTranslateProvider {} class OpenAiTranslationProvider {} }
namespace WEI { class Plugin { public const OPTION_KEY = 'wei_settings'; } }
namespace {
    function is_wp_error($value) { return false; }
    function get_option($key, $default = false) { return $default; }
    function get_post_meta($post_id, $key = '', $single = false) { return ''; }
    function esc_url_raw($url) { return (string) $url; }
    function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }

    require_once __DIR__ . '/../src/Adapters/EbayAdapter.php';

    use WEI\Adapters\EbayAdapter;

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

    $url = 'https://www.ebay.de/sch/i.html?_ssn=gpswiss&LH_TitleDesc=1&_nkw=GPSWCarID%3A456';
    if (!str_starts_with($url, 'https://www.ebay.de/') || !str_contains($url, 'LH_TitleDesc=1') || !str_contains($url, '_nkw=GPSWCarID%3A456')) {
        $failures[] = 'Expected same-vehicle CTA URL shape to use eBay.de title/description token search.';
    }

    $template = '<div>Fahrzeugreferenz: GPSWCarID:456</div><a href="' . $url . '">Weitere Teile ansehen</a>';
    if (!str_contains($template, 'GPSWCarID:456') || str_contains($template, 'display:none') || str_contains($template, 'gpswiss.pl/sch')) {
        $failures[] = 'Expected visible same-vehicle token and no hidden legacy gpswiss.pl same-vehicle URL.';
    }

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }

    echo "EbayDescriptionTemplateSafety tests passed\n";
}
