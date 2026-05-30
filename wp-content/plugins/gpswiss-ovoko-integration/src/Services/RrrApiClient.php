<?php

namespace GPSwiss\Ovoko\Services;

class RrrApiClient
{
    public function __construct(private array $settings)
    {
    }

    public function check_configuration(): array
    {
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? ''));
        $credentialsConfigured = $this->has_credentials();
        $publicProbes = $this->run_public_probes($baseUrl);
        $authProbe = ['executed' => false, 'success' => false, 'reason' => 'Credentials are not configured.'];

        if ($credentialsConfigured) {
            $authProbe = $this->check_auth_readonly();
        }
        $authSuccess = !empty($authProbe['success']);

        return [
            'base_url_set' => $baseUrl !== '',
            'credentials_configured' => $credentialsConfigured,
            'enabled' => !empty($this->settings['rrr_api_enabled']),
            'dry_run' => !empty($this->settings['rrr_api_dry_run']),
            'status' => ($baseUrl !== '' && $credentialsConfigured && $authSuccess) ? 'authenticated_readonly_probe_confirmed' : 'needs_configuration_or_endpoint_confirmation',
            'message' => ($baseUrl !== '' && $credentialsConfigured)
                ? 'RRR credentials saved. Configuration test uses POST form-data and validates status_code=R200 from JSON body.'
                : 'RRR API credentials are incomplete. Fill username/password/user_token in settings.',
            'test_request' => [
                'method' => 'POST',
                'path' => '/v2/get/parts?limit=1&page=1',
                'content_type' => 'application/x-www-form-urlencoded',
                'uses_form_data' => true,
                'includes_auth_fields' => true,
                'auth_fields' => ['username', 'password', 'user_token'],
                'notes' => 'Read-only probe only. Success requires JSON body status_code === R200.',
            ],
            'public_probes' => $publicProbes,
            'authenticated_endpoint_confirmed' => $authSuccess,
            'read_only_endpoint' => '/v2/get/parts?limit=1&page=1',
            'auth_probe' => $authProbe,
        ];
    }

    public function check_auth_readonly(): array
    {
        return $this->post_form('/v2/get/parts?limit=1&page=1', []);
    }


    public function diagnose_connection(array $preferredPaths = []): array
    {
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? ''));
        $auth = $this->get_auth_form_fields();
        $hasToken = trim((string) ($auth['user_token'] ?? '')) !== '';
        $hasCredentials = $this->has_credentials();

        if ($baseUrl === '') {
            return [
                'ok' => false,
                'status_label' => 'ERROR — missing base URL',
                'reason' => 'missing_base_url',
                'base_url' => '',
                'base_url_valid' => false,
                'token_present' => $hasToken,
                'credentials_present' => $hasCredentials,
                'tested_endpoint' => null,
                'http_status' => null,
                'response_body' => '',
                'error' => 'RRR base URL is empty.',
            ];
        }

        $paths = $preferredPaths !== [] ? $preferredPaths : ['/get/part/10994', '/get/car/458'];
        $results = [];
        foreach ($paths as $path) {
            $url = $baseUrl . $path;
            $response = wp_remote_post($url, [
                'timeout' => 12,
                'body' => $auth,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            ]);

            if (is_wp_error($response)) {
                $results[] = [
                    'ok' => false,
                    'tested_endpoint' => $path,
                    'url' => $url,
                    'http_status' => null,
                    'response_body' => '',
                    'error' => $response->get_error_code() . ': ' . $response->get_error_message(),
                    'reason' => str_contains((string) $response->get_error_code(), 'timeout') ? 'timeout' : 'wp_error',
                ];
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $bodyRaw = (string) wp_remote_retrieve_body($response);
            $body = mb_substr($bodyRaw, 0, 1200);
            $decoded = json_decode($bodyRaw, true);
            $statusCode = is_array($decoded) ? (string) ($decoded['status_code'] ?? '') : '';
            $ok = $code === 200 && ($statusCode === '' || $statusCode === 'R200');
            $reason = $ok ? 'ok' : ($code === 401 ? 'unauthorized' : ($code === 404 ? 'invalid_endpoint' : 'http_error'));
            $results[] = [
                'ok' => $ok,
                'tested_endpoint' => $path,
                'url' => $url,
                'http_status' => $code,
                'response_body' => $body,
                'error' => $ok ? '' : ('HTTP ' . $code),
                'reason' => $reason,
            ];
            if ($ok) {
                break;
            }
        }

        $best = $results[0] ?? [];
        foreach ($results as $row) {
            if (!empty($row['ok'])) {
                $best = $row;
                break;
            }
        }

        return [
            'ok' => !empty($best['ok']),
            'status_label' => !empty($best['ok']) ? 'OK' : ('ERROR — ' . ((string) ($best['error'] ?? $best['reason'] ?? 'unknown'))),
            'reason' => (string) ($best['reason'] ?? 'unknown'),
            'base_url' => $baseUrl,
            'base_url_valid' => (bool) preg_match('#^https?://#i', $baseUrl),
            'token_present' => $hasToken,
            'credentials_present' => $hasCredentials,
            'tested_endpoint' => (string) ($best['tested_endpoint'] ?? ''),
            'http_status' => $best['http_status'] ?? null,
            'response_body' => (string) ($best['response_body'] ?? ''),
            'error' => (string) ($best['error'] ?? ''),
            'attempts' => $results,
        ];
    }

    public function preview_fetch_part_by_id(string $partId): array
    {
        if ($partId === '') {
            return ['ok' => false, 'message' => 'Missing part id for preview'];
        }

        return $this->post_form('/crm/export/part', ['part_id' => $partId]);
    }

    public function preview_fetch_parts_page(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        return $this->post_form('/crm/export/parts-v2', ['limit' => $limit]);
    }

    public function preview_fetch_parts_sample(int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(50, $limit));
        $page = max(1, $page);
        return $this->post_form('/v2/get/parts?limit=' . $limit . '&page=' . $page, []);
    }

    public function preview_fetch_parts_by_date_from(string $dateFrom, int $limit = 50, int $page = 1): array
    {
        $limit = max(1, min(50, $limit));
        $page = max(1, $page);
        $dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : gmdate('Y-m-d');

        return $this->post_form('/v2/get/parts?limit=' . $limit . '&page=' . $page . '&date_from=' . rawurlencode($dateFrom), [], true);
    }

    public function probe_ovoko_event_sources_for_part(int $partId = 4303, array $dates = []): array
    {
        $partId = max(1, $partId);
        $dates = $dates !== [] ? $dates : [gmdate('Y-m-d'), gmdate('Y-m-d', time() - DAY_IN_SECONDS)];
        $dates = array_values(array_unique(array_filter(array_map(static function ($date): string {
            $date = trim((string) $date);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
        }, $dates))));
        if ($dates === []) {
            $dates = [gmdate('Y-m-d')];
        }

        $singlePart = $this->preview_fetch_single_part($partId);
        $singleRecord = $this->extract_single_part_record((array) ($singlePart['payload'] ?? []));
        $singleNormalized = $this->normalize_rrr_single_part_payload((array) ($singlePart['payload'] ?? []), ['details_only' => true]);

        $rows = [];
        foreach ($dates as $date) {
            foreach ($this->event_source_probe_specs($date) as $spec) {
                $result = $this->post_form((string) $spec['path'], (array) $spec['body'], true);
                $rows[] = $this->summarize_event_source_probe_row($spec, $date, $partId, $result);
            }
        }

        $hits = array_values(array_filter($rows, static fn(array $row): bool => !empty($row['contains_target_part'])));

        return [
            'ok' => true,
            'action_name' => 'Read-only Ovoko/RRR event source probe',
            'probe_type' => 'read_only_ovoko_status_sale_event_source_probe',
            'read_only' => true,
            'target_part_id' => (string) $partId,
            'dates_checked' => $dates,
            'today_utc' => gmdate('Y-m-d'),
            'single_part_reference' => [
                'endpoint' => '/get/part/{part_id}',
                'ok' => !empty($singlePart['ok']),
                'status_code' => (string) ($singlePart['status_code'] ?? ''),
                'status' => (string) ($singleNormalized['status'] ?? ($singleRecord['status'] ?? '')),
                'updated_at' => (string) ($singleNormalized['updated_at'] ?? ($singleRecord['updated_at'] ?? '')),
            ],
            'documented_or_known_endpoint_families_checked' => [
                'parts updated from date' => ['/v2/get/parts date_from query', '/v2/get/parts updated_from/updated_after query', '/crm/export/parts-v2 body filters'],
                'sold parts/status changes/deleted unavailable' => ['/v2/get/parts status=2 variants', '/crm/export/parts-v2 status=2 variants'],
                'CRM changes' => ['/crm/export/parts-v2 filters', '/crm/export/part single reference'],
                'orders/sales in Ovoko' => ['/v2/get/orders variants', '/crm/export/orders variants'],
                'warehouse status' => ['/v2/get/parts warehouse/status-style filters where available'],
            ],
            'hit_count' => count($hits),
            'found_target_part' => $hits !== [],
            'first_hit' => $hits[0] ?? null,
            'results' => $rows,
            'recommendation' => $hits !== []
                ? 'Use the first hit endpoint/params as the event/status-change source candidate and keep Woo product reconciliation only as fallback.'
                : 'No probed read-only endpoint returned the target part in the checked dates. Confirm the official Ovoko/RRR sold/status-change endpoint with Ovoko support before changing cron source logic.',
        ];
    }

    private function event_source_probe_specs(string $date): array
    {
        $dateTime = $date . ' 00:00:00';
        $queryEndpoints = ['/v2/get/parts'];
        $bodyEndpoints = ['/crm/export/parts-v2'];
        $orderEndpoints = ['/v2/get/orders', '/v2/get/sales', '/crm/export/orders', '/crm/export/sales'];
        $specs = [];

        foreach ($queryEndpoints as $endpoint) {
            foreach ([
                ['date_from' => $date],
                ['updated_from' => $dateTime],
                ['updated_after' => $dateTime],
                ['status' => '2'],
                ['date_from' => $date, 'status' => '2'],
                ['updated_from' => $dateTime, 'status' => '2'],
                ['updated_after' => $dateTime, 'status' => '2'],
            ] as $params) {
                $specs[] = ['endpoint' => $endpoint, 'path' => $endpoint . '?' . http_build_query(['limit' => 50, 'page' => 1] + $params, '', '&', PHP_QUERY_RFC3986), 'body' => [], 'params' => $params, 'param_location' => 'query'];
            }
        }

        foreach ($bodyEndpoints as $endpoint) {
            foreach ([
                ['date_from' => $date],
                ['updated_from' => $dateTime],
                ['updated_after' => $dateTime],
                ['status' => '2'],
                ['date_from' => $date, 'status' => '2'],
                ['updated_from' => $dateTime, 'status' => '2'],
                ['updated_after' => $dateTime, 'status' => '2'],
            ] as $params) {
                $specs[] = ['endpoint' => $endpoint, 'path' => $endpoint, 'body' => ['limit' => 50, 'page' => 1] + $params, 'params' => $params, 'param_location' => 'body'];
            }
        }

        foreach ($orderEndpoints as $endpoint) {
            foreach ([['date_from' => $date], ['updated_from' => $dateTime], ['updated_after' => $dateTime], ['status' => '2']] as $params) {
                $specs[] = ['endpoint' => $endpoint, 'path' => $endpoint . '?' . http_build_query(['limit' => 50, 'page' => 1] + $params, '', '&', PHP_QUERY_RFC3986), 'body' => [], 'params' => $params, 'param_location' => 'query'];
            }
        }

        return $specs;
    }

    private function summarize_event_source_probe_row(array $spec, string $date, int $targetPartId, array $result): array
    {
        $payload = (array) ($result['payload'] ?? []);
        $rawRecords = (array) ($result['raw_records'] ?? []);
        $records = $rawRecords !== [] ? $rawRecords : (array) ($result['records'] ?? []);
        $target = $this->find_record_containing_part_id($records, (string) $targetPartId);
        if ($target === [] && $payload !== []) {
            $target = $this->find_record_containing_part_id([$payload], (string) $targetPartId);
        }
        $recordIds = $this->extract_probe_record_ids_from_any($records);
        $targetNormalized = $target !== [] ? $this->normalize_rrr_single_part_payload($target, ['details_only' => true]) : [];

        return [
            'endpoint' => (string) ($spec['endpoint'] ?? ''),
            'path' => (string) ($spec['path'] ?? ''),
            'params' => (array) ($spec['params'] ?? []),
            'param_location' => (string) ($spec['param_location'] ?? ''),
            'date_checked' => $date,
            'ok' => !empty($result['ok']),
            'http_code' => $result['http_code'] ?? null,
            'status_code' => (string) ($result['status_code'] ?? ''),
            'message' => (string) ($result['msg'] ?? $result['message'] ?? ''),
            'total_count' => (int) ($result['pagination']['total_count'] ?? count($records)),
            'returned_count' => (int) ($result['records_count'] ?? count($records)),
            'contains_target_part' => $target !== [],
            'target_part_status' => $target !== [] ? (string) ($targetNormalized['status'] ?? ($target['status'] ?? '')) : '',
            'target_part_updated_at' => $target !== [] ? (string) ($targetNormalized['updated_at'] ?? ($target['updated_at'] ?? '')) : '',
            'part_ids_sample' => array_slice($recordIds, 0, 20),
            'response_top_level_keys' => array_values(array_map('strval', array_keys($payload))),
            'full_payload_omitted' => true,
        ];
    }

    private function extract_probe_record_ids_from_any(array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach (['id', 'part_id', 'partId', 'rrr_id'] as $key) {
                $id = trim((string) ($record[$key] ?? ''));
                if ($id !== '' && preg_match('/^\d+$/', $id)) {
                    $ids[] = sanitize_text_field($id);
                    break;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function find_record_containing_part_id(array $records, string $partId): array
    {
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            foreach (['id', 'part_id', 'partId', 'rrr_id'] as $key) {
                if ((string) ($record[$key] ?? '') === $partId) {
                    return $record;
                }
            }
            foreach ($record as $value) {
                if (is_array($value)) {
                    $found = $this->find_record_containing_part_id([$value], $partId);
                    if ($found !== []) {
                        return $found;
                    }
                }
            }
        }
        return [];
    }

    public function probe_parts_delta_filter_support(string $fromIso = ''): array
    {
        $fromIso = trim($fromIso) !== '' ? trim($fromIso) : gmdate('c', time() - DAY_IN_SECONDS);
        $fromTimestamp = strtotime($fromIso) ?: (time() - DAY_IN_SECONDS);
        $dateFormats = $this->build_delta_probe_date_formats($fromTimestamp);
        $baseline = $this->post_form('/v2/get/parts?limit=5&page=1', [], true);
        $baselineSummary = $this->summarize_parts_delta_probe_result($baseline, $fromTimestamp);
        $baselineIds = $this->extract_probe_record_ids((array) ($baseline['records'] ?? []));
        $baselineTotal = (int) ($baseline['pagination']['total_count'] ?? 0);
        $preciseProbe = $this->probe_precise_parts_delta_filters($fromIso, 5, 1);
        $endpointParamCandidates = [
            '/v2/get/parts' => ['updated_after', 'from', 'date_from', 'updated_from', 'modified_after', 'changed_after'],
            '/v2/get/parts/categories' => ['updated_after', 'from', 'date_from', 'updated_from'],
        ];

        $results = [];
        foreach ($endpointParamCandidates as $endpoint => $params) {
            foreach ($params as $param) {
                foreach ($dateFormats as $formatName => $dateValue) {
                    $path = $endpoint . '?limit=5&page=1&' . rawurlencode($param) . '=' . rawurlencode($dateValue);
                    $result = $this->post_form($path, [], true);
                    $results[] = $this->build_delta_probe_row($path, $result, $fromTimestamp, $baselineTotal, $baselineIds, $param, $formatName, $dateValue);
                }
            }
        }

        foreach (['from', 'date_from', 'updated_after'] as $param) {
            foreach ($dateFormats as $formatName => $dateValue) {
                $result = $this->post_form('/crm/export/parts-v2', ['limit' => 5, $param => $dateValue], true);
                $results[] = $this->build_delta_probe_row('/crm/export/parts-v2', $result, $fromTimestamp, $baselineTotal, $baselineIds, $param, $formatName, $dateValue, 'body');
            }
        }

        $confirmedRows = array_values(array_filter($results, static fn(array $row): bool => !empty($row['delta_filter_confirmed'])));
        $ignoredRows = array_values(array_filter($results, static fn(array $row): bool => !empty($row['filter_likely_ignored'])));
        $best = $confirmedRows[0] ?? null;

        return [
            'ok' => true,
            'from' => $fromIso,
            'from_timestamp' => $fromTimestamp,
            'probe_type' => 'read_only_delta_filter_probe',
            'baseline' => $baselineSummary + [
                'path' => '/v2/get/parts?limit=5&page=1',
                'record_ids' => $baselineIds,
            ],
            'date_formats_checked' => $dateFormats,
            'parameter_names_checked' => array_values(array_unique(array_merge($endpointParamCandidates['/v2/get/parts'], $endpointParamCandidates['/v2/get/parts/categories'], ['from', 'date_from', 'updated_after']))),
            'delta_sync_confirmed' => $best !== null,
            'delta_filter_used' => $best['filter_signature'] ?? '',
            'best_confirmed_filter' => $best,
            'filter_likely_ignored_count' => count($ignoredRows),
            'precise_updated_from_probe' => $preciseProbe,
            'recommended_confirmed_filter' => (string) ($preciseProbe['delta_filter_used'] ?? ($best['filter_signature'] ?? '')),
            'warning' => $best === null ? 'delta_sync_not_confirmed' : '',
            'results' => $results,
        ];
    }

    public function probe_precise_parts_delta_filters(string $fromIso = '', int $limit = 5, int $page = 1): array
    {
        $limit = max(1, min(25, $limit));
        $page = max(1, $page);
        $fromIso = trim($fromIso) !== '' ? trim($fromIso) : gmdate('c', time() - DAY_IN_SECONDS);
        $fromTimestamp = strtotime($fromIso) ?: (time() - DAY_IN_SECONDS);
        $updatedFromValue = gmdate('Y-m-d H:i:s', $fromTimestamp);
        $dateFromValue = gmdate('Y-m-d', $fromTimestamp);

        $baselinePath = '/v2/get/parts?limit=' . $limit . '&page=' . $page;
        $baseline = $this->post_form($baselinePath, [], true);
        $baselineSummary = $this->summarize_parts_delta_probe_result($baseline, $fromTimestamp);
        $baselineIds = $this->extract_probe_record_ids((array) ($baseline['records'] ?? []));
        $baselineTotal = (int) ($baseline['pagination']['total_count'] ?? 0);

        $updatedFromPath = $baselinePath . '&updated_from=' . rawurlencode($updatedFromValue);
        $dateFromPath = $baselinePath . '&date_from=' . rawurlencode($dateFromValue);
        $updatedFrom = $this->post_form($updatedFromPath, [], true);
        $dateFrom = $this->post_form($dateFromPath, [], true);

        $updatedFromRow = $this->build_delta_probe_row($updatedFromPath, $updatedFrom, $fromTimestamp, $baselineTotal, $baselineIds, 'updated_from', 'YYYY-MM-DD HH:MM:SS', $updatedFromValue);
        $dateFromRow = $this->build_delta_probe_row($dateFromPath, $dateFrom, strtotime($dateFromValue . ' 00:00:00 UTC') ?: $fromTimestamp, $baselineTotal, $baselineIds, 'date_from', 'YYYY-MM-DD', $dateFromValue);
        $baselineRow = $baselineSummary + [
            'path' => $baselinePath,
            'param_name' => '',
            'date_format' => '',
            'date_value' => '',
            'total_count' => $baselineTotal,
            'record_ids' => $baselineIds,
            'same_total_count_as_unfiltered' => true,
            'same_first_record_ids_as_unfiltered' => true,
            'filter_likely_ignored' => false,
            'delta_filter_confirmed' => false,
            'full_payload_omitted' => true,
        ];

        $deltaFilterUsed = '';
        if (!empty($updatedFromRow['delta_filter_confirmed'])) {
            $deltaFilterUsed = 'updated_from';
        } elseif (!empty($dateFromRow['delta_filter_confirmed'])) {
            $deltaFilterUsed = 'date_from';
        }

        return [
            'ok' => true,
            'probe_type' => 'read_only_precise_updated_from_vs_date_from_probe',
            'endpoint' => '/v2/get/parts',
            'limit' => $limit,
            'page' => $page,
            'delta_from_input' => $fromIso,
            'delta_from_timestamp' => $fromTimestamp,
            'updated_from_value' => $updatedFromValue,
            'updated_from_url_encoded_value' => rawurlencode($updatedFromValue),
            'date_from_value' => $dateFromValue,
            'delta_sync_confirmed' => $deltaFilterUsed !== '',
            'delta_filter_used' => $deltaFilterUsed,
            'cron_policy' => $deltaFilterUsed === 'updated_from'
                ? 'Use updated_from with exact time windows. Page only inside the delta result set.'
                : ($deltaFilterUsed === 'date_from'
                    ? 'Use date_from=YYYY-MM-DD only with idempotency/dedup by part_id + updated_at + payload hash. Page only inside the date delta result set.'
                    : 'Do not enable live cron. No confirmed delta filter.'),
            'baseline' => $baselineRow,
            'updated_from' => $updatedFromRow,
            'date_from' => $dateFromRow,
            'comparison' => [
                'updated_from_same_total_count_as_unfiltered' => !empty($updatedFromRow['same_total_count_as_unfiltered']),
                'updated_from_same_first_record_ids_as_unfiltered' => !empty($updatedFromRow['same_first_record_ids_as_unfiltered']),
                'date_from_same_total_count_as_unfiltered' => !empty($dateFromRow['same_total_count_as_unfiltered']),
                'date_from_same_first_record_ids_as_unfiltered' => !empty($dateFromRow['same_first_record_ids_as_unfiltered']),
            ],
            'safety' => [
                'read_only' => true,
                'no_woo_write' => true,
                'no_ovoko_write' => true,
                'no_stock_or_status_change' => true,
            ],
        ];
    }

    private function build_delta_probe_date_formats(int $fromTimestamp): array
    {
        return [
            'YYYY-MM-DD' => gmdate('Y-m-d', $fromTimestamp),
            'YYYY-MM-DD HH:MM:SS' => gmdate('Y-m-d H:i:s', $fromTimestamp),
            'ISO_8601_no_timezone' => gmdate('Y-m-d\TH:i:s', $fromTimestamp),
            'ISO_8601_UTC' => gmdate('c', $fromTimestamp),
            'unix_timestamp' => (string) $fromTimestamp,
        ];
    }

    private function build_delta_probe_row(string $path, array $result, int $fromTimestamp, int $baselineTotal, array $baselineIds, string $param, string $formatName, string $dateValue, string $paramLocation = 'query'): array
    {
        $summary = $this->summarize_parts_delta_probe_result($result, $fromTimestamp);
        $recordIds = $this->extract_probe_record_ids((array) ($result['records'] ?? []));
        $total = (int) ($result['pagination']['total_count'] ?? 0);
        $sameTotalAsBaseline = $baselineTotal > 0 && $total === $baselineTotal;
        $sameFirstIdsAsBaseline = $baselineIds !== [] && $recordIds !== [] && $recordIds === array_slice($baselineIds, 0, count($recordIds));
        $hasUpdatedAt = !empty($summary['updated_at_present']);
        $hasOlderThanFrom = !empty($summary['has_records_older_than_delta_from']);
        $resultSetChanged = ($baselineTotal > 0 && $total > 0 && $total < $baselineTotal) || ($recordIds !== [] && $baselineIds !== [] && $recordIds !== array_slice($baselineIds, 0, count($recordIds)));
        $confirmed = !empty($result['ok']) && $hasUpdatedAt && !$hasOlderThanFrom && $resultSetChanged;
        $likelyIgnored = !empty($result['ok']) && ($hasOlderThanFrom || ($sameTotalAsBaseline && $sameFirstIdsAsBaseline));

        return $summary + [
            'path' => $path,
            'param_name' => $param,
            'param_location' => $paramLocation,
            'date_format' => $formatName,
            'date_value' => $dateValue,
            'filter_signature' => $paramLocation . ':' . $path . ':' . $param . ':' . $formatName,
            'record_ids' => $recordIds,
            'same_total_count_as_unfiltered' => $sameTotalAsBaseline,
            'same_first_record_ids_as_unfiltered' => $sameFirstIdsAsBaseline,
            'filter_result_set_changed' => $resultSetChanged,
            'filter_likely_ignored' => $likelyIgnored,
            'delta_filter_confirmed' => $confirmed,
            'reason' => $confirmed
                ? 'Returned records are within the requested updated_at window and differ from the unfiltered baseline.'
                : ($likelyIgnored ? 'Filter appears ignored or returned records older than delta_from.' : 'No conclusive delta filtering effect detected.'),
            'full_payload_omitted' => true,
        ];
    }

    private function summarize_parts_delta_probe_result(array $result, int $fromTimestamp): array
    {
        $records = (array) ($result['records'] ?? []);
        $updatedTimestamps = [];
        $older = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $updatedAt = trim((string) ($record['updated_at'] ?? ''));
            if ($updatedAt === '') {
                continue;
            }
            $timestamp = strtotime($updatedAt);
            if (!$timestamp) {
                continue;
            }
            $updatedTimestamps[] = $timestamp;
            if ($timestamp < $fromTimestamp) {
                $older[] = [
                    'id' => sanitize_text_field((string) ($record['id'] ?? '')),
                    'updated_at' => sanitize_text_field($updatedAt),
                ];
            }
        }

        return [
            'ok' => !empty($result['ok']),
            'success' => !empty($result['success']),
            'status_code' => (string) ($result['status_code'] ?? ''),
            'http_code' => $result['http_code'] ?? null,
            'records_count' => (int) ($result['records_count'] ?? 0),
            'pagination' => (array) ($result['pagination'] ?? []),
            'total_count' => (int) ($result['pagination']['total_count'] ?? 0),
            'returned_records_count' => (int) ($result['records_count'] ?? count($records)),
            'updated_at_present' => $updatedTimestamps !== [],
            'min_updated_at' => $updatedTimestamps !== [] ? gmdate('c', min($updatedTimestamps)) : '',
            'max_updated_at' => $updatedTimestamps !== [] ? gmdate('c', max($updatedTimestamps)) : '',
            'records_older_than_delta_from_count' => count($older),
            'has_records_older_than_delta_from' => $older !== [],
            'records_older_than_delta_from_sample' => array_slice($older, 0, 5),
        ];
    }

    private function extract_probe_record_ids(array $records): array
    {
        $ids = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $id = trim((string) ($record['id'] ?? ''));
            if ($id !== '') {
                $ids[] = sanitize_text_field($id);
            }
        }
        return $ids;
    }

    public function probe_part_search_by_code(string $partNumber): array
    {
        $partNumber = trim($partNumber);
        if ($partNumber === '') {
            return ['ok' => false, 'action_name' => 'Probe RRR part search by code', 'reason' => 'missing_part_number'];
        }

        $baseline = $this->post_form('/v2/get/parts?limit=10&page=1', [], true);
        $baselineTotal = (int) ($baseline['pagination']['total_count'] ?? 0);
        $baselineRecords = (int) ($baseline['records_count'] ?? 0);
        $baselineStatus = (string) ($baseline['status_code'] ?? '');

        $params = ['manufacturer_code', 'visible_code', 'other_code', 'code', 'search', 'q', 'external_id', 'part_code', 'part_number', 'query', 'name'];
        $rows = [];
        foreach ($params as $param) {
            $path = '/v2/get/parts?limit=10&page=1&' . rawurlencode($param) . '=' . rawurlencode($partNumber);
            $result = $this->post_form($path, [], true);
            $payload = (array) ($result['payload'] ?? []);
            $rawData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $sample = [];
            $keys = [];
            $exactMatches = [];
            $exactFields = [];
            foreach ($rawData as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if ($keys === []) {
                    $keys = array_values(array_map('strval', array_keys($record)));
                }
                if (count($sample) < 3) {
                    $sample[] = [
                        'id' => sanitize_text_field((string) ($record['id'] ?? '')),
                        'name' => sanitize_text_field((string) ($record['name'] ?? '')),
                        'manufacturer_code' => sanitize_text_field((string) ($record['manufacturer_code'] ?? '')),
                        'visible_code' => sanitize_text_field((string) ($record['visible_code'] ?? '')),
                        'other_code' => sanitize_text_field((string) ($record['other_code'] ?? '')),
                        'external_id' => sanitize_text_field((string) ($record['external_id'] ?? '')),
                    ];
                }
                foreach (['manufacturer_code', 'visible_code', 'other_code', 'code', 'external_id', 'part_code', 'part_number'] as $field) {
                    $value = isset($record[$field]) ? (string) $record[$field] : '';
                    if ($value !== '' && strcasecmp($value, $partNumber) === 0) {
                        $exactFields[$field] = true;
                        $exactMatches[] = sanitize_text_field((string) ($record['id'] ?? ''));
                    }
                }
            }

            $total = (int) ($result['pagination']['total_count'] ?? 0);
            $recordsCount = (int) ($result['records_count'] ?? 0);
            $statusCode = (string) ($result['status_code'] ?? '');
            $effective = false;
            if ($statusCode === 'R200') {
                if ($total !== $baselineTotal || $recordsCount !== $baselineRecords) {
                    $effective = true;
                }
                if ($exactMatches !== []) {
                    $effective = true;
                }
                if (stripos((string) ($result['msg'] ?? ''), $param) !== false && stripos((string) ($result['msg'] ?? ''), 'filter') !== false) {
                    $effective = true;
                }
            }

            $rows[] = [
                'path' => $path,
                'status_code' => $statusCode,
                'msg' => (string) ($result['msg'] ?? ''),
                'pagination' => (array) ($result['pagination'] ?? []),
                'records_count' => $recordsCount,
                'candidate_record_keys' => $keys,
                'exact_code_matches' => array_values(array_unique(array_filter($exactMatches))),
                'exact_match_fields' => array_values(array_keys($exactFields)),
                'first_records_safe_summary' => $sample,
                'filter_effective' => $effective,
                'reason' => $effective ? 'Filter changed result set and/or returned exact match.' : 'No clear filtering effect compared with baseline sample.',
            ];
        }

        return [
            'ok' => true,
            'action_name' => 'Probe RRR part search by code',
            'part_number' => $partNumber,
            'baseline' => [
                'path' => '/v2/get/parts?limit=10&page=1',
                'status_code' => $baselineStatus,
                'total_count' => $baselineTotal,
                'records_count' => $baselineRecords,
            ],
            'candidates' => $rows,
        ];
    }

    public function preview_search_part_by_code(string $partNumber, int $limit = 10, int $page = 1): array
    {
        $partNumber = trim($partNumber);
        if ($partNumber === '') {
            return ['ok' => false, 'reason' => 'missing_part_number'];
        }
        $limit = max(1, min(50, $limit));
        $page = max(1, $page);
        $path = '/v2/get/parts?limit=' . $limit . '&page=' . $page . '&search=' . rawurlencode($partNumber);
        $result = $this->post_form($path, [], true);
        $records = is_array($result['records'] ?? null) ? $result['records'] : [];
        return [
            'ok' => true,
            'path' => $path,
            'status_code' => (string) ($result['status_code'] ?? ''),
            'pagination' => (array) ($result['pagination'] ?? []),
            'records_count' => (int) ($result['records_count'] ?? count($records)),
            'records' => $records,
        ];
    }


    public function preview_fetch_car_by_id(string $carId): array
    {
        $carId = trim($carId);
        if ($carId === '') {
            return ['ok' => false, 'message' => 'Missing car id for preview'];
        }

        $probe = $this->probe_vehicle_endpoints((int) $carId);
        foreach ((array) ($probe['endpoints'] ?? []) as $row) {
            if (empty($row['success'])) {
                continue;
            }
            $record = (array) ($row['candidate_record'] ?? []);
            $normalized = $this->normalize_vehicle_record($record, $carId);
            $exactCarMatch = !empty($row['contains_car_id_requested']);
            $pathIsExactFetch = preg_match('#/(?:v2/)?get/(?:car|vehicle)/' . preg_quote($carId, '#') . '$#', (string) ($row['path'] ?? '')) === 1;
            if ($exactCarMatch || ($pathIsExactFetch && $this->has_vehicle_fields($normalized))) {
                return [
                    'ok' => true,
                    'endpoint_confirmed' => true,
                    'endpoint_used' => (string) ($row['path'] ?? ''),
                    'car_id' => $carId,
                    'normalized' => $normalized,
                    'raw_record' => $record,
                    'raw_diagnostics' => $this->build_vehicle_raw_diagnostics($record, $normalized),
                ];
            }
        }

        return ['ok'=>false,'endpoint_confirmed'=>false,'car_id'=>$carId,'probe'=>$probe,'message'=>'No confirmed read-only endpoint returned vehicle details for requested car_id.'];
    }

    public function probe_ovoko_vehicle_data_for_car_id(int $carId): array
    {
        $carId = max(1, $carId);
        $preview = $this->preview_fetch_car_by_id((string) $carId);
        $record = (array) ($preview['raw_record'] ?? []);
        $normalized = (array) ($preview['normalized'] ?? []);

        return [
            'ok' => !empty($preview['ok']),
            'mode' => 'read_only',
            'action_name' => 'Probe Ovoko vehicle data for car_id',
            'car_id' => $carId,
            'endpoint_used' => (string) ($preview['endpoint_used'] ?? ''),
            'vehicle_fetch_ok' => !empty($preview['ok']),
            'vehicle_fields_count' => count(array_filter($normalized, static fn($value) => !is_array($value) && $value !== '' && $value !== null)),
            'normalized_vehicle_data' => $normalized,
            'raw_report' => $this->build_vehicle_raw_diagnostics($record, $normalized),
            'dictionary_resolution' => [
                'status' => (string) ($normalized['vehicle_dictionary_resolution_status'] ?? ''),
                'source' => (string) ($normalized['vehicle_dictionary_resolution_source'] ?? ''),
                'field_sources' => (array) ($normalized['vehicle_dictionary_field_sources'] ?? []),
            ],
            'no_write_to_woo' => true,
            'checked_at' => gmdate('c'),
        ];
    }

    public function probe_vehicle_endpoints(int $carId = 458): array
    {
        $carId=max(1,$carId);
        $paths=['/get/car/'.$carId,'/v2/get/car/'.$carId,'/get/vehicle/'.$carId,'/v2/get/vehicle/'.$carId,'/get/cars?limit=1&page=1','/v2/get/cars?limit=1&page=1','/get/cars','/get/vehicles?limit=1&page=1','/v2/get/vehicles?limit=1&page=1'];
        $results=[];
        foreach($paths as $path){
            $raw=$this->post_form($path,[],true);
            $payload=(array)($raw['payload']??[]);
            $candidate=$this->extract_candidate_record($payload, (string) $carId);
            $candidateKeys=array_values(array_map('strval',array_keys((array)$candidate)));
            $contains=(string)($this->first_non_empty_value((array)$candidate,['car_id','id','vehicle_id']))===(string)$carId;
            $norm=$this->normalize_vehicle_record((array)$candidate,(string)$carId,false);
            $results[]=['path'=>$path,'executed'=>!empty($raw['executed']),'http_code'=>$raw['http_code']??null,'status_code'=>(string)($raw['status_code']??''),'msg'=>(string)($raw['msg']??$raw['message']??''),'success'=>!empty($raw['success']),'response_top_level_keys'=>array_values(array_map('strval',array_keys($payload))),'candidate_record_keys'=>$candidateKeys,'contains_car_id_458'=>$carId===458?$contains:null,'contains_car_id_requested'=>$contains,'contains_vehicle_fields'=>$this->has_vehicle_fields($norm),'safe_sample'=>$this->safe_sample($candidate),'full_payload_omitted'=>true,'candidate_record'=>$candidate];
        }
        return ['ok'=>true,'car_id'=>$carId,'endpoints'=>$results];
    }
    public function preview_fetch_single_part(int $partId): array
    {
        $partId = max(1, $partId);
        return $this->post_form('/get/part/' . $partId, [], true);
    }

    public function extract_single_part_record(array $payload): array
    {
        $candidates = [];

        if (isset($payload['list'][0][0]) && is_array($payload['list'][0][0])) {
            $candidates[] = $payload['list'][0][0];
        }
        if (isset($payload['list'][0]) && is_array($payload['list'][0])) {
            $candidates[] = $payload['list'][0];
        }
        if (isset($payload['data'][0]) && is_array($payload['data'][0])) {
            $candidates[] = $payload['data'][0];
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            $candidates[] = $payload['data'];
        }
        $candidates[] = $payload;

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $hasId = isset($candidate['id']) && (string) $candidate['id'] !== '';
            $hasName = isset($candidate['name']) && (string) $candidate['name'] !== '';
            if ($hasId || $hasName) {
                return $candidate;
            }
        }

        return [];
    }

    public function normalize_rrr_single_part_payload(array $payload, array $options = []): array
    {
        $detailsOnly = !empty($options['details_only']);
        $record = $this->extract_single_part_record($payload);
        $carId = $this->first_non_empty_value($record, ['car_id', 'carId', 'vehicle_id', 'vehicleId']);
        $vehicle = $this->extract_vehicle_data($record);
        $priceDiagnostics = $detailsOnly ? ['allegro_channel_price' => null, 'allegro_channel_currency' => '', 'allegro_price_location' => 'skipped_details_only', 'diagnostic_channel_keys' => []] : $this->extract_allegro_price_diagnostics($record);
        $internalNotesPrice = $detailsOnly ? ['field_exists' => false, 'price_found' => false, 'price_value' => null, 'price_format_valid' => false, 'price_error' => 'skipped_details_only'] : $this->parse_internal_notes_price($record);
        $ovokoPrice = $record['price'] ?? null;
        $ovokoCurrency = sanitize_text_field((string) ($record['currency'] ?? ''));
        $ovokoOriginalPrice = $record['original_price'] ?? null;
        $ovokoOriginalCurrency = sanitize_text_field((string) ($record['original_currency'] ?? ''));
        $allegroPrice = $priceDiagnostics['allegro_channel_price'];
        $allegroCurrency = sanitize_text_field((string) $priceDiagnostics['allegro_channel_currency']);

        $priceSource = 'missing_woo_price';
        $priceReviewRequired = true;
        $priceReason = 'No plain numeric Woo price found in internal notes and no Allegro channel price available.';
        $wooTargetPrice = null;
        $wooTargetCurrency = '';

        if (!empty($internalNotesPrice['price_found'])) {
            $priceSource = 'internal_notes_plain_price';
            $priceReviewRequired = false;
            $priceReason = 'Woo price parsed from Ovoko internal notes plain numeric value.';
            $wooTargetPrice = $internalNotesPrice['price_value'];
            $wooTargetCurrency = 'PLN';
        } elseif (!empty($internalNotesPrice['field_exists']) && empty($internalNotesPrice['price_format_valid'])) {
            $priceSource = 'invalid_internal_notes_price';
            $priceReason = 'Internal notes contain invalid price format. Expected plain numeric value like 250 or 250.50.';
        } elseif ($allegroPrice !== null) {
            $priceSource = 'allegro_channel_price';
            $priceReviewRequired = false;
            $priceReason = 'Woo target price sourced from Allegro channel price.';
            $wooTargetPrice = $allegroPrice;
            $wooTargetCurrency = $allegroCurrency !== '' ? $allegroCurrency : $ovokoOriginalCurrency;
        }

        $images = $detailsOnly ? [] : $this->normalize_gallery($record['part_photo_gallery'] ?? $record['images'] ?? []);
        $singlePhoto = $detailsOnly ? '' : sanitize_text_field((string) ($record['photo'] ?? ''));

        $summary = [
            'part_id' => sanitize_text_field((string) ($record['id'] ?? '')),
            'car_id' => sanitize_text_field((string) $carId),
            'title' => sanitize_text_field((string) ($record['name'] ?? '')),
            'status' => sanitize_text_field((string) ($record['status'] ?? '')),
            'price' => $ovokoPrice,
            'currency' => $ovokoCurrency,
            'original_price' => $ovokoOriginalPrice,
            'original_currency' => $ovokoOriginalCurrency,
            'ovoko_price' => $ovokoPrice,
            'ovoko_currency' => $ovokoCurrency,
            'ovoko_original_price' => $ovokoOriginalPrice,
            'ovoko_original_currency' => $ovokoOriginalCurrency,
            'allegro_channel_price' => $allegroPrice,
            'allegro_channel_currency' => $allegroCurrency,
            'woo_target_price' => $wooTargetPrice,
            'woo_target_currency' => $wooTargetCurrency,
            'price_source' => $priceSource,
            'price_review_required' => $priceReviewRequired,
            'price_reason' => $priceReason,
            'allegro_price_available' => $allegroPrice !== null,
            'allegro_price_location' => (string) ($priceDiagnostics['allegro_price_location'] ?? 'not_found_in_get_part_payload'),
            'price_diagnostic_channel_keys' => (array) ($priceDiagnostics['diagnostic_channel_keys'] ?? []),
            'manufacturer_code' => sanitize_text_field((string) ($record['manufacturer_code'] ?? '')),
            'visible_code' => sanitize_text_field((string) ($record['visible_code'] ?? '')),
            'other_code' => sanitize_text_field((string) ($record['other_code'] ?? '')),
            'quality' => sanitize_text_field((string) ($record['quality'] ?? '')),
            'notes' => sanitize_textarea_field((string) ($record['notes'] ?? '')),
            'category_id' => sanitize_text_field((string) ($record['category_id'] ?? '')),
            'category_title_path' => sanitize_text_field((string) ($record['category_title_path'] ?? '')),
            'position' => sanitize_text_field((string) ($record['position'] ?? '')),
            'shop_url' => esc_url_raw((string) ($record['shop_url'] ?? '')),
            'show_url' => esc_url_raw((string) ($record['show_url'] ?? '')),
            'photo' => $singlePhoto,
            'part_photo_gallery' => $images,
            'create_date' => sanitize_text_field((string) ($record['create_date'] ?? '')),
            'updated_at' => sanitize_text_field((string) ($record['updated_at'] ?? '')),
            'external_id' => sanitize_text_field((string) ($record['external_id'] ?? '')),
            'place' => sanitize_text_field((string) ($record['place'] ?? '')),
            'allegro_channel' => sanitize_text_field((string) ($record['allegro_channel'] ?? $record['allegro'] ?? '')),
            'allegro_id' => sanitize_text_field((string) ($record['allegro_id'] ?? '')),
            'vehicle_data_status' => 'not_available',
            'internal_notes_field_exists' => array_key_exists('internal_notes', $record),
            'internal_notes_price_found' => !empty($internalNotesPrice['price_found']),
            'internal_notes_price_value' => $internalNotesPrice['price_value'],
            'internal_notes_price_currency' => 'PLN',
            'internal_notes_price_format_valid' => !empty($internalNotesPrice['price_format_valid']),
            'internal_notes_price_error' => (string) ($internalNotesPrice['price_error'] ?? ''),
            'reserved_user_field_exists' => array_key_exists('reserved_user', $record),
            'reserved_date_field_exists' => array_key_exists('reserved_date', $record),
        ];
        if ($detailsOnly) {
            unset(
                $summary['title'],
                $summary['price'],
                $summary['currency'],
                $summary['original_price'],
                $summary['original_currency'],
                $summary['ovoko_price'],
                $summary['ovoko_currency'],
                $summary['ovoko_original_price'],
                $summary['ovoko_original_currency'],
                $summary['allegro_channel_price'],
                $summary['allegro_channel_currency'],
                $summary['woo_target_price'],
                $summary['woo_target_currency'],
                $summary['price_source'],
                $summary['price_review_required'],
                $summary['price_reason'],
                $summary['allegro_price_available'],
                $summary['allegro_price_location'],
                $summary['price_diagnostic_channel_keys'],
                $summary['notes'],
                $summary['shop_url'],
                $summary['show_url'],
                $summary['photo'],
                $summary['part_photo_gallery']
            );
        }

        if ($vehicle !== []) {
            $summary = array_merge($summary, $vehicle);
            $summary['vehicle_data_status'] = 'embedded';
        } elseif ($summary['car_id'] !== '') {
            $summary['vehicle_data_status'] = 'car_id_only';
        }

        return $summary;
    }

    private function parse_internal_notes_price(array $record): array
    {
        $fieldExists = array_key_exists('internal_notes', $record);
        if (!$fieldExists) {
            return ['field_exists' => false, 'price_found' => false, 'price_value' => null, 'price_format_valid' => false, 'price_error' => ''];
        }

        $raw = is_scalar($record['internal_notes']) ? trim((string) $record['internal_notes']) : '';
        if ($raw === '') {
            return ['field_exists' => true, 'price_found' => false, 'price_value' => null, 'price_format_valid' => false, 'price_error' => ''];
        }

        if (!preg_match('/^\d+(?:[.,]\d+)?$/', $raw)) {
            return ['field_exists' => true, 'price_found' => false, 'price_value' => null, 'price_format_valid' => false, 'price_error' => 'Invalid format. Expected plain numeric value like 250 or 250.50.'];
        }

        $normalized = str_replace(',', '.', $raw);
        $value = (float) $normalized;
        if ($value <= 0) {
            return ['field_exists' => true, 'price_found' => false, 'price_value' => null, 'price_format_valid' => false, 'price_error' => 'Price must be a positive number greater than 0.'];
        }

        return ['field_exists' => true, 'price_found' => true, 'price_value' => $value + 0, 'price_format_valid' => true, 'price_error' => ''];
    }

    private function extract_allegro_price_diagnostics(array $record): array
    {
        $candidateKeys = [
            'allegro', 'channels', 'sales_channels', 'channel_prices', 'marketplace_prices',
            'integrations', 'offers', 'allegro_price', 'price_allegro', 'sale_price', 'external_offers',
        ];
        $flat = $this->flatten_payload_paths($record);
        $matchingKeys = [];
        $allegroPrice = null;
        $allegroCurrency = '';
        $allegroLocation = 'not_found_in_get_part_payload';

        foreach ($flat as $path => $value) {
            $lowerPath = strtolower($path);
            foreach ($candidateKeys as $candidateKey) {
                if (str_contains($lowerPath, strtolower($candidateKey))) {
                    $matchingKeys[$path] = true;
                }
            }

            if ($allegroPrice !== null) {
                continue;
            }

            if (is_numeric($value) && str_contains($lowerPath, 'allegro') && str_contains($lowerPath, 'price')) {
                $allegroPrice = $value + 0;
                $allegroLocation = $path;
                continue;
            }

            if (is_string($value) && str_contains($lowerPath, 'currency') && str_contains($lowerPath, 'allegro')) {
                $allegroCurrency = sanitize_text_field($value);
            }
        }

        return [
            'allegro_channel_price' => $allegroPrice,
            'allegro_channel_currency' => $allegroCurrency,
            'allegro_price_location' => $allegroLocation,
            'diagnostic_channel_keys' => array_values(array_keys($matchingKeys)),
        ];
    }

    private function flatten_payload_paths(array $input, string $prefix = ''): array
    {
        $flattened = [];
        foreach ($input as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $flattened += $this->flatten_payload_paths($value, $path);
                continue;
            }
            $flattened[$path] = $value;
        }
        return $flattened;
    }

    public function build_sales_channels_diagnostic(array $payload): array
    {
        $record = $this->extract_single_part_record($payload);
        if ($record === []) {
            return [
                'allegro_channel_available' => false,
                'reason' => 'No Allegro/channel fields found in /get/part/{id}; likely requires another endpoint or Ovoko/RRR support confirmation.',
                'matched_paths' => [],
                'matched_keys' => [],
                'safe_scalar_values' => [],
                'full_payload_omitted' => true,
            ];
        }

        $keywords = [
            'allegro', 'channel', 'channels', 'marketplace', 'marketplaces', 'offer', 'offers',
            'listing', 'listings', 'aukcja', 'auction', 'external_offer', 'publication', 'integration',
        ];
        $safeKeyHints = ['id', 'status', 'name', 'type', 'channel', 'offer_id', 'listing_id', 'price', 'currency'];
        $flat = $this->flatten_payload_paths($record);
        $matchedPaths = [];
        $matchedKeys = [];
        $safeScalars = [];
        $matchedPathTypes = [];
        $allegroOfferId = '';
        $allegroListingId = '';
        $allegroPublicationStatus = '';
        $allegroPrice = null;
        $allegroPriceLocation = '';

        foreach ($flat as $path => $value) {
            $pathLower = strtolower($path);
            $key = (string) substr($path, (int) strrpos('.' . $path, '.') + 1);
            $keyLower = strtolower($key);
            $isMatch = false;
            foreach ($keywords as $keyword) {
                if (str_contains($pathLower, $keyword) || str_contains($keyLower, $keyword)) {
                    $isMatch = true;
                    break;
                }
            }
            if (!$isMatch) {
                continue;
            }

            $matchedPaths[$path] = true;
            $matchedKeys[$key] = true;
            $matchedPathTypes[$path] = gettype($value);

            $isSafeKey = false;
            foreach ($safeKeyHints as $hint) {
                if (str_contains($keyLower, $hint)) {
                    $isSafeKey = true;
                    break;
                }
            }
            if ($isSafeKey && (is_scalar($value) || $value === null)) {
                $safeScalars[] = [
                    'path' => $path,
                    'key' => $key,
                    'value' => is_string($value) ? sanitize_text_field($value) : $value,
                    'value_type' => gettype($value),
                ];
            }

            if ($allegroOfferId === '' && str_contains($pathLower, 'allegro') && str_contains($pathLower, 'offer') && str_contains($keyLower, 'id') && (is_scalar($value) || $value === null)) {
                $allegroOfferId = sanitize_text_field((string) $value);
            }
            if ($allegroListingId === '' && str_contains($pathLower, 'allegro') && (str_contains($pathLower, 'listing') || str_contains($pathLower, 'auction')) && str_contains($keyLower, 'id') && (is_scalar($value) || $value === null)) {
                $allegroListingId = sanitize_text_field((string) $value);
            }
            if ($allegroPublicationStatus === '' && str_contains($pathLower, 'allegro') && str_contains($pathLower, 'publication') && str_contains($keyLower, 'status') && is_scalar($value)) {
                $allegroPublicationStatus = sanitize_text_field((string) $value);
            }
            if ($allegroPrice === null && str_contains($pathLower, 'allegro') && str_contains($pathLower, 'price') && is_numeric($value)) {
                $allegroPrice = $value + 0;
                $allegroPriceLocation = $path;
            }
        }

        $walker = function (array $node, string $prefix = '') use (&$walker, $keywords, &$matchedPaths, &$matchedKeys, &$matchedPathTypes): void {
            foreach ($node as $k => $v) {
                $path = $prefix === '' ? (string) $k : $prefix . '.' . (string) $k;
                $pathLower = strtolower($path);
                $keyLower = strtolower((string) $k);
                foreach ($keywords as $keyword) {
                    if (str_contains($pathLower, $keyword) || str_contains($keyLower, $keyword)) {
                        $matchedPaths[$path] = true;
                        $matchedKeys[(string) $k] = true;
                        $matchedPathTypes[$path] = gettype($v);
                        break;
                    }
                }
                if (is_array($v)) {
                    $walker($v, $path);
                }
            }
        };
        $walker($record);
        $hasAny = !empty($matchedPaths);

        $diagnostic = [
            'allegro_channel_available' => $hasAny,
            'matched_paths' => array_values(array_keys($matchedPaths)),
            'matched_keys' => array_values(array_keys($matchedKeys)),
            'matched_path_types' => $matchedPathTypes,
            'safe_scalar_values' => $safeScalars,
            'full_payload_omitted' => true,
            'allegro_offer_id' => $allegroOfferId,
            'allegro_listing_id' => $allegroListingId,
            'allegro_publication_status' => $allegroPublicationStatus,
            'allegro_price' => $allegroPrice,
            'allegro_price_location' => $allegroPriceLocation !== '' ? $allegroPriceLocation : 'not_found',
        ];

        if (($allegroOfferId !== '' || $allegroListingId !== '') && $allegroPrice === null) {
            $diagnostic['price_resolution_hint'] = 'Allegro offer ID found; Woo target price may need to be fetched from Allegro API, not RRR API.';
        }
        if (!$hasAny) {
            $diagnostic['reason'] = 'No Allegro/channel fields found in /get/part/{id}; likely requires another endpoint or Ovoko/RRR support confirmation.';
        }

        return $diagnostic;
    }

    private function normalize_gallery(mixed $gallery): array
    {
        if (!is_array($gallery)) {
            return [];
        }

        $result = [];
        foreach ($gallery as $item) {
            if (is_string($item)) {
                $result[] = esc_url_raw($item);
                continue;
            }
            if (is_array($item) && isset($item['url'])) {
                $result[] = esc_url_raw((string) $item['url']);
            }
        }
        return array_values(array_filter($result));
    }

    private function first_non_empty_value(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && (string) $record[$key] !== '') {
                return (string) $record[$key];
            }
        }
        return '';
    }

    private function extract_vehicle_data(array $record): array
    {
        $container = [];
        foreach (['car', 'vehicle', 'vehicle_data', 'car_data'] as $vehicleKey) {
            if (isset($record[$vehicleKey]) && is_array($record[$vehicleKey])) {
                $container = $record[$vehicleKey];
                break;
            }
        }
        if ($container === [] && $this->has_vehicle_fields($this->normalize_vehicle_record($record, (string) $this->first_non_empty_value($record, ['car_id', 'vehicle_id'])))) {
            $container = $record;
        }
        if ($container === []) {
            return [];
        }

        $vin = sanitize_text_field((string) ($container['vin'] ?? $container['vehicle_vin'] ?? ''));
        $vinMasked = $vin === '' ? '' : substr($vin, 0, 3) . '***' . substr($vin, -3);
        $normalized = $this->normalize_vehicle_record($container, (string) $this->first_non_empty_value($record, ['car_id', 'vehicle_id']));
        $normalized['vehicle_id'] = sanitize_text_field((string) ($container['id'] ?? $container['car_id'] ?? $container['vehicle_id'] ?? ''));
        $normalized['vehicle_vin_masked'] = $vinMasked;
        $normalized['vehicle_data_status'] = 'embedded';
        return $normalized;
    }

    public function normalize_rrr_part_payload(array $payload): array
    {
        return [
            'part_id' => sanitize_text_field((string) ($payload['part_id'] ?? $payload['id'] ?? '')),
            'name' => sanitize_text_field((string) ($payload['name'] ?? '')),
            'description' => sanitize_textarea_field((string) ($payload['description'] ?? '')),
            'price' => (float) ($payload['price'] ?? 0),
            'stock' => (int) ($payload['stock'] ?? 0),
            'raw' => $payload,
        ];
    }

    public function map_rrr_part_to_woo_meta(array $normalized): array
    {
        return [
            '_ovoko_part_id' => (string) ($normalized['part_id'] ?? ''),
            '_ovoko_price' => (string) ($normalized['price'] ?? ''),
            '_ovoko_raw_payload' => wp_json_encode((array) ($normalized['raw'] ?? [])),
        ];
    }



    private function extract_candidate_record(array $payload, string $requestedId = ''): array
    {
        $candidates = [];
        $collect = static function ($node) use (&$candidates): void {
            if (is_array($node)) {
                $candidates[] = $node;
            }
        };
        if (isset($payload['list']) && is_array($payload['list'])) {
            foreach ($payload['list'] as $item) {
                if (is_array($item) && isset($item[0]) && is_array($item[0])) { $collect($item[0]); }
                $collect($item);
            }
        }
        if (isset($payload['data']) && is_array($payload['data'])) {
            if ($this->is_list_array($payload['data'])) { foreach ($payload['data'] as $item) { $collect($item); } }
            $collect($payload['data']);
        }
        foreach (['car','vehicle','cars','vehicles','result','record'] as $k) {
            if (!isset($payload[$k]) || !is_array($payload[$k])) { continue; }
            if ($this->is_list_array($payload[$k])) { foreach ($payload[$k] as $item) { $collect($item); } }
            $collect($payload[$k]);
        }
        $candidates[] = $payload;

        if ($requestedId !== '') {
            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) { continue; }
                if ((string) $this->first_non_empty_value($candidate, ['car_id','id','vehicle_id']) === $requestedId) { return (array) $candidate; }
            }
        }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) { continue; }
            if (!empty($candidate['car_id']) || !empty($candidate['id']) || !empty($candidate['vehicle_id']) || !empty($candidate['car_model']) || !empty($candidate['model']) || !empty($candidate['car_engine_code']) || !empty($candidate['engine_code'])) { return (array) $candidate; }
        }
        return [];
    }

    private function is_list_array(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    private function normalize_vehicle_record(array $record, string $fallbackCarId, bool $resolveDictionaries = true): array
    {
        $carId = (string) ($this->first_non_empty_value($record, ['car_id', 'id', 'vehicle_id']) ?: $fallbackCarId);
        $capacityRaw = $this->first_non_empty_value($record, ['car_engine_cubic_capacity', 'engine_capacity_cc', 'engine_capacity', 'capacity_cc']);
        $capacityCc = is_numeric($capacityRaw) ? (int) $capacityRaw : 0;
        $capacityL = $capacityCc > 0 ? round($capacityCc / 1000, 1) : null;
        $mapped = $this->local_confirmed_dictionary_for_car($record, $carId);
        $dictionaryResolved = $resolveDictionaries ? $this->resolve_vehicle_dictionaries($record) : [];
        $rawFuelId = (string) $this->first_non_empty_value($record, ['car_fuel','fuel_id','car_fuel_id']);
        $rawFuelId = $this->looks_like_dictionary_id($rawFuelId) ? $rawFuelId : '';
        $dictionarySources = (array) ($dictionaryResolved['_vehicle_dictionary_field_sources'] ?? []);
        unset($dictionaryResolved['_vehicle_dictionary_field_sources']);
        $localMapped = $mapped;
        $mapped = array_merge($dictionaryResolved, $mapped);
        if ($rawFuelId !== '' && empty($dictionaryResolved['vehicle_fuel'])) {
            unset($mapped['vehicle_fuel']);
        }
        if (!empty($dictionaryResolved['vehicle_fuel'])) {
            $mapped['vehicle_fuel'] = $dictionaryResolved['vehicle_fuel'];
            foreach (['vehicle_fuel_raw_id','vehicle_fuel_resolved_label','vehicle_fuel_endpoint_used','vehicle_fuel_matched_record'] as $fuelDebugKey) {
                if (array_key_exists($fuelDebugKey, $dictionaryResolved)) { $mapped[$fuelDebugKey] = $dictionaryResolved[$fuelDebugKey]; }
            }
        }
        $engineCode = sanitize_text_field((string) ($this->first_non_empty_value($record, ['car_engine_code', 'engine_code', 'engine']) ?? ''));
        $directMake = $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_make_name','car_manufacturer_name','manufacturer_name','make_name','brand_name','make','manufacturer','brand','car_make']));
        $directModel = $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_model_name','model_name','model_title','car_model_title','model','car_model_text']));
        $directGeneration = $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_model_category_name','model_category_name','generation_name','generation','modification','car_generation','car_model_category_title']));
        if (empty($mapped['vehicle_generation']) && !empty($mapped['vehicle_model']) && empty($directGeneration)) {
            $mapped['vehicle_generation'] = (string) $mapped['vehicle_model'];
            $dictionarySources['vehicle_generation'] = ['source' => (string) (($dictionarySources['vehicle_model']['source'] ?? '') ?: 'dictionary_api'), 'id' => (string) (($dictionarySources['vehicle_model']['id'] ?? '') ?: ($record['car_model'] ?? '')), 'endpoint_used' => (string) ($dictionarySources['vehicle_model']['endpoint_used'] ?? ''), 'derived_from' => 'vehicle_model'];
        }
        $fuel = (string) ($mapped['vehicle_fuel'] ?? ($rawFuelId === '' ? $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_fuel_name','fuel_name','fuel','car_fuel_text'])) : ''));
        $engineMarketing = $capacityL ? number_format($capacityL, 1) : '';
        $engineSource = 'derived_from_capacity_only';
        if ($carId === '458' && $fuel === 'Benzyna' && strtoupper($engineCode) === 'CZDA' && $engineMarketing !== '') { $engineMarketing .= ' TSI'; $engineSource = 'local_confirmed_from_ovoko_ui_for_car_458'; }
        $year = $this->first_non_empty_value($record, ['car_year','car_years','car_model_years','year','production_year']);
        return array_merge([
            'car_id' => $carId,
            'vehicle_make' => sanitize_text_field((string) ($mapped['vehicle_make'] ?? $directMake)),
            'vehicle_make_short' => (string) ($mapped['vehicle_make_short'] ?? ''),
            'vehicle_model' => sanitize_text_field((string) ($mapped['vehicle_model'] ?? $directModel)),
            'vehicle_generation' => sanitize_text_field((string) ($mapped['vehicle_generation'] ?? $directGeneration)),
            'vehicle_engine_marketing' => $engineMarketing,
            'vehicle_engine_marketing_source' => $engineSource,
            'vehicle_year' => (string) ($mapped['vehicle_year'] ?? sanitize_text_field((string) $year)),
            'vehicle_period' => (string) ($mapped['vehicle_period'] ?? sanitize_text_field((string) ($record['period'] ?? (($record['car_years'] ?? '') !== '' ? ((string)$record['car_years']).'--' : '')))),
            'vehicle_fuel' => sanitize_text_field($fuel),
            'vehicle_fuel_raw_id' => (string) ($mapped['vehicle_fuel_raw_id'] ?? $rawFuelId),
            'vehicle_fuel_resolved_label' => (string) ($mapped['vehicle_fuel_resolved_label'] ?? ($fuel !== '' ? $fuel : '')),
            'vehicle_fuel_endpoint_used' => (string) ($mapped['vehicle_fuel_endpoint_used'] ?? ''),
            'vehicle_fuel_matched_record' => (array) ($mapped['vehicle_fuel_matched_record'] ?? []),
            'vehicle_engine_capacity_cc' => $capacityCc > 0 ? $capacityCc : null,
            'vehicle_engine_capacity_l' => $capacityL,
            'vehicle_engine_power_kw' => sanitize_text_field((string) $this->first_non_empty_value($record, ['car_engine_power','engine_power_kw','power_kw'])),
            'vehicle_engine_code' => $engineCode,
            'vehicle_gearbox_type' => sanitize_text_field((string) ($mapped['vehicle_gearbox_type'] ?? $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_gearbox_type_name','gearbox_type_name','gearbox_type','transmission_type'])))),
            'vehicle_body_type' => sanitize_text_field((string) ($mapped['vehicle_body_type'] ?? $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_body_type_name','body_type_name','body_type'])))),
            'vehicle_drive_wheels' => sanitize_text_field((string) ($mapped['vehicle_drive_wheels'] ?? $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_wheel_drive_name','wheel_drive_name','drive_wheels','wheel_drive','drivetrain'])))),
            'vehicle_steering_position' => sanitize_text_field((string) ($this->first_non_empty_value($record, ['steering_position','car_steering_position']) ?: 'Lewa strona')),
            'vehicle_color' => sanitize_text_field((string) ($mapped['vehicle_color'] ?? $this->readable_vehicle_text($this->first_non_empty_value($record, ['car_color_name','color_name','color','car_color_text'])))),
            'vehicle_color_code' => sanitize_text_field((string) $this->first_non_empty_value($record, ['car_color_code','color_code','paint_code'])),
            'mileage_km' => (string) ($mapped['mileage_km'] ?? sanitize_text_field((string) $this->first_non_empty_value($record, ['car_mileage','mileage_km','mileage']))),
            'car_model_category_diagnostic' => isset($record['car_model_category']) && is_scalar($record['car_model_category']) ? sanitize_text_field((string) $record['car_model_category']) : '',
            'model_lookup_strategy' => (string) ($mapped['model_lookup_strategy'] ?? ''),
            'model_lookup_matched_brand_id' => (string) ($mapped['model_lookup_matched_brand_id'] ?? ''),
            'model_lookup_matched_record' => (array) ($mapped['model_lookup_matched_record'] ?? []),
            'brand_resolved_from_model_record' => !empty($mapped['brand_resolved_from_model_record']),
            'vehicle_dictionary_resolution_status' => $mapped !== [] ? ($dictionaryResolved !== [] ? 'dictionary_resolved' : 'partial_local_confirmed') : 'direct_payload_fields',
            'vehicle_dictionary_resolution_source' => $mapped !== [] ? ($dictionaryResolved !== [] ? 'dictionary_api_or_fallback' : 'local_confirmed_from_ovoko_ui') : 'vehicle_endpoint_payload',
            'vehicle_dictionary_field_sources' => $this->merge_local_vehicle_dictionary_sources($dictionarySources, $localMapped),
            'vehicle_data_status' => 'car_endpoint_confirmed',
            'raw_payload_summary' => ['keys' => array_values(array_map('strval', array_keys($record)))],
        ], []);
    }

    private function has_vehicle_fields(array $normalized): bool
    {
        foreach (['vehicle_make','vehicle_model','vehicle_generation','vehicle_year','vehicle_engine_marketing','vehicle_engine_capacity_l','vehicle_engine_capacity_cc','vehicle_fuel','vehicle_gearbox_type','vehicle_color','vehicle_drive_wheels'] as $k) { if (!empty($normalized[$k])) return true; }
        return false;
    }

    private function safe_sample(array $candidate): array
    {
        $out=[];
        foreach($candidate as $k=>$v){ if(is_scalar($v) && !in_array($k,['username','password','user_token'],true)){$out[(string)$k]=sanitize_text_field((string)$v);} }
        return $out;
    }


    private function dictionary_payload_records(array $payload): array
    {
        $records = [];
        $collect = function ($node) use (&$collect, &$records): void {
            if (!is_array($node)) { return; }
            if (!$this->is_list_array($node)) { $records[] = $node; }
            foreach ($node as $child) { if (is_array($child)) { $collect($child); } }
        };
        foreach ($payload as $child) {
            if (is_array($child)) { $collect($child); }
        }
        return $records;
    }

    private function dictionary_records_sample(array $records, int $limit = 5): array
    {
        $sample = [];
        foreach (array_slice($records, 0, $limit) as $record) {
            $sample[] = $this->safe_sample((array) $record);
        }
        return $sample;
    }

    private function raw_keys_sample(array $payload, array $records = []): array
    {
        return [
            'top_level_keys' => array_values(array_map('strval', array_keys($payload))),
            'first_record_keys' => isset($records[0]) && is_array($records[0]) ? array_values(array_map('strval', array_keys($records[0]))) : [],
        ];
    }

    private function inspect_dictionary_payload_for_id(array $payload, string $id): array
    {
        $records = $this->dictionary_payload_records($payload);
        foreach ($this->flatten_payload_paths($payload) as $path => $value) {
            if (!is_scalar($value)) { continue; }
            $pathParts = explode('.', (string) $path);
            $lastPathPart = (string) end($pathParts);
            if ($lastPathPart !== $id) { continue; }
            $label = $this->readable_vehicle_text($value);
            if ($label !== '') {
                return [
                    'found_record_with_id' => true,
                    'matched_record' => ['payload_path' => (string) $path, 'value' => sanitize_text_field((string) $value)],
                    'label_extraction_path' => (string) $path,
                    'resolved_label' => $label,
                ];
            }
        }
        foreach ($records as $record) {
            $record = (array) $record;
            $candidateId = $this->first_non_empty_value($record, ['id','value','key','model_id','manufacturer_id','brand_id','category_id','fuel_id','car_fuel_id','car_model_id','car_model_category','car_body_type_id']);
            if ($candidateId === '' || (string) $candidateId !== $id) { continue; }
            foreach (['name','title','label','value_text','text','manufacturer','brand','model','type','color','pl','en','lt'] as $labelKey) {
                if (!array_key_exists($labelKey, $record)) { continue; }
                $label = $this->readable_vehicle_text($record[$labelKey]);
                if ($label === '') { continue; }
                return [
                    'found_record_with_id' => true,
                    'matched_record' => $this->safe_sample($record),
                    'label_extraction_path' => $labelKey,
                    'resolved_label' => $label,
                ];
            }
            return [
                'found_record_with_id' => true,
                'matched_record' => $this->safe_sample($record),
                'label_extraction_path' => '',
                'resolved_label' => '',
            ];
        }
        return [
            'found_record_with_id' => false,
            'matched_record' => [],
            'label_extraction_path' => '',
            'resolved_label' => '',
        ];
    }

    private function probe_dictionary_endpoint(string $path, string $id): array
    {
        $raw = $this->post_form($path, [], true);
        $payload = (array) ($raw['payload'] ?? []);
        $records = $this->dictionary_payload_records($payload);
        $inspection = $this->inspect_dictionary_payload_for_id($payload, $id);
        return array_merge([
            'endpoint' => $path,
            'http_code' => $raw['http_code'] ?? null,
            'status_code' => (string) ($raw['status_code'] ?? ''),
            'success' => !empty($raw['success']),
            'msg' => (string) ($raw['msg'] ?? ''),
            'records_count' => count($records),
            'first_records_sample' => $this->dictionary_records_sample($records),
            'raw_keys_sample' => $this->raw_keys_sample($payload, $records),
        ], $inspection);
    }

    private function scan_car_model_dictionary_by_id_debug(string $id): array
    {
        $cacheKey = 'gpswiss_ovoko_car_model_debug_scan_' . md5($id);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) { return $cached; }

        $brandPath = '/get/car_brands';
        $brandEndpoint = $this->probe_dictionary_endpoint($brandPath, '');
        $brands = $this->post_form($brandPath, [], true);
        $brandPayload = (array) ($brands['payload'] ?? []);
        $brandIds = !empty($brands['success']) ? array_slice($this->extract_car_brand_ids_from_payload($brandPayload), 0, 500) : [];
        $modelEndpoints = [];
        $found = null;
        foreach ($brandIds as $brandId) {
            $path = '/get/car_models/' . rawurlencode($brandId);
            $endpoint = $this->probe_dictionary_endpoint($path, $id);
            $endpoint['brand_id'] = (string) $brandId;
            $modelEndpoints[] = $endpoint;
            if (!empty($endpoint['found_record_with_id']) && $found === null) {
                $found = [
                    'brand_id' => (string) $brandId,
                    'endpoint_used' => $path,
                    'matched_record' => (array) ($endpoint['matched_record'] ?? []),
                    'label_extraction_path' => (string) ($endpoint['label_extraction_path'] ?? ''),
                    'resolved_label' => (string) ($endpoint['resolved_label'] ?? ''),
                ];
            }
        }
        $result = [
            'brand_endpoint' => $brandEndpoint,
            'brand_ids_count' => count($brandIds),
            'brand_ids_sample' => array_slice($brandIds, 0, 20),
            'model_endpoint_checks_count' => count($modelEndpoints),
            'model_endpoints_checked' => $modelEndpoints,
            'found' => $found !== null,
            'found_at' => $found ?: [],
        ];
        set_transient($cacheKey, $result, HOUR_IN_SECONDS);
        return $result;
    }

    public function probe_ovoko_dictionary_value(string $dictionaryType, string $id): array
    {
        $requestedDictionaryType = strtolower(trim(str_replace(['-', ' '], '_', $dictionaryType)));
        $dictionaryType = $this->normalize_vehicle_dictionary_type($dictionaryType);
        $id = trim($id);
        if ($dictionaryType === '' || $id === '') {
            return [
                'ok' => false,
                'mode' => 'read_only',
                'action_name' => 'Probe Ovoko dictionary value',
                'requested_dictionary_type' => $requestedDictionaryType,
                'dictionary_type' => $dictionaryType,
                'id' => $id,
                'reason' => 'missing_dictionary_type_or_id',
                'no_write_to_woo' => true,
            ];
        }

        $paths = $this->official_vehicle_dictionary_paths($dictionaryType);
        $endpointReports = [];
        $endpointUsed = '';
        $matchedRecord = [];
        $labelExtractionPath = '';
        $resolvedLabel = '';
        foreach ($paths as $path) {
            $report = $this->probe_dictionary_endpoint($path, $id);
            $endpointReports[] = $report;
            if (!empty($report['found_record_with_id']) && $endpointUsed === '') {
                $endpointUsed = $path;
                $matchedRecord = (array) ($report['matched_record'] ?? []);
                $labelExtractionPath = (string) ($report['label_extraction_path'] ?? '');
                $resolvedLabel = (string) ($report['resolved_label'] ?? '');
            }
        }

        $allBrandModelScan = [];
        if ($dictionaryType === 'model') {
            $allBrandModelScan = $this->scan_car_model_dictionary_by_id_debug($id);
            if ($endpointUsed === '' && !empty($allBrandModelScan['found_at'])) {
                $foundAt = (array) $allBrandModelScan['found_at'];
                $endpointUsed = (string) ($foundAt['endpoint_used'] ?? '');
                $matchedRecord = (array) ($foundAt['matched_record'] ?? []);
                $labelExtractionPath = (string) ($foundAt['label_extraction_path'] ?? '');
                $resolvedLabel = (string) ($foundAt['resolved_label'] ?? '');
            }
        }

        $resolution = $this->resolve_vehicle_dictionary_value_with_source($dictionaryType, $id, []);
        if ($resolvedLabel === '' && (string) ($resolution['label'] ?? '') !== '') {
            $resolvedLabel = (string) $resolution['label'];
        }
        $source = $resolvedLabel !== '' ? (string) (($resolution['source'] ?? '') ?: 'dictionary_api_debug') : 'unresolved';
        if ($source !== 'unresolved' && $endpointUsed === '' && (string) ($resolution['endpoint_used'] ?? '') !== '') {
            $endpointUsed = (string) $resolution['endpoint_used'];
        }

        return [
            'ok' => $resolvedLabel !== '',
            'mode' => 'read_only',
            'action_name' => 'Probe Ovoko dictionary value',
            'requested_dictionary_type' => $requestedDictionaryType,
            'dictionary_type' => $dictionaryType,
            'id' => $id,
            'endpoints_checked' => array_values(array_unique(array_merge($paths, (array) ($resolution['endpoints_checked'] ?? [])))),
            'endpoint_reports' => $endpointReports,
            'endpoint_used' => $endpointUsed,
            'http_status_by_endpoint' => array_map(static fn(array $row): array => [
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'http_code' => $row['http_code'] ?? null,
                'status_code' => (string) ($row['status_code'] ?? ''),
                'success' => !empty($row['success']),
            ], $endpointReports),
            'records_count' => array_sum(array_map(static fn(array $row): int => (int) ($row['records_count'] ?? 0), $endpointReports)),
            'first_records_sample' => array_map(static fn(array $row): array => [
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'sample' => (array) ($row['first_records_sample'] ?? []),
            ], $endpointReports),
            'raw_keys_sample' => array_map(static fn(array $row): array => [
                'endpoint' => (string) ($row['endpoint'] ?? ''),
                'keys' => (array) ($row['raw_keys_sample'] ?? []),
            ], $endpointReports),
            'found_record_with_id' => $matchedRecord !== [],
            'matched_record' => $matchedRecord,
            'label_extraction_path' => $labelExtractionPath,
            'resolved_label' => $resolvedLabel,
            'resolved_make' => $dictionaryType === 'make' ? $resolvedLabel : '',
            'resolved_model' => $dictionaryType === 'model' ? $resolvedLabel : '',
            'resolved_generation' => $dictionaryType === 'generation' ? $resolvedLabel : ($dictionaryType === 'model' ? $resolvedLabel : ''),
            'source' => $source,
            'all_brands_model_scan' => $allBrandModelScan,
            'official_endpoints_checked' => $this->official_vehicle_dictionary_paths($dictionaryType),
            'model_record' => (array) ($resolution['model_record'] ?? []),
            'status' => $resolvedLabel !== '' ? 'resolved' : 'unresolved',
            'no_write_to_woo' => true,
            'checked_at' => gmdate('c'),
        ];
    }

    public function probe_ovoko_car_brands_models_raw(): array
    {
        $brandReport = $this->probe_dictionary_endpoint('/get/car_brands', '');
        $brand936Report = $this->probe_dictionary_endpoint('/get/car_brands', '936');
        $brand4Report = $this->probe_dictionary_endpoint('/get/car_brands', '4');
        $models936 = $this->probe_dictionary_endpoint('/get/car_models/936', '1585');
        $models936For22 = $this->probe_dictionary_endpoint('/get/car_models/936', '22');
        $models4 = $this->probe_dictionary_endpoint('/get/car_models/4', '22');
        $models4For1585 = $this->probe_dictionary_endpoint('/get/car_models/4', '1585');
        $scan1585 = $this->scan_car_model_dictionary_by_id_debug('1585');
        $scan22 = $this->scan_car_model_dictionary_by_id_debug('22');

        return [
            'ok' => true,
            'mode' => 'read_only',
            'action_name' => 'Probe Ovoko car brands/models raw',
            'car_brands' => [
                'endpoint' => '/get/car_brands',
                'http_code' => $brandReport['http_code'] ?? null,
                'status_code' => (string) ($brandReport['status_code'] ?? ''),
                'success' => !empty($brandReport['success']),
                'count' => (int) ($brandReport['records_count'] ?? 0),
                'sample_records' => (array) ($brandReport['first_records_sample'] ?? []),
                'raw_keys_sample' => (array) ($brandReport['raw_keys_sample'] ?? []),
                'contains_id_936' => !empty($brand936Report['found_record_with_id']),
                'id_936_record' => (array) ($brand936Report['matched_record'] ?? []),
                'id_936_resolved_label' => (string) ($brand936Report['resolved_label'] ?? ''),
                'contains_id_4' => !empty($brand4Report['found_record_with_id']),
                'id_4_record' => (array) ($brand4Report['matched_record'] ?? []),
                'id_4_resolved_label' => (string) ($brand4Report['resolved_label'] ?? ''),
            ],
            'car_models_936' => [
                'endpoint' => '/get/car_models/936',
                'http_code' => $models936['http_code'] ?? null,
                'status_code' => (string) ($models936['status_code'] ?? ''),
                'success' => !empty($models936['success']),
                'count' => (int) ($models936['records_count'] ?? 0),
                'sample_records' => (array) ($models936['first_records_sample'] ?? []),
                'raw_keys_sample' => (array) ($models936['raw_keys_sample'] ?? []),
                'contains_model_1585' => !empty($models936['found_record_with_id']),
                'model_1585_record' => (array) ($models936['matched_record'] ?? []),
                'model_1585_resolved_label' => (string) ($models936['resolved_label'] ?? ''),
                'contains_model_22' => !empty($models936For22['found_record_with_id']),
                'model_22_record' => (array) ($models936For22['matched_record'] ?? []),
                'model_22_resolved_label' => (string) ($models936For22['resolved_label'] ?? ''),
            ],
            'car_models_4' => [
                'endpoint' => '/get/car_models/4',
                'http_code' => $models4['http_code'] ?? null,
                'status_code' => (string) ($models4['status_code'] ?? ''),
                'success' => !empty($models4['success']),
                'count' => (int) ($models4['records_count'] ?? 0),
                'sample_records' => (array) ($models4['first_records_sample'] ?? []),
                'raw_keys_sample' => (array) ($models4['raw_keys_sample'] ?? []),
                'contains_model_22' => !empty($models4['found_record_with_id']),
                'model_22_record' => (array) ($models4['matched_record'] ?? []),
                'model_22_resolved_label' => (string) ($models4['resolved_label'] ?? ''),
                'contains_model_1585' => !empty($models4For1585['found_record_with_id']),
                'model_1585_record' => (array) ($models4For1585['matched_record'] ?? []),
                'model_1585_resolved_label' => (string) ($models4For1585['resolved_label'] ?? ''),
            ],
            'all_brand_scan_model_1585' => $scan1585,
            'all_brand_scan_model_22' => $scan22,
            'no_write_to_woo' => true,
            'checked_at' => gmdate('c'),
        ];
    }

    public function probe_vehicle_dictionary_endpoints(int $carId = 458): array
    {
        $carId = max(1, $carId);
        $expected = ['model' => '1936', 'model_category' => '25', 'fuel' => '2', 'gearbox_type' => '0', 'wheel_drive' => '1', 'body_type' => '4', 'color' => '5'];
        $paths = [
            '/get/car_models/25','/get/car_brands','/get/car/models','/get/car/model/1936','/get/car/model-categories','/get/car/model-category/25','/get/car/manufacturers','/get/car/manufacturer/25',
            '/get/car_model','/get/car_models','/get/model','/get/models','/get/car_model_category','/get/car_model_categories','/get/model_category','/get/model_categories','/get/manufacturer','/get/manufacturers','/get/car_manufacturer','/get/car_manufacturers',
            '/get/models','/get/model/1936','/get/model-categories','/get/model-category/25','/get/manufacturers','/get/manufacturer/25',
            '/get/car/fuels','/get/car/fuel/2','/get/fuels','/get/fuel/2','/get/car/gearbox-types','/get/car/gearbox-type/0','/get/gearbox-types','/get/gearbox-type/0',
            '/get/car/body-types','/get/car/body-type/4','/get/body-types','/get/body-type/4','/get/car/wheel-drives','/get/car/wheel-drive/1','/get/wheel-drives','/get/wheel-drive/1',
            '/get/car/colors','/get/car/color/5','/get/colors','/get/color/5'
        ];
        $v2=[]; foreach($paths as $path){ $v2[]='/v2'.$path; }
        $all=array_values(array_unique(array_merge($paths,$v2)));
        $rows=[];
        foreach($all as $path){
            $raw=$this->post_form($path,[],true); $payload=(array)($raw['payload']??[]);
            $record=$this->extract_candidate_record($payload);
            $keys=array_values(array_map('strval',array_keys($record)));
            $flat=$this->flatten_payload_paths($payload);
            $contains=false; foreach($expected as $v){ foreach($flat as $fv){ if((string)$fv===$v){$contains=true;break 2;} } }
            $resolved='';
            $sample=$this->safe_sample(is_array($record)?$record:[]);
            $sampleText=strtolower(wp_json_encode($sample));
            if (str_contains($sampleText,'touran')) $resolved='1936 => Touran / Touran III';
            elseif (str_contains($sampleText,'volkswagen')) $resolved='25 => Volkswagen';
            elseif (str_contains($sampleText,'benz')) $resolved='2 => Benzyna';
            $rows[]=['path'=>$path,'http_code'=>$raw['http_code']??null,'status_code'=>(string)($raw['status_code']??''),'success'=>!empty($raw['success']),'msg'=>(string)($raw['msg']??''),'response_top_level_keys'=>array_values(array_map('strval',array_keys($payload))),'record_keys'=>$keys,'sample'=>$sample,'contains_expected_id'=>$contains,'resolved_label'=>$resolved,'full_payload_omitted'=>true];
        }
        return ['ok'=>true,'car_id'=>$carId,'endpoints'=>$rows];
    }

    public function probe_category_endpoints(int $categoryId = 0): array
    {
        $paths = [
            '/get/categories','/get/category','/get/category/' . max(1, $categoryId),
            '/get/part/categories','/get/parts/categories','/get/categories/tree',
            '/crm/get/categories','/crm/export/categories',
        ];
        $v2 = [];
        foreach ($paths as $path) { $v2[] = '/v2' . $path; }
        $all = array_values(array_unique(array_merge($paths, $v2)));
        $rows = [];
        foreach ($all as $path) {
            $raw = $this->post_form($path, [], true);
            $payload = (array) ($raw['payload'] ?? []);
            $flat = $this->flatten_payload_paths($payload);
            $matched = [];
            foreach ($flat as $k => $v) {
                $lower = strtolower((string) $k);
                if (str_contains($lower, 'category') || str_contains($lower, 'path') || str_contains($lower, 'breadcrumb')) {
                    $matched[] = ['path' => $k, 'value' => is_scalar($v) ? sanitize_text_field((string) $v) : gettype($v)];
                }
            }
            $rows[] = [
                'path' => $path,
                'http_code' => $raw['http_code'] ?? null,
                'status_code' => (string) ($raw['status_code'] ?? ''),
                'success' => !empty($raw['success']),
                'msg' => (string) ($raw['msg'] ?? ''),
                'response_top_level_keys' => array_values(array_map('strval', array_keys($payload))),
                'category_related_matches' => array_slice($matched, 0, 40),
                'full_payload_omitted' => true,
            ];
        }
        return ['ok' => true, 'category_id' => $categoryId, 'endpoints' => $rows];
    }

    public function fetch_categories_tree_snapshot(): array
    {
        $path = '/get/categories/tree';
        $raw = $this->post_form($path, [], true);
        $payload = (array) ($raw['payload'] ?? []);
        $nodes = (!empty($raw['success']) && !empty($payload)) ? $this->extract_category_nodes($payload) : [];

        return [
            'ok' => !empty($raw['success']) && $nodes !== [],
            'endpoint' => $path,
            'http_code' => $raw['http_code'] ?? null,
            'status_code' => (string) ($raw['status_code'] ?? ''),
            'msg' => (string) ($raw['msg'] ?? ''),
            'payload_shape' => $this->describe_categories_payload_shape($payload),
            'nodes' => $nodes,
            'fetched_at' => gmdate('c'),
        ];
    }

    public function build_category_path_map_from_nodes(array $nodes): array
    {
        $byId = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $id = (string) ($node['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $node;
        }

        $map = [];
        foreach ($byId as $id => $node) {
            $chain = [];
            $visited = [];
            $cursor = $node;
            for ($i = 0; $i < 20; $i++) {
                $cursorId = (string) ($cursor['id'] ?? '');
                if ($cursorId === '' || isset($visited[$cursorId])) {
                    break;
                }
                $visited[$cursorId] = true;
                $chain[] = $cursor;
                $parentId = (string) ($cursor['parent_id'] ?? '');
                if ($parentId === '' || $parentId === '0' || !isset($byId[$parentId])) {
                    break;
                }
                $cursor = $byId[$parentId];
            }
            $chain = array_reverse($chain);
            $segments = array_values(array_filter(array_map(function ($n): string {
                return trim((string) ($n['pl'] ?? $n['title'] ?? $n['en'] ?? $n['name'] ?? $n['label'] ?? ''));
            }, $chain), static fn($v) => $v !== ''));
            $map[$id] = [
                'id' => $id,
                'node' => $node,
                'parent_chain' => $chain,
                'path' => implode(' / ', $segments),
            ];
        }

        return $map;
    }

    public function resolve_category_path_from_map(int $categoryId, array $categoryPathMap): array
    {
        $categoryId = max(1, $categoryId);
        $entry = $categoryPathMap[(string) $categoryId] ?? null;
        if (!is_array($entry)) {
            return [
                'ok' => false,
                'category_tree_endpoint_used' => 'snapshot:/get/categories/tree',
                'category_tree_node_found' => false,
                'category_tree_node' => null,
                'category_tree_parent_chain' => [],
                'category_tree_resolved_path' => '',
                'category_target_id' => $categoryId,
                'category_target_found' => false,
                'categories_total_loaded' => count($categoryPathMap),
            ];
        }

        return [
            'ok' => trim((string) ($entry['path'] ?? '')) !== '',
            'category_tree_endpoint_used' => 'snapshot:/get/categories/tree',
            'category_tree_node_found' => true,
            'category_tree_node' => $entry['node'] ?? null,
            'category_tree_parent_chain' => $entry['parent_chain'] ?? [],
            'category_tree_resolved_path' => (string) ($entry['path'] ?? ''),
            'category_tree_payload_fragment' => [],
            'categories_payload_shape' => [],
            'categories_sample_records' => [],
            'categories_sample_keys' => [],
            'category_target_id' => $categoryId,
            'categories_total_loaded' => count($categoryPathMap),
            'category_target_search_performed' => true,
            'category_target_found' => true,
            'category_target_node_if_found' => $entry['node'] ?? null,
            'category_target_nearby_records' => [],
            'category_target_parent_chain' => $entry['parent_chain'] ?? [],
            'category_target_resolved_path' => (string) ($entry['path'] ?? ''),
        ];
    }

    public function resolve_category_path_from_tree(int $categoryId): array
    {
        $categoryId = max(1, $categoryId);
        $candidates = ['/get/categories/tree', '/v2/get/categories/tree', '/get/categories', '/v2/get/categories'];
        $endpointDiagnostics = [];
        $selectedPayload = [];
        $selectedEndpoint = '';
        foreach ($candidates as $path) {
            $raw = $this->post_form($path, [], true);
            $payload = (array) ($raw['payload'] ?? []);
            $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];
            $sampleRecord = [];
            if ($list !== []) {
                $first = $list[0] ?? [];
                if (is_array($first) && isset($first[0]) && is_array($first[0])) {
                    $sampleRecord = (array) $first[0];
                } elseif (is_array($first)) {
                    $sampleRecord = (array) $first;
                }
            }
            $endpointDiagnostics[] = [
                'endpoint' => $path,
                'http_code' => $raw['http_code'] ?? null,
                'status_code' => (string) ($raw['status_code'] ?? ''),
                'success' => !empty($raw['success']),
                'payload_top_level_keys' => array_values(array_map('strval', array_keys($payload))),
                'list_structure' => [
                    'is_list_array' => is_array($payload['list'] ?? null),
                    'list_count' => count($list),
                    'first_item_type' => $list !== [] ? gettype($list[0]) : '',
                ],
                'sample_records_first_20' => array_slice($list, 0, 20),
                'sample_keys' => array_values(array_map('strval', array_keys($sampleRecord))),
            ];
            if (!empty($raw['success']) && !empty($payload)) {
                $selectedPayload = $payload;
                $selectedEndpoint = $path;
                break;
            }
        }

        if ($selectedEndpoint === '') {
            return [
                'ok' => false,
                'category_tree_endpoint_used' => '',
                'category_tree_node_found' => false,
                'category_tree_node' => null,
                'category_tree_parent_chain' => [],
                'category_tree_resolved_path' => '',
                'category_tree_payload_fragment' => [],
                'categories_payload_shape' => [],
                'categories_sample_records' => [],
                'categories_sample_keys' => [],
                'categories_endpoints_debug' => $endpointDiagnostics,
            ];
        }

        $nodes = $this->extract_category_nodes($selectedPayload);
        $categoryIdAsString = (string) $categoryId;
        $byId = [];
        $countNodesWithIdField = 0;
        $minCategoryId = null;
        $maxCategoryId = null;
        foreach ($nodes as $node) {
            if (!array_key_exists('id', $node)) {
                continue;
            }
            $countNodesWithIdField++;
            $rawId = (string) $node['id'];
            if ($rawId === '') {
                continue;
            }
            $asInt = (int) $rawId;
            $minCategoryId = $minCategoryId === null ? $asInt : min($minCategoryId, $asInt);
            $maxCategoryId = $maxCategoryId === null ? $asInt : max($maxCategoryId, $asInt);
            $byId[$rawId] = $node;
        }

        $target = $byId[$categoryIdAsString] ?? null;
        $nearbyRecords = [];
        for ($i = max(1, $categoryId - 2); $i <= ($categoryId + 3); $i++) {
            $k = (string) $i;
            if (isset($byId[$k])) {
                $nearbyRecords[$k] = $byId[$k];
            }
        }
        $dynamicTargetDebug = [
            'category_target_id' => $categoryId,
            'category_target_search_performed' => true,
            'category_target_found' => (bool) $target,
            'category_target_node_if_found' => $target ?: null,
            'category_target_nearby_records' => $nearbyRecords,
            'category_target_parent_chain' => [],
            'category_target_resolved_path' => '',
        ];
        if (!$target) {
            return [
                'ok' => false,
                'category_tree_endpoint_used' => $selectedEndpoint,
                'category_tree_node_found' => false,
                'category_tree_node' => null,
                'category_tree_parent_chain' => [],
                'category_tree_resolved_path' => '',
                'category_tree_payload_fragment' => [],
                'categories_payload_shape' => $this->describe_categories_payload_shape($selectedPayload),
                'categories_sample_records' => $this->extract_categories_sample_records($selectedPayload),
                'categories_sample_keys' => $this->extract_categories_sample_keys($selectedPayload),
                'category_target_id' => $categoryId,
                'categories_total_loaded' => count($nodes),
                'category_id_322_search_performed' => $categoryId === 322,
                'category_id_322_found' => $categoryId === 322 ? false : null,
                'category_id_322_node_if_found' => null,
                'category_id_322_nearby_records' => $categoryId === 322 ? $nearbyRecords : [],
                'category_target_search_performed' => $dynamicTargetDebug['category_target_search_performed'],
                'category_target_found' => $dynamicTargetDebug['category_target_found'],
                'category_target_node_if_found' => $dynamicTargetDebug['category_target_node_if_found'],
                'category_target_nearby_records' => $dynamicTargetDebug['category_target_nearby_records'],
                'category_target_parent_chain' => $dynamicTargetDebug['category_target_parent_chain'],
                'category_target_resolved_path' => $dynamicTargetDebug['category_target_resolved_path'],
                'count_nodes_with_id_field' => $countNodesWithIdField,
                'min_category_id' => $minCategoryId,
                'max_category_id' => $maxCategoryId,
                'categories_endpoints_debug' => $endpointDiagnostics,
            ];
        }

        $chain = [];
        $visited = [];
        $cursor = $target;
        for ($i = 0; $i < 20; $i++) {
            $id = (string) ($cursor['id'] ?? '');
            if ($id === '' || isset($visited[$id])) { break; }
            $visited[$id] = true;
            $chain[] = $cursor;
            $parentId = (string) ($cursor['parent_id'] ?? '');
            if ($parentId === '' || $parentId === '0') { break; }
            if (!isset($byId[$parentId])) { break; }
            $cursor = $byId[$parentId];
        }
        $chain = array_reverse($chain);
        $segments = array_values(array_filter(array_map(function ($n): string {
            return trim((string) ($n['pl'] ?? $n['en'] ?? $n['title'] ?? $n['name'] ?? $n['label'] ?? ''));
        }, $chain), static fn($v) => $v !== ''));
        $dynamicTargetDebug['category_target_parent_chain'] = $chain;
        $dynamicTargetDebug['category_target_resolved_path'] = implode(' / ', $segments);

        return [
            'ok' => !empty($segments),
            'category_tree_endpoint_used' => $selectedEndpoint,
            'category_tree_node_found' => true,
            'category_tree_node' => $target,
            'category_tree_parent_chain' => $chain,
            'category_tree_resolved_path' => implode(' / ', $segments),
            'category_tree_payload_fragment' => $this->build_category_tree_fragment_for_id($nodes, $categoryId),
            'categories_payload_shape' => $this->describe_categories_payload_shape($selectedPayload),
            'categories_sample_records' => $this->extract_categories_sample_records($selectedPayload),
            'categories_sample_keys' => $this->extract_categories_sample_keys($selectedPayload),
            'category_target_id' => $categoryId,
            'categories_total_loaded' => count($nodes),
            'category_id_322_search_performed' => $categoryId === 322,
            'category_id_322_found' => $categoryId === 322 ? true : null,
            'category_id_322_node_if_found' => $categoryId === 322 ? $target : null,
            'category_id_322_nearby_records' => $categoryId === 322 ? $nearbyRecords : [],
            'category_target_search_performed' => $dynamicTargetDebug['category_target_search_performed'],
            'category_target_found' => $dynamicTargetDebug['category_target_found'],
            'category_target_node_if_found' => $dynamicTargetDebug['category_target_node_if_found'],
            'category_target_nearby_records' => $dynamicTargetDebug['category_target_nearby_records'],
            'category_target_parent_chain' => $dynamicTargetDebug['category_target_parent_chain'],
            'category_target_resolved_path' => $dynamicTargetDebug['category_target_resolved_path'],
            'count_nodes_with_id_field' => $countNodesWithIdField,
            'min_category_id' => $minCategoryId,
            'max_category_id' => $maxCategoryId,
            'categories_endpoints_debug' => $endpointDiagnostics,
        ];
    }

    public function resolve_category_path_from_parts_categories(int $categoryId): array
    {
        $categoryId = max(1, $categoryId);
        $path = '/v2/get/parts/categories';
        $loaded = [];
        $matching = [];
        $pagesFetched = [];
        $totalCount = null;
        $limit = 200;
        $maxPages = 10;

        for ($page = 1; $page <= $maxPages; $page++) {
            $raw = $this->post_form($path, ['page' => $page, 'limit' => $limit], true);
            $payload = (array) ($raw['payload'] ?? []);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
            $totalCount = isset($pagination['total_count']) ? (int) $pagination['total_count'] : $totalCount;
            $pagesFetched[] = ['page' => $page, 'records' => count($data), 'pagination' => $pagination];
            if (empty($raw['success'])) { break; }
            if ($data === []) { break; }
            foreach ($data as $idx => $row) {
                if (!is_array($row)) { continue; }
                $loaded[] = $row;
                if ((int) ($row['category_id'] ?? 0) === $categoryId) {
                    $matching[] = ['index' => $idx, 'record' => $row];
                }
            }
            if ($totalCount !== null && count($loaded) >= $totalCount) { break; }
            if (count($data) < $limit) { break; }
        }

        $candidateParentFields = [];
        $candidateTitleFields = [];
        foreach ($matching as $m) {
            $flat = $this->flatten_nested_fields((array) $m['record'], 4, '', 1, 500);
            foreach ($flat as $k => $v) {
                $lk = strtolower((string) $k);
                if (str_contains($lk, 'parent')) { $candidateParentFields[$k] = $v; }
                if (str_contains($lk, 'title') || str_contains($lk, 'name') || str_contains($lk, 'path') || str_contains($lk, 'category')) { $candidateTitleFields[$k] = $v; }
            }
        }

        $resolvedPath = '';
        if (!empty($matching)) {
            $record = (array) $matching[0]['record'];
            $candidates = [
                $record['category_title_path'] ?? null,
                $record['title_path'] ?? null,
                $record['path'] ?? null,
                $record['breadcrumb'] ?? null,
                $record['full_path'] ?? null,
            ];
            foreach ($candidates as $candidate) {
                $v = trim((string) $candidate);
                if ($v !== '') { $resolvedPath = $v; break; }
            }
        }

        return [
            'ok' => $resolvedPath !== '',
            'parts_categories_endpoint_used' => $path,
            'parts_categories_total_records_loaded' => count($loaded),
            'parts_categories_pagination' => $pagesFetched,
            'parts_categories_records_matching_category_id' => $matching,
            'parts_categories_candidate_parent_fields' => $candidateParentFields,
            'parts_categories_candidate_title_fields' => $candidateTitleFields,
            'parts_categories_resolved_path' => $resolvedPath,
            'parts_categories_requires_more_pages' => $totalCount !== null ? (count($loaded) < $totalCount) : false,
        ];
    }

    private function extract_category_nodes(array $payload): array
    {
        if ($payload === []) {
            return [];
        }
        $nodes = [];
        $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? $row['category_id'] ?? '');
            if (trim($id) === '') { continue; }
            $parentId = (string) ($row['parent_id'] ?? $row['parent'] ?? '0');
            $title = (string) (
                ($row['pl'] ?? null)
                ?? ($row['en'] ?? null)
                ?? ($row['title'] ?? null)
                ?? ($row['name'] ?? null)
                ?? ($row['label'] ?? null)
                ?? ($row['category_title'] ?? null)
                ?? ''
            );
            $nodes[] = [
                'id' => sanitize_text_field($id),
                'parent_id' => sanitize_text_field($parentId),
                'title' => sanitize_text_field($title),
                'pl' => sanitize_text_field((string) ($row['pl'] ?? '')),
                'en' => sanitize_text_field((string) ($row['en'] ?? '')),
                'level' => sanitize_text_field((string) ($row['level'] ?? '')),
            ];
        }
        return $nodes;
    }

    private function describe_categories_payload_shape(array $payload): array
    {
        $list = $payload['list'] ?? null;
        return [
            'top_level_keys' => array_values(array_map('strval', array_keys($payload))),
            'has_list' => is_array($list),
            'list_count' => is_array($list) ? count($list) : 0,
            'list_first_item_type' => is_array($list) && $list !== [] ? gettype($list[0]) : '',
        ];
    }

    private function extract_categories_sample_records(array $payload): array
    {
        $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];
        return array_slice($list, 0, 20);
    }

    private function extract_categories_sample_keys(array $payload): array
    {
        $list = is_array($payload['list'] ?? null) ? $payload['list'] : [];
        if ($list === []) {
            return [];
        }
        $first = $list[0] ?? [];
        if (is_array($first) && isset($first[0]) && is_array($first[0])) {
            return array_values(array_map('strval', array_keys((array) $first[0])));
        }
        if (is_array($first)) {
            return array_values(array_map('strval', array_keys((array) $first)));
        }
        return [];
    }

    private function flatten_nested_fields($payload, int $maxDepth = 8, string $prefix = '', int $depth = 1, int $maxFields = 5000, int &$fieldCount = 0): array
    {
        if ($depth > $maxDepth || $fieldCount >= $maxFields) {
            return [];
        }

        if (is_object($payload)) {
            $payload = (array) $payload;
        }

        if (!is_array($payload)) {
            if ($prefix === '') {
                return [];
            }
            $fieldCount++;
            return [$prefix => $payload];
        }

        $out = [];
        foreach ($payload as $key => $value) {
            if ($fieldCount >= $maxFields) {
                break;
            }

            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value) || is_object($value)) {
                $out += $this->flatten_nested_fields($value, $maxDepth, $path, $depth + 1, $maxFields, $fieldCount);
                continue;
            }

            $fieldCount++;
            $out[$path] = $value;
        }

        return $out;
    }

    private function build_category_tree_fragment_for_id(array $nodes, int $categoryId): array
    {
        $byId = [];
        foreach ($nodes as $node) { $byId[(int) $node['id']] = $node; }
        if (!isset($byId[$categoryId])) { return []; }
        $target = $byId[$categoryId];
        $parentId = (int) ($target['parent_id'] ?? 0);
        $siblings = [];
        foreach ($nodes as $node) {
            if ((int) ($node['parent_id'] ?? 0) === $parentId) { $siblings[] = $node; }
        }
        return ['target' => $target, 'siblings_with_same_parent' => array_values($siblings)];
    }

    private function value_from_path(array $payload, string $path)
    {
        $node = $payload;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) { return null; }
            $node = $node[$part];
        }
        return $node;
    }

    private function build_vehicle_raw_diagnostics(array $record, array $normalized = []): array
    {
        $rawFields = [];
        foreach ([
            'make' => ['car_make','make','manufacturer','brand','car_manufacturer','car_model_category'],
            'model' => ['car_model','model','model_id','car_model_id'],
            'modification' => ['car_modification','modification','modification_id','car_modification_id','car_model_category','generation','generation_id'],
            'generation' => ['car_model_category','model_category','model_category_id','generation','generation_id'],
            'fuel' => ['car_fuel','fuel','fuel_id','car_fuel_id'],
            'gearbox' => ['car_gearbox_type','gearbox_type','gearbox_type_id','transmission_type','car_gearbox_code'],
            'color' => ['car_color','color','color_id','car_color_id','car_color_code','color_code'],
            'wheel_drive' => ['car_wheel_drive','wheel_drive','wheel_drive_id','drive_wheels','drivetrain'],
        ] as $group => $keys) {
            $rawFields[$group] = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $record) && (is_scalar($record[$key]) || $record[$key] === null)) {
                    $rawFields[$group][$key] = is_string($record[$key]) ? sanitize_text_field($record[$key]) : $record[$key];
                }
            }
        }

        $normalizedReadable = array_intersect_key($normalized, array_flip(['vehicle_make','vehicle_make_short','vehicle_model','vehicle_generation','vehicle_year','vehicle_period','vehicle_engine_capacity_cc','vehicle_engine_capacity_l','vehicle_fuel','vehicle_fuel_raw_id','vehicle_fuel_resolved_label','vehicle_fuel_endpoint_used','vehicle_fuel_matched_record','vehicle_gearbox_type','vehicle_color','vehicle_color_code','vehicle_drive_wheels','car_model_category_diagnostic','model_lookup_strategy','model_lookup_matched_brand_id','model_lookup_matched_record','brand_resolved_from_model_record']));
        $warnings = [];
        foreach (['vehicle_make','vehicle_model'] as $required) {
            if (trim((string) ($normalizedReadable[$required] ?? '')) === '') {
                $warnings[] = $required . '_unresolved_title_builder_will_use_fallback';
            }
        }

        return [
            'vehicle_raw_keys_sample' => array_slice(array_values(array_map('strval', array_keys($record))), 0, 80),
            'vehicle_raw_preview_redacted' => $this->redacted_vehicle_preview($record),
            'raw_vehicle_identity_fields' => $rawFields,
            'dictionary_id_candidates' => $this->extract_vehicle_dictionary_id_candidates($record),
            'normalized_readable_fields' => $normalizedReadable,
            'dictionary_field_sources' => (array) ($normalized['vehicle_dictionary_field_sources'] ?? []),
            'warnings' => $warnings,
            'fallback_vehicle_slug_if_unresolved' => (($warnings !== [] && !empty($normalized['car_id'])) ? ('vehicle-' . sanitize_title((string) $normalized['car_id'])) : ''),
            'full_payload_omitted' => true,
        ];
    }

    private function redacted_vehicle_preview(array $record): array
    {
        $preview = [];
        $sensitiveHints = ['vin', 'body_number', 'engine_number', 'registration', 'plate'];
        foreach ($record as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $keyString = (string) $key;
            $lower = strtolower($keyString);
            $valueString = is_string($value) ? sanitize_text_field($value) : $value;
            foreach ($sensitiveHints as $hint) {
                if (str_contains($lower, $hint)) {
                    $valueString = $value === '' || $value === null ? '' : '[redacted]';
                    break;
                }
            }
            $preview[$keyString] = $valueString;
            if (count($preview) >= 60) {
                break;
            }
        }
        return $preview;
    }

    private function extract_vehicle_dictionary_id_candidates(array $record): array
    {
        $map = [
            'make_id' => ['make_id','manufacturer_id','brand_id','car_make','car_manufacturer'],
            'model_id' => ['model_id','car_model_id','car_model','model'],
            'modification_id' => ['modification_id','car_modification_id','car_modification','modification'],
            'generation_id' => ['generation_id','model_category_id','generation','model_category'],
            'fuel_id' => ['fuel_id','car_fuel_id','car_fuel','fuel'],
            'gearbox_id' => ['gearbox_type_id','car_gearbox_type_id','car_gearbox_type','gearbox_type','transmission_type'],
            'wheel_drive_id' => ['wheel_drive_id','car_wheel_drive_id','car_wheel_drive','wheel_drive','drive_wheels'],
            'color_id' => ['color_id','car_color_id','car_color','color'],
            'body_type_id' => ['body_type_id','car_body_type_id','car_body_type','body_type'],
        ];
        $out = [];
        foreach ($map as $label => $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $record) || !is_scalar($record[$key])) {
                    continue;
                }
                $value = trim((string) $record[$key]);
                if ($value === '' || !$this->looks_like_dictionary_id($value)) {
                    continue;
                }
                $out[$label][$key] = sanitize_text_field($value);
            }
        }
        return $out;
    }

    private function looks_like_dictionary_id(string $value): bool
    {
        return preg_match('/^-?\d+$/', trim($value)) === 1;
    }

    private function readable_vehicle_text($value): string
    {
        $text = trim(sanitize_text_field((string) $value));
        if ($text === '' || $this->looks_like_dictionary_id($text)) {
            return '';
        }
        return $text;
    }

    private function normalize_vehicle_dictionary_type(string $type): string
    {
        $type = strtolower(trim($type));
        $type = str_replace(['-', ' '], '_', $type);
        return match ($type) {
            'car_model', 'model' => 'model',
            'car_model_category', 'model_category' => 'generation',
            'manufacturer_category', 'car_make_category' => '',
            'generation', 'modification' => 'generation',
            'car_fuel', 'fuel' => 'fuel',
            'car_gearbox_type', 'gearbox', 'gearbox_type', 'transmission_type' => 'gearbox',
            'car_wheel_drive', 'wheel_drive', 'drive_wheels', 'drivetrain' => 'wheel_drive',
            'car_color', 'color' => 'color',
            'car_body_type', 'body_type' => 'body_type',
            'make', 'brand', 'manufacturer', 'car_brand', 'car_manufacturer' => 'make',
            default => '',
        };
    }

    private function official_vehicle_dictionary_paths(string $type, array $context = []): array
    {
        $type = $this->normalize_vehicle_dictionary_type($type);
        $brandCandidates = [];
        foreach (['brand_id','make_id','manufacturer_id','car_make','car_manufacturer'] as $key) {
            if (!empty($context[$key]) && is_scalar($context[$key]) && $this->looks_like_dictionary_id((string) $context[$key])) {
                $brandCandidates[] = (string) $context[$key];
            }
        }
        $brandCandidates = array_values(array_unique($brandCandidates));
        return match ($type) {
            'make' => ['/get/car_brands'],
            'model' => $brandCandidates !== [] ? array_map(static fn($brandId) => '/get/car_models/' . rawurlencode($brandId), $brandCandidates) : [],
            'generation' => [],
            'fuel' => ['/get/fuel'],
            'gearbox' => ['/get/gearbox_type'],
            'wheel_drive' => ['/get/wheel_drive'],
            'color' => ['/get/color'],
            'body_type' => ['/get/car_body_type'],
            default => [],
        };
    }

    private function fallback_vehicle_dictionary_label(string $type, string $id): string
    {
        $type = $this->normalize_vehicle_dictionary_type($type);
        $maps = [
            'gearbox' => ['0' => 'Mechaniczna', '1' => 'Automatyczna'],
            'wheel_drive' => ['1' => 'Przód', '2' => 'Tył', '3' => 'AWD'],
            'body_type' => ['1' => 'Sedan', '2' => 'Kombi', '4' => 'Minivan'],
            'color' => ['5' => 'Niebieski', '9' => 'Szary', '10' => 'Czarny'],
        ];
        return (string) ($maps[$type][$id] ?? '');
    }

    private function resolve_vehicle_dictionary_value(string $type, string $id, array $context = []): string
    {
        $resolved = $this->resolve_vehicle_dictionary_value_with_source($type, $id, $context);
        return (string) ($resolved['label'] ?? '');
    }

    private function resolve_vehicle_dictionary_value_with_source(string $type, string $id, array $context = []): array
    {
        $type = $this->normalize_vehicle_dictionary_type($type);
        $id = trim($id);
        if ($type === '' || $id === '' || !$this->looks_like_dictionary_id($id)) {
            return ['label' => '', 'source' => 'unresolved', 'endpoint_used' => ''];
        }

        $cacheKey = 'gpswiss_ovoko_vehicle_dict_v4_' . md5($type . ':' . $id . ':' . wp_json_encode($context));
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $endpointsChecked = [];
        $rawKeys = [];
        foreach ($this->official_vehicle_dictionary_paths($type, $context) as $path) {
            $endpointsChecked[] = $path;
            $raw = $this->post_form($path, [], true);
            if (empty($raw['success'])) {
                continue;
            }
            $payload = (array) ($raw['payload'] ?? []);
            $rawKeys[$path] = array_values(array_map('strval', array_keys($payload)));
            $inspection = $this->inspect_dictionary_payload_for_id($payload, $id);
            $label = (string) ($inspection['resolved_label'] ?? '');
            if ($label !== '') {
                $record = (array) ($inspection['matched_record'] ?? []);
                if ($record === []) { $record = $this->extract_dictionary_record_from_payload($payload, $id); }
                $resolved = ['label' => $label, 'source' => 'dictionary_api', 'endpoint_used' => $path, 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys, 'dictionary_record' => $record, 'matched_record' => $record];
                if ($type === 'model') {
                    $resolved['model_record'] = $record;
                    $brandFromRecord = $this->first_non_empty_value($record, ['brand','brand_id','manufacturer_id','make_id']);
                    if ($brandFromRecord !== '') { $resolved['model_lookup_matched_brand_id'] = (string) $brandFromRecord; }
                }
                if ($type === 'fuel') {
                    $resolved['raw_fuel_id'] = $id;
                    $resolved['resolved_fuel_label'] = $label;
                    $resolved['fuel_matched_record'] = $record;
                }
                set_transient($cacheKey, $resolved, DAY_IN_SECONDS);
                return $resolved;
            }
        }

        if ($type === 'model') {
            $scan = $this->scan_car_model_dictionary_by_id($id);
            $endpointsChecked = array_values(array_unique(array_merge($endpointsChecked, (array) ($scan['endpoints_checked'] ?? []))));
            $rawKeys = array_merge($rawKeys, (array) ($scan['raw_keys'] ?? []));
            if ((string) ($scan['label'] ?? '') !== '') {
                $resolved = [
                    'label' => (string) $scan['label'],
                    'source' => 'dictionary_api',
                    'endpoint_used' => (string) ($scan['endpoint_used'] ?? ''),
                    'endpoints_checked' => $endpointsChecked,
                    'raw_keys' => $rawKeys,
                    'model_record' => (array) ($scan['model_record'] ?? []),
                    'dictionary_record' => (array) ($scan['model_record'] ?? []),
                    'model_lookup_strategy' => 'all_brand_scan_by_model_id',
                    'model_lookup_matched_brand_id' => (string) ($scan['model_lookup_matched_brand_id'] ?? ''),
                ];
                set_transient($cacheKey, $resolved, DAY_IN_SECONDS);
                return $resolved;
            }
        }

        $csv = $this->resolve_vehicle_dictionary_from_csv_mapping($type, $id);
        if ((string) ($csv['label'] ?? '') !== '') {
            $resolved = ['label' => (string) $csv['label'], 'source' => 'csv_mapping', 'endpoint_used' => '', 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys, 'csv_mapping_match' => (array) ($csv['match'] ?? [])];
            set_transient($cacheKey, $resolved, DAY_IN_SECONDS);
            return $resolved;
        }

        $fallback = $this->fallback_vehicle_dictionary_label($type, $id);
        if ($fallback !== '') {
            $resolved = ['label' => $fallback, 'source' => 'local_fallback', 'endpoint_used' => '', 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys];
            set_transient($cacheKey, $resolved, DAY_IN_SECONDS);
            return $resolved;
        }

        $resolved = ['label' => '', 'source' => 'unresolved', 'endpoint_used' => '', 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys];
        set_transient($cacheKey, $resolved, HOUR_IN_SECONDS);
        return $resolved;
    }

    private function extract_dictionary_record_from_payload(array $payload, string $id): array
    {
        $candidates = [];
        $collect = function ($node) use (&$collect, &$candidates): void {
            if (!is_array($node)) { return; }
            if (!$this->is_list_array($node)) { $candidates[] = $node; }
            foreach ($node as $child) { if (is_array($child)) { $collect($child); } }
        };
        $collect($payload);
        foreach ($candidates as $candidate) {
            $candidateId = $this->first_non_empty_value($candidate, ['id','value','key','model_id','manufacturer_id','brand_id','category_id','fuel_id','car_fuel_id','car_model_id','car_model_category','car_body_type_id']);
            if ($candidateId !== '' && (string) $candidateId === $id) {
                return $this->safe_sample($candidate);
            }
        }
        return [];
    }

    private function extract_car_brand_ids_from_payload(array $payload): array
    {
        $ids = [];
        $collect = function ($node) use (&$collect, &$ids): void {
            if (!is_array($node)) { return; }
            if (!$this->is_list_array($node)) {
                $id = $this->first_non_empty_value($node, ['id','brand_id','manufacturer_id','car_model_category']);
                if ($id !== '' && $this->looks_like_dictionary_id((string) $id)) { $ids[] = (string) $id; }
            }
            foreach ($node as $child) { if (is_array($child)) { $collect($child); } }
        };
        $collect($payload);
        return array_values(array_unique($ids));
    }

    private function scan_car_model_dictionary_by_id(string $id): array
    {
        $cacheKey = 'gpswiss_ovoko_car_model_all_brand_scan_v2_' . md5($id);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) { return $cached; }

        $brandPath = '/get/car_brands';
        $endpointsChecked = [$brandPath];
        $rawKeys = [];
        $brands = $this->post_form($brandPath, [], true);
        $brandPayload = (array) ($brands['payload'] ?? []);
        $rawKeys[$brandPath] = array_values(array_map('strval', array_keys($brandPayload)));
        if (empty($brands['success'])) {
            $result = ['label' => '', 'endpoint_used' => '', 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys, 'model_lookup_strategy' => 'all_brand_scan_by_model_id', 'model_lookup_matched_brand_id' => '', 'model_record' => []];
            set_transient($cacheKey, $result, HOUR_IN_SECONDS);
            return $result;
        }
        $brandIds = array_slice($this->extract_car_brand_ids_from_payload($brandPayload), 0, 500);
        foreach ($brandIds as $brandId) {
            $path = '/get/car_models/' . rawurlencode($brandId);
            $endpointsChecked[] = $path;
            $raw = $this->post_form($path, [], true);
            $payload = (array) ($raw['payload'] ?? []);
            $rawKeys[$path] = array_values(array_map('strval', array_keys($payload)));
            if (empty($raw['success'])) { continue; }
            $label = $this->extract_dictionary_label_from_payload($payload, $id);
            if ($label !== '') {
                $record = $this->extract_dictionary_record_from_payload($payload, $id);
                $matchedBrandId = (string) ($this->first_non_empty_value($record, ['brand','brand_id','manufacturer_id','make_id']) ?: $brandId);
                $result = [
                    'label' => $label,
                    'endpoint_used' => $path,
                    'endpoints_checked' => $endpointsChecked,
                    'raw_keys' => $rawKeys,
                    'model_record' => $record,
                    'model_lookup_strategy' => 'all_brand_scan_by_model_id',
                    'model_lookup_matched_brand_id' => $matchedBrandId,
                ];
                set_transient($cacheKey, $result, DAY_IN_SECONDS);
                return $result;
            }
        }
        $result = ['label' => '', 'endpoint_used' => '', 'endpoints_checked' => $endpointsChecked, 'raw_keys' => $rawKeys, 'model_lookup_strategy' => 'all_brand_scan_by_model_id', 'model_lookup_matched_brand_id' => '', 'model_record' => []];
        set_transient($cacheKey, $result, HOUR_IN_SECONDS);
        return $result;
    }

    private function resolve_vehicle_dictionary_from_csv_mapping(string $type, string $id): array
    {
        $csvMap = (array) ($this->settings['ovoko_csv_mapping'] ?? []);
        if ($csvMap === []) { return ['label' => '', 'match' => []]; }
        foreach ($csvMap as $rows) {
            foreach ((array) $rows as $row) {
                if (!is_array($row)) { continue; }
                $row = (array) $row;
                $vehicleInfo = (string) $this->first_non_empty_value($row, ['vehicle_info','automobilis','vehicle','car','model']);
                $parsed = $this->parse_vehicle_info_label_for_csv_dictionary($vehicleInfo);
                if ($type === 'make') {
                    $candidateId = (string) $this->first_non_empty_value($row, ['car_model_category','model_category','brand_id','manufacturer_id','make_id']);
                    $label = (string) ($parsed['make'] ?? '');
                    if ($candidateId === $id && $label !== '') { return ['label' => $label, 'match' => ['id' => (string) ($row['id'] ?? ''), 'vehicle_info' => $vehicleInfo]]; }
                }
                if ($type === 'model' || $type === 'generation') {
                    $candidateId = (string) $this->first_non_empty_value($row, ['car_model','model_id','car_model_id']);
                    $label = (string) ($parsed['model'] ?? '');
                    if ($candidateId === $id && $label !== '') { return ['label' => $label, 'match' => ['id' => (string) ($row['id'] ?? ''), 'vehicle_info' => $vehicleInfo]]; }
                }
            }
        }
        return ['label' => '', 'match' => []];
    }

    private function parse_vehicle_info_label_for_csv_dictionary(string $vehicleInfo): array
    {
        $raw = trim($vehicleInfo);
        if ($raw === '') { return ['make' => '', 'model' => '']; }
        preg_match('/^([^,(]+)(?:\s*\(([^)]*)\))?/u', $raw, $base);
        $label = trim((string) ($base[1] ?? $raw));
        $known = ['Mercedes-Benz','Volkswagen','VW','Audi','BMW','Peugeot','Citroen','Citroën','Renault','Opel','Ford','Toyota','Nissan','Hyundai','Kia','Fiat','Volvo','Skoda','Škoda','Seat'];
        foreach ($known as $brand) {
            if (stripos($label, $brand) === 0) {
                return ['make' => $brand === 'VW' ? 'Volkswagen' : $brand, 'model' => trim(substr($label, strlen($brand)))];
            }
        }
        return ['make' => '', 'model' => $this->readable_vehicle_text($label)];
    }

    private function extract_dictionary_label_from_payload(array $payload, string $id): string
    {
        $candidates = [];
        $collect = function ($node) use (&$collect, &$candidates): void {
            if (!is_array($node)) {
                return;
            }
            if (!$this->is_list_array($node)) {
                $candidates[] = $node;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($payload);
        foreach ($this->flatten_payload_paths($payload) as $path => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $pathParts = explode('.', (string) $path);
            $lastPathPart = (string) end($pathParts);
            if ($lastPathPart !== $id) {
                continue;
            }
            $label = $this->readable_vehicle_text($value);
            if ($label !== '') {
                return $label;
            }
        }
        foreach ($candidates as $candidate) {
            $candidateId = $this->first_non_empty_value($candidate, ['id','value','key','model_id','manufacturer_id','brand_id','category_id','fuel_id','car_fuel_id','car_model_id','car_model_category','car_body_type_id']);
            if ($candidateId !== '' && (string) $candidateId !== $id) {
                continue;
            }
            $label = $this->first_non_empty_value($candidate, ['name','title','label','value_text','text','manufacturer','brand','model','type','color','pl','en','lt']);
            $label = $this->readable_vehicle_text($label);
            if ($label !== '') {
                return $label;
            }
        }
        return '';
    }

    private function resolve_vehicle_dictionaries(array $record): array
    {
        $ids = $this->extract_vehicle_dictionary_id_candidates($record);
        $resolved = [];
        $sources = [];
        $brandResolvedFromModelRecord = false;

        foreach (($ids['model_id'] ?? []) as $modelId) {
            $resolution = $this->resolve_vehicle_dictionary_value_with_source('model', (string) $modelId, []);
            if ((string) ($resolution['label'] ?? '') === '') {
                continue;
            }

            $modelRecord = (array) ($resolution['model_record'] ?? $resolution['dictionary_record'] ?? []);
            $matchedBrandId = (string) ($resolution['model_lookup_matched_brand_id'] ?? $this->first_non_empty_value($modelRecord, ['brand','brand_id','manufacturer_id','make_id']));
            $resolved['vehicle_model'] = (string) $resolution['label'];
            $resolved['vehicle_generation'] = (string) $resolution['label'];
            $resolved['model_lookup_strategy'] = 'all_brand_scan_by_model_id';
            $resolved['model_lookup_matched_brand_id'] = $matchedBrandId;
            $resolved['model_lookup_matched_record'] = $modelRecord;
            $sources['vehicle_model'] = [
                'source' => (string) ($resolution['source'] ?? 'dictionary_api'),
                'id' => (string) $modelId,
                'endpoint_used' => (string) ($resolution['endpoint_used'] ?? ''),
                'model_lookup_strategy' => 'all_brand_scan_by_model_id',
                'matched_brand_id' => $matchedBrandId,
            ];
            $sources['vehicle_generation'] = $sources['vehicle_model'] + ['derived_from' => 'vehicle_model'];

            if (empty($resolved['vehicle_period'])) {
                $yearStart = (string) ($modelRecord['year_start'] ?? '');
                $yearEnd = (string) ($modelRecord['year_end'] ?? '');
                if ($yearStart !== '' || $yearEnd !== '') {
                    $resolved['vehicle_period'] = $yearStart . '--' . $yearEnd;
                }
            }

            if ($matchedBrandId !== '') {
                $brandResolution = $this->resolve_vehicle_dictionary_value_with_source('make', $matchedBrandId, []);
                if ((string) ($brandResolution['label'] ?? '') !== '') {
                    $resolved['vehicle_make'] = (string) $brandResolution['label'];
                    $brandRecord = (array) ($brandResolution['dictionary_record'] ?? []);
                    $short = $this->extract_vehicle_make_short_from_brand_record($brandRecord);
                    if ($short !== '') { $resolved['vehicle_make_short'] = $short; }
                    $brandResolvedFromModelRecord = true;
                    $sources['vehicle_make'] = [
                        'source' => (string) ($brandResolution['source'] ?? 'dictionary_api'),
                        'id' => $matchedBrandId,
                        'endpoint_used' => (string) ($brandResolution['endpoint_used'] ?? ''),
                        'derived_from' => 'model_record.brand',
                    ];
                    if ($short !== '') {
                        $sources['vehicle_make_short'] = $sources['vehicle_make'] + ['derived_from' => 'brand_record'];
                    }
                }
            }
            break;
        }
        $resolved['brand_resolved_from_model_record'] = $brandResolvedFromModelRecord;

        $fields = [
            'make' => ['vehicle_make', $ids['make_id'] ?? []],
            'model' => ['vehicle_model', $ids['model_id'] ?? []],
            'generation' => ['vehicle_generation', ($ids['modification_id'] ?? []) + ($ids['generation_id'] ?? [])],
            'fuel' => ['vehicle_fuel', $ids['fuel_id'] ?? []],
            'gearbox' => ['vehicle_gearbox_type', $ids['gearbox_id'] ?? []],
            'wheel_drive' => ['vehicle_drive_wheels', $ids['wheel_drive_id'] ?? []],
            'color' => ['vehicle_color', $ids['color_id'] ?? []],
            'body_type' => ['vehicle_body_type', $ids['body_type_id'] ?? []],
        ];
        foreach ($fields as $type => [$target, $candidates]) {
            if (!empty($resolved[$target])) {
                continue;
            }
            $sources[$target] = ['source' => 'unresolved', 'id' => '', 'endpoint_used' => ''];
            foreach ($candidates as $id) {
                $resolution = $this->resolve_vehicle_dictionary_value_with_source((string) $type, (string) $id, $record);
                if ((string) ($resolution['label'] ?? '') !== '') {
                    $resolved[$target] = (string) $resolution['label'];
                    $sources[$target] = [
                        'source' => (string) ($resolution['source'] ?? 'unresolved'),
                        'id' => (string) $id,
                        'endpoint_used' => (string) ($resolution['endpoint_used'] ?? ''),
                    ];
                    if ($type === 'fuel') {
                        $matchedRecord = (array) ($resolution['fuel_matched_record'] ?? $resolution['matched_record'] ?? $resolution['dictionary_record'] ?? []);
                        $resolved['vehicle_fuel_raw_id'] = (string) $id;
                        $resolved['vehicle_fuel_resolved_label'] = (string) $resolution['label'];
                        $resolved['vehicle_fuel_endpoint_used'] = (string) ($resolution['endpoint_used'] ?? '');
                        $resolved['vehicle_fuel_matched_record'] = $matchedRecord;
                        $sources[$target]['matched_record'] = $matchedRecord;
                    }
                    break;
                }
                $sources[$target]['id'] = (string) $id;
                if ($type === 'fuel' && empty($resolved['vehicle_fuel_raw_id'])) {
                    $resolved['vehicle_fuel_raw_id'] = (string) $id;
                    $resolved['vehicle_fuel_resolved_label'] = '';
                    $resolved['vehicle_fuel_endpoint_used'] = (string) (((array) ($resolution['endpoints_checked'] ?? []))[0] ?? '');
                    $resolved['vehicle_fuel_matched_record'] = [];
                    $sources[$target]['endpoint_used'] = $resolved['vehicle_fuel_endpoint_used'];
                }
            }
        }
        if ($sources !== []) {
            $resolved['_vehicle_dictionary_field_sources'] = $sources;
        }
        return $resolved;
    }

    private function extract_vehicle_make_short_from_brand_record(array $record): string
    {
        foreach (['short_name','name_short','short','code','abbr','abbreviation'] as $key) {
            if (!array_key_exists($key, $record)) { continue; }
            $value = $this->readable_vehicle_text($record[$key]);
            if ($value !== '') { return $value; }
        }
        return '';
    }

    private function merge_local_vehicle_dictionary_sources(array $sources, array $localMapped): array
    {
        foreach ($localMapped as $field => $_value) {
            if (str_starts_with((string) $field, 'vehicle_')) {
                $sources[(string) $field] = ['source' => 'local_fallback', 'id' => '', 'endpoint_used' => ''];
            }
        }
        return $sources;
    }

    private function local_confirmed_dictionary_for_car(array $record, string $carId): array
    {
        if ($carId === '378') {
            return [
                'vehicle_make' => 'Mercedes-Benz',
                'vehicle_make_short' => 'Mercedes',
                'vehicle_model' => 'A-class',
                'vehicle_generation' => 'A W177 AMG',
                'vehicle_fuel' => 'Benzyna',
                'vehicle_gearbox_type' => 'Automatyczny',
                'vehicle_body_type' => 'Hatchback',
                'vehicle_drive_wheels' => 'AWD',
                'vehicle_color' => 'Czerwony',
                'vehicle_period' => '2018--',
                'vehicle_year' => '2022',
                'mileage_km' => '9777',
            ];
        }
        if ($carId !== '458') { return []; }
        $mapped=[];
        if ((string)($record['car_model_category'] ?? '') === '25') { $mapped['vehicle_make']='Volkswagen'; $mapped['vehicle_make_short']='VW'; }
        if ((string)($record['car_model'] ?? '') === '1936') { $mapped['vehicle_model']='Touran'; $mapped['vehicle_generation']='Touran III'; }
        if ((string)($record['car_fuel'] ?? '') === '2') { $mapped['vehicle_fuel']='Benzyna'; }
        if ((string)($record['car_gearbox_type'] ?? '') === '0') { $mapped['vehicle_gearbox_type']='Mechaniczna'; }
        if ((string)($record['car_wheel_drive'] ?? '') === '1') { $mapped['vehicle_drive_wheels']='Przód'; }
        if ((string)($record['car_body_type'] ?? '') === '4') { $mapped['vehicle_body_type']='Minivan'; }
        if ((string)($record['car_color'] ?? '') === '5') { $mapped['vehicle_color']='Niebieski'; }
        return $mapped;
    }

    private function run_public_probes(string $baseUrl): array
    {
        if ($baseUrl === '') {
            return [];
        }

        $paths = ['/docs/', '/openapi/swagger.yaml'];
        $results = [];
        foreach ($paths as $path) {
            $response = wp_remote_get($baseUrl . $path, ['timeout' => 10]);
            if (is_wp_error($response)) {
                $results[] = ['path' => $path, 'ok' => false, 'http_code' => null, 'error' => $response->get_error_code()];
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $results[] = ['path' => $path, 'ok' => $code > 0 && $code < 500, 'http_code' => $code];
        }

        return $results;
    }



    public function change_part_status(string $partId, string|int $status): array
    {
        $partId = trim((string) $partId);
        $status = trim((string) $status);
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? 'https://api.rrr.lt'));
        $endpointPath = '/crm/changePartStatus';

        if ($baseUrl === '') {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Missing RRR base URL', 'endpoint_path' => $endpointPath];
        }

        if ($partId === '' || !preg_match('/^\d+$/', $partId)) {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Invalid part_id', 'endpoint_path' => $endpointPath];
        }

        if (!in_array($status, ['0', '1', '2', '3', '4'], true)) {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Invalid status. Allowed values: 0, 1, 2, 3, 4.', 'endpoint_path' => $endpointPath];
        }

        $endpoint = $baseUrl . $endpointPath;
        $requestParams = [
            'part_id' => $partId,
            'status' => $status,
        ] + $this->get_auth_form_fields();

        $sentFields = [
            'username' => '[redacted]',
            'password' => '[redacted]',
            'user_token' => '[redacted]',
            'part_id' => $partId,
            'status' => $status,
        ];

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => $requestParams,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'endpoint' => $endpoint,
                'endpoint_path' => $endpointPath,
                'method' => 'POST',
                'content_type' => 'application/x-www-form-urlencoded',
                'http_status' => null,
                'raw_response_body' => '',
                'parsed_json' => null,
                'status_code' => '',
                'message' => 'RRR request failed: ' . $response->get_error_code() . ' ' . $response->get_error_message(),
                'sent_fields' => $sentFields,
            ];
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $parsed = json_decode($rawBody, true);
        $jsonStatus = is_array($parsed) ? sanitize_text_field((string) ($parsed['status_code'] ?? '')) : '';
        $apiMessage = is_array($parsed)
            ? sanitize_text_field((string) ($parsed['message'] ?? $parsed['msg'] ?? $parsed['error'] ?? ''))
            : 'Non-JSON response';

        return [
            'ok' => $httpStatus === 200 && $jsonStatus === 'R200',
            'endpoint' => $endpoint,
            'endpoint_path' => $endpointPath,
            'method' => 'POST',
            'content_type' => 'application/x-www-form-urlencoded',
            'http_status' => $httpStatus,
            'raw_response_body' => $rawBody,
            'parsed_json' => is_array($parsed) ? $parsed : null,
            'status_code' => $jsonStatus,
            'message' => $apiMessage,
            'sent_fields' => $sentFields,
        ];
    }

    public function update_part_internal_notes_only(string $partId, string $internalNotes): array
    {
        $partId = trim($partId);
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Missing RRR base URL'];
        }

        if ($partId === '' || !preg_match('/^\d+$/', $partId)) {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Invalid part_id'];
        }

        $endpointPath = '/crm/updatePart';
        $endpoint = $baseUrl . $endpointPath;
        $requestParams = [
            'part_id' => $partId,
            'internal_notes' => $internalNotes,
        ] + $this->get_auth_form_fields();

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => $requestParams,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'endpoint' => $endpoint,
                'endpoint_path' => $endpointPath,
                'http_status' => null,
                'raw_response_body' => '',
                'parsed_json' => null,
                'status_code' => '',
                'message' => 'RRR request failed: ' . $response->get_error_code() . ' ' . $response->get_error_message(),
                'sent_fields' => ['username' => '[redacted]', 'password' => '[redacted]', 'user_token' => '[redacted]', 'part_id' => $partId, 'internal_notes' => '[provided]'],
            ];
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $parsed = json_decode($rawBody, true);
        $jsonStatus = is_array($parsed) ? sanitize_text_field((string) ($parsed['status_code'] ?? '')) : '';
        $apiMessage = is_array($parsed)
            ? sanitize_text_field((string) ($parsed['message'] ?? $parsed['msg'] ?? $parsed['error'] ?? ''))
            : 'Non-JSON response';

        return [
            'ok' => $jsonStatus === 'R200',
            'endpoint' => $endpoint,
            'endpoint_path' => $endpointPath,
            'http_status' => $httpStatus,
            'raw_response_body' => $rawBody,
            'parsed_json' => is_array($parsed) ? $parsed : null,
            'status_code' => $jsonStatus,
            'message' => $apiMessage,
            'sent_fields' => ['username' => '[redacted]', 'password' => '[redacted]', 'user_token' => '[redacted]', 'part_id' => $partId, 'internal_notes' => '[provided]'],
        ];
    }

    public function test_update_part_place_once(string $partId, string $place): array
    {
        $partId = trim($partId);
        $place = trim($place);
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'http_status' => null, 'status_code' => '', 'message' => 'Missing RRR base URL'];
        }

        $endpointPath = '/crm/updatePart';
        $endpoint = $baseUrl . $endpointPath;
        $requestParams = [
            'part_id' => $partId,
            'place' => $place,
        ] + $this->get_auth_form_fields();

        $response = wp_remote_post($endpoint, [
            'timeout' => 20,
            'body' => $requestParams,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok' => false,
                'endpoint' => $endpoint,
                'http_status' => null,
                'raw_response_body' => '',
                'parsed_json' => null,
                'status_code' => '',
                'message' => 'RRR request failed: ' . $response->get_error_code() . ' ' . $response->get_error_message(),
            ];
        }

        $httpStatus = (int) wp_remote_retrieve_response_code($response);
        $rawBody = (string) wp_remote_retrieve_body($response);
        $parsed = json_decode($rawBody, true);
        $jsonStatus = is_array($parsed) ? sanitize_text_field((string) ($parsed['status_code'] ?? '')) : '';
        $apiMessage = is_array($parsed)
            ? sanitize_text_field((string) ($parsed['message'] ?? $parsed['msg'] ?? $parsed['error'] ?? ''))
            : 'Non-JSON response';

        return [
            'ok' => $jsonStatus === 'R200',
            'endpoint' => $endpoint,
            'endpoint_path' => $endpointPath,
            'http_status' => $httpStatus,
            'raw_response_body' => $rawBody,
            'parsed_json' => is_array($parsed) ? $parsed : null,
            'status_code' => $jsonStatus,
            'message' => $apiMessage,
        ];
    }

    private function post_form(string $path, array $payload, bool $includeRawPayload = false): array
    {
        $baseUrl = $this->normalize_base_url((string) ($this->settings['rrr_api_base_url'] ?? ''));
        if ($baseUrl === '') {
            return ['ok' => false, 'http_code' => null, 'status_code' => null, 'message' => 'Missing RRR base URL'];
        }

        $body = $payload + $this->get_auth_form_fields();
        $response = wp_remote_post($baseUrl . $path, [
            'timeout' => 12,
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'http_code' => null, 'status_code' => null, 'message' => 'RRR request failed: ' . $response->get_error_code()];
        }

        $httpCode = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $statusCode = is_array($decoded) ? sanitize_text_field((string) ($decoded['status_code'] ?? '')) : '';
        $message = is_array($decoded) ? sanitize_text_field((string) ($decoded['msg'] ?? $decoded['message'] ?? '')) : 'Non-JSON response';
        $ok = $httpCode === 200 && $statusCode === 'R200';
        $pagination = is_array($decoded['pagination'] ?? null) ? $decoded['pagination'] : [];
        $records = [];
        if (is_array($decoded['data'] ?? null)) {
            foreach ($decoded['data'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $records[] = [
                    'id' => sanitize_text_field((string) ($row['id'] ?? '')),
                    'external_id' => isset($row['external_id']) ? sanitize_text_field((string) $row['external_id']) : null,
                    'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                    'status' => sanitize_text_field((string) ($row['status'] ?? '')),
                    'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
                ];
            }
        }
        $firstRecord = $records[0] ?? [];

        return [
            'ok' => $ok,
            'executed' => true,
            'success' => $ok,
            'http_code' => $httpCode,
            'status_code' => $statusCode,
            'msg' => $message,
            'pagination' => [
                'page' => isset($pagination['page']) ? (int) $pagination['page'] : null,
                'limit' => isset($pagination['limit']) ? (int) $pagination['limit'] : null,
                'total_count' => isset($pagination['total_count']) ? (int) $pagination['total_count'] : null,
            ],
            'first_record' => $firstRecord,
            'records' => $records,
            'records_count' => count($records),
            'raw_records' => $includeRawPayload && is_array($decoded['data'] ?? null) ? array_values(array_filter($decoded['data'], 'is_array')) : [],
            'payload' => $includeRawPayload && is_array($decoded) ? $decoded : [],
        ];
    }

    private function get_auth_form_fields(): array
    {
        return [
            'username' => (string) ($this->settings['rrr_api_username'] ?? ''),
            'password' => (string) ($this->settings['rrr_api_password'] ?? ''),
            'user_token' => (string) ($this->settings['rrr_api_user_token'] ?? ''),
        ];
    }

    private function has_credentials(): bool
    {
        return (string) ($this->settings['rrr_api_username'] ?? '') !== ''
            && (string) ($this->settings['rrr_api_password'] ?? '') !== ''
            && (string) ($this->settings['rrr_api_user_token'] ?? '') !== '';
    }

    private function normalize_base_url(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/');
    }
}
