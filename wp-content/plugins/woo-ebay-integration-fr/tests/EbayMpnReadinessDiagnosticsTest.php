<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$serviceSource = file_get_contents($root . '/src/Services/VehicleCompatibilityAuditService.php') ?: '';
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($adapterSource, 'mpn_oe_readiness'), 'Preflight must return MPN/OE readiness diagnostics.');
$assert(str_contains($adapterSource, 'detected_manufacturer_part_number'), 'MPN diagnostics must include detected manufacturer part number.');
$assert(str_contains($adapterSource, "'source_type' => 'attribute'") && str_contains($adapterSource, "'source_key' => \$attributeName"), 'MPN diagnostics must report source field/meta/attribute, including Woo attributes such as Numer części.');
$assert(str_contains($adapterSource, 'Herstellernummer') && str_contains($adapterSource, 'Manufacturer Part Number'), 'Final aspects must map MPN to eBay item specific aliases.');
$assert(str_contains($adapterSource, 'present_in_final_item_specifics_payload'), 'MPN diagnostics must report whether MPN is in final eBay item specifics payload.');
$assert(str_contains($adapterSource, 'missing_manufacturer_part_number_item_specific'), 'Missing MPN must be reported as a separate item-specific/aspect issue.');
$assert(str_contains($adapterSource, "'vehicle_compatibility_readiness_note'") && str_contains($adapterSource, 'does not block publish'), 'Publish readiness must explicitly avoid blocking on KType/ePID compatibility enhancement.');
$assert(!str_contains($adapterSource, 'missing_ktype'), 'Publish readiness code must not block on compatibility_status = missing_ktype.');
$assert(str_contains($serviceSource, 'compatibility_enhancement_missing'), 'Vehicle audit must rename missing KType/ePID to compatibility_enhancement_missing.');
$assert(str_contains($serviceSource, "'called_ebay_api' => false") && str_contains($serviceSource, "'updated_ebay_listing' => false"), 'Vehicle compatibility audit must remain read-only and declare no API/listing changes.');
foreach (['create_offer', 'publish_offer', 'update_offer', 'bulk_update_price_quantity', 'revise'] as $forbidden) {
    $assert(!str_contains($serviceSource, $forbidden), 'Vehicle audit service must not publish/revise/call mutating eBay methods: ' . $forbidden);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "eBay MPN readiness diagnostics tests passed\n";
