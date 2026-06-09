<?php

namespace GPS_Ebay_Fitment\Service;

class FutureEbayCompatibilitySync
{
    public function sync_is_disabled_by_design()
    {
        return true;
    }

    public function planned_endpoint()
    {
        return 'PUT /sell/inventory/v1/inventory_item/{sku}/product_compatibility';
    }

    public function sync()
    {
        return new \WP_Error('gps_ebay_fitment_sync_disabled', 'Live eBay compatibility sync is intentionally disabled in this plugin version.');
    }
}
