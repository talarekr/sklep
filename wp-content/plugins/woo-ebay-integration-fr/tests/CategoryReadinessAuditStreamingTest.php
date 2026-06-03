<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$schedulerSource = file_get_contents($root . '/src/Services/AutoSyncScheduler.php') ?: '';
$adapterSource = file_get_contents($root . '/src/Adapters/EbayAdapter.php') ?: '';
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

$assert(str_contains($schedulerSource, 'append_category_audit_csv_row'), 'Category readiness audit must append CSV rows while processing.');
$assert(str_contains($schedulerSource, 'fopen($path, \'ab\')'), 'Category readiness audit must append CSV rows in streaming mode.');
$assert(!str_contains($schedulerSource, '$fullRows = []'), 'Category readiness audit must not rebuild full report rows in memory.');
$assert(!str_contains($schedulerSource, '$problemRows = []'), 'Category readiness audit must not rebuild problem report rows in memory.');
$assert(str_contains($schedulerSource, 'category_audit_csv_headers'), 'Category readiness audit must use the compact audit CSV header list.');
$assert(str_contains($adminSource, 'audit_batch_size'), 'Run category readiness audit must accept an administrative batch size.');
$assert(str_contains($viewSource, 'name="audit_batch_size"'), 'Run category readiness audit form must expose the administrative batch size.');
$assert(str_contains($adminSource, "'status' => \$result === 'partial' ? 'partial'"), 'Partial category readiness audit responses must report status=partial.');
$assert(str_contains($adminSource, "'resume_offset'"), 'Partial category readiness audit responses must include resume_offset.');
$assert(str_contains($adminSource, "'sample_problem_product_ids' => array_slice"), 'Category readiness audit response must cap sample problem product IDs.');
$assert(str_contains($adapterSource, 'resolve_french_content_lightweight'), 'Preflight must provide a lightweight French content resolver for audits.');
$assert(str_contains($adapterSource, '!empty($settings[\'lightweight\'])'), 'Preflight must branch into lightweight mode.');
$assert(!str_contains($adapterSource, 'get_post_meta($product_id, EbayFrenchContentTranslator::META_PAYLOAD, true);'), 'Lightweight audit mode must not load the full translated content payload meta.');
$assert(str_contains($adapterSource, 'metadata_exists(\'post\', $product_id, EbayFrenchContentTranslator::META_DESCRIPTION)'), 'Lightweight audit mode must check description presence without loading the full description.');
$lightweightMethod = substr($adapterSource, strpos($adapterSource, 'private function resolve_french_content_lightweight'), strpos($adapterSource, 'private function resolve_french_content(', strpos($adapterSource, 'private function resolve_french_content_lightweight')) - strpos($adapterSource, 'private function resolve_french_content_lightweight'));
$assert(!str_contains($lightweightMethod, 'get_description('), 'Lightweight audit mode must not load full listing descriptions.');
$assert(str_contains($adapterSource, 'empty($settings[\'lightweight\'])'), 'Lightweight audit mode must skip cached aspect payload translation.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category readiness audit streaming tests passed\n";
