<?php

namespace GPS_Ebay_Fitment\Service;

use GPS_Ebay_Fitment\Repository\TecDocCacheRepository;
use GPS_Ebay_Fitment\Resolver\PartNumberResolver;

class ApifyTecDocLookupService
{
    private $cache_repository;
    private $resolver;

    public function __construct(TecDocCacheRepository $cache_repository)
    {
        $this->cache_repository = $cache_repository;
        $this->resolver = new PartNumberResolver();
    }

    public function settings()
    {
        $defaults = [
            'enabled' => 0,
            'api_token' => '',
            'actor_id' => '',
            'lang_id' => 4,
            'country_id' => 63,
            'type_id' => 1,
            'timeout' => 60,
            'max_articles_per_part_number' => 10,
            'max_ktype_count_before_needs_review' => 200,
            'dry_run' => 1,
            'cache_ttl_days' => 365,
        ];
        $settings = get_option('gps_ebay_fitment_apify_settings', []);
        return wp_parse_args(is_array($settings) ? $settings : [], $defaults);
    }

    public function lookup($part_number, $args = [])
    {
        $settings = $this->settings();
        $force_refresh = !empty($args['force_refresh']);
        $dry_run = array_key_exists('dry_run', $args) ? (bool) $args['dry_run'] : (bool) $settings['dry_run'];
        $normalized = $this->resolver->normalize($part_number);
        $base = [
            'part_number' => $part_number,
            'normalized_part_number' => $normalized,
            'provider' => TecDocCacheRepository::PROVIDER,
            'status' => 'pending',
            'confidence' => 0,
            'ktype_list' => [],
            'ktype_count' => 0,
            'matched_articles' => [],
            'matched_makes' => [],
            'matched_models' => [],
            'raw_summary' => [],
            'error' => '',
        ];

        if ($normalized === '') {
            return array_merge($base, ['status' => 'missing_part_number', 'error' => 'Empty part number after normalization.']);
        }

        if (!$force_refresh) {
            $cached = $this->cache_repository->get_valid($normalized);
            if ($cached) {
                return $this->cache_repository->row_to_result($cached);
            }
        }

        if (empty($settings['enabled']) || empty($settings['api_token']) || empty($settings['actor_id'])) {
            return array_merge($base, ['status' => 'not_configured', 'error' => 'Apify TecDoc lookup is disabled or missing token/actor ID.']);
        }

        $articles_response = $this->run_actor($settings, [
            'endpoint_partsSearchArticlesByOem' => true,
            'parts_articleOemNo_29' => $normalized,
            'parts_langId_29' => (int) $settings['lang_id'],
        ]);
        if (is_wp_error($articles_response)) {
            return $this->store_if_allowed(array_merge($base, ['status' => 'error', 'error' => $articles_response->get_error_message()]), $settings, $dry_run);
        }

        $articles = $this->unique_articles($articles_response);
        $articles = array_slice($articles, 0, max(1, (int) $settings['max_articles_per_part_number']));
        if (!$articles) {
            return $this->store_if_allowed(array_merge($base, [
                'status' => 'no_tecdoc_match',
                'raw_summary' => ['article_count' => 0],
            ]), $settings, $dry_run);
        }

        $ktypes = [];
        $makes = [];
        $models = [];
        $vehicle_samples = [];
        foreach ($articles as $article) {
            $vehicles_response = $this->run_actor($settings, [
                'endpoint_partsCompatibleVehiclesByArticleNoSupplierId' => true,
                'parts_typeId_21' => (int) $settings['type_id'],
                'parts_articleNo_21' => (string) $article['articleNo'],
                'parts_supplierId_21' => (int) $article['supplierId'],
                'parts_langId_21' => (int) $settings['lang_id'],
                'parts_countryFilterId_21' => (int) $settings['country_id'],
            ]);
            if (is_wp_error($vehicles_response)) {
                return $this->store_if_allowed(array_merge($base, [
                    'status' => 'error',
                    'matched_articles' => $articles,
                    'error' => $vehicles_response->get_error_message(),
                ]), $settings, $dry_run);
            }
            foreach ($vehicles_response as $compatibility_group) {
                $cars = isset($compatibility_group['compatibleCars']) && is_array($compatibility_group['compatibleCars']) ? $compatibility_group['compatibleCars'] : [];
                foreach ($cars as $car) {
                    if (!empty($car['vehicleId'])) {
                        $ktypes[(string) (int) $car['vehicleId']] = (int) $car['vehicleId'];
                    }
                    if (!empty($car['manufacturerName'])) {
                        $makes[$car['manufacturerName']] = $car['manufacturerName'];
                    }
                    if (!empty($car['modelName'])) {
                        $models[$car['modelName']] = $car['modelName'];
                    }
                    if (count($vehicle_samples) < 25) {
                        $vehicle_samples[] = [
                            'vehicleId' => isset($car['vehicleId']) ? (int) $car['vehicleId'] : 0,
                            'manufacturerName' => isset($car['manufacturerName']) ? $car['manufacturerName'] : '',
                            'modelName' => isset($car['modelName']) ? $car['modelName'] : '',
                            'typeEngineName' => isset($car['typeEngineName']) ? $car['typeEngineName'] : '',
                            'constructionIntervalStart' => isset($car['constructionIntervalStart']) ? $car['constructionIntervalStart'] : '',
                            'constructionIntervalEnd' => isset($car['constructionIntervalEnd']) ? $car['constructionIntervalEnd'] : '',
                        ];
                    }
                }
            }
        }

        $ktype_list = array_values($ktypes);
        sort($ktype_list, SORT_NUMERIC);
        $ktype_count = count($ktype_list);
        $status = $ktype_count > 0 ? 'ready' : 'no_tecdoc_match';
        if ($ktype_count > (int) $settings['max_ktype_count_before_needs_review']) {
            $status = 'needs_review';
        }

        $result = array_merge($base, [
            'status' => $status,
            'confidence' => $ktype_count > 0 ? 0.95 : 0,
            'ktype_list' => $ktype_list,
            'ktype_count' => $ktype_count,
            'matched_articles' => $articles,
            'matched_makes' => array_values($makes),
            'matched_models' => array_values($models),
            'raw_summary' => [
                'article_count' => count($articles),
                'vehicle_sample_count' => count($vehicle_samples),
                'vehicle_samples' => $vehicle_samples,
            ],
        ]);

        return $this->store_if_allowed($result, $settings, $dry_run);
    }

    private function store_if_allowed($result, $settings, $dry_run)
    {
        if (!$dry_run) {
            $this->cache_repository->upsert($result, (int) $settings['cache_ttl_days']);
        }
        return $result;
    }

    private function run_actor($settings, $input)
    {
        $actor_id = str_replace('/', '~', trim($settings['actor_id']));
        $url = sprintf('https://api.apify.com/v2/acts/%s/run-sync-get-dataset-items?token=%s', rawurlencode($actor_id), rawurlencode($settings['api_token']));
        $response = wp_remote_post($url, [
            'timeout' => max(5, (int) $settings['timeout']),
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($input),
        ]);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('gps_apify_http_error', 'Apify HTTP error ' . $code . '. Token hidden.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new \WP_Error('gps_apify_json_error', 'Apify returned invalid JSON.');
        }
        return $decoded;
    }

    private function unique_articles($rows)
    {
        $articles = [];
        foreach ($rows as $row) {
            if (empty($row['articleNo']) || empty($row['supplierId'])) {
                continue;
            }
            $key = (isset($row['articleId']) ? $row['articleId'] : '') . '|' . $row['articleNo'] . '|' . $row['supplierId'];
            $articles[$key] = [
                'articleId' => isset($row['articleId']) ? $row['articleId'] : '',
                'articleSearchNo' => isset($row['articleSearchNo']) ? $row['articleSearchNo'] : '',
                'articleNo' => (string) $row['articleNo'],
                'articleProductName' => isset($row['articleProductName']) ? $row['articleProductName'] : '',
                'manufacturerId' => isset($row['manufacturerId']) ? $row['manufacturerId'] : '',
                'manufacturerName' => isset($row['manufacturerName']) ? $row['manufacturerName'] : '',
                'supplierId' => (int) $row['supplierId'],
                'supplierName' => isset($row['supplierName']) ? $row['supplierName'] : '',
            ];
        }
        return array_values($articles);
    }
}
