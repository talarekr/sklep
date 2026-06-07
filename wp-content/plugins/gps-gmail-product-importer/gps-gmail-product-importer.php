<?php
/**
 * Plugin Name: GPS Gmail Product Importer
 * Description: Imports Gmail messages from a selected label into WooCommerce as safe draft product candidates.
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
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'maybe_handle_oauth_callback'));
        add_action('admin_post_gps_gmail_product_importer_disconnect', array($this, 'handle_disconnect'));
        add_action('admin_post_gps_gmail_product_importer_test', array($this, 'handle_test'));
        add_action('admin_post_gps_gmail_product_importer_dry_run', array($this, 'handle_dry_run'));
        add_action('admin_post_gps_gmail_product_importer_import', array($this, 'handle_import'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_test', array($this, 'handle_ovoko_test'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_enrichment_dry_run', array($this, 'handle_ovoko_enrichment_dry_run'));
        add_action('admin_post_gps_gmail_product_importer_ovoko_enrichment_save', array($this, 'handle_ovoko_enrichment_save'));
        add_action('wp_ajax_gps_gmail_product_importer_import_batch', array($this, 'ajax_import_batch'));
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
            'batch_size' => 5,
            'product_status' => 'draft',
            'import_images' => 1,
            'duplicate_protection' => 1,
            'auto_assign_high_confidence_category' => 0,
            'google_client_id' => '',
            'google_client_secret' => '',
            'delay_between_batches' => 3,
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
            'batch_size' => max(1, min(25, absint($input['batch_size'] ?? 5))),
            'product_status' => $status,
            'import_images' => empty($input['import_images']) ? 0 : 1,
            'duplicate_protection' => empty($input['duplicate_protection']) ? 0 : 1,
            'auto_assign_high_confidence_category' => empty($input['auto_assign_high_confidence_category']) ? 0 : 1,
            'google_client_id' => sanitize_text_field($input['google_client_id'] ?? ''),
            'google_client_secret' => sanitize_text_field($input['google_client_secret'] ?? ''),
            'delay_between_batches' => max(1, min(60, absint($input['delay_between_batches'] ?? 3))),
        );
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
    var running = false;
    function tick(){
        if(!running){ return; }
        var delay = parseInt($('#gps-gmail-delay').val() || 3, 10);
        $.post(GPSGmailImporter.ajaxUrl, {
            action: 'gps_gmail_product_importer_import_batch',
            nonce: GPSGmailImporter.nonce,
            batch_size: $('#gps-gmail-auto-batch-size').val()
        }, function(resp){
            $('#gps-gmail-run-output').text(JSON.stringify(resp.data || resp, null, 2));
            if(!resp.success || !resp.data || resp.data.state !== 'running'){
                running = false;
                $('#gps-gmail-start').prop('disabled', false);
                $('#gps-gmail-stop').prop('disabled', true);
                return;
            }
            setTimeout(tick, Math.max(1, delay) * 1000);
        });
    }
    $(document).on('click', '#gps-gmail-start', function(e){
        e.preventDefault();
        running = true;
        $('#gps-gmail-start').prop('disabled', true);
        $('#gps-gmail-stop').prop('disabled', false);
        tick();
    });
    $(document).on('click', '#gps-gmail-stop', function(e){
        e.preventDefault();
        running = false;
        $('#gps-gmail-start').prop('disabled', false);
        $('#gps-gmail-stop').prop('disabled', true);
        $.post(GPSGmailImporter.ajaxUrl, {
            action: 'gps_gmail_product_importer_import_batch',
            nonce: GPSGmailImporter.nonce,
            stop: 1
        }, function(resp){
            $('#gps-gmail-run-output').text(JSON.stringify(resp.data || resp, null, 2));
        });
    });
})(jQuery);
JS;
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
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
                <div class="notice notice-info"><pre><?php echo esc_html(wp_json_encode($last_result, JSON_PRETTY_PRINT)); ?></pre></div>
            <?php endif; ?>

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
                    <tr><th><label for="gps-ovoko-enrichment-delay"><?php esc_html_e('Ovoko enrichment delay between batches', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-ovoko-enrichment-delay" type="number" min="1" max="60" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[ovoko_enrichment_delay_between_batches]" value="<?php echo esc_attr($settings['ovoko_enrichment_delay_between_batches']); ?>"> <?php esc_html_e('seconds', 'gps-gmail-product-importer'); ?></td></tr>
                    <tr><th><label for="gps-gmail-batch-size"><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?></label></th><td><input id="gps-gmail-batch-size" type="number" min="1" max="25" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[batch_size]" value="<?php echo esc_attr($settings['batch_size']); ?>"></td></tr>
                    <tr><th><?php esc_html_e('Product status default', 'gps-gmail-product-importer'); ?></th><td><select name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[product_status]"><option value="draft" <?php selected($settings['product_status'], 'draft'); ?>><?php esc_html_e('Draft', 'gps-gmail-product-importer'); ?></option><option value="pending_review" <?php selected($settings['product_status'], 'pending_review'); ?>><?php esc_html_e('Pending review', 'gps-gmail-product-importer'); ?></option></select><p class="description"><?php esc_html_e('Default is draft. Products are never published automatically.', 'gps-gmail-product-importer'); ?></p></td></tr>
                    <tr><th><?php esc_html_e('Import images', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[import_images]" value="1" <?php checked($settings['import_images'], 1); ?>> <?php esc_html_e('Import jpg/jpeg/png/webp attachments', 'gps-gmail-product-importer'); ?></label></td></tr>
                    <tr><th><?php esc_html_e('Duplicate protection', 'gps-gmail-product-importer'); ?></th><td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[duplicate_protection]" value="1" <?php checked($settings['duplicate_protection'], 1); ?>> <?php esc_html_e('Skip existing Gmail message IDs and possible OEM duplicates', 'gps-gmail-product-importer'); ?></label></td></tr>
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

            <h2><?php echo esc_html__('3. Dry-run Import', 'gps-gmail-product-importer'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_dry_run">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="5"></label>
                <?php submit_button(__('Run dry-run', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
            </form>

            <h2><?php echo esc_html__('4. Import Products', 'gps-gmail-product-importer'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_import">
                <label><?php esc_html_e('Batch size', 'gps-gmail-product-importer'); ?> <input type="number" min="1" max="25" name="batch_size" value="<?php echo esc_attr($settings['batch_size']); ?>"></label>
                <?php submit_button(__('Import batch', 'gps-gmail-product-importer'), 'primary', 'submit', false); ?>
            </form>
            <p><label><?php esc_html_e('Auto-run batch size', 'gps-gmail-product-importer'); ?> <input id="gps-gmail-auto-batch-size" type="number" min="1" max="25" value="<?php echo esc_attr($settings['batch_size']); ?>"></label> <button class="button" id="gps-gmail-start"><?php esc_html_e('Start auto runner', 'gps-gmail-product-importer'); ?></button> <button class="button" id="gps-gmail-stop" disabled><?php esc_html_e('Stop auto runner', 'gps-gmail-product-importer'); ?></button></p>
            <pre id="gps-gmail-run-output"><?php echo esc_html(wp_json_encode($this->run_state(), JSON_PRETTY_PRINT)); ?></pre>

            <h2><?php echo esc_html__('5. Product Enrichment → Ovoko API enrichment', 'gps-gmail-product-importer'); ?></h2>
            <p><?php esc_html_e('Enriches Woo draft products imported from Gmail by reading _gps_detected_oem_part_number and _gps_normalized_oem_part_number, then calling the Ovoko/RRR API read-only OEM lookup. Live save writes suggestions only and never publishes Woo, eBay, or Ovoko listings.', 'gps-gmail-product-importer'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field(self::NONCE_ACTION); ?><input type="hidden" name="action" value="gps_gmail_product_importer_ovoko_test">
                <label for="gps-ovoko-test-oem"><?php esc_html_e('Test Ovoko OEM lookup', 'gps-gmail-product-importer'); ?></label>
                <input id="gps-ovoko-test-oem" class="regular-text" name="oem" placeholder="<?php echo esc_attr__('OEM / part number', 'gps-gmail-product-importer'); ?>">
                <?php submit_button(__('Test lookup (no writes)', 'gps-gmail-product-importer'), 'secondary', 'submit', false); ?>
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

            <h2><?php echo esc_html__('6. Reports', 'gps-gmail-product-importer'); ?></h2>
            <p><?php echo esc_html(sprintf(__('Reports are written under wp-content/uploads/%s/.', 'gps-gmail-product-importer'), self::UPLOAD_DIR)); ?></p>
            <ul>
                <?php foreach ($this->report_files() as $file => $path) : ?>
                    <li><?php echo esc_html($file); ?> — <?php echo file_exists($path) ? esc_html(size_format(filesize($path))) : esc_html__('not created yet', 'gps-gmail-product-importer'); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
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

    public function ajax_import_batch()
    {
        if (!current_user_can('manage_options') || !check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
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

    private function verify_admin_action()
    {
        if (!current_user_can('manage_options')) {
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
                if ($analysis['duplicate_status']) {
                    $state['total_duplicates']++;
                    $state['total_skipped']++;
                    $row['action'] = 'skip';
                    $row['result'] = $analysis['duplicate_status'];
                    $this->write_report_row('actions', $row);
                    $items[] = $analysis;
                    continue;
                }
                if ($dry_run) {
                    $row['action'] = 'dry_run';
                    $row['result'] = 'would_import';
                    $this->write_report_row('actions', $row);
                    $items[] = $analysis;
                    continue;
                }
                $created = $this->create_product_from_analysis($analysis, $message);
                if (is_wp_error($created)) {
                    $state['total_errors']++;
                    $row['action'] = 'create_product';
                    $row['result'] = 'error';
                    $row['error_message'] = $created->get_error_message();
                    $this->write_report_row('errors', $row);
                } else {
                    $state['total_imported']++;
                    $row['product_id'] = $created['product_id'];
                    $row['product_status'] = $created['product_status'];
                    $row['images_imported'] = $created['images_imported'];
                    $row['action'] = 'create_product';
                    $row['result'] = 'created_draft_candidate';
                    $this->write_report_row('actions', $row);
                    $analysis['product_id'] = $created['product_id'];
                    $analysis['images_imported'] = $created['images_imported'];
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
        return array('batches_completed' => 0, 'total_checked' => 0, 'total_imported' => 0, 'total_skipped' => 0, 'total_duplicates' => 0, 'total_errors' => 0, 'remaining_messages' => 0, 'gmail_query_used' => '', 'gmail_label_used' => '', 'message_status_filter' => $this->sanitize_message_status_filter($this->settings()['message_status_filter'] ?? 'read'), 'state' => 'idle', 'stopped_reason' => '', 'last_batch_result' => array());
    }

    private function run_state()
    {
        return wp_parse_args((array) get_option(self::OPTION_RUN_STATE, array()), $this->fresh_run_state());
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

    private function analyze_message($message, $label, $message_list_request = array())
    {
        $headers = $this->headers($message);
        $subject = $headers['subject'] ?? '';
        $oem = $this->extract_oem_candidates($subject);
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
            'gmail_query_used' => sanitize_text_field($message_list_request['gmail_query_used'] ?? ''),
            'gmail_label_used' => sanitize_text_field($message_list_request['gmail_label_used'] ?? $label),
            'message_status_filter' => sanitize_text_field($message_list_request['message_status_filter'] ?? 'read'),
            'body' => wp_kses_post($body),
            'label' => sanitize_text_field($label),
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
        preg_match_all('/[A-Z0-9][A-Z0-9\-\s\.]{3,}[A-Z0-9]/i', (string) $subject, $matches);
        $candidates = array();
        foreach ($matches[0] ?? array() as $candidate) {
            $candidate = trim(preg_replace('/\s+/', ' ', strtoupper($candidate)));
            $normalized = $this->normalize_oem($candidate);
            if (strlen($normalized) >= 4 && preg_match('/\d/', $normalized)) {
                $candidates[$normalized] = $candidate;
            }
        }
        uasort($candidates, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        $selected = reset($candidates) ?: '';
        return array('selected' => $selected, 'normalized' => $this->normalize_oem($selected), 'candidates' => array_values($candidates));
    }

    private function normalize_oem($value)
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));
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

    private function create_product_from_analysis($analysis, $message)
    {
        if (!function_exists('wc_get_product')) {
            return new WP_Error('gps_gmail_wc_missing', 'WooCommerce is required to create products.');
        }
        $settings = $this->settings();
        $status = $settings['product_status'] === 'pending_review' ? 'pending' : 'draft';
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
        update_post_meta($product_id, '_gps_gmail_import_message_id', $analysis['message_id']);
        update_post_meta($product_id, '_gps_gmail_import_thread_id', $analysis['thread_id']);
        update_post_meta($product_id, '_gps_gmail_import_subject', $analysis['subject']);
        update_post_meta($product_id, '_gps_gmail_import_from', $analysis['from']);
        update_post_meta($product_id, '_gps_gmail_import_date', $analysis['date']);
        update_post_meta($product_id, '_gps_gmail_import_label', $analysis['label']);
        update_post_meta($product_id, '_gps_gmail_imported_at', current_time('mysql', true));
        update_post_meta($product_id, '_gps_gmail_import_status', in_array('needs_review', $analysis['warnings'], true) ? 'needs_review' : 'imported');
        update_post_meta($product_id, '_gps_gmail_import_source', 'gmail');
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
        if ($settings['auto_assign_high_confidence_category'] && $analysis['suggested_woo_category_id'] && $analysis['suggested_woo_category_confidence'] === 'high') {
            wp_set_object_terms($product_id, array((int) $analysis['suggested_woo_category_id']), 'product_cat');
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
        $part_category = $this->ovoko_record_value($selected, array('category_title_path', 'category_name', 'category', 'category.title_path', 'category.name'));
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
            'oem_numbers' => $this->ovoko_oem_numbers($selected),
            'raw_match_summary' => $this->ovoko_raw_match_summary($selected),
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
            '_gps_ovoko_part_category' => sanitize_text_field($analysis['part_category']),
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
        return $this->empty_report_row($run_id, $dry_run, array('gmail_message_id' => $a['message_id'], 'gmail_thread_id' => $a['thread_id'], 'gmail_date' => $a['date'], 'gmail_from' => $a['from'], 'gmail_subject' => $a['subject'], 'gmail_is_unread' => $a['gmail_is_unread'], 'gmail_read_status' => $a['gmail_read_status'], 'gmail_query_used' => $a['gmail_query_used'], 'gmail_label_used' => $a['gmail_label_used'], 'message_status_filter' => $a['message_status_filter'], 'detected_oem_part_number' => $a['detected_oem_part_number'], 'normalized_oem_part_number' => $a['normalized_oem_part_number'], 'detected_vehicle_make' => $a['detected_vehicle_make'], 'detected_vehicle_model' => $a['detected_vehicle_model'], 'detected_vehicle_confidence' => $a['detected_vehicle_confidence'], 'suggested_woo_category_id' => $a['suggested_woo_category_id'], 'suggested_woo_category_path' => $a['suggested_woo_category_path'], 'suggested_woo_category_confidence' => $a['suggested_woo_category_confidence'], 'image_attachments_found' => $a['image_attachments_found'], 'duplicate_status' => $a['duplicate_status'], 'duplicate_existing_product_id' => $a['duplicate_existing_product_id']));
    }

    private function empty_report_row($run_id, $dry_run, $overrides = array())
    {
        return array_merge(array_fill_keys($this->csv_columns(), ''), array('timestamp' => current_time('mysql', true), 'run_id' => $run_id, 'dry_run' => $dry_run ? 'yes' : 'no'), $overrides);
    }

    private function csv_columns()
    {
        return array('timestamp', 'run_id', 'dry_run', 'gmail_message_id', 'gmail_thread_id', 'gmail_date', 'gmail_from', 'gmail_subject', 'gmail_is_unread', 'gmail_read_status', 'gmail_query_used', 'gmail_label_used', 'message_status_filter', 'detected_oem_part_number', 'normalized_oem_part_number', 'detected_vehicle_make', 'detected_vehicle_model', 'detected_vehicle_confidence', 'suggested_woo_category_id', 'suggested_woo_category_path', 'suggested_woo_category_confidence', 'image_attachments_found', 'images_imported', 'product_id', 'product_status', 'duplicate_status', 'duplicate_existing_product_id', 'action', 'result', 'error_message');
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
