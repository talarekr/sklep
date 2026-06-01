<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Repositories/CategoryMappingRepository.php';

use WEI\Repositories\CategoryMappingRepository;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!function_exists('get_ancestors')) {
    function get_ancestors($id, $taxonomy): array { return []; }
}
if (!function_exists('get_term')) {
    function get_term($id, $taxonomy) { return (object) ['name' => 'Woo category ' . $id]; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool { return false; }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text): string { return strip_tags((string) $text); }
}
if (!function_exists('remove_accents')) {
    function remove_accents($text): string { return (string) $text; }
}

class ManualCategoryMappingResolverFakeWpdb
{
    public string $prefix = 'wp_';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $posts = 'wp_posts';
    public int $insert_id = 0;
    public array $rows = [];

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = preg_replace('/%[sd]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_results(string $query, $output = null): array
    {
        if (!str_contains($query, 'wp_wei_ebay_category_mappings')) {
            return [];
        }
        preg_match("/marketplace_id='([^']+)'/", $query, $marketplaceMatch);
        preg_match('/woo_term_id=(\d+)/', $query, $termMatch);
        $marketplace = $marketplaceMatch[1] ?? 'EBAY_DE';
        $termId = (int) ($termMatch[1] ?? 0);
        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['marketplace_id'] === $marketplace && (int) $row['woo_term_id'] === $termId));
        foreach ($rows as &$row) {
            $row['resolver_priority'] = match (true) {
                in_array($row['source'], ['manual', 'manual_woo_category_mapping', 'manual_teaching_csv'], true) && !str_starts_with($row['status'], 'disabled') => 1,
                in_array($row['source'], ['import', 'csv_import'], true) && !str_starts_with($row['status'], 'disabled') => 2,
                in_array($row['source'], ['rule', 'auto_taxonomy'], true) && !str_starts_with($row['status'], 'disabled') => 3,
                !str_starts_with($row['status'], 'disabled') => 4,
                default => 5,
            };
        }
        usort($rows, static fn (array $a, array $b): int => ($a['resolver_priority'] <=> $b['resolver_priority']) ?: strcmp($b['updated_at'], $a['updated_at']) ?: ((int) $b['id'] <=> (int) $a['id']));
        return $rows;
    }

    public function get_row(string $query, $output = null): ?array
    {
        $rows = $this->get_results($query, $output);
        return $rows[0] ?? null;
    }

    public function update(string $table, array $data, array $where): int
    {
        $updated = 0;
        foreach ($this->rows as &$row) {
            if (isset($where['id']) && (int) $row['id'] !== (int) $where['id']) {
                continue;
            }
            $row = array_merge($row, $data);
            $updated++;
        }
        return $updated;
    }

    public function insert(string $table, array $data): int
    {
        $this->insert_id = max(array_map(static fn(array $row): int => (int) $row['id'], $this->rows) ?: [0]) + 1;
        $data['id'] = $this->insert_id;
        $this->rows[] = $data;
        return 1;
    }

    public function query(string $query): int
    {
        preg_match("/marketplace_id='([^']+)'/", $query, $marketplaceMatch);
        preg_match('/woo_term_id=(\d+)/', $query, $termMatch);
        preg_match('/id<>(\d+)/', $query, $idMatch);
        $marketplace = $marketplaceMatch[1] ?? 'EBAY_DE';
        $termId = (int) ($termMatch[1] ?? 0);
        $selectedId = (int) ($idMatch[1] ?? 0);
        $updated = 0;
        foreach ($this->rows as &$row) {
            if ($row['marketplace_id'] === $marketplace && (int) $row['woo_term_id'] === $termId && (int) $row['id'] !== $selectedId && !in_array($row['source'], ['manual', 'manual_woo_category_mapping', 'manual_teaching_csv'], true) && !str_starts_with($row['status'], 'disabled')) {
                $row['status'] = 'disabled_duplicate';
                $updated++;
            }
        }
        return $updated;
    }
}

$wpdb = new ManualCategoryMappingResolverFakeWpdb();
$wpdb->rows = [
    ['id' => 10, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 7, 'ebay_category_id' => 'rule-older', 'source' => 'rule', 'status' => 'mapped_auto', 'updated_at' => '2026-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00'],
    ['id' => 11, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 7, 'ebay_category_id' => 'import-newer', 'source' => 'import', 'status' => 'mapped_manual', 'updated_at' => '2026-02-01 00:00:00', 'created_at' => '2026-02-01 00:00:00'],
    ['id' => 12, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 7, 'ebay_category_id' => 'manual-old', 'source' => 'manual', 'status' => 'mapped_manual', 'updated_at' => '2026-01-15 00:00:00', 'created_at' => '2026-01-15 00:00:00'],
    ['id' => 13, 'marketplace_id' => 'EBAY_DE', 'woo_term_id' => 7, 'ebay_category_id' => 'manual-new', 'source' => 'manual', 'status' => 'mapped_manual', 'updated_at' => '2026-03-01 00:00:00', 'created_at' => '2026-03-01 00:00:00'],
];

$repo = (new ReflectionClass(CategoryMappingRepository::class))->newInstanceWithoutConstructor();
$selected = $repo->resolveProductionCategoryMapping(7, 'EBAY_DE');
$failures = [];
if (($selected['ebay_category_id'] ?? '') !== 'manual-new') {
    $failures[] = 'Resolver should choose newest active manual mapping before import/rule/legacy rows.';
}

$saved = $repo->save_manual_mapping(7, 'EBAY_DE', ['category_id' => '33573', 'category_name' => 'Leaf', 'category_path' => 'Auto & Motorrad Teile > Leaf']);
$selected = $repo->resolveProductionCategoryMapping(7, 'EBAY_DE');
if (($selected['ebay_category_id'] ?? '') !== '33573' || ($selected['source'] ?? '') !== 'manual') {
    $failures[] = 'Manual save should update the active manual row and future resolution should use it.';
}
if ((int) ($saved['duplicates_disabled'] ?? 0) < 2) {
    $failures[] = 'Manual save should disable duplicate non-manual legacy/import/rule rows.';
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Manual category mapping resolver tests passed\n";
