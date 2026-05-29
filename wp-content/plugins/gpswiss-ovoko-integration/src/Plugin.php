<?php

namespace GPSwiss\Ovoko;

use GPSwiss\Ovoko\Services\AdminPage;
use GPSwiss\Ovoko\Services\OvokoIntegrationService;
use GPSwiss\Ovoko\Services\OvokoBidirectionalSyncOrchestrator;
use GPSwiss\Ovoko\Services\OvokoWooSaleSyncQueue;

class Plugin
{
    private string $pluginFile;

    public function __construct(string $pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    public function boot(): void
    {
        $service = new OvokoIntegrationService($this->pluginFile);
        $saleQueue = new OvokoWooSaleSyncQueue($service);
        $orchestrator = new OvokoBidirectionalSyncOrchestrator($service, $saleQueue);
        $adminPage = new AdminPage($service);

        $service->hooks();
        $saleQueue->hooks();
        $orchestrator->hooks();
        $adminPage->hooks();
    }
}
