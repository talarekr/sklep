<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\WooToOvokoCreatePartPreviewService;

require_once dirname(__DIR__) . '/src/Services/WooToOvokoCreatePartPreviewService.php';

$GLOBALS['gpswiss_test_posts'] = [];
$GLOBALS['gpswiss_test_meta'] = [];
$GLOBALS['gpswiss_test_terms'] = [];
$GLOBALS['gpswiss_test_term_meta'] = [];
$GLOBALS['gpswiss_test_attachments'] = [];
$GLOBALS['gpswiss_test_products'] = [];
$GLOBALS['gpswiss_test_writes'] = [];

class GPSwissPreviewTestProduct
{
    public function __construct(private int $id) {}
    public function get_sku(): string { return (string) get_post_meta($this->id, '_sku', true); }
    public function get_price(): string { return (string) get_post_meta($this->id, '_price', true); }
    public function get_regular_price(): string { return (string) get_post_meta($this->id, '_regular_price', true); }
    public function get_sale_price(): string { return (string) get_post_meta($this->id, '_sale_price', true); }
    public function get_stock_status(): string { return (string) get_post_meta($this->id, '_stock_status', true); }
    public function get_stock_quantity(): ?int { $raw = get_post_meta($this->id, '_stock', true); return $raw === '' ? null : (int) $raw; }
    public function get_image_id(): int { return (int) get_post_meta($this->id, '_thumbnail_id', true); }
    public function get_gallery_image_ids(): array
    {
        $raw = trim((string) get_post_meta($this->id, '_product_image_gallery', true));
        return $raw === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
    public function get_description(): string { $post = get_post($this->id); return (string) ($post->post_content ?? ''); }
}

function gpswiss_reset_preview_test_state(): void
{
    $GLOBALS['gpswiss_test_posts'] = [];
    $GLOBALS['gpswiss_test_meta'] = [];
    $GLOBALS['gpswiss_test_terms'] = [];
    $GLOBALS['gpswiss_test_term_meta'] = [];
    $GLOBALS['gpswiss_test_attachments'] = [];
    $GLOBALS['gpswiss_test_products'] = [];
    $GLOBALS['gpswiss_test_writes'] = [];
}

function gpswiss_add_post(int $id, string $postType = 'product', string $status = 'draft', string $title = 'Test product'): void
{
    $GLOBALS['gpswiss_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => $postType, 'post_status' => $status, 'post_title' => $title, 'post_content' => 'Description'];
    if ($postType === 'product') {
        $GLOBALS['gpswiss_test_products'][$id] = new GPSwissPreviewTestProduct($id);
    }
}

function gpswiss_set_meta(int $id, string $key, mixed $value): void
{
    $GLOBALS['gpswiss_test_meta'][$id][$key] = $value;
}

function gpswiss_seed_valid_product(int $id = 123): void
{
    gpswiss_add_post($id);
    gpswiss_set_meta($id, '_sku', 'SKU-123');
    gpswiss_set_meta($id, '_price', '199.99');
    gpswiss_set_meta($id, '_regular_price', '199.99');
    gpswiss_set_meta($id, '_stock_status', 'instock');
    gpswiss_set_meta($id, '_stock', '1');
    gpswiss_set_meta($id, '_part_number', 'ABC-123');
    gpswiss_set_meta($id, '_manufacturer_code', 'ABC-123');
    $GLOBALS['gpswiss_test_terms'][$id] = [(object) ['term_id' => 9, 'name' => 'Mapped category', 'slug' => 'mapped-category']];
    $GLOBALS['gpswiss_test_term_meta'][9]['_ovoko_category_id'] = '777';
    gpswiss_set_meta($id, '_thumbnail_id', '501');
    $GLOBALS['gpswiss_test_attachments'][501] = 'https://example.test/501.jpg';
}

function get_post($id): object|false { return $GLOBALS['gpswiss_test_posts'][(int) $id] ?? false; }
function get_post_type($id): string|false { $post = get_post((int) $id); return $post ? $post->post_type : false; }
function get_post_status($id): string|false { $post = get_post((int) $id); return $post ? $post->post_status : false; }
function get_the_title($id): string { $post = get_post((int) $id); return $post ? (string) $post->post_title : ''; }
function get_post_meta($id, string $key = '', bool $single = false): mixed { return $GLOBALS['gpswiss_test_meta'][(int) $id][$key] ?? ''; }
function wc_get_product($id): mixed { return $GLOBALS['gpswiss_test_products'][(int) $id] ?? null; }
function get_woocommerce_currency(): string { return 'PLN'; }
function wp_get_post_terms(int $id, string $taxonomy): array { return $taxonomy === 'product_cat' ? ($GLOBALS['gpswiss_test_terms'][$id] ?? []) : []; }
function get_term_meta(int $termId, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_test_term_meta'][$termId][$key] ?? ''; }
function get_post_thumbnail_id(int $id): int { return (int) get_post_meta($id, '_thumbnail_id', true); }
function wp_get_attachment_url(int $id): string { return (string) ($GLOBALS['gpswiss_test_attachments'][$id] ?? ''); }
function wp_http_validate_url(string $url): bool { return str_starts_with($url, 'http://') || str_starts_with($url, 'https://'); }
function get_posts(array $args): array
{
    $results = [];
    foreach ($GLOBALS['gpswiss_test_posts'] as $id => $post) {
        if (($args['post_type'] ?? '') !== '' && $post->post_type !== $args['post_type']) {
            continue;
        }
        if (in_array($id, (array) ($args['exclude'] ?? []), true)) {
            continue;
        }
        $matches = true;
        foreach ((array) ($args['meta_query'] ?? []) as $condition) {
            if (!is_array($condition) || !isset($condition['key'])) {
                continue;
            }
            $value = $GLOBALS['gpswiss_test_meta'][$id][$condition['key']] ?? '';
            $compare = $condition['compare'] ?? '=';
            if ($compare === 'EXISTS' && $value === '') {
                $matches = false;
            }
            if ($compare === '=' && (string) $value !== (string) ($condition['value'] ?? '')) {
                $matches = false;
            }
        }
        if ($matches) {
            $results[] = (int) $id;
        }
    }
    return array_slice($results, 0, (int) ($args['posts_per_page'] ?? 10));
}
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_test_writes'][] = ['update_post_meta', $id, $key, $value]; return true; }
function wp_insert_post(array $postarr): int { $GLOBALS['gpswiss_test_writes'][] = ['wp_insert_post', $postarr]; return 999; }

function gpswiss_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gpswiss_codes(array $result): array
{
    return array_map(static fn(array $row): string => (string) $row['code'], (array) $result['validations']);
}

function gpswiss_run_preview_test(string $name, callable $test): void
{
    gpswiss_reset_preview_test_state();
    $test(new WooToOvokoCreatePartPreviewService());
    echo "PASS {$name}\n";
}

gpswiss_run_preview_test('invalid product ID', function (WooToOvokoCreatePartPreviewService $service): void {
    $result = $service->preview(0);
    gpswiss_assert($result['ok'] === false, 'Invalid product ID should fail result ok.');
    gpswiss_assert(in_array('invalid_product_id', gpswiss_codes($result), true), 'Invalid product ID validation missing.');
});

gpswiss_run_preview_test('non-product post type', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_add_post(10, 'page', 'draft', 'Page');
    $result = $service->preview(10);
    gpswiss_assert($result['ok'] === false, 'Non-product should fail result ok.');
    gpswiss_assert(in_array('non_product_post_type', gpswiss_codes($result), true), 'Non-product validation missing.');
});

gpswiss_run_preview_test('product with existing _ovoko_part_id', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    gpswiss_set_meta(123, '_ovoko_part_id', '456');
    $result = $service->preview(123);
    gpswiss_assert($result['would_be_eligible'] === false, 'Existing Ovoko part should block eligibility.');
    gpswiss_assert($result['duplicate_checks']['has_existing_ovoko_part_id'] === true, 'Existing Ovoko part duplicate check missing.');
    gpswiss_assert(in_array('existing_ovoko_part_id', gpswiss_codes($result), true), 'Existing Ovoko part validation missing.');
});

gpswiss_run_preview_test('missing SKU', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    gpswiss_set_meta(123, '_sku', '');
    $result = $service->preview(123);
    gpswiss_assert(in_array('missing_sku', gpswiss_codes($result), true), 'Missing SKU validation missing.');
});

gpswiss_run_preview_test('missing price', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    gpswiss_set_meta(123, '_price', '');
    $result = $service->preview(123);
    gpswiss_assert(in_array('missing_or_non_numeric_price', gpswiss_codes($result), true), 'Missing price validation missing.');
});

gpswiss_run_preview_test('missing part identifier', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    foreach (['_part_number', '_mpn', 'mpn', '_manufacturer_code', '_gpswiss_part_number', '_gps_detected_part_code', '_gps_detected_oem_part_number'] as $key) {
        gpswiss_set_meta(123, $key, '');
    }
    $result = $service->preview(123);
    gpswiss_assert(in_array('missing_part_identifier', gpswiss_codes($result), true), 'Missing part identifier validation missing.');
});

gpswiss_run_preview_test('valid draft product preview', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    $result = $service->preview(123);
    gpswiss_assert($result['ok'] === true, 'Valid preview should be ok.');
    gpswiss_assert($result['would_be_eligible'] === true, 'Valid draft should be eligible.');
    gpswiss_assert($result['would_send'] === false, 'Dry-run must never send.');
    gpswiss_assert($result['proposed_endpoint'] === 'UNCONFIRMED_CREATE_PART_ENDPOINT', 'Endpoint must remain unconfirmed placeholder.');
    gpswiss_assert($result['proposed_payload']['sku'] === 'SKU-123', 'Payload SKU missing.');
});

gpswiss_run_preview_test('image preview extraction', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    gpswiss_set_meta(123, '_thumbnail_id', '501');
    gpswiss_set_meta(123, '_product_image_gallery', '502,503');
    $GLOBALS['gpswiss_test_attachments'][501] = 'https://example.test/501.jpg';
    $GLOBALS['gpswiss_test_attachments'][502] = 'https://example.test/502.jpg';
    $GLOBALS['gpswiss_test_attachments'][503] = 'https://example.test/503.jpg';
    $result = $service->preview(123);
    gpswiss_assert($result['images']['featured_image_id'] === 501, 'Featured image ID missing.');
    gpswiss_assert($result['images']['gallery_image_ids'] === [502, 503], 'Gallery image IDs missing.');
    gpswiss_assert(count($result['images']['image_urls']) === 3, 'Image URLs not extracted.');
    gpswiss_assert($result['images']['upload_policy'] === 'preview_urls_only_no_upload', 'Upload policy must forbid upload.');
});

gpswiss_run_preview_test('no write performed', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    $service->preview(123);
    gpswiss_assert($GLOBALS['gpswiss_test_writes'] === [], 'Preview must not perform Woo writes.');
});
