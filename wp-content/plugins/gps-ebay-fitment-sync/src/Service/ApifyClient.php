<?php

declare(strict_types=1);

namespace GPS_Ebay_Fitment_Sync\Service;

use GPS_Ebay_Fitment_Sync\Support\Settings;

final class ApifyClient
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function article_search_payload(string $partNumber): array
    {
        return [
            'endpoint_partsSearchArticlesByOem' => true,
            'parts_articleOemNo_29' => $partNumber,
            'parts_langId_29' => (int) $this->settings->get('lang_id'),
        ];
    }

    public function compatible_vehicles_payload(string $articleNo, int $supplierId): array
    {
        return [
            'endpoint_partsCompatibleVehiclesByArticleNoSupplierId' => true,
            'parts_typeId_21' => 1,
            'parts_articleNo_21' => $articleNo,
            'parts_supplierId_21' => $supplierId,
            'parts_langId_21' => (int) $this->settings->get('lang_id'),
            'parts_countryFilterId_21' => (int) $this->settings->get('country_filter_id'),
        ];
    }

    public function search_articles(string $partNumber): array
    {
        $response = $this->post($this->article_search_payload($partNumber));
        if (!$response['success']) {
            return ['success' => false, 'articles' => [], 'error' => $response['error']];
        }

        return ['success' => true, 'articles' => self::parse_articles($response['items']), 'error' => null];
    }

    public function compatible_vehicles(string $articleNo, int $supplierId): array
    {
        $response = $this->post($this->compatible_vehicles_payload($articleNo, $supplierId));
        if (!$response['success']) {
            return ['success' => false, 'vehicles' => [], 'error' => $response['error']];
        }

        return ['success' => true, 'vehicles' => self::parse_vehicles($response['items']), 'error' => null];
    }

    public function post(array $payload): array
    {
        $token = (string) $this->settings->get('apify_token');
        $actorId = (string) $this->settings->get('actor_id');
        if ($token === '') {
            return ['success' => false, 'items' => [], 'error' => 'Missing Apify token.'];
        }
        if ($actorId === '') {
            return ['success' => false, 'items' => [], 'error' => 'Missing Apify actor ID.'];
        }

        $url = sprintf('https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items?token=%s', rawurlencode($actorId), rawurlencode($token));
        $response = wp_remote_post($url, [
            'timeout' => (int) $this->settings->get('timeout'),
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'items' => [], 'error' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return ['success' => false, 'items' => [], 'error' => 'Apify HTTP error ' . $code . ': ' . substr($body, 0, 500)];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'items' => [], 'error' => 'Invalid JSON returned by Apify.'];
        }

        return ['success' => true, 'items' => $decoded, 'error' => null];
    }

    public static function parse_articles(array $items): array
    {
        $articles = [];
        foreach (self::flatten($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $articleId = self::first_value($item, ['articleId', 'article_id', 'id']);
            $articleNo = self::first_value($item, ['articleNo', 'article_no', 'articleNumber']);
            $supplierId = self::first_value($item, ['supplierId', 'supplier_id', 'brandId']);
            $supplierName = self::first_value($item, ['supplierName', 'supplier_name', 'brandName']);
            if ($articleNo === null || $supplierId === null) {
                continue;
            }
            $articles[] = [
                'articleId' => $articleId === null ? null : (int) $articleId,
                'articleNo' => (string) $articleNo,
                'supplierId' => (int) $supplierId,
                'supplierName' => $supplierName === null ? '' : (string) $supplierName,
                '_raw' => $item,
            ];
        }

        return array_values(self::unique_by($articles, static fn(array $article): string => $article['articleNo'] . ':' . $article['supplierId']));
    }

    public static function parse_vehicles(array $items): array
    {
        $vehicles = [];
        foreach (self::flatten($items) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (isset($item['compatibleCars']) && is_array($item['compatibleCars'])) {
                foreach ($item['compatibleCars'] as $car) {
                    if (is_array($car) && isset($car['vehicleId'])) {
                        $vehicles[] = $car;
                    }
                }
                continue;
            }
            if (isset($item['vehicleId'])) {
                $vehicles[] = $item;
            }
        }

        return array_values(self::unique_by($vehicles, static fn(array $vehicle): string => (string) ($vehicle['vehicleId'] ?? '')));
    }

    private static function flatten(array $items): array
    {
        $flat = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach (['articles', 'data', 'items', 'results'] as $key) {
                if (isset($item[$key]) && is_array($item[$key])) {
                    foreach ($item[$key] as $nested) {
                        $flat[] = $nested;
                    }
                    continue 2;
                }
            }
            $flat[] = $item;
        }

        return $flat;
    }

    private static function first_value(array $item, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== '') {
                return $item[$key];
            }
        }

        return null;
    }

    private static function unique_by(array $rows, callable $keyCallback): array
    {
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $key = $keyCallback($row);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }
}
