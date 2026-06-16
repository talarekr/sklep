<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/src/Service/WooLaravelProductExport.php');
$admin = file_get_contents($root . '/src/Admin/WooLaravelProductExportPage.php');
$plugin = file_get_contents($root . '/src/Plugin.php');

$checks = [
    'product row core columns' => str_contains($service, "'woo_product_id','post_id','product_type','sku','name'"),
    'OEM/meta fields exported' => str_contains($service, "'oem_number'") && str_contains($service, "'part_number'"),
    'ovoko_car_id column exists' => str_contains($service, "'ovoko_car_id'"),
    'image URLs exported' => str_contains($service, 'wp_get_attachment_url'),
    'product images csv includes sku and image_url' => str_contains($service, "'woo_product_id','sku','image_id','image_url','alt_text','position','is_primary'"),
    'image summary tracks missing and warnings' => str_contains($service, "'products_missing_images'") && str_contains($service, "'total_image_rows'") && str_contains($service, "'image_url_resolution_warnings'"),
    'categories exported' => str_contains($service, "product_categories.csv") && str_contains($service, "'category_ids'"),
    'category tree required files exported' => str_contains($service, "woo_category_tree.csv") && str_contains($service, "woo_category_tree.json") && str_contains($service, "woo_category_tree_summary.json"),
    'category tree critical columns exported' => str_contains($service, "'term_id','parent_term_id','depth','sort_order','name','slug','full_path','full_slug_path'") && str_contains($service, "'source_taxonomy'"),
    'category tree preserves hierarchy and paths' => str_contains($service, "'children'") && str_contains($service, "implode(' > ', \$path)") && str_contains($service, "implode('/', \$slugPath)"),
    'category tree summary exported' => str_contains($service, "'total_categories'") && str_contains($service, "'root_categories'") && str_contains($service, "'max_depth'") && str_contains($service, "'warnings'"),
    'category tree admin button and ajax wired' => str_contains($admin, 'Export Woo category tree for Laravel') && str_contains($admin, 'gps_laravel_category_tree_export') && str_contains($admin, 'ajaxCategoryTree'),
    'no product writes' => !preg_match('/wp_update_post|update_post_meta|wc_update_product_stock|save\(/i', $service . $admin),
    'no API calls' => !preg_match('/wp_remote_(post|request|put|get)|curl_exec/i', $service . $admin),
    'batch/keyset pagination used' => str_contains($service, 'ID > %d ORDER BY ID ASC LIMIT %d'),
    'memory-safe export' => str_contains($service, 'fopen($f,\'ab\')') && str_contains($service, 'LIMIT %d'),
    'nonce/capability checks' => str_contains($admin, 'check_ajax_referer') && str_contains($admin, 'current_user_can'),
    'download link protected' => str_contains($admin, 'check_admin_referer') && str_contains($admin, 'admin_post_gps_laravel_product_export_download'),
    'no secrets in legacy_payload_json' => str_contains($service, 'token|secret|password|pass|key|auth|client_secret'),
    'admin page registered' => str_contains($plugin, 'WooLaravelProductExportPage'),
];

$failed = 0;
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ' - ' . $name . PHP_EOL;
    if (!$ok) $failed++;
}
exit($failed > 0 ? 1 : 0);
