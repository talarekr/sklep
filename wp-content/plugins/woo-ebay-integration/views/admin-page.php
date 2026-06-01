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
$renderFulfillmentPolicyControl = static function (string $field, string $selectedId, string $manualId, string $placeholder) use ($fulfillmentPolicies): void {
    $selectedId = trim($selectedId);
    $idInCachedPolicies = false;
    foreach ($fulfillmentPolicies as $policy) {
        if ((string) ($policy['fulfillmentPolicyId'] ?? '') === $selectedId) {
            $idInCachedPolicies = true;
            break;
        }
    }
    $manualValue = '';
    ?>
    <select name="<?php echo esc_attr($field); ?>">
        <option value=""><?php echo esc_html($placeholder); ?></option>
        <?php foreach ($fulfillmentPolicies as $policy): ?>
            <?php
            $policyId = (string) ($policy['fulfillmentPolicyId'] ?? '');
            if ($policyId === '') {
                continue;
            }
            $policyName = (string) ($policy['name'] ?? $policyId);
            $label = $policyName . ' — ' . $policyId;
            ?>
            <option value="<?php echo esc_attr($policyId); ?>" <?php selected($selectedId, $policyId); ?>><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
    </select>
    <input type="hidden" name="<?php echo esc_attr($field . '_existing'); ?>" value="<?php echo esc_attr($selectedId); ?>" />
    <input class="regular-text" name="<?php echo esc_attr($field . '_manual'); ?>" value="<?php echo esc_attr($manualValue); ?>" placeholder="or paste exact fulfillment policy ID" />
    <?php if ($selectedId !== '' && !$idInCachedPolicies): ?><span class="description">Current saved ID: <code><?php echo esc_html($selectedId); ?></code> (not in cached policy list)</span><?php endif; ?>
    <?php
};
$locationKey = (string) ($s['inventory_location_key'] ?? 'gpswiss-pl');
$fulfillmentId = (string) ($s['ebay_fulfillment_policy_id'] ?? '');
$fulfillmentId30 = (string) ($s['shipping_policy_30'] ?? $s['fulfillment_policy_id_30_eur'] ?? $fulfillmentId);
$fulfillmentId50 = (string) ($s['shipping_policy_50'] ?? $s['fulfillment_policy_id_50_eur'] ?? '');
$fulfillmentId130 = (string) ($s['shipping_policy_130'] ?? $s['fulfillment_policy_id_130_eur'] ?? '');
$defaultShippingPolicyId = (string) ($s['default_shipping_policy_id'] ?? '');
$shippingMappingWarnings = get_option('wei_ebay_shipping_mapping_warnings', []);
$shippingMappingWarnings = is_array($shippingMappingWarnings) ? $shippingMappingWarnings : [];
$paymentId = (string) ($s['ebay_payment_policy_id'] ?? '');
$returnId = (string) ($s['ebay_return_policy_id'] ?? '');
$accountSetupConfigured = $locationKey !== '' && $fulfillmentId30 !== '' && $fulfillmentId50 !== '' && $fulfillmentId130 !== '' && $paymentId !== '' && $returnId !== '';

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
$categoryDashboardSummary = is_array($category_dashboard_summary ?? null) ? $category_dashboard_summary : [];
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

    $manualStatus = $displayCategoryId === '' ? 'missing' : 'needs_category_review';
    $validationByTerm = is_array($category_validation_statuses['by_woo_term_id'] ?? null) ? $category_validation_statuses['by_woo_term_id'] : [];
    $validationByCategory = is_array($category_validation_statuses['by_category_id'] ?? null) ? $category_validation_statuses['by_category_id'] : [];
    $rowValidation = is_array($validationByTerm[(string) ($row['term_id'] ?? '')] ?? null) ? $validationByTerm[(string) ($row['term_id'] ?? '')] : (is_array($validationByCategory[$displayCategoryId] ?? null) ? $validationByCategory[$displayCategoryId] : []);
    if ($displayCategoryId === '') {
        $manualStatus = 'missing';
    } elseif (!empty($rowValidation)) {
        if (empty($rowValidation['valid'])) {
            $manualStatus = 'invalid_ebay_category_id';
        } elseif (empty($rowValidation['leaf'])) {
            $manualStatus = 'non_leaf_category';
        } else {
            $manualStatus = 'valid';
        }
    } elseif (in_array($statusValue, ['needs_category_review', 'blocked_by_threshold', 'blocked_by_sanity', 'category_sanity_failed'], true)) {
        $manualStatus = $statusValue === 'blocked_by_sanity' ? 'blocked_by_category' : 'needs_category_review';
    } elseif (in_array($statusValue, ['accepted_manual', 'accepted_auto'], true)) {
        $manualStatus = 'valid';
    }

    $row['_ui_status'] = $manualStatus;
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
$wooCategorySearch = isset($_GET['woo_category_search']) ? sanitize_text_field(wp_unslash((string) $_GET['woo_category_search'])) : '';
$ebayCategoryIdSearch = isset($_GET['current_ebay_category_id']) ? sanitize_text_field(wp_unslash((string) $_GET['current_ebay_category_id'])) : '';
$filteredCategoryRows = array_values(array_filter($categoryRows, static function (array $row) use ($currentFilter, $wooCategorySearch, $ebayCategoryIdSearch): bool {
    $statusValue = (string) ($row['_ui_status'] ?? '');
    if ($currentFilter !== 'all' && $currentFilter !== '' && $statusValue !== $currentFilter) {
        return false;
    }
    if ($wooCategorySearch !== '' && !str_contains(strtolower((string) ($row['woo_category_path'] ?? $row['name'] ?? '')), strtolower($wooCategorySearch))) {
        return false;
    }
    if ($ebayCategoryIdSearch !== '' && !str_contains((string) ($row['_ui_category_id'] ?? $row['ebay_category_id'] ?? ''), $ebayCategoryIdSearch)) {
        return false;
    }
    return true;
}));
if ($currentSort === '') {
    usort($filteredCategoryRows, static fn (array $a, array $b): int => ((int) ($b['product_count'] ?? 0)) <=> ((int) ($a['product_count'] ?? 0)));
} elseif ($currentSort === 'confidence') {
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
$publishReadinessReports = is_array($readinessSummary['reports'] ?? null) ? $readinessSummary['reports'] : [];
$readinessFilter = sanitize_key((string) ($_GET['readiness_filter'] ?? 'all'));
$readinessBucketMap = [
    'all' => 'not_ready_items',
    'blocked_by_category' => 'blocked_by_category_items',
    'excluded_from_ebay' => 'excluded_from_ebay_items',
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
    'excluded_from_ebay' => 'Excluded from eBay',
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
$accountSetupMissingCount = ($locationKey === '' ? 1 : 0) + ($fulfillmentId30 === '' ? 1 : 0) + ($fulfillmentId50 === '' ? 1 : 0) + ($fulfillmentId130 === '' ? 1 : 0) + ($paymentId === '' ? 1 : 0) + ($returnId === '' ? 1 : 0);
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
$manualCategoryPickerRows = is_array($manual_category_picker_rows ?? null) ? $manual_category_picker_rows : [];
$manualCategoryPickerQuery = (string) ($manual_category_picker_query ?? '');
$vehicleCompatibilityAuditSummary = is_array($vehicle_compatibility_audit_summary ?? null) ? $vehicle_compatibility_audit_summary : [];
$vehicleCompatibilityDiagnostics = is_array($vehicle_compatibility_diagnostics ?? null) ? $vehicle_compatibility_diagnostics : [];
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
$readinessBlockedByCategoryCount = (int) ($readinessSummary['blocked_by_category'] ?? 0);
$readinessBlockedCount = (int) ($readinessSummary['blocked'] ?? $readinessSummary['not_ready'] ?? 0);
$readinessReadyCount = (int) ($readinessSummary['ready'] ?? 0);
$readinessProcessedTotal = (int) ($readinessSummary['processed_total'] ?? $readinessSummary['processed'] ?? 0);
$readinessTotalProducts = (int) ($readinessSummary['total_products'] ?? 0);
$excludedFromEbayCount = (int) ($readinessSummary['excluded_from_ebay'] ?? 0);
$excludedNoWooCategoryCount = (int) ($readinessSummary['excluded_no_woo_category'] ?? 0);
$excludedBezKategoriiCount = (int) ($readinessSummary['excluded_bez_kategorii'] ?? 0);
$missingAspectsCount = (int) ($readinessSummary['blocked_by_required_aspects'] ?? $readinessSummary['missing_required_aspects'] ?? 0);
$publishAuditComplete = !empty($readinessSummary['complete']) || (string) ($readinessSummary['status'] ?? '') === 'completed';
$publishAuditStatusLabel = $publishAuditComplete ? 'COMPLETE / FINAL KPI' : 'PARTIAL / IN PROGRESS';
$publishAuditRemainingProducts = (int) ($readinessSummary['remaining_products'] ?? max(0, $readinessTotalProducts - $readinessProcessedTotal));
$publishAuditBatchSize = (int) ($readinessSummary['batch_size'] ?? 200);
$publishAuditRunId = (string) ($readinessSummary['audit_run_id'] ?? '');
$initialPublishBlockedCount = (int) ($initialPublishCandidates['blocked_by_category'] ?? 0)
    + (int) ($initialPublishCandidates['missing_aspects'] ?? 0)
    + (int) ($initialPublishCandidates['content_not_ready'] ?? 0)
    + (int) ($initialPublishCandidates['price_not_ready'] ?? 0)
    + (int) ($initialPublishCandidates['other'] ?? 0)
    + (int) ($initialPublishCandidates['errors'] ?? 0);
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
    <?php if (!empty($shippingMappingWarnings['conflicts'])): ?><div class="notice notice-warning"><p><strong>eBay shipping category mapping conflict:</strong> Woo category IDs appear in multiple groups. Runtime priority is Wysyłka 130 &gt; Wysyłka 50 &gt; Wysyłka 30.</p><pre><?php echo esc_html(wp_json_encode($shippingMappingWarnings['conflicts'], JSON_PRETTY_PRINT)); ?></pre></div><?php endif; ?>

    <div class="wei-box">
        <h2>Dashboard / Status</h2>
        <p class="description">Krótki operacyjny stan integracji. Ten widok nie wykonuje pełnych skanów produktów ani nie uruchamia workerów.</p>
        <div class="wei-grid">
            <div class="wei-card"><span>eBay account/API status</span><strong><span class="wei-badge <?php echo esc_attr($accountSetupMissingCount > 0 ? 'warn' : 'ok'); ?>"><?php echo esc_html($accountSetupMissingCount > 0 ? (string) $accountSetupMissingCount . ' missing' : 'configured'); ?></span></strong></div>
            <div class="wei-card"><span>Marketplace</span><strong><?php echo esc_html((string) $setting('marketplace_id', 'EBAY_DE')); ?></strong></div>
            <div class="wei-card"><span>Last successful sync</span><strong><?php echo esc_html((string) ($autoSync['checkpoint']['last_success_at'] ?? '-')); ?></strong></div>
            <div class="wei-card"><span>Last error</span><strong><?php echo esc_html((string) (($autoSync['checkpoint']['last_error'] ?? $initialPublish['last_error'] ?? '') ?: '-')); ?></strong></div>
            <div class="wei-card"><span>Ready to publish (latest readiness scan)</span><strong><?php echo esc_html((string) $readinessReadyCount); ?></strong></div>
            <div class="wei-card"><span>Blocked by category (latest readiness scan)</span><strong><?php echo esc_html((string) $readinessBlockedByCategoryCount); ?></strong></div>
            <div class="wei-card"><span>Excluded from eBay</span><strong><?php echo esc_html((string) $excludedFromEbayCount); ?></strong></div>
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
        <p class="description">Step-by-step EBAY_DE category workflow. This module only reads/writes category mappings and local CSV diagnostics; worklist import never publishes, revises listings, or calls the eBay API.</p>
        <?php
        $categoryAuditSummary = is_array($category_readiness_audit_summary ?? null) ? $category_readiness_audit_summary : [];
        $lastCategoryAudit = is_array($categoryAuditSummary['last_category_readiness_audit'] ?? null) ? $categoryAuditSummary['last_category_readiness_audit'] : [];
        if ($lastCategoryAudit === []) {
            $lastCategoryAudit = get_option('wei_ebay_last_category_readiness_audit', []);
            $lastCategoryAudit = is_array($lastCategoryAudit) ? $lastCategoryAudit : [];
        }
        $categoryAuditDownloadUrl = static function (string $path): string {
            $file = basename($path);
            return $file !== '' ? admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode($file)) : '';
        };
        $categoryAuditFullReady = !empty($lastCategoryAudit['full_report_csv_exists']) && (int) ($lastCategoryAudit['full_report_csv_size'] ?? 0) > 0;
        $categoryAuditProblemsReady = !empty($lastCategoryAudit['problems_only_csv_exists']) && (int) ($lastCategoryAudit['problems_only_csv_size'] ?? 0) > 0;
        $categoryAuditExcludedReady = !empty($lastCategoryAudit['excluded_products_csv_exists']) && (int) ($lastCategoryAudit['excluded_products_csv_size'] ?? 0) > 0;
        $categoryAuditFullAdminUrl = (string) ($lastCategoryAudit['full_report_csv_admin_url'] ?? $categoryAuditDownloadUrl((string) ($lastCategoryAudit['full_report_csv_path'] ?? '')));
        $categoryAuditProblemsAdminUrl = (string) ($lastCategoryAudit['problems_only_csv_admin_url'] ?? $categoryAuditDownloadUrl((string) ($lastCategoryAudit['problems_only_csv_path'] ?? '')));
        $categoryAuditExcludedAdminUrl = (string) ($lastCategoryAudit['excluded_products_csv_admin_url'] ?? $categoryAuditDownloadUrl((string) ($lastCategoryAudit['excluded_products_csv_path'] ?? '')));
        $worklistReady = !empty($category_mapping_worklist_summary['worklist_csv_exists']) && (int) ($category_mapping_worklist_summary['worklist_csv_size'] ?? 0) > 0;
        $worklistDownloadUrl = admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode('category-mapping-worklist.csv'));
        $allWorklistReady = !empty($all_category_mapping_worklist_summary['worklist_csv_exists']) && (int) ($all_category_mapping_worklist_summary['worklist_csv_size'] ?? 0) > 0;
        $allWorklistDownloadUrl = admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode('all-category-mapping-worklist.csv'));
        $blockedFixRecommendationsReady = !empty($blocked_category_fix_report_summary['recommendations_csv_exists']) && (int) ($blocked_category_fix_report_summary['recommendations_csv_size'] ?? 0) > 0;
        $blockedFixImportReady = !empty($blocked_category_fix_report_summary['fix_import_csv_exists']) && (int) ($blocked_category_fix_report_summary['fix_import_csv_size'] ?? 0) > 0;
        $blockedFixRecommendationDownloadUrl = admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode('blocked_category_mapping_recommendations.csv'));
        $blockedFixImportDownloadUrl = admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode('blocked_category_mapping_fix_import.csv'));
        $auditIsComplete = !empty($categoryAuditSummary['complete']) || (string) ($categoryAuditSummary['status'] ?? '') === 'completed';
        $auditLabel = $auditIsComplete ? 'FULL COMPLETED AUDIT' : 'PARTIAL AUDIT / IN PROGRESS';
        $lastAuditTime = (string) ($lastCategoryAudit['finished_at'] ?? $lastCategoryAudit['completed_at'] ?? $lastCategoryAudit['updated_at'] ?? '');
        $lastImportTime = (string) ($category_mapping_worklist_import_summary['imported_at'] ?? $category_mapping_worklist_import_summary['updated_at'] ?? '');
        $primaryNextAction = $lastAuditTime === '' ? 'Run category readiness audit' : (!$worklistReady ? 'Generate category-mapping-worklist.csv' : ($lastImportTime === '' ? 'Import filled category-mapping-worklist.csv' : 'Run category readiness audit'));
        ?>
        <div class="notice notice-info inline"><p><strong>Primary next action:</strong> <?php echo esc_html($primaryNextAction); ?></p></div>
        <div class="wei-grid">
            <div class="wei-card"><span>marketplace</span><strong>EBAY_DE</strong></div>
            <div class="wei-card"><span>categories with products</span><strong><?php echo esc_html((string) ($categoryDashboardSummary['categories_with_products'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>mapped categories with products</span><strong><?php echo esc_html((string) ($categoryDashboardSummary['mapped_categories_with_products'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>valid categories</span><strong><?php echo esc_html((string) ($categoryDashboardSummary['valid_categories'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>category blockers</span><strong><?php echo esc_html((string) ($categoryDashboardSummary['blocked_by_category'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>last audit time</span><strong><?php echo esc_html($lastAuditTime !== '' ? $lastAuditTime : '—'); ?></strong></div>
            <div class="wei-card"><span>last import time</span><strong><?php echo esc_html($lastImportTime !== '' ? $lastImportTime : '—'); ?></strong></div>
        </div>

        <section class="wei-card" data-wei-category-section="status-audyt"><h3>Section 1 — Status / Audyt</h3>
            <p class="description">Audit and diagnostics only. CSV output includes resolver-selected mapping details and explicit category problem reasons.</p>
            <div class="wei-actions">
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_run_category_readiness_audit'); ?><input type="hidden" name="action" value="wei_run_category_readiness_audit" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><input type="hidden" name="audit_full_run" value="1" /><label>Batch size <input type="number" name="audit_batch_size" value="200" min="1" max="200" step="1" style="width:80px" /></label><button class="button button-primary">Run full category readiness audit</button></form>
                <?php if (!$auditIsComplete && !empty($categoryAuditSummary['audit_run_id'])): ?><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_run_category_readiness_audit'); ?><input type="hidden" name="action" value="wei_run_category_readiness_audit" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><input type="hidden" name="continue_audit" value="1" /><label>Batch size <input type="number" name="audit_batch_size" value="<?php echo esc_attr((string) ($categoryAuditSummary['batch_size'] ?? 200)); ?>" min="1" max="200" step="1" style="width:80px" /></label><button class="button">Continue audit</button></form><?php endif; ?>
                <?php if ($categoryAuditFullReady): ?><a class="button" href="<?php echo esc_url($categoryAuditFullAdminUrl); ?>">Download full audit CSV</a><span class="description"><?php echo esc_html(basename((string) ($lastCategoryAudit['full_report_csv_path'] ?? ''))); ?> · <?php echo esc_html($lastAuditTime); ?></span><?php endif; ?>
                <?php if ($categoryAuditProblemsReady): ?><a class="button" href="<?php echo esc_url($categoryAuditProblemsAdminUrl); ?>">Download problems-only CSV</a><span class="description"><?php echo esc_html(basename((string) ($lastCategoryAudit['problems_only_csv_path'] ?? ''))); ?> · <?php echo esc_html($lastAuditTime); ?></span><?php endif; ?>
                <?php if ($categoryAuditExcludedReady): ?><a class="button" href="<?php echo esc_url($categoryAuditExcludedAdminUrl); ?>">Download excluded-products CSV</a><span class="description"><?php echo esc_html(basename((string) ($lastCategoryAudit['excluded_products_csv_path'] ?? ''))); ?> · <?php echo esc_html($lastAuditTime); ?></span><?php endif; ?>
            </div>
            <p><strong><?php echo esc_html($auditLabel); ?></strong></p>
            <?php if (!$auditIsComplete): ?><div class="notice notice-warning inline"><p>Do not use these KPI as final publish readiness numbers.</p></div><?php endif; ?>
            <div class="wei-grid">
                <?php foreach (['batch_size' => 'batch_size', 'current_offset' => 'current_offset', 'processed_total' => 'processed_total', 'total_products' => 'total_products', 'remaining_products' => 'remaining_products', 'complete' => 'complete', 'audit_run_id' => 'audit_run_id', 'started_at' => 'started_at', 'finished_at' => 'finished_at'] as $label => $key): ?>
                    <div class="wei-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html(is_bool($categoryAuditSummary[$key] ?? null) ? (($categoryAuditSummary[$key] ?? false) ? 'true' : 'false') : (string) ($categoryAuditSummary[$key] ?? '—')); ?></strong></div>
                <?php endforeach; ?>
            </div>
            <div class="wei-grid">
                <?php foreach (['processed' => 'processed_total', 'ready' => 'ready', 'blocked' => 'blocked_by_category', 'excluded_from_ebay' => 'excluded_from_ebay', 'excluded_no_woo_category' => 'excluded_no_woo_category', 'excluded_bez_kategorii' => 'excluded_bez_kategorii', 'missing_category' => 'missing_category', 'invalid_ebay_category_id' => 'invalid_ebay_category_id', 'non_leaf_category' => 'non_leaf_category', 'category_sanity_failed' => 'category_sanity_failed', 'needs_category_review' => 'needs_category_review', 'missing_required_aspects' => 'missing_required_aspects'] as $label => $key): ?>
                    <div class="wei-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html((string) ($auditIsComplete ? ($categoryAuditSummary[$key] ?? 0) : ($categoryAuditSummary[$key] ?? 0))); ?></strong></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="wei-card" data-wei-category-section="reczne-mapowanie"><h3>Section 2 — Ręczne mapowanie kategoriami</h3>
            <p class="description"><strong>Fill only final_ebay_category_id. Leave uncertain categories empty. Empty rows are skipped.</strong> Use all-category-mapping-worklist.csv to complete mapping for every Woo category with products.</p>
            <div class="wei-actions">
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_category_mapping_worklist'); ?><input type="hidden" name="action" value="wei_generate_category_mapping_worklist" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><button class="button button-primary">Generate category-mapping-worklist.csv</button></form>
                <?php if ($worklistReady): ?><a class="button button-primary" href="<?php echo esc_url($worklistDownloadUrl); ?>">Download category-mapping-worklist.csv</a><span class="description"><?php echo esc_html(basename((string) ($category_mapping_worklist_summary['worklist_csv_path'] ?? 'category-mapping-worklist.csv'))); ?> · <?php echo esc_html((string) ($category_mapping_worklist_summary['generated_at'] ?? $category_mapping_worklist_summary['updated_at'] ?? '')); ?> · <?php echo esc_html((string) (int) ($category_mapping_worklist_summary['rows'] ?? 0)); ?> rows</span><?php endif; ?>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_all_category_mapping_worklist'); ?><input type="hidden" name="action" value="wei_generate_all_category_mapping_worklist" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><button class="button button-primary">Generate all-category-mapping-worklist.csv</button></form>
                <?php if ($allWorklistReady): ?><a class="button button-primary" href="<?php echo esc_url($allWorklistDownloadUrl); ?>">Download all-category-mapping-worklist.csv</a><span class="description"><?php echo esc_html(basename((string) ($all_category_mapping_worklist_summary['worklist_csv_path'] ?? 'all-category-mapping-worklist.csv'))); ?> · <?php echo esc_html((string) ($all_category_mapping_worklist_summary['generated_at'] ?? $all_category_mapping_worklist_summary['updated_at'] ?? '')); ?> · <?php echo esc_html((string) (int) ($all_category_mapping_worklist_summary['rows'] ?? 0)); ?> rows</span><?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_import_category_mapping_worklist'); ?><input type="hidden" name="action" value="wei_import_category_mapping_worklist" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><input type="file" name="category_mapping_worklist_csv" accept=".csv,text/csv" required /><button class="button">Import filled category-mapping-worklist.csv</button></form>
            </div>
            <div class="wei-grid">
                <?php foreach (['accepted' => 'accepted', 'skipped' => 'skipped_empty_final_ebay_category_id', 'rejected' => 'rejected', 'inserted' => 'inserted_mappings', 'updated' => 'updated_mappings', 'deactivated duplicates' => 'deactivated_duplicate_mappings', 'unchanged' => 'unchanged_mappings'] as $label => $key): ?>
                    <div class="wei-card"><span><?php echo esc_html($label); ?></span><strong><?php echo esc_html((string) ($category_mapping_worklist_import_summary[$key] ?? 0)); ?></strong></div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($category_mapping_worklist_import_summary['warnings'])): ?><details open><summary>Errors / warnings</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($category_mapping_worklist_import_summary['warnings'], 3000)); ?></pre></details><?php endif; ?>
            <?php if (!empty($category_mapping_worklist_import_summary['import_debug_rows'])): ?><details open><summary>First 5 import debug rows</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($category_mapping_worklist_import_summary['import_debug_rows'], 6000)); ?></pre></details><?php endif; ?>
            <?php if (!empty($category_mapping_worklist_import_summary)): ?><details><summary>Latest import summary JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($category_mapping_worklist_import_summary, 6000)); ?></pre></details><?php endif; ?>
        </section>

        <section class="wei-card" data-wei-category-section="category-cache-diagnostic"><h3>Category cache diagnostic</h3>
            <p class="description">Local EBAY_DE taxonomy cache health used by the worklist validator. Sample lookups include 33544, 33615, 33566, 9886, and 171115.</p>
            <div class="wei-grid">
                <div class="wei-card"><span>marketplace_id</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['marketplace_id'] ?? 'EBAY_DE')); ?></strong></div>
                <div class="wei-card"><span>total cached categories</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['total_cached_categories'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>cached leaf categories</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['cached_leaf_categories'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>cached non-leaf categories</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['cached_non_leaf_categories'] ?? 0)); ?></strong></div>
                <div class="wei-card"><span>taxonomy version</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['taxonomy_version'] ?? '')); ?></strong></div>
                <div class="wei-card"><span>last cache refresh/import time</span><strong><?php echo esc_html((string) ($category_cache_diagnostic['last_cache_refresh_import_time'] ?? '')); ?></strong></div>
            </div>
            <details open><summary>Sample lookup by category ID</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($category_cache_diagnostic['sample_lookup_by_category_id'] ?? [], 6000)); ?></pre></details>
        </section>

        <section class="wei-card" data-wei-category-section="diagnostyka-mappingu"><h3>Section 3 — Diagnostyka mappingu</h3>
            <p class="description">Debug why the production resolver selects or blocks a Woo category. Example diagnostic case: Woo category ID 5197 should select manual_worklist eBay category 33566 after import.</p>
            <form method="get" action="<?php echo esc_url($adminPageUrl); ?>" class="wei-actions"><input type="hidden" name="page" value="woo-ebay" /><label>Woo category ID <input type="number" min="1" name="woo_category_diagnostics_id" value="<?php echo esc_attr((string) ($category_mapping_diagnostics_id ?? '')); ?>" /></label><button class="button">Show mapping diagnostics</button></form>
            <?php if (!empty($category_mapping_diagnostics)): ?>
                <div class="wei-grid"><div class="wei-card"><span>selected eBay category</span><strong><?php echo esc_html((string) ($category_mapping_diagnostics['selected_ebay_category_id'] ?? '')); ?></strong></div><div class="wei-card"><span>source</span><strong><?php echo esc_html((string) ($category_mapping_diagnostics['selected_source'] ?? '')); ?></strong></div><div class="wei-card"><span>leaf/cache</span><strong><?php echo !empty($category_mapping_diagnostics['selected_ebay_category_exists_in_cache']) ? (!empty($category_mapping_diagnostics['selected_ebay_category_is_leaf']) ? 'valid leaf in cache' : 'non-leaf in cache') : 'missing from cache'; ?></strong></div><div class="wei-card"><span>audit status</span><strong><?php echo esc_html((string) ($category_mapping_diagnostics['audit_category_status'] ?? '')); ?></strong></div></div>
                <details open><summary>Resolver diagnostics JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($category_mapping_diagnostics, 8000)); ?></pre></details>
            <?php endif; ?>
        </section>

        <section class="wei-card" data-wei-category-section="automatyczne-sugestie-legacy-tools"><h3>Section 4 — Automatyczne sugestie / legacy tools</h3>
            <p class="wei-danger"><strong>Advanced:</strong> use only for rule-based suggestions. Manual worklist import has priority.</p>
            <div class="wei-actions">
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_blocked_category_fix_report'); ?><input type="hidden" name="action" value="wei_generate_blocked_category_fix_report" /><input type="hidden" name="marketplace_id" value="EBAY_DE" /><button class="button">Generate blocked category fix report</button></form>
                <?php if ($blockedFixRecommendationsReady): ?><a class="button" href="<?php echo esc_url($blockedFixRecommendationDownloadUrl); ?>">Download blocked_category_mapping_recommendations.csv</a><span class="description"><?php echo esc_html((string) ($blocked_category_fix_report_summary['generated_at'] ?? $blocked_category_fix_report_summary['updated_at'] ?? '')); ?></span><?php endif; ?>
                <?php if ($blockedFixImportReady): ?><a class="button" href="<?php echo esc_url($blockedFixImportDownloadUrl); ?>">Download blocked_category_mapping_fix_import.csv</a><span class="description"><?php echo esc_html((string) ($blocked_category_fix_report_summary['generated_at'] ?? $blocked_category_fix_report_summary['updated_at'] ?? '')); ?></span><?php endif; ?>
                <a class="button" href="<?php echo esc_url($loadCategoryMappingsUrl); ?>">Open mapping table</a>
            </div>
            <details><summary>Blocked category fix report JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview($blocked_category_fix_report_summary, 6000)); ?></pre></details>
        </section>

        <section class="wei-card" data-wei-category-section="ovoko-supplier-future"><h3>Section 5 — Ovoko / Supplier import, future</h3>
            <p class="description">Ovoko category mapping CSV can be imported here later when available.</p>
        </section>

        <details><summary>Category dashboard summary JSON</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['category_dashboard_summary' => $categoryDashboardSummary, 'last_audit' => $lastCategoryAudit, 'worklist' => $category_mapping_worklist_summary, 'all_worklist' => $all_category_mapping_worklist_summary], 6000)); ?></pre></details>
    </div>

    <div class="wei-box" data-wei-module="publish">
        <h2>3. Publish</h2>
        <p class="description">Publish ready products only. Run the full publish readiness audit first; it only reads local Woo/product meta data and writes CSV diagnostics. Publish/export buttons remain separate actions.</p>
        <p class="wei-danger"><strong>Warning:</strong> Publish run counters, readiness audit counters, and eBay listing state refresh counters are intentionally separate. These counters are from the last publish run. Reset progress before starting a new full publish run if listings were ended manually on eBay.</p>
        <h3>Latest readiness scan</h3>
        <?php if (!$publishAuditComplete): ?><div class="notice notice-warning inline"><p><strong>PARTIAL / IN PROGRESS:</strong> These are checkpointed partial counts only. The dashboard shows final publish readiness KPI only after <code>complete=true</code>.</p></div><?php endif; ?>
        <div class="wei-grid">
            <div class="wei-card"><span>audit status</span><strong><?php echo esc_html($publishAuditStatusLabel); ?></strong></div>
            <div class="wei-card"><span>processed_total / total_products</span><strong><?php echo esc_html((string) $readinessProcessedTotal . ' / ' . (string) $readinessTotalProducts); ?></strong></div>
            <div class="wei-card"><span>remaining_products</span><strong><?php echo esc_html((string) $publishAuditRemainingProducts); ?></strong></div>
            <div class="wei-card"><span>batch_size</span><strong><?php echo esc_html((string) $publishAuditBatchSize); ?></strong></div>
            <div class="wei-card"><span>complete</span><strong><?php echo $publishAuditComplete ? 'true' : 'false'; ?></strong></div>
            <div class="wei-card"><span>current_offset</span><strong><?php echo esc_html((string) ($readinessSummary['current_offset'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>processed_total</span><strong><?php echo esc_html((string) $readinessProcessedTotal); ?></strong></div>
            <div class="wei-card"><span>total_products</span><strong><?php echo esc_html((string) $readinessTotalProducts); ?></strong></div>
            <div class="wei-card"><span><?php echo $publishAuditComplete ? 'ready (final KPI)' : 'ready (partial)'; ?></span><strong><?php echo esc_html((string) $readinessReadyCount); ?></strong></div>
            <div class="wei-card"><span>blocked</span><strong><?php echo esc_html((string) $readinessBlockedCount); ?></strong></div>
            <div class="wei-card"><span>excluded_from_ebay</span><strong><?php echo esc_html((string) $excludedFromEbayCount); ?></strong></div>
            <div class="wei-card"><span>excluded_no_woo_category</span><strong><?php echo esc_html((string) $excludedNoWooCategoryCount); ?></strong></div>
            <div class="wei-card"><span>excluded_bez_kategorii</span><strong><?php echo esc_html((string) $excludedBezKategoriiCount); ?></strong></div>
            <div class="wei-card"><span>blocked_by_category</span><strong><?php echo esc_html((string) $readinessBlockedByCategoryCount); ?></strong></div>
            <div class="wei-card"><span>blocked_by_price</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_price'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>blocked_by_stock</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_stock'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>blocked_by_images</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_images'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>blocked_by_german_content</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_german_content'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>blocked_by_required_aspects</span><strong><?php echo esc_html((string) $missingAspectsCount); ?></strong></div>
        </div>
        <h3>Latest publish run</h3>
        <div class="wei-grid">
            <div class="wei-card"><span>processed_this_run</span><strong><?php echo esc_html((string) ($initialPublish['processed'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>exported_this_run</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?></strong></div>
            <div class="wei-card"><span>published_this_run</span><strong><?php echo esc_html((string) $initialPublishSuccess); ?></strong></div>
            <div class="wei-card"><span>skipped_this_run</span><strong><?php echo esc_html((string) $initialPublishSkippedThisRun); ?></strong></div>
            <div class="wei-card"><span>errors_this_run</span><strong><?php echo esc_html((string) $initialPublishFailed); ?></strong></div>
            <div class="wei-card"><span>ready_at_publish_start</span><strong><?php echo esc_html((string) $initialPublishTotalReady); ?></strong></div>
            <div class="wei-card"><span>blocked_this_publish_run</span><strong><?php echo esc_html((string) $initialPublishBlockedCount); ?></strong></div>
        </div>
        <h3>Latest eBay state refresh</h3>
        <div class="wei-grid">
            <div class="wei-card"><span>current_active_listing_count</span><strong><?php echo esc_html((string) ($ebayListingStateSummary['current_active_listing_count'] ?? 0)); ?></strong></div>
            <div class="wei-card"><span>needs_reexport_count</span><strong><?php echo esc_html((string) ($ebayListingStateSummary['needs_reexport_count'] ?? 0)); ?></strong></div>
        </div>
        <div class="wei-actions" data-wei-primary-actions="publish">
            <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                <?php wp_nonce_field('wei_full_publish_readiness_audit'); ?>
                <input type="hidden" name="action" value="wei_full_publish_readiness_audit" />
                <label>Batch size <input type="number" min="1" max="500" name="batch_size" value="200" /></label>
                <button class="button">Run readiness scan / full publish readiness audit (new checkpointed run)</button>
            </form>
            <?php if (!$publishAuditComplete && $publishAuditRunId !== ''): ?>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>">
                    <?php wp_nonce_field('wei_full_publish_readiness_audit'); ?>
                    <input type="hidden" name="action" value="wei_full_publish_readiness_audit" />
                    <input type="hidden" name="continue_audit" value="1" />
                    <label>Batch size <input type="number" min="1" max="500" name="batch_size" value="<?php echo esc_attr((string) $publishAuditBatchSize); ?>" /></label>
                    <button class="button button-primary">Continue readiness audit</button>
                </form>
            <?php endif; ?>
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
        <div class="wei-actions">
            <?php foreach (['full_csv' => 'publish-readiness-full.csv', 'problems_only_csv' => 'publish-readiness-problems-only.csv', 'ready_products_csv' => 'publish-readiness-ready-products.csv', 'excluded_csv' => 'publish-readiness-excluded.csv'] as $reportKey => $label): ?>
                <?php $report = is_array($publishReadinessReports[$reportKey] ?? null) ? $publishReadinessReports[$reportKey] : []; ?>
                <?php if (!empty($report['exists']) && !empty($report['path'])): ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=download_wei_report&file=' . rawurlencode(basename((string) $report['path'])))); ?>"><?php echo esc_html($label); ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <details><summary>Readiness scan, publish run, and eBay state refresh technical summaries</summary><pre class="wei-scroll"><?php echo esc_html($technicalPreview(['latest_readiness_scan' => $readinessSummary, 'latest_publish_run' => ['processed_this_run' => (int) ($initialPublish['processed'] ?? 0), 'exported_this_run' => (int) $initialPublishSuccess, 'published_this_run' => (int) $initialPublishSuccess, 'skipped_this_run' => (int) $initialPublishSkippedThisRun, 'errors_this_run' => (int) $initialPublishFailed, 'historical_published_count' => (int) ($publishSummary['historical_published_count'] ?? 0), 'current_offer_count' => (int) ($publishSummary['current_offer_count'] ?? $ebayListingStateSummary['current_offer_count'] ?? 0), 'publish_progress_published_this_run' => (int) ($publishSummary['publish_progress_published_this_run'] ?? $initialPublishSuccess), 'published_total_from_old_checkpoint' => (int) ($publishSummary['published_total_from_old_checkpoint'] ?? 0), 'ended_listing_count' => (int) ($publishSummary['ended_listing_count'] ?? $ebayListingStateSummary['ended_listing_count'] ?? 0), 'last_batch_log' => $initialPublishLog], 'latest_ebay_state_refresh' => $ebayListingStateSummary], 8000)); ?></pre></details>
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
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_vehicle_compatibility_diagnostics'); ?><input type="hidden" name="action" value="wei_vehicle_compatibility_diagnostics" /><input type="number" name="product_id" placeholder="Woo product ID" required /><button class="button">Run vehicle compatibility diagnostics</button></form>
                <form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_run_vehicle_compatibility_audit'); ?><input type="hidden" name="action" value="wei_run_vehicle_compatibility_audit" /><label>Limit <input type="number" min="1" max="5000" name="limit" value="500" /></label><button class="button">Run vehicle compatibility readiness audit</button></form>
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
        <?php if ($vehicleCompatibilityDiagnostics !== []): ?>
            <div class="wei-card"><h3>Vehicle compatibility diagnostics</h3>
                <p class="description">Single-product EBAY_DE read-only preview. Missing KType/ePID is displayed as <code>compatibility_enhancement_missing</code> and is not a publish blocker when MPN/OE item specifics and other readiness checks pass. Status values include <code>ready_ktype</code>, <code>ready_epid</code>, <code>compatibility_enhancement_missing</code>, <code>unsupported_category</code> and <code>needs_manual_review</code>.</p>
                <pre class="wei-scroll"><?php echo esc_html($technicalPreview($vehicleCompatibilityDiagnostics, 10000)); ?></pre>
            </div>
        <?php endif; ?>
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
                    'inventory_location_city', 'inventory_location_address_line_1',
                ] as $preserveKey): ?>
                    <input type="hidden" name="<?php echo esc_attr($preserveKey); ?>" value="<?php echo esc_attr((string) $setting($preserveKey)); ?>" />
                <?php endforeach; ?>
                <?php foreach (['verbose_debug', 'woo_to_ebay_stock_sync_enabled', 'ebay_order_sync_enabled', 'auto_generate_german_content_preflight', 'enable_ebay_de_description_template', 'regenerate_german_content_on_hash_change'] as $preserveFlag): ?>
                    <?php if (!empty($s[$preserveFlag])): ?><input type="hidden" name="<?php echo esc_attr($preserveFlag); ?>" value="1" /><?php endif; ?>
                <?php endforeach; ?>
                <table class="form-table" role="presentation">
                    <tr><th><label for="wei-marketplace-id">Marketplace</label></th><td><input id="wei-marketplace-id" class="regular-text" name="marketplace_id" value="<?php echo esc_attr((string) $setting('marketplace_id', 'EBAY_DE')); ?>" placeholder="EBAY_DE" /></td></tr>
                    <tr><th><label for="wei-location-key">Merchant location key</label></th><td><input id="wei-location-key" class="regular-text" name="inventory_location_key" value="<?php echo esc_attr((string) $setting('inventory_location_key', 'gpswiss-pl')); ?>" /></td></tr>
                    <tr><th colspan="2"><h3>eBay shipping category mapping</h3><p class="description">Paste Woo <code>product_cat</code> IDs, not eBay category IDs. Select existing eBay fulfillment policies only; this plugin does not create shipping policies. Conflicts are allowed but warned; runtime priority is Wysyłka 130 &gt; Wysyłka 50 &gt; Wysyłka 30.</p></th></tr>
                    <tr><th><label for="wei-shipping-policy-30">Wysyłka 30 policy ID/name</label></th><td><?php $renderFulfillmentPolicyControl('shipping_policy_30', $fulfillmentId30, $fulfillmentId30, 'Select exact Wysyłka 30 fulfillment policy'); ?> <input class="regular-text" name="shipping_policy_name_30" value="<?php echo esc_attr((string) ($s['shipping_policy_name_30'] ?? '')); ?>" placeholder="optional policy name" /><p class="description">Choose the exact existing eBay fulfillment policy; duplicate names are disambiguated by policy ID.</p></td></tr>
                    <tr><th><label for="wei-shipping-policy-50">Wysyłka 50 policy ID/name</label></th><td><?php $renderFulfillmentPolicyControl('shipping_policy_50', $fulfillmentId50, $fulfillmentId50, 'Select exact Wysyłka 50 fulfillment policy'); ?> <input class="regular-text" name="shipping_policy_name_50" value="<?php echo esc_attr((string) ($s['shipping_policy_name_50'] ?? '')); ?>" placeholder="optional policy name" /></td></tr>
                    <tr><th><label for="wei-shipping-policy-130">Wysyłka 130 policy ID/name</label></th><td><?php $renderFulfillmentPolicyControl('shipping_policy_130', $fulfillmentId130, $fulfillmentId130, 'Select exact Wysyłka 130 fulfillment policy'); ?> <input class="regular-text" name="shipping_policy_name_130" value="<?php echo esc_attr((string) ($s['shipping_policy_name_130'] ?? '')); ?>" placeholder="optional policy name" /></td></tr>
                    <tr><th><label for="wei-default-shipping-policy">Default shipping policy ID/name</label></th><td><?php $renderFulfillmentPolicyControl('default_shipping_policy_id', $defaultShippingPolicyId, $defaultShippingPolicyId, 'Select optional default fulfillment policy'); ?> <input class="regular-text" name="default_shipping_policy_name" value="<?php echo esc_attr((string) ($s['default_shipping_policy_name'] ?? '')); ?>" placeholder="optional policy name" /><p class="description">Used only when no Woo category ID matches any shipping group.</p></td></tr>
                    <tr><th><label for="wei-shipping-category-ids-30">Wysyłka 30</label></th><td><textarea id="wei-shipping-category-ids-30" class="large-text code" rows="2" name="shipping_category_ids_30" placeholder="5063,5066,5069,5122"><?php echo esc_textarea((string) ($s['shipping_category_ids_30'] ?? '')); ?></textarea></td></tr>
                    <tr><th><label for="wei-shipping-category-ids-50">Wysyłka 50</label></th><td><textarea id="wei-shipping-category-ids-50" class="large-text code" rows="2" name="shipping_category_ids_50"><?php echo esc_textarea((string) ($s['shipping_category_ids_50'] ?? '')); ?></textarea></td></tr>
                    <tr><th><label for="wei-shipping-category-ids-130">Wysyłka 130</label></th><td><textarea id="wei-shipping-category-ids-130" class="large-text code" rows="2" name="shipping_category_ids_130"><?php echo esc_textarea((string) ($s['shipping_category_ids_130'] ?? '')); ?></textarea></td></tr>
                    <tr><th><label for="wei-payment-policy">Payment policy ID</label></th><td><input id="wei-payment-policy" class="regular-text" name="ebay_payment_policy_id" value="<?php echo esc_attr($paymentId); ?>" /></td></tr>
                    <tr><th><label for="wei-return-policy">Return policy ID</label></th><td><input id="wei-return-policy" class="regular-text" name="ebay_return_policy_id" value="<?php echo esc_attr($returnId); ?>" /></td></tr>
                    <tr><th><label for="wei-markup">Default markup %</label></th><td><input id="wei-markup" type="number" step="0.01" name="ebay_default_markup_percent" value="<?php echo esc_attr((string) $setting('ebay_default_markup_percent', 25)); ?>" /></td></tr>
                    <tr><th><label for="wei-ebay-seller-username">eBay seller username/store slug</label></th><td><input id="wei-ebay-seller-username" class="regular-text" name="ebay_seller_username" value="<?php echo esc_attr((string) $setting('ebay_seller_username', \WEI\Plugin::DEFAULT_EBAY_SELLER_USERNAME)); ?>" placeholder="<?php echo esc_attr(\WEI\Plugin::DEFAULT_EBAY_SELLER_USERNAME); ?>" /> <span class="description">Primary source for the eBay.de same-vehicle CTA seller search, for example <code>https://www.ebay.de/sch/i.html?_ssn=gpswiss&amp;_nkw=456</code>.</span></td></tr>
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
            <div class="wei-actions"><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_shipping_mapping_report'); ?><input type="hidden" name="action" value="wei_generate_shipping_mapping_report" /><button class="button">Generate shipping mapping report</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_shipping_mapping_diagnostics'); ?><input type="hidden" name="action" value="wei_shipping_mapping_diagnostics" /><input type="number" min="1" name="product_id" placeholder="Product ID" required /><button class="button">Preview shipping mapping</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_update_shipping_policy_one'); ?><input type="hidden" name="action" value="wei_update_shipping_policy_one" /><input type="number" min="1" name="product_id" placeholder="Product ID" /><input name="sku" placeholder="or SKU" /><button class="button">Update shipping policy for one product</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_process_queue_now'); ?><input type="hidden" name="action" value="wei_ebay_process_queue_now" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Process eBay queue now</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_ebay_rebuild_ready_queue'); ?><input type="hidden" name="action" value="wei_ebay_rebuild_ready_queue" /><input type="number" min="1" max="100" name="batch_size" value="50" /><button class="button">Rebuild ready queue</button></form><form method="post" action="<?php echo esc_url($adminPostUrl); ?>"><?php wp_nonce_field('wei_generate_ebay_skus'); ?><input type="hidden" name="action" value="wei_generate_ebay_skus" /><input type="number" name="batch_size" value="200" min="1" max="1000" /><button class="button">Generate missing eBay SKUs</button></form></div>
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
