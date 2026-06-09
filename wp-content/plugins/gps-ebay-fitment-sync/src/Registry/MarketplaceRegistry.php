<?php

namespace GPS_Ebay_Fitment\Registry;

class MarketplaceRegistry
{
    public function all()
    {
        return [
            'EBAY_DE' => [
                'label' => 'eBay Germany',
                'domain' => 'ebay.de',
                'plugin_key' => 'woo-ebay-integration',
                'supports_ktype' => true,
                'enabled' => true,
                'content_language' => 'de-DE',
                'currency' => 'EUR',
                'mapping_marketplace' => 'ebay',
            ],
            'EBAY_FR' => [
                'label' => 'eBay France',
                'domain' => 'ebay.fr',
                'plugin_key' => 'woo-ebay-integration-fr',
                'supports_ktype' => true,
                'enabled' => true,
                'content_language' => 'fr-FR',
                'currency' => 'EUR',
                'mapping_marketplace' => 'ebay_fr',
            ],
            'EBAY_ES' => [
                'label' => 'eBay Spain',
                'domain' => 'ebay.es',
                'plugin_key' => 'future-ebay-es',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'es-ES',
                'currency' => 'EUR',
                'mapping_marketplace' => 'ebay_es',
            ],
            'EBAY_IT' => [
                'label' => 'eBay Italy',
                'domain' => 'ebay.it',
                'plugin_key' => 'future-ebay-it',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'it-IT',
                'currency' => 'EUR',
                'mapping_marketplace' => 'ebay_it',
            ],
            'EBAY_GB' => [
                'label' => 'eBay United Kingdom',
                'domain' => 'ebay.co.uk',
                'plugin_key' => 'future-ebay-gb',
                'supports_ktype' => true,
                'enabled' => false,
                'content_language' => 'en-GB',
                'currency' => 'GBP',
                'mapping_marketplace' => 'ebay_gb',
            ],
        ];
    }

    public function enabled()
    {
        return array_filter($this->all(), static function ($marketplace) {
            return !empty($marketplace['enabled']);
        });
    }

    public function get($marketplace_id)
    {
        $all = $this->all();
        return isset($all[$marketplace_id]) ? $all[$marketplace_id] : null;
    }
}
