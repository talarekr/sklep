<?php

declare(strict_types=1);

use GPSwiss\Ovoko\Services\WooToOvokoCreatePartPreviewService;

define('ABSPATH', dirname(__DIR__, 4) . '/');

$GLOBALS['gps_test_options'] = array();
$GLOBALS['gps_test_meta'] = array();
$GLOBALS['gps_test_posts'] = array();
$GLOBALS['gps_test_terms'] = array();
$GLOBALS['gps_test_term_meta'] = array();
$GLOBALS['gps_test_attachments'] = array();
$GLOBALS['gps_test_next_product_id'] = 9001;
$GLOBALS['gps_test_valid_product_cat_terms'] = array(5802 => true);

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $GLOBALS['gps_test_options'][$name] ?? $default; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_textarea_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags); }
function current_time($type, $gmt = 0) { return '2026-06-08 10:00:00'; }
function get_current_user_id() { return 42; }
function get_post_meta($id, $key = '', $single = false) { return $GLOBALS['gps_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][(int) $id][$key] = $value; return true; }
function get_post($id) { return $GLOBALS['gps_test_posts'][(int) $id] ?? null; }
function get_post_type($id) { $post = get_post((int) $id); return $post ? $post->post_type : false; }
function get_post_status($id) { $post = get_post((int) $id); return $post ? $post->post_status : false; }
function get_the_title($id) { $post = get_post((int) $id); return $post ? (string) $post->post_title : ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_insert_post($args, $wp_error = false) { $id = $GLOBALS['gps_test_next_product_id']++; $GLOBALS['gps_test_posts'][$id] = (object) ($args + array('ID' => $id)); return $id; }
function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false) { $GLOBALS['gps_test_terms'][] = compact('object_id', 'terms', 'taxonomy', 'append'); return true; }
function taxonomy_exists($taxonomy) { return $taxonomy === 'product_cat' || $taxonomy === 'product_type'; }
function term_exists($term, $taxonomy = '') { return ($taxonomy === 'product_cat' && !empty($GLOBALS['gps_test_valid_product_cat_terms'][(int) $term])) ? array('term_id' => (int) $term, 'term_taxonomy_id' => (int) $term) : null; }
function wp_delete_post($post_id, $force_delete = false) { unset($GLOBALS['gps_test_posts'][$post_id]); return true; }
function has_post_thumbnail($product_id) { return false; }
function get_woocommerce_currency() { return 'PLN'; }
function get_post_thumbnail_id($id) { return (int) get_post_meta((int) $id, '_thumbnail_id', true); }
function wp_get_attachment_url($id) { return (string) ($GLOBALS['gps_test_attachments'][(int) $id] ?? ''); }
function wp_http_validate_url($url) { return str_starts_with((string) $url, 'http://') || str_starts_with((string) $url, 'https://'); }
function wp_get_post_terms($id, $taxonomy) { return $taxonomy === 'product_cat' ? array((object) array('term_id' => 5802, 'name' => 'DPF', 'slug' => 'dpf')) : array(); }
function get_term_meta($term_id, $key, $single = false) { return $GLOBALS['gps_test_term_meta'][(int) $term_id][$key] ?? ''; }
function get_posts($args) {
    $results = array();
    foreach ($GLOBALS['gps_test_posts'] as $id => $post) {
        $post_types = (array) ($args['post_type'] ?? array());
        if ($post_types && !in_array($post->post_type ?? '', $post_types, true)) { continue; }
        if (in_array((int) $id, (array) ($args['exclude'] ?? array()), true) || in_array((int) $id, (array) ($args['post__not_in'] ?? array()), true)) { continue; }
        $matches = true;
        foreach ((array) ($args['meta_query'] ?? array()) as $condition) {
            if (!is_array($condition) || !isset($condition['key'])) { continue; }
            $value = $GLOBALS['gps_test_meta'][(int) $id][$condition['key']] ?? '';
            $compare = $condition['compare'] ?? '=';
            if ($compare === 'EXISTS' && $value === '') { $matches = false; }
            if ($compare === '=' && (string) $value !== (string) ($condition['value'] ?? '')) { $matches = false; }
        }
        if ($matches) { $results[] = (int) $id; }
    }
    return array_slice($results, 0, (int) ($args['posts_per_page'] ?? 10));
}
function wc_get_product_id_by_sku($sku) {
    foreach ($GLOBALS['gps_test_meta'] as $id => $meta) {
        if (($meta['_sku'] ?? '') === $sku) { return (int) $id; }
    }
    return 0;
}
function wc_get_product($id = null) { return isset($GLOBALS['gps_test_posts'][(int) $id]) ? new GPS_Gmail_Test_Product((int) $id) : null; }

class GPS_Gmail_Test_Product
{
    public function __construct(private int $id) {}
    public function get_sku(): string { return (string) get_post_meta($this->id, '_sku', true); }
    public function get_price(): string { return (string) get_post_meta($this->id, '_price', true); }
    public function get_regular_price(): string { return (string) get_post_meta($this->id, '_regular_price', true); }
    public function get_sale_price(): string { return (string) get_post_meta($this->id, '_sale_price', true); }
    public function get_stock_status(): string { return (string) get_post_meta($this->id, '_stock_status', true); }
    public function get_stock_quantity(): ?int { $raw = get_post_meta($this->id, '_stock', true); return $raw === '' ? null : (int) $raw; }
    public function get_image_id(): int { return (int) get_post_meta($this->id, '_thumbnail_id', true); }
    public function get_gallery_image_ids(): array { return array(); }
    public function get_description(): string { $post = get_post($this->id); return $post ? (string) $post->post_content : ''; }
}

class WP_Error
{
    private string $message;
    public function __construct($code = '', $message = '', $data = null) { $this->message = (string) $message; }
    public function get_error_message() { return $this->message; }
}

require dirname(__DIR__) . '/gps-gmail-product-importer.php';
require_once dirname(__DIR__, 2) . '/gpswiss-ovoko-integration/src/Services/WooToOvokoCreatePartPreviewService.php';

$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS] = GPS_Gmail_Product_Importer::default_settings();
$GLOBALS['gps_test_options'][GPS_Gmail_Product_Importer::OPTION_SETTINGS]['import_images'] = 0;
$GLOBALS['gps_test_term_meta'][5802]['_ovoko_category_id'] = '1407';
$GLOBALS['gps_test_attachments'][501] = 'https://example.test/501.jpg';

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$createDraft = $reflection->getMethod('create_woo_draft_from_staging_item');
$createDraft->setAccessible(true);

function gps_assert($condition, $message, $payload = null): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        if ($payload !== null) { var_export($payload); }
        exit(1);
    }
}

function gps_seed_ready_staging_item(int $id): void {
    $GLOBALS['gps_test_posts'][$id] = (object) array('ID' => $id, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Staging ' . $id, 'post_content' => 'Body');
    $meta = array(
        '_gps_staging_item_id' => $id,
        '_gps_gmail_message_id' => 'gmail-' . $id,
        '_gps_gmail_thread_id' => 'thread-' . $id,
        '_gps_gmail_date' => '2026-06-07',
        '_gps_gmail_from' => 'sender@example.com',
        '_gps_gmail_subject' => 'DPF 5Q0131701AN',
        '_gps_gmail_label' => 'Woo import',
        '_gps_body' => 'Body',
        '_gps_storage_location' => 'A1',
        '_gps_detected_part_code' => '5Q0131701AN',
        '_gps_normalized_part_code' => '5Q0131701AN',
        '_gps_detected_oem_part_number' => '5Q0131701AN',
        '_gps_normalized_oem_part_number' => '5Q0131701AN',
        '_gps_oem_candidates' => array(),
        '_gps_detected_vehicle_make' => 'VW',
        '_gps_detected_vehicle_model' => 'Golf',
        '_gps_detected_vehicle_confidence' => 'high',
        '_gps_gmail_import_image_count' => 2,
        '_gps_gmail_import_attachment_set_hash' => 'hash',
        '_gps_gmail_images_metadata' => json_encode(array(array('attachment_id' => 'img-1'))),
        '_gps_gmail_warnings' => '[]',
        '_gps_ovoko_enrichment_status' => 'suggested',
        '_gps_ovoko_category_id' => '1407',
        '_gps_ovoko_category_name' => 'DPF',
        '_gps_ovoko_category_path' => 'Exhaust > DPF',
        '_gps_ovoko_part_category' => 'Catalyst',
        '_gps_category_mapping_status' => 'mapped',
        '_gps_suggested_woo_category_id' => 5802,
        '_gps_suggested_woo_category_path' => 'Filtr cząstek stałych Katalizator / FAP / DPF',
        '_gps_suggested_woo_category_confidence' => 'medium',
        '_gps_suggested_category_source' => 'ovoko_enrichment',
        '_gps_shipping_group' => 'shipping_30',
        '_gps_manual_price_override_enabled' => '1',
        '_gps_manual_price_pln' => '1000',
        '_gps_gmail_created_product_id' => 0,
        '_gps_staging_status' => 'imported_from_gmail',
    );
    $GLOBALS['gps_test_meta'][$id] = $meta;
}

gps_seed_ready_staging_item(60849);
$created = $createDraft->invoke($plugin, 60849);
$productId = (int) ($created['created_product_id'] ?? 0);
gps_assert($created['result'] === 'created_product' && $productId > 0, 'Woo draft should be created from ready staging item.', $created);
gps_assert(($GLOBALS['gps_test_meta'][$productId]['_sku'] ?? '') === 'GPS-GMAIL-60849', 'Created draft should store the staging-based Woo _sku.', $GLOBALS['gps_test_meta'][$productId] ?? array());
gps_assert(wc_get_product($productId)->get_sku() === 'GPS-GMAIL-60849', 'Created draft WC product get_sku() should return generated SKU.');
gps_assert(($GLOBALS['gps_test_meta'][$productId]['_gps_generated_sku'] ?? '') === 'GPS-GMAIL-60849' && ($GLOBALS['gps_test_meta'][$productId]['_gps_sku_source'] ?? '') === 'gmail_staging_item', 'Created draft should store generated SKU provenance meta.', $GLOBALS['gps_test_meta'][$productId] ?? array());

$GLOBALS['gps_test_posts'][7000] = (object) array('ID' => 7000, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'Existing product');
$GLOBALS['gps_test_meta'][7000]['_sku'] = 'GPS-GMAIL-60850';
gps_seed_ready_staging_item(60850);
$duplicateCreated = $createDraft->invoke($plugin, 60850);
$duplicateProductId = (int) ($duplicateCreated['created_product_id'] ?? 0);
gps_assert($duplicateCreated['result'] === 'created_product' && $duplicateProductId > 0, 'Woo draft should still be created when the base SKU is taken.', $duplicateCreated);
gps_assert(($GLOBALS['gps_test_meta'][$duplicateProductId]['_sku'] ?? '') === 'GPS-GMAIL-60850-2', 'Duplicate base SKU should receive a safe numeric suffix.', $GLOBALS['gps_test_meta'][$duplicateProductId] ?? array());
gps_assert(($GLOBALS['gps_test_meta'][$duplicateProductId]['_gps_generated_sku'] ?? '') === 'GPS-GMAIL-60850', 'Duplicate SKU product should keep the base generated SKU meta.', $GLOBALS['gps_test_meta'][$duplicateProductId] ?? array());

update_post_meta($productId, '_thumbnail_id', 501);
update_post_meta($productId, '_ovoko_car_id', 9002);
$preview = (new WooToOvokoCreatePartPreviewService())->preview($productId);
$codes = array_map(static fn(array $row): string => (string) $row['code'], (array) $preview['validations']);
gps_assert(!in_array('missing_sku', $codes, true), 'Ovoko preview should not report missing_sku for a Gmail-created draft.', $preview);
gps_assert($preview['would_be_eligible'] === true, 'Ovoko preview should be eligible after Gmail draft SKU generation.', $preview);
gps_assert(($preview['proposed_payload']['sku'] ?? '') === 'GPS-GMAIL-60849', 'Ovoko proposed payload should include the generated Gmail SKU.', $preview);

echo "Woo draft SKU generation tests passed\n";
