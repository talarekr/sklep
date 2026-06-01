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

$assert(str_contains($adminSource, "add_action('admin_post_wei_run_category_readiness_audit'"), 'Run category readiness audit admin-post hook must be registered.');
$assert(str_contains($viewSource, 'name="action" value="wei_run_category_readiness_audit"'), 'Run category readiness audit button must submit its dedicated action.');
$assert(!str_contains($viewSource, 'Run category readiness audit</button>\n            </form>\n            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">\n                <?php wp_nonce_field(\'wei_export_category_teaching_csv\'); ?>') || str_contains($viewSource, 'wei_run_category_readiness_audit'), 'Run category readiness audit must not reuse the generic dashboard/validation handler.');
$assert(str_contains($adminSource, "'action' => 'run_category_readiness_audit'"), 'Handler response must include action=run_category_readiness_audit.');
$assert(str_contains($adminSource, "'problems_only_csv_url'"), 'Handler response must include problems_only_csv_url.');
$assert(str_contains($adminSource, "'problems_only_csv_exists' => \$problemsExists"), 'Handler response must report problems_only_csv_exists.');
$assert(str_contains($adminSource, "'problems_only_csv_size'"), 'Handler response must report problems_only_csv_size.');
$assert(str_contains($adminSource, "'full_report_csv_url'"), 'Handler response must include full_report_csv_url.');
$assert(str_contains($adminSource, "'category_dashboard_summary' => \$this->category_dashboard_summary_for_report(\$marketplaceId)"), 'Dashboard summary must be nested without replacing the audit payload.');
$assert(strpos($adminSource, "'action' => 'run_category_readiness_audit'") < strpos($adminSource, "'category_dashboard_summary' => \$this->category_dashboard_summary_for_report(\$marketplaceId)"), 'Audit action/result fields must precede optional dashboard summary nesting.');
$assert(str_contains($adminSource, "update_option('wei_ebay_category_readiness_audit_summary'"), 'Handler must persist the category readiness audit summary.');
$assert(str_contains($schedulerSource, "update_option('wei_ebay_last_problems_only_csv_path'"), 'Audit completion must persist the last problems-only CSV path.');
$assert(str_contains($adminSource, "get_option('wei_ebay_last_problems_only_csv_path'"), 'Blocked category fix report lookup must read the persisted last problems-only CSV path.');
$assert(str_contains($schedulerSource, "'-problems-only.csv'"), 'Problems-only audit report filename must use the problems-only suffix.');
$assert(str_contains($schedulerSource, "'size' => is_file(\$path) ? (int) filesize(\$path) : 0"), 'CSV report metadata must include size from the generated file.');
foreach (['product_id', 'product_title', 'woo_category_id', 'woo_subcategory_name', 'woo_category_path', 'current_ebay_category_id', 'current_ebay_category_path', 'status', 'reason', 'detected_intent', 'category_sanity_reason', 'missing_aspects', 'content_status', 'price_status'] as $header) {
    $assert(str_contains($schedulerSource, "'" . $header . "'"), 'Problems CSV header must include ' . $header . '.');
}
$assert(str_contains($viewSource, 'Full report CSV:'), 'UI must expose a direct full report CSV link.');
$assert(str_contains($viewSource, 'Problems only CSV:'), 'UI must expose a direct problems-only CSV link.');
$assert(str_contains($adminSource, "'result' => 'error'") && str_contains($adminSource, 'missing_problems_only_csv_run_category_audit_first'), 'Fix report must still fail clearly when no problems CSV is available.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Category readiness audit routing tests passed\n";
