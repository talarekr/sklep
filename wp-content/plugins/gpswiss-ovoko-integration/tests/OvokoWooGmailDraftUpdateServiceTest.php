<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoWooGmailDraftUpdateService;

require_once dirname(__DIR__) . '/src/Services/OvokoIntegrationService.php';
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
    $GLOBALS['gpswiss_gmail_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Gmail draft old title', 'post_name' => 'gmail-draft-old-title-gps-gmail-101', 'post_content' => 'Old Gmail text', 'post_excerpt' => 'Old short'];
    $GLOBALS['gpswiss_gmail_meta'][$id] = [
        '_sku' => 'GPS-GMAIL-101',
        '_ovoko_part_id' => '555',
        '_thumbnail_id' => '901',
        '_product_image_gallery' => '902,903',
        '_price' => '',
        '_regular_price' => '',
    ];
    $GLOBALS['gpswiss_gmail_terms'][$id] = [(object) ['term_id' => 11, 'name' => 'Old category']];
    $GLOBALS['gpswiss_gmail_term_meta'][22] = ['_ovoko_category_id' => '1407', 'name' => 'Real mapped category', 'slug' => 'real-mapped-category', 'parent' => 0];
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
    $integration = new GPSwiss\Ovoko\Services\OvokoIntegrationService(__FILE__);
    return new OvokoWooGmailDraftUpdateService(null, static function (string $partId): array {
        return ['ok' => true, 'part' => $GLOBALS['gpswiss_gmail_next_part'] + ['part_id' => $partId]];
    }, static function (array $part) use ($integration): array {
        return $integration->build_woo_product_title_preview_for_gmail_draft($part, null);
    }, static function (string $generatedTitle, int $productId, string $postStatus) use ($integration): array {
        return $integration->build_woo_product_slug_preview_for_gmail_draft($generatedTitle, $productId, $postStatus);
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
function get_terms(array $args): array {
    $rows = [];
    foreach ($GLOBALS['gpswiss_gmail_term_meta'] as $termId => $meta) {
        if (isset($args['meta_key'])) {
            if (($meta[$args['meta_key']] ?? '') !== (string) ($args['meta_value'] ?? '')) continue;
        }
        if (isset($args['name']) && (string) ($meta['name'] ?? '') !== (string) $args['name']) continue;
        if (isset($args['parent']) && (int) ($meta['parent'] ?? 0) !== (int) $args['parent']) continue;
        $rows[] = (object) ['term_id' => (int) $termId, 'name' => (string) ($meta['name'] ?? 'Mapped'), 'slug' => (string) ($meta['slug'] ?? sanitize_title((string) ($meta['name'] ?? 'Mapped'))), 'parent' => (int) ($meta['parent'] ?? 0)];
        if ((int) ($args['number'] ?? 0) === 1) break;
    }
    return $rows;
}
function get_term(int $termId, string $taxonomy): ?object {
    $meta = $GLOBALS['gpswiss_gmail_term_meta'][$termId] ?? null;
    return is_array($meta) ? (object) ['term_id' => $termId, 'name' => (string) ($meta['name'] ?? 'Mapped'), 'slug' => (string) ($meta['slug'] ?? sanitize_title((string) ($meta['name'] ?? 'Mapped'))), 'parent' => (int) ($meta['parent'] ?? 0)] : null;
}
function get_option(string $key, mixed $default = false): mixed { return $default; }
function get_posts(array $args): array { return array_keys($GLOBALS['gpswiss_gmail_posts']); }
function is_wp_error(mixed $thing): bool { return false; }
function wp_json_encode(mixed $data, int $flags = 0): string { return json_encode($data, $flags) ?: ''; }
function wp_strip_all_tags(string $text): string { return strip_tags($text); }
function sanitize_title(string $title): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')); }
function wp_unique_post_slug(string $slug, int $postId, string $postStatus, string $postType, int $postParent): string { return $slug; }
function home_url(string $path = ''): string { return 'https://gpswiss.test' . $path; }
function get_permalink(int $id): string { $slug = (string) ($GLOBALS['gpswiss_gmail_posts'][$id]->post_name ?? ''); return $slug !== '' ? home_url('/produkt/' . $slug . '/') : ''; }
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

gpswiss_run('Preview uses existing Ovoko Woo title builder instead of raw Ovoko title', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_posts'][101]->post_title = '905-5970';
    $GLOBALS['gpswiss_gmail_meta'][101]['_sku'] = 'GPS-GMAIL-61488';
    $GLOBALS['gpswiss_gmail_meta'][101]['_ovoko_part_id'] = '11191';
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part([
        'part_id' => '11191',
        'title' => 'Lusterko wsteczne',
        'category_id' => '1524',
        'category_title_path' => 'Lusterko wsteczne / Podsufitki / Szyberdachy i inne elementy',
        'manufacturer_code' => '9055970',
        'visible_code' => '9055970',
        'car_id' => '425',
        'vehicle_make' => 'BMW',
        'vehicle_model' => 'X5',
        'vehicle_generation' => 'E70',
        'vehicle_year' => '2012',
    ]);
    $GLOBALS['gpswiss_gmail_term_meta'] = [
        123 => ['name' => 'Lusterko wsteczne', 'slug' => 'lusterko-wsteczne', 'parent' => 0],
        124 => ['name' => 'Podsufitki', 'slug' => 'podsufitki', 'parent' => 123],
        125 => ['name' => 'Szyberdachy i inne elementy', 'slug' => 'szyberdachy-i-inne-elementy', 'parent' => 124],
    ];

    $preview = $service->preview_one(101);
    gpswiss_assert($preview['title_after'] === 'BMW X5 E70 2012 2.0 Lusterko wsteczne 9055970', 'Preview title_after should be generated by existing title builder.');
    gpswiss_assert($preview['title_after'] !== 'Lusterko wsteczne', 'Preview used raw Ovoko title.');
    gpswiss_assert(($preview['slug_before'] ?? '') === 'gmail-draft-old-title-gps-gmail-101', 'Preview should expose slug_before.');
    gpswiss_assert(($preview['slug_after'] ?? '') === 'bmw-x5-e70-2012-2-0-lusterko-wsteczne-9055970', 'Slug should be based on the generated title without appending Gmail SKU.');
    gpswiss_assert(str_contains((string) ($preview['slug_builder_source'] ?? ''), 'existing_ovoko_woo_generated_title'), 'Slug should report the normal Ovoko/Woo slug source.');
    gpswiss_assert(!str_contains((string) ($preview['slug_after'] ?? ''), 'gps-gmail-61488'), 'Slug should not contain the Gmail SKU.');
    gpswiss_assert(($preview['url_before'] ?? '') === 'https://gpswiss.test/produkt/gmail-draft-old-title-gps-gmail-101/', 'Preview should expose url_before.');
    gpswiss_assert(($preview['url_after'] ?? '') === 'https://gpswiss.test/produkt/bmw-x5-e70-2012-2-0-lusterko-wsteczne-9055970/', 'Preview should expose url_after based on generated slug.');
    gpswiss_assert(($preview['planned_permalink_path'] ?? '') === '/produkt/bmw-x5-e70-2012-2-0-lusterko-wsteczne-9055970/', 'Preview should expose planned permalink path.');
    gpswiss_assert(!empty($preview['title_builder_used_vehicle_data']), 'Title builder should report vehicle data usage.');

    $live = $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($live['title_after'] === $preview['title_after'], 'Live update title should match preview title.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_title === $preview['title_after'], 'Live update did not save generated title.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_name === $preview['slug_after'], 'Live update did not save generated slug.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_sku'] === 'GPS-GMAIL-61488', 'Technical Gmail SKU changed.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_thumbnail_id'] === '901', 'Thumbnail changed.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_product_image_gallery'] === '902,903', 'Gallery changed.');
    foreach ($GLOBALS['gpswiss_gmail_writes'] as $write) {
        gpswiss_assert($write[0] !== 'wp_insert_post', 'wp_insert_post was called.');
    }
});

gpswiss_run('Live update changes title price category meta but preserves thumbnail', function (OvokoWooGmailDraftUpdateService $service): void {
    $result = $service->update_one(101, ['confirmation' => OvokoWooGmailDraftUpdateService::LIVE_CONFIRMATION]);
    gpswiss_assert($result['updated'] === true, 'Live update should update.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_posts'][101]->post_title === 'AUDI A4 B9 2018 2.0 Ovoko final product title 8W0807283', 'Title not updated through existing builder.');
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


gpswiss_run('Preview maps Ovoko category 1524 by existing Woo category path', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part([
        'category_id' => '1524',
        'category_title_path' => 'Lusterko wsteczne / Podsufitki / Szyberdachy i inne elementy',
        'title' => 'Lusterko wsteczne',
        'description' => "LUSTERKO WEWNĘTRZNE WSTECZNE EUROPA
MAG: FA4",
        'price' => '120',
    ]);
    $GLOBALS['gpswiss_gmail_term_meta'] = [
        123 => ['name' => 'Lusterko wsteczne', 'slug' => 'lusterko-wsteczne', 'parent' => 0],
        124 => ['name' => 'Podsufitki', 'slug' => 'podsufitki', 'parent' => 123],
        125 => ['name' => 'Szyberdachy i inne elementy', 'slug' => 'szyberdachy-i-inne-elementy', 'parent' => 124],
    ];

    $result = $service->preview_one(101);
    gpswiss_assert((int) $result['category_mapping']['term_id'] === 125, 'Preview should map category path to existing term_id.');
    gpswiss_assert(!in_array('category_mapping_failed', $result['blocked_reasons'], true), 'category_mapping_failed should disappear.');
    gpswiss_assert($result['ready_for_sale'] === true, 'Product should be ready after category mapping.');
    gpswiss_assert($result['would_publish'] === true, 'Product should publish when ready.');
    gpswiss_assert(($result['matched_by'] ?? '') === 'exact name/path', 'matched_by should report exact name/path.');
    gpswiss_assert(($result['ovoko_category_id'] ?? '') === '1524', 'ovoko_category_id diagnostic missing.');
    gpswiss_assert(($result['ovoko_category_title_path'] ?? '') === 'Lusterko wsteczne / Podsufitki / Szyberdachy i inne elementy', 'ovoko_category_title_path diagnostic missing.');
});

gpswiss_run('Preview exposes category mapping diagnostics when mapping fails', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part([
        'category_id' => '1524',
        'category_title_path' => 'Lusterko wsteczne / Podsufitki / Szyberdachy i inne elementy',
    ]);
    $GLOBALS['gpswiss_gmail_term_meta'] = [
        501 => ['name' => 'Unrelated category', 'slug' => 'unrelated-category', 'parent' => 0],
    ];

    $result = $service->preview_one(101);
    gpswiss_assert(in_array('category_mapping_failed', $result['blocked_reasons'], true), 'category_mapping_failed should be reported.');
    gpswiss_assert(($result['matched_by'] ?? '') === 'none', 'matched_by should be none.');
    gpswiss_assert(!empty($result['category_mapping_attempts']), 'category_mapping_attempts diagnostic missing.');
    gpswiss_assert(!empty($result['candidate_terms']), 'candidate_terms diagnostic missing.');
    gpswiss_assert(count($result['candidate_terms']) <= 10, 'candidate_terms should be capped at 10.');
});

gpswiss_run('Preview one converts fetch exception to ovoko_fetch_failed report', function (): void {
    $service = new OvokoWooGmailDraftUpdateService(null, static function (): array {
        throw new RuntimeException('simulated fetch outage');
    });
    $result = $service->preview_one(101);
    gpswiss_assert($result['ok'] === false, 'Fetch exception should not be OK.');
    gpswiss_assert(in_array('ovoko_fetch_failed', $result['blocked_reasons'], true), 'ovoko_fetch_failed not reported.');
    gpswiss_assert(($result['technical_details']['message'] ?? '') === 'simulated fetch outage', 'Technical details missing fetch exception.');
});

gpswiss_run('Preview eligible reports one fetch failure and continues', function (): void {
    gpswiss_gmail_seed(102);
    $GLOBALS['gpswiss_gmail_meta'][102]['_sku'] = 'GPS-GMAIL-102';
    $GLOBALS['gpswiss_gmail_meta'][102]['_ovoko_part_id'] = '556';
    $service = new OvokoWooGmailDraftUpdateService(null, static function (string $partId): array {
        if ($partId === '555') {
            throw new RuntimeException('simulated one-item fetch outage');
        }
        return ['ok' => true, 'part' => gpswiss_gmail_part(['part_id' => $partId])];
    });

    $result = $service->preview_eligible(2);
    gpswiss_assert($result['total_ovoko_fetch_failed'] === 1, 'Fetch failure count should be one.');
    gpswiss_assert(count($result['examples']) === 2, 'Preview should continue to the second product.');
});

gpswiss_run('Preview one without injected RRR client reports controlled configuration error', function (): void {
    $service = new OvokoWooGmailDraftUpdateService();
    $result = $service->preview_one(101);
    gpswiss_assert($result['ok'] === false, 'Missing RRR client should not be OK.');
    gpswiss_assert(in_array('ovoko_fetch_failed', $result['blocked_reasons'], true), 'Missing RRR client should be reported as ovoko_fetch_failed.');
    gpswiss_assert(($result['error'] ?? '') === 'rrr_api_client_not_configured', 'Missing RRR client error should be controlled.');
});

gpswiss_run('Batch preview scans only eligible Gmail products with Ovoko part IDs for updates', function (OvokoWooGmailDraftUpdateService $service): void {
    gpswiss_gmail_seed(102);
    $GLOBALS['gpswiss_gmail_meta'][102]['_sku'] = 'NORMAL-102';
    $GLOBALS['gpswiss_gmail_meta'][102]['_ovoko_part_id'] = '556';
    gpswiss_gmail_seed(103);
    $GLOBALS['gpswiss_gmail_meta'][103]['_sku'] = 'GPS-GMAIL-103';
    unset($GLOBALS['gpswiss_gmail_meta'][103]['_ovoko_part_id']);

    $result = $service->run_batch(['mode' => 'preview', 'batch_size' => 10]);
    gpswiss_assert($result['counters']['scanned'] === 3, 'Batch should scan all seeded products.');
    gpswiss_assert($result['counters']['eligible'] === 1, 'Only one product should be eligible.');
    gpswiss_assert($result['counters']['skipped'] === 2, 'Non-Gmail and missing part products should be skipped.');
    gpswiss_assert($result['counters']['would_update'] === 1, 'Ready eligible product should be reported as would_update.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_writes'] === [], 'Preview batch must not write.');
});

gpswiss_run('Batch live updates ready Gmail products and preserves images without creating Woo products', function (OvokoWooGmailDraftUpdateService $service): void {
    $beforePostCount = count($GLOBALS['gpswiss_gmail_posts']);
    $beforeImages = [
        'thumbnail' => $GLOBALS['gpswiss_gmail_meta'][101]['_thumbnail_id'],
        'gallery' => $GLOBALS['gpswiss_gmail_meta'][101]['_product_image_gallery'],
    ];

    $result = $service->run_batch([
        'mode' => 'live',
        'batch_size' => 1,
        'publish_when_ready' => true,
        'confirmation' => OvokoWooGmailDraftUpdateService::BATCH_LIVE_CONFIRMATION,
    ]);

    gpswiss_assert($result['counters']['eligible'] === 1, 'Live batch should process one eligible product.');
    gpswiss_assert($result['counters']['updated'] === 1, 'Ready product should be updated.');
    gpswiss_assert($result['counters']['published'] === 1, 'Ready product should be published when enabled.');
    gpswiss_assert(count($GLOBALS['gpswiss_gmail_posts']) === $beforePostCount, 'Batch must not create Woo products.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_thumbnail_id'] === $beforeImages['thumbnail'], 'Thumbnail should be preserved.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_meta'][101]['_product_image_gallery'] === $beforeImages['gallery'], 'Gallery should be preserved.');
    foreach ($GLOBALS['gpswiss_gmail_writes'] as $write) {
        gpswiss_assert($write[0] !== 'wp_insert_post', 'wp_insert_post was called.');
    }
});

gpswiss_run('Batch live skips blocked product without updating', function (OvokoWooGmailDraftUpdateService $service): void {
    $GLOBALS['gpswiss_gmail_next_part'] = gpswiss_gmail_part(['price' => '']);
    $result = $service->run_batch([
        'mode' => 'live',
        'batch_size' => 1,
        'confirmation' => OvokoWooGmailDraftUpdateService::BATCH_LIVE_CONFIRMATION,
    ]);

    gpswiss_assert($result['counters']['blocked'] === 1, 'Missing price should block product.');
    gpswiss_assert($result['counters']['updated'] === 0, 'Blocked product should not be updated.');
    gpswiss_assert($GLOBALS['gpswiss_gmail_writes'] === [], 'Blocked live batch should not write.');
});
