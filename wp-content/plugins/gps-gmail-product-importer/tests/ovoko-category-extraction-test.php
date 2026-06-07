<?php

define('ABSPATH', dirname(__DIR__, 4) . '/');

function add_action() {}
function register_activation_hook() {}
function register_post_type() {}
function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return $text; }
function esc_html_e($text, $domain = null) { echo $text; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($name, $default = false) { return $default; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function wp_json_encode($value, $flags = 0, $depth = 512) { return json_encode($value, $flags, $depth); }
function current_time($type, $gmt = 0) { return '2026-06-07 12:00:00'; }
function wp_kses_post($value) { return (string) $value; }

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

$reflection = new ReflectionClass(GPS_Gmail_Product_Importer::class);
$plugin = $reflection->newInstanceWithoutConstructor();
$analyze = $reflection->getMethod('analyze_ovoko_lookup');
$analyze->setAccessible(true);
$payload = $reflection->getMethod('ovoko_meta_payload');
$payload->setAccessible(true);

function assert_true($condition, $message, $payload = null) {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        if ($payload !== null) { var_export($payload); }
        exit(1);
    }
}

function analyze_ovoko_fixture($plugin, $analyze, $record) {
    return $analyze->invoke($plugin, '5Q0131701AN', array('records' => array($record)));
}

$explicit = analyze_ovoko_fixture($plugin, $analyze, array(
    'id' => '1',
    'name' => 'Free product name should not win',
    'manufacturer_code' => '5Q0131701AN',
    'category_name' => 'Explicit category',
    'category_id' => 'cat-123',
    'category_path' => 'Root > Explicit category',
));
assert_true($explicit['category_name'] === 'Explicit category', 'Explicit category_name should be used when present.', $explicit);
assert_true($explicit['category_id'] === 'cat-123', 'Explicit category_id should be stored alongside category_name.', $explicit);
assert_true($explicit['category_path'] === 'Root > Explicit category', 'Explicit category_path should be stored alongside category_name.', $explicit);
assert_true($explicit['part_category'] === 'Explicit category', 'Explicit category_name should populate part_category.', $explicit);
assert_true(strpos($explicit['raw_category_data'], 'category_name') !== false, 'Raw category data should record explicit category source.', $explicit);

$nested = analyze_ovoko_fixture($plugin, $analyze, array(
    'id' => '2',
    'name' => 'Fallback should not win',
    'manufacturer_code' => '5Q0131701AN',
    'metadata' => array(
        'rrr_category' => array(
            'id' => 'rrr-77',
            'name' => 'Nested category',
            'path' => 'Root > Nested category',
        ),
    ),
));
assert_true($nested['category_id'] === 'rrr-77', 'Nested category object ID should be used.', $nested);
assert_true($nested['category_name'] === 'Nested category', 'Nested category object name should be used.', $nested);
assert_true($nested['category_path'] === 'Root > Nested category', 'Nested category object path should be used.', $nested);

$fallback = analyze_ovoko_fixture($plugin, $analyze, array(
    'id' => '3',
    'name' => 'Name fallback category',
    'manufacturer_code' => '5Q0131701AN',
    'category' => '',
));
assert_true($fallback['category_name'] === 'Name fallback category', 'selected_match.name should be used when explicit category is empty.', $fallback);
assert_true($fallback['part_category'] === 'Name fallback category', 'selected_match.name fallback should populate part_category.', $fallback);
assert_true(strpos($fallback['raw_category_data'], 'selected_match.name') !== false, 'Raw category data should explain selected_match.name fallback.', $fallback);

$idWithNameFallback = analyze_ovoko_fixture($plugin, $analyze, array(
    'id' => '4',
    'name' => 'Category ID with name fallback',
    'manufacturer_code' => '5Q0131701AN',
    'category_id' => '1407',
    'category_name' => '',
    'category_path' => '',
    'part_category' => '',
));
assert_true($idWithNameFallback['category_id'] === '1407', 'Explicit category_id should be preserved when category_name is empty.', $idWithNameFallback);
assert_true($idWithNameFallback['category_name'] === 'Category ID with name fallback', 'selected_match.name should fill category_name when only category_id is explicit.', $idWithNameFallback);
assert_true($idWithNameFallback['part_category'] === 'Category ID with name fallback', 'selected_match.name should fill part_category when only category_id is explicit.', $idWithNameFallback);
assert_true(strpos($idWithNameFallback['raw_category_data'], '"category_id_source":"category_id"') !== false, 'Raw category data should record explicit category_id source.', $idWithNameFallback);
assert_true(strpos($idWithNameFallback['raw_category_data'], '"category_name_source":"selected_match.name fallback"') !== false, 'Raw category data should record category_name selected_match.name fallback source.', $idWithNameFallback);
assert_true(strpos($idWithNameFallback['raw_category_data'], '"part_category_source":"selected_match.name fallback"') !== false, 'Raw category data should record part_category selected_match.name fallback source.', $idWithNameFallback);

$item60849 = analyze_ovoko_fixture($plugin, $analyze, array(
    'id' => '11003',
    'name' => 'Filtr cząstek stałych Katalizator / FAP / DPF',
    'manufacturer_code' => '5Q0131701AN',
    'visible_code' => '5Q0131723',
    'other_code' => '',
    'category_id' => '1407',
    'category_name' => '',
    'category_path' => '',
    'part_category' => '',
    'car' => array(),
));
$expected = 'Filtr cząstek stałych Katalizator / FAP / DPF';
assert_true($item60849['confidence'] === 'high', '60849-style exact single match should remain high confidence.', $item60849);
assert_true($item60849['category_id'] === '1407', '60849-style payload should preserve explicit category_id.', $item60849);
assert_true($item60849['category_name'] === $expected, '60849-style payload should store selected name as category_name.', $item60849);
assert_true($item60849['part_category'] === $expected, '60849-style payload should store selected name as part_category.', $item60849);
assert_true(strpos($item60849['raw_category_data'], '"category_id_source":"category_id"') !== false, '60849-style raw category data should record explicit category_id source.', $item60849);
assert_true(strpos($item60849['raw_category_data'], '"category_name_source":"selected_match.name fallback"') !== false, '60849-style raw category data should record category_name fallback source.', $item60849);
assert_true(strpos($item60849['raw_category_data'], '"part_category_source":"selected_match.name fallback"') !== false, '60849-style raw category data should record part_category fallback source.', $item60849);
assert_true(strpos($item60849['raw_selected_match'], '11003') !== false && strpos($item60849['raw_selected_match'], 'exact_oem_match') === false, 'Raw selected match should contain the full API payload without internal match flags.', $item60849);
$meta = $payload->invoke($plugin, '5Q0131701AN', $item60849);
assert_true($meta['_gps_ovoko_category_id'] === '1407', 'Meta payload should include preserved category_id.', $meta);
assert_true($meta['_gps_ovoko_category_name'] === $expected, 'Meta payload should include normalized category_name.', $meta);
assert_true($meta['_gps_ovoko_part_category'] === $expected, 'Meta payload should include normalized part_category.', $meta);
assert_true(strpos($meta['_gps_ovoko_raw_category_data'], 'selected_match.name') !== false, 'Meta payload raw category data should explain name fallback.', $meta);
assert_true(strpos($meta['_gps_ovoko_raw_selected_match'], '5Q0131723') !== false, 'Meta payload should include full selected match JSON.', $meta);

echo "Ovoko category extraction tests passed\n";
