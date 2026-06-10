<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoToWooGmailDraftUpdateService;

require_once dirname(__DIR__) . '/src/Services/OvokoToWooGmailDraftUpdateService.php';
require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';

$GLOBALS['gpswiss_gmail_posts'] = [];
$GLOBALS['gpswiss_gmail_meta'] = [];
$GLOBALS['gpswiss_gmail_terms'] = [];
$GLOBALS['gpswiss_gmail_writes'] = [];
$GLOBALS['gpswiss_gmail_insert_post_calls'] = 0;
$GLOBALS['gpswiss_gmail_image_import_calls'] = 0;
$GLOBALS['gpswiss_next_term_id'] = 1000;

function gpswiss_reset_gmail_update_state(): void
{
    $GLOBALS['gpswiss_gmail_posts'] = [];
    $GLOBALS['gpswiss_gmail_meta'] = [];
    $GLOBALS['gpswiss_gmail_terms'] = [];
    $GLOBALS['gpswiss_gmail_writes'] = [];
    $GLOBALS['gpswiss_gmail_insert_post_calls'] = 0;
    $GLOBALS['gpswiss_gmail_image_import_calls'] = 0;
    $GLOBALS['gpswiss_next_term_id'] = 1000;
}

function gpswiss_seed_gmail_product(int $id = 501, string $sku = 'GPS-GMAIL-ABC', string $partId = '9001'): void
{
    $GLOBALS['gpswiss_gmail_posts'][$id] = (object) [
        'ID' => $id,
        'post_type' => 'product',
        'post_status' => 'draft',
        'post_title' => 'Gmail draft title',
        'post_name' => 'gmail-draft-title',
        'post_content' => 'Original Gmail description',
        'post_excerpt' => 'Original Gmail short',
    ];
    $GLOBALS['gpswiss_gmail_meta'][$id] = [
        '_sku' => $sku,
        '_ovoko_part_id' => $partId,
        '_price' => '',
        '_regular_price' => '',
        '_thumbnail_id' => '77',
        '_product_image_gallery' => '77,78,79',
    ];
}

function gpswiss_part(array $overrides = []): array
{
    return ['ok' => true, 'normalized' => array_merge([
        'part_id' => '9001',
        'title' => 'Ovoko real title',
        'notes' => 'Long Ovoko product description with technical content.',
        'woo_target_price' => '321.50',
        'price' => '321.50',
        'category_title_path' => 'Body > Door',
        'category_id' => '44',
        'status' => 'available',
        'manufacturer_code' => 'OEM-123',
        'visible_code' => 'VIS-123',
        'other_code' => 'ALT-123',
        'car_id' => 'CAR-7',
        'vehicle_make' => 'VW',
        'vehicle_model' => 'Golf',
        'vehicle_generation' => 'VII',
        'vehicle_engine_code' => 'CFFB',
        'vehicle_year' => '2016',
    ], $overrides)];
}

function gpswiss_fetcher(array $part): callable
{
    return static fn(string $partId): array => $part;
}

function gpswiss_assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gpswiss_run_gmail_update_test(string $name, callable $test): void
{
    gpswiss_reset_gmail_update_state();
    $test();
    echo "PASS {$name}\n";
}

function get_post(int $id): ?object { return $GLOBALS['gpswiss_gmail_posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_gmail_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_gmail_meta'][$id][$key] = $value; $GLOBALS['gpswiss_gmail_writes'][] = ['meta', $id, $key, $value]; return true; }
function wp_update_post(array $postarr, bool $wp_error = false): int { $id = (int) $postarr['ID']; foreach ($postarr as $k => $v) { if ($k !== 'ID') { $GLOBALS['gpswiss_gmail_posts'][$id]->{$k} = $v; } } $GLOBALS['gpswiss_gmail_writes'][] = ['post', $postarr]; return $id; }
function wp_insert_post(array $postarr, bool $wp_error = false): int { $GLOBALS['gpswiss_gmail_insert_post_calls']++; return 999999; }
function get_posts(array $args): array { return array_keys($GLOBALS['gpswiss_gmail_posts']); }
function wp_get_post_terms(int $id, string $taxonomy, array $args = []): array { return $GLOBALS['gpswiss_gmail_terms'][$id] ?? []; }
function get_term_by(string $field, string $value, string $taxonomy): object|false { foreach ($GLOBALS['gpswiss_gmail_terms'] as $terms) { foreach ($terms as $term) { if (($term->name ?? '') === $value) { return $term; } } } return false; }
function get_terms(array $args): array { return []; }
function wp_insert_term(string $term, string $taxonomy, array $args = []): array { $id = ++$GLOBALS['gpswiss_next_term_id']; return ['term_id' => $id]; }
function wp_set_object_terms(int $id, array $terms, string $taxonomy, bool $append = false): bool { $GLOBALS['gpswiss_gmail_terms'][$id] = [(object) ['term_id' => (int) $terms[0], 'name' => 'Door', 'slug' => 'door']]; $GLOBALS['gpswiss_gmail_writes'][] = ['terms', $id, $terms]; return true; }
function update_term_meta(int $termId, string $key, mixed $value): bool { return true; }
function sanitize_title(string $value): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value), '-')); }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
function is_wp_error(mixed $value): bool { return false; }

// Sentinel that must never be called by the Gmail draft update service.
function gpswiss_fake_image_import_service_call(): void { $GLOBALS['gpswiss_gmail_image_import_calls']++; }

gpswiss_run_gmail_update_test('SKU GPS-GMAIL + ovoko_part_id + Woo images previews OK', function (): void {
    gpswiss_seed_gmail_product();
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->preview_one(501);
    gpswiss_assert_true($result['ok'] === true, 'Preview should be ok.');
    gpswiss_assert_true($result['sku'] === 'GPS-GMAIL-ABC', 'SKU should be reported.');
    gpswiss_assert_true($result['ovoko_part_id'] === '9001', 'Ovoko part ID should be reported.');
    gpswiss_assert_true($result['images_preserved'] === true, 'Preview should report images preserved.');
});

gpswiss_run_gmail_update_test('Preview does not write anything', function (): void {
    gpswiss_seed_gmail_product();
    (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->preview_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_writes'] === [], 'Preview must not write posts, terms, or meta.');
});

gpswiss_run_gmail_update_test('Live update changes title price category meta but preserves thumbnail', function (): void {
    gpswiss_seed_gmail_product();
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_posts'][501]->post_title === 'Ovoko real title', 'Title should change.');
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_meta'][501]['_price'] === '321.5', 'Price should change.');
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_meta'][501]['_ovoko_manufacturer_code'] === 'OEM-123', 'Meta should change.');
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_meta'][501]['_thumbnail_id'] === '77', 'Thumbnail must be preserved.');
    gpswiss_assert_true($result['images_preserved'] === true, 'Image guard should report preserved.');
});

gpswiss_run_gmail_update_test('Live update preserves gallery', function (): void {
    gpswiss_seed_gmail_product();
    (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_meta'][501]['_product_image_gallery'] === '77,78,79', 'Gallery must be preserved.');
});

gpswiss_run_gmail_update_test('Missing Ovoko price remains draft with missing_price', function (): void {
    gpswiss_seed_gmail_product();
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part(['woo_target_price' => '', 'price' => '']))))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_posts'][501]->post_status === 'draft', 'Product should remain draft.');
    gpswiss_assert_true(in_array('missing_price', $result['blocked_reasons'], true), 'missing_price blocker expected.');
});

gpswiss_run_gmail_update_test('Price and category publish when ready is ON', function (): void {
    gpswiss_seed_gmail_product();
    (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501, ['publish_when_ready' => true]);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_posts'][501]->post_status === 'publish', 'Ready product should publish.');
});

gpswiss_run_gmail_update_test('Missing Woo images does not publish and blocks', function (): void {
    gpswiss_seed_gmail_product();
    $GLOBALS['gpswiss_gmail_meta'][501]['_thumbnail_id'] = '';
    $GLOBALS['gpswiss_gmail_meta'][501]['_product_image_gallery'] = '';
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_posts'][501]->post_status === 'draft', 'Product without images should remain draft.');
    gpswiss_assert_true(in_array('missing_existing_woo_images', $result['blocked_reasons'], true), 'missing_existing_woo_images blocker expected.');
});

gpswiss_run_gmail_update_test('Non Gmail SKU is skipped', function (): void {
    gpswiss_seed_gmail_product(501, 'NORMAL-SKU', '9001');
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->preview_one(501);
    gpswiss_assert_true($result['ok'] === false, 'Non-Gmail product should not be ok.');
    gpswiss_assert_true(in_array('non_gmail_sku', $result['blocked_reasons'], true), 'non_gmail_sku blocker expected.');
});

gpswiss_run_gmail_update_test('Missing ovoko_part_id is skipped', function (): void {
    gpswiss_seed_gmail_product(501, 'GPS-GMAIL-ABC', '');
    $result = (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->preview_one(501);
    gpswiss_assert_true($result['ok'] === false, 'Missing part ID should not be ok.');
    gpswiss_assert_true(in_array('missing_part_id', $result['blocked_reasons'], true), 'missing_part_id blocker expected.');
});

gpswiss_run_gmail_update_test('No new Woo product is created', function (): void {
    gpswiss_seed_gmail_product();
    (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_insert_post_calls'] === 0, 'wp_insert_post must not be called.');
});

gpswiss_run_gmail_update_test('Image import service is not called', function (): void {
    gpswiss_seed_gmail_product();
    (new OvokoToWooGmailDraftUpdateService([], gpswiss_fetcher(gpswiss_part())))->update_one(501);
    gpswiss_assert_true($GLOBALS['gpswiss_gmail_image_import_calls'] === 0, 'Image import service must not be called.');
});
