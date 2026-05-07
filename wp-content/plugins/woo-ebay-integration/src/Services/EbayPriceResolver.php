<?php

namespace WEI\Services;

class EbayPriceResolver
{
    private const TRANSIENT_KEY = 'wei_nbp_eur_rate';
    private const LAST_OPTION_KEY = 'wei_nbp_eur_rate_last';
    private const NBP_EUR_URL = 'https://api.nbp.pl/api/exchangerates/rates/a/eur/?format=json';
    private const SPECIAL_CATEGORY_SLUGS = ['silniki-kompletne', 'kompletne-skrzynie'];
    private const SPECIAL_CATEGORY_PATHS = [
        ['motoryzacja', 'czesci-samochodowe', 'silniki-i-osprzet', 'silniki-kompletne'],
        ['motoryzacja', 'czesci-samochodowe', 'uklad-napedowy', 'skrzynie-biegow', 'kompletne-skrzynie'],
    ];

    public function __construct(private Logger $logger)
    {
    }

    public function resolve($product, int $product_id, array $settings, bool $suppressLog = false): array
    {
        $basePricePln = $this->product_price_pln($product);
        $markup = $this->resolve_markup($product, $product_id, $settings);
        $rate = $suppressLog ? $this->get_cached_eur_rate() : $this->get_eur_rate($settings);

        $result = [
            'base_price_pln' => $basePricePln,
            'markup_percent' => $markup['markup_percent'],
            'markup_source' => $markup['markup_source'],
            'marked_price_pln' => null,
            'currency_source' => 'nbp_table_a',
            'nbp_rate' => $rate['nbp_rate'],
            'nbp_effective_date' => $rate['nbp_effective_date'],
            'nbp_table_no' => $rate['nbp_table_no'],
            'ebay_price_eur' => null,
            'ready' => false,
            'error' => '',
        ];

        if ($basePricePln <= 0) {
            $result['error'] = 'invalid_price';
            if (!$suppressLog) {
                $this->log_resolution($product_id, $result);
            }
            return $result;
        }

        if (empty($rate['ready']) || (float) $rate['nbp_rate'] <= 0) {
            $result['error'] = 'missing_exchange_rate';
            if (!$suppressLog) {
                $this->log_resolution($product_id, $result);
            }
            return $result;
        }

        $markedPricePln = round($basePricePln * (1 + ((float) $markup['markup_percent'] / 100)), 2);
        $ebayPriceEur = round($markedPricePln / (float) $rate['nbp_rate'], 2);
        if ($ebayPriceEur <= 0) {
            $result['error'] = 'invalid_price';
            if (!$suppressLog) {
                $this->log_resolution($product_id, $result);
            }
            return $result;
        }

        $result['marked_price_pln'] = $markedPricePln;
        $result['ebay_price_eur'] = $ebayPriceEur;
        $result['ready'] = true;
        if (!$suppressLog) {
            $this->log_resolution($product_id, $result);
        }
        return $result;
    }

    public function get_rate_status(array $settings): array
    {
        $rate = $this->get_eur_rate($settings);
        $fetchedAt = (int) ($rate['fetched_at'] ?? 0);
        return array_merge($rate, [
            'cache_age_seconds' => $fetchedAt > 0 ? max(0, time() - $fetchedAt) : null,
            'cache_status' => !empty($rate['from_transient']) ? 'fresh' : (!empty($rate['from_last_saved']) ? 'last_saved' : (!empty($rate['ready']) ? 'refreshed' : 'missing')),
        ]);
    }

    public function readiness_summary(array $settings, int $limit = 500): array
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private', 'draft'],
            'fields' => 'ids',
            'numberposts' => max(1, $limit),
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $summary = [
            'checked' => 0,
            'ready_price_ok' => 0,
            'blocked_by_invalid_price' => 0,
            'blocked_by_missing_exchange_rate' => 0,
        ];
        foreach ((array) $ids as $id) {
            $product = wc_get_product((int) $id);
            if (!$product) {
                continue;
            }
            $summary['checked']++;
            $resolution = $this->resolve($product, (int) $id, $settings);
            if (!empty($resolution['ready'])) {
                $summary['ready_price_ok']++;
                continue;
            }
            if (($resolution['error'] ?? '') === 'missing_exchange_rate') {
                $summary['blocked_by_missing_exchange_rate']++;
            } else {
                $summary['blocked_by_invalid_price']++;
            }
        }

        return $summary;
    }

    private function product_price_pln($product): float
    {
        $raw = $product ? $product->get_price() : '';
        if (function_exists('wc_format_decimal')) {
            return (float) wc_format_decimal($raw, 6);
        }

        return (float) str_replace(',', '.', (string) $raw);
    }

    private function resolve_markup($product, int $product_id, array $settings): array
    {
        $default = $this->positive_percent($settings['ebay_default_markup_percent'] ?? 25, 25);
        $special = $this->positive_percent($settings['ebay_special_category_markup_percent'] ?? 30, 30);
        foreach ($this->product_category_slug_paths($product, $product_id) as $path) {
            $leaf = (string) end($path);
            if (in_array($leaf, self::SPECIAL_CATEGORY_SLUGS, true) || $this->matches_special_path($path)) {
                return ['markup_percent' => $special, 'markup_source' => 'special_woo_category:' . implode('/', $path)];
            }
        }

        return ['markup_percent' => $default, 'markup_source' => 'default'];
    }

    private function positive_percent($value, float $fallback): float
    {
        $percent = (float) $value;
        return $percent > 0 ? round($percent, 4) : $fallback;
    }

    private function product_category_slug_paths($product, int $product_id): array
    {
        $sourceProductId = $product && method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0 ? (int) $product->get_parent_id() : $product_id;
        $termIds = $product && method_exists($product, 'get_category_ids') ? (array) $product->get_category_ids() : [];
        if ($termIds === []) {
            $termIds = wp_get_post_terms($sourceProductId, 'product_cat', ['fields' => 'ids']);
        }

        $paths = [];
        foreach ((array) $termIds as $termId) {
            $termId = (int) $termId;
            if ($termId <= 0) {
                continue;
            }
            $ancestorIds = array_reverse(array_map('intval', get_ancestors($termId, 'product_cat')));
            $path = [];
            foreach (array_merge($ancestorIds, [$termId]) as $pathTermId) {
                $term = get_term($pathTermId, 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $path[] = sanitize_title((string) $term->slug);
                }
            }
            if ($path !== []) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function matches_special_path(array $path): bool
    {
        foreach (self::SPECIAL_CATEGORY_PATHS as $specialPath) {
            $length = count($specialPath);
            if ($length > 0 && array_slice($path, -$length) === $specialPath) {
                return true;
            }
        }

        return false;
    }

    private function get_cached_eur_rate(): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && (float) ($cached['nbp_rate'] ?? 0) > 0) {
            $cached['ready'] = true;
            $cached['from_transient'] = true;
            return $cached;
        }

        $last = get_option(self::LAST_OPTION_KEY, []);
        if (is_array($last) && (float) ($last['nbp_rate'] ?? 0) > 0) {
            $last['ready'] = true;
            $last['from_last_saved'] = true;
            return $last;
        }

        return [
            'ready' => false,
            'nbp_rate' => null,
            'nbp_effective_date' => '',
            'nbp_table_no' => '',
            'fetched_at' => 0,
            'error' => 'missing_cached_exchange_rate',
        ];
    }

    private function get_eur_rate(array $settings): array
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (is_array($cached) && (float) ($cached['nbp_rate'] ?? 0) > 0) {
            $cached['ready'] = true;
            $cached['from_transient'] = true;
            return $cached;
        }

        $fetched = $this->fetch_eur_rate();
        if (!empty($fetched['ready'])) {
            set_transient(self::TRANSIENT_KEY, $fetched, $this->cache_ttl_seconds($settings));
            update_option(self::LAST_OPTION_KEY, $fetched, false);
            return $fetched;
        }

        $last = get_option(self::LAST_OPTION_KEY, []);
        if (is_array($last) && (float) ($last['nbp_rate'] ?? 0) > 0) {
            $last['ready'] = true;
            $last['from_last_saved'] = true;
            $last['fetch_error'] = (string) ($fetched['error'] ?? 'nbp_api_unavailable');
            return $last;
        }

        return [
            'ready' => false,
            'nbp_rate' => null,
            'nbp_effective_date' => '',
            'nbp_table_no' => '',
            'fetched_at' => 0,
            'error' => (string) ($fetched['error'] ?? 'missing_exchange_rate'),
        ];
    }

    private function fetch_eur_rate(): array
    {
        $response = wp_remote_get(self::NBP_EUR_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response)) {
            return ['ready' => false, 'error' => $response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['ready' => false, 'error' => 'nbp_http_' . $code];
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
        $row = is_array($decoded['rates'][0] ?? null) ? $decoded['rates'][0] : [];
        $mid = (float) ($row['mid'] ?? 0);
        if ($mid <= 0) {
            return ['ready' => false, 'error' => 'nbp_rate_missing'];
        }

        return [
            'ready' => true,
            'currency_source' => 'nbp_table_a',
            'nbp_rate' => $mid,
            'nbp_effective_date' => (string) ($row['effectiveDate'] ?? ''),
            'nbp_table_no' => (string) ($row['no'] ?? ''),
            'fetched_at' => time(),
        ];
    }

    private function cache_ttl_seconds(array $settings): int
    {
        $hours = (float) ($settings['nbp_rate_cache_ttl_hours'] ?? 12);
        $ttl = (int) round($hours * HOUR_IN_SECONDS);
        if ($ttl <= 0) {
            $ttl = 12 * HOUR_IN_SECONDS;
        }

        return $ttl;
    }

    private function log_resolution(int $product_id, array $resolution): void
    {
        $this->logger->info('Resolved EBAY_DE offer price from WooCommerce PLN price', [
            'product_id' => $product_id,
            'base_price_pln' => $resolution['base_price_pln'],
            'markup_percent' => $resolution['markup_percent'],
            'markup_source' => $resolution['markup_source'],
            'nbp_rate' => $resolution['nbp_rate'],
            'nbp_effective_date' => $resolution['nbp_effective_date'],
            'ebay_price_eur' => $resolution['ebay_price_eur'],
            'wrote_woo_price' => false,
            'ready' => !empty($resolution['ready']),
            'error' => (string) ($resolution['error'] ?? ''),
        ]);
    }
}
