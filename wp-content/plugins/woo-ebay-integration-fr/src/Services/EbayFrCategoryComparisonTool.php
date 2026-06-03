<?php

namespace WEI_FR\Services;

class EbayFrCategoryComparisonTool
{
    public function __construct(private EbayClient $client, private Logger $logger)
    {
    }

    public function generate(string $deMappingCsv = '', bool $forceRefresh = false): array
    {
        $base = $this->report_dir();
        $reportsDir = $base['dir'] . '/reports';
        if (!is_dir($reportsDir)) {
            wp_mkdir_p($reportsDir);
        }

        $de = $this->load_marketplace_subtree('EBAY_DE', '131090', $reportsDir, $forceRefresh);
        $fr = $this->load_marketplace_subtree('EBAY_FR', '131090', $reportsDir, $forceRefresh);
        $de6030 = $this->load_marketplace_subtree('EBAY_DE', '6030', $reportsDir, $forceRefresh, true);
        $fr6030 = $this->load_marketplace_subtree('EBAY_FR', '6030', $reportsDir, $forceRefresh, true);

        $deRows = $this->flatten_subtree('EBAY_DE', (string) ($de['tree_id'] ?? ''), '131090', (array) ($de['response']['rootCategoryNode'] ?? []));
        $frRows = $this->flatten_subtree('EBAY_FR', (string) ($fr['tree_id'] ?? ''), '131090', (array) ($fr['response']['rootCategoryNode'] ?? []));

        $paths = [
            'ebay_de_auto_categories_csv' => $base['dir'] . '/ebay_de_auto_categories.csv',
            'ebay_fr_auto_categories_csv' => $base['dir'] . '/ebay_fr_auto_categories.csv',
            'comparison_csv' => $base['dir'] . '/ebay_de_fr_auto_category_comparison.csv',
            'mapping_candidates_csv' => $base['dir'] . '/ebay_de_to_fr_category_mapping_candidates.csv',
        ];

        $this->write_csv($paths['ebay_de_auto_categories_csv'], $this->category_headers(), $deRows);
        $this->write_csv($paths['ebay_fr_auto_categories_csv'], $this->category_headers(), $frRows);
        $comparisonRows = $this->comparison_rows($deRows, $frRows);
        $this->write_csv($paths['comparison_csv'], $this->comparison_headers(), $comparisonRows);
        $candidateRows = $this->mapping_candidate_rows($deMappingCsv, $frRows);
        $this->write_csv($paths['mapping_candidates_csv'], $this->candidate_headers(), $candidateRows);
        $categorySummary = $this->category_summary($deRows, $frRows);
        $manualMappingSummary = $this->manual_mapping_summary($candidateRows);
        $taxonomyApiErrors = $this->taxonomy_api_errors([$de, $fr, $de6030, $fr6030]);

        return [
            'result' => 'success',
            'output_dir' => $base['dir'],
            'output_url' => $base['url'],
            'de_count' => count($deRows),
            'fr_count' => count($frRows),
            'comparison_count' => count($comparisonRows),
            'mapping_candidate_count' => count($candidateRows),
            'summary_counts' => array_merge($categorySummary, $manualMappingSummary),
            'category_summary' => $categorySummary,
            'manual_mapping_summary' => $manualMappingSummary,
            'taxonomy_api_errors' => $taxonomyApiErrors,
            'reports' => array_map(fn(string $path): array => ['path' => $path, 'url' => $base['url'] . '/' . basename($path)], $paths),
            'raw_reports' => [
                'de_131090' => $reportsDir . '/ebay-de-category-subtree-131090.json',
                'fr_131090' => $reportsDir . '/ebay-fr-category-subtree-131090.json',
                'de_6030' => $reportsDir . '/ebay-de-category-subtree-6030.json',
                'fr_6030' => $reportsDir . '/ebay-fr-category-subtree-6030.json',
            ],
        ];
    }

    private function load_marketplace_subtree(string $marketplace, string $categoryId, string $reportsDir, bool $forceRefresh, bool $optional = false): array
    {
        $file = $reportsDir . '/ebay-' . strtolower(str_replace('EBAY_', '', $marketplace)) . '-category-subtree-' . $categoryId . '.json';
        if (!$forceRefresh && is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $tree = $this->client->get_default_category_tree_id($marketplace);
        if (is_wp_error($tree)) {
            if ($optional) {
                return ['marketplace' => $marketplace, 'category_id' => $categoryId, 'tree_id' => '', 'response' => [], 'error' => $tree->get_error_message()];
            }
            throw new \RuntimeException('Unable to load category tree for ' . $marketplace . ': ' . $tree->get_error_message());
        }
        $treeId = (string) ($tree['categoryTreeId'] ?? '');
        if ($treeId === '') {
            throw new \RuntimeException('Missing categoryTreeId for ' . $marketplace);
        }
        $subtree = $this->client->get_category_subtree($treeId, $categoryId);
        if (is_wp_error($subtree)) {
            if ($optional) {
                return ['marketplace' => $marketplace, 'category_id' => $categoryId, 'tree_id' => $treeId, 'response' => [], 'error' => $subtree->get_error_message()];
            }
            throw new \RuntimeException('Unable to load category subtree ' . $categoryId . ' for ' . $marketplace . ': ' . $subtree->get_error_message());
        }

        $payload = ['marketplace' => $marketplace, 'category_id' => $categoryId, 'tree_id' => $treeId, 'response' => $subtree, 'generated_at' => gmdate('c')];
        file_put_contents($file, wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $payload;
    }

    private function flatten_subtree(string $marketplace, string $treeId, string $rootId, array $node, array $path = [], string $parentId = ''): array
    {
        $category = (array) ($node['category'] ?? []);
        $id = (string) ($category['categoryId'] ?? '');
        $name = (string) ($category['categoryName'] ?? '');
        if ($id === '') {
            return [];
        }
        $currentPath = array_merge($path, [$name]);
        $children = (array) ($node['childCategoryTreeNodes'] ?? []);
        $leaf = (bool) ($node['leafCategoryTreeNode'] ?? ($children === []));
        $row = [
            'marketplace' => $marketplace,
            'category_id' => $id,
            'parent_category_id' => $parentId,
            'category_name' => $name,
            'category_level' => (string) count($currentPath),
            'category_tree_id' => $treeId,
            'category_subtree_root_id' => $rootId,
            'category_path' => implode(' > ', array_filter($currentPath)),
            'is_leaf' => $leaf ? '1' : '0',
            'leaf_category_tree_node' => $leaf ? '1' : '0',
            'source' => 'taxonomy_api',
        ];
        $rows = [$row];
        foreach ($children as $child) {
            if (is_array($child)) {
                array_push($rows, ...$this->flatten_subtree($marketplace, $treeId, $rootId, $child, $currentPath, $id));
            }
        }
        return $rows;
    }

    private function comparison_rows(array $deRows, array $frRows): array
    {
        $de = $this->index_by_id($deRows);
        $fr = $this->index_by_id($frRows);
        $ids = array_values(array_unique(array_merge(array_keys($de), array_keys($fr))));
        sort($ids, SORT_NATURAL);
        $rows = [];
        foreach ($ids as $id) {
            $d = $de[$id] ?? [];
            $f = $fr[$id] ?? [];
            $existsDe = $d !== [];
            $existsFr = $f !== [];
            $sameParent = $existsDe && $existsFr && (string) $d['parent_category_id'] === (string) $f['parent_category_id'];
            $sameLevel = $existsDe && $existsFr && (string) $d['category_level'] === (string) $f['category_level'];
            $leafDe = (string) ($d['is_leaf'] ?? '0') === '1';
            $leafFr = (string) ($f['is_leaf'] ?? '0') === '1';
            $status = 'NEEDS_REVIEW';
            if (!$existsFr) $status = 'EXISTS_ONLY_DE';
            elseif (!$existsDe) $status = 'EXISTS_ONLY_FR';
            elseif (!$sameParent) $status = 'SAME_ID_DIFFERENT_PARENT';
            elseif (!$sameLevel) $status = 'SAME_ID_DIFFERENT_LEVEL';
            elseif ($leafDe && $leafFr) $status = 'OK_SAME_ID_AND_PARENT_LEAF';
            elseif (!$leafDe || !$leafFr) $status = 'OK_SAME_ID_NON_LEAF';
            $rows[] = [
                'category_id' => $id,
                'exists_in_de' => $existsDe ? '1' : '0',
                'exists_in_fr' => $existsFr ? '1' : '0',
                'de_name' => (string) ($d['category_name'] ?? ''),
                'fr_name' => (string) ($f['category_name'] ?? ''),
                'de_parent_category_id' => (string) ($d['parent_category_id'] ?? ''),
                'fr_parent_category_id' => (string) ($f['parent_category_id'] ?? ''),
                'de_category_level' => (string) ($d['category_level'] ?? ''),
                'fr_category_level' => (string) ($f['category_level'] ?? ''),
                'de_category_path' => (string) ($d['category_path'] ?? ''),
                'fr_category_path' => (string) ($f['category_path'] ?? ''),
                'is_leaf_de' => $leafDe ? '1' : '0',
                'is_leaf_fr' => $leafFr ? '1' : '0',
                'same_parent' => $sameParent ? '1' : '0',
                'same_level' => $sameLevel ? '1' : '0',
                'status' => $status,
                'notes' => $status === 'OK_SAME_ID_AND_PARENT_LEAF' ? 'Same category id is a FR leaf candidate.' : 'Review before importing as final FR mapping.',
            ];
        }
        return $rows;
    }

    private function mapping_candidate_rows(string $deMappingCsv, array $frRows): array
    {
        if ($deMappingCsv === '' || !is_readable($deMappingCsv)) {
            return [];
        }
        $fr = $this->index_by_id($frRows);
        $frByName = $this->index_leaf_rows_by_normalized_name($frRows);
        $rows = [];
        $fh = fopen($deMappingCsv, 'rb');
        if (!$fh) return [];
        $headers = fgetcsv($fh) ?: [];
        while (($values = fgetcsv($fh)) !== false) {
            $row = [];
            foreach ($headers as $i => $header) $row[(string) $header] = (string) ($values[$i] ?? '');
            $deId = trim((string) ($row['final_ebay_category_id'] ?? ''));
            $candidate = $deId !== '' ? ($fr[$deId] ?? []) : [];
            $possibleEquivalent = [];
            if ($candidate === [] && $deId !== '') {
                $possibleEquivalent = $this->find_possible_fr_equivalent($row, $frByName);
            }
            $activeCandidate = $candidate !== [] ? $candidate : $possibleEquivalent;
            $isLeaf = (string) ($activeCandidate['is_leaf'] ?? '0') === '1';
            $status = 'NEEDS_REVIEW';
            if ($deId !== '') {
                if ($candidate !== []) {
                    $status = $isLeaf ? 'OK_SAME_ID_ON_FR' : 'SAME_ID_ON_FR_BUT_NON_LEAF';
                } elseif ($possibleEquivalent !== []) {
                    $status = 'POSSIBLE_FR_EQUIVALENT';
                } else {
                    $status = 'DE_ID_NOT_FOUND_ON_FR';
                }
            }
            $rows[] = [
                'woo_category_id' => (string) ($row['woo_category_id'] ?? ''),
                'woo_category_name' => (string) ($row['woo_category_name'] ?? ''),
                'product_count' => (string) ($row['product_count'] ?? ''),
                'de_final_ebay_category_id' => $deId,
                'de_category_name' => (string) ($row['current_ebay_category_name'] ?? ''),
                'de_category_path' => (string) ($row['current_ebay_category_path'] ?? ''),
                'fr_candidate_category_id' => $candidate !== [] ? $deId : (string) ($possibleEquivalent['category_id'] ?? ''),
                'fr_candidate_category_name' => (string) ($activeCandidate['category_name'] ?? ''),
                'fr_candidate_category_path' => (string) ($activeCandidate['category_path'] ?? ''),
                'fr_candidate_is_leaf' => $isLeaf ? '1' : '0',
                'mapping_status' => $status,
                'review_note' => $this->candidate_review_note($status),
                'sample_product_id' => (string) ($row['sample_product_id'] ?? ''),
                'sample_product_title' => (string) ($row['sample_product_title'] ?? ''),
            ];
        }
        fclose($fh);
        return $rows;
    }


    private function category_summary(array $deRows, array $frRows): array
    {
        $de = $this->index_by_id($deRows);
        $fr = $this->index_by_id($frRows);
        $same = array_intersect(array_keys($de), array_keys($fr));
        $deOnly = array_diff(array_keys($de), array_keys($fr));
        $frOnly = array_diff(array_keys($fr), array_keys($de));

        return [
            'de_categories_found' => count($deRows),
            'fr_categories_found' => count($frRows),
            'same_category_ids' => count($same),
            'de_only_category_ids' => count($deOnly),
            'fr_only_category_ids' => count($frOnly),
        ];
    }

    private function manual_mapping_summary(array $candidateRows): array
    {
        $statuses = [
            'OK_SAME_ID_ON_FR' => 0,
            'SAME_ID_ON_FR_BUT_NON_LEAF' => 0,
            'DE_ID_NOT_FOUND_ON_FR' => 0,
            'NEEDS_REVIEW' => 0,
            'POSSIBLE_FR_EQUIVALENT' => 0,
        ];
        foreach ($candidateRows as $row) {
            $status = (string) ($row['mapping_status'] ?? 'NEEDS_REVIEW');
            if (!array_key_exists($status, $statuses)) {
                $status = 'NEEDS_REVIEW';
            }
            $statuses[$status]++;
        }

        return [
            'finalny_rows_processed' => count($candidateRows),
            'ok_same_id_on_fr_count' => $statuses['OK_SAME_ID_ON_FR'],
            'same_id_on_fr_but_non_leaf_count' => $statuses['SAME_ID_ON_FR_BUT_NON_LEAF'],
            'de_id_not_found_on_fr_count' => $statuses['DE_ID_NOT_FOUND_ON_FR'],
            'needs_review_count' => $statuses['NEEDS_REVIEW'],
            'possible_fr_equivalent_count' => $statuses['POSSIBLE_FR_EQUIVALENT'],
            'mapping_status_counts' => $statuses,
        ];
    }

    private function taxonomy_api_errors(array $payloads): array
    {
        $errors = [];
        foreach ($payloads as $payload) {
            if (!is_array($payload) || empty($payload['error'])) {
                continue;
            }
            $errors[] = [
                'marketplace' => (string) ($payload['marketplace'] ?? ''),
                'category_id' => (string) ($payload['category_id'] ?? ''),
                'message' => (string) $payload['error'],
            ];
        }
        return $errors;
    }

    private function index_leaf_rows_by_normalized_name(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ((string) ($row['is_leaf'] ?? '0') !== '1') {
                continue;
            }
            foreach ([$row['category_name'] ?? '', basename(str_replace(' > ', '/', (string) ($row['category_path'] ?? '')))] as $name) {
                $normalized = $this->normalize_category_text((string) $name);
                if ($normalized !== '' && empty($out[$normalized])) {
                    $out[$normalized] = $row;
                }
            }
        }
        return $out;
    }

    private function find_possible_fr_equivalent(array $row, array $frByName): array
    {
        foreach (['current_ebay_category_name', 'woo_category_name'] as $field) {
            $normalized = $this->normalize_category_text((string) ($row[$field] ?? ''));
            if ($normalized !== '' && !empty($frByName[$normalized])) {
                return $frByName[$normalized];
            }
        }
        return [];
    }

    private function normalize_category_text(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = preg_replace('/\s+/', ' ', $value) ?: '';
        return trim($value);
    }

    private function candidate_review_note(string $status): string
    {
        return match ($status) {
            'OK_SAME_ID_ON_FR' => 'Same id exists as EBAY_FR leaf; review before final import.',
            'SAME_ID_ON_FR_BUT_NON_LEAF' => 'Same id exists on EBAY_FR but is not a leaf category; choose a leaf before import.',
            'POSSIBLE_FR_EQUIVALENT' => 'DE id was not found on EBAY_FR, but a same-name FR leaf was found; verify manually.',
            'DE_ID_NOT_FOUND_ON_FR' => 'DE id was not found on EBAY_FR; no safe automatic final mapping.',
            default => 'No safe automatic final mapping; needs human review.',
        };
    }

    private function index_by_id(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (string) ($row['category_id'] ?? '');
            if ($id !== '') $out[$id] = $row;
        }
        return $out;
    }

    private function report_dir(): array
    {
        $upload = wp_upload_dir();
        $dir = trailingslashit((string) ($upload['basedir'] ?? WP_CONTENT_DIR . '/uploads')) . 'wei-ebay-integration-fr';
        $url = trailingslashit((string) ($upload['baseurl'] ?? content_url('uploads'))) . 'wei-ebay-integration-fr';
        if (!is_dir($dir)) wp_mkdir_p($dir);
        return ['dir' => $dir, 'url' => $url];
    }

    private function write_csv(string $path, array $headers, array $rows): void
    {
        $fh = fopen($path, 'wb');
        if (!$fh) throw new \RuntimeException('Unable to write ' . $path);
        fputcsv($fh, $headers);
        foreach ($rows as $row) fputcsv($fh, array_map(static fn($header): string => (string) ($row[$header] ?? ''), $headers));
        fclose($fh);
    }

    private function category_headers(): array { return ['marketplace','category_id','parent_category_id','category_name','category_level','category_tree_id','category_subtree_root_id','category_path','is_leaf','leaf_category_tree_node','source']; }
    private function comparison_headers(): array { return ['category_id','exists_in_de','exists_in_fr','de_name','fr_name','de_parent_category_id','fr_parent_category_id','de_category_level','fr_category_level','de_category_path','fr_category_path','is_leaf_de','is_leaf_fr','same_parent','same_level','status','notes']; }
    private function candidate_headers(): array { return ['woo_category_id','woo_category_name','product_count','de_final_ebay_category_id','de_category_name','de_category_path','fr_candidate_category_id','fr_candidate_category_name','fr_candidate_category_path','fr_candidate_is_leaf','mapping_status','review_note','sample_product_id','sample_product_title']; }
}
