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

    public function normalize_rrr_single_part_payload(array $payload): array
    {
        $record = $this->extract_single_part_record($payload);
        $carId = $this->first_non_empty_value($record, ['car_id', 'carId', 'vehicle_id', 'vehicleId']);
        $vehicle = $this->extract_vehicle_data($record);
        $priceDiagnostics = $this->extract_allegro_price_diagnostics($record);
        $ovokoPrice = $record['price'] ?? null;
        $ovokoCurrency = sanitize_text_field((string) ($record['currency'] ?? ''));
        $ovokoOriginalPrice = $record['original_price'] ?? null;
        $ovokoOriginalCurrency = sanitize_text_field((string) ($record['original_currency'] ?? ''));
        $allegroPrice = $priceDiagnostics['allegro_channel_price'];
        $allegroCurrency = sanitize_text_field((string) $priceDiagnostics['allegro_channel_currency']);
        $priceSource = $allegroPrice !== null ? 'allegro_channel_price' : 'missing_allegro_channel_price';
        $priceReviewRequired = $allegroPrice === null;
        $priceReason = $priceReviewRequired
            ? 'Allegro channel price not found; do not import Ovoko price as Woo price.'
            : 'Woo target price sourced from Allegro channel price.';

        $images = $this->normalize_gallery($record['part_photo_gallery'] ?? $record['images'] ?? []);
        $singlePhoto = sanitize_text_field((string) ($record['photo'] ?? ''));

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
            'woo_target_price' => $allegroPrice,
            'woo_target_currency' => $allegroPrice !== null ? ($allegroCurrency !== '' ? $allegroCurrency : $ovokoOriginalCurrency) : '',
            'price_source' => $priceSource,
            'price_review_required' => $priceReviewRequired,
            'price_reason' => $priceReason,
            'allegro_price_available' => !$priceReviewRequired,
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
            'reserved_user_field_exists' => array_key_exists('reserved_user', $record),
            'reserved_date_field_exists' => array_key_exists('reserved_date', $record),
        ];

        if ($vehicle !== []) {
            $summary = array_merge($summary, $vehicle);
            $summary['vehicle_data_status'] = 'embedded';
        } elseif ($summary['car_id'] !== '') {
            $summary['vehicle_data_status'] = 'car_id_only';
        }

        return $summary;
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
