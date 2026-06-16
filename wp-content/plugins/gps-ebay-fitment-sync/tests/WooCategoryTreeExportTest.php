<?php

declare(strict_types=1);

use GPS_Ebay_Fitment_Sync\Service\WooLaravelProductExport;

const ARRAY_A = 'ARRAY_A';
$GLOBALS['gps_terms'] = [
    (object) ['term_id' => 3, 'parent' => 2, 'name' => 'Subchild', 'slug' => 'subchild', 'count' => 0, 'description' => 'Sub'],
    (object) ['term_id' => 1, 'parent' => 0, 'name' => 'Root', 'slug' => 'root', 'count' => 5, 'description' => 'Root desc'],
    (object) ['term_id' => 2, 'parent' => 1, 'name' => 'Child', 'slug' => 'child', 'count' => 1, 'description' => 'Child desc'],
];
$GLOBALS['gps_options'] = [];
$GLOBALS['gps_term_meta'] = [1 => ['order' => 1, 'display_type' => 'products', 'thumbnail_id' => 10, '_wei_ebay_category_id' => '179753', '_wei_ebay_category_name' => 'Engine Mounts', '_wei_ebay_category_path' => 'Vehicle Parts & Accessories > Car Parts > Engine Mounts'], 2 => ['order' => 1], 3 => ['order' => 1]];

function wp_generate_password($length, $special_chars = true, $extra_special_chars = false): string { return 'abc123'; }
function wp_upload_dir(): array { return ['basedir' => sys_get_temp_dir() . '/gps-category-tree-test']; }
function trailingslashit($value): string { return rtrim((string) $value, '/') . '/'; }
function sanitize_key($key): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function wp_mkdir_p($dir): bool { return is_dir($dir) || mkdir($dir, 0777, true); }
function get_terms($args): array { return $GLOBALS['gps_terms']; }
function is_wp_error($thing): bool { return false; }
function get_term_meta($termId, $key, $single = true) { return (string) ($GLOBALS['gps_term_meta'][(int) $termId][$key] ?? ''); }
function wp_get_attachment_url($id) { return $id === 10 ? 'https://example.test/thumb.jpg' : false; }
function has_filter($hook): bool { return false; }
function wp_json_encode($data, $flags = 0, $depth = 512): string { return json_encode($data, $flags, $depth); }
function update_option($option, $value, $autoload = null): bool { $GLOBALS['gps_options'][$option] = $value; return true; }
function get_option($option, $default = false) { return $GLOBALS['gps_options'][$option] ?? $default; }

require_once dirname(__DIR__) . '/src/Service/WooLaravelProductExport.php';

$exporter = new WooLaravelProductExport();
$result = $exporter->exportCategoryTree();
$state = array_values($GLOBALS['gps_options'])[0];
$csv = array_map('str_getcsv', file($state['files']['woo_category_tree_csv']));
$json = json_decode(file_get_contents($state['files']['woo_category_tree_json']), true);
$summary = json_decode(file_get_contents($state['files']['woo_category_tree_summary']), true);
$header = $csv[0];
$rootRow = array_combine($header, $csv[1]);
$childRow = array_combine($header, $csv[2]);

$checks = [
    'summary count equals exported product_cat terms' => ($summary['total_categories'] ?? null) === 3,
    'summary categories_total equals exported product_cat terms' => ($summary['categories_total'] ?? null) === 3,
    'root count is one' => ($summary['root_categories'] ?? null) === 1,
    'max depth is two' => ($summary['max_depth'] ?? null) === 2,
    'CSV child appears after parent' => $csv[1][0] === '1' && $csv[2][0] === '2' && $csv[3][0] === '3',
    'full_path is Root > Child > Subchild' => $csv[3][6] === 'Root > Child > Subchild',
    'full_slug_path is root/child/subchild' => $csv[3][7] === 'root/child/subchild',
    'JSON has nested children arrays' => ($json[0]['children'][0]['children'][0]['term_id'] ?? null) === 3,
    'thumbnail URL exported' => $csv[1][12] === 'https://example.test/thumb.jpg',
    'CSV contains ebay_category_id column' => in_array('ebay_category_id', $header, true),
    'CSV exports known eBay category id from term_meta' => ($rootRow['ebay_category_id'] ?? '') === '179753',
    'CSV exports eBay mapping source meta key' => ($rootRow['ebay_mapping_source'] ?? '') === 'term_meta:_wei_ebay_category_id',
    'JSON contains ebay_category_id for each category node' => array_key_exists('ebay_category_id', $json[0]) && array_key_exists('ebay_category_id', $json[0]['children'][0]) && ($json[0]['ebay_category_id'] ?? '') === '179753',
    'summary counts categories with eBay mapping' => ($summary['categories_with_ebay_category_id'] ?? null) === 1,
    'summary counts categories without eBay mapping' => ($summary['categories_without_ebay_category_id'] ?? null) === 2,
    'summary counts unique eBay category ids' => ($summary['ebay_category_id_unique_count'] ?? null) === 1,
    'summary reports missing mapping term ids' => ($summary['missing_ebay_category_mapping_term_ids'] ?? []) === [2, 3],
    'unmapped child source is empty' => ($childRow['ebay_mapping_source'] ?? '') === 'empty',
    'export result exposes required files' => in_array('woo_category_tree.csv', $result['files'], true) && in_array('woo_category_tree.json', $result['files'], true) && in_array('woo_category_tree_summary.json', $result['files'], true),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed > 0 ? 1 : 0);
