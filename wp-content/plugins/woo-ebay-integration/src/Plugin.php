<?php

namespace WEI;

use WEI\Adapters\EbayAdapter;
use WEI\Database\Migrations;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayAuth;
use WEI\Services\EbayClient;
use WEI\Services\Logger;
use WEI\Services\OrderImporter;
use WEI\Services\SyncService;

class Plugin
{
    public const OPTION_KEY = 'wei_ebay_settings';

    public function boot(): void
    {
        $logger = new Logger();
        $repo = new MappingRepository($logger);
        $auth = new EbayAuth($logger);
        $client = new EbayClient($auth, $logger);
        $adapter = new EbayAdapter($client, $repo, $logger);
        $sync = new SyncService($adapter, $repo, $logger);
        $orders = new OrderImporter($adapter, $repo, $logger);

        Migrations::maybe_upgrade();

        add_action('init', [$auth, 'handle_oauth_callback']);
        add_action('woocommerce_product_set_stock', [$sync, 'handle_stock_change'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$sync, 'handle_stock_change'], 10, 1);
        add_action('wei_ebay_sync_stock_batch', [$sync, 'sync_stock_batch']);
        add_action('wei_ebay_import_orders', [$orders, 'import_once']);
    }
}
