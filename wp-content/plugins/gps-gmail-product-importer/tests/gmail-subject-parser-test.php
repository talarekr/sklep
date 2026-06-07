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

require dirname(__DIR__) . '/gps-gmail-product-importer.php';

$cases = array(
    '2KNS 5Q0131701AN' => array('storage_location' => '2KNS', 'detected_part_code' => '5Q0131701AN', 'normalized_part_code' => '5Q0131701AN'),
    '5Q0131701AN' => array('storage_location' => '', 'detected_part_code' => '5Q0131701AN', 'normalized_part_code' => '5Q0131701AN'),
    'A12 03L131512DQ' => array('storage_location' => 'A12', 'detected_part_code' => '03L131512DQ', 'normalized_part_code' => '03L131512DQ'),
    'MAGAZYN-1 8V0407255A' => array('storage_location' => 'MAGAZYN-1', 'detected_part_code' => '8V0407255A', 'normalized_part_code' => '8V0407255A'),
);

foreach ($cases as $subject => $expected) {
    $actual = GPS_Gmail_Product_Importer::parse_gmail_subject($subject);
    foreach ($expected as $key => $value) {
        if ($actual[$key] !== $value) {
            fwrite(STDERR, sprintf("Subject %s: expected %s=%s, got %s\n", $subject, $key, $value, $actual[$key]));
            exit(1);
        }
    }
    if ($actual['detected_oem_part_number'] !== $expected['detected_part_code'] || $actual['normalized_oem_part_number'] !== $expected['normalized_part_code']) {
        fwrite(STDERR, sprintf("Subject %s: OEM fields did not match detected part code\n", $subject));
        exit(1);
    }
    if ($actual['oem_candidates'] !== array($expected['detected_part_code'])) {
        fwrite(STDERR, sprintf("Subject %s: OEM candidates included unexpected values\n", $subject));
        exit(1);
    }
}

echo "Gmail subject parser tests passed\n";
