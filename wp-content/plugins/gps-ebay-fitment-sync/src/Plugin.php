<?php

namespace GPS_Ebay_Fitment;

use GPS_Ebay_Fitment\Admin\AdminPage;
use GPS_Ebay_Fitment\Database\Installer;
use GPS_Ebay_Fitment\Registry\MarketplaceRegistry;
use GPS_Ebay_Fitment\Repository\FitmentSyncRepository;
use GPS_Ebay_Fitment\Repository\TecDocCacheRepository;
use GPS_Ebay_Fitment\Resolver\PartNumberResolver;
use GPS_Ebay_Fitment\Service\ApifyTecDocLookupService;
use GPS_Ebay_Fitment\Service\ProductDiscoveryService;

class Plugin
{
    private static $instance;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init()
    {
        Installer::maybe_install();
        if (is_admin()) {
            $fitment_repository = new FitmentSyncRepository();
            $cache_repository = new TecDocCacheRepository();
            $part_number_resolver = new PartNumberResolver();
            $registry = new MarketplaceRegistry();
            $discovery_service = new ProductDiscoveryService($registry, $fitment_repository, $part_number_resolver);
            $lookup_service = new ApifyTecDocLookupService($cache_repository);

            (new AdminPage($registry, $fitment_repository, $cache_repository, $discovery_service, $lookup_service))->hooks();
        }
    }
}
