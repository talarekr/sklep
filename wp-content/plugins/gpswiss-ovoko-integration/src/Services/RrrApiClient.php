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
            if (!empty($row['success']) && !empty($row['contains_car_id_requested'])) {
                $record = (array) ($row['candidate_record'] ?? []);
                return [
                    'ok' => true,
                    'endpoint_confirmed' => true,
                    'endpoint_used' => (string) ($row['path'] ?? ''),
                    'car_id' => $carId,
                    'normalized' => $this->normalize_vehicle_record($record, $carId),
                ];
            }
        }

        return ['ok'=>false,'endpoint_confirmed'=>false,'car_id'=>$carId,'probe'=>$probe,'message'=>'No confirmed read-only endpoint returned vehicle details for requested car_id.'];
    }

    public function probe_vehicle_endpoints(int $carId = 458): array
    {
        $carId=max(1,$carId);
        $paths=['/get/car/'.$carId,'/get/cars?limit=1&page=1','/v2/get/cars?limit=1&page=1','/v2/get/car/'.$carId,'/get/cars','/get/vehicles?limit=1&page=1','/v2/get/vehicles?limit=1&page=1'];
        $results=[];
        foreach($paths as $path){
            $raw=$this->post_form($path,[],true);
            $payload=(array)($raw['payload']??[]);
            $candidate=$this->extract_candidate_record($payload);
            $candidateKeys=array_values(array_map('strval',array_keys((array)$candidate)));
            $contains=(string)($this->first_non_empty_value((array)$candidate,['car_id','id','vehicle_id']))===(string)$carId;
            $norm=$this->normalize_vehicle_record((array)$candidate,(string)$carId);
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
        foreach (['car', 'vehicle', 'vehicle_data'] as $vehicleKey) {
            if (isset($record[$vehicleKey]) && is_array($record[$vehicleKey])) {
                $container = $record[$vehicleKey];
                break;
            }
        }
        if ($container === []) {
            return [];
        }

        $vin = sanitize_text_field((string) ($container['vin'] ?? $container['vehicle_vin'] ?? ''));
        $vinMasked = $vin === '' ? '' : substr($vin, 0, 3) . '***' . substr($vin, -3);

        return [
            'vehicle_id' => sanitize_text_field((string) ($container['id'] ?? $container['car_id'] ?? '')),
            'vehicle_make' => sanitize_text_field((string) ($container['make'] ?? $container['manufacturer'] ?? '')),
            'vehicle_model' => sanitize_text_field((string) ($container['model'] ?? '')),
            'vehicle_generation' => sanitize_text_field((string) ($container['generation'] ?? $container['modification'] ?? '')),
            'vehicle_year' => sanitize_text_field((string) ($container['year'] ?? '')),
            'vehicle_fuel' => sanitize_text_field((string) ($container['fuel'] ?? '')),
            'vehicle_engine_capacity' => sanitize_text_field((string) ($container['engine_capacity'] ?? '')),
            'vehicle_engine_power_kw' => sanitize_text_field((string) ($container['engine_power_kw'] ?? '')),
            'vehicle_engine_code' => sanitize_text_field((string) ($container['engine_code'] ?? '')),
            'vehicle_gearbox_type' => sanitize_text_field((string) ($container['gearbox_type'] ?? '')),
            'vehicle_body_type' => sanitize_text_field((string) ($container['body_type'] ?? '')),
            'vehicle_drive_wheels' => sanitize_text_field((string) ($container['drive_wheels'] ?? '')),
            'vehicle_steering_position' => sanitize_text_field((string) ($container['steering_position'] ?? '')),
            'vehicle_color' => sanitize_text_field((string) ($container['color'] ?? '')),
            'vehicle_color_code' => sanitize_text_field((string) ($container['color_code'] ?? '')),
            'vehicle_period' => sanitize_text_field((string) ($container['period'] ?? '')),
            'vehicle_vin_masked' => $vinMasked,
        ];
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



    private function extract_candidate_record(array $payload): array
    {
        $candidates = [];
        if (isset($payload['list'][0][0]) && is_array($payload['list'][0][0])) { $candidates[] = $payload['list'][0][0]; }
        if (isset($payload['list'][0]) && is_array($payload['list'][0])) { $candidates[] = $payload['list'][0]; }
        if (isset($payload['data'][0]) && is_array($payload['data'][0])) { $candidates[] = $payload['data'][0]; }
        if (isset($payload['data']) && is_array($payload['data'])) { $candidates[] = $payload['data']; }
        foreach (['cars','vehicles'] as $k) { if (isset($payload[$k][0]) && is_array($payload[$k][0])) { $candidates[] = $payload[$k][0]; } }
        $candidates[] = $payload;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) { continue; }
            if (!empty($candidate['car_id']) || !empty($candidate['id']) || !empty($candidate['car_model']) || !empty($candidate['car_engine_code'])) { return (array) $candidate; }
        }
        return [];
    }

    private function normalize_vehicle_record(array $record, string $fallbackCarId): array
    {
        $carId = (string) ($this->first_non_empty_value($record, ['car_id', 'id', 'vehicle_id']) ?: $fallbackCarId);
        $capacityCc = (int) ($record['car_engine_cubic_capacity'] ?? $record['engine_capacity'] ?? 0);
        $capacityL = $capacityCc > 0 ? round($capacityCc / 1000, 1) : null;
        $mapped = $this->local_confirmed_dictionary_for_car($record, $carId);
        $engineCode = sanitize_text_field((string) ($record['car_engine_code'] ?? $record['engine_code'] ?? ''));
        $fuel = (string) ($mapped['vehicle_fuel'] ?? sanitize_text_field((string) ($record['fuel'] ?? '')));
        $engineMarketing = $capacityL ? number_format($capacityL, 1) : '';
        $engineSource = 'derived_from_capacity_only';
        if ($carId === '458' && $fuel === 'Benzyna' && strtoupper($engineCode) === 'CZDA' && $engineMarketing !== '') { $engineMarketing .= ' TSI'; $engineSource = 'local_confirmed_from_ovoko_ui_for_car_458'; }
        return array_merge([
            'car_id' => $carId,
            'vehicle_make' => (string) ($mapped['vehicle_make'] ?? ''),
            'vehicle_make_short' => (string) ($mapped['vehicle_make_short'] ?? ''),
            'vehicle_model' => (string) ($mapped['vehicle_model'] ?? ''),
            'vehicle_generation' => (string) ($mapped['vehicle_generation'] ?? ''),
            'vehicle_engine_marketing' => $engineMarketing,
            'vehicle_engine_marketing_source' => $engineSource,
            'vehicle_year' => (string) ($mapped['vehicle_year'] ?? sanitize_text_field((string) ($record['car_years'] ?? $record['car_model_years'] ?? $record['year'] ?? ''))),
            'vehicle_period' => (string) ($mapped['vehicle_period'] ?? sanitize_text_field((string) ($record['period'] ?? (($record['car_years'] ?? '') !== '' ? ((string)$record['car_years']).'--' : '')))),
            'vehicle_fuel' => $fuel,
            'vehicle_engine_capacity_cc' => $capacityCc > 0 ? $capacityCc : null,
            'vehicle_engine_capacity_l' => $capacityL,
            'vehicle_engine_power_kw' => sanitize_text_field((string) ($record['car_engine_power'] ?? $record['engine_power_kw'] ?? '')),
            'vehicle_engine_code' => $engineCode,
            'vehicle_gearbox_type' => (string) ($mapped['vehicle_gearbox_type'] ?? ''),
            'vehicle_body_type' => (string) ($mapped['vehicle_body_type'] ?? ''),
            'vehicle_drive_wheels' => (string) ($mapped['vehicle_drive_wheels'] ?? ''),
            'vehicle_steering_position' => 'Lewa strona',
            'vehicle_color' => (string) ($mapped['vehicle_color'] ?? ''),
            'vehicle_color_code' => sanitize_text_field((string) ($record['car_color_code'] ?? $record['color_code'] ?? '')),
            'mileage_km' => (string) ($mapped['mileage_km'] ?? sanitize_text_field((string) ($record['car_mileage'] ?? ''))),
            'vehicle_dictionary_resolution_status' => 'partial_local_confirmed',
            'vehicle_dictionary_resolution_source' => 'local_confirmed_from_ovoko_ui',
            'vehicle_data_status' => 'car_endpoint_confirmed',
            'raw_payload_summary' => ['keys' => array_values(array_map('strval', array_keys($record)))],
        ], []);
    }

    private function has_vehicle_fields(array $normalized): bool
    {
        foreach (['make','model','generation','modification','engine_marketing'] as $k) { if (!empty($normalized[$k])) return true; }
        return false;
    }

    private function safe_sample(array $candidate): array
    {
        $out=[];
        foreach($candidate as $k=>$v){ if(is_scalar($v) && !in_array($k,['username','password','user_token'],true)){$out[(string)$k]=sanitize_text_field((string)$v);} }
        return $out;
    }

    public function probe_vehicle_dictionary_endpoints(int $carId = 458): array
    {
        $carId = max(1, $carId);
        $expected = ['model' => '1936', 'model_category' => '25', 'fuel' => '2', 'gearbox_type' => '0', 'wheel_drive' => '1', 'body_type' => '4', 'color' => '5'];
        $paths = [
            '/get/car/models','/get/car/model/1936','/get/car/model-categories','/get/car/model-category/25','/get/car/manufacturers','/get/car/manufacturer/25',
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

        if (!method_exists($this, 'flatten_nested_fields')) {
            return [];
        }

        $flat = $this->flatten_nested_fields($payload, 8, '', 1, 5000);
        if ($flat === []) {
            return [];
        }

        $candidateParents = [];
        foreach ($flat as $path => $value) {
            if (preg_match('/^(.*)\.(id|category_id)$/', (string) $path, $m)) {
                $candidateParents[] = $m[1];
            }
        }
        $nodes = [];
        foreach (array_unique($candidateParents) as $prefix) {
            $id = (string) ($this->value_from_path($payload, $prefix . '.id') ?? $this->value_from_path($payload, $prefix . '.category_id') ?? '');
            if (trim($id) === '') { continue; }
            $parentId = (string) ($this->value_from_path($payload, $prefix . '.parent_id') ?? $this->value_from_path($payload, $prefix . '.parent') ?? '0');
            $title = (string) (
                $this->value_from_path($payload, $prefix . '.pl')
                ?? $this->value_from_path($payload, $prefix . '.en')
                ?? $this->value_from_path($payload, $prefix . '.title')
                ?? $this->value_from_path($payload, $prefix . '.name')
                ?? $this->value_from_path($payload, $prefix . '.label')
                ?? $this->value_from_path($payload, $prefix . '.category_title')
                ?? ''
            );
            $nodes[] = [
                'id' => sanitize_text_field($id),
                'parent_id' => sanitize_text_field($parentId),
                'title' => sanitize_text_field($title),
                'pl' => sanitize_text_field((string) ($this->value_from_path($payload, $prefix . '.pl') ?? '')),
                'en' => sanitize_text_field((string) ($this->value_from_path($payload, $prefix . '.en') ?? '')),
                'level' => sanitize_text_field((string) ($this->value_from_path($payload, $prefix . '.level') ?? '')),
                'path' => $prefix,
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
