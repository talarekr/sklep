<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Admin;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Service\AuditCsvExporter;
use GPS_Ebay_Fitment_Sync\Service\FitmentLookupService;
use GPS_Ebay_Fitment_Sync\Service\ProductScanner;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class AdminPage
{
    private Settings $settings;
    private FitmentLookupService $lookup;
    private ProductScanner $scanner;
    private Database $database;
    private AuditCsvExporter $auditCsvExporter;

    public function __construct(Settings $settings, FitmentLookupService $lookup, ProductScanner $scanner, Database $database, AuditCsvExporter $auditCsvExporter)
    {
        $this->settings = $settings;
        $this->lookup = $lookup;
        $this->scanner = $scanner;
        $this->database = $database;
        $this->auditCsvExporter = $auditCsvExporter;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_gps_ebay_fitment_manual_lookup', [$this, 'manual_lookup']);
        add_action('admin_post_gps_ebay_fitment_cache_diagnostics', [$this, 'cache_diagnostics']);
        add_action('admin_post_gps_ebay_fitment_repair_schema', [$this, 'repair_schema']);
        add_action('admin_post_gps_ebay_fitment_scan', [$this, 'scan_products']);
        add_action('admin_post_gps_ebay_fitment_backfill', [$this, 'backfill']);
    }

    public function admin_menu(): void
    {
        add_submenu_page('woocommerce', __('GPS eBay Fitment', 'gps-ebay-fitment-sync'), __('GPS eBay Fitment', 'gps-ebay-fitment-sync'), 'manage_woocommerce', 'gps-ebay-fitment-sync', [$this, 'render']);
    }

    public function manual_lookup(): void
    {
        $this->guard('gps_ebay_fitment_manual_lookup');
        $partNumber = isset($_POST['part_number']) ? sanitize_text_field(wp_unslash($_POST['part_number'])) : '';
        $save = isset($_POST['lookup_save']);
        $forceLive = isset($_POST['force_live']);
        $result = $this->lookup->lookup($partNumber, $save, $forceLive);
        $result['cache_diagnostics'] = $this->database->cache_diagnostics($partNumber);
        $this->store_result(['type' => 'manual_lookup', 'result' => $result]);
    }

    public function cache_diagnostics(): void
    {
        $this->guard('gps_ebay_fitment_cache_diagnostics');
        $partNumber = isset($_POST['part_number']) ? sanitize_text_field(wp_unslash($_POST['part_number'])) : '';
        $this->store_result(['type' => 'cache_diagnostics', 'result' => $this->database->cache_diagnostics($partNumber)]);
    }

    public function repair_schema(): void
    {
        $this->guard('gps_ebay_fitment_repair_schema');
        $this->store_result(['type' => 'schema_repair', 'result' => Database::repair_schema()]);
    }

    public function scan_products(): void
    {
        $this->guard('gps_ebay_fitment_scan');
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 100;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $persist = isset($_POST['persist_map']);
        $exportCsv = isset($_POST['export_csv']);
        $result = $this->scanner->scan($limit, $offset, $persist);
        if ($exportCsv) {
            $result = array_merge($result, $this->auditCsvExporter->export_scan_preview($result, $offset, $limit));
        } else {
            $result = array_merge($result, $this->empty_csv_result());
        }
        $this->store_result(['type' => 'scan', 'result' => $result]);
    }

    public function backfill(): void
    {
        $this->guard('gps_ebay_fitment_backfill');
        $limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 100;
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
        $scan = $this->scanner->scan($limit, $offset, true);
        $result = $this->lookup->backfill(array_values($scan['unique_part_numbers']));
        $result = array_merge($result, $this->auditCsvExporter->export_backfill($scan, $result, $offset, $limit));
        $this->store_result(['type' => 'backfill', 'scan' => $scan, 'result' => $result]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gps-ebay-fitment-sync'));
        }

        $settings = $this->settings->all();
        $last = get_transient($this->transient_key());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('GPS eBay Fitment Sync', 'gps-ebay-fitment-sync'); ?></h1>
            <p><?php echo esc_html__('MVP for TecDoc/Apify vehicle compatibility lookup and canonical local KType cache. This plugin does not write to eBay.', 'gps-ebay-fitment-sync'); ?></p>

            <h2><?php echo esc_html__('Settings', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('gps_ebay_fitment_sync'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="gps-apify-token"><?php echo esc_html__('Apify token', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-apify-token" name="<?php echo esc_attr(Settings::OPTION); ?>[apify_token]" type="password" class="regular-text" value="<?php echo esc_attr($settings['apify_token'] ? '********' : ''); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gps-actor-id"><?php echo esc_html__('Apify actor ID', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-actor-id" name="<?php echo esc_attr(Settings::OPTION); ?>[actor_id]" type="text" class="regular-text" value="<?php echo esc_attr($settings['actor_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gps-lang-id"><?php echo esc_html__('TecDoc language ID', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-lang-id" name="<?php echo esc_attr(Settings::OPTION); ?>[lang_id]" type="number" value="<?php echo esc_attr((string) $settings['lang_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gps-country-filter-id"><?php echo esc_html__('TecDoc country filter ID', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-country-filter-id" name="<?php echo esc_attr(Settings::OPTION); ?>[country_filter_id]" type="number" value="<?php echo esc_attr((string) $settings['country_filter_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gps-timeout"><?php echo esc_html__('Request timeout (seconds)', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-timeout" name="<?php echo esc_attr(Settings::OPTION); ?>[timeout]" type="number" min="5" max="300" value="<?php echo esc_attr((string) $settings['timeout']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="gps-batch-size"><?php echo esc_html__('Backfill batch size', 'gps-ebay-fitment-sync'); ?></label></th>
                        <td><input id="gps-batch-size" name="<?php echo esc_attr(Settings::OPTION); ?>[batch_size]" type="number" min="1" max="50" value="<?php echo esc_attr((string) $settings['batch_size']); ?>"></td>
                    </tr>
                </table>
                <?php submit_button(__('Save settings', 'gps-ebay-fitment-sync')); ?>
            </form>

            <hr>
            <h2><?php echo esc_html__('Manual lookup', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gps_ebay_fitment_manual_lookup">
                <?php wp_nonce_field('gps_ebay_fitment_manual_lookup'); ?>
                <p><label><?php echo esc_html__('Part number / OEM', 'gps-ebay-fitment-sync'); ?> <input name="part_number" type="text" class="regular-text" required></label></p>
                <p>
                    <button class="button" type="submit" name="lookup_dry_run" value="1"><?php echo esc_html__('Dry-run lookup without saving', 'gps-ebay-fitment-sync'); ?></button>
                    <button class="button button-primary" type="submit" name="lookup_save" value="1"><?php echo esc_html__('Lookup and save to cache', 'gps-ebay-fitment-sync'); ?></button>
                    <label><input type="checkbox" name="force_live" value="1"> <?php echo esc_html__('Force live Apify lookup', 'gps-ebay-fitment-sync'); ?></label>
                </p>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gps_ebay_fitment_cache_diagnostics">
                <?php wp_nonce_field('gps_ebay_fitment_cache_diagnostics'); ?>
                <p>
                    <label><?php echo esc_html__('Part number / OEM', 'gps-ebay-fitment-sync'); ?> <input name="part_number" type="text" class="regular-text" required></label>
                    <button class="button" type="submit"><?php echo esc_html__('Check cache only (no Apify)', 'gps-ebay-fitment-sync'); ?></button>
                </p>
            </form>

            <hr>
            <h2><?php echo esc_html__('Cache table repair', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gps_ebay_fitment_repair_schema">
                <?php wp_nonce_field('gps_ebay_fitment_repair_schema'); ?>
                <p><?php echo esc_html__('Safely re-run dbDelta to create missing cache tables, add missing columns, and add indexes without dropping cached data.', 'gps-ebay-fitment-sync'); ?></p>
                <p><button class="button" type="submit"><?php echo esc_html__('Repair/check cache tables', 'gps-ebay-fitment-sync'); ?></button></p>
            </form>

            <hr>
            <h2><?php echo esc_html__('Scan Woo products for unique part numbers', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gps_ebay_fitment_scan">
                <?php wp_nonce_field('gps_ebay_fitment_scan'); ?>
                <p>
                    <label><?php echo esc_html__('Limit', 'gps-ebay-fitment-sync'); ?> <input name="limit" type="number" value="100" min="1" max="500"></label>
                    <label><?php echo esc_html__('Offset', 'gps-ebay-fitment-sync'); ?> <input name="offset" type="number" value="0" min="0"></label>
                    <label><input type="checkbox" name="persist_map" value="1"> <?php echo esc_html__('Persist product map rows (no Apify calls)', 'gps-ebay-fitment-sync'); ?></label>
                    <label><input type="checkbox" name="export_csv" value="1"> <?php echo esc_html__('Export scan preview CSV (no Apify calls)', 'gps-ebay-fitment-sync'); ?></label>
                </p>
                <p><button class="button button-primary" type="submit"><?php echo esc_html__('Preview scan', 'gps-ebay-fitment-sync'); ?></button></p>
            </form>

            <h2><?php echo esc_html__('Small backfill', 'gps-ebay-fitment-sync'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="gps_ebay_fitment_backfill">
                <?php wp_nonce_field('gps_ebay_fitment_backfill'); ?>
                <p><?php echo esc_html__('Uses cache first and only looks up uncached part numbers from this scan batch. No eBay updates are performed.', 'gps-ebay-fitment-sync'); ?></p>
                <p>
                    <label><?php echo esc_html__('Scan limit', 'gps-ebay-fitment-sync'); ?> <input name="limit" type="number" value="100" min="1" max="500"></label>
                    <label><?php echo esc_html__('Offset', 'gps-ebay-fitment-sync'); ?> <input name="offset" type="number" value="0" min="0"></label>
                </p>
                <p><button class="button" type="submit"><?php echo esc_html__('Run small backfill', 'gps-ebay-fitment-sync'); ?></button></p>
            </form>

            <?php $this->render_result($last); ?>
        </div>
        <?php
    }

    private function render_result($last): void
    {
        if (!is_array($last)) {
            return;
        }

        echo '<hr><h2>' . esc_html__('Last result', 'gps-ebay-fitment-sync') . '</h2>';
        if (($last['type'] ?? '') === 'manual_lookup') {
            $result = $last['result'];
            echo '<table class="widefat striped"><tbody>';
            $this->row('Normalized part number', $result['part_number_normalized'] ?? '');
            $this->row('Status', $result['status'] ?? '');
            $this->row('Came from cache', !empty($result['from_cache']) ? 'yes' : 'no');
            $this->row('Saved', !empty($result['saved']) ? 'yes' : 'no');
            $this->row('Cache lookup key', (string) ($result['cache_lookup_key'] ?? ''));
            $this->row('Cache part cache ID', isset($result['cache_part_cache_id']) && $result['cache_part_cache_id'] !== null ? (string) $result['cache_part_cache_id'] : '');
            $this->row('Cache hit', !empty($result['cache_hit']) ? 'true' : 'false');
            $this->row('Force live', !empty($result['force_live']) ? 'true' : 'false');
            if (!empty($result['cache_diagnostics']) && is_array($result['cache_diagnostics'])) {
                $diagnostics = $result['cache_diagnostics'];
                $this->row('Diagnostic table name', (string) ($diagnostics['table_name'] ?? ''));
                $this->row('Diagnostic table exists', !empty($diagnostics['table_exists']) ? 'true' : 'false');
                $this->row('Diagnostic schema OK', !empty($diagnostics['schema_ok']) ? 'true' : 'false');
                $this->row('Diagnostic row exists', !empty($diagnostics['row_exists']) ? 'true' : 'false');
                $this->row('Diagnostic row ID', isset($diagnostics['row_id']) && $diagnostics['row_id'] !== null ? (string) $diagnostics['row_id'] : '');
                $this->row('Diagnostic row status', (string) ($diagnostics['row_status'] ?? ''));
                $this->row('Diagnostic row article count', isset($diagnostics['row_article_count']) && $diagnostics['row_article_count'] !== null ? (string) $diagnostics['row_article_count'] : '');
                $this->row('Diagnostic row vehicle count', isset($diagnostics['row_vehicle_count']) && $diagnostics['row_vehicle_count'] !== null ? (string) $diagnostics['row_vehicle_count'] : '');
                $this->row('Diagnostic last DB error', (string) ($diagnostics['last_db_error'] ?? ''));
            }
            if (!empty($result['save_debug']) && is_array($result['save_debug'])) {
                $this->row('Save debug', wp_json_encode($result['save_debug'], JSON_PRETTY_PRINT));
            }
            $this->row('Step 1 articles found', (string) count($result['articles'] ?? []));
            $this->row('Step 2 compatible vehicle count', (string) count($result['unique_vehicle_ids'] ?? []));
            $this->row('Unique KTypes / vehicleIds', implode(', ', array_slice($result['unique_vehicle_ids'] ?? [], 0, 100)));
            $this->row('Errors', implode("\n", $result['errors'] ?? []));
            echo '</tbody></table>';
            return;
        }

        if (isset($last['result']) && is_array($last['result'])) {
            $this->render_csv_summary($last['result']);
        }

        echo '<pre style="max-height: 420px; overflow:auto; background:#fff; padding:12px; border:1px solid #ccd0d4;">' . esc_html(wp_json_encode($last, JSON_PRETTY_PRINT)) . '</pre>';
    }

    private function render_csv_summary(array $result): void
    {
        echo '<h3>' . esc_html__('Audit CSV', 'gps-ebay-fitment-sync') . '</h3>';
        echo '<table class="widefat striped"><tbody>';
        $this->row('CSV generated', !empty($result['csv_generated']) ? 'yes' : 'no');
        $this->row('CSV file path', (string) ($result['csv_path'] ?? ''));
        $url = (string) ($result['csv_url'] ?? '');
        if ($url !== '') {
            echo '<tr><th scope="row">' . esc_html__('CSV download link', 'gps-ebay-fitment-sync') . '</th><td><a href="' . esc_url($url) . '">' . esc_html($url) . '</a></td></tr>';
        } else {
            $this->row('CSV download link', '');
        }
        $this->row('CSV row count', isset($result['csv_row_count']) ? (string) $result['csv_row_count'] : '');
        $this->row('Run ID', (string) ($result['run_id'] ?? ''));
        if (!empty($result['csv_error'])) {
            $this->row('CSV error', (string) $result['csv_error']);
        }
        echo '</tbody></table>';
    }

    private function empty_csv_result(): array
    {
        return [
            'csv_generated' => false,
            'csv_path' => '',
            'csv_url' => '',
            'csv_row_count' => 0,
            'run_id' => '',
            'csv_error' => '',
        ];
    }

    private function row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><pre style="white-space:pre-wrap;margin:0;">' . esc_html($value) . '</pre></td></tr>';
    }

    private function guard(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'gps-ebay-fitment-sync'));
        }
        check_admin_referer($nonce);
    }

    private function store_result(array $result): void
    {
        set_transient($this->transient_key(), $result, 30 * MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('admin.php?page=gps-ebay-fitment-sync&gps_fitment_result=1'));
        exit;
    }

    private function transient_key(): string
    {
        return 'gps_ebay_fitment_sync_last_' . get_current_user_id();
    }
}
