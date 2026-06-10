<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\OvokoIntegrationService;
use GPSwiss\Ovoko\Services\OvokoProductSyncService;

require_once dirname(__DIR__) . '/src/Services/RrrApiClient.php';
require_once dirname(__DIR__) . '/src/Services/OvokoWooSaleSyncQueue.php';
require_once dirname(__DIR__) . '/src/Services/OvokoProductSyncService.php';
require_once dirname(__DIR__) . '/src/Services/OvokoIntegrationService.php';

$GLOBALS['gpswiss_ovoko_guard_posts'] = [];
$GLOBALS['gpswiss_ovoko_guard_meta'] = [];
$GLOBALS['gpswiss_ovoko_guard_writes'] = [];
$GLOBALS['gpswiss_ovoko_guard_next_insert_id'] = 9001;
$GLOBALS['gpswiss_ovoko_guard_options'] = [];

function gpswiss_guard_reset(): void
{
    $GLOBALS['gpswiss_ovoko_guard_posts'] = [];
    $GLOBALS['gpswiss_ovoko_guard_meta'] = [];
    $GLOBALS['gpswiss_ovoko_guard_writes'] = [];
    $GLOBALS['gpswiss_ovoko_guard_next_insert_id'] = 9001;
    $GLOBALS['gpswiss_ovoko_guard_options'] = [];
}

function gpswiss_guard_seed_product(int $id, string $sku, string $metaKey, string $partId): void
{
    $GLOBALS['gpswiss_ovoko_guard_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Existing Gmail draft'];
    $GLOBALS['gpswiss_ovoko_guard_meta'][$id] = ['_sku' => $sku, $metaKey => $partId];
}

function gpswiss_guard_service(): OvokoIntegrationService
{
    return new OvokoIntegrationService(__FILE__);
}

function gpswiss_guard_record(string $partId): array
{
    return [
        'id' => $partId,
        'part_id' => $partId,
        'name' => 'Incoming Ovoko product',
        'notes' => 'Incoming description',
        'internal_notes' => 'price 123.45 PLN',
        'status' => 'active',
        'updated_at' => '2026-06-10 12:00:00',
    ];
}

function gpswiss_guard_assert(bool $condition, string $message, mixed $context = null): void
{
    if (!$condition) {
        throw new RuntimeException($message . ($context === null ? '' : ' ' . json_encode($context)));
    }
}

function gpswiss_guard_run(string $name, callable $test): void
{
    gpswiss_guard_reset();
    $test();
    echo "PASS {$name}\n";
}

function post_type_exists(string $postType): bool { return $postType === 'product'; }
function get_posts(array $args): array
{
    $ids = [];
    foreach ($GLOBALS['gpswiss_ovoko_guard_posts'] as $id => $post) {
        if (($args['post_type'] ?? '') !== '' && (string) $post->post_type !== (string) $args['post_type']) {
            continue;
        }
        if (($args['post_status'] ?? 'any') !== 'any' && (string) $post->post_status !== (string) $args['post_status']) {
            continue;
        }
        if (isset($args['meta_key']) && (string) ($GLOBALS['gpswiss_ovoko_guard_meta'][$id][$args['meta_key']] ?? '') !== (string) ($args['meta_value'] ?? '')) {
            continue;
        }
        $ids[] = (int) $id;
    }
    sort($ids);
    $limit = (int) ($args['numberposts'] ?? $args['posts_per_page'] ?? 0);
    return ($args['fields'] ?? '') === 'ids' ? ($limit > 0 ? array_slice($ids, 0, $limit) : $ids) : $ids;
}
function get_post_meta(int $id, string $key, bool $single = false): mixed { return $GLOBALS['gpswiss_ovoko_guard_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): bool { $GLOBALS['gpswiss_ovoko_guard_writes'][] = ['update_post_meta', $id, $key, $value]; $GLOBALS['gpswiss_ovoko_guard_meta'][$id][$key] = $value; return true; }
function wp_insert_post(array $postarr, bool $wpError = false): int { $GLOBALS['gpswiss_ovoko_guard_writes'][] = ['wp_insert_post', $postarr]; $id = (int) $GLOBALS['gpswiss_ovoko_guard_next_insert_id']; $GLOBALS['gpswiss_ovoko_guard_posts'][$id] = (object) (['ID' => $id] + $postarr); return $id; }
function wp_update_post(array $postarr): int { $GLOBALS['gpswiss_ovoko_guard_writes'][] = ['wp_update_post', $postarr]; return (int) ($postarr['ID'] ?? 0); }
function wp_set_object_terms(int $id, mixed $terms, string $taxonomy): array { $GLOBALS['gpswiss_ovoko_guard_writes'][] = ['wp_set_object_terms', $id, $terms, $taxonomy]; return (array) $terms; }
function wp_set_post_terms(int $id, array $terms, string $taxonomy, bool $append = false): array { $GLOBALS['gpswiss_ovoko_guard_writes'][] = ['wp_set_post_terms', $id, $terms, $taxonomy, $append]; return $terms; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['gpswiss_ovoko_guard_options'][$key] ?? $default; }
function wp_parse_args(mixed $args, array $defaults = []): array { return array_merge($defaults, is_array($args) ? $args : []); }
function update_option(string $key, mixed $value, bool $autoload = true): bool { $GLOBALS['gpswiss_ovoko_guard_options'][$key] = $value; return true; }
function is_wp_error(mixed $thing): bool { return false; }
function wp_json_encode(mixed $data, int $flags = 0, int $depth = 512): string|false { return json_encode($data, $flags, $depth); }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_textarea_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_title(string $value): string { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-')); }
function esc_url_raw(string $value): string { return trim($value); }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function wc_get_product(int $id): mixed { return null; }
function get_edit_post_link(int $id, string $context = 'display'): string { return 'post.php?post=' . $id; }
function wp_get_post_terms(int $id, string $taxonomy, array $args = []): array { return []; }
function get_post_field(string $field, int $id): string { return (string) ($GLOBALS['gpswiss_ovoko_guard_posts'][$id]->$field ?? ''); }
function get_term(int $id, string $taxonomy): object { return (object) ['term_id' => $id, 'name' => 'Term ' . $id, 'parent' => 0]; }
function get_ancestors(int $id, string $taxonomy): array { return []; }
function wp_get_attachment_url(int $id): string { return ''; }
function download_url(string $url): string { return ''; }
function media_handle_sideload(array $file, int $postId, string $desc = ''): int { return 0; }
function set_post_thumbnail(int $postId, int $thumbnailId): bool { return true; }

gpswiss_guard_run('existing GPS-GMAIL product with _ovoko_part_id is skipped before wp_insert_post', function (): void {
    gpswiss_guard_seed_product(123, 'GPS-GMAIL-123', '_ovoko_part_id', '999');

    $result = gpswiss_guard_service()->apply_manual_live_date_from_part(gpswiss_guard_record('999'), gpswiss_guard_record('999'), [
        'product_id' => 0,
        'price' => ['ok' => true, 'price' => '123.45'],
    ]);

    gpswiss_guard_assert(($result['skip_reason'] ?? '') === 'skipped_existing_gmail_product_by_ovoko_part_id', 'Expected Gmail duplicate skip.', $result);
    gpswiss_guard_assert(($result['created'] ?? true) === false, 'Skipped product must not be created.', $result);
    gpswiss_guard_assert(($result['updated'] ?? true) === false, 'Skipped product must not be updated.', $result);
    foreach ($GLOBALS['gpswiss_ovoko_guard_writes'] as $write) {
        gpswiss_guard_assert($write[0] !== 'wp_insert_post', 'wp_insert_post was called for existing Gmail product.', $GLOBALS['gpswiss_ovoko_guard_writes']);
    }
});

gpswiss_guard_run('existing GPS-GMAIL product with ovoko_part_id is found by guard', function (): void {
    gpswiss_guard_seed_product(124, 'GPS-GMAIL-124', 'ovoko_part_id', '999');

    $guard = (new OvokoProductSyncService())->find_existing_gmail_product_by_ovoko_part_id('999');

    gpswiss_guard_assert(!empty($guard['found']), 'Expected guard to find ovoko_part_id meta.', $guard);
    gpswiss_guard_assert(($guard['matched_meta_key'] ?? '') === 'ovoko_part_id', 'Expected ovoko_part_id meta key.', $guard);
    gpswiss_guard_assert(($guard['skip_reason'] ?? '') === 'skipped_existing_gmail_product_by_ovoko_part_id', 'Expected skip reason.', $guard);
});

gpswiss_guard_run('part_id without existing Gmail product is not blocked by guard', function (): void {
    $guard = (new OvokoProductSyncService())->find_existing_gmail_product_by_ovoko_part_id('1000');

    gpswiss_guard_assert(empty($guard['found']), 'Guard must not block non-existing part_id.', $guard);
    gpswiss_guard_assert(($guard['skip_reason'] ?? '') === '', 'No skip reason expected for non-existing part_id.', $guard);
});
