<?php

namespace WEI_FR\Services;

use WEI_FR\Adapters\EbayAdapter;
use WEI_FR\Plugin;
use WEI_FR\Repositories\CategoryMappingRepository;

class AutoNewProductPublisher
{
    public const HOOK = 'wei_fr_ebay_run_new_product_publish';
    public const LOCK_OPTION = 'wei_fr_ebay_new_product_publish_lock';
    public const LAST_RUN_OPTION = 'wei_fr_ebay_new_product_publish_last_run';
    public const SETTINGS_PREFIX = 'auto_new_product_publish_';
    public const MARKETPLACE_ID = 'EBAY_FR';

    private const REPORT_DIR = 'wei-ebay-integration-fr';
    private const LAST_RUN_FILE = 'fr-new-product-publish-last-run.json';
    private const ACTIONS_FILE = 'fr-new-product-publish-actions.csv';
    private const ERRORS_FILE = 'fr-new-product-publish-errors.csv';

    private const CSV_COLUMNS = [
        'timestamp','run_id','marketplace','dry_run','product_id','title','sku','ebay_sku','woo_category_id','woo_category_path','mapped_ebay_category_id','shipping_group','fulfillment_policy_id','payment_policy_id','return_policy_id','merchant_location_key','language_content_status','required_aspects_status','price_status','stock_status','image_status','readiness_status','action','result','listing_id','offer_id','listing_url','error_message','skip_reason',
    ];

    public function __construct(private EbayAdapter $adapter, private CategoryMappingRepository $categoryRepo, private Logger $logger)
    {
    }

    public function hooks(): void
    {
        add_action(self::HOOK, [$this, 'run_scheduled']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action('init', [$this, 'ensure_scheduled']);
    }

    public function cron_schedules(array $schedules): array
    {
        $schedules['wei_new_product_hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => 'WEI FR New Product Publisher Hourly'];
        $schedules['wei_new_product_twicedaily'] = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => 'WEI FR New Product Publisher Twice Daily'];
        $schedules['wei_new_product_daily'] = ['interval' => DAY_IN_SECONDS, 'display' => 'WEI FR New Product Publisher Daily'];
        return $schedules;
    }

    public function ensure_scheduled(): void
    {
        $settings = $this->settings();
        $next = wp_next_scheduled(self::HOOK);
        if (empty($settings['enabled'])) {
            if ($next) {
                wp_clear_scheduled_hook(self::HOOK);
            }
            return;
        }
        if (!$next) {
            wp_schedule_event(time() + 300, $this->wp_cron_recurrence((string) $settings['frequency']), self::HOOK);
        }
    }

    public function run_scheduled(): array
    {
        $settings = $this->settings();
        if (empty($settings['enabled'])) {
            return ['status' => 'disabled', 'marketplace' => self::MARKETPLACE_ID];
        }
        return $this->run(false, []);
    }

    public function run_manual(bool $dryRun = true, array $overrides = []): array
    {
        return $this->run($dryRun, $overrides + ['manual' => true]);
    }

    public function status(): array
    {
        $settings = $this->settings();
        $last = get_option(self::LAST_RUN_OPTION, []);
        $last = is_array($last) ? $last : [];
        return [
            'enabled' => !empty($settings['enabled']),
            'dry_run' => !empty($settings['dry_run']),
            'last_run' => (string) ($last['finished_at'] ?? $last['started_at'] ?? ''),
            'next_run' => wp_next_scheduled(self::HOOK) ? gmdate('Y-m-d H:i:s', (int) wp_next_scheduled(self::HOOK)) : '',
            'queued_candidates_count' => $this->count_candidates(),
            'published_this_run' => (int) ($last['published_this_run'] ?? 0),
            'skipped_this_run' => (int) ($last['skipped_this_run'] ?? 0),
            'blocked_this_run' => (int) ($last['blocked_this_run'] ?? 0),
            'errors_this_run' => (int) ($last['errors_this_run'] ?? 0),
            'last_summary' => $last,
            'reports' => $this->report_urls(),
            'hook' => self::HOOK,
        ];
    }

    public function settings(): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        $s = is_array($s) ? $s : [];
        $defaults = [
            'enabled' => 0,
            'dry_run' => 1,
            'batch_size' => 20,
            'frequency' => 'hourly',
            'max_publishes_per_run' => 1,
            'publish_only_if_ready' => 1,
            'newer_than_date' => '',
            'include_category_ids' => '',
            'exclude_category_ids' => '',
            'require_manual_approval' => 1,
            'auto_generate_language_content' => 0,
            'stop_on_first_error' => 0,
            'delay_between_publishes' => 0,
            'allowed_statuses' => 'publish',
        ];
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = $s[self::SETTINGS_PREFIX . $key] ?? $default;
        }
        $out['batch_size'] = max(1, min(100, (int) $out['batch_size']));
        $out['max_publishes_per_run'] = max(1, min(50, (int) $out['max_publishes_per_run']));
        $out['delay_between_publishes'] = max(0, min(60, (int) $out['delay_between_publishes']));
        $out['frequency'] = in_array((string) $out['frequency'], ['hourly', 'twicedaily', 'daily'], true) ? (string) $out['frequency'] : 'hourly';
        foreach (['enabled','dry_run','publish_only_if_ready','require_manual_approval','auto_generate_language_content','stop_on_first_error'] as $flag) {
            $out[$flag] = !empty($out[$flag]) ? 1 : 0;
        }
        return $out;
    }

    public function save_settings(array $posted): array
    {
        $s = get_option(Plugin::OPTION_KEY, []);
        $s = is_array($s) ? $s : [];
        $settings = $this->settings();
        foreach (array_keys($settings) as $key) {
            $full = self::SETTINGS_PREFIX . $key;
            if (in_array($key, ['enabled','dry_run','publish_only_if_ready','require_manual_approval','auto_generate_language_content','stop_on_first_error'], true)) {
                $s[$full] = !empty($posted[$full]) ? 1 : 0;
            } else {
                $s[$full] = sanitize_text_field((string) ($posted[$full] ?? ''));
            }
        }
        update_option(Plugin::OPTION_KEY, $s, false);
        wp_clear_scheduled_hook(self::HOOK);
        $this->ensure_scheduled();
        return $this->settings();
    }

    private function run(bool $forceDryRun, array $overrides): array
    {
        if (!$this->acquire_lock()) {
            return ['status' => 'locked', 'marketplace' => self::MARKETPLACE_ID];
        }
        $runId = gmdate('YmdHis') . '-' . wp_generate_password(6, false, false);
        $settings = array_merge($this->settings(), $overrides);
        $dryRun = $forceDryRun || !empty($settings['dry_run']);
        $summary = [
            'run_id' => $runId,
            'marketplace' => self::MARKETPLACE_ID,
            'dry_run' => $dryRun,
            'started_at' => gmdate('Y-m-d H:i:s'),
            'published_this_run' => 0,
            'skipped_this_run' => 0,
            'blocked_this_run' => 0,
            'errors_this_run' => 0,
            'candidates' => 0,
            'actions' => [],
        ];
        try {
            foreach ($this->candidate_ids((int) $settings['batch_size'], $settings) as $productId) {
                $summary['candidates']++;
                $row = $this->process_product($productId, $settings, $dryRun, $runId);
                $summary['actions'][] = $row;
                $this->append_csv(self::ACTIONS_FILE, $row);
                if ($row['result'] === 'published') {
                    $summary['published_this_run']++;
                    if (!$dryRun && (int) $settings['delay_between_publishes'] > 0) {
                        sleep((int) $settings['delay_between_publishes']);
                    }
                } elseif ($row['result'] === 'error') {
                    $summary['errors_this_run']++;
                    $this->append_csv(self::ERRORS_FILE, $row);
                    if (!empty($settings['stop_on_first_error'])) {
                        break;
                    }
                } elseif ($row['result'] === 'blocked') {
                    $summary['blocked_this_run']++;
                } else {
                    $summary['skipped_this_run']++;
                }
                if (!$dryRun && (int) $settings['max_publishes_per_run'] > 0 && $summary['published_this_run'] >= (int) $settings['max_publishes_per_run']) {
                    break;
                }
            }
            $summary['finished_at'] = gmdate('Y-m-d H:i:s');
            $summary['status'] = $summary['errors_this_run'] > 0 ? 'completed_with_errors' : 'completed';
            update_option(self::LAST_RUN_OPTION, $summary, false);
            $this->write_json(self::LAST_RUN_FILE, $summary);
            $this->logger->info('Auto New Product Publisher run completed', $summary);
            return $summary;
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private function process_product(int $productId, array $settings, bool $dryRun, string $runId): array
    {
        $product = wc_get_product($productId);
        $row = $this->row_from_preflight($productId, $product, [], $dryRun, $runId);
        if ($this->has_listing_meta($productId)) {
            $row['action'] = 'skip';
            $row['result'] = 'skipped';
            $row['skip_reason'] = 'existing_marketplace_listing_meta';
            return $row;
        }
        if (!empty($settings['require_manual_approval']) && (string) get_post_meta($productId, '_wei_fr_ebay_auto_publish_approved', true) !== '1') {
            $row['action'] = 'skip';
            $row['result'] = 'blocked';
            $row['skip_reason'] = 'manual_approval_required';
            return $row;
        }
        $preflight = $this->adapter->preflight_product($productId, null, true, false, [
            'suppress_side_effects' => $dryRun || empty($settings['auto_generate_language_content']),
            'auto_generate_french_content_preflight' => !empty($settings['auto_generate_language_content']) && !$dryRun ? 1 : 0,
            'regenerate_french_content_on_hash_change' => !empty($settings['auto_generate_language_content']) && !$dryRun ? 1 : 0,
        ]);
        $row = $this->row_from_preflight($productId, $product, $preflight, $dryRun, $runId);
        if (!$preflight['ready']) {
            $row['action'] = 'skip';
            $row['result'] = 'blocked';
            $row['skip_reason'] = (string) ($preflight['status'] ?? 'not_ready');
            return $row;
        }
        if ($dryRun) {
            $row['action'] = 'dry_run_publish';
            $row['result'] = 'ready_dry_run';
            return $row;
        }
        $result = $this->adapter->export_product($productId, null, true);
        if (($result['result'] ?? '') === 'success') {
            $listingId = (string) ($result['listing_id'] ?? '');
            $offerId = (string) ($result['offer_id'] ?? '');
            $inventoryId = (string) ($result['inventory_id'] ?? $row['ebay_sku']);
            $url = $this->listing_url($listingId);
            update_post_meta($productId, '_wei_fr_ebay_listing_id', $listingId);
            update_post_meta($productId, '_wei_fr_ebay_offer_id', $offerId);
            update_post_meta($productId, '_wei_fr_ebay_inventory_item_id', $inventoryId);
            update_post_meta($productId, '_wei_fr_ebay_listing_url', $url);
            update_post_meta($productId, '_wei_fr_ebay_marketplace', self::MARKETPLACE_ID);
            update_post_meta($productId, '_wei_fr_ebay_published_at', gmdate('Y-m-d H:i:s'));
            $row['action'] = 'publish';
            $row['result'] = 'published';
            $row['listing_id'] = $listingId;
            $row['offer_id'] = $offerId;
            $row['listing_url'] = $url;
            return $row;
        }
        $row['action'] = 'publish';
        $row['result'] = (($result['result'] ?? '') === 'skipped') ? 'skipped' : 'error';
        $row['error_message'] = (string) ($result['message'] ?? $result['error'] ?? 'publish_failed');
        $row['skip_reason'] = (string) ($result['status'] ?? $result['reason'] ?? '');
        return $row;
    }

    private function row_from_preflight(int $productId, $product, array $preflight, bool $dryRun, string $runId): array
    {
        $category = is_array($preflight['category'] ?? null) ? $preflight['category'] : [];
        $content = is_array($preflight['content'] ?? null) ? $preflight['content'] : [];
        $price = is_array($preflight['price_resolution'] ?? null) ? $preflight['price_resolution'] : [];
        return array_merge(array_fill_keys(self::CSV_COLUMNS, ''), [
            'timestamp' => gmdate('Y-m-d H:i:s'),
            'run_id' => $runId,
            'marketplace' => self::MARKETPLACE_ID,
            'dry_run' => $dryRun ? '1' : '0',
            'product_id' => (string) $productId,
            'title' => is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : get_the_title($productId),
            'sku' => is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
            'ebay_sku' => (string) ($preflight['sku_resolution']['sku'] ?? ''),
            'woo_category_id' => (string) ($category['woo_term_id'] ?? $category['mapping']['woo_term_id'] ?? ''),
            'woo_category_path' => (string) ($category['woo_category_path'] ?? $category['mapping']['woo_category_path'] ?? ''),
            'mapped_ebay_category_id' => (string) ($category['category_id'] ?? ''),
            'shipping_group' => (string) ($preflight['selected_shipping_group'] ?? ''),
            'fulfillment_policy_id' => (string) ($preflight['selected_fulfillment_policy_id'] ?? ''),
            'payment_policy_id' => (string) ($preflight['selected_payment_policy_id'] ?? ''),
            'return_policy_id' => (string) ($preflight['selected_return_policy_id'] ?? ''),
            'merchant_location_key' => (string) ($preflight['merchant_location_key'] ?? ''),
            'language_content_status' => empty($content['title']) || empty($content['description']) ? 'missing' : (string) ($content['source'] ?? 'ready'),
            'required_aspects_status' => empty($preflight['missing_aspects']) ? 'ready' : 'missing',
            'price_status' => !empty($price['ready']) ? 'ready' : (string) ($price['error'] ?? 'invalid'),
            'stock_status' => empty($preflight['stock_block_reason']) ? 'ready' : (string) $preflight['stock_block_reason'],
            'image_status' => is_object($product) && method_exists($product, 'get_image_id') && $product->get_image_id() ? 'ready' : 'missing',
            'readiness_status' => (string) ($preflight['status'] ?? 'not_ready'),
            'action' => 'evaluate',
            'result' => !empty($preflight['ready']) ? 'ready' : 'blocked',
            'error_message' => !empty($preflight['ready']) ? '' : (string) ($preflight['message'] ?? ''),
        ]);
    }

    private function candidate_ids(int $limit, array $settings): array
    {
        $args = [
            'post_type' => 'product',
            'post_status' => $this->allowed_statuses((string) $settings['allowed_statuses']),
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $this->candidate_meta_query(),
            'tax_query' => $this->candidate_tax_query($settings),
        ];
        if (!empty($settings['newer_than_date'])) {
            $args['date_query'] = [['after' => (string) $settings['newer_than_date'], 'inclusive' => true]];
        }
        $q = new \WP_Query($args);
        return array_map('intval', (array) $q->posts);
    }

    public function count_candidates(): int
    {
        $settings = $this->settings();
        $args = [
            'post_type' => 'product',
            'post_status' => $this->allowed_statuses((string) $settings['allowed_statuses']),
            'fields' => 'ids',
            'posts_per_page' => 1,
            'meta_query' => $this->candidate_meta_query(),
            'tax_query' => $this->candidate_tax_query($settings),
        ];
        $q = new \WP_Query($args);
        return (int) $q->found_posts;
    }

    private function candidate_meta_query(): array
    {
        return ['relation' => 'AND',
            ['key' => '_wei_fr_ebay_listing_id', 'compare' => 'NOT EXISTS'],
            ['key' => '_wei_fr_ebay_offer_id', 'compare' => 'NOT EXISTS'],
            ['key' => '_wei_fr_ebay_inventory_item_id', 'compare' => 'NOT EXISTS'],
            ['key' => '_wei_fr_ebay_inventory_id', 'compare' => 'NOT EXISTS'],
            ['key' => '_wei_fr_ebay_listing_status', 'value' => ['sold','ended','archived','published'], 'compare' => 'NOT IN'],
            ['key' => '_wei_fr_ebay_excluded_from_ebay', 'value' => '1', 'compare' => '!='],
            ['key' => '_excluded_from_ebay', 'value' => '1', 'compare' => '!='],
        ];
    }

    private function candidate_tax_query(array $settings): array
    {
        $tax = [];
        $include = $this->parse_ids((string) ($settings['include_category_ids'] ?? ''));
        $exclude = $this->parse_ids((string) ($settings['exclude_category_ids'] ?? ''));
        if ($include) {
            $tax[] = ['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $include, 'operator' => 'IN'];
        }
        if ($exclude) {
            $tax[] = ['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $exclude, 'operator' => 'NOT IN'];
        }
        if (count($tax) > 1) {
            $tax['relation'] = 'AND';
        }
        return $tax;
    }

    private function has_listing_meta(int $productId): bool
    {
        foreach (['_wei_fr_ebay_listing_id','_wei_fr_ebay_offer_id','_wei_fr_ebay_inventory_item_id','_wei_fr_ebay_inventory_id','_wei_fr_ebay_item_id'] as $key) {
            if (trim((string) get_post_meta($productId, $key, true)) !== '') {
                return true;
            }
        }
        $status = strtolower((string) get_post_meta($productId, '_wei_fr_ebay_listing_status', true));
        return in_array($status, ['sold','ended','archived','published'], true);
    }

    private function report_dir(): string
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . self::REPORT_DIR;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir;
    }

    private function report_urls(): array
    {
        $upload = wp_upload_dir();
        $base = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . self::REPORT_DIR . '/';
        return ['last_run' => $base . self::LAST_RUN_FILE, 'actions' => $base . self::ACTIONS_FILE, 'errors' => $base . self::ERRORS_FILE];
    }

    private function append_csv(string $file, array $row): void
    {
        $path = trailingslashit($this->report_dir()) . $file;
        $exists = file_exists($path);
        $fh = fopen($path, 'ab');
        if (!$fh) {
            return;
        }
        if (!$exists) {
            fputcsv($fh, self::CSV_COLUMNS);
        }
        fputcsv($fh, array_map(static fn($key) => (string) ($row[$key] ?? ''), self::CSV_COLUMNS));
        fclose($fh);
    }

    private function write_json(string $file, array $data): void
    {
        file_put_contents(trailingslashit($this->report_dir()) . $file, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function acquire_lock(): bool
    {
        $lock = (int) get_option(self::LOCK_OPTION, 0);
        if ($lock > time() - 1800) {
            return false;
        }
        update_option(self::LOCK_OPTION, time(), false);
        return true;
    }

    private function wp_cron_recurrence(string $frequency): string
    {
        return match ($frequency) {
            'daily' => 'wei_new_product_daily',
            'twicedaily' => 'wei_new_product_twicedaily',
            default => 'wei_new_product_hourly',
        };
    }

    private function allowed_statuses(string $raw): array
    {
        $statuses = array_values(array_intersect(array_map('trim', explode(',', $raw)), ['publish','draft','private']));
        return $statuses ?: ['publish'];
    }

    private function parse_ids(string $raw): array
    {
        return array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $raw) ?: [])));
    }

    private function listing_url(string $listingId): string
    {
        return $listingId !== '' ? 'https://www.ebay.fr/itm/' . rawurlencode($listingId) : '';
    }
}
