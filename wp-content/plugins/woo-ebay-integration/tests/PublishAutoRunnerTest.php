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

$publishSectionStart = strpos($viewSource, 'data-wei-module="publish"');
$publishSectionEnd = $publishSectionStart === false ? false : strpos($viewSource, 'data-wei-module="german-content"', $publishSectionStart);
$publishSection = $publishSectionStart === false ? '' : substr($viewSource, $publishSectionStart, $publishSectionEnd === false ? null : $publishSectionEnd - $publishSectionStart);

foreach ([
    'Auto publish runner' => 'Auto-runner UI heading missing.',
    'id="wei-publish-auto-runner"' => 'Auto-runner wrapper missing.',
    'id="wei-auto-publish-batch-size" type="number" min="1" max="300" value="50"' => 'Auto-runner batch-size control must default to 50 and keep publish action bounds.',
    'id="wei-auto-publish-delay" type="number" min="0" max="3600" step="1" value="5"' => 'Auto-runner delay control must default to 5 seconds.',
    'id="wei-auto-publish-start"' => 'Auto-runner Start button missing.',
    'id="wei-auto-publish-stop"' => 'Auto-runner Stop button missing.',
    'data-counter="batches_completed"' => 'batches_completed counter missing.',
    'data-counter="total_processed"' => 'total_processed counter missing.',
    'data-counter="total_exported"' => 'total_exported counter missing.',
    'data-counter="total_published"' => 'total_published counter missing.',
    'data-counter="total_skipped"' => 'total_skipped counter missing.',
    'data-counter="total_failed"' => 'total_failed counter missing.',
    'data-counter="remaining_ready"' => 'remaining_ready counter missing.',
    'data-counter="last_batch_result"' => 'last_batch_result display missing.',
    'data-counter="state"' => 'running / stopped / completed display missing.',
    'data-counter="stopped_reason"' => 'stopped_reason display missing.',
] as $needle => $message) {
    $assert(str_contains($publishSection, $needle), $message);
}

foreach ([
    "formData.append('action', runner.dataset.action || 'wei_publish_ready_products')" => 'JS must call existing publish-ready-products endpoint.',
    "formData.append('_wpnonce', runner.dataset.nonce || '')" => 'JS must send the existing nonce.',
    "formData.append('wei_auto_runner', '1')" => 'JS must mark auto-runner requests.',
    'if (state.running || state.inFlight)' => 'Start handler must prevent double-submit.',
    'if (state.inFlight)' => 'Batch handler must guard against double-submit.',
    "startButton.disabled = status === 'running'" => 'Start button must disable while running.',
    "stopRunner('manual_stop')" => 'Stop button must stop the runner.',
    'await runBatch(batchIndex)' => 'Runner must wait for the previous request to finish before continuing.',
    'await wait(numberFromInput(delayInput, 5, 0, 3600) * 1000)' => 'Runner must wait the configured delay between batches.',
    "batch.fatal_error || batch.stopped_reason" => 'Fatal server errors must stop the runner.',
    "const madeProgress = published > 0 || exported > 0 || processed > 0" => 'Runner must keep going after any successful processed/exported/published batch.',
    "if (batch.queue_empty === true)" => 'Runner must stop only when the server confirms the global ready queue is empty.',
    "if (globalRemaining === 0 && !madeProgress)" => 'Runner may stop on global_remaining_ready=0 only after a no-progress confirmation batch.',
    "if (!madeProgress)" => 'Runner must stop when a batch makes no progress and the queue is not confirmed non-empty.',
    "window.addEventListener('beforeunload'" => 'Runner must stop when the user leaves the page.',
] as $needle => $message) {
    $assert(str_contains($viewSource, $needle), $message);
}

foreach ([
    '$autoRunner = !empty($_POST[\'wei_auto_runner\']);' => 'Server handler must detect auto-runner requests.',
    'wp_send_json($payload)' => 'Auto-runner requests must receive JSON instead of a redirect.',
    'publish_ready_products_auto_runner_stopped_reason($res)' => 'Server handler must classify fatal/system/API/auth/rate-limit stop reasons.',
    '\'auto_runner_batch_index\' => $autoRunnerBatchIndex' => 'Server payload/log must include auto_runner_batch_index.',
    '\'batch_size\' => $batchSize' => 'Server payload/log must include batch_size.',
    '\'global_remaining_ready\' => $globalRemainingReady' => 'Server payload/log must include global_remaining_ready.',
    '\'queue_empty\' => $queueEmpty' => 'Server payload/log must include queue_empty.',
    'count_initial_publish_candidates_from_meta()' => 'Server must calculate the global remaining ready queue after each batch.',
    '\'processed\' => (int) ($payload[\'processed\'] ?? 0)' => 'Auto-runner log must include processed.',
    '\'exported\' => (int) ($payload[\'exported\'] ?? 0)' => 'Auto-runner log must include exported.',
    '\'published\' => (int) ($payload[\'published\'] ?? 0)' => 'Auto-runner log must include published.',
    '\'skipped_not_ready\' => (int) ($payload[\'skipped_not_ready\'] ?? 0)' => 'Auto-runner log must include skipped_not_ready.',
    '\'errors\' => (int) ($payload[\'errors\'] ?? 0)' => 'Auto-runner log must include errors.',
    '\'stopped_reason\' => $stoppedReason' => 'Auto-runner log must include stopped_reason.',
] as $needle => $message) {
    $assert(str_contains($adminSource, $needle), $message);
}

$publishReadyProductsBlockStart = strpos($adminSource, 'public function publish_ready_products()');
$publishReadyProductsBlock = $publishReadyProductsBlockStart === false ? '' : substr($adminSource, $publishReadyProductsBlockStart, 5000);
$assert(str_contains($publishReadyProductsBlock, 'run_initial_publish_batch($batchSize)'), 'Auto-runner must reuse the existing publish-ready-products batch implementation.');
$assert(!str_contains($publishSection, 'export_product('), 'Publish module UI rendering must not call eBay export APIs.');
$assert(!str_contains($publishSection, 'publishOffer'), 'Publish module UI rendering must not call eBay publish APIs.');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "Publish auto-runner tests passed\n";
