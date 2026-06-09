<?php

namespace GPSEbayFitmentSync\Services;

final class MarketplaceRegistry
{
    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        return [
            'EBAY_DE' => [
                'label' => 'eBay.de',
                'domain' => 'ebay.de',
                'plugin_key' => 'wei_ebay_de',
                'marketplace_row_key' => 'ebay',
                'meta_prefix' => 'wei_ebay',
                'option_key' => 'wei_ebay_settings',
                'supports_ktype' => true,
                'enabled' => true,
                'content_language' => 'de-DE',
                'currency' => 'EUR',
            ],
            'EBAY_FR' => [
                'label' => 'eBay.fr',
                'domain' => 'ebay.fr',
                'plugin_key' => 'wei_ebay_fr',
                'marketplace_row_key' => 'ebay_fr',
                'meta_prefix' => 'wei_fr_ebay',
                'option_key' => 'wei_fr_ebay_settings',
                'supports_ktype' => true,
                'enabled' => true,
                'content_language' => 'fr-FR',
                'currency' => 'EUR',
            ],
            'EBAY_ES' => [
                'label' => 'eBay.es',
                'domain' => 'ebay.es',
                'plugin_key' => 'future_ebay_es',
                'marketplace_row_key' => 'ebay_es',
                'meta_prefix' => 'future_ebay_es',
                'option_key' => '',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'es-ES',
                'currency' => 'EUR',
            ],
            'EBAY_IT' => [
                'label' => 'eBay.it',
                'domain' => 'ebay.it',
                'plugin_key' => 'future_ebay_it',
                'marketplace_row_key' => 'ebay_it',
                'meta_prefix' => 'future_ebay_it',
                'option_key' => '',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'it-IT',
                'currency' => 'EUR',
            ],
            'EBAY_GB' => [
                'label' => 'eBay.co.uk',
                'domain' => 'ebay.co.uk',
                'plugin_key' => 'future_ebay_gb',
                'marketplace_row_key' => 'ebay_gb',
                'meta_prefix' => 'future_ebay_gb',
                'option_key' => '',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'en-GB',
                'currency' => 'GBP',
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function get(string $marketplace): ?array
    {
        $all = $this->all();
        return $all[$marketplace] ?? null;
    }

    /** @return string[] */
    public function enabled_marketplaces(): array
    {
        return array_keys(array_filter($this->all(), static function (array $config): bool {
            return !empty($config['enabled']);
        }));
    }
}
