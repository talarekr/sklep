<?php

declare(strict_types=1);

if (!function_exists('absint')) {
    function absint($maybeint): int
    {
        return abs((int) $maybeint);
    }
}

require_once __DIR__ . '/../src/Services/AdminPage.php';

use WEI_FR\Services\AdminPage;

$failures = [];

$categoryIds100 = [162, 137, 136];
$categoryIds50 = [];

$adminPage = (new ReflectionClass(AdminPage::class))->newInstanceWithoutConstructor();
$countMethod = new ReflectionMethod(AdminPage::class, 'count_products_for_shipping_mapping');
$countMethod->setAccessible(true);

$estimated50 = $countMethod->invokeArgs($adminPage, [$categoryIds50, $categoryIds100, true]);
if ($estimated50 !== 0) {
    $failures[] = 'Expected estimated_50_eur to be 0 when 100 EUR category IDs are non-empty and 50 EUR category IDs are empty, got ' . var_export($estimated50, true);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'Shipping mapping report count tests passed' . PHP_EOL;
