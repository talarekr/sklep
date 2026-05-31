<?php
$maskSecret = static function (?string $value): string {
    $value = (string) $value;
    if ($value === '') {
        return 'not configured';
    }

    return strlen($value) <= 8 ? '••••••••' : substr($value, 0, 4) . '••••••••' . substr($value, -4);
};

$redactTechnical = static function (string $value) use ($s): string {
    $secrets = array_filter([
        (string) ($s['client_secret'] ?? ''),
        (string) ($s['translation_api_key'] ?? ''),
        (string) ($s['client_id'] ?? ''),
    ]);
    foreach ($secrets as $secret) {
        $value = str_replace($secret, '[redacted]', $value);
    }

    return (string) preg_replace('/("?(?:client_secret|translation_api_key|access_token|refresh_token|api_key|authorization)"?\s*[:=]\s*)"?[^",}\s]+"?/i', '$1[redacted]', $value);
};

$lastStatusMessage = (string) ($status['message'] ?? '');
$lastAction = $lastStatusMessage !== '' ? $lastStatusMessage : 'No recent action';
$lastStatusPayload = [];
if (preg_match('/^(.*?):\s*(\{.*\})\s*$/s', $lastStatusMessage, $matches)) {
    $lastAction = trim((string) $matches[1]);
    $decodedStatus = json_decode((string) $matches[2], true);
    if (is_array($decodedStatus)) {
        $lastStatusPayload = $decodedStatus;
    }
}
$lastStatusResult = strtolower((string) ($lastStatusPayload['result'] ?? $lastStatusPayload['status'] ?? 'ready'));
$lastStatusIsError = $lastStatusResult === 'error' || str_contains(strtolower($lastStatusMessage), 'failed') || str_contains(strtolower($lastStatusMessage), 'error');
$lastProductId = (string) ($lastStatusPayload['product_id'] ?? $lastStatusPayload['id'] ?? '');
$lastStage = (string) ($lastStatusPayload['stage'] ?? $lastStatusPayload['failed_stage'] ?? '');
$lastShortMessage = (string) ($lastStatusPayload['message'] ?? $lastStatusPayload['error'] ?? $lastStatusMessage);
if ($lastShortMessage === '') {
    $lastShortMessage = '-';
}
$lastShortMessage = wp_strip_all_tags($lastShortMessage);
if (strlen($lastShortMessage) > 220) {
    $lastShortMessage = substr($lastShortMessage, 0, 217) . '...';
}

$cached = is_array($s['wei_cached_policies'] ?? null) ? $s['wei_cached_policies'] : [];
$fulfillmentPolicies = is_array($cached['fulfillmentPolicies'] ?? null) ? $cached['fulfillmentPolicies'] : [];
$paymentPolicies = is_array($cached['paymentPolicies'] ?? null) ? $cached['paymentPolicies'] : [];
$returnPolicies = is_array($cached['returnPolicies'] ?? null) ? $cached['returnPolicies'] : [];
$findPolicyName = static function (array $policies, string $id, string $idKey): string {
    foreach ($policies as $policy) {
        if ((string) ($policy[$idKey] ?? '') === $id) {
            return (string) ($policy['name'] ?? $id);
        }
    }

    return $id;
};
$locationKey = (string) ($s['inventory_location_key'] ?? 'gpswiss-pl');
$fulfillmentId = (string) ($s['ebay_fulfillment_policy_id'] ?? '');
$fulfillmentId30 = (string) ($s['fulfillment_policy_id_30_eur'] ?? $fulfillmentId);
$fulfillmentId50 = (string) ($s['fulfillment_policy_id_50_eur'] ?? '');
$fulfillmentId100 = (string) ($s['fulfillment_policy_id_100_eur'] ?? '');
$paymentId = (string) ($s['ebay_payment_policy_id'] ?? '');
$returnId = (string) ($s['ebay_return_policy_id'] ?? '');
$accountSetupConfigured = $locationKey !== '' && $fulfillmentId30 !== '' && $fulfillmentId50 !== '' && $fulfillmentId100 !== '' && $paymentId !== '' && $returnId !== '';

$provider = (string) ($s['translation_provider'] ?? 'disabled');
$translationConfigured = $provider === 'google_cloud_translate' && (string) ($s['translation_api_key'] ?? '') !== '';
$translationLabel = $translationConfigured ? 'Google Cloud Translate configured' : ($provider === 'disabled' ? 'disabled' : 'provider selected, API key missing');

$nbpRateStatus = is_array($nbp_rate_status ?? null) ? $nbp_rate_status : [];
$nbpRate = $nbpRateStatus['nbp_rate'] ?? null;
$nbpEffectiveDate = (string) ($nbpRateStatus['nbp_effective_date'] ?? '');
$nbpCacheAge = $nbpRateStatus['cache_age_seconds'] ?? null;
$nbpCacheAgeLabel = is_numeric($nbpCacheAge) ? human_time_diff(time() - (int) $nbpCacheAge, time()) : '-';
$nbpCacheStatus = (string) ($nbpRateStatus['cache_status'] ?? 'missing');

$buildEbayCategoryUrl = static function (string $categoryId, array $categoryData = []): string {
    $preferredUrl = '';
    $urlKeys = ['categoryWebUrl', 'categoryUrl', 'webUrl', 'url'];
    foreach ($urlKeys as $urlKey) {
        if (isset($categoryData[$urlKey]) && is_scalar($categoryData[$urlKey])) {
            $preferredUrl = trim((string) $categoryData[$urlKey]);
            break;
        }
        if (isset($categoryData['raw_summary']) && is_array($categoryData['raw_summary']) && isset($categoryData['raw_summary'][$urlKey]) && is_scalar($categoryData['raw_summary'][$urlKey])) {
            $preferredUrl = trim((string) $categoryData['raw_summary'][$urlKey]);
            break;
        }
    }

    if ($preferredUrl !== '' && preg_match('#^https?://#i', $preferredUrl)) {
        return $preferredUrl;
    }

    return $categoryId !== '' ? 'https://www.ebay.de/b/' . rawurlencode($categoryId) : '';
};

$thresholdValue = \WEI\Services\CategoryMappingSafety::threshold((array) ($s ?? []));
$categoryRows = [];
$categorySummary = [
    'total' => 0,
    'mapped_manual' => 0,
    'accepted_auto' => 0,
    'needs_category_review' => 0,
    'blocked_by_sonstige' => 0,
    'blocked_by_expected_keyword' => 0,
    'blocked_by_threshold' => 0,
    'last_auto_map_run' => '-',
];
foreach ((array) ($category_mappings ?? []) as $row) {
    $statusValue = (string) ($row['status'] ?? '');
    if ($statusValue === '') {
        $statusValue = empty($row['ebay_category_id']) ? 'unmapped' : 'mapped_manual';
    }
    $confidenceValue = (float) ($row['confidence'] ?? 0);
    $sanityReason = '';
    if ($statusValue === 'mapped_auto' || (string) ($row['source'] ?? '') === 'auto_taxonomy') {
        $safety = \WEI\Services\CategoryMappingSafety::evaluate_auto_mapping(
            (string) ($row['woo_category_path'] ?? $row['name'] ?? ''),
            trim((string) (($row['ebay_category_path'] ?? '') . ' ' . ($row['ebay_category_name'] ?? ''))),
            $confidenceValue,
            (array) ($s ?? [])
        );
        $statusValue = (string) ($safety['ui_status'] ?? $statusValue);
        $sanityReason = (string) ($safety['sanity_reason'] ?? '');
    } elseif ($statusValue === 'low_confidence_auto') {
        $statusValue = 'blocked_by_threshold';
    } elseif ($statusValue === 'category_sanity_failed') {
        $statusValue = 'blocked_by_sanity';
        $sanityReason = (string) ($row['error_reason'] ?? '');
    } elseif (in_array($statusValue, ['mapped_manual', 'mapped_manual_teaching', 'mapped_manual_woo_category'], true)) {
        $statusValue = 'accepted_manual';
    }

    $debug = json_decode((string) ($row['suggestion_payload'] ?? ''), true);
    $best = is_array($debug) && is_array($debug['selected_candidate'] ?? null) ? $debug['selected_candidate'] : (is_array($debug) && is_array($debug['best'] ?? null) ? $debug['best'] : []);
    $bestSuggestion = trim((string) (($best['category_id'] ?? '') . ' ' . ($best['category_path'] ?? $best['path'] ?? $best['category_name'] ?? $best['name'] ?? '')));
    $displayCategoryId = trim((string) ($row['ebay_category_id'] ?? ''));
    if ($displayCategoryId === '' && $statusValue === 'needs_category_review') {
        $displayCategoryId = trim((string) ($best['category_id'] ?? ''));
    }
    $displayCategoryPath = trim((string) (($row['ebay_category_name'] ?? '') . ' ' . ($row['ebay_category_path'] ?? '')));
    if ($displayCategoryPath === '' && $statusValue === 'needs_category_review') {
        $displayCategoryPath = trim((string) (($best['category_path'] ?? $best['path'] ?? '') . ' ' . ($best['category_name'] ?? $best['name'] ?? '')));
    }

    $row['_ui_status'] = $statusValue;
    $row['_ui_sanity_reason'] = $sanityReason;
    $row['_ui_best_suggestion'] = $bestSuggestion;
    $row['_ui_category_id'] = $displayCategoryId;
    $row['_ui_category_path'] = $displayCategoryPath;
    $row['_ui_category_url'] = $buildEbayCategoryUrl($displayCategoryId, $best);
    $row['_ui_top_candidates'] = is_array($debug) && is_array($debug['top_candidates'] ?? null) ? array_slice($debug['top_candidates'], 0, 3) : [];
    $row['_ui_rejected_best_reason'] = is_array($debug) ? (string) ($debug['rejected_best_reason'] ?? '') : '';
    $categoryRows[] = $row;

    $categorySummary['total']++;
    if ($statusValue === 'accepted_manual') {
        $categorySummary['mapped_manual']++;
    } elseif ($statusValue === 'accepted_auto') {
        $categorySummary['accepted_auto']++;
    } elseif ($statusValue === 'needs_category_review') {
        $categorySummary['needs_category_review']++;
    } elseif ($statusValue === 'blocked_by_threshold') {
        $categorySummary['blocked_by_threshold']++;
    } elseif ($statusValue === 'blocked_by_sanity') {
        if ($sanityReason === 'auto_mapping_to_sonstige_for_specific_woo_category') {
            $categorySummary['blocked_by_sonstige']++;
        } elseif ($sanityReason === 'expected_path_keyword_missing') {
            $categorySummary['blocked_by_expected_keyword']++;
        } else {
            $categorySummary['needs_category_review']++;
        }
    }
    if ((string) ($row['source'] ?? '') === 'auto_taxonomy' && !empty($row['updated_at'])) {
        $categorySummary['last_auto_map_run'] = max((string) $categorySummary['last_auto_map_run'], (string) $row['updated_at']);
    }
}
if ($categorySummary['last_auto_map_run'] === '-') {
    foreach ((array) $logs as $log) {
        if (str_contains((string) ($log['message'] ?? ''), 'Auto category mapping')) {
            $categorySummary['last_auto_map_run'] = (string) ($log['at'] ?? '-');
            break;
        }
    }
}

$currentFilter = isset($_GET['category_status']) ? sanitize_key(wp_unslash((string) $_GET['category_status'])) : 'all';
$currentSort = isset($_GET['category_sort']) ? sanitize_key(wp_unslash((string) $_GET['category_sort'])) : '';
$filteredCategoryRows = array_values(array_filter($categoryRows, static function (array $row) use ($currentFilter): bool {
    $statusValue = (string) ($row['_ui_status'] ?? '');
    if ($currentFilter === 'all' || $currentFilter === '') {
        return true;
    }
    if ($currentFilter === 'mapped_auto') {
        return $statusValue === 'accepted_auto';
    }
    if ($currentFilter === 'mapped_manual') {
        return $statusValue === 'accepted_manual';
    }
    if ($currentFilter === 'needs_review') {
        return $statusValue === 'needs_category_review';
    }
    if ($currentFilter === 'blocked') {
        return in_array($statusValue, ['blocked_by_threshold', 'blocked_by_sanity'], true);
    }

    return true;
}));
if ($currentSort === 'confidence') {
    usort($filteredCategoryRows, static fn (array $a, array $b): int => ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0)));
} elseif ($currentSort === 'products') {
    usort($filteredCategoryRows, static fn (array $a, array $b): int => ((int) ($b['product_count'] ?? 0)) <=> ((int) ($a['product_count'] ?? 0)));
}
$filterUrl = static fn (string $filter): string => add_query_arg(['page' => 'woo-ebay', 'wei_section' => 'category-mappings', 'category_status' => $filter], admin_url('admin.php'));
$sortUrl = static fn (string $sort): string => add_query_arg(['page' => 'woo-ebay', 'wei_section' => 'category-mappings', 'category_status' => $currentFilter, 'category_sort' => $sort], admin_url('admin.php'));

$riskyCategoryRows = array_values(array_filter($categoryRows, static function (array $row): bool {
    return !in_array((string) ($row['_ui_status'] ?? ''), ['accepted_manual', 'accepted_auto'], true);
}));
usort($riskyCategoryRows, static fn (array $a, array $b): int => ((int) ($b['product_count'] ?? 0)) <=> ((int) ($a['product_count'] ?? 0)) ?: ((float) ($b['confidence'] ?? 0)) <=> ((float) ($a['confidence'] ?? 0)));
$riskyCategoryRows = array_slice($riskyCategoryRows, 0, 10);

$skuActiveRun = is_array($ebay_sku_generation_status['active_run'] ?? null) ? $ebay_sku_generation_status['active_run'] : [];
$skuLastRun = is_array($ebay_sku_generation_status['last_run'] ?? null) ? $ebay_sku_generation_status['last_run'] : [];
$skuLastTotals = is_array($skuLastRun['totals'] ?? null) ? $skuLastRun['totals'] : [];
$skuActiveTotals = is_array($skuActiveRun['totals'] ?? null) ? $skuActiveRun['totals'] : [];
$autoSync = is_array($auto_sync_status ?? null) ? $auto_sync_status : [];
$autoLastSummary = is_array($autoSync['last_summary'] ?? null) ? $autoSync['last_summary'] : [];
$readinessSummary = is_array($autoSync['readiness_summary'] ?? null) ? $autoSync['readiness_summary'] : [];
$readinessFilter = sanitize_key((string) ($_GET['readiness_filter'] ?? 'all'));
$readinessBucketMap = [
    'all' => 'not_ready_items',
    'blocked_by_category' => 'blocked_by_category_items',
    'missing_required_aspects' => 'missing_required_aspects_items',
    'invalid_price' => 'invalid_price_items',
    'missing_content_image_stock' => 'not_ready_items',
];
if (!isset($readinessBucketMap[$readinessFilter])) {
    $readinessFilter = 'all';
}
$notReadyItems = is_array($readinessSummary[$readinessBucketMap[$readinessFilter]] ?? null) ? $readinessSummary[$readinessBucketMap[$readinessFilter]] : [];
if ($readinessFilter === 'missing_content_image_stock') {
    $notReadyItems = array_values(array_filter($notReadyItems, static function (array $item): bool {
        $status = (string) ($item['status'] ?? '');
        $reason = strtolower((string) ($item['primary_reason'] ?? ''));
        $errors = strtolower(implode(' ', array_map('strval', (array) ($item['errors'] ?? []))));
        return $status === 'not_ready_missing_german_content'
            || str_contains($reason . ' ' . $errors, 'content')
            || str_contains($reason . ' ' . $errors, 'german')
            || str_contains($reason . ' ' . $errors, 'image')
            || str_contains($reason . ' ' . $errors, 'stock');
    }));
}
$readinessFilterLabels = [
    'all' => 'All not ready',
    'blocked_by_category' => 'Blocked by category',
    'missing_required_aspects' => 'Missing required aspects',
    'invalid_price' => 'Invalid price',
    'missing_content_image_stock' => 'Missing content/image/stock',
];
$exportSummary = is_array($autoSync['export_summary'] ?? null) ? $autoSync['export_summary'] : [];
$stockSummary = is_array($autoSync['stock_summary'] ?? null) ? $autoSync['stock_summary'] : [];
$initialPublish = is_array($initial_publish_status ?? null) ? $initial_publish_status : [];
$initialPublishCandidates = is_array($initial_publish_candidate_summary ?? null) ? $initial_publish_candidate_summary : (is_array($initialPublish['candidate_summary'] ?? null) ? $initialPublish['candidate_summary'] : []);
$initialPublishSkippedReasons = is_array($initialPublishCandidates['skipped_reasons'] ?? null) ? $initialPublishCandidates['skipped_reasons'] : [];
$initialPublishLog = array_values(array_map('strval', (array) ($initialPublish['last_batch_log'] ?? [])));
$initialPublishLastBatch = $initialPublishLog !== [] ? (string) end($initialPublishLog) : '-';
$initialPublishTotalReady = (int) ($initialPublish['total_ready'] ?? $initialPublishCandidates['initial_publish_candidates'] ?? $initialPublishCandidates['ready'] ?? 0);
$initialPublishSuccess = (int) ($initialPublish['success'] ?? 0);
$initialPublishFailed = (int) ($initialPublish['failed'] ?? 0);
$initialPublishRemaining = (int) ($initialPublish['remaining'] ?? max(0, $initialPublishTotalReady - $initialPublishSuccess));
$initialPublishLastBatchSuccess = (int) ($initialPublish['last_batch_success'] ?? 0);
$initialPublishLastBatchFailed = (int) ($initialPublish['last_batch_failed'] ?? 0);
$initialPublishLastBatchProcessed = (int) ($initialPublish['last_batch_processed'] ?? 0);
$initialPublishLastRunAt = (string) ($initialPublish['last_run_at'] ?? '');
$initialPublishLastPublishedProductId = (int) ($initialPublish['last_published_product_id'] ?? 0);
$initialPublishLastListingId = (string) ($initialPublish['last_listing_id'] ?? '');
$initialPublishPublicationStatus = (string) ($initialPublish['status'] ?? 'idle');
$autoStatus = (string) ($autoSync['status'] ?? 'disabled');
$autoModeLabels = [
    'disabled' => 'Disabled',
    'preflight_only' => 'Preflight only / readiness only',
    'export_ready_products' => 'Export ready products',
    'orders_stock_only' => 'Orders & stock sync only',
    'full_sync' => 'Full sync',
];
$frequencyLabels = ['every_15_minutes' => 'every 15 minutes', 'hourly' => 'hourly', 'daily' => 'daily'];
$categoryBlockedCount = (int) $categorySummary['blocked_by_sonstige'] + (int) $categorySummary['blocked_by_expected_keyword'] + (int) $categorySummary['blocked_by_threshold'];
$categoryMissingCount = (int) $categorySummary['needs_category_review'];
$teachingManualMappingsCount = (int) $categorySummary['mapped_manual'];
$latestLogMessage = (string) ($logs[0]['message'] ?? '');
$latestLogHasError = str_contains(strtolower($latestLogMessage), 'error') || str_contains(strtolower($latestLogMessage), 'failed');
$accountSetupMissingCount = ($locationKey === '' ? 1 : 0) + ($fulfillmentId30 === '' ? 1 : 0) + ($fulfillmentId50 === '' ? 1 : 0) + ($fulfillmentId100 === '' ? 1 : 0) + ($paymentId === '' ? 1 : 0) + ($returnId === '' ? 1 : 0);
$toScalarString = static function ($value, string $default = ''): string {
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
        return (string) $value;
    }

    return $default;
};
$displayValue = static function ($value, string $fallback = '—') use ($toScalarString): string {
    $value = trim($toScalarString($value));
    return $value !== '' ? $value : $fallback;
};
$adminPostUrl = $toScalarString(admin_url('admin-post.php'));
$adminPageUrl = $toScalarString(admin_url('admin.php'));
$connectUrl = $toScalarString($connect_url ?? '');
$setting = static fn(string $key, $default = ''): string => $toScalarString($s[$key] ?? $default);
$boolLabel = static fn($enabled): string => !empty($enabled) ? 'Enabled' : 'Disabled';
$badgeClass = static fn($enabled): string => !empty($enabled) ? 'ok' : 'warn';
$productSyncRowsLoaded = !empty($load_product_sync_rows);
$categoryRowsLoaded = !empty($load_category_mapping_rows);
$productSyncRows = array_slice(is_array($product_sync_status_rows ?? null) ? $product_sync_status_rows : [], 0, 20);
$recentLogs = array_slice(is_array($logs ?? null) ? $logs : [], 0, 20);
$limitedNotReadyItems = array_slice($notReadyItems, 0, 20);
$loadProductSyncUrl = add_query_arg(['page' => 'woo-ebay', 'wei_section' => 'product-sync'], $adminPageUrl);
$loadCategoryMappingsUrl = add_query_arg(['page' => 'woo-ebay', 'wei_section' => 'category-mappings'], $adminPageUrl);
$formatNullableCount = static fn($value): string => $value === null ? 'not loaded' : (string) $value;
$technicalPreview = static function ($value, int $maxLength = 6000) use ($redactTechnical): string {
    $json = wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json = is_string($json) ? $redactTechnical($json) : '';
    if (strlen($json) > $maxLength) {
        return substr($json, 0, $maxLength) . "\n… truncated for admin preview …";
    }

    return $json;
};
?>
<?php
$logFilter = isset($_GET['wei_log_filter']) ? sanitize_key(wp_unslash((string) $_GET['wei_log_filter'])) : 'all';
$logFilters = ['all' => 'All', 'ebay' => 'eBay', 'publish' => 'Publish', 'order' => 'Order', 'sync' => 'Sync', 'category' => 'Category'];
if (!isset($logFilters[$logFilter])) {
    $logFilter = 'all';
}
$recentLogs = array_values(array_filter(array_slice(is_array($logs ?? null) ? $logs : [], 0, 50), static function (array $log) use ($logFilter): bool {
    if ($logFilter === 'all') {
        return true;
    }
    $haystack = strtolower((string) ($log['message'] ?? '') . ' ' . wp_json_encode($log['context'] ?? []));
    return str_contains($haystack, $logFilter);
}));
$recentLogs = array_slice($recentLogs, 0, 50);
$blockedByCategoryCount = (int) ($initialPublishCandidates['blocked_by_category'] ?? ($initialPublishSkippedReasons['blocked_by_category'] ?? $categoryBlockedCount));
$missingAspectsCount = (int) ($initialPublishCandidates['missing_aspects'] ?? ($initialPublishSkippedReasons['missing_aspects'] ?? ($readinessSummary['missing_required_aspects'] ?? 0)));
$publishedListingsCount = (int) ($initialPublishCandidates['already_published'] ?? ($initialPublishSkippedReasons['already_published'] ?? 0)) + (int) $initialPublishSuccess;
$queuedJobsCount = (int) ($autoSync['queued_products_count'] ?? 0);
$failedJobsCount = (int) ($autoSync['failed_queue_count'] ?? 0);
$cronActive = !empty($autoSync['next_run']) && empty($s['auto_sync_paused']);
$lastSyncResult = [
    'last_run' => (string) (($autoSync['last_run'] ?? '') ?: '-'),
    'last_success_at' => (string) ($autoSync['checkpoint']['last_success_at'] ?? '-'),
    'last_run_status' => (string) ($autoSync['checkpoint']['last_run_status'] ?? $autoStatus),
    'last_error' => (string) (($autoSync['checkpoint']['last_error'] ?? '') ?: '-'),
    'queued_products_count' => $queuedJobsCount,
    'failed_queue_count' => $failedJobsCount,
];
$movedModules = [
    'API readiness, token, policy refresh, raw cached policies' => 'Advanced diagnostics → Account / API diagnostics',
    'Listing quality audit, condition cleanup, dry-run payloads' => 'Advanced diagnostics → Listing quality / cleanup tools',
    'Shipping mapping reports and fulfillment policy batch tools' => 'Advanced diagnostics → Shipping and item-specific tools',
    'SKU generation and queue/process helpers' => 'Advanced diagnostics → Queue, SKU and checkpoint tools',
    'Manual export/publish/preflight forms' => 'Main actions, renamed as daily single-product workflow',
    'Readiness/category audit report tables' => 'Advanced diagnostics, with only operational counts surfaced in Dashboard / Category mapping',
];
$sectionLayout = ['Dashboard / Status', 'Main actions', 'Category mapping', 'Bulk publish', 'eBay sync', 'Advanced diagnostics', 'Recent logs'];
?>
<div class="wrap wei-admin">
    <h1>eBay Integration</h1>
    <style>
        .wei-admin .wei-box { background:#fff; border:1px solid #dcdcde; box-shadow:0 1px 1px rgba(0,0,0,.04); margin:16px 0; padding:16px; max-width:1240px; }
        .wei-admin .wei-box h2, .wei-admin .wei-box h3 { margin-top:0; }
        .wei-admin .wei-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin:12px 0; }
        .wei-admin .wei-card { border:1px solid #dcdcde; background:#f6f7f7; padding:10px 12px; min-height:62px; }
        .wei-admin .wei-card span { display:block; color:#646970; font-size:12px; }
        .wei-admin .wei-card strong { display:block; font-size:18px; line-height:1.35; overflow-wrap:anywhere; }
        .wei-admin .wei-badge { display:inline-block; padding:2px 8px; border-radius:999px; background:#f0f0f1; color:#1d2327; font-size:12px; font-weight:600; vertical-align:middle; }
        .wei-admin .wei-badge.ok { background:#edfaef; color:#008a20; }
        .wei-admin .wei-badge.warn { background:#fcf3db; color:#996800; }
        .wei-admin .wei-badge.error { background:#fcf0f1; color:#b32d2e; }
        .wei-admin .wei-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:10px 0; }
        .wei-admin .wei-actions form { display:inline-flex; gap:6px; align-items:center; margin:0; flex-wrap:wrap; }
        .wei-admin .wei-workflow { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:12px; }
        .wei-admin .wei-action-group { border-left:4px solid #dcdcde; padding:10px 0 10px 12px; margin:10px 0; }
        .wei-admin .wei-action-group.primary { border-left-color:#2271b1; }
        .wei-admin .wei-action-group.safe { border-left-color:#00a32a; }
        .wei-admin .wei-action-group.warning { border-left-color:#dba617; }
        .wei-admin .wei-publish-status { border-left:4px solid #2271b1; background:#f6f7f7; padding:12px 14px; margin:10px 0 0; max-width:720px; }
        .wei-admin .wei-publish-status p { margin:4px 0; }
        .wei-admin details.wei-box > summary { cursor:pointer; font-size:16px; font-weight:600; margin:-16px; padding:16px; }
        .wei-admin details.wei-box[open] > summary { border-bottom:1px solid #dcdcde; margin-bottom:16px; }
        .wei-admin details:not(.wei-box) > summary { cursor:pointer; font-weight:600; }
        .wei-admin .wei-scroll, .wei-admin textarea.code { max-height:360px; overflow:auto; white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; padding:10px; }
        .wei-admin .wei-scroll-table { max-height:440px; overflow:auto; border:1px solid #dcdcde; background:#fff; }
        .wei-admin .wei-scroll-table table { margin:0; border:0; }
        .wei-admin .form-table th { width:240px; }
        .wei-admin .description { color:#646970; }
        .wei-admin .wei-muted-list { columns:2; max-width:980px; }
        .wei-admin .wei-danger { border-left:4px solid #b32d2e; background:#fcf0f1; padding:10px 12px; }
    </style>

    <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>eBay settings saved.</p></div><?php endif; ?>

    <div class="wei-box">
        <h2>Dashboard / Status</h2>
        <p class="description">Krótki operacyjny stan integracji. Ten widok nie wykonuje pełnych skanów produktów ani nie uruchamia workerów.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>eBay account/API status</span><strong><span class="wei-badge <?php echo esc_attr($accountSetupMissingCount > 0 ? 'warn' : 'ok'); ?>"><?php echo esc_html($accountSetupMissingCount > 0 ? (string) $accountSetupMissingCount . ' missing' : 'configured'); ?></span></strong></div>
            <div class="wei-card"><span>Marketplace</span><strong><?php echo esc_html((string) $setting('marketplace_id', 'EBAY_DE')); ?></strong></div>
            <div class="wei-card"><span>Last successful sync</span><strong><?php echo esc_html((string) ($autoSync['checkpoint']['last_success_at'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Last error</span><strong><?php echo esc_html((string) (($autoSync['checkpoint']['last_error'] ?? $initialPublish['last_error'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Ready to publish</span><strong><?php echo esc_html((string) $initialPublishTotalReady); ?></strong></div>
            <div class="wei-card"><span>Blocked by category</span><strong><?php echo esc_html((string) $blockedByCategoryCount); ?></strong></div>
            <div class="wei-card"><span>Missing aspects</span><strong><?php echo esc_html((string) $missingAspectsCount); ?></strong></div>
            <div class="wei-card"><span>Published listings</span><strong><?php echo esc_html((string) $publishedListingsCount); ?></strong></div>
            <div class="wei-card"><span>Queued / failed jobs</span><strong><?php echo esc_html((string) $queuedJobsCount); ?> / <?php echo esc_html((string) $failedJobsCount); ?></strong></div>
            <div class="wei-card"><span>Cron</span><strong><span class="wei-badge <?php echo esc_attr($cronActive ? 'ok' : 'warn'); ?>"><?php echo esc_html($cronActive ? 'active' : 'inactive/paused'); ?></span></strong></div>
        </div>
        <details><summary>New panel layout and moved modules</summary>
            <h3>Nowy układ sekcji</h3>
            <ol><?php foreach ($sectionLayout as $section): ?><li><?php echo esc_html($section); ?></li><?php endforeach; ?></ol>
            <h3>Przeniesione moduły</h3>
            <table class="widefat striped"><thead><tr><th>Stary moduł / techniczna nazwa</th><th>Nowa lokalizacja</th></tr></thead><tbody><?php foreach ($movedModules as $old => $newPlace): ?><tr><td><?php echo esc_html($old); ?></td><td><?php echo esc_html($newPlace); ?></td></tr><?php endforeach; ?></tbody></table>
        </details>
    </div>

    <div class="wei-box">
        <h2>Main actions</h2>
        <p class="description">Główny workflow pojedynczego produktu: wpisz Woo product ID i przejdź od readiness przez preview do publikacji. Formularze używają istniejących action handlerów i nonce.</p>
        <div class="wei-workflow">
            <div class="wei-action-group primary">
                <h3>Check listing readiness for product</h3>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_preflight'); ?>
                    <input type="hidden" name="action" value="wei_preflight_product" />
                    <label>Product ID <input type="number" name="product_id" placeholder="Woo product ID" required /></label>
                    <button class="button button-primary">Check readiness</button>
                </form>
            </div>
            <div class="wei-action-group primary">
                <h3>Preview listing</h3>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_description_template_preview'); ?>
                    <input type="hidden" name="action" value="wei_description_template_preview" />
                    <label>Product ID / SKU <input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /></label>
                    <button class="button">Preview listing</button>
                </form>
            </div>
            <div class="wei-action-group primary">
                <h3>Publish single product</h3>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('This will publish a public eBay offer for the selected product if the existing handler allows it. Continue?');">
                    <?php wp_nonce_field('wei_publish_product_offer_only'); ?>
                    <input type="hidden" name="action" value="wei_publish_product_offer_only" />
                    <label>Product ID <input type="number" name="product_id" placeholder="Woo product ID" required /></label>
                    <button class="button button-primary">Publish single product</button>
                </form>
            </div>
            <div class="wei-action-group safe">
                <h3>Regenerate / refresh eBay content</h3>
                <p class="description">Safely regenerates German eBay content in local product meta only. Calls Google Translate via the configured provider, refreshes _wei_ebay_de_* cache/hash, and never updates or creates active eBay listings.</p>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_ebay_regenerate_german_content'); ?>
                    <input type="hidden" name="action" value="wei_ebay_regenerate_german_content" />
                    <label>Product ID <input type="number" name="product_id" placeholder="Woo product ID" required /></label>
                    <button class="button">Regenerate/refresh eBay content</button>
                </form>
            </div>
            <div class="wei-action-group safe">
                <h3>Export product payload</h3>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_export'); ?>
                    <input type="hidden" name="action" value="wei_export_product" />
                    <label>Product ID <input type="number" name="product_id" placeholder="Woo product ID" required /></label>
                    <input type="text" name="ebay_category_id" placeholder="optional category ID" />
                    <textarea name="ebay_aspects_json" placeholder='optional aspects JSON' rows="1"></textarea>
                    <button class="button">Export single product</button>
                </form>
            </div>
            <div class="wei-action-group safe">
                <h3>Last result as JSON</h3>
                <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['last_action' => $lastAction, 'product_id' => $lastProductId, 'stage' => $lastStage, 'message' => $lastShortMessage, 'payload' => $lastStatusPayload], 5000)); ?></pre>
            </div>
        </div>
    </div>

    <div class="wei-box">
        <h2>Kategorie eBay</h2>
        <p class="description">Sekcja mapowania WooCommerce → eBay. Generowanie CSV z Ovoko tylko pobiera dane i tworzy plik diagnostyczny — nie zmienia produktów, kategorii Woo ani aktywnych listingów.</p>
        <?php $ovokoConfidence = is_array($ovoko_category_suggestions_summary['confidence'] ?? null) ? $ovoko_category_suggestions_summary['confidence'] : []; ?>
        <div class="wei-grid">
            <div class="wei-card"><span>Liczba kategorii WooCommerce</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['woo_categories_total'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Liczba końcowych podkategorii</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['woo_leaf_categories'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Zmapowane kategorie</span><strong><?php echo esc_html((string) ((int) $categorySummary['mapped_manual'] + (int) $categorySummary['accepted_auto'])); ?></strong></div>
            <div class="wei-card"><span>Niezmapowane / review</span><strong><?php echo esc_html((string) $categoryMissingCount); ?></strong></div>
            <div class="wei-card"><span>Ovoko: listingi pobrane</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['ovoko_listings_fetched'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Ovoko: dostały ebay_category_id</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['mapped_categories'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Ovoko: bez dopasowania</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['unmapped_categories'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Confidence high/medium/low/none</span><strong><?php echo esc_html((string) (($ovokoConfidence['high'] ?? 0) . '/' . ($ovokoConfidence['medium'] ?? 0) . '/' . ($ovokoConfidence['low'] ?? 0) . '/' . ($ovokoConfidence['none'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Różne kategorie eBay</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['distinct_ebay_categories_detected'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Produkty w niezmapowanych</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['unmapped_products_count'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Ostatnie generowanie</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['generated_at'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Ostatni import CSV</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['imported_at'] ?? '-')); ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_import_category_teaching_csv'); ?>
                <input type="hidden" name="action" value="wei_import_category_teaching_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <input type="file" name="teaching_csv" accept=".csv,text/csv" required />
                <button class="button button-primary">Importuj CSV jako mapping produkcyjny</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_export_category_template_csv'); ?>
                <input type="hidden" name="action" value="wei_export_category_template_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <label><input type="checkbox" name="export_all_categories" value="1" /> eksportuj wszystkie kategorie</label>
                <button class="button">Eksportuj szablon CSV kategorii</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_export_ovoko_category_suggestions_csv'); ?>
                <input type="hidden" name="action" value="wei_export_ovoko_category_suggestions_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <label>Limit listingów Ovoko <input type="number" name="limit" min="1" max="10000" value="500" /></label>
                <label><input type="checkbox" name="export_all_categories" value="1" /> wszystkie kategorie</label>
                <label><input type="checkbox" name="force_refresh" value="1" /> odśwież cache</label>
                <button class="button">Auto-uzupełnij CSV z Ovoko/eBay</button>
            </form>
            <?php $ovokoCsv = is_array($ovoko_category_suggestions_summary['reports']['ovoko_suggestions_csv'] ?? null) ? $ovoko_category_suggestions_summary['reports']['ovoko_suggestions_csv'] : []; ?>
            <?php if (!empty($ovokoCsv['url'])): ?><a class="button button-primary" href="<?php echo esc_url((string) $ovokoCsv['url']); ?>">Pobierz ostatni CSV Ovoko</a><?php endif; ?>
            <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'readiness_filter' => 'blocked_by_category'], $adminPageUrl)); ?>">Produkty blocked_by_category</a>
            <a class="button" href="<?php echo esc_url($loadCategoryMappingsUrl); ?>">Tabela mapowań</a>
        </div>
        <p class="description">Importowane minimum CSV: <code>woo_subcategory_id</code> albo <code>woo_category_id</code> oraz <code>ebay_category_id</code>. Puste <code>ebay_category_id</code> są pomijane i raportowane jako <code>skipped_empty_ebay_id</code>.</p>
        <details><summary>Last import/export status JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['last_import' => $category_teaching_import_summary, 'last_template_export' => $category_template_export_summary, 'last_ovoko_export' => $ovoko_category_suggestions_summary, 'legacy_teaching_export' => $category_teaching_export_summary, 'last_manual_apply' => $manual_woo_category_apply_summary], 9000)); ?></pre></details>
        <?php if ($categoryRowsLoaded): ?>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Woo category</th><th>Products</th><th>eBay category</th><th>Status</th><th>Manual fallback</th></tr></thead><tbody><?php foreach ($filteredCategoryRows as $row): ?><tr><td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td><td><code><?php echo esc_html((string) ($row['_ui_category_id'] ?? '')); ?></code><br><?php echo esc_html((string) ($row['_ui_category_path'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['_ui_status'] ?? '')); ?></td><td><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_save_category_mapping'); ?><input type="hidden" name="action" value="wei_save_category_mapping" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="hidden" name="woo_term_id" value="<?php echo esc_attr((string) ($row['term_id'] ?? '0')); ?>" /><input type="text" name="ebay_category_id" placeholder="category ID" value="<?php echo esc_attr((string) ($row['ebay_category_id'] ?? '')); ?>" size="8" /><input type="text" name="ebay_category_name" placeholder="name" value="<?php echo esc_attr((string) ($row['ebay_category_name'] ?? '')); ?>" /><input type="text" name="ebay_category_path" placeholder="path" value="<?php echo esc_attr((string) ($row['ebay_category_path'] ?? '')); ?>" /><button class="button">Save mapping</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <div class="wei-box">
        <h2>Bulk publish</h2>
        <p class="description">Masowe wystawianie jest niżej niż workflow pojedynczego produktu. Używa istniejących kandydatów initial publish i istniejącego handlera batch.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>Status</span><strong><?php echo esc_html($initialPublishPublicationStatus); ?></strong></div>
            <div class="wei-card"><span>Current cursor</span><strong><?php echo esc_html((string) ($initialPublish['cursor'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Published total</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?></strong></div>
            <div class="wei-card"><span>Remaining</span><strong><?php echo esc_html((string) $initialPublishRemaining); ?></strong></div>
            <div class="wei-card"><span>Success / failed / skipped</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?> / <?php echo esc_html((string) $initialPublishFailed); ?> / <?php echo esc_html((string) ($initialPublishCandidates['skipped'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Last run</span><strong><?php echo esc_html($initialPublishLastRunAt !== '' ? $initialPublishLastRunAt : '-'); ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_ebay_rebuild_initial_publish_candidates'); ?>
                <input type="hidden" name="action" value="wei_ebay_rebuild_initial_publish_candidates" />
                <label>Batch size <input type="number" min="1" max="500" name="batch_size" value="100" /></label>
                <label><input type="checkbox" name="reset_rebuild" value="1" <?php checked(($initialPublishCandidates['status'] ?? '') !== 'in_progress'); ?> /> reset cursor</label>
                <button class="button">Build / refresh candidates</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('This can publish public eBay listings in a batch. Continue?');">
                <?php wp_nonce_field('wei_ebay_initial_publish_batch'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_batch" />
                <label>Batch size <input type="number" min="1" max="50" name="batch_size" value="5" /></label>
                <button class="button button-primary" <?php disabled($initialPublishPublicationStatus === 'paused'); ?>>Start batch</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_initial_publish_toggle_pause'); ?><input type="hidden" name="action" value="wei_ebay_initial_publish_toggle_pause" /><button class="button"><?php echo $initialPublishPublicationStatus === 'paused' ? 'Resume batch' : 'Pause batch'; ?></button></form>
        </div>
        <h3>Last batch log</h3>
        <pre class="wei-scroll"><?php echo esc_html($initialPublishLastBatch !== '-' ? implode("\n", $initialPublishLog) : '-'); ?></pre>
    </div>

    <div class="wei-box">
        <h2>eBay sync</h2>
        <p class="description">Synchronizacja zamówień i inventory/offers jest osobno od wystawiania produktów.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>Mode</span><strong><?php echo esc_html($autoModeLabels[(string) $setting('auto_sync_mode', 'disabled')] ?? (string) $setting('auto_sync_mode', 'disabled')); ?></strong></div>
            <div class="wei-card"><span>Last result</span><strong><?php echo esc_html((string) ($autoSync['checkpoint']['last_run_status'] ?? $autoStatus)); ?></strong></div>
            <div class="wei-card"><span>Next run</span><strong><?php echo esc_html((string) ($autoSync['next_run'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Paused</span><strong><?php echo !empty($s['auto_sync_paused']) ? 'Paused' : 'Running'; ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_sync_now'); ?><input type="hidden" name="action" value="wei_ebay_sync_now" /><button class="button button-primary">Run eBay scheduled sync manually</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_orders_now'); ?><input type="hidden" name="action" value="wei_auto_sync_orders_now" /><button class="button">Import orders now</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_stock_now'); ?><input type="hidden" name="action" value="wei_auto_sync_stock_now" /><input type="number" min="1" max="300" name="batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_stock_batch_size', 100)); ?>" /><button class="button">Sync inventory/offers now</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_toggle_pause'); ?><input type="hidden" name="action" value="wei_auto_sync_toggle_pause" /><button class="button"><?php echo !empty($s['auto_sync_paused']) ? 'Resume sync' : 'Pause sync'; ?></button></form>
        </div>
        <details><summary>Last sync result JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($lastSyncResult, 4000)); ?></pre></details>
    </div>

    <details class="wei-box">
        <summary>Advanced diagnostics <span class="wei-badge warn">collapsed by default</span></summary>
        <p class="wei-danger"><strong>Safety:</strong> these tools are rare or technical. They keep existing capability checks and nonces, but may run heavy scans, write diagnostics, reset checkpoints or process queues. Use only when needed.</p>
        <details><summary>Account / API diagnostics</summary>
            <div class="wei-actions"><a class="button button-primary" href="<?php echo esc_url($connectUrl); ?>">Connect eBay</a><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_upsert_inventory_location'); ?><input type="hidden" name="action" value="wei_upsert_inventory_location" /><button class="button">Create / update inventory location</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_refresh_policies'); ?><input type="hidden" name="action" value="wei_refresh_policies" /><button class="button">Refresh policies from eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run API readiness check</button></form></div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['cached_policies' => $cached, 'last_status' => $status, 'auto_sync' => array_diff_key($autoSync, array_flip(['readiness_summary']))], 8000)); ?></pre>
        </details>
        <details><summary>Settings</summary>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_save_settings'); ?>
                <input type="hidden" name="action" value="wei_save_settings" />
                <?php foreach ([
                    'environment', 'runame', 'default_category_id', 'default_item_condition', 'default_country_of_origin',
                    'auto_category_confidence_threshold', 'ebay_special_category_markup_percent', 'nbp_rate_cache_ttl_hours',
                    'sku_category_overrides', 'product_category_overrides', 'sku_aspect_overrides', 'category_aspect_fallbacks',
                    'default_hersteller_fallback', 'ebay_sku_prefix', 'stock_sync_mode', 'auto_sync_frequency',
                    'auto_sync_preflight_batch_size', 'ebay_stock_sync_mode', 'ebay_order_stock_update_mode', 'translation_provider',
                    'ebay_de_delivery_map_url', 'inventory_location_name', 'inventory_location_country', 'inventory_location_postal_code',
                    'inventory_location_city', 'inventory_location_address_line_1', 'shipping_category_ids_50_eur', 'shipping_category_ids_100_eur',
                ] as $preserveKey): ?>
                    <input type="hidden" name="<?php echo esc_attr($preserveKey); ?>" value="<?php echo esc_attr((string) $setting($preserveKey)); ?>" />
                <?php endforeach; ?>
                <?php foreach (['verbose_debug', 'woo_to_ebay_stock_sync_enabled', 'ebay_order_sync_enabled', 'auto_generate_german_content_preflight', 'enable_ebay_de_description_template', 'regenerate_german_content_on_hash_change'] as $preserveFlag): ?>
                    <?php if (!empty($s[$preserveFlag])): ?><input type="hidden" name="<?php echo esc_attr($preserveFlag); ?>" value="1" /><?php endif; ?>
                <?php endforeach; ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="wei-marketplace-id">Marketplace</label></th><td><input id="wei-marketplace-id" class="regular-text" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" placeholder="EBAY_DE" /></td></tr>
                    <tr><th><label for="wei-location-key">Merchant location key</label></th><td><input id="wei-location-key" class="regular-text" name="inventory_location_key" value="<?php echo esc_attr((string) $setting('inventory_location_key', 'gpswiss-pl')); ?>" /></td></tr>
                    <tr><th><label for="wei-fulfillment-policy-30">Fulfillment policy ID 30 EUR</label></th><td><input id="wei-fulfillment-policy-30" class="regular-text" name="fulfillment_policy_id_30_eur" value="<?php echo esc_attr($fulfillmentId30); ?>" /></td></tr>
                    <tr><th><label for="wei-fulfillment-policy-50">Fulfillment policy ID 50 EUR</label></th><td><input id="wei-fulfillment-policy-50" class="regular-text" name="fulfillment_policy_id_50_eur" value="<?php echo esc_attr($fulfillmentId50); ?>" /></td></tr>
                    <tr><th><label for="wei-fulfillment-policy-100">Fulfillment policy ID 100 EUR</label></th><td><input id="wei-fulfillment-policy-100" class="regular-text" name="fulfillment_policy_id_100_eur" value="<?php echo esc_attr($fulfillmentId100); ?>" /></td></tr>
                    <tr><th><label for="wei-payment-policy">Payment policy ID</label></th><td><input id="wei-payment-policy" class="regular-text" name="ebay_payment_policy_id" value="<?php echo esc_attr($paymentId); ?>" /></td></tr>
                    <tr><th><label for="wei-return-policy">Return policy ID</label></th><td><input id="wei-return-policy" class="regular-text" name="ebay_return_policy_id" value="<?php echo esc_attr($returnId); ?>" /></td></tr>
                    <tr><th><label for="wei-markup">Default markup %</label></th><td><input id="wei-markup" type="number" step="0.01" name="ebay_default_markup_percent" value="<?php echo esc_attr((string) $setting('ebay_default_markup_percent', 25)); ?>" /></td></tr>
                    <tr><th>Auto publish</th><td><label><input type="checkbox" name="auto_publish_enabled" value="1" <?php checked(!empty($s['auto_publish_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>Auto export</th><td><label><input type="checkbox" name="auto_export_enabled" value="1" <?php checked(!empty($s['auto_export_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>Auto sync mode</th><td><select name="auto_sync_mode"><?php foreach ($autoModeLabels as $mode => $label): ?><option value="<?php echo esc_attr($mode); ?>" <?php selected((string) $setting('auto_sync_mode', 'disabled'), $mode); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><label for="wei-export-batch-size">Export batch size</label></th><td><input id="wei-export-batch-size" type="number" min="1" max="50" name="auto_sync_export_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_export_batch_size', 20)); ?>" /></td></tr>
                    <tr><th><label for="wei-stock-batch-size">Stock batch size</label></th><td><input id="wei-stock-batch-size" type="number" min="1" max="300" name="auto_sync_stock_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_stock_batch_size', 100)); ?>" /></td></tr>
                    <tr><th><label for="wei-client-id">Client ID</label></th><td><input id="wei-client-id" class="regular-text" name="client_id" value="<?php echo esc_attr((string) $setting('client_id')); ?>" /></td></tr>
                    <tr><th><label for="wei-client-secret">Client secret</label></th><td><input id="wei-client-secret" class="regular-text" type="password" name="client_secret" value="<?php echo esc_attr((string) $setting('client_secret')); ?>" autocomplete="new-password" /> <span class="description"><?php echo esc_html($maskSecret((string) $setting('client_secret'))); ?></span></td></tr>
                    <tr><th><label for="wei-redirect-uri">Redirect URI</label></th><td><input id="wei-redirect-uri" class="large-text" name="redirect_uri" value="<?php echo esc_attr((string) $setting('redirect_uri')); ?>" /></td></tr>
                </table>
                <p><button class="button button-primary">Save settings</button></p>
            </form>
        </details>
        <details><summary>Readiness scans and category reports</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_readiness_now'); ?><input type="hidden" name="action" value="wei_auto_sync_readiness_now" /><button class="button button-primary">Run readiness scan now</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_full_category_audit'); ?><input type="hidden" name="action" value="wei_full_category_audit" /><button class="button">Run full category audit</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_missing_german_content_audit'); ?><input type="hidden" name="action" value="wei_generate_missing_german_content_audit" /><input type="hidden" name="restart" value="1" /><input type="number" min="1" max="200" name="batch_size" value="50" /><button class="button">Generate missing German content only</button></form></div>
            <p><?php foreach ($readinessFilterLabels as $filter => $label): ?><a href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'readiness_filter' => $filter], $adminPageUrl)); ?>"><?php echo $readinessFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === array_key_last($readinessFilterLabels) ? '' : ' / '; ?><?php endforeach; ?></p>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Product</th><th>Status</th><th>Reason</th><th>Errors</th></tr></thead><tbody><?php foreach ($limitedNotReadyItems as $item): ?><tr><td><?php echo esc_html((string) ($item['product_id'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['status'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['primary_reason'] ?? '')); ?></td><td><?php echo esc_html(implode('; ', array_map('strval', (array) ($item['errors'] ?? [])))); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['readiness_counts' => array_diff_key($readinessSummary, array_flip(['not_ready_items', 'blocked_by_category_items', 'missing_required_aspects_items', 'invalid_price_items'])), 'full_category_audit' => $full_category_audit_summary, 'german_content_audit' => $german_content_audit_summary], 6000)); ?></pre>
        </details>
        <details><summary>Category mapping technical tools</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_map_categories'); ?><input type="hidden" name="action" value="wei_auto_map_categories" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Re-evaluate category mappings</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_blocked_category_mappings'); ?><input type="hidden" name="action" value="wei_repair_blocked_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Re-evaluate blocked only</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_audit_category_groups'); ?><input type="hidden" name="action" value="wei_repair_audit_category_groups" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Repair from audit groups</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_apply_manual_woo_category_mappings'); ?><input type="hidden" name="action" value="wei_apply_manual_woo_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Apply manual mappings to products</button></form></div>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_test_category_teaching_rule_match'); ?><input type="hidden" name="action" value="wei_test_category_teaching_rule_match" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="number" name="product_id" placeholder="Woo product ID" /><button class="button">Test teaching rule match</button></form>
        </details>
        <details><summary>Listing quality / cleanup tools</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_listing_quality_audit'); ?><input type="hidden" name="action" value="wei_generate_listing_quality_audit" /><button class="button">Generate listing quality audit</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_condition_cleanup_single'); ?><input type="hidden" name="action" value="wei_condition_cleanup_single" /><input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /><button class="button">Clean condition/aspects</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_description_condition_cleanup_single'); ?><input type="hidden" name="action" value="wei_description_condition_cleanup_single" /><input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /><button class="button">Clean description condition wording</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_basic_specifics_single'); ?><input type="hidden" name="action" value="wei_basic_specifics_single" /><input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /><button class="button">Update basic item specifics</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_description_template_publish_dry_run'); ?><input type="hidden" name="action" value="wei_description_template_publish_dry_run" /><input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /><button class="button">Dry-run description payload</button></form></div>
            <?php if (!empty($listing_quality_audit)): ?><pre class="wei-scroll"><?php echo esc_html($technicalPreview($listing_quality_audit, 8000)); ?></pre><?php endif; ?>
        </details>
        <details><summary>Shipping, queue, SKU and destructive tools</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_shipping_mapping_report'); ?><input type="hidden" name="action" value="wei_generate_shipping_mapping_report" /><button class="button">Generate shipping mapping report</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_update_shipping_policy_one'); ?><input type="hidden" name="action" value="wei_update_shipping_policy_one" /><input type="number" min="1" name="product_id" placeholder="Product ID" /><input name="sku" placeholder="or SKU" /><button class="button">Update shipping policy for one product</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_process_queue_now'); ?><input type="hidden" name="action" value="wei_ebay_process_queue_now" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Process eBay queue now</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_rebuild_ready_queue'); ?><input type="hidden" name="action" value="wei_ebay_rebuild_ready_queue" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Rebuild ready queue</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_ebay_skus'); ?><input type="hidden" name="action" value="wei_generate_ebay_skus" /><input type="number" name="batch_size" value="200" min="1" max="1000" /><button class="button">Generate missing eBay SKUs</button></form></div>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Reset progress initial publish? Wpisz RESET w polu potwierdzenia.');"><?php wp_nonce_field('wei_ebay_initial_publish_reset'); ?><input type="hidden" name="action" value="wei_ebay_initial_publish_reset" /><input type="text" name="confirm_reset" placeholder="RESET" size="8" /><button class="button button-link-delete">Reset bulk publish progress</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync'); ?><input type="hidden" name="action" value="wei_sync_stock" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Sync stock for one product</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_import_order'); ?><input type="hidden" name="action" value="wei_import_order" /><input class="regular-text" name="order_id" placeholder="optional eBay order ID" /><button class="button">Import one eBay order</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_verify_api_publishing_readiness'); ?><input type="hidden" name="action" value="wei_verify_api_publishing_readiness" /><input type="number" name="product_id" placeholder="Woo product ID" required /><label><input type="checkbox" name="write_diagnostic_offer" value="1" /> write diagnostic offer</label><button class="button">Verify API publishing readiness</button></form></div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['shipping_mapping_report' => $shipping_mapping_report, 'shipping_policy_bulk_status' => $shipping_policy_bulk_status, 'basic_specifics_bulk_status' => $basic_specifics_bulk_status, 'sku_status' => $ebay_sku_status, 'sku_generation' => $ebay_sku_generation_status], 8000)); ?></pre>
        </details>
        <details><summary>Product sync status rows</summary>
            <p><a class="button" href="<?php echo esc_url($loadProductSyncUrl); ?>">Load recent product sync rows</a></p>
            <?php if ($productSyncRowsLoaded): ?><div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Product</th><th>eBay SKU / inventory_id</th><th>offer_id</th><th>listing_id</th><th>public_url</th><th>last_export_at</th><th>last_publish_at</th><th>last_sync_status</th><th>last_sync_error</th><th>listing status</th></tr></thead><tbody><?php foreach ($productSyncRows as $row): ?><?php $editUrl = $toScalarString($row['edit_url'] ?? ''); $publicUrl = $toScalarString($row['public_url'] ?? ''); $skuOrInventory = $displayValue($row['sku'] ?? '') !== '—' ? $displayValue($row['sku'] ?? '') : $displayValue($row['inventory_id'] ?? ''); ?><tr><td><?php if ($editUrl !== ''): ?><a href="<?php echo esc_url($editUrl); ?>"><?php endif; ?>#<?php echo esc_html($displayValue($row['product_id'] ?? '')); ?> <?php echo esc_html($displayValue($row['title'] ?? '')); ?><?php if ($editUrl !== ''): ?></a><?php endif; ?></td><td><code><?php echo esc_html($skuOrInventory); ?></code></td><td><code><?php echo esc_html($displayValue($row['offer_id'] ?? '')); ?></code></td><td><code><?php echo esc_html($displayValue($row['listing_id'] ?? '')); ?></code></td><td><?php if ($publicUrl !== ''): ?><a href="<?php echo esc_url($publicUrl); ?>" target="_blank" rel="noopener noreferrer">open</a><?php else: ?><?php echo esc_html('—'); ?><?php endif; ?></td><td><?php echo esc_html($displayValue($row['last_export_at'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_publish_at'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_sync_status'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_sync_error'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['listing_status'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="description">Rows are not loaded during normal render.</p><?php endif; ?>
        </details>
    </details>

    <div class="wei-box">
        <h2>Recent logs</h2>
        <p class="description">Ostatnie logi z prostym filtrem i zwijanym technical JSON.</p>
        <p><?php foreach ($logFilters as $filter => $label): ?><a href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'wei_log_filter' => $filter], $adminPageUrl)); ?>"><?php echo $logFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === array_key_last($logFilters) ? '' : ' / '; ?><?php endforeach; ?></p>
        <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>At</th><th>Level</th><th>Summary</th><th>Technical JSON</th></tr></thead><tbody><?php foreach ($recentLogs as $log): ?><?php $message = (string) ($log['message'] ?? ''); $short = strlen($message) > 180 ? substr($message, 0, 177) . '...' : $message; ?><tr><td><?php echo esc_html((string) ($log['at'] ?? '')); ?></td><td><?php echo esc_html((string) ($log['level'] ?? '')); ?></td><td><?php echo esc_html($short); ?></td><td><details><summary>show JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($log['context'] ?? [], 2500)); ?></pre></details></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
</div>
