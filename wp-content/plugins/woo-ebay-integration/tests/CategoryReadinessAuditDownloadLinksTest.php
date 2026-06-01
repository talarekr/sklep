<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$schedulerSource = file_get_contents($root . '/src/Services/AutoSyncScheduler.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';

foreach ([
    'full_report_csv_path',
    'full_report_csv_url',
    'full_report_csv_exists',
    'full_report_csv_size',
    'problems_only_csv_path',
    'problems_only_csv_url',
    'problems_only_csv_exists',
    'problems_only_csv_size',
] as $field) {
    $assert(str_contains($adminSource, "'" . $field . "'"), 'Readiness audit JSON/status must include ' . $field . '.');
    $assert(str_contains($schedulerSource, "'" . $field . "'"), 'Scheduler must persist last audit metadata for ' . $field . '.');
}

$assert(str_contains($schedulerSource, "'full_audit_csv' =>") && str_contains($schedulerSource, "'problems_only_csv' =>"), 'Audit must write both full and problems-only CSV reports.');
$assert(str_contains($adminSource, "update_option('wei_ebay_last_category_readiness_audit'"), 'Run category readiness audit must persist last audit metadata.');
$assert(str_contains($viewSource, 'Download full category audit CSV'), 'UI must show the full audit download link after audit.');
$assert(str_contains($viewSource, 'Download problems only CSV'), 'UI must show the problems-only download link after audit.');
$assert(str_contains($viewSource, 'Download last full audit CSV'), 'UI must show the last full audit download link after refresh.');
$assert(str_contains($viewSource, 'Download last problems-only audit CSV'), 'UI must show the last problems-only download link after refresh.');
$assert(str_contains($adminSource, "admin-post.php?action=download_wei_report&file="), 'Audit CSV links must include the admin-post download fallback.');
$assert(str_contains($adminSource, "last_category_readiness_audit_path('problems_only_csv')"), 'Generate blocked category fix report must read the last problems-only CSV from status/option.');
$assert(str_contains($adminSource, "'message' => 'Run category readiness audit first'"), 'Missing last problems CSV must show the requested message.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category readiness audit download link tests passed\n";
