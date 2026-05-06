<?php

namespace WEI\Services;

class EbayTaxonomyService
{
    public function __construct(private EbayClient $client, private Logger $logger)
    {
    }

    public function get_default_category_tree_id(string $marketplace_id = 'EBAY_DE'): string
    {
        $cacheKey = 'wei_taxonomy_tree_' . sanitize_key($marketplace_id);
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $res = $this->client->get_default_category_tree_id($marketplace_id);
        if (is_wp_error($res)) {
            $this->logger->error('Unable to load eBay taxonomy tree id', ['marketplace_id' => $marketplace_id, 'error' => $res->get_error_message()]);
            return '';
        }

        $treeId = (string) ($res['categoryTreeId'] ?? '');
        if ($treeId !== '') {
            set_transient($cacheKey, $treeId, DAY_IN_SECONDS * 7);
        }

        return $treeId;
    }

    public function get_category_suggestions(string $marketplace_id, string $query): array
    {
        $treeId = $this->get_default_category_tree_id($marketplace_id);
        if ($treeId === '' || trim($query) === '') {
            return [];
        }

        $cacheKey = 'wei_tax_suggest_' . md5($marketplace_id . '|' . $query);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $res = $this->client->get_category_suggestions($treeId, $query);
        if (is_wp_error($res)) {
            $this->logger->error('Unable to load eBay category suggestions', ['marketplace_id' => $marketplace_id, 'query' => $query, 'error' => $res->get_error_message()]);
            return [];
        }

        $suggestions = is_array($res['categorySuggestions'] ?? null) ? $res['categorySuggestions'] : [];
        set_transient($cacheKey, $suggestions, DAY_IN_SECONDS);
        return $suggestions;
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

    private function static_required_aspects(string $marketplace_id, string $category_id): array
    {
        if ($marketplace_id === 'EBAY_DE' && $category_id === '179847') {
            return ['Hersteller'];
        }

        return [];
    }
}
