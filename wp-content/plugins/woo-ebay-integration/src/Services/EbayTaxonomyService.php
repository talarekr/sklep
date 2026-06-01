<?php

namespace WEI\Services;

class EbayTaxonomyService
{
    /** @var array<string, array> */
    private array $treeResults = [];

    public function __construct(private EbayClient $client, private Logger $logger)
    {
    }

    public function get_default_category_tree_id(string $marketplace_id = 'EBAY_DE'): string
    {
        $result = $this->get_default_category_tree_id_result($marketplace_id);
        return (string) ($result['category_tree_id'] ?? '');
    }

    public function get_default_category_tree_id_result(string $marketplace_id = 'EBAY_DE', bool $force_refresh = false): array
    {
        $marketplace_id = trim($marketplace_id);
        $memoryKey = sanitize_key($marketplace_id);
        if ($force_refresh) {
            unset($this->treeResults[$memoryKey]);
        }
        if (!$force_refresh && isset($this->treeResults[$memoryKey])) {
            return $this->treeResults[$memoryKey];
        }

        $oauthContext = $this->client->taxonomy_oauth_context();
        $cacheKey = 'wei_taxonomy_tree_' . sanitize_key($marketplace_id);
        if ($force_refresh) {
            delete_transient($cacheKey);
        }
        $cached = get_transient($cacheKey);
        if (!$force_refresh && is_string($cached) && $cached !== '') {
            $result = ['status' => 'ok', 'category_tree_id' => $cached, 'source' => 'cache'] + $oauthContext;
            $this->logger->info('Loaded eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'category_tree_id' => $cached, 'source' => 'cache'] + $oauthContext);
            return $this->treeResults[$memoryKey] = $result;
        }

        $res = $this->client->get_default_category_tree_id($marketplace_id);
        if (is_wp_error($res)) {
            $status = $this->is_forbidden_error($res) ? 'taxonomy_api_forbidden' : 'taxonomy_tree_failed';
            $result = ['status' => $status, 'category_tree_id' => '', 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()] + $oauthContext;
            $this->logger->error('Unable to load eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'status' => $status, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()] + $oauthContext);
            return $this->treeResults[$memoryKey] = $result;
        }

        $treeId = (string) ($res['categoryTreeId'] ?? '');
        if ($treeId !== '') {
            set_transient($cacheKey, $treeId, DAY_IN_SECONDS * 7);
            $result = ['status' => 'ok', 'category_tree_id' => $treeId, 'source' => 'api'] + $oauthContext;
            $this->logger->info('Loaded eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'category_tree_id' => $treeId, 'source' => 'api'] + $oauthContext);
            return $this->treeResults[$memoryKey] = $result;
        }

        $result = ['status' => 'taxonomy_tree_failed', 'category_tree_id' => '', 'error' => 'eBay response did not include categoryTreeId'] + $oauthContext;
        $this->logger->error('Unable to load eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'status' => 'taxonomy_tree_failed', 'error' => 'eBay response did not include categoryTreeId'] + $oauthContext);
        return $this->treeResults[$memoryKey] = $result;
    }

    public function get_category_suggestions(string $marketplace_id, string $query): array
    {
        $result = $this->get_category_suggestions_result($marketplace_id, $query);
        return is_array($result['suggestions'] ?? null) ? $result['suggestions'] : [];
    }

    public function get_category_suggestions_result(string $marketplace_id, string $query, bool $force_refresh = false): array
    {
        $tree = $this->get_default_category_tree_id_result($marketplace_id, $force_refresh);
        $treeId = (string) ($tree['category_tree_id'] ?? '');
        if (($tree['status'] ?? '') === 'taxonomy_api_forbidden') {
            return ['status' => 'taxonomy_api_forbidden', 'suggestions' => [], 'error' => (string) ($tree['error'] ?? 'eBay Taxonomy API forbidden'), 'tree' => $tree];
        }
        if ($treeId === '' || trim($query) === '') {
            return ['status' => 'suggestion_failed', 'suggestions' => [], 'error' => $treeId === '' ? 'Missing eBay category_tree_id' : 'Missing suggestion query', 'tree' => $tree];
        }

        $cacheKey = 'wei_tax_suggest_' . md5($marketplace_id . '|' . $query);
        if ($force_refresh) {
            delete_transient($cacheKey);
        }
        $cached = get_transient($cacheKey);
        if (!$force_refresh && is_array($cached)) {
            return ['status' => 'ok', 'suggestions' => $cached, 'source' => 'cache', 'tree' => $tree];
        }

        $res = $this->client->get_category_suggestions($treeId, $query);
        if (is_wp_error($res)) {
            $status = $this->is_forbidden_error($res) ? 'taxonomy_api_forbidden' : 'suggestion_failed';
            $this->logger->error('Unable to load eBay category suggestions', ['marketplace_id' => $marketplace_id, 'category_tree_id' => $treeId, 'query' => $query, 'status' => $status, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()] + $this->client->taxonomy_oauth_context());
            return ['status' => $status, 'suggestions' => [], 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data(), 'tree' => $tree];
        }

        $suggestions = is_array($res['categorySuggestions'] ?? null) ? $res['categorySuggestions'] : [];
        set_transient($cacheKey, $suggestions, DAY_IN_SECONDS);
        return ['status' => 'ok', 'suggestions' => $suggestions, 'source' => 'api', 'tree' => $tree];
    }


    public function cached_category(string $marketplace_id, string $category_id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_tree_cache';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE marketplace_id=%s AND category_id=%s LIMIT 1",
            $marketplace_id,
            trim($category_id)
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return [
            'status' => 'ok',
            'category_id' => (string) ($row['category_id'] ?? ''),
            'category_name' => (string) ($row['category_name'] ?? ''),
            'category_path' => (string) ($row['category_path'] ?? ''),
            'leaf' => !empty($row['is_leaf']),
            'source' => 'local_tree_cache',
        ];
    }

    public function search_cached_automotive_categories(string $marketplace_id, string $query = '', int $limit = 50): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_tree_cache';
        $limit = max(1, min(100, $limit));
        $query = trim($query);
        if ($query !== '') {
            $like = '%' . $wpdb->esc_like($query) . '%';
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT category_id, category_name, category_path, is_leaf, parent_category_id FROM {$table} WHERE marketplace_id=%s AND is_automotive=1 AND (category_id=%s OR category_name LIKE %s OR category_path LIKE %s) ORDER BY is_leaf DESC, category_path ASC LIMIT %d",
                $marketplace_id,
                $query,
                $like,
                $like,
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT category_id, category_name, category_path, is_leaf, parent_category_id FROM {$table} WHERE marketplace_id=%s AND is_automotive=1 ORDER BY category_path ASC LIMIT %d",
                $marketplace_id,
                $limit
            ), ARRAY_A);
        }
        return is_array($rows) ? $rows : [];
    }

    public function import_cached_categories(string $marketplace_id, array $rows): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'wei_ebay_category_tree_cache';
        $now = gmdate('Y-m-d H:i:s');
        $summary = ['processed' => 0, 'inserted_or_updated' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $summary['skipped']++;
                continue;
            }
            $categoryId = trim((string) ($row['category_id'] ?? $row['categoryId'] ?? ''));
            $name = trim((string) ($row['category_name'] ?? $row['categoryName'] ?? ''));
            $path = trim((string) ($row['category_path'] ?? $row['categoryPath'] ?? $name));
            if ($categoryId === '' || $name === '') {
                $summary['skipped']++;
                continue;
            }
            $summary['processed']++;
            $isAutomotive = !empty($row['is_automotive']) || str_contains(strtolower($path), 'auto & motorrad teile') || str_contains(strtolower($path), 'autoteile');
            $data = [
                'marketplace_id' => $marketplace_id,
                'category_id' => $categoryId,
                'parent_category_id' => (string) ($row['parent_category_id'] ?? $row['parentCategoryId'] ?? ''),
                'category_name' => $name,
                'category_path' => $path,
                'is_leaf' => !empty($row['is_leaf']) || !empty($row['leaf']) ? 1 : 0,
                'is_automotive' => $isAutomotive ? 1 : 0,
                'imported_at' => $now,
                'updated_at' => $now,
            ];
            $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE marketplace_id=%s AND category_id=%s LIMIT 1", $marketplace_id, $categoryId), ARRAY_A);
            if (is_array($existing)) {
                $wpdb->update($table, $data, ['id' => (int) $existing['id']]);
            } else {
                $wpdb->insert($table, $data);
            }
            $summary['inserted_or_updated']++;
        }
        return $summary;
    }


    public function get_category_details_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        $category_id = trim($category_id);
        if ($category_id === '') {
            return ['status' => 'category_details_failed', 'category_id' => '', 'category_name' => 'unknown', 'category_path' => 'unknown', 'error' => 'Missing category_id'];
        }

        $local = $this->cached_category($marketplace_id, $category_id);
        if (!$force_refresh && is_array($local)) {
            return $local;
        }

        $cacheKey = 'wei_cat_details_' . sanitize_key($marketplace_id) . '_' . sanitize_key($category_id);
        if ($force_refresh) {
            delete_transient($cacheKey);
        }
        $cached = get_transient($cacheKey);
        if (!$force_refresh && is_array($cached)) {
            return $cached + ['source' => 'cache'];
        }

        $tree = $this->get_default_category_tree_id_result($marketplace_id, $force_refresh);
        $treeId = (string) ($tree['category_tree_id'] ?? '');
        if (($tree['status'] ?? '') === 'taxonomy_api_forbidden' || $treeId === '') {
            return ['status' => (string) ($tree['status'] ?? 'category_details_failed'), 'category_id' => $category_id, 'category_name' => 'unknown', 'category_path' => 'unknown', 'error' => (string) ($tree['error'] ?? 'Missing eBay category_tree_id')];
        }

        $res = $this->client->get_category_subtree($treeId, $category_id);
        if (is_wp_error($res)) {
            $status = $this->is_forbidden_error($res) ? 'taxonomy_api_forbidden' : 'category_details_failed';
            $this->logger->error('Unable to load eBay category details', ['marketplace_id' => $marketplace_id, 'category_id' => $category_id, 'status' => $status, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()]);
            return ['status' => $status, 'category_id' => $category_id, 'category_name' => 'unknown', 'category_path' => 'unknown', 'error' => $res->get_error_message()];
        }

        $node = is_array($res['categorySubtreeNode'] ?? null) ? $res['categorySubtreeNode'] : (is_array($res['categoryTreeNode'] ?? null) ? $res['categoryTreeNode'] : []);
        $category = is_array($node['category'] ?? null) ? $node['category'] : [];
        $name = trim((string) ($category['categoryName'] ?? $node['categoryName'] ?? 'unknown'));
        $names = [];
        foreach ((array) ($node['categoryTreeNodeAncestors'] ?? []) as $ancestor) {
            if (is_array($ancestor)) {
                $ancestorName = trim((string) ($ancestor['categoryName'] ?? $ancestor['category']['categoryName'] ?? ''));
                if ($ancestorName !== '') {
                    $names[] = $ancestorName;
                }
            }
        }
        if ($name !== '' && $name !== 'unknown') {
            $names[] = $name;
        }

        $children = is_array($node['childCategoryTreeNodes'] ?? null) ? $node['childCategoryTreeNodes'] : [];
        $leaf = isset($category['leafCategoryTreeNode']) ? (bool) $category['leafCategoryTreeNode'] : (isset($node['leafCategoryTreeNode']) ? (bool) $node['leafCategoryTreeNode'] : $children === []);
        $result = ['status' => 'ok', 'category_id' => $category_id, 'category_name' => $name !== '' ? $name : 'unknown', 'category_path' => $names !== [] ? implode(' > ', array_values(array_unique($names))) : 'unknown', 'leaf' => $leaf];
        set_transient($cacheKey, $result, DAY_IN_SECONDS * 7);
        return $result;
    }

    public function validate_category_result(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        $category_id = trim($category_id);
        if ($category_id === '') {
            return ['category_id' => '', 'valid' => false, 'leaf' => false, 'category_path' => '', 'validation_status' => 'missing', 'taxonomy_error' => 'Missing category_id'];
        }

        $details = $this->get_category_details_result($marketplace_id, $category_id, $force_refresh);
        if (($details['status'] ?? '') !== 'ok') {
            return [
                'category_id' => $category_id,
                'valid' => false,
                'leaf' => false,
                'category_path' => (string) ($details['category_path'] ?? ''),
                'validation_status' => 'invalid_ebay_category_id',
                'taxonomy_error' => (string) ($details['error'] ?? $details['status'] ?? 'category_details_failed'),
            ];
        }

        $leaf = !empty($details['leaf']);
        $aspects = $this->get_required_aspects($marketplace_id, $category_id, $force_refresh);
        return [
            'category_id' => $category_id,
            'valid' => true,
            'leaf' => $leaf,
            'category_name' => (string) ($details['category_name'] ?? ''),
            'category_path' => (string) ($details['category_path'] ?? ''),
            'validation_status' => $leaf ? 'valid_leaf' : 'non_leaf_ebay_category_id',
            'required_aspects_count' => count($aspects),
        ];
    }

    public function get_required_aspects(string $marketplace_id, string $category_id, bool $force_refresh = false): array
    {
        $category_id = trim($category_id);
        if ($category_id === '') {
            return [];
        }

        $cacheKey = 'wei_req_aspects_' . sanitize_key($marketplace_id) . '_' . sanitize_key($category_id);
        if ($force_refresh) {
            delete_transient($cacheKey);
        }
        $cached = get_transient($cacheKey);
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $required = $this->static_required_aspects($marketplace_id, $category_id);
        $tree = $this->get_default_category_tree_id_result($marketplace_id, $force_refresh);
        $treeId = (string) ($tree['category_tree_id'] ?? '');
        if ($treeId !== '') {
            $res = $this->client->get_item_aspects_for_category($treeId, $category_id);
            if (!is_wp_error($res)) {
                $required = [];
                foreach ((array) ($res['aspects'] ?? []) as $aspect) {
                    if (!is_array($aspect)) {
                        continue;
                    }
                    $constraint = is_array($aspect['aspectConstraint'] ?? null) ? $aspect['aspectConstraint'] : [];
                    if (!empty($constraint['aspectRequired'])) {
                        $name = trim((string) ($aspect['localizedAspectName'] ?? ''));
                        if ($name !== '') {
                            $required[] = $name;
                        }
                    }
                }
            } else {
                $this->logger->error('Unable to load eBay category aspects; using MVP static cache if available', [
                    'marketplace_id' => $marketplace_id,
                    'category_id' => $category_id,
                    'error' => $res->get_error_message(),
                ]);
            }
        }

        $required = array_values(array_unique(array_filter($required)));
        set_transient($cacheKey, $required, DAY_IN_SECONDS);
        return $required;
    }

    public function search_local_category_index(string $marketplace_id, string $query, array $expectedKeywords = [], int $limit = 20): array
    {
        $index = $this->get_local_category_index($marketplace_id);
        if ($index === []) {
            return [];
        }

        $queryTokens = $this->tokens($query);
        $expectedTokens = $this->tokens(implode(' ', $expectedKeywords));
        $scored = [];
        foreach ($index as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = (string) (($row['category_path'] ?? '') . ' ' . ($row['category_name'] ?? ''));
            $tokens = $this->tokens($text);
            $expectedMatches = $expectedTokens === [] ? [] : array_intersect($expectedTokens, $tokens);
            $queryMatches = $queryTokens === [] ? [] : array_intersect($queryTokens, $tokens);
            if ($expectedTokens !== [] && $expectedMatches === []) {
                continue;
            }

            $score = (count($expectedMatches) * 3.0) + (count($queryMatches) * 0.5);
            if (!empty($row['is_leaf'])) {
                $score += 1.0;
            }
            if ($this->is_sonstige_text($text)) {
                $score -= 5.0;
            }
            if ($score <= 0) {
                continue;
            }
            $row['index_score'] = round($score, 4);
            $scored[] = $row;
        }

        usort($scored, static fn(array $a, array $b): int => ((float) ($b['index_score'] ?? 0)) <=> ((float) ($a['index_score'] ?? 0)));
        return array_slice($scored, 0, max(1, $limit));
    }

    public function get_local_category_index(string $marketplace_id): array
    {
        $cacheKey = 'wei_tax_local_index_' . sanitize_key($marketplace_id) . '_auto_parts_v1';
        if ($force_refresh) {
            delete_transient($cacheKey);
        }
        $cached = get_transient($cacheKey);
        if (!$force_refresh && is_array($cached)) {
            return $cached;
        }

        $tree = $this->get_default_category_tree_id_result($marketplace_id, $force_refresh);
        $treeId = (string) ($tree['category_tree_id'] ?? '');
        if ($treeId === '') {
            return [];
        }

        $index = [];
        foreach ($this->local_index_root_category_ids($marketplace_id) as $rootId) {
            $res = $this->client->get_category_subtree($treeId, $rootId);
            if (is_wp_error($res)) {
                $this->logger->error('Unable to load eBay local category index subtree', ['marketplace_id' => $marketplace_id, 'category_id' => $rootId, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()]);
                continue;
            }
            $node = is_array($res['categorySubtreeNode'] ?? null) ? $res['categorySubtreeNode'] : (is_array($res['categoryTreeNode'] ?? null) ? $res['categoryTreeNode'] : []);
            if ($node !== []) {
                $this->flatten_category_node($node, [], $index);
            }
        }

        $deduped = [];
        foreach ($index as $row) {
            $id = (string) ($row['category_id'] ?? '');
            if ($id !== '') {
                $deduped[$id] = $row;
            }
        }
        $index = array_values($deduped);
        if ($index !== []) {
            set_transient($cacheKey, $index, DAY_IN_SECONDS * 7);
        }
        return $index;
    }

    private function local_index_root_category_ids(string $marketplace_id): array
    {
        if ($marketplace_id === 'EBAY_DE') {
            return ['131090']; // Auto & Motorrad: Teile
        }
        return [];
    }

    private function flatten_category_node(array $node, array $pathNames, array &$index): void
    {
        $category = is_array($node['category'] ?? null) ? $node['category'] : [];
        $id = trim((string) ($category['categoryId'] ?? $node['categoryId'] ?? ''));
        $name = trim((string) ($category['categoryName'] ?? $node['categoryName'] ?? ''));
        $children = is_array($node['childCategoryTreeNodes'] ?? null) ? $node['childCategoryTreeNodes'] : [];
        $isLeaf = isset($category['leafCategoryTreeNode']) ? (bool) $category['leafCategoryTreeNode'] : (isset($node['leafCategoryTreeNode']) ? (bool) $node['leafCategoryTreeNode'] : $children === []);
        $currentPath = $pathNames;
        if ($name !== '') {
            $currentPath[] = $name;
        }
        if ($id !== '' && $name !== '') {
            $index[] = [
                'category_id' => $id,
                'category_name' => $name,
                'category_path' => implode(' > ', array_values(array_unique($currentPath))),
                'is_leaf' => $isLeaf,
            ];
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->flatten_category_node($child, $currentPath, $index);
            }
        }
    }

    private function tokens(string $text): array
    {
        $text = function_exists('remove_accents') ? remove_accents($text) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = strtolower(str_replace(['ß', 'ü', 'ö', 'ä'], ['ss', 'u', 'o', 'a'], (string) $text));
        $parts = preg_split('/[^a-z0-9]+/u', $text) ?: [];
        $stop = ['und', 'oder', 'der', 'die', 'das', 'ein', 'eine', 'auto', 'motorrad', 'teile', 'zubehor'];
        return array_values(array_unique(array_filter($parts, static fn(string $token): bool => mb_strlen($token) >= 3 && !in_array($token, $stop, true))));
    }

    private function is_sonstige_text(string $text): bool
    {
        return str_contains(strtolower((string) $text), 'sonstige');
    }

    private function is_forbidden_error(\WP_Error $error): bool
    {
        $data = $error->get_error_data();
        if (is_array($data) && (int) ($data['status'] ?? 0) === 403) {
            return true;
        }
        $encoded = is_array($data) ? wp_json_encode($data) : (string) $data;
        return str_contains(strtolower($error->get_error_message() . ' ' . (string) $encoded), '403');
    }

    private function static_required_aspects(string $marketplace_id, string $category_id): array
    {
        if ($marketplace_id === 'EBAY_DE' && $category_id === '179847') {
            return ['Hersteller'];
        }
        if ($marketplace_id === 'EBAY_DE' && $category_id === '138858') {
            return ['Hersteller', 'Herstellernummer'];
        }

        return [];
    }
}
