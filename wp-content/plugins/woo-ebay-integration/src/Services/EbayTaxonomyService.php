<?php

namespace WEI\Services;

class EbayTaxonomyService
{
    public function __construct(private EbayClient $client, private Logger $logger)
    {
    }

    public function get_default_category_tree_id(string $marketplace_id = 'EBAY_DE'): string
    {
        $result = $this->get_default_category_tree_id_result($marketplace_id);
        return (string) ($result['category_tree_id'] ?? '');
    }

    public function get_default_category_tree_id_result(string $marketplace_id = 'EBAY_DE'): array
    {
        $cacheKey = 'wei_taxonomy_tree_' . sanitize_key($marketplace_id);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return ['status' => 'ok', 'category_tree_id' => $cached, 'source' => 'cache'];
        }

        $res = $this->client->get_default_category_tree_id($marketplace_id);
        if (is_wp_error($res)) {
            $status = $this->is_forbidden_error($res) ? 'taxonomy_api_forbidden' : 'taxonomy_tree_failed';
            $this->logger->error('Unable to load eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'status' => $status, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()]);
            return ['status' => $status, 'category_tree_id' => '', 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()];
        }

        $treeId = (string) ($res['categoryTreeId'] ?? '');
        if ($treeId !== '') {
            set_transient($cacheKey, $treeId, DAY_IN_SECONDS * 7);
            return ['status' => 'ok', 'category_tree_id' => $treeId, 'source' => 'api'];
        }

        return ['status' => 'taxonomy_tree_failed', 'category_tree_id' => '', 'error' => 'eBay response did not include categoryTreeId'];
    }

    public function get_category_suggestions(string $marketplace_id, string $query): array
    {
        $result = $this->get_category_suggestions_result($marketplace_id, $query);
        return is_array($result['suggestions'] ?? null) ? $result['suggestions'] : [];
    }

    public function get_category_suggestions_result(string $marketplace_id, string $query): array
    {
        $tree = $this->get_default_category_tree_id_result($marketplace_id);
        $treeId = (string) ($tree['category_tree_id'] ?? '');
        if (($tree['status'] ?? '') === 'taxonomy_api_forbidden') {
            return ['status' => 'taxonomy_api_forbidden', 'suggestions' => [], 'error' => (string) ($tree['error'] ?? 'eBay Taxonomy API forbidden'), 'tree' => $tree];
        }
        if ($treeId === '' || trim($query) === '') {
            return ['status' => 'suggestion_failed', 'suggestions' => [], 'error' => $treeId === '' ? 'Missing eBay category_tree_id' : 'Missing suggestion query', 'tree' => $tree];
        }

        $cacheKey = 'wei_tax_suggest_' . md5($marketplace_id . '|' . $query);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return ['status' => 'ok', 'suggestions' => $cached, 'source' => 'cache', 'tree' => $tree];
        }

        $res = $this->client->get_category_suggestions($treeId, $query);
        if (is_wp_error($res)) {
            $status = $this->is_forbidden_error($res) ? 'taxonomy_api_forbidden' : 'suggestion_failed';
            $this->logger->error('Unable to load eBay category suggestions', ['marketplace_id' => $marketplace_id, 'query' => $query, 'status' => $status, 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data()]);
            return ['status' => $status, 'suggestions' => [], 'error' => $res->get_error_message(), 'error_details' => $res->get_error_data(), 'tree' => $tree];
        }

        $suggestions = is_array($res['categorySuggestions'] ?? null) ? $res['categorySuggestions'] : [];
        set_transient($cacheKey, $suggestions, DAY_IN_SECONDS);
        return ['status' => 'ok', 'suggestions' => $suggestions, 'source' => 'api', 'tree' => $tree];
    }

    public function get_required_aspects(string $marketplace_id, string $category_id): array
    {
        $category_id = trim($category_id);
        if ($category_id === '') {
            return [];
        }

        $cacheKey = 'wei_req_aspects_' . sanitize_key($marketplace_id) . '_' . sanitize_key($category_id);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $required = $this->static_required_aspects($marketplace_id, $category_id);
        $treeId = $this->get_default_category_tree_id($marketplace_id);
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

        return [];
    }
}
