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

    public function get_policies(string $type)
    {
        return $this->request('GET', '/sell/account/v1/' . $type);
    }

    public function get_orders(array $query = [])
    {
        return $this->request('GET', '/sell/fulfillment/v1/order', null, $query);
    }

    public function get_locations()
    {
        return $this->request('GET', '/sell/inventory/v1/location');
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
            $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
            if ($status >= 200 && $status < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            if (in_array($status, [429, 500, 502, 503, 504], true) && $attempt < 4) {
                sleep($attempt);
                continue;
            }

            $this->logger->error('eBay API request failed', ['path' => $path, 'status' => $status, 'body' => $decoded]);
            return new \WP_Error('wei_ebay_http_error', 'eBay API HTTP error', ['status' => $status, 'body' => $decoded]);
        }

        return new \WP_Error('wei_ebay_http_error', 'eBay API retries exhausted');
    }
}
