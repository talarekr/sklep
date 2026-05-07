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
$paymentId = (string) ($s['ebay_payment_policy_id'] ?? '');
$returnId = (string) ($s['ebay_return_policy_id'] ?? '');
$accountSetupConfigured = $locationKey !== '' && $fulfillmentId !== '' && $paymentId !== '' && $returnId !== '';

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
$filterUrl = static fn (string $filter): string => add_query_arg(['page' => 'woo-ebay', 'category_status' => $filter], admin_url('admin.php'));
$sortUrl = static fn (string $sort): string => add_query_arg(['page' => 'woo-ebay', 'category_status' => $currentFilter, 'category_sort' => $sort], admin_url('admin.php'));

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
$autoStatus = (string) ($autoSync['status'] ?? 'disabled');
$autoModeLabels = [
    'disabled' => 'Disabled',
    'preflight_only' => 'Preflight only / readiness only',
    'export_ready_products' => 'Export ready products',
    'orders_stock_only' => 'Orders & stock sync only',
    'full_sync' => 'Full sync',
];
$frequencyLabels = ['every_15_minutes' => 'every 15 minutes', 'hourly' => 'hourly', 'daily' => 'daily'];
?>
<div class="wrap wei-admin">
    <h1>eBay Integration</h1>
    <style>
        .wei-admin .postbox { max-width: 1200px; margin-top: 16px; padding: 0 16px 16px; }
        .wei-admin .postbox > h2, .wei-admin .postbox > h3 { padding-top: 12px; }
        .wei-admin details.postbox summary { cursor: pointer; font-weight: 600; font-size: 14px; padding: 14px 0; }
        .wei-admin .wei-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; max-width: 1100px; }
        .wei-admin .wei-metric { border: 1px solid #dcdcde; background: #fff; padding: 10px 12px; }
        .wei-admin .wei-metric strong { display: block; font-size: 20px; line-height: 1.4; }
        .wei-admin .wei-ok { color: #008a20; font-weight: 600; }
        .wei-admin .wei-error { color: #b32d2e; font-weight: 600; }
        .wei-admin .wei-warn { color: #996800; font-weight: 600; }
        .wei-admin .wei-actions form { display: inline-block; margin: 0 8px 8px 0; }
        .wei-admin .wei-technical pre { max-height: 260px; overflow: auto; white-space: pre-wrap; background: #f6f7f7; padding: 10px; border: 1px solid #dcdcde; }
    </style>

    <div class="postbox">
        <h2>eBay Auto Sync</h2>
        <p class="description">Operational scheduler for eBay readiness, safe export preparation, order import, and Woo → eBay stock updates. WooCommerce remains the central source of stock; Allegro is not touched.</p>
        <div class="wei-grid">
            <div class="wei-metric"><span>Auto sync status</span><strong><?php echo esc_html(ucwords(str_replace('_', ' ', $autoStatus))); ?></strong></div>
            <div class="wei-metric"><span>Mode</span><strong><?php echo esc_html($autoModeLabels[(string) ($autoSync['mode'] ?? 'disabled')] ?? (string) ($autoSync['mode'] ?? 'disabled')); ?></strong></div>
            <div class="wei-metric"><span>Frequency</span><strong><?php echo esc_html($frequencyLabels[(string) ($autoSync['frequency'] ?? 'hourly')] ?? 'hourly'); ?></strong></div>
            <div class="wei-metric"><span>Batch size</span><strong><?php echo esc_html((string) ($autoSync['batch_size'] ?? 20)); ?></strong></div>
            <div class="wei-metric"><span>Last run</span><strong><?php echo esc_html((string) ($autoSync['last_run'] ?: '-')); ?></strong></div>
            <div class="wei-metric"><span>Next scheduled run</span><strong><?php echo esc_html((string) ($autoSync['next_run'] ?? '-')); ?></strong></div>
            <div class="wei-metric"><span>Pending stock sync items</span><strong><?php echo esc_html((string) ($autoSync['pending_stock_sync'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Woo → eBay stock sync</span><strong><?php echo !empty($autoSync['woo_to_ebay_stock_sync_enabled']) ? '<span class="wei-ok">enabled</span>' : '<span class="wei-warn">disabled</span>'; ?></strong></div>
            <div class="wei-metric"><span>eBay stock sync mode</span><strong><?php echo esc_html((string) ($autoSync['ebay_stock_sync_mode'] ?? 'max_one')); ?></strong></div>
            <div class="wei-metric"><span>eBay → Woo order sync</span><strong><?php echo !empty($autoSync['ebay_order_sync_enabled']) ? '<span class="wei-ok">enabled</span>' : '<span class="wei-warn">disabled</span>'; ?></strong></div>
            <div class="wei-metric"><span>Account restriction status</span><strong><?php echo esc_html((string) ($autoSync['account_restriction_status'] ?: '-')); ?></strong></div>
        </div>
        <table class="widefat striped" style="max-width:900px;margin-top:12px;">
            <tbody>
                <tr><th scope="row">Last result summary</th><td><?php echo esc_html($autoLastSummary !== [] ? wp_json_encode($autoLastSummary) : 'No scheduler run yet'); ?></td></tr>
            </tbody>
        </table>
        <div class="wei-actions" style="margin-top:12px;">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_auto_sync_readiness_now'); ?><input type="hidden" name="action" value="wei_auto_sync_readiness_now" /><button class="button">Run readiness scan now</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_full_category_audit'); ?><input type="hidden" name="action" value="wei_full_category_audit" /><label><input type="checkbox" name="verbose_debug" value="1" /> verbose debug JSON</label> <button class="button button-secondary">Run / continue full category audit</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_auto_sync_orders_now'); ?><input type="hidden" name="action" value="wei_auto_sync_orders_now" /><button class="button">Run order sync now</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_auto_sync_stock_now'); ?><input type="hidden" name="action" value="wei_auto_sync_stock_now" /><button class="button">Run stock sync now</button></form>
            <?php if (!empty($s['auto_export_enabled'])): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_auto_sync_export_now'); ?><input type="hidden" name="action" value="wei_auto_sync_export_now" /><button class="button">Run export batch now</button></form><?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_auto_sync_toggle_pause'); ?><input type="hidden" name="action" value="wei_auto_sync_toggle_pause" /><button class="button"><?php echo !empty($s['auto_sync_paused']) ? 'Resume auto sync' : 'Pause auto sync'; ?></button></form>
        </div>
    </div>

    <div class="postbox">
        <h2>Readiness Summary</h2>
        <div class="wei-grid">
            <div class="wei-metric"><span>Ready products</span><strong><?php echo esc_html((string) ($readinessSummary['ready'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Not ready products</span><strong><?php echo esc_html((string) ($readinessSummary['not_ready'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Blocked by category</span><strong><?php echo esc_html((string) ($readinessSummary['blocked_by_category'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Missing German content</span><strong><?php echo esc_html((string) ($readinessSummary['missing_german_content'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Missing required aspects</span><strong><?php echo esc_html((string) ($readinessSummary['missing_required_aspects'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Invalid price</span><strong><?php echo esc_html((string) ($readinessSummary['invalid_price'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Missing image</span><strong><?php echo esc_html((string) ($readinessSummary['missing_image'] ?? 0)); ?></strong></div>
        </div>

        <?php if (!empty($full_category_audit_summary)): ?>
            <?php $auditReports = is_array($full_category_audit_summary['reports'] ?? null) ? $full_category_audit_summary['reports'] : []; ?>
            <details style="margin-top:12px;" open>
                <summary>Latest full eBay category audit reports</summary>
                <div class="wei-grid" style="margin-top:8px;">
                    <div class="wei-metric"><span>Progress</span><strong><?php echo esc_html((string) ($full_category_audit_summary['processed'] ?? $full_category_audit_summary['total_scanned'] ?? 0) . ' / ' . (string) ($full_category_audit_summary['total_products'] ?? '?')); ?></strong></div>
                    <div class="wei-metric"><span>Status</span><strong><?php echo esc_html((string) ($full_category_audit_summary['status'] ?? $full_category_audit_summary['result'] ?? '-')); ?></strong></div>
                    <div class="wei-metric"><span>Last batch</span><strong><?php echo esc_html((string) ($full_category_audit_summary['processed_this_batch'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Ready</span><strong><?php echo esc_html((string) ($full_category_audit_summary['ready_count'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Blocked by category</span><strong><?php echo esc_html((string) ($full_category_audit_summary['blocked_by_category_count'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Missing category</span><strong><?php echo esc_html((string) ($full_category_audit_summary['missing_category_count'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Missing aspects</span><strong><?php echo esc_html((string) ($full_category_audit_summary['missing_required_aspects_count'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Content not ready</span><strong><?php echo esc_html((string) ($full_category_audit_summary['content_not_ready_count'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Price not ready</span><strong><?php echo esc_html((string) ($full_category_audit_summary['price_not_ready_count'] ?? 0)); ?></strong></div>
                </div>
                <ul>
                    <?php foreach ($auditReports as $label => $report): ?>
                        <?php if (is_array($report) && !empty($report['url'])): ?>
                            <li><a href="<?php echo esc_url((string) $report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(str_replace('_', ' ', (string) $label)); ?></a> <?php echo isset($report['rows']) ? esc_html('(' . (string) $report['rows'] . ' rows)') : ''; ?></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
                <p class="description">Audit scans published WooCommerce products in safe dry-run batches, resumes an in-progress run on the next click, and writes final CSV/JSON files only after the last batch. It does not generate German content, write translated content/meta, export, publish, change Woo SKU, change Woo price, or touch Allegro.</p>
            </details>
        <?php endif; ?>

        <details style="margin-top:12px;" <?php echo !empty($german_content_audit_summary) ? 'open' : ''; ?>>
            <summary>Audit-driven German content generation</summary>
            <p class="description">Uses the latest problems CSV as the source of truth and processes only rows where <code>status=content_not_ready</code> and <code>reason=missing_german_content</code>. It writes only <code>_wei_ebay_de_title</code>, <code>_wei_ebay_de_description</code>, and related WEI German content meta; it does not call eBay APIs, publish, create/update offers, change Woo SKU/price, or touch Allegro.</p>
            <?php if (!empty($german_content_audit_summary)): ?>
                <?php $germanReports = is_array($german_content_audit_summary['reports'] ?? null) ? $german_content_audit_summary['reports'] : []; ?>
                <div class="wei-grid" style="margin-top:8px;">
                    <div class="wei-metric"><span>Status</span><strong><?php echo esc_html((string) ($german_content_audit_summary['status'] ?? '-')); ?></strong></div>
                    <div class="wei-metric"><span>Progress</span><strong><?php echo esc_html((string) ($german_content_audit_summary['processed'] ?? 0) . ' / ' . (string) ($german_content_audit_summary['eligible_total'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Generated</span><strong><?php echo esc_html((string) ($german_content_audit_summary['generated'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Already ready</span><strong><?php echo esc_html((string) ($german_content_audit_summary['already_ready'] ?? 0)); ?></strong></div>
                    <div class="wei-metric"><span>Failed</span><strong><?php echo esc_html((string) ($german_content_audit_summary['failed'] ?? 0)); ?></strong></div>
                </div>
                <ul>
                    <?php foreach ($germanReports as $label => $report): ?>
                        <?php if (is_array($report) && !empty($report['url'])): ?><li><a href="<?php echo esc_url((string) $report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(str_replace('_', ' ', (string) $label)); ?></a></li><?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                <?php wp_nonce_field('wei_generate_missing_german_content_audit'); ?>
                <input type="hidden" name="action" value="wei_generate_missing_german_content_audit" />
                <label>Batch size <input type="number" name="batch_size" value="50" min="1" max="200" /></label>
                <label><input type="checkbox" name="restart" value="1" /> restart from first eligible audit row</label>
                <button class="button button-primary">Generate missing German content only</button>
            </form>
        </details>
        <details class="wei-not-ready-products" style="margin-top:12px;" <?php echo !empty($notReadyItems) ? 'open' : ''; ?>>
            <summary>Not ready products</summary>
            <p class="description">Shows up to 50 products from the latest readiness scan, with each bucket capped to keep the admin page and logs compact.</p>
            <p class="subsubsub">
                <?php foreach ($readinessFilterLabels as $filterKey => $filterLabel): ?>
                    <?php $filterUrl = add_query_arg(['page' => 'woo-ebay', 'readiness_filter' => $filterKey], admin_url('admin.php')); ?>
                    <a href="<?php echo esc_url($filterUrl); ?>" class="<?php echo $readinessFilter === $filterKey ? 'current' : ''; ?>"><?php echo esc_html($filterLabel); ?></a><?php echo $filterKey !== array_key_last($readinessFilterLabels) ? ' | ' : ''; ?>
                <?php endforeach; ?>
            </p>
            <table class="widefat striped" style="clear:both;">
                <thead><tr><th>Product ID</th><th>Product title</th><th>Reason</th><th>Mapping diagnostics</th><th>Missing aspects</th><th>Category</th><th>eBay category</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($notReadyItems as $item): ?>
                    <?php
                    $itemProductId = (int) ($item['product_id'] ?? 0);
                    $categoryStatus = (string) ($item['category_status'] ?? '');
                    $categoryReason = (string) ($item['category_sanity_reason'] ?? '');
                    $categoryLabel = trim($categoryStatus . ($categoryReason !== '' ? ': ' . $categoryReason : ''));
                    $ebayCategory = trim((string) ($item['category_id'] ?? '') . ' ' . (string) ($item['category_name'] ?? '') . ' ' . (string) ($item['category_path'] ?? ''));
                    $missingAspects = implode(', ', array_map('strval', (array) ($item['missing_aspects'] ?? [])));
                    $bestCandidate = trim((string) ($item['best_candidate_category_id'] ?? '') . ' ' . (string) ($item['best_candidate_name'] ?? '') . ' ' . (string) ($item['best_candidate_path'] ?? ''));
                    $mappingDiagnosticParts = [];
                    if ((string) ($item['detected_intent'] ?? '') !== '') {
                        $mappingDiagnosticParts[] = 'Intent: ' . (string) $item['detected_intent'];
                    } else {
                        if ((string) ($item['why_no_intent_match'] ?? '') !== '') {
                            $mappingDiagnosticParts[] = 'No intent: ' . (string) $item['why_no_intent_match'];
                        }
                        if ((string) ($item['intent_source_text_used'] ?? '') !== '') {
                            $mappingDiagnosticParts[] = 'Intent text: ' . (string) $item['intent_source_text_used'];
                        }
                    }
                    if ($bestCandidate !== '') {
                        $mappingDiagnosticParts[] = 'Best: ' . $bestCandidate;
                    }
                    if ((string) ($item['rejected_best_reason'] ?? '') !== '') {
                        $mappingDiagnosticParts[] = 'Reason: ' . (string) $item['rejected_best_reason'];
                    } elseif ((string) ($item['mapping_error_reason'] ?? '') !== '') {
                        $mappingDiagnosticParts[] = 'Reason: ' . (string) $item['mapping_error_reason'];
                    }
                    $mappingDiagnostics = implode(' | ', $mappingDiagnosticParts);
                    $preflightUrl = add_query_arg(['action' => 'wei_preflight_product', 'product_id' => $itemProductId, '_wpnonce' => wp_create_nonce('wei_preflight')], admin_url('admin-post.php'));
                    $categoryReviewUrl = add_query_arg(['page' => 'woo-ebay', 'category_status' => 'needs_review'], admin_url('admin.php')) . '#category-mapping-summary';
                    ?>
                    <tr>
                        <td><code><?php echo esc_html((string) $itemProductId); ?></code></td>
                        <td><?php echo esc_html((string) ($item['product_title'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($item['primary_reason'] ?? '')); ?></td>
                        <td><?php echo esc_html($mappingDiagnostics !== '' ? $mappingDiagnostics : '-'); ?></td>
                        <td><?php echo esc_html($missingAspects !== '' ? $missingAspects : '-'); ?></td>
                        <td><?php echo esc_html($categoryLabel !== '' ? $categoryLabel : '-'); ?></td>
                        <td><?php echo esc_html($ebayCategory !== '' ? $ebayCategory : '-'); ?></td>
                        <td><code><?php echo esc_html((string) ($item['status'] ?? 'not_ready')); ?></code></td>
                        <td>
                            <?php if ((string) ($item['edit_url'] ?? '') !== ''): ?><a href="<?php echo esc_url((string) $item['edit_url']); ?>">Edit product</a><?php endif; ?>
                            <?php if ($itemProductId > 0): ?> | <a href="<?php echo esc_url($preflightUrl); ?>">Preflight only</a><?php endif; ?>
                            <?php if (str_contains((string) ($item['primary_reason'] ?? ''), 'blocked_by_category')): ?> | <a href="<?php echo esc_url($categoryReviewUrl); ?>">Open category mapping review</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($notReadyItems === []): ?><tr><td colspan="9">No not-ready products in this filter for the latest readiness scan.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </details>
        <details class="wei-technical" style="margin-top:12px;">
            <summary>Technical details</summary>
            <pre><?php echo esc_html($redactTechnical(wp_json_encode($readinessSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '')); ?></pre>
        </details>
    </div>

    <div class="postbox">
        <h2>Export Summary</h2>
        <div class="wei-grid">
            <div class="wei-metric"><span>Exported inventory</span><strong><?php echo esc_html((string) ($exportSummary['exported'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Published</span><strong><?php echo esc_html((string) ($exportSummary['published'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Account blocked</span><strong><?php echo esc_html($autoStatus === 'blocked_by_ebay_account_restriction' ? 'yes' : 'no'); ?></strong></div>
        </div>
    </div>

    <div class="postbox">
        <h2>Order / Stock Sync Summary</h2>
        <div class="wei-grid">
            <div class="wei-metric"><span>Orders imported</span><strong><?php echo esc_html((string) ($autoLastSummary['orders_imported'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Woo stock updates from eBay orders</span><strong><?php echo esc_html((string) ($autoLastSummary['woo_stock_updates'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Pending Woo → eBay stock sync</span><strong><?php echo esc_html((string) ($autoSync['pending_stock_sync'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>eBay stock updates done</span><strong><?php echo esc_html((string) ($stockSummary['updated'] ?? $autoLastSummary['ebay_stock_updates'] ?? 0)); ?></strong></div>
            <div class="wei-metric"><span>Stock sync errors</span><strong><?php echo esc_html((string) ($stockSummary['errors'] ?? 0)); ?></strong></div>
        </div>
    </div>

    <div class="postbox">
        <h2>Connection &amp; readiness summary</h2>
        <div class="wei-grid">
            <div class="wei-metric"><span>Environment</span><strong><?php echo esc_html((string) ($s['environment'] ?? 'production')); ?></strong></div>
            <div class="wei-metric"><span>Marketplace</span><strong><?php echo esc_html((string) ($s['marketplace_id'] ?? 'EBAY_DE')); ?></strong></div>
            <div class="wei-metric"><span>Connection</span><strong><?php echo !empty($s['refresh_token']) ? '<span class="wei-ok">connected</span>' : '<span class="wei-warn">not connected</span>'; ?></strong></div>
            <div class="wei-metric"><span>Token expires at</span><strong><?php echo esc_html((string) ($s['expires_at'] ?? '-')); ?></strong></div>
        </div>
        <div class="wei-actions" style="margin-top:12px;">
            <a class="button button-primary" href="<?php echo esc_url($connect_url); ?>">Connect eBay</a>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_disconnect'); ?><input type="hidden" name="action" value="wei_disconnect" /><button class="button">Disconnect eBay</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_test'); ?><input type="hidden" name="action" value="wei_test_connection" /><button class="button">Test connection</button></form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_readiness'); ?><input type="hidden" name="action" value="wei_readiness" /><button class="button">Run readiness check</button></form>
        </div>
        <table class="widefat striped" style="max-width:900px;margin-top:12px;">
            <tbody>
                <tr><th scope="row">Last action</th><td><?php echo esc_html($lastAction); ?> <span class="<?php echo $lastStatusIsError ? 'wei-error' : 'wei-ok'; ?>"><?php echo esc_html($lastStatusIsError ? 'error' : 'ready'); ?></span></td></tr>
                <?php if ($lastProductId !== ''): ?><tr><th scope="row">Product ID</th><td><code><?php echo esc_html($lastProductId); ?></code></td></tr><?php endif; ?>
                <tr><th scope="row">Message</th><td><?php echo esc_html($lastShortMessage); ?></td></tr>
                <?php if ($lastStage !== ''): ?><tr><th scope="row">Stage</th><td><code><?php echo esc_html($lastStage); ?></code></td></tr><?php endif; ?>
            </tbody>
        </table>
        <details class="wei-technical" style="margin-top:12px;">
            <summary>Show technical details</summary>
            <pre><?php echo esc_html($redactTechnical(wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '')); ?></pre>
        </details>
    </div>

    <div class="postbox">
        <h2>eBay-only SKU preparation</h2>
        <p class="description">Generates plugin-owned <code>_wei_ebay_sku</code> values only. WooCommerce <code>_sku</code> is never written, Allegro data is untouched, and no eBay publish/export is started.</p>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr><th scope="row">Products total eligible</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_total_eligible'] ?? '0')); ?></code></td></tr>
                <tr><th scope="row">Products with _wei_ebay_sku</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_with_wei_ebay_sku'] ?? '0')); ?></code></td></tr>
                <tr><th scope="row">Products missing _wei_ebay_sku</th><td><code><?php echo esc_html((string) ($ebay_sku_status['products_missing_wei_ebay_sku'] ?? '0')); ?></code></td></tr>
                <tr><th scope="row">Generated in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['generated_in_last_run'] ?? '0')); ?></code></td></tr>
                <tr><th scope="row">Conflicts/errors in last run</th><td><code><?php echo esc_html((string) ($ebay_sku_status['conflicts_in_last_run'] ?? '0')); ?></code> conflicts, <code><?php echo esc_html((string) ($ebay_sku_status['errors_in_last_run'] ?? '0')); ?></code> errors</td></tr>
                <tr><th scope="row">Active run</th><td><code><?php echo esc_html((string) ($skuActiveRun['run_id'] ?? '-')); ?></code><?php if ($skuActiveRun): ?> processed <code><?php echo esc_html((string) ($skuActiveTotals['processed'] ?? '0')); ?></code>, remaining <code><?php echo esc_html((string) ($skuActiveRun['remaining_missing'] ?? '0')); ?></code><?php endif; ?></td></tr>
                <tr><th scope="row">Last run</th><td><code><?php echo esc_html((string) ($skuLastRun['run_id'] ?? '-')); ?></code><?php if ($skuLastRun): ?> processed <code><?php echo esc_html((string) ($skuLastTotals['processed'] ?? '0')); ?></code>, wrote_woo_sku <code>false</code><?php endif; ?></td></tr>
            </tbody>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em">
            <?php wp_nonce_field('wei_generate_ebay_skus'); ?>
            <input type="hidden" name="action" value="wei_generate_ebay_skus" />
            <?php if (!empty($skuActiveRun['run_id'])): ?><input type="hidden" name="run_id" value="<?php echo esc_attr((string) $skuActiveRun['run_id']); ?>" /><?php endif; ?>
            <label>Batch size <input type="number" name="batch_size" value="200" min="1" max="500" /></label>
            <button class="button button-primary"><?php echo !empty($skuActiveRun['run_id']) ? 'Continue eBay SKU generation' : 'Generate missing eBay SKUs'; ?></button>
        </form>
    </div>

    <div class="postbox">
        <h2>German content status</h2>
        <table class="widefat striped" style="max-width:700px;">
            <tbody>
                <tr><th scope="row">German translation</th><td><?php echo esc_html($translationLabel); ?></td></tr>
                <tr><th scope="row">Auto-generate</th><td><?php echo !empty($s['auto_generate_german_content_preflight']) ? '<span class="wei-ok">enabled</span>' : '<span class="wei-warn">disabled</span>'; ?></td></tr>
                <tr><th scope="row">Regenerate on source change</th><td><?php echo !empty($s['regenerate_german_content_on_hash_change']) ? 'enabled' : 'disabled'; ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="postbox">
        <h2>EBAY_DE price status</h2>
        <table class="widefat striped" style="max-width:700px;">
            <tbody>
                <tr><th scope="row">NBP EUR rate</th><td><code><?php echo esc_html(is_numeric($nbpRate) ? number_format((float) $nbpRate, 4, '.', '') : 'missing'); ?></code></td></tr>
                <tr><th scope="row">Effective date</th><td><?php echo esc_html($nbpEffectiveDate !== '' ? $nbpEffectiveDate : '-'); ?></td></tr>
                <tr><th scope="row">Cache</th><td><?php echo esc_html($nbpCacheStatus); ?><?php if ($nbpCacheAgeLabel !== '-'): ?>, age <?php echo esc_html($nbpCacheAgeLabel); ?><?php endif; ?></td></tr>
            </tbody>
        </table>
        <p class="description">eBay DE offer prices are resolved from WooCommerce PLN prices only for eBay payloads. WooCommerce regular/sale prices and Allegro data are not changed.</p>
    </div>

    <div class="postbox">
        <h2 id="category-mapping-summary">Category mapping summary</h2>
        <div class="wei-grid">
            <div class="wei-metric"><span>Total categories</span><strong><?php echo esc_html((string) $categorySummary['total']); ?></strong></div>
            <div class="wei-metric"><span>Mapped manual</span><strong><?php echo esc_html((string) $categorySummary['mapped_manual']); ?></strong></div>
            <div class="wei-metric"><span>Accepted auto</span><strong><?php echo esc_html((string) $categorySummary['accepted_auto']); ?></strong></div>
            <div class="wei-metric"><span>Needs category review</span><strong><?php echo esc_html((string) $categorySummary['needs_category_review']); ?></strong></div>
            <div class="wei-metric"><span>Blocked by Sonstige</span><strong><?php echo esc_html((string) $categorySummary['blocked_by_sonstige']); ?></strong></div>
            <div class="wei-metric"><span>Blocked by expected keyword</span><strong><?php echo esc_html((string) $categorySummary['blocked_by_expected_keyword']); ?></strong></div>
            <div class="wei-metric"><span>Blocked by threshold</span><strong><?php echo esc_html((string) $categorySummary['blocked_by_threshold']); ?></strong></div>
            <div class="wei-metric"><span>Last auto-map run</span><strong><?php echo esc_html((string) $categorySummary['last_auto_map_run']); ?></strong></div>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 12px 0 0;">
            <?php wp_nonce_field('wei_auto_map_categories'); ?>
            <input type="hidden" name="action" value="wei_auto_map_categories" />
            <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
            <button class="button button-primary">Re-evaluate category mappings</button>
            <span class="description">Re-evaluates auto mappings and maps unmapped categories; manual mappings are not overwritten. Runs in WP Admin only; it does not export or publish offers.</span>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 8px 0 0;">
            <?php wp_nonce_field('wei_repair_blocked_category_mappings'); ?>
            <input type="hidden" name="action" value="wei_repair_blocked_category_mappings" />
            <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
            <button class="button">Re-evaluate blocked category mappings only</button>
            <span class="description">Legacy sample-based repair. Prefer the audit group repair below now that the full CSV audit is available.</span>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 8px 0 0; border-left:4px solid #2271b1; padding-left:10px;">
            <?php wp_nonce_field('wei_repair_audit_category_groups'); ?>
            <input type="hidden" name="action" value="wei_repair_audit_category_groups" />
            <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
            <button class="button button-primary">Repair category issues from audit reason groups</button>
            <span class="description">Uses the latest problems CSV reason groups and applies imported manual teaching rules before retrying automatic candidates. Detailed diagnostics are written to CSV/JSON, not the main log.</span>
        </form>

        <div style="margin: 10px 0 0; border-left:4px solid #46b450; padding-left:10px;">
            <h3 style="margin:0 0 8px;">Category mapping teaching export/import</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 8px;">
                <?php wp_nonce_field('wei_export_category_teaching_csv'); ?>
                <input type="hidden" name="action" value="wei_export_category_teaching_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                <button class="button">Export teaching CSV from latest audit problems</button>
                <span class="description">Groups products by Woo category path, detected intent, sanity reason, bad category, and optional keyword family so you can fill one manual category per pattern.</span>
            </form>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 8px;">
                <?php wp_nonce_field('wei_import_category_teaching_csv'); ?>
                <input type="hidden" name="action" value="wei_import_category_teaching_csv" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                <input type="file" name="teaching_csv" accept=".csv,text/csv" required />
                <button class="button button-primary">Import filled teaching CSV</button>
                <span class="description">Rows with manual_ebay_category_id create manual_woo_category_mapping rules by Woo category path. Expected-keyword mismatches are warnings; only hard safety mismatches are rejected.</span>
            </form>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 0 0 8px; border-left:4px solid #46b450; padding-left:10px;">
                <?php wp_nonce_field('wei_apply_manual_woo_category_mappings'); ?>
                <input type="hidden" name="action" value="wei_apply_manual_woo_category_mappings" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                <button class="button button-primary">Apply manual Woo category mappings to all products</button>
                <span class="description">Loads active manual_woo_category_mapping rules by Woo category path and writes category mappings for matching published products/categories only. Does not call taxonomy suggestions, eBay APIs, publish, or modify SKU/price/Allegro data.</span>
            </form>
            <?php if (!empty($manual_woo_category_apply_summary)): ?>
                <?php
                $applyErrors = is_array($manual_woo_category_apply_summary['errors_sample'] ?? null) ? $manual_woo_category_apply_summary['errors_sample'] : [];
                $applyHardSafetyReasons = is_array($manual_woo_category_apply_summary['top_hard_safety_reasons'] ?? null) ? $manual_woo_category_apply_summary['top_hard_safety_reasons'] : [];
                $applySkippedRows = is_array($manual_woo_category_apply_summary['skipped_sample_rows'] ?? null) ? $manual_woo_category_apply_summary['skipped_sample_rows'] : [];
                ?>
                <details style="margin-top:8px;" open>
                    <summary>Last manual Woo category mapping apply summary</summary>
                    <div class="wei-grid" style="margin-top:8px;">
                        <div class="wei-metric"><span>manual_rules_loaded</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['manual_rules_loaded'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>woo_categories_matched</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['woo_categories_matched'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>products_scanned</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['products_scanned'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>products_matching_manual_categories</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['products_matching_manual_categories'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>mappings_written</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['mappings_written'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>already_mapped</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['already_mapped'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>skipped_by_hard_safety</span><strong><?php echo esc_html((string) ($manual_woo_category_apply_summary['skipped_by_hard_safety'] ?? 0)); ?></strong></div>
                    </div>
                    <?php if ($applyHardSafetyReasons !== []): ?>
                        <p><strong>top_hard_safety_reasons</strong></p>
                        <table class="widefat striped">
                            <thead><tr><th>reason</th><th>count</th></tr></thead>
                            <tbody>
                            <?php foreach ($applyHardSafetyReasons as $reason => $count): ?>
                                <tr><td><code><?php echo esc_html((string) $reason); ?></code></td><td><?php echo esc_html((string) $count); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($applySkippedRows !== []): ?>
                        <p><strong>skipped_sample_rows</strong></p>
                        <table class="widefat striped">
                            <thead><tr><th>reason</th><th>manual_ebay_category_id</th><th>manual_ebay_category_path</th><th>woo_category_path</th><th>product_count</th><th>sample_product_ids</th></tr></thead>
                            <tbody>
                            <?php foreach ($applySkippedRows as $row): ?>
                                <tr>
                                    <td><code><?php echo esc_html((string) ($row['reason'] ?? '')); ?></code></td>
                                    <td><code><?php echo esc_html((string) ($row['manual_ebay_category_id'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($row['manual_ebay_category_path'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['woo_category_path'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['product_count'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($row['sample_product_ids'] ?? '')); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($applyErrors !== []): ?>
                        <p class="description">errors_sample</p>
                        <ul>
                            <?php foreach ($applyErrors as $error): ?><li><?php echo esc_html((string) $error); ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
            <?php if (!empty($category_teaching_export_summary)): ?>
                <?php $teachingReports = is_array($category_teaching_export_summary['reports'] ?? null) ? $category_teaching_export_summary['reports'] : []; ?>
                <p class="description">Last teaching export: <?php echo esc_html((string) ($category_teaching_export_summary['groups_exported'] ?? 0)); ?> grouped rows.</p>
                <ul>
                    <?php foreach ($teachingReports as $label => $report): ?>
                        <?php if (is_array($report) && !empty($report['url'])): ?><li><a href="<?php echo esc_url((string) $report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(str_replace('_', ' ', (string) $label)); ?></a><?php echo isset($report['rows']) ? esc_html(' (' . (string) $report['rows'] . ' rows)') : ''; ?></li><?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (!empty($category_teaching_import_summary)): ?>
                <?php
                $importedRuleKeys = is_array($category_teaching_import_summary['imported_rule_keys'] ?? null) ? $category_teaching_import_summary['imported_rule_keys'] : [];
                $validationErrors = is_array($category_teaching_import_summary['validation_errors_sample'] ?? null) ? $category_teaching_import_summary['validation_errors_sample'] : [];
                $importHardSafetyReasons = is_array($category_teaching_import_summary['top_hard_safety_reasons'] ?? null) ? $category_teaching_import_summary['top_hard_safety_reasons'] : [];
                $importSkippedRows = is_array($category_teaching_import_summary['skipped_sample_rows'] ?? null) ? $category_teaching_import_summary['skipped_sample_rows'] : [];
                ?>
                <details style="margin-top:8px;" open>
                    <summary>Last teaching import summary</summary>
                    <div class="wei-grid" style="margin-top:8px;">
                        <div class="wei-metric"><span>rows_read</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rows_read'] ?? $category_teaching_import_summary['rows'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>rows_with_manual_category_id</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rows_with_manual_category_id'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>rules_inserted</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rules_inserted'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>rules_updated</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rules_updated'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>rows_skipped</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rows_skipped'] ?? $category_teaching_import_summary['skipped_rows'] ?? 0)); ?></strong></div>
                        <div class="wei-metric"><span>rows_rejected_by_safety</span><strong><?php echo esc_html((string) ($category_teaching_import_summary['rows_rejected_by_safety'] ?? $category_teaching_import_summary['safety_failed_rows'] ?? 0)); ?></strong></div>
                    </div>
                    <?php if ($importHardSafetyReasons !== []): ?>
                        <p><strong>top_hard_safety_reasons</strong></p>
                        <table class="widefat striped">
                            <thead><tr><th>reason</th><th>count</th></tr></thead>
                            <tbody>
                            <?php foreach ($importHardSafetyReasons as $reason => $count): ?>
                                <tr><td><code><?php echo esc_html((string) $reason); ?></code></td><td><?php echo esc_html((string) $count); ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($importSkippedRows !== []): ?>
                        <p><strong>skipped_sample_rows</strong></p>
                        <table class="widefat striped">
                            <thead><tr><th>row</th><th>reason</th><th>manual_ebay_category_id</th><th>manual_ebay_category_path</th><th>woo_category_path</th><th>marketplace_id</th></tr></thead>
                            <tbody>
                            <?php foreach ($importSkippedRows as $row): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($row['row'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($row['reason'] ?? '')); ?></code></td>
                                    <td><code><?php echo esc_html((string) ($row['manual_ebay_category_id'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($row['manual_ebay_category_path'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['woo_category_path'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($row['marketplace_id'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($validationErrors !== []): ?>
                        <p><strong>validation_errors_sample</strong></p>
                        <ul><?php foreach ($validationErrors as $validationError): ?><li><code><?php echo esc_html((string) $validationError); ?></code></li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($importedRuleKeys !== []): ?>
                        <p><strong>First 10 imported rule keys</strong></p>
                        <table class="widefat striped">
                            <thead><tr><th>marketplace_id</th><th>woo_category_path</th><th>woo_category_path_hash</th><th>detected_intent</th><th>title_keyword_family</th><th>manual_ebay_category_id</th></tr></thead>
                            <tbody>
                            <?php foreach ($importedRuleKeys as $ruleKey): ?>
                                <tr>
                                    <td><?php echo esc_html((string) ($ruleKey['marketplace_id'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($ruleKey['woo_category_path'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($ruleKey['woo_category_path_hash'] ?? '')); ?></code></td>
                                    <td><?php echo esc_html((string) ($ruleKey['detected_intent'] ?? '')); ?></td>
                                    <td><?php echo esc_html((string) ($ruleKey['title_keyword_family'] ?? '')); ?></td>
                                    <td><code><?php echo esc_html((string) ($ruleKey['manual_ebay_category_id'] ?? '')); ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </details>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin: 10px 0 0;">
                <?php wp_nonce_field('wei_test_category_teaching_rule_match'); ?>
                <input type="hidden" name="action" value="wei_test_category_teaching_rule_match" />
                <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                <label>Test teaching rule match product_id <input type="number" name="product_id" min="1" required /></label>
                <button class="button">Test teaching rule match</button>
            </form>
            <?php if (!empty($category_teaching_match_diagnostic)): ?>
                <details class="wei-technical" style="margin-top:8px;" open>
                    <summary>Teaching rule match diagnostic</summary>
                    <pre><?php echo esc_html(wp_json_encode($category_teaching_match_diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: ''); ?></pre>
                </details>
            <?php endif; ?>
        </div>

        <?php if (!empty($category_group_repair_summary)): ?>
            <?php $repairReports = is_array($category_group_repair_summary['reports'] ?? null) ? $category_group_repair_summary['reports'] : []; ?>
            <p class="description">Last audit group repair: processed <?php echo esc_html((string) ($category_group_repair_summary['processed'] ?? 0)); ?>, fixed <?php echo esc_html((string) ($category_group_repair_summary['fixed_count'] ?? 0)); ?>, still blocked <?php echo esc_html((string) ($category_group_repair_summary['still_blocked_count'] ?? 0)); ?>.</p>
            <ul>
                <?php foreach ($repairReports as $label => $report): ?>
                    <?php if (is_array($report) && !empty($report['url'])): ?><li><a href="<?php echo esc_url((string) $report['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(str_replace('_', ' ', (string) $label)); ?></a><?php echo isset($report['rows']) ? esc_html(' (' . (string) $report['rows'] . ' rows)') : ''; ?></li><?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h3>Top 10 risky mappings</h3>
        <table class="widefat striped">
            <thead><tr><th>Woo category path</th><th>eBay categoryId</th><th>eBay category path</th><th>Confidence</th><th>Reason</th><th>Product count</th><th>eBay DE</th></tr></thead>
            <tbody>
            <?php foreach ($riskyCategoryRows as $row): ?>
                <tr>
                    <td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td>
                    <td><code><?php echo esc_html((string) ($row['_ui_category_id'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($row['_ui_category_path'] ?? '')); ?></td>
                    <td><?php echo esc_html(isset($row['confidence']) ? number_format((float) $row['confidence'], 4) : ''); ?></td>
                    <td><?php echo esc_html((string) (($row['_ui_sanity_reason'] ?? '') !== '' ? $row['_ui_sanity_reason'] : (($row['error_reason'] ?? '') !== '' ? $row['error_reason'] : ($row['_ui_status'] ?? '')))); ?></td>
                    <td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td>
                    <td><?php if ((string) ($row['_ui_category_url'] ?? '') !== ''): ?><a href="<?php echo esc_url((string) $row['_ui_category_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('Open on eBay DE'); ?></a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($riskyCategoryRows === []): ?><tr><td colspan="7">No risky mappings found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="postbox">
        <h2>eBay account setup summary</h2>
        <table class="widefat striped" style="max-width:800px;">
            <tbody>
                <tr><th scope="row">Location</th><td><?php echo esc_html($locationKey !== '' ? $locationKey . ' OK' : 'missing'); ?></td></tr>
                <tr><th scope="row">Fulfillment policy</th><td><?php echo esc_html($fulfillmentId !== '' ? $findPolicyName($fulfillmentPolicies, $fulfillmentId, 'fulfillmentPolicyId') . ' OK' : 'missing'); ?></td></tr>
                <tr><th scope="row">Payment policy</th><td><?php echo esc_html($paymentId !== '' ? $findPolicyName($paymentPolicies, $paymentId, 'paymentPolicyId') . ' OK' : 'missing'); ?></td></tr>
                <tr><th scope="row">Return policy</th><td><?php echo esc_html($returnId !== '' ? $findPolicyName($returnPolicies, $returnId, 'returnPolicyId') . ' OK' : 'missing'); ?></td></tr>
            </tbody>
        </table>
        <p class="description">Edit these fields in <strong>Advanced: eBay account setup</strong> below.</p>
    </div>

    <div class="postbox">
        <h2>Preflight / export actions</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_export'); ?><input type="hidden" name="action" value="wei_export_product" />
            <p><input type="number" name="product_id" placeholder="Woo product ID" />
            <input type="text" name="ebay_category_id" placeholder="eBay category ID override (optional)" /></p>
            <details>
                <summary>Optional technical export inputs</summary>
                <p><textarea name="ebay_aspects_json" class="large-text code" rows="4" placeholder='{&quot;Hersteller&quot;:[&quot;SEAT&quot;]}'></textarea><br />
                <span class="description">Optional per-product eBay aspects JSON saved before export.</span></p>
            </details>
            <button class="button button-primary">Preflight + export product</button>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;"><?php wp_nonce_field('wei_preflight'); ?><input type="hidden" name="action" value="wei_preflight_product" />
            <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Preflight only</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px; border-left:4px solid #dba617; padding-left:10px;"><?php wp_nonce_field('wei_publish_product_offer_only'); ?><input type="hidden" name="action" value="wei_publish_product_offer_only" />
            <input type="number" name="product_id" placeholder="Woo product ID" value="43535" /> <button class="button button-secondary">Publish this eBay offer only</button>
            <span class="description">Safe manual test: runs preflight first, requires ready=true and an existing offer_id, then calls publishOffer only for that offer. Does not create/update inventory, offers, Woo SKU, Woo price or Allegro.</span></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px; border-left:4px solid #2271b1; padding-left:10px;"><?php wp_nonce_field('wei_verify_api_publishing_readiness'); ?><input type="hidden" name="action" value="wei_verify_api_publishing_readiness" />
            <p><input type="number" name="product_id" placeholder="Woo product ID" value="43535" /> <button class="button button-secondary">Verify eBay API publishing readiness</button></p>
            <p><label><input type="checkbox" name="write_diagnostic_offer" value="1" /> Also create/update the Inventory API item/offer in diagnostic mode (no publishOffer)</label></p>
            <span class="description">Safe single-product diagnostic for EBAY_DE Inventory API publishing: checks OAuth/app scopes, Account API privileges/programs, policies, merchant location, getInventoryItem/getOffer state, required publish fields, and createOffer/updateOffer warnings/errors. It never changes Woo SKU, Woo price, Allegro, global auto-publish, or calls publishOffer.</span></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;"><?php wp_nonce_field('wei_sync'); ?><input type="hidden" name="action" value="wei_sync_stock" />
            <input type="number" name="product_id" placeholder="Woo product ID" /> <button class="button">Sync stock</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;"><?php wp_nonce_field('wei_import_order'); ?><input type="hidden" name="action" value="wei_import_order" /><button class="button">Import one eBay order</button></form>
    </div>

    <div class="postbox">
        <h2>Recent logs/errors summary</h2>
        <ul>
            <?php foreach (array_slice((array) $logs, 0, 8) as $log): ?>
                <li><strong><?php echo esc_html((string) ($log['at'] ?? '')); ?></strong> <?php echo esc_html($redactTechnical(wp_strip_all_tags((string) ($log['message'] ?? '')))); ?></li>
            <?php endforeach; ?>
        </ul>
        <details class="wei-technical">
            <summary>Show technical log details</summary>
            <pre><?php echo esc_html($redactTechnical(wp_json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '')); ?></pre>
        </details>
    </div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('wei_save_settings'); ?>
        <input type="hidden" name="action" value="wei_save_settings" />

        <details class="postbox" open>
            <summary>Advanced scheduler settings</summary>
            <p>Mode: <select name="auto_sync_mode">
                <?php foreach ($autoModeLabels as $modeKey => $modeLabel): ?><option value="<?php echo esc_attr($modeKey); ?>" <?php selected(($s['auto_sync_mode'] ?? 'disabled'), $modeKey); ?>><?php echo esc_html($modeLabel); ?></option><?php endforeach; ?>
            </select></p>
            <p>Frequency: <select name="auto_sync_frequency">
                <?php foreach ($frequencyLabels as $frequencyKey => $frequencyLabel): ?><option value="<?php echo esc_attr($frequencyKey); ?>" <?php selected(($s['auto_sync_frequency'] ?? 'hourly'), $frequencyKey); ?>><?php echo esc_html($frequencyLabel); ?></option><?php endforeach; ?>
            </select></p>
            <p>Preflight batch size: <input type="number" min="1" max="300" name="auto_sync_preflight_batch_size" value="<?php echo esc_attr((string) ($s['auto_sync_preflight_batch_size'] ?? 200)); ?>" class="small-text" /></p>
            <p>Export batch size: <input type="number" min="1" max="50" name="auto_sync_export_batch_size" value="<?php echo esc_attr((string) ($s['auto_sync_export_batch_size'] ?? 20)); ?>" class="small-text" /></p>
            <p>Stock batch size: <input type="number" min="1" max="300" name="auto_sync_stock_batch_size" value="<?php echo esc_attr((string) ($s['auto_sync_stock_batch_size'] ?? 100)); ?>" class="small-text" /></p>
            <p><label><input type="checkbox" name="woo_to_ebay_stock_sync_enabled" value="1" <?php checked(!empty($s['woo_to_ebay_stock_sync_enabled'])); ?> /> Woo → eBay stock sync enabled</label></p>
            <p><label><input type="checkbox" name="ebay_order_sync_enabled" value="1" <?php checked(!empty($s['ebay_order_sync_enabled'])); ?> /> eBay → Woo order sync enabled</label></p>
            <p><label><input type="checkbox" name="auto_export_enabled" value="1" <?php checked(!empty($s['auto_export_enabled'])); ?> /> Export ready products automatically</label></p>
            <p><label><input type="checkbox" name="auto_publish_enabled" value="1" <?php checked(!empty($s['auto_publish_enabled'])); ?> /> Publish offers automatically (keep off while account restriction exists)</label></p>
            <p>eBay stock sync mode: <select name="ebay_stock_sync_mode"><option value="set_zero_only" <?php selected(($s['ebay_stock_sync_mode'] ?? 'max_one'), 'set_zero_only'); ?>>set_zero_only</option><option value="max_one" <?php selected(($s['ebay_stock_sync_mode'] ?? 'max_one'), 'max_one'); ?>>max_one</option><option value="exact_stock" <?php selected(($s['ebay_stock_sync_mode'] ?? 'max_one'), 'exact_stock'); ?>>exact_stock</option></select></p>
            <p>eBay order → Woo stock mode: <select name="ebay_order_stock_update_mode"><option value="set_zero" <?php selected(($s['ebay_order_stock_update_mode'] ?? 'set_zero'), 'set_zero'); ?>>Set to zero after eBay sale</option><option value="reduce" <?php selected(($s['ebay_order_stock_update_mode'] ?? 'set_zero'), 'reduce'); ?>>Reduce by sold quantity</option></select></p>
            <p class="description">Defaults are safe: auto sync disabled, auto export off, auto publish off. Stock mode <code>max_one</code> sends <code>availableQuantity=0</code> when Woo stock reaches zero.</p>
        </details>

        <details class="postbox">
            <summary>Advanced: API credentials</summary>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr><th scope="row">Environment</th><td><select name="environment"><option value="sandbox" <?php selected(($s['environment'] ?? ''), 'sandbox'); ?>>sandbox</option><option value="production" <?php selected(($s['environment'] ?? 'production'), 'production'); ?>>production</option></select></td></tr>
                    <tr><th scope="row">Client ID</th><td><input type="text" name="client_id" value="<?php echo esc_attr($s['client_id'] ?? ''); ?>" class="regular-text" /> <span class="description">Current: <?php echo esc_html($maskSecret((string) ($s['client_id'] ?? ''))); ?></span></td></tr>
                    <tr><th scope="row">Client Secret</th><td><input type="password" name="client_secret" value="" class="regular-text" autocomplete="off" placeholder="Leave blank to keep current" /> <span class="description">Current: <?php echo esc_html($maskSecret((string) ($s['client_secret'] ?? ''))); ?></span></td></tr>
                    <tr><th scope="row">eBay RuName</th><td><input type="text" name="runame" value="<?php echo esc_attr($s['runame'] ?? ''); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Marketplace ID</th><td><input type="text" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Default eBay Category ID</th><td><input type="text" name="default_category_id" value="<?php echo esc_attr($s['default_category_id'] ?? ''); ?>" class="regular-text" /></td></tr>
                    <tr><th scope="row">Callback URL</th><td><code><?php echo esc_html(admin_url('admin.php?page=ebay-auth-callback')); ?></code></td></tr>
                    <tr><th scope="row">Authorize URL</th><td><code style="word-break:break-all"><?php echo esc_html($redactTechnical((string) $connect_url)); ?></code></td></tr>
                </tbody>
            </table>
        </details>

        <details class="postbox" <?php echo $accountSetupConfigured ? '' : 'open'; ?>>
            <summary>Advanced: eBay account setup</summary>
            <table class="widefat striped" style="max-width:800px;margin-bottom:12px;">
                <tbody>
                    <tr><th scope="row">Location</th><td><?php echo esc_html($locationKey !== '' ? $locationKey . ' OK' : 'missing'); ?></td></tr>
                    <tr><th scope="row">Fulfillment policy</th><td><?php echo esc_html($fulfillmentId !== '' ? $findPolicyName($fulfillmentPolicies, $fulfillmentId, 'fulfillmentPolicyId') . ' OK' : 'missing'); ?></td></tr>
                    <tr><th scope="row">Payment policy</th><td><?php echo esc_html($paymentId !== '' ? $findPolicyName($paymentPolicies, $paymentId, 'paymentPolicyId') . ' OK' : 'missing'); ?></td></tr>
                    <tr><th scope="row">Return policy</th><td><?php echo esc_html($returnId !== '' ? $findPolicyName($returnPolicies, $returnId, 'returnPolicyId') . ' OK' : 'missing'); ?></td></tr>
                </tbody>
            </table>
            <h3>Inventory Location</h3>
            <p>Merchant Location Key: <input type="text" name="inventory_location_key" value="<?php echo esc_attr($s['inventory_location_key'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
            <p>Name: <input type="text" name="inventory_location_name" value="<?php echo esc_attr($s['inventory_location_name'] ?? 'gpswiss-pl'); ?>" class="regular-text" /></p>
            <p>Country: <input type="text" name="inventory_location_country" value="<?php echo esc_attr($s['inventory_location_country'] ?? 'PL'); ?>" class="regular-text" /></p>
            <p>Postal code: <input type="text" name="inventory_location_postal_code" value="<?php echo esc_attr($s['inventory_location_postal_code'] ?? '08-460'); ?>" class="regular-text" /></p>
            <p>City: <input type="text" name="inventory_location_city" value="<?php echo esc_attr($s['inventory_location_city'] ?? 'Sobolew'); ?>" class="regular-text" /></p>
            <p>Address line 1: <input type="text" name="inventory_location_address_line_1" value="<?php echo esc_attr($s['inventory_location_address_line_1'] ?? ''); ?>" class="regular-text" /></p>
            <h3>Business Policies (manual in eBay)</h3>
            <p>Fulfillment policy: <select name="fulfillmentPolicyId"><option value="">-- select --</option><?php foreach ($fulfillmentPolicies as $policy): ?><?php $policyId = (string) ($policy['fulfillmentPolicyId'] ?? ''); ?><option value="<?php echo esc_attr($policyId); ?>" <?php selected($fulfillmentId, $policyId); ?>><?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)</option><?php endforeach; ?></select></p>
            <p>Payment policy: <select name="paymentPolicyId"><option value="">-- select --</option><?php foreach ($paymentPolicies as $policy): ?><?php $policyId = (string) ($policy['paymentPolicyId'] ?? ''); ?><option value="<?php echo esc_attr($policyId); ?>" <?php selected($paymentId, $policyId); ?>><?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)</option><?php endforeach; ?></select></p>
            <p>Return policy: <select name="returnPolicyId"><option value="">-- select --</option><?php foreach ($returnPolicies as $policy): ?><?php $policyId = (string) ($policy['returnPolicyId'] ?? ''); ?><option value="<?php echo esc_attr($policyId); ?>" <?php selected($returnId, $policyId); ?>><?php echo esc_html((string) ($policy['name'] ?? $policyId)); ?> (<?php echo esc_html($policyId); ?>)</option><?php endforeach; ?></select></p>
        </details>

        <details class="postbox">
            <summary>Advanced: German content settings</summary>
            <p>Translation provider: <select name="translation_provider"><option value="disabled" <?php selected($provider, 'disabled'); ?>>Disabled</option><option value="google_cloud_translate" <?php selected($provider, 'google_cloud_translate'); ?>>Google Cloud Translate</option></select><br />
                <span class="description">Used only for generated eBay DE meta content. WooCommerce title/description and Allegro data are not changed.</span></p>
            <p>Google Translation API key: <input type="password" name="translation_api_key" value="" class="regular-text" autocomplete="off" placeholder="Leave blank to keep current" /> <span class="description">Current: <?php echo esc_html($maskSecret((string) ($s['translation_api_key'] ?? ''))); ?></span></p>
            <p><label><input type="checkbox" name="auto_generate_german_content_preflight" value="1" <?php checked(!empty($s['auto_generate_german_content_preflight'])); ?> /> Auto-generate missing German content during preflight</label><br />
                <span class="description">Preflight writes only <code>_wei_ebay_de_*</code> meta and does not call eBay inventory/offer/publish APIs.</span></p>
            <p><label><input type="checkbox" name="regenerate_german_content_on_hash_change" value="1" <?php checked(!empty($s['regenerate_german_content_on_hash_change'])); ?> /> Regenerate German content when source hash changes</label></p>
            <p><label><input type="checkbox" name="verbose_debug" value="1" <?php checked(!empty($s['verbose_debug'])); ?> /> Verbose debug mode</label><br />
                <span class="description">Default off. When off, preflight/audit avoids logging full product payloads, aspects and taxonomy candidates to the main log.</span></p>
        </details>

        <details class="postbox">
            <summary>Advanced: SKU and aspect defaults</summary>
            <p><label><input type="checkbox" name="use_woo_sku_for_ebay" value="1" disabled="disabled" /> Use WooCommerce SKU for eBay when present</label><br />
                <span class="description"><strong>Disabled.</strong> eBay uses only the plugin-owned <code>_wei_ebay_sku</code> value, generated automatically when missing. WooCommerce <code>_sku</code> is never changed.</span></p>
            <p>eBay-only SKU prefix: <input type="text" name="ebay_sku_prefix" value="<?php echo esc_attr((string) ($s['ebay_sku_prefix'] ?? 'GPSW')); ?>" class="regular-text" placeholder="GPSW" /></p>
            <p><strong>WooCommerce SKU write-back:</strong> <code>disabled</code></p>
            <p>Default manufacturer / Hersteller fallback: <input type="text" name="default_hersteller_fallback" value="<?php echo esc_attr($s['default_hersteller_fallback'] ?? ''); ?>" class="regular-text" placeholder="SEAT" /></p>
            <p>Category aspect fallbacks:<br /><textarea name="category_aspect_fallbacks" class="large-text code" rows="3" placeholder="179847|Hersteller|SEAT"><?php echo esc_textarea((string) ($s['category_aspect_fallbacks'] ?? '')); ?></textarea></p>
            <p>Auto category confidence threshold: <input type="number" step="0.01" min="0.01" max="1" name="auto_category_confidence_threshold" value="<?php echo esc_attr((string) ($s['auto_category_confidence_threshold'] ?? \WEI\Services\CategoryMappingSafety::DEFAULT_AUTO_CONFIDENCE_THRESHOLD)); ?>" class="small-text" /></p>
            <p>Legacy eBay order stock mode: <select name="stock_sync_mode"><option value="set_zero" <?php selected(($s['stock_sync_mode'] ?? 'set_zero'), 'set_zero'); ?>>Set to zero after eBay sale</option><option value="reduce" <?php selected(($s['stock_sync_mode'] ?? 'set_zero'), 'reduce'); ?>>Reduce by sold quantity</option></select></p>
        </details>

        <details class="postbox">
            <summary>Advanced: EBAY_DE price resolver</summary>
            <p>Default markup percent: <input type="number" step="0.01" min="0.01" name="ebay_default_markup_percent" value="<?php echo esc_attr((string) ($s['ebay_default_markup_percent'] ?? 25)); ?>" class="small-text" />%</p>
            <p>Special category markup percent: <input type="number" step="0.01" min="0.01" name="ebay_special_category_markup_percent" value="<?php echo esc_attr((string) ($s['ebay_special_category_markup_percent'] ?? 30)); ?>" class="small-text" />%</p>
            <p>NBP rate cache TTL: <input type="number" step="0.25" min="0.25" name="nbp_rate_cache_ttl_hours" value="<?php echo esc_attr((string) ($s['nbp_rate_cache_ttl_hours'] ?? 12)); ?>" class="small-text" /> hours</p>
            <p class="description">Special Woo category slugs: <code>silniki-kompletne</code>, <code>kompletne-skrzynie</code>. The resolved EUR price is used in <code>pricingSummary.price.value</code> only.</p>
        </details>

        <details class="postbox">
            <summary>Developer/debug overrides</summary>
            <p class="description">Dev/debug only. These exception fields remain available for troubleshooting and legacy fallbacks, but they are not part of the normal operational workflow.</p>
            <p>Product Category Overrides (dev/debug):<br /><textarea name="product_category_overrides" class="large-text code" rows="3" placeholder="43582=179847"><?php echo esc_textarea((string) ($s['product_category_overrides'] ?? '')); ?></textarea></p>
            <p>Legacy SKU Category Overrides:<br /><textarea name="sku_category_overrides" class="large-text code" rows="3" placeholder="CFM-001=179847"><?php echo esc_textarea((string) ($s['sku_category_overrides'] ?? '')); ?></textarea></p>
            <p>Developer/debug JSON aspects fallback:<br /><textarea name="sku_aspect_overrides" class="large-text code" rows="7" placeholder='{&quot;CFM-001&quot;:{&quot;Hersteller&quot;:[&quot;SEAT&quot;]}}'><?php echo esc_textarea((string) ($s['sku_aspect_overrides'] ?? '')); ?></textarea></p>
        </details>

        <p><button class="button button-primary">Save settings</button></p>
    </form>

    <details class="postbox wei-actions">
        <summary>Advanced: account maintenance actions</summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_upsert_inventory_location'); ?><input type="hidden" name="action" value="wei_upsert_inventory_location" /><button class="button">Create / Update inventory location</button></form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('wei_refresh_policies'); ?><input type="hidden" name="action" value="wei_refresh_policies" /><button class="button">Refresh policies from eBay</button></form>
    </details>

    <details class="postbox">
        <summary>Category Mapping Review</summary>
        <p>Auto-map scans WooCommerce product categories currently used by products, translates/normalizes the category query to German, and stores only confirmed high-confidence eBay DE leaf mappings automatically. Medium/low confidence rows remain blocked for export until reviewed.</p>
        <p>
            <?php foreach (['all' => 'All', 'mapped_auto' => 'mapped_auto', 'mapped_manual' => 'mapped_manual', 'needs_review' => 'needs_review', 'blocked' => 'blocked'] as $filter => $label): ?>
                <a href="<?php echo esc_url($filterUrl($filter)); ?>"><?php echo $currentFilter === $filter ? '<strong>' . esc_html($label) . '</strong>' : esc_html($label); ?></a><?php echo $filter === 'blocked' ? '' : ' / '; ?>
            <?php endforeach; ?>
            <span style="margin-left:12px;">Sort: <a href="<?php echo esc_url($sortUrl('confidence')); ?>">confidence</a> / <a href="<?php echo esc_url($sortUrl('products')); ?>">products</a></span>
        </p>
        <table class="widefat striped">
            <thead><tr><th>Woo category</th><th><a href="<?php echo esc_url($sortUrl('products')); ?>">Products</a></th><th>eBay categoryId</th><th>eBay category name/path</th><th>eBay DE link</th><th>Source</th><th><a href="<?php echo esc_url($sortUrl('confidence')); ?>">Confidence</a></th><th>Threshold</th><th>Status</th><th>Sanity reason</th><th>Last updated</th><th>Error / selected and alternatives</th><th>Manual fallback</th></tr></thead>
            <tbody>
            <?php foreach ($filteredCategoryRows as $row): ?>
                <?php $statusValue = (string) ($row['_ui_status'] ?? ''); ?>
                <?php $statusColor = in_array($statusValue, ['accepted_manual', 'accepted_auto'], true) ? '#008a20' : (in_array($statusValue, ['blocked_by_threshold', 'needs_category_review'], true) ? '#996800' : '#b32d2e'); ?>
                <tr>
                    <td><?php echo esc_html((string) ($row['woo_category_path'] ?? $row['name'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($row['product_count'] ?? '0')); ?></td>
                    <td><code><?php echo esc_html((string) ($row['_ui_category_id'] ?? '')); ?></code></td>
                    <td><?php echo esc_html((string) ($row['_ui_category_path'] ?? '')); ?></td>
                    <td><?php if ((string) ($row['_ui_category_url'] ?? '') !== ''): ?><a href="<?php echo esc_url((string) $row['_ui_category_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html('Open on eBay DE'); ?></a><?php endif; ?></td>
                    <td><?php echo esc_html((string) ($row['source'] ?? '')); ?></td>
                    <td><?php echo esc_html(isset($row['confidence']) ? number_format((float) $row['confidence'], 4) : ''); ?></td>
                    <td><?php echo esc_html(number_format($thresholdValue, 4)); ?></td>
                    <td><span style="color:<?php echo esc_attr($statusColor); ?>"><?php echo esc_html($statusValue); ?></span></td>
                    <td><?php echo esc_html((string) ($row['_ui_sanity_reason'] ?? '')); ?></td>
                    <td><?php echo esc_html((string) ($row['updated_at'] ?? '')); ?></td>
                    <td>
                        <?php echo esc_html((string) ($row['error_reason'] ?? '')); ?>
                        <?php if ((string) ($row['_ui_best_suggestion'] ?? '') !== ''): ?><br /><strong><?php echo esc_html('Selected:'); ?></strong> <span class="description"><?php echo esc_html((string) $row['_ui_best_suggestion']); ?></span><?php endif; ?>
                        <?php if ((string) ($row['_ui_rejected_best_reason'] ?? '') !== ''): ?><br /><span class="description"><?php echo esc_html('Rejected first suggestion: ' . (string) $row['_ui_rejected_best_reason']); ?></span><?php endif; ?>
                        <?php if (!empty($row['_ui_top_candidates'])): ?>
                            <br /><strong><?php echo esc_html('Top alternatives:'); ?></strong>
                            <ol style="margin:4px 0 0 18px;">
                                <?php foreach ((array) $row['_ui_top_candidates'] as $candidate): ?>
                                    <li><code><?php echo esc_html((string) ($candidate['category_id'] ?? '')); ?></code> <?php echo esc_html((string) ($candidate['name'] ?? '')); ?> <span class="description"><?php echo esc_html(number_format((float) ($candidate['score'] ?? 0), 4) . ' · ' . (string) ($candidate['source'] ?? '') . (!empty($candidate['sanity_pass']) ? ' · sanity OK' : ' · ' . (string) ($candidate['sanity_reason'] ?? 'sanity failed'))); ?></span></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <?php wp_nonce_field('wei_save_category_mapping'); ?>
                            <input type="hidden" name="action" value="wei_save_category_mapping" />
                            <input type="hidden" name="marketplace_id" value="<?php echo esc_attr($s['marketplace_id'] ?? 'EBAY_DE'); ?>" />
                            <input type="hidden" name="woo_term_id" value="<?php echo esc_attr((string) ($row['term_id'] ?? '0')); ?>" />
                            <input type="text" name="ebay_category_id" placeholder="179847" value="<?php echo esc_attr((string) ($row['ebay_category_id'] ?? '')); ?>" size="8" />
                            <input type="text" name="ebay_category_name" placeholder="eBay category name" value="<?php echo esc_attr((string) ($row['ebay_category_name'] ?? '')); ?>" />
                            <input type="text" name="ebay_category_path" placeholder="eBay category path (optional)" value="<?php echo esc_attr((string) ($row['ebay_category_path'] ?? '')); ?>" />
                            <button class="button">Save mapping</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </details>
</div>
