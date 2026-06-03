<?php

declare(strict_types=1);

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir());
}

$GLOBALS['wei_fr_test_options'] = [];
$GLOBALS['wei_fr_test_upload_dir'] = sys_get_temp_dir() . '/wei-blocked-category-regression-test-' . getmypid();
$GLOBALS['wei_fr_test_terms'] = [];

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
if (!function_exists('content_url')) {
    function content_url(string $path = ''): string
    {
        return 'https://example.test/wp-content/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        return [
            'basedir' => $GLOBALS['wei_fr_test_upload_dir'],
            'baseurl' => 'https://example.test/wp-content/uploads',
        ];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0777, true);
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['wei_fr_test_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('get_term')) {
    function get_term(int $termId, string $taxonomy)
    {
        unset($taxonomy);
        return (object) ['term_id' => $termId, 'name' => $GLOBALS['wei_fr_test_terms'][$termId] ?? 'Term ' . $termId];
    }
}
if (!function_exists('get_terms')) {
    function get_terms(array $args = []): array
    {
        unset($args);
        return [];
    }
}
if (!function_exists('get_ancestors')) {
    function get_ancestors(int $termId, string $taxonomy): array
    {
        unset($termId, $taxonomy);
        return [];
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value): bool
    {
        return false;
    }
}

require_once __DIR__ . '/../src/Services/CategoryMappingSafety.php';
require_once __DIR__ . '/../src/Services/EbayDeCategoryRuleMapper.php';
require_once __DIR__ . '/../src/Services/EbayTaxonomyService.php';
require_once __DIR__ . '/../src/Services/Logger.php';
require_once __DIR__ . '/../src/Repositories/CategoryMappingRepository.php';
require_once __DIR__ . '/../src/Services/BlockedCategoryFixReportService.php';

use WEI_FR\Repositories\CategoryMappingRepository;
use WEI_FR\Services\BlockedCategoryFixReportService;
use WEI_FR\Services\EbayDeCategoryRuleMapper;
use WEI_FR\Services\EbayTaxonomyService;
use WEI_FR\Services\Logger;

final class BlockedCategoryRuleMapperRegressionTestLogger extends Logger
{
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void {}
}

final class BlockedCategoryRuleMapperRegressionTestTaxonomy extends EbayTaxonomyService
{
    public function __construct() {}

    public function validate_category_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        return [];
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$mapper = new EbayDeCategoryRuleMapper();
$recommend = static fn(string $name, string $path = '', string $title = ''): array => $mapper->recommend([
    'woo_subcategory_name' => $name,
    'woo_category_path' => $path !== '' ? $path : 'Części samochodowe > ' . $name,
    'product_title' => $title,
]);

$uncategorized = $recommend('Bez kategorii', 'Bez kategorii', 'Silnik kompletny Audi');
$assert(($uncategorized['detected_intent'] ?? '') === 'uncategorized', 'Bez kategorii must be a dedicated manual-review intent.');
$assert(($uncategorized['recommended_ebay_category_id'] ?? '') === '', 'Bez kategorii must not receive an automatic eBay category.');

$towHook = $recommend('Hak holowniczy / Komplet', 'Części samochodowe > Hak holowniczy / Komplet', 'Hak holowniczy komplet wiązka kabel');
$assert(($towHook['recommended_ebay_category_id'] ?? '') !== '179847', 'Hak holowniczy / Komplet must not map to Kabelbäume.');
$assert(str_contains((string) ($towHook['recommended_ebay_category_path'] ?? ''), 'Anhängerkupplungen'), 'Hak holowniczy / Komplet must map to Anhängerkupplungen family.');

$coolingHose = $recommend('Przewód / Wąż chłodnicy', 'Części samochodowe > Układ chłodzenia > Przewód / Wąż chłodnicy', 'Przewód chłodnicy Audi');
$assert(($coolingHose['recommended_ebay_category_id'] ?? '') !== '33544', 'Przewód / Wąż chłodnicy must not map to Klimaleitungen.');
$assert(($coolingHose['detected_intent'] ?? '') === 'cooling_hose', 'Przewód / Wąż chłodnicy must be detected as cooling_hose.');

$acOther = $recommend('Inne elementy układu klimatyzacji A/C', 'Części samochodowe > Klimatyzacja > Inne elementy układu klimatyzacji A/C', 'Część klimatyzacji Audi');
$assert(($acOther['detected_intent'] ?? '') === 'ac_other', 'Generic Inne elementy układu klimatyzacji A/C must be ac_other.');
$assert(($acOther['recommended_ebay_category_id'] ?? '') === '', 'Generic Inne elementy układu klimatyzacji A/C must need manual review without a clear sample title.');

$roofInterior = $recommend('Lusterko wsteczne / Podsufitka / Panel oświetlenia wnętrza', 'Części samochodowe > Podsufitki / Szyberdachy > Lusterko wsteczne / Podsufitka / Panel oświetlenia wnętrza', 'Lampka wnętrza Audi');
$assert(($roofInterior['recommended_ebay_category_id'] ?? '') !== '262172', 'Lusterko/Podsufitka/Panel oświetlenia must not map to Schiebedächer.');

$interiorLight = $recommend('Panel oświetlenia wnętrza', 'Części samochodowe > Podsufitki / Szyberdachy > Panel oświetlenia wnętrza', 'Panel oświetlenia wnętrza Audi');
$assert(($interiorLight['recommended_ebay_category_id'] ?? '') !== '262172', 'Panel oświetlenia wnętrza must not map to Schiebedächer.');

$sunroof = $recommend('Szyberdach', 'Części samochodowe > Podsufitki / Szyberdachy > Szyberdach', 'Mechanizm szyberdachu Audi');
$assert(($sunroof['recommended_ebay_category_id'] ?? '') === '262172', 'Szyberdach must still map to Schiebedächer.');

$acHose = $recommend('Wąż / Przewód klimatyzacji A/C', 'Części samochodowe > Klimatyzacja > Wąż / Przewód klimatyzacji A/C', 'Wąż klimatyzacji Audi');
$assert(($acHose['recommended_ebay_category_id'] ?? '') === '33544', 'Wąż / Przewód klimatyzacji A/C must still map to 33544.');

$GLOBALS['wei_fr_test_terms'] = [
    1 => 'Bez kategorii',
    2 => 'Hak holowniczy / Komplet',
    3 => 'Przewód / Wąż chłodnicy',
    4 => 'Inne elementy układu klimatyzacji A/C',
    5 => 'Wąż / Przewód klimatyzacji A/C',
];

$csvPath = trailingslashit($GLOBALS['wei_fr_test_upload_dir']) . 'source-problems.csv';
wp_mkdir_p(dirname($csvPath));
$fh = fopen($csvPath, 'wb');
fputcsv($fh, ['status', 'product_id', 'title', 'woo_category_id', 'woo_category_path', 'current_ebay_category_id', 'current_ebay_category_path']);
fputcsv($fh, ['blocked_by_category', '201', 'Silnik kompletny Audi', '1', 'Bez kategorii', '33516', 'Old path']);
fputcsv($fh, ['blocked_by_category', '202', 'Hak holowniczy komplet wiązka', '2', 'Części samochodowe > Hak holowniczy / Komplet', '33516', 'Old path']);
fputcsv($fh, ['blocked_by_category', '203', 'Przewód chłodnicy Audi', '3', 'Części samochodowe > Układ chłodzenia > Przewód / Wąż chłodnicy', '33516', 'Old path']);
fputcsv($fh, ['blocked_by_category', '204', 'Część klimatyzacji Audi', '4', 'Części samochodowe > Klimatyzacja > Inne elementy układu klimatyzacji A/C', '33516', 'Old path']);
fputcsv($fh, ['blocked_by_category', '205', 'Wąż klimatyzacji Audi', '5', 'Części samochodowe > Klimatyzacja > Wąż / Przewód klimatyzacji A/C', '33516', 'Old path']);
fclose($fh);

$repo = (new ReflectionClass(CategoryMappingRepository::class))->newInstanceWithoutConstructor();
$service = new BlockedCategoryFixReportService($repo, new BlockedCategoryRuleMapperRegressionTestTaxonomy(), new BlockedCategoryRuleMapperRegressionTestLogger());
$result = $service->generate_from_audit($csvPath, 'EBAY_FR');
$fixRows = array_map('str_getcsv', file((string) $result['fix_import_csv_path']) ?: []);
$fixHeader = array_shift($fixRows);
$fixAssoc = [];
foreach ($fixRows as $row) {
    $fixAssoc[] = array_combine($fixHeader, $row);
}
$fixByTerm = [];
foreach ($fixAssoc as $row) {
    $fixByTerm[(string) ($row['woo_category_id'] ?? '')] = $row;
}
$assert(!isset($fixByTerm['1']), 'Bez kategorii must not be present in blocked_category_mapping_fix_import.csv.');
$assert(isset($fixByTerm['2']) && ($fixByTerm['2']['ebay_category_id'] ?? '') === '33653', 'Tow hook should be safe import candidate for Anhängerkupplungen.');
$assert(!isset($fixByTerm['3']), 'Cooling hose without a confirmed EBAY_FR leaf must not be in fix import.');
$assert(!isset($fixByTerm['4']), 'Generic AC other category must not be in fix import.');
$assert(isset($fixByTerm['5']) && ($fixByTerm['5']['ebay_category_id'] ?? '') === '33544', 'AC hose should remain a safe import candidate for 33544.');

$recommendationRows = array_map('str_getcsv', file((string) $result['recommendations_csv_path']) ?: []);
$recommendationHeader = array_shift($recommendationRows);
$assert(in_array('exclusion_reason', $recommendationHeader, true), 'Recommendations CSV must expose exclusion_reason.');
$assert(in_array('mapping_status', $recommendationHeader, true), 'Recommendations CSV must expose mapping_status.');
$assert(in_array('confidence', $recommendationHeader, true), 'Recommendations CSV must expose textual confidence.');
$recommendations = [];
foreach ($recommendationRows as $row) {
    $record = array_combine($recommendationHeader, $row);
    $recommendations[(string) ($record['woo_category_id'] ?? '')] = $record;
}
$assert(($recommendations['1']['mapping_status'] ?? '') === 'needs_manual_review', 'Bez kategorii recommendation status must be needs_manual_review.');
$assert(($recommendations['1']['confidence'] ?? '') === 'low', 'Bez kategorii recommendation confidence must be low.');
$assert(($recommendations['1']['apply_candidate'] ?? '') === '0', 'Bez kategorii apply_candidate must be false.');
$assert(($recommendations['1']['recommended_ebay_category_id'] ?? '') === '', 'Bez kategorii recommendation must not include category ID.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Blocked category rule mapper regression tests passed\n";
