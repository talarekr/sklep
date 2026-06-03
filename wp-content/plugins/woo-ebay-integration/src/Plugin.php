<?php

namespace WEI;

use WEI\Adapters\EbayAdapter;
use WEI\Database\Migrations;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Repositories\MappingRepository;
use WEI\Services\EbayAuth;
use WEI\Services\EbayClient;
use WEI\Services\EbayTaxonomyService;
use WEI\Services\EbaySkuGenerator;
use WEI\Services\EbayPriceResolver;
use WEI\Services\Logger;
use WEI\Services\OrderImporter;
use WEI\Services\SyncService;
use WEI\Services\StockSyncService;
use WEI\Services\AdminPage;
use WEI\Services\AutoCategoryMappingService;
use WEI\Services\AutoSyncScheduler;

class Plugin
{
    public const OPTION_KEY = 'wei_ebay_settings';
    public const DEFAULT_EBAY_SELLER_USERNAME = 'gpswiss';

    public function boot(): void
    {
        $logger = new Logger();
        $repo = new MappingRepository($logger);
        $categoryRepo = new CategoryMappingRepository($logger);
        $auth = new EbayAuth($logger);
        $client = new EbayClient($auth, $logger);
        $taxonomy = new EbayTaxonomyService($client, $logger);
        $autoCategoryMapper = new AutoCategoryMappingService($categoryRepo, $taxonomy, $logger, $client);
        $skuGenerator = new EbaySkuGenerator($logger);
        $priceResolver = new EbayPriceResolver($logger);
        $adapter = new EbayAdapter($client, $repo, $categoryRepo, $taxonomy, $logger, $skuGenerator, $priceResolver);
        $sync = new SyncService($adapter, $repo, $logger);
        $orders = new OrderImporter($adapter, $repo, $logger);
        $scheduler = new AutoSyncScheduler($adapter, $orders, $logger);
        $stockSync = new StockSyncService($client, $repo, $logger);
        $adminPage = new AdminPage($auth, $adapter, $sync, $orders, $logger, $categoryRepo, $autoCategoryMapper, $skuGenerator, $priceResolver, $taxonomy, $scheduler, $stockSync);

        Migrations::maybe_upgrade();
        $adminPage->hooks();
        $scheduler->hooks();
        $stockSync->hooks();

        add_action('admin_init', [$auth, 'handle_oauth_callback'], 0);
        add_action('current_screen', [$auth, 'handle_current_screen_oauth_callback'], 0);
        add_action('load-admin_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [$auth, 'handle_load_oauth_callback'], 0);
        add_action('load-woocommerce_page_' . EbayAuth::CALLBACK_PAGE_SLUG, [$auth, 'handle_woocommerce_load_oauth_callback'], 0);
        add_action('admin_post_' . EbayAuth::ADMIN_POST_CALLBACK_ACTION, [$auth, 'handle_admin_post_oauth_callback']);
        add_action('admin_post_nopriv_' . EbayAuth::ADMIN_POST_CALLBACK_ACTION, [$auth, 'handle_admin_post_oauth_callback']);
        add_action('wei_ebay_sync_stock_batch', [$sync, 'sync_stock_batch']);
        add_action('wei_ebay_import_orders', [$orders, 'import_once']);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('gpswiss ebay categories ovoko-suggestions', static function (array $args, array $assocArgs) use ($autoCategoryMapper): void {
                $limit = isset($assocArgs['limit']) ? max(1, min(10000, (int) $assocArgs['limit'])) : 500;
                $marketplaceId = isset($assocArgs['marketplace']) ? (string) $assocArgs['marketplace'] : 'EBAY_DE';
                $leafOnly = !isset($assocArgs['all-categories']);
                $forceRefresh = isset($assocArgs['force-refresh']);
                $summary = $autoCategoryMapper->export_ovoko_category_suggestions_csv($marketplaceId, $limit, $leafOnly, $forceRefresh);
                $reports = is_array($summary['reports'] ?? null) ? $summary['reports'] : [];
                $csv = is_array($reports['ovoko_suggestions_csv'] ?? null) ? $reports['ovoko_suggestions_csv'] : [];
                if (!empty($assocArgs['output']) && !empty($csv['path']) && is_readable((string) $csv['path'])) {
                    copy((string) $csv['path'], (string) $assocArgs['output']);
                    $summary['output'] = (string) $assocArgs['output'];
                }
                \WP_CLI::line(wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            });
        }
    }
}
