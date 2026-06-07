<?php

namespace GPSwiss\Ovoko\Services;

class WooToOvokoCreatePartPreviewService
{
    private const ACTION_NAME = 'Preview Woo product → Ovoko create-part payload';
    private const MODE = 'dry_run_no_ovoko_write_no_woo_write';
    private const PROPOSED_ENDPOINT = 'UNCONFIRMED_CREATE_PART_ENDPOINT';
    private const ALLOWED_STATUSES = ['draft'];
    private const PART_IDENTIFIER_META_KEYS = [
        '_part_number',
        '_mpn',
        'mpn',
        '_manufacturer_code',
        '_gpswiss_part_number',
        '_gps_detected_part_code',
        '_gps_detected_oem_part_number',
    ];
    private const DUPLICATE_META_KEYS = [
        '_ovoko_part_id',
        'ovoko_part_id',
        'part_id',
        'source_part_id',
        'external_part_id',
    ];

    public function preview(int $productId): array
    {
        $result = $this->base_result($productId);

        if ($productId <= 0) {
            $result['ok'] = false;
            $this->add_validation($result, 'error', 'invalid_product_id', 'A positive product_id is required.');
            $this->finalize($result);
            return $result;
        }

        $post = get_post($productId);
        if (!$post) {
            $result['ok'] = false;
            $this->add_validation($result, 'error', 'invalid_product_id', 'Product does not exist.');
            $this->finalize($result);
            return $result;
        }

        $postType = get_post_type($productId);
        if ($postType === 'product_variation') {
            $result['ok'] = false;
            $result['post_type'] = $postType;
            $this->add_validation($result, 'error', 'variation_not_supported', 'Product variations are not supported by this dry-run preview.');
            $this->finalize($result);
            return $result;
        }

        if ($postType !== 'product') {
            $result['ok'] = false;
            $result['post_type'] = (string) $postType;
            $this->add_validation($result, 'error', 'non_product_post_type', 'The supplied ID is not a WooCommerce product.');
            $this->finalize($result);
            return $result;
        }

        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
        $status = (string) get_post_status($productId);
        $title = trim((string) get_the_title($productId));
        $sku = $this->product_value($product, $productId, 'sku', '_sku');
        $price = $this->product_value($product, $productId, 'price', '_price');
        $regularPrice = $this->product_value($product, $productId, 'regular_price', '_regular_price');
        $salePrice = $this->product_value($product, $productId, 'sale_price', '_sale_price');
        $stockStatus = $this->product_value($product, $productId, 'stock_status', '_stock_status');
        $stockQuantity = $this->product_stock_quantity($product, $productId);
        $partIdentifier = $this->first_meta_value($productId, self::PART_IDENTIFIER_META_KEYS);
        $manufacturerCodeData = $this->first_meta_value($productId, ['_manufacturer_code', '_mpn', 'mpn', '_gpswiss_part_number']);
        $manufacturerCode = (string) $manufacturerCodeData['value'];
        $categoryReadiness = $this->category_mapping_readiness($productId);
        $images = $this->image_preview($product, $productId);
        $duplicates = $this->duplicate_checks($productId, $sku, $manufacturerCode, $partIdentifier['value']);

        $result['product_status'] = $status;
        $result['post_type'] = $postType;
        $result['duplicate_checks'] = $duplicates;
        $result['images'] = $images;
        $result['source_woo_fields_meta_used'] = [
            'post' => ['ID' => $productId, 'post_type' => $postType, 'post_status' => $status, 'post_title' => $title],
            'product_methods' => ['get_sku', 'get_price', 'get_regular_price', 'get_sale_price', 'get_stock_status', 'get_stock_quantity', 'get_image_id', 'get_gallery_image_ids'],
            'meta_keys' => array_values(array_unique(array_merge(['_sku', '_price', '_regular_price', '_sale_price', '_stock_status', '_stock', '_thumbnail_id', '_product_image_gallery'], self::PART_IDENTIFIER_META_KEYS, self::DUPLICATE_META_KEYS, ['_ovoko_manufacturer_code', 'ovoko_id', 'source']))),
            'taxonomy' => ['product_cat'],
        ];

        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            $this->add_validation($result, 'error', 'product_status_not_allowed', 'Only explicitly allowed statuses may be previewed for create-part payloads.', ['allowed_statuses' => self::ALLOWED_STATUSES, 'actual_status' => $status]);
        }
        if ($duplicates['has_existing_ovoko_part_id']) {
            $this->add_validation($result, 'error', 'existing_ovoko_part_id', 'Product already has _ovoko_part_id and must not be treated as a new Ovoko part.');
        }
        if ($title === '') {
            $this->add_validation($result, 'error', 'missing_title', 'Product title is required.');
        }
        if ($sku === '') {
            $this->add_validation($result, 'error', 'missing_sku', 'SKU is required.');
        }
        if ($price === '' || !is_numeric($price)) {
            $this->add_validation($result, 'error', 'missing_or_non_numeric_price', 'Price is required and must be numeric.');
        }
        if ($stockStatus === '' && $stockQuantity === null) {
            $this->add_validation($result, 'error', 'unknown_stock_status_quantity', 'Stock status or stock quantity must be known.');
        }
        if ($partIdentifier['value'] === '') {
            $this->add_validation($result, 'error', 'missing_part_identifier', 'Part identifier is required from an approved Woo meta key.', ['accepted_meta_keys' => self::PART_IDENTIFIER_META_KEYS]);
        }
        if (!$categoryReadiness['known']) {
            $this->add_validation($result, 'warning', 'category_mapping_readiness_unknown', 'Category mapping readiness could not be determined.');
        }
        if (empty($images['image_urls'])) {
            $this->add_validation($result, 'error', 'missing_images', 'At least one accessible product image URL is required for create-part readiness.');
        }
        foreach ((array) ($images['image_details'] ?? []) as $imageDetail) {
            if (empty($imageDetail['accessible'])) {
                $this->add_validation($result, 'error', 'inaccessible_image_url', 'Product image URL is missing or not accessible for preview.', ['attachment_id' => (int) ($imageDetail['attachment_id'] ?? 0), 'url' => (string) ($imageDetail['url'] ?? '')]);
            }
        }
        foreach ($duplicates['warnings'] as $warning) {
            $this->add_validation($result, 'warning', (string) $warning['code'], (string) $warning['message'], (array) ($warning['context'] ?? []));
        }
        foreach ($duplicates['blocking_warnings'] as $warning) {
            $this->add_validation($result, 'error', (string) $warning['code'], (string) $warning['message'], (array) ($warning['context'] ?? []));
        }

        $result['proposed_payload'] = [
            'title' => $title,
            'sku' => $sku,
            'price' => is_numeric($price) ? (float) $price : $price,
            'currency' => function_exists('get_woocommerce_currency') ? (string) get_woocommerce_currency() : '',
            'stock_status' => $stockStatus,
            'stock_quantity' => $stockQuantity,
            'part_identifier' => $partIdentifier['value'],
            'part_identifier_source' => $partIdentifier['key'],
            'manufacturer_code' => $manufacturerCode,
            'category' => $categoryReadiness,
            'images' => ['urls' => $images['image_urls'], 'upload_policy' => $images['upload_policy']],
            'description' => $this->product_description($product, $productId),
            'regular_price' => is_numeric($regularPrice) ? (float) $regularPrice : $regularPrice,
            'sale_price' => is_numeric($salePrice) ? (float) $salePrice : $salePrice,
        ];

        $this->finalize($result);
        return $result;
    }

    private function base_result(int $productId): array
    {
        return [
            'ok' => true,
            'action_name' => self::ACTION_NAME,
            'mode' => self::MODE,
            'product_id' => $productId,
            'product_status' => '',
            'would_be_eligible' => false,
            'would_send' => false,
            'no_ovoko_write' => true,
            'no_woo_write' => true,
            'proposed_endpoint' => self::PROPOSED_ENDPOINT,
            'endpoint_confirmation_required' => true,
            'payload_format_confirmation_required' => true,
            'duplicate_checks' => [],
            'validations' => [],
            'validation_errors' => [],
            'validation_warnings' => [],
            'source_woo_fields_meta_used' => [],
            'proposed_payload' => [],
            'images' => ['featured_image_id' => 0, 'gallery_image_ids' => [], 'image_urls' => [], 'upload_policy' => 'preview_urls_only_no_upload'],
            'checked_at' => gmdate('c'),
        ];
    }

    private function add_validation(array &$result, string $severity, string $code, string $message, array $context = []): void
    {
        $entry = ['severity' => $severity, 'code' => $code, 'message' => $message];
        if ($context !== []) {
            $entry['context'] = $context;
        }
        $result['validations'][] = $entry;
        if ($severity === 'error') {
            $result['validation_errors'][] = $entry;
        } else {
            $result['validation_warnings'][] = $entry;
        }
    }

    private function finalize(array &$result): void
    {
        $result['would_be_eligible'] = empty($result['validation_errors']);
        $result['would_send'] = false;
        $result['no_ovoko_write'] = true;
        $result['no_woo_write'] = true;
        $result['checked_at'] = gmdate('c');
    }

    private function product_value($product, int $productId, string $name, string $metaKey): string
    {
        $method = 'get_' . $name;
        if (is_object($product) && method_exists($product, $method)) {
            $value = $product->{$method}();
            return trim((string) $value);
        }
        return trim((string) get_post_meta($productId, $metaKey, true));
    }

    private function product_stock_quantity($product, int $productId): ?int
    {
        if (is_object($product) && method_exists($product, 'get_stock_quantity')) {
            $quantity = $product->get_stock_quantity();
            return $quantity === null ? null : (int) $quantity;
        }
        $raw = get_post_meta($productId, '_stock', true);
        return $raw === '' ? null : (int) $raw;
    }

    private function product_description($product, int $productId): string
    {
        if (is_object($product) && method_exists($product, 'get_description')) {
            return (string) $product->get_description();
        }
        $post = get_post($productId);
        return $post ? (string) ($post->post_content ?? '') : '';
    }

    private function first_meta_value(int $productId, array $keys): array
    {
        foreach ($keys as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                return ['key' => $key, 'value' => $value];
            }
        }
        return ['key' => '', 'value' => ''];
    }

    private function category_mapping_readiness(int $productId): array
    {
        $terms = function_exists('wp_get_post_terms') ? wp_get_post_terms($productId, 'product_cat') : [];
        if (!is_array($terms)) {
            return ['known' => false, 'terms' => [], 'mapped_terms' => [], 'unmapped_terms' => [], 'mapping_meta_keys_checked' => ['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id']];
        }
        $mapped = [];
        $unmapped = [];
        $keys = ['_gpswiss_ovoko_category_id', 'gpswiss_ovoko_category_id', '_ovoko_category_id', 'ovoko_category_id'];
        foreach ($terms as $term) {
            $termId = (int) ($term->term_id ?? 0);
            $row = ['term_id' => $termId, 'name' => (string) ($term->name ?? ''), 'slug' => (string) ($term->slug ?? '')];
            $ovokoCategoryId = '';
            foreach ($keys as $key) {
                $value = function_exists('get_term_meta') ? trim((string) get_term_meta($termId, $key, true)) : '';
                if ($value !== '') {
                    $ovokoCategoryId = $value;
                    $row['mapping_meta_key'] = $key;
                    $row['ovoko_category_id'] = $value;
                    break;
                }
            }
            if ($ovokoCategoryId !== '') {
                $mapped[] = $row;
            } else {
                $unmapped[] = $row;
            }
        }
        return ['known' => true, 'terms' => array_merge($mapped, $unmapped), 'mapped_terms' => $mapped, 'unmapped_terms' => $unmapped, 'ready' => $terms !== [] && $unmapped === [], 'mapping_meta_keys_checked' => $keys];
    }

    private function image_preview($product, int $productId): array
    {
        $featuredId = 0;
        if (is_object($product) && method_exists($product, 'get_image_id')) {
            $featuredId = (int) $product->get_image_id();
        }
        if ($featuredId <= 0 && function_exists('get_post_thumbnail_id')) {
            $featuredId = (int) get_post_thumbnail_id($productId);
        }

        $galleryIds = [];
        if (is_object($product) && method_exists($product, 'get_gallery_image_ids')) {
            $galleryIds = array_map('intval', (array) $product->get_gallery_image_ids());
        }
        if ($galleryIds === []) {
            $rawGallery = trim((string) get_post_meta($productId, '_product_image_gallery', true));
            if ($rawGallery !== '') {
                $galleryIds = array_values(array_filter(array_map('intval', array_map('trim', explode(',', $rawGallery)))));
            }
        }

        $ids = array_values(array_unique(array_filter(array_merge([$featuredId], $galleryIds))));
        $urls = [];
        $details = [];
        foreach ($ids as $id) {
            $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($id) : '';
            $accessible = $url !== '' && (!function_exists('wp_http_validate_url') || (bool) wp_http_validate_url($url));
            if ($url !== '') {
                $urls[] = $url;
            }
            $details[] = ['attachment_id' => $id, 'url' => $url, 'accessible' => $accessible, 'binary_sent' => false];
        }

        return [
            'featured_image_id' => $featuredId,
            'gallery_image_ids' => $galleryIds,
            'image_urls' => $urls,
            'image_details' => $details,
            'upload_policy' => 'preview_urls_only_no_upload',
        ];
    }

    private function duplicate_checks(int $productId, string $sku, string $manufacturerCode, string $partNumber): array
    {
        $existingOvokoPartId = trim((string) get_post_meta($productId, '_ovoko_part_id', true));
        $foundMeta = [];
        foreach (self::DUPLICATE_META_KEYS as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                $foundMeta[$key] = $value;
            }
        }

        $warnings = [];
        $blocking = [];
        if ($existingOvokoPartId !== '') {
            $blocking[] = ['code' => 'duplicate_existing_ovoko_part_id', 'message' => '_ovoko_part_id already exists.', 'context' => ['_ovoko_part_id' => $existingOvokoPartId]];
        }
        foreach (['ovoko_part_id', 'part_id', 'source_part_id', 'external_part_id'] as $key) {
            if (!empty($foundMeta[$key])) {
                $blocking[] = ['code' => 'duplicate_existing_part_identity_meta', 'message' => 'Existing part identity meta suggests this product is already linked to an external/Ovoko part.', 'context' => ['meta_key' => $key, 'meta_value' => $foundMeta[$key]]];
            }
        }
        if ($sku !== '' && preg_match('/^GPSW-OVK-\d+$/i', $sku) === 1) {
            $blocking[] = ['code' => 'sku_looks_like_imported_ovoko_sku', 'message' => 'SKU looks like an imported Ovoko SKU.', 'context' => ['sku' => $sku]];
        }

        $existingMatchRules = $this->existing_match_rule_markers($productId);
        if ($existingMatchRules !== []) {
            $warnings[] = ['code' => 'appears_matched_by_existing_ovoko_sync_match_rules', 'message' => 'Product has metadata that existing Ovoko sync matching uses or creates.', 'context' => ['markers' => $existingMatchRules]];
        }

        $sameSkuLinked = $sku === '' ? [] : $this->find_other_products_with_meta_and_ovoko_part($productId, '_sku', $sku);
        $sameManufacturerLinked = $manufacturerCode === '' ? [] : $this->find_other_products_with_meta_and_ovoko_part($productId, '_manufacturer_code', $manufacturerCode);
        if ($sameSkuLinked !== []) {
            $blocking[] = ['code' => 'another_product_same_sku_has_ovoko_part_id', 'message' => 'Another product with the same SKU already has _ovoko_part_id.', 'context' => ['product_ids' => $sameSkuLinked, 'sku' => $sku]];
        }
        if ($sameManufacturerLinked !== []) {
            $warnings[] = ['code' => 'another_product_same_manufacturer_code_has_ovoko_part_id', 'message' => 'Another product with the same manufacturer code already has _ovoko_part_id.', 'context' => ['product_ids' => $sameManufacturerLinked, 'manufacturer_code' => $manufacturerCode]];
        }

        return [
            'has_existing_ovoko_part_id' => $existingOvokoPartId !== '',
            'existing_ovoko_part_id' => $existingOvokoPartId,
            'sku' => $sku,
            'manufacturer_code' => $manufacturerCode,
            'part_number' => $partNumber,
            'identity_meta_found' => $foundMeta,
            'sku_looks_like_imported_ovoko_sku' => $sku !== '' && preg_match('/^GPSW-OVK-\d+$/i', $sku) === 1,
            'appears_matched_by_existing_ovoko_sync_match_rules' => $existingMatchRules !== [],
            'existing_match_rule_markers' => $existingMatchRules,
            'other_products_same_sku_with_ovoko_part_id' => $sameSkuLinked,
            'other_products_same_manufacturer_code_with_ovoko_part_id' => $sameManufacturerLinked,
            'warnings' => $warnings,
            'blocking_warnings' => $blocking,
        ];
    }

    private function existing_match_rule_markers(int $productId): array
    {
        $markers = [];
        foreach (['_allegro_offer_id', 'ovoko_id', '_ovoko_source_id', '_ovoko_raw_payload', '_ovoko_source_url', '_ovoko_manufacturer_code'] as $key) {
            $value = trim((string) get_post_meta($productId, $key, true));
            if ($value !== '') {
                $markers[$key] = $value;
            }
        }
        if ((string) get_post_meta($productId, 'source', true) === 'ovoko_master') {
            $markers['source'] = 'ovoko_master';
        }
        return $markers;
    }

    private function find_other_products_with_meta_and_ovoko_part(int $productId, string $metaKey, string $metaValue): array
    {
        if (!function_exists('get_posts')) {
            return [];
        }
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'fields' => 'ids',
            'posts_per_page' => 10,
            'exclude' => [$productId],
            'meta_query' => [
                'relation' => 'AND',
                ['key' => $metaKey, 'value' => $metaValue, 'compare' => '='],
                ['key' => '_ovoko_part_id', 'compare' => 'EXISTS'],
            ],
        ]);
        return array_values(array_filter(array_map('intval', (array) $ids), static fn(int $id): bool => $id !== $productId));
    }
}
