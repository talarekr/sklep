<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\WooToOvokoCrmOnlyBatchImportService;
use GPSwiss\Ovoko\Services\WooToOvokoCrmOnlyImportService;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';
require_once dirname(__DIR__) . '/src/Services/WooToOvokoCreatePartPreviewService.php';
require_once dirname(__DIR__) . '/src/Services/WooToOvokoCrmOnlyImportService.php';
require_once dirname(__DIR__) . '/src/Services/WooToOvokoCrmOnlyBatchImportService.php';

$GLOBALS['gpswiss_batch_test_posts'] = [];
$GLOBALS['gpswiss_batch_test_meta'] = [];
$GLOBALS['gpswiss_batch_test_terms'] = [];
$GLOBALS['gpswiss_batch_test_term_meta'] = [];
$GLOBALS['gpswiss_batch_test_attachments'] = [];
$GLOBALS['gpswiss_batch_test_products'] = [];
$GLOBALS['gpswiss_batch_test_options'] = [];
$GLOBALS['gpswiss_batch_test_import_payloads'] = [];
$GLOBALS['gpswiss_batch_test_import_responses'] = [];

class GPSwissBatchTestProduct
{
    public function __construct(private int $id) {}
    public function get_sku(): string { return (string) get_post_meta($this->id, '_sku', true); }
    public function get_price(): string { return (string) get_post_meta($this->id, '_price', true); }
    public function get_regular_price(): string { return (string) get_post_meta($this->id, '_regular_price', true); }
    public function get_sale_price(): string { return (string) get_post_meta($this->id, '_sale_price', true); }
    public function get_stock_status(): string { return (string) get_post_meta($this->id, '_stock_status', true); }
    public function get_stock_quantity(): ?int { $raw = get_post_meta($this->id, '_stock', true); return $raw === '' ? null : (int) $raw; }
    public function get_image_id(): int { return (int) get_post_meta($this->id, '_thumbnail_id', true); }
    public function get_gallery_image_ids(): array { $raw = trim((string) get_post_meta($this->id, '_product_image_gallery', true)); return $raw === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $raw)))); }
    public function get_description(): string { $post = get_post($this->id); return (string) ($post->post_content ?? ''); }
}

class GPSwissBatchTestWpdb
{
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    private array $lastParams = [];

    public function esc_like(string $text): string { return $text; }
    public function prepare(string $sql, array $params): string { $this->lastParams = $params; return $sql; }
    public function get_col(string $sql): array
    {
        $min = (int) ($this->lastParams[0] ?? 0);
        $max = PHP_INT_MAX;
        $limit = (int) end($this->lastParams);
        foreach ($this->lastParams as $param) {
            if (is_int($param) && $param > $min && $param !== $limit) {
                $max = min($max, $param);
            }
        }
        $ids = $this->matching_ids($min, $max);
        return array_slice($ids, 0, $limit > 0 ? $limit : 50);
    }

    public function get_var(string $sql): int
    {
        if (str_starts_with($sql, 'SELECT p.ID')) {
            $ids = $this->get_col($sql);
            return (int) ($ids[0] ?? 0);
        }

        $withOvokoMetaOnly = str_contains($sql, 'opm.post_id IS NOT NULL OR npm.post_id IS NOT NULL');
        $ids = $this->matching_ids(0, PHP_INT_MAX);
        if ($withOvokoMetaOnly) {
            $ids = array_values(array_filter($ids, static function (int $id): bool {
                return trim((string) get_post_meta($id, '_ovoko_part_id', true)) !== ''
                    || trim((string) get_post_meta($id, 'ovoko_part_id', true)) !== '';
            }));
        }
        return count($ids);
    }

    private function matching_ids(int $min, int $max): array
    {
        $ids = [];
        foreach ($GLOBALS['gpswiss_batch_test_posts'] as $id => $post) {
            if ((string) $post->post_type !== 'product' || (string) $post->post_status !== 'draft') {
                continue;
            }
            if ($id <= $min || $id > $max) {
                continue;
            }
            if (!str_starts_with((string) get_post_meta((int) $id, '_sku', true), 'GPS-GMAIL-')) {
                continue;
            }
            $ids[] = (int) $id;
        }
        sort($ids);
        return $ids;
    }
}

$GLOBALS['wpdb'] = new GPSwissBatchTestWpdb();

function gpswiss_batch_reset(): void
{
    $GLOBALS['gpswiss_batch_test_posts'] = [];
    $GLOBALS['gpswiss_batch_test_meta'] = [];
    $GLOBALS['gpswiss_batch_test_terms'] = [];
    $GLOBALS['gpswiss_batch_test_term_meta'] = [];
    $GLOBALS['gpswiss_batch_test_attachments'] = [];
    $GLOBALS['gpswiss_batch_test_products'] = [];
    $GLOBALS['gpswiss_batch_test_options'] = [];
    $GLOBALS['gpswiss_batch_test_import_payloads'] = [];
    $GLOBALS['gpswiss_batch_test_import_responses'] = [];
}

function gpswiss_batch_seed_product(int $id, string $sku): void
{
    $GLOBALS['gpswiss_batch_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Product ' . $id, 'post_content' => 'Woo/Gmail description/body'];
    $GLOBALS['gpswiss_batch_test_products'][$id] = new GPSwissBatchTestProduct($id);
    gpswiss_batch_set_meta($id, '_sku', $sku);
    gpswiss_batch_set_meta($id, '_price', '199.99');
    gpswiss_batch_set_meta($id, '_regular_price', '199.99');
    gpswiss_batch_set_meta($id, '_stock_status', 'instock');
    gpswiss_batch_set_meta($id, '_stock', '1');
    gpswiss_batch_set_meta($id, '_part_number', '5Q0131701AN');
    gpswiss_batch_set_meta($id, '_manufacturer_code', '5Q0131701AN');
    gpswiss_batch_set_meta($id, '_gps_storage_location', '2KNS');
    gpswiss_batch_set_meta($id, '_ovoko_quality_id', '2');
    gpswiss_batch_set_meta($id, '_thumbnail_id', (string) ($id + 1000));
    gpswiss_batch_set_meta($id, '_product_image_gallery', (string) ($id + 1000));
    $GLOBALS['gpswiss_batch_test_attachments'][$id + 1000] = 'https://example.test/' . $id . '.jpg';
    $GLOBALS['gpswiss_batch_test_terms'][$id] = [(object) ['term_id' => 9, 'name' => 'Mapped category', 'slug' => 'mapped-category']];
    $GLOBALS['gpswiss_batch_test_term_meta'][9]['_ovoko_category_id'] = '1407';
    $GLOBALS['gpswiss_batch_test_options']['gpswiss_ovoko_default_crm_import_car_id'] = '494';
}

function gpswiss_batch_set_meta(int $id, string $key, mixed $value): void { $GLOBALS['gpswiss_batch_test_meta'][$id][$key] = $value; }
function gpswiss_batch_importer(array $payload): array { $GLOBALS['gpswiss_batch_test_import_payloads'][] = $payload; if ($GLOBALS['gpswiss_batch_test_import_responses'] !== []) { return array_shift($GLOBALS['gpswiss_batch_test_import_responses']); } return ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => 'RRR-999', 'raw_body' => '{"status_code":"R200","part_id":"RRR-999"}']; }
function gpswiss_batch_service(): WooToOvokoCrmOnlyBatchImportService { return new WooToOvokoCrmOnlyBatchImportService([], null, new WooToOvokoCrmOnlyImportService([], 'gpswiss_batch_importer')); }
function gpswiss_batch_assert(bool $condition, string $message, mixed $context = null): void { if (!$condition) { throw new RuntimeException($message . ($context === null ? '' : ' ' . json_encode($context))); } }
function gpswiss_batch_run(string $name, callable $test): void { gpswiss_batch_reset(); $test(); echo "PASS {$name}\n"; }

function get_post($id): object|false { return $GLOBALS['gpswiss_batch_test_posts'][(int) $id] ?? false; }
function get_post_type($id): string|false { $post = get_post((int) $id); return $post ? $post->post_type : false; }
function get_post_status($id): string|false { $post = get_post((int) $id); return $post ? $post->post_status : false; }
function get_the_title($id): string { $post = get_post((int) $id); return $post ? (string) $post->post_title : ''; }
function get_post_meta($id, string $key = '', bool $single = false): mixed { return $GLOBALS['gpswiss_batch_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_batch_test_meta'][$id][$key] = $value; return true; }
function wc_get_product($id): mixed { return $GLOBALS['gpswiss_batch_test_products'][(int) $id] ?? null; }
function get_woocommerce_currency(): string { return 'PLN'; }
function wp_get_post_terms(int $id, string $taxonomy): array { return $taxonomy === 'product_cat' ? ($GLOBALS['gpswiss_batch_test_terms'][$id] ?? []) : []; }
function get_term_meta(int $termId, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_batch_test_term_meta'][$termId][$key] ?? ''; }
function get_post_thumbnail_id(int $id): int { return (int) get_post_meta($id, '_thumbnail_id', true); }
function wp_get_attachment_url(int $id): string { return (string) ($GLOBALS['gpswiss_batch_test_attachments'][$id] ?? ''); }
function get_attached_file(int $id): string { return '/tmp/gpswiss-batch-test-' . $id . '.jpg'; }
function wp_http_validate_url(string $url): bool { return str_starts_with($url, 'http://') || str_starts_with($url, 'https://'); }
function is_wp_error(mixed $thing): bool { return false; }
function wp_remote_head(string $url, array $args = []): array { return ['response' => ['code' => 200], 'headers' => ['content-type' => 'image/jpeg', 'content-length' => '12345']]; }
function wp_remote_get(string $url, array $args = []): array { return wp_remote_head($url, $args); }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['gpswiss_batch_test_options'][$key] ?? $default; }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
function get_posts(array $args): array
{
    $ids = [];
    foreach ($GLOBALS['gpswiss_batch_test_posts'] as $id => $post) {
        if (($args['post_type'] ?? '') !== '' && (string) $post->post_type !== (string) $args['post_type']) {
            continue;
        }
        if (($args['post_status'] ?? 'any') !== 'any' && (string) $post->post_status !== (string) $args['post_status']) {
            continue;
        }
        if (in_array((int) $id, array_map('intval', (array) ($args['exclude'] ?? [])), true)) {
            continue;
        }
        $matchesMeta = true;
        foreach ((array) ($args['meta_query'] ?? []) as $clause) {
            if (!is_array($clause) || !isset($clause['key'])) {
                continue;
            }
            $value = get_post_meta((int) $id, (string) $clause['key'], true);
            $compare = (string) ($clause['compare'] ?? '=');
            if ($compare === 'EXISTS') {
                $matchesMeta = $matchesMeta && trim((string) $value) !== '';
            } elseif ($compare === '=') {
                $matchesMeta = $matchesMeta && (string) $value === (string) ($clause['value'] ?? '');
            }
        }
        if ($matchesMeta) {
            $ids[] = (int) $id;
        }
    }
    sort($ids);
    $limit = (int) ($args['posts_per_page'] ?? 0);
    return $limit > 0 ? array_slice($ids, 0, $limit) : $ids;
}
function current_time(string $type): string { return $type === 'mysql' ? '2026-06-10 12:00:00' : gmdate('c'); }
function sanitize_text_field(string $value): string { return trim($value); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? ''; }

gpswiss_batch_run('find_candidate skips recoverable retry block and returns next eligible Gmail draft', function (): void {
    gpswiss_batch_seed_product(60886, 'GPS-GMAIL-60886');
    gpswiss_batch_seed_product(60887, 'GPS-GMAIL-60887');
    gpswiss_batch_set_meta(60886, '_gps_ovoko_crm_only_import_last_error', '{"code":"recoverable_part_id_blocks_retry","message":"Last Ovoko import response already contains part_id 11055."}');
    gpswiss_batch_set_meta(60886, '_gps_ovoko_crm_only_import_last_response_raw', '{"status_code":"R202","msg":"OK [WARNING] Part won\'t be shown in shop unless you fill out price field","part_id":"11055"}');

    $result = gpswiss_batch_service()->run_one_batch(['mode' => 'find_candidate', 'batch_size' => 1]);

    gpswiss_batch_assert(($result['first_candidate_product_id'] ?? 0) === 60887, 'Recoverable product must not be first candidate.', $result);
    gpswiss_batch_assert(($result['skipped_counts']['skipped_recoverable_retry_blocked'] ?? 0) === 1, 'Recoverable skipped count missing.', $result);
    gpswiss_batch_assert(($result['skipped_counts']['found_gps_gmail_drafts'] ?? 0) === 2, 'Draft count missing.', $result);
});

gpswiss_batch_run('batch skips recoverable retry block without importing and continues to next Gmail draft', function (): void {
    gpswiss_batch_seed_product(60886, 'GPS-GMAIL-60886');
    gpswiss_batch_seed_product(60887, 'GPS-GMAIL-60887');
    gpswiss_batch_set_meta(60886, '_gps_ovoko_crm_only_import_recoverable_part_id', '11055');
    gpswiss_batch_set_meta(60886, '_gps_ovoko_crm_only_import_last_response_raw', '{"status_code":"R202","part_id":"11055"}');

    $result = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 1]);

    gpswiss_batch_assert(($result['success_count'] ?? 0) === 1, 'Next eligible product should import successfully.', $result);
    gpswiss_batch_assert(($result['repair_needed_count'] ?? 0) === 1, 'Recoverable skip should increment repair_needed_count.', $result);
    gpswiss_batch_assert(($result['failed_count'] ?? 0) === 0, 'Recoverable skip must not be fatal failed.', $result);
    gpswiss_batch_assert(count($GLOBALS['gpswiss_batch_test_import_payloads']) === 1, 'Only next eligible product should call importer.', $GLOBALS['gpswiss_batch_test_import_payloads']);
    gpswiss_batch_assert(($result['items'][0]['product_id'] ?? 0) === 60886 && ($result['items'][0]['skip_reason'] ?? '') === 'skipped_recoverable_retry_blocked', 'Skipped item for 60886 missing.', $result['items']);
    gpswiss_batch_assert(($result['items'][1]['product_id'] ?? 0) === 60887 && !empty($result['items'][1]['ok']), 'Imported item for 60887 missing.', $result['items']);
});


gpswiss_batch_run('batch marks Ovoko photo file missing as repair-needed and continues', function (): void {
    gpswiss_batch_seed_product(62803, 'GPS-GMAIL-62803');
    gpswiss_batch_seed_product(62804, 'GPS-GMAIL-62804');
    gpswiss_batch_seed_product(62805, 'GPS-GMAIL-62805');
    $GLOBALS['gpswiss_batch_test_import_responses'] = [
        ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => 'RRR-A', 'raw_body' => '{"status_code":"R200","part_id":"RRR-A"}'],
        ['ok' => false, 'http_code' => 200, 'status_code' => 'R400', 'msg' => ['photo' => '[ERROR] file does not exist'], 'part_id' => '', 'raw_body' => '{"status_code":"R400","msg":{"photo":"[ERROR] file does not exist"}}'],
        ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => 'RRR-C', 'raw_body' => '{"status_code":"R200","part_id":"RRR-C"}'],
    ];

    $result = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 3, 'stop_on_first_error' => true]);

    gpswiss_batch_assert(($result['ok'] ?? false) === true, 'Photo file missing must not make the batch fatal.', $result);
    gpswiss_batch_assert(($result['success_count'] ?? 0) === 2, 'Products A and C should import successfully.', $result);
    gpswiss_batch_assert(($result['failed_count'] ?? 0) === 0, 'Photo file missing must not increment failed_count.', $result);
    gpswiss_batch_assert(($result['repair_needed_count'] ?? 0) === 1, 'Photo file missing should increment repair_needed_count.', $result);
    gpswiss_batch_assert(($result['photo_file_missing_count'] ?? 0) === 1, 'Photo file missing count missing.', $result);
    gpswiss_batch_assert(($result['skipped_photo_file_missing'] ?? 0) === 1, 'Skipped photo file missing count missing.', $result);
    gpswiss_batch_assert(($result['items'][1]['product_id'] ?? 0) === 62804, 'Product B item missing.', $result['items']);
    gpswiss_batch_assert(($result['items'][1]['status'] ?? '') === 'repair_needed', 'Product B should be repair_needed.', $result['items'][1]);
    gpswiss_batch_assert(($result['items'][1]['skip_reason'] ?? '') === 'ovoko_photo_file_missing', 'Product B skip reason should be ovoko_photo_file_missing.', $result['items'][1]);
    gpswiss_batch_assert(get_post_meta(62804, '_gps_ovoko_crm_only_import_repair_reason', true) === 'ovoko_photo_file_missing', 'Product B should persist photo repair reason.');

    $GLOBALS['gpswiss_batch_test_import_payloads'] = [];
    $GLOBALS['gpswiss_batch_test_import_responses'] = [];
    $second = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 1, 'cursor' => 62803]);
    gpswiss_batch_assert(($second['skipped_photo_file_missing'] ?? 0) === 1, 'Next run should skip product B as skipped_photo_file_missing.', $second);
    gpswiss_batch_assert(($second['items'][0]['product_id'] ?? 0) === 62804 && ($second['items'][0]['skip_reason'] ?? '') === 'ovoko_photo_file_missing', 'Next run should report B as photo repair skip.', $second['items']);
    gpswiss_batch_assert($GLOBALS['gpswiss_batch_test_import_payloads'] === [], 'Next run must not resend product B.', $GLOBALS['gpswiss_batch_test_import_payloads']);
});


gpswiss_batch_run('batch treats same-SKU linked Ovoko part preview block as item-level duplicate and continues', function (): void {
    gpswiss_batch_seed_product(63874, 'GPS-GMAIL-63874');
    gpswiss_batch_seed_product(63895, 'GPS-GMAIL-DUPLICATE');
    gpswiss_batch_seed_product(63896, 'GPS-GMAIL-DUPLICATE');
    gpswiss_batch_seed_product(63897, 'GPS-GMAIL-63897');
    $GLOBALS['gpswiss_batch_test_posts'][63896]->post_status = 'publish';
    gpswiss_batch_set_meta(63896, '_ovoko_part_id', '11683');
    $GLOBALS['gpswiss_batch_test_import_responses'] = [
        ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => '11678', 'raw_body' => '{"status_code":"R200","part_id":"11678"}'],
        ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => '11680', 'raw_body' => '{"status_code":"R200","part_id":"11680"}'],
    ];

    $result = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 3, 'stop_on_first_error' => true]);

    gpswiss_batch_assert(($result['ok'] ?? false) === true, 'Duplicate SKU block must not make the batch fatal.', $result);
    gpswiss_batch_assert(($result['success_count'] ?? 0) === 2, 'Products A and C should import successfully.', $result);
    gpswiss_batch_assert(($result['blocked_count'] ?? 0) === 1, 'Product B should increment blocked_count.', $result);
    gpswiss_batch_assert(($result['failed_count'] ?? 0) === 0, 'Duplicate SKU block must not increment failed_count.', $result);
    gpswiss_batch_assert(($result['duplicate_sku_ovoko_count'] ?? 0) === 1, 'Duplicate SKU Ovoko count missing.', $result);
    gpswiss_batch_assert(($result['skipped_duplicate_sku_ovoko'] ?? 0) === 1, 'Skipped duplicate SKU Ovoko count missing.', $result);
    gpswiss_batch_assert(($result['stop_reason'] ?? '') !== 'preview_ineligible_stop_on_first_error', 'Duplicate SKU must not stop on first error.', $result);
    gpswiss_batch_assert(count($result['errors'] ?? []) === 0, 'Duplicate SKU block must not be added to fatal errors.', $result['errors'] ?? []);
    gpswiss_batch_assert(count($GLOBALS['gpswiss_batch_test_import_payloads']) === 2, 'Only products A and C should call importer.', $GLOBALS['gpswiss_batch_test_import_payloads']);
    gpswiss_batch_assert(($result['items'][1]['product_id'] ?? 0) === 63895, 'Product B item missing.', $result['items']);
    gpswiss_batch_assert(($result['items'][1]['status'] ?? '') === 'blocked', 'Product B should be blocked.', $result['items'][1]);
    gpswiss_batch_assert(($result['items'][1]['skip_reason'] ?? '') === 'duplicate_sku_already_has_ovoko_part_id', 'Product B skip reason should be duplicate_sku_already_has_ovoko_part_id.', $result['items'][1]);
    gpswiss_batch_assert(in_array('another_product_same_sku_has_ovoko_part_id', (array) ($result['items'][1]['validation_codes'] ?? []), true), 'Product B validation code missing.', $result['items'][1]);
    gpswiss_batch_assert(get_post_meta(63895, '_gps_ovoko_crm_only_import_blocked_reason', true) === 'duplicate_sku_already_has_ovoko_part_id', 'Product B should persist duplicate blocked reason.');

    $GLOBALS['gpswiss_batch_test_import_payloads'] = [];
    $GLOBALS['gpswiss_batch_test_import_responses'] = [];
    $second = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 1, 'cursor' => 63874]);
    gpswiss_batch_assert(($second['blocked_count'] ?? 0) === 1, 'Next run should count product B as blocked duplicate.', $second);
    gpswiss_batch_assert(($second['skipped_duplicate_sku_ovoko'] ?? 0) === 1, 'Next run should skip product B as duplicate SKU blocked.', $second);
    gpswiss_batch_assert(($second['items'][0]['product_id'] ?? 0) === 63895 && ($second['items'][0]['skip_reason'] ?? '') === 'duplicate_sku_already_has_ovoko_part_id', 'Next run should report B as duplicate SKU skip.', $second['items']);
    gpswiss_batch_assert($GLOBALS['gpswiss_batch_test_import_payloads'] === [], 'Next run must not resend product B.', $GLOBALS['gpswiss_batch_test_import_payloads']);
});

gpswiss_batch_run('batch scans past already imported lookahead window to next eligible Gmail draft', function (): void {
    for ($id = 61000; $id < 61050; $id++) {
        gpswiss_batch_seed_product($id, 'GPS-GMAIL-' . $id);
        gpswiss_batch_set_meta($id, '_ovoko_part_id', 'RRR-' . $id);
    }
    gpswiss_batch_seed_product(61050, 'GPS-GMAIL-61050');

    $result = gpswiss_batch_service()->run_one_batch(['mode' => 'live', 'batch_size' => 1]);

    gpswiss_batch_assert(($result['success_count'] ?? 0) === 1, 'Runner must import product after already-imported first window.', $result);
    gpswiss_batch_assert(($result['stop_reason'] ?? '') !== 'no_eligible_products', 'Runner must not stop no_eligible_products while later unimported drafts exist.', $result);
    gpswiss_batch_assert(($result['already_imported_count'] ?? 0) === 50, 'First window imported products must be counted.', $result);
    gpswiss_batch_assert(($result['windows_scanned'] ?? 0) >= 2, 'Runner must scan beyond the first lookahead window.', $result);
    gpswiss_batch_assert(($result['scan_window_first_id'] ?? 0) === 61000 && ($result['scan_window_last_id'] ?? 0) === 61050, 'Scan window diagnostics should cover first and last checked IDs.', $result);
});
