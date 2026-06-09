<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
$GLOBALS['gps_test_posts'] = [];
$GLOBALS['gps_test_meta'] = [];
$GLOBALS['gps_test_options'] = [];
$GLOBALS['gps_test_transients'] = [];
$GLOBALS['gps_test_next_post_id'] = 1000;
$GLOBALS['gps_test_messages'] = [];
$GLOBALS['gps_test_mark_read_calls'] = [];
$GLOBALS['gps_test_mark_read_fail'] = false;
$GLOBALS['gps_test_insert_error'] = false;

function add_action(...$args): void {}
function register_activation_hook(...$args): void {}
function register_post_type(...$args): void {}
function add_menu_page(...$args): void {}
function register_setting(...$args): void {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_textarea_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_title($value) { return sanitize_key(str_replace(' ', '-', (string) $value)); }
function sanitize_file_name($value) { return basename((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_parse_args($args, $defaults = []) { return array_merge((array) $defaults, (array) $args); }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function wp_kses_post($value) { return (string) $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_trim_words($text, $num_words = 55, $more = null) { $words = preg_split('/\s+/', trim(wp_strip_all_tags((string) $text))); if (!$words || $words === ['']) { return ''; } return implode(' ', array_slice($words, 0, $num_words)) . (count($words) > $num_words ? (string) $more : ''); }
function current_time($type, $gmt = false) { return '2026-06-09 00:00:00'; }
function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) { return str_repeat('a', (int) $length); }
function get_option($key, $default = false) { return $GLOBALS['gps_test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['gps_test_options'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['gps_test_transients'][$key] ?? false; }
function set_transient($key, $value, $expiration = 0) { $GLOBALS['gps_test_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['gps_test_transients'][$key]); return true; }
function add_query_arg($args, $url = '') { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
function trailingslashit($path) { return rtrim((string) $path, '/\\') . '/'; }
function wp_upload_dir() { $base = sys_get_temp_dir() . '/gps-gmail-mark-read-test'; return ['basedir' => $base]; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function taxonomy_exists($taxonomy) { return false; }
function get_term_by(...$args) { return false; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error { private $code; private $message; private $data; public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }

function get_post_meta($id, $key = '', $single = false) { if ($key === '') { return $GLOBALS['gps_test_meta'][(int) $id] ?? []; } return $GLOBALS['gps_test_meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['gps_test_meta'][(int) $id][$key] = $value; return true; }
function get_post($id) { return $GLOBALS['gps_test_posts'][(int) $id] ?? null; }
function get_posts($args) {
    $out = [];
    foreach ($GLOBALS['gps_test_posts'] as $id => $post) {
        if (!empty($args['post_type']) && $post->post_type !== $args['post_type']) { continue; }
        $ok = true;
        foreach (($args['meta_query'] ?? []) as $clause) {
            if (($GLOBALS['gps_test_meta'][(int) $id][$clause['key']] ?? '') !== $clause['value']) { $ok = false; break; }
        }
        if ($ok) { $out[] = !empty($args['fields']) && $args['fields'] === 'ids' ? (int) $id : $post; }
    }
    return array_slice($out, 0, (int) ($args['posts_per_page'] ?? count($out)));
}
function wp_insert_post($postarr, $wp_error = false) {
    if ($GLOBALS['gps_test_insert_error']) { return new WP_Error('insert_failed', 'Insert failed.'); }
    $id = $GLOBALS['gps_test_next_post_id']++;
    $GLOBALS['gps_test_posts'][$id] = (object) ['ID' => $id, 'post_type' => $postarr['post_type'], 'post_status' => $postarr['post_status'] ?? 'private', 'post_title' => $postarr['post_title'] ?? '', 'post_content' => $postarr['post_content'] ?? '', 'post_excerpt' => $postarr['post_excerpt'] ?? ''];
    return $id;
}
function wp_update_post($postarr, $wp_error = false) {
    $id = (int) $postarr['ID'];
    if (empty($GLOBALS['gps_test_posts'][$id])) { return new WP_Error('missing_post', 'Post missing.'); }
    foreach (['post_title', 'post_content', 'post_excerpt', 'post_status'] as $key) { if (isset($postarr[$key])) { $GLOBALS['gps_test_posts'][$id]->$key = $postarr[$key]; } }
    return $id;
}
function wp_remote_request($url, $args = []) {
    if (str_ends_with($url, '/labels')) { return ['response' => ['code' => 200], 'body' => json_encode(['labels' => [['id' => 'Label_1', 'name' => 'Woo import']]])]; }
    if (str_contains($url, '/messages?')) { return ['response' => ['code' => 200], 'body' => json_encode(['resultSizeEstimate' => count($GLOBALS['gps_test_messages']), 'messages' => array_map(fn($id) => ['id' => $id], array_keys($GLOBALS['gps_test_messages']))])]; }
    if (preg_match('~/messages/([^/?]+)/modify$~', $url, $m)) { $GLOBALS['gps_test_mark_read_calls'][] = ['id' => urldecode($m[1]), 'args' => $args]; return $GLOBALS['gps_test_mark_read_fail'] ? ['response' => ['code' => 500], 'body' => json_encode(['error' => 'boom'])] : ['response' => ['code' => 200], 'body' => '{}']; }
    if (preg_match('~/messages/([^/?]+)~', $url, $m)) { return ['response' => ['code' => 200], 'body' => json_encode($GLOBALS['gps_test_messages'][urldecode($m[1])] ?? [])]; }
    return ['response' => ['code' => 404], 'body' => '{}'];
}
function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }

require_once dirname(__DIR__) . '/gps-gmail-product-importer.php';

function tassert(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function call_private($object, string $method, array $args = []) { $ref = new ReflectionMethod($object, $method); $ref->setAccessible(true); return $ref->invokeArgs($object, $args); }
function reset_env(array $settings = []): void {
    $GLOBALS['gps_test_posts'] = [];
    $GLOBALS['gps_test_meta'] = [];
    $GLOBALS['gps_test_transients'] = [];
    $GLOBALS['gps_test_next_post_id'] = 1000;
    $GLOBALS['gps_test_mark_read_calls'] = [];
    $GLOBALS['gps_test_mark_read_fail'] = false;
    $GLOBALS['gps_test_insert_error'] = false;
    $GLOBALS['gps_test_options'] = [GPS_Gmail_Product_Importer::OPTION_TOKENS => ['access_token' => 'token', 'expires_at' => time() + 3600], GPS_Gmail_Product_Importer::OPTION_SETTINGS => array_merge(GPS_Gmail_Product_Importer::default_settings(), ['message_status_filter' => 'unread', 'gmail_label' => 'Woo import', 'mark_gmail_read_after_staging' => 1], $settings)];
}
function seed_message(string $id): void { $GLOBALS['gps_test_messages'] = [$id => ['id' => $id, 'threadId' => 'thread-' . $id, 'labelIds' => ['UNREAD', 'Label_1'], 'payload' => ['headers' => [['name' => 'Subject', 'value' => '17KNS 06K145654L'], ['name' => 'Date', 'value' => 'Tue'], ['name' => 'From', 'value' => 'sender@example.com']]]]]; }
function run_case(string $name, callable $test): void { reset_env(); seed_message('msg-1'); $test(GPS_Gmail_Product_Importer::instance()); echo "PASS {$name}\n"; }

run_case('dry-run does not call Gmail mark-read', function ($plugin): void {
    $state = call_private($plugin, 'process_batch', [true, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 0, 'Dry-run must not modify Gmail.');
    tassert(($state['last_batch_result'][0]['gmail_mark_read_status'] ?? '') === 'not_attempted', 'Dry-run status should stay not_attempted.');
});
run_case('successful new staging removes UNREAD', function ($plugin): void {
    $state = call_private($plugin, 'process_batch', [false, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 1, 'Expected one Gmail modify call.');
    tassert($GLOBALS['gps_test_mark_read_calls'][0]['id'] === 'msg-1', 'Expected message msg-1 marked read.');
    tassert(json_decode($GLOBALS['gps_test_mark_read_calls'][0]['args']['body'], true)['removeLabelIds'] === ['UNREAD'], 'Expected UNREAD removal.');
    tassert($state['total_marked_read'] === 1 && $state['messages_marked_read'] === 1, 'Expected marked-read totals.');
});
run_case('successful staging update removes UNREAD', function ($plugin): void {
    $GLOBALS['gps_test_posts'][222] = (object) ['ID' => 222, 'post_type' => GPS_Gmail_Product_Importer::STAGING_POST_TYPE, 'post_status' => 'private', 'post_title' => 'old', 'post_content' => 'old'];
    $GLOBALS['gps_test_meta'][222]['_gps_gmail_message_id'] = 'msg-1';
    $state = call_private($plugin, 'process_batch', [false, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 1, 'Update should mark read.');
    tassert($state['total_stage_updated'] === 1, 'Expected staging update.');
});
run_case('duplicate existing imported product removes UNREAD', function ($plugin): void {
    $GLOBALS['gps_test_posts'][333] = (object) ['ID' => 333, 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'product'];
    $GLOBALS['gps_test_meta'][333]['_gps_gmail_import_message_id'] = 'msg-1';
    $state = call_private($plugin, 'process_batch', [false, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 1, 'Duplicate-safe path should mark read.');
    tassert(($state['last_batch_result'][0]['duplicate_status'] ?? '') === 'duplicate_message_id', 'Expected duplicate message status.');
});
run_case('technical staging error does not remove UNREAD', function ($plugin): void {
    $GLOBALS['gps_test_insert_error'] = true;
    $state = call_private($plugin, 'process_batch', [false, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 0, 'Staging error must not mark read.');
    tassert($state['messages_left_unread_due_to_errors'] === 1, 'Expected left-unread error count.');
});
run_case('Gmail mark-read failure adds warning but preserves staged item', function ($plugin): void {
    $GLOBALS['gps_test_mark_read_fail'] = true;
    $state = call_private($plugin, 'process_batch', [false, 1]);
    tassert(count($GLOBALS['gps_test_mark_read_calls']) === 1, 'Expected failed Gmail modify attempt.');
    tassert($state['total_staged'] === 1, 'Staged item should be preserved.');
    tassert($state['total_mark_read_failed'] === 1, 'Expected mark-read failure total.');
    tassert(in_array('gmail_mark_read_failed', $state['warnings'], true), 'Expected gmail_mark_read_failed warning.');
    tassert(($state['last_batch_result'][0]['gmail_mark_read_status'] ?? '') === 'gmail_mark_read_failed', 'Expected per-message failure status.');
});
