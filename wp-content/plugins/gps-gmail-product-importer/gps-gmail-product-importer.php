<?php
/**
 * Plugin Name: GPS Gmail Product Importer
 * Description: Stages Gmail messages from a selected label into an import queue before creating safe WooCommerce draft product candidates.
 * Version: 0.1.0
 * Author: GPS
 * Requires PHP: 7.4
 * Text Domain: gps-gmail-product-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class GPS_Gmail_Product_Importer
{
    const OPTION_SETTINGS = 'gps_gmail_product_importer_settings';
    const OPTION_TOKENS = 'gps_gmail_product_importer_tokens';
    const OPTION_CONNECTED_EMAIL = 'gps_gmail_product_importer_connected_email';
    const OPTION_RUN_STATE = 'gps_gmail_product_importer_run_state';
    const NONCE_ACTION = 'gps_gmail_product_importer_action';
    const UPLOAD_DIR = 'gps-gmail-product-importer';
    const LOCK_KEY = 'gps_gmail_product_importer_batch_lock';
    const STAGING_POST_TYPE = 'gps_gmail_stage';
    private const OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES = array('official_code_prediction', 'panel_network_capture', 'api_candidate_code_lookup', 'public_ovoko_code_page', 'woo_term_exact_ovoko_id');


    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_staging_post_type'));
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'maybe_handle_oauth_callback'));
        add_action('admin_post_gps_gmail_product_importer_disconnect', array($this, 'handle_disconnect'));
        add_action('admin_post_gps_gmail_product_importer_test', array($this, 'handle_test'));
        add_action('admin_post_gps_gmail_product_importer_dry_run', array($this, 'handle_dry_run'));
        add_action('admin_post_gps_gmail_product_importer_import', array($this, 'handle_import'));
        add_action('admin_post_gps_gmail_product_importer_create_woo_drafts', array($this, 'handle_create_woo_drafts'));
        add_action('admin_post_gps_gmail_product_importer_full_preparation_batch', array($this, 'handle_full_preparation_batch'));
        add_action('admin_post_gps_gmail_product_importer_download_audit_csv', array($this, 'handle_download_audit_csv'));
        add_action('admin_post_gps_gmail_product_importer_queue_item_action', array($this, 'handle_import_queue_item_action'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_test', array($this, 'handle_ovoko_test'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_category_prediction_test', array($this, 'handle_ovoko_category_prediction_test'));
        add_action('admin_post_gps_gmail_product_importer_allegro_test', array($this, 'handle_allegro_test'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_enrichment_dry_run', array($this, 'handle_ovoko_enrichment_dry_run'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_enrichment_save', array($this, 'handle_ovoko_enrichment_save'));
        add_action('wp_ajax_gps_gmail_product_importer_import_batch', array($this, 'ajax_import_batch'));
        add_action('wp_ajax_gps_gmail_product_importer_auto_runner_batch', array($this, 'ajax_auto_runner_batch'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
    }

    public static function activate()
    {
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }
        self::ensure_report_directory();
    }

    public function register_staging_post_type()
    {
        register_post_type(self::STAGING_POST_TYPE, array(
            'labels' => array(
                'name' => __('Gmail Import Queue', 'gps-gmail-product-importer'),
                'singular_name' => __('Gmail Import Queue Item', 'gps-gmail-product-importer'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title', 'editor', 'excerpt'),
            'capability_type' => 'post',
        ));
    }

    public static function default_settings()
    {
        return array(
            'gmail_label' => 'Woo import',
            'message_status_filter' => 'read',
            'ovoko_enrichment_enabled' => 0,
            'ovoko_enrichment_overwrite_existing_attributes' => 0,
            'ovoko_enrichment_auto_assign_category' => 0,
            'ovoko_enrichment_batch_size' => 5,
            'ovoko_enrichment_dry_run' => 1,
            'ovoko_enrichment_save_suggestions' => 1,
            'ovoko_enrichment_delay_between_batches' => 3,
            'ovoko_price_fallback_enabled' => 1,
            'ovoko_eur_to_pln_fallback_rate' => '',
            'ovoko_prefer_internal_notes_price' => 1,
            'batch_size' => 5,
            'product_status' => 'draft',
            'import_images' => 1,
            'duplicate_protection' => 1,
            'auto_assign_high_confidence_category' => 0,
            'google_client_id' => '',
            'google_client_secret' => '',
            'delay_between_batches' => 3,
            'allegro_api_enabled' => 0,
            'allegro_client_id' => '',
            'allegro_client_secret' => '',
            'allegro_environment' => 'production',
            'allegro_access_token' => '',
            'allegro_refresh_token' => '',
            'allegro_token_expires_at' => '',
            'allegro_marketplace_country' => 'PL',
            'allegro_search_limit' => 20,
            'allegro_min_filtered_offer_count' => 5,
            'allegro_price_statistic_method' => 'median',
            'allegro_readiness_min_confidence' => 'medium',
            'fixed_ovoko_import_category_enabled' => 0,
            'fixed_ovoko_import_category_id' => '',
            'fixed_ovoko_import_category_name' => '',
            'fixed_ovoko_import_category_review_warning_enabled' => 1,
            'allow_empty_price_for_fixed_category_crm_only_import' => 1,
            'mark_gmail_read_after_staging' => 1,
        );
    }

    private function settings()
    {
        return wp_parse_args((array) get_option(self::OPTION_SETTINGS, array()), self::default_settings());
    }

    public function register_menu()
    {
        add_menu_page(
            __('GPS Gmail Importer', 'gps-gmail-product-importer'),
            __('GPS Gmail Importer', 'gps-gmail-product-importer'),
            'manage_options',
            'gps-gmail-product-importer',
            array($this, 'render_admin_page'),
            'dashicons-email-alt2',
            56
        );
    }

    public function register_settings()
    {
        register_setting('gps_gmail_product_importer_settings', self::OPTION_SETTINGS, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $input = (array) $input;
        $status = isset($input['product_status']) && $input['product_status'] === 'pending_review' ? 'pending_review' : 'draft';
        return array(
            'gmail_label' => sanitize_text_field($input['gmail_label'] ?? 'Woo import'),
            'message_status_filter' => $this->sanitize_message_status_filter($input['message_status_filter'] ?? 'read'),
            'ovoko_enrichment_enabled' => empty($input['ovoko_enrichment_enabled']) ? 0 : 1,
            'ovoko_enrichment_overwrite_existing_attributes' => empty($input['ovoko_enrichment_overwrite_existing_attributes']) ? 0 : 1,
            'ovoko_enrichment_auto_assign_category' => empty($input['ovoko_enrichment_auto_assign_category']) ? 0 : 1,
            'ovoko_enrichment_batch_size' => max(1, min(25, absint($input['ovoko_enrichment_batch_size'] ?? 5))),
            'ovoko_enrichment_dry_run' => empty($input['ovoko_enrichment_dry_run']) ? 0 : 1,
            'ovoko_enrichment_save_suggestions' => empty($input['ovoko_enrichment_save_suggestions']) ? 0 : 1,
            'ovoko_enrichment_delay_between_batches' => max(1, min(60, absint($input['ovoko_enrichment_delay_between_batches'] ?? 3))),
            'ovoko_price_fallback_enabled' => empty($input['ovoko_price_fallback_enabled']) ? 0 : 1,
            'ovoko_eur_to_pln_fallback_rate' => isset($input['ovoko_eur_to_pln_fallback_rate']) && is_numeric(str_replace(',', '.', (string) $input['ovoko_eur_to_pln_fallback_rate'])) && (float) str_replace(',', '.', (string) $input['ovoko_eur_to_pln_fallback_rate']) > 0 ? sanitize_text_field(str_replace(',', '.', (string) $input['ovoko_eur_to_pln_fallback_rate'])) : '',
            'ovoko_prefer_internal_notes_price' => empty($input['ovoko_prefer_internal_notes_price']) ? 0 : 1,
            'batch_size' => max(1, min(25, absint($input['batch_size'] ?? 5))),
            'product_status' => $status,
            'import_images' => empty($input['import_images']) ? 0 : 1,
            'duplicate_protection' => empty($input['duplicate_protection']) ? 0 : 1,
            'auto_assign_high_confidence_category' => empty($input['auto_assign_high_confidence_category']) ? 0 : 1,
            'google_client_id' => sanitize_text_field($input['google_client_id'] ?? ''),
            'google_client_secret' => sanitize_text_field($input['google_client_secret'] ?? ''),
            'delay_between_batches' => max(1, min(60, absint($input['delay_between_batches'] ?? 3))),
            'allegro_api_enabled' => empty($input['allegro_api_enabled']) ? 0 : 1,
            'allegro_client_id' => sanitize_text_field($input['allegro_client_id'] ?? ''),
            'allegro_client_secret' => sanitize_text_field($input['allegro_client_secret'] ?? ''),
            'allegro_environment' => in_array(($input['allegro_environment'] ?? 'production'), array('production', 'sandbox'), true) ? sanitize_text_field($input['allegro_environment']) : 'production',
            'allegro_access_token' => sanitize_text_field($input['allegro_access_token'] ?? ''),
            'allegro_refresh_token' => sanitize_text_field($input['allegro_refresh_token'] ?? ''),
            'allegro_token_expires_at' => sanitize_text_field($input['allegro_token_expires_at'] ?? ''),
            'allegro_marketplace_country' => $this->sanitize_allegro_country($input['allegro_marketplace_country'] ?? 'PL'),
            'allegro_search_limit' => max(1, min(60, absint($input['allegro_search_limit'] ?? 20))),
            'allegro_min_filtered_offer_count' => max(1, min(25, absint($input['allegro_min_filtered_offer_count'] ?? 5))),
            'allegro_price_statistic_method' => in_array(($input['allegro_price_statistic_method'] ?? 'median'), array('median', 'min', 'max'), true) ? sanitize_text_field($input['allegro_price_statistic_method']) : 'median',
            'allegro_readiness_min_confidence' => in_array(($input['allegro_readiness_min_confidence'] ?? 'medium'), array('high', 'medium', 'low'), true) ? sanitize_text_field($input['allegro_readiness_min_confidence']) : 'medium',
            'fixed_ovoko_import_category_enabled' => empty($input['fixed_ovoko_import_category_enabled']) ? 0 : 1,
            'fixed_ovoko_import_category_id' => absint($input['fixed_ovoko_import_category_id'] ?? 0) > 0 ? (string) absint($input['fixed_ovoko_import_category_id']) : '',
            'fixed_ovoko_import_category_name' => sanitize_text_field($input['fixed_ovoko_import_category_name'] ?? ''),
            'fixed_ovoko_import_category_review_warning_enabled' => empty($input['fixed_ovoko_import_category_review_warning_enabled']) ? 0 : 1,
            'allow_empty_price_for_fixed_category_crm_only_import' => empty($input['allow_empty_price_for_fixed_category_crm_only_import']) ? 0 : 1,
            'mark_gmail_read_after_staging' => empty($input['mark_gmail_read_after_staging']) ? 0 : 1,
        );
    }

    private function sanitize_allegro_country($value)
    {
        $value = strtoupper(trim((string) $value));
        return in_array($value, array('PL', 'CZ', 'SK'), true) ? $value : 'PL';
    }

    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_gps-gmail-product-importer') {
            return;
        }
        wp_register_script('gps-gmail-product-importer-admin', '', array('jquery'), '0.1.0', true);
        wp_enqueue_script('gps-gmail-product-importer-admin');
        wp_localize_script('gps-gmail-product-importer-admin', 'GPSGmailImporter', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        ));
        wp_add_inline_script('gps-gmail-product-importer-admin', $this->admin_js());
    }

    private function admin_js()
    {
        return <<<'JS'
(function($){
    var runnerActive = false;
    var runnerTimer = null;

    function autoRunnerDelay(){
        var delay = parseInt($('#gps-gmail-auto-delay').val() || 5, 10);
        return Math.max(1, delay) * 1000;
    }

    function setAutoRunnerButtons(active){
        $('#gps-gmail-auto-start').prop('disabled', active);
        $('#gps-gmail-auto-stop').prop('disabled', !active);
    }

    function updateAutoRunnerOutput(data){
        data = data || {};
        $('[data-gps-auto-counter]').each(function(){
            var key = $(this).data('gps-auto-counter');
            var value = data[key];
            if (key === 'total_created' && value === undefined) {
                value = data.total_products_created;
            }
            if (value === undefined || value === null || value === '') {
                value = (key === 'stopped_reason') ? '-' : 0;
            }
            if (typeof value === 'object') {
                value = JSON.stringify(value);
            }
            $(this).text(value);
        });
        $('#gps-gmail-auto-output').text(JSON.stringify(data, null, 2));
    }

    function stopAutoRunner(reason){
        runnerActive = false;
        if (runnerTimer) {
            clearTimeout(runnerTimer);
            runnerTimer = null;
        }
        setAutoRunnerButtons(false);
        if (reason) {
            $('[data-gps-auto-counter="stopped_reason"]').text(reason);
        }
    }

    function runAutoRunnerBatch(reset){
        if (!runnerActive) { return; }
        $.post(GPSGmailImporter.ajaxUrl, {
            action: 'gps_gmail_product_importer_auto_runner_batch',
            nonce: GPSGmailImporter.nonce,
            mode: $('#gps-gmail-auto-mode').val(),
            batch_size: $('#gps-gmail-auto-batch-size').val(),
            reset: reset ? 1 : 0
        }).done(function(resp){
            var data = resp && resp.data ? resp.data : resp;
            updateAutoRunnerOutput(data);
            if (!resp || !resp.success || !data || data.state !== 'running') {
                stopAutoRunner((data && data.stopped_reason) || (resp && resp.success ? '' : 'ajax_error'));
                return;
            }
            runnerTimer = setTimeout(function(){ runAutoRunnerBatch(false); }, autoRunnerDelay());
        }).fail(function(xhr){
            var data = {state: 'error', stopped_reason: 'ajax_http_error', status: xhr.status, response: xhr.responseJSON || xhr.responseText};
            updateAutoRunnerOutput(data);
            stopAutoRunner('ajax_http_error');
        });
    }

    $(document).on('click', '#gps-gmail-auto-start', function(e){
        e.preventDefault();
        runnerActive = true;
        setAutoRunnerButtons(true);
        runAutoRunnerBatch(true);
    });

    $(document).on('click', '#gps-gmail-auto-stop', function(e){
        e.preventDefault();
        runnerActive = false;
        if (runnerTimer) {
            clearTimeout(runnerTimer);
            runnerTimer = null;
        }
        $('[data-gps-auto-counter="state"]').text('stopping');
        $.post(GPSGmailImporter.ajaxUrl, {
            action: 'gps_gmail_product_importer_auto_runner_batch',
            nonce: GPSGmailImporter.nonce,
            stop: 1
        }).always(function(resp){
            var data = resp && resp.data ? resp.data : resp;
            updateAutoRunnerOutput(data || {state: 'stopped', stopped_reason: 'admin_requested'});
            setAutoRunnerButtons(false);
        });
    });
})(jQuery);
JS;
    }

    public function render_admin_page()
    {
        if (!$this->current_user_can_manage_importer()) {
            wp_die(esc_html__('You do not have permission to access this page.', 'gps-gmail-product-importer'));
        }
        $settings = $this->settings();
        $connected = get_option(self::OPTION_CONNECTED_EMAIL, '');
        $last_result = get_transient('gps_gmail_product_importer_last_admin_result');
        delete_transient('gps_gmail_product_importer_last_admin_result');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('GPS Gmail Importer', 'gps-gmail-product-importer'); ?></h1>
            <?php if ($last_result) : ?>
                <?php
                $last_result_is_error = !empty($last_result['error']) || in_array((string) ($last_result['result'] ?? ''), array('error', 'blocked'), true);
                if (($last_result['action'] ?? '') === 'allegro_price_research' && in_array((string) ($last_result['result'] ?? ''), array('api_error', 'not_configured', 'no_query'), true)) {
                    $last_result_is_error = false;
                }
                $last_result_message = $this->admin_result_notice_message($last_result);
                ?>
                <div class="notice <?php echo $last_result_is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible">
                    <p><strong><?php echo esc_html($last_result_message); ?></strong></p>
                    <pre><?php echo esc_html(wp_json_encode($last_result, JSON_PRETTY_PRINT)); ?></pre>
                </div>
            <?php endif; ?>

            <?php $this->render_bulk_import_dashboard($settings, $last_result); ?>

            <h2><?php echo esc_html__('Advanced diagnostics and settings', 'gps-gmail-product-importer'); ?></h2>
            <p class="description"><?php esc_html_e('Advanced diagnostics are below. Day-to-day Gmail → staging → audit → Woo draft work should use the Bulk Import Dashboard above.', 'gps-gmail-product-importer'); ?></p>

            <h2><?php echo esc_html__('1. Settings', 'gps-gmail-product-importer'); ?></h2>
            <form method="post" action="options.php">
                <?php settings_fields('gps_gmail_product_importer_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="gps-gmail-label"><?php esc_html_e('Gmail label', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-gmail-label" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[gmail_label]" value="<?php echo esc_attr($settings['gmail_label']); ?>"></td></tr>
                    <tr><th><label for="gps-gmail-message-status-filter"><?php esc_html_e('Gmail message status filter', 'gps-gmail-product-importer'); ?></label></th><td><select id="gps-gmail-message-status-filter" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[message_status_filter]"><option value="all" <?php selected($settings['message_status_filter'], 'all'); ?>><?php esc_html_e('All messages', 'gps-gmail-product-importer'); ?></option><option value="read" <?php selected($settings['message_status_filter'], 'read'); ?>><?php esc_html_e('Only read/opened messages', 'gps-gmail-product-importer'); ?></option><option value="unread" <?php selected($settings['message_status_filter'], 'unread'); ?>><?php esc_html_e('Only unread messages', 'gps-gmail-product-importer'); ?></option></select><p class="description"><?php esc_html_e('Opened/read emails are Gmail messages without the UNREAD label.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th colspan="2"><h3><?php esc_html_e('Product Enrichment → Ovoko API enrichment', 'gps-gmail-product-importer'); ?></h3><p class="description"><?php esc_html_e('Uses the existing GPSwiss Ovoko Integration RRR/Ovoko API credentials for read-only OEM lookup. No browser scraping, Ovoko listing writes, eBay calls, Woo publishing, stock sync, or price changes are performed.', 'gps-gmail-product-importer'); ?></p></th></tr>
                    <tr><th><?php esc_html_e('Enable Ovoko enrichment', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_enabled]" value="1" <?php checked($settings['ovoko_enrichment_enabled'], 1); ?>> <?php esc_html_e('Allow Gmail-imported draft products to be enriched from Ovoko API lookup suggestions', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Overwrite existing attributes', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_overwrite_existing_attributes]" value="1" <?php checked($settings['ovoko_enrichment_overwrite_existing_attributes'], 1); ?>> <?php esc_html_e('Replace existing _gps_ovoko_* suggestion meta values when saving live suggestions', 'gps-gmail-product-importer'); ?></label><p class="description"><?php esc_html_e('Default is no overwrite.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Auto-assign category if confidence high', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_auto_assign_category]" value="1" <?php checked($settings['ovoko_enrichment_auto_assign_category'], 1); ?>> <?php esc_html_e('Assign a matched Woo category only for high-confidence Ovoko enrichment', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><label for="gps-ovoko-enrichment-batch-size"><?php esc_html_e('Ovoko enrichment batch size', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-ovoko-enrichment-batch-size" type="number" min="1" max="25" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_batch_size]" value="<?php echo esc_attr($settings['ovoko_enrichment_batch_size']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Dry-run by default', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_dry_run]" value="1" <?php checked($settings['ovoko_enrichment_dry_run'], 1); ?>> <?php esc_html_e('Preview Ovoko API enrichment without Woo product writes', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Save suggestions', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_save_suggestions]" value="1" <?php checked($settings['ovoko_enrichment_save_suggestions'], 1); ?>> <?php esc_html_e('Live enrichment saves only _gps_ovoko_* suggestion meta and optional high-confidence category assignment.', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Enable Ovoko price suggestion', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_price_fallback_enabled]" value="1" <?php checked($settings['ovoko_price_fallback_enabled'], 1); ?>> <?php esc_html_e('Use selected Ovoko match price as the primary automatic price suggestion for Woo drafts', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><label for="gps-ovoko-eur-rate"><?php esc_html_e('EUR→PLN fallback rate', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-ovoko-eur-rate" class="small-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_eur_to_pln_fallback_rate]" value="<?php echo esc_attr($settings['ovoko_eur_to_pln_fallback_rate']); ?>"><p class="description"><?php esc_html_e('Optional. Used only when selected Ovoko price is EUR and no PLN value exists.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Prefer Ovoko internal_notes price', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_prefer_internal_notes_price]" value="1" <?php checked($settings['ovoko_prefer_internal_notes_price'], 1); ?>> <?php esc_html_e('Use numeric internal_notes before original_price PLN', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Enable fixed Ovoko import category for Gmail CRM-only flow', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[fixed_ovoko_import_category_enabled]" value="1" <?php checked($settings['fixed_ovoko_import_category_enabled'], 1); ?>> <?php esc_html_e('Use one technical Ovoko category for CRM-only imports pending staff review', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Fixed Ovoko category ID', 'gps-gmail-product-importer'); ?></th><td><input type="number" min="1" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[fixed_ovoko_import_category_id]" value="<?php echo esc_attr($settings['fixed_ovoko_import_category_id']); ?>" class="small-text" placeholder="278"></td></tr>
                    <tr><th><?php esc_html_e('Fixed Ovoko category name', 'gps-gmail-product-importer'); ?></th><td><input type="text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[fixed_ovoko_import_category_name]" value="<?php echo esc_attr($settings['fixed_ovoko_import_category_name']); ?>" class="regular-text" placeholder="Turbina"></td></tr>
                    <tr><th><?php esc_html_e('Add category review warning to internal notes', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[fixed_ovoko_import_category_review_warning_enabled]" value="1" <?php checked($settings['fixed_ovoko_import_category_review_warning_enabled'], 1); ?>> <?php esc_html_e('Warn staff to correct the temporary category in Ovoko before publishing', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Allow empty price for fixed-category CRM-only drafts/imports', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allow_empty_price_for_fixed_category_crm_only_import]" value="1" <?php checked($settings['allow_empty_price_for_fixed_category_crm_only_import'], 1); ?>> <?php esc_html_e('Do not block Woo draft creation or CRM-only import when price is missing', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><label for="gps-ovoko-enrichment-delay"><?php esc_html_e('Ovoko enrichment delay between batches', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-ovoko-enrichment-delay" type="number" min="1" max="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_delay_between_batches]" value="<?php echo esc_attr($settings['ovoko_enrichment_delay_between_batches']); ?>"> <?php esc_html_e('seconds', 'gps-gmail-product-importer'); ?></td></tr>
                    <tr><th><label for="gps-gmail-batch-size"><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-gmail-batch-size" type="number" min="1" max="25" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[batch_size]" value="<?php echo esc_attr($settings['batch_size']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Product status default', 'gps-gmail-product-importer'); ?></th><td><select name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[product_status]"><option value="draft" <?php selected($settings['product_status'], 'draft'); ?>><?php esc_html_e('Draft', 'gps-gmail-product-importer'); ?></option><option value="pending_review" <?php selected($settings['product_status'], 'pending_review'); ?>><?php esc_html_e('Pending review', 'gps-gmail-product-importer'); ?></option></select><p class="description"><?php esc_html_e('Default is draft. Products are never published automatically.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Import images', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[import_images]" value="1" <?php checked($settings['import_images'], 1); ?>> <?php esc_html_e('Import jpg/jpeg/png/webp attachments', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Duplicate protection', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[duplicate_protection]" value="1" <?php checked($settings['duplicate_protection'], 1); ?>> <?php esc_html_e('Skip existing Gmail message IDs and possible OEM duplicates', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Mark Gmail messages as read after successful staging', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[mark_gmail_read_after_staging]" value="1" <?php checked($settings['mark_gmail_read_after_staging'], 1); ?>> <?php esc_html_e('Remove the UNREAD label only after a non-dry-run staging, update, or duplicate-safe staging decision succeeds.', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th colspan="2"><h3><?php esc_html_e('Product Enrichment → Allegro API price research', 'gps-gmail-product-importer'); ?></h3><p class="description"><?php esc_html_e("Optional legacy diagnostics only. Uses this plugin's own Allegro API credentials to search public offers and save staging-only _gps_allegro_* data; results are not used for production selected price, Woo draft readiness, or Ovoko CRM-only price fields.", 'gps-gmail-product-importer'); ?></p></th></tr>
                    <tr><th><?php esc_html_e('Enable Allegro API', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_api_enabled]" value="1" <?php checked($settings['allegro_api_enabled'], 1); ?>> <?php esc_html_e('Allow optional item-scoped Allegro diagnostic actions', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><label for="gps-allegro-environment"><?php esc_html_e('Allegro environment', 'gps-gmail-product-importer'); ?></label></th><td><select id="gps-allegro-environment" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_environment]"><option value="production" <?php selected($settings['allegro_environment'], 'production'); ?>><?php esc_html_e('Production', 'gps-gmail-product-importer'); ?></option><option value="sandbox" <?php selected($settings['allegro_environment'], 'sandbox'); ?>><?php esc_html_e('Sandbox', 'gps-gmail-product-importer'); ?></option></select></td></tr>
                    <tr><th><label for="gps-allegro-client-id"><?php esc_html_e('Allegro client ID', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-client-id" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_client_id]" value="<?php echo esc_attr($settings['allegro_client_id']); ?>"></td></tr>
                    <tr><th><label for="gps-allegro-client-secret"><?php esc_html_e('Allegro client secret', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-client-secret" type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_client_secret]" value="<?php echo esc_attr($settings['allegro_client_secret']); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="gps-allegro-access-token"><?php esc_html_e('Allegro access token', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-access-token" type="password" class="large-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_access_token]" value="<?php echo esc_attr($settings['allegro_access_token']); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="gps-allegro-refresh-token"><?php esc_html_e('Allegro refresh token', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-refresh-token" type="password" class="large-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_refresh_token]" value="<?php echo esc_attr($settings['allegro_refresh_token']); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="gps-allegro-token-expires-at"><?php esc_html_e('Allegro token expires at', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-token-expires-at" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_token_expires_at]" value="<?php echo esc_attr($settings['allegro_token_expires_at']); ?>"><p class="description"><?php esc_html_e('Unix timestamp or parseable date/time. Leave blank only for long-lived/manual testing tokens.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th><label for="gps-allegro-marketplace-country"><?php esc_html_e('Allegro marketplace / country', 'gps-gmail-product-importer'); ?></label></th><td><select id="gps-allegro-marketplace-country" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_marketplace_country]"><option value="PL" <?php selected($settings['allegro_marketplace_country'], 'PL'); ?>>PL / allegro-pl</option><option value="CZ" <?php selected($settings['allegro_marketplace_country'], 'CZ'); ?>>CZ / allegro-cz</option><option value="SK" <?php selected($settings['allegro_marketplace_country'], 'SK'); ?>>SK / allegro-sk</option></select></td></tr>
                    <tr><th><label for="gps-allegro-search-limit"><?php esc_html_e('Allegro search limit', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-search-limit" type="number" min="1" max="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_search_limit]" value="<?php echo esc_attr($settings['allegro_search_limit']); ?>"></td></tr>
                    <tr><th><label for="gps-allegro-min-filtered"><?php esc_html_e('Minimum filtered offer count', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-allegro-min-filtered" type="number" min="1" max="25" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_min_filtered_offer_count]" value="<?php echo esc_attr($settings['allegro_min_filtered_offer_count']); ?>"></td></tr>
                    <tr><th><label for="gps-allegro-stat-method"><?php esc_html_e('Price statistic method', 'gps-gmail-product-importer'); ?></label></th><td><select id="gps-allegro-stat-method" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_price_statistic_method]"><option value="median" <?php selected($settings['allegro_price_statistic_method'], 'median'); ?>><?php esc_html_e('Median', 'gps-gmail-product-importer'); ?></option><option value="min" <?php selected($settings['allegro_price_statistic_method'], 'min'); ?>><?php esc_html_e('Minimum', 'gps-gmail-product-importer'); ?></option><option value="max" <?php selected($settings['allegro_price_statistic_method'], 'max'); ?>><?php esc_html_e('Maximum', 'gps-gmail-product-importer'); ?></option></select></td></tr>
                    <tr><th><label for="gps-allegro-readiness-confidence"><?php esc_html_e('Confidence threshold', 'gps-gmail-product-importer'); ?></label></th><td><select id="gps-allegro-readiness-confidence" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[allegro_readiness_min_confidence]"><option value="medium" <?php selected($settings['allegro_readiness_min_confidence'], 'medium'); ?>><?php esc_html_e('Medium or high', 'gps-gmail-product-importer'); ?></option><option value="high" <?php selected($settings['allegro_readiness_min_confidence'], 'high'); ?>><?php esc_html_e('High only', 'gps-gmail-product-importer'); ?></option><option value="low" <?php selected($settings['allegro_readiness_min_confidence'], 'low'); ?>><?php esc_html_e('Low, medium, or high', 'gps-gmail-product-importer'); ?></option></select></td></tr>
                    <tr><th><?php esc_html_e('Auto-assign category', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[auto_assign_high_confidence_category]" value="1" <?php checked($settings['auto_assign_high_confidence_category'], 1); ?>> <?php esc_html_e('Assign Woo category automatically only if confidence is high', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><label for="gps-google-client-id"><?php esc_html_e('Google client ID', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-google-client-id" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[google_client_id]" value="<?php echo esc_attr($settings['google_client_id']); ?>"></td></tr>
                    <tr><th><label for="gps-google-client-secret"><?php esc_html_e('Google client secret', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-google-client-secret" type="password" class="regular-text" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[google_client_secret]" value="<?php echo esc_attr($settings['google_client_secret']); ?>" autocomplete="off"></td></tr>
                    <tr><th><label for="gps-gmail-delay"><?php esc_html_e('Delay between batches', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-gmail-delay" type="number" min="1" max="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[delay_between_batches]" value="<?php echo esc_attr($settings['delay_between_batches']); ?>"> <?php esc_html_e('seconds', 'gps-gmail-product-importer'); ?></td></tr>
                </table>
                <?php submit_button(__('Save settings', 'gps-gmail-product-importer')); ?>
            </form>

            <h2><?php echo esc_html__('2. Gmail Connection', 'gps-gmail-product-importer'); ?></h2>
            <p><?php echo $connected ? esc_html(sprintf(__('Connected account: %s', 'gps-gmail-product-importer'), $connected)) : esc_html__('No Gmail account connected.', 'gps-gmail-product-importer'); ?></p>
            <p><?php esc_html_e('Tokens are stored in WordPress options and are never displayed in this admin screen.', 'gps-gmail-product-importer'); ?></p>
            <p><a class="button button-primary" href="<?php echo esc_url($this->oauth_url()); ?>"><?php esc_html_e('Connect Gmail', 'gps-gmail-product-importer'); ?></a></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_disconnect"><?php submit_button(__('Disconnect Gmail', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_test"><?php submit_button(__('Test Gmail API', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?></form>

            <h2><?php echo esc_html__('3. Import Queue', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Gmail Scan writes parsed messages into staging/import queue records first. Woo, eBay, and Ovoko publishing are not performed by the scan.', 'gps-gmail-product-importer'); ?></p>
            <p><strong><?php esc_html_e('Price source priority:', 'gps-gmail-product-importer'); ?></strong> <?php esc_html_e('manual_override → ovoko_price_suggestion → no selected price', 'gps-gmail-product-importer'); ?></p>
            <p class="description"><?php esc_html_e('Allegro price research is disabled by default, optional legacy diagnostics only, and not used in the production Gmail → Woo → Ovoko flow.', 'gps-gmail-product-importer'); ?></p>
            <?php $this->render_import_queue_admin_view(); ?>

            <h2><?php echo esc_html__('4. Gmail Scan', 'gps-gmail-product-importer'); ?></h2>
            <h3><?php echo esc_html__('Dry-run Gmail Scan', 'gps-gmail-product-importer'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_dry_run">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="5"></label>
                <?php submit_button(__('Run dry-run', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>

            <h3><?php echo esc_html__('Stage Gmail messages', 'gps-gmail-product-importer'); ?></h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_import">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['batch_size']); ?>"></label>
                <?php submit_button(__('Scan and stage batch', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?>
            </form>
            <p><label><?php esc_html_e('Auto-run batch size', 'gps-gmail-product-importer'); ?> <input id="gps-gmail-auto-batch-size" type="number" min="1" max="25" value="<?php echo esc_attr($settings['batch_size']); ?>"></label> <button class="button" id="gps-gmail-start"><?php esc_html_e('Start auto runner', 'gps-gmail-product-importer'); ?></button> <button class="button" id="gps-gmail-stop" disabled><?php esc_html_e('Stop auto runner', 'gps-gmail-product-importer'); ?></button></p>
            <pre id="gps-gmail-run-output"><?php echo esc_html(wp_json_encode($this->run_state(), JSON_PRETTY_PRINT)); ?></pre>

            <h2><?php echo esc_html__('5. Ovoko Enrichment', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Enriches staged Gmail queue items or previously created drafts by reading detected part/OEM numbers, then calling the Ovoko/RRR API read-only OEM lookup. Live save writes suggestions only and never publishes Woo, eBay, or Ovoko listings.', 'gps-gmail-product-importer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_ovoko_test">
                <label for="gps-ovoko-test-oem"><?php esc_html_e('Test Ovoko OEM lookup', 'gps-gmail-product-importer'); ?></label>
                <input id="gps-ovoko-test-oem" class="regular-text" name="oem" placeholder="<?php echo esc_attr__('OEM / part number', 'gps-gmail-product-importer'); ?>">
                <?php submit_button(__('Test lookup (no writes)', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;padding:12px;background:#fff;border:1px solid #ccd0d4;max-width:1000px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_ovoko_category_prediction_test">
                <h3 style="margin-top:0;"><?php esc_html_e('Test Ovoko category prediction by code', 'gps-gmail-product-importer'); ?></h3>
                <p class="description"><?php esc_html_e('Ovoko panel category prediction is required. This read-only diagnostic tries safe API candidates and can parse a redacted browser Network capture. It does not use category-tree fallback or manual category mapping as prediction.', 'gps-gmail-product-importer'); ?></p>
                <p><label for="gps-ovoko-category-prediction-code"><?php esc_html_e('Part code', 'gps-gmail-product-importer'); ?></label> <input id="gps-ovoko-category-prediction-code" class="regular-text" name="part_code" value="06K145654L"></p>
                <p><label for="gps-ovoko-category-prediction-expected"><?php esc_html_e('Expected panel category', 'gps-gmail-product-importer'); ?></label> <input id="gps-ovoko-category-prediction-expected" class="regular-text" name="expected_category_name" value="Turbina"></p>
                <p><label for="gps-ovoko-category-capture-request"><?php esc_html_e('Optional redacted Network capture request', 'gps-gmail-product-importer'); ?></label><br><textarea id="gps-ovoko-category-capture-request" name="capture_request" rows="5" style="width:100%;" placeholder="URL, method, query params, form payload, status/auth style; redact cookies/session/token values"></textarea></p>
                <p><label for="gps-ovoko-category-capture-response"><?php esc_html_e('Optional redacted Network capture response body', 'gps-gmail-product-importer'); ?></label><br><textarea id="gps-ovoko-category-capture-response" name="capture_response" rows="5" style="width:100%;" placeholder="JSON/HTML response that returns Turbina/category_id"></textarea></p>
                <p class="description"><?php esc_html_e('Next required action if unresolved: open Ovoko web panel → create/add/import part form → DevTools Network → clear → type 06K145654L into main part code/manufacturer code → wait for Turbina → paste the request/response here.', 'gps-gmail-product-importer'); ?></p>
                <?php submit_button(__('Test category prediction (no writes)', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_ovoko_enrichment_dry_run">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['ovoko_enrichment_batch_size']); ?>"></label>
                <?php submit_button(__('Run Ovoko enrichment dry-run', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_ovoko_enrichment_save">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['ovoko_enrichment_batch_size']); ?>"></label>
                <?php submit_button(__('Save Ovoko suggestions', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('6. Allegro Price Research (optional legacy diagnostics)', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Allegro price research is disabled by default and not used in the production pricing flow. Optional item-scoped diagnostics save only _gps_allegro_* staging meta and never select or overwrite the Woo draft price.', 'gps-gmail-product-importer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_allegro_test">
                <?php submit_button(__('Test Allegro API connection', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('7. Category Mapping', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Stores suggested Woo category ID, path, confidence, and source on staging records before product creation.', 'gps-gmail-product-importer'); ?></p>

            <h2><?php echo esc_html__('8. Readiness', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Readiness validation stores separate Woo draft and marketplace readiness. Woo draft readiness requires a selected price from manual_override or ovoko_price_suggestion; Allegro is not required.', 'gps-gmail-product-importer'); ?></p>

            <h2><?php echo esc_html__('9. Full Preparation Batch', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Runs staging-only preparation for queued items: Ovoko enrichment, category suggestion by code when needed, Ovoko price suggestion, category mapping, and readiness validation. This does not create Woo products, write to Ovoko, call Allegro, or perform CRM-only import.', 'gps-gmail-product-importer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_full_preparation_batch">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="5"></label>
                <?php submit_button(__('Run full preparation batch', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('10. Create Woo Draft Products', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Creates WooCommerce draft products from staging items that pass Woo draft readiness. Products are always created as post_type product with draft status.', 'gps-gmail-product-importer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_create_woo_drafts">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="5"></label>
                <?php submit_button(__('Create Woo draft products from ready staging items', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('11. Reports', 'gps-gmail-product-importer'); ?></h2>
            <p><?php echo esc_html(sprintf(__('Reports are written under wp-content/uploads/%s/.', 'gps-gmail-product-importer'), self::UPLOAD_DIR)); ?></p>
            <ul>
                <?php foreach ($this->report_files() as $file => $path) : ?>
                    <li><?php echo esc_html($file); ?> — <?php echo file_exists($path) ? esc_html(size_format(filesize($path))) : esc_html__('not created yet', 'gps-gmail-product-importer'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    private function admin_result_notice_message($result)
    {
        $result = (array) $result;
        $action = (string) ($result['action'] ?? '');
        $status = (string) ($result['result'] ?? '');
        if ($action === 'reset_created_woo_product_link' && $status === 'reset_created_product_link') {
            return __('Created Woo product link was reset for the import queue item.', 'gps-gmail-product-importer');
        }
        if ($action === 'reset_created_woo_product_link' && $status === 'blocked') {
            return __('Reset created Woo product link was blocked because the linked product still exists. Check force reset to proceed.', 'gps-gmail-product-importer');
        }
        if (!empty($result['error'])) {
            return (string) $result['error'];
        }
        return __('Import queue action completed.', 'gps-gmail-product-importer');
    }



    private function render_bulk_import_dashboard($settings, $last_result = array())
    {
        $audit = $this->bulk_dashboard_audit_data();
        $settings_summary = $this->bulk_dashboard_settings_summary($settings);
        ?>
        <style>
            .gps-bulk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:16px 0}.gps-card{background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:14px}.gps-card h3{margin-top:0}.gps-count{font-size:24px;font-weight:600}.gps-badge{display:inline-block;border-radius:10px;padding:2px 8px;font-size:12px;background:#f0f0f1}.gps-badge-ready{background:#d1e7dd;color:#0f5132}.gps-badge-review{background:#fff3cd;color:#664d03}.gps-badge-created{background:#cfe2ff;color:#084298}.gps-badge-error{background:#f8d7da;color:#842029}.gps-danger{border-left:4px solid #d63638}.gps-primary-flow{border-left:4px solid #2271b1}
        </style>
        <h2><?php esc_html_e('Bulk Import Dashboard', 'gps-gmail-product-importer'); ?></h2>
        <p><?php esc_html_e('One safe workflow for Gmail unread scan → staging queue → full preparation audit → Woo draft product creation. This dashboard does not call Ovoko live import or Allegro.', 'gps-gmail-product-importer'); ?></p>
        <div class="gps-bulk-grid">
            <div class="gps-card gps-primary-flow"><h3><?php esc_html_e('Import settings summary', 'gps-gmail-product-importer'); ?></h3>
                <ul>
                    <?php foreach ($settings_summary['rows'] as $row) : ?><li><strong><?php echo esc_html($row['label']); ?>:</strong> <?php echo esc_html($row['value']); ?></li><?php endforeach; ?>
                </ul>
                <?php if ($settings_summary['warnings']) : ?><div class="notice notice-warning inline"><p><strong><?php esc_html_e('Warnings', 'gps-gmail-product-importer'); ?></strong></p><ul><?php foreach ($settings_summary['warnings'] as $warning) : ?><li><?php echo esc_html($warning); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            </div>
            <div class="gps-card"><h3><?php esc_html_e('Audit summary', 'gps-gmail-product-importer'); ?></h3>
                <div class="gps-bulk-grid" style="grid-template-columns:repeat(2,minmax(110px,1fr));margin:0;">
                    <?php foreach ($audit['summary_cards'] as $card) : ?><div><div class="gps-count"><?php echo esc_html((string) $card['count']); ?></div><div><?php echo esc_html($card['label']); ?></div></div><?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="gps-bulk-grid">
            <div class="gps-card"><h3><?php esc_html_e('Step 1 — Scan Gmail', 'gps-gmail-product-importer'); ?></h3>
                <p><?php esc_html_e('Scan unread Gmail messages and stage them into the Import Queue. Scan/stage never creates Woo products, never calls Allegro, and never writes to Ovoko.', 'gps-gmail-product-importer'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:8px;"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_dry_run"><label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['batch_size']); ?>"></label> <?php submit_button(__('Dry-run scan', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_import"><label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['batch_size']); ?>"></label> <?php submit_button(__('Stage unread Gmail messages', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?></form>
                <?php $this->render_last_batch_summary($last_result); ?>
            </div>
            <div class="gps-card"><h3><?php esc_html_e('Step 2 — Prepare / Audit staged items', 'gps-gmail-product-importer'); ?></h3>
                <p><?php esc_html_e('Runs full preparation and readiness validation only. No Woo product creation, no Ovoko write, no Allegro call.', 'gps-gmail-product-importer'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_full_preparation_batch"><label><?php esc_html_e('Limit', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="25"></label> <label><input type="checkbox" name="only_unprepared" value="1" checked> <?php esc_html_e('Only unprepared', 'gps-gmail-product-importer'); ?></label> <label><input type="checkbox" name="include_needs_review" value="1" checked> <?php esc_html_e('Include needs review', 'gps-gmail-product-importer'); ?></label> <?php submit_button(__('Run full preparation audit', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?></form>
                <?php if ($audit['blockers_grouped']) : ?><p><strong><?php esc_html_e('Blockers grouped by reason:', 'gps-gmail-product-importer'); ?></strong> <?php echo esc_html($this->format_blocker_counts($audit['blockers_grouped'])); ?></p><?php endif; ?>
            </div>
            <div class="gps-card"><h3><?php esc_html_e('Step 3 — Create Woo draft products', 'gps-gmail-product-importer'); ?></h3>
                <p><?php esc_html_e('Creates only items whose Woo draft readiness is ready_to_create_product. Needs-review and duplicate/already-created items are skipped.', 'gps-gmail-product-importer'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_create_woo_drafts"><label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="10"></label> <?php submit_button(__('Create Woo drafts for ready items', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?></form>
            </div>
            <div class="gps-card"><h3><?php esc_html_e('Audit CSV exports', 'gps-gmail-product-importer'); ?></h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_download_audit_csv"><input type="hidden" name="csv_type" value="errors"><?php submit_button(__('Download audit errors CSV', 'gps-gmail-product-importer'), $audit['summary']['blocked'] > 0 ? 'secondary' : 'secondary disabled', 'submit', false); ?></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;"><?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_download_audit_csv"><input type="hidden" name="csv_type" value="full"><?php submit_button(__('Download full audit CSV', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?></form>
            </div>
        </div>
        <div class="gps-card gps-primary-flow" id="gps-gmail-auto-runner">
            <h3><?php esc_html_e('Optional Auto runner', 'gps-gmail-product-importer'); ?></h3>
            <p><?php esc_html_e('Browser-based runner: one small AJAX batch per request, then the browser waits before starting the next batch. It reuses the same Gmail scan/stage, preparation audit, and Woo draft creation methods as the manual buttons.', 'gps-gmail-product-importer'); ?></p>
            <p class="description"><?php esc_html_e('Safety: no Ovoko live import, no Allegro import, no eBay sends, and Woo products remain drafts. Existing readiness blockers and duplicate/already-created checks are not bypassed.', 'gps-gmail-product-importer'); ?></p>
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:12px 0;">
                <label><strong><?php esc_html_e('Mode', 'gps-gmail-product-importer'); ?></strong><br>
                    <select id="gps-gmail-auto-mode">
                        <option value="scan_gmail_dry_run"><?php esc_html_e('dry-run scan Gmail', 'gps-gmail-product-importer'); ?></option>
                        <option value="scan_gmail_stage"><?php esc_html_e('stage unread Gmail messages', 'gps-gmail-product-importer'); ?></option>
                        <option value="prepare_audit"><?php esc_html_e('preparation audit', 'gps-gmail-product-importer'); ?></option>
                        <option value="create_woo_drafts"><?php esc_html_e('create Woo draft products', 'gps-gmail-product-importer'); ?></option>
                        <option value="full_pipeline_safe"><?php esc_html_e('full safe pipeline', 'gps-gmail-product-importer'); ?></option>
                    </select>
                </label>
                <label><strong><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?></strong><br><input id="gps-gmail-auto-batch-size" type="number" min="1" max="25" value="10"></label>
                <label><strong><?php esc_html_e('Delay between batches', 'gps-gmail-product-importer'); ?></strong><br><input id="gps-gmail-auto-delay" type="number" min="1" max="60" value="5"> <?php esc_html_e('seconds', 'gps-gmail-product-importer'); ?></label>
                <button id="gps-gmail-auto-start" class="button button-primary"><?php esc_html_e('Start', 'gps-gmail-product-importer'); ?></button>
                <button id="gps-gmail-auto-stop" class="button" disabled><?php esc_html_e('Stop', 'gps-gmail-product-importer'); ?></button>
            </div>
            <table class="widefat striped" style="max-width:1100px;">
                <tbody>
                <?php foreach (array('batches_completed', 'total_checked', 'total_staged', 'total_prepared', 'total_created', 'total_skipped', 'total_blocked', 'total_errors', 'remaining_candidates', 'state', 'stopped_reason') as $counter_key) : ?>
                    <tr><th style="width:240px;"><?php echo esc_html($counter_key); ?></th><td><code data-gps-auto-counter="<?php echo esc_attr($counter_key); ?>"><?php echo $counter_key === 'stopped_reason' ? '-' : ($counter_key === 'state' ? 'idle' : '0'); ?></code></td></tr>
                <?php endforeach; ?>
                    <tr><th><?php esc_html_e('last_batch_result', 'gps-gmail-product-importer'); ?></th><td><pre id="gps-gmail-auto-output" style="white-space:pre-wrap;max-height:360px;overflow:auto;margin:0;">{}</pre></td></tr>
                </tbody>
            </table>
        </div>
        <div class="gps-card"><h3><?php esc_html_e('Audit table', 'gps-gmail-product-importer'); ?></h3>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e('Staging item ID', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Gmail date', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Gmail subject', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Storage location', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Part code/OEM', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Images', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Fixed category', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Price status', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Woo readiness', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Blockers', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Created product ID', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Actions', 'gps-gmail-product-importer'); ?></th></tr></thead><tbody>
            <?php foreach ($audit['rows'] as $row) : ?><tr><td><?php echo esc_html((string) $row['staging_item_id']); ?></td><td><?php echo esc_html($row['gmail_date']); ?></td><td><?php echo esc_html($row['gmail_subject']); ?></td><td><?php echo esc_html($row['storage_location']); ?></td><td><?php echo esc_html(trim($row['part_code'] . ' / ' . $row['oem'], ' /')); ?></td><td><?php echo esc_html((string) $row['image_count']); ?></td><td><?php echo esc_html($row['fixed_category_label']); ?></td><td><?php echo esc_html($row['price_status']); ?></td><td><?php echo $this->status_badge($row['status_badge'], $row['readiness_status']); ?></td><td><?php echo esc_html(implode(', ', $row['blocking_reasons'])); ?></td><td><?php echo esc_html((string) $row['created_product_id']); ?></td><td><?php $this->render_import_queue_item_actions((int) $row['staging_item_id'], $row['readiness_status'] === 'ready_to_create_product' && empty($row['blocking_reasons'])); ?></td></tr><?php endforeach; ?>
            </tbody></table>
        </div>
        <?php if ($audit['created_rows']) : ?><div class="gps-card"><h3><?php esc_html_e('Step 4 — Post-create summary', 'gps-gmail-product-importer'); ?></h3><table class="widefat striped"><thead><tr><th><?php esc_html_e('Staging → product', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Title / SKU', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Images', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Fixed category / price empty', 'gps-gmail-product-importer'); ?></th><th><?php esc_html_e('Links', 'gps-gmail-product-importer'); ?></th></tr></thead><tbody><?php foreach ($audit['created_rows'] as $row) : ?><tr><td><?php echo esc_html($row['staging_item_id'] . ' → ' . $row['created_product_id']); ?></td><td><?php echo esc_html($row['gmail_subject']); ?><br><code><?php echo esc_html($this->product_sku((int) $row['created_product_id'])); ?></code></td><td><?php echo esc_html((string) $row['image_count']); ?></td><td><?php echo esc_html($row['fixed_category_label'] . ' / ' . ($row['price_empty'] ? 'empty price' : 'priced')); ?></td><td><a href="<?php echo esc_url(get_edit_post_link((int) $row['created_product_id'])); ?>"><?php esc_html_e('Edit Woo product', 'gps-gmail-product-importer'); ?></a> | <a href="<?php echo esc_url(admin_url('admin.php?page=gpswiss-ovoko-integration&product_id=' . absint($row['created_product_id']))); ?>"><?php esc_html_e('Preview Woo → Ovoko CRM-only import payload', 'gps-gmail-product-importer'); ?></a> | <a href="<?php echo esc_url(add_query_arg(array('page' => 'gps-gmail-product-importer', 'gps_staging_item_id' => (int) $row['staging_item_id']), admin_url('admin.php'))); ?>"><?php esc_html_e('View Import Queue item', 'gps-gmail-product-importer'); ?></a></td></tr><?php endforeach; ?></tbody></table><p class="description"><?php esc_html_e('No automatic live Ovoko import is run from this dashboard.', 'gps-gmail-product-importer'); ?></p></div><?php endif; ?>
        <hr>
        <?php
    }

    private function bulk_dashboard_settings_summary($settings)
    {
        $fixed = $this->fixed_import_category_settings();
        $rows = array(
            array('label' => 'Gmail label', 'value' => (string) ($settings['gmail_label'] ?? '')),
            array('label' => 'Gmail status filter', 'value' => (string) ($settings['message_status_filter'] ?? '')),
            array('label' => 'Fixed Ovoko category enabled', 'value' => !empty($fixed['enabled']) ? 'yes' : 'no'),
            array('label' => 'Fixed category ID/name', 'value' => trim((string) ($fixed['category_id'] ?? '') . ' / ' . (string) ($fixed['category_name'] ?? ''), ' /')),
            array('label' => 'Empty price allowed', 'value' => !empty($settings['allow_empty_price_for_fixed_category_crm_only_import']) ? 'yes' : 'no'),
            array('label' => 'Product status default', 'value' => 'draft'),
            array('label' => 'Image import enabled', 'value' => !empty($settings['import_images']) ? 'yes' : 'no'),
            array('label' => 'Duplicate protection enabled', 'value' => !empty($settings['duplicate_protection']) ? 'yes' : 'no'),
            array('label' => 'Mark Gmail as read after staging', 'value' => !empty($settings['mark_gmail_read_after_staging']) ? 'yes' : 'no'),
        );
        $warnings = array();
        if (!empty($fixed['enabled']) && empty($fixed['configured'])) { $warnings[] = 'Fixed category is enabled but category ID is empty.'; }
        if (empty($settings['allow_empty_price_for_fixed_category_crm_only_import'])) { $warnings[] = 'Empty price is not allowed; CRM-only fixed-category no-price drafts will be blocked.'; }
        if ((string) ($settings['message_status_filter'] ?? '') !== 'unread') { $warnings[] = 'Gmail filter is not set to unread.'; }
        if (empty($settings['import_images'])) { $warnings[] = 'Image import is disabled.'; }
        if (empty($settings['duplicate_protection'])) { $warnings[] = 'Duplicate protection is disabled.'; }
        return array('rows' => $rows, 'warnings' => $warnings);
    }

    private function bulk_dashboard_audit_data($limit = 1000)
    {
        $ids = get_posts(array('post_type' => self::STAGING_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => $limit, 'orderby' => 'modified', 'order' => 'DESC'));
        $rows = array(); $summary = array('total_staged' => 0, 'ready' => 0, 'blocked' => 0, 'already_created' => 0, 'duplicates' => 0, 'missing_images' => 0, 'missing_part_code' => 0, 'readiness_errors' => 0); $blockers = array();
        foreach ($ids as $id) {
            $row = $this->bulk_dashboard_audit_row((int) $id);
            $rows[] = $row; $summary['total_staged']++;
            if ($row['readiness_status'] === 'ready_to_create_product' && empty($row['blocking_reasons'])) { $summary['ready']++; } else { $summary['blocked']++; }
            if ($row['created_product_id']) { $summary['already_created']++; }
            if (in_array('duplicate_gmail_message', $row['blocking_reasons'], true) || in_array('duplicate_oem', $row['blocking_reasons'], true)) { $summary['duplicates']++; }
            if (in_array('missing_images', $row['blocking_reasons'], true)) { $summary['missing_images']++; }
            if (in_array('missing_part_code', $row['blocking_reasons'], true)) { $summary['missing_part_code']++; }
            if ($row['readiness_status'] === 'needs_review' || $row['readiness_status'] === 'blocked') { $summary['readiness_errors']++; }
            foreach ($row['blocking_reasons'] as $reason) { $blockers[$reason] = ($blockers[$reason] ?? 0) + 1; }
        }
        return array('rows' => $rows, 'created_rows' => array_values(array_filter($rows, function ($row) { return !empty($row['created_product_id']); })), 'summary' => $summary, 'blockers_grouped' => $blockers, 'summary_cards' => array(array('label' => 'Total staged', 'count' => $summary['total_staged']), array('label' => 'Ready for Woo draft', 'count' => $summary['ready']), array('label' => 'Blocked', 'count' => $summary['blocked']), array('label' => 'Already created', 'count' => $summary['already_created']), array('label' => 'Duplicates', 'count' => $summary['duplicates']), array('label' => 'Missing images', 'count' => $summary['missing_images']), array('label' => 'Missing part code', 'count' => $summary['missing_part_code']), array('label' => 'Readiness errors', 'count' => $summary['readiness_errors'])));
    }

    private function bulk_dashboard_audit_row($item_id)
    {
        $post = get_post($item_id); $analysis = $this->analysis_from_staging_item($item_id); $created_product_id = absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true)); $staging_status = (string) get_post_meta($item_id, '_gps_staging_status', true); $readiness = $this->woo_draft_readiness_status($analysis, $staging_status, $created_product_id); $reasons = $this->normalize_dashboard_blockers((array) ($readiness['blocking_reasons'] ?? array()), $analysis, $staging_status);
        $fixed_id = (string) get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_id', true); $fixed_name = (string) get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', true); $price = $this->selected_price_for_analysis($analysis); $empty_allowed = $this->fixed_import_category_empty_price_allowed($analysis);
        return array('staging_item_id' => (int) $item_id, 'gmail_message_id' => (string) ($analysis['message_id'] ?? ''), 'gmail_date' => (string) ($analysis['date'] ?? ''), 'gmail_subject' => (string) (($analysis['subject'] ?? '') ?: ($post->post_title ?? '')), 'storage_location' => (string) ($analysis['storage_location'] ?? ''), 'part_code' => (string) (($analysis['detected_part_code'] ?? '') ?: ($analysis['normalized_part_code'] ?? '')), 'oem' => (string) (($analysis['detected_oem_part_number'] ?? '') ?: ($analysis['normalized_oem_part_number'] ?? '')), 'image_count' => absint($analysis['image_attachments_found'] ?? 0), 'fixed_category_label' => trim($fixed_id . ' / ' . $fixed_name, ' /') ?: 'not configured', 'price_status' => $empty_allowed ? ($price ? 'selected price ' . $price['price'] : 'empty price allowed') : ($price ? 'selected price ' . $price['price'] : 'no price allowed'), 'readiness_status' => (string) ($readiness['status'] ?? 'needs_review'), 'blocking_reasons' => $reasons, 'created_product_id' => $created_product_id, 'status_badge' => $created_product_id ? 'created' : ($staging_status === 'duplicate' ? 'duplicate' : ((string) ($readiness['status'] ?? '') === 'ready_to_create_product' && empty($reasons) ? 'ready' : 'needs_review')), 'suggested_action' => $this->suggested_action_for_blockers($reasons), 'notes' => (string) get_post_meta($item_id, '_gps_gmail_warnings', true), 'price_empty' => !$price);
    }

    private function normalize_dashboard_blockers($reasons, $analysis = array(), $staging_status = '')
    {
        $map = array('missing_detected_part_code' => 'missing_part_code', 'missing_normalized_part_code' => 'missing_part_code', 'missing_detected_oem_part_number' => 'missing_part_code', 'missing_normalized_oem_part_number' => 'missing_part_code', 'missing_images_metadata' => 'missing_images', 'fixed_import_category_not_configured' => 'fixed_category_not_configured', 'duplicate' => 'duplicate_gmail_message');
        $out = array(); foreach ($reasons as $reason) { $out[] = $map[(string) $reason] ?? (string) $reason; }
        if ($staging_status === 'duplicate') { $out[] = 'duplicate_gmail_message'; }
        if (trim((string) ($analysis['duplicate_status'] ?? '')) !== '') { $out[] = (string) ($analysis['duplicate_status'] ?? ''); }
        return array_values(array_unique(array_filter($out)));
    }

    private function suggested_action_for_blockers($reasons)
    {
        if (!$reasons) { return 'ready'; }
        $actions = array(); foreach ($reasons as $reason) { if ($reason === 'missing_part_code') { $actions[] = 'add/fix part code'; } elseif ($reason === 'missing_images') { $actions[] = 'add images'; } elseif (str_contains($reason, 'duplicate')) { $actions[] = 'check duplicate'; } elseif ($reason === 'fixed_category_not_configured') { $actions[] = 'configure fixed category'; } elseif ($reason === 'product_already_created') { $actions[] = 'already created'; } else { $actions[] = 'manual review'; } }
        return implode('; ', array_values(array_unique($actions)));
    }

    private function status_badge($type, $label)
    {
        $class = $type === 'ready' ? 'gps-badge-ready' : ($type === 'created' ? 'gps-badge-created' : ($type === 'error' ? 'gps-badge-error' : 'gps-badge-review'));
        return '<span class="gps-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    private function format_blocker_counts($counts)
    {
        $parts = array(); foreach ($counts as $reason => $count) { $parts[] = $reason . ': ' . $count; } return implode(', ', $parts);
    }

    private function render_last_batch_summary($last_result)
    {
        if (!is_array($last_result) || empty($last_result)) { return; }
        $keys = array('total_checked', 'total_staged', 'total_stage_updated', 'total_skipped', 'total_duplicates', 'total_marked_read', 'total_mark_read_failed', 'messages_marked_read', 'messages_left_unread_due_to_errors', 'remaining_messages');
        echo '<p><strong>' . esc_html__('Last batch summary:', 'gps-gmail-product-importer') . '</strong> ';
        $parts = array(); foreach ($keys as $key) { if (isset($last_result[$key])) { $parts[] = $key . '=' . (string) $last_result[$key]; } }
        if (!empty($last_result['warnings'])) { $parts[] = 'warnings=' . implode('|', array_map('strval', (array) $last_result['warnings'])); }
        echo esc_html(implode(', ', $parts) ?: (string) ($last_result['result'] ?? 'completed')) . '</p>';
    }

    private function product_sku($product_id)
    {
        return (string) get_post_meta($product_id, '_sku', true);
    }

    public function handle_download_audit_csv()
    {
        $this->verify_admin_action();
        $type = sanitize_key($_POST['csv_type'] ?? 'full');
        $audit = $this->bulk_dashboard_audit_data();
        $rows = $type === 'errors' ? array_values(array_filter($audit['rows'], function ($row) { return !empty($row['blocking_reasons']); })) : $audit['rows'];
        $filename = 'gps-gmail-import-audit-' . ($type === 'errors' ? 'errors' : 'full') . '-' . gmdate('Ymd-His') . '.csv';
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, array('staging_item_id', 'gmail_message_id', 'gmail_subject', 'storage_location', 'part_code', 'image_count', 'readiness_status', 'blocking_reasons', 'suggested_action', 'created_product_id', 'notes'));
        foreach ($rows as $row) { fputcsv($out, array($row['staging_item_id'], $row['gmail_message_id'], $row['gmail_subject'], $row['storage_location'], $row['part_code'], $row['image_count'], $row['readiness_status'], implode('|', $row['blocking_reasons']), $row['suggested_action'], $row['created_product_id'], $row['notes'])); }
        fclose($out);
        exit;
    }

    private function render_import_queue_admin_view()
    {
        $items = get_posts(array(
            'post_type' => self::STAGING_POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => 100,
            'orderby' => 'modified',
            'order' => 'DESC',
        ));
        $detail_id = absint($_GET['gps_staging_item_id'] ?? 0);
        $detail_post = $detail_id ? get_post($detail_id) : null;
        if ($detail_post && $detail_post->post_type !== self::STAGING_POST_TYPE) {
            $detail_post = null;
        }
        ?>
        <p class="description"><?php esc_html_e('Queue actions are item-scoped. They do not create Woo products unless you explicitly use Create Woo Draft Product, and that action creates draft products only.', 'gps-gmail-product-importer'); ?></p>
        <?php if (!$items) : ?>
            <p><?php esc_html_e('No staged import queue records found yet. Run Stage Gmail messages to create queue items.', 'gps-gmail-product-importer'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="margin-top:10px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Staging item ID', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Created date', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Updated date', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Gmail subject', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Storage location', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Detected part code', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Detected OEM', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Vehicle make/model', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Images count', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Staging status', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Woo draft readiness status', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Created product ID', 'gps-gmail-product-importer'); ?></th>
                        <th><?php esc_html_e('Actions', 'gps-gmail-product-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <?php
                        $item_id = (int) $item->ID;
                        $created_product_id = absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true));
                        $current_readiness = $this->woo_draft_readiness_status($this->analysis_from_staging_item($item_id), (string) get_post_meta($item_id, '_gps_staging_status', true), $created_product_id);
                        $readiness = $current_readiness['status'];
                        $is_ready = $current_readiness['status'] === 'ready_to_create_product' && empty($current_readiness['blocking_reasons']);
                        $vehicle = trim(get_post_meta($item_id, '_gps_detected_vehicle_make', true) . ' ' . get_post_meta($item_id, '_gps_detected_vehicle_model', true));
                        ?>
                        <tr>
                            <td><?php echo esc_html($item_id); ?></td>
                            <td><?php echo esc_html(get_date_from_gmt($item->post_date_gmt ?: $item->post_date, 'Y-m-d H:i:s')); ?></td>
                            <td><?php echo esc_html(get_date_from_gmt($item->post_modified_gmt ?: $item->post_modified, 'Y-m-d H:i:s')); ?></td>
                            <td><?php echo esc_html(get_post_meta($item_id, '_gps_gmail_subject', true) ?: $item->post_title); ?></td>
                            <td><?php echo esc_html(get_post_meta($item_id, '_gps_storage_location', true)); ?></td>
                            <td><?php echo esc_html(get_post_meta($item_id, '_gps_detected_part_code', true)); ?></td>
                            <td><?php echo esc_html(get_post_meta($item_id, '_gps_detected_oem_part_number', true)); ?></td>
                            <td><?php echo esc_html($vehicle); ?></td>
                            <td><?php echo esc_html(absint(get_post_meta($item_id, '_gps_gmail_import_image_count', true))); ?></td>
                            <td><?php echo esc_html(get_post_meta($item_id, '_gps_staging_status', true)); ?></td>
                            <td><?php echo esc_html($readiness); ?></td>
                            <td><?php echo esc_html($created_product_id); ?></td>
                            <td><?php $this->render_import_queue_item_actions($item_id, $is_ready); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if ($detail_post) : ?>
            <?php $this->render_import_queue_detail($detail_post); ?>
        <?php elseif ($detail_id) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Import queue item not found.', 'gps-gmail-product-importer'); ?></p></div>
        <?php endif;
    }

    private function render_reset_created_product_link_form($item_id)
    {
        $created_product_id = absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true));
        if ($created_product_id <= 0) {
            return;
        }

        $linked_product = get_post($created_product_id);
        $linked_product_exists = (bool) $linked_product;
        $linked_product_status = $linked_product_exists ? (string) ($linked_product->post_status ?? '') : '';
        $requires_force = $linked_product_exists && $linked_product_status !== 'trash';
        ?>
        <div style="max-width:720px;margin:12px 0 16px;padding:12px;background:#fff;border:1px solid #ccd0d4;">
            <h4 style="margin-top:0;"><?php esc_html_e('Reset created Woo product link', 'gps-gmail-product-importer'); ?></h4>
            <table class="widefat striped" style="margin-bottom:12px;">
                <tbody>
                    <tr><th style="width:260px;"><?php esc_html_e('Linked created product ID', 'gps-gmail-product-importer'); ?></th><td><?php echo esc_html($created_product_id); ?></td></tr>
                    <tr><th><?php esc_html_e('Linked product exists', 'gps-gmail-product-importer'); ?></th><td><?php echo esc_html($linked_product_exists ? __('Yes', 'gps-gmail-product-importer') : __('No', 'gps-gmail-product-importer')); ?></td></tr>
                    <tr><th><?php esc_html_e('Linked product post status', 'gps-gmail-product-importer'); ?></th><td><?php echo esc_html($linked_product_exists ? ($linked_product_status ?: __('unknown', 'gps-gmail-product-importer')) : __('missing/deleted', 'gps-gmail-product-importer')); ?></td></tr>
                </tbody>
            </table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="gps_gmail_product_importer_queue_item_action">
                <input type="hidden" name="queue_action" value="reset_created_woo_product_link">
                <input type="hidden" name="staging_item_id" value="<?php echo esc_attr($item_id); ?>">
                <p class="description"><?php esc_html_e('Clears only the staging item created-product link and refreshes readiness. It never deletes or creates Woo products.', 'gps-gmail-product-importer'); ?></p>
                <?php if ($requires_force) : ?>
                    <p><label><input type="checkbox" name="force_reset_created_product_link" value="1"> <?php esc_html_e('Force reset even if the linked Woo product still exists and is not in trash', 'gps-gmail-product-importer'); ?></label></p>
                <?php endif; ?>
                <?php submit_button(__('Reset created Woo product link', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private function render_import_queue_item_actions($item_id, $is_ready)
    {
        $detail_url = add_query_arg(array('page' => 'gps-gmail-product-importer', 'gps_staging_item_id' => $item_id), admin_url('admin.php'));
        echo '<p style="margin:0 0 4px;"><a class="button button-small" href="' . esc_url($detail_url) . '#gps-import-queue-detail">' . esc_html__('View details', 'gps-gmail-product-importer') . '</a></p>';
        $actions = array(
            'full_preparation' => __('Run full preparation for this item', 'gps-gmail-product-importer'),
            'ovoko_enrichment' => __('Run Ovoko enrichment for this item', 'gps-gmail-product-importer'),
            'ovoko_category_suggestion' => __('Run Ovoko category suggestion by code for this item', 'gps-gmail-product-importer'),
            'allegro_price_research' => __('Optional legacy diagnostics: run Allegro price research (not production pricing)', 'gps-gmail-product-importer'),
            'ovoko_price_suggestion' => __('Run Ovoko price suggestion for this item', 'gps-gmail-product-importer'),
            'category_mapping' => __('Run category mapping for this item', 'gps-gmail-product-importer'),
            'readiness_validation' => __('Run readiness validation for this item', 'gps-gmail-product-importer'),
            'create_woo_draft' => __('Create Woo Draft Product', 'gps-gmail-product-importer'),
        );
        foreach ($actions as $action => $label) {
            $disabled = $action === 'create_woo_draft' && !$is_ready;
            $button_class = $action === 'full_preparation' ? 'button button-primary button-small' : 'button button-small';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0 0 4px;">';
            wp_nonce_field(self::NONCE_ACTION);
            echo '<input type="hidden" name="action" value="gps_gmail_product_importer_queue_item_action">';
            echo '<input type="hidden" name="queue_action" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="staging_item_id" value="' . esc_attr($item_id) . '">';
            echo '<button type="submit" class="' . esc_attr($button_class) . '"' . ($disabled ? ' disabled="disabled"' : '') . '>' . esc_html($label) . '</button>';
            echo '</form>';
        }
    }

    private function render_import_queue_detail($post)
    {
        $item_id = (int) $post->ID;
        $raw_meta = get_post_meta($item_id);
        $normalized_meta = array();
        foreach ($raw_meta as $key => $values) {
            $value = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
            $decoded = is_string($value) ? json_decode($value, true) : null;
            $normalized_meta[$key] = is_array($decoded) ? $decoded : $value;
        }
        $groups = array(
            __('Gmail metadata', 'gps-gmail-product-importer') => array('_gps_gmail_message_id', '_gps_gmail_thread_id', '_gps_gmail_date', '_gps_gmail_from', '_gps_gmail_subject', '_gps_gmail_label', '_gps_gmail_query_used', '_gps_gmail_message_status_filter'),
            __('Body excerpt', 'gps-gmail-product-importer') => array('_gps_gmail_body_excerpt'),
            __('Storage and part detection', 'gps-gmail-product-importer') => array('_gps_storage_location', '_gps_detected_part_code', '_gps_normalized_part_code', '_gps_detected_oem_part_number', '_gps_normalized_oem_part_number'),
            __('OEM candidates', 'gps-gmail-product-importer') => array('_gps_gmail_import_oem_candidates'),
            __('Vehicle detection', 'gps-gmail-product-importer') => array('_gps_detected_vehicle_make', '_gps_detected_vehicle_model', '_gps_detected_vehicle_confidence'),
            __('Images metadata', 'gps-gmail-product-importer') => array('_gps_gmail_import_image_count', '_gps_gmail_import_attachment_set_hash', '_gps_gmail_images_metadata'),
            __('Duplicate status', 'gps-gmail-product-importer') => array('_gps_duplicate_status', '_gps_duplicate_existing_product_id'),
            __('Existing Ovoko part match', 'gps-gmail-product-importer') => array('_gps_ovoko_enrichment_status', '_gps_ovoko_enrichment_checked_at', '_gps_ovoko_lookup_oem', '_gps_ovoko_match_count', '_gps_ovoko_selected_match_id', '_gps_ovoko_confidence', '_gps_ovoko_vehicle_make', '_gps_ovoko_vehicle_model', '_gps_ovoko_vehicle_generation', '_gps_ovoko_vehicle_year', '_gps_ovoko_engine_code', '_gps_ovoko_engine_capacity', '_gps_ovoko_fuel_type', '_gps_ovoko_gearbox_type', '_gps_ovoko_power', '_gps_ovoko_mileage', '_gps_ovoko_part_name', '_gps_ovoko_category_id', '_gps_ovoko_category_name', '_gps_ovoko_category_path', '_gps_ovoko_part_category', '_gps_ovoko_raw_category_data', '_gps_ovoko_raw_selected_match', '_gps_ovoko_oem_numbers', '_gps_ovoko_raw_match_summary'),
            __('Ovoko category-by-code suggestion unavailable / endpoint not confirmed', 'gps-gmail-product-importer') => array('_gps_ovoko_category_suggestion_status', '_gps_ovoko_category_suggestion_code', '_gps_ovoko_category_suggestion_confidence', '_gps_ovoko_category_suggestion_source_type', '_gps_ovoko_category_suggestion_category_id', '_gps_ovoko_category_suggestion_category_name', '_gps_ovoko_category_review_required', '_gps_ovoko_category_review_reason', '_gps_ovoko_category_suggestion_source', '_gps_ovoko_category_suggestion_checked_at', '_gps_ovoko_category_suggestion_raw_response'),
            __('Ovoko price suggestion fields', 'gps-gmail-product-importer') => array('_gps_ovoko_price_suggestion_status', '_gps_ovoko_price_suggestion_pln', '_gps_ovoko_price_suggestion_source', '_gps_ovoko_price_suggestion_currency', '_gps_ovoko_price_suggestion_raw_value', '_gps_ovoko_price_suggestion_checked_at', '_gps_ovoko_price_suggestion_notes'),
            __('Allegro price fields (optional legacy diagnostics, not production pricing)', 'gps-gmail-product-importer') => array('_gps_allegro_price_research_status', '_gps_allegro_price_research_checked_at', '_gps_allegro_price_query', '_gps_allegro_price_raw_offer_count', '_gps_allegro_price_filtered_offer_count', '_gps_allegro_price_median_pln', '_gps_allegro_price_min_pln', '_gps_allegro_price_max_pln', '_gps_allegro_price_confidence', '_gps_allegro_price_sample_offer_urls', '_gps_allegro_price_source', '_gps_allegro_price_suggestion', '_gps_allegro_price_currency', '_gps_allegro_price_notes', '_gps_allegro_price_error_http_status', '_gps_allegro_price_error_response', '_gps_allegro_price_error_code', '_gps_allegro_price_error_checked_at'),
            __('Manual price override fields', 'gps-gmail-product-importer') => array('_gps_manual_price_override_enabled', '_gps_manual_price_pln', '_gps_manual_price_note', '_gps_manual_price_set_at', '_gps_manual_price_set_by'),
            __('Selected price fields', 'gps-gmail-product-importer') => array('_gps_selected_price_pln', '_gps_selected_price_source', '_gps_selected_price_checked_at', '_gps_price_required_for_woo_draft', '_gps_price_required_for_crm_only_import', '_gps_empty_price_allowed_reason'),
            __('Category mapping fields', 'gps-gmail-product-importer') => array('_gps_category_mapping_status', '_gps_category_mapping_checked_at', '_gps_suggested_woo_category_id', '_gps_suggested_woo_category_path', '_gps_suggested_woo_category_confidence', '_gps_suggested_category_source'),
            __('Shipping group', 'gps-gmail-product-importer') => array('_gps_shipping_group'),
            __('Woo draft readiness status', 'gps-gmail-product-importer') => array('_gps_woo_draft_readiness_status', '_gps_woo_draft_readiness_checked_at'),
            __('Woo draft blocking reasons', 'gps-gmail-product-importer') => array('_gps_woo_draft_blocking_reasons'),
            __('Marketplace readiness status', 'gps-gmail-product-importer') => array('_gps_marketplace_readiness_status', '_gps_marketplace_readiness_checked_at'),
            __('Marketplace blocking reasons', 'gps-gmail-product-importer') => array('_gps_marketplace_blocking_reasons'),
            __('Legacy readiness status', 'gps-gmail-product-importer') => array('_gps_readiness_status', '_gps_readiness_checked_at'),
            __('Legacy blocking reasons', 'gps-gmail-product-importer') => array('_gps_blocking_reasons'),
            __('Created product ID', 'gps-gmail-product-importer') => array('_gps_gmail_created_product_id', '_gps_gmail_created_product_at', '_gps_gmail_created_product_checked_at', '_gps_gmail_created_product_status'),
        );
        $selected_price_source = (string) ($normalized_meta['_gps_selected_price_source'] ?? '');
        $price_readiness_label = $selected_price_source === 'manual_override' ? __('manual price override', 'gps-gmail-product-importer') : ($selected_price_source === 'ovoko_price_suggestion' ? __('Ovoko price suggestion', 'gps-gmail-product-importer') : __('no selected price yet', 'gps-gmail-product-importer'));
        $allegro_unavailable_message = $this->allegro_unavailable_message_from_meta($normalized_meta);
        ?>
        <div id="gps-import-queue-detail" style="margin-top:20px;">
            <h3><?php echo esc_html(sprintf(__('Import Queue Item #%d Details', 'gps-gmail-product-importer'), $item_id)); ?></h3>
            <p><strong><?php esc_html_e('Woo draft price readiness source:', 'gps-gmail-product-importer'); ?></strong> <?php echo esc_html($price_readiness_label); ?></p>
            <p><strong><?php esc_html_e('Price source priority:', 'gps-gmail-product-importer'); ?></strong> <?php esc_html_e('manual_override → ovoko_price_suggestion → no selected price', 'gps-gmail-product-importer'); ?></p>
            <p class="description"><?php esc_html_e('Allegro price research is disabled / optional / not used in production flow.', 'gps-gmail-product-importer'); ?></p>
            <p class="description"><?php esc_html_e('Ovoko panel category prediction is required for no-match items. If unresolved, run Test Ovoko category prediction by code with the staged code and paste a redacted Ovoko panel Network capture; category-tree/catalog/manual mapping is not accepted as prediction.', 'gps-gmail-product-importer'); ?></p>
            <?php if ($allegro_unavailable_message !== '') : ?><p class="description"><strong><?php esc_html_e('Optional Allegro diagnostic note:', 'gps-gmail-product-importer'); ?></strong> <?php echo esc_html($allegro_unavailable_message); ?></p><?php endif; ?>
            <?php foreach ($groups as $label => $keys) : ?>
                <h4><?php echo esc_html($label); ?></h4>
                <table class="widefat striped" style="max-width:1100px;margin-bottom:12px;">
                    <tbody>
                        <?php foreach ($keys as $key) : ?>
                            <tr><th style="width:260px;"><?php echo esc_html($key); ?></th><td><pre style="white-space:pre-wrap;margin:0;"><?php echo esc_html($this->format_meta_value($normalized_meta[$key] ?? '')); ?></pre></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
            <?php $this->render_reset_created_product_link_form($item_id); ?>
            <h4><?php esc_html_e('Set manual price override', 'gps-gmail-product-importer'); ?></h4>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px;margin-bottom:16px;padding:12px;background:#fff;border:1px solid #ccd0d4;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="gps_gmail_product_importer_queue_item_action">
                <input type="hidden" name="queue_action" value="manual_price_override">
                <input type="hidden" name="staging_item_id" value="<?php echo esc_attr($item_id); ?>">
                <p><label><?php esc_html_e('Price PLN', 'gps-gmail-product-importer'); ?> <input type="number" min="0.01" step="0.01" name="manual_price_pln" value="<?php echo esc_attr($normalized_meta['_gps_manual_price_pln'] ?? ''); ?>" required></label></p>
                <p><label><?php esc_html_e('Note/reason', 'gps-gmail-product-importer'); ?><br><textarea name="manual_price_note" rows="3" style="width:100%;"><?php echo esc_textarea((string) ($normalized_meta['_gps_manual_price_note'] ?? '')); ?></textarea></label></p>
                <p class="description"><?php esc_html_e('Manual price is stored as manual_override staging meta only. It does not create Woo products and does not mark Allegro research as completed.', 'gps-gmail-product-importer'); ?></p>
                <?php submit_button(__('Set/update manual price override', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>
            <h4><?php esc_html_e('Full message body', 'gps-gmail-product-importer'); ?></h4>
            <pre style="white-space:pre-wrap;max-height:280px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;"><?php echo esc_html($post->post_content); ?></pre>
            <h4><?php esc_html_e('Raw stored meta', 'gps-gmail-product-importer'); ?></h4>
            <pre style="white-space:pre-wrap;max-height:420px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:10px;"><?php echo esc_html(wp_json_encode($normalized_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
        <?php
    }

    private function format_meta_value($value)
    {
        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string) $value;
    }

    private function oauth_url()
    {
        $settings = $this->settings();
        $state = wp_create_nonce('gps_gmail_product_importer_oauth');
        return add_query_arg(array(
            'client_id' => $settings['google_client_id'],
            'redirect_uri' => $this->redirect_uri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/userinfo.email',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ), 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    private function redirect_uri()
    {
        return admin_url('admin.php?page=gps-gmail-product-importer');
    }

    public function maybe_handle_oauth_callback()
    {
        if (!is_admin() || ($_GET['page'] ?? '') !== 'gps-gmail-product-importer' || empty($_GET['code'])) {
            return;
        }
        if (!current_user_can('manage_options') || empty($_GET['state']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['state'])), 'gps_gmail_product_importer_oauth')) {
            wp_die(esc_html__('Invalid Gmail OAuth request.', 'gps-gmail-product-importer'));
        }
        $settings = $this->settings();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'code' => sanitize_text_field(wp_unslash($_GET['code'])),
                'client_id' => $settings['google_client_id'],
                'client_secret' => $settings['google_client_secret'],
                'redirect_uri' => $this->redirect_uri(),
                'grant_type' => 'authorization_code',
            ),
        ));
        $body = $this->json_response($response);
        if (is_wp_error($body)) {
            set_transient('gps_gmail_product_importer_last_admin_result', array('error' => $body->get_error_message()), 60);
            wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
            exit;
        }
        $this->store_tokens($body);
        $profile = $this->gmail_request('https://www.googleapis.com/oauth2/v2/userinfo');
        if (!is_wp_error($profile) && !empty($profile['email'])) {
            update_option(self::OPTION_CONNECTED_EMAIL, sanitize_email($profile['email']), false);
        }
        set_transient('gps_gmail_product_importer_last_admin_result', array('connected' => true, 'account' => get_option(self::OPTION_CONNECTED_EMAIL, '')), 60);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_disconnect()
    {
        $this->verify_admin_action();
        delete_option(self::OPTION_TOKENS);
        delete_option(self::OPTION_CONNECTED_EMAIL);
        set_transient('gps_gmail_product_importer_last_admin_result', array('disconnected' => true), 60);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_test()
    {
        $this->verify_admin_action();
        $result = $this->test_connection();
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 60);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_dry_run()
    {
        $this->verify_admin_action();
        $result = $this->process_batch(true, max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_import()
    {
        $this->verify_admin_action();
        $result = $this->process_batch(false, max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_ovoko_test()
    {
        $this->verify_admin_action();
        $oem = sanitize_text_field(wp_unslash($_POST['oem'] ?? ''));
        $result = $this->test_ovoko_oem_lookup($oem);
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_allegro_test()
    {
        $this->verify_admin_action();
        $result = $this->test_allegro_api_connection();
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_ovoko_enrichment_dry_run()
    {
        $this->verify_admin_action();
        $result = $this->process_ovoko_enrichment_batch(true, max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_ovoko_enrichment_save()
    {
        $this->verify_admin_action();
        $result = $this->process_ovoko_enrichment_batch(false, max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    private function current_user_can_manage_importer()
    {
        return current_user_can('manage_options') || current_user_can('manage_woocommerce');
    }

    public function ajax_import_batch()
    {
        if (!$this->current_user_can_manage_importer() || !check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }
        if (!empty($_POST['stop'])) {
            $state = $this->run_state();
            $state['state'] = 'stopped';
            $state['stopped_reason'] = 'admin_requested';
            update_option(self::OPTION_RUN_STATE, $state, false);
            wp_send_json_success($state);
        }
        $result = $this->process_batch(false, max(1, min(25, absint($_POST['batch_size'] ?? 5))), true);
        wp_send_json_success($result);
    }

    public function ajax_auto_runner_batch()
    {
        if (!$this->current_user_can_manage_importer() || !check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        if (!empty($_POST['stop'])) {
            $state = $this->auto_runner_state();
            $state['state'] = 'stopping';
            $state['stopped_reason'] = 'admin_requested';
            $state['state'] = 'stopped';
            update_option(self::OPTION_RUN_STATE, $state, false);
            wp_send_json_success($state);
        }

        $mode = $this->sanitize_auto_runner_mode($_POST['mode'] ?? 'scan_gmail_stage');
        $batch_size = max(1, min(25, absint($_POST['batch_size'] ?? 10)));
        $reset = !empty($_POST['reset']);
        $state = $reset ? $this->fresh_auto_runner_state($mode) : $this->auto_runner_state();
        if (($state['mode'] ?? '') !== $mode || !in_array((string) ($state['state'] ?? ''), array('running', 'stopping'), true)) {
            $state = $this->fresh_auto_runner_state($mode);
        }
        if (($state['state'] ?? '') === 'stopping') {
            $state['state'] = 'stopped';
            $state['stopped_reason'] = $state['stopped_reason'] ?: 'admin_requested';
            update_option(self::OPTION_RUN_STATE, $state, false);
            wp_send_json_success($state);
        }

        try {
            $state['state'] = 'running';
            $state['batch_size'] = $batch_size;
            $result = $this->run_auto_runner_batch($state, $batch_size);
            update_option(self::OPTION_RUN_STATE, $result, false);
            wp_send_json_success($result);
        } catch (Throwable $e) {
            $state['state'] = 'error';
            $state['total_errors'] = absint($state['total_errors'] ?? 0) + 1;
            $state['stopped_reason'] = $e->getMessage();
            $state['last_batch_result'] = array('fatal_error' => $e->getMessage());
            update_option(self::OPTION_RUN_STATE, $state, false);
            wp_send_json_success($state);
        }
    }

    private function sanitize_auto_runner_mode($mode)
    {
        $mode = sanitize_key((string) $mode);
        return in_array($mode, array('scan_gmail_dry_run', 'scan_gmail_stage', 'prepare_audit', 'create_woo_drafts', 'full_pipeline_safe'), true) ? $mode : 'scan_gmail_stage';
    }

    private function fresh_auto_runner_state($mode)
    {
        return array_merge($this->fresh_run_state(), array(
            'mode' => $this->sanitize_auto_runner_mode($mode),
            'pipeline_stage' => $mode === 'full_pipeline_safe' ? 'scan_gmail_stage' : '',
            'state' => 'running',
            'stopped_reason' => '',
            'total_prepared' => 0,
            'total_created' => 0,
            'total_blocked' => 0,
            'remaining_candidates' => 0,
            'processed_staging_item_ids' => array(),
            'processed_gmail_message_ids' => array(),
            'last_batch_result' => array(),
        ));
    }

    private function auto_runner_state()
    {
        $saved = (array) get_option(self::OPTION_RUN_STATE, array());
        return wp_parse_args($saved, $this->fresh_auto_runner_state($this->sanitize_auto_runner_mode($saved['mode'] ?? 'scan_gmail_stage')));
    }

    private function run_auto_runner_batch($state, $batch_size)
    {
        $mode = (string) ($state['mode'] ?? 'scan_gmail_stage');
        $effective_mode = $mode === 'full_pipeline_safe' ? (string) ($state['pipeline_stage'] ?? 'scan_gmail_stage') : $mode;
        if ($mode === 'full_pipeline_safe' && $effective_mode === '') {
            $effective_mode = 'scan_gmail_stage';
            $state['pipeline_stage'] = $effective_mode;
        }

        if ($effective_mode === 'scan_gmail_dry_run' || $effective_mode === 'scan_gmail_stage') {
            $state = $this->run_auto_gmail_scan_batch($state, $batch_size, $effective_mode === 'scan_gmail_dry_run');
        } elseif ($effective_mode === 'prepare_audit') {
            $state = $this->run_auto_prepare_audit_batch($state, $batch_size);
        } elseif ($effective_mode === 'create_woo_drafts') {
            $state = $this->run_auto_create_woo_drafts_batch($state, $batch_size);
        } else {
            $state['state'] = 'error';
            $state['total_errors'] = absint($state['total_errors'] ?? 0) + 1;
            $state['stopped_reason'] = 'unknown_auto_runner_mode';
        }

        if ($mode === 'full_pipeline_safe') {
            $state = $this->advance_full_pipeline_if_stage_completed($state);
        }
        $state['total_created'] = absint($state['total_created'] ?? $state['total_products_created'] ?? 0);
        return $state;
    }

    private function run_auto_gmail_scan_batch($state, $batch_size, $dry_run)
    {
        update_option(self::OPTION_RUN_STATE, $state, false);
        $before = $state;
        $result = $this->process_batch($dry_run, $batch_size, true);
        $state = array_merge($state, $result);
        $items = (array) ($result['last_batch_result'] ?? array());
        $message_ids = array_values(array_filter(array_map(function ($item) { return is_array($item) ? (string) ($item['message_id'] ?? '') : ''; }, $items)));
        $already_seen = $message_ids && count(array_diff($message_ids, (array) ($before['processed_gmail_message_ids'] ?? array()))) === 0;
        $state['processed_gmail_message_ids'] = array_values(array_unique(array_merge((array) ($before['processed_gmail_message_ids'] ?? array()), $message_ids)));
        $state['remaining_candidates'] = absint($result['remaining_messages'] ?? 0);
        $state['last_batch_result'] = array('mode' => $dry_run ? 'scan_gmail_dry_run' : 'scan_gmail_stage', 'batch' => $items, 'remaining_messages' => $state['remaining_candidates'], 'no_woo_product_created' => true, 'no_ovoko_live_import' => true, 'no_allegro_call' => true, 'no_ebay_call' => true);
        if (($result['state'] ?? '') === 'error' || ($result['state'] ?? '') === 'locked') {
            $state['state'] = 'error';
            $state['stopped_reason'] = $result['stopped_reason'] ?? 'gmail_scan_error';
            return $state;
        }
        $stage_progress = (absint($state['total_staged'] ?? 0) > absint($before['total_staged'] ?? 0)) || (absint($state['total_stage_updated'] ?? 0) > absint($before['total_stage_updated'] ?? 0));
        if (($result['state'] ?? '') === 'complete') {
            $state['state'] = 'completed';
            $state['stopped_reason'] = $result['stopped_reason'] ?: 'no_messages_returned';
        } elseif ($dry_run && $already_seen) {
            $state['state'] = 'completed';
            $state['stopped_reason'] = 'dry_run_does_not_write_seen_current_batch';
        } elseif (!$dry_run && $already_seen && !$stage_progress) {
            $state['state'] = 'completed';
            $state['stopped_reason'] = 'no_new_stage_progress_for_seen_current_batch';
        } else {
            $state['state'] = 'running';
        }
        return $state;
    }

    private function advance_full_pipeline_if_stage_completed($state)
    {
        if (($state['state'] ?? '') === 'error') {
            return $state;
        }
        if (($state['state'] ?? '') !== 'completed') {
            return $state;
        }
        $stage = (string) ($state['pipeline_stage'] ?? 'scan_gmail_stage');
        if ($stage === 'scan_gmail_stage') {
            $state['pipeline_stage'] = 'prepare_audit';
            $state['state'] = 'running';
            $state['stopped_reason'] = '';
            $state['last_batch_result']['pipeline_advanced_to'] = 'prepare_audit';
        } elseif ($stage === 'prepare_audit') {
            $state['pipeline_stage'] = 'create_woo_drafts';
            $state['state'] = 'running';
            $state['stopped_reason'] = '';
            $state['last_batch_result']['pipeline_advanced_to'] = 'create_woo_drafts';
        } elseif ($stage === 'create_woo_drafts') {
            $state['pipeline_stage'] = 'completed';
            $state['state'] = 'completed';
            $state['stopped_reason'] = $state['stopped_reason'] ?: 'full_pipeline_completed';
        }
        return $state;
    }

    private function run_auto_prepare_audit_batch($state, $batch_size)
    {
        $exclude = array_map('absint', (array) ($state['processed_staging_item_ids'] ?? array()));
        $ids = $this->auto_runner_staging_candidate_ids($batch_size, $exclude, false);
        if (!$ids) {
            $state['state'] = 'completed';
            $state['stopped_reason'] = 'no_staging_items_remaining';
            $state['remaining_candidates'] = 0;
            $state['last_batch_result'] = array('mode' => 'prepare_audit', 'items' => array(), 'no_woo_product_created' => true, 'no_ovoko_live_import' => true, 'no_allegro_call' => true, 'no_ebay_call' => true);
            return $state;
        }
        $items = array();
        foreach ($ids as $id) {
            $items[] = $this->run_full_preparation_for_staging_item((int) $id);
        }
        $blocked = count(array_filter($items, function ($item) { return (($item['result'] ?? '') !== 'ready_to_create_product'); }));
        $errors = count(array_filter($items, function ($item) { return (($item['result'] ?? '') === 'error') || !empty($item['fatal_error']); }));
        $state['processed_staging_item_ids'] = array_values(array_unique(array_merge($exclude, array_map('absint', $ids))));
        $state['batches_completed'] = absint($state['batches_completed'] ?? 0) + 1;
        $state['total_checked'] = absint($state['total_checked'] ?? 0) + count($ids);
        $state['total_prepared'] = absint($state['total_prepared'] ?? 0) + count($items);
        $state['total_blocked'] = absint($state['total_blocked'] ?? 0) + $blocked;
        $state['total_errors'] = absint($state['total_errors'] ?? 0) + $errors;
        $state['remaining_candidates'] = $this->auto_runner_staging_candidate_count($state['processed_staging_item_ids'], false);
        $state['last_batch_result'] = array('action' => 'full_preparation_batch', 'mode' => 'prepare_audit', 'result' => 'completed', 'batch_size' => $batch_size, 'total_processed' => count($items), 'items' => $items, 'writes' => 'staging_meta_only', 'no_woo_product_created' => true, 'no_ovoko_live_import' => true, 'no_allegro_call' => true, 'no_ebay_call' => true);
        $state['state'] = $state['remaining_candidates'] > 0 ? 'running' : 'completed';
        $state['stopped_reason'] = $state['state'] === 'completed' ? 'no_staging_items_remaining' : '';
        return $state;
    }

    private function run_auto_create_woo_drafts_batch($state, $batch_size)
    {
        $exclude = array_map('absint', (array) ($state['processed_staging_item_ids'] ?? array()));
        $result = $this->create_woo_drafts_from_ready_staging($batch_size, $exclude);
        $items = (array) ($result['items'] ?? array());
        $processed_ids = array_values(array_filter(array_map(function ($item) { return absint($item['staging_item_id'] ?? 0); }, $items)));
        $state['processed_staging_item_ids'] = array_values(array_unique(array_merge($exclude, $processed_ids)));
        $state['batches_completed'] = absint($state['batches_completed'] ?? 0) + 1;
        $state['total_checked'] = absint($state['total_checked'] ?? 0) + absint($result['total_checked'] ?? count($items));
        $state['total_created'] = absint($state['total_created'] ?? 0) + absint($result['created_count'] ?? 0);
        $state['total_products_created'] = absint($state['total_products_created'] ?? 0) + absint($result['created_count'] ?? 0);
        $state['total_skipped'] = absint($state['total_skipped'] ?? 0) + absint($result['skipped_count'] ?? 0);
        $state['total_blocked'] = absint($state['total_blocked'] ?? 0) + absint($result['skipped_count'] ?? 0);
        $state['total_errors'] = absint($state['total_errors'] ?? 0) + count((array) ($result['errors'] ?? array()));
        $state['remaining_candidates'] = $this->auto_runner_staging_candidate_count($state['processed_staging_item_ids'], true);
        $result['mode'] = 'create_woo_drafts';
        $result['no_ovoko_live_import'] = true;
        $result['no_allegro_call'] = true;
        $result['no_ebay_call'] = true;
        $result['product_status'] = 'draft';
        $state['last_batch_result'] = $result;
        $state['state'] = ($state['remaining_candidates'] > 0 && ($items || absint($result['created_count'] ?? 0) > 0)) ? 'running' : 'completed';
        $state['stopped_reason'] = $state['state'] === 'completed' ? 'no_ready_or_unprocessed_staging_items_remaining' : '';
        return $state;
    }

    private function auto_runner_staging_candidate_ids($limit, $exclude_ids = array(), $only_uncreated = false)
    {
        $args = array('post_type' => self::STAGING_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => max(1, min(100, absint($limit))), 'orderby' => 'ID', 'order' => 'ASC');
        $exclude_ids = array_values(array_filter(array_map('absint', (array) $exclude_ids)));
        if ($exclude_ids) {
            $args['post__not_in'] = $exclude_ids;
        }
        if ($only_uncreated) {
            $args['meta_query'] = array('relation' => 'OR', array('key' => '_gps_gmail_created_product_id', 'compare' => 'NOT EXISTS'), array('key' => '_gps_gmail_created_product_id', 'value' => array('', '0'), 'compare' => 'IN'));
        }
        return array_map('absint', get_posts($args));
    }

    private function auto_runner_staging_candidate_count($exclude_ids = array(), $only_uncreated = false)
    {
        return count($this->auto_runner_staging_candidate_ids(1000, $exclude_ids, $only_uncreated));
    }

    private function verify_admin_action()
    {
        if (!$this->current_user_can_manage_importer()) {
            wp_die(esc_html__('Forbidden', 'gps-gmail-product-importer'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    private function test_connection()
    {
        $labels = $this->gmail_request('https://gmail.googleapis.com/gmail/v1/users/me/labels');
        if (is_wp_error($labels)) {
            return array('ok' => false, 'error' => $labels->get_error_message());
        }
        return array('ok' => true, 'connected_account' => get_option(self::OPTION_CONNECTED_EMAIL, ''), 'labels_found' => count($labels['labels'] ?? array()));
    }

    private function process_batch($dry_run, $batch_size, $auto = false)
    {
        if (!$dry_run && get_transient(self::LOCK_KEY)) {
            return array_merge($this->run_state(), array('state' => 'locked', 'stopped_reason' => 'concurrent_request_blocked'));
        }
        if (!$dry_run) {
            set_transient(self::LOCK_KEY, 1, 120);
        }
        try {
            $run_id = gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
            $settings = $this->settings();
            $label = $settings['gmail_label'];
            $message_status_filter = $this->sanitize_message_status_filter($settings['message_status_filter'] ?? 'read');
            $label_id = $this->find_label_id($label);
            if (is_wp_error($label_id)) {
                throw new Exception($label_id->get_error_message());
            }
            $message_list_request = $this->message_list_request($label_id, $label, $batch_size, $message_status_filter);
            $messages = $this->list_messages($message_list_request);
            if (is_wp_error($messages)) {
                throw new Exception($messages->get_error_message());
            }
            $state = $auto ? $this->run_state() : $this->fresh_run_state();
            $state['state'] = 'running';
            $state['gmail_query_used'] = $message_list_request['gmail_query_used'];
            $state['gmail_label_used'] = $message_list_request['gmail_label_used'];
            $state['message_status_filter'] = $message_list_request['message_status_filter'];
            $state['remaining_messages'] = max(0, absint($messages['resultSizeEstimate'] ?? 0) - count($messages['messages'] ?? array()));
            $items = array();
            foreach (($messages['messages'] ?? array()) as $message_ref) {
                $state['total_checked']++;
                $message = $this->get_message($message_ref['id']);
                if (is_wp_error($message)) {
                    $state['total_errors']++;
                    $this->write_report_row('errors', $this->empty_report_row($run_id, $dry_run, array('gmail_message_id' => $message_ref['id'], 'gmail_query_used' => $message_list_request['gmail_query_used'], 'gmail_label_used' => $message_list_request['gmail_label_used'], 'message_status_filter' => $message_list_request['message_status_filter'], 'action' => 'read_message', 'result' => 'error', 'error_message' => $message->get_error_message())));
                    continue;
                }
                if (!$this->message_matches_status_filter($message, $message_status_filter)) {
                    continue;
                }
                $analysis = $this->analyze_message($message, $label, $message_list_request);
                $row = $this->report_row_from_analysis($run_id, $dry_run, $analysis);
                if ($dry_run) {
                    $row['action'] = 'dry_run_stage';
                    $row['result'] = $analysis['duplicate_status'] ?: 'would_stage';
                    $this->write_report_row('actions', $row);
                    $items[] = $analysis;
                    continue;
                }
                $staged = $this->stage_message_analysis($analysis);
                if (is_wp_error($staged)) {
                    $state['total_errors']++;
                    if (($analysis['gmail_was_unread'] ?? $analysis['gmail_is_unread'] ?? '') === 'yes') {
                        $state['messages_left_unread_due_to_errors']++;
                    }
                    $analysis['gmail_mark_read_status'] = 'not_attempted_staging_error';
                    $row['gmail_mark_read_status'] = $analysis['gmail_mark_read_status'];
                    $row['action'] = 'stage_message';
                    $row['result'] = 'error';
                    $row['error_message'] = $staged->get_error_message();
                    $this->write_report_row('errors', $row);
                } else {
                    $analysis = array_merge($analysis, $staged);
                    $row['staging_item_id'] = $staged['staging_item_id'];
                    $row['staging_status'] = $staged['staging_status'];
                    $row['created_product_id'] = $staged['created_product_id'];
                    $row['duplicate_status'] = $analysis['duplicate_status'];
                    $row['action'] = $staged['stage_action'];
                    $row['result'] = $staged['staging_status'];
                    $this->maybe_mark_staged_gmail_message_read($analysis, $settings, $state, $row);
                    if ($staged['stage_action'] === 'created_staging_item') {
                        $state['total_staged']++;
                    } elseif ($staged['stage_action'] === 'updated_staging_item') {
                        $state['total_stage_updated']++;
                    } elseif ($analysis['duplicate_status']) {
                        $state['total_duplicates']++;
                    } else {
                        $state['total_skipped']++;
                    }
                    $this->write_report_row('actions', $row);
                }
                $items[] = $analysis;
            }
            $state['batches_completed']++;
            $state['last_batch_result'] = $items;
            if (empty($messages['messages'])) {
                $state['state'] = 'complete';
                $state['stopped_reason'] = 'no_messages_returned';
            }
            update_option(self::OPTION_RUN_STATE, $state, false);
            $this->write_last_run($state);
            return $state;
        } catch (Exception $e) {
            $state = $this->run_state();
            $state['state'] = 'error';
            $state['total_errors']++;
            $state['stopped_reason'] = $e->getMessage();
            update_option(self::OPTION_RUN_STATE, $state, false);
            return $state;
        } finally {
            if (!$dry_run) {
                delete_transient(self::LOCK_KEY);
            }
        }
    }

    private function fresh_run_state()
    {
        return array('batches_completed' => 0, 'total_checked' => 0, 'total_staged' => 0, 'total_stage_updated' => 0, 'total_duplicates' => 0, 'total_skipped' => 0, 'total_products_created' => 0, 'total_errors' => 0, 'total_marked_read' => 0, 'total_mark_read_failed' => 0, 'messages_marked_read' => 0, 'messages_left_unread_due_to_errors' => 0, 'warnings' => array(), 'remaining_messages' => 0, 'gmail_query_used' => '', 'gmail_label_used' => '', 'message_status_filter' => $this->sanitize_message_status_filter($this->settings()['message_status_filter'] ?? 'read'), 'state' => 'idle', 'stopped_reason' => '', 'last_batch_result' => array());
    }

    private function run_state()
    {
        return wp_parse_args((array) get_option(self::OPTION_RUN_STATE, array()), $this->fresh_run_state());
    }

    private function maybe_mark_staged_gmail_message_read(&$analysis, $settings, &$state, &$row)
    {
        $analysis['gmail_marked_read'] = 'no';
        $analysis['gmail_mark_read_error'] = '';
        if (empty($settings['mark_gmail_read_after_staging'])) {
            $analysis['gmail_mark_read_status'] = 'disabled';
        } elseif (($analysis['gmail_was_unread'] ?? $analysis['gmail_is_unread'] ?? '') !== 'yes') {
            $analysis['gmail_mark_read_status'] = 'not_unread';
        } else {
            $marked = $this->mark_gmail_message_read($analysis['message_id'] ?? '');
            if (is_wp_error($marked)) {
                $analysis['gmail_mark_read_status'] = 'gmail_mark_read_failed';
                $analysis['gmail_mark_read_error'] = $marked->get_error_message();
                $analysis['warnings'] = array_values(array_unique(array_merge((array) ($analysis['warnings'] ?? array()), array('gmail_mark_read_failed'))));
                $state['total_mark_read_failed']++;
                $state['messages_left_unread_due_to_errors']++;
                $state['warnings'] = array_values(array_unique(array_merge((array) ($state['warnings'] ?? array()), array('gmail_mark_read_failed'))));
            } else {
                $analysis['gmail_marked_read'] = 'yes';
                $analysis['gmail_mark_read_status'] = 'marked_read';
                $state['total_marked_read']++;
                $state['messages_marked_read']++;
            }
        }
        $row['gmail_marked_read'] = $analysis['gmail_marked_read'];
        $row['gmail_mark_read_status'] = $analysis['gmail_mark_read_status'];
        $row['gmail_mark_read_error'] = $analysis['gmail_mark_read_error'];
        if ($analysis['gmail_mark_read_status'] === 'gmail_mark_read_failed') {
            $row['error_message'] = trim((string) ($row['error_message'] ?? '') . ' gmail_mark_read_failed: ' . $analysis['gmail_mark_read_error']);
        }
    }

    private function find_label_id($name)
    {
        $labels = $this->gmail_request('https://gmail.googleapis.com/gmail/v1/users/me/labels');
        if (is_wp_error($labels)) {
            return $labels;
        }
        foreach (($labels['labels'] ?? array()) as $label) {
            if (isset($label['name']) && strcasecmp($label['name'], $name) === 0) {
                return $label['id'];
            }
        }
        return new WP_Error('gps_gmail_label_missing', sprintf('Gmail label not found: %s', $name));
    }

    private function list_messages($message_list_request)
    {
        return $this->gmail_request($message_list_request['url']);
    }

    private function message_list_request($label_id, $label, $batch_size, $message_status_filter)
    {
        $message_status_filter = $this->sanitize_message_status_filter($message_status_filter);
        $label_ids = array($label_id);
        $query = '';
        if ($message_status_filter === 'unread') {
            $label_ids[] = 'UNREAD';
            $query = 'is:unread';
        } elseif ($message_status_filter === 'read') {
            $query = '-is:unread';
        }

        return array(
            'url' => 'https://gmail.googleapis.com/gmail/v1/users/me/messages?' . $this->query_string_with_repeated_values(array(
                'labelIds' => array_values(array_unique($label_ids)),
                'maxResults' => $batch_size,
                'q' => $query,
            )),
            'gmail_query_used' => $query,
            'gmail_label_used' => $label,
            'message_status_filter' => $message_status_filter,
        );
    }

    private function query_string_with_repeated_values($args)
    {
        $parts = array();
        foreach ($args as $key => $value) {
            if ($value === '' || $value === null || $value === array()) {
                continue;
            }
            foreach ((array) $value as $item) {
                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
            }
        }
        return implode('&', $parts);
    }

    private function sanitize_message_status_filter($value)
    {
        $value = sanitize_key($value);
        return in_array($value, array('all', 'read', 'unread'), true) ? $value : 'read';
    }

    private function message_matches_status_filter($message, $message_status_filter)
    {
        $is_unread = in_array('UNREAD', (array) ($message['labelIds'] ?? array()), true);
        if ($message_status_filter === 'unread') {
            return $is_unread;
        }
        if ($message_status_filter === 'read') {
            return !$is_unread;
        }
        return true;
    }

    private function get_message($message_id)
    {
        return $this->gmail_request(add_query_arg(array('format' => 'full'), 'https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($message_id)));
    }

    private function mark_gmail_message_read($message_id)
    {
        $message_id = sanitize_text_field($message_id);
        if ($message_id === '') {
            return new WP_Error('gps_gmail_message_id_missing', 'Gmail message ID is missing.');
        }
        return $this->gmail_request('https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($message_id) . '/modify', array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('removeLabelIds' => array('UNREAD'))),
        ));
    }

    private function analyze_message($message, $label, $message_list_request = array())
    {
        $headers = $this->headers($message);
        $subject = $headers['subject'] ?? '';
        $subject_parts = self::parse_gmail_subject($subject);
        $oem = array('selected' => $subject_parts['detected_part_code'], 'normalized' => $subject_parts['normalized_part_code'], 'candidates' => $subject_parts['oem_candidates']);
        $body = $this->extract_body($message['payload'] ?? array());
        $images = $this->image_parts($message['payload'] ?? array());
        $vehicle = $this->detect_vehicle($oem['selected'], $subject . ' ' . $body);
        $category = $this->suggest_category($subject . ' ' . $body, $vehicle);
        $attachment_hash = $this->attachment_set_hash($images);
        $duplicate = $this->duplicate_status($message['id'] ?? '', $oem['normalized'], $attachment_hash);
        $is_unread = in_array('UNREAD', (array) ($message['labelIds'] ?? array()), true);
        return array(
            'message_id' => sanitize_text_field($message['id'] ?? ''),
            'thread_id' => sanitize_text_field($message['threadId'] ?? ''),
            'date' => sanitize_text_field($headers['date'] ?? ''),
            'from' => sanitize_text_field($headers['from'] ?? ''),
            'subject' => sanitize_text_field($subject),
            'gmail_is_unread' => $is_unread ? 'yes' : 'no',
            'gmail_read_status' => $is_unread ? 'unread' : 'read',
            'gmail_was_unread' => $is_unread ? 'yes' : 'no',
            'gmail_marked_read' => 'no',
            'gmail_mark_read_status' => 'not_attempted',
            'gmail_mark_read_error' => '',
            'gmail_query_used' => sanitize_text_field($message_list_request['gmail_query_used'] ?? ''),
            'gmail_label_used' => sanitize_text_field($message_list_request['gmail_label_used'] ?? $label),
            'message_status_filter' => sanitize_text_field($message_list_request['message_status_filter'] ?? 'read'),
            'body' => wp_kses_post($body),
            'body_excerpt' => wp_trim_words(wp_strip_all_tags($body), 80, '…'),
            'label' => sanitize_text_field($label),
            'storage_location' => sanitize_text_field($subject_parts['storage_location']),
            'detected_part_code' => sanitize_text_field($subject_parts['detected_part_code']),
            'normalized_part_code' => sanitize_text_field($subject_parts['normalized_part_code']),
            'detected_oem_part_number' => $oem['selected'],
            'normalized_oem_part_number' => $oem['normalized'],
            'oem_candidates' => $oem['candidates'],
            'detected_vehicle_make' => $vehicle['make'],
            'detected_vehicle_model' => $vehicle['model'],
            'detected_vehicle_confidence' => $vehicle['confidence'],
            'suggested_woo_category_id' => $category['id'],
            'suggested_woo_category_path' => $category['path'],
            'suggested_woo_category_confidence' => $category['confidence'],
            'suggested_category_source' => $category['source'],
            'image_attachments_found' => count($images),
            'image_attachment_set_hash' => $attachment_hash,
            'images' => $images,
            'duplicate_status' => $duplicate['status'],
            'duplicate_existing_product_id' => $duplicate['product_id'],
            'warnings' => $this->warnings($oem, $images, $vehicle),
        );
    }

    private function headers($message)
    {
        $out = array();
        foreach (($message['payload']['headers'] ?? array()) as $header) {
            $out[strtolower($header['name'])] = $header['value'];
        }
        return $out;
    }

    public function extract_oem_candidates($subject)
    {
        $parsed = self::parse_gmail_subject($subject);
        return array('selected' => $parsed['detected_part_code'], 'normalized' => $parsed['normalized_part_code'], 'candidates' => $parsed['oem_candidates']);
    }

    public static function parse_gmail_subject($subject)
    {
        $subject = trim(preg_replace('/\s+/', ' ', (string) $subject));
        $tokens = $subject === '' ? array() : preg_split('/\s+/', $subject);
        $part_index = -1;
        $part_code = '';
        foreach ($tokens as $index => $token) {
            if (self::is_code_like_subject_token($token)) {
                $part_index = $index;
                $part_code = $token;
            }
        }
        $storage = $part_index > 0 ? implode(' ', array_slice($tokens, 0, $part_index)) : '';
        $normalized = self::normalize_oem_value($part_code);
        return array(
            'storage_location' => $storage,
            'detected_part_code' => $part_code,
            'normalized_part_code' => $normalized,
            'detected_oem_part_number' => $part_code,
            'normalized_oem_part_number' => $normalized,
            'oem_candidates' => $part_code === '' ? array() : array($part_code),
        );
    }

    private static function is_code_like_subject_token($token)
    {
        $normalized = self::normalize_oem_value($token);
        return strlen($normalized) >= 5 && preg_match('/\d/', $normalized);
    }

    private static function normalize_oem_value($value)
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));
    }

    private function normalize_oem($value)
    {
        return self::normalize_oem_value($value);
    }

    private function extract_body($payload)
    {
        $texts = array();
        $this->walk_parts($payload, function ($part) use (&$texts) {
            $mime = strtolower($part['mimeType'] ?? '');
            if (in_array($mime, array('text/plain', 'text/html'), true) && !empty($part['body']['data'])) {
                $decoded = $this->base64url_decode($part['body']['data']);
                $texts[] = $mime === 'text/html' ? wp_strip_all_tags($decoded) : $decoded;
            }
        });
        return trim(implode("\n\n", array_filter($texts)));
    }

    private function image_parts($payload)
    {
        $images = array();
        $allowed = array('image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
        $this->walk_parts($payload, function ($part) use (&$images, $allowed) {
            $mime = strtolower($part['mimeType'] ?? '');
            $filename = sanitize_file_name($part['filename'] ?? '');
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (isset($allowed[$mime]) || in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
                $images[] = array('attachment_id' => sanitize_text_field($part['body']['attachmentId'] ?? ''), 'filename' => $filename ?: ('gmail-image-' . (count($images) + 1) . '.' . ($allowed[$mime] ?? $ext)), 'mime' => $mime ?: 'image/' . $ext, 'size' => absint($part['body']['size'] ?? 0));
            }
        });
        return $images;
    }

    private function walk_parts($part, $callback)
    {
        $callback($part);
        foreach (($part['parts'] ?? array()) as $child) {
            $this->walk_parts($child, $callback);
        }
    }

    private function detect_vehicle($oem, $text)
    {
        $existing = $this->vehicle_from_existing_product($this->normalize_oem($oem));
        if ($existing['confidence'] === 'high') {
            return $existing;
        }
        $rules = array(
            'BMW' => array('SERIA 1', 'SERIA 3', 'SERIA 5', 'X1', 'X3', 'X5'),
            'AUDI' => array('A3', 'A4', 'A5', 'A6', 'Q3', 'Q5'),
            'MERCEDES' => array('A CLASS', 'C CLASS', 'E CLASS', 'SPRINTER'),
            'VOLKSWAGEN' => array('GOLF', 'PASSAT', 'TOURAN', 'TIGUAN'),
            'FORD' => array('FOCUS', 'MONDEO', 'TRANSIT'),
            'OPEL' => array('ASTRA', 'INSIGNIA', 'CORSA'),
        );
        $upper = strtoupper($text);
        foreach ($rules as $make => $models) {
            if (strpos($upper, $make) !== false) {
                foreach ($models as $model) {
                    if (strpos($upper, $model) !== false) {
                        return array('make' => $make, 'model' => $model, 'confidence' => 'medium');
                    }
                }
                return array('make' => $make, 'model' => '', 'confidence' => 'low');
            }
        }
        return $existing;
    }

    private function vehicle_from_existing_product($normalized_oem)
    {
        if (!$normalized_oem) {
            return array('make' => '', 'model' => '', 'confidence' => 'low');
        }
        $ids = get_posts(array('post_type' => 'product', 'post_status' => array('publish', 'draft', 'pending', 'private'), 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_normalized_oem_part_number', 'value' => $normalized_oem))));
        if (!$ids) {
            return array('make' => '', 'model' => '', 'confidence' => 'low');
        }
        $id = (int) $ids[0];
        return array('make' => sanitize_text_field(get_post_meta($id, '_gps_detected_vehicle_make', true)), 'model' => sanitize_text_field(get_post_meta($id, '_gps_detected_vehicle_model', true)), 'confidence' => 'high');
    }

    private function suggest_category($text, $vehicle)
    {
        $path = '';
        $confidence = 'low';
        $upper = strtoupper($text);
        $hints = array('GEARBOX' => 'Gearboxes', 'TRANSMISSION' => 'Gearboxes', 'ENGINE' => 'Engines', 'TURBO' => 'Turbochargers', 'LAMP' => 'Lighting', 'HEADLIGHT' => 'Lighting');
        foreach ($hints as $needle => $label) {
            if (strpos($upper, $needle) !== false) {
                $path = $label;
                $confidence = $vehicle['confidence'] === 'high' ? 'high' : 'medium';
                break;
            }
        }
        $id = 0;
        if ($path && taxonomy_exists('product_cat')) {
            $term = get_term_by('name', $path, 'product_cat');
            $id = $term && !is_wp_error($term) ? (int) $term->term_id : 0;
        }
        return array('id' => $id, 'path' => $path, 'confidence' => $confidence, 'source' => $path ? 'local_rules' : 'none');
    }

    private function attachment_set_hash($images)
    {
        if (!$images) {
            return '';
        }
        $parts = array();
        foreach ($images as $image) {
            $parts[] = ($image['attachment_id'] ?? '') . '|' . ($image['filename'] ?? '') . '|' . ($image['size'] ?? 0);
        }
        sort($parts);
        return hash('sha256', implode('||', $parts));
    }

    private function duplicate_status($message_id, $normalized_oem, $attachment_hash = '')
    {
        $message_match = get_posts(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_gmail_import_message_id', 'value' => $message_id))));
        if ($message_match) {
            return array('status' => 'duplicate_message_id', 'product_id' => (int) $message_match[0]);
        }
        if (!$this->settings()['duplicate_protection'] || !$normalized_oem) {
            return array('status' => '', 'product_id' => 0);
        }
        $oem_match = get_posts(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_normalized_oem_part_number', 'value' => $normalized_oem))));
        if ($oem_match) {
            return array('status' => 'duplicate_oem_possible', 'product_id' => (int) $oem_match[0]);
        }
        if ($attachment_hash) {
            $attachment_match = get_posts(array('post_type' => 'product', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_gmail_import_attachment_set_hash', 'value' => $attachment_hash))));
            if ($attachment_match) {
                return array('status' => 'duplicate_existing_product_id', 'product_id' => (int) $attachment_match[0]);
            }
        }
        return array('status' => '', 'product_id' => 0);
    }

    private function warnings($oem, $images, $vehicle)
    {
        $warnings = array();
        if (!$oem['selected']) {
            $warnings[] = 'missing_oem_candidate';
        }
        if (!$images) {
            $warnings[] = 'no_image_attachments';
        }
        if ($vehicle['confidence'] === 'low') {
            $warnings[] = 'needs_review';
        }
        return $warnings;
    }

    private function stage_message_analysis($analysis)
    {
        $existing_id = $this->find_staging_item_by_message_id($analysis['message_id']);
        $duplicate = $analysis['duplicate_status'];
        $status = $duplicate ? 'duplicate' : 'imported_from_gmail';
        $postarr = array(
            'post_type' => self::STAGING_POST_TYPE,
            'post_status' => 'private',
            'post_title' => $analysis['subject'] ?: ($analysis['detected_part_code'] ?: $analysis['message_id']),
            'post_content' => $analysis['body'],
            'post_excerpt' => $analysis['body_excerpt'],
        );
        if ($existing_id) {
            $postarr['ID'] = $existing_id;
            $post_id = wp_update_post($postarr, true);
            $action = 'updated_staging_item';
            if (!$duplicate) {
                $duplicate = 'existing_staging_item';
            }
        } else {
            $post_id = wp_insert_post($postarr, true);
            $action = 'created_staging_item';
        }
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        $created_product_id = absint(get_post_meta($post_id, '_gps_gmail_created_product_id', true));
        $meta = $this->staging_meta_from_analysis($analysis, $status, $duplicate, $created_product_id);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        return array(
            'staging_item_id' => (int) $post_id,
            'staging_status' => $status,
            'stage_action' => $action,
            'duplicate_status' => $duplicate,
            'created_product_id' => $created_product_id,
            'product_id' => 0,
            'images_imported' => 0,
        );
    }

    private function find_staging_item_by_message_id($message_id)
    {
        if (!$message_id) {
            return 0;
        }
        $ids = get_posts(array('post_type' => self::STAGING_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_gmail_message_id', 'value' => $message_id))));
        return $ids ? (int) $ids[0] : 0;
    }

    private function fixed_import_category_settings($settings = null)
    {
        $settings = $settings === null ? $this->settings() : (array) $settings;
        $enabled = !empty($settings['fixed_ovoko_import_category_enabled']);
        $id = absint($settings['fixed_ovoko_import_category_id'] ?? 0);
        $name = trim((string) ($settings['fixed_ovoko_import_category_name'] ?? ''));
        $empty_price_allowed = $enabled && !empty($settings['allow_empty_price_for_fixed_category_crm_only_import']);
        return array(
            'enabled' => $enabled,
            'configured' => $enabled && $id > 0 && $name !== '',
            'category_id' => $id,
            'category_name' => $name,
            'review_warning_enabled' => !empty($settings['fixed_ovoko_import_category_review_warning_enabled']),
            'empty_price_allowed' => $empty_price_allowed,
            'mode' => $enabled ? 'fixed_import_category_manual_review' : '',
        );
    }

    private function fixed_import_category_empty_price_allowed($analysis = array())
    {
        $fixed = $this->fixed_import_category_settings();
        return $fixed['configured'] && $fixed['empty_price_allowed'];
    }

    private function fixed_import_category_meta_values($settings = null)
    {
        $fixed = $this->fixed_import_category_settings($settings);
        if (!$fixed['configured']) {
            return array(
                '_gps_price_required_for_woo_draft' => '1',
                '_gps_price_required_for_crm_only_import' => '1',
                '_gps_empty_price_allowed_reason' => '',
            );
        }
        return array(
            '_gps_ovoko_category_suggestion_status' => 'fixed_import_category_manual_review',
            '_gps_ovoko_category_suggestion_category_id' => (string) $fixed['category_id'],
            '_gps_ovoko_category_suggestion_category_name' => $fixed['category_name'],
            '_gps_ovoko_category_suggestion_confidence' => 'placeholder',
            '_gps_ovoko_category_suggestion_source_type' => 'fixed_import_category_manual_review',
            '_gps_ovoko_category_review_required' => '1',
            '_gps_ovoko_category_review_reason' => 'fixed import category used; staff must correct category in Ovoko before publishing',
            '_gps_price_required_for_woo_draft' => $fixed['empty_price_allowed'] ? '0' : '1',
            '_gps_price_required_for_crm_only_import' => $fixed['empty_price_allowed'] ? '0' : '1',
            '_gps_empty_price_allowed_reason' => $fixed['empty_price_allowed'] ? 'fixed_category_crm_only_import_review_flow' : '',
        );
    }

    private function persist_fixed_import_category_meta_for_staging_item($item_id)
    {
        foreach ($this->fixed_import_category_meta_values() as $key => $value) {
            update_post_meta($item_id, $key, $value);
        }
    }

    private function staging_meta_from_analysis($analysis, $staging_status, $duplicate_status, $created_product_id)
    {
        $selected_price = $this->selected_price_for_analysis($analysis);
        $analysis['selected_price_pln'] = $selected_price ? $selected_price['price'] : '';
        $analysis['selected_price_source'] = $selected_price ? $selected_price['source'] : '';
        $woo_readiness = $this->woo_draft_readiness_status($analysis, $staging_status, $created_product_id);
        $marketplace_readiness = $this->marketplace_readiness_status($analysis, $staging_status, $created_product_id);
        return array_merge($this->fixed_import_category_meta_values(), array(
            '_gps_gmail_message_id' => sanitize_text_field($analysis['message_id']),
            '_gps_gmail_thread_id' => sanitize_text_field($analysis['thread_id']),
            '_gps_gmail_date' => sanitize_text_field($analysis['date']),
            '_gps_gmail_from' => sanitize_text_field($analysis['from']),
            '_gps_gmail_subject' => sanitize_text_field($analysis['subject']),
            '_gps_gmail_label' => sanitize_text_field($analysis['label']),
            '_gps_gmail_query_used' => sanitize_text_field($analysis['gmail_query_used']),
            '_gps_gmail_message_status_filter' => sanitize_text_field($analysis['message_status_filter']),
            '_gps_gmail_body_excerpt' => sanitize_text_field($analysis['body_excerpt']),
            '_gps_storage_location' => sanitize_text_field($analysis['storage_location']),
            '_gps_detected_part_code' => sanitize_text_field($analysis['detected_part_code']),
            '_gps_normalized_part_code' => sanitize_text_field($analysis['normalized_part_code']),
            '_gps_detected_oem_part_number' => sanitize_text_field($analysis['detected_oem_part_number']),
            '_gps_normalized_oem_part_number' => sanitize_text_field($analysis['normalized_oem_part_number']),
            '_gps_gmail_import_oem_candidates' => wp_json_encode(array_values((array) $analysis['oem_candidates'])),
            '_gps_detected_vehicle_make' => sanitize_text_field($analysis['detected_vehicle_make']),
            '_gps_detected_vehicle_model' => sanitize_text_field($analysis['detected_vehicle_model']),
            '_gps_detected_vehicle_confidence' => sanitize_text_field($analysis['detected_vehicle_confidence']),
            '_gps_gmail_import_image_count' => absint($analysis['image_attachments_found']),
            '_gps_gmail_import_attachment_set_hash' => sanitize_text_field($analysis['image_attachment_set_hash']),
            '_gps_gmail_images_metadata' => wp_json_encode((array) $analysis['images']),
            '_gps_duplicate_status' => sanitize_text_field($duplicate_status),
            '_gps_duplicate_existing_product_id' => absint($analysis['duplicate_existing_product_id']),
            '_gps_gmail_warnings' => wp_json_encode(array_values((array) $analysis['warnings'])),
            '_gps_ovoko_enrichment_status' => sanitize_text_field($analysis['ovoko_enrichment_status'] ?? ''),
            '_gps_allegro_price_research_status' => sanitize_text_field($analysis['allegro_price_research_status'] ?? ''),
            '_gps_allegro_price_research_checked_at' => sanitize_text_field($analysis['allegro_price_research_checked_at'] ?? ''),
            '_gps_allegro_price_query' => sanitize_text_field($analysis['allegro_price_query'] ?? ''),
            '_gps_allegro_price_raw_offer_count' => absint($analysis['allegro_price_raw_offer_count'] ?? 0),
            '_gps_allegro_price_filtered_offer_count' => absint($analysis['allegro_price_filtered_offer_count'] ?? 0),
            '_gps_allegro_price_median_pln' => sanitize_text_field($analysis['allegro_price_median_pln'] ?? ''),
            '_gps_allegro_price_min_pln' => sanitize_text_field($analysis['allegro_price_min_pln'] ?? ''),
            '_gps_allegro_price_max_pln' => sanitize_text_field($analysis['allegro_price_max_pln'] ?? ''),
            '_gps_allegro_price_confidence' => sanitize_text_field($analysis['allegro_price_confidence'] ?? ''),
            '_gps_allegro_price_sample_offer_urls' => is_string($analysis['allegro_price_sample_offer_urls'] ?? '') ? sanitize_text_field($analysis['allegro_price_sample_offer_urls'] ?? '') : wp_json_encode(array_values((array) ($analysis['allegro_price_sample_offer_urls'] ?? array())), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            '_gps_allegro_price_source' => sanitize_text_field($analysis['allegro_price_source'] ?? ''),
            '_gps_allegro_price_suggestion' => sanitize_text_field($analysis['allegro_price_suggestion'] ?? ''),
            '_gps_allegro_price_currency' => sanitize_text_field($analysis['allegro_price_currency'] ?? ''),
            '_gps_allegro_price_notes' => sanitize_textarea_field($analysis['allegro_price_notes'] ?? ''),
            '_gps_allegro_price_error_http_status' => absint($analysis['allegro_price_error_http_status'] ?? 0),
            '_gps_allegro_price_error_response' => sanitize_textarea_field($analysis['allegro_price_error_response'] ?? ''),
            '_gps_allegro_price_error_code' => sanitize_text_field($analysis['allegro_price_error_code'] ?? ''),
            '_gps_allegro_price_error_checked_at' => sanitize_text_field($analysis['allegro_price_error_checked_at'] ?? ''),
            '_gps_manual_price_override_enabled' => !empty($analysis['manual_price_override_enabled']) ? '1' : '0',
            '_gps_manual_price_pln' => sanitize_text_field($analysis['manual_price_pln'] ?? ''),
            '_gps_manual_price_note' => sanitize_textarea_field($analysis['manual_price_note'] ?? ''),
            '_gps_manual_price_set_at' => sanitize_text_field($analysis['manual_price_set_at'] ?? ''),
            '_gps_manual_price_set_by' => absint($analysis['manual_price_set_by'] ?? 0),
            '_gps_selected_price_pln' => $selected_price ? $selected_price['price'] : '',
            '_gps_selected_price_source' => $selected_price ? $selected_price['source'] : '',
            '_gps_selected_price_checked_at' => current_time('mysql', true),
            '_gps_category_mapping_status' => sanitize_text_field($analysis['category_mapping_status'] ?? ''),
            '_gps_suggested_woo_category_id' => absint($analysis['suggested_woo_category_id']),
            '_gps_suggested_woo_category_path' => sanitize_text_field($analysis['suggested_woo_category_path']),
            '_gps_suggested_woo_category_confidence' => sanitize_text_field($analysis['suggested_woo_category_confidence']),
            '_gps_suggested_category_source' => sanitize_text_field($analysis['suggested_category_source']),
            '_gps_shipping_group' => sanitize_text_field($analysis['shipping_group'] ?? ''),
            '_gps_woo_draft_readiness_status' => $woo_readiness['status'],
            '_gps_woo_draft_blocking_reasons' => wp_json_encode($woo_readiness['blocking_reasons']),
            '_gps_woo_draft_readiness_checked_at' => current_time('mysql', true),
            '_gps_marketplace_readiness_status' => $marketplace_readiness['status'],
            '_gps_marketplace_blocking_reasons' => wp_json_encode($marketplace_readiness['blocking_reasons']),
            '_gps_marketplace_readiness_checked_at' => current_time('mysql', true),
            '_gps_readiness_status' => $woo_readiness['status'],
            '_gps_blocking_reasons' => wp_json_encode($woo_readiness['blocking_reasons']),
            '_gps_gmail_created_product_id' => absint($created_product_id),
            '_gps_staging_status' => sanitize_text_field($staging_status),
            '_gps_staged_from_gmail_at' => current_time('mysql', true),
        ));
    }

    private function readiness_status($analysis, $staging_status, $created_product_id)
    {
        return $this->woo_draft_readiness_status($analysis, $staging_status, $created_product_id);
    }

    private function woo_draft_readiness_status($analysis, $staging_status, $created_product_id)
    {
        $blocking = $this->base_readiness_blockers($analysis, $staging_status, $created_product_id);
        return $this->readiness_response($blocking, 'ready_to_create_product');
    }

    private function marketplace_readiness_status($analysis, $staging_status, $created_product_id)
    {
        $blocking = $this->base_readiness_blockers($analysis, $staging_status, $created_product_id);
        if (!in_array((string) ($analysis['shipping_group'] ?? ''), $this->allowed_shipping_groups(), true)) {
            $blocking[] = 'missing_shipping_group';
        }
        return $this->readiness_response($blocking, 'ready_for_marketplace');
    }

    private function base_readiness_blockers($analysis, $staging_status, $created_product_id)
    {
        $blocking = array();
        if ($created_product_id) {
            $blocking[] = 'product_already_created';
        }
        if ($staging_status === 'duplicate') {
            $blocking[] = 'duplicate';
        }
        if (trim((string) ($analysis['message_id'] ?? '')) === '') {
            $blocking[] = 'missing_gmail_message_id';
        }
        if ($this->gmail_staging_base_sku($analysis) === '') {
            $blocking[] = 'missing_generated_sku';
        }
        if (trim((string) ($analysis['detected_part_code'] ?? '')) === '') {
            $blocking[] = 'missing_detected_part_code';
        }
        if (trim((string) ($analysis['normalized_part_code'] ?? '')) === '') {
            $blocking[] = 'missing_normalized_part_code';
        }
        if (trim((string) ($analysis['detected_oem_part_number'] ?? '')) === '') {
            $blocking[] = 'missing_detected_oem_part_number';
        }
        if (trim((string) ($analysis['normalized_oem_part_number'] ?? '')) === '') {
            $blocking[] = 'missing_normalized_oem_part_number';
        }
        if (empty($analysis['image_attachments_found'])) {
            $blocking[] = 'missing_images';
        }
        if (empty($analysis['images']) || !is_array($analysis['images'])) {
            $blocking[] = 'missing_images_metadata';
        }
        // Ovoko status `suggested` is this plugin's successful high-confidence enrichment value.
        $fixed_settings = $this->fixed_import_category_settings();
        $fixed_import_category_allows_empty_price = $this->fixed_import_category_empty_price_allowed($analysis);
        if (!empty($fixed_settings['enabled']) && empty($fixed_settings['configured'])) {
            $blocking[] = 'fixed_import_category_not_configured';
        }
        if (!$fixed_import_category_allows_empty_price && !$this->status_indicates_success((string) ($analysis['ovoko_enrichment_status'] ?? ''), array('enriched', 'matched', 'ok', 'suggested', 'no_match', 'needs_review'))) {
            $blocking[] = 'missing_ovoko_enrichment';
        }
        // Production price can come only from manual override or a completed Ovoko price suggestion.
        $selected_price = $this->selected_price_for_analysis($analysis);
        if (!$selected_price && !$fixed_import_category_allows_empty_price) {
            $blocking[] = 'missing_selected_price';
        }
        $category_mapping_ready = $this->status_indicates_success((string) ($analysis['category_mapping_status'] ?? ''), array('success', 'ok', 'mapped', 'matched'));
        if (!$category_mapping_ready && !$fixed_import_category_allows_empty_price) {
            $blocking[] = (string) ($analysis['category_mapping_status'] ?? '') === 'missing_ovoko_category_resolution' ? 'missing_ovoko_category_resolution' : 'missing_category_mapping';
        } elseif (!$fixed_import_category_allows_empty_price && $category_mapping_ready && (absint($analysis['suggested_woo_category_id'] ?? 0) <= 0 || strtolower(trim((string) ($analysis['suggested_woo_category_confidence'] ?? ''))) === 'low' || trim((string) ($analysis['suggested_woo_category_confidence'] ?? '')) === '' || in_array(strtolower(trim((string) ($analysis['suggested_category_source'] ?? ''))), array('', 'none'), true))) {
            $blocking[] = 'invalid_category_mapping';
        } elseif (!$fixed_import_category_allows_empty_price && !$this->product_cat_term_exists(absint($analysis['suggested_woo_category_id'] ?? 0))) {
            $blocking[] = 'invalid_category_term';
        }
        return $blocking;
    }

    private function mapped_suggested_product_cat_id($analysis)
    {
        if (!$this->status_indicates_success((string) ($analysis['category_mapping_status'] ?? ''), array('success', 'ok', 'mapped', 'matched'))) {
            return 0;
        }
        return absint($analysis['suggested_woo_category_id'] ?? 0);
    }

    private function product_cat_term_exists($term_id)
    {
        $term_id = absint($term_id);
        if ($term_id <= 0) {
            return false;
        }
        if (function_exists('taxonomy_exists') && !taxonomy_exists('product_cat')) {
            return false;
        }
        if (function_exists('term_exists')) {
            $exists = term_exists($term_id, 'product_cat');
            return $exists && (!function_exists('is_wp_error') || !is_wp_error($exists));
        }
        if (function_exists('get_term')) {
            $term = get_term($term_id, 'product_cat');
            return $term && (!function_exists('is_wp_error') || !is_wp_error($term));
        }
        return false;
    }

    private function readiness_response($blocking, $ready_status)
    {
        $blocking = array_values(array_unique($blocking));
        return array('status' => $blocking ? 'needs_review' : $ready_status, 'blocking_reasons' => $blocking);
    }

    private function allegro_readiness_quality_ok($analysis)
    {
        $settings = $this->settings();
        $filtered_count = absint($analysis['allegro_price_filtered_offer_count'] ?? 0);
        $minimum_count = max(1, absint($settings['allegro_min_filtered_offer_count'] ?? 5));
        if ($filtered_count >= $minimum_count) {
            return true;
        }
        $confidence = strtolower(trim((string) ($analysis['allegro_price_confidence'] ?? '')));
        $required = strtolower(trim((string) ($settings['allegro_readiness_min_confidence'] ?? 'medium')));
        $rank = array('no_match' => 0, 'not_configured' => 0, 'low' => 1, 'medium' => 2, 'high' => 3);
        return ($rank[$confidence] ?? 0) >= ($rank[$required] ?? 2);
    }

    private function selected_price_for_analysis($analysis)
    {
        if (!empty($analysis['manual_price_override_enabled']) && (float) ($analysis['manual_price_pln'] ?? 0) > 0) {
            return array('price' => $this->format_manual_price_number($analysis['manual_price_pln']), 'source' => 'manual_override');
        }
        if ($this->status_indicates_success((string) ($analysis['ovoko_price_suggestion_status'] ?? ''), array('completed')) && (float) ($analysis['ovoko_price_suggestion_pln'] ?? 0) > 0 && strtoupper(trim((string) ($analysis['ovoko_price_suggestion_currency'] ?? ''))) === 'PLN') {
            return array('price' => $this->format_manual_price_number($analysis['ovoko_price_suggestion_pln']), 'source' => 'ovoko_price_suggestion');
        }
        return null;
    }

    private function persist_selected_price_for_staging_item($item_id, $analysis)
    {
        $selected = $this->selected_price_for_analysis($analysis);
        update_post_meta($item_id, '_gps_selected_price_checked_at', current_time('mysql', true));
        if (!$selected) {
            update_post_meta($item_id, '_gps_selected_price_pln', '');
            update_post_meta($item_id, '_gps_selected_price_source', '');
            return null;
        }
        update_post_meta($item_id, '_gps_selected_price_pln', $selected['price']);
        update_post_meta($item_id, '_gps_selected_price_source', $selected['source']);
        return $selected;
    }


    private function status_indicates_success($status, $allowed_success_statuses)
    {
        return in_array(strtolower(trim((string) $status)), $allowed_success_statuses, true);
    }

    private function allowed_shipping_groups()
    {
        return array('shipping_30', 'shipping_50', 'shipping_130');
    }

    private function stored_blocking_reasons_empty($item_id)
    {
        $stored = get_post_meta($item_id, '_gps_blocking_reasons', true);
        if ($stored === '' || $stored === array()) {
            return true;
        }
        if (is_array($stored)) {
            return empty($stored);
        }
        $decoded = json_decode((string) $stored, true);
        return is_array($decoded) ? empty($decoded) : false;
    }

    public function handle_ovoko_category_prediction_test()
    {
        $this->verify_admin_action();
        $code = sanitize_text_field((string) ($_POST['part_code'] ?? '06K145654L'));
        $expected = sanitize_text_field((string) ($_POST['expected_category_name'] ?? 'Turbina'));
        $capture_request = isset($_POST['capture_request']) ? wp_unslash((string) $_POST['capture_request']) : '';
        $capture_response = isset($_POST['capture_response']) ? wp_unslash((string) $_POST['capture_response']) : '';
        $result = $this->test_ovoko_category_prediction_by_code($code, $expected, $capture_request, $capture_response);
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(add_query_arg(array('page' => 'gps-gmail-product-importer'), admin_url('admin.php')));
        exit;
    }

    public function handle_import_queue_item_action()
    {
        $this->verify_admin_action();
        $item_id = absint($_POST['staging_item_id'] ?? 0);
        $queue_action = sanitize_key($_POST['queue_action'] ?? '');
        $post = $item_id ? get_post($item_id) : null;
        if (!$post || $post->post_type !== self::STAGING_POST_TYPE) {
            $result = array('action' => $queue_action, 'staging_item_id' => $item_id, 'result' => 'error', 'error' => 'Import queue item not found.');
        } else {
            switch ($queue_action) {
                case 'full_preparation':
                    $result = $this->run_full_preparation_for_staging_item($item_id);
                    break;
                case 'ovoko_enrichment':
                    $result = $this->run_ovoko_enrichment_for_staging_item($item_id);
                    break;
                case 'allegro_price_research':
                    $result = $this->run_allegro_price_research_for_staging_item($item_id);
                    break;
                case 'ovoko_price_suggestion':
                    $result = $this->run_ovoko_price_suggestion_for_staging_item($item_id);
                    break;
                case 'ovoko_category_suggestion':
                    $result = $this->run_ovoko_category_suggestion_for_staging_item($item_id);
                    break;
                case 'category_mapping':
                    $result = $this->run_category_mapping_for_staging_item($item_id);
                    break;
                case 'readiness_validation':
                    $result = $this->run_readiness_validation_for_staging_item($item_id);
                    break;
                case 'reset_created_woo_product_link':
                    $result = $this->reset_created_woo_product_link_for_staging_item($item_id, !empty($_POST['force_reset_created_product_link']));
                    break;
                case 'manual_price_override':
                    $result = $this->set_manual_price_override_for_staging_item($item_id, $_POST);
                    break;
                case 'create_woo_draft':
                    $result = $this->create_woo_draft_from_staging_item($item_id);
                    break;
                default:
                    $result = array('action' => $queue_action, 'staging_item_id' => $item_id, 'result' => 'error', 'error' => 'Unknown queue action.');
            }
        }
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(add_query_arg(array('page' => 'gps-gmail-product-importer', 'gps_staging_item_id' => $item_id), admin_url('admin.php')) . '#gps-import-queue-detail');
        exit;
    }


    private function reset_created_woo_product_link_for_staging_item($item_id, $force = false)
    {
        $created_product_id = absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true));
        $linked_product = $created_product_id ? get_post($created_product_id) : null;
        $linked_product_status = $linked_product ? (string) ($linked_product->post_status ?? '') : '';
        $linked_product_exists_active = $linked_product && $linked_product_status !== 'trash';

        if ($linked_product_exists_active && !$force) {
            return array(
                'action' => 'reset_created_woo_product_link',
                'staging_item_id' => $item_id,
                'result' => 'blocked',
                'reason' => 'linked_product_still_exists',
                'created_product_id' => $created_product_id,
                'linked_product_status' => $linked_product_status,
                'writes' => 'none',
            );
        }

        $created_product_meta_keys = array(
            '_gps_gmail_created_product_id',
            '_gps_gmail_created_product_at',
            '_gps_gmail_created_product_checked_at',
            '_gps_gmail_created_product_status',
            '_gps_gmail_created_product_error',
            '_gps_created_product_id',
            '_gps_created_product_at',
            '_gps_created_product_status',
        );
        foreach ($created_product_meta_keys as $meta_key) {
            delete_post_meta($item_id, $meta_key);
        }
        update_post_meta($item_id, '_gps_gmail_created_product_id', 0);
        update_post_meta($item_id, '_gps_staging_status', 'imported_from_gmail');

        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        $woo_status = (string) ($readiness['woo_draft_readiness']['status'] ?? ($readiness['result'] ?? 'needs_review'));
        update_post_meta($item_id, '_gps_staging_status', $woo_status === 'ready_to_create_product' ? 'ready_to_create_product' : 'needs_review');

        return array(
            'action' => 'reset_created_woo_product_link',
            'staging_item_id' => $item_id,
            'result' => 'reset_created_product_link',
            'created_product_id' => $created_product_id,
            'linked_product_status' => $linked_product ? $linked_product_status : 'missing',
            'forced' => (bool) $force,
            'readiness' => $readiness,
            'writes' => 'staging_meta_only',
        );
    }

    private function set_manual_price_override_for_staging_item($item_id, $request)
    {
        $raw_price = isset($request['manual_price_pln']) ? trim((string) $request['manual_price_pln']) : '';
        if ($raw_price === '' || !is_numeric($raw_price) || (float) $raw_price <= 0) {
            return array('action' => 'manual_price_override', 'staging_item_id' => $item_id, 'result' => 'error', 'error' => 'Manual price must be numeric and greater than 0.', 'writes' => 'none');
        }
        $price = $this->format_manual_price_number($raw_price);
        update_post_meta($item_id, '_gps_manual_price_override_enabled', '1');
        update_post_meta($item_id, '_gps_manual_price_pln', $price);
        update_post_meta($item_id, '_gps_manual_price_note', sanitize_textarea_field((string) ($request['manual_price_note'] ?? '')));
        update_post_meta($item_id, '_gps_manual_price_set_at', current_time('mysql', true));
        update_post_meta($item_id, '_gps_manual_price_set_by', function_exists('get_current_user_id') ? absint(get_current_user_id()) : 0);
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        return array('action' => 'manual_price_override', 'staging_item_id' => $item_id, 'result' => 'saved_manual_price_override', 'manual_price_pln' => $price, 'price_source' => 'manual_override', 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }

    private function run_ovoko_enrichment_for_staging_item($item_id)
    {
        $this->persist_fixed_import_category_meta_for_staging_item($item_id);
        $analysis = $this->analysis_from_staging_item($item_id);
        $oem = $analysis['detected_oem_part_number'] ?: $analysis['normalized_oem_part_number'];
        if (!$oem) {
            $oem = $analysis['detected_part_code'] ?: $analysis['normalized_part_code'];
        }
        if (!$oem) {
            update_post_meta($item_id, '_gps_ovoko_enrichment_status', 'missing_oem');
            update_post_meta($item_id, '_gps_ovoko_enrichment_checked_at', current_time('mysql', true));
            return array('action' => 'ovoko_enrichment', 'staging_item_id' => $item_id, 'result' => 'skipped', 'reason' => 'missing_oem');
        }
        $lookup = $this->ovoko_lookup_by_oem($oem, 10);
        if (is_wp_error($lookup)) {
            update_post_meta($item_id, '_gps_ovoko_enrichment_status', 'error');
            update_post_meta($item_id, '_gps_ovoko_enrichment_checked_at', current_time('mysql', true));
            update_post_meta($item_id, '_gps_ovoko_error_message', sanitize_text_field($lookup->get_error_message()));
            return array('action' => 'ovoko_enrichment', 'staging_item_id' => $item_id, 'result' => 'error', 'error' => $lookup->get_error_message(), 'writes' => 'staging_meta_only');
        }
        $ovoko = $this->analyze_ovoko_lookup($oem, $lookup);
        $suggested_category = $this->suggest_woo_category_from_ovoko($ovoko);
        $ovoko['suggested_category_id'] = $suggested_category['id'];
        $ovoko['suggested_category_path'] = $suggested_category['path'];
        foreach ($this->ovoko_meta_payload($oem, $ovoko) as $key => $value) {
            update_post_meta($item_id, $key, $value);
        }
        if (!empty($ovoko['suggested_category_id']) || !empty($ovoko['suggested_category_path'])) {
            update_post_meta($item_id, '_gps_suggested_woo_category_id', absint($ovoko['suggested_category_id']));
            update_post_meta($item_id, '_gps_suggested_woo_category_path', sanitize_text_field($ovoko['suggested_category_path']));
            update_post_meta($item_id, '_gps_suggested_woo_category_confidence', sanitize_text_field($ovoko['confidence']));
            update_post_meta($item_id, '_gps_suggested_category_source', 'ovoko_enrichment');
        }
        $category_suggestion = array();
        if ((int) $ovoko['match_count'] === 0 || $ovoko['confidence'] === 'none') {
            $category_suggestion = $this->run_ovoko_category_suggestion_for_staging_item($item_id, $oem, false);
        }
        $price_suggestion = $this->run_ovoko_price_suggestion_for_staging_item($item_id, false);
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        return array('action' => 'ovoko_enrichment', 'staging_item_id' => $item_id, 'result' => 'saved_staging_suggestions', 'match_count' => $ovoko['match_count'], 'confidence' => $ovoko['confidence'], 'category_suggestion' => $category_suggestion, 'price_suggestion' => $price_suggestion, 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }


    private function run_ovoko_category_suggestion_for_staging_item($item_id, $code = '', $refresh_readiness = true)
    {
        $analysis = $this->analysis_from_staging_item($item_id);
        $code = trim((string) $code);
        if ($code === '') {
            $code = trim((string) ($analysis['detected_oem_part_number'] ?: $analysis['normalized_oem_part_number']));
        }
        if ($code === '') {
            $code = trim((string) ($analysis['detected_part_code'] ?: $analysis['normalized_part_code']));
        }
        $checked_at = current_time('mysql', true);
        $suggestion = $this->normalize_ovoko_category_suggestion_result($this->ovoko_category_suggestion_by_part_code($code, $analysis, $checked_at), $code, $checked_at);
        $status = sanitize_text_field((string) ($suggestion['status'] ?? 'unavailable'));
        $category_id = sanitize_text_field((string) ($suggestion['category_id'] ?? ''));
        $category_name = sanitize_text_field((string) ($suggestion['category_name'] ?? ''));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_status', $status);
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_code', sanitize_text_field($code));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_category_id', $category_id);
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', $category_name);
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_confidence', sanitize_text_field((string) ($suggestion['confidence'] ?? 'none')));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_source_type', sanitize_text_field((string) ($suggestion['source_type'] ?? 'unavailable')));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_source', sanitize_text_field((string) ($suggestion['source'] ?? '')));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_checked_at', sanitize_text_field((string) ($suggestion['checked_at'] ?? $checked_at)));
        update_post_meta($item_id, '_gps_ovoko_category_suggestion_raw_response', is_string($suggestion['raw_response'] ?? '') ? (string) $suggestion['raw_response'] : wp_json_encode($suggestion['raw_response'] ?? array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->ovoko_category_suggestion_allows_mapping($suggestion) && $category_name !== '') {
            $mapped = $this->woo_category_mapping_candidate($category_name, $category_name, 'ovoko_category_prediction_by_code', 'high');
            if (!empty($mapped['id'])) {
                update_post_meta($item_id, '_gps_suggested_woo_category_id', absint($mapped['id']));
                update_post_meta($item_id, '_gps_suggested_woo_category_path', sanitize_text_field((string) $mapped['path']));
                update_post_meta($item_id, '_gps_suggested_woo_category_confidence', sanitize_text_field((string) $mapped['confidence']));
                update_post_meta($item_id, '_gps_suggested_category_source', sanitize_text_field((string) $mapped['source']));
            }
        }
        $readiness = $refresh_readiness ? $this->run_readiness_validation_for_staging_item($item_id) : array();
        return array('action' => 'ovoko_category_suggestion', 'staging_item_id' => $item_id, 'result' => $status, 'code' => $code, 'category_id' => $category_id, 'category_name' => $category_name, 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }

    private function ovoko_category_suggestion_by_part_code($code, $analysis, $checked_at)
    {
        $default = array(
            'status' => $code === '' ? 'no_suggestion' : 'unavailable',
            'category_id' => '',
            'category_name' => '',
            'source' => 'not_checked',
            'source_type' => 'unavailable',
            'confidence' => 'none',
            'checked_at' => $checked_at,
            'raw_response' => array('message' => 'No category suggestion source returned a usable result.'),
        );
        if ($code === '') {
            return $default;
        }
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('gps_gmail_product_importer_ovoko_category_suggestion_by_part_code', $default, $code, $analysis);
            if (is_array($filtered) && $filtered !== $default) {
                return array_merge($default, $filtered);
            }
        }
        $api_result = $this->ovoko_category_suggestion_from_rrr_api_candidates($code, $checked_at);
        if (in_array((string) ($api_result['status'] ?? ''), array('completed', 'needs_manual_category_id', 'endpoint_error'), true)) {
            return $api_result;
        }
        $public_result = $this->ovoko_category_suggestion_from_public_code_page($code, $checked_at);
        if (in_array((string) ($public_result['status'] ?? ''), array('completed', 'needs_manual_category_id', 'endpoint_error', 'no_suggestion'), true)) {
            return $public_result;
        }
        return array_merge($default, array(
            'source' => 'manual_network_capture_required',
            'source_type' => 'unavailable',
            'confidence' => 'none',
            'raw_response' => array(
                'official_api_candidates' => $api_result,
                'public_code_page' => $public_result,
                'manual_capture_required' => true,
            ),
        ));
    }

    private function normalize_ovoko_category_suggestion_result($suggestion, $code, $checked_at)
    {
        $suggestion = is_array($suggestion) ? $suggestion : array();
        $category_name = sanitize_text_field((string) ($suggestion['category_name'] ?? $suggestion['name'] ?? ''));
        $category_id = sanitize_text_field((string) ($suggestion['category_id'] ?? $suggestion['id'] ?? ''));
        $source = sanitize_text_field((string) ($suggestion['source'] ?? ''));
        $source_type = $this->normalize_ovoko_category_suggestion_source_type((string) ($suggestion['source_type'] ?? ''), $source);
        $confidence = $this->normalize_ovoko_category_suggestion_confidence((string) ($suggestion['confidence'] ?? ''), $source_type, $category_id !== '');
        $raw_response = $suggestion['raw_response'] ?? $suggestion;
        $status = sanitize_text_field((string) ($suggestion['status'] ?? ''));
        if (in_array($status, array('found', 'success', 'mapped'), true)) {
            $status = 'completed';
        }
        $trusted = $category_name !== '' && $category_id !== '' && $confidence === 'high' && in_array($source_type, self::OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES, true);
        if ($trusted) {
            $status = 'completed';
        } elseif ($category_name !== '' || $category_id !== '') {
            $status = 'no_code_specific_suggestion';
            $confidence = 'none';
            $category_id = '';
            $category_name = '';
            if ($source_type === '') {
                $source_type = 'unavailable';
            }
            $raw_response = array('discarded_untrusted_category_suggestion' => $suggestion, 'reason' => 'category suggestion was not high-confidence and code-specific; broad catalog/category-tree/text guesses are not allowed');
        } elseif ($status === '') {
            $status = $code === '' ? 'no_suggestion' : 'unavailable';
        }
        if (!in_array($status, array('completed', 'needs_manual_category_id', 'no_suggestion', 'no_code_specific_suggestion', 'endpoint_error', 'unavailable'), true)) {
            $status = 'unavailable';
        }
        if ($status !== 'completed' && $source_type === '') {
            $source_type = 'unavailable';
        }
        return array(
            'status' => $status,
            'category_id' => $status === 'completed' ? $category_id : '',
            'category_name' => $status === 'completed' ? $category_name : '',
            'confidence' => $status === 'completed' ? $confidence : 'none',
            'source_type' => $source_type !== '' ? $source_type : 'unavailable',
            'source' => $source,
            'checked_at' => sanitize_text_field((string) ($suggestion['checked_at'] ?? $checked_at)),
            'raw_response' => $raw_response,
        );
    }

    private function normalize_ovoko_category_suggestion_source_type($source_type, $source)
    {
        $source_type = sanitize_key((string) $source_type);
        $source = (string) $source;
        $allowed = array_merge(self::OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES, array('category_tree_fallback', 'unavailable'));
        if (in_array($source_type, $allowed, true)) {
            return $source_type;
        }
        if (strpos($source, 'official_code_prediction') !== false) {
            return 'official_code_prediction';
        }
        if (strpos($source, 'panel_network_capture') !== false || strpos($source, 'network_capture') !== false) {
            return 'panel_network_capture';
        }
        if (strpos($source, 'rrr_api_candidate:') === 0 || strpos($source, 'ovoko_category_prediction_by_code:') === 0) {
            return 'api_candidate_code_lookup';
        }
        if (strpos($source, 'ovoko_public_code_page') !== false) {
            return 'public_ovoko_code_page';
        }
        if (strpos($source, 'woo_term_meta:') !== false || strpos($source, 'woo_term_exact_ovoko_id') !== false) {
            return 'woo_term_exact_ovoko_id';
        }
        if (strpos($source, 'categories_tree') !== false || strpos($source, 'category_tree') !== false || strpos($source, 'rrr_catalog') !== false) {
            return 'category_tree_fallback';
        }
        return 'unavailable';
    }

    private function normalize_ovoko_category_suggestion_confidence($confidence, $source_type, $has_category_id)
    {
        $confidence = sanitize_key((string) $confidence);
        if (in_array($confidence, array('high', 'medium', 'low', 'none'), true)) {
            return $confidence;
        }
        if ($has_category_id && in_array($source_type, self::OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES, true)) {
            return 'high';
        }
        if ($source_type === 'category_tree_fallback') {
            return 'low';
        }
        return 'none';
    }

    private function ovoko_category_suggestion_allows_mapping($suggestion)
    {
        $suggestion = is_array($suggestion) ? $suggestion : array();
        return (string) ($suggestion['status'] ?? '') === 'completed'
            && (string) ($suggestion['confidence'] ?? '') === 'high'
            && in_array((string) ($suggestion['source_type'] ?? ''), self::OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES, true)
            && trim((string) ($suggestion['category_id'] ?? '')) !== '';
    }

    private function ovoko_category_suggestion_from_rrr_api_candidates($code, $checked_at)
    {
        $diagnostic = $this->test_ovoko_category_prediction_by_code($code, '', '', '', $checked_at);
        $selected = (array) ($diagnostic['final_selected_category_source'] ?? array());
        if (!empty($selected) && (string) ($selected['status'] ?? '') === 'completed') {
            return array(
                'status' => 'completed',
                'category_id' => (string) ($selected['parsed_category_id'] ?? ''),
                'category_name' => (string) ($selected['parsed_category_name'] ?? ''),
                'source' => 'ovoko_category_prediction_by_code:' . (string) ($selected['endpoint'] ?? ''),
                'source_type' => 'api_candidate_code_lookup',
                'confidence' => 'high',
                'checked_at' => $checked_at,
                'raw_response' => array('diagnostic' => $diagnostic),
            );
        }
        $had_error = false;
        foreach ((array) ($diagnostic['sources_attempted'] ?? array()) as $attempt) {
            if ((string) ($attempt['response_status'] ?? '') === 'endpoint_error') {
                $had_error = true;
                break;
            }
        }
        return array(
            'status' => $had_error ? 'endpoint_error' : 'unavailable',
            'source' => 'ovoko_category_prediction_api_candidates_no_confirmed_endpoint',
            'source_type' => 'unavailable',
            'confidence' => 'none',
            'checked_at' => $checked_at,
            'raw_response' => array('diagnostic' => $diagnostic),
        );
    }

    private function ovoko_category_suggestion_from_public_code_page($code, $checked_at)
    {
        if (!function_exists('wp_remote_get')) {
            return array('status' => 'unavailable', 'source' => 'wp_remote_get_unavailable', 'source_type' => 'unavailable', 'confidence' => 'none', 'checked_at' => $checked_at, 'raw_response' => array());
        }
        $url = 'https://ovoko.pl/oferta/' . rawurlencode($code);
        $response = wp_remote_get($url, array('timeout' => 12, 'headers' => array('User-Agent' => 'GPS Gmail Product Importer category suggestion/0.1')));
        if (is_wp_error($response)) {
            return array('status' => 'endpoint_error', 'source' => 'ovoko_public_code_page', 'source_type' => 'public_ovoko_code_page', 'confidence' => 'none', 'checked_at' => $checked_at, 'raw_response' => array('url' => $url, 'error' => $response->get_error_message()));
        }
        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($http_code < 200 || $http_code >= 300) {
            return array('status' => 'endpoint_error', 'source' => 'ovoko_public_code_page', 'source_type' => 'public_ovoko_code_page', 'confidence' => 'none', 'checked_at' => $checked_at, 'raw_response' => array('url' => $url, 'http_code' => $http_code, 'body_excerpt' => substr($body, 0, 500)));
        }
        $parsed = $this->parse_ovoko_public_code_page_category($body, $code);
        if ($parsed['category_name'] === '') {
            return array('status' => 'no_suggestion', 'source' => 'ovoko_public_code_page', 'source_type' => 'public_ovoko_code_page', 'confidence' => 'none', 'checked_at' => $checked_at, 'raw_response' => array('url' => $url, 'http_code' => $http_code, 'body_excerpt' => substr(wp_strip_all_tags($body), 0, 800)));
        }
        return array('status' => $parsed['category_id'] !== '' ? 'completed' : 'no_code_specific_suggestion', 'category_id' => $parsed['category_id'], 'category_name' => $parsed['category_name'], 'source' => 'ovoko_public_code_page', 'source_type' => 'public_ovoko_code_page', 'confidence' => $parsed['category_id'] !== '' ? 'high' : 'none', 'checked_at' => $checked_at, 'raw_response' => array('url' => $url, 'http_code' => $http_code, 'parsed' => $parsed));
    }

    private function parse_ovoko_category_network_capture($request, $response)
    {
        $request = $this->normalize_ovoko_category_capture_request($request);
        $url = (string) ($request['url'] ?? '');
        $method = strtoupper((string) ($request['method'] ?? ''));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
        $parsed = $this->parse_ovoko_category_suggestion_payload($response);
        $classification = $this->classify_ovoko_category_prediction_endpoint($path, $request);
        $trusted = !empty($classification['has_code_parameter'])
            && $parsed['category_id'] !== ''
            && $parsed['category_name'] !== ''
            && !empty($classification['safe_to_call_from_wordpress'])
            && in_array((string) ($classification['endpoint_classification'] ?? ''), array('official_api', 'api_candidate'), true);
        return array(
            'status' => $trusted ? 'completed' : 'no_code_specific_suggestion',
            'category_id' => $trusted ? $parsed['category_id'] : '',
            'category_name' => $trusted ? $parsed['category_name'] : '',
            'parsed_category_id' => $parsed['category_id'],
            'parsed_category_name' => $parsed['category_name'],
            'source_type' => $trusted ? 'api_candidate_code_lookup' : (!empty($classification['category_tree_or_catalog']) ? 'category_tree_fallback' : 'unavailable'),
            'confidence' => $trusted ? 'high' : 'none',
            'source' => 'network_capture_parser:' . ($path !== '' ? $path : 'unknown_path'),
            'endpoint' => $path,
            'request_params' => (array) ($request['params_redacted'] ?? array()),
            'response_status' => (string) ($request['response_status'] ?? ''),
            'endpoint_classification' => (string) ($classification['endpoint_classification'] ?? 'unusable'),
            'safe_to_call_from_wordpress' => !empty($classification['safe_to_call_from_wordpress']),
            'requires_browser_session_cookies' => !empty($classification['requires_browser_session_cookies']),
            'uses_crm_importPart_credentials' => !empty($classification['uses_crm_importPart_credentials']),
            'code_specific' => !empty($classification['code_specific_endpoint']) && !empty($classification['has_code_parameter']),
            'detected_input_code' => (string) ($classification['detected_input_code'] ?? ''),
            'classification' => array_merge($classification, array('method' => $method, 'path' => $path, 'parsed_category_id' => $parsed['category_id'], 'parsed_category_name' => $parsed['category_name'])),
        );
    }

    private function normalize_ovoko_category_capture_request($request)
    {
        if (is_array($request)) {
            $request['headers'] = (array) ($request['headers'] ?? array());
            $request['body'] = $request['body'] ?? array();
            $request['params_redacted'] = $this->redacted_capture_params($request);
            return $request;
        }
        $text = trim((string) $request);
        $out = array('method' => '', 'url' => '', 'headers' => array(), 'body' => array(), 'response_status' => '', 'raw_request_excerpt' => substr($text, 0, 1200));
        if ($text === '') {
            $out['params_redacted'] = array();
            return $out;
        }
        if (preg_match('/\b(GET|POST|PUT|PATCH)\s+(https?:\/\/\S+|\/\S+)/i', $text, $m)) {
            $out['method'] = strtoupper($m[1]);
            $out['url'] = trim($m[2]);
        } elseif (preg_match('/URL\s*[:=]\s*(\S+)/i', $text, $m)) {
            $out['url'] = trim($m[1]);
        } elseif (preg_match('/https?:\/\/\S+/i', $text, $m)) {
            $out['url'] = trim($m[0]);
        }
        if (preg_match('/method\s*[:=]\s*(GET|POST|PUT|PATCH)/i', $text, $m)) {
            $out['method'] = strtoupper($m[1]);
        }
        if (preg_match('/status\s*[:=]\s*(\d{3})/i', $text, $m)) {
            $out['response_status'] = $m[1];
        }
        if (preg_match('/cookie\s*:/i', $text) || preg_match('/\b(PHPSESSID|sessionid|laravel_session|connect\.sid)\b/i', $text)) {
            $out['headers']['Cookie'] = '[redacted]';
        }
        if (preg_match('/authorization\s*:\s*bearer\s+/i', $text)) {
            $out['headers']['Authorization'] = 'Bearer [redacted]';
        }
        $body = array();
        foreach (array('manufacturer_code', 'part_code', 'part_number', 'code', 'q', 'query', 'search', 'username', 'password', 'user_token') as $key) {
            if (preg_match('/\b' . preg_quote($key, '/') . '\b\s*[:=]\s*([^\s,&]+)/i', $text, $m)) {
                $body[$key] = trim($m[1], "'\" ");
            }
        }
        $out['body'] = $body;
        $out['params_redacted'] = $this->redacted_capture_params($out);
        return $out;
    }

    private function redacted_capture_params($request)
    {
        $params = array();
        $url = (string) ($request['url'] ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?: '');
        if ($query !== '') {
            parse_str($query, $params);
        }
        if (is_array($request['body'] ?? null)) {
            $params = array_merge($params, (array) $request['body']);
        }
        foreach ($params as $key => $value) {
            if (preg_match('/password|token|session|cookie|authorization/i', (string) $key)) {
                $params[$key] = '[redacted]';
            }
        }
        return $params;
    }

    private function classify_ovoko_category_prediction_endpoint($path_or_url, $request = array())
    {
        $request = is_array($request) ? $request : array();
        $path = (string) (parse_url((string) $path_or_url, PHP_URL_PATH) ?: $path_or_url);
        $host = (string) (parse_url((string) $path_or_url, PHP_URL_HOST) ?: parse_url((string) ($request['url'] ?? ''), PHP_URL_HOST));
        $params = (array) ($request['params_redacted'] ?? $this->redacted_capture_params($request));
        $detected_code = '';
        foreach (array('manufacturer_code', 'part_code', 'part_number', 'code', 'q', 'query', 'search') as $key) {
            if (trim((string) ($params[$key] ?? '')) !== '') {
                $detected_code = trim((string) $params[$key]);
                break;
            }
        }
        $headers_text = strtolower(wp_json_encode((array) ($request['headers'] ?? array())));
        $requires_cookies = strpos($headers_text, 'cookie') !== false || strpos($headers_text, 'phpsessid') !== false || strpos($headers_text, 'session') !== false;
        $uses_crm_credentials = isset($params['username']) && isset($params['password']) && isset($params['user_token']);
        $category_tree_or_catalog = preg_match('#/categories(/tree)?$#i', $path) || preg_match('#/(part|parts)/categories$#i', $path);
        $code_specific_endpoint = preg_match('#/(v2/)?get/(part|parts)/category$#i', $path) || preg_match('#/categories/(suggest|predict|autocomplete)$#i', $path) || (preg_match('#categor#i', $path) && preg_match('#(suggest|predict|autocomplete)#i', $path));
        $official = preg_match('/(^|\.)rrr\.lt$/i', $host) || preg_match('/api\.rrr/i', $host) || $uses_crm_credentials;
        if ($requires_cookies || (!$official && preg_match('/ovoko|panel/i', $host))) {
            $endpoint_classification = 'panel_private';
        } elseif ($official && $code_specific_endpoint) {
            $endpoint_classification = preg_match('#^/v2/get/#i', $path) ? 'official_api' : 'api_candidate';
        } elseif ($code_specific_endpoint) {
            $endpoint_classification = 'api_candidate';
        } elseif ($category_tree_or_catalog) {
            $endpoint_classification = 'unusable';
        } else {
            $endpoint_classification = 'unusable';
        }
        $safe = in_array($endpoint_classification, array('official_api', 'api_candidate'), true) && !$requires_cookies && ($uses_crm_credentials || $official || $code_specific_endpoint);
        return array(
            'endpoint_classification' => $endpoint_classification,
            'safe_to_call_from_wordpress' => $safe,
            'requires_browser_session_cookies' => $requires_cookies,
            'uses_crm_importPart_credentials' => $uses_crm_credentials,
            'has_code_parameter' => $detected_code !== '',
            'detected_input_code' => $detected_code,
            'code_specific_endpoint' => (bool) $code_specific_endpoint,
            'category_tree_or_catalog' => (bool) $category_tree_or_catalog,
            'host' => $host,
            'path' => $path,
        );
    }

    private function parse_ovoko_category_suggestion_payload($payload)
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                return $this->parse_ovoko_public_code_page_category($payload, '');
            }
        }
        $flat = $this->flatten_array_for_category_suggestion((array) $payload);
        $id = '';
        $name = '';
        foreach ($flat as $path => $value) {
            if (!is_scalar($value) || trim((string) $value) === '') {
                continue;
            }
            $lower = strtolower((string) $path);
            if ($id === '' && preg_match('/(^|[._-])(category_id|categoryid|rrr_category_id|id)$/', $lower)) {
                $id = sanitize_text_field((string) $value);
            }
            if ($name === '' && preg_match('/(^|[._-])(category_name|category_title|category|name|title|label|pl|en)$/', $lower) && !preg_match('/(^|[._-])(status|code|id)$/', $lower)) {
                $candidate = sanitize_text_field((string) $value);
                if ($candidate !== '' && !preg_match('/^R\d+$/i', $candidate)) {
                    $name = $candidate;
                }
            }
        }
        return array('category_id' => $id, 'category_name' => $name);
    }

    private function parse_ovoko_public_code_page_category($html, $code)
    {
        $html = (string) $html;
        $segment = $html;
        $marker = 'Więcej ofert z kodem producenta';
        $marker_pos = stripos($html, $marker);
        if ($marker_pos !== false) {
            $segment = substr($html, $marker_pos);
        }
        $counts = array();
        if (preg_match_all('/<a\b[^>]*>(.*?)<\/a>/is', $segment, $matches)) {
            foreach ($matches[1] as $label_html) {
                $label = trim(html_entity_decode(wp_strip_all_tags($label_html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($this->looks_like_ovoko_part_category_label($label)) {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }
        if (!$counts && preg_match_all('/Image:\s*[^\n<]*?\s([\p{L}][\p{L}\s\/-]{2,60})\s*(?:†|<|\n)/u', $segment, $alt_matches)) {
            foreach ($alt_matches[1] as $label) {
                $label = trim((string) $label);
                if ($this->looks_like_ovoko_part_category_label($label)) {
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
        }
        arsort($counts);
        $category_name = (string) array_key_first($counts);
        if ($category_name === '' && preg_match('/Części z tym kodem należą do kategorii „([^”]+)”/u', wp_strip_all_tags($html), $m)) {
            $category_name = trim((string) $m[1]);
        }
        $resolved = $category_name !== '' ? $this->resolve_ovoko_category_id_for_suggestion($category_name) : array('category_id' => '', 'source' => '');
        return array('category_id' => (string) ($resolved['category_id'] ?? ''), 'category_name' => $category_name, 'category_counts' => $counts, 'category_id_resolution' => $resolved);
    }

    private function looks_like_ovoko_part_category_label($label)
    {
        $label = trim((string) $label);
        if ($label === '' || mb_strlen($label) > 80 || preg_match('/\d/', $label)) {
            return false;
        }
        $reject = array('Strona główna', 'Wyświetl', 'Zobacz wszystkie części', 'Polska', 'Litwa', 'Holandia', 'Audi', 'Volkswagen', 'Skoda', 'Seat', 'Cupra');
        return !in_array($label, $reject, true);
    }

    private function resolve_ovoko_category_id_for_suggestion($category_name)
    {
        $category_name = trim((string) $category_name);
        if ($category_name === '') {
            return array('category_id' => '', 'source' => 'empty_category_name');
        }
        if (function_exists('taxonomy_exists') && taxonomy_exists('product_cat') && function_exists('get_term_by')) {
            $term = get_term_by('name', $category_name, 'product_cat');
            if (!$term && function_exists('sanitize_title')) {
                $term = get_term_by('slug', sanitize_title($category_name), 'product_cat');
            }
            if ($term && !is_wp_error($term) && function_exists('get_term_meta')) {
                foreach (array('_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id') as $meta_key) {
                    $value = trim((string) get_term_meta((int) $term->term_id, $meta_key, true));
                    if ($value !== '') {
                        return array('category_id' => $value, 'source' => 'woo_term_exact_ovoko_id:' . $meta_key, 'woo_term_id' => (int) $term->term_id);
                    }
                }
            }
        }
        return array('category_id' => '', 'source' => 'not_found_without_broad_category_tree_fallback');
    }

    private function resolve_ovoko_category_id_from_rrr_catalog($category_name)
    {
        if (!$this->ovoko_credentials_detected() || !function_exists('wp_remote_post')) {
            return array('category_id' => '', 'source' => 'rrr_catalog_unavailable');
        }
        $settings = $this->ovoko_integration_settings();
        $base_url = rtrim((string) $settings['rrr_api_base_url'], '/');
        if ($base_url === '') {
            return array('category_id' => '', 'source' => 'rrr_catalog_base_url_missing');
        }
        $response = wp_remote_post($base_url . '/get/categories/tree', array('timeout' => 15, 'body' => array('username' => (string) $settings['rrr_api_username'], 'password' => (string) $settings['rrr_api_password'], 'user_token' => (string) $settings['rrr_api_user_token']), 'headers' => array('Content-Type' => 'application/x-www-form-urlencoded')));
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) < 200 || (int) wp_remote_retrieve_response_code($response) >= 300) {
            return array('category_id' => '', 'source' => 'rrr_catalog_endpoint_error');
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($decoded)) {
            return array('category_id' => '', 'source' => 'rrr_catalog_non_json');
        }
        $needle = $this->normalize_category_label_for_compare($category_name);
        foreach ($this->flatten_category_catalog_nodes($decoded) as $node) {
            $id = (string) ($node['id'] ?? $node['category_id'] ?? '');
            foreach (array('pl', 'en', 'lt', 'name', 'title', 'label') as $key) {
                $label = (string) ($node[$key] ?? '');
                if ($id !== '' && $this->normalize_category_label_for_compare($label) === $needle) {
                    return array('category_id' => $id, 'source' => 'rrr_categories_tree', 'matched_label_key' => $key, 'matched_node' => $node);
                }
            }
        }
        return array('category_id' => '', 'source' => 'rrr_catalog_no_match');
    }

    private function flatten_category_catalog_nodes($value)
    {
        $nodes = array();
        if (!is_array($value)) {
            return $nodes;
        }
        $has_id = isset($value['id']) || isset($value['category_id']);
        if ($has_id) {
            $nodes[] = $value;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $nodes = array_merge($nodes, $this->flatten_category_catalog_nodes($child));
            }
        }
        return $nodes;
    }

    private function flatten_array_for_category_suggestion($value, $prefix = '')
    {
        $flat = array();
        foreach ((array) $value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($child)) {
                $flat += $this->flatten_array_for_category_suggestion($child, $path);
            } else {
                $flat[$path] = $child;
            }
        }
        return $flat;
    }

    private function normalize_category_label_for_compare($label)
    {
        $label = function_exists('remove_accents') ? remove_accents((string) $label) : (string) $label;
        return strtolower(trim(preg_replace('/\s+/', ' ', $label)));
    }

    private function run_full_preparation_for_staging_item($item_id)
    {
        $enrichment = $this->run_ovoko_enrichment_for_staging_item($item_id);
        $category_suggestion = array();
        $match_count = absint(get_post_meta($item_id, '_gps_ovoko_match_count', true));
        $enrichment_status = (string) get_post_meta($item_id, '_gps_ovoko_enrichment_status', true);
        if ($match_count === 0 || $enrichment_status === 'no_match') {
            $category_suggestion = $this->run_ovoko_category_suggestion_for_staging_item($item_id, '', false);
        }
        $this->persist_fixed_import_category_meta_for_staging_item($item_id);
        $price = $this->run_ovoko_price_suggestion_for_staging_item($item_id, false);
        $mapping = $this->fixed_import_category_empty_price_allowed() ? array('action' => 'category_mapping', 'result' => 'fixed_import_category_manual_review', 'writes' => 'staging_meta_only') : $this->run_category_mapping_for_staging_item($item_id);
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        return array_merge(
            array('action' => 'full_preparation', 'result' => (string) ($readiness['result'] ?? 'needs_review'), 'writes' => 'staging_meta_only', 'no_woo_product_created' => true, 'no_ovoko_write' => true, 'no_allegro_call' => true),
            $this->full_preparation_summary_for_staging_item($item_id),
            array('steps' => array('ovoko_enrichment' => $enrichment, 'ovoko_category_suggestion' => $category_suggestion, 'ovoko_price_suggestion' => $price, 'category_mapping' => $mapping, 'readiness_validation' => $readiness))
        );
    }

    private function full_preparation_summary_for_staging_item($item_id)
    {
        $blocking = json_decode((string) get_post_meta($item_id, '_gps_woo_draft_blocking_reasons', true), true);
        return array(
            'staging_item_id' => (int) $item_id,
            'ovoko_enrichment_status' => get_post_meta($item_id, '_gps_ovoko_enrichment_status', true),
            'ovoko_match_count' => absint(get_post_meta($item_id, '_gps_ovoko_match_count', true)),
            'ovoko_selected_match_id' => get_post_meta($item_id, '_gps_ovoko_selected_match_id', true),
            'ovoko_category_id' => get_post_meta($item_id, '_gps_ovoko_category_id', true),
            'ovoko_category_name' => get_post_meta($item_id, '_gps_ovoko_category_name', true),
            'ovoko_category_suggestion_status' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_status', true),
            'ovoko_category_suggestion_code' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_code', true),
            'ovoko_category_suggestion_category_id' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_id', true),
            'ovoko_category_suggestion_category_name' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', true),
            'ovoko_category_suggestion_confidence' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_confidence', true),
            'ovoko_category_suggestion_source_type' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_source_type', true),
            'ovoko_price_suggestion_status' => get_post_meta($item_id, '_gps_ovoko_price_suggestion_status', true),
            'ovoko_price_suggestion_pln' => get_post_meta($item_id, '_gps_ovoko_price_suggestion_pln', true),
            'selected_price_source' => get_post_meta($item_id, '_gps_selected_price_source', true),
            'selected_price_pln' => get_post_meta($item_id, '_gps_selected_price_pln', true),
            'category_mapping_status' => get_post_meta($item_id, '_gps_category_mapping_status', true),
            'suggested_woo_category_id' => absint(get_post_meta($item_id, '_gps_suggested_woo_category_id', true)),
            'woo_draft_readiness_status' => get_post_meta($item_id, '_gps_woo_draft_readiness_status', true),
            'woo_draft_blocking_reasons' => is_array($blocking) ? $blocking : array(),
            'category_mode' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_source_type', true),
            'category_review_required' => get_post_meta($item_id, '_gps_ovoko_category_review_required', true) === '1',
            'fixed_category_id' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_id', true),
            'fixed_category_name' => get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', true),
            'empty_price_allowed' => get_post_meta($item_id, '_gps_price_required_for_woo_draft', true) === '0',
            'price_review_required' => get_post_meta($item_id, '_gps_price_required_for_woo_draft', true) === '0' && get_post_meta($item_id, '_gps_selected_price_pln', true) === '',
            'public_import_allowed' => false,
            'crm_only_no_price_strategy' => get_post_meta($item_id, '_gps_empty_price_allowed_reason', true) === 'fixed_category_crm_only_import_review_flow',
        );
    }


    private function run_ovoko_price_suggestion_for_staging_item($item_id, $refresh_readiness = true)
    {
        $settings = $this->settings();
        $checked_at = current_time('mysql', true);
        if (empty($settings['ovoko_price_fallback_enabled'])) {
            $result = array('status' => 'disabled', 'pln' => '', 'source' => '', 'currency' => '', 'raw_value' => '', 'checked_at' => $checked_at, 'notes' => 'Ovoko price suggestion extraction is disabled in settings.');
        } else {
            $raw = get_post_meta($item_id, '_gps_ovoko_raw_selected_match', true);
            $selected = is_array($raw) ? $raw : json_decode((string) $raw, true);
            if (!is_array($selected)) {
                $result = array('status' => 'no_price', 'pln' => '', 'source' => '', 'currency' => '', 'raw_value' => '', 'checked_at' => $checked_at, 'notes' => 'No selected Ovoko match JSON is available.');
            } else {
                $result = $this->extract_ovoko_price_suggestion($selected, $settings, $checked_at);
            }
        }
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_status', sanitize_text_field((string) $result['status']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_pln', sanitize_text_field((string) $result['pln']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_source', sanitize_text_field((string) $result['source']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_currency', sanitize_text_field((string) $result['currency']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_raw_value', sanitize_text_field((string) $result['raw_value']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_checked_at', sanitize_text_field((string) $result['checked_at']));
        update_post_meta($item_id, '_gps_ovoko_price_suggestion_notes', sanitize_textarea_field((string) $result['notes']));
        $readiness = $refresh_readiness ? $this->run_readiness_validation_for_staging_item($item_id) : array();
        return array('action' => 'ovoko_price_suggestion', 'staging_item_id' => $item_id, 'result' => $result['status'], 'price_pln' => $result['pln'], 'source' => $result['source'], 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }

    private function extract_ovoko_price_suggestion(array $selected, array $settings, $checked_at)
    {
        $prefer_notes = !empty($settings['ovoko_prefer_internal_notes_price']);
        $notes_value = $this->extract_numeric_price_from_text((string) ($selected['internal_notes'] ?? ''));
        if ($prefer_notes && $notes_value !== '') {
            return array('status' => 'completed', 'pln' => $this->format_manual_price_number($notes_value), 'source' => 'ovoko_internal_notes', 'currency' => 'PLN', 'raw_value' => (string) $selected['internal_notes'], 'checked_at' => $checked_at, 'notes' => 'Numeric price extracted from Ovoko internal_notes as PLN.');
        }
        $original_price = trim((string) ($selected['original_price'] ?? ''));
        $original_currency = strtoupper(trim((string) ($selected['original_currency'] ?? '')));
        if ($original_price !== '' && is_numeric(str_replace(',', '.', $original_price)) && $original_currency === 'PLN') {
            return array('status' => 'completed', 'pln' => $this->format_manual_price_number(str_replace(',', '.', $original_price)), 'source' => 'ovoko_original_price', 'currency' => 'PLN', 'raw_value' => $original_price, 'checked_at' => $checked_at, 'notes' => 'Original Ovoko price used as PLN.');
        }
        if (!$prefer_notes && $notes_value !== '') {
            return array('status' => 'completed', 'pln' => $this->format_manual_price_number($notes_value), 'source' => 'ovoko_internal_notes', 'currency' => 'PLN', 'raw_value' => (string) $selected['internal_notes'], 'checked_at' => $checked_at, 'notes' => 'Numeric price extracted from Ovoko internal_notes as PLN.');
        }
        $price = trim((string) ($selected['price'] ?? ''));
        $currency = strtoupper(trim((string) ($selected['currency'] ?? '')));
        if ($price !== '' && is_numeric(str_replace(',', '.', $price))) {
            $normalized_price = str_replace(',', '.', $price);
            if ($currency === 'PLN') {
                return array('status' => 'completed', 'pln' => $this->format_manual_price_number($normalized_price), 'source' => 'ovoko_price_pln', 'currency' => 'PLN', 'raw_value' => $price, 'checked_at' => $checked_at, 'notes' => 'Ovoko price used as PLN.');
            }
            if ($currency === 'EUR') {
                $rate = trim((string) ($settings['ovoko_eur_to_pln_fallback_rate'] ?? ''));
                if ($rate !== '' && is_numeric(str_replace(',', '.', $rate)) && (float) str_replace(',', '.', $rate) > 0) {
                    return array('status' => 'completed', 'pln' => $this->format_manual_price_number(((float) $normalized_price) * ((float) str_replace(',', '.', $rate))), 'source' => 'ovoko_price_eur_converted', 'currency' => 'PLN', 'raw_value' => $price, 'checked_at' => $checked_at, 'notes' => 'Ovoko EUR price converted to PLN using configured fallback rate ' . str_replace(',', '.', $rate) . '.');
                }
                return array('status' => 'needs_conversion', 'pln' => '', 'source' => 'ovoko_price_eur', 'currency' => 'EUR', 'raw_value' => $price, 'checked_at' => $checked_at, 'notes' => 'Ovoko price is EUR and no EUR→PLN fallback rate is configured.');
            }
        }
        return array('status' => 'no_price', 'pln' => '', 'source' => '', 'currency' => '', 'raw_value' => '', 'checked_at' => $checked_at, 'notes' => 'Selected Ovoko match does not contain a usable price suggestion.');
    }

    private function extract_numeric_price_from_text($text)
    {
        if (preg_match('/(?<!\d)(\d+(?:[\.,]\d+)?)(?!\d)/', (string) $text, $matches)) {
            return str_replace(',', '.', $matches[1]);
        }
        return '';
    }

    private function allegro_unavailable_message_from_meta($meta)
    {
        $status = strtolower(trim((string) ($meta['_gps_allegro_price_research_status'] ?? '')));
        $code = trim((string) ($meta['_gps_allegro_price_error_code'] ?? ''));
        $http = trim((string) ($meta['_gps_allegro_price_error_http_status'] ?? ''));
        $notes = trim((string) ($meta['_gps_allegro_price_notes'] ?? ''));
        $response = is_scalar($meta['_gps_allegro_price_error_response'] ?? '') ? (string) ($meta['_gps_allegro_price_error_response'] ?? '') : wp_json_encode($meta['_gps_allegro_price_error_response'] ?? array());
        $haystack = strtolower($status . ' ' . $code . ' ' . $http . ' ' . $notes . ' ' . $response);
        if (strpos($haystack, 'accessdenied') !== false || strpos($haystack, 'access denied') !== false || $http === '403' || in_array($status, array('error', 'unavailable'), true)) {
            return trim('HTTP ' . $http . ' ' . $code . ' ' . $notes);
        }
        return '';
    }

    private function run_allegro_price_research_for_staging_item($item_id)
    {
        $settings = $this->settings();
        $checked_at = current_time('mysql', true);
        if (!$this->allegro_credentials_configured($settings)) {
            $this->persist_allegro_price_research($item_id, array(
                'status' => 'not_configured',
                'checked_at' => $checked_at,
                'query' => '',
                'raw_offer_count' => 0,
                'filtered_offer_count' => 0,
                'median_pln' => '',
                'min_pln' => '',
                'max_pln' => '',
                'confidence' => 'not_configured',
                'sample_offer_urls' => array(),
                'source' => '',
                'suggestion' => '',
                'currency' => '',
                'notes' => 'Optional Allegro diagnostics are disabled or not configured. No marketplace request or selected-price write was performed.',
            ));
            return array('action' => 'allegro_price_research', 'staging_item_id' => $item_id, 'result' => 'not_configured', 'writes' => 'staging_meta_only');
        }

        $queries = $this->allegro_queries_for_staging_item($item_id);
        if (!$queries) {
            $this->persist_allegro_price_research($item_id, array(
                'status' => 'no_query',
                'checked_at' => $checked_at,
                'query' => '',
                'raw_offer_count' => 0,
                'filtered_offer_count' => 0,
                'median_pln' => '',
                'min_pln' => '',
                'max_pln' => '',
                'confidence' => 'no_match',
                'sample_offer_urls' => array(),
                'source' => 'allegro_api',
                'suggestion' => '',
                'currency' => '',
                'notes' => 'No detected part code/OEM was available for Allegro price research.',
            ));
            return array('action' => 'allegro_price_research', 'staging_item_id' => $item_id, 'result' => 'no_query', 'writes' => 'staging_meta_only');
        }

        $offers = array();
        $errors = array();
        foreach ($queries as $query) {
            $lookup = $this->allegro_search_offers($query, $settings);
            if (is_wp_error($lookup)) {
                $errors[] = array(
                    'message' => $lookup->get_error_message(),
                    'data' => $lookup->get_error_data(),
                );
                continue;
            }
            foreach ((array) ($lookup['offers'] ?? array()) as $offer) {
                $offer['_gps_query'] = $query;
                $offers[] = $offer;
            }
        }

        if (!$offers && $errors) {
            $first_error = $errors[0] ?? array();
            $first_diagnostics = $first_error['data']['diagnostics'] ?? array();
            $error_messages = array_map(function ($error) {
                return is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
            }, $errors);
            $previous_selected_price = get_post_meta($item_id, '_gps_selected_price_pln', true);
            $previous_selected_source = get_post_meta($item_id, '_gps_selected_price_source', true);
            $previous_selected_checked_at = get_post_meta($item_id, '_gps_selected_price_checked_at', true);
            $this->persist_allegro_price_research($item_id, array(
                'status' => 'api_error',
                'checked_at' => $checked_at,
                'query' => implode(', ', $queries),
                'raw_offer_count' => 0,
                'filtered_offer_count' => 0,
                'median_pln' => '',
                'min_pln' => '',
                'max_pln' => '',
                'confidence' => 'no_match',
                'sample_offer_urls' => array(),
                'source' => 'allegro_api',
                'suggestion' => '',
                'currency' => '',
                'notes' => 'Allegro API request failed: ' . implode(' | ', array_slice($error_messages, 0, 3)),
                'error_http_status' => absint($first_diagnostics['http_status'] ?? 0),
                'error_response' => (string) ($first_diagnostics['sanitized_response_body'] ?? ''),
                'error_code' => (string) ($first_diagnostics['classification'] ?? ''),
                'error_checked_at' => $checked_at,
            ));
            $readiness = $this->run_readiness_validation_for_staging_item($item_id);
            $preserved_selected_price = in_array((string) $previous_selected_source, array('manual_override', 'ovoko_price_suggestion'), true) && (float) $previous_selected_price > 0;
            if ($preserved_selected_price) {
                update_post_meta($item_id, '_gps_selected_price_pln', sanitize_text_field((string) $previous_selected_price));
                update_post_meta($item_id, '_gps_selected_price_source', sanitize_text_field((string) $previous_selected_source));
                update_post_meta($item_id, '_gps_selected_price_checked_at', sanitize_text_field((string) $previous_selected_checked_at));
            }
            return array('action' => 'allegro_price_research', 'staging_item_id' => $item_id, 'result' => 'api_error', 'errors' => $error_messages, 'diagnostics' => $first_diagnostics, 'readiness' => $readiness, 'selected_price_preserved' => $preserved_selected_price, 'writes' => 'staging_meta_only');
        }

        $analysis = $this->analyze_allegro_price_offers($queries, $offers, $settings);
        $analysis['checked_at'] = $checked_at;
        $analysis['source'] = 'allegro_api';
        $this->persist_allegro_price_research($item_id, $analysis);
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        return array('action' => 'allegro_price_research', 'staging_item_id' => $item_id, 'result' => $analysis['status'], 'query' => $analysis['query'], 'raw_offer_count' => $analysis['raw_offer_count'], 'filtered_offer_count' => $analysis['filtered_offer_count'], 'confidence' => $analysis['confidence'], 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }

    private function allegro_credentials_configured($settings = null)
    {
        $settings = $settings === null ? $this->settings() : (array) $settings;
        if (empty($settings['allegro_api_enabled'])) {
            return false;
        }
        $has_access_token = trim((string) ($settings['allegro_access_token'] ?? '')) !== '';
        $has_refresh_credentials = trim((string) ($settings['allegro_refresh_token'] ?? '')) !== '' && trim((string) ($settings['allegro_client_id'] ?? '')) !== '' && trim((string) ($settings['allegro_client_secret'] ?? '')) !== '';
        $has_client_credentials = trim((string) ($settings['allegro_client_id'] ?? '')) !== '' && trim((string) ($settings['allegro_client_secret'] ?? '')) !== '';
        return $has_access_token || $has_refresh_credentials || $has_client_credentials;
    }

    private function allegro_queries_for_staging_item($item_id)
    {
        $queries = array(
            get_post_meta($item_id, '_gps_normalized_part_code', true),
            get_post_meta($item_id, '_gps_normalized_oem_part_number', true),
            get_post_meta($item_id, '_gps_detected_part_code', true),
            get_post_meta($item_id, '_gps_detected_oem_part_number', true),
        );
        $ovoko_oems = get_post_meta($item_id, '_gps_ovoko_oem_numbers', true);
        if (is_string($ovoko_oems)) {
            $decoded = json_decode($ovoko_oems, true);
            $ovoko_oems = is_array($decoded) ? $decoded : preg_split('/[,;\s]+/', $ovoko_oems);
        }
        foreach ((array) $ovoko_oems as $oem) {
            $queries[] = $oem;
        }
        $normalized = array();
        foreach ($queries as $query) {
            $query = $this->normalize_allegro_oem_query($query);
            if ($query !== '') {
                $normalized[] = $query;
            }
        }
        return array_values(array_unique($normalized));
    }

    private function normalize_allegro_oem_query($query)
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string) $query)));
    }

    private function allegro_environment($settings)
    {
        return (($settings['allegro_environment'] ?? 'production') === 'sandbox') ? 'sandbox' : 'production';
    }

    private function allegro_api_base_url($settings)
    {
        return $this->allegro_environment($settings) === 'sandbox' ? 'https://api.allegro.pl.allegrosandbox.pl' : 'https://api.allegro.pl';
    }

    private function allegro_auth_token_url($settings)
    {
        return $this->allegro_environment($settings) === 'sandbox' ? 'https://allegro.pl.allegrosandbox.pl/auth/oauth/token' : 'https://allegro.pl/auth/oauth/token';
    }

    private function allegro_listing_request($query, $settings, $token, $limit = 1)
    {
        $country = $this->sanitize_allegro_country($settings['allegro_marketplace_country'] ?? 'PL');
        $marketplace = array('PL' => 'allegro-pl', 'CZ' => 'allegro-cz', 'SK' => 'allegro-sk')[$country];
        $currency = array('PL' => 'PLN', 'CZ' => 'CZK', 'SK' => 'EUR')[$country];
        return array(
            'url' => add_query_arg(array(
                'phrase' => $query,
                'marketplaceId' => $marketplace,
                'shipping.country' => $country,
                'currency' => $currency,
                'limit' => max(1, min(60, absint($limit))),
                'fallback' => 'false',
            ), $this->allegro_api_base_url($settings) . '/offers/listing'),
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/vnd.allegro.public.v1+json',
            ),
        );
    }

    private function test_allegro_api_connection()
    {
        $settings = $this->settings();
        $context = array();
        $token = $this->allegro_access_token($settings, $context);
        $request = array(
            'url' => $this->allegro_api_base_url($settings) . '/offers/listing',
            'headers' => array('Accept' => 'application/vnd.allegro.public.v1+json'),
        );
        if (!is_wp_error($token)) {
            $request = $this->allegro_listing_request('test', $settings, $token, 1);
            $response = wp_remote_get($request['url'], array('timeout' => 20, 'headers' => $request['headers']));
            return $this->allegro_connection_diagnostics($response, $request, $settings, $context);
        }
        $error_data = $token->get_error_data();
        $diagnostics = is_array($error_data) && !empty($error_data['diagnostics']) ? (array) $error_data['diagnostics'] : $this->allegro_connection_diagnostics(null, $request, $settings, $context);
        $diagnostics['result'] = 'token_error';
        $diagnostics['error'] = $token->get_error_message();
        if (($diagnostics['classification'] ?? 'not_403') === 'not_403') {
            $diagnostics['classification'] = empty($context['access_token_exists']) ? 'missing_header' : 'invalid_token';
        }
        return $diagnostics;
    }

    private function allegro_connection_diagnostics($response, $request, $settings, $context = array())
    {
        $status = is_wp_error($response) || $response === null ? 0 : (int) wp_remote_retrieve_response_code($response);
        $raw_body = is_wp_error($response) || $response === null ? '' : (string) wp_remote_retrieve_body($response);
        $response_headers = is_wp_error($response) || $response === null ? array() : $this->normalize_http_headers(wp_remote_retrieve_headers($response));
        $sanitized_body = $this->sanitize_allegro_response_body($raw_body);
        return array(
            'action' => 'allegro_api_connection_test',
            'result' => ($status >= 200 && $status < 300) ? 'success' : 'error',
            'endpoint_url' => (string) ($request['url'] ?? ''),
            'environment' => $this->allegro_environment($settings),
            'access_token_exists' => !empty($context['access_token_exists']),
            'token_expiry' => $context['token_expiry'] ?? $this->format_allegro_token_expiry($settings['allegro_token_expires_at'] ?? ''),
            'refresh_attempted' => !empty($context['refresh_attempted']),
            'http_status' => $status,
            'sanitized_response_body' => $sanitized_body,
            'allegro_trace_request_id_headers' => $this->extract_trace_request_headers($response_headers),
            'headers_used_sanitized' => $this->sanitize_allegro_headers((array) ($request['headers'] ?? array())),
            'classification' => $this->classify_allegro_api_response($status, $sanitized_body, $response_headers, (array) ($request['headers'] ?? array()), $settings),
        );
    }

    private function allegro_wp_error_with_diagnostics($error, $response, $request, $settings, $context)
    {
        $diagnostics = $this->allegro_connection_diagnostics($response, $request, $settings, $context);
        $data = is_array($error->get_error_data()) ? $error->get_error_data() : array();
        $data['diagnostics'] = $diagnostics;
        return new WP_Error($error->get_error_code(), $error->get_error_message(), $data);
    }

    private function normalize_http_headers($headers)
    {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        } elseif (is_object($headers)) {
            $headers = (array) $headers;
        }
        $normalized = array();
        foreach ((array) $headers as $key => $value) {
            $normalized[strtolower((string) $key)] = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }
        return $normalized;
    }

    private function sanitize_allegro_headers($headers)
    {
        $sanitized = array();
        foreach ((array) $headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, array('authorization', 'proxy-authorization', 'cookie', 'set-cookie'), true) || strpos($lower, 'token') !== false || strpos($lower, 'secret') !== false) {
                $sanitized[$key] = '[redacted]';
            } else {
                $sanitized[$key] = is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : sanitize_text_field((string) $value);
            }
        }
        return $sanitized;
    }

    private function sanitize_allegro_response_body($raw_body)
    {
        $raw_body = (string) $raw_body;
        $decoded = json_decode($raw_body, true);
        if (is_array($decoded)) {
            $clean = $this->redact_sensitive_payload($decoded);
            return wp_json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $clean = preg_replace('/(access_token|refresh_token|token|client_secret|authorization)(["\'\s:=]+)([^"\'\s,&}]+)/i', '$1$2[redacted]', $raw_body);
        return mb_substr(wp_strip_all_tags((string) $clean), 0, 4000);
    }

    private function redact_sensitive_payload($payload)
    {
        $redacted = array();
        foreach ((array) $payload as $key => $value) {
            $lower = strtolower((string) $key);
            if (strpos($lower, 'token') !== false || strpos($lower, 'secret') !== false || $lower === 'authorization' || $lower === 'password') {
                $redacted[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact_sensitive_payload($value);
            } else {
                $redacted[$key] = is_string($value) ? mb_substr($value, 0, 1000) : $value;
            }
        }
        return $redacted;
    }

    private function extract_trace_request_headers($headers)
    {
        $matches = array();
        foreach ((array) $headers as $key => $value) {
            $lower = strtolower((string) $key);
            if (strpos($lower, 'trace') !== false || strpos($lower, 'request') !== false || strpos($lower, 'correlation') !== false || strpos($lower, 'allegro') !== false) {
                $matches[$key] = sanitize_text_field((string) $value);
            }
        }
        return $matches;
    }

    private function classify_allegro_api_response($status, $body, $response_headers, $request_headers, $settings)
    {
        $text = strtolower((string) $body . ' ' . wp_json_encode($response_headers));
        if (empty($request_headers['Authorization'])) {
            return 'missing_header';
        }
        if (strpos($text, 'expired') !== false) {
            return 'expired_token';
        }
        if (strpos($text, 'invalid_token') !== false || strpos($text, 'invalid token') !== false || strpos($text, 'unauthorized') !== false) {
            return 'invalid_token';
        }
        if (strpos($text, 'sandbox') !== false || strpos($text, 'production') !== false || strpos($text, 'environment') !== false || strpos($text, 'issuer') !== false) {
            return 'wrong_environment';
        }
        if (strpos($text, 'scope') !== false || strpos($text, 'forbidden') !== false || strpos($text, 'permission') !== false) {
            return 'forbidden_scope';
        }
        if (strpos($text, 'missing') !== false && (strpos($text, 'header') !== false || strpos($text, 'authorization') !== false || strpos($text, 'accept') !== false)) {
            return 'missing_header';
        }
        if ((int) $status === 403) {
            return 'unknown_403';
        }
        return 'not_403';
    }

    private function format_allegro_token_expiry($value)
    {
        $timestamp = $this->allegro_token_expiry_timestamp($value);
        if (!$timestamp) {
            return '';
        }
        return gmdate('Y-m-d H:i:s', $timestamp) . ' UTC (' . $timestamp . ')';
    }

    private function allegro_search_offers($query, $settings)
    {
        $token_context = array();
        $token = $this->allegro_access_token($settings, $token_context);
        if (is_wp_error($token)) {
            return $token;
        }
        $request = $this->allegro_listing_request($query, $settings, $token, max(1, min(60, absint($settings['allegro_search_limit'] ?? 20))));
        $response = wp_remote_get($request['url'], array(
            'timeout' => 20,
            'headers' => $request['headers'],
        ));
        $body = $this->allegro_json_response($response);
        if (is_wp_error($body)) {
            return $this->allegro_wp_error_with_diagnostics($body, $response, $request, $settings, $token_context);
        }
        return array('offers' => $this->extract_allegro_listing_offers($body));
    }

    private function allegro_access_token($settings, &$context = null)
    {
        $settings = (array) $settings;
        $token = trim((string) ($settings['allegro_access_token'] ?? ''));
        $expires_at = $this->allegro_token_expiry_timestamp($settings['allegro_token_expires_at'] ?? '');
        if (is_array($context)) {
            $context['access_token_exists'] = $token !== '';
            $context['token_expiry'] = $this->format_allegro_token_expiry($settings['allegro_token_expires_at'] ?? '');
            $context['refresh_attempted'] = false;
        }
        if ($token !== '' && (!$expires_at || time() < $expires_at - 60)) {
            return $token;
        }
        if (trim((string) ($settings['allegro_refresh_token'] ?? '')) !== '' && trim((string) ($settings['allegro_client_id'] ?? '')) !== '' && trim((string) ($settings['allegro_client_secret'] ?? '')) !== '') {
            if (is_array($context)) {
                $context['refresh_attempted'] = true;
            }
            return $this->refresh_allegro_token($settings);
        }
        if (trim((string) ($settings['allegro_client_id'] ?? '')) !== '' && trim((string) ($settings['allegro_client_secret'] ?? '')) !== '') {
            return $this->request_allegro_client_credentials_token($settings);
        }
        return new WP_Error('gps_allegro_not_configured', 'Allegro API credentials are missing.');
    }

    private function refresh_allegro_token($settings)
    {
        $response = wp_remote_post($this->allegro_auth_token_url($settings), array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Basic ' . base64_encode((string) $settings['allegro_client_id'] . ':' . (string) $settings['allegro_client_secret'])),
            'body' => array('grant_type' => 'refresh_token', 'refresh_token' => (string) $settings['allegro_refresh_token']),
        ));
        return $this->store_allegro_token_response($response, $settings);
    }

    private function request_allegro_client_credentials_token($settings)
    {
        $response = wp_remote_post($this->allegro_auth_token_url($settings), array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Basic ' . base64_encode((string) $settings['allegro_client_id'] . ':' . (string) $settings['allegro_client_secret'])),
            'body' => array('grant_type' => 'client_credentials'),
        ));
        return $this->store_allegro_token_response($response, $settings);
    }

    private function store_allegro_token_response($response, $settings)
    {
        $body = $this->allegro_json_response($response);
        if (is_wp_error($body)) {
            $request = array(
                'url' => $this->allegro_auth_token_url($settings),
                'headers' => array('Authorization' => 'Basic ' . base64_encode((string) $settings['allegro_client_id'] . ':' . (string) $settings['allegro_client_secret'])),
            );
            $diagnostics = $this->allegro_connection_diagnostics($response, $request, $settings, array(
                'access_token_exists' => trim((string) ($settings['allegro_access_token'] ?? '')) !== '',
                'token_expiry' => $this->format_allegro_token_expiry($settings['allegro_token_expires_at'] ?? ''),
                'refresh_attempted' => trim((string) ($settings['allegro_refresh_token'] ?? '')) !== '',
            ));
            return new WP_Error($body->get_error_code(), $body->get_error_message(), array('diagnostics' => $diagnostics));
        }
        $access_token = sanitize_text_field($body['access_token'] ?? '');
        if ($access_token === '') {
            return new WP_Error('gps_allegro_missing_access_token', 'Allegro token response did not include an access token.');
        }
        $settings['allegro_access_token'] = $access_token;
        $settings['allegro_refresh_token'] = sanitize_text_field($body['refresh_token'] ?? ($settings['allegro_refresh_token'] ?? ''));
        $settings['allegro_token_expires_at'] = (string) (time() + absint($body['expires_in'] ?? 43200));
        update_option(self::OPTION_SETTINGS, $settings, false);
        return $access_token;
    }

    private function allegro_token_expiry_timestamp($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $parsed = strtotime($value);
        return $parsed ? (int) $parsed : 0;
    }

    private function allegro_json_response($response)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('gps_allegro_http_error', 'Allegro API request failed with HTTP ' . $code, $body);
        }
        return is_array($body) ? $body : array();
    }

    private function extract_allegro_listing_offers($body)
    {
        $offers = array();
        if (isset($body['items']) && is_array($body['items'])) {
            foreach (array('promoted', 'regular') as $bucket) {
                foreach ((array) ($body['items'][$bucket] ?? array()) as $offer) {
                    if (is_array($offer)) {
                        $offers[] = $offer;
                    }
                }
            }
        }
        foreach ((array) ($body['offers'] ?? array()) as $offer) {
            if (is_array($offer)) {
                $offers[] = $offer;
            }
        }
        return $offers;
    }

    private function analyze_allegro_price_offers($queries, $offers, $settings = array())
    {
        $queries = array_values(array_filter(array_map(array($this, 'normalize_allegro_oem_query'), (array) $queries)));
        $raw = array();
        foreach ((array) $offers as $offer) {
            $id = (string) ($offer['id'] ?? md5(wp_json_encode($offer)));
            $raw[$id] = $offer;
        }
        $filtered = array();
        foreach ($raw as $offer) {
            $price = $this->allegro_offer_price_pln($offer);
            if ($price <= 0) {
                continue;
            }
            $title = (string) ($offer['name'] ?? $offer['title'] ?? '');
            if (!$this->allegro_offer_text_matches_queries($title, $queries)) {
                continue;
            }
            if ($this->allegro_offer_title_is_excluded($title)) {
                continue;
            }
            $filtered[] = array('offer' => $offer, 'price' => $price, 'url' => $this->allegro_offer_url($offer));
        }
        $prices = array_values(array_map(function ($item) { return (float) $item['price']; }, $filtered));
        sort($prices, SORT_NUMERIC);
        $count = count($prices);
        $median = $count ? $this->median($prices) : 0;
        $min = $count ? min($prices) : 0;
        $max = $count ? max($prices) : 0;
        $spread_reasonable = $min > 0 ? (($max / $min) <= 3.0) : false;
        $confidence = 'no_match';
        if ($count >= 5 && $spread_reasonable) {
            $confidence = 'high';
        } elseif ($count >= 2) {
            $confidence = 'medium';
        } elseif ($count === 1) {
            $confidence = 'low';
        }
        $method = (string) ($settings['allegro_price_statistic_method'] ?? 'median');
        $suggestion = $median;
        if ($method === 'min') {
            $suggestion = $min;
        } elseif ($method === 'max') {
            $suggestion = $max;
        }
        $sample_urls = array_values(array_filter(array_unique(array_map(function ($item) { return $item['url']; }, array_slice($filtered, 0, 5)))));
        return array(
            'status' => $count > 0 ? 'completed' : 'no_match',
            'query' => implode(', ', $queries),
            'raw_offer_count' => count($raw),
            'filtered_offer_count' => $count,
            'median_pln' => $count ? $this->format_price_number($median) : '',
            'min_pln' => $count ? $this->format_price_number($min) : '',
            'max_pln' => $count ? $this->format_price_number($max) : '',
            'confidence' => $confidence,
            'sample_offer_urls' => $sample_urls,
            'suggestion' => $count ? $this->format_price_number($suggestion) : '',
            'currency' => $count ? 'PLN' : '',
            'notes' => $count ? sprintf('Allegro API price research completed from %d filtered offers out of %d raw offers. Statistic: %s.', $count, count($raw), $method ?: 'median') : sprintf('Allegro API returned %d raw offers, but none matched the OEM/code and price filters.', count($raw)),
        );
    }

    private function allegro_offer_price_pln($offer)
    {
        $price = $offer['sellingMode']['price'] ?? ($offer['price'] ?? array());
        $currency = strtoupper((string) ($price['currency'] ?? 'PLN'));
        if ($currency !== 'PLN') {
            return 0;
        }
        return (float) str_replace(',', '.', (string) ($price['price'] ?? 0));
    }

    private function allegro_offer_text_matches_queries($text, $queries)
    {
        $normalized_text = $this->normalize_allegro_oem_query($text);
        foreach ($queries as $query) {
            if ($query !== '' && strpos($normalized_text, $query) !== false) {
                return true;
            }
        }
        return false;
    }

    private function allegro_offer_title_is_excluded($title)
    {
        $title = strtolower((string) $title);
        $bad_terms = array('uszkodz', 'regenerac', 'regenerowany', 'napraw', 'serwis', 'czyszczen', 'kasowanie', 'emulator', 'pusty', 'wyciety', 'wycięty');
        foreach ($bad_terms as $term) {
            if (strpos($title, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    private function allegro_offer_url($offer)
    {
        if (!empty($offer['url'])) {
            return esc_url_raw($offer['url']);
        }
        if (!empty($offer['id'])) {
            return esc_url_raw('https://allegro.pl/oferta/' . rawurlencode((string) $offer['id']));
        }
        return '';
    }

    private function median($values)
    {
        $values = array_values(array_map('floatval', (array) $values));
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if (!$count) {
            return 0;
        }
        $middle = (int) floor($count / 2);
        return $count % 2 ? $values[$middle] : (($values[$middle - 1] + $values[$middle]) / 2);
    }

    private function format_price_number($value)
    {
        return number_format((float) $value, 2, '.', '');
    }

    private function format_manual_price_number($value)
    {
        $formatted = $this->format_price_number($value);
        $trimmed = rtrim(rtrim($formatted, '0'), '.');
        return $trimmed === '' ? '0' : $trimmed;
    }

    private function persist_allegro_price_research($item_id, $analysis)
    {
        update_post_meta($item_id, '_gps_allegro_price_research_status', sanitize_text_field((string) ($analysis['status'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_research_checked_at', sanitize_text_field((string) ($analysis['checked_at'] ?? current_time('mysql', true))));
        update_post_meta($item_id, '_gps_allegro_price_query', sanitize_text_field((string) ($analysis['query'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_raw_offer_count', absint($analysis['raw_offer_count'] ?? 0));
        update_post_meta($item_id, '_gps_allegro_price_filtered_offer_count', absint($analysis['filtered_offer_count'] ?? 0));
        update_post_meta($item_id, '_gps_allegro_price_median_pln', sanitize_text_field((string) ($analysis['median_pln'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_min_pln', sanitize_text_field((string) ($analysis['min_pln'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_max_pln', sanitize_text_field((string) ($analysis['max_pln'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_confidence', sanitize_text_field((string) ($analysis['confidence'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_sample_offer_urls', wp_json_encode(array_values((array) ($analysis['sample_offer_urls'] ?? array())), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        update_post_meta($item_id, '_gps_allegro_price_source', sanitize_text_field((string) ($analysis['source'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_suggestion', sanitize_text_field((string) ($analysis['suggestion'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_currency', sanitize_text_field((string) ($analysis['currency'] ?? '')));
        update_post_meta($item_id, '_gps_allegro_price_notes', sanitize_textarea_field((string) ($analysis['notes'] ?? '')));
        if (array_key_exists('error_http_status', $analysis)) {
            update_post_meta($item_id, '_gps_allegro_price_error_http_status', absint($analysis['error_http_status']));
        }
        if (array_key_exists('error_response', $analysis)) {
            update_post_meta($item_id, '_gps_allegro_price_error_response', sanitize_textarea_field((string) $analysis['error_response']));
        }
        if (array_key_exists('error_code', $analysis)) {
            update_post_meta($item_id, '_gps_allegro_price_error_code', sanitize_text_field((string) $analysis['error_code']));
        }
        if (array_key_exists('error_checked_at', $analysis)) {
            update_post_meta($item_id, '_gps_allegro_price_error_checked_at', sanitize_text_field((string) $analysis['error_checked_at']));
        }
    }

    private function run_category_mapping_for_staging_item($item_id)
    {
        $path = (string) get_post_meta($item_id, '_gps_suggested_woo_category_path', true);
        $category_id = absint(get_post_meta($item_id, '_gps_suggested_woo_category_id', true));
        $source = (string) get_post_meta($item_id, '_gps_suggested_category_source', true);
        if (in_array($source, array('ovoko_category_prediction_by_code', 'ovoko_category_suggestion_by_code'), true) && !$this->ovoko_category_suggestion_meta_allows_mapping($item_id)) {
            $path = '';
            $category_id = 0;
            $source = '';
            delete_post_meta($item_id, '_gps_suggested_woo_category_id');
            delete_post_meta($item_id, '_gps_suggested_woo_category_path');
            delete_post_meta($item_id, '_gps_suggested_woo_category_confidence');
            delete_post_meta($item_id, '_gps_suggested_category_source');
        }
        if (!$category_id) {
            $mapped = $this->woo_category_mapping_candidate_from_staging_item($item_id);
            $category_id = absint($mapped['id']);
            if ($category_id) {
                $path = (string) $mapped['path'];
                $source = (string) $mapped['source'];
                update_post_meta($item_id, '_gps_suggested_woo_category_id', $category_id);
                update_post_meta($item_id, '_gps_suggested_woo_category_path', sanitize_text_field($path));
                update_post_meta($item_id, '_gps_suggested_woo_category_confidence', sanitize_text_field((string) $mapped['confidence']));
                update_post_meta($item_id, '_gps_suggested_category_source', sanitize_text_field($source));
            }
        }
        if (!$category_id && $path !== '' && !in_array($source, array('ovoko_category_prediction_by_code', 'ovoko_category_suggestion_by_code'), true) && taxonomy_exists('product_cat')) {
            $category_name = trim((string) basename(str_replace('>', '/', $path)));
            $term = $category_name ? get_term_by('name', $category_name, 'product_cat') : false;
            if (!$term && $category_name) {
                $term = get_term_by('slug', sanitize_title($category_name), 'product_cat');
            }
            if ($term && !is_wp_error($term)) {
                $category_id = (int) $term->term_id;
                update_post_meta($item_id, '_gps_suggested_woo_category_id', $category_id);
            }
        }
        $status = $category_id ? 'mapped' : ($this->missing_ovoko_category_resolution_required($item_id) ? 'missing_ovoko_category_resolution' : 'needs_manual_mapping');
        update_post_meta($item_id, '_gps_category_mapping_status', $status);
        update_post_meta($item_id, '_gps_category_mapping_checked_at', current_time('mysql', true));
        if (!$source) {
            update_post_meta($item_id, '_gps_suggested_category_source', $category_id ? 'import_queue_mapping' : 'manual_review_required');
        }
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        return array('action' => 'category_mapping', 'staging_item_id' => $item_id, 'result' => $status, 'suggested_woo_category_id' => $category_id, 'suggested_category_source' => get_post_meta($item_id, '_gps_suggested_category_source', true), 'readiness' => $readiness, 'writes' => 'staging_meta_only');
    }

    private function ovoko_category_suggestion_meta_allows_mapping($item_id)
    {
        return get_post_meta($item_id, '_gps_ovoko_category_suggestion_status', true) === 'completed'
            && get_post_meta($item_id, '_gps_ovoko_category_suggestion_confidence', true) === 'high'
            && in_array((string) get_post_meta($item_id, '_gps_ovoko_category_suggestion_source_type', true), self::OVOKO_CATEGORY_SUGGESTION_HIGH_TRUST_SOURCE_TYPES, true)
            && trim((string) get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_id', true)) !== '';
    }

    private function missing_ovoko_category_resolution_required($item_id)
    {
        $suggestion_status = (string) get_post_meta($item_id, '_gps_ovoko_category_suggestion_status', true);
        $match_count = absint(get_post_meta($item_id, '_gps_ovoko_match_count', true));
        $enrichment_status = (string) get_post_meta($item_id, '_gps_ovoko_enrichment_status', true);
        return $match_count === 0 && in_array($enrichment_status, array('no_match', ''), true) && in_array($suggestion_status, array('unavailable', 'no_code_specific_suggestion', 'no_suggestion', 'endpoint_error'), true);
    }

    private function woo_category_mapping_candidate_from_staging_item($item_id)
    {
        $sources = array(
            array(
                'source' => 'ovoko_selected_match_category',
                'name' => get_post_meta($item_id, '_gps_ovoko_category_name', true),
                'path' => get_post_meta($item_id, '_gps_ovoko_category_path', true) ?: get_post_meta($item_id, '_gps_ovoko_part_category', true),
                'confidence' => get_post_meta($item_id, '_gps_ovoko_confidence', true) ?: 'medium',
            ),
            array(
                'source' => $this->ovoko_category_suggestion_meta_allows_mapping($item_id) ? 'ovoko_category_prediction_by_code' : '',
                'name' => $this->ovoko_category_suggestion_meta_allows_mapping($item_id) ? get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', true) : '',
                'path' => $this->ovoko_category_suggestion_meta_allows_mapping($item_id) ? get_post_meta($item_id, '_gps_ovoko_category_suggestion_category_name', true) : '',
                'confidence' => 'high',
            ),
        );
        foreach ($sources as $source) {
            $mapped = $this->woo_category_mapping_candidate((string) $source['name'], (string) $source['path'], (string) $source['source'], (string) $source['confidence']);
            if (!empty($mapped['id'])) {
                return $mapped;
            }
        }
        return array('id' => 0, 'path' => '', 'source' => '', 'confidence' => '');
    }

    private function woo_category_mapping_candidate($name, $path, $source, $confidence = 'medium')
    {
        if (!function_exists('taxonomy_exists') || !taxonomy_exists('product_cat')) {
            return array('id' => 0, 'path' => $path, 'source' => $source, 'confidence' => $confidence);
        }
        $category_name = trim((string) ($name !== '' ? $name : basename(str_replace('>', '/', $path))));
        if ($category_name === '') {
            return array('id' => 0, 'path' => $path, 'source' => $source, 'confidence' => $confidence);
        }
        $term = get_term_by('name', $category_name, 'product_cat');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($category_name), 'product_cat');
        }
        if (!$term || is_wp_error($term)) {
            return array('id' => 0, 'path' => $path !== '' ? $path : $category_name, 'source' => $source, 'confidence' => $confidence);
        }
        return array('id' => (int) $term->term_id, 'path' => $path !== '' ? $path : $category_name, 'source' => $source, 'confidence' => $confidence ?: 'medium');
    }

    private function run_readiness_validation_for_staging_item($item_id)
    {
        $this->persist_fixed_import_category_meta_for_staging_item($item_id);
        $analysis = $this->analysis_from_staging_item($item_id);
        $staging_status = (string) get_post_meta($item_id, '_gps_staging_status', true);
        $created_product_id = absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true));
        $selected_price = $this->persist_selected_price_for_staging_item($item_id, $analysis);
        $analysis['selected_price_pln'] = $selected_price ? $selected_price['price'] : '';
        $analysis['selected_price_source'] = $selected_price ? $selected_price['source'] : '';
        $woo_readiness = $this->woo_draft_readiness_status($analysis, $staging_status, $created_product_id);
        $marketplace_readiness = $this->marketplace_readiness_status($analysis, $staging_status, $created_product_id);
        update_post_meta($item_id, '_gps_woo_draft_readiness_status', $woo_readiness['status']);
        update_post_meta($item_id, '_gps_woo_draft_blocking_reasons', wp_json_encode($woo_readiness['blocking_reasons']));
        update_post_meta($item_id, '_gps_woo_draft_readiness_checked_at', current_time('mysql', true));
        update_post_meta($item_id, '_gps_marketplace_readiness_status', $marketplace_readiness['status']);
        update_post_meta($item_id, '_gps_marketplace_blocking_reasons', wp_json_encode($marketplace_readiness['blocking_reasons']));
        update_post_meta($item_id, '_gps_marketplace_readiness_checked_at', current_time('mysql', true));
        update_post_meta($item_id, '_gps_readiness_status', $woo_readiness['status']);
        update_post_meta($item_id, '_gps_blocking_reasons', wp_json_encode($woo_readiness['blocking_reasons']));
        update_post_meta($item_id, '_gps_readiness_checked_at', current_time('mysql', true));
        return array('action' => 'readiness_validation', 'staging_item_id' => $item_id, 'result' => $woo_readiness['status'], 'blocking_reasons' => $woo_readiness['blocking_reasons'], 'price_source' => $selected_price ? $selected_price['source'] : '', 'selected_price_pln' => $selected_price ? $selected_price['price'] : '', 'woo_draft_readiness' => $woo_readiness, 'marketplace_readiness' => $marketplace_readiness, 'writes' => 'staging_meta_only');
    }

    private function create_woo_draft_from_staging_item($item_id)
    {
        $readiness = $this->run_readiness_validation_for_staging_item($item_id);
        if (($readiness['result'] ?? '') !== 'ready_to_create_product') {
            return array('action' => 'create_woo_draft', 'staging_item_id' => $item_id, 'result' => 'blocked', 'reason' => 'item_not_ready', 'readiness' => $readiness);
        }
        if (absint(get_post_meta($item_id, '_gps_gmail_created_product_id', true))) {
            return array('action' => 'create_woo_draft', 'staging_item_id' => $item_id, 'result' => 'blocked', 'reason' => 'product_already_created');
        }
        $result = $this->create_product_from_analysis($this->analysis_from_staging_item($item_id), array());
        if (is_wp_error($result)) {
            update_post_meta($item_id, '_gps_staging_status', 'error');
            return array('action' => 'create_woo_draft', 'staging_item_id' => $item_id, 'result' => 'error', 'error' => $result->get_error_message());
        }
        update_post_meta($item_id, '_gps_gmail_created_product_id', absint($result['product_id']));
        update_post_meta($item_id, '_gps_staging_status', 'created_product');
        update_post_meta($item_id, '_gps_readiness_status', 'created_product');
        update_post_meta($item_id, '_gps_blocking_reasons', wp_json_encode(array('product_already_created')));
        return array('action' => 'create_woo_draft', 'staging_item_id' => $item_id, 'result' => 'created_product', 'created_product_id' => absint($result['product_id']), 'product_status' => 'draft');
    }

    public function handle_create_woo_drafts()
    {
        $this->verify_admin_action();
        $result = $this->create_woo_drafts_from_ready_staging(max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    public function handle_full_preparation_batch()
    {
        $this->verify_admin_action();
        $result = $this->run_full_preparation_batch(max(1, min(25, absint($_POST['batch_size'] ?? 5))));
        set_transient('gps_gmail_product_importer_last_admin_result', $result, 120);
        wp_safe_redirect(admin_url('admin.php?page=gps-gmail-product-importer'));
        exit;
    }

    private function run_full_preparation_batch($batch_size)
    {
        $ids = get_posts(array('post_type' => self::STAGING_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => max(1, min(25, absint($batch_size))), 'orderby' => 'modified', 'order' => 'DESC'));
        $items = array();
        foreach ($ids as $id) {
            $items[] = $this->run_full_preparation_for_staging_item((int) $id);
        }
        return array('action' => 'full_preparation_batch', 'result' => 'completed', 'batch_size' => max(1, min(25, absint($batch_size))), 'total_processed' => count($items), 'items' => $items, 'writes' => 'staging_meta_only', 'no_woo_product_created' => true, 'no_ovoko_write' => true, 'no_allegro_call' => true);
    }

    private function create_woo_drafts_from_ready_staging($batch_size, $exclude_ids = array())
    {
        $query_args = array('post_type' => self::STAGING_POST_TYPE, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => max($batch_size, 100), 'meta_query' => array('relation' => 'OR', array('key' => '_gps_gmail_created_product_id', 'compare' => 'NOT EXISTS'), array('key' => '_gps_gmail_created_product_id', 'value' => array('', '0'), 'compare' => 'IN')));
        $exclude_ids = array_values(array_filter(array_map('absint', (array) $exclude_ids)));
        if ($exclude_ids) {
            $query_args['post__not_in'] = $exclude_ids;
        }
        $ids = get_posts($query_args);
        $created = array();
        foreach ($ids as $id) {
            if (count(array_filter($created, function ($item) { return empty($item['blocked']) && empty($item['error']); })) >= $batch_size) {
                break;
            }
            $result = $this->create_woo_draft_from_staging_item((int) $id);
            if (($result['result'] ?? '') !== 'created_product') {
                $created[] = array('staging_item_id' => (int) $id, 'blocked' => true, 'reason' => $result['reason'] ?? ($result['error'] ?? 'item_not_ready'), 'readiness' => $result['readiness'] ?? array());
                continue;
            }
            $created[] = array('staging_item_id' => (int) $id, 'created_product_id' => absint($result['created_product_id']), 'product_status' => 'draft');
        }
        $created_count = count(array_filter($created, function ($item) { return empty($item['blocked']) && empty($item['error']); }));
        return array('action' => 'create_woo_drafts_for_ready_items', 'total_attempted' => min((int) $batch_size, count($ids)), 'total_checked' => count($ids), 'created_count' => $created_count, 'skipped_count' => count($created) - $created_count, 'errors' => array_values(array_filter($created, function ($item) { return !empty($item['error']); })), 'created_product_ids' => array_values(array_map(function ($item) { return (int) ($item['created_product_id'] ?? 0); }, array_filter($created, function ($item) { return empty($item['blocked']) && empty($item['error']); }))), 'total_products_created' => $created_count, 'items' => $created, 'no_ovoko_write' => true, 'no_allegro_call' => true);
    }

    private function analysis_from_staging_item($id)
    {
        $post = get_post($id);
        return array(
            'staging_item_id' => absint($id),
            'message_id' => get_post_meta($id, '_gps_gmail_message_id', true),
            'thread_id' => get_post_meta($id, '_gps_gmail_thread_id', true),
            'date' => get_post_meta($id, '_gps_gmail_date', true),
            'from' => get_post_meta($id, '_gps_gmail_from', true),
            'subject' => get_post_meta($id, '_gps_gmail_subject', true),
            'label' => get_post_meta($id, '_gps_gmail_label', true),
            'body' => $post ? $post->post_content : '',
            'storage_location' => get_post_meta($id, '_gps_storage_location', true),
            'detected_part_code' => get_post_meta($id, '_gps_detected_part_code', true),
            'normalized_part_code' => get_post_meta($id, '_gps_normalized_part_code', true),
            'detected_oem_part_number' => get_post_meta($id, '_gps_detected_oem_part_number', true),
            'normalized_oem_part_number' => get_post_meta($id, '_gps_normalized_oem_part_number', true),
            'oem_candidates' => json_decode((string) get_post_meta($id, '_gps_gmail_import_oem_candidates', true), true) ?: array(),
            'detected_vehicle_make' => get_post_meta($id, '_gps_detected_vehicle_make', true),
            'detected_vehicle_model' => get_post_meta($id, '_gps_detected_vehicle_model', true),
            'detected_vehicle_confidence' => get_post_meta($id, '_gps_detected_vehicle_confidence', true),
            'ovoko_enrichment_status' => get_post_meta($id, '_gps_ovoko_enrichment_status', true),
            'allegro_price_research_status' => get_post_meta($id, '_gps_allegro_price_research_status', true),
            'allegro_price_research_checked_at' => get_post_meta($id, '_gps_allegro_price_research_checked_at', true),
            'allegro_price_query' => get_post_meta($id, '_gps_allegro_price_query', true),
            'allegro_price_raw_offer_count' => get_post_meta($id, '_gps_allegro_price_raw_offer_count', true),
            'allegro_price_filtered_offer_count' => get_post_meta($id, '_gps_allegro_price_filtered_offer_count', true),
            'allegro_price_median_pln' => get_post_meta($id, '_gps_allegro_price_median_pln', true),
            'allegro_price_min_pln' => get_post_meta($id, '_gps_allegro_price_min_pln', true),
            'allegro_price_max_pln' => get_post_meta($id, '_gps_allegro_price_max_pln', true),
            'allegro_price_confidence' => get_post_meta($id, '_gps_allegro_price_confidence', true),
            'allegro_price_sample_offer_urls' => get_post_meta($id, '_gps_allegro_price_sample_offer_urls', true),
            'allegro_price_source' => get_post_meta($id, '_gps_allegro_price_source', true),
            'allegro_price_suggestion' => get_post_meta($id, '_gps_allegro_price_suggestion', true),
            'allegro_price_currency' => get_post_meta($id, '_gps_allegro_price_currency', true),
            'allegro_price_notes' => get_post_meta($id, '_gps_allegro_price_notes', true),
            'allegro_price_error_http_status' => get_post_meta($id, '_gps_allegro_price_error_http_status', true),
            'allegro_price_error_response' => get_post_meta($id, '_gps_allegro_price_error_response', true),
            'allegro_price_error_code' => get_post_meta($id, '_gps_allegro_price_error_code', true),
            'allegro_price_error_checked_at' => get_post_meta($id, '_gps_allegro_price_error_checked_at', true),
            'ovoko_price_suggestion_status' => get_post_meta($id, '_gps_ovoko_price_suggestion_status', true),
            'ovoko_price_suggestion_pln' => get_post_meta($id, '_gps_ovoko_price_suggestion_pln', true),
            'ovoko_price_suggestion_source' => get_post_meta($id, '_gps_ovoko_price_suggestion_source', true),
            'ovoko_price_suggestion_currency' => get_post_meta($id, '_gps_ovoko_price_suggestion_currency', true),
            'ovoko_price_suggestion_raw_value' => get_post_meta($id, '_gps_ovoko_price_suggestion_raw_value', true),
            'ovoko_price_suggestion_checked_at' => get_post_meta($id, '_gps_ovoko_price_suggestion_checked_at', true),
            'ovoko_price_suggestion_notes' => get_post_meta($id, '_gps_ovoko_price_suggestion_notes', true),
            'manual_price_override_enabled' => get_post_meta($id, '_gps_manual_price_override_enabled', true) === '1',
            'manual_price_pln' => get_post_meta($id, '_gps_manual_price_pln', true),
            'manual_price_note' => get_post_meta($id, '_gps_manual_price_note', true),
            'selected_price_pln' => get_post_meta($id, '_gps_selected_price_pln', true),
            'selected_price_source' => get_post_meta($id, '_gps_selected_price_source', true),
            'price_required_for_woo_draft' => get_post_meta($id, '_gps_price_required_for_woo_draft', true),
            'price_required_for_crm_only_import' => get_post_meta($id, '_gps_price_required_for_crm_only_import', true),
            'empty_price_allowed_reason' => get_post_meta($id, '_gps_empty_price_allowed_reason', true),
            'ovoko_category_id' => get_post_meta($id, '_gps_ovoko_category_id', true),
            'ovoko_category_name' => get_post_meta($id, '_gps_ovoko_category_name', true),
            'ovoko_category_path' => get_post_meta($id, '_gps_ovoko_category_path', true),
            'ovoko_part_category' => get_post_meta($id, '_gps_ovoko_part_category', true),
            'ovoko_category_suggestion_status' => get_post_meta($id, '_gps_ovoko_category_suggestion_status', true),
            'ovoko_category_suggestion_code' => get_post_meta($id, '_gps_ovoko_category_suggestion_code', true),
            'ovoko_category_suggestion_category_id' => get_post_meta($id, '_gps_ovoko_category_suggestion_category_id', true),
            'ovoko_category_suggestion_category_name' => get_post_meta($id, '_gps_ovoko_category_suggestion_category_name', true),
            'ovoko_category_suggestion_source' => get_post_meta($id, '_gps_ovoko_category_suggestion_source', true),
            'ovoko_category_suggestion_confidence' => get_post_meta($id, '_gps_ovoko_category_suggestion_confidence', true),
            'ovoko_category_suggestion_source_type' => get_post_meta($id, '_gps_ovoko_category_suggestion_source_type', true),
            'category_mapping_status' => get_post_meta($id, '_gps_category_mapping_status', true),
            'suggested_woo_category_id' => absint(get_post_meta($id, '_gps_suggested_woo_category_id', true)),
            'suggested_woo_category_path' => get_post_meta($id, '_gps_suggested_woo_category_path', true),
            'suggested_woo_category_confidence' => get_post_meta($id, '_gps_suggested_woo_category_confidence', true),
            'suggested_category_source' => get_post_meta($id, '_gps_suggested_category_source', true),
            'shipping_group' => get_post_meta($id, '_gps_shipping_group', true),
            'duplicate_status' => get_post_meta($id, '_gps_duplicate_status', true),
            'duplicate_existing_product_id' => get_post_meta($id, '_gps_duplicate_existing_product_id', true),
            'image_attachments_found' => absint(get_post_meta($id, '_gps_gmail_import_image_count', true)),
            'image_attachment_set_hash' => get_post_meta($id, '_gps_gmail_import_attachment_set_hash', true),
            'images' => json_decode((string) get_post_meta($id, '_gps_gmail_images_metadata', true), true) ?: array(),
            'warnings' => json_decode((string) get_post_meta($id, '_gps_gmail_warnings', true), true) ?: array(),
        );
    }

    private function create_product_from_analysis($analysis, $message)
    {
        if (!function_exists('wc_get_product')) {
            return new WP_Error('gps_gmail_wc_missing', 'WooCommerce is required to create products.');
        }
        $settings = $this->settings();
        $selected_price = $this->selected_price_for_analysis($analysis);
        $empty_price_allowed = $this->fixed_import_category_empty_price_allowed($analysis);
        if (!$selected_price && !$empty_price_allowed) {
            return new WP_Error('gps_gmail_missing_selected_price', 'A selected manual or Ovoko suggestion PLN price is required before creating a Woo draft.');
        }
        $empty_price_allowed = $this->fixed_import_category_empty_price_allowed($analysis);
        $suggested_category_id = $this->mapped_suggested_product_cat_id($analysis);
        if ($suggested_category_id <= 0 && !$empty_price_allowed) {
            return new WP_Error('gps_gmail_missing_mapped_category', 'A mapped Woo product_cat category is required before creating a Woo draft.');
        }
        if ($suggested_category_id > 0 && !$this->product_cat_term_exists($suggested_category_id)) {
            return new WP_Error('gps_gmail_invalid_mapped_category', sprintf('Mapped Woo product_cat term %d does not exist.', $suggested_category_id));
        }
        $base_sku = $this->gmail_staging_base_sku($analysis);
        if ($base_sku === '') {
            return new WP_Error('gps_gmail_missing_generated_sku', 'A staging item ID is required to generate a Woo SKU.');
        }
        $sku = $this->unique_gmail_staging_sku($base_sku);
        if ($sku === '') {
            return new WP_Error('gps_gmail_missing_generated_sku', 'A non-empty Woo SKU could not be generated.');
        }
        $status = 'draft';
        $product_id = wp_insert_post(array(
            'post_type' => 'product',
            'post_status' => $status,
            'post_title' => $this->product_title($analysis),
            'post_content' => $analysis['body'] ?: sprintf('Imported from Gmail message: %s', $analysis['subject']),
            'post_excerpt' => $analysis['detected_oem_part_number'] ? sprintf('OEM / part number: %s', $analysis['detected_oem_part_number']) : $analysis['subject'],
        ), true);
        if (is_wp_error($product_id)) {
            return $product_id;
        }
        wp_set_object_terms($product_id, 'simple', 'product_type');
        update_post_meta($product_id, '_stock', 1);
        update_post_meta($product_id, '_stock_status', 'instock');
        update_post_meta($product_id, '_manage_stock', 'yes');
        update_post_meta($product_id, '_sku', $sku);
        update_post_meta($product_id, '_gps_generated_sku', $base_sku);
        update_post_meta($product_id, '_gps_sku_source', 'gmail_staging_item');
        if ($selected_price) {
            update_post_meta($product_id, '_regular_price', $selected_price['price']);
            update_post_meta($product_id, '_price', $selected_price['price']);
        } else {
            update_post_meta($product_id, '_regular_price', '');
            update_post_meta($product_id, '_price', '');
            update_post_meta($product_id, '_sale_price', '');
        }
        update_post_meta($product_id, '_gps_source_staging_item_id', absint($analysis['staging_item_id'] ?? 0));
        update_post_meta($product_id, '_gps_selected_price_pln', $selected_price ? $selected_price['price'] : '');
        update_post_meta($product_id, '_gps_selected_price_source', $selected_price ? $selected_price['source'] : ($empty_price_allowed ? 'no_price_allowed' : ''));
        update_post_meta($product_id, '_gps_price_required_for_woo_draft', $empty_price_allowed ? '0' : '1');
        update_post_meta($product_id, '_gps_price_required_for_crm_only_import', $empty_price_allowed ? '0' : '1');
        update_post_meta($product_id, '_gps_empty_price_allowed_reason', $empty_price_allowed ? 'fixed_category_crm_only_import_review_flow' : '');
        foreach (array('_gps_allegro_price_research_status', '_gps_allegro_price_research_checked_at', '_gps_allegro_price_query', '_gps_allegro_price_raw_offer_count', '_gps_allegro_price_filtered_offer_count', '_gps_allegro_price_median_pln', '_gps_allegro_price_min_pln', '_gps_allegro_price_max_pln', '_gps_allegro_price_confidence', '_gps_allegro_price_sample_offer_urls', '_gps_allegro_price_source', '_gps_allegro_price_suggestion', '_gps_allegro_price_currency', '_gps_allegro_price_notes', '_gps_allegro_price_error_http_status', '_gps_allegro_price_error_response', '_gps_allegro_price_error_code', '_gps_allegro_price_error_checked_at') as $allegro_meta_key) {
            if (array_key_exists(ltrim($allegro_meta_key, '_gps_'), $analysis)) {
                update_post_meta($product_id, $allegro_meta_key, $analysis[ltrim($allegro_meta_key, '_gps_')]);
            } elseif (isset($analysis[$allegro_meta_key])) {
                update_post_meta($product_id, $allegro_meta_key, $analysis[$allegro_meta_key]);
            }
        }
        foreach (array('_gps_ovoko_price_suggestion_status', '_gps_ovoko_price_suggestion_pln', '_gps_ovoko_price_suggestion_source', '_gps_ovoko_price_suggestion_currency', '_gps_ovoko_price_suggestion_raw_value', '_gps_ovoko_price_suggestion_checked_at', '_gps_ovoko_price_suggestion_notes') as $ovoko_price_meta_key) {
            $analysis_key = ltrim($ovoko_price_meta_key, '_gps_');
            if (array_key_exists($analysis_key, $analysis)) {
                update_post_meta($product_id, $ovoko_price_meta_key, $analysis[$analysis_key]);
            } elseif (isset($analysis[$ovoko_price_meta_key])) {
                update_post_meta($product_id, $ovoko_price_meta_key, $analysis[$ovoko_price_meta_key]);
            }
        }
        update_post_meta($product_id, '_gps_gmail_import_message_id', $analysis['message_id']);
        update_post_meta($product_id, '_gps_gmail_import_thread_id', $analysis['thread_id']);
        update_post_meta($product_id, '_gps_gmail_import_subject', $analysis['subject']);
        update_post_meta($product_id, '_gps_gmail_import_from', $analysis['from']);
        update_post_meta($product_id, '_gps_gmail_import_date', $analysis['date']);
        update_post_meta($product_id, '_gps_gmail_import_label', $analysis['label']);
        update_post_meta($product_id, '_gps_gmail_imported_at', current_time('mysql', true));
        update_post_meta($product_id, '_gps_gmail_import_status', in_array('needs_review', $analysis['warnings'], true) ? 'needs_review' : 'imported');
        update_post_meta($product_id, '_gps_gmail_import_source', 'gmail');
        update_post_meta($product_id, '_gps_storage_location', $analysis['storage_location'] ?? '');
        update_post_meta($product_id, '_gps_detected_part_code', $analysis['detected_part_code'] ?? $analysis['detected_oem_part_number']);
        update_post_meta($product_id, '_gps_normalized_part_code', $analysis['normalized_part_code'] ?? $analysis['normalized_oem_part_number']);
        update_post_meta($product_id, '_gps_detected_oem_part_number', $analysis['detected_oem_part_number']);
        update_post_meta($product_id, '_gps_normalized_oem_part_number', $analysis['normalized_oem_part_number']);
        update_post_meta($product_id, '_gps_detected_vehicle_make', $analysis['detected_vehicle_make']);
        update_post_meta($product_id, '_gps_detected_vehicle_model', $analysis['detected_vehicle_model']);
        update_post_meta($product_id, '_gps_detected_vehicle_confidence', $analysis['detected_vehicle_confidence']);
        update_post_meta($product_id, '_gps_gmail_import_image_count', $analysis['image_attachments_found']);
        update_post_meta($product_id, '_gps_gmail_import_oem_candidates', $analysis['oem_candidates']);
        update_post_meta($product_id, '_gps_gmail_import_attachment_set_hash', $analysis['image_attachment_set_hash']);
        update_post_meta($product_id, '_gps_suggested_woo_category_id', $analysis['suggested_woo_category_id']);
        update_post_meta($product_id, '_gps_suggested_woo_category_path', $analysis['suggested_woo_category_path']);
        update_post_meta($product_id, '_gps_suggested_woo_category_confidence', $analysis['suggested_woo_category_confidence']);
        update_post_meta($product_id, '_gps_suggested_category_source', $analysis['suggested_category_source']);
        update_post_meta($product_id, '_gps_ovoko_category_id', $analysis['ovoko_category_id'] ?? '');
        update_post_meta($product_id, '_gps_ovoko_category_name', $analysis['ovoko_category_name'] ?? '');
        update_post_meta($product_id, '_gps_ovoko_category_path', $analysis['ovoko_category_path'] ?? '');
        update_post_meta($product_id, '_gps_ovoko_part_category', $analysis['ovoko_part_category'] ?? '');
        foreach ($this->fixed_import_category_meta_values() as $fixed_meta_key => $fixed_meta_value) {
            update_post_meta($product_id, $fixed_meta_key, $fixed_meta_value);
        }
        $category_assignment = $suggested_category_id > 0 ? wp_set_object_terms($product_id, array($suggested_category_id), 'product_cat', false) : true;
        if (is_wp_error($category_assignment)) {
            wp_delete_post($product_id, true);
            return $category_assignment;
        }
        $images_imported = 0;
        if ($settings['import_images']) {
            $images_imported = $this->import_images($product_id, $analysis, $message);
        }
        return array('product_id' => $product_id, 'product_status' => $status, 'images_imported' => $images_imported);
    }

    private function product_title($analysis)
    {
        $parts = array_filter(array($analysis['detected_vehicle_make'], $analysis['detected_vehicle_model'], $analysis['detected_oem_part_number']));
        return $parts ? implode(' ', $parts) : ($analysis['subject'] ?: 'Gmail product draft');
    }

    private function gmail_staging_base_sku($analysis)
    {
        $staging_item_id = absint($analysis['staging_item_id'] ?? 0);
        if ($staging_item_id <= 0) {
            return '';
        }
        return 'GPS-GMAIL-' . $staging_item_id;
    }

    private function unique_gmail_staging_sku($base_sku, $exclude_product_id = 0)
    {
        $base_sku = trim(sanitize_text_field((string) $base_sku));
        if ($base_sku === '') {
            return '';
        }
        if (!$this->woo_sku_exists($base_sku, $exclude_product_id)) {
            return $base_sku;
        }
        for ($suffix = 2; $suffix <= 100; $suffix++) {
            $candidate = $base_sku . '-' . $suffix;
            if (!$this->woo_sku_exists($candidate, $exclude_product_id)) {
                return $candidate;
            }
        }
        return '';
    }

    private function woo_sku_exists($sku, $exclude_product_id = 0)
    {
        $sku = trim((string) $sku);
        $exclude_product_id = absint($exclude_product_id);
        if ($sku === '') {
            return false;
        }
        if (function_exists('wc_get_product_id_by_sku')) {
            $product_id = absint(wc_get_product_id_by_sku($sku));
            return $product_id > 0 && $product_id !== $exclude_product_id;
        }
        if (function_exists('get_posts')) {
            $ids = get_posts(array(
                'post_type' => array('product', 'product_variation'),
                'post_status' => 'any',
                'fields' => 'ids',
                'posts_per_page' => 1,
                'post__not_in' => $exclude_product_id ? array($exclude_product_id) : array(),
                'meta_query' => array(array('key' => '_sku', 'value' => $sku)),
            ));
            return !empty($ids);
        }
        return false;
    }

    private function import_images($product_id, $analysis, $message)
    {
        if (empty($analysis['images'])) {
            return 0;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $gallery = array();
        foreach ($analysis['images'] as $image) {
            $existing = $this->find_existing_attachment($analysis['message_id'], $image['attachment_id']);
            if ($existing) {
                $attachment_id = $existing;
            } else {
                $data = $this->gmail_request('https://gmail.googleapis.com/gmail/v1/users/me/messages/' . rawurlencode($analysis['message_id']) . '/attachments/' . rawurlencode($image['attachment_id']));
                if (is_wp_error($data) || empty($data['data'])) {
                    continue;
                }
                $tmp = wp_tempnam($image['filename']);
                file_put_contents($tmp, $this->base64url_decode($data['data']));
                $file = array('name' => sanitize_file_name($image['filename']), 'type' => $image['mime'], 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp));
                $attachment_id = media_handle_sideload($file, $product_id, $analysis['subject']);
                if (is_wp_error($attachment_id)) {
                    @unlink($tmp);
                    continue;
                }
                update_post_meta($attachment_id, '_gps_gmail_import_message_id', $analysis['message_id']);
                update_post_meta($attachment_id, '_gps_gmail_import_attachment_id', $image['attachment_id']);
                update_post_meta($attachment_id, '_gps_gmail_import_source', 'gmail');
            }
            if (!has_post_thumbnail($product_id)) {
                set_post_thumbnail($product_id, $attachment_id);
            } else {
                $gallery[] = $attachment_id;
            }
        }
        if ($gallery) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', array_map('absint', $gallery)));
        }
        return (has_post_thumbnail($product_id) ? 1 : 0) + count($gallery);
    }

    private function find_existing_attachment($message_id, $attachment_id)
    {
        $ids = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'fields' => 'ids', 'posts_per_page' => 1, 'meta_query' => array(array('key' => '_gps_gmail_import_message_id', 'value' => $message_id), array('key' => '_gps_gmail_import_attachment_id', 'value' => $attachment_id))));
        return $ids ? (int) $ids[0] : 0;
    }

    private function gmail_request($url, $args = array())
    {
        $token = $this->access_token();
        if (is_wp_error($token)) {
            return $token;
        }
        $args = wp_parse_args($args, array('timeout' => 20, 'headers' => array()));
        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $response = wp_remote_request($url, $args);
        return $this->json_response($response);
    }

    private function access_token()
    {
        $tokens = (array) get_option(self::OPTION_TOKENS, array());
        if (empty($tokens['access_token'])) {
            return new WP_Error('gps_gmail_not_connected', 'Gmail is not connected.');
        }
        if (!empty($tokens['expires_at']) && time() < (int) $tokens['expires_at'] - 60) {
            return $tokens['access_token'];
        }
        if (empty($tokens['refresh_token'])) {
            return new WP_Error('gps_gmail_refresh_missing', 'Gmail access expired and no refresh token is stored.');
        }
        $settings = $this->settings();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 20, 'body' => array('client_id' => $settings['google_client_id'], 'client_secret' => $settings['google_client_secret'], 'refresh_token' => $tokens['refresh_token'], 'grant_type' => 'refresh_token')));
        $body = $this->json_response($response);
        if (is_wp_error($body)) {
            return $body;
        }
        $body['refresh_token'] = $tokens['refresh_token'];
        $this->store_tokens($body);
        return $body['access_token'];
    }

    private function store_tokens($body)
    {
        $tokens = array('access_token' => sanitize_text_field($body['access_token'] ?? ''), 'refresh_token' => sanitize_text_field($body['refresh_token'] ?? ((array) get_option(self::OPTION_TOKENS, array()))['refresh_token'] ?? ''), 'expires_at' => time() + absint($body['expires_in'] ?? 3600), 'scope' => sanitize_text_field($body['scope'] ?? ''), 'token_type' => sanitize_text_field($body['token_type'] ?? 'Bearer'));
        update_option(self::OPTION_TOKENS, $tokens, false);
    }

    private function json_response($response)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('gps_gmail_http_error', 'Google API request failed with HTTP ' . $code, $body);
        }
        return is_array($body) ? $body : array();
    }

    private function base64url_decode($data)
    {
        return base64_decode(strtr((string) $data, '-_', '+/'));
    }


    private function test_ovoko_category_prediction_by_code($code, $expected_category_name = 'Turbina', $capture_request = '', $capture_response = '', $checked_at = '')
    {
        $code = trim((string) $code);
        $expected_category_name = trim((string) $expected_category_name);
        $checked_at = $checked_at !== '' ? $checked_at : current_time('mysql', true);
        $attempts = array();
        if (trim((string) $capture_request) !== '' || trim((string) $capture_response) !== '') {
            $capture = $this->parse_ovoko_category_network_capture($capture_request, $capture_response);
            $capture['source_label'] = 'pasted_network_capture';
            $capture['expected_category_name'] = $expected_category_name;
            $capture['matched_expected_panel_result'] = $this->category_prediction_name_matches_expected((string) ($capture['parsed_category_name'] ?? $capture['category_name'] ?? ''), $expected_category_name);
            $capture['accepted'] = (string) ($capture['status'] ?? '') === 'completed' && !empty($capture['safe_to_call_from_wordpress']);
            $attempts[] = $capture;
        }
        foreach ($this->ovoko_category_prediction_api_candidate_attempts($code, $expected_category_name) as $attempt) {
            $attempts[] = $attempt;
        }
        $final = array();
        foreach ($attempts as $attempt) {
            if (!empty($attempt['accepted'])) {
                $final = $attempt;
                break;
            }
        }
        return array(
            'action' => 'ovoko_category_prediction_by_code_test',
            'result' => $final ? 'completed' : 'needs_network_capture',
            'code_tested' => $code,
            'expected_category_name' => $expected_category_name,
            'sources_attempted' => $attempts,
            'final_selected_category_source' => $final,
            'next_required_action' => $final ? '' : 'Ovoko panel category prediction is required: open Ovoko web panel → create/add/import part form → DevTools Network → clear → type ' . $code . ' into main part code/manufacturer code → wait for ' . ($expected_category_name !== '' ? $expected_category_name : 'the auto-selected category') . ' → paste redacted request/response into this parser.',
            'blocking_dependency' => $final ? '' : 'Need Ovoko-confirmed official/API-equivalent category prediction endpoint, or a captured endpoint proven safe for WordPress server-to-server use without browser session cookies.',
            'writes' => 'none',
            'checked_at' => $checked_at,
        );
    }

    private function ovoko_category_prediction_api_candidate_attempts($code, $expected_category_name)
    {
        $attempts = array();
        $code = trim((string) $code);
        if ($code === '') {
            return array(array(
                'source_label' => 'api_candidates',
                'endpoint' => '',
                'response_status' => 'skipped_missing_code',
                'parsed_category_id' => '',
                'parsed_category_name' => '',
                'confidence' => 'none',
                'endpoint_classification' => 'unusable',
                'safe_to_call_from_wordpress' => false,
                'accepted' => false,
            ));
        }
        if (!$this->ovoko_credentials_detected() || !function_exists('wp_remote_post')) {
            return array(array(
                'source_label' => 'api_candidates',
                'endpoint' => '',
                'request_params' => array('manufacturer_code' => $code, 'code' => $code, 'search' => $code),
                'response_status' => 'credentials_missing_or_http_unavailable',
                'parsed_category_id' => '',
                'parsed_category_name' => '',
                'confidence' => 'none',
                'endpoint_classification' => 'unusable',
                'safe_to_call_from_wordpress' => false,
                'requires_browser_session_cookies' => false,
                'uses_crm_importPart_credentials' => $this->ovoko_credentials_detected(),
                'accepted' => false,
            ));
        }
        $settings = $this->ovoko_integration_settings();
        $base_url = rtrim((string) $settings['rrr_api_base_url'], '/');
        if ($base_url === '') {
            return array(array('source_label' => 'api_candidates', 'endpoint' => '', 'response_status' => 'base_url_missing', 'parsed_category_id' => '', 'parsed_category_name' => '', 'confidence' => 'none', 'endpoint_classification' => 'unusable', 'safe_to_call_from_wordpress' => false, 'accepted' => false));
        }
        $auth = array('username' => (string) $settings['rrr_api_username'], 'password' => (string) $settings['rrr_api_password'], 'user_token' => (string) $settings['rrr_api_user_token']);
        $candidate_paths = array(
            '/get/part/category?manufacturer_code=' . rawurlencode($code),
            '/get/parts/category?manufacturer_code=' . rawurlencode($code),
            '/get/categories/suggest?manufacturer_code=' . rawurlencode($code),
            '/v2/get/part/category?manufacturer_code=' . rawurlencode($code),
            '/v2/get/parts/category?manufacturer_code=' . rawurlencode($code),
            '/v2/get/categories/suggest?manufacturer_code=' . rawurlencode($code),
            '/v2/get/categories',
            '/v2/get/categories/tree',
            '/get/categories',
            '/get/categories/tree',
            '/v2/get/parts/categories',
            '/get/part/categories',
            '/get/parts/categories',
        );
        foreach ($candidate_paths as $path) {
            $body = $auth + array('manufacturer_code' => $code, 'part_code' => $code, 'part_number' => $code, 'code' => $code, 'search' => $code, 'q' => $code);
            $classification = $this->classify_ovoko_category_prediction_endpoint($path, array('body' => $body, 'headers' => array()));
            $response = wp_remote_post($base_url . $path, array('timeout' => 10, 'body' => $body, 'headers' => array('Content-Type' => 'application/x-www-form-urlencoded')));
            $http_code = 0;
            $parsed = array('category_id' => '', 'category_name' => '');
            $response_status = 'endpoint_error';
            $body_excerpt = '';
            if (is_wp_error($response)) {
                $body_excerpt = $response->get_error_message();
            } else {
                $http_code = (int) wp_remote_retrieve_response_code($response);
                $raw_body = (string) wp_remote_retrieve_body($response);
                $body_excerpt = substr($raw_body, 0, 700);
                $decoded = json_decode($raw_body, true);
                $parsed = $this->parse_ovoko_category_suggestion_payload(is_array($decoded) ? $decoded : $raw_body);
                $response_status = $http_code >= 200 && $http_code < 300 ? 'http_ok' : 'http_' . $http_code;
            }
            $code_specific = !empty($classification['code_specific_endpoint']) && !empty($classification['has_code_parameter']);
            $matched_expected = $this->category_prediction_name_matches_expected((string) ($parsed['category_name'] ?? ''), $expected_category_name);
            $accepted = $response_status === 'http_ok'
                && $code_specific
                && (string) ($parsed['category_id'] ?? '') !== ''
                && (string) ($parsed['category_name'] ?? '') !== ''
                && ($expected_category_name === '' || $matched_expected)
                && in_array((string) ($classification['endpoint_classification'] ?? ''), array('official_api', 'api_candidate'), true);
            $attempts[] = array(
                'source_label' => 'safe_api_candidate_probe',
                'endpoint' => $path,
                'request_params' => array('manufacturer_code' => $code, 'part_code' => $code, 'part_number' => $code, 'code' => $code, 'search' => $code, 'q' => $code, 'username' => '[configured]', 'password' => '[redacted]', 'user_token' => '[redacted]'),
                'response_status' => $response_status,
                'http_code' => $http_code,
                'parsed_category_id' => (string) ($parsed['category_id'] ?? ''),
                'parsed_category_name' => (string) ($parsed['category_name'] ?? ''),
                'confidence' => $accepted ? 'high' : (!empty($parsed['category_name']) || !empty($parsed['category_id']) ? 'low' : 'none'),
                'source_type' => $accepted ? 'api_candidate_code_lookup' : (!empty($classification['category_tree_or_catalog']) ? 'category_tree_fallback' : 'unavailable'),
                'endpoint_classification' => (string) ($classification['endpoint_classification'] ?? 'unusable'),
                'code_specific' => $code_specific,
                'detected_input_code' => $code,
                'matched_expected_panel_result' => $matched_expected,
                'safe_to_call_from_wordpress' => !empty($classification['safe_to_call_from_wordpress']),
                'requires_browser_session_cookies' => !empty($classification['requires_browser_session_cookies']),
                'uses_crm_importPart_credentials' => !empty($classification['uses_crm_importPart_credentials']),
                'accepted' => $accepted,
                'status' => $accepted ? 'completed' : 'no_code_specific_suggestion',
                'body_excerpt' => $body_excerpt,
            );
        }
        return $attempts;
    }

    private function category_prediction_name_matches_expected($name, $expected)
    {
        $expected = $this->normalize_category_label_for_compare($expected);
        if ($expected === '') {
            return true;
        }
        return $this->normalize_category_label_for_compare($name) === $expected;
    }

    private function test_ovoko_oem_lookup($oem)
    {
        $lookup = $this->ovoko_lookup_by_oem($oem, 10);
        if (is_wp_error($lookup)) {
            return array(
                'api_status' => 'error',
                'error' => $lookup->get_error_message(),
                'credentials_detected' => $this->ovoko_credentials_detected(),
                'writes' => 'none',
            );
        }
        $analysis = $this->analyze_ovoko_lookup($oem, $lookup);
        return array(
            'api_status' => $lookup['api_status'],
            'credentials_detected' => $this->ovoko_credentials_detected(),
            'endpoint' => $lookup['endpoint'],
            'match_count' => $analysis['match_count'],
            'confidence' => $analysis['confidence'],
            'parsed_vehicle_product_parameters' => $this->ovoko_public_suggestion_payload($analysis),
            'raw_response_excerpt' => $lookup['raw_response_excerpt'],
            'writes' => 'none',
        );
    }

    private function process_ovoko_enrichment_batch($dry_run, $batch_size)
    {
        $run_id = gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        $settings = $this->settings();
        $state = array(
            'run_id' => $run_id,
            'dry_run' => $dry_run ? 'yes' : 'no',
            'state' => 'running',
            'checked' => 0,
            'enriched' => 0,
            'skipped' => 0,
            'errors' => 0,
            'credentials_detected' => $this->ovoko_credentials_detected(),
            'safety' => array(
                'no_ebay_api' => true,
                'no_ovoko_listing_create' => true,
                'no_ovoko_listing_update' => true,
                'no_woo_publish' => true,
                'no_stock_sync_changes' => true,
                'no_price_changes' => true,
            ),
            'items' => array(),
        );

        if (empty($settings['ovoko_enrichment_enabled'])) {
            $state['state'] = 'disabled';
            $state['message'] = 'Ovoko enrichment is disabled in settings.';
            $this->write_ovoko_enrichment_last_run($state);
            return $state;
        }
        if (!$dry_run && empty($settings['ovoko_enrichment_save_suggestions'])) {
            $state['state'] = 'disabled';
            $state['message'] = 'Live suggestion saving is disabled in settings.';
            $this->write_ovoko_enrichment_last_run($state);
            return $state;
        }

        $products = $this->gmail_imported_draft_products_for_ovoko_enrichment($batch_size);
        foreach ($products as $product_id) {
            $state['checked']++;
            $title = get_the_title($product_id);
            $oem = sanitize_text_field((string) get_post_meta($product_id, '_gps_detected_oem_part_number', true));
            $normalized_oem = sanitize_text_field((string) get_post_meta($product_id, '_gps_normalized_oem_part_number', true));
            if ($oem === '' && $normalized_oem !== '') {
                $oem = $normalized_oem;
            }
            $base_row = array('product_id' => $product_id, 'title' => $title, 'oem' => $oem, 'normalized_oem' => $normalized_oem);
            if ($oem === '') {
                $state['skipped']++;
                $row = $this->ovoko_enrichment_report_row($run_id, $dry_run, $base_row + array('action' => 'skip', 'result' => 'missing_oem'));
                $this->write_ovoko_enrichment_report_row('actions', $row);
                $state['items'][] = $row;
                continue;
            }

            $lookup = $this->ovoko_lookup_by_oem($oem, 10);
            if (is_wp_error($lookup)) {
                $state['errors']++;
                $row = $this->ovoko_enrichment_report_row($run_id, $dry_run, $base_row + array('action' => 'lookup', 'result' => 'error', 'error_message' => $lookup->get_error_message()));
                $this->write_ovoko_enrichment_report_row('errors', $row);
                $state['items'][] = $row;
                continue;
            }

            $analysis = $this->analyze_ovoko_lookup($oem, $lookup);
            $suggested_category = $this->suggest_woo_category_from_ovoko($analysis);
            $analysis['suggested_category_id'] = $suggested_category['id'];
            $analysis['suggested_category_path'] = $suggested_category['path'];
            $action = $dry_run ? 'dry_run' : 'save_suggestions';
            $result = $dry_run ? 'would_save_suggestions' : $this->save_ovoko_enrichment_suggestions($product_id, $oem, $analysis, $settings);
            if (!$dry_run && $result === 'saved_suggestions') {
                $state['enriched']++;
            }

            $row = $this->ovoko_enrichment_report_row($run_id, $dry_run, $base_row + array(
                'match_count' => $analysis['match_count'],
                'confidence' => $analysis['confidence'],
                'vehicle_make' => $analysis['vehicle_make'],
                'vehicle_model' => $analysis['vehicle_model'],
                'vehicle_generation' => $analysis['vehicle_generation'],
                'vehicle_year' => $analysis['vehicle_year'],
                'engine_code' => $analysis['engine_code'],
                'engine_capacity' => $analysis['engine_capacity'],
                'fuel_type' => $analysis['fuel_type'],
                'gearbox_type' => $analysis['gearbox_type'],
                'part_name' => $analysis['part_name'],
                'part_category' => $analysis['part_category'],
                'suggested_category_id' => $analysis['suggested_category_id'],
                'suggested_category_path' => $analysis['suggested_category_path'],
                'action' => $action,
                'result' => $result,
            ));
            $this->write_ovoko_enrichment_report_row('actions', $row);
            $state['items'][] = $row + array('would_save_meta' => $this->ovoko_meta_payload($oem, $analysis));
        }

        $state['state'] = 'complete';
        $this->write_ovoko_enrichment_last_run($state);
        return $state;
    }

    private function ovoko_credentials_detected()
    {
        $settings = $this->ovoko_integration_settings();
        return !empty($settings['rrr_api_username']) && !empty($settings['rrr_api_password']) && !empty($settings['rrr_api_user_token']);
    }

    private function ovoko_integration_settings()
    {
        $defaults = array(
            'rrr_api_base_url' => 'https://api.rrr.lt',
            'rrr_api_username' => '',
            'rrr_api_password' => '',
            'rrr_api_user_token' => '',
        );
        return wp_parse_args((array) get_option('gpswiss_ovoko_settings', array()), $defaults);
    }

    private function ovoko_lookup_by_oem($oem, $limit = 10)
    {
        $oem = trim((string) $oem);
        if ($oem === '') {
            return new WP_Error('gps_ovoko_missing_oem', 'Missing OEM / part number for Ovoko lookup.');
        }
        $settings = $this->ovoko_integration_settings();
        if (!$this->ovoko_credentials_detected()) {
            return new WP_Error('gps_ovoko_credentials_missing', 'Ovoko/RRR API credentials were not found in GPSwiss Ovoko Integration settings.');
        }
        $base_url = rtrim((string) $settings['rrr_api_base_url'], '/');
        if ($base_url === '') {
            return new WP_Error('gps_ovoko_base_url_missing', 'Ovoko/RRR API base URL is missing.');
        }
        $limit = max(1, min(50, absint($limit)));
        $endpoint = '/v2/get/parts?limit=' . $limit . '&page=1&search=' . rawurlencode($oem);
        $response = wp_remote_post($base_url . $endpoint, array(
            'timeout' => 15,
            'body' => array(
                'username' => (string) $settings['rrr_api_username'],
                'password' => (string) $settings['rrr_api_password'],
                'user_token' => (string) $settings['rrr_api_user_token'],
            ),
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        ));
        if (is_wp_error($response)) {
            return $response;
        }
        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('gps_ovoko_non_json', 'Ovoko/RRR API returned a non-JSON response.');
        }
        $status_code = sanitize_text_field((string) ($decoded['status_code'] ?? ''));
        if ($http_code !== 200 || $status_code !== 'R200') {
            return new WP_Error('gps_ovoko_api_error', 'Ovoko/RRR API lookup failed with HTTP ' . $http_code . ' status_code ' . $status_code . '.');
        }
        $records = array();
        if (is_array($decoded['data'] ?? null)) {
            $records = $decoded['data'];
        } elseif (is_array($decoded['list'] ?? null)) {
            $records = $decoded['list'];
        }
        return array(
            'api_status' => 'ok',
            'http_code' => $http_code,
            'status_code' => $status_code,
            'endpoint' => $endpoint,
            'records' => array_values(array_filter($records, 'is_array')),
            'raw_response_excerpt' => substr($body, 0, 1500),
        );
    }

    private function analyze_ovoko_lookup($oem, $lookup)
    {
        $records = (array) ($lookup['records'] ?? array());
        $selected = $this->select_ovoko_match($oem, $records);
        $match_count = count($records);
        $exact = !empty($selected['exact_oem_match']);
        $part_name = $this->ovoko_record_value($selected, array('name', 'part_name', 'title'));
        $category_data = $this->ovoko_extract_category_data($selected);
        $part_category = $category_data['part_category'];
        $confidence = 'none';
        if ($match_count > 0 && $exact && $match_count === 1 && ($part_name !== '' || $part_category !== '')) {
            $confidence = 'high';
        } elseif ($match_count > 0 && $exact) {
            $confidence = 'medium';
        } elseif ($match_count > 0) {
            $confidence = 'low';
        }
        return array(
            'match_count' => $match_count,
            'selected_match_id' => sanitize_text_field((string) ($selected['id'] ?? $selected['part_id'] ?? '')),
            'confidence' => $confidence,
            'vehicle_make' => $this->ovoko_record_value($selected, array('car.manufacturer', 'car.make', 'vehicle_make', 'manufacturer', 'make')),
            'vehicle_model' => $this->ovoko_record_value($selected, array('car.model', 'vehicle_model', 'model')),
            'vehicle_generation' => $this->ovoko_record_value($selected, array('car.generation', 'vehicle_generation', 'generation')),
            'vehicle_year' => $this->ovoko_record_value($selected, array('car.year', 'vehicle_year', 'year', 'year_from')),
            'engine_code' => $this->ovoko_record_value($selected, array('car.engine_code', 'engine_code')),
            'engine_capacity' => $this->ovoko_record_value($selected, array('car.engine_capacity', 'engine_capacity', 'engine')),
            'fuel_type' => $this->ovoko_record_value($selected, array('car.fuel_type', 'fuel_type', 'fuel')),
            'gearbox_type' => $this->ovoko_record_value($selected, array('car.gearbox_type', 'gearbox_type', 'gearbox')),
            'power' => $this->ovoko_record_value($selected, array('car.power', 'power')),
            'mileage' => $this->ovoko_record_value($selected, array('car.mileage', 'mileage')),
            'part_name' => $part_name,
            'part_category' => $part_category,
            'category_id' => $category_data['category_id'],
            'category_name' => $category_data['category_name'],
            'category_path' => $category_data['category_path'],
            'raw_category_data' => $this->ovoko_raw_category_data($category_data),
            'oem_numbers' => $this->ovoko_oem_numbers($selected),
            'raw_match_summary' => $this->ovoko_raw_match_summary($selected),
            'raw_selected_match' => $this->ovoko_raw_selected_match($selected),
            'suggested_category_id' => 0,
            'suggested_category_path' => '',
        );
    }

    private function select_ovoko_match($oem, $records)
    {
        $normalized = $this->normalize_oem($oem);
        $first = array();
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            if ($first === array()) {
                $first = $record;
            }
            foreach ($this->ovoko_oem_numbers($record) as $candidate) {
                if ($this->normalize_oem($candidate) !== '' && $this->normalize_oem($candidate) === $normalized) {
                    $record['exact_oem_match'] = true;
                    return $record;
                }
            }
        }
        return $first;
    }

    private function ovoko_oem_numbers($record)
    {
        $values = array();
        foreach (array('manufacturer_code', 'visible_code', 'other_code', 'code', 'external_id', 'part_code', 'part_number', 'oem', 'oem_number', 'oem_numbers') as $key) {
            if (!isset($record[$key])) {
                continue;
            }
            if (is_array($record[$key])) {
                foreach ($record[$key] as $item) {
                    if (is_scalar($item)) {
                        $values[] = (string) $item;
                    }
                }
            } elseif (is_scalar($record[$key])) {
                $values[] = (string) $record[$key];
            }
        }
        return array_values(array_unique(array_filter(array_map('trim', $values))));
    }

    private function ovoko_extract_category_data($record)
    {
        $data = array(
            'category_id' => '',
            'category_name' => '',
            'category_path' => '',
            'part_category' => '',
            'source' => '',
            'reason' => '',
            'explicit_category_found' => false,
            'source_value' => '',
            'category_id_source' => '',
            'category_name_source' => '',
            'category_path_source' => '',
            'part_category_source' => '',
        );
        if (!is_array($record) || !$record) {
            $data['reason'] = 'selected match was empty or not an array';
            return $data;
        }

        $explicit_paths = array(
            'category',
            'category_name',
            'category_id',
            'category_path',
            'category_title_path',
            'part_category',
            'car_part_category',
            'rrr_category',
            'category.name',
            'category.title',
            'category.title_path',
            'category.path',
            'category.id',
        );
        foreach ($explicit_paths as $path) {
            $value = $this->array_path_value($record, $path);
            $this->apply_ovoko_category_value($data, $value, $path);
        }

        if (!$data['explicit_category_found']) {
            $nested = $this->find_ovoko_nested_category_object($record);
            if ($nested) {
                $this->apply_ovoko_category_value($data, $nested['value'], $nested['path']);
            }
        }

        $name = $this->ovoko_record_value($record, array('name'));
        if ($name !== '' && ($data['category_name'] === '' || $data['part_category'] === '')) {
            if ($data['category_name'] === '') {
                $data['category_name'] = $name;
                $data['category_name_source'] = 'selected_match.name fallback';
            }
            if ($data['part_category'] === '') {
                $data['part_category'] = $name;
                $data['part_category_source'] = 'selected_match.name fallback';
            }
            if ($data['source'] === '') {
                $data['source'] = 'selected_match.name';
                $data['source_value'] = $name;
            }
        }

        if ($data['explicit_category_found']) {
            if ($data['part_category'] === '') {
                $data['part_category'] = $data['category_path'] !== '' ? $data['category_path'] : $data['category_name'];
                $data['part_category_source'] = $data['category_path'] !== '' ? 'category_path' : $data['category_name_source'];
            }
            $data['reason'] = $data['category_name_source'] === 'selected_match.name fallback' || $data['part_category_source'] === 'selected_match.name fallback'
                ? 'category_id derived from explicit Ovoko category field; missing human-readable category fields derived from selected_match.name fallback'
                : 'category derived from explicit Ovoko category field';
            return $data;
        }

        if ($name !== '') {
            $data['category_name'] = $name;
            $data['part_category'] = $name;
            $data['source'] = 'selected_match.name';
            $data['source_value'] = $name;
            $data['category_name_source'] = 'selected_match.name fallback';
            $data['part_category_source'] = 'selected_match.name fallback';
            $data['reason'] = 'explicit category fields were empty or missing; category derived from selected_match.name fallback';
        } else {
            $data['reason'] = 'explicit category fields were empty or missing and selected_match.name was empty';
        }
        return $data;
    }

    private function apply_ovoko_category_value(&$data, $value, $source)
    {
        if (is_array($value)) {
            $id = $this->ovoko_first_scalar($value, array('id', 'category_id', 'rrr_category_id'));
            $name = $this->ovoko_first_scalar($value, array('name', 'title', 'category_name', 'label'));
            $path = $this->ovoko_first_scalar($value, array('path', 'title_path', 'category_path', 'breadcrumb'));
            if ($name === '' && $path !== '') {
                $name = trim((string) basename(str_replace('>', '/', $path)));
            }
            if ($id === '' && $name === '' && $path === '') {
                return false;
            }
            if ($id !== '' && $data['category_id'] === '') {
                $data['category_id'] = sanitize_text_field($id);
                $data['category_id_source'] = $source;
            }
            if ($name !== '' && $data['category_name'] === '') {
                $data['category_name'] = sanitize_text_field($name);
                $data['category_name_source'] = $source;
            }
            if ($path !== '' && $data['category_path'] === '') {
                $data['category_path'] = sanitize_text_field($path);
                $data['category_path_source'] = $source;
            }
            if ($data['part_category'] === '') {
                $part_category = $data['category_path'] !== '' ? $data['category_path'] : $data['category_name'];
                if ($part_category !== '') {
                    $data['part_category'] = $part_category;
                    $data['part_category_source'] = $data['category_path'] !== '' ? $data['category_path_source'] : $data['category_name_source'];
                }
            }
            if ($data['source'] === '') {
                $data['source'] = $source;
                $data['source_value'] = $value;
            }
            $data['explicit_category_found'] = true;
            return true;
        }
        if (!is_scalar($value) || trim((string) $value) === '') {
            return false;
        }
        $clean = sanitize_text_field((string) $value);
        if (substr($source, -3) === '_id' || $source === 'category.id') {
            if ($data['category_id'] === '') {
                $data['category_id'] = $clean;
                $data['category_id_source'] = $source;
            }
        } elseif (substr($source, -5) === '_path' || in_array($source, array('category.title_path', 'category.path', 'category_title_path'), true)) {
            if ($data['category_path'] === '') {
                $data['category_path'] = $clean;
                $data['category_path_source'] = $source;
            }
            if ($data['category_name'] === '') {
                $data['category_name'] = trim((string) basename(str_replace('>', '/', $clean)));
                $data['category_name_source'] = $source;
            }
        } else {
            if ($data['category_name'] === '') {
                $data['category_name'] = $clean;
                $data['category_name_source'] = $source;
            }
        }
        if ($data['part_category'] === '') {
            $part_category = in_array($source, array('part_category', 'car_part_category', 'rrr_category'), true) ? $clean : ($data['category_path'] !== '' ? $data['category_path'] : $data['category_name']);
            if ($part_category !== '') {
                $data['part_category'] = $part_category;
                $data['part_category_source'] = in_array($source, array('part_category', 'car_part_category', 'rrr_category'), true) ? $source : ($data['category_path'] !== '' ? $data['category_path_source'] : $data['category_name_source']);
            }
        }
        if ($data['source'] === '') {
            $data['source'] = $source;
            $data['source_value'] = $clean;
        }
        $data['explicit_category_found'] = true;
        return true;
    }

    private function ovoko_first_scalar($array, $keys)
    {
        foreach ($keys as $key) {
            if (isset($array[$key]) && is_scalar($array[$key]) && trim((string) $array[$key]) !== '') {
                return (string) $array[$key];
            }
        }
        return '';
    }

    private function find_ovoko_nested_category_object($value, $path = '')
    {
        if (!is_array($value)) {
            return null;
        }
        foreach ($value as $key => $child) {
            $child_path = $path === '' ? (string) $key : $path . '.' . $key;
            if (is_array($child) && stripos((string) $key, 'category') !== false) {
                $candidate = array('category_id' => '', 'category_name' => '', 'category_path' => '', 'part_category' => '', 'source' => '', 'reason' => '', 'explicit_category_found' => false, 'source_value' => '', 'category_id_source' => '', 'category_name_source' => '', 'category_path_source' => '', 'part_category_source' => '');
                if ($this->apply_ovoko_category_value($candidate, $child, $child_path)) {
                    return array('path' => $child_path, 'value' => $child);
                }
            }
        }
        foreach ($value as $key => $child) {
            if (!is_array($child)) {
                continue;
            }
            $child_path = $path === '' ? (string) $key : $path . '.' . $key;
            $nested = $this->find_ovoko_nested_category_object($child, $child_path);
            if ($nested) {
                return $nested;
            }
        }
        return null;
    }

    private function ovoko_raw_category_data($category_data)
    {
        return wp_json_encode($category_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ovoko_record_value($record, $paths)
    {
        foreach ($paths as $path) {
            $value = $this->array_path_value($record, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return sanitize_text_field((string) $value);
            }
        }
        return '';
    }

    private function array_path_value($array, $path)
    {
        $value = $array;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function ovoko_raw_selected_match($record)
    {
        if (!is_array($record) || !$record) {
            return '';
        }
        $raw = $record;
        unset($raw['exact_oem_match']);
        return wp_json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ovoko_raw_match_summary($record)
    {
        if (!$record) {
            return '';
        }
        $summary = array(
            'id' => $record['id'] ?? ($record['part_id'] ?? ''),
            'name' => $record['name'] ?? '',
            'manufacturer_code' => $record['manufacturer_code'] ?? '',
            'visible_code' => $record['visible_code'] ?? '',
            'other_code' => $record['other_code'] ?? '',
            'category' => $record['category_title_path'] ?? ($record['category_name'] ?? ($record['category'] ?? '')),
            'car' => $record['car'] ?? array(),
        );
        return wp_json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ovoko_public_suggestion_payload($analysis)
    {
        return array(
            'selected_match_id' => $analysis['selected_match_id'],
            'vehicle_make' => $analysis['vehicle_make'],
            'vehicle_model' => $analysis['vehicle_model'],
            'vehicle_generation' => $analysis['vehicle_generation'],
            'vehicle_year' => $analysis['vehicle_year'],
            'engine_code' => $analysis['engine_code'],
            'engine_capacity' => $analysis['engine_capacity'],
            'fuel_type' => $analysis['fuel_type'],
            'gearbox_type' => $analysis['gearbox_type'],
            'power' => $analysis['power'],
            'mileage' => $analysis['mileage'],
            'part_name' => $analysis['part_name'],
            'part_category' => $analysis['part_category'],
            'category_id' => $analysis['category_id'],
            'category_name' => $analysis['category_name'],
            'category_path' => $analysis['category_path'],
            'raw_category_data' => $analysis['raw_category_data'],
            'raw_selected_match' => $analysis['raw_selected_match'],
            'oem_numbers' => $analysis['oem_numbers'],
        );
    }

    private function gmail_imported_draft_products_for_ovoko_enrichment($batch_size)
    {
        return get_posts(array(
            'post_type' => 'product',
            'post_status' => 'draft',
            'fields' => 'ids',
            'posts_per_page' => max(1, min(25, absint($batch_size))),
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => array(
                array('key' => '_gps_gmail_import_source', 'value' => 'gmail'),
                array('key' => '_gps_normalized_oem_part_number', 'compare' => 'EXISTS'),
            ),
        ));
    }

    private function save_ovoko_enrichment_suggestions($product_id, $oem, $analysis, $settings)
    {
        $payload = $this->ovoko_meta_payload($oem, $analysis);
        foreach ($payload as $key => $value) {
            if (empty($settings['ovoko_enrichment_overwrite_existing_attributes']) && !in_array($key, array('_gps_ovoko_enrichment_status', '_gps_ovoko_enrichment_checked_at'), true) && get_post_meta($product_id, $key, true) !== '') {
                continue;
            }
            update_post_meta($product_id, $key, $value);
        }
        if ($analysis['confidence'] === 'high' && !empty($settings['ovoko_enrichment_auto_assign_category']) && !empty($analysis['suggested_category_id'])) {
            wp_set_object_terms($product_id, array((int) $analysis['suggested_category_id']), 'product_cat');
        }
        return 'saved_suggestions';
    }

    private function ovoko_meta_payload($oem, $analysis)
    {
        $needs_review = in_array($analysis['confidence'], array('medium', 'low'), true);
        return array(
            '_gps_ovoko_enrichment_status' => $analysis['confidence'] === 'none' ? 'no_match' : ($needs_review ? 'needs_review' : 'suggested'),
            '_gps_ovoko_enrichment_checked_at' => current_time('mysql', true),
            '_gps_ovoko_lookup_oem' => sanitize_text_field((string) $oem),
            '_gps_ovoko_match_count' => (int) $analysis['match_count'],
            '_gps_ovoko_selected_match_id' => sanitize_text_field($analysis['selected_match_id']),
            '_gps_ovoko_confidence' => sanitize_text_field($analysis['confidence']),
            '_gps_ovoko_vehicle_make' => sanitize_text_field($analysis['vehicle_make']),
            '_gps_ovoko_vehicle_model' => sanitize_text_field($analysis['vehicle_model']),
            '_gps_ovoko_vehicle_generation' => sanitize_text_field($analysis['vehicle_generation']),
            '_gps_ovoko_vehicle_year' => sanitize_text_field($analysis['vehicle_year']),
            '_gps_ovoko_engine_code' => sanitize_text_field($analysis['engine_code']),
            '_gps_ovoko_engine_capacity' => sanitize_text_field($analysis['engine_capacity']),
            '_gps_ovoko_fuel_type' => sanitize_text_field($analysis['fuel_type']),
            '_gps_ovoko_gearbox_type' => sanitize_text_field($analysis['gearbox_type']),
            '_gps_ovoko_power' => sanitize_text_field($analysis['power']),
            '_gps_ovoko_mileage' => sanitize_text_field($analysis['mileage']),
            '_gps_ovoko_part_name' => sanitize_text_field($analysis['part_name']),
            '_gps_ovoko_category_id' => sanitize_text_field($analysis['category_id']),
            '_gps_ovoko_category_name' => sanitize_text_field($analysis['category_name']),
            '_gps_ovoko_category_path' => sanitize_text_field($analysis['category_path']),
            '_gps_ovoko_part_category' => sanitize_text_field($analysis['part_category']),
            '_gps_ovoko_raw_category_data' => (string) $analysis['raw_category_data'],
            '_gps_ovoko_raw_selected_match' => (string) $analysis['raw_selected_match'],
            '_gps_ovoko_oem_numbers' => array_map('sanitize_text_field', (array) $analysis['oem_numbers']),
            '_gps_ovoko_raw_match_summary' => wp_kses_post($analysis['raw_match_summary']),
        );
    }

    private function suggest_woo_category_from_ovoko($analysis)
    {
        if ($analysis['confidence'] !== 'high' || trim((string) $analysis['part_category']) === '' || !taxonomy_exists('product_cat')) {
            return array('id' => 0, 'path' => '');
        }
        $category_name = trim((string) basename(str_replace('>', '/', $analysis['part_category'])));
        if ($category_name === '') {
            return array('id' => 0, 'path' => '');
        }
        $term = get_term_by('name', $category_name, 'product_cat');
        if (!$term) {
            $term = get_term_by('slug', sanitize_title($category_name), 'product_cat');
        }
        if (!$term || is_wp_error($term)) {
            return array('id' => 0, 'path' => $analysis['part_category']);
        }
        return array('id' => (int) $term->term_id, 'path' => $analysis['part_category']);
    }

    private function ovoko_enrichment_csv_columns()
    {
        return array('timestamp', 'run_id', 'dry_run', 'product_id', 'title', 'oem', 'normalized_oem', 'match_count', 'confidence', 'vehicle_make', 'vehicle_model', 'vehicle_generation', 'vehicle_year', 'engine_code', 'engine_capacity', 'fuel_type', 'gearbox_type', 'part_name', 'part_category', 'suggested_category_id', 'suggested_category_path', 'action', 'result', 'error_message');
    }

    private function ovoko_enrichment_report_row($run_id, $dry_run, $overrides)
    {
        return array_merge(array_fill_keys($this->ovoko_enrichment_csv_columns(), ''), array('timestamp' => current_time('mysql', true), 'run_id' => $run_id, 'dry_run' => $dry_run ? 'yes' : 'no'), $overrides);
    }

    private function write_ovoko_enrichment_report_row($type, $row)
    {
        self::ensure_report_directory();
        $files = $this->report_files();
        $path = $type === 'errors' ? $files['ovoko-enrichment-errors.csv'] : $files['ovoko-enrichment-actions.csv'];
        $exists = file_exists($path);
        $handle = fopen($path, 'ab');
        if (!$handle) {
            return;
        }
        if (!$exists) {
            fputcsv($handle, $this->ovoko_enrichment_csv_columns());
        }
        fputcsv($handle, array_map(function ($column) use ($row) { return $row[$column] ?? ''; }, $this->ovoko_enrichment_csv_columns()));
        fclose($handle);
    }

    private function write_ovoko_enrichment_last_run($state)
    {
        self::ensure_report_directory();
        file_put_contents($this->report_files()['ovoko-enrichment-last-run.json'], wp_json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function report_row_from_analysis($run_id, $dry_run, $a)
    {
        return $this->empty_report_row($run_id, $dry_run, array('gmail_message_id' => $a['message_id'], 'gmail_thread_id' => $a['thread_id'], 'gmail_date' => $a['date'], 'gmail_from' => $a['from'], 'gmail_subject' => $a['subject'], 'gmail_is_unread' => $a['gmail_is_unread'], 'gmail_read_status' => $a['gmail_read_status'], 'gmail_was_unread' => $a['gmail_was_unread'] ?? $a['gmail_is_unread'], 'gmail_marked_read' => $a['gmail_marked_read'] ?? 'no', 'gmail_mark_read_status' => $a['gmail_mark_read_status'] ?? '', 'gmail_mark_read_error' => $a['gmail_mark_read_error'] ?? '', 'gmail_query_used' => $a['gmail_query_used'], 'gmail_label_used' => $a['gmail_label_used'], 'message_status_filter' => $a['message_status_filter'], 'storage_location' => $a['storage_location'], 'detected_part_code' => $a['detected_part_code'], 'normalized_part_code' => $a['normalized_part_code'], 'detected_oem_part_number' => $a['detected_oem_part_number'], 'normalized_oem_part_number' => $a['normalized_oem_part_number'], 'detected_vehicle_make' => $a['detected_vehicle_make'], 'detected_vehicle_model' => $a['detected_vehicle_model'], 'detected_vehicle_confidence' => $a['detected_vehicle_confidence'], 'suggested_woo_category_id' => $a['suggested_woo_category_id'], 'suggested_woo_category_path' => $a['suggested_woo_category_path'], 'suggested_woo_category_confidence' => $a['suggested_woo_category_confidence'], 'image_attachments_found' => $a['image_attachments_found'], 'staging_item_id' => $a['staging_item_id'] ?? '', 'staging_status' => $a['staging_status'] ?? '', 'created_product_id' => $a['created_product_id'] ?? 0, 'duplicate_status' => $a['duplicate_status'], 'duplicate_existing_product_id' => $a['duplicate_existing_product_id']));
    }

    private function empty_report_row($run_id, $dry_run, $overrides = array())
    {
        return array_merge(array_fill_keys($this->csv_columns(), ''), array('timestamp' => current_time('mysql', true), 'run_id' => $run_id, 'dry_run' => $dry_run ? 'yes' : 'no'), $overrides);
    }

    private function csv_columns()
    {
        return array('timestamp', 'run_id', 'dry_run', 'gmail_message_id', 'gmail_thread_id', 'gmail_date', 'gmail_from', 'gmail_subject', 'gmail_is_unread', 'gmail_read_status', 'gmail_was_unread', 'gmail_marked_read', 'gmail_mark_read_status', 'gmail_mark_read_error', 'gmail_query_used', 'gmail_label_used', 'message_status_filter', 'storage_location', 'detected_part_code', 'normalized_part_code', 'detected_oem_part_number', 'normalized_oem_part_number', 'detected_vehicle_make', 'detected_vehicle_model', 'detected_vehicle_confidence', 'suggested_woo_category_id', 'suggested_woo_category_path', 'suggested_woo_category_confidence', 'image_attachments_found', 'images_imported', 'product_id', 'product_status', 'staging_item_id', 'staging_status', 'created_product_id', 'duplicate_status', 'duplicate_existing_product_id', 'action', 'result', 'error_message');
    }

    private function write_report_row($type, $row)
    {
        self::ensure_report_directory();
        $files = $this->report_files();
        $path = $type === 'errors' ? $files['gmail-product-import-errors.csv'] : $files['gmail-product-import-actions.csv'];
        $exists = file_exists($path);
        $handle = fopen($path, 'ab');
        if (!$handle) {
            return;
        }
        if (!$exists) {
            fputcsv($handle, $this->csv_columns());
        }
        fputcsv($handle, array_map(function ($column) use ($row) { return $row[$column] ?? ''; }, $this->csv_columns()));
        fclose($handle);
    }

    private function write_last_run($state)
    {
        self::ensure_report_directory();
        file_put_contents($this->report_files()['gmail-product-import-last-run.json'], wp_json_encode($state, JSON_PRETTY_PRINT));
    }

    private function report_files()
    {
        $upload = wp_upload_dir();
        $base = trailingslashit($upload['basedir']) . self::UPLOAD_DIR;
        return array('gmail-product-import-last-run.json' => $base . '/gmail-product-import-last-run.json', 'gmail-product-import-actions.csv' => $base . '/gmail-product-import-actions.csv', 'gmail-product-import-errors.csv' => $base . '/gmail-product-import-errors.csv', 'ovoko-enrichment-last-run.json' => $base . '/ovoko-enrichment-last-run.json', 'ovoko-enrichment-actions.csv' => $base . '/ovoko-enrichment-actions.csv', 'ovoko-enrichment-errors.csv' => $base . '/ovoko-enrichment-errors.csv');
    }

    public static function ensure_report_directory()
    {
        $upload = wp_upload_dir();
        $base = trailingslashit($upload['basedir']) . self::UPLOAD_DIR;
        if (!is_dir($base)) {
            wp_mkdir_p($base);
        }
        if (!file_exists($base . '/index.html')) {
            file_put_contents($base . '/index.html', '');
        }
    }
}

GPS_Gmail_Product_Importer::instance();
