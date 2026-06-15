<?php
$root = dirname(__DIR__);
$service = $root . '/src/Services/OvokoDonorCarsCsvExportService.php';
$client = $root . '/src/Services/RrrApiClient.php';
$admin = $root . '/src/Services/AdminPage.php';
$js = $root . '/assets/admin-autorun.js';
function assert_contains_src(string $file, string $needle, string $message): void {
    $src = file_get_contents($file);
    if ($src === false || !str_contains($src, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}
assert_contains_src($admin, 'catch (\\Throwable $e)', 'AJAX export must catch Throwable and return JSON errors.');
assert_contains_src($admin, 'register_shutdown_function', 'AJAX export must register a shutdown fatal handler.');
assert_contains_src($admin, 'wp_send_json_error($payload, 500)', 'AJAX exception path must send JSON error.');
assert_contains_src($admin, 'GPSwiss Ovoko donor cars AJAX failed expected JSON', 'AJAX failures must be logged as expected JSON responses.');
assert_contains_src($client, 'dictionaryApiCallsThisTick >= $this->maxDictionaryApiCallsPerTick', 'dictionary API calls must be bounded per tick.');
assert_contains_src($client, '&& !$this->exportSafeDictionaryMode', 'export-safe mode must skip all-brand model scan.');
assert_contains_src($service, 'enable_export_safe_dictionary_mode(self::MAX_DICTIONARY_API_CALLS_PER_TICK)', 'donor export must enable bounded dictionary mode.');
assert_contains_src($service, 'dictionary_cache_complete', 'summary must include dictionary cache completeness.');
assert_contains_src($service, 'unresolved_fields_counts', 'summary must include unresolved counts.');
assert_contains_src($js, 'non-JSON response', 'frontend must report non-JSON responses.');
assert_contains_src($js, 'text.slice(0, 300)', 'frontend must show first 300 chars of plain text errors.');
assert_contains_src($js, 'HTTP ' . "' + r.status", 'frontend error must include HTTP status.');
echo "PASS donor cars AJAX JSON safety, bounded dictionary mode, diagnostics, and frontend non-JSON handling are present\n";
