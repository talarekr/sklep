<?php

namespace AWI;

if (!defined('ABSPATH')) {
    exit;
}

class AllegroClient
{
    private const ACCEPT_HEADER = 'application/vnd.allegro.public.v1+json';

    private AllegroAuth $auth;
    private Logger $logger;

    public function __construct(AllegroAuth $auth, Logger $logger)
    {
        $this->auth = $auth;
        $this->logger = $logger;
    }

    public function get_offers(string $status = 'ACTIVE', int $offset = 0, int $limit = 100)
    {
        $query = [
            'offset' => max(0, $offset),
            'limit' => min(100, max(1, $limit)),
        ];

        if (!empty($status)) {
            $query['publication.status'] = $status;
        }

        return $this->request('GET', '/sale/offers', [
            'query' => $query,
        ]);
    }

    public function get_offer_details(string $offer_id)
    {
        return $this->request('GET', '/sale/offers/' . rawurlencode($offer_id));
    }

    public function get_offer_url(array $offer): string
    {
        if (!empty($offer['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['id']);
        }

        return '';
    }

    private function request(string $method, string $path, array $args = [])
    {
        $token = $this->auth->get_valid_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $settings = Plugin::get_settings();
        $base_url = $settings['environment'] === 'sandbox'
            ? 'https://api.allegro.pl.allegrosandbox.pl'
            : 'https://api.allegro.pl';

        $url = $base_url . $path;
        if (!empty($args['query']) && is_array($args['query'])) {
            $url = add_query_arg($args['query'], $url);
        }

        $request_args = [
            'method' => strtoupper($method),
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => self::ACCEPT_HEADER,
                'Content-Type' => 'application/json',
            ],
        ];

        if (!empty($args['body'])) {
            $request_args['body'] = wp_json_encode($args['body']);
        }

        $response = wp_remote_request($url, $request_args);
        if (is_wp_error($response)) {
            $this->logger->error('Allegro API request failed.', ['path' => $path, 'error' => $response->get_error_message()]);
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($status < 200 || $status > 299) {
            $this->logger->error('Allegro API returned non-success status.', ['path' => $path, 'status' => $status, 'body' => $raw]);
            return new \WP_Error('awi_api_error', __('Allegro API zwróciło błąd.', 'allegro-woo-importer'), ['status' => $status, 'body' => $raw]);
        }

        if (!is_array($data)) {
            return new \WP_Error('awi_api_invalid_json', __('Nieprawidłowa odpowiedź JSON z Allegro API.', 'allegro-woo-importer'));
        }

        return $data;
    }
}
