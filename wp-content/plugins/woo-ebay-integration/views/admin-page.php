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
        .wei-admin .wei-actions form { display:inline-flex; gap:6px; align-items:center; margin:0; }
        .wei-admin .wei-actions form.wei-publish-form { display:block; }
        .wei-admin .wei-action-group { border-left:4px solid #dcdcde; padding:8px 0 8px 12px; margin:8px 0; }
        .wei-admin .wei-action-group.primary { border-left-color:#2271b1; }
        .wei-admin .wei-action-group.safe { border-left-color:#00a32a; }
        .wei-admin .wei-action-group.warning { border-left-color:#dba617; }
        .wei-admin .wei-publish-status { border-left:4px solid #2271b1; background:#f6f7f7; padding:12px 14px; margin:10px 0 0; max-width:620px; }
        .wei-admin .wei-publish-status p { margin:4px 0; }
        .wei-admin details.wei-box > summary { cursor:pointer; font-size:16px; font-weight:600; margin:-16px; padding:16px; }
        .wei-admin details.wei-box[open] > summary { border-bottom:1px solid #dcdcde; margin-bottom:16px; }
        .wei-admin details:not(.wei-box) > summary { cursor:pointer; font-weight:600; }
        .wei-admin .wei-scroll, .wei-admin .wei-technical pre, .wei-admin textarea.code { max-height:360px; overflow:auto; white-space:pre-wrap; background:#f6f7f7; border:1px solid #dcdcde; padding:10px; }
        .wei-admin .wei-scroll-table { max-height:440px; overflow:auto; border:1px solid #dcdcde; background:#fff; }
        .wei-admin .wei-scroll-table table { margin:0; border:0; }
        .wei-admin .form-table th { width:240px; }
        .wei-admin .description { color:#646970; }
    </style>

    <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success"><p>eBay settings saved.</p></div><?php endif; ?>

    <div class="wei-box">
        <h2>Główna konfiguracja eBay</h2>
        <p class="description">Najważniejsze ustawienia operacyjne są na górze. Masowa publikacja pozostaje bezpiecznie wyłączona, dopóki <code>Auto publish</code> nie jest włączone.</p>
        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
            <?php wp_nonce_field('wei_save_settings'); ?>
            <input type="hidden" name="action" value="wei_save_settings" />
            <table class="form-table" role="presentation">
                <tr><th><label for="wei-marketplace-id">Marketplace</label></th><td><input id="wei-marketplace-id" class="regular-text" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" placeholder="EBAY_DE" /></td></tr>
                <tr><th><label for="wei-location-key">Merchant location key</label></th><td><input id="wei-location-key" class="regular-text" name="inventory_location_key" value="<?php echo esc_attr((string) $setting('inventory_location_key', 'gpswiss-pl')); ?>" /></td></tr>
                <tr><th><label for="wei-fulfillment-policy-30">Fulfillment policy ID 30 EUR</label></th><td><input id="wei-fulfillment-policy-30" class="regular-text" name="fulfillment_policy_id_30_eur" value="<?php echo esc_attr($fulfillmentId30); ?>" /> <span class="description"><?php echo esc_html($fulfillmentId30 !== '' ? $findPolicyName($fulfillmentPolicies, $fulfillmentId30, 'fulfillmentPolicyId') : 'missing'); ?> (fallback/default)</span></td></tr>
                <tr><th><label for="wei-fulfillment-policy-50">Fulfillment policy ID 50 EUR</label></th><td><input id="wei-fulfillment-policy-50" class="regular-text" name="fulfillment_policy_id_50_eur" value="<?php echo esc_attr($fulfillmentId50); ?>" /> <span class="description"><?php echo esc_html($fulfillmentId50 !== '' ? $findPolicyName($fulfillmentPolicies, $fulfillmentId50, 'fulfillmentPolicyId') : 'missing'); ?></span></td></tr>
                <tr><th><label for="wei-fulfillment-policy-100">Fulfillment policy ID 100 EUR</label></th><td><input id="wei-fulfillment-policy-100" class="regular-text" name="fulfillment_policy_id_100_eur" value="<?php echo esc_attr($fulfillmentId100); ?>" /> <span class="description"><?php echo esc_html($fulfillmentId100 !== '' ? $findPolicyName($fulfillmentPolicies, $fulfillmentId100, 'fulfillmentPolicyId') : 'missing'); ?></span></td></tr>
                <tr><th><label for="wei-shipping-categories-50">Woo categories 50 EUR</label></th><td><textarea id="wei-shipping-categories-50" class="large-text code" rows="2" name="shipping_category_ids_50_eur"><?php echo esc_textarea((string) $setting('shipping_category_ids_50_eur', '')); ?></textarea><p class="description">Konkretnie przypisane Woo term IDs; bez dziedziczenia z kategorii nadrzędnej.</p></td></tr>
                <tr><th><label for="wei-shipping-categories-100">Woo categories 100 EUR</label></th><td><textarea id="wei-shipping-categories-100" class="large-text code" rows="2" name="shipping_category_ids_100_eur"><?php echo esc_textarea((string) $setting('shipping_category_ids_100_eur', '')); ?></textarea><p class="description">Jeżeli produkt ma też kategorię 50 EUR, resolver wybierze 100 EUR.</p></td></tr>
                <tr><th><label for="wei-payment-policy">Payment policy ID</label></th><td><input id="wei-payment-policy" class="regular-text" name="ebay_payment_policy_id" value="<?php echo esc_attr($paymentId); ?>" /> <span class="description"><?php echo esc_html($paymentId !== '' ? $findPolicyName($paymentPolicies, $paymentId, 'paymentPolicyId') : 'missing'); ?></span></td></tr>
                <tr><th><label for="wei-return-policy">Return policy ID</label></th><td><input id="wei-return-policy" class="regular-text" name="ebay_return_policy_id" value="<?php echo esc_attr($returnId); ?>" /> <span class="description"><?php echo esc_html($returnId !== '' ? $findPolicyName($returnPolicies, $returnId, 'returnPolicyId') : 'missing'); ?></span></td></tr>
                <tr><th><label for="wei-markup">Domyślny markup %</label></th><td><input id="wei-markup" type="number" step="0.01" name="ebay_default_markup_percent" value="<?php echo esc_attr((string) $setting('ebay_default_markup_percent', 25)); ?>" /></td></tr>
                <tr><th><label for="wei-condition">Domyślny stan przedmiotu</label></th><td><select id="wei-condition" name="default_item_condition"><option value="USED" <?php selected((string) $setting('default_item_condition', 'USED'), 'USED'); ?>>USED</option><option value="USED_EXCELLENT" <?php selected((string) $setting('default_item_condition', 'USED'), 'USED_EXCELLENT'); ?>>USED_EXCELLENT</option></select></td></tr>
                <tr><th>Auto publish</th><td><label><input type="checkbox" name="auto_publish_enabled" value="1" <?php checked(!empty($s['auto_publish_enabled'])); ?> /> enabled</label> <span class="wei-badge <?php echo esc_attr($badgeClass(!empty($s['auto_publish_enabled']))); ?>"><?php echo esc_html($boolLabel(!empty($s['auto_publish_enabled']))); ?></span></td></tr>
                <tr><th>Auto sync</th><td><select name="auto_sync_mode"><option value="disabled" <?php selected((string) $setting('auto_sync_mode', 'disabled'), 'disabled'); ?>>disabled / dry-run only</option><option value="preflight_only" <?php selected((string) $setting('auto_sync_mode', 'disabled'), 'preflight_only'); ?>>dry run / diagnostics only</option><option value="export_ready_products" <?php selected((string) $setting('auto_sync_mode', 'disabled'), 'export_ready_products'); ?>>export/update offers but do not publish unless auto publish is enabled</option><option value="orders_stock_only" <?php selected((string) $setting('auto_sync_mode', 'disabled'), 'orders_stock_only'); ?>>orders + stock only</option><option value="full_sync" <?php selected((string) $setting('auto_sync_mode', 'disabled'), 'full_sync'); ?>>full sync</option></select></td></tr>
                <tr><th><label for="wei-batch-size">Batch size / limit</label></th><td><input id="wei-batch-size" type="number" min="1" max="50" name="auto_sync_export_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_export_batch_size', 20)); ?>" /> <span class="description">Export batch</span></td></tr>
            </table>

            <details>
                <summary>Advanced settings / credentials</summary>
                <table class="form-table" role="presentation">
                    <tr><th>Environment</th><td><select name="environment"><option value="production" <?php selected((string) $setting('environment', 'production'), 'production'); ?>>production</option><option value="sandbox" <?php selected((string) $setting('environment', 'production'), 'sandbox'); ?>>sandbox</option></select></td></tr>
                    <tr><th>Client ID</th><td><input class="regular-text" name="client_id" value="<?php echo esc_attr((string) $setting('client_id', '')); ?>" /></td></tr>
                    <tr><th>Client secret</th><td><input class="regular-text" type="password" name="client_secret" placeholder="<?php echo esc_attr($maskSecret((string) $setting('client_secret', ''))); ?>" autocomplete="new-password" /></td></tr>
                    <tr><th>RuName</th><td><input class="regular-text" name="runame" value="<?php echo esc_attr((string) $setting('runame', '')); ?>" /></td></tr>
                    <tr><th>Default category ID</th><td><input class="regular-text" name="default_category_id" value="<?php echo esc_attr((string) $setting('default_category_id', '')); ?>" /></td></tr>
                    <tr><th>Special category markup %</th><td><input type="number" step="0.01" name="ebay_special_category_markup_percent" value="<?php echo esc_attr((string) $setting('ebay_special_category_markup_percent', 30)); ?>" /></td></tr>
                    <tr><th>NBP cache TTL hours</th><td><input type="number" step="0.01" name="nbp_rate_cache_ttl_hours" value="<?php echo esc_attr((string) $setting('nbp_rate_cache_ttl_hours', 12)); ?>" /> <span class="description">Rate: <?php echo esc_html((string) ($nbpRate ?? '-')); ?> <?php echo esc_html($nbpEffectiveDate); ?>, cache <?php echo esc_html($nbpCacheStatus); ?></span></td></tr>
                    <tr><th>Auto sync frequency</th><td><select name="auto_sync_frequency"><option value="every_15_minutes" <?php selected((string) $setting('auto_sync_frequency', 'hourly'), 'every_15_minutes'); ?>>every 15 minutes</option><option value="hourly" <?php selected((string) $setting('auto_sync_frequency', 'hourly'), 'hourly'); ?>>hourly</option><option value="daily" <?php selected((string) $setting('auto_sync_frequency', 'hourly'), 'daily'); ?>>daily</option></select></td></tr>
                    <tr><th>Preflight batch size</th><td><input type="number" min="1" max="300" name="auto_sync_preflight_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_preflight_batch_size', 200)); ?>" /></td></tr>
                    <tr><th>Stock batch size</th><td><input type="number" min="1" max="300" name="auto_sync_stock_batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_stock_batch_size', 100)); ?>" /></td></tr>
                    <tr><th>Enable export queue</th><td><label><input type="checkbox" name="auto_export_enabled" value="1" <?php checked(!empty($s['auto_export_enabled'])); ?> /> auto export enabled</label></td></tr>
                    <tr><th>Woo → eBay stock sync</th><td><label><input type="checkbox" name="woo_to_ebay_stock_sync_enabled" value="1" <?php checked(!empty($s['woo_to_ebay_stock_sync_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>eBay order sync</th><td><label><input type="checkbox" name="ebay_order_sync_enabled" value="1" <?php checked(!empty($s['ebay_order_sync_enabled'])); ?> /> enabled</label></td></tr>
                    <tr><th>Stock sync mode</th><td><select name="stock_sync_mode"><option value="set_zero" <?php selected((string) $setting('stock_sync_mode', 'set_zero'), 'set_zero'); ?>>set zero</option><option value="reduce" <?php selected((string) $setting('stock_sync_mode', 'set_zero'), 'reduce'); ?>>reduce</option></select></td></tr>
                    <tr><th>eBay stock sync mode</th><td><select name="ebay_stock_sync_mode"><option value="set_zero_only" <?php selected((string) $setting('ebay_stock_sync_mode', 'max_one'), 'set_zero_only'); ?>>set zero only</option><option value="max_one" <?php selected((string) $setting('ebay_stock_sync_mode', 'max_one'), 'max_one'); ?>>max one</option><option value="exact_stock" <?php selected((string) $setting('ebay_stock_sync_mode', 'max_one'), 'exact_stock'); ?>>exact stock</option></select></td></tr>
                    <tr><th>eBay order stock update</th><td><select name="ebay_order_stock_update_mode"><option value="set_zero" <?php selected((string) $setting('ebay_order_stock_update_mode', 'set_zero'), 'set_zero'); ?>>set zero</option><option value="reduce" <?php selected((string) $setting('ebay_order_stock_update_mode', 'set_zero'), 'reduce'); ?>>reduce</option></select></td></tr>
                    <tr><th>Translation provider</th><td><select name="translation_provider"><option value="disabled" <?php selected($provider, 'disabled'); ?>>disabled</option><option value="google_cloud_translate" <?php selected($provider, 'google_cloud_translate'); ?>>Google Cloud Translate</option></select> <span class="description"><?php echo esc_html($translationLabel); ?></span></td></tr>
                    <tr><th>Translation API key</th><td><input class="regular-text" type="password" name="translation_api_key" placeholder="<?php echo esc_attr($maskSecret((string) $setting('translation_api_key', ''))); ?>" /></td></tr>
                    <tr><th>German content automation</th><td><label><input type="checkbox" name="auto_generate_german_content_preflight" value="1" <?php checked(!empty($s['auto_generate_german_content_preflight'])); ?> /> generate during preflight</label><br><label><input type="checkbox" name="regenerate_german_content_on_hash_change" value="1" <?php checked(!empty($s['regenerate_german_content_on_hash_change'])); ?> /> regenerate on hash change</label></td></tr>
                    <tr><th>Inventory location details</th><td><input name="inventory_location_name" placeholder="name" value="<?php echo esc_attr((string) $setting('inventory_location_name', 'gpswiss-pl')); ?>" /> <input name="inventory_location_country" placeholder="country" value="<?php echo esc_attr((string) $setting('inventory_location_country', 'PL')); ?>" /> <input name="inventory_location_postal_code" placeholder="postal" value="<?php echo esc_attr((string) $setting('inventory_location_postal_code', '08-460')); ?>" /> <input name="inventory_location_city" placeholder="city" value="<?php echo esc_attr((string) $setting('inventory_location_city', 'Sobolew')); ?>" /> <input class="regular-text" name="inventory_location_address_line_1" placeholder="address" value="<?php echo esc_attr((string) $setting('inventory_location_address_line_1', '')); ?>" /></td></tr>
                    <tr><th>SKU prefix</th><td><input name="ebay_sku_prefix" value="<?php echo esc_attr((string) $setting('ebay_sku_prefix', 'GPSW')); ?>" /> <span class="description">eBay uses plugin-owned <code>_wei_ebay_sku</code>; Woo SKU is not overwritten.</span></td></tr>
                    <tr><th>Auto category threshold</th><td><input type="number" step="0.0001" min="0" max="1" name="auto_category_confidence_threshold" value="<?php echo esc_attr((string) $setting('auto_category_confidence_threshold', \WEI\Services\CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD)); ?>" /></td></tr>
                    <tr><th>Verbose debug</th><td><label><input type="checkbox" name="verbose_debug" value="1" <?php checked(!empty($s['verbose_debug'])); ?> /> enabled</label></td></tr>
                    <tr><th>SKU category overrides</th><td><textarea class="large-text code" rows="4" name="sku_category_overrides"><?php echo esc_textarea((string) $setting('sku_category_overrides', '')); ?></textarea></td></tr>
                    <tr><th>Product category overrides</th><td><textarea class="large-text code" rows="4" name="product_category_overrides"><?php echo esc_textarea((string) $setting('product_category_overrides', '')); ?></textarea></td></tr>
                    <tr><th>SKU aspect overrides</th><td><textarea class="large-text code" rows="4" name="sku_aspect_overrides"><?php echo esc_textarea((string) $setting('sku_aspect_overrides', '')); ?></textarea></td></tr>
                    <tr><th>Category aspect fallbacks</th><td><textarea class="large-text code" rows="4" name="category_aspect_fallbacks"><?php echo esc_textarea((string) $setting('category_aspect_fallbacks', '')); ?></textarea></td></tr>
                    <tr><th>Default Hersteller fallback</th><td><input class="regular-text" name="default_hersteller_fallback" value="<?php echo esc_attr((string) $setting('default_hersteller_fallback', '')); ?>" /></td></tr>
                </table>
            </details>
            <p><button class="button button-primary">Save settings</button></p>
        </form>
    </div>

    <div class="wei-box">
        
        <h2>eBay listing quality audit</h2>
        <p class="description">Manual report only. Scans active existing eBay offers in lightweight SQL batches and stores issues/suggestions. No auto-publish or mass update.</p>
        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_listing_quality_audit'); ?><input type="hidden" name="action" value="wei_generate_listing_quality_audit" /><button class="button">Generate eBay listing quality audit</button></form>
        <h3>Clean condition/aspects for one eBay listing</h3>
        <p class="description">Safe single-listing cleanup: removes only invalid condition-related custom aspects and keeps main condition as used. No createOffer/publishOffer.</p>
        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" class="wei-actions">
            <?php wp_nonce_field('wei_condition_cleanup_single'); ?>
            <input type="hidden" name="action" value="wei_condition_cleanup_single" />
            <input type="text" name="product_or_sku" placeholder="Product ID or SKU" required />
            <button class="button button-secondary">Clean condition/aspects for one eBay listing</button>
        </form>
        <?php if (!empty($listing_quality_audit)): ?>
            <div class="wei-grid" style="margin-top:8px;">
                <div class="wei-card"><span>Generated at</span><strong><?php echo esc_html((string) ($listing_quality_audit['generated_at'] ?? '-')); ?></strong></div>
                <div class="wei-card"><span>Scanned offers</span><strong><?php echo esc_html((string) ($listing_quality_audit['scanned'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Wrong category suspects</span><strong><?php echo esc_html((string) ($listing_quality_audit['suspected_wrong_ebay_category'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Missing fitment</span><strong><?php echo esc_html((string) ($listing_quality_audit['missing_fitment'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Condition conflicts</span><strong><?php echo esc_html((string) ($listing_quality_audit['condition_conflict_count'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Custom Stan aspects</span><strong><?php echo esc_html((string) ($listing_quality_audit['custom_stan_aspect_count'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Title contains NEU/NOWY/NEW</span><strong><?php echo esc_html((string) ($listing_quality_audit['title_contains_neu_count'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Ready for condition cleanup</span><strong><?php echo esc_html((string) ($listing_quality_audit['ready_for_condition_cleanup_count'] ?? 0)); ?></strong></div>
            </div>
            <details><summary>Audit JSON preview</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($listing_quality_audit, 9000)); ?></pre></details>
        <?php endif; ?>

        <h2>Shipping policy mapping report</h2>
        <p class="description">Raport jest generowany wyłącznie ręcznie przyciskiem i zapisywany jako ostatni wynik; zwykłe renderowanie panelu nie skanuje produktów.</p>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_shipping_mapping_report'); ?><input type="hidden" name="action" value="wei_generate_shipping_mapping_report" /><button class="button">Generate shipping mapping report</button></form>
        </div>

        <h3>Test fulfillment policy update for one product</h3>
        <p class="description">Bezpieczny test aktualizuje wyłącznie <code>listingPolicies.fulfillmentPolicyId</code> istniejącej oferty eBay. Nie tworzy oferty, nie publikuje, nie zmienia ceny, tytułu, opisu, kategorii ani stocku Woo.</p>
        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" class="wei-actions">
            <?php wp_nonce_field('wei_update_shipping_policy_one'); ?>
            <input type="hidden" name="action" value="wei_update_shipping_policy_one" />
            <label for="wei-shipping-one-product-id">Product ID</label>
            <input id="wei-shipping-one-product-id" type="number" min="1" name="product_id" class="small-text" />
            <span>or</span>
            <label for="wei-shipping-one-sku">SKU</label>
            <input id="wei-shipping-one-sku" name="sku" class="regular-text" />
            <button class="button button-secondary">Update shipping policy for one product</button>
        </form>

        <h3>Bulk fulfillment policy update queue</h3>
        <p class="notice notice-warning" style="padding:10px 12px;">This will update fulfillment policy only for existing active eBay listings, in batches of 50. It will not publish new listings.</p>
        <?php $bulkStatus = is_array($shipping_policy_bulk_status ?? null) ? $shipping_policy_bulk_status : []; ?>
        <div class="wei-grid">
            <div class="wei-card"><span>Status</span><strong><?php echo esc_html((string) ($bulkStatus['state'] ?? 'idle')); ?></strong></div>
            <div class="wei-card"><span>Total queued</span><strong><?php echo esc_html((string) ($bulkStatus['total_queued'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Processed</span><strong><?php echo esc_html((string) ($bulkStatus['processed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Remaining</span><strong><?php echo esc_html((string) ($bulkStatus['remaining'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Changed</span><strong><?php echo esc_html((string) ($bulkStatus['changed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Unchanged</span><strong><?php echo esc_html((string) ($bulkStatus['unchanged'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Failed</span><strong><?php echo esc_html((string) ($bulkStatus['failed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Last product ID</span><strong><?php echo esc_html((string) ($bulkStatus['last_product_id'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Started at</span><strong><?php echo esc_html((string) (($bulkStatus['started_at'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Updated at</span><strong><?php echo esc_html((string) (($bulkStatus['updated_at'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Last error</span><strong><?php echo esc_html((string) (($bulkStatus['last_error'] ?? '') ?: '-')); ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return window.confirm('This will update fulfillment policy only for existing active eBay listings, in batches of 50. It will not publish new listings.');">
                <?php wp_nonce_field('wei_shipping_policy_bulk_start'); ?>
                <input type="hidden" name="action" value="wei_shipping_policy_bulk_start" />
                <input type="hidden" name="batch_size" value="50" />
                <button class="button button-primary">Start fulfillment policy update for all active eBay listings</button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_shipping_policy_bulk_pause'); ?><input type="hidden" name="action" value="wei_shipping_policy_bulk_pause" /><button class="button">Pause</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_shipping_policy_bulk_resume'); ?><input type="hidden" name="action" value="wei_shipping_policy_bulk_resume" /><button class="button">Resume</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_shipping_policy_bulk_stop'); ?><input type="hidden" name="action" value="wei_shipping_policy_bulk_stop" /><button class="button button-link-delete">Stop / Clear queue</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_shipping_policy_bulk_process'); ?><input type="hidden" name="action" value="wei_shipping_policy_bulk_process" /><button class="button">Process next batch now</button></form>
        </div>
        <?php if (!empty($shipping_mapping_report)): ?>
            <div class="wei-grid">
                <div class="wei-card"><span>Generated at</span><strong><?php echo esc_html((string) ($shipping_mapping_report['generated_at'] ?? '-')); ?></strong></div>
                <div class="wei-card"><span>Total products</span><strong><?php echo esc_html((string) ($shipping_mapping_report['total_products'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Estimated 30 EUR</span><strong><?php echo esc_html((string) ($shipping_mapping_report['estimated_products_default_30'] ?? ($shipping_mapping_report['counts']['default_30_eur'] ?? 0))); ?></strong></div>
                <div class="wei-card"><span>Estimated 50 EUR</span><strong><?php echo esc_html((string) ($shipping_mapping_report['estimated_products_50'] ?? ($shipping_mapping_report['counts']['50_eur'] ?? 0))); ?></strong></div>
                <div class="wei-card"><span>Estimated 100 EUR</span><strong><?php echo esc_html((string) ($shipping_mapping_report['estimated_products_100'] ?? ($shipping_mapping_report['counts']['100_eur'] ?? 0))); ?></strong></div>
                <div class="wei-card"><span>100 EUR categories</span><strong><?php echo esc_html((string) ($shipping_mapping_report['count_categories_100'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>50 EUR categories</span><strong><?php echo esc_html((string) ($shipping_mapping_report['count_categories_50'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>Partial</span><strong><?php echo !empty($shipping_mapping_report['partial']) ? 'yes' : 'no'; ?></strong></div>
            </div>
            <?php if (!empty($shipping_mapping_report['warnings'])): ?>
                <h3>Warnings</h3>
                <pre class="wei-scroll"><?php echo esc_html($technicalPreview($shipping_mapping_report['warnings'], 2000)); ?></pre>
            <?php endif; ?>
            <h3>Sample category terms (max 100)</h3>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview($shipping_mapping_report['sample_terms'] ?? [], 4000)); ?></pre>
            <h3>Sample categories using default 30 EUR (max 100)</h3>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview($shipping_mapping_report['unmapped_categories'] ?? [], 4000)); ?></pre>
        <?php endif; ?>
    </div>

    <div class="wei-box">
        <h2>WooCommerce ↔ eBay sync</h2>
        <div class="wei-grid">
            <div class="wei-card"><span>Tryb pracy</span><strong><?php echo esc_html($autoModeLabels[(string) $setting('auto_sync_mode', 'disabled')] ?? (string) $setting('auto_sync_mode', 'disabled')); ?></strong></div>
            <div class="wei-card"><span>Auto publish</span><strong><span class="wei-badge <?php echo esc_attr($badgeClass(!empty($s['auto_publish_enabled']))); ?>"><?php echo esc_html($boolLabel(!empty($s['auto_publish_enabled']))); ?></span></strong></div>
            <div class="wei-card"><span>Auto export</span><strong><span class="wei-badge <?php echo esc_attr($badgeClass(!empty($s['auto_export_enabled']))); ?>"><?php echo esc_html($boolLabel(!empty($s['auto_export_enabled']))); ?></span></strong></div>
            <div class="wei-card"><span>Last run</span><strong><?php echo esc_html((string) (($autoSync['last_run'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Next run</span><strong><?php echo esc_html((string) ($autoSync['next_run'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Hook</span><strong><code><?php echo esc_html((string) ($autoSync['hook'] ?? 'wei_ebay_run_scheduled_sync')); ?></code></strong></div>
            <div class="wei-card"><span>Last success</span><strong><?php echo esc_html((string) ($autoSync['checkpoint']['last_success_at'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Last run status</span><strong><?php echo esc_html((string) ($autoSync['checkpoint']['last_run_status'] ?? $autoStatus)); ?></strong></div>
            <div class="wei-card"><span>Last error</span><strong><?php echo esc_html((string) (($autoSync['checkpoint']['last_error'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Queued products</span><strong><?php echo esc_html((string) ($autoSync['queued_products_count'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Failed queue</span><strong><?php echo esc_html((string) ($autoSync['failed_queue_count'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Paused</span><strong><?php echo !empty($s['auto_sync_paused']) ? 'Paused' : 'Running'; ?></strong></div>
            <div class="wei-card"><span>Pending stock sync</span><strong><?php echo esc_html((string) ($autoSync['pending_stock_sync'] ?? 0)); ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_sync_now'); ?><input type="hidden" name="action" value="wei_ebay_sync_now" /><button class="button button-primary">Run eBay sync now</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_process_queue_now'); ?><input type="hidden" name="action" value="wei_ebay_process_queue_now" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Process eBay queue now</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_rebuild_ready_queue'); ?><input type="hidden" name="action" value="wei_ebay_rebuild_ready_queue" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Rebuild queue for ready products</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_toggle_pause'); ?><input type="hidden" name="action" value="wei_auto_sync_toggle_pause" /><button class="button"><?php echo !empty($s['auto_sync_paused']) ? 'Resume sync' : 'Pause sync'; ?></button></form>
        </div>
        <p class="description"><strong>No full scan:</strong> the 15-minute cron uses checkpoints and the eBay queue only. Readiness scan/full category audit stay manual and are never triggered while rendering this panel.</p>
        <details><summary>Last processed counts / checkpoints</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['checkpoint' => $autoSync['checkpoint'] ?? [], 'queue_summary' => $autoSync['queue_summary'] ?? [], 'last_summary' => $autoLastSummary])); ?></pre></details>
        <div class="wei-action-group primary"><strong>Woo → eBay</strong><div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_export_now'); ?><input type="hidden" name="action" value="wei_auto_sync_export_now" /><input type="number" min="1" max="50" name="batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_export_batch_size', 20)); ?>" /><button class="button button-primary" <?php disabled(empty($s['auto_export_enabled'])); ?>>Export/update ready products</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_stock_now'); ?><input type="hidden" name="action" value="wei_auto_sync_stock_now" /><input type="number" min="1" max="300" name="batch_size" value="<?php echo esc_attr((string) $setting('auto_sync_stock_batch_size', 100)); ?>" /><button class="button">Sync stock only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync_prices_only'); ?><input type="hidden" name="action" value="wei_sync_prices_only" /><button class="button">Sync prices only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync_content_only'); ?><input type="hidden" name="action" value="wei_sync_content_only" /><button class="button">Sync content only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync_categories_only'); ?><input type="hidden" name="action" value="wei_sync_categories_only" /><button class="button">Sync categories/aspects only</button></form>
        </div><p class="description">Export/update uses existing safe export logic. Publishing happens only when <code>auto_publish_enabled</code> is saved as enabled.</p></div>
        <div class="wei-action-group safe"><strong>eBay → Woo</strong><div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_orders_now'); ?><input type="hidden" name="action" value="wei_auto_sync_orders_now" /><button class="button">Import eBay orders</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync_listing_meta_back'); ?><input type="hidden" name="action" value="wei_sync_listing_meta_back" /><button class="button">Sync listing IDs / URLs back to Woo meta</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync_ebay_stock_to_woo'); ?><input type="hidden" name="action" value="wei_sync_ebay_stock_to_woo" /><button class="button">Optional stock sync from eBay to Woo</button></form>
        </div><p class="description">Skeleton eBay → Woo actions never overwrite Woo price, description or stock without a separate implementation/option.</p></div>
        <div class="wei-action-group warning"><strong>Tryby pracy</strong><ul><li>Dry run / diagnostics only: use readiness scans, preflight and category audit.</li><li>Export/update offers but do not publish: leave Auto publish disabled.</li><li>Publish enabled only if Auto publish is enabled.</li><li>Manual one-product publish is available in Manual tools.</li></ul></div>
    </div>


    <div class="wei-box">
        <h2>Pierwsze wystawienie produktów na eBay</h2>
        <p class="description">Ręczny tryb initial publish jest osobny od 15-minutowego scheduled syncu. Sprawdzenie gotowości uruchamia się tylko po kliknięciu przycisku i zapisuje ostatni wynik; render panelu nie skanuje produktów.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>Gotowe do wystawienia</span><strong><?php echo esc_html((string) ($initialPublishCandidates['ready'] ?? $initialPublishCandidates['initial_publish_candidates'] ?? $initialPublish['total_ready'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Już opublikowane</span><strong><?php echo esc_html((string) ($initialPublishCandidates['already_published'] ?? ($initialPublishSkippedReasons['already_published'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Zablokowane kategorią</span><strong><?php echo esc_html((string) ($initialPublishCandidates['blocked_by_category'] ?? ($initialPublishSkippedReasons['blocked_by_category'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Brak aspektów</span><strong><?php echo esc_html((string) ($initialPublishCandidates['missing_aspects'] ?? ($initialPublishSkippedReasons['missing_aspects'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Brak treści</span><strong><?php echo esc_html((string) ($initialPublishCandidates['content_not_ready'] ?? ($initialPublishSkippedReasons['content_not_ready'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Brak ceny</span><strong><?php echo esc_html((string) ($initialPublishCandidates['price_not_ready'] ?? ($initialPublishSkippedReasons['price_not_ready'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Inne / stock</span><strong><?php echo esc_html((string) ($initialPublishCandidates['other'] ?? ($initialPublishSkippedReasons['other'] ?? 0))); ?></strong></div>
            <div class="wei-card"><span>Błędy sprawdzania</span><strong><?php echo esc_html((string) ($initialPublishCandidates['errors'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Sprawdzono produktów</span><strong><?php echo esc_html((string) ($initialPublishCandidates['processed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Status sprawdzania</span><strong><?php echo esc_html((string) ($initialPublishCandidates['status'] ?? 'not_checked')); ?></strong></div>
            <div class="wei-card"><span>Ostatnie sprawdzenie</span><strong><?php echo esc_html((string) (($initialPublishCandidates['last_run_at'] ?? $initialPublishCandidates['rebuilt_at'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Cursor sprawdzania</span><strong><?php echo esc_html((string) ($initialPublishCandidates['cursor'] ?? $initialPublishCandidates['next_offset'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Wystawione oferty</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?> / <?php echo esc_html((string) $initialPublishTotalReady); ?></strong></div>
            <div class="wei-card"><span>Pozostało do wystawienia</span><strong><?php echo esc_html((string) $initialPublishRemaining); ?></strong></div>
        </div>
        <p class="description"><strong>Źródło liczby kandydatów:</strong> sprawdzenie czyta aktualne produkty WooCommerce z bazy danych, wykonuje preflight dla każdego produktu w batchu i ustawia <code>_wei_ebay_export_status=ready</code> tylko dla produktów gotowych oraz nieopublikowanych. CSV jest opcjonalnym raportem diagnostycznym i nie jest wymagany do zbudowania listy.</p>
        <?php if ($initialPublishSkippedReasons !== []): ?>
            <h3>Skipped / not eligible breakdown</h3>
            <table class="widefat striped"><tbody>
                <?php foreach (['already_published', 'blocked_by_category', 'missing_aspects', 'content_not_ready', 'price_not_ready', 'other'] as $reasonKey): ?>
                    <tr><th><?php echo esc_html($reasonKey); ?></th><td><?php echo esc_html((string) ((int) ($initialPublishSkippedReasons[$reasonKey] ?? 0))); ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php if (!empty($initialPublish['last_error'])): ?><p class="description"><strong>Ostatni błąd:</strong> <?php echo esc_html((string) $initialPublish['last_error']); ?></p><?php endif; ?>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_ebay_rebuild_initial_publish_candidates'); ?>
                <input type="hidden" name="action" value="wei_ebay_rebuild_initial_publish_candidates" />
                <label>Batch size <input type="number" min="1" max="500" name="batch_size" value="100" /></label>
                <label><input type="checkbox" name="reset_rebuild" value="1" <?php checked(($initialPublishCandidates['status'] ?? '') !== 'in_progress'); ?> /> zacznij od początku</label>
                <button class="button">Sprawdź produkty gotowe do wystawienia</button>
                <p class="description">Ta akcja sprawdza produkty WooCommerce i buduje listę produktów gotowych do publikacji na eBay. Nie publikuje jeszcze ofert.</p>
            </form>
            <form class="wei-publish-form" method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_ebay_initial_publish_batch'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_batch" />
                <label>Batch size <input type="number" min="1" max="50" name="batch_size" value="5" /></label>
                <button class="button button-primary" <?php disabled($initialPublishPublicationStatus === 'paused'); ?>>Opublikuj oferty na eBay</button>
                <div class="wei-publish-status" aria-live="polite">
                    <p><strong>Wystawione oferty:</strong> <?php echo esc_html((string) $initialPublishSuccess); ?> / <?php echo esc_html((string) $initialPublishTotalReady); ?></p>
                    <p><strong>Pozostało do wystawienia:</strong> <?php echo esc_html((string) $initialPublishRemaining); ?></p>
                    <p><strong>Błędy publikacji:</strong> <?php echo esc_html((string) $initialPublishFailed); ?></p>
                    <p><strong>Ostatni batch:</strong> <?php echo esc_html((string) $initialPublishLastBatchSuccess); ?> success, <?php echo esc_html((string) $initialPublishLastBatchFailed); ?> failed, <?php echo esc_html((string) $initialPublishLastBatchProcessed); ?> processed</p>
                    <p><strong>Ostatnie uruchomienie publikacji:</strong> <?php echo esc_html($initialPublishLastRunAt !== '' ? $initialPublishLastRunAt : '-'); ?></p>
                    <p><strong>Ostatnio wystawione listing_id / product_id:</strong> <?php echo esc_html($initialPublishLastListingId !== '' ? $initialPublishLastListingId : '-'); ?> / <?php echo esc_html($initialPublishLastPublishedProductId > 0 ? (string) $initialPublishLastPublishedProductId : '-'); ?></p>
                    <p><strong>Status publikacji:</strong> <?php echo esc_html($initialPublishPublicationStatus); ?></p>
                </div>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_ebay_initial_publish_toggle_pause'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_toggle_pause" />
                <button class="button"><?php echo $initialPublishPublicationStatus === 'paused' ? 'Wznów' : 'Pauza'; ?></button>
            </form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>" onsubmit="return confirm('Reset progress initial publish? Wpisz RESET w polu potwierdzenia.');">
                <?php wp_nonce_field('wei_ebay_initial_publish_reset'); ?>
                <input type="hidden" name="action" value="wei_ebay_initial_publish_reset" />
                <input type="text" name="confirm_reset" placeholder="RESET" size="8" />
                <button class="button">Reset progress</button>
            </form>
        </div>
        <p class="description"><strong>Uwaga:</strong> przycisk publikacji realnie wystawia publiczne oferty eBay. Jeden klik publikuje maksymalnie podany <code>Batch size</code> z zapisanej listy kandydatów <code>_wei_ebay_export_status=ready</code>; samo sprawdzenie gotowości nie publikuje ofert.</p>
        <h3>Log ostatniego batcha</h3>
        <pre class="wei-scroll"><?php echo esc_html(implode("\n", $initialPublishLog)); ?></pre>
    </div>

    <div class="wei-box">
        <h2>Readiness Summary</h2>
        <div class="wei-grid">
            <div class="wei-card"><span>Ready</span><strong><?php echo esc_html((string) ($readinessSummary['ready'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Blocked by category</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_category'] ?? $categoryBlockedCount)); ?></strong></div>
            <div class="wei-card"><span>Missing category</span><strong><?php echo esc_html((string) ($readinessSummary['missing_category'] ?? $categoryMissingCount)); ?></strong></div>
            <div class="wei-card"><span>Missing aspects</span><strong><?php echo esc_html((string) ($readinessSummary['missing_required_aspects'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Content not ready</span><strong><?php echo esc_html((string) ($readinessSummary['missing_german_content'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Price not ready</span><strong><?php echo esc_html((string) ($readinessSummary['invalid_price'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>Ostatni audyt</span><strong><?php echo esc_html((string) ($readinessSummary['last_run'] ?? $full_category_audit_summary['last_run'] ?? '-')); ?></strong></div>
        </div>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_sync_readiness_now'); ?><input type="hidden" name="action" value="wei_auto_sync_readiness_now" /><button class="button button-primary">Run readiness scan now</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_full_category_audit'); ?><input type="hidden" name="action" value="wei_full_category_audit" /><button class="button">Run full category audit</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_missing_german_content_audit'); ?><input type="hidden" name="action" value="wei_generate_missing_german_content_audit" /><input type="hidden" name="restart" value="1" /><input type="number" min="1" max="200" name="batch_size" value="50" /><button class="button">Generate missing German content only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_export_category_teaching_csv'); ?><input type="hidden" name="action" value="wei_export_category_teaching_csv" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Export teaching CSV from latest audit problems</button></form>
        </div>
        <details><summary>Not-ready products and audit report links</summary>
            <p><?php foreach ($readinessFilterLabels as $filter => $label): ?><a href="<?php echo esc_url(add_query_arg(['page' => 'woo-ebay', 'readiness_filter' => $filter], $adminPageUrl)); ?>"><?php echo $readinessFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === array_key_last($readinessFilterLabels) ? '' : ' / '; ?><?php endforeach; ?></p>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Product</th><th>Status</th><th>Reason</th><th>Errors</th></tr></thead><tbody><?php foreach ($limitedNotReadyItems as $item): ?><tr><td><?php echo esc_html((string) ($item['product_id'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['status'] ?? '')); ?></td><td><?php echo esc_html((string) ($item['primary_reason'] ?? '')); ?></td><td><?php echo esc_html(implode('; ', array_map('strval', (array) ($item['errors'] ?? [])))); ?></td></tr><?php endforeach; ?></tbody></table></div>
            <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['readiness_counts' => array_diff_key($readinessSummary, array_flip(['not_ready_items', 'blocked_by_category_items', 'missing_required_aspects_items', 'invalid_price_items'])), 'shown_not_ready_items' => $limitedNotReadyItems, 'full_category_audit' => $full_category_audit_summary, 'german_content_audit' => $german_content_audit_summary])); ?></pre>
        </details>
    </div>

    <div class="wei-box">
        <h2>Product sync status</h2>
        <p class="description">Widok bazowych pól/meta eBay jest ładowany dopiero po kliknięciu, bo wymaga zapytania po meta produktach. Pełne eksporty i manual publish zapisują te pola bez dotykania Allegro.</p>
        <p><a class="button" href="<?php echo esc_url($loadProductSyncUrl); ?>">Load recent product sync rows</a></p>
        <?php if ($productSyncRowsLoaded): ?>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Product</th><th>eBay SKU / inventory_id</th><th>offer_id</th><th>listing_id</th><th>public_url</th><th>last_export_at</th><th>last_publish_at</th><th>last_sync_status</th><th>last_sync_error</th><th>listing status</th></tr></thead><tbody><?php foreach ($productSyncRows as $row): ?><?php $editUrl = $toScalarString($row['edit_url'] ?? ''); $publicUrl = $toScalarString($row['public_url'] ?? ''); $skuOrInventory = $displayValue($row['sku'] ?? '') !== '—' ? $displayValue($row['sku'] ?? '') : $displayValue($row['inventory_id'] ?? ''); ?><tr><td><?php if ($editUrl !== ''): ?><a href="<?php echo esc_url($editUrl); ?>"><?php endif; ?>#<?php echo esc_html($displayValue($row['product_id'] ?? '')); ?> <?php echo esc_html($displayValue($row['title'] ?? '')); ?><?php if ($editUrl !== ''): ?></a><?php endif; ?></td><td><code><?php echo esc_html($skuOrInventory); ?></code></td><td><code><?php echo esc_html($displayValue($row['offer_id'] ?? '')); ?></code></td><td><code><?php echo esc_html($displayValue($row['listing_id'] ?? '')); ?></code></td><td><?php if ($publicUrl !== ''): ?><a href="<?php echo esc_url($publicUrl); ?>" target="_blank" rel="noopener noreferrer">open</a><?php else: ?><?php echo esc_html('—'); ?><?php endif; ?></td><td><?php echo esc_html($displayValue($row['last_export_at'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_publish_at'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_sync_status'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['last_sync_error'] ?? '')); ?></td><td><?php echo esc_html($displayValue($row['listing_status'] ?? '')); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <p class="description">Recent product sync rows are not loaded during normal page render.</p>
        <?php endif; ?>
    </div>

    <details class="wei-box"><summary>Manual tools <span class="wei-badge warn">advanced</span></summary>
        <div class="wei-actions">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_preflight'); ?><input type="hidden" name="action" value="wei_preflight_product" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Preflight only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_export'); ?><input type="hidden" name="action" value="wei_export_product" /><input type="number" name="product_id" placeholder="Woo product ID" required /><input type="text" name="ebay_category_id" placeholder="optional category ID" /><textarea name="ebay_aspects_json" placeholder='optional aspects JSON' rows="1"></textarea><button class="button button-primary">Preflight + export product</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_publish_product_offer_only'); ?><input type="hidden" name="action" value="wei_publish_product_offer_only" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button button-secondary">Publish this eBay offer only</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_verify_api_publishing_readiness'); ?><input type="hidden" name="action" value="wei_verify_api_publishing_readiness" /><input type="number" name="product_id" placeholder="Woo product ID" required /><label><input type="checkbox" name="write_diagnostic_offer" value="1" /> write diagnostic offer</label><button class="button">Verify eBay API publishing readiness</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_sync'); ?><input type="hidden" name="action" value="wei_sync_stock" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Sync stock for one product</button></form>
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_import_order'); ?><input type="hidden" name="action" value="wei_import_order" /><input class="regular-text" name="order_id" placeholder="optional eBay order ID" /><button class="button">Import one eBay order</button></form>
        </div>
        <details><summary>eBay-only SKU preparation</summary><div class="wei-grid"><div class="wei-card"><span>Products with _wei_ebay_sku</span><strong><?php echo esc_html($formatNullableCount($ebay_sku_status['products_with_wei_ebay_sku'] ?? null)); ?></strong></div><div class="wei-card"><span>Products missing _wei_ebay_sku</span><strong><?php echo esc_html($formatNullableCount($ebay_sku_status['products_missing_wei_ebay_sku'] ?? null)); ?></strong></div><div class="wei-card"><span>Generated in last run</span><strong><?php echo esc_html((string) ($ebay_sku_status['generated_in_last_run'] ?? 0)); ?></strong></div></div><p class="description">Full SKU counts scan all Woo products and are intentionally not computed on page load. The batch action below performs the heavy work explicitly.</p><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_ebay_skus'); ?><input type="hidden" name="action" value="wei_generate_ebay_skus" /><input type="number" name="batch_size" value="200" min="1" max="1000" /><button class="button button-primary">Generate missing eBay SKUs</button></form></details>
    </details>

    <details class="wei-box"><summary>Category mapping tools <span class="wei-badge"><?php echo esc_html($categoryRowsLoaded ? (string) $categorySummary['total'] . ' loaded categories' : 'not loaded'); ?></span></summary>
        <p class="description">Category mapping rows require a grouped product/category query and are not loaded during normal render.</p>
        <p><a class="button" href="<?php echo esc_url($loadCategoryMappingsUrl); ?>">Load category mapping rows</a></p>
        <div class="wei-grid"><div class="wei-card"><span>Manual mapped</span><strong><?php echo esc_html((string) $categorySummary['mapped_manual']); ?></strong></div><div class="wei-card"><span>Accepted auto</span><strong><?php echo esc_html((string) $categorySummary['accepted_auto']); ?></strong></div><div class="wei-card"><span>Needs review</span><strong><?php echo esc_html((string) $categorySummary['needs_category_review']); ?></strong></div><div class="wei-card"><span>Blocked</span><strong><?php echo esc_html((string) $categoryBlockedCount); ?></strong></div><div class="wei-card"><span>Last auto-map run</span><strong><?php echo esc_html((string) $categorySummary['last_auto_map_run']); ?></strong></div></div>
        <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_auto_map_categories'); ?><input type="hidden" name="action" value="wei_auto_map_categories" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button button-primary">Re-evaluate category mappings</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_blocked_category_mappings'); ?><input type="hidden" name="action" value="wei_repair_blocked_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Re-evaluate blocked only</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_repair_audit_category_groups'); ?><input type="hidden" name="action" value="wei_repair_audit_category_groups" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Repair from audit groups</button></form></div>
        <h3>Teaching CSV export/import</h3><div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_export_category_teaching_csv'); ?><input type="hidden" name="action" value="wei_export_category_teaching_csv" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button">Export teaching CSV</button></form><form method="post" enctype="multipart/form-data" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_import_category_teaching_csv'); ?><input type="hidden" name="action" value="wei_import_category_teaching_csv" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="file" name="teaching_csv" accept=".csv,text/csv" required /><button class="button button-primary">Import filled teaching CSV</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_apply_manual_woo_category_mappings'); ?><input type="hidden" name="action" value="wei_apply_manual_woo_category_mappings" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><button class="button button-primary">Apply manual Woo category mappings to all products</button></form></div>
        <h3>Reports and summaries</h3><pre class="wei-scroll"><?php echo esc_html($redactTechnical(wp_json_encode(['last_teaching_export' => $category_teaching_export_summary, 'last_teaching_import' => $category_teaching_import_summary, 'last_manual_apply' => $manual_woo_category_apply_summary, 'last_group_repair' => $category_group_repair_summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '')); ?></pre>
        <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_test_category_teaching_rule_match'); ?><input type="hidden" name="action" value="wei_test_category_teaching_rule_match" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="number" name="product_id" placeholder="Woo product ID" /><button class="button">Test teaching rule match</button></form>
        <h3>Category mapping reports</h3><p><?php foreach (['all' => 'All', 'mapped_auto' => 'Auto', 'mapped_manual' => 'Manual', 'needs_review' => 'Needs review', 'blocked' => 'Blocked'] as $filter => $label): ?><a href="<?php echo esc_url($filterUrl($filter)); ?>"><?php echo $currentFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === 'blocked' ? '' : ' / '; ?><?php endforeach; ?></p>
        <?php if ($categoryRowsLoaded): ?>
            <div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>Woo category</th><th>Products</th><th>eBay category</th><th>Source</th><th>Confidence</th><th>Status</th><th>Reason</th><th>Manual fallback</th></tr></thead><tbody><?php foreach ($filteredCategoryRows as $row): ?><tr><td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td><td><code><?php echo esc_html((string) ($row['_ui_category_id'] ?? '')); ?></code><br><?php echo esc_html((string) ($row['_ui_category_path'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['source'] ?? '')); ?></td><td><?php echo esc_html(isset($row['confidence']) ? number_format((float) $row['confidence'], 4) : ''); ?></td><td><?php echo esc_html((string) ($row['_ui_status'] ?? '')); ?></td><td><?php echo esc_html((string) ($row['_ui_sanity_reason'] ?? $row['error_reason'] ?? '')); ?></td><td><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_save_category_mapping'); ?><input type="hidden" name="action" value="wei_save_category_mapping" /><input type="hidden" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" /><input type="hidden" name="woo_term_id" value="<?php echo esc_attr((string) ($row['term_id'] ?? '0')); ?>" /><input type="text" name="ebay_category_id" placeholder="category ID" value="<?php echo esc_attr((string) ($row['ebay_category_id'] ?? '')); ?>" size="8" /><input type="text" name="ebay_category_name" placeholder="name" value="<?php echo esc_attr((string) ($row['ebay_category_name'] ?? '')); ?>" /><input type="text" name="ebay_category_path" placeholder="path" value="<?php echo esc_attr((string) ($row['ebay_category_path'] ?? '')); ?>" /><button class="button">Save mapping</button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <?php else: ?>
            <p class="description">Open this section with the load button or a filter link to fetch the first 50 category mapping rows.</p>
        <?php endif; ?>
    </details>

    <details class="wei-box"><summary>eBay account / API status <span class="wei-badge <?php echo esc_attr($accountSetupMissingCount > 0 ? 'warn' : 'ok'); ?>"><?php echo esc_html($accountSetupMissingCount > 0 ? (string) $accountSetupMissingCount . ' missing' : 'configured'); ?></span></summary>
        <div class="wei-actions"><a class="button button-primary" href="<?php echo esc_url($connectUrl); ?>">Connect eBay</a><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_upsert_inventory_location'); ?><input type="hidden" name="action" value="wei_upsert_inventory_location" /><button class="button">Create / Update inventory location</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_refresh_policies'); ?><input type="hidden" name="action" value="wei_refresh_policies" /><button class="button">Refresh policies from eBay</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run API readiness check</button></form></div>
        <div class="wei-grid"><div class="wei-card"><span>OAuth/token status</span><strong><?php echo esc_html((string) ($status['token_status'] ?? $lastAction)); ?></strong></div><div class="wei-card"><span>Policies</span><strong><?php echo esc_html($accountSetupConfigured ? 'configured' : 'missing IDs'); ?></strong></div><div class="wei-card"><span>Merchant location</span><strong><?php echo esc_html($locationKey !== '' ? $locationKey : 'missing'); ?></strong></div><div class="wei-card"><span>Privileges/programs</span><strong><?php echo esc_html((string) $displayValue($autoSync['account_restriction_status'] ?? '')); ?></strong></div></div>
        <pre class="wei-scroll"><?php echo esc_html($technicalPreview(['cached_policies' => $cached, 'last_status' => $status, 'auto_sync' => array_diff_key($autoSync, array_flip(['readiness_summary']))])); ?></pre>
    </details>

    <details class="wei-box"><summary>Logs / debug <span class="wei-badge <?php echo esc_attr($latestLogHasError || $lastStatusIsError ? 'error' : 'ok'); ?>">recent</span></summary>
        <h3>Recent status</h3><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['last_action' => $lastAction, 'product_id' => $lastProductId, 'stage' => $lastStage, 'message' => $lastShortMessage, 'payload' => $lastStatusPayload])); ?></pre>
        <h3>Recent logs/errors</h3><div class="wei-scroll-table"><table class="widefat striped"><thead><tr><th>At</th><th>Level</th><th>Message</th><th>Context</th></tr></thead><tbody><?php foreach ($recentLogs as $log): ?><tr><td><?php echo esc_html((string) ($log['at'] ?? '')); ?></td><td><?php echo esc_html((string) ($log['level'] ?? '')); ?></td><td><?php echo esc_html((string) ($log['message'] ?? '')); ?></td><td><pre class="wei-scroll"><?php echo esc_html($technicalPreview($log['context'] ?? [], 2000)); ?></pre></td></tr><?php endforeach; ?></tbody></table></div>
    </details>
</div>
