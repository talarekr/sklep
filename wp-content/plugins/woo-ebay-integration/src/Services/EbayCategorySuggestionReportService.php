<?php

namespace WEI\Services;

use WEI\Plugin;
use WEI\Repositories\CategoryMappingRepository;
use WEI\Services\Translation\GoogleCloudTranslateProvider;

class EbayCategorySuggestionReportService
{
    private const AUTOMOTIVE_CONTEXT = 'Autoteile';
    private const PL_DE_AUTO_PHRASES = [
        'Wąż / Przewód klimatyzacji A/C' => 'Klimaanlagenschlauch Klimaleitung',
        'Przewód klimatyzacji' => 'Klimaleitung',
        'Wąż klimatyzacji' => 'Klimaanlagenschlauch',
        'Czujnik' => 'Sensor',
        'Zderzak' => 'Stoßstange',
        'Reflektor' => 'Scheinwerfer',
        'Lampa tylna' => 'Rückleuchte',
        'Lusterko' => 'Außenspiegel',
        'Drzwi' => 'Tür',
        'Maska' => 'Motorhaube',
        'Błotnik' => 'Kotflügel',
        'Alternator' => 'Lichtmaschine',
        'Rozrusznik' => 'Anlasser',
        'Turbosprężarka' => 'Turbolader',
    ];

    public const MARKETPLACE_ID = 'EBAY_DE';
    public const VALIDATION_OPTION = 'wei_ebay_category_validation_statuses';
    public const LAST_SUMMARY_OPTION = 'wei_ebay_category_suggestions_summary';
    public const CHECKPOINT_OPTION = 'wei_ebay_category_suggestions_checkpoint';

    public function __construct(private CategoryMappingRepository $categoryRepo, private EbayTaxonomyService $taxonomy, private Logger $logger)
    {
    }

    public function generate(array $args = []): array
    {
        $marketplaceId = self::MARKETPLACE_ID;
        $limit = max(1, min(500, (int) ($args['limit'] ?? 50)));
        $sampleLimit = max(1, min(5, (int) ($args['sample_limit'] ?? 5)));
        $topLimit = max(3, min(5, (int) ($args['top_limit'] ?? 5)));
        $mode = in_array((string) ($args['mode'] ?? 'leaf_with_products'), ['leaf_with_products', 'with_products', 'all_categories'], true) ? (string) $args['mode'] : 'leaf_with_products';
        $forceRefresh = !empty($args['force_refresh']);
        $restart = !empty($args['restart']);

        if ($restart) {
            delete_option(self::CHECKPOINT_OPTION);
        }
        $checkpoint = get_option(self::CHECKPOINT_OPTION, []);
        $offset = max(0, (int) ($args['offset'] ?? ($checkpoint['offset'] ?? 0)));

        $tree = $this->taxonomy->get_default_category_tree_id_result($marketplaceId, $forceRefresh);
        $categoryTreeId = (string) ($tree['category_tree_id'] ?? '');
        $rows = $this->categoryRepo->list_woo_categories_for_suggestions($marketplaceId, $limit, $offset, $mode);

        $summary = [
            'marketplace_id' => $marketplaceId,
            'category_tree_id' => $categoryTreeId,
            'woo_categories_processed' => 0,
            'mappings_with_current_id' => 0,
            'valid_current_mappings' => 0,
            'invalid_current_mappings' => 0,
            'non_leaf_current_mappings' => 0,
            'suggestions_found' => 0,
            'high_confidence' => 0,
            'medium_confidence' => 0,
            'low_confidence' => 0,
            'needs_manual_review' => 0,
            'api_errors' => 0,
            'batch' => ['limit' => $limit, 'offset' => $offset, 'next_offset' => $offset + count($rows), 'mode' => $mode, 'has_more' => count($rows) === $limit],
            'reports' => ['suggestions_csv' => ['path' => '', 'url' => ''], 'ready_to_import_csv' => ['path' => '', 'url' => '']],
        ];

        $reportRows = [];
        $readyRows = [];
        $existingValidation = get_option(self::VALIDATION_OPTION, []);
        $existingValidation = is_array($existingValidation) ? $existingValidation : [];
        $validationByCategoryId = is_array($existingValidation['by_category_id'] ?? null) ? $existingValidation['by_category_id'] : [];
        $validationByWooTerm = is_array($existingValidation['by_woo_term_id'] ?? null) ? $existingValidation['by_woo_term_id'] : [];

        foreach ($rows as $row) {
            $summary['woo_categories_processed']++;
            $termId = (int) ($row['term_id'] ?? 0);
            $currentId = trim((string) ($row['ebay_category_id'] ?? ''));
            $samples = $this->categoryRepo->sample_products_for_category($termId, $sampleLimit);
            $queryPlan = $this->build_german_query_plan((string) ($row['name'] ?? ''), (string) ($row['woo_category_path'] ?? ''), $samples);
            $queries = (array) ($queryPlan['queries'] ?? []);
            $taxonomyError = '';
            $chosenQuery = '';
            $chosenQuerySource = '';
            $suggestions = [];
            $rejectedSuggestions = [];

            foreach ($queries as $queryEntry) {
                $query = is_array($queryEntry) ? (string) ($queryEntry['query'] ?? '') : (string) $queryEntry;
                if ($query === '') {
                    continue;
                }
                $result = $this->taxonomy->get_category_suggestions_result($marketplaceId, $query, $forceRefresh);
                if (($result['status'] ?? '') !== 'ok') {
                    $taxonomyError = trim($taxonomyError . ' ' . (string) ($result['error'] ?? $result['status'] ?? 'suggestion_failed'));
                    $summary['api_errors']++;
                    continue;
                }
                $parsed = self::parse_suggestions((array) ($result['suggestions'] ?? []), $topLimit * 4);
                $queryRejected = [];
                $parsed = self::filter_and_rank_suggestions($parsed, (string) ($row['name'] ?? ''), (string) ($row['woo_category_path'] ?? ''), $query, $topLimit, $queryRejected);
                $rejectedSuggestions = array_merge($rejectedSuggestions, $queryRejected);
                if ($parsed !== []) {
                    $suggestions = $parsed;
                    $chosenQuery = $query;
                    $chosenQuerySource = is_array($queryEntry) ? (string) ($queryEntry['source'] ?? '') : '';
                    break;
                }
            }

            $currentValidation = $this->validate_current_category($marketplaceId, $currentId, $forceRefresh);
            if ($currentId !== '') {
                $summary['mappings_with_current_id']++;
                $validationByCategoryId[$currentId] = $currentValidation;
            }
            $validationByWooTerm[(string) $termId] = $currentValidation + [
                'woo_term_id' => $termId,
                'woo_category_path' => (string) ($row['woo_category_path'] ?? ''),
            ];

            if (!empty($currentValidation['valid']) && !empty($currentValidation['leaf'])) {
                $summary['valid_current_mappings']++;
            } elseif ($currentId !== '' && empty($currentValidation['valid'])) {
                $summary['invalid_current_mappings']++;
            } elseif ($currentId !== '' && empty($currentValidation['leaf'])) {
                $summary['non_leaf_current_mappings']++;
            }

            if ($suggestions !== []) {
                $summary['suggestions_found']++;
            }

            $status = self::mapping_status($currentId, $currentValidation, $suggestions);
            $confidence = self::confidence($currentId, $currentValidation, $suggestions, $status, $chosenQuery);
            if ($confidence === 'high') {
                $summary['high_confidence']++;
            } elseif ($confidence === 'medium') {
                $summary['medium_confidence']++;
            } elseif ($confidence === 'low') {
                $summary['low_confidence']++;
            } else {
                $summary['needs_manual_review']++;
            }

            $best = $suggestions[0] ?? [];
            $report = $this->build_report_row($row, $samples, $currentValidation, $status, $suggestions, $best, $confidence, $chosenQuery, $taxonomyError, $queryPlan, $chosenQuerySource, $rejectedSuggestions);
            $reportRows[] = $report;
            $readyRows[] = $this->build_ready_row($report, $currentId, $best);
        }

        $paths = $this->write_reports($reportRows, $readyRows);
        $summary['reports'] = $paths;
        update_option(self::VALIDATION_OPTION, ['by_category_id' => $validationByCategoryId, 'by_woo_term_id' => $validationByWooTerm, 'updated_at' => gmdate('c'), 'marketplace_id' => $marketplaceId, 'category_tree_id' => $categoryTreeId], false);
        update_option(self::LAST_SUMMARY_OPTION, $summary, false);
        update_option(self::CHECKPOINT_OPTION, ['offset' => $summary['batch']['next_offset'], 'updated_at' => gmdate('c'), 'mode' => $mode], false);

        return $summary;
    }

    public function build_german_query_plan(string $wooName, string $wooPath, array $samples = []): array
    {
        $sampleTitles = array_values(array_filter(array_map(static fn(array $sample): string => trim((string) ($sample['title'] ?? '')), $samples)));
        $existingTranslatedTitles = array_values(array_filter(array_map(static fn(array $sample): string => trim((string) ($sample['translated_title'] ?? '')), $samples)));
        $rawPolishQuery = self::clean_query(trim($wooName . ' ' . str_replace(' > ', ' ', $wooPath) . ' ' . implode(' ', array_slice($sampleTitles, 0, 3))));

        $translationInputs = [];
        $nameLocal = self::local_auto_phrase_translation($wooName);
        if ($nameLocal === '') {
            $translationInputs['name'] = $wooName;
        }
        $pathLocal = self::local_auto_phrase_translation($wooPath);
        if ($pathLocal === '') {
            $translationInputs['path'] = str_replace(' > ', ' ', $wooPath);
        }
        if ($existingTranslatedTitles === [] && $sampleTitles !== []) {
            foreach (array_slice($sampleTitles, 0, 2) as $idx => $title) {
                $translationInputs['title_' . $idx] = $title;
            }
        }

        $translated = $this->translate_to_german($translationInputs);
        $translationSource = (string) ($translated['source'] ?? 'local_dictionary');
        $translatedValues = (array) ($translated['values'] ?? []);

        $translatedName = $nameLocal !== '' ? $nameLocal : (string) ($translatedValues['name'] ?? $wooName);
        $translatedPath = $pathLocal !== '' ? $pathLocal : (string) ($translatedValues['path'] ?? str_replace(' > ', ' ', $wooPath));
        $sampleTranslatedTitles = $existingTranslatedTitles;
        foreach ($translatedValues as $key => $value) {
            if (str_starts_with((string) $key, 'title_') && trim((string) $value) !== '') {
                $sampleTranslatedTitles[] = trim((string) $value);
            }
        }
        $sampleTranslatedTitles = array_values(array_unique(array_filter($sampleTranslatedTitles)));

        $queries = [];
        $add = static function (array &$queries, string $query, string $source, string $raw, string $translatedText, string $translationSource): void {
            $query = self::ensure_automotive_context($query);
            if ($query !== '') {
                $queries[] = [
                    'query' => mb_substr($query, 0, 300),
                    'source' => $source,
                    'raw' => $raw,
                    'translated' => $translatedText,
                    'translation_source' => $translationSource,
                ];
            }
        };

        $add($queries, $translatedName, 'translated_woo_subcategory_name', $wooName, $translatedName, $nameLocal !== '' ? 'local_dictionary' : $translationSource);
        $add($queries, $translatedPath, 'translated_woo_category_path', $wooPath, $translatedPath, $pathLocal !== '' ? 'local_dictionary' : $translationSource);
        foreach (array_slice($sampleTranslatedTitles, 0, 2) as $title) {
            $add($queries, $title, 'sample_translated_product_title', implode(' | ', $sampleTitles), $title, $existingTranslatedTitles !== [] ? 'existing_ebay_title' : $translationSource);
        }
        foreach (array_slice($samples, 0, 3) as $sample) {
            $brandPart = trim((string) ($sample['manufacturer'] ?? '') . ' ' . (string) ($sample['mpn'] ?? '') . ' ' . $translatedName);
            $add($queries, $brandPart, 'sample_brand_model_part_name', (string) ($sample['title'] ?? ''), $brandPart, $nameLocal !== '' ? 'local_dictionary' : $translationSource);
        }

        $deduped = [];
        $seen = [];
        foreach ($queries as $query) {
            $key = mb_strtolower((string) $query['query']);
            if ($key !== '' && !isset($seen[$key])) {
                $seen[$key] = true;
                $deduped[] = $query;
            }
        }

        return [
            'raw_polish_query' => $rawPolishQuery,
            'translated_german_query' => self::clean_query(trim($translatedName . ' ' . $translatedPath)),
            'translation_source' => $nameLocal !== '' || $pathLocal !== '' ? 'local_dictionary' . ($translationSource !== 'disabled' ? '+' . $translationSource : '') : $translationSource,
            'sample_product_titles' => implode(' | ', array_slice($sampleTitles, 0, 5)),
            'sample_translated_titles' => implode(' | ', array_slice($sampleTranslatedTitles, 0, 5)),
            'queries' => $deduped,
        ];
    }

    public static function build_queries(string $wooName, string $wooPath, array $samples = []): array
    {
        $queries = [];
        $name = self::local_auto_phrase_translation($wooName);
        if ($name === '') {
            $name = self::looks_polish($wooName) ? '' : $wooName;
        }
        $path = self::local_auto_phrase_translation($wooPath);
        if ($path === '') {
            $path = self::looks_polish($wooPath) ? '' : str_replace(' > ', ' ', $wooPath);
        }
        foreach ([$name, $path] as $query) {
            $query = self::ensure_automotive_context($query);
            if ($query !== '') {
                $queries[] = $query;
            }
        }
        foreach (array_slice($samples, 0, 3) as $sample) {
            $title = trim((string) ($sample['translated_title'] ?? ''));
            if ($title === '') {
                $title = self::local_auto_phrase_translation((string) ($sample['title'] ?? ''));
            }
            $manufacturer = trim((string) ($sample['manufacturer'] ?? ''));
            $mpn = trim((string) ($sample['mpn'] ?? ''));
            $query = self::ensure_automotive_context(trim($title . ' ' . $manufacturer . ' ' . $mpn));
            if ($query !== '') {
                $queries[] = mb_substr($query, 0, 300);
            }
        }
        return array_values(array_unique(array_filter($queries)));
    }

    /** @param array<string,string> $texts */
    private function translate_to_german(array $texts): array
    {
        $texts = array_filter(array_map('strval', $texts), static fn(string $text): bool => trim($text) !== '');
        if ($texts === []) {
            return ['source' => 'local_dictionary', 'values' => []];
        }

        $settings = $this->settings();
        $providerKey = strtolower((string) ($settings['translation_provider'] ?? 'disabled'));
        if ($providerKey === 'google') {
            $providerKey = 'google_cloud_translate';
        }
        if ($providerKey !== 'google_cloud_translate') {
            return ['source' => 'disabled', 'values' => []];
        }

        try {
            $provider = new GoogleCloudTranslateProvider($settings, $this->logger);
            if (!$provider->is_configured()) {
                return ['source' => 'google_cloud_translate_not_configured', 'values' => []];
            }
            $keys = array_keys($texts);
            $values = $provider->translate_texts(array_values($texts), 'pl', 'de', 'text');
            $mapped = [];
            foreach ($keys as $idx => $key) {
                $mapped[(string) $key] = self::clean_query((string) ($values[$idx] ?? ''));
            }
            return ['source' => 'google_cloud_translate', 'values' => $mapped];
        } catch (\Throwable $e) {
            $this->logger->warning('eBay.de category suggestion query translation failed', ['error' => $e->getMessage()]);
            return ['source' => 'google_cloud_translate_failed', 'values' => []];
        }
    }

    private function settings(): array
    {
        $settings = function_exists('get_option') ? get_option(Plugin::OPTION_KEY, []) : [];
        if (!is_array($settings)) {
            $settings = [];
        }
        if (($settings['translation_provider'] ?? '') === 'google') {
            $settings['translation_provider'] = 'google_cloud_translate';
        }
        if (!isset($settings['translation_provider'])) {
            $settings['translation_provider'] = 'disabled';
        }
        if (!isset($settings['translation_api_key'])) {
            $settings['translation_api_key'] = '';
        }
        return $settings;
    }

    private static function local_auto_phrase_translation(string $text): string
    {
        $text = trim(wp_strip_all_tags($text));
        if ($text === '') {
            return '';
        }
        $normalized = self::normalize_lookup_text($text);
        $hits = [];
        foreach (self::PL_DE_AUTO_PHRASES as $pl => $de) {
            $needle = self::normalize_lookup_text((string) $pl);
            if ($needle !== '' && ($normalized === $needle || str_contains($normalized, $needle))) {
                $hits[] = (string) $de;
            }
        }
        if ($hits === [] && (str_contains($normalized, 'klimatyzacji') || str_contains($normalized, 'klima') || str_contains($normalized, 'a c'))) {
            $hits[] = 'Klimaanlagenschlauch Klimaleitung';
        }
        $query = self::clean_query(implode(' ', array_unique($hits)));
        $parts = preg_split('/\s+/', $query) ?: [];
        return implode(' ', array_values(array_unique(array_filter($parts))));
    }

    private static function normalize_lookup_text(string $text): string
    {
        $text = mb_strtolower(remove_accents(wp_strip_all_tags($text)));
        $text = str_replace(['/', '&', '-'], ' ', $text);
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private static function clean_query(string $query): string
    {
        return trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(html_entity_decode($query, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    private static function ensure_automotive_context(string $query): string
    {
        $query = self::clean_query($query);
        if ($query === '') {
            return '';
        }
        $normalized = mb_strtolower(remove_accents($query));
        if (!str_contains($normalized, 'autoteile') && !str_contains($normalized, 'auto ersatzteile') && !str_contains($normalized, 'auto & motorrad')) {
            $query .= ' ' . self::AUTOMOTIVE_CONTEXT;
        }
        return self::clean_query($query);
    }

    private static function looks_polish(string $text): bool
    {
        $normalized = self::normalize_lookup_text($text);
        return preg_match('/[ąćęłńóśźż]/iu', $text) === 1 || str_contains($normalized, 'czesci') || str_contains($normalized, 'samochod') || str_contains($normalized, 'przewod') || str_contains($normalized, 'waz');
    }

    public static function is_automotive_woo_category(string $wooName, string $wooPath): bool
    {
        $text = self::normalize_lookup_text($wooName . ' ' . $wooPath);
        if ($text === '') {
            return false;
        }
        if (self::local_auto_phrase_translation($wooName . ' ' . $wooPath) !== '') {
            return true;
        }
        foreach (['czesci', 'samochod', 'auto', 'motoryz', 'klima', 'zderzak', 'reflektor', 'lampa', 'lusterko', 'drzwi', 'maska', 'blotnik', 'alternator', 'rozrusznik', 'turbosprezarka', 'czujnik'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    public static function is_automotive_suggestion(array $suggestion): bool
    {
        $text = mb_strtolower(remove_accents((string) (($suggestion['category_path'] ?? '') . ' ' . ($suggestion['category_name'] ?? ''))));
        foreach (['auto & motorrad', 'autoteile', 'autoersatz', 'auto ersatz', 'fahrzeugteile', 'kfz', 'pkw', 'motorteile', 'karosserie', 'klimaanlage'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    public static function is_bad_generic_suggestion(array $suggestion): bool
    {
        $text = mb_strtolower(remove_accents((string) (($suggestion['category_path'] ?? '') . ' ' . ($suggestion['category_name'] ?? ''))));
        foreach (['sonstige', 'cds', 'bucher', 'bücher', 'dvds', 'filme', 'musik', 'computer', 'elektronik'] as $keyword) {
            if (str_contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }

    public static function filter_and_rank_suggestions(array $suggestions, string $wooName, string $wooPath, string $query, int $limit = 5, ?array &$rejected = null): array
    {
        $isAuto = self::is_automotive_woo_category($wooName, $wooPath);
        $queryTokens = self::query_match_tokens($query);
        $accepted = [];
        $rejected = [];
        foreach ($suggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $isAutomotiveSuggestion = self::is_automotive_suggestion($suggestion);
            if ($isAuto && !$isAutomotiveSuggestion) {
                $suggestion['rejected_reason'] = 'rejected_non_automotive';
                $rejected[] = $suggestion;
                continue;
            }
            $score = is_numeric($suggestion['score'] ?? null) ? (float) $suggestion['score'] : 0.0;
            $text = mb_strtolower(remove_accents((string) (($suggestion['category_path'] ?? '') . ' ' . ($suggestion['category_name'] ?? ''))));
            foreach ($queryTokens as $token) {
                if ($token !== '' && str_contains($text, $token)) {
                    $score += 0.20;
                }
            }
            if ($isAutomotiveSuggestion) {
                $score += 0.35;
            }
            if (self::is_bad_generic_suggestion($suggestion)) {
                $score -= 0.80;
            }
            $suggestion['score'] = (string) round($score, 4);
            $accepted[] = $suggestion;
        }
        usort($accepted, static fn(array $a, array $b): int => ((float) ($b['score'] ?? 0)) <=> ((float) ($a['score'] ?? 0)));
        return array_slice($accepted, 0, max(1, $limit));
    }

    private static function query_match_tokens(string $query): array
    {
        $query = mb_strtolower(remove_accents($query));
        $parts = preg_split('/[^a-z0-9]+/u', $query) ?: [];
        $stop = ['autoteile', 'auto', 'ersatzteile', 'gebraucht', 'teile'];
        return array_values(array_unique(array_filter($parts, static fn(string $token): bool => mb_strlen($token) >= 5 && !in_array($token, $stop, true))));
    }

    public static function parse_suggestions(array $rawSuggestions, int $limit = 5): array
    {
        $parsed = [];
        foreach ($rawSuggestions as $suggestion) {
            if (!is_array($suggestion)) {
                continue;
            }
            $category = is_array($suggestion['category'] ?? null) ? $suggestion['category'] : [];
            $id = trim((string) ($category['categoryId'] ?? $suggestion['categoryId'] ?? ''));
            $name = trim((string) ($category['categoryName'] ?? $suggestion['categoryName'] ?? ''));
            if ($id === '') {
                continue;
            }
            $pathNames = [];
            foreach ((array) ($suggestion['categoryTreeNodeAncestors'] ?? $suggestion['ancestors'] ?? []) as $ancestor) {
                if (!is_array($ancestor)) {
                    continue;
                }
                $ancestorName = trim((string) ($ancestor['categoryName'] ?? $ancestor['category']['categoryName'] ?? ''));
                if ($ancestorName !== '') {
                    $pathNames[] = $ancestorName;
                }
            }
            if ($name !== '') {
                $pathNames[] = $name;
            }
            $parsed[] = [
                'category_id' => $id,
                'category_name' => $name,
                'category_path' => $pathNames !== [] ? implode(' > ', array_values(array_unique($pathNames))) : $name,
                'score' => isset($suggestion['relevancy']) ? (string) $suggestion['relevancy'] : (isset($suggestion['score']) ? (string) $suggestion['score'] : ''),
                'raw' => $suggestion,
            ];
        }
        return array_slice($parsed, 0, max(1, $limit));
    }

    public static function mapping_status(string $currentId, array $validation, array $suggestions): string
    {
        if ($currentId !== '' && (empty($validation['valid']) || empty($validation['leaf']))) {
            return 'invalid_current';
        }
        $topIds = array_map(static fn(array $row): string => (string) ($row['category_id'] ?? ''), array_slice($suggestions, 0, 3));
        if ($currentId !== '' && in_array($currentId, $topIds, true) && !empty($validation['valid']) && !empty($validation['leaf'])) {
            return 'likely_ok';
        }
        if ($suggestions !== [] && $currentId !== (string) ($suggestions[0]['category_id'] ?? '')) {
            return 'review_suggested';
        }
        return 'needs_manual_review';
    }

    public static function confidence(string $currentId, array $validation, array $suggestions, string $status, string $query = ''): string
    {
        if ($suggestions === []) {
            return 'manual_review';
        }
        $top = $suggestions[0];
        if (self::is_bad_generic_suggestion($top)) {
            return 'low';
        }
        $automotive = self::is_automotive_suggestion($top);
        $matchedTokens = 0;
        $text = mb_strtolower(remove_accents((string) (($top['category_path'] ?? '') . ' ' . ($top['category_name'] ?? ''))));
        foreach (self::query_match_tokens($query) as $token) {
            if ($token !== '' && str_contains($text, $token)) {
                $matchedTokens++;
            }
        }
        if ($status === 'likely_ok' || ($automotive && $matchedTokens > 0 && ($status === 'review_suggested' || empty($validation['valid'])))) {
            return 'high';
        }
        if ($automotive && ($status === 'review_suggested' || $matchedTokens > 0)) {
            return 'medium';
        }
        return 'low';
    }

    public static function suggestion_report_columns(): array
    {
        $columns = ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','woo_category_slug','woo_category_url','products_count','current_ebay_category_id','current_ebay_category_valid','current_ebay_category_leaf','current_ebay_category_path','mapping_status'];
        for ($i = 1; $i <= 3; $i++) {
            array_push($columns, "suggested_ebay_category_id_{$i}", "suggested_ebay_category_name_{$i}", "suggested_ebay_category_path_{$i}", "suggested_ebay_category_score_{$i}");
        }
        return array_merge($columns, ['suggested_ebay_category_id','suggested_ebay_category_path','confidence','raw_polish_query','translated_german_query','query_used','query_source','translation_source','sample_product_ids','sample_product_titles','sample_translated_titles','rejected_suggestions','taxonomy_error','note']);
    }

    public static function ready_to_import_columns(): array
    {
        return ['woo_subcategory_id','woo_category_id','woo_subcategory_name','woo_category_path','products_count','old_ebay_category_id','ebay_category_id','suggested_ebay_category_path','confidence','mapping_status','note'];
    }

    private function validate_current_category(string $marketplaceId, string $categoryId, bool $forceRefresh): array
    {
        if ($categoryId === '') {
            return ['category_id' => '', 'valid' => false, 'leaf' => false, 'category_path' => '', 'validation_status' => 'missing'];
        }
        $details = $this->taxonomy->validate_category_result($marketplaceId, $categoryId, $forceRefresh);
        return $details;
    }

    private function build_report_row(array $row, array $samples, array $currentValidation, string $status, array $suggestions, array $best, string $confidence, string $query, string $error, array $queryPlan = [], string $querySource = '', array $rejectedSuggestions = []): array
    {
        $parentId = (int) ($row['parent'] ?? 0);
        $sampleIds = array_map(static fn(array $sample): string => (string) ($sample['id'] ?? ''), $samples);
        $sampleTitles = array_map(static fn(array $sample): string => (string) ($sample['title'] ?? ''), $samples);
        $report = array_fill_keys(self::suggestion_report_columns(), '');
        $report['woo_subcategory_id'] = (string) ($row['term_id'] ?? '');
        $report['woo_category_id'] = (string) ($parentId > 0 ? $parentId : ($row['term_id'] ?? ''));
        $report['woo_subcategory_name'] = (string) ($row['name'] ?? '');
        $report['woo_category_path'] = (string) ($row['woo_category_path'] ?? '');
        $report['woo_category_slug'] = (string) ($row['slug'] ?? '');
        $termLink = function_exists('get_term_link') ? get_term_link((int) ($row['term_id'] ?? 0), 'product_cat') : '';
        $report['woo_category_url'] = is_wp_error($termLink) ? '' : (string) $termLink;
        $report['products_count'] = (string) ($row['product_count'] ?? 0);
        $report['current_ebay_category_id'] = (string) ($row['ebay_category_id'] ?? '');
        $report['current_ebay_category_valid'] = !empty($currentValidation['valid']) ? '1' : '0';
        $report['current_ebay_category_leaf'] = !empty($currentValidation['leaf']) ? '1' : '0';
        $report['current_ebay_category_path'] = (string) ($currentValidation['category_path'] ?? '');
        $report['mapping_status'] = $status;
        foreach (array_slice($suggestions, 0, 3) as $idx => $suggestion) {
            $n = $idx + 1;
            $report["suggested_ebay_category_id_{$n}"] = (string) ($suggestion['category_id'] ?? '');
            $report["suggested_ebay_category_name_{$n}"] = (string) ($suggestion['category_name'] ?? '');
            $report["suggested_ebay_category_path_{$n}"] = (string) ($suggestion['category_path'] ?? '');
            $report["suggested_ebay_category_score_{$n}"] = (string) ($suggestion['score'] ?? '');
        }
        $report['suggested_ebay_category_id'] = (string) ($best['category_id'] ?? '');
        $report['suggested_ebay_category_path'] = (string) ($best['category_path'] ?? '');
        $report['confidence'] = $confidence;
        $report['raw_polish_query'] = (string) ($queryPlan['raw_polish_query'] ?? '');
        $report['translated_german_query'] = (string) ($queryPlan['translated_german_query'] ?? '');
        $report['query_used'] = $query;
        $report['query_source'] = $querySource;
        $report['translation_source'] = (string) ($queryPlan['translation_source'] ?? '');
        $report['sample_product_ids'] = implode('|', array_filter($sampleIds));
        $report['sample_product_titles'] = implode(' | ', array_filter($sampleTitles));
        $report['sample_translated_titles'] = (string) ($queryPlan['sample_translated_titles'] ?? '');
        $report['rejected_suggestions'] = implode(' | ', array_map(static fn(array $suggestion): string => trim((string) ($suggestion['category_id'] ?? '') . ':' . (string) ($suggestion['category_name'] ?? '') . ':' . (string) ($suggestion['rejected_reason'] ?? '')), array_slice($rejectedSuggestions, 0, 10)));
        $report['taxonomy_error'] = trim($error);
        $report['note'] = $status === 'invalid_current' ? 'Current eBay category is invalid/not found or non-leaf for EBAY_DE; review before import.' : 'Suggestion only; no production mapping was changed.';
        return $report;
    }

    private function build_ready_row(array $report, string $currentId, array $best): array
    {
        return [
            'woo_subcategory_id' => $report['woo_subcategory_id'],
            'woo_category_id' => $report['woo_category_id'],
            'woo_subcategory_name' => $report['woo_subcategory_name'],
            'woo_category_path' => $report['woo_category_path'],
            'products_count' => $report['products_count'],
            'old_ebay_category_id' => $currentId,
            'ebay_category_id' => (string) ($best['category_id'] ?? ''),
            'suggested_ebay_category_path' => (string) ($best['category_path'] ?? ''),
            'confidence' => $report['confidence'],
            'mapping_status' => $report['mapping_status'],
            'note' => $report['note'],
        ];
    }

    private function write_reports(array $reportRows, array $readyRows): array
    {
        $upload = wp_upload_dir();
        $baseDir = trailingslashit((string) $upload['basedir']) . 'woo-ebay-integration/category-suggestions';
        wp_mkdir_p($baseDir);
        $stamp = gmdate('Ymd-His');
        $suggestionsPath = $baseDir . '/ebay_de_category_suggestions_' . $stamp . '.csv';
        $readyPath = $baseDir . '/ready_to_import_suggestions.csv';
        $this->write_csv($suggestionsPath, self::suggestion_report_columns(), $reportRows);
        $this->write_csv($readyPath, self::ready_to_import_columns(), $readyRows);
        $baseUrl = trailingslashit((string) $upload['baseurl']) . 'woo-ebay-integration/category-suggestions';
        return [
            'suggestions_csv' => ['path' => $suggestionsPath, 'url' => $baseUrl . '/' . basename($suggestionsPath)],
            'ready_to_import_csv' => ['path' => $readyPath, 'url' => $baseUrl . '/' . basename($readyPath)],
        ];
    }

    private function write_csv(string $path, array $columns, array $rows): void
    {
        $fh = fopen($path, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Unable to write CSV: ' . $path);
        }
        fputcsv($fh, $columns);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(static fn(string $column): string => (string) ($row[$column] ?? ''), $columns));
        }
        fclose($fh);
    }
}
