<?php

namespace GPSEbayFitmentSync\DTO;

final class ListingContext
{
    public $product_id;
    public $marketplace;
    public $plugin_key;
    public $mapping_source;
    public $inventory_item_sku;
    public $offer_id;
    public $listing_id;
    public $ebay_category_id;
    public $listing_status;
    public $export_status;
    public $public_url;
    public $raw;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
        $this->raw = is_array($this->raw) ? $this->raw : [];
    }

    public function has_listing_identifier(): bool
    {
        return trim((string) $this->inventory_item_sku) !== '' || trim((string) $this->offer_id) !== '' || trim((string) $this->listing_id) !== '';
    }
}
