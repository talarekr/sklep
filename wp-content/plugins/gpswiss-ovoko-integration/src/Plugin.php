<?php

namespace GPSwiss\Ovoko;

use GPSwiss\Ovoko\Services\AdminPage;
use GPSwiss\Ovoko\Services\OvokoIntegrationService;

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
        $adminPage = new AdminPage($service);

        $service->hooks();
        $adminPage->hooks();
    }
}
