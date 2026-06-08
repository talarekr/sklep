<?php

namespace GPSwiss\Ovoko\Services;

class OvokoMarketplaceCategorySuggestionsClient
{
    public const SOURCE_TYPE = 'panel_marketplace_category_suggestions';
    public const ENDPOINT_PATH = '/api/v1/ovoko-marketplace-category-suggestions';

    public function __construct(private array $settings = []) {}

    public function predict_by_part_code(string $partCode, array $options = []): array
    {
        $partCode = trim($partCode);
        $baseUrl = $this->normalize_base_url((string) ($options['base_url'] ?? $this->settings['ovoko_marketplace_category_suggestions_base_url'] ?? ''));
        $authMode = (string) ($options['auth_mode'] ?? $this->settings['ovoko_marketplace_category_suggestions_auth_mode'] ?? 'none');
        $authMode = in_array($authMode, ['none', 'crm_credentials', 'bearer_token_manual_diagnostic_only'], true) ? $authMode : 'none';
        $endpoint = $baseUrl !== '' ? $baseUrl . self::ENDPOINT_PATH . '?partCode=' . rawurlencode($partCode) : self::ENDPOINT_PATH . '?partCode=' . rawurlencode($partCode);
        $safe = $this->is_safe_to_call_from_wordpress($authMode);

        $result = [
            'ok' => false,
            'source' => 'ovoko_marketplace_category_suggestions',
            'source_type' => self::SOURCE_TYPE,
            'partCode' => $partCode,
            'endpoint' => $endpoint,
            'http_status' => null,
            'auth_mode_used' => $authMode,
            'suggestions' => [],
            'selected_suggestion' => null,
            'confidence' => 'low',
            'status' => 'unavailable',
            'safe_to_call_from_wordpress' => $safe,
            'requires_browser_session_cookies' => false,
            'requires_browser_session_bearer' => $authMode === 'bearer_token_manual_diagnostic_only',
            'production_automation_allowed' => $safe,
            'raw_response' => null,
        ];

        if ($partCode === '') {
            return $result + ['reason' => 'missing_part_code'];
        }
        if ($baseUrl === '') {
            return $result + ['reason' => 'missing_marketplace_category_suggestion_base_url'];
        }
        if (!$safe) {
            $result['status'] = 'endpoint_requires_panel_auth';
            $result['reason'] = 'auth_mode_not_production_safe';
            return $result;
        }
        if (!function_exists('wp_remote_get')) {
            return $result + ['reason' => 'wordpress_http_api_unavailable'];
        }

        $headers = ['Accept' => 'application/ld+json, application/json'];
        if ($authMode === 'bearer_token_manual_diagnostic_only') {
            $token = trim((string) ($options['bearer_token'] ?? $this->settings['ovoko_marketplace_category_suggestions_bearer_token'] ?? ''));
            if ($token !== '') { $headers['Authorization'] = 'Bearer ' . $token; }
        }
        $response = wp_remote_get($endpoint, ['timeout' => 12, 'headers' => $headers]);
        if (function_exists('is_wp_error') && is_wp_error($response)) {
            $result['reason'] = 'wp_error';
            $result['message'] = method_exists($response, 'get_error_message') ? $response->get_error_message() : 'WP_Error';
            return $result;
        }
        $result['http_status'] = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $result['raw_response'] = $body;
        $decoded = json_decode($body, true);
        if (in_array($result['http_status'], [401, 403], true)) {
            $result['status'] = 'endpoint_requires_panel_auth';
            $result['requires_browser_session_bearer'] = true;
            $result['requires_browser_session_cookies'] = true;
            $result['production_automation_allowed'] = false;
            return $result + ['reason' => 'http_auth_required'];
        }
        if (!is_array($decoded)) {
            return $result + ['reason' => 'non_json_response'];
        }
        return $this->result_from_payload($decoded, $result);
    }

    public function parse_response(array $payload): array
    {
        return $this->result_from_payload($payload, [
            'ok' => false,
            'source' => 'ovoko_marketplace_category_suggestions',
            'source_type' => self::SOURCE_TYPE,
            'http_status' => 200,
            'auth_mode_used' => 'diagnostic_fixture',
            'suggestions' => [],
            'selected_suggestion' => null,
            'confidence' => 'low',
            'status' => 'unavailable',
            'safe_to_call_from_wordpress' => true,
            'requires_browser_session_cookies' => false,
            'requires_browser_session_bearer' => false,
            'production_automation_allowed' => true,
            'raw_response' => $payload,
        ]);
    }

    private function result_from_payload(array $payload, array $result): array
    {
        $members = is_array($payload['hydra:member'] ?? null) ? $payload['hydra:member'] : [];
        foreach ($members as $member) {
            if (!is_array($member)) { continue; }
            $suggestion = $this->parse_member($member);
            if ($suggestion !== null) { $result['suggestions'][] = $suggestion; }
        }
        if ($result['suggestions'] !== []) {
            $result['selected_suggestion'] = $result['suggestions'][0];
            $result['selected_suggestion']['selected'] = true;
            $result['ok'] = true;
            $result['status'] = 'completed';
            $result['confidence'] = 'high';
        } else {
            $result['status'] = 'no_level_3_suggestions';
        }
        return $result;
    }

    private function parse_member(array $member): ?array
    {
        $id = $member['id'] ?? null;
        $title = trim((string) ($member['title'] ?? ''));
        if ((int) ($member['level'] ?? 0) !== 3 || !is_numeric($id) || (int) $id <= 0 || $title === '') {
            return null;
        }
        $path = $this->build_path($member);
        $dimensions = null;
        if (is_array($member['dimensions'] ?? null)) {
            $dimensions = [];
            foreach (['height', 'width', 'length', 'weight'] as $key) {
                $dimensions[$key] = is_numeric($member['dimensions'][$key] ?? null) ? (float) $member['dimensions'][$key] : null;
            }
        }
        return [
            'category_id' => (int) $id,
            'category_name' => $title,
            'level' => 3,
            'category_path' => implode(' > ', $path),
            'path_levels' => $path,
            'dimensions' => $dimensions,
            'source_type' => self::SOURCE_TYPE,
            'confidence' => 'high',
            'status' => 'completed',
            'selected' => false,
        ];
    }

    private function build_path(array $node): array
    {
        $levels = [];
        $current = $node;
        while (is_array($current)) {
            $title = trim((string) ($current['title'] ?? ''));
            if ($title !== '') { array_unshift($levels, $title); }
            $current = is_array($current['parent'] ?? null) ? $current['parent'] : null;
        }
        return $levels;
    }

    private function is_safe_to_call_from_wordpress(string $authMode): bool
    {
        return in_array($authMode, ['none', 'crm_credentials'], true);
    }

    private function normalize_base_url(string $baseUrl): string
    {
        return rtrim(trim($baseUrl), '/');
    }
}
