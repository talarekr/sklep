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
$GLOBALS['gpswiss_test_options'] = [];

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
    $GLOBALS['gpswiss_test_options'] = [];
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
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['gpswiss_test_options'][$key] ?? $default; }

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
    gpswiss_assert($result['proposed_endpoint'] === 'DOCUMENTED_ENDPOINT_WRITE_BLOCKED', 'Endpoint should be documented but write-blocked.');
    gpswiss_assert($result['proposed_endpoint_path'] === '/crm/importPart', 'Likely endpoint path missing.');
    gpswiss_assert($result['endpoint_confirmation_required'] === false, 'Endpoint path is confirmed by documentation.');
    gpswiss_assert($result['payload_format_confirmation_required'] === false, 'Payload format is confirmed by documentation.');
    gpswiss_assert($result['proposed_payload']['external_id'] === 'SKU-123', 'Payload SKU/external_id missing.');
    gpswiss_assert($result['proposed_payload']['manufacturer_code'] === 'ABC-123', 'Payload manufacturer/OEM code missing.');
    gpswiss_assert($result['proposed_payload']['category_id'] === 777, 'Payload category_id missing.');
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


gpswiss_run_preview_test('60886-style payload includes required Woo fields', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_add_post(60886, 'product', 'draft', 'AUDI A3 5Q0131701AN');
    gpswiss_set_meta(60886, '_sku', 'GPS-GMAIL-60849');
    gpswiss_set_meta(60886, '_price', '1000');
    gpswiss_set_meta(60886, '_regular_price', '1000');
    gpswiss_set_meta(60886, '_stock_status', 'instock');
    gpswiss_set_meta(60886, '_stock', '1');
    gpswiss_set_meta(60886, '_part_number', '5Q0131701AN');
    gpswiss_set_meta(60886, '_manufacturer_code', '5Q0131701AN');
    gpswiss_set_meta(60886, '_gps_storage_location', '2KNS');
    gpswiss_set_meta(60886, '_thumbnail_id', '601');
    gpswiss_set_meta(60886, '_product_image_gallery', '602,603');
    $GLOBALS['gpswiss_test_posts'][60886]->post_content = 'Gmail description/body';
    $GLOBALS['gpswiss_test_terms'][60886] = [(object) ['term_id' => 5802, 'name' => 'DPF', 'slug' => 'dpf']];
    $GLOBALS['gpswiss_test_term_meta'][5802]['_ovoko_category_id'] = '1407';
    $GLOBALS['gpswiss_test_attachments'][601] = 'https://example.test/60886-1.jpg';
    $GLOBALS['gpswiss_test_attachments'][602] = 'https://example.test/60886-2.jpg';
    $GLOBALS['gpswiss_test_attachments'][603] = 'https://example.test/60886-3.jpg';

    $result = $service->preview(60886);
    $payload = $result['proposed_payload'];
    gpswiss_assert($result['would_send'] === false && $result['no_ovoko_write'] === true && $result['no_woo_write'] === true, 'Preview must be no-write.');
    gpswiss_assert($payload['external_id'] === 'GPS-GMAIL-60849', '60886 SKU missing.');
    gpswiss_assert($payload['manufacturer_code'] === '5Q0131701AN', '60886 OEM missing.');
    gpswiss_assert($payload['price'] === 1000.0, '60886 price missing.');
    gpswiss_assert($payload['original_currency'] === 'PLN', '60886 currency missing.');
    gpswiss_assert($payload['category_id'] === 1407, '60886 Ovoko category ID missing.');
    gpswiss_assert(count($payload['photos[]']) === 3, '60886 image URLs missing.');
    gpswiss_assert($payload['_source_summary']['stock_quantity'] === 1, '60886 stock missing.');
    gpswiss_assert($payload['place'] === '2KNS', '60886 storage location missing.');
});

gpswiss_run_preview_test('missing Ovoko category ID blocks preview readiness', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    $GLOBALS['gpswiss_test_term_meta'][9]['_ovoko_category_id'] = '';
    $result = $service->preview(123);
    gpswiss_assert(in_array('missing_ovoko_category_id', gpswiss_codes($result), true), 'Missing Ovoko category ID validation missing.');
    gpswiss_assert($result['would_be_eligible'] === false, 'Missing Ovoko category ID should block preview readiness.');
});

gpswiss_run_preview_test('missing image blocks future live readiness', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    gpswiss_set_meta(123, '_thumbnail_id', '');
    $GLOBALS['gpswiss_test_attachments'] = [];
    $result = $service->preview(123);
    gpswiss_assert(in_array('missing_images', gpswiss_codes($result), true), 'Missing images validation missing.');
    gpswiss_assert(in_array('missing_image', $result['future_live_readiness']['blockers'], true), 'Missing image future-live blocker missing.');
});

gpswiss_run_preview_test('draft visibility remains unknown and confirmation-required', function (WooToOvokoCreatePartPreviewService $service): void {
    gpswiss_seed_valid_product();
    $result = $service->preview(123);
    gpswiss_assert($result['would_create_as_draft_or_unpublished'] === 'unknown', 'Draft/unpublished behavior should be unknown.');
    gpswiss_assert($result['draft_visibility_field'] === null, 'No separate draft visibility field should be guessed.');
    gpswiss_assert($result['draft_visibility_value'] === null, 'Draft visibility value must not be guessed.');
    gpswiss_assert($result['draft_visibility_confirmation_required'] === true, 'Draft visibility confirmation must be required.');
    gpswiss_assert(in_array('draft_unpublished_behavior_not_confirmed', $result['future_live_readiness']['blockers'], true), 'Draft behavior blocker missing.');
});

gpswiss_run_preview_test('contract report identifies importPart without live publishing', function (WooToOvokoCreatePartPreviewService $service): void {
    $report = $service->create_part_contract_report();
    gpswiss_assert($report['candidate_endpoints'][0]['path'] === '/crm/importPart', 'importPart endpoint candidate missing.');
    gpswiss_assert($report['candidate_endpoints'][0]['method'] === 'POST', 'importPart HTTP method missing.');
    gpswiss_assert(in_array('status', $report['required_fields'], true), 'Required status field missing from contract.');
    gpswiss_assert($report['draft_unpublished_visibility_support']['confirmation_required'] === true, 'Draft confirmation requirement missing.');
    gpswiss_assert($report['draft_unpublished_visibility_support']['status_field_is_operational_stock_sales_status'] === true, 'Status should be marked operational stock/sales.');
    gpswiss_assert($report['candidate_endpoints'][0]['status'] === 'confirmed_by_documentation', 'importPart endpoint should be documentation-confirmed.');
    gpswiss_assert($report['documentation_backed_findings']['required_fields']['status'] === 'confirmed_by_documentation', 'Required fields should be documentation-confirmed.');
    gpswiss_assert($report['documentation_backed_findings']['hidden_draft_unpublished_private_field']['status'] === 'not_found_in_documentation', 'Hidden/draft field finding missing.');
    gpswiss_assert($report['documentation_backed_findings']['public_immediately_after_import']['status'] === 'unknown', 'Public import behavior must remain unknown.');
    gpswiss_assert($report['listing_visibility_audit']['import_part_visibility_field_separate_from_status']['status'] === 'not_found_in_documentation', 'Listing visibility audit missing importPart finding.');
    gpswiss_assert($report['listing_visibility_audit']['status_0_public_effect']['status'] === 'unknown', 'status=0 public effect must remain unknown.');
    gpswiss_assert($report['write_safety']['live_create_implemented'] === false, 'Live create must not be implemented.');
});


gpswiss_run_preview_test('contract report includes latest part status probe summary when available', function (WooToOvokoCreatePartPreviewService $service): void {
    $GLOBALS['gpswiss_test_options']['gpswiss_ovoko_part_status_probe_result'] = [
        'ok' => true,
        'checked_at' => '2026-06-08T00:00:00+00:00',
        'endpoint_used' => '/get/part_status',
        'status_count' => 5,
        'operational_stock_sales_statuses' => [['id' => '0', 'name' => 'In stock / Na stanie', 'interpretation' => 'operational_stock_sales_lifecycle']],
        'interpretation_summary' => ['status_catalog_scope' => 'operational_stock_sales_lifecycle', 'confirmation_required' => true, 'safe_non_public_status_value' => null],
    ];
    $report = $service->create_part_contract_report();
    gpswiss_assert($report['latest_part_status_probe_result']['available'] === true, 'Latest status probe summary missing.');
    gpswiss_assert($report['latest_part_status_probe_result']['endpoint_used'] === '/get/part_status', 'Latest status probe endpoint missing.');
    gpswiss_assert($report['latest_part_status_probe_result']['interpretation_summary']['confirmation_required'] === true, 'Latest status probe confirmation requirement missing.');
    gpswiss_assert($report['latest_part_status_probe_result']['status_catalog_scope'] === 'operational_stock_sales_lifecycle', 'Latest status probe should surface operational scope.');
});
