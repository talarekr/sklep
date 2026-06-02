<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/src/Services/AdminPage.php') ?: '';
$viewSource = file_get_contents($root . '/views/admin-page.php') ?: '';
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$methodBlock = static function (string $source, string $signature): string {
    $start = strpos($source, $signature);
    if ($start === false) {
        return '';
    }
    $next = strpos($source, "\n    public function ", $start + strlen($signature));
    if ($next === false) {
        $next = strpos($source, "\n    private function ", $start + strlen($signature));
    }
    return substr($source, $start, $next === false ? null : $next - $start);
};

$publishSectionStart = strpos($viewSource, 'data-wei-module="publish"');
$publishSectionEnd = $publishSectionStart === false ? false : strpos($viewSource, '<summary>Advanced / Debug', $publishSectionStart);
$publishSection = $publishSectionStart === false ? '' : substr($viewSource, $publishSectionStart, $publishSectionEnd === false ? null : $publishSectionEnd - $publishSectionStart);

foreach ([
    'PUBLISH_ACTION_MIN_BATCH_SIZE = 1' => 'Publish action batch-size validation must keep minimum 1.',
    'PUBLISH_ACTION_MAX_BATCH_SIZE = 300' => 'Publish action batch-size validation must allow 300.',
    "set_status('Maximum batch size is 300.')" => 'Publish action batch-size validation must reject oversized batches with the required message.',
    'if ($batchSize > self::PUBLISH_ACTION_MAX_BATCH_SIZE)' => 'Publish action batch-size validation must explicitly reject values above 300.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}


foreach ([
    'name="action" value="wei_auto_sync_export_now"' => 'Export ready products form missing action.',
    'name="action" value="wei_ebay_initial_publish_batch"' => 'Publish ready offers form missing action.',
    'name="action" value="wei_publish_ready_products"' => 'Publish ready products form missing action.',
    'min="1" max="300" name="batch_size" value="20"' => 'Export ready products batch-size input must allow 1 through 300 and keep default 20.',
    'min="1" max="300" name="batch_size" value="5"' => 'Publish batch-size inputs must allow 1 through 300 and keep default 5.',
] as $needle => $message) {
    $assert(str_contains($publishSection, $needle), $message);
}

$assert(substr_count($publishSection, 'min="1" max="300" name="batch_size" value="5"') >= 2, 'Both publish-ready offers and publish-ready products inputs must allow 300 while keeping default 5.');
$assert(str_contains($viewSource, 'id="wei-export-batch-size" type="number" min="1" max="300"'), 'Saved export batch-size setting input must allow up to 300.');
$assert(!str_contains($publishSection, 'max="50" name="batch_size" value="20"'), 'Export ready products input must no longer cap batch size at 50.');
$assert(!str_contains($publishSection, 'max="50" name="batch_size" value="5"'), 'Publish ready inputs must no longer cap batch size at 50.');

foreach ([
    'public function auto_sync_export_now()' => ['run_export_batch($batchSize)', "publish_action_batch_size_from_post('batch_size', 20)"],
    'public function ebay_initial_publish_batch()' => ['run_initial_publish_batch($batchSize)', "publish_action_batch_size_from_post('batch_size', 5)"],
    'public function publish_ready_products()' => ['run_initial_publish_batch($batchSize)', "publish_action_batch_size_from_post('batch_size', 5)"],
] as $signature => $needles) {
    $block = $methodBlock($adminSource, $signature);
    $assert($block !== '', 'Handler missing: ' . $signature);
    foreach ($needles as $needle) {
        $assert(str_contains($block, $needle), $signature . ' must use the validated batch size: ' . $needle);
    }
    $assert(!str_contains($block, 'min(50'), $signature . ' must not retain the old server-side cap of 50.');
}

$assert(str_contains($adminSource, "min(self::PUBLISH_ACTION_MAX_BATCH_SIZE, absint(\$_POST['auto_sync_export_batch_size'] ?? 20))"), 'Saved export batch-size setting must clamp at 300 server-side.');

foreach (['export_product(', 'publish_product(', 'publish_product_offer_only(', 'create_offer', 'publishOffer', 'update_post_meta(', 'wp_update_post('] as $forbidden) {
    $assert(!str_contains($publishSection, $forbidden), 'Publish module UI rendering must not modify products/listings or call eBay APIs: ' . $forbidden);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish batch size limit tests passed\n";
