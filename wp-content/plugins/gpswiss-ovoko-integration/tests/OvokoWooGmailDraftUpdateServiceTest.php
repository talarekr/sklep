<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoWooGmailDraftUpdateService;

require_once dirname(__DIR__) . '/src/Services/OvokoWooGmailDraftUpdateService.php';

$GLOBALS['gpswiss_gmail_posts'] = [];
$GLOBALS['gpswiss_gmail_meta'] = [];
$GLOBALS['gpswiss_gmail_terms'] = [];
$GLOBALS['gpswiss_gmail_term_meta'] = [];
$GLOBALS['gpswiss_gmail_writes'] = [];
$GLOBALS['gpswiss_gmail_image_import_calls'] = 0;
$GLOBALS['gpswiss_gmail_next_part'] = [];

function gpswiss_gmail_reset(): void
{
    $GLOBALS['gpswiss_gmail_posts'] = [];
    $GLOBALS['gpswiss_gmail_meta'] = [];
    $GLOBALS['gpswiss_gmail_terms'] = [];
    $GLOBALS['gpswiss_gmail_term_meta'] = [];
    $GLOBALS['gpswiss_gmail_writes'] = [];
    $GLOBALS['gpswiss_gmail_image_import_calls'] = 0;
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part();
}

function gpswiss_gmail_seed(int $id = 101): void
{
    $GLOBALS['gpswiss_gmail_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Gmail draft old title', 'post_content' => 'Old Gmail text', 'post_excerpt' => 'Old short'];
    $GLOBALS['gpswiss_gmail_meta'][$id] = [
        '_sku' => 'GPS-GMAIL-101',
        '_ovoko_part_id' => '555',
        '_thumbnail_id' => '901',
        '_product_image_gallery' => '902,903',
        '_price' => '',
        '_regular_price' => '',
    ];
    $GLOBALS['gpswiss_gmail_terms'][$id] = [(object) ['term_id' => 11, 'name' => 'Old category']];
    $GLOBALS['gpswiss_gmail_term_meta'][22] = ['_ovoko_category_id' => '1407', 'name' => 'Real mapped category'];
}

function gpswiss_gmail_part(array $overrides = []): array
{
    return $overrides + [
        'part_id' => '555',
        'title' => 'Ovoko final product title',
        'description' => 'Final long product description from Ovoko.',
        'price' => '250.50',
        'category_id' => '1407',
        'category_title_path' => 'Body / Bumper / Bracket',
        'status' => 'active',
        'make' => 'Audi',
        'model' => 'A4',
        'version' => 'B9',
        'engine' => '2.0 TDI',
        'year' => '2018',
        'car_id' => '777',
        'manufacturer_code' => '8W0807283',
        'visible_code' => 'VIS-1',
        'oe_numbers' => ['8W0807283'],
        'parameters' => ['side' => 'left'],
        'weight' => '2.4',
    ];
}

function gpswiss_gmail_service(): OvokoWooGmailDraftUpdateService
{
    return new OvokoWooGmailDraftUpdateService(null, static function (string $partId): array {
        return ['ok' => true, 'part' => $GLOBALS['gpswiss_gmail_next_part'] + ['part_id' => $partId]];
    });
}

function gpswiss_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function gpswiss_run(string $name, callable $test): void
{
    gpswiss_gmail_reset();
    gpswiss_gmail_seed();
    $test(gpswiss_gmail_service());
    echo "PASS {$name}\n";
}

function get_post(int $id): ?object { return $GLOBALS['gpswiss_gmail_posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_gmail_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_gmail_writes'][] = ['update_post_meta', $id, $key, $value]; $GLOBALS['gpswiss_gmail_meta'][$id][$key] = $value; return true; }
function wp_update_post(array $postarr): int { $GLOBALS['gpswiss_gmail_writes'][] = ['wp_update_post', $postarr]; $id = (int) $postarr['ID']; foreach ($postarr as $key => $value) { if ($key !== 'ID') $GLOBALS['gpswiss_gmail_posts'][$id]->$key = $value; } return $id; }
function wp_set_object_terms(int $id, array $terms, string $taxonomy, bool $append = false): array { $GLOBALS['gpswiss_gmail_writes'][] = ['wp_set_object_terms', $id, $terms, $taxonomy, $append]; $GLOBALS['gpswiss_gmail_terms'][$id] = [(object) ['term_id' => (int) $terms[0], 'name' => 'Real mapped category']]; return $terms; }
function wp_get_post_terms(int $id, string $taxonomy): array { return $GLOBALS['gpswiss_gmail_terms'][$id] ?? []; }
function get_terms(array $args): array { foreach ($GLOBALS['gpswiss_gmail_term_meta'] as $termId => $meta) { if (($meta[$args['meta_key']] ?? '') === (string) $args['meta_value']) return [(object) ['term_id' => (int) $termId, 'name' => (string) ($meta['name'] ?? 'Mapped')]]; } return []; }
function get_posts(array $args): array { return array_keys($GLOBALS['gpswiss_gmail_posts']); }
function is_wp_error(mixed $thing): bool { return false; }
function wp_json_encode(mixed $data, int $flags = 0): string { return json_encode($data, $flags) ?: ''; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function sanitize_title(string $title): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')); }
function gpswiss_fake_image_import_service(): void { $GLOBALS['gpswiss_gmail_image_import_calls']++; }

gpswiss_run('GPS-GMAIL SKU with ovoko_part_id and Woo images previews OK', function (OvokoWooGmailDraftUpdateService $service): void {
    $result = $service->preview_one(101);
    gpswiss_assert($result['ok'] === true, 'Preview should be OK.');
    gpswiss_assert($result['ready_for_sale'] === true, 'Product should be ready.');
    gpswiss_assert($result['images_preserved'] === true, 'Images must be marked preserved.');
});

gpswiss_run('Preview does not write anything', function (OvokoWooGmailDraftUpdateService $service): void {
    $before = $GLOBALS['gpswiss_gmail_meta'];
    $service->preview_one(101);
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'] === $before, 'Preview changed meta.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_writes'] === [], 'Preview wrote to WordPress.');
});

gpswiss_run('Live update changes title price category meta but preserves thumbnail', function (OvokoWooGmailDraftUpdateService $service): void {
    $result = $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($result['updated'] === true, 'Live update should update.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_title === 'Ovoko final product title', 'Title not updated.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_price'] === '250.5', 'Price not updated.');
    gpswiss_assert((int) $GLOBALS['gpswiss_gmail_terms'][101][0]->term_id === 22, 'Category not updated.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_thumbnail_id'] === '901', 'Thumbnail changed.');
});

gpswiss_run('Live update preserves product gallery', function (OvokoWooGmailDraftUpdateService $service): void {
    $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_product_image_gallery'] === '902,903', 'Gallery changed.');
});

gpswiss_run('Missing Ovoko price remains draft with missing_price', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part(['price' => '']);
    $result = $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_status === 'draft', 'Missing price should stay draft.');
    gpswiss_assert(in_array('missing_price', $result['blocked_reasons'], true), 'missing_price not reported.');
});

gpswiss_run('Price and mapped category publish when ready is ON', function (OvokoWooGmailDraftUpdateService $service): void {
    $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION, 'publish_when_ready' => true]);
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_status === 'publish', 'Ready product should publish.');
});

gpswiss_run('Missing Woo images blocks publish', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_meta'][101]['_thumbnail_id'] = '';
    $GLOBALS['gpswiss_gmail_meta'][101]['_product_image_gallery'] = '';
    $result = $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_status === 'draft', 'No-image product should stay draft.');
    gpswiss_assert(in_array('missing_existing_woo_images', $result['blocked_reasons'], true), 'missing_existing_woo_images not reported.');
});

gpswiss_run('Non GPS-GMAIL SKU is skipped', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_meta'][101]['_sku'] = 'EBAY-101';
    $result = $service->preview_one(101);
    gpswiss_assert($result['ok'] === false, 'Non Gmail product should not be OK.');
    gpswiss_assert(in_array('non_gmail_sku', $result['blocked_reasons'], true), 'non_gmail_sku not reported.');
});

gpswiss_run('Missing ovoko_part_id is skipped', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_meta'][101]['_ovoko_part_id'] = '';
    $result = $service->preview_one(101);
    gpswiss_assert($result['ok'] === false, 'Missing part ID should not be OK.');
    gpswiss_assert(in_array('missing_part_id', $result['blocked_reasons'], true), 'missing_part_id not reported.');
});

gpswiss_run('Live update does not create a Woo product', function (OvokoWooGmailDraftUpdateService $service): void {
    $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    foreach ($GLOBALS['gpswiss_gmail_writes'] as $write) {
        gpswiss_assert($write[0] !== 'wp_insert_post', 'wp_insert_post was called.');
    }
});

gpswiss_run('Image import service is not invoked', function (OvokoWooGmailDraftUpdateService $service): void {
    $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($GLOBALS['gpswiss_gmail_image_import_calls'] === 0, 'Image import service was called.');
});
