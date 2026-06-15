<?php

$root = dirname(__DIR__);
$source = file_get_contents($root . '/src/Services/WooCsvOvokoCarImportSupport.php') ?: '';
$plugin = file_get_contents($root . '/src/Plugin.php') ?: '';
$bootstrap = file_get_contents($root . '/gpswiss-ovoko-integration.php') ?: '';

$failures = [];
$assertContains = static function (string $haystack, string $needle, string $message) use (&$failures): void {
    if (!str_contains($haystack, $needle)) {
        $failures[] = $message . ' Missing: ' . $needle;
    }
};

foreach (['ovoko_car_id', 'car_id', 'donor_car_id', 'vehicle_id', 'ovoko_vehicle_id'] as $alias) {
    $assertContains($source, "'" . $alias . "'", 'Woo CSV Ovoko car ID alias must be supported.');
}
foreach (['car_make', 'car_model', 'car_generation', 'car_year', 'car_engine', 'car_engine_code', 'car_vin', 'car_fuel', 'car_body_type', 'car_gearbox', 'car_mileage'] as $field) {
    $assertContains($source, "'" . $field . "'", 'Optional Woo CSV donor vehicle field must be preserved in legacy payload.');
}

foreach (['woocommerce_csv_product_import_mapping_options', 'woocommerce_csv_product_import_mapping_default_columns', 'woocommerce_product_import_pre_insert_product_object', 'woocommerce_product_import_inserted_product_object'] as $hook) {
    $assertContains($source, $hook, 'WooCommerce CSV import support hook must be registered.');
}

$assertContains($source, "'_ovoko_car_id'", 'Imported part must store raw ovoko_car_id in private product meta.');
$assertContains($source, "'_gps_ovoko_import_legacy_payload'", 'Imported part must store donor vehicle data in traceable legacy payload meta.');
$assertContains($source, "'ovoko_car_not_found'", 'Unknown Ovoko car ID must be reported as a row/product warning.');
if (!str_contains($source, "'ovoko_car_id', 'external_id', 'source_id', 'legacy_id', 'car_id', 'vehicle_id'")) {
    $failures[] = 'Existing car lookup must check ovoko_car_id and legacy/source ID columns.';
}
if (str_contains($source, 'INSERT INTO') || str_contains($source, 'wp_insert_post')) {
    $failures[] = 'Unknown Ovoko car IDs must not silently create car records.';
}
$assertContains($plugin, 'new WooCsvOvokoCarImportSupport()', 'Plugin boot must instantiate Woo CSV Ovoko support.');
$assertContains($bootstrap, 'WooCsvOvokoCarImportSupport.php', 'Plugin bootstrap must load Woo CSV Ovoko support class.');

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

echo "Woo CSV Ovoko car import support checks passed.\n";
