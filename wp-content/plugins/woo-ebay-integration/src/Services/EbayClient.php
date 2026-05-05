<?php

namespace WEI\Services;

class EbayClient
{
    private const BASE = 'https://api.ebay.com';

    public function __construct(private EbayAuth $auth, private Logger $logger)
    {
    }

    public function create_or_replace_inventory_item(string $sku, array $payload)
    {
        return $this->request('PUT', '/sell/inventory/v1/inventory_item/' . rawurlencode($sku), $payload);
    }

    public function create_offer(array $payload)
    {
        return $this->request('POST', '/sell/inventory/v1/offer', $payload);
    }

    public function update_offer(string $offer_id, array $payload)
    {
        return $this->request('PUT', '/sell/inventory/v1/offer/' . rawurlencode($offer_id), $payload);
    }

    public function publish_offer(string $offer_id)
    {
        return $this->request('POST', '/sell/inventory/v1/offer/' . rawurlencode($offer_id) . '/publish', []);
    }

    public function bulk_update_price_quantity(array $requests)
    {
        return $this->request('POST', '/sell/inventory/v1/bulk_update_price_quantity', ['requests' => $requests]);
    }

    public function get_policies(string $type, string $marketplace_id = 'EBAY_DE')
    {
        return $this->request('GET', '/sell/account/v1/' . $type, null, ['marketplace_id' => $marketplace_id]);
    }

    public function create_fulfillment_policy(array $payload)
    {
        return $this->request('POST', '/sell/account/v1/fulfillment_policy', $payload);
    }

    public function create_payment_policy(array $payload)
    {
        return $this->request('POST', '/sell/account/v1/payment_policy', $payload);
    }

    public function create_return_policy(array $payload)
    {
        return $this->request('POST', '/sell/account/v1/return_policy', $payload);
    }

    public function update_fulfillment_policy(string $policy_id, array $payload)
    {
        return $this->request('PUT', '/sell/account/v1/fulfillment_policy/' . rawurlencode($policy_id), $payload);
    }

    public function update_payment_policy(string $policy_id, array $payload)
    {
        return $this->request('PUT', '/sell/account/v1/payment_policy/' . rawurlencode($policy_id), $payload);
    }

    public function update_return_policy(string $policy_id, array $payload)
    {
        return $this->request('PUT', '/sell/account/v1/return_policy/' . rawurlencode($policy_id), $payload);
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

    private function request(string $method, string $path, ?array $body = null, array $query = [])
    {
        $token = $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $url = self::BASE . $path;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method' => $method,
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
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

            $error_context = [
                'endpoint' => $path,
                'method' => $method,
                'status' => $status,
                'response_body' => is_array($decoded) ? $decoded : $raw_body,
                'correlation_headers' => $correlation_headers,
                'response_headers' => $normalized_headers,
            ];

            $this->logger->error('eBay API request failed', $error_context);
            return new \WP_Error('wei_ebay_http_error', 'eBay API HTTP error', $error_context);
        }

        return new \WP_Error('wei_ebay_http_error', 'eBay API retries exhausted');
    }
}
