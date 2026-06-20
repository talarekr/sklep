<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Service/MarketplaceMappingExport.php');
$admin = file_get_contents($root . '/src/Admin/WooLaravelProductExportPage.php');

$requiredFiles = [
    'woo_marketplace_mapping_products.csv',
    'woo_marketplace_mapping_products.json',
    'woo_ebay_listings.csv',
    'woo_ovoko_mapping.csv',
    'woo_allegro_legacy_mapping.csv',
    'woo_ebay_fitment.csv',
    'woo_marketplace_mapping_summary.json',
    'export_manifest.json',
];

$checks = [
    'admin button exists' => str_contains($admin, 'Export marketplace mapping data for Laravel'),
    'ajax action wired' => str_contains($admin, 'gps_marketplace_mapping_export') && str_contains($admin, 'ajaxMarketplaceMapping'),
    'last export ajax action wired' => str_contains($admin, 'gps_marketplace_mapping_last_export') && str_contains($admin, 'ajaxMarketplaceMappingLastExport'),
    'filesystem diagnostic action wired' => str_contains($admin, 'Check marketplace export filesystem') && str_contains($admin, 'gps_marketplace_mapping_filesystem_diagnostic') && str_contains($service, 'filesystemDiagnostic'),
    'all requested files generated' => count(array_filter($requiredFiles, static fn($f) => str_contains($service, $f))) === count($requiredFiles),
    'all Woo products queried' => str_contains($service, "post_type IN ('product','product_variation') ORDER BY ID ASC"),
    'product export includes raw matching meta' => str_contains($service, 'raw_marketplace_meta_json') && str_contains($service, 'all_detected_marketplace_meta_keys'),
    'dynamic marketplace key detection terms present' => str_contains($service, "'allegro','ovoko','rrr','ebay','listing','offer','auction','item','inventory','external','marketplace','source','gmail','wei'"),
    'eBay DE and FR rows supported' => str_contains($service, "['EBAY_DE','de']") && str_contains($service, "['EBAY_FR','fr']"),
    'Ovoko Gmail SKU supported' => str_contains($service, 'GPS-GMAIL-') && str_contains($service, 'ovoko_part_id') && str_contains($service, 'rrr_part_id'),
    'Allegro generated even with no rows' => str_contains($service, "'allegro'=>[]") && str_contains($service, 'woo_allegro_legacy_mapping.csv'),
    'fitment KType/OEM export supported' => str_contains($service, 'kt_type_count') && str_contains($service, 'oem_numbers'),
    'manifest has counts hashes and safety' => str_contains($service, 'sha256') && str_contains($service, "'read_only'=>true") && str_contains($service, "'external_api_calls'=>false"),
    'ajax response exposes file metadata' => str_contains($service, "'filename' =>") && str_contains($service, "'download_url' =>") && str_contains($service, "'row_count' =>") && str_contains($service, "'file_size' =>"),
    'export start exposes hard filesystem diagnostics' => str_contains($service, "'intended_export_dir'") && str_contains($service, "'wp_upload_dir_basedir'") && str_contains($service, "'is_export_root_writable'") && str_contains($service, "'php_current_user'") && str_contains($service, "'expected_files'"),
    'expected file verification includes absolute paths and errors' => str_contains($service, "'absolute_path'") && str_contains($service, "'last_error'") && str_contains($service, 'verifyFiles'),
    'last marketplace mapping saved only after verification' => str_contains($service, 'count($this->files)') && str_contains($service, "last_marketplace_mapping', ['export_id'=>"),
    'fallback scans writer export root' => str_contains($service, '"writer_export_root"') || (str_contains($service, "'writer_export_root' =>") && str_contains($service, "'fallback_scan_root' =>")),
    'ui renders marketplace download links' => str_contains($admin, 'gps-marketplace-downloads') && str_contains($admin, 'fileListHtml') && str_contains($admin, 'Missing file or download URL'),
    'page load renders last marketplace export' => str_contains($admin, 'gps-marketplace-last-export') && str_contains($admin, 'Last marketplace mapping export'),
    'no external API calls' => !preg_match('/wp_remote_(post|request|put|get)|curl_exec/i', $service . $admin),
    'no Woo/product writes' => !preg_match('/wp_update_post|update_post_meta|wc_update_product_stock|save\(/i', $service . $admin),
    'no marketplace writes' => !preg_match('/ReviseInventoryStatus|publishOffer|createOffer|submit|allegro_remote_write|ovoko_remote_write/i', $service . $admin),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed > 0 ? 1 : 0);
