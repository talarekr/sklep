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
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

$assert(str_contains($adminSource, 'for ($batch = 0; $batch < $maxBatches; $batch++)'), 'Admin full audit action must continue across batches until complete.');
$assert(str_contains($adminSource, 'run_full_category_audit($verboseDebug, $auditBatchSize, $restartAudit && $batch === 0)'), 'Full audit button must start a fresh run and then continue the same run.');
$assert(str_contains($viewSource, 'Run full category readiness audit'), 'UI must expose a clear full category readiness audit mode.');
$assert(str_contains($viewSource, 'Continue audit'), 'UI must expose an explicit continue mechanism for partial audits.');

foreach (['batch_size', 'current_offset', 'processed_total', 'total_products', 'remaining_products', 'complete', 'audit_run_id', 'started_at', 'finished_at'] as $field) {
    $assert(str_contains($adminSource, "'" . $field . "'"), 'Audit status must include ' . $field . '.');
    $assert(str_contains($viewSource, "'" . $field . "'"), 'Audit UI must show ' . $field . '.');
}

foreach (['audit_run_id', 'schema_version', 'generated_at', 'full_run', 'complete', 'ready', 'blocked_by_category', 'missing_category', 'invalid_ebay_category_id', 'non_leaf_category', 'category_sanity_failed', 'needs_category_review', 'missing_required_aspects'] as $field) {
    $assert(str_contains($schedulerSource, "'" . $field . "'"), 'Audit summary metadata must include ' . $field . '.');
}

$assert(str_contains($schedulerSource, "'processed_total' => (int) (\$state['total_scanned'] ?? 0)"), 'processed_total must come from emitted/scanned rows.');
$assert(str_contains($adminSource, '$processedTotal = (int) ($res[\'processed_total\'] ?? $processed);'), 'Admin reporting must not reset processed to 0 when rows were emitted.');
$assert(!str_contains($viewSource, '$categoryDashboardSummary[$key] ?? 0'), 'Readiness KPI must not fall back to stale dashboard counters.');

$assert(str_contains($schedulerSource, 'fopen($path, \'wb\')'), 'Audit CSVs must be freshly created/truncated before rows are written.');
$assert(str_contains($schedulerSource, "'audit_run_id',") && str_contains($schedulerSource, "'schema_version',"), 'CSV schema must include current-run identity columns.');
$assert(str_contains($schedulerSource, 'array_map(static fn($header) => (string) ($row[$header] ?? \'\'), $headers)'), 'CSV rows must always be written in header order with consistent schema.');
$assert(str_contains($schedulerSource, "'full_audit_csv' =>") && str_contains($schedulerSource, "'problems_only_csv' =>"), 'Full and problems-only CSV reports must be generated together.');
$assert(str_contains($schedulerSource, "'audit_run_id' => (string) (\$state['audit_run_id']"), 'Full and problems-only CSV reports must share the same audit_run_id from state.');

foreach (['selected_mapping_source', 'selected_ebay_category_id', 'resolver_reason', 'category_problem_reason'] as $field) {
    $assert(str_contains($schedulerSource, "'" . $field . "'"), 'Audit CSV must include resolver consistency column ' . $field . '.');
}
$assert(str_contains($schedulerSource, "'selected_mapping_source' => \$selectedMappingSource"), 'Audit CSV must report the selected mapping source, including manual_worklist.');
$assert(str_contains($schedulerSource, "'audit_mode' => true") && str_contains($schedulerSource, "'suppress_side_effects' => true"), 'Full category readiness audit must run preflight in read-only audit mode.');
$assert(!str_contains($schedulerSource, 'sell_feed') && !str_contains($schedulerSource, 'createOffer(') && !str_contains($schedulerSource, 'publishOffer('), 'Full category readiness audit must not perform eBay listing write calls.');
$assert(!str_contains(substr($schedulerSource, strpos($schedulerSource, 'public function run_full_category_audit'), strpos($schedulerSource, 'private function new_full_category_audit_state') - strpos($schedulerSource, 'public function run_full_category_audit')), 'update_post_meta('), 'Full category readiness audit must not modify product meta/listings.');

$assert(str_contains($viewSource, 'FULL COMPLETED AUDIT'), 'UI must label completed audit results clearly.');
$assert(str_contains($viewSource, 'PARTIAL AUDIT / IN PROGRESS'), 'UI must label partial audit results clearly.');
$assert(str_contains($viewSource, 'Do not use these KPI as final publish readiness numbers.'), 'UI must warn when audit KPI are partial.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category readiness audit full-run tests passed\n";
