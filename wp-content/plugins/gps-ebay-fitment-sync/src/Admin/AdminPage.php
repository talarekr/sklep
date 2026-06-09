<?php

namespace GPSEbayFitmentSync\Admin;

use GPSEbayFitmentSync\Adapters\WeiMarketplaceMappingAdapter;
use GPSEbayFitmentSync\Repositories\FitmentSyncRepository;
use GPSEbayFitmentSync\Services\EbayCompatibilitySyncService;
use GPSEbayFitmentSync\Services\FitmentPreparationService;
use GPSEbayFitmentSync\Services\MarketplaceRegistry;

final class AdminPage
{
    private $registry;
    private $repository;
    private $adapter;
    private $preparation;
    private $syncStub;
    private $lastResultOption = 'gps_ebay_fitment_last_scan_result';

    public function __construct(MarketplaceRegistry $registry, FitmentSyncRepository $repository, WeiMarketplaceMappingAdapter $adapter, FitmentPreparationService $preparation, EbayCompatibilitySyncService $syncStub)
    {
        $this->registry = $registry;
        $this->repository = $repository;
        $this->adapter = $adapter;
        $this->preparation = $preparation;
        $this->syncStub = $syncStub;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_gps_ebay_fitment_scan', [$this, 'handle_scan']);
        add_action('admin_post_gps_ebay_fitment_export_csv', [$this, 'handle_csv']);
    }

    public function admin_menu(): void
    {
        add_submenu_page('woocommerce', 'GPS eBay Fitment', 'GPS eBay Fitment', 'manage_woocommerce', 'gps-ebay-fitment', [$this, 'render']);
    }

    public function handle_scan(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions.', 'gps-ebay-fitment-sync'));
        }
        check_admin_referer('gps_ebay_fitment_scan');
        $args = [
            'limit' => isset($_POST['limit']) ? (int) $_POST['limit'] : 100,
            'offset' => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
            'marketplace' => sanitize_text_field((string) ($_POST['marketplace'] ?? 'all')),
            'dry_run' => !empty($_POST['dry_run']),
            'only_missing_rows' => !empty($_POST['only_missing_rows']),
            'only_missing_oem' => !empty($_POST['only_missing_oem']),
            'only_missing_ktype' => !empty($_POST['only_missing_ktype']),
            'only_ready' => !empty($_POST['only_ready']),
            'include_ended_sold' => !empty($_POST['include_ended_sold']),
        ];
        $result = $this->preparation->prepare_batch($args);
        update_option($this->lastResultOption, $result, false);
        wp_safe_redirect(add_query_arg(['page' => 'gps-ebay-fitment', 'gps_fitment_scanned' => '1'], admin_url('admin.php')));
        exit;
    }

    public function handle_csv(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Insufficient permissions.', 'gps-ebay-fitment-sync'));
        }
        check_admin_referer('gps_ebay_fitment_export_csv');
        $type = sanitize_key((string) ($_GET['type'] ?? 'full'));
        $rows = $this->repository->export_rows($type);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="gps-ebay-fitment-' . $type . '-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        $headers = ['id', 'product_id', 'sku', 'title', 'marketplace', 'plugin_key', 'fitment_status', 'oem_value', 'oem_source', 'ktype_count', 'inventory_item_sku', 'offer_id', 'listing_id', 'ebay_category_id', 'last_lookup_at', 'last_checked_at', 'last_error'];
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            fputcsv($out, [
                $row['id'] ?? '',
                $productId,
                get_post_meta($productId, '_sku', true),
                get_the_title($productId),
                $row['marketplace'] ?? '',
                $row['plugin_key'] ?? '',
                $row['fitment_status'] ?? '',
                $row['oem_value'] ?? '',
                $row['oem_source'] ?? '',
                $row['ktype_count'] ?? '',
                $row['inventory_item_sku'] ?? '',
                $row['offer_id'] ?? '',
                $row['listing_id'] ?? '',
                $row['ebay_category_id'] ?? '',
                $row['last_lookup_at'] ?? '',
                $row['last_checked_at'] ?? '',
                $row['last_error'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $counts = $this->repository->counts_by_status();
        $last = get_option($this->lastResultOption, []);
        $recent = is_array($last) && !empty($last['rows']) ? (array) $last['rows'] : $this->repository->recent_rows(['limit' => 50]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('GPS eBay Fitment', 'gps-ebay-fitment-sync'); ?></h1>
            <p><strong><?php echo esc_html__('Safety:', 'gps-ebay-fitment-sync'); ?></strong> <?php echo esc_html__('This plugin only reads existing Woo/eBay data, writes its own diagnostic table, and exports CSV. No live eBay compatibility requests are sent.', 'gps-ebay-fitment-sync'); ?></p>

            <h2><?php echo esc_html__('Overview', 'gps-ebay-fitment-sync'); ?></h2>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:1100px;">
                <?php $this->metric('eBay-mapped products', (string) $this->adapter->count_mapped_products()); ?>
                <?php $this->metric('Fitment rows', (string) ($counts['total'] ?? 0)); ?>
                <?php foreach (['missing_oem', 'missing_ktype', 'ready', 'synced', 'error'] as $status) : ?>
                    <?php $this->metric($status, (string) ($counts['by_status'][$status] ?? 0)); ?>
                <?php endforeach; ?>
            </div>
            <h3><?php echo esc_html__('Rows per marketplace', 'gps-ebay-fitment-sync'); ?></h3>
            <ul>
                <?php foreach ($this->registry->all() as $marketplace => $config) : ?>
                    <li><?php echo esc_html($marketplace . ' (' . $config['label'] . '): ' . (int) ($counts['by_marketplace'][$marketplace] ?? 0) . (!empty($config['enabled']) ? ' enabled' : ' disabled/planned')); ?></li>
                <?php endforeach; ?>
            </ul>

            <h2><?php echo esc_html__('Scan / prepare', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('gps_ebay_fitment_scan'); ?>
                <input type="hidden" name="action" value="gps_ebay_fitment_scan" />
                <table class="form-table" role="presentation">
                    <tr><th><label for="gps-fitment-limit">Limit</label></th><td><input id="gps-fitment-limit" type="number" name="limit" value="100" min="1" max="1000" /></td></tr>
                    <tr><th><label for="gps-fitment-offset">Offset</label></th><td><input id="gps-fitment-offset" type="number" name="offset" value="0" min="0" /></td></tr>
                    <tr><th><label for="gps-fitment-marketplace">Marketplace</label></th><td><select id="gps-fitment-marketplace" name="marketplace"><option value="all">all</option><option value="EBAY_DE">EBAY_DE</option><option value="EBAY_FR">EBAY_FR</option></select></td></tr>
                    <?php foreach (['only_missing_rows' => 'only missing rows', 'only_missing_oem' => 'only missing OEM', 'only_missing_ktype' => 'only missing KType', 'only_ready' => 'only ready', 'include_ended_sold' => 'include ended/sold listings', 'dry_run' => 'dry-run'] as $name => $label) : ?>
                        <tr><th><?php echo esc_html($label); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" /> <?php echo esc_html($label); ?></label></td></tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button('Scan eBay-mapped products and prepare fitment rows'); ?>
            </form>

            <h2><?php echo esc_html__('CSV export', 'gps-ebay-fitment-sync'); ?></h2>
            <p>
                <?php foreach (['full' => 'full report', 'missing_oem' => 'missing OEM', 'missing_ktype' => 'missing KType', 'ready' => 'ready for future eBay sync', 'errors' => 'errors'] as $type => $label) : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=gps_ebay_fitment_export_csv&type=' . $type), 'gps_ebay_fitment_export_csv')); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </p>

            <h2><?php echo esc_html__('Results table', 'gps-ebay-fitment-sync'); ?></h2>
            <?php $this->render_table($recent); ?>
        </div>
        <?php
    }

    private function metric(string $label, string $value): void
    {
        echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;"><strong>' . esc_html($value) . '</strong><br />' . esc_html($label) . '</div>';
    }

    private function render_table(array $rows): void
    {
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['product ID', 'SKU', 'title', 'OEM candidate', 'OEM source', 'KType count', 'KType status/confidence', 'marketplace', 'plugin key', 'inventory SKU', 'offer ID', 'listing ID', 'eBay category ID', 'listing status', 'fitment row status', 'last lookup', 'last checked', 'error', 'technical details'] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="19">' . esc_html__('No rows yet.', 'gps-ebay-fitment-sync') . '</td></tr>';
        }
        foreach ($rows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            $raw = (string) ($row['raw_response_excerpt'] ?? '');
            $ktypeStatus = (string) get_post_meta($productId, '_gps_fitment_status', true);
            $ktypeConfidence = (string) get_post_meta($productId, '_gps_fitment_confidence', true);
            echo '<tr>';
            echo '<td>' . esc_html((string) $productId) . '</td>';
            echo '<td>' . esc_html((string) get_post_meta($productId, '_sku', true)) . '</td>';
            echo '<td>' . esc_html((string) get_the_title($productId)) . '</td>';
            echo '<td>' . esc_html((string) ($row['oem_value'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['oem_source'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['ktype_count'] ?? '0')) . '</td>';
            echo '<td>' . esc_html(trim($ktypeStatus . '/' . $ktypeConfidence, '/')) . '</td>';
            echo '<td>' . esc_html((string) ($row['marketplace'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['plugin_key'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['inventory_item_sku'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['offer_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['listing_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['ebay_category_id'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['listing_status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['fitment_status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['last_lookup_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['last_checked_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['last_error'] ?? '')) . '</td>';
            echo '<td><details><summary>Show technical details</summary><pre style="white-space:pre-wrap;max-width:420px;">' . esc_html($raw) . '</pre></details></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
