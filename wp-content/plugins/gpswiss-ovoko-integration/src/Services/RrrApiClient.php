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

    private function post_form(string $path, array $payload): array
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
        $firstRecord = [];
        if (is_array($decoded['data'] ?? null) && !empty($decoded['data'][0]) && is_array($decoded['data'][0])) {
            $row = $decoded['data'][0];
            $firstRecord = [
                'id' => sanitize_text_field((string) ($row['id'] ?? '')),
                'external_id' => isset($row['external_id']) ? sanitize_text_field((string) $row['external_id']) : null,
                'name' => sanitize_text_field((string) ($row['name'] ?? '')),
                'status' => sanitize_text_field((string) ($row['status'] ?? '')),
                'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
            ];
        }

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
