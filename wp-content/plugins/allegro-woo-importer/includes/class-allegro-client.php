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
    /** @var array<string, array<string, mixed>> */
    private array $category_cache = [];
    /** @var array<string, array<int, array{id: string, name: string}>> */
    private array $category_path_cache = [];
    /** @var array<string, array<int, array{id: string, name: string}>> */
    private array $category_children_cache = [];

    public function __construct(AllegroAuth $auth, Logger $logger)
    {
        $this->auth = $auth;
        $this->logger = $logger;
    }

    public function get_offers(string $status = 'ACTIVE', int $offset = 0, int $limit = 100, string $page_token = '')
    {
        $query = [
            'offset' => max(0, $offset),
            'limit' => min(100, max(1, $limit)),
        ];

        if (!empty($status)) {
            $query['publication.status'] = $status;
        }

        if ($page_token !== '') {
            $query['page.id'] = sanitize_text_field($page_token);
        }

        return $this->request('GET', '/sale/offers', [
            'query' => $query,
        ]);
    }

    public function get_offer_details(string $offer_id)
    {
        $path = '/sale/product-offers/' . rawurlencode($offer_id);
        $this->logger->info('Fetching Allegro offer details.', ['offer_id' => $offer_id, 'endpoint' => $path]);

        $details = $this->request('GET', $path);
        if (is_wp_error($details)) {
            return $details;
        }

        $this->logger->info('Fetched Allegro offer details payload snapshot.', [
            'offer_id' => $offer_id,
            'top_level_parameters_count' => is_array($details['parameters'] ?? null) ? count((array) $details['parameters']) : 0,
            'product_set_count' => is_array($details['productSet'] ?? null) ? count((array) $details['productSet']) : 0,
        ]);

        if ($offer_id === '18303384599') {
            $product_set_preview = [];
            if (is_array($details['productSet'] ?? null)) {
                foreach ($details['productSet'] as $index => $product_set_item) {
                    if (!is_array($product_set_item)) {
                        continue;
                    }

                    $product_set_preview[] = [
                        'index' => (int) $index,
                        'parameters_count' => is_array($product_set_item['parameters'] ?? null) ? count((array) $product_set_item['parameters']) : 0,
                        'product_parameters_count' => is_array($product_set_item['product']['parameters'] ?? null) ? count((array) $product_set_item['product']['parameters']) : 0,
                        'parameters_preview' => is_array($product_set_item['parameters'] ?? null) ? array_slice((array) $product_set_item['parameters'], 0, 6) : [],
                        'product_parameters_preview' => is_array($product_set_item['product']['parameters'] ?? null) ? array_slice((array) $product_set_item['product']['parameters'], 0, 6) : [],
                    ];
                }
            }

            $this->logger->info('Offer payload debug for requested Allegro offer id.', [
                'offer_id' => $offer_id,
                'top_level_parameters_preview' => is_array($details['parameters'] ?? null) ? array_slice((array) $details['parameters'], 0, 10) : [],
                'product_set_preview' => $product_set_preview,
            ]);
        }

        return $details;
    }

    public function get_offer_url(array $offer): string
    {
        if (!empty($offer['id'])) {
            return 'https://allegro.pl/oferta/' . rawurlencode((string) $offer['id']);
        }

        return '';
    }

    public function get_category_details(string $category_id)
    {
        $category_id = sanitize_text_field($category_id);
        if ($category_id === '') {
            return new \WP_Error('awi_missing_category_id', __('Brak category_id Allegro.', 'allegro-woo-importer'));
        }

        if (isset($this->category_cache[$category_id])) {
            return $this->category_cache[$category_id];
        }

        $details = $this->request('GET', '/sale/categories/' . rawurlencode($category_id));
        if (is_wp_error($details)) {
            return $details;
        }

        $this->category_cache[$category_id] = $details;

        return $details;
    }

    public function get_category_path(string $category_id)
    {
        $category_id = sanitize_text_field($category_id);
        if ($category_id === '') {
            return [];
        }

        if (isset($this->category_path_cache[$category_id])) {
            return $this->category_path_cache[$category_id];
        }

        $path = [];
        $cursor = $category_id;
        $guard = 0;

        while ($cursor !== '' && $guard < 20) {
            $guard++;
            $details = $this->get_category_details($cursor);
            if (is_wp_error($details)) {
                return $details;
            }

            $name = sanitize_text_field((string) ($details['name'] ?? ''));
            $id = sanitize_text_field((string) ($details['id'] ?? $cursor));
            if ($id !== '' && $name !== '') {
                array_unshift($path, ['id' => $id, 'name' => $name]);
            }

            $parent_id = sanitize_text_field((string) ($details['parent']['id'] ?? ''));
            if ($parent_id === '' || $parent_id === $cursor) {
                break;
            }
            $cursor = $parent_id;
        }

        $this->category_path_cache[$category_id] = $path;

        return $path;
    }

    public function get_category_children(string $category_id)
    {
        $category_id = sanitize_text_field($category_id);
        if ($category_id === '') {
            return [];
        }

        if (isset($this->category_children_cache[$category_id])) {
            return $this->category_children_cache[$category_id];
        }

        $response = $this->request('GET', '/sale/categories', [
            'query' => [
                'parent.id' => $category_id,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $items = is_array($response['categories'] ?? null) ? (array) $response['categories'] : [];
        $children = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = sanitize_text_field((string) ($item['id'] ?? ''));
            $name = sanitize_text_field((string) ($item['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $children[] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        $this->category_children_cache[$category_id] = $children;

        return $children;
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
