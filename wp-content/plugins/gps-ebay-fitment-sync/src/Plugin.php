<?php

namespace GPSEbayFitmentSync;

use GPSEbayFitmentSync\Admin\AdminPage;
use GPSEbayFitmentSync\Adapters\WeiMarketplaceMappingAdapter;
use GPSEbayFitmentSync\Database\Migrations;
use GPSEbayFitmentSync\Repositories\FitmentSyncRepository;
use GPSEbayFitmentSync\Repositories\KTypeRepository;
use GPSEbayFitmentSync\Services\AutoModeHooks;
use GPSEbayFitmentSync\Services\EbayCompatibilitySyncService;
use GPSEbayFitmentSync\Services\FitmentPreparationService;
use GPSEbayFitmentSync\Services\MarketplaceRegistry;
use GPSEbayFitmentSync\Services\NullTecDocLookupService;
use GPSEbayFitmentSync\Services\OemPartNumberResolver;
use GPSEbayFitmentSync\Services\ProductDiagnosticContextResolver;

final class Plugin
{
    private static $instance;

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void
    {
        Migrations::maybe_upgrade();

        $registry = new MarketplaceRegistry();
        $adapter = new WeiMarketplaceMappingAdapter($registry);
        $repository = new FitmentSyncRepository();
        $ktypeRepository = new KTypeRepository();
        $resolver = new OemPartNumberResolver();
        $diagnosticResolver = new ProductDiagnosticContextResolver();
        $tecdoc = new NullTecDocLookupService();
        $preparation = new FitmentPreparationService($registry, $adapter, $repository, $resolver, $diagnosticResolver, $ktypeRepository, $tecdoc);
        $syncStub = new EbayCompatibilitySyncService($registry);

        (new AdminPage($registry, $repository, $adapter, $preparation, $syncStub))->hooks();
        (new AutoModeHooks($preparation))->hooks();
    }
}
