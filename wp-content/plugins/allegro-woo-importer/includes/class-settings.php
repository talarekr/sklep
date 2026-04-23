<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    private const LISTING_IMAGES_CHECKPOINT_OPTION_KEY = 'awi_listing_images_regen_checkpoint';
    private const LISTING_IMAGES_LAST_BATCH_OPTION_KEY = 'awi_listing_images_last_batch';

    private AllegroAuth $auth;
    private Importer $importer;
    private Logger $logger;
    private Cron $cron;
    private ProductMapper $mapper;

    public function __construct(AllegroAuth $auth, Importer $importer, Logger $logger, Cron $cron, ProductMapper $mapper)
    {
        $this->auth = $auth;
        $this->importer = $importer;
        $this->logger = $logger;
        $this->cron = $cron;
        $this->mapper = $mapper;
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_awi_manual_import', [$this, 'handle_manual_import']);
        add_action('admin_post_awi_restore_active_offers', [$this, 'handle_restore_active_offers']);
        add_action('admin_post_awi_listing_images_regenerate_batch', [$this, 'handle_listing_images_regenerate_batch']);
        add_action('admin_post_awi_listing_images_inspect_front', [$this, 'handle_listing_images_inspect_front']);
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Allegro Import', 'allegro-woo-importer'),
            __('Allegro Import', 'allegro-woo-importer'),
            'manage_woocommerce',
            'awi-settings',
            [$this, 'render_page'],
            'dashicons-update'
        );
    }

    public function register_settings(): void
    {
        register_setting('awi_settings_group', Plugin::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default' => Plugin::get_settings(),
        ]);

        register_setting('awi_settings_group', Plugin::SAFE_MODE_OPTION_KEY, [
            'type' => 'boolean',
            'sanitize_callback' => static function ($value): bool {
                return !empty($value);
            },
            'default' => false,
        ]);
    }

    public function sanitize_settings($input = null): array
    {
        if (!is_array($input)) {
            $input = [];
        }

        $safe_mode_raw = $_POST[Plugin::SAFE_MODE_OPTION_KEY] ?? '0';
        if (is_array($safe_mode_raw)) {
            $safe_mode_raw = end($safe_mode_raw);
        }
        $safe_mode_enabled = !empty($safe_mode_raw) && (string) $safe_mode_raw !== '0';
        update_option(Plugin::SAFE_MODE_OPTION_KEY, $safe_mode_enabled ? '1' : '0', false);

        $current = Plugin::get_settings();

        $clean = [
            'client_id' => sanitize_text_field($input['client_id'] ?? ''),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
            'redirect_uri' => esc_url_raw($input['redirect_uri'] ?? ''),
            'environment' => in_array(($input['environment'] ?? 'production'), ['production', 'sandbox'], true) ? $input['environment'] : 'production',
            'sync_mode' => in_array(($input['sync_mode'] ?? 'create_update'), ['create_only', 'update_only', 'create_update'], true) ? $input['sync_mode'] : 'create_update',
            'inactive_product_status' => in_array(($input['inactive_product_status'] ?? 'draft'), ['draft', 'private'], true) ? $input['inactive_product_status'] : 'draft',
            'cron_interval' => in_array(($input['cron_interval'] ?? 'manual'), ['manual', 'awi_15_minutes', 'hourly', 'daily'], true) ? $input['cron_interval'] : 'manual',
            'offer_status' => sanitize_text_field($input['offer_status'] ?? 'ACTIVE'),
            'reconciliation_enabled' => !empty($input['reconciliation_enabled']),
        ];

        return array_merge($current, $clean);
    }

    public function handle_manual_import(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'allegro-woo-importer'));
        }

        check_admin_referer('awi_manual_import');
        if ($this->block_heavy_operation('manual_import')) {
            return;
        }

        $token = $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            $this->logger->error('Manual import blocked: missing valid Allegro token.', ['error' => $token->get_error_message()]);
            $this->store_admin_notice('error', __('Najpierw połącz wtyczkę z Allegro (brak ważnego access tokena).', 'allegro-woo-importer'));
            wp_safe_redirect(add_query_arg(['page' => 'awi-settings'], admin_url('admin.php')));
            exit;
        }

        $summary = $this->importer->import_offers();

        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_import_done' => '1',
            'awi_created' => (int) ($summary['created'] ?? 0),
            'awi_updated' => (int) ($summary['updated'] ?? 0),
            'awi_errors' => (int) ($summary['errors'] ?? 0),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_restore_active_offers(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'allegro-woo-importer'));
        }

        check_admin_referer('awi_restore_active_offers');
        if ($this->block_heavy_operation('restore_active_offers')) {
            return;
        }

        $token = $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            $this->logger->error('Recovery blocked: missing valid Allegro token.', ['error' => $token->get_error_message()]);
            $this->store_admin_notice('error', __('Najpierw połącz wtyczkę z Allegro (brak ważnego access tokena).', 'allegro-woo-importer'));
            wp_safe_redirect(add_query_arg(['page' => 'awi-settings'], admin_url('admin.php')));
            exit;
        }

        $summary = $this->importer->restore_active_offers_to_instock();

        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_restore_done' => '1',
            'awi_restore_checked' => (int) ($summary['checked'] ?? 0),
            'awi_restore_restored' => (int) ($summary['restored'] ?? 0),
            'awi_restore_errors' => (int) ($summary['errors'] ?? 0),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $this->consume_admin_notice();

        if (isset($_GET['awi_import_done'])) {
            add_settings_error(
                'awi_messages',
                'awi_import_done',
                sprintf(
                    __('Import zakończony. Utworzono: %1$d, zaktualizowano: %2$d, błędy: %3$d', 'allegro-woo-importer'),
                    (int) ($_GET['awi_created'] ?? 0),
                    (int) ($_GET['awi_updated'] ?? 0),
                    (int) ($_GET['awi_errors'] ?? 0)
                ),
                'updated'
            );
        }

        if (isset($_GET['awi_restore_done'])) {
            add_settings_error(
                'awi_messages',
                'awi_restore_done',
                sprintf(
                    __('Recovery zakończony. Sprawdzono: %1$d, przywrócono: %2$d, błędy: %3$d', 'allegro-woo-importer'),
                    (int) ($_GET['awi_restore_checked'] ?? 0),
                    (int) ($_GET['awi_restore_restored'] ?? 0),
                    (int) ($_GET['awi_restore_errors'] ?? 0)
                ),
                'updated'
            );
        }

        if (isset($_GET['awi_listing_regen_done'])) {
            add_settings_error(
                'awi_messages',
                'awi_listing_regen_done',
                sprintf(
                    __('Regeneracja listing images: przetworzono %1$d, utworzono %2$d, pominięto %3$d, błędy %4$d. Kolejne po ID: %5$d', 'allegro-woo-importer'),
                    (int) ($_GET['awi_listing_processed'] ?? 0),
                    (int) ($_GET['awi_listing_created'] ?? 0),
                    (int) ($_GET['awi_listing_skipped'] ?? 0),
                    (int) ($_GET['awi_listing_errors'] ?? 0),
                    (int) ($_GET['awi_listing_next_after_id'] ?? 0)
                ),
                ((int) ($_GET['awi_listing_errors'] ?? 0)) > 0 ? 'error' : 'updated'
            );
        }

        if (isset($_GET['awi_listing_inspect_done'])) {
            add_settings_error(
                'awi_messages',
                'awi_listing_inspect_done',
                sprintf(
                    __('Diagnostyka ostatniego batcha zakończona. Zalogowano %1$d/%2$d produktów z batcha z %3$s.', 'allegro-woo-importer'),
                    (int) ($_GET['awi_listing_inspect_logged'] ?? 0),
                    (int) ($_GET['awi_listing_inspect_batch_count'] ?? 0),
                    sanitize_text_field((string) ($_GET['awi_listing_inspect_batch_ts'] ?? '—'))
                ),
                'updated'
            );
        }

        $settings = Plugin::get_settings();
        $history = get_option(Plugin::HISTORY_OPTION_KEY, []);
        if (!is_array($history)) {
            $history = [];
        }

        $oauth_url = $this->auth->get_authorization_url();
        $callback_uri = $this->auth->get_connection_callback_uri();
        $listing_regen_checkpoint = get_option(self::LISTING_IMAGES_CHECKPOINT_OPTION_KEY, []);
        if (!is_array($listing_regen_checkpoint)) {
            $listing_regen_checkpoint = [];
        }
        $listing_last_batch = get_option(self::LISTING_IMAGES_LAST_BATCH_OPTION_KEY, []);
        if (!is_array($listing_last_batch)) {
            $listing_last_batch = [];
        }

        $log_tail = $this->logger->read_tail(80);

        include AWI_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public function handle_listing_images_regenerate_batch(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'allegro-woo-importer'));
        }

        check_admin_referer('awi_listing_images_regenerate_batch');
        if ($this->block_heavy_operation('listing_images_regenerate_batch')) {
            return;
        }

        $batch_size = isset($_POST['awi_listing_batch_size']) ? max(1, (int) $_POST['awi_listing_batch_size']) : 10;
        $batch_size = min(50, $batch_size);
        $reset = !empty($_POST['awi_listing_reset_checkpoint']);
        $force_regenerate = !empty($_POST['awi_listing_force_regenerate']);

        if ($reset) {
            delete_option(self::LISTING_IMAGES_CHECKPOINT_OPTION_KEY);
        }

        $result = $this->run_listing_images_regeneration_batch($batch_size, $force_regenerate);
        $this->logger->info('Listing images regeneration batch executed from admin.', $result + [
            'batch_size' => $batch_size,
            'reset' => $reset,
            'force_regenerate' => $force_regenerate,
            'trigger' => 'admin_button',
        ]);

        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_listing_regen_done' => '1',
            'awi_listing_processed' => (int) ($result['processed'] ?? 0),
            'awi_listing_created' => (int) ($result['created'] ?? 0),
            'awi_listing_skipped' => (int) ($result['skipped'] ?? 0),
            'awi_listing_errors' => (int) ($result['errors'] ?? 0),
            'awi_listing_next_after_id' => (int) ($result['next_after_id'] ?? 0),
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_listing_images_inspect_front(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Brak uprawnień.', 'allegro-woo-importer'));
        }

        check_admin_referer('awi_listing_images_inspect_front');
        if ($this->block_heavy_operation('listing_images_inspect_front')) {
            return;
        }

        $batch = get_option(self::LISTING_IMAGES_LAST_BATCH_OPTION_KEY, []);
        if (!is_array($batch)) {
            $batch = [];
        }
        $product_ids = array_values(array_filter(array_map('intval', (array) ($batch['product_ids'] ?? [])), static function (int $product_id): bool {
            return $product_id > 0;
        }));
        if ($product_ids === []) {
            $this->store_admin_notice('error', __('Brak zapisanych produktów z ostatniego batcha. Najpierw uruchom regenerację batcha.', 'allegro-woo-importer'));
            wp_safe_redirect(add_query_arg(['page' => 'awi-settings'], admin_url('admin.php')));
            exit;
        }

        $rows = $this->run_front_listing_images_diagnostics_for_products($product_ids);
        $batch_timestamp = sanitize_text_field((string) ($batch['updated_at'] ?? ''));

        $this->logger->info('Front listing image diagnostics executed from admin.', [
            'trigger' => 'admin_button',
            'batch_products' => count($product_ids),
            'batch_timestamp' => $batch_timestamp,
            'logged_products' => count($rows),
        ]);

        foreach ($rows as $row) {
            $this->logger->info('Front listing image diagnostics product.', $row);
        }

        $redirect = add_query_arg([
            'page' => 'awi-settings',
            'awi_listing_inspect_done' => '1',
            'awi_listing_inspect_logged' => count($rows),
            'awi_listing_inspect_batch_count' => count($product_ids),
            'awi_listing_inspect_batch_ts' => $batch_timestamp !== '' ? $batch_timestamp : '—',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    private function run_front_listing_images_diagnostics_for_products(array $product_ids): array
    {
        if (!function_exists('wc_get_product')) {
            $this->logger->error('Front listing image diagnostics failed: WooCommerce function wc_get_product() unavailable.');
            return [];
        }

        $last_batch = get_option(self::LISTING_IMAGES_LAST_BATCH_OPTION_KEY, []);
        $batch_id = is_array($last_batch) ? (string) ($last_batch['updated_at'] ?? '') : '';

        $rows = [];
        foreach ($product_ids as $product_id) {
            $product_id = (int) $product_id;
            if ($product_id <= 0) {
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                $rows[] = [
                    'product_id' => $product_id,
                    'product_name' => '',
                    'permalink' => '',
                    'batch_id' => $batch_id,
                    'rendered_source' => 'product_not_found',
                    'helper_selected_image_id' => 0,
                    'listing_image_id' => 0,
                    'featured_image_id' => 0,
                    'candidate_source_image_ids' => [],
                    'selected_source_image_id' => 0,
                    'selected_source_aspect_ratio' => 0.0,
                    'selected_source_selection_reason' => '',
                    'listing_quality_tier' => '',
                    'listing_quality_score' => 0.0,
                    'best_available_source_quality_tier' => '',
                    'requires_better_source' => false,
                    'gallery_images_count' => 0,
                    'listing_file_exists' => false,
                    'listing_attachment_source_width' => 0,
                    'listing_attachment_source_height' => 0,
                    'listing_attachment_source_aspect_ratio' => 0.0,
                    'listing_attachment_rendered_width' => 0,
                    'listing_attachment_rendered_height' => 0,
                    'listing_attachment_scale_factor' => 0.0,
                    'listing_attachment_fill_ratio' => 0.0,
                    'listing_attachment_target_fill_ratio' => 0.0,
                    'listing_attachment_final_fit_mode' => '',
                    'listing_attachment_used_crop' => false,
                    'listing_attachment_fallback_used' => false,
                    'aspect_ratio' => 0.0,
                    'is_extreme_aspect_ratio' => false,
                    'fit_limited_by' => '',
                ];
                continue;
            }

            $diagnostics = $this->mapper->get_listing_image_diagnostics($product_id);
            $rows[] = [
                'product_id' => $product_id,
                'product_name' => $product->get_name(),
                'permalink' => get_permalink($product_id),
                'batch_id' => $batch_id,
                'rendered_source' => (string) ($diagnostics['rendered_source'] ?? ''),
                'helper_selected_image_id' => (int) ($diagnostics['helper_selected_image_id'] ?? 0),
                'listing_image_id' => (int) ($diagnostics['listing_image_id'] ?? 0),
                'featured_image_id' => (int) ($diagnostics['featured_image_id'] ?? 0),
                'candidate_source_image_ids' => array_map('intval', (array) ($diagnostics['candidate_source_image_ids'] ?? [])),
                'selected_source_image_id' => (int) ($diagnostics['selected_source_image_id'] ?? 0),
                'selected_source_aspect_ratio' => (float) ($diagnostics['selected_source_aspect_ratio'] ?? 0),
                'selected_source_selection_reason' => (string) ($diagnostics['selected_source_selection_reason'] ?? ''),
                'listing_quality_tier' => (string) ($diagnostics['listing_quality_tier'] ?? ''),
                'listing_quality_score' => (float) ($diagnostics['listing_quality_score'] ?? 0),
                'best_available_source_quality_tier' => (string) ($diagnostics['best_available_source_quality_tier'] ?? ''),
                'requires_better_source' => !empty($diagnostics['requires_better_source']),
                'gallery_images_count' => (int) ($diagnostics['gallery_images_count'] ?? 0),
                'listing_file_exists' => !empty($diagnostics['listing_file_exists']),
                'listing_attachment_source_width' => (int) ($diagnostics['listing_attachment_source_width'] ?? 0),
                'listing_attachment_source_height' => (int) ($diagnostics['listing_attachment_source_height'] ?? 0),
                'listing_attachment_source_aspect_ratio' => (float) ($diagnostics['listing_attachment_source_aspect_ratio'] ?? 0),
                'listing_attachment_rendered_width' => (int) ($diagnostics['listing_attachment_rendered_width'] ?? 0),
                'listing_attachment_rendered_height' => (int) ($diagnostics['listing_attachment_rendered_height'] ?? 0),
                'listing_attachment_scale_factor' => (float) ($diagnostics['listing_attachment_scale_factor'] ?? 0),
                'listing_attachment_fill_ratio' => (float) ($diagnostics['listing_attachment_fill_ratio'] ?? 0),
                'listing_attachment_target_fill_ratio' => (float) ($diagnostics['listing_attachment_target_fill_ratio'] ?? 0),
                'listing_attachment_final_fit_mode' => (string) ($diagnostics['listing_attachment_final_fit_mode'] ?? ''),
                'listing_attachment_used_crop' => !empty($diagnostics['listing_attachment_used_crop']),
                'listing_attachment_fallback_used' => !empty($diagnostics['listing_attachment_fallback_used']),
                'aspect_ratio' => (float) ($diagnostics['aspect_ratio'] ?? 0),
                'is_extreme_aspect_ratio' => !empty($diagnostics['is_extreme_aspect_ratio']),
                'fit_limited_by' => (string) ($diagnostics['fit_limited_by'] ?? ''),
            ];
        }

        return $rows;
    }

    private function run_listing_images_regeneration_batch(int $batch_size, bool $force_regenerate = false): array
    {
        $checkpoint = get_option(self::LISTING_IMAGES_CHECKPOINT_OPTION_KEY, []);
        if (!is_array($checkpoint)) {
            $checkpoint = [];
        }

        $last_product_id = max(0, (int) ($checkpoint['last_product_id'] ?? 0));
        $processed_total = max(0, (int) ($checkpoint['processed_total'] ?? 0));
        $created_total = max(0, (int) ($checkpoint['created_total'] ?? 0));
        $skipped_total = max(0, (int) ($checkpoint['skipped_total'] ?? 0));
        $error_total = max(0, (int) ($checkpoint['error_total'] ?? 0));

        global $wpdb;
        $posts_table = $wpdb->posts;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$posts_table}
            WHERE post_type = 'product'
              AND post_status IN ('publish', 'draft', 'pending', 'private')
              AND ID > %d
            ORDER BY ID ASC
            LIMIT %d",
            $last_product_id,
            $batch_size
        ));

        if (!is_array($ids) || empty($ids)) {
            delete_option(self::LISTING_IMAGES_CHECKPOINT_OPTION_KEY);
            return [
                'processed' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => 0,
                'next_after_id' => 0,
                'done' => true,
            ];
        }

        $batch_processed = 0;
        $batch_created = 0;
        $batch_skipped = 0;
        $batch_errors = 0;
        $batch_extreme_ratio_products = 0;
        $batch_preferred_count = 0;
        $batch_acceptable_count = 0;
        $batch_degraded_count = 0;
        $batch_last_resort_count = 0;
        $batch_requires_better_source_count = 0;
        $processed_product_ids = [];

        foreach ($ids as $raw_id) {
            $product_id = (int) $raw_id;
            $result = $this->mapper->ensure_listing_image_for_product($product_id, $force_regenerate);
            $status = (string) ($result['status'] ?? 'error');

            $batch_processed++;
            $processed_total++;
            $last_product_id = $product_id;
            $processed_product_ids[] = $product_id;

            if ($status === 'created') {
                $batch_created++;
                $created_total++;
                $created_listing_image_id = (int) ($result['listing_image_id'] ?? 0);
                $is_extreme_ratio = $created_listing_image_id > 0
                    ? (int) get_post_meta($created_listing_image_id, '_gp_listing_is_extreme_ratio', true) === 1
                    : false;
                if ($is_extreme_ratio) {
                    $batch_extreme_ratio_products++;
                }
                $this->log_listing_selection_qa_snapshot($product_id, $result);
                continue;
            }

            if ($status === 'skipped') {
                $batch_skipped++;
                $skipped_total++;
                $this->logger->info('Listing image regeneration skipped (admin batch).', [
                    'product_id' => $product_id,
                    'skip_reason' => (string) ($result['reason'] ?? 'unknown'),
                    'force_regenerate' => $force_regenerate,
                    'listing_image_id' => (int) ($result['listing_image_id'] ?? 0),
                ]);
                $this->log_listing_selection_qa_snapshot($product_id, $result);
                continue;
            }

            $batch_errors++;
            $error_total++;
            $this->logger->error('Listing image regeneration failed (admin batch).', [
                'product_id' => $product_id,
                'result' => $result,
            ]);
        }

        update_option(self::LISTING_IMAGES_CHECKPOINT_OPTION_KEY, [
            'last_product_id' => $last_product_id,
            'processed_total' => $processed_total,
            'created_total' => $created_total,
            'skipped_total' => $skipped_total,
            'error_total' => $error_total,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], false);

        update_option(self::LISTING_IMAGES_LAST_BATCH_OPTION_KEY, [
            'product_ids' => $processed_product_ids,
            'first_product_id' => $processed_product_ids !== [] ? (int) $processed_product_ids[0] : 0,
            'last_product_id' => $processed_product_ids !== [] ? (int) $processed_product_ids[count($processed_product_ids) - 1] : 0,
            'batch_size' => $batch_size,
            'force_regenerate' => $force_regenerate,
            'processed' => $batch_processed,
            'created' => $batch_created,
            'skipped' => $batch_skipped,
            'errors' => $batch_errors,
            'extreme_ratio_products_count' => $batch_extreme_ratio_products,
            'preferred_count' => $batch_preferred_count,
            'acceptable_count' => $batch_acceptable_count,
            'degraded_count' => $batch_degraded_count,
            'last_resort_count' => $batch_last_resort_count,
            'requires_better_source_count' => $batch_requires_better_source_count,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], false);

        $this->logger->info('Listing image regeneration batch QA summary.', [
            'processed' => $batch_processed,
            'created' => $batch_created,
            'skipped' => $batch_skipped,
            'errors' => $batch_errors,
            'preferred_count' => $batch_preferred_count,
            'acceptable_count' => $batch_acceptable_count,
            'degraded_count' => $batch_degraded_count,
            'last_resort_count' => $batch_last_resort_count,
            'requires_better_source_count' => $batch_requires_better_source_count,
        ]);

        return [
            'processed' => $batch_processed,
            'created' => $batch_created,
            'skipped' => $batch_skipped,
            'errors' => $batch_errors,
            'extreme_ratio_products_count' => $batch_extreme_ratio_products,
            'preferred_count' => $batch_preferred_count,
            'acceptable_count' => $batch_acceptable_count,
            'degraded_count' => $batch_degraded_count,
            'last_resort_count' => $batch_last_resort_count,
            'requires_better_source_count' => $batch_requires_better_source_count,
            'next_after_id' => $last_product_id,
            'batch_product_ids' => $processed_product_ids,
            'batch_first_product_id' => $processed_product_ids !== [] ? (int) $processed_product_ids[0] : 0,
            'batch_last_product_id' => $processed_product_ids !== [] ? (int) $processed_product_ids[count($processed_product_ids) - 1] : 0,
            'done' => false,
        ];
    }

    private function log_listing_selection_qa_snapshot(int $product_id, array $result): void
    {
        $diagnostics = $this->mapper->get_listing_image_diagnostics($product_id);

        $this->logger->info('Listing source selection QA snapshot.', [
            'product_id' => $product_id,
            'selected_source_image_id' => (int) ($result['selected_source_image_id'] ?? ($diagnostics['selected_source_image_id'] ?? 0)),
            'selected_source_aspect_ratio' => round((float) ($result['selected_source_aspect_ratio'] ?? ($diagnostics['selected_source_aspect_ratio'] ?? 0.0)), 6),
            'square_fill_ratio' => round((float) ($result['selected_source_square_fill_ratio'] ?? 0.0), 6),
            'final_fit_mode' => (string) ($diagnostics['listing_attachment_final_fit_mode'] ?? ''),
            'used_crop' => !empty($diagnostics['listing_attachment_used_crop']),
            'fill_ratio' => round((float) ($diagnostics['listing_attachment_fill_ratio'] ?? 0.0), 6),
            'selection_reason' => (string) ($result['selected_source_selection_reason'] ?? ($diagnostics['selected_source_selection_reason'] ?? '')),
        ]);
    }

    private function block_heavy_operation(string $operation): bool
    {
        if (!Plugin::is_safe_mode_enabled()) {
            return false;
        }

        $this->logger->warning('Safe mode enabled: blocked heavy admin operation.', [
            'operation' => $operation,
        ]);

        $this->store_admin_notice(
            'error',
            __('Tryb awaryjny jest aktywny. Operacje importu i diagnostyki zostały tymczasowo wyłączone dla stabilności.', 'allegro-woo-importer')
        );

        wp_safe_redirect(add_query_arg(['page' => 'awi-settings'], admin_url('admin.php')));
        exit;
    }

    private function consume_admin_notice(): void
    {
        $key = 'awi_admin_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice)) {
            return;
        }

        delete_transient($key);

        $type = (($notice['type'] ?? '') === 'success') ? 'updated' : 'error';
        $message = isset($notice['message']) ? (string) $notice['message'] : '';
        if ($message === '') {
            return;
        }

        add_settings_error('awi_messages', 'awi_runtime_notice', $message, $type);
    }

    private function store_admin_notice(string $type, string $message): void
    {
        set_transient(
            'awi_admin_notice_' . get_current_user_id(),
            [
                'type' => $type,
                'message' => $message,
            ],
            5 * MINUTE_IN_SECONDS
        );
    }
}
