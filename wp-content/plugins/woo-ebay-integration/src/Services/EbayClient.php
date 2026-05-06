<?php

namespace WEI\Services;

class EbayClient
{
    private const BASE = 'https://api.ebay.com';

    private const MARKETPLACE_CONTENT_LANGUAGE = [
        'EBAY_DE' => 'de-DE',
        'EBAY_GB' => 'en-GB',
        'EBAY_US' => 'en-US',
    ];

    public function __construct(private EbayAuth $auth, private Logger $logger)
    {
    }


    public function get_access_token()
    {
        return $this->auth->get_valid_access_token();
    }

    public function create_or_replace_inventory_item(string $sku, array $payload, array $context = [])
    {
        return $this->request('PUT', '/sell/inventory/v1/inventory_item/' . rawurlencode($sku), $payload, [], $context);
    }

    public function create_offer(array $payload, array $context = [])
    {
        return $this->request('POST', '/sell/inventory/v1/offer', $payload, [], $context);
    }

    public function update_offer(string $offer_id, array $payload, array $context = [])
    {
        return $this->request('PUT', '/sell/inventory/v1/offer/' . rawurlencode($offer_id), $payload, [], $context);
    }

    public function publish_offer(string $offer_id, array $context = [])
    {
        return $this->request('POST', '/sell/inventory/v1/offer/' . rawurlencode($offer_id) . '/publish', [], [], $context);
    }

    public function bulk_update_price_quantity(array $requests)
    {
        return $this->request('POST', '/sell/inventory/v1/bulk_update_price_quantity', ['requests' => $requests]);
    }

    public function get_policies(string $type, string $marketplace_id = 'EBAY_DE')
    {
        $marketplace_id = trim($marketplace_id);
        if ($marketplace_id === '') {
            return new \WP_Error('wei_marketplace_id_missing', 'marketplace_id is required when refreshing eBay Business Policies');
        }

        return $this->request('GET', '/sell/account/v1/' . $type, null, ['marketplace_id' => $marketplace_id]);
    }


    public function get_default_category_tree_id(string $marketplace_id = 'EBAY_DE')
    {
        $marketplace_id = trim($marketplace_id);
        if ($marketplace_id === '') {
            return new \WP_Error('wei_marketplace_id_missing', 'marketplace_id is required when loading eBay category taxonomy');
        }

        return $this->taxonomy_request('GET', '/commerce/taxonomy/v1/get_default_category_tree_id', null, ['marketplace_id' => $marketplace_id]);
    }

    public function get_category_subtree(string $category_tree_id, string $category_id)
    {
        $category_tree_id = trim($category_tree_id);
        $category_id = trim($category_id);
        if ($category_tree_id === '' || $category_id === '') {
            return new \WP_Error('wei_taxonomy_params_missing', 'category_tree_id and category_id are required when loading eBay category subtree');
        }

        return $this->taxonomy_request('GET', '/commerce/taxonomy/v1/category_tree/' . rawurlencode($category_tree_id) . '/get_category_subtree', null, ['category_id' => $category_id]);
    }

    public function get_category_suggestions(string $category_tree_id, string $query)
    {
        $category_tree_id = trim($category_tree_id);
        $query = trim($query);
        if ($category_tree_id === '' || $query === '') {
            return new \WP_Error('wei_taxonomy_params_missing', 'category_tree_id and q are required when loading eBay category suggestions');
        }

        return $this->taxonomy_request('GET', '/commerce/taxonomy/v1/category_tree/' . rawurlencode($category_tree_id) . '/get_category_suggestions', null, ['q' => $query]);
    }


    public function get_item_aspects_for_category(string $category_tree_id, string $category_id)
    {
        $category_tree_id = trim($category_tree_id);
        $category_id = trim($category_id);
        if ($category_tree_id === '' || $category_id === '') {
            return new \WP_Error('wei_taxonomy_params_missing', 'category_tree_id and category_id are required when loading eBay category aspects');
        }

        return $this->taxonomy_request('GET', '/commerce/taxonomy/v1/category_tree/' . rawurlencode($category_tree_id) . '/get_item_aspects_for_category', null, ['category_id' => $category_id]);
    }

    public function get_orders(array $query = [])
    {
        return $this->request('GET', '/sell/fulfillment/v1/order', null, $query);
    }

    public function get_locations()
    {
        return $this->request('GET', '/sell/inventory/v1/location');
    }

    public function create_or_update_location(string $merchant_location_key, array $payload)
    {
        return $this->request('POST', '/sell/inventory/v1/location/' . rawurlencode($merchant_location_key), $payload);
    }


    public function taxonomy_oauth_context(): array
    {
        return $this->auth->get_taxonomy_oauth_context();
    }

    private function taxonomy_request(string $method, string $path, ?array $body = null, array $query = [], array $context = [])
    {
        $context = array_merge($this->taxonomy_oauth_context(), $context, ['stage' => (string) ($context['stage'] ?? 'taxonomy')]);
        return $this->request($method, $path, $body, $query, $context, 'application');
    }

    private function request(string $method, string $path, ?array $body = null, array $query = [], array $context = [], string $tokenType = 'user')
    {
        $token = $tokenType === 'application' ? $this->auth->get_valid_application_access_token() : $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $url = self::BASE . $path;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $contentLanguage = $this->resolve_content_language($path, $body, $query, $context);
        if ($contentLanguage !== '') {
            $headers['Content-Language'] = $contentLanguage;
        }

        $args = [
            'method' => $method,
            'timeout' => 25,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $res = wp_remote_request($url, $args);
            if (is_wp_error($res)) {
                if ($attempt === 4) return $res;
                sleep($attempt);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($res);
            $raw_body = (string) wp_remote_retrieve_body($res);
            $decoded = json_decode($raw_body, true);
            if ($status >= 200 && $status < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < 4) {
                sleep($attempt);
                continue;
            }

            $response_headers = wp_remote_retrieve_headers($res);
            $normalized_headers = [];
            if ($response_headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary) {
                $normalized_headers = $response_headers->getAll();
            } elseif (is_array($response_headers)) {
                $normalized_headers = $response_headers;
            }

            $correlation_headers = [];
            foreach ($normalized_headers as $header_name => $header_value) {
                $header_name_lower = strtolower((string) $header_name);
                if (str_contains($header_name_lower, 'correlation') || str_contains($header_name_lower, 'request-id') || str_contains($header_name_lower, 'trace')) {
                    $correlation_headers[$header_name] = $header_value;
                }
            }

            $sanitized_headers = $this->sanitize_sensitive_data($args['headers'] ?? []);
            $request_payload = $body !== null ? $this->sanitize_sensitive_data($body) : null;

            $error_context = array_merge([
                'stage' => (string) ($context['stage'] ?? 'unknown'),
                'product_id' => isset($context['product_id']) ? (int) $context['product_id'] : null,
                'sku' => isset($context['sku']) ? (string) $context['sku'] : null,
                'endpoint' => $path,
                'method' => $method,
                'status' => $status,
                'response_body' => is_array($decoded) ? $decoded : $raw_body,
                'request_payload' => $request_payload,
                'request_headers' => $sanitized_headers,
                'token_type' => $tokenType === 'application' ? 'application' : 'user',
                'correlation_headers' => $correlation_headers,
                'response_headers' => $normalized_headers,
            ], $this->sanitize_sensitive_data($context));

            $this->logger->error('eBay API request failed', $error_context);
            return new \WP_Error('wei_ebay_http_error', 'eBay API HTTP error', $error_context);
        }

        return new \WP_Error('wei_ebay_http_error', 'eBay API retries exhausted');
    }


    private function resolve_content_language(string $path, ?array $body = null, array $query = [], array $context = []): string
    {
        if (!str_starts_with($path, '/sell/inventory/v1/')) {
            return '';
        }

        $marketplaceId = (string) ($context['marketplace_id'] ?? $context['marketplaceId'] ?? $query['marketplace_id'] ?? $query['marketplaceId'] ?? $body['marketplaceId'] ?? '');
        if ($marketplaceId === '') {
            return '';
        }

        return self::MARKETPLACE_CONTENT_LANGUAGE[$marketplaceId] ?? '';
    }

    private function sanitize_sensitive_data($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            $isSecret = str_contains($normalizedKey, 'authorization')
                || str_contains($normalizedKey, 'token')
                || str_contains($normalizedKey, 'secret')
                || str_contains($normalizedKey, 'password');

            if ($isSecret) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize_sensitive_data($value) : $value;
        }

        return $sanitized;
    }

}

