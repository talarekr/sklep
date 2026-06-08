<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\WooToOvokoCrmOnlyImportService;

require_once dirname(__DIR__) . '/src/Services/WooToOvokoCreatePartPreviewService.php';
require_once dirname(__DIR__) . '/src/Services/WooToOvokoCrmOnlyImportService.php';

$GLOBALS['gpswiss_test_posts'] = [];
$GLOBALS['gpswiss_test_meta'] = [];
$GLOBALS['gpswiss_test_terms'] = [];
$GLOBALS['gpswiss_test_term_meta'] = [];
$GLOBALS['gpswiss_test_attachments'] = [];
$GLOBALS['gpswiss_test_products'] = [];
$GLOBALS['gpswiss_test_writes'] = [];
$GLOBALS['gpswiss_test_options'] = [];
$GLOBALS['gpswiss_test_import_payloads'] = [];

class GPSwissCrmLiveTestProduct
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

function gpswiss_reset_live_test_state(): void
{
    $GLOBALS['gpswiss_test_posts'] = [];
    $GLOBALS['gpswiss_test_meta'] = [];
    $GLOBALS['gpswiss_test_terms'] = [];
    $GLOBALS['gpswiss_test_term_meta'] = [];
    $GLOBALS['gpswiss_test_attachments'] = [];
    $GLOBALS['gpswiss_test_products'] = [];
    $GLOBALS['gpswiss_test_writes'] = [];
    $GLOBALS['gpswiss_test_options'] = [];
    $GLOBALS['gpswiss_test_import_payloads'] = [];
}

function gpswiss_add_post(int $id, string $postType = 'product', string $status = 'draft', string $title = 'Test product'): void
{
    $GLOBALS['gpswiss_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => $postType, 'post_status' => $status, 'post_title' => $title, 'post_content' => 'Woo/Gmail description/body'];
    if ($postType === 'product') { $GLOBALS['gpswiss_test_products'][$id] = new GPSwissCrmLiveTestProduct($id); }
}
function gpswiss_set_meta(int $id, string $key, mixed $value): void { $GLOBALS['gpswiss_test_meta'][$id][$key] = $value; }
function gpswiss_seed_valid_live_product(int $id = 60886): void
{
    gpswiss_add_post($id);
    gpswiss_set_meta($id, '_sku', 'GPS-GMAIL-60849');
    gpswiss_set_meta($id, '_price', '199.99');
    gpswiss_set_meta($id, '_regular_price', '199.99');
    gpswiss_set_meta($id, '_stock_status', 'instock');
    gpswiss_set_meta($id, '_stock', '1');
    gpswiss_set_meta($id, '_part_number', '5Q0131701AN');
    gpswiss_set_meta($id, '_manufacturer_code', '5Q0131701AN');
    gpswiss_set_meta($id, '_gps_storage_location', '2KNS');
    gpswiss_set_meta($id, '_ovoko_quality_id', '2');
    gpswiss_set_meta($id, '_thumbnail_id', '501');
    gpswiss_set_meta($id, '_product_image_gallery', '501,502');
    $GLOBALS['gpswiss_test_attachments'][501] = 'https://example.test/501.jpg';
    $GLOBALS['gpswiss_test_attachments'][502] = 'https://example.test/502.jpg';
    $GLOBALS['gpswiss_test_terms'][$id] = [(object) ['term_id' => 9, 'name' => 'Mapped category', 'slug' => 'mapped-category']];
    $GLOBALS['gpswiss_test_term_meta'][9]['_ovoko_category_id'] = '1407';
    $GLOBALS['gpswiss_test_options']['gpswiss_ovoko_default_crm_import_car_id'] = '494';
    $GLOBALS['gpswiss_test_options']['gpswiss_ovoko_default_crm_import_car_note'] = 'Placeholder car_id used for CRM-only import. Vehicle must be corrected manually in Ovoko.';
}
function gpswiss_confirmations(): array { return ['confirm_placeholder_car_id' => true, 'confirm_live_one_product' => true, 'confirm_no_price_non_public' => true]; }
function gpswiss_fake_success_importer(array $payload): array { $GLOBALS['gpswiss_test_import_payloads'][] = $payload; return ['ok' => true, 'http_code' => 200, 'status_code' => 'R200', 'part_id' => 'RRR-999', 'raw_body' => '{"status_code":"R200","part_id":"RRR-999"}']; }
function gpswiss_fake_fail_importer(array $payload): array { $GLOBALS['gpswiss_test_import_payloads'][] = $payload; return ['ok' => false, 'http_code' => 200, 'status_code' => 'R500', 'part_id' => '', 'raw_body' => '{"status_code":"R500"}']; }

function get_post($id): object|false { return $GLOBALS['gpswiss_test_posts'][(int) $id] ?? false; }
function get_post_type($id): string|false { $post = get_post((int) $id); return $post ? $post->post_type : false; }
function get_post_status($id): string|false { $post = get_post((int) $id); return $post ? $post->post_status : false; }
function get_the_title($id): string { $post = get_post((int) $id); return $post ? (string) $post->post_title : ''; }
function get_post_meta($id, string $key = '', bool $single = false): mixed { return $GLOBALS['gpswiss_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_test_meta'][$id][$key] = $value; $GLOBALS['gpswiss_test_writes'][] = ['update_post_meta', $id, $key, $value]; return true; }
function wc_get_product($id): mixed { return $GLOBALS['gpswiss_test_products'][(int) $id] ?? null; }
function get_woocommerce_currency(): string { return 'PLN'; }
function wp_get_post_terms(int $id, string $taxonomy): array { return $taxonomy === 'product_cat' ? ($GLOBALS['gpswiss_test_terms'][$id] ?? []) : []; }
function get_term_meta(int $termId, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_test_term_meta'][$termId][$key] ?? ''; }
function get_post_thumbnail_id(int $id): int { return (int) get_post_meta($id, '_thumbnail_id', true); }
function wp_get_attachment_url(int $id): string { return (string) ($GLOBALS['gpswiss_test_attachments'][$id] ?? ''); }
function wp_http_validate_url(string $url): bool { return str_starts_with($url, 'http://') || str_starts_with($url, 'https://'); }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['gpswiss_test_options'][$key] ?? $default; }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
function get_posts(array $args): array { return []; }

function gpswiss_assert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function gpswiss_run_live_test(string $name, callable $test): void { gpswiss_reset_live_test_state(); $test(); echo "PASS {$name}\n"; }

gpswiss_run_live_test('live action unavailable when preview ineligible', function (): void {
    gpswiss_seed_valid_live_product();
    gpswiss_set_meta(60886, '_thumbnail_id', '');
    gpswiss_set_meta(60886, '_product_image_gallery', '');
    $GLOBALS['gpswiss_test_attachments'] = [];
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, gpswiss_confirmations());
    gpswiss_assert($result['ok'] === false && ($result['error_code'] ?? '') === 'preview_live_safety_failed', 'Ineligible preview must block live import.');
    gpswiss_assert($GLOBALS['gpswiss_test_import_payloads'] === [], 'Blocked live import must not call importer.');
});

gpswiss_run_live_test('live action blocked without confirmations', function (): void {
    gpswiss_seed_valid_live_product();
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, []);
    gpswiss_assert($result['ok'] === false && ($result['error_code'] ?? '') === 'missing_required_confirmations', 'Missing confirmations must block live import.');
    gpswiss_assert($GLOBALS['gpswiss_test_import_payloads'] === [], 'Missing confirmations must not call importer.');
});

gpswiss_run_live_test('live payload omits price fields and includes photo/photos[]', function (): void {
    gpswiss_seed_valid_live_product();
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, gpswiss_confirmations());
    $payload = $GLOBALS['gpswiss_test_import_payloads'][0] ?? [];
    gpswiss_assert($result['ok'] === true, 'Valid product should import successfully.');
    foreach (['price', 'original_price', 'currency', 'original_currency'] as $key) { gpswiss_assert(!array_key_exists($key, $payload), "{$key} must be absent from payload."); }
    gpswiss_assert(($payload['photo'] ?? '') === 'https://example.test/501.jpg', 'photo must be first image URL.');
    gpswiss_assert(($payload['photos[]'] ?? []) === ['https://example.test/501.jpg', 'https://example.test/502.jpg'], 'photos[] must include all image URLs.');
    gpswiss_assert(($payload['category_id'] ?? null) === 1407 && ($payload['car_id'] ?? null) === 494 && ($payload['quality'] ?? null) === 2 && ($payload['status'] ?? null) === 0, 'Required CRM fields missing.');
});

gpswiss_run_live_test('placeholder car confirmation required when placeholder car_id used', function (): void {
    gpswiss_seed_valid_live_product();
    $confirmations = ['confirm_live_one_product' => true, 'confirm_no_price_non_public' => true];
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, $confirmations);
    gpswiss_assert($result['ok'] === false && ($result['error_code'] ?? '') === 'placeholder_car_id_confirmation_required', 'Placeholder confirmation must be required.');
});

gpswiss_run_live_test('existing _ovoko_part_id blocks live import', function (): void {
    gpswiss_seed_valid_live_product();
    gpswiss_set_meta(60886, '_ovoko_part_id', '12345');
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, gpswiss_confirmations());
    gpswiss_assert($result['ok'] === false && ($result['error_code'] ?? '') === 'existing_part_id_meta_blocks_import', 'Existing part ID meta must block import.');
});

gpswiss_run_live_test('successful R200 response stores part_id and import meta', function (): void {
    gpswiss_seed_valid_live_product();
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, gpswiss_confirmations());
    gpswiss_assert($result['ok'] === true && $result['part_id'] === 'RRR-999', 'Successful response should expose returned part_id.');
    gpswiss_assert(get_post_meta(60886, '_ovoko_part_id', true) === 'RRR-999', '_ovoko_part_id must be stored.');
    gpswiss_assert(get_post_meta(60886, '_gps_ovoko_crm_only_import_strategy', true) === 'crm_only_non_public_initial_import', 'Import strategy meta missing.');
    gpswiss_assert(get_post_meta(60886, '_gps_ovoko_crm_only_import_price_omitted', true) === '1', 'Price omitted meta missing.');
});

gpswiss_run_live_test('failed response does not store part_id and stores error', function (): void {
    gpswiss_seed_valid_live_product();
    $result = (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_fail_importer'))->create(60886, gpswiss_confirmations());
    gpswiss_assert($result['ok'] === false, 'Failed API response must fail result.');
    gpswiss_assert(get_post_meta(60886, '_ovoko_part_id', true) === '', 'Failed API response must not store part_id.');
    gpswiss_assert(get_post_meta(60886, '_gps_ovoko_crm_only_import_last_error', true) !== '', 'Failed API response must store last error.');
});

gpswiss_run_live_test('no Woo price category stock mutation', function (): void {
    gpswiss_seed_valid_live_product();
    (new WooToOvokoCrmOnlyImportService([], 'gpswiss_fake_success_importer'))->create(60886, gpswiss_confirmations());
    $mutatedForbiddenMeta = array_filter($GLOBALS['gpswiss_test_writes'], static fn(array $write): bool => in_array($write[2] ?? '', ['_price', '_regular_price', '_sale_price', '_stock', '_stock_status', '_product_image_gallery', '_thumbnail_id'], true));
    gpswiss_assert($mutatedForbiddenMeta === [], 'Live import must not mutate Woo price/category/stock/image meta.');
});
