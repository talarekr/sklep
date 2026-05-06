<?php

namespace WEI;

use WEI\Adapters\EbayAdapter;
use WEI\Database\Migrations;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayAuth;
use WEI\Services\EbayClient;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\Logger;
use WEI\Services\OrderImporter;
use WEI\Services\SyncService;
use WEI\Services\AdminPage;
use WEI\Services\AutoCategoryMappingService;

class Plugin
{
    public const OPTION_KEY = 'wei_ebay_settings';

    public function boot(): void
    {
        $logger = new Logger();
        $repo = new MappingRepository($logger);
        $categoryRepo = new CategoryMappingRepository($logger);
        $auth = new EbayAuth($logger);
        $client = new EbayClient($auth, $logger);
        $taxonomy = new EbayTaxonomyService($client, $logger);
        $autoCategoryMapper = new AutoCategoryMappingService($categoryRepo, $taxonomy, $logger);
        $adapter = new EbayAdapter($client, $repo, $categoryRepo, $taxonomy, $logger);
        $sync = new SyncService($adapter, $repo, $logger);
        $orders = new OrderImporter($adapter, $repo, $logger);
        $adminPage = new AdminPage($auth, $adapter, $sync, $orders, $logger, $categoryRepo, $autoCategoryMapper);

        Migrations::maybe_upgrade();
        $adminPage->hooks();

        add_action('admin_init', [$auth, 'handle_oauth_callback']);
        add_action('woocommerce_product_set_stock', [$sync, 'handle_stock_change'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$sync, 'handle_stock_change'], 10, 1);
        add_action('wei_ebay_sync_stock_batch', [$sync, 'sync_stock_batch']);
        add_action('wei_ebay_import_orders', [$orders, 'import_once']);
    }
}
