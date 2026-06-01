<?php

namespace WEI\Services;

use WEI\Repositories\CategoryMappingRepository;

class VehicleCompatibilityAuditService
{
    public const CSV_FILENAME = 'vehicle-compatibility-readiness-audit.csv';

    /** @var array<string,array<int,string>> */
    private array $fieldAliases = [
        'ktype_values' => ['KType', 'k_type', 'ktype', 'ktype_id', '_ktype', '_ebay_ktype'],
        'epid_values' => ['ePID', 'epid', '_epid'],
        'make' => ['make', 'manufacturer', 'brand', 'Producent'],
        'model' => ['model', 'Model'],
        'year' => ['year', 'Rok produkcji samochodu'],
        'trim' => ['modification', 'trim', 'version', 'Modyfikacja'],
        'period' => ['period', 'Okres'],
        'engine_code' => ['engine code', 'engine_code', 'Kod silnika'],
        'engine_capacity' => ['engine capacity', 'engine_capacity', 'Pojemność silnika', 'Pojemnosc silnika'],
        'fuel' => ['fuel type', 'fuel_type', 'Rodzaj paliwa'],
        'power' => ['power', 'Moc silnika'],
        'ovoko_car_id' => ['ovoko_car_id', '_ovoko_car_id', 'car_id', '_car_id', 'donor_vehicle_id'],
    ];

    public function __construct(private ?CategoryMappingRepository $categoryRepo = null, private ?Logger $logger = null)
    {
    }

    public function auditProduct(int $productId, string $marketplaceId = 'EBAY_DE'): array
    {
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $sku = is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : $this->firstMetaValue($productId, ['_sku', 'sku']);
        $title = is_object($product) && method_exists($product, 'get_name') ? (string) $product->get_name() : (function_exists('get_the_title') ? (string) get_the_title($productId) : '');
        $category = $this->detectWooCategory($productId, $marketplaceId);
        $detected = $this->detectVehicleData($productId, $product);
        $statusInfo = $this->determineStatus($detected, $category);

        return [
            'product_id' => $productId,
            'sku' => $sku,
            'product_title' => $title,
            'woo_category_id' => (string) ($category['woo_category_id'] ?? ''),
            'woo_category_name' => (string) ($category['woo_category_name'] ?? ''),
            'woo_category_path' => (string) ($category['woo_category_path'] ?? ''),
            'ebay_category_id' => (string) ($category['ebay_category_id'] ?? ''),
            'category_status' => (string) ($category['category_status'] ?? ''),
            'marketplace_id' => $marketplaceId,
            'detected' => $detected,
            'detected_ktype_values' => $detected['ktype_values']['values'],
            'detected_epid_values' => $detected['epid_values']['values'],
            'detected_vehicle' => [
                'make' => $detected['make']['value'],
                'model' => $detected['model']['value'],
                'year' => $detected['year']['value'],
                'trim' => $detected['trim']['value'],
                'period' => $detected['period']['value'],
                'engine_code' => $detected['engine_code']['value'],
                'engine_capacity' => $detected['engine_capacity']['value'],
                'fuel' => $detected['fuel']['value'],
                'power' => $detected['power']['value'],
            ],
            'detected_ovoko_car_id' => $detected['ovoko_car_id']['value'],
            'sources' => $this->compactSources($detected),
            'compatibility_status' => $statusInfo['status'],
            'missing_fields' => $statusInfo['missing_fields'],
            'notes' => $statusInfo['notes'],
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'wrote_product_meta' => false,
        ];
    }

    public function detectVehicleData(int $productId, $product = null): array
    {
        $allMeta = $this->allMeta($productId);
        $attributes = $this->allAttributes($productId, $product);
        $detailRows = $this->productDetailsRows($productId);
        $result = [];
        foreach ($this->fieldAliases as $field => $aliases) {
            $values = [];
            $sources = [];
            foreach ($aliases as $alias) {
                foreach ($this->matchingMapValues($allMeta, $alias) as $sourceKey => $value) {
                    $this->appendValue($values, $value, $field);
                    $sources[] = ['source' => 'meta', 'key' => $sourceKey, 'value' => $value];
                }
                foreach ($this->matchingMapValues($attributes, $alias) as $sourceKey => $value) {
                    $this->appendValue($values, $value, $field);
                    $sources[] = ['source' => 'attribute', 'key' => $sourceKey, 'value' => $value];
                }
                foreach ($this->matchingMapValues($detailRows, $alias) as $sourceKey => $value) {
                    $this->appendValue($values, $value, $field);
                    $sources[] = ['source' => 'function', 'key' => 'gp_get_product_details_rows:' . $sourceKey, 'value' => $value];
                }
            }
            $values = array_values(array_unique(array_filter(array_map('strval', $values), static fn(string $v): bool => trim($v) !== '')));
            $result[$field] = [
                'value' => implode('; ', $values),
                'values' => $values,
                'sources' => $sources !== [] ? $sources : [['source' => 'fallback', 'key' => '', 'value' => '']],
            ];
        }
        return $result;
    }

    public function buildProductCompatibilityPayload(int $productId, string $marketplaceId = 'EBAY_DE'): array
    {
        $audit = $this->auditProduct($productId, $marketplaceId);
        $ktypes = (array) ($audit['detected_ktype_values'] ?? []);
        if ($ktypes === []) {
            return [
                'marketplaceId' => $marketplaceId,
                'compatibleProducts' => [],
                'compatibility_status' => (string) ($audit['compatibility_status'] ?? ''),
                'ready' => false,
                'note' => 'No KType values detected; audit is read-only and does not guess compatibility.',
            ];
        }

        return [
            'marketplaceId' => $marketplaceId,
            'compatibleProducts' => array_map(static fn(string $ktype): array => ['kTypeValue' => $ktype], $ktypes),
            'compatibility_status' => 'ready_ktype',
            'ready' => true,
            'note' => 'Future Inventory API payload preview only; not sent by audit.',
        ];
    }

    public function generateAuditCsv(string $marketplaceId = 'EBAY_DE', int $limit = 500): array
    {
        $upload = function_exists('wp_upload_dir') ? (array) wp_upload_dir() : ['basedir' => sys_get_temp_dir(), 'baseurl' => ''];
        $dir = rtrim((string) ($upload['basedir'] ?? sys_get_temp_dir()), '/\\') . '/wei-ebay-reports';
        if (function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        } elseif (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/' . self::CSV_FILENAME;
        $fh = fopen($path, 'wb');
        if (!$fh) {
            return ['result' => 'error', 'error' => 'csv_open_failed', 'path' => $path, 'called_ebay_api' => false, 'updated_ebay_listing' => false];
        }
        $headers = $this->csvHeaders();
        fputcsv($fh, $headers);
        $count = 0;
        foreach ($this->productIds($limit) as $productId) {
            $audit = $this->auditProduct((int) $productId, $marketplaceId);
            fputcsv($fh, $this->csvRow($audit));
            $count++;
        }
        fclose($fh);
        $baseurl = rtrim((string) ($upload['baseurl'] ?? ''), '/');
        return [
            'result' => 'success',
            'marketplace_id' => $marketplaceId,
            'processed' => $count,
            'csv_path' => $path,
            'csv_url' => $baseurl !== '' ? $baseurl . '/wei-ebay-reports/' . self::CSV_FILENAME : '',
            'csv_exists' => is_file($path),
            'csv_size' => is_file($path) ? (int) filesize($path) : 0,
            'headers' => $headers,
            'called_ebay_api' => false,
            'updated_ebay_listing' => false,
            'wrote_product_meta' => false,
        ];
    }

    public function csvHeaders(): array
    {
        return ['product_id', 'sku', 'product_title', 'woo_category_id', 'woo_category_name', 'ebay_category_id', 'category_status', 'ovoko_car_id', 'ktype_values', 'epid_values', 'make', 'model', 'year', 'trim', 'engine_code', 'engine_capacity', 'fuel', 'power', 'compatibility_status', 'missing_fields', 'notes'];
    }

    public function csvRow(array $audit): array
    {
        $detected = (array) ($audit['detected'] ?? []);
        $value = static fn(string $field): string => (string) ($detected[$field]['value'] ?? '');
        return [
            (string) ($audit['product_id'] ?? ''),
            (string) ($audit['sku'] ?? ''),
            (string) ($audit['product_title'] ?? ''),
            (string) ($audit['woo_category_id'] ?? ''),
            (string) ($audit['woo_category_name'] ?? ''),
            (string) ($audit['ebay_category_id'] ?? ''),
            (string) ($audit['category_status'] ?? ''),
            $value('ovoko_car_id'),
            implode('|', (array) ($detected['ktype_values']['values'] ?? [])),
            implode('|', (array) ($detected['epid_values']['values'] ?? [])),
            $value('make'),
            $value('model'),
            $value('year'),
            $value('trim'),
            $value('engine_code'),
            $value('engine_capacity'),
            $value('fuel'),
            $value('power'),
            (string) ($audit['compatibility_status'] ?? ''),
            implode('|', (array) ($audit['missing_fields'] ?? [])),
            implode('|', (array) ($audit['notes'] ?? [])),
        ];
    }

    private function determineStatus(array $detected, array $category): array
    {
        if (($detected['ktype_values']['values'] ?? []) !== []) {
            return ['status' => 'ready_ktype', 'missing_fields' => [], 'notes' => ['KType detected; future payload can use compatibleProducts.kTypeValue.']];
        }
        if (($detected['epid_values']['values'] ?? []) !== []) {
            return ['status' => 'ready_epid', 'missing_fields' => [], 'notes' => ['ePID detected; no eBay API call was made.']];
        }
        $categoryStatus = (string) ($category['category_status'] ?? '');
        if (in_array($categoryStatus, ['unsupported_category', 'non_automotive', 'not_automotive'], true)) {
            return ['status' => 'unsupported_category', 'missing_fields' => ['supported eBay.de automotive category'], 'notes' => ['Current category is not marked as supported for eBay.de vehicle compatibility audit.']];
        }
        if (in_array($categoryStatus, ['blocked_by_category', 'needs_category_review', 'category_sanity_failed', 'low_confidence_auto', 'unmapped'], true)) {
            return ['status' => 'needs_manual_review', 'missing_fields' => ['reviewed eBay.de category', 'KType'], 'notes' => ['Category mapping needs review before vehicle compatibility can be assessed safely.']];
        }
        $hasVehicleBasics = trim((string) ($detected['make']['value'] ?? '')) !== '' && trim((string) ($detected['model']['value'] ?? '')) !== '';
        $missing = [];
        foreach (['ktype_values', 'make', 'model', 'year'] as $field) {
            if (trim((string) ($detected[$field]['value'] ?? '')) === '' && ($detected[$field]['values'] ?? []) === []) {
                $missing[] = $field === 'ktype_values' ? 'KType' : $field;
            }
        }
        if ($hasVehicleBasics) {
            return ['status' => 'missing_ktype', 'missing_fields' => array_values(array_unique(array_merge(['KType'], $missing))), 'notes' => ['Vehicle make/model data exists, but audit does not guess eBay.de compatibility without KType/ePID.']];
        }
        return ['status' => 'insufficient_vehicle_data', 'missing_fields' => array_values(array_unique($missing)), 'notes' => ['No KType/ePID detected; available vehicle data is insufficient for publish-ready compatibility.', trim((string) ($detected['ovoko_car_id']['value'] ?? '')) !== '' ? 'Ovoko car ID alone is not treated as eBay.de compatibility-ready.' : '']];
    }

    private function compactSources(array $detected): array
    {
        $sources = [];
        foreach ($detected as $field => $info) {
            $sources[$field] = array_map(static fn(array $source): string => trim((string) ($source['source'] ?? '') . ':' . (string) ($source['key'] ?? ''), ':'), (array) ($info['sources'] ?? []));
        }
        return $sources;
    }

    private function detectWooCategory(int $productId, string $marketplaceId): array
    {
        $category = ['woo_category_id' => '', 'woo_category_name' => '', 'woo_category_path' => '', 'ebay_category_id' => $this->firstMetaValue($productId, ['_wei_ebay_category_id', 'wei_ebay_category_id', '_ebay_category_id']), 'category_status' => 'unknown'];
        $terms = function_exists('get_the_terms') ? get_the_terms($productId, 'product_cat') : [];
        if (is_array($terms) && $terms !== []) {
            $term = reset($terms);
            $termId = is_object($term) ? (int) ($term->term_id ?? 0) : 0;
            $category['woo_category_id'] = (string) $termId;
            $category['woo_category_name'] = is_object($term) ? (string) ($term->name ?? '') : '';
            $category['woo_category_path'] = $this->categoryRepo ? $this->categoryRepo->woo_category_path($termId) : $category['woo_category_name'];
            if ($this->categoryRepo) {
                $mapping = $this->categoryRepo->resolveProductionCategoryMapping($termId, $marketplaceId);
                if (is_array($mapping)) {
                    $category['ebay_category_id'] = (string) ($mapping['ebay_category_id'] ?? $category['ebay_category_id']);
                    $category['category_status'] = (string) ($mapping['status'] ?? $mapping['source'] ?? 'mapped');
                }
            }
        }
        if ($category['ebay_category_id'] !== '' && $category['category_status'] === 'unknown') {
            $category['category_status'] = 'product_meta';
        }
        return $category;
    }

    private function allMeta(int $productId): array
    {
        $out = [];
        if (!function_exists('get_post_meta')) {
            return $out;
        }
        $meta = get_post_meta($productId);
        if (is_array($meta)) {
            foreach ($meta as $key => $values) {
                $out[(string) $key] = $this->stringify($values);
            }
        }
        return $out;
    }

    private function firstMetaValue(int $productId, array $keys): string
    {
        if (!function_exists('get_post_meta')) {
            return '';
        }
        foreach ($keys as $key) {
            $value = $this->stringify(get_post_meta($productId, $key, true));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function allAttributes(int $productId, $product): array
    {
        $out = [];
        if (is_object($product) && method_exists($product, 'get_attributes')) {
            foreach ((array) $product->get_attributes() as $key => $attribute) {
                $name = is_object($attribute) && method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : (string) $key;
                $value = '';
                if (is_object($attribute) && method_exists($attribute, 'get_options')) {
                    $value = implode('; ', array_map('strval', (array) $attribute->get_options()));
                } elseif (is_array($attribute)) {
                    $name = (string) ($attribute['name'] ?? $name);
                    $value = $this->stringify($attribute['value'] ?? '');
                }
                if ($name !== '' && $value !== '') {
                    $out[$name] = $value;
                }
            }
        }
        $raw = function_exists('get_post_meta') ? get_post_meta($productId, '_product_attributes', true) : [];
        if (is_array($raw)) {
            foreach ($raw as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }
                $name = (string) ($attribute['name'] ?? '');
                $value = $this->stringify($attribute['value'] ?? '');
                if ($name !== '' && $value !== '') {
                    $out[$name] = $value;
                }
            }
        }
        return $out;
    }

    private function productDetailsRows(int $productId): array
    {
        if (function_exists('gp_get_product_details_rows')) {
            $rows = gp_get_product_details_rows($productId);
            return is_array($rows) ? array_map(fn($v): string => $this->stringify($v), $rows) : [];
        }
        return [];
    }

    private function matchingMapValues(array $map, string $alias): array
    {
        $matches = [];
        $target = $this->normalizeKey($alias);
        foreach ($map as $key => $value) {
            if ($this->normalizeKey((string) $key) === $target) {
                $matches[(string) $key] = $this->stringify($value);
            }
        }
        return $matches;
    }

    private function normalizeKey(string $key): string
    {
        $key = function_exists('remove_accents') ? remove_accents($key) : iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9]+/', '', $key) ?: '';
    }

    private function appendValue(array &$values, string $value, string $field): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }
        if (in_array($field, ['ktype_values', 'epid_values'], true)) {
            foreach (preg_split('/[;,|\s]+/', $value) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $values[] = $part;
                }
            }
            return;
        }
        $values[] = $value;
    }

    private function stringify($value): string
    {
        if (is_array($value)) {
            if (count($value) === 1 && array_key_exists(0, $value)) {
                return $this->stringify($value[0]);
            }
            return trim(implode('; ', array_map(fn($item): string => $this->stringify($item), $value)));
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }
        return trim((string) $value);
    }

    private function productIds(int $limit): array
    {
        if (function_exists('wc_get_products')) {
            $ids = wc_get_products(['status' => ['publish', 'private'], 'limit' => $limit, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'DESC']);
            return array_map('intval', is_array($ids) ? $ids : []);
        }
        if (function_exists('get_posts')) {
            $ids = get_posts(['post_type' => 'product', 'post_status' => ['publish', 'private'], 'fields' => 'ids', 'numberposts' => $limit, 'orderby' => 'ID', 'order' => 'DESC']);
            return array_map('intval', is_array($ids) ? $ids : []);
        }
        return [];
    }
}
