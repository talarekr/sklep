<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Admin;

use GPS_Ebay_Fitment_Sync\Database\Database;
use GPS_Ebay_Fitment_Sync\Service\AuditCsvExporter;
use GPS_Ebay_Fitment_Sync\Service\FitmentLookupService;
use GPS_Ebay_Fitment_Sync\Service\KTypeBackfillAutoRunner;
use GPS_Ebay_Fitment_Sync\Service\ProductScanner;
use GPS_Ebay_Fitment_Sync\Support\Settings;

final class AdminPage
{
    private Settings $settings;
    private FitmentLookupService $lookup;
    private ProductScanner $scanner;
    private Database $database;
    private AuditCsvExporter $auditCsvExporter;
    private KTypeBackfillAutoRunner $autoRunner;

    public function __construct(Settings $settings, FitmentLookupService $lookup, ProductScanner $scanner, Database $database, AuditCsvExporter $auditCsvExporter, KTypeBackfillAutoRunner $autoRunner)
    {
        $this->settings = $settings;
        $this->lookup = $lookup;
        $this->scanner = $scanner;
        $this->database = $database;
        $this->auditCsvExporter = $auditCsvExporter;
        $this->autoRunner = $autoRunner;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_gps_ebay_fitment_manual_lookup', [$this, 'manual_lookup']);
        add_action('admin_post_gps_ebay_fitment_cache_diagnostics', [$this, 'cache_diagnostics']);
        add_action('admin_post_gps_ebay_fitment_repair_schema', [$this, 'repair_schema']);
        add_action('admin_post_gps_ebay_fitment_scan', [$this, 'scan_products']);
        add_action('admin_post_gps_ebay_fitment_backfill', [$this, 'backfill']);
        add_action('wp_ajax_gps_ebay_fitment_ktype_backfill_batch', [$this, 'ajax_ktype_backfill_batch']);
        add_action('wp_ajax_gps_ebay_fitment_ktype_backfill_summary', [$this, 'ajax_ktype_backfill_summary']);
        add_action('wp_ajax_gps_ebay_fitment_ktype_backfill_stop', [$this, 'ajax_ktype_backfill_stop']);
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


    public function ajax_ktype_backfill_batch(): void
    {
        $this->ajax_guard('gps_ebay_fitment_ktype_backfill');
        $result = $this->autoRunner->run_batch([
            'run_id' => isset($_POST['run_id']) ? sanitize_text_field(wp_unslash($_POST['run_id'])) : '',
            'started_at' => isset($_POST['started_at']) ? sanitize_text_field(wp_unslash($_POST['started_at'])) : gmdate('c'),
            'offset' => isset($_POST['offset']) ? (int) $_POST['offset'] : 0,
            'start_offset' => isset($_POST['start_offset']) ? (int) $_POST['start_offset'] : 0,
            'batch_limit' => isset($_POST['batch_limit']) ? (int) $_POST['batch_limit'] : 10,
            'batch_number' => isset($_POST['batch_number']) ? (int) $_POST['batch_number'] : 1,
            'max_batches' => isset($_POST['max_batches']) ? (int) $_POST['max_batches'] : 0,
            'export_csv' => !empty($_POST['export_csv']),
            'persist_product_map' => !empty($_POST['persist_product_map']),
            'dry_run' => !empty($_POST['dry_run']),
            'resume' => !empty($_POST['resume']),
            'stop_on_first_error' => !empty($_POST['stop_on_first_error']),
            'confirmation' => isset($_POST['confirmation']) ? sanitize_text_field(wp_unslash($_POST['confirmation'])) : '',
        ]);

        wp_send_json_success($result);
    }

    public function ajax_ktype_backfill_summary(): void
    {
        $this->ajax_guard('gps_ebay_fitment_ktype_backfill');
        $summary = [];
        $allowed = [
            'run_id', 'started_at', 'finished_at', 'stopped_reason', 'start_offset', 'final_offset',
            'batch_limit', 'max_batches', 'total_batches', 'total_scanned_products',
            'products_with_raw_part_number', 'accepted_products', 'rejected_products', 'skipped_cached',
            'apify_lookup_attempted', 'found', 'not_found', 'errors', 'csv_files_count', 'batch_csv_urls', 'previous_run_id',
        ];
        foreach ($allowed as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = wp_unslash($_POST[$key]);
            if ($key === 'batch_csv_urls') {
                $decoded = json_decode((string) $value, true);
                $summary[$key] = is_array($decoded) ? array_map('esc_url_raw', $decoded) : [];
            } elseif (in_array($key, ['run_id', 'started_at', 'finished_at', 'stopped_reason', 'previous_run_id'], true)) {
                $summary[$key] = sanitize_text_field((string) $value);
            } else {
                $summary[$key] = (int) $value;
            }
        }

        wp_send_json_success($this->autoRunner->final_summary($summary));
    }

    public function ajax_ktype_backfill_stop(): void
    {
        $this->ajax_guard('gps_ebay_fitment_ktype_backfill');
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : 'manual_stop';
        wp_send_json_success($this->autoRunner->stop($reason));
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

            <?php $this->render_ktype_auto_runner(); ?>

            <?php $this->render_result($last); ?>
        </div>
        <?php
    }


    private function render_ktype_auto_runner(): void
    {
        $nonce = wp_create_nonce('gps_ebay_fitment_ktype_backfill');
        $checkpoint = $this->autoRunner->checkpoint();
        $checkpointJson = wp_json_encode($checkpoint);
        ?>
            <hr>
            <h2><?php echo esc_html__('KType Backfill Auto Runner', 'gps-ebay-fitment-sync'); ?></h2>
            <div id="gps-ktype-backfill-runner" class="gps-auto-runner" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-checkpoint="<?php echo esc_attr((string) $checkpointJson); ?>">
                <p><strong><?php echo esc_html__('Cost warning:', 'gps-ebay-fitment-sync'); ?></strong> <?php echo esc_html__('This can call paid Apify lookups for accepted not-cached part numbers.', 'gps-ebay-fitment-sync'); ?></p>
                <p><?php echo esc_html__('Browser-based automation follows the existing eBay auto-runner pattern, but progress is also checkpointed server-side after each completed batch so refreshes, timeouts, and manual stops can resume from the stored next offset.', 'gps-ebay-fitment-sync'); ?></p>
                <h3><?php echo esc_html__('Last server checkpoint', 'gps-ebay-fitment-sync'); ?></h3>
                <table class="widefat striped" style="max-width:980px;"><tbody>
                    <tr><th><?php echo esc_html__('Run ID', 'gps-ebay-fitment-sync'); ?></th><td><code data-ktype-checkpoint="run_id"><?php echo esc_html((string) ($checkpoint['run_id'] ?? '')); ?></code></td></tr>
                    <tr><th><?php echo esc_html__('Status', 'gps-ebay-fitment-sync'); ?></th><td data-ktype-checkpoint="status"><?php echo esc_html((string) ($checkpoint['status'] ?? '')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Last completed offset', 'gps-ebay-fitment-sync'); ?></th><td data-ktype-checkpoint="last_completed_offset"><?php echo esc_html(isset($checkpoint['last_completed_offset']) ? (string) $checkpoint['last_completed_offset'] : ''); ?></td></tr>
                    <tr><th><?php echo esc_html__('Next offset', 'gps-ebay-fitment-sync'); ?></th><td data-ktype-checkpoint="next_offset"><?php echo esc_html(isset($checkpoint['next_offset']) ? (string) $checkpoint['next_offset'] : ''); ?></td></tr>
                    <tr><th><?php echo esc_html__('Last error', 'gps-ebay-fitment-sync'); ?></th><td data-ktype-checkpoint="last_error"><?php echo esc_html((string) ($checkpoint['last_error'] ?? '')); ?></td></tr>
                    <tr><th><?php echo esc_html__('Final summary CSV', 'gps-ebay-fitment-sync'); ?></th><td data-ktype-checkpoint="final_summary_csv_url"><?php $summaryUrl = (string) ($checkpoint['final_summary_csv_url'] ?? ''); echo $summaryUrl !== '' ? '<a href="' . esc_url($summaryUrl) . '">' . esc_html($summaryUrl) . '</a>' : ''; ?></td></tr>
                </tbody></table>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:10px 0;">
                    <label><?php echo esc_html__('Start offset', 'gps-ebay-fitment-sync'); ?> <input id="gps-ktype-start-offset" type="number" min="0" value="0"></label>
                    <label><?php echo esc_html__('Batch limit', 'gps-ebay-fitment-sync'); ?> <input id="gps-ktype-batch-limit" type="number" min="1" max="50" value="10"></label>
                    <label><?php echo esc_html__('Max batches', 'gps-ebay-fitment-sync'); ?> <input id="gps-ktype-max-batches" type="number" min="0" value="0"></label>
                    <label><?php echo esc_html__('Delay between batches (ms)', 'gps-ebay-fitment-sync'); ?> <input id="gps-ktype-delay" type="number" min="0" max="600000" value="1000"></label>
                </div>
                <p>
                    <label><input id="gps-ktype-stop-on-error" type="checkbox" value="1"> <?php echo esc_html__('Stop on first error', 'gps-ebay-fitment-sync'); ?></label><br>
                    <label><input id="gps-ktype-export-csv" type="checkbox" value="1" checked> <?php echo esc_html__('Export CSV per batch', 'gps-ebay-fitment-sync'); ?></label><br>
                    <label><input id="gps-ktype-final-summary" type="checkbox" value="1" checked> <?php echo esc_html__('Generate final summary CSV', 'gps-ebay-fitment-sync'); ?></label><br>
                    <label><input id="gps-ktype-persist-map" type="checkbox" value="1" checked> <?php echo esc_html__('Persist product map rows', 'gps-ebay-fitment-sync'); ?></label><br>
                    <label><input id="gps-ktype-dry-run" type="checkbox" value="1"> <?php echo esc_html__('Dry-run / preview only mode (no Apify calls and no cache writes)', 'gps-ebay-fitment-sync'); ?></label>
                </p>
                <p><label><?php echo esc_html__('Confirmation', 'gps-ebay-fitment-sync'); ?> <input id="gps-ktype-confirmation" type="text" class="regular-text" placeholder="RUN KTYPE BACKFILL"></label> <code>RUN KTYPE BACKFILL</code></p>
                <p><button type="button" class="button button-primary" id="gps-ktype-start" disabled><?php echo esc_html__('Start new run', 'gps-ebay-fitment-sync'); ?></button> <button type="button" class="button" id="gps-ktype-resume" <?php disabled(empty($checkpoint)); ?>><?php echo esc_html__('Resume last run', 'gps-ebay-fitment-sync'); ?></button> <button type="button" class="button" id="gps-ktype-stop"><?php echo esc_html__('Stop', 'gps-ebay-fitment-sync'); ?></button></p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;max-width:980px;">
                    <?php foreach (['state','current_offset','last_completed_offset','next_offset','batch_number','total_batches','total_scanned_products','products_with_raw_part_number','accepted_products','rejected_products','skipped_cached','apify_lookup_attempted','found','not_found','errors','stopped_reason'] as $counter): ?>
                        <div style="border:1px solid #c3c4c7;background:#fff;padding:8px;"><span><?php echo esc_html($counter); ?></span><br><strong data-ktype-counter="<?php echo esc_attr($counter); ?>">0</strong></div>
                    <?php endforeach; ?>
                </div>
                <p><strong><?php echo esc_html__('Latest CSV', 'gps-ebay-fitment-sync'); ?></strong><br><span data-ktype-counter="latest_csv">-</span></p>
                <p><strong><?php echo esc_html__('Batch CSV links', 'gps-ebay-fitment-sync'); ?></strong></p>
                <ol data-ktype-counter="batch_csv_links"></ol>
                <p><strong><?php echo esc_html__('Final summary CSV', 'gps-ebay-fitment-sync'); ?></strong><br><span data-ktype-counter="final_summary_csv">-</span></p>
                <p><strong><?php echo esc_html__('Last batch counters', 'gps-ebay-fitment-sync'); ?></strong></p>
                <pre data-ktype-counter="last_batch_result" style="max-height:260px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;">-</pre>
            </div>
            <script>
            (function () {
                const runner = document.getElementById('gps-ktype-backfill-runner');
                if (!runner) { return; }
                const $ = function (id) { return document.getElementById(id); };
                const startButton = $('gps-ktype-start');
                const resumeButton = $('gps-ktype-resume');
                const stopButton = $('gps-ktype-stop');
                const confirmationInput = $('gps-ktype-confirmation');
                const dryRunInput = $('gps-ktype-dry-run');
                const fields = {};
                const checkpointFields = {};
                runner.querySelectorAll('[data-ktype-counter]').forEach(function (node) { fields[node.getAttribute('data-ktype-counter')] = node; });
                runner.querySelectorAll('[data-ktype-checkpoint]').forEach(function (node) { checkpointFields[node.getAttribute('data-ktype-checkpoint')] = node; });
                let serverCheckpoint = {};
                try { serverCheckpoint = JSON.parse(runner.dataset.checkpoint || '{}') || {}; } catch (e) { serverCheckpoint = {}; }
                const state = { running:false, stopped:false, inFlight:false, delayTimer:0, abortController:null, resume:false, run_id:'', started_at:'', start_offset:0, current_offset:0, last_completed_offset:-1, next_offset:0, batch_limit:10, max_batches:0, total_batches:0, total_scanned_products:0, products_with_raw_part_number:0, accepted_products:0, rejected_products:0, skipped_cached:0, apify_lookup_attempted:0, found:0, not_found:0, errors:0, csvUrls:[], stopped_reason:'-' };
                function numberInput(id, fallback, min, max) { const value = parseInt(($(id) || {}).value || String(fallback), 10); return Number.isFinite(value) ? Math.max(min, Math.min(max, value)) : fallback; }
                function setField(name, value) { if (fields[name]) { fields[name].textContent = String(value); } }
                function safeText(value) { return String(value || '').replace(/</g, '&lt;'); }
                function link(url) { return url ? '<a href="' + String(url).replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer">' + safeText(url) + '</a>' : '-'; }
                function applyCheckpoint(checkpoint) {
                    if (!checkpoint || !checkpoint.run_id) { return; }
                    serverCheckpoint = checkpoint;
                    const aggregate = checkpoint.aggregate_counters || {};
                    state.run_id = checkpoint.run_id || state.run_id;
                    state.started_at = checkpoint.started_at || state.started_at;
                    state.start_offset = parseInt(checkpoint.start_offset || 0, 10) || 0;
                    state.current_offset = parseInt(checkpoint.next_offset || checkpoint.current_offset || 0, 10) || 0;
                    state.next_offset = parseInt(checkpoint.next_offset || 0, 10) || 0;
                    state.last_completed_offset = parseInt(checkpoint.last_completed_offset || -1, 10);
                    state.batch_limit = parseInt(checkpoint.batch_limit || state.batch_limit, 10) || state.batch_limit;
                    state.max_batches = parseInt(checkpoint.max_batches || 0, 10) || 0;
                    state.total_batches = parseInt(checkpoint.total_batches_completed || 0, 10) || 0;
                    ['total_scanned_products','products_with_raw_part_number','accepted_products','rejected_products','skipped_cached','apify_lookup_attempted','found','not_found','errors'].forEach(function (name) { state[name] = parseInt(aggregate[name] || 0, 10) || 0; });
                    state.csvUrls = Array.isArray(checkpoint.batch_csv_urls) ? checkpoint.batch_csv_urls.slice() : [];
                    state.stopped_reason = checkpoint.status || '-';
                    if (checkpointFields.run_id) { checkpointFields.run_id.textContent = checkpoint.run_id || ''; }
                    ['status','last_completed_offset','next_offset','last_error'].forEach(function (name) { if (checkpointFields[name]) { checkpointFields[name].textContent = checkpoint[name] || ''; } });
                    if (checkpointFields.final_summary_csv_url) { checkpointFields.final_summary_csv_url.innerHTML = link(checkpoint.final_summary_csv_url || ''); }
                }
                function refresh(lastBatch) {
                    ['current_offset','last_completed_offset','next_offset','batch_number','total_batches','total_scanned_products','products_with_raw_part_number','accepted_products','rejected_products','skipped_cached','apify_lookup_attempted','found','not_found','errors','stopped_reason'].forEach(function (name) { setField(name, state[name] ?? 0); });
                    setField('state', state.running ? 'running' : (state.stopped ? 'stopped' : 'idle'));
                    if (fields.last_batch_result) { fields.last_batch_result.textContent = lastBatch ? JSON.stringify(lastBatch, null, 2) : '-'; }
                    if (fields.latest_csv) { fields.latest_csv.innerHTML = link(state.csvUrls[state.csvUrls.length - 1] || ''); }
                    if (fields.batch_csv_links) { fields.batch_csv_links.innerHTML = state.csvUrls.map(function (url) { return '<li>' + link(url) + '</li>'; }).join(''); }
                    startButton.disabled = state.running || (!dryRunInput.checked && confirmationInput.value !== 'RUN KTYPE BACKFILL');
                    resumeButton.disabled = state.running || !serverCheckpoint.run_id || (!dryRunInput.checked && confirmationInput.value !== 'RUN KTYPE BACKFILL');
                }
                function wait(ms) { return new Promise(function (resolve) { state.delayTimer = window.setTimeout(function () { state.delayTimer = 0; resolve(); }, ms); }); }
                async function post(action, data) {
                    const formData = new FormData();
                    formData.append('action', action);
                    formData.append('_ajax_nonce', runner.dataset.nonce || '');
                    Object.keys(data).forEach(function (key) { formData.append(key, data[key]); });
                    state.abortController = new AbortController();
                    state.inFlight = true;
                    try {
                        const response = await fetch(runner.dataset.ajaxUrl, { method:'POST', credentials:'same-origin', body:formData, signal:state.abortController.signal });
                        if (!response.ok) { throw new Error('request_failed_http_' + response.status); }
                        const result = await response.json();
                        if (result && result.success && result.data) { return result.data; }
                        throw new Error((result && result.data && result.data.error) || 'request_failed');
                    } finally { state.inFlight = false; state.abortController = null; }
                }
                async function stop(reason) {
                    state.stopped = true; state.running = false; state.stopped_reason = reason || 'manual_stop';
                    if (state.delayTimer) { window.clearTimeout(state.delayTimer); state.delayTimer = 0; }
                    if (state.abortController) { state.abortController.abort(); state.abortController = null; }
                    refresh();
                    try { const checkpoint = await post('gps_ebay_fitment_ktype_backfill_stop', { reason: state.stopped_reason }); applyCheckpoint(checkpoint); refresh(); } catch (e) {}
                }
                async function writeSummary(reason) {
                    if (!$('gps-ktype-final-summary').checked) { return; }
                    const result = await post('gps_ebay_fitment_ktype_backfill_summary', { run_id:state.run_id, finished_at:(new Date()).toISOString(), stopped_reason:reason || state.stopped_reason });
                    if (fields.final_summary_csv) { fields.final_summary_csv.innerHTML = link(result.summary_csv_url || ''); }
                    if (serverCheckpoint.run_id) { serverCheckpoint.final_summary_csv_url = result.summary_csv_url || ''; applyCheckpoint(serverCheckpoint); }
                }
                async function start(resume) {
                    if (state.running || state.inFlight) { return; }
                    state.running = true; state.stopped = false; state.resume = !!resume;
                    if (resume && serverCheckpoint.run_id) {
                        applyCheckpoint(serverCheckpoint);
                    } else {
                        state.started_at = (new Date()).toISOString(); state.run_id = 'ktype-backfill-' + Date.now().toString(36); state.start_offset = numberInput('gps-ktype-start-offset', 0, 0, 999999999); state.current_offset = state.start_offset; state.next_offset = state.start_offset; state.last_completed_offset = -1; state.batch_limit = numberInput('gps-ktype-batch-limit', 10, 1, 50); state.max_batches = numberInput('gps-ktype-max-batches', 0, 0, 999999); state.total_batches = 0; state.total_scanned_products = 0; state.products_with_raw_part_number = 0; state.accepted_products = 0; state.rejected_products = 0; state.skipped_cached = 0; state.apify_lookup_attempted = 0; state.found = 0; state.not_found = 0; state.errors = 0; state.csvUrls = []; state.stopped_reason = '-';
                    }
                    refresh();
                    try {
                        while (state.running && !state.stopped) {
                            const batchNumber = state.total_batches + 1;
                            const result = await post('gps_ebay_fitment_ktype_backfill_batch', { run_id:state.run_id, started_at:state.started_at, offset:state.current_offset, start_offset:state.start_offset, batch_limit:state.batch_limit, batch_number:batchNumber, max_batches:state.max_batches, export_csv:$('gps-ktype-export-csv').checked ? '1' : '', persist_product_map:$('gps-ktype-persist-map').checked ? '1' : '', dry_run:dryRunInput.checked ? '1' : '', resume:state.resume ? '1' : '', stop_on_first_error:$('gps-ktype-stop-on-error').checked ? '1' : '', confirmation:confirmationInput.value });
                            state.resume = false;
                            if (result.success === false) { throw new Error(result.error || result.stopped_reason || 'batch_failed'); }
                            if (result.checkpoint) { applyCheckpoint(result.checkpoint); } else {
                                const counters = result.counters || {};
                                state.total_batches += 1; state.current_offset = result.next_offset || (state.current_offset + state.batch_limit); state.next_offset = state.current_offset; state.last_completed_offset = result.last_completed_offset || result.offset || state.last_completed_offset;
                                ['total_scanned_products','products_with_raw_part_number','accepted_products','rejected_products','skipped_cached','apify_lookup_attempted','found','not_found','errors'].forEach(function (name) { state[name] += parseInt(counters[name] || 0, 10); });
                                if (result.csv_url) { state.csvUrls.push(result.csv_url); }
                            }
                            refresh(result);
                            if (result.done) { state.stopped_reason = result.stopped_reason || 'completed'; break; }
                            await wait(numberInput('gps-ktype-delay', 1000, 0, 600000));
                        }
                    } catch (error) {
                        if (!(state.stopped && error.name === 'AbortError')) { state.stopped_reason = error && error.message ? error.message : 'fatal_error'; }
                    }
                    state.running = false; state.stopped = true; refresh();
                    try { await writeSummary(state.stopped_reason); } catch (error) { if (fields.final_summary_csv) { fields.final_summary_csv.textContent = 'summary_failed: ' + (error && error.message ? error.message : error); } }
                }
                applyCheckpoint(serverCheckpoint);
                confirmationInput.addEventListener('input', function () { refresh(); });
                dryRunInput.addEventListener('change', function () { refresh(); });
                startButton.addEventListener('click', function () { start(false); });
                resumeButton.addEventListener('click', function () { start(true); });
                stopButton.addEventListener('click', function () { stop('manual_stop'); });
                window.addEventListener('beforeunload', function () { if (state.running) { stop('page_unload'); } });
                refresh();
            }());
            </script>
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

    private function ajax_guard(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        check_ajax_referer($nonce);
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
