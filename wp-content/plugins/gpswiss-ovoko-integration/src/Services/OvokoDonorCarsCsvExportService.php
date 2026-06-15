<?php

namespace GPSwiss\Ovoko\Services;

class OvokoDonorCarsCsvExportService
{
    public const LIMIT = 100;
    public const CSV_FILENAME = 'ovoko_donor_cars.csv';
    public const SUMMARY_FILENAME = 'ovoko_donor_cars_summary.json';
    public const MAX_DICTIONARY_API_CALLS_PER_TICK = 5;

    public const COLUMNS = [
        'ovoko_car_id','external_id','make','model','generation','platform','production_year','fuel','gearbox','drive','steering_side','body_type','color','car_model_raw_id','car_model_category_raw_id','car_model_years','car_years','car_color_raw_id','car_color_code','car_body_number','car_engine_number','car_engine_cubic_capacity','car_engine_capacity_l','car_engine_power','car_engine_type','car_engine_code','car_gearbox_type_raw_id','car_gearbox_code','car_interior','car_fuel_raw_id','car_mileage','defectation_notes','car_wheel_type_raw_id','car_wheel_drive_raw_id','car_body_type_raw_id','photo','car_photo_gallery','dismantling_at','vehicle_make','vehicle_model','vehicle_generation','vehicle_year','vehicle_period','vehicle_engine_marketing','vehicle_engine_capacity_cc','vehicle_engine_capacity_l','vehicle_engine_power_kw','vehicle_engine_code','vehicle_fuel','vehicle_gearbox_type','vehicle_body_type','vehicle_drive_wheels','vehicle_steering_position','vehicle_color','vehicle_color_code','mileage_km'
    ];

    public function __construct(private RrrApiClient $client)
    {
    }

    public function start(): array
    {
        $paths = $this->paths();
        wp_mkdir_p(dirname($paths['csv']));
        $handle = fopen($paths['csv'], 'wb');
        if ($handle === false) {
            return ['ok' => false, 'error' => 'csv_open_failed'];
        }
        fputcsv($handle, self::COLUMNS, ',', '"', '\\');
        fclose($handle);

        $this->client->enable_export_safe_dictionary_mode(self::MAX_DICTIONARY_API_CALLS_PER_TICK);
        $summary = $this->initial_summary();
        $summary['dictionary_cache_phase'] = 'starting';
        $this->write_summary($summary);

        return ['ok' => true, 'next_page' => 1, 'limit' => self::LIMIT, 'summary' => $summary];
    }

    public function process_page(int $page): array
    {
        $page = max(1, $page);
        $paths = $this->paths();
        if (!file_exists($paths['csv'])) {
            $this->start();
        }
        $this->client->enable_export_safe_dictionary_mode(self::MAX_DICTIONARY_API_CALLS_PER_TICK);
        $summary = $this->read_summary();
        $summary['dictionary_cache_phase'] = 'export_uses_existing_model_cache_only';
        $result = $this->client->fetch_donor_cars_page(self::LIMIT, $page);
        if (empty($result['ok'])) {
            $summary['errors'][] = [
                'page' => $page,
                'http_code' => $result['http_code'] ?? null,
                'status_code' => $result['status_code'] ?? '',
                'message' => $result['msg'] ?? $result['message'] ?? 'RRR donor cars page fetch failed',
            ];
            $summary['completed_at'] = gmdate('c');
            $this->merge_dictionary_tick_diagnostics($summary);
            $this->add_memory($summary);
            $this->write_summary($summary);
            return ['ok' => false, 'done' => true, 'error' => 'api_page_failed', 'summary' => $summary];
        }

        $records = array_values(array_filter((array) ($result['raw_records'] ?? []), 'is_array'));
        $modelCache = (array) ($this->client->dictionary_tick_diagnostics()['staged_model_cache'] ?? []);
        $summary['warnings'][] = 'Donor export does not build the Ovoko model dictionary cache during export ticks. Run Build/continue Ovoko model dictionary cache separately before final Laravel import, or treat unresolved model rows as unsafe.';
        $this->merge_model_cache_summary($summary, $modelCache);
        $handle = fopen($paths['csv'], 'ab');
        if ($handle === false) {
            return ['ok' => false, 'done' => true, 'error' => 'csv_append_failed', 'summary' => $summary];
        }
        foreach ($records as $record) {
            $row = $this->map_record($record);
            fputcsv($handle, array_map(static fn($column) => $row[$column] ?? '', self::COLUMNS), ',', '"', '\\');
            $this->count_unresolved($summary, $row);
        }
        fclose($handle);

        $totalCount = (int) ($result['pagination']['total_count'] ?? 0);
        if ($totalCount > 0) {
            $summary['total_count_from_api'] = $totalCount;
        }
        $summary['cars_exported'] += count($records);
        $summary['pages_processed'] = max((int) $summary['pages_processed'], $page);
        $this->merge_dictionary_tick_diagnostics($summary);
        $this->add_memory($summary);

        $done = count($records) === 0 || ($totalCount > 0 && $summary['cars_exported'] >= $totalCount);
        if ($done) {
            $summary['completed_at'] = gmdate('c');
        }
        $this->write_summary($summary);

        return ['ok' => true, 'status' => $done ? 'completed' : 'exporting_donor_cars', 'phase' => $done ? 'Completed' : 'Exporting donor cars', 'done' => $done, 'next_page' => $page + 1, 'records_exported' => count($records), 'summary' => $summary, 'downloads' => $this->download_urls()];
    }

    public function download_urls(): array
    {
        return [
            'csv' => $this->download_url('csv'),
            'summary' => $this->download_url('summary'),
        ];
    }

    private function download_url(string $type): string
    {
        return add_query_arg([
            'action' => 'gpswiss_ovoko_download_donor_cars_export',
            'export_id' => 'donor_cars',
            'type' => $type,
            '_wpnonce' => wp_create_nonce('gpswiss_ovoko_download_donor_cars_export_' . $type),
        ], admin_url('admin-post.php'));
    }

    public function paths(): array
    {
        $upload = wp_upload_dir(null, false);
        $dir = trailingslashit((string) $upload['basedir']) . 'gpswiss-ovoko-integration/donor-cars-export';
        return ['dir' => $dir, 'csv' => $dir . '/' . self::CSV_FILENAME, 'summary' => $dir . '/' . self::SUMMARY_FILENAME];
    }

    private function initial_summary(): array
    {
        return ['dictionary_cache_complete' => false, 'dictionary_cache_phase' => 'not_started', 'csv_safe_for_laravel_import' => false, 'csv_safe_for_laravel_import_reason' => 'model dictionary cache incomplete', 'dictionary_api_calls_total' => 0, 'dictionary_api_calls_this_tick' => 0, 'dictionary_cache_hits' => 0, 'dictionary_cache_misses' => 0, 'unresolved_fields_counts' => [], 'total_count_from_api' => 0, 'cars_exported' => 0, 'pages_processed' => 0, 'limit' => self::LIMIT, 'started_at' => gmdate('c'), 'completed_at' => '', 'resolved_model_count' => 0, 'unresolved_model_count' => 0, 'resolved_fuel_count' => 0, 'unresolved_fuel_count' => 0, 'resolved_gearbox_count' => 0, 'unresolved_gearbox_count' => 0, 'resolved_drive_count' => 0, 'unresolved_drive_count' => 0, 'resolved_body_type_count' => 0, 'unresolved_body_type_count' => 0, 'resolved_color_count' => 0, 'unresolved_color_count' => 0, 'unique_car_model_ids' => [], 'resolved_car_model_ids' => [], 'unresolved_car_model_ids' => [], 'unresolved_car_model_id_samples' => [], 'unique_car_model_category_ids' => [], 'resolved_car_model_category_ids' => [], 'unresolved_car_model_category_id_samples' => [], 'model_dictionary_cache_complete' => false, 'model_dictionary_resolution_strategy' => 'staged_all_brand_model_cache_by_model_id_then_direct_context_if_available_no_category_as_brand', 'all_brand_model_cache_complete' => false, 'brand_model_endpoints_processed' => 0, 'brand_model_endpoints_total' => 0, 'model_cache_successful_endpoint_count' => 0, 'model_cache_failed_endpoint_count' => 0, 'failed_brand_model_endpoints' => [], 'target_model_ids_found' => [], 'target_model_ids_missing' => [], 'unresolved_only_because_cache_incomplete_count' => 0, 'model_resolution_errors' => [], 'sample_unresolved_rows' => [], 'dictionary_sources_used' => [], 'dictionary_probe_status' => 'not_run', 'dictionary_resolution_diagnostics' => ['dictionary_probe_called' => false, 'cache' => ['hits' => 0, 'misses' => 0], 'endpoints_called' => [], 'endpoint_statuses' => [], 'record_counts' => [], 'sample_id_resolution' => [], 'dictionary_resolution_available' => false, 'reason' => 'not_run'], 'warnings' => [], 'errors' => [], 'memory_usage_diagnostics' => []];
    }

    private function read_summary(): array
    {
        $path = $this->paths()['summary'];
        $decoded = file_exists($path) ? json_decode((string) file_get_contents($path), true) : null;
        return is_array($decoded) ? $decoded + $this->initial_summary() : $this->initial_summary();
    }

    private function write_summary(array $summary): void
    {
        wp_mkdir_p(dirname($this->paths()['summary']));
        file_put_contents($this->paths()['summary'], wp_json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function map_record(array $record): array
    {
        $get = static fn(array $keys): string => self::first($record, $keys);
        $cc = $get(['car_engine_cubic_capacity','engine_cubic_capacity','engine_capacity_cc','engine_capacity']);
        $capacityL = $get(['car_engine_capacity_l','engine_capacity_l']);
        if ($capacityL === '' && is_numeric($cc)) { $capacityL = rtrim(rtrim(number_format(((float) $cc) / 1000, 1, '.', ''), '0'), '.'); }
        $mileage = $get(['car_mileage','mileage','mileage_km']);
        $resolved = $this->client->resolve_donor_car_vehicle_fields_from_existing_model_cache($record);
        $dictionaryDiagnostics = ['dictionary_probe_called' => false, 'reason' => 'export_uses_existing_model_cache_only'];
        $fieldSources = (array) ($resolved['vehicle_dictionary_field_sources'] ?? []);
        $modelLabel = (string) ($resolved['vehicle_model'] ?? $get(['vehicle_model','model_name']));
        $makeLabel = (string) ($resolved['vehicle_make'] ?? $get(['vehicle_make','make','manufacturer','brand_name']));
        $generationLabel = (string) ($resolved['vehicle_generation'] ?? $get(['vehicle_generation','generation']));
        $yearLabel = (string) ($resolved['vehicle_year'] ?? $get(['vehicle_year','year']));
        $fuelLabel = (string) ($resolved['vehicle_fuel'] ?? $get(['vehicle_fuel','fuel_name']));
        $gearboxLabel = (string) ($resolved['vehicle_gearbox_type'] ?? $get(['vehicle_gearbox_type','gearbox_name']));
        $driveLabel = (string) ($resolved['vehicle_drive_wheels'] ?? $get(['vehicle_drive_wheels','wheel_drive']));
        $steeringLabel = (string) ($resolved['vehicle_steering_position'] ?? $get(['vehicle_steering_position','steering_position','wheel_type']));
        $bodyLabel = (string) ($resolved['vehicle_body_type'] ?? $get(['vehicle_body_type','body_name']));
        $colorLabel = (string) ($resolved['vehicle_color'] ?? $get(['vehicle_color','color_name']));
        return [
            'ovoko_car_id' => $get(['car_id','id','vehicle_id']),
            'external_id' => $get(['external_id']),
            'make' => $makeLabel,
            'model' => $modelLabel,
            'generation' => $generationLabel,
            'platform' => (string) ($resolved['vehicle_platform'] ?? $get(['vehicle_platform','platform'])),
            'production_year' => $yearLabel,
            'fuel' => $fuelLabel,
            'gearbox' => $gearboxLabel,
            'drive' => $driveLabel,
            'steering_side' => $steeringLabel,
            'body_type' => $bodyLabel,
            'color' => $colorLabel,
            'car_model_raw_id' => $get(['car_model_id','model_id','car_model']),
            'car_model_category_raw_id' => $get(['car_model_category_id','model_category_id','car_model_category']),
            'car_model_years' => $get(['car_model_years','model_years']),
            'car_years' => $get(['car_years','years','year']),
            'car_color_raw_id' => $get(['car_color_id','color_id','car_color']),
            'car_color_code' => $get(['car_color_code','color_code']),
            'car_body_number' => $get(['car_body_number','body_number','vin']),
            'car_engine_number' => $get(['car_engine_number','engine_number']),
            'car_engine_cubic_capacity' => $cc,
            'car_engine_capacity_l' => $capacityL,
            'car_engine_power' => $get(['car_engine_power','engine_power','power']),
            'car_engine_type' => $get(['car_engine_type','engine_type']),
            'car_engine_code' => $get(['car_engine_code','engine_code']),
            'car_gearbox_type_raw_id' => $get(['car_gearbox_type_id','gearbox_type_id','car_gearbox_type']),
            'car_gearbox_code' => $get(['car_gearbox_code','gearbox_code']),
            'car_interior' => $get(['car_interior','interior']),
            'car_fuel_raw_id' => $get(['car_fuel_id','fuel_id','car_fuel']),
            'car_mileage' => $mileage,
            'defectation_notes' => $get(['defectation_notes','defect_notes','notes']),
            'car_wheel_type_raw_id' => $get(['car_wheel_type_id','wheel_type_id','car_wheel_type']),
            'car_wheel_drive_raw_id' => $get(['car_wheel_drive_id','wheel_drive_id','drive_wheels_id','car_wheel_drive']),
            'car_body_type_raw_id' => $get(['car_body_type_id','body_type_id','car_body_type']),
            'photo' => $get(['photo','main_photo']),
            'car_photo_gallery' => self::stringify($record['car_photo_gallery'] ?? $record['photo_gallery'] ?? $record['gallery'] ?? ''),
            'dismantling_at' => $get(['dismantling_at','dismantled_at','date']),
            'vehicle_make' => $makeLabel,
            'vehicle_model' => $modelLabel,
            'vehicle_generation' => $generationLabel,
            'vehicle_year' => $yearLabel,
            'vehicle_period' => $get(['vehicle_period','period']),
            'vehicle_engine_marketing' => $get(['vehicle_engine_marketing','engine_marketing']),
            'vehicle_engine_capacity_cc' => $cc,
            'vehicle_engine_capacity_l' => $capacityL,
            'vehicle_engine_power_kw' => $get(['vehicle_engine_power_kw','engine_power_kw']),
            'vehicle_engine_code' => $get(['vehicle_engine_code','engine_code','car_engine_code']),
            'vehicle_fuel' => $fuelLabel,
            'vehicle_gearbox_type' => $gearboxLabel,
            'vehicle_body_type' => $bodyLabel,
            'vehicle_drive_wheels' => $driveLabel,
            'vehicle_steering_position' => $steeringLabel,
            'vehicle_color' => $colorLabel,
            'vehicle_color_code' => $get(['vehicle_color_code','color_code','car_color_code']),
            'mileage_km' => $mileage,
            '_dictionary_sources' => $fieldSources,
            '_dictionary_resolution_diagnostics' => $dictionaryDiagnostics,
        ];
    }

    private static function first(array $record, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($record[$key]) && is_scalar($record[$key]) && trim((string) $record[$key]) !== '') { return sanitize_text_field((string) $record[$key]); }
        }
        return '';
    }

    private static function stringify(mixed $value): string
    {
        if (is_scalar($value)) { return sanitize_text_field((string) $value); }
        return is_array($value) ? wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    }

    private function count_unresolved(array &$summary, array $row): void
    {
        $this->count_resolution($summary, $row, 'model', 'car_model_raw_id', 'vehicle_model');
        $this->count_resolution($summary, $row, 'fuel', 'car_fuel_raw_id', 'vehicle_fuel');
        $this->count_resolution($summary, $row, 'gearbox', 'car_gearbox_type_raw_id', 'vehicle_gearbox_type');
        $this->count_resolution($summary, $row, 'drive', 'car_wheel_drive_raw_id', 'vehicle_drive_wheels');
        $this->count_resolution($summary, $row, 'body_type', 'car_body_type_raw_id', 'vehicle_body_type');
        $this->count_resolution($summary, $row, 'color', 'car_color_raw_id', 'vehicle_color');
        foreach ((array) ($row['_dictionary_sources'] ?? []) as $source) {
            $name = is_array($source) ? (string) ($source['source'] ?? '') : '';
            if ($name !== '' && $name !== 'unresolved' && !in_array($name, $summary['dictionary_sources_used'], true)) {
                $summary['dictionary_sources_used'][] = $name;
            }
        }
        if (isset($row['_dictionary_resolution_diagnostics']) && is_array($row['_dictionary_resolution_diagnostics'])) {
            $this->merge_dictionary_resolution_diagnostics($summary['dictionary_resolution_diagnostics'], $row['_dictionary_resolution_diagnostics']);
        }
        $summary['dictionary_probe_status'] = $summary['dictionary_sources_used'] !== [] ? 'resolved_or_partially_resolved' : 'unresolved';
        $summary['dictionary_cache_hits'] = (int) ($summary['dictionary_resolution_diagnostics']['cache']['hits'] ?? 0);
        $summary['dictionary_cache_misses'] = (int) ($summary['dictionary_resolution_diagnostics']['cache']['misses'] ?? 0);
        $summary['unresolved_fields_counts'] = [
            'model' => (int) $summary['unresolved_model_count'], 'fuel' => (int) $summary['unresolved_fuel_count'], 'gearbox' => (int) $summary['unresolved_gearbox_count'],
            'drive' => (int) $summary['unresolved_drive_count'], 'body_type' => (int) $summary['unresolved_body_type_count'], 'color' => (int) $summary['unresolved_color_count'],
        ];
        $summary['dictionary_cache_complete'] = ((int) array_sum($summary['unresolved_fields_counts'])) === 0;
        if (!$summary['dictionary_cache_complete'] && !in_array('Eksport nadal ma niepełne słowniki Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.', $summary['warnings'], true)) {
            $summary['warnings'][] = 'Eksport nadal ma niepełne słowniki Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.';
        }
        if (($row['car_model_raw_id'] ?? '') !== '' && ($row['vehicle_model'] ?? '') === '' && count($summary['sample_unresolved_rows']) < 20) {
            $summary['sample_unresolved_rows'][] = ['ovoko_car_id' => (string) ($row['ovoko_car_id'] ?? ''), 'car_model_raw_id' => (string) ($row['car_model_raw_id'] ?? ''), 'car_model_category_raw_id' => (string) ($row['car_model_category_raw_id'] ?? ''), 'endpoints_tried' => ['/get/car_brands','/get/car_models/{brand_id}'], 'reason' => 'car_model id not found in staged all-brand model cache; car_model_category was not used as brand_id'];
        }
        if ((int) $summary['unresolved_model_count'] > 0 && !in_array('Eksport nadal ma niepełne modele Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.', $summary['warnings'], true)) {
            $summary['warnings'][] = 'Eksport nadal ma niepełne modele Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.';
        }
        $resolvedTotal = (int) $summary['resolved_model_count'] + (int) $summary['resolved_fuel_count'] + (int) $summary['resolved_gearbox_count'] + (int) $summary['resolved_drive_count'] + (int) $summary['resolved_body_type_count'] + (int) $summary['resolved_color_count'];
        $unresolvedTotal = (int) $summary['unresolved_model_count'] + (int) $summary['unresolved_fuel_count'] + (int) $summary['unresolved_gearbox_count'] + (int) $summary['unresolved_drive_count'] + (int) $summary['unresolved_body_type_count'] + (int) $summary['unresolved_color_count'];
        if ($unresolvedTotal > 0 && $resolvedTotal < $unresolvedTotal && !in_array('Eksport nadal ma niepełne słowniki Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.', $summary['warnings'], true)) {
            $summary['warnings'][] = 'Eksport nadal ma niepełne słowniki Ovoko/RRR. Nie używaj jako finalnego importu Laravel bez weryfikacji.';
        }
    }

    private function merge_dictionary_resolution_diagnostics(array &$target, array $source): void
    {
        $target['dictionary_probe_called'] = !empty($target['dictionary_probe_called']) || !empty($source['dictionary_probe_called']);
        $target['cache']['hits'] = (int) ($target['cache']['hits'] ?? 0) + (int) ($source['cache']['hits'] ?? 0);
        $target['cache']['misses'] = (int) ($target['cache']['misses'] ?? 0) + (int) ($source['cache']['misses'] ?? 0);
        foreach ((array) ($source['endpoints_called'] ?? []) as $endpoint) {
            if (!in_array($endpoint, (array) ($target['endpoints_called'] ?? []), true)) { $target['endpoints_called'][] = $endpoint; }
        }
        $target['endpoint_statuses'] = array_merge((array) ($target['endpoint_statuses'] ?? []), (array) ($source['endpoint_statuses'] ?? []));
        $target['record_counts'] = array_merge((array) ($target['record_counts'] ?? []), (array) ($source['record_counts'] ?? []));
        $target['sample_id_resolution'] = array_merge((array) ($target['sample_id_resolution'] ?? []), (array) ($source['sample_id_resolution'] ?? []));
        $target['dictionary_resolution_available'] = !empty($target['dictionary_resolution_available']) || !empty($source['dictionary_resolution_available']);
        $target['reason'] = !empty($target['dictionary_resolution_available']) ? '' : (string) ($source['reason'] ?? $target['reason'] ?? '');
    }

    private function count_resolution(array &$summary, array $row, string $name, string $rawKey, string $labelKey): void
    {
        if ((string) ($row[$rawKey] ?? '') === '') { return; }
        if ((string) ($row[$labelKey] ?? '') !== '') { $summary['resolved_' . $name . '_count']++; return; }
        $summary['unresolved_' . $name . '_count']++;
    }

    private function merge_model_cache_summary(array &$summary, array $cache): void
    {
        foreach (['unique_car_model_ids','unique_car_model_category_ids'] as $key) {
            $summary[$key] = array_values(array_unique(array_merge((array) ($summary[$key] ?? []), (array) ($cache[$key] ?? []))));
        }
        $resolved = array_keys((array) ($cache['model_id_to_record'] ?? []));
        $summary['resolved_car_model_ids'] = array_values(array_unique(array_merge((array) ($summary['resolved_car_model_ids'] ?? []), $resolved)));
        $summary['unresolved_car_model_ids'] = array_values(array_diff((array) $summary['unique_car_model_ids'], (array) $summary['resolved_car_model_ids']));
        $summary['unresolved_car_model_id_samples'] = array_slice($summary['unresolved_car_model_ids'], 0, 20);
        $summary['unresolved_car_model_category_id_samples'] = array_slice((array) $summary['unique_car_model_category_ids'], 0, 20);
        $summary['model_dictionary_cache_complete'] = !empty($cache['all_brand_model_cache_complete']);
        $summary['all_brand_model_cache_complete'] = !empty($cache['all_brand_model_cache_complete']);
        $summary['brand_model_endpoints_processed'] = (int) ($cache['brand_model_endpoints_processed'] ?? 0);
        $summary['brand_model_endpoints_total'] = (int) ($cache['brand_model_endpoints_total'] ?? 0);
        $summary['model_cache_successful_endpoint_count'] = (int) ($cache['model_cache_successful_endpoint_count'] ?? 0);
        $summary['model_cache_failed_endpoint_count'] = (int) ($cache['model_cache_failed_endpoint_count'] ?? 0);
        $summary['failed_brand_model_endpoints'] = (array) ($cache['failed_brand_model_endpoints'] ?? []);
        $summary['target_model_ids_found'] = $summary['resolved_car_model_ids'];
        $summary['target_model_ids_missing'] = $summary['unresolved_car_model_ids'];
        $summary['unresolved_only_because_cache_incomplete_count'] = empty($cache['all_brand_model_cache_complete']) ? count((array) $summary['unresolved_car_model_ids']) : 0;
        $summary['csv_safe_for_laravel_import'] = !empty($cache['all_brand_model_cache_complete']);
        $summary['csv_safe_for_laravel_import_reason'] = $summary['csv_safe_for_laravel_import'] ? 'model dictionary cache complete' : 'model dictionary cache incomplete';
    }


    private function merge_dictionary_tick_diagnostics(array &$summary): void
    {
        $diag = $this->client->dictionary_tick_diagnostics();
        $summary['dictionary_api_calls_this_tick'] = (int) ($diag['dictionary_api_calls_this_tick'] ?? 0);
        $summary['dictionary_api_calls_total'] = (int) ($summary['dictionary_api_calls_total'] ?? 0) + (int) $summary['dictionary_api_calls_this_tick'];
        $summary['last_dictionary_endpoint_attempted'] = (string) ($diag['last_dictionary_endpoint_attempted'] ?? '');
        $summary['max_dictionary_api_calls_per_tick'] = (int) ($diag['max_dictionary_api_calls_per_tick'] ?? self::MAX_DICTIONARY_API_CALLS_PER_TICK);
        if (!empty($diag['dictionary_call_limit_reached']) && !in_array('Dictionary API call limit was reached in this tick; export will continue with cached/raw values and resume on the next tick.', $summary['warnings'], true)) {
            $summary['warnings'][] = 'Dictionary API call limit was reached in this tick; export will continue with cached/raw values and resume on the next tick.';
        }
    }

    private function add_memory(array &$summary): void
    {
        $summary['memory_usage_diagnostics'][] = ['at' => gmdate('c'), 'usage_mb' => round(memory_get_usage(true) / 1048576, 2), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2), 'limit' => (string) ini_get('memory_limit')];
    }
}
