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
$ebayCategorySuggestionsSummary = get_option('wei_ebay_category_suggestions_summary', []);
$ebayCategorySuggestionsSummary = is_array($ebayCategorySuggestionsSummary) ? $ebayCategorySuggestionsSummary : [];
$ebayCategorySuggestionsProgress = get_option('wei_ebay_category_suggestions_checkpoint', []);
$ebayCategorySuggestionsProgress = is_array($ebayCategorySuggestionsProgress) ? $ebayCategorySuggestionsProgress : [];
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
$publishSummary = is_array($initialPublish['summary'] ?? null) ? $initialPublish['summary'] : [];
$ebayListingStateSummary = is_array($ebay_listing_state_summary ?? null) ? $ebay_listing_state_summary : [];
$initialPublishSkippedThisRun = (int) ($initialPublish['skipped'] ?? 0);
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

$oauthDiagnostics = is_array($oauth_diagnostics ?? null) ? $oauth_diagnostics : [];
$oauthCallbackDebug = [
    'oauth_status' => (string) ($oauthDiagnostics['oauth_status'] ?? ''),
    'callback_intercepted_by_admin_init' => $oauthDiagnostics['callback_intercepted_by_admin_init'] ?? null,
    'callback_page_registered' => $oauthDiagnostics['callback_page_registered'] ?? null,
    'page_param' => (string) ($oauthDiagnostics['page_param'] ?? ''),
    'code_received' => $oauthDiagnostics['code_received'] ?? null,
    'state_received' => $oauthDiagnostics['state_received'] ?? null,
    'state_valid' => $oauthDiagnostics['state_valid'] ?? null,
    'token_exchange_attempted' => $oauthDiagnostics['token_exchange_attempted'] ?? null,
    'token_exchange_success' => $oauthDiagnostics['token_exchange_success'] ?? null,
    'token_exchange_error' => (string) ($oauthDiagnostics['token_exchange_error'] ?? ''),
    'refresh_token_saved' => $oauthDiagnostics['refresh_token_saved'] ?? null,
    'has_refresh_token' => !empty($oauthDiagnostics['has_refresh_token']),
    'callback_url' => (string) ($oauthDiagnostics['callback_url'] ?? ''),
    'browser_callback_url' => (string) ($oauthDiagnostics['browser_callback_url'] ?? ($oauthDiagnostics['callback_url'] ?? '')),
    'ebay_runame' => (string) ($oauthDiagnostics['ebay_runame'] ?? ''),
    'oauth_redirect_param_used' => (string) ($oauthDiagnostics['oauth_redirect_param_used'] ?? ''),
];
$oauthCallbackMessage = [
    'oauth_error' => isset($_GET['oauth_error']) ? sanitize_text_field(wp_unslash((string) $_GET['oauth_error'])) : '',
    'error_description' => isset($_GET['error_description']) ? sanitize_text_field(wp_unslash((string) $_GET['error_description'])) : '',
    'state_valid' => isset($_GET['state_valid']) ? sanitize_text_field(wp_unslash((string) $_GET['state_valid'])) : '',
    'redirect_uri_used' => isset($_GET['redirect_uri_used']) ? sanitize_text_field(wp_unslash((string) $_GET['redirect_uri_used'])) : '',
];
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
$sectionLayout = ['Dashboard / Status', 'German Content', 'Kategorie eBay', 'Publish', 'Advanced / Debug', 'Recent logs'];
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

    <div class="wei-box" data-wei-module="german-content">
        <h2>1. German Content</h2>
        <p class="description">Generate German title, description, listing template data, Spezifikationen and Artikelmerkmale for eBay.de. These actions write only local German-content meta and do not call eBay APIs.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>Translation provider</span><strong><?php echo esc_html($translationLabel); ?></strong></div>
            <div class="wei-card"><span>Last processed</span><strong><?php echo esc_html((string) ($german_content_audit_summary['processed'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Generated</span><strong><?php echo esc_html((string) ($german_content_audit_summary['generated'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Already fresh</span><strong><?php echo esc_html((string) ($german_content_audit_summary['already_ready'] ?? ($german_content_audit_summary['already_fresh'] ?? '-'))); ?></strong></div>
            <div class="wei-card"><span>Errors</span><strong><?php echo esc_html((string) ($german_content_audit_summary['failed'] ?? ($german_content_audit_summary['errors'] ?? '-'))); ?></strong></div>
            <div class="wei-card"><span>eBay API calls</span><strong>false</strong></div>
        </div>
        <div class="wei-actions" data-wei-primary-actions="german-content">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_generate_german_content_batch'); ?>
                <input type="hidden" name="action" value="wei_generate_german_content_batch" />
                <input type="hidden" name="mode" value="all" />
                <label>Batch size <input type="number" min="1" max="200" name="batch_size" value="50" /></label>
                <button class="button button-primary">Generate / refresh German content for all products</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_generate_german_content_batch'); ?>
                <input type="hidden" name="action" value="wei_generate_german_content_batch" />
                <input type="hidden" name="mode" value="stale" />
                <label>Batch size <input type="number" min="1" max="200" name="batch_size" value="50" /></label>
                <button class="button">Generate / refresh stale German content only</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_description_template_preview'); ?>
                <input type="hidden" name="action" value="wei_description_template_preview" />
                <label>Product ID / SKU <input type="text" name="product_or_sku" placeholder="Product ID or SKU" required /></label>
                <button class="button">Preview German listing template</button>
            </form>
        </div>
        <details><summary>German Content last summary</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['processed' => (int) ($german_content_audit_summary['processed'] ?? 0), 'generated' => (int) ($german_content_audit_summary['generated'] ?? 0), 'already_fresh' => (int) ($german_content_audit_summary['already_ready'] ?? $german_content_audit_summary['already_fresh'] ?? 0), 'stale_fixed' => (int) ($german_content_audit_summary['stale_fixed'] ?? 0), 'errors' => (int) ($german_content_audit_summary['failed'] ?? $german_content_audit_summary['errors'] ?? 0), 'google_api_called' => !empty($german_content_audit_summary['google_api_called']), 'called_ebay_api' => false, 'updated_ebay_listing' => false, 'report_url' => (string) ($german_content_audit_summary['reports']['csv']['url'] ?? '')], 4000)); ?></pre></details>
    </div>

    <div class="wei-box" data-wei-module="ebay-categories">
        <h2>2. Kategorie eBay</h2>
        <p class="description">Check and maintain WooCommerce product_cat → eBay.de category ID mapping. Main actions focus on CSV import/export and readiness validation; suggestion generators and auto-mapping are in Advanced / Debug.</p>
        <?php $ovokoConfidence = is_array($ovoko_category_suggestions_summary['confidence'] ?? null) ? $ovoko_category_suggestions_summary['confidence'] : []; ?>
        <div class="wei-grid">
            <div class="wei-card"><span>total_categories</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['woo_categories_total'] ?? $categorySummary['total'])); ?></strong></div>
            <div class="wei-card"><span>mapped_categories</span><strong><?php echo esc_html((string) ((int) $categorySummary['mapped_manual'] + (int) $categorySummary['accepted_auto'])); ?></strong></div>
            <div class="wei-card"><span>valid_categories</span><strong><?php echo esc_html((string) ((int) $categorySummary['mapped_manual'] + (int) $categorySummary['accepted_auto'])); ?></strong></div>
            <div class="wei-card"><span>invalid_category_id</span><strong><?php echo esc_html((string) ($categorySummary['blocked_by_expected_keyword'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>non_leaf_category</span><strong><?php echo esc_html((string) ($categorySummary['blocked_by_sonstige'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>blocked_by_category</span><strong><?php echo esc_html((string) $categoryBlockedCount); ?></strong></div>
            <div class="wei-card"><span>needs_review</span><strong><?php echo esc_html((string) $categoryMissingCount); ?></strong></div>
            <div class="wei-card"><span>products_affected</span><strong><?php echo esc_html((string) ($ovoko_category_suggestions_summary['unmapped_products_count'] ?? $blockedByCategoryCount)); ?></strong></div>
            <div class="wei-card"><span>report_url</span><strong><?php $categoryReportUrl = (string) ($category_template_export_summary['reports']['template_csv']['url'] ?? $category_teaching_export_summary['reports']['teaching_csv']['url'] ?? ''); echo $categoryReportUrl !== '' ? '<a href="' . esc_url($categoryReportUrl) . '">open</a>' : '—'; ?></strong></div>
        </div>
        <div class="wei-actions" data-wei-primary-actions="categories">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_export_category_template_csv'); ?>
                <input type="hidden" name="action" value="wei_export_category_template_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <label><input type="checkbox" name="export_all_categories" value="1" /> include empty categories</label>
                <button class="button button-primary">Export category mapping CSV</button>
            </form>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_import_category_teaching_csv'); ?>
                <input type="hidden" name="action" value="wei_import_category_teaching_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <input type="file" name="teaching_csv" accept=".csv,text/csv" required />
                <button class="button button-primary">Import category mapping CSV</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_full_category_audit'); ?>
                <input type="hidden" name="action" value="wei_full_category_audit" />
                <button class="button">Validate current category mappings</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_full_category_audit'); ?>
                <input type="hidden" name="action" value="wei_full_category_audit" />
                <input type="hidden" name="verbose_debug" value="1" />
                <button class="button">Run category readiness audit</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_export_category_teaching_csv'); ?>
                <input type="hidden" name="action" value="wei_export_category_teaching_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <button class="button">Export blocked category report</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_generate_blocked_category_fix_report'); ?>
                <input type="hidden" name="action" value="wei_generate_blocked_category_fix_report" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" />
                <button class="button button-primary">Generate blocked category fix report</button>
            </form>
            <a class="button" href="<?php echo esc_url($loadCategoryMappingsUrl); ?>">Open mapping table</a>
        </div>
        <p class="description">Import CSV minimum: <code>woo_subcategory_id</code> or <code>woo_category_id</code> plus <code>ebay_category_id</code>. Empty <code>ebay_category_id</code> rows are skipped.</p>
        <details><summary>Category last summary JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['total_categories' => ($ovoko_category_suggestions_summary['woo_categories_total'] ?? $categorySummary['total']), 'mapped_categories' => ((int) $categorySummary['mapped_manual'] + (int) $categorySummary['accepted_auto']), 'valid_categories' => ((int) $categorySummary['mapped_manual'] + (int) $categorySummary['accepted_auto']), 'invalid_category_id' => ($categorySummary['blocked_by_expected_keyword'] ?? 0), 'non_leaf_category' => ($categorySummary['blocked_by_sonstige'] ?? 0), 'blocked_by_category' => $categoryBlockedCount, 'needs_review' => $categoryMissingCount, 'products_affected' => ($ovoko_category_suggestions_summary['unmapped_products_count'] ?? $blockedByCategoryCount), 'report_url' => $categoryReportUrl, 'last_import' => $category_teaching_import_summary, 'last_export' => $category_template_export_summary], 6000)); ?></pre></details>
        <?php if ($categoryRowsLoaded): ?>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Woo category</th><th>Products</th><th>eBay category</th><th>Status</th><th>Manual fallback</th></tr></thead><tbody><?php foreach ($filteredCategoryRows as $row): ?><tr><td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td><td><code><?php echo esc_html((string) ($row['_ui_category_id'] ?? '')); ?></code><br><?php echo esc_html((string) ($row['_ui_category_path'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['_ui_status'] ?? '')); ?></td><td><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_save_category_mapping'); ?><input type="hidden" name="action" value="wei_save_category_mapping" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="hidden" name="woo_term_id" value="<?php echo esc_attr((string) ($row['term_id'] ?? '0')); ?>" /><input type="text" name="ebay_category_id" placeholder="category ID" value="<?php echo esc_attr((string) ($row['ebay_category_id'] ?? '')); ?>" size="8" /><input type="text" name="ebay_category_name" placeholder="name" value="<?php echo esc_attr((string) ($row['ebay_category_name'] ?? '')); ?>" /><input type="text" name="ebay_category_path" placeholder="path" value="<?php echo esc_attr((string) ($row['ebay_category_path'] ?? '')); ?>" /><button class="button">Save mapping</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </div>

    <div class="wei-box" data-wei-module="publish">
        <h2>3. Publish</h2>
        <p class="description">Publish ready products only. The publish batch preflights each product, skips anything not ready, creates/replaces inventory, creates/updates the offer through the existing exporter, publishes, and stores offer_id/listing_id/public_url meta when eBay returns them.</p>
        <p class="wei-danger"><strong>Warning:</strong> These counters are from the last publish run. Reset progress before starting a new full publish run if listings were ended manually on eBay.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>processed_this_run</span><strong><?php echo esc_html((string) ($initialPublish['processed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>exported_this_run</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?></strong></div>
            <div class="wei-card"><span>published_this_run</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?></strong></div>
            <div class="wei-card"><span>skipped_this_run</span><strong><?php echo esc_html((string) $initialPublishSkippedThisRun); ?></strong></div>
            <div class="wei-card"><span>errors_this_run</span><strong><?php echo esc_html((string) $initialPublishFailed); ?></strong></div>
            <div class="wei-card"><span>ready</span><strong><?php echo esc_html((string) $initialPublishTotalReady); ?></strong></div>
            <div class="wei-card"><span>current_active_listing_count</span><strong><?php echo esc_html((string) ($publishSummary['current_active_listing_count'] ?? $ebayListingStateSummary['current_active_listing_count'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>needs_reexport_count</span><strong><?php echo esc_html((string) ($publishSummary['needs_reexport_count'] ?? $ebayListingStateSummary['needs_reexport_count'] ?? 0)); ?></strong></div>
        </div>
        <div class="wei-actions" data-wei-primary-actions="publish">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_auto_sync_readiness_now'); ?>
                <input type="hidden" name="action" value="wei_auto_sync_readiness_now" />
                <label>Batch size <input type="number" min="1" max="300" name="batch_size" value="200" /></label>
                <button class="button">Run readiness scan</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_auto_sync_export_now'); ?>
                <input type="hidden" name="action" value="wei_auto_sync_export_now" />
                <label>Batch size <input type="number" min="1" max="50" name="batch_size" value="20" /></label>
                <button class="button">Export ready products to eBay</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('This can publish public eBay listings for ready products only. Continue?');">
                <?php wp_nonce_field('wei_ebay_initial_publish_batch'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_batch" />
                <label>Batch size <input type="number" min="1" max="50" name="batch_size" value="5" /></label>
                <button class="button button-primary" <?php disabled($initialPublishPublicationStatus === 'paused'); ?>>Publish ready offers</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Publish ready products runs the existing ready-product publish batch. It skips not_ready products. Continue?');">
                <?php wp_nonce_field('wei_publish_ready_products'); ?>
                <input type="hidden" name="action" value="wei_publish_ready_products" />
                <label>Batch size <input type="number" min="1" max="50" name="batch_size" value="5" /></label>
                <button class="button button-primary" <?php disabled($initialPublishPublicationStatus === 'paused'); ?>>Publish ready products</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Reset publish progress / counters? This clears only publish progress, cursors, checkpoints and counters. It does not delete category mappings, German content, OAuth tokens, policies, prices, stock, images or Woo products. Type RESET to confirm.');">
                <?php wp_nonce_field('wei_ebay_initial_publish_reset'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_reset" />
                <input type="text" name="confirm_reset" placeholder="RESET" size="8" />
                <button class="button button-link-delete">Resetuj postęp publikacji</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Refresh current eBay listing and offer state via eBay API?');">
                <?php wp_nonce_field('wei_refresh_ebay_listing_state'); ?>
                <input type="hidden" name="action" value="wei_refresh_ebay_listing_state" />
                <label>Batch size <input type="number" min="1" max="500" name="batch_size" value="100" /></label>
                <button class="button">Refresh eBay listing state</button>
            </form>
        </div>
        <details><summary>Publish summary and last batch log</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['processed_this_run' => (int) ($initialPublish['processed'] ?? 0), 'exported_this_run' => (int) $initialPublishSuccess, 'published_this_run' => (int) $initialPublishSuccess, 'skipped_this_run' => (int) $initialPublishSkippedThisRun, 'errors_this_run' => (int) $initialPublishFailed, 'historical_published_count' => (int) ($publishSummary['historical_published_count'] ?? 0), 'current_active_listing_count' => (int) ($publishSummary['current_active_listing_count'] ?? $ebayListingStateSummary['current_active_listing_count'] ?? 0), 'current_offer_count' => (int) ($publishSummary['current_offer_count'] ?? $ebayListingStateSummary['current_offer_count'] ?? 0), 'publish_progress_published_this_run' => (int) ($publishSummary['publish_progress_published_this_run'] ?? $initialPublishSuccess), 'published_total_from_old_checkpoint' => (int) ($publishSummary['published_total_from_old_checkpoint'] ?? 0), 'needs_reexport_count' => (int) ($publishSummary['needs_reexport_count'] ?? $ebayListingStateSummary['needs_reexport_count'] ?? 0), 'ended_listing_count' => (int) ($publishSummary['ended_listing_count'] ?? $ebayListingStateSummary['ended_listing_count'] ?? 0), 'ready' => (int) $initialPublishTotalReady, 'last_ebay_state_refresh' => $ebayListingStateSummary, 'last_batch_log' => $initialPublishLog], 8000)); ?></pre></details>
    </div>

    <details class="wei-box">
        <summary>Advanced / Debug <span class="wei-badge warn">collapsed by default</span></summary>
        <p class="wei-danger"><strong>Safety:</strong> these tools are rare or technical. They keep existing capability checks and nonces, but may run heavy scans, write diagnostics, reset checkpoints or process queues. Use only when needed.</p>
        <details><summary>Advanced / Debug: eBay sync and order tools</summary>
            <p class="description">Synchronizacja zamówień i inventory/offers jest ukryta poza głównym trzyetapowym flow.</p>
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
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview($lastSyncResult, 4000)); ?></pre>
        </details>
        <details><summary>Advanced / Debug: single-product publish and content tools</summary>
            <div class="wei-actions">
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_preflight'); ?><input type="hidden" name="action" value="wei_preflight_product" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Run single-product readiness preflight</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_inspect_offer_before_publish'); ?><input type="hidden" name="action" value="wei_inspect_offer_before_publish" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Inspect offer before publish</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Manual publish offer only can publish one public eBay listing. Continue?');"><?php wp_nonce_field('wei_publish_product_offer_only'); ?><input type="hidden" name="action" value="wei_publish_product_offer_only" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Manual publish offer only</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_export'); ?><input type="hidden" name="action" value="wei_export_product" /><input type="number" name="product_id" placeholder="Woo product ID" required /><input type="text" name="ebay_category_id" placeholder="optional category ID" /><textarea name="ebay_aspects_json" placeholder="optional aspects JSON" rows="1"></textarea><button class="button">Export single product</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_regenerate_german_content'); ?><input type="hidden" name="action" value="wei_ebay_regenerate_german_content" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Regenerate German content for one product</button></form>
            </div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['last_action' => $lastAction, 'product_id' => $lastProductId, 'stage' => $lastStage, 'message' => $lastShortMessage, 'payload' => $lastStatusPayload], 5000)); ?></pre>
        </details>
        <details><summary>Account / API diagnostics</summary>
            <div class="wei-actions"><a class="button button-primary" href="<?php echo esc_url($connectUrl); ?>">Connect eBay</a><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_upsert_inventory_location'); ?><input type="hidden" name="action" value="wei_upsert_inventory_location" /><button class="button">Create / update inventory location</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_refresh_policies'); ?><input type="hidden" name="action" value="wei_refresh_policies" /><button class="button">Refresh policies from eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run API readiness check</button></form></div>
            <?php if (!empty($oauthCallbackMessage['oauth_error'])): ?><div class="notice notice-error inline"><p><strong>eBay OAuth error:</strong> <?php echo esc_html($oauthCallbackMessage['oauth_error']); ?> <?php echo $oauthCallbackMessage['error_description'] !== '' ? esc_html(' — ' . $oauthCallbackMessage['error_description']) : ''; ?></p><pre class="wei-scroll"><?php echo esc_html($technicalPreview($oauthCallbackMessage, 3000)); ?></pre></div><?php endif; ?>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['oauth' => $oauthCallbackDebug, 'cached_policies' => $cached, 'last_status' => $status, 'auto_sync' => array_diff_key($autoSync, array_flip(['readiness_summary']))], 8000)); ?></pre>
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
                    'ebay_de_delivery_map_url', 'ebay_seller_username', 'inventory_location_name', 'inventory_location_country', 'inventory_location_postal_code',
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
                    <tr><th><label for="wei-ebay-seller-username">eBay seller username</label></th><td><input id="wei-ebay-seller-username" class="regular-text" name="ebay_seller_username" value="<?php echo esc_attr((string) $setting('ebay_seller_username')); ?>" /> <span class="description">Used for eBay.de same-vehicle token search CTA.</span></td></tr>
                    <tr><th>Auto publish</th><td><label><input type="checkbox" name="auto_publish_enabled" value="1" <?php checked(!empty($s['auto_publish_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>Auto export</th><td><label><input type="checkbox" name="auto_export_enabled" value="1" <?php checked(!empty($s['auto_export_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>Auto sync mode</th><td><select name="auto_sync_mode"><?php foreach ($autoModeLabels as $mode => $label): ?><option value="<?php echo esc_attr($mode); ?>" <?php selected((string) $setting('auto_sync_mode', 'disabled'), $mode); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><label for="wei-export-batch-size">Export batch size</label></th><td><input id="wei-export-batch-size" type="number" min="1" max="50" name="auto_sync_export_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_export_batch_size', 20)); ?>" /></td></tr>
                    <tr><th><label for="wei-stock-batch-size">Stock batch size</label></th><td><input id="wei-stock-batch-size" type="number" min="1" max="300" name="auto_sync_stock_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_stock_batch_size', 100)); ?>" /></td></tr>
                    <tr><th><label for="wei-client-id">Client ID</label></th><td><input id="wei-client-id" class="regular-text" name="client_id" value="<?php echo esc_attr((string) $setting('client_id')); ?>" /></td></tr>
                    <tr><th><label for="wei-client-secret">Client secret</label></th><td><input id="wei-client-secret" class="regular-text" type="password" name="client_secret" value="<?php echo esc_attr((string) $setting('client_secret')); ?>" autocomplete="new-password" /> <span class="description"><?php echo esc_html($maskSecret((string) $setting('client_secret'))); ?></span></td></tr>
                    <tr><th><label for="wei-runame">eBay RuName</label></th><td><input id="wei-runame" class="large-text" name="runame" value="<?php echo esc_attr((string) $setting('runame', (string) ($oauthCallbackDebug['ebay_runame'] ?? ''))); ?>" /> <span class="description">Used as the OAuth <code>redirect_uri</code> parameter in the eBay authorization URL and token exchange.</span></td></tr>
                    <tr><th><label for="wei-redirect-uri">Browser callback URL</label></th><td><input id="wei-redirect-uri" class="large-text" name="redirect_uri" value="<?php echo esc_attr((string) $setting('redirect_uri', (string) ($oauthCallbackDebug['browser_callback_url'] ?? ''))); ?>" /> <span class="description">Accepted/Declined URL configured in the eBay Developer App. WordPress intercepts this URL at <code>admin_init</code>.</span></td></tr>
                </table>
                <p><button class="button button-primary">Save settings</button></p>
            </form>
        </details>
        <details><summary>Readiness scans and category reports</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_readiness_now'); ?><input type="hidden" name="action" value="wei_auto_sync_readiness_now" /><button class="button button-primary">Run readiness scan now</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_full_category_audit'); ?><input type="hidden" name="action" value="wei_full_category_audit" /><button class="button">Run full category audit</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_missing_german_content_audit'); ?><input type="hidden" name="action" value="wei_generate_missing_german_content_audit" /><input type="hidden" name="restart" value="1" /><input type="number" min="1" max="200" name="batch_size" value="50" /><button class="button">Generate missing German content only</button></form></div>
            <div class="wei-actions">
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_generate_all_ebay_de_category_suggestions'); ?>
                    <input type="hidden" name="action" value="wei_generate_all_ebay_de_category_suggestions" />
                    <label>Mode <select name="mode"><option value="leaf_with_products">leaf categories with products</option><option value="with_products">all categories with products</option><option value="all_categories">all categories</option></select></label>
                    <label><input type="checkbox" name="force_refresh" value="1" /> force refresh cache</label>
                    <button class="button button-primary">Generate all eBay.de category suggestions</button>
                </form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_generate_all_ebay_de_category_suggestions'); ?>
                    <input type="hidden" name="action" value="wei_generate_all_ebay_de_category_suggestions" />
                    <input type="hidden" name="continue_from_progress" value="1" />
                    <label>Mode <select name="mode"><option value="leaf_with_products">leaf categories with products</option><option value="with_products">all categories with products</option><option value="all_categories">all categories</option></select></label>
                    <button class="button">Debug continue batch</button>
                </form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_reset_ebay_de_category_suggestions_progress'); ?>
                    <input type="hidden" name="action" value="wei_reset_ebay_de_category_suggestions_progress" />
                    <button class="button button-link-delete">Resetuj progress sugestii</button>
                </form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_ebay_de_category_suggestions'); ?><input type="hidden" name="action" value="wei_generate_ebay_de_category_suggestions" /><input type="hidden" name="restart" value="1" /><label>Debug batch <input type="number" min="1" max="500" name="limit" value="50" /></label><label>Mode <select name="mode"><option value="leaf_with_products">leaf categories with products</option><option value="with_products">all categories with products</option><option value="all_categories">all categories</option></select></label><label><input type="checkbox" name="force_refresh" value="1" /> force refresh cache</label><button class="button">Debug batch 50</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_ebay_de_category_suggestions'); ?><input type="hidden" name="action" value="wei_generate_ebay_de_category_suggestions" /><input type="number" min="1" max="500" name="limit" value="50" /><button class="button">Debug continue batch</button></form>
            </div>
            <p class="description">Główna akcja synchronicznie przechodzi przez wszystkie kategorie w wybranym trybie, zapisuje progress po każdej kategorii i tworzy finalne CSV bez automatycznego importu.</p>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['progress' => $ebayCategorySuggestionsProgress, 'last_summary' => $ebayCategorySuggestionsSummary], 6000)); ?></pre>
            <p><?php foreach ($readinessFilterLabels as $filter => $label): ?><a href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'readiness_filter' => $filter], $adminPageUrl)); ?>"><?php echo $readinessFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === array_key_last($readinessFilterLabels) ? '' : ' / '; ?><?php endforeach; ?></p>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Product</th><th>Status</th><th>Reason</th><th>Errors</th></tr></thead><tbody><?php foreach ($limitedNotReadyItems as $item): ?><tr><td><?php echo esc_html((string) ($item['product_id'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['status'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['primary_reason'] ?? '')); ?></td><td><?php echo esc_html(implode('; ', array_map('strval', (array) ($item['errors'] ?? [])))); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['readiness_counts' => array_diff_key($readinessSummary, array_flip(['not_ready_items', 'blocked_by_category_items', 'missing_required_aspects_items', 'invalid_price_items'])), 'ebay_de_category_suggestions' => $ebayCategorySuggestionsSummary, 'ebay_de_category_suggestions_progress' => $ebayCategorySuggestionsProgress, 'full_category_audit' => $full_category_audit_summary, 'german_content_audit' => $german_content_audit_summary], 6000)); ?></pre>
        </details>
        <details><summary>Category mapping technical tools</summary>
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_map_categories'); ?><input type="hidden" name="action" value="wei_auto_map_categories" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Auto category mapping</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_blocked_category_mappings'); ?><input type="hidden" name="action" value="wei_repair_blocked_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Re-evaluate blocked only</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_audit_category_groups'); ?><input type="hidden" name="action" value="wei_repair_audit_category_groups" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Repair from audit groups</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_apply_manual_woo_category_mappings'); ?><input type="hidden" name="action" value="wei_apply_manual_woo_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Apply manual mappings to products</button></form></div>
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
        <details><summary>Raw logs</summary>
            <p class="description">Ostatnie logi z prostym filtrem i zwijanym technical JSON.</p>
            <p><?php foreach ($logFilters as $filter => $label): ?><a href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'wei_log_filter' => $filter], $adminPageUrl)); ?>"><?php echo $logFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === array_key_last($logFilters) ? '' : ' / '; ?><?php endforeach; ?></p>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>At</th><th>Level</th><th>Summary</th><th>Technical JSON</th></tr></thead><tbody><?php foreach ($recentLogs as $log): ?><?php $message = (string) ($log['message'] ?? ''); $short = strlen($message) > 180 ? substr($message, 0, 177) . '...' : $message; ?><tr><td><?php echo esc_html((string) ($log['at'] ?? '')); ?></td><td><?php echo esc_html((string) ($log['level'] ?? '')); ?></td><td><?php echo esc_html($short); ?></td><td><details><summary>show JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($log['context'] ?? [], 2500)); ?></pre></details></td></tr><?php endforeach; ?></tbody></table></div>
        </details>
    </details>
</div>
