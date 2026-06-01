<?php

declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../src/Repositories/CategoryMappingRepository.php';

use WEI\Repositories\CategoryMappingRepository;

final class CategoryMappingSummaryFakeWpdb
{
    public string $prefix = 'wp_';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $posts = 'wp_posts';

    /** @var array<int, array<string, mixed>> */
    private array $termsData = [
        ['term_id' => 1, 'name' => 'Alternatory'],
        ['term_id' => 2, 'name' => 'Rozruszniki'],
        ['term_id' => 3, 'name' => 'Puste'],
    ];

    /** @var array<int, array<string, mixed>> */
    private array $mappings = [
        ['id' => 10, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 1, 'woo_category_path' => 'Części > Alternatory', 'ebay_category_id' => '33573', 'ebay_category_name' => 'Lichtmaschinen', 'ebay_category_path' => 'Auto & Motorrad > Teile > Lichtmaschinen', 'status' => 'mapped_manual', 'source' => 'csv_import', 'updated_at' => '2026-06-01 10:00:00'],
        ['id' => 11, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 2, 'woo_category_path' => 'Części > Rozruszniki', 'ebay_category_id' => '33576', 'ebay_category_name' => 'Anlasser', 'ebay_category_path' => 'Auto & Motorrad > Teile > Anlasser', 'status' => 'mapped_manual', 'source' => 'csv_import', 'updated_at' => '2026-06-01 10:01:00'],
    ];

    /** @var array<int, int> */
    private array $productCounts = [1 => 2, 2 => 1, 3 => 0];

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%s|%d/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): int
    {
        if (str_contains($query, 'FROM wp_term_taxonomy tt WHERE tt.taxonomy')) {
            return count($this->termsData);
        }
        if (str_contains($query, 'INNER JOIN wp_relationships')) {
            return 0;
        }
        if (str_contains($query, 'INNER JOIN wp_term_relationships') && !str_contains($query, 'wp_wei_ebay_category_mappings')) {
            return count(array_filter($this->productCounts, static fn (int $count): bool => $count > 0));
        }
        if (str_contains($query, 'COUNT(DISTINCT tt.term_id)') && str_contains($query, 'wp_wei_ebay_category_mappings') && str_contains($query, 'wp_term_relationships')) {
            return count(array_filter($this->mappings, fn (array $row): bool => $row['marketplace_id'] === 'EBAY_DE' && trim((string) $row['ebay_category_id']) !== '' && ($this->productCounts[(int) $row['woo_term_id']] ?? 0) > 0));
        }
        if (str_contains($query, 'COUNT(DISTINCT tt.term_id)') && str_contains($query, 'wp_wei_ebay_category_mappings')) {
            return count(array_filter($this->mappings, static fn (array $row): bool => $row['marketplace_id'] === 'EBAY_DE' && trim((string) $row['ebay_category_id']) !== ''));
        }
        if (str_contains($query, 'COUNT(*) FROM wp_wei_ebay_category_mappings WHERE marketplace_id') && str_contains($query, 'ebay_category_id')) {
            return count(array_filter($this->mappings, static fn (array $row): bool => $row['marketplace_id'] === 'EBAY_DE' && trim((string) $row['ebay_category_id']) !== ''));
        }
        if (str_contains($query, 'COUNT(*) FROM wp_wei_ebay_category_mappings WHERE marketplace_id')) {
            return count(array_filter($this->mappings, static fn (array $row): bool => $row['marketplace_id'] === 'EBAY_DE'));
        }
        if (str_contains($query, 'COUNT(*) FROM wp_wei_ebay_category_mappings')) {
            return count($this->mappings);
        }
        return 0;
    }

    public function get_results(string $query, $output = null): array
    {
        if (str_contains($query, 'GROUP BY COALESCE')) {
            return [['status' => 'mapped_manual', 'count' => 2]];
        }
        if (str_contains($query, 'SELECT m.woo_term_id')) {
            return array_map(function (array $mapping): array {
                $term = current(array_filter($this->termsData, static fn (array $term): bool => (int) $term['term_id'] === (int) $mapping['woo_term_id'])) ?: [];
                return $mapping + ['woo_category_name' => (string) ($term['name'] ?? '')];
            }, $this->mappings);
        }
        return [];
    }
}

$wpdb = new CategoryMappingSummaryFakeWpdb();
$repo = (new ReflectionClass(CategoryMappingRepository::class))->newInstanceWithoutConstructor();
$summary = $repo->production_mapping_summary('EBAY_DE', ['total_rows' => 2, 'updated' => 2, 'inserted' => 0], [], []);

$failures = [];
$assertSame = static function ($actual, $expected, string $message) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$assertSame($summary['mapped_categories'], 2, 'mock import of two production mappings should be counted as mapped');
$assertSame($summary['mapped_categories_with_products'], 2, 'mapped categories with products should be counted separately');
$assertSame($summary['validation_status'], 'not_run', 'empty taxonomy validation cache should be reported as not_run');
$assertSame($summary['valid_categories'], 0, 'valid categories should remain zero when validation has not run');
$assertSame($summary['last_import']['total_rows'], 2, 'last import total rows should be exposed');
$assertSame($summary['last_import']['updated'], 2, 'last import updated count should be exposed');
$assertSame($summary['diagnostics']['mapping_table'], 'wp_wei_ebay_category_mappings', 'diagnostics should identify production mapping table');
$assertSame($summary['diagnostics']['mapping_rows_ebay_de'], 2, 'diagnostics should count EBAY_DE mapping rows');
$assertSame($summary['diagnostics']['mapping_rows_with_category_id'], 2, 'diagnostics should count mappings with ebay_category_id');
$assertSame($summary['diagnostics']['mapping_status_counts']['mapped_manual'] ?? 0, 2, 'diagnostics should expose mapped_manual status count');

$validated = $repo->production_mapping_summary('EBAY_DE', ['total_rows' => 2, 'updated' => 2, 'inserted' => 0], [
    'by_woo_term_id' => [
        '1' => ['category_id' => '33573', 'valid' => true, 'leaf' => true],
        '2' => ['category_id' => '33576', 'valid' => false, 'leaf' => false],
    ],
], []);
$assertSame($validated['mapped_categories'], 2, 'mapped count should not depend on taxonomy validation result');
$assertSame($validated['valid_categories'], 1, 'validated leaf categories should be counted separately');
$assertSame($validated['invalid_category_id'], 1, 'invalid taxonomy validation should be counted separately from mapped');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category mapping production summary tests passed\n";
