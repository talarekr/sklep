<?php

namespace WEI\Services;

use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\Translation\GoogleCloudTranslateProvider;

class AutoCategoryMappingService
{
    private const AUTO_CONFIDENCE_THRESHOLD = 0.85;
    private const REVIEW_CONFIDENCE_THRESHOLD = 0.60;

    public function __construct(private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }

    public function auto_map_used_categories(string $marketplaceId = 'EBAY_DE', int $limit = 200): array
    {
        $settings = $this->settings();
        $marketplaceId = trim($marketplaceId) !== '' ? trim($marketplaceId) : (string) ($settings['marketplace_id'] ?? 'EBAY_DE');
        $rows = $this->categoryRepo->list_used_woo_categories($marketplaceId, $limit);
        $summary = ['marketplace_id' => $marketplaceId, 'processed' => 0, 'mapped_auto' => 0, 'needs_category_review' => 0, 'unmapped' => 0, 'taxonomy_api_forbidden' => 0, 'suggestion_failed' => 0, 'skipped_confirmed' => 0];

        foreach ($rows as $row) {
            $termId = (int) ($row['term_id'] ?? 0);
            if ($termId <= 0) {
                continue;
            }

            $existing = $this->categoryRepo->find($marketplaceId, $termId);
            if ($this->is_confirmed_mapping($existing)) {
                $summary['skipped_confirmed']++;
                continue;
            }

            $result = $this->auto_map_term($termId, $marketplaceId, $settings);
            $summary['processed']++;
            $status = (string) ($result['status'] ?? 'suggestion_failed');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    public function auto_map_term(int $termId, string $marketplaceId = 'EBAY_DE', ?array $settings = null): array
    {
        $settings = $settings ?? $this->settings();
        $path = $this->categoryRepo->woo_category_path($termId);
        $samples = $this->categoryRepo->sample_products_for_category($termId, 5);
        $sampleIds = array_map(static fn(array $sample): int => (int) ($sample['id'] ?? 0), $samples);
        $querySource = $this->build_query($path, $samples);
        $query = $this->translate_query_to_german($querySource, $settings);

        $base = [
            'marketplace_id' => $marketplaceId,
            'woo_term_id' => $termId,
            'woo_category_path' => $path,
            'sample_product_ids' => wp_json_encode(array_values(array_filter($sampleIds))),
        ];

        $result = $this->taxonomy->get_category_suggestions_result($marketplaceId, $query);
        if (($result['status'] ?? '') === 'taxonomy_api_forbidden') {
            $this->categoryRepo->upsert(array_merge($base, [
                'ebay_category_id' => '',
                'ebay_category_name' => '',
                'ebay_category_path' => '',
                'source' => 'suggestion',
                'confidence' => 0,
                'status' => 'taxonomy_api_forbidden',
                'error_reason' => (string) ($result['error'] ?? 'eBay Taxonomy API forbidden'),
                'suggestion_payload' => $this->debug_payload($querySource, $query, [], $result),
            ]));
            return ['status' => 'taxonomy_api_forbidden'];
        }

        $suggestions = is_array($result['suggestions'] ?? null) ? $result['suggestions'] : [];
        if ($suggestions === []) {
            $this->categoryRepo->upsert(array_merge($base, [
                'ebay_category_id' => '',
                'ebay_category_name' => '',
                'ebay_category_path' => '',
                'source' => 'suggestion',
                'confidence' => 0,
                'status' => ($result['status'] ?? '') === 'ok' ? 'unmapped' : 'suggestion_failed',
                'error_reason' => (string) ($result['error'] ?? 'No eBay category suggestions returned'),
                'suggestion_payload' => $this->debug_payload($querySource, $query, [], $result),
            ]));
            return ['status' => ($result['status'] ?? '') === 'ok' ? 'unmapped' : 'suggestion_failed'];
        }

        $best = $this->pick_best_suggestion($suggestions, $query, $samples, $marketplaceId);
        $categoryId = (string) ($best['category_id'] ?? '');
        $confidence = (float) ($best['confidence'] ?? 0);
        $status = 'unmapped';
        $source = 'suggestion';
        $errorReason = '';

        if ($categoryId === '') {
            $status = 'suggestion_failed';
            $errorReason = 'Suggestion payload did not include categoryId';
        } elseif ($confidence >= self::AUTO_CONFIDENCE_THRESHOLD) {
            $status = 'mapped_auto';
            $source = 'auto_taxonomy';
        } elseif ($confidence >= self::REVIEW_CONFIDENCE_THRESHOLD) {
            $status = 'needs_category_review';
        } else {
            $status = 'unmapped';
            $errorReason = 'Best suggestion confidence below review threshold';
        }

        $this->categoryRepo->upsert(array_merge($base, [
            'ebay_category_id' => in_array($status, ['mapped_auto', 'needs_category_review'], true) ? $categoryId : '',
            'ebay_category_name' => (string) ($best['category_name'] ?? ''),
            'ebay_category_path' => (string) ($best['category_path'] ?? ''),
            'source' => $source,
            'confidence' => $confidence,
            'status' => $status,
            'error_reason' => $errorReason,
            'suggestion_payload' => $this->debug_payload($querySource, $query, $best, $result),
        ]));

        $this->logger->info('Auto category mapping evaluated', ['woo_term_id' => $termId, 'status' => $status, 'confidence' => $confidence, 'category_id' => $categoryId]);
        return ['status' => $status, 'confidence' => $confidence, 'category_id' => $categoryId];
    }

    private function is_confirmed_mapping(?array $mapping): bool
    {
        if (!$mapping || trim((string) ($mapping['ebay_category_id'] ?? '')) === '') {
            return false;
        }

        $status = (string) ($mapping['status'] ?? '');
        $source = (string) ($mapping['source'] ?? '');
        return in_array($status, ['mapped_manual', 'mapped_auto'], true) || ($status === '' && $source === 'manual');
    }

    private function build_query(string $path, array $samples): string
    {
        $parts = [$path];
        foreach ($samples as $sample) {
            foreach (['title', 'mpn', 'manufacturer'] as $key) {
                $value = trim((string) ($sample[$key] ?? ''));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', array_unique($parts))) ?: '');
    }

    private function translate_query_to_german(string $query, array $settings): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $cacheKey = 'wei_cat_query_de_' . md5($query);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $translated = $query;
        $providerKey = strtolower((string) ($settings['translation_provider'] ?? 'disabled'));
        if ($providerKey === 'google') {
            $providerKey = 'google_cloud_translate';
        }

        if ($providerKey === 'google_cloud_translate') {
            try {
                $provider = new GoogleCloudTranslateProvider($settings, $this->logger);
                if ($provider->is_configured() && method_exists($provider, 'translate_texts')) {
                    $values = $provider->translate_texts([$query], 'pl', 'de', 'text');
                    $translated = trim((string) ($values[0] ?? $query));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Category mapping query translation failed; using source query', ['error' => $e->getMessage()]);
            }
        }

        $translated = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($translated, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: $query);
        set_transient($cacheKey, $translated, DAY_IN_SECONDS * 14);
        return $translated;
    }

    private function pick_best_suggestion(array $suggestions, string $query, array $samples, string $marketplaceId): array
    {
        $best = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
            $categoryId = trim((string) ($category['categoryId'] ?? ''));
            $categoryName = trim((string) ($category['categoryName'] ?? ''));
            $path = $this->suggestion_path($suggestion, $categoryName);
            $required = $categoryId !== '' ? $this->taxonomy->get_required_aspects($marketplaceId, $categoryId) : [];
            $confidence = $this->score_suggestion($query, $path . ' ' . $categoryName, $suggestion, $samples, $required);
            if ($best === [] || $confidence > (float) ($best['confidence'] ?? 0)) {
                $best = [
                    'category_id' => $categoryId,
                    'category_name' => $categoryName,
                    'category_path' => $path,
                    'confidence' => $confidence,
                    'required_aspects' => $required,
                    'raw_summary' => $this->summarize_suggestion($suggestion),
                ];
            }
        }

        return $best;
    }

    private function score_suggestion(string $query, string $suggestionText, array $suggestion, array $samples, array $requiredAspects): float
    {
        $queryTokens = $this->tokens($query);
        $suggestionTokens = $this->tokens($suggestionText);
        $overlapDenominator = max(1, min(count(array_unique($queryTokens)), count(array_unique($suggestionTokens))));
        $overlap = $queryTokens === [] ? 0 : count(array_intersect($queryTokens, $suggestionTokens)) / $overlapDenominator;
        $score = 0.40 + min(0.30, $overlap * 0.45);

        $automotiveTokens = ['auto', 'fahrzeug', 'kabel', 'leitung', 'kabelbaum', 'motor', 'teile', 'ersatzteile', 'oe', 'oem', 'mpn', 'hersteller'];
        if (array_intersect($queryTokens, $automotiveTokens) || array_intersect($suggestionTokens, $automotiveTokens)) {
            $score += 0.15;
        }

        if ($this->suggestion_is_leaf($suggestion)) {
            $score += 0.15;
        }

        $hasIdentifiers = false;
        $hasManufacturer = false;
        foreach ($samples as $sample) {
            $hasIdentifiers = $hasIdentifiers || trim((string) ($sample['mpn'] ?? '')) !== '';
            $hasManufacturer = $hasManufacturer || trim((string) ($sample['manufacturer'] ?? '')) !== '';
        }
        if ($hasIdentifiers) {
            $score += 0.05;
        }
        if ($hasManufacturer) {
            $score += 0.05;
        }
        if ($requiredAspects === [] || !in_array('Hersteller', $requiredAspects, true) || $hasManufacturer) {
            $score += 0.05;
        }

        return round(min(0.99, max(0.0, $score)), 4);
    }

    private function suggestion_path(array $suggestion, string $categoryName): string
    {
        $names = [];
        foreach ((array) ($suggestion['categoryTreeNodeAncestors'] ?? []) as $ancestor) {
            if (is_array($ancestor)) {
                $name = trim((string) ($ancestor['categoryName'] ?? $ancestor['category']['categoryName'] ?? ''));
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        if ($categoryName !== '') {
            $names[] = $categoryName;
        }
        return implode(' > ', array_values(array_unique($names)));
    }

    private function suggestion_is_leaf(array $suggestion): bool
    {
        if (isset($suggestion['category']['leafCategoryTreeNode'])) {
            return (bool) $suggestion['category']['leafCategoryTreeNode'];
        }
        if (isset($suggestion['leafCategoryTreeNode'])) {
            return (bool) $suggestion['leafCategoryTreeNode'];
        }
        return empty($suggestion['childCategoryTreeNodes']);
    }

    private function tokens(string $text): array
    {
        $text = strtolower(remove_accents(wp_strip_all_tags($text)));
        $parts = preg_split('/[^a-z0-9äöüß]+/u', $text) ?: [];
        $stop = ['und', 'oder', 'der', 'die', 'das', 'ein', 'eine', 'do', 'dla', 'oraz', 'w', 'z', 'na', 'the', 'and'];
        return array_values(array_unique(array_filter($parts, static fn(string $token): bool => mb_strlen($token) >= 3 && !in_array($token, $stop, true))));
    }

    private function debug_payload(string $sourceQuery, string $translatedQuery, array $best, array $result): string
    {
        return wp_json_encode([
            'query_source' => mb_substr($sourceQuery, 0, 500),
            'query_de' => mb_substr($translatedQuery, 0, 500),
            'best' => $best,
            'taxonomy_status' => $result['status'] ?? '',
            'error' => $result['error'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
    }

    private function summarize_suggestion(array $suggestion): array
    {
        $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
        return [
            'categoryId' => (string) ($category['categoryId'] ?? ''),
            'categoryName' => (string) ($category['categoryName'] ?? ''),
            'path' => $this->suggestion_path($suggestion, (string) ($category['categoryName'] ?? '')),
        ];
    }

    private function settings(): array
    {
        $settings = get_option(Plugin::OPTION_KEY, []);
        return is_array($settings) ? $settings : [];
    }
}
