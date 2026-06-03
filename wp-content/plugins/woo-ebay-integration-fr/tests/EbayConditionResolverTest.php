<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Services/EbayConditionResolver.php';

use WEI_FR\Services\EbayConditionResolver;

$failures = [];

$defaultResolution = EbayConditionResolver::resolve('EBAY_FR', []);
if (($defaultResolution['condition'] ?? '') !== 'USED_EXCELLENT') {
    $failures[] = 'Expected EBAY_FR default inventory item condition to be USED_EXCELLENT, got ' . json_encode($defaultResolution);
}
if (($defaultResolution['source'] ?? '') !== 'default_used_parts') {
    $failures[] = 'Expected default condition source default_used_parts, got ' . json_encode($defaultResolution);
}

$payload = [
    'availability' => ['shipToLocationAvailability' => ['quantity' => 1]],
    'condition' => $defaultResolution['condition'],
    'product' => [
        'title' => 'Test part',
        'description' => 'Test description',
        'imageUrls' => ['https://example.test/image.jpg'],
        'aspects' => ['Stan' => ['Używany']],
    ],
];

if (($payload['condition'] ?? '') !== 'USED_EXCELLENT') {
    $failures[] = 'Expected EBAY_FR export payload condition to be USED_EXCELLENT, got ' . json_encode($payload);
}
if (($payload['product']['aspects']['Stan'][0] ?? '') === 'Używany' && ($payload['condition'] ?? '') !== 'USED_EXCELLENT') {
    $failures[] = 'Payload must not rely only on Stan aspect for item condition.';
}

$invalidResolution = EbayConditionResolver::resolve('EBAY_FR', ['default_item_condition' => 'Neu']);
if (($invalidResolution['condition'] ?? '') !== 'USED_EXCELLENT') {
    $failures[] = 'Expected invalid configured condition to fall back to USED_EXCELLENT, got ' . json_encode($invalidResolution);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'EbayConditionResolver regression tests passed' . PHP_EOL;
